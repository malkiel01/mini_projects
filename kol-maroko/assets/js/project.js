// קול מרוקו — 📁 פרויקט (פרוסה F2a; lib/project.php): עץ הקבצים של העמוד ועורך קוד רב-לשוניות.
// S4 / S6: התוכן מגיע כנתונים (JSON) ונכנס רק לעורך. שם קובץ ותוכן קובץ מוצגים כטקסט בלבד — דרך h() / textContent,
// ואין בקובץ הזה innerHTML. העורך (Monaco) נטען מ-assets/vendor/monaco, לא מ-CDN.
'use strict';

const Project = (() => {
  const VS = new URL('assets/vendor/monaco/vs', location.href).href;
  const LS = 'kol-project';
  const AUTOSAVE_MS = 1500;
  const LANGS = { html: 'html', htm: 'html', css: 'css', js: 'javascript', mjs: 'javascript', cjs: 'javascript', json: 'json', webmanifest: 'json', map: 'json',
    svg: 'xml', xml: 'xml', md: 'markdown', markdown: 'markdown', ts: 'typescript', scss: 'scss', less: 'less', yml: 'yaml', yaml: 'yaml', php: 'php',
    sql: 'sql', ini: 'ini', sh: 'shell' };
  const ICONS = { html: '🌐', htm: '🌐', css: '🎨', scss: '🎨', less: '🎨', js: '📜', mjs: '📜', ts: '📜', json: '🧩', svg: '🖼️', md: '📝', txt: '📝',
    png: '🖼️', jpg: '🖼️', jpeg: '🖼️', gif: '🖼️', webp: '🖼️', avif: '🖼️', ico: '🖼️', mp3: '🎙️', m4a: '🎙️', wav: '🎙️', ogg: '🎙️', aac: '🎙️', mp4: '🎬', webm: '🎬',
    woff: '🔤', woff2: '🔤', ttf: '🔤', otf: '🔤', pdf: '📕', zip: '🗜️' };
  const IMG = /^(png|jpe?g|gif|webp|avif|ico|bmp|svg)$/, AUD = /^(mp3|m4a|aac|ogg|wav|weba)$/, VID = /^(mp4|webm)$/;
  const MEDIA_UP = /^(image\/(jpeg|png|webp|gif)|audio\/|video\/(mp4|webm|quicktime))/;

  // מצב שנשאר בין ביקורים במסך (המודלים חיים גם כשעוברים ללשונית אחרת — שינוי שלא נשמר אינו הולך לאיבוד)
  const S = { files: [], byId: new Map(), trash: 0, limits: {}, kol: null, canPurge: false, docs: new Map(), tabs: [], cur: null, editor: null, host: null, loaded: false };
  let monaco = null, prefs = load();

  function load() {
    try { return Object.assign({ tabs: [], cur: null, views: {}, closed: [], font: 14, wrap: true }, JSON.parse(localStorage.getItem(LS) || '{}')); }
    catch { return { tabs: [], cur: null, views: {}, closed: [], font: 14, wrap: true }; }
  }
  function persist() {
    prefs.tabs = S.tabs; prefs.cur = S.cur;
    try { localStorage.setItem(LS, JSON.stringify(prefs)); } catch { /* אחסון מלא או חסום — לא חוסם עבודה */ }
  }

  const ext = p => { const n = p.slice(p.lastIndexOf('/') + 1), i = n.lastIndexOf('.'); return i > 0 ? n.slice(i + 1).toLowerCase() : ''; };
  const base = p => p.slice(p.lastIndexOf('/') + 1);
  const parent = p => (p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '');
  const join = (dir, name) => (dir ? dir + '/' + name : name);
  const size = n => (n >= 1048576 ? (n / 1048576).toFixed(1) + 'MB' : n >= 1024 ? Math.round(n / 1024) + 'KB' : n + 'B');
  const rawUrl = (f, dl) => `api.php?action=pf_raw&id=${f.id}${dl ? '&download=1' : ''}&v=${f.rev}`;
  const phone = () => matchMedia('(max-width: 999px)').matches;
  const dark = () => matchMedia('(prefers-color-scheme: dark)').matches;

  // ── Monaco, מהאפליקציה עצמה ───────────────────────────────
  function loadMonaco() {
    if (monaco) return Promise.resolve(monaco);
    if (window.monaco) return Promise.resolve(monaco = window.monaco);
    return new Promise((ok, fail) => {
      const t = setTimeout(() => fail(new Error('העורך לא נטען')), 20000);
      window.MonacoEnvironment = { getWorkerUrl: () => VS + '/base/worker/workerMain.js' };
      const done = () => {
        window.require.config({ paths: { vs: VS } });
        window.require(['vs/editor/editor.main'], m => { clearTimeout(t); ok(monaco = m); }, e => { clearTimeout(t); fail(e); });
      };
      if (window.require?.config) return done();
      const s = document.createElement('script');
      s.src = VS + '/loader.js';
      s.onerror = () => { clearTimeout(t); fail(new Error('העורך לא נטען')); };
      s.onload = done;
      document.head.append(s);
    });
  }

  // ── נתונים ────────────────────────────────────────────────
  async function refresh() {
    const d = await api('pf_tree');
    S.files = d.files; S.trash = d.trash; S.limits = d.limits; S.kol = d.kol; S.canPurge = d.can_purge; S.loaded = true;
    S.byId = new Map(d.files.map(f => [f.id, f]));
    // לשונית של קובץ שנמחק או שונה שמו — מתעדכנת
    S.tabs = S.tabs.filter(id => S.byId.has(id) || S.docs.get(id)?.dirty);
    if (!S.tabs.includes(S.cur)) S.cur = S.tabs[S.tabs.length - 1] ?? null;
    drawAll();
  }

  /** העץ: תיקיות (גם כאלה שקיימות רק כי יש בהן קבצים) וקבצים, ממוינים — תיקיות קודם. */
  function tree() {
    const root = { path: '', dirs: new Map(), files: [] };
    const dir = p => {
      let n = root;
      if (p) for (const seg of p.split('/')) {
        if (!n.dirs.has(seg)) n.dirs.set(seg, { path: join(n.path, seg), name: seg, dirs: new Map(), files: [], row: null });
        n = n.dirs.get(seg);
      }
      return n;
    };
    for (const f of S.files) {
      if (f.kind === 'dir') dir(f.path).row = f;
      else dir(parent(f.path)).files.push(f);
    }
    return root;
  }

  // ── לשוניות ומסמכים ───────────────────────────────────────
  const isText = d => d && d.model;
  const dirty = id => !!S.docs.get(id)?.dirty;

  async function openFile(id, activate = true) {
    const f = S.byId.get(id);
    if (!f || f.kind === 'dir') return;
    if (!S.tabs.includes(id)) S.tabs.push(id);
    if (activate) S.cur = id;
    persist();
    if (!S.docs.has(id)) {
      const doc = { id, rev: f.rev, dirty: false, model: null, saved: '', binary: false, saving: false, timer: 0, conflict: false };
      S.docs.set(id, doc);
      if (f.kind === 'file' && !(IMG.test(ext(f.path)) && ext(f.path) !== 'svg') && !AUD.test(ext(f.path)) && !VID.test(ext(f.path))) {
        try {
          const d = await api('pf_read', undefined, `&id=${id}`);
          doc.rev = d.file.rev;
          if (d.binary) doc.binary = true;
          else {
            doc.saved = d.content;
            if (monaco) {
              doc.model = monaco.editor.createModel(d.content, LANGS[ext(f.path)] || 'plaintext');
              doc.model.onDidChangeContent(() => changed(doc));
            } else doc.text = d.content;                       // העורך לא נטען — תיבת טקסט פשוטה
          }
        } catch (e) { S.docs.delete(id); S.tabs = S.tabs.filter(x => x !== id); if (S.cur === id) S.cur = S.tabs[0] ?? null; persist(); toast(e.message, true); }
      } else doc.binary = true;
    }
    drawAll();
  }

  function changed(doc) {
    emit(doc, 'text');
    const now = doc.model ? doc.model.getValue() : doc.text;
    const was = doc.dirty;
    doc.dirty = now !== doc.saved;
    if (was !== doc.dirty) { drawTabs(); drawTree(); }
    drawStatus();
    clearTimeout(doc.timer);
    if (doc.dirty && !doc.conflict) doc.timer = setTimeout(() => save(doc.id, true), AUTOSAVE_MS);
  }

  // ── שירות המסמכים (04 §1) ─────────────────────────────────
  // מקור אמת אחד לכל קובץ פתוח: הטקסט, ההיסטוריה (ביטול / חזרה) וה-rev. עורך הקוד והעורך החזותי (assets/js/editor)
  // עובדים על אותו מסמך — תיקון מכאן או משם נכנס לאותה היסטוריה, ונשמר באותה שמירה (pf_write, אותו rev, אותן גרסאות).
  const subs = new Map();
  function emit(doc, kind) { for (const cb of subs.get(doc.id) || []) { try { cb(kind); } catch (e) { console.error(e); } } }
  function handle(doc) {
    const text = () => (doc.model ? doc.model.getValue() : doc.text ?? '');
    return {
      id: doc.id, text,
      get path() { return S.byId.get(doc.id)?.path || ''; },
      get rev() { return doc.rev; }, get dirty() { return doc.dirty; }, get saving() { return doc.saving; }, get conflict() { return doc.conflict; },
      get binary() { return doc.binary; }, get error() { return doc.error || ''; }, get errorStatus() { return doc.errorStatus || 0; },
      get lastTx() { return doc.tx?.[doc.tx.length - 1] || null; },
      /** עסקה עם שם: תיקוני טקסט {from, to, text} (מיקומים בטקסט הנוכחי) כצעד אחד בהיסטוריה. */
      edit(patches, label) {
        if (doc.binary) throw new Error('הקובץ פתוח לקריאה בלבד');
        if (doc.model) {
          const m = doc.model, pos = at => m.getPositionAt(at);
          m.pushStackElement();
          m.pushEditOperations(null, patches.map(p => { const a = pos(p.from), b = pos(p.to); return { range: new monaco.Range(a.lineNumber, a.column, b.lineNumber, b.column), text: p.text, forceMoveMarkers: true }; }), () => null);
          m.pushStackElement();
        } else {
          const ps = patches.slice().sort((a, b) => a.from - b.from), before = doc.text; let out = '', at = 0;
          for (const p of ps) { out += before.slice(at, p.from) + p.text; at = p.to; }
          (doc.undo = doc.undo || []).push(before); doc.redo = [];
          doc.text = out + before.slice(at); changed(doc);
        }
        (doc.tx = doc.tx || []).push({ label, at: Date.now() }); if (doc.tx.length > 100) doc.tx.shift();
      },
      undo() { if (doc.model) doc.model.undo(); else if (doc.undo?.length) { (doc.redo = doc.redo || []).push(doc.text); doc.text = doc.undo.pop(); changed(doc); } },
      redo() { if (doc.model) doc.model.redo(); else if (doc.redo?.length) { doc.undo.push(doc.text); doc.text = doc.redo.pop(); changed(doc); } },
      canUndo() { return doc.model ? doc.model.canUndo() : !!doc.undo?.length; },
      canRedo() { return doc.model ? doc.model.canRedo() : !!doc.redo?.length; },
      on(cb) { if (!subs.has(doc.id)) subs.set(doc.id, new Set()); subs.get(doc.id).add(cb); return () => subs.get(doc.id)?.delete(cb); },
      save: () => save(doc.id),
    };
  }
  const Docs = {
    /** כל קובצי הפרויקט (אחרי טעינת העץ). */
    async files() { if (!S.loaded) await refresh(); return S.files; },
    byPath: path => S.files.find(f => f.path === path && f.kind !== 'dir') || null,
    /** פותח קובץ כמסמך משותף (וכלשונית בעורך הקוד). מחזיר null כשאי אפשר לפתוח. */
    async open(id) {
      if (!S.loaded) await refresh();
      try { await loadMonaco(); } catch { /* בלי Monaco — מסמך טקסט פשוט, עם היסטוריה משלו */ }
      if (!S.docs.has(id)) { const cur = S.cur; await openFile(id, false); S.cur = cur ?? id; persist(); }
      const doc = S.docs.get(id);
      return doc ? handle(doc) : null;
    },
    /** הבייטים של קובץ שאינו UTF-8 — לתצוגה בלבד. */
    async bytes(id) { const d = await api('pf_read', undefined, `&id=${id}`); return d.binary ? Uint8Array.from(atob(d.b64), c => c.charCodeAt(0)) : new TextEncoder().encode(d.content); },
  };

  /** שמירה. הקוד נשמר כמו שהוא — גם שבור; אין כאן שום בדיקת תקינות שמונעת שמירה. */
  async function save(id, auto = false) {
    const doc = S.docs.get(id);
    if (!doc || !doc.dirty || doc.saving || (auto && doc.conflict)) return;
    const content = doc.model ? doc.model.getValue() : doc.text;
    doc.saving = true; drawStatus();
    try {
      const d = await api('pf_write', { id, rev: doc.rev, content, auto });
      doc.rev = d.file.rev; doc.saved = content; doc.conflict = false;
      const f = S.byId.get(id);
      if (f) Object.assign(f, d.file);
      if (d.kol) { S.kol = d.kol; drawBars(); }
      if (f) bc?.postMessage({ t: 'saved', path: f.path });       // התצוגה החיה מתרעננת (assets/js/live.js)
    } catch (e) {
      if (e.status === 409 && e.data?.conflict) { doc.conflict = true; conflictSheet(doc, content, e.data.conflict); }
      else if (!auto) toast(e.message, true);
      doc.error = e.message; doc.errorStatus = e.status || 0;
    } finally {
      doc.saving = false;
      const now = doc.model ? doc.model.getValue() : doc.text;
      doc.dirty = now !== doc.saved;
      if (!doc.dirty) { doc.error = ''; doc.errorStatus = 0; }
      drawTabs(); drawTree(); drawStatus(); emit(doc, 'state');
      if (doc.dirty && !doc.conflict && !doc.error) { clearTimeout(doc.timer); doc.timer = setTimeout(() => save(id, true), AUTOSAVE_MS); }
    }
  }

  /** "הקובץ השתנה בינתיים" — שתי הגרסאות זו מול זו, והבחירה בידי העורך. שום דבר לא נדרס בשקט. */
  function conflictSheet(doc, mine, theirs) {
    const f = S.byId.get(doc.id);
    const box = h('div', { class: 'pj-diff', dir: 'ltr' });
    const dlg = sheet('הקובץ השתנה בינתיים',
      h('p', { class: 'hint' }, `${f ? f.path : ''} נשמר בינתיים בידי ${theirs.file.by || 'מישהו אחר'} (${when(theirs.file.at)}). למטה — שתי הגרסאות.`),
      box,
      h('div', { class: 'row-btns wrap' },
        h('button', { type: 'button', class: 'btn primary', onclick: () => { doc.rev = theirs.file.rev; doc.conflict = false; dlg.close(); save(doc.id); } }, 'לשמור את שלי'),
        h('button', { type: 'button', class: 'btn', onclick: () => {
          const text = theirs.binary ? '' : theirs.content;
          doc.rev = theirs.file.rev; doc.saved = text; doc.conflict = false;
          if (doc.model) doc.model.setValue(text); else { doc.text = text; changed(doc); drawEditor(); }
          dlg.close();
        } }, 'לקחת את הגרסה מהשרת'),
        h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'אחר כך')));
    // שתי תיבות לקריאה בלבד (עורך ההשוואה של Monaco, כשהוא נסגר, משבית את העורך הראשי — לכן לא כאן; diff מלא ב-F2b)
    const pane = (label, text) => h('label', { class: 'f' }, h('span', {}, label), h('textarea', { class: 'code-area', readonly: true, rows: 9, spellcheck: 'false' }, text));
    box.append(pane('בשרת', theirs.binary ? '(קובץ בינארי)' : theirs.content), pane('שלך', mine));
    drawStatus();
  }

  function closeTab(id) {
    const doc = S.docs.get(id);
    if (doc?.dirty && !confirm(`ב-"${base(S.byId.get(id)?.path || '')}" יש שינויים שלא נשמרו. לסגור בלי לשמור?`)) return;
    if (doc) { clearTimeout(doc.timer); doc.model?.dispose(); S.docs.delete(id); }
    delete prefs.views[id];
    const i = S.tabs.indexOf(id);
    S.tabs = S.tabs.filter(x => x !== id);
    if (S.cur === id) S.cur = S.tabs[Math.min(i, S.tabs.length - 1)] ?? null;
    persist(); drawAll();
  }

  function keepView() {
    if (S.editor && S.editor.getModel()) {
      const id = [...S.docs.values()].find(d => d.model === S.editor.getModel())?.id;
      if (id) { prefs.views[id] = S.editor.saveViewState(); persist(); }
    }
  }

  // ── ציור ──────────────────────────────────────────────────
  const el = {};
  function drawAll() { if (!S.host) return; drawBars(); drawTree(); drawTabs(); drawEditor(); drawStatus(); }

  function drawBars() {
    if (!S.host) return;                                         // המסך אינו מוצג (המסמך פתוח בעורך החזותי) — אין מה לצייר
    const k = S.kol;
    el.kol.hidden = !k || k.valid;
    if (k && !k.valid) {
      const line = kolLine();
      el.kol.replaceChildren(h('strong', {}, 'kol.json לא תקין'), ` — ${k.error}${line ? ` (שורה ${line})` : ''}. `,
        k.config ? `המסך ממשיך לעבוד עם הגרסה התקינה האחרונה (גרסה ${k.rev}).` : 'אין גרסה תקינה קודמת.', ' ',
        h('button', { type: 'button', class: 'btn sm', onclick: () => { const f = S.files.find(x => x.path === 'kol.json'); if (f) openFile(f.id); } }, 'פתיחת kol.json'));
    }
  }
  /** מספר השורה של השגיאה ב-kol.json — מהסימון של Monaco, ואם אין — מהודעת השגיאה של הדפדפן. */
  function kolLine() {
    const f = S.files.find(x => x.path === 'kol.json'), doc = f && S.docs.get(f.id);
    if (doc?.model && monaco) { const m = monaco.editor.getModelMarkers({ resource: doc.model.uri })[0]; if (m) return m.startLineNumber; }
    const text = doc ? (doc.model ? doc.model.getValue() : doc.text) : null;
    if (text == null) return 0;
    try { JSON.parse(text); } catch (e) { const m = /position (\d+)/.exec(e.message); if (m) return text.slice(0, +m[1]).split('\n').length; const l = /line (\d+)/.exec(e.message); if (l) return +l[1]; }
    return 0;
  }

  function drawTree() {
    if (!S.host) return;                                         // המסך אינו מוצג (המסמך פתוח בעורך החזותי) — אין מה לצייר
    const entry = S.kol?.config?.entry;
    const rows = [];
    const walk = (n, depth) => {
      for (const d of [...n.dirs.values()].sort((a, b) => a.name.localeCompare(b.name, 'he'))) {
        const shut = prefs.closed.includes(d.path);
        rows.push(h('li', { class: 'pj-row pj-dir', style: `--d:${depth}`, 'data-dir': d.path },
          h('button', { type: 'button', class: 'pj-name', 'aria-expanded': String(!shut), onclick: () => { prefs.closed = shut ? prefs.closed.filter(x => x !== d.path) : [...prefs.closed, d.path]; persist(); drawTree(); } },
            h('span', { class: 'pj-ico', 'aria-hidden': 'true' }, shut ? '▸' : '▾'), h('span', { class: 'pj-ico', 'aria-hidden': 'true' }, '📁'), h('span', { class: 'one', dir: 'auto' }, d.name)),
          h('button', { type: 'button', class: 'icon-btn pj-more', 'aria-label': `פעולות על התיקייה ${d.name}`, onclick: () => dirMenu(d) }, '⋯')));
        if (!shut) walk(d, depth + 1);
      }
      for (const f of n.files.sort((a, b) => base(a.path).localeCompare(base(b.path), 'he'))) {
        const miss = f.kind === 'media' && f.media_info?.missing;
        rows.push(h('li', { class: 'pj-row' + (f.id === S.cur ? ' on' : ''), style: `--d:${depth}`, 'data-dir': parent(f.path) },
          h('button', { type: 'button', class: 'pj-name', title: f.path, onclick: () => { openFile(f.id); if (phone()) side(false); } },
            h('span', { class: 'pj-ico', 'aria-hidden': 'true' }), h('span', { class: 'pj-ico', 'aria-hidden': 'true' }, ICONS[ext(f.path)] || '📄'),
            h('span', { class: 'one', dir: 'auto' }, base(f.path)),
            f.path === entry && h('span', { class: 'chip', title: 'קובץ הכניסה (kol.json)' }, 'כניסה'),
            f.kind === 'media' && h('span', { class: 'chip' + (miss ? ' bad' : '') }, miss ? 'חסר בספרייה' : 'מדיה'),
            dirty(f.id) && h('span', { class: 'pj-dot', title: 'לא נשמר', 'aria-label': 'לא נשמר' }, '●')),
          h('button', { type: 'button', class: 'icon-btn pj-more', 'aria-label': `פעולות על ${base(f.path)}`, onclick: () => fileMenu(f) }, '⋯')));
      }
    };
    walk(tree(), 0);
    el.tree.replaceChildren(...(rows.length ? rows : [h('li', { class: 'empty' }, 'אין קבצים. "+ קובץ" כדי להתחיל.')]));
    el.trash.replaceChildren('🗑️ סל', S.trash ? h('small', {}, ' ' + S.trash) : '');
    el.count.textContent = `${S.files.filter(f => f.kind === 'file').length} קבצים · עד ${S.limits.files ?? ''}`;
  }

  function drawTabs() {
    if (!S.host) return;                                         // המסך אינו מוצג (המסמך פתוח בעורך החזותי) — אין מה לצייר
    el.tabs.replaceChildren(...S.tabs.map(id => {
      const f = S.byId.get(id);
      const name = f ? base(f.path) : '(נמחק)';
      return h('span', { class: 'pj-tab' + (id === S.cur ? ' on' : ''), role: 'presentation' },
        h('button', { type: 'button', role: 'tab', 'aria-selected': String(id === S.cur), title: f?.path, onclick: () => { keepView(); S.cur = id; persist(); drawAll(); } },
          h('span', { dir: 'auto' }, name), dirty(id) && h('span', { class: 'pj-dot', 'aria-label': 'לא נשמר' }, ' ●')),
        h('button', { type: 'button', class: 'pj-x', 'aria-label': `סגירת ${name}`, onclick: () => closeTab(id) }, '✕'));
    }));
    el.tabs.querySelector('.on')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  function drawEditor() {
    if (!S.host) return;                                         // המסך אינו מוצג (המסמך פתוח בעורך החזותי) — אין מה לצייר
    const f = S.byId.get(S.cur), doc = S.docs.get(S.cur);
    el.ed.hidden = el.view.hidden = true;
    if (!f || !doc) {
      el.view.hidden = false;
      el.view.replaceChildren(h('p', { class: 'empty' }, S.cur && !f ? 'הקובץ הזה נמחק או הועבר.' : phone() ? 'אין קובץ פתוח.' : 'בחרו קובץ מהעץ כדי לפתוח אותו.'),
        phone() && h('p', { class: 'empty' }, h('button', { type: 'button', class: 'btn primary', onclick: () => side(true) }, '📂 קבצי הקוד — בחירת קובץ')));
      return;
    }
    if (doc.model && S.editor) {
      el.ed.hidden = false;
      if (S.editor.getModel() !== doc.model) {
        keepView();
        S.editor.setModel(doc.model);
        if (prefs.views[f.id]) S.editor.restoreViewState(prefs.views[f.id]);
      }
      S.editor.layout();
      if (!phone()) S.editor.focus();
      return;
    }
    el.view.hidden = false;
    if (doc.text != null && !doc.binary) {                       // העורך לא נטען: תיבת טקסט, כדי שתמיד אפשר לערוך ולשמור
      const ta = h('textarea', { class: 'code-area pj-fallback', dir: 'ltr', spellcheck: 'false', 'aria-label': f.path, oninput: () => { doc.text = ta.value; changed(doc); } });
      ta.value = doc.text;
      el.view.replaceChildren(ta);
      return;
    }
    const x = ext(f.path), type = f.media_info?.type || '';
    const kind = f.kind === 'media' ? f.media_info?.kind : IMG.test(x) ? 'image' : AUD.test(x) ? 'audio' : VID.test(x) ? 'video' : '';
    // תצוגה בלבד: תמונה ב-<img>, שמע ב-<audio> — הדפדפן אינו מריץ בהם קוד; והכתובת עצמה מוגשת בארגז חול
    const show = kind === 'image' ? h('img', { src: rawUrl(f), alt: base(f.path), class: 'pj-img' })
      : kind === 'audio' ? h('audio', { src: rawUrl(f), controls: true, preload: 'metadata' })
      : kind === 'video' ? h('video', { src: rawUrl(f), controls: true, preload: 'metadata', class: 'pj-img' })
      : h('p', { class: 'empty' }, 'קובץ בינארי — אין לו תצוגה כאן. אפשר להוריד אותו.');
    el.view.replaceChildren(h('div', { class: 'pj-bin' }, show,
      h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'נתיב'), h('dd', { dir: 'auto' }, f.path)),
        h('div', {}, h('dt', {}, 'גודל'), h('dd', {}, size(f.size))),
        h('div', {}, h('dt', {}, 'סוג'), h('dd', { dir: 'ltr' }, type || (x ? '.' + x : 'לא ידוע'))),
        f.kind === 'media' && h('div', {}, h('dt', {}, 'איפה הבייטים'), h('dd', {}, 'בספריית המדיה — כאן הפניה'))),
      h('button', { type: 'button', class: 'btn pj-dl', onclick: () => download(f) }, '⬇️ הורדה')));
  }

  function drawStatus() {
    if (!S.host) return;                                         // המסך אינו מוצג (המסמך פתוח בעורך החזותי) — אין מה לצייר
    const doc = S.docs.get(S.cur), f = S.byId.get(S.cur);
    const editable = !!doc && !doc.binary;
    el.save.disabled = !editable || !doc.dirty || doc.saving;
    el.status.className = 'pj-status' + (doc?.conflict || doc?.error ? ' bad' : '');
    el.status.textContent = !doc || !f ? '' : doc.binary ? size(f.size) : doc.conflict ? '⚠️ השתנה בינתיים — לא נשמר' : doc.saving ? 'שומר…' : doc.dirty ? (doc.error ? '⚠️ לא נשמר: ' + doc.error : '● לא נשמר') : '✓ נשמר';
    for (const b of el.bar.querySelectorAll('[data-ed]')) b.disabled = !editable || !S.editor;
  }

  // ── פעולות על העץ ─────────────────────────────────────────
  async function run(fn, okMsg) {
    try { const r = await fn(); await refresh(); if (okMsg) toast(okMsg); return r; }
    catch (e) { toast(e.message, true); return null; }
  }

  function nameSheet(title, label, value, okLabel, onOk, hint) {
    const input = h('input', { type: 'text', dir: 'auto', value, 'aria-label': label, autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', required: true });
    const go = async () => { if (!input.value) return; if (await onOk(input.value) !== null) dlg.close(); };
    const dlg = sheet(title,
      h('label', { class: 'f' }, h('span', {}, label), input),
      hint && h('p', { class: 'hint' }, hint),
      h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn primary', onclick: go }, okLabel), h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'ביטול')));
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    setTimeout(() => { input.focus(); const n = input.value, i = n.lastIndexOf('.'), s = n.lastIndexOf('/') + 1; input.setSelectionRange(s, i > s ? i : n.length); }, 50);
  }

  const newFile = dir => nameSheet('קובץ חדש', 'נתיב הקובץ', join(dir, 'new.html'), 'יצירה',
    path => run(async () => { const d = await api('pf_create', { path, content: '' }); await refresh(); openFile(d.file.id); }),
    'כל שם וכל סיומת. "/" יוצר תיקייה בדרך.');
  const newDir = dir => nameSheet('תיקייה חדשה', 'נתיב התיקייה', join(dir, 'תיקייה'), 'יצירה', path => run(() => api('pf_mkdir', { path })));
  const rename = (target, path) => nameSheet('שינוי שם', 'הנתיב החדש', path, 'שינוי',
    to => run(() => api('pf_rename', { ...target, to })), 'הקבצים האחרים אינם מתעדכנים לבד — הפניות לנתיב הישן יישארו כמו שהן.');

  function remove(target, label, n) {
    const open = S.tabs.filter(id => dirty(id) && (target.id === id || (target.path && (S.byId.get(id)?.path || '').startsWith(target.path + '/'))));
    if (!confirm(`להעביר את ${label} לסל?${n > 1 ? ` (${n} פריטים)` : ''}${open.length ? '\nיש בו שינויים שלא נשמרו.' : ''}\nאפשר לשחזר מהסל.`)) return;
    for (const id of open) { const d = S.docs.get(id); clearTimeout(d.timer); d.dirty = false; }
    run(() => api('pf_delete', target), 'עבר לסל');
  }

  function menu(title, items) {
    const dlg = sheet(title, h('div', { class: 'pj-menu' }, items.filter(Boolean).map(([label, fn, cls]) =>
      h('button', { type: 'button', class: 'btn ' + (cls || ''), onclick: () => { dlg.close(); fn(); } }, label))));
  }
  const fileMenu = f => menu(base(f.path), [
    ['📂 פתיחה', () => openFile(f.id)],
    ['✏️ שינוי שם', () => rename({ id: f.id }, f.path)],
    ['⬇️ הורדה', () => download(f)],
    ['🗑️ מחיקה לסל', () => remove({ id: f.id }, `"${base(f.path)}"`, 1), 'danger'],
  ]);
  const dirMenu = d => menu(d.path, [
    ['📄 קובץ חדש כאן', () => newFile(d.path)],
    ['📁 תיקייה חדשה כאן', () => newDir(d.path)],
    ['⬆️ העלאה לכאן', () => pick(d.path)],
    ['✏️ שינוי שם', () => rename({ path: d.path }, d.path)],
    ['🗑️ מחיקה לסל', () => remove({ path: d.path }, `התיקייה "${d.name}"`, S.files.filter(f => f.path === d.path || f.path.startsWith(d.path + '/')).length), 'danger'],
  ]);

  /** הורדה: הבייטים נמשכים כנתונים ונשמרים בשם הקובץ. הקובץ אינו נפתח בדפדפן בשום שלב. */
  async function download(f) {
    try {
      const res = await fetch(rawUrl(f, true));
      if (!res.ok) throw new Error('ההורדה נכשלה');
      const url = URL.createObjectURL(new Blob([await res.arrayBuffer()], { type: 'application/octet-stream' }));
      const a = h('a', { href: url, download: base(f.path) });
      document.body.append(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 30000);
    } catch (e) { toast(e.message, true); }
  }

  // ── העלאה ─────────────────────────────────────────────────
  function pick(dir) {
    const input = h('input', { type: 'file', multiple: true, hidden: true, onchange: () => { upload(dir, [...input.files]); input.remove(); } });
    document.body.append(input); input.click();
  }

  /** קובץ טקסט (וכל קובץ שאינו מדיה) נכנס למאגר, בייט בבייט. מדיה נכנסת לספריית המדיה, והעץ מקבל הפניה. */
  async function upload(dir, files) {
    if (!files.length) return;
    const fails = [];
    let done = 0;
    for (const file of files) {
      const path = join(dir, file.name);
      toast(`מעלה ${++done} מתוך ${files.length}…`);
      try {
        let linked = false;
        if (MEDIA_UP.test(file.type)) {
          try { const m = await Media.upload(file); await api('pf_link', { path, file_id: m.id }); linked = true; }
          catch (e) { if (e.status === 409 || e.status === 423 || e.status === 413) throw e; }   // הספרייה לא קיבלה — נשמר כקובץ רגיל
        }
        if (!linked) {
          const buf = new Uint8Array(await file.arrayBuffer());
          let body;
          try { body = { path, content: new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(buf) }; }
          catch { let s = ''; for (let i = 0; i < buf.length; i += 0x8000) s += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000)); body = { path, content_b64: btoa(s) }; }
          await api('pf_create', body);
        }
      } catch (e) { fails.push(`${file.name}: ${e.message}`); }
    }
    await refresh().catch(() => {});
    if (fails.length) sheet('חלק מהקבצים לא עלו', h('ul', { class: 'pj-fails' }, fails.map(t => h('li', { dir: 'auto' }, t))));
    else toast(files.length > 1 ? `${files.length} קבצים עלו` : 'הקובץ עלה');
  }

  // ── הסל ───────────────────────────────────────────────────
  async function trashSheet() {
    let d;
    try { d = await api('pf_trash'); } catch (e) { return toast(e.message, true); }
    const groups = new Map();
    for (const it of d.items) { const k = it.batch || 'x' + it.id; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(it); }
    const act = async (action, body, msg) => { try { await api(action, body); toast(msg); await refresh(); trashSheet(); } catch (e) { toast(e.message, true); } };
    sheet(`סל הפרויקט · ${d.items.length}`,
      h('p', { class: 'hint' }, 'מחיקה היא העברה לסל. שחזור מחזיר את הקובץ לנתיב שלו, עם ההיסטוריה. מחיקה סופית — רק מכאן, ורק לבעלים.'),
      d.items.length ? h('ul', { class: 'list' }, [...groups.values()].map(g => {
        const ids = g.map(x => x.id), first = g[0];
        const label = g.length > 1 ? `${g.filter(x => x.kind !== 'dir').length} קבצים · ${common(g.map(x => x.path))}` : first.path;
        return h('li', { class: 'card pj-trash' },
          h('div', { class: 'body' },
            h('div', { class: 'l1' }, h('span', { class: 'one', dir: 'auto' }, label)),
            h('div', { class: 'l2' }, `נמחק ${when(first.deleted_at)} · ${first.deleted_by || ''}`)),
          h('button', { type: 'button', class: 'btn sm', onclick: () => act('pf_restore', { ids }, 'שוחזר') }, '↩️ שחזור'),
          S.canPurge && h('button', { type: 'button', class: 'btn sm ghost danger', onclick: () => {
            if (confirm(`למחוק סופית את ${label}?\nאי אפשר לשחזר אחרי זה.`)) act('pf_purge', { ids, confirm: true }, 'נמחק סופית');
          } }, 'מחיקה סופית'));
      })) : h('p', { class: 'empty' }, 'הסל ריק.'));
  }
  function common(paths) {
    let p = parent(paths[0]);
    while (p && !paths.every(x => x === p || x.startsWith(p + '/'))) p = parent(p);
    return p || 'שורש הפרויקט';
  }

  // ── כלי העורך ─────────────────────────────────────────────
  const edRun = id => { S.editor?.focus(); S.editor?.getAction(id)?.run(); };
  function applyPrefs() {
    S.editor?.updateOptions({ fontSize: prefs.font, wordWrap: prefs.wrap ? 'on' : 'off' });
    persist();
  }
  const toolsMenu = () => menu('כלי העורך', [
    ['🔍 חיפוש בקובץ', () => edRun('actions.find')],
    ['🔁 חיפוש והחלפה', () => edRun('editor.action.startFindReplaceAction')],
    ['↗️ מעבר לשורה', () => edRun('editor.action.gotoLine')],
    ['↩️ בטל', () => { S.editor?.focus(); S.editor?.trigger('menu', 'undo', null); }],
    ['↪️ חזור', () => { S.editor?.focus(); S.editor?.trigger('menu', 'redo', null); }],
    ['⊟ קיפול הכול', () => edRun('editor.foldAll')],
    ['⊞ פתיחת כל הקיפולים', () => edRun('editor.unfoldAll')],
    [prefs.wrap ? '↔️ בלי עטיפת שורות' : '↩︎ עטיפת שורות', () => { prefs.wrap = !prefs.wrap; applyPrefs(); }],
    ['🔠 גופן גדול יותר', () => { prefs.font = Math.min(28, prefs.font + 1); applyPrefs(); }],
    ['🔡 גופן קטן יותר', () => { prefs.font = Math.max(10, prefs.font - 1); applyPrefs(); }],
  ]);

  function side(open) { S.host?.classList.toggle('side-open', open); if (!open) S.editor?.layout(); }

  // ── המסך ──────────────────────────────────────────────────
  async function view(host) {
    S.host = host;
    el.kol = h('p', { class: 'pj-warn', role: 'alert', hidden: true });
    el.tree = h('ul', { class: 'pj-tree', 'aria-label': 'קבצי הפרויקט' });
    el.trash = h('button', { type: 'button', class: 'btn sm', onclick: trashSheet }, '🗑️ סל');
    el.count = h('span', { class: 'hint' });
    el.tabs = h('div', { class: 'pj-tabs', role: 'tablist', 'aria-label': 'קבצים פתוחים' });
    // עורך אחד לכל חיי הדף: Monaco שנסגר (dispose) משבית פעולות בעורך שנפתח אחריו — לכן שומרים אותו ומחברים מחדש
    el.ed = el.ed || h('div', { class: 'pj-ed', dir: 'ltr', hidden: true });
    el.view = h('div', { class: 'pj-view' }, h('p', { class: 'empty' }, 'טוען…'));
    el.status = h('span', { class: 'pj-status', role: 'status' });
    el.save = h('button', { type: 'button', class: 'btn primary sm', disabled: true, onclick: () => save(S.cur) }, '💾 שמירה');
    el.bar = h('div', { class: 'pj-bar' },
      h('button', { type: 'button', class: 'btn sm pj-files-btn', onclick: () => side(true) }, '📂 קבצי הקוד'),
      el.tabs, el.status, el.save,
      h('button', { type: 'button', class: 'btn sm pj-live-btn', title: 'תצוגה חיה — העמוד רץ כפי שירוץ אצל הגולש', onclick: () => live() }, '▶️', h('span', { class: 'pj-live-word' }, ' תצוגה חיה')),
      h('button', { type: 'button', class: 'icon-btn', 'data-ed': true, 'aria-label': 'כלי העורך', onclick: toolsMenu }, '⋯'));
    const aside = h('aside', { class: 'pj-side', 'aria-label': 'מנהל הקבצים' },
      h('div', { class: 'pj-side-head' },
        h('strong', {}, 'קבצי הפרויקט'),
        h('button', { type: 'button', class: 'icon-btn pj-side-x', 'aria-label': 'סגירת רשימת הקבצים', onclick: () => side(false) }, '✕')),
      h('div', { class: 'pj-side-btns' },
        h('button', { type: 'button', class: 'btn sm', onclick: () => newFile('') }, '+ קובץ'),
        h('button', { type: 'button', class: 'btn sm', onclick: () => newDir('') }, '+ תיקייה'),
        h('button', { type: 'button', class: 'btn sm', onclick: () => pick('') }, '⬆️ העלאה'),
        h('button', { type: 'button', class: 'btn sm', 'data-live': true, onclick: () => live() }, '▶️ תצוגה חיה'),
        el.trash),
      el.tree, el.count);
    // גרירה מהמחשב אל תיקייה (או אל העץ — לשורש)
    aside.addEventListener('dragover', e => { if (e.dataTransfer?.types.includes('Files')) { e.preventDefault(); aside.classList.add('drop'); } });
    aside.addEventListener('dragleave', () => aside.classList.remove('drop'));
    aside.addEventListener('drop', e => {
      if (!e.dataTransfer?.files.length) return;
      e.preventDefault(); aside.classList.remove('drop');
      upload(e.target.closest('[data-dir]')?.dataset.dir || '', [...e.dataTransfer.files]);
    });
    host.replaceChildren(h('div', { class: 'pj' },
      h('p', { class: 'pj-note', role: 'note' }, 'הקבצים כאן עדיין אינם מתפרסמים. הפרסום מהקבצים ייפתח בשלב הבא.'),
      el.kol,
      h('div', { class: 'pj-wrap' }, aside, h('section', { class: 'pj-main' }, el.bar, el.ed, el.view))));

    try { await loadMonaco(); } catch { toast('העורך המלא לא נטען — עורכים בתיבת טקסט', true); }
    if (S.host !== host) return;
    if (monaco && !S.editor) {
      S.editor = monaco.editor.create(el.ed, { model: null, automaticLayout: true, theme: dark() ? 'vs-dark' : 'vs', fontSize: prefs.font, wordWrap: prefs.wrap ? 'on' : 'off',
        minimap: { enabled: !phone() }, tabSize: 2, scrollBeyondLastLine: false, fixedOverflowWidgets: true, unicodeHighlight: { nonBasicASCII: false, ambiguousCharacters: false },
        // "הכותרת הדביקה" של Monaco מתעדכנת באיחור (async), ואחרי שינוי שמקצר את הקובץ (מחיקה בעורך החזותי, ביטול) היא
        // שואלת על שורה שכבר אינה קיימת — "Illegal value for lineNumber" (V1a 🟡-1). כבויה.
        stickyScroll: { enabled: false } });
      S.editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => save(S.cur));
    }
    try {
      const first = !S.loaded;
      await refresh();
      if (first) {                                               // הלשוניות והמיקום מהביקור הקודם
        const want = prefs.tabs.filter(id => S.byId.has(id)), cur = S.byId.has(prefs.cur) ? prefs.cur : want[0] ?? null;
        for (const id of want) await openFile(id, false);
        S.cur = cur; persist();
      }
      drawAll();
      if (phone() && !S.cur) side(true);                         // בטלפון, בלי קובץ פתוח: העץ עצמו, לא מסך ריק עם כפתור
    } catch (e) { el.view.hidden = false; el.view.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  // ── ▶️ התצוגה החיה (F4) ───────────────────────────────────
  // live.php הוא מסמך משלו (מדיניות CSP מלאה), ובתוכו המסגרת המבודדת. כאן רק פותחים אותו בשכבה מעל המסך, ומדברים
  // איתו בערוץ של אותו מקור: "נשמר" ← רענון; "פתח בקוד" → הקובץ והשורה נפתחים בעורך. קוד העמוד אינו שותף לערוץ.
  let bc = null, liveEl = null;
  try { bc = new BroadcastChannel('kol-pf'); } catch { /* בלי רענון אוטומטי */ }
  function liveClose() { liveEl?.remove(); liveEl = null; S.editor?.layout(); }
  async function live() {
    const doc = S.docs.get(S.cur), f = S.byId.get(S.cur);
    if (doc?.dirty) await save(S.cur);                         // התצוגה מראה את מה שנשמר
    const path = f && /\.html?$/i.test(f.path) ? f.path : '';
    liveClose();
    liveEl = h('div', { class: 'pj-live' }, h('iframe', { src: 'live.php?embed=1' + (path ? '&path=' + encodeURIComponent(path) : ''), title: 'תצוגה חיה' }));
    document.body.append(liveEl);
  }
  /** פותח קובץ בעורך הקוד. line — שורה; range — {from, to} בתווים: הטווח מסומן (למשל האלמנט שנבחר בעורך החזותי). */
  async function openAt(path, line, range) {
    liveClose();
    if (location.hash.split('/')[1] !== 'project') { location.hash = '#/project'; await new Promise(r => setTimeout(r, 400)); }
    const f = S.files.find(x => x.path === path);
    if (!f) { toast('הקובץ אינו בפרויקט: ' + path, true); return; }
    await openFile(f.id);
    const m = S.docs.get(f.id)?.model;
    if (S.editor && m) for (let i = 0; i < 60 && S.editor.getModel() !== m; i++) await new Promise(r => setTimeout(r, 50));
    if (S.editor && m && line > 0 && S.editor.getModel() === m) {   // שורה שמעבר לסוף הקובץ (המסוף מדווח על גרסה ישנה) — נתחמת
      const ln = Math.min(line, m.getLineCount());
      S.editor.revealLineInCenter(ln); S.editor.setPosition({ lineNumber: ln, column: 1 }); S.editor.focus();
    }
    if (S.editor && m && range) {
      if (S.editor.getModel() !== m) return;                     // העורך עוד לא על הקובץ הזה — לא מסמנים טווח בקובץ אחר
      const a = m.getPositionAt(range.from), b = m.getPositionAt(range.to), sel = new monaco.Range(a.lineNumber, a.column, b.lineNumber, b.column);
      S.editor.setSelection(sel); S.editor.revealRangeInCenterIfOutsideViewport(sel); S.editor.focus();
    }
  }
  bc?.addEventListener('message', e => {
    const d = e.data;
    if (!d || typeof d !== 'object') return;
    if (d.t === 'close') liveClose();
    if (d.t === 'open' && typeof d.path === 'string') openAt(d.path, Number.isInteger(d.line) ? d.line : 0);
  });

  function unmount() {
    if (!S.host) return;
    keepView();
    S.host = null;
  }

  // Ctrl/⌘+S גם כשהמיקוד מחוץ לעורך; אזהרה ביציאה כשיש שינוי שלא נשמר
  document.addEventListener('keydown', e => {
    if (S.host && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(S.cur); }
  });
  window.addEventListener('beforeunload', e => { keepView(); if ([...S.docs.values()].some(d => d.dirty)) { e.preventDefault(); e.returnValue = ''; } });
  window.addEventListener('hashchange', () => { if (S.host && location.hash.split('/')[1] !== 'project') unmount(); });

  // ── 📏 מגבלות (מסך המפתח) ─────────────────────────────────
  async function limits(host) {
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let d;
    try { d = await api('pf_limits'); } catch (e) { return host.replaceChildren(h('p', { class: 'error' }, e.message)); }
    const draw = () => host.replaceChildren(
      h('p', { class: 'hint' }, `המגבלות של מאגר הפרויקט. אלה הגדרות, לא קבועים בקוד — שינוי חל מיד. בפרויקט עכשיו ${d.used.files} קבצים.`),
      h('ul', { class: 'list' }, d.limits.map(l => {
        const input = h('input', { type: 'number', dir: 'ltr', min: l.min, max: l.max, value: l.value, 'aria-label': l.label });
        return h('li', { class: 'card pj-limit' },
          h('label', { class: 'f' }, h('span', {}, `${l.label} · ברירת מחדל ${l.default}`), input),
          h('button', { type: 'button', class: 'btn sm primary', onclick: async () => {
            try { d = await api('pf_limits_set', { key: l.key, value: +input.value }); toast('נשמר'); draw(); } catch (e) { toast(e.message, true); }
          } }, 'שמירה'));
      })));
    draw();
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (typeof ACTIONS === 'object') Object.assign(ACTIONS, { pf_create: '📁 יצר קובץ בפרויקט', pf_mkdir: '📁 יצר תיקייה בפרויקט', pf_rename: '📁 שינה שם בפרויקט',
      pf_delete: '📁 העביר לסל הפרויקט', pf_restore: '📁 שחזר מסל הפרויקט', pf_purge: '📁 מחק סופית מהפרויקט', pf_upload: '📁 העלה מדיה לפרויקט', pf_limit: '📏 שינה מגבלה של הפרויקט' });
  });

  return { view, unmount, limits, loadMonaco, live, docs: Docs, openAt, _state: S, _open: openFile, _save: save };
})();
