// A saint's public page: 3D view, owner, listing, and comments (with pictures).
import * as api from './api.js';
import { el, openModal, toast, sparkle, thumb, fmt, timeAgo } from './ui.js';
import { state, def } from './state.js';
import { mountViewer } from './preview.js';
import { statBars } from './store.js';

const MAX_IMG = 5 * 1024 * 1024;
const OK_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

export function openSaint(saintId) {
  let viewer = null;
  const wrap = el('div', {}, el('p.muted', { text: 'Loading saint…' }));
  const modal = openModal(wrap, { label: 'Saint', onClose: () => viewer && viewer.dispose() });
  const load = async (keepComposer = false) => {
    let data;
    try { data = await api.get('saint', { id: saintId }); } catch (e) { wrap.replaceChildren(el('p.err', { text: e.message })); return; }
    const { saint, listing, comments } = data;
    const d = def(saint.key);
    if (viewer) viewer.dispose();
    const view = el('div.viewer');
    const msg = el('p.err');
    const actions = el('div.row');
    if (listing) {
      if (saint.mine) {
        actions.append(el('button.btn.red', { type: 'button', text: 'Take off the market', on: { click: async (e) => {
          e.target.disabled = true;
          try { await api.post('market/cancel', { listingId: listing.id }); await state.refresh(); toast(d.name + ' is back home.'); load(); } catch (ex) { msg.textContent = ex.message; e.target.disabled = false; }
        } } }));
      } else if (state.me) {
        actions.append(el('button.btn.green#buy-listing', { type: 'button', text: `Buy for ✦ ${fmt(listing.price)}`, on: { click: async (e) => {
          e.target.disabled = true;
          msg.textContent = '';
          try {
            const r = await api.post('market/buy', { listingId: listing.id });
            await state.refresh();
            toast(`You traded for ${r.name}! 🎉`);
            sparkle(innerWidth / 2, innerHeight / 2);
            load();
          } catch (ex) { msg.textContent = ex.message; e.target.disabled = false; }
        } } }));
      }
    }
    const commentBtn = el('button.btn.blue#comment-btn', { type: 'button', text: '💬 Comment' });
    const composerSlot = el('div');
    commentBtn.addEventListener('click', () => {
      if (!state.me) { toast('Log in to comment.', true); return; }
      if (!composerSlot.firstChild) composerSlot.append(composer(saint.id, () => load(true)));
      composerSlot.querySelector('textarea').focus();
    });
    const list = el('div.comments', {}, el('h3', { text: `Comments (${comments.length})` }),
      comments.length ? comments.map((c) => commentNode(c, load)) : el('p.muted', { text: 'No comments yet. Say something kind!' }));
    const owner = saint.mine ? 'You' : saint.owner;
    wrap.replaceChildren(el('div.detail', {},
      el('div', {}, view, el('div', { style: { textAlign: 'center', marginTop: '8px' } }, commentBtn)),
      el('div', {},
        el('h2', { text: d.name }),
        el('div.row', {}, el('span.tag.' + d.rarity, { text: d.rarity }),
          saint.status !== 'ok' ? el('span.tag.' + saint.status, { text: saint.status === 'listed' ? 'for trade' : 'frozen (card check)' }) : null,
          el('span.muted', {}, 'Owned by ', saint.mine ? el('b', { text: 'you' }) : el('a', { href: '#', text: saint.owner, on: { click: (e) => { e.preventDefault(); openPlayer(saint.owner); } } }))),
        el('p.muted', { text: `★ ${saint.rating} rating · ${saint.wins} wins · ${saint.losses} losses · patron of ${d.patron}` }),
        listing ? el('div.question', {}, el('b', { text: `For trade: ✦ ${fmt(listing.price)}` }), el('p', { style: { margin: '4px 0', fontStyle: 'italic' }, text: `${owner === 'You' ? 'You wrote' : owner + ' says'}: “${listing.benefit}”` })) : null,
        el('p', { text: d.bio }),
        statBars(d.stats),
        saint.mine && saint.card ? el('p.muted', { text: `Card code: ${saint.card}` }) : null,
        actions, msg, composerSlot, list)));
    viewer = mountViewer(view, d);
    if (keepComposer) list.scrollIntoView({ block: 'end' });
  };
  load();
  return modal;
}

