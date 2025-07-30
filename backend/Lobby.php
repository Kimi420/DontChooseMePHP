<?php
// CORS-Header für alle Anfragen setzen
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Sicherstellen, dass keine weiteren CORS-Header gesetzt werden
if (function_exists('apache_response_headers')) {
    $headers = apache_response_headers();
    if (isset($headers['Access-Control-Allow-Origin']) && strpos($headers['Access-Control-Allow-Origin'], ',') !== false) {
        header_remove('Access-Control-Allow-Origin');
        header('Access-Control-Allow-Origin: *');
    }
}

// OPTIONS-Preflight-Anfragen behandeln
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error Reporting für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Prüfen ob Dateien existieren
    if (!file_exists('Player.php')) {
        throw new Exception('Player.php nicht gefunden');
    }
    if (!file_exists('GameManager.php')) {
        throw new Exception('GameManager.php nicht gefunden');
    }

    require_once 'Player.php';
    require_once 'GameManager.php';

    // Prüfen ob Klasse existiert
    if (!class_exists('GameManager')) {
        throw new Exception('GameManager-Klasse nicht gefunden');
    }

    $gameManager = new GameManager();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $jsonData = file_get_contents('php://input');

        if ($jsonData === false) {
            throw new Exception('Konnte Request-Body nicht lesen');
        }

        $data = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON-Parsing-Fehler: ' . json_last_error_msg());
        }

        if (!$data) {
            throw new Exception('Keine JSON-Daten empfangen');
        }

        if (!isset($data['playerName'])) {
            throw new Exception('Spielername fehlt in den Daten');
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

} catch (Error $e) {
    error_log('PHP Error in Lobby.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'PHP-Fehler: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('Exception in Lobby.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server-Fehler: ' . $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Throwable in Lobby.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unerwarteter Fehler: ' . $e->getMessage()]);
}
?>
