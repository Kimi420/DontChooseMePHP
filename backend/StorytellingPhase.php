<?php

require_once 'Player.php';
require_once 'Card.php';

/**
 * StorytellingPhase-Klasse für die Dixit-Storytelling-Phase
 */
class StorytellingPhase {
    private Player $storyteller;
    private Card $chosenCard;
    private string $hint;

    public function __construct(Player $storyteller) {
        $this->storyteller = $storyteller;
    }

    public function start(string $hint, Card $card) {
        $this->hint = $hint;
        $this->chosenCard = $card;
        $this->storyteller->isStoryteller = true;
    }

    public function getHint(): string {
        return $this->hint;
    }

    public function getChosenCard(): Card {
        return $this->chosenCard;
    }

    public function getStoryteller(): Player {
        return $this->storyteller;
    }
}

