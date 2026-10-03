//#region node_modules/.nitro/vite/services/ssr/assets/_tanstack-start-manifest_v-jdm7-3vD.js
var tsrStartManifest = () => ({ routes: {
	__root__: {
		filePath: "/workspace/src/routes/__root.tsx",
		children: [
			"/",
			"/login",
			"/play/$gameId",
			"/api/auth/$"
		],
		preloads: [
			"/assets/index-no_oIOB7.js",
			"/assets/react-SIfiwpqq.js",
			"/assets/preload-helper-DCC9U1cP.js"
		],
		scripts: [{ attrs: {
			type: "module",
			async: !0,
			src: "/assets/index-no_oIOB7.js"
		} }]
	},
	"/": {
		filePath: "/workspace/src/routes/index.tsx",
		children: void 0,
		preloads: [
			"/assets/routes--VkriyfZ.js",
			"/assets/use-current-user-B_ghCiDx.js",
			"/assets/gates-sAIAy0Ow.js"
		]
	},
	"/login": {
		filePath: "/workspace/src/routes/login.tsx",
		children: void 0,
		preloads: ["/assets/login-CZrdorYa.js", "/assets/use-current-user-B_ghCiDx.js"]
	},
	"/play/$gameId": {
		filePath: "/workspace/src/routes/play.$gameId.tsx",
		children: void 0,
		preloads: [
			"/assets/play._gameId-q31yakz9.js",
			"/assets/use-current-user-B_ghCiDx.js",
			"/assets/gates-sAIAy0Ow.js"
		]
	}
} });
//#endregion
export { tsrStartManifest };
