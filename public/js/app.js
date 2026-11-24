import { createPlayer } from './player/playbackController.js';
import { createSocket } from './network/websocket.js';

const audio = document.querySelector('#audio');
const player = createPlayer(audio);
const libraryEl = document.querySelector('#library');
const searchEl = document.querySelector('#search');
const root = document.documentElement;
let tracks = [];

const themeToggle = document.querySelector('#theme-toggle');
const savedTheme = localStorage.getItem('rivune-theme');
if (savedTheme === 'light' || savedTheme === 'dark') root.dataset.theme = savedTheme;
function syncThemeButton() {
  const isDark = root.dataset.theme !== 'light';
  themeToggle.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
}
syncThemeButton();
themeToggle.addEventListener('click', () => {
  root.dataset.theme = root.dataset.theme === 'light' ? 'dark' : 'light';
  localStorage.setItem('rivune-theme', root.dataset.theme);
  document.querySelector('meta[name="theme-color"]').content = root.dataset.theme === 'light' ? '#F7F5FC' : '#11111D';
  syncThemeButton();
});
// Keep navigation and search in one overlay; reveal it only on upward movement.
const topbarEl = document.querySelector('.topbar');
const scrollHeader = document.createElement('div');
scrollHeader.className = 'scroll-header';
scrollHeader.setAttribute('role', 'region');
scrollHeader.setAttribute('aria-label', 'Navigation and music search');
document.body.prepend(scrollHeader);
scrollHeader.append(topbarEl, searchEl);

let previousScrollY = 0;
let lastDirectionChangeY = 0;
let currentDirection = 0;
const scrollDirectionThreshold = 3;

function getPageScrollY() {
  return Math.max(0, window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0);
}

function updateScrollNavigation() {
  const currentY = getPageScrollY();

  if (document.querySelector('.player')?.classList.contains('expanded')) {
    document.body.classList.add('nav-hidden');
    previousScrollY = currentY;
    currentDirection = 0;
    return;
  }

  if (currentY <= 20) {
    document.body.classList.remove('nav-hidden');
    previousScrollY = currentY;
    lastDirectionChangeY = currentY;
    currentDirection = 0;
    return;
  }

  const delta = currentY - previousScrollY;
  previousScrollY = currentY;
  if (Math.abs(delta) < 1) return;

  const direction = delta > 0 ? 1 : -1;
  if (direction !== currentDirection) {
    currentDirection = direction;
    lastDirectionChangeY = currentY;
  }

  if (Math.abs(currentY - lastDirectionChangeY) >= scrollDirectionThreshold) {
    document.body.classList.toggle('nav-hidden', direction > 0);
  }
}

previousScrollY = getPageScrollY();
window.addEventListener('scroll', updateScrollNavigation, { passive: true });

searchEl.addEventListener('focus', () => document.body.classList.remove('nav-hidden'));
searchEl.addEventListener('blur', () => {
  previousScrollY = getPageScrollY();
  lastDirectionChangeY = previousScrollY;
  currentDirection = 0;
});
document.addEventListener('keydown', event => {
  if (event.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
    event.preventDefault();
    searchEl.focus();
  }
  if (event.key === 'Escape') document.querySelector('.player').classList.remove('expanded');
});

