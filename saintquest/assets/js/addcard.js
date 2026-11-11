// Add: hold a Saint Quest card up to the camera (QR), upload a photo of it,
// or type the code printed at the bottom.
import * as api from './api.js';
import { el, openModal, toast, sparkle, thumb } from './ui.js';
import { state, def } from './state.js';
import { openSaint } from './saintview.js';
import { openPrintCards } from './extras.js';

let jsqrPromise = null;
export function loadJsQR() {
  if (window.jsQR) return Promise.resolve(window.jsQR);
  if (!jsqrPromise) {
    jsqrPromise = new Promise((res, rej) => {
      const s = document.createElement('script');
      s.src = (window.SQ_BASE || '') + '/assets/vendor/jsQR.min.js';
      s.onload = () => res(window.jsQR);
      s.onerror = () => rej(new Error('QR reader failed to load'));
      document.head.append(s);
    });
  }
  return jsqrPromise;
}

const CODE_RE = /SQ[-\s]?[0-9A-Z]{4}[-\s]?[0-9A-Z]{4}[-\s]?[0-9A-Z]{4}/i;
export function extractCode(text) {
  if (!text) return null;
  const m = String(text).toUpperCase().match(CODE_RE);
  return m ? m[0] : null;
}

/** Decode a QR code from an ImageData, trying normal then inverted colours. */
export async function decodeImageData(img) {
  const jsQR = await loadJsQR();
  const r = jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
  return r ? r.data : null;
}

