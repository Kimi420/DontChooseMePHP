<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


/**
 * Card-Klasse für eine Dixit-Karte
 */
class Card {
    public int $id;
    public string $title;
    public string $image;

    public function __construct(int $id, string $title, string $image) {
        $this->id = $id;
        $this->title = $title;
        $this->image = $image;
    }
}

