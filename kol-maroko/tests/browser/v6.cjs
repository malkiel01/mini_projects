/**
 * פרוסה V6 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/v6.cjs
 *
 * מיקום חופשי, על index.html המוסב (העמוד האמיתי): צעד 5 של התרחיש — הכותרת בפינה התחתונה של **הפס** (ביחס אל הפס, לא אל
 * העמודה הצרה שבאמצע; האלמנט מועבר ב-HTML באותה עסקה), נשארת שם אחרי שינוי רוחב הקנבס; "אינו קופץ"; עוגן; שכבות;
 * "חזרה לזרימה" וביטול — קבצים זהים. ובטלפון (390) — חיצים.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

const PAGE = `<!doctype html>
<html lang="he" dir="rtl"><head><meta charset="utf-8"><title>בדיקה</title>
<link rel="stylesheet" href="../css/page.css">
<style>
.imp { display: flex; }
#imp { flex-direction: column !important; }
.grid { display: grid; grid-template-columns: 1fr 1fr; }
</style>
</head><body>
<main class="main">
  <h2 id="a" class="hd">כותרת א</h2>
  <h2 id="b" class="hd">כותרת ב</h2>
  <p id="pp"><span>בתוך פסקה</span></p>
  <div class="imp" id="imp"><span>1</span><span>2</span></div>
  <div class="grid" id="grid"><span>א</span><span>ב</span></div>
  <ul id="list"><li>פריט</li></ul>
  <div id="box"><p id="t1">ראשון</p><p id="t2">שני</p><p id="t3">שלישי</p></div>
  <label for="nm">שם</label><input id="nm">
</main>
</body></html>
`;

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v6-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build, p, { timeout: 30000 }); await sleep(200); };
    const files = page => page.evaluate(() => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); return o; });
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const undoFiles = async (page, to) => { for (let i = 0; i < 60 && !same(await files(page), to); i++) { await page.evaluate(() => Editor._undo()); await sleep(40); } return same(await files(page), to); };
    const src = page => page.evaluate(() => Editor._state.doc.text());
    const st = page => page.evaluate(() => { const S = Editor._state, c = S.canvas, n = c.selected; return { sel: n ? c.label(n) : null, inequiv: c.inequiv.length, mapErrors: c.mapErrors.length, same: c.src === S.doc.text(), tx: S.doc.lastTx ? S.doc.lastTx.label : null, note: document.querySelector('.ed-note:not([hidden])')?.textContent || '' }; });
    /** נקודה במסך של אלמנט בקנבס. fx / fy — חלק מהרוחב / מהגובה; dy — פיקסלים מעל / מתחת. */
    const at = (page, sel, fx = 0.5, fy = 0.5, dy = 0, scroll = true) => page.evaluate(([s, fx, fy, dy, scroll]) => { const c = Editor._state.canvas, e = c.doc.querySelector(s); if (!e) return null; if (scroll) e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy + dy) * c.scale }; }, [sel, fx, fy, dy, scroll]);
    const select = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s), n = e && c.nodeOf(e); c.select(n); return !!n; }, sel);
    const dragTag = page => page.evaluate(() => { const t = document.querySelector('.dg-tag'), l = document.querySelector('.dg-line'); return { tag: t && !t.hidden ? t.textContent : '', bad: !!t && t.classList.contains('bad'), line: l && !l.hidden ? l.className : '' }; });
    /**
     * גרירה מנקודה לנקודה (במסך של המעטפת), באירועי מצביע. לא page.mouse: ב-Chromium בלי תצוגה, תזוזה עם כפתור לחוץ מעל
     * iframe עם sandbox בלי allow-scripts (הקנבס) אינה מסתיימת לעולם — נבדק בנפרד. האירועים נשלחים לאותם מאזינים:
     * מעל הקנבס — למסמך שבמסגרת, בקואורדינטות שלה; מחוץ לו — לאלמנט שמתחת לנקודה במעטפת.
     * peek — הקו והתווית רגע לפני השחרור; esc — Esc במקום שחרור; outside — שחרור על הסרגל.
     */
    const PD = () => {
      const ed = Editor._state, c = ed.canvas;
      const inFrame = (x, y) => { const f = c.frame.getBoundingClientRect(); return x >= f.left && x <= f.right && y >= f.top && y <= f.bottom; };
      const fpt = (x, y) => { const f = c.frame.getBoundingClientRect(); return [(x - f.left) / c.scale, (y - f.top) / c.scale]; };
      const fire = (t, type, x, y, cx, cy, touch) => t.dispatchEvent(new (t.ownerDocument.defaultView.PointerEvent)(type, { bubbles: true, cancelable: true, composed: true, clientX: cx, clientY: cy, pointerId: touch ? 2 : 1, pointerType: touch ? 'touch' : 'mouse', isPrimary: true, button: type === 'pointermove' ? -1 : 0, buttons: type === 'pointerup' ? 0 : 1 }));
      window.__pd = {
        down(x, y, touch, shell) { this.touch = !!touch; this.frame = inFrame(x, y) && !shell; if (this.frame) { const [fx, fy] = fpt(x, y); this.t = c.doc.elementFromPoint(fx, fy); fire(this.t, 'pointerdown', x, y, fx, fy, touch); } else { this.t = document.elementFromPoint(x, y); fire(this.t, 'pointerdown', x, y, x, y, touch); } },
        move(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', x, y, fx, fy, this.touch); } else { const t = ed.build.drag.active ? (document.elementFromPoint(x, y) || document.body) : this.t; fire(t.localName === 'iframe' ? document.body : t, 'pointermove', x, y, x, y, this.touch); } },
        up(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', x, y, fx, fy, this.touch); } else { const t = document.elementFromPoint(x, y) || document.body; fire(t.localName === 'iframe' ? document.body : t, 'pointerup', x, y, x, y, this.touch); } },
      };
    };
    const drag = async (page, from, to, opt = {}) => {
      await page.evaluate(PD);
      await page.evaluate(([x, y, t, sh]) => window.__pd.down(x, y, t, sh), [from.x, from.y, !!opt.touch, !!opt.shell]);
      if (opt.hold) await sleep(opt.hold);
      const steps = opt.steps || 8;
      for (let i = 1; i <= steps; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps]); await sleep(24); }
      if (opt.wiggle) for (let i = 1; i <= 4; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [to.x + opt.wiggle.x * i / 4, to.y + opt.wiggle.y * i / 4]); await sleep(24); }
      await sleep(60);
      const peek = await dragTag(page);
      if (opt.esc) { await page.keyboard.press('Escape'); await sleep(40); }
      const end = opt.end || (opt.wiggle ? { x: to.x + opt.wiggle.x, y: to.y + opt.wiggle.y } : to);
      await page.evaluate(([x, y]) => window.__pd.up(x, y), [end.x, end.y]); await sleep(150);
      return peek;
    };
    const lib = async (page, id) => { await page.click('[data-ntab="lib"]'); await sleep(60); const b = await page.$(`.ed-lib-item[data-lib="${id}"]`); const r = await b.boundingBox(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; };

    const shots = path.join(os.tmpdir(), 'kol-v6-shots'); fs.mkdirSync(shots, { recursive: true });
    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    await page.evaluate(() => Editor._tab('design'));
    const o0 = await files(page);
    const HERO = 'section.kadisha-hero';
    const comp = (page, p, sel = HERO) => page.evaluate(([s, p]) => getComputedStyle(Editor._state.canvas.doc.querySelector(s)).getPropertyValue(p), [sel, p]);
    const pseudo = (page, p, sel = HERO) => page.evaluate(([s, p]) => getComputedStyle(Editor._state.canvas.doc.querySelector(s), '::before').getPropertyValue(p), [sel, p]);
    const height = page => page.evaluate(s => Editor._state.canvas.doc.querySelector(s).getBoundingClientRect().height, HERO);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });

    const H1 = 'h1.kadisha-hero-title'; let p;
    const rect = (page, s) => page.evaluate(s => { const r = Editor._state.canvas.doc.querySelector(s).getBoundingClientRect(); return { l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height }; }, s);
    const near = (a, b, d = 1) => Math.abs(a - b) <= d;

    // ═══ 1. "✥ מיקום חופשי" ביחס אל הפס ═══
    await page.evaluate(() => Editor._tab('adv'));
    await select(page, H1); await sleep(150);
    const r0 = await rect(page, H1);
    await page.evaluate(() => { const S = Editor._state, c = S.canvas; S.pos.pickRef = c.nodeOf(c.doc.querySelector('section.kadisha-hero')); });
    await page.click('[data-free-btn]'); await sleep(300);
    let f1 = await files(page), r1 = await rect(page, H1);
    const par = await page.evaluate(s => Editor._state.canvas.doc.querySelector(s).parentElement.className, H1);
    const cssH1 = (f1['css/page.css'].match(/[^}]*kadisha-hero-title[^{]*\{[^}]*\}/g) || []).join('\n');
    ok(/kadisha-hero\b/.test(par) && !/inner/.test(par) && f1['index.html'] !== o0['index.html'], 'ביחס אל הפס: הכותרת הועברה ב-HTML להיות ילד ישיר של section.kadisha-hero', par);
    ok(/position: absolute/.test(cssH1) && /inset-inline-start: [\d.]+%/.test(cssH1) && /inset-block-start: [\d.]+%/.test(cssH1), 'נכתב בכלל היעד: position:absolute + שני היסטים לוגיים באחוזים', cssH1);
    ok(near(r0.l, r1.l) && near(r0.t, r1.t) && near(r0.w, r1.w), 'האלמנט אינו קופץ — אותו מקום ואותו רוחב (±1px)', { r0, r1 });
    const tx = await page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    ok(/^מיקום חופשי · h1.* · ביחס אל section\.kadisha-hero/.test(tx) && await page.evaluate(() => Editor._state.tx[Editor._state.tx.length - 1].docs.length) >= 2, 'עסקה אחת עם שם (HTML + CSS): "מיקום חופשי · … · ביחס אל section.kadisha-hero"', tx);

    // ═══ 2. גרירה לפינה התחתונה של הפס ═══
    await select(page, H1); await sleep(150);
    const sec = await rect(page, 'section.kadisha-hero');
    await page.evaluate(([sec, r]) => { const S = Editor._state, c = S.canvas, o = document.querySelector('[data-ps-move]'), b = o.getBoundingClientRect(), s = c.scale;
      const f = (t, x, y) => o.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: x, clientY: y, pointerId: 1, pointerType: 'mouse', button: 0, buttons: 1 }));
      const x0 = b.left + 10, y0 = b.top + 10, dx = (sec.l + 20 - r.l) * s, dy = (sec.b - 20 - r.b) * s;
      f('pointerdown', x0, y0); for (let i = 1; i <= 8; i++) f('pointermove', x0 + dx * i / 8, y0 + dy * i / 8); f('pointerup', x0 + dx, y0 + dy); }, [sec, await rect(page, H1)]);
    await sleep(300);
    f1 = await files(page);
    const css2 = (f1['css/page.css'].match(/[^}]*kadisha-hero-title[^{]*\{[^}]*\}/g) || []).join('\n'), r2 = await rect(page, H1), sec2 = await rect(page, 'section.kadisha-hero');
    const be = /inset-block-end: ([\d.]+)%/.exec(css2), ie = /inset-inline-end: ([\d.]+)%/.exec(css2);
    const geo = await page.evaluate(() => { const s = Editor._state.canvas.doc.querySelector('section.kadisha-hero'); return { W: s.clientWidth, H: s.clientHeight, cl: s.clientLeft, ct: s.clientTop }; });
    const mh = await page.evaluate(s => { const cs = getComputedStyle(Editor._state.canvas.doc.querySelector(s)); return { mb: parseFloat(cs.marginBottom) || 0, ml: parseFloat(cs.marginLeft) || 0 }; }, H1);
    ok(!!be && !!ie && !/inset-block-start/.test(css2) && !/inset-inline-start/.test(css2), 'גרירה לפינה התחתונה (בעברית — שמאל למטה = סוף): נכתבו inset-block-end + inset-inline-end; ההיסטים הקודמים נמחקו', css2);
    if (be && ie) {
      const dB = sec2.b - geo.ct - r2.b - mh.mb, dL = r2.l - sec2.l - geo.cl - mh.ml;
      ok(near(dB, +be[1] / 100 * geo.H) && near(dL, +ie[1] / 100 * geo.W), 'המרחק הנמדד מהפינה ≤ 1px מהערך שנכתב', { dB, want: +be[1] / 100 * geo.H, dL, wantL: +ie[1] / 100 * geo.W });
    }
    await page.evaluate(() => Editor._state.canvas.setViewport(900)); await sleep(300);
    const r3 = await rect(page, H1), sec3 = await rect(page, 'section.kadisha-hero'), geo3 = await page.evaluate(() => { const s = Editor._state.canvas.doc.querySelector('section.kadisha-hero'); return { W: s.clientWidth, H: s.clientHeight }; });
    ok(be && ie && near(sec3.b - r3.b - mh.mb, +be[1] / 100 * geo3.H, 1.5) && near(r3.l - sec3.l - mh.ml, +ie[1] / 100 * geo3.W, 1.5), 'אחרי שינוי רוחב הקנבס (900px) — הכותרת עדיין בפינה (אחוזים)', { r3, sec3 });
    await page.evaluate(() => Editor._state.canvas.setViewport(Editor._state.canvas.viewport)); await page.evaluate(() => Editor._state.canvas.setViewport(1180)); await sleep(200);

    // ═══ 3. עוגן, חיצים, שכבות ═══
    await select(page, H1); await sleep(150);
    const r4 = await rect(page, H1);
    await page.click('[data-anc="center-center"]'); await sleep(300);
    f1 = await files(page); const r5 = await rect(page, H1);
    ok(/translate: (-?50%) -50%/.test(f1['css/page.css']) && near(r4.l, r5.l) && near(r4.t, r5.t), 'עוגן מרכז: 50% ו-translate — שינוי עוגן אינו מזיז את האלמנט', { r4, r5 });
    await page.evaluate(() => document.activeElement.blur()); await page.evaluate(() => Editor._state.canvas.frame.focus());
    for (let i = 0; i < 3; i++) await page.evaluate(() => Editor._state.pos.nudge(Editor._state.canvas.selected, 'ArrowDown', true));
    await sleep(600); const r6 = await rect(page, H1);
    ok(near(r6.t - r5.t, 30, 1.5), 'חיצים (Shift) — 10px כל לחיצה, נכתב אחרי השהיה', { d: r6.t - r5.t });
    await page.click('[data-layer-to="front"]'); await sleep(250);
    f1 = await files(page);
    ok(/z-index: \d+;/.test((f1['css/page.css'].match(/[^}]*kadisha-hero-title[^{]*\{[^}]*\}/g) || []).join('')), '"לחזית" — z-index נכתב');
    // ═══ 4. חזרה לזרימה, ביטול ═══
    await page.click('[data-free]'); await sleep(300);
    f1 = await files(page);
    ok(!/position: absolute/.test((f1['css/page.css'].match(/[^}]*kadisha-hero-title[^{]*\{[^}]*\}/g) || []).join('')) && await page.evaluate(s => getComputedStyle(Editor._state.canvas.doc.querySelector(s)).position, H1) === 'static', '"חזרה לזרימה" — position, ההיסטים וה-translate נמחקו');
    ok(await undoFiles(page, o0), 'ביטול עד ההתחלה — כל הקבצים זהים בייט בבייט');
    await select(page, H1); await sleep(100);
    await page.evaluate(() => { Editor._state.pos.pickRef = null; }); await page.click('[data-free-btn]'); await sleep(300);
    f1 = await files(page);
    ok(f1['index.html'] === o0['index.html'] && /position: absolute/.test(f1['css/page.css']), 'בלי "ביחס אל" — ביחס לממוקם הקרוב (.kadisha-hero-inner), בלי הזזה ב-HTML');
    ok(await undoFiles(page, o0), 'ביטול — זהים');
    // ═══ 5. פריסה וגודל ═══
    await select(page, 'section.kadisha-hero'); await sleep(150);
    p = await page.evaluate(() => ({ flex: !!document.querySelector('[data-sec="flex"]'), jc: [...document.querySelectorAll('[data-field="justify-content"] button')].map(b => b.title), size: !!document.querySelector('[data-sec="size"]') }));
    ok(p.flex && p.size && p.jc.some(t => /התחלה \(ימין\)/.test(t)), 'פריסה: הפס (flex, שורה, עברית) — "התחלה (ימין)" בציר הראשי; קבוצת "גודל"', p);
    await page.click('[data-field="justify-content"] button[data-v="flex-end"]'); await sleep(250);
    f1 = await files(page);
    ok(/justify-content: flex-end/.test(f1['css/page.css']) && await comp(page, 'justify-content') === 'flex-end', 'יישור בציר הראשי — נכתב לכלל היעד ונראה בקנבס');
    const h0 = (await rect(page, 'section.kadisha-hero')).h;
    await page.evaluate(() => { const b = document.querySelector('[data-size-h="s"]'), r = b.getBoundingClientRect(), f = (t, x, y) => b.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: x, clientY: y, pointerId: 1, pointerType: 'mouse', button: 0, buttons: 1 })); const x = r.left + r.width / 2, y = r.top + r.height / 2; f('pointerdown', x, y); for (let i = 1; i <= 5; i++) f('pointermove', x, y + i * 12); f('pointerup', x, y + 60); });
    await sleep(300);
    f1 = await files(page); const h1 = (await rect(page, 'section.kadisha-hero')).h;
    ok(/height: \d+px/.test(f1['css/page.css']) && h1 > h0 + 30, 'ידית גודל (התחתונה) — גרירה מגביהה את הפס; עסקה אחת בשחרור', { h0, h1 });
    ok(await undoFiles(page, o0), 'ביטול — זהים');
    p = await page.evaluate(() => { const b = document.querySelector('[data-size-h="e"]'); b.dispatchEvent(new PointerEvent('pointerenter')); return document.querySelector('.sz-tip')?.textContent || ''; });
    ok(/גודל · width/.test(p), 'ידית גודל אומרת איזה מאפיין ישתנה לפני הגרירה', p);
    await page.screenshot({ path: path.join(shots, 'v6-1920.png') });
    {
      const lc = await mk(390, 844), lp = await lc.newPage(); lp.on('pageerror', e => errors.push('390: ' + e.message));
      await openPage(lp, 'index.html'); await select(lp, H1); await sleep(150);
      const fb = await lp.$('[data-free-btn]'); if (fb) { await fb.tap(); await sleep(300); }
      const mvb = await lp.evaluate(() => { const b = document.querySelector('[data-ps-move]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return Math.min(r.width, r.height); });
      ok(!!fb && mvb >= 44 && await comp(lp, 'position', H1) === 'absolute', '390px: "✥ מיקום חופשי" במגע — ידית הגרירה ≥44px', { mvb });
      await lp.screenshot({ path: path.join(shots, 'v6-390.png') });
      await lc.close();
    }
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
