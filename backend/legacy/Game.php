<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'Player.php';
require_once 'Card.php';
require_once 'StorytellingPhase.php';
require_once 'CardSelectionPhase.php';
require_once 'VotingPhase.php';
require_once 'ScoringPhase.php';

class Game {
    public string $gameId;
    /** @var Player[] */
    public array $players = [];
    public int $storytellerIndex = 0;
    public string $phase = 'waiting';
    public array $selectedCards = [];
    public array $votes = [];
    public ?string $hint = null;
    public ?int $storytellerCard = null;
    public ?string $winner = null;
    public array $mixedCards = [];
    public string $state = 'waiting';
    public array $cardData = [];
    /**
     * Restlicher Kartenstapel (enthält noch nicht verteilte Karten-IDs)
     * @var int[]
     */
    public array $deck = [];
    private int $HAND_SIZE = 6;

    public function __construct(string $gameId, array $cardData = []) {
        $this->gameId = $gameId;
        $this->players = [];
        $this->cardData = $cardData;
        // Deck initialisieren mit allen Karten-IDs aus cardData
        $this->deck = array_map(fn($c) => (int)($c['id'] ?? 0), $cardData);
        $this->deck = array_values(array_filter($this->deck, fn($id) => $id > 0));
    }

    /**
     * Fügt einen neuen Spieler zum Spiel hinzu
     */
    public function addPlayer(string $playerName): Player {
        $playerId = count($this->players) + 1;
        $player = new Player($playerId, $playerName);
        $this->players[] = $player;

        // Protokolliere die Aktion in der Datenbank
        if (class_exists('Database')) {
            Database::logGameAction($this->gameId, 'player_joined', $playerName, ['playerId' => $playerId]);
        }

        return $player;
    }

    public function getState(?string $playerName = null): array {
        // Spieler-Array für JSON-Serialisierung vorbereiten
        $playersArray = [];
        foreach ($this->players as $player) {
            $playersArray[] = [
                'id' => $player->id,
                'name' => $player->name,
                'score' => $player->score ?? 0,
                'isStoryteller' => $player->isStoryteller ?? false,
                'hasSelectedCard' => $player->hasSelectedCard ?? false,
                'cards' => isset($player->cards) && is_array($player->cards) ? $player->cards : []
            ];
        }

        return [
            'gameId' => $this->gameId,
            'players' => $playersArray,
            'storytellerIndex' => $this->storytellerIndex ?? 0,
            'phase' => $this->phase ?? 'waiting',
            'selectedCards' => $this->selectedCards ?? [],
            'votes' => $this->votes ?? [],
            'hint' => $this->hint,
            'storytellerCard' => $this->storytellerCard,
            'winner' => $this->winner,
            'mixedCards' => $this->mixedCards ?? [],
            'state' => $this->state ?? 'waiting',
            'deck' => $this->deck,
            'cardData' => $this->cardData
        ];
    }

    public function startGame(): void {
        $this->phase = 'storytelling';
        $this->state = 'playing';

        // Setze alle Spieler auf nicht Storyteller
        foreach ($this->players as $player) {
            $player->isStoryteller = false;
        }

        // Erster Spieler wird Storyteller
        if (count($this->players) > 0) {
            $this->players[0]->isStoryteller = true;
        }

        // Karten austeilen, falls Spieler noch keine Karten besitzen
        $this->dealHandsIfNeeded();

        // Protokolliere die Aktion
        if (class_exists('Database')) {
            Database::logGameAction($this->gameId, 'game_started', null, ['playerCount' => count($this->players)]);
        }
    }

    private function dealHandsIfNeeded(): void {
        // Prüfen ob bereits Karten vorhanden (wir nehmen ersten Spieler als Referenz)
        $alreadyDealt = false;
        foreach ($this->players as $p) {
            if (!empty($p->cards)) { $alreadyDealt = true; break; }
        }
        if ($alreadyDealt) return;

        // Deck durchmischen
        shuffle($this->deck);

        foreach ($this->players as $player) {
            $player->cards = [];
            for ($i = 0; $i < $this->HAND_SIZE; $i++) {
                if (empty($this->deck)) break; // Keine Karten mehr
                $cardId = array_shift($this->deck);
                $player->cards[] = $cardId; // Speichern nur die ID
            }
        }
    }

    private function replenishHands(): void {
        // Spieler nachziehen bis HAND_SIZE oder Deck leer
        foreach ($this->players as $player) {
            while (count($player->cards) < $this->HAND_SIZE && !empty($this->deck)) {
                $cardId = array_shift($this->deck);
                $player->cards[] = $cardId;
            }
        }
    }

