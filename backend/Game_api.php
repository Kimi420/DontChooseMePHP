<?php
// Error-Ausgaben unterdrücken für sauberes JSON
error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Wenn es eine OPTIONS-Anfrage ist (CORS-Preflight), beenden wir hier
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/NormalizedGameService.php';
$service = new NormalizedGameService();

function readJson(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['gameId'])) {
        echo json_encode(['success' => false, 'message' => 'Game ID fehlt']);
        exit;
    }
    $gameId = $_GET['gameId'];
    $playerName = isset($_GET['playerName']) ? $_GET['playerName'] : null;
    $res = $service->getGameState($gameId, $playerName);
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = readJson();
    // Erweiterung: Lobby-Erstellung mit Deck-Auswahl
    if (isset($data['action']) && $data['action'] === 'createGame') {
        $playerName = $data['playerName'] ?? null;
        $deckId = isset($data['deckId']) ? (int)$data['deckId'] : null;
        if (!$playerName) {
            echo json_encode(['success' => false, 'message' => 'Spielername fehlt']);
            exit;
        }
        $res = $service->createGame($playerName, $deckId);
        echo json_encode($res);
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
            $res = $service->startGame($gameId);
            break;
        case 'giveHint':
            if (!isset($data['playerName'],$data['cardId'],$data['hint'])) {
                $res = ['success'=>false,'message'=>'Fehlende Parameter'];
                break;
            }
            $res = $service->giveHint($gameId, $data['playerName'], (int)$data['cardId'], $data['hint']);
            break;
        case 'chooseCard':
            if (!isset($data['playerName'],$data['cardId'])) {
                $res = ['success'=>false,'message'=>'Fehlende Parameter'];
                break;
            }
            $res = $service->chooseCard($gameId, $data['playerName'], (int)$data['cardId']);
            break;
        case 'vote':
            if (!isset($data['playerName'],$data['cardId'])) {
                $res = ['success'=>false,'message'=>'Fehlende Parameter'];
                break;
            }
            $res = $service->vote($gameId, $data['playerName'], (int)$data['cardId']);
            break;
        case 'nextRound':
            $res = $service->nextRound($gameId);
            break;
        default:
            $res = ['success'=>false,'message'=>'Unbekannte Aktion'];
    }
    echo json_encode($res);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Methode nicht unterstützt']);
