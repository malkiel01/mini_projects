/**
 * קול מרוקו — 🎨 עורך (פרוסה V11): מנוע הקשירה של העורך — אותה סמנטיקה כמו js/kol-data.js של הכלי (02 §8.2, §8.3).
 *
 * בקנבס סקריפטים אינם רצים (sandbox בלי allow-scripts), ולכן את הכרטיסים מצייר כאן העורך, מאותה תבנית ומאותם נתונים.
 * הפונקציות כאן הן העתק של kol-data.js (lookup / fill / holds / bind / eachLayer / build / sorted / סינון וחיפוש), ונבדקות
 * מולו בבדיקה אחת (tests/editor/bind-parity.mjs): אותו פריט ואותה תבנית ← אותו DOM בשני המימושים. שינוי באחד — גם בשני.
 *
 * תוספת של העורך בלבד: hook.copy(copy, orig) — כל עותק של אלמנט (התבנית כולה, וכל חזרה של data-kol-each) מדווח, כדי שהעורך
 * ימפה כל חלק של כרטיס מצויר לצומת שבתבנית. ההוק אינו משנה את ה-DOM שנוצר.
 *
 * אין כאן innerHTML מנתונים מחוץ ל-data-kol-html (שם — בדיוק כמו kol-data.js; בקנבס אין סקריפטים, ובעמוד — זה הקובץ שלו).
 */
