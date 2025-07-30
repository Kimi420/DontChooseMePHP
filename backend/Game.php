<?php

require_once 'Player.php';
require_once 'Card.php';
require_once 'StorytellingPhase.php';
require_once 'CardSelectionPhase.php';
require_once 'VotingPhase.php';
require_once 'ScoringPhase.php';

class Game {
    public string $gameId;
    /** @var Player[] */
    public array $players = [];
    public int $storytellerIndex = 0;
    public string $phase = 'waiting';
    public array $selectedCards = [];
    public array $votes = [];
    public ?string $hint = null;
    public ?int $storytellerCard = null;
    public ?string $winner = null;
    public array $mixedCards = [];
    public string $state = 'waiting';

    public function __construct(string $gameId, array $players) {
        $this->gameId = $gameId;
        $this->players = $players;
    }

    public function getState(): array {
        return [
            'gameId' => $this->gameId,
            'players' => $this->players,
            'storytellerIndex' => $this->storytellerIndex,
            'phase' => $this->phase,
            'selectedCards' => $this->selectedCards,
            'votes' => $this->votes,
            'hint' => $this->hint,
            'storytellerCard' => $this->storytellerCard,
            'winner' => $this->winner,
            'mixedCards' => $this->mixedCards,
            'state' => $this->state
        ];
    }

    public function startGame(): void {
        $this->phase = 'storytelling';
        $this->state = 'playing';
        // Setze alle Spieler auf nicht Storyteller
        foreach ($this->players as $player) {
            $player->isStoryteller = false;
        }
        // Erster Spieler wird Storyteller
        if (count($this->players) > 0) {
            $this->players[0]->isStoryteller = true;
        }
    }

    public function giveHint(string $playerName, int $cardId, string $hint): bool {
        $storyteller = null;
        foreach ($this->players as $player) {
            if ($player->isStoryteller) {
                $storyteller = $player;
                break;
            }
        }
        if ($storyteller && $storyteller->name === $playerName) {
            $this->hint = $hint;
            $this->storytellerCard = $cardId;
            $this->phase = 'selectCards';
            return true;
        }
        return false;
    }

    public function chooseCard(string $playerName, int $cardId): bool {
        foreach ($this->players as $player) {
            if ($player->name === $playerName && !$player->isStoryteller) {
                $this->selectedCards[] = [
                    'playerId' => $player->id,
                    'cardId' => $cardId
                ];
                break;
            }
        }
        // Wenn alle Spieler (außer Erzähler) gewählt haben, nächste Phase
        $nonStorytellerCount = 0;
        foreach ($this->players as $player) {
            if (!$player->isStoryteller) {
                $nonStorytellerCount++;
            }
        }
        if (count($this->selectedCards) >= $nonStorytellerCount) {
            $this->phase = 'voting';
        }
        return true;
    }

    public function vote(string $playerName, int $cardId): bool {
        foreach ($this->players as $player) {
            if ($player->name === $playerName) {
                // Spieler darf nicht für eigene Karte stimmen
                $ownCard = array_filter($this->selectedCards, fn($sc) => $sc['playerId'] === $player->id && $sc['cardId'] === $cardId);
                if ($ownCard) return false;
                $this->votes[] = [
                    'playerId' => $player->id,
                    'cardId' => $cardId
                ];
                break;
            }
        }
        // Wenn alle Stimmen abgegeben, nächste Phase
        if (count($this->votes) >= count($this->players) - 1) {
            $this->phase = 'reveal';
        }
        return true;
    }

    public function nextRound(): void {
        // Punkte berechnen
        $scoring = new ScoringPhase();
        $scoring->calculateScores($this->players, $this->storytellerCard, array_column($this->votes, 'cardId'));
        // Erzähler wechseln
        $this->storytellerIndex = ($this->storytellerIndex + 1) % count($this->players);
        // Reset für neue Runde
        $this->phase = 'storytelling';
        $this->selectedCards = [];
        $this->votes = [];
        $this->hint = null;
        $this->storytellerCard = null;
        // Status der Kartenauswahl für alle Spieler zurücksetzen
        foreach ($this->players as $player) {
            $player->hasSelectedCard = false;
        }
    }

    public function restart(): void {
        foreach ($this->players as $player) {
            $player->score = 0;
        }
        $this->phase = 'waiting';
        $this->state = 'waiting';
        $this->selectedCards = [];
        $this->votes = [];
        $this->hint = null;
        $this->storytellerCard = null;
        $this->winner = null;
        $this->storytellerIndex = 0;
    }
}
