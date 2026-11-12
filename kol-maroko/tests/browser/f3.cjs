/**
 * פרוסה F3 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/f3.cjs
 *
 *  א. js/kol-data.js — בדיקה לכל תכונה שבטבלה של 02 §8.2, אי-תלות במבנה, ומקור אטום.
 *  ב. ההסבה מהמסך (🪄), ו"▶️ תצוגה חיה" על index.html המוסב.
 *  ג. רתמת הנאמנות (09 §4): הפרויקט המוסב מול "העמוד של היום" כפי שהכלי הישן מרנדר אותו (lexicon_render) —
 *     מראה ב-390/768/1280/1920, מספרים וסדר, 20 שאילתות חיפוש, כל נושא, שילוב, השמעה, מבוא, משוב, מקלדת, מהירות.
 *     ⚠️ ההשוואה אינה מול העמוד החי: הצוות אינו ניגש לאתר. ה-CSS כאן הוא קובץ בדיקה בסגנון ערכת הנושא, וה-JS של
 *     ערכת הנושא אינו זמין — ההתנהגות נבדקת מול מודל הנתונים של התבנית הישנה (data-search, data-topics).
 *  ד. העורך החזותי על index.html המוסב: 0 אלמנטים "אזור שבור"; כל אלמנט שנראה נבחר, נמחק ומבוטל.
 *
 * KOL_REPORT=<קובץ> — כותב את התוצאות כ-JSON (לדוח dev/migration-report.md).  KOL_SHOTS=<תיקייה> — שומר צילומים.
 * רץ מול tests/router.php ומסד זמני — data/ לא נפתחת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.KOL_SHOTS || '', REPORT = process.env.KOL_REPORT || '';
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const rows = [];                                               // לדוח: [קבוצה, מה, עבר?, פרט]
let group = '';
const ok = (cond, what, detail = '') => { ran++; rows.push([group, what, !!cond, detail]); if (cond) console.log('✅ ' + what + (detail ? ' — ' + detail : '')); else { failed++; console.log('❌ ' + what + (detail ? ' — ' + detail : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const KOL_DATA = fs.readFileSync(path.join(ROOT, 'assets', 'project-seed', 'kol-data.js'), 'utf8');
const HERO_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="600"><defs><linearGradient id="g" x1="0" x2="1"><stop offset="0" stop-color="#7a4b2a"/><stop offset="1" stop-color="#1d3557"/></linearGradient></defs><rect width="1600" height="600" fill="url(#g)"/></svg>';

// ── א. עמוד קטן לבדיקות היחידה של kol-data.js ──
const UNIT_DATA = { topics: [{ id: 'food', label: 'אוכל' }, { id: 'greet', label: 'ברכות' }], items: [
  { id: 3, word: 'גימל', meaning: '<p>פירוש <b>שלישי</b> תפוח</p>', topics: ['food'], audio: 'a/3.mp3', kind: 'song' },
  { id: 1, word: 'אלף', meaning: '<p>פירוש ראשון</p>', topics: ['food', 'greet'], audio: '', kind: 'word' },
  { id: 2, word: 'בית Bet', meaning: '<p>פירוש שני &amp; עוד</p>', topics: [], audio: 'a/2.mp3', kind: 'word' },
  { id: 4, word: 'דלת', meaning: '<p>רביעי</p>', topics: ['greet'], audio: 'a/4.mp3', kind: 'word' },
  { id: 5, word: 'הא', meaning: '<p>חמישי</p>', topics: ['greet'], audio: 'a/5.mp3', kind: 'word' },
] };
const UNIT_PAGE = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><title>unit</title></head><body>
<header id="top"><input id="q" type="search" data-kol-search="words" data-kol-search-fields="word">
<label><input id="scope" type="checkbox" data-kol-search-scope="words" data-kol-search-fields="word meaning topics">הרחב</label></header>
<nav id="filter" data-kol-filter="words:topics" data-kol-active-class="on"><button id="all" data-kol-filter-value="">הכל</button>
<template><button class="tb" data-kol-attr-data-t="id"><span data-kol-text="label"></span> (<span class="n" data-kol-text="count"></span>)</button></template></nav>
<p id="count" data-kol-count="words" data-kol-count-text="{shown} מתוך {total}"></p>
<main id="main"><ul id="list" data-kol-list="words" data-kol-sort="word" data-kol-page="2"><template>
<li class="card" data-kol-attr-data-id="id" data-kol-class-solo="topics.count=1" data-kol-class-song="kind=song">
  <h3 data-kol-text="word"></h3><i class="num" data-kol-text="#{id}"></i>
  <div class="m" data-kol-html="meaning"></div>
  <a class="lnk" data-kol-attr-href="audio" data-kol-attr-title="שמע של {word}" data-kol-if="audio">קובץ</a>
  <em class="noaudio" data-kol-if="!audio">אין הקלטה</em>
  <span class="tp" data-kol-each="topics" data-kol-text="label" data-kol-attr-data-tid="id"></span>
  <button class="play" data-kol-if="audio" data-kol-play data-kol-active-class="playing">▶</button>
  <button class="fb" data-kol-open="feedback">משוב</button>
</li></template></ul></main>
<button id="more" data-kol-more="words">עוד</button>
<p id="empty" data-kol-empty="words" hidden>אין תוצאות</p>
<div id="dlg" data-kol-dialog="feedback" hidden><strong id="dw" data-kol-text="word"></strong>
<form id="form" data-kol-form="feedback" data-kol-form-done="נרשם, עוד לא נשלח"><textarea id="msg" required></textarea><button id="send" type="submit">שלח</button><button id="x" type="button" data-kol-close>סגור</button><span id="status" data-kol-form-status></span></form></div>
<script>window.__aud = []; window.Audio = function (src) { var a = { src: src, paused: true, l: {}, play: function () { a.paused = false; return Promise.resolve(); }, pause: function () { a.paused = true; }, addEventListener: function (k, f) { a.l[k] = f; } }; window.__aud.push(a); return a; };</script>
<script src="js/kol-data.js"></script></body></html>`;

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-f3-'));
  const oldFile = path.join(data, 'old-render.html');
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, oldFile]);
  const OLD = fs.readFileSync(oldFile, 'utf8');
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  const report = { at: new Date().toISOString(), visual: [], perf: {}, counts: {} };
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();

    // ════ א. kol-data.js ════
    group = 'kol-data.js';
    {
      const ctx = await browser.newContext({ viewport: { width: 900, height: 700 } }), page = await ctx.newPage();
      const errs = [];
      page.on('pageerror', e => errs.push(e.message)); page.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
      let html = UNIT_PAGE;
      await ctx.route('http://kol.test/**', r => {
        const u = new URL(r.request().url()).pathname;
        if (u === '/') return r.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
        if (u === '/js/kol-data.js') return r.fulfill({ contentType: 'text/javascript', body: KOL_DATA });
        if (u === '/data/words.json') return r.fulfill({ contentType: 'application/json', body: JSON.stringify(UNIT_DATA) });
        return r.fulfill({ status: 404, body: '' });
      });
      const load = async () => { await page.goto('http://kol.test/'); await page.waitForSelector('#list[data-kol-ready]', { state: 'attached' }); };
      const words = () => page.$$eval('#list .card h3', a => a.map(x => x.textContent));
      const ready = () => page.waitForSelector('#list[data-kol-ready]', { state: 'attached' });
      await load();
      ok(JSON.stringify(await words()) === JSON.stringify(['אלף', 'בית Bet']), 'data-kol-list + <template>, data-kol-sort="word", data-kol-page="2"', (await words()).join(' | '));
      ok(await page.textContent('#list .card h3') === 'אלף' && await page.$eval('#list .card .num', e => e.textContent) === '#1', 'data-kol-text: שדה, ותבנית "#{id}"');
      ok(await page.$eval('#list .card .m', e => e.innerHTML) === '<p>פירוש ראשון</p>' && await page.$eval('#list .card:nth-of-type(2) .m', e => e.innerHTML) === '<p>פירוש שני &amp; עוד</p>', 'data-kol-html');
      ok(await page.$eval('#list .card .lnk', e => e.getAttribute('href') === 'a/2.mp3' && e.getAttribute('title') === 'שמע של בית Bet') && await page.$eval('#list .card', e => e.getAttribute('data-id')) === '1', 'data-kol-attr-*: שדה, ותבנית עם טקסט');
      ok(await page.$$eval('#list .card', a => a.map(c => [!!c.querySelector('.lnk'), !!c.querySelector('.noaudio'), !!c.querySelector('.play')].join())).then(v => v.join(' ') === 'false,true,false true,false,true'), 'data-kol-if="audio" / "!audio"');
      ok(await page.$$eval('#list .card', a => a.map(c => [...c.querySelectorAll('.tp')].map(t => t.textContent + ':' + t.dataset.tid).join('+'))).then(v => v.join(' | ') === 'אוכל:food+ברכות:greet | '), 'data-kol-each="topics" (עם התווית מטבלת הנושאים)');
      await page.click('#more'); await ready();
      ok((await words()).length === 4 && await page.isVisible('#more'), 'data-kol-more: עוד עמוד', (await words()).join(' | '));
      await page.click('#more'); await ready();
      ok((await words()).length === 5 && !(await page.isVisible('#more')), '…והכפתור נעלם כשאין עוד');
      ok(await page.$$eval('#list .card', a => a.map(c => c.className).join(' | ')) === 'card | card | card solo song | card solo | card solo', 'data-kol-class-*: "topics.count=1", "kind=song"');
      ok(await page.textContent('#count') === '5 מתוך 5', 'data-kol-count + data-kol-count-text', await page.textContent('#count'));
      await page.fill('#q', 'bet'); await ready();
      ok(JSON.stringify(await words()) === JSON.stringify(['בית Bet']) && await page.textContent('#count') === '1 מתוך 5', 'data-kol-search: חי, בלי תלות ברישיות');
      await page.fill('#q', 'תפוח'); await ready();
      ok((await words()).length === 0 && await page.isVisible('#empty'), 'data-kol-empty: מוצג כשאין תוצאות; data-kol-search-fields="word" — לא מחפש בפירוש');
      await page.check('#scope'); await ready();
      ok(JSON.stringify(await words()) === JSON.stringify(['גימל']) && !(await page.isVisible('#empty')), 'data-kol-search-scope: מסומן ← גם בפירוש (בלי תגיות ה-HTML)');
      await page.fill('#q', 'ברכות'); await ready();
      ok((await words()).length === 2, '…וגם בתוויות של הנושאים', (await words()).join(' | '));
      await page.fill('#q', ''); await page.uncheck('#scope'); await ready();
      ok(await page.$$eval('#filter .tb', a => a.map(b => b.textContent.replace(/\s+/g, ' ').trim() + '/' + b.dataset.t).join(' | ')) === 'אוכל (2)/food | ברכות (3)/greet', 'data-kol-filter="words:topics": כפתור לכל נושא, מהתבנית, עם המונה');
      await page.click('#filter .tb >> nth=1'); await ready(); await page.click('#more').catch(() => {}); await ready();
      ok(JSON.stringify(await words()) === JSON.stringify(['אלף', 'דלת', 'הא']) && await page.$eval('#filter .tb:nth-of-type(3)', b => b.classList.contains('on') && b.getAttribute('aria-pressed') === 'true') && await page.$eval('#all', b => b.getAttribute('aria-pressed') === 'false'), 'לחיצה על נושא מסננת; הכפתור הפעיל מסומן');
      await page.fill('#q', 'אל'); await ready();
      ok(JSON.stringify(await words()) === JSON.stringify(['אלף']), 'סינון + חיפוש יחד');
      await page.fill('#q', ''); await page.click('#all'); await ready(); await page.click('#more'); await ready(); await page.click('#more'); await ready();
      ok((await words()).length === 5, 'כפתור קבוע data-kol-filter-value="" מחזיר הכול');
      // נגן
      await page.click('#list .card:nth-of-type(2) .play');
      await page.click('#list .card:nth-of-type(3) .play');
      let au = await page.evaluate(() => window.__aud.map(a => a.src + ':' + (a.paused ? 'p' : 'on')));
      ok(au.join(' ') === 'a/2.mp3:p a/3.mp3:on' && await page.$eval('#list .card:nth-of-type(3) .play', b => b.classList.contains('playing')) && !(await page.$eval('#list .card:nth-of-type(2) .play', b => b.classList.contains('playing'))), 'data-kol-play: מתנגן; הקלטה שנייה עוצרת את הראשונה', au.join(' '));
      await page.click('#list .card:nth-of-type(3) .play');
      ok(await page.evaluate(() => window.__aud[1].paused) && !(await page.$eval('#list .card:nth-of-type(3) .play', b => b.classList.contains('playing'))), 'לחיצה שנייה על אותו כפתור — עצירה');
      // חלון וטופס
      await page.click('#list .card:nth-of-type(1) .fb');
      ok(await page.isVisible('#dlg') && await page.textContent('#dw') === 'אלף' && await page.evaluate(() => document.activeElement.id) === 'msg', 'data-kol-open / data-kol-dialog: נפתח מהכרטיס, עם המילה, והמיקוד בשדה הראשון');
      await page.fill('#msg', 'הערה'); await page.click('#send');
      ok(await page.textContent('#status') === 'נרשם, עוד לא נשלח', 'data-kol-form: הנוסח של data-kol-form-done מוצג; דבר אינו נשלח');
      await page.keyboard.press('Escape');
      ok(!(await page.isVisible('#dlg')) && await page.evaluate(() => document.activeElement.className) === 'fb', 'Escape סוגר, והמיקוד חוזר לכפתור שפתח');
      await page.click('#list .card:nth-of-type(2) .fb'); await page.click('#x');
      ok(!(await page.isVisible('#dlg')), 'data-kol-close');
      ok(errs.length === 0, 'בלי שגיאות במסוף', errs.slice(0, 2).join(' | '));
      // מיון
      html = UNIT_PAGE.replace('data-kol-sort="word" data-kol-page="2"', 'data-kol-sort="-id"'); await load();
      ok((await page.$$eval('#list .card', a => a.map(c => c.dataset.id).join(''))) === '54321', 'data-kol-sort="-id" (יורד), ובלי data-kol-page — הכול מוצג');
      html = UNIT_PAGE.replace('data-kol-sort="word" data-kol-page="2"', 'data-kol-sort="manual"'); await load();
      ok((await page.$$eval('#list .card', a => a.map(c => c.dataset.id).join(''))) === '31245', 'data-kol-sort="manual" — סדר הקובץ');
      // אי-תלות במבנה: החיפוש בסוף העמוד, הרשימה בתוך שני מכלים, בלי מונה
      errs.length = 0;
      html = UNIT_PAGE.replace(/<p id="count"[^>]*><\/p>/, '').replace(/<header id="top">([\s\S]*?)<\/header>/, '').replace('<main id="main">', '<main id="main"><div class="w1"><section class="w2">').replace('</ul></main>', '</ul></section></div></main>')
        .replace('<script src="js/kol-data.js">', '<footer><input id="q" type="search" data-kol-search="words" data-kol-search-fields="word"></footer><script src="js/kol-data.js">');
      await load();
      await page.fill('#q', 'דלת'); await ready();
      const moved = await words();
      await page.fill('#q', ''); await ready(); await page.click('#filter .tb >> nth=0'); await ready();
      ok(JSON.stringify(moved) === JSON.stringify(['דלת']) && (await words()).length === 2 && errs.length === 0, 'אי-תלות במבנה: חיפוש בסוף העמוד, רשימה בתוך שני מכלים, בלי מונה — הכול עובד, בלי שגיאה', errs.join(' | '));
      ok(!/localStorage|sessionStorage|indexedDB|document\.cookie/.test(KOL_DATA.replace(/\/\*[\s\S]*?\*\/|\/\/[^\n]*/g, '')), 'הקובץ אינו נוגע באחסון של הדפדפן');
      await ctx.close();
    }

    // ════ ב. ההסבה מהמסך, והתצוגה החיה ════
    group = 'ההסבה והתצוגה החיה';
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const siteReq = [];
    await ctx.route('https://maghreb.co.il/**', r => { siteReq.push(r.request().method() + ' ' + r.request().url()); return r.request().url().endsWith('hero.jpg') ? r.fulfill({ contentType: 'image/svg+xml', body: HERO_SVG }) : r.fulfill({ status: 404, body: '' }); });
    await ctx.route('https://fonts.googleapis.com/**', r => r.fulfill({ contentType: 'text/css', body: '' }));
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.accept());
    const call = (action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    ok(await page.isVisible('.pj-side-btns [data-migrate]'), 'בעלים רואה "🔄 ייבוא מחדש מהעמוד של היום" במנהל הקבצים');
    await page.click('.pj-side-btns [data-migrate]');
    await page.click('[data-migrate-run]');
    await page.waitForFunction(() => document.querySelector('#sheet')?.open && document.querySelector('#sheet').textContent.includes('מה נלקח מאיפה'), null, { timeout: 30000 });
    const sheetText = await page.textContent('#sheet');
    ok(sheetText.includes('נקודת שחזור נשמרה') && sheetText.includes('מהתבנית המובנית') && sheetText.includes('1496 מילים'), 'אחרי ההסבה מוצג הדוח: נקודת שחזור, מה נלקח מאיפה, מה השתנה בדרך');
    await page.keyboard.press('Escape');
    const tree = Object.fromEntries((await call('pf_tree')).files.map(f => [f.path, f.id]));
    ok(['index.html', 'css/base.css', 'css/theme-style.css', 'css/lexicon.css', 'css/tokens.css', 'css/page.css', 'js/kol-data.js', 'js/page.js', 'js/lexicon.js', 'kol.json', 'from-current/lexicon.html'].every(p => tree[p]), 'הפריסה של 02 §2 בעץ הקבצים');
    const indexSrc = (await call('pf_read', null, `&id=${tree['index.html']}`)).content;
    ok(!indexSrc.includes('{{') && indexSrc.startsWith('<!doctype html>'), 'index.html: HTML מלא, בלי Mustache', indexSrc.length + ' תווים');
    const pageCss = (await call('pf_read', null, `&id=${tree['css/page.css']}`)).content;
    ok(!pageCss.includes('!important'), 'css/page.css: בלי !important');
    // כל קובץ נפתח בעורך הקוד
    let opened = 0;
    for (const p of ['index.html', 'css/page.css', 'js/kol-data.js', 'kol.json']) { await page.evaluate(id => Project._open(id), tree[p]); await page.waitForFunction(id => Project._state.docs.get(id)?.model, tree[p]); opened++; }
    ok(opened === 4, 'הקבצים נפתחים בעורך הקוד');
    // ▶️ תצוגה חיה על index.html
    await page.evaluate(id => Project._open(id), tree['index.html']);
    await page.click('.pj-bar .pj-live-btn');
    let pv = null;
    for (let i = 0; i < 200 && !pv; i++) { pv = page.frames().find(f => f.url().includes('/preview/')); if (!pv) await sleep(50); }
    await pv.waitForSelector('#kadisha-lex-grid[data-kol-ready]', { state: 'attached', timeout: 30000 });
    const live = await pv.evaluate(() => {
      const cs = (s, p) => { const e = document.querySelector(s); return e ? getComputedStyle(e)[p] : null; };
      return { h1: document.querySelector('h1')?.textContent, heads: [...document.querySelectorAll('.kadisha-hero-inner > h1, .kadisha-hero-inner > p, .kadisha-hero-eyebrow-line')].map(e => e.textContent), bg: cs('.kadisha-hero', 'backgroundImage'), size: cs('.kadisha-hero-title', 'fontSize'),
        cards: document.querySelectorAll('.kadisha-word-card').length, plays: document.querySelectorAll('.kadisha-word-audio-btn').length, topics: document.querySelectorAll('.kadisha-lex-topic-btn').length, text: document.body.innerText.slice(0, 20000), origin: self.origin, sheets: document.styleSheets.length };
    });
    ok(live.h1 === 'קול מרוקו' && live.heads.length === 6, '"▶️ תצוגה חיה" על index.html: שש הכותרות', live.heads.join(' · '));
    ok(/hero\.jpg/.test(live.bg), '…תמונת הרקע', live.bg);
    ok(live.size === '60px' && live.sheets >= 5, '…העיצוב (גודל הכותרת מהפאנל: ' + live.size + ', ' + live.sheets + ' גיליונות)');
    ok(live.cards === 1496 && live.plays === 1494 && live.topics === 7, `…המילים: ${live.cards} כרטיסים, ${live.plays} כפתורי השמעה, ${live.topics - 1} נושאים + "הכל"`);
    ok(!live.text.includes('{{') && !live.text.includes('}}'), '…ואין {{…}} בעמוד');
    ok(live.origin === 'null', 'העמוד רץ במקור אטום (origin = null), וה-fetch היחסי ל-data/words.json עובד', live.origin);
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'f3-live-1280.png') });
    await page.evaluate(() => document.querySelector('.pj-live')?.remove());

    // ════ ג. רתמת הנאמנות ════
    group = 'נאמנות';
    const tok = (await call('pv_token')).token, NEW = `${base}/preview/${tok}/`;
    const oldUrl = 'http://old.test/';
    await ctx.route(oldUrl + '**', r => r.fulfill({ contentType: 'text/html; charset=utf-8', body: OLD }));
    const A = await ctx.newPage(), B = await ctx.newPage();       // A — הישן, B — המוסב
    const errB = []; B.on('pageerror', e => errB.push(e.message)); B.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errB.push(m.text()); });
    const fake = () => { window.__aud = []; window.Audio = function (src) { const a = { src, paused: true, play() { a.paused = false; return Promise.resolve(); }, pause() { a.paused = true; }, addEventListener() {} }; window.__aud.push(a); return a; }; };
    await B.addInitScript(fake);
    const openBoth = async (w, h) => {
      await A.setViewportSize({ width: w, height: h }); await B.setViewportSize({ width: w, height: h });
      await A.goto(oldUrl); await B.goto(NEW);
      await B.waitForSelector('#kadisha-lex-grid[data-kol-ready]', { state: 'attached', timeout: 30000 });
    };
    const diff = (a, b) => page.evaluate(async ([x, y]) => {
      const img = s => new Promise(r => { const i = new Image(); i.onload = () => r(i); i.src = 'data:image/png;base64,' + s; });
      const [p, q] = [await img(x), await img(y)], w = Math.max(p.width, q.width), h = Math.max(p.height, q.height);
      const px = i => { const c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, w, h); g.drawImage(i, 0, 0); return g.getImageData(0, 0, w, h).data; };
      const d1 = px(p), d2 = px(q); let n = 0, first = -1;
      for (let i = 0; i < d1.length; i += 4) if (Math.abs(d1[i] - d2[i]) + Math.abs(d1[i + 1] - d2[i + 1]) + Math.abs(d1[i + 2] - d2[i + 2]) > 24) { n++; if (first < 0) first = Math.floor(i / 4 / w); }
      return { ratio: n / (w * h), first, sameSize: p.width === q.width && p.height === q.height };
    }, [a, b]);
    const metrics = p => p.evaluate(() => {
      const cs = (s, ps) => { const e = document.querySelector(s); if (!e) return null; const c = getComputedStyle(e), r = e.getBoundingClientRect(); return ps.map(k => c[k]).join('|') + '|' + Math.round(r.top + scrollY) + '|' + Math.round(r.height) + '|' + Math.round(r.width); };
      const P = ['fontSize', 'fontWeight', 'lineHeight', 'marginTop', 'marginBottom', 'color', 'fontFamily'];
      return { title: cs('.kadisha-hero-title', P), main: cs('.kadisha-hero-subtitle-main', P), eyebrow: cs('.kadisha-hero-eyebrow--under', P), sub: cs('.kadisha-hero-subtitle', P), credit: cs('.kadisha-hero-credit', P),
        hero: cs('.kadisha-hero', ['minHeight', 'backgroundImage', 'display', 'paddingTop', 'paddingBottom']), controls: cs('.kadisha-lexicon-controls', ['paddingTop']), grid: cs('#kadisha-lex-grid', ['display', 'gridTemplateColumns', 'gap']),
        card1: cs('.kadisha-word-card', ['paddingTop', 'backgroundColor', 'borderRadius']), card9: cs('.kadisha-word-card:nth-of-type(9)', ['paddingTop']), docH: document.documentElement.scrollHeight };
    });
    for (const [w, h] of [[390, 844], [768, 1024], [1280, 800], [1920, 1080]]) {
      await openBoth(w, h);
      const [ma, mb] = [await metrics(A), await metrics(B)];
      const bad = Object.keys(ma).filter(k => ma[k] !== mb[k]);
      const clip = { x: 0, y: 0, width: w, height: Math.min(3000, ma.docH, mb.docH) };
      const [sa, sb] = [await A.screenshot({ clip, fullPage: true }), await B.screenshot({ clip, fullPage: true })];
      if (SHOTS) { fs.writeFileSync(path.join(SHOTS, `f3-${w}-old.png`), sa); fs.writeFileSync(path.join(SHOTS, `f3-${w}-new.png`), sb); }
      const d = await diff(sa.toString('base64'), sb.toString('base64'));
      report.visual.push({ width: w, ratio: d.ratio, firstRow: d.first, clipHeight: clip.height, docOld: ma.docH, docNew: mb.docH, styleDiffs: bad.map(k => `${k}: ${ma[k]} ≠ ${mb[k]}`) });
      ok(d.ratio <= 0.005 && d.sameSize, `מראה ב-${w}px: הפרש ${(d.ratio * 100).toFixed(3)}% מהפיקסלים (עד 0.5%), ${clip.height}px העליונים`, d.first >= 0 ? 'ההפרש הראשון בשורה ' + d.first : '');
      ok(bad.length === 0, `סגנון מחושב ומיקום של 11 אלמנטים ב-${w}px — זהים לרינדור הישן (גובה העמוד: ${ma.docH} מול ${mb.docH})`, bad.map(k => `${k}: ${ma[k]} ≠ ${mb[k]}`).join(' ; ').slice(0, 400));
    }
    // מספרים וסדר (1280)
    await openBoth(1280, 800);
    const listOf = p => p.evaluate(() => [...document.querySelectorAll('.kadisha-word-card')].map(c => [c.querySelector('.kadisha-word-title').textContent, c.querySelector('.kadisha-word-meaning').innerHTML, [...c.querySelectorAll('.kadisha-word-topic')].map(t => t.textContent).join(','), c.querySelector('.kadisha-word-topics')?.className || '', c.querySelector('.kadisha-word-audio-btn')?.getAttribute('data-audio-src') || '', c.querySelector('.kadisha-word-audio-btn')?.getAttribute('aria-label') || '', c.querySelector('.kadisha-word-feedback-btn') ? 'fb' : '', c.querySelector('.kadisha-word-extid')?.textContent || ''].join('¦')));
    const [la, lb] = [await listOf(A), await listOf(B)];
    report.counts = { words: lb.length, plays: lb.filter(x => x.split('¦')[4]).length };
    ok(la.length === 1496 && lb.length === 1496, '1,496 מילים', `${la.length} / ${lb.length}`);
    ok(lb.filter(x => x.split('¦')[4]).length === 1494, '1,494 כפתורי השמעה');
    const firstBad = la.findIndex((x, i) => x !== lb[i]);
    ok(firstBad === -1, 'הרשימה כולה זהה, כרטיס מול כרטיס ובאותו סדר: מילה, פירוש (HTML), נושאים, is-single, כתובת ההקלטה, תווית הנגישות, כפתור המשוב', firstBad >= 0 ? `כרטיס ${firstBad}: ${la[firstBad].split('¦').map((x, i) => x === lb[firstBad].split('¦')[i] ? '=' : x.slice(0, 120) + ' ≠ ' + lb[firstBad].split('¦')[i].slice(0, 120)).join(' ¦ ')}` : '');
    const topicsOf = p => p.evaluate(() => [...document.querySelectorAll('.kadisha-lex-topic-btn')].map(b => b.textContent.replace(/\s+/g, ' ').trim() + '/' + (b.getAttribute('data-topic') || '')));
    const [ta, tb] = [await topicsOf(A), await topicsOf(B)];
    ok(JSON.stringify(ta) === JSON.stringify(tb), 'אותם נושאים, אותו סדר, אותם מונים', tb.join(' | '));
    const txt = (p, s) => p.evaluate(q => document.querySelector(q)?.textContent.replace(/\s+/g, ' ').trim() ?? null, s);
    ok(await txt(A, '.kadisha-lexicon-count') === await txt(B, '.kadisha-lexicon-count'), 'המונה: אותו טקסט', await txt(B, '.kadisha-lexicon-count'));
    // חיפוש וסינון: המודל של התבנית הישנה (data-search / data-search-full / data-topics) מול kol-data.js
    const ref = await A.evaluate(() => [...document.querySelectorAll('.kadisha-word-card')].map(c => ({ w: c.querySelector('.kadisha-word-title').textContent, s: c.dataset.search, f: c.dataset.searchFull, t: (c.dataset.topics || '').split(' ').filter(Boolean) })));
    const shown = () => B.evaluate(() => [...document.querySelectorAll('.kadisha-word-card')].map(c => c.querySelector('.kadisha-word-title').textContent));
    const ready = () => B.waitForSelector('#kadisha-lex-grid[data-kol-ready]', { state: 'attached' });
    const search = async (q, full) => { await B.setChecked('#kadisha-lex-scope-full', !!full); await B.fill('#kadisha-lex-search', q); await ready(); return shown(); };
    const QUERIES = [['', 0], ['כסכסו', 0], ['כסכ', 0], ['מימונה 12', 0], ['בְּסְלָאמָה', 0], ['בסלאמה', 0], ['ZOHRA', 1], ['zohra', 1], ['bslama', 1], ['k&sksu', 1], ["s'ouda", 1], ['البركة', 1], ['حبيبي', 1], ['פתגמים', 1], ['בית הכנסת', 1], ['נאמר בכניסה', 1], ['1496', 0], ['  שבת   7 ', 0], ['אין-מילה-כזאת', 1], ['פירוש המילה', 0]];
    let qBad = [];
    for (const [q, full] of QUERIES) {
      const want = ref.filter(r => (full ? r.f : r.s).includes(q.trim().toLowerCase().replace(/\s+/g, ' '))).map(r => r.w), got = await search(q, full);
      if (JSON.stringify(want) !== JSON.stringify(got)) qBad.push(`"${q}"${full ? ' (מורחב)' : ''}: ${want.length} ≠ ${got.length}`);
      if (q === 'אין-מילה-כזאת') ok(await B.isVisible('#kadisha-lex-no-matches') && await txt(B, '#kadisha-lex-shown') === '0', 'בלי תוצאות: ההודעה מוצגת, והמונה 0');
    }
    ok(qBad.length === 0, '20 שאילתות חיפוש (עברית, ניקוד, ערבית, לטינית, חלקית, ריקה, רווחים, בלי תוצאות; רגיל ומורחב) — אותן תוצאות, אותו סדר', qBad.join(' ; '));
    await search('', 0);
    let fBad = [];
    const slugs = tb.slice(1).map(x => x.split('/')[1]);
    for (let i = 0; i < slugs.length; i++) {
      await B.click(`.kadisha-lex-topic-btn >> nth=${i + 1}`); await ready();
      const want = ref.filter(r => r.t.includes(slugs[i])).map(r => r.w), got = await shown();
      if (JSON.stringify(want) !== JSON.stringify(got)) fBad.push(`${slugs[i]}: ${want.length} ≠ ${got.length}`);
    }
    ok(fBad.length === 0 && slugs.length === 6, 'סינון: כל נושא — אותן מילים, אותו סדר', fBad.join(' ; '));
    const cq = ['zohra', 'mliha', 'bslama', 'lbaraka'].find(q => ref.some(r => r.t.includes(slugs[5]) && r.f.includes(q)));
    const combo = await search(cq, 1), wantCombo = ref.filter(r => r.t.includes(slugs[5]) && r.f.includes(cq)).map(r => r.w);
    ok(JSON.stringify(combo) === JSON.stringify(wantCombo) && combo.length > 0, 'נושא + חיפוש מורחב יחד', combo.length + ' תוצאות (צפוי ' + wantCombo.length + ')');
    ok(await B.$eval('.kadisha-lex-topic-btn >> nth=6', b => b.classList.contains('is-active')) && !(await B.$eval('.kadisha-lex-topic-btn', b => b.classList.contains('is-active'))), 'הנושא הפעיל מסומן is-active, ו"הכל" לא');
    await B.click('.kadisha-lex-topic-btn >> nth=0'); await search('', 0);
    // השמעה
    await B.click('.kadisha-word-card:nth-of-type(1) .kadisha-word-audio-btn'); await B.click('.kadisha-word-card:nth-of-type(2) .kadisha-word-audio-btn');
    const au = await B.evaluate(() => window.__aud.map(a => (a.paused ? 'p' : 'on')));
    ok(au.join(' ') === 'p on' && await B.$eval('.kadisha-word-card:nth-of-type(2) .kadisha-word-audio-btn', b => b.classList.contains('is-playing')), 'השמעה: מתנגן; הקלטה שנייה עוצרת את הראשונה');
    ok(lb.filter(x => !x.split('¦')[4]).length === 2, 'מילה בלי הקלטה — בלי כפתור (2 מילים)');
    // המבוא
    await B.click('.kadisha-lex-hero-btn >> nth=0');
    const g1 = await B.evaluate(() => [...document.querySelectorAll('.kadisha-lex-section-panel')].map(p => p.hidden).join());
    await B.click('.kadisha-lexicon-intro-toggle');
    const g2 = await B.evaluate(() => [[...document.querySelectorAll('.kadisha-lex-section-panel')].map(p => p.hidden).join(), document.querySelector('.kadisha-lexicon-intro-toggle').textContent.trim(), document.querySelector('.kadisha-lexicon-intro-toggle').getAttribute('aria-expanded')]);
    await B.click('.kadisha-lexicon-intro-toggle'); await B.click('.kadisha-lex-hero-btn >> nth=0');
    const g3 = await B.evaluate(() => [[...document.querySelectorAll('.kadisha-lex-section-panel')].map(p => p.hidden).join(), document.querySelector('.kadisha-lexicon-intro-toggle').textContent.trim()]);
    ok(g1 === 'false,true' && g2[0] === 'false,false' && g2[1].startsWith('הסתר') && g2[2] === 'true' && g3[0] === 'true,true' && g3[1].startsWith('קרא עוד'), 'המבוא: שני הכפתורים פותחים וסוגרים; "קרא עוד" / "הסתר"', [g1, g2.join('/'), g3.join('/')].join(' → '));
    // המשוב
    siteReq.length = 0;
    await B.click('.kadisha-word-card:nth-of-type(1) .kadisha-word-feedback-btn');
    const fbWord = await txt(B, '#kadisha-lex-feedback-word-name');
    await B.fill('#kadisha-lex-feedback-message', 'בדיקה'); await B.click('#kadisha-lex-feedback-send');
    const fbStatus = await txt(B, '#kadisha-lex-feedback-status');
    ok(await B.isVisible('#kadisha-lex-feedback-modal') && fbWord === lb[0].split('¦')[0] && fbStatus.includes('תיבת הפניות') && siteReq.length === 0, 'המשוב: נפתח מכרטיס עם המילה; מציג "יישלח לתיבת הפניות"; אינו שולח דבר לאתר', fbStatus);
    await B.keyboard.press('Escape');
    ok(!(await B.isVisible('#kadisha-lex-feedback-modal')) && await B.evaluate(() => document.activeElement.classList.contains('kadisha-word-feedback-btn')), 'מקלדת: Escape סוגר את החלון, והמיקוד חוזר לכפתור');
    // סדר Tab: מול הרינדור הישן (האלמנטים שמקבלים מיקוד, לפי הסדר; 25 הראשונים)
    const tabs = p => p.evaluate(() => [...document.querySelectorAll('a[href], button, input, textarea, select, [tabindex]')].filter(e => e.tabIndex >= 0 && e.offsetParent !== null).slice(0, 25).map(e => e.tagName + '.' + (e.className || e.id)).join(' '));
    await A.reload(); await B.reload(); await ready();
    ok((await tabs(A)).replace(/ is-active/g, '') === (await tabs(B)).replace(/ is-active/g, ''), 'מקלדת: אותו סדר Tab כמו ברינדור הישן (25 האלמנטים הראשונים)');
    ok(errB.length === 0, 'בלי שגיאות במסוף של העמוד המוסב', errB.slice(0, 3).join(' | '));
    // מהירות: טלפון, מעבד מואט פי 4
    {
      const P = await ctx.newPage(); await P.setViewportSize({ width: 390, height: 844 });
      const cdp = await ctx.newCDPSession(P); await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
      const t0 = Date.now(); await P.goto(NEW);
      await P.waitForSelector('.kadisha-word-card'); const tFirst = Date.now() - t0;
      await P.waitForSelector('#kadisha-lex-grid[data-kol-ready]', { state: 'attached' }); const tAll = Date.now() - t0;
      const tSearch = await P.evaluate(async () => { const i = document.querySelector('#kadisha-lex-search'); const t = performance.now(); i.value = 'זוהרה'; i.dispatchEvent(new Event('input', { bubbles: true })); const first = performance.now() - t; await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))); return [Math.round(first), Math.round(performance.now() - t)]; });
      const longest = await P.evaluate(() => new Promise(r => { let max = 0; new PerformanceObserver(l => { for (const e of l.getEntries()) max = Math.max(max, e.duration); }).observe({ type: 'longtask', buffered: true }); setTimeout(() => r(Math.round(max)), 300); }));
      report.perf = { firstCardMs: tFirst, allCardsMs: tAll, searchHandlerMs: tSearch[0], searchToPaintMs: tSearch[1], longestTaskMs: longest, note: '390px, מעבד מואט פי 4 (CDP), שרת מקומי' };
      ok(tFirst < 5000 && tSearch[1] < 1000, `מהירות (390px, מעבד ×4 איטי): כרטיס ראשון ${tFirst}ms, כל 1,496 ${tAll}ms; חיפוש — ${tSearch[0]}ms בטיפול, ${tSearch[1]}ms עד ציור; המשימה הארוכה ביותר ${longest}ms`);
      await P.close();
    }

    // ════ ד. העורך החזותי על index.html המוסב ════
    group = 'העורך החזותי';
    await page.goto(base + '/editor.php#/editor/' + encodeURIComponent('index.html')); await page.reload();   // העורך הוא מסמך משלו (V1b); index.html#/editor מפנה אליו — נבדק ב-v1b.cjs
    await page.waitForFunction(() => Editor._state.file && Editor._state.file.path === 'index.html' && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, null, { timeout: 30000 });
    const ed = await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc, all = K.all(c.m); c.draw(); return { marks: c.m.marks, broken: all.filter(n => n.broken || n.hasBroken).map(n => c.label(n)), total: all.length, strip: !document.querySelector('p.ed-strip.warn').hidden, boxes: document.querySelectorAll('.ed-mark-broken').length, visible: all.filter(n => !n.implied && c.rectOf(n) && !c.rectOf(n).zero).length }; });
    report.editor = ed;
    ok(ed.marks === 0 && ed.broken.length === 0 && !ed.strip && ed.boxes === 0, `0 אלמנטים "אזור שבור" ב-index.html המוסב (מתוך ${ed.total} אלמנטים במודל)`, ed.broken.slice(0, 5).join(', '));
    const sweep = await page.evaluate(async () => {
      const c = Editor._state.canvas, K = window.KolSrc, wait = ms => new Promise(r => setTimeout(r, ms));
      const src0 = c.src, bad = [], skipped = [];
      const vis = n => !n.implied && c.rectOf(n) && !c.rectOf(n).zero;
      const total = K.all(c.m).filter(vis).length;
      let done = 0;
      for (let i = 0; i < total; i++) {
        const n = K.all(Editor._state.canvas.m).filter(vis)[i];
        if (!n) break;
        const label = Editor._state.canvas.label(n);
        if (n.tag === 'html' || n.tag === 'body' || n.tag === 'head') { skipped.push(label); continue; }
        Editor._state.canvas.select(n);
        if (Editor._state.canvas.selected !== n) { bad.push('לא נבחר: ' + label); continue; }
        Editor._remove(); await wait(15);
        const after = Editor._state.canvas.src;
        if (after === src0 || after.length >= src0.length) { bad.push('לא נמחק: ' + label + ' — ' + (document.querySelector('.ed-note')?.textContent || '')); continue; }
        Editor._undo();
        for (let k = 0; k < 80 && (Editor._state.canvas.src !== src0 || K.all(Editor._state.canvas.m).filter(vis).length !== total); k++) await wait(25);
        if (Editor._state.canvas.src !== src0) { bad.push('הביטול לא החזיר: ' + label); break; }
        done++;
      }
      return { total, done, bad, skipped };
    });
    report.editor.sweep = { total: sweep.total, done: sweep.done, skipped: sweep.skipped, bad: sweep.bad };
    ok(sweep.bad.length === 0 && sweep.done + sweep.skipped.length === sweep.total && sweep.done >= 30, `כל אלמנט שנראה בקנבס נבחר, נמחק, והביטול מחזיר קובץ זהה: ${sweep.done} אלמנטים (${sweep.skipped.join(', ')} — אינם נמחקים מעצם טיבם)`, sweep.bad.slice(0, 4).join(' ; ') + ` [total ${sweep.total}, done ${sweep.done}]`);
    const tpl = await page.evaluate(() => { const c = Editor._state.canvas, K = window.KolSrc, all = K.all(c.m); const inTpl = n => { for (let p = n.parent; p; p = p.parent) if (p.tag === 'template') return true; return false; }; const t = all.filter(inTpl); return { n: t.length, visible: t.filter(n => c.rectOf(n) && !c.rectOf(n).zero).length, cards: c.doc.querySelectorAll('.kadisha-word-card').length }; });
    report.editor.template = tpl;
    console.log(`ℹ️  בתוך <template>: ${tpl.n} אלמנטים במודל, ${tpl.visible} נראים בקנבס; כרטיסים בקנבס: ${tpl.cards} (הקנבס אינו מריץ סקריפטים — הצגת הרשימה בקנבס היא של פרוסות V)`);
    ok(errors.length === 0, 'אין שגיאות JavaScript במסכי הכלי', errors.slice(0, 3).join(' | '));
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'f3-editor-1280.png') });
  } catch (e) {
    failed++; ran++; rows.push([group, 'הבדיקה נעצרה', false, e.message]);
    console.log('❌ הבדיקה נעצרה: ' + e.message.split('\n')[0]);
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  if (REPORT) fs.writeFileSync(REPORT, JSON.stringify({ ...report, rows, failed, ran }, null, 1));
  console.log('\n' + (failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`));
  process.exit(failed ? 1 : 0);
})();
