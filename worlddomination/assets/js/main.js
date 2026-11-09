import { boot } from './game.js';

boot().catch((err) => {
  const el = document.getElementById('fatal');
  if (el) {
    el.hidden = false;
    el.textContent = err && err.message ? err.message : 'World Domination failed to start.';
  }
  console.error(err);
});
