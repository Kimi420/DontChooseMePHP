<?php
// Error-Ausgaben komplett unterdrücken für sauberes JSON
error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'Game.php';
require_once 'Database.php';

/**
 * GameManager-Klasse zur Verwaltung aller laufenden Spiele
 */
class GameManager {
    /** @var Game[] */
    private array $games = [];
    private string $cardsFile;
    private PDO $db;

    /**
     * Konstruktor für den GameManager
     */
    public function __construct(string $cardsFile = __DIR__ . '/cards.json') {
        $this->cardsFile = $cardsFile;
        $this->db = Database::getConnection();
        $this->loadGamesFromDatabase();
    }

    /**
     * Lädt alle aktiven Spiele aus der Datenbank
     */
    private function loadGamesFromDatabase(): void {
        try {
            $stmt = $this->db->prepare("SELECT id, state FROM games WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->execute();

            while ($row = $stmt->fetch()) {
                $gameData = json_decode($row['state'], true);
                if ($gameData) {
                    $game = $this->createGameFromData($row['id'], $gameData);
                    $this->games[$row['id']] = $game;
                }
            }
        } catch (Exception $e) {
            error_log('Fehler beim Laden der Spiele: ' . $e->getMessage());
        }
    }

    /**
     * Erstellt ein Game-Objekt aus Datenbankdaten
     */
    private function createGameFromData(string $gameId, array $gameData): Game {
        $cardData = $this->loadCardData();
        $game = new Game($gameId, $cardData);

        // Spielzustand wiederherstellen
        $game->storytellerIndex = $gameData['storytellerIndex'] ?? 0;
        $game->phase = $gameData['phase'] ?? 'waiting';
        $game->selectedCards = $gameData['selectedCards'] ?? [];
        $game->votes = $gameData['votes'] ?? [];
        $game->hint = $gameData['hint'] ?? null;
        $game->storytellerCard = $gameData['storytellerCard'] ?? null;
        $game->winner = $gameData['winner'] ?? null;
        $game->mixedCards = $gameData['mixedCards'] ?? [];
        $game->state = $gameData['state'] ?? 'waiting';

        // Spieler wiederherstellen
        if (isset($gameData['players'])) {
            foreach ($gameData['players'] as $playerData) {
                $player = new Player($playerData['id'], $playerData['name']);
                $player->score = $playerData['score'] ?? 0;
                $player->isStoryteller = $playerData['isStoryteller'] ?? false;
                $player->hasSelectedCard = $playerData['hasSelectedCard'] ?? false;
                $player->cards = $playerData['cards'] ?? [];
                $game->players[] = $player;
            }
        }

        return $game;
    }

    /**
     * Speichert ein Spiel in der Datenbank
     */
    private function saveGameToDatabase(Game $game): void {
        try {
            $gameState = $game->getState();
            $stateJson = json_encode($gameState);

            $stmt = $this->db->prepare("
                INSERT INTO games (id, state) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE state = ?, updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$game->gameId, $stateJson, $stateJson]);
        } catch (Exception $e) {
            error_log('Fehler beim Speichern des Spiels: ' . $e->getMessage());
        }
    }

    /**
     * Erstellt ein neues Spiel
     */
    public function createGame(string $playerName): array {
        // Zufällige Spiel-ID generieren
        $gameId = $this->generateGameId();

        // Kartendaten laden
        $cardData = $this->loadCardData();

        // Neues Spiel erstellen
        $game = new Game($gameId, $cardData);

        // Spieler hinzufügen
        $player = $game->addPlayer($playerName);

        // Sicherstellen, dass Spieler Karten hat
        if (!isset($player->cards) || !is_array($player->cards)) {
            $player->cards = [];
        }

        // Spiel in der Liste und Datenbank speichern
        $this->games[$gameId] = $game;
        $this->saveGameToDatabase($game);

        return [
            'success' => true,
            'gameId' => $gameId,
            'player' => [
                'id' => $player->id,
                'name' => $player->name
            ]
        ];
    }

    /**
     * Lässt einen Spieler einem Spiel beitreten
     */
    public function joinGame(string $gameId, string $playerName): array {
        if (!isset($this->games[$gameId])) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }

        $game = $this->games[$gameId];

