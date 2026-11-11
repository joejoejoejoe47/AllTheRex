// Credits page and the printable saint-card maker.
import * as api from './api.js';
import { el, openModal, toast, thumb } from './ui.js';
import { state, def } from './state.js';

let qrPromise = null;
function loadQR() {
  if (window.qrcode) return Promise.resolve(window.qrcode);
  if (!qrPromise) {
    qrPromise = new Promise((res, rej) => {
      const s = document.createElement('script');
      s.src = (window.SQ_BASE || '') + '/assets/vendor/qrcode.min.js';
      s.onload = () => res(window.qrcode);
      s.onerror = () => rej(new Error('QR maker failed to load'));
      document.head.append(s);
    });
  }
  return qrPromise;
}

export function cardUrl(code) {
  return location.origin + (window.SQ_BASE || '') + '/?card=' + encodeURIComponent(code);
}

export async function qrDataUrl(text) {
  const qrcode = await loadQR();
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  return qr.createDataURL(6, 2);
}

async function cardNode(c) {
  const d = def(c.key);
  const src = await qrDataUrl(cardUrl(c.code));
  return el('div.saint-card', { 'data-code': c.code },
    el('div.sc-name', { text: d.name }), el('div.sc-patron', { text: 'Patron of ' + d.patron }),
    el('img.sc-art', { src: thumb(c.key), alt: '' }),
    el('div.sc-bottom', {}, el('img.sc-qr', { src, alt: 'QR code for ' + c.code }), el('div.sc-code', {}, c.code, el('small', { text: 'Saint Quest · scan with “Add”' }))));
}

export function openPrintCards() {
  if (!state.me) { toast('Log in first to print cards.', true); return; }
  const printable = state.list.filter((s) => ['common', 'uncommon', 'rare'].includes(s.rarity));
  const pickSaint = el('select.input#print-saint', {}, el('option', { value: 'random', text: '🎲 Surprise me (random saint)' }), printable.map((s) => el('option', { value: s.key, text: s.name })));
  const count = el('select.input#print-count', {}, [1, 2, 3, 4, 5, 6].map((n) => el('option', { value: n, text: n + (n === 1 ? ' card' : ' cards') })));
  count.value = '3';
  const sheet = el('div.cards-sheet');
  const msg = el('p.err');
  const area = el('div#print-area', {}, sheet);
  const make = el('button.btn.blue#print-make', { type: 'button', text: '✨ Make cards' });
  const printBtn = el('button.btn#print-go', { type: 'button', text: '🖨️ Print', hidden: true, on: { click: () => window.print() } });
  const show = async (cards) => {
    sheet.replaceChildren(...(await Promise.all(cards.map(cardNode))));
    printBtn.hidden = !cards.length;
  };
  make.addEventListener('click', async () => {
    make.disabled = true;
    msg.textContent = '';
    try {
      const { cards } = await api.post('cards/print', { key: pickSaint.value, count: Number(count.value) });
      await show(cards);
      toast('Your cards are ready to print!');
    } catch (e) { msg.textContent = e.message; }
    make.disabled = false;
  });
  const wrap = el('div', {},
    el('div.no-print', {}, el('h2', { text: '🃏 Print saint cards' }),
      el('p.muted', { text: 'Make real cards with a secret code and QR. Cut them out and give or trade them with friends. Whoever adds a card first with the “Add” button gets the saint. Each code works once, and nobody can guess one.' }),
      el('div.row', {}, pickSaint, count, make, printBtn), msg,
      el('p.muted', { style: { fontSize: '12px' }, text: 'You can make 12 cards a day. Epic and legendary saints come only from the Store.' })),
    area);
  openModal(wrap, { label: 'Print saint cards' });
  api.get('cards/printed').then(({ cards }) => { if (cards.length && !sheet.childElementCount) { show(cards); } }).catch(() => {});
}

export function openCredits() {
  const models = state.list.length ? state.list : [];
  openModal(el('div.credits', {},
    el('h2', { text: '📜 Credits' }),
    el('h3', { text: '3D saint models' }),
    el('p', {}, 'All saints are built from the free ', el('b', { text: 'KayKit Adventurers Character Pack 2.0' }), ' by ', el('a', { href: 'https://kaylousberg.com', target: '_blank', rel: 'noopener', text: 'Kay Lousberg' }),
      ', released under ', el('a', { href: 'https://creativecommons.org/publicdomain/zero/1.0/', target: '_blank', rel: 'noopener', text: 'CC0 1.0 (public domain)' }),
      ' (', el('a', { href: 'https://kaylousberg.itch.io/kaykit-adventurers', target: '_blank', rel: 'noopener', text: 'itch.io page' }), '). Animations: KayKit Rig Medium (same pack, CC0). Saint Quest recolours the robes and adds a golden halo.'),
    el('ul', {}, models.map((s) => el('li', {}, el('b', { text: s.name }), ` — KayKit “${s.model}” model, CC0, by Kay Lousberg`))),
    el('h3', { text: 'Art and code' }),
    el('ul', {},
      el('li', { text: 'Village background: concept art created for Saint Quest (AI-generated).' }),
      el('li', { text: 'Cathedral interior: built for Saint Quest from simple 3D shapes and hand-drawn canvas textures (no outside models).' }),
      el('li', { text: 'Walk-up-the-path video: created for Saint Quest (AI-generated).' }),
      el('li', {}, el('a', { href: 'https://threejs.org', target: '_blank', rel: 'noopener', text: 'three.js' }), ' (MIT) for 3D.'),
      el('li', {}, el('a', { href: 'https://github.com/cozmo/jsQR', target: '_blank', rel: 'noopener', text: 'jsQR' }), ' (Apache-2.0) to read card QR codes.'),
      el('li', {}, el('a', { href: 'https://github.com/kazuhikoarase/qrcode-generator', target: '_blank', rel: 'noopener', text: 'qrcode-generator' }), ' by Kazuhiko Arase (MIT) to print card QR codes.'),
      el('li', { text: 'Fonts: Fredoka and Nunito (SIL Open Font License) via Google Fonts.' })),
    el('p.muted', { text: 'Saint stories are short, kid-friendly summaries. Saint Quest is part of AllTheRex.' })), { label: 'Credits' });
}
