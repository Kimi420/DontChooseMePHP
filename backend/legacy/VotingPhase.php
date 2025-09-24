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
 * VotingPhase-Klasse für die Dixit-Abstimmungsphase
 */
class VotingPhase {
    /** @var array<int, int> */
    private array $votes = [];

    /**
     * Spieler stimmt für eine Karte ab
     */
    public function vote(Player $player, int $cardId): void {
        $this->votes[$player->id] = $cardId;
    }

    /**
     * Gibt alle Stimmen zurück
     */
    public function getVotes(): array {
        return $this->votes;
    }
}

