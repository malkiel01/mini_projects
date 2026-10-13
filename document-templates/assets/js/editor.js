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
  };
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
  async function renderBackground(url, mime) {
    const res = await fetch(url, { credentials: 'same-origin' });
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

    const schema = { pages: tpl.schema.pages || [], fields: (tpl.schema.fields || []).map(f => ({ ...f })) };
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
      Object.entries(TYPES).map(([type, t]) =>
        el('button', { class: 'small', 'data-type': type, title: 'לחצו, ואז לחצו על המקום בדף',
          onclick: () => setPlacing(placing === type ? null : type) }, t.icon + ' ' + t.label)));
    const pagesBox = el('div', { class: 'pages' });
    const panel = el('aside', { class: 'panel card' });
    const msg = el('div');

    show(
      el('div', { class: 'editor-bar card' },
        el('a', { href: '#home', class: 'small' }, '→ התבניות שלי'),
        nameInput, status, saveBtn),
      palette, msg,
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
    try {
      const pages = await renderBackground('api.php?action=tpl_asset&id=' + id, tpl.mime);
      pages.forEach((pg, i) => addPage(pg.visual, pg.w, pg.h, i));
    } catch (e) {
      msg.replaceChildren(notice(e.message, 'bad'));
      return;
    }

    // מבנה הדפים נקבע מהקובץ בפתיחה הראשונה.
    if (!schema.pages.length) {
      schema.pages = pageEls.map(p => ({ w: p.w, h: p.h }));
      setDirty(true);
    } else {
      setDirty(false);
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
        class: 'field t-' + f.type + (selected === f ? ' selected' : '') + (f.required ? ' required' : ''),
        style: `left:${f.x * 100}%; top:${f.y * 100}%; width:${f.w * 100}%; height:${f.h * 100}%`,
        title: f.label, tabindex: 0,
      }, el('span', { class: 'field-label' }, TYPES[f.type].icon + ' ' + f.label),
        el('span', { class: 'handle', 'aria-hidden': 'true' }));
      box.addEventListener('pointerdown', (e) => startDrag(e, f, e.target.classList.contains('handle')));
      box.addEventListener('focus', () => { if (selected !== f) select(f); });
      return box;
    }

    function draw() {
      pageEls.forEach((p, i) => p.layer.replaceChildren(...schema.fields.filter(f => f.page === i).map(fieldEl)));
    }

    // גרירה: הזזה, או שינוי גודל מהידית. התנועה מחושבת כשבר מגודל הדף.
    function startDrag(e, f, resize) {
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
    }

    function removeField(f) {
      schema.fields = schema.fields.filter(x => x !== f);
      setDirty(true);
      select(null);
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
      if (!selected) {
        panel.replaceChildren(
          el('h2', {}, `שדות (${schema.fields.length})`),
          schema.fields.length
            ? el('ol', { class: 'field-list' }, schema.fields.map(f => el('li', {},
                el('button', { class: 'link', onclick: () => { select(f); scrollTo(f); } },
                  TYPES[f.type].icon + ' ' + f.label),
                el('span', { class: 'muted small' }, ' · דף ' + (f.page + 1) + (f.required ? ' · חובה' : '')))))
            : el('p', { class: 'muted small' }, 'בחרו סוג שדה למעלה, ואז לחצו על המקום בדף.'),
          el('p', { class: 'muted small' }, 'גרירה מזיזה; הפינה התחתונה משנה גודל; חצים במקלדת — הזזה עדינה; Delete — מחיקה.'),
          numberingPanel());
        return;
      }
      const f = selected;
      const input = (label, prop, attrs = {}) => {
        const i = el('input', { value: f[prop] ?? '', ...attrs });
        i.addEventListener('input', () => {
          f[prop] = attrs.type === 'number' ? Number(i.value) : i.value;
          setDirty(true);
          if (prop === 'label') pagesBox.querySelector('.field.selected .field-label').textContent = TYPES[f.type].icon + ' ' + f.label;
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
      const textual = ['text', 'textarea', 'number', 'date'].includes(f.type);

      panel.replaceChildren(
        el('div', { class: 'row' }, el('h2', {}, 'הגדרות שדה'), el('button', { class: 'link small', onclick: () => select(null) }, 'כל השדות')),
        input('תווית — מה הממלא רואה', 'label', { maxlength: 120 }),
        select_('סוג', 'type', Object.fromEntries(Object.entries(TYPES).map(([k, t]) => [k, t.icon + ' ' + t.label]))),
        el('label', { class: 'inline' }, req, ' שדה חובה'),
        input('שורת עזרה (לא חובה)', 'help', { maxlength: 300 }),
        f.type !== 'signature' ? input('ערך ברירת מחדל', 'default', { maxlength: 1000 }) : null,
        textual ? input('גודל גופן', 'fontSize', { type: 'number', min: 6, max: 72 }) : null,
        textual ? select_('יישור', 'align', { right: 'ימין', center: 'מרכז', left: 'שמאל' }) : null,
        textual ? select_('כשהטקסט ארוך מהשדה', 'overflow', OVERFLOW) : null,
        input('מפתח פנימי (לייצוא ולאקסל)', 'key', { dir: 'ltr', maxlength: 60, pattern: '[a-z0-9_]+' }),
        el('div', { class: 'ops' },
          el('button', { class: 'small', onclick: () => duplicate(f) }, 'שכפול'),
          el('button', { class: 'small danger', onclick: () => removeField(f) }, 'מחיקה')));
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

    function scrollTo(f) {
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
  }

  window.DTEditor = { open, renderBackground, TYPES };
})();