function commentNode(c, reload) {
  if (c.hidden && !c.body && !c.author) return el('div.comment.hidden-c', { text: 'This comment was hidden.' });
  const acts = el('span.acts');
  if (c.canReport && !c.hidden) acts.append(el('button', { type: 'button', text: 'Report', title: 'Report this comment', on: { click: async () => {
    try { const r = await api.post('comments/report', { id: c.id }); toast(r.hidden ? 'Thanks. The comment is now hidden.' : 'Thanks for telling us.'); reload(); } catch (e) { toast(e.message, true); }
  } } }));
  if (c.canHide) acts.append(el('button', { type: 'button', text: c.hidden ? 'Show' : 'Hide', on: { click: async () => {
    try { await api.post('comments/hide', { id: c.id, hidden: !c.hidden }); reload(); } catch (e) { toast(e.message, true); }
  } } }));
  return el('div.comment' + (c.hidden ? '.hidden-c' : ''), {},
    el('div.meta', {}, el('b', { text: c.author + (c.hidden ? ' (hidden)' : '') }), el('span', {}, timeAgo(c.at), acts)),
    c.body ? el('div.body', { text: c.body }) : null,
    c.image ? el('img', { src: api.imageUrl(c.image), alt: 'Picture from ' + c.author, loading: 'lazy' }) : null);
}

/** Comment box: type, paste a picture (Ctrl/Cmd+V) or choose one. */
export function composer(saintId, done) {
  let file = null;
  const text = el('textarea.input#comment-text', { maxlength: 500, placeholder: 'Write a kind comment… (you can paste a picture here too)' });
  const pick = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', hidden: true, id: 'comment-file' });
  const preview = el('div.preview');
  const msg = el('p.err');
  const send = el('button.btn.green#comment-send', { type: 'button', text: 'Post comment' });
  const setFile = (f) => {
    msg.textContent = '';
    if (!f) { file = null; preview.replaceChildren(); return; }
    if (!OK_TYPES.includes(f.type)) { msg.textContent = 'Only JPEG, PNG, WebP or GIF pictures.'; return; }
    if (f.size > MAX_IMG) { msg.textContent = 'That picture is too big (5 MB max).'; return; }
    file = f;
    const url = URL.createObjectURL(f);
    preview.replaceChildren(el('img', { src: url, alt: 'Picture to post' }), el('button.tiny-link', { type: 'button', text: 'remove picture', on: { click: () => setFile(null) } }));
  };
  pick.addEventListener('change', () => setFile(pick.files[0] || null));
  text.addEventListener('paste', (e) => {
    const item = [...(e.clipboardData?.items || [])].find((i) => i.kind === 'file' && i.type.startsWith('image/'));
    if (item) { e.preventDefault(); setFile(item.getAsFile()); }
  });
  send.addEventListener('click', async () => {
    if (!text.value.trim() && !file) { msg.textContent = 'Write something or add a picture.'; return; }
    send.disabled = true;
    const fd = new FormData();
    fd.append('saintId', saintId);
    fd.append('body', text.value);
    if (file) fd.append('image', file, file.name || 'pasted.png');
    try {
      await api.post('comments', fd);
      toast('Comment posted!');
      text.value = '';
      setFile(null);
      done();
    } catch (e) { msg.textContent = e.message; }
    send.disabled = false;
  });
  return el('div.composer', {}, text, preview, msg,
    el('div.row', { style: { marginTop: '6px' } }, send, el('label.btn.ghost', { for: 'comment-file', text: '🖼️ Add picture' }), pick,
      el('span.muted', { style: { fontSize: '12px' }, text: 'Be kind. Pictures are checked and cleaned.' })));
}

export function openPlayer(username) {
  const wrap = el('div', {}, el('p.muted', { text: 'Loading…' }));
  openModal(wrap, { label: 'Player' });
  api.get('player', { name: username }).then(({ player }) => {
    const mine = state.me && state.me.username === player.username;
    wrap.replaceChildren(el('h2', { text: mine ? 'My saints' : `${player.username}'s saints` }),
      el('p.muted', { text: `Level ${player.level} · ✦ ${fmt(player.lifetime)} lifetime virtue · ${player.goodDeeds} good deeds` }),
      el('div.grid', {}, player.saints.map((s) => el('div.card', {}, el('button.open', { type: 'button', on: { click: () => openSaint(s.id) } },
        el('img', { src: thumb(s.key), alt: '', loading: 'lazy' }), el('h3', { text: def(s.key).name }),
        el('div.muted', { text: `★ ${s.rating}` }), s.status !== 'ok' ? el('span.tag.' + s.status, { text: s.status === 'listed' ? 'for trade' : 'frozen' }) : null)))),
      el('p.muted', { style: { marginTop: '10px' }, text: 'Tap a saint to see it up close and leave a comment.' }));
  }).catch((e) => wrap.replaceChildren(el('p.err', { text: e.message })));
}
