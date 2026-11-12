/**
 * פרוסה V3 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/v3.cjs
 *
 * מרווחים, טיפוגרפיה וצבע: השליטה המספרית, כלל היעד ("משנה את"), מקור ונדרס, איפוס, ידיות מרווח על הקנבס (כולל
 * "אפס הוא אפס" בין שתי כותרות שעל התמונה ב-index.html המוסב), צבע וניגודיות, בחירה מרובה, מקלדת, טלפון, ועסקאות
 * בשני קבצים. בכל שינוי: ה-diff בשורות של ההצהרה, "בטל" מחזיר את כל הקבצים בייט בבייט, ואין אי-שקילות במודל.
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
<link rel="stylesheet" href="../css/page.css">
<style>
.wrap h2 { margin: 0 0 .5em; }
.row { display: flex; gap: 6px; }
.strong #imp { margin-bottom: 30px !important; }
</style>
</head><body>
<div class="wrap strong">
  <h2 id="a">כותרת א</h2>
  <h2 id="b">כותרת ב</h2>
  <p id="imp" class="note">הערה</p>
  <p id="plain">פסקה בלי מחלקה</p>
  <div class="row" id="row"><span class="cell">1</span><span class="cell">2</span><span class="cell">3</span></div>
</div>
</body></html>
`;

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v3-'));
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
    // ── הכנה: העמוד של היום מיובא (F3), ועמוד בדיקה עם קובץ CSS משותף ──
    await call(page, 'pf_migrate', {});
    await call(page, 'pf_create', { path: 't/page.html', content: PAGE });
    const cards = Array.from({ length: 1496 }, (_, i) => `  <li class="card"><b>מילה ${i}</b> <span>פירוש</span></li>`).join('\n');
    await call(page, 'pf_create', { path: 't/big.html', content: `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><link rel="stylesheet" href="../css/page.css"></head><body>\n<h1 class="big-title">מילון</h1>\n<ul class="cards">\n${cards}\n</ul>\n</body></html>\n` });
    const files = page => page.evaluate(() => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); return o; });
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const undoFiles = async (page, to) => { for (let i = 0; i < 40 && !same(await files(page), to); i++) { await page.evaluate(() => Editor._undo()); await sleep(40); } return same(await files(page), to); };
    const lines = (a, b) => { const x = a.split('\n'), y = b.split('\n'); let s = 0; while (s < x.length && x[s] === y[s]) s++; let e = 0; while (e < x.length - s && e < y.length - s && x[x.length - 1 - e] === y[y.length - 1 - e]) e++; return { at: s + 1, old: x.length - s - e, neu: y.length - s - e, text: y.slice(s, y.length - e).join('\n') }; };
    const changed = (a, b) => { const out = {}; for (const k of Object.keys(b)) if (a[k] !== b[k]) out[k] = lines(a[k], b[k]); return out; };
    const pick = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(s)); c.select(n); c.reveal(n); return !!n; }, sel);
    const tab = async (page, id) => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(120); };
    const body = id => '.ed-side-body.sg[aria-label="' + (id === 'adv' ? 'מתקדם' : 'עיצוב') + '"]';
    const field = (id, phys) => body(id) + ' [data-field="' + phys + '"]';
    const typeIn = async (page, id, phys, v) => { const q = field(id, phys) + ' .sg-ctl input'; await page.fill(q, v); await page.press(q, 'Enter'); await sleep(200); };
    const cs = (page, sel, prop) => page.evaluate(([s, p]) => { const c = Editor._state.canvas; return c.frame.contentWindow.getComputedStyle(c.doc.querySelector(s)).getPropertyValue(p); }, [sel, prop]);
    const box = (page, sel) => page.evaluate(s => { const r = Editor._state.canvas.doc.querySelector(s).getBoundingClientRect(); return { top: r.top, bottom: r.bottom, left: r.left, right: r.right }; }, sel);
    const lastTx = (page, path) => page.evaluate(p => { const S = Editor._state; const d = p === S.file.path ? S.doc : [...S.sheets.values()].find(x => x.path === p)?.doc; return d && d.lastTx ? d.lastTx.label : null; }, path);
    const inequiv = page => page.evaluate(() => Editor._state.canvas.inequiv.length);

    // ── 1. index.html המוסב: כותרת שעל התמונה — "משנה את", גודל וצבע ──
    await openPage(page, 'index.html');
    const f0 = await files(page);
    await pick(page, 'h1.kadisha-hero-title'); await tab(page, 'design');
    const tgt = await page.evaluate(q => ({ sel: document.querySelector(q + ' [data-target]').selectedOptions[0].textContent, info: document.querySelector(q + ' [data-tinfo]').textContent, n: document.querySelector(q + ' [data-target]').options.length }), body('design'));
    ok(tgt.sel.includes('.kadisha-lexicon-page .kadisha-hero-title') && tgt.info.includes('css/page.css') && /משפיע על 1 אלמנט/.test(tgt.info) && tgt.n >= 3, 'בורר היעד: "משנה את: <הכלל של המחלקה> · css/page.css · משפיע על אלמנט 1" — ועוד יעדים (כלל אחר שחל, "רק האלמנט הזה")', tgt);
    const fsSrc = await page.textContent(field('design', 'font-size') + ' [data-src]');
    ok(await page.inputValue(field('design', 'font-size') + ' .sg-ctl input') === '60px' && /css\/page\.css:\d+/.test(fsSrc), 'גודל: הערך (60px) ומאיפה הוא מגיע — קובץ, כלל ושורה', fsSrc);
    await typeIn(page, 'design', 'font-size', '48px');
    let d1 = changed(f0, await files(page));
    ok(Object.keys(d1).join() === 'css/page.css' && d1['css/page.css'].old === 1 && /font-size: 48px; font-weight: 700/.test(d1['css/page.css'].text) && await cs(page, 'h1.kadisha-hero-title', 'font-size') === '48px', 'שינוי גודל: נכתב בכלל של המחלקה ב-page.css — שורה אחת, רק הערך — והקנבס מתעדכן', d1);
    ok(/^גודל גופן · h1#lexicon-hero-title · 60px ← 48px$/.test(await lastTx(page, 'css/page.css') || ''), 'העסקה נקראת "גודל גופן · h1… · 60px ← 48px"', await lastTx(page, 'css/page.css'));
    await typeIn(page, 'design', 'color', '#ffcc00');
    d1 = changed(f0, await files(page));
    ok(/color: #ffcc00; \}/.test(d1['css/page.css'].text) && await cs(page, 'h1.kadisha-hero-title', 'color') === 'rgb(255, 204, 0)', 'צבע טקסט: הצהרה חדשה באותו כלל, בסגנון שלו', d1['css/page.css']);
    ok(await page.isVisible(field('design', 'color') + ' [data-color]'), 'ליד הצבע — בוחר צבע');
    await page.click(field('design', 'color') + ' [data-color]');
    const pc = await page.evaluate(q => ({ vars: [...document.querySelectorAll(q + ' [data-var]')].map(b => b.dataset.var), cols: document.querySelectorAll(q + ' [data-col]').length, alpha: !!document.querySelector(q + ' [data-alpha]') }), body('design'));
    ok(pc.vars.includes('--ka-cream') && pc.cols > 3 && pc.alpha, 'בוחר הצבע: שקיפות, ו"צבעים בעמוד" — הצבעים שבקובצי ה-CSS ומשתני הצבע (--ka-cream)', pc);
    await page.click(body('design') + ' [data-var="--ka-cream"]'); await sleep(200);
    ok(/color: var\(--ka-cream\); \}/.test((await files(page))['css/page.css'].split('\n').find(l => l.includes('kadisha-hero-title,')) ? (await files(page))['css/page.css'] : ''), 'בחירת משתנה כותבת var(--ka-cream)');
    ok(await page.isVisible(body('design') + ' [data-contrast="image"]'), 'ניגודיות: מעל תמונת רקע — נאמר שהיחס אינו מחושב');

    // ── 2. "אפס הוא אפס": גרירת הרווח בין שתי כותרות שעל התמונה ──
    await tab(page, 'adv');
    await pick(page, 'h1.kadisha-hero-title'); await sleep(150);
    ok(await page.isVisible('.sp-area[data-area="margin-bottom"]') !== null && await page.isVisible('[data-sp-gap]'), 'אלמנט נבחר: אזורי שוליים / ריפוד, וידית בינו לבין האח הבא');
    const gapTitle = await page.getAttribute('[data-sp-gap]', 'title');
    ok(/בין h1#lexicon-hero-title ל-p\.kadisha-hero-subtitle-main/.test(gapTitle) && /שוליים:/.test(gapTitle), 'הידית אומרת מה יוצר את הרווח: מי תורם כמה', gapTitle);
    const g0 = await page.evaluate(() => { const b = document.querySelector('[data-sp-gap]').getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; });
    const dist = async () => { const a = await box(page, 'h1.kadisha-hero-title'), b = await box(page, '.kadisha-hero-subtitle-main'); return Math.round((b.top - a.bottom) * 100) / 100; };
    const fg = await files(page);
    const before = await dist(), scale = await page.evaluate(() => Editor._state.canvas.scale);
    await page.mouse.move(g0.x, g0.y); await page.mouse.down();
    for (let i = 1; i <= 10; i++) { await page.mouse.move(g0.x, g0.y - (before * scale) * i / 10 - (i === 10 ? 0 : 0)); await sleep(16); }
    const live = await page.isVisible('.sp-num');
    await page.mouse.up(); await sleep(250);
    const zero = await dist();
    ok(live && zero === 0, 'גרירת ידית המרווח עד אפס: המספר רץ ליד הסמן, ובשחרור המרחק הנמדד בין שתי הכותרות הוא 0px', { before, zero });
    const d2 = changed(fg, await files(page));
    ok(/margin: 0 0 1\.1rem/.test(d2['css/page.css']?.text || '') && !d2['index.html'], 'נכתב בכלל שממנו הרווח מגיע (margin של .kadisha-hero-subtitle-main, רכיב בתוך הקיצור), עסקה אחת', d2);
    await page.mouse.click(g0.x, (await page.evaluate(() => { const b = document.querySelector('[data-sp-gap]').getBoundingClientRect(); return b.top + b.height / 2; }))); await sleep(150);
    ok(await page.isVisible('[data-gapbar] [data-bar-input]'), 'הקשה על הידית (בלי גרירה): סרגל עם − / + ושדה מספר לאותו רווח');
    await page.fill('[data-gapbar] [data-bar-input]', '24'); await page.press('[data-gapbar] [data-bar-input]', 'Enter'); await sleep(250);
    ok(await dist() === 24, 'מקלידים 24 — והמרחק 24px', await dist());
    ok(await undoFiles(page, f0) && await inequiv(page) === 0, '"בטל" עד ההתחלה מחזיר את index.html ואת כל קובצי ה-CSS בייט בבייט; 0 אי-שקילויות');

    // ── 3. עמוד הבדיקה: נדרס, מחלקה חדשה (שני קבצים), !important, איפוס, ערך לא חוקי ──
    await openPage(page, 't/page.html');
    const t0 = await files(page);
    await pick(page, '#a'); await tab(page, 'adv');
    const nc = await page.evaluate(q => ({ sel: document.querySelector(q + ' [data-target]').selectedOptions[0].textContent, name: document.querySelector(q + ' [data-newclass]')?.value }), body('adv'));
    ok(/מחלקה חדשה \.wrap-title · css\/page\.css/.test(nc.sel) && nc.name === 'wrap-title', 'לאלמנט בלי מחלקה: ברירת המחדל — מחלקה חדשה בשם קריא מההקשר (wrap-title), ניתן לשינוי לפני האישור', nc);
    const over = await page.textContent(field('adv', 'margin-bottom') + ' [data-over]').catch(() => '');
    ok(/לא ייראה/.test(over) && over.includes('.wrap h2') && await page.isVisible(field('adv', 'margin-bottom') + ' [data-over-write]') && await page.isVisible(field('adv', 'margin-bottom') + ' [data-over-move]'), 'שינוי שלא ייראה: הפאנל אומר זאת לפני הכתיבה (נדרס ע״י .wrap h2), ומציע לשנות את הכלל הדורס או להעביר את היעד אליו', over);
    await page.fill(body('adv') + ' [data-newclass]', 'head-a'); await page.press(body('adv') + ' [data-newclass]', 'Tab');
    await typeIn(page, 'adv', 'padding-top', '3px');
    let d3 = changed(t0, await files(page));
    ok(d3['t/page.html']?.text.trim() === '<h2 id="a" class="head-a">כותרת א</h2>' && /\.head-a \{[^}]*padding-block-start: 3px;[^}]*\}\s*$/.test((await files(page))['css/page.css']) && await cs(page, '#a', 'padding-top') === '3px', 'מחלקה חדשה: המחלקה נוספה ל-HTML והכלל ל-page.css (padding-block-start) — עסקה אחת, שני קבצים', d3);
    await page.evaluate(() => Editor._undo()); await sleep(150);
    ok(same(await files(page), t0), 'ו"בטל" אחד מחזיר את שני הקבצים בייט בבייט');
    await pick(page, '#a'); await sleep(100);
    await page.click(field('adv', 'margin-bottom') + ' [data-over-write]'); await sleep(80);
    await typeIn(page, 'adv', 'margin-bottom', '0');
    d3 = changed(t0, await files(page));
    ok(d3['t/page.html']?.text === '.wrap h2 { margin: 0 0 0; }' && Object.keys(d3).length === 1 && await cs(page, '#a', 'margin-bottom') === '0px', '"לשנות את הכלל הדורס": הרכיב בתוך הקיצור של .wrap h2 (ב-<style> של העמוד) — לא margin-bottom לצידו', d3);
    await pick(page, '#a'); await sleep(100);
    await page.click(field('adv', 'margin-top') + ' [data-over-move]'); await sleep(150);
    await page.click(field('adv', 'margin-top') + ' [data-reset]'); await sleep(200);
    d3 = changed(t0, await files(page));
    ok(d3['t/page.html']?.text === '.wrap h2 {  }' || /^\.wrap h2 \{\s*\}$/.test(d3['t/page.html']?.text || ''), 'איפוס: מוחק את ההצהרה (כאן — הקיצור כולו, והעורך אומר זאת); הכלל נשאר ריק ואינו נמחק', d3);
    ok(/הקיצור|כל ההצהרה/.test(await page.textContent('.ed-note')), 'ההודעה: "האיפוס מחק את כל ההצהרה margin"');
    ok(await undoFiles(page, t0), 'בטל ×2 — חזרה לקובץ המקורי');
    // !important של "הכלי הישן"
    await pick(page, '#imp'); await sleep(100);
    const ov2 = await page.textContent(field('adv', 'margin-bottom') + ' [data-over]').catch(() => '');
    ok(/!important/.test(ov2) && ov2.includes('.strong #imp'), 'מרווח שמקורו ב-!important: הפאנל מציג מי מנצח ולמה', ov2);
    await page.click(field('adv', 'margin-bottom') + ' [data-over-move]'); await sleep(150);
    await typeIn(page, 'adv', 'margin-bottom', '10px');
    d3 = changed(t0, await files(page));
    ok(d3['t/page.html']?.text === '.strong #imp { margin-bottom: 10px !important; }' && await cs(page, '#imp', 'margin-bottom') === '10px', '"להעביר את היעד אליו" + עריכה: הערך משתנה, ה-!important נשמר — העורך אינו מוחק ואינו מוסיף אותו מעצמו', d3);
    await page.click(field('adv', 'margin-bottom') + ' [data-unimportant]'); await sleep(200);
    ok(changed(t0, await files(page))['t/page.html']?.text === '.strong #imp { margin-bottom: 10px; }', '"הסר !important" — כפתור');
    await page.fill(field('adv', 'margin-top') + ' .sg-ctl input', 'abc'); await page.press(field('adv', 'margin-top') + ' .sg-ctl input', 'Enter'); await sleep(150);
    const badState = await page.evaluate(q => document.querySelector(q + ' .sg-ctl input').getAttribute('aria-invalid'), field('adv', 'margin-top'));
    ok(badState === 'true' && changed(t0, await files(page))['t/page.html']?.text === '.strong #imp { margin-bottom: 10px; }', 'ערך לא חוקי (abc) מסומן, ואינו נכתב');
    for (const v of ['calc(1em + 2px)', 'clamp(4px, 2vw, 20px)', '-12px', 'auto']) {
      await pick(page, '#imp'); await sleep(60); await typeIn(page, 'adv', 'margin-top', v);
    }
    const imp = (await files(page))['t/page.html'];
    ok(/\.strong #imp \{ margin-bottom: 10px; margin-block-start: auto; \}/.test(imp), 'יחידות וערכים מותאמים בהקלדה חופשית: calc(), clamp(), שלילי, auto — כולם נכתבו', imp.split('\n').find(l => l.includes('#imp {')));
    ok(await undoFiles(page, t0), '"בטל" עד ההתחלה — הקובץ בייט בבייט');
    // צעדים: חיצים, Shift ×10, Alt ×0.1, גרירה על התווית
    await pick(page, '#plain'); await tab(page, 'adv');
    const fin = field('adv', 'padding-top') + ' .sg-ctl input';
    await page.focus(fin); await page.keyboard.press('ArrowUp'); await page.keyboard.press('Shift+ArrowUp'); await sleep(600);
    ok(await cs(page, '#plain', 'padding-top') === '11px', 'חץ למעלה +1, Shift ×10 — ושמירה אחרי הפסקה קצרה (11px)', await cs(page, '#plain', 'padding-top'));
    await pick(page, '#plain'); await sleep(80);
    const lab = await page.evaluate(q => { const el = document.querySelector(q + ' [data-scrub]'); el.scrollIntoView({ block: 'center' }); const b = el.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; }, field('adv', 'padding-top'));
    await page.mouse.move(lab.x, lab.y); await page.mouse.down(); for (let i = 1; i <= 5; i++) { await page.mouse.move(lab.x + i * 4, lab.y); await sleep(16); } await page.mouse.up(); await sleep(250);
    ok(await cs(page, '#plain', 'padding-top') === '21px', 'גרירה על התווית משנה את המספר (20px ימינה ← +10)', await cs(page, '#plain', 'padding-top'));
    ok(await undoFiles(page, t0), 'בטל — חזרה');
    // gap של הורה flex
    await pick(page, '.cell'); await sleep(150);
    const gt = await page.getAttribute('[data-sp-gap]', 'title');
    ok(/gap של ההורה/.test(gt) && /בין כל 3 הילדים/.test(gt), 'ידית בין אחים בתוך flex עם gap: "משנה את הרווח בין כל 3 הילדים"', gt);
    const gp = await page.evaluate(() => { const b = document.querySelector('[data-sp-gap]').getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; });
    await page.mouse.click(gp.x, gp.y); await sleep(150);
    await page.fill('[data-gapbar] [data-bar-input]', '0'); await page.press('[data-gapbar] [data-bar-input]', 'Enter'); await sleep(250);
    const rowCss = changed(t0, await files(page))['t/page.html']?.text || '';
    const c12 = await page.evaluate(() => { const [a, b] = Editor._state.canvas.doc.querySelectorAll('.cell'); return Math.round(a.getBoundingClientRect().left - b.getBoundingClientRect().right); });
    ok(rowCss === '.row { display: flex; gap: 6px 0; }' && c12 === 0, 'gap ← 0: נכתב ה-gap של ההורה (עמודות), והמרחק בין התאים 0', { rowCss, c12 });
    ok(/בין כל 3 הילדים/.test(await page.textContent('.ed-note')) && await page.isVisible('.ed-note button:has-text("רק כאן")'), '...והעורך אומר "משנה את הרווח בין כל 3 הילדים", עם "רק כאן"');
    await page.click('.ed-note button:has-text("רק כאן")'); await sleep(250);
    const only = changed(t0, await files(page));
    ok(!(only['t/page.html']?.text || '').includes('gap: 6px 0') && /class="cell"[^>]*style="margin-inline-start: -6px"|<span class="cell" style="margin-inline-start: -6px">2<\/span>/.test(only['t/page.html']?.text || '') && await page.evaluate(() => { const [a, b] = Editor._state.canvas.doc.querySelectorAll('.cell'); return Math.round(a.getBoundingClientRect().left - b.getBoundingClientRect().right); }) === 0, '"רק כאן": ה-gap חוזר, ונכתבים שוליים לאלמנט השני בלבד', only);
    ok(await undoFiles(page, t0), 'בטל — חזרה');
    // מקלדת: Alt + חיצים
    await pick(page, '#plain'); await page.evaluate(() => document.activeElement.blur());
    await page.keyboard.press('Alt+ArrowDown'); await page.keyboard.press('Alt+ArrowDown'); await sleep(650);
    ok(await cs(page, '#plain', 'margin-bottom') !== (await page.evaluate(() => '')) && changed(t0, await files(page))['css/page.css'] !== undefined || changed(t0, await files(page))['t/page.html'] !== undefined, 'Alt + ↓ ×2: השוליים בצד שנבחר גדלים (עסקה אחת אחרי הפסקה)', changed(t0, await files(page)));
    ok(await undoFiles(page, t0), 'בטל — חזרה');
    // בחירה מרובה
    await pick(page, '#a'); await tab(page, 'design');
    const pb = await at(page, '#b'); await page.keyboard.down('Shift'); await page.mouse.click(pb.x, pb.y); await page.keyboard.up('Shift'); await sleep(200);
    ok(await page.isVisible(body('design') + ' [data-multi="2"]'), 'Shift + לחיצה: שני אלמנטים נבחרים, והפאנל אומר שהשינוי חל על שניהם');
    await typeIn(page, 'design', 'color', 'rgb(10, 20, 30)');
    ok(await cs(page, '#a', 'color') === 'rgb(10, 20, 30)' && await cs(page, '#b', 'color') === 'rgb(10, 20, 30)', 'שינוי צבע חל על שניהם');
    await page.evaluate(() => Editor._undo()); await sleep(150);
    ok(same(await files(page), t0), 'ובטל אחד מחזיר הכול');
    // ניגודיות
    await pick(page, '#plain'); await typeIn(page, 'design', 'color', '#dddddd');
    ok(await page.isVisible(body('design') + ' .sg-contrast.low'), 'ניגודיות נמוכה מ-4.5 (טקסט אפור בהיר על לבן) — מסומנת, לא חוסמת');
    ok(await undoFiles(page, t0), 'בטל — חזרה');
    // ידית גודל הטקסט, וסימון האלמנטים שהיעד משפיע עליהם
    await pick(page, '#plain'); await tab(page, 'design'); await sleep(150);
    const fh = await page.evaluate(() => { const b = document.querySelector('[data-sp-fs]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
    const fs0 = await cs(page, '#plain', 'font-size');
    if (fh) { await page.mouse.move(fh.x, fh.y); await page.mouse.down(); for (let i = 1; i <= 5; i++) { await page.mouse.move(fh.x, fh.y + i * 4); await sleep(16); } await page.mouse.up(); await sleep(250); }
    ok(fh && parseFloat(await cs(page, '#plain', 'font-size')) === parseFloat(fs0) + 10, 'ידית בפינת אלמנט טקסט: גרירה למטה (20px) מגדילה את font-size ב-10', { fs0, now: await cs(page, '#plain', 'font-size') });
    ok(await undoFiles(page, t0), 'בטל — חזרה');
    await pick(page, '.cell'); await tab(page, 'design');
    await page.hover(body('design') + ' [data-target]'); await sleep(120);
    ok(await page.$$eval('[data-sp-hi]', x => x.length) === 3, 'ריחוף על "משנה את" (כלל חדש .cell): שלושת האלמנטים שהוא משפיע עליהם מסומנים בקנבס', await page.$$eval('[data-sp-hi]', x => x.length));
    await page.mouse.move(5, 5); await sleep(60);
    // מה יוצר את הרווח
    await pick(page, '#a'); await tab(page, 'adv');
    ok(await page.isVisible(body('adv') + ' [data-sec="why"] [data-why="gap"]'), '"מה יוצר את הרווח" — בלשונית מתקדם');

    // ── 4. ביצועים: גרירת ידית עם 1,496 כרטיסים בעמוד ──
    await openPage(page, 't/big.html');
    await pick(page, 'h1.big-title'); await tab(page, 'adv'); await sleep(200);
    const hp = await page.evaluate(() => { const b = document.querySelector('[data-sp="padding-bottom"]').getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; });
    await page.mouse.move(hp.x, hp.y); await page.mouse.down();
    await page.evaluate(() => { window.__fr = 0; const f = () => { window.__fr++; if (window.__fr < 100000) window.__raf = requestAnimationFrame(f); }; window.__raf = requestAnimationFrame(f); window.__t0 = performance.now(); });
    for (let i = 1; i <= 40; i++) { await page.mouse.move(hp.x, hp.y + i); }
    const fps = await page.evaluate(() => { cancelAnimationFrame(window.__raf); return Math.round(window.__fr / ((performance.now() - window.__t0) / 1000)); });
    await page.mouse.up(); await sleep(300);
    ok(fps > 30, 'גרירת ידית עם 1,496 כרטיסים: ' + fps + ' תמונות בשנייה (מעל 30)', fps);
    await page.evaluate(() => Editor._undo()); await sleep(100);

    // ── 5. טלפון (390px): ידיות 44px, וסרגל − / + ──
    const cm = await mk(390, 780), mp = await cm.newPage();
    mp.on('pageerror', e => errors.push('390: ' + e.message));
    await openPage(mp, 'index.html');
    const m0 = await files(mp);
    await pick(mp, 'h1.kadisha-hero-title');
    await mp.evaluate(() => { document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab('adv'); document.querySelector('.ed-bar [data-panel="info"]').click(); }); await sleep(250);
    const hs = await mp.evaluate(() => { const b = document.querySelector('[data-sp-gap]'); const r = b && !b.hidden ? b.getBoundingClientRect() : null; return r && { w: Math.round(r.width), h: Math.round(r.height), x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
    ok(hs && hs.w === 44 && hs.h === 44, 'בטלפון: ידית הרווח 44×44px', hs);
    await mp.tap('[data-sp-gap]'); await sleep(200);
    ok(await mp.isVisible('[data-gapbar] [data-bar="-"]') && await mp.isVisible('[data-gapbar] [data-bar="+"]') && await mp.isVisible('[data-gapbar] [data-bar-input]'), 'הקשה על הידית: − / + ושדה מספר לאותו מרווח');
    await mp.tap('[data-gapbar] [data-bar="0"]'); await sleep(250);
    const z2 = await mp.evaluate(() => { const d = Editor._state.canvas.doc, a = d.querySelector('h1.kadisha-hero-title').getBoundingClientRect(), b = d.querySelector('.kadisha-hero-subtitle-main').getBoundingClientRect(); return Math.round((b.top - a.bottom) * 100) / 100; });
    ok(z2 === 0, 'בטלפון: "0" — המרחק בין הכותרות 0px', z2);
    const mw = changed(m0, await files(mp));
    ok(Object.keys(mw).length === 1 && /@media|font-size: 29px/.test((await files(mp))['css/page.css']), 'נכתב ב-page.css (העמוד מגדיר את רוחב הטלפון ב-@media — הכותרות עצמן)', mw);
    ok(await undoFiles(mp, m0), 'בטל — חזרה (390)');
    await cm.close();
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף לאורך כל הבדיקה', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
