import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// Tiny .env loader (no dependency). Real environment variables win.
try {
  for (const line of fs.readFileSync(path.join(ROOT, '.env'), 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m && !(m[1] in process.env)) process.env[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
  }
} catch { /* no .env */ }

const env = process.env;
const prod = env.NODE_ENV === 'production';

let sessionSecret = env.SESSION_SECRET;
if (!sessionSecret) {
  if (prod) throw new Error('SESSION_SECRET is required when NODE_ENV=production');
  sessionSecret = crypto.randomBytes(32).toString('hex');
  console.warn('[config] SESSION_SECRET not set: using a random one (logins reset on restart).');
}

const list = (s) => (s || '').split(',').map((x) => x.trim()).filter(Boolean);

export const config = {
  port: Number(env.PORT) || 3000,
  host: env.HOST || '0.0.0.0',
  prod,
  sessionSecret,
  sessionTtlMs: (Number(env.SESSION_TTL_DAYS) || 14) * 86400e3,
  cookieSecure: prod || env.COOKIE_SECURE === '1',
  trustProxy: env.TRUST_PROXY === '1',
  allowedOrigins: list(env.ALLOWED_ORIGINS),
  maxParticipants: Math.min(20, Math.max(2, Number(env.MAX_PARTICIPANTS) || 20)),
  googleClientId: env.GOOGLE_CLIENT_ID || '',
  smsMode: env.SMS_MODE === 'twilio' ? 'twilio' : 'console',
  twilio: { sid: env.TWILIO_ACCOUNT_SID, token: env.TWILIO_AUTH_TOKEN, from: env.TWILIO_FROM },
  totpIssuer: env.TOTP_ISSUER || 'AllTheRex vchat',
  stunUrls: list(env.STUN_URLS || 'stun:stun.l.google.com:19302'),
  turnUrls: list(env.TURN_URLS),
  turnSecret: env.TURN_SECRET || '',
  turnUsername: env.TURN_USERNAME || '',
  turnCredential: env.TURN_CREDENTIAL || '',
};

if (config.smsMode === 'twilio' && !(config.twilio.sid && config.twilio.token && config.twilio.from)) {
  throw new Error('SMS_MODE=twilio needs TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM');
}
if (prod && config.smsMode === 'console') {
  console.warn('[config] SMS_MODE=console in production: phone codes are only printed to the log!');
}
