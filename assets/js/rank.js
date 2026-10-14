/* Ranking UI: cards people drag into order — shared by the participant page and
   the editor's phone preview. Touch: press and drag a card, or grab its handle.
   Mouse and pen: drag. Keyboard: arrow keys move the focused card. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;

  var HOLD_MS = 160; // touch: a press this long picks a card up; a quick swipe scrolls the page
  var SLOP = 8;      // px a finger may wander while pressing before it counts as a scroll
  var EDGE = 72;     // px from the top or bottom of the screen where dragging scrolls

  function hint() {
    return 'Drag the cards into order';
  }

  /** Seeded random numbers, so a person sees the same shuffle every time. */
  function seeded(text) {
    var a = 1779033703 ^ text.length;
    for (var i = 0; i < text.length; i++) {
      a = Math.imul(a ^ text.charCodeAt(i), 3432918353);
      a = (a << 13) | (a >>> 19);
    }
    return function () {
      a = (a + 0x6d2b79f5) | 0;
      var t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function shuffle(ids, seed) {
    var out = ids.slice();
    var rand = seeded(String(seed));
    for (var i = out.length - 1; i > 0; i--) {
      var j = Math.floor(rand() * (i + 1));
      var t = out[i];
      out[i] = out[j];
      out[j] = t;
    }
    return out;
  }

  /** `order` checked against the items in `base`: unknown ids dropped, missing ones added in base order. */
  function complete(order, base) {
    var known = {};
    var seen = {};
    var out = [];
    base.forEach(function (id) { known[id] = true; });
    (order || []).forEach(function (id) {
      if (known[id] && !seen[id]) {
        seen[id] = true;
        out.push(id);
      }
    });
    base.forEach(function (id) { if (!seen[id]) out.push(id); });
    return out;
  }

  function buzz(ms) {
    if (navigator.vibrate) {
      try { navigator.vibrate(ms); } catch (e) { /* ignore */ }
    }
  }

  /** The nearest scrolling box around el, or null for the page itself. */
  function scrollParent(el) {
    for (var p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      var oy = getComputedStyle(p).overflowY;
      if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight) return p;
    }
    return null;
  }

  function scrollTop(box) {
    return box ? box.scrollTop : window.pageYOffset || document.documentElement.scrollTop;
  }

  /**
   * The sortable list.
   * question: { title, options:[{id,label,image,color}], settings:{top_label,bottom_label} }
   * order:    option ids, top first. onChange(newOrder) runs after every move.
   * opts:     { disabled, demo } — demo shows a hand moving the first card once.
   */
  function list(question, order, onChange, opts) {
    opts = opts || {};
    var s = question.settings || {};
    var locked = !!opts.disabled;
    var byId = {};
    question.options.forEach(function (o, i) { byId[o.id] = { o: o, i: i }; });
    var hasImages = question.options.some(function (o) { return !!o.image; });
    var ids = complete(order, question.options.map(function (o) { return o.id; }));

    var root = h('div.rank' + (locked ? '.is-locked' : ''));
    var helpId = 'rank-help-' + Math.random().toString(36).slice(2, 8);
    var listEl = h('ol.rank-list', { 'aria-label': question.title || 'Ranking' });
    var status = h('div.sr-only', { 'aria-live': 'polite' });
    var items = ids.map(item);

    if (!locked) {
      root.appendChild(h('p.p-hint.rank-hint', null, GM.icon('move'), h('span', { text: hint() })));
      root.appendChild(h('span.sr-only', { id: helpId, text: 'Use the up and down arrow keys to move it.' }));
    }
    if (s.top_label) root.appendChild(h('div.rank-end.is-top', null, GM.icon('arrow-up'), h('span', { dir: 'auto', text: s.top_label })));
    items.forEach(function (it) { listEl.appendChild(it.el); });
    root.appendChild(listEl);
    if (s.bottom_label) root.appendChild(h('div.rank-end.is-bottom', null, GM.icon('arrow-down'), h('span', { dir: 'auto', text: s.bottom_label })));
    root.appendChild(status);
    renumber(items);

    function item(id) {
      var o = byId[id].o;
      var title = o.label || 'Item ' + (byId[id].i + 1);
      var name = title + (o.tag ? ' (' + o.tag.name + ')' : ''); // what screen readers hear
      var num = h('span.rank-num', { 'aria-hidden': 'true' });
      var el = h('li.rank-item', {
        'data-color': o.color || 'charcoal',
        tabindex: locked ? null : '0',
        'aria-describedby': locked ? null : helpId
      },
        num,
        hasImages ? h('span.rank-img', null, o.image
          ? h('img', { src: o.image, alt: '', draggable: 'false', loading: 'lazy', decoding: 'async' })
          : h('span.rank-img-empty', { text: GM.initials(title) })) : null,
        h('span.rank-text', null,
          GM.tagPill(o.tag),
          h('span.rank-label', { dir: 'auto', text: o.label || (o.image ? '' : 'Item ' + (byId[id].i + 1)) })),
        locked ? null : h('span.rank-grip', { 'aria-hidden': 'true' }, GM.icon('grip')));
      return { id: id, el: el, num: num, name: name };
    }

    /** Show each card's place. `list` may be a preview of the order while dragging. */
    function renumber(list) {
      list.forEach(function (it, i) {
        it.num.textContent = String(i + 1);
        it.el.setAttribute('aria-label', it.name + ', place ' + (i + 1) + ' of ' + list.length);
      });
    }

    function itemFor(el) {
      return items.filter(function (it) { return it.el === el; })[0];
    }

    /** Move one card to index `to`, sliding every card from where it is to its new place. */
    function moveTo(it, to) {
      // Measure where each card is now, put it in its new place without animating,
      // then slide it from the old spot to the new one.
      items.forEach(function (x) {
        x.first = x.el.getBoundingClientRect().top;
        x.el.style.transition = 'none';
        x.el.style.transform = '';
      });
      items.splice(items.indexOf(it), 1);
      items.splice(to, 0, it);
      listEl.insertBefore(it.el, items[to + 1] ? items[to + 1].el : null);
      items.forEach(function (x) {
        var dy = x.first - x.el.getBoundingClientRect().top;
        if (Math.abs(dy) >= 0.5) x.el.style.transform = 'translateY(' + dy + 'px)';
      });
      listEl.getBoundingClientRect(); // apply the starting positions before animating
      items.forEach(function (x) {
        x.el.style.transition = '';
        x.el.style.transform = '';
      });
      renumber(items);
    }

    function changed(it) {
      var place = items.indexOf(it) + 1;
      status.textContent = it.name + ', moved to place ' + place + ' of ' + items.length;
      GM.store.set('rankMoved', '1');
      buzz(8);
      if (onChange) onChange(items.map(function (x) { return x.id; }));
    }

    /* ---------- Demo: a hand drags the first card down and back, once ---------- */

    var demo = null;
    if (!locked && opts.demo && items.length > 1 && !GM.store.get('rankMoved')) {
      demo = { it: items[0], hand: h('span.rank-hand', { 'aria-hidden': 'true' }, GM.icon('hand')) };
      demo.it.el.classList.add('is-demo');
      demo.it.el.appendChild(demo.hand);
      demo.it.el.addEventListener('animationend', function (e) {
        if (e.target === e.currentTarget) stopDemo();
      });
    }

    function stopDemo() {
      if (!demo) return;
      demo.it.el.classList.remove('is-demo');
      demo.hand.remove();
      demo = null;
    }

    /* ---------- Dragging ---------- */

    var press = null; // a pointer is down on a card
    var drag = null;  // …and has picked it up

    listEl.addEventListener('pointerdown', function (e) {
      if (locked || press || !e.isPrimary || (e.pointerType === 'mouse' && e.button !== 0)) return;
      var el = e.target.closest('.rank-item');
      if (!el) return;
      press = { it: itemFor(el), id: e.pointerId, type: e.pointerType, x0: e.clientX, y0: e.clientY, y: e.clientY, timer: 0 };
      if (e.pointerType === 'touch') {
        // The handle picks up at once; elsewhere a short press does, so swiping still scrolls.
        if (e.target.closest('.rank-grip')) begin();
        else press.timer = setTimeout(begin, HOLD_MS);
      }
      document.addEventListener('pointermove', onMove);
      document.addEventListener('pointerup', onUp);
      document.addEventListener('pointercancel', onUp);
    });

    // While a card is picked up, a moving finger drags it instead of scrolling.
    listEl.addEventListener('touchmove', function (e) {
      if (drag) e.preventDefault();
    }, { passive: false });
    listEl.addEventListener('contextmenu', function (e) {
      if (press) e.preventDefault();
    });

    function onMove(e) {
      if (!press || e.pointerId !== press.id) return;
      press.y = e.clientY;
      if (!drag) {
        var slop = press.type === 'touch' ? SLOP : 3;
        if (Math.abs(e.clientY - press.y0) <= slop && Math.abs(e.clientX - press.x0) <= slop) return;
        if (press.type === 'touch') {
          end(false); // a swipe: the page scrolls
          return;
        }
        begin();
      }
      e.preventDefault();
      layout();
    }

    function onUp(e) {
      if (press && e.pointerId === press.id) end(true);
    }

    function begin() {
      if (!press || drag) return;
      clearTimeout(press.timer);
      stopDemo();
      var from = items.indexOf(press.it);
      drag = {
        it: press.it,
        from: from,
        to: from,
        tops: items.map(function (x) { return x.el.offsetTop; }),
        heights: items.map(function (x) { return x.el.offsetHeight; }),
        box: scrollParent(listEl),
        raf: 0
      };
      drag.gap = items.length > 1 ? drag.tops[1] - drag.tops[0] - drag.heights[0] : 0;
      drag.scroll0 = scrollTop(drag.box);
      try { listEl.setPointerCapture(press.id); } catch (e) { /* not supported */ }
      drag.it.el.classList.add('is-dragging');
      root.classList.add('is-sorting');
      buzz(12);
      drag.raf = requestAnimationFrame(autoScroll);
    }

    /** Follow the pointer and open a gap where the card would land. */
    function layout() {
      var d = drag;
      var last = items.length - 1;
      var dy = press.y - press.y0 + (scrollTop(d.box) - d.scroll0);
      dy = Math.max(d.tops[0] - d.tops[d.from], Math.min(d.tops[last] + d.heights[last] - d.tops[d.from] - d.heights[d.from], dy));
      d.it.el.style.transform = 'translateY(' + dy + 'px) scale(1.02)';

      var center = d.tops[d.from] + d.heights[d.from] / 2 + dy;
      var to = 0;
      for (var j = 0; j < items.length; j++) {
        if (j !== d.from && d.tops[j] + d.heights[j] / 2 < center) to++;
      }
      var step = d.heights[d.from] + d.gap;
      items.forEach(function (x, j) {
        if (j === d.from) return;
        var shift = d.from < to && j > d.from && j <= to ? -step : d.from > to && j >= to && j < d.from ? step : 0;
        x.el.style.transform = shift ? 'translateY(' + shift + 'px)' : '';
      });
      if (to !== d.to) {
        d.to = to;
        var preview = items.slice();
        preview.splice(d.from, 1);
        preview.splice(to, 0, d.it);
        renumber(preview);
        buzz(4);
      }
    }

    /** Near the top or bottom edge, scroll so long lists can be sorted end to end. */
    function autoScroll() {
      if (!drag) return;
      var box = drag.box;
      var r = box ? box.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
      var bottom = r.bottom;
      var bar = document.querySelector('.p-actions');
      if (bar) bottom = Math.min(bottom, bar.getBoundingClientRect().top);
      var y = press.y;
      var speed = 0;
      if (y < r.top + EDGE) speed = -Math.min(1, (r.top + EDGE - y) / EDGE);
      else if (y > bottom - EDGE) speed = Math.min(1, (y - bottom + EDGE) / EDGE);
      if (speed) {
        var before = scrollTop(box);
        if (box) box.scrollTop += speed * 14;
        else window.scrollBy(0, speed * 14);
        if (scrollTop(box) !== before) layout();
      }
      drag.raf = requestAnimationFrame(autoScroll);
    }

    function end(drop) {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onUp);
      if (!press) return;
      clearTimeout(press.timer);
      var d = drag;
      press = null;
      drag = null;
      if (!d) return;
      cancelAnimationFrame(d.raf);
      d.it.el.classList.remove('is-dragging');
      root.classList.remove('is-sorting');
      var to = drop ? d.to : d.from;
      moveTo(d.it, to);
      if (to !== d.from) changed(d.it);
    }

    /* ---------- Keyboard ---------- */

    listEl.addEventListener('keydown', function (e) {
      var step = { ArrowUp: -1, ArrowDown: 1 }[e.key];
      if (locked || press || !step || !e.target.classList.contains('rank-item')) return;
      e.preventDefault();
      var it = itemFor(e.target);
      var to = items.indexOf(it) + step;
      if (to < 0 || to >= items.length) return;
      stopDemo();
      moveTo(it, to);
      it.el.focus();
      changed(it);
    });

    return root;
  }

  GM.Rank = { hint: hint, list: list, shuffle: shuffle, complete: complete };
})();
