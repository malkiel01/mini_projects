/**
 * R3 — גרירה בעכבר של טקסט בקנבס (Chromium, Playwright):   node kol-maroko/tests/browser/r3.cjs
 * הבאג של הבעלים (15.11, Chrome, עכבר): גרירה על טקסט (h1/p/span בתוך section.kadisha-hero) רק מסמנת טקסט ולא גוררת.
 * עכבר אמיתי (page.mouse) — לא PointerEvent סינתטי: בחירת הטקסט וה-dragstart של הדפדפן קורים רק כך.
 * (א) הטקסט עצמו נבחר ונגרר; (ב) ההורה נבחר וגוררים מתוך טקסט של ילד. ובלי לשבור: לחיצה כפולה לעריכה,
 * בחירת טקסט בזמן עריכה, Shift. ב-390 (מגע, CDP): החלקה = גלילה, לחיצה ארוכה = גרירה.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');
const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-r3-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const call = (page, action, body) => page.evaluate(([a, b]) => api(a, b ?? undefined), [action, body ?? null]);
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build, p, { timeout: 30000 }); await sleep(300); };
    const at = (page, sel, fx = 0.5, fy = 0.5) => page.evaluate(([s, fx, fy]) => { const c = Editor._state.canvas, e = c.doc.querySelector(s); if (!e) return null; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, fx, fy]);
    /** נקודה על הטקסט עצמו (Range של צומת הטקסט הראשון), לא על ריפוד */
    const atText = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s); if (!e) return null; e.scrollIntoView({ block: 'center' }); const w = c.doc.createTreeWalker(e, 4); let t; while ((t = w.nextNode()) && !t.data.trim()); if (!t) return null; const rg = c.doc.createRange(); rg.setStart(t, 0); rg.setEnd(t, Math.min(3, t.length)); const r = rg.getClientRects()[0], f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width / 2) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; }, sel);
    const select = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s), n = e && c.nodeOf(e); c.select(n); return !!n; }, sel);
    const st = page => page.evaluate(() => { const S = Editor._state, c = S.canvas, d = S.build.drag, t = document.querySelector('.dg-tag'); return { sel: c.selected ? c.label(c.selected) : null, active: !!d.active, tag: t && !t.hidden ? t.textContent : '', textSel: String(c.doc.getSelection() || '').trim().slice(0, 80), editing: !!(S.text && S.text.active), tx: S.doc.lastTx ? S.doc.lastTx.label : null }; });
    /**
     * עכבר אמיתי דרך CDP (Input.dispatchMouseEvent) — אותו מסלול של הדפדפן כמו עכבר פיזי: mousedown, בחירת טקסט, dragstart.
     * לא page.mouse: עם כפתור לחוץ Playwright מיירט גרירה (Input.setInterceptDrags) ומחכה לה, ומעל הקנבס (sandbox) זה לא נגמר.
     */
    const cdps = new WeakMap();
    const cdpOf = async page => { if (!cdps.has(page)) cdps.set(page, await page.context().newCDPSession(page)); return cdps.get(page); };
    const M = async (page, type, x, y, o = {}) => (await cdpOf(page)).send('Input.dispatchMouseEvent', { type, x, y, button: type === 'mouseMoved' && !o.b ? 'none' : 'left', buttons: o.b ? 1 : 0, clickCount: o.n || 1, modifiers: o.mod || 0 });
    const click = async (page, p, n = 1) => { await M(page, 'mouseMoved', p.x, p.y); for (let i = 1; i <= n; i++) { await M(page, 'mousePressed', p.x, p.y, { b: 1, n: i }); await M(page, 'mouseReleased', p.x, p.y, { n: i }); } };
    /** גרירה. peek — המצב באמצע הגרירה */
    const mouseDrag = async (page, from, to, opt = {}) => {
      const mod = opt.shift ? 8 : 0;
      await M(page, 'mouseMoved', from.x, from.y, { mod }); await sleep(60);
      await M(page, 'mousePressed', from.x, from.y, { b: 1, mod }); await sleep(40);
      const steps = opt.steps || 10;
      for (let i = 1; i <= steps; i++) { await M(page, 'mouseMoved', from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps, { b: 1, mod }); await sleep(25); }
      await sleep(80);
      const peek = await st(page);
      await M(page, 'mouseReleased', to.x, to.y, { mod }); await sleep(250);
      return { peek, after: await st(page) };
    };

    // ═══ 1280, עכבר ═══
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const page = await ctx.newPage(), errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    const us = await page.evaluate(() => { const c = Editor._state.canvas, h = c.doc.querySelector('h1.kadisha-hero-title'); return h ? c.frame.contentWindow.getComputedStyle(h).userSelect : null; });
    console.log('   user-select של h1 בקנבס: ' + us);

    // (א) הטקסט עצמו: h1 נבחר (בלחיצה), ואז נגרר מתוך הטקסט שלו
    for (const [s, name] of [['h1.kadisha-hero-title', 'h1'], ['p.kadisha-hero-subtitle-main', 'p'], ['.kadisha-hero-eyebrow-line', 'span']]) {
      const from = await atText(page, s);
      if (!from) { ok(false, '(א) ' + name + ': אין טקסט בעמוד', s); continue; }
      await click(page, from); await sleep(250);
      const r = await mouseDrag(page, from, { x: from.x - 40, y: from.y + 70 });
      ok(r.peek.active && !r.peek.textSel, '(א) ' + name + ' נבחר, גרירה מתוך הטקסט שלו — גרירה של אלמנט (לא בחירת טקסט)', r);
      ok(!r.after.textSel && !r.after.editing, '(א) ' + name + ': אחרי השחרור — אין בחירת טקסט כחולה', r.after);
      await openPage(page, 'index.html');
    }
    // (ב) ההורה נבחר (section.kadisha-hero) וגוררים מתוך הטקסט של ה-h1 שבתוכו — כמו בצילום של הבעלים
    {
      await select(page, 'section.kadisha-hero'); await sleep(150);
      const from = await atText(page, 'h1.kadisha-hero-title');
      const r = await mouseDrag(page, from, { x: from.x, y: from.y + 120 });
      ok(r.peek.active && !r.peek.textSel, '(ב) section.kadisha-hero נבחר, גרירה מתוך טקסט ה-h1 — גרירה (לא בחירת טקסט)', r);
      ok(!r.after.textSel, '(ב) אחרי השחרור — אין בחירת טקסט כחולה', r.after);
    }
    // (ג) בחירת טקסט שכבר קיימת על ה-h1 (מהעתקה / מעריכה קודמת): לחיצה עליה מתחילה גרירה מקורית של הטקסט (pointercancel) — לא
    {
      await openPage(page, 'index.html');
      await select(page, 'h1.kadisha-hero-title');
      await page.evaluate(() => { const c = Editor._state.canvas, h = c.doc.querySelector('h1.kadisha-hero-title'), s = c.doc.getSelection(); s.selectAllChildren(h); });
      const from = await atText(page, 'h1.kadisha-hero-title');
      const ev0 = await page.evaluate(() => Editor._state.canvas.events.length);
      const r = await mouseDrag(page, from, { x: from.x, y: from.y + 120 });
      const evs = await page.evaluate(n => Editor._state.canvas.events.slice(n).filter(x => !/move/.test(x)), ev0);
      ok(r.peek.active && !r.peek.textSel && !evs.includes('pointercancel:mouse'), '(ג) h1 עם בחירת טקסט קיימת — גרירה של אלמנט, בלי pointercancel של גרירה מקורית', { r, evs });
    }
    // לא לשבור: לחיצה כפולה — עריכה במקום; בזמן עריכה גרירה בעכבר בוחרת טקסט (ולא גוררת); Esc יוצא
    {
      await openPage(page, 'index.html');
      const from = await atText(page, 'h1.kadisha-hero-title');
      await click(page, from, 2); await sleep(400);
      const e1 = await st(page);
      ok(e1.editing, 'לחיצה כפולה על h1 — עריכת טקסט במקום', e1);
      const box = await at(page, 'h1.kadisha-hero-title', 0.9, 0.5), box2 = await at(page, 'h1.kadisha-hero-title', 0.1, 0.5);
      const r = await mouseDrag(page, box, box2);
      ok(r.after.editing && !r.peek.active && r.after.textSel.length > 1, 'בזמן עריכה: גרירה בעכבר בתוך הטקסט — בוחרת טקסט, לא גוררת', r);
      await page.keyboard.press('Escape'); await sleep(300);
      ok(!(await st(page)).editing, 'Esc — יציאה מהעריכה', await st(page));
    }
    // לא לשבור: Shift + לחיצה — בחירה מרובה
    {
      await openPage(page, 'index.html');
      const a = await atText(page, 'h1.kadisha-hero-title'); await click(page, a); await sleep(200);
      const b = await atText(page, 'p.kadisha-hero-subtitle-main');
      await M(page, 'mouseMoved', b.x, b.y, { mod: 8 }); await M(page, 'mousePressed', b.x, b.y, { b: 1, mod: 8 }); await M(page, 'mouseReleased', b.x, b.y, { mod: 8 }); await sleep(250);
      const n = await page.evaluate(() => Editor._state.canvas.picked().length);
      ok(n === 2 && !(await st(page)).textSel, 'Shift + לחיצה על טקסט — בחירה מרובה (2), בלי בחירת טקסט', n);
    }
    ok(!errors.length, '1280: אין שגיאות בדף', errors);
    await ctx.close();

    // ═══ 390, מגע (CDP): החלקה על טקסט = גלילה, לחיצה ארוכה על טקסט = גרירה ═══
    {
      const tctx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
      const tp = await tctx.newPage(), terr = [];
      tp.on('pageerror', e => terr.push(e.message)); tp.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
      await tp.goto(base + '/#/project'); await tp.waitForSelector('.pj-tree .pj-row');
      await openPage(tp, 'index.html');
      const c = await cdpOf(tp), pt = (x, y) => [{ x: Math.round(x), y: Math.round(y), id: 1, radiusX: 4, radiusY: 4, force: 1 }];
      const touch = async (from, to, hold) => { await c.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pt(from.x, from.y) }); if (hold) await sleep(hold); let mid = null;
        for (let i = 1; i <= 12; i++) { await c.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pt(from.x + (to.x - from.x) * i / 12, from.y + (to.y - from.y) * i / 12) }); await sleep(20); if (i === 11) mid = await st(tp); }
        await c.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(500); return mid; };
      const from = await atText(tp, 'p.kadisha-hero-subtitle-main');
      const y0 = await tp.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollY);
      const m1 = await touch(from, { x: from.x, y: from.y - 250 });
      const y1 = await tp.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollY);
      ok(y1 > y0 + 50 && !m1.active, '390: החלקה על טקסט — גלילה, לא גרירה', { y0, y1, m1 });
      const f2 = await atText(tp, 'p.kadisha-hero-subtitle-main');
      const m2 = await touch(f2, { x: f2.x, y: f2.y + 140 }, 550);
      ok(m2.active && !m2.textSel, '390: לחיצה ארוכה על טקסט ואז תזוזה — גרירה של אלמנט', m2);
      ok(!terr.length, '390: אין שגיאות בדף', terr);
      await tctx.close();
    }
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
