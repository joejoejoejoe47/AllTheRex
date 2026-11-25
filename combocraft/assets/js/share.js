// Instagram-story style share card (mockup 04): DOM preview + 1080x1920 PNG.
import { esc, iconUrl, artHtml, svg } from './fx.js?v=11';

export function storyHtml(el, { base, first, small = false } = {}) {
  const [a, b] = el.recipe || [null, null];
  const words = el.name.split(' ');
  // Mockup 04 makes the longest word of a long name pop.
  let resHtml = esc(el.name);
  if (words.length >= 3) {
    let li = 0;
    words.forEach((w, i) => { if (w.length > words[li].length) li = i; });
    resHtml = words.map((w, i) => (i === li ? `<span class="hl">${esc(w)}</span>` : esc(w))).join(' ');
  }
  return `<div class="story">
    ${first ? `<div class="seal">${svg.seal}</div>` : ''}
    ${a ? `<div class="a">${esc(a.name)}</div><div class="plusop">+</div><div class="b">${esc(b.name)}</div><div class="eq2">=</div>` : '<div class="a" style="margin-top:90px">&nbsp;</div>'}
    <div class="res${el.name.length > 34 ? ' long' : ''}">${resHtml}</div>
    <div class="pic">${artHtml(el.art === 'boyfriend' ? { ...el, art: 'bfguy' } : el, base)}</div>
    <button class="shareb" data-act="share">${svg.share}Share</button>
    <div class="foot"><img src="${base}assets/logo/logo.png" alt="ComboCraft"></div>
  </div>`;
}

function loadImg(src) {
  return new Promise((res) => {
    if (!src) return res(null);
    const im = new Image();
    im.crossOrigin = 'anonymous';
    im.onload = () => res(im);
    im.onerror = () => res(null);
    im.src = src;
  });
}

function fitFont(c, text, weight, family, max, maxW) {
  let size = max;
  do {
    c.font = `${weight} ${size}px ${family}`;
    if (c.measureText(text).width <= maxW) break;
    size -= 4;
  } while (size > 40);
  return size;
}

function wrap(c, text, maxW) {
  const words = text.split(' ');
  const lines = [];
  let line = '';
  for (const w of words) {
    const t = line ? line + ' ' + w : w;
    if (c.measureText(t).width > maxW && line) { lines.push(line); line = w; } else line = t;
  }
  if (line) lines.push(line);
  return lines;
}

export async function storyPng(el, { base, first }) {
  const W = 1080, H = 1920;
  const cv = document.createElement('canvas');
  cv.width = W; cv.height = H;
  const c = cv.getContext('2d');
  try { await Promise.all([document.fonts.load('700 120px Fredoka'), document.fonts.load('600 60px Fredoka')]); } catch {}
  const g = c.createLinearGradient(0, 0, W * 0.35, H);
  g.addColorStop(0, '#c9b2f1'); g.addColorStop(0.42, '#e2c4ec'); g.addColorStop(0.78, '#f8c9da'); g.addColorStop(1, '#fbd0dc');
  c.fillStyle = g; c.fillRect(0, 0, W, H);
  // soft glow
  const rg = c.createRadialGradient(W * 0.5, H * 0.75, 50, W * 0.5, H * 0.75, 700);
  rg.addColorStop(0, 'rgba(255,255,255,.35)'); rg.addColorStop(1, 'rgba(255,255,255,0)');
  c.fillStyle = rg; c.fillRect(0, 0, W, H);

  const F = 'Fredoka, Inter, sans-serif';
  c.textAlign = 'center'; c.textBaseline = 'alphabetic';
  let y = 120;
  const [a, b] = el.recipe || [null, null];
  if (a) {
    let s = fitFont(c, a.name, 700, F, 200, first ? 620 : 900);
    c.fillStyle = '#3a237d'; c.font = `700 ${s}px ${F}`; y += s * 0.95; c.fillText(a.name, W / 2, y);
    c.fillStyle = '#6b2fc0'; c.font = `700 130px ${F}`; y += 120; c.fillText('+', W / 2, y);
    s = fitFont(c, b.name, 700, F, 190, 920);
    c.fillStyle = '#ee5b84'; c.font = `700 ${s}px ${F}`; y += s * 0.95; c.fillText(b.name, W / 2, y);
    c.fillStyle = '#3a237d'; c.font = `800 130px ${F}`; y += 112; c.fillText('=', W / 2, y);
  } else {
    y += 120;
  }
  let rs = 110, lines;
  do { c.font = `700 ${rs}px ${F}`; lines = wrap(c, el.name, 900); rs -= 6; } while ((lines.length > 4 || lines.some((l) => c.measureText(l).width > 940)) && rs > 50);
  rs += 6;
  c.fillStyle = '#20175a'; c.font = `700 ${rs}px ${F}`;
  y += 30;
  for (const l of lines) { y += rs * 1.04; c.fillText(l, W / 2, y); }

  // illustration
  const top = Math.max(y + 40, 1060), bottom = 1730;
  const artSrc = el.art ? `${base}assets/art/${el.art === 'boyfriend' ? 'bfguy' : el.art}.webp` : iconUrl(el.emoji);
  const im = await loadImg(artSrc);
  if (im) {
    const boxW = el.art ? 900 : 520, boxH = el.art ? bottom - top : Math.min(520, bottom - top);
    const sc = Math.min(boxW / im.width, boxH / im.height);
    const w = im.width * sc, h = im.height * sc;
    c.save(); c.shadowColor = 'rgba(70,30,120,.25)'; c.shadowBlur = 40; c.shadowOffsetY = 18;
    c.drawImage(im, (W - w) / 2, el.art ? bottom - h : top + (bottom - top - h) / 2, w, h);
    c.restore();
  } else {
    c.font = `400 400px "Noto Color Emoji","Apple Color Emoji","Segoe UI Emoji",sans-serif`;
    c.fillText(el.emoji, W / 2, bottom - 120);
  }
  if (first) {
    const seal = await loadImg('data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg.seal));
    if (seal) c.drawImage(seal, W - 360, 26, 330, 289);
  }
  // footer
  const logo = await loadImg(`${base}assets/logo/logo.png`);
  if (logo) {
    const lw = 400, lh = (logo.height / logo.width) * lw;
    c.drawImage(logo, (W - lw) / 2, H - 150, lw, lh);
  }
  c.fillStyle = 'rgba(40,25,90,.7)'; c.font = `600 34px ${F}`;
  c.fillText('alltherex.com/combocraft', W / 2, H - 40);
  return new Promise((res) => cv.toBlob((bl) => res(bl), 'image/png'));
}
