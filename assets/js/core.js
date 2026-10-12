/* VCL Gamify — shared helpers (no framework, no build step). */
(function () {
  'use strict';

  var GM = (window.GM = window.GM || {});
  var cfg = (GM.config = window.GM_CONFIG || { base: '' });
  var ICONS = window.GM_ICONS || {};

  GM.url = function (path) {
    return cfg.base + '/' + String(path || '').replace(/^\//, '');
  };

  /* ---------- DOM ---------- */

  /** h('div.card', {onclick: fn, text: 'Hi'}, child, …) */
  GM.h = function (tag, attrs) {
    var parts = tag.split('.');
    var el = document.createElement(parts[0] || 'div');
    if (parts.length > 1) el.className = parts.slice(1).join(' ');
    if (attrs) {
      for (var key in attrs) {
        var val = attrs[key];
        if (val === null || val === undefined || val === false) continue;
        if (key === 'text') el.textContent = val;
        else if (key === 'html') el.innerHTML = val;
        else if (key === 'class') el.className += (el.className ? ' ' : '') + val;
        else if (key === 'style' && typeof val === 'object') {
          for (var prop in val) {
            if (prop.slice(0, 2) === '--') el.style.setProperty(prop, String(val[prop]));
            else el.style[prop] = val[prop];
          }
        }
        else if (key.slice(0, 2) === 'on' && typeof val === 'function') el.addEventListener(key.slice(2), val);
        else if (val === true) el.setAttribute(key, '');
        else el.setAttribute(key, val);
      }
    }
    for (var i = 2; i < arguments.length; i++) append(el, arguments[i]);
    return el;
  };

  function append(el, child) {
    if (child === null || child === undefined || child === false) return;
    if (Array.isArray(child)) child.forEach(function (c) { append(el, c); });
    else if (child instanceof Node) el.appendChild(child);
    else el.appendChild(document.createTextNode(String(child)));
  }

  GM.icon = function (name, cls) {
    var span = document.createElement('span');
    span.innerHTML =
      '<svg' + (cls ? ' class="' + cls + '"' : '') +
      ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      (ICONS[name] || '') + '</svg>';
    return span.firstChild;
  };

  GM.data = function (id) {
    var el = document.getElementById(id || 'gm-data');
    try {
      return el ? JSON.parse(el.textContent) : null;
    } catch (e) {
      return null;
    }
  };

  GM.qs = function (sel, root) { return (root || document).querySelector(sel); };
  GM.qsa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------- Storage (never throws) ---------- */

  // Keys are scoped to the install folder, so two installs on one domain don't collide.
  var storePrefix = 'gm' + (cfg.base || '') + '.';
  GM.store = {
    get: function (k) {
      try { return window.localStorage.getItem(storePrefix + k); } catch (e) { return null; }
    },
    set: function (k, v) {
      try {
        if (v === null || v === undefined) window.localStorage.removeItem(storePrefix + k);
        else window.localStorage.setItem(storePrefix + k, v);
      } catch (e) { /* private mode */ }
    }
  };

  /* ---------- Network ---------- */

  GM.api = function (method, path, body, opts) {
    opts = opts || {};
    var headers = { Accept: 'application/json', 'X-Requested-With': 'gamify' };
    if (cfg.csrf) headers['X-CSRF-Token'] = cfg.csrf;
    var pid = GM.store.get('pid');
    if (pid) headers['X-Participant-Token'] = pid;
    var init = { method: method, headers: headers, credentials: 'same-origin', cache: 'no-store', signal: opts.signal };
    if (body instanceof FormData) {
      init.body = body;
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    return fetch(GM.url(path), init).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { /* not JSON */ }
        if (!res.ok) {
          var err = new Error((data && data.error) || 'Something went wrong. Please try again.');
          err.status = res.status;
          err.code = data && data.code;
          throw err;
        }
        return data;
      });
    });
  };

  GM.sleep = function (ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  };

  /**
   * Live updates through long-polling: the server holds each request until the
   * activity's version changes (a vote, a setting), then answers immediately.
   * Works on any shared host — no WebSockets needed.
   */
  GM.live = function (opts) {
    var version = opts.version || 0;
    var stopped = false;
    var failures = 0;
    var ctrl = null;

    function setStatus(s) { if (opts.onStatus) opts.onStatus(s); }

    function waitVisible() {
      return new Promise(function (resolve) {
        if (!document.hidden) return resolve();
        function on() {
          if (!document.hidden) {
            document.removeEventListener('visibilitychange', on);
            resolve();
          }
        }
        document.addEventListener('visibilitychange', on);
      });
    }

    function loop() {
      if (stopped) return;
      var wait = opts.pauseWhenHidden ? waitVisible() : Promise.resolve();
      wait.then(function () {
        if (stopped) return;
        ctrl = window.AbortController ? new AbortController() : null;
        var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 40000);
        GM.api('GET', opts.url(version), undefined, { signal: ctrl && ctrl.signal })
          .then(function (data) {
            clearTimeout(timer);
            if (failures > 0 || opts._first !== false) setStatus('live');
            opts._first = false;
            failures = 0;
            if (data && data.changed === false) {
              version = data.version;
            } else if (data) {
              version = data.version;
              opts.onData(data);
            }
            loop();
          })
          .catch(function (err) {
            clearTimeout(timer);
            if (stopped) return;
            if (err && (err.status === 401 || err.status === 403 || err.status === 404)) {
              setStatus('gone');
              if (opts.onGone) opts.onGone(err);
              return;
            }
            failures++;
            setStatus('reconnecting');
            GM.sleep(Math.min(10000, 600 * Math.pow(2, Math.min(failures, 4)))).then(loop);
          });
      });
    }

    loop();
    return {
      stop: function () { stopped = true; if (ctrl) ctrl.abort(); },
      setVersion: function (v) { version = v; }
    };
  };

  /* ---------- Feedback ---------- */

  GM.toast = function (message, tone) {
    var host = document.querySelector('.toast-host');
    if (!host) return;
    var iconName = tone === 'error' ? 'alert' : 'check';
    var t = GM.h('div.toast' + (tone ? '.is-' + tone : ''), { role: 'status' }, GM.icon(iconName), GM.h('span', { text: message }));
    host.appendChild(t);
    setTimeout(function () {
      t.classList.add('is-leaving');
      setTimeout(function () { t.remove(); }, 260);
    }, tone === 'error' ? 4200 : 2200);
  };

  GM.copy = function (text) {
    function fallback() {
      var ta = GM.h('textarea', { style: { position: 'fixed', opacity: '0' } });
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* ignore */ }
      ta.remove();
    }
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).catch(fallback);
    }
    fallback();
    return Promise.resolve();
  };

  /** Apple-style alert. Resolves true when confirmed. */
  GM.confirm = function (o) {
    return new Promise(function (resolve) {
      var dlg = GM.h('dialog.dialog');
      var cancel = GM.h('button.btn', { type: 'button', text: o.cancelLabel || 'Cancel' });
      var ok = GM.h('button.btn.' + (o.danger ? 'btn-danger-solid' : 'btn-primary'), { type: 'button', text: o.confirmLabel || 'OK' });
      dlg.appendChild(GM.h('div.dialog-body', null,
        GM.h('h2.dialog-title', { text: o.title }),
        o.message ? GM.h('p.dialog-text', { text: o.message }) : null));
      dlg.appendChild(GM.h('div.dialog-actions', null, cancel, ok));
      document.body.appendChild(dlg);
      function done(v) {
        dlg.close();
        dlg.remove();
        resolve(v);
      }
      cancel.addEventListener('click', function () { done(false); });
      ok.addEventListener('click', function () { done(true); });
      dlg.addEventListener('cancel', function (e) { e.preventDefault(); done(false); });
      dlg.addEventListener('click', function (e) { if (e.target === dlg) done(false); });
      if (dlg.showModal) dlg.showModal();
      else resolve(window.confirm(o.title));
      ok.focus();
    });
  };

  GM.busy = function (btn, on) {
    if (!btn) return;
    if (on) {
      btn.dataset.label = btn.innerHTML;
      btn.style.width = btn.offsetWidth + 'px';
      btn.innerHTML = '<span class="spinner"></span>';
      btn.disabled = true;
    } else {
      if (btn.dataset.label !== undefined) btn.innerHTML = btn.dataset.label;
      btn.style.width = '';
      btn.disabled = false;
    }
  };

  /* ---------- Formatting ---------- */

  GM.plural = function (n, one, many) {
    return n + ' ' + (n === 1 ? one : many);
  };

  /** Headline count: people who voted. On multiple-choice polls that's "voters", not "votes". */
  GM.voterWord = function (n, multiple) {
    return multiple ? (n === 1 ? 'voter' : 'voters') : (n === 1 ? 'vote' : 'votes');
  };

  GM.fmtCode = function (code) {
    code = String(code || '');
    return code.length === 6 ? code.slice(0, 3) + ' ' + code.slice(3) : code;
  };

  GM.initials = function (name) {
    var words = String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
    var out = words.map(function (w) { return Array.from(w)[0] || ''; }).join('').toUpperCase();
    return out || '?';
  };

  /** A round avatar: the person's persona emoji, or their initials if they have none. */
  GM.avatar = function (name, emoji, cls) {
    return GM.h('span.avatar' + (emoji ? '.is-persona' : '') + (cls ? '.' + cls : ''), {
      'aria-hidden': 'true',
      text: emoji || GM.initials(name)
    });
  };

  GM.timeAgo = function (iso) {
    var t = Date.parse(iso);
    if (!t) return '';
    var s = Math.max(0, Math.round((Date.now() - t) / 1000));
    if (s < 10) return 'now';
    if (s < 60) return s + 's';
    var m = Math.round(s / 60);
    if (m < 60) return m + 'm';
    var h = Math.round(m / 60);
    if (h < 24) return h + 'h';
    return Math.round(h / 24) + 'd';
  };

  /** Animate a number in an element from its last value to `to`. */
  GM.countTo = function (el, to, suffix) {
    suffix = suffix || '';
    var from = el._gmValue !== undefined ? el._gmValue : 0;
    el._gmValue = to;
    if (from === to || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      el.textContent = to + suffix;
      return;
    }
    var start = performance.now();
    var dur = 700;
    cancelAnimationFrame(el._gmRaf);
    function frame(now) {
      var p = Math.min(1, (now - start) / dur);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(from + (to - from) * eased) + suffix;
      if (p < 1) el._gmRaf = requestAnimationFrame(frame);
    }
    el._gmRaf = requestAnimationFrame(frame);
  };

  /* ---------- QR codes ---------- */

  /** Returns an SVG string. Uses the vendored qrcode-generator (MIT). */
  GM.qrSvg = function (text, opts) {
    opts = opts || {};
    if (typeof window.qrcode !== 'function') return '';
    var qr = window.qrcode(0, opts.ecc || 'M');
    qr.addData(text);
    qr.make();
    var n = qr.getModuleCount();
    var m = opts.margin !== undefined ? opts.margin : 1;
    var size = n + m * 2;
    var dark = opts.dark || '#1d1d1f';
    var light = opts.light || '#ffffff';

    function inFinder(r, c) {
      return (r < 7 && c < 7) || (r < 7 && c >= n - 7) || (r >= n - 7 && c < 7);
    }
    var d = '';
    for (var r = 0; r < n; r++) {
      for (var c = 0; c < n; c++) {
        if (qr.isDark(r, c) && !inFinder(r, c)) d += 'M' + (c + m) + ' ' + (r + m) + 'h1v1h-1z';
      }
    }
    function finder(x, y) {
      x += m;
      y += m;
      return '<rect x="' + x + '" y="' + y + '" width="7" height="7" rx="2" fill="' + dark + '"/>' +
        '<rect x="' + (x + 1) + '" y="' + (y + 1) + '" width="5" height="5" rx="1.3" fill="' + light + '"/>' +
        '<rect x="' + (x + 2) + '" y="' + (y + 2) + '" width="3" height="3" rx="0.9" fill="' + dark + '"/>';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" role="img" aria-label="QR code">' +
      '<rect width="' + size + '" height="' + size + '" fill="' + light + '"/>' +
      '<path d="' + d + '" fill="' + dark + '" shape-rendering="crispEdges"/>' +
      finder(0, 0) + finder(n - 7, 0) + finder(0, n - 7) +
      '</svg>';
  };

  GM.download = function (blob, filename) {
    var a = GM.h('a', { href: URL.createObjectURL(blob), download: filename });
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  };

  GM.svgToPng = function (svg, px) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () {
        var canvas = document.createElement('canvas');
        canvas.width = px;
        canvas.height = px;
        var ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(img, 0, 0, px, px);
        canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('PNG failed')); }, 'image/png');
      };
      img.onerror = reject;
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    });
  };

  /* ---------- Segmented control ---------- */

  GM.segmented = function (root, value, onChange) {
    var thumb = root.querySelector('.segmented-thumb');
    var buttons = GM.qsa('button', root);
    function place(animate) {
      var active = buttons.filter(function (b) { return b.dataset.value === value; })[0];
      buttons.forEach(function (b) { b.setAttribute('aria-checked', b === active ? 'true' : 'false'); });
      if (!active || !thumb) return;
      if (!animate) thumb.style.transition = 'none';
      thumb.style.width = active.offsetWidth + 'px';
      thumb.style.transform = 'translateX(' + active.offsetLeft + 'px)';
      if (!animate) {
        thumb.offsetWidth; // flush
        thumb.style.transition = '';
      }
    }
    buttons.forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.dataset.value === value) return;
        value = b.dataset.value;
        place(true);
        if (onChange) onChange(value);
      });
    });
    place(false);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { place(false); });
    window.addEventListener('resize', function () { place(false); });
    return {
      set: function (v) {
        if (v === value) return;
        value = v;
        place(true);
      }
    };
  };

  /** Grow a textarea to fit its content. */
  GM.autosize = function (ta) {
    function fit() {
      ta.style.height = 'auto';
      ta.style.height = ta.scrollHeight + 'px';
    }
    ta.addEventListener('input', fit);
    fit();
    return fit;
  };
})();
