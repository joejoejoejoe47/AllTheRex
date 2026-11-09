import { attemptCapture, trailSelfCrosses, OOB, EMPTY } from '../assets/js/capture.js';

let pass = 0;
let fail = 0;
function check(cond, label) {
  if (cond) {
    pass++;
    console.log('  ok ', label);
  } else {
    fail++;
    console.error('  FAIL:', label);
  }
}

function gridFrom(lines) {
  const map = { '.': EMPTY, '#': OOB, '1': 1, '2': 2 };
  return lines.map((line) => [...line].map((ch) => map[ch] ?? EMPTY));
}

const base = gridFrom([
  '##########',
  '#........#',
  '#........#',
  '#........#',
  '#........#',
  '#.111....#',
  '#.111....#',
  '##########',
]);

const trail = [
  [2, 4], [2, 3], [2, 2], [3, 2], [4, 2], [5, 2], [5, 3], [5, 4], [4, 4], [3, 4],
];
const r = attemptCapture(base, 1, trail);
check(r.ok === true, 'valid loop captures');
check(r.captured > 0, 'captured some cells');
check(r.grid[3][3] === 1, 'interior cell claimed');
check(r.grid[1][1] === EMPTY, 'outside-the-loop land stays empty');

const enemy = base.map((row) => row.slice());
enemy[3][3] = 2;
enemy[3][4] = 2;
const r2 = attemptCapture(enemy, 1, trail);
check(r2.grid[3][3] === 1, 'enclosed enemy cell is captured');

check(attemptCapture(base, 1, []).reason === 'empty-trail', 'empty trail invalid');
check(attemptCapture(base, 1, [[0, 0], [1, 1]]).reason === 'out-of-bounds-trail', 'oob trail invalid');
check(attemptCapture(base, 1, [[6, 2], [6, 3], [5, 3]]).reason === 'start-not-connected', 'unconnected trail invalid');

const nibble = attemptCapture(base, 1, [[2, 4]]);
check(nibble.ok && nibble.captured === 1, 'out-and-back claims only the trail');

const cross = [[1, 1], [2, 1], [3, 1], [3, 2], [2, 2], [2, 1], [2, 0]];
check(trailSelfCrosses(cross, 3) === true, 'self-crossing detected');
check(trailSelfCrosses([[1, 1], [2, 1], [3, 1]], 3) === false, 'open trail is not a cross');

console.log(`${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
