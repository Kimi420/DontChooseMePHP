// Einfacher AudioManager für das Abspielen von Sounds
class AudioManager {
  constructor() {
    this.currentAudio = null;
    this.volume = 0.3;
  }

  setVolume(vol) {
    this.volume = vol;
    if (this.currentAudio) {
      this.currentAudio.volume = vol;
    }
  }

  playTrack(src, loop = false, fadeIn = 0) {
    if (this.currentAudio) {
      this.currentAudio.pause();
      this.currentAudio = null;
    }
    const audio = new window.Audio(src);
    audio.loop = loop;
    audio.volume = 0;
    audio.play();
    this.currentAudio = audio;
    if (fadeIn > 0) {
      let v = 0;
      const step = this.volume / (fadeIn / 50);
      const fade = setInterval(() => {
        v += step;
        audio.volume = Math.min(v, this.volume);
        if (v >= this.volume) clearInterval(fade);
      }, 50);
    } else {
      audio.volume = this.volume;
    }
  }

  playEffect(src) {
    const audio = new window.Audio(src);
    audio.volume = this.volume;
    audio.play();
  }

  stopTrack(fadeOut = 0) {
    if (this.currentAudio) {
      if (fadeOut > 0) {
        let v = this.currentAudio.volume;
        const step = v / (fadeOut / 50);
        const fade = setInterval(() => {
          v -= step;
          this.currentAudio.volume = Math.max(v, 0);
          if (v <= 0) {
            clearInterval(fade);
            this.currentAudio.pause();
            this.currentAudio = null;
          }
        }, 50);
      } else {
        this.currentAudio.pause();
        this.currentAudio = null;
      }
    }
  }
}

const audioManager = new AudioManager();
export default audioManager;

