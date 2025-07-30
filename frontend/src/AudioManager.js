// Einfacher AudioManager für das Abspielen von Sounds
class AudioManager {
  constructor() {
    this.currentAudio = null;
    this.volume = 0.3;
    this.isEnabled = true;
    this.audioCache = new Map();
    this.loadingPromises = new Map();
  }

  setVolume(volume) {
    this.volume = Math.max(0, Math.min(1, volume));
    if (this.currentAudio) {
      this.currentAudio.volume = this.volume;
    }
  }

  async loadAudio(filename) {
    // Prüfen ob bereits geladen oder gerade geladen wird
    if (this.audioCache.has(filename)) {
      return this.audioCache.get(filename);
    }
    if (this.loadingPromises.has(filename)) {
      return this.loadingPromises.get(filename);
    }

    // Audio laden
    const loadPromise = new Promise((resolve, reject) => {
      const audio = new Audio();

      audio.addEventListener('canplaythrough', () => {
        this.audioCache.set(filename, audio);
        this.loadingPromises.delete(filename);
        resolve(audio);
      });

      audio.addEventListener('error', (e) => {
        console.warn(`Audio-Datei nicht gefunden: ${filename}`, e);
        this.loadingPromises.delete(filename);
        resolve(null); // Null zurückgeben statt Fehler werfen
      });

      // Versuche Audio zu laden
      try {
        audio.src = `/${filename}`;
        audio.preload = 'auto';
      } catch (error) {
        console.warn(`Fehler beim Laden von ${filename}:`, error);
        resolve(null);
      }
    });

    this.loadingPromises.set(filename, loadPromise);
    return loadPromise;
  }

  async playTrack(filename, loop = false, fadeInMs = 0) {
    if (!this.isEnabled) return;

    try {
      // Aktuellen Track stoppen
      if (this.currentAudio) {
        this.stopTrack(100);
      }

      // Audio laden
      const audio = await this.loadAudio(filename);

      // Wenn Audio nicht geladen werden konnte, stumm weitermachen
      if (!audio) {
        console.log(`Audio ${filename} nicht verfügbar - stumme Wiedergabe`);
        return;
      }

      this.currentAudio = audio;
      audio.loop = loop;
      audio.volume = fadeInMs > 0 ? 0 : this.volume;

      // Abspielen
      await audio.play();

      // Fade-in Effekt
      if (fadeInMs > 0) {
        this.fadeIn(audio, fadeInMs);
      }
    } catch (error) {
      console.warn(`Fehler beim Abspielen von ${filename}:`, error);
      // Nicht als Fehler behandeln, sondern stumm weitermachen
    }
  }

  fadeIn(audio, duration) {
    const steps = 20;
    const stepVolume = this.volume / steps;
    const stepTime = duration / steps;
    let currentStep = 0;

    const fadeInterval = setInterval(() => {
      currentStep++;
      audio.volume = Math.min(stepVolume * currentStep, this.volume);

      if (currentStep >= steps) {
        clearInterval(fadeInterval);
      }
    }, stepTime);
  }

  stopTrack(fadeOutMs = 0) {
    if (!this.currentAudio) return;

    if (fadeOutMs > 0) {
      this.fadeOut(this.currentAudio, fadeOutMs);
    } else {
      this.currentAudio.pause();
      this.currentAudio = null;
    }
  }

  fadeOut(audio, duration) {
    const steps = 20;
    const stepVolume = audio.volume / steps;
    const stepTime = duration / steps;
    let currentStep = 0;

    const fadeInterval = setInterval(() => {
      currentStep++;
      audio.volume = Math.max(audio.volume - stepVolume, 0);

      if (currentStep >= steps || audio.volume <= 0) {
        clearInterval(fadeInterval);
        audio.pause();
        if (this.currentAudio === audio) {
          this.currentAudio = null;
        }
      }
    }, stepTime);
  }

  setEnabled(enabled) {
    this.isEnabled = enabled;
    if (!enabled && this.currentAudio) {
      this.stopTrack(100);
    }
  }
}

// Singleton-Instanz exportieren
const audioManager = new AudioManager();
export default audioManager;
