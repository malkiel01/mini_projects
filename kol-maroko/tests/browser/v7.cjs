/**
 * פרוסה V7 — מכשירים: בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/v7.cjs
 *
 * על index.html המוסב (העמוד האמיתי): בורר המכשיר (מ-kol.json), Ctrl+Shift+M ורוחב חופשי; בטלפון — גודל הכותרת 32 נכתב
 * ל-@media (max-width: 767px) מיד אחרי הכלל הבסיסי, ובמחשב נשאר 48; גרירה אמיתית בעכבר של ידית המרווח — לטלפון בלבד;
 * "עובר ממחשב" / "נכתב כאן"; הסתרה בטלפון ← נראית במחשב, ובקנבס — שקופה ומקווקוות; order רק בטלפון (גרירה ← "רק כאן");
 * @media שאינו מכשיר (782px); "רק האלמנט הזה" במכשיר; איפוס במכשיר (הכלל והבלוק נמחקים); "לכל המכשירים"; נקודות שבירה
 * (שינוי max מעדכן את ה-@media, עסקה אחת); ביטול עד ההתחלה — קבצים זהים. ובטלפון (390) — מגע, ידיות ≥44px.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v7-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.devs, p, { timeout: 30000 }); await sleep(200); };
    const files = page => page.evaluate(async () => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); const kf = Project.docs.byPath('kol.json'); if (kf) { const d = await Project.docs.open(kf.id); o['kol.json'] = d.text(); } return o; });
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const undoFiles = async (page, to) => { for (let i = 0; i < 80 && !same(await files(page), to); i++) { await page.evaluate(() => Editor._undo()); await sleep(40); } return same(await files(page), to); };
    const src = page => page.evaluate(() => Editor._state.doc.text());
    const css = async page => (await files(page))['css/page.css'];
    const select = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s), n = e && c.nodeOf(e); c.select(n); c.reveal(n); return !!n; }, sel);
    const tab = async (page, id) => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(150); };
    const body = id => '.ed-side-body.sg[aria-label="' + (id === 'adv' ? 'מתקדם' : 'עיצוב') + '"]';
    const field = (id, phys) => body(id) + ' [data-field="' + phys + '"]';
    const typeIn = async (page, id, phys, v) => { const q = field(id, phys) + ' .sg-ctl input'; await page.fill(q, v); await page.press(q, 'Enter'); await sleep(250); };
    const cs = (page, sel, prop) => page.evaluate(([s, p]) => { const c = Editor._state.canvas; return c.frame.contentWindow.getComputedStyle(c.doc.querySelector(s)).getPropertyValue(p); }, [sel, prop]);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const dev = page => page.evaluate(() => ({ id: Editor._state.device, vp: Editor._state.canvas.viewport, root: document.querySelector('.ed').dataset.devi, name: document.querySelector('[data-dev-name]')?.textContent || '', w: document.querySelector('[data-dev-width]')?.value, note: !document.querySelector('[data-dev-note]')?.hidden }));
    const clickDev = async (page, id) => { await page.click('[data-dev="' + id + '"]'); await sleep(250); };
    const devState = (page, id, phys) => page.evaluate(q => { const r = document.querySelector(q); return r ? { state: r.dataset.state, text: r.textContent, title: r.title } : null; }, field(id, phys) + ' [data-dev-state]');
    /** הבלוק @media (max-width: N) שמכיל את הבורר — או null. */
    const block = (text, max, sel) => { const re = new RegExp('@media \\(max-width: ' + max + 'px\\) \\{[\\s\\S]*?\\n\\}', 'g'); for (const m of text.match(re) || []) if (!sel || m.includes(sel)) return m; return null; };
    const H1 = 'h1.kadisha-hero-title', H1SEL = '.kadisha-lexicon-page .kadisha-hero-title';

    const shots = path.join(os.tmpdir(), 'kol-v7-shots'); fs.mkdirSync(shots, { recursive: true });
    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    const o0 = await files(page);

    // ═══ 1. בורר המכשיר ═══
    const btns = await page.evaluate(() => [...document.querySelectorAll('[data-dev]')].map(b => b.dataset.dev));
    ok(same(btns, ['desktop', 'tablet', 'mobile']), 'בסרגל: 🖥 מחשב · טאבלט · טלפון — מ-breakpoints שב-kol.json, בסדר יורד של max', btns);
    await clickDev(page, 'desktop');
    const ed = await page.evaluate(() => { const r = document.querySelector('.ed').getBoundingClientRect(); return { w: r.width, h: r.height }; });
    ok(ed.w >= 1880 && ed.h >= 900, '1920: העורך על כל המסך', ed);
    await tab(page, 'design'); await select(page, H1); await sleep(150);
    await typeIn(page, 'design', 'font-size', '48px');
    const fDesk = await files(page);
    ok(await cs(page, H1, 'font-size') === '48px' && !/@media \(max-width: 767px\)/.test(fDesk['css/page.css']), 'מחשב: גודל הכותרת 48 — בכלל הבסיסי, בלי @media');
    await clickDev(page, 'mobile');
    let d = await dev(page);
    ok(d.id === 'mobile' && d.vp === 390 && d.root === '2' && /טלפון/.test(d.name) && d.w === '390' && d.note, 'בחירת "טלפון" (לחיצה): הקנבס ב-390 (preview), הפאנל מסומן בצבע המכשיר, ובראשו "נכתב ל-@media (max-width: 767px)"', d);
    let ds = await devState(page, 'design', 'font-size');
    ok(ds && ds.state === 'from' && /עובר ממחשב/.test(ds.text) && /48px/.test(ds.text) && /טלפון: 48px/.test(ds.title), 'בטלפון: "○ עובר ממחשב 48px"; ריחוף — הערך בכל מכשיר', ds);

    // ═══ 2. כתיבה לטלפון: ערך מ-@media שאינו מכשיר (600px), "לכתוב לטלפון בכל זאת", וכתיבה ל-@media של הטלפון ═══
    const ov = await page.evaluate(q => { const w = document.querySelector(q + ' [data-over]'); return w ? { t: w.textContent, dev: !!w.querySelector('[data-over-dev]'), who: w.querySelector('[data-who-wins]')?.textContent || '' } : null; }, field('design', 'font-size'));
    ok(ov && /max-width: 600px/.test(ov.t) && /חל כשהמסך צר מ-601px/.test(ov.t) && ov.dev && /ינצח/.test(ov.who), 'בטלפון הערך הנראה (42px) מגיע מ-@media (max-width: 600px) — "חל כשהמסך צר מ-601px": ברירת המחדל — לערוך שם; ומוצע "לכתוב לטלפון בכל זאת", עם "מי ינצח"', ov);
    await typeIn(page, 'design', 'font-size', '32px');
    let c1 = await css(page);
    ok(/@media \(max-width: 600px\) \{\s*\.kadisha-lexicon-page \.kadisha-hero-title, \.kadisha-lexicon-page \.kadisha-hero h1 \{ font-size: 32px; \}/.test(c1) && !/max-width: 767px/.test(c1) && await cs(page, H1, 'font-size') === '32px', 'בטלפון — גודל הכותרת 32: נכתב להצהרה שב-600px (ברירת המחדל, כמו V3)');
    ok(/^גודל גופן · h1#lexicon-hero-title · טלפון · 42px ← 32px$/.test(await lastTx(page)), 'שם העסקה כולל את המכשיר: "' + await lastTx(page) + '"');
    await page.click(field('design', 'font-size') + ' [data-over-dev]'); await sleep(100);
    await page.fill(field('design', 'font-size') + ' .sg-ctl input', '30px'); await page.press(field('design', 'font-size') + ' .sg-ctl input', 'Enter'); await sleep(300);
    c1 = await css(page); let b1 = block(c1, 767, 'kadisha-hero-title');
    ok(b1 && /font-size: 30px;/.test(b1) && /font-size: 48px; font-weight: 700; line-height: 1\.15; margin-bottom: 0; \}\n@media \(max-width: 767px\) \{/.test(c1), '"לכתוב לטלפון בכל זאת": אותו בורר בתוך @media (max-width: 767px) — בלוק חדש מיד אחרי הכלל הבסיסי', b1);
    ds = await devState(page, 'design', 'font-size');
    ok(ds && ds.state === 'over' && /⚠/.test(ds.text) && /נדרס/.test(ds.text), 'ומי שנדרס — נאמר: "⚠ נכתב בטלפון — אבל נדרס" (600px כתוב אחריו)', ds);
    ds = await devState(page, 'design', 'letter-spacing');
    await typeIn(page, 'design', 'letter-spacing', '2px');
    c1 = await css(page); b1 = block(c1, 767, 'kadisha-hero-title');
    ds = await devState(page, 'design', 'letter-spacing');
    ok(/font-size: 30px; letter-spacing: 2px;/.test(b1 || '') && await cs(page, H1, 'letter-spacing') === '2px' && ds && ds.state === 'own' && /●/.test(ds.text), 'ריווח אותיות בטלפון: נכתב לכלל שכבר בבלוק — "● נכתב בטלפון"', { b1, ds });
    await clickDev(page, 'desktop');
    ok(await cs(page, H1, 'font-size') === '48px' && await cs(page, H1, 'letter-spacing') === 'normal', 'חזרה למחשב: הכותרת נשארה 48, בלי ריווח');
    // Ctrl+Shift+M — מחזור בין המכשירים
    await page.click('.ed-canvas', { position: { x: 5, y: 5 } }).catch(() => {}); await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
    const seq = []; for (let i = 0; i < 3; i++) { await page.keyboard.press('Control+Shift+M'); await sleep(150); seq.push((await dev(page)).id); }
    ok(same(seq, ['tablet', 'mobile', 'desktop']), 'Ctrl+Shift+M: מחשב ← טאבלט ← טלפון ← מחשב', seq);
    // רוחב חופשי: 430 שייך לטלפון
    await page.fill('[data-dev-width]', '430'); await page.press('[data-dev-width]', 'Enter'); await sleep(250);
    d = await dev(page);
    ok(d.id === 'mobile' && d.vp === 430 && /טלפון/.test(d.name) && d.w === '430', 'רוחב חופשי 430px: הקנבס ב-430, והבורר אומר לאיזה מכשיר הרוחב שייך — "טלפון · 430px"', d);
    await page.fill('[data-dev-width]', '900'); await page.press('[data-dev-width]', 'Enter'); await sleep(200);
    ok((await dev(page)).id === 'tablet', 'רוחב 900px — שייך לטאבלט');

    // ═══ 3. גרירה אמיתית בעכבר של ידית המרווח — בטלפון בלבד ═══
    const dist = () => page.evaluate(() => { const dd = Editor._state.canvas.doc, a = dd.querySelector('h1.kadisha-hero-title'), b = a.nextElementSibling; return Math.round((b.getBoundingClientRect().top - a.getBoundingClientRect().bottom) * 100) / 100; });
    const strip = t => t.replace(/@media \(max-width: 767px\) \{[\s\S]*?\n\}\n?/g, '');
    await clickDev(page, 'desktop'); await tab(page, 'adv'); await select(page, H1); await sleep(250);
    const deskD = await dist();
    await clickDev(page, 'mobile'); await select(page, H1); await sleep(250);
    const g0 = await page.evaluate(() => { const b = document.querySelector('[data-sp-gap]'); if (!b) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
    const before = await dist(), scale = await page.evaluate(() => Editor._state.canvas.scale), cBefore = await css(page);
    if (g0) { await page.mouse.move(g0.x, g0.y); await page.mouse.down(); for (let i = 1; i <= 10; i++) { await page.mouse.move(g0.x, g0.y + 24 * scale * i / 10); await sleep(16); } await page.mouse.up(); await sleep(300); }
    const after = await dist(), c3 = await css(page), b3 = (c3.match(/@media \(max-width: 767px\) \{[\s\S]*?\n\}/g) || []).join('\n');
    ok(g0 && after > before + 15 && /margin/.test(b3) && strip(c3) === strip(cBefore), 'גרירה בעכבר של ידית הרווח בין הכותרות, בטלפון: הרווח גדל — נכתב ל-@media של הטלפון בלבד; שאר הקובץ לא השתנה', { before, after, b3 });
    await clickDev(page, 'desktop'); await select(page, H1); await sleep(200);
    ok(await dist() === deskD, 'במחשב — הרווח בין הכותרות כמו שהיה', { deskD, now: await dist() });

    // ═══ 4. הסתרה בטלפון ═══
    await clickDev(page, 'mobile'); await select(page, '.kadisha-hero-credit'); await sleep(200);
    await page.click('[data-devhide-btn]'); await sleep(300);
    let c4 = await css(page), b4 = block(c4, 767, 'kadisha-hero-credit');
    const mark = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.kadisha-hero-credit'), s = c.frame.contentWindow.getComputedStyle(e); return { display: s.display, opacity: s.opacity, outline: s.outlineStyle, set: c.devHidden.has(c.nodeOf(e)) }; });
    ok(b4 && /display: none/.test(b4) && mark.display !== 'none' && +mark.opacity < 0.6 && mark.outline === 'dashed' && mark.set, '"הסתר בטלפון" (👁): display:none ב-@media; בקנבס האלמנט שקוף למחצה ומקווקו — ואינו נעלם', { b4, mark });
    ok(/הצג בטלפון/.test(await page.textContent('[data-devhide-btn]')) && /^הסתרה · .* · טלפון/.test(await lastTx(page)), 'הכפתור מתחלף ל"הצג בטלפון"; העסקה: "' + await lastTx(page) + '"');
    await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); }); await sleep(250);
    ok(await page.evaluate(() => !!document.querySelector('.nv-row.sel [data-dev-hidden]')), 'ב-Navigator: השורה מסומנת "מוסתר במכשיר הזה"');
    await clickDev(page, 'desktop');
    ok(await cs(page, '.kadisha-hero-credit', 'display') !== 'none' && !(await page.evaluate(() => Editor._state.canvas.devHidden.size)), 'במחשב — שורת הקרדיט נראית');
    await tab(page, 'adv'); await select(page, '.kadisha-hero-credit'); await sleep(200);
    const boxes = await page.evaluate(() => [...document.querySelectorAll('[data-devhide]')].map(b => b.dataset.devhide + ':' + b.checked));
    ok(same(boxes, ['desktop:false', 'tablet:false', 'mobile:true']), '"מתקדם" ← "מכשירים": תיבות מחשב / טאבלט / טלפון — "מוסתר בטלפון" מסומנת', boxes);
    // "הצג" — מוחק את ההצהרה, והבלוק שנשאר ריק נמחק
    await clickDev(page, 'mobile'); await select(page, '.kadisha-hero-credit'); await sleep(200);
    await page.click('[data-devhide-btn]'); await sleep(300);
    ok(!block(await css(page), 767, 'kadisha-hero-credit') && (await css(page)).split('kadisha-hero-credit').length === c4.split('kadisha-hero-credit').length - 1, '"הצג בטלפון": ההצהרה נמחקה, והכלל שנשאר ריק — נמחק (גם הבלוק, כשהוא ריק)');

    // ═══ 5. order רק בטלפון: גרירה ← "רק כאן" ═══
    const html0 = await src(page);
    const PD = () => {
      const c = Editor._state.canvas, fpt = (x, y) => { const f = c.frame.getBoundingClientRect(); return [(x - f.left) / c.scale, (y - f.top) / c.scale]; };
      const fire = (t, type, cx, cy) => t.dispatchEvent(new (t.ownerDocument.defaultView.PointerEvent)(type, { bubbles: true, cancelable: true, composed: true, clientX: cx, clientY: cy, pointerId: 1, pointerType: 'mouse', isPrimary: true, button: type === 'pointermove' ? -1 : 0, buttons: type === 'pointerup' ? 0 : 1 }));
      window.__pd = { down(x, y) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy), 'pointerdown', fx, fy); }, move(x, y) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', fx, fy); }, up(x, y) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', fx, fy); } };
    };
    const at = (sel, fx = 0.5, fy = 0.5) => page.evaluate(([s, fx, fy]) => { const c = Editor._state.canvas, e = c.doc.querySelector(s); e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, fx, fy]);
    await select(page, H1); await sleep(150);
    { const from = await at(H1), to = await at('.kadisha-hero-subtitle-main', 0.5, 0.85);
      await page.evaluate(PD); await page.evaluate(([x, y]) => window.__pd.down(x, y), [from.x, from.y]);
      for (let i = 1; i <= 8; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [from.x + (to.x - from.x) * i / 8, from.y + (to.y - from.y) * i / 8]); await sleep(24); }
      await sleep(60); await page.evaluate(([x, y]) => window.__pd.up(x, y), [to.x, to.y]); await sleep(300); }
    const ask = await page.evaluate(() => { const a = document.querySelector('[data-ask^="סדר ב"]'); return a ? [...a.querySelectorAll('[data-choice]')].map(b => b.dataset.choice + ':' + b.textContent) : null; });
    ok(ask && ask[0].startsWith('all:') && ask.some(x => x.startsWith('here:')), 'גרירה בזמן שהטלפון נבחר שואלת: "להזיז בכל המכשירים (HTML), או רק כאן (order)?" — ברירת המחדל: בכל המכשירים', ask);
    if (ask) { await page.click('[data-choice="here"]'); await sleep(400); }
    const c5 = await css(page), ordH1 = await cs(page, H1, 'order'), ordSub = await cs(page, '.kadisha-hero-subtitle-main', 'order');
    const vis = await page.evaluate(() => { const dd = Editor._state.canvas.doc; return dd.querySelector('h1.kadisha-hero-title').getBoundingClientRect().top > dd.querySelector('.kadisha-hero-subtitle-main').getBoundingClientRect().top; });
    ok(await src(page) === html0 && /order: \d+/.test(block(c5, 767, 'kadisha-hero') || '') && +ordH1 > +ordSub && vis, '"רק בטלפון": order לאחים ב-@media — הכותרת מתחת לתת-הכותרת בטלפון; ה-HTML לא השתנה', { ordH1, ordSub, vis });
    ok(await page.evaluate(() => !!document.querySelector('.nv-row.sel [data-order]')), 'ב-Navigator: הסדר הנראה לצד סדר המקור ("נראה · במקור")');
    await clickDev(page, 'desktop');
    ok(await page.evaluate(() => { const dd = Editor._state.canvas.doc; return dd.querySelector('h1.kadisha-hero-title').getBoundingClientRect().top < dd.querySelector('.kadisha-hero-subtitle-main').getBoundingClientRect().top; }), 'במחשב — הסדר המקורי');

    // ═══ 6. @media שאינו מכשיר (782px), ו"רק האלמנט הזה" במכשיר ═══
    await clickDev(page, 'mobile'); await tab(page, 'adv'); await select(page, 'section.kadisha-hero'); await sleep(250);
    // היעד: הכלל .kadisha-hero שב-lexicon.css (של ערכת הנושא) — ה-@media (max-width: 782px) שאחריו קובע את הריפוד בטלפון
    await page.evaluate(q => { const s = document.querySelector(q); s.value = String([...s.options].findIndex(o => /^\.kadisha-hero · css\/lexicon\.css/.test(o.textContent.replace(/^★ /, '')))); s.dispatchEvent(new Event('change')); }, body('adv') + ' [data-target]'); await sleep(200);
    const over = await page.evaluate(q => { const w = document.querySelector(q + ' [data-over]'); return w ? { t: w.textContent, dev: !!w.querySelector('[data-over-dev]'), who: w.querySelector('[data-who-wins]')?.textContent || '' } : null; }, field('adv', 'padding-top'));
    ok(over && /782px/.test(over.t) && /חל כשהמסך צר מ-783px/.test(over.t) && over.dev && /ינצח/.test(over.who), 'ערך שבטלפון מגיע מ-@media (max-width: 782px): הפאנל אומר "חל כשהמסך צר מ-783px", ומציע לכתוב לטלפון בכל זאת — ואומר מי ינצח', over);
    const fOv = await files(page); await typeIn(page, 'adv', 'padding-top', '30px');
    const c6 = (await files(page))['css/lexicon.css'];
    ok(/@media \(max-width: 782px\) \{\s*\.kadisha-hero \{ padding: 30px 0 2rem; \}/.test(c6) && c6.replace('padding: 30px 0 2rem', 'padding: 2.5rem 0 2rem') === fOv['css/lexicon.css'], 'ברירת המחדל (כמו V3): העריכה נכתבת להצהרה שב-782px', (c6.match(/@media \(max-width: 782px\) \{[^}]*\}/) || [''])[0]);
    await undoFiles(page, fOv);
    await tab(page, 'design'); await select(page, H1); await sleep(150);
    await page.evaluate(q => { const s = document.querySelector(q); s.value = String([...s.options].findIndex(o => /style=""/.test(o.textContent))); s.dispatchEvent(new Event('change')); }, body('design') + ' [data-target]'); await sleep(200);
    const inl = await page.isVisible(body('design') + ' [data-dev-inline]'); const fIn = await files(page);
    await typeIn(page, 'design', 'letter-spacing', '2px').catch(() => {});
    ok(inl && same(await files(page), fIn), 'במכשיר, "רק האלמנט הזה" (style="") — הפאנל אומר שאי אפשר ומציע מחלקה; דבר אינו נכתב', inl);
    await page.evaluate(q => { const s = document.querySelector(q); s.value = '0'; s.dispatchEvent(new Event('change')); }, body('design') + ' [data-target]'); await sleep(200);

    // ═══ 7. "⇊ לכל המכשירים", ואיפוס במכשיר ═══
    await select(page, H1); await sleep(200);
    await page.click(field('design', 'letter-spacing') + ' [data-dev-all]'); await sleep(300);
    let c7 = await css(page), b7 = block(c7, 767, 'kadisha-hero-title');
    ok(/margin-bottom: 0; letter-spacing: 2px; \}/.test(c7) && !/letter-spacing/.test(b7 || ''), '"⇊ לכל המכשירים": 2px נכתב לבסיס, ונמחק מה-@media של הטלפון', b7);
    await page.keyboard.press('Control+z'); await sleep(300);
    ok(/letter-spacing: 2px;/.test(block(await css(page), 767, 'kadisha-hero-title') || '') && !/margin-bottom: 0; letter-spacing/.test(await css(page)), 'ביטול — חזרה לערך הטלפון בלבד');
    const rdis = await page.evaluate(q => document.querySelector(q)?.disabled, field('design', 'letter-spacing') + ' [data-reset]');
    await page.click(field('design', 'letter-spacing') + ' [data-reset]'); await sleep(300);
    c7 = await css(page);
    ok(rdis === false && !/letter-spacing: 2px/.test(c7) && /\{ font-size: 30px; order: 2; \}/.test(block(c7, 767, 'kadisha-hero-title') || '') && await cs(page, H1, 'letter-spacing') === 'normal' && /· טלפון · איפוס$/.test(await lastTx(page)), '⟲ "איפוס בטלפון": נמחקה רק ההצהרה שבתוך ה-@media (שאר הכלל נשאר) — "' + await lastTx(page) + '"', block(c7, 767, 'kadisha-hero-title'));

    // ═══ 8. נקודות שבירה ═══
    const fBp = await files(page);
    await page.click('[data-breakpoints]'); await sleep(200);
    await page.fill('[data-bp-row="mobile"] [data-bp="max"]', '700'); await sleep(150);
    const sum = await page.textContent('[data-bp-sum]');
    ok(/767px ← 700px/.test(sum) && /\d+ כללים ב-\d+ (קובץ|קבצים)/.test(sum), 'שינוי max של "טלפון": תצוגה מקדימה — "N כללים ב-M קבצים"', sum);
    await page.click('[data-bp-save]'); await sleep(400);
    const fB = await files(page);
    ok(/"max": 700/.test(fB['kol.json']) && !/max-width: 767px/.test(fB['css/page.css']) && /max-width: 700px/.test(fB['css/page.css']) && (await dev(page)).id === 'mobile' && /"styles"/.test(fB['kol.json']), 'נשמר: kol.json (שאר הקובץ נשאר) וכל ה-@media של הטלפון עודכנו ל-700px — עסקה אחת', fB['kol.json'].slice(0, 400));
    await page.evaluate(() => Editor._undo()); await sleep(300);
    ok(same(await files(page), fBp), 'ביטול אחד מחזיר את kol.json ואת page.css יחד');
    await page.click('[data-breakpoints]'); await sleep(200);
    await page.click('[data-bp-del="mobile"]'); await sleep(150);
    const gone = await page.textContent('[data-bp-sum]');
    ok(/נשארים בקבצים/.test(gone) && /768px/.test(gone), 'מחיקת נקודה שיש לה כללים — אזהרה: הכללים נשארים בקבצים ("חלים כשהמסך צר מ-768px")', gone);
    await page.evaluate(() => document.getElementById('sheet').close()); await sleep(150);

    // ═══ 9. ביטול עד ההתחלה ═══
    ok(await undoFiles(page, o0) && (await page.evaluate(() => Editor._state.canvas.inequiv.length)) === 0, 'ביטול עד ההתחלה — כל הקבצים (HTML, CSS, kol.json) זהים בייט בבייט');
    await clickDev(page, 'desktop'); await select(page, H1); await sleep(200);
    const ovf = async () => page.evaluate(() => [...document.querySelectorAll('.ed-info .ed-side-body.sg')].filter(b => !b.hidden).map(b => { let worst = null; for (const e of b.querySelectorAll('*')) { const r = e.getBoundingClientRect(), p = b.getBoundingClientRect(); if (r.width && (r.right > p.right + 1 || r.left < p.left - 1) && (!worst || r.width > worst.w)) worst = { cls: e.className, w: Math.round(r.width), pr: Math.round(p.right), r: Math.round(r.right) }; } return { sw: b.scrollWidth, cw: b.clientWidth, worst }; }));
    await tab(page, 'design'); await select(page, H1); await sleep(200); const ov1 = await ovf();
    await tab(page, 'adv'); await sleep(200); const ov2 = await ovf();
    ok([...ov1, ...ov2].every(x => x.sw <= x.cw + 1 && !x.worst), '1920: פאנל העיצוב והמתקדם — שום דבר אינו חורג מהרוחב (התוויות אינן חתוכות)', { ov1, ov2 });
    await tab(page, 'design');
    await page.screenshot({ path: path.join(shots, 'v7-1920.png') });

    // ═══ 10. 390 — מגע ═══
    await page.waitForFunction(() => { const S = Editor._state, ds = [S.doc, ...[...S.sheets.values()].map(x => x.doc)]; return ds.every(d => !d.dirty && !d.saving); }, null, { timeout: 20000 });
    {
      const lc = await mk(390, 844), lp = await lc.newPage(); lp.on('pageerror', e => errors.push('390: ' + e.message));
      await openPage(lp, 'index.html');
      const l0 = await files(lp);
      ok((await dev(lp)).id === 'mobile' && (await dev(lp)).vp === 390, '390: נפתח במצב "טלפון"');
      const tb = await lp.evaluate(() => [...document.querySelectorAll('[data-dev]')].map(b => { const r = b.getBoundingClientRect(); return Math.round(Math.min(r.width, r.height)); }));
      ok(tb.every(x => x >= 36), 'כפתורי המכשיר במגע', tb);
      await select(lp, H1); await sleep(150);
      await lp.evaluate(() => { document.querySelector('[data-space]').click(); }); await sleep(250);
      const gh = await lp.evaluate(() => { const b = document.querySelector('[data-sp-gap]'); if (!b) return null; const r = b.getBoundingClientRect(); return { w: r.width, h: r.height }; });
      const ld = () => lp.evaluate(() => { const dd = Editor._state.canvas.doc, a = dd.querySelector('h1.kadisha-hero-title'), b = a.nextElementSibling; return Math.round(b.getBoundingClientRect().top - a.getBoundingClientRect().bottom); });
      const lb = await ld(), lscl = await lp.evaluate(() => Editor._state.canvas.scale);
      { // מגע אמיתי (CDP): touchStart / touchMove / touchEnd — הדפדפן מייצר מהם אירועי מצביע מסוג touch
        const cdp = await lc.newCDPSession(lp), r = await lp.evaluate(() => { const b = document.querySelector('[data-sp-gap]'), q = b.getBoundingClientRect(); return { x: q.left + q.width / 2, y: q.top + q.height / 2 }; });
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: r.x, y: r.y }] });
        for (let i = 1; i <= 10; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: r.x, y: r.y + 24 * lscl * i / 10 }] }); await sleep(20); }
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(400);
      }
      const lcss = (await files(lp))['css/page.css'];
      ok(gh && gh.w >= 44 && gh.h >= 44 && await ld() > lb + 15 && /margin/.test(block(lcss, 767, 'kadisha-hero') || ''), '390 במגע: ידית הרווח ≥44px; גרירה באצבע — הרווח גדל, ונכתב ל-@media של הטלפון', { gh, lb, after: await ld() });
      await lp.screenshot({ path: path.join(shots, 'v7-390.png') });
      ok(await undoFiles(lp, l0), '390: ביטול — זהים');
      await lc.close();
    }
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
