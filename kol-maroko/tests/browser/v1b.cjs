/**
 * פרוסה V1b — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/v1b.cjs
 *
 * מה שנשאר מ-V1: Navigator (עץ, חיפוש, קיפול, בחירה דו-כיוונית, 24,000 צמתים, 👁/🔒), "מוצג לעריכה" לאלמנט מוסתר
 * ולתוכן <template>, פאנל "פרטים", "בחר בעורך" ומבט מפוצל, "🐞 לא הצלחתי לשנות", גיליונות בטלפון, מקלדת ו-`?`,
 * המדיניות (CSP) של המסך, ומה שנדחה מ-V1a. רץ על index.html המוסב (F3) — מול tests/router.php ומסד זמני.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.KOL_SHOTS || '';
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

const EVIL = `"><img src=x onerror=parent.postMessage("pwned:class","*")>`;
const PAGE = `<!doctype html>
<html lang="he" dir="rtl"><head><meta charset="utf-8"><title>בדיקה</title>
<link rel="stylesheet" href="a.css"><link rel="stylesheet" href="sub/b.css">
<style>.inline-rule { letter-spacing: 3px; }
@media (max-width: 500px) { .hero-title { color: rgb(9, 9, 9); } }</style>
</head><body>
<h1 class="hero-title inline-rule" id="t1" style="margin-top: 7px">כותרת</h1>
<h2 class="sub">משנה</h2>
<div class='${EVIL}' id="evil-class">מחלקה זדונית</div>
<div id="gone" style="display:none"><p id="deep">מוסתר עמוק</p></div>
<div id="dlg" hidden><strong id="dw">חלון</strong></div>
<dialog id="real-dlg"><p id="in-dlg">חלון אמיתי</p></dialog>
<div id="zero"></div>
<table id="tb"><tr><td>תא</td></tr></table>
<ul id="cards"><template><li class="card"><b class="wordx">מילה</b><template><i class="deep-t">פנימי</i></template></li></template></ul>
<section class="wrap"><div class="in"><span>א</span><span>ב</span><span>ג</span></div></section>
</body></html>
`;

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v1b-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const st = page => page.evaluate(() => { const c = Editor._state.canvas, n = c && c.selected; return { sel: n ? c.label(n) : null, tag: n ? n.tag : null, src: c ? c.src : null, box: c ? !c.boxSel.hidden : false, boxText: c ? c.boxSel.textContent : '', vis: n ? c.visual(n) : '', where: document.querySelector('.ed-where').textContent, inequiv: c ? c.inequiv.length : -1, mapErrors: c ? c.mapErrors.length : -1 }; });
    const openPage = async (page, p) => { await page.goto(base + '/#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, p, { timeout: 30000 }); await sleep(150); };
    /** לחיצה אמיתית על שורה ב-Navigator לפי הטקסט שלה (אחרי חיפוש). */
    const navSearch = async (page, q) => { await page.fill('.nv-search', q); await sleep(260); };
    const navRows = page => page.$$eval('.nv-row', r => r.map(x => x.querySelector('.nv-lab').textContent));
    const navAll = page => page.evaluate(() => { const nav = Editor._state.nav; return nav.rows.map(r => nav.name(r.n) + (r.n.implied ? ' לא במקור' : '')); });
    const navClick = async (page, i = 0) => { await page.click(`.nv-row >> nth=${i} >> .nv-lab`); await sleep(120); };

    const ctx = await mk(1280, 800), page = await ctx.newPage();
    const errors = global.__errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => { window.__msgs = []; window.addEventListener('message', e => { if (typeof e.data === 'string' && e.data.startsWith('pwned')) window.__msgs.push(e.data); }); });
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await call(page, 'pf_create', { path: 't/page.html', content: PAGE });
    await call(page, 'pf_create', { path: 't/a.css', content: '.hero-title { color: rgb(1, 2, 3); margin-top: 3px; }\n.sub { color: red; }\nh1.hero-title { color: rgb(7, 7, 7) !important; }\n#t1 { color: rgb(5, 5, 5); }\n' });
    await call(page, 'pf_create', { path: 't/sub/b.css', content: '/* שני */\n\n.hero-title {\n  font-weight: 400;\n  color: rgb(4, 5, 6);\n}\n' });

    // ── 1. Navigator ──
    await openPage(page, 't/page.html');
    ok(await page.isVisible('.ed-nav .nv-list'), 'Navigator פתוח לצד הקנבס (1280px)');
    const rows0 = await navRows(page);
    ok(rows0[0].startsWith('html') && rows0.some(r => r.startsWith('head') && r.includes('ראש העמוד')) && rows0.some(r => r.startsWith('h1#t1') && r.includes('כותרת')), 'העץ: html, head ("ראש העמוד"), ושם שורה = תגית + מזהה + תחילת הטקסט');
    ok(!rows0.some(r => r.startsWith('title')), 'ראש העמוד מקופל בפתיחה');
    await page.click('.nv-row:has-text("ראש העמוד") .nv-tw'); await sleep(80);
    ok((await navRows(page)).some(r => r.startsWith('title')) && (await navRows(page)).some(r => r.startsWith('link')), 'פתיחת <head>: title, meta, link, style בעץ');
    await page.click('.nv-row:has-text("ראש העמוד") .nv-tw'); await sleep(80);
    ok(!(await navRows(page)).some(r => r.startsWith('title')), 'קיפול חוזר');
    const all0 = await navAll(page);
    ok(all0.includes('template') && all0.includes('li.card') && all0.includes('i.deep-t'), 'תוכן <template> (גם מקונן) מופיע בעץ');
    ok(all0.includes('tbody לא במקור'), '<tbody> שהדפדפן הוסיף — בעץ, מסומן "לא במקור"');
    // חיפוש
    await navSearch(page, '.card'); ok((await navRows(page)).length === 1 && (await navRows(page))[0].startsWith('li.card'), 'חיפוש לפי מחלקה (.card)');
    await navSearch(page, '#real'); ok((await navRows(page)).length === 1, 'חיפוש לפי מזהה (#real…)');
    await navSearch(page, 'span'); ok((await navRows(page)).length === 3, 'חיפוש לפי תגית (span) — 3');
    await navSearch(page, 'מוסתר עמוק'); ok((await navRows(page)).length === 1 && (await navRows(page))[0].startsWith('p#deep'), 'חיפוש לפי טקסט');
    // אלמנט מוסתר ← "מוצג לעריכה"
    const hiddenBefore = await page.evaluate(() => { const d = Editor._state.canvas.doc; return [getComputedStyle(d.querySelector('#gone')).display, d.documentElement.outerHTML.length]; });
    await navClick(page);
    let s = await st(page);
    ok(s.sel === 'p#deep' && s.box && s.vis === 'shown' && s.boxText.includes('מוצג לעריכה') && s.where.includes('מוצג לעריכה'), 'אלמנט בתוך display:none שנבחר ב-Navigator — מוצג בקנבס, מסומן "מוצג לעריכה" — ' + s.boxText);
    ok(await page.evaluate(() => Editor._state.canvas.src === Editor._state.doc.text() && !Editor._state.doc.dirty), 'הקובץ אינו משתנה כשאלמנט מוצג לעריכה');
    await navSearch(page, '');
    ok(await page.evaluate(() => { const l = document.querySelector('.nv-list'), r = document.querySelector('.nv-row.sel'); if (!r) return false; const a = r.getBoundingClientRect(), b = l.getBoundingClientRect(); return a.top >= b.top && a.bottom <= b.bottom; }), 'אחרי ניקוי החיפוש — הנבחר נראה בעץ (האבות נפתחו, נגלל אליו)');
    await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.m.root.kids[1]); }); await sleep(60);
    const hiddenAfter = await page.evaluate(() => { const d = Editor._state.canvas.doc; return [getComputedStyle(d.querySelector('#gone')).display, d.documentElement.outerHTML.length, d.querySelector('#gone').getAttribute('style')]; });
    ok(hiddenAfter[0] === 'none' && hiddenAfter[1] === hiddenBefore[1] && hiddenAfter[2] === 'display:none', 'כשהבחירה עוברת — חוזר להסתתר, וה-DOM של הקנבס כמו שהיה');
    for (const [q, label, what] of [['#dw', 'strong#dw', 'בתוך hidden'], ['#in-dlg', 'p#in-dlg', 'בתוך <dialog> סגור'], ['.wordx', 'b.wordx', 'בתוך <template>'], ['.deep-t', 'i.deep-t', 'בתוך <template> מקונן'], ['template', 'template', 'התבנית עצמה']]) {
      await navSearch(page, q); await navClick(page);
      s = await st(page);
      ok(s.sel === label && s.box && s.vis === 'shown', `${what}: נבחר ומוצג לעריכה — ${s.boxText}`);
    }
    await navSearch(page, '');
    const clean = await page.evaluate(() => { const c = Editor._state.canvas; c.select(null); return { clones: c.doc.querySelectorAll('[data-kol-canvas="shown"]').length, diffs: window.KolCanvas.sameDom(c.doc.documentElement, window.KolCanvas.browserParse(c.src).documentElement, true) }; });
    if (clean.diffs.length) console.log('   ' + JSON.stringify(clean.diffs.slice(0, 4)));
    ok(clean.clones === 0 && clean.diffs.length === 0, 'אחרי ביטול הבחירה: אין עותקי תבנית, והקנבס זהה לניתוח נקי של הקובץ');
    // אלמנט שאינו נראה (head) — נבחר, "פתח בקוד" פעיל
    await navSearch(page, 'title'); await navClick(page);
    s = await st(page);
    ok(s.sel === 'title' && s.vis === 'nonvisual' && s.where.includes('אינו נראה') && !(await page.isDisabled('.ed-bar .btn.sm')), 'title (ראש העמוד): נבחר ב-Navigator, "אינו נראה בעמוד", ו"פתח בקוד" פעיל');
    await navSearch(page, 'tbody'); await navClick(page);
    s = await st(page);
    ok(s.sel.startsWith('tbody') && s.where.includes('לא במקור') && s.box, 'tbody: נבחר, מסומן "לא במקור"');
    await navSearch(page, '#zero'); await navClick(page);
    ok((await st(page)).vis === 'zero' && await page.$('.ed-mark-zero') !== null, 'אלמנט בגודל אפס: נבחר, ויש לו מצביע בקנבס');
    await navSearch(page, '');
    // בחירה דו-כיוונית
    await page.evaluate(() => { const c = Editor._state.canvas, d = c.doc.querySelector('.in span:nth-child(2)'), r = d.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); window.__pt = { x: f.left + (r.left + r.width / 2) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; });
    const pt = await page.evaluate(() => window.__pt); await page.mouse.click(pt.x, pt.y); await sleep(120);
    ok(await page.$eval('.nv-row.sel .nv-lab', e => e.textContent.startsWith('span') && e.textContent.includes('ב')), 'לחיצה בקנבס ← השורה בעץ מסומנת');
    // מקלדת בעץ
    await page.focus('.nv-list'); await page.keyboard.press('ArrowDown'); await sleep(60);
    ok((await page.$eval('.nv-row.sel .nv-lab', e => e.textContent)).includes('ג'), 'חץ למטה בעץ — השורה הבאה נבחרת (גם בקנבס)');
    await page.keyboard.press('ArrowLeft'); await sleep(60);
    ok((await st(page)).sel === 'div.in', 'חץ שמאלה בעץ — ההורה');
    await page.keyboard.press('ArrowLeft'); await sleep(60);
    ok(await page.$eval('.nv-row.sel', e => e.getAttribute('aria-expanded') === 'false'), 'חץ שמאלה שוב — מקפל');
    await page.keyboard.press('ArrowRight'); await sleep(60);
    // 👁 / 🔒
    await page.keyboard.press('h'); await sleep(80);
    ok(await page.evaluate(() => { const c = Editor._state.canvas; return c.hiddenNodes.size === 1 && c.src === Editor._state.doc.text() && !Editor._state.doc.dirty; }), '👁 (H): מוסתר בעורך; הקובץ אינו משתנה');
    await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.m.root.kids[1]); }); await sleep(60);
    ok(await page.evaluate(() => getComputedStyle(Editor._state.canvas.doc.querySelector('.in')).display === 'none'), '👁: האלמנט אינו מוצג בקנבס (כשאינו הנבחר)');
    await navSearch(page, '.sub'); await page.click('.nv-row [data-act="lock"]'); await sleep(60); await navSearch(page, '');
    const subPt = await page.evaluate(() => { const c = Editor._state.canvas; c.select(null); const r = c.doc.querySelector('.sub').getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + 20) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; });
    await page.mouse.click(subPt.x, subPt.y); await sleep(80);
    ok((await st(page)).sel === 'body', '🔒: לחיצה בקנבס על אלמנט נעול אינה בוחרת אותו (נבחר ההורה)');
    await openPage(page, 't/page.html');
    ok(await page.evaluate(() => { const c = Editor._state.canvas; return c.hiddenNodes.size === 1 && c.lockedNodes.size === 1 && [...c.lockedNodes][0].tag === 'h2' && [...c.hiddenNodes][0].tag === 'div'; }), '👁 ו-🔒 נשמרים בדפדפן לכל עמוד (אחרי טעינה מחדש)');
    await page.evaluate(() => { const c = Editor._state.canvas; for (const n of [...c.hiddenNodes]) c.setHidden(n, false); c.lockedNodes.clear(); Editor._marks(); });
    // טקסט בלבד: מחלקה שהיא HTML
    await navSearch(page, '#evil-class');
    ok(await page.evaluate(() => !document.querySelector('.ed img[src="x"]') && !document.querySelector('.nv-list img')) && (await page.evaluate(() => window.__msgs)).length === 0, 'מחלקה בשם "><img onerror> מוצגת ב-Navigator כטקסט בלבד');
    await navClick(page); await navSearch(page, '');

    // ── 2. index.html המוסב (F3): לכל צומת יש דרך לבחור ──
    await openPage(page, 'index.html');
    const cover = await page.evaluate(async () => {
      const c = Editor._state.canvas, K = window.KolSrc, nav = Editor._state.nav, all = K.all(c.m);
      const out = { total: all.length, broken: c.m.marks, canvas: 0, shown: 0, zero: 0, nonvisual: 0, unreachable: [], noRow: 0, inTemplate: 0 };
      for (const n of all) {
        c.select(n);                                                        // מה שלחיצה על השורה ב-Navigator עושה
        nav.sync();
        if (!nav.index.has(n)) out.noRow++;
        const v = c.visual(n);
        if (v === 'visible') out.canvas++; else if (v === 'shown') out.shown++; else if (v === 'zero') out.zero++; else if (v === 'nonvisual') out.nonvisual++; else out.unreachable.push(c.label(n));
        for (let p = n.parent; p; p = p.parent) if (p.tag === 'template') { out.inTemplate++; break; }
      }
      c.select(null);
      return out;
    });
    console.log('   index.html: ' + JSON.stringify({ ...cover, unreachable: cover.unreachable.slice(0, 8) }));
    ok(cover.broken === 0, 'index.html המוסב: 0 אזורים שבורים');
    ok(cover.noRow === 0, `index.html: לכל ${cover.total} הצמתים יש שורה ב-Navigator (גם מקופלים — הבחירה פותחת אותם)`);
    ok(cover.unreachable.length === 0, `index.html: כל צומת שאינו בראש העמוד נראה בקנבס כשהוא נבחר (${cover.canvas} נראים, ${cover.shown} מוצגים לעריכה, ${cover.zero} בגודל אפס, ${cover.nonvisual} בלי מראה)` + (cover.unreachable.length ? ' — חסרים: ' + cover.unreachable.slice(0, 6).join(', ') : ''));
    ok(cover.inTemplate > 0 && cover.shown > 0, `index.html: ${cover.inTemplate} צמתים בתוך <template> — נבחרים ומוצגים`);
    // הכרטיס, כפתור הנושא, המבוא וחלון המשוב — בלחיצה אמיתית ב-Navigator
    const targets = await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc, all = K.all(c.m), inT = n => { for (let p = n.parent; p; p = p.parent) if (p.tag === 'template') return true; return false; };
      const pick = (name, f) => { const n = all.find(f); return n ? [name, c.label(n), K.attrOf(n, 'id') ? '#' + K.attrOf(n, 'id').value : '.' + K.classesOf(n)[0]] : [name, null, null]; };
      return [pick('כרטיס מילה (בתבנית)', n => inT(n) && n.tag !== 'template' && K.classesOf(n).length && n.parent.tag === 'template' && n.parent.parent && K.attrOf(n.parent.parent, 'data-kol-list')),
        pick('כפתור נושא (בתבנית)', n => inT(n) && K.classesOf(n).length && n.parent.tag === 'template' && n.parent.parent && K.attrOf(n.parent.parent, 'data-kol-filter')),
        pick('חלון המשוב', n => !!K.attrOf(n, 'data-kol-dialog')),
        pick('אלמנט מוסתר (hidden)', n => !!K.attrOf(n, 'hidden') && !K.attrOf(n, 'data-kol-dialog') && !inT(n) && K.classesOf(n).length > 0)]; });
    for (const [name, label, q] of targets) {
      if (!label) { ok(false, name + ': לא נמצא ב-index.html'); continue; }
      await navSearch(page, q); await navClick(page);
      s = await st(page);
      ok(s.box && (s.vis === 'shown' || s.vis === 'visible'), `index.html · ${name} (${label}): נבחר ב-Navigator ומוצג בקנבס — ${s.vis === 'shown' ? s.boxText : 'נראה'}`);
    }
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'v1b-1280-index-shown.png') });
    await navSearch(page, '');

    // ── 3. 24,000 צמתים ──
    const big = '<!doctype html><html><head><title>big</title></head><body><main>' + Array.from({ length: 4000 }, (_, i) => `<article class="c"><h3>כותרת ${i}</h3><p><b>א</b><i>ב</i><span>ג</span></p></article>`).join('\n') + '</main></body></html>';
    await call(page, 'pf_create', { path: 't/big.html', content: big });
    await openPage(page, 't/big.html');
    const bigR = await page.evaluate(async () => {
      const c = Editor._state.canvas, nav = Editor._state.nav, K = window.KolSrc, list = nav.list, sleep = ms => new Promise(r => setTimeout(r, ms));
      const t0 = performance.now(); nav.refresh(); const build = performance.now() - t0;
      const domRows = () => list.querySelectorAll('.nv-row').length;
      const a = domRows(), frames = [];
      for (let i = 1; i <= 20; i++) { const t = performance.now(); list.scrollTop = (list.scrollHeight - list.clientHeight) * i / 20; nav.draw(); frames.push(performance.now() - t); await sleep(0); }
      const lastRow = [...list.querySelectorAll('.nv-row')].pop().textContent;
      const deep = K.all(c.m)[20000]; const t1 = performance.now(); c.select(deep); const selMs = performance.now() - t1; await sleep(50);
      const selRow = list.querySelector('.nv-row.sel');
      return { nodes: c.m.count, rows: nav.rows.length, build, a, b: domRows(), maxFrame: Math.max(...frames), lastRow, selMs, selVisible: !!selRow && selRow.getBoundingClientRect().top >= list.getBoundingClientRect().top && selRow.getBoundingClientRect().bottom <= list.getBoundingClientRect().bottom };
    });
    console.log('   24K: ' + JSON.stringify(bigR));
    ok(bigR.nodes >= 24000 && bigR.rows >= 24000, `קובץ עם ${bigR.nodes} צמתים — כולם בעץ (${bigR.rows} שורות)`);
    ok(bigR.a < 80 && bigR.b < 80, `רק השורות הנראות ב-DOM (${bigR.a}, ${bigR.b} מתוך ${bigR.rows})`);
    ok(bigR.build < 300 && bigR.maxFrame < 50, `בלי תקיעה: בניית הרשימה ${bigR.build.toFixed(1)}ms, ציור בגלילה עד ${bigR.maxFrame.toFixed(1)}ms`);
    ok(bigR.lastRow.includes('ג') && bigR.selVisible, 'גלילה עד הסוף מציגה את השורה האחרונה; בחירה של צומת רחוק גוללת אליו');

    ok(errors.length === 0, 'אין שגיאות JavaScript בדף (1280px)' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
    await ctx.close();
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e && e.stack || e) + (global.__errors && global.__errors.length ? '\n   שגיאות בדף: ' + global.__errors.slice(0, 3).join(' | ') : '')); }
  finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${ran - failed}/${ran} עברו` + (failed ? ` — ${failed} נכשלו` : ''));
  process.exit(failed ? 1 : 0);
})();
