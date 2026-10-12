/* Poll voting UI — shared by the participant page and the editor's phone preview. */
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

  GM.Poll = { hint: hint, options: options, CHECK: CHECK };
})();
