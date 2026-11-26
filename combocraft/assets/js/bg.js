// Double-click the empty background to pick a bright scene or upload your own.
import { esc } from './fx.js?v=43';

const BGS = [
  { id: 'stadium', name: 'Stadium', file: 'assets/art/arena-bg.webp', thumb: 'assets/art/bg-stadium-thumb.webp' },
  { id: 'beach', name: 'Sunny Beach', file: 'assets/art/bg-beach.webp', thumb: 'assets/art/bg-beach-thumb.webp' },
  { id: 'candy', name: 'Candy Land', file: 'assets/art/bg-candy.webp', thumb: 'assets/art/bg-candy-thumb.webp' },
  { id: 'space', name: 'Space Neon', file: 'assets/art/bg-space.webp', thumb: 'assets/art/bg-space-thumb.webp' },
  { id: 'jungle', name: 'Jungle', file: 'assets/art/bg-jungle.webp', thumb: 'assets/art/bg-jungle-thumb.webp' },
  { id: 'snow', name: 'Snowy Rink', file: 'assets/art/bg-snow.webp', thumb: 'assets/art/bg-snow-thumb.webp' },
  { id: 'coral', name: 'Coral Reef', file: 'assets/art/bg-coral.webp', thumb: 'assets/art/bg-coral-thumb.webp' },
];

let ctx = { api: null, toast: null, state: null, BASE: '/' };
let lastTap = 0;

export function initBg(c) {
  ctx = c;
  applyFromStorage();
  bindBgClicks();
  maybeTip();
}

export function syncPrefs(prefs) {
  if (!prefs) return;
  if (prefs.bg && prefs.bg !== 'custom') {
    applyBg(prefs.bg, prefs.url || null, false);
    return;
  }
  if (prefs.bg === 'custom' && prefs.url) {
    applyBg('custom', absUrl(prefs.url), false);
  }
}

export function applyFromStorage() {
  let id = 'stadium';
  let custom = '';
  try {
    id = localStorage.getItem('cc_bg') || 'stadium';
    custom = localStorage.getItem('cc_bg_custom') || '';
  } catch {}
  if (id === 'custom' && custom) applyBg('custom', custom, false);
  else applyBg(id, null, false);
}

