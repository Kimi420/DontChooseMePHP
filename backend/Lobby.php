<?php
// CORS-Header für alle Anfragen setzen
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// OPTIONS-Preflight-Anfragen behandeln
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error Reporting für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once 'Player.php';
    require_once 'GameManager.php';

    $gameManager = new GameManager();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $jsonData = file_get_contents('php://input');
        $data = json_decode($jsonData, true);

        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Ungültige JSON-Daten']);
            exit;
        }

        if (!isset($data['playerName'])) {
            echo json_encode(['success' => false, 'message' => 'Spielername fehlt']);
            exit;
        }

        $playerName = $data['playerName'];

        if (isset($data['gameId'])) {
            // Spiel beitreten
            $gameId = $data['gameId'];
            $result = $gameManager->joinGame($gameId, $playerName);
        } else {
            // Neues Spiel erstellen
            $result = $gameManager->createGame($playerName);
        }

        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen unterstützt']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server-Fehler: ' . $e->getMessage()]);
}
?>
