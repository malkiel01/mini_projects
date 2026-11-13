/**
 * פרוסה V1a — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/v1a.cjs
 *
 * העורך החזותי על קבצי הפרויקט האמיתיים: בחירה, שביל ניווט, "פתח בקוד", שינוי בקוד ← קנבס, מחיקה ← קוד, ביטול בייט
 * בבייט, היסטוריה משותפת, אותה שמירה ואותו rev, rev ישן, נעילה, קובץ שאינו UTF-8, כמה <link>, רשימה ארוכה, קוד שבור —
 * ובדיקת ההתקפה על קובץ מהפרויקט (סקריפט, onclick, javascript:, ושם מחלקה שהוא HTML). 1280px ו-390px.
 * רץ מול tests/router.php ומסד זמני — data/ לא נפתחת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.KOL_SHOTS || '';
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 600) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
/** V4: מחיקה של אלמנט שקוד נשען עליו מציגה אזהרה ("בצע בכל זאת" / "בטל") — לא חסימה. כאן — ממשיכים. */
const goAnyway = async page => { await sleep(60); if (await page.isVisible('#sheet[open] [data-choice="go"]').catch(() => false)) { await page.click('#sheet [data-choice="go"]'); await sleep(60); } };

const ATTACK = `<!doctype html>
<html lang="he" dir="rtl"><head><meta charset="utf-8"><title>התקפה</title>
<link rel="stylesheet" href="a.css"><link rel="stylesheet" href="sub/b.css">
<style>.inline-rule { letter-spacing: 3px; }</style>
<script>parent.postMessage('pwned:script', '*'); document.title = 'PWNED'; fetch('/api.php?action=pf_tree&pwned=1');</script>
</head><body>
<h1 class="hero-title inline-rule" id="t1">כותרת</h1>
<h2 class="sub">משנה</h2>
<div class='"><img src=x onerror=parent.postMessage("pwned:class","*")>' id="evil-class">מחלקה זדונית</div>
<p id="evil-click" onclick="parent.postMessage('pwned:onclick','*')">לחיצה</p>
<a id="evil-href" href="javascript:parent.postMessage('pwned:href','*')">קישור</a>
<img id="evil-img" src="nope-pwned.png" onerror="parent.postMessage('pwned:onerror','*')">
<img id="pic" src="pic.svg" alt="">
<p id="zero"></p>
<table id="tb"><tr><td>תא</td></tr></table>
<section class="wrap"><div class="in"><span>א</span><span>ב</span><span>ג</span></div></section>
<ul id="long">${Array.from({ length: 75 }, (_, i) => `<li class="it">פריט ${i + 1}</li>`).join('')}</ul>
</body></html>
`;

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v1a-'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const pwnedReq = [];
    const mk = async (width, height, user = OWNER) => {
      const ctx = await browser.newContext({ viewport: { width, height }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
      ctx.on('request', r => { if (r.url().includes('pwned=1')) pwnedReq.push(r.url()); });
      return ctx;
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const st = page => page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc, n = c && c.selected; return { sel: n ? c.label(n) : null, tag: n ? n.tag : null, src: c ? c.src : null, box: c ? !c.boxSel.hidden : false, crumbs: [...document.querySelectorAll('.ed-crumb')].map(e => e.textContent), mode: c && c.lastMode, inequiv: c ? c.inequiv.length : -1, mapErrors: c ? c.mapErrors.length : -1, title: c ? c.doc.title : '' }; });
    /** לחיצה אמיתית (עכבר) על אלמנט בקנבס, לפי בורר. */
    const clickIn = async (page, sel, opt = {}) => {
      const p = await page.evaluate(([s, o]) => { const c = Editor._state.canvas, d = c.doc.querySelector(s); d.scrollIntoView({ block: 'center' }); const r = d.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + (o.center ? r.width / 2 : Math.min(r.width / 2, 12))) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; }, [sel, { center: !!opt.center }]);
      if (opt.alt) await page.keyboard.down('Alt');
      if (opt.touch) await page.touchscreen.tap(p.x, p.y); else await page.mouse.click(p.x, p.y);
      if (opt.alt) await page.keyboard.up('Alt');
      await sleep(60);
    };
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, p, { timeout: 30000 }); };
    const hist = id => JSON.parse(execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1] . "/kol.sqlite"); echo json_encode($p->query("SELECT rev, note, sub FROM pf_history WHERE file_id = " . (int)$argv[2] . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));', data, String(id)]).toString());

    const ctx = await mk(1280, 800), page = await ctx.newPage();
    const errors = [], msgs = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => { window.__msgs = []; window.addEventListener('message', e => { if (typeof e.data === 'string' && e.data.startsWith('pwned')) window.__msgs.push(e.data); }); });
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');

    // ── 1. התרחיש של הפרוסה (§3), על from-current/lexicon.html ──
    await page.goto(base + '/editor.php#/editor');
    await page.waitForSelector('.ed-page option', { state: 'attached' });
    ok(await page.$$eval('#tabs a', a => a.some(x => x.textContent.includes('עורך'))) , 'לשונית "🎨 עורך" לצד המסכים הקיימים');
    const pages = await page.$$eval('.ed-page option', o => o.map(x => x.textContent));
    ok(pages.includes('index.html') && pages.includes('from-current/lexicon.html') && pages.every(p => /\.html?$/.test(p)), 'בורר "עמוד": כל קובצי ה-HTML של הפרויקט (' + pages.length + ')');
    await openPage(page, 'from-current/lexicon.html');
    const lexId = await page.evaluate(() => Editor._state.file.id), orig = (await call(page, 'pf_read', null, `&id=${lexId}`)).content, rev0 = await page.evaluate(() => Editor._state.doc.rev);
    ok((await st(page)).src === orig && orig.includes('{{#'), 'הקנבס טעון מהקובץ האמיתי (עם תחביר Mustache), זהה למאגר');
    ok(await page.evaluate(() => Editor._state.canvas.frame.getAttribute('sandbox') === 'allow-same-origin'), 'הקנבס: sandbox בלי allow-scripts');
    const target = await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc; const n = K.all(c.m).find(x => /^h[1-6]$/.test(x.tag) && c.rectOf(x) && !c.rectOf(x).zero && !x.kids.length) || K.all(c.m).find(x => !x.implied && !x.kids.length && c.rectOf(x) && !c.rectOf(x).zero && x.parent && x.parent.tag !== 'head'); window.__t = n; return { tag: n.tag, path: K.pathOf(n), from: n.from, to: K.endOf(c.m, n), text: c.m.src.slice(n.from, K.endOf(c.m, n)) }; });
    const tsel = await page.evaluate(() => { const d = Editor._state.canvas.m2d.get(window.__t); d.setAttribute('data-test-target', ''); return true; });
    await clickIn(page, '[data-test-target]');
    await page.evaluate(() => Editor._state.canvas.m2d.get(window.__t).removeAttribute('data-test-target'));
    let s = await st(page);
    ok(s.tag === target.tag && s.box, 'לחיצה בקנבס בוחרת את האלמנט (' + s.sel + '), והמסגרת מצוירת מעל הקנבס');
    ok(s.crumbs.length >= 3 && s.crumbs[0].startsWith('html') && s.crumbs[s.crumbs.length - 1] === s.sel, 'שביל הניווט: ' + s.crumbs.join(' › '));
    ok(await page.evaluate(() => !Editor._state.canvas.doc.querySelector('[class*="kol"],[data-kol-sel]') && Editor._state.canvas.layer.ownerDocument === document), 'ה-DOM של הקנבס אינו מקבל סימון — המסגרות במסמך של המעטפת');
    // "פתח בקוד"
    await page.click('.ed-bar button:has-text("</>")');
    await page.waitForSelector('.pj-ed .monaco-editor .view-line');
    await page.waitForFunction(() => { const e = Project._state.editor, m = e && e.getModel(); return m && !e.getSelection().isEmpty(); });
    const selText = await page.evaluate(() => { const e = Project._state.editor; return e.getModel().getValueInRange(e.getSelection()); });
    ok(selText === target.text, '"פתח בקוד": עורך הקוד נפתח על הקובץ, וטווח האלמנט מסומן');
    // שינוי טקסט בקוד ← נראה בקנבס
    const marker = 'שונה-בקוד-' + Date.now();
    await page.evaluate(([t, m]) => { const e = Project._state.editor, mod = e.getModel(), a = mod.getPositionAt(t.to), s0 = mod.getValue(), close = s0.lastIndexOf('</', t.to), p = mod.getPositionAt(close); e.executeEdits('test', [{ range: new monaco.Range(p.lineNumber, p.column, p.lineNumber, p.column), text: m }]); e.pushUndoStop(); }, [target, marker]);
    await page.goto(base + '/editor.php#/editor/' + encodeURIComponent('from-current/lexicon.html'));   // מ-V2 תבנית ישנה אינה נפתחת מהזיכרון — מבקשים אותה במפורש
    await page.waitForFunction(() => Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy);
    ok(await page.evaluate(m => Editor._state.canvas.doc.body.textContent.includes(m), marker), 'שינוי טקסט בעורך הקוד נראה בקנבס');
    // מחיקה בקנבס ← נראית בקוד
    const victim = await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc, inB = x => { for (; x; x = x.parent) if (x.broken) return true; return false; }; const n = K.all(c.m).find(x => !x.implied && x.et && x.kids.length >= 2 && x.kids.length < 30 && c.rectOf(x) && !c.rectOf(x).zero && x.parent && x.parent.tag !== 'html' && x.tag !== 'body' && !x.hasBroken && !inB(x)); c.select(n); return { label: c.label(n), inside: K.all({ root: n }).length - 1, text: c.m.src.slice(n.from, K.endOf(c.m, n)), before: c.src }; });
    await page.keyboard.press('Delete'); await goAnyway(page);
    await sleep(100);
    s = await st(page);
    const noteText = await page.textContent('.ed-note');
    ok(!s.src.includes(victim.text) && s.src.length < victim.before.length, 'Delete: האלמנט הוסר מהקובץ ומהקנבס (' + victim.label + ')');
    ok(noteText.includes('נמחק') && noteText.includes('עם ' + victim.inside + ' אלמנטים בתוכו') && noteText.includes('בטל'), 'ההודעה: "' + noteText.replace('✕', '').trim() + '"');
    ok(await page.evaluate(() => Editor._state.doc.text() === Editor._state.canvas.src && Project._state.docs.get(Editor._state.file.id).model.getValue() === Editor._state.canvas.src), 'המחיקה נראית בעורך הקוד — אותו מסמך, בלי טעינה מחדש');
    ok(s.inequiv === 0 && s.mapErrors === 0, 'אחרי המחיקה המודל שקול לניתוח מלא והמיפוי שלם (מצב העדכון בקובץ הזה: ' + s.mode + ')');
    ok(await page.evaluate(() => { const c = Editor._state.canvas; return window.KolCanvas.sameDom(c.doc.documentElement, window.KolCanvas.browserParse(c.src).documentElement, true).filter(d => !/base|style/.test(d)).length === 0; }), 'ה-DOM של הקנבס אחרי המחיקה זהה לניתוח טרי של הדפדפן');
    // אותה שמירה, אותו rev, אותה היסטוריה
    await page.waitForFunction(r => !Editor._state.doc.dirty && Editor._state.doc.rev > r, rev0, { timeout: 10000 });
    const saved = await call(page, 'pf_read', null, `&id=${lexId}`);
    ok(saved.content === s.src && saved.file.rev === await page.evaluate(() => Editor._state.doc.rev), 'נשמר לשרת דרך אותה שמירה (pf_write), עם rev ' + saved.file.rev);
    const h1 = hist(lexId);
    ok(h1.length >= 2 && h1[h1.length - 1].rev === saved.file.rev, 'המחיקה מופיעה בהיסטוריית הקובץ (pf_history: ' + h1.map(x => x.rev + ':' + x.note).join(', ') + ')');
    ok(await page.evaluate(() => Editor._state.doc.lastTx.label.startsWith('מחיקת ')), 'העסקה נושאת שם: "' + await page.evaluate(() => Editor._state.doc.lastTx.label) + '"');
    // Ctrl+Z ×2 ← זהה למקור, בייט בבייט
    await page.keyboard.press('Control+z'); await sleep(80);
    ok((await st(page)).src === victim.before, 'Ctrl+Z: המחיקה בוטלה — הקובץ זהה למה שהיה');
    await page.keyboard.press('Control+z'); await sleep(80);
    s = await st(page);
    ok(s.src === orig, 'Ctrl+Z ×2: הקובץ זהה למקור בייט בבייט (' + Buffer.byteLength(orig) + ' בייט)');
    await page.keyboard.press('Control+y'); await sleep(80); await page.keyboard.press('Control+y'); await sleep(80);
    ok((await st(page)).src.length === victim.before.length - victim.text.length - (victim.before.length - victim.text.length - (await st(page)).src.length), 'Ctrl+Y ×2 מחזיר את שתי הפעולות');
    await page.keyboard.press('Control+z'); await sleep(60); await page.keyboard.press('Control+z'); await sleep(60);
    // היסטוריה משותפת: חזותי, הקלדה בקוד, חזותי — שלושה ביטולים בסדר
    const snap = [];
    const delFirst = async () => { await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc, inB = x => { for (; x; x = x.parent) if (x.broken) return true; return false; }; c.select(K.all(c.m).find(x => !x.implied && x.et && !x.kids.length && c.rectOf(x) && x.parent && x.parent.et && x.parent.tag !== 'head' && !inB(x))); }); await page.keyboard.press('Delete'); await goAnyway(page); await sleep(80); };
    snap.push((await st(page)).src); await delFirst();
    snap.push((await st(page)).src);
    await page.evaluate(() => { const m = Project._state.docs.get(Editor._state.file.id).model; m.pushStackElement(); m.pushEditOperations(null, [{ range: new monaco.Range(1, 1, 1, 1), text: '<!-- הוקלד בקוד -->' }], () => null); m.pushStackElement(); });
    await page.waitForFunction(() => Editor._state.canvas.src.startsWith('<!-- הוקלד בקוד -->'), null, { timeout: 300 }).then(() => ok(true, 'הקלדה בקוד מעדכנת את הקנבס בתוך 300ms'), () => ok(false, 'הקלדה בקוד מעדכנת את הקנבס בתוך 300ms'));
    snap.push((await st(page)).src); await delFirst();
    const after3 = (await st(page)).src; let order = after3 !== snap[2];
    for (let i = 2; i >= 0; i--) { await page.keyboard.press('Control+z'); await sleep(80); order = order && (await st(page)).src === snap[i]; }
    ok(order && snap[0] === orig, 'היסטוריה משותפת: פעולה חזותית, הקלדה בקוד, פעולה חזותית — שלושה ביטולים מחזירים את שלושתן, בסדר');
    await page.waitForFunction(() => !Editor._state.doc.dirty, null, { timeout: 10000 });
    ok((await call(page, 'pf_read', null, `&id=${lexId}`)).content === orig, 'אחרי הביטולים גם הקובץ שבשרת זהה למקור');
    // האלמנט הנבחר נשאר נבחר אחרי הקלדה בקוד
    await clickIn(page, 'body *:not(script):not(style)');
    const keepSel = (await st(page)).sel;
    await page.evaluate(() => { const m = Project._state.docs.get(Editor._state.file.id).model; m.pushEditOperations(null, [{ range: new monaco.Range(1, 1, 1, 1), text: '<!-- x -->' }], () => null); });
    await sleep(250);
    ok(keepSel && (await st(page)).sel === keepSel && (await st(page)).box, 'אחרי הקלדה בקוד האלמנט הנבחר נשאר נבחר (' + keepSel + ')');
    await page.evaluate(() => Project._state.docs.get(Editor._state.file.id).model.undo()); await sleep(200);
    // כל צומת במודל ממופה לאלמנט בקנבס
    const all = await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc, ns = K.all(c.m); let mapped = 0, shown = 0, hidden = 0; for (const n of ns) { const d = c.m2d.get(n); if (d && d.localName === n.tag) mapped++; if (c.rectOf(n)) shown++; else hidden++; c.selected = n; } c.select(null); return { total: ns.length, mapped, shown, hidden }; });
    ok(all.mapped === all.total, `כל צומת במודל ממופה לאלמנט בקנבס ונבחר בקוד (${all.mapped}/${all.total}; ${all.shown} מרונדרים, ${all.hidden} מוסתרים — אלה ייבחרו מה-Navigator, V1b)`);
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'v1a-1280-lexicon.png') });

    // ── 2. בדיקת ההתקפה, על קובץ מהפרויקט ──
    await call(page, 'pf_create', { path: 'atk/attack.html', content: ATTACK });
    await call(page, 'pf_create', { path: 'atk/a.css', content: '.hero-title { color: rgb(1, 2, 3); }\n.sub { background: url("pic.svg"); }\n' });
    await call(page, 'pf_create', { path: 'atk/sub/b.css', content: '.sub { color: rgb(4, 5, 6); }\n' });
    await call(page, 'pf_create', { path: 'atk/pic.svg', content: '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="30"><rect width="40" height="30" fill="red"/></svg>' });
    await openPage(page, 'atk/attack.html');
    for (const q of ['#evil-click', '#evil-href', '#evil-img', '#evil-class', '#t1']) await clickIn(page, q);
    await sleep(400);
    s = await st(page);
    const pw = await page.evaluate(() => window.__msgs.slice());
    ok(pw.length === 0 && pwnedReq.length === 0 && s.title === 'התקפה' && new URL(page.url()).hash.startsWith('#/editor'), 'התקפה: <script>, onclick, onerror, javascript: — שום קוד לא רץ (0 הודעות, 0 בקשות, הכותרת לא שונתה, אין ניווט)');
    await clickIn(page, '#evil-class');
    s = await st(page);
    ok(s.sel === 'div#evil-class' && await page.evaluate(() => [...document.querySelectorAll('.ed img, .spike-layer img, .ed-crumbs img')].every(i => i.classList.contains('kit-prev') && /^assets\/kit\//.test(i.getAttribute('src') || '') && ![...i.attributes].some(a => /^on/i.test(a.name))) && [...document.querySelectorAll('.ed-crumb')].every(b => b.children.length === 0)), 'שם מחלקה שהוא HTML מוצג כטקסט בלבד (שביל, תווית, הודעה) — התמונות היחידות במעטפת הן תצוגות המקדימות של ערכת הרכיבים (V16, T2)');
    await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.d2m.get(c.doc.querySelector('#evil-class'))); });
    await page.keyboard.press('Delete'); await goAnyway(page); await sleep(100);
    ok(await page.evaluate(() => !document.querySelector('.ed-note img') && document.querySelector('.ed-note').textContent.includes('נמחק')) && (await page.evaluate(() => window.__msgs.length)) === 0, 'גם בהודעת המחיקה — טקסט בלבד');
    ok((await st(page)).mode === 'inc' && (await st(page)).inequiv === 0, 'בקובץ תקין המחיקה היא עדכון הדרגתי, שקול לניתוח מלא');
    await page.keyboard.press('Control+z'); await sleep(100);
    // הוספת סקריפט דרך תיקון (הקלדה בקוד) — גם הוא אינו רץ
    await page.evaluate(() => { const m = Project._state.docs.get(Editor._state.file.id).model, at = m.getValue().indexOf('<h2'), p = m.getPositionAt(at); m.pushEditOperations(null, [{ range: new monaco.Range(p.lineNumber, p.column, p.lineNumber, p.column), text: '<script>parent.postMessage("pwned:patched","*")</scr' + 'ipt><img src="nope-pwned2.png" onerror="parent.postMessage(\'pwned:patched-img\',\'*\')">' }], () => null); });
    await sleep(500);
    ok(await page.evaluate(() => Editor._state.canvas.doc.querySelectorAll('script').length === 2 && window.__msgs.length === 0), 'סקריפט שנוסף בתיקון נקודתי (לא בטעינה) — גם הוא אינו רץ');
    await page.evaluate(() => Project._state.docs.get(Editor._state.file.id).model.undo()); await sleep(250);
    // כמה <link>, <style> בתוך הדף, ותמונות הפרויקט
    const css = await page.evaluate(async () => { const c = Editor._state.canvas, w = c.frame.contentWindow, g = q => w.getComputedStyle(c.doc.querySelector(q)); const img = c.doc.querySelector('#pic'); if (!img.complete) await new Promise(r => { img.onload = img.onerror = r; setTimeout(r, 3000); });
      return { a: g('#t1').color, b: g('.sub').color, inline: g('#t1').letterSpacing, bg: g('.sub').backgroundImage, img: img.naturalWidth, linked: c.linked, net: [...c.doc.querySelectorAll('link[rel=stylesheet]')].every(l => l.disabled || l.hasAttribute('disabled')) }; });
    ok(css.a === 'rgb(1, 2, 3)' && css.b === 'rgb(4, 5, 6)' && css.inline === '3px', 'שני <link> ו-<style> בתוך הדף — כולם חלים בקנבס');
    ok(css.linked.length === 2 && css.linked.every(l => l.inProject) && css.net, 'קובצי ה-CSS נטענים מהפרויקט (מהמסמך שבעורך), לא מהרשת');
    ok(css.img === 40 && css.bg.includes('/preview/'), 'תמונת הפרויקט מוצגת (גם url() שבתוך ה-CSS) — דרך כתובת התצוגה המבודדת');
    // עריכת קובץ ה-CSS בקוד ← הקנבס
    await page.evaluate(() => { const f = Project.docs.byPath('atk/a.css'), m = Project._state.docs.get(f.id).model; m.setValue('.hero-title { color: rgb(9, 9, 9); }'); });
    await sleep(100);
    ok(await page.evaluate(() => { const c = Editor._state.canvas; return c.frame.contentWindow.getComputedStyle(c.doc.querySelector('#t1')).color; }) === 'rgb(9, 9, 9)', 'שינוי בקובץ ה-CSS בעורך הקוד מגיע לקנבס');

    // ── 3. בחירה ──
    await clickIn(page, '.in span');
    ok((await st(page)).sel === 'span', 'לחיצה בוחרת את האלמנט העמוק ביותר שמתחת לסמן');
    await clickIn(page, '.in span');
    ok((await st(page)).sel === 'div.in', 'לחיצה חוזרת — ההורה');
    { const t = await page.textContent('.ed-note').catch(() => ''), vis = await page.isVisible('.ed-note');   // T2 (E2E ⚪5): עם רמז, ו"חזרה ל-"
      ok(vis && /נבחר ההורה: div\.in/.test(t) && /חזרה ל-span/.test(t), 'לחיצה חוזרת — ההודעה אומרת שנבחר ההורה, ומציעה "↩ חזרה ל-span"', t);
      await page.click('.ed-note button:has-text("חזרה ל-")'); await sleep(60);
      ok((await st(page)).sel === 'span', '"↩ חזרה ל-span" — הבחירה חוזרת');
      await clickIn(page, '.in span'); }
    await page.keyboard.press('Escape'); await sleep(40);
    ok((await st(page)).sel === 'section.wrap', 'Esc — ההורה');
    await page.keyboard.press('ArrowDown'); await sleep(40); await page.keyboard.press('ArrowDown'); await sleep(40);
    const a1 = (await st(page)).crumbs.length;
    await page.keyboard.press('ArrowLeft'); await sleep(40);
    const sib = await page.evaluate(() => { const c = Editor._state.canvas; return c.selected.parent.kids.indexOf(c.selected); });
    ok(a1 === 5 && sib === 1, 'חיצים: ילד, ואח (בעמוד RTL שמאל = הבא)');
    await clickIn(page, '.in span', { alt: true }); const alt1 = (await st(page)).sel; await clickIn(page, '.in span', { alt: true }); const alt2 = (await st(page)).sel;
    ok(alt1 !== alt2 && [alt1, alt2].every(Boolean), 'Alt + לחיצה עובר בין אלמנטים שמונחים זה על זה (' + alt1 + ' ← ' + alt2 + ')');
    await page.hover('.ed-canvas', { position: { x: 300, y: 60 } }); await sleep(80);
    ok(await page.evaluate(() => { const c = Editor._state.canvas; return !!c.hovered && !c.boxHov.hidden && c.boxHov.firstChild.textContent === c.label(c.hovered); }), 'ריחוף — מסגרת ותווית', await page.evaluate(() => { const c = Editor._state.canvas; return { hv: c.hovered && c.label(c.hovered), sel: c.selected && c.label(c.selected), hid: c.boxHov.hidden, ev: c.events.slice(-3), top: document.elementFromPoint(document.querySelector('.ed-canvas').getBoundingClientRect().left + 300, document.querySelector('.ed-canvas').getBoundingClientRect().top + 60)?.outerHTML.slice(0, 80) }; }));
    await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.d2m.get(c.doc.querySelector('#tb tbody'))); });
    s = await st(page);
    ok(s.sel === 'tbody · לא במקור' && (await page.textContent('.ed-where')).includes('לא במקור'), 'אלמנט שהדפדפן הוסיף (<tbody>) נבחר, מסומן "לא במקור"');
    await page.click('.ed-bar button:has-text("</>")');
    await page.waitForFunction(() => { const e = Project._state.editor; return e && e.getModel() && !e.getSelection().isEmpty() && e.getModel().getValueInRange(e.getSelection()).startsWith('<table'); }, null, { timeout: 5000 }).then(() => ok(true, '"פתח בקוד" מ-<tbody> מוביל להורה הכתוב שלו (<table>)'), () => ok(false, '"פתח בקוד" מ-<tbody> מוביל להורה הכתוב שלו'));
    await page.goto(base + '/editor.php#/editor'); await page.waitForFunction(() => Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy);
    const zero = await page.evaluate(() => { const c = Editor._state.canvas; c.draw(); const b = [...document.querySelectorAll('.ed-mark-zero')].find(x => x.title.startsWith('p#zero')); if (!b) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
    if (zero) await page.mouse.click(zero.x, zero.y);
    ok(zero && (await st(page)).sel === 'p#zero', 'אלמנט בגודל אפס מקבל מצביע שאפשר ללחוץ עליו');
    // רשימה ארוכה
    const cap = await page.evaluate(() => { const c = Editor._state.canvas, lis = [...c.doc.querySelectorAll('#long li')]; return { shown: lis.filter(l => l.getClientRects().length).length, total: lis.length, strip: document.querySelector('button.ed-strip').textContent, hidden: document.querySelector('button.ed-strip').hidden }; });
    ok(cap.shown === 60 && cap.total === 75 && !cap.hidden && cap.strip.includes('הצג הכול'), 'רשימה ארוכה: 75 אחים זהים — מוצגים 60, ו"הצג הכול"');
    await page.click('button.ed-strip');
    ok(await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('#long li')].filter(l => l.getClientRects().length).length) === 75, '"הצג הכול" מציג את כולם');
    // רוחב הקנבס
    const vp = [];
    for (const d of ['tablet', 'mobile', 'desktop']) { await page.click(`.ed-dev [data-dev="${d}"]`); await sleep(60); vp.push(await page.evaluate(() => { const c = Editor._state.canvas; return [c.frame.contentWindow.innerWidth, c.scale]; })); }
    ok(vp[0][0] === 820 && vp[1][0] === 390 && vp[2][0] > 900, 'רוחב הקנבס: טאבלט 820, טלפון 390, מחשב ' + vp[2][0]);
    await page.click('.ed-dev [data-dev="mobile"]'); await clickIn(page, '#t1');
    const boxOk = await page.evaluate(() => { const c = Editor._state.canvas, b = c.boxSel.getBoundingClientRect(), r = c.doc.querySelector('#t1').getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return Math.abs(b.left - (f.left + r.left * c.scale)) < 2 && Math.abs(b.top - (f.top + r.top * c.scale)) < 2; });
    ok((await st(page)).sel === 'h1#t1' && boxOk, 'ברוחב טלפון: הלחיצה בוחרת, והמסגרת יושבת על האלמנט');
    await page.click('.ed-dev [data-dev="desktop"]');
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'v1a-1280-attack.png') });

    // ── 4. קוד שבור ──
    await call(page, 'pf_create', { path: 'atk/broken.html', content: '<!doctype html><html><body>\n<h1 id="ok1">לפני</h1>\n<div id="half"><p>בתוך <span>שבור</p></div>\n<div id="bad" <p>תגית חצויה</p></div>\n<footer id="ok2">אחרי</footer>\n</body></html>\n' });
    await openPage(page, 'atk/broken.html');
    const br = await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc; c.draw(); return { marks: c.m.marks, strip: !document.querySelector('p.ed-strip.warn').hidden, boxes: document.querySelectorAll('.ed-mark-broken').length, bad: K.all(c.m).filter(n => n.broken).map(n => c.label(n)) }; });
    ok(br.marks > 0 && br.strip && br.boxes > 0, 'קובץ שבור נטען; האזור השבור מסומן בקנבס (' + br.bad.join(', ') + ')');
    await clickIn(page, '#ok2', { center: true });   /* במרכז: בפינה יושב המצביע של ה-<p> הריק שמעליו */ const beforeB = (await st(page)).src;
    ok((await st(page)).sel === 'footer#ok2', 'מה שמחוץ לאזור השבור נבחר');
    await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc; c.select(K.all(c.m).find(n => n.broken)); });
    await page.keyboard.press('Delete'); await goAnyway(page); await sleep(100);
    const nb = await page.evaluate(() => ({ text: document.querySelector('.ed-note').textContent, err: document.querySelector('.ed-note').classList.contains('err'), btn: !!document.querySelector('.ed-note .btn') }));
    ok((await st(page)).src === beforeB && nb.err && nb.text.includes('שבור') && nb.text.includes('פתח בקוד'), 'מחיקה באזור שבור נדחית, בהודעה, עם "פתח בקוד" — והקובץ לא השתנה');
    await clickIn(page, '#ok2', { center: true });   /* במרכז: בפינה יושב המצביע של ה-<p> הריק שמעליו */ await page.keyboard.press('Delete'); await goAnyway(page); await sleep(120);
    ok(!(await st(page)).src.includes('id="ok2"') && (await st(page)).inequiv === 0, 'מחיקה מחוץ לאזור השבור עובדת (ניתוח מלא: ' + (await st(page)).mode + ')');
    await page.keyboard.press('Control+z'); await sleep(120);
    ok((await st(page)).src === beforeB, 'והביטול מחזיר קובץ זהה');

    // ── 5. קובץ שאינו UTF-8 — קריאה בלבד ──
    await call(page, 'pf_create', { path: 'atk/legacy.html', content_b64: Buffer.from([0x3c, 0x68, 0x31, 0x3e, 0xf9, 0xec, 0xe5, 0xed, 0x3c, 0x2f, 0x68, 0x31, 0x3e]).toString('base64') }).catch(e => { console.log('   (pf_create b64: ' + e.message + ')'); });
    const hasLegacy = await page.evaluate(async () => { await Project.docs.files(); const d = await api('pf_tree'); return d.files.some(f => f.path === 'atk/legacy.html'); });
    if (hasLegacy) {
      await openPage(page, 'atk/legacy.html');
      await clickIn(page, 'h1'); const b0 = (await st(page)).src; await page.keyboard.press('Delete'); await goAnyway(page); await sleep(100);
      ok((await page.textContent('.ed-status')).includes('לקריאה בלבד') && (await st(page)).sel === 'h1' && (await st(page)).src === b0 && await page.evaluate(() => Editor._state.canvas.doc.body.textContent.includes('שלום')), 'קובץ שאינו UTF-8: נפתח לקריאה בלבד, עם הודעה; נבחר — ואינו נכתב');
    } else ok(false, 'קובץ שאינו UTF-8: לא ניתן היה ליצור קובץ בדיקה');

    // ── 6. rev ישן: אין דריסה ──
    await openPage(page, 'atk/attack.html');
    const aid = await page.evaluate(() => Editor._state.file.id), cur = await call(page, 'pf_read', null, `&id=${aid}`);
    const other = cur.content.replace('משנה', 'שונה ממקום אחר');
    await call(page, 'pf_write', { id: aid, rev: cur.file.rev, content: other });
    await clickIn(page, '#t1'); await page.keyboard.press('Delete'); await goAnyway(page);
    await page.waitForSelector('#sheet[open]', { timeout: 10000 }).catch(() => {});
    const conflict = await page.evaluate(() => ({ open: document.querySelector('#sheet').open, title: document.querySelector('#sheet-title').textContent, conflict: Editor._state.doc.conflict }));
    ok(conflict.open && conflict.conflict && (await call(page, 'pf_read', null, `&id=${aid}`)).content === other, 'rev ישן: אין דריסה — הודעה ("' + conflict.title + '"), והקובץ שבשרת נשאר של מי ששמר');
    const took = await page.evaluate(() => { const b = [...document.querySelectorAll('#sheet button')].find(x => /שלהם|בשרת|טען|זנח|השרת/.test(x.textContent)); if (b) b.click(); return b ? b.textContent : [...document.querySelectorAll('#sheet button')].map(x => x.textContent).join(' | '); });
    await sleep(400);
    ok(await page.evaluate(o => Editor._state.canvas.src === o && Editor._state.canvas.doc.body.textContent.includes('שונה ממקום אחר'), other), 'אחרי בחירת הגרסה שבשרת ("' + took + '") המודל והקנבס נטענים מחדש ממנה');

    // ── 7. נעילת העריכה הקיימת ──
    const ctx2 = await mk(1000, 700, { sub: 'u-two', name: 'Second Editor', email: 'two@example.test', email_verified: true, platform_admin: true }), p2 = await ctx2.newPage();
    await p2.goto(base + '/#/project'); await p2.waitForSelector('.pj-tree .pj-row');
    const lock = await p2.evaluate(() => api('lock', { op: 'take' }).then(r => r.lock, e => ({ error: e.message })));
    await page.evaluate(() => { document.querySelector('#sheet').close(); });
    await clickIn(page, '#evil-click'); await page.keyboard.press('Delete'); await goAnyway(page);
    await page.waitForFunction(() => Editor._state.readOnly, null, { timeout: 10000 }).catch(() => {});
    const ro = await page.evaluate(() => ({ ro: Editor._state.readOnly, status: document.querySelector('.ed-status').textContent }));
    const srv = (await call(page, 'pf_read', null, `&id=${aid}`)).content;
    ok(lock && lock.mine && !!ro.ro && srv.includes('id="evil-click"'), 'נעילה אצל עורך אחר: הכתיבה נדחית בשרת (423), והעורך עובר לקריאה בלבד — "' + ro.ro + '"');
    await clickIn(page, '#t1').catch(() => {}); await clickIn(page, '.in span');
    const srcRo = (await st(page)).src; await page.keyboard.press('Delete'); await goAnyway(page); await sleep(100);
    ok((await st(page)).sel === 'span' && (await st(page)).src === srcRo, 'מי שאינו רשאי לערוך — רואה ובוחר, ואינו כותב');
    await p2.evaluate(() => api('lock', { op: 'release' })); await ctx2.close();
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף (1280px)' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
    await ctx.close();

    // ── 8. 390px ──
    const ctxM = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } }), pm = await ctxM.newPage();
    const errM = []; pm.on('pageerror', e => errM.push(e.message));
    await pm.goto(base + '/#/project'); await pm.waitForSelector('.pj-tree .pj-row', { state: 'attached' });
    await openPage(pm, 'from-current/lexicon.html');
    const lay = await pm.evaluate(() => { const bar = document.querySelector('.ed-bar').getBoundingClientRect(), cv = document.querySelector('.ed-canvas').getBoundingClientRect(); return { scroll: document.documentElement.scrollWidth, w: innerWidth, bar: bar.right <= innerWidth + 1 && bar.left >= -1, cvH: cv.height, cvW: cv.width, taps: [...document.querySelectorAll('.ed-bar button, .ed-bar select')].map(b => b.getBoundingClientRect()).every(r => r.height >= 34 && r.width >= 34) }; });
    ok(lay.scroll <= lay.w && lay.bar, '390px: אין גלילה אופקית לדף, והסרגל בתוך המסך');
    ok(lay.cvH > 400 && lay.cvW > 350 && lay.taps, `390px: הקנבס ממלא את המסך (${Math.round(lay.cvW)}×${Math.round(lay.cvH)}), וכפתורי הסרגל ניתנים להקשה`);
    const tm = await pm.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc; const n = K.all(c.m).find(x => !x.implied && !x.kids.length && c.rectOf(x) && !c.rectOf(x).zero && c.rectOf(x).height > 10 && x.parent && x.parent.tag !== 'head'); c.m2d.get(n).setAttribute('data-test-target', ''); return c.label(n); });
    await clickIn(pm, '[data-test-target]', { touch: true });
    const sm = await st(pm);
    ok(sm.sel === tm && sm.box, '390px: הקשה (מגע) בוחרת אלמנט — ' + sm.sel);
    await sleep(450);                                                     // מ-V2: שתי הקשות מהירות (עד 380ms) הן "הקשה כפולה" — עריכת טקסט; הקשה חוזרת איטית מזה עולה להורה
    await clickIn(pm, '[data-test-target]', { touch: true });
    ok((await st(pm)).sel !== tm && (await st(pm)).crumbs.length === sm.crumbs.length - 1, '390px: הקשה חוזרת עולה להורה');
    await pm.evaluate(() => Editor._state.canvas.doc.querySelector('[data-test-target]').removeAttribute('data-test-target'));
    if (SHOTS) await pm.screenshot({ path: path.join(SHOTS, 'v1a-390-lexicon.png') });
    ok(errM.length === 0, 'אין שגיאות JavaScript בדף (390px)' + (errM.length ? ': ' + errM.slice(0, 3).join(' | ') : ''));
    await ctxM.close();
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e && e.stack || e)); }
  finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${ran - failed}/${ran} עברו` + (failed ? ` — ${failed} נכשלו` : ''));
  process.exit(failed ? 1 : 0);
})();
