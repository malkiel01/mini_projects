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
    created: 'נוצר', values_saved: 'נשמרו ערכים', completed: 'הושלם ונעול', archived: 'הועבר לארכיון',
    restored: 'שוחזר מהארכיון', downloaded: 'הורד PDF', deleted: 'נמחק',
    link_issued: 'נשלח קישור', opened: 'נפתח', verified: 'אומת בקוד', participant_completed: 'משתתף סיים',
  };
  const VIA = { email: 'במייל', link: 'כקישור', whatsapp: 'בוואטסאפ' };
  const EDITABLE = ['draft', 'in_progress'];

  const fmtDate = (iso) => iso ? new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }) : '—';
  const ddmmyyyy = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v) ? v.split('-').reverse().join('/') : v;

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

  async function list() {
    const { api, el, notice, show } = window.DT;
    const params = new URLSearchParams(location.hash.split('?')[1] || '');
    const box = el('div', {}, el('p', { class: 'muted' }, 'טוען…'));
    const msg = el('div');
    const q = el('input', { type: 'search', placeholder: 'חיפוש לפי מספר או כותרת', class: 'search' });
    const tplSel = el('select', {}, el('option', { value: '' }, 'כל התבניות'));
    const statusSel = el('select', {}, el('option', { value: '' }, 'כל המצבים'),
      ['draft', 'in_progress', 'completed'].map(s => el('option', { value: s }, STATUS[s][0])));
    const archived = el('input', { type: 'checkbox' });
    let docs = [];

    function render() {
      const term = q.value.trim();
      const rows = docs.filter(d =>
        (!tplSel.value || String(d.template_id) === tplSel.value) &&
        (!statusSel.value || d.status === statusSel.value) &&
        (!term || (d.number || '').includes(term) || d.title.includes(term)));
      if (!rows.length) {
        box.replaceChildren(el('p', { class: 'muted' }, docs.length ? 'אין מסמכים שמתאימים לסינון.'
          : 'עוד לא הונפקו מסמכים. ב"התבניות שלי" לוחצים "הנפקת מסמך" ליד תבנית.'));
        return;
      }
      box.replaceChildren(el('div', { class: 'table-wrap' }, el('table', { class: 'docs' },
        el('thead', {}, el('tr', {}, ['מספר', 'כותרת', 'תבנית', 'מצב', 'שדות חובה', 'נוצר'].map(h => el('th', {}, h)))),
        el('tbody', {}, rows.map(d => {
          const [label, kind] = STATUS[d.status] || [d.status, ''];
          return el('tr', { class: 'clickable' + (d.archived ? ' archived' : ''), onclick: () => { location.hash = 'doc=' + d.id; } },
            el('td', { dir: 'ltr', 'data-label': 'מספר' }, el('a', { href: '#doc=' + d.id }, d.number || '—')),
            el('td', { 'data-label': 'כותרת' }, d.title),
            el('td', { class: 'muted', 'data-label': 'תבנית' }, d.template),
            el('td', { 'data-label': 'מצב' }, el('span', { class: 'badge ' + kind }, label)),
            el('td', { 'data-label': 'שדות חובה' }, d.required ? `${d.filled}/${d.required}` : '—'),
            el('td', { class: 'small muted', 'data-label': 'נוצר' }, fmtDate(d.created_at)));
        })))));
    }

    async function load() {
      try {
        docs = (await api('doc_list', undefined, archived.checked ? { archived: 1 } : {})).documents;
        const seen = new Map(docs.map(d => [d.template_id, d.template]));
        const cur = tplSel.value || params.get('template') || '';
        tplSel.replaceChildren(el('option', { value: '' }, 'כל התבניות'),
          [...seen].map(([id, name]) => el('option', { value: id, selected: String(id) === cur }, name)));
        render();
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    [q, tplSel, statusSel].forEach(n => n.addEventListener('input', render));
    archived.addEventListener('change', load);
    window.DT.setTab('docs');
    show(el('section', { class: 'card' },
      el('h1', {}, 'מסמכים'),
      el('div', { class: 'filters' }, q, tplSel, statusSel,
        el('label', { class: 'inline small muted' }, archived, ' כולל ארכיון')),
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
    const editable = () => fill ? !doc.me.locked && !doc.me.blocked : EDITABLE.includes(doc.status);
    // שדה שאפשר לערוך עכשיו: אצל הממלא — רק שלו. אצל המנפיק — כל שדה, חוץ
    // משדות של משתתף שכבר נשלח אליו קישור.
    const canEdit = (f) => {
      if (!editable()) return false;
      const role = f.role || 'owner';
      if (fill) return role === doc.me.role_key;
      return !(doc.participants || []).some(p => p.role_key === role && p.notified_at);
    };
    const fields = doc.schema.fields;
    // סדר הטופס: לפי דף, ואז מלמעלה למטה, ואז מימין לשמאל.
    const ordered = [...fields].sort((a, b) => a.page - b.page || (a.y - b.y > 0.01 ? 1 : b.y - a.y > 0.01 ? -1 : b.x - a.x));

    const title = el('input', { class: 'tpl-name', value: doc.title, maxlength: 200, 'aria-label': 'כותרת המסמך' });
    const saveStatus = el('span', { class: 'save-status small' });
    const statusBadge = el('span', { class: 'badge' });
    const progress = el('span', { class: 'small muted' });
    const msg = el('div');
    const body = el('div', { class: 'doc-body' });
    const journal = el('aside', { class: 'panel card journal', hidden: true });
    const modeBtn = el('button', { class: 'small', onclick: () => { mode = mode === 'page' ? 'form' : 'page';
      try { localStorage.setItem('dt-fill-mode', mode); } catch (e) {} renderBody(); } });
    const completeBtn = el('button', { class: 'primary small', onclick: fill ? fillDone : complete }, fill ? 'סיימתי ✓' : 'סיום ונעילה');
    const pdfBtn = el('button', { class: 'small', onclick: downloadPdf }, '⬇ הורדת PDF');
    const people = el('aside', { class: 'panel card people', hidden: true });
    const side = (panel, other) => { panel.hidden = !panel.hidden; other.hidden = true; };

    show(
      el('div', { class: 'editor-bar card' },
        fill ? null : el('a', { href: '#docs', class: 'small' }, '→ מסמכים'),
        el('strong', { dir: 'ltr', class: 'doc-number' }, doc.number || ''),
        title, statusBadge, saveStatus),
      fill && doc.me ? el('p', { class: 'notice' }, `שלום${doc.me.name ? ' ' + doc.me.name : ''} — ממלאים כאן את השדות של "${doc.me.role}". ` +
        'השדות שלך מסומנים במסגרת; את השאר רואים לקריאה בלבד.') : null,
      el('div', { class: 'palette' },
        modeBtn,
        fill ? null : el('button', { class: 'small', onclick: () => { side(journal, people); renderJournal(); } }, '📜 יומן'),
        fill || !(doc.participants || []).length ? null
          : el('button', { class: 'small', onclick: () => { side(people, journal); renderPeople(); } }, '👥 משתתפים'),
        fill ? null : pdfBtn, completeBtn, progress),
      msg,
      el('div', { class: 'editor' }, body, journal, people));

    window.DT.setLeaveGuard(Object.assign(() => {
      if (Object.keys(pending).length) flush();
      return true;
    }, { dirty: () => Object.keys(pending).length > 0 || !!inflight }));

    function renderHeader() {
      const [label, kind] = STATUS[doc.status] || [doc.status, ''];
      statusBadge.textContent = label;
      statusBadge.className = 'badge ' + kind;
      title.disabled = fill || !editable();
      completeBtn.hidden = !editable();
      modeBtn.textContent = mode === 'page' ? '☰ למילוי כטופס' : '📄 למילוי על המסמך';
      const p = doc.progress;
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
    function control(f, onPage) {
      const v = values[f.key] ?? '';
      const ro = !canEdit(f);
      const common = { 'data-key': f.key, 'aria-label': f.label, disabled: ro };
      let n;
      if (f.type === 'checkbox') {
        n = el('input', { type: 'checkbox', checked: v === '1', ...common });
        n.addEventListener('change', () => queue(f.key, n.checked ? '1' : ''));
      } else if (f.type === 'signature') {
        n = el('button', { type: 'button', class: 'sig-btn' + (v ? ' has' : ''), ...common,
          onclick: () => signaturePad(f, (data) => { queue(f.key, data); renderBody(); }) },
          v ? el('img', { src: v, alt: 'חתימה' }) : '✍️ לחצו לחתימה');
        if (ro) n.disabled = true;
      } else if (f.type === 'textarea') {
        n = el('textarea', { ...common, placeholder: onPage ? f.label : '' });
        n.value = v;
        n.addEventListener('input', () => queue(f.key, limit(f, n)));
      } else {
        const type = { number: 'text', date: 'date', text: 'text' }[f.type] || 'text';
        n = el('input', { type, ...common, inputmode: f.type === 'number' ? 'decimal' : null,
          dir: f.type === 'number' ? 'ltr' : null, placeholder: onPage ? f.label : '' });
        n.value = v;
        n.addEventListener('input', () => queue(f.key, limit(f, n)));
      }
      if (onPage && f.type !== 'checkbox' && f.type !== 'signature') {
        n.style.fontSize = `calc(var(--k) * ${f.fontSize || 11}px)`;
        n.style.textAlign = f.align === 'center' ? 'center' : f.align === 'left' ? 'left' : 'right';
      }
      return n;
    }

    // "לא לאפשר להקליד מעבר לגודל": מודדים את הטקסט בגופן ובגודל של השדה.
    const measure = document.createElement('canvas').getContext('2d');
    function limit(f, input) {
      // בשדה רב-שורתי זה תלוי בשבירת השורות — שם נשען על ה-PDF (הקטנה), לא חוסמים.
      if (f.overflow !== 'block' || f.type === 'textarea') return input.value;
      const pw = doc.schema.pages[f.page].w * f.w;
      measure.font = `${f.fontSize || 11}px Alef, Arial`;
      while (input.value && measure.measureText(input.value).width > pw - 4) input.value = input.value.slice(0, -1);
      return input.value;
    }

    // ── תצוגה: על המסמך ──
    let pagesCache = null;
    async function renderPage() {
      if (!pagesCache) {
        body.replaceChildren(el('p', { class: 'muted' }, 'טוען את המסמך…'));
        pagesCache = await window.DTEditor.renderBackground(src.asset[0], doc.mime, src.asset[1]);
      }
      const wrap = el('div', { class: 'pages filling' });
      pagesCache.forEach((pg, i) => {
        const layer = el('div', { class: 'layer' });
        const page = el('div', { class: 'page', style: `aspect-ratio: ${pg.w} / ${pg.h}` }, pg.visual, layer);
        pg.visual.classList.add('page-bg');
        fields.filter(f => f.page === i).forEach(f => {
          layer.append(el('div', {
            class: 'fill t-' + f.type + (f.required ? ' required' : '') + (canEdit(f) ? ' mine' : ' theirs'), 'data-key': f.key, title: f.label + (f.help ? ' — ' + f.help : ''),
            style: `left:${f.x * 100}%; top:${f.y * 100}%; width:${f.w * 100}%; height:${f.h * 100}%`,
          }, control(f, true)));
        });
        new ResizeObserver(() => { page.style.setProperty('--k', page.clientWidth / pg.w); }).observe(page);
        wrap.append(page);
      });
      body.replaceChildren(wrap);
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
        items.push(el('label', { class: 'form-field t-' + f.type, 'data-key': f.key },
          el('span', {}, f.label, f.required ? el('b', { class: 'req', title: 'חובה' }, ' *') : null),
          c, f.help ? el('small', { class: 'muted' }, f.help) : null));
      }
      body.replaceChildren(el('div', { class: 'card fill-form' }, items));
    }

    async function renderBody() {
      renderHeader();
      try {
        if (mode === 'page') await renderPage(); else renderForm();
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
          el('span', { class: 'muted small' }, fmtDate(e.at) + (e.ip ? ' · ' + e.ip : '')),
          e.detail && e.detail.changes ? el('ul', { class: 'small' }, e.detail.changes.map(c => el('li', {},
            c.label + ': ', 'signed' in c ? (c.signed ? 'נחתם' : 'החתימה נמחקה')
              : el('span', {}, el('del', { class: 'muted' }, short(c.from)), ' → ', short(c.to))))) : null,
          e.detail && e.detail.number ? el('div', { class: 'small muted' }, 'מספר: ' + e.detail.number) : null,
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
            done ? null : el('div', { class: 'person-fields' }, name, email, phone),
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
    const { PDFDocument, rgb } = window.PDFLib;
    const fontBytes = await (await fetch(FONT)).arrayBuffer();

    let pdf;
    if (doc.mime === 'application/pdf') {
      pdf = await PDFDocument.load(srcBytes, { ignoreEncryption: true });
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
    const ink = rgb(0.04, 0.12, 0.4);
    const pages = pdf.getPages();
    const annex = [];

    for (const f of doc.schema.fields) {
      const raw = values[f.key] ?? '';
      if (raw === '' || !pages[f.page]) continue;
      const page = pages[f.page];
      const { width: W, height: H } = page.getSize();
      const bx = f.x * W, bw = f.w * W, bh = f.h * H, top = H - f.y * H;

      if (f.type === 'checkbox') {
        if (raw !== '1') continue;
        const s = Math.min(bw, bh), cx = bx + bw / 2, cy = top - bh / 2;
        page.drawLine({ start: { x: cx - s * 0.35, y: cy }, end: { x: cx - s * 0.08, y: cy - s * 0.3 }, thickness: Math.max(1, s / 8), color: ink });
        page.drawLine({ start: { x: cx - s * 0.08, y: cy - s * 0.3 }, end: { x: cx + s * 0.4, y: cy + s * 0.35 }, thickness: Math.max(1, s / 8), color: ink });
        continue;
      }
      if (f.type === 'signature') {
        const png = await pdf.embedPng(raw);
        const k = Math.min(bw / png.width, bh / png.height);
        page.drawImage(png, { x: bx + (bw - png.width * k) / 2, y: top - bh + (bh - png.height * k) / 2, width: png.width * k, height: png.height * k });
        continue;
      }

      const text = f.type === 'date' ? ddmmyyyy(raw) : raw;
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
      let y = top - (bh - blockH) / 2 - size;
      for (const line of r.lines) {
        const v = pdfText(line);
        const w = font.widthOfTextAtSize(v, size);
        const x = f.align === 'left' ? bx + pad : f.align === 'center' ? bx + (bw - w) / 2 : bx + bw - w - pad;
        page.drawText(v, { x, y, size, font, color: ink });
        y -= lh;
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