function formatTime(seconds = 0) {
  if (!Number.isFinite(seconds)) return '0:00';
  return `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
}
function filtered() {
  const q = searchEl.value.trim().toLowerCase();
  return tracks.filter(t => `${t.title} ${t.artist} ${t.album}`.toLowerCase().includes(q));
}
function render(list) {
  libraryEl.replaceChildren();
  document.querySelector('#track-count').textContent = `${tracks.length} ${tracks.length === 1 ? 'track' : 'tracks'}`;
  if (!list.length) {
    const empty = document.createElement('div');
    empty.className = 'empty';
    empty.innerHTML = '<span class="empty-orb">♫</span><strong></strong><span></span>';
    empty.querySelector('strong').textContent = tracks.length ? 'No matching tracks' : 'Your library is waiting';
    empty.querySelector('span:last-child').textContent = tracks.length ? 'Try another search.' : 'Add audio files using the button above.';
    libraryEl.append(empty);
    return;
  }
  for (const track of list) {
    const row = document.createElement('div');
    row.className = 'track-row';
    const playButton = document.createElement('button');
    playButton.className = `track${player.currentTrack?.id === track.id ? ' active' : ''}`;
    playButton.type = 'button';
    playButton.innerHTML = '<span class="cover">♫</span><span class="track-main"><strong></strong><span></span></span><time></time>';
    if (track.artworkUrl) {
      playButton.querySelector('.cover').style.backgroundImage = `url("${track.artworkUrl}")`;
      playButton.querySelector('.cover').textContent = '';
    }
    playButton.querySelector('strong').textContent = track.title;
    playButton.querySelector('.track-main span').textContent = `${track.artist} · ${track.album}`;
    playButton.querySelector('time').textContent = formatTime(track.duration);
    playButton.addEventListener('click', () => {
      player.setQueue(tracks, track.id);
      player.play();
      updatePlayer();
      render(filtered());
    });
    const remove = document.createElement('button');
    remove.className = 'delete-track';
    remove.type = 'button';
    remove.title = `Delete ${track.title}`;
    remove.setAttribute('aria-label', `Delete ${track.title}`);
    remove.textContent = '×';
    remove.addEventListener('click', async () => {
      if (!confirm(`Delete “${track.title}” from your music library? This removes the file from the server.`)) return;
      remove.disabled = true;
      try {
        const response = await fetch(`/api/tracks/${encodeURIComponent(track.id)}`, { method: 'DELETE' });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Could not delete track');
        if (player.currentTrack?.id === track.id) {
          player.clear();
          updatePlayer();
        }
        await loadLibrary();
      } catch (error) {
        alert(error.message);
        remove.disabled = false;
      }
    });
    row.append(playButton, remove);
    libraryEl.append(row);
  }
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
  const shuffleButton = document.querySelector('#shuffle');
  shuffleButton.classList.toggle('selected', player.isShuffle);
  shuffleButton.setAttribute('aria-pressed', String(player.isShuffle));
  shuffleButton.setAttribute('aria-label', player.isShuffle ? 'Disable shuffle' : 'Enable shuffle');
}
async function loadLibrary() {
  const response = await fetch('/api/library');
  if (!response.ok) throw new Error('Unable to load music library');
  const data = await response.json();
  tracks = Array.isArray(data.tracks) ? data.tracks : [];
  render(filtered());
}
searchEl.addEventListener('input', () => render(filtered()));
document.querySelector('#upload-files').addEventListener('change', async event => {
  const files = [...event.target.files];
  if (!files.length) return;
  const upload = document.querySelector('.upload-button');
  upload.classList.add('busy');
  upload.setAttribute('aria-busy', 'true');
  try {
    for (const file of files) {
      const response = await fetch(`/api/tracks/upload?filename=${encodeURIComponent(file.name)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/octet-stream' },
        body: file
      });
      const result = await response.json();
      if (!response.ok) throw new Error(`${file.name}: ${result.error || 'Upload failed'}`);
    }
    await loadLibrary();
  } catch (error) {
    alert(error.message);
    await loadLibrary().catch(() => {});
  } finally {
    event.target.value = '';
    upload.classList.remove('busy');
    upload.removeAttribute('aria-busy');
  }
});
document.querySelector('#play-pause').addEventListener('click', () => {
  if (!player.currentTrack && tracks.length) player.setQueue(tracks, tracks[0].id);
  player.toggle();
  updatePlayer();
});
document.querySelector('#next').addEventListener('click', () => { player.next(); updatePlayer(); render(filtered()); });
document.querySelector('#previous').addEventListener('click', () => { player.previous(); updatePlayer(); render(filtered()); });
document.querySelector('#shuffle').addEventListener('click', () => { player.setShuffle(!player.isShuffle); updatePlayer(); });
document.querySelector('#expand-player').addEventListener('click', () => {
  const expanded = document.querySelector('.player').classList.toggle('expanded');
  document.querySelector('#expand-player').setAttribute('aria-label', expanded ? 'Collapse player' : 'Expand player');
});
document.querySelector('#volume').addEventListener('input', event => player.setVolume(Number(event.target.value)));
document.querySelector('#seek').addEventListener('input', event => {
  if (audio.duration) audio.currentTime = (Number(event.target.value) / 1000) * audio.duration;
});
audio.addEventListener('timeupdate', () => {
  document.querySelector('#elapsed').textContent = formatTime(audio.currentTime);
  document.querySelector('#duration').textContent = formatTime(audio.duration);
  if (audio.duration) document.querySelector('#seek').value = String(audio.currentTime / audio.duration * 1000);
});
audio.addEventListener('play', updatePlayer);
audio.addEventListener('pause', updatePlayer);
audio.addEventListener('ended', () => { player.next(); updatePlayer(); render(filtered()); });

createSocket(online => {
  const el = document.querySelector('#connection');
  el.classList.toggle('online', online);
  el.querySelector('span').textContent = online ? 'Server connected' : 'Reconnecting';
}, async message => {
  if (message.type === 'library:updated') await loadLibrary();
});
loadLibrary().catch(() => {
  libraryEl.replaceChildren();
  const error = document.createElement('div');
  error.className = 'empty';
  error.innerHTML = '<span class="empty-orb">!</span><strong>Library unavailable</strong><span>Could not connect to the RIVUNE server. Check the connection and refresh.</span>';
  libraryEl.append(error);
});
