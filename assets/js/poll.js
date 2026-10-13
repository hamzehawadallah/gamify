/* Question screen pieces — shared by the participant page and the editor's phone
   preview: the screen's header (activity panel + question), the instruction row
   and the voting options. */
(function () {
  'use strict';

  var GM = window.GM;
  var h = GM.h;

  var CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';

  function hint(settings) {
    if (!settings.multiple) return 'Choose one';
    if (settings.max_choices > 0) return 'Choose up to ' + settings.max_choices;
    return 'Choose all that apply';
  }

  /** The instruction above the answers, e.g. "Choose one", with an icon in the question's color. */
  function hintRow(text, iconName) {
    return h('p.p-hint', null, GM.icon(iconName || 'check'), h('span', { text: text }));
  }

  /**
   * The top of a question screen. The activity gets its own panel (title,
   * instructions, progress); the question follows with a colored
   * "Question 2 of 3" label, its title and its description, so the two never
   * run together.
   * o: { activityTitle, activityDesc, title, description, index, total,
   *      progress: [{ state: 'done'|'current'|'', color }] (with several questions) }
   */
  function screenHead(o) {
    var multi = o.total > 1;
    // A single question without its own title uses the activity's: don't say it twice.
    var showTitle = !!o.activityTitle && o.activityTitle !== o.title;
    var intro = null;
    if (showTitle || o.activityDesc || multi) {
      intro = h('header.p-intro' + (multi ? '.has-progress' : ''), null,
        showTitle ? h('div.p-intro-title', { dir: 'auto', text: o.activityTitle }) : null,
        o.activityDesc ? h('p.p-intro-desc', { dir: 'auto', text: o.activityDesc }) : null);
      if (multi) {
        var bars = h('div.p-progress-bars', { role: 'img', 'aria-label': 'Question ' + (o.index + 1) + ' of ' + o.total });
        o.progress.forEach(function (p) {
          bars.appendChild(h('span' + (p.state ? '.is-' + p.state : ''), { 'data-color': p.color || 'red' }));
        });
        intro.appendChild(bars);
      }
    }
    var head = h('div.p-qhead', null,
      h('div.p-eyebrow', { text: multi ? 'Question ' + (o.index + 1) + ' of ' + o.total : 'Question' }),
      h('h1.p-title', { dir: 'auto', text: o.title }),
      o.description ? h('p.p-desc', { dir: 'auto', text: o.description }) : null);
    return [intro, head];
  }

  /**
   * Render the option list.
   * selected: array of option ids; onChange(newSelectedArray)
   */
  function options(activity, selected, onChange, opts) {
    opts = opts || {};
    var s = activity.settings || {};
    var multi = !!s.multiple;
    var hasImages = activity.options.some(function (o) { return !!o.image; });
    var list = h('div.opts' + (multi ? '.is-multi' : '') + (hasImages ? '.has-images' : ''), {
      role: multi ? 'group' : 'radiogroup',
      'aria-label': activity.title
    });
    var current = selected.slice();
    var buttons = [];

    activity.options.forEach(function (o, i) {
      var label = o.label || (hasImages ? '' : 'Option ' + (i + 1));
      var btn = h('button.opt', {
        type: 'button',
        role: multi ? 'checkbox' : 'radio',
        'aria-checked': current.indexOf(o.id) >= 0 ? 'true' : 'false',
        'aria-label': label || 'Option ' + (i + 1),
        style: { '--i': i },
        disabled: opts.disabled
      });
      if (hasImages) {
        btn.appendChild(h('span.opt-img', null, o.image
          ? h('img', { src: o.image, alt: '', loading: 'lazy', decoding: 'async' })
          : h('span.opt-img-empty', { text: GM.initials(label || String(i + 1)) })));
      }
      btn.appendChild(h('span.opt-label', { dir: 'auto', text: label }));
      btn.appendChild(h('span.opt-mark', { html: CHECK }));
      btn.addEventListener('click', function () {
        var idx = current.indexOf(o.id);
        if (multi) {
          if (idx >= 0) current.splice(idx, 1);
          else if (s.max_choices > 0 && current.length >= s.max_choices) {
            GM.toast('You can choose up to ' + s.max_choices, 'error');
            return;
          } else current.push(o.id);
        } else {
          current = [o.id];
        }
        buttons.forEach(function (b) {
          b.el.setAttribute('aria-checked', current.indexOf(b.id) >= 0 ? 'true' : 'false');
        });
        if (navigator.vibrate) {
          try { navigator.vibrate(8); } catch (e) { /* ignore */ }
        }
        if (onChange) onChange(current.slice());
      });
      buttons.push({ id: o.id, el: btn });
      list.appendChild(btn);
    });
    return list;
  }

  GM.Poll = { hint: hint, hintRow: hintRow, screenHead: screenHead, options: options, CHECK: CHECK };
})();
