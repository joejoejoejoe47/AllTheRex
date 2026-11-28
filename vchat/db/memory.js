// In-memory store: fine for development, loses everything on restart.
// To use a real database, copy this file, implement the same methods (see README.md), and
// point db/index.js at it. Every method may be async.
import crypto from 'node:crypto';

export default function createMemoryStore() {
  const users = new Map();      // id -> user
  const otps = new Map();       // phone -> { hash, expiresAt, attempts }
  const meetings = new Map();   // code -> meeting

  const find = (pred) => { for (const u of users.values()) if (pred(u)) return { ...u }; return null; };

  return {
    async findUserById(id) { const u = users.get(id); return u ? { ...u } : null; },
    async findUserByGoogleSub(sub) { return find((u) => u.googleSub === sub); },
    async findUserByPhone(phone) { return find((u) => u.phone === phone); },
    async createUser(fields) {
      const u = {
        id: crypto.randomUUID(), googleSub: null, email: null, phone: null, name: 'User', picture: null,
        totpSecret: null, totpEnabled: false, totpLastStep: 0, recoveryHashes: [], createdAt: Date.now(),
        ...fields,
      };
      users.set(u.id, u);
      return { ...u };
    },
    async updateUser(id, patch) {
      const u = users.get(id); if (!u) return null;
      Object.assign(u, patch);
      return { ...u };
    },

    async putOtp(phone, rec) { otps.set(phone, { ...rec }); },
    async getOtp(phone) { const o = otps.get(phone); return o ? { ...o } : null; },
    async deleteOtp(phone) { otps.delete(phone); },

    async createMeeting(m) { meetings.set(m.code, { ended: false, createdAt: Date.now(), ...m }); return { ...meetings.get(m.code) }; },
    async getMeeting(code) { const m = meetings.get(code); return m ? { ...m } : null; },
    async endMeeting(code) { const m = meetings.get(code); if (m) m.ended = true; },
  };
}
