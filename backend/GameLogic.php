<?php
/**
 * Utility-Funktionen für die Spiellogik
 */
// CORS-Header für alle Anfragen setzen
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
/**
 * Mischt ein Array zufällig (Fisher-Yates Shuffle)
 * @param array $array - Das zu mischende Array
 * @return array Das gemischte Array
 */
function shuffle_array($array) {
    $shuffled = $array;
    $count = count($shuffled);
    for ($i = $count - 1; $i > 0; $i--) {
        $j = floor(mt_rand() / mt_getrandmax() * ($i + 1));
        // Werte tauschen
        $temp = $shuffled[$i];
        $shuffled[$i] = $shuffled[$j];
        $shuffled[$j] = $temp;
    }
    return $shuffled;
}

/**
 * Berechnet die Punkte am Ende einer Runde
 * @param array $game - Das Spielobjekt
 * @return array Die Punkteverteilung
 */
function calculate_points($game) {
    $storyteller_id = $game['selectedCards'][0]['playerId'];
    $correct_card_id = $game['storytellerCard'];
    $votes = $game['votes'];

    // Zähle korrekte Stimmen
    $correct_votes = 0;
    foreach ($votes as $vote) {
        if ($vote['cardId'] === $correct_card_id) {
            $correct_votes++;
        }
    }

    $all_correct = $correct_votes === count($votes);
    $none_correct = $correct_votes === 0;

    // Punkte für den Erzähler
    foreach ($game['players'] as &$player) {
        if ($player['id'] === $storyteller_id) {
            if (!$none_correct && !$all_correct) {
                $player['points'] += 3;
            }
        }
    }

    // Punkte für richtige Stimmen
    foreach ($votes as $vote) {
        if ($vote['cardId'] === $correct_card_id) {
            foreach ($game['players'] as &$player) {
                if ($player['id'] === $vote['playerId']) {
                    $player['points'] += 3;
                    break;
                }
            }
        }
    }

    // Punkte für andere Spieler, die Stimmen für ihre Karten erhalten haben
    foreach ($game['selectedCards'] as $selected_card) {
        if ($selected_card['playerId'] !== $storyteller_id) {
            // Zähle Stimmen für diese Karte
            $votes_for_card = 0;
            foreach ($votes as $vote) {
                if ($vote['cardId'] === $selected_card['cardId']) {
                    $votes_for_card++;
                }
            }

            // Füge Punkte hinzu
            foreach ($game['players'] as &$player) {
                if ($player['id'] === $selected_card['playerId']) {
                    $player['points'] += $votes_for_card;
                    break;
                }
            }
        }
    }

    // Erstelle Punkteverteilung
    $points = [];
    foreach ($game['players'] as $player) {
        $points[] = [
            'id' => $player['name'],
            'points' => $player['points']
        ];
    }

    return ['points' => $points];
}

/**
 * Validiert, ob ein Spielzustand gültig ist
 * @param array $game - Das zu validierende Spielobjekt
 * @return bool True wenn gültig, false sonst
 */
function validate_game_state($game) {
    if (empty($game) || empty($game['players']) || count($game['players']) < 2) {
        return false;
    }

    if ($game['storytellerIndex'] < 0 || $game['storytellerIndex'] >= count($game['players'])) {
        return false;
    }

    return true;
}
?>

