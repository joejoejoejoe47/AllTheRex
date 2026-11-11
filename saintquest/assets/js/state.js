// Shared client state.
export const state = {
  me: null,
  catalog: {},
  list: [],
  homeIndex: 0,
  listeners: [],
};
export function onChange(fn) { state.listeners.push(fn); }
export function setMe(me) {
  state.me = me;
  if (me && state.homeIndex >= me.saints.length) state.homeIndex = 0;
  for (const fn of state.listeners) fn(state);
}
export const def = (key) => state.catalog[key];
export function homeSaint() {
  if (!state.me || !state.me.saints.length) return null;
  return state.me.saints[state.homeIndex % state.me.saints.length];
}
