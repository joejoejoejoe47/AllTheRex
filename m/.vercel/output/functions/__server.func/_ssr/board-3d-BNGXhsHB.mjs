import { o as __toESM } from "../_runtime.mjs";
import { n as require_react } from "../_libs/@radix-ui/react-compose-refs+[...].mjs";
import { a as LatheGeometry, c as Vector3, l as require_jsx_runtime, n as Canvas, o as MeshStandardMaterial, r as useFrame, s as Vector2, t as OrbitControls } from "../_libs/@react-three/drei+[...].mjs";
import { t as Chess } from "../_libs/chess.js.mjs";
//#region node_modules/.nitro/vite/services/ssr/assets/board-3d-BNGXhsHB.js
var import_react = /* @__PURE__ */ __toESM(require_react());
var import_jsx_runtime = require_jsx_runtime();
var FILES = "abcdefgh";
function squareToWorld(square) {
	const file = square.charCodeAt(0) - 97;
	const rank = Number(square[1]) - 1;
	return [
		file - 3.5,
		0,
		3.5 - rank
	];
}
var LIGHT_SQ = "#d7c4a3";
var DARK_SQ = "#6e4d3a";
var IVORY = "#efe6d4";
var EBONY = "#1b1713";
var SELECT = "#3f5d4e";
var LAST = "#8a6a4e";
var CHECK = "#9a4036";
function lathe(pairs, segments = 28) {
	const pts = pairs.map(([x, y]) => new Vector2(x, y));
	return new LatheGeometry(pts, segments);
}
function makeGeometries() {
	return {
		pawn: lathe([
			[0, 0],
			[.32, 0],
			[.32, .06],
			[.2, .1],
			[.16, .26],
			[.2, .3],
			[.12, .34],
			[.14, .4],
			[.2, .5],
			[.18, .58],
			[.1, .63],
			[0, .64]
		]),
		rook: lathe([
			[0, 0],
			[.34, 0],
			[.34, .08],
			[.22, .12],
			[.2, .52],
			[.28, .56],
			[.28, .72],
			[.22, .72],
			[0, .72]
		]),
		bishop: lathe([
			[0, 0],
			[.32, 0],
			[.32, .07],
			[.18, .12],
			[.14, .4],
			[.18, .5],
			[.12, .62],
			[.1, .78],
			[.06, .86],
			[.08, .9],
			[0, .92]
		]),
		queen: lathe([
			[0, 0],
			[.34, 0],
			[.34, .08],
			[.18, .14],
			[.16, .46],
			[.22, .54],
			[.16, .62],
			[.2, .78],
			[.12, .86],
			[.08, .94],
			[0, .96]
		]),
		king: lathe([
			[0, 0],
			[.34, 0],
			[.34, .08],
			[.18, .14],
			[.16, .5],
			[.22, .58],
			[.16, .66],
			[.18, .84],
			[.12, .9],
			[0, .9]
		]),
		knightBase: lathe([
			[0, 0],
			[.32, 0],
			[.32, .08],
			[.2, .12],
			[.18, .28],
			[0, .28]
		])
	};
}
function PieceMesh({ type, color, geometries, ivory, ebony }) {
	const mat = color === "w" ? ivory : ebony;
	if (type === "n") return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("group", { children: [
		/* @__PURE__ */ (0, import_jsx_runtime.jsx)("mesh", {
			geometry: geometries.knightBase,
			material: mat,
			castShadow: true
		}),
		/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
			position: [
				0,
				.42,
				.02
			],
			rotation: [
				.15,
				0,
				0
			],
			castShadow: true,
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
				.22,
				.38,
				.34
			] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
				color: mat.color,
				roughness: mat.roughness,
				metalness: mat.metalness
			})]
		}),
		/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
			position: [
				0,
				.62,
				.16
			],
			rotation: [
				.55,
				0,
				0
			],
			castShadow: true,
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
				.2,
				.22,
				.3
			] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
				color: mat.color,
				roughness: mat.roughness,
				metalness: mat.metalness
			})]
		}),
		/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
			position: [
				0,
				.72,
				.3
			],
			castShadow: true,
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
				.16,
				.12,
				.16
			] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
				color: mat.color,
				roughness: mat.roughness,
				metalness: mat.metalness
			})]
		}),
		/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
			position: [
				.05,
				.86,
				.08
			],
			castShadow: true,
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
				.08,
				.16,
				.1
			] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
				color: mat.color,
				roughness: mat.roughness,
				metalness: mat.metalness
			})]
		})
	] });
	const geo = type === "p" ? geometries.pawn : type === "r" ? geometries.rook : type === "b" ? geometries.bishop : type === "q" ? geometries.queen : geometries.king;
	return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("group", { children: [
		/* @__PURE__ */ (0, import_jsx_runtime.jsx)("mesh", {
			geometry: geo,
			material: mat,
			castShadow: true
		}),
		type === "k" ? /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("group", {
			position: [
				0,
				.98,
				0
			],
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
				castShadow: true,
				children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
					.07,
					.22,
					.07
				] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
					color: mat.color,
					roughness: .35,
					metalness: .08
				})]
			}), /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
				position: [
					0,
					.04,
					0
				],
				castShadow: true,
				children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
					.18,
					.07,
					.07
				] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
					color: mat.color,
					roughness: .35,
					metalness: .08
				})]
			})]
		}) : null,
		type === "q" ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("group", {
			position: [
				0,
				.96,
				0
			],
			children: [
				0,
				1,
				2,
				3,
				4
			].map((i) => {
				const a = i / 5 * Math.PI * 2;
				return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
					position: [
						Math.cos(a) * .12,
						.02,
						Math.sin(a) * .12
					],
					castShadow: true,
					children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("sphereGeometry", { args: [
						.035,
						10,
						10
					] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
						color: mat.color,
						roughness: .32,
						metalness: .1
					})]
				}, i);
			})
		}) : null,
		type === "r" ? /* @__PURE__ */ (0, import_jsx_runtime.jsx)("group", {
			position: [
				0,
				.74,
				0
			],
			children: [
				0,
				1,
				2,
				3
			].map((i) => {
				const a = i / 4 * Math.PI * 2 + Math.PI / 4;
				return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
					position: [
						Math.cos(a) * .22,
						.05,
						Math.sin(a) * .22
					],
					castShadow: true,
					children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
						.1,
						.1,
						.1
					] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
						color: mat.color,
						roughness: .4,
						metalness: .06
					})]
				}, i);
			})
		}) : null
	] });
}
function AnimatedPiece({ square, spawnFrom, type, color, selected, you, geometries, ivory, ebony, onClick }) {
	const ref = (0, import_react.useRef)(null);
	const start = squareToWorld(spawnFrom);
	const pos = (0, import_react.useRef)(new Vector3(start[0], 0, start[2]));
	const lift = (0, import_react.useRef)(selected ? .22 : 0);
	useFrame((_, raw) => {
		const dt = Math.min(raw, .1);
		const dest = squareToWorld(square);
		const target = new Vector3(dest[0], 0, dest[2]);
		const k = 1 - Math.exp(-14 * dt);
		pos.current.lerp(target, k);
		lift.current += ((selected ? .24 : 0) - lift.current) * (1 - Math.exp(-16 * dt));
		if (!ref.current) return;
		ref.current.position.set(pos.current.x, .08 + lift.current, pos.current.z);
		const facing = you === "b" ? Math.PI : 0;
		const knightFace = color === "w" ? 0 : Math.PI;
		ref.current.rotation.y = type === "n" ? knightFace + facing : 0;
	});
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("group", {
		ref,
		onClick: (e) => {
			e.stopPropagation();
			onClick();
		},
		children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(PieceMesh, {
			type,
			color,
			geometries,
			ivory,
			ebony
		})
	});
}
function BoardSquares({ selected, legal, lastMove, checkSquare, onSquare }) {
	const squares = (0, import_react.useMemo)(() => {
		const list = [];
		for (let r = 0; r < 8; r++) for (let f = 0; f < 8; f++) {
			const sq = `${FILES[f]}${r + 1}`;
			list.push({
				sq,
				x: f - 3.5,
				z: 3.5 - r,
				light: (f + r) % 2 === 1
			});
		}
		return list;
	}, []);
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("group", { children: squares.map(({ sq, x, z, light }) => {
		const isSel = selected === sq;
		const isLast = lastMove?.from === sq || lastMove?.to === sq;
		return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("group", { children: [/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
			position: [
				x,
				.04,
				z
			],
			receiveShadow: true,
			onClick: (e) => {
				e.stopPropagation();
				onSquare(sq);
			},
			onPointerOver: (e) => {
				e.stopPropagation();
				document.body.style.cursor = "pointer";
			},
			onPointerOut: () => {
				document.body.style.cursor = "default";
			},
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
				.98,
				.08,
				.98
			] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
				color: checkSquare === sq ? CHECK : isSel ? SELECT : isLast ? LAST : light ? LIGHT_SQ : DARK_SQ,
				roughness: .62,
				metalness: .04
			})]
		}), legal.has(sq) ? /* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
			position: [
				x,
				.1,
				z
			],
			rotation: [
				-Math.PI / 2,
				0,
				0
			],
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("circleGeometry", { args: [.16, 22] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshBasicMaterial", {
				color: "#f3ead8",
				transparent: true,
				opacity: .88
			})]
		}) : null] }, sq);
	}) });
}
function Scene({ fen, you, lastMove, selected, legal, checkSquare, onSquare, interactive }) {
	const geometries = (0, import_react.useMemo)(() => makeGeometries(), []);
	const ivory = (0, import_react.useMemo)(() => new MeshStandardMaterial({
		color: IVORY,
		roughness: .34,
		metalness: .06
	}), []);
	const ebony = (0, import_react.useMemo)(() => new MeshStandardMaterial({
		color: EBONY,
		roughness: .4,
		metalness: .08
	}), []);
	(0, import_react.useEffect)(() => () => {
		Object.values(geometries).forEach((g) => g.dispose());
		ivory.dispose();
		ebony.dispose();
	}, [
		geometries,
		ivory,
		ebony
	]);
	const pieces = (0, import_react.useMemo)(() => {
		const chess = new Chess(fen);
		const list = [];
		for (const sq of chess.board().flatMap((row, rankFromTop) => row.map((p, file) => ({
			p,
			sq: `${FILES[file]}${8 - rankFromTop}`
		})))) if (sq.p) list.push({
			sq: sq.sq,
			type: sq.p.type,
			color: sq.p.color
		});
		return list;
	}, [fen]);
	return /* @__PURE__ */ (0, import_jsx_runtime.jsxs)(import_jsx_runtime.Fragment, { children: [
		/* @__PURE__ */ (0, import_jsx_runtime.jsx)("color", {
			attach: "background",
			args: ["#0c0d0b"]
		}),
		/* @__PURE__ */ (0, import_jsx_runtime.jsx)("hemisphereLight", { args: [
			"#efe6d4",
			"#24332c",
			.55
		] }),
		/* @__PURE__ */ (0, import_jsx_runtime.jsx)("ambientLight", { intensity: .28 }),
		/* @__PURE__ */ (0, import_jsx_runtime.jsx)("directionalLight", {
			position: [
				8,
				14,
				6
			],
			intensity: 1.35,
			castShadow: true,
			"shadow-mapSize-width": 1024,
			"shadow-mapSize-height": 1024,
			"shadow-camera-near": 1,
			"shadow-camera-far": 40,
			"shadow-camera-left": -10,
			"shadow-camera-right": 10,
			"shadow-camera-top": 10,
			"shadow-camera-bottom": -10
		}),
		/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
			rotation: [
				-Math.PI / 2,
				0,
				0
			],
			position: [
				0,
				-.28,
				0
			],
			receiveShadow: true,
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("circleGeometry", { args: [11, 48] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
				color: "#24332c",
				roughness: .9
			})]
		}),
		/* @__PURE__ */ (0, import_jsx_runtime.jsxs)("mesh", {
			position: [
				0,
				-.1,
				0
			],
			receiveShadow: true,
			children: [/* @__PURE__ */ (0, import_jsx_runtime.jsx)("boxGeometry", { args: [
				9.6,
				.28,
				9.6
			] }), /* @__PURE__ */ (0, import_jsx_runtime.jsx)("meshStandardMaterial", {
				color: "#3c261c",
				roughness: .58,
				metalness: .05
			})]
		}),
		/* @__PURE__ */ (0, import_jsx_runtime.jsx)(BoardSquares, {
			selected,
			legal: interactive ? legal : /* @__PURE__ */ new Set(),
			lastMove,
			checkSquare,
			onSquare
		}),
		pieces.map((p) => /* @__PURE__ */ (0, import_jsx_runtime.jsx)(AnimatedPiece, {
			square: p.sq,
			spawnFrom: lastMove?.to === p.sq ? lastMove.from : p.sq,
			type: p.type,
			color: p.color,
			selected: selected === p.sq,
			you,
			geometries,
			ivory,
			ebony,
			onClick: () => onSquare(p.sq)
		}, `${p.color}${p.type}${p.sq}`)),
		/* @__PURE__ */ (0, import_jsx_runtime.jsx)(OrbitControls, {
			enablePan: false,
			minPolarAngle: .55,
			maxPolarAngle: 1.05,
			minDistance: 10,
			maxDistance: 18,
			target: [
				0,
				.2,
				0
			],
			enableDamping: true,
			dampingFactor: .08
		})
	] });
}
function ChessBoard3D({ fen, you, lastMove, myTurn, onMove, disabled }) {
	const [selected, setSelected] = (0, import_react.useState)(null);
	const chess = (0, import_react.useMemo)(() => new Chess(fen), [fen]);
	const legal = (0, import_react.useMemo)(() => {
		if (!selected) return /* @__PURE__ */ new Set();
		return new Set(chess.moves({
			square: selected,
			verbose: true
		}).map((m) => m.to));
	}, [chess, selected]);
	const checkSquare = (0, import_react.useMemo)(() => {
		if (!chess.isCheck()) return null;
		const board = chess.board();
		for (let r = 0; r < 8; r++) for (let f = 0; f < 8; f++) {
			const p = board[r][f];
			if (p?.type === "k" && p.color === chess.turn()) return `${FILES[f]}${8 - r}`;
		}
		return null;
	}, [chess]);
	(0, import_react.useEffect)(() => {
		setSelected(null);
	}, [fen]);
	function onSquare(sq) {
		if (disabled || !myTurn) {
			setSelected(null);
			return;
		}
		const piece = chess.get(sq);
		if (selected && legal.has(sq)) {
			onMove(selected, sq);
			setSelected(null);
			return;
		}
		if (piece && piece.color === you) {
			setSelected(sq);
			return;
		}
		setSelected(null);
	}
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", {
		className: "h-full w-full touch-none",
		children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Canvas, {
			shadows: true,
			dpr: [1, 1.75],
			camera: {
				position: you === "w" ? [
					0,
					15.2,
					11.2
				] : [
					0,
					15.2,
					-11.2
				],
				fov: 36,
				near: .1,
				far: 80
			},
			gl: {
				antialias: true,
				alpha: false
			},
			onCreated: ({ camera, gl }) => {
				camera.lookAt(0, .2, 0);
				gl.shadowMap.enabled = true;
				gl.shadowMap.type = 1;
			},
			children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Scene, {
				fen,
				you,
				lastMove,
				selected,
				legal,
				checkSquare,
				onSquare,
				interactive: Boolean(myTurn && !disabled)
			})
		})
	});
}
//#endregion
export { ChessBoard3D };
