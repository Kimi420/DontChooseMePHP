<?php
// Error-Ausgaben unterdrücken für sauberes JSON
error_reporting(0);
ini_set('display_errors', 0);

// Output-Buffering starten
ob_start();

// Wenn es eine OPTIONS-Anfrage ist (CORS-Preflight), beenden wir hier
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit(0);
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

require_once 'GameManager.php';

$gameManager = new GameManager();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['gameId'])) {
        echo json_encode(['success' => false, 'message' => 'Game ID fehlt']);
        exit;
    }
    $gameId = $_GET['gameId'];
    $playerName = isset($_GET['playerName']) ? $_GET['playerName'] : null;
    $result = $gameManager->getGameState($gameId, $playerName);
    ob_clean();
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false) {
        echo json_encode(['success' => false, 'message' => 'Request-Body konnte nicht gelesen werden']);
        exit;
    }
    $data = json_decode($rawBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'Ungültiges JSON: ' . json_last_error_msg()]);
        exit;
    }
    if (!$data || !isset($data['gameId']) || !isset($data['action'])) {
        echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
        exit;
    }

    $gameId = $data['gameId'];
    $action = $data['action'];

    switch ($action) {
        case 'start':
            $result = $gameManager->startGame($gameId);
            break;
        case 'giveHint':
            if (!isset($data['playerName'], $data['cardId'], $data['hint'])) {
                echo json_encode(['success' => false, 'message' => 'Fehlende Parameter']);
                exit;
            }
            $result = $gameManager->giveHint($gameId, $data['playerName'], $data['cardId'], $data['hint']);
            break;
        case 'chooseCard':
            if (!isset($data['playerName'], $data['cardId'])) {
                echo json_encode(['success' => false, 'message' => 'Fehlende Parameter']);
                exit;
            }
            $result = $gameManager->chooseCard($gameId, $data['playerName'], $data['cardId']);
            break;
        case 'vote':
            if (!isset($data['playerName'], $data['cardId'])) {
                echo json_encode(['success' => false, 'message' => 'Fehlende Parameter']);
                exit;
            }
            $result = $gameManager->vote($gameId, $data['playerName'], $data['cardId']);
            break;
        case 'nextRound':
            $result = $gameManager->nextRound($gameId);
            break;
        default:
            $result = ['success' => false, 'message' => 'Unbekannte Aktion'];
    }

    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Methode nicht unterstützt']);
