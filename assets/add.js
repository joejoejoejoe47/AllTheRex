/* Add-a-title: live search of public databases. Results are built with textContent (no HTML injection). */
(function () {
  'use strict';
  var root = document.getElementById('finder');
  if (!root) return;
  var box = document.getElementById('ext-q'), list = document.getElementById('ext-results'), status = document.getElementById('ext-status');
  var timer, seq = 0, ctl;

  function cat() { var r = root.querySelector('input[name=scat]:checked'); return r ? r.value : 'movie'; }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function hidden(form, name, value) { var i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value == null ? '' : value; form.appendChild(i); }

  function row(r) {
    var d = el('div', 'result');
    var pic = el('div', 'thumb');
    if (r.image) {
      var img = document.createElement('img');
      img.src = r.image; img.alt = ''; img.loading = 'lazy'; img.referrerPolicy = 'no-referrer';
      img.onerror = function () { img.remove(); };
      pic.appendChild(img);
    }
    d.appendChild(pic);
    var info = el('div', 'info');
    info.appendChild(el('strong', null, r.title));
    var meta = [r.creator, r.year].filter(Boolean).join(' · ');
    info.appendChild(el('span', 'meta', meta));
    d.appendChild(info);
    if (r.existing_id) {
      var a = el('a', 'btn small ghost', 'Already added · open');
      a.href = root.dataset.titleUrl + r.existing_id;
      d.appendChild(a);
    } else {
      var f = document.createElement('form');
      f.method = 'post'; f.action = root.dataset.add;
      hidden(f, 'csrf', window.REX.csrf);
      hidden(f, 'category', r.category); hidden(f, 'title', r.title); hidden(f, 'creator', r.creator);
      hidden(f, 'year', r.year); hidden(f, 'image_url', r.image);
      var b = el('button', 'btn small', 'Add'); b.type = 'submit';
      f.appendChild(b);
      f.addEventListener('submit', function () { b.disabled = true; b.textContent = 'Adding…'; });
      d.appendChild(f);
    }
    return d;
  }

  function run() {
    var q = box.value.trim();
    list.textContent = '';
    if (q.length < 2) { status.textContent = ''; return; }
    var mine = ++seq;
    if (ctl) ctl.abort();
    ctl = ('AbortController' in window) ? new AbortController() : null;
    status.textContent = 'Searching…';
    fetch(root.dataset.endpoint + '?cat=' + encodeURIComponent(cat()) + '&q=' + encodeURIComponent(q),
      { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctl ? ctl.signal : undefined })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (mine !== seq) return;
        list.textContent = '';
        if (!res.ok || !res.j.ok) { status.textContent = (res.j && res.j.error) || 'Search failed.'; return; }
        if (res.j.failed) { status.textContent = 'The search service didn\'t respond. Try again, or add it manually below.'; return; }
        if (!res.j.results.length) { status.textContent = 'No matches. Try fewer words, or add it manually below.'; return; }
        status.textContent = res.j.results.length + ' result' + (res.j.results.length === 1 ? '' : 's') + '. Pick the right one:';
        res.j.results.forEach(function (r) { list.appendChild(row(r)); });
      })
      .catch(function (e) { if (e && e.name === 'AbortError') return; if (mine === seq) status.textContent = 'Search failed. Check your connection.'; });
  }

  function later() { clearTimeout(timer); timer = setTimeout(run, 400); }
  box.addEventListener('input', later);
  root.querySelectorAll('input[name=scat]').forEach(function (r) { r.addEventListener('change', run); });
  if (box.value.trim().length >= 2) run(); else box.focus();
})();
