/* Poll editor: question, options with images (drag to reorder), settings and a live phone preview. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;
  var boot = GM.data() || {};
  var existing = boot.editor;

  var state = {
    title: existing ? existing.title : '',
    description: existing ? existing.description : '',
    settings: Object.assign({}, boot.defaults || {}, existing ? existing.settings : {}),
    options: (existing ? existing.options : [{ label: '' }, { label: '' }]).map(function (o) {
      return { key: uid(), id: o.id || null, label: o.label || '', image: o.image || null, imageUrl: o.image_url || null, uploading: false };
    })
  };
  var dirty = false;
  var saving = false;

  var form = document.getElementById('editor');
  var titleEl = document.getElementById('q-title');
  var descEl = document.getElementById('q-desc');
  var list = document.getElementById('opt-list');
  var addBtn = document.getElementById('opt-add');
  var fileInput = document.getElementById('file-input');
  var preview = document.getElementById('preview');
  var note = document.getElementById('editor-note');
  var saveBtn = document.getElementById('save-btn');
  var maxRow = document.getElementById('max-row');
  var maxOut = document.getElementById('max-out');
  var MAX_OPTIONS = 20;

  function uid() {
    return 'o' + Math.random().toString(36).slice(2, 9);
  }

  function changed() {
    dirty = true;
    schedulePreview();
  }

  /* ---------- Question ---------- */

  titleEl.value = state.title;
  descEl.value = state.description;
  var fitTitle = GM.autosize(titleEl);
  var fitDesc = GM.autosize(descEl);
  titleEl.addEventListener('input', function () { state.title = titleEl.value; changed(); });
  descEl.addEventListener('input', function () { state.description = descEl.value; changed(); });
  titleEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      var first = list.querySelector('.opt-input');
      if (first) first.focus();
    }
  });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { fitTitle(); fitDesc(); });

  /* ---------- Options ---------- */

  function renderOptions(focusKey) {
    list.innerHTML = '';
    state.options.forEach(function (o, i) {
      list.appendChild(optionRow(o, i));
    });
    addBtn.hidden = state.options.length >= MAX_OPTIONS;
    if (focusKey) {
      var input = list.querySelector('[data-key="' + focusKey + '"] .opt-input');
      if (input) input.focus();
    }
    schedulePreview();
  }

  function optionRow(o, i) {
    var row = h('div.opt-row', { 'data-key': o.key });
    if (o.isNew) {
      row.classList.add('is-new');
      o.isNew = false;
    }

    var handle = h('button.drag-handle', { type: 'button', 'aria-label': 'Drag to reorder', title: 'Drag to reorder' }, GM.icon('grip'));
    handle.addEventListener('pointerdown', function (e) { startDrag(e, row, o); });
    handle.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        e.preventDefault();
        move(o, e.key === 'ArrowUp' ? -1 : 1);
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
      if (e.key === 'Enter') {
        e.preventDefault();
        var idx = state.options.indexOf(o);
        var next = state.options[idx + 1];
        if (next) {
          var el = list.querySelector('[data-key="' + next.key + '"] .opt-input');
          if (el) el.focus();
        } else if (o.label.trim() || o.image) {
          addOption();
        }
      } else if (e.key === 'Backspace' && input.value === '' && !o.image && state.options.length > 2) {
        e.preventDefault();
        var at = state.options.indexOf(o);
        removeOption(o);
        var prev = state.options[Math.max(0, at - 1)];
        if (prev) {
          var p = list.querySelector('[data-key="' + prev.key + '"] .opt-input');
          if (p) p.focus();
        }
      }
    });

    var remove = h('button.btn.btn-icon.btn-sm.btn-plain.btn-remove', {
      type: 'button', 'aria-label': 'Remove option ' + (i + 1), title: 'Remove option'
    }, GM.icon('x'));
    remove.disabled = state.options.length <= 2;
    remove.addEventListener('click', function () { removeOption(o); });

    row.appendChild(handle);
    row.appendChild(slot);
    if (o.image) {
      row.appendChild(h('button.img-remove', {
        type: 'button', 'aria-label': 'Remove image', title: 'Remove image',
        onclick: function () {
          o.image = null;
          o.imageUrl = null;
          changed();
          renderOptions();
        }
      }, GM.icon('x')));
    }
    row.appendChild(input);
    row.appendChild(remove);
    return row;
  }

  function addOption() {
    if (state.options.length >= MAX_OPTIONS) return;
    var o = { key: uid(), id: null, label: '', image: null, imageUrl: null, isNew: true };
    state.options.push(o);
    changed();
    renderOptions(o.key);
  }

  function removeOption(o) {
    if (state.options.length <= 2) return;
    state.options.splice(state.options.indexOf(o), 1);
    changed();
    renderOptions();
    updateNote();
  }

  function move(o, delta) {
    var i = state.options.indexOf(o);
    var j = i + delta;
    if (j < 0 || j >= state.options.length) return;
    state.options.splice(i, 1);
    state.options.splice(j, 0, o);
    changed();
    renderOptions();
    var handle = list.querySelector('[data-key="' + o.key + '"] .drag-handle');
    if (handle) handle.focus();
  }

  addBtn.addEventListener('click', addOption);

  /* Drag to reorder (mouse, pen and touch) */
  function startDrag(e, row, o) {
    if (e.button !== undefined && e.button !== 0) return;
    e.preventDefault();
    var rows = GM.qsa('.opt-row', list);
    var startY = e.clientY;
    var rect = row.getBoundingClientRect();
    var height = rect.height;
    var from = state.options.indexOf(o);
    var to = from;
    row.classList.add('is-dragging');
    row.setPointerCapture && row.setPointerCapture(e.pointerId);

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
      rows.forEach(function (r) { r.style.transform = ''; r.style.transition = ''; });
      row.classList.remove('is-dragging');
      if (to !== from) {
        state.options.splice(from, 1);
        state.options.splice(to, 0, o);
        changed();
      }
      renderOptions();
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
    renderOptions();
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
        renderOptions();
      });
  }

  /* ---------- Settings ---------- */

  GM.qsa('[data-setting]').forEach(function (input) {
    var key = input.getAttribute('data-setting');
    input.checked = !!state.settings[key];
    input.addEventListener('change', function () {
      state.settings[key] = input.checked;
      syncMax();
      changed();
    });
  });

  function syncMax() {
    maxRow.hidden = !state.settings.multiple;
    var n = state.settings.max_choices || 0;
    maxOut.textContent = n === 0 ? 'No limit' : String(n);
  }
  GM.qsa('[data-step]', maxRow).forEach(function (btn) {
    btn.addEventListener('click', function () {
      var n = (state.settings.max_choices || 0) + parseInt(btn.getAttribute('data-step'), 10);
      var limit = Math.max(2, state.options.length);
      if (n < 0) n = 0;
      if (n === 1) n = btn.getAttribute('data-step') === '1' ? 2 : 0;
      if (n > limit) n = 0;
      state.settings.max_choices = n;
      syncMax();
      changed();
    });
  });
  syncMax();

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

  var previewSelected = [];
  function renderPreview() {
    var opts = state.options.map(function (o, i) {
      return { id: i + 1, label: o.label.trim(), image: o.imageUrl };
    });
    var activity = {
      title: state.title.trim() || 'Your question',
      description: state.description.trim(),
      settings: state.settings,
      options: opts
    };
    previewSelected = previewSelected.filter(function (id) { return id <= opts.length; });
    var submit = h('button.btn.btn-primary.btn-lg.btn-block', { type: 'button', text: 'Submit vote', tabindex: '-1' });
    submit.disabled = !previewSelected.length;
    var scroll = preview.querySelector('.p-shell');
    var scrollTop = scroll ? scroll.scrollTop : 0;

    var shell = h('div.p-shell', { 'aria-hidden': 'true' },
      h('header.p-top', null,
        h('span.brand', null, h('img.brand-mark', { src: document.querySelector('.nav .brand-mark').src, alt: '' }), GM.config.appName || ''),
        h('span.me-chip', null, GM.avatar('Alex Morgan', 3), h('span', { text: 'Alex' }))),
      h('main.p-main', null, h('section.p-view', null,
        h('span.p-kicker', null, GM.icon('poll'), 'Poll'),
        h('h1.p-title', { dir: 'auto', text: activity.title }),
        activity.description ? h('p.p-desc', { dir: 'auto', text: activity.description }) : null,
        h('p.p-hint', { text: GM.Poll.hint(state.settings) }),
        GM.Poll.options(activity, previewSelected, function (sel) {
          previewSelected = sel;
          submit.disabled = !sel.length;
        }))),
      h('div.p-actions', null, h('div.p-actions-inner', null, submit)));

    preview.innerHTML = '';
    preview.appendChild(shell);
    shell.scrollTop = scrollTop;
    GM.qsa('button', shell).forEach(function (b) { b.tabIndex = -1; });
  }

  /* ---------- Save ---------- */

  function updateNote() {
    if (existing && existing.votes > 0) {
      note.textContent = 'This poll has ' + GM.plural(existing.votes, 'vote', 'votes') + '. Removing an option also removes its votes.';
    }
  }
  updateNote();

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (saving) return;
    if (!state.title.trim()) {
      GM.toast('Add a question first.', 'error');
      titleEl.focus();
      return;
    }
    var filled = state.options.filter(function (o) { return o.label.trim() || o.image; });
    if (filled.length < 2) {
      GM.toast('Add at least two options.', 'error');
      var empty = list.querySelector('.opt-input');
      GM.qsa('.opt-input', list).some(function (inp) {
        if (!inp.value.trim()) { empty = inp; return true; }
        return false;
      });
      if (empty) empty.focus();
      return;
    }
    if (state.options.some(function (o) { return o.uploading; })) {
      GM.toast('Wait for the images to finish uploading.', 'error');
      return;
    }

    var body = {
      type: 'poll',
      title: state.title,
      description: state.description,
      settings: state.settings,
      options: filled.map(function (o) { return { id: o.id, label: o.label, image: o.image }; })
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

  renderOptions();
  if (!existing) setTimeout(function () { titleEl.focus(); }, 200);
})();
