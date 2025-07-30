<?php

/**
 * AudioManager für das Backend - Vereinfachte Version des JavaScript-AudioManagers
 *
 * Hinweis: Da Audio im Backend nicht direkt abgespielt werden kann,
 * dient diese Klasse hauptsächlich dazu, Audiodateien zu verwalten und
 * die entsprechenden Referenzen an das Frontend zurückzugeben
 */
class AudioManager {
    private array $tracks = [];
    private string $audioBasePath;

    /**
     * Konstruktor für den AudioManager
     */
    public function __construct(string $audioBasePath = '../sounds/') {
        $this->audioBasePath = $audioBasePath;

        // Standard-Tracks registrieren
        $this->registerTrack('lobby', 'lobby.mp3');
        $this->registerTrack('game', 'game.mp3');
        $this->registerTrack('victory', 'victory.mp3');
        $this->registerTrack('card_selection', 'card_selection.mp3');
        $this->registerTrack('voting', 'voting.mp3');
        $this->registerTrack('storyteller', 'storyteller.mp3');
        $this->registerTrack('phase_change', 'phase-change.mp3');
    }

    /**
     * Registriert einen Audio-Track
     */
    public function registerTrack(string $name, string $filename): void {
        $this->tracks[$name] = $filename;
    }

    /**
     * Gibt den Pfad zu einem Track zurück
     */
    public function getTrackPath(string $name): ?string {
        if (!isset($this->tracks[$name])) {
            return null;
        }

        return $this->audioBasePath . $this->tracks[$name];
    }

    /**
     * Gibt eine Liste aller verfügbaren Tracks zurück
     */
    public function getAvailableTracks(): array {
        $result = [];
        foreach ($this->tracks as $name => $filename) {
            $result[$name] = $this->audioBasePath . $filename;
        }
        return $result;
    }

    /**
     * Prüft, ob eine Audiodatei existiert
     */
    public function trackExists(string $name): bool {
        if (!isset($this->tracks[$name])) {
            return false;
        }

        $path = $_SERVER['DOCUMENT_ROOT'] . '/' . $this->audioBasePath . $this->tracks[$name];
        return file_exists($path);
    }

    /**
     * API-Endpunkt für verfügbare Audio-Tracks
     */
    public function handleApiRequest(): array {
        return [
            'success' => true,
            'tracks' => $this->getAvailableTracks()
        ];
    }
}

// Wenn diese Datei direkt aufgerufen wird, API-Endpunkt bereitstellen
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    header('Content-Type: application/json');

    // CORS-Header
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    $audioManager = new AudioManager();
    echo json_encode($audioManager->handleApiRequest());
}
?>

