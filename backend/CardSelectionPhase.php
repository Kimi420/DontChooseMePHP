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

/**
 * CardSelectionPhase-Klasse für die Dixit-Kartenauswahl-Phase
 */
class CardSelectionPhase {
    /** @var array<int, Card> */
    private array $selectedCards = [];

    /**
     * Spieler wählt eine Karte aus seiner Hand aus
     */
    public function selectCard(Player $player, Card $card): void {
        $this->selectedCards[$player->id] = $card;
    }

    /**
     * Gibt alle ausgewählten Karten zurück
     */
    public function getSelectedCards(): array {
        return $this->selectedCards;
    }
}

