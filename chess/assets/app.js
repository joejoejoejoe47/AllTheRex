const base = window.MORSE_BASE || "";
const glyph = { K: "♔", Q: "♕", R: "♖", B: "♗", N: "♘", P: "♙", k: "♚", q: "♛", r: "♜", b: "♝", n: "♞", p: "♟" };
const app = document.querySelector("#app");
let pollTimer = 0;
let user = null;

function armPoll() {
  clearTimeout(pollTimer);
  if (!game) return;
  if (game.waiting || (game.status === "active" && game.you !== game.turn)) {
    pollTimer = setTimeout(poll, 2000);
  }
}
let boards = [];
let game = null;
let selected = "";
let promo = null;

async function api(action, body) {
  const res = await fetch(`${base}/api.php?action=${action}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body || {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || "Request failed");
  return data;
}

function boardSkin() {
  const id = user?.board || "lodge";
  return boards.find((b) => b.id === id) || { light: "#f6e7c4", dark: "#17362a" };
}

function parseFen(fen) {
  const rows = fen.split(" ")[0].split("/");
  const sq = [];
  for (const row of rows) {
    for (const ch of row) {
      if (/\d/.test(ch)) for (let i = 0; i < Number(ch); i++) sq.push("");
      else sq.push(ch);
    }
  }
  return sq;
}

function nameAt(i) {
  return "abcdefgh"[i % 8] + (8 - Math.floor(i / 8));
}

function paint() {
  if (!user) return authScreen();
  if (game) return gameScreen();
  return homeScreen();
}

function authScreen(error = "") {
  app.innerHTML = `<main class="card">
    <p class="eyebrow">Morse Chess</p>
    <h1>Take a seat</h1>
    <p class="muted">Username and password. This is the Plesk club.</p>
    ${error ? `<p class="error">${error}</p>` : ""}
    <form id="in" class="stack">
      <label>Username <input name="username" autocomplete="username" required /></label>
      <label>Password <input name="password" type="password" autocomplete="current-password" required /></label>
      <div class="row">
        <button type="submit">Sign in</button>
        <button type="button" class="ghost" id="up">Create account</button>
      </div>
    </form>
  </main>`;
  app.querySelector("#in").onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const data = await api("login", Object.fromEntries(fd));
      user = data.user;
      paint();
    } catch (err) {
      authScreen(err.message);
    }
  };
  app.querySelector("#up").onclick = async () => {
    const fd = new FormData(app.querySelector("#in"));
    try {
      const data = await api("register", Object.fromEntries(fd));
      user = data.user;
      paint();
    } catch (err) {
      authScreen(err.message);
    }
  };
}

function homeScreen(error = "") {
  const skinButtons = boards.map((b) => `<button type="button" class="ghost" data-board="${b.id}">${b.name}${b.cost ? ` · ${b.cost}` : ""}</button>`).join("");
  app.innerHTML = `<main class="shell">
    <header class="top">
      <div>
        <p class="eyebrow">Morse Chess</p>
        <h1>${user.username}</h1>
        <p class="muted">${user.score} Elo · <span class="coins">${user.coins} Morse coins</span></p>
      </div>
      <button class="ghost" id="out" type="button">Sign out</button>
    </header>
    ${error ? `<p class="error">${error}</p>` : ""}
    <section class="panel stack">
      <div class="row">
        <button id="bot" type="button">Play MorseBot</button>
        <button id="host" class="ghost" type="button">Host a friend</button>
      </div>
      <form id="join" class="row">
        <input name="id" placeholder="Game code" style="max-width:12rem" />
        <button type="submit">Join</button>
      </form>
      <div>
        <p class="eyebrow">Boards</p>
        <div class="row">${skinButtons}</div>
      </div>
    </section>
  </main>`;
  app.querySelector("#out").onclick = async () => {
    await api("logout");
    user = null;
    paint();
  };
  app.querySelector("#bot").onclick = () => start("bot");
  app.querySelector("#host").onclick = () => start("host");
  app.querySelector("#join").onsubmit = async (e) => {
    e.preventDefault();
    try {
      const data = await api("join", { id: new FormData(e.target).get("id") });
      game = data.game;
      paint();
    } catch (err) {
      homeScreen(err.message);
    }
  };
  app.querySelectorAll("[data-board]").forEach((btn) => {
    btn.onclick = async () => {
      try {
        const data = await api("board", { id: btn.dataset.board });
        user = data.user;
        paint();
      } catch (err) {
        homeScreen(err.message);
      }
    };
  });
}

async function start(action) {
  try {
    const data = await api(action);
    game = data.game;
    paint();
  } catch (err) {
    homeScreen(err.message);
  }
}

function gameScreen(error = "") {
  const skin = boardSkin();
  const squares = parseFen(game.fen);
  const targets = new Set((game.moves || []).filter((m) => m.from === selected).map((m) => m.to));
  let cells = "";
  for (let i = 0; i < 64; i++) {
    const light = (Math.floor(i / 8) + (i % 8)) % 2 === 0;
    const sq = nameAt(i);
    const cls = [
      "sq",
      selected === sq ? "on" : "",
      game.lastFrom === sq || game.lastTo === sq ? "last" : "",
      targets.has(sq) ? "dot" : "",
    ].filter(Boolean).join(" ");
    cells += `<button type="button" class="${cls}" data-sq="${sq}" style="background:${light ? skin.light : skin.dark};color:${/[A-Z]/.test(squares[i]) ? "#f7f1e6" : "#1a140e"}">${glyph[squares[i]] || ""}</button>`;
  }
  const title = game.waiting ? `Code ${game.id}` : `${game.white} vs ${game.black || "…"}`;
  app.innerHTML = `<main class="shell">
    <header class="top">
      <div>
        <p class="eyebrow">${game.status === "active" ? (game.turn === "w" ? "White to move" : "Black to move") : game.status.replace("_", " ")}</p>
        <h1>${title}</h1>
        ${game.you === "s" ? `<p class="muted">Watching</p>` : ""}
        ${error ? `<p class="error">${error}</p>` : ""}
      </div>
      <div class="row">
        ${game.status === "active" && game.you !== "s" ? `<button class="ghost" id="resign" type="button">Resign</button>` : ""}
        <button class="ghost" id="leave" type="button">Lobby</button>
      </div>
    </header>
    <div class="play">
      <div class="board">${cells}</div>
      <aside class="panel">
        <p class="muted">${game.waiting ? "Send this code to a friend." : "Click a piece, then a square."}</p>
        ${promo ? `<div class="row" id="promo">${["q","r","b","n"].map((p) => `<button data-promo="${p}" type="button">${p.toUpperCase()}</button>`).join("")}</div>` : ""}
      </aside>
    </div>
  </main>`;
  app.querySelector("#leave").onclick = () => { game = null; promo = null; selected = ""; paint(); };
  const resign = app.querySelector("#resign");
  if (resign) resign.onclick = async () => {
    const data = await api("resign", { id: game.id });
    game = data.game;
    paint();
  };
  app.querySelectorAll("[data-sq]").forEach((btn) => {
    btn.onclick = () => choose(btn.dataset.sq);
  });
  app.querySelectorAll("[data-promo]").forEach((btn) => {
    btn.onclick = () => send(promo.from, promo.to, btn.dataset.promo);
  });
  armPoll();
}

