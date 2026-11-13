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
  const JS = ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/canvas.js', 'assets/js/editor/text.js', 'assets/js/editor/navigator.js', 'assets/js/editor/details.js', 'assets/js/editor/content.js', 'assets/js/editor/report.js', 'assets/js/editor/background.js', 'assets/js/editor/style.js', 'assets/js/editor/globals.js', 'assets/js/editor/structure.js', 'assets/js/editor/drag.js', 'assets/js/editor/build.js', 'assets/js/editor/design.js', 'assets/js/editor/identity.js', 'assets/js/editor/states.js', 'assets/js/editor/extra.js', 'assets/js/editor/position.js', 'assets/js/editor/layout.js', 'assets/js/editor/devices.js', 'assets/js/editor/spacing.js', 'assets/js/editor/effects.js', 'assets/js/editor/motion.js', 'assets/js/editor/bind.js', 'assets/js/editor/listview.js', 'assets/js/editor/data.js', 'assets/js/editor/component-state.js', 'assets/js/editor/components.js', 'assets/js/editor/history.js', 'assets/js/editor/stylecopy.js', 'assets/js/editor/contextmenu.js', 'assets/js/editor/palette.js', 'assets/js/editor/templates.js', /* V15 */ 'assets/js/editor/head.js', 'assets/js/editor/pages.js', /* V14 */ 'assets/js/editor/touch.js'];
  const LS = 'kol-editor';
  /** V7: המכשירים = "מחשב" (הבסיס, בלי @media) ונקודות השבירה שב-kol.json (02 §5) — מהרחב לצר. */
  const DESKTOP = { id: 'desktop', icon: '🖥️', label: 'מחשב', width: 1280, max: null };
  const DEFAULT_BP = [{ id: 'tablet', label: 'טאבלט', max: 1024, preview: 820 }, { id: 'mobile', label: 'טלפון', max: 767, preview: 390 }];
  let DEVICES = null;
  /** breakpoints מ-kol.json ← המכשירים. בלי breakpoints (או קובץ שאינו נקרא) — ברירת המחדל של 02 §5. */
  function devicesFrom(kol) {
    const bp = Array.isArray(kol?.config?.breakpoints) ? kol.config.breakpoints : DEFAULT_BP, out = [DESKTOP];
    for (const b of bp.slice(0, 8)) {
      const max = Math.round(+b?.max || 0), w = Math.round(+b?.preview || max);
      if (!b || typeof b.id !== 'string' || !b.id || max < 200 || max > 4000 || w < 240 || w > 4000 || out.some(x => x.id === b.id || x.max === max)) continue;
      out.push({ id: b.id, icon: max > 900 ? '💻' : '📱', label: typeof b.label === 'string' && b.label ? b.label.slice(0, 30) : b.id, width: w, max, wide: max > 900 });
    }
    return out.sort((a, b) => (b.max ?? 1e9) - (a.max ?? 1e9));
  }
  DEVICES = devicesFrom(null);
  const SYNC_MS = 120;                                                     // הקלדה בקוד ← קנבס: הרבה מתחת ל-300ms
  const S = { tx: [], rtx: [], pv: new Map(), kol: null, host: null, canvas: null, doc: null, off: null, file: null, sheets: new Map(), sheetOff: [], readOnly: '', device: matchMedia('(max-width: 999px)').matches ? 'mobile' : 'desktop', timer: 0, own: null, token: null, busy: false, nav: null, info: null, split: false, code: null, codeOff: null, mute: false, fromCode: false, curT: 0 };
  let pending = null; const PICK = 'kol-editor-pick';
  const phone = () => matchMedia('(max-width: 999px)').matches;
  const el = {};
  let loading = null, K = null;
  let prefs = {}; try { prefs = JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch { prefs = {}; }
  const uid = () => String(state.user?.id ?? state.user?.sub ?? state.user?.email ?? '');   // V15: העמוד הפתוח — לכל משתמש
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
      S.tx = []; S.rtx = []; S.pv.clear();
      S.file = f; S.doc = doc; S.readOnly = ''; prefs.page = f.path; /* V15: העמוד הפתוח — לכל משתמש */ { const u = uid(); if (u) { const m = prefs.pageBy && typeof prefs.pageBy === 'object' ? prefs.pageBy : {}; delete m[u]; m[u] = f.path; for (const k of Object.keys(m).slice(0, Math.max(0, Object.keys(m).length - 20))) delete m[k]; prefs.pageBy = m; } } keep(); S.pages?.drawBtn(); S.blocked = new Map(); drawBlocked();
      try {                                                                 // הנעילה ורוחבי המכשיר — כבר בפתיחה, לא בכתיבה הראשונה
        const info = await Project.docs.info();
        if (info.lock?.by) S.readOnly = 'העמוד בעריכה אצל ' + info.lock.by + ' — כאן אפשר לראות ולבחור, לא לשנות';
        Project.seed.draw(el.seed, () => { const host = S.host; unmount(); view(host); });   // שלד הזריעה: "העמוד של היום עוד לא יובא · 🔄 ייבוא עכשיו"
        S.kol = info.kol?.config || null; DEVICES = devicesFrom(info.kol); S.freeW = 0; { const mine = prefs.devByPage && prefs.devByPage[f.path]; if (mine && DEVICES.some(d => d.id === mine)) S.device = mine; } if (!DEVICES.some(d => d.id === S.device)) S.device = DEVICES[0].id;   // V7: המכשיר — לכל עמוד, בדפדפן
        drawDevices();
      } catch { /* בלי רשת — ברירות המחדל; הנעילה תתגלה בשמירה */ }
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
      // V7: kol.json — המכשירים מתעדכנים כשהקובץ משתנה (נקודות שבירה, ביטול שלהן, או עריכה בקוד)
      { const kf = Project.docs.byPath('kol.json'), kd = kf && await Project.docs.open(kf.id).catch(() => null); if (kd && !kd.binary) S.sheetOff.push(kd.on(kind => { if (kind === 'text') kolChanged(kd.text()); })); }
      S.js = [];                                                            // V4: קובצי ה-JS של הפרויקט — לאזהרה "ייתכן ש-js/… נשען על…" (קריאה בלבד)
      for (const jf of files.filter(x => x.kind === 'file' && /\.m?js$/i.test(x.path)).slice(0, 20)) { try { const jd = await Project.docs.open(jf.id); if (jd && !jd.binary) S.js.push({ path: jf.path, doc: jd }); } catch { /* לא נפתח — בלי אזהרה ממנו */ } }
      // V12: template.html של רכיב — נפתח בקנבס עם style.css שלו (לתבנית אין <head> ואין <link>)
      { const cm = /^components\/([^/]+)\/template\.html$/.exec(f.path), cf = cm && Project.docs.byPath('components/' + cm[1] + '/style.css');
        S.canvas.extraSheets = [];
        if (cf) { const cd = await Project.docs.open(cf.id).catch(() => null); if (cd && !cd.binary) { const href = 'style.css'; S.sheets.set(href, { path: cf.path, doc: cd }); S.canvas.extraSheets = [href]; S.sheetOff.push(cd.on(kind => { if (kind === 'text' && S.canvas) { S.canvas.setSheet(href, cssForCanvas(cf.path, cd.text())); drawInfo(); } })); } } }
      const c = S.canvas;
      c.baseHref = S.token ? pvUrl(dirOf(f.path)) : '';
      c.verify = !!state.user?.developer || prefs.verify === true;         // מצב פיתוח: שקילות אחרי כל פעולה
      c.capOff = false;
      await c.load(src, href => { const s = S.sheets.get(href); return s ? cssForCanvas(s.path, s.doc.text()) : null; });
      c.setViewport(deviceWidth());
      loadMarks();
      if (!doc.binary) S.off = doc.on(kind => (kind === 'text' ? onDocText() : drawState()));
      if (S.hist) { S.hist.untrackAll(); if (!doc.binary) S.hist.track(doc); for (const x of S.sheets.values()) S.hist.track(x.doc); S.hist.draw(); }   // V13: היסטוריה אחת לעמוד ולקובצי ה-CSS שלו
      if (S.readOnly) note(S.readOnly);
      mountCode();
      S.comp?.opened(f);                                                    // V12: תבנית של רכיב ← "עורך את הרכיב"
      drawAll();
      if (!pending) { try { const p = JSON.parse(sessionStorage.getItem(PICK) || 'null'); sessionStorage.removeItem(PICK); if (p && Date.now() - p.at < 30000) pending = p; } catch { /* אין אחסון */ } }
      if (pending && pending.path === f.path) applyAt(pending.off);
      pending = null;
    } catch (e) { status(''); note(e.message, true); }
    finally { S.busy = false; }
  }
  function close() {
    S.text?.end(); clearTimeout(S.timer); S.off?.(); S.off = null; S.sheetOff.forEach(f => f()); S.sheetOff = [];
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
    if (!/\/editor\.php$/.test(location.pathname)) {                        // מעורך הקוד שבמסמך הרגיל: העורך החזותי הוא מסמך אחר (editor.php) — שומרים, וזוכרים מה לבחור
      const f = Project.docs.byPath(path);
      if (f) await Project._save(f.id);
      try { sessionStorage.setItem(PICK, JSON.stringify({ path, off, at: Date.now() })); } catch { /* אין אחסון — העורך ייפתח בלי בחירה */ }
    }
    if (S.host) { const f = Project.docs.byPath(path); if (f) { el.page.value = f.id; await open(f.id); } return; }
    location.hash = '#/editor/' + encodeURIComponent(path);
  }
  function mountCode() {
    S.codeOff?.dispose(); S.codeOff = null; S.code = null;
    if (!S.split || !el.pane) return;
    const sp = S.doc && !S.doc.binary && S.file ? Project.splitEditor(el.pane, S.file.id) : null;
    if (!sp) { el.pane.replaceChildren(h('p', { class: 'hint' }, S.doc?.binary ? 'הקובץ אינו UTF-8 — אין לו עורך קוד.' : 'עורך הקוד לא נטען.')); return; }
    S.code = sp; sp.ed.updateOptions({ readOnly: !!S.readOnly });
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
  /** 📏: ידיות המרווח על הקנבס, גם כשהפאנל סגור או על לשונית אחרת. */
  function spaceMode(on) {
    S.spaceOn = on == null ? !S.spaceOn : !!on; el.spaceBtn?.setAttribute('aria-pressed', String(S.spaceOn));
    if (!S.spaceOn && el.gapbar) el.gapbar.hidden = true;
    S.canvas?.draw();
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
  /** V7: רוחב חופשי ← המכשיר שהרוחב שייך אליו (הצר ביותר שה-max שלו מכסה את הרוחב; אחרת — מחשב). */
  const belongs = w => DEVICES.filter(d => d.max && w <= d.max).sort((a, b) => a.max - b.max)[0] || DEVICES[0];
  /** המכשיר שהכתיבה הולכת אליו: null — מחשב; אחרת { id, label, max }. */
  const writeDevice = () => { const d = curDevice(); return d.max ? { id: d.id, label: d.label, max: d.max } : null; };
  /** שם העסקה עם המכשיר: "גודל · כותרת · טלפון · 48 ← 32". */
  const devLabel = (label, d) => { if (!d) return label; const p = String(label).split(' · '); if (p.includes(d.label)) return label; p.splice(Math.min(2, p.length), 0, d.label); return p.join(' · '); };
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
        await drawMine(list);                                               // קודם הרשימה, ואז ההודעה: מי שרואה "נפתחה מטלה" רואה אותה כבר ב"הדיווחים שלי"
        msg.textContent = '✅ נפתחה מטלה ' + r.code + (r.attached ? ', עם הצילום' : ' (בלי צילום)') + '. היא נשארת פתוחה עד שתאשר שזה נפתר.';
      } catch (e) { msg.textContent = '⚠️ ' + e.message; sendBtn.disabled = false; }
    });
    sheet('🐞 לא הצלחתי לשנות את זה', shotBox, h('pre', { class: 'ed-report-info', dir: 'auto' }, R.detail(d, '').split('\n').slice(2).join('\n')), tried, h('div', { class: 'ed-report-go' }, sendBtn), msg, h('h3', { class: 'dt-h' }, 'הדיווחים שלי'), list);
    drawMine(list);
  }
  async function drawMine(list) {
    const R = window.KolReport, turn = list._turn = (list._turn || 0) + 1;   // בקשה ישנה שחוזרת מאוחר אינה דורסת רשימה חדשה ממנה
    try {
      const items = (await R.mine()).filter(x => x.area === R.LABEL);
      if (list._turn !== turn) return;
      list.replaceChildren(...(items.length ? items.map(x => h('div', { class: 'ed-mine-row' }, h('code', {}, x.code), h('span', {}, x.title.replace(R.LABEL + ': ', '')), h('b', {}, R.STATUS[x.status] || x.status))) : [h('p', { class: 'hint' }, 'עוד לא דיווחת על דבר.')]));
    } catch (e) { if (list._turn === turn) list.replaceChildren(h('p', { class: 'hint' }, e.message)); }
  }
  /** תפריט ההקשר של אלמנט (לחיצה ימנית; לחיצה ארוכה במגע; מקש התפריט / Shift+F10). V13: התפריט הצף (contextmenu.js). */
  function ctxMenu(n, x, y, from) {
    const c = S.canvas; if (!c?.m || !n) return;
    if (S.menu) { if (x == null && c.elOf(n)) { const r = c.rectOf(n); if (r) { const f = c.layer.getBoundingClientRect(); x = f.left + r.left + 12; y = f.top + r.top + 12; } } return S.menu.open(n, x, y, from); }
    c.select(n);
    const dlg = document.getElementById('sheet'), go = fn => () => { dlg.close(); fn(); };
    const item = (label, fn, off) => h('button', { type: 'button', class: 'btn ed-menu-item', disabled: !!off, onclick: go(fn) }, label);
    sheet(c.label(n), h('div', { class: 'ed-menu', role: 'menu' },
      item('↑ בחר הורה', () => { c.select(n.parent); c.reveal(n.parent); }, !n.parent),
      item('</> פתח בקוד', () => openInCode(n)),
      item('🌳 הצג ב-Navigator', () => { panel('nav', true); S.nav.sync(); S.nav.list.focus(); }),
      item('✏️ ערוך טקסט', () => S.text.begin(n), !canWrite()),
      item('✏️ תוכן — תמונה, קישור, מדיה', () => { panel('info', true); setTab('content'); }),
      item('ℹ️ פרטים', () => { panel('info', true); setTab('info'); }),
      item('🕘 גרסאות של הקובץ', () => Project.versions(S.file)),
      item('⋯ מבנה — שכפול, עטיפה, פירוק, העברה, העתקה', () => S.build?.menu(n), false),
      item('↕ העבר אל…', () => S.build?.moveSheet(n), !canWrite() || /^(html|head|body)$/.test(n.tag)),
      /^(html|head|body)$/.test(n.tag) ? item('⚙️ הגדרות העמוד — כותרת, שיתוף, סמל, קוד', () => S.head?.show()) : null,   // V15
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
  /** כותב תיקונים לקובץ כעסקה אחת עם שם; הקנבס מתעדכן מיד. false — הכתיבה נדחתה (וההודעה כבר הוצגה). */
  function commit(patches, label) {
    if (!patches.length) return true;
    if (!canWrite()) { note(S.readOnly || 'הקובץ פתוח לקריאה בלבד', true); return false; }
    S.own = patches;
    try { S.doc.edit(patches, label); } catch (e) { S.own = null; note(e.message, true); return false; }
    if (S.own) syncCanvas();
    pushTx([S.doc], label);
    return true;
  }
  /** V4: עסקה אחת בכמה קבצים — ops: [{ html: true | doc, patches }], לפי הסדר. */
  function multi(label, ops) {
    if (!canWrite()) { note(S.readOnly || 'הקובץ פתוח לקריאה בלבד', true); return false; }
    if (S.canvas.src !== S.doc.text()) { clearTimeout(S.timer); syncCanvas(); }
    const docs = [];
    try {
      for (const o of ops) {
        if (!o.patches.length) continue;
        const d = o.html ? S.doc : o.doc;
        if (d === S.doc) { S.own = o.patches; d.edit(o.patches, label); if (S.own) syncCanvas(); } else d.edit(o.patches, label);
        docs.push(d);
      }
    } catch (e) { if (docs.length) pushTx(docs, label); note(e.message, true); return false; }
    if (docs.length) pushTx(docs, label);
    drawAll();
    return true;
  }
  /** V4: מקשר קובץ CSS של הפרויקט לעמוד (<link> בסוף ה-<head>) — המסמך נפתח קודם, כדי שהקנבס יציג אותו מיד. */
  async function linkSheet(path) {
    const cf = Project.docs.byPath(path); if (!cf) { note('הקובץ ' + path + ' אינו בפרויקט', true); return false; }
    const cd = await Project.docs.open(cf.id); if (!cd || cd.binary) { note('הקובץ ' + path + ' לא נפתח', true); return false; }
    const href = relRef(path), head = S.canvas.m.root && S.canvas.m.root.kids.find(k => k.tag === 'head' && !k.implied);
    if (!head) { note('לעמוד אין <head> כתוב — מקשרים בעורך הקוד', true); return false; }
    if (!S.sheets.has(href)) { S.sheets.set(href, { path, doc: cd }); S.sheetOff.push(cd.on(kind => { if (kind === 'text' && S.canvas) { S.canvas.setSheet(href, cssForCanvas(path, cd.text())); drawInfo(); } })); S.hist?.track(cd); }
    return act('קישור ' + path + ' לעמוד', m => K.html.insert(m, K.byPath(m, K.pathOf(head)), null, '<link rel="stylesheet" href="' + window.KolText.escAttr(href) + '">'), head);   // <link> שייך ל-<head> (KolStruct.why אינו מכניס לשם תוכן נראה)
  }
  /** הקובץ לכלל חדש: styles.page שב-kol.json, אם העמוד מקשר אליו; אחרת קובץ ה-CSS האחרון שהעמוד מקשר. */
  /** V8: קובץ ה-CSS של ברירות המחדל לתגיות (styles.base), אם העמוד מקשר אליו. */
  function basePath() { const want = S.kol?.styles?.base; return [...S.sheets.values()].some(x => x.path === want) ? want : null; }
  function pagePath() { const want = S.kol?.styles?.page, paths = [...S.sheets.values()].map(x => x.path); return paths.includes(want) ? want : paths[paths.length - 1] || null; }
  // ── עסקאות על כמה קבצים (V3): ביטול / החזרה משותפים לכל הקבצים שהעסקה נגעה בהם ──
  /** רושם עסקה: הקבצים שנערכו (מסמך שנערך כמה פעמים — כמה פעמים), והטקסט של כל אחד אחריה. */
  function pushTx(docs, label) {
    if (S.hist?.merge(docs, label)) return;                                // V13: אותו ערך ברצף (גרירת מחוון, חיצים) — שורה אחת
    const after = new Map(); for (const d of docs) after.set(d, d.text());
    const t = { docs, label, after }; S.hist?.pushed(t);
    S.tx.push(t); S.rtx = []; if (S.tx.length > 500) S.tx.shift();      // V13: עד 500 צעדים
    S.hist?.draw();
  }
  const txLive = (t, key) => t && t.docs.every(d => d.text() === t[key].get(d));
  function undoTx() {
    const t = S.tx[S.tx.length - 1];
    if (!txLive(t, 'after')) { S.tx = []; S.rtx = []; return false; }        // מאז נערך משהו מחוץ לעורך החזותי — כל קובץ לפי ההיסטוריה שלו
    S.tx.pop(); const before = new Map();
    const run = () => {
      if (t.code) { for (const d of t.docs) { let i = 0; while (d.text() !== t.before.get(d) && d.canUndo() && i++ < 5000) d.undo(); } }   // V13: הקלדה בעורך הקוד — עד הטקסט שלפניה
      else for (const d of t.docs.slice().reverse()) if (d.canUndo()) d.undo();
    };
    if (S.hist) S.hist.quiet(run); else run();
    if (t.fx) for (const f of t.fx.slice().reverse()) f.undo();             // V9: קבצים שנוצרו בשרת עם העסקה (גופן) — לסל
    for (const d of t.docs) before.set(d, d.text());
    t.before = before; S.rtx.push(t); S.hist?.draw(); return true;
  }
  function redoTx() {
    const t = S.rtx[S.rtx.length - 1];
    if (!txLive(t, 'before')) { S.rtx = []; return false; }
    S.rtx.pop();
    const run = () => {
      if (t.code) { for (const d of t.docs) { let i = 0; while (d.text() !== t.after.get(d) && d.canRedo() && i++ < 5000) d.redo(); } }
      else for (const d of t.docs) if (d.canRedo()) d.redo();
    };
    if (S.hist) S.hist.quiet(run); else run();
    if (t.fx) for (const f of t.fx) f.redo();
    for (const d of t.docs) t.after.set(d, d.text());
    S.tx.push(t); S.hist?.draw(); return true;
  }
  /** כתיבת עיצוב: per — Map<אלמנט, [[מאפיין, ערך | null, יעד], …]>. כל אלמנט וכל יעד מתוכננים מחדש על הטקסט העדכני
   *  (אחרי מה שכבר נכתב באותה עסקה), וכל הקבצים נרשמים כעסקה אחת. מחזיר את הקבצים שנכתבו, או null. */
  function writeStyle(per, label, opt = {}) {
    unpreview();
    if (!canWrite()) { note(S.readOnly || 'הקובץ פתוח לקריאה בלבד', true); return null; }
    const c = S.canvas, St = S.styler, docs = [], notes = [], devs = new Set();
    if (c.src !== S.doc.text()) { clearTimeout(S.timer); syncCanvas(); }
    const paths = [...per.keys()].map(n => K.pathOf(n));
    // V7: שם העסקה כולל את המכשיר (כשכל הכתיבה היא למכשיר אחד)
    for (const sets of per.values()) for (const [, , t] of sets) { const tw = St.dev(t); if (tw && tw.dev) devs.add(tw.dev.label); }
    { const sts = new Set(); for (const sets of per.values()) for (const [, , t] of sets) if (t && t.state) sts.add(t.state.pc); if (sts.size === 1) label += ' · מצב ' + [...sts][0]; }   // V8
    if (devs.size === 1) { const one = [...devs][0]; label = devLabel(label, { label: one }); } else if (!devs.size && writeDevice()) label = devLabel(label, writeDevice());   // גם כשהכתיבה הולכת ל-@media אחר — המכשיר שבו נעשתה
    try {
      [...per.values()].forEach((sets, i) => {
        const n = K.byPath(c.m, paths[i]); if (!n) return;
        const groups = new Map();
        for (const [p, v, t] of sets) { const tw = St.dev(t); if (tw.dev) devs.add(tw.dev.label); const key = t.kind + '|' + t.sel + '|' + t.path + '|' + (t.media || '') + '|' + (t.name || '') + '|' + (tw.dev ? tw.dev.id : '') + '|' + (t.state ? t.state.sel : ''); if (!groups.has(key)) groups.set(key, { t, sets: [] }); groups.get(key).sets.push([p, v]); }
        for (const { t, sets: list } of groups.values()) {
          const cur = K.byPath(c.m, paths[i]), tt = St.dev(resolveTarget(cur, t));
          const ops = St.plan(cur, tt, list, opt);
          if (ops.notes) notes.push(...ops.notes);
          for (const o of ops) {
            if (!o.patches.length) continue;
            if (o.doc === S.doc) { S.own = o.patches; S.doc.edit(o.patches, label); if (S.own) syncCanvas(); }
            else o.doc.edit(o.patches, label);
            docs.push(o.doc);
          }
        }
      });
    } catch (e) {
      if (docs.length) pushTx(docs, label);
      if (!(e instanceof K.SrcError)) { console.error(e); note(e.message, true); return null; }
      note(e.message, true); return null;
    }
    if (!docs.length) { drawAll(); return []; }
    pushTx(docs, label);
    drawAll();
    if (notes.length) note('האיפוס מחק את כל ההצהרה ' + notes.join(', ') + ' (הערך היה חלק מקיצור)', false, { label: 'בטל', run: undo });
    return docs;
  }
  /** V5: כתיבה שתוכננה מחוץ ל-Styler (הסרת תמונה, החלפת url(), מחיקת כלל, HTML + CSS) — עסקה אחת.
   *  list — תיקונים {doc, patches} או פונקציות שמחזירות אותם, כל אחת מתוכננת על הטקסט שאחרי הקודמות. */
  function writeOps(list, label) {
    unpreview();
    if (!canWrite()) { note(S.readOnly || 'הקובץ פתוח לקריאה בלבד', true); return null; }
    if (S.canvas.src !== S.doc.text()) { clearTimeout(S.timer); syncCanvas(); }
    const docs = [];
    try {
      for (const p of list) {
        for (const o of (typeof p === 'function' ? p() : [p]) || []) {
          if (!o || !o.patches.length) continue;
          if (o.doc === S.doc) { S.own = o.patches; S.doc.edit(o.patches, label); if (S.own) syncCanvas(); }
          else o.doc.edit(o.patches, label);
          docs.push(o.doc);
        }
      }
    } catch (e) {
      if (docs.length) pushTx(docs, label);
      if (!(e instanceof K.SrcError)) console.error(e);
      if (e.code !== 'noop') note(e.message, true);
      drawAll(); return null;
    }
    if (!docs.length) { drawAll(); return []; }
    pushTx(docs, label); drawAll();
    return docs;
  }
  /** V6: מאחד את k העסקאות האחרונות לאחת (HTML שהוזז + עיצוב שנכתב — ביטול אחד). */
  function joinTx(k, label) {
    if (k < 2 || S.tx.length < k) return;
    const parts = S.tx.splice(S.tx.length - k, k), docs = parts.flatMap(t => t.docs), after = new Map(), fx = parts.flatMap(t => t.fx || []), before = new Map();
    for (const d of docs) after.set(d, d.text());
    for (const t of parts.slice().reverse()) if (t.before) for (const [d, x] of t.before) before.set(d, x);   // V13: הטקסט שלפני הראשונה
    const t = fx.length ? { docs, label, after, fx } : { docs, label, after }; t.before = before; t.at = Date.now();
    S.tx.push(t); S.rtx = []; S.hist?.draw();
  }
  /** V9: פעולה בשרת שנעשתה יחד עם העסקה האחרונה (העלאת גופן) — הביטול / ההחזרה שלה. */
  function attachFx(fx) { const t = S.tx[S.tx.length - 1]; if (t) (t.fx = t.fx || []).push(fx); }
  /** V9: כל עמודי הפרויקט (קובצי .html שאינם רכיבים ואינם תבנית ישנה) — מסמכים פתוחים, לכתיבת <link> בכולם. */
  async function pagesDocs() {
    const out = [];
    for (const f of (await Project.docs.files()).filter(x => x.kind === 'file' && /\.html?$/i.test(x.path) && !/^components\//.test(x.path) && !Project.oldTemplate(x.path))) {
      const d = f.path === S.file?.path ? S.doc : await Project.docs.open(f.id).catch(() => null);
      if (d && !d.binary) out.push({ path: f.path, doc: d });
    }
    return out;
  }
  /** היעד, על המפל העדכני: אותו סוג, בורר, קובץ ו-@media (השורה עשויה לזוז אחרי כתיבה קודמת באותה עסקה). */
  function resolveTarget(n, t) {
    if (t.kind !== 'rule') return t;
    const list = S.styler.targets(n), same = list.filter(x => x.kind === 'rule' && x.sel === t.sel && x.path === t.path && (x.media || '') === (t.media || ''));
    const hit = same.find(x => x.line === t.line) || same[0] || t;
    let out = 'dev' in t && hit !== t ? Object.assign({}, hit, { dev: t.dev }) : hit;   // V7: מכשיר שנקבע במפורש — נשמר
    if (t.state && out !== t) out = Object.assign({}, out, { state: t.state });          // V8: והמצב
    return out;
  }
  /** תצוגה בזמן גרירה / גלגלת: ה-DOM של הקנבס בלבד (style עם !important), והקובץ — רק בשחרור. */
  function preview(nodes, sets) {
    const c = S.canvas; if (!c) return;
    for (const n of nodes) {
      const d = c.elOf(n); if (!d) continue;
      if (!S.pv.has(d)) S.pv.set(d, d.getAttribute('style'));
      for (const [p, v] of sets) { if (v == null) d.style.removeProperty(p); else d.style.setProperty(p, v, 'important'); }
    }
    c.draw();
  }
  function unpreview() {
    if (!S.pv.size) return;
    for (const [d, st] of S.pv) { if (st == null) d.removeAttribute('style'); else d.setAttribute('style', st); }
    S.pv.clear(); S.canvas?.draw();
  }
  /** פעולה על המודל: fn(m) מחזירה {patches}. פעולה שנדחתה (SrcError) — הודעה, ו"פתח בקוד" לאזור שבור. */
  function act(label, fn, n) {
    const c = S.canvas; if (!c?.m) return false;
    if (!canWrite()) { note(S.readOnly || 'הקובץ פתוח לקריאה בלבד', true); return false; }
    if (c.src !== S.doc.text()) { clearTimeout(S.timer); syncCanvas(); }
    let res;
    try { res = fn(c.m); }
    catch (e) {
      if (!(e instanceof K.SrcError)) throw e;
      if (e.code !== 'noop') note(e.message, true, e.code === 'broken' ? { label: '</> פתח בקוד', run: () => openInCode(n) } : null);
      return false;
    }
    return commit(res.patches, label);
  }
  /** מחיקה (V4): דרך המבנה — אזהרה כשקוד נשען על האלמנט, ובחירה מרובה. */
  function remove() { S.text?.end(); if (!S.canvas?.selected) return note('בוחרים אלמנט, ואז מוחקים'); if (S.build) return S.build.remove(); return removeOne(S.canvas.selected); }
  function removeOne(n0, cut) {
    S.text?.end();
    const c = S.canvas, n = n0 || c?.selected;
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
    pushTx([S.doc], 'מחיקת ' + label);
    if (next && c.m2d.has(next)) c.select(next);
    note((cut ? 'נגזר: ' : 'נמחק: ') + label + (inside ? ', עם ' + inside + ' אלמנטים בתוכו' : ''), false, { label: 'בטל', run: undo });
  }
  function undo() { S.text?.end(); unpreview(); if (undoTx() || (S.doc?.canUndo() && (S.doc.undo(), true))) { clearTimeout(S.timer); syncCanvas(); } else note('אין מה לבטל'); }
  function redo() { S.text?.end(); unpreview(); if (redoTx() || (S.doc?.canRedo() && (S.doc.redo(), true))) { clearTimeout(S.timer); syncCanvas(); } else note('אין מה להחזיר'); }
  /** "פתח בקוד": עורך הקוד נפתח על הקובץ, והטווח של האלמנט מסומן. אלמנט שהדפדפן הוסיף — ההורה הכתוב שלו. */
  function openInCode(n) {
    n = n || S.canvas?.selected;
    if (!S.file) return;
    while (n && n.implied) n = n.parent;
    if (!n) return Project.openAt(S.file.path, 1);
    Project.openAt(S.file.path, 0, { from: n.from, to: K.endOf(S.canvas.m, n) });
  }
  function onKey(e) {
    if (!S.host || !S.canvas?.m || e.defaultPrevented) return;
    const mod0 = e.ctrlKey || e.metaKey, k0 = e.key, code0 = e.code;
    // V13: Ctrl+S — שמירה, בכל מקום (גם בשדה ובעריכת טקסט; בעורך הקוד — שלו)
    if (mod0 && !e.altKey && !e.shiftKey && (code0 === 'KeyS' || k0 === 's' || k0 === 'S' || k0 === 'ד') && !e.target?.closest?.('.monaco-editor')) { e.preventDefault(); return saveNow(); }
    if (document.querySelector('dialog[open]') || S.palette?.el || S.menu?.el) return;   // גיליון / חיפוש / תפריט פתוח — הקיצורים של העורך אינם פעילים
    const t = e.target, tag = t && t.nodeType === 1 ? t.localName : '';
    if (S.text?.active) { if (t && t.ownerDocument !== document) S.text.key(e); return; }   // עריכת טקסט במקום: המקשים שייכים לטקסט
    if (t && t.ownerDocument === document && (/^(input|textarea|select)$/.test(tag) || t.isContentEditable || t.closest?.('dialog[open], .monaco-editor'))) return;
    const mod = e.ctrlKey || e.metaKey, k = e.key, c = S.canvas;
    const hit = fn => { e.preventDefault(); fn(); };
    if (S.pointing && k === 'Escape') return hit(() => pointing(false));
    if ((k === 'ContextMenu' || (k === 'F10' && e.shiftKey)) && c.selected) return hit(() => ctxMenu(c.selected));
    // V13: Ctrl+E — חיפוש פעולה · Ctrl+P — תצוגה חיה · Ctrl+Shift+C / V — העתק / הדבק סגנון
    if (mod && !e.altKey && !e.shiftKey && (e.code === 'KeyE' || k === 'e' || k === 'E' || k === 'ק')) return hit(() => S.palette?.open());
    if (mod && !e.altKey && !e.shiftKey && (e.code === 'KeyP' || k === 'p' || k === 'P' || k === 'פ')) return hit(liveNow);
    if (mod && e.shiftKey && !e.altKey && (e.code === 'KeyC' || k === 'c' || k === 'C' || k === 'ב') && c.selected) return hit(() => S.sc?.copy(c.selected));
    if (mod && e.shiftKey && !e.altKey && (e.code === 'KeyV' || k === 'v' || k === 'V' || k === 'ה') && c.selected) return hit(() => (S.sc?.clip ? S.sc.paste() : note('אין סגנון בלוח — Ctrl+Shift+C על אלמנט קודם', true)));
    if (mod && !e.altKey && k.toLowerCase() === 'z') return hit(e.shiftKey ? redo : undo);
    if (mod && !e.altKey && k.toLowerCase() === 'y') return hit(redo);
    if (mod && e.shiftKey && !e.altKey && (k === 'm' || k === 'M' || k === 'צ' || e.code === 'KeyM')) return hit(() => { const i = DEVICES.findIndex(d => d.id === curDevice().id); setDevice(DEVICES[(i + 1) % DEVICES.length].id); });   // V7
    // Alt + חיצים: שוליים — רק כשצד נבחר בתרשים הקופסה; אחרת מזיזים בין אחים (Alt + Shift — מוציא / מכניס) (V4)
    if (!e.altKey && !mod && /^Arrow/.test(k) && c.selected && S.pos?.isFree(c.selected) && canWrite()) return hit(() => S.pos.nudge(c.selected, k, e.shiftKey));   // V6: אלמנט ממוקם — חיצים מזיזים
    if (e.altKey && !mod && /^Arrow/.test(k) && c.selected) return hit(() => { if (S.space && S.space.sidePicked === c.selected && !e.shiftKey) S.space.nudge(k, e.shiftKey); else S.build?.key(k, e.shiftKey); });
    if (mod && !e.altKey && (k === 'd' || k === 'D' || k === 'ג') && c.selected) return hit(() => S.build?.duplicate());
    if (mod && !e.altKey && !e.shiftKey && (k === 'c' || k === 'C' || k === 'ב') && c.selected && !hasTextSel()) return hit(() => S.build?.copy(false));
    if (mod && !e.altKey && !e.shiftKey && (k === 'x' || k === 'X' || k === 'ס') && c.selected && !hasTextSel()) return hit(() => S.build?.copy(true));
    if (mod || e.altKey) return;
    if (k === 'm' || k === 'M' || k === 'צ') return hit(() => spaceMode());
    if (k === 'n' || k === 'N' || k === 'מ') return hit(() => { panel('nav', true); S.nav.list.focus(); });
    if (k === 's' || k === 'S' || k === 'ד') return hit(() => split());
    if (k === 'b' || k === 'B' || k === 'נ') return hit(() => pointing(!S.pointing));
    if (k === '?' || (k === '/' && e.shiftKey)) return hit(help);
    if (k === 'c' || k === 'C' || k === 'ב') return hit(() => openInCode());
    if (/^[1-9]$/.test(k) && DEVICES[+k - 1]) return hit(() => setDevice(DEVICES[+k - 1].id));
    if (!c.selected && /^Arrow/.test(k)) return hit(() => { const b = c.m.root && (c.m.root.kids.find(x => x.tag === 'body') || c.m.root); if (b) { c.select(b); c.reveal(b); } });
    if (k === 'i' || k === 'I' || k === 'ן') return hit(() => { panel('info', true); setTab('info'); el.infoBody.focus(); });
    if (k === 't' || k === 'T' || k === 'א') return hit(() => { panel('info', true); setTab('content'); el.contentBody.focus(); });
    if (k === 'Delete' || k === 'Backspace') return hit(remove);
    if (!c.selected) return;
    if ((k === 'Enter' || k === 'F2') && (!t || t.ownerDocument !== document || t === document.body)) return hit(() => S.text.begin(c.selected));   // רק כשהמיקוד בקנבס — Enter על כפתור של המסך נשאר שלו
    if (k === 'Escape') return hit(() => { if (c.selected.parent && !/^html$/.test(c.selected.parent.tag)) c.step('parent'); else { c.select(null); } });   // V13: בראש העץ — ביטול הבחירה
    if (k === 'ArrowUp') return hit(() => c.step('parent'));
    if (k === 'ArrowDown') return hit(() => c.step('child'));
    if (k === 'ArrowLeft' || k === 'ArrowRight') { const rtl = c.frame.contentWindow.getComputedStyle(c.doc.documentElement).direction === 'rtl'; return hit(() => c.step((k === 'ArrowLeft') === rtl ? 'next' : 'prev')); }
  }
  document.addEventListener('keydown', onKey);
  const hasTextSel = () => { try { return !!String(window.getSelection() || '').trim(); } catch { return false; } };
  /** הדבקה (V4): Ctrl+V על הקנבס — מהלוח של העורך, או מלוח המערכת (HTML, אחרת טקסט). בשדה / בעריכת טקסט — של השדה. */
  function onPaste(e) {
    if (!S.host || !S.canvas?.m || !S.build || S.text?.active || document.querySelector('dialog[open]')) return;
    const t = e.target, tag = t && t.nodeType === 1 ? t.localName : '';
    if (t && t.ownerDocument === document && (/^(input|textarea|select)$/.test(tag) || t.isContentEditable || t.closest?.('.monaco-editor'))) return;
    const d = e.clipboardData; if (!d) return;
    e.preventDefault();
    S.build.paste(d.getData('text/html') || d.getData('text/plain') || (S.build.clip ? S.build.clip.html : ''));
  }
  document.addEventListener('paste', onPaste);
  /** מסמך הקנבס (נוצר פעם אחת לכל מסגרת): הדבקה ו-Esc בזמן גרירה גם כשהמיקוד בקנבס. */
  function listenFrame(doc) { doc.addEventListener('paste', onPaste); doc.addEventListener('keydown', e => { if (e.key === 'Escape' && S.build?.drag.active) { e.preventDefault(); e.stopPropagation(); S.build.drag.cancel(); } }, true); }

  const KEYS = [['חיצים ↑ ↓', 'ההורה / הילד הראשון (בלי בחירה — מתחילים מ-body)'], ['חיצים ← →', 'האח הקודם / הבא'], ['Esc', 'ההורה (או ביטול מצב ההצבעה)'],
    ['לחיצה כפולה · Enter · F2', 'עריכת הטקסט במקום (Esc — סיום ושמירה)'], ['בעריכת טקסט: Ctrl+B · I · U', 'מודגש · נטוי · קו תחתון'], ['Delete', 'מחיקת האלמנט הנבחר'], ['Ctrl+Z · Ctrl+Y · Ctrl+Shift+Z', 'בטל · החזר — גם עריכה בעורך הקוד של העמוד (היסטוריה אחת, 🕘)'], ['Ctrl+C · Ctrl+V · Ctrl+X · Ctrl+D', 'העתק · הדבק אחרי · גזור · שכפל'], ['Ctrl+Shift+C · Ctrl+Shift+V', 'העתק סגנון · הדבק סגנון (Alt + גרירה מאלמנט לאלמנט — מכחול)'], ['Ctrl+E', 'חיפוש פעולה — כל הפעולות וכל השליטות בפאנל'], ['Ctrl+S', 'שמירה עכשיו'], ['Ctrl+P', '▶️ תצוגה חיה'], ['לחיצה ימנית · לחיצה ארוכה', 'תפריט ההקשר — בקנבס וב-Navigator'], ['C', '</> פתח בקוד'], ['N', 'Navigator — עץ האלמנטים'], ['T', 'הפאנל — לשונית "תוכן"'], ['I', 'הפאנל — לשונית "פרטים"'], ['S', 'מבט מפוצל — קוד לצד הקנבס'],
    ['M', '📏 ידיות מרווח על הקנבס'], ['Alt + ↑ ↓', 'שוליים בצד שנבחר −1 / +1 (Shift — 10)'], ['Alt + ← →', 'בחירת הצד (עליון · ימין · תחתון · שמאל)'], ['Shift + לחיצה', 'בחירה מרובה — שינוי עיצוב חל על כולם'], ['בשדה מספר: ↑ ↓ · גלגלת', '+1 / −1 · Shift ×10 · Alt ×0.1 (גם בגרירה על התווית)'],
    ['B', '🐞 לא הצלחתי לשנות את זה'], ['Shift+F10 · מקש התפריט', 'תפריט ההקשר של האלמנט הנבחר'], ['ב-Mac', 'Cmd במקום Ctrl'], ['1 · 2 · 3 · Ctrl+Shift+M', 'המכשיר: מחשב · טאבלט · טלפון — הקנבס ברוחב שלו, וכל שינוי עיצוב נכתב אליו (@media)'], ['?', 'הרשימה הזו'],
    ['ב-Navigator: ↑ ↓', 'השורה הקודמת / הבאה'], ['ב-Navigator: ← →', 'קיפול / פתיחה, ההורה / הילד'], ['ב-Navigator: H · L', '👁 הסתרה בעורך · 🔒 נעילה'], ['בעורך הקוד: Ctrl+Alt+E', 'בחר בעורך החזותי']];
  /** V13: Ctrl+S — כל מה שלא נשמר (העמוד וקובצי ה-CSS שלו), עכשיו. */
  async function saveNow() {
    S.text?.end?.();
    const dirty = [...Project._state.docs.values()].filter(d => d.dirty && !d.binary);
    if (!dirty.length) return note('הכול כבר שמור');
    try { for (const d of dirty) await Project._save(d.id); note('💾 נשמר · ' + dirty.length + (dirty.length === 1 ? ' קובץ' : ' קבצים')); } catch (e) { note(e.message, true); }
    drawState();
  }
  /** V13: Ctrl+P — ▶️ תצוגה חיה של העמוד (אחרי שמירה), בחלון משלה. */
  async function liveNow() {
    if (!S.file) return;
    try { for (const d of [...Project._state.docs.values()].filter(x => x.dirty && !x.binary)) await Project._save(d.id); } catch (e) { note(e.message, true); }
    window.open('live.php?path=' + encodeURIComponent(S.file.path), 'kol-live');
  }
  function help() { sheet('קיצורי מקלדת', h('table', { class: 'ed-keys' }, h('tbody', {}, KEYS.map(([k, t]) => h('tr', {}, h('th', { scope: 'row', dir: 'ltr' }, h('kbd', {}, k)), h('td', {}, t)))))); }
  /** V7: בורר המכשיר — כפתור לכל מכשיר, הרוחב (חופשי — "טלפון · 430px": לאיזה מכשיר הרוחב שייך), ו"נקודות שבירה…". */
  function drawDevices() {
    if (!el.dev) return;
    const cur = curDevice(), w = deviceWidth();
    const wIn = h('input', { type: 'number', class: 'ed-dev-w', min: '240', max: '4000', step: '1', dir: 'ltr', value: String(w), 'aria-label': 'רוחב הקנבס בפיקסלים (רוחב חופשי)', title: 'רוחב חופשי — הקנבס ברוחב הזה, והכתיבה הולכת למכשיר שהרוחב שייך אליו', 'data-dev-width': true });
    wIn.addEventListener('change', () => { const v = Math.round(+wIn.value); if (v >= 240 && v <= 4000) setWidth(v); else wIn.value = String(deviceWidth()); });
    el.dev.replaceChildren(...DEVICES.map((d, i) => h('button', { type: 'button', 'data-dev': d.id, 'aria-pressed': String(d.id === S.device), 'aria-label': d.label + (d.max ? ' — עד ' + d.max + 'px' : ' — הבסיס') + ' (' + (i + 1) + ')', title: d.label + (d.max ? ' · עד ' + d.max + 'px · תצוגה ' + d.width + 'px' : ' · הבסיס (בלי @media)') + ' · Ctrl+Shift+M',
      class: d.wide ? 'wide' : '', onclick: () => setDevice(d.id) }, d.icon)),
      h('span', { class: 'ed-dev-name', 'data-dev-name': cur.id }, cur.label + ' · '), wIn, h('span', { class: 'ed-dev-px' }, 'px'),
      h('button', { type: 'button', class: 'ed-dev-bp', 'data-breakpoints': true, title: 'נקודות שבירה… — שם, רוחב מרבי ותצוגה לכל מכשיר (kol.json)', 'aria-label': 'נקודות שבירה', onclick: breakpoints }, '⚙'));
    if (el.root) { el.root.dataset.devi = String(DEVICES.indexOf(cur)); el.root.dataset.devId = cur.id; }
    drawDevNote();
  }
  function setDevice(id) {
    S.device = id; S.freeW = 0; prefs.device = id; rememberDev(); keep();
    S.canvas?.setViewport(deviceWidth()); S.canvas?.markDev?.(); drawDevices(); drawInfo(); S.nav?.refresh(); S.space?.draw?.();
  }
  /** המכשיר שנבחר — לעמוד הזה (עד 40 עמודים). */
  function rememberDev() { if (!S.file) return; const m = prefs.devByPage && typeof prefs.devByPage === 'object' ? prefs.devByPage : {}; delete m[S.file.path]; m[S.file.path] = S.device; const k = Object.keys(m); for (const x of k.slice(0, Math.max(0, k.length - 40))) delete m[x]; prefs.devByPage = m; }
  /** רוחב חופשי: הקנבס ברוחב שנבחר, והמכשיר — זה שהרוחב שייך אליו. */
  function setWidth(w) {
    S.freeW = w; S.device = belongs(w).id; prefs.device = S.device; rememberDev(); keep();
    S.canvas?.setViewport(w); S.canvas?.markDev?.(); drawDevices(); drawInfo(); S.nav?.refresh(); S.space?.draw?.();
  }
  /** קובצי ה-CSS של העמוד, לעדכון @media: הגיליונות המקושרים, ותוכן כל <style> בעמוד (עם המיקום שלו ב-HTML). */
  function cssTexts() {
    const out = [...new Set([...S.sheets.values()].map(x => x.doc))].map(doc => ({ doc, path: [...S.sheets.values()].find(x => x.doc === doc).path, text: doc.text(), off: 0 }));
    const m = S.canvas?.m; if (m?.root) K.walk(m.root, n => { if (n.tag === 'style' && !n.implied && n.st && n.et) out.push({ doc: S.doc, html: true, path: S.file.path, text: m.src.slice(n.st[1], n.et[0]), off: n.st[1] }); });
    return out;
  }
  /** V7: "נקודות שבירה…" — שם, max ו-preview לכל מכשיר; הוספה ומחיקה. נכתב ל-kol.json, ושינוי max מעדכן (אם בוחרים) את כל
   *  ה-@media שנכתבו לנקודה — עסקה אחת, עם תצוגה מקדימה של מה שישתנה. */
  async function breakpoints() {
    const ST = window.KolStyle; if (!ST || !S.canvas?.m) return;
    const kf = Project.docs.byPath('kol.json');
    const rows = DEVICES.filter(d => d.max).map(d => ({ id: d.id, label: d.label, max: d.max, preview: d.width, was: d.max }));
    const list = h('div', { class: 'ed-bp-list', 'data-bp-list': true }), sum = h('div', { class: 'ed-bp-sum', 'data-bp-sum': true, role: 'status' });
    const upd = h('label', { class: 'ed-bp-upd' }, h('input', { type: 'checkbox', checked: true, 'data-bp-media': true }), ' לעדכן גם את ה-@media שנכתבו לנקודה');
    const err = h('p', { class: 'ed-warn', hidden: true, 'data-bp-err': true });
    const changes = () => {
      const texts = cssTexts(), moved = [], gone = [];
      for (const r of rows) if (r.was && !r.del && +r.max !== r.was) { let rules = 0, files = new Set(); for (const t of texts) { const x = ST.mediaRetarget(t.text, r.was, +r.max); if (x.blocks) { rules += x.rules; files.add(t.path); } } moved.push({ r, rules, files: files.size }); }
      for (const r of rows) if (r.was && r.del) { let rules = 0; for (const t of texts) rules += ST.mediaCount(t.text, r.was); gone.push({ r, rules }); }
      return { moved, gone };
    };
    const draw = () => {
      list.replaceChildren(h('div', { class: 'ed-bp-row head' }, h('span', {}, 'שם'), h('span', {}, 'עד (max, px)'), h('span', {}, 'תצוגה (px)'), h('span', {})),
        ...rows.filter(r => !r.del).map(r => {
          const inp = (k, type, extra) => { const i = h('input', Object.assign({ type, value: String(r[k]), 'data-bp': k, dir: type === 'number' ? 'ltr' : 'auto', 'aria-label': { label: 'שם', max: 'עד (max)', preview: 'תצוגה' }[k] + ' — ' + r.label }, extra)); i.addEventListener('input', () => { r[k] = type === 'number' ? Math.round(+i.value) : i.value.trim(); drawSum(); }); return i; };
          return h('div', { class: 'ed-bp-row', 'data-bp-row': r.id }, inp('label', 'text', { maxlength: '30' }), inp('max', 'number', { min: '200', max: '4000' }), inp('preview', 'number', { min: '240', max: '4000' }),
            h('button', { type: 'button', class: 'btn sm', 'data-bp-del': r.id, 'aria-label': 'מחיקת ' + r.label, onclick: () => { r.del = true; draw(); } }, '🗑'));
        }),
        h('button', { type: 'button', class: 'btn sm', 'data-bp-add': true, onclick: () => { const min = Math.min(...rows.filter(r => !r.del).map(r => +r.max), 1280); const max = Math.max(240, Math.round(min * 0.75)); rows.push({ id: 'bp-' + max + '-' + Date.now().toString(36).slice(-3), label: 'מכשיר ' + max, max, preview: Math.max(240, max - 40) }); draw(); } }, '＋ נקודה'));
      drawSum();
    };
    const drawSum = () => {
      const { moved, gone } = changes(), lines = [];
      for (const x of moved) lines.push(h('p', { 'data-bp-moved': x.r.id }, '↔ ' + x.r.label + ': ' + x.r.was + 'px ← ' + x.r.max + 'px' + (x.rules ? ' · ' + x.rules + ' כללים ב-' + x.files + (x.files === 1 ? ' קובץ' : ' קבצים') + ' כתובים לנקודה הזו' : ' · אין כללים כתובים לנקודה')));
      for (const x of gone) lines.push(h('p', { class: x.rules ? 'ed-warn' : '', 'data-bp-gone': x.r.id }, '🗑 ' + x.r.label + (x.rules ? ' — ' + x.rules + ' כללים כתובים לנקודה. הם נשארים בקבצים ואינם נמחקים: יחולו כשהמסך צר מ-' + (x.r.was + 1) + 'px, אבל לא יופיעו כמכשיר.' : ' — אין כללים כתובים לנקודה.')));
      upd.hidden = !moved.some(x => x.rules);
      sum.replaceChildren(...(lines.length ? lines : [h('p', { class: 'hint' }, 'מה שישתנה יופיע כאן לפני השמירה.')]));
    };
    const save = h('button', { type: 'button', class: 'btn primary', 'data-bp-save': true }, 'שמירה');
    draw();
    const dlg = sheet('נקודות שבירה', h('p', { class: 'hint' }, 'כל נקודה היא מכשיר: שינוי עיצוב בו נכתב ל-@media (max-width: <עד>px). "מחשב" — הבסיס, בלי @media. נשמר ב-kol.json.'), list, sum, upd, err, h('div', { class: 'ed-ask-bar' }, save));
    save.addEventListener('click', async () => {
      const live = rows.filter(r => !r.del), bad = live.find(r => !r.label || !(r.max >= 200 && r.max <= 4000) || !(r.preview >= 240 && r.preview <= 4000)) || live.find((r, i) => live.findIndex(x => +x.max === +r.max) !== i);
      if (bad) { err.hidden = false; err.textContent = '⚠️ ' + (bad.label || 'נקודה') + ': שם, ו-max בין 200 ל-4000 (שונה מכל נקודה אחרת), ותצוגה בין 240 ל-4000.'; return; }
      if (!kf) { err.hidden = false; err.textContent = '⚠️ אין kol.json בשורש הפרויקט'; return; }
      const kd = await Project.docs.open(kf.id); if (!kd || kd.binary) { err.hidden = false; err.textContent = '⚠️ kol.json לא נפתח'; return; }
      const sorted = live.slice().sort((a, b) => b.max - a.max).map(r => ({ id: r.id, label: r.label, max: +r.max, preview: +r.preview }));
      let next; try { next = ST.kolBreakpoints(kd.text(), sorted); } catch (e) { err.hidden = false; err.textContent = '⚠️ ' + e.message; return; }
      const was = kd.text(), ops = [{ doc: kd, patches: [{ from: 0, to: was.length, text: next }] }], mv = rows.filter(r => r.was && !r.del && +r.max !== r.was);
      // עדכון ה-@media: כל קובץ מתוכנן על הטקסט העדכני (אחרי מה שכבר נכתב באותה עסקה)
      if (mv.length && upd.querySelector('input').checked) for (const r of mv) ops.push(() => cssTexts().filter(t => ST.mediaRetarget(t.text, r.was, +r.max).blocks).map(t => ({ doc: t.doc, patches: ST.mediaRetarget(t.text, r.was, +r.max).patches.map(p => ({ from: p.from + t.off, to: p.to + t.off, text: p.text })) })));
      const label = 'נקודות שבירה · ' + sorted.map(r => r.label + ' ' + r.max).join(' · ');
      const diff = (a, b) => { let i = 0; while (i < a.length && i < b.length && a[i] === b[i]) i++; let j = 0; while (j < a.length - i && j < b.length - i && a[a.length - 1 - j] === b[b.length - 1 - j]) j++; return [{ from: i, to: a.length - j, text: b.slice(i, b.length - j) }]; };
      ops[0].patches = next === was ? [] : diff(was, next);
      const ok = writeOps(ops, label);
      if (!ok) return;
      dlg?.close?.();
      kolChanged(next);
      note('נקודות השבירה נשמרו ב-kol.json' + (mv.length && upd.querySelector('input').checked ? ' וה-@media עודכנו' : ''), false, { label: 'בטל', run: undo });
    });
  }
  /** kol.json השתנה: המכשירים מחדש (קובץ שאינו נקרא — נשארים האחרונים התקינים). */
  function kolChanged(text) {
    let cfg; try { cfg = JSON.parse(String(text).replace(/^\uFEFF/, '')); } catch { return; }
    if (!cfg || typeof cfg !== 'object') return;
    S.kol = cfg; const was = JSON.stringify(DEVICES); DEVICES = devicesFrom({ config: cfg });
    if (JSON.stringify(DEVICES) === was) return;
    if (!DEVICES.some(d => d.id === S.device)) S.device = DEVICES[0].id;
    S.freeW = 0; S.canvas?.setViewport(deviceWidth()); drawDevices(); drawInfo();
  }
  /** בראש הפאנל: לאן הכתיבה הולכת עכשיו. */
  function drawDevNote() {
    if (!el.devNote) return;
    const d = curDevice();
    el.devNote.hidden = !d.max || S.tab === 'glob';                        // V9: "🌐 כללי" כותב ל-:root / לתגית בבסיס — לא למכשיר
    if (!d.max) return el.devNote.replaceChildren();
    el.devNote.replaceChildren(h('b', {}, d.icon + ' ' + d.label), ' — כל שינוי עיצוב נכתב ל-', h('code', { dir: 'ltr' }, '@media (max-width: ' + d.max + 'px)'), ' ולא משנה את המחשב. שינוי מבנה (הזזה, הוספה, מחיקה, טקסט, תמונה) — חל על כל המכשירים.');
  }

  // ── ציור ──────────────────────────────────────────────────
  const deviceWidth = () => S.freeW || (DEVICES.find(d => d.id === S.device) || DEVICES[0]).width;
  function status(text) { if (el.status) el.status.textContent = text; }
  function drawState() {
    const d = S.doc; if (!d) return;
    if (d.errorStatus === 423 && !S.readOnly) { S.readOnly = d.error; note(d.error + ' — כאן אפשר לראות ולבחור, לא לשנות', true); S.code?.ed.updateOptions({ readOnly: true }); }   // נעילת העריכה הקיימת
    status(S.readOnly ? '🔒 לקריאה בלבד' : d.conflict ? '⚠️ הקובץ השתנה בינתיים' : d.saving ? 'שומר…' : d.dirty ? (d.error ? '⚠️ ' + d.error : 'לא נשמר') : 'נשמר · גרסה ' + d.rev);
    el.undo.disabled = !(S.tx.length || d.canUndo()) || !!S.readOnly; el.redo.disabled = !(S.rtx.length || d.canRedo()) || !!S.readOnly;
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
  function drawInfo() {
    if (!infoOpen()) return;
    try { ({ content: S.content, design: S.design, adv: S.adv, info: S.info, glob: S.glob })[S.tab]?.draw(); } catch (e) { console.error(e); }
    drawTools();
  }
  /** לשוניות הפאנל: "תוכן" (נערך — V2) ו"פרטים" (קריאה בלבד — V1b). "עיצוב" ו"מתקדם" — V3 והלאה. */
  function setTab(id) {
    S.tab = ['info', 'design', 'adv', 'glob'].includes(id) ? id : 'content'; prefs.tab = S.tab; keep();
    if (S.tab === 'glob' && !infoOpen()) panel('info', true);
    if (!el.ptabs) return;
    for (const b of el.ptabs.children) b.setAttribute('aria-selected', String(b.dataset.ptab === S.tab));
    el.contentBody.hidden = S.tab !== 'content'; el.infoBody.hidden = S.tab !== 'info'; el.designBody.hidden = S.tab !== 'design'; el.advBody.hidden = S.tab !== 'adv'; if (el.globBody) el.globBody.hidden = S.tab !== 'glob';
    drawDevNote?.();
    S.space?.draw();
    drawInfo();
  }
  /** כפתורי הסרגל שתלויים בבחירה: "החלף" לתמונה, "＋ תמונה". */
  function drawTools() {
    if (!el.addImg) return;
    const n = S.canvas?.selected, w = canWrite();
    el.addImg.disabled = !w; el.swap.disabled = !w;
    if (n && n.tag === 'img') { if (!el.swap.isConnected) el.addImg.after(el.swap); } else el.swap.remove();   // "החלף" בסרגל — רק כשנבחרה תמונה
    if (el.hideBtn) { const ok3 = !!(n && w && S.canvas.elOf(n) && !/^(html|body|head)$/.test(n.tag)), d = curDevice(), on = ok3 && (d.max ? !!S.canvas.devHidden?.has(n) : S.canvas.visual(n) === 'shown' && !S.text?.active);   // זול: בלי לאסוף את כללי ה-CSS בכל ציור (גם בזמן עריכת טקסט)
      el.hideBtn.disabled = !ok3; el.hideBtn.setAttribute('aria-pressed', String(on)); el.hideBtn.title = (on ? 'הצג ב' : 'הסתר ב') + d.label + ' — display' + (on ? '' : ': none') + (d.max ? ' ב-@media (max-width: ' + d.max + 'px)' : ' בכלל הבסיסי (המכשירים הצרים שומרים על התצוגה)'); el.hideBtn.lastChild.textContent = on ? ' הצג ב' + d.label : ' הסתר ב' + d.label; }
    if (el.freeBtn) { const ok2 = !!(n && w && S.canvas.elOf(n) && !/^(html|body|head)$/.test(n.tag)), fr = ok2 && S.pos?.isFree(n); el.freeBtn.disabled = !ok2; el.freeBtn.setAttribute('aria-pressed', String(!!fr)); }
    const bgOn = !!(n && S.bg && S.bg.hasImage(n));                       // V5: "🖼 תמונת רקע" — החלף · הסר · הזז
    if (bgOn) { if (!el.bgTools.isConnected) (el.swap.isConnected ? el.swap : el.addImg).after(el.bgTools); for (const b of el.bgTools.querySelectorAll('button')) b.disabled = !w; } else el.bgTools?.remove();
    drawHandle();
  }

  // ── תמונה ומדיה: הספרייה של הכלי ─────────────────────────
  const KIND_EXT = () => ({ image: window.KolContent.IMG_EXT, audio: window.KolContent.AUD_EXT, video: window.KolContent.VID_EXT });
  const kindOfFile = f => { const k = f.media_info?.kind || ''; if (/^(image|audio|video)$/.test(k)) return k; const X = KIND_EXT(); return X.image.test(f.path) ? 'image' : X.audio.test(f.path) ? 'audio' : X.video.test(f.path) ? 'video' : 'other'; };
  /** נתיב בפרויקט ← הפניה יחסית מהקובץ הנערך (מקודדת כמו בכתובת). */
  function relRef(path) {
    const from = dirOf(S.file.path).split('/').filter(Boolean), to = path.split('/');
    let i = 0; while (i < from.length && i < to.length - 1 && from[i] === to[i]) i++;
    return '../'.repeat(from.length - i) + to.slice(i).map(encodeURIComponent).join('/');
  }
  const refPath = ref => (S.file ? resolve(dirOf(S.file.path), ref || '') : null);
  /** כתובת להצגה של הפניה מהעמוד: קובץ בפרויקט — מהתצוגה המבודדת; כתובת מלאה — כמו שהיא. */
  const refUrl = ref => { if (!ref) return ''; if (/^(https?:)?\/\//i.test(ref)) return ref; const p = refPath(ref); return p != null && S.token ? pvUrl(p) : ''; };
  const safeName = name => (name || 'file').normalize('NFC').replace(/[\/:*?"<>|#%&{}$!'@+`=\s]+/g, '-').replace(/^-+|-+$/g, '') || 'file';
  /** מעלה קובץ לספרייה של הכלי ומקשר אותו לפרויקט תחת media/. מחזיר את הנתיב בפרויקט. */
  async function uploadToProject(file) {
    const m = await Media.upload(file);
    return linkToProject(m.id, file.name);
  }
  async function linkToProject(fileId, name) {
    const files = await Project.docs.reload(), hit = files.find(f => f.kind === 'media' && f.media === fileId);
    if (hit) return hit.path;
    const base = safeName(name), dot = base.lastIndexOf('.'), stem = dot > 0 ? base.slice(0, dot) : base, ext = dot > 0 ? base.slice(dot) : '';
    let path = 'media/' + base; for (let i = 2; files.some(f => f.path === path); i++) path = 'media/' + stem + '-' + i + ext;
    await api('pf_link', { path, file_id: fileId });
    await Project.docs.reload();
    return path;
  }
  /** בורר הספרייה: קבצים שכבר בפרויקט, קבצים שבספרייה של הכלי (יקושרו לפרויקט בבחירה), והעלאה. מחזיר נתיב בפרויקט, או null. */
  function pick(kind, title) {
    return new Promise(done => {
      const dlg = document.getElementById('sheet'); let settled = false;
      const finish = v => { if (settled) return; settled = true; dlg.removeEventListener('close', onClose); if (dlg.open) dlg.close(); done(v); };
      const onClose = () => finish(null);
      const grid = h('div', { class: 'ed-pick', role: 'listbox', 'aria-label': title }), msg = h('p', { class: 'hint', role: 'status' }, 'טוען…');
      const accept = kind === 'image' ? 'image/*' : kind === 'audio' ? 'audio/*' : kind === 'video' ? 'video/*' : '';
      const up = h('input', { type: 'file', accept: accept || null, hidden: true, 'data-pick-file': true });
      up.addEventListener('change', async () => { const f = up.files[0]; if (!f) return; msg.textContent = 'מעלה…'; try { finish(await uploadToProject(f)); } catch (e) { msg.textContent = '⚠️ ' + e.message; } });
      const thumb = (k, url) => { if (k === 'image' && url) { const im = h('img', { alt: '', loading: 'lazy' }); im.src = url; return im; } return h('span', { class: 'ed-pick-ic', 'aria-hidden': 'true' }, k === 'audio' ? '🔊' : k === 'video' ? '🎬' : '📄'); };
      const item = (label, sub, k, url, run) => h('button', { type: 'button', class: 'ed-pick-item', role: 'option', 'data-pick': label, title: label, onclick: async () => { msg.textContent = 'בוחר…'; try { finish(await run()); } catch (e) { msg.textContent = '⚠️ ' + e.message; } } }, thumb(k, url), h('span', { class: 'ed-pick-name', dir: 'ltr' }, label), h('span', { class: 'hint' }, sub));
      sheet(title, h('div', { class: 'ed-pick-bar' }, h('button', { type: 'button', class: 'btn primary', 'data-pick-upload': true, onclick: () => up.click() }, '⬆️ העלאה מהמחשב'), up), msg, grid);
      dlg.addEventListener('close', onClose);
      (async () => {
        try {
          const files = await Project.docs.reload(), want = f => kind === 'any' || kindOfFile(f) === kind, linked = new Set(files.filter(f => f.kind === 'media').map(f => f.media));
          const rows = files.filter(f => f.kind !== 'dir' && want(f) && (kind !== 'any' || f.path !== S.file.path)).sort((a, b) => a.path.localeCompare(b.path)).map(f => item(f.path, 'בפרויקט', kindOfFile(f), S.token ? pvUrl(f.path) : '', async () => f.path));
          let lib = []; try { lib = (await api('files')).files || []; } catch { lib = []; }
          for (const f of lib) if (!linked.has(f.id) && (kind === 'any' || f.kind === kind)) rows.push(item(f.name, 'בספרייה של הכלי', f.kind, f.url, () => linkToProject(f.id, f.name)));
          grid.replaceChildren(...rows);
          msg.textContent = rows.length ? rows.length + ' קבצים. בחירה בקובץ מהספרייה מקשרת אותו לפרויקט (תחת media/).' : 'אין עדיין קבצים מהסוג הזה — מעלים אחד מהמחשב.';
        } catch (e) { msg.textContent = '⚠️ ' + e.message; }
      })();
    });
  }
  /** "＋ תמונה": אחרי האלמנט הנבחר (בלי בחירה — בסוף ה-body), עם המחלקה של תמונות אחיות אם יש. */
  function insertImage(path, target) {
    const c = S.canvas; if (!c?.m) return false;
    let n = target || c.selected; const body = c.m.root && (c.m.root.kids.find(x => x.tag === 'body') || c.m.root);
    for (let x = n; x; x = x.parent) if (x.tag === 'head') n = null;
    if (!n || !n.parent || n.tag === 'body' || n.tag === 'html') n = null;
    while (n && n.implied) n = n.parent && n.parent.parent ? n.parent : null;
    const par = n ? n.parent : body, index = n ? par.kids.indexOf(n) + 1 : null;
    const sib = par.kids.find(k => k.tag === 'img' && K.attrOf(k, 'class')), cls = sib ? K.attrOf(sib, 'class').value : '';
    const html = '<img src="' + window.KolText.escAttr(relRef(path)) + '" alt=""' + (cls ? ' class="' + window.KolText.escAttr(cls) + '"' : '') + '>';
    const pp = K.pathOf(par), at = index == null ? par.kids.length : index;
    if (!act('הוספת תמונה · ' + path.split('/').pop(), m => K.html.insert(m, par, index, html), par)) return false;
    const fresh = K.byPath(c.m, pp.concat(at)); if (fresh) { c.select(fresh); c.reveal(fresh); }
    return true;
  }
  async function addImage() { S.text?.end(); const p = await pick('image', 'הוספת תמונה'); if (p) insertImage(p); }
  async function swapImage() { S.text?.end(); if (S.content && S.canvas?.selected?.tag === 'img') S.content.replaceImage(S.canvas.selected); }
  /** קובץ שנגרר מהמחשב אל הקנבס: על תמונה — מחליף אותה; במקום אחר — תמונה חדשה אחרי האלמנט שמתחת לסמן. */
  async function dropFile(file, n) {
    if (!canWrite()) return note(S.readOnly || 'הקובץ פתוח לקריאה בלבד', true);
    if (!/^image\//.test(file.type) && !KIND_EXT().image.test(file.name)) return note('אפשר לגרור לכאן קובץ תמונה. שמע ווידאו — מהפאנל "תוכן".', true);
    const c = S.canvas, path0 = n ? K.pathOf(n) : null;
    note('מעלה את ' + file.name + '…');
    let path; try { path = await uploadToProject(file); } catch (e) { return note(e.message, true); }
    let t = path0 ? K.byPath(c.m, path0) : null;
    if (t && t.tag !== 'img' && S.bg) {                                       // V5: על רובריקה עם תמונת רקע — רקע או אלמנט?
      let host = t; while (host && !S.bg.hasImage(host)) host = host.parent;
      if (host) { const how = await S.bg.ask(host); if (!how) return; const cur = K.byPath(c.m, K.pathOf(host)); if (how === 'bg' && cur) { c.select(cur); return S.bg.usePath(cur, path); } }
      t = path0 ? K.byPath(c.m, path0) : null;
    }
    if (t && t.tag === 'img') { const had = !!K.attrOf(t, 'srcset'); if (act('החלפת תמונה · ' + c.label(t), m => ({ patches: K.html.setAttr(m, t, 'src', relRef(path)).patches.concat(had ? K.html.removeAttr(m, t, 'srcset').patches : []) }), t)) { c.select(K.byPath(c.m, path0)); note('התמונה הוחלפה' + (had ? ', וה-srcset שלה הוסר' : ''), false, { label: 'בטל', run: undo }); } }
    else if (insertImage(path, t)) note('נוספה תמונה', false, { label: 'בטל', run: undo });
  }
  /** ידית הגודל של תמונה נבחרת: גרירה משנה רוחב ושומרת יחס; Shift — חופשי. נכתב בתכונת style. */
  function drawHandle() {
    S.space?.draw(); S.fx?.drawHandles(); S.mo?.drawHandles();   // V10
    const c = S.canvas, hd = el.handle; if (!hd || !c) return;
    const n = c.selected, r = n && n.tag === 'img' && canWrite() && !S.text?.active ? c.rectOf(n) : null;
    hd.hidden = !r; if (!r || hd._drag) return;
    hd.style.cssText = 'left:' + (r.left + r.width - 7) + 'px;top:' + (r.top + r.height - 7) + 'px';
  }
  function handleDown(e) {
    const c = S.canvas, n = c?.selected, d = n && c.elOf(n); if (!d || n.tag !== 'img') return;
    e.preventDefault(); el.handle.setPointerCapture(e.pointerId);
    const r0 = d.getBoundingClientRect(), x0 = e.clientX, y0 = e.clientY, ratio = r0.height / (r0.width || 1), st0 = d.getAttribute('style'), rtlSide = false;
    let w = Math.round(r0.width), hgt = Math.round(r0.height), free = false;
    el.handle._drag = true; el.size.hidden = false;
    const move = ev => {
      free = ev.shiftKey; w = Math.max(8, Math.round(r0.width + (ev.clientX - x0) / c.scale)); hgt = free ? Math.max(8, Math.round(r0.height + (ev.clientY - y0) / c.scale)) : Math.round(w * ratio);
      d.style.width = w + 'px'; d.style.height = free ? hgt + 'px' : 'auto';
      c.draw(); const r = c.rectOf(n); if (r) { el.handle.style.cssText = 'left:' + (r.left + r.width - 7) + 'px;top:' + (r.top + r.height - 7) + 'px'; el.size.style.cssText = 'left:' + r.left + 'px;top:' + (r.top + r.height + 4) + 'px'; }
      el.size.textContent = w + ' × ' + hgt + ' px' + (free ? ' · חופשי' : '');
    };
    const up = ev => {
      el.handle.removeEventListener('pointermove', move); el.handle.removeEventListener('pointerup', up); el.handle.removeEventListener('pointercancel', up);
      el.handle._drag = false; el.size.hidden = true;
      if (st0 == null) d.removeAttribute('style'); else d.setAttribute('style', st0);          // ה-DOM חוזר; הכתיבה — דרך המודל
      if (ev.type === 'pointerup' && (w !== Math.round(r0.width) || free)) {
        const SE = window.KolContent.styleEdit, cur = (K.attrOf(n, 'style') || {}).value || '', next = SE(SE(cur, 'width', w + 'px'), 'height', free ? hgt + 'px' : (window.KolContent.styleGet(cur, 'height') ? 'auto' : null));
        act('גודל ' + w + (free ? '×' + hgt : '') + 'px · ' + c.label(n), m => K.html.setAttr(m, n, 'style', next), n);
      }
      c.draw(); drawHandle();
    };
    el.handle.addEventListener('pointermove', move); el.handle.addEventListener('pointerup', up); el.handle.addEventListener('pointercancel', up);
  }
  /** משאבים חיצוניים של העמוד שאינם נטענים בקנבס (המדיניות של העורך) — פס עם הרשימה, כטקסט. */
  function drawBlocked() {
    if (!el.blocked) return;
    const list = [...(S.blocked || new Map()).keys()];
    el.blocked.hidden = !list.length;
    if (list.length) el.blocked.replaceChildren(h('summary', {}, list.length + ' משאבים חיצוניים אינם מוצגים בעורך — הם מוצגים ב"▶️ תצוגה חיה"'), h('ul', { dir: 'ltr' }, list.slice(0, 50).map(u => h('li', {}, u))));
  }
  function drawAll() {
    if (!S.host) return;
    drawState(); drawCrumbs(); S.nav?.refresh(); drawInfo(); drawTools(); saveMarks();
    if (S.build) { S.build.tools._n = null; S.build.draw(); }
    S.lv?.drawStrip(); S.data?.drawLib();
    S.lay?.drawHandles(); S.pos?.drawHandle(); S.fx?.drawHandles(); S.mo?.drawHandles();   // ידיות הקנבס של V6 — גם אחרי כתיבת CSS בלבד (הקנבס אינו מצויר מחדש אז)
    const cap = S.canvas?.capInfo;
    el.cap.hidden = !cap;
    if (cap) el.cap.textContent = cap.shown ? 'מוצגים כל האלמנטים · הצג 60' : 'מוצגים 60 מכל רשימה ארוכה · הצג הכול (' + cap.hidden + ' מוסתרים)';
    const broken = S.canvas?.m?.marks || 0;
    el.broken.hidden = !broken;
    if (broken) el.broken.textContent = '⚠️ בקובץ יש קוד שבור (' + broken + ') — האזור מסומן, ואי אפשר לשנות אותו מכאן';
    /* V15 */ drawHeadStrip(); S.head?.refresh(); S.pages?.drawBroken(S.canvas, K, S.file?.path); S.pages?.drawBtn();
  }
  /* V15: קוד מותאם בראש / בסוף העמוד — בקנבס אינו רץ (03 §2) */
  function drawHeadStrip() {
    if (!el.headStrip || !S.canvas?.m || !window.KolHead) return;
    let sc; try { sc = window.KolHead.scan(K, S.canvas.m); } catch { sc = null; }
    const parts = sc ? [sc.headCode && 'בראש העמוד', sc.bodyCode && 'בסוף העמוד'].filter(Boolean) : [];
    el.headStrip.hidden = !parts.length;
    if (parts.length) el.headStrip.replaceChildren(h('span', {}, '⌨️ קוד ' + parts.join(' ו') + ' — בקנבס אינו רץ · '), h('button', { type: 'button', class: 'linkish', 'data-headstrip-live': true, onclick: () => livePage() }, '▶️ תצוגה חיה'), ' · ', h('button', { type: 'button', class: 'linkish', onclick: () => S.head?.show() }, '⚙️ הגדרות העמוד'));
  }
  /* V15: ▶️ תצוגה חיה — העמוד שבעורך (לא תמיד entry) */
  async function livePage(path) {
    path = path || S.file?.path; if (!path) return;
    if (S.file && S.file.path === path) { try { await Project._save(S.file.id); } catch { /* נשמר אוטומטית בהמשך */ } }
    document.querySelector('.pj-live[data-ed-live]')?.remove();
    const box = h('div', { class: 'pj-live', 'data-ed-live': path }, h('iframe', { src: 'live.php?embed=1&path=' + encodeURIComponent(path), title: 'תצוגה חיה: ' + path }));
    document.body.append(box);
    if (!livePage.bc) { try { livePage.bc = new BroadcastChannel('kol-pf'); livePage.bc.addEventListener('message', e => { if (e.data && e.data.t === 'close') document.querySelector('.pj-live[data-ed-live]')?.remove(); }); } catch { /* בלי ערוץ — Escape */ } }
    const esc = e => { if (e.key === 'Escape') { box.remove(); document.removeEventListener('keydown', esc); } }; document.addEventListener('keydown', esc);
  }
  /* V15: מעבר עמוד — הנוכחי נשמר (אין שינוי שאובד), הבא נפתח באותו מכשיר */
  async function openPage(path, fresh) {
    let f = Project.docs.byPath(path); if (!f || fresh) { await Project.docs.reload(); f = Project.docs.byPath(path); }
    if (!f) { note('העמוד ' + path + ' אינו בפרויקט', true); return; }
    if (S.file && !fresh) { try { await Project._save(S.file.id); } catch (e) { note('העמוד הנוכחי לא נשמר: ' + e.message, true); return; } }
    const m = prefs.devByPage && typeof prefs.devByPage === 'object' ? prefs.devByPage : {}; m[path] = S.device; prefs.devByPage = m; keep();
    await refreshPageList();
    el.page.value = f.id; await open(f.id);
    try { history.replaceState(history.state, '', '#/editor/' + encodeURIComponent(path)); } catch { /* כתובת שאינה ניתנת לשינוי — רענון יפתח מהזיכרון */ }
  }
  async function refreshPageList() {
    const files = (await Project.docs.files()).filter(f => f.kind === 'file' && /\.html?$/i.test(f.path)).sort((a, b) => a.path.localeCompare(b.path));
    const cur = el.page.value;
    el.page.replaceChildren(...files.map(f => h('option', { value: f.id, 'data-old-tpl': Project.oldTemplate(f.path) || null, title: Project.oldTemplate(f.path) ? 'תבנית ישנה (עם {{…}}) — העמוד עצמו הוא index.html' : null }, f.path)));
    if ([...el.page.options].some(o => o.value === cur)) el.page.value = cur;
  }
  function note(text, err, action) {
    if (!el.note) return;
    clearTimeout(note.t);
    el.note.className = 'ed-note' + (err ? ' err' : '');
    el.note.replaceChildren(...[h('span', {}, text), action ? h('button', { type: 'button', class: 'btn sm', onclick: () => { el.note.hidden = true; action.run(); } }, action.label) : null,
      h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => { el.note.hidden = true; } }, '✕')].filter(Boolean));   // replaceChildren(null) כותב "null"
    el.note.hidden = false;
    note.t = setTimeout(() => { el.note.hidden = true; }, err || action ? 12000 : 5000);
  }

  /** V4: ידית בין פאנל צד לקנבס — גרירה משנה את רוחב הפאנל (200–560px), נשמר בדפדפן. */
  function grip(id) {
    const g = h('div', { class: 'ed-grip', 'data-grip-side': id, role: 'separator', 'aria-orientation': 'vertical', 'aria-label': 'רוחב ' + (id === 'nav' ? 'ה-Navigator' : 'הפאנל'), title: 'גרירה — רוחב הפאנל' });
    g.addEventListener('pointerdown', e => {
      if (e.button > 0) return; e.preventDefault();
      const side = el.root.querySelector('.ed-' + id), w0 = side ? side.getBoundingClientRect().width : 280, x0 = e.clientX;
      try { g.setPointerCapture(e.pointerId); } catch { /* */ }
      g.classList.add('on');
      const mv = ev => { const w = Math.round(Math.max(200, Math.min(560, w0 + (id === 'nav' ? ev.clientX - x0 : x0 - ev.clientX)))); el.root.style.setProperty('--ed-' + id + '-w', w + 'px'); prefs[id + 'W'] = w; };
      const up = () => { g.classList.remove('on'); g.removeEventListener('pointermove', mv); g.removeEventListener('pointerup', up); g.removeEventListener('pointercancel', up); keep(); };
      g.addEventListener('pointermove', mv); g.addEventListener('pointerup', up); g.addEventListener('pointercancel', up);
    });
    return g;
  }
  async function view(host, wantPath) {
    S.host = host;
    el.page = h('select', { class: 'ed-page', 'aria-label': 'עמוד', onchange: () => { const f = (Project._state.files || []).find(x => x.id === +el.page.value); if (f && S.pages) openPage(f.path); else open(+el.page.value); } });   // V15: מעבר שומר את הנוכחי
    el.status = h('span', { class: 'ed-status', role: 'status' });
    el.undo = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'בטל (Ctrl+Z)', title: 'בטל (Ctrl+Z)', disabled: true, onclick: undo }, '↶');
    el.redo = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'החזר (Ctrl+Y)', title: 'החזר (Ctrl+Y)', disabled: true, onclick: redo }, '↷');
    el.del = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'מחיקת האלמנט הנבחר (Delete)', title: 'מחיקה (Delete)', disabled: true, onclick: remove }, '🗑️');
    el.code = h('button', { type: 'button', class: 'btn sm', title: 'פתח בקוד — הקובץ, על האלמנט הנבחר', disabled: true, onclick: () => openInCode() }, '</>', h('span', { class: 'ed-word' }, ' פתח בקוד'));
    el.dev = h('div', { class: 'ed-dev', role: 'group', 'aria-label': 'רוחב הקנבס' }); drawDevices();
    el.crumbs = h('nav', { class: 'ed-crumbs', dir: 'ltr', 'aria-label': 'שביל ניווט' });
    el.where = h('span', { class: 'ed-where' });
    el.seltools = h('span', { class: 'ed-seltools-host' });
    el.cap = h('button', { type: 'button', class: 'ed-strip', hidden: true, onclick: () => { S.canvas.showAll(!S.canvas.capOff); drawAll(); } });
    el.broken = h('p', { class: 'ed-strip warn', role: 'alert', hidden: true });
    el.compStrip = h('div', { class: 'ed-strip cm-strip', hidden: true, 'data-comp-strip': true, role: 'group', 'aria-label': 'עריכת רכיב' });   // V12
    el.lvStrip = h('div', { class: 'ed-strip lv-strip', hidden: true, 'data-lv-strips': true, role: 'group', 'aria-label': 'רשימות הנתונים בקנבס' });   // V11
    el.seed = h('p', { class: 'ed-strip warn ed-seed', role: 'alert', 'data-seed': true, hidden: true });
    el.blocked = h('details', { class: 'ed-strip ed-blocked', hidden: true });
    el.note = h('div', { class: 'ed-note', role: 'status', hidden: true });
    /* V15 */ el.headStrip = h('p', { class: 'ed-strip ed-headstrip', 'data-head-strip': true, hidden: true });
    el.hdBtn = h('button', { type: 'button', class: 'icon-btn', 'data-head-btn': true, 'aria-label': 'הגדרות העמוד — כותרת, תיאור, תמונת שיתוף, סמל, קוד בראש העמוד', title: '⚙️ הגדרות העמוד — כותרת, תיאור, שיתוף, סמל, שפה, קוד בראש העמוד', onclick: () => (S.head?.open ? S.head.hide() : S.head?.show()) }, '⚙️');
    el.liveBtn = h('button', { type: 'button', class: 'icon-btn', 'data-ed-live-btn': true, 'aria-label': 'תצוגה חיה — העמוד שבעורך', title: '▶️ תצוגה חיה — העמוד שבעורך, כפי שירוץ אצל הגולש', onclick: () => livePage() }, '▶️');
    el.hdSide = h('aside', { class: 'ed-side ed-hd', 'aria-label': 'הגדרות העמוד', 'data-head-side': true }, h('div', { class: 'ed-side-head' }, h('strong', {}, '⚙️ הגדרות העמוד'), h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירת הגדרות העמוד', onclick: () => S.head?.hide() }, '✕')), h('div', { class: 'ed-side-body hd-body' }));
    el.pgOverlay = h('div', { class: 'ed-pgover', 'data-pg-overlay': true });
    el.canvas = h('div', { class: 'ed-canvas' });
    const toggle = (id, icon, label) => h('button', { type: 'button', class: 'icon-btn', 'data-panel': id, 'aria-pressed': 'false', 'aria-label': label, title: label, onclick: () => panel(id) }, icon);
    const side = (id, title, body) => h('aside', { class: 'ed-side ed-' + id, 'aria-label': title },
      h('div', { class: 'ed-side-head' }, h('strong', {}, title), h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירת ' + title, onclick: () => panel(id, false) }, '✕')), body);
    el.splitBtn = h('button', { type: 'button', class: 'icon-btn', 'data-split': true, 'aria-pressed': 'false', 'aria-label': 'מבט מפוצל — קוד לצד הקנבס (S)', title: 'מבט מפוצל — קוד לצד הקנבס (S)', onclick: () => split() }, '◫');
    el.pane = h('div', { class: 'ed-code', 'aria-label': 'הקוד של העמוד' });
    el.tabs = h('div', { class: 'ed-tabs', role: 'tablist', 'aria-label': 'קנבס או קוד' }, [['canvas', '🖼️ קנבס'], ['code', '</> קוד']].map(([id, label]) => h('button', { type: 'button', role: 'tab', 'data-tab': id, 'aria-selected': String(id === 'canvas'), onclick: () => tab(id) }, label)));
    el.bug = h('button', { type: 'button', class: 'icon-btn', 'data-bug': true, 'aria-pressed': 'false', 'aria-label': 'לא הצלחתי לשנות את זה — דיווח (B)', title: '🐞 לא הצלחתי לשנות את זה — לוחצים, ומצביעים על מה שלא הצלחת לשנות (B)', onclick: () => pointing(!S.pointing) }, '🐞');
    el.point = h('div', { class: 'ed-strip ed-point', role: 'status', hidden: true });
    el.gapbar = h('div', { class: 'ed-strip ed-gapbar', role: 'group', 'aria-label': 'מרווח — מספר', 'data-gapbar': true, hidden: true });
    el.spaceBtn = h('button', { type: 'button', class: 'icon-btn', 'data-space': true, 'aria-pressed': 'false', 'aria-label': 'ידיות מרווח על הקנבס (M)', title: 'ידיות מרווח על הקנבס — שוליים, ריפוד, ורווח בין אחים (M)', onclick: () => spaceMode() }, '📏');
    el.navBody = h('div', { class: 'ed-side-body nv' });
    el.libBody = h('div', { class: 'ed-side-body ed-libbody', hidden: true });
    el.compBody = h('div', { class: 'ed-side-body ed-libbody ed-compbody', hidden: true, 'data-comp-body': true });   // V12
    el.tplBody = h('div', { class: 'ed-side-body ed-libbody ed-tplbody', hidden: true, 'data-tpl-body': true });   // V13: 📄 תבניות
    el.histBody = h('div', { class: 'ed-side-body ed-libbody ed-histbody', hidden: true, 'data-hist-body': true });   // V13: 🕘 היסטוריה
    const navTab = id => h('button', { type: 'button', role: 'tab', 'data-ntab': id, 'aria-selected': String(id === 'tree'), title: { tree: 'עץ האלמנטים', lib: 'הוספה מהספרייה', comp: 'רכיבים', tpl: 'תבניות — מקטעים ועמודים שמורים', hist: 'היסטוריה — כל צעד בשמו, וגרסאות' }[id], onclick: () => navTabs(id) }, { tree: '🌳 עץ', lib: '＋ ספרייה', comp: '🧩 רכיבים', tpl: '📄 תבניות', hist: '🕘 היסטוריה' }[id]);
    el.navTabs = h('div', { class: 'ed-ntabs', role: 'tablist', 'aria-label': 'עץ, ספרייה, רכיבים, תבניות או היסטוריה' }, navTab('tree'), navTab('lib'), navTab('comp'), navTab('tpl'), navTab('hist'));
    el.navWrap = h('div', { class: 'ed-navwrap' }, el.navTabs, el.navBody, el.libBody, el.compBody, el.tplBody, el.histBody);
    el.infoBody = h('div', { class: 'ed-side-body dt', tabindex: '-1', role: 'tabpanel', 'aria-label': 'פרטים' });
    el.contentBody = h('div', { class: 'ed-side-body ct', tabindex: '-1', role: 'tabpanel', 'aria-label': 'תוכן' });
    el.designBody = h('div', { class: 'ed-side-body sg', tabindex: '-1', role: 'tabpanel', 'aria-label': 'עיצוב' });
    el.advBody = h('div', { class: 'ed-side-body sg', tabindex: '-1', role: 'tabpanel', 'aria-label': 'מתקדם' });
    el.globBody = h('div', { class: 'ed-side-body gl', tabindex: '-1', role: 'tabpanel', 'aria-label': 'סגנון כללי', hidden: true });
    el.ptabs = h('div', { class: 'ed-ptabs', role: 'tablist', 'aria-label': 'לשוניות הפאנל' }, [['content', '✏️ תוכן'], ['design', '🎨 עיצוב'], ['adv', '📐 מתקדם'], ['info', 'ℹ️ פרטים'], ['glob', '🌐 כללי']].map(([id, label]) => h('button', { type: 'button', role: 'tab', 'data-ptab': id, 'aria-selected': 'false', onclick: () => setTab(id) }, label)));
    el.devNote = h('p', { class: 'ed-devnote', 'data-dev-note': true, hidden: true });
    el.panelBody = h('div', { class: 'ed-side-body' }, el.ptabs, el.devNote, el.infoBody, el.contentBody, el.designBody, el.advBody, el.globBody);
    el.addImg = h('button', { type: 'button', class: 'btn sm', 'data-add-img': true, title: 'הוספת תמונה — אחרי האלמנט הנבחר', disabled: true, onclick: addImage }, '＋🖼️', h('span', { class: 'ed-word' }, ' תמונה'));
    el.swap = h('button', { type: 'button', class: 'btn sm', 'data-swap-img': true, title: 'החלפת התמונה הנבחרת', onclick: swapImage }, '🖼️', h('span', { class: 'ed-word' }, ' החלף'));
    const bgAct = fn => () => { const n = S.canvas?.selected; if (!n || !S.bg) return; S.text?.end(); const im = S.bg.image(n, S.styler.info(n)), L = im.layers.find(x => x.kind === 'url'); fn(n, L); };
    el.bgTools = h('span', { class: 'ed-bgtools', role: 'group', 'aria-label': 'תמונת הרקע של הרובריקה', 'data-bg-tools': true }, h('span', { class: 'ed-word' }, '🖼 תמונת רקע:'),
      h('button', { type: 'button', class: 'btn sm', 'data-bgt': 'replace', title: 'החלפת תמונת הרקע — מהספרייה או מהמחשב', onclick: bgAct((n, L) => S.bg.replace(n, L)) }, '🖼', h('span', { class: 'ed-word' }, ' החלף')),
      h('button', { type: 'button', class: 'btn sm', 'data-bgt': 'remove', title: 'הסרת תמונת הרקע (הרובריקה והתוכן נשארים)', onclick: bgAct((n, L) => { if (L) S.bg.remove(n, L); else note('התמונה אינה כתובה בקובץ שנערך כאן', true); }) }, '🗑', h('span', { class: 'ed-word' }, ' הסר')),
      h('button', { type: 'button', class: 'btn sm', 'data-bgt': 'move', title: 'הזזה והגדלה של התמונה בתוך הרובריקה — על הקנבס', onclick: bgAct(n => S.bg.move(n)) }, '✥', h('span', { class: 'ed-word' }, ' הזז')));
    el.freeBtn = h('button', { type: 'button', class: 'btn sm', 'data-free-btn': true, 'aria-pressed': 'false', title: 'מיקום חופשי — האלמנט יוצא מהזרימה ונגרר לכל מקום ברובריקת הייחוס', disabled: true, onclick: () => { const n = S.canvas?.selected; if (!n || !S.pos) return; S.text?.end(); if (S.pos.isFree(n)) S.pos.off(n); else S.pos.on(n, S.pos.pickRef); } }, '✥', h('span', { class: 'ed-word' }, ' מיקום חופשי'));
    el.hideBtn = h('button', { type: 'button', class: 'btn sm', 'data-devhide-btn': true, 'aria-pressed': 'false', disabled: true, onclick: () => { const n = S.canvas?.selected; if (!n || !S.devs) return; S.text?.end(); const d = curDevice(); S.devs.set(n, d, !(d.max ? S.canvas.devHidden?.has(n) : S.devs.hidden(n, d))); } }, '👁', h('span', { class: 'ed-word' }, ' הסתר'));
    el.bar = h('div', { class: 'ed-bar' }, el.page, /* V15 */ h('span', { class: 'ed-pghost', 'data-pg-host': true }), el.dev, el.undo, el.redo, el.del, el.addImg, el.freeBtn, el.hideBtn, el.code, toggle('nav', '🌳', 'Navigator — עץ האלמנטים (N)'), toggle('info', 'ℹ️', 'הפאנל — תוכן (עריכה) ופרטים: מה האלמנט, איפה הוא בקוד, ואיזה עיצוב חל עליו (I)'), /* V15 */ el.hdBtn, el.liveBtn, el.splitBtn, el.spaceBtn, el.bug, h('button', { type: 'button', class: 'icon-btn', 'data-help': true, 'aria-label': 'קיצורי מקלדת (?)', title: 'קיצורי מקלדת (?)', onclick: help }, '?'), el.status);
    el.mid = h('div', { class: 'ed-mid' }, el.tabs, h('div', { class: 'ed-panes' }, el.canvas, el.pane));
    el.root = h('section', { class: 'ed', 'aria-label': 'העורך החזותי' },
      el.bar, h('div', { class: 'ed-path' }, el.seltools, el.crumbs, el.where), el.seed, /* V15 */ el.headStrip, el.cap, el.lvStrip, el.compStrip, el.broken, el.blocked, el.point, el.gapbar,
      h('div', { class: 'ed-body' }, side('nav', 'Navigator', el.navWrap), grip('nav'), el.mid, grip('info'), side('info', 'האלמנט הנבחר', el.panelBody), /* V15 */ el.hdSide), el.note, el.pgOverlay);
    for (const id of ['nav', 'info']) if (prefs[id + 'W'] >= 200 && prefs[id + 'W'] <= 560) el.root.style.setProperty('--ed-' + id + '-w', prefs[id + 'W'] + 'px');
    host.replaceChildren(el.root);
    status('טוען…');
    try {
      await loadJs(); K = window.KolSrc;
      if (S.host !== host) return;
      const c = S.canvas = new window.KolCanvas.Canvas(el.canvas);
      c.devMaxes = () => DEVICES.filter(d => d.max).map(d => d.max);   // V7: רק @media של נקודות שבירה הוא "מוסתר במכשיר"
      c.drag = false; c.repeatUp = true; c.marksOn = true;                  // V1a: בלי גרירה (V4); לחיצה חוזרת — ההורה
      S.nav = new window.KolNavigator.Navigator(el.navBody, { canvas: c, K, onPick: n => { c.select(n); c.reveal(n); if (n.tag === 'head' && S.head) S.head.show(); /* V15: ראש העמוד ← הטופס */ }, onToggle: saveMarks });
      S.info = new window.KolDetails.Details(el.infoBody, { canvas: c, K, sheets: () => S.sheets, file: () => S.file, openAt: (p, line) => Project.openAt(p, line), openEl: n => openInCode(n) });
      S.text = new window.KolText.TextEdit(c, { canWrite: () => (canWrite() ? '' : S.readOnly || 'הקובץ פתוח לקריאה בלבד'), commit: (ps, label) => { if (c.src !== S.doc.text()) return false; return commit(ps, label); },
        run: (label, fn) => act(label, fn), undo: () => { if (undoTx() || (S.doc?.canUndo() && (S.doc.undo(), true))) { clearTimeout(S.timer); syncCanvas(); } }, redo: () => { if (redoTx() || (S.doc?.canRedo() && (S.doc.redo(), true))) { clearTimeout(S.timer); syncCanvas(); } },
        note, openInCode, onState: () => { el.root.classList.toggle('typing', !!S.text?.active); drawCrumbs(); } });
      { const prev = c.onListen; c.onListen = doc => { if (prev) prev(doc); listenFrame(doc); }; if (c.ready && c.doc) listenFrame(c.doc); }
      c.onDbl = (n, pt) => { if (S.pointing) return; if (S.lv && S.lv.inList(n) && S.lv.binding(n) && /^(text|html)$/.test(S.lv.binding(n).kind) && S.lv.binding(n).field) { S.lv.scope = 'word'; S.lv.bar._key = ''; if (S.lv.beginValue(n)) return; } S.text.begin(n, pt); };   // V11: ערך קשור — "המילה הזו", במקום
      S.content = new window.KolContent.Content(el.contentBody, { canvas: c, file: () => S.file, files: () => Project._state.files || [], act, canWrite: () => !!canWrite(), begin: n => S.text.begin(n), openEl: n => openInCode(n), openAt: (p, line) => Project.openAt(p, line),
        note, pick, url: refUrl, resolve: refPath, rel: relRef, details: S.info, removeEl: remove, tagField: n => S.build?.tagField(n) });
      el.handle = h('button', { type: 'button', class: 'ed-handle', hidden: true, 'aria-label': 'שינוי גודל התמונה — גרירה (Shift: בלי לשמור יחס)', title: 'גרירה משנה את הרוחב ושומרת יחס · Shift — חופשי' }); el.handle.addEventListener('pointerdown', handleDown);
      el.size = h('span', { class: 'ed-size', hidden: true }); c.layer.append(el.handle, el.size);
      c.onDraw = () => { S.comp?.drawTags(); S.states?.apply(); S.lv?.drawBoxes(); drawHandle(); S.build?.draw(); S.bg?.place(); S.pos?.drawHandle(); S.lay?.drawHandles(); S.fx?.drawHandles(); S.mo?.drawHandles(); }; c.onDropFile = (file, n) => dropFile(file, n);
      S.styler = new window.KolStyle.Styler({ canvas: c, details: S.info, sheets: () => S.sheets, file: () => S.file, doc: () => S.doc, pagePath, basePath, getDevice: writeDevice, getDevices: () => DEVICES.map(d => ({ id: d.id, label: d.label, max: d.max })) });
      const dOpt = mode => ({ mode, canvas: c, styler: S.styler, nodes: () => c.picked(), write: writeStyle, preview, unpreview, openAt: (p, line) => Project.openAt(p, line), canWrite: () => !!canWrite(), sheets: () => S.sheets,
        highlight: els => S.space?.highlight(els), hint: phys => S.space?.hint(phys), layout: (n, d) => S.build?.layoutSection(n, d), pickSide: side => { if (S.space) { S.space.side = side; S.space.sidePicked = c.selected; } }, setDevice: id => setDevice(id) });
      S.states = new window.KolStates.States({ canvas: c, K, note, onChange: () => drawAll() });   // V8: מצבים
      S.ident = new window.KolIdentity.Identity({ canvas: c, styler: S.styler, writeOps, note, undo, pagePath, docOf: path => { for (const x of S.sheets.values()) if (x.path === path) return x.doc; return null; }, html: () => S.doc, filePath: () => S.file?.path,
        files: () => Project._state.files || [], openDoc: f => Project.docs.open(f.id), highlight: els => S.space?.highlight(els), canWrite: () => !!canWrite(), openInCode, phone, jsTexts: () => (S.js || []).map(x => ({ path: x.path, text: x.doc.text() })) });
      S.extra = new window.KolExtra.Extra({ canvas: c, styler: S.styler, writeOps, note, html: () => S.doc, docOf: path => { for (const x of S.sheets.values()) if (x.path === path) return x.doc; return null; } });
      S.design = new window.KolDesign.Design(el.designBody, dOpt('design'));
      S.bg = new window.KolBg.Bg(S.design, { writeOps, pick, note, undo, phone, rel: relRef, blocked: () => S.blocked || new Map(), docOf: path => { for (const x of S.sheets.values()) if (x.path === path) return x.doc; return null; }, html: () => S.doc, filePath: () => S.file?.path, files: () => Project._state.files || [], layer: () => c.layer, sheet });
      S.design.bgSection = n => S.bg.section(n);
      S.adv = new window.KolDesign.Design(el.advBody, dOpt('adv'));
      S.pos = new window.KolPos.Pos({ design: S.adv, write: writeStyle, act, joinTx, note, undo, phone, layer: () => c.layer, X: window.KolStruct, setDevice: id => { const d = DEVICES.find(x => x.id === id) || DEVICES.slice().sort((a, b) => (+a.width || 1e9) - (+b.width || 1e9))[0]; if (d) setDevice(d.id); }, busy: () => !!S.bg?.mv || !!S.text?.active });
      S.lay = new window.KolLayout.Layout({ design: S.adv, write: writeStyle, note, undo, phone, layer: () => c.layer, busy: () => !!S.bg?.mv || !!S.text?.active || !!S.pos?.dragging, on: () => S.tab === 'adv' && (infoOpen() || phone()) });
      S.devs = new window.KolDevices.Devices({ design: S.adv, write: writeStyle, note, undo, device: writeDevice, devs: () => DEVICES, setDevice });
      S.adv.extra = n => [...S.ident.section(n), ...S.lay.sections(n), ...S.pos.section(n), ...S.devs.section(n)];
      for (const D of [S.design, S.adv]) { D.states = S.states; D.identity = S.ident; D.extraProps = (n, d) => S.extra.section(n, d); }
      // V9: "🌐 כללי" — גופנים, משתנים, ברירות מחדל לתגיות; ומכל שליטה: ⛓ קשר למשתנה / צור משתנה מהערך
      const docOfP = path => { for (const x of S.sheets.values()) if (x.path === path) return x.doc; return null; };
      S.glob = new window.KolGlobals.Globals(el.globBody, { canvas: c, styler: S.styler, writeOps, joinTx, attachFx, note, undo, html: () => S.doc, filePath: () => S.file?.path, sheets: () => [...S.sheets.values()], docOf: docOfP, pages: pagesDocs,
        files: () => Project._state.files || [], reload: () => Project.docs.reload(), kol: () => S.kol, design: () => S.design, setTab, basePath, linkSheet, pvUrl: p => (S.token ? pvUrl(p) : ''), canWrite: () => !!canWrite(),
        highlight: els => S.space?.highlight(els), openAt: (p, line) => Project.openAt(p, line), jsTexts: () => (S.js || []).map(x => ({ path: x.path, text: x.doc.text() })) });
      for (const D of [S.design, S.adv]) { D.varBtn = (n, phys, label, d) => S.glob.bindBtn(n, phys, label, d); D.varChip = (n, phys, s, d) => S.glob.chip(n, phys, s, d); D.glob = S.glob; }
      S.nav.order = n => S.devs.seen(n);
      S.space = new window.KolSpacing.Spacing(c, { styler: S.styler, design: S.adv, write: writeStyle, preview, unpreview, canWrite: () => !!canWrite(), note, undo, phone, on: () => (S.spaceOn || (S.tab === 'adv' && (infoOpen() || phone())) ? 'adv' : S.tab === 'design' && infoOpen() ? 'design' : false), sheetHost: () => el.gapbar });
      S.adv.whyGap = n => S.space.whyNode(n);
      // V10: אפקטים ותנועה — "עיצוב": מסגרת, צל, אפקטים, מסיכה, מפריד, מעבר; "מתקדם": transform, כניסה, גלילה, נדבק, וידאו / מצגת ברקע
      const fxBusy = () => !!S.bg?.mv || !!S.text?.active || !!S.pos?.dragging || !!S.lay?.dragging;
      S.fx = new window.KolFx.Fx({ design: S.design, adv: S.adv, write: writeStyle, writeOps, joinTx, note, undo, phone, layer: () => c.layer, busy: fxBusy, on: () => S.tab === 'design' && (infoOpen() || phone()),
        html: () => S.doc, docOf: docOfP, pagePath, pick, filePath: () => S.file?.path, shapeUrl: id => 'assets/editor-shapes/' + encodeURIComponent(id) + '.svg', reorder: (...a) => S.lay.reorder(...a) });
      S.mo = new window.KolMotion.Motion({ design: S.design, adv: S.adv, fx: S.fx, write: writeStyle, writeOps, joinTx, attachFx, txCount: () => S.tx.length, note, undo, phone, layer: () => c.layer, busy: () => fxBusy() || !!S.fx?.dragging, on: () => S.tab === 'adv' && (infoOpen() || phone()),
        html: () => S.doc, docOf: docOfP, pagePath, sheets: () => S.sheets, linkSheet, files: () => Project._state.files || [], reload: () => Project.docs.reload(), openDoc: f => Project.docs.open(f.id), filePath: () => S.file?.path, pick, devs: () => DEVICES,
        seedUrl: name => 'assets/project-seed/' + encodeURIComponent(name), api: (a, b) => api(a, b) });
      S.design.fx = n => S.fx.sections(n); S.design.transition = (n, d) => S.mo.transition(n, d);
      { const ex = S.adv.extra; S.adv.extra = n => [...ex(n), ...S.mo.sections(n)]; }
      el.bar.append(S.mo.button());
      S.build = new window.KolBuild.Build({ canvas: c, nav: S.nav, navHost: el.libBody, toolsHost: el.seltools, K, X: window.KolStruct, styler: S.styler, act, multi, writeStyle, note, sheet, undo, canWrite: () => !!canWrite(), device: writeDevice, devices: () => S.devs,
        pick, rel: relRef, openInCode, linkSheet, removeOne, pagePath, docOf: path => { for (const x of S.sheets.values()) if (x.path === path) return x.doc; return null; }, sheetsList: () => [...S.sheets.values()],
        jsTexts: () => (S.js || []).map(x => ({ path: x.path, text: x.doc.text() })), filePath: () => S.file?.path, linkedPaths: () => { const out = []; if (c.m?.root) K.walk(c.m.root, n => { if (n.tag === 'link' && /stylesheet/i.test(K.attrOf(n, 'rel')?.value || '')) { const hr = K.attrOf(n, 'href'); if (hr) out.push(resolve(dirOf(S.file.path), hr.value)); } }); return out; },
        begin: (n, pt, all) => { if (S.text.begin(n, pt) && all && S.text.e) { const sl = c.doc.getSelection(); sl.selectAllChildren(S.text.e); } } });
      // V11: רשימת הנתונים בקנבס — הכרטיסים, "התבנית / המילה הזו", פאנל "נתונים"
      S.lv = new window.KolList.ListView(c, { K, B: window.KolBind, strip: el.lvStrip, note, undo, sheet, canWrite: () => !!canWrite(),
        resolve: ref => (S.file ? resolve(dirOf(S.file.path), ref) : null),
        load: async path => {
          let f = Project.docs.byPath(path); if (!f) { await Project.docs.reload(); f = Project.docs.byPath(path); }
          if (!f) throw new Error('קובץ הנתונים ' + path + ' אינו בפרויקט — הרשימה ריקה בקנבס');
          const d = await api('pf_read', undefined, '&id=' + f.id);
          if (d.binary) throw new Error(path + ' אינו טקסט');
          let data; try { data = JSON.parse(d.content); } catch (e) { throw new Error(path + ' אינו JSON תקין — ' + e.message); }
          return { data, rev: d.file.rev, id: f.id, internal: d.file.internal || [] };
        },
        save: async (id, rev, content, check) => {
          const p = await api('pf_write', { id, rev, content, tx: ['המילה הזו (עורך)'] });
          if (!p.plan) { Project.docs.reload().catch(() => {}); return { data: JSON.parse(content), rev: p.file.rev }; }   // קובץ JSON רגיל (לא טבלה) — נשמר כמו שהוא
          check(p.plan);
          const d = await api('pf_write', { id, rev, content, apply: true, token: p.plan.token });
          Project.docs.reload().catch(() => {});                              // קובץ פתוח בעורך הקוד — מתעדכן (syncOpen)
          return { data: JSON.parse(d.content), rev: d.file.rev };
        },
        pushFx: (label, fx) => { S.tx.push({ docs: [], label, after: new Map(), fx: [fx] }); S.rtx = []; drawState(); },
        pick: async kind => { const r = await Media.pick({ kind }); return Array.isArray(r) ? r[0] : r; },
        kd: () => {
          const sys = S.kol?.system?.['js/kol-data.js']; if (sys && sys.modified === true) return { modified: true };
          const mine = (S.js || []).find(x => x.path === 'js/kol-data.js'); if (!mine || !S.kdSeed) return { modified: false };
          const t = mine.doc.text(), ver = x => (/version:\s*'([\d.]+)'/.exec(x) || [])[1];
          return { modified: ver(t) === ver(S.kdSeed) && t !== S.kdSeed };
        },
        openWord: (L, item) => S.data?.openWord(L, item) });
      c.lv = S.lv; S.lv.onScope = () => drawInfo();
      if (!S.kdSeed) fetch('assets/project-seed/kol-data.js', { credentials: 'same-origin' }).then(r => (r.ok ? r.text() : '')).then(t => { S.kdSeed = t; S.lv?.drawStrip(); }).catch(() => {});
      S.data = new window.KolDataPanel.Data({ canvas: c, lv: S.lv, K, X: window.KolStruct, build: S.build, act, multi, writeOps, joinTx, html: () => S.doc, styler: S.styler, write: writeStyle, note, sheet, undo, canWrite: () => !!canWrite(), api: (a, b, q) => api(a, b, q), pagePath, docOf: docOfP, select: n => { c.select(n); c.reveal(n); }, content: S.content, libHost: el.libBody, drawAll: () => drawAll() });
      // V12: רכיבים — הלשונית "🧩 רכיבים", ההגדרות ב"תוכן", התגים, עריכת רכיב ועדכון מופעים (components.js)
      S.comp = new window.KolComponents.Components({ canvas: c, K, X: window.KolStruct, build: S.build, content: S.content, nav: S.nav, act, writeOps, joinTx, attachFx, txCount: () => S.tx.length,
        pushFx: (label, fx) => { S.tx.push({ docs: [], label, after: new Map(), fx: [fx] }); S.rtx = []; drawState(); },
        note, sheet, undo, canWrite: () => !!canWrite(), pick, rel: relRef, refPath, file: () => S.file, doc: () => S.doc, pages: pagesDocs, styler: S.styler,
        openPath: async p => { const f = Project.docs.byPath(p); if (!f) return note('הקובץ ' + p + ' אינו בפרויקט', true); if (![...el.page.options].some(o => o.value === String(f.id))) el.page.append(h('option', { value: f.id }, f.path)); el.page.value = f.id; await open(f.id); },
        linkSheet, drawAll: () => drawAll(), host: el.compBody, strip: el.compStrip });
      // V13: היסטוריה עם שמות, העתק / הדבק / אפס סגנון, תבניות, תפריט ההקשר ו-Ctrl+E
      S.hist = new window.KolHistory.History({ S, host: el.histBody, undo, redo, drawState, note, sheet, api: (a, b, q) => api(a, b, q), writeOps, canWrite: () => !!canWrite(), pathOf: d => d.path || '' });
      S.sc = new window.KolStyleCopy.StyleCopy({ canvas: c, styler: S.styler, writeStyle, writeOps, joinTx, txCount: () => S.tx.length, state: () => S, note, sheet, undo, canWrite: () => !!canWrite(), html: () => S.doc, file: () => S.file, devs: () => DEVICES.filter(d => d.max) });
      c.onAltDrag = (phase, n, t) => { if (!canWrite()) return; S.sc.altDrag(phase, n, t); };
      S.tpl = new window.KolTemplates.Templates({ canvas: c, build: S.build, comp: () => S.comp, styler: S.styler, writeOps, joinTx, txCount: () => S.tx.length, state: () => S, pushFx: (label, fx) => { S.tx.push({ docs: [], label, after: new Map(), fx: [fx] }); S.rtx = []; drawState(); S.hist?.draw(); },
        note, sheet, undo, canWrite: () => !!canWrite(), file: () => S.file, doc: () => S.doc, pagePath, docOf: docOfP, sheets: () => [...new Set([...S.sheets.values()])], api: (a, b, q) => api(a, b, q), pvUrl: p => (S.token ? pvUrl(p) : ''), host: el.tplBody, shoot: n => window.KolReport.shoot(c, n) });
      S.actions = new window.KolCtx.Actions({ S, K, canvas: () => S.canvas, build: () => S.build, sc: () => S.sc, comp: () => S.comp, tpl: () => S.tpl, canWrite: () => !!canWrite(), readOnly: () => S.readOnly, note, undo, redo, joinTx, openInCode, report: n => reportSheet(n),
        panel, setTab, select: n => { if (n) { c.select(n); c.reveal(n); } }, editText: n => S.text.begin(n), save: saveNow, live: liveNow, help, split: () => split(), addImage, addLib: id => { const it = S.build.items().find(x => x.id === id); if (it) S.build.addHere(it); },
        hist: () => { panel('nav', true); navTabs('hist'); }, tpls: () => { panel('nav', true); navTabs('tpl'); }, recent: id => S.palette?.used('a:' + id) });
      S.menu = new window.KolCtx.Menu(S.actions, { c: () => S.canvas, phone, mac: /Mac|iPhone|iPad/.test(navigator.platform || '') });
      S.palette = new window.KolPalette.Palette({ actions: S.actions, canvas: c, panel, setTab, designHost: () => el.designBody, advHost: () => el.advBody, note, phone });
      S.nav.onContext = (n, x, y) => ctxMenu(n, x, y, 'nav');
      /* V15: ⚙️ הגדרות העמוד (head.js) ו-📄 עמודים (pages.js) */
      S.head = new window.KolHead.Head({ canvas: c, K, act, joinTx, note, openInCode, pick, rel: relRef, url: p => refUrl(p), canWrite: () => !!canWrite(), phone, host: el.hdSide,
        openAt: off => { const n = nodeAt(off); if (n) openInCode(n); else openInCode(); },
        pageUrl: () => (S.pages?.data?.base || '/lexicon/') + window.KolPages.urlOf(S.file?.path || ''), host_: () => location.host,
        editBody: () => { const b = c.m?.root?.kids?.find(k => k.tag === 'body'); if (!b) return note('לעמוד אין <body>', true); S.head.hide(); c.select(b); c.reveal(b); panel('info', true); setTab('design'); },
        onState: on => { el.root.classList.toggle('hd-open', on && !phone()); el.hdBtn.setAttribute('aria-pressed', String(!!on)); S.canvas?.setViewport(deviceWidth()); } });
      S.pages = new window.KolPages.Pages({ headSettings: () => S.head?.show(), liveTitle: () => { try { return c.m ? window.KolHead.scan(K, c.m).values.title : ''; } catch { return ''; } }, api: (a, b, q) => api(a, b, q), note, sheet, current: () => S.file?.path, openPath: (p, fresh) => openPage(p, fresh), pvUrl: p => (S.token ? pvUrl(p) : ''), phone, live: p => livePage(p),
        canPublish: () => RANK[state.user?.role] >= RANK.publisher, reload: () => Project.docs.reload(), anchor: () => el.root, overlay: () => el.pgOverlay,
        onManager: on => el.root.classList.toggle('pg-open', !!on && !phone()) });
      el.bar.querySelector('[data-pg-host]').replaceWith(S.pages.btn);
      if (S.actions) {                                                  // V13: תפריט ההקשר וחיפוש הפעולה
        const L = S.actions.list.bind(S.actions), G = S.actions.global.bind(S.actions);
        S.actions.list = () => [...L(), { id: 'page-settings', icon: '⚙️', label: 'הגדרות העמוד — כותרת, שיתוף, סמל, קוד', keys: '', why: n => (/^(html|head|body)$/.test(n.tag) ? '' : 'על <html>, <head> או <body>'), run: () => S.head?.show() }];
        S.actions.global = () => [...G(), { id: 'page-settings', icon: '⚙️', label: 'הגדרות העמוד', keys: '', run: () => S.head?.show() }, { id: 'pages', icon: '📄', label: 'עמודים — חדש, כתובת, סדר, 404', keys: '', run: () => S.pages?.manager() }];
      }
      S.content.pages = S.pages;
      S.pages.load().then(() => drawAll());
      setTab(prefs.tab);
      c.onSelect = () => { if (S.bg?.mv && S.bg.mv.n !== c.selected) S.bg.stop(); drawCrumbs(); drawTools(); S.nav.sync(); drawInfo(); markInCode(); if (el.gapbar) el.gapbar.hidden = true; }; c.onKey = onKey; c.onContext = (n, x, y) => ctxMenu(n, x, y);
      S.touch?.unmount(); S.touch = new window.KolTouch.Touch({ S, el, panel, phone });   /* V14: מגע — גיליון בשלושה גבהים, האלמנט גלוי מעליו, מקלדת, ↑ ↓, צביטה */
      c.onBlocked = (uri, dir) => { if (!S.blocked || S.blocked.has(uri)) return; S.blocked.set(uri, dir); clearTimeout(S.blockedT); S.blockedT = setTimeout(drawBlocked, 100); };
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
      el.page.replaceChildren(...files.map(f => h('option', { value: f.id, 'data-old-tpl': Project.oldTemplate(f.path) || null, title: Project.oldTemplate(f.path) ? 'תבנית ישנה (עם {{…}}) — העמוד עצמו הוא index.html' : null }, f.path)));   // הטקסט — הנתיב בלבד; הסימון בתכונה ובעץ
      // העמוד האחרון שנפתח, ובלעדיו index.html. קובץ תבנית ישן (from-current/) נפתח רק כשמבקשים אותו במפורש — לא מהזיכרון ולא כ"ראשון"
      const old = f => Project.oldTemplate(f.path), mine = prefs.pageBy && prefs.pageBy[uid()], mem = files.find(f => f.path === (prefs.pageBy ? mine : prefs.page) && !old(f));   // V15: לכל משתמש
      const first = files.find(f => f.path === wantPath) || mem || files.find(f => f.path === 'index.html') || files.find(f => !old(f)) || files[0];
      el.page.value = first.id;
      await open(first.id);
    } catch (e) { status(''); el.canvas.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }
  /** לשונית ליד ה-Navigator: 🌳 עץ / ＋ ספרייה (V4). */
  function navTabs(id) {
    if (!el.navTabs) return;
    for (const b of el.navTabs.children) b.setAttribute('aria-selected', String(b.dataset.ntab === id));
    el.navBody.hidden = id !== 'tree'; el.libBody.hidden = id !== 'lib'; if (el.compBody) el.compBody.hidden = id !== 'comp'; if (S.build?.lib) S.build.lib.hidden = id !== 'lib';
    if (el.tplBody) el.tplBody.hidden = id !== 'tpl'; if (el.histBody) el.histBody.hidden = id !== 'hist';
    if (id === 'tree') S.nav?.draw();
    if (id === 'hist') S.hist?.draw();
    if (id === 'tpl') S.tpl?.load().catch(e => note(e.message, true));
  }
  function unmount() { if (!S.host) return; S.touch?.unmount(); S.touch = null; /* V14 */ S.menu?.close(); S.palette?.close(); S.hist?.untrackAll(); S.comp?.unmount(); S.comp = null; S.build?.unmount(); S.build = null; S.pointing = false; S.codeOff?.dispose(); S.codeOff = null; S.code = null; close(); S.host = null; S.canvas = null; S.nav = null; S.info = null; S.text = null; S.content = null; }
  window.addEventListener('hashchange', () => { if (S.host && location.hash.split('/')[1] !== 'editor') unmount(); });

  return { view, unmount, selectAt, _head: () => S.head, _pages: () => S.pages, _openPage: openPage, _live: livePage, _glob: () => S.glob, _ctx: ctxMenu, _save: saveNow, _prefs: o => { Object.assign(prefs, o); if (o.device) S.device = o.device; keep(); }, _state: S, _undo: undo, _redo: redo, _remove: () => removeOne(), _removeWarn: remove, _sync: syncCanvas, _marks: saveMarks, _act: act, _tab: setTab, _pick: pick, _drop: dropFile, _insertImage: insertImage };
})();