    public function giveHint(string $playerName, int $cardId, string $hint): bool {
        $storyteller = null;
        foreach ($this->players as $player) {
            if ($player->isStoryteller) {
                $storyteller = $player;
                break;
            }
        }

        if ($storyteller && $storyteller->name === $playerName) {
            // Validierung: Hinweis darf nicht leer sein
            if (trim($hint) === '') {
                return false;
            }
            // Validierung: Karte muss in seiner Hand sein
            if (!in_array($cardId, $storyteller->cards, true)) {
                return false; // Ungültige Karte
            }
            $this->hint = $hint;
            $this->storytellerCard = $cardId;
            $this->phase = 'selectCards';

            if (class_exists('Database')) {
                Database::logGameAction($this->gameId, 'hint_given', $playerName, ['cardId' => $cardId, 'hint' => $hint]);
            }
            return true;
        }
        return false;
    }

    public function chooseCard(string $playerName, int $cardId): bool {
        foreach ($this->players as $player) {
            if ($player->name === $playerName && !$player->isStoryteller) {
                // Karte muss in seiner Hand sein
                if (!in_array($cardId, $player->cards, true)) {
                    return false;
                }
                // Verhindere Doppel-Wahl desselben Spielers
                foreach ($this->selectedCards as $sc) {
                    if ($sc['playerId'] === $player->id) {
                        return false; // Bereits gewählt
                    }
                }
                $this->selectedCards[] = [
                    'playerId' => $player->id,
                    'cardId' => $cardId
                ];
                $player->hasSelectedCard = true;

                if (class_exists('Database')) {
                    Database::logGameAction($this->gameId, 'card_selected', $playerName, ['cardId' => $cardId]);
                }
                break;
            }
        }

        // Wenn alle Spieler (außer Erzähler) gewählt haben, nächste Phase
        $nonStorytellerCount = 0;
        foreach ($this->players as $player) {
            if (!$player->isStoryteller) { $nonStorytellerCount++; }
        }
        if (count($this->selectedCards) >= $nonStorytellerCount) {
            $this->phase = 'voting';
            // Mixed Cards erzeugen (Storyteller + alle ausgewählten)
            $allCardIds = array_map(fn($sc) => $sc['cardId'], $this->selectedCards);
            if ($this->storytellerCard !== null) {
                $allCardIds[] = $this->storytellerCard;
            }
            $allCardIds = array_values(array_unique($allCardIds));
            shuffle($allCardIds);
            $this->mixedCards = $allCardIds;

            // Gespielte Karten aus Händen entfernen
            foreach ($this->players as $p) {
                $used = [];
                if ($p->isStoryteller && $this->storytellerCard !== null) {
                    $used[] = $this->storytellerCard;
                } else {
                    foreach ($this->selectedCards as $sc) {
                        if ($sc['playerId'] === $p->id) { $used[] = $sc['cardId']; break; }
                    }
                }
                if ($used) {
                    $p->cards = array_values(array_filter($p->cards, fn($cId) => !in_array($cId, $used, true)));
                }
            }
        }
        return true;
    }

    public function vote(string $playerName, int $cardId): bool {
        foreach ($this->players as $player) {
            if ($player->name === $playerName) {
                if ($player->isStoryteller) { return false; } // Erzähler stimmt nicht ab

                $this->votes[] = [
                    'playerId' => $player->id,
                    'cardId' => $cardId
                ];

                // Protokolliere die Aktion
                if (class_exists('Database')) {
                    Database::logGameAction($this->gameId, 'vote_cast', $playerName, ['cardId' => $cardId]);
                }

                break;
            }
        }

        // Wenn alle Stimmen abgegeben, nächste Phase
        if (count($this->votes) >= count($this->players) - 1) {
            $this->phase = 'reveal';
        }

        return true;
    }

    public function nextRound(): void {
        // Punkte berechnen
        if (class_exists('ScoringPhase')) {
            $scoring = new ScoringPhase();
            $scoring->calculateScores($this->players, $this->storytellerCard, array_column($this->votes, 'cardId'));
        }

        // Erzähler wechseln
        $this->storytellerIndex = ($this->storytellerIndex + 1) % count($this->players);

        // Alle Spieler zurücksetzen
        foreach ($this->players as $player) {
            $player->isStoryteller = false;
            $player->hasSelectedCard = false;
        }

        // Neuen Erzähler setzen
        if (isset($this->players[$this->storytellerIndex])) {
            $this->players[$this->storytellerIndex]->isStoryteller = true;
        }

        // Reset für neue Runde
        $this->phase = 'storytelling';
        $this->selectedCards = [];
        $this->votes = [];
        $this->hint = null;
        $this->storytellerCard = null;

        // Nachziehen für neue Runde
        $this->replenishHands();

        // Protokolliere die Aktion
        if (class_exists('Database')) {
            Database::logGameAction($this->gameId, 'next_round', null, ['newStoryteller' => $this->storytellerIndex]);
        }
    }

    public function restart(): void {
        foreach ($this->players as $player) {
            $player->score = 0;
        }
        $this->phase = 'waiting';
        $this->state = 'waiting';
        $this->selectedCards = [];
        $this->votes = [];
        $this->hint = null;
        $this->storytellerCard = null;
        $this->winner = null;
        $this->storytellerIndex = 0;
    }
}
?>
