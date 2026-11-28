import { api, beacon } from './api.js';

/**
 * Framework-independent meeting engine.
 *  - Signalling: HTTP long/short polling against api.php (works on plain PHP hosting).
 *  - Media: full-mesh WebRTC; quality is reduced automatically as the room fills (applyQuality).
 * The UI subscribes via onChange(snapshot) and calls the action methods.
 */
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export class Room {
  constructor({ code, user, iceServers, mic, cam, onChange, onChat, onEnd }) {
    Object.assign(this, { code, user, iceServers, wantMic: mic, wantCam: cam, onChange, onChat, onEnd });
    this.peers = new Map();       // id -> { id, name, picture, state, stream, pc?, q, initiator, connecting }
    this.meStream = new MediaStream();
    this.me = { muted: true, cameraOff: true, sharing: false };
    this.audioTrack = this.camTrack = this.screenTrack = null;
    this.outbox = []; this.sending = false; this.flushTimer = null;
    this.since = 0; this.seen = new Set(); this.closed = false; this.hot = 0;
    this.peerId = null; this.hostUserId = null; this.max = 20; this.title = '';
  }

  /* ---------- lifecycle ---------- */
  async start() {
    const problems = [];
    if (this.wantMic) {
      try { this.audioTrack = (await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } })).getAudioTracks()[0]; }
      catch { problems.push('microphone'); }
    }
    if (this.wantCam) this.camTrack = await this.#getCam(problems);
    this.me.muted = !this.audioTrack; this.me.cameraOff = !this.camTrack;
    this.#setLocalVideo();

    let j;
    try { j = await api('room.join', { code: this.code }); } catch (e) { this.#stopLocal(); throw e; }
    Object.assign(this, { peerId: j.peer, since: j.since, hostUserId: j.hostUserId, max: j.max, title: j.title });
    for (const p of j.peers) this.#addPeer(p);
    for (const p of j.peers) this.#offer(this.#createPeer(p.id, true));
    this.#queue({ type: 'state', ...this.me });
    this.#emit();
    this.#pollLoop();
    this.unload = () => beacon('room.leave', { peer: this.peerId });
    window.addEventListener('pagehide', this.unload);
    return problems;
  }

  async leave() {
    if (this.closed) return;
    const peer = this.peerId;
    this.#teardown();
    try { await api('room.leave', { peer }); } catch { /* best effort */ }
  }

  #teardown() {
    this.closed = true;
    clearTimeout(this.flushTimer);
    for (const p of this.peers.values()) p.pc?.close();
    this.peers.clear();
    this.#stopLocal();
    window.removeEventListener('pagehide', this.unload);
  }

  #finish(reason) { if (this.closed) return; this.#teardown(); this.onEnd(reason); }

  /* ---------- state for the UI ---------- */
  snapshot() {
    const peers = [...this.peers.values()].map((p) => ({ id: p.id, name: p.name, picture: p.picture, state: p.state, stream: p.stream, connecting: p.connecting }));
    return {
      me: { name: this.user.name, picture: this.user.picture, state: { ...this.me }, stream: this.meStream, mirror: !this.screenTrack },
      peers, hostUserId: this.hostUserId, max: this.max, title: this.title,
    };
  }
  #emit() { if (!this.closed) { this.#applyQuality(); this.onChange(this.snapshot()); } }

  /* ---------- polling transport ---------- */
  async #pollLoop() {
    let fails = 0;
    while (!this.closed) {
      const t0 = Date.now();
      let delay = Date.now() - this.hot < 8000 ? 250 : 1200;
      try {
        const r = await api('room.poll', { peer: this.peerId, since: this.since });
        fails = 0;
        for (const ev of r.events) this.#handle(ev);
        if (r.events.length) this.hot = Date.now();
        if (r.gone) {
          return this.#finish({ kicked: 'The host removed you from the meeting.', ended: 'The host ended the meeting.',
            replaced: 'You joined this meeting from another window.' }[r.gone] || (r.gone === 'left' ? 'You left the meeting.' : null));
        }
        if (Date.now() - t0 > 1000) delay = 0; // server held the request (long-poll): go again immediately
      } catch (e) {
        if (e.status === 401 || e.status === 410) return this.#finish('You are no longer in this meeting.');
        if (++fails > 10) return this.#finish('Connection lost. Rejoin from the meeting link.');
        delay = Math.min(4000, 500 * fails);
      }
      await sleep(delay);
    }
  }

  #handle(ev) {
    if (this.seen.has(ev.id)) return;
    this.seen.add(ev.id);
    if (ev.id > this.since) this.since = ev.id;
    if (this.seen.size > 600) for (const id of this.seen) if (id < this.since - 300) this.seen.delete(id);

    switch (ev.type) {
      case 'peer-joined': { const p = ev.data.peer; if (!this.peers.has(p.id)) { this.#addPeer(p); this.#emit(); this.onChat?.({ system: true, text: `${p.name} joined` }); } break; }
      case 'peer-left': { const p = this.peers.get(ev.from); if (p) { p.pc?.close(); this.peers.delete(ev.from); this.#emit(); this.onChat?.({ system: true, text: `${p.name} left` }); } break; }
      case 'state': { const p = this.peers.get(ev.from); if (p) { p.state = ev.data; this.#emit(); } break; }
      case 'chat': this.onChat?.({ from: ev.from, name: ev.data.name, text: ev.data.text, ts: ev.data.ts }); break;
      case 'signal': this.#onSignal(ev.from, ev.data); break;
      default: break;
    }
  }

  #queue(msg, urgent = false) {
    this.outbox.push(msg);
    this.hot = Date.now();
    clearTimeout(this.flushTimer);
    this.flushTimer = setTimeout(() => this.#flush(), urgent ? 0 : 80);
  }
  async #flush() {
    if (this.sending || this.closed || !this.outbox.length) return;
    this.sending = true;
    const batch = this.outbox.splice(0, 60);
    try { await api('room.send', { peer: this.peerId, msgs: batch }); }
    catch (e) {
      if (e.status === 410 || e.status === 401) { this.sending = false; return this.#finish('You are no longer in this meeting.'); }
      this.outbox.unshift(...batch); // transient failure: retry
      await sleep(600);
    }
    this.sending = false;
    if (this.outbox.length) this.flushTimer = setTimeout(() => this.#flush(), 0);
  }

  /* ---------- WebRTC mesh ---------- */
  #addPeer(p) {
    this.peers.set(p.id, { id: p.id, name: p.name, picture: p.picture, state: p.state, stream: new MediaStream(), pc: null, q: Promise.resolve(), initiator: false, connecting: true });
  }

  #createPeer(id, initiator) {
    const p = this.peers.get(id);
    const pc = new RTCPeerConnection({ iceServers: this.iceServers });
    Object.assign(p, { pc, initiator });
    if (initiator) { // the answering side picks up the transceivers created by the offer (see #onSignal)
      p.audio = pc.addTransceiver('audio', { direction: 'sendrecv' });
      p.video = pc.addTransceiver('video', { direction: 'sendrecv' });
      this.#attachLocal(p);
    }
    pc.onicecandidate = (e) => e.candidate && this.#queue({ type: 'signal', to: id, data: { candidate: e.candidate } });
    pc.ontrack = (e) => { if (!p.stream.getTracks().includes(e.track)) p.stream.addTrack(e.track); };
    pc.onconnectionstatechange = () => {
      const connecting = !['connected', 'completed'].includes(pc.connectionState);
      if (connecting !== p.connecting) { p.connecting = connecting; this.#emit(); }
      if (pc.connectionState === 'failed' && p.initiator) this.#offer(p, true);
    };
    return p;
  }

  #attachLocal(p) {
    if (this.audioTrack) p.audio?.sender.replaceTrack(this.audioTrack).catch(() => {});
    if (this.#videoOut()) p.video?.sender.replaceTrack(this.#videoOut()).catch(() => {});
  }

  #offer(p, iceRestart = false) {
    p.q = p.q.then(async () => {
      await p.pc.setLocalDescription(await p.pc.createOffer({ iceRestart }));
      this.#queue({ type: 'signal', to: p.id, data: { sdp: p.pc.localDescription } }, true);
    }).catch((e) => console.warn('offer', e));
  }

  #onSignal(from, data) {
    const p = this.peers.get(from);
    if (!p) return;
    if (!p.pc) this.#createPeer(from, false);
    p.q = p.q.then(async () => { // serialised so ICE candidates never overtake the offer
      if (data.sdp) {
        await p.pc.setRemoteDescription(data.sdp);
        if (data.sdp.type === 'offer') {
          if (!p.audio) { // first offer: turn the receive-only transceivers into send+receive
            for (const tr of p.pc.getTransceivers()) { tr.direction = 'sendrecv'; p[tr.receiver.track.kind] = tr; }
            this.#attachLocal(p);
          }
          await p.pc.setLocalDescription(await p.pc.createAnswer());
          this.#queue({ type: 'signal', to: from, data: { sdp: p.pc.localDescription } }, true);
        }
      } else if (data.candidate) {
        await p.pc.addIceCandidate(data.candidate).catch(() => {});
      }
    }).catch((e) => console.warn('signal', e));
  }

  // Lower per-stream bitrate as the room grows so total upload stays reasonable.
  #applyQuality() {
    const n = this.peers.size + 1;
    const bps = n <= 3 ? 1_200_000 : n <= 6 ? 600_000 : n <= 10 ? 300_000 : n <= 15 ? 150_000 : 90_000;
    const scale = n <= 6 ? 1 : n <= 12 ? 1.5 : 2;
    for (const p of this.peers.values()) {
      const s = p.video?.sender;
      if (!s?.track) continue;
      const prm = s.getParameters();
      if (!prm.encodings?.length) prm.encodings = [{}];
      prm.encodings[0].maxBitrate = this.screenTrack ? Math.max(bps, 800_000) : bps;
      prm.encodings[0].scaleResolutionDownBy = this.screenTrack ? 1 : scale;
      s.setParameters(prm).catch(() => {});
    }
  }

  /* ---------- local media ---------- */
  async #getCam(problems) {
    try {
      return (await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30, max: 30 } } })).getVideoTracks()[0];
    } catch { problems?.push('camera'); return null; }
  }
  #videoOut() { return this.screenTrack || this.camTrack; }
  #setLocalVideo() {
    for (const t of this.meStream.getVideoTracks()) this.meStream.removeTrack(t);
    const v = this.#videoOut();
    if (v) this.meStream.addTrack(v);
    this.me.cameraOff = !this.camTrack; this.me.sharing = !!this.screenTrack;
  }
  #replaceVideo() {
    for (const p of this.peers.values()) p.video?.sender.replaceTrack(this.#videoOut()).catch(() => {});
    this.#setLocalVideo();
  }
  #pushState() { this.#queue({ type: 'state', ...this.me }); this.#emit(); }
  #stopLocal() {
    for (const t of [this.audioTrack, this.camTrack, this.screenTrack]) t?.stop();
    this.audioTrack = this.camTrack = this.screenTrack = null;
  }

  async toggleMic() {
    if (!this.audioTrack) {
      try { this.audioTrack = (await navigator.mediaDevices.getUserMedia({ audio: true })).getAudioTracks()[0]; }
      catch { return 'Microphone unavailable. Check browser permissions.'; }
      for (const p of this.peers.values()) p.audio?.sender.replaceTrack(this.audioTrack).catch(() => {});
      this.me.muted = false;
    } else { this.audioTrack.enabled = !this.audioTrack.enabled; this.me.muted = !this.audioTrack.enabled; }
    this.#pushState();
  }
  async toggleCam() {
    if (this.camTrack) { this.camTrack.stop(); this.camTrack = null; }
    else { this.camTrack = await this.#getCam(); if (!this.camTrack) return 'Camera unavailable. Check browser permissions.'; }
    if (!this.screenTrack) this.#replaceVideo(); else this.#setLocalVideo();
    this.#pushState();
  }
  async toggleShare() {
    if (this.screenTrack) return this.#stopShare();
    if ([...this.peers.values()].some((p) => p.state.sharing)) return 'Someone else is already sharing.';
    try {
      const s = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { max: 15 } }, audio: false });
      this.screenTrack = s.getVideoTracks()[0];
      this.screenTrack.contentHint = 'detail';
      this.screenTrack.onended = () => this.#stopShare();
      this.#replaceVideo(); this.#pushState();
    } catch { /* cancelled */ }
  }
  #stopShare() {
    if (!this.screenTrack) return;
    this.screenTrack.onended = null; this.screenTrack.stop(); this.screenTrack = null;
    this.#replaceVideo(); this.#pushState();
  }

  /* ---------- chat & host actions ---------- */
  sendChat(text) { this.#queue({ type: 'chat', text }, true); }
  kick(id) { this.#queue({ type: 'kick', id }, true); }
  endForAll() { this.#queue({ type: 'end' }, true); }
}
