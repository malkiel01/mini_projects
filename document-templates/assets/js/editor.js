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
  // שלב 11: טבלה חוזרת — רק בתבנית Word ובתבנית שנכתבה בכלי (על דף PDF אין לאן להתרחב)
  const PLACEABLE = Object.keys(TYPES).filter(t => t !== 'table' && t !== 'system');
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
      parsed: !!tpl.schema.parsed,
      ...(tpl.schema.body ? { body: tpl.schema.body.map(b => ({ ...b })) } : {}),
    };
    const roleIndex = (key) => Math.max(0, schema.roles.findIndex(r => r.key === key));
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
    const palette = el('div', { class: 'palette design-bar' }, addBtn, legend, placingBar);
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
          + (f.required ? ' required' : '') + (f.hidden ? ' private' : ''),
        style: `left:${f.x * 100}%; top:${f.y * 100}%; width:${f.w * 100}%; height:${f.h * 100}%`,
        title: f.label + ' — לחיצה: הגדרות; גרירה: הזזה', tabindex: 0, role: 'button',
      }, el('span', { class: 'field-label' }, iconOf(f) + ' ' + f.label),
        el('span', { class: 'handle', 'aria-hidden': 'true' }));
      box.addEventListener('pointerdown', (e) => startDrag(e, f, e.target.classList.contains('handle')));
      box.addEventListener('focus', () => { if (selected !== f) { selected = f; for (const n of pagesBox.querySelectorAll('.field.selected')) n.classList.remove('selected'); box.classList.add('selected'); } });
      box.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); editField(f); } });
      return box;
    }

    function draw() {
      renderLegend();
      const sp = showSpots && !readOnly ? freeSpots() : [];
      pageEls.forEach((p, i) => p.layer.replaceChildren(
        ...sp.filter(s => s.page === i).map(spotEl),
        ...schema.fields.filter(f => f.page === i).map(fieldEl)));
    }

    // גרירה: הזזה, או שינוי גודל מהידית. התנועה מחושבת כשבר מגודל הדף.
    function startDrag(e, f, resize) {
      if (readOnly) { e.stopPropagation(); editField(f); return; }
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
      const c = { ...f, id: uid(), key: f.key + '_2', label: f.label + ' (עותק)',
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
    function whoOf(f) {
      if (f.type === 'system') return '⚙️ מתמלא לבד';
      if (f.type === 'calc') return 'ƒ מחושב לבד';
      return (f.role || 'owner') === 'owner' ? '✍️ אני ממלא' : '🔗 ' + roleLabel(f.role) + ' — בקישור';
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
      panel.hidden = tab === 'design';
      if (tab === 'design') { panel.replaceChildren(); return; }
      if (tab === 'fields') panel.replaceChildren(fieldsList());
      else if (tab === 'roles') panel.replaceChildren(rolesPanel());
      else panel.replaceChildren(numberingPanel(), el('div', { class: 'numbering' }, el('h2', {}, 'תגיות לתבנית'),
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
      add('✍️ אני ממלא', 'בהנפקה או בעריכת המסמך', schema.fields.filter(f => !isAuto(f) && (f.role || 'owner') === 'owner'));
      for (const r of schema.roles.filter(r => r.key !== 'owner')) {
        add('🔗 ' + r.label + ' — ממלא בקישור', r.auth === 'email' ? 'נכנס עם קוד במייל' : 'נכנס מהקישור', schema.fields.filter(f => !isAuto(f) && f.role === r.key));
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
        const textual = ['text', 'textarea', 'number', 'date', 'calc', 'system'].includes(f.type);
        const onPage = !isWord && !isAuthored;
        // סוג: טבלה נשארת טבלה. מעבר ל"מתמלא לבד" — בוחרים מקור.
        const types = f.type === 'table' ? ['table'] : [...PLACEABLE, 'system'];
        const typeSel = el('select', { disabled: f.type === 'table' },
          types.map(k => el('option', { value: k, selected: f.type === k }, TYPES[k].icon + ' ' + TYPES[k].label)));
        typeSel.addEventListener('change', () => {
          f.type = typeSel.value;
          if (f.type === 'calc') { f.formula ??= ''; f.format ??= 'number'; }
          if (f.type === 'system') f.source ??= 'number';
          if (isAuto(f)) { f.required = false; f.contactField = ''; }
          setDirty(true); relabel(); draw(); render();
        });
        // מי ממלא: כפתורי בחירה — אני / כל ממלא בקישור / + ממלא חדש
        const roleSeg = el('div', { class: 'role-seg', role: 'radiogroup', 'aria-label': 'מי ממלא' },
          schema.roles.map((r, i) => el('button', { type: 'button', role: 'radio', 'aria-checked': String((f.role || 'owner') === r.key),
            class: ((f.role || 'owner') === r.key ? 'on ' : '') + 'role-opt role-' + (i % 6), 'data-role': r.key,
            onclick: () => { f.role = r.key; setDirty(true); draw(); render(); } },
          el('span', { class: 'role-dot role-' + (i % 6) }), r.key === 'owner' ? 'אני' : r.label + ' (קישור)')),
          el('button', { type: 'button', class: 'role-opt add', onclick: () => {
            const name = (prompt('שם הממלא (למשל: לקוח, ספק, עד)', schema.roles.length === 1 ? 'לקוח' : '') || '').trim();
            if (!name) return;
            const r = { key: 'role_' + Math.random().toString(36).slice(2, 7), label: name.slice(0, 60), auth: 'none' };
            schema.roles.push(r);
            f.role = r.key;
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
          : el('div', {}, roleSeg, el('p', { class: 'hint' }, (f.role || 'owner') === 'owner'
              ? 'אתם ממלאים את השדה — בהנפקה או בעריכת המסמך.'
              : `${roleLabel(f.role)} ממלא את השדה דרך הקישור האישי שלו. אתם רואים את מה שמילא.`));

        body.replaceChildren(
          group('השדה',
            input('תווית — מה רואים ליד השדה', 'label', { maxlength: 120 }),
            el('label', { class: 'f' }, el('span', {}, 'סוג'), typeSel),
            auto ? null : input('שורת עזרה (לא חובה)', 'help', { maxlength: 300 }),
            f.placeholder ? el('p', { class: 'hint' }, 'בקובץ: ', el('code', {}, '{{' + f.placeholder + '}}')) : null,
            f.pdfName ? el('p', { class: 'hint' }, 'שדה בטופס המקורי: ', el('code', { dir: 'ltr' }, f.pdfName)) : null),
          group(auto ? 'מאיפה הערך' : 'מי ממלא', who),
          group('הרשאות ותצוגה',
            auto ? null : toggle('שדה חובה', 'אי אפשר לסיים בלעדיו', () => !!f.required, v => { f.required = v; }),
            schema.roles.length > 1 || f.hidden ? toggle('מוסתר מהממלאים בקישור', 'רק אתם (ומי שממלא את השדה) רואים אותו — מידע פנימי',
              () => !!f.hidden, v => { f.hidden = v; }) : null,
            toggle('מודפס במסמך', 'כבוי — נשמר ומיוצא לאקסל, אבל לא מופיע בקובץ המודפס', () => f.print !== false, v => { f.print = v; }),
            el('div', { class: 'f' }, el('span', {}, 'מתי השדה מוצג'), condEditor(f, f, draw))),
          auto || f.type === 'signature' ? null : group('ערך',
            f.type === 'file' ? filePanel(f)
              : f.type === 'table' ? tablePanel(f)
              : input('ערך ברירת מחדל', 'default', { maxlength: 1000 }),
            !['file', 'checkbox', 'table'].includes(f.type) ? choose('מתמלא מאיש קשר', 'contactField', {
              '': '— לא —', name: 'שם', id_number: 'ת"ז / ח"פ', phone: 'טלפון', email: 'מייל', address: 'כתובת', notes: 'הערות',
            }) : null),
          textual && onPage ? group('על הדף',
            el('div', { class: 'fields two' },
              input('גודל גופן', 'fontSize', { type: 'number', min: 6, max: 72 }),
              choose('יישור', 'align', { right: 'ימין', center: 'מרכז', left: 'שמאל' })),
            choose('כשהטקסט ארוך מהשדה', 'overflow', OVERFLOW),
            el('p', { class: 'hint' }, `דף ${f.page + 1}. הזזה וגודל — בגרירה על הדף.`)) : null,
          el('details', { class: 'fs-group adv' }, el('summary', {}, 'מתקדם'),
            input('מפתח פנימי (לייצוא ולאקסל)', 'key', { dir: 'ltr', maxlength: 60, pattern: '[a-z0-9_]+' }),
            readOnly ? null : el('div', { class: 'ops' },
              f.placeholder ? null : el('button', { type: 'button', class: 'small', onclick: () => duplicate(f) }, 'שכפול'),
              el('button', { type: 'button', class: 'small danger', onclick: () => removeField(f) }, 'מחיקת השדה'))));
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
        const label = el('input', { value: r.label, maxlength: 60, 'aria-label': 'שם התפקיד' });
        label.addEventListener('input', () => { r.label = label.value; setDirty(true); });
        const auth = el('select', { 'aria-label': 'אימות' },
          el('option', { value: 'none', selected: r.auth !== 'email' }, 'בלי אימות'),
          el('option', { value: 'email', selected: r.auth === 'email' }, 'קוד במייל'));
        auth.addEventListener('change', () => { r.auth = auth.value; setDirty(true); });
        const del = el('button', { class: 'link small', title: 'הסרה', onclick: () => {
          const used = schema.fields.filter(f => f.role === r.key).length;
          if (used && !confirm(`ל"${r.label}" משויכים ${used} שדות. הם יעברו אליך. להסיר?`)) return;
          schema.fields.forEach(f => { if (f.role === r.key) f.role = 'owner'; });
          schema.roles = schema.roles.filter(x => x !== r);
          setDirty(true); draw(); renderPanel();
        } }, '✕');
        return el('li', { class: 'role-row' }, dot, label, auth, del);
      }));
      const seq = el('input', { type: 'checkbox', checked: schema.sequential });
      seq.addEventListener('change', () => { schema.sequential = seq.checked; setDirty(true); });
      return el('div', { class: 'numbering' },
        el('h2', {}, 'מי ממלא את המסמך'),
        el('p', { class: 'hint' }, '"אני" — אתם, בהנפקה. כל ממלא נוסף מקבל קישור אישי, וממלא רק את השדות שלו. משייכים שדה בהגדרות השדה ("מי ממלא").'),
        list,
        el('button', { class: 'small', onclick: () => {
          const n = schema.roles.length;
          schema.roles.push({ key: 'role_' + Math.random().toString(36).slice(2, 7), label: n === 1 ? 'לקוח' : 'משתתף ' + n, auth: 'none' });
          setDirty(true); renderPanel();
        } }, '+ ממלא בקישור'),
        schema.roles.length > 1 ? el('label', { class: 'inline small' }, seq, ' לפי הסדר — כל אחד מקבל את הקישור רק אחרי שהקודם סיים') : null);
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
      pagesBox.replaceChildren(
        readOnly ? null : el('p', { class: 'notice' }, '✍️ כותבים את המסמך כאן. שדה = ', el('code', {}, '{{שם_השדה}}'),
          ' בתוך הטקסט, או "+ שדה". בלוח מימין מגדירים לכל שדה סוג, חישוב ומתי מוצג.'),
        sheet,
        readOnly ? null : el('div', { class: 'ops add-blocks' }, el('span', { class: 'muted small' }, 'הוספה:'),
          Object.entries(BLOCKS).map(([t, l]) => el('button', { type: 'button', class: 'small', onclick: () => addBlock(t) }, '+ ' + l))));
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
        const grow = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; };
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
            Object.entries({ text: 'טקסט', number: 'מספר', calc: 'ƒ מחושב' }).map(([v, t]) => el('option', { value: v, selected: c.type === v }, t)));
          type.addEventListener('change', () => { c.type = type.value; if (c.type === 'calc') { c.formula ??= ''; c.format ??= 'number'; } setDirty(true); draw_(); });
          const del = el('button', { type: 'button', class: 'link small', title: 'הסרה', disabled: f.columns.length < 2,
            onclick: () => { f.columns.splice(i, 1); setDirty(true); draw_(); } }, '✕');
          const fmt = el('select', { 'aria-label': 'תצוגה' }, Object.entries(FORMATS).filter(([k]) => k !== 'date')
            .map(([v, t]) => el('option', { value: v, selected: (c.format || 'number') === v }, t)));
          fmt.addEventListener('change', () => { c.format = fmt.value; setDirty(true); });
          return el('div', { class: 'col-row' },
            el('div', { class: 'row' }, label, type, del),
            c.name ? el('p', { class: 'small muted' }, 'בקובץ: ', el('code', {}, `{{${f.placeholder}.${c.name}}}`)) : null,
            c.type === 'calc' ? formulaInput(c, f, f.columns.filter(x => x !== c && x.type !== 'text').map(x => [colId(x), x.label])) : null,
            c.type === 'calc' ? fmt : null);
        }), f.columns.length < 12 ? el('button', { type: 'button', class: 'small', onclick: () => {
          f.columns.push({ key: 'c' + Math.random().toString(36).slice(2, 7), label: 'עמודה ' + (f.columns.length + 1), type: 'text' });
          setDirty(true); draw_();
        } }, '+ עמודה') : null);
      };
      draw_();
      return box;
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
        const val = isBox
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
        val.addEventListener(isBox ? 'change' : 'input', upd);
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
    if (autoSave) save();
  }

  window.DTEditor = { open, renderBackground, TYPES, wordPreview, DOCX, zoomable };
})();
