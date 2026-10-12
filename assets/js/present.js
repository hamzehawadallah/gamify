/* Presenter screen — full-bleed, scales to any size (projector, PowerPoint Web Viewer, Teams). */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;
  var data = GM.data() || {};
  var payload = data.live;

  var stage = document.getElementById('stage');
  var titleEl = document.getElementById('stage-title');
  var descEl = document.getElementById('stage-desc');
  var body = document.getElementById('stage-body');
  var joinPill = document.getElementById('join-pill');
  var countEl = document.getElementById('voter-count');
  var countLabel = document.getElementById('voter-label');
  var closedBadge = document.getElementById('closed-badge');
  var liveDot = document.getElementById('live-dot');
  var votersEl = document.getElementById('voters');
  var qrEl = document.getElementById('qr');
  var qrCode = document.getElementById('qr-code');

  var results = null;
  var resultsHost = null;
  var lastJoinUrl = null;
  var seenVoters = {};
  var connBanner = null;

  function renderJoin(join) {
    if (join.url === lastJoinUrl) return;
    lastJoinUrl = join.url;
    var host = join.display.replace(new RegExp('/?' + join.code + '$'), '');
    joinPill.innerHTML = '';
    joinPill.appendChild(GM.icon('qr'));
    joinPill.appendChild(h('span', null, 'Join at ', h('b', { text: host })));
    joinPill.appendChild(h('span.sep'));
    joinPill.appendChild(h('span', null, 'Code ', h('span.code', { text: GM.fmtCode(join.code) })));
    joinPill.querySelector('svg').style.cssText = 'width:calc(var(--u)*1.6);height:calc(var(--u)*1.6);color:var(--accent)';
    qrEl.innerHTML = GM.qrSvg(join.url, { margin: 0 });
    qrCode.textContent = GM.fmtCode(join.code);
  }

  function renderVoters(list, total) {
    votersEl.hidden = !list.length;
    if (!list.length) return;
    var shown = list.slice(0, 10);
    var frag = document.createDocumentFragment();
    shown.forEach(function (v) {
      var av = GM.avatar(v.initials, v.id);
      av.textContent = v.initials;
      if (seenVoters[v.id]) av.style.animation = 'none';
      seenVoters[v.id] = true;
      frag.appendChild(av);
    });
    votersEl.innerHTML = '';
    votersEl.appendChild(frag);
    var more = total - shown.length;
    if (more > 0) votersEl.appendChild(h('span.more', { text: '+' + more + ' more' }));
  }

  function renderBody(p) {
    var a = p.activity;
    if (a.results_hidden) {
      results = null;
      resultsHost = null;
      var big = h('div.big', { text: '0' });
      body.innerHTML = '';
      body.appendChild(h('div.stage-state', null,
        big,
        h('div.big-sub', { text: p.results.voters === 1 ? 'person has voted' : 'people have voted' }),
        h('div.hint', null, GM.icon('eye-off'), 'Results will be revealed soon')));
      big._gmValue = body._lastHiddenCount || 0;
      GM.countTo(big, p.results.voters);
      body._lastHiddenCount = p.results.voters;
      return;
    }
    if (!results) {
      body.innerHTML = '';
      resultsHost = h('div', { style: { display: 'grid', minHeight: '0' } });
      body.appendChild(resultsHost);
      results = new GM.Results(resultsHost);
    }
    results.update(a, p.results);
  }

  function render(p) {
    payload = p;
    var a = p.activity;

    titleEl.textContent = a.title;
    titleEl.classList.toggle('is-long', a.title.length > 90);
    descEl.textContent = a.description || '';
    descEl.hidden = !a.description;
    document.title = a.title;

    var showQr = data.qr !== null && data.qr !== undefined ? data.qr : a.settings.show_qr;
    stage.classList.toggle('no-qr', !showQr);
    renderJoin(p.join);

    GM.countTo(countEl, p.results.voters);
    countLabel.textContent = GM.voterWord(p.results.voters, a.settings.multiple);

    closedBadge.hidden = a.status !== 'closed';

    renderBody(p);
    renderVoters(p.recent_voters || [], p.results.voters);
  }

  function setConn(status) {
    liveDot.classList.toggle('is-warn', status === 'reconnecting');
    liveDot.classList.toggle('is-off', status === 'gone');
    if (status === 'reconnecting' && !connBanner) {
      connBanner = h('div.stage-conn', null, h('span.live-dot.is-warn'), 'Reconnecting…');
      document.body.appendChild(connBanner);
    } else if (status !== 'reconnecting' && connBanner) {
      connBanner.remove();
      connBanner = null;
    }
  }

  render(payload);

  // Browser presenting: press F or double-click for full screen.
  function toggleFullscreen() {
    var el = document.documentElement;
    if (!document.fullscreenElement) {
      if (el.requestFullscreen) el.requestFullscreen().catch(function () {});
    } else if (document.exitFullscreen) {
      document.exitFullscreen();
    }
  }
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'f' || e.key === 'F') && !e.metaKey && !e.ctrlKey && !e.altKey) toggleFullscreen();
  });
  document.addEventListener('dblclick', toggleFullscreen);

  var key = encodeURIComponent(data.key || '');
  GM.live({
    version: payload.version,
    url: function (v) { return 'api/present/' + payload.join.code + '?key=' + key + '&v=' + v; },
    onData: render,
    onStatus: setConn,
    onGone: function () {
      body.innerHTML = '';
      body.appendChild(h('div.stage-state', null,
        h('div.big-sub', { text: 'This presentation has ended.' }),
        h('div.hint', null, GM.icon('info'), 'The poll was deleted or its link changed.')));
    }
  });
})();
