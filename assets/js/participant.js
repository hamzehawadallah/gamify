/* Participant page: name once → vote → see confirmation (and results, if allowed). */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;
  var initial = GM.data() || {};

  var state = {
    code: initial.code,
    activity: initial.activity,
    me: initial.me,
    my: initial.my || [],
    results: initial.results || null,
    version: initial.version || 0,
    editing: false,
    selected: [],
    justVoted: false,
    view: null
  };

  var shell = document.getElementById('app');
  var top = shell.querySelector('.p-top');
  var main = shell.querySelector('.p-main');
  var resultsView = null;
  var pollTimer = null;

  /* ---------- Identity ---------- */

  function rememberMe(me) {
    state.me = me;
    if (me) {
      GM.store.set('pid', me.token);
      GM.store.set('name', me.name);
    } else {
      GM.store.set('pid', null);
      GM.store.set('name', null);
    }
  }

  function apply(d) {
    if (!d || d.changed === false) return false;
    // Ignore a slow response that is older than what we already show.
    if (d.activity && state.activity && (d.version || 0) < state.version) return false;
    rememberMe(d.me || null);
    state.activity = d.activity;
    state.my = d.my || [];
    state.results = d.results || null;
    state.version = d.version || 0;
    return true;
  }

  /** Resolves true/false for applied/unchanged, or null if the request failed (network). */
  function refresh() {
    return GM.api('GET', 'api/activity/' + state.code).then(apply).catch(function () { return null; });
  }

  /* ---------- Rendering ---------- */

  function renderTop() {
    var chip = top.querySelector('.me-chip');
    if (chip) chip.remove();
    if (!state.me) return;
    chip = h('button.me-chip', { type: 'button', 'aria-label': 'Signed in as ' + state.me.name + '. Change name' },
      GM.avatar(state.me.name, state.me.name),
      h('span', { text: state.me.name }));
    chip.addEventListener('click', openNameSheet);
    top.appendChild(chip);
  }

  function currentView() {
    if (!state.activity) return 'missing';
    if (!state.me) return 'name';
    if (state.my.length && !state.editing) return 'done';
    if (state.activity.status !== 'open') return 'closed';
    return 'vote';
  }

  function render() {
    renderTop();
    var view = currentView();
    state.view = view;
    resultsView = null;
    main.innerHTML = '';
    var actions = document.querySelector('.p-actions');
    if (actions) actions.remove();

    if (view === 'missing') renderState('alert', 'We couldn’t find this activity', 'Check the code and try again.', h('a.btn.btn-tinted', { href: GM.url('/'), text: 'Enter a code' }));
    else if (view === 'name') renderName();
    else if (view === 'vote') renderVote();
    else if (view === 'done') renderDone();
    else if (view === 'closed') renderState('lock', 'Voting is closed', 'This poll isn’t taking votes right now. Keep this page open — it updates when voting opens.');
    schedulePoll();
  }

  function renderState(iconName, title, text, extra) {
    main.appendChild(h('section.p-view.p-state', null,
      h('div.p-state-icon', null, GM.icon(iconName)),
      h('h1', { text: title }),
      h('p', { text: text }),
      extra || null));
  }

  function renderName(prefill) {
    var avatar = GM.avatar('?', 'x', 'p-name-avatar');
    var input = h('input.input.input-xl', {
      type: 'text', name: 'name', maxlength: '60', placeholder: 'Your name', autocomplete: 'name',
      autocapitalize: 'words', enterkeyhint: 'go', spellcheck: 'false', 'aria-label': 'Your name', dir: 'auto'
    });
    input.value = prefill || GM.store.get('name') || '';
    var error = h('p.form-error', { role: 'alert' });
    var btn = h('button.btn.btn-primary.btn-lg', { type: 'submit', text: 'Continue' });

    function sync() {
      var v = input.value.trim();
      btn.disabled = v === '';
      avatar.textContent = v ? GM.initials(v) : '?';
      avatar.setAttribute('data-hue', v ? GM.hue(v) : 7);
    }
    input.addEventListener('input', sync);
    sync();

    var form = h('form', { novalidate: true }, input, error, btn,
      h('p.note', null, GM.icon('shield'), h('span', { text: 'Your name is saved on this device, so you only type it once. The presenter sees it next to your answers.' })));
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = input.value.trim();
      if (!name) return;
      error.textContent = '';
      GM.busy(btn, true);
      GM.api('POST', 'api/me', { name: name })
        .then(function (d) {
          rememberMe(d.me);
          return refresh();
        })
        .then(function () { render(); })
        .catch(function (err) {
          GM.busy(btn, false);
          error.textContent = err.message;
        });
    });

    main.appendChild(h('section.p-view.p-name', null,
      avatar,
      h('h1.p-title', { text: 'What’s your name?' }),
      h('p.p-desc', { text: 'Join ' + (state.activity ? '“' + state.activity.title + '”' : 'the activity') + ' in one tap.' }),
      form));
    setTimeout(function () { if (!input.value) input.focus(); }, 350);
  }

  function renderVote() {
    var a = state.activity;
    var ids = a.options.map(function (o) { return o.id; });
    state.selected = state.selected.filter(function (id) { return ids.indexOf(id) >= 0; });
    if (state.editing && !state.selected.length) state.selected = state.my.slice();

    var submit = h('button.btn.btn-primary.btn-lg.btn-block', { type: 'button', text: state.editing ? 'Update vote' : 'Submit vote' });
    submit.disabled = state.selected.length === 0;

    var opts = GM.Poll.options(a, state.selected, function (sel) {
      state.selected = sel;
      submit.disabled = sel.length === 0;
    });

    main.appendChild(h('section.p-view', null,
      h('span.p-kicker', null, GM.icon('poll'), 'Poll'),
      h('h1.p-title', { dir: 'auto', text: a.title }),
      a.description ? h('p.p-desc', { dir: 'auto', text: a.description }) : null,
      h('p.p-hint', { text: GM.Poll.hint(a.settings) }),
      opts));

    submit.addEventListener('click', function () { vote(submit); });
    var inner = h('div.p-actions-inner', null, submit);
    if (state.editing) {
      inner.appendChild(h('button.btn.btn-plain', {
        type: 'button', text: 'Keep my previous vote',
        onclick: function () { state.editing = false; render(); }
      }));
    }
    document.body.appendChild(h('div.p-actions', null, inner));
  }

  function vote(btn) {
    if (!state.selected.length) return;
    GM.busy(btn, true);
    GM.api('POST', 'api/activity/' + state.code + '/vote', { option_ids: state.selected })
      .then(function (d) {
        apply(d);
        state.editing = false;
        state.selected = [];
        state.justVoted = true;
        if (navigator.vibrate) {
          try { navigator.vibrate([12, 40, 18]); } catch (e) { /* ignore */ }
        }
        render();
      })
      .catch(function (err) {
        GM.busy(btn, false);
        GM.toast(err.message, 'error');
        if (err.status === 401) {
          rememberMe(null);
          render();
        } else if (err.code === 'closed' || err.code === 'stale' || err.code === 'already_voted') {
          state.editing = false;
          refresh().then(render);
        }
      });
  }

  function renderDone() {
    var a = state.activity;
    var first = (state.me.name || '').trim().split(/\s+/)[0];
    var picks = h('div.my-pick');
    state.my.forEach(function (id) {
      var o = a.options.filter(function (x) { return x.id === id; })[0];
      if (!o) return;
      picks.appendChild(h('div.my-pick-item', null,
        o.image ? h('img', { src: o.image, alt: '' }) : h('span.tick', { html: GM.Poll.CHECK }),
        h('span', { dir: 'auto', text: o.label || 'Your choice' })));
    });

    var check = h('div', {
      html: '<svg class="check-anim" viewBox="0 0 96 96" aria-hidden="true"><circle cx="48" cy="48" r="46"/><path d="M29 49.5l12.5 12.5L67 36"/></svg>'
    }).firstChild;

    var section = h('section.p-view.p-done', null,
      check,
      h('h1', { text: state.justVoted ? 'Thanks, ' + first + '!' : 'You’ve voted' }),
      h('p.lead', { text: a.status === 'open' ? 'Your vote is in.' : 'Voting has closed.' }),
      picks);

    if (state.results) {
      var holder = h('div');
      var count = h('span', { text: state.results.voters + ' ' + GM.voterWord(state.results.voters, a.settings.multiple) });
      section.appendChild(h('div.card.p-results', null,
        h('div.p-results-head', null, h('span', { text: 'Live results' }), h('span', null, h('span.live-dot'), count)),
        holder));
      resultsView = { view: new GM.Results(holder, { compact: true }), count: count };
      resultsView.view.update(a, state.results);
    } else {
      section.appendChild(h('p.p-note', { text: 'Watch the big screen for the results.' }));
    }
    main.appendChild(section);
    state.justVoted = false;

    if (a.status === 'open' && a.settings.allow_change) {
      document.body.appendChild(h('div.p-actions', null, h('div.p-actions-inner', null,
        h('button.btn.btn-tinted.btn-lg.btn-block', {
          type: 'button', text: 'Change my vote',
          onclick: function () { state.editing = true; state.selected = state.my.slice(); render(); }
        }))));
    }
  }

  /* ---------- Name sheet ---------- */

  function openNameSheet() {
    var input = h('input.input.input-xl', { type: 'text', maxlength: '60', autocomplete: 'name', spellcheck: 'false', 'aria-label': 'Your name', dir: 'auto' });
    input.value = state.me.name;
    var err = h('p.form-error', { role: 'alert' });
    var save = h('button.btn.btn-primary.btn-lg', { type: 'submit', text: 'Save' });
    var fresh = h('button.btn.btn-plain', { type: 'button', text: 'Not ' + state.me.name + '? Start as someone new' });
    var form = h('form.sheet-body', { novalidate: true },
      h('h2.dialog-title', { text: 'Your name' }),
      h('p.muted', { style: { fontSize: '15px', marginTop: '-8px' }, text: 'This is what the presenter sees next to your answers.' }),
      input, err, save, fresh);
    var dlg = h('dialog.sheet', null, h('div.sheet-grabber'), form);
    document.body.appendChild(dlg);

    function close() {
      dlg.close();
      dlg.remove();
    }
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      GM.busy(save, true);
      GM.api('POST', 'api/me', { name: input.value })
        .then(function (d) {
          rememberMe(d.me);
          close();
          renderTop();
          GM.toast('Name updated', 'success');
        })
        .catch(function (e2) {
          GM.busy(save, false);
          err.textContent = e2.message;
        });
    });
    fresh.addEventListener('click', function () {
      GM.api('POST', 'api/me/forget').catch(function () {}).then(function () {
        rememberMe(null);
        state.my = [];
        state.results = null;
        state.editing = false;
        close();
        render();
      });
    });
    dlg.showModal();
  }

  /* ---------- Staying in sync ---------- */

  function schedulePoll() {
    clearTimeout(pollTimer);
    var view = state.view;
    if (view === 'missing' || view === 'name') return;
    var every = view === 'done' && state.results ? 3000 : view === 'closed' ? 4000 : 8000;
    pollTimer = setTimeout(pollOnce, every);
  }

  /** Everything that changes what the participant sees — except live numbers. */
  function signature() {
    var a = state.activity;
    return JSON.stringify([
      currentView(),
      a ? [a.title, a.description, a.status, a.results_hidden, a.settings, a.options] : null,
      state.my,
      !!state.results
    ]);
  }

  function pollOnce() {
    if (document.hidden) return schedulePoll();
    var before = signature();
    GM.api('GET', 'api/activity/' + state.code + '?v=' + state.version)
      .then(function (d) {
        if (apply(d)) {
          if (signature() !== before) {
            render();
            return;
          }
          if (resultsView && state.results) {
            // Same screen, new numbers: animate them in place.
            resultsView.view.update(state.activity, state.results);
            resultsView.count.textContent = state.results.voters + ' ' + GM.voterWord(state.results.voters, state.activity.settings.multiple);
          }
        }
        schedulePoll();
      })
      .catch(schedulePoll);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && state.view && state.view !== 'name') {
      clearTimeout(pollTimer);
      pollOnce();
    }
  });

  /* ---------- Start ---------- */

  if (state.me) {
    rememberMe(state.me);
    render();
  } else if (GM.store.get('pid') && state.activity) {
    // The cookie was cleared but this device remembers the participant. If the
    // network fails, try again rather than forgetting them (apply() clears the
    // backup only when the server says the token is unknown).
    var restore = function (attempt) {
      refresh().then(function (ok) {
        if (ok === null && attempt < 3) {
          setTimeout(function () { restore(attempt + 1); }, 1200 * attempt);
          return;
        }
        render();
      });
    };
    restore(1);
  } else {
    render();
  }
})();
