import { createPlayer } from './player/playbackController.js';
import { createSocket } from './network/websocket.js';

const audio = document.querySelector('#audio');
const player = createPlayer(audio);
const libraryEl = document.querySelector('#library');
const searchEl = document.querySelector('#search');
let tracks = [];

const themeToggle = document.querySelector('#theme-toggle');
const root = document.documentElement;
const savedTheme = localStorage.getItem('rivune-theme');
if (savedTheme === 'light' || savedTheme === 'dark') {
  root.dataset.theme = savedTheme;
}
function syncThemeButton() {
  const isDark = root.dataset.theme !== 'light';
  themeToggle.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
}
syncThemeButton();
themeToggle.addEventListener('click', () => {
  root.dataset.theme = root.dataset.theme === 'light' ? 'dark' : 'light';
  localStorage.setItem('rivune-theme', root.dataset.theme);
  document.querySelector('meta[name="theme-color"]').content =
    root.dataset.theme === 'light' ? '#F7F5FC' : '#11111D';
  syncThemeButton();
});
document.addEventListener('keydown', (event) => {
  if (event.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
    event.preventDefault();
    searchEl.focus();
  }
});


function formatTime(seconds = 0) {
  if (!Number.isFinite(seconds)) return '0:00';
  const mins = Math.floor(seconds / 60);
  return `${mins}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
}

function render(list) {
  libraryEl.replaceChildren();
  document.querySelector('#track-count').textContent = `${list.length} tracks`;
  if (!list.length) {
    const empty = document.createElement('div');
    empty.className = 'empty';
    empty.textContent = tracks.length ? 'No matching tracks.' : 'No music found. Add audio files to the music/ folder and refresh.';
    libraryEl.append(empty);
    return;
  }
  for (const track of list) {
    const row = document.createElement('button');
    row.className = `track${player.currentTrack?.id === track.id ? ' active' : ''}`;
    row.type = 'button';
    row.innerHTML = `<span class="cover">♫</span><span class="track-main"><strong></strong><span></span></span><time></time>`;
    if (track.artworkUrl) { row.querySelector('.cover').style.backgroundImage = `url("${track.artworkUrl}")`; row.querySelector('.cover').style.backgroundSize = 'cover'; row.querySelector('.cover').textContent = ''; }
    row.querySelector('strong').textContent = track.title;
    row.querySelector('.track-main span').textContent = `${track.artist} · ${track.album}`;
    row.querySelector('time').textContent = formatTime(track.duration);
    row.addEventListener('click', () => {
      player.setQueue(tracks, track.id);
      player.play();
      updatePlayer();
      render(filtered());
    });
    libraryEl.append(row);
  }
}

function filtered() {
  const q = searchEl.value.trim().toLowerCase();
  return tracks.filter(t => `${t.title} ${t.artist} ${t.album}`.toLowerCase().includes(q));
}
function updatePlayer() {
  const track = player.currentTrack;
  document.querySelector('#now-title').textContent = track?.title || 'Nothing playing';
  document.querySelector('#now-artist').textContent = track?.artist || 'Choose a track to begin';
  const art = document.querySelector('#artwork');
  art.textContent = track?.artworkUrl ? '' : '♫';
  art.style.backgroundImage = track?.artworkUrl ? `url("${track.artworkUrl}")` : 'none';
  art.style.backgroundSize = 'cover';
  document.querySelector('#play-pause').classList.toggle('is-playing', !audio.paused);
  document.querySelector('#play-pause').setAttribute('aria-label', audio.paused ? 'Play' : 'Pause');
}
async function loadLibrary() {
  const response = await fetch('/api/library');
  if (!response.ok) throw new Error('Unable to load music library');
  const data = await response.json();
  tracks = data.tracks;
  render(filtered());
}
searchEl.addEventListener('input', () => render(filtered()));
document.querySelector('#play-pause').addEventListener('click', () => {
  if (!player.currentTrack && tracks.length) player.setQueue(tracks, tracks[0].id);
  player.toggle();
  updatePlayer();
});
document.querySelector('#next').addEventListener('click', () => { player.next(); updatePlayer(); render(filtered()); });
document.querySelector('#previous').addEventListener('click', () => { player.previous(); updatePlayer(); render(filtered()); });
document.querySelector('#volume').addEventListener('input', e => player.setVolume(Number(e.target.value)));
document.querySelector('#seek').addEventListener('input', e => {
  if (audio.duration) audio.currentTime = (Number(e.target.value) / 1000) * audio.duration;
});
audio.addEventListener('timeupdate', () => {
  document.querySelector('#elapsed').textContent = formatTime(audio.currentTime);
  document.querySelector('#duration').textContent = formatTime(audio.duration);
  if (audio.duration) document.querySelector('#seek').value = String(audio.currentTime / audio.duration * 1000);
});
audio.addEventListener('play', updatePlayer);
audio.addEventListener('pause', updatePlayer);
audio.addEventListener('ended', () => { player.next(); updatePlayer(); render(filtered()); });

createSocket((online) => {
  const el = document.querySelector('#connection');
  el.classList.toggle('online', online);
  el.querySelector('span').textContent = online ? 'Server connected' : 'Reconnecting';
}, async (message) => {
  if (message.type === 'library:updated') await loadLibrary();
});

loadLibrary().catch(() => {
  libraryEl.replaceChildren();
  const error = document.createElement('div');
  error.className = 'empty';
  error.innerHTML = '<span class="empty-orb">!</span><strong>Library unavailable</strong><span>Could not connect to the RIVUNE server. Check the connection and refresh.</span>';
  libraryEl.append(error);
});
