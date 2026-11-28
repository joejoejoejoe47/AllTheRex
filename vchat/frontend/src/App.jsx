import { useEffect, useState, useCallback } from 'react';
import { api } from './api.js';
import { Avatar, toast } from './ui.jsx';
import AuthScreen from './Auth.jsx';
import Meeting from './Meeting.jsx';

const codeFromHash = () => (location.hash.match(/^#\/m\/([a-z]{3}-[a-z]{4}-[a-z]{3})$/i) || [])[1]?.toLowerCase() || null;

function useHashCode() {
  const [code, setCode] = useState(codeFromHash());
  useEffect(() => {
    const f = () => setCode(codeFromHash());
    window.addEventListener('hashchange', f);
    return () => window.removeEventListener('hashchange', f);
  }, []);
  return code;
}

export default function App() {
  const [config, setConfig] = useState(null);
  const [user, setUser] = useState(null);
  const [inCall, setInCall] = useState(null); // { code, mic, cam, info }
  const code = useHashCode();

  useEffect(() => {
    api('config').then((c) => { setConfig(c); setUser(c.user); })
      .catch(() => { setConfig({ googleClientId: '', iceServers: [], maxParticipants: 20 }); });
  }, []);

  const goHome = useCallback((msg) => { setInCall(null); location.hash = ''; if (msg) toast(msg, 6000); }, []);

  if (!config) return <div className="screen"><div className="note">Loading…</div></div>;
  if (!user) return <AuthScreen config={config} onLogin={(u) => setUser(u)} />;
  if (inCall) {
    return <Meeting {...inCall} user={user} iceServers={config.iceServers} onExit={goHome} />;
  }
  const header = (
    <div className="topbar">
      <strong>vchat</strong>
      <div className="who">
        <Avatar user={user} /><span>{user.name}</span>
        <button className="btn ghost" onClick={async () => { await api('auth.logout', {}); setUser(null); location.hash = ''; }}>Sign out</button>
      </div>
    </div>
  );
  return code
    ? <>{header}<PreJoin key={code} code={code} onJoin={setInCall} /></>
    : <>{header}<Lobby user={user} config={config} onUser={setUser} /></>;
}

function Lobby({ user, config, onUser }) {
  const [name, setName] = useState(user.name);
  const [title, setTitle] = useState('');
  const [join, setJoin] = useState('');
  const [err, setErr] = useState('');

  async function start() {
    setErr('');
    try {
      const n = name.trim();
      if (n && n !== user.name) onUser((await api('me.update', { name: n })).user);
      const m = await api('meeting.create', { title });
      location.hash = `#/m/${m.code}`;
    } catch (e) { setErr(e.message); }
  }
  function doJoin() {
    const m = join.match(/([a-z]{3}-[a-z]{4}-[a-z]{3})/i);
    if (m) { setErr(''); location.hash = `#/m/${m[1].toLowerCase()}`; } else setErr('That does not look like a meeting code.');
  }

  return (
    <div className="lobby">
      <div className="card">
        <h1>New meeting</h1>
        <p className="sub">Start a call and invite up to {config.maxParticipants - 1} others.</p>
        <label className="field"><span>Your display name</span>
          <input type="text" value={name} maxLength={60} onChange={(e) => setName(e.target.value)} /></label>
        <label className="field"><span>Meeting title (optional)</span>
          <input type="text" value={title} maxLength={120} placeholder={`${user.name}'s meeting`} onChange={(e) => setTitle(e.target.value)} /></label>
        <button className="btn block" onClick={start}>Start meeting</button>
      </div>
      <div className="card">
        <h1>Join a meeting</h1>
        <p className="sub">Paste an invite link or enter the meeting code.</p>
        <label className="field"><span>Meeting code or link</span>
          <input type="text" value={join} placeholder="abc-defg-hij" autoCapitalize="none" spellCheck={false}
            onChange={(e) => setJoin(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && doJoin()} /></label>
        <button className="btn block ghost" onClick={doJoin}>Join</button>
        <div className="err" role="alert">{err}</div>
      </div>
    </div>
  );
}

function PreJoin({ code, onJoin }) {
  const [info, setInfo] = useState(null);
  const [error, setError] = useState('');
  const [mic, setMic] = useState(true);
  const [cam, setCam] = useState(true);

  useEffect(() => { api('meeting.get', undefined, { code }).then(setInfo).catch((e) => setError(e.message)); }, [code]);

  if (error) {
    return (
      <div className="screen"><div className="card">
        <h1>Cannot join</h1><p className="sub">{error}</p>
        <button className="btn block" onClick={() => { location.hash = ''; }}>Back</button>
      </div></div>
    );
  }
  if (!info) return <div className="screen"><div className="note">Loading…</div></div>;
  return (
    <div className="screen"><div className="card">
      <h1>{info.title}</h1>
      <p className="sub">{info.participants} of {info.max} in the meeting{info.isHost ? ' · you are the host' : ''}</p>
      <label className="check"><input type="checkbox" checked={mic} onChange={(e) => setMic(e.target.checked)} /> Join with microphone on</label>
      <label className="check"><input type="checkbox" checked={cam} onChange={(e) => setCam(e.target.checked)} /> Join with camera on</label>
      <button className="btn block" disabled={info.full} onClick={() => onJoin({ code, info, mic, cam })}>{info.full ? 'Meeting is full' : 'Join now'}</button>
      <p className="note"><a href="#" onClick={(e) => { e.preventDefault(); location.hash = ''; }}>Cancel</a></p>
    </div></div>
  );
}
