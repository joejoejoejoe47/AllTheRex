import { o as __toESM } from "../_runtime.mjs";
import { n as require_react } from "../_libs/@radix-ui/react-compose-refs+[...].mjs";
import { l as require_jsx_runtime } from "../_libs/@react-three/drei+[...].mjs";
import { v as Navigate, y as useNavigate } from "../_libs/@tanstack/react-router+[...].mjs";
import { i as USERNAME_RE, t as BOT_USERNAME } from "./mores-constants-BQLYM6Vi.mjs";
import { a as Timer, n as Wind, o as Swords, t as X, u as Castle } from "../_libs/lucide-react.mjs";
import { _ as sendChallenge, a as SplashSkeleton, b as useCurrentUserState, c as claimUsername, d as getHomeState, f as joinQueue, g as respondChallenge, i as Label, l as cn, n as Button, o as cancelChallenge, p as leaveQueue, r as Input, t as AuthScreen, v as startBotGame } from "./use-current-user-D04QZakv.mjs";
import { n as UserButton } from "./gates-5VByONat.mjs";
//#region node_modules/.nitro/vite/services/ssr/assets/routes-DBpd9rS7.js
var import_react = /* @__PURE__ */ __toESM(require_react());
var import_jsx_runtime = require_jsx_runtime();
function Card({ className, ...props }) {
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
		className: cn("rounded-xl border border-line bg-panel p-6 text-ivory shadow-[0_18px_50px_-28px_rgba(0,0,0,0.65)]", className),
		...props
	});
}
function Badge({ className, ...props }) {
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
		className: cn("inline-flex items-center rounded-full border border-line bg-panel-2 px-2.5 py-1 text-xs font-medium text-cream", className),
		...props
	});
}
function ClubHome() {
	const navigate = useNavigate();
	const { user, isPending } = useCurrentUserState();
	const [home, setHome] = (0, import_react.useState)(null);
	const [flow, setFlow] = (0, import_react.useState)({ kind: "idle" });
	const [claim, setClaim] = (0, import_react.useState)("");
	const [target, setTarget] = (0, import_react.useState)("");
	const [error, setError] = (0, import_react.useState)(null);
	const [busy, setBusy] = (0, import_react.useState)(false);
	const [loadError, setLoadError] = (0, import_react.useState)(null);
	(0, import_react.useEffect)(() => {
		let live = true;
		const tick = async () => {
			try {
				const next = await getHomeState();
				if (!live) return;
				setHome(next);
				setLoadError(null);
				if (next.activeGameId) navigate({
					to: "/play/$gameId",
					params: { gameId: next.activeGameId }
				});
			} catch (e) {
				if (live) setLoadError(e instanceof Error ? e.message : "Could not load the club.");
			}
		};
		tick();
		const id = window.setInterval(() => void tick(), 1200);
		return () => {
			live = false;
			window.clearInterval(id);
		};
	}, [navigate]);
	if (isPending || !user) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(SplashSkeleton, {});
	if (!home) return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("main", {
		className: "mx-auto flex min-h-dvh max-w-5xl flex-col px-5 py-8",
		children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(SplashSkeleton, {}), loadError ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
			className: "mt-4 text-sm text-danger",
			children: loadError
		}) : null]
	});
	if (home.activeGameId) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Navigate, {
		to: "/play/$gameId",
		params: { gameId: home.activeGameId }
	});
	async function onClaim(e) {
		e.preventDefault();
		setError(null);
		if (!USERNAME_RE.test(claim.trim())) {
			setError("Club names are 3–20 letters, numbers, or underscores.");
			return;
		}
		setBusy(true);
		try {
			await claimUsername({ data: { username: claim.trim() } });
			setHome(await getHomeState());
		} catch (err) {
			setError(err instanceof Error ? err.message : "Could not claim that name.");
		} finally {
			setBusy(false);
		}
	}
	async function chooseMode(mode) {
		if (flow.kind !== "pick-mode") return;
		setError(null);
		if (flow.intent === "challenge") {
			setFlow({
				kind: "ask-name",
				mode
			});
			return;
		}
		setBusy(true);
		try {
			if (flow.intent === "bot") {
				const { gameId } = await startBotGame({ data: { mode } });
				await navigate({
					to: "/play/$gameId",
					params: { gameId }
				});
				return;
			}
			const { gameId } = await joinQueue({ data: { mode } });
			if (gameId) {
				await navigate({
					to: "/play/$gameId",
					params: { gameId }
				});
				return;
			}
			setFlow({
				kind: "searching",
				mode
			});
		} catch (err) {
			setError(err instanceof Error ? err.message : "Could not start a match.");
		} finally {
			setBusy(false);
		}
	}
	async function submitChallenge(e) {
		e.preventDefault();
		if (flow.kind !== "ask-name") return;
		setError(null);
		const name = target.trim();
		if (!USERNAME_RE.test(name)) {
			setError("Enter a club name (3–20 letters, numbers, or underscores).");
			return;
		}
		setBusy(true);
		try {
			const res = await sendChallenge({ data: {
				username: name,
				mode: flow.mode
			} });
			if (!res.ok) {
				setError(res.error);
				return;
			}
			if ("gameId" in res && res.gameId) {
				await navigate({
					to: "/play/$gameId",
					params: { gameId: res.gameId }
				});
				return;
			}
			setFlow({
				kind: "waiting",
				mode: flow.mode,
				username: name,
				challengeId: res.challengeId
			});
		} catch (err) {
			setError(err instanceof Error ? err.message : "Could not send that challenge.");
		} finally {
			setBusy(false);
		}
	}
	if (!home.profile) return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("main", {
		className: "mx-auto flex min-h-dvh max-w-lg flex-col justify-center px-5 py-10",
		children: [
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
				className: "text-xs uppercase tracking-[0.2em] text-mist",
				children: "Claim your seat"
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h1", {
				className: "mt-3 font-display text-4xl text-ivory",
				children: "Choose a club name"
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
				className: "mt-3 text-sm text-mist",
				children: "This is how other players challenge you. You can sign back in with it anytime."
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("form", {
				className: "mt-8 space-y-4",
				onSubmit: onClaim,
				children: [
					/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
						className: "space-y-2",
						children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Label, {
							htmlFor: "claim",
							children: "Club name"
						}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Input, {
							id: "claim",
							value: claim,
							autoFocus: true,
							onChange: (e) => setClaim(e.target.value),
							placeholder: "e.g. IvoryRook"
						})]
					}),
					error ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
						className: "text-sm text-danger",
						children: error
					}) : null,
					/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
						type: "submit",
						variant: "solid",
						className: "w-full",
						disabled: busy,
						children: busy ? "Saving…" : "Enter the lounge"
					})
				]
			})
		]
	});
	return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("main", {
		className: "mx-auto min-h-dvh w-full max-w-6xl px-5 py-6 sm:px-8",
		children: [
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("header", {
				className: "flex flex-wrap items-center justify-between gap-4",
				children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
					className: "flex items-center gap-3",
					children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
						className: "grid size-10 place-items-center rounded-md bg-forest text-ivory",
						children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Castle, {
							className: "size-5",
							strokeWidth: 1.75
						})
					}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", { children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
						className: "font-display text-xl font-semibold tracking-wordmark text-ivory",
						children: "MORES"
					}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("p", {
						className: "text-xs text-mist",
						children: ["Signed in as ", home.profile.username]
					})] })]
				}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
					className: "flex items-center gap-4",
					children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
						className: "rounded-md border border-line bg-panel px-3 py-2 text-right",
						children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
							className: "text-xs uppercase tracking-[0.16em] text-mist",
							children: "Club score"
						}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
							className: "font-mono text-lg tabular-nums text-ivory",
							children: home.profile.score
						})]
					}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
						className: "rounded-md border border-line bg-panel px-2 py-1",
						children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(UserButton, {})
					})]
				})]
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("section", {
				className: "mt-10",
				children: [
					/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
						className: "text-xs uppercase tracking-[0.2em] text-mist",
						children: "The lounge"
					}),
					/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h1", {
						className: "mt-2 max-w-2xl font-display text-4xl text-ivory",
						children: "Your board is waiting."
					}),
					/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
						className: "mt-3 max-w-xl text-sm text-mist",
						children: "Pull a random seat near your score, or send a named challenge. Timed games give each player one minute a turn. Win or lose, the club moves eight points."
					})
				]
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("section", {
				className: "mt-8 grid gap-4 md:grid-cols-2",
				children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("button", {
					type: "button",
					onClick: () => setFlow({
						kind: "pick-mode",
						intent: "random"
					}),
					className: "rounded-xl border border-line bg-panel p-6 text-left transition-[border-color] duration-200 hover:border-line-strong",
					children: [
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Swords, {
							className: "size-5 text-cream",
							strokeWidth: 1.75
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h2", {
							className: "mt-4 font-display text-2xl text-ivory",
							children: "Random pull-up"
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
							className: "mt-2 text-sm text-mist",
							children: "Sit across from a player near your score. If the lounge is quiet, MoresBot holds the other chair."
						})
					]
				}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("button", {
					type: "button",
					onClick: () => setFlow({
						kind: "pick-mode",
						intent: "challenge"
					}),
					className: "rounded-xl border border-line bg-panel p-6 text-left transition-[border-color] duration-200 hover:border-line-strong",
					children: [
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Castle, {
							className: "size-5 text-cream",
							strokeWidth: 1.75
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h2", {
							className: "mt-4 font-display text-2xl text-ivory",
							children: "Intended pull-up"
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
							className: "mt-2 text-sm text-mist",
							children: "Type a club name. They see that you want to battle, and a yes seats you both at once."
						})
					]
				})]
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("button", {
				type: "button",
				className: "mt-3 text-sm text-mist underline-offset-4 hover:text-ivory hover:underline",
				onClick: () => setFlow({
					kind: "pick-mode",
					intent: "bot"
				}),
				children: ["Warm up against ", BOT_USERNAME]
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("section", {
				className: "mt-10 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]",
				children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Card, {
					className: "min-h-48",
					children: [
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h2", {
							className: "font-display text-2xl",
							children: "Challenges"
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
							className: "mt-1 text-sm text-mist",
							children: "Messages land here the moment someone names you."
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
							className: "mt-5 space-y-3",
							children: [home.inbox.length === 0 ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
								className: "text-sm text-mist",
								children: "No one is waiting on you right now."
							}) : home.inbox.map((c) => /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
								className: "flex flex-col gap-3 rounded-md border border-line bg-ink-soft p-4 sm:flex-row sm:items-center sm:justify-between",
								children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("p", {
									className: "text-sm text-ivory",
									children: [
										/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
											className: "font-medium",
											children: c.fromUsername
										}),
										" wants to battle you in chess",
										" ",
										c.mode,
										"."
									]
								}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
									className: "flex gap-2",
									children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
										size: "sm",
										variant: "solid",
										onClick: async () => {
											const res = await respondChallenge({ data: {
												id: c.id,
												accept: true
											} });
											if (res.ok && res.gameId) await navigate({
												to: "/play/$gameId",
												params: { gameId: res.gameId }
											});
										},
										children: "Yes"
									}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
										size: "sm",
										variant: "secondary",
										onClick: () => respondChallenge({ data: {
											id: c.id,
											accept: false
										} }),
										children: "Decline"
									})]
								})]
							}, c.id)), home.outgoing.filter((c) => c.status === "pending").map((c) => /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
								className: "flex items-center justify-between gap-3 rounded-md border border-line p-4",
								children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("p", {
									className: "text-sm text-mist",
									children: [
										"Waiting on ",
										c.toUsername,
										" for a ",
										c.mode,
										" game."
									]
								}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
									size: "sm",
									variant: "ghost",
									onClick: () => cancelChallenge({ data: { id: c.id } }),
									children: "Cancel"
								})]
							}, c.id))]
						})
					]
				}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
					className: "space-y-4",
					children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Card, { children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h2", {
						className: "font-display text-2xl",
						children: "In the room"
					}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
						className: "mt-4 space-y-2",
						children: home.online.length === 0 ? /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("p", {
							className: "text-sm text-mist",
							children: [
								"The other chairs are empty. Challenge ",
								BOT_USERNAME,
								" or wait."
							]
						}) : home.online.map((p) => /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("button", {
							type: "button",
							className: "flex w-full items-center justify-between rounded-md px-2 py-2 text-left hover:bg-panel-2",
							onClick: () => {
								setTarget(p.username);
								setFlow({
									kind: "ask-name",
									mode: "timed"
								});
							},
							children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
								className: "text-sm text-ivory",
								children: p.username
							}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
								className: "font-mono text-xs tabular-nums text-mist",
								children: p.score
							})]
						}, p.username))
					})] }), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Card, { children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h2", {
						className: "font-display text-2xl",
						children: "Standings"
					}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("ol", {
						className: "mt-4 space-y-2",
						children: home.leaders.map((p, i) => /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("li", {
							className: "flex items-center justify-between text-sm",
							children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("span", {
								className: "text-mist",
								children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
									className: "mr-2 font-mono tabular-nums text-cream",
									children: i + 1
								}), p.username]
							}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
								className: "font-mono tabular-nums text-ivory",
								children: p.score
							})]
						}, p.username))
					})] })]
				})]
			}),
			(flow.kind !== "idle" || home.queued) && /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
				className: "fixed inset-0 z-40 grid place-items-end bg-ink/70 p-4 sm:place-items-center",
				children: /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
					className: "w-full max-w-md rounded-xl border border-line bg-panel p-6",
					children: [
						/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
							className: "flex items-start justify-between gap-3",
							children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h2", {
								className: "font-display text-2xl text-ivory",
								children: flow.kind === "pick-mode" ? "How do you want to play?" : flow.kind === "ask-name" ? "Who is sitting across?" : flow.kind === "searching" || home.queued ? "Finding a board" : "Challenge sent"
							}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("button", {
								type: "button",
								className: "grid size-9 place-items-center rounded-md text-mist hover:bg-panel-2 hover:text-ivory",
								onClick: async () => {
									if (home.queued) await leaveQueue();
									setFlow({ kind: "idle" });
									setError(null);
								},
								"aria-label": "Close",
								children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(X, { className: "size-4" })
							})]
						}),
						flow.kind === "pick-mode" ? /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
							className: "mt-5 grid gap-3",
							children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Button, {
								variant: "solid",
								size: "lg",
								className: "h-auto justify-start py-4",
								disabled: busy,
								onClick: () => chooseMode("timed"),
								children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Timer, { className: "size-4" }), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("span", {
									className: "text-left",
									children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
										className: "block",
										children: "Timed"
									}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
										className: "block text-xs font-normal text-ink/70",
										children: "One minute each turn"
									})]
								})]
							}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Button, {
								variant: "secondary",
								size: "lg",
								className: "h-auto justify-start py-4",
								disabled: busy,
								onClick: () => chooseMode("breeze"),
								children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Wind, { className: "size-4" }), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("span", {
									className: "text-left",
									children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
										className: "block",
										children: "Breeze"
									}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
										className: "block text-xs font-normal text-mist",
										children: "No clock. Think as long as you like."
									})]
								})]
							})]
						}) : null,
						flow.kind === "ask-name" ? /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("form", {
							className: "mt-5 space-y-4",
							onSubmit: submitChallenge,
							children: [
								/* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Badge, { children: [flow.mode, " game"] }),
								/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
									className: "space-y-2",
									children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Label, {
										htmlFor: "opp",
										children: "Club name"
									}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Input, {
										id: "opp",
										autoFocus: true,
										value: target,
										onChange: (e) => setTarget(e.target.value),
										placeholder: "Type their club name"
									})]
								}),
								error ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
									className: "text-sm text-danger",
									children: error
								}) : null,
								/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
									type: "submit",
									variant: "solid",
									className: "w-full",
									disabled: busy,
									children: busy ? "Sending…" : "Send challenge"
								})
							]
						}) : null,
						flow.kind === "searching" || home.queued ? /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
							className: "mt-5 space-y-3",
							children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("p", {
								className: "text-sm text-mist",
								children: [
									"Looking for a player near ",
									home.profile.score,
									flow.kind === "searching" ? ` in ${flow.mode}` : "",
									". If no one sits down, you will be seated with ",
									"MoresBot",
									"."
								]
							}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
								className: "h-1 overflow-hidden rounded-full bg-ink-soft",
								children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", { className: "h-full w-1/2 animate-pulse bg-forest" })
							})]
						}) : null,
						flow.kind === "waiting" ? /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("p", {
							className: "mt-5 text-sm text-mist",
							children: [
								home.profile.username,
								" wants to battle ",
								flow.username,
								" in chess ",
								flow.mode,
								". Waiting for their yes."
							]
						}) : null,
						error && flow.kind === "pick-mode" ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
							className: "mt-3 text-sm text-danger",
							children: error
						}) : null
					]
				})
			})
		]
	});
}
function Home() {
	const { user, isPending } = useCurrentUserState();
	if (isPending) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(SplashSkeleton, {});
	if (!user) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(AuthScreen, {});
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(ClubHome, {});
}
//#endregion
export { Home as component };
