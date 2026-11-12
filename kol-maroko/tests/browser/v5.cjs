/**
 * פרוסה V5 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/v5.cjs
 *
 * רקע של רובריקה, על index.html המוסב (העמוד האמיתי): צעדים 6–7 של התרחיש (03 §7.3) — לזהות את תמונת הרקע של הפס,
 * להסיר אותה (הפס נשאר בגובהו), להסיר גם את הכיסוי, לשים תמונה אחרת (מהמחשב — גרירה על הפס), ✥ להזיז ולהגדיל,
 * לשנות את ההכהיה; הזרקה בכתובת; ביטול עד ההתחלה — קבצים זהים. ובטלפון (390) — אותן פעולות במגע.
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
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v5-'));
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

    const shots = path.join(os.tmpdir(), 'kol-v5-shots'); fs.mkdirSync(shots, { recursive: true });
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

    // ═══ 1. לזהות ═══
    await select(page, HERO); await sleep(150);
    let p = await page.evaluate(() => { const q = s => document.querySelector(s); return { tools: !!q('[data-bg-tools]') && q('[data-bg-tools]').isConnected, sec: !!q('[data-sec="bg"]'), what: q('[data-bg-what]')?.textContent || '', src: q('[data-bg-src]')?.textContent || '', cover: q('[data-bg-cover]')?.dataset.bgCover || '', vars: [...document.querySelectorAll('[data-cover-var]')].map(x => x.dataset.coverVar), pos: q('[data-field="background-position"] .sg-src')?.textContent || '', size: q('[data-field="background-size"] input')?.value || '' }; });
    ok(p.tools && p.sec && /תמונת רקע של הרובריקה, לא אלמנט תמונה/.test(p.what) && /css\/page\.css:\d+/.test(p.src), 'צעד 6: בחירת הפס — "🖼 תמונת רקע" בסרגל, ובפאנל: "זו תמונת רקע של הרובריקה, לא אלמנט תמונה", והמקור (css/page.css, שורה)', p);
    ok(p.cover === 'before' && p.vars.includes('--ka-hero-overlay'), 'הכיסוי (::before) מזוהה כשורה "כיסוי", והשקיפות שבמשתנה --ka-hero-overlay — מחוון', p);
    ok(/\.kadisha-hero ·/.test(p.pos) && p.size === 'cover', 'מיקום וגודל שבאים מקיצור background בכלל אחר — המקור נמצא (לא "ברירת מחדל של הדפדפן")', p);
    const sel1 = await page.evaluate(() => { const c = Editor._state.canvas, d = c.doc.querySelector('section.kadisha-hero'), r = d.getBoundingClientRect(); const inner = d.querySelector('.kadisha-hero-inner').getBoundingClientRect(); const x = r.left + 8, y = r.top + 8; const e = c.doc.elementFromPoint(x, y); const n = c.pick ? c.pick(e) : c.nodeOf(e); return n ? c.label(n) : ''; });
    ok(/section\.kadisha-hero/.test(sel1), 'לחיצה על שטח התמונה שאין עליו אלמנט — בוחרת את הרובריקה', sel1);

    // ═══ 2. להסיר ═══
    const h0 = await height(page);
    await page.click('[data-bgt="remove"]'); await sleep(250);
    let f1 = await files(page);
    p = await page.evaluate(() => ({ after: !!document.querySelector('[data-bg-after]'), cover: !!document.querySelector('[data-after-cover]'), color: document.querySelector('[data-after-color]')?.textContent || '' }));
    ok(!/background-image/.test(f1['css/page.css']) && /--ka-hero-overlay/.test(f1['css/page.css']) && await comp(page, 'background-image') === 'none' && Math.abs(await height(page) - h0) < 1 && f1['index.html'] === o0['index.html'], 'צעד 6: 🗑 הסר — background-image נמחק מ-css/page.css; הפס נשאר בגובהו, ה-HTML לא נגע', { h0, css: f1['css/page.css'].slice(0, 400) });
    ok(/^רקע · הסרת התמונה · section\.kadisha-hero/.test(await lastTx(page)), 'עסקה אחת עם שם: "רקע · הסרת התמונה · section.kadisha-hero"', await lastTx(page));
    ok(p.after && p.cover && /var\(--ka-navy\)/.test(p.color), 'מה שנשאר נאמר מיד: "נשאר כיסוי כהה (::before)" ו"נשאר צבע הרקע var(--ka-navy)"', p);
    await page.click('[data-after-cover] button'); await sleep(250);
    let f2 = await files(page);
    ok(!/--has-image::before/.test(f2[Object.keys(f2).find(k => /::before/.test(o0[k] || '') && /has-image::before/.test(o0[k]))] || '') && await pseudo(page, 'content') === 'none', '"הסר גם אותו" — הכלל של ה-::before נמחק, והכיסוי נעלם', Object.keys(f2));
    ok(await undoFiles(page, o0), 'ביטול עד ההתחלה — כל הקבצים זהים בייט בבייט');

    // ═══ 3. להחליף — מהמחשב, בגרירה על הפס ═══
    await select(page, HERO); await sleep(100);
    await page.evaluate(async () => { const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg=='; const bin = Uint8Array.from(atob(b64), c => c.charCodeAt(0)); const f = new File([bin], 'new hero.png', { type: 'image/png' }); const c = Editor._state.canvas; window.__drop = Editor._drop(f, c.nodeOf(c.doc.querySelector('.kadisha-hero-inner'))); });
    await page.waitForSelector('[data-ask="bg"]', { timeout: 8000 }); await page.click('[data-ask="bg"]'); await page.evaluate(() => window.__drop); await sleep(400);
    f1 = await files(page);
    const bi = await comp(page, 'background-image'), got = /url\("([^"]+)"\)/.exec(bi), st200 = got ? await page.evaluate(u => fetch(u).then(r => r.status), got[1]) : 0;
    ok(/background-image: url\('\.\.\/media\/new-hero\.png'\);/.test(f1['css/page.css']) && f1['index.html'] === o0['index.html'] && st200 === 200, 'צעד 7: קובץ שנגרר על הפס ← "כרקע" — רק ה-url() מתחלף, יחסית ל-css/ (../media/…) ובמרכאות של הקובץ; בקנבס נטען (200)', { bi, st200, css: f1['css/page.css'].slice(0, 300) });
    const pv = await page.evaluate(async () => { const S = Editor._state; try { const r = await api('preview_url', { path: 'index.html' }); return r; } catch (e) { return e.message; } });
    void pv;
    // ═══ 4. ✥ הזזה ═══
    await select(page, HERO); await sleep(100);
    const pos0 = await comp(page, 'background-position');
    await page.click('[data-bgt="move"]'); await sleep(150);
    const box = await (await page.$('.bg-move')).boundingBox();
    await page.evaluate(() => { const o = document.querySelector('.bg-move'), r = o.getBoundingClientRect(), f = (t, x, y) => o.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: x, clientY: y, pointerId: 1, pointerType: 'mouse', button: 0, buttons: 1 })); f('pointerdown', r.left + 300, r.top + 100); for (let i = 1; i <= 6; i++) f('pointermove', r.left + 300 - i * 30, r.top + 100 - i * 10); f('pointerup', r.left + 120, r.top + 40); });
    await sleep(250);
    f1 = await files(page);
    const pos1 = await comp(page, 'background-position');
    ok(!!box && pos1 !== pos0 && /background-position: \d+% \d+%;/.test(f1['css/page.css']) && /^רקע · הזזה/.test(await lastTx(page)), '✥ גרירה בתוך הפס — background-position באחוזים, עסקה אחת בשחרור; הערך המחושב השתנה', { pos0, pos1, tx: await lastTx(page) });
    await page.evaluate(() => { const o = document.querySelector('.bg-move'); for (let i = 0; i < 5; i++) o.dispatchEvent(new WheelEvent('wheel', { bubbles: true, cancelable: true, deltaY: -100 })); });
    await sleep(800);
    f1 = await files(page);
    ok(/background-size: \d+% auto;/.test(f1['css/page.css']) && /%/.test(await comp(page, 'background-size')), 'גלגלת — background-size גדל (רוחב באחוזים), נכתב אחרי השהיה קצרה', await comp(page, 'background-size'));
    await page.focus('.bg-move'); await page.keyboard.press('Escape'); await sleep(80);
    ok(!(await page.$('.bg-move')), 'Esc — יוצא ממצב ההזזה');

    // ═══ 5. הכהיה ═══
    await select(page, HERO); await sleep(120);
    for (const v of ['0', '80']) { await page.evaluate(v => { const r = document.querySelector('[data-cover-var="--ka-hero-overlay"]'); r.value = v; r.dispatchEvent(new Event('input', { bubbles: true })); r.dispatchEvent(new Event('change', { bubbles: true })); }, v); await sleep(250); }
    f1 = await files(page);
    ok(/--ka-hero-overlay: \.8;/.test(f1['css/page.css']) && /0\.8/.test(await pseudo(page, 'background-color')), 'ההכהיה ל-0 ול-80% — המחוון כותב את הגדרת המשתנה ב-css/page.css; ה-::before בקנבס משתנה', { bg: await pseudo(page, 'background-color') });

    // ═══ 6. הזרקה בכתובת ═══
    const before = await files(page);
    for (const v of ['javascript:alert(1)', '</style><script>alert(1)</script>', 'data:text/html,x']) { await page.evaluate(v => { const i = document.querySelector('[data-bg-url]'); i.value = v; i.dispatchEvent(new Event('change', { bubbles: true })); }, v); await sleep(120); }
    ok(same(before, await files(page)) && await page.evaluate(() => document.querySelector('[data-bg-url]').classList.contains('bad')), 'כתובת javascript: / </style> / data שאינו תמונה — נדחית, הקבצים לא נגעו');
    await page.evaluate(() => { const i = document.querySelector('[data-bg-url]'); i.value = "x');}body{color:red} .a{b:url('"; i.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(200);
    f1 = await files(page);
    const rulesOk = await page.evaluate(() => { const K = window.KolSrc, t = [...Editor._state.sheets.values()].find(x => x.path === 'css/page.css').doc.text(), m = K.parseCss(t); return { broken: K.cssAll(m).some(n => n.broken), body: K.cssAll(m).some(n => n.type === 'rule' && /^body$/.test(n.sel.trim())), color: getComputedStyle(Editor._state.canvas.doc.body).color }; });
    ok(!rulesOk.broken && !rulesOk.body && /url\('x%27%29;\}body\{color:red\}%20\.a\{b:url%28%27'\)/.test(f1['css/page.css']), 'סגירת \') ; } — מקודדת: אין כלל body חדש, הקובץ אינו נשבר', { rulesOk, line: (f1['css/page.css'].match(/background-image:[^\n]*/) || [''])[0] });
    ok(await undoFiles(page, o0), 'ביטול עד ההתחלה — כל הקבצים זהים בייט בבייט (אחרי החלפה, הזזה, גודל, הכהיה)');
    // ═══ 7. רובריקה בלי תמונה: ＋ תמונת רקע ═══
    await select(page, '.kadisha-lexicon-intro'); await sleep(120);
    ok(!!(await page.$('[data-bg-add]')) && !(await page.$('[data-bg-tools]')), 'רובריקה בלי תמונה: "＋ תמונת רקע" בפאנל, ובסרגל אין "תמונת רקע"');
    await page.evaluate(() => { const S = Editor._state; S.bg.usePath(S.canvas.selected, 'media/new-hero.png'); }); await sleep(250);
    f1 = await files(page);
    ok(/\.kadisha-lexicon-intro \{[^}]*background-image: url\('\.\.\/media\/new-hero\.png'\);[^}]*background-size: cover;[^}]*background-position: center;[^}]*background-repeat: no-repeat;/.test(f1['css/page.css']) && f1['index.html'] === o0['index.html'], '"＋ תמונת רקע" — לכלל היעד: background-image, ואיתו cover / center / no-repeat; הטקסט לא השתנה', f1['css/page.css'].slice(f1['css/page.css'].indexOf('lexicon-intro'), f1['css/page.css'].indexOf('lexicon-intro') + 300));
    ok(await undoFiles(page, o0), 'ביטול — זהים');
    await page.screenshot({ path: path.join(shots, 'v5-1920.png') });

    // ═══ 8. טלפון ═══
    {
      const lc = await mk(390, 844), lp = await lc.newPage(); lp.on('pageerror', e => errors.push('390: ' + e.message));
      await openPage(lp, 'index.html');
      await select(lp, HERO); await sleep(150);
      const tb = await lp.$('[data-bgt="move"]');

      if (tb) { await tb.tap(); await sleep(150); }
      const bt = await lp.evaluate(() => [...document.querySelectorAll('.bg-move-b')].map(b => { const r = b.getBoundingClientRect(); return Math.min(r.width, r.height); }));
      const posA = await comp(lp, 'background-position');
      if (bt.length) { await lp.tap('[data-mv="left"]'); await lp.tap('[data-mv="in"]'); await sleep(800); }
      const posB = await comp(lp, 'background-position');
      ok(!!tb && bt.length >= 7 && bt.every(x => x >= 44) && posA !== posB, '390px: "✥ הזז" במגע — כפתורי חיצים ו-− / + (≥44px), והערך המחושב השתנה', { bt, posA, posB });
      await lp.screenshot({ path: path.join(shots, 'v5-390.png') });
      await lc.close();
    }
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
