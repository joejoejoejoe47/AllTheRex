import crypto from 'node:crypto';

const rid = () => crypto.randomBytes(5).toString('hex');

/** Signalling + chat for meetings. Media itself flows peer-to-peer (WebRTC). */
export function createRooms({ store, maxParticipants }) {
  const rooms = new Map(); // code -> { code, hostId, peers: Map, banned: Set }

  const pub = (p) => ({ id: p.id, name: p.user.name, picture: p.user.picture || null, state: p.state });
  const send = (p, msg) => p.ws.send(JSON.stringify(msg));
  const broadcast = (room, msg, exceptId) => {
    for (const p of room.peers.values()) if (p.id !== exceptId) send(p, msg);
  };

  function leave(peer) {
    const room = peer.room;
    if (!room || !room.peers.delete(peer.id)) return;
    peer.room = null;
    broadcast(room, { type: 'peer-left', id: peer.id });
    if (!room.peers.size) rooms.delete(room.code);
  }

  async function join(peer, code) {
    if (peer.room || peer.joining) return;
    peer.joining = true;
    try {
      code = String(code || '').toLowerCase();
      const meeting = await store.getMeeting(code);
      if (!meeting || meeting.ended) return fatal(peer, 'not-found', 'This meeting does not exist or has ended.');
      let room = rooms.get(code);
      if (!room) { room = { code, hostId: meeting.hostId, peers: new Map(), banned: new Set() }; rooms.set(code, room); }
      if (room.banned.has(peer.user.id)) return fatal(peer, 'removed', 'You were removed from this meeting.');

      // One seat per account: a newer connection replaces an older one.
      for (const old of [...room.peers.values()]) {
        if (old.user.id === peer.user.id) { send(old, { type: 'replaced' }); leave(old); old.ws.close(4001); }
      }
      if (room.peers.size >= maxParticipants) {
        return fatal(peer, 'full', `This meeting is full (${maxParticipants} people max).`);
      }
      const others = [...room.peers.values()].map(pub);
      room.peers.set(peer.id, peer);
      peer.room = room;
      send(peer, { type: 'joined', you: peer.id, hostUserId: room.hostId, title: meeting.title, peers: others, max: maxParticipants });
      broadcast(room, { type: 'peer-joined', peer: pub(peer) }, peer.id);
    } finally {
      peer.joining = false;
    }
  }

  function fatal(peer, code, message) {
    send(peer, { type: 'error', code, message });
    peer.ws.close(4000);
    if (peer.room) leave(peer);
  }

  async function onMessage(peer, raw) {
    let m;
    try { m = JSON.parse(raw); } catch { return; }
    if (!m || typeof m.type !== 'string') return;

    // token bucket: 400 burst, refills 100/s (ICE candidates arrive in bursts)
    const now = Date.now();
    peer.tokens = Math.min(400, peer.tokens + ((now - peer.tokenAt) / 1000) * 100);
    peer.tokenAt = now;
    if (--peer.tokens < 0) return peer.ws.close(4008);

    if (m.type === 'join') return join(peer, m.code);
    const room = peer.room;
    if (!room) return;

    switch (m.type) {
      case 'signal': {
        const to = room.peers.get(m.to);
        if (to && to.id !== peer.id && m.data && typeof m.data === 'object') send(to, { type: 'signal', from: peer.id, data: m.data });
        break;
      }
      case 'state': {
        peer.state = { muted: !!m.muted, cameraOff: !!m.cameraOff, sharing: !!m.sharing };
        broadcast(room, { type: 'state', id: peer.id, state: peer.state }, peer.id);
        break;
      }
      case 'chat': {
        const text = String(m.text || '').trim().slice(0, 2000);
        if (text) broadcast(room, { type: 'chat', from: peer.id, name: peer.user.name, text, ts: now }, peer.id);
        break;
      }
      case 'kick': {
        if (peer.user.id !== room.hostId) return;
        const t = room.peers.get(m.id);
        if (!t || t.id === peer.id) return;
        room.banned.add(t.user.id);
        send(t, { type: 'kicked' });
        leave(t);
        t.ws.close(4003);
        break;
      }
      case 'end': {
        if (peer.user.id !== room.hostId) return;
        await store.endMeeting(room.code);
        broadcast(room, { type: 'ended' });
        for (const p of [...room.peers.values()]) { p.room = null; p.ws.close(4004); }
        rooms.delete(room.code);
        break;
      }
    }
  }

  return {
    attach(ws, user) {
      const peer = { id: rid(), ws, user, room: null, joining: false, tokens: 400, tokenAt: Date.now(),
        state: { muted: true, cameraOff: true, sharing: false } };
      ws.on('message', (raw) => onMessage(peer, raw).catch((e) => console.error('[rooms]', e)));
      ws.on('close', () => leave(peer));
    },
    count: (code) => rooms.get(code)?.peers.size || 0,
  };
}
