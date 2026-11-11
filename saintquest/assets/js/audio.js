// Sound: the home theme (looped while you are at home), spoken lines for the
// people you meet (with a level meter that drives their mouths) and small
// effects. Everything is a static file made for the game; nothing is
// generated in the player's browser. One mute toggle (remembered) silences all.

const base = () => (window.SQ_BASE || '') + '/assets/audio/';
let ctx = null, master = null, musicGain = null, voiceGain = null, analyser = null, levelBuf = null;
let musicBuf = null, musicSrc = null, musicWanted = false, musicLoading = null;
let muted = localStorage.getItem('sq-muted') === '1';
const bufCache = new Map();
const muteFns = [];
let speaking = null; // { src, resolve }
let lastLevel = 0;

export const isMuted = () => muted;
export function onMute(fn) { muteFns.push(fn); }

function ensure() {
  if (ctx) return ctx;
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) return null;
  ctx = new AC();
  master = ctx.createGain();
  master.gain.value = muted ? 0 : 1;
  master.connect(ctx.destination);
  musicGain = ctx.createGain();
  musicGain.gain.value = 0;
  musicGain.connect(master);
  voiceGain = ctx.createGain();
  voiceGain.gain.value = 1;
  analyser = ctx.createAnalyser();
  analyser.fftSize = 512;
  levelBuf = new Float32Array(analyser.fftSize);
  voiceGain.connect(analyser);
  analyser.connect(master);
  return ctx;
}

/** Call from a user gesture (key press, click): browsers only allow sound after one. */
export function unlock() {
  const c = ensure();
  if (c && c.state === 'suspended') c.resume().catch(() => {});
  if (musicWanted) startMusic();
}
for (const ev of ['pointerdown', 'keydown', 'touchend']) window.addEventListener(ev, unlock, { passive: true });

export function setMuted(m) {
  muted = m;
  localStorage.setItem('sq-muted', m ? '1' : '0');
  if (master) master.gain.setTargetAtTime(m ? 0 : 1, ctx.currentTime, 0.05);
  for (const fn of muteFns) fn(m);
}

async function load(name) {
  if (!bufCache.has(name)) {
    bufCache.set(name, (async () => {
      const c = ensure();
      const data = await fetch(base() + name).then((r) => { if (!r.ok) throw new Error(r.status); return r.arrayBuffer(); });
      return await new Promise((res, rej) => c.decodeAudioData(data, res, rej));
    })().catch((e) => { bufCache.delete(name); throw e; }));
  }
  return bufCache.get(name);
}

export function preloadVoices(ids) { if (ensure()) for (const id of ids) load('voice/' + id + '.mp3').catch(() => {}); }

async function startMusic() {
  const c = ensure();
  if (!c || c.state !== 'running') return;
  if (!musicBuf) {
    if (!musicLoading) musicLoading = load('home-theme.mp3').then((b) => { musicBuf = b; }).catch(() => {});
    await musicLoading;
    if (!musicBuf) return;
  }
  if (!musicSrc) {
    musicSrc = c.createBufferSource();
    musicSrc.buffer = musicBuf;
    musicSrc.loop = true;
    musicSrc.connect(musicGain);
    musicSrc.start();
  }
  musicGain.gain.setTargetAtTime(musicWanted ? 0.45 : 0, c.currentTime, 0.6);
}

/** Home theme on/off (fades). */
export function music(on) {
  musicWanted = on;
  if (!ctx) return;
  if (on) startMusic();
  else musicGain.gain.setTargetAtTime(0, ctx.currentTime, 0.4);
}
export const musicPlaying = () => !!(musicSrc && musicWanted && ctx && ctx.state === 'running');

/** Duck the music while someone talks. */
function duck(on) { if (ctx && musicWanted && musicSrc) musicGain.gain.setTargetAtTime(on ? 0.16 : 0.45, ctx.currentTime, 0.15); }

/**
 * Speak a line (voice/<id>.mp3). Resolves when it ends (or right away, after
 * fallbackMs, if sound is unavailable). Only one line at a time.
 */
export async function say(id, fallbackMs = 1800) {
  stopVoice();
  const c = ensure();
  if (!c || c.state !== 'running') { await new Promise((r) => setTimeout(r, fallbackMs)); return false; }
  let buf;
  try { buf = await load('voice/' + id + '.mp3'); } catch { await new Promise((r) => setTimeout(r, fallbackMs)); return false; }
  return new Promise((resolve) => {
    const src = c.createBufferSource();
    src.buffer = buf;
    src.connect(voiceGain);
    duck(true);
    const me = { src, resolve };
    speaking = me;
    src.onended = () => { if (speaking === me) { speaking = null; duck(false); } resolve(true); };
    src.start();
  });
}

export function stopVoice() {
  if (!speaking) return;
  const s = speaking;
  speaking = null;
  try { s.src.onended = null; s.src.stop(); } catch { /* already stopped */ }
  duck(false);
  s.resolve(false);
}

/** A one-shot effect (e.g. the great bell). */
export async function effect(name) {
  const c = ensure();
  if (!c || c.state !== 'running') return;
  try {
    const b = await load(name);
    const s = c.createBufferSource();
    s.buffer = b;
    s.connect(master);
    s.start();
  } catch { /* no sound */ }
}

/** Current loudness of the voice (0..1), smoothed: drives the talking mouths. */
export function level() {
  if (!analyser || !speaking) { lastLevel *= 0.7; return lastLevel; }
  analyser.getFloatTimeDomainData(levelBuf);
  let sum = 0;
  for (let i = 0; i < levelBuf.length; i++) sum += levelBuf[i] * levelBuf[i];
  const rms = Math.sqrt(sum / levelBuf.length);
  const v = Math.min(1, rms * 5.5);
  lastLevel = lastLevel * 0.45 + v * 0.55;
  return lastLevel;
}
export const isSpeaking = () => !!speaking;

window.__sqAudio = { get muted() { return muted; }, get music() { return musicPlaying(); }, get speaking() { return !!speaking; }, get state() { return ctx ? ctx.state : 'none'; } };
