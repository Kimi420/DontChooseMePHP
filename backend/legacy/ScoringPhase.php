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
 * ScoringPhase-Klasse für die Dixit-Auswertungsphase
 */
class ScoringPhase {
    /**
     * Berechnet und vergibt Punkte an die Spieler
     * @param Player[] $players
     * @param int $storytellerCardId
     * @param array<int, int> $votes
     */
    public function calculateScores(array $players, int $storytellerCardId, array $votes): void {
        // Beispielhafte Punktevergabe nach Dixit-Regeln
        $storytellerFound = false;
        $voteCounts = array_count_values($votes);

        foreach ($players as $player) {
            if (isset($votes[$player->id]) && $votes[$player->id] === $storytellerCardId) {
                $player->score += 3;
                $storytellerFound = true;
            }
        }

        // Storyteller bekommt Punkte, wenn nicht alle oder keiner richtig gewählt hat
        if ($storytellerFound && count($voteCounts) > 1) {
            foreach ($players as $player) {
                if (isset($votes[$player->id]) && $votes[$player->id] !== $storytellerCardId) {
                    // Bonuspunkte für Spieler, deren Karte gewählt wurde
                    $player->score += $voteCounts[$votes[$player->id]] ?? 0;
                }
            }
        }
    }
}

