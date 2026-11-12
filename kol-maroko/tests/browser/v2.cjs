/**
 * פרוסה V2 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/v2.cjs
 *
 * תוכן: טקסט במקום, תמונה, קישור וכפתור, שמע ווידאו, ולשונית "תוכן". רץ על index.html המוסב (F3) ועל עמוד בדיקה —
 * מול tests/router.php ומסד זמני. בכל שינוי נבדק: ה-diff נוגע רק בשורות של האלמנט, "בטל" מחזיר קובץ זהה בייט בבייט,
 * והשינוי נשמר ומוגש ב"▶️ תצוגה חיה".
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 600) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
/** השורות שהשתנו בין שני טקסטים: [מספר שורה ראשונה, כמה שורות ישנות, כמה חדשות]. */
const lineDiff = (a, b) => { const x = a.split('\n'), y = b.split('\n'); let s = 0; while (s < x.length && s < y.length && x[s] === y[s]) s++; let e = 0; while (e < x.length - s && e < y.length - s && x[x.length - 1 - e] === y[y.length - 1 - e]) e++; return { line: s + 1, was: x.length - s - e, now: y.length - s - e }; };

const PAGE = `<!doctype html>
<html lang="he" dir="rtl"><head><meta charset="utf-8"><title>בדיקה</title>
<style>.card { padding: 4px; } .pic { width: 120px; }</style>
</head><body>
<h1 class="hero-title" id="t1">כותרת ראשית</h1>
<p id="rich">פסקה עם <b>מודגש</b> ועוד <a href="one.html" class="lnk">קישור</a> ובסוף <b>שוב</b> מילה אחרונה.</p>
<p id="ent">&copy; 2024&nbsp;קול &amp; מרוקו &lt;תג&gt;</p>
<p id="nik">שָׁלוֹם مرحبا Hello 😀 {{title_he}}</p>
<div id="box">
  <span class="in">ילד ראשון</span>
  <span class="in">ילד שני</span>
</div>
<ul id="list">
  <li class="it">פריט א</li>
  <li class="it">פריט ב</li>
</ul>
<div id="empty"></div>
<button id="btn" type="button">לחץ כאן</button>
<a id="cta" class="btn" href="#list">לרשימה</a>
<figure id="fig"><img id="pic" class="pic" src="img/a.png" alt="תמונה א" srcset="img/a.png 1x, img/a2.png 2x"></figure>
<img id="lonely" src="img/missing.png">
<audio id="aud" src="snd/a.mp3" controls></audio>
<video id="vid" src="snd/v.mp4" width="200"></video>
<iframe id="emb" src="https://www.youtube.com/embed/xyz" width="300" height="150"></iframe>
<ul id="cards"><template><li class="card"><b class="wordx">מילה</b> <span class="mean">פירוש</span></li></template></ul>
<section id="bad"><div class="open"><p id="inbad">בתוך שבור</p></section>
</body></html>
`;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v2-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, p, { timeout: 30000 }); await sleep(150); };
    const src = page => page.evaluate(() => Editor._state.doc.text());
    const st = page => page.evaluate(() => { const S = Editor._state, c = S.canvas, n = c.selected; return { sel: n ? c.label(n) : null, typing: !!S.text.active, host: S.text.active ? c.label(S.text.n) : null, inequiv: c.inequiv.length, mapErrors: c.mapErrors.length, src: c.src, same: c.src === S.doc.text(), tx: S.doc.lastTx ? S.doc.lastTx.label : null, bar: !S.text.bar.hidden }; });
    /** מיקום במסך של אלמנט בקנבס (לפי בורר CSS בתוך המסגרת). fx / fy — חלק מהרוחב / מהגובה. */
    const at = (page, sel, fx = 0.5, fy = 0.5) => page.evaluate(([s, fx, fy]) => { const c = Editor._state.canvas, e = typeof s === 'string' ? c.doc.querySelector(s) : null; if (!e) return null; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, fx, fy]);
    const dbl = async (page, sel, fx, fy) => { const p = await at(page, sel, fx, fy); await page.mouse.dblclick(p.x, p.y); await sleep(120); };
    const click = async (page, sel, fx, fy) => { const p = await at(page, sel, fx, fy); await page.mouse.click(p.x, p.y); await sleep(80); };
    const undoAll = async (page, to) => { for (let i = 0; i < 40 && (await src(page)) !== to; i++) { await page.evaluate(() => Editor._undo()); await sleep(30); } return (await src(page)) === to; };
    /** בוחר את כל הטקסט של צומת טקסט בקנבס שמכיל את המחרוזת, ומסמן את המחרוזת. */
    const mark = (page, text) => page.evaluate(t => { const c = Editor._state.canvas, d = c.doc, w = d.createTreeWalker(Editor._state.text.e, 4); let x; while ((x = w.nextNode())) { const i = x.data.indexOf(t); if (i >= 0) { const r = d.createRange(); r.setStart(x, i); r.setEnd(x, i + t.length); const s = d.getSelection(); s.removeAllRanges(); s.addRange(r); d.dispatchEvent(new Event('selectionchange')); return true; } } return false; }, text);
    const tb = async (page, label) => { await page.click(`.ed-tbar button[aria-label^="${label}"]`); await sleep(120); };

    const ctx = await mk(1280, 800), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await call(page, 'pf_create', { path: 't/page.html', content: PAGE });
    await call(page, 'pf_create', { path: 't/one.html', content: '<!doctype html><title>one</title><h1 id="top">one</h1>' });

    // ── 1. טקסט במקום — על index.html המוסב: שש הכותרות שעל התמונה ──
    await openPage(page, 'index.html');
    const idx0 = await src(page);
    const TITLES = ['h1.kadisha-hero-title', 'p.kadisha-hero-subtitle-main', 'span.kadisha-hero-eyebrow-line:nth-child(1)', 'span.kadisha-hero-eyebrow-line:nth-child(2)', 'p.kadisha-hero-subtitle:not(.kadisha-hero-credit)', 'p.kadisha-hero-credit'];
    let allTitles = true, titleNotes = [];
    for (let i = 0; i < TITLES.length; i++) {
      const before = await src(page);
      await dbl(page, TITLES[i]);
      const s1 = await st(page);
      await page.keyboard.press('Control+End'); await page.keyboard.type(' חדש' + i);
      const live = await page.evaluate(s => Editor._state.canvas.doc.querySelector(s).textContent, TITLES[i]);
      await page.keyboard.press('Escape'); await sleep(80);
      const after = await src(page), d = lineDiff(before, after), s2 = await st(page);
      const good = s1.typing && live.endsWith(' חדש' + i) && !s2.typing && d.was === 1 && d.now === 1 && after.split('\n')[d.line - 1].includes(' חדש' + i + '</') && s2.tx && s2.tx.startsWith('טקסט · ') && s2.inequiv === 0 && s2.same;
      if (!good) { allTitles = false; titleNotes.push({ t: TITLES[i], s1: { typing: s1.typing, host: s1.host }, live, d, tx: s2.tx, line: after.split('\n')[d.line - 1] }); }
    }
    ok(allTitles, 'index.html המוסב: כל אחת משש הכותרות שעל התמונה נערכת במקום (לחיצה כפולה, הקלדה, Esc) — בכל אחת השתנתה שורה אחת בלבד, והעסקה נקראת "טקסט · …"', titleNotes);
    const idx6 = await src(page);
    ok(idx6 !== idx0 && lineDiff(idx0, idx6).was <= 6, 'אחרי שש העריכות: השינוי כולו בתוך שורות הכותרות', lineDiff(idx0, idx6));
    await page.evaluate(() => Project._save(Editor._state.file.id)); await sleep(300);
    const tok = await call(page, 'pv_token');
    const liveHtml = await (await fetch(new URL(tok.base + tok.token + '/index.html', base + '/').href)).text();
    ok(liveHtml.includes('קול מרוקו חדש0') && liveHtml.includes(' חדש5'), 'השינוי נשמר ומוגש ב"▶️ תצוגה חיה" (הכתובת המבודדת מגישה את הטקסט החדש)', liveHtml.slice(0, 200));
    ok(await undoAll(page, idx0), '"בטל" שש פעמים מחזיר את index.html בייט בבייט');
    // כפתור בעמוד המוסב: הטקסט שלו נערך במקום (span בתוך button)
    await dbl(page, '.kadisha-lex-hero-btn-text');
    const sb = await st(page);
    await page.keyboard.press('Control+End'); await page.keyboard.type('!'); await page.keyboard.press('Escape'); await sleep(80);
    ok(sb.typing && sb.host === 'span.kadisha-lex-hero-btn-text' && (await src(page)).includes('kadisha-lex-hero-btn-text">הקדמה!</span>'), 'טקסט של כפתור (span בתוך button) נערך במקום');
    ok(await undoAll(page, idx0), 'וביטול מחזיר קובץ זהה');
    // טקסט בתוך <template> (כרטיס המילה) — דרך ה-Navigator
    const tplHost = await page.evaluate(() => { const S = Editor._state, c = S.canvas, K = window.KolSrc; let hit = null; K.walk(c.m.root, n => { if (hit) return; let inT = false; for (let p = n.parent; p; p = p.parent) if (p.tag === 'template') inT = true; if (inT && window.KolText.editable(n) && window.KolText.ownText(n) && !window.KolText.blocker(c.m, n)) hit = n; }); if (!hit) return null; c.select(hit); c.reveal(hit); const ok = S.text.begin(hit); return { label: c.label(hit), ok, note: c.shownNote, text: K.textOf(c.m, hit) }; });
    let tplOk = false, tplGot = tplHost;
    if (tplHost && tplHost.ok) {
      await page.keyboard.press('Control+End'); await page.keyboard.type('★'); await page.keyboard.press('Escape'); await sleep(80);
      const a = await src(page), d = lineDiff(idx0, a); tplGot = { tplHost, d, line: a.split('\n')[d.line - 1] };
      tplOk = d.was === 1 && d.now === 1 && a.split('\n')[d.line - 1].includes('★') && (await st(page)).inequiv === 0;
    }
    ok(tplOk, 'טקסט שבתוך <template> (כרטיס המילה) נערך במקום — על העותק ש"מוצג לעריכה"; בקובץ השתנתה שורה אחת בתוך התבנית', tplGot);
    ok(await undoAll(page, idx0), 'וביטול מחזיר קובץ זהה');

    // ── 2. טקסט במקום — עמוד הבדיקה: diff מדויק, ישויות, סרגל, Enter, הדבקה ──
    await openPage(page, 't/page.html');
    const p0 = await src(page);
    await dbl(page, '#rich', 0.97, 0.5);
    const r1 = await st(page);
    ok(r1.typing && r1.host === 'p#rich' && r1.bar, 'לחיצה כפולה על פסקה: נכנסים לעריכה, והסרגל הצף מוצג', r1);
    // מחליפים מילה אחת ("אחרונה" ← "סופית") בפסקה שיש בה קישור ושני <b>
    await mark(page, 'אחרונה'); await page.keyboard.type('סופית');
    const liveNow = await page.evaluate(() => Editor._state.canvas.doc.querySelector('#rich').textContent);
    ok(liveNow.includes('מילה סופית.') && (await src(page)) === p0, 'כל הקשה מעדכנת את הקנבס מיד; הקובץ עוד לא נכתב (עסקה אחת לרצף הקלדה)');
    await page.keyboard.press('Escape'); await sleep(80);
    const p1 = await src(page);
    ok(p1 === p0.replace('מילה אחרונה.', 'מילה סופית.'), 'שינוי מילה אחת בפסקה עם קישור ושני <b>: בקובץ השתנתה המילה בלבד — התגיות, התכונות והרווחים זהים', lineDiff(p0, p1));
    ok((await st(page)).tx === 'טקסט · p#rich', 'העסקה נקראת "טקסט · p#rich"');
    ok(await undoAll(page, p0), 'Ctrl+Z אחרי היציאה מבטל את העסקה כולה — קובץ זהה');
    // שתי שניות בלי הקשה — נכתב, ונשארים בעריכה עם הסמן במקומו
    await dbl(page, '#t1'); await page.keyboard.press('Control+End'); await page.keyboard.type(' א');
    await sleep(2300);
    const idle = await st(page);
    ok(idle.typing && idle.src.includes('<h1 class="hero-title" id="t1">כותרת ראשית א</h1>') && idle.tx === 'טקסט · h1#t1', 'שתי שניות בלי הקשה: העסקה נכתבת, ונשארים בעריכה', { typing: idle.typing, tx: idle.tx });
    await page.keyboard.type('ב');
    await page.keyboard.press('Escape'); await sleep(80);
    ok((await src(page)).includes('>כותרת ראשית אב</h1>'), 'והסמן נשאר במקומו: ההקלדה ממשיכה מאותה נקודה');
    ok(await undoAll(page, p0), 'ביטול (שתי עסקאות) מחזיר קובץ זהה');
    // Ctrl+Z בתוך העריכה מבטל הקלדה
    await dbl(page, '#t1'); await page.keyboard.press('Control+End'); await page.keyboard.type('XYZ'); await page.keyboard.press('Control+z');
    const afterZ = await page.evaluate(() => Editor._state.canvas.doc.querySelector('#t1').textContent);
    await page.keyboard.press('Escape'); await sleep(80);
    ok(afterZ.length < 'כותרת ראשיתXYZ'.length && (await st(page)).typing === false, 'Ctrl+Z בתוך העריכה מבטל הקלדה (של הדפדפן), ונשארים בעריכה עד Esc', afterZ);
    await undoAll(page, p0);
    // ישויות ותווים מיוחדים
    await dbl(page, '#ent'); await mark(page, '2024'); await page.keyboard.type('2025 <b> & "x"');
    await page.keyboard.press('Escape'); await sleep(80);
    const e1 = await src(page);
    ok(e1.includes('<p id="ent">&copy; 2025 &lt;b&gt; &amp; "x"&nbsp;קול &amp; מרוקו &lt;תג&gt;</p>'), 'תווים שדורשים קידוד נכתבים נכון (< > &), וישות שלא נגעו בה נשארת כמו שנכתבה (&copy; &nbsp; &amp; &lt;)', e1.split('\n').find(l => l.includes('id="ent"')));
    ok(await undoAll(page, p0), 'ביטול מחזיר את הישויות המקוריות');
    await dbl(page, '#ent'); await page.keyboard.press('Control+Home'); await page.keyboard.press('Shift+ArrowLeft'); await page.keyboard.type('ק');
    await page.keyboard.press('Escape'); await sleep(80);
    const e2 = (await src(page)).split('\n').find(l => l.includes('id="ent"'));
    ok(/^<p id="ent">ק 2024&nbsp;קול &amp; מרוקו &lt;תג&gt;<\/p>$/.test(e2), 'ישות שנערכה (נמחקה והוחלפה) — נכתב התו שהוקלד; השאר נשאר', e2);
    await undoAll(page, p0);
    // ניקוד, ערבית, לטינית, אמוג'י ו-Mustache
    await dbl(page, '#nik'); await page.keyboard.press('Control+End'); await page.keyboard.type(' בְּרָכָה عربي {{x}} 🎉');
    await page.keyboard.press('Escape'); await sleep(80);
    const n1 = (await src(page)).split('\n').find(l => l.includes('id="nik"'));
    ok(n1 === '<p id="nik">שָׁלוֹם مرحبا Hello 😀 {{title_he}} בְּרָכָה عربي {{x}} 🎉</p>', 'עברית מנוקדת, ערבית, לטינית, אמוג\'י ותחביר Mustache נכתבים כמו שהוקלדו (הסוגריים אינם מקודדים)', n1);
    await undoAll(page, p0);
    // Enter = <br>; אין <div> ואין &nbsp; של הדפדפן
    await dbl(page, '#t1'); await page.keyboard.press('Control+End'); await page.keyboard.press('Enter'); await page.keyboard.type('שורה שנייה '); await page.keyboard.press('Shift+Enter'); await page.keyboard.type('שלישית');
    await page.keyboard.press('Escape'); await sleep(80);
    const b1 = (await src(page)).split('\n').find(l => l.includes('id="t1"'));
    ok(b1 === '<h1 class="hero-title" id="t1">כותרת ראשית<br>שורה שנייה <br>שלישית</h1>', 'Enter ו-Shift+Enter כותבים <br>; שום <div> ושום &nbsp; של הדפדפן אינם נכתבים', b1);
    ok(await undoAll(page, p0), 'ביטול מחזיר קובץ זהה');
    // הסרגל הצף: מודגש, נטוי, קו, קישור, הסר עיצוב, עטוף במחלקה
    await dbl(page, '#t1'); await mark(page, 'ראשית'); await tb(page, 'מודגש');
    const f1 = await st(page);
    ok(f1.typing && f1.src.includes('id="t1">כותרת <strong>ראשית</strong></h1>') && f1.tx === 'מודגש · h1#t1', 'מודגש: נכתבת תגית רגילה <strong>, ונשארים בעריכה', f1.tx);
    await mark(page, 'כותרת'); await tb(page, 'נטוי'); await mark(page, 'כותרת'); await tb(page, 'קו תחתון');
    const f2 = (await src(page)).split('\n').find(l => l.includes('id="t1"'));
    ok(f2 === '<h1 class="hero-title" id="t1"><em><u>כותרת</u></em> <strong>ראשית</strong></h1>', 'נטוי וקו תחתון: <em> ו-<u>', f2);
    await mark(page, 'ראשית'); await tb(page, 'קישור'); await page.fill('.ed-tb-in', 'one.html'); await page.keyboard.press('Enter'); await sleep(150);
    const f3 = (await src(page)).split('\n').find(l => l.includes('id="t1"'));
    ok(f3.includes('<strong><a href="one.html">ראשית</a></strong>') && (await st(page)).typing, 'קישור על טקסט מסומן: <a href>, ונשארים בעריכה', f3);
    await mark(page, 'ראשית'); await tb(page, 'קישור'); await page.fill('.ed-tb-in', 'javascript:alert(1)'); await page.keyboard.press('Enter'); await sleep(150);
    ok(!(await src(page)).includes('javascript:'), 'כתובת javascript: נדחית');
    await mark(page, 'כותרת'); await tb(page, 'עטוף במחלקה'); await page.fill('.ed-tb-in', 'accent'); await page.keyboard.press('Enter'); await sleep(150);
    const f4 = (await src(page)).split('\n').find(l => l.includes('id="t1"'));
    ok(f4.includes('<span class="accent">כותרת</span>'), '"עטוף במחלקה…" יוצר <span class="accent">', f4);
    await page.keyboard.press('Escape'); await sleep(80);
    const spanSel = await page.evaluate(() => { const c = Editor._state.canvas, n = window.KolSrc.q(c.m, 'span.accent'); c.select(n); return c.label(c.selected); });
    ok(spanSel === 'span.accent', 'וה-span נבחר אחר כך ככל אלמנט');
    await dbl(page, '#t1'); await page.keyboard.press('Control+a'); await page.evaluate(() => Editor._state.canvas.doc.dispatchEvent(new Event('selectionchange'))); await tb(page, 'הסר עיצוב');
    const f5 = (await src(page)).split('\n').find(l => l.includes('id="t1"'));
    ok(!/<(strong|em|u)>/.test(f5) && f5.includes('<a href="one.html">ראשית</a>'), '"הסר עיצוב" מסיר מודגש / נטוי / קו, ומשאיר קישור ומחלקה', f5);
    await page.keyboard.press('Escape'); await sleep(80);
    ok(await undoAll(page, p0), 'ביטול של כל פעולות הסרגל מחזיר קובץ זהה');
    // כיוון הטקסט
    await dbl(page, '#t1'); await tb(page, 'כיוון הטקסט');
    const d1 = await st(page);
    ok(d1.src.includes('<h1 class="hero-title" id="t1" dir="rtl">') && d1.typing, 'כפתור הכיוון כותב תכונת dir', d1.tx);
    await tb(page, 'כיוון הטקסט'); await tb(page, 'כיוון הטקסט'); await page.keyboard.press('Escape'); await sleep(80);
    ok((await src(page)) === p0, 'rtl ← ltr ← אוטומטי: התכונה מוסרת, והקובץ חוזר לקדמותו');
    await undoAll(page, p0);
    // הדבקה: טקסט נקי כברירת מחדל; "הדבק עם עיצוב" — רק b / i / u / a / br
    const paste = (html, text) => page.evaluate(([h, t]) => { const c = Editor._state.canvas, dt = new DataTransfer(); dt.setData('text/html', h); dt.setData('text/plain', t); Editor._state.text.e.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); }, [html, text]);
    const RICH = '<div style="color:red"><b>מודגש</b> <span class="zar" style="font-size:40px">זר</span> <a href="http://x.test/" onclick="x()">קישור</a><script>alert(1)</script><img src=x onerror=alert(2)></div>';
    await dbl(page, '#t1'); await page.keyboard.press('Control+End'); await paste(RICH, ' מודגש זר קישור');
    await page.keyboard.press('Escape'); await sleep(80);
    const ps1 = (await src(page)).split('\n').find(l => l.includes('id="t1"'));
    ok(ps1 === '<h1 class="hero-title" id="t1">כותרת ראשית מודגש זר קישור</h1>', 'הדבקה: טקסט נקי כברירת מחדל', ps1);
    await undoAll(page, p0);
    await dbl(page, '#t1'); await page.keyboard.press('Control+End'); await tb(page, 'הדבק עם עיצוב'); await paste(RICH, 'x');
    await page.keyboard.press('Escape'); await sleep(80);
    const ps2 = (await src(page)).split('\n').find(l => l.includes('id="t1"'));
    ok(ps2 === '<h1 class="hero-title" id="t1">כותרת ראשית<strong>מודגש</strong> זר <a href="http://x.test/">קישור</a></h1>', '"הדבק עם עיצוב": רק strong / a — בלי סגנונות, בלי מחלקות זרות, בלי סקריפט ובלי onclick', ps2);
    ok(await undoAll(page, p0), 'ביטול מחזיר קובץ זהה');
    // מכל, אלמנט ריק, פריט חדש, אזור שבור, Enter / F2
    await dbl(page, '#box', 0.02, 0.5);
    const c1 = await st(page);
    ok(c1.typing && c1.host === 'span.in', 'אלמנט בלי טקסט ישיר (מכל): לחיצה כפולה נכנסת לילד שיש בו טקסט', c1.host);
    await page.keyboard.press('Escape'); await sleep(60);
    await page.evaluate(() => { const c = Editor._state.canvas; c.select(window.KolSrc.q(c.m, '#empty')); }); await page.evaluate(() => Editor._state.canvas.frame.contentWindow.focus());
    await page.keyboard.press('F2'); await sleep(100);
    const ph = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('#empty'); return { typing: Editor._state.text.active, ph: c.frame.contentWindow.getComputedStyle(e, '::before').content }; });
    await page.keyboard.type('מילאתי'); await page.keyboard.press('Escape'); await sleep(80);
    ok(ph.typing && ph.ph.includes('הקלד כאן') && (await src(page)).includes('<div id="empty">מילאתי</div>'), 'אלמנט ריק: F2 נכנס לעריכה, מוצג "הקלד כאן", והטקסט נכתב', ph);
    await undoAll(page, p0);
    await click(page, '#list li:nth-child(2)'); await page.keyboard.press('Enter'); await sleep(100);
    const l1 = await st(page);
    await tb(page, 'פריט חדש'); await page.keyboard.type('פריט ג'); await page.keyboard.press('Escape'); await sleep(80);
    const l2 = await src(page);
    ok(l1.typing && l1.host === 'li.it' && l2.includes('  <li class="it">פריט ב</li>\n  <li class="it">פריט ג</li>\n</ul>'), 'Enter על אלמנט נבחר נכנס לעריכה; "פריט חדש" כותב <li> אח באותה מחלקה ובאותה הזחה', lineDiff(p0, l2));
    ok(await undoAll(page, p0), 'ביטול מחזיר קובץ זהה');
    await dbl(page, '#inbad');
    const bad = await st(page), badNote = await page.textContent('.ed-note');
    ok(!bad.typing && /שבור/.test(badNote) && /פתח בקוד/.test(badNote), 'אזור שבור: העריכה נדחית, עם "פתח בקוד"', badNote);
    // בחירה באלמנט אחר בזמן עריכה כותבת את מה שהוקלד
    await dbl(page, '#t1'); await page.keyboard.press('Control+End'); await page.keyboard.type('!'); await click(page, '#cta', 0.5, 0.5);
    const sw = await st(page);
    ok(!sw.typing && sw.sel === 'a#cta' && sw.src.includes('>כותרת ראשית!</h1>'), 'לחיצה על אלמנט אחר בזמן עריכה: ההקלדה נכתבת, והאלמנט האחר נבחר', { sel: sw.sel, typing: sw.typing });
    await undoAll(page, p0);
    ok((await st(page)).inequiv === 0, 'לאורך כל עריכות הטקסט: המודל ההדרגתי שקול לניתוח מלא (0 אי-שקילויות)');
//MORE
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
