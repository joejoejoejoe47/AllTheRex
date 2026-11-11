// Shared walking input for the home village and the church: WASD / arrow
// keys (ignored while typing or while a window is open) plus an on-screen
// joystick for touch screens.

const keys = new Set();
const stick = { x: 0, y: 0 };
const MOVE = { KeyW: [0, -1], ArrowUp: [0, -1], KeyS: [0, 1], ArrowDown: [0, 1], KeyA: [-1, 0], ArrowLeft: [-1, 0], KeyD: [1, 0], ArrowRight: [1, 0] };
let actionHandler = null;
let enabledFn = () => true;

/** True when keys should not move the saint (typing, a window open, battle, login…). */
export function blocked(target) {
  const t = target || document.activeElement;
  if (t && t.closest && t.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]')) return true;
  if (document.querySelector('.modal-backdrop')) return true;
  const hiddenOr = (sel) => { const n = document.querySelector(sel); return n && !n.hidden; };
  if (hiddenOr('#trade-drop') || hiddenOr('#battle-root') || hiddenOr('#auth')) return true;
  return !enabledFn();
}

export function setEnabled(fn) { enabledFn = fn; }
export function onAction(fn) { actionHandler = fn; }

window.addEventListener('keydown', (e) => {
  if (e.ctrlKey || e.metaKey || e.altKey) return;
  if (blocked(e.target)) { keys.clear(); return; }
  if (MOVE[e.code]) {
    keys.add(e.code);
    e.preventDefault();
    return;
  }
  if ((e.code === 'KeyE' || e.code === 'Space' || e.code === 'Enter') && !e.repeat && actionHandler) {
    // Space/Enter on a focused button keeps its normal meaning.
    if (e.code !== 'KeyE' && e.target && e.target.closest && e.target.closest('button, a')) return;
    if (actionHandler(e.code)) e.preventDefault();
  }
});
window.addEventListener('keyup', (e) => { keys.delete(e.code); });
window.addEventListener('blur', () => keys.clear());
document.addEventListener('visibilitychange', () => { if (document.hidden) keys.clear(); });

/** Current direction: x right, y down-screen (toward the viewer), length ≤ 1. */
export function direction() {
  let x = stick.x, y = stick.y;
  if (keys.size && !blocked()) {
    for (const k of keys) { x += MOVE[k][0]; y += MOVE[k][1]; }
  } else if (keys.size) keys.clear();
  const l = Math.hypot(x, y);
  return l > 1 ? { x: x / l, y: y / l } : { x, y };
}

export function clearKeys() { keys.clear(); stick.x = stick.y = 0; }

export const isTouch = () => window.matchMedia('(pointer: coarse)').matches || 'ontouchstart' in window;

/** A small joystick (touch). Returns the element; caller places it. */
export function makeJoystick(id) {
  const s = document.createElement('div');
  s.className = 'walk-stick';
  s.id = id;
  s.setAttribute('aria-hidden', 'true');
  const knob = document.createElement('i');
  s.append(knob);
  let pid = null;
  const move = (e) => {
    const r = s.getBoundingClientRect();
    let dx = e.clientX - (r.left + r.width / 2), dy = e.clientY - (r.top + r.height / 2);
    const m = r.width / 2;
    const l = Math.hypot(dx, dy);
    if (l > m) { dx *= m / l; dy *= m / l; }
    stick.x = Math.abs(dx / m) < 0.15 ? 0 : dx / m;
    stick.y = Math.abs(dy / m) < 0.15 ? 0 : dy / m;
    knob.style.transform = `translate(${dx}px, ${dy}px)`;
  };
  s.addEventListener('pointerdown', (e) => { e.preventDefault(); pid = e.pointerId; s.setPointerCapture(pid); move(e); });
  s.addEventListener('pointermove', (e) => { if (e.pointerId === pid) move(e); });
  const up = () => { pid = null; stick.x = stick.y = 0; knob.style.transform = ''; };
  s.addEventListener('pointerup', up);
  s.addEventListener('pointercancel', up);
  return s;
}
