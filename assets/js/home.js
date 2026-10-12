/* Join page: six digit boxes that behave like one field (typing, paste, backspace, autofill). */
(function () {
  'use strict';

  var GM = window.GM;
  var form = document.getElementById('join-form');
  var group = document.getElementById('code-input');
  var boxes = GM.qsa('input', group);
  var btn = document.getElementById('join-btn');
  var error = document.getElementById('join-error');

  function code() {
    return boxes.map(function (b) { return b.value; }).join('');
  }

  function fill(digits, start) {
    var i = start;
    digits.split('').forEach(function (d) {
      if (i < boxes.length) boxes[i++].value = d;
    });
    (boxes[Math.min(i, boxes.length - 1)]).focus();
    sync();
  }

  function sync() {
    var c = code();
    btn.disabled = c.length !== 6;
    error.textContent = '';
    group.classList.remove('is-error');
    if (c.length === 6) go();
  }

  boxes.forEach(function (box, i) {
    box.addEventListener('input', function () {
      var digits = box.value.replace(/\D/g, '');
      box.value = '';
      if (digits) fill(digits, i);
      else sync();
    });
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !box.value && i > 0) {
        boxes[i - 1].value = '';
        boxes[i - 1].focus();
        sync();
        e.preventDefault();
      } else if (e.key === 'ArrowLeft' && i > 0) {
        boxes[i - 1].focus();
      } else if (e.key === 'ArrowRight' && i < boxes.length - 1) {
        boxes[i + 1].focus();
      }
    });
    box.addEventListener('paste', function (e) {
      var text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
      if (text) {
        e.preventDefault();
        fill(text.slice(0, 6), 0);
      }
    });
    box.addEventListener('focus', function () { box.select(); });
  });

  var going = false;
  function go() {
    if (going) return;
    var c = code();
    going = true;
    GM.busy(btn, true);
    GM.api('GET', 'api/activity/' + c)
      .then(function () { window.location.href = GM.url(c); })
      .catch(function (err) {
        going = false;
        GM.busy(btn, false);
        btn.disabled = false;
        group.classList.add('is-error');
        error.textContent = err.status === 404 ? 'No activity uses that code. Check it and try again.' : err.message;
      });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (code().length === 6) go();
  });

  boxes[0].focus();
})();
