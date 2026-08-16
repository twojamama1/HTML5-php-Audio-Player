const audio = document.getElementById("audio");
const browser = document.getElementById("browser");
const refreshBtn = document.getElementById("refresh");
const cover = document.getElementById("cover");
const title = document.getElementById("title");
const artist = document.getElementById("artist");
const album = document.getElementById("album");
const folder = document.getElementById("folder");
const playBtn = document.getElementById("play");
const prevBtn = document.getElementById("prev");
const nextBtn = document.getElementById("next");
const progress = document.getElementById("progress");
const currentTime = document.getElementById("current-time");
const duration = document.getElementById("duration");
const volume = document.getElementById("volume");
const volumeIcon = document.getElementById("volume-icon");
const volumeValue = document.getElementById("volume-value");
const status = document.getElementById("status");

const FALLBACK_COVER =
  "data:image/svg+xml;charset=UTF-8," +
  encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600">
    <rect width="600" height="600" fill="#171a22"/>
    <circle cx="300" cy="300" r="170" fill="#282d39"/>
    <circle cx="300" cy="300" r="42" fill="#171a22"/>
    <circle cx="300" cy="300" r="12" fill="#9b7bd1"/>
  </svg>`);

// Show the same fallback artwork before any track is selected.
cover.src = FALLBACK_COVER;

let tracks = [];
let currentIndex = -1;

function formatTime(seconds) {
  if (!Number.isFinite(seconds)) return "0:00";
  const s = Math.floor(seconds);
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, c => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
  }[c]));
}

async function loadLibrary() {
  browser.innerHTML = `<div class="loading">Skanowanie biblioteki…</div>`;

  try {
    const response = await fetch("api.php?action=scan", { cache: "no-store" });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const data = await response.json();
    if (!data.ok) throw new Error(data.error || "Nieznany błąd");

    tracks = data.tracks || [];
    renderBrowser();
    status.textContent = `Znaleziono ${tracks.length} plików audio (FLAC / MP3 / WAV)`;
  } catch (error) {
    console.error(error);
    browser.innerHTML = `<div class="error">Nie udało się zeskanować biblioteki.<br>${escapeHtml(error.message)}</div>`;
    status.textContent = "Błąd skanowania";
  }
}

function buildTree() {
  const root = { folders: new Map(), files: [] };

  for (const track of tracks) {
    const parts = track.relative.split("/").filter(Boolean);
    const fileName = parts.pop();
    let node = root;

    for (const part of parts) {
      if (!node.folders.has(part)) {
        node.folders.set(part, { folders: new Map(), files: [] });
      }
      node = node.folders.get(part);
    }

    node.files.push({ ...track, displayName: fileName });
  }

  return root;
}

function renderFolder(name, node, path = "") {
  const details = document.createElement("details");
  details.className = "folder";

  const summary = document.createElement("summary");
  summary.textContent = `${name} (${node.files.length + countFiles(node)})`;
  details.appendChild(summary);

  const content = document.createElement("div");
  content.className = "folder-content";

  [...node.folders.entries()]
    .sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true }))
    .forEach(([folderName, child]) => {
      content.appendChild(renderFolder(folderName, child, `${path}${folderName}/`));
    });

  node.files
    .sort((a, b) => a.displayName.localeCompare(b.displayName, undefined, { numeric: true }))
    .forEach(track => {
      content.appendChild(createTrackButton(track));
    });

  details.appendChild(content);
  return details;
}

function countFiles(node) {
  let total = 0;
  for (const child of node.folders.values()) {
    total += child.files.length + countFiles(child);
  }
  return total;
}

function createTrackButton(track) {
  const button = document.createElement("button");
  button.className = "track";
  button.dataset.id = track.id;

  button.innerHTML = `
    <span class="track-icon">♫</span>
    <span class="track-name">${escapeHtml(track.title || track.displayName)}</span>
  `;

  button.addEventListener("click", () => playTrackById(track.id));
  return button;
}

function renderBrowser() {
  browser.innerHTML = "";

  if (!tracks.length) {
    browser.innerHTML = `<div class="empty">Nie znaleziono plików FLAC, MP3 ani WAV.</div>`;
    return;
  }

  const tree = buildTree();

  for (const [folderName, node] of tree.folders.entries()) {
    browser.appendChild(renderFolder(folderName, node));
  }

  for (const track of tree.files) {
    browser.appendChild(createTrackButton(track));
  }
}

function playTrackById(id) {
  const index = tracks.findIndex(t => t.id === id);
  if (index >= 0) playTrack(index);
}

function playTrack(index) {
  if (index < 0 || index >= tracks.length) return;

  currentIndex = index;
  const track = tracks[index];

  audio.src = track.url;
  audio.load();

  title.textContent = track.title || track.filename;
  artist.textContent = track.artist || "Nieznany wykonawca";
  album.textContent = track.album || "Nieznany album";
  folder.textContent = track.relative;

  cover.src = track.cover || FALLBACK_COVER;
  cover.onerror = () => {
    cover.onerror = null;
    cover.src = FALLBACK_COVER;
  };

  document.querySelectorAll(".track.active").forEach(el => el.classList.remove("active"));
  const active = document.querySelector(`.track[data-id="${CSS.escape(track.id)}"]`);
  if (active) {
    active.classList.add("active");
    active.scrollIntoView({ block: "nearest" });
  }

  audio.play().catch(() => {});
  status.textContent = track.filename;
}

function getCurrentFolderTracks() {
  if (currentIndex < 0) return [];

  const current = tracks[currentIndex];
  const slash = current.relative.lastIndexOf("/");
  const dir = slash >= 0 ? current.relative.slice(0, slash) : "";

  return tracks
    .map((track, index) => ({ track, index }))
    .filter(({ track }) => {
      const s = track.relative;
      const i = s.lastIndexOf("/");
      return (i >= 0 ? s.slice(0, i) : "") === dir;
    });
}

function playRelative(direction) {
  const folderTracks = getCurrentFolderTracks();
  if (!folderTracks.length) return;

  const position = folderTracks.findIndex(x => x.index === currentIndex);
  const nextPosition =
    (position + direction + folderTracks.length) % folderTracks.length;

  playTrack(folderTracks[nextPosition].index);
}

playBtn.addEventListener("click", () => {
  if (!audio.src) {
    if (tracks.length) playTrack(0);
    return;
  }

  if (audio.paused) audio.play();
  else audio.pause();
});

prevBtn.addEventListener("click", () => playRelative(-1));
nextBtn.addEventListener("click", () => playRelative(1));

audio.addEventListener("play", () => {
  playBtn.textContent = "⏸";
});

audio.addEventListener("pause", () => {
  playBtn.textContent = "▶";
});

audio.addEventListener("loadedmetadata", () => {
  duration.textContent = formatTime(audio.duration);
  progress.max = Number.isFinite(audio.duration) ? audio.duration : 100;
});

audio.addEventListener("timeupdate", () => {
  currentTime.textContent = formatTime(audio.currentTime);
  progress.value = audio.currentTime;
});

audio.addEventListener("ended", () => {
  playRelative(1);
});

audio.addEventListener("error", () => {
  status.textContent = "Nie można odtworzyć tego pliku.";
});

progress.addEventListener("input", () => {
  audio.currentTime = Number(progress.value);
});

volume.addEventListener("input", () => {
  audio.volume = Number(volume.value);
  updateVolumeUI();
});

volumeIcon.addEventListener("click", () => {
  audio.muted = !audio.muted;
  updateVolumeUI();
});

function updateVolumeUI() {
  const v = audio.muted ? 0 : audio.volume;
  volumeValue.textContent = `${Math.round(v * 100)}%`;
  volumeIcon.textContent = v === 0 ? "🔇" : v < .5 ? "🔉" : "🔊";
}

refreshBtn.addEventListener("click", loadLibrary);

audio.volume = .7;
updateVolumeUI();
loadLibrary();
