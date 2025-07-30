<?php
// GameController.php: API-Endpunkt für das Spiel
require_once 'Game.php';
require_once 'Player.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Dummy: Spiel-Instanz aus Session oder Datei laden (hier als Beispiel)
// In Produktion: Spielzustand persistent speichern/laden!
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['game'])) {
    $_SESSION['game'] = new Game(uniqid('game_'), []);
}
$game = $_SESSION['game'];

// Request-Daten lesen
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? null;
$playerName = $input['playerName'] ?? null;
$cardId = $input['cardId'] ?? null;
$hint = $input['hint'] ?? null;

// Spielerobjekt finden
$player = null;
if ($playerName) {
    foreach ($game->players as $p) {
        if ($p->name === $playerName) {
            $player = $p;
            break;
        }
    }
}

try {
    switch ($action) {
        case 'getState':
            echo json_encode($game->getState());
            break;
        case 'giveHint':
            $ok = $game->giveHint($playerName, $cardId, $hint);
            echo json_encode(['success' => $ok]);
            break;
        case 'chooseCard':
            $ok = $game->chooseCard($playerName, $cardId);
            echo json_encode(['success' => $ok]);
            break;
        case 'vote':
            $ok = $game->vote($playerName, $cardId);
            echo json_encode(['success' => $ok]);
            break;
        case 'nextRound':
            $game->nextRound();
            echo json_encode(['success' => true]);
            break;
        default:
            echo json_encode(['error' => 'Unbekannte Aktion']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

