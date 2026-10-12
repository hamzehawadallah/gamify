/* PowerPoint add-in: lives inside a slide. Paste a presenter link once; the slide
   remembers it (saved in the .pptx) and shows the live results during the slideshow. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;
  var KEY = 'gamifyPresentUrl';
  var root = document.getElementById('pp');
  var settings = null; // PowerPoint's per-add-in document settings, when running inside PowerPoint
  var started = false;

  /** Returns a clean presenter URL, 'other' for another site's link, or null if it isn't one. */
  function normalize(value) {
    var v = String(value || '').trim();
    if (!v) return null;
    if (!/^https?:\/\//i.test(v)) v = location.protocol + '//' + v.replace(/^\/+/, '');
    var u;
    try { u = new URL(v); } catch (e) { return null; }
    if (!/\/present\/\d{6}\/?$/.test(u.pathname) || !u.searchParams.get('key')) return null;
    if (u.origin !== location.origin || u.pathname.indexOf((GM.config.base || '') + '/present/') !== 0) return 'other';
    return u.href;
  }

  function savedUrl() {
    var v = settings ? settings.get(KEY) : GM.store.get('pp.url');
    return v && normalize(v) === v ? v : null;
  }

  function save(url, done) {
    if (settings) {
      settings.set(KEY, url);
      settings.saveAsync(function () { done(); });
    } else {
      GM.store.set('pp.url', url);
      done();
    }
  }

  function showSetup(prefill) {
    root.innerHTML = '';
    var input = h('input.input', {
      type: 'text', inputmode: 'url', autocomplete: 'off', spellcheck: 'false',
      placeholder: 'Paste the presenter link', 'aria-label': 'Presenter link'
    });
    input.value = prefill || '';
    var error = h('p.form-error', { role: 'alert' });
    var btn = h('button.btn.btn-primary', { type: 'submit' }, GM.icon('present'), 'Show live results');
    var form = h('form.pp-form', { novalidate: true }, input, btn);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var url = normalize(input.value);
      if (url === 'other') {
        error.textContent = 'This link is from a different website. Use a presenter link from this ' + (GM.config.appName || 'Gamify') + ' site.';
      } else if (!url) {
        error.textContent = 'That doesn’t look like a presenter link. Copy it from the activity page: Present → Copy.';
      } else {
        GM.busy(btn, true);
        save(url, function () { showLive(url); });
      }
    });
    root.appendChild(h('div.pp-setup', null,
      h('img.pp-mark', { src: GM.url('assets/img/addin-80.png'), alt: '' }),
      h('h1', { text: 'Live results on this slide' }),
      h('p', { text: 'Paste the presenter link from your activity page (Present → Copy). This slide remembers it.' }),
      form,
      error));
    setTimeout(function () { input.focus(); }, 50);
  }

  function showLive(url) {
    root.innerHTML = '';
    root.appendChild(h('iframe.pp-frame', { src: url, title: 'Live results', allow: 'fullscreen' }));
    root.appendChild(h('button.btn.btn-sm.pp-change', {
      type: 'button', title: 'Show a different activity',
      onclick: function () { showSetup(url); }
    }, GM.icon('edit'), 'Change link'));
  }

  function start() {
    if (started) return;
    started = true;
    var url = savedUrl();
    if (url) showLive(url);
    else showSetup();
  }

  /** Hide the "Change link" button while the slideshow is running. */
  function watchSlideshow() {
    try {
      var doc = Office.context.document;
      var apply = function (view) { document.body.classList.toggle('is-slideshow', view === 'read'); };
      doc.getActiveViewAsync(function (r) { if (r.status === Office.AsyncResultStatus.Succeeded) apply(r.value); });
      doc.addHandlerAsync(Office.EventType.ActiveViewChanged, function (e) { apply(e.activeView); });
    } catch (e) { /* older PowerPoint: the button only shows on hover */ }
  }

  if (window.Office && typeof Office.onReady === 'function') {
    Office.onReady(function (info) {
      if (info && info.host && Office.context && Office.context.document && Office.context.document.settings) {
        settings = Office.context.document.settings;
        watchSlideshow();
      }
      start();
    });
    // Never leave the slide blank if Office is slow to answer.
    setTimeout(start, 6000);
  } else {
    start();
  }
})();
