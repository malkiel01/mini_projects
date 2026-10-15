// עורך תבנית — שלב 3: רקע של PDF או תמונה, ושדות שממקמים עליו.
//
// מיקום וגודל של שדה נשמרים כשבר מגודל הדף (0..1). כך הם נכונים בכל רוחב
// מסך ובכל רזולוציה, וגם בהפקת ה-PDF בהמשך.
(function () {
  'use strict';

  const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';

  // גודל ברירת מחדל לשדה חדש, בנקודות PDF (72 לאינץ'). מומר לשבר לפי הדף.
  const TYPES = {
    text:      { label: 'טקסט',       icon: 'Aa', w: 170, h: 20 },
    textarea:  { label: 'טקסט ארוך',  icon: '¶',  w: 260, h: 70 },
    number:    { label: 'מספר',       icon: '#',  w: 90,  h: 20 },
    date:      { label: 'תאריך',      icon: '📅', w: 90,  h: 20 },
    select:    { label: 'בחירה מרשימה', icon: '☰', w: 150, h: 20 },
    checkbox:  { label: 'תיבת סימון', icon: '☑',  w: 14,  h: 14 },
    signature: { label: 'חתימה',      icon: '✍️', w: 150, h: 50 },
    file:      { label: 'צירוף קובץ', icon: '📎', w: 170, h: 20 },
    calc:      { label: 'מחושב',      icon: 'ƒ',  w: 110, h: 20 },
    table:     { label: 'טבלה',       icon: '▦',  w: 400, h: 120 },
    system:    { label: 'מתמלא לבד',  icon: '⚙️', w: 120, h: 20 },
  };
  // שדה שמתמלא לבד בכל מסמך — מהמסמך עצמו
  const SYSTEM = {
    number: ['מספר המסמך', '#️⃣'], issued: ['תאריך הנפקה', '📅'], owner: ['שם המנפיק', '👤'],
    title: ['כותרת המסמך', '🏷️'], completed: ['תאריך השלמה', '✅'],
  };
  const isAuto = (f) => f.type === 'system' || f.type === 'calc';
  const iconOf = (f) => f.type === 'system' ? (SYSTEM[f.source] || SYSTEM.number)[1] : TYPES[f.type].icon;
  // טבלה חוזרת: גם על דף PDF — אזור שבתוכו השורות מודפסות כטבלה ("+ שורה" במילוי). מה שלא נכנס — לנספח
  const PLACEABLE = Object.keys(TYPES).filter(t => t !== 'system');
  const AUTHORED = 'application/x-dt-authored';
  const FORMATS = { number: 'מספר (1,234.5)', money: 'סכום (1,234.50 ₪)', date: 'תאריך (13/10/2026)' };
  const COND_OPS = { eq: 'שווה ל', neq: 'שונה מ', filled: 'מולא', empty: 'ריק' };
  const PH = /\{\{\s*([^{}]{1,60}?)\s*\}\}/g;
  /** ניחוש סוג לפי שם — כמו docx_fields בשרת. */
  const guess = (n) => /תאריך|date/i.test(n) ? 'date' : /סכום|מחיר|כמות|amount|price|sum/i.test(n) ? 'number'
    : /סימון|מאשר|☐|check/i.test(n) ? 'checkbox' : /הערות|תיאור|פירוט|notes/i.test(n) ? 'textarea' : 'text';
  const OVERFLOW = {
    shrink: 'להקטין גופן עד שייכנס',
    annex: 'להמשיך בנספח בסוף המסמך',
    block: 'לא לאפשר להקליד מעבר לגודל',
  };

  let pdfReady = null;
  function loadPdfJs() {
    if (window.pdfjsLib) return Promise.resolve();
    pdfReady ??= new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = PDFJS + 'pdf.min.js';
      s.onload = () => { pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js'; resolve(); };
      s.onerror = () => { pdfReady = null; reject(new Error('טעינת מציג ה-PDF נכשלה. בדקו חיבור לרשת')); };
      document.head.append(s);
    });
    return pdfReady;
  }

  /**
   * מצייר את קובץ הרקע (PDF או תמונה) ומחזיר לכל דף: אלמנט להצגה, ורוחב/גובה
   * בנקודות PDF. משמש גם את העורך וגם את מסך המסמך.
   */
  async function renderBackground(url, mime, headers) {
    const res = await fetch(url, { credentials: 'same-origin', headers: headers || {} });
    if (!res.ok) throw new Error('טעינת הקובץ נכשלה');
    const buf = await res.arrayBuffer();
    const out = [];
    if (mime === 'application/pdf') {
      await loadPdfJs();
      const pdf = await pdfjsLib.getDocument({ data: buf.slice(0) }).promise;
      for (let i = 1; i <= pdf.numPages; i++) {
        const page = await pdf.getPage(i);
        const vp1 = page.getViewport({ scale: 1 });
        const canvas = document.createElement('canvas');
        // רזולוציה לפי רוחב תצוגה של עד ~1000px, כפול צפיפות המסך.
        const scale = Math.min(3, (1000 / vp1.width) * (window.devicePixelRatio || 1));
        const vp = page.getViewport({ scale });
        canvas.width = Math.round(vp.width);
        canvas.height = Math.round(vp.height);
        await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
        out.push({ visual: canvas, w: vp1.width, h: vp1.height });
      }
      out.pdf = pdf;   // לפירוק (שלב 10)
    } else {
      const img = new Image();
      img.src = URL.createObjectURL(new Blob([buf], { type: mime }));
      await img.decode();
      // תמונה: "נקודות" לפי רוחב A4 (595), כדי שגדלי השדות ייראו סבירים.
      out.push({ visual: img, w: 595, h: 595 * img.naturalHeight / img.naturalWidth });
    }
    out.bytes = buf;
    return out;
  }

  const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

  /**
   * תצוגה מקדימה של Word בדפדפן (docx-preview). קרובה למקור, לא זהה —
   * הקובץ שיורד הוא Word עצמו, ושם העיצוב 1:1.
   */
  async function wordPreview(buf) {
    const load = (src) => new Promise((ok, bad) => {
      if (document.querySelector(`script[src="${src}"]`)) return ok();
      const sc = document.createElement('script');
      sc.src = src; sc.onload = ok; sc.onerror = () => bad(new Error('טעינת רכיב התצוגה נכשלה'));
      document.head.append(sc);
    });
    await load('https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js');
    await load('https://cdn.jsdelivr.net/npm/docx-preview@0.3.3/dist/docx-preview.min.js');
    const box = document.createElement('div');
    box.className = 'word-preview';
    await window.docx.renderAsync(buf, box, null, { inWrapper: true, ignoreLastRenderedPageBreak: true });
    // הדף ברוחב A4 אמיתי — מקטינים אותו לרוחב העמודה, כמו דפי PDF
    const wrap = box.querySelector('.docx-wrapper');
    if (wrap && window.ResizeObserver) {
      new ResizeObserver(() => {
        const page = wrap.querySelector('section');
        if (!page || !box.clientWidth) return;
        wrap.style.zoom = '';
        const need = page.offsetWidth + 32;
        wrap.style.zoom = need > box.clientWidth ? String(box.clientWidth / need) : '';
      }).observe(box);
    }
    return box;
  }

  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  /**
   * צופה עם זום לדפי המסמך (עורך, מילוי, ממלא חיצוני): − / 100% / + / ⛶,
   * צביטה שמגדילה רק את המסמך (לא את האתר), הקשה כפולה, ו-Ctrl+גלגלת.
   * גלילה באצבע אחת — רגילה. הבמה ב-ltr, כדי שחשבון הגלילה יהיה פשוט גם בעברית.
   */
  function zoomable(inner) {
    const mk = (tag, cls, attrs, ...kids) => {
      const n = document.createElement(tag);
      if (cls) n.className = cls;
      for (const [k, v] of Object.entries(attrs || {})) { if (k.startsWith('on')) n.addEventListener(k.slice(2), v); else n.setAttribute(k, v); }
      n.append(...kids);
      return n;
    };
    let z = 1;
    const stage = mk('div', 'zoom-stage', {}, inner);
    const pct = mk('button', 'zoom-pct', { type: 'button', 'aria-label': 'גודל מלא לרוחב', title: 'גודל מלא לרוחב', onclick: () => set(1) }, '100%');
    const full = mk('button', 'icon-btn', { type: 'button', 'aria-label': 'מסך מלא', title: 'מסך מלא', onclick: () => fullscreen() }, '⛶');
    const bar = mk('div', 'zoom-bar', { role: 'toolbar', 'aria-label': 'תצוגת המסמך' },
      mk('button', 'icon-btn', { type: 'button', 'aria-label': 'הקטנה', title: 'הקטנה', onclick: () => set(z / 1.25) }, '−'),
      pct,
      mk('button', 'icon-btn', { type: 'button', 'aria-label': 'הגדלה', title: 'הגדלה', onclick: () => set(z * 1.25) }, '+'),
      full);
    const box = mk('div', 'zoom-view', {}, bar, stage);

    /** זום סביב נקודה (במסך) — מה שמתחת לאצבעות נשאר במקום. */
    function set(nz, cx, cy) {
      nz = clamp(Math.round(nz * 100) / 100, 0.5, 4);
      if (nz === z) return;
      const r = stage.getBoundingClientRect();
      const ax = (cx ?? r.left + r.width / 2) - r.left, ay = (cy ?? r.top + Math.min(r.height, innerHeight) / 2) - r.top;
      const scroller = box.classList.contains('full') ? stage : null;
      const x = stage.scrollLeft + ax, y = (scroller ? stage.scrollTop : 0) + ay;
      const k = nz / z;
      z = nz;
      inner.style.width = z * 100 + '%';
      pct.textContent = Math.round(z * 100) + '%';
      stage.classList.toggle('zoomed', z > 1);
      stage.scrollLeft = x * k - ax;
      if (scroller) stage.scrollTop = y * k - ay;
      else window.scrollBy(0, ay * (k - 1));
    }
    function fullscreen(on = !box.classList.contains('full')) {
      box.classList.toggle('full', on);
      document.body.classList.toggle('zoom-lock', on);
      full.setAttribute('aria-label', on ? 'יציאה ממסך מלא' : 'מסך מלא');
      full.title = full.getAttribute('aria-label');
      full.textContent = on ? '✕' : '⛶';
    }
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && box.classList.contains('full')) fullscreen(false); });

    // צביטה בשתי אצבעות — מגדילה רק את המסמך
    let pinch = null;
    const dist = (t) => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
    stage.addEventListener('touchstart', (e) => {
      if (e.touches.length === 2) pinch = { d: dist(e.touches), z };
    }, { passive: true });
    stage.addEventListener('touchmove', (e) => {
      if (!pinch || e.touches.length !== 2) return;
      e.preventDefault();
      const cx = (e.touches[0].clientX + e.touches[1].clientX) / 2, cy = (e.touches[0].clientY + e.touches[1].clientY) / 2;
      set(pinch.z * dist(e.touches) / pinch.d, cx, cy);
    }, { passive: false });
    stage.addEventListener('touchend', (e) => { if (e.touches.length < 2) pinch = null; });
    // הקשה כפולה (לא על שדה) — 200% / חזרה
    stage.addEventListener('dblclick', (e) => {
      if (e.target.closest('input, textarea, button, select, .field')) return;
      set(z > 1.2 ? 1 : 2, e.clientX, e.clientY);
    });
    // Ctrl+גלגלת (וצביטה במשטח מגע במחשב)
    stage.addEventListener('wheel', (e) => {
      if (!e.ctrlKey) return;
      e.preventDefault();
      set(z * Math.exp(-e.deltaY * 0.0025), e.clientX, e.clientY);
    }, { passive: false });
    return { box, bar, stage, set, get zoom() { return z; } };
  }
  const uid = () => 'f' + Math.random().toString(36).slice(2, 9);

  async function open(id) {
    const { api, el, notice, show } = window.DT;
    show(el('p', { class: 'muted' }, 'טוען תבנית…'));
    window.DT.wide();

    let tpl;
    try {
      tpl = (await api('tpl_get', undefined, { id })).template;
    } catch (e) {
      show(notice(e.message, 'bad'), el('p', {}, el('a', { href: '#home' }, 'חזרה לתבניות')));
      return;
    }

    const schema = {
      pages: tpl.schema.pages || [],
      fields: (tpl.schema.fields || []).map(f => ({ role: 'owner', ...f })),
      roles: (tpl.schema.roles && tpl.schema.roles.length ? tpl.schema.roles : [{ key: 'owner', label: 'אני (המנפיק)', auth: 'none' }]).map(r => ({ ...r })),
      sequential: !!tpl.schema.sequential,
      certificate: !!tpl.schema.certificate,
      approval: !!tpl.schema.approval,
      fill_download: !!tpl.schema.fill_download,
      styles: (tpl.schema.styles || []).map(x => ({ ...x })),
      parsed: !!tpl.schema.parsed,
      ...(tpl.schema.body ? { body: tpl.schema.body.map(b => ({ ...b })) } : {}),
    };
    const roleIndex = (key) => Math.max(0, schema.roles.findIndex(r => r.key === key));
    // גופנים: מה שבתבנית (גם של הבעלים, כששותף עורך) + מה שהעליתי
    const fontNames = new Map((tpl.fonts || []).map(x => [x.id, x.name]));
    let myFonts = null;
    const loadMyFonts = () => (myFonts ??= api('font_list').then(r => { for (const x of r.fonts) fontNames.set(x.id, x.name); return r.fonts; }).catch(() => []));
    window.DTFonts.loadAll(schema, { tpl: id }).then(() => draw());
    let selected = null;
    let placing = null;
    let dirty = false;
    let saving = false;

    // ── מבנה המסך ──
    const nameInput = el('input', { class: 'tpl-name', value: tpl.name, maxlength: 120, 'aria-label': 'שם התבנית' });
    const status = el('span', { class: 'save-status muted small' });
    const saveBtn = el('button', { class: 'primary', onclick: () => save() }, 'שמירה');
    // עיצוב הטופס: "+ שדה" (אריחים בגיליון) ומקרא — מי ממלא כל צבע
    const addBtn = el('button', { class: 'primary', onclick: () => addSheet() }, '+ שדה');
    const legend = el('div', { class: 'legend', 'aria-label': 'מי ממלא — לפי צבע' });
    const placingText = el('span');
    // בזמן הצבה — ההנחיה במקום הכפתור והמקרא, באותו סרגל: הדף לא זז מתחת לאצבע
    const placingBar = el('div', { class: 'placing-bar', hidden: true, role: 'status' }, placingText,
      el('button', { class: 'small', onclick: () => setPlacing(null) }, 'ביטול'));
    // נעילת כל השדות בבת אחת — כשהעיצוב גמור, כדי ששום שדה לא יזוז בטעות
    const lockAllBtn = el('button', { type: 'button', class: 'small lock-all', onclick: () => {
      const lock = schema.fields.some(f => !f.locked);
      for (const f of schema.fields) f.locked = lock;
      setDirty(true); draw();
    } });
    const palette = el('div', { class: 'palette design-bar' }, addBtn, lockAllBtn, legend, placingBar);
    const pagesBox = el('div', { class: 'pages' });
    const viewer = zoomable(pagesBox);
    const panel = el('section', { class: 'panel card tab-panel', role: 'tabpanel' });
    // לשוניות: עיצוב הטופס · שדות · ממלאים · הגדרות — מסך ארוך מפוצל, לא גלילה אחת
    const TABS = [['design', '📄 עיצוב'], ['fields', '🧩 שדות'], ['roles', '👥 ממלאים'], ['settings', '⚙️ הגדרות']];
    let tab = 'design';
    try { tab = sessionStorage.getItem('dt-editor-tab') || 'design'; } catch (e) { /* אחסון חסום */ }
    const tabsEl = el('div', { class: 'seg-tabs', role: 'tablist', 'aria-label': 'עריכת התבנית' });
    const designView = el('div', { class: 'design-view' });
    const msg = el('div');
    const parseBox = el('div');
    let spots = [];          // שלב 10: מקומות מילוי מוצעים מהטקסט
    let showSpots = true;

    // שלב 7: תבנית ששותפה ברמה נמוכה מ"עורך" — צפייה בלבד.
    const readOnly = window.DT.rank(tpl.access || 'owner') < 3;
    if (readOnly) {
      nameInput.disabled = true;
      saveBtn.hidden = true;
      status.hidden = true;
      addBtn.hidden = true;
      pagesBox.classList.add('readonly');
    }

    designView.append(palette, parseBox, viewer.box);
    show(
      el('div', { class: 'editor-bar card' }, nameInput, status, saveBtn),
      tpl.owner_name ? el('p', { class: 'notice' }, `👥 תבנית של ${tpl.owner_name} — ההרשאה שלך: ${window.DT.LEVEL[tpl.access]}` +
        (readOnly ? '. צפייה בלבד.' : '.')) : null,
      tabsEl, msg, designView, panel);

    window.DT.setTab('home');
    window.DT.setBack('home');   // חזרה בכותרת — לתבניות
    window.DT.setLeaveGuard(Object.assign(
      () => !dirty || confirm('יש שינויים שלא נשמרו. לצאת בלי לשמור?'),
      { dirty: () => dirty }));

    function setDirty(v) {
      dirty = v;
      status.textContent = v ? '● שינויים לא שמורים' : '✓ נשמר';
      status.classList.toggle('warn', v);
    }
    nameInput.addEventListener('input', () => setDirty(true));

    // ── רקע ──
    const pageEls = [];
    const isWord = tpl.mime === DOCX;
    const isAuthored = tpl.mime === AUTHORED;
    if (isWord || isAuthored) { viewer.bar.hidden = true; addBtn.hidden = true; }   // בלי דפים — בלי זום; השדות מהטקסט
    if (isAuthored) {
      pagesBox.classList.add('word', 'authored');   // הגוף מצויר בסוף הפתיחה, כשהכול מאותחל
    } else if (isWord) {
      // תבנית Word: השדות באים מהסימונים {{...}} בקובץ. אין מיקום על דף —
      // מגדירים כאן רק את השדות, ומציגים תצוגה מקדימה של הקובץ.
      pagesBox.classList.add('word');
      pagesBox.replaceChildren(el('p', { class: 'notice' }, '📝 תבנית Word — השדות הם הסימונים {{...}} שבקובץ. ' +
        'כדי להוסיף שדה: מוסיפים סימון ב-Word ומעלים מחדש. כאן מגדירים לכל שדה סוג, חובה, מי ממלא ומילוי מאיש קשר.'));
      try {
        const res = await fetch('api.php?action=tpl_asset&id=' + id, { credentials: 'same-origin' });
        pagesBox.append(await wordPreview(await res.arrayBuffer()));
      } catch (e) {
        pagesBox.append(notice('התצוגה המקדימה לא נטענה: ' + e.message, 'bad'));
      }
    } else {
      try {
        var pages = await renderBackground('api.php?action=tpl_asset&id=' + id, tpl.mime);
        pages.forEach((pg, i) => addPage(pg.visual, pg.w, pg.h, i));
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
        return;
      }
    }

    // מבנה הדפים נקבע מהקובץ בפתיחה הראשונה.
    if (!schema.pages.length && !isWord && !isAuthored) {
      schema.pages = pageEls.map(p => ({ w: p.w, h: p.h }));
      setDirty(true);
    } else {
      setDirty(false);
    }

    // ── שלב 10: PDF "לפרק" ──
    let report = null;
    let autoSave = false;
    // תבנית מוכנה: PDF שנושא בתוכו את ההגדרות (קובץ מצורף dt-template.json — שדות, טבלאות, נוסחאות, ממלאים).
    // בפתיחה הראשונה, כשעוד אין שדות: ההגדרות נכנסות כמו שהן, במקום פירוק הטופס. השרת בודק הכול בשמירה.
    let imported = 0;
    if (pages && pages.pdf && !schema.fields.length && !readOnly && !schema.parsed) {
      try {
        const att = await pages.pdf.getAttachments();
        const it = att && Object.values(att).find(a => /^dt-template\.json$/i.test(a.filename || ''));
        const t = it ? JSON.parse(new TextDecoder().decode(it.content)) : null;
        if (t && Array.isArray(t.fields)) {
          // רשימה לפי שם ({list: {name, level}}) — הרשימה של המשתמש בשם הזה (📚 רשימות); אין כזו — בלי רשימה
          const mine = (await api('list_index').catch(() => ({ lists: [] }))).lists;
          const bind = (o) => {
            if (!o || !o.list || o.list.id) return;
            const l = mine.find(x => x.name === o.list.name);
            const level = Number(o.list.level) || 0;
            if (l && level < l.levels.length) { o.list = { id: l.id, level, ...(o.list.lang ? { lang: o.list.lang } : {}) }; return; }
            delete o.list;
            if (o.type === 'select' && !(o.options || []).length) o.type = 'text';   // בלי רשימה ובלי אפשרויות — טקסט חופשי, לא בחירה ריקה
          };
          for (const f of t.fields) { bind(f); (f.columns || []).forEach(bind); }
          for (const r of t.roles || []) if (r && r.key && !schema.roles.some(x => x.key === r.key)) schema.roles.push(r);
          for (const k of ['sequential', 'approval', 'fill_download', 'certificate']) if (k in t) schema[k] = !!t[k];
          schema.fields.push(...t.fields.filter(f => f && TYPES[f.type] && schema.pages[f.page || 0]).map(f => ({
            help: '', required: false, default: '', fontSize: 11, align: 'right', overflow: 'shrink', role: 'owner', page: 0, ...f, id: uid() })));
          imported = schema.fields.length;
          schema.parsed = true;   // לא לפרק גם את שדות הטופס — ההגדרות המוכנות קובעות
          setDirty(true);
          autoSave = imported > 0;
        }
      } catch (e) { /* אין הגדרות מוכנות — ממשיכים כרגיל */ }
    }
    if (pages && pages.pdf && tpl.source === 'pdf_parsed') {
      try { report = await window.DTParse.analyze(pages.pdf); }
      catch (e) { parseBox.replaceChildren(notice('הפירוק נכשל: ' + e.message, 'bad')); }
      // בפתיחה הראשונה: שדות הטופס המובנים נהפכים לשדות התבנית, במקומם המדויק.
      if (report && !schema.parsed && !readOnly) {
        const added = schema.fields.some(f => f.pdfName) ? [] : report.fields.map(asField);
        schema.fields.push(...added);
        schema.parsed = true;
        setDirty(true);
        autoSave = added.length > 0;   // נשמר בסוף הפתיחה, כשהכול מאותחל
      }
    }
    renderParse();

    function asField(p) {
      const n = schema.fields.filter(f => f.type === p.type).length + 1;
      const ascii = String(p.pdfName || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
      const key = (/^[a-z]/.test(ascii) ? ascii : p.type + '_' + n) + '_' + Math.random().toString(36).slice(2, 5);
      const { label: _l, ...rest } = p;
      return { id: uid(), key, help: '', required: false, default: '', fontSize: 11, align: 'right', overflow: 'shrink',
        role: 'owner', ...rest, label: (p.label || TYPES[p.type].label).slice(0, 120) };
    }

    /** הצעות שעדיין פנויות — בלי אלה שכבר יש עליהן שדה. */
    function freeSpots() { return spots.filter(s => !schema.fields.some(f => window.DTParse.overlap(f, s) > 0.3)); }

    function addSpot(s) {
      const t = TYPES[s.type] ? s.type : 'text';
      const p = schema.pages[s.page];
      // שטח קטן מדי לחתימה — מגדילים כלפי מעלה
      const h = t === 'signature' ? Math.max(s.h, 40 / p.h) : s.h;
      const f = asField({ type: t, label: s.label || TYPES[t].label, page: s.page, x: s.x, y: Math.max(0, s.y + s.h - h), w: s.w, h,
        fontSize: Math.round(Math.max(8, Math.min(12, s.h * p.h * 0.7))) });
      schema.fields.push(f);
      setDirty(true);
      return f;
    }

    function renderParse() {
      if (readOnly || isWord || tpl.mime !== 'application/pdf') { parseBox.replaceChildren(); return; }
      const toSource = async (source) => {
        // שומרים קודם — המעבר טוען את העורך מחדש
        if (dirty) { await save(); if (dirty) return; }
        try { await api('tpl_set_source', { id, source }); open(id); }
        catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
      };
      if (tpl.source !== 'pdf_parsed') {
        parseBox.replaceChildren(el('p', { class: 'parse-bar muted small' }, '🔒 PDF נעול — את השדות ממקמים לבד. ',
          el('button', { class: 'small', onclick: () => toSource('pdf_parsed') }, '🔍 לנסות לפרק את הקובץ')));
        return;
      }
      if (!report) return;
      spots = report.spots;
      const free = freeSpots();
      if (!report.fields.length && report.chars < 20) {
        parseBox.replaceChildren(el('div', { class: 'notice bad parse-bar' },
          el('strong', {}, '❌ לא הצלחתי לפרק. '),
          'אין בקובץ שדות טופס ולא שכבת טקסט — כנראה סריקה או תמונה. ',
          el('div', { class: 'ops' },
            el('button', { class: 'small', onclick: () => toSource('pdf_locked') }, '🔒 להמשיך כקובץ נעול'),
            el('button', { class: 'small', disabled: true, title: 'דורש קרדיטים שהמפתח מקצה — שלב 13' }, '🤖 לפרק בבינה מלאכותית (בקרוב)'))));
        return;
      }
      const toggle = el('input', { type: 'checkbox', checked: showSpots });
      toggle.addEventListener('change', () => { showSpots = toggle.checked; draw(); });
      parseBox.replaceChildren(el('div', { class: 'notice ok parse-bar' },
        el('strong', {}, '✅ הצלחתי. '),
        report.fields.length ? `נמצאו ${report.fields.length} שדות טופס — הם בתבנית, במקומם. ` : 'אין בקובץ שדות טופס מובנים. ',
        report.chars >= 20 ? 'קראתי את הטקסט' + (spots.length ? ` — ויש ${free.length} הצעות למקומות מילוי (מסומנות בקו מקווקו; לחיצה הופכת לשדה).` : ', ולא מצאתי בו מקומות מילוי ברורים.') : '',
        el('div', { class: 'ops' },
          spots.length ? el('label', { class: 'inline small' }, toggle, ' להציג הצעות') : null,
          free.length ? el('button', { class: 'small', onclick: () => {
            for (const s of freeSpots()) addSpot(s);
            select(null); renderParse();
          } }, `+ להוסיף את כל ${free.length} ההצעות`) : null,
          el('button', { class: 'small', onclick: () => toSource('pdf_locked') }, '🔒 להפוך לנעול'))));
    }

    function spotEl(s) {
      const b = el('button', { type: 'button', class: 'spot', title: 'להוסיף שדה: ' + (s.label || ''),
        style: `left:${s.x * 100}%; top:${s.y * 100}%; width:${s.w * 100}%; height:${s.h * 100}%` },
        el('span', {}, '+ ' + (s.label || TYPES[s.type]?.label || 'שדה')));
      b.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
        if (placing) return;
        e.preventDefault();
        const f = addSpot(s);
        renderParse();
        editField(f);
      });
      return b;
    }

    function addPage(visual, w, h, index) {
      const layer = el('div', { class: 'layer' });
      const wrap = el('div', { class: 'page', style: `aspect-ratio: ${w} / ${h}` }, visual, layer,
        el('span', { class: 'page-no' }, String(index + 1)));
      visual.classList.add('page-bg');
      layer.addEventListener('pointerdown', (e) => {
        if (e.target !== layer) return;
        if (placing) {
          const r = layer.getBoundingClientRect();
          addField(placing, index, (e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height);
        } else {
          select(null);
        }
      });
      pagesBox.append(wrap);
      pageEls.push({ w, h, layer });
    }

    // ── שדות ──
    // הצבה: בוחרים סוג ב"+ שדה", ואז מקישים במקום בדף. p = { type, source? }
    function setPlacing(p) {
      placing = p;
      pagesBox.classList.toggle('placing', !!p);
      placingBar.hidden = !p;
      legend.hidden = !!p;
      addBtn.hidden = !!p || readOnly || isWord || isAuthored;
      if (p) placingText.textContent = '👆 הקישו במקום בדף שבו יופיע: ' + (p.type === 'system' ? SYSTEM[p.source][0] : TYPES[p.type].label);
    }

    /** "+ שדה": אריחים — שדות למילוי, ושדות שמתמלאים לבד. */
    function addSheet() {
      let dlg;
      const pick = (p) => () => { dlg.close(); setPlacing(p); };
      const t = (type) => { const b = window.DT.tile(TYPES[type].icon, TYPES[type].label, pick({ type })); b.dataset.type = type; return b; };
      dlg = window.DT.sheet('הוספת שדה', [
        el('p', { class: 'hint' }, 'בוחרים סוג, ואז מקישים במקום בדף. אחר כך — מי ממלא, הרשאות וכל השאר.'),
        el('h3', { class: 'sec-title' }, '✍️ למילוי — אני או ממלא בקישור'),
        el('div', { class: 'tiles' }, PLACEABLE.filter(k => k !== 'calc').map(t)),
        el('h3', { class: 'sec-title' }, '⚙️ מתמלא לבד בכל מסמך'),
        el('div', { class: 'tiles' },
          Object.entries(SYSTEM).map(([src, [label, icon]]) => {
            const b = window.DT.tile(icon, label, pick({ type: 'system', source: src }));
            b.dataset.type = 'system:' + src;
            return b;
          }),
          (() => { const b = window.DT.tile('ƒ', 'מחושב — לפי נוסחה', pick({ type: 'calc' })); b.dataset.type = 'calc'; return b; })()),
      ]);
    }

    function addField(p, page, cx, cy) {
      const type = p.type;
      const t = TYPES[type];
      const pg = schema.pages[page];
      const w = clamp(t.w / pg.w, 0.01, 1);
      const h = clamp(t.h / pg.h, 0.005, 1);
      const n = schema.fields.filter(f => f.type === type).length + 1;
      const f = {
        id: uid(), key: (type === 'system' ? p.source : type) + '_' + n + '_' + Math.random().toString(36).slice(2, 5), type,
        label: type === 'system' ? SYSTEM[p.source][0] : t.label + ' ' + n, help: '', required: false, default: '',
        page, x: clamp(cx - w / 2, 0, 1 - w), y: clamp(cy - h / 2, 0, 1 - h), w, h,
        fontSize: 11, align: 'right', overflow: 'shrink', role: 'owner',
        ...(type === 'system' ? { source: p.source } : {}), ...(type === 'calc' ? { formula: '', format: 'number' } : {}),
        ...(type === 'select' ? { options: [] } : {}),
        ...(type === 'table' ? { fontSize: 9, overflow: 'annex', group: false, columns: [
          { key: 'c1', label: 'תיאור', type: 'text' }, { key: 'c2', label: 'כמות', type: 'number' }, { key: 'c3', label: 'מחיר', type: 'number' }] } : {}),
      };
      schema.fields.push(f);
      setPlacing(null);
      setDirty(true);
      draw();
      editField(f);
    }

    function fieldEl(f) {
      const box = el('div', {
        class: 'field t-' + f.type + (isAuto(f) ? ' auto' : ' role-' + (roleIndex(f.role) % 6)) + (selected === f ? ' selected' : '')
          + (f.required ? ' required' : '') + (f.hidden ? ' private' : '') + (f.locked ? ' locked' : '') + (rolesOfF(f).length > 1 ? ' multi' : ''),
        style: `left:${f.x * 100}%; top:${f.y * 100}%; width:${f.w * 100}%; height:${f.h * 100}%`,
        title: f.label + (f.locked ? ' — 🔒 נעול במקומו. לחיצה: הגדרות' : ' — לחיצה: הגדרות; גרירה: הזזה'), tabindex: 0, role: 'button',
      }, el('span', { class: 'field-label va-' + (f.valign || 'middle') + ' ha-' + (f.align || 'right'),
          style: f.font ? `font-family:${window.DTFonts.family(f.font)}` : null }, iconOf(f) + ' ' + f.label),
        el('span', { class: 'handle', 'aria-hidden': 'true' }));
      box.addEventListener('pointerdown', (e) => startDrag(e, f, e.target.classList.contains('handle')));
      // נעול: לא נגרר — הקשה פותחת הגדרות, ואצבע על השדה גוללת ומגדילה את הדף כרגיל
      box.addEventListener('click', (e) => { if (f.locked && !readOnly) { e.stopPropagation(); editField(f); } });
      box.addEventListener('focus', () => { if (selected !== f) { selected = f; for (const n of pagesBox.querySelectorAll('.field.selected')) n.classList.remove('selected'); box.classList.add('selected'); } });
      box.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); editField(f); } });
      return box;
    }

    function draw() {
      renderLegend();
      const anyOpen = schema.fields.some(f => !f.locked);
      lockAllBtn.textContent = anyOpen ? '🔒 נעילת הכול' : '🔓 שחרור הכול';
      lockAllBtn.title = anyOpen ? 'לנעול את כל השדות במקומם' : 'לשחרר את כל השדות להזזה';
      lockAllBtn.hidden = readOnly || isWord || isAuthored || !schema.fields.length;
      const sp = showSpots && !readOnly ? freeSpots() : [];
      pageEls.forEach((p, i) => p.layer.replaceChildren(
        ...sp.filter(s => s.page === i).map(spotEl),
        ...schema.fields.filter(f => f.page === i).map(fieldEl)));
    }

    // גרירה: הזזה, או שינוי גודל מהידית. התנועה מחושבת כשבר מגודל הדף.
    function startDrag(e, f, resize) {
      if (readOnly) { e.stopPropagation(); editField(f); return; }
      if (f.locked) { e.stopPropagation(); return; }   // הלחיצה (click) פותחת את ההגדרות
      e.preventDefault();
      e.stopPropagation();
      const box = e.currentTarget;
      // בלי ציור מחדש: הוא היה מחליף את האלמנט שהגרירה תפסה.
      if (selected !== f) {
        selected = f;
        for (const n of pagesBox.querySelectorAll('.field.selected')) n.classList.remove('selected');
        box.classList.add('selected');
      }
      const layer = pageEls[f.page].layer;
      const r = layer.getBoundingClientRect();
      const start = { x: e.clientX, y: e.clientY, fx: f.x, fy: f.y, fw: f.w, fh: f.h };
      box.setPointerCapture(e.pointerId);
      let moved = false;
      const onMove = (ev) => {
        const dx = (ev.clientX - start.x) / r.width;
        const dy = (ev.clientY - start.y) / r.height;
        if (Math.abs(dx) + Math.abs(dy) > 0.001) moved = true;
        if (resize) {
          f.w = clamp(start.fw + dx, 0.01, 1 - f.x);
          f.h = clamp(start.fh + dy, 0.005, 1 - f.y);
        } else {
          f.x = clamp(start.fx + dx, 0, 1 - f.w);
          f.y = clamp(start.fy + dy, 0, 1 - f.h);
        }
        Object.assign(box.style, { left: f.x * 100 + '%', top: f.y * 100 + '%', width: f.w * 100 + '%', height: f.h * 100 + '%' });
      };
      const onUp = () => {
        box.removeEventListener('pointermove', onMove);
        box.removeEventListener('pointerup', onUp);
        box.removeEventListener('pointercancel', onUp);
        if (moved) setDirty(true);
        else editField(f);   // הקשה בלי גרירה — פותחת את הגדרות השדה
      };
      box.addEventListener('pointermove', onMove);
      box.addEventListener('pointerup', onUp);
      box.addEventListener('pointercancel', onUp);
    }

    function select(f) {
      selected = f;
      draw();
      renderPanel();
      if (isAuthored) renderBlocks();   // תצוגת הטבלה בגוף מתעדכנת אחרי עריכת עמודות
    }

    function editField(f) {
      select(f);
      fieldSheet(f);
    }

    function removeField(f) {
      if (isAuthored) {
        // נכתב בכלי: השדה הוא הסימון בטקסט — מוחקים גם אותו (ואת קטע הטבלה)
        if (!confirm(`למחוק את "${f.label}" — גם מהטקסט?`)) return;
        for (const b of schema.body) {
          if (b.text) b.text = b.text.replace(PH, (m, n) => n.trim() === f.placeholder ? '' : m);
        }
        schema.body = schema.body.filter(b => !(b.type === 'table' && b.table === f.key));
        renderBlocks();
      }
      schema.fields = schema.fields.filter(x => x !== f);
      for (const x of schema.fields) if (x.showIf && x.showIf.key === f.key) x.showIf = null;
      for (const d of document.querySelectorAll('dialog.field-dlg[open]')) d.close();
      setDirty(true);
      select(null);
      if (spots.length) renderParse();   // ההצעה שמתחתיו חוזרת
    }

    function duplicate(f) {
      const c = { ...f, id: uid(), key: f.key + '_2', label: f.label + ' (עותק)', locked: false,
        y: clamp(f.y + f.h + 0.01, 0, 1 - f.h) };
      schema.fields.push(c);
      setDirty(true);
      for (const d of document.querySelectorAll('dialog.field-dlg[open]')) d.close();
      editField(c);
    }

    // מקלדת: מחיקה, ובחצים — הזזה עדינה (Shift — צעד גדול).
    document.addEventListener('keydown', onKey);
    function onKey(e) {
      if (readOnly) return;
      if (!document.body.contains(pagesBox)) { document.removeEventListener('keydown', onKey); return; }
      if (e.key === 'Escape') { setPlacing(null); return; }
      if (document.querySelector('dialog[open]')) return;   // גיליון פתוח — המקשים שלו
      if (!selected || /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) return;
      if (selected.locked) return;   // נעול: לא זז בחצים ולא נמחק ב-Delete
      if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeField(selected); return; }
      const step = e.shiftKey ? 0.01 : 0.002;
      const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
      if (!d) return;
      e.preventDefault();
      selected.x = clamp(selected.x + d[0], 0, 1 - selected.w);
      selected.y = clamp(selected.y + d[1], 0, 1 - selected.h);
      setDirty(true);
      draw();
      pagesBox.querySelector('.field.selected')?.focus();
    }

    // ── לשוניות ולוחות ──
    const roleLabel = (key) => (schema.roles.find(r => r.key === key) || schema.roles[0]).label;
    /** מי מתפעל את השדה — במילים: לבד, אני, או ממלא בקישור. */
    /** ההסתרה מהממלאים — רק כשיש ממי להסתיר; ומי שממלא את השדה רואה אותו תמיד. */
    function hiddenCtl(f, toggle) {
      const others = hideFrom(f).map(r => r.label);
      const fills = rolesOfF(f).filter(k => k !== 'owner').map(roleLabel);
      const dropped = hiddenDropped === f;
      hiddenDropped = null;
      const note = dropped ? el('p', { class: 'hint warn-hint' }, '⚠️ ההסתרה בוטלה: עכשיו כל הממלאים בקישור ממלאים את השדה — אין ממי להסתיר אותו.') : null;
      if (!others.length) {
        const many = fills.length > 1;
        return fills.length ? [note, el('p', { class: 'hint' }, `🔗 ${fills.join(' ו')} ${many ? 'ממלאים' : 'ממלא'} את השדה בקישור — ולכן ${many ? 'רואים' : 'רואה'} אותו. אי אפשר להסתיר שדה ממי שממלא אותו.`)] : note;
      }
      return [note, fills.length
        ? toggle('מוסתר משאר הממלאים בקישור', `לא רואים אותו: ${others.join(', ')}. רואים: אתם ו${fills.join(' ו')}, ${fills.length > 1 ? 'שממלאים' : 'שממלא'} אותו`, () => !!f.hidden, v => { f.hidden = v; })
        : toggle('מוסתר מהממלאים בקישור', 'רק אתם רואים אותו — מידע פנימי', () => !!f.hidden, v => { f.hidden = v; })];
    }

    function whoOf(f) {
      if (f.type === 'system') return '⚙️ מתמלא לבד';
      if (f.type === 'calc') return 'ƒ מחושב לבד';
      if (rolesOfF(f).length > 1) return '🔀 ' + rolesNames(f);
      return (f.role || 'owner') === 'owner' ? '✍️ אני ממלא' : '🔗 ' + roleLabel(f.role) + ' — בקישור';
    }
    // כמה ממלאים לאותו שדה ("או פלוני או אלמוני"): role — הראשון, roles — כולם
    const rolesOfF = (f) => f.roles && f.roles.length ? f.roles : [f.role || 'owner'];
    const rolesNames = (f) => rolesOfF(f).map(k => k === 'owner' ? 'אני' : roleLabel(k)).join(' או ');
    function setRoles(f, list) {
      const keep = schema.roles.map(r => r.key).filter(k => list.includes(k));   // לפי סדר הממלאים
      f.role = keep[0] || 'owner';
      f.roles = keep.length > 1 ? keep : [];
      fixHidden(f);
    }
    // "מוסתר" — ממי: ממלאים בקישור שלא ממלאים את השדה. מי שממלא — תמיד רואה
    const hideFrom = (f) => schema.roles.filter(r => r.key !== 'owner' && !rolesOfF(f).includes(r.key));
    let hiddenDropped = null;   // שדה שההסתרה שלו בוטלה עכשיו — להסביר בגיליון
    /** אכיפה: אין ממי להסתיר — ההסתרה מתבטלת (כמו בשרת). */
    function fixHidden(f) {
      if (f.hidden && !hideFrom(f).length) { f.hidden = false; hiddenDropped = f; }
    }

    function renderLegend() {
      const used = new Set(schema.fields.filter(f => !isAuto(f)).map(f => f.role || 'owner'));
      legend.replaceChildren(
        ...schema.roles.map((r, i) => el('span', { class: 'chip legend-chip', title: r.key === 'owner' ? 'אני ממלא' : 'ממלא בקישור' },
          el('span', { class: 'role-dot role-' + (i % 6) }), (r.key === 'owner' ? 'אני' : r.label + ' (קישור)') +
            (used.has(r.key) ? '' : ' · 0'))),
        el('span', { class: 'chip legend-chip' }, el('span', { class: 'role-dot auto' }), 'מתמלא לבד'));
    }

    function renderTabs() {
      const count = { fields: schema.fields.length, roles: schema.roles.length };
      tabsEl.replaceChildren(...TABS.map(([k, label]) => el('button', {
        type: 'button', role: 'tab', 'aria-selected': String(tab === k), class: tab === k ? 'on' : '', 'data-tab': k,
        onclick: () => { tab = k; try { sessionStorage.setItem('dt-editor-tab', k); } catch (e) { /* חסום */ } renderPanel(); },
      }, label, count[k] != null ? el('span', { class: 'count' }, String(count[k])) : null)));
    }

    function renderPanel() {
      renderTabs();
      designView.hidden = tab !== 'design';
      // תיבות טקסט שגודלו כשהלשונית הייתה מוסתרת קיבלו גובה 0 — מחשבים מחדש
      if (tab === 'design') requestAnimationFrame(() => { for (const ta of pagesBox.querySelectorAll('.block-text')) { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; } });
      panel.hidden = tab === 'design';
      if (tab === 'design') { panel.replaceChildren(); return; }
      if (tab === 'fields') panel.replaceChildren(fieldsList());
      else if (tab === 'roles') panel.replaceChildren(rolesPanel());
      else panel.replaceChildren(outputPanel(), isWord || isAuthored ? el('span') : el('div', { class: 'numbering' }, el('h2', {}, '🎨 סגנונות'), stylesPanel()), numberingPanel(), el('div', { class: 'numbering api-hint' }, el('h2', {}, '🔌 הנפקה ממערכת חיצונית'),
        el('p', { class: 'hint' }, 'מערכת חיצונית (CRM, אתר) יכולה להנפיק מסמכים מהתבנית הזאת דרך ה-API, ולקבל הודעה כשמולאו.'),
        el('p', { class: 'small' }, 'מזהה התבנית: ', el('code', { dir: 'ltr' }, String(id))),
        el('a', { class: 'link small', href: '#connections' }, 'מפתחות ודוגמאות — ב"🔌 חיבורים"')),
        fontsPanel(),
        el('div', { class: 'numbering' }, el('h2', {}, 'תגיות לתבנית'),
        window.DTCatalog.tagsInput(tpl.tags, async (tags) => {
          try { await api('tpl_tags', { id, tags }); tpl.tags = tags; }
          catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
        }, { disabled: readOnly })));
      // צפייה בלבד: הלוח מוצג, אבל אי אפשר לשנות דבר (חוץ ממעבר בין שדות).
      if (readOnly) for (const n of panel.querySelectorAll('input, select, textarea, button:not(.use-row)')) n.disabled = true;
    }

    /** כל השדות — לפי מי מתפעל אותם. שורה = פתיחת ההגדרות. */
    function fieldsList() {
      const groups = [];
      const add = (title, hint, list) => { if (list.length) groups.push([title, hint, list]); };
      const single = (f) => !isAuto(f) && rolesOfF(f).length === 1;
      add('✍️ אני ממלא', 'בהנפקה או בעריכת המסמך', schema.fields.filter(f => single(f) && (f.role || 'owner') === 'owner'));
      for (const r of schema.roles.filter(r => r.key !== 'owner')) {
        add('🔗 ' + r.label + ' — ממלא בקישור', r.auth === 'email' ? 'נכנס עם קוד במייל' : 'נכנס מהקישור', schema.fields.filter(f => single(f) && f.role === r.key));
      }
      const combos = [...new Set(schema.fields.filter(f => !isAuto(f) && rolesOfF(f).length > 1).map(f => rolesOfF(f).join('|')))];
      for (const c of combos) {
        const list = schema.fields.filter(f => !isAuto(f) && rolesOfF(f).join('|') === c);
        add('🔀 ' + rolesNames(list[0]), 'כל אחד מהם יכול למלא — מספיק שאחד מילא', list);
      }
      add('⚙️ מתמלא לבד', 'מהמסמך עצמו או מנוסחה — אף אחד לא מקליד', schema.fields.filter(isAuto));
      const sub = (f) => [
        f.type === 'system' ? (SYSTEM[f.source] || SYSTEM.number)[0] : TYPES[f.type].label,
        f.required ? 'חובה' : null,
        isWord || isAuthored ? (f.placeholder ? '{{' + f.placeholder + '}}' : null) : 'דף ' + (f.page + 1),
        f.hidden ? '🙈 מוסתר מהממלאים' : null,
        f.print === false ? '🚫 לא מודפס' : null,
        f.showIf ? '👁 מותנה' : null,
      ].filter(Boolean).join(' · ');
      return el('div', { class: 'fields-tab' },
        schema.fields.length ? null : el('p', { class: 'empty' }, isWord || isAuthored
          ? 'אין עדיין שדות — מוסיפים סימון {{שם}} בטקסט.' : 'אין עדיין שדות. בלשונית "עיצוב" — "+ שדה", ואז הקשה במקום בדף.'),
        groups.map(([title, hint, list]) => el('section', { class: 'field-group' },
          el('h3', { class: 'sec-title' }, title, el('small', { class: 'muted' }, ' · ' + hint)),
          el('ul', { class: 'list field-list' }, list.map(f => el('li', {},
            el('button', { type: 'button', class: 'use-row', 'data-key': f.key, onclick: () => editField(f) },
              el('span', { class: 'use-ic' + (isAuto(f) ? ' auto' : ' role-' + (roleIndex(f.role) % 6)), 'aria-hidden': 'true' }, iconOf(f)),
              el('span', { class: 'use-tx' }, el('b', {}, f.label), el('small', {}, sub(f))),
              el('span', { class: 'chev', 'aria-hidden': 'true' }, '‹'))))))),
        isWord || isAuthored ? null : el('p', { class: 'hint' }, 'בדף: הקשה על שדה — הגדרות; גרירה — הזזה; הפינה — גודל; חצים — הזזה עדינה; Delete — מחיקה.'));
    }

    /**
     * מבנה התצוגה של תאריך / מספר — כמו תבנית מותאמת בוורד: מבנים מוכנים עם
     * דוגמה חיה, או מבנה משלכם (dd/MM/yyyy, hd בhMMMM hyy, ₪ #,##0.00…).
     */
    // ── מבנה התצוגה — כמו "עיצוב תאים" באקסל: קטגוריה, אפשרויות, דוגמה, וקוד (סוג) שאפשר לערוך ──
    const fmtKind = (f) => window.DTFmt.isDate(f) ? 'date' : window.DTFmt.isNumber(f) ? 'number' : 'text';
    const LTR = (t) => '\u2066' + t + '\u2069';   // מספר שלילי נקרא משמאל לימין גם בתוך טקסט עברי
    const NEG = [['-', LTR('-1,234.10')], ['red', 'באדום: ' + LTR('1,234.10')], ['paren', LTR('(1,234.10)')], ['redparen', 'באדום: ' + LTR('(1,234.10)')], ['redminus', 'באדום: ' + LTR('-1,234.10')]];
    const SYMS = [['₪ ', '₪ לפני'], [' ש״ח', 'ש״ח אחרי'], ['$', '$'], ['€', '€'], ['', 'בלי סמל']];
    const FRACTIONS = [['# ?/?', 'עד ספרה אחת (1/4)'], ['# ??/??', 'עד שתי ספרות (21/25)'], ['# ???/???', 'עד שלוש ספרות'],
      ['# ?/2', 'חצאים (1/2)'], ['# ?/4', 'רבעים (2/4)'], ['# ?/8', 'שמיניות (4/8)'], ['# ?/10', 'עשיריות (3/10)'], ['# ??/100', 'מאיות (30/100)']];
    const SPECIAL = [['000-0000000', 'טלפון נייד'], ['00-0000000', 'טלפון קווי'], ['000000000', 'תעודת זהות (9 ספרות)'], ['0000000', 'מיקוד']];
    const CATS = {
      number: [['general', 'כללי'], ['number', 'מספר'], ['currency', 'מטבע'], ['accounting', 'חשבונאות'], ['percent', 'אחוזים'],
        ['fraction', 'שבר'], ['scientific', 'מדעי'], ['special', 'מיוחד'], ['custom', 'מותאם אישית']],
      date: [['general', 'כללי'], ['date', 'תאריך'], ['custom', 'מותאם אישית']],
      text: [['general', 'כללי'], ['text', 'טקסט'], ['special', 'מיוחד'], ['custom', 'מותאם אישית']],
    };
    /** קוד מאפשרויות: d — ספרות אחרי הנקודה, sep — מפריד אלפים, neg — איך שלילי, sym — סמל מטבע. */
    function fmtCode(cat, o) {
      const base = (o.sep ? '#,##0' : '0') + (o.d ? '.' + '0'.repeat(o.d) : '');
      const money = (b) => o.sym.startsWith(' ') ? b + o.sym : o.sym + b;
      const negs = (b) => ({ '-': b, red: `${b};[אדום]${b}`, paren: `${b};(${b})`, redparen: `${b};[אדום](${b})`, redminus: `${b};[אדום]-${b}` })[o.neg];
      if (cat === 'number') return negs(base);
      if (cat === 'currency') { const b = money(base); return o.neg === '-' ? `${b};-${b}` : negs(b); }
      if (cat === 'accounting') { const b = money('#,##0' + (o.d ? '.' + '0'.repeat(o.d) : '')); return `${b};(${b});${money('"-"')}`; }
      if (cat === 'percent') return '0' + (o.d ? '.' + '0'.repeat(o.d) : '') + '%';
      if (cat === 'scientific') return '0' + (o.d ? '.' + '0'.repeat(o.d) : '') + 'E+00';
      if (cat === 'text') return '@';
      return '';
    }
    /** מאיזו קטגוריה הקוד, ובאילו אפשרויות — כדי לפתוח את החלון במקום הנכון. */
    function fmtGuess(kind, code) {
      const o = { d: 2, sep: true, neg: '-', sym: '₪ ' };
      if (!code) return ['general', o];
      const dec = /\.(0+)/.exec(code.split(';')[0]); o.d = dec ? dec[1].length : 0;
      o.sep = code.includes(',');
      const sym = SYMS.find(([v]) => v && code.includes(v.trim())); if (sym) o.sym = sym[0];
      const sec = code.split(';');
      o.neg = sec.length < 2 ? '-' : /\[אדום\]\(/.test(sec[1]) ? 'redparen' : /\[אדום\]-/.test(sec[1]) ? 'redminus' : /\[אדום\]/.test(sec[1]) ? 'red' : /^\(/.test(sec[1]) ? 'paren' : '-';
      const cands = kind === 'date' ? ['date'] : kind === 'text' ? ['text', 'special'] : ['number', 'currency', 'accounting', 'percent', 'scientific', 'fraction', 'special'];
      for (const c of cands) {
        if (c === 'date' && window.DTFmt.PRESETS.date.some(([p]) => p === code)) return ['date', o];
        if (c === 'fraction' && FRACTIONS.some(([p]) => p === code)) return ['fraction', o];
        if (c === 'special' && SPECIAL.some(([p]) => p === code)) return ['special', o];
        if (!['date', 'fraction', 'special'].includes(c) && fmtCode(c, o) === code) return [c, o];
      }
      return ['custom', o];
    }
    // ── רשימות ערכים (🔌 חיבורים ← 📚 רשימות): שדה או עמודה ← רשימה + רמה ──
    let myLists = null;
    const loadLists = async () => (myLists ??= (await api('list_index').catch(() => ({ lists: [] }))).lists);
    const LANG_NAMES = { en: 'אנגלית · English', fr: 'צרפתית · Français', ar: 'ערבית · العربية', ru: 'רוסית · Русский', es: 'ספרדית · Español', de: 'גרמנית · Deutsch' };
    function listPicker(o, after) {
      const box = el('div', { class: 'list-pick' });
      const draw_ = async () => {
        const ls = await loadLists();
        const cur = o.list ? ls.find(l => l.id === o.list.id) : null;
        const sel = el('select', { 'aria-label': 'רשימת ערכים', disabled: readOnly }, el('option', { value: '' }, '— בלי —'),
          ls.map(l => el('option', { value: l.id, selected: !!cur && cur.id === l.id }, l.name)),
          o.list && !cur ? el('option', { value: o.list.id, selected: true }, 'רשימה של משתמש אחר') : null);
        sel.addEventListener('change', () => {
          if (Number(sel.value)) o.list = { id: Number(sel.value), level: 0 }; else delete o.list;
          setDirty(true); draw_(); if (after) after();
        });
        const lv = cur && cur.levels.length > 1 ? el('select', { 'aria-label': 'רמה', disabled: readOnly },
          cur.levels.map((n, i) => el('option', { value: i, selected: o.list.level === i }, `${i + 1}. ${n}`))) : null;
        if (lv) lv.addEventListener('change', () => { o.list.level = Number(lv.value); setDirty(true); });
        // שפה בפלט: בוחרים בעברית, ובמסמך שיוצא (PDF / Word / הדפסה) נכתב התרגום
        const langs = cur ? [...new Set([...(cur.langs || []), ...(o.list.lang ? [o.list.lang] : [])])] : [];
        const lang = cur ? el('select', { 'aria-label': 'שפה בפלט', disabled: readOnly },
          el('option', { value: '' }, 'כמו שנבחר (עברית)'),
          langs.map(k => el('option', { value: k, selected: o.list.lang === k }, LANG_NAMES[k] || k))) : null;
        if (lang) lang.addEventListener('change', () => { if (lang.value) o.list.lang = lang.value; else delete o.list.lang; setDirty(true); });
        box.replaceChildren(...[el('div', { class: 'fields two' },
          el('label', { class: 'f' }, el('span', {}, '📚 ערכים מרשימה'), sel),
          lv ? el('label', { class: 'f' }, el('span', {}, 'רמה'), lv) : el('span')),
          lang ? el('label', { class: 'f' }, el('span', {}, '🌐 שפה במסמך'), lang) : null,
          cur && !langs.length ? el('p', { class: 'hint' }, 'אפשר להדפיס את הערכים בשפה אחרת (למשל אנגלית): מוסיפים תרגומים בעריכת הרשימה — "🌐 תרגומים".') : null,
          !ls.length ? el('p', { class: 'hint' }, 'עוד אין רשימות. יוצרים ב"🔌 חיבורים" ← "📚 רשימות" — ידנית או מקובץ Excel.') : null,
          cur && cur.levels.length > 1 ? el('p', { class: 'hint' }, `רשימה מקוננת (${cur.levels.join(' › ')}): שדות ועמודות מאותה רשימה ברמות שמעל מסננים את האפשרויות כאן.`) : null].filter(Boolean));
      };
      draw_();
      return box;
    }

    let myFmtCodes = null;
    const loadFmtCodes = async () => (myFmtCodes ??= (await api('fmt_codes').catch(() => ({ codes: [] }))).codes);

    /** בגיליון השדה: המבנה הנוכחי, דוגמה, ו"עיצוב…" שפותח את החלון. */
    function fmtPicker(f) {
      const kind = fmtKind(f);
      const now = el('p', { class: 'fmt-now' });
      const paint = () => {
        const [cat] = fmtGuess(kind, f.fmt || '');
        const r = f.fmt ? window.DTFmt.rich(f, fmtSampleValue(f, kind)) : null;
        now.replaceChildren(el('span', { class: 'muted' }, (CATS[kind].find(([k]) => k === cat) || ['', 'מותאם אישית'])[1] + ' · '),
          f.fmt ? el('code', { dir: 'ltr' }, f.fmt) : 'בלי מבנה',
          ...(r ? [el('span', { class: 'muted' }, ' · דוגמה: '), el('b', { style: r.color ? `color:${r.color}` : '' }, r.text)] : []));
      };
      paint();
      return el('div', { class: 'fmt-picker' }, now,
        readOnly ? null : el('button', { type: 'button', class: 'small', onclick: () => formatDialog(f, () => { setDirty(true); paint(); draw(); }) }, '✎ עיצוב…'),
        el('p', { class: 'hint' }, 'הערך נשמר כמו שהוא; המבנה קובע רק איך הוא מוצג — על הדף, ב-PDF וב-Word.'));
    }
    const fmtSampleValue = (f, kind) => (f.default && !f.default.startsWith('@')) ? f.default
      : kind === 'date' ? new Date().toISOString().slice(0, 10) : kind === 'text' ? (/0/.test(f.fmt || '') ? '0521234567' : 'ישראל ישראלי') : '-1234.1';

    /** החלון: קטגוריות מימין, אפשרויות, סוג (הקוד), רשימת קודים — ודוגמה חיה למעלה. */
    async function formatDialog(f, done) {
      const kind = fmtKind(f);
      let [cat, o] = fmtGuess(kind, f.fmt || '');
      let code = f.fmt || '';
      const sampleIn = el('input', { value: fmtSampleValue(f, kind), dir: kind === 'text' ? 'auto' : 'ltr', 'aria-label': 'ערך לדוגמה', class: 'fmt-sample-in' });
      const sampleOut = el('div', { class: 'fmt-sample-out', 'aria-live': 'polite' });
      const codeIn = el('input', { dir: 'ltr', maxlength: 80, 'aria-label': 'סוג (קוד המבנה)', value: code });
      const cats = el('div', { class: 'fmt-cats', role: 'radiogroup', 'aria-label': 'קטגוריה' });
      const opts = el('div', { class: 'fmt-opts' });
      const err = el('div');
      const show = () => {
        const r = code ? window.DTFmt.rich({ ...f, fmt: code }, sampleIn.value) : null;
        sampleOut.textContent = r ? r.text : (sampleIn.value || '—');
        sampleOut.style.color = (r && r.color) || '';
      };
      const setCode = (c, fromInput) => { code = c; if (!fromInput) codeIn.value = c; show(); };
      const regen = () => { if (!['general', 'custom', 'date', 'fraction', 'special'].includes(cat)) setCode(fmtCode(cat, o)); };
      const num = (label, key, min, max) => {
        const i = el('input', { type: 'number', min, max, value: o[key] });
        i.addEventListener('input', () => { o[key] = Math.max(min, Math.min(max, Number(i.value) || 0)); regen(); });
        return el('label', { class: 'f' }, el('span', {}, label), i);
      };
      const choice = (label, list, cur, pick) => el('div', { class: 'f' }, el('span', {}, label),
        el('div', { class: 'fmt-list', role: 'listbox' }, list.map(([v, t]) => {
          return el('button', { type: 'button', role: 'option', 'aria-selected': String(cur === v), class: 'fmt-item' + (cur === v ? ' on' : ''),
            onclick: (e) => { pick(v); e.currentTarget.parentNode.querySelectorAll('.fmt-item').forEach(b => { b.classList.toggle('on', b === e.currentTarget); b.setAttribute('aria-selected', String(b === e.currentTarget)); }); } }, t);
        })));
      async function drawOpts() {
        const sampleOf = (c) => window.DTFmt.rich({ ...f, fmt: c }, sampleIn.value)?.text ?? '';
        const parts = [];
        if (cat === 'general') parts.push(el('p', { class: 'hint' }, 'בלי מבנה — הערך מוצג כמו שהוא' + (kind === 'date' ? ' (dd/MM/yyyy).' : '.')));
        if (['number', 'currency', 'accounting', 'percent', 'scientific'].includes(cat)) parts.push(num('ספרות אחרי הנקודה', 'd', 0, 6));
        if (cat === 'number') {
          const sep = el('input', { type: 'checkbox', checked: o.sep });
          sep.addEventListener('change', () => { o.sep = sep.checked; regen(); });
          parts.push(el('label', { class: 'switch' }, sep, el('span', {}, 'מפריד אלפים (,)')));
        }
        if (['currency', 'accounting'].includes(cat)) parts.push(choice('סמל', SYMS, o.sym, (v) => { o.sym = v; regen(); }));
        if (['number', 'currency'].includes(cat)) parts.push(choice('מספרים שליליים', NEG, o.neg, (v) => { o.neg = v; regen(); }));
        if (cat === 'date') parts.push(choice('סוג', window.DTFmt.PRESETS.date.map(([p, n]) => [p, `${window.DTFmt.date(p, kind === 'date' ? sampleIn.value : new Date().toISOString().slice(0, 10))}  ·  ${n}`]), code, (v) => setCode(v)));
        if (cat === 'fraction') parts.push(choice('סוג', FRACTIONS, code, (v) => setCode(v)));
        if (cat === 'special') parts.push(choice('סוג', SPECIAL, code, (v) => setCode(v)));
        if (cat === 'text') parts.push(el('p', { class: 'hint' }, 'הערך מוצג בדיוק כמו שהוקלד (@). אפשר להוסיף טקסט קבוע במירכאות: "מר "@'));
        if (cat === 'custom') {
          const mine = (await loadFmtCodes()).filter(x => x.kind === kind);
          const builtIn = kind === 'date' ? window.DTFmt.PRESETS.date.map(([p]) => p)
            : kind === 'text' ? ['@', '"מר "@', ...SPECIAL.map(([p]) => p)]
            : ['0', '0.00', '#,##0', '#,##0.00', '#,##0;-#,##0', '#,##0;[אדום]-#,##0', '#,##0.00;-#,##0.00', '#,##0.00;[אדום]-#,##0.00',
               '₪ #,##0;-₪ #,##0', '₪ #,##0.00;[אדום]-₪ #,##0.00', '#,##0 ש״ח', '0%', '0.00%', '0.00E+00', '# ?/?', ...SPECIAL.map(([p]) => p)];
          const list = el('div', { class: 'fmt-codes', role: 'listbox', 'aria-label': 'קודים' });
          const paintList = () => list.replaceChildren(...[...builtIn.map(c => ({ code: c })), ...mine].map(x => el('div', { class: 'fmt-code-row' + (x.code === code ? ' on' : '') },
            el('button', { type: 'button', class: 'fmt-code', dir: 'ltr', onclick: () => { setCode(x.code); paintList(); } }, x.code),
            el('small', { class: 'muted', dir: 'auto' }, sampleOf(x.code)),
            x.id ? el('button', { type: 'button', class: 'link small danger-text', title: 'מחיקה מהרשימה', onclick: async () => {
              try { await api('fmt_code_delete', { id: x.id }); mine.splice(mine.indexOf(x), 1); myFmtCodes = myFmtCodes.filter(y => y !== x); paintList(); }
              catch (e) { err.replaceChildren(notice(e.message, 'bad')); }
            } }, 'מחק') : null)));
          paintList();
          parts.push(el('div', { class: 'f' }, el('span', {}, 'קודים — לחיצה בוחרת; את הקוד אפשר לערוך בשדה "סוג"'), list));
          parts.push(el('details', { class: 'fmt-help' }, el('summary', {}, 'הסימנים'),
            el('dl', {}, (kind === 'date'
              ? [['d / dd', 'יום'], ['M / MM / MMMM', 'חודש / שם החודש'], ['yy / yyyy', 'שנה'], ['dddd', 'יום בשבוע'], ['hd / hMMMM / hyy', 'תאריך עברי'], ["'text'", 'אנגלית — בגרש']]
              : [['0 / #', 'ספרה (חובה / רק אם יש)'], [',', 'מפריד אלפים'], ['.00', 'ספרות אחרי הנקודה'], ['%', 'אחוזים'], [';', 'חיובי;שלילי;אפס'],
                 ['[אדום]', 'צבע: אדום, כחול, ירוק, שחור'], ['"טקסט"', 'טקסט קבוע'], ['E+00', 'מדעי'], ['?/?', 'שבר'], ['@', 'הטקסט עצמו'], ['000-0000000', 'מסכת ספרות']])
              .flatMap(([k, v]) => [el('dt', { dir: 'ltr' }, k), el('dd', {}, v)]))));
        }
        opts.replaceChildren(...parts);
      }
      const drawCats = () => cats.replaceChildren(...CATS[kind].map(([k, t]) => el('button', { type: 'button', role: 'radio', 'aria-checked': String(cat === k),
        class: 'fmt-cat' + (cat === k ? ' on' : ''), onclick: () => {
          cat = k;
          if (k === 'general') setCode('');
          else if (k === 'date' && !window.DTFmt.PRESETS.date.some(([p]) => p === code)) setCode(window.DTFmt.PRESETS.date[0][0]);
          else if (k === 'fraction' && !FRACTIONS.some(([p]) => p === code)) setCode(FRACTIONS[0][0]);
          else if (k === 'special' && !SPECIAL.some(([p]) => p === code)) setCode(SPECIAL[0][0]);
          else regen();
          drawCats(); drawOpts();
        } }, t)));
      sampleIn.addEventListener('input', () => { show(); if (cat === 'custom' || cat === 'date') drawOpts(); });
      codeIn.addEventListener('input', () => { setCode(codeIn.value.trim(), true); if (cat !== 'custom') { cat = 'custom'; drawCats(); drawOpts(); } });
      const ok = el('button', { type: 'button', class: 'primary small', onclick: async () => {
        f.fmt = code;
        // קוד שאינו מהרשימה — נשמר ל"מותאם אישית" שלי, לשימוש חוזר בכל התבניות
        if (code && cat === 'custom' && fmtGuess(kind, code)[0] === 'custom' && !(await loadFmtCodes()).some(x => x.kind === kind && x.code === code)) {
          api('fmt_code_save', { kind, code }).then(() => { myFmtCodes = null; }).catch(() => {});
        }
        dlg.close(); done();
      } }, 'אישור');
      const dlg = window.DT.sheet('עיצוב: ' + f.label, el('div', { class: 'fmt-dialog' },
        el('div', { class: 'fmt-sample' }, el('span', { class: 'muted' }, 'דוגמה'), sampleOut, el('label', { class: 'f' }, el('span', {}, 'ערך לבדיקה'), sampleIn)),
        el('div', { class: 'fmt-main' }, cats, el('div', { class: 'fmt-side' }, opts, el('label', { class: 'f' }, el('span', {}, 'סוג'), codeIn), err))), { action: ok });
      dlg.classList.add('fmt-dlg');
      drawCats(); await drawOpts(); show();
    }

    /** הגופנים שהעליתי — רשימה, העלאה, מחיקה. */
    function fontsPanel() {
      const box = el('div', { class: 'numbering fonts-panel' }, el('h2', {}, '🔤 הגופנים שלי'));
      const list = el('ul', { class: 'list card-list' });
      const file = el('input', { type: 'file', accept: '.ttf,.otf', class: 'visually-hidden' });
      const render = async () => {
        const fonts = await loadMyFonts();
        list.replaceChildren(...fonts.map(x => el('li', {},
          el('span', { class: 'use-row' }, el('span', { class: 'use-ic', 'aria-hidden': 'true' }, 'Aa'),
            el('span', { class: 'use-tx' }, el('b', { style: `font-family:${window.DTFonts.family(x.id)}` }, x.name),
              el('small', {}, `${x.format.toUpperCase()} · ${Math.max(1, Math.round(x.size / 1024))}KB · דוגמה: אבגד 123`))),
          readOnly ? null : el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'מחיקת הגופן', title: 'מחיקה', onclick: async () => {
            if (!confirm(`למחוק את הגופן "${x.name}"? שדות שכבר משתמשים בו ימשיכו להציג אותו.`)) return;
            try { await api('font_delete', { id: x.id }); myFonts = null; render(); } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
          } }, '🗑️'))));
        for (const x of fonts) window.DTFonts.load(x.id, { tpl: id });
        if (!fonts.length) list.replaceChildren(el('li', {}, el('p', { class: 'empty' }, 'עוד אין גופנים. מעלים קובץ TTF או OTF.')));
      };
      file.addEventListener('change', async () => {
        if (!file.files[0]) return;
        const fd = new FormData(); fd.append('file', file.files[0]);
        try { const r = (await api('font_upload', fd)).font; await window.DTFonts.add(r.id, await file.files[0].arrayBuffer()); myFonts = null; render(); }
        catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
        file.value = '';
      });
      render();
      box.append(el('p', { class: 'hint' }, 'גופן שמעלים משמש גם על המסך וגם ב-PDF שיוצא. בוחרים אותו בהגדרות של כל שדה ("על הדף").'),
        list, readOnly ? null : el('label', { class: 'btn small' }, file, '+ העלאת גופן'));
      return box;
    }

    /** בחירת גופן: ברירת המחדל, הגופנים שבתבנית ושלי, והעלאה ישר מכאן. */
    function fontPicker(f, after) {
      const sel = el('select', { 'aria-label': 'גופן' });
      const file = el('input', { type: 'file', accept: '.ttf,.otf,font/ttf,font/otf', class: 'visually-hidden' });
      const fill = (fonts) => {
        const ids = new Set([...fonts.map(x => x.id), ...fontNames.keys()]);
        // replaceChildren לא פורש מערכים (el כן) — פורשים כאן
        sel.replaceChildren(...[el('option', { value: '0' }, 'Alef — ברירת מחדל'),
          ...[...ids].map(i => el('option', { value: i, selected: f.font === i }, fontNames.get(i) || 'גופן ' + i)),
          readOnly ? null : el('option', { value: 'upload' }, '+ העלאת גופן (TTF / OTF)…')].filter(Boolean));
        sel.value = String(f.font || 0);
      };
      const apply = () => { setDirty(true); window.DTFonts.load(f.font, { tpl: id }).then(() => { draw(); after && after(); }); };
      sel.addEventListener('change', () => {
        if (sel.value === 'upload') { sel.value = String(f.font || 0); file.click(); return; }
        f.font = Number(sel.value) || 0;
        apply();
      });
      file.addEventListener('change', async () => {
        const x = file.files[0];
        if (!x) return;
        const fd = new FormData();
        fd.append('file', x);
        try {
          const r = (await api('font_upload', fd)).font;
          fontNames.set(r.id, r.name);
          (await loadMyFonts()).push(r);
          await window.DTFonts.add(r.id, await x.arrayBuffer());
          f.font = r.id;
          fill(await loadMyFonts());
          apply();
        } catch (e) { sel.after(notice(e.message, 'bad')); }
        file.value = '';
      });
      fill([]);
      loadMyFonts().then(fill);
      return el('label', { class: 'f' }, el('span', {}, 'גופן'), sel, file);
    }

    /** מיקום הטקסט בשדה: רשת 3×3 — ימין/מרכז/שמאל × למעלה/אמצע/למטה. */
    // צבע הטקסט: שחור (ברירת המחדל) · כמה מוכנים · כל צבע אחר. ריק = שחור
    const COLORS = [['', 'שחור', '#000000'], ['#0b1f66', 'כחול כהה'], ['#1d4ed8', 'כחול'], ['#b91c1c', 'אדום'], ['#15803d', 'ירוק']];
    function colorPicker(f, opts = {}) {
      const custom = el('input', { type: 'color', value: f.color || '#000000', 'aria-label': 'צבע אחר' });
      const row = el('div', { class: 'color-row', role: 'group', 'aria-label': 'צבע הטקסט' });
      const all = el('button', { type: 'button', class: 'link small', onclick: () => {
        schema.fields.forEach(x => { x.color = f.color || ''; });
        setDirty(true); draw(); all.textContent = '✓ הוחל על כל השדות';
      } }, 'להחיל על כל השדות');
      const set = (v) => { f.color = v; custom.value = v || '#000000'; all.textContent = 'להחיל על כל השדות'; setDirty(true); draw(); paint(); };
      const paint = () => {
        const cur = f.color || '';
        const own = cur !== '' && !COLORS.some(([v]) => v === cur);
        row.replaceChildren(...COLORS.map(([v, label, sw]) => el('button', { type: 'button', class: 'swatch' + (cur === v ? ' on' : ''),
          title: label, 'aria-label': label, 'aria-pressed': String(cur === v), style: `--c:${sw || v}`, onclick: () => set(v) })),
          el('label', { class: 'swatch custom' + (own ? ' on' : ''), title: 'צבע אחר', style: own ? `--c:${cur}` : '' }, custom));
      };
      custom.addEventListener('input', () => set(custom.value.toLowerCase() === '#000000' ? '' : custom.value.toLowerCase()));
      paint();
      return el('div', { class: 'f color-pick' }, el('span', {}, 'צבע הטקסט'), row, opts.noAll ? null : all);
    }

    // ── סגנונות (כמו בוורד): שם + גופן, גודל, צבע ומיקום. שדה מקושר מקבל אותם מהסגנון ──
    const STYLE_PROPS = ['font', 'fontSize', 'color', 'align', 'valign'];
    const styleOf = (f) => (f.style && schema.styles.find(x => x.id === f.style)) || null;
    /** מעתיק מכל סגנון לשדות שלו. סגנון שנמחק — הקישור נופל, המראה נשאר. */
    function applyStyles() {
      for (const f of schema.fields) {
        const st = styleOf(f);
        if (f.style && !st) f.style = '';
        if (st) STYLE_PROPS.forEach(k => { f[k] = st[k]; });
      }
    }
    function newStyle(from, name) {
      const st = { id: 's' + Date.now().toString(36), name: name.slice(0, 40), font: from.font || 0, fontSize: from.fontSize || 11,
        color: from.color || '', align: from.align || 'right', valign: from.valign || 'middle' };
      schema.styles.push(st);
      return st;
    }
    /** בגיליון השדה: איזה סגנון — או בלי, או סגנון חדש מהשדה הזה. */
    function stylePicker(f, after) {
      const sel = el('select', { 'aria-label': 'סגנון', disabled: readOnly },
        el('option', { value: '' }, '— בלי סגנון —'),
        schema.styles.map(st => el('option', { value: st.id }, st.name)),
        readOnly ? null : el('option', { value: 'new' }, '+ סגנון חדש מהשדה הזה…'),
        readOnly || !schema.styles.length ? null : el('option', { value: 'manage' }, '✎ עריכת הסגנונות…'));
      sel.value = f.style || '';
      sel.addEventListener('change', () => {
        const v = sel.value;
        if (v === 'manage') { sel.value = f.style || ''; stylesSheet(after); return; }
        if (v === 'new') {
          const name = (prompt('שם הסגנון (למשל: כותרת, טקסט רגיל, הערה)', '') || '').trim();
          if (!name) { sel.value = f.style || ''; return; }
          f.style = newStyle(f, name).id;
        } else f.style = v;
        applyStyles(); setDirty(true); draw(); after();
      });
      return el('label', { class: 'f' }, el('span', {}, '🎨 סגנון'), sel);
    }
    /** תצוגה של הסגנון: אותו גופן, גודל יחסי, צבע ויישור. */
    function styleSample(st) {
      const n = el('div', { class: 'style-sample' }, st.name || 'אבג', ' · 123');
      Object.assign(n.style, { fontFamily: st.font ? window.DTFonts.family(st.font) : 'Alef, Arial, sans-serif',
        fontSize: Math.round(Math.min(40, st.fontSize * 1.3)) + 'px', color: st.color || '#000',
        textAlign: st.align === 'center' ? 'center' : st.align === 'left' ? 'left' : 'right' });
      return n;
    }
    /** ניהול הסגנונות: שם, גופן, גודל, מיקום, צבע — ומחיקה. כל שינוי חל מיד על השדות של הסגנון. */
    function stylesPanel() {
      const box = el('div', { class: 'styles-panel' });
      const paint = () => {
        const cards = schema.styles.map(st => {
          const used = schema.fields.filter(f => f.style === st.id).length;
          const sample = el('div');
          const sync = () => { applyStyles(); setDirty(true); draw(); sample.replaceChildren(styleSample(st)); };
          const name = el('input', { value: st.name, maxlength: 40, 'aria-label': 'שם הסגנון', disabled: readOnly });
          name.addEventListener('input', () => { st.name = name.value; sync(); });
          const size = el('input', { type: 'number', min: 6, max: 72, value: st.fontSize, disabled: readOnly });
          size.addEventListener('input', () => { st.fontSize = Math.max(6, Math.min(72, Number(size.value) || 12)); sync(); });
          const del = readOnly ? null : el('button', { type: 'button', class: 'link small danger-text', onclick: () => {
            if (used && !confirm(`ב-${used} שדות. הם ישמרו את המראה הנוכחי, בלי קישור לסגנון. למחוק?`)) return;
            schema.styles = schema.styles.filter(x => x !== st);
            applyStyles(); setDirty(true); draw(); paint();
          } }, 'מחיקה');
          sample.replaceChildren(styleSample(st));
          const card = el('div', { class: 'style-card' },
            el('div', { class: 'style-head' }, name, el('small', { class: 'muted' }, used === 1 ? 'שדה אחד' : used ? `${used} שדות` : 'לא בשימוש'), del),
            sample,
            el('div', { class: 'fields two' }, fontPicker(st, sync), el('label', { class: 'f' }, el('span', {}, 'גודל גופן'), size)),
            positionGrid(st), colorPicker(st, { noAll: true }));
          // הבוררים (מיקום, צבע) משנים את st ישירות — אחרי כל לחיצה מעדכנים את השדות והתצוגה
          card.addEventListener('click', () => setTimeout(sync));
          card.addEventListener('input', () => setTimeout(sync));
          return card;
        });
        box.replaceChildren(...cards,
          schema.styles.length ? el('span') : el('p', { class: 'hint' }, 'עוד אין סגנונות. סגנון שומר גופן, גודל, צבע ומיקום בשם אחד — ומחילים אותו על שדה בבחירה. שינוי בסגנון משנה את כל השדות שלו.'),
          readOnly ? el('span') : el('button', { type: 'button', class: 'small', onclick: () => {
            const nm = (prompt('שם הסגנון', 'סגנון ' + (schema.styles.length + 1)) || '').trim();
            if (!nm) return;
            newStyle({}, nm); setDirty(true); paint();
          } }, '+ סגנון חדש'));
      };
      paint();
      return box;
    }
    function stylesSheet(after) {
      window.DT.sheet('🎨 סגנונות', el('div', { class: 'field-sheet' }, stylesPanel()),
        { onClose: () => { applyStyles(); draw(); after && after(); } });
    }

    function positionGrid(f) {
      const H = [['right', 'ימין'], ['center', 'מרכז'], ['left', 'שמאל']];
      const V = [['top', 'למעלה'], ['middle', 'באמצע'], ['bottom', 'למטה']];
      const box = el('div', { class: 'pos-grid', role: 'radiogroup', 'aria-label': 'מיקום הטקסט בשדה' });
      const now = el('small', { class: 'muted' });
      const draw_ = () => {
        box.replaceChildren(...V.flatMap(([v, vl]) => H.map(([h, hl]) => {
          const on = (f.align || 'right') === h && (f.valign || 'middle') === v;
          return el('button', { type: 'button', role: 'radio', 'aria-checked': String(on), class: 'pos-cell' + (on ? ' on' : ''),
            'aria-label': `${vl}, ${hl}`, title: `${vl}, ${hl}`, disabled: readOnly,
            onclick: () => { f.align = h; f.valign = v; setDirty(true); draw(); draw_(); } },
            el('span', { class: `pos-dot va-${v} ha-${h}` }, el('i')));
        })));
        const vl = V.find(x => x[0] === (f.valign || 'middle'))[1], hl = H.find(x => x[0] === (f.align || 'right'))[1];
        now.textContent = `${vl}, ${hl}`;
      };
      draw_();
      return el('div', { class: 'f' }, el('span', {}, 'מיקום הטקסט בשדה'), el('div', { class: 'pos-row' }, box, now));
    }

    /** הגדרות שדה אחד — בגיליון: מה, מי ממלא, הרשאות, ערך, מיקום, מתקדם. */
    function fieldSheet(f) {
      const body = el('div', { class: 'field-sheet' });
      const done = el('button', { type: 'button', class: 'primary small', onclick: () => dlg.close() }, 'סיום');
      const dlg = window.DT.sheet('הגדרות שדה', body, { action: done, onClose: () => { draw(); renderPanel(); if (isAuthored) renderBlocks(); } });
      dlg.classList.add('field-dlg');
      const relabel = () => { const lbl = pagesBox.querySelector('.field.selected .field-label'); if (lbl) lbl.textContent = iconOf(f) + ' ' + f.label; };
      const input = (label, prop, attrs = {}) => {
        const i = el('input', { value: f[prop] ?? '', ...attrs });
        i.addEventListener('input', () => {
          f[prop] = attrs.type === 'number' ? Number(i.value) : i.value;
          setDirty(true);
          if (prop === 'label') relabel();
        });
        return el('label', { class: 'f' }, el('span', {}, label), i);
      };
      const choose = (label, prop, options, after) => {
        const s = el('select', {}, Object.entries(options).map(([v, t]) => el('option', { value: v, selected: (f[prop] ?? '') === v }, t)));
        s.addEventListener('change', () => { f[prop] = s.value; setDirty(true); draw(); after && after(); });
        return el('label', { class: 'f' }, el('span', {}, label), s);
      };
      const toggle = (label, hint, get, set) => {
        const c = el('input', { type: 'checkbox', checked: get() });
        c.addEventListener('change', () => { set(c.checked); setDirty(true); draw(); });
        return el('label', { class: 'switch' }, c, el('span', {}, label, hint ? el('small', { class: 'muted' }, hint) : null));
      };
      const group = (title, ...kids) => el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, title), ...kids);

      function render() {
        const auto = isAuto(f);
        const textual = ['text', 'textarea', 'number', 'date', 'calc', 'system', 'select', 'table'].includes(f.type);   // טבלה: גופן, גודל, צבע, ומה קורה כשהשורות לא נכנסות
        const onPage = !isWord && !isAuthored;
        // סוג: טבלה נשארת טבלה. מעבר ל"מתמלא לבד" — בוחרים מקור.
        const types = f.type === 'table' ? ['table'] : [...PLACEABLE, 'system'];
        const typeSel = el('select', { disabled: f.type === 'table' },
          types.map(k => el('option', { value: k, selected: f.type === k }, TYPES[k].icon + ' ' + TYPES[k].label)));
        typeSel.addEventListener('change', () => {
          f.type = typeSel.value;
          if (f.type === 'calc') { f.formula ??= ''; f.format ??= 'number'; }
          if (f.type === 'system') f.source ??= 'number';
          if (f.type === 'select') f.options ??= [];
          if (isAuto(f)) { f.required = false; f.contactField = ''; }
          setDirty(true); relabel(); draw(); render();
        });
        // מי ממלא: כפתורי בחירה — אני / כל ממלא בקישור / + ממלא חדש
        // מי ממלא — אפשר לבחור כמה: כל אחד מהם יכול למלא את השדה
        const mine = rolesOfF(f);
        const roleSeg = el('div', { class: 'role-seg', role: 'group', 'aria-label': 'מי ממלא — אפשר כמה' },
          schema.roles.map((r, i) => el('button', { type: 'button', 'aria-pressed': String(mine.includes(r.key)),
            class: (mine.includes(r.key) ? 'on ' : '') + 'role-opt role-' + (i % 6), 'data-role': r.key,
            onclick: () => {
              const next = mine.includes(r.key) ? mine.filter(k => k !== r.key) : [...mine, r.key];
              if (!next.length) return;   // לפחות ממלא אחד
              setRoles(f, next); setDirty(true); draw(); render();
            } },
          el('span', { class: 'role-dot role-' + (i % 6) }), r.key === 'owner' ? 'אני' : r.label + ' (קישור)', mine.includes(r.key) ? ' ✓' : '')),
          el('button', { type: 'button', class: 'role-opt add', onclick: () => {
            const name = (prompt('שם הממלא (למשל: לקוח, ספק, עד)', schema.roles.length === 1 ? 'לקוח' : '') || '').trim();
            if (!name) return;
            const r = { key: 'role_' + Math.random().toString(36).slice(2, 7), label: name.slice(0, 60), auth: 'none' };
            schema.roles.push(r);
            setRoles(f, [r.key]); // ממלא חדש מקבל את השדה; לשיתוף — לוחצים גם על ממלא נוסף
            setDirty(true); draw(); render();
          } }, '+ ממלא חדש'));
        const who = auto
          ? (f.type === 'system'
            ? el('div', {}, choose('מה נכנס לשדה', 'source', Object.fromEntries(Object.entries(SYSTEM).map(([k, [l, ic]]) => [k, ic + ' ' + l])), () => {
                if (Object.values(SYSTEM).some(([l]) => l === f.label) || !f.label) { f.label = SYSTEM[f.source][0]; relabel(); render(); }
              }),
              el('p', { class: 'hint' }, {
                number: 'המספר הרץ של המסמך — לפי "מספור מסמכים" בלשונית ההגדרות. נקבע בהנפקה.',
                issued: 'התאריך שבו המסמך הונפק.', owner: 'השם של מי שהנפיק את המסמך.',
                title: 'הכותרת שנותנים למסמך בהנפקה.', completed: 'התאריך שבו המסמך הושלם — ריק עד אז.',
              }[f.source || 'number']))
            : calcPanel(f))
          : el('div', {}, roleSeg, el('p', { class: 'hint' }, rolesOfF(f).length > 1
              ? `🔀 ${rolesNames(f)} — כל אחד מהם יכול למלא, ומספיק שאחד מילא. בשדה חובה: ממלא שמסיים לא נחסם כל עוד אחר עוד יכול להשלים; האחרון שנשאר — כן.`
              : (f.role || 'owner') === 'owner'
              ? 'אתם ממלאים את השדה — בהנפקה או בעריכת המסמך. אפשר לסמן כמה ממלאים: "או אני או הלקוח".'
              : `${roleLabel(f.role)} ממלא את השדה דרך הקישור האישי שלו. אפשר לסמן כמה ממלאים: "או פלוני או אלמוני".`));

        body.replaceChildren(...[
          group('השדה',
            onPage ? toggle('🔒 נעול במקומו', 'לא זז ולא משנה גודל בטעות. כדי להזיז — משחררים כאן', () => !!f.locked, v => { f.locked = v; }) : null,
            input('תווית — מה רואים ליד השדה', 'label', { maxlength: 120 }),
            el('label', { class: 'f' }, el('span', {}, 'סוג'), typeSel),
            auto ? null : input('שורת עזרה (לא חובה)', 'help', { maxlength: 300 }),
            f.placeholder ? el('p', { class: 'hint' }, 'בקובץ: ', el('code', {}, '{{' + f.placeholder + '}}')) : null,
            f.pdfName ? el('p', { class: 'hint' }, 'שדה בטופס המקורי: ', el('code', { dir: 'ltr' }, f.pdfName)) : null),
          group(auto ? 'מאיפה הערך' : 'מי ממלא', who),
          group('הרשאות ותצוגה',
            auto ? null : toggle('שדה חובה', 'אי אפשר לסיים בלעדיו', () => !!f.required, v => { f.required = v; }),
            hiddenCtl(f, toggle),
            toggle('מודפס במסמך', 'כבוי — נשמר ומיוצא לאקסל, אבל לא מופיע בקובץ המודפס', () => f.print !== false, v => { f.print = v; }),
            el('div', { class: 'f' }, el('span', {}, 'מתי השדה מוצג'), condEditor(f, f, draw))),
          window.DTFmt.isDate(f) || window.DTFmt.isNumber(f) || f.type === 'text' ? group('מבנה התצוגה', fmtPicker(f)) : null,
          auto || f.type === 'signature' ? null : group('ערך',
            ['select', 'text'].includes(f.type) ? listPicker(f, render) : null,
            f.type === 'text' && f.list ? el('p', { class: 'hint' }, 'בשדה טקסט — הרשימה היא הצעות; אפשר לכתוב גם ערך אחר.') : null,
            f.type === 'select' && !f.list ? selectPanel(f) : null,
            f.type === 'file' ? filePanel(f)
              : f.type === 'table' ? tablePanel(f)
              : f.type === 'date' ? dateDefault(f)
              : input('ערך ברירת מחדל', 'default', { maxlength: 1000 }),
            !['file', 'checkbox', 'table', 'select'].includes(f.type) ? choose('מתמלא מאיש קשר', 'contactField', {
              '': '— לא —', name: 'שם', id_number: 'ת"ז / ח"פ', phone: 'טלפון', email: 'מייל', address: 'כתובת', notes: 'הערות',
            }) : null),
          onPage ? group('על הדף',
            textual || f.type === 'checkbox' ? stylePicker(f, render) : null,
            styleOf(f) ? el('div', { class: 'style-linked' }, styleSample(styleOf(f)),
              el('p', { class: 'hint' }, `הגופן, הגודל, הצבע והמיקום — מהסגנון "${styleOf(f).name}". שינוי בסגנון משנה את כל השדות שלו; לשינוי רק כאן — "בלי סגנון".`),
              readOnly ? null : el('button', { type: 'button', class: 'link small', onclick: () => stylesSheet(render) }, '✎ עריכת הסגנון')) : null,
            textual && !styleOf(f) ? el('div', { class: 'fields two' },
              fontPicker(f, relabel),
              input('גודל גופן', 'fontSize', { type: 'number', min: 6, max: 72 })) : null,
            textual && !styleOf(f) ? positionGrid(f) : null,
            (textual || f.type === 'checkbox') && !styleOf(f) ? colorPicker(f) : null,
            textual ? choose('כשהטקסט ארוך מהשדה', 'overflow', OVERFLOW) : null,
            el('p', { class: 'hint' }, `דף ${f.page + 1}. ` + (f.locked ? 'נעול — אי אפשר לגרור.' : 'הזזה וגודל — בגרירה על הדף.'))) : null,
          el('details', { class: 'fs-group adv' }, el('summary', {}, 'מתקדם'),
            input('מפתח פנימי (לייצוא ולאקסל)', 'key', { dir: 'ltr', maxlength: 60, pattern: '[a-z0-9_]+' }),
            readOnly ? null : el('div', { class: 'ops' },
              f.placeholder ? null : el('button', { type: 'button', class: 'small', onclick: () => duplicate(f) }, 'שכפול'),
              el('button', { type: 'button', class: 'small danger', onclick: () => removeField(f) }, 'מחיקת השדה')))].filter(Boolean));
        if (readOnly) for (const n of body.querySelectorAll('input, select, textarea, button')) n.disabled = true;
      }
      render();
      return dlg;
    }

    // משתתפים: מי ממלא איזה שדה, ובאיזה סדר. "אני" תמיד ראשון ואינו נמחק.
    function rolesPanel() {
      const list = el('ol', { class: 'roles' }, schema.roles.map((r, i) => {
        const dot = el('span', { class: 'role-dot role-' + (i % 6) });
        if (r.key === 'owner') return el('li', {}, dot, el('strong', {}, r.label), ' ', el('span', { class: 'muted small' }, '— ממלא ראשון'));
        const label = el('input', { value: r.label, maxlength: 60, 'aria-label': 'שם הממלא', placeholder: 'שם הממלא (למשל: לקוח)' });
        label.addEventListener('input', () => { r.label = label.value; setDirty(true); });
        const auth = el('select', { 'aria-label': 'אימות' },
          el('option', { value: 'none', selected: r.auth !== 'email' }, 'בלי אימות'),
          el('option', { value: 'email', selected: r.auth === 'email' }, 'קוד במייל'));
        auth.addEventListener('change', () => { r.auth = auth.value; setDirty(true); });
        const del = el('button', { class: 'link small', title: 'הסרה', onclick: () => {
          const used = schema.fields.filter(f => rolesOfF(f).includes(r.key)).length;
          if (used && !confirm(`ל"${r.label}" משויכים ${used} שדות. שדה שרק הוא ממלא — יעבור אליך. להסיר?`)) return;
          schema.fields.forEach(f => {
            if (!rolesOfF(f).includes(r.key)) return;
            const rest = rolesOfF(f).filter(k => k !== r.key);
            setRoles(f, rest.length ? rest : ['owner']);
          });
          schema.roles = schema.roles.filter(x => x !== r);
          schema.fields.forEach(fixHidden);   // בלי הממלא הזה ייתכן שאין כבר ממי להסתיר
          hiddenDropped = null;
          setDirty(true); draw(); renderPanel();
        } }, '✕');
        // שורה ראשונה: צבע · שם הממלא · הסרה; מתחת — אימות. כך השם תמיד רחב וקריא
        // איש קשר קבוע (לא חובה): כל מסמך מהתבנית מקשר אליו את הממלא מיד
        const fixed = el('span', { class: 'muted small' }, '…');
        window.DTCatalog.contactPicker(r.contact || null, (c) => { r.contact = c ? c.id : 0; setDirty(true); },
          { empty: '— בוחרים בכל מסמך —', disabled: readOnly })
          .then(sel => fixed.replaceWith(sel)).catch(() => fixed.replaceWith(el('span', { class: 'muted small' }, '—')));
        return el('li', { class: 'role-row' }, dot, label, del,
          el('label', { class: 'role-auth' }, el('span', {}, 'כניסה:'), auth),
          el('label', { class: 'role-auth' }, el('span', {}, '👤 איש קשר:'), fixed));
      }));
      const seq = el('input', { type: 'checkbox', checked: schema.sequential });
      seq.addEventListener('change', () => { schema.sequential = seq.checked; setDirty(true); });
      return el('div', { class: 'numbering' },
        el('h2', {}, 'מי ממלא את המסמך'),
        el('p', { class: 'hint' }, '"אני" — אתם, בהנפקה. כל ממלא נוסף מקבל קישור אישי, וממלא רק את השדות שלו. משייכים שדה בהגדרות השדה ("מי ממלא"). כל ממלא הוא איש קשר: בוחרים אותו בכל מסמך — או קובעים כאן איש קשר קבוע.'),
        list,
        el('button', { class: 'small', onclick: () => {
          const n = schema.roles.length;
          schema.roles.push({ key: 'role_' + Math.random().toString(36).slice(2, 7), label: n === 1 ? 'לקוח' : 'משתתף ' + n, auth: 'none' });
          setDirty(true); renderPanel();
        } }, '+ ממלא בקישור'),
        schema.roles.length > 1 ? el('label', { class: 'inline small' }, seq, ' לפי הסדר — כל אחד מקבל את הקישור רק אחרי שהקודם סיים') : null,
        schema.roles.length > 1 ? approvalPanel() : null);
    }

    /** מסלול אישור: הממלאים מסיימים → ממתין לאישור שלי → אושר (או הוחזר לתיקון) → הממלא מוריד. */
    function approvalPanel() {
      const sw = (prop, label, hint) => {
        const c = el('input', { type: 'checkbox', checked: !!schema[prop], disabled: readOnly });
        c.addEventListener('change', () => { schema[prop] = c.checked; setDirty(true); });
        return el('div', {}, el('label', { class: 'switch' }, c, el('span', {}, label)), el('p', { class: 'small muted' }, hint));
      };
      return el('div', { class: 'approval-set' },
        el('h3', {}, 'אישור והורדה'),
        sw('approval', 'ממתין לאישור שלי', 'כשהממלאים סיימו, המסמך לא ננעל לבד: מקבלים מייל "ממתין לאישורך", ובמסמך — "✓ אישור ונעילה" או "↩ החזרה לתיקון" עם הערה.'),
        sw('fill_download', 'הממלא יכול להוריד את המסמך', 'אחרי שהמסמך הושלם (או אושר) — הממלא מקבל במייל קישור, ומוריד ממנו את המסמך. שדה שמוסתר ממנו — לא יופיע אצלו.'));
    }

    // מספור המסמכים שיונפקו מהתבנית.
    const numbering = Object.assign({ scheme: 'prefix_year_seq', prefix: '', digits: 4, pattern: '{prefix}-{yyyy}-{seq}' },
      tpl.numbering || {});
    let numberingDirty = false;
    const SCHEMES = {
      prefix_year_seq: 'קידומת-שנה-רץ', seq: 'מספר רץ', year_month_seq: 'שנה/חודש-רץ',
      yearly: 'שנה-רץ, מתאפס כל שנה', pattern: 'תבנית חופשית', none: 'בלי מספור',
    };
    function numberingPreview() {
      const y = new Date().getFullYear(), m = String(new Date().getMonth() + 1).padStart(2, '0');
      const seq = '1'.padStart(numbering.digits, '0');
      const j = (...p) => p.filter(Boolean).join('-');
      return {
        prefix_year_seq: j(numbering.prefix, y, seq), seq: j(numbering.prefix, seq),
        year_month_seq: j(numbering.prefix, `${y}/${m}`) + '-001', yearly: j(numbering.prefix, y, seq),
        pattern: numbering.pattern.replace('{prefix}', numbering.prefix).replace('{yyyy}', y).replace('{mm}', m).replace('{seq}', seq),
        none: '—',
      }[numbering.scheme];
    }
    /** ברירת מחדל לתאריך: בלי / היום / היום ± ימים / תאריך קבוע. נשמר כ-"@today", "@today+30" או "2026-10-15". */
    function dateDefault(f) {
      const m = /^@today(?:([+-])(\d+))?$/.exec(f.default || '');
      let kind = !f.default ? '' : m ? (m[1] ? 'rel' : 'today') : 'fixed';
      const days = el('input', { type: 'number', class: 'days', min: -3650, max: 3650, value: m && m[1] ? (m[1] === '-' ? -m[2] : m[2]) : 30, 'aria-label': 'ימים מההנפקה' });
      const fixed = el('input', { type: 'date', value: kind === 'fixed' ? f.default : '', 'aria-label': 'תאריך קבוע' });
      const sel = el('select', { 'aria-label': 'ערך ברירת מחדל' }, [['', '— בלי —'], ['today', 'היום (יום ההנפקה)'], ['rel', 'היום + ימים'], ['fixed', 'תאריך קבוע']]
        .map(([v, t]) => el('option', { value: v, selected: kind === v }, t)));
      const extra = el('span');
      const sync = () => {
        const n = Math.trunc(Number(days.value) || 0);
        f.default = kind === 'today' ? '@today' : kind === 'rel' ? (n ? '@today' + (n > 0 ? '+' : '-') + Math.abs(n) : '@today') : kind === 'fixed' ? fixed.value : '';
        extra.replaceChildren(kind === 'rel' ? el('label', { class: 'f' }, el('span', {}, 'ימים'), days) : kind === 'fixed' ? el('label', { class: 'f' }, el('span', {}, 'תאריך'), fixed) : '');
        setDirty(true);
      };
      sel.addEventListener('change', () => { kind = sel.value; sync(); });
      days.addEventListener('input', sync); fixed.addEventListener('input', sync);
      extra.replaceChildren(kind === 'rel' ? el('label', { class: 'f' }, el('span', {}, 'ימים'), days) : kind === 'fixed' ? el('label', { class: 'f' }, el('span', {}, 'תאריך'), fixed) : '');
      return el('div', {}, el('div', { class: 'date-default' }, el('label', { class: 'f' }, el('span', {}, 'ערך ברירת מחדל'), sel), extra),
        el('p', { class: 'hint' }, '"היום" — התאריך ביום שבו מנפיקים את המסמך (שעון ישראל). אפשר גם "היום + 30" למועד אחרון, או מספר שלילי לתאריך שעבר.'));
    }

    /** ה-PDF שיוצא: נקי (רק המסמך), או עם דף אישור בסוף. */
    function outputPanel() {
      const cert = el('input', { type: 'checkbox', checked: !!schema.certificate, disabled: readOnly });
      cert.addEventListener('change', () => { schema.certificate = cert.checked; setDirty(true); });
      return el('div', { class: 'numbering' },
        el('h2', {}, 'ה-PDF שיוצא'),
        el('label', { class: 'switch' }, cert, el('span', {}, 'לצרף דף אישור בסוף המסמך שהושלם')),
        el('p', { class: 'small' }, 'דף האישור: טביעת אצבע של הערכים ויומן הפעולות — הוכחה שהמסמך לא שונה אחרי שננעל. ' +
          'כבוי — ה-PDF נקי, ודף האישור יורד בנפרד בכפתור 🧾 במסמך שהושלם. מתאים להפעיל בחוזה; לא במסמך שנשלח הלאה, כמו אישור.'));
    }

    function numberingPanel() {
      const preview = el('strong', { dir: 'ltr' }, numberingPreview());
      const upd = (k, v) => { numbering[k] = v; numberingDirty = true; setDirty(true); preview.textContent = numberingPreview(); };
      const sel = el('select', {}, Object.entries(SCHEMES).map(([v, t]) => el('option', { value: v, selected: numbering.scheme === v }, t)));
      sel.addEventListener('change', () => { upd('scheme', sel.value); renderPanel(); });
      const pre = el('input', { value: numbering.prefix, maxlength: 20, placeholder: 'למשל: שכ' });
      pre.addEventListener('input', () => upd('prefix', pre.value));
      const dig = el('input', { type: 'number', min: 1, max: 8, value: numbering.digits });
      dig.addEventListener('input', () => upd('digits', Math.max(1, Math.min(8, Number(dig.value) || 4))));
      const pat = el('input', { value: numbering.pattern, dir: 'ltr', maxlength: 60 });
      pat.addEventListener('input', () => upd('pattern', pat.value));
      const none = numbering.scheme === 'none';
      return el('div', { class: 'numbering' },
        el('h2', {}, 'מספור מסמכים'),
        el('label', {}, el('span', {}, 'שיטה'), sel),
        none ? null : el('label', {}, el('span', {}, 'קידומת'), pre),
        none ? null : el('label', {}, el('span', {}, 'ספרות במספר הרץ'), dig),
        numbering.scheme === 'pattern' ? el('label', {}, el('span', {}, 'תבנית: {prefix} {yyyy} {mm} {seq}'), pat) : null,
        el('p', { class: 'small' }, 'המסמך הבא יקבל: ', preview));
    }

    // ── שלב 11: תבנית שנכתבה בכלי — עורך הגוף ──
    // כל קטע: כותרת, פסקה (עם {{שדות}}), טבלה חוזרת או מעבר עמוד. השדות נגזרים
    // מהסימונים בטקסט — כמו בתבנית Word — ומוגדרים בלוח שמימין.
    const BLOCKS = { p: 'פסקה', heading: 'כותרת', table: 'טבלה', break: 'מעבר עמוד' };
    const removedFields = {};   // שדה שהסימון שלו נמחק — אם יחזור, ההגדרות שלו חוזרות
    const bid = () => 'b' + Math.random().toString(36).slice(2, 8);
    const cleanName = (n) => String(n || '').trim().replace(/\s+/g, '_').replace(/[{}.]/g, '').slice(0, 60);

    function placeholderField(n, extra = {}) {
      if (removedFields[n]) { const f = removedFields[n]; delete removedFields[n]; return f; }
      return { id: uid(), key: 'f_' + Math.random().toString(36).slice(2, 8), placeholder: n, label: n.replace(/_/g, ' ').trim(),
        type: guess(n), help: '', required: false, default: '', page: 0, x: 0, y: 0, w: 0.3, h: 0.03,
        fontSize: 11, align: 'right', overflow: 'shrink', role: 'owner', ...extra };
    }

    function syncFields() {
      const names = [];
      for (const b of schema.body) {
        for (const m of (b.text || '').matchAll(PH)) {
          const n = m[1].trim();
          if (!n.includes('.') && !names.includes(n)) names.push(n);
        }
      }
      const tables = schema.body.filter(b => b.type === 'table').map(b => b.table);
      for (const n of names) if (!schema.fields.some(f => f.placeholder === n)) schema.fields.push(placeholderField(n));
      const keep = (f) => names.includes(f.placeholder) || (f.type === 'table' && tables.includes(f.key));
      for (const f of schema.fields) if (!keep(f)) removedFields[f.placeholder] = f;
      schema.fields = schema.fields.filter(keep);
      for (const f of schema.fields) if (f.showIf && !schema.fields.some(x => x.key === f.showIf.key)) f.showIf = null;
      if (selected && !schema.fields.includes(selected)) selected = null;
    }

    function bodyChanged(full) {
      syncFields();
      setDirty(true);
      if (full) renderBlocks();
      renderPanel();
    }

    function renderBlocks() {
      if (!isAuthored) return;
      const sheet = el('div', { class: 'doc-sheet' }, schema.body.map(blockEl));
      pagesBox.replaceChildren(...[
        readOnly ? null : el('p', { class: 'notice' }, '✍️ כותבים את המסמך כאן. שדה = ', el('code', {}, '{{שם_השדה}}'),
          ' בתוך הטקסט, או "+ שדה". בלשונית "🧩 שדות" מגדירים לכל שדה סוג, מי ממלא, חישוב ומתי מוצג.'),
        sheet,
        readOnly ? null : el('div', { class: 'ops add-blocks' }, el('span', { class: 'muted small' }, 'הוספה:'),
          Object.entries(BLOCKS).map(([t, l]) => el('button', { type: 'button', class: 'small', onclick: () => addBlock(t) }, '+ ' + l)))].filter(Boolean));
    }

    function blockEl(b, i) {
      const last = schema.body.length - 1;
      const btn = (t, title, on, dis) => el('button', { type: 'button', class: 'link small', title, disabled: !!dis, onclick: on }, t);
      const wrap = el('div', { class: 'block b-' + b.type + (b.showIf ? ' conditional' : ''), 'data-block': b.id });
      let main;
      if (b.type === 'p' || b.type === 'heading') {
        const ta = el('textarea', { class: 'block-text', rows: 1, disabled: readOnly, 'aria-label': BLOCKS[b.type],
          placeholder: b.type === 'heading' ? 'כותרת' : 'טקסט. שדה: {{שם_השדה}}' });
        ta.value = b.text || '';
        ta.style.textAlign = b.align === 'center' ? 'center' : b.align === 'justify' ? 'justify' : 'right';
        const grow = () => { if (!ta.offsetParent) return; ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; };   // מוסתר — לא מודדים
        ta.addEventListener('input', () => { b.text = ta.value; setDirty(true); grow(); });
        ta.addEventListener('change', () => bodyChanged(false));
        requestAnimationFrame(grow);
        const ins = el('select', { class: 'field-ins', 'aria-label': 'הוספת שדה לטקסט', hidden: readOnly },
          el('option', { value: '' }, '+ שדה'), el('option', { value: '*' }, 'שדה חדש…'),
          schema.fields.filter(f => f.placeholder).map(f => el('option', { value: f.placeholder }, TYPES[f.type].icon + ' ' + f.label)));
        ins.addEventListener('change', () => {
          const n = cleanName(ins.value === '*' ? prompt('שם השדה (למשל: שם_הלקוח)') : ins.value);
          ins.value = '';
          if (!n) return;
          const at = ta.selectionStart ?? ta.value.length;
          b.text = ta.value.slice(0, at) + '{{' + n + '}}' + ta.value.slice(ta.selectionEnd ?? at);
          bodyChanged(true);
        });
        const align = el('select', { class: 'align', 'aria-label': 'יישור', hidden: readOnly },
          Object.entries({ right: 'ימין', center: 'מרכז', justify: 'מיושר' }).map(([v, t]) => el('option', { value: v, selected: (b.align || 'right') === v }, t)));
        align.addEventListener('change', () => { b.align = align.value; ta.style.textAlign = align.value === 'justify' ? 'justify' : align.value; setDirty(true); });
        wrap.classList.add(b.type === 'heading' ? 'is-heading' : 'is-p');
        main = el('div', { class: 'block-main' }, ta, el('div', { class: 'block-side' }, ins, align));
      } else if (b.type === 'table') {
        const f = schema.fields.find(x => x.key === b.table);
        main = f ? el('div', { class: 'block-table' },
          el('table', {}, el('thead', {}, el('tr', {}, (f.columns || []).map(c => el('th', {}, c.label + (c.type === 'calc' ? ' ƒ' : ''))))),
            el('tbody', {}, el('tr', {}, (f.columns || []).map(() => el('td', {}, '…'))))),
          readOnly ? null : el('button', { type: 'button', class: 'link small', onclick: () => editField(f) }, '⚙ ' + f.label + ' — עמודות וחישובים'))
          : el('p', { class: 'muted small' }, 'טבלה חסרה');
      } else {
        main = el('div', { class: 'block-break' }, '— מעבר עמוד —');
      }
      const tools = readOnly ? null : el('div', { class: 'block-tools' },
        el('span', { class: 'muted small' }, BLOCKS[b.type]),
        btn(b.showIf ? '👁 מותנה' : '👁', 'מתי הקטע מוצג', () => {
          const open_ = wrap.querySelector('.cond-box');
          if (open_) { open_.remove(); return; }
          wrap.append(el('div', { class: 'cond-box' }, el('span', { class: 'small muted' }, 'הקטע מוצג: '),
            condEditor(b, null, () => wrap.classList.toggle('conditional', !!b.showIf))));
        }),
        btn('↑', 'למעלה', () => move(i, -1), i === 0),
        btn('↓', 'למטה', () => move(i, 1), i === last),
        btn('✕', 'מחיקת הקטע', () => {
          if ((b.text || b.type === 'table') && !confirm('למחוק את הקטע?')) return;
          schema.body.splice(i, 1);
          bodyChanged(true);
        }));
      wrap.append(tools, main);
      return wrap;
    }

    function move(i, d) {
      const [b] = schema.body.splice(i, 1);
      schema.body.splice(i + d, 0, b);
      bodyChanged(true);
    }

    function addBlock(type) {
      if (type === 'table') {
        let n = cleanName(prompt('שם הטבלה (למשל: פריטים)', 'פריטים'));
        if (!n) return;
        while (schema.fields.some(f => f.placeholder === n)) n += '_2';
        const t = placeholderField(n, { type: 'table', columns: [
          { key: 'desc', label: 'תיאור', type: 'text' }, { key: 'qty', label: 'כמות', type: 'number' },
          { key: 'unit', label: 'מחיר', type: 'number' },
          { key: 'line', label: 'סה"כ', type: 'calc', formula: 'כמות * מחיר', format: 'money' }] });
        const total = placeholderField('סה"כ_' + n, { type: 'calc', label: 'סה"כ ' + n.replace(/_/g, ' '), formula: `sum(${n}.סה"כ)`, format: 'money' });
        schema.fields.push(t, total);
        schema.body.push({ id: bid(), type: 'table', table: t.key }, { id: bid(), type: 'p', text: `סה"כ: {{${total.placeholder}}}`, align: 'right' });
      } else {
        schema.body.push({ id: bid(), type, ...(type === 'break' ? {} : { text: '', align: type === 'heading' ? 'center' : 'right' }) });
      }
      bodyChanged(true);
      const tas = pagesBox.querySelectorAll('.block-text');
      if (type !== 'table' && type !== 'break' && tas.length) tas[tas.length - 1].focus();
    }

    // ── שלב 11: נוסחה, טבלה, תנאי ──
    const under = (x) => String(x || '').trim().replace(/\s+/g, '_');
    /** איך לכתוב שדה בנוסחה: הסימון או התווית — אם הם מזהה תקין; אחרת המפתח. */
    const idOf = (x) => [x.placeholder, under(x.label)].find(n => n && /^[\p{L}_"'][\p{L}\p{N}_."']*$/u.test(n) && !n.includes('.')) || x.key;
    const colId = (c) => /^[\p{L}_"'][\p{L}\p{N}_"']*$/u.test(under(c.label)) ? under(c.label) : c.key;

    function formulaInput(target, tableCtx, refs) {
      const out = el('p', { class: 'small formula-msg' });
      const inp = el('input', { value: target.formula || '', class: 'formula', dir: 'auto',
        placeholder: tableCtx ? 'למשל: כמות * מחיר' : 'למשל: sum(פריטים.סה"כ) * 1.18', 'aria-label': 'נוסחה' });
      const check = () => {
        const err = window.DTCalc.check(inp.value, schema.fields, tableCtx);
        out.textContent = err ? '⚠ ' + err : inp.value.trim() ? '✓ תקין' : '';
        out.classList.toggle('bad-text', !!err);
        out.classList.toggle('ok-text', !err);
      };
      inp.addEventListener('input', () => { target.formula = inp.value; setDirty(true); check(); });
      check();
      const insert = (t) => {
        const a = inp.selectionStart ?? inp.value.length, b = inp.selectionEnd ?? a;
        inp.value = inp.value.slice(0, a) + t + inp.value.slice(b);
        inp.focus();
        inp.setSelectionRange(a + t.length, a + t.length);
        inp.dispatchEvent(new Event('input'));
      };
      return el('div', { class: 'formula-box' }, inp, out,
        refs.length ? el('div', { class: 'chips refs' }, refs.map(([t, title]) =>
          el('button', { type: 'button', class: 'chip', title, onclick: () => insert(t) }, t))) : null);
    }

    function calcPanel(f) {
      const refs = [];
      for (const x of schema.fields) {
        if (x === f) continue;
        if (x.type === 'table') {
          for (const c of x.columns || []) if (c.type !== 'text') refs.push([`sum(${idOf(x)}.${colId(c)})`, `סכום העמודה "${c.label}"`]);
          refs.push([`count(${idOf(x)})`, 'מספר השורות']);
        } else if (!['signature', 'file'].includes(x.type)) {
          refs.push([idOf(x), x.label]);
        }
      }
      return el('div', { class: 'file-opts' },
        el('span', { class: 'muted small' }, 'נוסחה'),
        formulaInput(f, null, refs),
        el('p', { class: 'muted small' }, '+ − * / ( ) · sum(טבלה.עמודה) · round(x, 2) · if(תנאי, א, ב) · days(תאריך, 30) · min · max'),
        formatSelect(f));
    }

    function formatSelect(f) {
      const sel = el('select', {}, Object.entries(FORMATS).map(([v, t]) => el('option', { value: v, selected: (f.format || 'number') === v }, t)));
      sel.addEventListener('change', () => { f.format = sel.value; setDirty(true); });
      return el('label', {}, el('span', {}, 'תצוגה'), sel);
    }

    function tablePanel(f) {
      f.columns ??= [];
      const box = el('div', { class: 'file-opts table-cols' });
      const draw_ = () => {
        box.replaceChildren(el('span', { class: 'muted small' }, 'עמודות'), ...f.columns.map((c, i) => {
          const label = el('input', { value: c.label, maxlength: 60, 'aria-label': 'שם העמודה' });
          label.addEventListener('input', () => { c.label = label.value; setDirty(true); });
          label.addEventListener('change', draw_);
          const type = el('select', { 'aria-label': 'סוג העמודה' },
            Object.entries({ text: 'טקסט', number: 'מספר', select: '☰ בחירה', calc: 'ƒ מחושב' }).map(([v, t]) => el('option', { value: v, selected: c.type === v }, t)));
          type.addEventListener('change', () => { c.type = type.value; if (c.type === 'calc') { c.formula ??= ''; c.format ??= 'number'; } if (c.type === 'select') c.options ??= []; setDirty(true); draw_(); });
          // בחירה: אפשרות בכל שורה
          const opts = el('textarea', { rows: 3, 'aria-label': 'אפשרויות — אחת בכל שורה', placeholder: 'אפשרות בכל שורה, למשל:\nקבר בודד\nמכפלה' });
          opts.value = (c.options || []).join('\n');
          opts.addEventListener('input', () => { c.options = opts.value.split('\n').map(x => x.trim()).filter(Boolean); setDirty(true); });
          const del = el('button', { type: 'button', class: 'link small', title: 'הסרה', disabled: f.columns.length < 2,
            onclick: () => { f.columns.splice(i, 1); setDirty(true); draw_(); } }, '✕');
          const fmt = el('select', { 'aria-label': 'תצוגה' }, Object.entries(FORMATS).filter(([k]) => k !== 'date')
            .map(([v, t]) => el('option', { value: v, selected: (c.format || 'number') === v }, t)));
          fmt.addEventListener('change', () => { c.format = fmt.value; setDirty(true); });
          return el('div', { class: 'col-row' },
            el('div', { class: 'row' }, label, type, del),
            c.name ? el('p', { class: 'small muted' }, 'בקובץ: ', el('code', {}, `{{${f.placeholder}.${c.name}}}`)) : null,
            c.type === 'calc' ? formulaInput(c, f, f.columns.filter(x => x !== c && x.type !== 'text').map(x => [colId(x), x.label])) : null,
            c.type === 'calc' ? fmt : null,
            c.type === 'select' && !c.list ? opts : null,
            ['select', 'text'].includes(c.type) ? listPicker(c, draw_) : null);
        }), f.columns.length < 12 ? el('button', { type: 'button', class: 'small', onclick: () => {
          f.columns.push({ key: 'c' + Math.random().toString(36).slice(2, 7), label: 'עמודה ' + (f.columns.length + 1), type: 'text' });
          setDirty(true); draw_();
        } }, '+ עמודה') : null);
      };
      draw_();
      // קיבוץ: שורות רצופות שזהות ב-N העמודות הראשונות — קבוצה אחת בפלט (הערכים פעם אחת, קו עבה בין קבוצות)
      const grp = el('select', { 'aria-label': 'קיבוץ' });
      const fillGrp = () => {
        const cur = f.group === true ? 1 : Number(f.group) || 0;
        grp.replaceChildren(el('option', { value: 0, selected: !cur }, 'בלי קיבוץ'),
          ...f.columns.slice(0, -1).map((c, i) => el('option', { value: i + 1, selected: cur === i + 1 },
            'לפי ' + f.columns.slice(0, i + 1).map(x => x.label).join(' › '))));
      };
      fillGrp();
      box.addEventListener('change', fillGrp);
      grp.addEventListener('change', () => { f.group = Number(grp.value); setDirty(true); });
      return el('div', {}, box, el('label', { class: 'f' }, el('span', {}, 'קיבוץ שורות'), grp),
        el('p', { class: 'hint' }, 'שורות רצופות שזהות בעמודות האלה (למשל גוש › חלקה › שורה › אחוזה) — קבוצה אחת: הערכים מודפסים פעם אחת, וקו עבה רק בין קבוצות. במילוי: "+ שורה באותה …".'));
    }

    /** "מוצג רק אם…": שדה, פעולה, ערך. target — שדה או קטע בגוף. */
    function condEditor(target, self, onChange) {
      const c = target.showIf || {};
      const others = schema.fields.filter(x => x !== self && !['signature', 'file', 'calc'].includes(x.type));
      const field = el('select', { 'aria-label': 'תלוי בשדה' },
        el('option', { value: '' }, '— תמיד —'),
        others.map(x => el('option', { value: x.key, selected: c.key === x.key }, 'רק אם ' + x.label)));
      const box = el('div', { class: 'cond' });
      const draw_ = (fromUser) => {
        const src = schema.fields.find(x => x.key === field.value);
        if (!src) { box.replaceChildren(field); return; }
        const isBox = src.type === 'checkbox';
        const ops = src.type === 'table' ? { filled: 'יש בה שורות', empty: 'ריקה' } : isBox ? { eq: 'מסומן?' } : COND_OPS;
        const op = el('select', { 'aria-label': 'תנאי' }, Object.entries(ops).map(([v, t]) => el('option', { value: v, selected: (target.showIf?.op || 'eq') === v }, t)));
        const listOpts = src.type === 'select' && !src.source ? (src.options || []) : [];
        const val = listOpts.length
          ? el('select', { 'aria-label': 'ערך' }, el('option', { value: '' }, '— ערך —'),
              listOpts.map(o => el('option', { value: o.value, selected: target.showIf?.value === o.value }, o.label)))
          : isBox
          ? el('select', { 'aria-label': 'ערך' }, el('option', { value: 'כן', selected: target.showIf?.value !== 'לא' }, 'כן'),
              el('option', { value: 'לא', selected: target.showIf?.value === 'לא' }, 'לא'))
          : el('input', { value: target.showIf?.value || '', maxlength: 200, 'aria-label': 'ערך', placeholder: 'ערך' });
        const upd = () => {
          const o = ops[op.value] ? op.value : Object.keys(ops)[0];
          target.showIf = { key: field.value, op: o, value: ['filled', 'empty'].includes(o) ? '' : val.value };
          val.hidden = ['filled', 'empty'].includes(o);
          setDirty(true);
          onChange && onChange();
        };
        op.addEventListener('change', upd);
        val.addEventListener(val.tagName === 'SELECT' ? 'change' : 'input', upd);
        box.replaceChildren(field, op, val);
        // בפתיחה — רק מציגים; שינוי נרשם כשהמשתמש בוחר
        if (fromUser) upd(); else val.hidden = ['filled', 'empty'].includes(op.value);
      };
      field.addEventListener('change', () => {
        if (!field.value) { target.showIf = null; setDirty(true); onChange && onChange(); }
        draw_(true);
      });
      draw_();
      return box;
    }

    // ── בחירה מרשימה: רשימה קבועה, או ממקור נתונים חיצוני ──
    let sourcesP = null;   // המקורות של המשתמש — נטען פעם אחת לעורך
    const loadSources = (force) => (sourcesP = !force && sourcesP ? sourcesP
      : api('src_list').then(r => r.sources).catch(() => []));

    function selectPanel(f) {
      f.options ??= [];
      const box = el('div', { class: 'select-opts' });
      const mode = f.source ? 'source' : 'list';
      const seg = el('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'מאיפה הרשימה' },
        [['list', '✍️ רשימה קבועה'], ['source', '🔌 ממערכת חיצונית']].map(([k, t]) =>
          el('label', {}, el('input', { type: 'radio', name: 'opt-src-' + f.id, value: k, checked: mode === k,
            onchange: () => {
              if (k === 'list') f.source = null;
              else f.source = { id: 0, value_key: '', label_key: '', fills: [] };
              setDirty(true); draw_();
            } }), el('span', {}, t))));

      function listEditor() {
        // שורה לכל אפשרות: "ערך" או "ערך | מה שמוצג"
        const ta = el('textarea', { rows: Math.max(4, Math.min(12, f.options.length + 1)), dir: 'auto',
          'aria-label': 'האפשרויות', placeholder: 'שורה לכל אפשרות\nלמשל:\nקטן\nבינוני\nS | קטן (מה שמוצג)' });
        ta.value = f.options.map(o => o.label && o.label !== o.value ? `${o.value} | ${o.label}` : o.value).join('\n');
        const count = el('small', { class: 'muted' });
        const parse = () => {
          const seen = new Set();
          f.options = ta.value.split('\n').map(l => l.split('|').map(x => x.trim())).filter(([v]) => v && !seen.has(v) && seen.add(v))
            .map(([v, l]) => ({ value: v, label: l || v }));
          count.textContent = f.options.length + ' אפשרויות';
        };
        ta.addEventListener('input', () => { parse(); setDirty(true); });
        parse();
        return el('label', { class: 'f' }, el('span', {}, 'האפשרויות'), ta, count);
      }

      async function sourceEditor() {
        const holder = el('div', { class: 'src-pick' }, el('p', { class: 'hint' }, 'טוען מקורות…'));
        const sources = await loadSources();
        const s = f.source;
        const render = () => {
          const src = sources.find(x => x.id === s.id);
          const pick = el('select', { 'aria-label': 'מקור הנתונים' }, el('option', { value: '0' }, '— בחירת מקור —'),
            sources.map(x => el('option', { value: x.id, selected: x.id === s.id }, x.name)),
            s.id && !src ? el('option', { value: s.id, selected: true }, 'מקור של בעל התבנית') : null);
          pick.addEventListener('change', () => { s.id = Number(pick.value); s.value_key = ''; s.label_key = ''; s.fills = []; setDirty(true); render(); });
          const keys = src?.keys || [];
          const keySel = (label, prop, dflt) => {
            const k = el('select', {}, el('option', { value: '' }, '— כמו במקור' + (dflt ? ` (${dflt})` : '') + ' —'),
              keys.map(x => el('option', { value: x, selected: s[prop] === x }, x)));
            k.addEventListener('change', () => { s[prop] = k.value; setDirty(true); });
            return el('label', { class: 'f' }, el('span', {}, label), k);
          };
          // שדות שמתמלאים מהרשומה שנבחרה
          const targets = schema.fields.filter(x => x !== f && ['text', 'textarea', 'number', 'date'].includes(x.type));
          const fillRows = targets.map(x => {
            const cur = (s.fills || []).find(m => m.key === x.key)?.from || '';
            const k = el('select', { 'aria-label': 'ממלא את ' + x.label }, el('option', { value: '' }, '— לא —'),
              keys.map(y => el('option', { value: y, selected: cur === y }, y)));
            k.addEventListener('change', () => {
              s.fills = (s.fills || []).filter(m => m.key !== x.key);
              if (k.value) s.fills.push({ key: x.key, from: k.value });
              setDirty(true);
            });
            return el('div', { class: 'map-row' }, el('span', { class: 'use-tx' }, el('b', {}, x.label)), k);
          });
          const status = src ? el('p', { class: 'hint' + (src.last_error ? ' bad-text' : '') }, src.last_error
            ? '⚠ ' + src.last_error
            : `${src.row_count} רשומות · עודכן ${src.fetched_at ? new Date(src.fetched_at).toLocaleString('he-IL') : '—'} · מתרענן כל ${src.ttl_min} דק׳`) : null;
          const sync = src ? el('button', { type: 'button', class: 'small', onclick: async () => {
            sync.disabled = true; sync.textContent = 'מסנכרן…';
            try { Object.assign(src, (await api('src_sync', { id: src.id })).source); }
            catch (e) { src.last_error = e.message; }
            render();
          } }, '🔄 סנכרון עכשיו') : null;
          holder.replaceChildren(...[
            sources.length ? null : el('p', { class: 'notice' }, 'עוד אין מקורות נתונים. מגדירים אותם ב"🔌 חיבורים": כתובת שמחזירה רשימה, ואם צריך — אסימון.'),
            el('label', { class: 'f' }, el('span', {}, 'מקור הנתונים'), pick),
            el('a', { class: 'link small', href: '#connections' }, '🔌 ניהול מקורות'),
            status, sync,
            src ? el('div', { class: 'fields two' }, keySel('הערך שנשמר', 'value_key', src.value_key), keySel('מה שמוצג ברשימה', 'label_key', src.label_key)) : null,
            src && targets.length ? el('h4', { class: 'sub-title' }, 'הבחירה ממלאת גם') : null,
            src && targets.length ? el('div', { class: 'fill-map' }, fillRows) : null,
            src && !keys.length ? el('p', { class: 'hint' }, 'אין עדיין רשומות מהמקור — סנכרנו, ואז יופיעו כאן השדות שלו.') : null].filter(Boolean));
          if (readOnly) for (const n of holder.querySelectorAll('select, button')) n.disabled = true;
        };
        render();
        return holder;
      }

      async function draw_() {
        box.replaceChildren(seg, f.source ? await sourceEditor() : listEditor());
      }
      draw_();
      return box;
    }

    // צירוף קובץ: אילו סוגים, גודל מרבי, ואחד או כמה.
    function filePanel(f) {
      f.accept ??= ['pdf', 'image'];
      f.maxMb ??= 10;
      const KINDS = { pdf: 'PDF', image: 'תמונה (כולל צילום מהמצלמה)', office: 'Word / Excel' };
      const boxes = Object.entries(KINDS).map(([k, t]) => {
        const c = el('input', { type: 'checkbox', checked: f.accept.includes(k) });
        c.addEventListener('change', () => {
          f.accept = Object.keys(KINDS).filter(x => x === k ? c.checked : f.accept.includes(x));
          if (!f.accept.length) { f.accept = [k]; c.checked = true; }
          setDirty(true);
        });
        return el('label', { class: 'inline small' }, c, ' ' + t);
      });
      const max = el('input', { type: 'number', min: 1, max: 50, value: f.maxMb });
      max.addEventListener('input', () => { f.maxMb = Math.max(1, Math.min(50, Number(max.value) || 10)); setDirty(true); });
      const multi = el('input', { type: 'checkbox', checked: !!f.multiple });
      multi.addEventListener('change', () => { f.multiple = multi.checked; setDirty(true); });
      return el('div', { class: 'file-opts' },
        el('span', { class: 'muted small' }, 'סוגי קבצים מותרים'), ...boxes,
        el('label', {}, el('span', {}, 'גודל מרבי לקובץ (MB)'), max),
        el('label', { class: 'inline small' }, multi, ' אפשר לצרף כמה קבצים'),
        el('p', { class: 'muted small' }, 'המגבלה בפועל היא הקטנה מבין זו לבין המכסה של החשבון.'));
    }

    function scrollTo(f) {
      if (!pageEls[f.page]) return;   // תבנית Word — אין דפים לגלול אליהם
      pageEls[f.page].layer.parentElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ── שמירה ──
    async function save() {
      if (saving) return;
      saving = true;
      saveBtn.disabled = true;
      msg.replaceChildren();
      try {
        const body = { id, name: nameInput.value, schema };
        if (numberingDirty) body.numbering = numbering;
        const r = await api('tpl_save', body);
        numberingDirty = false;
        setDirty(false);
        if (r.version !== tpl.version) {
          msg.replaceChildren(notice(`נשמר כגרסה ${r.version}. מסמכים שכבר הונפקו נשארים על הגרסה הקודמת.`, 'ok'));
          tpl.version = r.version;
        }
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      } finally {
        saving = false;
        saveBtn.disabled = false;
      }
    }

    draw();
    renderPanel();
    renderBlocks();
    if (imported) msg.replaceChildren(notice(`✓ הקובץ הגיע עם הגדרות מוכנות: ${imported} שדות, כולל טבלאות ונוסחאות — נטענו ונשמרו.`, 'ok'));
    if (autoSave) save();
  }

  window.DTEditor = { open, renderBackground, TYPES, wordPreview, DOCX, zoomable };
})();
