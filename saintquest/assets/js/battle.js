// Holy Battle: pick a saint and one of three family-friendly 3D mini-games.
// The server starts each battle (seed + token) and decides the reward.
import * as api from './api.js';
import { $, el, openModal, toast, sparkle, thumb, fmt } from './ui.js';
import { state, def, homeSaint } from './state.js';
import { createSaint, THREE } from './saint3d.js';
import { pause as pauseHome } from './home.js';
import { duel } from './games/duel.js';
import { race } from './games/race.js';
import { defense } from './games/defense.js';

const GAMES = {
  duel: { name: 'Arena Duel', icon: '⚔️', blurb: 'Face a shadow in the sky arena. Throw light orbs, raise your shield, and turn the shadow back into light!', controls: 'Move: WASD / arrows / joystick · Shine: Space, click or ✨ · Shield: Shift or 🛡️', make: duel },
  race: { name: 'Light-Orb Race', icon: '🏃', blurb: 'Race three rival saints down the golden path for 45 seconds. Grab the most light orbs and dodge the shadow puddles!', controls: 'Change lane: A/D, ←/→, swipe or tap the left/right side', make: race },
  defense: { name: 'Chapel Defense', icon: '⛪', blurb: 'Shadows float toward the village chapel. Tap them to shine light on them, and place candles to guard the chapel for 60 seconds.', controls: 'Tap/click a shadow to shine · Space: shine on the nearest · 🕯️ then tap the grass to place a candle', make: defense },
};

export function openBattleSelect() {
  if (!state.me) return;
  let chosen = (homeSaint() || state.me.saints[0]).id;
  const strip = el('div.saint-strip', { style: { margin: '0 0 12px', width: '100%' } });
  const drawStrip = () => strip.replaceChildren(...state.me.saints.map((s) => el('button' + (s.id === chosen ? '.on' : ''), { type: 'button', 'aria-pressed': String(s.id === chosen), on: { click: () => { chosen = s.id; drawStrip(); } } },
    el('img', { src: thumb(s.key), alt: '' }), el('span', { text: def(s.key).name }), el('span.tag', { text: '★ ' + s.rating, style: { left: 'auto', right: '4px' } }))));
  drawStrip();
  const modal = openModal(el('div', {},
    el('h2', { text: '✝ Holy Battle' }),
    el('p.muted', { text: 'Choose your saint, then choose a battle. Winning earns virtue points and raises your saint’s rating (your best-rated saint stands on your home page).' }),
    el('h3', { style: { margin: '6px 0' }, text: '1. Your saint' }), strip,
    el('h3', { style: { margin: '6px 0' }, text: '2. The battle' }),
    el('div.games', {}, Object.entries(GAMES).map(([id, g]) => el('button.game-card', { type: 'button', 'data-game': id, on: { click: () => { modal.close(); startBattle(id, chosen); } } },
      el('div.icon', { text: g.icon }), el('h3', { text: g.name }), el('p', { style: { margin: '4px 0' }, text: g.blurb }), el('p.muted', { style: { fontSize: '12px', margin: 0 }, text: g.controls }))))), { label: 'Holy Battle' });
}

