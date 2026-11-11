// The game's dialogue box: a portrait, the speaker's name and their line,
// typed out in step with the recorded voice. Used by the people on the paths
// and by the Holy Mystery cutscene.
import { el } from './ui.js';

let box = null, nameEl = null, textEl = null, faceEl = null, typer = 0;

function ensure() {
  if (box) return;
  faceEl = el('div.dlg-face');
  nameEl = el('b.dlg-name');
  textEl = el('p.dlg-text');
  box = el('div#dialogue.dialogue', { hidden: true, role: 'status', 'aria-live': 'polite' }, faceEl, el('div.dlg-body', {}, nameEl, textEl), el('span.dlg-next', { 'aria-hidden': 'true', text: '▼' }));
  document.body.append(box);
}

/** face: { img } (a picture URL) or { emoji, color }. dur: seconds to type the line over. */
export function show(name, text, face = {}, dur = 2, mine = false) {
  ensure();
  clearInterval(typer);
  faceEl.replaceChildren(face.img ? el('img', { src: face.img, alt: '' }) : el('span', { text: face.emoji || '🙂' }));
  faceEl.style.background = face.color || '';
  nameEl.textContent = name;
  box.classList.toggle('mine', !!mine);
  box.hidden = false;
  box.classList.remove('done');
  document.body.classList.add('talking');
  // Type the words out over ~85% of the spoken line.
  const words = text.split(' ');
  let i = 0;
  textEl.textContent = '';
  textEl.dataset.full = text;
  const step = Math.max(28, (dur * 850) / Math.max(1, words.length));
  const tick = () => {
    i++;
    textEl.textContent = words.slice(0, i).join(' ');
    if (i >= words.length) { clearInterval(typer); box.classList.add('done'); }
  };
  tick();
  typer = setInterval(tick, step);
}

export function hide() {
  if (!box) return;
  clearInterval(typer);
  box.hidden = true;
  document.body.classList.remove('talking');
}

export const text = () => (box && !box.hidden ? box.querySelector('.dlg-name').textContent + ': ' + (textEl.dataset.full || '') : '');
