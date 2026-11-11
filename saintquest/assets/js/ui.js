// DOM helpers, modal stack, toasts and celebration sparkles.
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

/** el('div.class#id', {attrs}, children...) — text is always set as text, never HTML. */
export function el(spec, attrs = {}, ...children) {
  const m = spec.match(/^([a-z0-9]+)?((?:[.#][\w-]+)*)$/i);
  const node = document.createElement((m && m[1]) || 'div');
  if (m && m[2]) {
    for (const part of m[2].match(/[.#][\w-]+/g) || []) {
      if (part[0] === '.') node.classList.add(part.slice(1)); else node.id = part.slice(1);
    }
  }
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'on') { for (const [ev, fn] of Object.entries(v)) node.addEventListener(ev, fn); }
    else if (k === 'text') node.textContent = v;
    else if (k === 'style' && typeof v === 'object') Object.assign(node.style, v);
    else if (k in node && typeof v !== 'string') node[k] = v;
    else node.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined || c === false) continue;
    node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return node;
}

let toastTimer = null;
export function toast(msg, bad = false, ms = 3200) {
  const t = $('#toast');
  t.textContent = msg;
  t.classList.toggle('bad', !!bad);
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.hidden = true; }, ms);
}

const stack = [];
export function openModal(content, { narrow = false, onClose = null, label = 'Dialog' } = {}) {
  const close = el('button.modal-close', { type: 'button', 'aria-label': 'Close', text: '✕' });
  const box = el('div.modal' + (narrow ? '.narrow' : ''), { role: 'dialog', 'aria-modal': 'true', 'aria-label': label }, close, content);
  const backdrop = el('div.modal-backdrop', {}, box);
  const entry = { backdrop, onClose, box };
  const doClose = () => closeModal(entry);
  close.addEventListener('click', doClose);
  backdrop.addEventListener('pointerdown', (e) => { if (e.target === backdrop) entry.downOnBackdrop = true; });
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop && entry.downOnBackdrop) doClose(); entry.downOnBackdrop = false; });
  $('#modal-root').append(backdrop);
  stack.push(entry);
  setTimeout(() => (box.querySelector('[autofocus]') || close).focus(), 30);
  entry.close = doClose;
  entry.setContent = (node) => { box.replaceChildren(close, node); };
  return entry;
}
export function closeModal(entry) {
  const i = stack.indexOf(entry);
  if (i < 0) return;
  stack.splice(i, 1);
  entry.backdrop.remove();
  if (entry.onClose) entry.onClose();
}
export function closeAllModals() { while (stack.length) closeModal(stack[stack.length - 1]); }
export function topModal() { return stack[stack.length - 1] || null; }
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && stack.length && !document.querySelector('#battle-root:not([hidden])')) closeModal(stack[stack.length - 1]);
});

export function sparkle(x, y, glyphs = ['✨', '💛', '⭐']) {
  for (let i = 0; i < 9; i++) {
    const s = el('span.confetti', { text: glyphs[i % glyphs.length] });
    s.style.left = (x + (Math.random() - 0.5) * 120) + 'px';
    s.style.top = (y + (Math.random() - 0.5) * 40) + 'px';
    s.style.animationDelay = (i * 0.05) + 's';
    document.body.append(s);
    setTimeout(() => s.remove(), 1900);
  }
}

export const thumb = (key, kind = 'full') => (window.SQ_BASE || '') + `/assets/thumbs/${kind}-${key}.webp`;
export const fmt = (n) => Number(n || 0).toLocaleString('en-US');
export function timeAgo(utc) {
  const t = Date.parse(String(utc).replace(' ', 'T') + 'Z');
  if (!t) return '';
  const s = Math.max(1, Math.round((Date.now() - t) / 1000));
  if (s < 60) return 'just now';
  if (s < 3600) return Math.round(s / 60) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago';
  return Math.round(s / 86400) + ' d ago';
}
