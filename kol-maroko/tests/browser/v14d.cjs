/**
 * פרוסה V14d — מה ש-V14 השאירה (handoff §7) — בדיקות בדפדפן   node kol-maroko/tests/browser/v14d.cjs
 *
 * כל מגע — CDP Input.dispatchTouchEvent (לא PointerEvent סינתטי): touch-action, click אחרי touchend ו-pointercancel מופיעים רק כך.
 *  1. שורת מקשים לקוד בטלפון (📁 פרויקט, Monaco): < Tab ↶ 🔎 — בלי לקחת את המיקוד מהעורך; צמודה מעל המקלדת (--vvh); במחשב — אין.
 *  2. Monaco לא נטען: תיבת טקסט עם אותה שורה, היסטוריה, שמירה ו-rev; "פתח בקוד" קופץ לשורה; וגם לשונית "קוד" של העורך החזותי.
 *  3. ✕ לביטול גרירה במגע — מופיע למעלה; שחרור עליו = ביטול, הקובץ לא משתנה; גרירה רגילה עדיין נכתבת.
 *  4. "100%" ל"מחשב" / "טאבלט" בטלפון — קנה מידה 1, גלילה לרוחב רק בתוך הקנבס; ובחירה בהקשה במצב הזה.
 *  5. coversOf (P2 ⚪) — סריקה אחת עד שהעמוד משתנה; שכבות העורך אינן מבטלות את התוצאה.
 *  6. התרחיש של 03 §7.3 — עשרת הצעדים ב-390, במגע.
 *  ו: החלקה על הקנבס = גלילה (V14 §2.2) — לא נשברה.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
const ONLY = (process.env.V14D_ONLY || '').split(',').filter(Boolean);
const want = k => !ONLY.length || ONLY.includes(String(k));
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 1200) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v14d-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  const errors = [];
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, touch = true) => browser.newContext({ viewport: { width, height }, hasTouch: touch, isMobile: touch, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const shotDir = path.join(os.tmpdir(), 'kol-v14d-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });
    const newPage = async (ctx, tag) => {
      const page = await ctx.newPage();
      page.on('pageerror', e => { errors.push(tag + ': ' + e.message); console.log('   ⚠️ שגיאה בדף (' + tag + ') אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 3).join(' | ').slice(0, 300)); });
      page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
      return page;
    };
    const ready = page => page.waitForFunction(() => Editor._state.file && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.touch, null, { timeout: 30000 });
    const openEd = async page => { await page.goto(base + '/editor.php#/editor/index.html'); await page.reload(); await ready(page); await page.waitForFunction(() => !Editor._state.lv || Editor._state.lv.lists.every(L => L.status !== 'loading'), null, { timeout: 30000 }); await sleep(600); };
    const flush = async page => { try { await page.evaluate(async () => { const S = Project._state; for (const [id, d] of S.docs) if (d.dirty) await Project._save(id); }); await page.waitForFunction(() => [...Project._state.docs.values()].every(d => !d.dirty && !d.saving), null, { timeout: 15000 }); } catch (e) { console.log('   ⚠️ flush: ' + e.message.slice(0, 200)); } };
    // ── מגע אמיתי (CDP) ──
    const cdpOf = async page => page.__cdp || (page.__cdp = await page.context().newCDPSession(page));
    const TP = (x, y) => [{ x: Math.round(x), y: Math.round(y), id: 1, radiusX: 4, radiusY: 4, force: 1 }];
    const ctap = async (page, p, hold = 40) => { const s = await cdpOf(page); await s.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: TP(p.x, p.y) }); await sleep(hold); await s.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(320); };
    /** גרירה באצבע. hold — לחיצה ארוכה לפני התזוזה; via — נקודות ביניים; during(i) — נקרא לפני השחרור. */
    const cdrag = async (page, from, to, opt = {}) => {
      const s = await cdpOf(page);
      await s.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: TP(from.x, from.y) });
      if (opt.hold) await sleep(opt.hold);
      const pts = [from, ...(opt.via || []), to]; let mid = null;
      for (let j = 1; j < pts.length; j++) {
        const a = pts[j - 1], b = pts[j], steps = opt.steps ?? 10;
        for (let i = 1; i <= steps; i++) { await s.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: TP(a.x + (b.x - a.x) * i / steps, a.y + (b.y - a.y) * i / steps) }); await sleep(opt.stepMs || 24); }
        if (opt.pause) await sleep(opt.pause);
      }
      if (opt.during) mid = await opt.during();
      await sleep(60);
      await s.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(450);
      return mid;
    };
    const rectOf = (page, sel) => page.evaluate(s => { const e = typeof s === 'string' ? document.querySelector(s) : null; if (!e || !e.getClientRects().length) return null; if (e.closest('[data-keys]')) e.scrollIntoView({ block: 'nearest', inline: 'nearest' }); const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, w: r.width, h: r.height, top: r.top, bottom: r.bottom, left: r.left, right: r.right }; }, sel);
    /** נקודה במסך (המעטפת) לאלמנט שבקנבס. */
    const at = (page, sel, fx = 0.5, fy = 0.5, scroll = true, i = 0) => page.evaluate(([s, fx, fy, sc, i]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return null; if (sc) e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, fx, fy, scroll, i]);
    const select = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s), n = e && c.nodeOf(e); c.select(n); return !!n; }, sel);
    const src = page => page.evaluate(() => Editor._state.doc.text());
    const files = page => page.evaluate(() => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); return o; });
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const undoFiles = async (page, to) => { for (let i = 0; i < 80 && !same(await files(page), to); i++) { await page.evaluate(() => Editor._undo()); await sleep(50); } return same(await files(page), to); };
    const note = page => page.evaluate(() => document.querySelector('.ed-note:not([hidden])')?.textContent || '');

    // ═══ הכנה: הפרויקט מהעמוד ═══
    {
      const ctx = await mk(1280, 900, false), page = await newPage(ctx, 'prep');
      await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
      await page.evaluate(() => api('pf_migrate', {}));
      await ctx.close();
    }
    const openProject = async (page, file, line) => {
      await page.goto(base + '/editor.php#/project'); await page.waitForSelector('.pj-tree .pj-row', { state: 'attached', timeout: 30000 });
      await page.evaluate(([f, l]) => Project.openAt(f, l), [file, line || 1]); await sleep(600);
    };

    // ═══ 1. שורת מקשים — Monaco (390) ═══
    if (want(1)) {
      const ctx = await mk(390, 844), page = await newPage(ctx, '1');
      await openProject(page, 'css/page.css', 1);
      await page.waitForSelector('.pj-ed .monaco-editor .view-lines', { timeout: 30000 }); await sleep(400);
      let st = await page.evaluate(() => { const k = document.querySelector('.pj-main [data-keys]'), bs = [...k.querySelectorAll('.pj-key')]; const r = k.getBoundingClientRect(), pj = document.querySelector('.pj').getBoundingClientRect();
        return { vis: !k.hidden && !!k.offsetParent, keys: bs.map(b => b.dataset.key), minH: Math.min(...bs.map(b => b.getBoundingClientRect().height)), minW: Math.min(...bs.map(b => b.getBoundingClientRect().width)), bottom: Math.round(r.bottom), pjBottom: Math.round(pj.bottom), ih: innerHeight, hscroll: document.documentElement.scrollWidth > innerWidth }; });
      ok(st.vis && ['<', '>', '/', '=', '"', '{', '}', ';', 'Tab', 'undo', 'find'].every(k => st.keys.includes(k)) && st.minH >= 44 && st.minW >= 44 && st.bottom <= st.ih && !st.hscroll,
        '390 · 📁 קוד: שורת מקשים מתחת לעורך (< > / = " { } ; Tab ↶ 🔎), כל מקש ≥44px, בתוך המסך, בלי גלילה אופקית של הדף', st);
      await page.evaluate(() => { const ed = Project._state.editor; ed.setPosition({ lineNumber: 1, column: 1 }); ed.focus(); });
      const line1 = () => page.evaluate(() => Project._state.editor.getModel().getLineContent(1));
      const l0 = await line1();
      await ctap(page, await rectOf(page, '.pj-main [data-key="<"]'));
      st = await page.evaluate(() => ({ focus: !!document.activeElement?.closest('.monaco-editor'), pos: Project._state.editor.getPosition().column }));
      const l1 = await line1();
      ok(l1 === '<' + l0 && st.focus && st.pos === 2, 'הקשה על "<" — נכנס במקום הסמן, הסמן אחריו, והמיקוד נשאר בעורך (המקלדת לא נסגרת)', { l0, l1, st });
      await ctap(page, await rectOf(page, '.pj-main [data-key="undo"]'));
      ok(await line1() === l0, '↶ — מבטל את ההקלדה', await line1());
      await ctap(page, await rectOf(page, '.pj-main [data-key="Tab"]'));
      const l2 = await line1();
      ok(/^\s+/.test(l2) && l2.trim() === l0.trim(), 'Tab — הזחה', l2);
      await ctap(page, await rectOf(page, '.pj-main [data-key="undo"]'));
      await ctap(page, await rectOf(page, '.pj-main [data-key="find"]'));
      ok(await page.evaluate(() => !!document.querySelector('.find-widget.visible')) && await line1() === l0, '🔎 — תיבת החיפוש של העורך נפתחת', await line1());
      await page.keyboard.press('Escape');
      // מקלדת וירטואלית: .pj בגובה --vvh — השורה עולה איתה
      await page.evaluate(() => document.documentElement.style.setProperty('--vvh', '480px')); await sleep(300);
      st = await page.evaluate(() => { const r = document.querySelector('.pj-main [data-keys]').getBoundingClientRect(); return { bottom: Math.round(r.bottom), top: Math.round(r.top), ed: Math.round(document.querySelector('.pj-ed').getBoundingClientRect().height) }; });
      ok(st.bottom <= 480 && st.ed > 100, 'מקלדת פתוחה (--vvh = 480): שורת המקשים צמודה מעליה, והעורך מתקצר', st);
      await page.evaluate(() => document.documentElement.style.removeProperty('--vvh'));
      await shot(page, 'v14d-1-keys');
      ok(!(await page.evaluate(() => Project._state.docs.get(Project._state.cur).dirty)), 'אחרי ↶ — הקובץ לא שונה');
      await ctx.close();
      // במחשב — אין שורה
      const c2 = await mk(1280, 900, false), p2 = await newPage(c2, '1-desk');
      await openProject(p2, 'css/page.css', 1); await p2.waitForSelector('.pj-ed .monaco-editor .view-lines', { timeout: 30000 });
      st = await p2.evaluate(() => { const k = document.querySelector('.pj-main [data-keys]'); return { el: !!k, shown: !!(k && k.offsetParent) }; });
      ok(st.el && !st.shown, 'במחשב (1280, עכבר) — שורת המקשים אינה מוצגת', st);
      await c2.close();
    }

    // ═══ 2. Monaco לא נטען — תיבת טקסט, אותה שורה, היסטוריה, שמירה; ובלשונית "קוד" של העורך ═══
    if (want(2)) {
      const ctx = await mk(390, 844), page = await newPage(ctx, '2');
      await page.route('**/monaco/vs/loader.js', r => r.abort());
      await openProject(page, 'css/page.css', 3);
      await page.waitForSelector('.pj-view textarea.pj-fallback', { timeout: 30000 }); await sleep(300);
      let st = await page.evaluate(() => { const ta = document.querySelector('.pj-fallback'), k = document.querySelector('.pj-main [data-keys]'), t = ta.value, l3 = t.split('\n').slice(0, 2).join('\n').length + 1;
        return { keys: !k.hidden && !!k.offsetParent, focus: document.activeElement === ta, sel: ta.selectionStart, l3, monaco: !!window.monaco }; });
      ok(!st.monaco && st.keys && st.focus && st.sel === st.l3, 'בלי Monaco: תיבת טקסט, שורת המקשים מוצגת, ו"פתח בקוד" (שורה 3) — הסמן בתחילת שורה 3', st);
      const val = () => page.evaluate(() => document.querySelector('.pj-fallback').value);
      const v0 = await val();
      await ctap(page, await rectOf(page, '.pj-main [data-key="<"]'));
      const v1 = await val();
      const f1 = await page.evaluate(() => ({ focus: document.activeElement === document.querySelector('.pj-fallback'), sel: document.querySelector('.pj-fallback').selectionStart }));
      ok(v1 === v0.slice(0, st.l3) + '<' + v0.slice(st.l3) && f1.focus && f1.sel === st.l3 + 1, 'תיבת טקסט: "<" נכנס במקום הסמן, והמיקוד נשאר בתיבה', { f1, at: v1.slice(st.l3 - 5, st.l3 + 5), was: v0.slice(st.l3 - 5, st.l3 + 5) });
      await page.keyboard.type('ab'); await sleep(100);
      await ctap(page, await rectOf(page, '.pj-main [data-key="undo"]'));
      const v2 = await val();
      await ctap(page, await rectOf(page, '.pj-main [data-key="undo"]'));
      const v3 = await val();
      await ctap(page, await rectOf(page, '.pj-main [data-key="redo"]'));
      const v4 = await val();
      ok(v2 === v1 && v3 === v0 && v4 === v1, 'תיבת טקסט: ↶ מבטל את ההקלדה (צעד אחד), ↶ נוסף — את "<", ↷ — מחזיר', { v2: v2 === v1, v3: v3 === v0, v4: v4 === v1 });
      await ctap(page, await rectOf(page, '.pj-main [data-key="Tab"]'));
      ok((await val()).slice(st.l3 + 1, st.l3 + 3) === '  ', 'תיבת טקסט: Tab — שני רווחים');
      await page.keyboard.press('Control+z'); await sleep(100);
      ok(await val() === v1, 'Ctrl+Z בתיבת הטקסט — אותה היסטוריה (לא של הדפדפן)');
      // שמירה — אותו rev, אותה פעולה
      const r = await page.evaluate(async () => { const P = Project._state, id = P.cur, d = P.docs.get(id), rev0 = d.rev; await Project._save(id); const s = await api('pf_read', undefined, '&id=' + id); return { rev0, rev1: d.rev, dirty: d.dirty, same: s.content === document.querySelector('.pj-fallback').value, srv: s.file.rev }; });
      ok(r.rev1 > r.rev0 && !r.dirty && r.same && r.srv === r.rev1, 'תיבת טקסט: 💾 נשמר לשרת (pf_write) — rev עולה, התוכן זהה', r);
      await ctap(page, await rectOf(page, '.pj-main [data-key="undo"]')); await page.evaluate(async () => Project._save(Project._state.cur)); await sleep(200);
      ok(await val() === v0, 'ביטול ושמירה — חזרה לקובץ המקורי');
      // לשונית "קוד" של העורך החזותי — בלי Monaco
      await page.goto(base + '/editor.php#/editor/index.html'); await ready(page); await sleep(500);
      await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('split')) document.querySelector('[data-split]').click(); });
      await sleep(300);
      await ctap(page, await rectOf(page, '.ed-tabs [data-tab="code"]'));
      st = await page.evaluate(() => { const ta = document.querySelector('.ed-code textarea.pj-split'), k = document.querySelector('.ed-code [data-keys]'); return { ta: !!(ta && ta.offsetParent), h: ta ? Math.round(ta.getBoundingClientRect().height) : 0, keys: !!(k && !k.hidden && k.offsetParent), err: /לא נטען/.test(document.querySelector('.ed-code').textContent) }; });
      ok(st.ta && st.h > 200 && st.keys && !st.err, 'עורך חזותי בלי Monaco — לשונית "קוד": תיבת טקסט על המסך, עם שורת המקשים (לא "עורך הקוד לא נטען")', st);
      // בחירה בקנבס ← הטווח בקוד; הקלדה בקוד ← הקנבס
      await ctap(page, await rectOf(page, '.ed-tabs [data-tab="canvas"]'));
      await select(page, 'h1.kadisha-hero-title'); await sleep(200);
      await ctap(page, await rectOf(page, '.ed-tabs [data-tab="code"]'));
      st = await page.evaluate(() => { const ta = document.querySelector('.ed-code textarea.pj-split'); return ta.value.slice(ta.selectionStart, ta.selectionEnd).slice(0, 120); });
      ok(/^<h1 [^>]*kadisha-hero-title/.test(st), 'בחירה בקנבס ← הטווח של האלמנט מסומן בתיבת הטקסט', st);
      const t0 = await src(page);
      await page.evaluate(() => { const ta = document.querySelector('.ed-code textarea.pj-split'), i = ta.value.indexOf('La Voix du Maroc'); ta.focus(); ta.setSelectionRange(i, i + 2); });
      await page.keyboard.type('Le'); await sleep(900);
      const eb = await page.evaluate(() => Editor._state.canvas.doc.querySelector('.kadisha-hero-eyebrow-line')?.textContent || '');
      ok(/^Le Voix du Maroc/.test(eb) && (await src(page)).includes('Le Voix du Maroc'), 'הקלדה בתיבת הטקסט ← הקנבס מתעדכן (אותו מסמך)', eb);
      await ctap(page, await rectOf(page, '.ed-code [data-key="undo"]')); await sleep(500);
      ok(await src(page) === t0, '↶ בשורת המקשים של לשונית הקוד — הקובץ חוזר', (await src(page)).includes('La Voix'));
      await flush(page);
      await shot(page, 'v14d-2-fallback');
      await ctx.close();
    }

    // ═══ 3–4 ו-ו: העורך ב-390 ═══
    if (want(3) || want(4) || want(5) || want('ו')) {
      const ctx = await mk(390, 844), page = await newPage(ctx, '390');
      await openEd(page);
      const o0 = await files(page);

      if (want(3)) {
        // ✕ — גרירה במגע אל ✕ ← ביטול
        const from = await at(page, '.kadisha-hero-eyebrow-line'), tx0 = await page.evaluate(() => Editor._state.tx.length);
        const mid = await cdrag(page, from, { x: from.x, y: from.y + 60 }, { hold: 550, steps: 6, during: async () => {
          const x = await rectOf(page, '[data-ts-dragx]'); if (!x) return { x: null };
          const s = await cdpOf(page);
          for (let i = 1; i <= 10; i++) { await s.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: TP(from.x + (x.x - from.x) * i / 10, from.y + 60 + (x.y - from.y - 60) * i / 10) }); await sleep(30); }
          await sleep(150);
          return page.evaluate(() => { const X = document.querySelector('[data-ts-dragx]'), r = X.getBoundingClientRect(); return { x: !X.hidden, on: X.classList.contains('on'), top: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height), active: !!Editor._state.build.drag.active, line: !document.querySelector('.dg-line').hidden }; });
        } });
        const after = await page.evaluate(() => ({ x: !document.querySelector('[data-ts-dragx]').hidden, active: !!Editor._state.build.drag.active, tx: Editor._state.tx.length }));
        ok(mid && mid.x && mid.active && mid.top < 80 && mid.h >= 44, 'גרירה במגע: ✕ מופיע למעלה בזמן הגרירה (≥44px)', mid);
        ok(mid && mid.on && !mid.line, 'האצבע מעל ✕ — הוא מודגש, ואין קו יעד', mid);
        ok(same(await files(page), o0) && after.tx === tx0 && !after.active && !after.x && /בוטלה/.test(await note(page)), 'שחרור על ✕ — הגרירה בוטלה: הקובץ לא השתנה, אין צעד בהיסטוריה, ✕ נעלם', { after, tx0, note: await note(page) });
        // ובלי ✕ — אותה גרירה נכתבת
        { const f2 = await at(page, '.kadisha-hero-eyebrow-line'), to = await at(page, 'h1.kadisha-hero-title', 0.5, 0.15, false);
          await cdrag(page, f2, to, { hold: 550, steps: 10 }); }
        ok(/<span class="kadisha-hero-eyebrow-line">La Voix du Maroc<\/span>\n      <h1 /.test(await src(page)), 'גרירה רגילה במגע (לא אל ✕) — עדיין נכתבת');
        ok(await undoFiles(page, o0), 'ביטול — זהים');
        // גרירה בעכבר — בלי ✕ (Esc מבטל)
        const xm = await page.evaluate(() => { const S = Editor._state, D = S.build.drag, n = S.canvas.nodeOf(S.canvas.doc.querySelector('h1')); S.touch.lastPT = 'mouse'; D.start({ nodes: [n] }, 100, 300); const x = !document.querySelector('[data-ts-dragx]').hidden; D.cancel(); return x; });
        ok(!xm, 'גרירה שהתחילה בעכבר — ✕ אינו מופיע');
        // החלקה על הקנבס = גלילה (לא נשבר)
        await page.evaluate(() => { Editor._state.canvas.select(null); Editor._state.canvas.frame.contentWindow.scrollTo(0, 0); }); await sleep(200);
        const p0 = await at(page, 'h1.kadisha-hero-title', 0.5, 0.5, false), y0 = await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollY);
        await cdrag(page, p0, { x: p0.x, y: p0.y - 220 }, { steps: 8, stepMs: 16 });
        const y1 = await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollY);
        ok(y1 > y0 + 80 && same(await files(page), o0) && !(await page.evaluate(() => !!Editor._state.build.drag.active)), 'החלקה על הקנבס (בלי לחיצה ארוכה) — גלילה, לא גרירה', { y0, y1 });
      }

      if (want(4)) {
        // "100%" — מחשב וטאבלט בטלפון
        await page.evaluate(() => document.querySelector('.ed-dev [data-dev="desktop"]').click()); await sleep(700);
        let st = await page.evaluate(() => { const c = Editor._state.canvas, b = document.querySelector('[data-ts-one]'); return { one: !!b && !b.hidden && !!b.offsetParent, scale: c.scale, h: b ? Math.round(b.getBoundingClientRect().height) : 0 }; });
        ok(st.one && st.scale < 0.5 && st.h >= 44, '390 · "מחשב": מוקטן להתאמה, וכפתור "100%" מוצג (≥44px)', st);
        await ctap(page, await rectOf(page, '[data-ts-one]')); await sleep(400);
        st = await page.evaluate(() => { const c = Editor._state.canvas, h = c.host; return { scale: c.scale, sw: h.scrollWidth, cw: h.clientWidth, page: document.documentElement.scrollWidth - innerWidth, fit: document.querySelector('[data-ts-fit]').textContent, fitShown: !document.querySelector('[data-ts-fit]').hidden, one: !document.querySelector('[data-ts-one]').hidden }; });
        ok(Math.abs(st.scale - 1) < 0.01 && st.sw > st.cw + 500 && st.page <= 0 && st.fitShown && /100%/.test(st.fit) && !st.one, '"100%" — קנה מידה 1: גלילה לרוחב בתוך הקנבס בלבד (הדף אינו גולל לצדדים), ו"⤢ התאם · 100%"', st);
        st = await page.evaluate(async () => { const h = Editor._state.canvas.host, a = h.scrollLeft; h.scrollLeft = -1e5; await new Promise(r => setTimeout(r, 60)); const b = h.scrollLeft; h.scrollLeft = 1e5; await new Promise(r => setTimeout(r, 60)); const c = h.scrollLeft; return { a, b, c, span: Math.abs(c - b), need: h.scrollWidth - h.clientWidth }; });
        ok(st.span >= st.need - 2 && st.need > 500, 'בתוך הקנבס — אפשר להגיע לשני הקצוות של העמוד ברוחב 1280', st);
        // הקשה בוחרת במצב הזה
        const hit = await page.evaluate(() => { const c = Editor._state.canvas, hr = c.host.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); for (const fy of [0.35, 0.5, 0.65]) { const x = hr.left + hr.width / 2, y = hr.top + hr.height * fy, e = c.doc.elementFromPoint((x - f.left) / c.scale, (y - f.top) / c.scale), n = e && c.nodeOf(e); if (n && !['html', 'body'].includes(n.tag)) return { x, y, label: c.label(n) }; } return null; });
        if (hit) await ctap(page, hit); await sleep(500);
        const sel = await page.evaluate(() => { const c = Editor._state.canvas; return c.selected ? c.label(c.selected) : null; });
        const zs = await page.evaluate(() => ({ scale: Editor._state.canvas.scale, fit: !document.querySelector('[data-ts-fit]').hidden }));
        ok(hit && sel && sel === hit.label && Math.abs(zs.scale - 1) < 0.01 && zs.fit, 'ב-100% — הקשה על אלמנט בוחרת אותו, והקנבס נשאר ב-100% (הגיליון שעלה אינו מאפס)', { hit, sel, zs });
        await shot(page, 'v14d-4-desktop-100');
        await ctap(page, await rectOf(page, '[data-ts-fit]')); await sleep(400);
        st = await page.evaluate(() => ({ scale: Editor._state.canvas.scale, one: !document.querySelector('[data-ts-one]').hidden }));
        ok(st.scale < 0.5 && st.one, '"⤢ התאם" — חזרה להקטנה, ו"100%" שוב מוצג', st);
        await page.evaluate(() => document.querySelector('.ed-dev [data-dev="tablet"]').click()); await sleep(700);
        st = await page.evaluate(() => ({ scale: Editor._state.canvas.scale, one: !document.querySelector('[data-ts-one]').hidden }));
        await ctap(page, await rectOf(page, '[data-ts-one]')); await sleep(400);
        const st2 = await page.evaluate(() => ({ scale: Editor._state.canvas.scale, page: document.documentElement.scrollWidth - innerWidth }));
        ok(st.one && st.scale < 0.6 && Math.abs(st2.scale - 1) < 0.01 && st2.page <= 0, '"טאבלט": "100%" — קנה מידה 1, בלי גלילה של הדף', { st, st2 });
        await page.evaluate(() => document.querySelector('.ed-dev [data-dev="mobile"]').click()); await sleep(700);
        st = await page.evaluate(() => ({ scale: Editor._state.canvas.scale, one: !document.querySelector('[data-ts-one]').hidden, fit: !document.querySelector('[data-ts-fit]').hidden, real: Editor._state.canvas.realW }));
        ok(!st.one && !st.fit && st.real, '"טלפון": ברוחב האמיתי — בלי "100%"', st);
        ok(same(await files(page), o0), 'מעבר בין מכשירים ו-100% — הקבצים לא השתנו');
      }

      if (want(5)) {
        // coversOf — מטמון עד שהעמוד משתנה
        const r = await page.evaluate(() => {
          const S = Editor._state, c = S.canvas, P = S.pos, d = c.doc, box = d.createElement('div');
          box.style.cssText = 'position:absolute;left:0;top:0;width:100%;height:' + (d.querySelector('h1').getBoundingClientRect().bottom + d.defaultView.scrollY + 20) + 'px;background:rgba(255,0,0,.4);z-index:50';
          d.body.append(box);
          const s0 = P.covScans || 0, a = P.coversOf(box), s1 = P.covScans, b = P.coversOf(box), s2 = P.covScans;
          c.draw(); S.build.draw();
          return new Promise(res => setTimeout(() => {
            const c3 = P.coversOf(box), s3 = P.covScans;
            d.querySelector('h1').append(d.createTextNode(' '));
            setTimeout(() => { const e = P.coversOf(box), s4 = P.covScans; box.remove(); d.querySelector('h1').lastChild.remove(); res({ n: a.length, s0, s1, s2, s3, s4, sameArr: a === b && b === c3, eLen: e.length }); }, 30);
          }, 30));
        });
        ok(r.n >= 1 && r.s1 === r.s0 + 1 && r.s2 === r.s1 && r.sameArr, 'coversOf: סריקה אחת; ציור חוזר של הפאנל — מהמטמון', r);
        ok(r.s3 === r.s2, 'ציור השכבות של העורך (מסגרות, ידיות) — אינו מבטל את המטמון', r);
        ok(r.s4 === r.s3 + 1 && r.eLen >= 1, 'שינוי בעמוד עצמו — סריקה חדשה', r);
      }
      ok(same(await files(page), o0), 'העורך ב-390 — הקבצים זהים למצב ההתחלה');
      await ctx.close();
    }

    // ═══ 6. התרחיש של 03 §7.3 — ב-390, במגע ═══
    if (want(6)) {
      const ctx = await mk(390, 844), page = await newPage(ctx, '7.3');
      await openEd(page);
      const o0 = await files(page);
      const HERO = 'section.kadisha-hero', H1 = 'h1.kadisha-hero-title';
      const comp = (p, sel = HERO) => page.evaluate(([s, p]) => getComputedStyle(Editor._state.canvas.doc.querySelector(s)).getPropertyValue(p), [sel, p]);
      const rect = s => page.evaluate(s => { const r = Editor._state.canvas.doc.querySelector(s).getBoundingClientRect(); return { l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height }; }, s);
      const height = () => page.evaluate(s => Editor._state.canvas.doc.querySelector(s).getBoundingClientRect().height, HERO);
      const label = () => page.evaluate(() => { const c = Editor._state.canvas; return c.selected ? c.elOf(c.selected) : null; });
      /** הקשה (CDP) על כפתור במעטפת. */
      const tapSel = async sel => { const r = await page.evaluate(s => { const e = [...document.querySelectorAll(s)].find(x => x.getClientRects().length); if (!e) return null; e.scrollIntoView({ block: 'nearest', inline: 'nearest' }); const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; }, sel); if (!r) return false; await ctap(page, r); return true; };
      /** הקשה (CDP) על אלמנט בקנבס — במרכז הנראה שלו, בחלק של הקנבס שאינו מכוסה בגיליון. */
      const tapIn = async (sel, i = 0) => {
        const p = await page.evaluate(([s, i]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return null; e.scrollIntoView({ block: 'center' });
          const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(), x = f.left + (r.left + r.width / 2) * c.scale, y = f.top + (r.top + r.height / 2) * c.scale;
          const top = document.elementFromPoint(x, y); return { x, y, ok: top === c.frame }; }, [sel, i]);
        if (!p) return false;
        if (await page.evaluate(([s, i]) => { const c = Editor._state.canvas; return !!c.selected && c.elOf(c.selected) === c.doc.querySelectorAll(s)[i]; }, [sel, i])) return true;   // כבר נבחר (הקשה עליו — עולה להורה)
        await ctap(page, p); await sleep(250);                                // מעבר לחלון ההקשה הכפולה (380ms)
        return page.evaluate(([s, i]) => { const c = Editor._state.canvas; return !!c.selected && c.elOf(c.selected) === c.doc.querySelectorAll(s)[i]; }, [sel, i]);
      };
      const panel = async (id, tab) => { await page.evaluate(([id, tab]) => { if (!document.querySelector('.ed').classList.contains(id + '-open')) document.querySelector('.ed-bar [data-panel="' + id + '"]').click(); if (tab) Editor._tab(tab); }, [id, tab]); await sleep(250); };
      const fld = (id, phys) => '.ed-side-body.sg[aria-label="' + (id === 'adv' ? 'מתקדם' : 'עיצוב') + '"] [data-field="' + phys + '"]';
      const typeIn = async (id, phys, v) => { const q = fld(id, phys) + ' .sg-ctl input'; await page.tap(q); await page.fill(q, v); await page.press(q, 'Enter'); await sleep(300); };

      // צעד 1 — הקשה על כל אחת משש הכותרות שעל התמונה
      const LEAVES = '.kadisha-hero-inner :is(h1, p, span):not(:has(*)):not(:empty):not(:is(a, button) *)';
      const n6 = await page.evaluate(q => Editor._state.canvas.doc.querySelectorAll(q).length, LEAVES); let hit = 0;
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(450);
      for (let i = 0; i < n6; i++) if (await tapIn(LEAVES, i)) hit++;
      ok(n6 === 6 && hit === 6, '7.3 · 390 · צעד 1: הקשה באצבע על כל אחת משש הכותרות — כל אחת נבחרת בנפרד', { n6, hit });
      // צעד 2 — לחיצה ארוכה וגרירה: הצרפתית מעל העברית
      { const from = await at(page, '.kadisha-hero-eyebrow-line'), to = await at(page, H1, 0.5, 0.15, false); await cdrag(page, from, to, { hold: 550, steps: 10 }); }
      let sc = await src(page);
      ok(/<div class="kadisha-hero-inner">\n      <span class="kadisha-hero-eyebrow-line">La Voix du Maroc<\/span>\n      <h1 /.test(sc), '7.3 · 390 · צעד 2: לחיצה ארוכה וגרירה באצבע — הכותרת בצרפתית לפני הכותרת בעברית');
      // צעד 3 — כותרת שביעית מהספרייה (בגיליון), מקלידים בה, גודל וצבע
      await tapIn('p.kadisha-hero-subtitle-main');
      await panel('nav'); await tapSel('[data-ntab="lib"]'); await sleep(200);
      await tapSel('.ed-lib-item[data-lib="heading"]'); await sleep(400);
      await page.keyboard.type('הכותרת השביעית'); await page.keyboard.press('Escape'); await sleep(400);
      const NEWSEL = await page.evaluate(() => { const all = [...Editor._state.canvas.doc.querySelectorAll('.kadisha-hero-inner > *')], i = all.findIndex(x => x.textContent.trim() === 'הכותרת השביעית'); return i < 0 ? null : '.kadisha-hero-inner > :nth-child(' + (i + 1) + ')'; });
      await page.evaluate(() => { const b = document.querySelector('.ed-nav .ed-side-head .icon-btn'); if (document.querySelector('.ed').classList.contains('nav-open') && b) b.click(); }); await sleep(250);
      const t3 = NEWSEL && await tapIn(NEWSEL);
      await panel('info', 'design');
      // היעד — ברירת המחדל (★ מחלקה חדשה): במצב "טלפון" נכתב ל-@media של הטלפון (ל-style="" אין @media)
      if (NEWSEL) { await typeIn('design', 'font-size', '40px'); await typeIn('design', 'color', '#ffcc00'); }
      ok(NEWSEL && t3 && await comp('font-size', NEWSEL) === '40px' && await comp('color', NEWSEL) === 'rgb(255, 204, 0)', '7.3 · 390 · צעד 3: כותרת שביעית מהספרייה בגיליון — מקלידים בה במקום, גודל 40px וצבע', { NEWSEL, t3 });
      const newFs = () => page.evaluate(() => { const e = [...Editor._state.canvas.doc.querySelectorAll('*')].find(x => x.children.length === 0 && x.textContent.trim() === 'הכותרת השביעית'); return e ? getComputedStyle(e).fontSize : null; });
      // צעד 4 — ידית המרווח באצבע עד 0, ואחר כך 24
      await panel('info', 'adv'); await page.evaluate(() => Editor._state.touch.set('row')); await sleep(300);
      await tapIn(H1); await sleep(500);
      const gdist = () => page.evaluate(() => { const d = Editor._state.canvas.doc, a = d.querySelector('h1.kadisha-hero-title'), b = a.nextElementSibling; return Math.round((b.getBoundingClientRect().top - a.getBoundingClientRect().bottom) * 100) / 100; });
      const gH = () => page.evaluate(() => { const b = document.querySelector('[data-sp-gap]'); if (!b || b.hidden || !b.getClientRects().length) return null; const r = b.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2; return { x, y, top: document.elementFromPoint(x, y) === b || b.contains(document.elementFromPoint(x, y)) }; });
      const scl = await page.evaluate(() => Editor._state.canvas.scale);
      { const g = await gH(), d0 = await gdist(); if (g) await cdrag(page, g, { x: g.x, y: g.y - d0 * scl }, { steps: 12 }); }
      const z4 = await gdist();
      { const g = await gH(); if (g) await ctap(page, g); await sleep(200); }
      if (await page.evaluate(() => !!document.querySelector('[data-gapbar]:not([hidden]) [data-bar-input]'))) { await page.fill('[data-gapbar] [data-bar-input]', '24'); await page.press('[data-gapbar] [data-bar-input]', 'Enter'); await sleep(300); }
      ok(z4 === 0 && await gdist() === 24, '7.3 · 390 · צעד 4: ידית המרווח באצבע עד 0, ואחר כך מקלידים 24', { z4, d: await gdist() });
      // צעד 5 — מיקום חופשי ביחס אל הפס, ולפינה בגרירת ✥
      await page.evaluate(() => Editor._state.touch.set('half')); await panel('info', 'adv');
      await tapIn('.kadisha-hero-credit'); await sleep(300);
      await page.evaluate(() => { const s = document.querySelector('[data-free-ref]'); if (!s) return; const i = [...s.options].findIndex(o => /^section\.kadisha-hero/.test(o.textContent)); s.selectedIndex = i; s.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(120);
      await tapSel('[data-free-btn]'); await sleep(400);
      await page.evaluate(() => Editor._state.touch.set('row')); await sleep(300);
      await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('section.kadisha-hero'); e.scrollIntoView({ block: 'end' }); c.draw(); }); await sleep(300);
      { const r = await rect('.kadisha-hero-credit'), s5 = await rect(HERO), b = await page.evaluate(() => { const m = document.querySelector('[data-ps-move]'); if (!m || m.hidden) return null; const r = m.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top - 24 }; });
        const sc5 = await page.evaluate(() => Editor._state.canvas.scale);
        if (b) await cdrag(page, b, { x: b.x + (s5.l + 24 - r.l) * sc5, y: b.y + (s5.b - 24 - r.b) * sc5 }, { steps: 12 }); }
      { const css = ((await files(page))['css/page.css'].match(/[^}]*kadisha-hero-credit[^{]*\{[^}]*\}/g) || []).join('\n'), par5 = await page.evaluate(() => Editor._state.canvas.doc.querySelector('.kadisha-hero-credit').parentElement.className);
        const r = await rect('.kadisha-hero-credit'), s5 = await rect(HERO);
        ok(/kadisha-hero\b/.test(par5) && !/inner/.test(par5) && /inset-block-end: [\d.]+%/.test(css) && s5.b - r.b < 40 && r.l - s5.l < 40, '7.3 · 390 · צעד 5: "✥ מיקום חופשי" ביחס אל הפס, וגרירת ✥ באצבע לפינה התחתונה', { par5, css, r, s5 }); }
      // צעד 6 — מסירים את התמונה
      await page.evaluate(() => Editor._state.touch.set('half')); await panel('info', 'design');
      await select(page, HERO); await sleep(250); const hh6 = await height();
      await tapSel('[data-bgt="remove"]'); await sleep(300);
      ok(await comp('background-image') === 'none' && Math.abs(await height() - hh6) < 1, '7.3 · 390 · צעד 6: 🗑 בהקשה — התמונה הוסרה, הפס נשאר בגובהו');
      // צעד 7 — תמונה אחרת (העלאה), מיקום וגודל
      await tapSel('[data-bg-add]'); await page.waitForSelector('#sheet[open] [data-pick-file]', { state: 'attached', timeout: 10000 });
      await page.setInputFiles('#sheet[open] [data-pick-file]', { name: 'other-hero.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', 'base64') }); await sleep(1000);
      for (const [p7, v7] of [['background-position', '30% 40%'], ['background-size', '80% auto']]) { const q = '[data-field="' + p7 + '"] .sg-ctl input'; if (await page.$(q)) { await page.fill(q, v7); await page.press(q, 'Enter'); await sleep(300); } }
      { const css = (await files(page))['css/page.css'];
        ok(/background-image: url\('\.\.\/media\/other-hero\.png'\);/.test(css) && await comp('background-position') === '30% 40%' && /80%/.test(await comp('background-size')), '7.3 · 390 · צעד 7: תמונה אחרת (העלאה), מיקום 30% 40% וגודל 80%', { pos: await comp('background-position'), size: await comp('background-size') }); }
      // צעד 8 — הפס כולו אל מתחת לשורת החיפוש: ⠿ בסרגל הנבחר, גרירה באצבע
      await page.evaluate(() => { const b = document.querySelector('.ed-info .ed-side-head .icon-btn'); if (document.querySelector('.ed').classList.contains('info-open') && b) b.click(); }); await sleep(300);
      await select(page, HERO); await sleep(600);
      { const to = await at(page, '.kadisha-lexicon-controls', 0.5, 1, true); await sleep(150);
        const to2 = await at(page, '.kadisha-lexicon-controls', 0.5, 1, false), g = await rectOf(page, '.ed-seltools [data-grip]');
        if (g && to2) await cdrag(page, g, { x: to2.x, y: to2.y - 3 }, { steps: 12, via: [{ x: to2.x + 40, y: to2.y - 20 }] }); void to; }
      sc = await src(page);
      ok(sc.indexOf('kadisha-lexicon-controls') < sc.indexOf('<section class="kadisha-hero ') && await page.evaluate(() => Editor._state.canvas.inequiv.length) === 0, '7.3 · 390 · צעד 8: ⠿ וגרירה באצבע — הפס כולו מתחת לשורת החיפוש');
      // צעד 9 — כל שינוי נראה בקבצים; שינוי מספר בלשונית "קוד" ← הקנבס
      const f9 = await files(page), lines = (a, b) => { const A = new Set(a.split('\n')); return b.split('\n').filter(l => !A.has(l)); };
      const dh = lines(o0['index.html'], f9['index.html']), dc = lines(o0['css/page.css'], f9['css/page.css']);
      const seen = { 2: dh.some(l => /La Voix du Maroc/.test(l)), 3: dh.some(l => /הכותרת השביעית/.test(l)) && dc.concat(dh).some(l => /font-size: 40px/.test(l)) && dc.concat(dh).some(l => /#ffcc00/.test(l)), 4: dc.some(l => /24px/.test(l)), 5: dc.some(l => /inset-block-end/.test(l)), 6: !/background-image: url\('[^']*'\)/.test(f9['css/page.css'].replace(/url\('\.\.\/media\/other-hero\.png'\)/, '')), 7: dc.some(l => /other-hero\.png/.test(l)) && dc.some(l => /30% 40%/.test(l)) && dc.some(l => /80% auto/.test(l)), 8: dh.some(l => /<section class="kadisha-hero /.test(l)) };
      ok(Object.values(seen).every(Boolean), '7.3 · 390 · צעד 9: כל אחד מהשינויים (2–8) נראה בקבצים כשורה שהשתנתה', { seen });
      await tapSel('[data-split]'); await sleep(300); await tapSel('.ed-tabs [data-tab="code"]');
      await page.waitForSelector('.ed-code .monaco-editor .view-lines', { timeout: 20000 }); await sleep(300);
      const where = f9['css/page.css'].includes('font-size: 40px') ? 'css/page.css' : 'index.html';
      await page.evaluate(w => { const P = Project._state, S = Editor._state; const id = Project.docs.byPath(w)?.id; if (w !== S.file.path) Project.splitEditor(document.querySelector('.ed-code'), id); const ed = P.split, m = ed.getModel(), i = m.getValue().indexOf('font-size: 40px') + 'font-size: '.length; if (i < 11) throw new Error('40px לא נמצא ב-' + w); ed.focus(); ed.setSelection(monaco.Selection.fromPositions(m.getPositionAt(i), m.getPositionAt(i + 2))); ed.revealPositionInCenter(m.getPositionAt(i)); }, where);
      await page.keyboard.type('52'); await sleep(800);
      const keysCode = await page.evaluate(() => { const k = document.querySelector('.ed-code [data-keys]'); return !!(k && !k.hidden && k.offsetParent); });
      ok(await newFs() === '52px' && keysCode, '7.3 · 390 · צעד 9: בלשונית "קוד" (' + where + ', עם שורת המקשים) מקלידים 52 במקום 40 — והקנבס מתעדכן', { fs: await newFs(), keysCode });
      await tapSel('.ed-code [data-key="undo"]'); await sleep(600);
      ok(await newFs() === '40px', '7.3 · 390 · ↶ בשורת המקשים — חזרה ל-40', await newFs());
      await tapSel('.ed-tabs [data-tab="canvas"]'); await sleep(300);
      await page.screenshot({ path: path.join(shotDir, 'v14d-6-scenario.png') });
      // צעד 10 — ↶ בסרגל, באצבע, עד ההתחלה
      let undos = 0;
      for (; undos < 40 && !same(await files(page), o0); undos++) { if (!(await tapSel('.ed-bar button[aria-label^="בטל"]:not([disabled])'))) break; await sleep(80); }
      ok(same(await files(page), o0) && await page.evaluate(() => Editor._state.canvas.inequiv.length) === 0, '7.3 · 390 · צעד 10: ↶ בהקשה ×' + undos + ' — כל הקבצים זהים בייט בבייט למצב ההתחלה', undos);
      await flush(page);
      await ctx.close();
    }

    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
