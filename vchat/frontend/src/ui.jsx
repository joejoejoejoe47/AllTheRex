export function Avatar({ user }) {
  const pic = user.picture && /^https:\/\//.test(user.picture) ? user.picture : null;
  return (
    <div className="ava">
      {pic ? <img src={pic} alt="" referrerPolicy="no-referrer" /> : (user.name || '?').trim().charAt(0).toUpperCase() || '?'}
    </div>
  );
}

export function toast(msg, ms = 3500) {
  const t = document.createElement('div');
  t.className = 'toast'; t.setAttribute('role', 'status'); t.textContent = msg;
  document.body.append(t);
  setTimeout(() => t.remove(), ms);
}