function mulberry32(a) {
  return function () { a |= 0; a = (a + 0x6D2B79F5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}

export async function startBattle(game, saintId) {
  const saint = state.me.saints.find((s) => s.id === saintId);
  const sdef = def(saint.key);
  let session;
  try { session = await api.post('battle/start', { game, saintId }); } catch (e) { toast(e.message, true); return; }
  pauseHome(true);
  const root = $('#battle-root');
  root.hidden = false;
  root.replaceChildren();
  const renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
  root.append(renderer.domElement);
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(50, innerWidth / innerHeight, 0.1, 400);
  const resize = () => { renderer.setSize(innerWidth, innerHeight); camera.aspect = innerWidth / innerHeight; camera.updateProjectionMatrix(); };
  resize();
  window.addEventListener('resize', resize);

  // input
  let ctx = null;
  const keys = new Set();
  const stick = { x: 0, y: 0 };
  const onKey = (e) => {
    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' ', 'Space'].includes(e.key)) e.preventDefault();
    if (e.type === 'keydown') { keys.add(e.code); if (ctx && ctx.onKeyDown) ctx.onKeyDown(e.code); } else keys.delete(e.code);
    if (e.type === 'keydown' && e.code === 'Escape') quit();
  };
  window.addEventListener('keydown', onKey);
  window.addEventListener('keyup', onKey);

  const hud = el('div.hud');
  const center = el('div.hud-center');
  const help = el('div.battle-help', { text: GAMES[game].controls });
  const quitBtn = el('button.btn.ghost.battle-quit', { type: 'button', text: '✕ Leave', on: { click: () => quit() } });
  root.append(hud, center, help, quitBtn);

  const player = await createSaint(sdef);
  scene.add(player.object);
  const clock = new THREE.Clock();
  let ended = false, running = false, raf = 0;
  const startedAt = performance.now();

  ctx = {
    THREE, scene, camera, renderer, root, hud, center, help, player, def: sdef, saint, stats: sdef.stats,
    rng: mulberry32(session.seed), seed: session.seed, rivals: session.rivals, keys, stick, el, sparkle,
    createSaint, catalog: state.list, defOf: def,
    axis() {
      let x = stick.x, y = stick.y;
      if (keys.has('KeyA') || keys.has('ArrowLeft')) x -= 1;
      if (keys.has('KeyD') || keys.has('ArrowRight')) x += 1;
      if (keys.has('KeyW') || keys.has('ArrowUp')) y -= 1;
      if (keys.has('KeyS') || keys.has('ArrowDown')) y += 1;
      const l = Math.hypot(x, y);
      return l > 1 ? { x: x / l, y: y / l } : { x, y };
    },
    joystick() {
      const s = el('div.stick', {}, el('i'));
      root.append(s);
      const knob = s.firstChild;
      let id = null;
      const move = (e) => {
        const r = s.getBoundingClientRect();
        let dx = e.clientX - (r.left + r.width / 2), dy = e.clientY - (r.top + r.height / 2);
        const m = r.width / 2;
        const l = Math.hypot(dx, dy);
        if (l > m) { dx *= m / l; dy *= m / l; }
        stick.x = dx / m; stick.y = dy / m;
        knob.style.transform = `translate(${dx}px, ${dy}px)`;
      };
      s.addEventListener('pointerdown', (e) => { id = e.pointerId; s.setPointerCapture(id); move(e); });
      s.addEventListener('pointermove', (e) => { if (e.pointerId === id) move(e); });
      const up = () => { id = null; stick.x = stick.y = 0; knob.style.transform = ''; };
      s.addEventListener('pointerup', up);
      s.addEventListener('pointercancel', up);
      return s;
    },
    button(label, cls, onPress) {
      let bar = root.querySelector('.battle-actions');
      if (!bar) { bar = el('div.battle-actions'); root.append(bar); }
      const b = el('button.round-btn' + (cls ? '.' + cls : ''), { type: 'button', text: label });
      b.addEventListener('pointerdown', (e) => { e.preventDefault(); onPress(); });
      bar.append(b);
      return b;
    },
    elapsed: () => (performance.now() - startedAt) / 1000,
    end: (score, won) => finish(score, won),
  };

  const gameApi = await GAMES[game].make(ctx);

  // countdown
  const cd = el('div.countdown');
  root.append(cd);
  for (const t of ['3', '2', '1', 'Go!']) { cd.textContent = t; await new Promise((r) => setTimeout(r, t === 'Go!' ? 500 : 650)); if (ended) return; }
  cd.remove();
  running = true;
  clock.getDelta();
  const loop = () => {
    raf = requestAnimationFrame(loop);
    const dt = Math.min(0.05, clock.getDelta());
    if (running) gameApi.update(dt);
    player.update(dt);
    if (gameApi.animate) gameApi.animate(dt);
    renderer.render(scene, camera);
  };
  loop();
  // first frame before the countdown ended
  renderer.render(scene, camera);

  async function finish(score, won) {
    if (ended) return;
    ended = true;
    running = false;
    help.remove();
    let result;
    try {
      result = await api.post('battle/finish', { battleId: session.battleId, token: session.token, score: Math.round(score), won: !!won });
    } catch (e) {
      result = { won: false, reward: 0, ratingDelta: 0, note: e.message, score };
    }
    if (result.won) { player.play('Interact', { once: true }); sparkle(innerWidth / 2, innerHeight / 2, ['✨', '🕊️', '⭐']); }
    await state.refresh(true);
    const box = el('div.panel.cream.result#battle-result', { style: { position: 'absolute', left: '50%', top: '50%', transform: 'translate(-50%,-50%)', padding: '18px 24px', width: 'min(440px, 92vw)', zIndex: 3 } },
      el('div.big', { text: result.won ? '🏆 Victory!' : '🕊️ Good try!' }),
      el('p', { text: (gameApi.summary ? gameApi.summary(result) : `Score ${result.score}`) }),
      el('p', {}, el('b', { text: `+${result.reward} virtue points` }), result.ratingDelta ? ` · rating ${result.ratingDelta > 0 ? '+' : ''}${result.ratingDelta} (★ ${result.rating})` : ''),
      result.note ? el('p.muted', { text: result.note }) : null,
      el('div.row', { style: { justifyContent: 'center' } },
        el('button.btn.blue', { type: 'button', text: 'Play again', on: { click: () => { cleanup(); startBattle(game, saintId); } } }),
        el('button.btn', { type: 'button', text: 'Other battles', on: { click: () => { cleanup(); openBattleSelect(); } } }),
        el('button.btn.ghost', { type: 'button', text: 'Home', on: { click: () => cleanup() } })));
    root.append(box);
  }

  function quit() {
    if (!ended) {
      const sure = root.querySelector('.leave-ask');
      if (sure) return;
      running = false;
      const ask = el('div.panel.cream.leave-ask', { style: { position: 'absolute', left: '50%', top: '50%', transform: 'translate(-50%,-50%)', padding: '16px 20px', zIndex: 4, textAlign: 'center' } },
        el('p', { style: { fontWeight: 800 }, text: 'Leave this battle? It will count as a loss.' }),
        el('div.row', { style: { justifyContent: 'center' } },
          el('button.btn.blue', { type: 'button', text: 'Keep playing', on: { click: () => { ask.remove(); running = true; clock.getDelta(); } } }),
          el('button.btn.red', { type: 'button', text: 'Leave', on: { click: () => { ask.remove(); finish(gameApi.score ? gameApi.score() : 0, false); } } })));
      root.append(ask);
      return;
    }
    cleanup();
  }

  function cleanup() {
    cancelAnimationFrame(raf);
    window.removeEventListener('resize', resize);
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('keyup', onKey);
    if (gameApi.dispose) gameApi.dispose();
    player.dispose();
    renderer.dispose();
    renderer.forceContextLoss?.();
    root.replaceChildren();
    root.hidden = true;
    pauseHome(false);
  }
  window.__sqBattle = { game, get ended() { return ended; }, get running() { return running; }, debug: () => (gameApi.debug ? gameApi.debug() : {}) };
}
