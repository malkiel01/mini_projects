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

    // ══ ערכת ההתקפה מול הסטודיו — הבודק מחובר כבעלים ══
    console.log('\n── ערכת ההתקפה ──');
    const ctx = await mk(1280, 900), page = await ctx.newPage();
    await page.goto(base + '/#/dev/health');
    await page.waitForSelector('#kit-run');
    const runKit = async () => {
      await page.click('#kit-run');
      await page.waitForFunction(() => !document.getElementById('kit-run').disabled && document.querySelectorAll('#kit-out li').length > 0, null, { timeout: 40000 });
      return page.$$eval('#kit-out li', els => els.map(e => ({ ok: e.classList.contains('ok'), name: e.querySelector('b')?.textContent || '', text: e.querySelector('p')?.textContent || '' })));
    };

    // ביקורת חיובית לערכה כולה: מסירים את הבידוד (בלי כותרת ארגז החול, ועם allow-same-origin) — הערכה חייבת להיכשל
    const weakA = async route => { const r = await route.fetch(); const h = { ...r.headers() }; delete h['content-security-policy']; await route.fulfill({ response: r, headers: h }); };
    const weakB = async route => { const r = await route.fetch(); await route.fulfill({ response: r, body: (await r.text()).replace("const SANDBOX = 'allow-scripts", "const SANDBOX = 'allow-same-origin allow-scripts") }); };
    await page.route('**/preview/**', weakA); await page.route('**/assets/js/live.js', weakB);
    const weak = await runKit();
    ok(weak.length > 0 && weak.some(c => !c.ok), 'ביקורת חיובית לערכה כולה: כשמסירים את הבידוד — הערכה אדומה (' + weak.filter(c => !c.ok).map(c => c.text).join(' | ').slice(0, 90) + ')');
    ok((await page.textContent('#kit-info')).includes('❌'), 'והתוצאה האחרונה מוצגת כאדומה');
    await page.unroute('**/preview/**', weakA); await page.unroute('**/assets/js/live.js', weakB);
    fs.writeFileSync(trace, '');

    const kit = await runKit();
    const red = kit.filter(c => !c.ok);
    ok(kit.length >= 15 && red.length === 0, `ערכת ההתקפה: כל ${kit.length} הבדיקות ירוקות` + (red.length ? ' · נכשלו: ' + red.map(c => c.name + ' [' + c.text + ']').join(' | ') : ''));
    for (const n of ['מקור אטום', 'document.cookie', 'localStorage', 'parent.document', 'top.location', 'fetch ל-api.php', 'הקובץ לא השתנה', 'הסטודיו מתעלם', 'מוצגת כטקסט', 'בלי אסימון', 'ביקורת חיובית']) {
      const c = kit.find(x => x.name.includes(n));
      ok(c && c.ok, '  · ' + (c ? c.name + ' — ' + c.text : 'חסרה בדיקה: ' + n));
    }
    ok((await page.textContent('#kit-info')).includes('✅'), 'התוצאה האחרונה נשמרת ומוצגת במסך המפתח');
    await page.reload(); await page.waitForSelector('#kit-run');
    await until(async () => (await page.textContent('#kit-info')).includes('✅'));
    ok((await page.textContent('#kit-info')).includes('✅'), 'וגם אחרי טעינה מחדש (מהשרת)');
    ok(await page.evaluate(async () => (await api('pf_tree')).files.every(f => !f.path.startsWith('__kol-attack')) && (await api('pf_trash')).items.every(f => !f.path.startsWith('__kol-attack'))), 'הקבצים הזמניים נמחקו, גם מהסל');
    const hp = await until(() => page.$$eval('.dev-checks:not(#kit-out) li', els => els.map(e => e.textContent).find(t => t.includes('התצוגה החיה'))));
    ok(hp && hp.includes('✅') && hp.includes('מהדפדפן'), 'בדיקת התקינות: כתובת התצוגה מחזירה את כותרות הבידוד');

    // מה שהשרת ראה בפועל: כל פנייה ל-api.php מתוך התצוגה (Origin: null) הגיעה בלי זהות
    const T = seen(), fromFrame = T.filter(t => t.uri.includes('/api.php') && t.origin === 'null');
    ok(fromFrame.length > 0 && fromFrame.every(t => !t.user), `השרת: ${fromFrame.length} פניות ל-api.php מתוך התצוגה (${[...new Set(fromFrame.map(t => t.method))].join('+')}) — כולן בלי זהות` + (fromFrame.some(t => t.user) ? ' · ⚠️ ' + JSON.stringify(fromFrame.find(t => t.user)) : ''));
    ok(T.some(t => t.uri.includes('action=me') && t.user && t.origin !== 'null'), 'ביקורת חיובית: אותה פנייה מהסטודיו עצמו הגיעה עם זהות');
    ok(T.filter(t => t.uri.includes('/preview/')).length > 0, 'ביקורת חיובית: היומן רואה את הבקשות של התצוגה');

    // ── פתיחה ישירה בלשונית, עם ובלי אסימון; ומסגרת שמנווטת את עצמה ל-api.php ──
    await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row', { state: 'attached' });
    const PROBE = "<!doctype html><title>t</title><script>var d=document.documentElement.dataset;d.o=self.origin;try{void document.cookie;d.c='read'}catch(e){d.c='throw'}try{localStorage.length;d.s='read'}catch(e){d.s='throw'}</scr" + "ipt>";
    const HOST = '<img src=x onerror=window.pwned=1>.html';
    for (const [p, c] of [['t/origin.html', PROBE], ['t/nav.html', `<script>location.href = ${JSON.stringify(base + '/api.php?action=me&from=selfnav')}</scr` + 'ipt>'],
      ['t/loop.html', '<title>loop</title><script>setTimeout(() => { for (;;) {} }, 400)</scr' + 'ipt>'], ['t/' + HOST, '<title>שם עוין</title><p class=\'"><img src=x onerror=top.pwned=1>\'>x</p>']]) await call(page, 'pf_create', { path: p, content: c });
    const tok = (await call(page, 'pv_token')).token;
    const tab = await ctx.newPage();
    const res = await tab.goto(`${base}/preview/${tok}/t/origin.html`);
    const d = await tab.evaluate(() => ({ ...document.documentElement.dataset }));
    ok(res.status() === 200 && d.o === 'null' && d.c === 'throw' && d.s === 'throw', `פתיחה ישירה בלשונית, עם אסימון: מקור אטום (origin=${d.o}, cookie ${d.c}, storage ${d.s})`);
    const no = await tab.goto(`${base}/preview/x/t/origin.html`);
    ok(no.status() === 403 && (no.headers()['content-security-policy'] || '').startsWith('sandbox ') && !(await tab.content()).includes('dataset'), 'פתיחה ישירה בלי אסימון: 403, בלי תוכן, ועדיין בארגז חול');
    const ctl = await tab.goto(base + '/index.html');
    ok(await tab.evaluate(() => { try { void document.cookie; localStorage.length; return self.origin; } catch { return 'throw'; } }) === base, 'ביקורת חיובית: דף רגיל של הסטודיו — מקור רגיל, עוגייה ואחסון נקראים');
    fs.writeFileSync(trace, '');
    await tab.goto(base + '/live.php?path=t/nav.html');
    await sleep(1500);
    const nav2 = seen().find(t => t.uri.includes('from=selfnav'));
    ok(!nav2 || !nav2.user, 'מסגרת שמנווטת את עצמה ל-api.php: ' + (nav2 ? `הבקשה הגיעה בלי זהות (site: ${nav2.site})` : 'נחסמת עוד בדפדפן — frame-src של live.php מתיר רק את כתובת התצוגה'));
    ok(seen().some(t => t.uri.includes('/t/nav.html')), 'ביקורת חיובית: העמוד שמנווט אכן נטען');

    // ── לולאה אינסופית ──
    await tab.goto(base + '/live.php?path=t/loop.html');
    await until(async () => (await tab.textContent('#lv-title')) === 'loop');
    await sleep(1200);
    const alive = await Promise.race([tab.evaluate(() => 1 + 1), sleep(3000).then(() => 'FROZEN')]);
    ok(alive === 2, 'לולאה אינסופית בקוד העמוד: המסך נשאר חי (עם בידוד תהליכים — ר\' ההערה ב-README)');
    if (alive === 2) {
      await call(page, 'pf_write', { id: (await call(page, 'pf_tree')).files.find(f => f.path === 't/loop.html').id, rev: 1, content: '<title>fixed</title>' });
      await tab.click('#lv-stop');
      ok(await until(async () => (await tab.textContent('#lv-title')) === 'fixed'), '"עצור ורענן": המסגרת התקועה מוחלפת, והעמוד המתוקן נטען');
    }

    // ══ C1 שלב ב: המדיניות המלאה על live.php ══
    console.log('\n── C1 שלב ב ──');
    const lr = await tab.goto(base + '/live.php?path=' + encodeURIComponent('t/' + HOST));
    const csp = lr.headers()['content-security-policy'] || '';
    ok(csp.includes("default-src 'none'") && csp.includes("script-src 'self'") && !/unsafe-eval|script-src[^;]*unsafe-inline/.test(csp) && csp.includes("frame-ancestors 'self'") && csp.includes("object-src 'none'"), 'live.php: המדיניות המלאה ככותרת שרת');
    ok(csp.includes(`frame-src 127.0.0.1:${port}/preview/;`), 'frame-src מתיר רק את כתובת התצוגה');
    const attack = () => new Promise(async done => {
      const out = { violations: [] };
      document.addEventListener('securitypolicyviolation', e => out.violations.push(e.effectiveDirective));
      const s = document.createElement('script'); s.textContent = 'window.__inl = 1'; document.body.append(s); out.inline = window.__inl === 1;
      setTimeout('window.__ev = 1', 0); await new Promise(r => setTimeout(r, 150)); out.eval = window.__ev === 1;      // קוד ממחרוזת — כמו eval, ונבדק בהקשר של הדף עצמו
      out.img = await new Promise(r => { const i = new Image(); i.onload = () => r(true); i.onerror = () => r(false); i.src = location.href.replace('127.0.0.1', 'localhost').replace(/[^/]*$/, 'assets/icon.svg'); });
      const f = document.createElement('iframe'); f.src = 'index.html'; document.body.append(f);
      out.frame = await new Promise(r => { f.onload = () => { try { r(!!f.contentDocument && f.contentDocument.title !== ''); } catch { r(false); } }; setTimeout(() => r(false), 1500); });
      f.remove();
      setTimeout(() => done(out), 200);
    });
    const hard = await tab.evaluate(attack);
    ok(!hard.inline && !hard.eval && !hard.img && !hard.frame, `סקריפט בתוך הדף, eval, תמונה מדומיין זר ומסגרת שאינה התצוגה — נחסמים (${[...new Set(hard.violations)].join(', ')})`);
    await tab.waitForSelector('.lv-iframe');
    await tab.click('#lv-pick'); await sleep(300);
    await tab.frames().find(f => f.url().includes('/preview/')).click('p');
    await until(async () => (await tab.textContent('#lv-inspect')).includes('onerror'));
    ok(await tab.textContent('#lv-path') === 't/' + HOST && (await tab.textContent('#lv-inspect')).includes('<img') && await tab.$('.lv img') === null && await tab.evaluate(() => window.pwned) === undefined,
      'שם קובץ ושם מחלקה שהם HTML — מוצגים כטקסט, ואינם רצים');
    await tab.goto(base + '/index.html');
    const soft = await tab.evaluate(attack);
    ok(soft.inline && soft.eval && soft.frame, 'ביקורת חיובית: באותן בדיקות, דף בלי המדיניות (index.html) — הכול עובר, כלומר הבדיקה מסוגלת להיכשל');
    await ctx.close();
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
