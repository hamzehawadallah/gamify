/* Participant page: name once → answer each question of the activity (pick
   options, or drag a ranking into order) → summary (and live results, if the
   trainer allows it). */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;
  var initial = GM.data() || {};

  var state = {
    code: initial.code,
    activity: initial.activity,
    me: initial.me,
    my: initial.my || {},          // {question_id: [option ids]}
    results: initial.results || null,
    version: initial.version || 0,
    step: null,                    // index of the question on screen; null = decide automatically
    editing: false,                // going through the questions again to change answers
    selected: [],
    justVoted: false,
    personaSkipped: false,         // no persona was free (or the list failed to load): carry on without
    view: null
  };

  var shell = document.getElementById('app');
  var top = shell.querySelector('.p-top');
  var main = shell.querySelector('.p-main');
  var resultViews = [];
  var pollTimer = null;

  /* ---------- Helpers ---------- */

  function questions() {
    return (state.activity && state.activity.questions) || [];
  }

  function answered(q) {
    var picks = state.my[q.id];
    return !!(picks && picks.length);
  }

  function allAnswered() {
    return questions().length > 0 && questions().every(answered);
  }

  function firstUnanswered() {
    var qs = questions();
    for (var i = 0; i < qs.length; i++) if (!answered(qs[i])) return i;
    return -1;
  }

  function anyAnswered() {
    return questions().some(answered);
  }

  /** A question's heading: its own title, the activity's (single question) or "Question N". */
  function qTitle(q, i) {
    if (q.title) return q.title;
    return questions().length === 1 ? state.activity.title : 'Question ' + (i + 1);
  }

  function optionLabel(q, id) {
    for (var i = 0; i < q.options.length; i++) {
      if (q.options[i].id === id) return q.options[i].label || (isRanking(q) ? 'Item ' : 'Option ') + (i + 1);
    }
    return '';
  }

  function isRanking(q) {
    return q.type === 'ranking';
  }

  /** Same answer? Votes compare as sets, rankings in order. */
  function sameAnswer(q, a, b) {
    if (!a || !b) return false;
    return isRanking(q) ? a.join() === b.join() : a.slice().sort().join() === b.slice().sort().join();
  }

  /** A ranking starts from the order on screen, else the saved one, else this person's own shuffle. */
  function rankingStart(q) {
    var ids = q.options.map(function (o) { return o.id; });
    var base = q.settings.shuffle ? GM.Rank.shuffle(ids, (state.me ? state.me.token : '') + ':' + q.id) : ids;
    var from = state.selected.length ? state.selected : answered(q) ? state.my[q.id] : [];
    return GM.Rank.complete(from, base);
  }

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
    state.my = d.my || {};
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
    chip = h('button.me-chip', { type: 'button', 'aria-label': 'Signed in as ' + state.me.name + '. Change name or persona' },
      GM.avatar(state.me.name, state.me.emoji),
      h('span', { text: state.me.name }));
    chip.addEventListener('click', openNameSheet);
    top.appendChild(chip);
  }

  function currentView() {
    if (!state.activity) return 'missing';
    if (!state.me || (!state.me.persona && !state.personaSkipped)) return 'name';
    if (!questions().length) return 'empty';
    var open = state.activity.status === 'open';
    if (state.editing && open) return 'vote';
    if (state.step !== null && state.step < questions().length && open) return 'vote';
    if (allAnswered() || (anyAnswered() && !open) || state.step !== null) return 'done';
    if (!open) return 'closed';
    return 'vote';
  }

  function render() {
    renderTop();
    var view = currentView();
    state.view = view;
    resultViews = [];
    main.innerHTML = '';
    var actions = document.querySelector('.p-actions');
    if (actions) actions.remove();

    if (view === 'missing') renderState('alert', 'We couldn’t find this activity', 'Check the code and try again.', h('a.btn.btn-tinted', { href: GM.url('/'), text: 'Enter a code' }));
    else if (view === 'name') renderName();
    else if (view === 'vote') renderVote();
    else if (view === 'done') renderDone();
    else if (view === 'empty') renderState('clock', 'Almost ready', 'The trainer is still setting this activity up. Keep this page open.');
    else if (view === 'closed') renderState('lock', 'Voting is closed', 'This activity isn’t taking votes right now. Keep this page open — it updates when voting opens.');
    window.scrollTo(0, 0);
    schedulePoll();
  }

  function renderState(iconName, title, text, extra) {
    main.appendChild(h('section.p-view.p-state', null,
      h('div.p-state-icon', null, GM.icon(iconName)),
      h('h1', { text: title }),
      h('p', { text: text }),
      extra || null));
  }

  /* ---------- Personas ---------- */

  var holdMemo = '';

  /** This visit's claim on a suggested persona, kept for the browser session. */
  function holdToken(value) {
    if (value) holdMemo = value;
    try {
      if (value) sessionStorage.setItem('gm.hold', value);
      return sessionStorage.getItem('gm.hold') || holdMemo;
    } catch (e) {
      return holdMemo;
    }
  }

  /**
   * The persona scroller: a row of emoji with the selected one first. Every
   * visitor is offered a different free persona, held for them until they
   * confirm, and personas other people use aren't shown.
   */
  function personaPicker(onChange) {
    var list = [];
    var selected = null;
    var loaded = false;
    var row = h('div.persona-row', { role: 'radiogroup', 'aria-label': 'Your persona' });
    var el = h('div.persona-pick', { hidden: true }, h('div.persona-label', { text: 'Your persona' }), row);

    function draw() {
      row.innerHTML = '';
      list.forEach(function (p) {
        var on = p.key === selected;
        row.appendChild(h('button.persona', {
          type: 'button', role: 'radio', 'aria-checked': on ? 'true' : 'false', tabindex: on ? '0' : '-1',
          'aria-label': p.label, title: p.label, 'data-key': p.key, text: p.emoji,
          onclick: function () { choose(p.key); }
        }));
      });
      el.hidden = !list.length;
      if (onChange) onChange();
    }

    function take(d, keepOrder) {
      holdToken(d.hold);
      if (keepOrder && list.length) {
        var free = {};
        d.personas.forEach(function (p) { free[p.key] = true; });
        list = list.filter(function (p) { return free[p.key]; });
      } else {
        list = d.personas;
      }
      selected = d.selected;
      loaded = true;
      draw();
    }

    function load() {
      return GM.api('POST', 'api/personas', { hold: holdToken() })
        .then(function (d) { take(d, false); row.scrollLeft = 0; })
        .catch(function () { loaded = true; draw(); });
    }

    function choose(key) {
      if (key === selected) return;
      var before = selected;
      selected = key;
      draw();
      GM.api('POST', 'api/personas', { hold: holdToken(), want: key })
        .then(function (d) {
          take(d, true);
          if (d.selected !== key) GM.toast('Someone just picked that one. Choose another.', 'error');
        })
        .catch(function () { selected = before; draw(); });
    }

    // Arrow keys move along the row, like any radio group.
    row.addEventListener('keydown', function (e) {
      var step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
      if (!step || !list.length) return;
      e.preventDefault();
      if (document.documentElement.dir === 'rtl' && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) step = -step;
      var i = list.findIndex(function (p) { return p.key === selected; });
      var next = list[Math.max(0, Math.min(list.length - 1, i + step))];
      choose(next.key);
      var btn = row.querySelector('[data-key="' + next.key + '"]');
      if (btn) btn.focus();
    });

    load();
    return {
      el: el,
      value: function () { return selected; },
      ready: function () { return loaded; },
      reload: load
    };
  }

  /** Saves name + persona; a persona someone else just took reloads the picker. */
  function saveMe(name, picker, errorEl) {
    return GM.api('POST', 'api/me', { name: name, persona: picker.value(), hold: holdToken() })
      .then(function (d) {
        rememberMe(d.me);
        if (!d.me.persona) state.personaSkipped = true; // none were free
        return d;
      })
      .catch(function (err) {
        errorEl.textContent = err.message;
        if (err.code === 'persona_taken' || err.code === 'persona_invalid') picker.reload();
        throw err;
      });
  }

  /** First visit: persona + name. Also shown to people named earlier who have no persona yet. */
  function renderName() {
    var returning = !!state.me;
    var input = h('input.input.input-xl', {
      type: 'text', name: 'name', maxlength: '60', placeholder: 'Your name', autocomplete: 'name',
      autocapitalize: 'words', enterkeyhint: 'go', spellcheck: 'false', 'aria-label': 'Your name', dir: 'auto'
    });
    input.value = returning ? state.me.name : (GM.store.get('name') || '');
    var error = h('p.form-error', { role: 'alert' });
    var btn = h('button.btn.btn-primary.btn-lg', { type: 'submit', text: 'Continue' });
    var picker = personaPicker(sync);

    function sync() {
      btn.disabled = input.value.trim() === '' || !picker.ready();
    }
    input.addEventListener('input', sync);
    sync();

    var form = h('form', { novalidate: true }, input, error, btn,
      h('p.note', null, GM.icon('shield'), h('span', { text: 'Your name and persona are saved on this device, so you only choose them once. The presenter sees your persona next to your answers.' })));
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = input.value.trim();
      if (!name || !picker.ready()) return;
      error.textContent = '';
      GM.busy(btn, true);
      saveMe(name, picker, error)
        .then(function () { return refresh(); })
        .then(function () { render(); })
        .catch(function () { GM.busy(btn, false); sync(); });
    });

    main.appendChild(h('section.p-view.p-name', null,
      picker.el,
      h('h1.p-title', { text: returning ? 'Pick your persona' : 'What’s your name?' }),
      returning
        ? h('p.p-desc', { text: 'Each persona belongs to one person. One is picked for you above; tap another to change it.' })
        : state.activity ? h('p.p-desc', { dir: 'auto', text: state.activity.title }) : null,
      form));
    if (!returning) setTimeout(function () { if (!input.value) input.focus(); }, 350);
  }

  function renderVote() {
    var a = state.activity;
    var qs = questions();
    if (state.step === null || state.step >= qs.length) {
      var next = firstUnanswered();
      state.step = state.editing || next < 0 ? 0 : next;
    }
    var i = state.step;
    var q = qs[i];
    var multi = qs.length > 1;
    var locked = answered(q) && !a.settings.allow_change;

    // Start from the saved answer for this question.
    var ranking = isRanking(q);
    if (ranking) {
      state.selected = rankingStart(q);
    } else {
      var ids = q.options.map(function (o) { return o.id; });
      state.selected = state.selected.filter(function (id) { return ids.indexOf(id) >= 0; });
      if (!state.selected.length && answered(q)) state.selected = state.my[q.id].slice();
    }

    var isLast = i === qs.length - 1;
    var noun = ranking ? 'ranking' : 'vote';
    var submit = h('button.btn.btn-primary.btn-lg', { type: 'button' });
    function syncButton() {
      var was = answered(q);
      var changed = !was || !sameAnswer(q, state.selected, state.my[q.id]);
      if (!multi) submit.textContent = !was ? 'Submit ' + noun : changed ? 'Update ' + noun : 'Keep my ' + noun;
      else if (!isLast) submit.textContent = 'Next';
      else submit.textContent = !was ? 'Submit' : changed ? 'Update & finish' : 'Done';
      submit.disabled = state.selected.length === 0 && !locked;
    }

    var onPick = function (sel) {
      state.selected = sel;
      syncButton();
    };
    var opts = ranking
      ? GM.Rank.list(q, state.selected, onPick, { disabled: locked, demo: true })
      : GM.Poll.options(q, state.selected, onPick, { disabled: locked });
    syncButton();

    var view = h('section.p-view', { 'data-color': q.color || 'red' });
    var titleText = qTitle(q, i);
    if (multi || a.title !== titleText) {
      view.appendChild(h('div.p-activity', null, h('span', { dir: 'auto', text: a.title })));
    }
    if (multi && i === 0 && a.description) {
      view.appendChild(h('p.p-activity-desc', { dir: 'auto', text: a.description }));
    }
    if (multi) {
      var bars = h('div.p-progress-bars', { 'aria-hidden': 'true' });
      qs.forEach(function (qq, k) {
        bars.appendChild(h('span' + (k === i ? '.is-current' : answered(qq) ? '.is-done' : '')));
      });
      view.appendChild(h('div.p-progress', null, bars, h('span.p-progress-count', { text: (i + 1) + ' of ' + qs.length })));
    }
    view.appendChild(h('h1.p-title', { dir: 'auto', text: titleText }));
    if (!multi && a.description) view.appendChild(h('p.p-desc', { dir: 'auto', text: a.description }));
    if (q.description) view.appendChild(h('p.p-desc', { dir: 'auto', text: q.description }));
    if (!ranking) view.appendChild(h('p.p-hint', { text: GM.Poll.hint(q.settings) }));
    view.appendChild(opts);
    if (locked) view.appendChild(h('p.p-locked', null, GM.icon('lock'), 'You’ve answered this one. Answers can’t be changed for this activity.'));
    main.appendChild(view);

    submit.addEventListener('click', function () { submitStep(submit, q, locked); });
    var row = h('div.p-actions-row', null);
    if (i > 0) {
      row.appendChild(h('button.btn.btn-lg.btn-back', {
        type: 'button', 'aria-label': 'Previous question',
        onclick: function () { state.step = i - 1; state.selected = []; render(); }
      }, GM.icon('chevron-left')));
    } else {
      row.style.gridTemplateColumns = '1fr';
    }
    row.appendChild(submit);
    var inner = h('div.p-actions-inner', null, row);
    if (multi && !answered(q) && !isLast) {
      inner.appendChild(h('button.btn.btn-plain', {
        type: 'button', text: 'Skip this question',
        onclick: function () { state.step = i + 1; state.selected = []; render(); }
      }));
    }
    if (state.editing && !multi) {
      inner.appendChild(h('button.btn.btn-plain', {
        type: 'button', text: 'Keep my previous ' + noun,
        onclick: function () { finish(false); }
      }));
    }
    document.body.appendChild(h('div.p-actions', null, inner));
  }

  /** Save this question's answer (if it changed), then move on. */
  function submitStep(btn, q, locked) {
    var qs = questions();
    var unchanged = answered(q) && sameAnswer(q, state.selected, state.my[q.id]);
    if (locked || unchanged || !state.selected.length) {
      advance();
      return;
    }
    GM.busy(btn, true);
    GM.api('POST', 'api/activity/' + state.code + '/vote', { question_id: q.id, option_ids: state.selected })
      .then(function (d) {
        apply(d);
        if (navigator.vibrate) {
          try { navigator.vibrate(12); } catch (e) { /* ignore */ }
        }
        advance();
      })
      .catch(function (err) {
        GM.busy(btn, false);
        GM.toast(err.message, 'error');
        if (err.status === 401) {
          rememberMe(null);
          render();
        } else if (err.code === 'closed' || err.code === 'stale' || err.code === 'already_voted') {
          refresh().then(function () { advanceIfAnswered(q, qs); });
        }
      });
  }

  function advanceIfAnswered(q) {
    if (answered(q)) advance();
    else render();
  }

  function advance() {
    var qs = questions();
    state.selected = [];
    var next = state.step + 1;
    if (!state.editing) {
      // Jump to the next question that still needs an answer.
      while (next < qs.length && answered(qs[next])) next++;
    }
    if (next >= qs.length) finish(true);
    else {
      state.step = next;
      render();
    }
  }

  function finish(voted) {
    state.editing = false;
    state.step = questions().length; // past the end → summary
    state.selected = [];
    state.justVoted = voted && allAnswered();
    if (navigator.vibrate && state.justVoted) {
      try { navigator.vibrate([12, 40, 18]); } catch (e) { /* ignore */ }
    }
    render();
  }

  function renderDone() {
    var a = state.activity;
    var qs = questions();
    var first = (state.me.name || '').trim().split(/\s+/)[0];
    var open = a.status === 'open';
    var missing = firstUnanswered();

    var check = h('div', {
      html: '<svg class="check-anim" viewBox="0 0 96 96" aria-hidden="true"><circle cx="48" cy="48" r="46"/><path d="M29 49.5l12.5 12.5L67 36"/></svg>'
    }).firstChild;

    var noun = qs.length === 1 && isRanking(qs[0]) ? 'ranking' : 'vote';
    var heading = state.justVoted ? 'Thanks, ' + first + '!' : (missing < 0 ? 'You’re all done' : 'Your answers');
    var lead = !open ? 'Voting has closed.'
      : missing < 0 ? (qs.length > 1 ? 'All your answers are in.' : 'Your ' + noun + ' is in.')
      : 'You skipped ' + GM.plural(qs.filter(function (q) { return !answered(q); }).length, 'question', 'questions') + '.';

    var summary = h('div.p-summary');
    qs.forEach(function (q, i) {
      var mine = state.my[q.id] || [];
      var picks = mine.map(function (id) { return optionLabel(q, id); }).filter(Boolean);
      var answer;
      if (isRanking(q) && picks.length) {
        // Their own order, each item with its place and color.
        answer = h('ol.p-summary-rank', null, mine.map(function (id, k) {
          var o = q.options.filter(function (x) { return x.id === id; })[0];
          return o ? h('li', { 'data-color': o.color || 'charcoal' },
            h('span.n', { text: String(k + 1) }),
            h('span', { dir: 'auto', text: optionLabel(q, id) })) : null;
        }));
      } else {
        answer = h('div.p-summary-a' + (picks.length ? '' : '.is-empty'), { dir: 'auto', text: picks.length ? picks.join(', ') : 'Skipped' });
      }
      var card = h('div.p-summary-card', { 'data-color': q.color || 'red' },
        qs.length > 1 ? h('div.p-summary-q', { dir: 'auto', text: qTitle(q, i) }) : null,
        answer);
      var r = state.results && state.results.questions && state.results.questions[i];
      if (r && r.counts) {
        var holder = h('div');
        card.appendChild(holder);
        var view = new GM.Results(holder, { compact: true });
        view.update({ options: q.options, status: a.status, type: q.type }, r);
        resultViews.push({ view: view, index: i });
      }
      summary.appendChild(card);
    });

    var section = h('section.p-view.p-done', null,
      check,
      h('h1', { text: heading }),
      h('p.lead', { text: lead }),
      summary);
    if (!state.results) section.appendChild(h('p.p-note', { text: 'Watch the big screen for the results.' }));
    main.appendChild(section);
    state.justVoted = false;

    var buttons = [];
    if (open && missing >= 0) {
      buttons.push(h('button.btn.btn-primary.btn-lg.btn-block', {
        type: 'button', text: 'Answer skipped questions',
        onclick: function () { state.step = missing; state.editing = false; render(); }
      }));
    }
    if (open && a.settings.allow_change && anyAnswered()) {
      buttons.push(h('button.btn.btn-tinted.btn-lg.btn-block', {
        type: 'button', text: qs.length > 1 ? 'Change my answers' : 'Change my ' + noun,
        onclick: function () { state.editing = true; state.step = 0; state.selected = []; render(); }
      }));
    }
    if (buttons.length) {
      document.body.appendChild(h('div.p-actions', null, h('div.p-actions-inner', null, buttons)));
    }
  }

  /* ---------- Name sheet ---------- */

  function openNameSheet() {
    var input = h('input.input.input-xl', { type: 'text', maxlength: '60', autocomplete: 'name', spellcheck: 'false', 'aria-label': 'Your name', dir: 'auto' });
    input.value = state.me.name;
    var err = h('p.form-error', { role: 'alert' });
    var save = h('button.btn.btn-primary.btn-lg', { type: 'submit', text: 'Save' });
    var fresh = h('button.btn.btn-plain', { type: 'button', text: 'Not ' + state.me.name + '? Start as someone new' });
    var picker = personaPicker(function () { save.disabled = !picker || !picker.ready(); });
    var form = h('form.sheet-body', { novalidate: true },
      h('h2.dialog-title', { text: 'Your persona and name' }),
      picker.el,
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
      err.textContent = '';
      GM.busy(save, true);
      saveMe(input.value, picker, err)
        .then(function () {
          close();
          renderTop();
          GM.toast('Saved', 'success');
        })
        .catch(function () { GM.busy(save, false); });
    });
    fresh.addEventListener('click', function () {
      GM.api('POST', 'api/me/forget').catch(function () {}).then(function () {
        rememberMe(null);
        state.my = {};
        state.results = null;
        state.editing = false;
        state.step = null;
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
    var every = view === 'done' && state.results ? 3000 : view === 'closed' || view === 'empty' ? 4000 : 8000;
    pollTimer = setTimeout(pollOnce, every);
  }

  /** Everything that changes what the participant sees — except live numbers. */
  function signature() {
    var a = state.activity;
    return JSON.stringify([
      currentView(),
      a ? [a.title, a.description, a.status, a.results_hidden, a.settings, a.questions] : null,
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
          if (state.results && resultViews.length) {
            // Same screen, new numbers: animate them in place.
            resultViews.forEach(function (rv) {
              var q = questions()[rv.index];
              var r = state.results.questions[rv.index];
              if (q && r && r.counts) rv.view.update({ options: q.options, status: state.activity.status, type: q.type }, r);
            });
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
