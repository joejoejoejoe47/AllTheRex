import { useEffect, useRef, useState } from 'react';
import { Room } from './room.js';
import { Avatar, toast } from './ui.jsx';

function Tile({ id, who, state, stream, local, mirror, connecting }) {
  const ref = useRef(null);
  useEffect(() => { if (ref.current && ref.current.srcObject !== stream) ref.current.srcObject = stream; }, [stream]);
  const cls = ['tile', local && mirror ? 'mirror' : '', state.muted ? 'muted' : '', state.cameraOff && !state.sharing ? 'cam-off' : '', connecting ? 'connecting' : '']
    .filter(Boolean).join(' ');
  return (
    <div className={cls} data-peer={id}>
      <video ref={ref} autoPlay playsInline muted={local} />
      <div className="bigava"><Avatar user={who} /></div>
      <div className="label"><span className="nm">{local ? `${who.name} (You)` : who.name}</span></div>
      <div className="conn">Connecting…</div>
    </div>
  );
}

export default function Meeting({ code, info, user, iceServers, mic, cam, onExit }) {
  const roomRef = useRef(null);
  const [snap, setSnap] = useState(null);
  const [chat, setChat] = useState([]);
  const [panel, setPanel] = useState(null); // 'people' | 'chat' | null
  const [unread, setUnread] = useState(0);
  const panelRef = useRef(null);
  panelRef.current = panel;

  useEffect(() => {
    let cancelled = false;
    const room = new Room({
      code, user, iceServers, mic, cam,
      onChange: (s) => !cancelled && setSnap(s),
      onChat: (m) => {
        if (cancelled) return;
        setChat((c) => [...c, m]);
        if (!m.system && panelRef.current !== 'chat') setUnread((n) => n + 1);
      },
      onEnd: (reason) => !cancelled && onExit(reason),
    });
    roomRef.current = room;
    room.start().then((problems) => {
      if (problems?.length && !cancelled) toast(`Could not access your ${problems.join(' and ')}. You can still join.`, 5000);
      if (cancelled) room.leave();
    }).catch((e) => !cancelled && onExit(e.message));
    return () => { cancelled = true; room.leave(); };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const room = roomRef.current;
  if (!snap) return <div className="screen"><div className="note">Joining…</div></div>;

  const act = async (fn) => { const msg = await fn(); if (msg) toast(msg); };
  const togglePanel = (w) => { const next = panel === w ? null : w; setPanel(next); if (next === 'chat') setUnread(0); };
  const iAmHost = user.id === snap.hostUserId;
  const everyone = [{ id: 'me', who: snap.me, ...snap.me, local: true }, ...snap.peers.map((p) => ({ ...p, who: p, local: false }))];
  const sharer = everyone.find((t) => t.state.sharing);
  const n = everyone.length;
  const cols = n <= 1 ? 1 : n <= 4 ? 2 : n <= 9 ? 3 : n <= 16 ? 4 : 5;
  const tile = (t) => <Tile key={t.id} id={t.id} who={t.who} state={t.state} stream={t.stream} local={t.local} mirror={t.mirror} connecting={t.connecting} />;

  async function copyInvite() {
    const url = `${location.origin}${location.pathname}#/m/${code}`;
    try { await navigator.clipboard.writeText(url); toast('Invite link copied'); } catch { window.prompt('Copy this invite link:', url); }
  }

  const { me } = snap;
  return (
    <div className="meeting">
      <div className="mhead"><div className="t">{snap.title || info.title}</div><span className="c">{n} / {snap.max} people</span></div>
      <div className="mbody">
        {sharer ? (
          <div className="stage spot">
            <div className="main">{tile(sharer)}</div>
            <div className="strip">{everyone.filter((t) => t !== sharer).map(tile)}</div>
          </div>
        ) : (
          <div className="stage grid" style={{ '--cols': cols, '--rows': Math.ceil(n / cols) }}>{everyone.map(tile)}</div>
        )}
        {panel && (
          <aside className="side">
            <header>
              <button className={panel === 'people' ? 'on' : ''} onClick={() => setPanel('people')}>People ({n})</button>
              <button className={panel === 'chat' ? 'on' : ''} onClick={() => { setPanel('chat'); setUnread(0); }}>Chat</button>
            </header>
            {panel === 'people'
              ? <People everyone={everyone} iAmHost={iAmHost} hostUserId={snap.hostUserId} user={user} room={room} />
              : <Chat chat={chat} onSend={(text) => { room.sendChat(text); setChat((c) => [...c, { from: 'me', name: user.name, text, ts: Date.now() }]); }} />}
          </aside>
        )}
      </div>
      <div className="controls">
        <button className={`cbtn ${me.state.muted ? 'off' : ''}`} onClick={() => act(() => room.toggleMic())}><span className="ic">{me.state.muted ? '🔇' : '🎤'}</span><span>{me.state.muted ? 'Unmute' : 'Mute'}</span></button>
        <button className={`cbtn ${me.state.cameraOff && !me.state.sharing ? 'off' : ''}`} onClick={() => act(() => room.toggleCam())}><span className="ic">{me.state.cameraOff ? '🚫' : '📷'}</span><span>{me.state.cameraOff ? 'Start video' : 'Stop video'}</span></button>
        {navigator.mediaDevices?.getDisplayMedia && (
          <button className={`cbtn ${me.state.sharing ? 'on' : ''}`} onClick={() => act(() => room.toggleShare())}><span className="ic">🖥️</span><span>{me.state.sharing ? 'Stop share' : 'Share'}</span></button>
        )}
        <button className="cbtn" onClick={() => togglePanel('people')}><span className="ic">👥</span><span>People</span></button>
        <button className="cbtn" onClick={() => togglePanel('chat')}><span className="ic">💬</span><span>Chat</span>{unread > 0 && <span className="badge">{unread}</span>}</button>
        <button className="cbtn" onClick={copyInvite}><span className="ic">🔗</span><span>Invite</span></button>
        <button className="cbtn leave" onClick={() => { room.leave().then(() => onExit()); }}><span className="ic">⎋</span><span>Leave</span></button>
      </div>
    </div>
  );
}

function People({ everyone, iAmHost, hostUserId, user, room }) {
  return (
    <div className="pane">
      {everyone.map((t) => (
        <div className="person" key={t.id}>
          <Avatar user={t.who} />
          <span className="nm">{t.who.name}{t.local ? ' (You)' : ''}</span>
          <small>{t.state.muted ? '🔇' : '🎤'}</small>
          {t.local && iAmHost && <small>Host</small>}
          {iAmHost && !t.local && <button className="btn ghost" onClick={() => window.confirm(`Remove ${t.who.name}?`) && room.kick(t.id)}>Remove</button>}
        </div>
      ))}
      {iAmHost && <button className="btn danger block" style={{ marginTop: 12 }} onClick={() => window.confirm('End the meeting for everyone?') && room.endForAll()}>End meeting for all</button>}
    </div>
  );
}

function Chat({ chat, onSend }) {
  const [text, setText] = useState('');
  const list = useRef(null);
  useEffect(() => { if (list.current) list.current.scrollTop = list.current.scrollHeight; }, [chat]);
  return (
    <div style={{ display: 'flex', flexDirection: 'column', flex: 1, minHeight: 0 }}>
      <div className="pane" ref={list}>
        {chat.map((m, i) => m.system
          ? <div className="msg note" key={i}>{m.text}</div>
          : <div className="msg" key={i}>
              <b>{m.from === 'me' ? 'You' : m.name}</b>
              <time>{new Date(m.ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</time>
              <div>{m.text}</div>
            </div>)}
      </div>
      <form className="chatform" onSubmit={(e) => { e.preventDefault(); const t = text.trim(); if (t) { onSend(t); setText(''); } }}>
        <input type="text" value={text} maxLength={2000} placeholder="Message everyone" autoComplete="off" onChange={(e) => setText(e.target.value)} />
        <button className="btn" type="submit">Send</button>
      </form>
    </div>
  );
}
