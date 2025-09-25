// Einfacher AudioManager für das Abspielen von Musik und Effekten
class AudioManager {
  constructor() {
    this.currentAudio = null;
    this.volume = 0.3;
    this.isEnabled = true;
    this.audioCache = new Map();
    this.loadingPromises = new Map();
    this.activeEffects = new Set();
    this._unlockBound = false;
    this._pendingTrack = null; // {filename, loop, fadeInMs}
    this._playAttempted = false;
    this._fallbackPrefixes = ['/', '/frontend/build/']; // versucht beide Basen
    // Neu: Autoplay-Status
    this.autoplayBlocked = false;
    this._statusListeners = new Set();
  }

  // Listener-Verwaltung für Statusänderungen (z.B. autoplayBlocked)
  addStatusListener(fn) { if (fn && typeof fn === 'function') this._statusListeners.add(fn); }
  removeStatusListener(fn) { this._statusListeners.delete(fn); }
  _notifyStatus() { const payload = { autoplayBlocked: this.autoplayBlocked, enabled: this.isEnabled }; this._statusListeners.forEach(fn => { try { fn(payload); } catch(_){} }); }
  _setAutoplayBlocked(flag) { if (this.autoplayBlocked !== flag) { this.autoplayBlocked = flag; this._notifyStatus(); } }

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
    this._notifyStatus();
  }

  // Lädt (oder holt aus Cache) ein Audio-Element
  async loadAudio(filename) {
    const normName = filename.replace(/\\/g,'/').replace(/^\//,'');
    const candidates = this._fallbackPrefixes.map(p => p.replace(/\/$/,'') + '/' + normName);
    // Falls Root bereits enthalten war, sicherstellen dass es ganz vorne steht
    if (filename.startsWith('/')) candidates.unshift(filename);
    let lastError = null;
    for (const src of candidates) {
      const audio = await this._loadSingle(src).catch(e=>{ lastError=e; return null; });
      if (audio) return audio; // erster Treffer
    }
    if (lastError) console.warn('Audio konnte nicht geladen werden:', normName, lastError);
    return null;
  }
  async _loadSingle(src) {
    if (this.audioCache.has(src)) return this.audioCache.get(src);
    if (this.loadingPromises.has(src)) return this.loadingPromises.get(src);
    const loadPromise = new Promise(resolve => {
      const audio = new Audio();
      let done=false;
      const finish = (result) => { if (done) return; done=true; resolve(result); };
      audio.addEventListener('canplaythrough', () => {
        this.audioCache.set(src, audio); this.loadingPromises.delete(src); finish(audio);
      }, { once:true });
      audio.addEventListener('error', () => { this.loadingPromises.delete(src); finish(null); }, { once:true });
      audio.preload='auto';
      audio.src = src;
    });
    this.loadingPromises.set(src, loadPromise);
    return loadPromise;
  }

  requestBackgroundMusic(filename, loop=true, fadeInMs=0) {
    // Sofort versuchen
    this.ensureAutoplayUnlock();
    this._pendingTrack = { filename, loop, fadeInMs };
    this._tryPlayPending();
  }

  ensureAutoplayUnlock() {
    if (this._unlockBound) return;
    const unlockHandler = () => {
      this._tryPlayPending(true);
    };
    ['pointerdown','click','keydown','touchstart'].forEach(ev => {
      window.addEventListener(ev, unlockHandler, { passive:true });
    });
    this._unlockBound = true;
  }

  async _tryPlayPending(fromUserGesture=false) {
    if (!this._pendingTrack || !this.isEnabled) return;
    const { filename, loop, fadeInMs } = this._pendingTrack;
    try {
      await this.playTrack(filename, loop, fadeInMs, {internal:true});
      this._pendingTrack = null;
      this._setAutoplayBlocked(false);
    } catch (e) {
      if (fromUserGesture) {
        console.warn('Playback trotz User-Geste fehlgeschlagen:', e);
      }
      // Bleibt pending für nächsten Versuch
    }
  }

  // Öffentliche Methode um nach User-Klick erneut zu versuchen
  attemptResume() {
    if (!this.autoplayBlocked && !this._pendingTrack) return Promise.resolve(false);
    return this._tryPlayPending(true) || Promise.resolve(true);
  }

  // Spielt Hintergrundmusik (ersetzt vorherigen Track)
  async playTrack(filename, loop = true, fadeInMs = 0, opts={internal:false}) {
    if (!this.isEnabled) return;
    if (!opts.internal) this._pendingTrack = { filename, loop, fadeInMs };
    try {
      if (this.currentAudio) this.stopTrack(200);
      const audio = await this.loadAudio(filename);
      if (!audio) throw new Error('Audio nicht geladen');
      this.currentAudio = audio;
      audio.loop = !!loop;
      audio.currentTime = 0;
      // Vorbereiten für Fade-In
      if (fadeInMs > 0) {
        audio.volume = 0;
      } else {
        audio.volume = this.volume;
      }
      const playResult = audio.play();
      let wrappedPromise = null;
      if (playResult && typeof playResult.then === 'function') {
        wrappedPromise = playResult.then(() => {
          this._setAutoplayBlocked(false);
          // erfolgreicher Start -> Pending löschen
          if (!opts.internal) this._pendingTrack = null;
          return true;
        }).catch(err => {
          if (err && (err.name === 'NotAllowedError' || /play\(\) failed|Autoplay/i.test(err.message||''))) {
            this._setAutoplayBlocked(true);
          }
          if (!opts.internal) console.warn('Playback verweigert (Autoplay oder anderer Fehler). Warte auf User-Geste…');
          // Nur zurücksetzen falls dieses Audio noch aktuell ist
          if (this.currentAudio === audio) this.currentAudio = null;
          throw err;
        });
      } else {
        // Kein Promise -> gilt als erfolgreich
        this._setAutoplayBlocked(false);
        if (!opts.internal) this._pendingTrack = null;
      }
      // Fade-In jetzt wirklich ausführen
      if (fadeInMs > 0 && this.currentAudio === audio) {
        this.fadeIn(audio, fadeInMs, this.volume);
      }
      return wrappedPromise; // kann null sein
    } catch (e) {
      if (!opts.internal) console.warn('Fehler beim Starten des Tracks:', e);
      throw e;
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
