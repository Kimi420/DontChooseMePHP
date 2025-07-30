<?php

require_once 'Player.php';

/**
 * Lobby-Klasse für die Verwaltung der Spiel-Lobby
 */
class Lobby {
    private string $gameId;
    /** @var Player[] */
    private array $players = [];
    private string $state = 'waiting'; // 'waiting' oder 'playing'

    public function __construct(string $gameId) {
        $this->gameId = $gameId;
    }

    /**
     * Spieler tritt der Lobby bei
     */
    public function joinLobby(Player $player): bool {
        foreach ($this->players as $p) {
            if ($p->name === $player->name) {
                return false; // Name schon vergeben
            }
        }
        $this->players[] = $player;
        return true;
    }

    /**
     * Spieler verlässt die Lobby
     */
    public function leaveLobby(string $playerName): void {
        $this->players = array_filter($this->players, fn($p) => $p->name !== $playerName);
    }

    /**
     * Gibt den aktuellen Lobby-Status zurück
     */
    public function getState(): array {
        return [
            'gameId' => $this->gameId,
            'players' => array_map(fn($p) => ['id' => $p->id, 'name' => $p->name], $this->players),
            'state' => $this->state
        ];
    }

    /**
     * Startet das Spiel, wenn genug Spieler vorhanden sind
     */
    public function startGame(): bool {
        if (count($this->players) >= 3) {
            $this->state = 'playing';
            return true;
        }
        return false;
    }
}

