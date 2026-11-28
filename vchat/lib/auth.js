import crypto from 'node:crypto';
import { config } from './config.js';
import { sign, verify } from './session.js';
import { makeLimiter } from './limiter.js';
import { newSecret, checkTotp, otpauthUri } from './totp.js';
import { verifyGoogleIdToken } from './google.js';
import { sendSms } from './sms.js';

const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');
const otpHash = (phone, code) => crypto.createHmac('sha256', config.sessionSecret).update(`${phone}:${code}`).digest('hex');
const eq = (a, b) => { const x = Buffer.from(a), y = Buffer.from(b); return x.length === y.length && crypto.timingSafeEqual(x, y); };

const smsPerPhone = makeLimiter(5, 3600e3);
const smsPerIp = makeLimiter(Number(process.env.SMS_IP_LIMIT) || 15, 3600e3); // SMS_IP_LIMIT: tune behind shared NAT
const codeTries = makeLimiter(10, 15 * 60e3);   // wrong SMS codes per phone
const stepTwoFails = makeLimiter(5, 15 * 60e3); // wrong 2nd-step codes per account

export class HttpError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}

export const normalizePhone = (p) => {
  const s = String(p || '').replace(/[\s().-]/g, '');
  return /^\+[1-9]\d{7,14}$/.test(s) ? s : null;
};
export const publicUser = (u) => ({
  id: u.id, name: u.name, picture: u.picture, email: u.email,
  phone: u.phone ? `•••${u.phone.slice(-4)}` : null,
});

export function createAuth(store) {
  async function google({ credential }) {
    let g;
    try { g = await verifyGoogleIdToken(credential); } catch (e) {
      throw new HttpError(401, 'Google sign-in failed');
    }
    let user = await store.findUserByGoogleSub(g.sub);
    if (!user) user = await store.createUser({ googleSub: g.sub, email: g.email, name: g.name.slice(0, 60), picture: g.picture });
    else if (g.picture !== user.picture) user = await store.updateUser(user.id, { picture: g.picture });
    return { user }; // Google enforces the account's own 2-Step Verification
  }

  async function phoneStart({ phone }, ip) {
    const p = normalizePhone(phone);
    if (!p) throw new HttpError(400, 'Enter your number with country code, e.g. +15551234567');
    if (!smsPerIp.allow(ip) || !smsPerPhone.allow(p)) throw new HttpError(429, 'Too many codes requested. Try again later.');
    const code = String(crypto.randomInt(0, 1_000_000)).padStart(6, '0');
    await store.putOtp(p, { hash: otpHash(p, code), expiresAt: Date.now() + 5 * 60e3, attempts: 0 });
    await sendSms(p, `Your vchat code is ${code}. It expires in 5 minutes.`);
    return { ok: true }; // same response whether or not the number has an account
  }

  async function phoneVerify({ phone, code }) {
    const p = normalizePhone(phone);
    if (!p || !/^\d{6}$/.test(String(code))) throw new HttpError(400, 'Enter the 6-digit code.');
    if (codeTries.blocked(p)) throw new HttpError(429, 'Too many attempts. Request a new code later.');
    const rec = await store.getOtp(p);
    if (!rec || rec.expiresAt < Date.now()) throw new HttpError(401, 'Code expired. Request a new one.');
    if (rec.attempts >= 5 || !eq(rec.hash, otpHash(p, String(code)))) {
      codeTries.fail(p);
      await store.putOtp(p, { ...rec, attempts: rec.attempts + 1 });
      throw new HttpError(401, 'Wrong code.');
    }
    await store.deleteOtp(p);
    codeTries.clear(p);

    let user = await store.findUserByPhone(p);
    if (!user) user = await store.createUser({ phone: p, name: `User ${p.slice(-4)}` });
    const ticket = sign({ uid: user.id, k: 'step2' }, 5 * 60e3);

    if (user.totpEnabled) return { step: 'totp', ticket };
    // First time: enrol an authenticator app. Not enabled until a code is confirmed.
    const secret = newSecret();
    await store.updateUser(user.id, { totpSecret: secret, totpEnabled: false, totpLastStep: 0 });
    return { step: 'enroll', ticket, secret, otpauth: otpauthUri(secret, p, config.totpIssuer) };
  }

  async function phoneSecondStep({ ticket, code }) {
    const t = verify(ticket);
    if (!t || t.k !== 'step2') throw new HttpError(401, 'Session expired. Start again.');
    if (stepTwoFails.blocked(t.uid)) throw new HttpError(429, 'Too many wrong codes. Try again in 15 minutes.');
    const user = await store.findUserById(t.uid);
    if (!user || !user.totpSecret) throw new HttpError(401, 'Start again.');
    const input = String(code || '').trim();

    const step = checkTotp(user.totpSecret, input, user.totpLastStep);
    if (step !== null) {
      stepTwoFails.clear(user.id);
      if (!user.totpEnabled) { // enrolment confirmed
        const recovery = Array.from({ length: 8 }, () => crypto.randomBytes(5).toString('hex'));
        const u = await store.updateUser(user.id, { totpEnabled: true, totpLastStep: step, recoveryHashes: recovery.map(sha) });
        return { user: u, recoveryCodes: recovery };
      }
      return { user: await store.updateUser(user.id, { totpLastStep: step }) };
    }
    // recovery code (single use) - only for accounts that have finished enrolment
    const h = sha(input.toLowerCase());
    if (user.totpEnabled && user.recoveryHashes.includes(h)) {
      stepTwoFails.clear(user.id);
      const u = await store.updateUser(user.id, { recoveryHashes: user.recoveryHashes.filter((x) => x !== h) });
      return { user: u, usedRecovery: true, recoveryLeft: u.recoveryHashes.length };
    }
    stepTwoFails.fail(user.id);
    throw new HttpError(401, 'Wrong code.');
  }

  return { google, phoneStart, phoneVerify, phoneSecondStep };
}
