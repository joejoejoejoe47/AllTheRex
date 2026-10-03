import { o as __toESM } from "../_runtime.mjs";
import { n as require_react } from "../_libs/@radix-ui/react-compose-refs+[...].mjs";
import { l as require_jsx_runtime } from "../_libs/@react-three/drei+[...].mjs";
import { _ as Link, v as Navigate } from "../_libs/@tanstack/react-router+[...].mjs";
import { o as formatClock } from "./mores-constants-BQLYM6Vi.mjs";
import { r as Undo2, s as Flag } from "../_libs/lucide-react.mjs";
import { a as SplashSkeleton, b as useCurrentUserState, h as resignGame, l as cn, m as makeMove, n as Button, s as claimTimeout, u as getGame } from "./use-current-user-D04QZakv.mjs";
import { n as Route$1 } from "./router-D6AYCLsI.mjs";
import { t as RedirectToSignIn } from "./gates-5VByONat.mjs";
//#region node_modules/.nitro/vite/services/ssr/assets/play._gameId-DHSa4Dyp.js
var import_react = /* @__PURE__ */ __toESM(require_react());
var import_jsx_runtime = require_jsx_runtime();
var ChessBoard3D = (0, import_react.lazy)(() => import("./board-3d-BNGXhsHB.mjs").then((m) => ({ default: m.ChessBoard3D })));
function playTone(freq, dur = .1, gain = .04) {
	try {
		const ctx = new AudioContext();
		const o = ctx.createOscillator();
		const g = ctx.createGain();
		o.type = "sine";
		o.frequency.value = freq;
		g.gain.setValueAtTime(gain, ctx.currentTime);
		g.gain.exponentialRampToValueAtTime(.001, ctx.currentTime + dur);
		o.connect(g).connect(ctx.destination);
		o.start();
		o.stop(ctx.currentTime + dur);
	} catch {}
}
function ResultOverlay({ game }) {
	const won = game.status === "white_win" && game.you === "w" || game.status === "black_win" && game.you === "b";
	const draw = game.status === "draw";
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
		className: "absolute inset-0 z-20 grid place-items-center bg-ink/75 px-5",
		children: /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
			className: "w-full max-w-md rounded-xl border border-line bg-panel p-8 text-center",
			children: [
				/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
					className: "text-xs uppercase tracking-[0.2em] text-mist",
					children: draw ? "Shared table" : won ? "The point is yours" : "The other board takes it"
				}),
				/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h2", {
					className: "mt-3 font-display text-5xl text-ivory",
					children: draw ? "Draw" : won ? "Victory" : "Defeat"
				}),
				/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
					className: "mt-4 text-sm text-mist",
					children: draw ? "Score unchanged." : won ? `You take 8 club points.` : `You drop 8 club points.`
				}),
				/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("p", {
					className: "mt-1 font-mono text-lg tabular-nums text-ivory",
					children: ["Score ", game.myScore]
				}),
				/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
					asChild: true,
					variant: "solid",
					className: "mt-6",
					children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Link, {
						to: "/",
						children: "Return to the lounge"
					})
				})
			]
		})
	});
}
function GameView({ gameId }) {
	const [game, setGame] = (0, import_react.useState)(null);
	const [error, setError] = (0, import_react.useState)(null);
	const [remain, setRemain] = (0, import_react.useState)(0);
	const lastSan = (0, import_react.useRef)(null);
	(0, import_react.useEffect)(() => {
		let live = true;
		const tick = async () => {
			try {
				const snap = await getGame({ data: { gameId } });
				if (!live) return;
				if (!snap) {
					setError("This board is not yours, or it no longer exists.");
					return;
				}
				setGame(snap);
				setError(null);
				setRemain(snap.remainingMs);
				if (snap.lastMove && snap.lastMove.san !== lastSan.current) {
					if (lastSan.current) playTone(snap.turn === snap.you ? 380 : 520, .09, .035);
					lastSan.current = snap.lastMove.san;
				}
			} catch (e) {
				if (live) setError(e instanceof Error ? e.message : "Lost the board connection.");
			}
		};
		tick();
		const id = window.setInterval(() => void tick(), 700);
		return () => {
			live = false;
			window.clearInterval(id);
		};
	}, [gameId]);
	(0, import_react.useEffect)(() => {
		if (!game || game.mode !== "timed" || game.status !== "active") return;
		const id = window.setInterval(() => {
			setRemain((r) => Math.max(0, r - 250));
		}, 250);
		return () => window.clearInterval(id);
	}, [
		game?.mode,
		game?.status,
		game?.turn,
		game?.fen
	]);
	(0, import_react.useEffect)(() => {
		if (!game || game.mode !== "timed" || game.status !== "active") return;
		if (remain > 0) return;
		claimTimeout({ data: { gameId } }).then((snap) => {
			if (snap) setGame(snap);
		});
	}, [
		remain,
		game,
		gameId
	]);
	async function onMove(from, to) {
		const res = await makeMove({ data: {
			gameId,
			from,
			to
		} });
		if (res.ok && res.game) {
			setGame(res.game);
			setRemain(res.game.remainingMs);
		}
	}
	if (error && !game) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("main", {
		className: "grid min-h-dvh place-items-center px-5",
		children: /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
			className: "max-w-sm text-center",
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
				className: "text-sm text-danger",
				children: error
			}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
				asChild: true,
				variant: "solid",
				className: "mt-4",
				children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Link, {
					to: "/",
					children: "Back to the lounge"
				})
			})]
		})
	});
	if (!game) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("main", {
		className: "grid min-h-dvh place-items-center",
		children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
			className: "text-sm text-mist",
			children: "Setting the board…"
		})
	});
	const myTurn = game.status === "active" && game.turn === game.you;
	const myName = game.you === "w" ? game.white.username : game.black.username;
	const opp = game.you === "w" ? game.black : game.white;
	const myClock = myTurn ? remain : game.mode === "timed" ? 6e4 : 0;
	const oppClock = !myTurn && game.status === "active" ? remain : game.mode === "timed" ? 6e4 : 0;
	const over = game.status !== "active";
	return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("main", {
		className: "relative flex h-dvh flex-col overflow-hidden bg-ink",
		children: [
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("header", {
				className: "flex items-center justify-between gap-3 px-4 py-3 sm:px-6",
				children: [
					/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Link, {
						to: "/",
						className: "text-xs uppercase tracking-[0.18em] text-mist hover:text-ivory",
						children: "Mores"
					}),
					/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
						className: "text-xs uppercase tracking-[0.16em] text-mist",
						children: game.mode === "timed" ? "Timed · 1 min / turn" : "Breeze · no clock"
					}),
					game.status === "active" ? /* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Button, {
						size: "sm",
						variant: "ghost",
						onClick: async () => {
							const snap = await resignGame({ data: { gameId } });
							if (snap) setGame(snap);
						},
						children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Flag, { className: "size-3.5" }), "Resign"]
					}) : /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
						asChild: true,
						size: "sm",
						variant: "ghost",
						children: /* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Link, {
							to: "/",
							children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Undo2, { className: "size-3.5" }), "Lounge"]
						})
					})
				]
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)(PlayerStrip, {
				name: opp.username,
				score: opp.score,
				color: game.you === "w" ? "Black" : "White",
				clock: game.mode === "timed" ? oppClock : null,
				active: !myTurn && !over,
				align: "top"
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
				className: "relative min-h-0 flex-1",
				children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
					className: "absolute inset-0",
					children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(import_react.Suspense, {
						fallback: /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
							className: "grid h-full place-items-center text-sm text-mist",
							children: "Setting the board…"
						}),
						children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(ChessBoard3D, {
							fen: game.fen,
							you: game.you,
							lastMove: game.lastMove,
							myTurn,
							onMove,
							disabled: over
						})
					})
				}), over ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)(ResultOverlay, { game }) : null]
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)(PlayerStrip, {
				name: myName,
				score: game.you === "w" ? game.white.score : game.black.score,
				color: game.you === "w" ? "White" : "Black",
				clock: game.mode === "timed" ? myClock : null,
				active: myTurn && !over,
				align: "bottom",
				moves: game.moves.map((m) => m.san).slice(-8)
			})
		]
	});
}
function PlayerStrip({ name, score, color, clock, active, moves }) {
	const low = clock !== null && clock <= 1e4 && active;
	return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
		className: "flex items-center justify-between gap-3 px-4 py-3 sm:px-6",
		children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", { children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("p", {
			className: "text-sm font-medium text-ivory",
			children: [
				name,
				" ",
				/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("span", {
					className: "font-sans text-xs font-normal text-mist",
					children: [
						color,
						" · ",
						score
					]
				})
			]
		}), moves && moves.length > 0 ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
			className: "mt-0.5 max-w-[55vw] truncate font-mono text-[11px] text-mist",
			children: moves.join("  ")
		}) : null] }), clock !== null ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
			className: cn("rounded-md border px-3 py-1.5 font-mono text-xl tabular-nums", active ? low ? "border-danger text-danger" : "border-line-strong bg-panel text-ivory" : "border-line text-mist"),
			children: formatClock(clock)
		}) : null]
	});
}
function PlayPage() {
	const { gameId } = Route$1.useParams();
	const { user, isPending } = useCurrentUserState();
	if (isPending) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(SplashSkeleton, {});
	if (!user) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(RedirectToSignIn, {});
	if (!gameId) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Navigate, { to: "/" });
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(GameView, { gameId });
}
//#endregion
export { PlayPage as component };
