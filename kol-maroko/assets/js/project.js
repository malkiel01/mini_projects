// קול מרוקו — 📁 פרויקט (פרוסות F2a + F2b; lib/project.php, lib/project_ops.php): עץ הקבצים של העמוד ועורך קוד רב-לשוניות.
// F2b: העברה (גרירה / "העבר אל…") ושכפול; ZIP הלוך-חזור; חיפוש והחלפה בכל הקבצים; הפניות בשינוי שם ובמחיקה; גרסאות עם השוואה ושחזור.
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
    S.files = d.files; S.trash = d.trash; S.limits = d.limits; S.kol = d.kol; S.canPurge = d.can_purge; S.seedOnly = !!d.seed_only; S.loaded = true;
    S.byId = new Map(d.files.map(f => [f.id, f]));
    // לשונית של קובץ שנמחק או שונה שמו — מתעדכנת
    S.tabs = S.tabs.filter(id => S.byId.has(id) || S.docs.get(id)?.dirty);
    if (!S.tabs.includes(S.cur)) S.cur = S.tabs[S.tabs.length - 1] ?? null;
    drawSeed(el.seed);
    if (el.mig) el.mig.hidden = !S.canPurge;                     // "ייבוא מחדש" — בעלים בלבד
    drawAll();
    if ([...S.docs.values()].some(stale)) syncOpen();            // השרת שינה קובץ פתוח (הפניות, החלפה, שחזור, ZIP)
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
    if (activate) { S.cur = id; if (phone()) side(false); }    // בטלפון העץ מכסה את העורך — קובץ שנפתח סוגר אותו
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
    if (doc.dirty && !doc.conflict && !S.byId.get(doc.id)?.linked) doc.timer = setTimeout(() => save(doc.id, true), AUTOSAVE_MS);   // N1: הקובץ של הטבלה — רק בשמירה מפורשת
  }

  // ── שירות המסמכים (04 §1) ─────────────────────────────────
  // מקור אמת אחד לכל קובץ פתוח: הטקסט, ההיסטוריה (ביטול / חזרה) וה-rev. עורך הקוד והעורך החזותי (assets/js/editor)
  // עובדים על אותו מסמך — תיקון מכאן או משם נכנס לאותה היסטוריה, ונשמר באותה שמירה (pf_write, אותו rev, אותן גרסאות).
  const subs = new Map();
  function emit(doc, kind) { for (const cb of subs.get(doc.id) || []) { try { cb(kind); } catch (e) { console.error(e); } } }
  function handle(doc) {
    const text = () => (doc.model && !doc.model.isDisposed() ? doc.model.getValue() : doc.text ?? doc.saved ?? '');   // מסמך שנסגר (ייבוא מחדש) — הטקסט האחרון, לא חריגה
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
        if (label) (doc.txPending = doc.txPending || []).push(label);   // שם העסקה — נשמר כהערת הגרסה בשמירה הבאה
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
    /** טוען מחדש את רשימת הקבצים (אחרי העלאה או קישור של מדיה מהעורך החזותי). */
    async reload() { await refresh(); return S.files; },
    byPath: path => S.files.find(f => f.path === path && f.kind !== 'dir') || null,
    /** פותח קובץ כמסמך משותף (וכלשונית בעורך הקוד). מחזיר null כשאי אפשר לפתוח. */
    async open(id) {
      if (!S.loaded) await refresh();
      try { await loadMonaco(); } catch { /* בלי Monaco — מסמך טקסט פשוט, עם היסטוריה משלו */ }
      if (!S.docs.has(id)) { const cur = S.cur; await openFile(id, false); S.cur = cur ?? id; persist(); }
      const doc = S.docs.get(id);
      return doc ? handle(doc) : null;
    },
    /** מצב הפרויקט ברגע זה: מי מחזיק בנעילת העריכה, ו-kol.json (נקודות השבירה). */
    async info() { const d = await api('pf_tree'); S.kol = d.kol; S.seedOnly = !!d.seed_only; S.canPurge = d.can_purge; return { lock: d.lock, kol: d.kol, seedOnly: S.seedOnly }; },
    /** הבייטים של קובץ שאינו UTF-8 — לתצוגה בלבד. */
    async bytes(id) { const d = await api('pf_read', undefined, `&id=${id}`); return d.binary ? Uint8Array.from(atob(d.b64), c => c.charCodeAt(0)) : new TextEncoder().encode(d.content); },
  };

  /** שמירה. הקוד נשמר כמו שהוא — גם שבור; אין כאן שום בדיקת תקינות שמונעת שמירה. */
  async function save(id, auto = false) {
    const doc = S.docs.get(id);
    if (!doc || !doc.dirty || doc.saving || (auto && doc.conflict)) return;
    const content = doc.model ? doc.model.getValue() : doc.text;
    if (S.byId.get(id)?.linked) return auto ? undefined : wordsSave(doc, content);   // N1: "מה ישתנה" לפני שהטבלה משתנה
    doc.saving = true; drawStatus();
    try {
      const tx = (doc.txPending || []).slice();
      const d = await api('pf_write', tx.length ? { id, rev: doc.rev, content, auto, tx } : { id, rev: doc.rev, content, auto });
      doc.rev = d.file.rev; doc.saved = content; doc.conflict = false;
      if (doc.txPending) doc.txPending.splice(0, tx.length);
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

  // ── N1: data/words.json — הקובץ של טבלת המילים ─────────────
  // שמירה = בדיקה בשרת ← "מה ישתנה" (לפי id) ← "החל". שגיאה — עם שורה ועמודה, מסומנת בעורך; הטבלה אינה משתנה עד "החל".
  function wordsMarks(doc, errors, warnings = []) {
    if (!doc.model || !monaco) return;
    const m = (x, sev) => ({ startLineNumber: x.line, startColumn: x.col, endLineNumber: x.line, endColumn: x.col + 1, message: x.msg, severity: sev });
    monaco.editor.setModelMarkers(doc.model, 'kol-words', [...errors.map(x => m(x, monaco.MarkerSeverity.Error)), ...warnings.map(x => m(x, monaco.MarkerSeverity.Warning))]);
  }
  const wordsAt = (doc, line) => { if (S.editor && doc.model) { S.editor.revealLineInCenter(line); S.editor.setPosition({ lineNumber: line, column: 1 }); S.editor.focus(); } };
  async function wordsSave(doc, content, create = []) {
    doc.saving = true; drawStatus();
    try {
      const d = await api('pf_write', { id: doc.id, rev: doc.rev, content, create_topics: create });
      doc.error = ''; wordsMarks(doc, [], d.plan.warnings);
      wordsPlanSheet(doc, content, d.plan, create);
    } catch (e) {
      doc.error = e.message; doc.errorStatus = e.status || 0;
      if (e.status === 409 && e.data?.conflict) { doc.conflict = true; conflictSheet(doc, content, e.data.conflict); }
      else if (e.status === 422 && e.data?.errors) { wordsMarks(doc, e.data.errors, e.data.warnings || []); wordsErrors(doc, content, e.data, create); }
      else toast(e.message, true);
    } finally {
      doc.saving = false;
      drawTabs(); drawTree(); drawStatus(); emit(doc, 'state');
    }
  }
  function wordsErrors(doc, content, data, create) {
    const props = (data.proposals || []).filter(p => !create.includes(p.slug));
    const list = data.errors.filter(x => !x.propose);
    const dlg = sheet('הקובץ לא נשמר — הטבלה לא השתנתה',
      list.length ? h('ul', { class: 'pj-fails', 'data-words-errors': true }, list.slice(0, 50).map(x => h('li', {},
        h('button', { type: 'button', class: 'pj-ref-at', onclick: () => { dlg.close(); wordsAt(doc, x.line); } }, h('span', { dir: 'ltr' }, `שורה ${x.line}, עמודה ${x.col}`)), ' ', h('span', { dir: 'auto' }, x.msg)))) : null,
      props.length ? h('div', { class: 'warnbox' }, h('p', {}, 'נושאים שאינם קיימים:'),
        h('div', { class: 'row-btns wrap' }, props.map(p => h('button', { type: 'button', class: 'btn sm', 'data-create-topic': p.slug,
          onclick: () => { dlg.close(); wordsSave(doc, content, [...create, p.slug]); } }, `צור נושא "${p.slug}" (${p.name})`)))) : null,
      h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'חזרה לעורך')));
  }
  function wordsPlanSheet(doc, content, plan, create) {
    const c = plan.counts;
    const field = { word: 'מילה', meaning: 'פירוש', topics: 'נושאים', audio: 'הקלטה', external_id: 'מספר מהאקסל' };
    const fold = (title, n, items) => n ? h('details', { class: 'pj-plan' }, h('summary', {}, `${title} (${n})`), h('ul', {}, items)) : null;
    const apply = h('button', { type: 'button', class: 'btn primary', 'data-words-apply': true }, 'החל');
    const dlg = sheet('מה ישתנה בטבלת המילים',
      h('p', { 'data-words-summary': true }, h('b', {}, plan.summary)),
      !c.changed && !c.added && !c.trashed && !c.moved && !c.topics ? h('p', { class: 'hint' }, 'אין שינויים — הקובץ זהה לטבלה.') : null,
      plan.warnings?.length ? h('div', { class: 'warnbox' }, h('ul', {}, plan.warnings.slice(0, 20).map(x => h('li', { dir: 'auto' }, `שורה ${x.line}: ${x.msg}`)))) : null,
      fold('ישתנו', c.changed, plan.changed.map(w => h('li', {}, h('b', { dir: 'auto' }, w.word), h('small', { class: 'muted' }, ` #${w.id}`),
        h('ul', {}, Object.entries(w.fields).map(([k, [a, b]]) => h('li', {}, `${field[k] || k}: `, h('span', { class: 'pj-ref-old', dir: 'auto' }, a || '—'), ' ← ', h('span', { class: 'pj-ref-new', dir: 'auto' }, b || '—'))))))),
      fold('יתווספו', c.added, plan.added.map(w => h('li', { dir: 'auto' }, w.word, w.id ? h('small', { class: 'muted' }, ` #${w.id}`) : h('small', { class: 'muted' }, ' — מזהה חדש')))),
      fold('יעברו לסל', c.trashed, plan.trashed.map(w => h('li', { dir: 'auto' }, w.word, h('small', { class: 'muted' }, ` #${w.id}${w.audio ? ' · עם ההקלטה (נשארת בספרייה)' : ''}`)))),
      plan.new_topics?.length ? h('p', {}, 'נושאים חדשים: ' + plan.new_topics.map(t => `${t.name} (${t.slug})`).join(', ')) : null,
      plan.snapshot ? h('p', { class: 'hint' }, '↩️ לפני ההחלה תיווצר נקודת שחזור.') : null,
      plan.needs === 'publisher' ? h('p', { class: 'hint' }, 'מחיקה של יותר מ-20 מילים — רק למפרסם.') : null,
      h('div', { class: 'row-btns' }, apply, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'בטל')));
    apply.onclick = async () => {
      apply.disabled = true;
      try {
        const d = await api('pf_write', { id: doc.id, rev: doc.rev, content, apply: true, token: plan.token, create_topics: create });
        dlg.close();
        setDocText(doc, d.content, d.file.rev);
        doc.error = ''; doc.errorStatus = 0; wordsMarks(doc, []);
        const f = S.byId.get(doc.id);
        if (f) { Object.assign(f, d.file); bc?.postMessage({ t: 'saved', path: f.path }); }
        toast('הטבלה עודכנה · ' + plan.summary);
        drawAll();
      } catch (e) {
        apply.disabled = false;
        if (e.status === 409 && e.data?.conflict) { dlg.close(); doc.conflict = true; conflictSheet(doc, content, e.data.conflict); }
        else toast(e.message, true);
      }
    };
  }
  /** "מחיקה" של הקובץ של הטבלה: אינה מוחקת מילים. ההסבר, והאפשרות להסיר את data.words מ-kol.json. */
  function wordsRemove(f) {
    const dlg = sheet('הקובץ של טבלת המילים',
      h('p', {}, 'זה הקובץ של טבלת המילים. מחיקה כאן אינה מוחקת מילים.'),
      h('p', { class: 'hint' }, 'להסיר אותו מהעמוד = להסיר את data.words מ-kol.json. הטבלה נשארת כמו שהיא, ואפשר להחזיר מההיסטוריה של kol.json.'),
      h('div', { class: 'row-btns wrap' },
        h('button', { type: 'button', class: 'btn danger', 'data-words-unlink': true, onclick: () => { dlg.close(); run(() => api('pf_delete', { id: f.id, unlink: true }), 'data.words הוסר מ-kol.json — המילים נשארו'); } }, 'הסר את data.words מ-kol.json'),
        h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'ביטול')));
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

  /** קובצי התבנית של הגרסה הקודמת (from-current/, עם {{…}}) — נשארים לעיון, ואינם העמוד. */
  const OLD_TPL = path => /^from-current\/[^/]+\.html?$/i.test(path);
  function drawTree() {
    if (!S.host) return;                                         // המסך אינו מוצג (המסמך פתוח בעורך החזותי) — אין מה לצייר
    const entry = S.kol?.config?.entry;
    const rows = [];
    const walk = (n, depth) => {
      for (const d of [...n.dirs.values()].sort((a, b) => a.name.localeCompare(b.name, 'he'))) {
        const shut = prefs.closed.includes(d.path);
        rows.push(h('li', { class: 'pj-row pj-dir', style: `--d:${depth}`, 'data-dir': d.path, draggable: 'true', ondragstart: e => dragStart(e, { path: d.path }) },
          h('button', { type: 'button', class: 'pj-name', 'aria-expanded': String(!shut), onclick: () => { prefs.closed = shut ? prefs.closed.filter(x => x !== d.path) : [...prefs.closed, d.path]; persist(); drawTree(); } },
            h('span', { class: 'pj-ico', 'aria-hidden': 'true' }, shut ? '▸' : '▾'), h('span', { class: 'pj-ico', 'aria-hidden': 'true' }, '📁'), h('span', { class: 'one', dir: 'auto' }, d.name)),
          h('button', { type: 'button', class: 'icon-btn pj-more', 'aria-label': `פעולות על התיקייה ${d.name}`, onclick: () => dirMenu(d) }, '⋯')));
        if (!shut) walk(d, depth + 1);
      }
      for (const f of n.files.sort((a, b) => base(a.path).localeCompare(base(b.path), 'he'))) {
        const miss = f.kind === 'media' && f.media_info?.missing;
        rows.push(h('li', { class: 'pj-row' + (f.id === S.cur ? ' on' : ''), style: `--d:${depth}`, 'data-dir': parent(f.path), draggable: f.linked ? 'false' : 'true', ondragstart: e => { if (!f.linked) dragStart(e, { id: f.id }, f.path); } },
          h('button', { type: 'button', class: 'pj-name', title: f.path, onclick: () => { openFile(f.id); if (phone()) side(false); } },
            h('span', { class: 'pj-ico', 'aria-hidden': 'true' }), h('span', { class: 'pj-ico', 'aria-hidden': 'true' }, ICONS[ext(f.path)] || '📄'),
            h('span', { class: 'one', dir: 'auto' }, base(f.path)),
            f.path === entry && h('span', { class: 'chip', title: 'קובץ הכניסה (kol.json)' }, 'כניסה'),
            OLD_TPL(f.path) && h('span', { class: 'chip', 'data-old-tpl': true, title: 'קובץ תבנית מהגרסה הקודמת (עם {{…}}) — לעיון בלבד; העמוד עצמו הוא index.html' }, 'תבנית ישנה'),
            f.kind === 'media' && h('span', { class: 'chip' + (miss ? ' bad' : '') }, miss ? 'חסר בספרייה' : 'מדיה'),
            f.linked && h('span', { class: 'chip', 'data-linked': f.linked, title: f.note }, '🔗 טבלה'),
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
    el.status.textContent = !doc || !f ? '' : doc.binary ? size(f.size) : doc.conflict ? '⚠️ השתנה בינתיים — לא נשמר' : doc.saving ? 'שומר…' : doc.dirty ? (doc.error ? '⚠️ לא נשמר: ' + doc.error : f.linked ? '● לא נשמר — Ctrl+S: "מה ישתנה" בטבלה' : '● לא נשמר') : f.linked ? '🔗 ' + f.note : '✓ נשמר';
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
    to => moveTo(target, path, to), 'שינוי של התיקייה בנתיב — הוא העברה. אם קבצים אחרים מפנים לנתיב הישן, תוצג רשימה, ואתם מחליטים אם לעדכן.');

  async function remove(target, label, n) {
    const open = S.tabs.filter(id => dirty(id) && (target.id === id || (target.path && (S.byId.get(id)?.path || '').startsWith(target.path + '/'))));
    const doIt = () => {
      for (const id of open) { const d = S.docs.get(id); clearTimeout(d.timer); d.dirty = false; }
      return run(() => api('pf_delete', target), 'עבר לסל');
    };
    let refs = [];
    try { refs = (await api('pf_refs', undefined, tq(target))).refs; } catch { /* בלי רשימה — עדיין אפשר למחוק */ }
    if (refs.length) {                                           // לא חוסם: הרשימה, והבחירה בידי בעל העמוד
      const dlg = sheet(`${refs.length} הפניות ל${label}`,
        h('p', { class: 'hint' }, `קבצים אחרים מפנים אל ${label}. אחרי המחיקה ההפניות האלה לא יגיעו לשום דבר. אפשר לפתוח כל אחת ולתקן — או למחוק בכל זאת (אפשר לשחזר מהסל).`),
        refList(refs, false),
        h('div', { class: 'row-btns wrap' },
          h('button', { type: 'button', class: 'btn danger', 'data-refs-delete': true, onclick: () => { dlg.close(); doIt(); } }, `העברה לסל בכל זאת${n > 1 ? ` (${n} פריטים)` : ''}`),
          h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'ביטול')));
      return;
    }
    if (!confirm(`להעביר את ${label} לסל?${n > 1 ? ` (${n} פריטים)` : ''}${open.length ? '\nיש בו שינויים שלא נשמרו.' : ''}\nאפשר לשחזר מהסל.`)) return;
    doIt();
  }

  function menu(title, items) {
    const dlg = sheet(title, h('div', { class: 'pj-menu' }, items.filter(Boolean).map(it => it instanceof Node ? it : (([label, fn, cls]) =>
      h('button', { type: 'button', class: 'btn ' + (cls || ''), onclick: () => { dlg.close(); fn(); } }, label))(it))));
  }
  /** "גרסאות" של קובץ: כל שמירה — מתי, מי, ומה נעשה בה (שם העסקה מהעורך החזותי, "שמירה", "שמירה אוטומטית"). */
  async function versions(f) {
    const body = h('div', { class: 'pj-versions' }, h('p', { class: 'hint' }, 'טוען…'));
    sheet('גרסאות · ' + base(f.path), body);
    try {
      const d = await api('pf_versions', undefined, `&id=${f.id}`);
      if (d.linked) {                                              // N1: השמירות של הטבלה (יומן הפעולות) — אין גוש שמור לשחזר
        body.replaceChildren(h('p', { class: 'hint' }, 'הקובץ נבנה מטבלת המילים, ולכן אין לו גרסאות שמורות. אלה השמירות האחרונות בטבלה; חזרה אחורה — ב-↩️ שחזור (נקודת שחזור נוצרת לפני כל מחיקה ולפני שינוי של יותר מ-20 מילים).'),
          h('ol', { class: 'pj-ver-list' }, d.versions.map(v => h('li', {}, h('div', {}, h('span', { class: 'pj-ver-note', dir: 'auto' }, v.note), h('span', { class: 'hint' }, ' — ' + (v.by || '') + ' · ' + when(v.at)))))));
        return;
      }
      body.replaceChildren(h('p', { class: 'hint' }, d.versions.length + ' גרסאות שמורות. הגרסה הנוכחית: ' + d.file.rev + '. "השוואה" — מה השתנה מאז; "שחזור" — התוכן שלה נשמר כגרסה חדשה (שום גרסה אינה נמחקת).'),
        h('ol', { class: 'pj-ver-list' }, d.versions.map((v, i) => h('li', { 'data-rev': v.rev },
          h('div', {}, h('b', {}, 'גרסה ' + v.rev), ' · ', h('span', { class: 'pj-ver-note', dir: 'auto' }, v.note), h('span', { class: 'hint' }, ' — ' + (v.by || '') + ' · ' + when(v.at) + ' · ' + size(v.size))),
          f.kind === 'file' && v.hid && h('div', { class: 'pj-ver-btns' },
            h('button', { type: 'button', class: 'btn sm', 'data-ver-diff': v.hid, onclick: () => diffSheet(d.file, v) }, '↔️ השוואה'),
            i > 0 && h('button', { type: 'button', class: 'btn sm', 'data-ver-restore': v.hid, onclick: () => restoreVersion(d.file, v) }, '↩️ שחזור'))))));
    } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }
  const fileMenu = f => f.linked ? menu(base(f.path), [
    h('p', { class: 'hint' }, '🔗 ' + f.note + '. השם והמיקום — ב-kol.json (data.words.file).'),
    ['🕘 שמירות', () => versions(f)],
    ['📂 פתיחה', () => openFile(f.id)],
    ['📋 שכפול (עותק סטטי — לא מקושר לטבלה)', () => duplicate({ id: f.id }, f.path)],
    ['⬇️ הורדה', () => download(f)],
    ['🗑️ הסרה מהעמוד…', () => wordsRemove(f), 'danger'],
  ]) : menu(base(f.path), [
    f.kind === 'file' ? ['🕘 גרסאות', () => versions(f)] : null,
    ['📂 פתיחה', () => openFile(f.id)],
    ['✏️ שינוי שם', () => rename({ id: f.id }, f.path)],
    ['↪️ העבר אל…', () => moveSheet({ id: f.id }, f.path)],
    ['📋 שכפול', () => duplicate({ id: f.id }, f.path)],
    ['⬇️ הורדה', () => download(f)],
    ['🗑️ מחיקה לסל', () => remove({ id: f.id }, `"${base(f.path)}"`, 1), 'danger'],
  ]);
  const dirMenu = d => menu(d.path, [
    ['📄 קובץ חדש כאן', () => newFile(d.path)],
    ['📁 תיקייה חדשה כאן', () => newDir(d.path)],
    ['⬆️ העלאה לכאן', () => pick(d.path)],
    ['✏️ שינוי שם', () => rename({ path: d.path }, d.path)],
    ['↪️ העבר אל…', () => moveSheet({ path: d.path }, d.path)],
    ['📋 שכפול', () => duplicate({ path: d.path }, d.path)],
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
  // ── ההסבה (F3): "🔄 ייבוא מחדש מהעמוד של היום" — בעלים בלבד. אין "פרויקט חדש" ואין "פרויקט ריק": לכלי יש פרויקט אחד
  //    (הכרעת הבעלים, team/decisions.md 2026-11-12). pf_blank נשאר בשרת, ואינו בממשק. ─────────
  const NOTE_ICON = { failed: '❌', warn: '⚠️', changed: '✏️', dropped: '➖' };
  function migrateReport(r, title) {
    sheet(title,
      r.snapshot ? h('p', { class: 'hint' }, `נקודת שחזור נשמרה לפני הפעולה (♻️ שחזור). ${r.trashed ? `הפרויקט הקודם — ${r.trashed} פריטים — בסל הפרויקט.` : ''}`) : null,
      r.taken ? h('h3', {}, 'מה נלקח מאיפה') : null,
      r.taken ? h('ul', { class: 'list' }, r.taken.map(([what, from]) => h('li', { class: 'card' }, h('div', { class: 'body' }, h('strong', { dir: 'auto' }, what), h('div', { class: 'hint' }, from))))) : null,
      r.notes?.length ? h('h3', {}, `מה השתנה בדרך, ומה לא הומר · ${r.notes.length}`) : null,
      r.notes?.length ? h('ul', { class: 'list' }, r.notes.map(([kind, text]) => h('li', { class: 'card' }, h('div', { class: 'body' }, (NOTE_ICON[kind] || '•') + ' ' + text)))) : null,
      h('p', { class: 'hint' }, 'הקבצים: ' + (r.files || []).join(' · ')));
  }
  /** "🔄 ייבוא מחדש": אישור אחד, pf_migrate (נקודת שחזור לפני; הקבצים הנוכחיים — לסל), ודוח. מחזיר true כשהייבוא נעשה. */
  async function reimport(question) {
    if (S.tabs.some(dirty) && !confirm('יש קבצים עם שינויים שלא נשמרו. להמשיך בלי לשמור אותם?')) return false;
    if (!confirm(question || 'לייבא מחדש את הפרויקט מהעמוד של היום?\nקבצי הפרויקט הנוכחי יעברו לסל.')) return false;
    try {
      const r = await api('pf_migrate', {});
      for (const d of S.docs.values()) { clearTimeout(d.timer); d.dirty = false; d.model?.dispose(); }
      S.docs.clear(); S.tabs = []; S.cur = null; persist();
      await refresh();
      bc?.postMessage({ t: 'saved' });
      migrateReport(r, 'הפרויקט יובא מחדש מהעמוד של היום');
      return true;
    } catch (e) { toast(e.message, true); return false; }
  }
  /** הפס "העמוד של היום עוד לא יובא" — כל עוד index.html הוא שלד הזריעה. בעלים: עם "ייבוא עכשיו"; אחרים — מי יכול. */
  function drawSeed(box, after) {
    if (!box) return;
    box.hidden = !S.seedOnly;
    if (!S.seedOnly) return;
    box.replaceChildren(h('span', {}, '⚠️ העמוד של היום עוד לא יובא', h('span', { class: 'seed-more' }, ' — מה שרואים כאן הוא שלד ריק, לא העמוד שבאתר.')),
      S.canPurge ? h('button', { type: 'button', class: 'btn primary sm', 'data-seed-run': true, onclick: async () => { if (await reimport('לייבא עכשיו את העמוד של היום לפרויקט?\nנשמרת נקודת שחזור לפני.')) after?.(); } }, '🔄 ייבוא עכשיו')
        : h('span', { class: 'hint' }, ' הייבוא — בידי בעל העמוד.'));
  }
  async function migrateSheet() {
    let last = null;
    try { last = (await api('pf_migrate_last')).last; } catch { /* בלי הדוח הקודם */ }
    const go = async () => { dlg.close(); await reimport(); };
    const dlg = sheet('🔄 ייבוא מחדש מהעמוד של היום',
      h('p', { class: 'hint' }, 'הפרויקט נבנה מחדש מהעמוד של היום. קבצי הפרויקט הנוכחי עוברים לסל (אפשר לשחזר), ונקודת שחזור נשמרת לפני. התיקייה from-current/ נשארת. העמוד הישן, הסטודיו, המילים והגרסאות — אינם משתנים.'),
      h('div', { class: 'pj-menu' },
        h('button', { type: 'button', class: 'btn primary', 'data-migrate-run': true, onclick: go }, '🔄 ייבוא מחדש'),
        last ? h('button', { type: 'button', class: 'btn', onclick: () => { dlg.close(); migrateReport(last, 'דוח הייבוא האחרון · ' + (last.at || '').slice(0, 10)); } }, '📋 דוח הייבוא האחרון') : null));
  }

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

  // ── F2b: קבצים פתוחים שהשרת שינה ──────────────────────────
  const stale = d => !d.dirty && !d.binary && !d.saving && S.byId.has(d.id) && S.byId.get(d.id).rev !== d.rev;
  /** מחליף את הטקסט של מסמך פתוח (לא מלוכלך) בגרסה מהשרת — כצעד אחד שאפשר לבטל. */
  function setDocText(doc, text, rev) {
    doc.rev = rev; doc.saved = text; doc.conflict = false;
    if (doc.model) {
      if (doc.model.getValue() !== text) {
        doc.model.pushStackElement();
        doc.model.pushEditOperations(null, [{ range: doc.model.getFullModelRange(), text }], () => null);
        doc.model.pushStackElement();
      }
    } else doc.text = text;
    doc.dirty = false;
    emit(doc, 'text'); emit(doc, 'state');
  }
  let syncing = false;
  async function syncOpen() {
    if (syncing) return;
    syncing = true;
    try {
      for (const doc of [...S.docs.values()].filter(stale)) {
        try { const d = await api('pf_read', undefined, `&id=${doc.id}`); if (!d.binary && stale(doc)) setDocText(doc, d.content, d.file.rev); } catch { /* בפעם הבאה */ }
      }
    } finally { syncing = false; }
    drawAll();
  }
  /** לפני פעולה שהשרת כותב בה לקבצים אחרים (הפניות, החלפה, ZIP) — כל מה שבעורך נשמר קודם. */
  async function saveAll() {
    for (const d of [...S.docs.values()]) if (d.dirty && !d.binary) await save(d.id);
    if ([...S.docs.values()].some(d => d.dirty)) throw new Error('יש קבצים שלא נשמרו — שמרו אותם קודם');
  }

  // ── F2b: העברה, שכפול, הפניות ─────────────────────────────
  const tq = t => (t.id ? `&id=${t.id}` : `&path=${encodeURIComponent(t.path)}`);
  /** רשימת הפניות: קובץ:שורה (פותח את הקובץ בשורה), הערך הישן, ובשינוי שם — החדש. הכול כטקסט. */
  function refList(refs, withNew) {
    return h('ul', { class: 'pj-refs' }, refs.map(r => h('li', { 'data-ref': r.path + ':' + r.line },
      h('button', { type: 'button', class: 'pj-ref-at', title: 'פתיחה בשורה', onclick: () => { $('#sheet')?.close(); openAt(r.path, r.line); } }, h('span', { dir: 'ltr' }, `${r.path}:${r.line}`)),
      h('code', { dir: 'ltr', class: 'pj-ref-old' }, r.old),
      withNew && r.new != null ? [h('span', { 'aria-hidden': 'true' }, ' ← '), h('code', { dir: 'ltr', class: 'pj-ref-new' }, r.new)] : null)));
  }
  /**
   * שינוי שם / העברה. קודם — מי מפנה לנתיב הישן (pf_refs). אין הפניות → מבצעים. יש → הרשימה, ובעל העמוד בוחר: לעדכן
   * אותן, או להעביר בלי לעדכן. לא חוסם. מחזיר null כשבוטל.
   */
  async function moveTo(target, from, to) {
    if (!to || to === from) return null;
    try { await saveAll(); } catch (e) { toast(e.message, true); return null; }
    let refs;
    try { refs = (await api('pf_refs', undefined, tq(target) + '&to=' + encodeURIComponent(to))).refs; } catch (e) { toast(e.message, true); return null; }
    const go = upd => run(() => api('pf_rename', { ...target, to, update_refs: upd }), upd ? `הועבר · ${refs.length} הפניות עודכנו` : 'הועבר');
    if (!refs.length) return go(false);
    return new Promise(res => {
      const dlg = sheet(`${refs.length} הפניות ל-${base(from)}`,
        h('p', { class: 'hint' }, `קבצים אחרים מפנים אל "${from}". אחרי ההעברה ל-"${to}" ההפניות האלה לא יגיעו אליו — אלא אם מעדכנים אותן. כך ייראו אחרי העדכון:`),
        refList(refs, true),
        h('div', { class: 'row-btns wrap' },
          h('button', { type: 'button', class: 'btn primary', 'data-refs-update': true, onclick: () => { res(go(true)); dlg.close(); } }, `העברה ועדכון ${refs.length} הפניות`),
          h('button', { type: 'button', class: 'btn', 'data-refs-skip': true, onclick: () => { res(go(false)); dlg.close(); } }, 'העברה בלי לעדכן'),
          h('button', { type: 'button', class: 'btn ghost', 'data-close': true, onclick: () => res(null) }, 'ביטול')));
    });
  }
  /** "העבר אל…" — בכל מכשיר: רשימת התיקיות; הבחירה מעבירה, עם בדיקת ההפניות. */
  function moveSheet(target, from) {
    const dirs = [''];
    const walk = n => { for (const d of [...n.dirs.values()].sort((a, b) => a.name.localeCompare(b.name, 'he'))) { dirs.push(d.path); walk(d); } };
    walk(tree());
    const isDir = !target.id;
    const can = dirs.filter(d => d !== parent(from) && !(isDir && (d === from || d.startsWith(from + '/'))));
    const dlg = sheet('העברה אל… · ' + base(from),
      h('p', { class: 'hint' }, 'בחרו תיקייה. במחשב אפשר גם לגרור שורה בעץ אל תיקייה.'),
      can.length ? h('div', { class: 'pj-menu pj-move' }, can.map(d => h('button', { type: 'button', class: 'btn', 'data-move-to': d, onclick: () => { dlg.close(); moveTo(target, from, join(d, base(from))); } },
        h('span', { 'aria-hidden': 'true' }, d ? '📁 ' : '🏠 '), h('span', { dir: 'auto' }, d || 'שורש הפרויקט')))) : h('p', { class: 'empty' }, 'אין תיקייה אחרת. צרו תיקייה ואז העבירו.'));
  }
  const DRAG = 'application/x-kol-move';
  function dragStart(e, target, path) {
    e.dataTransfer.setData(DRAG, JSON.stringify({ ...target, from: path || target.path }));
    e.dataTransfer.effectAllowed = 'move';
  }
  function dropMove(raw, dir) {
    let t;
    try { t = JSON.parse(raw); } catch { return; }
    const from = t.from;
    if (typeof from !== 'string' || parent(from) === dir || (!t.id && (dir === from || dir.startsWith(from + '/')))) return;
    moveTo(t.id ? { id: t.id } : { path: from }, from, join(dir, base(from)));
  }
  /** שם פנוי לעותק: "x (עותק).html", "x (עותק 2).html"… */
  function dupName(p) {
    const b = base(p), i = b.lastIndexOf('.'), stem = i > 0 ? b.slice(0, i) : b, x = i > 0 ? b.slice(i) : '';
    const taken = n => S.files.some(f => f.path.toLowerCase() === n.toLowerCase() || f.path.toLowerCase().startsWith(n.toLowerCase() + '/'));
    let n = join(parent(p), `${stem} (עותק)${x}`), k = 2;
    while (taken(n)) n = join(parent(p), `${stem} (עותק ${k++})${x}`);
    return n;
  }
  const duplicate = (target, path) => nameSheet('שכפול', 'הנתיב של העותק', dupName(path), 'שכפול',
    to => run(async () => { const d = await api('pf_copy', { ...target, to }); if (d.file && d.file.kind === 'file' && target.id) { await refresh(); openFile(d.file.id); } return d; }, 'שוכפל'),
    'העותק מתחיל היסטוריה משלו. ההפניות בתוכו — כמו במקור.');

  // ── F2b: גרסה מול הנוכחית, ושחזור ─────────────────────────
  /** השוואת שורות (LCS, אחרי קיצוץ ההתחלה והסוף המשותפים). [סימן, טקסט, שורה ישנה, שורה חדשה]. */
  function lineDiff(a, b) {
    const A = a.split('\n'), B = b.split('\n'), ops = [];
    let s = 0; while (s < A.length && s < B.length && A[s] === B[s]) s++;
    let e = 0; while (e < A.length - s && e < B.length - s && A[A.length - 1 - e] === B[B.length - 1 - e]) e++;
    const a2 = A.slice(s, A.length - e), b2 = B.slice(s, B.length - e), n = a2.length, m = b2.length;
    for (let i = 0; i < s; i++) ops.push([' ', A[i], i + 1, i + 1]);
    if (n * m > 4e6) {                                           // ענק — בלי יישור: הכול הוסר / נוסף
      a2.forEach((l, i) => ops.push(['-', l, s + i + 1, 0])); b2.forEach((l, j) => ops.push(['+', l, 0, s + j + 1]));
    } else {
      const W = m + 1, L = new Uint32Array((n + 1) * W);
      for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) L[i * W + j] = a2[i] === b2[j] ? L[(i + 1) * W + j + 1] + 1 : Math.max(L[(i + 1) * W + j], L[i * W + j + 1]);
      let i = 0, j = 0;
      while (i < n || j < m) {
        if (i < n && j < m && a2[i] === b2[j]) { ops.push([' ', a2[i], s + i + 1, s + j + 1]); i++; j++; }
        else if (i < n && (j === m || L[(i + 1) * W + j] >= L[i * W + j + 1])) { ops.push(['-', a2[i], s + i + 1, 0]); i++; }   // הוסר — לפני נוסף
        else { ops.push(['+', b2[j], 0, s + j + 1]); j++; }
      }
    }
    for (let k = 0; k < e; k++) ops.push([' ', A[A.length - e + k], A.length - e + k + 1, B.length - e + k + 1]);
    return ops;
  }
  /** התצוגה: שורות שהוסרו / נוספו, עם 3 שורות הקשר; רצף ארוך של שורות זהות — מקופל. טקסט בלבד. */
  function diffView(a, b) {
    const ops = lineDiff(a, b), keep = new Uint8Array(ops.length), C = 3;
    ops.forEach((o, i) => { if (o[0] !== ' ') for (let k = Math.max(0, i - C); k <= Math.min(ops.length - 1, i + C); k++) keep[k] = 1; });
    const add = ops.filter(o => o[0] === '+').length, del = ops.filter(o => o[0] === '-').length;
    if (!add && !del) return h('p', { class: 'empty', 'data-diff-none': true }, 'אין הבדל — התוכן זהה.');
    const rows = [];
    for (let i = 0; i < ops.length; i++) {
      if (!keep[i]) { let j = i; while (j < ops.length && !keep[j]) j++; rows.push(h('div', { class: 'pj-dl gap' }, `⋯ ${j - i} שורות זהות`)); i = j - 1; continue; }
      const [sg, t, o, nn] = ops[i];
      rows.push(h('div', { class: 'pj-dl' + (sg === '+' ? ' add' : sg === '-' ? ' del' : '') },
        h('span', { class: 'pj-ln' }, o || ''), h('span', { class: 'pj-ln' }, nn || ''), h('span', { class: 'pj-dt' }, (sg === ' ' ? '  ' : sg + ' ') + t)));
    }
    return h('div', {}, h('p', { class: 'hint', 'data-diff-sum': `${del}-${add}` }, `${del} שורות הוסרו · ${add} נוספו`), h('div', { class: 'pj-diffv', dir: 'ltr' }, rows));
  }
  async function diffSheet(f, v) {
    const body = h('div', { class: 'pj-diff-wrap' }, h('p', { class: 'hint' }, 'טוען…'));
    sheet(`גרסה ${v.rev} מול הנוכחית · ${base(f.path)}`, body);
    try {
      const doc = S.docs.get(f.id);
      const old = await api('pf_version', undefined, `&id=${f.id}&hid=${v.hid}`);
      const live = doc && !doc.binary ? { text: doc.model ? doc.model.getValue() : doc.text, label: doc.dirty ? 'מה שבעורך עכשיו (לא נשמר)' : 'הגרסה הנוכחית' } : null;
      const cur = live || await api('pf_read', undefined, `&id=${f.id}`).then(d => (d.binary ? null : { text: d.content, label: 'הגרסה הנוכחית' }));
      if (old.binary || !cur) { body.replaceChildren(h('p', { class: 'empty' }, 'קובץ בינארי — אין השוואה בטקסט.')); return; }
      body.replaceChildren(h('p', { class: 'hint' }, `מימין לשמאל: גרסה ${v.rev} (${v.by || ''} · ${when(v.at)}) מול ${cur.label}. − הוסר מאז · + נוסף מאז.`),
        diffView(old.content, cur.text),
        h('div', { class: 'row-btns wrap' },
          h('button', { type: 'button', class: 'btn', onclick: () => versions(f) }, '→ חזרה לגרסאות'),
          h('button', { type: 'button', class: 'btn primary', 'data-ver-restore': v.hid, onclick: () => restoreVersion(f, v) }, `↩️ שחזור גרסה ${v.rev}`)));
    } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }
  async function restoreVersion(f, v) {
    const doc = S.docs.get(f.id), cur = S.byId.get(f.id) || f;
    if (doc?.dirty && !confirm('בקובץ יש שינויים שלא נשמרו. השחזור יחליף אותם. להמשיך?')) return;
    if (!confirm(`לשחזר את גרסה ${v.rev} של "${base(cur.path)}"?\nהתוכן שלה יישמר כגרסה חדשה — שום גרסה אינה נמחקת.`)) return;
    try {
      if (doc) clearTimeout(doc.timer);
      const d = await api('pf_version_restore', { id: f.id, hid: v.hid, rev: doc?.rev ?? cur.rev });
      if (doc && !d.binary) setDocText(doc, d.content, d.file.rev);
      Object.assign(cur, d.file);
      if (d.kol) { S.kol = d.kol; drawBars(); }
      bc?.postMessage({ t: 'saved', path: cur.path });
      toast(`שוחזר — גרסה ${v.rev} נשמרה כגרסה ${d.file.rev}`);
      await refresh();
      versions(cur);
    } catch (e) { toast(e.message, true); }
  }

  // ── F2b: חיפוש והחלפה בכל הקבצים ─────────────────────────
  const last = { q: '', regex: false, case: false, with: '' };
  function searchSheet() {
    const qIn = h('input', { type: 'search', dir: 'auto', value: last.q, 'aria-label': 'מה לחפש', placeholder: 'מה לחפש', autocomplete: 'off', spellcheck: 'false', 'data-s-q': true });
    const rx = h('input', { type: 'checkbox', 'data-s-rx': true }), cs = h('input', { type: 'checkbox', 'data-s-case': true });
    rx.checked = last.regex; cs.checked = last.case;
    const rep = h('input', { type: 'text', dir: 'auto', value: last.with, 'aria-label': 'להחליף ב-', placeholder: 'להחליף ב- (לא חובה)', autocomplete: 'off', spellcheck: 'false', 'data-s-with': true });
    const out = h('div', { class: 'pj-sres', 'aria-live': 'polite' });
    const opts = () => { Object.assign(last, { q: qIn.value, regex: rx.checked, case: cs.checked, with: rep.value }); return { q: last.q, regex: last.regex, case: last.case }; };
    const qs = o => `&q=${encodeURIComponent(o.q)}${o.regex ? '&regex=1' : ''}${o.case ? '&case=1' : ''}`;
    async function find() {
      const o = opts();
      if (!o.q) return;
      out.replaceChildren(h('p', { class: 'hint' }, 'מחפש…'));
      try { await saveAll(); } catch { /* מחפשים במה שנשמר */ }
      try {
        const d = await api('pf_search', undefined, qs(o));
        out.replaceChildren(h('p', { class: 'hint', 'data-s-total': d.total }, d.total ? `${d.total} התאמות ב-${d.files.length} קבצים${d.cut ? ' — הרשימה קוצרה; צמצמו את החיפוש' : ''}` : 'לא נמצא.'),
          ...d.files.map(f => h('details', { open: true, class: 'pj-sfile' },
            h('summary', {}, h('span', { dir: 'auto' }, f.path), h('small', {}, ' · ' + f.count)),
            h('ul', {}, f.hits.map(x => h('li', {}, h('button', { type: 'button', class: 'pj-hit', 'data-hit': `${f.path}:${x.line}`, onclick: () => { $('#sheet')?.close(); openAt(f.path, x.line, null, { col: x.col, len: x.len }); } },
              h('span', { class: 'pj-ln' }, x.line), h('span', { class: 'pj-hit-t', dir: 'ltr' }, x.text))))))));
      } catch (e) { out.replaceChildren(h('p', { class: 'error' }, e.message)); }
    }
    async function preview() {
      const o = opts();
      if (!o.q) return;
      try { await saveAll(); } catch (e) { toast(e.message, true); return; }
      out.replaceChildren(h('p', { class: 'hint' }, 'מכין תצוגה מקדימה…'));
      let d;
      try { d = await api('pf_replace', { ...o, with: last.with }); } catch (e) { out.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
      if (!d.files.length) { out.replaceChildren(h('p', { class: 'hint' }, 'אין מה להחליף.')); return; }
      const picks = new Map();
      const go = h('button', { type: 'button', class: 'btn primary', 'data-s-apply': true, onclick: async () => {
        const chosen = d.files.filter(f => picks.get(f.id).checked);
        if (!chosen.length) return;
        go.disabled = true;
        try {
          const r = await api('pf_replace', { ...o, with: last.with, apply: true, ids: chosen.map(f => f.id), revs: Object.fromEntries(chosen.map(f => [f.id, f.rev])) });
          toast(`${r.replaced} החלפות ב-${r.files.length} קבצים · נקודת שחזור נשמרה לפני`);
          await refresh();
          for (const f of r.files) bc?.postMessage({ t: 'saved', path: f.path });
          find();
        } catch (e) { go.disabled = false; out.prepend(h('p', { class: 'error' }, e.message)); }
      } }, `החלפה ב-${d.files.length} קבצים`);
      const count = () => { const n = d.files.filter(f => picks.get(f.id).checked); go.textContent = `החלפה ב-${n.length} קבצים`; go.disabled = !n.length; };
      out.replaceChildren(h('p', { class: 'hint' }, `תצוגה מקדימה: ${d.total} החלפות ב-${d.files.length} קבצים. עדיין לא נכתב דבר. הורידו סימון מקובץ שלא רוצים לשנות. לפני ההחלפה נשמרת נקודת שחזור, ולכל קובץ — גרסה חדשה.`),
        ...d.files.map(f => {
          const cb = h('input', { type: 'checkbox', checked: true, 'data-s-file': f.path, onchange: count });
          picks.set(f.id, cb);
          return h('div', { class: 'pj-sfile' },
            h('label', { class: 'pj-check' }, cb, h('span', { dir: 'auto' }, f.path), h('small', {}, ' · ' + f.count)),
            h('ul', { class: 'pj-samples' }, f.samples.map(x => h('li', {},
              h('div', { class: 'pj-dl del', dir: 'ltr' }, h('span', { class: 'pj-ln' }, x.line), h('span', { class: 'pj-dt' }, '- ' + x.before)),
              h('div', { class: 'pj-dl add', dir: 'ltr' }, h('span', { class: 'pj-ln' }, x.line), h('span', { class: 'pj-dt' }, '+ ' + x.after))))));
        }),
        h('div', { class: 'row-btns' }, go));
    }
    qIn.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); find(); } });
    sheet('🔎 חיפוש והחלפה בכל הקבצים',
      h('label', { class: 'f' }, h('span', {}, 'חיפוש'), qIn),
      h('div', { class: 'row-btns wrap' }, h('label', { class: 'pj-check' }, rx, ' ביטוי רגולרי'), h('label', { class: 'pj-check' }, cs, ' רישיות (Aa)')),
      h('label', { class: 'f' }, h('span', {}, 'החלפה'), rep),
      h('div', { class: 'row-btns wrap' },
        h('button', { type: 'button', class: 'btn primary', 'data-s-find': true, onclick: find }, '🔎 חיפוש'),
        h('button', { type: 'button', class: 'btn', 'data-s-preview': true, onclick: preview }, '🔁 החלפה — תצוגה מקדימה')),
      out);
    setTimeout(() => qIn.focus(), 50);
    if (last.q) find();
  }

  // ── F2b: ZIP הלוך-חזור ────────────────────────────────────
  const zipMenu = () => menu('🗜️ ZIP', [
    ['⬇️ הורדת הפרויקט כולו כ-ZIP', () => zipDownload()],
    ['📦 העלאת ZIP — קודם תצוגה של מה ישתנה', () => zipPick()],
  ]);
  async function zipDownload() {
    try {
      const res = await fetch('api.php?action=pf_zip');
      if (!res.ok) { let m = 'ההורדה נכשלה'; try { m = (await res.json()).error || m; } catch { /* לא JSON */ } throw new Error(m); }
      const url = URL.createObjectURL(new Blob([await res.arrayBuffer()], { type: 'application/zip' }));
      const a = h('a', { href: url, download: `kol-project-${new Date().toISOString().slice(0, 10)}.zip` });
      document.body.append(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 30000);
    } catch (e) { toast(e.message, true); }
  }
  function zipPick() {
    const input = h('input', { type: 'file', accept: '.zip,application/zip', hidden: true, 'data-zip-input': true, onchange: () => { if (input.files[0]) zipPlan(input.files[0]); input.remove(); } });
    document.body.append(input); input.click();
  }
  /** ה-ZIP עצמו כגוף הבקשה (לא JSON). אותן הגנות: X-Kol + CSRF. */
  async function zipSend(file, query) {
    let res, data;
    try {
      res = await fetch('api.php?action=pf_zip_import' + query, { method: 'POST', headers: { 'Content-Type': 'application/zip', 'X-Kol': '1', 'X-CSRF': state.csrf }, body: file });
      data = await res.json();
    } catch { throw new Error(res ? 'תשובה לא תקינה מהשרת' : 'אין חיבור לשרת'); }
    if (!data.ok) throw Object.assign(new Error(data.error || 'שגיאה'), { status: res.status, data });
    return data;
  }
  async function zipPlan(file) {
    try { await saveAll(); } catch (e) { toast(e.message, true); return; }
    sheet('📦 ייבוא ZIP', h('p', { class: 'hint' }, `בודק את "${file.name}"…`));
    let plan;
    try { plan = (await zipSend(file, '')).plan; } catch (e) { sheet('ה-ZIP לא ייובא', h('p', { class: 'error', 'data-zip-error': true }, e.message)); return; }
    const c = plan.counts, list = (key, title, items, open) => items.length ? h('details', { class: 'pj-zlist', open: open || null, 'data-zip-list': key },
      h('summary', {}, `${title} · ${c[key] ?? items.length}`), h('ul', {}, items.map(p => h('li', { dir: 'auto' }, typeof p === 'string' ? p : `${p.path} — ${size(p.size)}`)))) : null;
    const can = !plan.errors.length && (c.add + c.replace + c.dirs) > 0;
    const dlg = sheet(`📦 ייבוא ZIP · ${file.name}`,
      h('p', { class: 'hint' }, 'עדיין לא נכתב דבר. כך ישתנה הפרויקט:'),
      plan.errors.length ? h('div', { class: 'pj-warn', role: 'alert' }, h('strong', {}, 'אי אפשר לייבא:'), h('ul', {}, plan.errors.map(t => h('li', { dir: 'auto' }, t)))) : null,
      list('add', '➕ יתווספו', plan.add, true),
      list('replace', '♻️ יוחלפו (נקודת שחזור נשמרת לפני; לכל קובץ — גרסה חדשה)', plan.replace, true),
      list('dirs', '📁 תיקיות חדשות', plan.dirs),
      list('same', '＝ זהים — לא ישתנו', plan.same),
      list('keep', '· בפרויקט ואינם ב-ZIP — לא ישתנו ולא יימחקו', plan.keep),
      list('skipped', 'דולגו (קובצי מערכת של macOS)', plan.skipped),
      plan.big.length ? h('div', {}, list('big', `לא ייובאו — גדולים ממגבלת הגודל (${size(plan.limit.text)})`, plan.big, true), h('p', { class: 'hint' }, plan.limit.who)) : null,
      h('div', { class: 'row-btns wrap' },
        can && h('button', { type: 'button', class: 'btn primary', 'data-zip-apply': true, onclick: async e => {
          e.target.disabled = true;
          try {
            const r = await zipSend(file, '&apply=1&token=' + encodeURIComponent(plan.token));
            dlg.close();
            toast(`יובא: ${r.done.add} נוספו · ${r.done.replace} הוחלפו${r.snapshot ? ' · נקודת שחזור נשמרה' : ''}`);
            await refresh();
            bc?.postMessage({ t: 'saved' });
          } catch (err) { e.target.disabled = false; toast(err.message, true); }
        } }, `ייבוא · ${c.add} חדשים, ${c.replace} מוחלפים`),
        !can && !plan.errors.length && h('p', { class: 'hint' }, 'אין מה לייבא — הכול כבר בפרויקט.'),
        h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'ביטול')));
  }

  // ── כלי העורך ─────────────────────────────────────────────
  const edRun = id => { S.editor?.focus(); S.editor?.getAction(id)?.run(); };
  function applyPrefs() {
    S.editor?.updateOptions({ fontSize: prefs.font, wordWrap: prefs.wrap ? 'on' : 'off' });
    persist();
  }
  const toolsMenu = () => menu('כלי העורך', [
    /\.html?$/i.test(S.byId.get(S.cur)?.path || '') ? ['🎯 בחר בעורך החזותי (Ctrl+Alt+E)', () => pickInEditor(S.editor, S.cur)] : null,
    ['🔍 חיפוש בקובץ', () => edRun('actions.find')],
    S.canPurge ? ['🔄 ייבוא מחדש מהעמוד של היום', () => migrateSheet()] : null,   // גם כאן — בטלפון רשימת הקבצים סגורה, והכפתור שבה אינו נראה
    ['🔁 חיפוש והחלפה', () => edRun('editor.action.startFindReplaceAction')],
    ['🔎 בכל הקבצים — חיפוש, והחלפה עם תצוגה מקדימה', () => searchSheet()],
    ['🗜️ ZIP — הורדה והעלאה של הפרויקט', () => zipMenu()],
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
    el.seed = h('p', { class: 'pj-warn pj-seed', role: 'alert', 'data-seed': true, hidden: true });
    el.tree = h('ul', { class: 'pj-tree', 'aria-label': 'קבצי הפרויקט' });
    el.trash = h('button', { type: 'button', class: 'btn sm', onclick: trashSheet }, '🗑️ סל');
    el.count = h('span', { class: 'hint' });
    el.mig = h('button', { type: 'button', class: 'btn sm', 'data-migrate': true, hidden: true, title: 'ייבוא מחדש של הפרויקט מהעמוד של היום', onclick: migrateSheet }, '🔄 ייבוא מחדש מהעמוד של היום');
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
        h('button', { type: 'button', class: 'btn sm', 'data-search': true, onclick: () => searchSheet() }, '🔎 חיפוש'),
        h('button', { type: 'button', class: 'btn sm', 'data-zip': true, onclick: () => zipMenu() }, '🗜️ ZIP'),
        el.trash, el.mig),
      el.tree, el.count);
    // גרירה מהמחשב אל תיקייה (או אל העץ — לשורש)
    aside.addEventListener('dragover', e => { const t = e.dataTransfer?.types || []; if (t.includes('Files') || t.includes(DRAG)) { e.preventDefault(); aside.classList.add('drop'); } });
    aside.addEventListener('dragleave', () => aside.classList.remove('drop'));
    aside.addEventListener('drop', e => {
      const moving = e.dataTransfer?.getData(DRAG);              // גרירה בתוך העץ = העברה לתיקייה (במחשב; בכל מכשיר — "העבר אל…")
      if (moving) { e.preventDefault(); aside.classList.remove('drop'); dropMove(moving, e.target.closest('[data-dir]')?.dataset.dir || ''); return; }
      if (!e.dataTransfer?.files.length) return;
      e.preventDefault(); aside.classList.remove('drop');
      upload(e.target.closest('[data-dir]')?.dataset.dir || '', [...e.dataTransfer.files]);
    });
    host.replaceChildren(h('div', { class: 'pj' },
      h('p', { class: 'pj-note', role: 'note' }, 'הקבצים כאן עדיין אינם מתפרסמים. הפרסום מהקבצים ייפתח בשלב הבא.'),
      el.kol, el.seed,
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
      addPick(S.editor, () => S.cur);
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
  // ── "בחר בעורך" (04 §3): מהסמן בקוד אל האלמנט בעורך החזותי — תפריט ההקשר של עורך הקוד, וקיצור ──
  function pickInEditor(ed, id) {
    const f = S.byId.get(id), m = ed?.getModel();
    if (!f || !m || !/\.html?$/i.test(f.path)) { toast('"בחר בעורך" פועל בקובץ HTML', true); return; }
    Editor.selectAt(f.path, m.getOffsetAt(ed.getPosition()));
  }
  function addPick(ed, idOf) {
    ed.addAction({ id: 'kol.pickInEditor', label: 'בחר בעורך החזותי', keybindings: [monaco.KeyMod.CtrlCmd | monaco.KeyMod.Alt | monaco.KeyCode.KeyE],
      precondition: "editorLangId == 'html'", contextMenuGroupId: 'navigation', contextMenuOrder: 0, run: () => pickInEditor(ed, idOf()) });
  }
  /**
   * עורך קוד שני, למבט המפוצל של העורך החזותי: אותו מסמך (אותו מודל של Monaco — אותו טקסט, אותה היסטוריה, אותה שמירה).
   * נוצר פעם אחת לכל חיי הדף (Monaco שנסגר משבית פעולות בעורך שנפתח אחריו), ומחובר מחדש למכל שמבקש אותו.
   */
  function splitEditor(host, id) {
    const doc = S.docs.get(id);
    if (!monaco || !doc?.model) return null;
    if (!S.split) {
      el.split = h('div', { class: 'pj-split', dir: 'ltr' });
      S.split = monaco.editor.create(el.split, { model: null, automaticLayout: true, theme: dark() ? 'vs-dark' : 'vs', fontSize: prefs.font, wordWrap: 'on', minimap: { enabled: false }, tabSize: 2,
        scrollBeyondLastLine: false, fixedOverflowWidgets: true, unicodeHighlight: { nonBasicASCII: false, ambiguousCharacters: false }, stickyScroll: { enabled: false }, lineNumbersMinChars: 3 });
      S.split.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => save(S.splitId));
      addPick(S.split, () => S.splitId);
    }
    S.splitId = id;
    host.replaceChildren(el.split);
    S.split.setModel(doc.model); S.split.layout();
    const ed = S.split, m = doc.model;
    return { ed, offset: () => m.getOffsetAt(ed.getPosition()),
      /** מסמן טווח (בתווים) בלי להעביר אליו את המיקוד. */
      mark(from, to) { const a = m.getPositionAt(from), b = m.getPositionAt(to), r = new monaco.Range(a.lineNumber, a.column, b.lineNumber, b.column); ed.setSelection(r); ed.revealRangeInCenterIfOutsideViewport(r); },
      selection() { const r = ed.getSelection(); return r ? { from: m.getOffsetAt(r.getStartPosition()), to: m.getOffsetAt(r.getEndPosition()) } : null; },
      onCursor(cb) { return ed.onDidChangeCursorPosition(e => { if (e.source === 'mouse' || e.source === 'keyboard') cb(); }); } };
  }
  /** פותח קובץ בעורך הקוד. line — שורה; range — {from, to} בתווים: הטווח מסומן (למשל האלמנט שנבחר בעורך החזותי). */
  async function openAt(path, line, range, cols) {
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
      if (cols?.col) S.editor.setSelection(new monaco.Range(ln, cols.col, ln, cols.col + (cols.len || 0)));   // ההתאמה מהחיפוש בכל הקבצים
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
      pf_migrate: '🔄 ייבא מחדש את הפרויקט מהעמוד של היום', pf_blank: '📄 התחיל פרויקט ריק', pf_delete: '📁 העביר לסל הפרויקט', pf_restore: '📁 שחזר מסל הפרויקט', pf_purge: '📁 מחק סופית מהפרויקט', pf_upload: '📁 העלה מדיה לפרויקט', pf_limit: '📏 שינה מגבלה של הפרויקט',
      pf_copy: '📋 שכפל בפרויקט', pf_version_restore: '↩️ שחזר גרסה של קובץ', pf_replace: '🔁 החליף בכל קובצי הפרויקט', pf_zip: '🗜️ הוריד את הפרויקט כ-ZIP', pf_zip_import: '📦 ייבא ZIP לפרויקט' });
  });

  return { oldTemplate: OLD_TPL, seed: { get only() { return !!S.seedOnly; }, draw: drawSeed }, view, unmount, limits, loadMonaco, live, docs: Docs, openAt, splitEditor, versions, _state: S, _open: openFile, _save: save };
})();