(function (g) {
  'use strict';

  function isEmpty(value) {
    return value == null || value === '' || value === false || value === 0 || (Array.isArray(value) && value.length === 0);
  }

  /** ערך של שדה. scope — שכבות: הפריט, ובתוך data-kol-each גם הערך הנוכחי. "." · "a.b" · "topics.count". */
  function lookup(scope, path) {
    path = String(path).trim();
    var top = scope[scope.length - 1];
    if (path === '.') return top && typeof top === 'object' && 'value' in top ? top.value : top;
    var parts = path.split('.');
    for (var i = scope.length - 1; i >= 0; i--) {
      var layer = scope[i];
      if (layer && typeof layer === 'object' && parts[0] in layer) {
        var value = layer[parts[0]];
        for (var k = 1; k < parts.length && value != null; k++) {
          value = parts[k] === 'count' && Array.isArray(value) ? value.length : value[parts[k]];
        }
        return value;
      }
    }
    return undefined;
  }

  function fill(scope, spec) {
    if (spec.indexOf('{') === -1) {
      var value = lookup(scope, spec);
      return Array.isArray(value) ? value.join(' ') : (value == null ? '' : String(value));
    }
    return spec.replace(/\{([^{}]+)\}/g, function (whole, path) {
      var value = lookup(scope, path);
      return value == null ? '' : String(value);
    });
  }

  function holds(scope, condition) {
    condition = String(condition).trim();
    var match = /^(.*?)(!=|=)(.*)$/.exec(condition);
    if (match) {
      var equal = String(lookup(scope, match[1])) === match[3].trim();
      return match[2] === '=' ? equal : !equal;
    }
    if (condition.charAt(0) === '!') return isEmpty(lookup(scope, condition.slice(1)));
    return !isEmpty(lookup(scope, condition));
  }

  /** הצורה הכללית (V11): data-kol-class="isActive: topics; is-single: topics.count=1" ← [[שם, תנאי], …]. */
  function classPairs(spec) {
    var out = [], parts = String(spec).split(';');
    for (var i = 0; i < parts.length; i++) {
      var at = parts[i].indexOf(':');
      if (at < 1) continue;
      var name = parts[i].slice(0, at).trim(), cond = parts[i].slice(at + 1).trim();
      if (name && cond && !/\s/.test(name)) out.push([name, cond]);
    }
    return out;
  }

  function bind(element, scope, state, hook) {
    var each = element.getAttribute('data-kol-each');
    if (each !== null) {
      var values = lookup(scope, each);
      values = Array.isArray(values) ? values : [];
      for (var i = 0; i < values.length; i++) {
        var copy = element.cloneNode(true);
        if (hook && hook.copy) hook.copy(copy, element);
        copy.removeAttribute('data-kol-each');
        element.parentNode.insertBefore(copy, element);
        bind(copy, scope.concat([eachLayer(state, each, values[i])]), state, hook);
      }
      element.parentNode.removeChild(element);
      return false;
    }
    var condition = element.getAttribute('data-kol-if');
    if (condition !== null && !holds(scope, condition)) {
      element.parentNode.removeChild(element);
      return false;
    }
    var attributes = Array.prototype.slice.call(element.attributes);
    for (var a = 0; a < attributes.length; a++) {
      var name = attributes[a].name, spec = attributes[a].value;
      if (name.indexOf('data-kol-attr-') === 0) element.setAttribute(name.slice(14), fill(scope, spec));
      else if (name === 'data-kol-class') { var pairs = classPairs(spec); for (var p = 0; p < pairs.length; p++) if (holds(scope, pairs[p][1])) element.classList.add(pairs[p][0]); }
      else if (name.indexOf('data-kol-class-') === 0 && holds(scope, spec)) element.classList.add(name.slice(15));
    }
    var html = element.getAttribute('data-kol-html');
    var text = element.getAttribute('data-kol-text');
    if (html !== null) { element.innerHTML = fill(scope, html); return true; }
    if (text !== null) { element.textContent = fill(scope, text); return true; }
    var children = Array.prototype.slice.call(element.children);
    for (var c = 0; c < children.length; c++) bind(children[c], scope, state, hook);
    return true;
  }

  function eachLayer(state, field, value) {
    if (value && typeof value === 'object') return value;
    var known = state && state.labels[field] && state.labels[field][value];
    return { value: value, id: value, label: known ? known.label : value };
  }

  /** עותק מלא של תבנית לפריט אחד. hook.copy(עותק, מקור) — לכל אלמנט שורש של התבנית (העורך ממפה ממנו את כל העץ). */
  function build(template, scope, state, hook) {
    var doc = template.ownerDocument;
    var holder = doc.createElement('div');
    var content = template.content.cloneNode(true);
    if (hook && hook.copy) {
      var src = template.content.children, dst = content.children;
      for (var s = 0; s < src.length && s < dst.length; s++) hook.copy(dst[s], src[s]);
    }
    holder.appendChild(content);
    var roots = Array.prototype.slice.call(holder.children);
    for (var i = 0; i < roots.length; i++) bind(roots[i], scope, state, hook);
    return Array.prototype.slice.call(holder.children);
  }

  function templateOf(container) {
    for (var i = 0; i < container.children.length; i++) {
      if (container.children[i].tagName === 'TEMPLATE' && !container.children[i].hasAttribute('data-kol-canvas')) return container.children[i];
    }
    return null;
  }

  // ── רשימה: תוויות, מיון, חיפוש, סינון ───────────────────

  /** state.labels מקובץ הנתונים: כל מפתח שהוא רשימה של { id, label } (למשל topics). */
  function labelsOf(data) {
    var labels = {};
    if (data && !Array.isArray(data)) {
      Object.keys(data).forEach(function (key) {
        if (key === 'items' || !Array.isArray(data[key])) return;
        var table = {};
        data[key].forEach(function (row) { if (row && row.id !== undefined) table[row.id] = row; });
        labels[key] = table;
      });
    }
    return labels;
  }
  function itemsOf(data) { return Array.isArray(data) ? data : ((data && data.items) || []); }

  // ניקוד וטעמים (U+0591–U+05C7), תנועות ערביות (U+064B–U+065F, U+0670), ותווי כיוון ורוחב-אפס — לא נספרים בהשוואה.
  // כך "שָׁלוֹם" נמצא ב"שלום" ולהפך. רק לחיפוש: התצוגה והמיון לא עוברים כאן.
  var MARKS = /[\u0591-\u05C7\u064B-\u065F\u0670\u00AD\u200B-\u200F\u202A-\u202E\u2060-\u2069\uFEFF]/g;

  /** טקסט לחיפוש: בלי תגיות, אותיות קטנות, בלי ניקוד, רווח אחד. decode — פענוח ישויות (בדפדפן: textarea, כמו kol-data.js). */
  function plain(value, decode) {
    var text = String(value == null ? '' : value);
    if (text.indexOf('<') !== -1) text = text.replace(/<[^>]*>/g, ' ');
    if (text.indexOf('&') !== -1 && decode) text = decode(text);
    return text.replace(MARKS, '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  // (S2 · kol-data.js 1.6.0) האות הראשונה — כמו firstLetter של kol-data.js.
  var FINALS = { '\u05DA': '\u05DB', '\u05DD': '\u05DE', '\u05DF': '\u05E0', '\u05E3': '\u05E4', '\u05E5': '\u05E6' };
  var LETTER;
  try { LETTER = new RegExp('\\p{L}', 'u'); }
  catch (e) { LETTER = /[A-Za-z\u00C0-\u024F\u0370-\u03FF\u0400-\u04FF\u05D0-\u05EA\u0620-\u064A\u0671-\u06D3]/; }

  function firstLetter(value) {
    var text = String(value == null ? '' : value);
    if (text.indexOf('<') !== -1) text = text.replace(/<[^>]*>/g, ' ');
    if (text.normalize) text = text.normalize('NFKD');
    text = text.replace(MARKS, '').replace(/[\u0300-\u036F]/g, '');
    for (var i = 0; i < text.length; i++) {
      var ch = text.charAt(i);
      if (/[\uD800-\uDBFF]/.test(ch)) { ch = text.substr(i, 2); i++; }
      if (!LETTER.test(ch)) continue;
      var up = ch.toUpperCase();
      return FINALS[ch] || (up.length === ch.length ? up : ch);
    }
    return '';
  }

  /** הערכים של פריט לפי מפתח סינון: "topics" ← הערכים; "word@letter" ← [האות הראשונה]. */
  function filterValues(item, key) {
    var at = key.indexOf('@');
    if (at !== -1 && key.slice(at + 1) === 'letter') { var l = firstLetter(item[key.slice(0, at)]); return l ? [l] : []; }
    return [].concat(item[key] == null ? [] : item[key]);
  }
  function passes(item, key, wanted) {
    if (!key || wanted == null || wanted === '') return true;
    if (key.indexOf('@') !== -1) return filterValues(item, key).indexOf(wanted) !== -1;
    var value = item[key];
    return Array.isArray(value) ? value.indexOf(wanted) !== -1 : String(value) === String(wanted);
  }

  function haystack(state, item, fields, decode) {
    return fields.split(/\s+/).filter(Boolean).map(function (field) {
      var value = item[field];
      if (Array.isArray(value)) {
        return value.map(function (v) {
          var known = state.labels[field] && state.labels[field][v];
          return plain(known ? known.label : v, decode);
        }).join(' ');
      }
      return plain(value, decode);
    }).join(' ');
  }

  var collators = {};
  function collator(lang) {
    lang = String(lang || 'he').trim() || 'he';
    if (!collators[lang]) {
      try { collators[lang] = new Intl.Collator(lang, { numeric: true, sensitivity: 'base' }); }
      catch (e) { collators[lang] = new Intl.Collator('he', { numeric: true, sensitivity: 'base' }); }
    }
    return collators[lang];
  }

  function sorted(items, how, lang) {
    if (!how || how === 'manual') return items;
    var down = how.charAt(0) === '-', field = down ? how.slice(1) : how;
    var copy = items.slice(), col = collator(lang);
    copy.sort(function (a, b) {
      var x = a[field], y = b[field];
      var order = typeof x === 'number' && typeof y === 'number' ? x - y : col.compare(String(x == null ? '' : x), String(y == null ? '' : y));
      return down ? -order : order;
    });
    return copy;
  }

  /** מי מתאים: { query, fields, filters: {מפתח: ערך}, filterField, filterValue } — כמו refresh של kol-data.js (סינונים מצטברים). */
  function matched(state, opt, decode) {
    var query = plain(opt.query || '', decode), fields = opt.fields || 'word', active = {};
    for (var key in (opt.filters || {})) if (Object.prototype.hasOwnProperty.call(opt.filters, key)) active[key] = opt.filters[key];
    if (opt.filterField) active[opt.filterField] = opt.filterValue;
    var keys = Object.keys(active);
    return state.items.filter(function (item) {
      for (var k = 0; k < keys.length; k++) if (!passes(item, keys[k], active[keys[k]])) return false;
      return query === '' || haystack(state, item, fields, decode).indexOf(query) !== -1;
    });
  }

  /** שכבות כפתורי הסינון: ערך לכל ערך שיש לו פריטים, בסדר טבלת התוויות; אותיות — לפי ה-Collator של lang (כמו buildFilter). */
  function filterLayers(state, field, lang) {
    var counts = {}, order = [];
    state.items.forEach(function (item) {
      filterValues(item, field).forEach(function (value) {
        if (counts[value] === undefined) { counts[value] = 0; order.push(value); }
        counts[value]++;
      });
    });
    var known = state.labels[field];
    if (known) order = Object.keys(known).filter(function (id) { return counts[id]; });
    else if (field.indexOf('@') !== -1) { var col = collator(lang); order.sort(function (a, b) { return col.compare(a, b); }); }
    return order.map(function (value) {
      return { id: value, value: value, label: known && known[value] ? known[value].label : value, count: counts[value] };
    });
  }

  /** הכפתור הפעיל בסינון (כמו drawAround): aria-pressed, ומחלקת data-kol-active-class. buttons — כל [data-kol-filter-value] במכל. */
  function markActive(container, buttons, value) {
    var active = container.getAttribute('data-kol-active-class');
    for (var i = 0; i < buttons.length; i++) {
      var on = buttons[i].getAttribute('data-kol-filter-value') === value;
      buttons[i].setAttribute('aria-pressed', on ? 'true' : 'false');
      if (active) buttons[i].classList.toggle(active, on);
    }
  }

  /** כל התכונות שהמנוע מכיר — לפאנל "נתונים" ולבדיקות. */
  var ATTRS = ['data-kol-text', 'data-kol-html', 'data-kol-if', 'data-kol-each', 'data-kol-class', 'data-kol-play'];
  /** האם האלמנט קשור לשדה (ערך משלו במילה): text / html / attr-* / each / play. */
  function boundOf(el) {
    if (!el || !el.getAttribute) return null;
    var t = el.getAttribute('data-kol-text'), hh = el.getAttribute('data-kol-html');
    if (hh !== null) return { kind: 'html', spec: hh };
    if (t !== null) return { kind: 'text', spec: t };
    return null;
  }
  /** השדות שבתבנית (בלי סוגריים מסולסלים): "השמע את {word}" ← ["word"]. */
  function fieldsIn(spec) {
    spec = String(spec || '');
    if (spec.indexOf('{') === -1) return spec.trim() && spec.trim() !== '.' ? [spec.trim().split('.')[0]] : [];
    var out = [], m, re = /\{([^{}]+)\}/g;
    while ((m = re.exec(spec))) { var f = m[1].trim().split('.')[0]; if (f && f !== '.' && out.indexOf(f) < 0) out.push(f); }
    return out;
  }

  g.KolBind = {
    isEmpty: isEmpty, lookup: lookup, fill: fill, holds: holds, classPairs: classPairs, bind: bind, eachLayer: eachLayer, build: build, templateOf: templateOf,
    labelsOf: labelsOf, itemsOf: itemsOf, plain: plain, firstLetter: firstLetter, filterValues: filterValues, haystack: haystack, sorted: sorted, matched: matched, filterLayers: filterLayers, markActive: markActive,
    ATTRS: ATTRS, boundOf: boundOf, fieldsIn: fieldsIn
  };
})(typeof window !== 'undefined' ? window : globalThis);
