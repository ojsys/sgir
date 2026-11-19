/* SGIR Materials — small progressive enhancements (pages work without JS) */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ── Mobile menu ────────────────────────────────────────────
  var menuBtn = $('#menuBtn'), scrim = $('#scrim');
  if (menuBtn) menuBtn.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  if (scrim) scrim.addEventListener('click', function () { document.body.classList.remove('nav-open'); });

  // ── Filters submit on change ───────────────────────────────
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form && el.form.submit(); });
  });

  // ── Confirm destructive buttons ────────────────────────────
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-confirm]');
    if (b && !window.confirm(b.getAttribute('data-confirm'))) ev.preventDefault();
  });

  // ── Password generator ─────────────────────────────────────
  $$('[data-gen-pw]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
      var arr = new Uint32Array(10), out = '';
      window.crypto.getRandomValues(arr);
      for (var i = 0; i < arr.length; i++) out += chars[arr[i] % chars.length];
      var input = btn.parentNode.querySelector('[data-pw]');
      input.value = out.slice(0, 5) + '-' + out.slice(5);
      input.focus(); input.select();
    });
  });

  // ── Receive / Issue form ───────────────────────────────────
  var form = $('#moveForm');
  if (!form) return;

  var type = form.getAttribute('data-type');
  var linesBody = $('#lines'), noLines = $('#noLines'), lineCount = $('#lineCount');
  var search = $('#itemSearch'), results = $('#pickerResults');
  var timer = null, current = [], hl = -1, reqSeq = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function refreshLines() {
    var rows = $$('tr', linesBody);
    noLines.hidden = rows.length > 0;
    lineCount.textContent = rows.length ? rows.length + ' item' + (rows.length === 1 ? '' : 's') : '';
    rows.forEach(function (tr) {
      var input = $('.qty-input', tr);
      var over = type === 'out' && parseFloat(input.value) > parseFloat(tr.getAttribute('data-stock'));
      tr.classList.toggle('over', !!over);
      input.title = over ? 'More than is in store' : '';
    });
  }

  function addLine(it) {
    var existing = $('tr[data-id="' + it.id + '"]', linesBody);
    if (existing) { $('.qty-input', existing).focus(); return; }
    var tr = document.createElement('tr');
    tr.setAttribute('data-id', it.id);
    tr.setAttribute('data-stock', it.quantity);
    tr.innerHTML =
      '<td><input type="hidden" name="item_id[]" value="' + it.id + '"><strong>' + esc(it.description) + '</strong>' +
      '<div class="muted small mono">' + esc(it.code) + (it.bin_location ? ' · Bin ' + esc(it.bin_location) : '') + '</div></td>' +
      '<td class="num">' + esc(it.qty_label) + ' <span class="muted small">' + esc(it.unit) + '</span></td>' +
      '<td class="num"><input class="qty-input" type="number" name="qty[]" min="0" step="any" inputmode="decimal" required' +
      (type === 'count' ? ' value="' + esc(it.quantity) + '"' : '') + '></td>' +
      '<td class="right"><button type="button" class="icon-btn remove-line" aria-label="Remove">✕</button></td>';
    linesBody.appendChild(tr);
    refreshLines();
    var q = $('.qty-input', tr);
    q.focus(); if (type === 'count') q.select();
  }

  function render() {
    hl = -1;
    if (!current.length) {
      results.innerHTML = '<li class="none">No matching items</li>';
    } else {
      results.innerHTML = current.map(function (it, i) {
        var zero = type === 'out' && it.quantity <= 0;
        return '<li role="option" data-i="' + i + '"><span><strong>' + esc(it.description) + '</strong><br><span class="muted small mono">' +
          esc(it.code) + '</span></span><span class="pr-stock' + (zero ? ' pr-zero' : '') + '">' + esc(it.qty_label) + ' ' + esc(it.unit) +
          (zero ? ' — none in store' : '') + '</span></li>';
      }).join('');
    }
    results.hidden = false;
  }

  function doSearch() {
    var q = search.value.trim();
    if (q.length < 2) { results.hidden = true; return; }
    var seq = ++reqSeq;
    fetch('api.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (data) { if (seq === reqSeq) { current = data || []; render(); } })
      .catch(function () { /* offline: ignore */ });
  }

  function pick(i) {
    var it = current[i];
    if (!it) return;
    if (type === 'out' && it.quantity <= 0) { search.focus(); return; }
    addLine(it);
    search.value = ''; results.hidden = true; current = [];
  }

  search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(doSearch, 180); });
  search.addEventListener('keydown', function (ev) {
    var items = $$('li[data-i]', results);
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      if (!items.length) return;
      ev.preventDefault();
      hl = (hl + (ev.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach(function (li, n) { li.classList.toggle('hl', n === hl); });
      items[hl].scrollIntoView({ block: 'nearest' });
    } else if (ev.key === 'Enter') {
      ev.preventDefault(); // never submit the whole form from the search box
      if (items.length) pick(hl >= 0 ? hl : 0);
    } else if (ev.key === 'Escape') {
      results.hidden = true;
    }
  });
  results.addEventListener('mousedown', function (ev) {
    var li = ev.target.closest('li[data-i]');
    if (li) { ev.preventDefault(); pick(+li.getAttribute('data-i')); }
  });
  search.addEventListener('blur', function () { setTimeout(function () { results.hidden = true; }, 150); });

  linesBody.addEventListener('click', function (ev) {
    if (ev.target.closest('.remove-line')) { ev.target.closest('tr').remove(); refreshLines(); }
  });
  linesBody.addEventListener('input', refreshLines);
  // Enter in a qty box jumps back to search instead of submitting early
  linesBody.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.classList.contains('qty-input')) { ev.preventDefault(); search.focus(); }
  });

  // Show only the detail fields relevant to the chosen reason
  function syncReason() {
    var r = (form.querySelector('input[name="reason"]:checked') || {}).value || '';
    $$('[data-show-for]', form).forEach(function (el) {
      var show = el.getAttribute('data-show-for').split(' ').indexOf(r) !== -1;
      el.hidden = !show;
      $$('input,select,textarea', el).forEach(function (i) { i.disabled = !show; });
    });
    $$('[data-req-for]', form).forEach(function (el) {
      el.hidden = el.getAttribute('data-req-for').split(' ').indexOf(r) === -1;
    });
  }
  $$('input[name="reason"]', form).forEach(function (r) { r.addEventListener('change', syncReason); });
  syncReason();

  form.addEventListener('submit', function (ev) {
    if (!$$('tr', linesBody).length) { ev.preventDefault(); search.focus(); noLines.classList.add('txt-out'); return; }
    var btn = form.querySelector('button[type=submit]');
    setTimeout(function () { btn.disabled = true; btn.textContent = 'Saving…'; }, 0);
  });

  refreshLines();
})();
