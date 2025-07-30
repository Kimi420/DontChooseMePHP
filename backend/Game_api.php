<?php
// Error-Ausgaben unterdrücken für sauberes JSON
error_reporting(0);
ini_set('display_errors', 0);

// Output-Buffering starten
ob_start();

header('Content-Type: application/json');

// CORS-Header
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Wenn es eine OPTIONS-Anfrage ist (CORS-Preflight), beenden wir hier
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'GameManager.php';

// GameManager-Instanz erstellen
$gameManager = new GameManager();

// GET-Anfrage für Spielstatus
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Game ID aus der URL-Query holen
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

// POST-Anfrage für Spielaktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // JSON-Daten aus dem Request-Body lesen
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);

    // Fehlende Felder abfangen
    if (!$data || !isset($data['gameId']) || !isset($data['action'])) {
        echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
        exit;
    }

    $gameId = $data['gameId'];
    $action = $data['action'];

    // Aktion ausführen
    switch ($action) {
        case 'start':
            $result = $gameManager->startGame($gameId);
            break;

        case 'giveHint':
            if (!isset($data['playerName']) || !isset($data['cardId']) || !isset($data['hint'])) {
                echo json_encode(['success' => false, 'message' => 'Fehlende Parameter']);
                exit;
            }
            $result = $gameManager->giveHint($gameId, $data['playerName'], $data['cardId'], $data['hint']);
            break;

        case 'chooseCard':
            if (!isset($data['playerName']) || !isset($data['cardId'])) {
                echo json_encode(['success' => false, 'message' => 'Fehlende Parameter']);
                exit;
            }
            $result = $gameManager->chooseCard($gameId, $data['playerName'], $data['cardId']);
            break;

        case 'vote':
            if (!isset($data['playerName']) || !isset($data['cardId'])) {
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

    ob_clean();
    echo json_encode($result);
    exit;
}

// Andere HTTP-Methoden
ob_clean();
echo json_encode(['success' => false, 'message' => 'Methode nicht unterstützt']);
?>
