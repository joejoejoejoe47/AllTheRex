import { l as require_jsx_runtime } from "../_libs/@react-three/drei+[...].mjs";
import { v as Navigate } from "../_libs/@tanstack/react-router+[...].mjs";
import { a as SplashSkeleton, b as useCurrentUserState, t as AuthScreen } from "./use-current-user-D04QZakv.mjs";
//#region node_modules/.nitro/vite/services/ssr/assets/login-DHoYiZwJ.js
var import_jsx_runtime = require_jsx_runtime();
function Login() {
	const { user, isPending } = useCurrentUserState();
	if (isPending) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(SplashSkeleton, {});
	if (user) return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(Navigate, { to: "/" });
	return /* @__PURE__ */ (0, import_jsx_runtime.jsx)(AuthScreen, {});
}
//#endregion
export { Login as component };
