// Config-driven house ads + play-page rail + timed video popup.
// Add more entries to HOUSE_ADS. A real network tag can be dropped into
// the [data-ad-slot] placeholder in the right-hand panel.

import { esc } from './fx.js?v=32';

/** @typedef {{ id:string, title:string, kicker?:string, blurb:string, cta:string, href:string, external?:boolean, youtube?:string, start?:number, image?:string }} Ad */

/** @type {Ad[]} */
export const HOUSE_ADS = [
  {
    id: 'morse-chess',
    title: 'Morse Chess',
    kicker: 'Sponsored',
    blurb: 'Chess with Morse code. The official AllTheRex game.',
    cta: 'Play Morse Chess',
    href: 'https://alltherex.com/chess',
    external: true,
    youtube: 'O7SAFS7Ef1U',
    start: 2,
  },
  {
    id: 'morse-legendary',
    title: 'Morse Chess Legendary',
    kicker: 'Sponsored',
    blurb: 'The deluxe board, portraits and Legendary seats.',
    cta: 'Play Legendary',
    href: 'https://alltherex.com/chessLegendary/',
    external: true,
  },
  {
    id: 'combo-clash',
    title: 'Combo Clash',
    kicker: 'Sponsored',
    blurb: 'Best-of-3 arenas, parties and rematches — inside ComboCraft.',
    cta: 'Enter Clash',
    href: 'clash',
  },
  {
    id: 'premium',
    title: 'ComboCraft Premium',
    kicker: 'Sponsored',
    blurb: 'Unlimited combinations, no ads, exclusive themes. Coming soon.',
    cta: 'See Premium',
    href: 'pricing',
  },
];

const STORAGE = 'cc_ads_v1';
const INTERVAL_MS = 20 * 60 * 1000;
const CLOSE_SEC = 10;
const TICK = 1000;

let ctx = { state: null, go: null };
let ticking = false;
let lastTick = 0;
let timer = 0;
let popupOpen = false;
let pending = false;

function isPremium() {
  return !!ctx.state?.me?.premium;
}

function loadStore() {
  try {
    const d = JSON.parse(localStorage.getItem(STORAGE) || 'null');
    if (d && typeof d.activeMs === 'number') {
      return { activeMs: d.activeMs, lastPopupActiveMs: d.lastPopupActiveMs || 0 };
    }
  } catch {}
  return { activeMs: 0, lastPopupActiveMs: 0 };
}

function saveStore(s) {
  try { localStorage.setItem(STORAGE, JSON.stringify(s)); } catch {}
}

/** True when a popup would cover a match, countdown, or daily win animation. */
export function isAdBusy() {
  if (document.querySelector('.rl-arena, .clash-lo-head, #loGrid, .rl-results')) return true;
  if (document.querySelector('.cc-invite, .cci-ring')) return true;
  if (document.querySelector('.daily-pop, #dailyBar.daily-pop')) return true;
  if (document.querySelector('.clash-wrap .thinking')) return true;
  if (document.querySelector('.act-card.act-search')) return true;
  return false;
}

function ytThumb(id) {
  return 'https://i.ytimg.com/vi/' + encodeURIComponent(id) + '/hqdefault.jpg';
}

function ytEmbed(id, start = 0, { autoplay = false } = {}) {
  const params = new URLSearchParams({
    autoplay: autoplay ? '1' : '0',
    mute: '1',
    start: String(start || 0),
    rel: '0',
    modestbranding: '1',
    playsinline: '1',
    enablejsapi: '1',
    origin: location.origin,
  });
  return 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?' + params.toString();
}

