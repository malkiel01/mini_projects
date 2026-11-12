/*
 * kol-data.js — קשירת נתונים לעמוד (קובץ מערכת של "קול מרוקו", גרסה 1.3.0)
 *
 * הקובץ הזה שלך: אפשר לקרוא אותו, לשנות אותו ואפילו למחוק אותו. הוא מחבר בין הנתונים (data/words.json)
 * לבין ה-HTML של העמוד, לפי תכונות data-kol-* שכתובות על האלמנטים עצמם:
 *
 *   data-kol-list="words"            המכל שבו מוצגת הרשימה. הילד <template> שלו הוא תבנית הפריט.
 *   data-kol-sort="word"             מיון: שם שדה, "-שדה" ליורד, או "manual" (סדר הקובץ = הסדר שנקבע בגרירה בטבלה).
 *   data-kol-sort-lang="he"          השפה של המיון לפי א"ב (Intl.Collator): "he", "ar", "fr"… ברירת מחדל: he.
 *   data-kol-page="60"               כמה פריטים בכל טעינה. בלי התכונה — הכול מוצג.
 *   data-kol-text="meaning"          תוכן הטקסט של האלמנט ← השדה.   אפשר גם תבנית: "#{id}"
 *   data-kol-html="meaning"          כמו text, אבל כ-HTML.
 *   data-kol-attr-src="audio"        תכונה כלשהי ← השדה.            אפשר גם תבנית: "השמע את {word}"
 *   data-kol-if="audio" / "!audio"   האלמנט נשאר רק אם לשדה יש / אין ערך. גם "שדה=ערך", "topics.count=1".
 *   data-kol-class-NAME="תנאי"       המחלקה NAME נוספת לאלמנט כשהתנאי מתקיים.
 *   data-kol-each="topics"           חזרה על האלמנט לכל ערך בשדה-רשימה. בפנים: {label}, {id}, או "." לערך עצמו.
 *   data-kol-search="words"          <input> — חיפוש חי. data-kol-search-fields="word meaning" — באילו שדות.
 *   data-kol-search-scope="words"    תיבת סימון: כשהיא מסומנת, החיפוש עובר לשדות שב-data-kol-search-fields שלה.
 *   data-kol-filter="words:topics"   מכל של כפתורי סינון לפי שדה. הילד <template> — תבנית הכפתור ({label}, {id}, {count}).
 *                                    כפתור קבוע (למשל "הכל"): data-kol-filter-value="" בתוך המכל.
 *   data-kol-count="words"           מונה. הנוסח ב-data-kol-count-text, למשל "{shown} מתוך {total}".
 *   data-kol-more="words"            כפתור "טען עוד".
 *   data-kol-empty="words"           מוצג רק כשאין תוצאות.
 *   data-kol-play                    כפתור בתוך תבנית הפריט: משמיע את ההקלטה של הפריט (השדה audio).
 *   data-kol-open="feedback"         כפתור בתוך תבנית הפריט: פותח את החלון data-kol-dialog="feedback".
 *   data-kol-close                   סוגר את החלון שהוא בתוכו.
 *   data-kol-form="feedback"         טופס בלי action: נשלח לתיבת הפניות בכלי (הכתובת — <meta name="kol-form-endpoint">,
 *                                    שהפרסום כותב). נשלחים כל השדות עם name. טופס עם action משלו — הדפדפן שולח לשם, וכאן לא נוגעים.
 *   data-kol-form-done               אלמנט בתוך הטופס (או בתוך החלון שלו): מוצג אחרי שליחה שהצליחה. כתבו בו מה שתרצו.
 *   data-kol-form-error              כמוהו — מוצג כשהשליחה נכשלה. בזמן השליחה: הכפתור מושבת, ו-aria-busy על הטופס.
 *   data-kol-trap                    שדה מלכודת (מוסתר מבני אדם): בוט שממלא אותו — נענה "התקבל", והפנייה אינה נשמרת.
 *                                    (הצורה הישנה — data-kol-form-done="טקסט" על ה-<form> ו-data-kol-form-status — עובדת גם היא.)
 *   data-kol-active-class="is-on"    איזו מחלקה לשים על כפתור סינון / השמעה פעיל (בנוסף ל-aria-pressed).
 *   data-kol-prerendered             (נכתב בפרסום) המכל כבר מכיל את הרשימה כ-HTML, למי שאין לו JS ולמנועי חיפוש.
 *                                    כל פריט כתוב מסומן data-kol-pre; בציור הראשון הם מוסרים והרשימה נבנית מחדש — בלי
 *                                    כפילות. שאר מה שכתבתם בתוך המכל נשאר.
 *
 * שלושה כללים שהקובץ שומר עליהם:
 *   1. אין כאן אף מילה שהגולש רואה. כל כיתוב הוא תוכן או תכונה של אלמנט בקובץ העמוד.
 *   2. הקובץ אינו נשען על מבנה העמוד: כל אלמנט נמצא לפי התכונה שלו בלבד. אפשר להזיז, לעטוף ולמחוק.
 *   3. בלי אחסון בדפדפן (localStorage וכדומה), והנתונים נטענים בכתובת יחסית: data/<שם הרשימה>.json
 *
 * כשהעמוד מוצג כחלון בתוך האתר (אופן "חלון" או "פס האתר"), הקובץ שולח לאתר שלוש הודעות בלבד, וזה כל המידע שיוצא:
 *   {kol:"height", h} — גובה העמוד (כדי שהחלון יהיה בגובה התוכן, בלי פס גלילה כפול)
 *   {kol:"title",  t} — הכותרת (<title>), לכותרת של הלשונית
 *   {kol:"path",   p} — הדף שמוצג עכשיו (למשל about/index.html), לכתובת שבשורת הכתובת
 * כשהעמוד הוא כל המסך — לא נשלח דבר. מחקתם את החלק הזה? החלון פשוט לא יתעדכן.
 */
