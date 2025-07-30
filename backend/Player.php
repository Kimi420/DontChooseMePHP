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
    /** @var Card[] */
    public array $hand;
    public bool $isActive;
    public bool $isStoryteller;
    public bool $hasSelectedCard;
    public array $cards = []; // Fehlende Property hinzugefügt

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
        $this->cards = []; // Initialisierung der cards-Array
    }

    public function addCard($card): void {
        $this->cards[] = $card;
    }

    public function removeCard($cardId): bool {
        foreach ($this->cards as $key => $card) {
            if ($card->id === $cardId || $card['id'] === $cardId) {
                unset($this->cards[$key]);
                $this->cards = array_values($this->cards); // Array-Indizes neu ordnen
                return true;
            }
        }
        return false;
    }

    public function getCards(): array {
        return $this->cards;
    }
}
