<?php
header('Content-Type: application/json');

// CORS-Header
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Wenn es eine OPTIONS-Anfrage ist (CORS-Preflight), beenden wir hier
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Einfache Endpunkt-Übersicht
$endpoints = [
    'GET /backend/' => 'API-Übersicht',
    'POST /backend/Lobby.php' => 'Spiel erstellen oder beitreten',
    'GET /backend/Game_api.php?gameId=XYZ' => 'Spielstatus abfragen',
    'POST /backend/Game_api.php' => 'Spielaktionen ausführen'
];

echo json_encode([
    'name' => 'Don\'t Choose Me API',
    'version' => '1.0.0',
    'endpoints' => $endpoints
]);
?>