async function poll() {
  if (!game) return;
  try {
    const data = await api("game", { id: game.id });
    if (!game || data.game.id !== game.id) return;
    const changed = data.game.fen !== game.fen || data.game.status !== game.status;
    game = data.game;
    if (changed) paint();
    else armPoll();
  } catch {
    /* ignore a missed poll */
  }
}

function choose(sq) {
  if (!game || game.status !== "active" || game.you !== game.turn) return;
  const hit = (game.moves || []).some((m) => m.from === selected && m.to === sq);
  if (hit) {
    const options = (game.moves || []).filter((m) => m.from === selected && m.to === sq);
    if (options.some((m) => m.promo)) {
      promo = { from: selected, to: sq };
      paint();
      return;
    }
    send(selected, sq, "");
    return;
  }
  selected = (game.moves || []).some((m) => m.from === sq) ? sq : "";
  promo = null;
  paint();
}

async function send(from, to, piece) {
  try {
    const data = await api("move", { id: game.id, from, to, promo: piece });
    game = data.game;
    selected = "";
    promo = null;
    paint();
  } catch (err) {
    gameScreen(err.message);
  }
}

api("me").then((data) => {
  user = data.user;
  boards = data.boards || [];
  paint();
}).catch(() => authScreen("The database is not ready. Run install.php."));
