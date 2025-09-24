<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'Card.php';

/**
 * Player-Klasse für einen Dixit-Spieler
 */
class Player {
    public int $id;
    public string $name;
    public int $score;
    /** @var Card[]|array */
    public array $hand; // (wird aktuell nicht genutzt – cards wird verwendet)
    public bool $isActive;
    public bool $isStoryteller;
    public bool $hasSelectedCard;
    /**
     * Karten, die der Spieler aktuell hat (wird vom Spiel genutzt)
     * @var array<int, mixed>
     */
    public array $cards = [];

    public function __construct(
        int $id,
        string $name,
        int $score = 0,
        array $hand = [],
        bool $isActive = false,
        bool $isStoryteller = false,
        bool $hasSelectedCard = false
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->score = $score;
        $this->hand = $hand;
        $this->isActive = $isActive;
        $this->isStoryteller = $isStoryteller;
        $this->hasSelectedCard = $hasSelectedCard;
        // $this->cards bereits über Property-Default initialisiert
    }

    /**
     * Fügt dem Spieler eine Karte hinzu
     * @param Card|array $card
     */
    public function addCard($card): void {
        $this->cards[] = $card;
    }

    /**
     * Entfernt eine Karte anhand ihrer ID
     */
    public function removeCard($cardId): bool {
        foreach ($this->cards as $key => $card) {
            // Unterstützt sowohl Objekt- als auch Array-Repräsentation
            $currentId = is_object($card) ? ($card->id ?? null) : ($card['id'] ?? null);
            if ($currentId === $cardId) {
                unset($this->cards[$key]);
                $this->cards = array_values($this->cards); // Neu indexieren
                return true;
            }
        }
        return false;
    }

    /**
     * Gibt alle Karten des Spielers zurück
     */
    public function getCards(): array {
        return $this->cards;
    }
}
