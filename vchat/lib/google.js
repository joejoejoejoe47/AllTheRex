import crypto from 'node:crypto';
import { config } from './config.js';

// Verifies a Google Identity Services ID token (RS256 JWT) locally against Google's published keys.
let jwks = { keys: [], fetched: 0 };

async function getKeys(force = false) {
  if (!force && Date.now() - jwks.fetched < 3600e3 && jwks.keys.length) return jwks.keys;
  const r = await fetch('https://www.googleapis.com/oauth2/v3/certs');
  if (!r.ok) throw new Error('Could not fetch Google keys');
  jwks = { keys: (await r.json()).keys || [], fetched: Date.now() };
  return jwks.keys;
}

export async function verifyGoogleIdToken(idToken, now = Date.now(), keysOverride = null) {
  if (!config.googleClientId && !keysOverride) throw new Error('Google sign-in is not configured');
  const parts = String(idToken).split('.');
  if (parts.length !== 3) throw new Error('Malformed token');
  const header = JSON.parse(Buffer.from(parts[0], 'base64url').toString());
  const claims = JSON.parse(Buffer.from(parts[1], 'base64url').toString());
  if (header.alg !== 'RS256') throw new Error('Bad algorithm');

  let keys = keysOverride || (await getKeys());
  let jwk = keys.find((k) => k.kid === header.kid);
  if (!jwk && !keysOverride) jwk = (await getKeys(true)).find((k) => k.kid === header.kid);
  if (!jwk) throw new Error('Unknown signing key');

  const ok = crypto.verify('RSA-SHA256', Buffer.from(`${parts[0]}.${parts[1]}`),
    crypto.createPublicKey({ key: jwk, format: 'jwk' }), Buffer.from(parts[2], 'base64url'));
  if (!ok) throw new Error('Bad signature');

  const aud = keysOverride ? claims.aud : config.googleClientId;
  if (claims.aud !== aud) throw new Error('Wrong audience');
  if (!['accounts.google.com', 'https://accounts.google.com'].includes(claims.iss)) throw new Error('Wrong issuer');
  if (!(claims.exp * 1000 > now)) throw new Error('Token expired');
  if (!claims.sub) throw new Error('No subject');
  if (claims.email && claims.email_verified !== true && claims.email_verified !== 'true') throw new Error('Email not verified');
  return { sub: claims.sub, email: claims.email || null, name: claims.name || claims.email || 'Google user', picture: claims.picture || null };
}
