/* Star ratings: progressive enhancement. Without JS each star is a normal form submit. */
(function () {
  'use strict';

  var toastTimer;
  function toast(msg, isErr) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.className = 'toast'; }, 2600);
  }

  function paint(form, rating) {
    form.dataset.mine = rating;
    form.querySelectorAll('.star').forEach(function (b, i) {
      b.classList.toggle('on', i < rating);
    });
    var clear = form.querySelector('.clear');
    if (clear) clear.hidden = rating === 0;
  }

  function preview(form, upTo) {
    form.querySelectorAll('.star').forEach(function (b, i) {
      b.classList.toggle('hover', i < upTo);
    });
  }

  document.addEventListener('mouseover', function (e) {
    var b = e.target.closest && e.target.closest('.stars .star');
    if (b) preview(b.form, parseInt(b.value, 10));
  });
  document.addEventListener('mouseout', function (e) {
    var f = e.target.closest && e.target.closest('.stars');
    if (f) preview(f, 0);
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.classList || !form.classList.contains('stars')) return;
    e.preventDefault();

    if (!window.REX.loggedIn) {
      window.location.href = window.REX.loginUrl;
      return;
    }
    var btn = e.submitter;
    if (!btn) return;
    var rating = parseInt(btn.value, 10);
    var previous = parseInt(form.dataset.mine || '0', 10);
    paint(form, rating);                       // optimistic
    var body = new FormData(form);
    body.set('rating', String(rating));

    fetch(window.REX.rateUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.REX.csrf }
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.error) || 'Could not save');
        var label = document.querySelector('[data-avg-for="' + form.dataset.item + '"]');
        if (label) {
          label.innerHTML = res.j.n > 0
            ? '<b>' + res.j.avg.toFixed(1) + '</b> ★ · ' + res.j.n + ' rating' + (res.j.n === 1 ? '' : 's')
            : 'No ratings yet';
        }
        toast(rating === 0 ? 'Rating removed' : 'Saved: ' + rating + ' ★ (your picks will update)');
      })
      .catch(function (err) {
        paint(form, previous);
        toast(err.message, true);
      });
  });
})();
