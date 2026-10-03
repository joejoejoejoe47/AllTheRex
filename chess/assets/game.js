import { Chess } from "./chess.js";

const root = document.querySelector(".table");
const token = document.querySelector('meta[name="morse-token"]')?.content ?? "";
const boardEl = document.getElementById("board");
const farEl = document.getElementById("far-plate");
const nearEl = document.getElementById("near-plate");
const resultEl = document.getElementById("result");
const movesEl = document.getElementById("moves");

let snapshot = JSON.parse(root?.dataset.game || "{}");
let chess = new Chess();
let selected = "";
let syncedAt = Date.now();
let busy = false;
let pendingPromo = null;

const GLYPH = {
  w: { k: "♔", q: "♕", r: "♖", b: "♗", n: "♘", p: "♙" },
  b: { k: "♚", q: "♛", r: "♜", b: "♝", n: "♞", p: "♟" },
};
const VALUE = { p: 100, n: 320, b: 330, r: 500, q: 900, k: 0 };

function replay(moves) {
  const next = new Chess();
  for (const uci of String(moves || "").split(/\s+/).filter(Boolean)) {
    const played = next.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4],
    });
    if (!played) return null;
  }
  return next;
}

function outcome(position, mover) {
  if (position.isCheckmate()) return mover;
  if (position.isDraw()) return "draw";
  return "";
}

function squares() {
  const youBlack = snapshot.you === "black";
  const list = [];
  for (let rank = 0; rank < 8; rank++) {
    for (let file = 0; file < 8; file++) {
      const f = youBlack ? 7 - file : file;
      const r = youBlack ? rank + 1 : 8 - rank;
      list.push("abcdefgh"[f] + r);
    }
  }
  return list;
}

function msLeft(side) {
  const base = side === "white" ? snapshot.whiteMs : snapshot.blackMs;
  if (!snapshot.clock || snapshot.status !== "play" || snapshot.turn !== side) return base;
  return Math.max(0, base - (Date.now() - syncedAt));
}

function fmt(ms) {
  const total = Math.ceil(ms / 1000);
  const m = Math.floor(total / 60);
  const s = total % 60;
  return m + ":" + String(s).padStart(2, "0");
}

function plate(el, person, side) {
  const clock = snapshot.clock
    ? '<b>' + fmt(msLeft(side)) + "</b>"
    : "";
  el.innerHTML = "<strong>" + person.name + "</strong><span>" + person.rating + "</span>" + clock;
}

function paint() {
  const youBlack = snapshot.you === "black";
  plate(farEl, youBlack ? snapshot.white : snapshot.black, youBlack ? "white" : "black");
  plate(nearEl, youBlack ? snapshot.black : snapshot.white, youBlack ? "black" : "white");
  const history = chess.history({ verbose: true });
  const last = history[history.length - 1];
  const targets = new Set();
  if (selected) {
    for (const move of chess.moves({ square: selected, verbose: true })) targets.add(move.to);
  }
  const king = chess.inCheck()
    ? squares().find((sq) => {
        const piece = chess.get(sq);
        return piece && piece.type === "k" && piece.color === chess.turn();
      })
    : "";
  boardEl.replaceChildren();
  for (const sq of squares()) {
    const piece = chess.get(sq);
    const button = document.createElement("button");
    button.type = "button";
    button.className = "sq " + ((sq.charCodeAt(0) + Number(sq[1])) % 2 === 0 ? "dark" : "light");
    if (sq === selected || (last && (sq === last.from || sq === last.to))) button.classList.add("mark");
    if (targets.has(sq)) button.classList.add("target");
    if (piece && targets.has(sq)) button.classList.add("has");
    if (sq === king) button.classList.add("check");
    button.textContent = piece ? GLYPH[piece.color][piece.type] : "";
    button.addEventListener("click", () => choose(sq));
    boardEl.appendChild(button);
  }
  movesEl.replaceChildren();
  chess.history().forEach((san, index) => {
    if (index % 2 === 0) {
      const li = document.createElement("li");
      li.innerHTML = "<span>" + (index / 2 + 1) + ".</span><b></b><b></b>";
      movesEl.appendChild(li);
    }
    const row = movesEl.lastElementChild;
    row.children[(index % 2) + 1].textContent = san;
  });
  if (snapshot.status === "white") resultEl.textContent = snapshot.white.name + " wins";
  else if (snapshot.status === "black") resultEl.textContent = snapshot.black.name + " wins";
  else if (snapshot.status === "draw") resultEl.textContent = "Drawn";
  else resultEl.textContent = snapshot.turn === snapshot.you ? "Your move" : "Waiting";
}

function myTurn() {
  return snapshot.status === "play" && snapshot.turn === snapshot.you && !busy;
}

