// Daily good deeds with the villagers in the picture: a quick, gentle tap game.
import * as api from './api.js';
import { el, openModal, toast, sparkle } from './ui.js';
import { state } from './state.js';
import { cheer } from './home.js';

const DEEDS = {
  apples: { who: 'Grandma Agnes', ask: 'Oh dear, my apples rolled everywhere! Could you put them back in my basket?', item: '🍎', goal: '🧺', count: 6, done: 'Bless you, child! My basket is full again.' },
  lamb: { who: 'Little Tobias', ask: 'My lamb ran off into the flowers! Can you find her? Tap the flowers to look.', item: '🌼', goal: '🐑', count: 5, hidden: true, done: 'You found her! Thank you so much!' },
  chapel: { who: 'Brother Peter', ask: 'Help me ring the chapel bell for evening prayer. Tap the bell!', item: '🔔', goal: '⛪', count: 7, done: 'Ding, dong! The whole village heard. Thank you!' },
};

export function openDeed(key) {
  if (!state.me) return;
  const d = DEEDS[key];
  if (state.me.deedsToday.includes(key)) { toast(`You already helped ${d.who} today. Come back tomorrow!`); return; }
  const play = el('div.deed-play#deed-play', {}, el('span.goal', { text: d.goal }));
  const counter = el('b', { text: `0 / ${d.count}` });
  const wrap = el('div', {}, el('h2', { text: `Help ${d.who}` }), el('p', { text: `“${d.ask}”` }), play, el('p.muted', {}, 'Progress: ', counter, ' · reward: ✦ 25 virtue and +1 Good Deed'));
  const modal = openModal(wrap, { narrow: true, label: 'Good deed' });
  let n = 0;
  const lambAt = Math.floor(Math.random() * d.count);
  const finish = async () => {
    try {
      const r = await api.post('deeds', { deed: key });
      await state.refresh();
      cheer();
      wrap.replaceChildren(el('h2', { text: '💛 Good deed done!' }), el('p', { text: `“${d.done}” — ${d.who}` }), el('p', { text: `You earned ✦ ${r.reward} virtue points and a Good Deed.` }),
        el('button.btn.blue', { type: 'button', text: 'Yay!', on: { click: () => modal.close() } }));
      sparkle(innerWidth / 2, innerHeight / 2);
    } catch (e) { toast(e.message, true); modal.close(); }
  };
  for (let i = 0; i < d.count; i++) {
    const b = el('button.target', { type: 'button', text: d.item, 'aria-label': 'Tap', style: { left: 8 + Math.random() * 70 + '%', top: 10 + Math.random() * 62 + '%' } });
    b.addEventListener('click', () => {
      if (key === 'chapel') {
        b.style.transform = `rotate(${n % 2 ? -25 : 25}deg)`;
        b.style.left = 8 + Math.random() * 70 + '%';
        b.style.top = 10 + Math.random() * 62 + '%';
      } else if (key === 'lamb' && i === lambAt) {
        b.textContent = '🐑';
        n = d.count - 1;
        setTimeout(() => b.remove(), 400);
      } else {
        b.remove();
      }
      n++;
      counter.textContent = `${Math.min(n, d.count)} / ${d.count}`;
      if (n === d.count) finish();
    });
    if (key === 'chapel' && i > 0) continue;
    play.append(b);
  }
}
