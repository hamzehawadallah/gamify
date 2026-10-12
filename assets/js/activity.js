/* Admin activity page: live results, voting controls, sharing and presenting. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;
  var data = GM.data() || {};
  var id = data.id;
  var payload = data.live;

  var $ = function (sel) { return document.querySelector(sel); };
  var titleEl = $('#act-title');
  var metaEl = $('#act-meta');
  var countEl = $('#voter-count');
  var liveDot = $('#live-dot');
  var hiddenSwitch = $('#hidden-switch');
  var hiddenBanner = $('#hidden-banner');
  var respList = $('#resp-list');
  var respCount = $('#resp-count');
  var presentUrlEl = $('#present-url');
  var presentBtn = $('#present-btn');

  var results = new GM.Results($('#results'));
  var seen = null;
  var busy = false;

  /* ---------- Render ---------- */

  function render(p) {
    payload = p;
    var a = p.activity;
    titleEl.textContent = a.title;
    document.title = a.title + ' · ' + (GM.config.appName || '');

    metaEl.innerHTML = '';
    metaEl.appendChild(a.status === 'open'
      ? h('span.pill.pill-green', null, h('span.dot'), 'Voting open')
      : h('span.pill', null, GM.icon('lock'), 'Voting closed'));
    if (a.results_hidden) metaEl.appendChild(h('span.pill.pill-orange', null, GM.icon('eye-off'), 'Results hidden'));
    metaEl.appendChild(h('span.pill', null, GM.icon('qr'), 'Code ' + GM.fmtCode(p.join.code)));
    if (a.settings.multiple) {
      metaEl.appendChild(h('span.pill', { text: a.settings.max_choices ? 'Up to ' + a.settings.max_choices + ' choices' : 'Multiple choice' }));
    }

    statusSeg.set(a.status);
    hiddenSwitch.checked = !!a.results_hidden;
    hiddenBanner.hidden = !a.results_hidden;
    countEl.textContent = p.results.voters + ' ' + GM.voterWord(p.results.voters, a.settings.multiple);

    results.update(a, p.results);
    renderResponses(p);
  }

  function renderResponses(p) {
    var labels = {};
    p.activity.options.forEach(function (o, i) { labels[o.id] = o.label || 'Option ' + (i + 1); });
    var rows = p.responses || [];
    respCount.textContent = rows.length ? GM.plural(rows.length, 'person', 'people') : '';
    respList.innerHTML = '';
    if (!rows.length) {
      respList.appendChild(h('div.results-empty', null,
        h('span', { text: 'No votes yet.' }),
        h('span', { text: 'Share the QR code to get started.' })));
    }
    var nowSeen = {};
    rows.forEach(function (r) {
      var key = r.participant_id + '@' + r.at;
      nowSeen[key] = true;
      var answer = r.option_ids.map(function (oid) { return labels[oid] || '—'; }).join(', ');
      respList.appendChild(h('div.resp-row' + (seen && !seen[key] ? '.is-new' : ''), null,
        GM.avatar(r.name, r.participant_id),
        h('span.resp-name', { dir: 'auto', text: r.name }),
        h('span.resp-answer', { dir: 'auto', text: answer, title: answer }),
        h('time.resp-time', { datetime: r.at, 'data-at': r.at, text: GM.timeAgo(r.at) })));
    });
    seen = nowSeen;
  }

  setInterval(function () {
    GM.qsa('.resp-time').forEach(function (t) { t.textContent = GM.timeAgo(t.getAttribute('data-at')); });
  }, 20000);

  /* ---------- Controls ---------- */

  function setState(change) {
    if (busy) return;
    busy = true;
    GM.api('POST', 'admin/api/activity/' + id + '/state', change)
      .then(function (p) {
        render(p);
        live.setVersion(p.version);
      })
      .catch(function (err) {
        GM.toast(err.message, 'error');
        render(payload);
      })
      .then(function () { busy = false; });
  }

  var statusSeg = GM.segmented($('#status-seg'), payload.activity.status, function (v) {
    setState({ status: v });
    GM.toast(v === 'open' ? 'Voting is open' : 'Voting is closed', 'success');
  });

  hiddenSwitch.addEventListener('change', function () {
    setState({ results_hidden: hiddenSwitch.checked });
    GM.toast(hiddenSwitch.checked ? 'Results hidden from the audience' : 'Results revealed', 'success');
  });

  // Present link + theme
  var theme = GM.store.get('presentTheme') || 'light';
  function presentUrl() {
    return data.present_url + '&theme=' + theme;
  }
  function syncPresent() {
    presentUrlEl.textContent = presentUrl();
    presentBtn.href = presentUrl();
  }
  GM.segmented($('#theme-seg'), theme, function (v) {
    theme = v;
    GM.store.set('presentTheme', v);
    syncPresent();
  });
  syncPresent();

  // Share
  var joinUrl = payload.join.url;
  $('#join-url').textContent = joinUrl;
  $('#share-code').textContent = GM.fmtCode(payload.join.code);
  var qrSvg = GM.qrSvg(joinUrl, { margin: 0 });
  $('#qr').innerHTML = qrSvg;

  GM.qsa('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var which = btn.getAttribute('data-copy');
      GM.copy(which === 'join' ? joinUrl : presentUrl()).then(function () {
        GM.toast(which === 'join' ? 'Join link copied' : 'Presenter link copied', 'success');
      });
    });
  });

  var fileBase = 'qr-' + payload.join.code;
  $('#dl-svg').addEventListener('click', function () {
    GM.download(new Blob([GM.qrSvg(joinUrl, { margin: 2 })], { type: 'image/svg+xml' }), fileBase + '.svg');
  });
  $('#dl-png').addEventListener('click', function () {
    GM.svgToPng(GM.qrSvg(joinUrl, { margin: 2 }), 1200)
      .then(function (blob) { GM.download(blob, fileBase + '.png'); })
      .catch(function () { GM.toast('Couldn’t create the PNG. Try the SVG instead.', 'error'); });
  });

  // More menu
  var moreBtn = $('#more-btn');
  var menu = $('#more-menu');
  function toggleMenu(open) {
    menu.hidden = !open;
    moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  moreBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    toggleMenu(menu.hidden);
  });
  document.addEventListener('click', function (e) {
    if (!menu.hidden && !menu.contains(e.target)) toggleMenu(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') toggleMenu(false);
  });

  menu.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    toggleMenu(false);
    var action = btn.getAttribute('data-action');

    if (action === 'duplicate') {
      GM.api('POST', 'admin/api/activity/' + id + '/duplicate')
        .then(function (r) { window.location.href = r.url; })
        .catch(function (err) { GM.toast(err.message, 'error'); });
    }
    if (action === 'reset') {
      GM.confirm({
        title: 'Clear all votes?',
        message: 'Everyone will be able to vote again. This can’t be undone.',
        confirmLabel: 'Clear votes',
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        GM.api('POST', 'admin/api/activity/' + id + '/reset')
          .then(function (p) {
            render(p);
            live.setVersion(p.version);
            GM.toast('All votes cleared', 'success');
          })
          .catch(function (err) { GM.toast(err.message, 'error'); });
      });
    }
    if (action === 'delete') {
      GM.confirm({
        title: 'Delete this poll?',
        message: 'Its QR code and presenter link will stop working, and all votes will be removed.',
        confirmLabel: 'Delete',
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        GM.api('POST', 'admin/api/activity/' + id + '/delete')
          .then(function () { window.location.href = GM.url('admin'); })
          .catch(function (err) { GM.toast(err.message, 'error'); });
      });
    }
  });

  /* ---------- Live ---------- */

  render(payload);
  var live = GM.live({
    version: payload.version,
    pauseWhenHidden: true,
    url: function (v) { return 'admin/api/activity/' + id + '/live?v=' + v; },
    onData: render,
    onStatus: function (s) {
      liveDot.classList.toggle('is-warn', s === 'reconnecting');
      liveDot.classList.toggle('is-off', s === 'gone');
    },
    onGone: function (err) {
      if (err.status === 401) window.location.href = GM.url('admin/login');
      else GM.toast('This poll was deleted.', 'error');
    }
  });
})();
