import { t as Chess } from "../_libs/chess.js.mjs";
import { i as TSS_SERVER_FUNCTION, r as createServerFn } from "./ssr.mjs";
import { r as getSql } from "./db-CyyeQ0mh.mjs";
import { a as authMiddleware, i as USERNAME_RE, n as BOT_USER_ID, r as TURN_MS } from "./mores-constants-BQLYM6Vi.mjs";
//#region node_modules/.nitro/vite/services/ssr/assets/mores-Bso3o0n6.js
var createServerRpc = (serverFnMeta, splitImportFn) => {
	const url = "/_serverFn/" + serverFnMeta.id;
	return Object.assign(splitImportFn, {
		url,
		serverFnMeta,
		[TSS_SERVER_FUNCTION]: true
	});
};
var PIECE_VAL = {
	p: 100,
	n: 320,
	b: 330,
	r: 500,
	q: 900,
	k: 0
};
function asTime(v) {
	if (typeof v === "number" && Number.isFinite(v)) return v;
	if (v instanceof Date) return v.getTime();
	if (typeof v === "string") {
		const n = Date.parse(v);
		return Number.isFinite(n) ? n : Date.now();
	}
	return Date.now();
}
function parseMode(v) {
	return v === "breeze" ? "breeze" : "timed";
}
function newId() {
	return crypto.randomUUID();
}
function cleanUsername(raw) {
	const username = raw.trim();
	if (!USERNAME_RE.test(username)) throw new Error("Club names are 3–20 letters, numbers, or underscores.");
	return username;
}
async function profileById(sql, userId) {
	return (await sql`
    select user_id, username, username_lc, score from profiles where user_id = ${userId} limit 1
  `)[0] ?? null;
}
async function touchProfile(sql, userId) {
	await sql`update profiles set last_seen = now() where user_id = ${userId}`;
}
async function insertGame(sql, a, b, mode) {
	const id = newId();
	const aWhite = Math.random() < .5;
	await sql`
    insert into games (
      id, white_user_id, black_user_id, mode, fen, status, turn, turn_started_at
    ) values (
      ${id}, ${aWhite ? a : b}, ${aWhite ? b : a}, ${mode}, ${new Chess().fen()}, 'active', 'w', ${(/* @__PURE__ */ new Date()).toISOString()}
    )
  `;
	return id;
}
async function loadGame(sql, gameId) {
	return (await sql`select * from games where id = ${gameId} limit 1`)[0] ?? null;
}
async function applyScore(sql, winnerId, loserId) {
	if (winnerId !== "bot-mores") await sql`update profiles set score = score + ${8} where user_id = ${winnerId}`;
	if (loserId !== "bot-mores") await sql`update profiles set score = greatest(0, score - ${8}) where user_id = ${loserId}`;
}
async function finishGame(sql, game, status, winnerUserId) {
	if (game.status !== "active") return;
	const scored = status !== "draw";
	await sql`
    update games
    set status = ${status},
        winner_user_id = ${winnerUserId},
        scored = ${scored}
    where id = ${game.id} and status = 'active'
  `;
	if (scored && winnerUserId) await applyScore(sql, winnerUserId, winnerUserId === game.white_user_id ? game.black_user_id : game.white_user_id);
	game.status = status;
	game.winner_user_id = winnerUserId;
	game.scored = scored;
}
function evaluate(chess, bot) {
	if (chess.isCheckmate()) return chess.turn() === bot ? -1e5 : 1e5;
	if (chess.isDraw() || chess.isStalemate()) return 0;
	let s = 0;
	for (const row of chess.board()) for (const p of row) {
		if (!p) continue;
		const v = PIECE_VAL[p.type] ?? 0;
		s += p.color === bot ? v : -v;
	}
	const mobility = chess.moves().length;
	s += chess.turn() === bot ? mobility * 2 : -mobility * 2;
	if (chess.isCheck()) s += chess.turn() === bot ? -40 : 40;
	return s;
}
function pickBotMove(fen) {
	const chess = new Chess(fen);
	const bot = chess.turn();
	const moves = chess.moves({ verbose: true });
	if (!moves.length) return null;
	let best = -Infinity;
	const top = [];
	for (const m of moves) {
		chess.move(m);
		const score = evaluate(chess, bot);
		chess.undo();
		if (score > best + 8) {
			best = score;
			top.length = 0;
			top.push(m);
		} else if (score >= best - 8) {
			if (score > best) best = score;
			top.push(m);
		}
	}
	const pick = top[Math.floor(Math.random() * top.length)] ?? moves[0];
	return {
		from: pick.from,
		to: pick.to,
		promotion: pick.promotion
	};
}
async function recordAndApplyMove(sql, game, from, to, promotion) {
	const chess = new Chess(game.fen);
	const needsPromo = chess.get(from)?.type === "p" && (to.endsWith("8") || to.endsWith("1"));
	const moved = chess.move({
		from,
		to,
		promotion: needsPromo ? promotion ?? "q" : void 0
	});
	if (!moved) return {
		ok: false,
		error: "That move is not legal."
	};
	const ply = ((await sql`select count(*)::int as c from game_moves where game_id = ${game.id}`)[0]?.c ?? 0) + 1;
	await sql`
    insert into game_moves (game_id, ply, san, from_sq, to_sq)
    values (${game.id}, ${ply}, ${moved.san}, ${moved.from}, ${moved.to})
  `;
	let status = "active";
	let winner = null;
	if (chess.isCheckmate()) {
		status = moved.color === "w" ? "white_win" : "black_win";
		winner = moved.color === "w" ? game.white_user_id : game.black_user_id;
	} else if (chess.isDraw() || chess.isStalemate()) status = "draw";
	const now = (/* @__PURE__ */ new Date()).toISOString();
	await sql`
    update games set
      fen = ${chess.fen()},
      turn = ${chess.turn()},
      last_move_from = ${moved.from},
      last_move_to = ${moved.to},
      last_move_san = ${moved.san},
      turn_started_at = ${now},
      status = ${status},
      winner_user_id = ${winner},
      scored = ${status !== "active" && status !== "draw"}
    where id = ${game.id}
  `;
	game.fen = chess.fen();
	game.turn = chess.turn();
	game.last_move_from = moved.from;
	game.last_move_to = moved.to;
	game.last_move_san = moved.san;
	game.turn_started_at = now;
	game.status = status;
	game.winner_user_id = winner;
	if (status === "white_win" || status === "black_win") {
		const loser = winner === game.white_user_id ? game.black_user_id : game.white_user_id;
		if (winner) await applyScore(sql, winner, loser);
		game.scored = true;
	}
	return { ok: true };
}
async function maybeTimeout(sql, game) {
	if (game.status !== "active" || game.mode !== "timed") return;
	if (6e4 - (Date.now() - asTime(game.turn_started_at)) > 0) return;
	const winner = game.turn === "w" ? game.black_user_id : game.white_user_id;
	await finishGame(sql, game, game.turn === "w" ? "black_win" : "white_win", winner);
}
async function maybeBotMove(sql, game) {
	if (game.status !== "active") return;
	const botSide = game.white_user_id === "bot-mores" ? "w" : game.black_user_id === "bot-mores" ? "b" : null;
	if (!botSide || game.turn !== botSide) return;
	if (Date.now() - asTime(game.turn_started_at) < 650) return;
	const pick = pickBotMove(game.fen);
	if (!pick) {
		if (new Chess(game.fen).isCheckmate()) {
			const winner = botSide === "w" ? game.black_user_id : game.white_user_id;
			await finishGame(sql, game, botSide === "w" ? "black_win" : "white_win", winner);
		} else await finishGame(sql, game, "draw", null);
		return;
	}
	await recordAndApplyMove(sql, game, pick.from, pick.to, pick.promotion);
}
async function snapshotFor(sql, game, userId) {
	if (game.white_user_id !== userId && game.black_user_id !== userId) return null;
	await maybeTimeout(sql, game);
	await maybeBotMove(sql, game);
	const fresh = await loadGame(sql, game.id) ?? game;
	const [white, black] = await Promise.all([profileById(sql, fresh.white_user_id), profileById(sql, fresh.black_user_id)]);
	const moves = await sql`
    select san, from_sq, to_sq from game_moves where game_id = ${fresh.id} order by ply asc
  `;
	const you = fresh.white_user_id === userId ? "w" : "b";
	const remainingMs = fresh.mode === "timed" && fresh.status === "active" ? Math.max(0, TURN_MS - (Date.now() - asTime(fresh.turn_started_at))) : TURN_MS;
	const me = you === "w" ? white : black;
	const opp = you === "w" ? black : white;
	return {
		id: fresh.id,
		fen: fresh.fen,
		mode: parseMode(fresh.mode),
		status: fresh.status,
		turn: fresh.turn,
		you,
		white: {
			userId: fresh.white_user_id,
			username: white?.username ?? "White",
			score: white?.score ?? 100
		},
		black: {
			userId: fresh.black_user_id,
			username: black?.username ?? "Black",
			score: black?.score ?? 100
		},
		lastMove: fresh.last_move_from && fresh.last_move_to && fresh.last_move_san ? {
			from: fresh.last_move_from,
			to: fresh.last_move_to,
			san: fresh.last_move_san
		} : null,
		remainingMs,
		serverNow: Date.now(),
		moves: moves.map((m) => ({
			san: m.san,
			from: m.from_sq,
			to: m.to_sq
		})),
		winnerUserId: fresh.winner_user_id,
		myScore: me?.score ?? 100,
		opponentName: opp?.username ?? "Opponent"
	};
}
async function tryMatch(sql, userId, score, mode, joinedAt) {
	const wait = Date.now() - asTime(joinedAt);
	const window = wait > 7e3 ? 4e3 : wait > 3500 ? 180 : 70;
	const opp = (await sql`
    select user_id, score from match_queue
    where user_id <> ${userId} and mode = ${mode}
    order by joined_at asc
  `).find((r) => Math.abs(Number(r.score) - score) <= window);
	if (opp) {
		if ((await sql`
      delete from match_queue where user_id = ${opp.user_id} returning user_id
    `).length) {
			await sql`delete from match_queue where user_id = ${userId}`;
			return insertGame(sql, userId, opp.user_id, mode);
		}
	}
	if (wait > 8e3) {
		await sql`delete from match_queue where user_id = ${userId}`;
		return insertGame(sql, userId, BOT_USER_ID, mode);
	}
	return null;
}
var usernameAvailable_createServerFn_handler = createServerRpc({
	id: "f9bbfccd8ba059e72ea6f269738cf2f9dcbbcfa74aaa903f36270b0bcdff5467",
	name: "usernameAvailable",
	filename: "src/lib/server/mores.ts"
}, (opts) => usernameAvailable.__executeServer(opts));
var usernameAvailable = createServerFn({ method: "POST" }).validator((input) => ({ username: cleanUsername(input.username) })).handler(usernameAvailable_createServerFn_handler, async ({ data }) => {
	return (await (await getSql())`
      select 1 from profiles where username_lc = ${data.username.toLowerCase()} limit 1
    `).length === 0;
});
var claimUsername_createServerFn_handler = createServerRpc({
	id: "e7b84f0a89834b83d322c5c94efd86b4d499aecf20789501eb35fe92387b1ea6",
	name: "claimUsername",
	filename: "src/lib/server/mores.ts"
}, (opts) => claimUsername.__executeServer(opts));
var claimUsername = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ username: cleanUsername(input.username) })).handler(claimUsername_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	const existing = await profileById(sql, context.userId);
	if (existing) return {
		username: existing.username,
		score: existing.score
	};
	try {
		await sql`
        insert into profiles (user_id, username, username_lc, score)
        values (${context.userId}, ${data.username}, ${data.username.toLowerCase()}, ${100})
      `;
	} catch {
		throw new Error("That club name is already taken.");
	}
	return {
		username: data.username,
		score: 100
	};
});
var getHomeState_createServerFn_handler = createServerRpc({
	id: "9a2d41241098c96f04fce7f46c94343b47f358009f14f07a9545eefa1c012bfc",
	name: "getHomeState",
	filename: "src/lib/server/mores.ts"
}, (opts) => getHomeState.__executeServer(opts));
var getHomeState = createServerFn({ method: "GET" }).middleware([authMiddleware]).handler(getHomeState_createServerFn_handler, async ({ context }) => {
	const sql = await getSql();
	const profile = await profileById(sql, context.userId);
	if (!profile) return {
		profile: null,
		inbox: [],
		outgoing: [],
		activeGameId: null,
		queued: false,
		queueMode: null,
		online: [],
		leaders: []
	};
	await touchProfile(sql, context.userId);
	const active = await sql`
      select id from games
      where status = 'active' and (white_user_id = ${context.userId} or black_user_id = ${context.userId})
      order by created_at desc
      limit 1
    `;
	const q = await sql`
      select mode, joined_at from match_queue where user_id = ${context.userId} limit 1
    `;
	let queued = q.length > 0;
	let queueMode = q[0] ? parseMode(q[0].mode) : null;
	let matched = null;
	if (q[0]) {
		matched = await tryMatch(sql, context.userId, Number(profile.score), parseMode(q[0].mode), q[0].joined_at);
		if (matched) {
			queued = false;
			queueMode = null;
		}
	}
	const inboxRows = await sql`
      select c.id, c.mode, c.status, c.created_at, p.username
      from challenges c
      join profiles p on p.user_id = c.from_user_id
      where c.to_user_id = ${context.userId} and c.status = 'pending'
      order by c.created_at desc
    `;
	const outRows = await sql`
      select c.id, c.mode, c.status, c.created_at, p.username
      from challenges c
      join profiles p on p.user_id = c.to_user_id
      where c.from_user_id = ${context.userId} and c.status in ('pending', 'declined')
      order by c.created_at desc
      limit 8
    `;
	const online = await sql`
      select username, score from profiles
      where user_id <> ${context.userId}
        and user_id <> ${BOT_USER_ID}
        and last_seen > now() - interval '20 seconds'
      order by score desc
      limit 12
    `;
	const leaders = await sql`
      select username, score from profiles
      order by score desc, created_at asc
      limit 8
    `;
	return {
		profile: {
			username: profile.username,
			score: Number(profile.score)
		},
		inbox: inboxRows.map((r) => ({
			id: r.id,
			fromUsername: r.username,
			toUsername: profile.username,
			mode: parseMode(r.mode),
			status: r.status,
			createdAt: String(r.created_at)
		})),
		outgoing: outRows.map((r) => ({
			id: r.id,
			fromUsername: profile.username,
			toUsername: r.username,
			mode: parseMode(r.mode),
			status: r.status,
			createdAt: String(r.created_at)
		})),
		activeGameId: matched ?? active[0]?.id ?? null,
		queued,
		queueMode,
		online: online.map((r) => ({
			username: r.username,
			score: Number(r.score)
		})),
		leaders: leaders.map((r) => ({
			username: r.username,
			score: Number(r.score)
		}))
	};
});
var joinQueue_createServerFn_handler = createServerRpc({
	id: "6a75e6876619562a78cc7bfa652abff67c9e93b6a451c9f33374b91988605ba6",
	name: "joinQueue",
	filename: "src/lib/server/mores.ts"
}, (opts) => joinQueue.__executeServer(opts));
var joinQueue = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ mode: parseMode(input.mode) })).handler(joinQueue_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	const profile = await profileById(sql, context.userId);
	if (!profile) throw new Error("Choose a club name first.");
	const active = await sql`
      select id from games
      where status = 'active' and (white_user_id = ${context.userId} or black_user_id = ${context.userId})
      limit 1
    `;
	if (active[0]) return { gameId: active[0].id };
	await sql`
      insert into match_queue (user_id, score, mode, joined_at)
      values (${context.userId}, ${profile.score}, ${data.mode}, ${(/* @__PURE__ */ new Date()).toISOString()})
      on conflict (user_id) do update set score = excluded.score, mode = excluded.mode, joined_at = excluded.joined_at
    `;
	return { gameId: await tryMatch(sql, context.userId, Number(profile.score), data.mode, (/* @__PURE__ */ new Date()).toISOString()) };
});
var leaveQueue_createServerFn_handler = createServerRpc({
	id: "09d96d1150fde2165be796c75755b8b49ddf9ad8b4e3fbda72d0a4431ead4b62",
	name: "leaveQueue",
	filename: "src/lib/server/mores.ts"
}, (opts) => leaveQueue.__executeServer(opts));
var leaveQueue = createServerFn({ method: "POST" }).middleware([authMiddleware]).handler(leaveQueue_createServerFn_handler, async ({ context }) => {
	await (await getSql())`delete from match_queue where user_id = ${context.userId}`;
	return { ok: true };
});
var sendChallenge_createServerFn_handler = createServerRpc({
	id: "9af9353ab470718ff38d1a1f92f9dc8771c9be04d9b6b40b5e8212ef84512b3a",
	name: "sendChallenge",
	filename: "src/lib/server/mores.ts"
}, (opts) => sendChallenge.__executeServer(opts));
var sendChallenge = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({
	username: cleanUsername(input.username),
	mode: parseMode(input.mode)
})).handler(sendChallenge_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	if (!await profileById(sql, context.userId)) throw new Error("Choose a club name first.");
	const active = await sql`
      select id from games
      where status = 'active' and (white_user_id = ${context.userId} or black_user_id = ${context.userId})
      limit 1
    `;
	if (active[0]) return {
		ok: true,
		gameId: active[0].id
	};
	const to = (await sql`
      select user_id, username, username_lc, score from profiles
      where username_lc = ${data.username.toLowerCase()}
      limit 1
    `)[0];
	if (!to) return {
		ok: false,
		error: "No player with that club name."
	};
	if (to.user_id === context.userId) return {
		ok: false,
		error: "You cannot challenge yourself."
	};
	if (to.user_id === "bot-mores") return {
		ok: true,
		gameId: await insertGame(sql, context.userId, BOT_USER_ID, data.mode)
	};
	if ((await sql`
      select 1 from challenges
      where from_user_id = ${context.userId} and to_user_id = ${to.user_id} and status = 'pending'
      limit 1
    `).length) return {
		ok: false,
		error: "You already sent them a challenge."
	};
	const id = newId();
	await sql`
      insert into challenges (id, from_user_id, to_user_id, mode, status)
      values (${id}, ${context.userId}, ${to.user_id}, ${data.mode}, 'pending')
    `;
	return {
		ok: true,
		challengeId: id
	};
});
var respondChallenge_createServerFn_handler = createServerRpc({
	id: "876ee21a444fca322964d37f9beb4788821085e46e7bbe6a95daea3a38c21bfd",
	name: "respondChallenge",
	filename: "src/lib/server/mores.ts"
}, (opts) => respondChallenge.__executeServer(opts));
var respondChallenge = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({
	id: String(input.id),
	accept: Boolean(input.accept)
})).handler(respondChallenge_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	const ch = (await sql`
      select id, from_user_id, to_user_id, mode, status from challenges where id = ${data.id} limit 1
    `)[0];
	if (!ch || ch.to_user_id !== context.userId) return {
		ok: false,
		error: "Challenge not found."
	};
	if (ch.status !== "pending") return {
		ok: false,
		error: "That challenge is no longer open."
	};
	if (!data.accept) {
		await sql`update challenges set status = 'declined' where id = ${ch.id}`;
		return { ok: true };
	}
	const gameId = await insertGame(sql, ch.from_user_id, ch.to_user_id, parseMode(ch.mode));
	await sql`update challenges set status = 'accepted', game_id = ${gameId} where id = ${ch.id}`;
	return {
		ok: true,
		gameId
	};
});
var cancelChallenge_createServerFn_handler = createServerRpc({
	id: "a3e78aeedab9e23dfbf856fa98b10c2f61df529d43f7418d347beda98b42abde",
	name: "cancelChallenge",
	filename: "src/lib/server/mores.ts"
}, (opts) => cancelChallenge.__executeServer(opts));
var cancelChallenge = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ id: String(input.id) })).handler(cancelChallenge_createServerFn_handler, async ({ context, data }) => {
	await (await getSql())`
      update challenges set status = 'cancelled'
      where id = ${data.id} and from_user_id = ${context.userId} and status = 'pending'
    `;
	return { ok: true };
});
var getGame_createServerFn_handler = createServerRpc({
	id: "bc6a93cf580d4bf4e05b959ced019d6095a766d712bc829aefe3dba5d4fa4c0c",
	name: "getGame",
	filename: "src/lib/server/mores.ts"
}, (opts) => getGame.__executeServer(opts));
var getGame = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ gameId: String(input.gameId) })).handler(getGame_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	const game = await loadGame(sql, data.gameId);
	if (!game) return null;
	return snapshotFor(sql, game, context.userId);
});
var makeMove_createServerFn_handler = createServerRpc({
	id: "ed8e70a5b8c8349b4604dd5168a1c1d05b46e9c4a1a72cf35dba2592ed4b4eec",
	name: "makeMove",
	filename: "src/lib/server/mores.ts"
}, (opts) => makeMove.__executeServer(opts));
var makeMove = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({
	gameId: String(input.gameId),
	from: String(input.from),
	to: String(input.to),
	promotion: input.promotion
})).handler(makeMove_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	const game = await loadGame(sql, data.gameId);
	if (!game) return {
		ok: false,
		error: "Game not found."
	};
	if (game.status !== "active") return {
		ok: false,
		error: "This game is over."
	};
	await maybeTimeout(sql, game);
	if (game.status !== "active") return {
		ok: false,
		error: "Time expired.",
		game: await snapshotFor(sql, game, context.userId)
	};
	const side = game.white_user_id === context.userId ? "w" : game.black_user_id === context.userId ? "b" : "w";
	if (game.white_user_id !== context.userId && game.black_user_id !== context.userId) return {
		ok: false,
		error: "You are not seated at this board."
	};
	if (game.turn !== side) return {
		ok: false,
		error: "Wait for your turn."
	};
	const applied = await recordAndApplyMove(sql, game, data.from, data.to, data.promotion);
	if (!applied.ok) return applied;
	return {
		ok: true,
		game: await snapshotFor(sql, game, context.userId)
	};
});
var claimTimeout_createServerFn_handler = createServerRpc({
	id: "34159b28857eb890ed5d571c23e70c035989407f6d2ff68a8943e90c4f42c987",
	name: "claimTimeout",
	filename: "src/lib/server/mores.ts"
}, (opts) => claimTimeout.__executeServer(opts));
var claimTimeout = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ gameId: String(input.gameId) })).handler(claimTimeout_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	const game = await loadGame(sql, data.gameId);
	if (!game) return null;
	await maybeTimeout(sql, game);
	return snapshotFor(sql, game, context.userId);
});
var resignGame_createServerFn_handler = createServerRpc({
	id: "27c7e8f042e54d5a21aa768197287a4e143bf967af6d931a30741d9d495010c0",
	name: "resignGame",
	filename: "src/lib/server/mores.ts"
}, (opts) => resignGame.__executeServer(opts));
var resignGame = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ gameId: String(input.gameId) })).handler(resignGame_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	const game = await loadGame(sql, data.gameId);
	if (!game) return null;
	if (game.white_user_id !== context.userId && game.black_user_id !== context.userId) return null;
	if (game.status === "active") {
		const winner = game.white_user_id === context.userId ? game.black_user_id : game.white_user_id;
		await finishGame(sql, game, winner === game.white_user_id ? "white_win" : "black_win", winner);
	}
	return snapshotFor(sql, game, context.userId);
});
var startBotGame_createServerFn_handler = createServerRpc({
	id: "b8cc246b7127b93f89d3224c5da34615747feb1f23b05cbb40febf0ac8140770",
	name: "startBotGame",
	filename: "src/lib/server/mores.ts"
}, (opts) => startBotGame.__executeServer(opts));
var startBotGame = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ mode: parseMode(input.mode) })).handler(startBotGame_createServerFn_handler, async ({ context, data }) => {
	const sql = await getSql();
	if (!await profileById(sql, context.userId)) throw new Error("Choose a club name first.");
	const active = await sql`
      select id from games
      where status = 'active' and (white_user_id = ${context.userId} or black_user_id = ${context.userId})
      limit 1
    `;
	if (active[0]) return { gameId: active[0].id };
	return { gameId: await insertGame(sql, context.userId, BOT_USER_ID, data.mode) };
});
//#endregion
export { cancelChallenge_createServerFn_handler, claimTimeout_createServerFn_handler, claimUsername_createServerFn_handler, getGame_createServerFn_handler, getHomeState_createServerFn_handler, joinQueue_createServerFn_handler, leaveQueue_createServerFn_handler, makeMove_createServerFn_handler, resignGame_createServerFn_handler, respondChallenge_createServerFn_handler, sendChallenge_createServerFn_handler, startBotGame_createServerFn_handler, usernameAvailable_createServerFn_handler };
