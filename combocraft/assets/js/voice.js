// Team voice for Combo Clash parties. Morse Chess approach: WebRTC mesh
// signaled over the PHP API (cc_clash_rtc_*), audio only, no camera.

const ICE = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun.cloudflare.com:3478'] }];

export function createVoice({ apiBase, room, selfId, name, headers = {} }) {
  const peers = new Map();
  const audioEls = new Map();
  let stream = null;
  let cursor = 0;
  let timer = 0;
  let closed = true;
  let muteSelf = false;
  let muteAll = false;

  async function start() {
    closed = false;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
      applyMute();
    } catch (e) {
      console.warn('voice mic', e);
    }
    poll();
  }

  function stop() {
    closed = true;
    clearTimeout(timer);
    for (const slot of peers.values()) try { slot.pc.close(); } catch {}
    peers.clear();
    for (const el of audioEls.values()) el.remove();
    audioEls.clear();
    if (stream) stream.getTracks().forEach((t) => t.stop());
    stream = null;
    const body = JSON.stringify({ op: 'leave', room, peer: selfId });
    try {
      fetch(apiBase + 'api/clash/rtc', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CC': '1', ...headers }, body, credentials: 'same-origin', keepalive: true });
    } catch {}
  }

  function setMuteSelf(on) { muteSelf = !!on; applyMute(); }
  function setMuteAll(on) { muteAll = !!on; applyMute(); }
  function applyMute() {
    if (stream) stream.getAudioTracks().forEach((t) => { t.enabled = !muteSelf; });
    for (const el of audioEls.values()) el.muted = muteAll;
  }

  async function poll() {
    if (closed) return;
    try {
      const q = new URLSearchParams({ room, peer: selfId, name: name || '', since: String(cursor) });
      const r = await fetch(apiBase + 'api/clash/rtc?' + q, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (d && d.ok !== false) {
        for (const s of d.signals || []) {
          cursor = Math.max(cursor, s.id);
          await onSignal(s);
        }
        const ids = new Set((d.peers || []).map((p) => p.id));
        for (const p of d.peers || []) {
          if (p.id !== selfId) ensurePeer(p.id, true);
        }
        for (const [id, slot] of [...peers]) {
          if (!ids.has(id)) {
            try { slot.pc.close(); } catch {}
            peers.delete(id);
            const el = audioEls.get(id);
            if (el) { el.remove(); audioEls.delete(id); }
          }
        }
      }
    } catch {}
    if (!closed) timer = setTimeout(poll, 800);
  }

  function ensurePeer(id, mayOffer) {
    if (peers.has(id)) return peers.get(id);
    const pc = new RTCPeerConnection({ iceServers: ICE });
    const polite = selfId < id;
    const slot = { pc, polite, makingOffer: false, ignoreOffer: false };
    if (stream) stream.getTracks().forEach((t) => pc.addTrack(t, stream));
    pc.onicecandidate = (ev) => {
      if (ev.candidate) signal(id, 'ice', ev.candidate.toJSON ? ev.candidate.toJSON() : ev.candidate);
    };
    pc.ontrack = (ev) => {
      let el = audioEls.get(id);
      if (!el) {
        el = document.createElement('audio');
        el.autoplay = true;
        el.playsInline = true;
        el.dataset.peer = id;
        document.body.appendChild(el);
        audioEls.set(id, el);
      }
      el.srcObject = ev.streams[0] || new MediaStream([ev.track]);
      el.muted = muteAll;
    };
    pc.onnegotiationneeded = async () => {
      try {
        slot.makingOffer = true;
        await pc.setLocalDescription(await pc.createOffer());
        await signal(id, 'offer', pc.localDescription);
      } catch (e) { console.warn('voice offer', e); }
      finally { slot.makingOffer = false; }
    };
    peers.set(id, slot);
    return slot;
  }

  async function onSignal(s) {
    const slot = ensurePeer(s.from, false);
    const pc = slot.pc;
    if (s.kind === 'offer' || s.kind === 'answer') {
      const desc = s.payload;
      const offerCollision = s.kind === 'offer' && (slot.makingOffer || pc.signalingState !== 'stable');
      slot.ignoreOffer = !slot.polite && offerCollision;
      if (slot.ignoreOffer) return;
      if (offerCollision) {
        try { await pc.setLocalDescription({ type: 'rollback' }); } catch {}
      }
      await pc.setRemoteDescription(desc);
      if (s.kind === 'offer') {
        await pc.setLocalDescription(await pc.createAnswer());
        await signal(s.from, 'answer', pc.localDescription);
      }
    } else if (s.kind === 'ice' && s.payload) {
      try { await pc.addIceCandidate(s.payload); } catch {}
    }
  }

  async function signal(to, kind, payload) {
    try {
      await fetch(apiBase + 'api/clash/rtc', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CC': '1' },
        body: JSON.stringify({ op: 'signal', room, from: selfId, to, kind, payload }),
      });
    } catch {}
  }

  return { start, stop, setMuteSelf, setMuteAll, get muteSelf() { return muteSelf; }, get muteAll() { return muteAll; } };
}
