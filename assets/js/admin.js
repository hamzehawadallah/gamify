/* Admin: dashboard list (search, cards). Loaded on every admin page. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;

  function card(a, i) {
    var status = a.status === 'open'
      ? h('span.status.is-open', null, h('span.dot'), 'Open')
      : h('span.status.is-closed', null, GM.icon('lock'), 'Closed');
    return h('a.a-card', { href: GM.url('admin/activity/' + a.id), style: { '--i': Math.min(i, 12) } },
      a.cover ? h('div.a-card-cover', { style: { backgroundImage: 'url("' + a.cover + '")' } }) : null,
      h('div.a-card-top', null,
        h('span.a-card-kind', { text: GM.plural(a.question_count, 'question', 'questions') }),
        status),
      h('h2.a-card-title', { dir: 'auto', text: a.title }),
      h('div.a-card-meta', null,
        h('span', { title: 'People who took part' }, GM.icon('users'), String(a.voters)),
        h('span.code', { text: GM.fmtCode(a.code) })));
  }

  function initDashboard() {
    var cards = document.getElementById('cards');
    if (!cards) return;
    var all = (GM.data() || {}).activities || [];
    var search = document.getElementById('search');
    var count = document.getElementById('count');
    var noMatch = document.getElementById('no-match');

    function draw() {
      var q = (search.value || '').trim().toLowerCase();
      var list = all.filter(function (a) {
        return !q || a.title.toLowerCase().indexOf(q) >= 0 || a.code.indexOf(q.replace(/\s/g, '')) >= 0;
      });
      cards.innerHTML = '';
      list.forEach(function (a, i) { cards.appendChild(card(a, i)); });
      noMatch.hidden = list.length > 0;
      count.textContent = GM.plural(list.length, 'activity', 'activities');
    }
    search.addEventListener('input', draw);
    draw();
  }

  initDashboard();
})();
