/* Activity editor: title and instructions, one or more questions (each with a
   color, options with images, drag to reorder), settings and a live phone preview. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;
  var boot = GM.data() || {};
  var existing = boot.editor;
  var COLORS = boot.colors || ['red', 'blue', 'yellow', 'green', 'purple', 'teal', 'orange', 'charcoal'];
  var MAX_QUESTIONS = boot.maxQuestions || 12;
  var MAX_OPTIONS = 20;

  function uid() {
    return 'k' + Math.random().toString(36).slice(2, 9);
  }

  function makeOption(o) {
    o = o || {};
    return { key: uid(), id: o.id || null, label: o.label || '', image: o.image || null, imageUrl: o.image_url || null, uploading: false };
  }

  function makeQuestion(q, position) {
    q = q || {};
    var s = q.settings || {};
    return {
      key: uid(),
      id: q.id || null,
      title: q.title || '',
      description: q.description || '',
      settings: {
        multiple: !!s.multiple,
        max_choices: s.max_choices || 0,
        color: s.color || COLORS[position % COLORS.length]
      },
      options: (q.options && q.options.length ? q.options : [{}, {}]).map(makeOption)
    };
  }

  var state = {
    title: existing ? existing.title : '',
    description: existing ? existing.description : '',
    settings: Object.assign({}, boot.defaults || {}, existing ? existing.settings : {}),
    questions: (existing && existing.questions.length ? existing.questions : [{}]).map(makeQuestion)
  };
  var focusKey = state.questions[0].key;
  var dirty = false;
  var saving = false;

  var form = document.getElementById('editor');
  var titleEl = document.getElementById('a-title');
  var descEl = document.getElementById('a-desc');
  var qList = document.getElementById('questions');
  var addQBtn = document.getElementById('add-question');
  var fileInput = document.getElementById('file-input');
  var preview = document.getElementById('preview');
  var note = document.getElementById('editor-note');
  var saveBtn = document.getElementById('save-btn');

  function changed() {
    dirty = true;
    schedulePreview();
  }

  function findQ(key) {
    return state.questions.filter(function (q) { return q.key === key; })[0];
  }

  /* ---------- Activity title ---------- */

  titleEl.value = state.title;
  descEl.value = state.description;
  var fitTitle = GM.autosize(titleEl);
  var fitDesc = GM.autosize(descEl);
  titleEl.addEventListener('input', function () { state.title = titleEl.value; changed(); });
  descEl.addEventListener('input', function () { state.description = descEl.value; changed(); });
  titleEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      descEl.focus();
    }
  });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { fitTitle(); fitDesc(); });

  /* ---------- Questions ---------- */

  function renderQuestions(focus) {
    qList.innerHTML = '';
    state.questions.forEach(function (q, qi) {
      qList.appendChild(questionCard(q, qi));
    });
    addQBtn.hidden = state.questions.length >= MAX_QUESTIONS;
    if (focus) {
      var el = qList.querySelector(focus);
      if (el) el.focus();
    }
    updateNote();
    schedulePreview();
  }

  function questionCard(q, qi) {
    var n = state.questions.length;
    var card = h('div.card.qcard', { 'data-color': q.settings.color, 'data-key': q.key });
    if (q.isNew) q.isNew = false;
    else card.style.animation = 'none';
    card.addEventListener('focusin', function () {
      if (focusKey !== q.key) {
        focusKey = q.key;
        schedulePreview();
      }
    });

    // Header: number, color, move, delete
    var dots = h('div.color-dots', { role: 'radiogroup', 'aria-label': 'Question color' });
    COLORS.forEach(function (c) {
      dots.appendChild(h('button.color-dot', {
        type: 'button', 'data-color': c, title: c, 'aria-label': c + ' color',
        'aria-pressed': q.settings.color === c ? 'true' : 'false',
        onclick: function () {
          q.settings.color = c;
          focusKey = q.key;
          changed();
          renderQuestions();
        }
      }));
    });
    var up = h('button.btn.btn-icon.btn-sm.btn-plain', { type: 'button', title: 'Move up', 'aria-label': 'Move question up', onclick: function () { moveQ(q, -1); } }, GM.icon('chevron-left'));
    up.firstChild.style.transform = 'rotate(90deg)';
    up.disabled = qi === 0;
    var down = h('button.btn.btn-icon.btn-sm.btn-plain', { type: 'button', title: 'Move down', 'aria-label': 'Move question down', onclick: function () { moveQ(q, 1); } }, GM.icon('chevron-left'));
    down.firstChild.style.transform = 'rotate(-90deg)';
    down.disabled = qi === n - 1;
    var del = h('button.btn.btn-icon.btn-sm.btn-plain.btn-del', { type: 'button', title: 'Delete question', 'aria-label': 'Delete question', onclick: function () { removeQ(q); } }, GM.icon('trash'));
    del.disabled = n <= 1;

    card.appendChild(h('div.qcard-head', null,
      h('span.qcard-badge', { text: String(qi + 1) }),
      h('span.qcard-label', { text: 'Question ' + (qi + 1) }),
      dots,
      h('div.qcard-tools', null, up, down, del)));

    // Title + description
    var title = h('input.qcard-title', {
      type: 'text', maxlength: '300', dir: 'auto', 'aria-label': 'Question ' + (qi + 1) + ' title',
      placeholder: n === 1 ? 'Question (optional — uses the activity title)' : 'Question title'
    });
    title.value = q.title;
    title.addEventListener('input', function () { q.title = title.value; changed(); });
    var desc = h('input.qcard-desc', {
      type: 'text', maxlength: '1000', dir: 'auto', 'aria-label': 'Question ' + (qi + 1) + ' description',
      placeholder: 'Short description (optional)'
    });
    desc.value = q.description;
    desc.addEventListener('input', function () { q.description = desc.value; changed(); });
    card.appendChild(h('div.qcard-fields', null, title, desc));

    // Options
    var list = h('div.opt-editor');
    q.options.forEach(function (o, i) { list.appendChild(optionRow(q, o, i, list)); });
    card.appendChild(list);
    var add = h('button.opt-add', { type: 'button' }, GM.icon('plus'), 'Add option');
    add.hidden = q.options.length >= MAX_OPTIONS;
    add.addEventListener('click', function () { addOption(q); });
    card.appendChild(add);

    // Multiple choice
    var multi = h('input.switch', { type: 'checkbox', 'aria-label': 'Allow multiple answers' });
    multi.checked = q.settings.multiple;
    multi.addEventListener('change', function () {
      q.settings.multiple = multi.checked;
      if (!multi.checked) q.settings.max_choices = 0;
      focusKey = q.key;
      changed();
      renderQuestions();
    });
    var foot = h('div.qcard-foot', null, h('label', null, multi, 'Allow multiple answers'));
    if (q.settings.multiple) {
      var out = h('output', { text: q.settings.max_choices ? 'Up to ' + q.settings.max_choices : 'No limit' });
      var step = function (d) {
        var v = (q.settings.max_choices || 0) + d;
        var limit = Math.max(2, q.options.length);
        if (v < 0) v = 0;
        if (v === 1) v = d > 0 ? 2 : 0;
        if (v > limit) v = 0;
        q.settings.max_choices = v;
        out.textContent = v ? 'Up to ' + v : 'No limit';
        changed();
      };
      foot.appendChild(h('span.stepper', null,
        h('button', { type: 'button', 'aria-label': 'Fewer', text: '−', onclick: function () { step(-1); } }),
        out,
        h('button', { type: 'button', 'aria-label': 'More', text: '+', onclick: function () { step(1); } })));
    }
    card.appendChild(foot);
    return card;
  }

  function addQuestion() {
    if (state.questions.length >= MAX_QUESTIONS) return;
    var q = makeQuestion({}, state.questions.length);
    q.isNew = true;
    state.questions.push(q);
    focusKey = q.key;
    changed();
    renderQuestions('[data-key="' + q.key + '"] .qcard-title');
    var card = qList.querySelector('[data-key="' + q.key + '"]');
    if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function removeQ(q) {
    if (state.questions.length <= 1) return;
    var filled = q.options.some(function (o) { return o.label.trim() || o.image; }) || q.title.trim();
    var go = function () {
      state.questions.splice(state.questions.indexOf(q), 1);
      if (focusKey === q.key) focusKey = state.questions[0].key;
      changed();
      renderQuestions();
    };
    if (!filled) return go();
    GM.confirm({
      title: 'Delete this question?',
      message: q.id ? 'Its options and any votes on it will be removed when you save.' : 'Its options will be removed.',
      confirmLabel: 'Delete',
      danger: true
    }).then(function (ok) { if (ok) go(); });
  }

  function moveQ(q, delta) {
    var i = state.questions.indexOf(q);
    var j = i + delta;
    if (j < 0 || j >= state.questions.length) return;
    state.questions.splice(i, 1);
    state.questions.splice(j, 0, q);
    focusKey = q.key;
    changed();
    renderQuestions();
  }

  addQBtn.addEventListener('click', addQuestion);

  /* ---------- Options ---------- */

  function optionRow(q, o, i, list) {
    var row = h('div.opt-row', { 'data-key': o.key });
    if (o.isNew) {
      row.classList.add('is-new');
      o.isNew = false;
    }

    var handle = h('button.drag-handle', { type: 'button', 'aria-label': 'Drag to reorder', title: 'Drag to reorder' }, GM.icon('grip'));
    handle.addEventListener('pointerdown', function (e) { startDrag(e, q, row, o, list); });
    handle.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        e.preventDefault();
        moveOption(q, o, e.key === 'ArrowUp' ? -1 : 1);
      }
    });

    var slot = h('button.img-slot', { type: 'button', 'aria-label': o.image ? 'Change image' : 'Add image', title: o.image ? 'Change image' : 'Add image' });
    if (o.imageUrl) slot.appendChild(h('img', { src: o.imageUrl, alt: '' }));
    else slot.appendChild(GM.icon('image'));
    if (o.uploading) slot.appendChild(h('span.spinner'));
    slot.addEventListener('click', function () { pickImage(o); });
    slot.addEventListener('dragover', function (e) { e.preventDefault(); slot.classList.add('is-dragover'); });
    slot.addEventListener('dragleave', function () { slot.classList.remove('is-dragover'); });
    slot.addEventListener('drop', function (e) {
      e.preventDefault();
      slot.classList.remove('is-dragover');
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) upload(o, f);
    });

    var input = h('input.opt-input', {
      type: 'text', maxlength: '300', dir: 'auto',
      placeholder: o.image ? 'Caption (optional)' : 'Option ' + (i + 1),
      'aria-label': 'Option ' + (i + 1)
    });
    input.value = o.label;
    input.addEventListener('input', function () { o.label = input.value; changed(); });
    input.addEventListener('keydown', function (e) {
      var idx = q.options.indexOf(o);
      if (e.key === 'Enter') {
        e.preventDefault();
        var next = q.options[idx + 1];
        if (next) focusOption(next);
        else if (o.label.trim() || o.image) addOption(q);
      } else if (e.key === 'Backspace' && input.value === '' && !o.image && q.options.length > 2) {
        e.preventDefault();
        removeOption(q, o);
        var prev = q.options[Math.max(0, idx - 1)];
        if (prev) focusOption(prev);
      }
    });

    var remove = h('button.btn.btn-icon.btn-sm.btn-plain.btn-remove', {
      type: 'button', 'aria-label': 'Remove option ' + (i + 1), title: 'Remove option'
    }, GM.icon('x'));
    remove.disabled = q.options.length <= 2;
    remove.addEventListener('click', function () { removeOption(q, o); });

    row.appendChild(handle);
    row.appendChild(slot);
    if (o.image) {
      row.appendChild(h('button.img-remove', {
        type: 'button', 'aria-label': 'Remove image', title: 'Remove image',
        onclick: function () {
          o.image = null;
          o.imageUrl = null;
          changed();
          renderQuestions();
        }
      }, GM.icon('x')));
    }
    row.appendChild(input);
    row.appendChild(remove);
    return row;
  }

  function focusOption(o) {
    var el = qList.querySelector('[data-key="' + o.key + '"] .opt-input');
    if (el) el.focus();
  }

  function addOption(q) {
    if (q.options.length >= MAX_OPTIONS) return;
    var o = makeOption();
    o.isNew = true;
    q.options.push(o);
    focusKey = q.key;
    changed();
    renderQuestions();
    focusOption(o);
  }

  function removeOption(q, o) {
    if (q.options.length <= 2) return;
    q.options.splice(q.options.indexOf(o), 1);
    changed();
    renderQuestions();
  }

  function moveOption(q, o, delta) {
    var i = q.options.indexOf(o);
    var j = i + delta;
    if (j < 0 || j >= q.options.length) return;
    q.options.splice(i, 1);
    q.options.splice(j, 0, o);
    changed();
    renderQuestions();
    var handle = qList.querySelector('[data-key="' + o.key + '"] .drag-handle');
    if (handle) handle.focus();
  }

  /* Drag to reorder options within a question (mouse, pen and touch) */
  function startDrag(e, q, row, o, list) {
    if (e.button !== undefined && e.button !== 0) return;
    e.preventDefault();
    var rows = GM.qsa('.opt-row', list);
    var startY = e.clientY;
    var height = row.getBoundingClientRect().height;
    var from = q.options.indexOf(o);
    var to = from;
    row.classList.add('is-dragging');

    function onMove(ev) {
      var dy = ev.clientY - startY;
      row.style.transform = 'translateY(' + dy + 'px) scale(1.02)';
      to = Math.max(0, Math.min(rows.length - 1, from + Math.round(dy / height)));
      rows.forEach(function (r, idx) {
        if (r === row) return;
        var shift = 0;
        if (from < to && idx > from && idx <= to) shift = -height;
        if (from > to && idx < from && idx >= to) shift = height;
        r.style.transition = 'transform .25s cubic-bezier(.2,.8,.2,1)';
        r.style.transform = shift ? 'translateY(' + shift + 'px)' : '';
      });
    }
    function onUp() {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onUp);
      if (to !== from) {
        q.options.splice(from, 1);
        q.options.splice(to, 0, o);
        changed();
      }
      renderQuestions();
    }
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
    document.addEventListener('pointercancel', onUp);
  }

  /* Images */
  var pickingFor = null;
  function pickImage(o) {
    pickingFor = o;
    fileInput.value = '';
    fileInput.click();
  }
  fileInput.addEventListener('change', function () {
    var f = fileInput.files && fileInput.files[0];
    if (f && pickingFor) upload(pickingFor, f);
    pickingFor = null;
  });

  /** Shrink big photos in the browser first so uploads are quick on hotel Wi-Fi. */
  function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1500000 || !window.createImageBitmap) {
      return Promise.resolve(file);
    }
    return createImageBitmap(file).then(function (bmp) {
      var scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(bmp.width * scale);
      canvas.height = Math.round(bmp.height * scale);
      canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) {
          resolve(blob && blob.size < file.size ? new File([blob], 'image.webp', { type: blob.type }) : file);
        }, 'image/webp', 0.86);
      });
    }).catch(function () { return file; });
  }

  function upload(o, file) {
    if (!/^image\//.test(file.type)) {
      GM.toast('Please choose an image file.', 'error');
      return;
    }
    o.uploading = true;
    var localUrl = URL.createObjectURL(file);
    var previous = { image: o.image, imageUrl: o.imageUrl };
    o.imageUrl = localUrl;
    renderQuestions();
    shrink(file)
      .then(function (f) {
        var fd = new FormData();
        fd.append('image', f, f.name || 'image');
        return GM.api('POST', 'admin/api/upload', fd);
      })
      .then(function (r) {
        o.image = r.image;
        o.imageUrl = r.url;
        changed();
      })
      .catch(function (err) {
        o.image = previous.image;
        o.imageUrl = previous.imageUrl;
        GM.toast(err.message, 'error');
      })
      .then(function () {
        o.uploading = false;
        URL.revokeObjectURL(localUrl);
        renderQuestions();
      });
  }

  /* ---------- Activity settings ---------- */

  GM.qsa('[data-setting]').forEach(function (input) {
    var key = input.getAttribute('data-setting');
    input.checked = !!state.settings[key];
    input.addEventListener('change', function () {
      state.settings[key] = input.checked;
      changed();
    });
  });

  /* ---------- Phone preview ---------- */

  var previewQueued = false;
  function schedulePreview() {
    if (previewQueued || !preview) return;
    previewQueued = true;
    requestAnimationFrame(function () {
      previewQueued = false;
      renderPreview();
    });
  }

  var previewSelected = {};
  function renderPreview() {
    var qs = state.questions;
    var qi = Math.max(0, qs.indexOf(findQ(focusKey)));
    var q = qs[qi];
    var multi = qs.length > 1;
    var activityTitle = state.title.trim() || 'Your activity';
    var qTitle = q.title.trim() || (multi ? 'Question ' + (qi + 1) : activityTitle);
    var question = {
      title: qTitle,
      settings: q.settings,
      options: q.options.map(function (o, i) { return { id: i + 1, label: o.label.trim(), image: o.imageUrl }; })
    };
    var sel = (previewSelected[q.key] || []).filter(function (id) { return id <= question.options.length; });
    var submit = h('button.btn.btn-primary.btn-lg.btn-block', { type: 'button', text: multi && qi < qs.length - 1 ? 'Next' : 'Submit', tabindex: '-1' });
    submit.disabled = !sel.length;

    var view = h('section.p-view', { 'data-color': q.settings.color });
    if (multi || qTitle !== activityTitle) view.appendChild(h('div.p-activity', null, h('span', { text: activityTitle })));
    if (multi && qi === 0 && state.description.trim()) view.appendChild(h('p.p-activity-desc', { dir: 'auto', text: state.description.trim() }));
    if (multi) {
      var bars = h('div.p-progress-bars');
      qs.forEach(function (x, k) { bars.appendChild(h('span' + (k === qi ? '.is-current' : k < qi ? '.is-done' : ''))); });
      view.appendChild(h('div.p-progress', null, bars, h('span.p-progress-count', { text: (qi + 1) + ' of ' + qs.length })));
    }
    view.appendChild(h('h1.p-title', { dir: 'auto', text: qTitle }));
    if (!multi && state.description.trim()) view.appendChild(h('p.p-desc', { dir: 'auto', text: state.description.trim() }));
    if (q.description.trim()) view.appendChild(h('p.p-desc', { dir: 'auto', text: q.description.trim() }));
    view.appendChild(h('p.p-hint', { text: GM.Poll.hint(q.settings) }));
    view.appendChild(GM.Poll.options(question, sel, function (s) {
      previewSelected[q.key] = s;
      submit.disabled = !s.length;
    }));

    var scroll = preview.querySelector('.p-shell');
    var scrollTop = scroll ? scroll.scrollTop : 0;
    var shell = h('div.p-shell', { 'aria-hidden': 'true' },
      h('header.p-top', null,
        h('span.brand', null, h('img.brand-logo', { src: document.querySelector('.nav .brand-logo').src, alt: '' })),
        h('span.me-chip', null, GM.avatar('Alex Morgan', 3), h('span', { text: 'Alex' }))),
      h('main.p-main', null, view),
      h('div.p-actions', null, h('div.p-actions-inner', null, submit)));

    preview.innerHTML = '';
    preview.appendChild(shell);
    shell.scrollTop = scrollTop;
    GM.qsa('button', shell).forEach(function (b) { b.tabIndex = -1; });
  }

  /* ---------- Save ---------- */

  function updateNote() {
    if (existing && existing.votes > 0) {
      note.textContent = GM.plural(existing.votes, 'person has', 'people have') + ' voted. Removing an option or question also removes its votes.';
    }
  }

  function focusProblem(q, selector) {
    focusKey = q.key;
    renderQuestions(selector);
    var card = qList.querySelector('[data-key="' + q.key + '"]');
    if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (saving) return;
    if (!state.title.trim()) {
      GM.toast('Give the activity a title first.', 'error');
      titleEl.focus();
      return;
    }
    var multi = state.questions.length > 1;
    for (var i = 0; i < state.questions.length; i++) {
      var q = state.questions[i];
      var filled = q.options.filter(function (o) { return o.label.trim() || o.image; });
      if (multi && !q.title.trim()) {
        GM.toast('Give question ' + (i + 1) + ' a title.', 'error');
        focusProblem(q, '[data-key="' + q.key + '"] .qcard-title');
        return;
      }
      if (filled.length < 2) {
        GM.toast('Question ' + (i + 1) + ' needs at least two options.', 'error');
        focusProblem(q, '[data-key="' + q.key + '"] .opt-input');
        return;
      }
      if (q.options.some(function (o) { return o.uploading; })) {
        GM.toast('Wait for the images to finish uploading.', 'error');
        return;
      }
    }

    var body = {
      type: 'poll',
      title: state.title,
      description: state.description,
      settings: state.settings,
      questions: state.questions.map(function (q) {
        return {
          id: q.id,
          title: q.title,
          description: q.description,
          settings: q.settings,
          options: q.options
            .filter(function (o) { return o.label.trim() || o.image; })
            .map(function (o) { return { id: o.id, label: o.label, image: o.image }; })
        };
      })
    };
    saving = true;
    GM.busy(saveBtn, true);
    var path = existing ? 'admin/api/activity/' + existing.id : 'admin/api/activities';
    GM.api('POST', path, body)
      .then(function (r) {
        dirty = false;
        window.location.href = r.url;
      })
      .catch(function (err) {
        saving = false;
        GM.busy(saveBtn, false);
        GM.toast(err.message, 'error');
        if (err.status === 401) window.location.href = GM.url('admin/login');
      });
  });

  window.addEventListener('beforeunload', function (e) {
    if (dirty && !saving) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  renderQuestions();
  if (!existing) setTimeout(function () { titleEl.focus(); }, 200);
})();
