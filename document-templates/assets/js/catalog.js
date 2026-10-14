// קטלוג — שלב 8: אנשי קשר, תגיות, ועזרים שמשמשים את שאר המסכים.
(function () {
  'use strict';

  const FIELDS = [
    ['name', 'שם מלא / שם העסק'], ['id_number', 'ת"ז / ח"פ'], ['phone', 'טלפון'],
    ['email', 'מייל'], ['address', 'כתובת'], ['notes', 'הערות'],
  ];
  // אווטאר: ראשי תיבות וגוון קבוע מהשם
  const hue = (s) => [...String(s)].reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 360, 7);
  const initials = (s) => String(s).trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('');
  const parseTags = (s) => String(s || '').split(/[,،]/).map(t => t.trim()).filter(Boolean);

  /** שדה תגיות: טקסט מופרד בפסיקים. onChange נקרא ביציאה מהשדה, רק אם השתנה. */
  function tagsInput(tags, onChange, opts = {}) {
    const { el } = window.DT;
    const input = el('input', { class: 'tags-input', value: (tags || []).join(', '), placeholder: opts.placeholder || 'תגיות, מופרדות בפסיק',
      disabled: !!opts.disabled, 'aria-label': 'תגיות' });
    let last = input.value;
    input.addEventListener('change', () => {
      const list = parseTags(input.value);
      input.value = list.join(', ');
      if (input.value !== last) { last = input.value; onChange(list); }
    });
    return input;
  }

  function chips(tags) {
    const { el } = window.DT;
    return (tags || []).length ? el('span', { class: 'chips' }, tags.map(t => el('span', { class: 'chip' }, t))) : null;
  }

  let cache = null;
  async function contacts(force) {
    if (!cache || force) cache = (await window.DT.api('contact_list')).contacts;
    return cache;
  }

  /** חלון עריכה/יצירה של איש קשר. מחזיר את המזהה, או null בביטול. */
  function contactDialog(c) {
    const { api, el, notice } = window.DT;
    return new Promise((resolve) => {
      const out = el('div');
      const inputs = Object.fromEntries(FIELDS.map(([k]) => [k, k === 'notes'
        ? el('textarea', { name: k, rows: 3 })
        : el('input', { name: k, dir: ['phone', 'email', 'id_number'].includes(k) ? 'ltr' : null,
            type: k === 'email' ? 'email' : 'text', required: k === 'name' })]));
      for (const [k] of FIELDS) inputs[k].value = (c && c[k]) || '';
      const tags = el('input', { value: ((c && c.tags) || []).join(', '), placeholder: 'למשל: דיירים, ספקים' });
      let done = null;
      const fills = c ? el('div', { class: 'contact-fills' }) : null;
      const form = el('form', { class: 'quota-form' },
        el('h2', {}, c ? 'עריכת איש קשר' : 'איש קשר חדש'),
        FIELDS.map(([k, label]) => el('label', {}, el('span', {}, label), inputs[k])),
        el('label', {}, el('span', {}, 'תגיות'), tags),
        c ? el('p', { class: 'hint' }, 'עדכון כאן מגיע לכל מסמך שבו הוא ממלא ועוד לא סיים.') : null,
        fills,
        out,
        el('div', { class: 'ops' }, el('button', { type: 'submit', class: 'primary' }, 'שמירה'),
          el('button', { type: 'button', onclick: () => dlg.close() }, 'ביטול')));
      const dlg = el('dialog', { class: 'sig-dialog' }, form);
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(FIELDS.map(([k]) => [k, inputs[k].value]));
        body.tags = parseTags(tags.value);
        if (c) body.id = c.id;
        try { done = (await api('contact_save', body)).id; cache = null; dlg.close(); }
        catch (err) { out.replaceChildren(notice(err.message, 'bad')); }
      });
      dlg.addEventListener('close', () => { dlg.remove(); resolve(done); });
      document.body.append(dlg);
      dlg.showModal();
      inputs.name.focus();
      if (c) fillsOf(c, fills, () => dlg.close());
    });
  }

  const PSTATUS = { waiting: ['לא נשלח', ''], sent: ['נשלח', 'warn'], opened: ['פתח', 'warn'], completed: ['✓ סיים', 'ok'] };

  /** איפה איש הקשר ממלא בקישור — מצב, ושליחת קישור ישר מהכרטיס. */
  async function fillsOf(c, box, closeDlg) {
    const { api, el, notice } = window.DT;
    let list;
    try { list = (await api('contact_fills', undefined, { id: c.id })).fills; } catch (e) { box.replaceChildren(notice(e.message, 'bad')); return; }
    if (!list.length) { box.replaceChildren(el('p', { class: 'muted small' }, '🔗 עוד לא ממלא בקישור באף מסמך.')); return; }
    const msg = el('div');
    const send = async (x, via) => {
      msg.replaceChildren();
      const wa = via === 'whatsapp' ? window.open('', '_blank') : null;
      try {
        const r = await api('part_link', { id: x.document_id, participant_id: x.participant_id, via });
        if (wa) wa.location.href = r.whatsapp;
        msg.replaceChildren(...[notice(r.emailed ? '✓ נשלח במייל' : '✓ קישור חדש (הקודם בוטל)', 'ok'),
          via === 'link' ? el('input', { class: 'link-box', value: r.url, readonly: true, dir: 'ltr', onclick: (e) => e.target.select() }) : null].filter(Boolean));
        if (via === 'link') navigator.clipboard?.writeText(r.url).catch(() => {});
        fillsOf(c, box, closeDlg);
      } catch (e) { if (wa) wa.close(); msg.replaceChildren(notice(e.message, 'bad')); }
    };
    box.replaceChildren(el('b', {}, list.length === 1 ? '🔗 ממלא בקישור במסמך אחד' : `🔗 ממלא בקישור ב-${list.length} מסמכים`),
      el('ul', { class: 'list fills-list' }, list.map(x => {
        const [label, kind] = PSTATUS[x.status] || [x.status, ''];
        return el('li', {},
          el('div', { class: 'fill-row' },
            el('a', { href: '#doc=' + x.document_id, onclick: () => closeDlg() }, el('strong', {}, x.title || 'מסמך'), x.number ? ' · ' + x.number : ''),
            el('span', { class: 'badge ' + kind }, label)),
          el('small', { class: 'muted' }, 'בתפקיד: ' + x.role + (x.blocked && !x.completed_at ? ' · ' + x.blocked : '')),
          x.can_send ? el('div', { class: 'ops' },
            el('button', { type: 'button', class: 'small', onclick: () => send(x, 'email') }, '✉ מייל'),
            el('button', { type: 'button', class: 'small', onclick: () => send(x, 'whatsapp') }, 'וואטסאפ'),
            el('button', { type: 'button', class: 'small', onclick: () => send(x, 'link') }, '🔗 קישור')) : null);
      })), msg);
  }

  /** ייבוא מהטלפון: בורר אנשי הקשר של הדפדפן (אנדרואיד), או קובץ vcf (כל טלפון). */
  function importDialog(after) {
    const { api, el, notice, sheet } = window.DT;
    const body = el('div', { class: 'field-sheet' });
    const msg = el('div');
    const canPick = 'contacts' in navigator && 'select' in navigator.contacts;
    const file = el('input', { type: 'file', accept: '.vcf,text/vcard,text/x-vcard', class: 'visually-hidden' });
    file.addEventListener('change', async () => {
      const f = file.files[0];
      if (!f) return;
      if (f.size > 10 * 1024 * 1024) { msg.replaceChildren(notice('הקובץ גדול מדי (עד 10MB)', 'bad')); return; }
      const list = window.DTVcard.parse(await f.text());
      if (!list.length) { msg.replaceChildren(notice('לא נמצאו אנשי קשר בקובץ — צריך קובץ vcf', 'bad')); return; }
      review(list);
    });
    async function pick() {
      try {
        const res = await navigator.contacts.select(['name', 'email', 'tel'], { multiple: true });
        const list = res.map(x => ({ name: (x.name || [])[0] || '', email: (x.email || [])[0] || '', phone: (x.tel || [])[0] || '' }))
          .filter(x => x.name || x.phone || x.email);
        if (list.length) review(list);
      } catch (e) { msg.replaceChildren(notice('הבחירה בוטלה', 'bad')); }
    }
    async function review(list) {
      const have = await contacts(true);
      const pk = (p) => { const d = String(p || '').replace(/\D+/g, ''); return d.startsWith('972') ? '0' + d.slice(3) : d; };
      const dup = (x) => have.some(c => (x.email && c.email && c.email.toLowerCase() === x.email.toLowerCase()) || (pk(x.phone) && pk(c.phone) === pk(x.phone)));
      const rows = list.map(x => ({ x, dup: dup(x), box: el('input', { type: 'checkbox', checked: !dup(x) }) }));
      const count = el('span');
      const paint = () => { count.textContent = String(rows.filter(r => r.box.checked).length); };
      rows.forEach(r => r.box.addEventListener('change', paint));
      const all = el('input', { type: 'checkbox', checked: rows.every(r => r.box.checked || r.dup), onchange: () => { rows.forEach(r => { if (!r.dup) r.box.checked = all.checked; }); paint(); } });
      const go = el('button', { type: 'button', class: 'primary', onclick: async () => {
        go.disabled = true;
        try {
          const r = await api('contact_import', { contacts: rows.filter(r => r.box.checked).map(r => r.x) });
          cache = null;
          body.replaceChildren(notice(`✓ נוספו ${r.added} אנשי קשר` + (r.skipped ? ` · ${r.skipped} כבר היו או חסרים` : '') + '. הם מתויגים "מהטלפון".', 'ok'));
          after();
        } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); go.disabled = false; }
      } }, 'ייבוא ', count);
      body.replaceChildren(msg,
        el('label', { class: 'switch small' }, all, el('span', {}, `הכול (${list.length})`)),
        el('ul', { class: 'list import-list' }, rows.map(r => el('li', {}, el('label', { class: 'imp-row' + (r.dup ? ' dup' : '') }, r.box,
          el('span', { class: 'use-tx' }, el('strong', {}, r.x.name || r.x.phone || r.x.email),
            el('small', { dir: 'auto' }, [r.x.phone, r.x.email].filter(Boolean).join(' · ') + (r.dup ? ' · כבר באנשי הקשר' : ''))))))),
        el('div', { class: 'ops' }, go));
      paint();
    }
    body.replaceChildren(...[
      el('p', { class: 'hint' }, 'אנשי קשר מהטלפון נכנסים לרשימה שלך — פרטיים ומוצפנים. מי שכבר קיים (אותו טלפון או מייל) מסומן ולא ייובא שוב.'),
      canPick ? el('button', { type: 'button', class: 'primary', onclick: pick }, '📱 בחירה מאנשי הקשר בטלפון') : null,
      el('label', { class: 'btn' }, file, '📄 קובץ אנשי קשר (vcf)'),
      el('details', { class: 'vcf-help' }, el('summary', {}, 'איך מוציאים קובץ vcf מהטלפון?'),
        el('ul', {},
          el('li', {}, 'אנדרואיד: אפליקציית "אנשי קשר" ← תפריט ← ניהול / ייבוא וייצוא ← ייצוא לקובץ (vcf).'),
          el('li', {}, 'אייפון: אנשי קשר ← "רשימות" ← לחיצה ארוכה על "כל אנשי הקשר" ← ייצוא. או: איש קשר בודד ← שיתוף איש קשר.'))),
      msg].filter(Boolean));   // replaceChildren מדפיס "null"
    if (!canPick) body.prepend(el('p', { class: 'hint' }, 'בחירה ישירה מאנשי הקשר עובדת בכרום באנדרואיד. בכל טלפון אחר — דרך קובץ vcf.'));
    sheet('📱 ייבוא אנשי קשר', body);
  }

  /** בחירת איש קשר: רשימה נפתחת + "חדש…". onPick(contact|null). */
  async function contactPicker(currentId, onPick, opts = {}) {
    const { el } = window.DT;
    const list = await contacts();
    const sel = el('select', { 'aria-label': 'איש קשר', disabled: !!opts.disabled },
      el('option', { value: '' }, opts.empty || '— בלי איש קשר —'),
      list.map(c => el('option', { value: c.id, selected: c.id === currentId }, c.name + (c.phone ? ' · ' + c.phone : ''))),
      el('option', { value: 'new' }, '+ איש קשר חדש…'));
    sel.addEventListener('change', async () => {
      if (sel.value === 'new') {
        const id = await contactDialog(null);
        const fresh = await contacts(true);
        const c = fresh.find(x => x.id === id);
        sel.replaceChildren(el('option', { value: '' }, opts.empty || '— בלי איש קשר —'),
          ...fresh.map(x => el('option', { value: x.id, selected: x.id === (c ? c.id : currentId) }, x.name + (x.phone ? ' · ' + x.phone : ''))),
          el('option', { value: 'new' }, '+ איש קשר חדש…'));
        if (c) onPick(c); else sel.value = currentId || '';
        return;
      }
      onPick(list.concat(cache || []).find(c => String(c.id) === sel.value) || null);
    });
    return sel;
  }

  // ── מסך אנשי הקשר ──
  async function view() {
    const { api, el, notice, show } = window.DT;
    window.DT.setTab('contacts');
    const box = el('div', {}, el('p', { class: 'muted' }, 'טוען…'));
    const msg = el('div');
    const q = el('input', { type: 'search', placeholder: 'חיפוש: שם, טלפון, מייל, תגית', class: 'search', 'aria-label': 'חיפוש אנשי קשר' });
    const archived = el('input', { type: 'checkbox' });
    let all = [];

    function render() {
      const term = q.value.trim().toLowerCase();
      const rows = all.filter(c => !term || [c.name, c.phone, c.id_number, c.email, c.address, c.tags.join(' ')]
        .join(' ').toLowerCase().includes(term));
      if (!rows.length) {
        box.replaceChildren(el('p', { class: 'empty' }, all.length ? 'אין תוצאות.' : 'עוד אין אנשי קשר. "+ איש קשר" כדי להתחיל — או בחירה ישר מתוך מסמך.'));
        return;
      }
      // שורה לכל איש קשר: אווטאר, שם, טלפון · מייל; לחיצה — עריכה
      box.replaceChildren(el('ul', { class: 'list contacts' }, rows.map(c => el('li', { class: c.archived ? 'archived' : '' },
        el('button', { type: 'button', class: 'use-row', onclick: async () => { if (await contactDialog(c)) load(); } },
          el('span', { class: 'avatar', style: `--h:${hue(c.name)}`, 'aria-hidden': 'true' }, initials(c.name)),
          el('span', { class: 'use-tx' }, el('strong', {}, c.name),
            el('small', { dir: 'auto' }, [c.phone, c.email, c.id_number, c.fills ? `🔗 ממלא ב-${c.fills}` : ''].filter(Boolean).join(' · ') || 'בלי פרטי קשר'),
            chips(c.tags))),
        c.documents ? el('a', { class: 'icon-btn docs-n', href: '#docs?contact=' + c.id, 'aria-label': `${c.documents} מסמכים`, title: 'המסמכים שלו' },
          '🗂️', el('span', { class: 'badge-n' }, String(c.documents))) : null,
        el('button', { class: 'icon-btn', 'aria-label': c.archived ? 'שחזור' : 'ארכיון', title: c.archived ? 'שחזור' : 'ארכיון',
          onclick: () => archive(c) }, c.archived ? '♻️' : '🗄️')))));
    }

    async function load() {
      try { all = (await api('contact_list', undefined, archived.checked ? { archived: 1 } : {})).contacts; cache = null; render(); }
      catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    }
    async function archive(c) {
      try { await api('contact_archive', { id: c.id, restore: c.archived }); load(); }
      catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    }

    q.addEventListener('input', render);
    archived.addEventListener('change', load);
    show(el('section', { class: 'screen' },
      el('div', { class: 'toolbar' }, q,
        el('button', { class: 'primary', onclick: async () => { if (await contactDialog(null)) load(); } }, '+ איש קשר'),
        el('button', { type: 'button', onclick: () => importDialog(load) }, '📱 ייבוא')),
      el('label', { class: 'switch small' }, archived, el('span', {}, 'כולל ארכיון')),
      msg, box,
      el('p', { class: 'hint' }, '🔒 פרטיים לך בלבד ומוצפנים. במסמך — בוחרים איש קשר, והשדות שסומנו בתבנית מתמלאים לבד.')));
    await load();
  }

  window.DTCatalog = { view, tagsInput, chips, contactPicker, contactDialog, contacts, parseTags, importDialog };
})();
