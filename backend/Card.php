<?php

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

