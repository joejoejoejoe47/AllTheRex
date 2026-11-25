// Sounds (Web Audio, no files), confetti, emoji artwork and small SVG pieces.

let ctx = null;
let soundOn = (() => { try { return localStorage.getItem('cc_sound') !== 'off'; } catch { return true; } })();
export const sound = {
  get on() { return soundOn; },
  set on(v) { soundOn = v; try { localStorage.setItem('cc_sound', v ? 'on' : 'off'); } catch {} },
};
function ac() {
  if (!soundOn) return null;
  try {
    ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  } catch { return null; }
}
function tone(c, freq, t0, dur, { type = 'sine', vol = 0.18, glide = 0 } = {}) {
  const o = c.createOscillator();
  const g = c.createGain();
  o.type = type;
  o.frequency.setValueAtTime(freq, t0);
  if (glide) o.frequency.exponentialRampToValueAtTime(freq * glide, t0 + dur);
  g.gain.setValueAtTime(0.0001, t0);
  g.gain.exponentialRampToValueAtTime(vol, t0 + 0.012);
  g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
  o.connect(g).connect(c.destination);
  o.start(t0);
  o.stop(t0 + dur + 0.05);
}
export function sfx(name) {
  const c = ac();
  if (!c) return;
  const t = c.currentTime + 0.01;
  if (name === 'pop') {
    tone(c, 520, t, 0.09, { type: 'sine', vol: 0.16, glide: 1.6 });
  } else if (name === 'pick') {
    tone(c, 700, t, 0.06, { type: 'triangle', vol: 0.09, glide: 1.25 });
  } else if (name === 'whoosh') {
    const len = 0.45;
    const buf = c.createBuffer(1, c.sampleRate * len, c.sampleRate);
    const d = buf.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / d.length);
    const src = c.createBufferSource();
    src.buffer = buf;
    const f = c.createBiquadFilter();
    f.type = 'bandpass';
    f.frequency.setValueAtTime(400, t);
    f.frequency.exponentialRampToValueAtTime(2600, t + len);
    f.Q.value = 1.2;
    const g = c.createGain();
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(0.12, t + 0.12);
    g.gain.exponentialRampToValueAtTime(0.0001, t + len);
    src.connect(f).connect(g).connect(c.destination);
    src.start(t);
  } else if (name === 'success') {
    [523.25, 659.25, 783.99, 1046.5].forEach((f, i) => tone(c, f, t + i * 0.075, 0.32, { type: 'triangle', vol: 0.14 }));
    tone(c, 2093, t + 0.3, 0.25, { type: 'sine', vol: 0.05 });
  } else if (name === 'known') {
    [587.33, 783.99].forEach((f, i) => tone(c, f, t + i * 0.08, 0.22, { type: 'triangle', vol: 0.11 }));
  } else if (name === 'fanfare') {
    const notes = [[523.25, 0], [659.25, 0.12], [783.99, 0.24], [1046.5, 0.36], [987.77, 0.62], [1046.5, 0.74], [1318.5, 0.86]];
    notes.forEach(([f, d]) => { tone(c, f, t + d, 0.42, { type: 'sawtooth', vol: 0.05 }); tone(c, f, t + d, 0.5, { type: 'triangle', vol: 0.12 }); });
    for (let i = 0; i < 10; i++) tone(c, 1800 + Math.random() * 1800, t + 1 + i * 0.05, 0.12, { type: 'sine', vol: 0.03 });
  } else if (name === 'error') {
    tone(c, 220, t, 0.18, { type: 'square', vol: 0.05, glide: 0.7 });
  }
}

