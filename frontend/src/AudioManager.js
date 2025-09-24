// Einfacher AudioManager für das Abspielen von Musik und Effekten
class AudioManager {
  constructor() {
    this.currentAudio = null;          // Aktueller Musik-Track
    this.volume = 0.3;                 // Basis-Lautstärke (0..1)
    this.isEnabled = true;             // Globaler Mute-Schalter
    this.audioCache = new Map();       // Cache geladener Audio-Elemente
    this.loadingPromises = new Map();  // Verhindert doppeltes Laden
    this.activeEffects = new Set();    // Laufende Effekt-Sounds
  }

  // Setzt globale Lautstärke (wirkt auch auf aktuellen Track)
  setVolume(volume) {
    this.volume = Math.max(0, Math.min(1, volume));
    if (this.currentAudio) {
      this.currentAudio.volume = this.volume;
    }
    // Effekte nicht nachträglich anpassen (absichtlich statisch)
  }

  setEnabled(enabled) {
    this.isEnabled = enabled;
    if (!enabled) {
      this.stopTrack(150);
      // Effekte stoppen
      this.activeEffects.forEach(effect => {
        try { effect.pause(); } catch (_) {}
      });
      this.activeEffects.clear();
    }
  }

  // Lädt (oder holt aus Cache) ein Audio-Element
  async loadAudio(filename) {
    if (this.audioCache.has(filename)) return this.audioCache.get(filename);
    if (this.loadingPromises.has(filename)) return this.loadingPromises.get(filename);

    const loadPromise = new Promise(resolve => {
      const audio = new Audio();
      audio.addEventListener('canplaythrough', () => {
        this.audioCache.set(filename, audio);
        this.loadingPromises.delete(filename);
        resolve(audio);
      }, { once: true });
      audio.addEventListener('error', (e) => {
        console.warn(`Audio-Datei nicht gefunden oder fehlerhaft: ${filename}`, e);
        this.loadingPromises.delete(filename);
        resolve(null);
      }, { once: true });
      try {
        // Einheitliche Pfadlogik: Caller übergibt z.B. 'sounds/lobby.mp3'
        const norm = filename.replace(/\\/g, '/');
        audio.src = norm.startsWith('/') ? norm : '/' + norm;
        audio.preload = 'auto';
        // Browser starten erst Playback bei play()
      } catch (err) {
        console.warn('Fehler beim Setzen der Audio-Quelle:', err);
        resolve(null);
      }
    });

    this.loadingPromises.set(filename, loadPromise);
    return loadPromise;
  }

  // Spielt Hintergrundmusik (ersetzt vorherigen Track)
  async playTrack(filename, loop = true, fadeInMs = 0) {
    if (!this.isEnabled) return;

    try {
      // Alten Track ggf. ausblenden
      if (this.currentAudio) {
        this.stopTrack(200);
      }

      const audio = await this.loadAudio(filename);
      if (!audio) return; // Silent fallback

      this.currentAudio = audio;
      audio.loop = !!loop;
      audio.currentTime = 0;
      audio.volume = fadeInMs > 0 ? 0 : this.volume;

      const playResult = audio.play();
      if (playResult && typeof playResult.catch === 'function') {
        playResult.catch(err => {
          // Autoplay-Block o.Ä. – nicht kritisch
          console.warn('Playback verweigert (Autoplay?):', err);
        });
      }

      if (fadeInMs > 0) {
        this.fadeIn(audio, fadeInMs, this.volume);
      }
    } catch (e) {
      console.warn(`Fehler beim Abspielen von Track ${filename}:`, e);
    }
  }

  // Spielt einen kurzen Effekt (überlappt Musik)
  async playEffect(filename, { volumeMultiplier = 1, fadeInMs = 0, autoCleanup = true } = {}) {
    if (!this.isEnabled) return;
    try {
      const audio = await this.loadAudio(filename);
      if (!audio) return;

      // Klon, damit gleichzeitig mehrfach gleiche Effekte spielbar sind
      const effect = audio.cloneNode(true);
      effect.volume = fadeInMs > 0 ? 0 : Math.min(1, this.volume * volumeMultiplier);
      effect.currentTime = 0;

      this.activeEffects.add(effect);

      const playResult = effect.play();
      if (playResult && typeof playResult.catch === 'function') {
        playResult.catch(err => console.warn('Effekt Playback verweigert:', err));
      }

      if (fadeInMs > 0) {
        this.fadeIn(effect, fadeInMs, Math.min(1, this.volume * volumeMultiplier));
      }

      if (autoCleanup) {
        effect.addEventListener('ended', () => {
          this.activeEffects.delete(effect);
        }, { once: true });
      }
    } catch (e) {
      console.warn(`Fehler beim Effekt ${filename}:`, e);
    }
  }

  // Stoppt aktuellen Track (optional mit Fade-Out)
  stopTrack(fadeOutMs = 0) {
    if (!this.currentAudio) return;
    const audio = this.currentAudio;

    if (fadeOutMs > 0) {
      this.fadeOut(audio, fadeOutMs, () => {
        try { audio.pause(); } catch (_) {}
        if (this.currentAudio === audio) {
          this.currentAudio = null;
        }
      });
    } else {
      try { audio.pause(); } catch (_) {}
      if (this.currentAudio === audio) {
        this.currentAudio = null;
      }
    }
  }

  // Utility: Fade-In
  fadeIn(audio, durationMs, targetVolume) {
    const steps = 20;
    const stepTime = durationMs / steps;
    let current = 0;
    const interval = setInterval(() => {
      current++;
      audio.volume = Math.min(targetVolume, (targetVolume / steps) * current);
      if (current >= steps) {
        clearInterval(interval);
        audio.volume = targetVolume;
      }
    }, stepTime);
  }

  // Utility: Fade-Out
  fadeOut(audio, durationMs, onComplete) {
    const steps = 20;
    const stepTime = durationMs / steps;
    const startVolume = audio.volume;
    let current = 0;
    const interval = setInterval(() => {
      current++;
      const newVolume = Math.max(0, startVolume - (startVolume / steps) * current);
      audio.volume = newVolume;
      if (current >= steps || newVolume <= 0.0001) {
        clearInterval(interval);
        audio.volume = 0;
        if (onComplete) onComplete();
      }
    }, stepTime);
  }
}

// Singleton-Instanz exportieren
const audioManager = new AudioManager();
export default audioManager;
