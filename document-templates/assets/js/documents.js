// מסמכים — שלב 4: רשימה, מילוי (על המסמך או כטופס), שמירה אוטומטית, יומן, סיום ו-PDF.
(function () {
  'use strict';

  const PDFLIB = 'https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js';
  const FONTKIT = 'https://unpkg.com/@pdf-lib/fontkit@1.1.1/dist/fontkit.umd.min.js';
  const FONT = 'assets/fonts/Alef-Regular.ttf';

  const STATUS = {
    draft: ['טיוטה', 'muted'], in_progress: ['בתהליך', 'warn'], completed: ['הושלם', 'ok'],
    sent: ['נשלח', 'warn'], locked: ['נעול', 'ok'], expired: ['פג תוקף', 'bad'],
    cancelled: ['בוטל', 'bad'], declined: ['נדחה', 'bad'],
  };
  const EVENTS = {
    dev_override: '🛡️ פעולת מפתח', webhook: 'הודעה למערכת חיצונית', api_issued: 'הונפק דרך API',
    created: 'נוצר', values_saved: 'נשמרו ערכים', completed: 'הושלם ונעול', archived: 'הועבר לארכיון',
    restored: 'שוחזר מהארכיון', downloaded: 'הורד PDF', deleted: 'נמחק',
    link_issued: 'נשלח קישור', opened: 'נפתח', verified: 'אומת בקוד', participant_completed: 'משתתף סיים',
    file_uploaded: 'צורף קובץ', shared: 'שותף', unshared: 'שיתוף הוסר', contact_set: 'נקבע איש קשר', tags_set: 'עודכנו תגיות',
    imported: 'נוצר מאקסל', exported: 'יוצא לאקסל',
    awaiting_approval: '⏳ ממתין לאישור', approved: '✓ אושר ונעול', returned: '↩ הוחזר לתיקון', submitted: '📨 נשלח לאישור',
  };
  const VIA = { email: 'במייל', link: 'כקישור', whatsapp: 'בוואטסאפ' };
  const EDITABLE = ['draft', 'in_progress'];
  const AUTHORED = 'application/x-dt-authored';
  const PH = /\{\{\s*([^{}]{1,60}?)\s*\}\}/g;

  const fmtDate = (iso) => iso ? new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }) : '—';
  const ddmmyyyy = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v) ? v.split('-').reverse().join('/') : v;
  /** שדה שמתמלא לבד: תאריכים — בפורמט ישראלי, השאר כמו שהוא. */
  const sysText = (f, v) => ['issued', 'completed'].includes(f.source) ? ddmmyyyy(v) : v;
  /** ערך מחושב / מתמלא לבד — לתצוגה: המבנה המותאם של השדה, ואם אין — ברירת המחדל. */
  const calcText = (f, v) => window.DTFmt.apply(f, v) ?? window.DTCalc.display(v, f.format);
  const sysShown = (f, v) => window.DTFmt.apply(f, v) ?? sysText(f, v);
  /** בחירה מרשימה: מה שמוצג ומודפס — התווית. */
  const optText = (f, v) => (f.options || []).find(o => o.value === v)?.label ?? v;

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      if (document.querySelector(`script[src="${src}"]`)) return resolve();
      const s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = () => reject(new Error('טעינת רכיב נכשלה. בדקו חיבור לרשת'));
      document.head.append(s);
    });
  }

  // ── רשימת המסמכים ─────────────────────────────────────────

  // רשימת המסמכים: כל הסינון בשרת (שם הערכים מפוענחים). תצוגות שמורות — בדפדפן.
  async function list() {
    const { api, el, notice, show } = window.DT;
    const params = new URLSearchParams(location.hash.split('?')[1] || '');
    const box = el('div', {}, el('p', { class: 'muted' }, 'טוען…'));
    const msg = el('div');
    const count = el('span', { class: 'muted small' });
    const f = {
      q: el('input', { type: 'search', placeholder: 'חיפוש: מספר, כותרת, ערך, איש קשר', class: 'search', 'aria-label': 'חיפוש מסמכים' }),
      template_id: el('select', {}, el('option', { value: '' }, 'כל התבניות')),
      status: el('select', {}, el('option', { value: '' }, 'כל המצבים'),
        ['draft', 'sent', 'in_progress', 'completed'].map(s => el('option', { value: s }, STATUS[s][0]))),
      tag: el('select', {}, el('option', { value: '' }, 'כל התגיות')),
      contact_id: el('select', {}, el('option', { value: '' }, 'כל אנשי הקשר')),
      by: el('select', {}, el('option', { value: '' }, 'שלי ומשותפים'), el('option', { value: 'me' }, 'שלי'),
        el('option', { value: 'shared' }, 'ששותפו איתי')),
      from: el('input', { type: 'date', 'aria-label': 'מתאריך' }),
      to: el('input', { type: 'date', 'aria-label': 'עד תאריך' }),
      archived: el('input', { type: 'checkbox' }),
    };
    const views = el('select', { 'aria-label': 'תצוגות שמורות' });
    const VKEY = 'dt-doc-views';
    const readViews = () => { try { return JSON.parse(localStorage.getItem(VKEY) || '{}'); } catch (e) { return {}; } };
    const current = () => Object.fromEntries(Object.entries(f).map(([k, n]) => [k, n.type === 'checkbox' ? (n.checked ? '1' : '') : n.value])
      .filter(([, v]) => v));
    function fillViews() {
      const v = readViews();
      // replaceChildren לא משטח מערכים ומדפיס null — לכן spread ו-filter.
      views.replaceChildren(...[el('option', { value: '' }, '⭐ תצוגות שמורות'),
        ...Object.keys(v).map(n => el('option', { value: n }, n)),
        el('option', { value: '__save' }, '+ שמירת הסינון הנוכחי…'),
        Object.keys(v).length ? el('option', { value: '__del' }, '✕ מחיקת תצוגה…') : null].filter(Boolean));
    }
    views.addEventListener('change', () => {
      const v = readViews();
      if (views.value === '__save') {
        const name = prompt('שם לתצוגה:');
        if (name) { v[name] = current(); try { localStorage.setItem(VKEY, JSON.stringify(v)); } catch (e) {} }
      } else if (views.value === '__del') {
        const name = prompt('איזו תצוגה למחוק?\n' + Object.keys(v).join('\n'));
        if (name && v[name]) { delete v[name]; try { localStorage.setItem(VKEY, JSON.stringify(v)); } catch (e) {} }
      } else if (v[views.value]) {
        for (const [k, n] of Object.entries(f)) {
          const val = v[views.value][k] || '';
          if (n.type === 'checkbox') n.checked = !!val; else n.value = val;
        }
        load();
      }
      fillViews();
    });

    const ICON = { draft: '📝', sent: '📨', in_progress: '✏️', completed: '✅' };
    function render(docs) {
      count.textContent = docs.length ? docs.length + ' מסמכים' : '';
      const n = Object.keys(current()).filter(k => k !== 'q').length;
      filterBtn.replaceChildren('⚙️', n ? el('span', { class: 'badge-n' }, String(n)) : '');
      if (!docs.length) {
        box.replaceChildren(el('p', { class: 'empty' }, Object.keys(current()).length ? 'אין מסמכים שמתאימים לסינון.'
          : 'עוד לא הונפקו מסמכים. ב"תבניות" לוחצים "+ מסמך" ליד תבנית.'));
        return;
      }
      box.replaceChildren(el('ul', { class: 'list docs' }, docs.map(d => {
        const [label, kind] = STATUS[d.status] || [d.status, ''];
        const sub = [d.template, d.contact, d.owner_name ? 'של ' + d.owner_name : null, fmtDate(d.created_at)].filter(Boolean).join(' · ');
        return el('li', { class: d.archived ? 'archived' : '' },
          el('a', { class: 'use-row', href: '#doc=' + d.id },
            el('span', { class: 'use-ic', 'aria-hidden': 'true' }, ICON[d.status] || '📄'),
            el('span', { class: 'use-tx' },
              el('span', { class: 'l1' }, el('b', { class: 'doc-title' }, d.title), el('span', { class: 't', dir: 'ltr' }, d.number || '')),
              el('small', {}, sub),
              d.required && d.status !== 'completed' ? el('span', { class: 'mini-track', title: `שדות חובה: ${d.filled} מתוך ${d.required}` },
                el('span', { style: `width:${Math.round(d.filled / d.required * 100)}%` })) : null,
              window.DTCatalog.chips(d.tags)),
            el('span', { class: 'badge ' + kind }, label)));
      })));
    }

    // סינון — בגיליון; בראש המסך רק חיפוש וכפתור
    const filterBtn = el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סינון', title: 'סינון', onclick: () => {
      window.DT.sheet('סינון מסמכים', [
        el('div', { class: 'fields' },
          el('label', { class: 'f' }, el('span', {}, 'תבנית'), f.template_id),
          el('label', { class: 'f' }, el('span', {}, 'מצב'), f.status),
          el('label', { class: 'f' }, el('span', {}, 'תגית'), f.tag),
          el('label', { class: 'f' }, el('span', {}, 'איש קשר'), f.contact_id),
          el('label', { class: 'f' }, el('span', {}, 'של מי'), f.by)),
        el('div', { class: 'fields two' },
          el('label', { class: 'f' }, el('span', {}, 'מתאריך'), f.from),
          el('label', { class: 'f' }, el('span', {}, 'עד תאריך'), f.to)),
        el('label', { class: 'switch' }, f.archived, el('span', {}, 'כולל ארכיון')),
        el('label', { class: 'f' }, el('span', {}, 'תצוגות שמורות'), views),
        el('button', { type: 'button', class: 'ghost', onclick: () => {
          for (const [k, n] of Object.entries(f)) { if (k === 'q') continue; if (n.type === 'checkbox') n.checked = false; else n.value = ''; }
          load();
        } }, 'ניקוי הסינון'),
      ], { action: el('button', { type: 'button', class: 'primary small', onclick: (e) => e.target.closest('dialog').close() }, 'הצגה') });
    } }, '⚙️');

    let seq = 0;
    async function load() {
      const my = ++seq;
      try {
        const r = await api('doc_list', undefined, current());
        if (my !== seq) return;   // תשובה ישנה שהגיעה אחרי חדשה
        // אפשרויות הסינון נבנות מהתוצאות (תבניות, תגיות) — בלי לאבד את הבחירה הנוכחית.
        const keep = (sel, items, first) => {
          const cur = sel.value;
          sel.replaceChildren(el('option', { value: '' }, first), ...items.map(([v, t]) => el('option', { value: v, selected: String(v) === cur }, t)));
        };
        if (!f.template_id.value) keep(f.template_id, [...new Map(r.documents.map(d => [d.template_id, d.template]))], 'כל התבניות');
        keep(f.tag, [...new Set([...r.tags, f.tag.value].filter(Boolean))].map(t => [t, t]), 'כל התגיות');
        render(r.documents);
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    // בחירות שמגיעות מהכתובת: #docs?template=5 / ?contact=7
    if (params.get('template')) f.template_id.append(el('option', { value: params.get('template'), selected: true }, 'התבנית הנבחרת'));
    if (params.get('tag')) f.tag.append(el('option', { value: params.get('tag'), selected: true }, params.get('tag')));
    try {
      const cs = await window.DTCatalog.contacts();
      f.contact_id.append(...cs.map(c => el('option', { value: c.id, selected: String(c.id) === params.get('contact') }, c.name)));
    } catch (e) { /* בלי אנשי קשר — בלי הסינון הזה */ }

    let t = null;
    f.q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(load, 300); });
    for (const [k, n] of Object.entries(f)) if (k !== 'q') n.addEventListener('change', load);
    fillViews();
    window.DT.setTab('docs');
    // שלב 12: ייצוא לאקסל — של תבנית אחת (לכל תבנית שדות אחרים), לפי הסינון
    const exportBtn = el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'ייצוא לאקסל', title: 'ייצוא לאקסל', onclick: async () => {
      if (!f.template_id.value) { msg.replaceChildren(notice('בחרו תבנית בסינון (⚙️) — הייצוא הוא של תבנית אחת', 'bad')); return; }
      msg.replaceChildren();
      try { await window.DTBulk.exportFile(current()); }
      catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    } }, '📤');
    show(el('section', { class: 'screen' },
      el('div', { class: 'toolbar' }, f.q, filterBtn, exportBtn, el('a', { class: 'btn small', href: '#shares' }, '📨 שיתופים')),
      el('p', { class: 'hint' }, count),
      msg, box));
    await load();
  }

  async function issue(templateId, share) {
    const { api } = window.DT;
    const r = await api('doc_create', { template_id: templateId });
    // שליחה למילוי: המסמך נפתח ישר בבחירה "מי ממלא מה"
    if (share) try { sessionStorage.setItem('dt-share-setup', String(r.id)); } catch (e) { /* אחסון חסום */ }
    location.hash = 'doc=' + r.id;
  }

  // ── שיתופים: כל מה שנשלח למילוי בקישור, ומה המצב ──
  const SHARE_ST = { waiting: ['לא נשלח', 'muted'], sent: ['נשלח', ''], opened: ['נפתח', 'warn'], filled: ['מילא', 'ok'],
    awaiting: ['⏳ ממתין לאישורך', 'warn'], done: ['✓ הושלם', 'ok'] };
  async function shares() {
    const { api, el, notice, show } = window.DT;
    window.DT.setTab('docs');
    show(el('p', { class: 'muted' }, 'טוען…'));
    let list;
    try { list = (await api('fill_shares')).shares; } catch (e) { show(notice(e.message, 'bad')); return; }
    const filt = el('select', { 'aria-label': 'סינון לפי מצב' }, el('option', { value: '' }, 'כל המצבים'),
      Object.entries(SHARE_ST).map(([k, [t]]) => el('option', { value: k }, t)));
    const box = el('div');
    const when = (s) => s ? new Date(s).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
    const paint = () => {
      const rows = list.filter(s => !filt.value || s.status === filt.value);
      box.replaceChildren(rows.length ? el('ul', { class: 'list card-list shares-list' }, rows.map(s => el('li', {},
        el('a', { class: 'use-row', href: '#doc=' + s.doc_id },
          el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📨'),
          el('span', { class: 'use-tx' }, el('b', {}, (s.name || s.role) + (s.name && s.role !== 'לקוח' ? ' · ' + s.role : '')),
            el('small', {}, [s.template, s.number, s.filled_at ? 'מילא ' + when(s.filled_at) : s.opened_at ? 'פתח ' + when(s.opened_at) : s.sent_at ? 'נשלח ' + when(s.sent_at) : 'נוצר ' + when(s.created_at)].filter(Boolean).join(' · '))),
          el('span', { class: 'badge ' + SHARE_ST[s.status][1] }, SHARE_ST[s.status][0])))))
        : el('p', { class: 'empty' }, list.length ? 'אין שיתופים במצב הזה.' : 'עוד לא נשלח שום טופס למילוי. מתבנית: ⋯ ← "📨 שליחה למילוי".'));
    };
    filt.addEventListener('change', paint);
    paint();
    show(el('div', { class: 'toolbar' }, el('h1', { class: 'grow' }, '📨 שיתופים למילוי'), filt,
      el('a', { class: 'btn small', href: '#docs' }, '🗂️ כל המסמכים')),
      el('p', { class: 'hint' }, 'כל טופס ששלחתם למילוי בקישור — למי, ממה, ומה המצב. לחיצה פותחת את המסמך.'), box);
  }

  // ── מסמך ─────────────────────────────────────────────────

  /**
   * מסך המסמך. שני מצבים:
   *   - מנהל הטופס (ברירת מחדל): כל המסמך, יומן, משתתפים, סיום ו-PDF.
   *   - ממלא חיצוני (opts.fill): אותו מסך, אבל עורך רק את השדות של התפקיד
   *     שלו, בלי יומן ובלי פרטי משתתפים. ה-api שלו מוסיף את האסימון לכל בקשה.
   */
  async function open(id, opts = {}) {
    const { api, el, notice, show } = window.DT;
    const fill = !!opts.fill;
    const src = fill ? {
      get: () => api('fill_get'),
      save: (b) => api('fill_save', { values: b.values }),
      asset: ['api.php?action=fill_asset', { 'X-Fill-Token': opts.token }],
    } : {
      get: () => api('doc_get', undefined, { id }),
      save: (b) => api('doc_save', b),
      asset: ['api.php?action=doc_asset&id=' + id, {}],
    };
    window.DT.setTab('docs');
    show(el('p', { class: 'muted' }, 'טוען מסמך…'));
    window.DT.wide();

    let doc;
    try {
      doc = (await src.get()).document;
    } catch (e) {
      show(notice(e.message, 'bad'), fill ? null : el('p', {}, el('a', { href: '#docs' }, 'חזרה למסמכים')));
      return;
    }

    const values = { ...doc.values };
    const pending = {};
    let timer = null;
    let inflight = null;
    let mode = 'page';
    try { if (localStorage.getItem('dt-fill-mode') === 'form') mode = 'form'; } catch (e) { /* אחסון חסום — ברירת מחדל */ }
    // בטלפון, ממלא חיצוני מתחיל בטופס — נוח יותר מהקלדה על דף מוקטן.
    if (fill && window.innerWidth < 640) mode = 'form';
    // שלב 9: מסמך מתבנית Word — אין "על המסמך" (אין דפים ממוקמים). ממלאים כטופס,
    // מורידים Word ממולא, ורואים תצוגה מקדימה.
    const isWord = doc.mime === window.DTEditor.DOCX;
    if (isWord) mode = 'form';
    // שלב 11: נכתב בכלי — "על המסמך" הוא הטקסט עצמו, עם השדות בתוכו
    const isAuthored = doc.mime === AUTHORED;
    const C = window.DTCalc;
    // שלב 7: מי שהמסמך שותף איתו — לפי הרמה. ממלא+ ממלא, עורך+ מסיים ושולח, מנהל-שותף+ משתף.
    const lvl = () => fill ? 0 : window.DT.rank(doc.access || 'owner');
    const editable = () => fill ? !doc.me.locked && !doc.me.blocked : EDITABLE.includes(doc.status) && lvl() >= 2;
    // שדה שאפשר לערוך עכשיו: אצל הממלא — רק שלו. אצל המנפיק — כל שדה, חוץ
    // משדות של משתתף שכבר נשלח אליו קישור.
    // שדה עם כמה ממלאים ("או פלוני או אלמוני") — כל אחד מהם יכול
    const rolesOf = (f) => f.roles && f.roles.length ? f.roles : [f.role || 'owner'];
    const canEdit = (f) => {
      if (!editable()) return false;
      const roles = rolesOf(f);
      if (fill) return roles.includes(doc.me.role_key);
      if (roles.includes('owner')) return true;
      return !(doc.participants || []).some(p => roles.includes(p.role_key) && p.notified_at);
    };
    /** "או: לקוח, ערב" — מי עוד יכול למלא את השדה (חוץ ממני). */
    const othersText = (f) => {
      const roles = rolesOf(f);
      if (roles.length < 2) return '';
      const me = fill ? doc.me.role_key : 'owner';
      const names = roles.filter(r => r !== me).map(r => r === 'owner' ? (fill ? 'השולח' : 'אני')
        : ((doc.schema.roles || []).find(x => x.key === r)?.label || r));
      return names.length ? 'אפשר שימלא גם: ' + names.join(' או ') : '';
    };
    const fields = doc.schema.fields;
    const byKey = Object.fromEntries(fields.map(f => [f.key, f]));
    // רשימות ערכים: מה כבר נבחר ברמות של אותה רשימה — בשדות, ובשורה של טבלה. שינוי — מרענן את התלויים
    const listKnown = (id, row, tbl) => {
      const k = {};
      for (const x of fields) if (x.list && x.list.id === id && values[x.key]) k[x.list.level] = values[x.key];
      if (row && tbl) for (const c of tbl.columns || []) if (c.list && c.list.id === id && row[c.key]) k[c.list.level] = row[c.key];
      return k;
    };
    const listRefresh = [];
    // רשימה גדולה: האפשרויות מגיעות מהשרת (לפי מה שנבחר ברמות שמעל); כשמגיעות — מציירים שוב
    bigLists.cache.clear();
    bigLists.fetch = (ref, known) => api('list_opts', { doc: fill ? undefined : id, list: ref.id, level: ref.level, known }).then(r => r.values);
    bigLists.done = () => listRefresh.forEach(fn => fn());
    const byPh = Object.fromEntries(fields.filter(f => f.placeholder).map(f => [f.placeholder, f]));
    // סדר הטופס: לפי דף, ואז מלמעלה למטה, ואז מימין לשמאל.
    const ordered = [...fields].sort((a, b) => a.page - b.page || (a.y - b.y > 0.01 ? 1 : b.y - a.y > 0.01 ? -1 : b.x - a.x));

    const title = el('input', { class: 'tpl-name', value: doc.title, maxlength: 200, 'aria-label': 'כותרת המסמך' });
    const saveStatus = el('span', { class: 'save-status small' });
    const statusBadge = el('span', { class: 'badge' });
    const progress = el('span', { class: 'small muted' });
    const stage = el('div', { class: 'doc-stage' });   // מסלול אישור: ממתין לאישור / הוחזר / אושר — ולממלא: הורדה
    const msg = el('div');
    const body = el('div', { class: 'doc-body' });
    const journal = el('aside', { class: 'panel card journal', hidden: true });
    // תצוגה: על המסמך / טופס — בורר מקטעים בשורה אחת
    const modeSeg = el('fieldset', { class: 'seg mode-seg', 'aria-label': 'תצוגת המילוי' },
      el('label', {}, el('input', { type: 'radio', name: 'dt-mode', value: 'page' }), el('span', {}, '📄 על המסמך')),
      el('label', {}, el('input', { type: 'radio', name: 'dt-mode', value: 'form' }), el('span', {}, '☰ טופס')));
    modeSeg.addEventListener('change', (e) => {
      mode = e.target.value;
      try { localStorage.setItem('dt-fill-mode', mode); } catch (err) { /* אחסון חסום */ }
      renderBody();
    });
    const completeBtn = el('button', { class: 'primary', onclick: () => fill ? fillDone() : waitsForOther() ? submit() : complete() }, fill ? 'סיימתי ✓' : 'סיום ונעילה');
    // התבנית מחייבת אישור של בעליה, ומישהו אחר הנפיק: המנפיק שולח לאישור — לא נועל
    const waitsForOther = () => !!(doc.approver && !doc.approver.me);
    // כל פעולה — אייקון ומילה (אייקון לבד לא אומר מה הוא עושה); הפירוט המלא — בריחוף ולקורא מסך
    const ib = (icon, word, label, onclick) => el('button', { type: 'button', class: 'act-btn', 'aria-label': label, title: label, onclick },
      el('span', { 'aria-hidden': 'true' }, icon), el('span', { class: 'act-word' }, word));
    const pdfBtn = ib('⬇️', 'PDF', 'הורדת PDF', downloadPdf);
    // הורדה / הדפסה: מסמך שננעל רק באישור של בעל התבנית — רק אחרי שאושר
    const outBtns = [];
    const outBtn = (b) => { if (b) outBtns.push(b); return b; };
    const outLocked = () => waitsForOther() && doc.status !== 'completed';
    const track = el('span', { class: 'track', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100 }, el('span'));
    const people = el('aside', { class: 'panel card people', hidden: true });
    const meta = el('div', { class: 'doc-meta' });
    // לוח צד (יומן / שליחה למילוי): בטלפון הוא מתחת לדף — גוללים אליו כשנפתח
    const side = (panel, other) => {
      panel.hidden = !panel.hidden; other.hidden = true;
      if (!panel.hidden) requestAnimationFrame(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    };

    show(
      // סיכום קודם: מספר, מצב, שמירה, כותרת, והתקדמות שדות החובה
      el('div', { class: 'summary doc-head' },
        el('div', { class: 'line' }, el('strong', { dir: 'ltr', class: 'doc-number' }, doc.number || ''), statusBadge,
          el('span', { class: 'spacer' }), saveStatus),
        title, track, progress),
      stage,
      fill ? null : meta,
      !fill && doc.owner_name ? el('p', { class: 'notice' }, `👥 מסמך של ${doc.owner_name} — ההרשאה שלך: ${window.DT.LEVEL[doc.access]}.`) : null,
      fill && doc.me ? el('p', { class: 'notice' }, `שלום${doc.me.name ? ' ' + doc.me.name : ''} — ממלאים כאן את השדות של "${doc.me.role}". ` +
        'השדות שלך מסומנים במסגרת; את השאר רואים לקריאה בלבד.') : null,
      el('div', { class: 'toolbar doc-actions' },
        modeSeg,
        el('div', { class: 'icons' },
          fill ? null : ib('📜', 'יומן', 'יומן', () => { side(journal, people); renderJournal(); }),
          // שליחה למילוי — תמיד גלוי: יש ממלא בקישור → הקישור שלו; אין → הסבר איך מוסיפים
          fill || lvl() < 3 ? null : ib('🔗', 'שליחה למילוי', 'משתתפים', openFill),
          !fill && lvl() >= 4 ? ib('📤', 'שיתוף', 'שיתוף', () => window.DT.shareDialog('document', id, doc.title, reload, { fill: lvl() >= 3 ? openFill : null })) : null,
          !fill && doc.access && doc.access !== 'owner' ? ib('🚪', 'עזיבה', 'עזיבה', () => window.DT.leave('document', id, doc.title)) : null,
          fill ? null : ib('📑', 'שכפול', 'שכפול — מסמך חדש עם אותם ערכים', duplicate),
          fill || isWord || isAuthored ? null : outBtn(pdfBtn),
          !fill && doc.status === 'completed' && doc.final_hash ? ib('🧾', 'דף אישור', 'דף אישור', downloadCertificate) : null,
          !fill && (isWord || isAuthored) ? outBtn(ib('⬇️', 'Word', 'הורדת Word', () => wordFile(false))) : null,
          (isWord || isAuthored) && !fill ? outBtn(ib('👁️', 'תצוגה', 'תצוגה מקדימה', () => wordFile(true))) : null,
          isAuthored && !fill ? outBtn(ib('🖨️', 'הדפסה', 'הדפסה / PDF', printDoc)) : null),
        completeBtn),
      msg,
      el('div', { class: 'editor' }, body, journal, people));

    if (!fill) window.DT.setBack('docs');   // חזרה בכותרת — לרשימת המסמכים
    try {
      if (!fill && sessionStorage.getItem('dt-share-setup') === String(id)) { sessionStorage.removeItem('dt-share-setup'); setTimeout(shareSetup, 300); }
    } catch (e) { /* אחסון חסום */ }

    // שלב 8: איש קשר (רק לבעלים — הכרטיסים פרטיים) ותגיות (ממלא ומעלה, גם במסמך נעול).
    async function renderMeta() {
      if (fill) return;
      const parts = [];
      if (doc.access === 'owner') {
        const picker = await window.DTCatalog.contactPicker(doc.contact_id, async (c) => {
          try {
            const filled = Object.values(values).some(v => v) ? confirm('למלא גם שדות שכבר מלאים מכרטיס איש הקשר?') : false;
            const r = await api('doc_contact', { id, contact_id: c ? c.id : 0, overwrite: filled });
            Object.assign(values, r.values || {});
            msg.replaceChildren(notice(c ? `קושר ל${c.name}` + (r.filled ? ` · מולאו ${r.filled} שדות` : '') : 'הקישור לאיש הקשר הוסר', 'ok'));
            await reload();
            renderBody();
          } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
        }, { disabled: !EDITABLE.includes(doc.status) });
        parts.push(el('label', { class: 'inline small' }, '👤 ', picker));
      } else if (doc.contact) {
        parts.push(el('span', { class: 'small' }, '👤 ' + doc.contact));
      }
      parts.push(el('label', { class: 'inline small tags-label' }, '🏷️ ', window.DTCatalog.tagsInput(doc.tags, async (tags) => {
        try { await api('doc_tags', { id, tags }); doc.tags = tags; }
        catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
      }, { disabled: lvl() < 2 })));
      meta.replaceChildren(...parts);
    }
    renderMeta();

    window.DT.setLeaveGuard(Object.assign(() => {
      if (Object.keys(pending).length) flush();
      return true;
    }, { dirty: () => Object.keys(pending).length > 0 || !!inflight }));

    function renderHeader() {
      const [label, kind] = STATUS[doc.status] || [doc.status, ''];
      statusBadge.textContent = label;
      statusBadge.className = 'badge ' + kind;
      title.disabled = fill || !editable();
      completeBtn.hidden = !editable() || (!fill && lvl() < 3);
      if (!fill) {
        completeBtn.textContent = waitsForOther() ? (doc.awaiting_approval ? '⏳ נשלח לאישור' : '📨 שליחה לאישור של ' + doc.approver.name)
          : doc.awaiting_approval ? '✓ אישור ונעילה' : 'סיום ונעילה';
        completeBtn.disabled = waitsForOther() && doc.awaiting_approval;
        for (const b of outBtns) b.hidden = outLocked();
      }
      renderStage();
      for (const r of modeSeg.querySelectorAll('input')) r.checked = r.value === mode;
      modeSeg.hidden = isWord;
      const p = doc.progress;
      const pct = p.required ? Math.round(p.filled / p.required * 100) : 100;
      track.hidden = !p.required || doc.status === 'completed';
      track.setAttribute('aria-valuenow', pct);
      track.firstChild.style.width = Math.max(pct, 2) + '%';
      if (fill) {
        progress.textContent = doc.me.blocked || (doc.me.completed ? '✓ סיימת. תודה!'
          : p.missing.length ? 'נשארו שדות חובה: ' + p.missing.join(', ') : 'כל שדות החובה שלך מלאים');
      } else {
        progress.textContent = p.required ? `שדות חובה: ${p.filled} מתוך ${p.required}` : '';
      }
    }

    /** מסלול אישור. מנהל הטופס: "ממתין לאישורך" + החזרה לתיקון. ממלא: איפה זה עומד, והורדה כשאושר. */
    function renderStage() {
      const parts = [];
      if (!fill && waitsForOther()) {
        const back = [...(doc.events || [])].reverse().find(e => e.type === 'returned' || e.type === 'submitted');
        parts.push(el('div', { class: 'notice' + (back && back.type === 'returned' ? ' warn' : '') },
          doc.awaiting_approval ? [el('strong', {}, '⏳ נשלח לאישור של ' + doc.approver.name + '. '), 'כשיאשר — המסמך יינעל, ותקבלו מייל.']
            : back && back.type === 'returned' ? [el('strong', {}, '↩ הוחזר אליך לתיקון. '), back.detail && back.detail.note ? '"' + back.detail.note + '"' : 'תקנו ושלחו שוב לאישור.']
            : [el('strong', {}, '🔒 '), 'המסמך הזה ננעל רק באישור של ' + doc.approver.name + '. כשסיימתם — "📨 שליחה לאישור".'],
          el('p', { class: 'small muted' }, 'הורדה והדפסה — אחרי שיאושר.')));
      } else if (!fill && doc.awaiting_approval) {
        parts.push(el('div', { class: 'notice warn stage-box' },
          el('p', {}, el('strong', {}, '⏳ ממתין לאישורך. '), doc.approver ? 'נשלח אליך לאישור. בדקו את הערכים — ואז "✓ אישור ונעילה", או החזירו לתיקון.' : 'כל הממלאים סיימו. בדקו את הערכים — ואז "✓ אישור ונעילה", או החזירו לתיקון.'),
          lvl() >= 3 ? el('button', { type: 'button', class: 'small', onclick: returnDialog }, '↩ החזרה לתיקון') : null));
      }
      if (fill && doc.me) {
        const me = doc.me;
        if (me.returned !== null && me.returned !== undefined && !me.completed) {
          parts.push(el('div', { class: 'notice warn' }, el('strong', {}, '↩ הוחזר אליך לתיקון. '), me.returned ? '"' + me.returned + '"' : 'תקנו ולחצו שוב "סיימתי ✓".'));
        } else if (me.approved) {
          parts.push(el('div', { class: 'notice ok stage-box' },
            el('p', {}, el('strong', {}, doc.schema.approval ? '✓ המסמך אושר.' : '✓ המסמך הושלם.'), me.can_download ? ' אפשר להוריד עותק:' : ''),
            me.can_download ? el('div', { class: 'row-btns' },
              isWord || isAuthored ? el('button', { type: 'button', class: 'small', onclick: () => wordFile(false) }, '⬇️ הורדת Word') : null,
              isAuthored ? el('button', { type: 'button', class: 'small', onclick: printDoc }, '🖨️ הדפסה / PDF') : null,
              !isWord && !isAuthored ? el('button', { type: 'button', class: 'small primary', onclick: downloadPdf }, '⬇️ הורדת PDF') : null) : null));
        } else if (me.completed && me.awaiting) {
          parts.push(el('div', { class: 'notice' }, el('strong', {}, '⏳ ממתין לאישור. '), 'מה שמילאת נשלח. כשיאושר' + (doc.schema.fill_download ? ' — תקבלו הודעה, ותוכלו להוריד את המסמך מכאן.' : ', תקבלו הודעה.')));
        }
      }
      stage.replaceChildren(...parts);
    }

    /** החזרה לתיקון: למי (ממלא אחד, או כל מי שסיים), ומה לתקן. הממלא מקבל קישור חדש במייל. */
    function returnDialog() {
      const done = (doc.participants || []).filter(p => p.status === 'completed');
      const who = el('select', { 'aria-label': 'למי' }, done.length > 1 ? el('option', { value: 0 }, 'כל הממלאים') : null,
        done.map(p => el('option', { value: p.id }, p.role + (p.name ? ' — ' + p.name : ''))));
      const note = el('textarea', { rows: 4, maxlength: 1000, placeholder: 'מה לתקן? (נשלח לממלא במייל)' });
      const out = el('div');
      const go = el('button', { type: 'button', class: 'primary small', onclick: async () => {
        go.disabled = true;
        try {
          const r = await api('doc_return', { id, participant: Number(who.value), note: note.value });
          dlg.close();
          await reload();
          msg.replaceChildren(notice(`הוחזר לתיקון (${r.returned})` + (r.mailed ? ' — נשלח קישור חדש במייל.' : ' — אין מייל: שלחו קישור מ-👥 משתתפים.'), 'ok'));
        } catch (e) { go.disabled = false; out.replaceChildren(notice(e.message, 'bad')); }
      } }, 'החזרה');
      const dlg = window.DT.sheet('↩ החזרה לתיקון', el('div', { class: 'field-sheet' },
        el('label', { class: 'f' }, el('span', {}, 'למי'), who), el('label', { class: 'f' }, el('span', {}, 'הערה'), note), out), { action: go });
    }

    function setSave(state, text) {
      saveStatus.className = 'save-status small ' + state;
      saveStatus.textContent = text;
    }

    // ── שמירה אוטומטית ──
    function queue(key, v) {
      values[key] = v;
      pending[key] = v;
      // אותו שדה מופיע פעמיים (בטקסט) — מעדכנים גם את העותק
      for (const n of body.querySelectorAll(`input[data-key="${CSS.escape(key)}"]:not([type=checkbox]), textarea[data-key="${CSS.escape(key)}"], select[data-key="${CSS.escape(key)}"]`)) {
        if (n !== document.activeElement) n.value = v;
      }
      refreshDerived();
      if (byKey[key] && (byKey[key].list || byKey[key].type === 'table')) listRefresh.forEach(fn => fn());
      setSave('warn', '● שומר…');
      markField(key, 'dirty');
      clearTimeout(timer);
      timer = setTimeout(flush, 700);
    }

    async function flush() {
      clearTimeout(timer);
      if (inflight) { await inflight; }
      const keys = Object.keys(pending);
      if (!keys.length) return;
      const batch = {};
      keys.forEach(k => { batch[k] = pending[k]; delete pending[k]; });
      const send = fill ? { values: batch } : { id, values: batch };
      if (batch._title !== undefined) { send.title = batch._title; delete batch._title; }
      inflight = src.save(send).then(r => {
        doc.progress = r.progress;
        // הערכים המחושבים של השרת הם הקובעים (מה שעוד ממתין לשמירה — לא דורסים)
        if (r.computed) {
          for (const [k, v] of Object.entries(r.computed)) if (!(k in pending)) values[k] = v;
          refreshDerived();
        }
        if (doc.status === 'draft' && r.saved) doc.status = 'in_progress';
        keys.forEach(k => markField(k, 'saved'));
        setSave('ok', '✓ נשמר ' + new Date(r.saved_at).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' }));
        renderHeader();
      }).catch(e => {
        keys.forEach(k => { if (!(k in pending)) pending[k] = batch[k] ?? send.title; markField(k, 'error'); });
        setSave('bad', '⚠ לא נשמר: ' + e.message);
      }).finally(() => { inflight = null; });
      await inflight;
    }

    title.addEventListener('input', () => queue('_title', title.value));

    function markField(key, state) {
      for (const n of body.querySelectorAll(`[data-key="${CSS.escape(key)}"]`)) {
        n.classList.remove('dirty', 'saved', 'error');
        n.classList.add(state);
      }
    }

    // ── קלט לשדה — אותו רכיב בשתי התצוגות ──
    function control(f, onPage, inlineText) {
      const v = values[f.key] ?? '';
      const ro = !canEdit(f);
      const common = { 'data-key': f.key, 'aria-label': f.label, disabled: ro };
      let n;
      // מחושב / מתמלא לבד: על הדף — באותו גופן ויישור כמו בפלט, בשורה אחת
      const onPageText = (o) => {
        if (onPage) { o.classList.add('on-page'); o.style.fontSize = `calc(var(--k) * ${f.fontSize || 11}px)`;
          o.style.textAlign = f.align === 'center' ? 'center' : f.align === 'left' ? 'left' : 'right';
          o.style.alignItems = f.valign === 'top' ? 'flex-start' : f.valign === 'bottom' ? 'flex-end' : 'center';
          if (f.font) o.style.fontFamily = window.DTFonts.family(f.font);
          const fc = window.DTFmt.rich(f, values[f.key] ?? '')?.color;
          if (fc || f.color) o.style.color = fc || f.color; }
        return o;
      };
      if (f.type === 'calc') {
        return onPageText(el('output', { class: 'calc-out', 'data-calc': f.key, 'aria-label': f.label }, calcText(f, v) || '—'));
      }
      if (f.type === 'system') {
        return onPageText(el('output', { class: 'calc-out sys-out', 'data-sys': f.key, 'aria-label': f.label, title: 'מתמלא לבד', dir: 'auto' }, sysShown(f, v) || '—'));
      }
      if (f.type === 'table') return onPage ? tablePreview(f) : tableControl(f);
      if (f.type === 'file') {
        n = onPage
          ? el('button', { type: 'button', class: 'sig-btn file-btn', 'data-key': f.key, 'aria-label': f.label,
              onclick: () => fileDialog(f) }, fileSummary(f))
          : fileControl(f);
      } else if (f.type === 'checkbox') {
        n = el('input', { type: 'checkbox', checked: v === '1', ...common });
        n.addEventListener('change', () => queue(f.key, n.checked ? '1' : ''));
      } else if (f.type === 'signature') {
        n = el('button', { type: 'button', class: 'sig-btn' + (v ? ' has' : ''), ...common,
          onclick: () => signaturePad(f, (data) => { queue(f.key, data); renderBody(); }) },
          v ? el('img', { src: v, alt: 'חתימה' }) : '✍️ לחצו לחתימה');
        if (ro) n.disabled = true;
      } else if (f.type === 'select' && f.list) {
        // רשימת ערכים — מסוננת לפי הרמות שמעל
        n = el('select', common);
        const paint = () => fillSelect(n, listOptions(doc.lists, f.list, listKnown(f.list.id)), values[f.key] ?? '', onPage || inlineText ? f.label : '— בחירה —',
          (v) => listShow(doc.lists, f.list, v));
        paint(); listRefresh.push(paint);
        n.addEventListener('change', () => { queue(f.key, n.value); outHint(); });
        // שפה במסמך: בוחרים בעברית — ורואים מה ייכתב
        const outHint = () => { if (f.list.lang !== f.list.show) n.title = n.value ? 'במסמך: ' + listOut(doc.lists, f.list, n.value) : ''; };
        outHint();
      } else if (f.type === 'select') {
        // בחירה מרשימה; בחירה ממקור חיצוני יכולה למלא שדות נוספים מהרשומה
        const opts = f.options || [];
        n = el('select', common, el('option', { value: '' }, onPage || inlineText ? f.label : '— בחירה —'),
          opts.map(o => el('option', { value: o.value, selected: o.value === v }, o.label)),
          v && !opts.some(o => o.value === v) ? el('option', { value: v, selected: true }, v) : null);
        n.addEventListener('change', () => {
          queue(f.key, n.value);
          const o = opts.find(x => x.value === n.value);
          for (const [k, val] of Object.entries(o?.data || {})) if (byKey[k] && canEdit(byKey[k])) queue(k, val);
        });
      } else if (f.type === 'textarea') {
        n = el('textarea', { ...common, placeholder: onPage ? f.label : '' });
        n.value = v;
        n.addEventListener('input', () => queue(f.key, limit(f, n)));
      } else {
        const type = { number: 'text', date: 'date', text: 'text' }[f.type] || 'text';
        n = el('input', { type, ...common, inputmode: f.type === 'number' ? 'decimal' : null,
          dir: f.type === 'number' ? 'ltr' : null, placeholder: onPage || inlineText ? f.label : '' });
        n.value = v;
        // תאריך: הקשה פותחת את לוח השנה (בטלפון, על הדף, הקשה בלבד לא תמיד פותחת אותו)
        if (type === 'date') n.addEventListener('pointerup', (e) => {
          if (e.pointerType !== 'touch' || n.disabled || n.readOnly) return;   // במחשב — הדפדפן פותח לבד
          try { n.showPicker(); } catch (err) { /* דפדפן ישן */ }
        });
        if (inlineText && type === 'text') n.size = Math.max(6, Math.min(40, (v || f.label).length + 2));
        n.addEventListener('input', () => queue(f.key, limit(f, n)));
        if (f.type === 'text' && f.list) {
          // רשימת ערכים בשדה טקסט — הצעות (מותר לכתוב גם אחר)
          let dl = datalistFor(n, listOptions(doc.lists, f.list, listKnown(f.list.id)));
          listRefresh.push(() => { const d2 = datalistFor(n, listOptions(doc.lists, f.list, listKnown(f.list.id))); dl.replaceWith(d2); dl = d2; });
          return el('span', { class: 'dl-wrap' }, n, dl);
        }
      }
      if (onPage && f.type !== 'checkbox' && f.type !== 'signature') {
        n.style.fontSize = `calc(var(--k) * ${f.fontSize || 11}px)`;
        n.style.textAlign = f.align === 'center' ? 'center' : f.align === 'left' ? 'left' : 'right';
        if (f.font) n.style.fontFamily = window.DTFonts.family(f.font);
        if (f.color) { n.style.color = f.color; n.style.accentColor = f.color; }
        // למעלה / למטה: שורה אחת — הריפוד דוחף את הטקסט לקצה (בגובה השדה, בנקודות של הדף)
        if (f.type !== 'textarea' && f.type !== 'file' && f.valign && f.valign !== 'middle') {
          const rest = Math.max(0, f.h * doc.schema.pages[f.page].h - (f.fontSize || 11) * 1.3);
          n.style[f.valign === 'top' ? 'paddingBottom' : 'paddingTop'] = `calc(var(--k) * ${rest}px)`;
        }
      }
      // מבנה מותאם (תאריך עברי, שם חודש, מספר עם פסיקים…): מוצג כשלא עורכים; בהקשה — הערך עצמו לעריכה
      if (f.fmt && (f.type === 'date' || f.type === 'number' || f.type === 'text') && !inlineText) {
        const show = el(onPage ? 'span' : 'small', { class: onPage ? 'fmt-show' : 'fmt-preview', 'aria-hidden': onPage ? 'true' : null });
        const wrap = el('span', { class: onPage ? 'fmt-wrap' : 'fmt-form' });
        if (onPage) {
          Object.assign(show.style, { fontSize: n.style.fontSize, fontFamily: n.style.fontFamily, color: n.style.color,
            justifyContent: f.align === 'center' ? 'center' : f.align === 'left' ? 'flex-end' : 'flex-start',
            alignItems: f.valign === 'top' ? 'flex-start' : f.valign === 'bottom' ? 'flex-end' : 'center' });
        }
        const upd = () => {
          const r = window.DTFmt.rich(f, n.value);
          const t = r && r.text;
          show.textContent = t ? (onPage ? t : 'יוצג כך: ' + t) : '';
          if (onPage) show.style.color = (r && r.color) || n.style.color || '';   // [אדום] במבנה — גובר על צבע השדה
          wrap.classList.toggle('has-fmt', !!t);
        };
        n.addEventListener('input', upd);
        n.addEventListener('change', upd);
        wrap.append(n, show);
        upd();
        return wrap;
      }
      return n;
    }

    // ── שלב 11: טבלה חוזרת, שדות מחושבים ותנאי הצגה ──
    function tableControl(f) {
      const cols = f.columns || [];
      const rows = C.rows(values[f.key]);
      const ro = !canEdit(f);
      const tbody = el('tbody');
      const commit = () => {
        C.tableCompute(f, fields, values, rows).forEach((x, i) => Object.assign(rows[i], x));
        tbody.querySelectorAll('tr').forEach((tr, i) => cols.forEach((c, j) => {
          if (c.type === 'calc') tr.children[j].textContent = C.display(rows[i][c.key] ?? '', c.format);
        }));
        queue(f.key, rows.length ? JSON.stringify(rows) : '');
      };
      const gn = groupN(f), gcols = cols.slice(0, gn);
      const rowEl = (r, i) => el('tr', { class: sameGroup(f, rows, i) ? 'same-grp' : null }, cols.map(c => {
        if (c.type === 'calc') return el('td', { class: 'calc-cell', 'data-label': c.label }, C.display(r[c.key] ?? '', c.format));
        if (c.type === 'select') {
          const sel = el('select', { disabled: ro, 'aria-label': c.label });
          // מרשימת ערכים — מסונן לפי השדות והעמודות שמעל באותה רשימה (באותה שורה)
          const paint = () => fillSelect(sel, c.list ? listOptions(doc.lists, c.list, listKnown(c.list.id, r, f)) : (c.options || []), r[c.key] ?? '', '—',
            (v) => c.list ? listShow(doc.lists, c.list, v) : v);
          paint(); if (c.list) listRefresh.push(paint);
          sel.addEventListener('change', () => { r[c.key] = sel.value; commit(); if (gcols.includes(c)) markGroups(); if (c.list) listRefresh.forEach(fn => fn()); });
          return el('td', { 'data-label': c.label }, sel);
        }
        const inp = el('input', { value: r[c.key] ?? '', disabled: ro, 'aria-label': c.label,
          inputmode: c.type === 'number' ? 'decimal' : null, dir: c.type === 'number' ? 'ltr' : null });
        inp.addEventListener('input', () => { r[c.key] = inp.value; commit(); if (gcols.includes(c)) markGroups(); if (c.list) listRefresh.forEach(fn => fn()); });
        if (c.list && c.type === 'text') {
          let dl = datalistFor(inp, listOptions(doc.lists, c.list, listKnown(c.list.id, r, f)));
          listRefresh.push(() => { const d2 = datalistFor(inp, listOptions(doc.lists, c.list, listKnown(c.list.id, r, f))); dl.replaceWith(d2); dl = d2; });
          return el('td', { 'data-label': c.label }, inp, dl);
        }
        return el('td', { 'data-label': c.label }, inp);
      }), ro ? null : el('td', { class: 'row-del' }, el('button', { type: 'button', class: 'link small', title: 'מחיקת שורה',
        onclick: () => { rows.splice(rows.indexOf(r), 1); redraw(); commit(); } }, '✕')));
      const redraw = () => tbody.replaceChildren(...rows.map(rowEl));
      // קיבוץ: שורה שממשיכה את הקבוצה של הקודמת (זהה בעמודות הקבוצה) — בלי קו מפריד
      const markGroups = () => tbody.querySelectorAll('tr').forEach((tr, i) => tr.classList.toggle('same-grp', sameGroup(f, rows, i)));
      redraw();
      return el('div', { class: 'grid-wrap', 'data-key': f.key },
        el('table', { class: 'grid' }, el('thead', {}, el('tr', {}, cols.map(c => el('th', {}, c.label)), ro ? null : el('th'))), tbody),
        ro ? (rows.length ? null : el('span', { class: 'muted small' }, 'אין שורות'))
          : el('div', { class: 'row-btns' },
            el('button', { type: 'button', class: 'small', onclick: () => {
              rows.push({}); redraw();
              tbody.lastChild?.querySelector('input, select')?.focus();
            } }, '+ שורה'),
            // שורה נוספת באותה קבוצה (למשל הקבר השני באותה מכפלה): מעתיקה את עמודות הקבוצה
            gn ? el('button', { type: 'button', class: 'small', onclick: () => {
              const last = rows[rows.length - 1];
              rows.push(last ? Object.fromEntries(gcols.map(c => [c.key, last[c.key] ?? ''])) : {});
              redraw(); commit();
              tbody.lastChild?.querySelectorAll('input, select')[gn]?.focus();
            } }, '+ שורה באותה ' + gcols[gn - 1].label) : null));
    }

    /** על המסמך: הטבלה כפי שתודפס, בתוך האזור שלה; הקשה — עריכת השורות בגיליון. */
    function tablePreview(f) {
      const cols = f.columns || [];
      const rows = C.rows(values[f.key]);
      const gn = groupN(f);
      const cell = (c, r) => c.type === 'calc' ? C.display(r[c.key] ?? '', c.format) : c.type === 'number' ? C.display(r[c.key] ?? '', 'number') : (r[c.key] ?? '');
      const t = el('table', { class: 'tbl-page' + (gn ? ' grouped' : ''), style: `font-size: calc(var(--k) * ${f.fontSize || 9}px)` + (f.color ? `; color: ${f.color}` : '') },
        el('thead', {}, el('tr', {}, cols.map(c => el('th', {}, c.label)))),
        el('tbody', {}, rows.map((r, i) => {
          const cont = sameGroup(f, rows, i);
          return el('tr', { class: cont ? 'same-grp' : null }, cols.map((c, j) => el('td', { class: c.type === 'number' || c.type === 'calc' ? 'num' : c.type === 'text' ? 'txt' : null },
            j < gn && cont ? '' : cell(c, r))));
        })));
      const edit = canEdit(f);
      const box = el('div', { class: 'tbl-box' + (edit ? ' editable' : ''), role: edit ? 'button' : null, tabindex: edit ? 0 : null,
        'aria-label': edit ? f.label + ' — עריכת השורות' : f.label,
        onkeydown: edit ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.currentTarget.click(); } } : null,
        onclick: edit ? () => {
          const dlg = window.DT.sheet('▦ ' + f.label, el('div', { class: 'tbl-sheet' }, tableControl(f)),
            { action: el('button', { type: 'button', class: 'primary small', onclick: () => dlg.close() }, 'סיום'), onClose: () => renderBody() });
        } : null }, t, rows.length ? null : el('span', { class: 'tbl-empty' }, edit ? '+ הוספת שורות' : 'אין שורות'));
      return box;
    }

    /** מחשב מחדש את השדות המחושבים (לתצוגה מיידית), ומסתיר מה שתנאי ההצגה שלו לא מתקיים. */
    function refreshDerived() {
      // ממלא בקישור לא מקבל שדות מוסתרים — החישוב שלו חלקי; הערכים מהשרת קובעים
      if (!fill) Object.assign(values, C.all(doc.schema, values));
      for (const n of body.querySelectorAll('[data-calc]')) {
        const f = byKey[n.dataset.calc];
        if (f) n.textContent = calcText(f, values[f.key] ?? '') || '—';
      }
      for (const n of body.querySelectorAll('[data-sys]')) {
        const f = byKey[n.dataset.sys];
        if (f) n.textContent = sysShown(f, values[f.key] ?? '') || '—';
      }
      const vis = C.visibleKeys(doc.schema, values);
      for (const n of body.querySelectorAll('[data-vis-key]')) n.hidden = !vis.has(n.dataset.visKey);
      for (const n of body.querySelectorAll('[data-cond]')) {
        const c = JSON.parse(n.dataset.cond);
        n.hidden = !(C.shown(c, fields, values) && vis.has(c.key));
      }
    }

    // ── תצוגה: נכתב בכלי — הטקסט, והשדות בתוכו ──
    function inline(text) {
      const out = [];
      const pushText = (t) => t.split('\n').forEach((line, i) => { if (i) out.push(el('br')); if (line) out.push(line); });
      let last = 0;
      for (const m of text.matchAll(PH)) {
        pushText(text.slice(last, m.index));
        last = m.index + m[0].length;
        const f = byPh[m[1].trim()];
        if (!f) continue;
        const block = ['table', 'textarea', 'signature', 'file'].includes(f.type);
        out.push(el(block ? 'div' : 'span', {
          class: 'inline-field t-' + f.type + (canEdit(f) ? ' mine' : ' theirs') + (f.required ? ' required' : ''),
          'data-vis-key': f.key, title: f.label + (f.help ? ' — ' + f.help : ''),
        }, control(f, false, true)));
      }
      pushText(text.slice(last));
      return out;
    }

    function renderAuthored() {
      const sheet = el('div', { class: 'doc-sheet authored-doc' });
      for (const b of doc.schema.body || []) {
        const style = 'text-align:' + (b.align === 'center' ? 'center' : b.align === 'justify' ? 'justify' : 'right');
        let n = null;
        if (b.type === 'heading') n = el('h2', { class: 'a-h', style }, inline(b.text || ''));
        else if (b.type === 'p') n = el('div', { class: 'a-p', style }, inline(b.text || ''));
        else if (b.type === 'table' && byKey[b.table]) n = el('div', { class: 'a-table', 'data-vis-key': b.table }, tableControl(byKey[b.table]));
        else if (b.type === 'break') n = el('div', { class: 'a-break' }, 'מעבר עמוד');
        if (!n) continue;
        if (b.showIf) n.dataset.cond = JSON.stringify(b.showIf);
        sheet.append(n);
      }
      body.replaceChildren(sheet);
    }

    /** הדפסה (ומשם "שמירה כ-PDF") — המסמך עם הערכים, בלי שדות. */
    function printDoc() {
      const vis = C.visibleKeys(doc.schema, values);
      const esc = (t) => String(t ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
      const table = (f) => '<table><tr>' + (f.columns || []).map(c => `<th>${esc(c.label)}</th>`).join('') + '</tr>'
        + C.rows(values[f.key]).map(r => '<tr>' + (f.columns || []).map(c =>
          `<td>${esc(c.type === 'calc' ? C.display(r[c.key] ?? '', c.format) : listOut(doc.lists, c.list, r[c.key]))}</td>`).join('') + '</tr>').join('') + '</table>';
      const val = (f) => {
        const v = f.list ? listOut(doc.lists, f.list, values[f.key]) : values[f.key] ?? '';
        if (!vis.has(f.key) || f.print === false) return '';
        switch (f.type) {
          case 'date': return esc(window.DTFmt.apply(f, v) ?? ddmmyyyy(v));
          case 'number': return esc(window.DTFmt.apply(f, v) ?? v);
          case 'text': return esc(window.DTFmt.apply(f, v) ?? v);
          case 'system': return esc(sysShown(f, v));
          case 'select': return esc(optText(f, v));
          case 'checkbox': return v === '1' ? '☒' : '☐';
          case 'calc': return esc(calcText(f, v));
          case 'signature': return /^data:image\/png;base64,/.test(v) ? `<img class="sig" src="${esc(v)}" alt="חתימה">` : '';
          case 'table': return table(f);
          case 'file': {
            let ids = []; try { ids = JSON.parse(v || '[]'); } catch (e) { /* ריק */ }
            return esc(ids.map(aid => (doc.attachments || []).find(a => a.id === aid)?.name || 'קובץ').join(', '));
          }
          default: return esc(v).replace(/\n/g, '<br>');
        }
      };
      const text = (t) => {
        let out = '', last = 0;
        for (const m of (t || '').matchAll(PH)) {
          out += esc(t.slice(last, m.index)).replace(/\n/g, '<br>');
          const f = byPh[m[1].trim()];
          out += f ? val(f) : '';
          last = m.index + m[0].length;
        }
        return out + esc((t || '').slice(last)).replace(/\n/g, '<br>');
      };
      let html = '';
      for (const b of doc.schema.body || []) {
        if (b.showIf && !(C.shown(b.showIf, fields, values) && vis.has(b.showIf.key))) continue;
        const align = ` style="text-align:${b.align === 'center' ? 'center' : b.align === 'justify' ? 'justify' : 'right'}"`;
        if (b.type === 'heading') html += `<h1${align}>${text(b.text)}</h1>`;
        else if (b.type === 'p') html += `<p${align}>${text(b.text)}</p>`;
        else if (b.type === 'table' && byKey[b.table] && vis.has(b.table) && byKey[b.table].print !== false) html += table(byKey[b.table]);
        else if (b.type === 'break') html += '<div class="brk"></div>';
      }
      const foot = esc((doc.number ? doc.number + ' · ' : '') + doc.title)
        + (doc.status === 'completed' && doc.final_hash ? ' · הושלם · טביעת אצבע: ' + esc(doc.final_hash.slice(0, 16)) + '…' : '');
      const page = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><title>${esc(doc.title)}</title><style>
        body{font-family:Arial,'Alef',sans-serif;font-size:11pt;line-height:1.55;margin:0;color:#000}
        @page{size:A4;margin:2cm} h1{font-size:18pt;margin:0 0 10pt} p{margin:0 0 7pt}
        table{width:100%;border-collapse:collapse;margin:6pt 0 10pt} th,td{border:1px solid #888;padding:3pt 6pt;text-align:right}
        th{background:#f1f3f5} .sig{height:48px;vertical-align:middle} .brk{break-after:page}
        footer{margin-top:24pt;font-size:8pt;color:#666;border-top:1px solid #ccc;padding-top:4pt}
      </style></head><body>${html}<footer>${foot}</footer></body></html>`;
      const fr = el('iframe', { class: 'print-frame', title: 'הדפסה', style: 'position:fixed;width:0;height:0;border:0;inset:auto' });
      document.body.append(fr);
      fr.contentDocument.open();
      fr.contentDocument.write(page);
      fr.contentDocument.close();
      fr.contentWindow.focus();
      try { fr.contentWindow.print(); } catch (e) { msg.replaceChildren(notice('ההדפסה נכשלה: ' + e.message, 'bad')); }
      setTimeout(() => fr.remove(), 60000);
    }

    // ── צירוף קבצים ──
    const ACCEPT = { pdf: '.pdf,application/pdf', image: 'image/*', office: '.doc,.docx,.xls,.xlsx' };
    const fileIds = (f) => { try { return JSON.parse(values[f.key] || '[]'); } catch (e) { return []; } };
    const attById = (aid) => (doc.attachments || []).find(a => a.id === aid);
    const kb = (n) => n > 1048576 ? (n / 1048576).toFixed(1) + 'MB' : Math.max(1, Math.round(n / 1024)) + 'KB';
    function fileSummary(f) {
      const n = fileIds(f).length;
      return n ? `📎 ${n === 1 ? (attById(fileIds(f)[0])?.name || 'קובץ מצורף') : n + ' קבצים'}` : (canEdit(f) ? '📎 צירוף' : '—');
    }

    function fileControl(f) {
      const ids = fileIds(f);
      const editable = canEdit(f);
      const status = el('span', { class: 'muted small' });
      const pick = (capture) => {
        const inp = el('input', { type: 'file', accept: capture ? 'image/*' : (f.accept || ['pdf', 'image']).map(k => ACCEPT[k]).join(','),
          capture: capture ? 'environment' : null, hidden: true });
        inp.addEventListener('change', async () => {
          const files = [...inp.files].slice(0, f.multiple ? 10 : 1);
          for (const file of files) {
            status.textContent = 'מעלה ' + file.name + '…';
            const fd = new FormData();
            if (!fill) fd.append('id', id);
            fd.append('field_key', f.key);
            fd.append('file', file);
            try {
              const r = await api(fill ? 'fill_upload' : 'doc_upload', fd);
              doc.attachments = [...(doc.attachments || []), r.attachment];
              values[f.key] = r.value;
              status.textContent = '';
            } catch (e) {
              status.textContent = '⚠ ' + e.message;
              return;
            }
          }
          await reload();
          renderBody();
        });
        document.body.append(inp);
        inp.click();
        setTimeout(() => inp.remove(), 60000);
      };
      return el('div', { class: 'file-ctl', 'data-key': f.key },
        ids.length ? el('ul', { class: 'file-list' }, ids.map(aid => {
          const a = attById(aid);
          return el('li', {},
            a ? el('button', { type: 'button', class: 'link', onclick: () => downloadAtt(a) }, '📄 ' + a.name)
              : el('span', { class: 'muted' }, '📄 קובץ מצורף (לא זמין לצפייה)'),
            a ? el('span', { class: 'muted small' }, ' ' + kb(a.size)) : null,
            editable ? el('button', { type: 'button', class: 'link small danger-text', title: 'הסרה',
              onclick: () => { queue(f.key, JSON.stringify(ids.filter(x => x !== aid))); renderBody(); } }, ' ✕') : null);
        })) : (editable ? null : el('span', { class: 'muted small' }, 'לא צורף קובץ')),
        editable ? el('div', { class: 'ops' },
          el('button', { type: 'button', class: 'small', onclick: () => pick(false) },
            ids.length && !f.multiple ? '📎 החלפת קובץ' : '📎 צירוף קובץ'),
          (f.accept || []).includes('image') ? el('button', { type: 'button', class: 'small', onclick: () => pick(true) }, '📷 צילום') : null,
          el('span', { class: 'muted small' }, 'עד ' + (f.maxMb || 10) + 'MB')) : null,
        status);
    }

    function fileDialog(f) {
      const body_ = el('div');
      const dlg = el('dialog', { class: 'sig-dialog' }, el('h2', {}, f.label), body_,
        el('div', { class: 'ops' }, el('button', { onclick: () => dlg.close() }, 'סגירה')));
      const draw = () => body_.replaceChildren(fileControl(f));
      draw();
      dlg.addEventListener('close', () => { dlg.remove(); renderBody(); });
      document.body.append(dlg);
      dlg.showModal();
    }

    async function downloadAtt(a) {
      try {
        const url = fill ? 'api.php?action=fill_file&att=' + a.id : `api.php?action=doc_file&id=${id}&att=${a.id}`;
        const res = await fetch(url, { credentials: 'same-origin', headers: fill ? { 'X-Fill-Token': opts.token } : {} });
        if (!res.ok) throw new Error('ההורדה נכשלה');
        const link = el('a', { href: URL.createObjectURL(await res.blob()), download: a.name });
        document.body.append(link); link.click(); link.remove();
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    // "לא לאפשר להקליד מעבר לגודל": מודדים את הטקסט בגופן ובגודל של השדה.
    const measure = document.createElement('canvas').getContext('2d');
    function limit(f, input) {
      // בשדה רב-שורתי זה תלוי בשבירת השורות — שם נשען על ה-PDF (הקטנה), לא חוסמים.
      // רק בטקסט חופשי: תאריך נשמר כ-2026-10-15 ומוצג אחרת — קיצוץ הופך אותו לפסול, והשדה מתרוקן
      if (f.overflow !== 'block' || !['text', 'number'].includes(f.type)) return input.value;
      const pw = doc.schema.pages[f.page].w * f.w;
      measure.font = `${f.fontSize || 11}px ${window.DTFonts.family(f.font)}`;
      while (input.value && measure.measureText(input.value).width > pw - 4) input.value = input.value.slice(0, -1);
      return input.value;
    }

    // ── תצוגה: על המסמך ──
    let zoom = null;   // הזום נשמר בין ציורים של הדף
    let pagesCache = null;
    async function renderPage(seq) {
      // גופנים שהועלו — לפני הציור, כדי שהשדות יופיעו מיד בגופן הנכון
      window.DTFonts.loadAll(doc.schema, fill ? { token: opts.token } : { doc: id });
      if (!pagesCache) {
        body.replaceChildren(el('p', { class: 'muted' }, 'טוען את המסמך…'));
        pagesCache = await window.DTEditor.renderBackground(src.asset[0], doc.mime, src.asset[1]);
        // בזמן שהדפים נטענו, אולי עברו לטופס (או ציירו מחדש) — אז לא דורסים.
        if (seq !== renderSeq) return;
      }
      const wrap = el('div', { class: 'pages filling' });
      pagesCache.forEach((pg, i) => {
        const layer = el('div', { class: 'layer' });
        const page = el('div', { class: 'page', style: `aspect-ratio: ${pg.w} / ${pg.h}` }, pg.visual, layer);
        pg.visual.classList.add('page-bg');
        fields.filter(f => f.page === i).forEach(f => {
          layer.append(el('div', {
            'data-vis-key': f.key,
            class: 'fill t-' + f.type + (f.required ? ' required' : '') + (canEdit(f) ? ' mine' : ' theirs'), 'data-key': f.key, title: f.label + (f.help ? ' — ' + f.help : ''),
            style: `left:${f.x * 100}%; top:${f.y * 100}%; width:${f.w * 100}%; height:${f.h * 100}%`,
          }, control(f, true)));
        });
        new ResizeObserver(() => { page.style.setProperty('--k', page.clientWidth / pg.w); }).observe(page);
        wrap.append(page);
      });
      // צופה עם זום וגלילה — גם בטלפון (שלב 12+: נגישות)
      const z = zoom || (zoom = window.DTEditor.zoomable(wrap));
      if (z.stage.firstChild !== wrap) { z.stage.replaceChildren(wrap); wrap.style.width = z.zoom * 100 + '%'; }
      body.replaceChildren(z.box);
    }

    // ── תצוגה: טופס ──
    function renderForm() {
      let lastPage = -1;
      const items = [];
      for (const f of ordered) {
        if (doc.schema.pages.length > 1 && f.page !== lastPage) {
          items.push(el('h3', { class: 'form-page' }, 'עמוד ' + (f.page + 1)));
          lastPage = f.page;
        }
        const c = control(f, false);
        // שדה עם כפתורים (קובץ, חתימה) — div: לחיצה על תווית הייתה מפעילה את הכפתור הראשון.
        items.push(el(['file', 'signature', 'table', 'calc', 'system'].includes(f.type) ? 'div' : 'label', { class: 'form-field t-' + f.type, 'data-key': f.key, 'data-vis-key': f.key },
          el('span', {}, f.label, f.required ? el('b', { class: 'req', title: 'חובה' }, ' *') : null),
          c, f.help ? el('small', { class: 'muted' }, f.help) : null,
          othersText(f) ? el('small', { class: 'muted shared-hint' }, '🔀 ' + othersText(f)) : null));
      }
      body.replaceChildren(el('div', { class: 'card fill-form' }, items));
    }

    let renderSeq = 0;
    async function renderBody() {
      renderHeader();
      const seq = ++renderSeq;
      listRefresh.length = 0;
      try {
        if (mode === 'page' && isAuthored) renderAuthored();
        else if (mode === 'page') await renderPage(seq);
        else renderForm();
        refreshDerived();
      } catch (e) {
        body.replaceChildren(notice(e.message, 'bad'));
      }
    }

    // ── חתימה ──
    function signaturePad(f, done) {
      const canvas = el('canvas', { width: 600, height: 200, class: 'sig-canvas' });
      const ctx = canvas.getContext('2d');
      ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1f66';
      let drawing = false, empty = true;
      const pos = (e) => { const r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * 600 / r.width, (e.clientY - r.top) * 200 / r.height]; };
      canvas.addEventListener('pointerdown', (e) => { drawing = true; empty = false; canvas.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(...pos(e)); });
      canvas.addEventListener('pointermove', (e) => { if (drawing) { ctx.lineTo(...pos(e)); ctx.stroke(); } });
      canvas.addEventListener('pointerup', () => { drawing = false; });
      const typed = el('input', { placeholder: 'או הקלידו את שמכם', maxlength: 60 });
      typed.addEventListener('input', () => {
        ctx.clearRect(0, 0, 600, 200);
        ctx.fillStyle = '#0b1f66';
        ctx.font = 'italic 64px Alef, Arial';
        ctx.textAlign = 'center';
        ctx.direction = 'rtl';
        ctx.fillText(typed.value, 300, 120);
        empty = !typed.value.trim();
      });
      const dlg = el('dialog', { class: 'sig-dialog' },
        el('h2', {}, f.label),
        el('p', { class: 'muted small' }, 'חתמו באצבע או בעכבר בתוך המסגרת.'),
        canvas, typed,
        el('div', { class: 'ops' },
          el('button', { class: 'primary', onclick: () => { if (empty) return; done(canvas.toDataURL('image/png')); dlg.close(); } }, 'אישור החתימה'),
          el('button', { onclick: () => { ctx.clearRect(0, 0, 600, 200); typed.value = ''; empty = true; } }, 'ניקוי'),
          values[f.key] ? el('button', { class: 'danger', onclick: () => { done(''); dlg.close(); } }, 'מחיקת החתימה') : null,
          el('button', { onclick: () => dlg.close() }, 'ביטול')));
      dlg.addEventListener('close', () => dlg.remove());
      document.body.append(dlg);
      dlg.showModal();
    }

    // ── יומן ──
    function renderJournal() {
      if (journal.hidden) return;
      journal.replaceChildren(
        el('div', { class: 'row' }, el('h2', {}, 'יומן המסמך'), el('button', { class: 'link small', onclick: () => { journal.hidden = true; } }, 'סגירה')),
        el('p', { class: 'small ' + (doc.chain_ok ? 'ok-text' : 'bad-text') },
          doc.chain_ok ? '✓ שרשרת היומן שלמה — לא נמחקה ולא שונתה אף רשומה' : '⚠ שרשרת היומן שבורה — רשומה שונתה או נמחקה'),
        // שמירות רצופות — שורה אחת (×מספר, טווח שעות); השינויים עצמם — בלחיצה
        el('ol', { class: 'events' }, collapseEvents(doc.events).reverse().map(e => el('li', {},
          el('strong', {}, (EVENTS[e.type] || e.type) + (e.n > 1 ? ` ×${e.n}` : '')), ' ',
          e.by ? el('span', { class: 'small' }, '· ' + e.by + ' ') : null,
          el('span', { class: 'muted small' }, fmtDate(e.at) + (e.n > 1 && hhmm(e.until) !== hhmm(e.at) ? '–' + hhmm(e.until) : '') + (e.ip ? ' · ' + e.ip : '')),
          e.changes.length > 3 ? el('details', { class: 'small' }, el('summary', {}, `${e.changes.length} שינויים`),
            el('ul', {}, e.changes.map(c => el('li', {},
            c.label + ': ', 'signed' in c ? (c.signed ? 'נחתם' : 'החתימה נמחקה')
              : el('span', {}, el('del', { class: 'muted' }, short(c.from)), ' → ', short(c.to)))))) :
          e.changes.length ? el('ul', { class: 'small' }, e.changes.map(c => el('li', {},
            c.label + ': ', 'signed' in c ? (c.signed ? 'נחתם' : 'החתימה נמחקה')
              : el('span', {}, el('del', { class: 'muted' }, short(c.from)), ' → ', short(c.to))))) : null,
          e.detail && e.detail.number ? el('div', { class: 'small muted' }, 'מספר: ' + e.detail.number) : null,
          e.detail && e.detail.copy_of ? el('div', { class: 'small muted' }, '⧉ עותק של ' + e.detail.copy_of) : null,
          e.detail && e.detail.roles ? el('div', { class: 'small muted' }, e.detail.roles.join(', ') + (e.detail.note ? ' · "' + e.detail.note + '"' : '')) : null,
          e.detail && e.detail.with ? el('div', { class: 'small muted' }, 'עם ' + e.detail.with + (e.detail.level ? ' · ' + e.detail.level : '')) : null,
          e.detail && e.detail.name ? el('div', { class: 'small muted' }, (e.detail.label ? e.detail.label + ': ' : '') + e.detail.name) : null,
          e.detail && e.detail.role ? el('div', { class: 'small muted' }, e.detail.role +
            (e.detail.via ? ' · ' + (VIA[e.detail.via] || e.detail.via) + (e.detail.auto ? ' (אוטומטית)' : '') : '')) : null,
          e.detail && e.detail.hash ? el('div', { class: 'small muted', dir: 'ltr' }, 'SHA-256: ' + e.detail.hash.slice(0, 16) + '…') : null))));
    }
    const short = (s) => { s = s === '1' ? '✓' : String(s || '∅'); return s.length > 40 ? s.slice(0, 40) + '…' : s; };

    async function reload() {
      doc = (await src.get()).document;
      renderHeader();
      renderJournal();
      renderPeople();
    }

    // ── ממלא: סיום ──
    async function fillDone() {
      await flush();
      if (doc.progress.missing.length) {
        msg.replaceChildren(notice('חסרים שדות חובה: ' + doc.progress.missing.join(', '), 'bad'));
        return;
      }
      if (!confirm('לסיים? אחרי זה אי אפשר לשנות את מה שמילאת.')) return;
      try {
        const r = await api('fill_complete', {});
        await reload();
        msg.replaceChildren(notice(r.document_completed
          ? 'תודה! המסמך הושלם, ומי ששלח אותו קיבל הודעה.' : 'תודה! מה שמילאת נשמר ונשלח.', 'ok'));
        renderBody();
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    // ── מנהל הטופס: משתתפים וקישורים ──
    const PSTATUS = { waiting: 'לא נשלח', sent: 'נשלח', opened: 'פתח', completed: '✓ סיים' };
    // ההודעה נשמרת מחוץ לרינדור: אחרי שליחה הלוח מתרענן, וההודעה צריכה להישאר.
    const out = el('div', { class: 'people-out' });
    /**
     * שליחה למילוי — שלב 1: לכל שדה, מי ממלא אותו במסמך הזה (👤 אני / 🔗 הלקוח / שנינו).
     * שדה "לעולם לא בקישור" — נעול על "אני". אחרי השמירה — שלב 2: למי לשלוח (הלוח "שליחה למילוי").
     */
    function shareSetup() {
      const roles = (doc.schema.roles || []).filter(r => r.key !== 'owner');
      const link = roles.find(r => r.key === 'link');
      const others = roles.filter(r => r.key !== 'link');
      const label = el('input', { value: link ? link.label : 'לקוח', maxlength: 60, placeholder: 'למשל: משפחת כהן, לקוח' });
      const auth = el('input', { type: 'checkbox', checked: !!(link && link.auth === 'email') });
      // אפשרויות לכל שדה: אני, הלקוח, שנינו — ותפקידים שכבר מוגדרים בתבנית
      const opts = [['owner', '👤 אני'], ['link', '🔗 הלקוח'], ['owner,link', '👤🔗 שנינו'], ...others.map(r => [r.key, '🔗 ' + r.label])];
      const list = (doc.schema.fields || []).filter(f => !['calc', 'system'].includes(f.type));
      const rolesOf = (f) => (f.roles && f.roles.length ? f.roles : [f.role || 'owner']).join(',');
      const sels = list.map(f => {
        const cur = rolesOf(f);
        const s = el('select', { 'aria-label': 'מי ממלא: ' + f.label, disabled: !!f.no_link },
          ...(f.no_link ? [el('option', { value: 'owner', selected: true }, '🔒 רק אני')]
            : opts.map(([v, t]) => el('option', { value: v, selected: v === cur }, t)).concat(opts.some(([v]) => v === cur) ? [] : [el('option', { value: cur, selected: true }, cur)])));
        return [f, s];
      });
      const count = el('p', { class: 'share-count small' });
      const recount = () => {
        const n = sels.filter(([, s]) => s.value.split(',').some(k => k !== 'owner')).length;
        count.textContent = n ? `🔗 הלקוח ממלא ${n} שדות. את השאר — אתם (אפשר למלא אותם לפני השליחה או אחריה).` : 'עוד לא בחרתם אף שדה ללקוח.';
      };
      sels.forEach(([, s]) => s.addEventListener('change', recount));
      recount();
      const all = (v) => { for (const [f, s] of sels) if (!f.no_link) s.value = v; recount(); };
      const out = el('div');
      const go = el('button', { type: 'button', class: 'primary small', onclick: async () => {
        const fields = Object.fromEntries(sels.map(([f, s]) => [f.key, s.value.split(',')]));
        if (!Object.values(fields).some(r => r.some(k => k !== 'owner'))) { out.replaceChildren(notice('בחרו לפחות שדה אחד שהלקוח ממלא', 'bad')); return; }
        go.disabled = true;
        try {
          await flush();
          await api('doc_assign', { id, fields, label: label.value, auth: auth.checked ? 'email' : 'none' });
          dlg.close();
          await reload();
          renderBody();
          people.hidden = true; side(people, journal); renderPeople();
          msg.replaceChildren(notice('✓ נקבע מי ממלא מה. עכשיו — למי לשלוח: בוחרים איש קשר ושולחים, או מעתיקים קישור.', 'ok'));
        } catch (e) { go.disabled = false; out.replaceChildren(notice(e.message, 'bad')); }
      } }, 'הלאה: למי לשלוח ←');
      const dlg = window.DT.sheet('📨 שליחה למילוי — מי ממלא מה', el('div', { class: 'field-sheet share-setup' },
        el('p', { class: 'hint' }, 'לכל שדה: מי ממלא אותו במסמך הזה. ההגדרה של השיתוף הזה בלבד — התבנית לא משתנה. שדה "🔒 רק אני" נקבע בתבנית ולא ניתן ללקוח.'),
        el('div', { class: 'fields two' }, el('label', { class: 'f' }, el('span', {}, 'שם הממלא (רואים אותו בקישור ובשיתופים)'), label),
          el('label', { class: 'switch' }, auth, el('span', {}, 'קוד אימות למייל לפני מילוי'))),
        el('div', { class: 'row-btns' }, el('button', { type: 'button', class: 'small', onclick: () => all('link') }, 'הכול ללקוח'),
          el('button', { type: 'button', class: 'small', onclick: () => all('owner') }, 'הכול אצלי')),
        el('ul', { class: 'assign-list' }, sels.map(([f, s]) => el('li', {},
          el('span', { class: 'assign-label' }, (f.type === 'file' ? '📎 ' : '') + f.label + (f.required ? ' *' : '')), s))),
        count, out), { action: go });
    }

    /** אין במסמך ממלא בקישור: מה צריך לעשות כדי לשלוח ללקוח למלא. */
    function noLinkDialog() {
      const go = el('button', { type: 'button', class: 'primary small', onclick: () => { dlg.close(); location.hash = 'tpl=' + doc.template_id; } }, 'פתיחת התבנית בעורך');
      const dlg = window.DT.sheet('🔗 שליחה למילוי', el('div', { class: 'field-sheet' },
        el('p', {}, 'כדי לשלוח ללקוח קישור למילוי, צריך בתבנית "ממלא בקישור" — ושדות שהוא ממלא:'),
        el('ol', { class: 'steps' },
          el('li', {}, 'בעורך התבנית ← לשונית "👥 ממלאים" ← "+ ממלא בקישור" (למשל: לקוח).'),
          el('li', {}, 'בכל שדה שהלקוח ממלא: הגדרות השדה ← "מי ממלא" ← לקוח.'),
          el('li', {}, 'שומרים, ומנפיקים מסמך חדש מהתבנית.'),
          el('li', {}, 'במסמך: "🔗 שליחה למילוי" ← בוחרים איש קשר ← 📧 מייל, 💬 וואטסאפ, או 🔗 העתקת קישור.')),
        el('p', { class: 'hint' }, 'המסמך הזה הונפק לפני שהיה בתבנית ממלא בקישור — לכן אין לו קישור. מסמך חדש מהתבנית יקבל.')),
        { action: go });
    }

    // שליחה למילוי: אין עדיין ממלא בקישור — קודם "מי ממלא מה"; יש — ישר למי לשלוח (ושם "⚙️ מי ממלא מה" לשינוי)
    function openFill() {
      if (editable() && !(doc.participants || []).length) return shareSetup();
      side(people, journal); renderPeople();
    }

    function renderPeople() {
      if (fill || people.hidden) return;
      people.replaceChildren(...[
        el('div', { class: 'row' }, el('h2', {}, '🔗 שליחה למילוי'), el('button', { class: 'link small', onclick: () => { people.hidden = true; } }, 'סגירה')),
        editable() && lvl() >= 3 && !doc.participants.some(p => p.status === 'completed')
          ? el('button', { type: 'button', class: 'small', onclick: shareSetup }, '⚙️ מי ממלא מה — שדות הלקוח בשיתוף הזה') : null,
        el('p', { class: 'muted small' }, 'לכל ממלא: בוחרים איש קשר, או כותבים פרטים ← שולחים. 💬 לוואטסאפ מספיק מספר טלפון — בלי מייל ובלי חשבון.'),
        doc.schema.sequential ? el('p', { class: 'muted small' }, 'לפי הסדר: הקישור הבא נשלח אוטומטית במייל כשהקודם מסיים.') : null,
        ...doc.participants.map(p => {
          // ממלא בקישור = איש קשר: בוחרים מהרשימה, או כותבים פרטים — והם נשמרים כאיש קשר.
          // שינוי כאן מעדכן את הכרטיס, ועדכון בכרטיס מגיע לכאן.
          let cid = p.contact_id || null;
          const name = el('input', { value: p.name, placeholder: 'שם', maxlength: 80, 'aria-label': 'שם' });
          const email = el('input', { value: p.email, placeholder: 'מייל', type: 'email', dir: 'ltr', 'aria-label': 'מייל' });
          const phone = el('input', { value: p.phone, placeholder: 'טלפון (לוואטסאפ)', dir: 'ltr', 'aria-label': 'טלפון' });
          const linkInfo = el('p', { class: 'muted small person-contact' });
          const paintLink = () => linkInfo.replaceChildren(...(cid
            ? ['👤 מקושר לאיש קשר — שינוי כאן מעדכן גם את הכרטיס. ',
               el('button', { type: 'button', class: 'link small', onclick: async () => {
                 const c = (await window.DTCatalog.contacts(true)).find(x => x.id === cid);
                 if (c && await window.DTCatalog.contactDialog(c)) { await reload(); renderPeople(); }
               } }, 'פתיחת הכרטיס')]
            : ['📇 בוחרים מאנשי הקשר — או כותבים פרטים, והם יישמרו כאיש קשר חדש.']));
          paintLink();
          const saveContact = async () => {
            const r = await api('part_contact', { id, participant_id: p.id, contact_id: cid || 0, name: name.value, email: email.value, phone: phone.value });
            cid = r.contact_id;
            if (r.contact) { name.value = r.contact.name; email.value = r.contact.email; phone.value = r.contact.phone; }
            Object.assign(p, { contact_id: cid, name: name.value, email: email.value, phone: phone.value });
            window.DTCatalog.contacts(true).catch(() => {});   // אולי נוצר כרטיס חדש
            paintLink();
          };
          const pickBox = el('span');
          window.DTCatalog.contactPicker(cid, (c) => {
            cid = c ? c.id : null;
            name.value = c ? c.name : ''; email.value = c ? c.email : ''; phone.value = c ? c.phone : '';
            paintLink();
          }, { empty: '— בחירה מאנשי הקשר —' }).then(sel => pickBox.replaceChildren(sel)).catch(() => {});
          const act = async (via) => {
            out.replaceChildren();
            // חלון וואטסאפ נפתח עכשיו, בתוך הלחיצה — אחרי await דפדפנים חוסמים חלונות.
            const wa = via === 'whatsapp' ? window.open('', '_blank') : null;
            try {
              await saveContact();
              const r = await api('part_link', { id, participant_id: p.id, via });
              if (wa) wa.location.href = r.whatsapp;
              out.replaceChildren(...[notice(r.emailed ? 'נשלח במייל ל-' + email.value : 'הקישור החדש (הקודם בוטל):', 'ok'),
                via === 'link' ? el('input', { class: 'link-box', value: r.url, readonly: true, dir: 'ltr', onclick: (e) => e.target.select() }) : null].filter(Boolean));
              if (via === 'link') navigator.clipboard?.writeText(r.url).catch(() => {});
              await reload();
              renderBody();   // השדות של המשתתף ננעלו אצל המנפיק
            } catch (e) {
              if (wa) wa.close();
              out.replaceChildren(notice(e.message, 'bad'));
            }
          };
          const done = !!p.completed_at;
          // וואטסאפ — לטלפון בלי חשבון ובלי מייל. הכפתור הראשי הוא מה שיש: טלפון בלי מייל → וואטסאפ
          const waBtn = el('button', { class: 'small', onclick: () => act('whatsapp') }, '💬 וואטסאפ');
          const mailBtn = el('button', { class: 'small', onclick: () => act('email') }, '✉ מייל');
          const paintMain = () => {
            const wa = phone.value.trim() !== '' && email.value.trim() === '';
            waBtn.classList.toggle('primary', wa); mailBtn.classList.toggle('primary', !wa);
          };
          phone.addEventListener('input', paintMain); email.addEventListener('input', paintMain); paintMain();
          return el('div', { class: 'person' },
            el('div', { class: 'row' }, el('strong', {}, p.role),
              el('span', { class: 'badge ' + (done ? 'ok' : p.opened_at ? 'warn' : 'muted') }, PSTATUS[p.status] || p.status)),
            p.auth === 'email' ? el('div', { class: 'muted small' }, '🔒 אימות בקוד למייל') : null,
            done ? (p.name ? el('div', { class: 'muted small' }, '👤 ' + p.name) : null) : el('div', { class: 'person-fields' }, pickBox, name, email, phone,
              el('button', { type: 'button', class: 'small', onclick: async () => {
                out.replaceChildren();
                try { await saveContact(); out.replaceChildren(notice(cid ? '✓ נשמר באנשי הקשר' : 'הממלא נותק מאיש הקשר', 'ok')); }
                catch (e) { out.replaceChildren(notice(e.message, 'bad')); }
              } }, '💾 שמירה')),
            done ? null : linkInfo,
            p.blocked && !done ? el('p', { class: 'muted small' }, p.blocked) : null,
            done || p.blocked ? null : el('div', { class: 'ops' },
              waBtn, mailBtn,
              el('button', { class: 'small', onclick: () => act('link') }, '🔗 העתקת קישור')),
            p.missing.length && !done ? el('div', { class: 'muted small' }, 'חסר אצלו: ' + p.missing.join(', ')) : null);
        }),
        out].filter(Boolean));
    }

    // ── שליחה לאישור (התבנית מחייבת אישור של בעליה) ──
    async function submit() {
      await flush();
      if (doc.progress.required && doc.progress.filled < doc.progress.required) {
        msg.replaceChildren(notice('חסרים שדות חובה: ' + (doc.progress.missing || []).join(', '), 'bad'));
        return;
      }
      try {
        const r = await api('doc_submit', { id });
        await reload();
        msg.replaceChildren(notice('נשלח לאישור של ' + r.approver + '. כשיאשר — תקבלו מייל.', 'ok'));
        renderBody();
      } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    }

    // ── סיום ──
    async function complete() {
      await flush();
      if (doc.progress.required && doc.progress.filled < doc.progress.required) {
        msg.replaceChildren(notice('חסרים שדות חובה: ' + (doc.progress.missing || []).join(', '), 'bad'));
        return;
      }
      if (!confirm('לסיים ולנעול את המסמך? אחרי זה אי אפשר לשנות בו דבר.')) return;
      try {
        await api('doc_complete', { id });
        await reload();
        msg.replaceChildren(notice('המסמך הושלם וננעל. אפשר להוריד PDF.', 'ok'));
        renderBody();
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    // ── Word: הורדה או תצוגה מקדימה של הקובץ הממולא ──
    async function wordFile(preview) {
      await flush();
      try {
        const res = fill ? await fetch('api.php?action=fill_docx', { headers: src.asset[1] })
          : await fetch(`api.php?action=doc_docx&id=${id}${preview ? '&preview=1' : ''}`, { credentials: 'same-origin' });
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'ההורדה נכשלה');
        const blob = await res.blob();
        if (!preview) {
          const a = el('a', { href: URL.createObjectURL(blob),
            download: ((doc.number ? doc.number + ' ' : '') + title.value).replace(/[\\/:*?"<>|]+/g, '_') + '.docx' });
          document.body.append(a); a.click(); a.remove();
          reload().catch(() => {});
          return;
        }
        const dlg = el('dialog', { class: 'sig-dialog word-dialog' }, el('div', { class: 'row' },
          el('h2', {}, 'תצוגה מקדימה'), el('button', { onclick: () => dlg.close() }, 'סגירה')),
          el('p', { class: 'muted small' }, 'קרובה למקור. הקובץ שיורד הוא Word עצמו — שם העיצוב מדויק.'),
          await window.DTEditor.wordPreview(await blob.arrayBuffer()));
        dlg.addEventListener('close', () => dlg.remove());
        document.body.append(dlg);
        dlg.showModal();
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    // ── PDF ──
    /** דף האישור לבד: טביעת אצבע ויומן — למי שצריך הוכחה, בלי להדביק אותו למסמך. */
    /** שכפול: מסמך חדש מאותה תבנית עם הערכים של זה — להדפסה חוזרת או לשינוי קטן. החתימות והקבצים לא עוברים. */
    async function duplicate(e) {
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        await flush();
        if (inflight) await inflight;
        const r = await api('doc_duplicate', { id });
        location.hash = 'doc=' + r.id;
        window.DT.toast(`נוצר ${r.number || 'מסמך חדש'} — עותק של ${doc.number || 'המסמך'}` +
          (r.skipped.length ? `. לא הועתקו (חותמים / מעלים מחדש): ${r.skipped.join(', ')}` : ''));
      } catch (err) {
        btn.disabled = false;
        msg.replaceChildren(notice(err.message, 'bad'));
      }
    }

    async function downloadCertificate() {
      try {
        const bytes = await certificatePdf(doc);
        const a = el('a', { href: URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' })),
          download: ('דף אישור ' + (doc.number ? doc.number + ' ' : '') + doc.title).replace(/[\\/:*?"<>|]+/g, '_') + '.pdf' });
        document.body.append(a); a.click(); a.remove();
      } catch (e) {
        msg.replaceChildren(notice('הפקת דף האישור נכשלה: ' + e.message, 'bad'));
      }
    }

    async function downloadPdf() {
      if (!fill && outLocked()) { msg.replaceChildren(notice('הורדה — אחרי ש' + doc.approver.name + ' יאשר את המסמך.', 'bad')); return; }
      pdfBtn.disabled = true;
      pdfBtn.textContent = 'מכין PDF…';
      try {
        await flush();
        if (!pagesCache) pagesCache = await window.DTEditor.renderBackground(src.asset[0], doc.mime, src.asset[1]);
        const bytes = await buildPdf(doc, values, pagesCache.bytes);
        const a = el('a', { href: URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' })),
          download: ((doc.number ? doc.number + ' ' : '') + title.value).replace(/[\\/:*?"<>|]+/g, '_') + '.pdf' });
        document.body.append(a); a.click(); a.remove();
        (fill ? api('fill_downloaded', { format: 'pdf' }) : api('doc_downloaded', { id }).then(reload)).catch(() => {});
      } catch (e) {
        msg.replaceChildren(notice('הפקת ה-PDF נכשלה: ' + e.message, 'bad'));
        // נתפס כאן — ולכן לא מגיע לבד ללוג המעקב. שולחים, כדי שייפתח "⚠️ לבירור" עם ההקשר
        api('client_error', { message: 'PDF: ' + String(e.message).slice(0, 400), where: String(e.stack || '').split('\n').slice(1, 3).join(' ').trim().slice(0, 300),
          page: location.hash.slice(0, 120) }).catch(() => {});
      } finally {
        pdfBtn.disabled = false;
        pdfBtn.replaceChildren(el('span', { 'aria-hidden': 'true' }, '⬇️'), el('span', { class: 'act-word' }, 'PDF'));
      }
    }

    renderBody();
  }

  // ── בניית ה-PDF ──────────────────────────────────────────

  // סדר תצוגה לעברית ב-PDF.
  //
  // visual() מחזיר את המחרוזת בסדר שבו היא נראית משמאל לימין: רצפים עבריים
  // הפוכים, רצפים לטיניים/מספרים כמו שהם, וסדר הרצפים הפוך (פסקה מימין
  // לשמאל). סימנים ניטרליים (רווח, נקודה, מקף, נקודתיים) בין שני רצפים
  // לטיניים שייכים להם — כך "050-123 4567" ו-"13.10.2026, 15:53" נשארים שלמים.
  //
  // pdfText() מפצה על fontkit: כשהאות הראשונה במחרוזת עברית, fontkit הופך
  // בעצמו את סדר כל התווים (בלי להחליף סוגריים). אז מוסרים לו את ההיפוך — והוא
  // מחזיר אותה לסדר התצוגה. נבדק מול ציור בפועל של pdf-lib 1.17.1 + fontkit 1.1.1.
  const HEB = /[֐-׿יִ-ﭏ]/;
  const MIRROR = { '(': ')', ')': '(', '[': ']', ']': '[', '{': '}', '}': '{', '<': '>', '>': '<' };
  const mirror = (c) => MIRROR[c] || c;
  function visual(str) {
    if (!HEB.test(str)) return str;
    const runs = [];
    for (const m of str.matchAll(/([֐-׿יִ-ﭏ]+)|([A-Za-z0-9]+)|([^֐-׿יִ-ﭏA-Za-z0-9]+)/g)) {
      runs.push({ t: m[1] ? 'r' : m[2] ? 'l' : 'n', s: m[0] });
    }
    for (let i = 1; i < runs.length - 1; i++) {
      if (runs[i].t === 'n' && runs[i - 1].t === 'l' && runs[i + 1].t === 'l') runs[i].t = 'l';
    }
    const merged = [];
    for (const r of runs) {
      const last = merged[merged.length - 1];
      if (last && last.t === 'l' && r.t === 'l') last.s += r.s; else merged.push({ ...r });
    }
    return merged.reverse().map(r => r.t === 'l' ? r.s : [...r.s].reverse().map(mirror).join('')).join('');
  }
  function pdfText(str) {
    const v = visual(str);
    const first = v.match(/[A-Za-z֐-׿יִ-ﭏ]/);
    return first && HEB.test(first[0]) ? [...v].reverse().join('') : v;
  }

  function wrapLines(text, font, size, maxW) {
    const out = [];
    for (const para of String(text).split(/\r?\n/)) {
      let line = '';
      for (const word of para.split(/\s+/)) {
        const tryLine = line ? line + ' ' + word : word;
        if (font.widthOfTextAtSize(pdfText(tryLine), size) <= maxW || !line) line = tryLine;
        else { out.push(line); line = word; }
      }
      out.push(line);
    }
    return out;
  }

  const A4 = [595.28, 841.89];
  /** דפי טקסט בעברית (נספח, דף אישור) — מימין, עם מעבר לדף חדש. */
  function pageWriter(pdf, font) {
    const { rgb } = window.PDFLib;
    return (heading, blocks) => {
      let page = pdf.addPage(A4), y = A4[1] - 60;
      const line = (t, size, color) => {
        if (y < 50) { page = pdf.addPage(A4); y = A4[1] - 60; }
        const v = pdfText(t);
        page.drawText(v, { x: A4[0] - 50 - font.widthOfTextAtSize(v, size), y, size, font, color: color || rgb(0, 0, 0) });
        y -= size * 1.5;
      };
      line(heading, 18);
      y -= 6;
      for (const b of blocks) {
        if (b.title) line(b.title, 12, rgb(0.3, 0.3, 0.3));
        for (const l of wrapLines(b.text, font, 11, A4[0] - 100)) line(l, 11);
        y -= 8;
      }
    };
  }

  /**
   * היומן לתצוגה: שמירות רצופות (אותו מי, אותה כתובת) — שורה אחת עם מונה וטווח שעות.
   * רק בתצוגה; היומן עצמו (שרשרת שאי אפשר לשנות) נשאר כמו שהוא.
   */
  function collapseEvents(events) {
    const out = [];
    for (const e of events) {
      const last = out[out.length - 1];
      if (last && e.type === 'values_saved' && last.type === 'values_saved' && (last.by || '') === (e.by || '') && (last.ip || '') === (e.ip || '')) {
        last.n++; last.until = e.at;
        last.changes.push(...(e.detail?.changes || []));
        continue;
      }
      out.push({ ...e, n: 1, until: e.at, changes: [...(e.detail?.changes || [])] });
    }
    return out;
  }
  const hhmm = (iso) => new Date(iso).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
  const eventLine = (e) => `${fmtDate(e.at)}${e.n > 1 && hhmm(e.until) !== hhmm(e.at) ? '–' + hhmm(e.until) : ''} · ${EVENTS[e.type] || e.type}`
    + (e.n > 1 ? ` ×${e.n}` : '') + (e.ip ? ' · IP ' + e.ip : '');

  /** תוכן דף האישור: המסמך, מתי, טביעת האצבע של הערכים, והיומן (מאוחד). */
  function certBlocks(doc) {
    return [
      { text: `מסמך: ${doc.title}${doc.number ? ' (' + doc.number + ')' : ''}` },
      { text: `נוצר: ${fmtDate(doc.created_at)} · הושלם: ${fmtDate(doc.completed_at)}` },
      { text: `טביעת אצבע (SHA-256) של הערכים: ${doc.final_hash}` },
      { title: 'יומן', text: collapseEvents(doc.events).map(eventLine).join('\n') },
    ];
  }

  /** דף האישור לבד — PDF נפרד, למסמך שהושלם. */
  async function certificatePdf(doc) {
    await loadScript(PDFLIB);
    await loadScript(FONTKIT);
    const pdf = await window.PDFLib.PDFDocument.create();
    pdf.registerFontkit(window.fontkit);
    const font = await pdf.embedFont(await (await fetch(FONT)).arrayBuffer(), { subset: true });
    pageWriter(pdf, font)('דף אישור', certBlocks(doc));
    pdf.setTitle('דף אישור — ' + doc.title);
    pdf.setProducer('תבניות מסמכים');
    return pdf.save();
  }

  /**
   * רשימת ערכים: האפשרויות ברמה של השדה, מסוננות לפי מה שכבר נבחר ברמות האחרות (known: {רמה: ערך}).
   * רמה שמעל — מסננת (בית העלמין שנבחר מצמצם את הגושים); רמה שמתחת — לא מגבילה.
   */
  /** רשימה גדולה: מטמון של תשובות השרת (רשימה+רמה+מה שנבחר מעל), ומי שמביא / מצייר מחדש (נקבע ב-open). */
  const bigLists = { cache: new Map(), pending: new Set(), fetch: null, done: null };
  function listOptions(lists, ref, known) {
    const L = lists && lists[String(ref.id)];
    if (!L) return [];
    if (L.big) {
      const up = {};
      for (const [lv, v] of Object.entries(known)) if (Number(lv) < ref.level && v) up[lv] = v;
      const k = ref.id + '|' + ref.level + '|' + JSON.stringify(up);
      if (bigLists.cache.has(k)) return bigLists.cache.get(k);
      if (bigLists.fetch && !bigLists.pending.has(k)) {
        bigLists.pending.add(k);
        bigLists.fetch(ref, up).then(v => bigLists.cache.set(k, v), () => bigLists.cache.set(k, []))
          .finally(() => { bigLists.pending.delete(k); if (bigLists.done) bigLists.done(); });
      }
      return null;   // נטען
    }
    const out = new Set();
    for (const row of L.rows) {
      if (!row[ref.level]) continue;
      if (Object.entries(known).every(([lv, v]) => Number(lv) >= ref.level || !v || row[lv] === v)) out.add(row[ref.level]);
    }
    return [...out];
  }
  /** הערך כפי שהוא נכתב במסמך שיוצא: התרגום לשפה שנקבעה לשדה (אם יש), אחרת הערך עצמו. */
  function listOut(lists, ref, v) { return listCol(lists, ref, v, ref && ref.lang); }
  /** מה הממלא רואה בבחירה: העמודה שנקבעה לשדה ("הממלא רואה ובוחר"); הערך שנשמר — תמיד העמודה הראשית. */
  function listShow(lists, ref, v) { return listCol(lists, ref, v, ref && ref.show); }
  function listCol(lists, ref, v, lang) {
    v = String(v ?? '');
    if (!ref || !lang || v === '') return v;
    const t = lists && lists[String(ref.id)] && lists[String(ref.id)].translations;
    return (t && t[String(ref.level)] && t[String(ref.level)][v] && t[String(ref.level)][v][lang]) || v;
  }
  /** ממלא select באפשרויות, ושומר את הערך הנוכחי גם אם כבר לא ברשימה (מסומן — לא נמחק בשקט). */
  function fillSelect(sel, opts, cur, emptyLabel, label = (v) => v) {
    const { el } = window.DT;
    if (opts === null) {   // רשימה גדולה — האפשרויות בדרך מהשרת; הערך הנוכחי נשאר כמו שהוא
      sel.replaceChildren(el('option', { value: '' }, emptyLabel), ...(cur ? [el('option', { value: cur, selected: true }, label(cur))] : []),
        el('option', { value: '', disabled: true }, 'טוען…'));
      return;
    }
    sel.replaceChildren(el('option', { value: '' }, emptyLabel), ...opts.map(o => el('option', { value: o, selected: o === cur }, label(o))),
      ...(cur && !opts.includes(cur) ? [el('option', { value: cur, selected: true }, label(cur) + ' ⚠ לא ברשימה')] : []));
  }
  let dlSeq = 0;
  /** הצעות לשדה טקסט מרשימה: <datalist> צמוד. */
  function datalistFor(input, opts) {
    const { el } = window.DT;
    const dl = el('datalist', { id: 'dl-' + (++dlSeq) }, (opts || []).map(o => el('option', { value: o })));
    input.setAttribute('list', dl.id);
    return dl;
  }

  /** קיבוץ בטבלה: כמה עמודות ראשונות מזהות קבוצה (גוש › חלקה › שורה › אחוזה), והאם שורה i ממשיכה את הקבוצה של הקודמת. */
  const groupN = (f) => f.group === true ? 1 : Math.max(0, Number(f.group) || 0);
  const sameGroup = (f, rows, i) => {
    const n = groupN(f), cols = (f.columns || []).slice(0, n);
    return n > 0 && i > 0 && cols.some(c => String(rows[i][c.key] ?? '') !== '')
      && cols.every(c => String(rows[i][c.key] ?? '') === String(rows[i - 1][c.key] ?? ''));
  };

  async function buildPdf(doc, values, srcBytes) {
    await loadScript(PDFLIB);
    await loadScript(FONTKIT);
    const { PDFDocument, rgb, degrees } = window.PDFLib;
    const fontBytes = await (await fetch(FONT)).arrayBuffer();

    let pdf;
    if (doc.mime === 'application/pdf') {
      pdf = await PDFDocument.load(srcBytes, { ignoreEncryption: true });
      // הגדרות תבנית שמצורפות לקובץ (dt-template.json) — לא יוצאות במסמך שנשלח הלאה
      try { const nm = pdf.catalog.lookup(window.PDFLib.PDFName.of('Names')); if (nm) nm.delete(window.PDFLib.PDFName.of('EmbeddedFiles')); } catch (e) { /* אין */ }
      // שלב 10: לטופס PDF יש שדות משלו, שמוצגים מעל תוכן הדף — שדה ריק עם רקע
      // היה מסתיר את מה שנכתוב. משטחים אותם לתוכן הדף לפני שכותבים מעליהם.
      try {
        const form = pdf.getForm();
        if (form.getFields().length) {
          form.flatten({ updateFieldAppearances: false });
          stripForm(pdf, false);   // pdf-lib מוחק את השדות אבל משאיר הפניות אליהם ב-Annots של הדף — מנקים
        }
      } catch (e) {
        // טופס עם הפניה שבורה (שדה שמצביע על אובייקט שלא קיים): pdf-lib לא מצליח לשטח אותו, וגם לא
        // למחוק שדה-שדה. מורידים את שכבת הטופס כולה ישירות — התוכן של הדף נשאר, ומה שנכתוב מעליו
        stripForm(pdf, true);
      }
    } else {
      pdf = await PDFDocument.create();
      const p = doc.schema.pages[0];
      let img;
      if (doc.mime === 'image/jpeg') img = await pdf.embedJpg(srcBytes);
      else if (doc.mime === 'image/png') img = await pdf.embedPng(srcBytes);
      else { // WEBP — pdf-lib לא תומך; ממירים ל-PNG בדפדפן
        const bmp = await createImageBitmap(new Blob([srcBytes]));
        const c = document.createElement('canvas'); c.width = bmp.width; c.height = bmp.height;
        c.getContext('2d').drawImage(bmp, 0, 0);
        img = await pdf.embedPng(await (await new Promise(r => c.toBlob(r, 'image/png'))).arrayBuffer());
      }
      pdf.addPage([p.w, p.h]).drawImage(img, { x: 0, y: 0, width: p.w, height: p.h });
    }
    pdf.registerFontkit(window.fontkit);
    const font = await pdf.embedFont(fontBytes, { subset: true });
    const fontDefault = font;
    // גופנים שהועלו: אותו קובץ שעל המסך. לא נטען — נופלים לגופן ברירת המחדל
    const fonts = new Map();
    for (const fid of new Set(doc.schema.fields.map(f => f.font).filter(Boolean))) {
      try { fonts.set(fid, await pdf.embedFont(await window.DTFonts.bytes(fid, { doc: doc.id }), { subset: true })); }
      catch (e) { /* ברירת מחדל */ }
    }
    // צבע הטקסט: של השדה (#rrggbb), או שחור — ברירת המחדל
    const BLACK = rgb(0, 0, 0);
    const inkOf = (f) => /^#[0-9a-f]{6}$/i.test(f.color || '')
      ? rgb(...[1, 3, 5].map(i => parseInt(f.color.slice(i, i + 2), 16) / 255)) : BLACK;
    const pages = pdf.getPages();
    const annex = [];

    /**
     * הדף כפי שרואים אותו: האזור הגלוי (CropBox) והסיבוב — בדיוק כמו המסך (pdf.js).
     * מחזיר את מידות הדף הנראה, ו-pt(u, v): נקודה בדף הנראה (מלמעלה-ימין… משמאל
     * למעלה, y כלפי מטה) → נקודה במערכת של ה-PDF. בלי זה, שדה בדף מסובב או
     * חתוך יוצא במקום אחר מזה שעל המסך.
     */
    const frame = (page) => {
      const cb = page.getCropBox();
      const r = ((page.getRotation().angle % 360) + 360) % 360;
      const W = r % 180 ? cb.height : cb.width, H = r % 180 ? cb.width : cb.height;
      const pt = (u, v) => {
        const a = u / W, b = v / H;
        if (r === 90) return { x: cb.x + b * cb.width, y: cb.y + a * cb.height };
        if (r === 180) return { x: cb.x + (1 - a) * cb.width, y: cb.y + b * cb.height };
        if (r === 270) return { x: cb.x + (1 - b) * cb.width, y: cb.y + (1 - a) * cb.height };
        return { x: cb.x + a * cb.width, y: cb.y + (1 - b) * cb.height };
      };
      return { W, H, pt, rot: degrees(r) };
    };

    const vis = window.DTCalc.visibleKeys(doc.schema, values);   // שלב 11: שדה מוסתר — לא נכתב
    const flows = await layoutFlows(vis);
    for (const f of doc.schema.fields) {
      if ((values[f.key] ?? '') === '' || !pages[f.page] || !vis.has(f.key) || f.print === false) continue;   // "לא מודפס" — לא נכתב
      const fl = flows.get(f.page);
      // דף עם טבלה "לפי מספר השורות": השדה יכול לעבור מקום, או לחזור בכמה דפים
      for (const P of fl ? fl.place(f) : [{ page: pages[f.page], fr: frame(pages[f.page]), dv: 0 }]) await drawField(f, P.page, P.fr, P.dv);
    }

    async function drawField(f, page, fr, dv) {
      let raw = values[f.key] ?? '';
      // הכול בנקודות של הדף הנראה: (bx, by) — הפינה העליונה של השדה, y כלפי מטה. H0 — גובה הדף המקורי
      const { W, H, pt, rot } = fr, H0 = fr.H0 ?? fr.H;
      const fc = window.DTFmt.rich(f, raw)?.color;   // [אדום] במבנה (למשל מספר שלילי) — גובר על צבע השדה
      const ink = fc ? inkOf({ color: fc }) : inkOf(f);
      const bx = f.x * W, by = f.y * H0 + dv, bw = f.w * W, bh = f.h * H0;   // dv — הזזה אחרי טבלה שמתכווצת / מתארכת

      if (f.type === 'checkbox') {
        if (raw !== '1') return;
        const s = Math.min(bw, bh), cx = bx + bw / 2, cy = by + bh / 2;
        const line = (x1, y1, x2, y2) => page.drawLine({ start: pt(x1, y1), end: pt(x2, y2), thickness: Math.max(1, s / 8), color: ink });
        line(cx - s * 0.35, cy, cx - s * 0.08, cy + s * 0.3);
        line(cx - s * 0.08, cy + s * 0.3, cx + s * 0.4, cy - s * 0.35);
        return;
      }
      if (f.type === 'table') {
        if (!flows.has(f.page) || flows.get(f.page).table !== f) drawTable(f, page, { W, H, pt, rot }, ink, fonts.get(f.font) || fontDefault, { bx, by, bw, bh });
        return;
      }
      if (f.type === 'file') {
        let ids = [];
        try { ids = JSON.parse(raw); } catch (e) { /* ריק */ }
        if (!ids.length) return;
        const names = ids.map(aid => (doc.attachments || []).find(a => a.id === aid)?.name || 'קובץ');
        raw = 'מצורף: ' + names.join(', ');
      }
      if (f.type === 'signature') {
        const png = await pdf.embedPng(raw);
        const k = Math.min(bw / png.width, bh / png.height);
        const iw = png.width * k, ih = png.height * k;
        // pdf-lib מצייר תמונה מהפינה השמאלית-תחתונה שלה, ומסובב סביבה
        const o = pt(bx + (bw - iw) / 2, by + (bh + ih) / 2);
        page.drawImage(png, { x: o.x, y: o.y, width: iw, height: ih, rotate: rot });
        return;
      }

      const font = fonts.get(f.font) || fontDefault;
      const out = f.list ? listOut(doc.lists, f.list, raw) : raw;   // רשימת ערכים — בשפת המסמך
      const text = window.DTFmt.apply(f, out) ?? (f.type === 'date' ? ddmmyyyy(raw) : f.type === 'calc' ? window.DTCalc.display(raw, f.format)
        : f.type === 'system' ? sysText(f, raw) : f.type === 'select' ? optText(f, out) : out);   // מבנה מותאם קודם
      let size = f.fontSize || 11;
      const pad = 2;
      const fits = (sz) => {
        const lines = f.type === 'textarea' ? wrapLines(text, font, sz, bw - pad * 2) : [text];
        const tooWide = lines.some(l => font.widthOfTextAtSize(pdfText(l), sz) > bw - pad * 2);
        return { lines, ok: !tooWide && lines.length * sz * 1.25 <= bh + sz * 0.3 };
      };
      let r = fits(size);
      if (!r.ok && f.overflow !== 'annex') {
        while (!r.ok && size > 4) { size -= 0.5; r = fits(size); }
      }
      if (!r.ok && f.overflow === 'annex') {
        annex.push({ label: f.label, text });
        r = { lines: ['(ראו נספח ' + annex.length + ')'] };
      }
      const lh = size * 1.25;
      const blockH = r.lines.length * lh;
      // קו הבסיס של השורה הראשונה, בדף הנראה — לפי המיקום שנבחר: למעלה / באמצע / למטה
      let y = f.valign === 'top' ? by + pad + size * 0.95
        : f.valign === 'bottom' ? by + bh - pad - size * 0.25 - (r.lines.length - 1) * lh
        : by + (bh - blockH) / 2 + size;
      for (const line of r.lines) {
        const v = pdfText(line);
        const w = font.widthOfTextAtSize(v, size);
        const x = f.align === 'left' ? bx + pad : f.align === 'center' ? bx + (bw - w) / 2 : bx + bw - w - pad;
        const o = pt(x, y);
        page.drawText(v, { x: o.x, y: o.y, size, font, color: ink, rotate: rot });
        y += lh;
      }
    }

    /**
     * טבלה "לפי מספר השורות" (fit: 'rows'): הטבלה תופסת רק את השורות שיש, ומה שמתחתיה ברקע (מחירים, פרטי בנק…)
     * עולה אחריה — עד "השוליים התחתונים" (keep_bottom, מ״מ), שנשארים במקומם (כותרת תחתונה של נייר המכתבים).
     * ארוכה מהאזור שלה: overflow 'grow' — הדף מתארך; 'pages' — נשברת לדפים, והחלק העליון של הדף, כותרת
     * הטבלה והשוליים התחתונים חוזרים בכל דף; מה שאחרי הטבלה — רק בדף האחרון. אחרת (נספח / הקטנה) — כרגיל.
     * הרקע נבנה מחדש מעותק של הדף, באזורים חתוכים. דף מסובב או חתוך (CropBox) — נשאר כמו שהוא.
     */
    async function layoutFlows(vis) {
      const { pushGraphicsState, popGraphicsState, rectangle, clip, endPath, PDFName } = window.PDFLib;
      const out = new Map();
      for (const f of doc.schema.fields) {
        if (f.type !== 'table' || f.fit !== 'rows' || out.has(f.page) || !pages[f.page] || !vis.has(f.key) || f.print === false) continue;
        const src = pages[f.page];
        const cb = src.getCropBox(), mb = src.getMediaBox();
        if ((src.getRotation().angle % 360) || cb.x || cb.y || mb.x || mb.y || Math.abs(cb.width - mb.width) > 0.5 || Math.abs(cb.height - mb.height) > 0.5) continue;
        const W = cb.width, H0 = cb.height;
        const font = fonts.get(f.font) || fontDefault;
        const bx = f.x * W, tTop = f.y * H0, bw = f.w * W, bh = f.h * H0, tb = tTop + bh;
        const fe = Math.min(H0, Math.max(tb, H0 - (Number(f.keep_bottom ?? 25) || 0) * 72 / 25.4));
        const sum = (a) => a.reduce((x, y) => x + y, 0);
        const long = f.overflow === 'grow' || f.overflow === 'pages';
        const M = measureTable(f, font, bw, long ? Infinity : bh);   // דף ארוך / דפים — לא מקטינים את הגופן בשביל הגובה
        const n = M.rows.length;
        const chunks = [];   // {from, to, R}: השורות בכל דף, והגובה שהטבלה תופסת בו
        if (!n || !M.cols.length) chunks.push({ from: 0, to: 0, R: 0 });
        else if (f.overflow === 'pages') {
          for (let i = 0; i < n;) {
            const rest = M.head + sum(M.hs.slice(i));
            if (rest <= bh + 0.5) { chunks.push({ from: i, to: n, R: rest }); i = n; break; }
            let j = i, R = M.head;
            while (j < n && R + M.hs[j] <= fe - tTop + 0.5) R += M.hs[j++];
            if (j === i) R += M.hs[j++];   // שורה גבוהה מדף שלם — לפחות שורה אחת בכל דף
            chunks.push({ from: i, to: j, R });
            i = j;
            if (i >= n) chunks.push({ from: n, to: n, R: 0 });   // לא נשאר מקום למה שאחרי הטבלה — דף משלו
          }
        } else if (f.overflow === 'grow') chunks.push({ from: 0, to: n, R: M.head + sum(M.hs) });
        else {
          // נספח / הקטנה: כמו בטבלה קבועה — רק מה שנכנס באזור, ומה שאחריו עולה
          let R = M.head, k = 0;
          while (k < n && R + M.hs[k] <= bh + 0.5) R += M.hs[k++];
          if (k < n && f.overflow === 'annex') R = Math.min(bh, R + M.size * 1.6);
          chunks.push({ from: 0, to: n, R, clipped: true });
        }
        const copy = (await pdf.copyPages(pdf, [pdf.getPages().indexOf(src)]))[0];
        const emb = await pdf.embedPage(copy);
        const built = [];
        for (let k = 0; k < chunks.length; k++) {
          const last = k === chunks.length - 1, ch = chunks[k];
          const delta = last ? tTop + ch.R - tb : 0;        // כמה זז מה שאחרי הטבלה (שלילי — עולה)
          const extra = f.overflow === 'grow' ? Math.max(0, delta) : 0;   // דף ארוך: בכמה הדף מתארך
          const Ht = H0 + extra;
          let page = src;
          if (k > 0) page = pdf.insertPage(pdf.getPages().indexOf(built[k - 1].page) + 1, [W, Ht]);
          else {
            // מנקים את התוכן של הדף ומציירים אותו מחדש מהעותק
            src.node.set(PDFName.of('Contents'), pdf.context.obj([]));
            src.contentStream = undefined; src.contentStreamRef = undefined;
          }
          for (const box of ['MediaBox', 'CropBox', 'BleedBox', 'TrimBox', 'ArtBox']) {
            if (box === 'MediaBox' || page.node.get(PDFName.of(box))) page.node.set(PDFName.of(box), pdf.context.obj([0, 0, W, Ht]));
          }
          const region = (v0, v1, s) => {
            if (v1 - v0 < 0.5) return;
            page.pushOperators(pushGraphicsState(), rectangle(0, Ht - (v1 + s), W, v1 - v0), clip(), endPath());
            page.drawPage(emb, { x: 0, y: Ht - s - H0 });
            page.pushOperators(popGraphicsState());
          };
          region(0, tTop, 0);
          if (last) region(tb, fe, delta);
          region(fe, H0, extra);
          const fr = { W, H: Ht, H0, pt: (u, v) => ({ x: u, y: Ht - v }), rot: degrees(0) };
          built.push({ page, fr, delta, extra, last });
          if (ch.to > ch.from || (ch.clipped && n)) {
            drawTable(f, page, fr, inkOf(f), font, { bx, by: tTop, bw, bh: ch.clipped ? bh : ch.R + 1 },
              ch.clipped ? null : { M, from: ch.from, to: ch.to });
          }
        }
        out.set(f.page, {
          table: f,
          // מעל הטבלה — בכל דף; אחרי הטבלה — בדף האחרון, במקום החדש; בשוליים התחתונים — בכל דף, בתחתית
          place: (g) => {
            const v = g.y * H0;
            if (v >= fe - 0.5) return built.map(b => ({ page: b.page, fr: b.fr, dv: b.extra }));
            if (v >= tb - 0.5) { const b = built[built.length - 1]; return [{ page: b.page, fr: b.fr, dv: b.delta }]; }
            return built.map(b => ({ page: b.page, fr: b.fr, dv: 0 }));
          },
        });
      }
      return out;
    }

    /**
     * טבלה על הדף: כותרת + שורה לכל רשומה, מימין לשמאל, בתוך האזור של השדה. רוחב העמודות — לפי התוכן.
     * קיבוץ: שורה שממשיכה את הקבוצה (אותו ערך בעמודה הראשונה) — בלי הערך הזה ובלי קו עבה; בין קבוצות — קו עבה.
     * לא נכנס: קודם מקטינים את הגופן (עד 6), ומה שעדיין לא נכנס — לנספח (או נחתך, אם נבחר "להקטין").
     */
    /** מידות הטבלה: גודל הגופן, רוחב העמודות וגובה כל שורה. bh = Infinity — מקטינים רק בשביל הרוחב. */
    function measureTable(f, font, bw, bh) {
      const C = window.DTCalc;
      const cols = f.columns || [];
      const rows = C.rows(values[f.key]);
      const shown = (c, r) => c.type === 'calc' ? C.display(r[c.key] ?? '', c.format) : c.type === 'number' ? C.display(r[c.key] ?? '', 'number') : listOut(doc.lists, c.list, r[c.key]);
      const wraps = (c) => c.type === 'text';   // טקסט (למשל הערות) נשבר לכמה שורות; מספר ובחירה — שורה אחת
      const pad = 3;
      // רוחב טבעי לכל עמודה; עמודת טקסט ארוכה — עד 38% מהרוחב (והשאר נשבר לשורות)
      const natural = (sz) => cols.map(c => {
        const w = Math.max(font.widthOfTextAtSize(pdfText(c.label), sz), ...rows.map(r => font.widthOfTextAtSize(pdfText(shown(c, r)), sz))) + pad * 2;
        return wraps(c) ? Math.min(w, bw * 0.38) : w;
      });
      const layout = (sz) => {
        const nat = natural(sz), total = nat.reduce((a, b) => a + b, 0);
        const ws = nat.map(w => w * bw / total);
        const lines = rows.map(r => cols.map((c, j) => wraps(c) ? wrapLines(shown(c, r), font, sz, ws[j] - pad * 2) : [shown(c, r)]));
        const hs = lines.map(row => Math.max(1, ...row.map(l => l.length)) * sz * 1.25 + sz * 0.6);
        return { total, ws, lines, hs, head: sz * 2 };
      };
      let size = f.fontSize || 9, L = rows.length && cols.length ? layout(size) : { total: 0, ws: [], lines: [], hs: [], head: size * 2 };
      const height = (L) => L.head + L.hs.reduce((a, b) => a + b, 0);
      while (rows.length && cols.length && size > 6 && (L.total > bw * 1.02 || height(L) > bh)) { size -= 0.5; L = layout(size); }
      return { size, L, rows, cols, pad, shown, head: L.head, hs: L.hs };
    }

    /**
     * טבלה על הדף: כותרת + שורה לכל רשומה, מימין לשמאל, בתוך האזור של השדה. רוחב העמודות — לפי התוכן.
     * קיבוץ: שורה שממשיכה את הקבוצה (אותו ערך בעמודה הראשונה) — בלי הערך הזה ובלי קו עבה; בין קבוצות — קו עבה.
     * לא נכנס: קודם מקטינים את הגופן (עד 6), ומה שעדיין לא נכנס — לנספח (או נחתך, אם נבחר "להקטין").
     * part — חלק מטבלה שנשברת לדפים: {M, from, to} — השורות האלה בלבד, בלי לבדוק גובה.
     */
    function drawTable(f, page, { pt, rot }, ink, font, { bx, by, bw, bh }, part) {
      const M = part ? part.M : measureTable(f, font, bw, bh);
      const { size, L, rows, cols, pad, shown } = M;
      if (!cols.length || !rows.length) return;
      const gn = groupN(f);
      const from = part ? part.from : 0, to = part ? part.to : rows.length;
      const grey = rgb(0.45, 0.48, 0.55), light = rgb(0.82, 0.84, 0.88);
      const hline = (y, color, thickness) => page.drawLine({ start: pt(bx, y), end: pt(bx + bw, y), color, thickness });
      const text = (str, xRight, w, yBase, color, center) => {
        const v = pdfText(str);
        let s2 = size;
        while (s2 > 4 && font.widthOfTextAtSize(v, s2) > w - pad * 2) s2 -= 0.5;
        const tw = font.widthOfTextAtSize(v, s2);
        const o = pt(center ? xRight - w + (w - tw) / 2 : xRight - pad - tw, yBase);
        page.drawText(v, { x: o.x, y: o.y, size: s2, font, color, rotate: rot });
      };
      // כותרת
      let x = bx + bw;
      cols.forEach((c, j) => { text(c.label, x, L.ws[j], by + L.head * 0.66, grey, false); x -= L.ws[j]; });
      hline(by + L.head, ink, 0.9);
      // שורות — עד שנגמר האזור
      let y = by + L.head, n = from;
      for (let i = from; i < to; i++) {
        if (!part && y + L.hs[i] > by + bh + 0.5) break;
        const cont = i > from && sameGroup(f, rows, i);   // בתחילת דף — הקבוצה נכתבת שוב
        if (i > from) hline(y, cont ? light : (gn ? ink : light), cont ? 0.3 : (gn ? 0.7 : 0.4));
        let xr = bx + bw;
        cols.forEach((c, j) => {
          if (!(j < gn && cont)) L.lines[i][j].forEach((ln, k) => text(ln, xr, L.ws[j], y + size * 1.15 + k * size * 1.25, ink, c.type === 'number' || c.type === 'calc'));
          xr -= L.ws[j];
        });
        y += L.hs[i]; n++;
      }
      hline(y, ink, 0.6);
      // מה שלא נכנס
      if (!part && n < rows.length && f.overflow === 'annex') {
        const rest = rows.slice(n).map(r => cols.map(c => c.label + ': ' + (shown(c, r) || '—')).join(' · '));
        annex.push({ label: f.label + ` (שורות ${n + 1}–${rows.length})`, text: rest.join('\n') });
        const note = pdfText('(המשך בנספח ' + annex.length + ')');
        const o = pt(bx + bw - pad - font.widthOfTextAtSize(note, size), Math.min(by + bh - 1, y + size * 1.3));
        page.drawText(note, { x: o.x, y: o.y, size, font, color: grey, rotate: rot });
      }
    }

    // נספח: טקסטים שלא נכנסו בשדה, כשמנהל הטופס בחר "להמשיך בנספח".
    const writePages = pageWriter(pdf, font);
    if (annex.length) {
      writePages('נספח' + (doc.number ? ' — ' + doc.number : ''),
        annex.map((a, i) => ({ title: `(${i + 1}) ${a.label}`, text: a.text })));
    }

    // דף אישור בסוף — רק אם התבנית ביקשה (ההגדרות ← "ה-PDF שיוצא"). אחרת — בכפתור 🧾 נפרד
    if (doc.schema.certificate && doc.status === 'completed' && doc.final_hash) writePages('דף אישור', certBlocks(doc));

    pdf.setTitle(doc.title);
    pdf.setProducer('תבניות מסמכים');
    // בלי לצייר מחדש את שדות הטופס המקורי: כבר שוטחו או הוסרו, ובטופס שבור הציור עצמו נכשל
    return pdf.save({ updateFieldAppearances: false });
  }

  /**
   * ניקוי שכבת הטופס של ה-PDF המקורי — בלי לקרוא את השדות דרך pdf-lib (שנכשל על הפניה שבורה).
   * all=false: רק הפניות שבורות ב-Annots (אחרי flatten). all=true: גם כל ה-Widgets, ו-AcroForm מהקטלוג.
   */
  function stripForm(pdf, all) {
    const { PDFName, PDFRef, PDFDict } = window.PDFLib;
    try { if (all) pdf.catalog.delete(PDFName.of('AcroForm')); } catch (e) { /* אין */ }
    for (const page of pdf.getPages()) {
      try {
        const annots = page.node.Annots();
        if (!annots) continue;
        for (let i = annots.size() - 1; i >= 0; i--) {
          const it = annots.get(i);
          const obj = it instanceof PDFRef ? pdf.context.lookup(it) : it;
          const widget = obj instanceof PDFDict && String(obj.get(PDFName.of('Subtype'))) === '/Widget';
          if (!obj || (all && widget)) annots.remove(i);
        }
      } catch (e) { /* דף בלי Annots תקין — ממשיכים */ }
    }
  }

  window.DTDocs = { list, open, issue, pdfText, shares };
})();
