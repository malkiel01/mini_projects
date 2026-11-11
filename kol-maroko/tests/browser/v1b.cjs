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
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, p, { timeout: 30000 }); await sleep(150); };
    /** לחיצה אמיתית על שורה ב-Navigator לפי הטקסט שלה (אחרי חיפוש). */
    const navSearch = async (page, q) => { await page.fill('.nv-search', q); await sleep(260); };
    const navRows = page => page.$$eval('.nv-row', r => r.map(x => x.querySelector('.nv-lab').textContent));
    const navAll = page => page.evaluate(() => { const nav = Editor._state.nav; return nav.rows.map(r => nav.name(r.n) + (r.n.implied ? ' לא במקור' : '')); });
    const navClick = async (page, i = 0) => { await page.click(`.nv-row >> nth=${i} >> .nv-lab`); await sleep(120); };

    const ctx = await mk(1280, 800), page = await ctx.newPage();
    const errors = global.__errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
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

    // ── 1ב. פאנל "פרטים" ──
    ok(await page.isVisible('.ed-info .dt'), 'פאנל "פרטים" פתוח לצד הקנבס (1280px)');
    ok(!(await page.evaluate(() => document.querySelector('.dt img'))) && (await page.$eval('.dt', e => e.textContent)).includes('onerror=') && (await page.evaluate(() => window.__msgs)).length === 0, 'מחלקה בשם "><img onerror> מוצגת ב"פרטים" כטקסט בלבד');
    await navSearch(page, '#t1'); await navClick(page); await navSearch(page, '');
    const info = await page.evaluate(() => { const d = Editor._state.info.last; return { text: document.querySelector('.dt').textContent, rules: d.rules.map(r => ({ sel: r.sel, path: r.path, line: r.line, off: !!r.off, media: r.media, decls: r.decls.map(x => x.prop + ':' + x.value + (x.important ? '!' : '') + '@' + x.line + (x.by ? ' <' + x.by.sel : '')) })) }; });
    const rule = (sel, p) => info.rules.find(r => r.sel === sel && r.path === p);
    ok(info.text.includes('<h1>') && info.text.includes('#t1') && info.text.includes('.hero-title') && info.text.includes('.inline-rule') && info.text.includes('t/page.html') && info.text.includes('שורה 7'), 'פרטים: תגית, מזהה, מחלקות, הקובץ והשורה');
    ok(info.rules.length === 7, 'פרטים: 7 כללים חלים על h1 (שני קבצים מקושרים, <style> בדף, @media, ו-style=) — ' + info.rules.length);
    ok(rule('.hero-title', 't/a.css')?.line === 1 && rule('#t1', 't/a.css')?.line === 4 && rule('.hero-title', 't/sub/b.css')?.line === 3 && rule('.hero-title', 't/sub/b.css').decls[0].startsWith('font-weight:400@4'), 'פרטים: לכל כלל הקובץ והשורה שלו, ולכל הצהרה השורה שלה');
    ok(rule('.inline-rule', 't/page.html')?.line === 4 && rule('.inline-rule', 't/page.html').decls[0] === 'letter-spacing:3px@4', 'פרטים: כלל מתוך <style> שבדף — השורה בקובץ ה-HTML');
    const colors = info.rules.filter(r => !r.off).flatMap(r => r.decls.filter(d => d.startsWith('color:')));
    ok(colors.length === 4 && colors.filter(d => d.includes(' <h1.hero-title')).length === 3 && colors.some(d => d.startsWith('color:rgb(7, 7, 7)!') && !d.includes(' <')), 'פרטים: מה נדרס — שלוש הצהרות color נדרסות בידי h1.hero-title !important');
    ok(rule('.hero-title', 't/a.css').decls.some(d => d.startsWith('margin-top:3px') && d.includes(' <style=')) && rule('style="…"', 't/page.html').decls[0] === 'margin-top:7px@7', 'פרטים: style= שבתגית דורס את margin-top של הקובץ, עם השורה ב-HTML');
    const mq = info.rules.find(r => r.media);
    ok(mq && mq.off && mq.media.includes('max-width') && info.text.includes('אינו חל ברוחב הנוכחי'), 'פרטים: כלל בתוך @media שאינו חל ברוחב הזה — מוצג, מסומן, ואינו משתתף במפל');
    ok(await page.evaluate(() => getComputedStyle(Editor._state.canvas.doc.querySelector('#t1')).color === 'rgb(7, 7, 7)'), 'המפל שבפאנל תואם לדפדפן (הצבע בפועל — של הכלל המנצח)');
    await page.click('.dt-decl:has-text("font-weight")');
    await page.waitForFunction(() => location.hash.startsWith('#/project') && Project._state.editor && Project._state.editor.getModel() && Project._state.byId.get(Project._state.cur)?.path === 't/sub/b.css' && Project._state.editor.getPosition().lineNumber === 4, null, { timeout: 8000 }).then(() => ok(true, '"פתח בקוד" מהצהרה: עורך הקוד נפתח על קובץ ה-CSS, בשורה של ההצהרה'), () => ok(false, '"פתח בקוד" מהצהרה: עורך הקוד נפתח על קובץ ה-CSS, בשורה של ההצהרה'));
    // CSS שבור: סוגר חסר — הפאנל אומר איפה
    await call(page, 'pf_write', { id: (await call(page, 'pf_tree')).files.find(f => f.path === 't/sub/b.css').id, rev: (await call(page, 'pf_tree')).files.find(f => f.path === 't/sub/b.css').rev, content: '.hero-title {\n  font-weight: 400;\n\n.sub { color: blue; }\n' });
    await openPage(page, 't/page.html');
    await navSearch(page, '#t1'); await navClick(page); await navSearch(page, '');
    ok((await page.$eval('.dt', e => e.textContent)).includes('שגיאה ב-CSS בשורה') && await page.$('.dt-note .btn') !== null, 'CSS עם סוגר חסר (03 §2.3): הפאנל מציג "שגיאה ב-CSS בשורה N", עם "פתח בקוד"');

    // ── 1ג. "בחר בעורך" ומבט מפוצל ──
    await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
    await page.evaluate(async () => { await Project.openAt('t/page.html', 9); });
    await page.waitForFunction(() => Project._state.editor && Project._state.editor.getModel() && Project._state.editor.getPosition().lineNumber === 9);
    const hasAction = await page.evaluate(() => { const a = Project._state.editor.getAction('kol.pickInEditor'); return !!a && a.isSupported(); });
    ok(hasAction, 'עורך הקוד, קובץ HTML: הפעולה "בחר בעורך החזותי" קיימת (תפריט ההקשר)');
    await page.evaluate(() => { const ed = Project._state.editor, m = ed.getModel(); ed.setPosition(m.getPositionAt(m.getValue().indexOf('מחלקה זדונית') + 2)); ed.focus(); });
    await page.click('.pj-ed .view-lines', { button: 'right', position: { x: 300, y: 300 } }).catch(() => {});
    ok(await page.locator('.monaco-menu .action-label', { hasText: 'בחר בעורך החזותי' }).count() > 0, 'תפריט ההקשר של עורך הקוד מציג "בחר בעורך החזותי"');
    await page.keyboard.press('Escape');
    await page.evaluate(() => { const ed = Project._state.editor, m = ed.getModel(); ed.setPosition(m.getPositionAt(m.getValue().indexOf('מחלקה זדונית') + 2)); ed.focus(); });
    await page.keyboard.press('Control+Alt+e');
    await page.waitForFunction(() => location.hash.startsWith('#/editor') && Editor._state.canvas && Editor._state.canvas.selected, null, { timeout: 15000 }).catch(() => {});
    await sleep(200);
    ok((await st(page)).sel === 'div#evil-class', '"בחר בעורך" בקיצור (Ctrl+Alt+E): העורך החזותי נפתח, והאלמנט שהסמן בתוכו נבחר — ' + (await st(page)).sel);
    await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
    await page.evaluate(async () => { await Project.openAt('t/a.css', 1); });
    ok(await page.evaluate(() => { const a = Project._state.editor.getAction('kol.pickInEditor'); return !a || !a.isSupported(); }), 'בקובץ CSS הפעולה אינה זמינה');
    // מבט מפוצל
    await openPage(page, 't/page.html');
    await page.click('[data-split]'); await page.waitForSelector('.ed-code .monaco-editor .view-lines'); await sleep(300);
    const lay2 = await page.evaluate(() => { const a = document.querySelector('.ed-canvas').getBoundingClientRect(), b = document.querySelector('.ed-code').getBoundingClientRect(); return { side: Math.abs(a.top - b.top) < 4 && a.width > 200 && b.width > 200 && (a.right <= b.left + 2 || b.right <= a.left + 2), same: Project._state.split.getModel() === Project._state.docs.get(Editor._state.file.id).model }; });
    ok(lay2.side && lay2.same, 'מבט מפוצל (1280px): קוד לצד הקנבס, על אותו מסמך');
    await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.d2m.get(c.doc.querySelector('.sub'))); }); await sleep(100);
    ok(await page.evaluate(() => { const sp = Editor._state.code, r = sp.selection(), t = Editor._state.doc.text(); return t.slice(r.from, r.to) === '<h2 class="sub">משנה</h2>'; }), 'בחירה בקנבס מסמנת את הטווח של האלמנט בקוד');
    await page.evaluate(() => { const ed = Project._state.split, m = ed.getModel(); ed.focus(); ed.setPosition(m.getPositionAt(m.getValue().indexOf('<td>') + 5)); });
    await page.keyboard.press('ArrowRight'); await sleep(450);
    ok((await st(page)).sel === 'td', 'הסמן בקוד (מקלדת) מסמן את האלמנט בקנבס — ' + (await st(page)).sel);
    await page.keyboard.type('XY'); await sleep(450);
    ok(await page.evaluate(() => Editor._state.canvas.doc.querySelector('td').textContent.includes('XY') && Editor._state.canvas.selected.tag === 'td'), 'הקלדה בקוד שבמבט המפוצל מעדכנת את הקנבס, והנבחר נשאר נבחר');
    await page.keyboard.press('Control+z'); await page.keyboard.press('Control+z'); await sleep(300);
    ok(await page.evaluate(src => Editor._state.doc.text() === src, PAGE), 'ביטול בקוד מחזיר את הקובץ למקור (אותה היסטוריה)');
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'v1b-1280-split.png') });
    await page.click('[data-split]'); await sleep(100);
    ok(!(await page.isVisible('.ed-code')), 'כיבוי המבט המפוצל');

    // ── 1ד. "🐞 לא הצלחתי לשנות את זה" ──
    const rows = sql => JSON.parse(execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1] . "/kol.sqlite"); echo json_encode($p->query($argv[2])->fetchAll(PDO::FETCH_ASSOC));', data, sql]).toString());
    const canvasPt = (pg, sel) => pg.evaluate(q => { const c = Editor._state.canvas, d = c.doc.querySelector(q); d.scrollIntoView({ block: 'center' }); const r = d.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + Math.min(r.width / 2, 14)) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; }, sel);
    await page.evaluate(() => Editor._state.canvas.select(null));
    await page.click('[data-bug]');
    ok(await page.isVisible('.ed-point') && (await page.textContent('.ed-point')).includes('מצביעים'), '🐞 בסרגל: מצב הצבעה, עם הנחיה');
    let pt2 = await canvasPt(page, '.sub'); await page.mouse.click(pt2.x, pt2.y);
    await page.waitForSelector('#sheet[open] .ed-shot img', { timeout: 15000 });
    await page.waitForFunction(() => { const i = document.querySelector('.ed-shot img'); return i.complete && i.naturalWidth > 0; });
    const rep = await page.evaluate(() => ({ info: document.querySelector('.ed-report-info').textContent, w: document.querySelector('.ed-shot img').naturalWidth, chart: document.querySelector('.ed-shot').textContent.includes('תרשים'), pointing: !document.querySelector('.ed-point').hidden, sel: Editor._state.canvas.label(Editor._state.canvas.selected) }));
    ok(rep.sel === 'h2.sub' && !rep.pointing, 'הצבעה על אלמנט בקנבס פותחת את הדיווח עליו');
    ok(rep.w >= 600 && !rep.chart, `צילום הקנבס מוצג בדיווח (${rep.w}px, צילום מלא ולא תרשים)`);
    ok(rep.info.includes('שביל: html › body › h2.sub') && rep.info.includes('תגית: <h2> · מחלקות: .sub') && rep.info.includes('קובץ: t/page.html · שורות 8') && /רוחב הקנבס: 1280px/.test(rep.info), 'הדיווח כולל שביל, תגית ומחלקות, קובץ ושורות, ורוחב הקנבס');
    await page.fill('#sheet textarea', 'רציתי להגדיל את הכותרת');
    await page.click('[data-bug-send]');
    await page.waitForFunction(() => /נפתחה מטלה KM-\d+/.test(document.querySelector('#sheet').textContent), null, { timeout: 15000 });
    const code1 = await page.evaluate(() => Editor._state.lastReport.code);
    const t1 = rows(`SELECT code, area, kind, status, detail FROM dev_tasks WHERE code = '${code1}'`)[0], f1 = rows(`SELECT mime, size FROM dev_files WHERE code = '${code1}'`);
    ok(/^KM-\d+$/.test(code1) && t1 && t1.area === 'לא הצלחתי לשנות' && t1.kind === 'bug' && t1.status === 'open', `נפתחה מטלת DevLoop ${code1}, תווית "לא הצלחתי לשנות"`);
    ok(t1.detail.includes('מה ניסיתי לשנות: רציתי להגדיל את הכותרת') && t1.detail.includes('אלמנט: h2.sub') && t1.detail.includes('שביל: html › body › h2.sub') && t1.detail.includes('שורות 8') && t1.detail.includes('1280px'), 'במטלה: הטקסט החופשי, האלמנט, השביל, הקובץ והשורות, הרוחב');
    ok(f1.length === 1 && f1[0].mime === 'image/png' && f1[0].size > 2000, `הצילום צורף למטלה (PNG, ${f1[0] && f1[0].size} בייט)`);
    ok((await page.$$eval('.ed-mine-row', r => r.map(x => x.textContent))).some(x => x.includes(code1) && x.includes('פתוח')), 'המדווח רואה את המטלה שלו ואת מצבה');
    await page.click('#sheet [data-close]');
    // תפריט ההקשר
    pt2 = await canvasPt(page, '#t1'); await page.mouse.click(pt2.x, pt2.y, { button: 'right' });
    await page.waitForSelector('#sheet[open] .ed-menu');
    const menuItems = await page.$$eval('.ed-menu-item', b => b.map(x => x.textContent));
    ok(menuItems.some(x => x.includes('בחר הורה')) && menuItems.some(x => x.includes('פתח בקוד')) && menuItems.some(x => x.includes('לא הצלחתי לשנות')) && (await st(page)).sel === 'h1#t1', 'לחיצה ימנית על אלמנט: תפריט הקשר עם "בחר הורה", "פתח בקוד", "🐞 לא הצלחתי"');
    await page.click('.ed-menu-item:has-text("לא הצלחתי")');
    await page.waitForSelector('#sheet[open] [data-bug-send]');
    await page.click('[data-bug-send]');                                   // בלי טקסט — לא חובה
    await page.waitForFunction(() => /נפתחה מטלה KM-\d+/.test(document.querySelector('#sheet').textContent), null, { timeout: 15000 });
    await page.click('#sheet [data-close]');
    // מקלדת בלבד: B ← "האלמנט הנבחר" ← שליחה — ושוב על h2.sub (לקיבוץ)
    await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.d2m.get(c.doc.querySelector('.sub'))); document.querySelector('.nv-list').focus(); });
    await page.keyboard.press('b');
    ok(await page.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-bug-selected')), 'מקלדת: B פותח את מצב ההצבעה, והמיקוד על "האלמנט הנבחר"');
    await page.keyboard.press('Enter');
    await page.waitForSelector('#sheet[open] [data-bug-send]');
    await page.focus('[data-bug-send]'); await page.keyboard.press('Enter');
    await page.waitForFunction(() => /נפתחה מטלה KM-\d+/.test(document.querySelector('#sheet').textContent), null, { timeout: 15000 });
    await page.keyboard.press('b'); await sleep(60);
    ok(await page.evaluate(() => document.querySelector('.ed-point').hidden), 'כשגיליון פתוח — קיצורי העורך אינם פעילים');
    await page.click('#sheet [data-close]'); await sleep(100);
    await page.keyboard.press('b'); await page.keyboard.press('Escape'); await sleep(60);
    ok(await page.evaluate(() => document.querySelector('.ed-point').hidden && !Editor._state.canvas.onPoint), 'Esc מבטל את מצב ההצבעה');
    // אלמנט מוסתר / בתבנית — גם עליו מדווחים
    await navSearch(page, '.wordx'); await navClick(page); await navSearch(page, '');
    await page.keyboard.press('Shift+F10').catch(() => {});
    await page.evaluate(() => { if (!document.querySelector('#sheet[open] .ed-menu')) Editor._ctx(Editor._state.canvas.selected); });
    await page.click('.ed-menu-item:has-text("לא הצלחתי")');
    await page.waitForSelector('#sheet[open] .ed-shot img'); await page.click('[data-bug-send]');
    await page.waitForFunction(() => /נפתחה מטלה KM-\d+/.test(document.querySelector('#sheet').textContent), null, { timeout: 15000 });
    await page.click('#sheet [data-close]');
    const stuckRows = rows("SELECT code, detail FROM dev_tasks WHERE area = 'לא הצלחתי לשנות'");
    ok(stuckRows.length === 4 && stuckRows.some(r => r.detail.includes('אלמנט: b.wordx') && r.detail.includes('הוצג לעריכה')), 'דיווח על אלמנט שבתוך <template> (מוצג לעריכה) — נפתח, ומציין שהוא מוסתר בעמוד');
    // מסך המפתח: מונה, מקובץ לפי אלמנט
    await page.goto(base + '/#/dev/tasks'); await page.waitForSelector('[data-stuck]');
    const stuck = await page.evaluate(() => ({ n: document.querySelector('[data-stuck]').dataset.stuck, text: document.querySelector('.dev-stuck').textContent, groups: document.querySelectorAll('.dev-stuck li').length }));
    ok(stuck.n === '4' && stuck.groups === 3 && /h2\.sub · t\/page\.html — 2/.test(stuck.text), `מסך המפתח: מונה "לא הצלחתי לשנות" — ${stuck.n} פתוחות, מקובצות לפי אלמנט (${stuck.groups} אלמנטים; h2.sub — 2)`);
    // חבר שאינו המפתח מדווח, ורואה רק את שלו
    await call(page, 'member_add', { email: 'ed@example.test', role: 'editor' });
    const ctxE = await mk(1280, 800, { sub: 'u-ed', name: 'Ed Member', email: 'ed@example.test', email_verified: true }), pe = await ctxE.newPage();
    const errE = []; pe.on('pageerror', e => errE.push(e.message));
    await pe.goto(base + '/#/project'); await pe.waitForSelector('.pj-tree .pj-row');
    await openPage(pe, 't/page.html');
    await pe.click('[data-bug]'); pt2 = await canvasPt(pe, '#t1'); await pe.mouse.click(pt2.x, pt2.y);
    await pe.waitForSelector('#sheet[open] .ed-shot img'); await pe.click('[data-bug-send]');
    await pe.waitForFunction(() => /נפתחה מטלה KM-\d+/.test(document.querySelector('#sheet').textContent), null, { timeout: 15000 });
    const mineE = await pe.$$eval('.ed-mine-row', r => r.map(x => x.textContent));
    const codeE = await pe.evaluate(() => Editor._state.lastReport);
    ok(mineE.length === 1 && mineE[0].includes(codeE.code) && codeE.attached, `חבר בתפקיד "עורך" (לא המפתח) מדווח, עם צילום, ורואה רק את הדיווח שלו (${codeE.code})`);
    ok((await call(pe, 'dev-items')).items.length === 0 && !(await pe.$$eval('#tabs a', a => a.some(x => x.textContent.includes('מפתח')))), 'לחבר שאינו המפתח אין מסך מטלות — רק הדיווחים שלו');
    ok(errE.length === 0, 'אין שגיאות JavaScript אצל החבר' + (errE.length ? ': ' + errE[0] : ''));
    await ctxE.close();

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

    // ── 3ב. המדיניות (C1 שלב ב): המסך הוא מסמך משלו, תחת CSP מלא ──
    const resp = await ctx.request.get(base + '/editor.php');
    const csp = resp.headers()['content-security-policy'] || '';
    ok(/default-src 'none'/.test(csp) && /script-src 'self'(;|$)/.test(csp) && !/unsafe-eval/.test(csp) && !/script-src[^;]*unsafe-inline/.test(csp) && /connect-src 'self'/.test(csp) && /object-src 'none'/.test(csp) && /base-uri 'self'/.test(csp) && /frame-ancestors 'self'/.test(csp), 'editor.php: כותרת CSP מלאה (C1 שלב ב) — ' + csp.slice(0, 90) + '…');
    ok(/frame-src [^;]*\/live\.php [^;]*\/preview\//.test(csp) && !/frame-src[^;]*\*/.test(csp) && (resp.headers()['x-content-type-options'] || '') === 'nosniff', 'editor.php: frame-src — רק התצוגה החיה והתצוגה המבודדת; nosniff');
    await page.goto(base + '/#/editor/' + encodeURIComponent('t/page.html'));
    await page.waitForFunction(() => location.pathname.endsWith('/editor.php') && typeof Editor !== 'undefined' && Editor._state.file && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, null, { timeout: 30000 });
    ok(new URL(page.url()).hash === '#/editor/' + encodeURIComponent('t/page.html'), 'index.html#/editor מפנה ל-editor.php — העורך נפתח תמיד תחת המדיניות');
    const tabsH = await page.$$eval('#tabs a', a => a.map(x => x.getAttribute('href')));
    ok(tabsH.includes('#/editor') && tabsH.includes('#/project') && tabsH.includes('index.html#/words') && tabsH.includes('index.html#/studio'), 'במסמך המוקשח: העורך ועורך הקוד במקום; שאר הלשוניות מובילות ל-index.html');
    const blocked = await page.evaluate(async () => {
      const out = {}, v = []; document.addEventListener('securitypolicyviolation', e => v.push(e.effectiveDirective));
      const s = document.createElement('script'); s.textContent = 'window.__inline = 1'; document.head.append(s); out.inline = window.__inline === 1;
      // eval נבדק מחוץ לקריאת הבדיקה עצמה (setTimeout): כלי האוטומציה מתיר eval בקוד שהוא עצמו מריץ, והדף — לא
      await new Promise(r => setTimeout(() => { try { out.evalRan = (0, eval)('1+1') === 2; } catch { out.evalRan = false; } try { out.fn = new Function('return 1')() === 1; } catch { out.fn = false; } r(); }, 0));
      const d = document.createElement('div'); d.innerHTML = '<img src=x onerror="window.__h = 1">'; document.body.append(d); await new Promise(r => setTimeout(r, 300)); out.handler = window.__h === 1; d.remove();
      out.img = await new Promise(r => { const i = new Image(); i.onload = () => r(true); i.onerror = () => r(false); i.src = 'https://example.com/x.png'; setTimeout(() => r(false), 2500); });
      try { await fetch('https://example.com/', { mode: 'no-cors' }); out.fetch = true; } catch { out.fetch = false; }
      const c = Editor._state.canvas, cd = c.doc, ci = cd.createElement('img'); out.canvasImg = await new Promise(r => { ci.onload = () => r(true); ci.onerror = () => r(false); ci.src = 'https://example.com/y.png'; cd.body.append(ci); setTimeout(() => r(false), 2500); }); ci.remove();
      out.violations = [...new Set(v)];
      return out;
    });
    ok(!blocked.inline && !blocked.evalRan && !blocked.fn && !blocked.handler, 'CSP: סקריפט בתוך הדף, eval, new Function ו-onerror שהוזרק כ-HTML — אינם רצים');
    ok(!blocked.img && !blocked.fetch && !blocked.canvasImg, 'CSP: תמונה ו-fetch לדומיין זר נחסמים — גם בתוך הקנבס (srcdoc יורש את המדיניות)');
    ok(await page.evaluate(() => { const c = Editor._state.canvas; return !!c.doc.querySelector('h1') && getComputedStyle(c.doc.querySelector('#t1')).color === 'rgb(7, 7, 7)'; }), 'תחת המדיניות: הקנבס מוצג, וה-CSS של הפרויקט חל בו');
    await page.evaluate(() => Editor._state.canvas.doc.querySelector('img[src$="y.png"]')?.remove());

    // ── 4. מקלדת בלבד, ו-`?` ──
    await openPage(page, 't/page.html');
    await page.evaluate(() => { document.activeElement && document.activeElement.blur(); });
    await page.keyboard.press('ArrowDown'); await sleep(60);
    ok((await st(page)).sel === 'body', 'מקלדת: בלי בחירה, חץ בוחר את body');
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowLeft'); await sleep(60);
    ok((await st(page)).sel === 'h2.sub', 'מקלדת: חץ למטה — הילד הראשון; חץ — האח הבא (' + (await st(page)).sel + ')');
    await page.keyboard.press('Shift+?'); await page.waitForSelector('#sheet[open] .ed-keys');
    const keys = await page.$$eval('.ed-keys kbd', k => k.map(x => x.textContent));
    ok(keys.length >= 15 && ['Delete', 'C', 'N', 'I', 'S', 'B', '?'].every(k => keys.includes(k)), '`?` מציג את רשימת הקיצורים (' + keys.length + ')');
    await page.click('#sheet [data-close]');
    await page.keyboard.press('2'); await sleep(80);
    ok(await page.evaluate(() => Editor._state.device === 'tablet' && Editor._state.canvas.viewport === 820), 'מקלדת: 2 — רוחב טאבלט');
    await page.keyboard.press('1');
    await page.keyboard.press('i'); await sleep(60);
    ok(await page.evaluate(() => document.querySelector('.ed').classList.contains('info-open') && document.activeElement === document.querySelector('.dt')), 'מקלדת: I — פאנל הפרטים, והמיקוד בו');
    await page.keyboard.press('Tab');
    ok(await page.evaluate(() => document.activeElement.classList.contains('dt-open')), 'Tab מגיע ל"פתח בקוד" שבפרטים (וממנו לכל הצהרה)');
    await page.evaluate(() => document.activeElement.blur());
    await page.keyboard.press('n'); await sleep(60);
    ok(await page.evaluate(() => document.activeElement === document.querySelector('.nv-list') && !!document.querySelector('.nv-list').getAttribute('aria-activedescendant')), 'מקלדת: N — המיקוד בעץ, על השורה הנבחרת');
    await page.evaluate(() => document.activeElement.blur());
    await page.keyboard.press('c');
    await page.waitForFunction(() => location.hash.startsWith('#/project') && Project._state.editor && Project._state.editor.getSelection() && !Project._state.editor.getSelection().isEmpty(), null, { timeout: 8000 }).then(() => ok(true, 'מקלדת: C — "פתח בקוד", והטווח של האלמנט מסומן'), () => ok(false, 'מקלדת: C — "פתח בקוד", והטווח של האלמנט מסומן'));
    const allBtns = await (async () => { await openPage(page, 't/page.html'); return page.$$eval('.ed-bar button, .ed-bar select', b => b.filter(x => !(x.getAttribute('aria-label') || x.title || x.textContent.trim())).length); })();
    ok(allBtns === 0, 'לכל כפתור בסרגל יש שם נגיש');

    ok(errors.length === 0, 'אין שגיאות JavaScript בדף (1280px)' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
    await ctx.close();
    // ── 5. טלפון (390px): הקנבס על כל המסך; Navigator ופרטים — גיליונות מלמטה; לשוניות במקום פיצול ──
    const ctxM = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } }), pm = await ctxM.newPage();
    const errM = []; pm.on('pageerror', e => errM.push(e.message));
    await pm.goto(base + '/#/project'); await pm.waitForSelector('.pj-tree .pj-row', { state: 'attached' });
    await openPage(pm, 't/page.html');
    const layM = await pm.evaluate(() => { const cv = document.querySelector('.ed-canvas').getBoundingClientRect(); return { scroll: document.documentElement.scrollWidth <= innerWidth, cvW: cv.width, cvH: cv.height, navHidden: getComputedStyle(document.querySelector('.ed-nav')).display === 'none', infoHidden: getComputedStyle(document.querySelector('.ed-info')).display === 'none', taps: [...document.querySelectorAll('.ed-bar button, .ed-bar select')].map(b => b.getBoundingClientRect()).every(r => r.height >= 34 && r.width >= 34 && r.right <= innerWidth + 1) }; });
    ok(layM.scroll && layM.cvW > 350 && layM.cvH > 400 && layM.navHidden && layM.infoHidden && layM.taps, `390px: הקנבס על כל המסך (${Math.round(layM.cvW)}×${Math.round(layM.cvH)}), הפאנלים סגורים, וכל כפתורי הסרגל ניתנים להקשה`);
    await pm.tap('[data-panel="nav"]'); await sleep(150);
    const sheetM = await pm.evaluate(() => { const r = document.querySelector('.ed-nav').getBoundingClientRect(); return { fixed: getComputedStyle(document.querySelector('.ed-nav')).position === 'fixed', bottom: Math.abs(r.bottom - innerHeight) < 2, w: r.width, h: r.height, rows: document.querySelectorAll('.nv-row').length }; });
    ok(sheetM.fixed && sheetM.bottom && sheetM.w === 390 && sheetM.h > 300 && sheetM.rows > 5, `390px: Navigator — גיליון מלמטה (${sheetM.w}×${Math.round(sheetM.h)}), עם שורות`);
    await pm.tap('.nv-row:has-text("h2.sub") .nv-lab'); await sleep(150);
    ok((await st(pm)).sel === 'h2.sub' && (await st(pm)).box, '390px: הקשה על שורה בגיליון בוחרת בקנבס');
    await pm.tap('[data-panel="info"]'); await sleep(150);
    ok(await pm.evaluate(() => getComputedStyle(document.querySelector('.ed-nav')).display === 'none' && getComputedStyle(document.querySelector('.ed-info')).position === 'fixed' && document.querySelector('.dt').textContent.includes('<h2>')), '390px: פרטים — גיליון מלמטה, במקום ה-Navigator (אחד בכל רגע)');
    if (SHOTS) await pm.screenshot({ path: path.join(SHOTS, 'v1b-390-info.png') });
    await pm.tap('.ed-info .ed-side-head button'); await sleep(100);
    ok(await pm.evaluate(() => getComputedStyle(document.querySelector('.ed-info')).display === 'none'), '390px: ✕ סוגר את הגיליון');
    await pm.tap('[data-split]'); await pm.waitForSelector('.ed-tabs [role="tab"]'); await sleep(200);
    ok(await pm.evaluate(() => getComputedStyle(document.querySelector('.ed-tabs')).display !== 'none' && getComputedStyle(document.querySelector('.ed-code')).display === 'none' && getComputedStyle(document.querySelector('.ed-canvas')).display !== 'none'), '390px: מבט מפוצל = לשוניות; הקנבס מוצג');
    await pm.tap('.ed-tabs [data-tab="code"]'); await pm.waitForSelector('.ed-code .monaco-editor .view-lines'); await sleep(300);
    ok(await pm.evaluate(() => { const sp = Editor._state.code, r = sp && sp.selection(); return getComputedStyle(document.querySelector('.ed-canvas')).display === 'none' && document.querySelector('.ed-code').getBoundingClientRect().width > 350 && !!r && Editor._state.doc.text().slice(r.from, r.to) === '<h2 class="sub">משנה</h2>'; }), '390px: לשונית "קוד" — הקוד על כל הרוחב, והאלמנט הנבחר מסומן בו');
    await pm.tap('.ed-tabs [data-tab="canvas"]'); await sleep(250);
    ok(await pm.evaluate(() => getComputedStyle(document.querySelector('.ed-canvas')).display !== 'none' && !Editor._state.canvas.boxSel.hidden), '390px: חזרה ללשונית "קנבס" — הבחירה במקומה');
    await pm.tap('[data-split]'); await sleep(100);
    // 🐞 במגע, והדיווח כגיליון
    await pm.tap('[data-bug]'); const ptM = await canvasPt(pm, '#t1'); await pm.touchscreen.tap(ptM.x, ptM.y);
    await pm.waitForSelector('#sheet[open] .ed-shot img', { timeout: 15000 });
    ok((await pm.textContent('.ed-report-info')).includes('רוחב הקנבס: 390px (טלפון)'), '390px: 🐞 במגע פותח דיווח, עם רוחב הקנבס 390px');
    await pm.tap('#sheet [data-close]');
    ok(errM.length === 0, 'אין שגיאות JavaScript בדף (390px)' + (errM.length ? ': ' + errM.slice(0, 3).join(' | ') : ''));
    await ctxM.close();
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e && e.stack || e) + (global.__errors && global.__errors.length ? '\n   שגיאות בדף: ' + global.__errors.slice(0, 3).join(' | ') : '')); }
  finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${ran - failed}/${ran} עברו` + (failed ? ` — ${failed} נכשלו` : ''));
  process.exit(failed ? 1 : 0);
})();
