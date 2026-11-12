/**
 * פרוסה V4 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/v4.cjs
 *
 * מבנה — "רובריקות בתוך רובריקות", על index.html המוסב (העמוד האמיתי): צעדים 2, 3, 8 של התרחיש (03 §7.3) ובדיקת
 * הבעלים של V4 (רובריקה ↔ עם שתי רובריקות, גרירת כותרת וטקסט אליהן, ↕ ובחזרה), ביטול עד ההתחלה — קבצים זהים.
 * ועוד: קו ההכנסה (כיוון ותווית), Esc ושחרור מחוץ ליעד, בחירה מרובה, Navigator, מה שהדפדפן אינו מרשה (והצעה),
 * <template>, שכפול (id), עטיפה ופירוק, תגית, העתק / הדבק (גם עוין), אזהרות לפני מחיקה, מקלדת, טלפון, ביצועים.
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

const PAGE = `<!doctype html>
<html lang="he" dir="rtl"><head><meta charset="utf-8"><title>בדיקה</title>
<link rel="stylesheet" href="../css/page.css">
<style>
.imp { display: flex; flex-direction: column !important; }
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
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v4-'));
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
        down(x, y, touch) { this.touch = !!touch; const top = document.elementFromPoint(x, y); this.frame = inFrame(x, y) && (!top || top.localName === 'iframe' || top.classList.contains('ed-canvas') || top.classList.contains('spike-layer')); if (this.frame) { const [fx, fy] = fpt(x, y); this.t = c.doc.elementFromPoint(fx, fy); fire(this.t, 'pointerdown', x, y, fx, fy, touch); } else { this.t = document.elementFromPoint(x, y); fire(this.t, 'pointerdown', x, y, x, y, touch); } },
        move(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', x, y, fx, fy, this.touch); } else { const t = ed.build.drag.active ? (document.elementFromPoint(x, y) || document.body) : this.t; fire(t.localName === 'iframe' ? document.body : t, 'pointermove', x, y, x, y, this.touch); } },
        up(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', x, y, fx, fy, this.touch); } else { const t = document.elementFromPoint(x, y) || document.body; fire(t.localName === 'iframe' ? document.body : t, 'pointerup', x, y, x, y, this.touch); } },
      };
    };
    const drag = async (page, from, to, opt = {}) => {
      await page.evaluate(PD);
      await page.evaluate(([x, y, t]) => window.__pd.down(x, y, t), [from.x, from.y, !!opt.touch]);
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

    const ctx = await mk(1280, 900), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => d.dismiss());
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await call(page, 'pf_create', { path: 't/page.html', content: PAGE });
    const cards = Array.from({ length: 1496 }, (_, i) => `  <li class="card"><b>מילה ${i}</b> <span>פירוש</span></li>`).join('\n');
    await call(page, 'pf_create', { path: 't/big.html', content: `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><link rel="stylesheet" href="../css/page.css"></head><body>\n<h1 class="big-title">מילון</h1>\n<div class="tools"><p>כלים</p></div>\n<ul class="cards">\n${cards}\n</ul>\n</body></html>\n` });

    // ═══ 1. התרחיש על העמוד האמיתי ═══
    await openPage(page, 'index.html');
    const t0 = await files(page);
    await page.evaluate(() => { const S = Editor._state; S.canvas.verify = true; });
    // צעד 2: הכותרת בצרפתית אל מעל הכותרת בעברית
    let from = await at(page, '.kadisha-hero-eyebrow-line'), to = await at(page, 'h1.kadisha-hero-title', 0.5, 0.2, 0, false);
    let pk = await drag(page, from, to);
    ok(/לפני h1#lexicon-hero-title · בתוך div\.kadisha-hero-inner/.test(pk.tag) && !pk.bad && / h$| h /.test(pk.line + ' '), 'צעד 2: בזמן הגרירה — קו אופקי (רובריקת-עמודה) ותווית "לפני h1#… · בתוך div.kadisha-hero-inner"', pk);
    let s1 = await src(page), q = await st(page);
    ok(/<div class="kadisha-hero-inner">\n      <span class="kadisha-hero-eyebrow-line">La Voix du Maroc<\/span>\n      <h1 /.test(s1) && q.same && !q.inequiv && /^גרירה · span\.kadisha-hero-eyebrow-line · אל לפני h1#lexicon-hero-title$/.test(q.tx || ''), 'צעד 2: הכותרת בצרפתית לפני הכותרת בעברית — שורה משלה, עסקה אחת עם שם, המודל שקול', q);
    ok(await page.evaluate(() => { const d = Editor._state.canvas.doc, f = [...d.querySelectorAll('.kadisha-hero-eyebrow-line')].find(x => x.textContent === 'La Voix du Maroc'), h = d.querySelector('h1.kadisha-hero-title'); return f.getBoundingClientRect().bottom <= h.getBoundingClientRect().top + 1; }), 'צעד 2: בקנבס — הכותרת בצרפתית מעל הכותרת בעברית');
    // צעד 3: כותרת שביעית, ומקלידים בה
    await select(page, 'p.kadisha-hero-subtitle-main');
    await page.click('[data-ntab="lib"]'); await sleep(60);
    await page.click('.ed-lib-item[data-lib="heading"]'); await sleep(250);
    await page.keyboard.type('הכותרת השביעית'); await page.keyboard.press('Escape'); await sleep(300);
    s1 = await src(page);
    ok(/<p class="kadisha-hero-subtitle-main">[^<]*<\/p>\n      <h1 class="kadisha-hero-title">הכותרת השביעית<\/h1>/.test(s1) || /<\/p>\n      <p class="kadisha-hero-subtitle-main">הכותרת השביעית<\/p>/.test(s1) || /<h2>הכותרת השביעית<\/h2>/.test(s1), 'צעד 3: כותרת חדשה — עם התגית והמחלקות של השכנה, והטקסט שהוקלד במקום', s1.slice(s1.indexOf('kadisha-hero-inner'), s1.indexOf('kadisha-hero-inner') + 700));
    // צעד 8: הפס כולו אל מתחת לשורת החיפוש
    await select(page, 'section.kadisha-hero'); await sleep(120);
    to = await at(page, '.kadisha-lexicon-controls', 0.5, 1, -3); await sleep(80);
    const grip = await page.$('.ed-seltools [data-grip]'), gb = await grip.boundingBox();
    pk = await drag(page, { x: gb.x + gb.width / 2, y: gb.y + gb.height / 2 }, to, { steps: 12, wiggle: { x: 40, y: 0 } });   // השפה התחתונה של שורת החיפוש ושל המונה שבתוכה — אחת; תזוזה אופקית עולה רמה
    s1 = await src(page); q = await st(page);
    ok(/kadisha-lexicon-count[^\n]*\n    <\/div>\s*\n    <section class="kadisha-hero kadisha-hero--has-image"[^\n]*\n      <div class="kadisha-container">\n        <div class="kadisha-hero-inner">/.test(s1) && s1.indexOf('kadisha-lexicon-controls') < s1.indexOf('<section class="kadisha-hero ') && q.same && !q.inequiv, 'צעד 8: הפס כולו מתחת לשורת החיפוש (בשפה המשותפת — תזוזה אופקית עולה רמה), מוזח לשכניו', { pk, q, around: s1.slice(s1.indexOf('kadisha-lexicon-count') , s1.indexOf('kadisha-lexicon-count') + 400) });
    // בדיקת הבעלים: רובריקה ↔ מתחת לפס, ובתוכה שתי רובריקות
    from = await lib(page, 'row2'); to = await at(page, 'section.kadisha-hero', 0.5, 1, -3);
    pk = await drag(page, from, to);
    let f1 = await files(page);
    ok(/<\/section>\s*\n    <div class="row-1"><div class="row-1-col"><\/div><div class="row-1-col"><\/div><\/div>/.test(f1['index.html']) && /\.row-1 \{[^}]*display: flex;[^}]*flex-direction: row;[^}]*flex-wrap: wrap;/.test(f1['css/page.css']) && /\.row-1-col \{[^}]*flex: 1 1 0;[^}]*min-width: 0;/.test(f1['css/page.css']), 'בדיקת הבעלים: "שורה של 2" נגררת מהספרייה אל מתחת לפס — HTML + כלל CSS (row-1, row-1-col) בעסקה אחת', { pk, css: f1['css/page.css'].slice(-200), html: f1['index.html'].slice(f1['index.html'].indexOf('</section>') - 50, f1['index.html'].indexOf('</section>') + 200) });
    const emptyH = await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('.row-1-col')].map(e => Math.round(e.getBoundingClientRect().height)));
    ok(emptyH.length === 2 && emptyH.every(x => x >= 48) && !f1['index.html'].includes('min-height'), 'רובריקה ריקה אינה נעלמת: בקנבס ≥48px וקו מקווקו — ובקובץ אין גובה', emptyH);
    // גוררים כותרת מהספרייה אל האחת, וטקסט אל השנייה
    from = await lib(page, 'heading'); to = await at(page, '.row-1-col', 0.5, 0.5);
    pk = await drag(page, from, to);
    from = await lib(page, 'text'); to = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelectorAll('.row-1-col')[1]; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width / 2) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; });
    const pk2 = await drag(page, from, to);
    s1 = await src(page);
    ok(/<div class="row-1"><div class="row-1-col"><h2>כותרת<\/h2><\/div><div class="row-1-col"><p>טקסט<\/p><\/div><\/div>/.test(s1) && /^ראשון · בתוך div\.row-1-col/.test(pk.tag), 'בדיקת הבעלים: כותרת אל הרובריקה האחת, טקסט אל השנייה (גרירה מהספרייה)', { pk, pk2, s: s1.slice(s1.indexOf('row-1') - 10, s1.indexOf('row-1') + 200) });
    await page.keyboard.press('Escape'); await sleep(100);
    // ↕ ובחזרה
    await select(page, '.row-1');
    await page.evaluate(() => Editor._tab('adv')); await sleep(150);
    ok(await page.isVisible('[data-sec="layout"] [data-setdir="row"][aria-pressed="true"]'), 'הפאנל "מתקדם" ← "פריסה": המתג מציג את המצב המחושב (↔ שורה)');
    await page.click('[data-sec="layout"] [data-setdir="col"]'); await sleep(250);
    f1 = await files(page);
    ok(/\.row-1 \{[^}]*flex-direction: column;/.test(f1['css/page.css']) && await page.evaluate(() => getComputedStyle(Editor._state.canvas.doc.querySelector('.row-1')).flexDirection) === 'column' && /^כיוון · div\.row-1 · שורה ← עמודה/.test((await st(page)).note), 'בדיקת הבעלים: ↔ ← ↕ — flex-direction: column בכלל של המחלקה, עסקה עם שם', (await st(page)).note);
    await page.click('[data-sec="layout"] [data-setdir="row"]'); await sleep(250);
    f1 = await files(page);
    ok(/\.row-1 \{[^}]*flex-direction: row;/.test(f1['css/page.css']) && await page.evaluate(() => getComputedStyle(Editor._state.canvas.doc.querySelector('.row-1')).flexDirection) === 'row', 'בדיקת הבעלים: ↕ ← ↔ — חזרה לשורה');
    ok((await st(page)).inequiv === 0, 'אין אי-שקילות במודל לאורך התרחיש');
    ok(await undoFiles(page, t0), 'Ctrl+Z עד ההתחלה — כל הקבצים זהים בייט בבייט');

    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
