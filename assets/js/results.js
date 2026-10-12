/* Live results renderer — used on the presenter screen, the admin page and phones.
   Builds the DOM once, then only updates widths and numbers so changes animate. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;

  function Results(root, opts) {
    this.root = root;
    this.opts = opts || {};
    this.key = null;
    this.rows = {};
    this.el = null;
  }

  Results.prototype.build = function (options) {
    var hasImages = options.some(function (o) { return !!o.image; });
    var n = options.length;
    var compact = !!this.opts.compact;
    this.rows = {};
    // 'list' = compact rows with thumbnails (presenter wall); otherwise bars, or tiles for images.
    this.mode = this.opts.layout === 'list' ? 'list' : hasImages ? 'tiles' : 'bars';

    var el = h('div.results.results--' + this.mode + (compact ? '.is-compact' : ''), { role: 'list' });
    if (this.mode === 'list') {
      el.style.setProperty('--density', String(n <= 4 ? 1 : n <= 5 ? 0.88 : n <= 7 ? 0.76 : 0.64));
    } else if (hasImages) {
      var cols = n <= 4 ? n : n <= 6 ? 3 : n <= 8 ? 4 : 5;
      el.style.setProperty('--cols', String(Math.max(2, cols)));
    } else {
      var density = n <= 4 ? 1 : n <= 6 ? 0.86 : n <= 8 ? 0.74 : n <= 12 ? 0.6 : 0.5;
      el.style.setProperty('--density', String(density));
    }

    var self = this;
    var mode = this.mode;
    options.forEach(function (o, i) {
      var label = o.label || (hasImages ? '' : 'Option ' + (i + 1));
      var fill = h('div.r-fill');
      var pct = h('b.r-pct', { text: '0%' });
      var count = h('span.r-count', { text: '0 votes' });
      var value = h('span.r-value', null, pct, count);
      var row;
      if (mode === 'list') {
        row = h('div.r-item' + (hasImages ? '' : '.no-thumb'), { role: 'listitem' },
          hasImages ? h('div.r-thumb', null, o.image ? h('img', { src: o.image, alt: '', decoding: 'async' }) : null) : null,
          h('div.r-label', { dir: 'auto', text: label || 'Option ' + (i + 1) }),
          value,
          h('div.r-meter', null, fill));
        value.removeChild(count);
      } else if (hasImages) {
        var img = o.image
          ? h('img', { src: o.image, alt: label || 'Option ' + (i + 1), loading: 'lazy', decoding: 'async' })
          : h('div.r-img-empty', { text: GM.initials(label || String(i + 1)) });
        row = h('div.r-tile', { role: 'listitem' },
          h('div.r-img', null, img),
          label ? h('div.r-label', { dir: 'auto', text: label }) : null,
          h('div.r-meter', null, fill),
          value);
      } else {
        row = h('div.r-row', { role: 'listitem' },
          h('div.r-label', { dir: 'auto', text: label }),
          h('div.r-track', null, fill, value));
      }
      self.rows[o.id] = { el: row, fill: fill, pct: pct, count: count, value: value, badge: null };
      el.appendChild(row);
    });

    this.root.innerHTML = '';
    this.root.appendChild(el);
    this.el = el;
  };

  /**
   * question: { options:[{id,label,image}], status }  (status is the activity's)
   * results:  { voters, counts:[{id,count}] }
   */
  Results.prototype.update = function (activity, results) {
    var options = activity.options || [];
    var key = options.map(function (o) { return o.id + '|' + o.label + '|' + (o.image || ''); }).join('~') + (this.opts.compact ? 'c' : '') + (this.opts.layout || '');
    var justBuilt = false;
    if (key !== this.key) {
      this.key = key;
      this.build(options);
      justBuilt = true;
    }

    var counts = {};
    (results.counts || []).forEach(function (c) { counts[c.id] = c.count; });
    var voters = results.voters || 0;
    var max = 0;
    options.forEach(function (o) { max = Math.max(max, counts[o.id] || 0); });
    var showTop = activity.status === 'closed' && max > 0;
    // Bars scale to the leading option; tile and list meters show the share of voters.
    var shareOfVoters = this.mode !== 'bars';
    var self = this;

    // On a fresh build, wait a frame so bars animate up from zero.
    var schedule = justBuilt
      ? function (fn) { requestAnimationFrame(function () { requestAnimationFrame(fn); }); }
      : function (fn) { fn(); };
    schedule(function () {
      options.forEach(function (o) {
        var row = self.rows[o.id];
        if (!row) return;
        var c = counts[o.id] || 0;
        var pct = voters > 0 ? Math.round((c / voters) * 100) : 0;
        var p = shareOfVoters ? pct / 100 : (max > 0 ? c / max : 0);
        row.fill.style.setProperty('--p', String(p));
        row.fill.classList.toggle('is-zero', c === 0);
        GM.countTo(row.pct, pct, '%');
        row.count.textContent = GM.plural(c, 'vote', 'votes');
        row.el.setAttribute('aria-label', (o.label || 'Option') + ': ' + pct + '%, ' + GM.plural(c, 'vote', 'votes'));

        var isTop = showTop && c === max;
        row.el.classList.toggle('is-top', isTop);
        if (isTop && !row.badge) {
          row.badge = h('span.r-top', null, GM.icon('trophy'), 'Top');
          row.value.appendChild(row.badge);
        } else if (!isTop && row.badge) {
          row.badge.remove();
          row.badge = null;
        }
      });
    });
  };

  GM.Results = Results;
})();
