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
      el('div', { class: 'toolbar' }, f.q, filterBtn, exportBtn),
      el('p', { class: 'hint' }, count),
      msg, box));
    await load();
  }

  async function issue(templateId) {
    const { api } = window.DT;
    const r = await api('doc_create', { template_id: templateId });
    location.hash = 'doc=' + r.id;
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
    const canEdit = (f) => {
      if (!editable()) return false;
      const role = f.role || 'owner';
      if (fill) return role === doc.me.role_key;
      return !(doc.participants || []).some(p => p.role_key === role && p.notified_at);
    };
    const fields = doc.schema.fields;
    const byKey = Object.fromEntries(fields.map(f => [f.key, f]));
    const byPh = Object.fromEntries(fields.filter(f => f.placeholder).map(f => [f.placeholder, f]));
    // סדר הטופס: לפי דף, ואז מלמעלה למטה, ואז מימין לשמאל.
    const ordered = [...fields].sort((a, b) => a.page - b.page || (a.y - b.y > 0.01 ? 1 : b.y - a.y > 0.01 ? -1 : b.x - a.x));

    const title = el('input', { class: 'tpl-name', value: doc.title, maxlength: 200, 'aria-label': 'כותרת המסמך' });
    const saveStatus = el('span', { class: 'save-status small' });
    const statusBadge = el('span', { class: 'badge' });
    const progress = el('span', { class: 'small muted' });
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
    const completeBtn = el('button', { class: 'primary', onclick: fill ? fillDone : complete }, fill ? 'סיימתי ✓' : 'סיום ונעילה');
    // פעולה מוכרת = אייקון (עם תווית לקורא מסך); הפעולה העיקרית — עם מילה
    const ib = (icon, label, onclick) => el('button', { type: 'button', class: 'icon-btn', 'aria-label': label, title: label, onclick }, icon);
    const pdfBtn = ib('⬇️', 'הורדת PDF', downloadPdf);
    const track = el('span', { class: 'track', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100 }, el('span'));
    const people = el('aside', { class: 'panel card people', hidden: true });
    const meta = el('div', { class: 'doc-meta' });
    const side = (panel, other) => { panel.hidden = !panel.hidden; other.hidden = true; };

    show(
      // סיכום קודם: מספר, מצב, שמירה, כותרת, והתקדמות שדות החובה
      el('div', { class: 'summary doc-head' },
        el('div', { class: 'line' }, el('strong', { dir: 'ltr', class: 'doc-number' }, doc.number || ''), statusBadge,
          el('span', { class: 'spacer' }), saveStatus),
        title, track, progress),
      fill ? null : meta,
      !fill && doc.owner_name ? el('p', { class: 'notice' }, `👥 מסמך של ${doc.owner_name} — ההרשאה שלך: ${window.DT.LEVEL[doc.access]}.`) : null,
      fill && doc.me ? el('p', { class: 'notice' }, `שלום${doc.me.name ? ' ' + doc.me.name : ''} — ממלאים כאן את השדות של "${doc.me.role}". ` +
        'השדות שלך מסומנים במסגרת; את השאר רואים לקריאה בלבד.') : null,
      el('div', { class: 'toolbar doc-actions' },
        modeSeg,
        el('div', { class: 'icons' },
          fill ? null : ib('📜', 'יומן', () => { side(journal, people); renderJournal(); }),
          fill || !(doc.participants || []).length || lvl() < 3 ? null : ib('👥', 'משתתפים', () => { side(people, journal); renderPeople(); }),
          !fill && lvl() >= 4 ? ib('🔗', 'שיתוף', () => window.DT.shareDialog('document', id, doc.title, reload)) : null,
          !fill && doc.access && doc.access !== 'owner' ? ib('🚪', 'עזיבה', () => window.DT.leave('document', id, doc.title)) : null,
          fill || isWord || isAuthored ? null : pdfBtn,
          !fill && (isWord || isAuthored) ? ib('⬇️', 'הורדת Word', () => wordFile(false)) : null,
          (isWord || isAuthored) && !fill ? ib('👁️', 'תצוגה מקדימה', () => wordFile(true)) : null,
          isAuthored && !fill ? ib('🖨️', 'הדפסה / PDF', printDoc) : null),
        completeBtn),
      msg,
      el('div', { class: 'editor' }, body, journal, people));

    if (!fill) window.DT.setBack('docs');   // חזרה בכותרת — לרשימת המסמכים

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
          if (f.font) o.style.fontFamily = window.DTFonts.family(f.font); }
        return o;
      };
      if (f.type === 'calc') {
        return onPageText(el('output', { class: 'calc-out', 'data-calc': f.key, 'aria-label': f.label }, calcText(f, v) || '—'));
      }
      if (f.type === 'system') {
        return onPageText(el('output', { class: 'calc-out sys-out', 'data-sys': f.key, 'aria-label': f.label, title: 'מתמלא לבד', dir: 'auto' }, sysShown(f, v) || '—'));
      }
      if (f.type === 'table') return tableControl(f);
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
        if (inlineText && type === 'text') n.size = Math.max(6, Math.min(40, (v || f.label).length + 2));
        n.addEventListener('input', () => queue(f.key, limit(f, n)));
      }
      if (onPage && f.type !== 'checkbox' && f.type !== 'signature') {
        n.style.fontSize = `calc(var(--k) * ${f.fontSize || 11}px)`;
        n.style.textAlign = f.align === 'center' ? 'center' : f.align === 'left' ? 'left' : 'right';
        if (f.font) n.style.fontFamily = window.DTFonts.family(f.font);
        // למעלה / למטה: שורה אחת — הריפוד דוחף את הטקסט לקצה (בגובה השדה, בנקודות של הדף)
        if (f.type !== 'textarea' && f.type !== 'file' && f.valign && f.valign !== 'middle') {
          const rest = Math.max(0, f.h * doc.schema.pages[f.page].h - (f.fontSize || 11) * 1.3);
          n.style[f.valign === 'top' ? 'paddingBottom' : 'paddingTop'] = `calc(var(--k) * ${rest}px)`;
        }
      }
      // מבנה מותאם (תאריך עברי, שם חודש, מספר עם פסיקים…): מוצג כשלא עורכים; בהקשה — הערך עצמו לעריכה
      if (f.fmt && (f.type === 'date' || f.type === 'number') && !inlineText) {
        const show = el(onPage ? 'span' : 'small', { class: onPage ? 'fmt-show' : 'fmt-preview', 'aria-hidden': onPage ? 'true' : null });
        const wrap = el('span', { class: onPage ? 'fmt-wrap' : 'fmt-form' });
        if (onPage) {
          Object.assign(show.style, { fontSize: n.style.fontSize, fontFamily: n.style.fontFamily,
            justifyContent: f.align === 'center' ? 'center' : f.align === 'left' ? 'flex-end' : 'flex-start',
            alignItems: f.valign === 'top' ? 'flex-start' : f.valign === 'bottom' ? 'flex-end' : 'center' });
        }
        const upd = () => {
          const t = window.DTFmt.apply(f, n.value);
          show.textContent = t ? (onPage ? t : 'יוצג כך: ' + t) : '';
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
      const rowEl = (r) => el('tr', {}, cols.map(c => {
        if (c.type === 'calc') return el('td', { class: 'calc-cell', 'data-label': c.label }, C.display(r[c.key] ?? '', c.format));
        const inp = el('input', { value: r[c.key] ?? '', disabled: ro, 'aria-label': c.label,
          inputmode: c.type === 'number' ? 'decimal' : null, dir: c.type === 'number' ? 'ltr' : null });
        inp.addEventListener('input', () => { r[c.key] = inp.value; commit(); });
        return el('td', { 'data-label': c.label }, inp);
      }), ro ? null : el('td', { class: 'row-del' }, el('button', { type: 'button', class: 'link small', title: 'מחיקת שורה',
        onclick: () => { rows.splice(rows.indexOf(r), 1); redraw(); commit(); } }, '✕')));
      const redraw = () => tbody.replaceChildren(...rows.map(rowEl));
      redraw();
      return el('div', { class: 'grid-wrap', 'data-key': f.key },
        el('table', { class: 'grid' }, el('thead', {}, el('tr', {}, cols.map(c => el('th', {}, c.label)), ro ? null : el('th'))), tbody),
        ro ? (rows.length ? null : el('span', { class: 'muted small' }, 'אין שורות'))
          : el('button', { type: 'button', class: 'small', onclick: () => {
            rows.push({}); redraw();
            tbody.lastChild?.querySelector('input')?.focus();
          } }, '+ שורה'));
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
          `<td>${esc(c.type === 'calc' ? C.display(r[c.key] ?? '', c.format) : r[c.key])}</td>`).join('') + '</tr>').join('') + '</table>';
      const val = (f) => {
        const v = values[f.key] ?? '';
        if (!vis.has(f.key) || f.print === false) return '';
        switch (f.type) {
          case 'date': return esc(window.DTFmt.apply(f, v) ?? ddmmyyyy(v));
          case 'number': return esc(window.DTFmt.apply(f, v) ?? v);
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
      if (f.overflow !== 'block' || f.type === 'textarea') return input.value;
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
          c, f.help ? el('small', { class: 'muted' }, f.help) : null));
      }
      body.replaceChildren(el('div', { class: 'card fill-form' }, items));
    }

    let renderSeq = 0;
    async function renderBody() {
      renderHeader();
      const seq = ++renderSeq;
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
        el('ol', { class: 'events' }, [...doc.events].reverse().map(e => el('li', {},
          el('strong', {}, EVENTS[e.type] || e.type), ' ',
          e.by ? el('span', { class: 'small' }, '· ' + e.by + ' ') : null,
          el('span', { class: 'muted small' }, fmtDate(e.at) + (e.ip ? ' · ' + e.ip : '')),
          e.detail && e.detail.changes ? el('ul', { class: 'small' }, e.detail.changes.map(c => el('li', {},
            c.label + ': ', 'signed' in c ? (c.signed ? 'נחתם' : 'החתימה נמחקה')
              : el('span', {}, el('del', { class: 'muted' }, short(c.from)), ' → ', short(c.to))))) : null,
          e.detail && e.detail.number ? el('div', { class: 'small muted' }, 'מספר: ' + e.detail.number) : null,
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
    function renderPeople() {
      if (fill || people.hidden) return;
      people.replaceChildren(...[
        el('div', { class: 'row' }, el('h2', {}, 'משתתפים'), el('button', { class: 'link small', onclick: () => { people.hidden = true; } }, 'סגירה')),
        doc.schema.sequential ? el('p', { class: 'muted small' }, 'לפי הסדר: הקישור הבא נשלח אוטומטית במייל כשהקודם מסיים.') : null,
        ...doc.participants.map(p => {
          const name = el('input', { value: p.name, placeholder: 'שם', maxlength: 80 });
          const email = el('input', { value: p.email, placeholder: 'מייל', type: 'email', dir: 'ltr' });
          const phone = el('input', { value: p.phone, placeholder: 'טלפון (לוואטסאפ)', dir: 'ltr' });
          const saveContact = async () => {
            await api('part_contact', { id, participant_id: p.id, name: name.value, email: email.value, phone: phone.value });
            Object.assign(p, { name: name.value, email: email.value, phone: phone.value });
          };
          const act = async (via) => {
            out.replaceChildren();
            // חלון וואטסאפ נפתח עכשיו, בתוך הלחיצה — אחרי await דפדפנים חוסמים חלונות.
            const wa = via === 'whatsapp' ? window.open('', '_blank') : null;
            try {
              await saveContact();
              const r = await api('part_link', { id, participant_id: p.id, via });
              if (wa) wa.location.href = r.whatsapp;
              out.replaceChildren(notice(r.emailed ? 'נשלח במייל ל-' + email.value : 'הקישור החדש (הקודם בוטל):', 'ok'),
                via === 'link' ? el('input', { class: 'link-box', value: r.url, readonly: true, dir: 'ltr', onclick: (e) => e.target.select() }) : null);
              if (via === 'link') navigator.clipboard?.writeText(r.url).catch(() => {});
              await reload();
              renderBody();   // השדות של המשתתף ננעלו אצל המנפיק
            } catch (e) {
              if (wa) wa.close();
              out.replaceChildren(notice(e.message, 'bad'));
            }
          };
          const done = !!p.completed_at;
          return el('div', { class: 'person' },
            el('div', { class: 'row' }, el('strong', {}, p.role),
              el('span', { class: 'badge ' + (done ? 'ok' : p.opened_at ? 'warn' : 'muted') }, PSTATUS[p.status] || p.status)),
            p.auth === 'email' ? el('div', { class: 'muted small' }, '🔒 אימות בקוד למייל') : null,
            done ? null : el('div', { class: 'person-fields' }, name, email, phone, (() => {
              const btn = el('button', { type: 'button', class: 'link small', onclick: async () => {
                const sel = await window.DTCatalog.contactPicker(null, (c) => {
                  if (c) { name.value = c.name; email.value = c.email; phone.value = c.phone; }
                }, { empty: '— בחירה מאנשי הקשר —' });
                btn.replaceWith(sel);
              } }, '👤 מאנשי הקשר');
              return btn;
            })()),
            p.blocked && !done ? el('p', { class: 'muted small' }, p.blocked) : null,
            done || p.blocked ? null : el('div', { class: 'ops' },
              el('button', { class: 'small primary', onclick: () => act('email') }, '✉ מייל'),
              el('button', { class: 'small', onclick: () => act('whatsapp') }, 'וואטסאפ'),
              el('button', { class: 'small', onclick: () => act('link') }, '🔗 העתקת קישור')),
            p.missing.length && !done ? el('div', { class: 'muted small' }, 'חסר אצלו: ' + p.missing.join(', ')) : null);
        }),
        out].filter(Boolean));
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
        const res = await fetch(`api.php?action=doc_docx&id=${id}${preview ? '&preview=1' : ''}`, { credentials: 'same-origin' });
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
    async function downloadPdf() {
      pdfBtn.disabled = true;
      pdfBtn.textContent = 'מכין PDF…';
      try {
        await flush();
        if (!pagesCache) pagesCache = await window.DTEditor.renderBackground(src.asset[0], doc.mime, src.asset[1]);
        const bytes = await buildPdf(doc, values, pagesCache.bytes);
        const a = el('a', { href: URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' })),
          download: ((doc.number ? doc.number + ' ' : '') + title.value).replace(/[\\/:*?"<>|]+/g, '_') + '.pdf' });
        document.body.append(a); a.click(); a.remove();
        api('doc_downloaded', { id }).then(reload).catch(() => {});
      } catch (e) {
        msg.replaceChildren(notice('הפקת ה-PDF נכשלה: ' + e.message, 'bad'));
      } finally {
        pdfBtn.disabled = false;
        pdfBtn.textContent = '⬇ הורדת PDF';
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

  async function buildPdf(doc, values, srcBytes) {
    await loadScript(PDFLIB);
    await loadScript(FONTKIT);
    const { PDFDocument, rgb, degrees } = window.PDFLib;
    const fontBytes = await (await fetch(FONT)).arrayBuffer();

    let pdf;
    if (doc.mime === 'application/pdf') {
      pdf = await PDFDocument.load(srcBytes, { ignoreEncryption: true });
      // שלב 10: לטופס PDF יש שדות משלו, שמוצגים מעל תוכן הדף — שדה ריק עם רקע
      // היה מסתיר את מה שנכתוב. משטחים אותם לתוכן הדף לפני שכותבים מעליהם.
      try {
        const form = pdf.getForm();
        if (form.getFields().length) {
          form.flatten({ updateFieldAppearances: false });
          // pdf-lib מוחק את השדות אבל משאיר הפניות אליהם ב-Annots של הדף — מנקים
          for (const page of pdf.getPages()) {
            const annots = page.node.Annots();
            if (!annots) continue;
            for (let i = annots.size() - 1; i >= 0; i--) {
              const ref = annots.get(i);
              if (ref instanceof window.PDFLib.PDFRef && !pdf.context.lookup(ref)) annots.remove(i);
            }
          }
        }
      } catch (e) {
        try { const form = pdf.getForm(); for (const f of form.getFields()) form.removeField(f); } catch (e2) { /* בלי טופס */ }
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
    const ink = rgb(0.04, 0.12, 0.4);
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
    for (const f of doc.schema.fields) {
      let raw = values[f.key] ?? '';
      if (raw === '' || !pages[f.page] || !vis.has(f.key) || f.print === false) continue;   // "לא מודפס" — לא נכתב
      const page = pages[f.page];
      // הכול בנקודות של הדף הנראה: (bx, by) — הפינה העליונה של השדה, y כלפי מטה
      const { W, H, pt, rot } = frame(page);
      const bx = f.x * W, by = f.y * H, bw = f.w * W, bh = f.h * H;

      if (f.type === 'checkbox') {
        if (raw !== '1') continue;
        const s = Math.min(bw, bh), cx = bx + bw / 2, cy = by + bh / 2;
        const line = (x1, y1, x2, y2) => page.drawLine({ start: pt(x1, y1), end: pt(x2, y2), thickness: Math.max(1, s / 8), color: ink });
        line(cx - s * 0.35, cy, cx - s * 0.08, cy + s * 0.3);
        line(cx - s * 0.08, cy + s * 0.3, cx + s * 0.4, cy - s * 0.35);
        continue;
      }
      if (f.type === 'file') {
        let ids = [];
        try { ids = JSON.parse(raw); } catch (e) { /* ריק */ }
        if (!ids.length) continue;
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
        continue;
      }

      const font = fonts.get(f.font) || fontDefault;
      const text = window.DTFmt.apply(f, raw) ?? (f.type === 'date' ? ddmmyyyy(raw) : f.type === 'calc' ? window.DTCalc.display(raw, f.format)
        : f.type === 'system' ? sysText(f, raw) : f.type === 'select' ? optText(f, raw) : raw);   // מבנה מותאם קודם
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

    // נספח: טקסטים שלא נכנסו בשדה, כשמנהל הטופס בחר "להמשיך בנספח".
    const A4 = [595.28, 841.89];
    const writePages = (heading, blocks) => {
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
    if (annex.length) {
      writePages('נספח' + (doc.number ? ' — ' + doc.number : ''),
        annex.map((a, i) => ({ title: `(${i + 1}) ${a.label}`, text: a.text })));
    }

    // דף אישור — רק למסמך שהושלם.
    if (doc.status === 'completed' && doc.final_hash) {
      const ev = doc.events.map(e => `${fmtDate(e.at)} · ${EVENTS[e.type] || e.type}${e.ip ? ' · IP ' + e.ip : ''}`);
      writePages('דף אישור', [
        { text: `מסמך: ${doc.title}${doc.number ? ' (' + doc.number + ')' : ''}` },
        { text: `נוצר: ${fmtDate(doc.created_at)} · הושלם: ${fmtDate(doc.completed_at)}` },
        { text: `טביעת אצבע (SHA-256) של הערכים: ${doc.final_hash}` },
        { title: 'יומן', text: ev.join('\n') },
      ]);
    }

    pdf.setTitle(doc.title);
    pdf.setProducer('תבניות מסמכים');
    return pdf.save();
  }

  window.DTDocs = { list, open, issue, pdfText };
})();
