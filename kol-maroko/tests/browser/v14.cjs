/**
 * פרוסה V14 — הכול גם בטלפון (ובטאבלט) — בדיקות בדפדפן   node kol-maroko/tests/browser/v14.cjs
 *
 * Playwright עם hasTouch + isMobile (390×844, 360×740, 768×1024, רוחב 844×390), ואירועי מגע (tap אמיתי; גרירה — PointerEvent
 * מסוג touch, כמו v4/v6: ה-iframe בארגז החול אינו מסיים גרירה של page.mouse). מכסה את §2.1–2.6 ואת הטבלה של §2.4 (פעולה במגע
 * לכל פרוסה V1–V12; V13 — בסבב השני), ו-§2.7: במחשב (1920 / 1366) אין שום סימן של V14, וכל כלל CSS של V14 בתוך
 * max-width: 999px או pointer: coarse. צילומים — ל-tmpdir (kol-v14-shots).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { if (process.env.V14_DEBUG && got !== undefined) console.log('   · ' + JSON.stringify(got).slice(0, 400)); ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 1200) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v14-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, touch = true) => browser.newContext({ viewport: { width, height }, hasTouch: touch, isMobile: touch, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const shotDir = path.join(os.tmpdir(), 'kol-v14-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });
    const ready = page => page.waitForFunction(() => Editor._state.file && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.touch, null, { timeout: 30000 });
    const flush = async page => { try { await page.evaluate(async () => { const S = Project._state; for (const [id, d] of S.docs) if (d.dirty) await Project._save(id); }); await page.waitForFunction(() => [...Project._state.docs.values()].every(d => !d.dirty && !d.saving), null, { timeout: 15000 }); } catch (e) { console.log('   ⚠️ flush: ' + e.message.slice(0, 200)); } };
    const errors = [];
    const newPage = async (ctx, tag) => {
      const page = await ctx.newPage();
      page.on('pageerror', e => { errors.push(tag + ': ' + e.message); console.log('   ⚠️ שגיאה בדף (' + tag + ') אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 3).join(' | ').slice(0, 300)); });
      page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
      return page;
    };
    const openEd = async page => { await page.goto(base + '/editor.php#/editor/index.html'); await page.reload(); await ready(page); await page.waitForFunction(() => !Editor._state.lv || Editor._state.lv.lists.every(L => L.status !== 'loading'), null, { timeout: 30000 }); await sleep(600); };
    // נקודה על הקנבס (קואורדינטות המעטפת) לאלמנט שבקנבס
    const at = (page, sel, i = 0, fx = 0.5, fy = 0.5, scroll = true) => page.evaluate(([s, i, fx, fy, sc]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return null; if (sc) e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, i, fx, fy, scroll]);
    const tap = async (page, p) => { await page.touchscreen.tap(p.x, p.y); await sleep(250); };
    const selName = page => page.evaluate(() => { const c = Editor._state.canvas; return c.selected ? c.label(c.selected) : null; });
    const select = (page, sel, i = 0) => page.evaluate(([s, i]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return false; const n = c.nodeOf(e); c.select(n); return !!n; }, [sel, i]);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const undo = async page => { await page.evaluate(() => Editor._undo()); await sleep(400); };
    // מגע סינתטי (PointerEvent מסוג touch) — על הקנבס או על המעטפת; hold = לחיצה ארוכה לפני התזוזה
    const PT = () => {
      const ed = Editor._state, c = ed.canvas;
      const inFrame = (x, y) => { const f = c.frame.getBoundingClientRect(), hr = c.host.getBoundingClientRect(); return x >= Math.max(f.left, hr.left) && x <= Math.min(f.right, hr.right) && y >= Math.max(f.top, hr.top) && y <= Math.min(f.bottom, hr.bottom); };
      const fpt = (x, y) => { const f = c.frame.getBoundingClientRect(); return [(x - f.left) / c.scale, (y - f.top) / c.scale]; };
      const fire = (t, type, cx, cy, id) => t.dispatchEvent(new (t.ownerDocument.defaultView.PointerEvent)(type, { bubbles: true, cancelable: true, composed: true, clientX: cx, clientY: cy, pointerId: id || 7, pointerType: 'touch', isPrimary: (id || 7) === 7, button: type === 'pointermove' ? -1 : 0, buttons: type === 'pointerup' || type === 'pointercancel' ? 0 : 1 }));
      const target = (x, y, sticky) => { if (inFrame(x, y) && !sticky.shell) { const [fx, fy] = fpt(x, y); return [c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, fx, fy]; } let t = ed.build.drag.active ? document.elementFromPoint(x, y) : (sticky.t || document.elementFromPoint(x, y)); if (!t || t.localName === 'iframe') t = document.body; return [t, x, y]; };
      window.__pt = {
        down(x, y, shell, id) { this.s = { shell: !!shell }; this.frame = inFrame(x, y) && !shell; const [t, cx, cy] = target(x, y, this.s); this.s.t = this.frame ? null : t; this.ft = this.frame ? t : null; fire(t, 'pointerdown', cx, cy, id); return t.className || t.localName; },
        move(x, y, id) { if (this.frame && !ed.build.drag.active) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', fx, fy, id); return; } const [t, cx, cy] = target(x, y, this.s); fire(t, 'pointermove', cx, cy, id); },
        up(x, y, id) { if (this.frame && !ed.build.drag.active) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', fx, fy, id); return; } const [t, cx, cy] = target(x, y, this.s); fire(t, 'pointerup', cx, cy, id); },
      };
    };
    const touchDrag = async (page, from, to, opt = {}) => {
      await page.evaluate(PT);
      await page.evaluate(([x, y, sh]) => window.__pt.down(x, y, sh), [from.x, from.y, !!opt.shell]);
      if (opt.hold) await sleep(opt.hold);
      const steps = opt.steps || 10; let mid = null;
      for (let i = 1; i <= steps; i++) { await page.evaluate(([x, y]) => window.__pt.move(x, y), [from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps]); await sleep(opt.stepMs || 24); if (i === steps - 1 && opt.during) mid = await opt.during(); }
      await sleep(80);
      await page.evaluate(([x, y]) => window.__pt.up(x, y), [to.x, to.y]); await sleep(400);
      return mid;
    };
    // מגע אמיתי (CDP) — למעטפת: ידיות, הגיליון, הפאנל. מצביע אמיתי, כך ש-setPointerCapture עובד
    const cdpOf = async page => page.__cdp || (page.__cdp = await page.context().newCDPSession(page));
    const realDrag = async (page, from, to, opt = {}) => {
      const s = await cdpOf(page), pt = (x, y) => [{ x: Math.round(x), y: Math.round(y), id: 1, radiusX: 4, radiusY: 4, force: 1 }];
      await s.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pt(from.x, from.y) });
      if (opt.hold) await sleep(opt.hold);
      const steps = opt.steps ?? 10; let mid = null;
      for (let i = 1; i <= steps; i++) { await s.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pt(from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps) }); await sleep(opt.stepMs || 24); if (i === steps - 1 && opt.during) mid = await opt.during(); }
      await sleep(60);
      await s.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(400);
      return mid;
    };
    // מאזיני touchmove שאינם passive על ה-document (CDP) — רק בזמן גרירה
    const tmCount = async page => { const c = await cdpOf(page); const { result } = await c.send('Runtime.evaluate', { expression: 'document' }); const { listeners } = await c.send('DOMDebugger.getEventListeners', { objectId: result.objectId }); return listeners.filter(l => l.type === 'touchmove' && !l.passive).length; };
    const rectOf = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height, top: r.top, bottom: r.bottom, left: r.left, right: r.right, cx: r.x + r.width / 2, cy: r.y + r.height / 2 }; }, sel);
    const sheetOpen = page => page.evaluate(() => document.getElementById('sheet').open);
    /** T2: גרירה אל צד ה-<span> של השורה (ולא לתוכו — E2E 🟡1) נשארת באותה רובריקה, ובטלפון שואלת את שאלת V7 ("בכל המכשירים / רק כאן") — עונים "בכל המכשירים". */
    /** T2: איפה האלמנט נחת — אח ישיר באותה רובריקה, לא בתוך שורה (<span>…), ומה שלפניו. ובקובץ — אין <p> / <h*> / <div> בתוך <span>. */
    const placeOf = page => page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector(window.__mv), prev = e && e.previousElementSibling;
      return { par: e && e.parentElement && e.parentElement.className, inLine: !!(e && e.closest('span, b, i, em, strong, small, label, a')), prev: prev ? prev.className : '', blockInSpan: /<span\b[^>]*>(?:(?!<\/span>)[\s\S])*<(p|div|h[1-6])\b/.test(Editor._state.doc.text()) }; });
    const answerAll = page => page.evaluate(() => { const d = document.getElementById('sheet'), b = d.open && /סדר ב/.test(d.textContent) && d.querySelector('[data-choice="all"]'); if (b) b.click(); return !!b; }).then(x => (x ? sleep(400) : null));
    const closeSheet = page => page.evaluate(() => { const d = document.getElementById('sheet'); if (d.open) d.close(); });

    // ═══ הכנה ═══
    {
      const ctx = await mk(1280, 900, false), page = await newPage(ctx, 'prep');
      await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
      await page.evaluate(() => api('pf_migrate', {}));
      await ctx.close();
    }

    // ═══ 0. Monaco (project.js / code.js): ה-flake של v12 ("_amdLoaderGlobal has already been declared") נסגר ב-V16 (data-monaco-loader);
    //      V14 מוסיף: איפוס בכישלון — תג שנכשל ב-error מוסר והניסיון הבא טוען מחדש; ב-timeout התג נשאר והניסיון הבא מחכה לו ═══
    {
      const LD = '**/monaco/vs/loader.js';
      const fresh = async () => { const c0 = await mk(390, 844), pg = await c0.newPage(), errs = []; pg.on('pageerror', e => errs.push(e.message)); await pg.goto(base + '/index.php#/words', { waitUntil: 'domcontentloaded' }); await pg.waitForFunction(() => typeof Project !== 'undefined' && !!Project.loadMonaco && typeof Code !== 'undefined' && !!Code.loadMonaco, null, { timeout: 20000 }); return { c0, pg, errs }; };
      const loaders = pg => pg.evaluate(() => document.querySelectorAll('script[data-monaco-loader]').length);
      // (א) loader.js חסום ← כישלון ← שחרור ← ניסיון חוזר מצליח, עם תג אחד ובלי שגיאת דף
      { const { c0, pg, errs } = await fresh();
        await pg.route(LD, r => r.abort());
        const r1 = await pg.evaluate(() => Project.loadMonaco().then(() => 'ok', e => 'fail:' + e.message));
        const after1 = await loaders(pg);
        await pg.unroute(LD);
        const r2 = await pg.evaluate(() => Project.loadMonaco().then(m => !!m && !!window.monaco, e => 'fail:' + e.message));
        await sleep(300);
        ok(/^fail/.test(r1) && after1 === 0 && r2 === true && await loaders(pg) === 1 && !errs.length, 'Monaco: loader.js חסום ← כישלון (התג הוסר) ← ניסיון חוזר מצליח — תג אחד, בלי שגיאת דף', { r1, after1, r2, n: await loaders(pg), errs });
        await c0.close(); }
      // (ב) Code.loadMonaco ו-Project.loadMonaco חופפים — loader אחד, אותו אובייקט
      { const { c0, pg, errs } = await fresh();
        const r = await pg.evaluate(async () => { const [a, b, c] = await Promise.all([Code.loadMonaco(), Project.loadMonaco(), Code.loadMonaco()]); return { same: a === b && b === c && !!a }; });
        await sleep(300);
        ok(r.same && await loaders(pg) === 1 && !errs.length, 'Monaco: Code.loadMonaco + Project.loadMonaco חופפים — loader.js אחד, אותו אובייקט, בלי שגיאת דף', { r, n: await loaders(pg), errs });
        await c0.close(); }
      // (ג) רשת איטית: Code מוותר ב-timeout (15s) בזמן ש-Project עוד מחכה (20s) — התג אינו מוסר, Project מצליח, ניסיון חוזר של Code מצליח
      { const { c0, pg, errs } = await fresh();
        await pg.route(LD, async r => { await sleep(16500); try { await r.continue(); } catch { /* */ } });
        const r = await pg.evaluate(async () => { const pc = Code.loadMonaco().then(() => 'ok', e => 'fail'), pp = Project.loadMonaco().then(() => 'ok', e => 'fail'); const [c, p] = await Promise.all([pc, pp]); const c2 = await Code.loadMonaco().then(() => 'ok', () => 'fail'); return { c, p, c2 }; });
        await sleep(300);
        ok(r.c === 'fail' && r.p === 'ok' && r.c2 === 'ok' && await loaders(pg) === 1 && !errs.length, 'Monaco: רשת איטית — Code ב-timeout לא מסיר את התג; Project מצליח, וניסיון חוזר של Code מצליח, בלי שגיאת דף', { r, n: await loaders(pg), errs });
        await c0.close(); }
    }

    // ═══ 1. 390×844 — המסך ═══
    const ctx = await mk(390, 844), page = await newPage(ctx, '390');
    await openEd(page);
    let st = await page.evaluate(() => {
      const bar = document.querySelector('.ed-bar'), vis = [...bar.children].filter(e => e.offsetParent && e.getBoundingClientRect().width);
      const tops = vis.map(e => Math.round(e.getBoundingClientRect().top + e.getBoundingClientRect().height / 2));
      const inView = s => { const e = bar.querySelector(s), r = e.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; };
      return { sw: document.documentElement.scrollWidth, w: innerWidth, oneRow: Math.max(...tops) - Math.min(...tops) <= 4, rows: [...new Set(tops)], nav: inView('[data-panel="nav"]'), info: inView('[data-panel="info"]'), barR: bar.getBoundingClientRect().right };
    });
    ok(st.sw <= st.w, '390: אין גלילה אופקית לעמוד', st);
    ok(st.oneRow && st.barR <= st.w + 1, '390: הסרגל העליון בשורה אחת (מה שלא נכנס — גולל בתוך הסרגל)', st);
    ok(st.nav && st.info, '390: 🌳 ו-ℹ️ גלויים בסרגל בלי לגלול', st);
    await shot(page, 'v14-390-start');

    // ═══ 1b. 🔴 בעלים: החלקה על הקנבס היא תמיד גלילה — מגע אמיתי (CDP), מכל נקודת התחלה ═══
    {
      const swipe = async (from, dy = -300) => { const c = await cdpOf(page), tp = (x, y) => [{ x: Math.round(x), y: Math.round(y), id: 1, radiusX: 4, radiusY: 4, force: 1 }];
        await c.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: tp(from.x, from.y) });
        for (let i = 1; i <= 15; i++) { await c.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: tp(from.x, from.y + dy * i / 15) }); await sleep(16); }
        await c.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(700); };
      const snap = () => page.evaluate(() => { const S = Editor._state, c = S.canvas; return { y: Math.round(c.frame.contentWindow.scrollY), sel: c.selected ? c.label(c.selected) : null, doc: S.doc.text().length + ':' + S.doc.text().slice(-200), css: [...S.sheets.values()].map(x => x.doc.text()).join('').length }; });
      const top0 = () => page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollTo(0, 0));
      const pointOn = (sel, fy = 0.5) => page.evaluate(([q, fy]) => { const c = Editor._state.canvas, e = c.doc.querySelector(q); if (!e) return null; const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(), hr = c.host.getBoundingClientRect(); const y = Math.min(hr.bottom - 20, Math.max(hr.top + 20, f.top + (r.top + r.height * fy) * c.scale)); return { x: f.left + (r.left + r.width / 2) * c.scale, y }; }, [sel, fy]);
      const check = async (label, sel, fy) => { await top0(); await sleep(200); const p0 = await pointOn(sel, fy); if (!p0) return ok(false, label + ' — לא נמצא ' + sel); const a = await snap(); await swipe(p0); const b = await snap();
        ok(b.y > a.y + 100 && b.sel === a.sel && b.doc === a.doc && b.css === a.css, label + ' — גולל (' + a.y + '→' + b.y + '), הבחירה והקבצים לא השתנו', { a: { y: a.y, sel: a.sel }, b: { y: b.y, sel: b.sel }, same: b.doc === a.doc && b.css === a.css }); };
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(300);
      await check('החלקה למעלה שמתחילה על הכותרת (כלום לא נבחר)', 'h1');
      await select(page, 'h1'); await sleep(600);
      await check('החלקה שמתחילה על הכותרת הנבחרת', 'h1');
      await select(page, '.kadisha-page'); await sleep(600);
      await check('החלקה בתוך רובריקה גדולה שנבחרה (הורה של כל העמוד)', 'h1');
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(300);
      await check('החלקה שמתחילה על תמונת הרקע של הרובריקה (section.kadisha-hero)', 'section.kadisha-hero', 0.8);
      // אלמנט במיקום חופשי (absolute), נבחר
      const tF = await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n'));
      await select(page, '.kadisha-hero-subtitle-main, h1 + *'); await sleep(300);
      await page.evaluate(() => { const S = Editor._state; S.pos.on(S.canvas.selected, S.pos.pickRef); }); await sleep(900);
      const freeOk = await page.evaluate(() => Editor._state.pos.isFree(Editor._state.canvas.selected));
      ok(freeOk, 'הוכן אלמנט במיקום חופשי לבדיקת ההחלקה');
      const fsel = await page.evaluate(() => { const c = Editor._state.canvas, e = c.elOf(c.selected); e.setAttribute('data-v14-free', ''); return true; });
      await check('החלקה שמתחילה על אלמנט במיקום חופשי (absolute, נבחר)', '[data-v14-free]', 0.6);
      st = await page.evaluate(() => { const b = document.querySelector('[data-ps-move]'); if (!b || b.hidden) return null; const cs = getComputedStyle(b), af = getComputedStyle(b, '::after'); return { pe: cs.pointerEvents, ta: cs.touchAction, grip: af.pointerEvents, w: parseFloat(af.width), h: parseFloat(af.height) }; });
      ok(st && st.pe === 'none' && st.grip === 'auto' && st.w >= 44 && st.h >= 44, 'מיקום חופשי במגע: התיבה אינה תופסת את האלמנט (החלקה עוברת), והגרירה — מידית ✥ של 44px', st);
      { await top0(); await sleep(300);
        const g = await page.evaluate(() => { const b = document.querySelector('[data-ps-move]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top - 24 }; });
        const r0 = await page.evaluate(() => { const r = document.querySelector('[data-v14-free]') ? Editor._state.canvas.doc.querySelector('[data-v14-free]').getBoundingClientRect() : null; return r && { l: Math.round(r.left), t: Math.round(r.top) }; });
        const css0 = await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n'));
        if (g) await realDrag(page, g, { x: g.x - 30, y: g.y + 40 }, { steps: 10, stepMs: 30 });
        await sleep(400);
        const css1 = await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n'));
        ok(!!g && css1 !== css0, 'מיקום חופשי: גרירה במגע אמיתי (CDP) מהידית ✥ — מזיזה את האלמנט (נכתב ל-CSS)', { g, r0, changed: css1 !== css0 }); }
      for (let i = 0; i < 6 && await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n')) !== tF; i++) await undo(page);
      ok(await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n')) === tF, 'Ctrl+Z מחזיר את המיקום החופשי');
      // הקשה בלי תזוזה — עדיין בוחרת
      await top0(); await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(400);
      const tp1 = await pointOn('h1'); await page.touchscreen.tap(tp1.x, tp1.y); await sleep(300);
      ok(/^h1/.test(await selName(page) || ''), 'הקשה בלי תזוזה — עדיין בוחרת (h1)');
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(500);
    }

    // ═══ 1c. 🟡 בעלים: כלי תמונת הרקע בטלפון — עם תוויות, במסגרת, לא מתבלבלים עם 🗑️/✥ ═══
    {
      await select(page, 'section.kadisha-hero'); await sleep(500);
      st = await page.evaluate(() => { const g = document.querySelector('.ed-bar [data-bg-tools]'); if (!g) return null; g.scrollIntoView({ inline: 'center', block: 'nearest' });
        const lab = g.querySelector(':scope > .ed-word'), bs = [...g.querySelectorAll('[data-bgt]')];
        return { label: lab && lab.offsetWidth > 0 ? lab.textContent.trim() : '', btns: bs.map(b => ({ k: b.dataset.bgt, txt: b.textContent.trim(), vis: [...b.querySelectorAll('.ed-word')].every(w => w.offsetWidth > 0), aria: b.getAttribute('aria-label') || '', h: Math.round(b.getBoundingClientRect().height) })), border: getComputedStyle(g).borderStyle };
      });
      ok(st && /תמונת רקע/.test(st.label) && st.btns.length === 3 && st.btns.every(b => b.vis && /תמונת רקע/.test(b.aria)) && /החלף/.test(st.btns[0].txt) && /הסר/.test(st.btns[1].txt) && /הזז/.test(st.btns[2].txt) && st.border === 'dashed',
        '390: כלי תמונת הרקע — קבוצה במסגרת עם "🖼 תמונת רקע:", ולכל כפתור תווית גלויה (החלף · הסר · הזז) ו-aria-label מלא', st);
      await page.evaluate(() => { Editor._state.canvas.select(null); document.querySelector('.ed-bar').scrollLeft = 0; }); await sleep(300);
    }

    // ═══ 2. בחירה (V1) — הקשה, הקשה חוזרת = הורה, פירורי לחם ═══
    const H1 = 'h1';
    const cvTop = () => page.evaluate(() => Math.round(document.querySelector('.ed-canvas').getBoundingClientRect().top));
    const top0 = await cvTop();
    let p = await at(page, H1); await tap(page, p);
    const s1 = await selName(page);
    ok(await cvTop() === top0, 'בחירה אינה מזיזה את הקנבס (אחרת ההקשה השנייה של הקשה כפולה נוחתת על אלמנט אחר)', [top0, await cvTop()]);
    ok(/^h1/.test(s1 || ''), 'V1 · הקשה בוחרת (h1)', s1);
    await sleep(500);                                                       // אחרי חלון ההקשה הכפולה
    p = await at(page, H1, 0, 0.5, 0.5, false); await tap(page, p);
    const s2 = await selName(page);
    ok(s2 && !/^h1/.test(s2), 'V1 · הקשה חוזרת על הנבחר — עולה להורה', s2);
    // E2E סבב 4: לחיצה ארוכה בוחרת; הקשה אחריה על אותו אלמנט — נשאר (לא ההורה), עם רמז
    {
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(500);
      const lp = await at(page, H1);
      await page.evaluate(PT); await page.evaluate(([x, y]) => window.__pt.down(x, y), [lp.x, lp.y]); await sleep(700); await page.evaluate(([x, y]) => window.__pt.up(x, y), [lp.x, lp.y]); await sleep(300);
      await page.evaluate(() => Editor._state.menu?.close()); await sleep(450);
      const lp2 = await at(page, H1, 0, 0.5, 0.5, false); await tap(page, lp2); await sleep(200);
      st = await page.evaluate(() => ({ sel: Editor._state.canvas.label(Editor._state.canvas.selected), note: (document.querySelector('.ed-note:not([hidden])') || {}).textContent || '' }));
      ok(/^h1/.test(st.sel) && /הורה/.test(st.note), 'לחיצה ארוכה ← הקשה על אותה כותרת: נשארת נבחרת, ורמז ל"בחר הורה"', st);
      await sleep(500); await tap(page, lp2); await sleep(200);
      ok(/^h1/.test(await selName(page)), 'וגם הקשה נוספת — עדיין הכותרת (ההורה — רק בכפתור / בפירורים)');
      await page.evaluate(() => { const b = [...document.querySelectorAll('.ed-seltools button')].find(x => /בחר הורה/.test(x.getAttribute('aria-label') || '')); b.click(); }); await sleep(200);
      ok(!/^h1/.test(await selName(page)), '"↑ בחר הורה" בסרגל — עולה להורה');
      await select(page, H1); await sleep(500);
    }
    st = await page.evaluate(() => { const cr = [...document.querySelectorAll('.ed-crumb')]; return cr.map(b => ({ t: b.textContent, h: b.getBoundingClientRect().height })); });
    ok(st.length >= 3 && st.every(x => x.h >= 44), 'פירורי לחם — כל פירור 44px לפחות במגע', st);
    await page.evaluate(() => { const cr = [...document.querySelectorAll('.ed-crumb')]; cr[1].scrollIntoView({ inline: 'center' }); });
    { const r = await page.evaluate(() => { const b = [...document.querySelectorAll('.ed-crumb')][1], r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, t: b.textContent }; }); await tap(page, r); ok((await selName(page) || '').startsWith(r.t.split(/[.#]/)[0]) && await selName(page) !== s2, 'הקשה על פירור בוחרת אותו (' + r.t + ')', await selName(page)); }

    // ═══ 3. הגיליון — שלושה גבהים, החלקה סוגרת, נזכר ═══
    await select(page, H1);
    await tap(page, await rectOf(page, '.ed-bar [data-panel="info"]').then(r => ({ x: r.cx, y: r.cy })));
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: document.querySelector('.ed-info').getBoundingClientRect().height, grip: !!document.querySelector('.ed-info [data-ts-grip]') }));
    ok(/info-open/.test(st.cls) && /ts-half/.test(st.cls) && Math.abs(st.h - 422) < 8 && st.grip, 'הפאנל נפתח כגיליון בחצי גובה, עם ידית אחיזה', st);
    const grip = async () => { const r = await rectOf(page, '.ed-info [data-ts-grip]'); return { x: r.cx, y: r.cy }; };
    let g = await grip(); await realDrag(page, g, { x: g.x, y: 60 });
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-info').getBoundingClientRect().height), ls: localStorage.getItem('kol-editor-sheet') }));
    ok(/ts-full/.test(st.cls) && st.h > 760 && st.ls === 'full', 'גרירת הידית למעלה — מלא (ונזכר)', st);
    g = await grip(); await realDrag(page, g, { x: g.x, y: 760 }, { steps: 12, stepMs: 40 });
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-info').getBoundingClientRect().height), ls: localStorage.getItem('kol-editor-sheet') }));
    ok(/ts-row/.test(st.cls) && st.h <= 140 && st.ls === 'row', 'גרירה למטה — שורה (רק הכותרת והלשוניות)', st);
    st = await page.evaluate(() => { const s = document.querySelector('.ed-info'), t = s.querySelector('.ed-ptabs').getBoundingClientRect(); return { tabsIn: t.bottom <= s.getBoundingClientRect().bottom + 1, tabs: t.height }; });
    ok(st.tabsIn, 'בגובה "שורה" — הלשוניות גלויות', st);
    g = await grip(); await realDrag(page, g, { x: g.x, y: 470 }, { steps: 8, stepMs: 40 });
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-info').getBoundingClientRect().height) }));
    ok(/ts-half/.test(st.cls), 'גרירה למעלה משורה — חצי', { st, g });
    g = await grip(); await tap(page, g);
    ok(/ts-full/.test(await page.evaluate(() => document.querySelector('.ed').className)), 'הקשה על הידית — הגובה הבא (חצי → מלא)');
    await page.evaluate(() => Editor._state.touch.set('row')); await sleep(350);
    g = await grip(); await realDrag(page, g, { x: g.x, y: 840 }, { steps: 6 });
    ok(!/info-open/.test(await page.evaluate(() => document.querySelector('.ed').className)), 'החלקה למטה מ"שורה" — סוגרת את הגיליון');
    await page.evaluate(() => { document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._state.touch.set('half'); }); await sleep(350);
    g = await grip(); await realDrag(page, g, { x: g.x, y: g.y + 115 }, { steps: 8, stepMs: 40 });
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-info').getBoundingClientRect().height) }));
    ok(/ts-third/.test(st.cls) && Math.abs(st.h - 844 * 0.36) < 6, 'חצי ← גרירה קצרה למטה — שליש (עוד קנבס)', st);
    await page.evaluate(() => document.querySelector('.ed-info .ed-side-head .icon-btn').click()); await sleep(200);
    await page.evaluate(() => Editor._state.touch.set('half'));
    await page.reload(); await ready(page); await sleep(500);
    await page.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(300);
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-nav').getBoundingClientRect().height), grip: !!document.querySelector('.ed-nav [data-ts-grip]') }));
    ok(/ts-half/.test(st.cls) && Math.abs(st.h - 422) < 8 && st.grip, 'אחרי טעינה מחדש — הגובה האחרון (חצי), גם ל-Navigator', st);
    await page.evaluate(() => { localStorage.setItem('kol-editor-sheet', 'bogus'); });

    // ═══ 4. האלמנט הנבחר — גלוי מעל הגיליון ═══
    const visible = (sel, i = 0) => page.evaluate(([s, i]) => {
      const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i], r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(), side = document.querySelector('.ed.nav-open .ed-nav, .ed.info-open .ed-info');
      const top = f.top + r.top * c.scale, bot = f.top + r.bottom * c.scale, sTop = side ? side.getBoundingClientRect().top : innerHeight, cTop = c.host.getBoundingClientRect().top;
      return { top: Math.round(top), bot: Math.round(bot), sTop: Math.round(sTop), cTop: Math.round(cTop), ok: top >= cTop - 1 && top < sTop - 8 };
    }, [sel, i]);
    // אלמנט בתחתית העמוד — אחרי בחירה הוא מעל ראש הגיליון
    const lastSel = await page.evaluate(() => { const c = Editor._state.canvas, real = e => !e.closest('[data-kol-canvas]') && c.nodeOf(e) && c.elOf(c.nodeOf(e)) === e, all = [...c.doc.body.querySelectorAll('p, h2, h3, a, button, footer, li')].filter(e => e.getClientRects().length && real(e)); const e = all[all.length - 1]; e.setAttribute('data-v14-last', ''); return e.localName; });
    await page.evaluate(() => { const c = Editor._state.canvas; c.frame.contentWindow.scrollTo(0, 0); const n = c.nodeOf(c.doc.querySelector('[data-v14-last]')); c.select(n); }); await sleep(700);
    st = await visible('[data-v14-last]');
    ok(st.ok, 'האלמנט הנבחר (האחרון בעמוד, ' + lastSel + ') נגלל אל מעל ראש הגיליון', st);
    // אלמנט גבוה מהשטח שנשאר — ראשו גלוי
    await page.evaluate(() => { const c = Editor._state.canvas; c.frame.contentWindow.scrollTo(0, 99999); const n = c.nodeOf(c.doc.querySelector('.kadisha-page') || c.doc.body.firstElementChild); c.select(n); }); await sleep(700);
    st = await visible('.kadisha-page, body > *');
    ok(st.ok, 'אלמנט גבוה מהשטח שנשאר — ראשו גלוי', st);
    await shot(page, 'v14-390-keep-visible');

    // ═══ 5. Navigator בגיליון — ↑ ↓ ⤵ ⤴ לשורה הנבחרת ═══
    const sib = await page.evaluate(() => { const c = Editor._state.canvas, real = e => !e.closest('[data-kol-canvas]') && c.nodeOf(e) && c.elOf(c.nodeOf(e)) === e; for (const e of c.doc.body.querySelectorAll('*')) { if (!real(e)) continue; const sibs = [...(e.parentElement?.children || [])].filter(x => x.getClientRects().length && real(x) && !/^(script|style|template)$/.test(x.localName)); const uq = e.id ? '#' + CSS.escape(e.id) : [...e.classList].map(k => '.' + CSS.escape(k)).find(k => c.doc.querySelectorAll(k).length === 1); if (uq && sibs.length >= 3 && sibs.indexOf(e) === 1 && !/^(script|style|template)$/.test(e.localName)) { window.__mv = uq; return { sel: uq, tag: e.localName, cls: e.className, i: [...e.parentElement.children].indexOf(e) }; } } return null; });
    ok(!!sib, 'נמצא אלמנט עם אחים להזזה', sib);
    const MV = sib && sib.sel;
    await page.evaluate(() => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(window.__mv)); c.select(n); }); await sleep(300);
    st = await page.evaluate(() => { const b = document.querySelector('[data-ts-navtools]'); return { shown: b && !b.hidden, btns: b ? [...b.querySelectorAll('[data-ts-nv]')].map(x => x.dataset.tsNv + (x.disabled ? '-off' : '') + ':' + Math.round(x.getBoundingClientRect().height)) : [] }; });
    ok(st.shown && st.btns.length === 5 && st.btns.every(x => +x.split(':')[1] >= 44), 'Navigator: סרגל ↑ ↓ ⤵ ⤴ "העבר אל…" לשורה הנבחרת, 44px', st);
    let idx = () => page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector(window.__mv); return [...e.parentElement.children].indexOf(e); });
    let i0 = await idx();
    await tap(page, await rectOf(page, '[data-ts-nv="down"]').then(r => ({ x: r.cx, y: r.cy })));
    ok(await idx() === i0 + 1 && /למטה/.test(await lastTx(page)), 'Navigator ↓ — השורה ירדה (עסקה "למטה")', [i0, await idx(), await lastTx(page)]);
    await tap(page, await rectOf(page, '[data-ts-nv="up"]').then(r => ({ x: r.cx, y: r.cy })));
    ok(await idx() === i0, 'Navigator ↑ — חזרה למקום');
    await tap(page, await rectOf(page, '[data-ts-nv="to"]').then(r => ({ x: r.cx, y: r.cy })));
    st = await page.evaluate(() => ({ open: document.getElementById('sheet').open, rows: document.querySelectorAll('.ed-moveto-row').length, mv: [...document.querySelectorAll('[data-mv]')].map(b => b.dataset.mv) }));
    ok(st.open && st.rows > 3 && st.mv.join() === 'up,down,in,out', '"↕ העבר אל…" — העץ בגיליון: לפני / בסוף כל יעד, ולמעלה / למטה / פנימה / החוצה', st);
    await closeSheet(page);
    // גרירה ב-Navigator במגע: לחיצה ארוכה על שורה
    await page.evaluate(() => { Editor._state.nav.refresh(); Editor._state.nav.sync(); }); await sleep(200);
    st = await page.evaluate(() => { const r = document.querySelector('.nv-row.sel'); if (!r) return null; const b = r.getBoundingClientRect(); return { x: b.x + b.width / 2, y: b.y + b.height / 2, h: b.height }; });
    if (st) {
      const ROWH = await page.evaluate(() => window.KolNavigator.ROW);   // 44 במגע (V14c), 30 בעכבר — הגרירה בשורות, לא בפיקסלים
      const from = st, to = { x: st.x, y: st.y + Math.round(70 / 30 * ROWH) }, tN = await page.evaluate(() => Editor._state.doc.text());
      const mid = await realDrag(page, from, to, { hold: 650, steps: 10, during: () => page.evaluate(() => !!Editor._state.build.drag.active) });
      const par0 = (await placeOf(page)).par;
      await answerAll(page);
      const tN1 = await page.evaluate(() => Editor._state.doc.text()), pl = await placeOf(page);
      ok(mid === true && tN1 !== tN, 'Navigator: לחיצה ארוכה על שורה ← גרירה במגע (' + await lastTx(page) + ')', { mid });
      ok(pl.par === par0 && !pl.inLine && /kadisha-hero-eyebrow/.test(pl.prev) && !pl.blockInSpan, 'Navigator · נחת אחרי שורת ה-eyebrow, באותה רובריקה — לא בתוך ה-<span> (T2 / E2E 🟡1)', pl);
      if (tN1 !== tN) await undo(page);
      ok(await page.evaluate(() => Editor._state.doc.text()) === tN, 'Navigator: Ctrl+Z מחזיר');
    } else ok(false, 'Navigator: השורה הנבחרת לא נמצאה');
    await page.evaluate(() => document.querySelector('.ed-nav .ed-side-head .icon-btn').click()); await sleep(250);

    // ═══ 6. בסרגל הנבחר: ⬆ ⬇ (V4 — ולצד הגרירה) ═══
    await page.evaluate(() => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(window.__mv)); c.select(n); }); await sleep(300);
    st = await page.evaluate(() => [...document.querySelectorAll('.ed-seltools [data-ts-mv]')].map(b => b.dataset.tsMv + ':' + Math.round(b.getBoundingClientRect().width)));
    ok(st.length === 2 && st.every(x => +x.split(':')[1] >= 44), 'סרגל הנבחר במגע: ⬆ ⬇ (החלפה עם האח)', st);
    i0 = await idx();
    await page.evaluate(() => document.querySelector('.ed-seltools [data-ts-mv="down"]').scrollIntoView({ inline: 'center', block: 'nearest' }));
    st = await page.evaluate(() => { const b = document.querySelector('.ed-seltools [data-ts-mv="down"]'), r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, top: document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) === b }; });
    ok(st.top, '⬇ נגיש בהקשה (סרגל הנבחר גולל בתוך שורת השביל)', st);
    await tap(page, st);
    ok(await idx() === i0 + 1, '⬇ — האלמנט אחרי האח', [i0, await idx()]);
    await undo(page);
    ok(await idx() === i0, 'Ctrl+Z מחזיר');

    // ═══ 7. גרירת סדר במגע (V4): לחיצה ארוכה על הנבחר ← גרירה, קו יעד ותווית, גלילה בקצה ═══
    {
      const tgt = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector(window.__mv), p = e.parentElement; const k = [...p.children].filter(x => x.getClientRects().length); return k.length; });
      await page.evaluate(() => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(window.__mv)); c.select(n); }); await sleep(200);
      const from = await at(page, MV), txt0 = await page.evaluate(() => Editor._state.doc.text());
      const sibAfter = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector(window.__mv); let s = e.nextElementSibling; while (s && !s.getClientRects().length) s = s.nextElementSibling; if (s) s.setAttribute('data-v14-after', ''); return !!s; });
      const to = sibAfter ? await at(page, '[data-v14-after]', 0, 0.5, 0.85, false) : { x: from.x, y: from.y + 80 };
      const mid = await touchDrag(page, from, to, { hold: 500, steps: 10, during: () => page.evaluate(() => { const d = Editor._state.build.drag; const tag = document.querySelector('.dg-tag:not([hidden])'); return { active: !!d.active, tag: tag ? tag.textContent : '' }; }) });
      const par4 = (await placeOf(page)).par;
      await answerAll(page);
      const after = await idx(), txt1 = await page.evaluate(() => Editor._state.doc.text()), pl4 = await placeOf(page);
      ok(pl4.par === par4 && !pl4.inLine && /kadisha-hero-eyebrow/.test(pl4.prev) && !pl4.blockInSpan, 'V4 · נחת אחרי האח שאליו נגרר (' + pl4.prev + '), באותה רובריקה — לא בתוך ה-<span>', pl4);
      ok(mid && mid.active && /מעל|מתחת|אחרי|לפני|בתוך|בסוף/.test(mid.tag), 'V4 · לחיצה ארוכה על הנבחר ← גרירה במגע, עם תווית היעד', { mid, tgt });
      const moved4 = txt1 !== txt0 && /^גרירה/.test(await lastTx(page));
      ok(moved4, 'V4 · השחרור הזיז את האלמנט (' + await lastTx(page) + ')', [i0, after]);
      if (moved4) await undo(page);
      ok(await page.evaluate(() => Editor._state.doc.text()) === txt0, 'V4 · Ctrl+Z מחזיר את הגרירה');
      // גלילה אוטומטית בקצה התחתון של הקנבס
      await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollTo(0, 0));
      const f0 = await at(page, MV);
      const hb = await rectOf(page, '.ed-canvas');
      await page.evaluate(PT);
      await page.evaluate(([x, y]) => window.__pt.down(x, y), [f0.x, f0.y]); await sleep(500);
      for (let i = 1; i <= 6; i++) { await page.evaluate(([x, y]) => window.__pt.move(x, y), [f0.x, f0.y + (hb.bottom - 12 - f0.y) * i / 6]); await sleep(30); }
      const y0 = await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollY);
      for (let i = 0; i < 30; i++) { await page.evaluate(([x, y]) => window.__pt.move(x, y), [f0.x, hb.bottom - 12]); await sleep(30); }
      const y1 = await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollY);
      await page.evaluate(() => Editor._state.build.drag.cancel()); await page.evaluate(([x, y]) => window.__pt.up(x, y), [f0.x, hb.bottom - 12]); await sleep(300);
      ok(y1 > y0 + 20, 'V4 · גלילה אוטומטית כשהאצבע בקצה התחתון של הקנבס', { y0, y1 });
      ok(await idx() === i0, 'V4 · ביטול הגרירה — אין שינוי');
    }

    const btn = async sel => { const r = await rectOf(page, sel); return r && r.w ? { x: r.cx, y: r.cy } : null; };
    const tapSel = async sel => { await page.evaluate(s => { const e = document.querySelector(s); if (e) e.scrollIntoView({ block: 'nearest', inline: 'center' }); }, sel); const b = await btn(sel); if (!b) return false; await tap(page, b); return true; };
    // ═══ 8. לחיצה ארוכה על אלמנט לא נבחר — תפריט הקשר כגיליון ═══
    {
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(200);
      const p2 = await at(page, H1);
      await page.evaluate(PT); await page.evaluate(([x, y]) => window.__pt.down(x, y), [p2.x, p2.y]); await sleep(700); await page.evaluate(([x, y]) => window.__pt.up(x, y), [p2.x, p2.y]); await sleep(400);
      st = await page.evaluate(() => { const m = document.querySelector('.ed-ctx'); if (!m) return null; const r = m.getBoundingClientRect(), it = [...m.querySelectorAll('.ed-ctx-item')].filter(b => b.getClientRects().length); return { w: Math.round(r.width), bottom: Math.round(r.bottom), iw: innerWidth, ih: innerHeight, n: it.length, minH: Math.min(...it.map(b => b.getBoundingClientRect().height)), close: Math.round(m.querySelector('[data-ctx-close]').getBoundingClientRect().width) }; });
      ok(st && st.w === st.iw && Math.abs(st.bottom - st.ih) <= 1 && st.n >= 10 && st.minH >= 44 && st.close >= 44, 'V13 · לחיצה ארוכה — תפריט ההקשר כגיליון מלמטה, ברוחב המסך, פריטים ו-✕ של 44px', st);
      // פעולה מהתפריט בהקשה: "שכפל" ← Ctrl+Z
      const tq = await page.evaluate(() => Editor._state.doc.text());
      await page.evaluate(() => document.querySelector('.ed-ctx [data-ctx="duplicate"]').scrollIntoView({ block: 'nearest' }));
      const dupOk = await tapSel('.ed-ctx [data-ctx="duplicate"]'); await sleep(500);
            ok(await page.evaluate(() => !document.querySelector('.ed-ctx')) && (await page.evaluate(() => Editor._state.doc.text())) !== tq && /שכפ/.test(await lastTx(page)), 'V13 · "שכפל" מהתפריט במגע — בוצע, והתפריט נסגר (' + await lastTx(page) + ')');
      await undo(page);
      ok(await page.evaluate(() => Editor._state.doc.text()) === tq, 'V13 · Ctrl+Z מחזיר');
      await page.evaluate(() => Editor._state.menu?.close());
      // במגע אמיתי (CDP): לחיצה ארוכה ← שחרור ← התפריט פתוח, ושום פריט לא הופעל (ה-click של השחרור נבלע)
      for (const sel of ['h1', '.kadisha-hero-subtitle-main, h1 + *']) {
        await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(500);
        const lp = await at(page, sel); const tL = await page.evaluate(() => Editor._state.doc.text() + '|' + Editor._state.tx.length);
        const c = await cdpOf(page), tp = [{ x: Math.round(lp.x), y: Math.round(lp.y), id: 1, radiusX: 4, radiusY: 4, force: 1 }];
        await c.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: tp }); await sleep(750);
        await c.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(600);
        const r = await page.evaluate(t => ({ menu: !!document.querySelector('.ed-ctx'), same: Editor._state.doc.text() + '|' + Editor._state.tx.length === t, tx: Editor._state.tx.length ? Editor._state.tx[Editor._state.tx.length - 1].label : '' }), tL);
        ok(r.menu && r.same, 'לחיצה ארוכה במגע אמיתי (' + sel.split(',')[0] + ') — התפריט פתוח, ושום פעולה לא רצה (ה-click של השחרור לא נוחת על פריט)', r);
        await page.evaluate(() => Editor._state.menu?.close()); await sleep(300);
      }
    }

    // ═══ 9. הקלדה (V2) + מקלדת וירטואלית ═══
    {
      if (process.env.V14_DEBUG) await page.evaluate(() => { const M = Editor._state.menu, o = M.open.bind(M); window.__mo = []; M.open = (...a) => { window.__mo.push(new Error().stack.split('\n').slice(1, 6).join(' | ')); return o(...a); }; });
      const p3 = await at(page, H1);
      await page.touchscreen.tap(p3.x, p3.y); await sleep(120); await page.touchscreen.tap(p3.x, p3.y); await sleep(500);
      await page.evaluate(() => Editor._state.touch.fakeKeyboard(320)); await sleep(300);
      const act = await page.evaluate(() => !!Editor._state.text?.active);
      ok(act, 'V2 · הקשה כפולה — עריכת טקסט במקום', await page.evaluate(() => { const c = Editor._state.canvas; return { sel: c.selected && c.label(c.selected), ev: c.events.slice(-12), held: c.held && c.label(c.held), menu: !!document.querySelector('.ed-ctx'), mo: window.__mo, sheet: document.getElementById('sheet').open }; }));
      st = await page.evaluate(() => { const b = document.querySelector('.ed-tbar:not([hidden])'); const r = b && b.getBoundingClientRect(); return r ? { bottom: Math.round(r.bottom), top: Math.round(r.top), ih: innerHeight, pos: getComputedStyle(b).position } : null; });
      ok(st && st.pos === 'fixed' && Math.abs(st.bottom - (st.ih - 320)) <= 2, 'מקלדת: שורת העיצוב (B I קישור…) צמודה מעל המקלדת', st);
      await page.keyboard.press('Control+End'); await page.keyboard.type(' ב'); await sleep(100);
      await page.evaluate(() => Editor._state.touch.keep(Editor._state.canvas.selected)); await sleep(200);
      const v = await page.evaluate(() => { const c = Editor._state.canvas, e = c.elOf(c.selected), r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(), bar = document.querySelector('.ed-tbar:not([hidden])').getBoundingClientRect(); return { top: Math.round(f.top + r.top * c.scale), barTop: Math.round(bar.top), cTop: Math.round(c.host.getBoundingClientRect().top) }; });
      ok(v.top >= v.cTop - 1 && v.top < v.barTop, 'מקלדת: השורה הנערכת גלויה מעל שורת העיצוב', v);
      ok(await page.evaluate(() => !!Editor._state.text?.active), 'V2 · העריכה עדיין פעילה אחרי הגלילה אל מעל המקלדת', await page.evaluate(() => { const c = Editor._state.canvas; return { h1: c.doc.querySelector('h1').outerHTML.slice(0, 200), act: document.activeElement && document.activeElement.localName, fa: c.doc.activeElement && c.doc.activeElement.localName }; }));
      await page.keyboard.press('Escape'); await sleep(500);
      const h1t = await page.evaluate(() => Editor._state.doc.text().match(/<h1[^>]*>[\s\S]*?<\/h1>/)[0]);
      ok(/ ב/.test(h1t), 'V2 · ההקלדה נשמרה ב-index.html', h1t);
      await undo(page);
      // שדה בפאנל: הגיליון עולה, השדה גלוי
      await page.evaluate(() => { Editor._state.touch.set('row'); const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); }); await sleep(200);
      await page.evaluate(() => { document.querySelector('.ed-bar [data-panel="info"]').click(); }); await sleep(200);
      await page.evaluate(() => Editor._tab('design')); await sleep(400);
      const inp = await page.evaluate(() => { const i = [...document.querySelectorAll('.ed-info input[type="text"], .ed-info input:not([type])')].find(x => x.getClientRects().length); if (!i) return null; i.setAttribute('data-v14-inp', ''); return true; });
      if (inp) {
        await page.evaluate(() => document.querySelector('[data-v14-inp]').focus()); await sleep(400);
        st = await page.evaluate(() => { const s = document.querySelector('.ed-info').getBoundingClientRect(), i = document.querySelector('[data-v14-inp]').getBoundingClientRect(); return { cls: document.querySelector('.ed').className, sBot: Math.round(s.bottom), ih: innerHeight, iTop: Math.round(i.top), iBot: Math.round(i.bottom), sTop: Math.round(s.top) }; });
        ok(/ts-half|ts-full/.test(st.cls) && Math.abs(st.sBot - (st.ih - 320)) <= 2 && st.iTop >= st.sTop && st.iBot <= st.sBot, 'מקלדת: שדה בפאנל — הגיליון עולה מעל המקלדת, והשדה גלוי', st);
      } else ok(false, 'שדה טקסט בפאנל העיצוב לא נמצא');
      await page.evaluate(() => { document.activeElement.blur(); Editor._state.touch.fakeKeyboard(0); }); await sleep(200);
    }

    // ═══ 10. −/+ : לחיצה ארוכה חוזרת ═══
    {
      await page.evaluate(() => { Editor._state.touch.set('half'); Editor._tab('design'); }); await sleep(400);
      const pl = await page.evaluate(() => { const b = [...document.querySelectorAll('.ed-info .sg-step')].find(x => x.textContent === '+' && !x.disabled && x.getClientRects().length && x.closest('.sg-line').querySelector('input')); if (!b) return null; b.scrollIntoView({ block: 'center' }); b.setAttribute('data-v14-plus', ''); window.__plus = b.getAttribute('aria-label'); const inp = b.parentElement.querySelector('input'); const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, v: inp.value, w: Math.round(r.width), h: Math.round(r.height) }; });
      if (pl) {
        ok(pl.w >= 44 && pl.h >= 44, '−/+ בפאנל — 44px במגע', pl);
        ok(await page.evaluate(p => document.elementFromPoint(p.x, p.y) === document.querySelector('[data-v14-plus]'), pl), '+ אינו מכוסה (הגיליון ברוחב המסך)');
        await realDrag(page, pl, pl, { hold: 1500, steps: 0 }); await sleep(300);
        const v1 = await page.evaluate(() => { const b = [...document.querySelectorAll('.ed-info .sg-step')].find(x => x.getAttribute('aria-label') === window.__plus); return b ? b.parentElement.querySelector('input').value : null; });
        const num = s => parseFloat(String(s).replace(/[^\d.-]/g, '')) || 0;
        ok(v1 != null && num(v1) - num(pl.v) >= 4, '+ בלחיצה ארוכה — חוזר ומאיץ (' + pl.v + ' ← ' + v1 + ')', { from: pl.v, to: v1 });
        for (let i = 0; i < 3; i++) { const t = await lastTx(page); if (!/.+/.test(t)) break; }
        await undo(page);
      } else ok(false, '+ בפאנל העיצוב לא נמצא');
    }

    // ═══ 11. ידית מרווח במגע (V3): 44px, ערך צף מעל האצבע ═══
    {
      await page.evaluate(() => { document.querySelector('.ed-info .ed-side-head .icon-btn').click(); }); await sleep(200);
      await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); }); await sleep(200);
      await page.evaluate(() => document.querySelector('[data-space]').click()); await sleep(400);
      const hd = await page.evaluate(() => { const b = [...document.querySelectorAll('.sp-h')].find(x => !x.hidden && x.getClientRects().length && /^padding-(top|bottom)/.test(x.dataset.sp)) || [...document.querySelectorAll('.sp-h')].find(x => !x.hidden && x.getClientRects().length); if (!b) return null; const r = b.getBoundingClientRect(), x = r.x + r.width / 2, y = r.y + r.height / 2; const hit = document.elementFromPoint(x + 18, y + 18), hit0 = document.elementFromPoint(x, y); return { x, y, sp: b.dataset.sp, w: r.width, hh: r.height, hit18: hit === b, at18: hit && hit.className, at0: hit0 === b ? 'self' : hit0 && hit0.className }; });
      if (hd) {
        ok(hd.hit18, 'V3 · ידית המרווח — אזור מגע 44×44 (נקודה 18px מהמרכז עדיין הידית)', hd);
        const vert = /top|bottom/.test(hd.sp);
        const mid = await realDrag(page, hd, vert ? { x: hd.x, y: hd.y + 40 } : { x: hd.x - 40, y: hd.y }, { steps: 8, during: () => page.evaluate(() => { const n = document.querySelector('.sp-num:not([hidden])'); return n ? Math.round(n.getBoundingClientRect().bottom) : null; }) });
        const fy = hd.y + (vert ? 40 * 7 / 8 : 0);
        ok(mid != null && mid < fy - 20, 'V3 · בזמן הגרירה — הערך הצף מעל האצבע', { numBottom: mid, finger: Math.round(fy) });
        ok(/מרווח|ריפוד|שוליים|padding|margin/.test(await lastTx(page)), 'V3 · גרירת ידית במגע נכתבה (' + await lastTx(page) + ')');
        await undo(page);
      } else ok(false, 'V3 · ידית מרווח לא הופיעה במגע');
      await page.evaluate(() => document.querySelector('[data-space]').click()); await sleep(200);
    }

    // ═══ 12. צביטה — הגדלה עד 300%, "⤢ התאם" ═══
    {
      const cv = await rectOf(page, '.ed-canvas');
      const cx = cv.cx, cy = cv.cy;
      await page.evaluate(PT);
      await page.evaluate(([x, y]) => { window.__pt.down(x - 20, y, false, 11); window.__pt.down(x + 20, y, false, 12); }, [cx, cy]);
      for (let i = 1; i <= 8; i++) { await page.evaluate(([x, y, d]) => { window.__pt.move(x - d, y, 11); window.__pt.move(x + d, y, 12); }, [cx, cy, 20 + i * 10]); await sleep(20); }
      await page.evaluate(([x, y]) => { window.__pt.up(x - 100, y, 11); window.__pt.up(x + 100, y, 12); }, [cx, cy]); await sleep(300);
      st = await page.evaluate(() => ({ z: Editor._state.touch.zoom, scale: Editor._state.canvas.scale, fit: !document.querySelector('[data-ts-fit]').hidden, fitT: document.querySelector('[data-ts-fit]').textContent }));
      st.fitH = await page.evaluate(() => Math.round(document.querySelector('[data-ts-fit]').getBoundingClientRect().height));
      ok(st.z > 2 && st.z <= 3 && st.fit && st.fitH >= 44, 'צביטה — הקנבס מוגדל (' + Math.round(st.z * 100) + '%), ו"⤢ התאם" מופיע', st);
      await page.evaluate(() => Editor._state.touch.setZoom(9));
      ok(await page.evaluate(() => Editor._state.touch.zoom) === 3, 'הגדלה — עד 300% לכל היותר');
      // במצב מוגדל: הקשה בוחרת את האלמנט הנכון
      let p4 = await at(page, H1);
      p4 = await page.evaluate(p => { const host = Editor._state.canvas.host, r = host.getBoundingClientRect(); host.scrollLeft += p.x - (r.left + r.width / 2); return p; }, p4);
      p4 = await at(page, H1, 0, 0.5, 0.5, false);
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(450);   // הקשה על הנבחר עולה להורה — מתחילים בלי בחירה
      await tap(page, p4);
      ok(/^h1/.test(await selName(page) || ''), 'במצב מוגדל — הקשה בוחרת את האלמנט שמתחת לאצבע', await page.evaluate(p => { const c = Editor._state.canvas, f = c.frame.getBoundingClientRect(); const t = c.doc.elementFromPoint((p.x - f.left) / c.scale, (p.y - f.top) / c.scale); return { sel: c.selected && c.label(c.selected), at: t && t.localName + '.' + t.className, ev: c.events.slice(-6), text: !!Editor._state.text?.active, menu: !!document.querySelector('.ed-ctx'), p, hostR: c.host.getBoundingClientRect().toJSON() }; }, p4));
      await shot(page, 'v14-390-zoom');
      await tap(page, await rectOf(page, '[data-ts-fit]').then(r => ({ x: r.cx, y: r.cy })));
      st = await page.evaluate(() => ({ z: Editor._state.touch.zoom, fit: document.querySelector('[data-ts-fit]').hidden }));
      ok(st.z === 1 && st.fit, '"⤢ התאם" — חזרה ל-100%', st);
    }

    // ═══ 13. הטבלה של §2.4 — פעולה במגע לכל פרוסה ═══
    {
      // V5 · החלפת תמונת רקע — הכפתור בסרגל
      const sec = await page.evaluate(() => { const c = Editor._state.canvas; for (const e of c.doc.querySelectorAll('body *')) { const bg = c.frame.contentWindow.getComputedStyle(e).backgroundImage; if (/url\(/.test(bg) && e.getClientRects().length) { const n = c.nodeOf(e); if (n) { c.select(n); return e.localName + '.' + e.className; } } } return null; });
      await sleep(300);
      if (sec) { const okT = await tapSel('[data-bgt="replace"]'); await sleep(400); ok(okT && await sheetOpen(page), 'V5 · "🖼 החלף" תמונת רקע — הקשה פותחת את הבחירה (' + sec + ')'); await closeSheet(page); }
      else ok(true, 'V5 · אין בעמוד רקע-תמונה — מדלג (נבדק ב-v5)');
      // V6 · מיקום חופשי
      await select(page, MV); await sleep(300);
      const t6 = await tapSel('[data-free-btn]'); await sleep(500);
      ok(t6 && /מיקום|חופשי|position/.test(await lastTx(page) + (await page.evaluate(() => document.querySelector('[data-free-btn]').getAttribute('aria-pressed')))), 'V6 · "✥ מיקום חופשי" — הקשה (' + await lastTx(page) + ')');
      if (await page.evaluate(() => document.querySelector('[data-free-btn]').getAttribute('aria-pressed') === 'true')) await undo(page);
      // V7 · עריכה במצב "טלפון" — נכתבת ל-@media
      st = await page.evaluate(() => ({ dev: Editor._state.device, w: Editor._state.canvas.viewport, host: Editor._state.canvas.host.clientWidth, frameW: Math.round(Editor._state.canvas.frame.getBoundingClientRect().width), scale: Editor._state.canvas.scale }));
      ok(st.scale === 1 && st.dev === 'mobile' && st.frameW === st.host, 'V7 · בטלפון, מצב "טלפון" נערך ברוחב האמיתי (קנה מידה 1, המסגרת = רוחב הקנבס)', st);
      // V8 · מצב :hover — כפתור המצב בפאנל
      await select(page, H1); await page.evaluate(() => { document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab('design'); }); await sleep(500);
      const hv = await page.evaluate(() => { const b = document.querySelector('.ed-info .sg-state-seg [data-state=":hover"]'); if (!b) return null; b.scrollIntoView({ block: 'center' }); const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, h: r.height }; });
      if (hv) { await tap(page, hv); ok(await page.evaluate(() => document.querySelector('.ed-info .sg-state-seg [data-state=":hover"]').getAttribute('aria-pressed') === 'true' || /hover/.test(document.querySelector('.sg-state-seg [aria-pressed="true"]')?.dataset.state || '')) && hv.h >= 44, 'V8 · מצב :hover — הקשה בוחרת את המצב (44px)', hv); await page.evaluate(() => document.querySelector('.ed-info .sg-state-seg [data-state="normal"]')?.click()); }
      else ok(false, 'V8 · כפתורי המצב לא נמצאו');
      // V9 · "🌐 כללי" — משתנים
      const t9 = await tapSel('.ed-ptabs [data-ptab="glob"]'); await sleep(500);
      ok(t9 && await page.evaluate(() => !document.querySelector('.ed-info .gl').hidden && document.querySelector('.ed-info .gl').textContent.length > 20), 'V9 · לשונית "🌐 כללי" נפתחת בהקשה');
      // V10 · צל / רדיוס — הקבוצה בפאנל, וידית הרדיוס 44px
      await page.evaluate(() => Editor._tab('design')); await sleep(300);
      const fx = await page.evaluate(() => { const s = document.querySelector('.ed-info .sg-fx, .ed-info [data-sec="effects"], .ed-info [data-sec="border"]'); return !!s; });
      ok(fx, 'V10 · קבוצות האפקטים (צל / רדיוס / מסגרת) בפאנל העיצוב בטלפון');
      // V11 · "המילה הזו" — סרגל הרשימות
      ok(await page.evaluate(() => !document.querySelector('[data-lv-strips]').hidden), 'V11 · סרגל רשימות הנתונים גלוי בטלפון');
      // V12 · רכיבים — לשונית ב-Navigator
      await page.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(300);
      const t12 = await tapSel('[data-ntab="comp"]'); await sleep(400);
      ok(t12 && await page.evaluate(() => !document.querySelector('[data-comp-body]').hidden), 'V12 · לשונית "🧩 רכיבים" נפתחת בהקשה בגיליון');
      await tapSel('[data-ntab="lib"]'); await sleep(300);
      const libItem = await page.evaluate(() => { const b = document.querySelector('.ed-libbody [data-lib]'); if (!b) return null; const r = b.getBoundingClientRect(); return { h: r.height }; });
      ok(libItem && libItem.h >= 44, 'V4 · הספרייה בגיליון — פריטים להקשה', libItem);
      await page.evaluate(() => document.querySelector('.ed-nav .ed-side-head .icon-btn').click()); await sleep(200);
    }

    // ═══ 13a. V13 במגע: חיפוש פעולה מכפתור, היסטוריה, תבניות ═══
    {
      await select(page, H1); await sleep(300);
      st = await page.evaluate(() => { document.querySelector('.ed-bar').scrollLeft = 0; const b = document.querySelector('[data-ts-palette]'), r = b && b.getBoundingClientRect(); return b ? { hidden: b.hidden, inView: r.left >= 0 && r.right <= innerWidth, w: Math.round(r.width) } : null; });
      ok(st && !st.hidden && st.inView, 'V13 · 🔎 חיפוש פעולה (Ctrl+E) — כפתור בסרגל, גלוי במגע', st);
      await tapSel('[data-ts-palette]'); await sleep(400);
      st = await page.evaluate(() => { const p = document.querySelector('[data-palette]'); if (!p) return null; const rows = [...p.querySelectorAll('.pl-row')].filter(b => b.getClientRects().length); return { n: rows.length, minH: Math.min(...rows.map(b => b.getBoundingClientRect().height)), sw: document.documentElement.scrollWidth, iw: innerWidth }; });
      ok(st && st.n > 3 && st.minH >= 44 && st.sw <= st.iw, 'V13 · החיפוש נפתח בהקשה; שורות 44px, בלי גלילה אופקית', st);
      await page.fill('[data-palette] .pl-in', 'שכפל'); await sleep(300);
      const tq2 = await page.evaluate(() => Editor._state.doc.text());
      const row = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-palette] .pl-row')].find(x => /שכפל/.test(x.textContent) && x.getAttribute('aria-disabled') !== 'true'); if (!b) return null; const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });
      if (row) { await tap(page, row); await sleep(500); }
      ok(row && (await page.evaluate(() => Editor._state.doc.text())) !== tq2, 'V13 · פעולה מהחיפוש בהקשה ("שכפל") — בוצעה');
      if (row) await undo(page);
      await page.evaluate(() => Editor._state.palette?.close());
      // היסטוריה ותבניות — לשוניות בגיליון ה-Navigator
      await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); }); await sleep(300);
      const th = await tapSel('[data-ntab="hist"]'); await sleep(400);
      st = await page.evaluate(() => { const rows = [...document.querySelectorAll('[data-hist-list] .hs-row')].filter(b => b.getClientRects().length); const tabs = [...document.querySelectorAll('.ed-ntabs button')].map(b => Math.round(b.getBoundingClientRect().height)); return { n: rows.length, minH: rows.length ? Math.min(...rows.map(b => b.getBoundingClientRect().height)) : 0, tabs, sw: document.documentElement.scrollWidth, iw: innerWidth }; });
      ok(th && st.n >= 2 && st.minH >= 44 && st.tabs.every(x => x >= 44) && st.sw <= st.iw, 'V13 · 🕘 היסטוריה בגיליון — שורות ולשוניות 44px', st);
      // קפיצה בהיסטוריה בהקשה: לשורת "התחלה" ובחזרה
      const tH = await page.evaluate(() => Editor._state.doc.text());
      const nTx = await page.evaluate(() => Editor._state.tx.length);
      if (nTx) {
        await tapSel('[data-hist="start"]'); await sleep(700);
        const back = await page.evaluate(() => Editor._state.tx.length);
        ok(back === 0, 'V13 · הקשה על "התחלה" בהיסטוריה — חזרה לתחילת העריכה', { nTx, back });
        await tapSel('[data-hist="r' + (nTx - 1) + '"]'); await sleep(700);
        ok(await page.evaluate(() => Editor._state.doc.text()) === tH, 'V13 · הקשה על השורה האחרונה — חזרה למצב העדכני');
      } else ok(true, 'V13 · אין עסקאות — דילוג על הקפיצה בהיסטוריה');
      const tt = await tapSel('[data-ntab="tpl"]'); await sleep(800);
      st = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-tpl-save-page], [data-tpl-import]')].filter(x => x.getClientRects().length); return { n: b.length, minH: b.length ? Math.min(...b.map(x => x.getBoundingClientRect().height)) : 0, sw: document.documentElement.scrollWidth, iw: innerWidth }; });
      ok(tt && st.n >= 2 && st.minH >= 44 && st.sw <= st.iw, 'V13 · 📄 תבניות בגיליון — נפתחות בהקשה, בלי גלילה אופקית', st);
      await shot(page, 'v14-390-v13-templates');
      await tapSel('[data-ntab="tree"]'); await sleep(200);
      await page.evaluate(() => document.querySelector('.ed-nav .ed-side-head .icon-btn').click()); await sleep(200);
    }

    // ═══ 13c. ⛶ מסך מלא, ↕ לכל שורה, גלילה בקצה ה-Navigator ═══
    {
      const cv0 = await page.evaluate(() => { const r = document.querySelector('.ed-canvas').getBoundingClientRect(); return { top: Math.round(r.top), h: Math.round(r.height) }; });
      await page.evaluate(() => { document.querySelector('.ed-bar').scrollLeft = 0; }); await tapSel('[data-ts-fs]'); await sleep(500);
      st = await page.evaluate(() => { const r = document.querySelector('.ed-canvas').getBoundingClientRect(), e = document.querySelector('.ed').getBoundingClientRect(); return { top: Math.round(r.top), h: Math.round(r.height), edTop: Math.round(e.top), pressed: document.querySelector('[data-ts-fs]').getAttribute('aria-pressed'), ls: localStorage.getItem('kol-editor-fs') }; });
      ok(st.edTop <= 1 && st.h >= cv0.h + 80 && st.pressed === 'true' && st.ls === '1', '⛶ מסך מלא: העורך מעל הכותרת והלשוניות — הקנבס ' + cv0.h + ' ← ' + st.h + 'px (ונזכר)', { cv0, st });
      await shot(page, 'v14-390-fullscreen');
      await tapSel('[data-ts-fs]'); await sleep(400);
      ok(await page.evaluate(() => !document.querySelector('.ed').classList.contains('ts-fs')), '⛶ שוב — חזרה');
      // ↕ על שורה שאינה הנבחרת
      await select(page, H1); await sleep(200);
      await page.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(300);
      await page.evaluate(() => { const N = Editor._state.nav; N.refresh(); N.sync(); }); await sleep(200);
      const rowInfo = await page.evaluate(sel => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(sel)), N = Editor._state.nav, i = N.index.get(n); N.list.scrollTop = Math.max(0, i * window.KolNavigator.ROW - 60); N.draw(); const row = N.list.querySelector('.nv-row[data-i="' + i + '"]'); const b = row && row.querySelector('[data-ts-rowmv]'); if (!b) return null; const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, w: Math.round(r.width), h: Math.round(r.height), rowH: Math.round(row.getBoundingClientRect().height), lab: c.label(n), selNow: c.label(c.selected) }; }, MV);
      if (rowInfo) {
        ok(rowInfo.w >= 44 && rowInfo.h >= 44 && rowInfo.rowH >= 44, 'Navigator במגע: שורה של 44px, ו-↕ של 44×44', rowInfo);
        await tap(page, rowInfo); await sleep(300);
        st = await page.evaluate(() => ({ open: document.getElementById('sheet').open, title: document.querySelector('#sheet h2, #sheet .sheet-title, #sheet header')?.textContent || document.getElementById('sheet').textContent.slice(0, 80) }));
        ok(st.open && st.title.includes(rowInfo.lab.split(' ')[0]) && rowInfo.selNow !== rowInfo.lab, '↕ על שורה שאינה הנבחרת — "העבר אל…" לשורה הזו (' + rowInfo.lab + ')', { st, rowInfo });
        const tR = await page.evaluate(() => Editor._state.doc.text());
        await tapSel('#sheet [data-mv="down"]'); await sleep(500);
        ok((await page.evaluate(() => Editor._state.doc.text())) !== tR && /למטה/.test(await lastTx(page)), '↕ ← ↓ — השורה ירדה');
        await undo(page);
      } else ok(false, '↕ לא נמצא בשורה');
      // contextmenu אחרי מגע (Chrome לאנדרואיד בלחיצה ארוכה) — אינו פותח את התפריט באמצע
      await closeSheet(page);
      const cmx = await page.evaluate(() => { const L = Editor._state.nav.list, row = L.querySelector('.nv-row:not(.sel)'), r = row.getBoundingClientRect(), x = r.x + r.width / 2, y = r.y + r.height / 2;
        row.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch', pointerId: 5, isPrimary: true, clientX: x, clientY: y }));
        row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: x, clientY: y }));
        const opened = !!document.querySelector('.ed-ctx'); row.dispatchEvent(new PointerEvent('pointercancel', { bubbles: true, pointerType: 'touch', pointerId: 5 })); Editor._state.menu?.close(); return opened; });
      ok(!cmx, 'Navigator: contextmenu שהדפדפן שולח בלחיצה ארוכה במגע — אינו פותח את התפריט באמצע (רק השחרור)');
      // גלילה אוטומטית בקצה התחתון של ה-Navigator, בזמן גרירה במגע
      await page.evaluate(() => { Editor._state.touch.set('full'); }); await sleep(450);
      await page.evaluate(() => { const N = Editor._state.nav; N.list.scrollTop = 0; N.draw(); }); await sleep(200);
      const nr = await page.evaluate(() => { const L = Editor._state.nav.list, lr = L.getBoundingClientRect(), row = [...L.querySelectorAll('.nv-row')].find(r => { const q = r.getBoundingClientRect(); return q.top >= lr.top && q.bottom <= lr.bottom - 50 && !/^(html|head|body)$/.test(r.querySelector('b').textContent); }), rr = row.getBoundingClientRect(); return { x: rr.x + rr.width / 2, y: rr.y + rr.height / 2, bottom: lr.bottom, max: L.scrollHeight - L.clientHeight }; });
      const s0 = await page.evaluate(() => Editor._state.nav.list.scrollTop);
      const cdp = await cdpOf(page), tp = (x, y) => [{ x: Math.round(x), y: Math.round(y), id: 1, radiusX: 4, radiusY: 4, force: 1 }];
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: tp(nr.x, nr.y) }); await sleep(650);
      for (let i = 1; i <= 8; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: tp(nr.x, nr.y + (nr.bottom - 8 - nr.y) * i / 8) }); await sleep(30); }
      for (let i = 0; i < 40; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: tp(nr.x + (i % 2), nr.bottom - 8) }); await sleep(30); }
      const s1 = await page.evaluate(() => Editor._state.nav.list.scrollTop), dAct = await page.evaluate(() => ({ drag: !!Editor._state.build.drag.active, ctx: !!document.querySelector('.ed-ctx') }));
      await page.evaluate(() => Editor._state.build.drag.cancel()); await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(400);
      await page.evaluate(() => Editor._state.touch.set('half')); await sleep(300);
      ok(s1 > s0 + 20 && nr.max > 40, 'Navigator: גלילה אוטומטית כשהאצבע בקצה התחתון, בזמן גרירה', { s0, s1, max: nr.max, dAct, nr });
      await page.evaluate(() => document.querySelector('.ed-nav .ed-side-head .icon-btn').click()); await sleep(200);
    }

    // ═══ 13d. בהגדלה: גרירה באצבע על רקע גוללת, על הנבחר — לא ═══
    {
      await select(page, H1); await sleep(600);
      await page.evaluate(() => Editor._state.touch.setZoom(2)); await sleep(300);
      const bgPt = await page.evaluate(() => { const c = Editor._state.canvas, host = c.host.getBoundingClientRect(); for (let y = host.top + 20; y < host.bottom - 20; y += 15) for (let x = host.left + 20; x < host.right - 20; x += 15) { const f = c.frame.getBoundingClientRect(), t = c.doc.elementFromPoint((x - f.left) / c.scale, (y - f.top) / c.scale); if (t && c.selected && !c.elOf(c.selected).contains(t)) return { x, y }; } return null; });
      // הגלילה עצמה — של הדפדפן; מה שקובע אם היא קורית: האם העורך עוצר את אירועי המגע (preventDefault) ואם touch-action מתיר
      const blocked = pt => page.evaluate(p => { const c = Editor._state.canvas, f = c.frame.getBoundingClientRect(), x = (p.x - f.left) / c.scale, y = (p.y - f.top) / c.scale, t = c.doc.elementFromPoint(x, y), W = c.doc.defaultView;
        const mk = type => { const tc = new W.Touch({ identifier: 9, target: t, clientX: x, clientY: y }); return new W.TouchEvent(type, { bubbles: true, cancelable: true, composed: true, touches: type === 'touchend' ? [] : [tc], targetTouches: type === 'touchend' ? [] : [tc], changedTouches: [tc] }); };
        const a = mk('touchstart'), b = mk('touchmove'); t.dispatchEvent(a); t.dispatchEvent(b); t.dispatchEvent(mk('touchend'));
        let ta = ''; for (let e = t; e; e = e.parentElement) { const v = W.getComputedStyle(e).touchAction; if (v !== 'auto') { ta = v; break; } }
        return { start: a.defaultPrevented, move: b.defaultPrevented, ta, host: getComputedStyle(c.host).overflow, tag: t.localName }; }, pt);
      const bgB = await blocked(bgPt);
      ok(!bgB.start && !bgB.move && !/none|pan-x(?!.*pan-y)/.test(bgB.ta) && bgB.host === 'auto', 'בהגדלה: גרירה באצבע על רקע — גוללת (העורך אינו עוצר את המגע; המכל גולל)', bgB);
      const elPt = await at(page, H1, 0, 0.5, 0.5, false);
      const elB = await blocked(elPt);
      ok(!elB.start && !elB.move, 'בהגדלה: החלקה גם על האלמנט הנבחר — גוללת (כלל הבעלים: החלקה היא תמיד גלילה; גרירה — רק אחרי לחיצה ארוכה או מ-⠿)', elB);
      await page.evaluate(() => Editor._state.touch.setZoom(1)); await sleep(300);
    }

    // ═══ 13e. V15 במגע: בורר העמוד, מנהל העמודים, הגדרות העמוד — 44px; סידור תגיות ב-⇅ במגע ═══
    {
      const SMALL = root => page.evaluate(sel => { const r0 = document.querySelector(sel); if (!r0) return ['אין ' + sel]; const out = [];
        for (const e of r0.querySelectorAll('button, input, select, textarea, [role="button"]')) { if (!e.getClientRects().length || e.disabled && e.closest('[hidden]')) continue; const r = e.getBoundingClientRect(), d = Object.keys(e.dataset).join(',') || e.className; if (r.height < 4 || r.width < 4) continue;   // עזרים נסתרים (Monaco)
          if (e.type === 'checkbox' || e.type === 'radio') { const l = e.closest('label'); if (!l || l.getBoundingClientRect().height < 44) out.push('☐ ' + d); continue; }
          if (r.height < 44 || (/icon-btn|linkish/.test(e.className) && r.width < 44)) out.push(Math.round(r.width) + '×' + Math.round(r.height) + ' ' + d); }
        return out; }, root);
      await page.evaluate(() => { document.querySelector('.ed-bar').scrollLeft = 0; }); await tapSel('[data-pages-btn]'); await sleep(500);
      st = { open: await sheetOpen(page), small: await SMALL('#sheet'), sw: await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth) };
      ok(st.open && !st.small.length && st.sw, 'V15 · 📄 בורר העמוד בהקשה — גיליון, כל השליטות ≥44px', st);
      await page.evaluate(() => [...document.querySelectorAll('#sheet .pg-pfoot button')].find(b => /כל העמודים/.test(b.textContent))?.click()); await sleep(700);
      st = { small: await SMALL('[data-pg-mgr]'), sw: await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth) };
      ok(!st.small.length && st.sw, 'V15 · מנהל העמודים — כל השליטות ≥44px, בלי גלילה אופקית', st);
      await closeSheet(page); await page.evaluate(() => document.querySelector('[data-pg-close]')?.click()); await sleep(300);
      await page.evaluate(() => Editor._state.head.show()); await sleep(700);
      st = { small: await SMALL('#sheet'), sw: await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth) };
      ok(!st.small.length && st.sw, 'V15 · ⚙️ הגדרות העמוד — כל השליטות (וגם תיבות הסימון — דרך התווית) ≥44px', st);
      // ⇅ במגע: סידור שורה ברשימת התגיות של <head>
      const tH = await page.evaluate(() => Editor._state.doc.text());
      const dr = await page.evaluate(() => { const hs = [...document.querySelectorAll('#sheet .hd-drag')].filter(b => b.getClientRects().length && !b.disabled); if (hs.length < 3) return null; const list = hs[0].closest('[data-i]').parentElement; hs[0].scrollIntoView({ block: 'center' }); const a = hs[0].getBoundingClientRect(), c = hs[2].getBoundingClientRect(); return { x: a.x + a.width / 2, y: a.y + a.height / 2, ty: c.y + c.height * 0.8, n: hs.length }; });
      if (dr) {
        await realDrag(page, dr, { x: dr.x, y: dr.ty }, { steps: 10, stepMs: 40 }); await sleep(500);
        const tH1 = await page.evaluate(() => Editor._state.doc.text());
        ok(tH1 !== tH, 'V15 · ⇅ במגע — שורה ברשימת התגיות של <head> זזה (' + await lastTx(page) + ')', { n: dr.n });
        if (tH1 !== tH) await undo(page);
        ok(await page.evaluate(() => Editor._state.doc.text()) === tH, 'V15 · Ctrl+Z מחזיר את סדר התגיות');
      } else ok(false, 'V15 · אין שלוש שורות ⇅ ברשימת התגיות');
      await closeSheet(page); await sleep(200);
    }

    // ═══ 13f. V16 במגע: ספריית הרכיבים המוכנים — 44px, הקשה מוסיפה אחרי הנבחר, לחיצה ארוכה גוררת לקנבס ═══
    {
      const SM = sel => page.evaluate(q => { const r0 = document.querySelector(q); if (!r0) return ['אין ' + q]; return [...r0.querySelectorAll('button, input, select')].filter(e => e.getClientRects().length).map(e => { const r = e.getBoundingClientRect(); return { w: r.width, h: r.height, d: Object.keys(e.dataset).join(',') }; }).filter(x => x.h > 3 && (x.h < 44 || (/kitClose/.test(x.d) && x.w < 44))).map(x => Math.round(x.w) + '×' + Math.round(x.h) + ' ' + x.d); }, sel);
      await select(page, H1); await sleep(500);
      await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); }); await sleep(300);
      await tapSel('[data-ntab="comp"]'); await sleep(800);
      st = { small: await SM('[data-comp-body]'), items: await page.evaluate(() => document.querySelectorAll('[data-comp-body] [data-kit-item]').length) };
      ok(st.items > 3 && !st.small.length, 'V16 · 🧩 הרכיבים המוכנים בגיליון — חיפוש, קטגוריות ו"⤢ לוח רחב" ≥44px', st);
      await tapSel('[data-comp-body] [data-kit-wide]'); await sleep(600);
      st = { small: await SM('[data-kit-board]'), sw: await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), w: await page.evaluate(() => { const b = document.querySelector('[data-kit-board]'); return b ? Math.round(b.getBoundingClientRect().width) : 0; }) };
      ok(st.w >= 380 && !st.small.length && st.sw, 'V16 · "⤢ לוח רחב" בטלפון — לוח ברוחב המסך, ✕ וקטגוריות ≥44px', st);
      // הקשה על כרטיס ← נוסף אחרי הנבחר (כולל העתקה לפרויקט), Ctrl+Z מחזיר
      const t0k = await page.evaluate(() => Editor._state.doc.text()), n0k = await page.evaluate(() => Editor._state.tx.length);
      const card = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-kit-board] [data-kit-item]')].find(x => x.getClientRects().length && x.getAttribute('aria-disabled') !== 'true'); if (!b) return null; b.scrollIntoView({ block: 'center' }); const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, name: b.dataset.kitItem, h: Math.round(r.height) }; });
      if (card) {
        ok(card.h >= 44, 'V16 · כרטיס רכיב ≥44px (' + card.h + ')');
        await tap(page, card); await page.waitForFunction(n => Editor._state.tx.length > n, n0k, { timeout: 15000 }).catch(() => {}); await sleep(800);
        const t1k = await page.evaluate(() => Editor._state.doc.text());
        ok(t1k !== t0k && new RegExp('data-kol-component="' + card.name).test(t1k), 'V16 · הקשה על "' + card.name + '" — נוסף לעמוד (' + await lastTx(page) + ')');
        await undo(page); await sleep(600);
        ok(await page.evaluate(() => Editor._state.doc.text()) === t0k, 'V16 · Ctrl+Z מחזיר את העמוד');
      } else ok(false, 'V16 · אין כרטיס רכיב זמין בלוח');
      // לחיצה ארוכה על כרטיס ← גרירה לקנבס
      await page.evaluate(() => document.querySelector('[data-kit-board] [data-kit-close]')?.click()); await sleep(400);
      const card2 = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-comp-body] [data-kit-item]')].find(x => x.getClientRects().length && x.getAttribute('aria-disabled') !== 'true'); if (!b) return null; b.scrollIntoView({ block: 'nearest' }); const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, name: b.dataset.kitItem }; });
      const tgt = await at(page, H1, 0, 0.5, 0.9);
      const t2k = await page.evaluate(() => Editor._state.doc.text());
      if (card2 && tgt) {
        const tm0 = await tmCount(page); let tm1 = -1;
        const mid = await realDrag(page, card2, tgt, { hold: 650, steps: 12, stepMs: 40, during: async () => { tm1 = await tmCount(page); return page.evaluate(() => !!Editor._state.build.drag.active); } });
        await page.waitForFunction(t => Editor._state.doc.text() !== t, t2k, { timeout: 15000 }).catch(() => {}); await sleep(600);
        const t3k = await page.evaluate(() => Editor._state.doc.text());
        ok(mid === true && t3k !== t2k, 'V16 · לחיצה ארוכה על "' + card2.name + '" ← גרירה לקנבס במגע — נוסף (' + await lastTx(page) + ')', { mid });
        const tm2 = await tmCount(page);
        ok(tm1 === tm0 + 1 && tm2 === tm0, 'מאזין touchmove שאינו passive על ה-document — רק בזמן הגרירה (לא כל עוד העורך פתוח)', { tm0, tm1, tm2 });
        if (t3k !== t2k) { await undo(page); await sleep(600); }
        ok(await page.evaluate(() => Editor._state.doc.text()) === t2k, 'V16 · Ctrl+Z מחזיר את הגרירה');
      } else ok(false, 'V16 · כרטיס / יעד לגרירה לא נמצא', { card2, tgt });
      await page.evaluate(() => { document.querySelector('[data-ntab="tree"]')?.click(); document.querySelector('.ed-nav .ed-side-head .icon-btn')?.click(); }); await sleep(300);
    }

    // ═══ 13b. E2E 🟡3: מיקום חופשי מהמחשב — אזהרה בטלפון, ו"בזרימה בטלפון בלבד" ═══
    {
      const t0 = await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n'));
      await page.evaluate(() => document.querySelector('.ed-dev [data-dev="desktop"]').click()); await sleep(600);
      await select(page, MV); await sleep(300);
      await page.evaluate(() => { const S = Editor._state; S.pos.on(S.canvas.selected, S.pos.pickRef); }); await sleep(600);
      const freeD = await page.evaluate(() => Editor._state.pos.isFree(Editor._state.canvas.selected));
      await page.evaluate(() => document.querySelector('.ed-dev [data-dev="mobile"]').click()); await sleep(600);
      await select(page, MV); await sleep(600);
      st = await page.evaluate(() => { const w = document.querySelector('[data-ts-freewarn]'); return { free: Editor._state.pos.isFree(Editor._state.canvas.selected), shown: !!w && !w.hidden, text: w ? w.textContent : '' }; });
      ok(freeD && st.free && st.shown && /מחשב/.test(st.text), 'E2E · מיקום חופשי שנקבע במחשב — בטלפון מופיעה אזהרה', st);
      const fl = await page.evaluate(() => { const b = document.querySelector('[data-ts-flow]'); b.scrollIntoView({ block: 'nearest' }); const r = b.getBoundingClientRect(), t = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return { x: r.x + r.width / 2, y: r.y + r.height / 2, top: t === b || b.contains(t), dis: b.disabled, at: t && t.className }; });
      ok(fl.top && !fl.dis, '"⇩ בזרימה" — גלוי ונגיש בהקשה', fl);
      await tap(page, fl); await sleep(700);
      st = await page.evaluate(() => { const S = Editor._state; return { free: S.pos.isFree(S.canvas.selected), shown: !document.querySelector('[data-ts-freewarn]').hidden, css: [...S.sheets.values()].map(x => x.doc.text()).join('\n') }; });
      ok(!st.free && !st.shown && /@media[^{]*max-width[^{]*\{[^@]*position:\s*static/.test(st.css), '"⇩ בזרימה בטלפון בלבד" — position: static ב-@media, והאזהרה נעלמת', { free: st.free, shown: st.shown });
      await page.evaluate(() => document.querySelector('.ed-dev [data-dev="desktop"]').click()); await sleep(600);
      await select(page, MV); await sleep(300);
      ok(await page.evaluate(() => Editor._state.pos.isFree(Editor._state.canvas.selected)), 'במחשב — האלמנט נשאר במיקום החופשי');
      for (let i = 0; i < 6 && await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n')) !== t0; i++) await undo(page);
      ok(await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n')) === t0, 'Ctrl+Z מחזיר את ה-CSS');
      await page.evaluate(() => document.querySelector('.ed-dev [data-dev="mobile"]').click()); await sleep(600);
    }
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '390: בסוף — עדיין אין גלילה אופקית');
    await flush(page);
    await ctx.close();

    // ═══ 14. 360×740 ═══
    {
      const c2 = await mk(360, 740), pg = await newPage(c2, '360'); await openEd(pg);
      st = await pg.evaluate(() => ({ sw: document.documentElement.scrollWidth, w: innerWidth, cv: Math.round(document.querySelector('.ed-canvas').getBoundingClientRect().height) }));
      ok(st.sw <= st.w && st.cv > 360, '360×740: אין גלילה אופקית, והקנבס ממלא את המסך', st);
      await shot(pg, 'v14-360'); await c2.close();
    }
    // ═══ 14b. §2.4: שאר המסכים (הקלטות, נתונים, פרסום, קבצים) ובוחר המדיה של העורך — לא נשברים ב-390 ═══
    {
      const c6 = await mk(390, 844), pg = await newPage(c6, '390-screens');
      for (const r of ['studio', 'words', 'publish', 'files', 'code']) {
        await pg.goto(base + '/index.php#/' + r); await sleep(1800);
        st = await pg.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: innerWidth, txt: (document.querySelector('main') || document.body).textContent.trim().length }));
        ok(st.sw <= st.iw && st.txt > 20, '390 · #/' + r + ' — נטען, בלי גלילה אופקית', st);
      }
      await openEd(pg);
      for (const kind of ['image', 'audio']) {
        await pg.evaluate(k => { Editor._pick(k, 'בדיקה'); }, kind); await sleep(900);
        st = await pg.evaluate(() => { const d = document.getElementById('sheet'), r = d.getBoundingClientRect(); return { open: d.open, w: Math.round(r.width), iw: innerWidth, sw: document.documentElement.scrollWidth, items: d.querySelectorAll('button').length }; });
        ok(st.open && st.w <= st.iw && st.sw <= st.iw && st.items > 1, '390 · בוחר ' + (kind === 'audio' ? 'ההקלטות' : 'התמונות') + ' בעורך — נפתח כגיליון ברוחב המסך', st);
        await pg.evaluate(() => document.getElementById('sheet').close()); await sleep(200);
      }
      await c6.close();
    }

    // ═══ 15. מצב רוחב — הגיליון בצד, 40% ═══
    {
      const c3 = await mk(844, 390), pg = await newPage(c3, 'land'); await openEd(pg);
      await pg.evaluate(() => document.querySelector('.ed-bar [data-panel="info"]').click()); await sleep(400);
      st = await pg.evaluate(() => { const r = document.querySelector('.ed-info').getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), iw: innerWidth, ih: innerHeight }; });
      ok(Math.abs(st.w - st.iw * 0.4) < 4 && st.h >= st.ih - 2 && st.top <= 1, 'מצב רוחב (844×390): הגיליון בצד, ברוחב 40%', st);
      await pg.evaluate(() => document.querySelector('.ed-info .ed-side-head .icon-btn').click()); await sleep(400);
      st = await pg.evaluate(() => { const e = document.querySelector('.ed').getBoundingClientRect(), cv = document.querySelector('.ed-canvas').getBoundingClientRect(); return { edTop: Math.round(e.top), edBot: Math.round(e.bottom), cvH: Math.round(cv.height), cvBot: Math.round(cv.bottom), ih: innerHeight, sh: document.documentElement.scrollHeight }; });
      st.hint = await pg.evaluate(() => { try { localStorage.removeItem('kol-editor-landhint'); } catch { /* */ } const T = Editor._state.touch; T.landHinted = false; T.layout(); return (document.querySelector('.ed-note:not([hidden])') || {}).textContent || ''; });
      st.again = await pg.evaluate(() => { const T = Editor._state.touch; T.landHinted = false; document.querySelector('.ed-note .icon-btn')?.click(); T.layout(); return !!document.querySelector('.ed-note:not([hidden])'); });
      ok(!st.again, 'מצב רוחב: הרמז מוצג פעם אחת למכשיר, לא בכל פתיחה', st.again);
      ok(/סובבו/.test(st.hint), 'מצב רוחב: רמז — לכותרת וללשוניות מסובבים לאורך', st.hint);
      ok(st.edTop <= 1 && st.edBot <= st.ih + 1 && st.cvBot <= st.ih + 1 && st.cvH >= 200, 'מצב רוחב: העורך על כל המסך (מעל כותרת האפליקציה), והקנבס ' + st.cvH + 'px מתוך ' + st.ih, st);
      await shot(pg, 'v14-landscape'); await c3.close();
    }
    // ═══ 16. טאבלט 768×1024 — הפאנל בצד (320px), Navigator גיליון ═══
    {
      const c4 = await mk(768, 1024), pg = await newPage(c4, '768'); await openEd(pg);
      await pg.evaluate(() => document.querySelector('.ed-bar [data-panel="info"]').click()); await sleep(400);
      st = await pg.evaluate(() => { const r = document.querySelector('.ed-info').getBoundingClientRect(), cv = document.querySelector('.ed-canvas').getBoundingClientRect(); return { w: Math.round(r.width), pos: getComputedStyle(document.querySelector('.ed-info')).position, cvW: Math.round(cv.width), overlap: cv.right > r.left && cv.left < r.right, sw: document.documentElement.scrollWidth }; });
      ok(st.w === 320 && st.pos !== 'fixed' && !st.overlap && st.sw <= 768, '768: הפאנל בצד (320px), לא מעל הקנבס', st);
      await pg.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(400);
      st = await pg.evaluate(() => ({ pos: getComputedStyle(document.querySelector('.ed-nav')).position, grip: document.querySelector('.ed-nav [data-ts-grip]').getBoundingClientRect().height }));
      ok(st.pos === 'fixed' && st.grip > 0, '768: Navigator כגיליון עם ידית', st);
      await pg.evaluate(() => { Editor._state.touch.set('third'); Editor._state.touch.setFull(true); }); await sleep(500);
      st = await pg.evaluate(() => { const cv = document.querySelector('.ed-canvas').getBoundingClientRect(), sd = document.querySelector('.ed-nav').getBoundingClientRect(); return { vis: Math.round(Math.min(cv.bottom, sd.top) - cv.top), cvTop: Math.round(cv.top) }; });
      ok(st.vis >= 450, '768: ⛶ מסך מלא + גיליון בשליש — ' + st.vis + 'px של קנבס מעל הגיליון', st);
      await pg.evaluate(() => { Editor._state.touch.setFull(false); Editor._state.touch.set('half'); }); await sleep(300);
      // במגע בטאבלט: הקשה בוחרת, ⬆ ⬇ בסרגל
      const pt = await pg.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('h1'); e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width / 2) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; });
      await pg.touchscreen.tap(pt.x, pt.y); await sleep(300);
      ok(await pg.evaluate(() => /^h1/.test(Editor._state.canvas.label(Editor._state.canvas.selected)) && document.querySelectorAll('.ed-seltools [data-ts-mv]').length === 2), '768: הקשה בוחרת, ו-⬆ ⬇ בסרגל הנבחר');
      // V13 ב-768: לחיצה ארוכה ← תפריט ההקשר (גיליון, 44px); 🔎 בסרגל
      await pg.evaluate(() => Editor._state.canvas.select(null)); await sleep(500);
      await pg.evaluate(PT); await pg.evaluate(([x, y]) => window.__pt.down(x, y), [pt.x, pt.y]); await sleep(700); await pg.evaluate(([x, y]) => window.__pt.up(x, y), [pt.x, pt.y]); await sleep(400);
      st = await pg.evaluate(() => { const m = document.querySelector('.ed-ctx'); if (!m) return null; const r = m.getBoundingClientRect(), it = [...m.querySelectorAll('.ed-ctx-item')].filter(b => b.getClientRects().length); return { w: Math.round(r.width), iw: innerWidth, minH: Math.min(...it.map(b => b.getBoundingClientRect().height)), pal: !document.querySelector('[data-ts-palette]').hidden }; });
      ok(st && st.w === st.iw && st.minH >= 44 && st.pal, '768 · V13: לחיצה ארוכה — תפריט ההקשר כגיליון, פריטים 44px; 🔎 בסרגל', st);
      await pg.evaluate(() => Editor._state.menu?.close());
      await shot(pg, 'v14-768'); await c4.close();
    }

    // ═══ 17. המחשב לא משתנה (§2.7) ═══
    {
      const css = fs.readFileSync(path.join(ROOT, 'assets/css/editor.css'), 'utf8'), blk = css.slice(css.indexOf('/* V14 —'));
      let depth = 0, bad = [], i = 0, ctxs = [];
      while (i < blk.length) {
        if (blk.startsWith('/*', i)) { i = blk.indexOf('*/', i) + 2; continue; }
        const ch = blk[i];
        if (ch === '{') { const head = blk.slice(blk.lastIndexOf('}', i - 1) + 1, i).replace(/\/\*[\s\S]*?\*\//g, '').trim(); ctxs.push(head); if (depth === 0 && !/^@media[^{]*(max-width:\s*999px|pointer:\s*coarse)/.test(head)) bad.push(head); depth++; }
        else if (ch === '}') { depth--; ctxs.pop(); }
        i++;
      }
      ok(!bad.length && depth === 0, '§2.7 · כל כלל CSS של V14 בתוך max-width: 999px או pointer: coarse', bad);
      ok(['top', 'right', 'bottom', 'left'].every(k => blk.includes('env(safe-area-inset-' + k + ')')), '§2.1 · אזור בטוח בכל ארבעת הקצוות (env(safe-area-inset-*))');
      { const leaks = blk.split(/[{}]/).filter((_, i) => i % 2 === 0).flatMap(h => h.split(',')).map(x => x.trim()).filter(x => /^#sheet\b/.test(x));
        ok(!leaks.length, 'editor.css נטען בכל המסכים — כל כלל של V14 על #sheet מוגבל לעורך (body:has(.ed))', leaks); }
      { const all = css.replace(/\/\*[\s\S]*?\*\//g, ''); let d = 0, mn = 0; for (const ch of all) { if (ch === '{') d++; else if (ch === '}') { d--; mn = Math.min(mn, d); } }
        ok(d === 0 && mn === 0, 'editor.css כולו מאוזן (עומק 0) — אף בלוק אינו בולע את הבלוקים שאחריו', { d, mn }); }
      // מסך מגע ≥1000px (לפטופ / טאבלט גדול): הפריסה של המחשב, וכללי pointer: coarse חלים
      { const c7 = await mk(1024, 768), pg = await newPage(c7, '1024-touch'); await openEd(pg);
        await pg.evaluate(() => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); }); await sleep(500);
        st = await pg.evaluate(() => ({ coarse: matchMedia('(pointer: coarse)').matches, phoneCls: [...document.querySelector('.ed').classList].filter(c => /^ts-(row|third|half|full|fs)$/.test(c)), crumb: Math.round(document.querySelector('.ed-crumb').getBoundingClientRect().height), mv: [...document.querySelectorAll('.ed-seltools [data-ts-mv]')].map(b => Math.round(b.getBoundingClientRect().height)), side: getComputedStyle(document.querySelector('.ed-info')).position }));
        st.fs = await pg.evaluate(() => { const b = document.querySelector('[data-ts-fs]'); return !!b && !b.hidden; });
        ok(!st.fs, '1024 במגע: ⛶ מוסתר (מסך מלא פועל רק מתחת ל-1000px)', st);
        ok(st.coarse && !st.phoneCls.length && st.crumb >= 44 && st.mv.length === 2 && st.mv.every(x => x >= 44) && st.side !== 'fixed', '1024 במגע: פריסת מחשב (פאנל בצד), וכללי המגע חלים — פירורים ו-⬆ ⬇ של 44px', st);
        await c7.close(); }
      for (const [w, hh] of [[1920, 1080], [1366, 768]]) {
        const c5 = await mk(w, hh, false), pg = await newPage(c5, String(w)); await openEd(pg);
        await pg.evaluate(() => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); }); await sleep(400);
        st = await pg.evaluate(() => {
          const ed = document.querySelector('.ed'), r = ed.getBoundingClientRect();
          return { cls: [...ed.classList].filter(c => /^ts-/.test(c)), grips: [...document.querySelectorAll('[data-ts-grip]')].map(g => g.getBoundingClientRect().height), mv: document.querySelectorAll('[data-ts-mv], [data-ts-rowmv]').length + (document.querySelector('[data-ts-fs]') && !document.querySelector('[data-ts-fs]').hidden ? 100 : 0), navtools: !document.querySelector('[data-ts-navtools]').hidden, cover: getComputedStyle(document.querySelector('.ed-panes')).marginBottom, bar: getComputedStyle(document.querySelector('.ed-bar')).flexWrap, l: Math.round(r.left), r: Math.round(innerWidth - r.right), fit: !document.querySelector('[data-ts-fit]').hidden, pal: !!document.querySelector('[data-ts-palette]') && !document.querySelector('[data-ts-palette]').hidden };
        });
        st.tabs = await pg.evaluate(() => [...document.querySelectorAll('[data-ts-grip]')].map(g => g.tabIndex));
        ok(!st.cls.length && st.tabs.every(x => x === -1) && st.grips.every(x => x === 0) && !st.mv && !st.navtools && st.cover === '0px' && !st.fit && !st.pal, w + ': אין שום סימן של V14 (ידיות גיליון, ⬆ ⬇, סרגל Navigator, קיצור הקנבס)', st);
        ok(st.l <= 16 && st.r <= 16, w + ': העורך ממלא את המסך, בלי ריק בצדדים', st);
        if (w === 1366) {   // Navigator: גובה השורה מתעדכן חי כשהקלט הופך למגע (2-in-1 / אמולציה)
          const rows = () => pg.evaluate(() => { const rs = [...document.querySelectorAll('.ed-nav .nv-row')].slice(0, 4).map(r => r.getBoundingClientRect()); return { h: rs.map(r => Math.round(r.height)), gap: rs.slice(1).map((r, i) => Math.round(r.top - rs[i].top)), coarse: matchMedia('(pointer: coarse)').matches, R: window.KolNavigator.ROW }; });
          await pg.evaluate(() => { const e = document.querySelector('.ed'); if (!e.classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); }); await sleep(300);
          const r0 = await rows();
          const cd = await pg.context().newCDPSession(pg); await cd.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }); await sleep(600);
          const r1 = await rows();
          ok(r0.R === 30 && r0.h.every(x => x === 30) && r0.gap.every(x => x === 30) && r1.coarse && r1.R === 44 && r1.h.every(x => x === 44) && r1.gap.every(x => x === 44), '1366: Navigator — גובה השורה ומרווח השורות מתעדכנים חי כשהקלט הופך למגע (30 ← 44)', { r0, r1 });
        }
        await shot(pg, 'v14-' + w); await c5.close();
      }
    }
    ok(errors.length === 0, 'אין שגיאות JavaScript בדפים' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); try { fs.rmSync(data, { recursive: true, force: true }); } catch { /* */ } }
  console.log('\n' + (failed ? '❌ ' + failed + ' נכשלו מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
