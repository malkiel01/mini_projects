// אזור הפיתוח: משתמשים, פתוח/סגור, לוג מעקב, אכיפה, מצב המערכת.
// המפתח רואה מטא-דאטה בלבד — מספרים, מצבים, בעלים, תאריכים. לא תוכן.
(function () {
  'use strict';

  const TABS = [['users', '👥 משתמשים'], ['items', '📋 מטלות'], ['log', '🪵 לוג'], ['force', '🛡️ אכיפה'], ['health', '🩺 מצב']];
  const STATUS_DOC = { draft: 'טיוטה', sent: 'נשלח', in_progress: 'בתהליך', completed: 'הושלם', locked: 'נעול',
    expired: 'פג', cancelled: 'בוטל', declined: 'נדחה' };
  const when = (iso) => iso ? new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'medium' }) : '—';
  let timer = null;
  // replaceChildren מדפיס "null" — מסננים ריקים
  const put = (node, ...kids) => node.replaceChildren(...kids.flat().filter(k => k != null && k !== false));

  /** tab — מהכתובת (#dev?tab=log). users — הלשונית הקיימת (app.js). */
  function view(tab, users) {
    const { el, show, setTab } = window.DT;
    setTab('dev');
    if (!TABS.some(([k]) => k === tab)) tab = 'users';
    clearInterval(timer);
    const body = el('section', { class: 'dev-body' });
    const tabs = el('div', { class: 'seg-tabs', role: 'tablist', 'aria-label': 'אזור הפיתוח' },
      TABS.map(([k, t]) => el('a', { href: '#dev?tab=' + k, role: 'tab', 'data-tab': k, class: tab === k ? 'on' : '',
        'aria-selected': String(tab === k) }, t)));
    show(el('div', { class: 'screen' }, tabs, body));
    ({ users, items, log, force, health })[tab](body);
  }

  // ── מטלות: המודול המשותף DevLoop (assets/js/devloop.js) — קוד, שיח, אימות, ראיות, ⚠️ לבירור מהלוג ──
  function items(box) {
    box.classList.add('devloop-box');
    DevLoop.show(box, new URLSearchParams(location.hash.split('?')[1] || '').get('code') || '');
  }

  // ── לוג מעקב ──
  async function log(box) {
    const { api, el, notice } = window.DT;
    const errorsOnly = el('input', { type: 'checkbox' });
    const q = el('input', { type: 'search', placeholder: 'פעולה, מזהה (id=12) או מזהה בקשה', 'aria-label': 'חיפוש בלוג' });
    const auto = el('input', { type: 'checkbox', checked: true });
    const nodev = el('input', { type: 'checkbox', checked: true });
    const out = el('div');
    const stats = el('div', { class: 'summary' });
    const ACTOR = { user: '👤', api: '🔑', fill: '🔗', claude: '🤖', guest: '·' };
    async function load() {
      const query = {};
      if (errorsOnly.checked) query.errors = 1;
      if (nodev.checked) query.nodev = 1;
      const term = q.value.trim();
      if (/^\w+=\d+$/.test(term)) query.ref = term; else if (/^[0-9a-f]{12}$/.test(term)) query.req = term; else if (term) query.act = term;
      let r;
      try { r = await api('dev_log_list', undefined, query); } catch (e) { put(out, notice(e.message, 'bad')); return; }
      const d = r.day;
      put(stats, el('div', { class: 'line' }, el('span', { class: 'muted' }, '24 שעות:'),
        el('b', {}, String(d.n || 0)), el('span', { class: 'muted' }, 'בקשות ·'),
        d.e5 ? el('span', { class: 'badge bad' }, d.e5 + ' שגיאות שרת') : el('span', { class: 'badge ok' }, 'אין שגיאות שרת'),
        d.e4 ? el('span', { class: 'badge warn' }, d.e4 + ' נדחו (4xx)') : null,
        d.js ? el('span', { class: 'badge bad' }, d.js + ' שגיאות בדפדפן') : null,
        el('span', { class: 'muted' }, '· ממוצע ' + (d.avg_ms || 0) + 'ms')));
      // כרטיס לכל בקשה: מה ומתי בשורה הראשונה, מי ועל מה בשנייה, והשגיאה — במלואה
      put(out, r.rows.length ? el('ul', { class: 'list card-list log-list' }, r.rows.map(x => {
        const js = x.action === 'client_error';
        const kind = js || x.status >= 500 ? 'bad' : x.status >= 400 ? 'warn' : 'ok';
        const t = new Date(x.at);
        return el('li', { class: 'log-row ' + kind },
          el('div', { class: 'log-l1' },
            el('span', { class: 'badge ' + kind }, js ? 'דפדפן' : String(x.status ?? '')),
            el('b', { dir: 'ltr', class: 'log-act' }, x.action),
            el('span', { class: 'log-time muted', title: when(x.at) }, t.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit', second: '2-digit' }))),
          el('div', { class: 'log-l2 muted' },
            [(ACTOR[x.actor] || '') + ' ' + (x.user || x.ip || 'אורח'), x.ref ? el('bdi', { dir: 'ltr' }, x.ref) : null, js ? null : x.ms + 'ms', x.method,
              x.req ? el('bdi', { dir: 'ltr', class: 'log-req', title: 'מזהה הבקשה' }, '#' + x.req) : null,
              x.task ? el('a', { href: '#dev?tab=items&code=' + x.task, class: 'log-task' }, '⚠️ ' + x.task) : null]
              .filter(Boolean).flatMap((v, i) => i ? [' · ', v] : [v])),
          x.message || x.trace || x.page ? el('div', { class: 'log-msg' }, x.message || '',
            x.trace || x.page ? el('small', { dir: 'ltr' }, [x.trace, x.page].filter(Boolean).join(' · ')) : null) : null);
      })) : el('p', { class: 'empty' }, 'אין רשומות.'));
    }
    errorsOnly.addEventListener('change', load);
    nodev.addEventListener('change', load);
    q.addEventListener('change', load);
    timer = setInterval(() => { if (auto.checked && document.body.contains(out)) load(); else clearInterval(timer); }, 15000);
    put(box, 
      el('p', { class: 'hint' }, 'כל בקשה לשרת: מתי, איזו פעולה, תוצאה, משך, מי (👤 משתמש · 🔑 API · 🔗 ממלא בקישור · 🤖 Claude), מזהי המסמך/התבנית, מזהה הבקשה, והודעת השגיאה. שגיאות JavaScript מהדפדפן נרשמות גם הן. בלי ערכים ובלי תוכן. נשמר 30 יום. שגיאת שרת או דפדפן נפתחת לבד כמטלה "⚠️ לבירור" — הקישור ⚠️ בשורה מוביל אליה.'),
      stats,
      el('div', { class: 'toolbar' }, q, el('label', { class: 'switch small' }, errorsOnly, el('span', {}, 'רק שגיאות')),
        el('label', { class: 'switch small' }, nodev, el('span', {}, 'בלי אזור הפיתוח')),
        el('label', { class: 'switch small' }, auto, el('span', {}, 'רענון אוטומטי'))),
      out);
    load();
  }

  // ── אכיפה ──
  async function force(box) {
    const { api, el, notice } = window.DT;
    let mode = 'document';
    const q = el('input', { type: 'search', placeholder: 'מספר מסמך, מזהה, מייל או שם הבעלים, ref', 'aria-label': 'חיפוש' });
    const deleted = el('input', { type: 'checkbox' });
    const out = el('div');
    const msg = el('div');
    const audit = el('div');
    let OPS = {};
    const seg = el('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'מה' },
      [['document', '🗂️ מסמכים'], ['template', '📄 תבניות']].map(([k, t]) => el('label', {},
        el('input', { type: 'radio', name: 'force-m', value: k, checked: mode === k, onchange: () => { mode = k; load(); } }), el('span', {}, t))));
    async function load() {
      const query = { q: q.value.trim() };
      if (deleted.checked) query.deleted = 1;
      try {
        if (mode === 'document') {
          const r = await api('dev_docs', undefined, query);
          put(out, r.documents.length ? el('ul', { class: 'list card-list' }, r.documents.map(d => el('li', { class: d.deleted ? 'archived' : '' },
            el('button', { type: 'button', class: 'use-row', onclick: () => act('document', d.id, `מסמך #${d.id}${d.number ? ' · ' + d.number : ''}`, d) },
              el('span', { class: 'use-ic', 'aria-hidden': 'true' }, d.locked ? '🔒' : '🗂️'),
              el('span', { class: 'use-tx' }, el('b', {}, `#${d.id}` + (d.number ? ' · ' + d.number : '')),
                el('small', {}, [d.owner, 'תבנית ' + d.template_id + ' v' + d.version, `${d.values} ערכים · ${d.participants} ממלאים · ${d.files} קבצים`,
                  d.api ? '🔑 API' + (d.ref ? ' ' + d.ref : '') : '', d.chain_ok ? '' : '⚠ שרשרת היומן שבורה'].filter(Boolean).join(' · '))),
              el('span', { class: 'badge ' + (d.deleted ? 'bad' : d.status === 'completed' ? 'ok' : '') }, d.deleted ? 'נמחק' : d.archived ? 'בארכיון' : STATUS_DOC[d.status] || d.status)))))
            : el('p', { class: 'empty' }, 'לא נמצאו מסמכים.'));
        } else {
          const r = await api('dev_tpls', undefined, query);
          put(out, r.templates.length ? el('ul', { class: 'list card-list' }, r.templates.map(t => el('li', { class: t.deleted ? 'archived' : '' },
            el('button', { type: 'button', class: 'use-row', onclick: () => act('template', t.id, `תבנית #${t.id}`, t) },
              el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📄'),
              el('span', { class: 'use-tx' }, el('b', {}, `#${t.id} · ${t.kind} · גרסה ${t.version}`),
                el('small', {}, [t.owner, t.documents + ' מסמכים', t.used ? 'בשימוש' : ''].filter(Boolean).join(' · '))),
              el('span', { class: 'badge ' + (t.deleted ? 'bad' : '') }, t.deleted ? 'נמחקה' : t.archived ? 'בארכיון' : 'פעילה')))))
            : el('p', { class: 'empty' }, 'לא נמצאו תבניות.'));
        }
      } catch (e) { put(out, notice(e.message, 'bad')); }
    }
    async function loadAudit() {
      try {
        const r = await api('dev_audit_list');
        OPS = r.ops;
        put(audit, r.audit.length ? el('ul', { class: 'list card-list' }, r.audit.map(a => el('li', {},
          el('span', { class: 'use-row' }, el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🛡️'),
            el('span', { class: 'use-tx' }, el('b', {}, `${OPS[a.type]?.[a.op] || a.op} — ${a.type === 'document' ? 'מסמך' : 'תבנית'} #${a.id}`),
              el('small', { class: 'wrap' }, `${when(a.at)} · ${a.by} · סיבה: ${a.reason}`))))))
          : el('p', { class: 'empty' }, 'עוד לא בוצעו פעולות אכיפה.'));
      } catch (e) { put(audit, notice(e.message, 'bad')); }
    }

    /** גיליון פעולה: בוחרים פעולה, כותבים סיבה, מקלידים את המזהה לאישור. */
    function act(type, id, title, row) {
      const { sheet, tile } = window.DT;
      const ops = Object.entries(OPS[type] || {}).filter(([op]) =>
        op === 'restore' ? row.deleted || row.archived : op === 'unlock' ? row.locked : op === 'delete' ? !row.deleted : true);
      const body = el('div', { class: 'field-sheet' });
      const dlg = sheet(title, body);
      const choose = () => put(body, 
        el('p', { class: 'hint' }, 'פעולת מפתח עוקפת את הכללים של המשתמשים. היא נרשמת ביומן הביקורת' + (type === 'document' ? ' וביומן המסמך עצמו' : '') + '.'),
        el('div', { class: 'tiles' }, ops.map(([op, label]) => tile(
          { delete: '🗑️', purge: '🔥', unlock: '🔓', revoke: '⛓️‍💥', restore: '♻️' }[op] || '•', label, () => confirmOp(op, label),
          { danger: ['delete', 'purge'].includes(op) }))));
      const confirmOp = (op, label) => {
        const reason = el('textarea', { rows: 2, maxlength: 300, placeholder: 'למשל: המשתמש ביקש למחוק מסמך שנשלח בטעות' });
        const conf = el('input', { dir: 'ltr', inputmode: 'numeric', placeholder: String(id), 'aria-label': 'המזהה לאישור' });
        const out2 = el('div');
        const go = el('button', { type: 'button', class: 'danger' }, 'ביצוע');
        go.onclick = async () => {
          go.disabled = true;
          try {
            const r = await api('dev_force', { type, id, op, reason: reason.value, confirm: conf.value });
            dlg.close();
            put(msg, notice(r.message, 'ok'));
            load(); loadAudit();
          } catch (e) { put(out2, notice(e.message, 'bad')); go.disabled = false; }
        };
        put(body, 
          el('p', {}, el('b', {}, label)),
          op === 'purge' ? notice('בלתי הפיך: הערכים, הקבצים ופרטי הממלאים נמחקים. יומן המסמך נשאר — כולל היסטוריית השינויים (מוצפנת), כי הוא "הוספה בלבד".', 'bad') : null,
          el('label', { class: 'f' }, el('span', {}, 'סיבה (חובה — נשמרת ביומן)'), reason),
          el('label', { class: 'f' }, el('span', {}, `לאישור — הקלידו ${id}`), conf),
          out2,
          el('div', { class: 'ops' }, go, el('button', { type: 'button', onclick: choose }, 'חזרה')));
        reason.focus();
      };
      choose();
    }

    q.addEventListener('change', load);
    deleted.addEventListener('change', load);
    put(box, 
      el('p', { class: 'hint' }, '🛡️ סמכות המפתח: לכפות פעולות שהמערכת חוסמת למשתמשים — מחיקת מסמך שמולא או הושלם, ביטול נעילה, ביטול קישורים, מחיקת תבנית שבשימוש, שחזור. רואים מטא-דאטה בלבד: מספר, בעלים, מצב, כמויות.'),
      seg,
      el('div', { class: 'toolbar' }, q, el('label', { class: 'switch small' }, deleted, el('span', {}, 'כולל מחוקים'))),
      msg, out,
      el('h3', { class: 'sec-title' }, '📜 יומן הביקורת — פעולות מפתח'), audit);
    await loadAudit();
    load();
  }

  // ── מצב המערכת ──
  async function health(box) {
    const { api, el, notice } = window.DT;
    let h;
    try { h = (await api('dev_health')).health; } catch (e) { put(box, notice(e.message, 'bad')); return; }
    const yes = (v, ok = 'תקין', bad = 'בעיה') => el('span', { class: 'badge ' + (v ? 'ok' : 'bad') }, v ? ok : bad);
    const cell = (label, v) => el('div', {}, el('dt', {}, label), el('dd', {}, v));
    put(box, 
      el('h3', { class: 'sec-title' }, 'השרת'),
      el('dl', { class: 'cells' },
        cell('PHP', h.php), cell('SQLite', h.sqlite), cell('הצפנה', yes(h.crypto)), cell('תיקיית הנתונים חסומה', yes(h.data_protected)),
        cell('כתיבה לתיקייה', yes(h.data_writable)), cell('HTTPS', yes(h.https, 'כן', 'לא')), cell('cURL (חיבורים)', yes(h.curl, 'יש', 'אין')),
        cell('ZIP (Word)', yes(h.zip, 'יש', 'אין')), cell('מייל', yes(h.mail, 'יש', 'אין'))),
      el('h3', { class: 'sec-title' }, 'נפחים'),
      el('dl', { class: 'cells' }, cell('מסד', h.db_mb + 'MB'), cell('קבצים', h.files_mb + 'MB'),
        cell('מקום פנוי', h.disk_free_mb === null ? '—' : h.disk_free_mb + 'MB')),
      el('h3', { class: 'sec-title' }, 'כמויות'),
      el('dl', { class: 'cells' }, cell('משתמשים פעילים', h.counts.users), cell('תבניות', h.counts.templates), cell('מסמכים', h.counts.documents),
        cell('הושלמו', h.counts.completed), cell('מפתחות API', h.counts.api_keys), cell('מקורות נתונים', h.counts.sources)),
      el('h3', { class: 'sec-title' }, '24 השעות האחרונות'),
      el('dl', { class: 'cells' }, cell('בקשות', h.day.requests),
        cell('שגיאות שרת', h.day.errors ? el('a', { href: '#dev?tab=log', class: 'badge bad' }, String(h.day.errors)) : yes(true, '0')),
        cell('שגיאות בדפדפן', h.day.js_errors ? el('a', { href: '#dev?tab=log', class: 'badge bad' }, String(h.day.js_errors)) : yes(true, '0'))),
      el('h3', { class: 'sec-title' }, 'שלמות היומנים'),
      h.chain_broken.length ? notice('⚠ שרשרת היומן שבורה במסמכים: ' + h.chain_broken.map(x => '#' + x).join(', '), 'bad')
        : notice('✓ שרשרת היומן שלמה בכל המסמכים שנבדקו (300 האחרונים).', 'ok'));
  }

  window.DTDev = { view };
})();
