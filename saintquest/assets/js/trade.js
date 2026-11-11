// Trade: a golden plate drops down with buttons on it, the chosen saint
// standing on the plate, and all your saints in a sideways-scrolling row.
import * as api from './api.js';
import { $, el, toast, sparkle, thumb, fmt } from './ui.js';
import { state, def } from './state.js';
import { mountViewer } from './preview.js';
import { openSaint } from './saintview.js';

let viewer = null;
let tab = 'sell';
let selected = null;

export function closeTrade() {
  const d = $('#trade-drop');
  if (viewer) { viewer.dispose(); viewer = null; }
  d.hidden = true;
  d.replaceChildren();
}

export function openTrade(startTab = 'sell') {
  if (!state.me) return;
  tab = startTab;
  if (!selected || !state.me.saints.find((s) => s.id === selected)) {
    const first = state.me.saints.find((s) => s.status === 'ok') || state.me.saints[0];
    selected = first ? first.id : null;
  }
  $('#trade-drop').hidden = false;
  render();
}

function render() {
  const d = $('#trade-drop');
  if (viewer) { viewer.dispose(); viewer = null; }
  const tabBtn = (id, label) => el('button.btn' + (tab === id ? '.on' : ''), { type: 'button', text: label, 'data-tab': id, on: { click: () => { tab = id; render(); } } });
  const buttons = el('div.plate-buttons', {}, tabBtn('sell', '🪙 Sell a saint'), tabBtn('market', '🏪 Market'), tabBtn('mine', '📜 My listings'),
    el('button.btn.ghost', { type: 'button', text: '✕ Close', on: { click: closeTrade } }));
  const body = el('div');
  const plate = el('div.plate', {}, buttons, body);
  d.replaceChildren(plate);
  if (tab === 'sell') renderSell(body, d);
  else renderMarket(body, tab === 'mine');
}

function renderSell(body, d) {
  const saints = state.me.saints;
  const s = saints.find((x) => x.id === selected);
  if (!s) { body.append(el('p', { text: 'You have no saints to trade yet.' })); return; }
  const info = def(s.key);
  const stand = el('div.plate-saint');
  const msg = el('p.err');
  let form;
  if (s.status === 'listed') {
    form = el('div', {}, el('h3', { text: info.name }), el('p', {}, el('span.tag.listed', { text: 'for trade' }), ' Everyone can see this saint on the Market.'),
      el('div.row', {},
        el('button.btn', { type: 'button', text: 'See listing', on: { click: () => openSaint(s.id) } }),
        el('button.btn.red', { type: 'button', text: 'Take it off the market', on: { click: async (e) => {
          e.target.disabled = true;
          try {
            const { listings } = await api.get('market');
            const mine = listings.find((l) => l.saintId === s.id);
            if (mine) await api.post('market/cancel', { listingId: mine.id });
            await state.refresh();
            toast(info.name + ' is back home with you.');
            render();
          } catch (ex) { msg.textContent = ex.message; e.target.disabled = false; }
        } } })), msg);
  } else if (s.status === 'frozen') {
    form = el('div', {}, el('h3', { text: info.name }), el('p', {}, el('span.tag.frozen', { text: 'frozen' }), ' Someone else scanned this saint’s card. It can’t be traded until that is sorted out — check the 🔔.'));
  } else {
    const price = el('input.input', { type: 'number', min: 10, max: 100000, step: 5, value: Math.max(10, Math.round(info.price * 0.8 / 5) * 5), id: 'trade-price', 'aria-label': 'Price in virtue points' });
    const story = el('textarea.input', { maxlength: 300, id: 'trade-story', placeholder: `How did ${info.name} help you? e.g. “I prayed to him before my test and felt brave.”`, 'aria-label': 'How this saint helped you' });
    const go = el('button.btn.green', { type: 'submit', text: 'Put it up for trade ✨', id: 'trade-submit' });
    form = el('form', { on: { submit: async (e) => {
      e.preventDefault();
      go.disabled = true;
      msg.textContent = '';
      try {
        await api.post('market/list', { saintId: s.id, price: Number(price.value), benefit: story.value });
        await state.refresh();
        toast(`${info.name} is on the Market for ✦ ${fmt(price.value)}. Everyone can see it now!`);
        const r = d.getBoundingClientRect();
        sparkle(r.left + r.width / 2, 200);
        render();
      } catch (ex) { msg.textContent = ex.message; go.disabled = false; }
    } } },
    el('h3', { style: { margin: '0 0 4px' }, text: info.name }),
    el('div.muted', { text: `★ ${s.rating} rating · ${s.wins} wins${s.card ? ' · from card ' + s.card : ''}` }),
    el('label.field', { for: 'trade-price', text: 'Price (virtue points)' }), price,
    el('label.field', { for: 'trade-story', text: 'What did this saint do for you?' }), story,
    el('div.row', { style: { marginTop: '10px' } }, go), msg);
  }
  body.append(el('div.plate-body', {}, stand, form));
  viewer = mountViewer(stand, info, { onPlate: true });
  const strip = el('div.saint-strip', { 'aria-label': 'Your saints — scroll sideways' }, saints.map((x) => el('button' + (x.id === selected ? '.on' : ''), {
    type: 'button', 'aria-pressed': String(x.id === selected), on: { click: () => { selected = x.id; render(); } },
  }, el('img', { src: thumb(x.key), alt: '', loading: 'lazy' }), el('span', { text: def(x.key).name }),
  x.status !== 'ok' ? el('span.tag.' + x.status, { text: x.status === 'listed' ? 'for trade' : 'frozen' }) : null)));
  d.append(strip);
  const on = strip.querySelector('.on');
  if (on) on.scrollIntoView({ inline: 'center', block: 'nearest' });
}

async function renderMarket(body, mineOnly) {
  const listEl = el('div.market-list', {}, el('p.muted', { text: 'Loading…' }));
  body.append(el('h3', { style: { margin: '0 0 6px', textAlign: 'center' }, text: mineOnly ? 'Saints you put up for trade' : 'Saints everyone is trading' }), listEl);
  try {
    const { listings } = await api.get('market');
    const items = listings.filter((l) => (mineOnly ? l.seller === state.me.username : true));
    listEl.replaceChildren(...items.map((l) => el('button.listing', { type: 'button', on: { click: () => openSaint(l.saintId) } },
      el('img', { src: thumb(l.key), alt: '' }),
      el('div', {}, el('b', { text: l.name }), el('div', { style: { color: 'var(--gold-dd)', fontWeight: 800 }, text: `✦ ${fmt(l.price)}` }),
        el('div.muted', { style: { fontSize: '12px' }, text: `${l.seller === state.me.username ? 'You' : l.seller} · ★ ${l.rating}` }),
        el('div.story', { text: '“' + l.benefit + '”' })))));
    if (!items.length) listEl.replaceChildren(el('p.muted', { text: mineOnly ? 'You have nothing up for trade. Pick “Sell a saint” to list one.' : 'No saints are up for trade yet. Be the first!' }));
  } catch (e) { listEl.replaceChildren(el('p.err', { text: e.message })); }
}
