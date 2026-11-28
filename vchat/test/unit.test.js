import assert from 'node:assert/strict';
import crypto from 'node:crypto';
process.env.GOOGLE_CLIENT_ID = 'test-client';
const { hotp, b32encode, b32decode, checkTotp, newSecret } = await import('../lib/totp.js');
const { verifyGoogleIdToken } = await import('../lib/google.js');
const { normalizePhone } = await import('../lib/auth.js');
const { sign, verify } = await import('../lib/session.js');

// RFC 4226 test vectors
const key = Buffer.from('12345678901234567890');
['755224', '287082', '359152', '969429'].forEach((v, i) => assert.equal(hotp(key, i), v));
assert.ok(b32decode(b32encode(key)).equals(key));

// TOTP window + replay protection
const secret = newSecret(), now = Date.now(), step = Math.floor(now / 30000);
const code = hotp(b32decode(secret), step);
assert.equal(checkTotp(secret, code, 0, now), step);
assert.equal(checkTotp(secret, code, step, now), null, 'replay rejected');
assert.equal(checkTotp(secret, '000000x', 0, now), null);

// phone numbers
assert.equal(normalizePhone('+1 (555) 123-4567'), '+15551234567');
assert.equal(normalizePhone('5551234567'), null);

// signed tokens
const t = sign({ a: 1 }, 1000);
assert.equal(verify(t).a, 1);
assert.equal(verify(t.slice(0, -2) + 'xx'), null);
assert.equal(verify(sign({ a: 1 }, -1)), null);

// Google token verification with a locally generated key
const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'k1' };
const mk = (claims, key = privateKey) => {
  const b = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
  const body = `${b({ alg: 'RS256', kid: 'k1' })}.${b(claims)}`;
  return `${body}.${crypto.sign('RSA-SHA256', Buffer.from(body), key).toString('base64url')}`;
};
const good = { iss: 'https://accounts.google.com', aud: 'x', sub: '123', email: 'a@b.c', email_verified: true, name: 'A', exp: Date.now() / 1000 + 600 };
assert.equal((await verifyGoogleIdToken(mk(good), Date.now(), [jwk])).sub, '123');
await assert.rejects(verifyGoogleIdToken(mk({ ...good, iss: 'evil.com' }), Date.now(), [jwk]), /issuer/);
await assert.rejects(verifyGoogleIdToken(mk({ ...good, exp: 1 }), Date.now(), [jwk]), /expired/);
await assert.rejects(verifyGoogleIdToken(mk({ ...good, email_verified: false }), Date.now(), [jwk]), /verified/);
const other = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 }).privateKey;
await assert.rejects(verifyGoogleIdToken(mk(good, other), Date.now(), [jwk]), /signature/);
console.log('unit tests passed');
