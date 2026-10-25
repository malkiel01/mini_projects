// קול מרוקו — הסטודיו (docs/spec.md, פרקים 3–4, 11).
// התצוגה: iframe מבודד (sandbox בלי same-origin) שמקבל HTML+CSS מהרינדור בשרת — מקור אחד לתצוגה ולפרסום.
// לחיצה בתוכו → postMessage עם data-kid → בחירה. עריכה: פאנל צד במחשב, גיליון בטלפון. שמירה אוטומטית
// עם rev, נעילת עריכה עם דופק, ביטול / חזרה בזיכרון.
'use strict';

const Studio = (() => {
  const TYPES = {
    heading: { icon: '🔠', label: 'כותרת', props: () => ({ text: 'כותרת חדשה', level: 2 }) },
    text: { icon: '📝', label: 'טקסט', props: () => ({ html: '<p>טקסט חדש</p>' }) },
    button: { icon: '🔘', label: 'כפתור', props: () => ({ label: 'לחצו כאן', href: '', variant: 'solid' }) },
    divider: { icon: '➖', label: 'קו מפריד', props: () => ({}) },
    spacer: { icon: '↕️', label: 'רווח', props: () => ({ height: 32 }) },
    image: { icon: '🖼️', label: 'תמונה', media: 'image', props: () => ({ file: '', alt: '', caption: '', href: '', width: 'auto' }) },
    gallery: { icon: '🧩', label: 'גלריה', media: 'image', multiple: true, props: () => ({ files: [], cols: 3 }) },
    audio: { icon: '🎙️', label: 'הקלטה / שמע', media: 'audio', props: () => ({ file: '', title: '' }) },
    video: { icon: '🎬', label: 'וידאו', props: () => ({ file: '', embed: '' }) },
    file: { icon: '📄', label: 'קובץ להורדה', media: 'doc', props: () => ({ file: '', label: '' }) },
  };
  const fileCache = {};   // מזהה → {name, url, kind…} — מה שהספרייה כבר החזירה
  const LAYOUTS = { '100': 'אחת', '50-50': '½ ½', '33-67': '⅓ ⅔', '67-33': '⅔ ⅓', '33-33-33': '⅓ ⅓ ⅓', '25-25-25-25': '¼ × 4' };
  const PADS = { '0': 'בלי', s: 'S', m: 'M', l: 'L', xl: 'XL' };
  const SIZES = { '': 'רגיל', sm: 'קטן', lg: 'גדול', xl: 'גדול מאוד', xxl: 'ענק' };
  const FONTS = { theme: 'של האתר', sans: 'ללא תגים', serif: 'עם תגים' };
  const WIDE = matchMedia('(min-width: 1000px)');

  let S = null;                 // מצב הסטודיו הפעיל
  let exiting = Promise.resolve();  // השמירה והשחרור של היציאה הקודמת — כניסה חוזרת מחכה להם

  const uid = () => Array.from(crypto.getRandomValues(new Uint8Array(8)), b => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36]).join('');
  const clone = o => JSON.parse(JSON.stringify(o));

  // ── איתור ───────────────────────────────────────────────
  function find(id) {
    const secs = S.page.sections;
    for (let si = 0; si < secs.length; si++) {
      const sec = secs[si];
      if (sec.id === id) return { kind: 'section', sec, si };
      for (let ci = 0; ci < sec.columns.length; ci++) {
        const col = sec.columns[ci];
        if (col.id === id) return { kind: 'column', sec, si, col, ci };
        const wi = col.widgets.findIndex(w => w.id === id);
        if (wi >= 0) return { kind: 'widget', sec, si, col, ci, w: col.widgets[wi], wi };
      }
    }
    return null;
  }
  const allColumns = () => S.page.sections.flatMap(s => s.columns);

  // ── שינוי, ביטול וחזרה ──────────────────────────────────
  function mutate(fn, { coalesce = false, rebuild = true } = {}) {
    if (S.readonly) return;
    const now = Date.now();
    if (!coalesce || now - S.lastSnap > 1200) { S.undo.push(JSON.stringify(S.page)); S.redo = []; if (S.undo.length > 100) S.undo.shift(); }
    S.lastSnap = now;
    fn();
    changed(rebuild);
  }
  function changed(rebuild = true) {
    S.dirty = true;
    status();
    scheduleRender();
    scheduleSave();
    updateBar();
    if (rebuild) renderPanel();
  }
  function undo() { if (!S.undo.length || S.readonly) return; S.redo.push(JSON.stringify(S.page)); S.page = JSON.parse(S.undo.pop()); fixSel(); changed(); }
  function redo() { if (!S.redo.length || S.readonly) return; S.undo.push(JSON.stringify(S.page)); S.page = JSON.parse(S.redo.pop()); fixSel(); changed(); }
  function fixSel() { if (S.sel && !find(S.sel)) S.sel = null; }

  // ── תצוגה ───────────────────────────────────────────────
  const FRAME = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style id="base">html,body{margin:0}body{font-family:system-ui,"Segoe UI",Arial,sans-serif;color:#1d1d1f;background:#fff}
[data-kid]{cursor:pointer}.kol-col:empty{min-height:64px;outline:1px dashed #c8c8c8;outline-offset:-4px}
.kol-sel{outline:2px solid #2d7ff9!important;outline-offset:2px}.kol-sec.kol-sel{outline-offset:-2px}
.kol-empty{padding:48px 16px;text-align:center;color:#888}</style><style id="page"></style></head>
<body><div id="root"></div><script>
const root=document.getElementById('root'),page=document.getElementById('page');let sel=null;
function mark(id){document.querySelectorAll('.kol-sel').forEach(e=>e.classList.remove('kol-sel'));sel=id;
 const el=id&&document.querySelector('[data-kid="'+id+'"]');if(el){el.classList.add('kol-sel');const r=el.getBoundingClientRect();
 if(r.bottom<0||r.top>innerHeight)el.scrollIntoView({block:'center'});}}
addEventListener('message',e=>{if(e.source!==parent)return;const d=e.data||{};
 if(d.kol==='render'){page.textContent=d.css;root.innerHTML=d.html||'<p class="kol-empty">העמוד ריק. "+ רכיב" כדי להתחיל.</p>';mark(d.sel);}
 if(d.kol==='sel')mark(d.sel);});
addEventListener('click',e=>{if(!e.target.closest('audio,video'))e.preventDefault();const el=e.target.closest('[data-kid]');parent.postMessage({kol:'pick',id:el?el.dataset.kid:null},'*');},true);
parent.postMessage({kol:'ready'},'*');
<\/script></body></html>`;

  function onMessage(e) {
    if (!S || e.source !== S.frame.contentWindow) return;
    const d = e.data || {};
    if (d.kol === 'ready') { S.frameReady = true; renderNow(); }
    if (d.kol === 'pick') {
      const f = d.id && find(d.id);
      select(f ? (f.kind === 'column' ? f.sec.id : d.id) : null, true);
    }
  }

  function scheduleRender() { clearTimeout(S.rt); S.rt = setTimeout(renderNow, 220); }
  async function renderNow() {
    if (!S?.frameReady) return;
    const seq = ++S.renderSeq;
    try {
      const r = await api('render', { page: S.page });
      if (seq !== S.renderSeq) return;
      S.frame.contentWindow.postMessage({ kol: 'render', html: r.html, css: r.css, sel: S.sel }, '*');
    } catch (e) { toast(e.message, true); }
  }

  // ── שמירה ונעילה ────────────────────────────────────────
  function status() {
    const el = S.root.querySelector('.st-status');
    const [txt, cls] = S.readonly ? ['קריאה בלבד', 'warn'] : S.saving ? ['שומר…', ''] : S.error ? ['לא נשמר', 'bad'] : S.dirty ? ['שינויים…', ''] : ['נשמר ✓', 'ok'];
    el.textContent = txt; el.className = `chip st-status ${cls}`;
  }
  function scheduleSave() { clearTimeout(S.st); S.st = setTimeout(saveNow, 1500); }
  async function saveNow() {
    clearTimeout(S.st);
    if (!S || !S.dirty || S.readonly) return;
    if (S.saving) { S.again = true; return; }
    S.saving = true; S.error = false; status();
    const s = S, sent = JSON.stringify(s.page);
    try {
      const r = await api('draft_save', { page: s.page, rev: s.rev });
      s.rev = r.rev;
      if (JSON.stringify(s.page) === sent) s.dirty = false;
    } catch (e) {
      s.error = true;
      if (S !== s) return;
      if (e.status === 409 || e.status === 423) readonly(e.message);
      else toast(e.message, true);
    } finally {
      s.saving = false;
      if (S === s) { status(); if (s.again) { s.again = false; saveNow(); } else if (s.dirty && !s.readonly && !s.error) scheduleSave(); }
    }
  }

  function readonly(msg) {
    S.readonly = true;
    const b = S.root.querySelector('.st-banner');
    b.hidden = false;
    b.replaceChildren(h('span', { class: 'one' }, msg), h('button', { class: 'btn sm ghost', type: 'button', onclick: () => mount(S.host) }, '↻ רענון'));
    status(); updateBar(); renderPanel();
  }

  async function beat() {
    try {
      const r = await api('lock', { op: 'take' });
      if (!r.lock.mine && !S.readonly) readonly(`בעריכה אצל ${r.lock.by}. השינויים שלך לא יישמרו`);
    } catch { /* רשת — הדופק הבא */ }
  }

  // יציאה: שמירה אחרונה (keepalive — עוברת גם כשהדף נסגר), ובתוך האפליקציה גם שחרור הנעילה אחריה.
  // בסגירת הדף אין שחרור: בקשה שנייה לא יוצאת אחרי unload, והנעילה פגה לבד אחרי שתי דקות.
  function post(action, body) {
    return fetch(`api.php?action=${action}`, {
      method: 'POST', keepalive: true, body: JSON.stringify(body),
      headers: { 'Content-Type': 'application/json', 'X-Kol': '1', 'X-CSRF': state.csrf },
    }).catch(() => {});
  }
  function flush(s, releaseLock) {
    if (!s || s.readonly) return;
    clearTimeout(s.st);
    const save = s.dirty ? post('draft_save', { page: s.page, rev: s.rev }) : Promise.resolve();
    s.dirty = false;
    if (releaseLock) exiting = save.then(() => post('lock', { op: 'release' }));
  }
  const onPageHide = () => flush(S, false);

  // ── פעולות על המבנה ─────────────────────────────────────
  function newSection(layout = '100') {
    return { id: uid(), layout, bg: '', pad: 'l', full: false, columns: layout.split('-').map(() => ({ id: uid(), widgets: [] })) };
  }

  async function add(type) {
    if (type === 'section') {
      const sec = newSection();
      mutate(() => {
        const f = S.sel && find(S.sel);
        S.page.sections.splice(f ? f.si + 1 : S.page.sections.length, 0, sec);
      });
      select(sec.id, true);
      return;
    }
    const w = { id: uid(), type, props: TYPES[type].props(), style: {} };
    if (TYPES[type].media) {
      const files = await Media.pick({ kind: TYPES[type].media, multiple: !!TYPES[type].multiple });
      if (!S) return;
      files.forEach(f => { fileCache[f.id] = f; });
      if (type === 'gallery') w.props.files = files.map(f => f.id);
      else if (files[0]) w.props.file = files[0].id;
      if (type === 'image' && files[0]) w.props.alt = files[0].name.replace(/\.[a-z0-9]+$/i, '');
    }
    mutate(() => {
      const f = S.sel && find(S.sel);
      if (f?.kind === 'widget') f.col.widgets.splice(f.wi + 1, 0, w);
      else if (f) f.sec.columns[0].widgets.push(w);
      else {
        if (!S.page.sections.length) S.page.sections.push(newSection());
        S.page.sections.at(-1).columns[0].widgets.push(w);
      }
    });
    select(w.id, true);
  }

  function move(id, dir) {
    const f = find(id); if (!f) return;
    mutate(() => {
      if (f.kind === 'section') {
        const to = f.si + dir;
        if (to < 0 || to >= S.page.sections.length) return;
        S.page.sections.splice(f.si, 1); S.page.sections.splice(to, 0, f.sec);
        return;
      }
      const to = f.wi + dir;
      if (to >= 0 && to < f.col.widgets.length) { f.col.widgets.splice(f.wi, 1); f.col.widgets.splice(to, 0, f.w); return; }
      // בקצה העמודה — לעמודה הסמוכה (גם בפס הסמוך)
      const cols = allColumns(), k = cols.indexOf(f.col) + dir;
      if (k < 0 || k >= cols.length) return;
      f.col.widgets.splice(f.wi, 1);
      dir < 0 ? cols[k].widgets.push(f.w) : cols[k].widgets.unshift(f.w);
    });
  }

  function duplicate(id) {
    const f = find(id); if (!f) return;
    const reid = o => { o.id = uid(); (o.columns || []).forEach(c => { c.id = uid(); c.widgets.forEach(reid); }); return o; };
    const copy = reid(clone(f.kind === 'section' ? f.sec : f.w));
    mutate(() => f.kind === 'section' ? S.page.sections.splice(f.si + 1, 0, copy) : f.col.widgets.splice(f.wi + 1, 0, copy));
    select(copy.id, false);
  }

  function remove(id) {
    const f = find(id); if (!f) return;
    if (f.kind === 'section' && f.sec.columns.some(c => c.widgets.length) && !confirm('למחוק את הפס עם כל מה שבתוכו?')) return;
    mutate(() => f.kind === 'section' ? S.page.sections.splice(f.si, 1) : f.col.widgets.splice(f.wi, 1));
    S.sel = null; renderPanel(); syncSel();
    toast('נמחק · ↶ לביטול');
  }

  function setLayout(sec, layout) {
    mutate(() => {
      const n = layout.split('-').length;
      while (sec.columns.length < n) sec.columns.push({ id: uid(), widgets: [] });
      if (sec.columns.length > n) {   // הרכיבים של העמודות שנעלמו — לאחרונה שנשארת
        const extra = sec.columns.splice(n).flatMap(c => c.widgets);
        sec.columns[n - 1].widgets.push(...extra);
      }
      sec.layout = layout;
    });
  }

  // ── בחירה ופאנל ─────────────────────────────────────────
  function syncSel() { if (S.frameReady) S.frame.contentWindow.postMessage({ kol: 'sel', sel: S.sel }, '*'); }

  // openSheet — בטלפון פותח את המאפיינים בגיליון (לחיצה בתצוגה, רכיב חדש, בחירה מהמבנה)
  function select(id, openSheet) {
    S.sel = id;
    S.vsrc = null;
    syncSel();
    renderPanel();
    if (!WIDE.matches && id && openSheet) openPanelSheet();
  }

  function openPanelSheet() {
    const f = S.sel && find(S.sel);
    if (!f) return;
    const body = h('div', { class: 'st-props' });
    S.sheetBody = body;
    renderPanelInto(body);
    const dlg = sheet(f.kind === 'section' ? '🧱 פס' : `${TYPES[f.w.type].icon} ${TYPES[f.w.type].label}`, body);
    dlg.addEventListener('close', () => { S && (S.sheetBody = null); }, { once: true });
  }

  function renderPanel() {
    if (!S) return;
    if (WIDE.matches) renderPanelInto(S.side);
    else if (S.sheetBody) {
      if (S.sel && find(S.sel)) renderPanelInto(S.sheetBody);
      else $('#sheet').close();
    }
  }

  function field(label, input, cls = 'f') { return h('label', { class: cls }, h('span', {}, label), input); }

  function seg(name, options, value, onPick, cols) {
    return h('fieldset', { class: 'seg' + (cols ? ' grid' : ''), style: cols ? `--cols:${cols}` : null, 'aria-label': name },
      (Array.isArray(options) ? options : Object.entries(options)).map(([v, l]) => h('label', {},
        h('input', { type: 'radio', name: 'seg-' + name, value: v, checked: v === value, disabled: S.readonly, onchange: () => onPick(v) }),
        h('span', {}, l))));
  }

  function colorInput(label, value, onPick) {
    const none = h('small', { class: 'muted', hidden: !!value }, 'ללא');
    const inp = h('input', { type: 'color', value: value || '#ffffff', disabled: S.readonly, 'aria-label': label,
      oninput: () => { onPick(inp.value); clear.hidden = false; none.hidden = true; } });
    const clear = h('button', { type: 'button', class: 'icon-btn', 'aria-label': `${label} — ללא`, hidden: !value, disabled: S.readonly,
      onclick: () => { onPick(''); clear.hidden = true; none.hidden = false; } }, '✕');
    return h('div', { class: 'f st-color' }, h('span', {}, label), h('div', { class: 'st-color-row' }, inp, none, clear));
  }

  function actions(id) {
    const b = (ic, lbl, fn) => h('button', { type: 'button', class: 'icon-btn ghost', 'aria-label': lbl, title: lbl, disabled: S.readonly, onclick: fn }, ic);
    return h('div', { class: 'st-actions' },
      b('↑', 'למעלה', () => move(id, -1)), b('↓', 'למטה', () => move(id, 1)),
      b('⧉', 'שכפול', () => duplicate(id)), b('🗑️', 'מחיקה', () => remove(id)));
  }

  function renderPanelInto(box) {
    const f = S.sel && find(S.sel);
    if (!f) {
      box.replaceChildren(h('p', { class: 'hint' }, 'לחיצה על רכיב בתצוגה פותחת אותו לעריכה.'),
        h('p', { class: 'hint' }, '"+ רכיב" מוסיף אחרי מה שנבחר.'));
      return;
    }
    const set = (obj, key, coalesce = true) => v => mutate(() => { obj[key] = v; }, { coalesce, rebuild: false });
    if (f.kind === 'section') {
      const s = f.sec;
      box.replaceChildren(
        actions(s.id),
        h('div', { class: 'f' }, h('span', {}, 'עמודות'), seg('layout', LAYOUTS, s.layout, v => setLayout(s, v), 3)),
        h('div', { class: 'f' }, h('span', {}, 'ריפוד'), seg('pad', PADS, s.pad, v => mutate(() => { s.pad = v; }, { rebuild: false }), 5)),
        colorInput('רקע', s.bg, set(s, 'bg')),
        h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: s.full, disabled: S.readonly,
          onchange: e => mutate(() => { s.full = e.target.checked; }, { rebuild: false }) }), h('span', {}, 'לכל רוחב המסך')));
      return;
    }
    const w = f.w, p = w.props;
    const parts = [actions(w.id)];
    if (w.type === 'heading') {
      parts.push(field('הטקסט', h('input', { value: p.text, maxlength: 500, disabled: S.readonly, oninput: e => set(p, 'text')(e.target.value) })),
        h('div', { class: 'f' }, h('span', {}, 'רמה'), seg('level', { 1: 'H1', 2: 'H2', 3: 'H3', 4: 'H4' }, String(p.level), v => set(p, 'level', false)(+v))));
    } else if (w.type === 'text') {
      parts.push(richText(p));
    } else if (w.type === 'button') {
      parts.push(field('הכיתוב', h('input', { value: p.label, maxlength: 120, disabled: S.readonly, oninput: e => set(p, 'label')(e.target.value) })),
        field('קישור', h('input', { value: p.href, dir: 'ltr', inputmode: 'url', placeholder: 'https://', disabled: S.readonly,
          oninput: e => set(p, 'href')(e.target.value.trim()) })),
        h('div', { class: 'f' }, h('span', {}, 'סגנון'), seg('variant', { solid: 'מלא', outline: 'מסגרת' }, p.variant, v => set(p, 'variant', false)(v))));
    } else if (w.type === 'spacer') {
      parts.push(field('גובה (פיקסלים)', h('input', { type: 'number', min: 4, max: 400, value: p.height, dir: 'ltr', disabled: S.readonly,
        oninput: e => set(p, 'height')(Math.max(4, Math.min(400, +e.target.value || 4))) })));
    }
    else if (TYPES[w.type].media || w.type === 'video') parts.push(...mediaFields(w));
    if (w.type !== 'spacer') parts.push(styleGroup(w));
    parts.push(h('button', { type: 'button', class: 'btn sm ghost st-up', onclick: () => select(f.sec.id, false) }, '🧱 הפס שמכיל אותו'));
    box.replaceChildren(...parts);
  }

  // ── שדות של רכיבי קבצים ─────────────────────────────────
  async function fileInfo(id) {
    if (!id) return null;
    if (!fileCache[id]) {
      try { (await api('files')).files.forEach(f => { fileCache[f.id] = f; }); } catch { /* מוצג כחסר */ }
    }
    return fileCache[id] || null;
  }

  function fileSlot(kind, id, onPick, label) {
    const box = h('div', { class: 'file-slot' }, h('span', { class: 'muted' }, '…'));
    const choose = async () => {
      const [f] = await Media.pick({ kind });
      if (f) { fileCache[f.id] = f; onPick(f); }
    };
    fileInfo(id).then(f => {
      box.replaceChildren(
        f ? (f.kind === 'image' ? h('img', { class: 'thumb lg', src: f.url, alt: '' }) : h('span', { class: 'use-ic', 'aria-hidden': 'true' }, TYPES[{ audio: 'audio', video: 'video', doc: 'file' }[f.kind]].icon)) : null,
        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, f ? f.name : id ? 'הקובץ חסר' : 'לא נבחר'), f ? h('small', {}, Media.mb(f.size)) : null),
        h('button', { type: 'button', class: 'btn sm ghost', disabled: S.readonly, onclick: choose }, f ? '🔁 החלפה' : label));
    });
    return box;
  }

  function mediaFields(w) {
    const p = w.props;
    const set = (k, coalesce = true) => v => mutate(() => { p[k] = v; }, { coalesce, rebuild: false });
    const setNow = (k, v) => mutate(() => { p[k] = v; });                       // עם בנייה מחדש של הפאנל
    const input = (label, k, attrs = {}) => field(label, h('input', { value: p[k], disabled: S.readonly, ...attrs, oninput: e => set(k)(e.target.value) }));
    switch (w.type) {
      case 'image': return [
        fileSlot('image', p.file, f => setNow('file', f.id), '🖼️ בחירה'),
        input('טקסט חלופי (לעיוורים ולמנועי חיפוש) *', 'alt', { maxlength: 300 }),
        input('כיתוב מתחת לתמונה', 'caption', { maxlength: 500 }),
        input('קישור בלחיצה', 'href', { dir: 'ltr', inputmode: 'url', placeholder: 'https://' }),
        h('div', { class: 'f' }, h('span', {}, 'רוחב'), seg('iwidth', { auto: 'מקורי', full: 'לכל הרוחב' }, p.width, v => set('width', false)(v))),
      ];
      case 'gallery': {
        const list = h('div', { class: 'gal-edit' });
        Promise.all(p.files.map(fileInfo)).then(fs => list.replaceChildren(...fs.map((f, i) => h('div', { class: 'gal-item' },
          f ? h('img', { class: 'thumb', src: f.url, alt: '' }) : h('span', { class: 'thumb' }, '?'),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'הסרה מהגלריה', disabled: S.readonly,
            onclick: () => setNow('files', p.files.filter((_, j) => j !== i)) }, '✕')))));
        return [
          h('div', { class: 'f' }, h('span', {}, `תמונות · ${p.files.length}`), list),
          h('button', { type: 'button', class: 'btn ghost', disabled: S.readonly, onclick: async () => {
            const fs = await Media.pick({ kind: 'image', multiple: true });
            fs.forEach(f => { fileCache[f.id] = f; });
            if (fs.length) setNow('files', [...p.files, ...fs.map(f => f.id).filter(id => !p.files.includes(id))]);
          } }, '+ תמונות'),
          h('div', { class: 'f' }, h('span', {}, 'עמודות'), seg('gcols', [['2', '2'], ['3', '3'], ['4', '4']], String(p.cols), v => set('cols', false)(+v))),
        ];
      }
      case 'audio': return [
        fileSlot('audio', p.file, f => { setNow('file', f.id); if (!p.title) setNow('title', f.name.replace(/\.[a-z0-9]+$/i, '')); }, '🎙️ בחירה / הקלטה'),
        input('כותרת מעל הנגן', 'title', { maxlength: 200 }),
      ];
      case 'video': {
        const src = p.embed ? 'link' : 'file';
        return [
          h('div', { class: 'f' }, h('span', {}, 'מקור'), seg('vsrc', { file: '⬆️ קובץ', link: '🔗 יוטיוב / וימאו' }, src,
            v => mutate(() => { if (v === 'file') p.embed = ''; else p.file = ''; S.vsrc = v; }))),
          (S.vsrc || src) === 'link'
            ? field('קישור לסרטון', h('input', { value: p.embed ? (p.embed.startsWith('youtube:') ? 'https://youtu.be/' + p.embed.slice(8) : 'https://vimeo.com/' + p.embed.slice(6)) : '',
              dir: 'ltr', inputmode: 'url', placeholder: 'https://youtu.be/…', disabled: S.readonly, onchange: e => {
                const v = embedOf(e.target.value.trim());
                if (e.target.value.trim() && !v) toast('לא זיהיתי קישור של יוטיוב או וימאו', true);
                mutate(() => { p.embed = v; p.file = ''; });
              } }))
            : fileSlot('video', p.file, f => mutate(() => { p.file = f.id; p.embed = ''; }), '🎬 בחירה'),
          h('p', { class: 'hint' }, 'וידאו עד 50MB. סרטון ארוך — עדיף ביוטיוב, ולהדביק קישור.'),
        ];
      }
      case 'file': return [
        fileSlot('doc', p.file, f => { setNow('file', f.id); }, '📄 בחירה'),
        input('הכיתוב בקישור (ריק — שם הקובץ)', 'label', { maxlength: 200 }),
      ];
    }
    return [];
  }

  function embedOf(url) {
    let m = url.match(/^https:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
    if (m) return 'youtube:' + m[1];
    m = url.match(/^https:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?(\d{5,12})/);
    return m ? 'vimeo:' + m[1] : '';
  }

  function styleGroup(w) {
    const s = w.style;
    const set = (k, coalesce = false) => v => mutate(() => { if (v === '' || v === false) delete s[k]; else s[k] = v; }, { coalesce, rebuild: false });
    const g = [h('h3', {}, 'עיצוב')];
    if (w.type !== 'divider') {
      g.push(h('div', { class: 'f' }, h('span', {}, 'יישור'), seg('align', [['', 'רגיל'], ['start', 'ימין'], ['center', 'מרכז'], ['end', 'שמאל']], s.align || '', set('align'), 4)),
        h('div', { class: 'f' }, h('span', {}, 'גודל'), seg('size', Object.entries(SIZES), s.size || '', set('size'), 5)));
    }
    g.push(h('div', { class: 'fields two' }, colorInput('צבע', s.color, set('color', true)), colorInput('רקע', s.bg, set('bg', true))),
      h('div', { class: 'f' }, h('span', {}, 'ריפוד'), seg('wpad', [['', 'רגיל'], ...Object.entries(PADS)], s.pad ?? '', set('pad'), 6)),
      h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!s.hide_mobile, disabled: S.readonly, onchange: e => set('hide_mobile')(e.target.checked) }), h('span', {}, 'מוסתר בטלפון')),
      h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!s.hide_desktop, disabled: S.readonly, onchange: e => set('hide_desktop')(e.target.checked) }), h('span', {}, 'מוסתר במחשב')));
    return h('section', { class: 'group' }, g);
  }

  // טקסט עשיר: contenteditable קטן. השרת מסנן הכול (allowlist) — כאן רק נוחות.
  function richText(p) {
    const ed = h('div', { class: 'rte', contenteditable: S.readonly ? 'false' : 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': 'הטקסט', dir: 'auto' });
    ed.innerHTML = p.html;
    const sync = () => mutate(() => { p.html = ed.innerHTML; }, { coalesce: true, rebuild: false });
    ed.addEventListener('input', sync);
    ed.addEventListener('paste', e => {                    // הדבקה — כטקסט, בלי העיצוב של המקור
      e.preventDefault();
      document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
    });
    const cmd = (c, v) => { ed.focus(); document.execCommand(c, false, v); sync(); };
    const b = (ic, lbl, fn) => h('button', { type: 'button', class: 'icon-btn', 'aria-label': lbl, title: lbl, disabled: S.readonly,
      onmousedown: e => e.preventDefault(), onclick: fn }, ic);
    const bar = h('div', { class: 'rte-bar' },
      b(h('b', {}, 'B'), 'מודגש', () => cmd('bold')), b(h('i', {}, 'I'), 'נטוי', () => cmd('italic')), b(h('u', {}, 'U'), 'קו תחתון', () => cmd('underline')),
      b('•', 'רשימה', () => cmd('insertUnorderedList')), b('1.', 'רשימה ממוספרת', () => cmd('insertOrderedList')),
      b('🔗', 'קישור', () => { const u = prompt('כתובת הקישור (https://…)', 'https://'); if (u) cmd('createLink', u.trim()); }),
      b('⌫', 'ניקוי עיצוב', () => { cmd('removeFormat'); cmd('unlink'); }));
    return h('div', { class: 'f' }, h('span', {}, 'הטקסט'), h('div', { class: 'rte-box' }, bar, ed));
  }

  // ── גיליונות: הוספה, מבנה, היסטוריה, הגדרות ─────────────
  function addSheet() {
    const tile = (type, icon, label) => h('button', { type: 'button', class: 'tile', onclick: () => { $('#sheet').close(); add(type); } },
      h('span', { class: 'tile-ic', 'aria-hidden': 'true' }, icon), label);
    sheet('הוספת רכיב',
      h('p', { class: 'hint' }, S.sel ? 'נכנס אחרי מה שנבחר.' : 'נכנס בסוף העמוד.'),
      h('div', { class: 'tiles' }, Object.entries(TYPES).map(([t, d]) => tile(t, d.icon, d.label)), tile('section', '🧱', 'פס חדש')));
  }

  function structureSheet() {
    const row = (id, icon, title, sub, depth) => h('li', {}, h('button', { type: 'button', class: 'use-row' + (id === S.sel ? ' on' : ''), style: `padding-inline-start:${12 + depth * 20}px`,
      onclick: () => { $('#sheet').close(); select(id, true); } },
      h('span', { class: 'use-ic', 'aria-hidden': 'true' }, icon), h('span', { class: 'use-tx' }, h('b', { class: 'one' }, title), sub ? h('small', {}, sub) : null),
      h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
    const rows = [];
    S.page.sections.forEach((s, i) => {
      rows.push(row(s.id, '🧱', `פס ${i + 1}`, `${LAYOUTS[s.layout]} · ${s.columns.reduce((n, c) => n + c.widgets.length, 0)} רכיבים`, 0));
      s.columns.forEach(c => c.widgets.forEach(w => rows.push(row(w.id, TYPES[w.type].icon, preview(w), TYPES[w.type].label, 1))));
    });
    sheet('🧱 מבנה העמוד', rows.length ? h('ul', { class: 'list' }, rows) : h('p', { class: 'empty' }, 'העמוד ריק.'));
  }

  function preview(w) {
    if (w.type === 'heading') return w.props.text || 'כותרת';
    if (w.type === 'text') { const d = document.createElement('div'); d.innerHTML = w.props.html; return d.textContent.trim().slice(0, 80) || 'טקסט'; }
    if (w.type === 'button') return w.props.label || 'כפתור';
    if (w.type === 'spacer') return `${w.props.height}px`;
    if (w.type === 'gallery') return `${w.props.files.length} תמונות`;
    if (w.type === 'audio') return w.props.title || fileCache[w.props.file]?.name || 'שמע';
    if (w.type === 'image') return w.props.alt || fileCache[w.props.file]?.name || 'תמונה';
    if (w.type === 'file') return w.props.label || fileCache[w.props.file]?.name || 'קובץ';
    return TYPES[w.type].label;
  }

  async function historySheet() {
    const body = h('div', {}, h('p', { class: 'empty' }, 'טוען…'));
    sheet('🕘 היסטוריה', body);
    try {
      await saveNow();
      const { revisions } = await api('revisions');
      body.replaceChildren(
        h('p', { class: 'hint' }, 'נקודה לכל עורך בכל 10 דקות. שחזור מחליף את הטיוטה, ואפשר לחזור ממנו.'),
        revisions.length ? h('ul', { class: 'list' }, revisions.map((r, i) => h('li', {}, h('button', {
          type: 'button', class: 'use-row', disabled: S.readonly || i === 0,
          onclick: async () => {
            if (!confirm('לשחזר את הטיוטה לנקודה הזו?')) return;
            try {
              const res = await api('revision_restore', { id: r.id, rev: S.rev });
              S.undo.push(JSON.stringify(S.page)); S.page = res.page; S.rev = res.rev; S.dirty = false; fixSel();
              $('#sheet').close(); renderNow(); renderPanel(); status(); toast('שוחזר');
            } catch (e) { toast(e.message, true); }
          },
        }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, r.note ? '↺' : '💾'),
          h('span', { class: 'use-tx' }, h('b', {}, new Date(r.at).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Jerusalem' })),
            h('small', {}, i === 0 ? `${r.name} · הנוכחית` : r.note ? `${r.name} · ${r.note.split('-')[0]}` : r.name)),
          i === 0 ? null : h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))))) : h('p', { class: 'empty' }, 'עוד אין שמירות.'));
    } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  function settingsSheet() {
    const st = S.page.settings;
    const set = (k, coalesce = true) => v => mutate(() => { st[k] = v; }, { coalesce, rebuild: false });
    sheet('⚙️ הגדרות העמוד',
      field('כותרת העמוד (לדפדפן)', h('input', { value: st.title, maxlength: 200, disabled: S.readonly, oninput: e => set('title')(e.target.value) })),
      h('div', { class: 'f' }, h('span', {}, 'גופן'), seg('font', FONTS, st.font, set('font', false))),
      field('רוחב מרבי (פיקסלים)', h('input', { type: 'number', min: 600, max: 1600, step: 20, value: st.max_width, dir: 'ltr', disabled: S.readonly,
        onchange: e => set('max_width', false)(Math.max(600, Math.min(1600, +e.target.value || 1140))) })),
      h('div', { class: 'fields two' }, colorInput('צבע טקסט', st.text, set('text')), colorInput('רקע העמוד', st.bg, set('bg'))),
      colorInput('צבע הדגשה (קישורים, כפתורים)', st.accent, v => set('accent')(v || '#a3322a')));
  }

  // ── סרגל ומבנה המסך ─────────────────────────────────────
  function updateBar() {
    const q = s => S.root.querySelector(s);
    q('[data-a=undo]').disabled = S.readonly || !S.undo.length;
    q('[data-a=redo]').disabled = S.readonly || !S.redo.length;
    q('[data-a=add]').disabled = S.readonly;
  }

  function onKey(e) {
    if (!S || e.target.closest('input, textarea, [contenteditable=true]')) return;
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); }
    else if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && S.sel && !$('#sheet').open) { e.preventDefault(); remove(S.sel); }
  }

  function onHide() { if (document.visibilityState === 'hidden') saveNow(); }

  async function mount(host) {
    unmount();
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען את הטיוטה…'));
    await exiting;
    let d;
    try { d = await api('draft'); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const btn = (a, ic, lbl, fn, cls = 'icon-btn') => h('button', { type: 'button', class: cls, 'data-a': a, 'aria-label': lbl, title: lbl, onclick: fn }, ic);
    const frame = h('iframe', { class: 'st-frame', title: 'תצוגת העמוד', sandbox: 'allow-scripts' });
    const side = h('aside', { class: 'st-side', 'aria-label': 'מאפייני הרכיב' });
    const device = h('fieldset', { class: 'seg st-device', 'aria-label': 'רוחב התצוגה' },
      [['phone', '📱'], ['desktop', '🖥️']].map(([v, ic], i) => h('label', {}, h('input', { type: 'radio', name: 'device', value: v, checked: i === 1,
        onchange: () => root.classList.toggle('phone', v === 'phone') }), h('span', { title: v === 'phone' ? 'טלפון' : 'מחשב' }, ic))));
    const root = h('div', { class: 'studio' },
      h('div', { class: 'st-bar' },
        btn('add', h('span', {}, h('span', { 'aria-hidden': 'true' }, '+'), ' רכיב'), 'הוספת רכיב', addSheet, 'btn primary'),
        btn('undo', '↶', 'ביטול', undo), btn('redo', '↷', 'חזרה', redo),
        btn('tree', '🧱', 'מבנה העמוד', structureSheet), btn('hist', '🕘', 'היסטוריה', historySheet), btn('set', '⚙️', 'הגדרות העמוד', settingsSheet),
        device, h('span', { class: 'chip st-status' })),
      h('div', { class: 'st-banner', role: 'status', hidden: true }),
      h('div', { class: 'st-main' }, h('div', { class: 'st-canvas' }, frame), side));
    host.replaceChildren(root);
    document.body.classList.add('studio-on');
    root.style.height = `calc(100dvh - ${Math.round(root.getBoundingClientRect().top + scrollY)}px)`;   // עד תחתית המסך, מתחת לכותרת וללשוניות

    S = { host, root, frame, side, page: d.page, rev: d.rev, sel: null, undo: [], redo: [], lastSnap: 0, dirty: false, saving: false,
      error: false, readonly: false, frameReady: false, renderSeq: 0, sheetBody: null };
    window.addEventListener('message', onMessage);
    document.addEventListener('keydown', onKey);
    document.addEventListener('visibilitychange', onHide);
    window.addEventListener('pagehide', onPageHide);
    WIDE.addEventListener('change', renderPanel);
    frame.srcdoc = FRAME;
    status(); updateBar(); renderPanel();

    const lock = d.lock.mine ? d.lock : (await api('lock', { op: 'take' }).catch(() => ({ lock: { mine: false, by: '?' } }))).lock;
    if (!lock.mine) readonly(`בעריכה אצל ${lock.by}. אפשר לצפות; העריכה תיפתח כשהוא יסיים`);
    S.beat = setInterval(beat, 30_000);
  }

  function unmount() {
    if (!S) return;
    flush(S, true);
    clearInterval(S.beat); clearTimeout(S.rt);
    window.removeEventListener('message', onMessage);
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('visibilitychange', onHide);
    window.removeEventListener('pagehide', onPageHide);
    WIDE.removeEventListener('change', renderPanel);
    document.body.classList.remove('studio-on');
    S = null;
  }

  return { mount, unmount };
})();
