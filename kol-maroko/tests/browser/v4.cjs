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

    const ctx = await mk(1280, 900), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await call(page, 'pf_create', { path: 't/page.html', content: PAGE });
    await call(page, 'pf_create', { path: 't/other.html', content: '<!doctype html>\n<html lang="he" dir="rtl">\n<head>\n  <meta charset="utf-8">\n  <title>אחר</title>\n</head>\n<body>\n  <p id="o1">עמוד אחר</p>\n</body>\n</html>\n' });
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
    pk = await drag(page, { x: gb.x + gb.width / 2, y: gb.y + gb.height / 2 }, to, { steps: 12, wiggle: { x: 40, y: 0 }, shell: true });   // השפה התחתונה של שורת החיפוש ושל המונה שבתוכה — אחת; תזוזה אופקית עולה רמה
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


    // ═══ 2. על עמוד בדיקה ═══
    // תבנית (<template>) ב-Navigator של העמוד האמיתי — לא ב-V4, עם ההסבר
    await page.evaluate(() => { const S = Editor._state; S.canvas.select(null); });
    const navRow = (page, sel, f = 0.5) => page.evaluate(([s, f]) => { const S = Editor._state, c = S.canvas, K = window.KolSrc; let n = null; K.walk(c.m.root, x => { if (!n && s(x)) n = x; }); if (!n) return null; S.nav.collapsed = new WeakSet(); S.nav.refresh(); S.nav.scrollTo(n); S.nav.draw(); const i = S.nav.index.get(n), e = S.nav.space.querySelector('[data-i="' + i + '"]'); if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.left + Math.min(r.width / 2, 140), y: r.top + r.height * f }; }, [new Function('x', 'return ' + sel), f]).catch(() => null);
    const navPt = (page, test, f = 0.5) => page.evaluate(([t, f]) => { const S = Editor._state, K = window.KolSrc, fn = new Function('x', 'K', 'return ' + t); let n = null; K.walk(S.canvas.m.root, x => { if (!n && fn(x, K)) n = x; }); if (!n) return null; for (let p = n.parent; p; p = p.parent) S.nav.collapsed.delete(p); S.nav.refresh(); S.nav.scrollTo(n); S.nav.draw(); const i = S.nav.index.get(n), e = S.nav.space.querySelector('[data-i="' + i + '"]'); if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.left + Math.min(r.width / 2, 140), y: r.top + r.height * f }; }, [test, f]);
    /** שתי שורות ב-Navigator, באותה גלילה: [מקור, יעד]. */
    const navPts = (page, a, b, fb = 0.5) => page.evaluate(([a, b, fb]) => { const S = Editor._state, K = window.KolSrc, R = window.KolNavigator.ROW; const find = t => { const fn = new Function('x', 'K', 'return ' + t); let n = null; K.walk(S.canvas.m.root, x => { if (!n && fn(x, K)) n = x; }); return n; };
      const na = find(a), nb = find(b); if (!na || !nb) return null; for (const n of [na, nb]) for (let p = n.parent; p; p = p.parent) S.nav.collapsed.delete(p); S.nav.refresh();
      const ia = S.nav.index.get(na), ib = S.nav.index.get(nb); S.nav.list.scrollTop = Math.max(0, Math.min(ia, ib) * R - 60); S.nav.draw();
      const pt = (i, f) => { const e = S.nav.space.querySelector('[data-i="' + i + '"]'), r = e.getBoundingClientRect(); return { x: r.left + Math.min(r.width / 2, 140), y: r.top + r.height * f }; };
      return [pt(ia, 0.5), pt(ib, fb)]; }, [a, b, fb]);
    await page.click('[data-ntab="tree"]'); await sleep(80);
    [from, to] = await navPts(page, "K.attrOf(x, 'id')?.value === 'kadisha-lex-no-matches'", "x.tag === 'article'", 0.15);
    const tpl0 = await src(page);
    pk = await drag(page, from, to, { steps: 6 });
    ok(pk.bad && /V11/.test(pk.tag) && (await src(page)) === tpl0, 'גרירה אל תוך <template> (כרטיס המילה) ב-Navigator — היעד אדום, התווית "…V11 — עד אז: פתח בקוד", ודבר אינו נכתב', pk);

    await sleep(1500);                                                         // השמירה האוטומטית של הביטול
    await openPage(page, 't/page.html');
    await page.evaluate(() => { Editor._state.canvas.verify = true; });
    const p0 = await files(page);
    // Esc ושחרור מחוץ ליעד
    pk = await drag(page, await at(page, '#a'), await at(page, '#t2', 0.5, 0.9), { esc: true });
    ok(same(await files(page), p0), 'Esc בזמן גרירה — דבר אינו נכתב');
    const barPt = await page.evaluate(() => { const r = document.querySelector('.ed-bar').getBoundingClientRect(); return { x: r.left + 4, y: r.top + r.height / 2 }; });
    pk = await drag(page, await at(page, '#a'), await at(page, '#t2', 0.5, 0.9), { end: barPt });
    ok(same(await files(page), p0), 'שחרור מחוץ לקנבס ול-Navigator — דבר אינו נכתב');
    // ניגררת בעכבר אחרי 4px; לחיצה — בחירה
    await page.mouse.click((await at(page, '#t2')).x, (await at(page, '#t2')).y); await sleep(100);
    ok((await st(page)).sel === 'p#t2' && same(await files(page), p0), 'לחיצה בלי תזוזה — בחירה, לא גרירה');
    // בחירה מרובה נגררת יחד, ונוחתת ברצף בסדר המקור
    await page.evaluate(() => { const c = Editor._state.canvas, d = c.doc; c.select(c.nodeOf(d.querySelector('#b'))); c.toggleMulti(c.nodeOf(d.querySelector('#a'))); });
    pk = await drag(page, await at(page, '#b'), await at(page, '#t3', 0.5, 0.85));
    s1 = await src(page);
    ok(/<p id="t3">שלישי<\/p><h2 id="a" class="hd">כותרת א<\/h2><h2 id="b" class="hd">כותרת ב<\/h2><\/div>/.test(s1) || /<p id="t3">שלישי<\/p>\s*<h2 id="a" class="hd">כותרת א<\/h2>\s*<h2 id="b" class="hd">כותרת ב<\/h2>\s*<\/div>/.test(s1), 'בחירה מרובה (b + a) נגררת יחד — נוחתת ברצף, בסדר המקור (a, b), אחרי #t3', { pk, s: s1.slice(s1.indexOf('<div id="box"'), s1.indexOf('<div id="box"') + 260) });
    ok(await undoFiles(page, p0), 'בטל — הקובץ זהה בייט בבייט');
    // כיוון: block ← שורה (בלי מחלקה — מחלקה חדשה ושני קבצים), ואז קו אנכי ברובריקת-שורה
    await select(page, '#box'); await page.evaluate(() => Editor._tab('adv')); await sleep(150);
    ok(await page.isVisible('[data-sec="layout"] [data-setdir="col"][aria-pressed="true"]'), 'מתג הכיוון מציג את המצב המחושב גם כשהעורך לא כתב אותו (block = ↕ עמודה)');
    await page.click('[data-sec="layout"] [data-setdir="row"]'); await sleep(300);
    f1 = await files(page);
    const boxCls = (/<div id="box" class="([\w-]+)">/.exec(f1['t/page.html']) || [])[1];
    ok(boxCls && new RegExp('\\.' + boxCls + ' \\{[^}]*display: flex;[^}]*flex-direction: row;[^}]*flex-wrap: wrap;').test(f1['css/page.css']) && !/[^-]direction:|row-reverse/.test(f1['t/page.html']), 'block ← שורה: display:flex; flex-direction:row; flex-wrap:wrap — במחלקה חדשה בשם קריא, ואין direction / row-reverse', { boxCls, css: f1['css/page.css'].slice(-200) });
    ok(await page.evaluate(() => { const d = Editor._state.canvas.doc, a = d.querySelector('#t1').getBoundingClientRect(), b = d.querySelector('#t2').getBoundingClientRect(); return a.left > b.left && Math.abs(a.top - b.top) < 2; }), 'בעברית שורה מתחילה מימין (#t1 מימין ל-#t2), מ-dir המחושב');
    ok(await page.isVisible('[data-sec="layout"] [data-wrap="wrap"]'), 'flex-wrap שהעורך כתב — שורה לקריאה בפאנל, עם "פתח בקוד"');
    pk = await drag(page, await at(page, '#t3'), await at(page, '#t1', 0.85, 0.5));
    ok(/ v$| v /.test(pk.line + ' ') && /לפני p#t1 · בתוך div#box/.test(pk.tag), 'ברובריקת-שורה הקו אנכי, והתווית "לפני p#t1 · בתוך div#box"', pk);
    ok(/<div id="box" class="[\w-]+"><p id="t3">שלישי<\/p><p id="t1">/.test(await src(page)), 'גרירה בשורה — לפי הסדר הנראה (מימין)');
    ok(await undoFiles(page, p0), 'בטל — שני הקבצים זהים בייט בבייט');
    // !important — נאמר לפני הכתיבה; grid — מוצג "רשת"
    await select(page, '#imp'); await page.evaluate(() => Editor._tab('adv')); await sleep(150);
    await page.click('[data-sec="layout"] [data-setdir="row"]'); await sleep(250);
    ok(/נדרס/.test(await page.textContent('#sheet').catch(() => '')) && await page.isVisible('#sheet [data-choice="rule"]'), '!important: "הכיוון לא ייראה — נדרס בידי…", עם "לשנות את הכלל הדורס" — לפני הכתיבה');
    await page.click('#sheet [data-choice="cancel"]'); await sleep(100);
    ok(same(await files(page), p0), 'בחירה ב"בטל" — דבר אינו נכתב (אין מתג שנלחץ ואינו משנה דבר)');
    await select(page, '#grid'); await sleep(150);
    ok(/רשת/.test(await page.textContent('[data-sec="layout"]')), 'grid: המתג מציג "רשת", ואומר שעריכת הרשת — V6');
    // מה שהדפדפן אינו מרשה: רובריקה בתוך <p>, <h2> ישר בתוך <ul> — והצעה
    from = await lib(page, 'box'); to = await at(page, '#pp span', 0.5, 0.5);
    pk = await drag(page, from, await at(page, '#pp', 0.5, 0.5));
    ok(pk.bad && /<p> אינו יכול להכיל רובריקה — הפוך אותו ל-<div> והכנס/.test(pk.tag) && same(await files(page), p0), 'רובריקה אל תוך <p> — היעד אדום, "<p> אינו יכול להכיל רובריקה — הפוך אותו ל-<div> והכנס", ודבר אינו נכתב', pk);
    pk = await drag(page, await at(page, '#a'), await at(page, '#list', 0.5, 0.5));
    ok(pk.bad && /בתוך <ul> רק <li>/.test(pk.tag) && same(await files(page), p0), '<h2> אל תוך <ul> — "בתוך <ul> רק <li> — עטוף ב-<li> והכנס"', pk);
    ok(await page.isVisible('.ed-note button:has-text("עטוף ב-<li> והכנס")'), 'ההודעה מציעה את הפעולה');
    await page.click('.ed-note button:has-text("עטוף ב-<li> והכנס")'); await sleep(200);
    ok(/<ul id="list">(<li>פריט<\/li>)?<li><h2 id="a" class="hd">כותרת א<\/h2><\/li>(<li>פריט<\/li>)?<\/ul>/.test(await src(page)) && (await st(page)).inequiv === 0, '"עטוף ב-<li> והכנס" — פעולה אחת, עסקה אחת', { note: (await st(page)).note, s: (await src(page)).slice((await src(page)).indexOf('<main'), (await src(page)).indexOf('<main') + 400) });
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // Navigator: גרירה בעץ
    await page.click('[data-ntab="tree"]'); await sleep(60);
    [from, to] = await navPts(page, "K.attrOf(x, 'id')?.value === 'b'", "K.attrOf(x, 'id')?.value === 'a'", 0.15);
    pk = await drag(page, from, to, { steps: 6 });
    ok(/<main class="main">\n  <h2 id="b" class="hd">כותרת ב<\/h2>\n  <h2 id="a" class="hd">כותרת א<\/h2>/.test(await src(page)), 'גרירה ב-Navigator: h2#b אל לפני h2#a — אותה פעולה כמו בקנבס', pk);
    [from, to] = await navPts(page, "x.tag === 'body'", "K.attrOf(x, 'id')?.value === 't1'");
    const beforeBody = await src(page); await drag(page, from, to, { steps: 6 });
    ok((await src(page)) === beforeBody, '<body> אינו נגרר', { from, tx: (await st(page)).tx });
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // שכפול (Ctrl+D): id כפול ← -2, ומה שונה נאמר
    await select(page, '#a'); await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
    await page.keyboard.press('Control+d'); await sleep(200);
    ok(/<h2 id="a" class="hd">כותרת א<\/h2>\n  <h2 id="a-2" class="hd">כותרת א<\/h2>/.test(await src(page)) && /id a ← a-2/.test((await st(page)).note) && (await st(page)).sel === 'h2#a-2', 'Ctrl+D: העתק מיד אחרי, עם המחלקות; id ← a-2 — והעורך אומר מה שונה; ההעתק נבחר', (await st(page)).note);
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // עטיפה (בחירה מרובה של אחים רצופים) ופירוק
    await page.evaluate(() => { const c = Editor._state.canvas, d = c.doc; c.select(c.nodeOf(d.querySelector('#t1'))); c.toggleMulti(c.nodeOf(d.querySelector('#t2'))); });
    await page.evaluate(() => { Editor._state.build.menu(Editor._state.canvas.selected); }); await sleep(100); await page.click('#sheet [data-wrap]'); await sleep(250);
    ok(/<div id="box"><div><p id="t1">ראשון<\/p><p id="t2">שני<\/p><\/div><p id="t3">/.test(await src(page)) && await page.isVisible('.ed-note button:has-text("סדר את הקוד")'), 'עטוף ברובריקה: <div> סביב שני אחים רצופים, בתוך השורה — ומוצע "סדר את הקוד של האלמנט"');
    const wrapped = await src(page);
    await page.evaluate(() => { Editor._state.build.menu(Editor._state.canvas.selected); }); await sleep(100); await page.click('#sheet [data-unwrap]'); await sleep(250);
    ok((await src(page)) === p0['t/page.html'], 'פרק רובריקה: הילדים נשארים במקומה — הקובץ חוזר לקדמותו');
    await page.evaluate(() => { const c = Editor._state.canvas, d = c.doc; c.select(c.nodeOf(d.querySelector('#t1'))); c.toggleMulti(c.nodeOf(d.querySelector('#t3'))); });
    await page.evaluate(() => { Editor._state.build.wrap(); }); await sleep(150);
    ok(/אינה רצופה/.test(await page.textContent('#sheet')) && await page.isVisible('#sheet [data-choice="each"]') && await page.isVisible('#sheet [data-choice="one"]'), 'בחירה שאינה רצופה — "עטוף כל אחד בנפרד" או "העבר לרובריקה אחת"');
    await page.click('#sheet [data-choice="one"]'); await sleep(250);
    ok(/<div id="box"><div><p id="t1">ראשון<\/p><p id="t3">שלישי<\/p><\/div><p id="t2">שני<\/p><\/div>/.test(await src(page)) || /<div id="box"><div><p id="t1">ראשון<\/p>\s*<p id="t3">שלישי<\/p><\/div><p id="t2">שני<\/p><\/div>/.test(await src(page)), '"העבר לרובריקה אחת" — שני האלמנטים ברובריקה אחת, עסקה אחת', (await src(page)).slice((await src(page)).indexOf('<div id="box"'), (await src(page)).indexOf('<div id="box"') + 160));
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    void wrapped;
    // החלפת תגית — קלט עוין נדחה, תקין נכתב בפתיחה ובסגירה
    await select(page, '#t3'); await page.evaluate(() => Editor._tab('content')); await sleep(150);
    for (const bad of ['div onclick=alert(1)', 'p><img src=x onerror=alert(1)', 'h1"']) { await page.fill('[data-retag]', bad); await page.press('[data-retag]', 'Enter'); await sleep(120); }
    ok(same(await files(page), p0) && /אינו שם תגית/.test((await st(page)).note), 'החלפת תגית: קלט עוין ("div onclick=…", "p><img …", מרכאה) — נדחה, הקובץ לא משתנה', (await st(page)).note);
    await page.fill('[data-retag]', 'section'); await page.press('[data-retag]', 'Enter'); await sleep(200);
    ok(/<section id="t3">שלישי<\/section>/.test(await src(page)), 'החלפת תגית בהקלדה חופשית: p ← section, בפתיחה ובסגירה');
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // העתק / הדבק — מהלוח של העורך, ומבחוץ (עוין)
    const paste = (page, html, text) => page.evaluate(([h, t]) => { const dt = new DataTransfer(); if (h != null) dt.setData('text/html', h); if (t != null) dt.setData('text/plain', t); Editor._state.canvas.doc.body.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); }, [html, text]);
    await select(page, '#a'); await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
    await page.keyboard.press('Control+c'); await sleep(100);
    await select(page, '#t3'); await paste(page, null, null); await sleep(250);
    ok(/<p id="t3">שלישי<\/p><h2 id="a" class="hd">כותרת א<\/h2><\/div>/.test(await src(page)), 'Ctrl+C / Ctrl+V: ההדבקה אחרי הנבחר, עם המחלקות', (await st(page)).note);
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    await select(page, '#t3');
    await paste(page, '<p onclick="steal()" class="x">טקסט<img src=x onerror="alert(1)"></p><script>alert(2)</script><a href=" javascript:alert(3)">קישור</a><iframe src="https://evil.example"></iframe>');
    await sleep(250); s1 = await src(page);
    ok(!/onclick|onerror|<script|javascript:|<iframe/i.test(s1) && /<p class="x">טקסט<img src="x"><\/p><a>קישור<\/a>/.test(s1) && /הוסרו/.test((await st(page)).note), 'הדבקה מבחוץ (עוינת): סקריפט, iframe, on… ו-javascript: — מוסרים, ומה שהוסר נאמר', { note: (await st(page)).note, s: s1.slice(s1.indexOf('<p id="t3"'), s1.indexOf('<p id="t3"') + 200) });
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    await select(page, '#t3'); await paste(page, '</div></main><p>x</p><main><div>');
    await sleep(250); s1 = await src(page);
    ok(/<p id="t3">שלישי<\/p><p>x<\/p><main><div><\/div><\/main><\/div>\n  <label for="nm">/.test(s1) && (await st(page)).inequiv === 0, 'הדבקה מבחוץ שסוגרת את הרובריקה ("</div></main>…") — מנורמלת לקטע סגור; הרובריקה לא נפתחת', s1.slice(s1.indexOf('<p id="t3"'), s1.indexOf('<p id="t3"') + 160));
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    await page.evaluate(() => { Editor._state.build.clip = { html: '<p>a</p></div><p>b', page: 't/page.html', sheets: [] }; });
    await select(page, '#t3'); await paste(page, null, null); await sleep(250);
    ok(same(await files(page), p0) && /אחרת ממה שנועד/.test((await st(page)).note), 'הדבקה מהלוח של העורך שהייתה סוגרת את הרובריקה — נדחית בבדיקת הניתוח מחדש, הקובץ לא משתנה', (await st(page)).note);
    await page.evaluate(() => { Editor._state.build.clip = null; });
    await paste(page, null, 'שורה & עוד'); await sleep(200);
    ok(/<p id="t3">שלישי<\/p><p>שורה &amp; עוד<\/p>/.test(await src(page)), 'טקסט רגיל מלוח המערכת — פסקה, עם בריחה', (await src(page)).slice((await src(page)).indexOf('<p id="t3"'), (await src(page)).indexOf('<p id="t3"') + 120));
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // אין אלמנט חובה: מחיקה עם אזהרה (label for="nm" מפנה אל #nm)
    await select(page, '#nm'); await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
    await page.keyboard.press('Delete'); await sleep(150);
    ok(/label מפנה אל #nm/.test(await page.textContent('#sheet')) && await page.isVisible('#sheet [data-choice="go"]'), 'מחיקה של #nm: אזהרה "label מפנה אל #nm", עם "בצע בכל זאת" ו"בטל" — לא חסימה');
    await page.click('#sheet [data-choice="cancel"]'); await sleep(100);
    ok(same(await files(page), p0), '"בטל" — לא נמחק');
    await page.keyboard.press('Delete'); await sleep(150); await page.click('#sheet [data-choice="go"]'); await sleep(200);
    ok(!/<input id="nm">/.test(await src(page)), '"בצע בכל זאת" — נמחק');
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // מקלדת: Alt + ↑ ↓ בין אחים; Alt + Shift — מוציא / מכניס
    await select(page, '#b'); await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
    await page.keyboard.press('Alt+ArrowUp'); await sleep(200);
    ok(/<main class="main">\n  <h2 id="b" class="hd">כותרת ב<\/h2>\n  <h2 id="a"/.test(await src(page)) && (await st(page)).sel === 'h2#b', 'Alt + ↑: h2#b עולה מעל h2#a (בלי צד שנבחר בתרשים הקופסה — הזזה, לא שוליים)');
    await page.keyboard.press('Alt+Shift+ArrowUp'); await sleep(200);
    ok(/<body>\n<h2 id="b" class="hd">כותרת ב<\/h2>\n<main/.test(await src(page)), 'Alt + Shift + ↑: מוציא מהרובריקה — לפני <main>');
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // הקוד והקנבס: הזזת שורות בקוד מזיזה את האלמנט בקנבס
    await page.evaluate(() => { const d = Editor._state.doc, t = d.text(), a = '  <h2 id="a" class="hd">כותרת א</h2>\n', b = '  <h2 id="b" class="hd">כותרת ב</h2>\n', i = t.indexOf(a); d.edit([{ from: i, to: i + a.length + b.length, text: b + a }], 'בקוד'); });
    await sleep(400);
    ok(await page.evaluate(() => { const d = Editor._state.canvas.doc; return d.querySelector('main').children[0].id === 'b'; }) && (await st(page)).same, 'הזזת שורות בעורך הקוד — האלמנט זז בקנבס');
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // "＋" בין אחים
    await page.evaluate(() => { Editor._state.canvas.setViewport(390); Editor._state.canvas.select(null); }); await sleep(150);   // קנבס ברוחב טלפון — בלי הקטנה
    const pa = await at(page, '#a', 0.5, 0.92); await page.mouse.move(pa.x + 30, pa.y - 60); await page.mouse.move(pa.x, pa.y, { steps: 4 }); await sleep(120);
    ok(!(await page.isVisible('.ed-plus[data-plus="after"]')), '"＋" אינו מופיע מיד (תזוזה עוברת ולחיצה מהירה אינן פוגשות אותו)'); await sleep(400);
    ok(await page.isVisible('.ed-plus[data-plus="after"]'), '"＋" מופיע בריחוף ליד השפה שבין אחים (לא באמצע האלמנט — כדי שלא יבלע לחיצות)', await page.evaluate(() => { const c = Editor._state.canvas, hv = c.hovered; return { hv: hv && c.label(hv), r: hv && c.rectOf(hv), w: Editor._state.build.canWrite(), scale: c.scale, ext: !!(c.ext && c.ext.active), edit: !!(c.edit && c.edit.active), press: !!c.press, dlg: !!document.querySelector('dialog[open]'), ev: c.events.slice(-4), pt: c.pt, at: (() => { const e = c.pt && c.doc.elementFromPoint(c.pt.x, c.pt.y); return e && e.outerHTML.slice(0, 60); })(), pick: c.pt && c.pick(c.doc.elementFromPoint(c.pt.x, c.pt.y)) && c.label(c.pick(c.doc.elementFromPoint(c.pt.x, c.pt.y))), locked: c.lockedNodes.size, plusN: !!Editor._state.build.plusN }; }), await page.evaluate(([x, y]) => document.elementFromPoint(x, y)?.outerHTML.slice(0, 100), [pa.x, pa.y]));
    await page.click('.ed-plus[data-plus="after"]'); await sleep(120); await page.click('#sheet .ed-lib-item[data-lib="heading"]'); await sleep(300); await page.keyboard.press('Escape'); await sleep(200);
    ok(/<h2 id="a" class="hd">כותרת א<\/h2>\n  <h2 class="hd">כותרת<\/h2>\n  <h2 id="b"/.test(await src(page)), '"＋" אחרי h2#a ← כותרת: בין כותרות היא מקבלת את התגית והמחלקות של השכנה (h2.hd)');
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    await page.evaluate(() => Editor._state.canvas.setViewport(1280)); await sleep(150);
    // רובריקה ריקה: הפאנל אומר שגובהה 0 בתצוגה החיה; תמונה בלי קובץ
    await select(page, '#t3'); await page.click('[data-ntab="lib"]'); await page.click('.ed-lib-item[data-lib="box"]'); await sleep(250);
    await page.evaluate(() => Editor._tab('adv')); await sleep(150);
    ok(await page.isVisible('[data-empty-note]') && /<p id="t3">שלישי<\/p><div><\/div>/.test(await src(page)), 'רובריקה ריקה נוספה אחרי הנבחר; הפאנל: "רובריקה ריקה — בתצוגה החיה גובהה 0"');
    await page.click('.ed-lib-item[data-lib="image"]'); await sleep(400);
    ok(await page.isVisible('#sheet[open] [data-pick-upload]'), 'תמונה מהספרייה — בחירת הקובץ נפתחת מיד');
    await page.keyboard.press('Escape'); await sleep(250);
    ok(/<div><\/div><img src="" alt="">|<div><img src="" alt=""><\/div>/.test(await src(page)) && /בלי קובץ/.test((await st(page)).note), 'ביטול הבחירה — האלמנט נשאר, "בלי קובץ"', (await st(page)).note);
    ok(await undoFiles(page, p0), 'בטל — חזרה');
    // רווח ברובריקה בזרימה רגילה: display:flex; flex-direction:column; gap — עסקה אחת; ערך עוין נדחה
    await select(page, '#box'); await page.evaluate(() => Editor._tab('adv')); await sleep(150);
    await page.fill('[data-blockgap]', '</style><script>alert(1)</script>'); await page.press('[data-blockgap]', 'Enter'); await sleep(200);
    ok(same(await files(page), p0), 'רווח: ערך עוין ("</style><script>…") — נדחה, אף קובץ לא השתנה', (await st(page)).note);
    await select(page, '#box'); await page.evaluate(() => Editor._tab('adv')); await sleep(150);
    await page.fill('[data-blockgap]', '12'); await page.press('[data-blockgap]', 'Enter'); await sleep(250);
    f1 = await files(page);
    ok(/\{[^}]*display: flex;[^}]*flex-direction: column;[^}]*row-gap: 12px;/.test(f1['css/page.css']) && await page.evaluate(() => getComputedStyle(Editor._state.canvas.doc.querySelector('#box')).rowGap) === '12px', 'רווח שהוקלד ברובריקה רגילה: display:flex; flex-direction:column; gap — והרווח נראה', f1['css/page.css'].slice(-160));
    ok(await undoFiles(page, p0), 'בטל — שני הקבצים חוזרים');
    ok((await st(page)).inequiv === 0, 'אין אי-שקילות במודל בעמוד הבדיקה');

    // ═══ העתק / הדבק בין עמודים ═══
    await openPage(page, 'index.html');
    await select(page, 'h1.kadisha-hero-title'); await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
    await page.keyboard.press('Control+c'); await sleep(120);
    const otherId = await page.evaluate(() => [...document.querySelectorAll('.ed-page option')].find(o => o.textContent === 't/other.html').value);
    await page.selectOption('.ed-page', otherId); await page.waitForFunction(() => Editor._state.file && Editor._state.file.path === 't/other.html' && !Editor._state.busy); await sleep(300);
    const o0 = await files(page);
    await select(page, '#o1'); await page.evaluate(() => Editor._state.canvas.doc.body.dispatchEvent(new ClipboardEvent('paste', { clipboardData: new DataTransfer(), bubbles: true, cancelable: true }))); await sleep(250);
    ok(/css\/page\.css/.test(await page.textContent('#sheet')) && await page.isVisible('#sheet [data-choice="link"]') && await page.isVisible('#sheet [data-choice="bare"]'), 'הדבקה בעמוד שאינו מקשר את קובצי העיצוב של האלמנט: "קשר את css/… לעמוד" (ברירת המחדל) או "הדבק בלי העיצוב"');
    await page.click('#sheet [data-choice="link"]'); await sleep(400);
    s1 = await src(page);
    ok(/<link rel="stylesheet" href="\.\.\/css\/page\.css">/.test(s1) && /<p id="o1">עמוד אחר<\/p>\n  <h1 id="lexicon-hero-title" class="kadisha-hero-title">קול מרוקו<\/h1>/.test(s1) && !Object.keys(await files(page)).some(k => k.endsWith('.css') && (o0[k] || '') !== (k in o0 ? o0[k] : '') ), 'בין עמודים: נוסף <link> לקבצים (נתיב יחסי), והאלמנט הודבק עם המחלקות — שום כלל לא שוכפל', { note: (await st(page)).note, s: s1.slice(s1.indexOf('<head>'), s1.indexOf('<head>') + 260) });
    ok(await page.evaluate(() => getComputedStyle(Editor._state.canvas.doc.querySelector('h1.kadisha-hero-title')).fontFamily) !== '' && (await st(page)).inequiv === 0, 'בקנבס — העיצוב של העמוד המקושר חל מיד');
    for (let i = 0; i < 20 && (await src(page)) !== o0['t/other.html']; i++) { await page.evaluate(() => Editor._undo()); await sleep(40); }
    ok((await src(page)) === o0['t/other.html'], 'בטל — העמוד האחר חוזר בייט בבייט (קבצי ה-CSS לא השתנו)');
    await select(page, '#o1'); await page.evaluate(() => Editor._state.canvas.doc.body.dispatchEvent(new ClipboardEvent('paste', { clipboardData: new DataTransfer(), bubbles: true, cancelable: true }))); await sleep(250);
    await page.click('#sheet [data-choice="bare"]'); await sleep(300);
    s1 = await src(page);
    ok(!/<link/.test(s1) && /<h1 id="lexicon-hero-title" class="kadisha-hero-title">קול מרוקו<\/h1>/.test(s1), '"הדבק בלי העיצוב" — האלמנט בלבד, בלי <link>');
    for (let i = 0; i < 20 && (await src(page)) !== o0['t/other.html']; i++) { await page.evaluate(() => Editor._undo()); await sleep(40); }
    ok((await src(page)) === o0['t/other.html'], 'בטל — חזרה');

    // ═══ 3. ביצועים: 1,496 מילים (60 בקנבס) ═══
    await openPage(page, 't/big.html');
    await page.evaluate(() => { Editor._state.canvas.verify = false; });       // מה שבעל העמוד מקבל; במצב מפתח נוסף ניתוח מלא לבדיקת השקילות
    await page.evaluate(() => { const d = Editor._state.build.drag, t = d.track.bind(d); window.__tt = []; d.track = (...a) => { const t0 = performance.now(); const r = t(...a); window.__tt.push(performance.now() - t0); return r; }; });
    const b0 = await src(page);
    from = await at(page, '.tools'); to = await at(page, 'li.card:nth-child(30)', 0.5, 0.2);
    await page.evaluate(PD); await page.evaluate(([x, y]) => window.__pd.down(x, y), [from.x, from.y]);
    for (let i = 1; i <= 30; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [from.x + (to.x - from.x) * i / 30, from.y + (to.y - from.y) * i / 30]); await sleep(18); }
    const top = await at(page, 'h1.big-title', 0.5, 0.15, 0, false);
    for (let i = 1; i <= 6; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [to.x + (top.x - to.x) * i / 6, to.y + (top.y - to.y) * i / 6]); await sleep(18); }
    to = top; await sleep(60);
    const tt = await page.evaluate(() => window.__tt), avg = tt.reduce((a, b) => a + b, 0) / Math.max(1, tt.length), max = Math.max(...tt);
    const dropMs = await page.evaluate(([x, y]) => { const t0 = performance.now(); window.__pd.up(x, y); return performance.now() - t0; }, [to.x, to.y]);
    await sleep(150);
    ok(tt.length >= 10 && avg < 12 && max < 33, `ביצועים: חישוב היעד והקו ${avg.toFixed(1)}ms בממוצע (מקס׳ ${max.toFixed(1)}) — מעל 30 תמונות בשנייה`, tt.length);
    ok(dropMs <= 100 && /<body>\n<p>כלים<\/p>\n<h1/.test(await src(page)), `ביצועים: השחרור (כתיבה, ניתוח, קנבס) ${dropMs.toFixed(0)}ms ≤ 100ms`, (await src(page)).slice(0, 400));

    // ═══ 4. טלפון (390px) ═══
    const cm = await mk(390, 844), mp = await cm.newPage();
    mp.on('pageerror', e => { errors.push('390: ' + e.message); });
    await openPage(mp, 't/page.html');
    const m0 = await files(mp);
    await select(mp, '#a'); await sleep(100);
    await mp.evaluate(() => { Editor._state.build.moveSheet(); }); await sleep(150);
    ok(await mp.isVisible('#sheet [data-mv="down"]') && await mp.isVisible('#sheet [data-moveto]'), 'טלפון: "העבר אל…" — ↑ ↓, הכנס / הוצא, ויעד בעץ');
    await mp.click('#sheet [data-mv="down"]'); await sleep(250);
    ok(/<main class="main">\n  <h2 id="b" class="hd">כותרת ב<\/h2>\n  <h2 id="a"/.test(await src(mp)), 'טלפון: ↓ — h2#a אחרי h2#b');
    await select(mp, '#t1'); await mp.evaluate(() => { Editor._state.build.moveSheet(); }); await sleep(150);
    await mp.click('#sheet [data-moveto-before="1.0"]').catch(() => {}); await sleep(250);
    ok(/<body>\n<p id="t1">ראשון<\/p>\n<main/.test(await src(mp)), 'טלפון: "העבר אל…" ← "לפני main" בעץ — כל יעד שהגרירה מגיעה אליו, גם מכאן', (await src(mp)).slice(0, 0));
    ok(await undoFiles(mp, m0), 'טלפון: בטל — חזרה');
    pk = await drag(mp, await at(mp, '#t3'), await at(mp, '#t1', 0.5, 0.15), { touch: true, hold: 450, steps: 8 });
    ok(/<div id="box"><p id="t3">שלישי<\/p><p id="t1">/.test(await src(mp)), 'טלפון: לחיצה ארוכה (400ms) ואז גרירה — #t3 לפני #t1', pk);
    ok(await undoFiles(mp, m0), 'טלפון: בטל — חזרה');
    await cm.close();

    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
