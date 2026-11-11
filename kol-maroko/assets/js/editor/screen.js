/**
 * קול מרוקו — 🎨 עורך (פרוסה V1a): קובץ HTML אמיתי מהפרויקט בקנבס דומם; בחירה, שביל ניווט, מחיקה וביטול, "פתח בקוד".
 *
 * אין כאן מקור אמת משלו: הטקסט, ההיסטוריה וה-rev הם של שירות המסמכים (Project.docs — אותו מסמך שעורך הקוד עורך).
 * כל שינוי — פעולה חזותית, ביטול, או הקלדה בעורך הקוד — מגיע מאותו מקום: המסמך השתנה ← הקנבס מתעדכן בתיקון טקסט.
 * הבידוד: הקנבס הוא iframe בלי allow-scripts; שמות מחלקות, טקסטים ושמות קבצים מוצגים כאן כטקסט בלבד (h / textContent),
 * ואין בקובץ הזה innerHTML.
 */
'use strict';

const Editor = (() => {
  const JS = ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/canvas.js', 'assets/js/editor/navigator.js', 'assets/js/editor/details.js', 'assets/js/editor/report.js'];
  const LS = 'kol-editor';
  const DEVICES = [{ id: 'desktop', icon: '🖥️', label: 'מחשב', width: 1280 }, { id: 'tablet', icon: '📱', label: 'טאבלט', width: 820, wide: true }, { id: 'mobile', icon: '📱', label: 'טלפון', width: 390 }];
  const SYNC_MS = 120;                                                     // הקלדה בקוד ← קנבס: הרבה מתחת ל-300ms
  const S = { host: null, canvas: null, doc: null, off: null, file: null, sheets: new Map(), sheetOff: [], readOnly: '', device: matchMedia('(max-width: 999px)').matches ? 'mobile' : 'desktop', timer: 0, own: null, token: null, busy: false, nav: null, info: null, split: false, code: null, codeOff: null, mute: false, fromCode: false, curT: 0 };
  let pending = null;
  const phone = () => matchMedia('(max-width: 999px)').matches;
  const el = {};
  let loading = null, K = null;
  let prefs = {}; try { prefs = JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch { prefs = {}; }
  const keep = () => { try { localStorage.setItem(LS, JSON.stringify(prefs)); } catch { /* אחסון חסום — לא חוסם עבודה */ } };

  const loadJs = () => (loading = loading || JS.reduce((p, src) => p.then(() => new Promise((ok, bad) => {
    if (document.querySelector('script[src="' + src + '"]')) return ok();
    document.head.append(h('script', { src, onload: ok, onerror: () => bad(new Error('לא נטען: ' + src)) }));
  })), Promise.resolve()).catch(e => { loading = null; throw e; }));

  const dirOf = p => (p.includes('/') ? p.slice(0, p.lastIndexOf('/') + 1) : '');
  /** נתיב יחסי (href / url) ביחס לתיקייה בפרויקט ← נתיב בפרויקט; null לכתובת חיצונית. */
  function resolve(dir, ref) {
    if (!ref || /^([a-z][a-z0-9+.-]*:|\/\/|#)/i.test(ref)) return null;
    const clean = ref.split(/[?#]/)[0], parts = (clean[0] === '/' ? clean.slice(1) : dir + clean).split('/'), out = [];
    for (const p of parts) { if (p === '..') out.pop(); else if (p && p !== '.') out.push(p); }
    try { return decodeURIComponent(out.join('/')); } catch { return out.join('/'); }
  }
  const pvUrl = path => new URL(S.token.base + S.token.token + '/' + path.split('/').map(encodeURIComponent).join('/'), location.href).href;
  /** כתובות יחסיות בתוך קובץ CSS נפתרות מול התיקייה שלו — בקנבס הוא <style>, ולכן הן נכתבות מחדש (ב-DOM בלבד). */
  const cssForCanvas = (path, text) => (S.token ? text.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, (all, q, ref) => { const p = resolve(dirOf(path), ref.trim()); return p == null ? all : 'url("' + pvUrl(p) + '")'; }) : text);

  // ── טעינת עמוד ────────────────────────────────────────────
  async function open(id) {
    if (S.busy) return; S.busy = true;
    try {
      close();
      const files = await Project.docs.files(), f = files.find(x => x.id === id);
      if (!f) throw new Error('הקובץ אינו בפרויקט');
      status('טוען…');
      const doc = await Project.docs.open(id);
      if (!doc) throw new Error('הקובץ לא נפתח');
      if (S.host === null) return;
      S.file = f; S.doc = doc; S.readOnly = ''; prefs.page = f.path; keep();
      let src;
      if (doc.binary) {                                                     // לא UTF-8: רואים ובוחרים, לא כותבים
        src = new TextDecoder('windows-1255').decode(await Project.docs.bytes(id));
        S.readOnly = 'הקובץ אינו בקידוד UTF-8 — נפתח לקריאה בלבד. כדי לערוך: שומרים אותו מחדש כ-UTF-8.';
      } else src = doc.text();
      try { S.token = (await api('pv_token')); } catch { S.token = null; }  // תמונות ומדיה של הפרויקט — מכתובת התצוגה המבודדת (F4)
      // קובצי ה-CSS שהעמוד מקשר אליהם — מהפרויקט, לא מהרשת; כל אחד מהם מסמך משותף, ושינוי בו מגיע לקנבס
      S.sheets = new Map();
      for (const m of src.matchAll(/<link\b[^>]*>/gi)) {
        const href = (/\bhref\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i.exec(m[0]) || []).slice(1).find(x => x != null);
        const path = resolve(dirOf(f.path), href || ''), cf = path && Project.docs.byPath(path);
        if (!cf || S.sheets.has(href)) continue;
        const cd = await Project.docs.open(cf.id);
        if (!cd || cd.binary) continue;
        S.sheets.set(href, { path, doc: cd });
        S.sheetOff.push(cd.on(kind => { if (kind === 'text' && S.canvas) { S.canvas.setSheet(href, cssForCanvas(path, cd.text())); drawInfo(); } }));
      }
      const c = S.canvas;
      c.baseHref = S.token ? pvUrl(dirOf(f.path)) : '';
      c.verify = !!state.user?.developer || prefs.verify === true;         // מצב פיתוח: שקילות אחרי כל פעולה
      c.capOff = false;
      await c.load(src, href => { const s = S.sheets.get(href); return s ? cssForCanvas(s.path, s.doc.text()) : null; });
      c.setViewport(deviceWidth());
      loadMarks();
      if (!doc.binary) S.off = doc.on(kind => (kind === 'text' ? onDocText() : drawState()));
      if (S.readOnly) note(S.readOnly);
      mountCode();
      drawAll();
      if (pending && pending.path === f.path) applyAt(pending.off);
      pending = null;
    } catch (e) { status(''); note(e.message, true); }
    finally { S.busy = false; }
  }
  function close() {
    clearTimeout(S.timer); S.off?.(); S.off = null; S.sheetOff.forEach(f => f()); S.sheetOff = [];
    S.doc = null; S.file = null; S.own = null;
  }

  // ── המסמך השתנה ← הקנבס ───────────────────────────────────
  function onDocText() {
    clearTimeout(S.timer);
    if (S.own) return syncCanvas();                                        // פעולה שלנו: מיד, ועם התיקונים המדויקים
    S.timer = setTimeout(syncCanvas, SYNC_MS);
  }
  /** מביא את הקנבס לטקסט של המסמך, בתיקון אחד: הקטע שבין התחילית המשותפת לסיומת המשותפת. */
  function syncCanvas() {
    const c = S.canvas, doc = S.doc;
    if (!c || !doc || !c.m) return;
    const now = doc.text(), was = c.src, own = S.own; S.own = null;
    if (now === was) return drawAll();
    let patches = own && K.applyPatches(was, own) === now ? own : null;
    if (!patches) {
      let a = 0; const max = Math.min(was.length, now.length);
      while (a < max && was.charCodeAt(a) === now.charCodeAt(a)) a++;
      let b = 0; while (b < max - a && was.charCodeAt(was.length - 1 - b) === now.charCodeAt(now.length - 1 - b)) b++;
      patches = [{ from: a, to: was.length - b, text: now.slice(a, now.length - b) }];
    }
    try { c.patch(patches); }
    catch (e) { console.error(e); c.load(now, c.sheets).then(drawAll); return; }   // נפילה בטוחה: טעינה מלאה
    drawAll();
  }

  // ── "בחר בעורך" ומבט מפוצל (04 §3) ──────────────────────
  /** האלמנט הכתוב העמוק ביותר שהמיקום בקובץ נמצא בתוכו. */
  function nodeAt(off) { let best = null; const m = S.canvas?.m; if (m?.root) K.walk(m.root, n => { if (!n.implied && n.from <= off && off <= n.to && (!best || n.from >= best.from)) best = n; }); return best; }
  function applyAt(off) {
    const c = S.canvas; if (!c?.m || !S.doc) return;
    if (!S.doc.binary && c.src !== S.doc.text()) { clearTimeout(S.timer); syncCanvas(); }
    const n = nodeAt(off);
    if (n) { c.select(n); c.reveal(n); } else note('במקום הזה בקובץ אין אלמנט');
  }
  /** "בחר בעורך": מעורך הקוד (או מהמבט המפוצל) — האלמנט שהסמן בתוכו נבחר בקנבס. */
  async function selectAt(path, off) {
    if (S.host && S.file?.path === path && S.canvas?.m) return applyAt(off);
    pending = { path, off };
    if (S.host) { const f = Project.docs.byPath(path); if (f) { el.page.value = f.id; await open(f.id); } return; }
    location.hash = '#/editor/' + encodeURIComponent(path);
  }
  function mountCode() {
    S.codeOff?.dispose(); S.codeOff = null; S.code = null;
    if (!S.split || !el.pane) return;
    const sp = S.doc && !S.doc.binary && S.file ? Project.splitEditor(el.pane, S.file.id) : null;
    if (!sp) { el.pane.replaceChildren(h('p', { class: 'hint' }, S.doc?.binary ? 'הקובץ אינו UTF-8 — אין לו עורך קוד.' : 'עורך הקוד לא נטען.')); return; }
    S.code = sp;
    S.codeOff = sp.onCursor(() => { if (S.mute) return; clearTimeout(S.curT); S.curT = setTimeout(fromCode, 150); });
    markInCode();
  }
  /** הסמן בקוד ← האלמנט בקנבס. */
  function fromCode() {
    const c = S.canvas; if (!S.code || !c?.m || !S.doc) return;
    if (c.src !== S.doc.text()) { clearTimeout(S.timer); syncCanvas(); }
    const n = nodeAt(S.code.offset());
    if (n && n !== c.selected) { S.fromCode = true; try { c.select(n); c.reveal(n); } finally { S.fromCode = false; } }
  }
  /** הבחירה בקנבס ← הטווח מסומן בקוד (בלי להזיז את המיקוד). */
  function markInCode() {
    const c = S.canvas; let n = c?.selected;
    if (!S.code || S.fromCode || !n || !S.doc || c.src !== S.doc.text()) return;
    while (n && n.implied) n = n.parent;
    if (!n) return;
    S.mute = true; try { S.code.mark(n.from, K.endOf(c.m, n)); } finally { S.mute = false; }
  }
  function split(on) {
    S.split = on == null ? !S.split : on; prefs.split = S.split; keep();
    el.root.classList.toggle('split', S.split); el.splitBtn.setAttribute('aria-pressed', String(S.split));
    if (S.split && !phone() && innerWidth < 1500) panel('info', false);       // מקום לקוד ולקנבס
    if (!S.split) el.pane.replaceChildren();
    mountCode(); tab('canvas');
  }
  /** בטלפון: לשוניות במקום פיצול. */
  function tab(id) {
    el.root.classList.toggle('tab-code', id === 'code');
    for (const b of el.tabs.children) b.setAttribute('aria-selected', String(b.dataset.tab === id));
    requestAnimationFrame(() => { S.canvas?.setViewport(deviceWidth()); S.code?.ed.layout(); if (id === 'code') markInCode(); });
  }

  // ── "🐞 לא הצלחתי לשנות את זה" (03 §12), ותפריט ההקשר של אלמנט ──
  const curDevice = () => DEVICES.find(d => d.id === S.device) || DEVICES[0];
  function pointing(on) {
    const c = S.canvas; if (!c?.m) return;
    S.pointing = on; el.root.classList.toggle('pointing', on); el.bug.setAttribute('aria-pressed', String(on));
    c.onPoint = on ? n => { pointing(false); reportSheet(n); } : null;
    el.point.hidden = !on;
    if (on) {
      const n = c.selected;
      el.point.replaceChildren(h('span', {}, '🐞 מצביעים על מה שלא הצלחת לשנות — לחיצה על כל דבר בקנבס.'),
        n ? h('button', { type: 'button', class: 'btn sm', 'data-bug-selected': true, onclick: () => { pointing(false); reportSheet(n); } }, 'האלמנט הנבחר: ' + c.label(n)) : null,
        h('button', { type: 'button', class: 'btn sm', onclick: () => pointing(false) }, 'ביטול (Esc)'));
      (el.point.querySelector('button') || el.point).focus();
    }
  }
  function reportSheet(n) {
    const c = S.canvas, R = window.KolReport; if (!c?.m || !n || !S.file) return;
    c.select(n); c.reveal(n);
    const d = R.describe(c, K, n, S.file, { width: deviceWidth(), label: curDevice().label });
    const shotBox = h('div', { class: 'ed-shot' }, h('span', { class: 'hint' }, 'מצלם את הקנבס…')), tried = h('textarea', { rows: 3, 'aria-label': 'מה ניסית לשנות (לא חובה)', placeholder: 'מה ניסית לשנות? (לא חובה)' });
    const msg = h('p', { class: 'hint', role: 'status' }), sendBtn = h('button', { type: 'button', class: 'btn primary', 'data-bug-send': true }, 'פתיחת מטלה'), list = h('div', { class: 'ed-mine' });
    let shot = null;
    const shooting = new Promise(r => setTimeout(r, 60)).then(() => R.shoot(c, n)).then(s => { shot = s; const img = h('img', { alt: 'צילום הקנבס, האלמנט מסומן' }); img.src = URL.createObjectURL(s.blob); shotBox.replaceChildren(img, s.full ? null : h('span', { class: 'hint' }, 'תרשים — הדפדפן אינו מרשה צילום מלא')); }, () => { shotBox.replaceChildren(h('span', { class: 'hint' }, 'הצילום לא הצליח — המטלה תיפתח בלעדיו')); });
    sendBtn.addEventListener('click', async () => {
      sendBtn.disabled = true; msg.textContent = 'שולח…';
      try {
        await shooting;
        const r = await R.send(d, tried.value, shot);
        S.lastReport = r;
        msg.textContent = '✅ נפתחה מטלה ' + r.code + (r.attached ? ', עם הצילום' : ' (בלי צילום)') + '. היא נשארת פתוחה עד שתאשר שזה נפתר.';
        drawMine(list);
      } catch (e) { msg.textContent = '⚠️ ' + e.message; sendBtn.disabled = false; }
    });
    sheet('🐞 לא הצלחתי לשנות את זה', shotBox, h('pre', { class: 'ed-report-info', dir: 'auto' }, R.detail(d, '').split('\n').slice(2).join('\n')), tried, h('div', { class: 'ed-report-go' }, sendBtn), msg, h('h3', { class: 'dt-h' }, 'הדיווחים שלי'), list);
    drawMine(list);
  }
  async function drawMine(list) {
    const R = window.KolReport;
    try {
      const items = (await R.mine()).filter(x => x.area === R.LABEL);
      list.replaceChildren(...(items.length ? items.map(x => h('div', { class: 'ed-mine-row' }, h('code', {}, x.code), h('span', {}, x.title.replace(R.LABEL + ': ', '')), h('b', {}, R.STATUS[x.status] || x.status))) : [h('p', { class: 'hint' }, 'עוד לא דיווחת על דבר.')]));
    } catch (e) { list.replaceChildren(h('p', { class: 'hint' }, e.message)); }
  }
  /** תפריט ההקשר של אלמנט (לחיצה ימנית; לחיצה ארוכה במגע; מקש התפריט / Shift+F10). */
  function ctxMenu(n) {
    const c = S.canvas; if (!c?.m || !n) return;
    c.select(n);
    const dlg = document.getElementById('sheet'), go = fn => () => { dlg.close(); fn(); };
    const item = (label, fn, off) => h('button', { type: 'button', class: 'btn ed-menu-item', disabled: !!off, onclick: go(fn) }, label);
    sheet(c.label(n), h('div', { class: 'ed-menu', role: 'menu' },
      item('↑ בחר הורה', () => { c.select(n.parent); c.reveal(n.parent); }, !n.parent),
      item('</> פתח בקוד', () => openInCode(n)),
      item('🌳 הצג ב-Navigator', () => { panel('nav', true); S.nav.sync(); S.nav.list.focus(); }),
      item('ℹ️ פרטים', () => panel('info', true)),
      item('🗑️ מחיקה', remove, !canWrite()),
      item('🐞 לא הצלחתי לשנות את זה', () => reportSheet(n))));
  }

  // ── 👁 / 🔒 — בעורך בלבד, נשמרות בדפדפן לכל עמוד (לפי מקום האלמנט בעץ) ──
  function saveMarks() {
    const c = S.canvas; if (!c?.m || !S.file) return;
    const paths = set => [...set].filter(n => c.m2d.has(n)).map(n => K.pathOf(n).join('.'));
    const hide = paths(c.hiddenNodes), lock = paths(c.lockedNodes);
    prefs.marks = prefs.marks || {};
    if (hide.length || lock.length) prefs.marks[S.file.path] = { hide, lock }; else delete prefs.marks[S.file.path];
    keep();
  }
  function loadMarks() {
    const c = S.canvas, mk = prefs.marks?.[S.file?.path]; if (!c?.m || !mk) return;
    const nodes = list => (list || []).map(p => K.byPath(c.m, p === '' ? [] : p.split('.').map(Number))).filter(Boolean);
    c.hiddenNodes = new Set(nodes(mk.hide)); c.lockedNodes = new Set(nodes(mk.lock));
    c.recap(); c.draw();
  }
  /** פאנל צד (Navigator / פרטים). במחשב — עמודה קבועה; בטלפון — גיליון מלמטה, אחד בכל רגע. */
  function panel(id, on) {
    const key = id === 'nav' ? 'navOpen' : 'infoOpen';
    const open = on == null ? !el.root.classList.contains(id + '-open') : on;
    if (open && phone()) for (const other of ['nav', 'info']) if (other !== id) el.root.classList.remove(other + '-open');
    el.root.classList.toggle(id + '-open', open);
    if (!phone()) { prefs[key] = open; keep(); }
    for (const b of el.bar.querySelectorAll('[data-panel]')) b.setAttribute('aria-pressed', String(el.root.classList.contains(b.dataset.panel + '-open')));
    if (open && id === 'nav') { S.nav?.refresh(); S.nav?.sync(); }
    if (open && id === 'info') drawInfo();
    S.canvas?.setViewport(deviceWidth());
  }

  // ── פעולות ────────────────────────────────────────────────
  const canWrite = () => S.doc && !S.readOnly && !S.doc.binary;
  function remove() {
    const c = S.canvas, n = c?.selected;
    if (!n) return note('בוחרים אלמנט, ואז מוחקים');
    if (!canWrite()) return note(S.readOnly || 'הקובץ פתוח לקריאה בלבד', true);
    if (c.src !== S.doc.text()) syncCanvas();
    let res; const label = c.label(n), inside = K.all({ root: n }).length - 1, next = n.parent;
    try { res = K.html.remove(c.m, n); }
    catch (e) {
      if (!(e instanceof K.SrcError)) throw e;
      return note(e.message, true, e.code === 'broken' ? { label: '</> פתח בקוד', run: () => openInCode(n) } : null);   // אזור שבור: נדחה
    }
    S.own = res.patches;
    try { S.doc.edit(res.patches, 'מחיקת ' + label); } catch (e) { S.own = null; return note(e.message, true); }
    if (S.own) syncCanvas();
    if (next && c.m2d.has(next)) c.select(next);
    note('נמחק: ' + label + (inside ? ', עם ' + inside + ' אלמנטים בתוכו' : ''), false, { label: 'בטל', run: undo });
  }
  function undo() { if (S.doc?.canUndo()) { S.doc.undo(); clearTimeout(S.timer); syncCanvas(); } else note('אין מה לבטל'); }
  function redo() { if (S.doc?.canRedo()) { S.doc.redo(); clearTimeout(S.timer); syncCanvas(); } else note('אין מה להחזיר'); }
  /** "פתח בקוד": עורך הקוד נפתח על הקובץ, והטווח של האלמנט מסומן. אלמנט שהדפדפן הוסיף — ההורה הכתוב שלו. */
  function openInCode(n) {
    n = n || S.canvas?.selected;
    if (!S.file) return;
    while (n && n.implied) n = n.parent;
    if (!n) return Project.openAt(S.file.path, 1);
    Project.openAt(S.file.path, 0, { from: n.from, to: K.endOf(S.canvas.m, n) });
  }
  function onKey(e) {
    if (!S.host || !S.canvas?.m || e.defaultPrevented || document.querySelector('dialog[open]')) return;   // גיליון פתוח — הקיצורים של העורך אינם פעילים
    const t = e.target, tag = t && t.nodeType === 1 ? t.localName : '';
    if (t && t.ownerDocument === document && (/^(input|textarea|select)$/.test(tag) || t.isContentEditable || t.closest?.('dialog[open], .monaco-editor'))) return;
    const mod = e.ctrlKey || e.metaKey, k = e.key, c = S.canvas;
    const hit = fn => { e.preventDefault(); fn(); };
    if (S.pointing && k === 'Escape') return hit(() => pointing(false));
    if ((k === 'ContextMenu' || (k === 'F10' && e.shiftKey)) && c.selected) return hit(() => ctxMenu(c.selected));
    if (mod && !e.altKey && k.toLowerCase() === 'z') return hit(e.shiftKey ? redo : undo);
    if (mod && !e.altKey && k.toLowerCase() === 'y') return hit(redo);
    if (mod || e.altKey) return;
    if (k === 'n' || k === 'N' || k === 'מ') return hit(() => { panel('nav', true); S.nav.list.focus(); });
    if (k === 's' || k === 'S' || k === 'ד') return hit(() => split());
    if (k === 'b' || k === 'B' || k === 'נ') return hit(() => pointing(!S.pointing));
    if (k === '?' || (k === '/' && e.shiftKey)) return hit(help);
    if (k === 'c' || k === 'C' || k === 'ב') return hit(() => openInCode());
    if (k === '1' || k === '2' || k === '3') return hit(() => setDevice(DEVICES[+k - 1].id));
    if (!c.selected && /^Arrow/.test(k)) return hit(() => { const b = c.m.root && (c.m.root.kids.find(x => x.tag === 'body') || c.m.root); if (b) { c.select(b); c.reveal(b); } });
    if (k === 'i' || k === 'I' || k === 'ן') return hit(() => { panel('info', true); el.infoBody.focus(); });
    if (k === 'Delete' || k === 'Backspace') return hit(remove);
    if (!c.selected) return;
    if (k === 'Escape') return hit(() => c.step('parent'));
    if (k === 'ArrowUp') return hit(() => c.step('parent'));
    if (k === 'ArrowDown') return hit(() => c.step('child'));
    if (k === 'ArrowLeft' || k === 'ArrowRight') { const rtl = c.frame.contentWindow.getComputedStyle(c.doc.documentElement).direction === 'rtl'; return hit(() => c.step((k === 'ArrowLeft') === rtl ? 'next' : 'prev')); }
  }
  document.addEventListener('keydown', onKey);

  const KEYS = [['חיצים ↑ ↓', 'ההורה / הילד הראשון (בלי בחירה — מתחילים מ-body)'], ['חיצים ← →', 'האח הקודם / הבא'], ['Esc', 'ההורה (או ביטול מצב ההצבעה)'],
    ['Delete', 'מחיקת האלמנט הנבחר'], ['Ctrl+Z · Ctrl+Y', 'בטל · החזר'], ['C', '</> פתח בקוד'], ['N', 'Navigator — עץ האלמנטים'], ['I', 'פרטים'], ['S', 'מבט מפוצל — קוד לצד הקנבס'],
    ['B', '🐞 לא הצלחתי לשנות את זה'], ['Shift+F10', 'תפריט ההקשר של האלמנט הנבחר'], ['1 · 2 · 3', 'רוחב הקנבס: מחשב · טאבלט · טלפון'], ['?', 'הרשימה הזו'],
    ['ב-Navigator: ↑ ↓', 'השורה הקודמת / הבאה'], ['ב-Navigator: ← →', 'קיפול / פתיחה, ההורה / הילד'], ['ב-Navigator: H · L', '👁 הסתרה בעורך · 🔒 נעילה'], ['בעורך הקוד: Ctrl+Alt+E', 'בחר בעורך החזותי']];
  function help() { sheet('קיצורי מקלדת', h('table', { class: 'ed-keys' }, h('tbody', {}, KEYS.map(([k, t]) => h('tr', {}, h('th', { scope: 'row', dir: 'ltr' }, h('kbd', {}, k)), h('td', {}, t)))))); }
  function setDevice(id) {
    S.device = id; prefs.device = id; keep();
    for (const b of el.dev.children) b.setAttribute('aria-pressed', String(b.dataset.dev === id));
    S.canvas?.setViewport(deviceWidth()); drawInfo();
  }

  // ── ציור ──────────────────────────────────────────────────
  const deviceWidth = () => (DEVICES.find(d => d.id === S.device) || DEVICES[0]).width;
  function status(text) { if (el.status) el.status.textContent = text; }
  function drawState() {
    const d = S.doc; if (!d) return;
    if (d.errorStatus === 423 && !S.readOnly) { S.readOnly = d.error; note(d.error + ' — כאן אפשר לראות ולבחור, לא לשנות', true); }   // נעילת העריכה הקיימת
    status(S.readOnly ? '🔒 לקריאה בלבד' : d.conflict ? '⚠️ הקובץ השתנה בינתיים' : d.saving ? 'שומר…' : d.dirty ? (d.error ? '⚠️ ' + d.error : 'לא נשמר') : 'נשמר · גרסה ' + d.rev);
    el.undo.disabled = !d.canUndo() || !!S.readOnly; el.redo.disabled = !d.canRedo() || !!S.readOnly;
  }
  function drawCrumbs() {
    const c = S.canvas, n = c?.selected, chain = [];
    for (let x = n; x; x = x.parent) chain.unshift(x);
    el.crumbs.replaceChildren(...(chain.length ? chain.flatMap((x, i) => [i ? h('span', { class: 'ed-sep', 'aria-hidden': 'true' }, '›') : null,
      h('button', { type: 'button', class: 'ed-crumb', 'aria-current': x === n ? 'true' : null, onclick: () => { c.select(x); c.reveal(x); } }, c.label(x))]).filter(Boolean)
      : [h('span', { class: 'hint' }, S.file ? 'לוחצים על אלמנט בעמוד כדי לבחור אותו' : '')]));
    el.crumbs.scrollLeft = el.crumbs.dir === 'ltr' ? el.crumbs.scrollWidth : -el.crumbs.scrollWidth;
    el.del.disabled = !n || !canWrite(); el.code.disabled = !S.file;
    const lines = n && !n.implied ? K.lineOf(c.m.src, n.from) : 0;
    const vis = n ? c.visual(n) : '';
    el.where.textContent = n ? (n.implied ? 'לא במקור — הדפדפן הוסיף' : 'שורה ' + lines) + (vis === 'shown' ? ' · ' + c.shownNote : vis === 'nonvisual' ? ' · אינו נראה בעמוד' : vis === 'zero' ? ' · בגודל אפס' : vis === 'none' ? ' · אינו מוצג' : '') : '';
  }
  const infoOpen = () => !!el.root?.classList.contains('info-open');
  function drawInfo() { if (S.info && infoOpen()) { try { S.info.draw(); } catch (e) { console.error(e); } } }
  function drawAll() {
    if (!S.host) return;
    drawState(); drawCrumbs(); S.nav?.refresh(); drawInfo(); saveMarks();
    const cap = S.canvas?.capInfo;
    el.cap.hidden = !cap;
    if (cap) el.cap.textContent = cap.shown ? 'מוצגים כל האלמנטים · הצג 60' : 'מוצגים 60 מכל רשימה ארוכה · הצג הכול (' + cap.hidden + ' מוסתרים)';
    const broken = S.canvas?.m?.marks || 0;
    el.broken.hidden = !broken;
    if (broken) el.broken.textContent = '⚠️ בקובץ יש קוד שבור (' + broken + ') — האזור מסומן, ואי אפשר לשנות אותו מכאן';
  }
  function note(text, err, action) {
    if (!el.note) return;
    clearTimeout(note.t);
    el.note.className = 'ed-note' + (err ? ' err' : '');
    el.note.replaceChildren(h('span', {}, text), action ? h('button', { type: 'button', class: 'btn sm', onclick: () => { el.note.hidden = true; action.run(); } }, action.label) : null,
      h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => { el.note.hidden = true; } }, '✕'));
    el.note.hidden = false;
    note.t = setTimeout(() => { el.note.hidden = true; }, err || action ? 12000 : 5000);
  }

  async function view(host, wantPath) {
    S.host = host;
    el.page = h('select', { class: 'ed-page', 'aria-label': 'עמוד', onchange: () => open(+el.page.value) });
    el.status = h('span', { class: 'ed-status', role: 'status' });
    el.undo = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'בטל (Ctrl+Z)', title: 'בטל (Ctrl+Z)', disabled: true, onclick: undo }, '↶');
    el.redo = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'החזר (Ctrl+Y)', title: 'החזר (Ctrl+Y)', disabled: true, onclick: redo }, '↷');
    el.del = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'מחיקת האלמנט הנבחר (Delete)', title: 'מחיקה (Delete)', disabled: true, onclick: remove }, '🗑️');
    el.code = h('button', { type: 'button', class: 'btn sm', title: 'פתח בקוד — הקובץ, על האלמנט הנבחר', disabled: true, onclick: () => openInCode() }, '</>', h('span', { class: 'ed-word' }, ' פתח בקוד'));
    el.dev = h('div', { class: 'ed-dev', role: 'group', 'aria-label': 'רוחב הקנבס' }, DEVICES.map(d => h('button', { type: 'button', 'data-dev': d.id, 'aria-pressed': String(d.id === S.device), 'aria-label': d.label + (d.width ? ' — ' + d.width + 'px' : ''), title: d.label + (d.width ? ' · ' + d.width + 'px' : ''),
      class: d.wide ? 'wide' : '', onclick: () => setDevice(d.id) }, d.icon)));
    el.crumbs = h('nav', { class: 'ed-crumbs', dir: 'ltr', 'aria-label': 'שביל ניווט' });
    el.where = h('span', { class: 'ed-where' });
    el.cap = h('button', { type: 'button', class: 'ed-strip', hidden: true, onclick: () => { S.canvas.showAll(!S.canvas.capOff); drawAll(); } });
    el.broken = h('p', { class: 'ed-strip warn', role: 'alert', hidden: true });
    el.note = h('div', { class: 'ed-note', role: 'status', hidden: true });
    el.canvas = h('div', { class: 'ed-canvas' });
    const toggle = (id, icon, label) => h('button', { type: 'button', class: 'icon-btn', 'data-panel': id, 'aria-pressed': 'false', 'aria-label': label, title: label, onclick: () => panel(id) }, icon);
    const side = (id, title, body) => h('aside', { class: 'ed-side ed-' + id, 'aria-label': title },
      h('div', { class: 'ed-side-head' }, h('strong', {}, title), h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירת ' + title, onclick: () => panel(id, false) }, '✕')), body);
    el.splitBtn = h('button', { type: 'button', class: 'icon-btn', 'data-split': true, 'aria-pressed': 'false', 'aria-label': 'מבט מפוצל — קוד לצד הקנבס (S)', title: 'מבט מפוצל — קוד לצד הקנבס (S)', onclick: () => split() }, '◫');
    el.pane = h('div', { class: 'ed-code', 'aria-label': 'הקוד של העמוד' });
    el.tabs = h('div', { class: 'ed-tabs', role: 'tablist', 'aria-label': 'קנבס או קוד' }, [['canvas', '🖼️ קנבס'], ['code', '</> קוד']].map(([id, label]) => h('button', { type: 'button', role: 'tab', 'data-tab': id, 'aria-selected': String(id === 'canvas'), onclick: () => tab(id) }, label)));
    el.bug = h('button', { type: 'button', class: 'icon-btn', 'data-bug': true, 'aria-pressed': 'false', 'aria-label': 'לא הצלחתי לשנות את זה — דיווח (B)', title: '🐞 לא הצלחתי לשנות את זה — לוחצים, ומצביעים על מה שלא הצלחת לשנות (B)', onclick: () => pointing(!S.pointing) }, '🐞');
    el.point = h('div', { class: 'ed-strip ed-point', role: 'status', hidden: true });
    el.navBody = h('div', { class: 'ed-side-body nv' });
    el.infoBody = h('div', { class: 'ed-side-body dt', tabindex: '-1' });
    el.bar = h('div', { class: 'ed-bar' }, el.page, el.dev, el.undo, el.redo, el.del, el.code, toggle('nav', '🌳', 'Navigator — עץ האלמנטים (N)'), toggle('info', 'ℹ️', 'פרטים — מה האלמנט, איפה הוא בקוד, ואיזה עיצוב חל עליו (I)'), el.splitBtn, el.bug, h('button', { type: 'button', class: 'icon-btn', 'data-help': true, 'aria-label': 'קיצורי מקלדת (?)', title: 'קיצורי מקלדת (?)', onclick: help }, '?'), el.status);
    el.mid = h('div', { class: 'ed-mid' }, el.tabs, h('div', { class: 'ed-panes' }, el.canvas, el.pane));
    el.root = h('section', { class: 'ed', 'aria-label': 'העורך החזותי' },
      el.bar, h('div', { class: 'ed-path' }, el.crumbs, el.where), el.cap, el.broken, el.point,
      h('div', { class: 'ed-body' }, side('nav', 'Navigator', el.navBody), el.mid, side('info', 'פרטים', el.infoBody)), el.note);
    host.replaceChildren(el.root);
    status('טוען…');
    try {
      await loadJs(); K = window.KolSrc;
      if (S.host !== host) return;
      const c = S.canvas = new window.KolCanvas.Canvas(el.canvas);
      c.drag = false; c.repeatUp = true; c.marksOn = true;                  // V1a: בלי גרירה (V4); לחיצה חוזרת — ההורה
      S.nav = new window.KolNavigator.Navigator(el.navBody, { canvas: c, K, onPick: n => { c.select(n); c.reveal(n); }, onToggle: saveMarks });
      S.info = new window.KolDetails.Details(el.infoBody, { canvas: c, K, sheets: () => S.sheets, file: () => S.file, openAt: (p, line) => Project.openAt(p, line), openEl: n => openInCode(n) });
      c.onSelect = () => { drawCrumbs(); S.nav.sync(); drawInfo(); markInCode(); }; c.onKey = onKey; c.onContext = n => ctxMenu(n);
      S.split = false; if (prefs.split) split(true);
      if (!phone() && prefs.infoOpen !== false) panel('info', true);
      if (!phone() && prefs.navOpen !== false) panel('nav', true);
      c.onInequiv = (diff, patches) => {                                    // נפילה בטוחה כבר נעשתה בקנבס; כאן — הרישום ביומן המפתח
        const rec = { type: 'editor-inequivalent', level: 'error', message: 'המודל ההדרגתי אינו שקול לניתוח מלא: ' + diff, file: S.file?.path, patches: JSON.stringify(patches).slice(0, 500) };
        console.error('[editor]', rec); if (typeof window.KolLog === 'function') window.KolLog(rec);
      };
      new ResizeObserver(() => { if (S.canvas === c && c.m) c.setViewport(deviceWidth()); }).observe(el.canvas);
      const files = (await Project.docs.files()).filter(f => f.kind === 'file' && /\.html?$/i.test(f.path)).sort((a, b) => a.path.localeCompare(b.path));
      if (S.host !== host) return;
      if (!files.length) { status(''); el.canvas.replaceChildren(h('p', { class: 'empty' }, 'אין בפרויקט קובץ HTML. יוצרים אחד ב-📁 פרויקט.')); return; }
      el.page.replaceChildren(...files.map(f => h('option', { value: f.id }, f.path)));
      const first = files.find(f => f.path === wantPath) || files.find(f => f.path === prefs.page) || files.find(f => f.path === 'index.html') || files[0];
      el.page.value = first.id;
      await open(first.id);
    } catch (e) { status(''); el.canvas.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }
  function unmount() { if (!S.host) return; S.pointing = false; S.codeOff?.dispose(); S.codeOff = null; S.code = null; close(); S.host = null; S.canvas = null; S.nav = null; S.info = null; }
  window.addEventListener('hashchange', () => { if (S.host && location.hash.split('/')[1] !== 'editor') unmount(); });

  return { view, unmount, selectAt, _ctx: ctxMenu, _state: S, _undo: undo, _redo: redo, _remove: remove, _sync: syncCanvas, _marks: saveMarks };
})();
