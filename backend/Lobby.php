<?php
// Vollständig auf normalisiertes System umgestellt
// CORS & JSON Setup
error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/NormalizedGameService.php';
$service = new NormalizedGameService();

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
    exit;
}

$data = readJsonBody();
if (!isset($data['playerName']) || trim($data['playerName']) === '') {
    echo json_encode(['success' => false, 'message' => 'Spielername fehlt']);
    exit;
}

$playerName = trim($data['playerName']);

if (isset($data['gameId']) && $data['gameId'] !== '') {
    // Beitreten
    $res = $service->joinGame($data['gameId'], $playerName);
    echo json_encode($res);
    exit;
}

// Neues Spiel erstellen
$res = $service->createGame($playerName);
echo json_encode($res);
