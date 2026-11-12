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
    // ═══ 6. V6b: ידית החלוקה בין עמודות — גרירה אמיתית (עכבר) ═══
    await page.evaluate(() => Editor._tab('design'));
    { const from = await lib(page, 'row2'), to = await at(page, 'section.kadisha-hero', 0.5, 1, -3); await drag(page, from, to); }
    await page.evaluate(() => Editor._tab('adv')); await sleep(100);
    await select(page, '.row-1'); await sleep(200);
    const fRow = await files(page);
    ok(/<div class="row-1"><div class="row-1-col"><\/div><div class="row-1-col"><\/div><\/div>/.test(fRow['index.html']), 'הכנה: "שורה של 2" מהספרייה (גרירה) — שתי עמודות ב-flex: 1 1 0');
    p = await page.evaluate(() => { const b = document.querySelector('[data-divider="0"]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); b.dispatchEvent(new PointerEvent('pointerenter')); return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width, h: r.height, title: b.title, tip: document.querySelector('.sz-tip')?.textContent || '', sp: !!document.querySelector('[data-sp-gap]:not([hidden])') }; });
    await page.screenshot({ path: path.join(shots, 'v6b-1920-divider.png') });
    ok(p && /^חלוקה · flex-grow לשניהם/.test(p.title) && /^חלוקה/.test(p.tip), 'רובריקת-שורה נבחרת: ידית חלוקה בצלע שבין העמודות; התווית בריחוף אומרת מה ישתנה (flex-grow לשניהם — הבסיס 0)', p);
    const colW = () => page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('.row-1 > div')].map(e => e.getBoundingClientRect().width));
    const w0 = await colW(), scl = await page.evaluate(() => Editor._state.canvas.scale);
    await page.mouse.move(p.x, p.y); await page.mouse.down(); await page.keyboard.down('Shift');
    for (let i = 1; i <= 10; i++) { await page.mouse.move(p.x - (w0[0] + w0[1]) * 0.21 * scl * i / 10, p.y); await sleep(20); }
    const liveTip = await page.evaluate(() => document.querySelector('.sz-tip:not([hidden])')?.textContent || '');
    await page.mouse.up(); await page.keyboard.up('Shift'); await sleep(300);
    let fD = await files(page); const w1 = await colW(), pct1 = w1[0] / (w1[0] + w1[1]) * 100;
    ok(/^70% · 30%/.test(liveTip), 'בזמן הגרירה — האחוזים רצים ליד הסמן; Shift — צעדים של 5% (70% · 30%)', liveTip);
    ok(Math.abs(pct1 - 70) < 0.6 && /flex-grow: 1\.4;/.test(fD['css/page.css']) && /flex-grow: 0\.6;/.test(fD['css/page.css']), 'בשחרור: 70% / 30% בקנבס, ו-flex-grow 1.4 / 0.6 (הסכום נשמר)', { pct1, css: fD['css/page.css'].slice(-300) });
    ok(/class="row-1-col row-1-box"/.test(fD['index.html']) && /class="row-1-col row-1-box-2"/.test(fD['index.html']) && /\.row-1-col \{[^}]*flex: 1 1 0;/.test(fD['css/page.css']), 'הכלל המשותף (.row-1-col) לא נגע — לכל עמודה מחלקה משלה, HTML + CSS בעסקה אחת', fD['index.html'].slice(fD['index.html'].indexOf('row-1') - 12, fD['index.html'].indexOf('row-1') + 140));
    ok(/^חלוקה · div\.row-1-col.* · 70% \/ 30%$/.test(await lastTx(page)) && (await st(page)).inequiv === 0, 'עסקה אחת עם שם ("חלוקה · … · 70% / 30%"); המודל שקול', await lastTx(page));
    // ידית הרווח (V3) של העמודה — כותבת רווח בלבד, לא flex
    await select(page, '.row-1 > div'); await sleep(200);
    const fBefore = await files(page);
    p = await page.evaluate(() => { const b = document.querySelector('[data-sp-gap]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, title: b.title, dv: !!document.querySelector('[data-divider]:not([hidden])') }; });
    if (p) { await page.mouse.move(p.x, p.y); await page.mouse.down(); for (let i = 1; i <= 6; i++) { await page.mouse.move(p.x - i * 5, p.y); await sleep(20); } await page.mouse.up(); await sleep(250); }
    fD = await files(page); const dCss = fD['css/page.css'].replace(fBefore['css/page.css'], '');
    const changedCss = fD['css/page.css'] !== fBefore['css/page.css'], diffLines = fD['css/page.css'].split('\n').filter(l => !fBefore['css/page.css'].split('\n').includes(l));
    const decls = t => (t.match(/[\w-]+: [^;]+;/g) || []).sort().join('|'), newDecl = decls(fD['css/page.css']).split('|').filter(d => !decls(fBefore['css/page.css']).split('|').includes(d));
    ok(p && !p.dv && changedCss && newDecl.length && newDecl.every(d => /^(margin|gap|row-gap|column-gap)/.test(d)), 'ידית הרווח של V3 (ברצועה, כשעמודה נבחרת) — כותבת רווח בלבד; ידית החלוקה אינה מוצגת אז', { p, newDecl, diffLines });
    ok(await undoFiles(page, fRow), 'ביטול — חזרה לשורה של 2 כמו שהיא');
    // בסיס שאינו 0 — flex-basis באחוזים לזה שנגרר בלבד
    await page.evaluate(() => { const S = Editor._state, c = S.canvas, n = c.nodeOf(c.doc.querySelector('.row-1 > div')), t = S.adv.target(n, S.styler.targets(n)); S.lay.write(new Map([[n, [['flex', '0 1 50%', t]]]]), 'הכנה · בסיס 50%'); });
    await select(page, '.row-1'); await sleep(200);
    p = await page.evaluate(() => { const b = document.querySelector('[data-divider="0"]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, title: b.title }; });
    { const fb = await files(page), cw = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector('.row-1'); return e.clientWidth; });
      if (p) { await page.mouse.move(p.x, p.y); await page.mouse.down(); await page.keyboard.down('Shift'); for (let i = 1; i <= 8; i++) { await page.mouse.move(p.x - cw * 0.1 * scl * i / 8, p.y); await sleep(20); } await page.mouse.up(); await page.keyboard.up('Shift'); await sleep(300); }
      const fa = await files(page), nd = decls => (decls.match(/[\w-]+: [^;]+;/g) || []), add = nd(fa['css/page.css']).filter(d => !nd(fb['css/page.css']).includes(d)), w = await colW();
      ok(p && /flex-basis % ל-div\.row-1-col/.test(p.title) && add.length === 1 && /^flex-basis: 60%;$/.test(add[0]) && Math.abs(w[0] / cw * 100 - 60) < 0.6, 'כשהבסיס אינו 0: ידית החלוקה כותבת flex-basis באחוזים (60%, Shift) רק לעמודה שנגררה', { p, add, w, cw }); }
    ok(await undoFiles(page, fRow), 'ביטול — זהים');

    // ═══ 7. V6b: grid — רשימת מסילות (הוספה / מחיקה / גרירה), וגרירת קו על הקנבס ═══
    await select(page, '.row-1'); await sleep(200);
    await page.selectOption('[data-field="display"] select', 'grid'); await sleep(250);
    ok(await comp(page, 'display', '.row-1') === 'grid' && await page.isVisible('[data-track-list="grid-template-columns"]'), 'display ← grid: נכתב, וקבוצת "רשת" עם רשימת מסילות');
    for (let i = 0; i < 3; i++) { await page.click('[data-track-add="grid-template-columns"]'); await sleep(200); }
    fD = await files(page);
    ok(/\.row-1 \{[^}]*grid-template-columns: 1fr 1fr 1fr;/.test(fD['css/page.css']), '"+ מסילה" ×3 — grid-template-columns: 1fr 1fr 1fr', fD['css/page.css'].slice(-260));
    await page.fill('[data-track-list="grid-template-columns"] [data-track-i="2"]', '120px'); await page.press('[data-track-list="grid-template-columns"] [data-track-i="2"]', 'Enter'); await sleep(250);
    // גרירה (⠿) של המסילה השלישית למקום הראשון
    { const g = await page.$('[data-track-grip="2"]'), gb = await g.boundingBox(), t0 = await (await page.$('[data-track-grip="0"]')).boundingBox();
      await page.mouse.move(gb.x + gb.width / 2, gb.y + gb.height / 2); await page.mouse.down(); for (let i = 1; i <= 6; i++) { await page.mouse.move(gb.x + gb.width / 2, gb.y + gb.height / 2 + (t0.y - gb.y - 4) * i / 6); await sleep(20); } await page.mouse.up(); await sleep(250); }
    fD = await files(page);
    ok(/grid-template-columns: 120px 1fr 1fr;/.test(fD['css/page.css']) && /^רשת|עמודות · div\.row-1 · סדר · מסילה 3 ← 1/.test(await lastTx(page)), 'ערך חופשי (120px) למסילה, וגרירת ⠿ — המסילה עברה לראש הרשימה; עסקה עם שם', { css: fD['css/page.css'].slice(-200), tx: await lastTx(page) });
    await page.click('[data-track-del="1"]'); await sleep(250);
    fD = await files(page);
    ok(/grid-template-columns: 120px 1fr;/.test(fD['css/page.css']), '✕ — מחיקת מסילה', fD['css/page.css'].slice(-200));
    p = await page.evaluate(() => { const b = document.querySelector('[data-grid-line="x0"]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, title: b.title, ro: b.classList.contains('ro') }; });
    await page.screenshot({ path: path.join(shots, 'v6b-1920-grid.png') });
    ok(p && !p.ro && /עמודה 1 \(120px\) — גרירה משנה את גודלה/.test(p.title), 'על הקנבס: קו בין המסילות, עם תווית ("מסילה · grid-template-columns · עמודה 1 (120px)")', p || await page.evaluate(() => { const S = Editor._state, L = S.lay; try { const cs0 = getComputedStyle(S.canvas.elOf(S.canvas.selected)); return { gtc: cs0.gridTemplateColumns, gtr: cs0.gridTemplateRows, decl: S.adv.curDeclared(S.canvas.selected, "grid-template-columns"), on: L.on(), n: S.canvas.label(S.canvas.selected), gl: L.gridLines(S.canvas.selected).length, els: [...document.querySelectorAll('.gt-line')].map(b => b.outerHTML.slice(0, 200)) }; } catch (e) { return e.stack; } }));
    const c0 = await page.evaluate(() => getComputedStyle(Editor._state.canvas.doc.querySelector('.row-1')).gridTemplateColumns.split(' ').map(parseFloat));
    if (p) { await page.mouse.move(p.x, p.y); await page.mouse.down(); for (let i = 1; i <= 8; i++) { await page.mouse.move(p.x - 80 * scl * i / 8, p.y); await sleep(20); } await page.mouse.up(); await sleep(300); }
    fD = await files(page); const c1 = await page.evaluate(() => getComputedStyle(Editor._state.canvas.doc.querySelector('.row-1')).gridTemplateColumns.split(' ').map(parseFloat));
    ok(/grid-template-columns: 200px 1fr;/.test(fD['css/page.css']) && Math.abs(c1[0] - 200) < 1 && /^מסילה · div\.row-1 · עמודה 1 · 200px 1fr$/.test(await lastTx(page)), 'גרירת הקו 80px (בעברית — שמאלה) — המסילה הראשונה 120px ← 200px, היחידה נשמרת; עסקה אחת', { c0, c1, tx: await lastTx(page) });
    await page.fill('[data-track-list="grid-template-columns"] [data-track-i="0"]', '1fr'); await page.press('[data-track-list="grid-template-columns"] [data-track-i="0"]', 'Enter'); await sleep(250);
    p = await page.evaluate(() => { const b = document.querySelector('[data-grid-line="x0"]'); const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
    await page.mouse.move(p.x, p.y); await page.mouse.down(); for (let i = 1; i <= 8; i++) { await page.mouse.move(p.x + (c1[0] + c1[1]) * 0.1 * scl * i / 8, p.y); await sleep(20); } await page.mouse.up(); await sleep(300);
    fD = await files(page);
    ok(/grid-template-columns: 0\.8fr 1\.2fr;/.test(fD['css/page.css']), 'fr ← fr: גרירת הקו מחלקת מחדש את הסכום (0.8fr 1.2fr)', fD['css/page.css'].slice(-200));
    await page.fill('[data-track-list="grid-template-columns"] [data-track-i="0"]', 'repeat(2, 1fr)'); await page.press('[data-track-list="grid-template-columns"] [data-track-i="0"]', 'Enter'); await sleep(250);
    p = await page.evaluate(() => ({ kinds: [...document.querySelectorAll('[data-track-list="grid-template-columns"] .gt-row:not(.add)')].map(r => r.dataset.kind), ro: [...document.querySelectorAll('.gt-line:not([hidden])')].map(b => b.classList.contains('ro')) }));
    ok(p.kinds[0] === 'repeat' && p.ro.length >= 1 && p.ro.every(Boolean), 'repeat() — מוצג כקבוצה אחת ברשימה, נערך כמו שהוא; הקווים על הקנבס אינם נגררים (אין ידית שנגררת ואינה משנה דבר)', p);
    ok(await undoFiles(page, fRow) && (await st(page)).inequiv === 0, 'ביטול עד השורה — זהים; המודל שקול');

    // ═══ 8. V6b: הצמדה למרווח שווה בין שלושה ═══
    await undoFiles(page, o0);
    await select(page, '.kadisha-hero-inner > :last-child'); await sleep(150);
    await page.evaluate(() => { Editor._state.pos.pickRef = null; }); await page.click('[data-free-btn]'); await sleep(300);
    const SUB = '.kadisha-hero-inner > :last-child';
    const eqGeo = await page.evaluate(s => { const d = Editor._state.canvas.doc, e = d.querySelector(s), ref = e.offsetParent, kids = [...ref.children].filter(k => k !== e && k.getClientRects().length).map(k => k.getBoundingClientRect()).filter(b => b.width > 0); kids.sort((a, b) => a.top - b.top); let A = null, B = null; for (let i = kids.length - 2; i >= 0 && !A; i--) if (kids[i + 1].top - kids[i].bottom > 4) { A = kids[i]; B = kids[i + 1]; } const r = e.getBoundingClientRect(); return { want: B.bottom + (B.top - A.bottom), top: r.top, gap: B.top - A.bottom }; }, SUB);
    {
      const h = await page.evaluate(() => { const b = document.querySelector('[data-ps-move]').getBoundingClientRect(); return { x: b.left + 10, y: b.top + 10 }; });
      const dy = (eqGeo.want - eqGeo.top + 0.5) * scl;   // חצי פיקסל מהמקום השווה — בתוך סף ההצמדה
      await page.mouse.move(h.x, h.y); await page.mouse.down(); for (let i = 1; i <= 8; i++) { await page.mouse.move(h.x, h.y + dy * i / 8); await sleep(20); }
      p = await page.evaluate(() => [...document.querySelectorAll('.ps-eq')].map(x => x.textContent));
      await page.mouse.up(); await sleep(300);
    }
    const top2 = await page.evaluate(s => Editor._state.canvas.doc.querySelector(s).getBoundingClientRect().top, SUB);
    ok(p.length === 2 && p.every(t => t === '=' + Math.round(eqGeo.gap)) && Math.abs(top2 - eqGeo.want) < 1, 'גרירה ליד "מרווח שווה": ההצמדה מניחה את האלמנט באותו מרווח מהאח הקודם כמו בין שני האחים, ושני הקטעים מסומנים "=" בזמן הגרירה', { p, top2, eqGeo });
    ok(await undoFiles(page, o0), 'ביטול — זהים');
    // ═══ 9. התרחיש המלא (03 §7.3), צעדים 1–10 ברצף אחד, בגרירה ובלחיצה אמיתיות ═══
    await undoFiles(page, o0);
    const tab = async id => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(150); };
    const fld = (id, phys) => '.ed-side-body.sg[aria-label="' + (id === 'adv' ? 'מתקדם' : 'עיצוב') + '"] [data-field="' + phys + '"]';
    const typeIn = async (id, phys, v) => { const q = fld(id, phys) + ' .sg-ctl input'; await page.fill(q, v); await page.press(q, 'Enter'); await sleep(250); };
    const selEl = () => page.evaluate(() => { const c = Editor._state.canvas, n = c.selected; return n ? c.elOf(n) : null; });
    const LEAVES = '.kadisha-hero-inner :is(h1, p, span):not(:has(*)):not(:empty):not(:is(a, button) *)';
    // צעד 1 — לחיצה (עכבר) על כל אחת משש הכותרות
    const n6 = await page.evaluate(q => Editor._state.canvas.doc.querySelectorAll(q).length, LEAVES); let hit = 0;
    for (let i = 0; i < n6; i++) {
      const pt = await page.evaluate(([q, i]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(q)[i]; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width / 2) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; }, [LEAVES, i]);
      await page.mouse.click(pt.x, pt.y); await sleep(120);
      if (await page.evaluate(([q, i]) => { const c = Editor._state.canvas; return c.elOf(c.selected) === c.doc.querySelectorAll(q)[i]; }, [LEAVES, i])) hit++;
    }
    ok(n6 === 6 && hit === 6, 'צעד 1: לחיצה בעכבר על כל אחת משש הכותרות שעל התמונה — כל אחת נבחרת בנפרד', { n6, hit, all: await page.evaluate(q => [...Editor._state.canvas.doc.querySelectorAll(q)].map(e => e.localName + '.' + e.className + ':' + e.textContent.trim().slice(0, 20)), LEAVES) });
    // צעד 2 — הכותרת בצרפתית אל מעל הכותרת בעברית
    { const from = await at(page, '.kadisha-hero-eyebrow-line'), to = await at(page, 'h1.kadisha-hero-title', 0.5, 0.2, 0, false); await drag(page, from, to); }
    let sc = await src(page);
    ok(/<div class="kadisha-hero-inner">\n      <span class="kadisha-hero-eyebrow-line">La Voix du Maroc<\/span>\n      <h1 /.test(sc), 'צעד 2: גרירה — הכותרת בצרפתית לפני הכותרת בעברית');
    // צעד 3 — כותרת שביעית, מקלידים בה, גודל וצבע
    await select(page, 'p.kadisha-hero-subtitle-main'); await page.click('[data-ntab="lib"]'); await sleep(60);
    await page.click('.ed-lib-item[data-lib="heading"]'); await sleep(250);
    await page.keyboard.type('הכותרת השביעית'); await page.keyboard.press('Escape'); await sleep(300);
    const NEW = await page.evaluate(() => { const e = [...Editor._state.canvas.doc.querySelectorAll('.kadisha-hero-inner *')].find(x => x.textContent.trim() === 'הכותרת השביעית'); return e ? e.localName + (e.className ? '.' + e.className.trim().split(/\s+/).join('.') : '') : null; });
    const NEWSEL = await page.evaluate(() => { const all = [...Editor._state.canvas.doc.querySelectorAll('.kadisha-hero-inner > *')], i = all.findIndex(x => x.textContent.trim() === 'הכותרת השביעית'); return i < 0 ? 'nope' : '.kadisha-hero-inner > :nth-child(' + (i + 1) + ')'; });
    await select(page, NEWSEL); await tab('design');
    await page.evaluate(q => { const s = document.querySelector(q); const o = [...s.options].findIndex(x => /רק האלמנט|style=""/.test(x.textContent)); if (o >= 0) { s.selectedIndex = o; s.dispatchEvent(new Event('change', { bubbles: true })); } }, '.ed-side-body.sg[aria-label="עיצוב"] [data-target]'); await sleep(150);
    await typeIn('design', 'font-size', '40px'); await typeIn('design', 'color', '#ffcc00');
    ok(NEW && await comp(page, 'font-size', NEWSEL) === '40px' && await comp(page, 'color', NEWSEL) === 'rgb(255, 204, 0)', 'צעד 3: כותרת שביעית — מקלידים בה במקום, גודל 40px וצבע', { NEW, NEWSEL, src: (await src(page)).split('kadisha-hero-inner')[1]?.slice(0, 600) });
    const newFs = () => page.evaluate(() => { const e = [...Editor._state.canvas.doc.querySelectorAll('*')].find(x => x.children.length === 0 && x.textContent.trim() === 'הכותרת השביעית'); return e ? getComputedStyle(e).fontSize : null; });
    // צעד 4 — ידית המרווח (עכבר) עד אפס, ואחר כך 24
    await tab('adv'); await select(page, 'h1.kadisha-hero-title'); await sleep(200);
    const gdist = () => page.evaluate(() => { const d = Editor._state.canvas.doc, a = d.querySelector('h1.kadisha-hero-title'), b = a.nextElementSibling; return Math.round((b.getBoundingClientRect().top - a.getBoundingClientRect().bottom) * 100) / 100; });
    { const g = await page.evaluate(() => { const b = document.querySelector('[data-sp-gap]'); const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; }), d0 = await gdist();
      await page.mouse.move(g.x, g.y); await page.mouse.down(); for (let i = 1; i <= 10; i++) { await page.mouse.move(g.x, g.y - d0 * scl * i / 10); await sleep(16); } await page.mouse.up(); await sleep(250); }
    const z4 = await gdist();
    { const g = await page.evaluate(() => { const b = document.querySelector('[data-sp-gap]'); const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; }); await page.mouse.click(g.x, g.y); await sleep(150); }
    await page.fill('[data-gapbar] [data-bar-input]', '24'); await page.press('[data-gapbar] [data-bar-input]', 'Enter'); await sleep(250);
    ok(z4 === 0 && await gdist() === 24, 'צעד 4: גרירת ידית המרווח עד 0, ואחר כך מקלידים 24 — המרחק הנמדד 0 ואז 24px', { z4, d: await gdist() });
    // צעד 5 — מיקום חופשי ביחס אל הפס, ולפינה (גרירה בעכבר)
    await select(page, '.kadisha-hero-credit'); await sleep(200);
    await page.evaluate(() => { const s = document.querySelector('[data-free-ref]'); const i = [...s.options].findIndex(o => /^section\.kadisha-hero/.test(o.textContent)); s.selectedIndex = i; s.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(100);
    await page.click('[data-free-btn]'); await sleep(300);
    { const r = await rect(page, '.kadisha-hero-credit'), sc5 = await rect(page, 'section.kadisha-hero'), b = await page.evaluate(() => { const r = document.querySelector('[data-ps-move]').getBoundingClientRect(); return { x: r.left + 8, y: r.top + 8 }; });
      const dx = (sc5.l + 24 - r.l) * scl, dy = (sc5.b - 24 - r.b) * scl;
      await page.mouse.move(b.x, b.y); await page.mouse.down(); for (let i = 1; i <= 10; i++) { await page.mouse.move(b.x + dx * i / 10, b.y + dy * i / 10); await sleep(20); } await page.mouse.up(); await sleep(300); }
    { const css = ((await files(page))['css/page.css'].match(/[^}]*kadisha-hero-credit[^{]*\{[^}]*\}/g) || []).join('\n'), par5 = await page.evaluate(() => Editor._state.canvas.doc.querySelector('.kadisha-hero-credit').parentElement.className);
      const r = await rect(page, '.kadisha-hero-credit'), s5 = await rect(page, 'section.kadisha-hero');
      ok(/kadisha-hero\b/.test(par5) && !/inner/.test(par5) && /inset-block-end: [\d.]+%/.test(css) && /inset-inline-end: [\d.]+%/.test(css) && s5.b - r.b < 40 && r.l - s5.l < 40, 'צעד 5: "✥ מיקום חופשי" ביחס אל הפס, וגרירה בעכבר לפינה התחתונה של התמונה — עוגן סוף / למטה', { par5, css, r, s5 }); }
    // צעד 6 — מסירים את התמונה
    await select(page, HERO); await sleep(150); const hh6 = await height(page);
    await page.click('[data-bgt="remove"]'); await sleep(250);
    ok(await comp(page, 'background-image') === 'none' && Math.abs(await height(page) - hh6) < 1, 'צעד 6: 🗑 — התמונה הוסרה, הפס נשאר בגובהו');
    // צעד 7 — תמונה אחרת (העלאה מבורר הקבצים), מיקום וגודל
    await tab('adv'); await select(page, HERO); await sleep(150);
    if (!(await page.locator('[data-bg-add]:visible').count())) await tab('design');
    await page.locator('[data-bg-add]:visible').first().click(); await page.waitForSelector('#sheet[open] [data-pick-file]', { state: 'attached' });
    await page.setInputFiles('#sheet[open] [data-pick-file]', { name: 'other-hero.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', 'base64') }); await sleep(900);
    for (const [p7, v7] of [['background-position', '30% 40%'], ['background-size', '80% auto']]) { const q = await page.$('[data-field="' + p7 + '"] .sg-ctl input'); if (q) { await q.fill(v7); await q.press('Enter'); await sleep(250); } }
    { const css = (await files(page))['css/page.css'];
      ok(/background-image: url\('\.\.\/media\/other-hero\.png'\);/.test(css) && await comp(page, 'background-position') === '30% 40%' && /80%/.test(await comp(page, 'background-size')), 'צעד 7: תמונה אחרת (העלאה דרך הבורר), ולה מיקום 30% 40% וגודל 80%', { pos: await comp(page, 'background-position'), size: await comp(page, 'background-size') }); }
    // צעד 8 — הפס כולו אל מתחת לשורת החיפוש (גרירה בידית הגרירה של הסרגל)
    await page.evaluate(() => Editor._tab('design')); await select(page, HERO); await sleep(150);
    { const to = await at(page, '.kadisha-lexicon-controls', 0.5, 1, -3); await sleep(80); const gb = await (await page.$('.ed-seltools [data-grip]')).boundingBox();
      await drag(page, { x: gb.x + gb.width / 2, y: gb.y + gb.height / 2 }, to, { steps: 12, wiggle: { x: 40, y: 0 }, shell: true }); }
    sc = await src(page);
    ok(sc.indexOf('kadisha-lexicon-controls') < sc.indexOf('<section class="kadisha-hero ') && (await st(page)).inequiv === 0, 'צעד 8: גרירה — הפס כולו מתחת לשורת החיפוש');
    // צעד 9 — "קוד": כל שינוי נראה בקבצים כשורה שהשתנתה; שינוי מספר בקוד ← הקנבס
    const f9 = await files(page), lines = (a, b) => { const A = new Set(a.split('\n')); return b.split('\n').filter(l => !A.has(l)); };
    const dh = lines(o0['index.html'], f9['index.html']), dc = lines(o0['css/page.css'], f9['css/page.css']);
    const seen = { 2: dh.some(l => /La Voix du Maroc/.test(l)), 3: dh.some(l => /הכותרת השביעית/.test(l)) && dc.concat(dh).some(l => /font-size: 40px/.test(l)) && dc.concat(dh).some(l => /#ffcc00/.test(l)), 4: dc.some(l => /24px/.test(l)), 5: dc.some(l => /inset-block-end/.test(l)), 6: !/background-image: url\('[^']*'\)/.test(f9['css/page.css'].replace(/url\('\.\.\/media\/other-hero\.png'\)/, '')), 7: dc.some(l => /other-hero\.png/.test(l)) && dc.some(l => /30% 40%/.test(l)) && dc.some(l => /80% auto/.test(l)), 8: dh.some(l => /<section class="kadisha-hero /.test(l)) };
    ok(Object.values(seen).every(Boolean), 'צעד 9: כל אחד מהשינויים (2–8) נראה בקבצים כשורה שהשתנתה', { seen, dh: dh.slice(0, 12), dc: dc.slice(0, 12) });
    await page.click('[data-split]'); await page.waitForSelector('.ed-code .monaco-editor .view-lines'); await sleep(300);
    const where = f9['css/page.css'].includes('font-size: 40px') ? 'css/page.css' : 'index.html';
    await page.evaluate(w => { const P = Project._state, S = Editor._state; const id = [...P.docs.entries()].find(([, d]) => d.path === w || d.file?.path === w)?.[0] ?? (w === S.file.path ? S.file.id : null); if (w !== S.file.path) Project.splitEditor(document.querySelector('.ed-code'), id); const ed = P.split, m = ed.getModel(), i = m.getValue().indexOf('font-size: 40px') + 'font-size: '.length; ed.focus(); ed.setSelection(monaco.Selection.fromPositions(m.getPositionAt(i), m.getPositionAt(i + 2))); }, where);
    await page.keyboard.type('52'); await sleep(700);
    ok(await newFs() === '52px', 'צעד 9: בעורך הקוד (' + where + ') מקלידים 52 במקום 40 — והקנבס מתעדכן', await newFs());
    await page.keyboard.press('Control+z'); await sleep(400); await page.click('[data-split]'); await sleep(150);
    // צעד 10 — ביטול עד ההתחלה
    let undos = 0; for (; undos < 60 && !same(await files(page), o0); undos++) { await page.evaluate(() => Editor._undo()); await sleep(40); }
    ok(same(await files(page), o0) && (await st(page)).inequiv === 0, 'צעד 10: "בטל" ×' + undos + ' — כל הקבצים זהים בייט בבייט למצב ההתחלה', undos);
    await page.screenshot({ path: path.join(shots, 'v6-1920.png') });
    {
      // הקבצים של הדסקטופ נשמרים בשרת (שמירה אוטומטית) — ה-390 נפתח רק אחרי שהביטול עד ההתחלה נשמר
      await undoFiles(page, o0);
      await page.waitForFunction(() => { const S = Editor._state, ds = [S.doc, ...[...S.sheets.values()].map(x => x.doc)]; return ds.every(d => !d.dirty && !d.saving); }, null, { timeout: 20000 });
      const lc = await mk(390, 844), lp = await lc.newPage(); lp.on('pageerror', e => errors.push('390: ' + e.message));
      await openPage(lp, 'index.html');
      ok(same(await files(lp), o0), '390: נפתח על הקבצים של ההתחלה (מה שהדסקטופ החזיר ושמר)');
      await select(lp, H1); await sleep(150);
      const fb = await lp.$('[data-free-btn]'); if (fb) { await fb.tap(); await sleep(300); }
      const mvb = await lp.evaluate(() => { const b = document.querySelector('[data-ps-move]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return Math.min(r.width, r.height); });
      ok(!!fb && mvb >= 44 && await comp(lp, 'position', H1) === 'absolute', '390px: "✥ מיקום חופשי" במגע — ידית הגרירה ≥44px', { mvb });
      // ── V6b במגע (390): צעד 2, צעד 5 (גרירת האלמנט הממוקם), ידית החלוקה וקו מסילה — באצבע ──
      /** גרירה באצבע על ידית במעטפת: כל האירועים לידית עצמה (כמו setPointerCapture), pointerType: touch. */
      const tdrag = (pg, sel, dx, dy) => pg.evaluate(async ([sel, dx, dy]) => { const b = document.querySelector(sel), r = b.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + Math.min(r.height / 2, 20); const f = (t, X, Y) => b.dispatchEvent(new PointerEvent(t, { bubbles: true, cancelable: true, clientX: X, clientY: Y, pointerId: 7, pointerType: 'touch', isPrimary: true, button: t === 'pointermove' ? -1 : 0, buttons: t === 'pointerup' ? 0 : 1 })); f('pointerdown', x, y); for (let i = 1; i <= 8; i++) { f('pointermove', x + dx * i / 8, y + dy * i / 8); await new Promise(r2 => setTimeout(r2, 20)); } f('pointerup', x + dx, y + dy); }, [sel, dx, dy]);
      const l0 = await files(lp); const lscl = await lp.evaluate(() => Editor._state.canvas.scale);
      { const r0 = await rect(lp, H1);
        await tdrag(lp, '[data-ps-move]', -30, 40); await sleep(300); const r1 = await rect(lp, H1);
        ok(Math.abs(r1.t - r0.t - 40 / lscl) < 3 && (await files(lp))['css/page.css'] !== l0['css/page.css'], '390 · צעד 5 במגע: גרירת האלמנט הממוקם באצבע — זז ונכתב בשחרור', { r0, r1 }); }
      for (let i = 0; i < 8 && /position: absolute/.test((await files(lp))['css/page.css']); i++) { await lp.evaluate(() => Editor._undo()); await sleep(60); }
      const lz = await files(lp);
      { const from = await at(lp, '.kadisha-hero-eyebrow-line'), to = await at(lp, 'h1.kadisha-hero-title', 0.5, 0.15, 0, false); await drag(lp, from, to, { touch: true, hold: 450, steps: 8 }); }
      ok(/<div class="kadisha-hero-inner">\n      <span class="kadisha-hero-eyebrow-line">La Voix du Maroc<\/span>\n      <h1 /.test(await src(lp)), '390 · צעד 2 במגע: לחיצה ארוכה וגרירה — הכותרת בצרפתית לפני הכותרת בעברית');
      await lp.evaluate(() => { const S = Editor._state, c = S.canvas, hero = c.nodeOf(c.doc.querySelector('section.kadisha-hero')), par = hero.parent; S.build.addRow(2, par, par.kids.indexOf(hero) + 1); }); await sleep(250);
      await lp.evaluate(() => Editor._tab('adv')); await select(lp, '.row-1'); await sleep(250);
      { const d = await lp.evaluate(() => { const b = document.querySelector('[data-divider="0"]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width, h: r.height }; });
        const w0 = await lp.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('.row-1 > div')].map(e => e.getBoundingClientRect().width));
        await lp.evaluate(() => { const c = Editor._state.canvas; c.doc.querySelector('.row-1').scrollIntoView({ block: 'center' }); c.draw(); }); await sleep(150); await lp.screenshot({ path: path.join(shots, 'v6b-390-divider.png') });
        if (d) { await tdrag(lp, '[data-divider="0"]', -(w0[0] + w0[1]) * 0.2 * lscl, 0); await sleep(300); }
        const w1 = await lp.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('.row-1 > div')].map(e => e.getBoundingClientRect().width)), css = (await files(lp))['css/page.css'];
        ok(d && d.w >= 44 && d.h >= 44 && w1[0] / (w1[0] + w1[1]) > 0.62 && /flex-grow: 1\.\d+;/.test(css), '390 · ידית החלוקה ≥44px, ובאצבע — העמודה הראשונה גדלה (~70%), flex-grow נכתב', { d, w0, w1 }); }
      await lp.evaluate(() => { const S = Editor._state, c = S.canvas, n = c.selected, t = S.adv.target(n, S.styler.targets(n)); S.lay.write(new Map([[n, [['display', 'grid', t], ['grid-template-columns', '1fr 1fr', t]]]]), 'הכנה · grid'); }); await sleep(250);
      { const l = await lp.evaluate(() => { const b = document.querySelector('[data-grid-line="x0"]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + Math.min(r.height / 2, 20), w: r.width }; });
        if (l) { await tdrag(lp, '[data-grid-line="x0"]', -60, 0); await sleep(300); }
        const css = (await files(lp))['css/page.css'];
        ok(l && l.w >= 44 && /grid-template-columns: 1\.\d+fr 0\.\d+fr;/.test(css), '390 · קו מסילה ≥44px, וגרירה באצבע — grid-template-columns נכתב (fr ← fr)', { l, css: (css.match(/grid-template-columns:[^;]*/) || [''])[0] }); }
      ok(await undoFiles(lp, lz), '390 · ביטול — זהים');
      await lp.screenshot({ path: path.join(shots, 'v6-390.png') });
      await lc.close();
    }
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
