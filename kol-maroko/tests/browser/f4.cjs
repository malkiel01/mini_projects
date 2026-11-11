/**
 * פרוסה F4 — התצוגה החיה המבודדת, בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/f4.cjs
 *
 * שלושה חלקים: (א) המסגרת בסטודיו — מצבי מכשיר, מסוף, כלי בדיקה, רענון אחרי שמירה, לולאה אינסופית;
 * (ב) ערכת ההתקפה מול הסטודיו — כשהבודק מחובר כבעלים, עם ביקורת חיובית לכל בדיקה (אותה בדיקה, במקור של הסטודיו,
 * מצליחה לגעת — כלומר היא מסוגלת להיכשל), ומול מה שהשרת ראה בפועל; (ג) C1 שלב ב — המדיניות המלאה על live.php.
 *
 * הזהות כאן בעוגייה (HttpOnly, SameSite=Lax) — כמו העוגייה של הפלטפורמה — כדי שהדפדפן עצמו יחליט מתי היא נשלחת.
 * רץ מול tests/router.php ומסד זמני; data/ האמיתית לא נפתחת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.KOL_SHOTS || '';
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const until = async (fn, ms = 8000) => { const t = Date.now(); for (;;) { try { const v = await fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await sleep(100); } };

const PAGE = `<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<title>לפני</title>
<link rel="stylesheet" href="s.css">
</head>
<body>
<p id="hello" class="a b">שלום</p>
<img src="missing.png" alt="">
<div style="height:3000px">גבוה</div>
<script>document.title='ok'</script>
<script>console.log('<img src=x onerror="top.pwned=1;window.pwned=1">');</script>
<script>try { localStorage.setItem('a', '1'); } catch (e) { console.warn('אחסון: ' + e.name); }</script>
<script>fetch('data.json').then(r => r.json()).then(j => console.info('json ' + j.n)); fetch('../data/words.json').then(r => r.json()).then(j => console.info('words ' + Array.isArray(j.items)));</script>
<script src="app.js"></script>
</body>
</html>
`;
const APP_JS = `// שורה 1
const made = document.createElement('div');
made.className = 'made'; made.textContent = 'נוצר ב-JS'; made.style.cssText = 'position:fixed;top:60px;left:10px;padding:8px;background:#eee';
document.body.append(made);
window.__mark = 1;
throw new Error('בום בשורה 6');
`;

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-f4-'));
  const trace = path.join(data, 'trace.jsonl');
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, KOL_TEST_TRACE: trace, PHP_CLI_SERVER_WORKERS: '6' }, stdio: 'ignore' });
  const seen = () => fs.existsSync(trace) ? fs.readFileSync(trace, 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)) : [];
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    // --site-per-process: בידוד תהליכים כמו בכרום שולחני. בלעדיו (כמו ב-Chromium של הבדיקות כברירת מחדל) מסגרת תקועה תוקעת גם את ההורה
    browser = await chromium.launch({ args: ['--site-per-process'] });
    const mk = async (width, height) => {
      const ctx = await browser.newContext({ viewport: { width, height }, acceptDownloads: true });
      await ctx.addCookies([{ name: 'kol_test_user', value: Buffer.from(JSON.stringify(OWNER)).toString('base64'), url: base, httpOnly: true, sameSite: 'Lax' }]);
      return ctx;
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const liveOf = page => page.frames().find(f => f.url().includes('/live.php'));
    const pvOf = page => page.frames().find(f => f.url().includes('/preview/'));
    const lines = live => live.$$eval('.lv-line', els => els.map(e => ({ level: e.className.replace('lv-line ', ''), text: e.querySelector('.lv-text').textContent, src: e.querySelector('.lv-src')?.textContent || '' })));

    for (const [W, H] of [[1280, 800], [390, 780]]) {
      console.log(`\n── ${W}px ──`);
      const ctx = await mk(W, H), page = await ctx.newPage();
      const [C0, C1] = W === 1280 ? ['rgb(255, 0, 0)', 'rgb(0, 0, 255)'] : ['rgb(0, 0, 255)', 'rgb(0, 128, 0)'];
      const errors = [];
      page.on('pageerror', e => { if (e.message !== 'Canceled' && !e.message.includes('בום')) errors.push(e.message); });   // "בום" — השגיאה המכוונת של העמוד שבמסגרת;     // "Canceled" — ביטול פנימי של Monaco בהחלפת קובץ
      await page.goto(base + '/#/project');
      await page.waitForSelector('.pj-tree .pj-row', { state: 'attached' });
      if (W === 1280) {
        for (const [p, c] of [['t/page.html', PAGE], ['t/s.css', '#hello { color: rgb(255, 0, 0); }\n'], ['t/app.js', APP_JS], ['t/data.json', '{"n": 7}']]) await call(page, 'pf_create', { path: p, content: c });
        await page.reload(); await page.waitForSelector('.pj-tree .pj-row', { state: 'attached' });
      }
      const ids = Object.fromEntries((await call(page, 'pf_tree')).files.map(f => [f.path, f.id]));
      await page.evaluate(async id => { await Project._open(id); }, ids['t/s.css']);
      await page.evaluate(async id => { await Project._open(id); }, ids['t/page.html']);
      await page.waitForSelector('.pj-tab.on', { state: 'attached' });

      // ── המסגרת ──
      ok(await page.$('.pj-side-btns [data-live]') !== null && await page.$('.pj-bar .pj-live-btn') !== null, 'כפתור "▶️ תצוגה חיה" במנהל הקבצים ובעורך הקוד');
      await page.click('.pj-bar .pj-live-btn');
      const live = await until(() => liveOf(page));
      await live.waitForSelector('.lv-iframe');
      const attrs = await live.$eval('.lv-iframe', f => ({ sandbox: f.getAttribute('sandbox'), src: f.src, srcdoc: f.hasAttribute('srcdoc') }));
      ok(/\ballow-scripts\b/.test(attrs.sandbox) && !/allow-same-origin|allow-top-navigation/.test(attrs.sandbox), 'המסגרת: sandbox עם allow-scripts, בלי allow-same-origin ובלי allow-top-navigation');
      ok(attrs.src.startsWith(base + '/preview/') && attrs.src.endsWith('/t/page.html') && !attrs.srcdoc, 'נטענת מהכתובת — לא srcdoc');
      await until(async () => (await live.textContent('#lv-title')) === 'ok');
      ok(await live.textContent('#lv-title') === 'ok' && await page.title() === 'קול מרוקו' && (await live.title()).startsWith('תצוגה חיה'), 'ה-JS של העמוד רץ (document.title="ok") — בתוך המסגרת בלבד');
      const pv = pvOf(page);
      ok(await pv.evaluate(() => getComputedStyle(document.getElementById('hello')).color) === C0, 'הפניה יחסית (s.css) עובדת בלי שינוי');

      // ── מסוף ──
      await until(async () => (await lines(live)).length >= 6);
      const L = await lines(live);
      const xss = L.find(l => l.text.includes('<img src=x onerror'));
      ok(xss && await live.$('.lv-console img') === null && await page.evaluate(() => window.pwned) === undefined && await live.evaluate(() => window.pwned) === undefined,
        'console.log(\'<img src=x onerror=…>\') — מוצג במסוף כטקסט, ואינו רץ');
      const boom = L.find(l => l.level === 'error' && l.text.includes('בום'));
      ok(boom && boom.src === 't/app.js:6', 'שגיאת JS עם קובץ ושורה (t/app.js:6)' + (boom ? '' : ' · ' + JSON.stringify(L.map(l => l.text.slice(0, 30)))));
      ok(L.some(l => l.level === 'error' && l.text.includes('המשאב לא נטען') && l.text.includes('t/missing.png')), 'משאב שנכשל מופיע במסוף');
      ok(L.some(l => l.text === 'json 7') && L.some(l => l.text === 'words true'), 'fetch יחסי ל-JSON של העמוד ול-data/words.json עובד במקור אטום (I4)');
      const xl = L.find(l => l.text.includes('<img src=x'));
      ok(xl && xl.src === 't/page.html:13', 'console.log מסקריפט בתוך הדף — עם השורה בקובץ (t/page.html:13)' + (xl ? ' ← ' + xl.src : ''));
      ok(await live.isVisible('#lv-limits') && (await live.textContent('#lv-limits')).includes('אחסון בדפדפן') && (await live.textContent('#lv-limits')).includes('ש-2'),
        'סרגל קבוע: אחסון בדפדפן לא יעבוד בארגז החול — מזוהה מהגישה בפועל');
      if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `f4-live-${W}.png`) });

      // ── מצבי מכשיר, רוחב חופשי, לשונית חדשה ──
      const segs = await live.$$eval('#lv-seg button', b => b.map(x => x.textContent));
      ok(segs.length === 3 && segs[1].includes('820') && segs[2].includes('390'), 'מצבי מכשיר לפי kol.json: ' + segs.join(' | '));
      await live.click('#lv-seg button:nth-child(3)');
      ok(await live.$eval('#lv-frame', e => Math.round(e.getBoundingClientRect().width)) === 390 && await pv.evaluate(() => innerWidth) === 390, 'מצב "טלפון": המסגרת ברוחב 390');
      if (W === 1280) {
        const g = await (await live.$('#lv-grip')).boundingBox();
        await page.mouse.move(g.x + g.width / 2, g.y + g.height / 2); await page.mouse.down(); await page.mouse.move(g.x + g.width / 2 + 60, g.y + g.height / 2, { steps: 4 }); await page.mouse.up();
        ok(await live.$eval('#lv-frame', e => Math.round(e.getBoundingClientRect().width)) === 510, 'רוחב חופשי בגרירה (390 → 510)');
      }
      await live.click('#lv-seg button:nth-child(1)');
      ok(await live.$eval('#lv-tab', a => a.href) === attrs.src && await live.$eval('#lv-tab', a => a.target) === '_blank', '"פתח בלשונית חדשה" — אותה כתובת, עם האסימון');

      // ── כלי הבדיקה ──
      await live.click('#lv-pick');
      await sleep(200);
      await pv.click('#hello');
      await until(async () => (await live.textContent('#lv-inspect')).includes('hello'));
      const insp = await live.textContent('#lv-inspect');
      ok(insp.includes('<p#hello.a.b>') && insp.includes('t/page.html:9'), 'כלי הבדיקה: תגית, מחלקות, והקובץ והשורה (t/page.html:9)');
      await pv.click('.made');
      await until(async () => (await live.textContent('#lv-inspect')).includes('made'));
      ok((await live.textContent('#lv-inspect')).includes('נוצר בזמן ריצה'), 'אלמנט שנוצר ב-JS: "נוצר בזמן ריצה", בלי קובץ');
      await live.click('#lv-pick');

      // ── רענון אחרי שמירה ──
      await pv.evaluate(() => { window.__mark = 2; scrollTo(0, 400); });
      await sleep(400);
      await page.evaluate(([id, c]) => { const d = Project._state.docs.get(id); d.model.setValue('#hello { color: ' + c + '; }\n'); return Project._save(id); }, [ids['t/s.css'], C1]);
      await until(() => pv.evaluate(c => getComputedStyle(document.getElementById('hello')).color === c, C1));
      ok(await pv.evaluate(c => getComputedStyle(document.getElementById('hello')).color === c && window.__mark === 2 && scrollY === 400, C1), 'שמירת CSS: הסגנון מתעדכן בלי טעינה מחדש');
      await page.evaluate(([id, c]) => { const d = Project._state.docs.get(id); d.model.setValue(c); return Project._save(id); }, [ids['t/page.html'], PAGE.replace('שלום', 'שלום שני')]);
      await until(() => pvOf(page).evaluate(() => document.getElementById('hello').textContent === 'שלום שני' && window.__mark === 1 && scrollY === 400));
      ok(await pvOf(page).evaluate(() => document.getElementById('hello').textContent === 'שלום שני' && window.__mark === 1 && scrollY === 400), 'שמירת HTML: טעינה מחדש, ומיקום הגלילה נשמר');
      await page.evaluate(([id, c]) => { const d = Project._state.docs.get(id); d.model.setValue(c); return Project._save(id); }, [ids['t/page.html'], PAGE]);
      await sleep(500);

      // ── "פתח בקוד" ──
      await until(async () => (await lines(live)).some(l => l.src === 't/app.js:6'));
      await live.click('#lv-t-console');
      await live.locator('.lv-src', { hasText: 't/app.js:6' }).last().click({ timeout: 8000 });
      await until(() => page.evaluate(id => Project._state.cur === id && !document.querySelector('.pj-live') && Project._state.editor.getPosition().lineNumber === 6, ids['t/app.js']));
      ok(await page.evaluate(id => Project._state.cur === id && !document.querySelector('.pj-live') && Project._state.editor.getPosition().lineNumber === 6, ids['t/app.js']), '"פתח בקוד" מהמסוף: הקובץ נפתח בעורך, בשורה 6');
      ok(errors.length === 0, 'בלי שגיאות JS בסטודיו' + (errors.length ? ' · ' + errors[0] : ''));
      await ctx.close();
    }
  } catch (e) {
    failed++;
    console.log('❌ הבדיקה נעצרה: ' + e.message.split('\n')[0]);
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
