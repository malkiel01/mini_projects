/*
 * קול מרוקו — הבנייה לפרסום של פרויקט הקבצים (פרוסה M1; docs/spec-v2/07-publish-and-mount.md §2).
 *
 * לקובץ הזה שני תפקידים, כל אחד בעולם אחר:
 *
 *  1. **בסטודיו** (window.KolBuild): מנהל את הבנייה. השרת מרכיב את החבילה מהמאגר; הדפדפן רק פותח כל עמוד שיש בו
 *     רשימה במסגרת מבודדת (preview/, מצב בנייה), מקבל ממנה את מה שנוצר בתוך כל מכל, ומוסר אותו לשרת.
 *  2. **בתוך המסגרת** (מוגש כ-__kol/bridge.js רק בכתובת של מצב בנייה): מחכה ש-js/kol-data.js **של הפרויקט** יסיים
 *     לצייר, ושולח להורה רק את הצמתים שנוספו בכל מכל — כל אחד מסומן data-kol-pre. שום דבר אחר מהעמוד אינו יוצא.
 *     כאן רץ קוד של בעל העמוד, ולכן ההורה מתייחס לכל מה שמגיע מכאן כקלט זר: השרת בודק ומרכיב.
 */
(function () {
  'use strict';

  var inFrame = false;
  try { inFrame = window.top !== window && /\/preview\/b[0-9a-z]+-p-/.test(location.pathname); } catch (e) { inFrame = true; }
  if (inFrame) { frame(); return; }

  // ── בסטודיו ──────────────────────────────────────────────

  /**
   * בנייה מלאה. opts: { prerender, include_config, exclude }. progress(step) — לפס ההתקדמות.
   * מחזיר את הגרסה הבנויה; כשל — Error עם .build = { msg, file, line, page } ו-.id.
   */
  async function run(opts, progress) {
    progress = progress || function () {};
    progress({ step: 'start' });
    var s = await api('build_start', opts || {});
    var externals = new Set();
    for (var i = 0; i < s.pages.length; i++) {
      var page = s.pages[i];
      progress({ step: 'page', i: i, n: s.pages.length, page: page, id: s.id });
      var r = await renderPage(s, page);
      (Array.isArray(r.externals) ? r.externals : []).forEach(function (u) { if (typeof u === 'string') externals.add(u); });
      if (!r.ok) {
        var err = clean(r.error, page);
        await api('build_fail', { id: s.id, error: err }).catch(function () {});
        throw Object.assign(new Error(err.msg), { build: err, id: s.id });
      }
      try {
        await api('build_page', { id: s.id, path: page, unsupported: !!(r.unsupported || r.nokd),
          containers: Array.isArray(r.containers) ? r.containers.map(function (c) { return c && typeof c.html === 'string' ? { list: String(c.list), html: c.html } : null; }) : [] });
      } catch (e) {
        var bad = { msg: e.message, file: page, line: (e.data && e.data.line) || 0, page: page };
        await api('build_fail', { id: s.id, error: bad }).catch(function () {});
        throw Object.assign(new Error(e.message), { build: bad, id: s.id });
      }
    }
    progress({ step: 'finish', id: s.id });
    var f = await api('build_finish', { id: s.id, externals: Array.from(externals) });
    progress({ step: 'done', id: s.id });
    return f.build;
  }

  function clean(e, page) {
    e = e && typeof e === 'object' ? e : {};
    return { msg: String(e.msg || 'הבנייה נכשלה').slice(0, 500), file: String(e.file || '').slice(0, 255), line: Number(e.line) || 0, page: page };
  }

  /** עמוד אחד במסגרת מבודדת (sandbox בלי allow-same-origin). התשובה — הודעה אחת מהמסגרת הזו בלבד, או זמן. */
  function renderPage(s, page) {
    return new Promise(function (resolve) {
      var f = document.createElement('iframe');
      f.setAttribute('sandbox', String(s.sandbox).replace(/^sandbox\s*/, ''));     // PV_SANDBOX הוא בצורת CSP ("sandbox …") — המאפיין בלי המילה
      f.setAttribute('aria-hidden', 'true');
      f.tabIndex = -1;
      f.referrerPolicy = 'no-referrer';
      f.title = 'בנייה';
      f.className = 'bd-frame';
      var timer = setTimeout(function () { done({ ok: false, error: { msg: 'הבנייה של העמוד לא הסתיימה תוך ' + (s.timeout || 60) + ' שניות', file: page, line: 0 } }); }, (s.timeout || 60) * 1000);
      function onMsg(e) {
        if (e.source !== f.contentWindow || !e.data || e.data.kolBuild !== 1) return;
        done(e.data);
      }
      function done(r) { clearTimeout(timer); window.removeEventListener('message', onMsg); f.remove(); resolve(r); }
      window.addEventListener('message', onMsg);
      f.src = s.base + s.token + '/' + page.split('/').map(encodeURIComponent).join('/');
      document.body.appendChild(f);
    });
  }

  window.KolBuild = { run: run, block: block, sheet: buildSheet, notices: notices };

  // ── הממשק: "📁 פרסום מפרויקט הקבצים" בלשונית 🚀 פרסום ──

  (function css() {
    if (document.querySelector('link[data-kol-publish-css]')) return;
    var l = document.createElement('link');
    l.rel = 'stylesheet'; l.href = 'assets/css/publish.css'; l.setAttribute('data-kol-publish-css', '');
    document.head.appendChild(l);
  })();

  var STATUS = { building: ['בבנייה', 'warn'], built: ['מוכנה', 'ok'], failed: ['נכשלה', 'bad'], sending: ['בשליחה / ממתין למשיכה', 'warn'], live: ['באתר', 'ok'], superseded: ['הוחלפה', ''] };
  var when = function (iso) { return iso ? new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Jerusalem' }) : ''; };
  var mb = function (n) { return n == null ? '' : n < 1048576 ? Math.max(1, Math.round(n / 1024)) + 'KB' : (n / 1048576).toFixed(1) + 'MB'; };
  var where = function (x) { return x.file ? x.file + (x.line ? ':' + x.line : '') : ''; };
  var openCode = function (x) { location.hash = '#/project'; if (x && x.file) toast('בקוד: ' + where(x)); };

  /** החלק החדש בלשונית הפרסום. host — המסך, לרענון אחרי בנייה. */
  function block(host, role) {
    var box = h('section', { class: 'pk', 'aria-labelledby': 'pk-t' }, h('p', { class: 'empty' }, 'טוען…'));
    draw(box, host, role);
    return box;
  }

  async function draw(box, host, role) {
    var d;
    try { d = await api('builds'); } catch (e) { box.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    var prer = h('input', { type: 'checkbox', checked: true });
    var conf = h('input', { type: 'checkbox' });
    var ex = h('textarea', { rows: 3, dir: 'ltr', placeholder: 'נתיב בכל שורה, למשל from-current/lexicon.js', spellcheck: 'false' });
    var bar = h('progress', { max: 100, value: 0, hidden: true });
    var status = h('p', { class: 'hint', role: 'status' });
    var go = h('button', { type: 'button', class: 'btn primary' }, '🏗️ בנה גרסה מהפרויקט');
    var start = async function (opts) {
      go.disabled = true; bar.hidden = false; bar.value = 5;
      try {
        var b = await run(opts, function (p) {
          if (p.step === 'start') status.textContent = 'אוסף את קובצי הפרויקט…';
          if (p.step === 'page') { bar.value = 10 + Math.round(80 * p.i / p.n); status.textContent = 'כתיבה מראש: ' + p.page + ' (' + (p.i + 1) + '/' + p.n + ')'; }
          if (p.step === 'finish') { bar.value = 92; status.textContent = 'בודק מה ישתנה…'; }
        });
        bar.value = 100; status.textContent = 'גרסה ' + b.id + ' מוכנה ✓';
        draw(box, host, role);
        buildSheet(b.id, role);
      } catch (e) {
        bar.hidden = true; go.disabled = false;
        status.replaceChildren(h('span', { class: 'error' }, e.message), e.build && e.build.file ? ' · ' + where(e.build) : '');
        if (e.id) buildSheet(e.id, role);
      }
    };
    go.onclick = function () {
      start({ prerender: prer.checked, include_config: conf.checked, exclude: ex.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean) });
    };
    var kd = d.kd;
    var kdBox = kd && kd.present && !kd.prerender
      ? h('div', { class: 'warnbox' }, h('b', {}, 'js/kol-data.js בפרויקט — גרסה ' + (kd.version || '?')), ' אינו יודע להמשיך מרשימה כתובה מראש, ולכן אין כתיבה מראש (גוגל ומי שאין לו JS לא יראו את המילים). ',
        d.is_owner ? h('button', { type: 'button', class: 'btn', onclick: function () { kdSheet(kd, function () { draw(box, host, role); }); } }, '🔄 עדכן את kol-data.js לגרסה ' + kd.seed_version)
          : h('span', { class: 'muted' }, 'העדכון — בידי הבעלים.'))
      : null;
    box.replaceChildren.apply(box, [
      h('h2', { class: 'sec-title', id: 'pk-t' }, '📁 פרסום מפרויקט הקבצים'),
      h('p', { class: 'hint' }, 'המסלול החדש: הקבצים נבנים כמו שהם, נבדקים ונחתמים. האתר עצמו עדיין מוצג מהטיוטה (למטה) — השליחה לאתר תיפתח עם התוסף 2.0.'),
      h('div', { class: 'line' }, d.live ? [h('span', { class: 'muted' }, 'באתר במסלול החדש:'), h('b', {}, 'גרסה ' + d.live.version), h('span', { class: 'muted' }, '· ' + when(d.live.sent_at))] : h('span', { class: 'muted' }, 'עוד לא נשלחה גרסה במסלול החדש')),
      kdBox,
      h('details', { class: 'pk-opts' }, h('summary', {}, 'הגדרות הבנייה'),
        h('label', { class: 'switch' }, prer, h('span', {}, 'כתיבה מראש של הרשימות ל-HTML (גוגל ובלי JS)')),
        h('label', { class: 'switch' }, conf, h('span', {}, 'לפרסם גם את kol.json ו-components/*/component.json')),
        h('label', { class: 'f' }, h('span', {}, 'קבצים להוציא מהחבילה'), ex)),
      h('div', { class: 'row-btns' }, go), bar, status,
      d.builds.length ? h('ul', { class: 'list pk-list' }, d.builds.slice(0, 10).map(function (b) {
        var st = STATUS[b.status] || [b.status, ''];
        return h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: function () { buildSheet(b.id, role); } },
          h('span', { class: 'use-ic', 'aria-hidden': 'true' }, b.version ? String(b.version) : '#' + b.id),
          h('span', { class: 'use-tx' }, h('b', { class: 'one' }, b.version ? 'גרסה ' + b.version : 'בנייה ' + b.id),
            h('small', {}, b.by + ' · ' + when(b.built_at || b.at) + (b.count ? ' · ' + b.count + ' קבצים' : '') + (b.stale ? ' · הפרויקט השתנה מאז' : ''))),
          h('span', { class: 'chip ' + st[1] }, st[0]), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
      })) : null,
    ].filter(Boolean));
  }

  /** עדכון kol-data.js: שתי הגרסאות זו מול זו, ואישור. נקודת שחזור נוצרת בשרת לפני השמירה. */
  async function kdSheet(kd, after) {
    var body = h('div', {}, h('p', { class: 'empty' }, 'טוען…'));
    var dlg = sheet('🔄 עדכון kol-data.js', body);
    var mine, seed;
    try { [mine, seed] = await Promise.all([api('pf_read', undefined, '&id=' + kd.id), api('kd_seed')]); } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    var go = h('button', { type: 'button', class: 'btn primary' }, 'עדכן (נקודת שחזור לפני)');
    var st = h('p', { class: 'hint', role: 'status' });
    go.onclick = async function () {
      go.disabled = true;
      try { var r = await api('kd_update', { rev: kd.rev }); dlg.close(); toast('kol-data.js עודכן ל-' + r.kd.version + ' · נקודת שחזור #' + r.snapshot); after(); }
      catch (e) { go.disabled = false; st.replaceChildren(h('span', { class: 'error' }, e.message)); }
    };
    body.replaceChildren(h('p', { class: 'hint' }, 'הקובץ בפרויקט (' + (kd.version || '?') + ') יוחלף בגרסה ' + kd.seed_version + '. אם שיניתם אותו — השינויים שלכם יוחלפו; הם נשארים בהיסטוריה של הקובץ ובנקודת השחזור.'),
      diffView(mine.content || '', seed.content || ''), h('div', { class: 'row-btns' }, go), st);
  }

  /** "מה ישתנה" לגרסה בנויה אחת. */
  async function buildSheet(id, role) {
    var body = h('div', { class: 'pk-sheet' }, h('p', { class: 'empty' }, 'טוען…'));
    sheet('🏗️ גרסה בנויה ' + id, body);
    var g;
    try { g = await api('build_get', undefined, '&id=' + id); } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    var b = g.build, rep = b.report || {}, df = rep.diff || {};
    var st = STATUS[b.status] || [b.status, ''];
    var out = [h('div', { class: 'line' }, h('span', { class: 'chip ' + st[1] }, st[0]), h('span', { class: 'muted' }, 'נבנתה בידי ' + b.by + ' · ' + when(b.built_at || b.at) + ' · מ-rev ' + b.rev),
      b.version ? h('b', {}, 'גרסה ' + b.version) : null)];
    if (b.stale) out.push(h('p', { class: 'warnbox' }, 'נבנתה מ-rev ' + b.rev + '; הפרויקט השתנה מאז. בנו שוב כדי לפרסם את המצב הנוכחי.'));
    if (b.status === 'failed') {
      var e = b.error || {};
      out.push(h('div', { class: 'error pk-err' }, h('b', {}, 'הבנייה נכשלה — אין גרסה. '), e.msg || '', e.file ? h('div', { dir: 'ltr' }, where(e)) : null,
        h('div', { class: 'row-btns wrap' }, h('button', { type: 'button', class: 'btn', onclick: function () { $('#sheet').close(); openCode(e); } }, 'פתח בקוד'),
          h('button', { type: 'button', class: 'btn primary', onclick: function () { $('#sheet').close(); rebuild(role, { prerender: false }); } }, 'בנה בלי כתיבה מראש'))));
      body.replaceChildren.apply(body, out);
      return;
    }
    if (b.status === 'building') { out.push(h('p', { class: 'hint' }, 'הבנייה עדיין רצה.')); body.replaceChildren.apply(body, out); return; }
    (rep.info || []).forEach(function (x) { out.push(h('p', { class: 'hint' }, 'ℹ️ ' + x.msg)); });
    // שליחה
    out.push(sendBox(b, g, role));
    // אזהרות
    var warn = rep.warnings || [];
    if (warn.length) out.push(h('div', { class: 'warnbox' }, h('b', {}, 'אזהרות (' + warn.length + ')'), h('ul', {}, warn.map(function (w) {
      return h('li', {}, (w.block ? '⛔ ' : '') + w.msg, w.file ? [' · ', h('button', { type: 'button', class: 'linkish', dir: 'ltr', onclick: function () { $('#sheet').close(); openCode(w); } }, where(w))] : null);
    }))));
    // קבצים
    out.push(h('h3', { class: 'sec-title' }, 'קבצים'), h('p', { class: 'hint' }, rep.count + ' קבצים · ' + mb(rep.bytes) + ' מתוך ' + mb(rep.quota)));
    if (df.first) out.push(h('p', {}, 'פרסום ראשון: כל הקבצים חדשים (' + df.added.length + ').'), compareBox(g));
    else if (!df.added.length && !df.changed.length && !df.removed.length) out.push(h('p', {}, '✓ אין שינוי בקבצים מאז גרסה ' + df.base));
    var fileList = function (ic, label, list) {
      return list && list.length ? h('details', { open: list.length <= 12 && !df.first }, h('summary', {}, ic + ' ' + label + ' (' + list.length + ')'),
        h('ul', { class: 'pk-files' }, list.slice(0, 400).map(function (f) {
          return h('li', {}, h('button', { type: 'button', class: 'linkish', dir: 'ltr', onclick: function () { fileSheet(id, f, role); } }, f.path), h('small', { class: 'muted' }, ' ' + mb(f.size)));
        }))) : null;
    };
    out.push(fileList('➕', 'נוספו', df.added), fileList('✏️', 'השתנו', df.changed), fileList('➖', 'נמחקו', df.removed));
    out.push(pagesBox(df, role));   /* V15 §2.6: עמודים — חדש / שונה / נמחק / כתובת שהשתנתה; קישורים שבורים לעמודים */
    if ((rep.excluded || []).length) out.push(h('details', {}, h('summary', {}, '🚫 יצאו מהחבילה (' + rep.excluded.length + ')'), h('ul', { class: 'pk-files' }, rep.excluded.map(function (x) { return h('li', {}, h('code', { dir: 'ltr' }, x.path), ' — ' + x.why); }))));
    // נתונים
    var w = rep.data;
    if (w) out.push(h('h3', { class: 'sec-title' }, 'נתונים'), h('p', {}, '📚 ' + w.total + ' מילים' + (df.first ? '' : ' · ' + w.added + ' נוספו, ' + w.changed + ' השתנו, ' + w.removed + ' נמחקו') + ' · 🔊 ' + w.audio + ' הקלטות, ' + w.audio_inside + ' בתוך החבילה'));
    // משאבים חיצוניים
    var ext = rep.externals || [];
    out.push(h('h3', { class: 'sec-title' }, 'משאבים חיצוניים (' + ext.length + ')'), h('p', { class: 'hint' }, 'כתובות מחוץ לחבילה שהעמוד טוען. מידע — לא חסימה.'),
      ext.length ? h('ul', { class: 'pk-files' }, ext.slice(0, 200).map(function (x) { return h('li', {}, h('span', { class: 'chip' }, x.kind), ' ', h('code', { dir: 'ltr' }, x.url), x.file ? h('small', { class: 'muted', dir: 'ltr' }, ' ' + where(x)) : h('small', { class: 'muted' }, ' (בזמן הבנייה)')); })) : h('p', {}, 'אין.'));
    // תצוגה
    out.push(h('div', { class: 'row-btns wrap' }, h('button', { type: 'button', class: 'btn', onclick: function () { viewSheet(g, id, role); } }, '▶️ תצוגה של הגרסה הבנויה'),
      h('button', { type: 'button', class: 'btn ghost', onclick: function () { $('#sheet').close(); rebuild(role, {}); } }, '🏗️ בנה שוב')));
    body.replaceChildren.apply(body, out.filter(Boolean));
  }

  /* V15 §2.6: "מה ישתנה" בעמודים. הזזה = נתיב ישן שבגרסה החיה ואינו קיים היום, והקובץ שזז (מהיסטוריית הקובץ — pages_list.moves). */
  function pagesBox(df, role) {
    var box = h('div', { class: 'pk-pages', 'data-pk-pages': true }, h('h3', { class: 'sec-title' }, '📄 עמודים'), h('p', { class: 'muted' }, 'טוען…'));
    var isPage = function (p) { return /\.html?$/i.test(p) && !/^components\//.test(p) && !/^from-current\/[^/]+\.html?$/i.test(p); };
    api('pages_list').then(function (d) {
      var moves = d.moves || [], from = {}, to = {};
      moves.forEach(function (m) { from[m.from] = m; to[m.to] = m; });
      var add = (df.added || []).filter(function (f) { return isPage(f.path) && !to[f.path]; }), chg = (df.changed || []).filter(function (f) { return isPage(f.path); }),
        rem = (df.removed || []).filter(function (f) { return isPage(f.path) && !from[f.path]; });
      var line = function (ic, label, list) { return list.length ? h('li', {}, ic + ' ' + label + ' (' + list.length + '): ', list.map(function (f) { return f.path; }).join(', ')) : null; };
      var kids = [h('h3', { class: 'sec-title' }, '📄 עמודים'),
        h('p', { 'data-pk-pages-count': true }, 'חדשים ' + add.length + ' · שונו ' + chg.length + ' · נמחקו ' + rem.length + ' · כתובת השתנתה ' + moves.length),
        h('ul', { class: 'pk-files' }, line('➕', 'חדשים', add), line('✏️', 'שונו', chg), line('➖', 'נמחקו', rem))];
      var canPub = role === 'publisher' || role === 'owner';
      moves.forEach(function (m) {
        var b = h('button', { type: 'button', class: 'btn sm', 'data-pk-redirect': m.from, disabled: !canPub || null, onclick: function () {
          var slug = m.from_url.replace(/\/$/, '');
          if (!/^[a-z0-9-]+(\/[a-z0-9-]+)*$/.test(slug)) { toast('הכתובת הישנה אינה בצורה של תיקייה — יוצרים הפניה בקוד', true); return; }
          b.disabled = true;
          api('page_create', { slug: slug, start: 'redirect', to: m.to }).then(function (r) { toast('נוצרה הפניה: ' + r.file.path + ' — בונים שוב כדי שתיכנס לגרסה'); b.textContent = '✓ נוצרה ' + r.file.path; })
            .catch(function (e) { b.disabled = false; toast(e.message, true); });
        } }, '↪ השאר הפניה');
        kids.push(h('div', { class: 'warnbox', 'data-pk-move': m.from }, h('b', {}, '⚠️ כתובת השתנתה: '), h('code', { dir: 'ltr' }, d.base + m.from_url), ' ← ', h('code', { dir: 'ltr' }, d.base + m.to_url),
          h('p', {}, 'קישורים מבחוץ (גוגל, וואטסאפ) לכתובת הישנה יקבלו 404. "השאר הפניה" יוצר את ' + m.from + ' עם meta refresh + canonical — קובץ רגיל בעץ.'), b));
      });
      if ((d.broken || []).length) kids.push(h('div', { class: 'warnbox', 'data-pk-broken': d.broken.length }, h('b', {}, 'קישורים שבורים לעמודים (' + d.broken.length + ')'),
        h('ul', {}, d.broken.slice(0, 50).map(function (x) { return h('li', {}, x.msg, ' · ', h('button', { type: 'button', class: 'linkish', dir: 'ltr', onclick: function () { $('#sheet').close(); openCode({ file: x.path, line: x.line }); } }, x.path + ':' + x.line)); }))));
      box.replaceChildren.apply(box, kids.filter(Boolean));
    }).catch(function (e) { box.replaceChildren(h('h3', { class: 'sec-title' }, '📄 עמודים'), h('p', { class: 'error' }, e.message)); });
    return box;
  }

  function rebuild(role, opts) {
    var t = toast('בונה…');
    run(opts).then(function (b) { toast('גרסה ' + b.id + ' מוכנה ✓'); buildSheet(b.id, role); refreshTab(); }).catch(function (e) { toast(e.message, true); if (e.id) buildSheet(e.id, role); refreshTab(); });
    return t;
  }
  function refreshTab() { if (/^#\/publish/.test(location.hash) && window.Publish) window.dispatchEvent(new HashChangeEvent('hashchange')); }

  function sendBox(b, g, role) {
    var box = h('div', { class: 'summary pk-send' });
    if (b.status === 'live') { box.append(h('p', {}, '✓ נשלחה לאתר · ' + when(b.sent_at) + (b.sent_by ? ' · ' + b.sent_by : ''))); return box; }
    if (b.status === 'superseded') { box.append(h('p', { class: 'muted' }, 'הוחלפה בגרסה חדשה יותר.')); return box; }
    var canSend = role === 'publisher' || role === 'owner';
    if (!canSend) { box.append(h('p', { class: 'hint' }, 'הגרסה נשמרה. רק מפרסם שולח לאתר — הוא בונה ובודק בעצמו.')); return box; }
    if (!b.by_me) { box.append(h('p', { class: 'hint' }, 'את הגרסה הזו בנה/תה ' + b.by + '. כדי לשלוח — בנו בעצמכם (🏗️ בנה שוב) ועברו על "מה ישתנה".')); return box; }
    var go = h('button', { type: 'button', class: 'btn primary', disabled: true }, b.status === 'sending' ? '↻ המשך שליחה' : '🚀 שלח לאתר');
    var st = h('p', { class: 'hint', role: 'status' }, 'בודק את התוסף באתר…');
    if (b.error && b.error.send) st.replaceChildren(h('span', { class: 'error' }, 'השליחה הקודמת נכשלה: ' + b.error.msg));
    var blocked = (b.report.warnings || []).some(function (w) { return w.block; });
    box.append(go, st);
    if (blocked) { st.textContent = 'החבילה גדולה מהמכסה — אי אפשר לשלוח.'; return box; }
    // M7 — ערוץ המשיכה: אין "שליחה"; הגרסה נכנסת לתור והאתר מושך אותה. גם לפני שהאתר משך אי פעם — היא מחכה בתור
    var pull = function (pulse) {
      go.disabled = false;
      if (b.status === 'sending') go.textContent = '↻ שוב לתור (מחליף את הקודם)';
      else go.textContent = '🚀 לתור — האתר מושך';
      if (!(b.error && b.error.send)) st.textContent = (b.status === 'sending' ? '⏳ בתור — ממתין למשיכה של האתר. ' : 'ערוץ משיכה: הגרסה נכנסת לתור, והאתר מושך אותה ומדווח. ') + (pulse ? pulse.text : 'האתר עוד לא משך מהכלי — הגרסה תחכה בתור.');
    };
    api('site_ping', {}).then(function (r) {
      var p = r.site || {};
      if (p.channel === 'pull') return pull(p.pulse);
      if ((p.protocol || 0) < 2) { st.textContent = 'הגרסה מוכנה ונשמרה. התוסף באתר הוא ' + (p.plugin || '1.x') + ' — לשליחה נדרש 2.0 (M2, מטלה לבעלים).'; return; }
      go.disabled = false; if (!(b.error && b.error.send)) st.textContent = 'התוסף באתר: ' + p.plugin + '. רק קבצים שהאתר עוד אינו מחזיק נשלחים; המניפסט — אחרון.';
    }).catch(function (e) { if (e.data && e.data.code === 'no_heartbeat') return pull(null); st.replaceChildren(h('span', { class: 'error' }, 'האתר: ' + e.message)); });
    go.onclick = async function () {
      if (!confirm('לשלוח את הגרסה הזו לאתר?')) return;
      go.disabled = true; st.textContent = 'שולח…';
      try {
        var r = await api('build_send', { id: b.id });
        // M7: בערוץ המשיכה — בתור; "באתר" רק כשהאתר מדווח שהחיל
        toast(r.queued ? '⏳ גרסה ' + r.build.version + ' בתור (#' + r.seq + ') — ממתין למשיכה של האתר' : 'גרסה ' + r.build.version + ' באתר ✓ (' + r.sent + ' קבצים נשלחו)');
        buildSheet(b.id, role); refreshTab();
      }
      catch (e) { go.disabled = false; go.textContent = '↻ המשך שליחה'; st.replaceChildren(h('span', { class: 'error' }, e.message)); }
    };
    return box;
  }

  function frameEl(src, title) {
    var f = h('iframe', { sandbox: 'allow-scripts allow-forms allow-popups allow-modals', referrerpolicy: 'no-referrer', title: title, loading: 'lazy' });
    f.src = src;
    return f;
  }
  var viewUrl = function (g, p) { return 'preview/' + g.view + '/' + (p ? p.split('/').map(encodeURIComponent).join('/') : ''); };

  /** פרסום ראשון: השוואה חזותית מול העמוד של היום (הגרסה שבאתר מהטיוטה), ב-1280 וב-390. */
  function compareBox(g) {
    var wrap = h('div', { class: 'pk-compare' });
    var seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'רוחב' });
    var cur = 1280;
    var stage = h('div', { class: 'pk-pair' });
    var old = h('iframe', { sandbox: 'allow-scripts', title: 'העמוד של היום', loading: 'lazy' });
    var mk = function (w) { var l = h('label', {}, h('input', { type: 'radio', name: 'pkw', checked: w === cur, onchange: function () { cur = w; fit(); } }), h('span', {}, w + 'px')); return l; };
    seg.append(mk(1280), mk(390));
    var cells = [['הגרסה הבנויה', frameEl(viewUrl(g), 'הגרסה הבנויה')], ['העמוד של היום (הטיוטה שבאתר)', old]].map(function (c) { return h('figure', {}, h('figcaption', {}, c[0]), h('div', { class: 'pk-scale' }, c[1])); });
    stage.append.apply(stage, cells);
    function fit() {
      cells.forEach(function (c) {
        var box = c.querySelector('.pk-scale'), f = box.firstChild, k = Math.min(1, (box.clientWidth || 300) / cur);
        f.style.width = cur + 'px'; f.style.height = Math.round(cur === 1280 ? 800 : 780) + 'px'; f.style.transform = 'scale(' + k + ')';
        box.style.height = Math.round((cur === 1280 ? 800 : 780) * k) + 'px';
      });
    }
    api('versions').then(function (v) {
      if (!v.live) { old.replaceWith(h('p', { class: 'hint' }, 'אין גרסה באתר מהטיוטה להשוואה.')); return; }
      return api('version_preview', undefined, '&n=' + v.live.n).then(function (r) {
        old.srcdoc = '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><style>body{margin:0;font-family:system-ui,Arial,sans-serif}' + r.css.replace(/<\/style/gi, '') + '</style></head><body>' + r.html + '</body></html>';
      });
    }).catch(function (e) { old.replaceWith(h('p', { class: 'error' }, e.message)); });
    wrap.append(seg, stage);
    setTimeout(fit, 0);
    window.addEventListener('resize', fit);
    return wrap;
  }

  function viewSheet(g, id, role) {
    var seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'רוחב' });
    var f = frameEl(viewUrl(g), 'הגרסה הבנויה');
    f.className = 'pk-view';
    [['מלא', ''], ['טאבלט', '820px'], ['טלפון', '390px']].forEach(function (x, i) {
      seg.append(h('label', {}, h('input', { type: 'radio', name: 'pkv', checked: i === 0, onchange: function () { f.style.width = x[1] || '100%'; } }), h('span', {}, x[0])));
    });
    sheet('▶️ גרסה בנויה ' + id, h('p', { class: 'hint' }, 'החבילה עצמה — הקבצים הבנויים, בדיוק כמו שייצאו, במסגרת המבודדת.'),
      h('div', { class: 'row-btns wrap' }, seg, h('a', { class: 'btn ghost', href: viewUrl(g), target: '_blank', rel: 'noopener noreferrer' }, '↗️ בלשונית חדשה'),
        h('button', { type: 'button', class: 'btn ghost', onclick: function () { buildSheet(id, role); } }, '‹ חזרה')), f);
  }

  /** קובץ אחד: diff לטקסט, תצוגה למדיה. */
  async function fileSheet(id, f, role) {
    var back = h('button', { type: 'button', class: 'btn ghost', onclick: function () { buildSheet(id, role); } }, '‹ חזרה');
    var body = h('div', {}, h('p', { class: 'empty' }, 'טוען…'));
    sheet(f.path, back, body);
    if (!f.text) {
      var g = await api('build_get', undefined, '&id=' + id).catch(function () { return null; });
      var src = g ? viewUrl(g, f.path) : '';
      body.replaceChildren(/^image\//.test(f.type) ? h('img', { src: src, alt: f.path, class: 'pk-thumb' })
        : /^audio\//.test(f.type) ? h('audio', { src: src, controls: true, preload: 'none' })
          : /^video\//.test(f.type) ? h('video', { src: src, controls: true, preload: 'none', class: 'pk-thumb' }) : h('p', { class: 'hint' }, f.type + ' · ' + mb(f.size)));
      return;
    }
    try {
      var [a, b] = await Promise.all([api('build_file', undefined, '&id=' + id + '&side=old&path=' + encodeURIComponent(f.path)), api('build_file', undefined, '&id=' + id + '&side=new&path=' + encodeURIComponent(f.path))]);
      body.replaceChildren(diffView(a.missing ? '' : a.content || '', b.missing ? '' : b.content || ''));
    } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  /** diff לפי שורות (LCS). קובץ גדול מדי — רק השורות שהשתנו בסדר הופעתן. */
  function diffView(a, b) {
    var A = a.split('\n'), B = b.split('\n');
    var rows = [];
    if (A.length * B.length > 4e6) {
      var sa = new Set(A), sb = new Set(B);
      A.forEach(function (l, i) { if (!sb.has(l)) rows.push(['-', l, i + 1]); });
      B.forEach(function (l, i) { if (!sa.has(l)) rows.push(['+', l, i + 1]); });
    } else {
      var n = A.length, m = B.length, L = [];
      for (var i = 0; i <= n; i++) L.push(new Uint32Array(m + 1));
      for (i = n - 1; i >= 0; i--) for (var j = m - 1; j >= 0; j--) L[i][j] = A[i] === B[j] ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1]);
      i = 0; j = 0;
      while (i < n || j < m) {
        if (i < n && j < m && A[i] === B[j]) { rows.push([' ', A[i], j + 1]); i++; j++; }
        else if (j < m && (i === n || L[i][j + 1] >= L[i + 1][j])) { rows.push(['+', B[j], j + 1]); j++; }
        else { rows.push(['-', A[i], i + 1]); i++; }
      }
      // רק סביב שינויים: 3 שורות הקשר
      var keep = rows.map(function () { return false; });
      rows.forEach(function (r, k) { if (r[0] !== ' ') for (var d = -3; d <= 3; d++) if (rows[k + d]) keep[k + d] = true; });
      var out = [];
      rows.forEach(function (r, k) { if (keep[k]) out.push(r); else if (out.length && out[out.length - 1][0] !== '…') out.push(['…', '', 0]); });
      rows = out;
    }
    if (!rows.some(function (r) { return r[0] === '+' || r[0] === '-'; })) return h('p', { class: 'hint' }, 'אין הבדל.');
    return h('pre', { class: 'pk-diff', dir: 'ltr' }, rows.slice(0, 3000).map(function (r) {
      return h('span', { class: r[0] === '+' ? 'add' : r[0] === '-' ? 'del' : r[0] === '…' ? 'gap' : '' }, (r[0] === '…' ? '…' : r[0] + ' ' + r[1]) + '\n');
    }));
  }

  // ── הודעת פרסום לבעלים ([ש-7]): בכניסה, עד שנקראה ──
  var noticesShown = false;
  async function notices() {
    if (noticesShown) return;
    for (var t = 0; t < 60 && !(typeof state !== 'undefined' && state.user && state.user.role); t++) await new Promise(function (r) { setTimeout(r, 500); });
    if (typeof state === 'undefined' || !state.user || typeof People !== 'undefined') return;   // P1: ההודעות — בפעמון (assets/js/people.js)
    noticesShown = true;
    var d;
    try { d = await api('notices'); } catch (e) { return; }
    if (!d.notices.length) return;
    var bar = h('div', { class: 'pk-notice', role: 'status' });
    var close = async function () { try { await api('notice_read', { ids: d.notices.map(function (n) { return n.id; }) }); } catch (e) { /* נשאר להבא */ } bar.remove(); };
    bar.append(h('b', {}, '🚀 ' + (d.notices.length > 1 ? d.notices.length + ' פרסומים חדשים' : 'פרסום חדש')),
      h('ul', {}, d.notices.slice(0, 5).map(function (n) {
        return h('li', {}, n.text + ' · ' + when(n.at) + ' ', n.build ? h('button', { type: 'button', class: 'linkish', onclick: function () { buildSheet(n.build, state.user.role); } }, 'מה השתנה') : null);
      })),
      h('button', { type: 'button', class: 'btn', onclick: close }, '✓ קראתי'));
    document.body.appendChild(bar);
  }

  // ── בתוך המסגרת ──────────────────────────────────────────

  function frame() {
    var root = location.pathname.replace(/^(.*?\/preview\/[^/]+\/).*$/, '$1');
    var page = decodeURIComponent(location.pathname.slice(root.length));
    var errors = [], sent = false, started = Date.now(), loadedAt = 0;
    var initial = new Map();

    function rel(u) {
      try {
        var x = new URL(u, location.href);
        if (x.origin === location.origin && x.pathname.indexOf(root) === 0) return decodeURIComponent(x.pathname.slice(root.length));
        return x.href;
      } catch (e) { return String(u || ''); }
    }
    window.addEventListener('error', function (e) {
      if (e.target && e.target !== window) return;                    // משאב שלא נטען — הסריקה בשרת מדווחת על הפניות שבורות
      errors.push({ msg: e.message || String(e.error), file: rel(e.filename || ''), line: e.lineno || 0 });
    }, true);
    window.addEventListener('unhandledrejection', function (e) {
      errors.push({ msg: 'Promise: ' + String((e.reason && e.reason.message) || e.reason), file: '', line: 0 });
    });
    // לפני ש-kol-data.js מתחיל (הסקריפט הזה ראשון ב-<head>, ולכן גם המאזין שלו ראשון): מה שכבר היה בכל מכל
    document.addEventListener('DOMContentLoaded', function () {
      all().forEach(function (c) { initial.set(c, new Set(Array.prototype.slice.call(c.children))); });
    });
    window.addEventListener('load', function () { loadedAt = Date.now(); });

    function all() { return Array.prototype.slice.call(document.querySelectorAll('[data-kol-list]')); }
    function hasTemplate(c) { for (var i = 0; i < c.children.length; i++) if (c.children[i].tagName === 'TEMPLATE') return true; return false; }
    function skip(c) { return !hasTemplate(c) || c.getAttribute('data-kol-prerender') === 'none'; }
    function externals() {
      try {
        return performance.getEntriesByType('resource').map(function (e) { return e.name; })
          .filter(function (u) { return /^https?:/i.test(u) && rel(u) === u; }).slice(0, 500);
      } catch (e) { return []; }
    }
    function done(r) {
      if (sent) return;
      sent = true;
      r.kolBuild = 1;
      r.page = page;
      r.externals = externals();
      parent.postMessage(r, '*');
    }
    function later() { setTimeout(tick, 100); }

    function tick() {
      if (errors.length) return done({ ok: false, error: errors[0] });
      if (!loadedAt) return later();
      var kd = window.KolData;
      if (!kd) return Date.now() - loadedAt > 1500 ? done({ ok: true, nokd: true }) : later();
      if (kd.prerender !== true) return done({ ok: true, unsupported: true, version: String(kd.version || '') });
      var lists = all();
      var waiting = lists.filter(function (c) { return !skip(c) && !c.hasAttribute('data-kol-ready'); });
      if (waiting.length) {
        if (Date.now() - started > 55000) return done({ ok: false, error: { msg: 'הרשימה "' + waiting[0].getAttribute('data-kol-list') + '" לא סיימה להיבנות', file: page, line: 0 } });
        return later();
      }
      // עוד רגע אחד — שגיאה שנזרקה מיד אחרי הציור האחרון נתפסת
      if (!tick.settled) { tick.settled = true; return setTimeout(tick, 150); }
      done({ ok: true, containers: lists.map(function (c) {
        if (skip(c)) return null;
        var was = initial.get(c) || new Set();
        var nodes = Array.prototype.slice.call(c.children).filter(function (n) { return n.tagName !== 'TEMPLATE' && !was.has(n); });
        var lim = c.getAttribute('data-kol-prerender');
        if (lim && /^\d+$/.test(lim)) nodes = nodes.slice(0, Number(lim));
        return { list: c.getAttribute('data-kol-list'), html: nodes.map(function (n) {
          var x = n.cloneNode(true);
          x.setAttribute('data-kol-pre', '');
          return x.outerHTML;
        }).join('\n') };
      }) });
    }
    later();
  }
})();