function absUrl(u) {
  if (!u) return '';
  if (/^(https?:|data:|blob:)/i.test(u)) return u;
  const base = ctx.BASE || (document.querySelector('base')?.getAttribute('href')) || '/';
  return new URL(u.replace(/^\//, ''), new URL(base, location.href)).href;
}

export function applyBg(id, customUrl, persist) {
  const builtin = BGS.some((b) => b.id === id);
  const bg = builtin ? id : (id === 'custom' && customUrl ? 'custom' : 'stadium');
  document.documentElement.setAttribute('data-bg', bg);
  if (bg === 'custom' && customUrl) {
    document.documentElement.style.setProperty('--cc-bg-image', 'url(' + JSON.stringify(customUrl) + ')');
  } else {
    document.documentElement.style.removeProperty('--cc-bg-image');
  }
  try {
    localStorage.setItem('cc_bg', bg);
    if (bg === 'custom' && customUrl) localStorage.setItem('cc_bg_custom', customUrl);
    else localStorage.removeItem('cc_bg_custom');
  } catch {}
  if (ctx.state) ctx.state.prefs = { ...(ctx.state.prefs || {}), bg, url: bg === 'custom' ? customUrl : null };
  if (persist && ctx.api && bg !== 'custom') {
    ctx.api('prefs', { bg }).catch(() => {});
  }
}

function bindBgClicks() {
  if (window.__ccBgBound) return;
  window.__ccBgBound = true;
  document.addEventListener('dblclick', (e) => {
    if (isChrome(e.target)) return;
    e.preventDefault();
    openBgPicker();
  });
  document.addEventListener('pointerup', (e) => {
    if (e.pointerType !== 'touch') return;
    if (isChrome(e.target)) { lastTap = 0; return; }
    const now = Date.now();
    if (now - lastTap < 340) {
      lastTap = 0;
      e.preventDefault();
      openBgPicker();
    } else lastTap = now;
  }, { passive: false });
}

function isChrome(el) {
  if (!(el instanceof Element)) return false;
  return !!el.closest('button,a,input,textarea,select,label,video,audio,.el,.modal,.overlay,.menu,.sheet,.combine,.daily-bar,.world-bar,.topbar,.tabs,.cc-party-bar,.play-activity,.rl-panel,.rl-mode,.slotp,.eq-book,.ft-stage,.bg-picker,.hero-inner,.hero-links,.lb-row,.gcard,[contenteditable],#toast');
}

function maybeTip() {
  try {
    if (localStorage.getItem('cc_bg_tip')) return;
    localStorage.setItem('cc_bg_tip', '1');
  } catch { return; }
  setTimeout(() => {
    if (ctx.toast) ctx.toast('Tip: double-click the background to pick a new scene or upload your own.');
  }, 1600);
}

export function openBgPicker() {
  document.querySelectorAll('.overlay.cc-bg').forEach((e) => e.remove());
  const cur = (ctx.state?.prefs?.bg) || (document.documentElement.getAttribute('data-bg') || 'stadium');
  const customUrl = ctx.state?.prefs?.url || (function () { try { return localStorage.getItem('cc_bg_custom') || ''; } catch { return ''; } })();
  const o = document.createElement('div');
  o.className = 'overlay cc-bg';
  o.innerHTML = `<div class="modal bg-picker" role="dialog" aria-label="Backgrounds">
    <button class="x" type="button" aria-label="Close">×</button>
    <h3>Pick a background</h3>
    <p class="bg-lead">A bright scene behind every page. Double-click an empty area to open this again.</p>
    <div class="bg-grid">
      ${BGS.map((b) => `<button type="button" class="bg-card${b.id === cur ? ' on' : ''}" data-bg="${b.id}">
        <img src="${b.thumb || b.file}" alt="${esc(b.name)}" />
        <span>${esc(b.name)}</span>
      </button>`).join('')}
      <label class="bg-card bg-upload${cur === 'custom' ? ' on' : ''}">
        ${customUrl ? `<img src="${esc(customUrl)}" alt="Your image" />` : `<span class="bg-upload-ico" aria-hidden="true">⬆</span>`}
        <span>Upload your own</span>
        <input type="file" id="bgFile" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" hidden />
      </label>
    </div>
    <div class="bg-acts">
      <button type="button" class="mini-btn" id="bgReset">Reset to default</button>
    </div>
  </div>`;
  document.body.appendChild(o);
  const close = () => o.remove();
  o.querySelector('button.x').onclick = close;
  o.addEventListener('click', (e) => {
    if (e.target === o) close();
    const c = e.target.closest('[data-bg]');
    if (!c) return;
    applyBg(c.dataset.bg, null, true);
    o.querySelectorAll('.bg-card').forEach((x) => x.classList.toggle('on', x === c));
    if (ctx.toast) ctx.toast('Background set to ' + (BGS.find((b) => b.id === c.dataset.bg)?.name || c.dataset.bg) + '.');
    setTimeout(close, 220);
  });
  o.querySelector('#bgReset').onclick = async () => {
    applyBg('stadium', null, false);
    try { await ctx.api('prefs', { bg: 'stadium', reset: true }); } catch {}
    if (ctx.toast) ctx.toast('Background reset to Stadium.');
    close();
  };
  const file = o.querySelector('#bgFile');
  if (file) file.onchange = () => { if (file.files?.[0]) uploadBg(file.files[0], o, close); };
}

async function uploadBg(file, overlay, close) {
  if (file.size > 5 * 1024 * 1024) {
    if (ctx.toast) ctx.toast('Please keep the image under 5 MB.');
    return;
  }
  const okType = /image\/(jpeg|png|webp)/i.test(file.type) || /\.(jpe?g|png|webp)$/i.test(file.name);
  if (!okType) {
    if (ctx.toast) ctx.toast('Please use a JPG, PNG or WebP image.');
    return;
  }
  if (ctx.toast) ctx.toast('Uploading your background…');
  try {
    const fd = new FormData();
    fd.append('file', file);
    const r = await fetch((ctx.BASE || '') + 'api/prefs/bg', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CC': '1' },
    });
    const d = await r.json().catch(() => null);
    if (!r.ok || !d || d.ok === false || !d.url) throw new Error((d && d.error) || 'Upload failed.');
    const url = absUrl(d.url);
    applyBg('custom', url, false);
    if (ctx.toast) ctx.toast('Your background is on!');
    if (close) setTimeout(close, 240);
    return;
  } catch (e) {
    // Guest / offline fallback: resize in the browser and keep it in localStorage.
    try {
      const dataUrl = await shrinkLocal(file);
      applyBg('custom', dataUrl, false);
      if (ctx.toast) ctx.toast('Saved this background on this device.');
      if (close) setTimeout(close, 240);
    } catch {
      if (ctx.toast) ctx.toast(e.message || 'Could not use that image.');
    }
  }
}

async function shrinkLocal(file) {
  const bmp = await createImageBitmap(file);
  const edge = Math.max(bmp.width, bmp.height);
  const scale = edge > 1920 ? 1920 / edge : 1;
  const w = Math.max(1, Math.round(bmp.width * scale));
  const h = Math.max(1, Math.round(bmp.height * scale));
  const canvas = document.createElement('canvas');
  canvas.width = w; canvas.height = h;
  const g = canvas.getContext('2d');
  g.drawImage(bmp, 0, 0, w, h);
  let url = canvas.toDataURL('image/jpeg', 0.82);
  if (url.length > 1.6e6) url = canvas.toDataURL('image/jpeg', 0.65);
  if (url.length > 2.4e6) throw new Error('That image is too big to keep on this device. Sign in and try a smaller photo.');
  return url;
}