(function () {
  'use strict';

  var FIRST_CHUNK = 60;      // כמה כרטיסים נבנים מיד, לפני שהדפדפן מצייר
  var NEXT_CHUNK = 200;      // וכמה בכל סיבוב אחר כך, כדי שהעמוד לא ייתקע
  var lists = {};            // שם הרשימה ← המצב שלה

  // ── כלים קטנים ────────────────────────────────────────────

  function all(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  /** טקסט לחיפוש: בלי תגיות HTML, באותיות קטנות, רווח אחד בין מילים. */
  function plain(value) {
    var text = String(value == null ? '' : value);
    if (text.indexOf('<') !== -1) text = text.replace(/<[^>]*>/g, ' ');
    if (text.indexOf('&') !== -1) {
      var box = document.createElement('textarea');
      box.innerHTML = text;
      text = box.value;
    }
    return text.toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function isEmpty(value) {
    return value == null || value === '' || value === false || value === 0 || (Array.isArray(value) && value.length === 0);
  }

  /**
   * ערך של שדה. scope הוא רשימה של "שכבות" — הפריט, ובתוך data-kol-each גם הערך הנוכחי.
   * "." — הערך הנוכחי;  "a.b" — שדה בתוך שדה;  "topics.count" — כמה ערכים יש ברשימה.
   */
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

  /** "word" ← הערך של השדה;  "השמע את {word}" ← הטקסט, עם הערכים במקומם. */
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

  /** תנאי: "audio", "!audio", "kind=song", "kind!=song", "topics.count=1". */
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

  // ── בניית אלמנטים מתבנית ──────────────────────────────────

  /** ממלא אלמנט אחד (וכל מה שבתוכו) לפי תכונות הקשירה. מחזיר false אם האלמנט הוסר (data-kol-if). */
  function bind(element, scope, state) {
    // 1. חזרה: האלמנט משוכפל לכל ערך ברשימה, והמקור מוסר
    var each = element.getAttribute('data-kol-each');
    if (each !== null) {
      var values = lookup(scope, each);
      values = Array.isArray(values) ? values : [];
      for (var i = 0; i < values.length; i++) {
        var copy = element.cloneNode(true);
        copy.removeAttribute('data-kol-each');
        element.parentNode.insertBefore(copy, element);
        bind(copy, scope.concat([eachLayer(state, each, values[i])]), state);
      }
      element.parentNode.removeChild(element);
      return false;
    }
    // 2. תנאי
    var condition = element.getAttribute('data-kol-if');
    if (condition !== null && !holds(scope, condition)) {
      element.parentNode.removeChild(element);
      return false;
    }
    // 3. תכונות ומחלקות
    var attributes = Array.prototype.slice.call(element.attributes);
    for (var a = 0; a < attributes.length; a++) {
      var name = attributes[a].name, spec = attributes[a].value;
      if (name.indexOf('data-kol-attr-') === 0) element.setAttribute(name.slice(14), fill(scope, spec));
      else if (name.indexOf('data-kol-class-') === 0 && holds(scope, spec)) element.classList.add(name.slice(15));
    }
    // 4. תוכן
    var html = element.getAttribute('data-kol-html');
    var text = element.getAttribute('data-kol-text');
    if (html !== null) { element.innerHTML = fill(scope, html); return true; }
    if (text !== null) { element.textContent = fill(scope, text); return true; }
    // 5. הילדים (עותק של הרשימה — היא משתנה תוך כדי)
    var children = Array.prototype.slice.call(element.children);
    for (var c = 0; c < children.length; c++) bind(children[c], scope, state);
    return true;
  }

  /** השכבה של ערך בתוך data-kol-each. ערך שהוא מזהה מטבלת הנושאים מקבל גם את התווית שלו. */
  function eachLayer(state, field, value) {
    if (value && typeof value === 'object') return value;
    var known = state && state.labels[field] && state.labels[field][value];
    return { value: value, id: value, label: known ? known.label : value };
  }

  /** עותק מלא של תבנית, לפריט אחד. מחזיר את האלמנטים שנוצרו. */
  function build(template, scope, state) {
    var holder = document.createElement('div');
    holder.appendChild(template.content.cloneNode(true));
    var roots = Array.prototype.slice.call(holder.children);
    for (var i = 0; i < roots.length; i++) bind(roots[i], scope, state);
    return Array.prototype.slice.call(holder.children);
  }

  function templateOf(container) {
    for (var i = 0; i < container.children.length; i++) {
      if (container.children[i].tagName === 'TEMPLATE') return container.children[i];
    }
    return null;
  }

  // ── רשימה: סינון, חיפוש, מיון, הצגה ───────────────────────

  function newState(name) {
    return {
      name: name, items: [], labels: {}, loaded: false,
      query: '', filterField: '', filterValue: '', limit: 0, matched: []
    };
  }

  /** הטקסט שבו מחפשים אצל פריט, לפי רשימת שדות. נשמר על הפריט, כדי לא לחשב שוב בכל הקשה. */
  function haystack(state, item, fields) {
    var cache = item.__kolSearch || (item.__kolSearch = {});
    if (cache[fields] === undefined) {
      cache[fields] = fields.split(/\s+/).filter(Boolean).map(function (field) {
        var value = item[field];
        if (Array.isArray(value)) {
          return value.map(function (v) {
            var known = state.labels[field] && state.labels[field][v];
            return plain(known ? known.label : v);
          }).join(' ');
        }
        return plain(value);
      }).join(' ');
    }
    return cache[fields];
  }

  /** באילו שדות מחפשים עכשיו: של תיבת "הרחב" מסומנת, ואם אין — של שדה החיפוש. */
  function searchFields(state) {
    var scopes = all('[data-kol-search-scope="' + state.name + '"]');
    for (var i = 0; i < scopes.length; i++) {
      if (scopes[i].checked) return scopes[i].getAttribute('data-kol-search-fields') || 'word';
    }
    var input = document.querySelector('[data-kol-search="' + state.name + '"]');
    return (input && input.getAttribute('data-kol-search-fields')) || 'word';
  }

  var collators = {};
  function collator(lang) {
    lang = String(lang || 'he').trim() || 'he';
    if (!collators[lang]) {
      try { collators[lang] = new Intl.Collator(lang, { numeric: true, sensitivity: 'base' }); }
      catch (e) { collators[lang] = new Intl.Collator('he', { numeric: true, sensitivity: 'base' }); }   // שפה לא תקינה — עברית
    }
    return collators[lang];
  }

  /** "manual" (או בלי התכונה) — סדר הקובץ כמו שהוא. אחרת — לפי שדה, עם Intl.Collator בשפה של data-kol-sort-lang. */
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

  /** מחשב מי מתאים לחיפוש ולסינון, ומציג מחדש את כל מה שקשור לרשימה. */
  function refresh(state, keepLimit) {
    if (!state.loaded) return;
    var query = plain(state.query), fields = searchFields(state);
    state.matched = state.items.filter(function (item) {
      if (state.filterField && state.filterValue !== '') {
        var value = item[state.filterField];
        var inFilter = Array.isArray(value) ? value.indexOf(state.filterValue) !== -1 : String(value) === state.filterValue;
        if (!inFilter) return false;
      }
      return query === '' || haystack(state, item, fields).indexOf(query) !== -1;
    });
    if (!keepLimit) state.limit = 0;
    all('[data-kol-list="' + state.name + '"]').forEach(function (container) { draw(state, container); });
    drawAround(state);
  }

  /** מציג את הפריטים במכל אחד. הכרטיס של כל פריט נבנה פעם אחת ונשמר — חיפוש רק מסדר מחדש. */
  function draw(state, container) {
    var template = templateOf(container);
    if (!template) return;
    if (container.hasAttribute('data-kol-prerendered') && !container.__kolClean) {
      container.__kolClean = true;                                         // הרשימה שנכתבה מראש — מתחלפת בזו החיה
      for (var c = container.children.length - 1; c >= 0; c--) {
        if (container.children[c].hasAttribute('data-kol-pre')) container.removeChild(container.children[c]);
      }
    }
    var token = container.__kolToken = (container.__kolToken || 0) + 1;
    var page = parseInt(container.getAttribute('data-kol-page'), 10) || 0;
    if (page && !state.limit) state.limit = page;
    var items = sorted(state.matched, container.getAttribute('data-kol-sort'), container.getAttribute('data-kol-sort-lang'));
    if (page) items = items.slice(0, state.limit);
    container.__kolShown = items.length;

    (container.__kolNodes || []).forEach(function (node) { if (node.parentNode === container) container.removeChild(node); });
    container.__kolNodes = [];
    var key = '__kolNodes' + (container.__kolId || (container.__kolId = ++draw.counter));

    function addChunk(from, size) {
      if (token !== container.__kolToken) return;                       // בינתיים התחיל ציור חדש — זה כבר לא רלוונטי
      var fragment = document.createDocumentFragment();
      var to = Math.min(items.length, from + size);
      for (var i = from; i < to; i++) {
        var item = items[i];
        var nodes = item[key];
        if (!nodes) {
          nodes = item[key] = build(template, [item], state);
          for (var n = 0; n < nodes.length; n++) nodes[n].__kolItem = item;
        }
        for (var m = 0; m < nodes.length; m++) { fragment.appendChild(nodes[m]); container.__kolNodes.push(nodes[m]); }
      }
      container.appendChild(fragment);
      if (to < items.length) setTimeout(function () { addChunk(to, NEXT_CHUNK); }, 0);
      else container.setAttribute('data-kol-ready', '');
    }
    container.removeAttribute('data-kol-ready');
    addChunk(0, FIRST_CHUNK);
  }
  draw.counter = 0;

  /** המונה, "אין תוצאות", "טען עוד" וכפתורי הסינון. כל אחד מהם יכול לא להיות בעמוד. */
  function drawAround(state) {
    var total = state.items.length, shown = state.matched.length;
    all('[data-kol-count="' + state.name + '"]').forEach(function (element) {
      var spec = element.getAttribute('data-kol-count-text') || '{shown}';
      element.textContent = fill([{ shown: shown, total: total }], spec);
    });
    all('[data-kol-empty="' + state.name + '"]').forEach(function (element) {
      element.hidden = shown !== 0;
      if (shown === 0 && element.style.display === 'none') element.style.display = '';
    });
    all('[data-kol-more="' + state.name + '"]').forEach(function (button) {
      button.hidden = !state.limit || state.limit >= shown;
    });
    all('[data-kol-filter^="' + state.name + ':"]').forEach(function (container) {
      var active = container.getAttribute('data-kol-active-class');
      all('[data-kol-filter-value]', container).forEach(function (button) {
        var on = button.getAttribute('data-kol-filter-value') === state.filterValue;
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (active) button.classList.toggle(active, on);
      });
    });
  }

  /** בונה את כפתורי הסינון מהתבנית שבמכל: כפתור לכל ערך שיש לו פריטים. */
  function buildFilter(state, container) {
    var field = container.getAttribute('data-kol-filter').split(':')[1] || '';
    var template = templateOf(container);
    if (!field || !template) return;
    var counts = {}, order = [];
    state.items.forEach(function (item) {
      [].concat(item[field] == null ? [] : item[field]).forEach(function (value) {
        if (counts[value] === undefined) { counts[value] = 0; order.push(value); }
        counts[value]++;
      });
    });
    var known = state.labels[field];
    if (known) order = Object.keys(known).filter(function (id) { return counts[id]; });   // הסדר של טבלת הנושאים
    (container.__kolNodes || []).forEach(function (node) { if (node.parentNode) node.parentNode.removeChild(node); });
    container.__kolNodes = [];
    order.forEach(function (value) {
      var layer = { id: value, value: value, label: known && known[value] ? known[value].label : value, count: counts[value] };
      build(template, [layer], state).forEach(function (node) {
        if (!node.hasAttribute('data-kol-filter-value')) node.setAttribute('data-kol-filter-value', value);
        container.appendChild(node);
        container.__kolNodes.push(node);
      });
    });
  }

  // ── טעינת הנתונים ─────────────────────────────────────────

  function load(name) {
    var state = lists[name] = newState(name);
    var holder = document.querySelector('[data-kol-list="' + name + '"][data-kol-src]');
    var url = holder ? holder.getAttribute('data-kol-src') : 'data/' + name + '.json';
    return fetch(url).then(function (response) {
      if (!response.ok) throw new Error(url + ' — ' + response.status);
      return response.json();
    }).then(function (data) {
      state.items = Array.isArray(data) ? data : (data.items || []);
      // טבלאות תוויות: כל מפתח בקובץ שהוא רשימה של { id, label } — למשל "topics"
      if (!Array.isArray(data)) {
        Object.keys(data).forEach(function (key) {
          if (key === 'items' || !Array.isArray(data[key])) return;
          var table = {};
          data[key].forEach(function (row) { if (row && row.id !== undefined) table[row.id] = row; });
          state.labels[key] = table;
        });
      }
      state.loaded = true;
      all('[data-kol-filter^="' + name + ':"]').forEach(function (container) {
        state.filterField = state.filterField || container.getAttribute('data-kol-filter').split(':')[1] || '';
        buildFilter(state, container);
      });
      var input = document.querySelector('[data-kol-search="' + name + '"]');
      if (input) state.query = input.value || '';
      refresh(state);
    }).catch(function (error) {
      console.error('kol-data: ' + name + ' — ' + error.message);   // הרשימה לא נטענה
    });
  }

  // ── נגן: הקלטה אחת בכל רגע ────────────────────────────────

  var audio = null, playingButton = null;

  function itemOf(element) {
    for (var node = element; node; node = node.parentNode) if (node.__kolItem) return node.__kolItem;
    return null;
  }

  function markPlaying(button, on) {
    if (!button) return;
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
    var active = button.getAttribute('data-kol-active-class');
    if (active) button.classList.toggle(active, on);
  }

  function stopAudio() {
    if (audio) { audio.pause(); audio = null; }
    markPlaying(playingButton, false);
    playingButton = null;
  }

  function play(button) {
    var same = button === playingButton;
    stopAudio();
    if (same) return;                                          // לחיצה שנייה על אותו כפתור — עצירה
    var item = itemOf(button);
    var field = button.getAttribute('data-kol-play') || 'audio';
    var source = item ? item[field] : button.getAttribute('data-kol-play');
    if (!source) return;
    audio = new Audio(source);
    playingButton = button;
    markPlaying(button, true);
    audio.addEventListener('ended', stopAudio);
    audio.addEventListener('error', stopAudio);
    var started = audio.play();
    if (started && started.catch) started.catch(function () { stopAudio(); });
  }

  // ── חלון וטופס ────────────────────────────────────────────

  var lastFocus = null;

  function openDialog(name, item) {
    var dialog = document.querySelector('[data-kol-dialog="' + name + '"]');
    if (!dialog) return;
    if (item) {
      all('[data-kol-text]', dialog).forEach(function (element) { element.textContent = fill([item], element.getAttribute('data-kol-text')); });
      all('[data-kol-value]', dialog).forEach(function (element) { element.value = fill([item], element.getAttribute('data-kol-value')); });
    }
    all('[data-kol-form-status]', dialog).forEach(function (element) { element.textContent = ''; });
    all('[data-kol-form-done], [data-kol-form-error]', dialog).forEach(function (element) { if (!element.hasAttribute('data-kol-form')) element.hidden = true; });
    lastFocus = document.activeElement;
    dialog.hidden = false;
    var first = dialog.querySelector('input:not([type=hidden]):not([tabindex="-1"]), textarea, select, button');
    if (first) first.focus();
  }

  function closeDialog(dialog) {
    if (!dialog || dialog.hidden) return;
    dialog.hidden = true;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
    lastFocus = null;
  }

  // ── האזנה לאירועים: פעם אחת, על המסמך כולו ────────────────
  // כך אין חשיבות למקום של האלמנט בעמוד, וגם כרטיסים שנבנים מאוחר יותר עובדים.

  function closest(target, selector) {
    return target && target.closest ? target.closest(selector) : null;
  }

  document.addEventListener('click', function (event) {
    var button;
    if ((button = closest(event.target, '[data-kol-play]'))) { play(button); return; }
    if ((button = closest(event.target, '[data-kol-open]'))) { openDialog(button.getAttribute('data-kol-open'), itemOf(button)); return; }
    if ((button = closest(event.target, '[data-kol-close]'))) { closeDialog(closest(button, '[data-kol-dialog]')); return; }
    if ((button = closest(event.target, '[data-kol-filter-value]'))) {
      var container = closest(button, '[data-kol-filter]');
      if (!container) return;
      var parts = container.getAttribute('data-kol-filter').split(':');
      var state = lists[parts[0]];
      if (!state) return;
      state.filterField = parts[1] || '';
      state.filterValue = button.getAttribute('data-kol-filter-value');
      refresh(state);
      return;
    }
    if ((button = closest(event.target, '[data-kol-more]'))) {
      var more = lists[button.getAttribute('data-kol-more')];
      if (!more) return;
      var holder = document.querySelector('[data-kol-list="' + more.name + '"][data-kol-page]');
      more.limit += (holder && parseInt(holder.getAttribute('data-kol-page'), 10)) || FIRST_CHUNK;
      refresh(more, true);
    }
  });

  document.addEventListener('input', function (event) {
    var input = closest(event.target, '[data-kol-search]');
    if (!input) return;
    var state = lists[input.getAttribute('data-kol-search')];
    if (!state) return;
    state.query = input.value;
    refresh(state);
  });

  document.addEventListener('change', function (event) {
    var box = closest(event.target, '[data-kol-search-scope]');
    if (!box) return;
    var state = lists[box.getAttribute('data-kol-search-scope')];
    if (state) refresh(state);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    all('[data-kol-dialog]').forEach(closeDialog);
  });

  // ── שליחת טופס (1.2.0) ────────────────────────────────────

  var loadedAt = Date.now();
  // הבסיס של החבילה — התיקייה שבה js/kol-data.js; ממנו הנתיב היחסי של העמוד (_page)
  var script = document.currentScript && document.currentScript.src;
  var base = script ? script.replace(/[?#].*$/, '').replace(/js\/kol-data\.js$/, '') : '';

  function pagePath() {
    var here = location.href.replace(/[?#].*$/, '');
    if (base && here.indexOf(base) === 0) return here.slice(base.length) || 'index.html';
    return location.pathname.split('/').pop() || 'index.html';
  }

  /** אלמנטי ההודעה של הטופס: בתוכו, ואם אין — בתוך החלון שלו. */
  function messages(form, attr) {
    var found = all('[' + attr + ']', form);
    var dialog = closest(form, '[data-kol-dialog]');
    if (!found.length && dialog) found = all('[' + attr + ']', dialog);
    return found.filter(function (element) { return element !== form && !element.hasAttribute('data-kol-form'); });
  }

  function finish(form, ok) {
    form.removeAttribute('aria-busy');
    all('button, input[type=submit]', form).forEach(function (button) {
      if (button.hasAttribute('data-kol-busy')) { button.disabled = false; button.removeAttribute('data-kol-busy'); }
    });
    messages(form, 'data-kol-form-done').forEach(function (element) { element.hidden = !ok; });
    messages(form, 'data-kol-form-error').forEach(function (element) { element.hidden = ok; });
    // הצורה הישנה: הנוסח בתכונה של הטופס, בתוך data-kol-form-status
    var text = form.getAttribute(ok ? 'data-kol-form-done' : 'data-kol-form-error') || '';
    all('[data-kol-form-status]', form).forEach(function (element) { element.textContent = text; });
    if (ok) form.reset();
  }

  document.addEventListener('submit', function (event) {
    var form = closest(event.target, '[data-kol-form]');
    if (!form || form.hasAttribute('action')) return;             // טופס עם action משלו — הדפדפן שולח לשם
    event.preventDefault();
    if (form.getAttribute('aria-busy') === 'true') return;
    if (form.checkValidity && !form.checkValidity()) { if (form.reportValidity) form.reportValidity(); return; }
    var meta = document.querySelector('meta[name="kol-form-endpoint"]');
    var endpoint = meta && meta.getAttribute('content');
    if (!endpoint || !window.fetch || !window.FormData) { finish(form, false); return; }
    var data = new FormData(form);
    var trap = '';
    all('[data-kol-trap]', form).forEach(function (element) {
      if (element.name) data.delete(element.name);
      trap += element.value || '';
    });
    data.set('_form', form.getAttribute('data-kol-form'));
    data.set('_t', String(Date.now() - loadedAt));
    data.set('_page', pagePath());
    if (trap) data.set('_trap', trap);
    form.setAttribute('aria-busy', 'true');
    all('button, input[type=submit]', form).forEach(function (button) {
      if ((button.type || 'submit') === 'submit' && !button.disabled) { button.disabled = true; button.setAttribute('data-kol-busy', ''); }
    });
    fetch(endpoint, { method: 'POST', body: data, credentials: 'omit', mode: 'cors' })
      .then(function (response) { return response.json(); })
      .then(function (answer) { finish(form, !!(answer && answer.ok)); })
      .catch(function () { finish(form, false); });
  });

  // ── התחלה ─────────────────────────────────────────────────

  function start() {
    var names = {};
    all('[data-kol-list]').forEach(function (element) { names[element.getAttribute('data-kol-list')] = true; });
    Object.keys(names).forEach(function (name) { if (name) load(name); });
  }

  // ── חלון בתוך האתר: גובה, כותרת ודף — רק כשיש מסמך מעל העמוד ──────

  var root = (function () {
    var me = document.currentScript && document.currentScript.src;
    var m = me && /^(.*\/)js\/kol-data\.js(?:[?#].*)?$/.exec(me);
    try { return m ? new URL(m[1]).pathname : null; } catch (e) { return null; }
  })();

  function tell(message) {
    try { window.parent.postMessage(message, '*'); } catch (e) { /* אין מסמך מעל */ }
  }

  function framed() {
    var sent = {};
    var queued = false;
    function height() {
      var html = document.documentElement;
      var h = html.getBoundingClientRect().height;
      if (html.scrollHeight > html.clientHeight + 1) h = Math.max(h, html.scrollHeight);   // תוכן שגולש מעבר לחלון
      return Math.min(50000, Math.ceil(h));
    }
    function sendHeight() {
      queued = false;
      var h = height();
      if (h !== sent.h) { sent.h = h; tell({ kol: 'height', h: h }); }
    }
    function queue() { if (!queued) { queued = true; (window.requestAnimationFrame || setTimeout)(sendHeight); } }
    function sendTitle() {
      var t = String(document.title || '').slice(0, 200);
      if (t !== sent.t) { sent.t = t; tell({ kol: 'title', t: t }); }
    }
    function sendPath() {
      if (!root || location.pathname.indexOf(root) !== 0) return;
      var p;
      try { p = decodeURIComponent(location.pathname.slice(root.length)); } catch (e) { return; }
      if (p === '' || p.charAt(p.length - 1) === '/') p += 'index.html';
      tell({ kol: 'path', p: p });
    }
    if (window.ResizeObserver) {
      var watch = new ResizeObserver(queue);
      watch.observe(document.documentElement);
      if (document.body) watch.observe(document.body);
    }
    window.addEventListener('load', queue);
    window.addEventListener('resize', queue);
    if (window.MutationObserver) {
      new MutationObserver(sendTitle).observe(document.head || document.documentElement, { childList: true, subtree: true, characterData: true });
    }
    window.addEventListener('popstate', sendPath);
    sendPath();
    sendTitle();
    queue();
  }

  var inFrame = false;
  try { inFrame = window.parent !== window; } catch (e) { inFrame = true; }

  // לבדיקות, ולמי שרוצה להפעיל מהקוד שלו: KolData.lists.words, KolData.refresh('words')
  window.KolData = {
    version: '1.3.0',
    prerender: true,       // יודע להמשיך ממכל עם data-kol-prerendered (הבנייה לפרסום בודקת את זה)
    lists: lists,
    refresh: function (name) { if (lists[name]) refresh(lists[name]); },
    reload: start
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
  if (inFrame) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', framed);
    else framed();
  }
})();
