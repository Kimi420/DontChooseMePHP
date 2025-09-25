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
    if (isset($_GET['decks'])) {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT id, name, description FROM g_decks ORDER BY id");
            $decks = $stmt->fetchAll();
            echo json_encode(['success' => true, 'decks' => $decks]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Fehler beim Laden der Decks', 'debug' => $e->getMessage()]);
        }
        exit;
    }
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
    if (!$data || !isset($data['action'])) {
        echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
        exit;
    }
    $action = $data['action'];
    switch ($action) {
        case 'join':
            if (!isset($data['gameId'],$data['playerName'])) { $res=['success'=>false,'message'=>'Parameter fehlen']; break; }
            $res = $service->joinGame($data['gameId'], $data['playerName']);
            break;
        case 'rejoin':
            if (!isset($data['gameId'],$data['playerName'])) { $res=['success'=>false,'message'=>'Parameter fehlen']; break; }
            $res = $service->rejoinGame($data['gameId'], $data['playerName']);
            break;
        case 'start':
            if (!isset($data['gameId'])) { $res=['success'=>false,'message'=>'gameId fehlt']; break; }
            $res = $service->startGame($data['gameId']);
            break;
        case 'setDeck':
            if (!isset($data['gameId'],$data['playerName'],$data['deckId'])) {
                $res = ['success'=>false,'message'=>'Fehlende Parameter'];
                break;
            }
            $res = $service->setDeck($data['gameId'], $data['playerName'], (int)$data['deckId']);
            break;
        case 'giveHint':
            if (!isset($data['gameId'],$data['playerName'],$data['cardId'],$data['hint'])) {
                $res = ['success'=>false,'message'=>'Fehlende Parameter'];
                break;
            }
            $res = $service->giveHint($data['gameId'], $data['playerName'], (int)$data['cardId'], $data['hint']);
            break;
        case 'chooseCard':
            if (!isset($data['gameId'],$data['playerName'],$data['cardId'])) {
                $res = ['success'=>false,'message'=>'Fehlende Parameter'];
                break;
            }
            $res = $service->chooseCard($data['gameId'], $data['playerName'], (int)$data['cardId']);
            break;
        case 'vote':
            if (!isset($data['gameId'],$data['playerName'],$data['cardId'])) {
                $res = ['success'=>false,'message'=>'Fehlende Parameter'];
                break;
            }
            $res = $service->vote($data['gameId'], $data['playerName'], (int)$data['cardId']);
            break;
        case 'nextRound':
            if (!isset($data['gameId'])) { $res=['success'=>false,'message'=>'gameId fehlt']; break; }
            $res = $service->nextRound($data['gameId']);
            break;
        default:
            $res = ['success'=>false,'message'=>'Unbekannte Aktion'];
    }
    echo json_encode($res);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Methode nicht unterstützt']);
