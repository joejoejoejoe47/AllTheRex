import { h, avatar, toast } from './ui.js';

/**
 * Meeting room: full-mesh WebRTC (every participant connects to every other), signalled over /ws.
 * Mesh is fine for small calls; upload bandwidth grows with (participants - 1), so video quality is
 * lowered automatically as the room fills (see applyQuality). For consistently good 15-20 person
 * calls, replace this media layer with an SFU (LiveKit, mediasoup) and keep the rest of the app.
 */
export async function startMeeting({ root, code, info, user, iceServers, mic, cam, onExit }) {
  const peers = new Map();   // id -> { pc, q, initiator, tile }
  const roster = new Map();  // id -> { id, name, picture, state }
  const tiles = new Map();   // id -> tile ("me" for self)
  const chat = [];
  let myId = null, hostUserId = null, ended = false, unread = 0, panel = null, sharing = false;
  let audioTrack = null, camTrack = null, screenTrack = null;
  const me = { muted: true, cameraOff: true, sharing: false };

  /* ---- local media ---- */
  const note = [];
  if (mic) {
    try { audioTrack = (await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } })).getAudioTracks()[0]; }
    catch { note.push('microphone'); }
  }
  if (cam) camTrack = await getCam(note);
  me.muted = !audioTrack; me.cameraOff = !camTrack;
  if (note.length) toast(`Could not access your ${note.join(' and ')}. You can still join.`, 5000);

  async function getCam(n) {
    try {
      return (await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30, max: 30 } } })).getVideoTracks()[0];
    } catch { n?.push('camera'); return null; }
  }
  const videoOut = () => screenTrack || camTrack;

  /* ---- UI skeleton ---- */
  const stage = h('div', { class: 'stage grid' });
  const sideEl = h('aside', { class: 'side hidden' });
  const count = h('span', { class: 'c' });
  const btns = {};
  const cbtn = (key, icon, label, onclick, cls = '') => (btns[key] = h('button', { class: `cbtn ${cls}`, onclick, type: 'button', 'aria-label': label },
    h('span', { class: 'ic' }, icon), h('span', { class: 'lb' }, label)));

  const controls = h('div', { class: 'controls' },
    cbtn('mic', '🎤', 'Mute', toggleMic), cbtn('cam', '📷', 'Stop video', toggleCam),
    cbtn('share', '🖥️', 'Share', toggleShare), cbtn('ppl', '👥', 'People', () => togglePanel('people')),
    cbtn('chat', '💬', 'Chat', () => togglePanel('chat')),
    cbtn('copy', '🔗', 'Invite', copyInvite),
    cbtn('leave', '⎋', 'Leave', () => leave(), 'leave'));
  if (!navigator.mediaDevices.getDisplayMedia) btns.share.classList.add('hidden');

  const view = h('div', { class: 'meeting' },
    h('div', { class: 'mhead' }, h('div', { class: 't' }, info.title), count),
    h('div', { class: 'mbody' }, stage, sideEl), controls);
  root.replaceChildren(view);

  /* ---- tiles ---- */
  function makeTile(id, who, local) {
    const video = h('video', { autoplay: true, playsInline: true, muted: !!local });
    const nm = h('span', { class: 'nm' }, local ? `${who.name} (You)` : who.name);
    const el = h('div', { class: 'tile' + (local ? ' mirror' : '') },
      video, h('div', { class: 'bigava' }, avatar(who)), h('div', { class: 'label' }, nm), h('div', { class: 'conn' }, 'Connecting…'));
    const t = { id, el, video, nm, stream: new MediaStream(), state: local ? me : who.state };
    video.srcObject = t.stream;
    tiles.set(id, t);
    paintTile(t);
    return t;
  }
  function paintTile(t) {
    const s = t.state;
    t.el.classList.toggle('muted', !!s.muted);
    t.el.classList.toggle('cam-off', !!s.cameraOff && !s.sharing);
  }
  function setLocalVideo() {
    const t = tiles.get('me'); if (!t) return;
    for (const tr of t.stream.getVideoTracks()) t.stream.removeTrack(tr);
    const v = videoOut(); if (v) t.stream.addTrack(v);
    t.el.classList.toggle('mirror', !screenTrack);
    me.cameraOff = !camTrack; me.sharing = !!screenTrack; paintTile(t);
  }
  function layout() {
    const all = [...tiles.values()];
    const sharer = all.find((t) => t.state.sharing);
    count.textContent = `${all.length} / ${info.max} people`;
    if (!sharer) {
      stage.className = 'stage grid';
      const n = all.length;
      const cols = n <= 1 ? 1 : n <= 4 ? 2 : n <= 9 ? 3 : n <= 16 ? 4 : 5;
      stage.style.setProperty('--cols', cols);
      stage.style.setProperty('--rows', Math.ceil(n / cols));
      stage.replaceChildren(...all.map((t) => t.el));
    } else {
      stage.className = 'stage spot';
      stage.replaceChildren(h('div', { class: 'main' }, sharer.el), h('div', { class: 'strip' }, all.filter((t) => t !== sharer).map((t) => t.el)));
    }
    if (panel === 'people') renderPanel();
    applyQuality();
  }

  /* ---- peers ---- */
  function createPeer(id, initiator) {
    const pc = new RTCPeerConnection({ iceServers });
    const p = { id, pc, initiator, q: Promise.resolve(), tile: tiles.get(id) };
    peers.set(id, p);
    if (initiator) { // the answering side picks up the transceivers created by the offer (see onSignal)
      p.audio = pc.addTransceiver('audio', { direction: 'sendrecv' });
      p.video = pc.addTransceiver('video', { direction: 'sendrecv' });
      attachLocal(p);
    }
    pc.onicecandidate = (e) => e.candidate && send({ type: 'signal', to: id, data: { candidate: e.candidate } });
    pc.ontrack = (e) => {
      const t = tiles.get(id); if (!t) return;
      if (!t.stream.getTracks().includes(e.track)) t.stream.addTrack(e.track);
      t.video.play?.().catch(() => {});
    };
    pc.onconnectionstatechange = () => {
      const t = tiles.get(id);
      if (t) t.el.classList.toggle('connecting', !['connected', 'completed'].includes(pc.connectionState));
      if (pc.connectionState === 'connected') applyQuality();
      if (pc.connectionState === 'failed' && p.initiator) offer(p, true);
    };
    return p;
  }
  function attachLocal(p) {
    if (audioTrack) p.audio.sender.replaceTrack(audioTrack).catch(() => {});
    if (videoOut()) p.video.sender.replaceTrack(videoOut()).catch(() => {});
  }
  async function offer(p, iceRestart = false) {
    p.q = p.q.then(async () => {
      await p.pc.setLocalDescription(await p.pc.createOffer({ iceRestart }));
      send({ type: 'signal', to: p.id, data: { sdp: p.pc.localDescription } });
    }).catch((e) => console.warn('offer', e));
  }
  function onSignal(from, data) {
    const p = peers.get(from) || (roster.has(from) ? createPeer(from, false) : null);
    if (!p) return;
    p.q = p.q.then(async () => { // serialised so candidates never overtake the offer
      if (data.sdp) {
        await p.pc.setRemoteDescription(data.sdp);
        if (data.sdp.type === 'offer') {
          if (!p.audio) { // first offer: turn the receive-only transceivers into send+receive
            for (const tr of p.pc.getTransceivers()) {
              tr.direction = 'sendrecv';
              p[tr.receiver.track.kind] = tr;
            }
            attachLocal(p);
          }
          await p.pc.setLocalDescription(await p.pc.createAnswer());
          send({ type: 'signal', to: from, data: { sdp: p.pc.localDescription } });
        }
      } else if (data.candidate) {
        await p.pc.addIceCandidate(data.candidate).catch(() => {});
      }
    }).catch((e) => console.warn('signal', e));
  }
  function dropPeer(id) {
    const p = peers.get(id); if (p) { p.pc.close(); peers.delete(id); }
    tiles.get(id)?.stream.getTracks().forEach((t) => t.stop?.());
    tiles.delete(id); roster.delete(id);
    layout();
  }

  // Lower per-stream bitrate as the room grows so total upload stays reasonable.
  function applyQuality() {
    const n = tiles.size;
    const vBps = n <= 3 ? 1_200_000 : n <= 6 ? 600_000 : n <= 10 ? 300_000 : n <= 15 ? 150_000 : 90_000;
    const scale = n <= 6 ? 1 : n <= 12 ? 1.5 : 2;
    for (const p of peers.values()) {
      const s = p.video?.sender; if (!s?.track) continue;
      const prm = s.getParameters();
      if (!prm.encodings?.length) prm.encodings = [{}];
      prm.encodings[0].maxBitrate = screenTrack ? Math.max(vBps, 800_000) : vBps;
      prm.encodings[0].scaleResolutionDownBy = screenTrack ? 1 : scale;
      s.setParameters(prm).catch(() => {});
    }
  }

  /* ---- signalling socket ---- */
  const ws = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`);
  const send = (m) => ws.readyState === 1 && ws.send(JSON.stringify(m));
  const ready = new Promise((res, rej) => { ws.onopen = res; ws.onerror = () => rej(new Error('Could not connect to the meeting server.')); });
  await ready;
  ws.onopen = null;

  const joined = new Promise((res, rej) => { ws._joined = res; ws._reject = rej; });
  ws.onmessage = (ev) => {
    let m; try { m = JSON.parse(ev.data); } catch { return; }
    switch (m.type) {
      case 'joined':
        myId = m.you; hostUserId = m.hostUserId;
        makeTile('me', user, true); setLocalVideo();
        for (const pr of m.peers) { roster.set(pr.id, pr); makeTile(pr.id, pr); }
        layout();
        for (const pr of m.peers) offer(createPeer(pr.id, true));
        send({ type: 'state', ...me });
        ws._joined();
        break;
      case 'peer-joined': roster.set(m.peer.id, m.peer); makeTile(m.peer.id, m.peer); layout(); toast(`${m.peer.name} joined`, 2000); break;
      case 'peer-left': { const n = roster.get(m.id)?.name; dropPeer(m.id); if (n) toast(`${n} left`, 2000); break; }
      case 'signal': onSignal(m.from, m.data); break;
      case 'state': {
        const r = roster.get(m.id), t = tiles.get(m.id);
        if (r && t) { r.state = m.state; t.state = m.state; paintTile(t); layout(); }
        break;
      }
      case 'chat':
        chat.push(m);
        if (panel !== 'chat') { unread++; badge(); } else renderPanel();
        break;
      case 'error': ws._reject?.(new Error(m.message)); ended = true; break;
      case 'kicked': ended = true; cleanup('The host removed you from the meeting.'); break;
      case 'ended': ended = true; cleanup('The host ended the meeting.'); break;
      case 'replaced': ended = true; cleanup('You joined this meeting from another window.'); break;
    }
  };
  ws.onclose = () => { if (!ended) { ended = true; cleanup('Connection lost. Rejoin from the meeting link.'); } };
  send({ type: 'join', code });
  try { await joined; } catch (e) { stopLocal(); throw e; }

  /* ---- controls ---- */
  function syncButtons() {
    const set = (k, off, onL, offL, ic) => { btns[k].classList.toggle('off', off); btns[k].querySelector('.lb').textContent = off ? offL : onL; if (ic) btns[k].querySelector('.ic').textContent = ic[off ? 1 : 0]; };
    set('mic', me.muted, 'Mute', 'Unmute', ['🎤', '🔇']);
    set('cam', me.cameraOff && !screenTrack, 'Stop video', 'Start video', ['📷', '🚫']);
    btns.share.classList.toggle('on', !!screenTrack);
    btns.share.querySelector('.lb').textContent = screenTrack ? 'Stop share' : 'Share';
  }
  function pushState() { const t = tiles.get('me'); t && paintTile(t); send({ type: 'state', ...me }); syncButtons(); layout(); }
  function replaceVideo() { for (const p of peers.values()) p.video?.sender.replaceTrack(videoOut()).catch(() => {}); setLocalVideo(); applyQuality(); }

  async function toggleMic() {
    if (!audioTrack) {
      try { audioTrack = (await navigator.mediaDevices.getUserMedia({ audio: true })).getAudioTracks()[0]; }
      catch { return toast('Microphone unavailable. Check browser permissions.'); }
      for (const p of peers.values()) p.audio?.sender.replaceTrack(audioTrack).catch(() => {});
      me.muted = false;
    } else { audioTrack.enabled = !audioTrack.enabled; me.muted = !audioTrack.enabled; }
    pushState();
  }
  async function toggleCam() {
    if (camTrack) { camTrack.stop(); camTrack = null; }
    else {
      const n = []; camTrack = await getCam(n);
      if (!camTrack) return toast('Camera unavailable. Check browser permissions.');
    }
    if (!screenTrack) replaceVideo(); else setLocalVideo();
    pushState();
  }
  async function toggleShare() {
    if (screenTrack) return stopShare();
    if ([...tiles.values()].some((t) => t.id !== 'me' && t.state.sharing)) return toast('Someone else is already sharing.');
    try {
      const s = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { max: 15 } }, audio: false });
      screenTrack = s.getVideoTracks()[0];
      screenTrack.contentHint = 'detail';
      screenTrack.onended = stopShare;
      replaceVideo(); pushState();
    } catch { /* user cancelled */ }
  }
  function stopShare() {
    if (!screenTrack) return;
    screenTrack.onended = null; screenTrack.stop(); screenTrack = null;
    replaceVideo(); pushState();
  }
  async function copyInvite() {
    const url = `${location.origin}${location.pathname}#/m/${code}`;
    try { await navigator.clipboard.writeText(url); toast('Invite link copied'); } catch { prompt('Copy this invite link:', url); }
  }

  /* ---- side panel ---- */
  function badge() {
    btns.chat.querySelector('.badge')?.remove();
    if (unread) btns.chat.append(h('span', { class: 'badge' }, unread));
  }
  function togglePanel(which) {
    panel = panel === which ? null : which;
    sideEl.classList.toggle('hidden', !panel);
    if (panel === 'chat') { unread = 0; badge(); }
    renderPanel();
  }
  function renderPanel() {
    if (!panel) return;
    const tabs = h('header', {},
      h('button', { class: panel === 'people' ? 'on' : '', onclick: () => togglePanelTo('people') }, `People (${tiles.size})`),
      h('button', { class: panel === 'chat' ? 'on' : '', onclick: () => togglePanelTo('chat') }, 'Chat'));
    const iAmHost = user.id === hostUserId;
    let body;
    if (panel === 'people') {
      body = h('div', { class: 'pane' }, [...tiles.values()].map((t) => {
        const who = t.id === 'me' ? user : roster.get(t.id);
        const isHost = t.id === 'me' ? iAmHost : false;
        return h('div', { class: 'person' }, avatar(who), h('span', { class: 'nm' }, who.name, t.id === 'me' ? ' (You)' : ''),
          h('small', {}, t.state.muted ? '🔇' : '🎤'),
          iAmHost && t.id !== 'me' ? h('button', { class: 'btn ghost', onclick: () => confirm(`Remove ${who.name}?`) && send({ type: 'kick', id: t.id }) }, 'Remove') : null,
          isHost ? h('small', {}, 'Host') : null);
      }));
      if (iAmHost) body.append(h('button', { class: 'btn danger block', style: 'margin-top:12px', onclick: () => confirm('End the meeting for everyone?') && send({ type: 'end' }) }, 'End meeting for all'));
    } else {
      const list = h('div', { class: 'pane' }, chat.map((c) => h('div', { class: 'msg' },
        h('b', {}, c.from === myId ? 'You' : c.name), h('time', {}, new Date(c.ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })), h('div', {}, c.text))));
      const input = h('input', { type: 'text', placeholder: 'Message everyone', maxLength: 2000, autocomplete: 'off' });
      const form = h('form', { class: 'chatform', onsubmit: (e) => {
        e.preventDefault(); const text = input.value.trim(); if (!text) return;
        send({ type: 'chat', text }); chat.push({ from: myId, name: user.name, text, ts: Date.now() }); input.value = ''; renderPanel();
      } }, input, h('button', { class: 'btn', type: 'submit' }, 'Send'));
      body = h('div', { style: 'display:flex;flex-direction:column;flex:1;min-height:0' }, list, form);
      queueMicrotask(() => { list.scrollTop = list.scrollHeight; if (document.activeElement === document.body) input.focus(); });
    }
    sideEl.replaceChildren(tabs, body);
  }
  function togglePanelTo(w) { panel = w; if (w === 'chat') { unread = 0; badge(); } renderPanel(); }

  /* ---- teardown ---- */
  function stopLocal() {
    for (const t of [audioTrack, camTrack, screenTrack]) t?.stop();
    audioTrack = camTrack = screenTrack = null;
  }
  function cleanup(msg) {
    for (const p of peers.values()) p.pc.close();
    peers.clear(); tiles.clear(); stopLocal();
    try { ws.close(); } catch { /* ignore */ }
    window.removeEventListener('beforeunload', onUnload);
    onExit(msg);
  }
  function leave() { ended = true; cleanup(); }
  const onUnload = () => { try { ws.close(); } catch { /* ignore */ } };
  window.addEventListener('beforeunload', onUnload);
  syncButtons();
}