export async function decodeFile(file) {
  const bmp = await createImageBitmap(file);
  for (const max of [1200, 800, 1800]) {
    const scale = Math.min(1, max / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas');
    c.width = Math.round(bmp.width * scale);
    c.height = Math.round(bmp.height * scale);
    const x = c.getContext('2d', { willReadFrequently: true });
    x.drawImage(bmp, 0, 0, c.width, c.height);
    const data = await decodeImageData(x.getImageData(0, 0, c.width, c.height));
    if (data) return data;
  }
  return null;
}

export function openAdd(prefill = '') {
  let stream = null, raf = null, busy = false;
  const stop = () => { if (raf) cancelAnimationFrame(raf); raf = null; if (stream) stream.getTracks().forEach((t) => t.stop()); stream = null; };
  const wrap = el('div');
  const modal = openModal(wrap, { label: 'Add a saint card', onClose: stop });
  const status = el('p.err', { role: 'status' });
  const result = el('div');
  let mode = prefill ? 'type' : 'camera';

  async function submit(raw) {
    if (busy) return;
    const code = extractCode(raw) || raw;
    busy = true;
    status.textContent = '';
    result.replaceChildren(el('p.muted', { text: 'Checking card ' + code + '…' }));
    try {
      const r = await api.post('cards/scan', { code });
      stop();
      await state.refresh();
      showResult(r);
    } catch (e) {
      result.replaceChildren();
      status.textContent = e.message;
      busy = false;
      if (mode === 'camera' && stream) setTimeout(() => { busy = false; }, 1500);
    }
  }

  function showResult(r) {
    const d = def(r.key);
    const pic = d ? el('img', { src: thumb(r.key), alt: '', style: { width: '160px', height: '160px', objectFit: 'contain' } }) : null;
    let body;
    if (r.result === 'added') {
      sparkle(innerWidth / 2, innerHeight / 2);
      body = el('div', { style: { textAlign: 'center' } }, pic, el('h2', { text: '🎉 ' + r.message }), el('div.row', { style: { justifyContent: 'center' } },
        el('button.btn.blue', { type: 'button', text: 'See my saint', on: { click: () => { modal.close(); openSaint(r.saintId); } } }),
        el('button.btn', { type: 'button', text: 'Add another card', on: { click: () => { busy = false; render(); } } })));
    } else if (r.result === 'already-yours') {
      body = el('div', { style: { textAlign: 'center' } }, pic, el('h2', { text: '👍 ' + r.message }), el('p.muted', { text: 'Scanning your own card again never changes anything.' }),
        el('button.btn', { type: 'button', text: 'Add another card', on: { click: () => { busy = false; render(); } } }));
    } else {
      body = el('div', { style: { textAlign: 'center' } }, pic, el('h2', { text: '⏳ Card check started' }),
        el('p', { text: r.message }),
        el('p.question', { text: 'What happens now: the player who added this card first will be asked “Did you trade this card?”. If they say yes, the card and ' + (d ? d.name : 'the saint') + ' move to you. If they say no, they must send a photo of the card, and the card stays frozen until one of you gives it up. Check the 🔔 for updates.' }),
        el('button.btn', { type: 'button', text: 'OK', on: { click: () => modal.close() } }));
    }
    wrap.replaceChildren(el('h2', { text: 'Add a saint card' }), body);
  }

  function render() {
    stop();
    status.textContent = '';
    result.replaceChildren();
    const tab = (id, label) => el('button.btn' + (mode === id ? '.on' : ''), { type: 'button', text: label, 'data-mode': id, on: { click: () => { mode = id; render(); } } });
    const pane = el('div');
    wrap.replaceChildren(el('h2', { text: '＋ Add a saint card' }),
      el('p.muted', {}, 'Hold up a Saint Quest card so its QR code is inside the box, or type the code printed at the bottom. No cards yet? ',
        el('a', { href: '#', text: 'Print some saint cards', on: { click: (e) => { e.preventDefault(); modal.close(); openPrintCards(); } } }), '.'),
      el('div.tabs', {}, tab('camera', '📷 Camera'), tab('photo', '🖼️ Photo of card'), tab('type', '⌨️ Type code')), pane, status, result);
    if (mode === 'camera') cameraPane(pane);
    else if (mode === 'photo') photoPane(pane);
    else typePane(pane);
  }

  async function cameraPane(pane) {
    const video = el('video', { playsinline: true, muted: true, autoplay: true });
    video.setAttribute('playsinline', '');
    pane.append(el('div.scan-box', {}, video, el('div.frame')), el('p.muted', { text: 'Asking for your camera… When the browser asks, tap Allow.' }));
    if (!navigator.mediaDevices?.getUserMedia) {
      pane.lastChild.textContent = 'This browser cannot use the camera here. Use “Photo of card” or “Type code” instead.';
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } }, audio: false });
    } catch (e) {
      pane.lastChild.textContent = 'The camera is blocked or missing (' + (e.name || 'error') + '). Use “Photo of card” or “Type code” instead.';
      return;
    }
    video.srcObject = stream;
    await video.play().catch(() => {});
    pane.lastChild.textContent = 'Looking for a card…';
    await loadJsQR();
    const c = document.createElement('canvas');
    const x = c.getContext('2d', { willReadFrequently: true });
    let last = 0;
    const loop = async (t) => {
      if (!stream) return;
      raf = requestAnimationFrame(loop);
      if (busy || t - last < 160 || video.readyState < 2) return;
      last = t;
      const scale = Math.min(1, 720 / Math.max(video.videoWidth, video.videoHeight));
      c.width = Math.round(video.videoWidth * scale);
      c.height = Math.round(video.videoHeight * scale);
      x.drawImage(video, 0, 0, c.width, c.height);
      const data = window.jsQR(x.getImageData(0, 0, c.width, c.height).data, c.width, c.height, { inversionAttempts: 'dontInvert' });
      const code = data && extractCode(data.data);
      if (code) { pane.lastChild.textContent = 'Found ' + code + '!'; submit(code); }
    };
    raf = requestAnimationFrame(loop);
  }

  function photoPane(pane) {
    const input = el('input#card-photo', { type: 'file', accept: 'image/*', capture: 'environment' });
    const prev = el('div');
    input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return;
      prev.replaceChildren(el('img', { src: URL.createObjectURL(f), alt: 'Card photo', style: { maxWidth: '100%', maxHeight: '220px', borderRadius: '12px' } }));
      status.textContent = '';
      try {
        const data = await decodeFile(f);
        const code = extractCode(data);
        if (code) submit(code); else status.textContent = 'No QR code found in that picture. Try a closer, sharper photo, or type the code.';
      } catch (e) { status.textContent = 'Could not read that picture: ' + e.message; }
    });
    pane.append(el('label.btn.blue', { for: 'card-photo', text: 'Choose or take a photo of the card' }), input, prev);
    input.style.display = 'none';
  }

  function typePane(pane) {
    const input = el('input.input.code-input#card-code', { value: prefill || '', placeholder: 'SQ-XXXX-XXXX-XXXX', maxlength: 20, autocomplete: 'off', autocapitalize: 'characters', spellcheck: false, 'aria-label': 'Card code' });
    const go = el('button.btn.green#card-submit', { type: 'submit', text: 'Add card' });
    pane.append(el('form', { on: { submit: (e) => { e.preventDefault(); submit(input.value); } } },
      el('label.field', { for: 'card-code', text: 'Code at the bottom of the card' }), input, el('div.row', { style: { marginTop: '10px' } }, go)));
    setTimeout(() => input.focus(), 50);
  }

  render();
  return modal;
}
