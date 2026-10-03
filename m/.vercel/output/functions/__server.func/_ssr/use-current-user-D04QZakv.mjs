import { o as __toESM } from "../_runtime.mjs";
import { n as require_react } from "../_libs/@radix-ui/react-compose-refs+[...].mjs";
import { l as require_jsx_runtime } from "../_libs/@react-three/drei+[...].mjs";
import { y as useNavigate } from "../_libs/@tanstack/react-router+[...].mjs";
import { a as getServerFnById, i as TSS_SERVER_FUNCTION, r as createServerFn } from "./ssr.mjs";
import { r as signIn, t as authClient } from "./client-B40BzJxt.mjs";
import { t as GROK_PROVIDERS } from "./server-BLl2FlXA.mjs";
import { a as authMiddleware, i as USERNAME_RE, s as usernameToEmail } from "./mores-constants-BQLYM6Vi.mjs";
import { c as Eye, l as EyeOff, u as Castle } from "../_libs/lucide-react.mjs";
import { n as clsx, t as cva } from "../_libs/class-variance-authority+clsx.mjs";
import { t as Slot } from "../_libs/radix-ui__react-slot.mjs";
import { t as twMerge } from "../_libs/tailwind-merge.mjs";
//#region node_modules/.nitro/vite/services/ssr/assets/use-current-user-D04QZakv.js
var import_react = /* @__PURE__ */ __toESM(require_react());
var import_jsx_runtime = require_jsx_runtime();
var createSsrRpc = (functionId) => {
	const url = "/_serverFn/" + functionId;
	const serverFnMeta = { id: functionId };
	const fn = async (...args) => {
		return (await getServerFnById(functionId, { origin: "server" }))(...args);
	};
	return Object.assign(fn, {
		url,
		serverFnMeta,
		[TSS_SERVER_FUNCTION]: true
	});
};
function parseMode(v) {
	return v === "breeze" ? "breeze" : "timed";
}
function cleanUsername(raw) {
	const username = raw.trim();
	if (!USERNAME_RE.test(username)) throw new Error("Club names are 3–20 letters, numbers, or underscores.");
	return username;
}
var usernameAvailable = createServerFn({ method: "POST" }).validator((input) => ({ username: cleanUsername(input.username) })).handler(createSsrRpc("f9bbfccd8ba059e72ea6f269738cf2f9dcbbcfa74aaa903f36270b0bcdff5467"));
var claimUsername = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ username: cleanUsername(input.username) })).handler(createSsrRpc("e7b84f0a89834b83d322c5c94efd86b4d499aecf20789501eb35fe92387b1ea6"));
var getHomeState = createServerFn({ method: "GET" }).middleware([authMiddleware]).handler(createSsrRpc("9a2d41241098c96f04fce7f46c94343b47f358009f14f07a9545eefa1c012bfc"));
var joinQueue = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ mode: parseMode(input.mode) })).handler(createSsrRpc("6a75e6876619562a78cc7bfa652abff67c9e93b6a451c9f33374b91988605ba6"));
var leaveQueue = createServerFn({ method: "POST" }).middleware([authMiddleware]).handler(createSsrRpc("09d96d1150fde2165be796c75755b8b49ddf9ad8b4e3fbda72d0a4431ead4b62"));
var sendChallenge = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({
	username: cleanUsername(input.username),
	mode: parseMode(input.mode)
})).handler(createSsrRpc("9af9353ab470718ff38d1a1f92f9dc8771c9be04d9b6b40b5e8212ef84512b3a"));
var respondChallenge = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({
	id: String(input.id),
	accept: Boolean(input.accept)
})).handler(createSsrRpc("876ee21a444fca322964d37f9beb4788821085e46e7bbe6a95daea3a38c21bfd"));
var cancelChallenge = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ id: String(input.id) })).handler(createSsrRpc("a3e78aeedab9e23dfbf856fa98b10c2f61df529d43f7418d347beda98b42abde"));
var getGame = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ gameId: String(input.gameId) })).handler(createSsrRpc("bc6a93cf580d4bf4e05b959ced019d6095a766d712bc829aefe3dba5d4fa4c0c"));
var makeMove = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({
	gameId: String(input.gameId),
	from: String(input.from),
	to: String(input.to),
	promotion: input.promotion
})).handler(createSsrRpc("ed8e70a5b8c8349b4604dd5168a1c1d05b46e9c4a1a72cf35dba2592ed4b4eec"));
var claimTimeout = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ gameId: String(input.gameId) })).handler(createSsrRpc("34159b28857eb890ed5d571c23e70c035989407f6d2ff68a8943e90c4f42c987"));
var resignGame = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ gameId: String(input.gameId) })).handler(createSsrRpc("27c7e8f042e54d5a21aa768197287a4e143bf967af6d931a30741d9d495010c0"));
var startBotGame = createServerFn({ method: "POST" }).middleware([authMiddleware]).validator((input) => ({ mode: parseMode(input.mode) })).handler(createSsrRpc("b8cc246b7127b93f89d3224c5da34615747feb1f23b05cbb40febf0ac8140770"));
function cn(...inputs) {
	return twMerge(clsx(inputs));
}
var buttonVariants = cva("inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-[opacity,transform,background-color,color,border-color] duration-150 ease-[cubic-bezier(0.22,1,0.36,1)] disabled:pointer-events-none disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/70 active:scale-[0.98]", {
	variants: {
		variant: {
			default: "bg-forest text-ivory hover:bg-forest-2",
			solid: "bg-ivory text-ink hover:bg-cream",
			secondary: "bg-panel-2 text-ivory border border-line hover:border-line-strong",
			ghost: "text-mist hover:text-ivory hover:bg-panel-2",
			danger: "bg-danger text-ivory hover:opacity-90",
			outline: "border border-line-strong bg-transparent text-ivory hover:bg-panel-2"
		},
		size: {
			default: "h-11 px-4",
			sm: "h-9 px-3 text-xs",
			lg: "h-12 px-5 text-base",
			xl: "h-14 px-6 text-base"
		}
	},
	defaultVariants: {
		variant: "default",
		size: "default"
	}
});
function Button({ className, variant, size, asChild, ...props }) {
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(asChild ? Slot : "button", {
		className: cn(buttonVariants({
			variant,
			size
		}), className),
		...props
	});
}
function Input({ className, ...props }) {
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("input", {
		className: cn("flex h-11 w-full rounded-md border border-line-strong bg-ink-soft px-3 text-sm text-ivory placeholder:text-mist/70", "transition-[border-color,box-shadow] duration-150 ease-[cubic-bezier(0.22,1,0.36,1)]", "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-2/80", "disabled:opacity-40", className),
		...props
	});
}
function Label({ className, ...props }) {
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("label", {
		className: cn("text-xs font-medium uppercase tracking-[0.14em] text-mist", className),
		...props
	});
}
function AuthScreen() {
	const navigate = useNavigate();
	const [mode, setMode] = (0, import_react.useState)("create");
	const [username, setUsername] = (0, import_react.useState)("");
	const [password, setPassword] = (0, import_react.useState)("");
	const [show, setShow] = (0, import_react.useState)(false);
	const [error, setError] = (0, import_react.useState)(null);
	const [pending, setPending] = (0, import_react.useState)(false);
	async function onSubmit(e) {
		e.preventDefault();
		setError(null);
		const name = username.trim();
		if (!USERNAME_RE.test(name)) {
			setError("Club names are 3–20 letters, numbers, or underscores.");
			return;
		}
		if (password.length < 8) {
			setError("Password must be at least 8 characters.");
			return;
		}
		setPending(true);
		try {
			const email = usernameToEmail(name);
			if (mode === "create") {
				if (!await usernameAvailable({ data: { username: name } })) {
					setError("That club name is already taken.");
					return;
				}
				const { error: signErr } = await authClient.signUp.email({
					email,
					password,
					name
				});
				if (signErr) {
					setError(signErr.message || "Could not create that account.");
					return;
				}
				await authClient.getSession();
				await claimUsername({ data: { username: name } });
			} else {
				const { error: signErr } = await authClient.signIn.email({
					email,
					password
				});
				if (signErr) {
					setError(signErr.message || "Club name or password is wrong.");
					return;
				}
			}
			await navigate({ to: "/" });
		} catch (err) {
			setError(err instanceof Error ? err.message : "Something went wrong.");
		} finally {
			setPending(false);
		}
	}
	return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("main", {
		className: "relative mx-auto flex min-h-dvh w-full max-w-6xl flex-col px-5 py-8 sm:px-8",
		children: [
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", { className: "board-grid pointer-events-none absolute inset-0 opacity-80" }),
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("header", {
				className: "relative z-10 flex items-center gap-3",
				children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", {
					className: "grid size-10 place-items-center rounded-md bg-forest text-ivory",
					children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Castle, {
						className: "size-5",
						strokeWidth: 1.75
					})
				}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", { children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
					className: "font-display text-xl font-semibold tracking-wordmark text-ivory",
					children: "MORES"
				}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
					className: "text-xs uppercase tracking-[0.18em] text-mist",
					children: "Chess club"
				})] })]
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("section", {
				className: "relative z-10 mt-12 grid flex-1 items-center gap-12 lg:mt-0 lg:grid-cols-[1.1fr_0.9fr]",
				children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
					className: "max-w-xl",
					children: [
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
							className: "text-xs uppercase tracking-[0.2em] text-mist",
							children: "Private tables. Named players."
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h1", {
							className: "mt-4 font-display text-4xl font-semibold text-ivory sm:text-5xl",
							children: "Sit down under your own name."
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
							className: "mt-5 max-w-md text-base text-mist",
							children: "Create a club name, walk back in with it, and send a challenge across the room. Timed seats run one minute a turn. The winner takes eight."
						})
					]
				}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
					className: "rounded-xl border border-line bg-panel p-5 sm:p-7",
					children: [
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
							className: "grid grid-cols-2 rounded-md bg-ink-soft p-1",
							children: [["create", "Create account"], ["enter", "Sign in"]].map(([id, label]) => /* @__PURE__ */ (0, import_jsx_runtime.jsx)("button", {
								type: "button",
								onClick: () => {
									setMode(id);
									setError(null);
								},
								className: mode === id ? "h-10 rounded-sm bg-panel-2 text-sm font-medium text-ivory" : "h-10 rounded-sm text-sm font-medium text-mist",
								children: label
							}, id))
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("form", {
							className: "mt-6 space-y-4",
							onSubmit,
							children: [
								/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
									className: "space-y-2",
									children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Label, {
										htmlFor: "club-name",
										children: "Club name"
									}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Input, {
										id: "club-name",
										autoComplete: "username",
										autoFocus: true,
										value: username,
										onChange: (e) => setUsername(e.target.value),
										placeholder: "e.g. NorthBoard",
										maxLength: 20
									})]
								}),
								/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
									className: "space-y-2",
									children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Label, {
										htmlFor: "club-pass",
										children: "Password"
									}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
										className: "relative",
										children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Input, {
											id: "club-pass",
											type: show ? "text" : "password",
											autoComplete: mode === "create" ? "new-password" : "current-password",
											value: password,
											onChange: (e) => setPassword(e.target.value),
											placeholder: "At least 8 characters",
											className: "pr-11"
										}), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("button", {
											type: "button",
											className: "absolute right-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center text-mist hover:text-ivory",
											onClick: () => setShow((v) => !v),
											"aria-label": show ? "Hide password" : "Show password",
											children: show ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)(EyeOff, { className: "size-4" }) : /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Eye, { className: "size-4" })
										})]
									})]
								}),
								error ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
									className: "text-sm text-danger",
									children: error
								}) : null,
								/* @__PURE__ */ (0, import_jsx_runtime.jsx)(Button, {
									type: "submit",
									variant: "solid",
									size: "lg",
									className: "w-full",
									disabled: pending,
									children: pending ? "Entering…" : mode === "create" ? "Create account" : "Enter the club"
								})
							]
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("div", {
							className: "mt-6 flex items-center gap-3 text-xs uppercase tracking-[0.16em] text-mist",
							children: [
								/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", { className: "h-px flex-1 bg-line" }),
								"or",
								/* @__PURE__ */ (0, import_jsx_runtime.jsx)("span", { className: "h-px flex-1 bg-line" })
							]
						}),
						/* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
							className: "mt-4 grid gap-2",
							children: GROK_PROVIDERS.map((p) => /* @__PURE__ */ (0, import_jsx_runtime.jsxs)(Button, {
								type: "button",
								variant: "secondary",
								className: "w-full",
								onClick: () => signIn(p.providerId, { callbackURL: "/" }),
								children: ["Continue with ", p.label]
							}, p.providerId))
						})
					]
				})]
			})
		]
	});
}
function SplashSkeleton() {
	return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("main", {
		className: "mx-auto flex min-h-dvh w-full max-w-6xl flex-col px-5 py-8 sm:px-8",
		children: [
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
				className: "font-display text-xl font-semibold tracking-wordmark text-ivory",
				children: "MORES"
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)("h1", {
				className: "mt-16 font-display text-4xl text-ivory",
				children: "Opening the club"
			}),
			/* @__PURE__ */ (0, import_jsx_runtime.jsx)("p", {
				className: "mt-4 max-w-md text-sm text-mist",
				children: "Taking your seat at the board."
			})
		]
	});
}
/**
* Current user + loading state. Same behavior in live preview and when deployed:
*   - Auth enabled -> the real signed-in user; `user` is `null` while
*                            the session resolves (`isPending: true`) and when
*                            signed out (`isPending: false`). Session comes from
*                            Better Auth `useSession()` → `/api/auth/get-session`
*                            (cookie when deployed; bearer in live preview).
*   - Auth disabled (`VITE_AUTH_ENABLED=false`) -> `DEV_USER`, never pending.
*
* Protect a route by waiting out `isPending` before acting on `user` —
* redirecting on `user: null` alone bounces signed-in visitors to sign-in on
* every hard reload:
*
*   import { RedirectToSignIn } from "@/lib/auth/gates";
*   const { user, isPending } = useCurrentUserState();
*   if (isPending) return null;              // still resolving — don't redirect yet
*   if (!user) return <RedirectToSignIn />;  // definitely signed out
*
* `authEnabled` is a module-level constant fixed at load, so the guarded hook
* call keeps a stable hook order across every render of a given component.
*/
function useCurrentUserState() {
	const { data, isPending } = authClient.useSession();
	const user = data?.user;
	return {
		user: user ? {
			id: user.id,
			displayName: user.name ?? null,
			primaryEmail: user.email ?? null,
			profileImageUrl: user.image ?? null,
			isDevFallback: false
		} : null,
		isPending
	};
}
/**
* Convenience view of `useCurrentUserState().user` for display (e.g.
* `user?.displayName ?? "Guest"`). NOTE: `null` means *loading OR signed out* —
* for redirects/guards use `useCurrentUserState()` and check `isPending`.
*/
function useCurrentUser() {
	return useCurrentUserState().user;
}
//#endregion
export { sendChallenge as _, SplashSkeleton as a, useCurrentUserState as b, claimUsername as c, getHomeState as d, joinQueue as f, respondChallenge as g, resignGame as h, Label as i, cn as l, makeMove as m, Button as n, cancelChallenge as o, leaveQueue as p, Input as r, claimTimeout as s, AuthScreen as t, getGame as u, startBotGame as v, useCurrentUser as y };
