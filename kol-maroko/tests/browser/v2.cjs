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
<svg id="ico" width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="8"/></svg>
<video id="auto" src="snd/v.mp4" autoplay muted></video>
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
    /** לחיצה שבוחרת את האלמנט עצמו גם כשהוא כבר נבחר (לחיצה חוזרת על הנבחר עולה להורה — V1a). */
    const clickFresh = async (page, sel, fx, fy) => { await page.evaluate(() => Editor._state.canvas.select(null)); await click(page, sel, fx, fy); };
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
    // ── 3. תמונה ──
    const b64 = PNG.toString('base64');
    for (const [pth, body] of [['t/img/a.png', b64], ['t/img/a2.png', b64], ['t/img/b.png', b64], ['t/snd/a.mp3', Buffer.from('ID3fake-a').toString('base64')], ['t/snd/b.mp3', Buffer.from('ID3fake-b').toString('base64')], ['t/snd/v.mp4', Buffer.from('fakemp4').toString('base64')]]) await call(page, 'pf_create', { path: pth, content_b64: body });
    await openPage(page, 't/page.html');
    const q0 = await src(page);
    const lineOf = (text, needle) => text.split('\n').find(l => l.includes(needle)) || '';
    /** פעולה אחת: הקובץ השתנה, רק בשורה אחת (או נוספה / נמחקה שורה אחת), והשורה החדשה עומדת בתנאי. */
    const op = async (what, fn, expect, loose) => { const b = await src(page); await fn(); await sleep(150); const a = await src(page), d = lineDiff(b, a), lines = a.split('\n').slice(d.line - 1, d.line - 1 + d.now).join('\n'), s = await st(page); ok(a !== b && d.was <= 1 && d.now <= (loose || 1) && expect(lines, a) && s.inequiv === 0 && s.same, what, { d, lines, tx: s.tx }); return b; };
    const setF = (key, v) => page.$eval(`.ct [data-f="${key}"]`, (e, v) => { if (e.type === 'checkbox') e.checked = v; else e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); }, v);
    const selNode = (sel) => page.evaluate(s => { const c = Editor._state.canvas, n = window.KolSrc.q(c.m, s); c.select(n); c.reveal(n); return c.label(n); }, sel);
    const pickItem = async name => { await page.waitForSelector(`#sheet[open] [data-pick="${name}"]`); await page.click(`[data-pick="${name}"]`); await sleep(250); };
    const ctText = () => page.textContent('.ct');
    await clickFresh(page, '#pic');
    const im1 = await st(page), imTxt = await ctText();
    ok(im1.sel === 'img#pic' && await page.isVisible('.ed-info .ct') && imTxt.includes('זו תמונה (אלמנט)') && imTxt.includes('a.png') && imTxt.includes('1×1') && imTxt.includes('srcset'), 'לחיצה על תמונה: לשונית "תוכן" (הראשונה בפאנל) אומרת "זו תמונה (אלמנט)", עם שם הקובץ, המידות וההערה על srcset', imTxt.slice(0, 200));
    ok(await page.$eval('.ct-preview img', e => e.complete && e.naturalWidth === 1), 'תצוגה מקדימה של התמונה נטענת בפאנל (מהכתובת המבודדת של הפרויקט)');
    await op('להחליף (הסרגל ← "החלף" ← בורר הספרייה): נכתב src בלבד, ה-srcset מוסר — רק שורת התמונה השתנתה', async () => { await page.click('[data-swap-img]'); await pickItem('t/img/b.png'); },
      l => l === '<figure id="fig"><img id="pic" class="pic" src="img/b.png" alt="תמונה א"></figure>');
    ok(/srcset/.test(await page.textContent('.ed-note')) && (await st(page)).tx === 'החלפת תמונה · img#pic', 'והודעה אומרת שה-srcset הוסר; העסקה: "החלפת תמונה · img#pic"');
    ok(await undoAll(page, q0), 'ביטול ההחלפה מחזיר קובץ זהה בייט בבייט');
    await clickFresh(page, '#pic');
    await op('להחליף בהעלאה מתוך הבורר: הקובץ עולה לספרייה של הכלי, מקושר לפרויקט תחת media/, והנתיב היחסי נכון (../media/…)', async () => { await page.click('.ct [data-w="replace"]'); await page.waitForSelector('#sheet[open] [data-pick-upload]'); await page.setInputFiles('[data-pick-file]', { name: 'תמונה חדשה.png', mimeType: 'image/png', buffer: PNG }); await page.waitForFunction(() => !document.getElementById('sheet').open, null, { timeout: 15000 }); },
      l => /src="\.\.\/media\/[^"]+\.png"/.test(l) && !l.includes('srcset'));
    const upRef = /src="\.\.\/(media\/[^"]+)"/.exec(await src(page))[1];
    await page.evaluate(() => Project._save(Editor._state.file.id)); await sleep(300);
    const tok2 = await call(page, 'pv_token');
    const upRes = await fetch(new URL(tok2.base + tok2.token + '/' + upRef, base + '/').href), pgLive = await (await fetch(new URL(tok2.base + tok2.token + '/t/page.html', base + '/').href)).text();
    ok(upRes.status === 200 && pgLive.includes('src="../' + upRef + '"'), '"▶️ תצוגה חיה": העמוד מוגש עם התמונה החדשה, והקובץ שלה מוגש (200)', upRes.status);
    ok(await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector('#pic'); return e.complete && e.naturalWidth === 1; }), 'והתמונה החדשה נטענת בקנבס');
    await undoAll(page, q0);
    await clickFresh(page, '#pic');
    await op('טקסט חלופי (alt)', () => setF('alt', 'חמור "לבן" & שחור'), l => l.includes('alt="חמור &quot;לבן&quot; &amp; שחור"'));
    await op('כיתוב: נכתב <figcaption> בתוך ה-<figure> הקיים', () => setF('caption', 'כיתוב <1>'), l => l.includes('></figure>') && l.includes('<figcaption>כיתוב &lt;1&gt;</figcaption></figure>'));
    await op('מחיקת הכיתוב מסירה את ה-<figcaption> (ה-<figure> נשאר, כי יש לו מזהה)', () => setF('caption', ''), l => l.startsWith('<figure id="fig"><img') && !l.includes('figcaption'));
    await op('רוחב בפאנל: נכתב בתכונת style', () => setF('width', '80'), l => l.includes('style="width: 80px"'));
    await op('התאמה (object-fit)', () => setF('fit', 'cover'), l => l.includes('style="width: 80px; object-fit: cover"'));
    await op('נקודת מיקוד: לחיצה על התצוגה המקדימה כותבת object-position', async () => { const b = await page.$eval('.ct-preview img', e => { const r = e.getBoundingClientRect(); return { x: r.left + r.width * 0.25, y: r.top + r.height * 0.75 }; }); await page.mouse.click(b.x, b.y); }, l => /object-position: \d+% \d+%/.test(l));
    await op('טעינה עצלה: loading="lazy"', () => setF('lazy', true), l => l.includes('loading="lazy"'));
    ok(await undoAll(page, q0), 'ביטול של כל שינויי התמונה מחזיר קובץ זהה');
    // ידית הגודל
    await clickFresh(page, '#pic');
    const hb = await page.evaluate(() => { const r = document.querySelector('.ed-handle').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, hidden: document.querySelector('.ed-handle').hidden, scale: Editor._state.canvas.scale }; });
    await op('גרירת הידית שבפינה משנה רוחב ושומרת יחס — נכתב ב-style, והמספר מוצג בזמן הגרירה', async () => { await page.mouse.move(hb.x, hb.y); await page.mouse.down(); await page.mouse.move(hb.x + 30 * hb.scale, hb.y + 4, { steps: 4 }); global.__sz = await page.evaluate(() => ({ t: document.querySelector('.ed-size').textContent, hidden: document.querySelector('.ed-size').hidden })); await page.mouse.up(); },
      l => /style="width: 1[45]\dpx"/.test(l) && !global.__sz.hidden && /^\d+ × \d+ px$/.test(global.__sz.t));
    const hb2 = await page.evaluate(() => { const r = document.querySelector('.ed-handle').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, scale: Editor._state.canvas.scale }; });
    await op('Shift + גרירה: חופשי — נכתבים רוחב וגובה', async () => { await page.mouse.move(hb2.x, hb2.y); await page.mouse.down(); await page.keyboard.down('Shift'); await page.mouse.move(hb2.x + 10 * hb2.scale, hb2.y + 40 * hb2.scale, { steps: 4 }); await page.mouse.up(); await page.keyboard.up('Shift'); },
      l => /style="width: \d+px; height: \d+px"/.test(l));
    ok(await undoAll(page, q0), 'ביטול הגרירות מחזיר קובץ זהה');
    // להסיר, להוסיף
    await clickFresh(page, '#pic');
    await op('להסיר: 🗑 בפאנל מוחק את האלמנט', () => page.click('.ct [data-w="remove"]'), l => l === '<figure id="fig"></figure>');
    await undoAll(page, q0);
    await clickFresh(page, '#t1');
    await op('להוסיף: "＋ תמונה" בסרגל — <img src alt=""> אחרי האלמנט הנבחר, בשורה משלו', async () => { await page.click('[data-add-img]'); await pickItem('t/img/b.png'); }, (l, a) => a.includes('<h1 class="hero-title" id="t1">כותרת ראשית</h1>\n<img src="img/b.png" alt="">\n<p id="rich">'));
    ok((await st(page)).sel === 'img' , 'והתמונה החדשה נבחרת');
    await undoAll(page, q0);
    await clickFresh(page, '#pic');
    await op('תמונה חדשה ליד תמונות אחיות מקבלת את המחלקה שלהן', async () => { await page.click('[data-add-img]'); await pickItem('t/img/b.png'); }, l => l.includes('<img src="img/b.png" alt="" class="pic"></figure>'));
    await undoAll(page, q0);
    // גרירת קובץ מהמחשב
    const dropOn = (sel, name) => page.evaluate(async ([s, name, b64]) => { const c = Editor._state.canvas, e = c.doc.querySelector(s), r = e.getBoundingClientRect(), dt = new DataTransfer(); dt.items.add(new File([Uint8Array.from(atob(b64), ch => ch.charCodeAt(0))], name, { type: 'image/png' })); const init = { dataTransfer: dt, bubbles: true, cancelable: true, clientX: r.left + r.width / 2, clientY: r.top + r.height / 2 }; const over = new DragEvent('dragover', init); e.dispatchEvent(over); const line = !c.boxDrop.hidden; e.dispatchEvent(new DragEvent('drop', init)); return { prevented: over.defaultPrevented, line }; }, [sel, name, b64]);
    let dr;
    await op('גרירת קובץ מהמחשב ישר על התמונה מחליפה אותה (src בלבד)', async () => { dr = await dropOn('#pic', 'dropped.png'); await page.waitForFunction(() => /src="\.\.\/media\/dropped/.test(Editor._state.doc.text()), null, { timeout: 15000 }); }, l => /<img id="pic" class="pic" src="\.\.\/media\/dropped[^"]*\.png" alt="תמונה א">/.test(l));
    ok(dr.prevented && dr.line, 'בזמן הגרירה מעל הקנבס מוצג סימון היעד');
    await undoAll(page, q0);
    await op('גרירת קובץ מהמחשב אל מקום אחר בקנבס מוסיפה תמונה אחרי האלמנט שמתחת לסמן', async () => { await dropOn('#nik', 'second.png'); await page.waitForFunction(() => /media\/second/.test(Editor._state.doc.text()), null, { timeout: 15000 }); }, (l, a) => /<p id="nik">.*<\/p>\n<img src="\.\.\/media\/second[^"]*\.png" alt="">\n<div id="box">/.test(a));
    ok(await undoAll(page, q0), 'ביטול מחזיר קובץ זהה');
    // תמונה חסרה, SVG שבתוך הדף
    await clickFresh(page, '#lonely');
    const ms = await st(page), msTxt = await ctText();
    ok(ms.sel === 'img#lonely' && msTxt.includes('תמונה חסרה') && msTxt.includes('img/missing.png') && await page.isVisible('.ct [data-w="replace"]') && await page.evaluate(() => [...document.querySelectorAll('.ed-mark-missing')].some(b => b.textContent.includes('img/missing.png'))), 'תמונה שהקובץ שלה חסר: מסגרת "תמונה חסרה" עם הנתיב בקנבס ובפאנל, ו"החלף"', msTxt.slice(0, 160));
    await op('כיתוב לתמונה שאינה בתוך <figure>: עוטף ב-<figure> + <figcaption>', () => setF('caption', 'בודדה'), l => l === '<figure><img id="lonely" src="img/missing.png"><figcaption>בודדה</figcaption></figure>');
    await selNode('#lonely');
    await setF('caption', ''); await sleep(150);
    ok((await src(page)) === q0, 'ומחיקת הכיתוב מסירה גם את ה-<figure> שנוסף — הקובץ חוזר לקדמותו');
    await undoAll(page, q0);
    await selNode('#ico');
    const svTxt = await ctText();
    await op('SVG שבתוך הדף נבחר כאלמנט; "החלף בקובץ מהספרייה" כותב <img>', async () => { await page.click('.ct [data-w="replace"]'); await pickItem('t/img/b.png'); }, l => l === '<img src="img/b.png" alt="">');
    ok(svTxt.includes('SVG') && svTxt.includes('פתח בקוד'), 'והפאנל מציע גם "פתח בקוד"');
    ok(await undoAll(page, q0), 'ביטול מחזיר קובץ זהה');

    // ── 4. קישור וכפתור ──
    const url0 = page.url();
    await clickFresh(page, '#cta');
    const lk = await st(page);
    ok(lk.sel === 'a#cta' && page.url() === url0 && await page.evaluate(() => Editor._state.canvas.frame.contentWindow.location.href === 'about:srcdoc'), 'לחיצה על קישור בקנבס בוחרת אותו — ואינה מנווטת');
    ok(await page.$eval('.ct [data-f="kind"]', e => e.value) === 'anchor' && await page.$eval('.ct [data-f="href"]', e => [...e.options].some(o => o.value === 't1') && e.value === 'list'), 'הפאנל מזהה "עוגן בעמוד", ומציג את רשימת המזהים שקיימים');
    await op('עוגן אחר מהרשימה', () => setF('href', 't1'), l => l === '<a id="cta" class="btn" href="#t1">לרשימה</a>');
    await op('יעד: כתובת', async () => { await setF('kind', 'url'); await sleep(120); await setF('href', 'https://example.org/a?b=1&c=2'); }, l => l.includes('href="https://example.org/a?b=1&amp;c=2"'));
    await setF('href', 'javascript:alert(1)'); await sleep(100);
    ok(!(await src(page)).includes('javascript:'), 'כתובת javascript: נדחית');
    const beforeBlank = await src(page);
    await op('"בחלון חדש": target="_blank" עם rel="noopener"', () => setF('blank', true), l => l.includes(' target="_blank" rel="noopener">'));
    await setF('blank', false); await sleep(150);
    ok((await src(page)) === beforeBlank, 'ביטול הסימון מסיר את שתי התכונות — הקובץ חוזר לקדמותו');
    await op('יעד: עמוד בפרויקט (נתיב יחסי מהקובץ הנערך)', () => setF('kind', 'page'), l => l.includes('href="one.html"'));
    await op('יעד: טלפון', async () => { await setF('kind', 'tel'); await sleep(120); await setF('href', '+972 50-123 4567'); }, l => l.includes('href="tel:+972501234567"'));
    await op('יעד: מייל', async () => { await setF('kind', 'mail'); await sleep(120); await setF('href', 'a@b.test'); }, l => l.includes('href="mailto:a@b.test"'));
    await op('יעד: קובץ מהספרייה', async () => { await setF('kind', 'file'); await pickItem('t/img/b.png'); }, l => l.includes('href="img/b.png"'));
    await page.evaluate(() => { window.__opened = []; window.open = (...a) => { window.__opened.push(a); return null; }; });
    await page.click('.ct [data-w="open-link"]');
    ok(await page.evaluate(() => __opened.length === 1 && /\/t\/img\/b\.png$/.test(__opened[0][0]) && __opened[0][2] === 'noopener'), '"פתח את הקישור" — כפתור נפרד בפאנל, פותח בחלון אחר');
    await op('"הסר קישור" מפרק את ה-<a> ומשאיר את התוכן', () => page.click('.ct [data-w="unlink"]'), l => l === 'לרשימה');
    ok(await undoAll(page, q0), 'ביטול של כל שינויי הקישור מחזיר קובץ זהה');
    await clickFresh(page, '#t1');
    await op('"הוסף קישור" על אלמנט שאינו קישור עוטף אותו ב-<a>', () => page.click('.ct [data-w="add-link"]'), l => l === '<a href="#"><h1 class="hero-title" id="t1">כותרת ראשית</h1></a>');
    ok((await st(page)).sel === 'a', 'וה-<a> החדש נבחר, עם שדות היעד');
    await undoAll(page, q0);
    await clickFresh(page, '#btn');
    const btTxt = await ctText();
    ok((await st(page)).sel === 'button#btn' && btTxt.includes('נקבע בקוד') && btTxt.includes('פתח בקוד'), '<button>: הפאנל אומר שמה שהכפתור עושה נקבע בקוד, עם "פתח בקוד"');
    await dbl(page, '#btn'); await page.keyboard.press('Control+End'); await page.keyboard.type('!'); await page.keyboard.press('Escape'); await sleep(100);
    ok((await src(page)).includes('<button id="btn" type="button">לחץ כאן!</button>'), 'והטקסט של הכפתור נערך במקום');
    ok(await undoAll(page, q0), 'ביטול מחזיר קובץ זהה');

    // ── 5. שמע, וידאו, הטמעה ──
    await clickFresh(page, '#aud');
    const au = await st(page);
    ok(au.sel === 'audio#aud' && await page.$eval('.ct [data-f="src"]', e => e.value) === 'snd/a.mp3', 'לחיצה על נגן שמע בקנבס בוחרת אותו (ואינה מפעילה אותו); הפאנל מציג את הקובץ');
    await op('שמע: קובץ מהספרייה', async () => { await page.click('.ct [data-w="pick-media"]'); await pickItem('t/snd/b.mp3'); }, l => l === '<audio id="aud" src="snd/b.mp3" controls></audio>');
    await op('שמע: לולאה', () => setF('loop', true), l => l === '<audio id="aud" src="snd/b.mp3" controls loop></audio>');
    await op('שמע: בלי פקדים', () => setF('controls', false), l => l === '<audio id="aud" src="snd/b.mp3" loop></audio>');
    await op('שמע: כתובת ביד', () => setF('src', 'https://cdn.example.org/x.mp3'), l => l.includes('src="https://cdn.example.org/x.mp3"'));
    await undoAll(page, q0);
    await clickFresh(page, '#aud');
    await page.click('.ct button:has-text("השמע כאן")'); await sleep(100);
    ok(await page.$eval('.ct-media', e => e.localName === 'audio' && /\/t\/snd\/a\.mp3$/.test(e.src) && e.controls), '▶️ בפאנל משמיע את הקובץ (נגן של המעטפת, מהכתובת של הפרויקט)');
    ok(await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('audio, video')].every(m => m.paused)), 'בקנבס המדיה אינה מתנגנת מעצמה — גם לא <video autoplay muted>');
    await clickFresh(page, '#vid');
    ok((await st(page)).sel === 'video#vid', 'וידאו נבחר בלחיצה');
    await op('וידאו: תמונת פתיחה (poster) מהספרייה', async () => { await page.click('.ct [data-w="poster"]'); await pickItem('t/img/b.png'); }, l => l === '<video id="vid" src="snd/v.mp4" width="200" poster="img/b.png"></video>');
    await op('וידאו: מושתק', () => setF('muted', true), l => l.includes(' poster="img/b.png" muted>'));
    await op('וידאו: ניגון אוטומטי', () => setF('autoplay', true), l => l.includes(' muted autoplay>'));
    ok(await undoAll(page, q0), 'ביטול של שינויי המדיה מחזיר קובץ זהה');
    await clickFresh(page, '#emb');
    const em = await st(page), emTxt = await ctText();
    ok(em.sel === 'iframe#emb' && emTxt.includes('אינה נטענת') && await page.isVisible('.ct [data-f="sandbox-note"]') && await page.evaluate(() => [...document.querySelectorAll('.ed-mark-frame')].some(b => b.textContent.includes('youtube.com/embed/xyz'))), 'הטמעה (iframe): נבחרת בלחיצה, בקנבס מסגרת עם הכתובת, והפאנל אומר שנגן מוטמע עלול שלא לעבוד בארגז החול', emTxt.slice(0, 120));
    ok(await page.evaluate(() => { try { return Editor._state.canvas.doc.querySelector('#emb').contentDocument === null || Editor._state.canvas.doc.querySelector('#emb').contentWindow.length === 0; } catch { return true; } }) && await page.evaluate(() => [...Editor._state.blocked.keys()].some(u => u.includes('youtube.com'))), 'וההטמעה אינה נטענת בעורך (המדיניות חוסמת אותה, והיא ברשימת "אינם מוצגים בעורך")');
    await op('הטמעה: רוחב', () => setF('width', '420'), l => l.includes('width="420" height="150"'));
    await op('הטמעה: כותרת (title)', () => setF('title', 'סרטון'), l => l.includes('title="סרטון"'));
    await op('הטמעה: כתובת', () => setF('src', 'https://player.vimeo.com/video/1'), l => l.includes('src="https://player.vimeo.com/video/1"'));
    ok(await undoAll(page, q0), 'ביטול מחזיר קובץ זהה');

    // ── 6. index.html המוסב: תמונת הרקע (❌ מפורש), כפתור, והלשונית ──
    await openPage(page, 'index.html');
    await clickFresh(page, 'h1.kadisha-hero-title');
    const bgTxt = await ctText();
    ok(await page.isVisible('.ct [data-f="bg-note"]') && /V5/.test(await page.textContent('.ct [data-f="bg-note"]')) && bgTxt.includes('section.kadisha-hero') && /css\/[a-z.-]+\.css:\d+/.test(await page.textContent('.ct [data-w="bg-code"]')), 'index.html: כותרת שעל התמונה — הפאנל אומר במפורש שזו תמונת רקע (CSS) של section.kadisha-hero, שהחלפה והסרה שלה אינן על המסך (V5), ומוביל להצהרה בקוד', bgTxt.slice(-260));
    ok(await page.$$eval('.ed-ptabs [role="tab"]', t => t.map(x => x.textContent + ':' + x.getAttribute('aria-selected')).join('|')) === '✏️ תוכן:true|ℹ️ פרטים:false', 'לשונית "תוכן" היא הראשונה בפאנל, והיא הפתוחה');
    await clickFresh(page, '.kadisha-lex-hero-btn'); await page.evaluate(() => { const c = Editor._state.canvas; let n = c.selected; while (n && n.tag !== 'button') n = n.parent; c.select(n); });
    const hb0 = await src(page);
    ok(await page.$eval('.ct [data-f="data-panel"]', e => e.localName === 'select' && e.value === 'kadisha-lex-panel-0'), 'כפתור בעמוד המוסב: הפאנל מציג את היעד שלו (data-panel) כרשימת המזהים שבעמוד');
    await op('index.html: שינוי היעד של כפתור (data-panel) — רק שורת הכפתור השתנתה', () => setF('data-panel', 'kadisha-lex-panel-1'), l => l.includes('data-panel="kadisha-lex-panel-1" aria-expanded="false" aria-controls="kadisha-lex-panel-0"'));
    await page.evaluate(() => Project._save(Editor._state.file.id)); await sleep(300);
    const tok3 = await call(page, 'pv_token');
    ok((await (await fetch(new URL(tok3.base + tok3.token + '/index.html', base + '/').href)).text()).includes('<button type="button" class="kadisha-lex-hero-btn kadisha-lex-gate" data-panel="kadisha-lex-panel-1" aria-expanded="false" aria-controls="kadisha-lex-panel-0">'), 'והשינוי מוגש ב"▶️ תצוגה חיה"');
    ok(await undoAll(page, hb0), 'ביטול מחזיר את index.html בייט בבייט');
    const imgs = await page.evaluate(() => Editor._state.canvas.doc.querySelectorAll('body img').length);
    console.log('   index.html המוסב: ' + imgs + ' אלמנטי <img> בגוף העמוד' + (imgs ? '' : ' — החלפה והסרה של תמונה נבדקות כאן על תמונה שמוסיפים'));
    await clickFresh(page, 'p.kadisha-hero-credit');
    const ib = await src(page);
    await op('index.html: "להוסיף תמונה אחרת" — ＋ תמונה אחרי הכותרת האחרונה שעל התמונה', async () => { await page.click('[data-add-img]'); await pickItem('t/img/a.png'); }, (l, a) => /kadisha-hero-credit">[^\n]*<\/p>\n\s*<img src="t\/img\/a\.png" alt="">\n/.test(a));
    await op('index.html: להחליף את התמונה', async () => { await page.click('[data-swap-img]'); await pickItem('t/img/b.png'); }, l => l.trim() === '<img src="t/img/b.png" alt="">');
    await page.evaluate(() => Editor._state.canvas.frame.contentWindow.focus());
    await op('index.html: להסיר את התמונה (Delete)', () => page.keyboard.press('Delete'), (l, a) => !a.includes('<img src="t/img/b.png"'), 0);
    ok((await src(page)) === ib, 'אחרי ההסרה הקובץ זהה למה שהיה לפני ההוספה');
    ok(await undoAll(page, hb0) && (await st(page)).inequiv === 0, 'ו"בטל" עד ההתחלה מחזיר את index.html בייט בבייט; 0 אי-שקילויות');

    // ── 7. טלפון (390px) ──
    const cm = await mk(390, 780), pm = await cm.newPage();
    pm.on('pageerror', e => { errors.push('390: ' + e.message); });
    await pm.goto(base + '/#/project'); await pm.waitForSelector('.pj-tree .pj-row', { state: 'attached' });
    await openPage(pm, 't/page.html');
    const tp = await at(pm, '#t1');
    await pm.touchscreen.tap(tp.x, tp.y); await sleep(90); await pm.touchscreen.tap(tp.x, tp.y); await sleep(250);
    const ph1 = await pm.evaluate(() => { const S = Editor._state, b = S.text.bar.getBoundingClientRect(), e = S.text.e && S.text.e.getBoundingClientRect(), f = S.canvas.frame.getBoundingClientRect(); return { typing: S.text.active, host: S.text.active ? S.canvas.label(S.text.n) : '', bar: !S.text.bar.hidden && b.left >= -1 && b.right <= innerWidth + 1 && b.bottom <= innerHeight + 1, below: !!e && b.top >= f.top + e.bottom * S.canvas.scale - 1, taps: [...S.text.bar.querySelectorAll('button')].every(x => x.getBoundingClientRect().height >= 44) }; });
    ok(ph1.typing && ph1.host === 'h1#t1' && ph1.bar && ph1.taps, '390px: הקשה כפולה פותחת עריכה; הסרגל הצף בתוך המסך, בתחתית החלון הנראה, וכפתוריו 44px', ph1);
    await pm.keyboard.press('Control+End'); await pm.keyboard.type(' נייד'); await pm.click('.ed-tbar button[aria-label^="סיום"]'); await sleep(150);
    ok((await pm.evaluate(() => Editor._state.doc.text())).includes('>כותרת ראשית נייד</h1>') && !(await pm.evaluate(() => Editor._state.text.active)), '390px: ההקלדה נכתבת, ו-✓ בסרגל מסיים');
    await pm.evaluate(() => Editor._undo()); await sleep(100);
    const ip = await at(pm, '#pic'); await pm.touchscreen.tap(ip.x, ip.y); await sleep(200);
    await pm.tap('[data-panel="info"]'); await sleep(200);
    ok(await pm.evaluate(() => { const S = Editor._state, p = document.querySelector('.ed-info'); return S.canvas.selected && S.canvas.selected.tag === 'img' && getComputedStyle(p).position === 'fixed' && !document.querySelector('.ct').hidden && !!document.querySelector('.ct [data-w="replace"]') && !!document.querySelector('[data-swap-img]'); }), '390px: תמונה נבחרת בהקשה; לשונית "תוכן" נפתחת כגיליון מלמטה, עם "החלף"');
    // המקלדת עולה (החלון הנראה מתכווץ): האלמנט הנערך נגלל אל מעליה ומעל הסרגל הצף
    await pm.tap('.ed-info .ed-side-head button'); await sleep(150);                // סוגרים את גיליון הפאנל
    await pm.evaluate(() => { const e = Editor._state.canvas.doc.querySelector('#list li:nth-child(2)'); e.scrollIntoView({ block: 'end' }); Editor._state.canvas.frame.contentWindow.scrollBy(0, -10); });
    await sleep(100);
    const kp = await at(pm, '#list li:nth-child(2)');
    await pm.touchscreen.tap(kp.x, kp.y); await sleep(90); await pm.touchscreen.tap(kp.x, kp.y); await sleep(250);
    await pm.setViewportSize({ width: 390, height: 430 }); await sleep(400);
    const kb = await pm.evaluate(() => { const S = Editor._state, c = S.canvas, f = c.frame.getBoundingClientRect(), r = S.text.e.getBoundingClientRect(), b = S.text.bar.getBoundingClientRect(), vv = visualViewport; return { typing: S.text.active, host: S.text.active ? c.label(S.text.n) : '', bottom: f.top + r.bottom * c.scale, top: f.top + r.top * c.scale, barTop: b.top, vh: vv.offsetTop + vv.height }; });
    ok(kb.typing && kb.host === 'li.it' && kb.bottom <= kb.barTop + 1 && kb.top >= 0 && kb.barTop + 1 >= 0 && kb.barTop < kb.vh, '390px: כשהמקלדת עולה (החלון הנראה מתכווץ), האלמנט הנערך נגלל אל מעליה — מעל הסרגל הצף', kb);
    await pm.keyboard.press('Escape'); await sleep(80);
    await pm.setViewportSize({ width: 390, height: 780 });
    // "🔄 ייבוא מחדש" נגיש בטלפון — בתפריט ⋯ של עורך הקוד (רשימת הקבצים סגורה שם)
    await pm.goto(base + '/#/project'); await pm.waitForSelector('.pj-tree .pj-row', { state: 'attached' }); await pm.waitForSelector('[data-ed]');
    await pm.tap('[data-ed]'); await sleep(200);
    ok(await pm.evaluate(() => [...document.querySelectorAll('#sheet[open] button')].some(b => b.textContent.includes('ייבוא מחדש מהעמוד של היום') && b.getBoundingClientRect().height >= 34)), '390px: "🔄 ייבוא מחדש מהעמוד של היום" בתפריט ⋯ של עורך הקוד', await pm.evaluate(() => (document.querySelector('#sheet[open]') || {}).textContent || 'אין גיליון פתוח'));
    await cm.close();

    // ── 8. "פרויקט אחד" (הכרעת הבעלים): ייבוא מחדש במקום "פרויקט חדש", בלי "פרויקט ריק"; ותבניות ישנות ──
    await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
    const migBtn = await page.textContent('.pj-side-btns [data-migrate]');
    await page.click('.pj-side-btns [data-migrate]'); await page.waitForSelector('#sheet[open] [data-migrate-run]');
    const migSheet = await page.evaluate(() => ({ title: document.getElementById('sheet-title').textContent, blank: !!document.querySelector('#sheet [data-blank-run]'), text: document.getElementById('sheet').textContent }));
    ok(migBtn.includes('🔄 ייבוא מחדש מהעמוד של היום') && migSheet.title.includes('ייבוא מחדש') && !migSheet.blank && !/פרויקט ריק|פרויקט חדש/.test(migSheet.text + migBtn), 'מנהל הקבצים: "🔄 ייבוא מחדש מהעמוד של היום" (לא "פרויקט חדש"), ואין "📄 פרויקט ריק"', migSheet);
    await page.evaluate(() => document.getElementById('sheet').close());
    const tpl = await page.evaluate(() => [...document.querySelectorAll('.pj-row:not(.pj-dir)')].map(r => ({ dir: r.dataset.dir, name: r.querySelector('.one').textContent, chip: !!r.querySelector('[data-old-tpl]') })));
    ok(tpl.some(r => r.chip) && tpl.every(r => r.chip === (r.dir === 'from-current' && /\.html?$/i.test(r.name))), 'בעץ: קובצי התבנית של from-current/ מסומנים "תבנית ישנה" — ורק הם', tpl.filter(r => r.chip !== (r.dir === 'from-current' && /\.html?$/i.test(r.name))));
    await page.evaluate(() => { localStorage.setItem('kol-editor', JSON.stringify({ page: 'from-current/lexicon.html' })); });
    await page.goto(base + '/editor.php#/editor'); await page.waitForFunction(() => Editor._state.file && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, null, { timeout: 30000 });
    const dflt = await page.evaluate(() => ({ path: Editor._state.file.path, opt: [...document.querySelectorAll('.ed-page option')].find(o => o.textContent.startsWith('from-current/lexicon.html'))?.textContent || '' }));
    ok(dflt.path === 'index.html' && dflt.opt.includes('תבנית ישנה'), '"🎨 עורך" נפתח על index.html — גם כשהעמוד האחרון שנפתח היה תבנית ישנה; ובבורר העמוד היא מסומנת "תבנית ישנה"', dflt);
//MORE
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
