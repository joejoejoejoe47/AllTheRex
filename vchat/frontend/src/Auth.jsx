import { useEffect, useRef, useState } from 'react';
import { api } from './api.js';
import { toast } from './ui.jsx';

let gisPromise;
const loadGoogle = () => (gisPromise ||= new Promise((res, rej) => {
  const s = document.createElement('script');
  s.src = 'https://accounts.google.com/gsi/client'; s.async = true; s.onload = res; s.onerror = rej;
  document.head.append(s);
}));

export default function AuthScreen({ config, onLogin }) {
  const [err, setErr] = useState('');
  const [recovery, setRecovery] = useState(null); // { codes, user }

  const finish = (r) => {
    if (r.recoveryCodes) return setRecovery({ codes: r.recoveryCodes, user: r.user });
    if (r.recoveryLeft !== undefined && r.recoveryLeft < 3) toast(`Recovery code used. ${r.recoveryLeft} left.`);
    onLogin(r.user);
  };

  if (recovery) {
    return (
      <div className="screen"><div className="card">
        <h1>2-step is on</h1>
        <p className="sub">Save these recovery codes somewhere safe. Each works once if you lose your authenticator app. They will not be shown again.</p>
        <div className="codes">{recovery.codes.map((c) => <div key={c}>{c}</div>)}</div>
        <button className="btn block" onClick={() => onLogin(recovery.user)}>I&apos;ve saved them — continue</button>
      </div></div>
    );
  }

  return (
    <div className="screen"><div className="card">
      <h1>Sign in to vchat</h1>
      <p className="sub">Video meetings for up to {config.maxParticipants} people.</p>
      <GoogleButton clientId={config.googleClientId} onError={setErr} onDone={finish} />
      <div className="divider">or use your phone</div>
      <PhoneFlow onError={setErr} onDone={finish} />
      <div className="err" role="alert">{err}</div>
    </div></div>
  );
}

function GoogleButton({ clientId, onError, onDone }) {
  const ref = useRef(null);
  const [msg, setMsg] = useState('');
  useEffect(() => {
    if (!clientId) { setMsg('Google sign-in is not configured (set google_client_id).'); return; }
    let live = true;
    loadGoogle().then(() => {
      if (!live) return;
      window.google.accounts.id.initialize({
        client_id: clientId,
        callback: async (resp) => {
          try { onDone(await api('auth.google', { credential: resp.credential })); } catch (e) { onError(e.message); }
        },
      });
      window.google.accounts.id.renderButton(ref.current, { theme: 'filled_black', size: 'large', text: 'continue_with', width: 280 });
    }).catch(() => setMsg('Could not load Google sign-in.'));
    return () => { live = false; };
  }, [clientId]); // eslint-disable-line react-hooks/exhaustive-deps
  return <div ref={ref} style={{ display: 'flex', justifyContent: 'center', minHeight: 44 }}>{msg && <div className="note">{msg}</div>}</div>;
}

function PhoneFlow({ onError, onDone }) {
  const [step, setStep] = useState('phone'); // phone | sms | second
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [second, setSecond] = useState(null); // { step: 'enroll'|'totp', ticket, secret, otpauth }
  const [busy, setBusy] = useState(false);

  const run = (fn) => async (e) => {
    e.preventDefault(); setBusy(true); onError('');
    try { await fn(); } catch (x) { onError(x.message); if (/expired|again/i.test(x.message) && step === 'second') setStep('phone'); }
    setBusy(false);
  };

  if (step === 'phone') {
    return (
      <form onSubmit={run(async () => { await api('auth.phone.start', { phone }); setCode(''); setStep('sms'); })}>
        <label className="field"><span>Phone number (with country code)</span>
          <input type="tel" autoComplete="tel" placeholder="+1 555 123 4567" value={phone} required onChange={(e) => setPhone(e.target.value)} /></label>
        <button className="btn block" type="submit" disabled={busy}>Text me a code</button>
      </form>
    );
  }
  if (step === 'sms') {
    return (
      <form onSubmit={run(async () => { setSecond(await api('auth.phone.verify', { phone, code: code.trim() })); setCode(''); setStep('second'); })}>
        <p className="note">Step 1 of 2: enter the 6-digit code we texted to {phone}.</p>
        <label className="field"><span>SMS code</span>
          <input type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={6} placeholder="123456" value={code} required autoFocus
            onChange={(e) => setCode(e.target.value)} /></label>
        <button className="btn block" type="submit" disabled={busy}>Verify</button>
        <p className="note"><a href="#" onClick={(e) => { e.preventDefault(); setStep('phone'); onError(''); }}>Use a different number</a></p>
      </form>
    );
  }
  const enroll = second.step === 'enroll';
  return (
    <form onSubmit={run(async () => onDone(await api('auth.phone.2fa', { ticket: second.ticket, code: code.trim() })))}>
      {enroll ? (
        <>
          <p className="note">Step 2 of 2: set up your authenticator app (Google Authenticator, Authy, 1Password…). Add this key, then enter the 6-digit code it shows.</p>
          <div className="secret">{second.secret}</div>
          <p className="note"><a href={second.otpauth}>Open in authenticator app (on this phone)</a></p>
        </>
      ) : <p className="note">Step 2 of 2: enter the code from your authenticator app, or one of your recovery codes.</p>}
      <label className="field"><span>{enroll ? 'Authenticator code' : 'Authenticator or recovery code'}</span>
        <input type="text" inputMode="numeric" autoComplete="one-time-code" placeholder="123456" value={code} required autoFocus
          onChange={(e) => setCode(e.target.value)} /></label>
      <button className="btn block" type="submit" disabled={busy}>{enroll ? 'Turn on 2-step & sign in' : 'Sign in'}</button>
    </form>
  );
}
