/*
 * קול מרוקו — "🔁 מעבר" בלשונית 🚀 פרסום (פרוסה M3; docs/spec-v2/slices/M3-cutover.md §2).
 *
 * **המסך אינו מדליק דבר באתר.** הוא מציג את התנאים (lib/cutover.php מחשב — כאן רק מוצג), מקבל ראיה מהבעלים,
 * וכשהכול ✓ — מציג את ההוראה לבעל האתר ופותח מטלה. המפסק "העמוד החדש" — מוצג לקריאה בלבד.
 * מפרסם — רואה; בעלים — גם מוסיף ראיה, יוצר נקודת שחזור ומסמן "מוכן". ההרשאה נבדקת בשרת, לא כאן.
 *
 * נטען מ-assets/js/publish.js (שורה אחת): KolCutover.mount(el).
 */
(function () {
  'use strict';

  (function css() {
    if (document.querySelector('link[data-kol-cutover-css]')) return;
    var l = document.createElement('link');
    l.rel = 'stylesheet'; l.href = 'assets/css/cutover.css'; l.setAttribute('data-kol-cutover-css', '');
    document.head.appendChild(l);
  })();

  var MARK = { ok: ['✓', 'ok', 'עבר'], bad: ['✗', 'bad', 'חסר'], wait: ['⏳', 'warn', 'ממתין'] };
  var when = function (iso) { return iso ? new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Jerusalem' }) : ''; };
  var safeUrl = function (u) { return typeof u === 'string' && /^https?:\/\//i.test(u) ? u : null; };

  function mount(box) {
    box.classList.add('co');
    box.setAttribute('aria-labelledby', 'co-t');
    box.replaceChildren(h('p', { class: 'empty' }, 'טוען את תנאי המעבר…'));
    load(box);
    return box;
  }

  async function load(box, given) {
    var s = given;
    if (!s) { try { s = await api('cutover'); } catch (e) { box.replaceChildren(title(), h('p', { class: 'error' }, e.message)); return; } }
    draw(box, s);
  }

  function title() { return h('h2', { class: 'sec-title', id: 'co-t' }, '🔁 מעבר — העמוד החדש באתר'); }

  function draw(box, s) {
    var byKey = {};
    s.rows.forEach(function (r) { byKey[r.key] = r; });
    var okN = s.rows.filter(function (r) { return r.status === 'ok'; }).length;
    var redo = function (next) { load(box, next); };
    box.replaceChildren.apply(box, [
      title(),
      // ✓ שנפל — אדום, בראש המסך
      s.fell && s.fell.length ? h('div', { class: 'error co-fell', role: 'alert' }, h('b', {}, '⚠️ תנאי שהיה ✓ ונפל:'),
        h('ul', {}, s.fell.map(function (k) { var r = byKey[k]; return h('li', {}, r.title + ' — ' + r.detail + (r.fell_at ? ' (מ-' + when(r.fell_at) + ')' : '')); }))) : null,
      s.live ? liveBox(s) : null,
      h('p', { class: 'hint' }, 'הכלי אינו מדליק דבר באתר. כאן רואים אם הכול מוכן; את "העמוד החדש" מדליק בעל האתר בעמוד התוסף בוורדפרס.'),
      h('p', { class: 'co-count' }, h('b', {}, okN + ' / ' + s.rows.length), ' תנאים ✓'),
      h('ol', { class: 'co-rows' }, s.rows.map(function (r) { return row(r, s, redo); })),
      readyBox(s, redo),
      h('p', { class: 'warnbox co-kill' }, h('b', {}, 'כלל הכיבוי: '), s.kill_rule),
    ].filter(Boolean));
  }

  function liveBox(s) {
    var st = s.stats;
    return h('div', { class: 'co-live' },
      h('p', {}, h('span', { class: 'chip ok' }, 'באוויר'), ' העמוד החדש באוויר מאז ', h('b', {}, when(s.live.since)), ' · גרסה ' + s.live.version),
      h('p', { class: 'co-switch' }, 'המפסק "העמוד החדש": ', h('b', {}, s.on ? 'דולק' : 'לא ידוע (האתר לא ענה)'),
        h('span', { class: 'muted' }, ' — לקריאה בלבד. הכיבוי: בעמוד התוסף בוורדפרס, לחיצה אחת.')),
      h('p', {}, h('b', {}, 'עכשיו — הבדיקה המיידית: '), s.immediate),
      st ? h('p', { class: 'muted' }, 'מאז ההדלקה: ' + Object.keys(st).map(function (k) { return k + ' ' + (typeof st[k] === 'object' ? JSON.stringify(st[k]) : st[k]); }).join(' · ')) : null);
  }

  function row(r, s, redo) {
    var m = MARK[r.status] || MARK.bad;
    var extra = [];
    if (r.key === 'recordings' && (r.missing_n || (r.images && r.images.length))) {
      extra.push(h('details', { class: 'co-more' }, h('summary', {}, 'החסרות (' + r.missing_n + (r.images.length ? ' + ' + r.images.length + ' תמונות' : '') + ')'),
        h('ul', {}, r.missing.slice(0, 20).map(function (x) { return h('li', {}, x.word || ('מילה ' + x.word_id)); }),
          r.images.map(function (x) { return h('li', {}, x.label + ' — ' + x.url); })),
        h('a', { href: r.link, class: 'btn ghost' }, '🗂️ לספרייה')));
    }
    if (r.key === 'backup' && s.can_edit) {
      extra.push(h('div', { class: 'line' },
        h('button', { type: 'button', class: 'btn', onclick: function (e) { act(e.target, 'cutover_snapshot', {}, redo); } }, '🛟 צור עכשיו'),
        h('a', { class: 'btn ghost', href: 'api.php?action=backup' }, '💾 הורד גיבוי מלא')));
    }
    if (r.evidence_kind) {
      if (r.evidence.length) extra.push(h('ul', { class: 'co-ev' }, r.evidence.map(function (e) { return evItem(e, s, redo); })));
      if (s.can_edit) extra.push(h('button', { type: 'button', class: 'btn' + (r.status === 'ok' ? ' ghost' : ''), onclick: function () { evSheet(r, redo); } }, r.status === 'ok' ? '➕ ראיה נוספת' : '➕ הוסף ראיה'));
    }
    return h('li', { class: 'co-row co-' + r.status, 'data-key': r.key },
      h('span', { class: 'chip ' + m[1], title: m[2] }, h('span', { 'aria-hidden': 'true' }, m[0]), h('span', { class: 'sr' }, m[2])),
      h('div', { class: 'co-body' },
        h('b', {}, r.title),
        h('small', { class: 'muted co-src' }, r.source),
        h('p', { class: 'co-detail' }, r.detail),
        r.todo ? h('p', { class: 'co-todo' }, h('b', {}, 'מה לעשות: '), r.todo) : null,
        extra));
  }

  function evItem(e, s, redo) {
    var u = safeUrl(e.url);
    return h('li', {},
      h('span', {}, '✓ ' + e.by + ' · ' + when(e.at)),
      e.note ? h('span', { class: 'co-note' }, ' — ' + e.note) : null,
      u ? h('a', { href: u, target: '_blank', rel: 'noopener noreferrer' }, ' 🔗 קישור') : null,
      e.file ? (e.file.missing ? h('span', { class: 'muted' }, ' · הקובץ נמחק') : h('a', { href: 'api.php?action=file&id=' + encodeURIComponent(e.file.id), target: '_blank', rel: 'noopener' }, ' 📎 ' + e.file.name)) : null,
      s.can_edit ? h('button', { type: 'button', class: 'linkish co-revoke', onclick: function (ev) {
        if (!confirm('לבטל את הראיה? התנאי יחזור ל-✗ (הראיה נשארת ביומן)')) return;
        act(ev.target, 'cutover_evidence_revoke', { id: e.id }, redo);
      } }, 'בטל') : null);
  }

  function evSheet(r, redo) {
    var note = h('textarea', { rows: 3, placeholder: 'מה נבדק, ומה התוצאה (למשל "עבר 48/48")' });
    var url = h('input', { type: 'url', dir: 'ltr', placeholder: 'https://…', inputmode: 'url' });
    var file = h('input', { type: 'file' });
    var confirmBox = h('input', { type: 'checkbox' });
    var status = h('p', { class: 'hint', role: 'status' });
    var go = h('button', { type: 'button', class: 'btn primary' }, 'שמור ראיה');
    go.onclick = async function () {
      if (!confirmBox.checked) { status.replaceChildren(h('span', { class: 'error' }, 'צריך לסמן "אני מאשר/ת"')); return; }
      go.disabled = true;
      try {
        var fid = '';
        if (file.files[0]) {
          status.textContent = 'מעלה את הקובץ…';
          fid = (await Media.upload(file.files[0])).id;
        }
        var next = await api('cutover_evidence', { cond: r.key, confirm: true, note: note.value, url: url.value.trim(), file_id: fid });
        document.getElementById('sheet').close();
        toast('הראיה נשמרה ✓');
        redo(next);
      } catch (e) {
        go.disabled = false;
        status.replaceChildren(h('span', { class: 'error' }, e.message));
      }
    };
    sheet('ראיה: ' + r.title, h('div', { class: 'co-form' },
      h('p', { class: 'hint' }, r.todo || 'ראיה נוספת. נשמרת עם השם והתאריך, וביומן הפעולות.'),
      h('label', {}, 'הערה', note),
      h('label', {}, 'קישור', url),
      h('label', {}, 'קובץ (צילום, PDF, דוח)', file),
      h('label', { class: 'co-confirm' }, confirmBox, ' אני מאשר/ת שהתנאי הזה מתקיים'),
      go, status));
  }

  function readyBox(s, redo) {
    if (s.task) {
      return h('div', { class: 'co-ready' }, h('p', {}, h('b', {}, '✅ מוכן למעבר'), ' · נפתחה מטלה ', h('b', {}, s.task), ' עם צעדי ההדלקה.'),
        h('p', {}, 'ההוראה לבעל האתר: ', h('b', { class: 'co-path' }, s.instruction)));
    }
    if (!s.ready) return h('p', { class: 'muted co-notready' }, '"מוכן למעבר" יופיע כשכל התנאים ✓.');
    return h('div', { class: 'co-ready' },
      h('p', {}, h('b', {}, '✅ כל התנאים ✓.'), ' הכלי אינו מדליק דבר — ההדלקה בידי בעל האתר:'),
      h('p', { class: 'co-path' }, s.instruction),
      s.can_edit ? h('button', { type: 'button', class: 'btn primary', onclick: async function (e) {
        e.target.disabled = true;
        try { var r = await api('cutover_ready', {}); toast(r.existing ? 'המטלה ' + r.code + ' כבר פתוחה' : 'נפתחה מטלה ' + r.code); redo(r.state); }
        catch (err) { e.target.disabled = false; toast(err.message, true); }
      } }, '🔁 מוכן למעבר — פתח מטלה') : h('p', { class: 'hint' }, 'פתיחת המטלה — בידי הבעלים.'));
  }

  async function act(btn, action, body, redo) {
    btn.disabled = true;
    try { redo(await api(action, body)); } catch (e) { btn.disabled = false; toast(e.message, true); }
  }

  window.KolCutover = { mount: mount };
})();
