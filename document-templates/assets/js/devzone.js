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

  // ── מטלות: קוד, שיח, וגישה ל-Claude ──
  const KIND = { open: ['פתוח', 'warn', '🟡'], question: ['שאלה אליך', 'bad', '❓'], closed: ['סגור', 'ok', '✅'] };
  const dayTime = (iso) => iso ? new Date(iso).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

  /** הטקסט שמדביקים לצ'אט — מטלות שממתינות ל-Claude (או כל הפתוחות). */
  function chatText(list) {
    const waiting = list.filter(x => x.waiting === 'claude');
    const pick = waiting.length ? waiting : list.filter(x => x.status !== 'closed');
    const head = waiting.length ? 'הערות שלי על מטלות — ממתינות לתשובה:' : 'המטלות הפתוחות:';
    return [head, ...pick.map(x => {
      const [label] = KIND[x.status] || [x.status];
      const thread = x.comments.map(c => `  ${c.author === 'claude' ? 'Claude' : 'אני'} (${dayTime(c.at)}): ${c.body}`);
      return [`\n${x.code} · ${x.title} [${label}]`, ...thread].join('\n');
    })].join('\n');
  }

  async function items(box) {
    const { api, el, notice, sheet } = window.DT;
    let list = [], meta = {};
    const params = new URLSearchParams(location.hash.split('?')[1] || '');
    let filter = 'pending', query = '', openCode = params.get('code') || '';
    const out = el('div');
    const stats = el('div', { class: 'summary' });
    const seg = el('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'סינון' });
    const search = el('input', { type: 'search', placeholder: 'קוד (DT-012) או מילה', 'aria-label': 'חיפוש מטלה',
      oninput: () => { query = search.value.trim(); render(); } });

    async function load(keep) {
      try { const r = await api('dev_items'); list = r.items || []; meta = r; }
      catch (e) { put(box, notice(e.message, 'bad')); return false; }
      if (!keep && openCode) { filter = 'all'; query = openCode; search.value = openCode; }
      return true;
    }
    const count = (fn) => list.filter(fn).length;
    const FILTERS = () => [
      ['pending', `פתוחים ושאלות (${count(x => x.status !== 'closed')})`],
      ['claude', `ממתינות ל-Claude (${count(x => x.waiting === 'claude')})`],
      ['closed', `סגורים (${count(x => x.status === 'closed')})`], ['all', 'הכול']];
    const pass = (x) => ({ pending: x.status !== 'closed', claude: x.waiting === 'claude', closed: x.status === 'closed', all: true })[filter];
    const match = (x) => !query || x.code.toLowerCase() === query.toLowerCase()
      || [x.code, x.title, x.area, x.detail, ...x.comments.map(c => c.body)].join(' ').toLowerCase().includes(query.toLowerCase());

    function thread(x) {
      const text = el('textarea', { rows: 2, maxlength: 4000, placeholder: 'הערה, תשובה לשאלה, או בקשה — Claude יענה כאן', 'aria-label': 'הערה ל-' + x.code });
      const msg = el('div');
      const send = el('button', { type: 'button', class: 'primary small', onclick: async () => {
        if (!text.value.trim()) return;
        send.disabled = true;
        try { await api('dev_comment', { code: x.code, body: text.value }); openCode = x.code; await load(true); render(); }
        catch (e) { put(msg, notice(e.message, 'bad')); send.disabled = false; }
      } }, 'שליחה');
      const last = x.comments.length ? x.comments[x.comments.length - 1] : null;
      return el('div', { class: 'thread' },
        el('b', {}, '💬 שיח'),
        x.comments.length ? el('ol', { class: 'comments' }, x.comments.map(c => el('li', { class: 'comment ' + c.author },
          el('div', { class: 'c-head' }, el('b', {}, c.author === 'claude' ? '🤖 Claude' : '👤 ' + c.name), el('small', { class: 'muted' }, dayTime(c.at)),
            c.author === 'user' && c === last ? el('button', { type: 'button', class: 'link small', onclick: async () => {
              try { await api('dev_comment_delete', { id: c.id }); openCode = x.code; await load(true); render(); }
              catch (e) { put(msg, notice(e.message, 'bad')); }
            } }, 'מחיקה') : null),
          el('p', { dir: 'auto' }, c.body)))) : el('p', { class: 'muted small' }, 'אין עדיין הערות.'),
        x.waiting === 'claude' ? el('p', { class: 'hint' }, '⏳ ממתין ל-Claude — תגידו לו "תקרא את המטלות", או "העתקה לצ\'אט" למעלה.') : null,
        msg, el('div', { class: 'c-new' }, text, send));
    }

    function statusPick(x) {
      if (x.source !== 'user') return null;
      const s = el('select', { 'aria-label': 'מצב', onchange: async () => {
        try { await api('dev_task_set', { code: x.code, status: s.value }); openCode = x.code; await load(true); render(); }
        catch (e) { alert(e.message); }
      } }, Object.entries(KIND).map(([k, [l]]) => el('option', { value: k, selected: x.status === k }, l)));
      return el('label', { class: 'f inline' }, el('span', {}, 'מצב'), s);
    }

    function render() {
      put(stats, el('div', { class: 'line' },
          el('b', {}, String(count(x => x.status === 'question'))), el('span', { class: 'muted' }, 'שאלות אליך ·'),
          el('b', {}, String(count(x => x.status === 'open'))), el('span', { class: 'muted' }, 'פתוחים ·'),
          el('b', {}, String(count(x => x.status === 'closed'))), el('span', { class: 'muted' }, 'סגורים ·'),
          el('b', {}, String(count(x => x.waiting === 'claude'))), el('span', { class: 'muted' }, 'ממתינות ל-Claude')),
        el('div', { class: 'line muted small' }, 'עודכן ' + (meta.updated || '—')));
      put(seg, FILTERS().map(([k, t]) => el('label', {}, el('input', { type: 'radio', name: 'items-f', value: k, checked: filter === k,
        onchange: () => { filter = k; render(); } }), el('span', {}, t))));
      const shown = list.filter(x => (query ? true : pass(x)) && match(x));
      put(out, shown.length ? el('ul', { class: 'list card-list items' }, shown.map(x => {
        const [label, kind, ic] = KIND[x.status] || [x.status, '', '•'];
        const d = el('details', { class: 'item', 'data-code': x.code, open: x.code === openCode },
          el('summary', { class: 'use-row' },
            el('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
            el('span', { class: 'use-tx' }, el('b', {}, el('code', { class: 'task-code', dir: 'ltr' }, x.code), ' ', x.title),
              el('small', {}, [x.area, x.closed ? 'נסגר ' + x.closed : x.opened ? 'נפתח ' + x.opened : '', x.pr ? 'PR #' + x.pr : '',
                x.source === 'user' ? 'נפתח מהמסך' : '', x.comments.length ? '💬 ' + x.comments.length : '']
                .filter(Boolean).join(' · '))),
            el('span', { class: 'badges' },
              x.waiting === 'claude' ? el('span', { class: 'badge warn' }, 'ממתין ל-Claude') : null,
              x.waiting === 'user' ? el('span', { class: 'badge info' }, 'Claude ענה') : null,
              el('span', { class: 'badge ' + kind }, label))),
          el('div', { class: 'item-body' },
            x.detail ? el('p', { dir: 'auto' }, x.detail) : null,
            x.questions?.length ? el('div', { class: 'questions' }, el('b', {}, 'שאלות פתוחות:'),
              el('ul', {}, x.questions.map(q => el('li', {}, q)))) : null,
            statusPick(x),
            thread(x)));
        d.addEventListener('toggle', () => { if (d.open) openCode = x.code; });
        return el('li', {}, d);
      })) : el('p', { class: 'empty' }, query ? 'לא נמצאה מטלה.' : 'אין פריטים.'));
      const target = openCode && out.querySelector(`details[data-code="${openCode}"]`);
      if (target) requestAnimationFrame(() => target.scrollIntoView({ block: 'nearest' }));
    }

    function newTask() {
      const title = el('input', { maxlength: 200, placeholder: 'מה צריך לעשות' });
      const area = el('input', { maxlength: 40, placeholder: 'למשל: עורך, מסמכים, אבטחה' });
      const detail = el('textarea', { rows: 4, maxlength: 4000, placeholder: 'פירוט (לא חובה)' });
      const msg = el('div');
      const go = el('button', { type: 'button', class: 'primary small', onclick: async () => {
        go.disabled = true;
        try {
          const r = await api('dev_task_create', { title: title.value, area: area.value, detail: detail.value });
          dlg.close(); openCode = r.code; filter = 'pending'; query = ''; search.value = '';
          await load(true); render();
        } catch (e) { put(msg, notice(e.message, 'bad')); go.disabled = false; }
      } }, 'פתיחה');
      const dlg = sheet('מטלה חדשה', el('div', { class: 'field-sheet' }, msg,
        el('label', { class: 'f' }, el('span', {}, 'כותרת'), title),
        el('label', { class: 'f' }, el('span', {}, 'תחום'), area),
        el('label', { class: 'f' }, el('span', {}, 'פירוט'), detail),
        el('p', { class: 'hint' }, 'המטלה מקבלת קוד מיד. Claude יראה אותה בפעם הבאה שיקרא את המטלות.')), { action: go });
      title.focus();
    }

    function copyChat() {
      const text = chatText(list);
      const area = el('textarea', { rows: 12, readonly: true, dir: 'auto', 'aria-label': 'הטקסט להדבקה' }, text);
      const b = el('button', { type: 'button', class: 'primary small', onclick: async () => {
        try { await navigator.clipboard.writeText(text); b.textContent = '✓ הועתק'; }
        catch (e) { area.select(); b.textContent = 'סמנו והעתיקו'; }
      } }, 'העתקה');
      sheet('העתקה לצ\'אט', el('div', { class: 'field-sheet' },
        el('p', { class: 'hint' }, 'בלי שום חיבור: מעתיקים, ומדביקים ל-Claude בצ\'אט. את התשובות שלו הוא יכתוב בקוד, או שתדביקו אותן כאן כהערה.'),
        area), { action: b });
    }

    async function tokens() {
      const listBox = el('div'), msg = el('div');
      const origin = location.href.split('#')[0].replace(/[^/]*$/, '') + 'api.php';
      async function show() {
        let r;
        try { r = await api('dev_token_list'); } catch (e) { put(msg, notice(e.message, 'bad')); return; }
        put(listBox, r.tokens.length ? el('ul', { class: 'list' }, r.tokens.map(t => el('li', { class: 'wrap' },
          el('span', {}, el('b', {}, t.label), el('small', { class: 'muted' },
            ` · ${t.by} · נוצר ${when(t.created_at)} · שימוש אחרון: ${t.last_used_at ? when(t.last_used_at) : 'עוד לא'}`)),
          t.revoked ? el('span', { class: 'badge' }, 'בוטל') : el('button', { type: 'button', class: 'small danger', onclick: async () => {
            if (!confirm('לבטל את האסימון? Claude לא יוכל לקרוא איתו יותר.')) return;
            try { await api('dev_token_revoke', { id: t.id }); show(); } catch (e) { put(msg, notice(e.message, 'bad')); }
          } }, 'ביטול')))) : el('p', { class: 'muted small' }, 'אין אסימונים.'));
      }
      const create = el('button', { type: 'button', class: 'primary small', onclick: async () => {
        try {
          const r = await api('dev_token_create', { label: 'Claude' });
          const say = `האסימון לקריאת המטלות: ${r.token}\nהכתובת: ${origin}?action=devapi_feed (בכותרת X-Dev-Token)`;
          const b = el('button', { type: 'button', class: 'small', onclick: async () => {
            try { await navigator.clipboard.writeText(say); b.textContent = '✓ הועתק'; } catch (e) { b.textContent = 'סמנו והעתיקו'; }
          } }, 'העתקה');
          put(msg, notice('✓ נוצר. העתיקו עכשיו — הוא לא יוצג שוב.', 'ok'),
            el('label', { class: 'f' }, el('span', {}, 'האסימון'), el('code', { class: 'secret', dir: 'ltr' }, r.token)),
            el('label', { class: 'f' }, el('span', {}, 'הכתובת (בכותרת X-Dev-Token)'), el('code', { class: 'secret', dir: 'ltr' }, origin + '?action=devapi_feed')),
            el('p', { class: 'hint' }, 'ההעתקה לוקחת את שניהם יחד — מדביקים ל-Claude כמו שזה.'), b);
          show();
        } catch (e) { put(msg, notice(e.message, 'bad')); }
      } }, '+ אסימון חדש');
      sheet('🔑 גישה ל-Claude', el('div', { class: 'field-sheet' },
        el('p', { class: 'hint' }, 'אסימון אישי שנותנים ל-Claude פעם אחת (בצ\'אט, או כסוד בהגדרות הסביבה שלו). איתו הוא קורא את המטלות ואת ההערות, ועונה בשיח — ולא שום דבר אחר במערכת. כשאומרים לו "תקרא את המטלות" — הוא קורא.'),
        msg, listBox), { action: create });
      show();
    }

    if (!await load(false)) return;
    put(box, stats,
      meta.note ? el('p', { class: 'hint' }, meta.note) : null,
      el('div', { class: 'items-bar' },
        el('button', { type: 'button', class: 'primary small', onclick: newTask }, '+ מטלה חדשה'),
        el('button', { type: 'button', class: 'small', onclick: copyChat }, '📋 העתקה לצ\'אט'),
        el('button', { type: 'button', class: 'small', onclick: tokens }, '🔑 גישה ל-Claude')),
      search, seg, out);
    render();
  }

  // ── לוג מעקב ──
  async function log(box) {
    const { api, el, notice } = window.DT;
    const errorsOnly = el('input', { type: 'checkbox' });
    const q = el('input', { type: 'search', placeholder: 'פעולה, או מזהה (id=12)', 'aria-label': 'חיפוש בלוג' });
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
            [(ACTOR[x.actor] || '') + ' ' + (x.user || x.ip || 'אורח'), x.ref ? el('bdi', { dir: 'ltr' }, x.ref) : null, js ? null : x.ms + 'ms', x.method]
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
      el('p', { class: 'hint' }, 'כל בקשה לשרת: מתי, איזו פעולה, תוצאה, משך, מי (👤 משתמש · 🔑 API · 🔗 ממלא בקישור), מזהי המסמך/התבנית, והודעת השגיאה. שגיאות JavaScript מהדפדפן נרשמות גם הן. בלי ערכים ובלי תוכן. נשמר 30 יום.'),
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