        // Prüfen, ob der Name bereits verwendet wird
        foreach ($game->players as $existingPlayer) {
            if ($existingPlayer->name === $playerName) {
                return ['success' => false, 'message' => 'Name bereits vergeben'];
            }
        }

        // Spieler hinzufügen
        $player = $game->addPlayer($playerName);

        // Spiel in Datenbank aktualisieren
        $this->saveGameToDatabase($game);

        return [
            'success' => true,
            'gameId' => $gameId,
            'player' => [
                'id' => $player->id,
                'name' => $player->name
            ]
        ];
    }

    /**
     * Startet ein Spiel
     */
    public function startGame(string $gameId): array {
        if (!isset($this->games[$gameId])) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }

        $game = $this->games[$gameId];

        if (count($game->players) < 3) {
            return ['success' => false, 'message' => 'Mindestens 3 Spieler benötigt'];
        }

        $game->startGame();
        $this->saveGameToDatabase($game);

        return ['success' => true];
    }

    /**
     * Gibt den aktuellen Spielstatus zurück
     */
    public function getGameState(string $gameId, string $playerName = null): array {
        if (!isset($this->games[$gameId])) {
            return [
                'success' => false,
                'message' => 'Spiel nicht gefunden',
                'players' => [],
                'gameId' => $gameId,
                'phase' => 'waiting',
                'state' => 'waiting'
            ];
        }

        $game = $this->games[$gameId];
        $gameState = $game->getState($playerName);

        // Sicherstellen, dass players immer ein Array ist
        if (!isset($gameState['players']) || !is_array($gameState['players'])) {
            $gameState['players'] = [];
        }

        return array_merge(['success' => true], $gameState);
    }

    /**
     * Erzähler gibt einen Hinweis
     */
    public function giveHint(string $gameId, string $playerName, string $cardId, string $hint): array {
        if (!isset($this->games[$gameId])) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }

        $game = $this->games[$gameId];
        $result = $game->giveHint($playerName, intval($cardId), $hint);

        if ($result) {
            $this->saveGameToDatabase($game);
        }

        return ['success' => $result];
    }

    /**
     * Spieler wählt eine Karte aus
     */
    public function chooseCard(string $gameId, string $playerName, string $cardId): array {
        if (!isset($this->games[$gameId])) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }

        $game = $this->games[$gameId];
        $result = $game->chooseCard($playerName, intval($cardId));

        if ($result) {
            $this->saveGameToDatabase($game);
        }

        return ['success' => $result];
    }

    /**
     * Spieler stimmt für eine Karte ab
     */
    public function vote(string $gameId, string $playerName, string $cardId): array {
        if (!isset($this->games[$gameId])) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }

        $game = $this->games[$gameId];
        $result = $game->vote($playerName, intval($cardId));

        if ($result) {
            $this->saveGameToDatabase($game);
        }

        return ['success' => $result];
    }

    /**
     * Wechselt zur nächsten Runde
     */
    public function nextRound(string $gameId): array {
        if (!isset($this->games[$gameId])) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }

        $game = $this->games[$gameId];
        $game->nextRound();
        $this->saveGameToDatabase($game);

        return ['success' => true];
    }

    /**
     * Generiert eine zufällige Spiel-ID
     */
    private function generateGameId(int $length = 6): string {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $id = '';

        do {
            $id = '';
            for ($i = 0; $i < $length; $i++) {
                $id .= $characters[rand(0, strlen($characters) - 1)];
            }
        } while (isset($this->games[$id]));

        return $id;
    }

    /**
     * Lädt die Kartendaten aus der JSON-Datei
     */
    private function loadCardData(): array {
        if (!file_exists($this->cardsFile)) {
            // Fallback: Leere Liste zurückgeben oder Beispielkarten
            return [
                ['id' => '1', 'title' => 'Karte 1', 'image' => 'card1.jpg'],
                ['id' => '2', 'title' => 'Karte 2', 'image' => 'card2.jpg'],
                ['id' => '3', 'title' => 'Karte 3', 'image' => 'card3.jpg']
            ];
        }

        $jsonData = file_get_contents($this->cardsFile);
        return json_decode($jsonData, true) ?: [];
    }
}
?>