// ---------- Emoji artwork (Microsoft Fluent Emoji, MIT, via jsDelivr) ----------
const CDN = 'https://cdn.jsdelivr.net/gh/microsoft/fluentui-emoji@main/assets/';
let fluent = null;
export async function loadFluent(base) {
  if (fluent) return fluent;
  try {
    const r = await fetch(base + 'assets/data/fluent.json');
    fluent = await r.json();
  } catch { fluent = {}; }
  return fluent;
}
function entry(emoji) {
  if (!fluent || !emoji) return null;
  return fluent[emoji.replace(/\uFE0F/g, '')] || null;
}
export function iconUrl(emoji, style = '3d') {
  const e = entry(emoji);
  if (!e) return null;
  if (Array.isArray(e)) {
    const p = style === 'flat' ? e[1] : e[0];
    return p ? CDN + p.split('/').map(encodeURIComponent).join('/') : null;
  }
  const base = e.split('/')[0];
  const stem = base.toLowerCase().replace(/ /g, '_');
  const file = style === 'flat' ? `Flat/${stem}_flat.svg` : `3D/${stem}_3d.png`;
  return CDN + e.split('/').map(encodeURIComponent).join('/') + '/' + file;
}
export function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
/** Swap the crown emoji for a star so it never appears in the UI. */
export function safeEmoji(emoji) {
  return String(emoji ?? '').replaceAll('👑', '⭐');
}
/** Small inline icon: Fluent 3D picture with the native emoji as fallback. */
export function ico(emoji, cls = 'ico') {
  emoji = safeEmoji(emoji);
  const u = iconUrl(emoji);
  if (!u) return `<span class="emo">${esc(emoji)}</span>`;
  return `<img class="${cls}" src="${u}" alt="${esc(emoji)}" loading="lazy" decoding="async" onerror="this.outerHTML='<span class=&quot;emo&quot;>'+this.alt+'</span>'">`;
}
/** Big illustration: bundled art if the element has one, else a large 3D emoji. */
export function artHtml(el, base) {
  if (el.art) return `<img class="artimg" src="${base}assets/art/${esc(el.art)}.webp" alt="${esc(el.name)}" decoding="async">`;
  const emoji = safeEmoji(el.emoji);
  const u = iconUrl(emoji);
  if (u) return `<img class="big3d" src="${u}" alt="${esc(emoji)}" decoding="async" crossorigin="anonymous" onerror="this.outerHTML='<span class=&quot;emo bigtxt&quot;>'+this.alt+'</span>'">`;
  return `<span class="emo bigtxt">${esc(emoji)}</span>`;
}

// ---------- Confetti ----------
export function confetti(canvas, ms = 4200) {
  const c = canvas.getContext('2d');
  const dpr = Math.min(2, window.devicePixelRatio || 1);
  const resize = () => { canvas.width = innerWidth * dpr; canvas.height = innerHeight * dpr; };
  resize();
  const colors = ['#f7a8c8', '#a8e6cf', '#ffd3a5', '#c3b1f7', '#9ad7f2', '#ffe08a', '#f9b4b4', '#b8f2e6', '#e6c3ff'];
  const parts = [];
  const N = Math.round(Math.min(160, innerWidth / 7));
  for (let i = 0; i < N; i++) {
    parts.push({
      x: Math.random() * canvas.width,
      y: -Math.random() * canvas.height * 0.8,
      w: (8 + Math.random() * 12) * dpr,
      h: (5 + Math.random() * 8) * dpr,
      vy: (1.2 + Math.random() * 2.2) * dpr,
      vx: (Math.random() - 0.5) * 1.2 * dpr,
      r: Math.random() * Math.PI,
      vr: (Math.random() - 0.5) * 0.12,
      c: colors[i % colors.length],
      a: 0.55 + Math.random() * 0.35,
      sway: Math.random() * Math.PI * 2,
    });
  }
  const start = performance.now();
  let raf;
  const step = (now) => {
    const el = now - start;
    c.clearRect(0, 0, canvas.width, canvas.height);
    const fade = el > ms - 900 ? Math.max(0, (ms - el) / 900) : 1;
    for (const p of parts) {
      p.sway += 0.03;
      p.x += p.vx + Math.sin(p.sway) * 0.6 * dpr;
      p.y += p.vy;
      p.r += p.vr;
      if (p.y > canvas.height + 30 && el < ms - 1400) { p.y = -20; p.x = Math.random() * canvas.width; }
      c.save();
      c.globalAlpha = p.a * fade;
      c.translate(p.x, p.y);
      c.rotate(p.r);
      c.scale(1, Math.abs(Math.cos(p.sway * 1.3)) * 0.7 + 0.3);
      c.fillStyle = p.c;
      c.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
      c.restore();
    }
    if (el < ms && canvas.isConnected) raf = requestAnimationFrame(step);
    else c.clearRect(0, 0, canvas.width, canvas.height);
  };
  raf = requestAnimationFrame(step);
  addEventListener('resize', resize, { once: true });
  return () => cancelAnimationFrame(raf);
}

