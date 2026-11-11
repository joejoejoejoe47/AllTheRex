// The bell: notifications plus card questions ("Did you trade this card?").
import * as api from './api.js';
import { el, openModal, toast, thumb, timeAgo } from './ui.js';
import { state } from './state.js';
import { openSaint } from './saintview.js';

export function openNotes() {
  if (!state.me) return;
  const wrap = el('div', {}, el('p.muted', { text: 'Loading…' }));
  const modal = openModal(wrap, { narrow: true, label: 'Notifications' });
  const load = async () => {
    let data;
    try { data = await api.get('notifications'); } catch (e) { wrap.replaceChildren(el('p.err', { text: e.message })); return; }
    const qs = data.questions.map((q) => questionNode(q, load));
    const items = data.items.map((n) => el('div.note' + (n.seen ? '' : '.unseen'), {},
      el('span', { style: { fontSize: '22px' }, text: icon(n.kind) }),
      el('div', { style: { flex: 1 } }, el('b', { text: n.title }), n.body ? el('small', { text: n.body }) : null, el('small', { text: timeAgo(n.at) }),
        n.refType === 'saint' && n.refId ? el('button.tiny-link', { type: 'button', text: 'Open saint', on: { click: () => { modal.close(); openSaint(n.refId); } } }) : null)));
    wrap.replaceChildren(el('div', {}, el('h2', { text: '🔔 Notifications' }),
      qs.length ? el('div', {}, el('h3', { style: { margin: '4px 0 8px' }, text: 'Card checks' }), qs) : null,
      items.length ? items : el('p.muted', { text: 'Nothing new yet.' })));
    if (data.items.some((n) => !n.seen)) { api.post('notifications/seen').then(() => state.refresh(true)).catch(() => {}); }
  };
  load();
  return modal;
}

const icon = (k) => ({ welcome: '🌟', sold: '🪙', comment: '💬', claim: '❓', 'claim-ok': '🎉', 'claim-no': '✋', 'claim-photo': '📷', 'claim-withdrawn': '🛡️' }[k] || '🔔');

function questionNode(q, reload) {
  const msg = el('p.err');
  const box = el('div.question', { 'data-claim': q.id, 'data-status': q.status },
    el('div.row', {}, el('img', { src: thumb(q.key, 'head'), alt: '', style: { width: '48px', height: '48px' } }),
      el('div', {}, el('b', { text: q.role === 'owner' ? `Did you trade your ${q.name} card?` : `Your claim on ${q.name}` }),
        el('div.muted', { text: q.role === 'owner' ? `${q.other} scanned card ${q.code}.` : `First added by ${q.other}.` }))),
    el('p', { style: { margin: '6px 0' } }, el('b', { text: 'Status: ' }), q.text));
  const act = async (path, body, okMsg) => {
    msg.textContent = '';
    try { await api.post(path, body); toast(okMsg); await state.refresh(true); reload(); } catch (e) { msg.textContent = e.message; }
  };
  if (q.role === 'owner' && q.status === 'pending') {
    box.append(el('div.row', {},
      el('button.btn.green.q-yes', { type: 'button', text: 'Yes, I traded it', on: { click: () => act('claims/respond', { id: q.id, traded: true }, 'The card moved to its new owner. Thank you for being honest!') } }),
      el('button.btn.red.q-no', { type: 'button', text: 'No, it is still mine', on: { click: () => act('claims/respond', { id: q.id, traded: false }, 'OK! Now please send a photo of your card.') } })));
  }
  const photoUpload = (label) => {
    const input = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', capture: 'environment', hidden: true, id: 'claim-photo-' + q.id });
    input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return;
      if (f.size > 5 * 1024 * 1024) { msg.textContent = 'That picture is too big (5 MB max).'; return; }
      const fd = new FormData();
      fd.append('id', q.id);
      fd.append('image', f, f.name || 'card.jpg');
      act('claims/photo', fd, 'Photo sent.');
    });
    return [el('label.btn.blue.q-photo', { for: 'claim-photo-' + q.id, text: label }), input];
  };
  if (q.role === 'owner' && (q.status === 'awaiting_photo' || q.status === 'disputed')) {
    box.append(el('div.row', {}, ...photoUpload(q.ownerPhoto ? '📷 Send a new photo' : '📷 Send a photo of the card'),
      el('button.btn.ghost', { type: 'button', text: 'Actually, I did trade it', on: { click: () => act('claims/respond', { id: q.id, traded: true }, 'The card moved to its new owner.') } })));
  }
  if (q.role === 'claimant') {
    box.append(el('div.row', {}, ...photoUpload(q.claimantPhoto ? '📷 New photo of my card' : '📷 Add a photo of my card'),
      el('button.btn.ghost.q-withdraw', { type: 'button', text: 'Withdraw my claim', on: { click: () => act('claims/withdraw', { id: q.id }, 'Claim withdrawn. The card stays with the other player.') } })));
  }
  const mineOwner = q.role === 'owner';
  const photos = [q.ownerPhoto && [mineOwner ? 'Your photo' : 'Owner’s photo', q.ownerPhoto], q.claimantPhoto && [mineOwner ? `${q.other}’s photo` : 'Your photo', q.claimantPhoto]].filter(Boolean);
  if (photos.length) box.append(el('div.row', {}, photos.map(([l, id]) => el('figure', { style: { margin: 0 } }, el('img.photo', { src: api.imageUrl(id), alt: l }), el('figcaption.muted', { text: l })))));
  box.append(msg);
  return box;
}
