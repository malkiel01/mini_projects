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
      const form = el('form', { class: 'quota-form' },
        el('h2', {}, c ? 'עריכת איש קשר' : 'איש קשר חדש'),
        FIELDS.map(([k, label]) => el('label', {}, el('span', {}, label), inputs[k])),
        el('label', {}, el('span', {}, 'תגיות'), tags),
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
    });
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
            el('small', { dir: 'auto' }, [c.phone, c.email, c.id_number].filter(Boolean).join(' · ') || 'בלי פרטי קשר'),
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
        el('button', { class: 'primary', onclick: async () => { if (await contactDialog(null)) load(); } }, '+ איש קשר')),
      el('label', { class: 'switch small' }, archived, el('span', {}, 'כולל ארכיון')),
      msg, box,
      el('p', { class: 'hint' }, '🔒 פרטיים לך בלבד ומוצפנים. במסמך — בוחרים איש קשר, והשדות שסומנו בתבנית מתמלאים לבד.')));
    await load();
  }

  window.DTCatalog = { view, tagsInput, chips, contactPicker, contactDialog, contacts, parseTags };
})();
