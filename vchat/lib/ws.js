import crypto from 'node:crypto';
import { EventEmitter } from 'node:events';

// Minimal RFC 6455 server (text frames only), so the app needs no npm packages.
const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

export class WS extends EventEmitter {
  constructor(socket, maxPayload = 64 * 1024) {
    super();
    this.socket = socket; this.max = maxPayload;
    this.buf = Buffer.alloc(0); this.frags = []; this.fragLen = 0;
    this.open = true; this.alive = true;
    socket.setNoDelay(true);
    socket.on('data', (d) => this.#data(d));
    socket.on('close', () => this.#closed());
    socket.on('error', () => socket.destroy());
  }
  #data(d) {
    if (!this.open) return;
    this.buf = this.buf.length ? Buffer.concat([this.buf, d]) : d;
    while (this.open && this.#parse()) { /* keep parsing */ }
  }
  #parse() {
    const b = this.buf;
    if (b.length < 2) return false;
    const fin = !!(b[0] & 0x80), op = b[0] & 0x0f, masked = !!(b[1] & 0x80);
    let len = b[1] & 0x7f, off = 2;
    if (!masked || (b[0] & 0x70)) return this.#fail(1002);
    if (len === 126) { if (b.length < 4) return false; len = b.readUInt16BE(2); off = 4; }
    else if (len === 127) {
      if (b.length < 10) return false;
      const big = b.readBigUInt64BE(2);
      if (big > BigInt(this.max)) return this.#fail(1009);
      len = Number(big); off = 10;
    }
    if (len > this.max) return this.#fail(1009);
    if (b.length < off + 4 + len) return false;
    const mask = b.subarray(off, off + 4);
    const payload = Buffer.from(b.subarray(off + 4, off + 4 + len));
    for (let i = 0; i < len; i++) payload[i] ^= mask[i & 3];
    this.buf = b.subarray(off + 4 + len);

    if (op >= 8) { // control frames
      if (!fin || len > 125) return this.#fail(1002);
      if (op === 8) { this.close(1000); return false; }
      if (op === 9) this.#frame(10, payload);
      if (op === 10) this.alive = true;
      return true;
    }
    if (op === 0 ? !this.frags.length : (op !== 1 && op !== 2 || this.frags.length)) return this.#fail(1002);
    this.fragLen += len;
    if (this.fragLen > this.max) return this.#fail(1009);
    if (op === 2 && !this.frags.length) return this.#fail(1003); // binary unsupported
    this.frags.push(payload);
    if (fin) {
      const msg = Buffer.concat(this.frags).toString('utf8');
      this.frags = []; this.fragLen = 0;
      this.emit('message', msg);
    }
    return true;
  }
  #fail(code) { this.close(code); return false; }
  #frame(op, payload) {
    if (!this.socket.writable) return;
    const n = payload.length;
    const head = n < 126 ? Buffer.from([0x80 | op, n])
      : n < 65536 ? Buffer.from([0x80 | op, 126, n >> 8, n & 255])
      : (() => { const h = Buffer.alloc(10); h[0] = 0x80 | op; h[1] = 127; h.writeBigUInt64BE(BigInt(n), 2); return h; })();
    this.socket.write(Buffer.concat([head, payload]));
  }
  send(text) { if (this.open) this.#frame(1, Buffer.from(text)); }
  ping() { if (this.open) { this.alive = false; this.#frame(9, Buffer.alloc(0)); } }
  close(code = 1000) {
    if (!this.open) return;
    const p = Buffer.alloc(2); p.writeUInt16BE(code);
    this.#frame(8, p);
    this.open = false;
    this.socket.end();
    setTimeout(() => this.socket.destroy(), 2000).unref();
    this.emit('close');
  }
  terminate() { this.socket.destroy(); }
  #closed() { if (this.open) { this.open = false; this.emit('close'); } }
}

/** Complete the handshake on an 'upgrade' socket and return a WS. */
export function acceptWebSocket(req, socket, maxPayload) {
  const key = req.headers['sec-websocket-key'];
  if (!key || req.headers.upgrade?.toLowerCase() !== 'websocket' || req.headers['sec-websocket-version'] !== '13') {
    socket.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n');
    return null;
  }
  const accept = crypto.createHash('sha1').update(key + GUID).digest('base64');
  socket.write(`HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n\r\n`);
  return new WS(socket, maxPayload);
}