function adHref(ad) {
  if (ad.external) return ad.href;
  const base = document.querySelector('base')?.getAttribute('href') || '/';
  return base + ad.href.replace(/^\//, '');
}

function cardHtml(ad, featured = false) {
  const media = ad.youtube
    ? `<div class="ad-media"><img src="${esc(ytThumb(ad.youtube))}" alt="" width="480" height="270" /><span class="ad-play" aria-hidden="true">▶</span></div>`
    : (ad.image ? `<div class="ad-media"><img src="${esc(ad.image)}" alt="" /></div>` : '');
  const target = ad.external ? ' target="_blank" rel="noopener"' : ' data-link';
  return `<article class="ad-card${featured ? ' featured' : ''}" data-ad="${esc(ad.id)}">
    <div class="ad-kicker">${esc(ad.kicker || 'Sponsored')}</div>
    ${media}
    <h3>${esc(ad.title)}</h3>
    <p>${esc(ad.blurb)}</p>
    <a class="mini-btn ad-cta" href="${esc(adHref(ad))}"${target}>${esc(ad.cta)}</a>
  </article>`;
}

export function renderAdRail(root) {
  if (!root) return;
  if (isPremium()) { root.hidden = true; root.innerHTML = ''; return; }
  root.hidden = false;
  const [first, ...rest] = HOUSE_ADS;
  root.innerHTML = `
    <div class="act-head">
      <h2>Sponsored</h2>
      <span class="ad-mark">Ads</span>
    </div>
    <div class="ad-rail-list">
      ${first ? cardHtml(first, true) : ''}
      ${rest.map((a) => cardHtml(a)).join('')}
      <div class="ad-network-slot" data-ad-slot="cc-play-right" aria-label="Advertisement">
        <div class="ad-kicker">Ad slot</div>
        <p>Reserved for a real ad network tag.</p>
      </div>
    </div>`;
  root.querySelectorAll('a[data-link]').forEach((a) => {
    a.addEventListener('click', (e) => {
      if (e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault();
      const href = a.getAttribute('href') || '';
      const path = href.replace(/^.*\/combocraft\/?/, '').replace(/^\//, '');
      if (ctx.go) ctx.go(path || 'play');
    });
  });
  // Featured Morse Chess card: same video as the popup, opens the popup (or the game if busy).
  const feat = root.querySelector('.ad-card.featured .ad-media, .ad-card.featured h3');
  if (feat && first?.youtube) {
    root.querySelector('.ad-card.featured .ad-media')?.addEventListener('click', () => showPopup(first, { fromRail: true }));
  }
}

function postYt(iframe, func, args = []) {
  try {
    iframe.contentWindow?.postMessage(JSON.stringify({ event: 'command', func, args }), '*');
  } catch {}
}

export function showPopup(ad = HOUSE_ADS[0], { fromRail = false } = {}) {
  if (!ad || isPremium() || popupOpen) return false;
  if (!fromRail && isAdBusy()) return false;
  popupOpen = true;
  pending = false;

  const ov = document.createElement('div');
  ov.className = 'ad-pop-ov';
  ov.setAttribute('role', 'dialog');
  ov.setAttribute('aria-modal', 'true');
  ov.setAttribute('aria-labelledby', 'adPopTitle');
  const start = ad.start || 0;
  const iframeSrc = ad.youtube ? ytEmbed(ad.youtube, start, { autoplay: true }) : '';
  ov.innerHTML = `<div class="ad-pop">
    <div class="ad-pop-top">
      <span class="ad-kicker">${esc(ad.kicker || 'Sponsored')}</span>
      <button type="button" class="ad-pop-x" disabled aria-label="Close ad in ${CLOSE_SEC} seconds">Close in ${CLOSE_SEC}</button>
    </div>
    ${iframeSrc ? `<div class="ad-pop-vid">
      <iframe id="adYt" src="${esc(iframeSrc)}" title="${esc(ad.title)}" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
      <button type="button" class="ad-unmute">Unmute</button>
    </div>` : ''}
    <h2 id="adPopTitle">${esc(ad.title)}</h2>
    <p>${esc(ad.blurb)}</p>
    <a class="btn ad-pop-cta" href="${esc(adHref(ad))}"${ad.external ? ' target="_blank" rel="noopener"' : ' data-link'}>${esc(ad.cta)}</a>
  </div>`;
  document.body.appendChild(ov);

  const x = ov.querySelector('.ad-pop-x');
  const un = ov.querySelector('.ad-unmute');
  const iframe = ov.querySelector('#adYt');
  let left = CLOSE_SEC;
  const t = setInterval(() => {
    left -= 1;
    if (left <= 0) {
      clearInterval(t);
      x.disabled = false;
      x.textContent = 'Close';
      x.setAttribute('aria-label', 'Close ad');
      return;
    }
    x.textContent = 'Close in ' + left;
    x.setAttribute('aria-label', 'Close ad in ' + left + ' seconds');
  }, 1000);

  function close() {
    if (x.disabled) return;
    clearInterval(t);
    if (iframe) iframe.src = 'about:blank';
    ov.remove();
    popupOpen = false;
  }
  x.addEventListener('click', close);
  ov.addEventListener('click', (e) => { if (e.target === ov && !x.disabled) close(); });
  ov.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !x.disabled) close(); });

  if (un && iframe) {
    un.addEventListener('click', () => {
      postYt(iframe, 'unMute');
      postYt(iframe, 'setVolume', [100]);
      postYt(iframe, 'playVideo');
      un.hidden = true;
    });
  }
  const cta = ov.querySelector('a[data-link]');
  if (cta) {
    cta.addEventListener('click', (e) => {
      e.preventDefault();
      if (x.disabled) return;
      close();
      if (ctx.go) ctx.go(ad.href);
    });
  }
  x.focus?.();
  return true;
}

function tryPopup() {
  if (popupOpen || isPremium() || document.visibilityState !== 'visible') return;
  const s = loadStore();
  if (s.activeMs - s.lastPopupActiveMs < INTERVAL_MS) return;
  if (isAdBusy()) { pending = true; return; }
  if (showPopup(HOUSE_ADS[0])) {
    s.lastPopupActiveMs = s.activeMs;
    saveStore(s);
    pending = false;
  }
}

function tick() {
  if (document.visibilityState !== 'visible' || isPremium()) {
    lastTick = 0;
    return;
  }
  const now = Date.now();
  if (!lastTick) { lastTick = now; return; }
  const dt = Math.min(now - lastTick, 2000);
  lastTick = now;
  const s = loadStore();
  s.activeMs += dt;
  saveStore(s);
  if (pending || s.activeMs - s.lastPopupActiveMs >= INTERVAL_MS) tryPopup();
}

export function initAds({ state, go } = {}) {
  if (state) ctx.state = state;
  if (go) ctx.go = go;
  if (ticking) return;
  ticking = true;
  lastTick = Date.now();
  timer = setInterval(tick, TICK);
  document.addEventListener('visibilitychange', () => {
    lastTick = document.visibilityState === 'visible' ? Date.now() : 0;
    if (pending) tryPopup();
  });
}

/** Test hook for screenshots. Does not reset the 20-minute timer unless the popup actually opens. */
export function debugShowAd() {
  return showPopup(HOUSE_ADS[0], { fromRail: true });
}

if (typeof window !== 'undefined') {
  window.__ccAds = { show: debugShowAd, busy: isAdBusy, ads: HOUSE_ADS, intervalMs: INTERVAL_MS };
}