// ---------- SVG bits ----------
export const svg = {
  share: '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="18" cy="5.5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="18.5" r="3"/><path d="M8.6 10.7l6.8-3.9M8.6 13.3l6.8 3.9" stroke="currentColor" stroke-width="2"/></svg>',
  upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4M7.5 8.5L12 4l4.5 4.5M5 14v4.5A1.5 1.5 0 006.5 20h11a1.5 1.5 0 001.5-1.5V14"/></svg>',
  arrowShare: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 5v4C7 9.6 4 13.5 3.5 19c2-3.3 5-5 9.5-5v4l7.5-6.5z"/></svg>',
  link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M10 14a4.5 4.5 0 006.4 0l3-3a4.5 4.5 0 00-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 00-6.4 0l-3 3a4.5 4.5 0 006.4 6.4l1-1"/></svg>',
  download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 19h14"/></svg>',
  close: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
  left: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>',
  right: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
  search: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/></svg>',
  lock: '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M7 10V8a5 5 0 0110 0v2h1a1 1 0 011 1v9a1 1 0 01-1 1H6a1 1 0 01-1-1v-9a1 1 0 011-1zm2 0h6V8a3 3 0 00-6 0z"/></svg>',
  sparkle: '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>',
  trophy: '<svg class="ix" viewBox="0 0 24 24" fill="#d39b19"><path d="M7 3h10v2h3v2a4 4 0 01-4 4h-.3A5 5 0 0113 13.9V17h3v3H8v-3h3v-3.1A5 5 0 018.3 11H8a4 4 0 01-4-4V5h3zm-1 4a2 2 0 002 2V7zm12 0h-2v2a2 2 0 002-2z"/></svg>',
  trophyGray: '<svg class="ix" viewBox="0 0 24 24" fill="#8a8c93"><path d="M7 3h10v2h3v2a4 4 0 01-4 4h-.3A5 5 0 0113 13.9V17h3v3H8v-3h3v-3.1A5 5 0 018.3 11H8a4 4 0 01-4-4V5h3zm-1 4a2 2 0 002 2V7zm12 0h-2v2a2 2 0 002-2z"/></svg>',
  trophyBronze: '<svg class="ix" viewBox="0 0 24 24" fill="#b8682c"><path d="M7 3h10v2h3v2a4 4 0 01-4 4h-.3A5 5 0 0113 13.9V17h3v3H8v-3h3v-3.1A5 5 0 018.3 11H8a4 4 0 01-4-4V5h3zm-1 4a2 2 0 002 2V7zm12 0h-2v2a2 2 0 002-2z"/></svg>',
  star: '<svg class="ix" viewBox="0 0 24 24" fill="#7e8088"><path d="M12 2.8l2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 16.6l-5.4 2.9 1.1-6.1L3.2 9.2l6.1-.8z"/></svg>',
  gift: '<svg viewBox="0 0 24 24" fill="none" stroke="#5a5d66" stroke-width="1.6" stroke-linejoin="round"><rect x="3.5" y="8" width="17" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12M12 8c-1.5-3.5-5-4-5.2-1.8C6.6 8 10 8 12 8zm0 0c1.5-3.5 5-4 5.2-1.8C17.4 8 14 8 12 8z"/></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="#3b3d44" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
  megaphone: '<svg viewBox="0 0 24 24" fill="none" stroke="#3b3d44" stroke-width="1.7" stroke-linejoin="round"><path d="M4 10v4h3l7 4V6L7 10zM17 9.5a3.5 3.5 0 010 5M7 14l1.5 5h2.5L10 14"/></svg>',
  check: '<svg viewBox="0 0 24 24" fill="none" stroke="#1670ef" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
  starWhite: '<svg viewBox="0 0 24 24" width="17" height="17" fill="#fff"><path d="M12 2.8l2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 16.6l-5.4 2.9 1.1-6.1L3.2 9.2l6.1-.8z"/></svg>',
  medal(kind) {
    const pal = {
      gold: ['#f9d65c', '#e2a91f', '#b97f0d', '#f4b733', '#d18e14'],
      silver: ['#e8eaee', '#b9bdc6', '#8d929c', '#c9ccd3', '#a3a7b0'],
      bronze: ['#f0b07a', '#c97a3e', '#9b5527', '#dd8d4f', '#b56a33'],
    }[kind];
    return `<svg class="medal" viewBox="0 0 60 66"><path d="M15 38l-7 24 9-4 5 7 6-20z" fill="${pal[3]}"/><path d="M45 38l7 24-9-4-5 7-6-20z" fill="${pal[4]}"/><circle cx="30" cy="27" r="22" fill="${pal[1]}"/><circle cx="30" cy="27" r="18" fill="${pal[0]}" stroke="${pal[2]}" stroke-width="1.2"/><path d="M30 15.5l3.4 7 7.7 1-5.6 5.3 1.4 7.6L30 32.8l-6.9 3.6 1.4-7.6-5.6-5.3 7.7-1z" fill="${pal[2]}" opacity=".85"/></svg>`;
  },
  seal: `<svg viewBox="0 0 160 140" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="sg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff1b3"/><stop offset=".45" stop-color="#f1c14e"/><stop offset="1" stop-color="#c58a17"/></linearGradient><linearGradient id="sr" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f6cf63"/><stop offset="1" stop-color="#b7800f"/></linearGradient></defs><path d="M10 88l22-12 4 24-22 6 8-10z" fill="#c38d1a"/><path d="M150 88l-22-12-4 24 22 6-8-10z" fill="#c38d1a"/>${Array.from({ length: 24 }, (_, i) => { const a = (i / 24) * Math.PI * 2; return `<circle cx="${80 + Math.cos(a) * 52}" cy="${60 + Math.sin(a) * 52}" r="9" fill="url(#sg)"/>`; }).join('')}<circle cx="80" cy="60" r="52" fill="url(#sg)"/><circle cx="80" cy="60" r="43" fill="#f7dd8a" stroke="#c9921d" stroke-width="2.5"/><path d="M20 76h120l-6 14 6 14H20l6-14z" fill="url(#sr)" stroke="#a46f0a" stroke-width="1.5"/><path d="M80 24l3 6.3 7 .9-5.1 4.8 1.3 6.8L80 39.6l-6.2 3.2 1.3-6.8L70 31.2l7-.9z" fill="#8a5a07"/><text x="80" y="64" text-anchor="middle" font-family="Georgia,serif" font-size="17" font-weight="700" fill="#3a2405">First</text><text x="80" y="95" text-anchor="middle" font-family="Georgia,serif" font-size="17.5" font-weight="700" fill="#3a2405">Discovery</text></svg>`,
  ribbon: '<svg viewBox="0 0 620 100" preserveAspectRatio="none"><defs><linearGradient id="rg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fbe7a6"/><stop offset=".5" stop-color="#efc65a"/><stop offset="1" stop-color="#d9a336"/></linearGradient><linearGradient id="rg2" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e2ad3d"/><stop offset="1" stop-color="#b98318"/></linearGradient></defs><path d="M0 30h70v62H0l22-31z" fill="url(#rg2)"/><path d="M620 30h-70v62h70l-22-31z" fill="url(#rg2)"/><path d="M50 82l20 10V80z" fill="#9c6c10"/><path d="M570 82l-20 10V80z" fill="#9c6c10"/><rect x="50" y="10" width="520" height="72" rx="4" fill="url(#rg)" stroke="#c79227" stroke-width="2"/><path d="M84 46l3.5-9 3.5 9 9 3.5-9 3.5-3.5 9-3.5-9-9-3.5zM536 46l3.5-9 3.5 9 9 3.5-9 3.5-3.5 9-3.5-9-9-3.5z" fill="#fff6d6"/></svg>',
};
