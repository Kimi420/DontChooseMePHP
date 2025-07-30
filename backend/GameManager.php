<?php
// Error-Ausgaben unterdrücken für sauberes JSON
error_reporting(0);
ini_set('display_errors', 0);

// Output-Buffering starten
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


require_once 'Game.php';

/**
 * GameManager-Klasse zur Verwaltung aller laufenden Spiele
 */
class GameManager {
    /** @var Game[] */
    private array $games = [];
    private string $cardsFile;

    /**
     * Konstruktor für den GameManager
     */
    public function __construct(string $cardsFile = __DIR__ . '/cards.json') {
        $this->cardsFile = $cardsFile;
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

        // Sicherstellen, dass Spieler Karten hat (falls benötigt)
        if (empty($player->cards)) {
            $player->cards = []; // Explizit als leeres Array setzen
        }

        // Spiel in der Liste speichern
        $this->games[$gameId] = $game;

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

        $game->startRound();

        return ['success' => true];
    }

    /**
     * Gibt den aktuellen Spielstatus zurück
     */
    public function getGameState(string $gameId, string $playerName = null): array {
        if (!isset($this->games[$gameId])) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }

        $game = $this->games[$gameId];
        return array_merge(['success' => true], $game->getState($playerName));
    }

    /**
     * Erzähler gibt einen Hinweis
     */
    public function giveHint(string $gameId, string $playerName, string $cardId, string $hint): array {
        if (!isset($this->games[$gameId])) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }

        $game = $this->games[$gameId];
        $result = $game->giveHint($playerName, $cardId, $hint);

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
        $result = $game->chooseCard($playerName, $cardId);

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
        $result = $game->vote($playerName, $cardId);

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
