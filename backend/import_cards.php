<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Importiert Karten aus cards.json als neues Deck in die Datenbank
require_once __DIR__ . '/Database.php';

try {
    $pdo = Database::getConnection();
} catch (Exception $e) {
    die("Fehler bei der Datenbankverbindung: " . $e->getMessage());
}
$cardsFile = __DIR__ . '/cards.json';
if (!file_exists($cardsFile)) {
    die("cards.json nicht gefunden\n");
}
$cards = json_decode(file_get_contents($cardsFile), true);
if (!is_array($cards) || count($cards) === 0) {
    die("cards.json ist leer oder ungültig\n");
}

$deckName = 'Standarddeck';
$deckDesc = 'Importiert aus cards.json am ' . date('Y-m-d H:i:s');

// Prüfen ob Deck schon existiert
$stmt = $pdo->prepare("SELECT id FROM g_decks WHERE name = ?");
$stmt->execute([$deckName]);
$deck = $stmt->fetch();
if ($deck) {
    $deckId = $deck['id'];
    echo "Deck '$deckName' existiert bereits mit ID $deckId. Karten werden hinzugefügt.\n";
} else {
    $pdo->prepare("INSERT INTO g_decks (name, description) VALUES (?, ?)")->execute([$deckName, $deckDesc]);
    $deckId = $pdo->lastInsertId();
    echo "Neues Deck '$deckName' mit ID $deckId angelegt.\n";
}

$inserted = 0;
foreach ($cards as $card) {
    if (!isset($card['title'], $card['image'])) continue;
    // Prüfen ob Karte schon existiert (nach Titel und Bild im Deck)
    $stmt = $pdo->prepare("SELECT id FROM g_cards WHERE deck_id = ? AND title = ? AND image = ?");
    $stmt->execute([$deckId, $card['title'], $card['image']]);
    if ($stmt->fetch()) continue;
    $pdo->prepare("INSERT INTO g_cards (deck_id, title, image) VALUES (?, ?, ?)")
        ->execute([$deckId, $card['title'], $card['image']]);
    $inserted++;
}
echo "$inserted Karten importiert.\n";

