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
  };
  // שלב 11: טבלה חוזרת — רק בתבנית Word ובתבנית שנכתבה בכלי (על דף PDF אין לאן להתרחב)
  const PLACEABLE = Object.keys(TYPES).filter(t => t !== 'table');
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
    const palette = el('div', { class: 'palette' },
      el('span', { class: 'muted small' }, 'הוספת שדה:'),
      PLACEABLE.map(type => [type, TYPES[type]]).map(([type, t]) =>
        el('button', { class: 'small', 'data-type': type, title: 'לחצו, ואז לחצו על המקום בדף',
          onclick: () => setPlacing(placing === type ? null : type) }, t.icon + ' ' + t.label)));
    const pagesBox = el('div', { class: 'pages' });
    const panel = el('aside', { class: 'panel card' });
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
      palette.hidden = true;
      pagesBox.classList.add('readonly');
    }

    show(
      el('div', { class: 'editor-bar card' },
        el('a', { href: '#home', class: 'small' }, '→ התבניות שלי'),
        nameInput, status, saveBtn),
      tpl.owner_name ? el('p', { class: 'notice' }, `👥 תבנית של ${tpl.owner_name} — ההרשאה שלך: ${window.DT.LEVEL[tpl.access]}` +
        (readOnly ? '. צפייה בלבד.' : '.')) : null,
      palette, parseBox, msg,
      el('div', { class: 'editor' }, pagesBox, panel));

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
    if (isAuthored) {
      palette.hidden = true;
      pagesBox.classList.add('word', 'authored');   // הגוף מצויר בסוף הפתיחה, כשהכול מאותחל
    } else if (isWord) {
      // תבנית Word: השדות באים מהסימונים {{...}} בקובץ. אין מיקום על דף —
      // מגדירים כאן רק את השדות, ומציגים תצוגה מקדימה של הקובץ.
      palette.hidden = true;
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
        select(f);
        renderParse();
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
    function setPlacing(type) {
      placing = type;
      pagesBox.classList.toggle('placing', !!type);
      for (const b of palette.querySelectorAll('[data-type]')) b.classList.toggle('active', b.dataset.type === type);
    }

    function addField(type, page, cx, cy) {
      const t = TYPES[type];
      const p = schema.pages[page];
      const w = clamp(t.w / p.w, 0.01, 1);
      const h = clamp(t.h / p.h, 0.005, 1);
      const n = schema.fields.filter(f => f.type === type).length + 1;
      const f = {
        id: uid(), key: type + '_' + n + '_' + Math.random().toString(36).slice(2, 5), type,
        label: t.label + ' ' + n, help: '', required: false, default: '',
        page, x: clamp(cx - w / 2, 0, 1 - w), y: clamp(cy - h / 2, 0, 1 - h), w, h,
        fontSize: 11, align: 'right', overflow: 'shrink',
      };
      schema.fields.push(f);
      setPlacing(null);
      setDirty(true);
      draw();
      select(f);
    }

    function fieldEl(f) {
      const box = el('div', {
        class: 'field t-' + f.type + ' role-' + (roleIndex(f.role) % 6) + (selected === f ? ' selected' : '') + (f.required ? ' required' : ''),
        style: `left:${f.x * 100}%; top:${f.y * 100}%; width:${f.w * 100}%; height:${f.h * 100}%`,
        title: f.label, tabindex: 0,
      }, el('span', { class: 'field-label' }, TYPES[f.type].icon + ' ' + f.label),
        el('span', { class: 'handle', 'aria-hidden': 'true' }));
      box.addEventListener('pointerdown', (e) => startDrag(e, f, e.target.classList.contains('handle')));
      box.addEventListener('focus', () => { if (selected !== f) select(f); });
      return box;
    }

    function draw() {
      const sp = showSpots && !readOnly ? freeSpots() : [];
      pageEls.forEach((p, i) => p.layer.replaceChildren(
        ...sp.filter(s => s.page === i).map(spotEl),
        ...schema.fields.filter(f => f.page === i).map(fieldEl)));
    }

    // גרירה: הזזה, או שינוי גודל מהידית. התנועה מחושבת כשבר מגודל הדף.
    function startDrag(e, f, resize) {
      if (readOnly) { e.stopPropagation(); select(f); return; }
      e.preventDefault();
      e.stopPropagation();
      const box = e.currentTarget;
      // בלי ציור מחדש: הוא היה מחליף את האלמנט שהגרירה תפסה.
      if (selected !== f) {
        selected = f;
        for (const n of pagesBox.querySelectorAll('.field.selected')) n.classList.remove('selected');
        box.classList.add('selected');
        renderPanel();
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
      setDirty(true);
      select(null);
      if (spots.length) renderParse();   // ההצעה שמתחתיו חוזרת
    }

    function duplicate(f) {
      const c = { ...f, id: uid(), key: f.key + '_2', label: f.label + ' (עותק)',
        y: clamp(f.y + f.h + 0.01, 0, 1 - f.h) };
      schema.fields.push(c);
      setDirty(true);
      select(c);
    }

    // מקלדת: מחיקה, ובחצים — הזזה עדינה (Shift — צעד גדול).
    document.addEventListener('keydown', onKey);
    function onKey(e) {
      if (readOnly) return;
      if (!document.body.contains(pagesBox)) { document.removeEventListener('keydown', onKey); return; }
      if (e.key === 'Escape') { setPlacing(null); return; }
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

    // ── לוח ההגדרות ──
    function renderPanel() {
      renderPanelInner();
      // צפייה בלבד: הלוח מוצג, אבל אי אפשר לשנות דבר (חוץ ממעבר בין שדות).
      if (readOnly) for (const n of panel.querySelectorAll('input, select, textarea, button:not(.link)')) n.disabled = true;
    }

    function renderPanelInner() {
      if (!selected) {
        panel.replaceChildren(
          el('h2', {}, `שדות (${schema.fields.length})`),
          schema.fields.length
            ? el('ol', { class: 'field-list' }, schema.fields.map(f => el('li', {},
                el('button', { class: 'link', onclick: () => { select(f); scrollTo(f); } },
                  TYPES[f.type].icon + ' ' + f.label),
                el('span', { class: 'muted small' }, (isWord || isAuthored ? '' : ' · דף ' + (f.page + 1)) + (f.required ? ' · חובה' : '')))))
            : el('p', { class: 'muted small' }, 'בחרו סוג שדה למעלה, ואז לחצו על המקום בדף.'),
          el('p', { class: 'muted small' }, 'גרירה מזיזה; הפינה התחתונה משנה גודל; חצים במקלדת — הזזה עדינה; Delete — מחיקה.'),
          rolesPanel(),
          numberingPanel(),
          el('div', { class: 'numbering' }, el('h2', {}, 'תגיות לתבנית'),
            window.DTCatalog.tagsInput(tpl.tags, async (tags) => {
              try { await api('tpl_tags', { id, tags }); tpl.tags = tags; }
              catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
            }, { disabled: readOnly })));
        return;
      }
      const f = selected;
      const input = (label, prop, attrs = {}) => {
        const i = el('input', { value: f[prop] ?? '', ...attrs });
        i.addEventListener('input', () => {
          f[prop] = attrs.type === 'number' ? Number(i.value) : i.value;
          setDirty(true);
          if (prop === 'label') { const lbl = pagesBox.querySelector('.field.selected .field-label'); if (lbl) lbl.textContent = TYPES[f.type].icon + ' ' + f.label; }
        });
        return el('label', {}, el('span', {}, label), i);
      };
      const select_ = (label, prop, options) => {
        const s = el('select', {}, Object.entries(options).map(([v, t]) => el('option', { value: v, selected: f[prop] === v }, t)));
        s.addEventListener('change', () => { f[prop] = s.value; setDirty(true); draw(); });
        return el('label', {}, el('span', {}, label), s);
      };
      const req = el('input', { type: 'checkbox', checked: f.required });
      req.addEventListener('change', () => { f.required = req.checked; setDirty(true); draw(); });
      const textual = ['text', 'textarea', 'number', 'date', 'calc'].includes(f.type);
      const derived = f.type === 'calc' || f.type === 'table';
      // סוג: שינוי מחליף את הלוח (לנוסחה יש שדות משלה). טבלה נשארת טבלה.
      const typeSel = el('select', { disabled: f.type === 'table' },
        (f.type === 'table' ? ['table'] : PLACEABLE).map(k => el('option', { value: k, selected: f.type === k }, TYPES[k].icon + ' ' + TYPES[k].label)));
      typeSel.addEventListener('change', () => {
        f.type = typeSel.value;
        if (f.type === 'calc') { f.formula ??= ''; f.format ??= 'number'; f.required = false; f.contactField = ''; }
        setDirty(true); draw(); renderPanel();
      });

      panel.replaceChildren(
        el('div', { class: 'row' }, el('h2', {}, 'הגדרות שדה'), el('button', { class: 'link small', onclick: () => select(null) }, 'כל השדות')),
        f.placeholder ? el('p', { class: 'small muted' }, 'בקובץ: ', el('code', {}, '{{' + f.placeholder + '}}')) : null,
        f.pdfName ? el('p', { class: 'small muted' }, 'שדה בטופס המקורי: ', el('code', { dir: 'ltr' }, f.pdfName)) : null,
        input('תווית — מה הממלא רואה', 'label', { maxlength: 120 }),
        el('label', {}, el('span', {}, 'סוג'), typeSel),
        f.type === 'calc' ? calcPanel(f) : null,
        f.type === 'table' ? tablePanel(f) : null,
        f.type === 'calc' ? null : el('label', { class: 'inline' }, req, ' שדה חובה'),
        schema.roles.length > 1 ? select_('מי ממלא', 'role', Object.fromEntries(schema.roles.map(r => [r.key, r.label]))) : null,
        input('שורת עזרה (לא חובה)', 'help', { maxlength: 300 }),
        f.type !== 'signature' && f.type !== 'file' && !derived ? input('ערך ברירת מחדל', 'default', { maxlength: 1000 }) : null,
        f.type === 'file' ? filePanel(f) : null,
        !['file', 'signature', 'checkbox', 'calc', 'table'].includes(f.type) ? select_('מתמלא מאיש קשר', 'contactField', {
          '': '— לא —', name: 'שם', id_number: 'ת"ז / ח"פ', phone: 'טלפון', email: 'מייל', address: 'כתובת', notes: 'הערות',
        }) : null,
        textual && !isWord && !isAuthored ? input('גודל גופן', 'fontSize', { type: 'number', min: 6, max: 72 }) : null,
        textual && !isWord && !isAuthored ? select_('יישור', 'align', { right: 'ימין', center: 'מרכז', left: 'שמאל' }) : null,
        textual && !isWord && !isAuthored ? select_('כשהטקסט ארוך מהשדה', 'overflow', OVERFLOW) : null,
        el('div', { class: 'numbering' }, el('h2', {}, 'מתי מוצג'), condEditor(f, f, draw)),
        input('מפתח פנימי (לייצוא ולאקסל)', 'key', { dir: 'ltr', maxlength: 60, pattern: '[a-z0-9_]+' }),
        el('div', { class: 'ops' },
          f.placeholder ? null : el('button', { class: 'small', onclick: () => duplicate(f) }, 'שכפול'),
          el('button', { class: 'small danger', onclick: () => removeField(f) }, 'מחיקה')));
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
        el('h2', {}, 'משתתפים'),
        el('p', { class: 'muted small' }, 'כל משתתף מקבל קישור אישי, וממלא רק את השדות שלו. משייכים שדה בהגדרות השדה.'),
        list,
        el('button', { class: 'small', onclick: () => {
          const n = schema.roles.length;
          schema.roles.push({ key: 'role_' + Math.random().toString(36).slice(2, 7), label: n === 1 ? 'לקוח' : 'משתתף ' + n, auth: 'none' });
          setDirty(true); renderPanel();
        } }, '+ הוספת משתתף'),
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
          readOnly ? null : el('button', { type: 'button', class: 'link small', onclick: () => select(f) }, '⚙ ' + f.label + ' — עמודות וחישובים'))
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

  window.DTEditor = { open, renderBackground, TYPES, wordPreview, DOCX };
})();
