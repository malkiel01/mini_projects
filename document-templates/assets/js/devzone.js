// אזור הפיתוח: משתמשים, פתוח/סגור, לוג מעקב, אכיפה, מצב המערכת.
// המפתח רואה מטא-דאטה בלבד — מספרים, מצבים, בעלים, תאריכים. לא תוכן.
(function () {
  'use strict';

  const TABS = [['users', '👥 משתמשים'], ['items', '📋 פתוח / סגור'], ['log', '🪵 לוג'], ['force', '🛡️ אכיפה'], ['health', '🩺 מצב']];
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

  // ── פתוח / סגור ──
  async function items(box) {
    const { api, el, notice } = window.DT;
    let r;
    try { r = await api('dev_items'); } catch (e) { put(box, notice(e.message, 'bad')); return; }
    const list = r.items || [];
    const KIND = { open: ['פתוח', 'warn', '🟡'], question: ['שאלה אליך', 'bad', '❓'], closed: ['סגור', 'ok', '✅'] };
    const counts = Object.fromEntries(Object.keys(KIND).map(k => [k, list.filter(x => x.status === k).length]));
    let filter = counts.question || counts.open ? 'pending' : 'all';
    const out = el('div');
    const seg = el('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'סינון' },
      [['pending', `פתוחים ושאלות (${counts.open + counts.question})`], ['closed', `סגורים (${counts.closed})`], ['all', 'הכול']].map(([k, t]) =>
        el('label', {}, el('input', { type: 'radio', name: 'items-f', value: k, checked: filter === k,
          onchange: () => { filter = k; render(); } }), el('span', {}, t))));
    const render = () => {
      const shown = list.filter(x => filter === 'all' || (filter === 'closed' ? x.status === 'closed' : x.status !== 'closed'));
      put(out, shown.length ? el('ul', { class: 'list card-list items' }, shown.map(x => {
        const [label, kind, ic] = KIND[x.status] || [x.status, '', '•'];
        return el('li', {}, el('details', { class: 'item' },
          el('summary', { class: 'use-row' },
            el('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
            el('span', { class: 'use-tx' }, el('b', {}, x.title),
              el('small', {}, [x.area, x.closed ? 'נסגר ' + x.closed : x.opened ? 'נפתח ' + x.opened : '', x.pr ? 'PR #' + x.pr : '']
                .filter(Boolean).join(' · '))),
            el('span', { class: 'badge ' + kind }, label)),
          el('div', { class: 'item-body' },
            x.detail ? el('p', {}, x.detail) : null,
            x.questions?.length ? el('div', { class: 'questions' }, el('b', {}, 'שאלות פתוחות:'),
              el('ul', {}, x.questions.map(q => el('li', {}, q)))) : null)));
      })) : el('p', { class: 'empty' }, 'אין פריטים.'));
    };
    put(box, 
      el('div', { class: 'summary' }, el('div', { class: 'line' },
        el('b', {}, String(counts.question)), el('span', { class: 'muted' }, 'שאלות אליך ·'),
        el('b', {}, String(counts.open)), el('span', { class: 'muted' }, 'פתוחים ·'),
        el('b', {}, String(counts.closed)), el('span', { class: 'muted' }, 'סגורים')),
        el('div', { class: 'line muted small' }, 'עודכן ' + (r.updated || '—'))),
      r.note ? el('p', { class: 'hint' }, r.note) : null,
      seg, out);
    render();
  }

  // ── לוג מעקב ──
  async function log(box) {
    const { api, el, notice } = window.DT;
    const errorsOnly = el('input', { type: 'checkbox' });
    const q = el('input', { type: 'search', placeholder: 'פעולה, או מזהה (id=12)', 'aria-label': 'חיפוש בלוג' });
    const auto = el('input', { type: 'checkbox', checked: true });
    const out = el('div');
    const stats = el('div', { class: 'summary' });
    const ACTOR = { user: '👤', api: '🔑', fill: '🔗', guest: '·' };
    async function load() {
      const query = {};
      if (errorsOnly.checked) query.errors = 1;
      const term = q.value.trim();
      if (/^\w+=\d+$/.test(term)) query.ref = term; else if (term) query.act = term;
      let r;
      try { r = await api('dev_log_list', undefined, query); } catch (e) { put(out, notice(e.message, 'bad')); return; }
      const d = r.day;
      put(stats, el('div', { class: 'line' }, el('span', { class: 'muted' }, '24 שעות:'),
        el('b', {}, String(d.n || 0)), el('span', { class: 'muted' }, 'בקשות ·'),
        d.e5 ? el('span', { class: 'badge bad' }, d.e5 + ' שגיאות שרת') : el('span', { class: 'badge ok' }, 'אין שגיאות שרת'),
        d.e4 ? el('span', { class: 'badge warn' }, d.e4 + ' נדחו (4xx)') : null,
        d.js ? el('span', { class: 'badge bad' }, d.js + ' שגיאות בדפדפן') : null,
        el('span', { class: 'muted' }, '· ממוצע ' + (d.avg_ms || 0) + 'ms')));
      put(out, r.rows.length ? el('div', { class: 'table-wrap' }, el('table', { class: 'sample log-table' },
        el('thead', {}, el('tr', {}, ['זמן', 'פעולה', 'מצב', 'ms', 'מי', 'מזהים', 'פירוט'].map(h => el('th', {}, h)))),
        el('tbody', {}, r.rows.map(x => el('tr', { class: x.action === 'client_error' || x.status >= 500 ? 'err' : x.status >= 400 ? 'warn' : '' },
          el('td', { dir: 'ltr' }, when(x.at)),
          el('td', { dir: 'ltr' }, (x.method || '') + ' ' + x.action),
          el('td', {}, x.action === 'client_error' ? 'JS' : String(x.status ?? '')),
          el('td', {}, String(x.ms)),
          el('td', { title: x.email || '' }, (ACTOR[x.actor] || '') + ' ' + (x.user || x.ip || '')),
          el('td', { dir: 'ltr' }, x.ref || ''),
          el('td', { class: 'wrap' }, [x.message, x.trace, x.page].filter(Boolean).join(' · '))))))) : el('p', { class: 'empty' }, 'אין רשומות.'));
    }
    errorsOnly.addEventListener('change', load);
    q.addEventListener('change', load);
    timer = setInterval(() => { if (auto.checked && document.body.contains(out)) load(); else clearInterval(timer); }, 15000);
    put(box, 
      el('p', { class: 'hint' }, 'כל בקשה לשרת: מתי, איזו פעולה, תוצאה, משך, מי (👤 משתמש · 🔑 API · 🔗 ממלא בקישור), מזהי המסמך/התבנית, והודעת השגיאה. שגיאות JavaScript מהדפדפן נרשמות גם הן. בלי ערכים ובלי תוכן. נשמר 30 יום.'),
      stats,
      el('div', { class: 'toolbar' }, q, el('label', { class: 'switch small' }, errorsOnly, el('span', {}, 'רק שגיאות')),
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
