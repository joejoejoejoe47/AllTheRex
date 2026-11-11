// The Store: every saint for sale for virtue points, with live 3D previews.
import * as api from './api.js';
import { el, openModal, toast, sparkle, thumb, fmt } from './ui.js';
import { state, def } from './state.js';
import { mountViewer } from './preview.js';

const RARITY_ORDER = ['common', 'uncommon', 'rare', 'epic', 'legendary'];

export function statBars(stats) {
  const row = (label, v, tip) => [el('span', { title: tip, text: label }), el('div.stat-bar', { title: `${v}/10` }, el('i', { style: { width: v * 10 + '%' } }))];
  return el('div.stats', {}, ...row('🙏 Faith', stats.faith, 'Power of light attacks'), ...row('🕊️ Hope', stats.hope, 'Speed'), ...row('💛 Charity', stats.charity, 'Light (health) in battles'));
}

export function openStore(query = '', focusKey = null) {
  let viewer = null;
  const wrap = el('div');
  const modal = openModal(wrap, { label: 'Store', onClose: () => viewer && viewer.dispose() });
  const ownedCount = (k) => (state.me ? state.me.saints.filter((s) => s.key === k).length : 0);

  function list(q) {
    if (viewer) { viewer.dispose(); viewer = null; }
    const search = el('input.input', { type: 'search', value: q, placeholder: 'Search the store (name, patron of…)', 'aria-label': 'Search the store' });
    const grid = el('div.grid');
    const draw = () => {
      const t = search.value.trim().toLowerCase();
      const items = state.list.filter((s) => !t || `${s.name} ${s.patron} ${s.rarity} ${s.key}`.toLowerCase().includes(t))
        .sort((a, b) => a.price - b.price);
      grid.replaceChildren(...items.map((s) => el('div.card', {},
        el('button.open', { type: 'button', on: { click: () => detail(s.key, search.value) }, 'aria-label': `${s.name}, ${s.price} virtue points` },
          el('img', { src: thumb(s.key), alt: '', loading: 'lazy' }),
          el('h3', { text: s.name }),
          el('div', {}, el('span.tag.' + s.rarity, { text: s.rarity })),
          el('div.price', { text: `✦ ${fmt(s.price)}` })),
        ownedCount(s.key) ? el('span.tag.owned', { text: `owned ×${ownedCount(s.key)}` }) : null)));
      if (!items.length) grid.append(el('p.muted', { text: 'No saints match that search.' }));
    };
    search.addEventListener('input', draw);
    draw();
    wrap.replaceChildren(el('h2', { text: '🛍️ Saint Store' }),
      el('p.muted', {}, 'You have ', el('b', { text: `✦ ${fmt(state.me ? state.me.virtue : 0)}` }), ' virtue points. Earn more by helping villagers and winning Holy Battles. Every saint is a free CC0 3D model by Kay Lousberg (KayKit) — see ', el('a', { href: '#credits', text: 'credits' }), '.'),
      search, el('div', { style: { height: '10px' } }), grid);
  }

  function detail(key, q) {
    const s = def(key);
    const view = el('div.viewer');
    const buy = el('button.btn.green', { type: 'button', text: `Buy for ✦ ${fmt(s.price)}` });
    const msg = el('p.err');
    const afford = state.me && state.me.virtue >= s.price;
    if (!afford) buy.title = 'Not enough virtue points yet';
    buy.addEventListener('click', async () => {
      buy.disabled = true;
      msg.textContent = '';
      try {
        const r = await api.post('store/buy', { key });
        await state.refresh();
        const idx = state.me.saints.findIndex((x) => x.id === r.saintId);
        toast(`${r.name} joined your saints! 🎉`);
        sparkle(innerWidth / 2, innerHeight / 2);
        if (viewer) viewer.play('Interact', { once: true });
        buy.textContent = 'Bought! Buy another?';
        if (idx >= 0) state.homeIndexHint = idx;
      } catch (e) { msg.textContent = e.message; }
      buy.disabled = false;
    });
    wrap.replaceChildren(
      el('button.back-link', { type: 'button', text: '‹ Back to the store', on: { click: () => list(q) } }),
      el('div.detail', {}, view, el('div', {},
        el('h2', { text: s.name }),
        el('div.row', {}, el('span.tag.' + s.rarity, { text: s.rarity }), el('span.muted', { text: `Patron of ${s.patron} · Feast day ${s.feast}` })),
        el('p', { text: s.bio }),
        statBars(s.stats),
        el('div.row', {}, buy, el('span.muted', { text: state.me ? `You have ✦ ${fmt(state.me.virtue)}` : '' })),
        msg,
        el('p.muted', { style: { fontSize: '12px' } }, 'Drag the saint to turn it around. 3D model: KayKit Adventurers by Kay Lousberg (CC0), recoloured with a halo for Saint Quest.'))));
    viewer = mountViewer(view, s);
  }

  if (focusKey && def(focusKey)) detail(focusKey, query); else list(query);
  return modal;
}
