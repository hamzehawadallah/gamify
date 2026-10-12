/* Presenter screen — full-bleed, scales to any size (projector, the PowerPoint add-in, Teams).
   Shows every question side by side (a "wall"), or one question with ?q=N. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;
  var data = GM.data() || {};
  var payload = data.live;

  var $ = function (id) { return document.getElementById(id); };
  var stage = $('stage');
  var kickerEl = $('stage-kicker');
  var titleEl = $('stage-title');
  var descEl = $('stage-desc');
  var body = $('stage-body');
  var wall = $('stage-wall');
  var joinPill = $('join-pill');
  var countEl = $('voter-count');
  var countLabel = $('voter-label');
  var closedBadge = $('closed-badge');
  var liveDot = $('live-dot');
  var votersEl = $('voters');
  var qrEl = $('qr');
  var qrCode = $('qr-code');

  var lastJoinUrl = null;
  var seenVoters = {};
  var connBanner = null;
  var single = null;   // { key, results } when showing one question
  var columns = null;  // { key, results: {question_id: Results} } when showing the wall
  var hiddenBig = null;

  function qTitle(a, q, i) {
    if (q.title) return q.title;
    return a.questions.length === 1 ? a.title : 'Question ' + (i + 1);
  }

  function resultsFor(p, i) {
    var r = p.results.questions[i] || { voters: 0, counts: [] };
    return r;
  }

  function renderJoin(join) {
    if (join.url === lastJoinUrl) return;
    lastJoinUrl = join.url;
    var host = join.display.replace(new RegExp('/?' + join.code + '$'), '');
    joinPill.innerHTML = '';
    var icon = GM.icon('qr');
    icon.style.cssText = 'width:calc(var(--u)*1.6);height:calc(var(--u)*1.6);color:var(--accent)';
    joinPill.appendChild(icon);
    joinPill.appendChild(h('span', null, 'Join at ', h('b', { text: host })));
    joinPill.appendChild(h('span.sep'));
    joinPill.appendChild(h('span', null, 'Code ', h('span.code', { text: GM.fmtCode(join.code) })));
    qrEl.innerHTML = GM.qrSvg(join.url, { margin: 0 });
    qrCode.textContent = GM.fmtCode(join.code);
  }

  function renderVoters(list, total) {
    votersEl.hidden = !list.length;
    if (!list.length) return;
    var shown = list.slice(0, 10);
    var frag = document.createDocumentFragment();
    shown.forEach(function (v) {
      var av = GM.avatar('', v.emoji);
      if (!v.emoji) av.textContent = v.initials;
      if (seenVoters[v.id]) av.style.animation = 'none';
      seenVoters[v.id] = true;
      frag.appendChild(av);
    });
    votersEl.innerHTML = '';
    votersEl.appendChild(frag);
    var more = total - shown.length;
    if (more > 0) votersEl.appendChild(h('span.more', { text: '+' + more + ' more' }));
  }

  /** "12 people have voted — results coming soon" while results are hidden. */
  function hiddenState(host, voters) {
    if (!hiddenBig || !host.contains(hiddenBig)) {
      host.innerHTML = '';
      hiddenBig = h('div.big', { text: '0' });
      host.appendChild(h('div.stage-state', { style: { gridColumn: '1 / -1' } },
        hiddenBig,
        h('div.big-sub'),
        h('div.hint', null, GM.icon('eye-off'), 'Results will be revealed soon')));
    }
    hiddenBig.nextSibling.textContent = voters === 1 ? 'person has voted' : 'people have voted';
    GM.countTo(hiddenBig, voters);
  }

  /** Which question index to show alone, or -1 for the wall. */
  function soloIndex(a) {
    if (a.questions.length === 1) return 0;
    var n = data.question || 0;
    return n >= 1 && n <= a.questions.length ? n - 1 : -1;
  }

  function renderSingle(p, i) {
    var a = p.activity;
    var q = a.questions[i];
    stage.classList.remove('is-wall');
    wall.hidden = true;
    body.hidden = false;
    columns = null;

    var title = qTitle(a, q, i);
    var kicker = title !== a.title ? a.title : '';
    kickerEl.hidden = !kicker;
    kickerEl.textContent = kicker;
    kickerEl.setAttribute('data-color', q.color || 'red');
    titleEl.textContent = title;
    titleEl.classList.toggle('is-long', title.length > 90);
    var desc = q.description || (a.questions.length === 1 ? a.description : '');
    descEl.textContent = desc;
    descEl.hidden = !desc;
    body.setAttribute('data-color', q.color || 'red');

    var r = resultsFor(p, i);
    if (a.results_hidden) {
      single = null;
      hiddenState(body, p.results.voters);
      return;
    }
    hiddenBig = null;
    var key = 'single-' + q.id;
    if (!single || single.key !== key) {
      body.innerHTML = '';
      var host = h('div', { style: { display: 'grid', minHeight: '0' } });
      body.appendChild(host);
      single = { key: key, results: new GM.Results(host) };
    }
    single.results.update({ options: q.options, status: a.status }, r);
  }

  function renderWall(p) {
    var a = p.activity;
    stage.classList.add('is-wall');
    body.hidden = true;
    wall.hidden = false;
    single = null;

    kickerEl.hidden = true;
    titleEl.textContent = a.title;
    titleEl.classList.remove('is-long');
    descEl.textContent = a.description || '';
    descEl.hidden = !a.description;

    if (a.results_hidden) {
      columns = null;
      wall.style.setProperty('--cols', '1');
      hiddenState(wall, p.results.voters);
      return;
    }
    hiddenBig = null;

    var n = a.questions.length;
    var key = JSON.stringify(a.questions.map(function (q) { return [q.id, q.title, q.description, q.color]; }));
    if (!columns || columns.key !== key) {
      wall.innerHTML = '';
      wall.style.setProperty('--cols', String(n <= 4 ? n : n <= 6 ? 3 : 4));
      columns = { key: key, results: {} };
      a.questions.forEach(function (q, i) {
        var bodyEl = h('div.wall-body');
        var col = h('article.wall-col', { 'data-color': q.color || 'red', style: { '--i': i } },
          h('header.wall-head', null,
            h('div.wall-title', { dir: 'auto', text: qTitle(a, q, i) }),
            q.description ? h('div.wall-desc', { dir: 'auto', text: q.description }) : null),
          bodyEl);
        wall.appendChild(col);
        columns.results[q.id] = new GM.Results(bodyEl, { layout: 'list' });
      });
    }
    a.questions.forEach(function (q, i) {
      columns.results[q.id].update({ options: q.options, status: a.status }, resultsFor(p, i));
    });
  }

  function render(p) {
    payload = p;
    var a = p.activity;
    document.title = a.title;

    var showQr = data.qr !== null && data.qr !== undefined ? data.qr : a.settings.show_qr;
    stage.classList.toggle('no-qr', !showQr);
    renderJoin(p.join);

    var solo = soloIndex(a);
    if (!a.questions.length) {
      titleEl.textContent = a.title;
      body.innerHTML = '';
    } else if (solo >= 0) {
      renderSingle(p, solo);
    } else {
      renderWall(p);
    }

    // One question on screen: count its voters; the wall counts everyone who took part.
    var soloResults = solo >= 0 ? p.results.questions[solo] : null;
    var voters = soloResults ? soloResults.voters : p.results.voters;
    GM.countTo(countEl, voters);
    countLabel.textContent = solo >= 0 && a.questions[solo]
      ? GM.voterWord(voters, a.questions[solo].settings.multiple)
      : (voters === 1 ? 'person' : 'people');
    closedBadge.hidden = a.status !== 'closed';
    renderVoters(p.recent_voters || [], voters);
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
      stage.classList.remove('is-wall');
      wall.hidden = true;
      body.hidden = false;
      body.innerHTML = '';
      body.appendChild(h('div.stage-state', null,
        h('div.big-sub', { text: 'This presentation has ended.' }),
        h('div.hint', null, GM.icon('info'), 'The activity was deleted or its link changed.')));
    }
  });
})();