function choose(sq) {
  if (!myTurn()) return;
  const piece = chess.get(sq);
  if (selected && selected !== sq) {
    const legal = chess.moves({ square: selected, verbose: true }).find((move) => move.to === sq);
    if (legal) {
      if (legal.promotion) {
        pendingPromo = { from: selected, to: sq };
        showPromo();
        return;
      }
      play(selected, sq, "");
      return;
    }
  }
  if (piece && piece.color === chess.turn()) {
    selected = sq;
    paint();
    return;
  }
  selected = "";
  paint();
}

function showPromo() {
  let bar = document.getElementById("promo");
  if (!bar) {
    bar = document.createElement("div");
    bar.id = "promo";
    bar.className = "promo";
    resultEl.after(bar);
  }
  bar.replaceChildren();
  for (const piece of ["q", "r", "b", "n"]) {
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = GLYPH.w[piece];
    button.addEventListener("click", () => {
      const choice = pendingPromo;
      pendingPromo = null;
      bar.remove();
      if (choice) play(choice.from, choice.to, piece);
    });
    bar.appendChild(button);
  }
}

async function play(from, to, promotion) {
  const probe = new Chess(chess.fen());
  const moved = probe.move({ from, to, promotion: promotion || undefined });
  if (!moved) return;
  const over = outcome(probe, snapshot.turn);
  selected = "";
  busy = true;
  try {
    const data = await postMove(from + to + (promotion || ""), over);
    if (data.bot) window.setTimeout(botPlay, 280);
  } finally {
    busy = false;
  }
}

async function postMove(uci, over) {
  const response = await fetch("index.php?api=1", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Morse-Token": token },
    body: JSON.stringify({ action: "move", game: snapshot.id, uci, over }),
  });
  const data = await response.json();
  if (data.game) apply(data.game);
  if (data.error) resultEl.textContent = data.error;
  return data;
}

function evaluate(position) {
  if (position.isCheckmate()) return position.turn() === "w" ? -100000 : 100000;
  if (position.isDraw()) return 0;
  let score = 0;
  for (const row of position.board()) {
    for (const piece of row) {
      if (!piece) continue;
      const value = VALUE[piece.type];
      score += piece.color === "w" ? value : -value;
    }
  }
  return score;
}

function minimax(position, depth, alpha, beta, white) {
  if (depth === 0 || position.isGameOver()) return evaluate(position);
  let best = white ? -Infinity : Infinity;
  for (const move of position.moves({ verbose: true })) {
    position.move(move);
    const score = minimax(position, depth - 1, alpha, beta, !white);
    position.undo();
    if (white) {
      best = Math.max(best, score);
      alpha = Math.max(alpha, best);
    } else {
      best = Math.min(best, score);
      beta = Math.min(beta, best);
    }
    if (beta <= alpha) break;
  }
  return best;
}

function botPlay() {
  if (snapshot.status !== "play" || snapshot.turn !== "black" || !snapshot.bot) return;
  const moves = chess.moves({ verbose: true });
  let pool = [];
  let bestScore = Infinity;
  for (const move of moves) {
    chess.move(move);
    const score = minimax(chess, 2, -1e9, 1e9, true);
    chess.undo();
    if (score < bestScore - 12) {
      bestScore = score;
      pool = [move];
    } else if (Math.abs(score - bestScore) <= 12) {
      pool.push(move);
      if (score < bestScore) bestScore = score;
    }
  }
  const pick = pool[Math.floor(Math.random() * pool.length)] || moves[0];
  if (!pick) return;
  const probe = new Chess(chess.fen());
  probe.move(pick);
  const uci = pick.from + pick.to + (pick.promotion || "");
  busy = true;
  postMove(uci, outcome(probe, "black")).finally(() => {
    busy = false;
  });
}

function apply(game) {
  const next = replay(game.moves);
  if (!next) return;
  snapshot = game;
  chess = next;
  syncedAt = Date.now();
  selected = "";
  paint();
}

async function poll() {
  if (busy || pendingPromo) return;
  const response = await fetch("index.php?api=1&action=state&g=" + snapshot.id);
  if (!response.ok) return;
  const data = await response.json();
  if (!data.game || data.game.moves === snapshot.moves && data.game.status === snapshot.status) {
    if (data.game) {
      snapshot.whiteMs = data.game.whiteMs;
      snapshot.blackMs = data.game.blackMs;
      snapshot.turn = data.game.turn;
      snapshot.status = data.game.status;
      syncedAt = Date.now();
    }
    return;
  }
  apply(data.game);
  if (data.game.bot && data.game.turn === "black" && data.game.status === "play") botPlay();
}

apply(snapshot);
if (snapshot.bot && snapshot.turn === "black" && snapshot.status === "play") botPlay();
window.setInterval(() => paint(), 400);
window.setInterval(() => { poll().catch(() => {}); }, 1600);
