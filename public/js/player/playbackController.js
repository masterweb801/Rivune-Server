export function createPlayer(audio) {
  let queue = [];
  let index = -1;
  let shuffle = false;
  const history = [];

  const player = {
    get currentTrack() { return queue[index] || null; },
    get isShuffle() { return shuffle; },
    setQueue(tracks, startId) {
      queue = [...tracks];
      index = queue.findIndex(t => t.id === startId);
      if (index < 0) index = 0;
      history.length = 0;
      this.loadCurrent();
    },
    setShuffle(enabled) { shuffle = Boolean(enabled); },
    loadCurrent() {
      const track = this.currentTrack;
      if (!track) { audio.removeAttribute('src'); return; }
      audio.src = track.streamUrl;
      audio.load();
    },
    async play() {
      if (!this.currentTrack) return;
      try { await audio.play(); }
      catch (error) { console.warn('Playback requires a user gesture or supported format:', error); }
    },
    pause() { audio.pause(); },
    toggle() { return audio.paused ? this.play() : this.pause(); },
    next() {
      if (!queue.length) return;
      if (shuffle && queue.length > 1) {
        history.push(index);
        let nextIndex = index;
        while (nextIndex === index) nextIndex = Math.floor(Math.random() * queue.length);
        index = nextIndex;
      } else {
        index = (index + 1) % queue.length;
      }
      this.loadCurrent();
      this.play();
    },
    previous() {
      if (!queue.length) return;
      if (audio.currentTime > 3) { audio.currentTime = 0; return; }
      if (shuffle && history.length) index = history.pop();
      else index = (index - 1 + queue.length) % queue.length;
      this.loadCurrent();
      this.play();
    },
    setVolume(value) { audio.volume = Math.max(0, Math.min(1, value)); }
  };
  audio.volume = 0.8;
  return player;
}
