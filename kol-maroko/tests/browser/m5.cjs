/**
 * פרוסה M5 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/m5.cjs
 *
 * בתצוגה החיה (ארגז חול, מקור אטום): פתיחת חלון המשוב מכרטיס ← שליחה ל-form.php?test=1 ← אלמנט ההצלחה, הטופס מתאפס,
 * פתיחה מחדש מסתירה את ההודעות; שגיאת רשת ← אלמנט השגיאה. בסטודיו: "📬 פניות" — הפנייה בסינון 🧪 עם המילה, הערכים
 * כטקסט (<img onerror> ו-<script> לא רצים), "✏️ ערוך את המילה" פותח את 📚; פנייה "מהאתר" ← המונה בלשונית, "טופל".
 * מסך המפתח ← 📬 טפסים: בדיקת התקינות (OPTIONS / POST) ירוקה. 1280px ו-390px. רץ מול tests/router.php ומסד זמני.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, extra) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (extra !== undefined ? ' — ' + JSON.stringify(extra).slice(0, 300) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

const EVIL = '<img src=x onerror="window.top.pwned=1"><script>window.top.pwned=2</script>';
const PAGE = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><title>m5</title></head><body>
<ul id="list" data-kol-list="words" data-kol-sort="word"><template><li class="card"><b data-kol-text="word"></b> <button class="fb" type="button" data-kol-open="feedback">משוב</button></li></template></ul>
<div id="dlg" data-kol-dialog="feedback" hidden><strong id="dw" data-kol-text="word"></strong>
<form id="form" data-kol-form="feedback"><input type="hidden" name="word_id" data-kol-value="id">
<input id="name" name="name"><textarea id="msg" name="message" required></textarea>
<div aria-hidden="true" style="position:absolute;left:-9999px"><input name="website" tabindex="-1" autocomplete="off" data-kol-trap></div>
<button id="send" type="submit">שלח</button><button id="x" type="button" data-kol-close>סגור</button>
<p id="done" data-kol-form-done hidden>תודה, התקבל</p><p id="err" data-kol-form-error hidden>לא נשלח</p></form></div>
<script src="js/kol-data.js"></script></body></html>`;

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m5-'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.accept());
    const call = (action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');

    // ── הזרע: מילים, kol-data.js 1.2.0, ועמוד עם טופס ─────
    const w1 = (await call('word_save', { word: 'אַבָּא', meaning: '<p>אב</p>', topics: [] })).id;
    await call('word_save', { word: 'בסלאמה', meaning: '<p>לשלום</p>', topics: [] });
    const seed = (await call('kd_seed')).content;
    ok(/version: '1\.2\.0'/.test(seed), 'kol-data.js בזרע — 1.2.0');
    const tree = await call('pf_tree');
    const kd = tree.files.find(f => f.path === 'js/kol-data.js');
    if (kd) await call('pf_write', { id: kd.id, rev: kd.rev, content: seed }); else await call('pf_create', { path: 'js/kol-data.js', content: seed });
    await call('pf_create', { path: 'm5.html', content: PAGE });

    // ── התצוגה החיה ──────────────────────────────────────
    const tok = (await call('pv_token')).token;
    const visitor = await browser.newContext({ viewport: { width: 1280, height: 860 } });     // גולש: בלי זהות ובלי עוגייה
    const pv = await visitor.newPage();
    const pvErr = [], posts = [];
    pv.on('pageerror', e => pvErr.push(e.message));

    pv.on('request', r => { if (r.url().includes('/form.php')) posts.push({ url: r.url(), method: r.method(), cookie: !!r.headers()['cookie'], body: r.postData() || '' }); });
    await pv.goto(`${base}/preview/${tok}/m5.html`);
    await pv.waitForSelector('#list .card');
    const meta = await pv.getAttribute('meta[name="kol-form-endpoint"]', 'content');
    ok(meta === `${base}/form.php?test=1`, 'meta של הכתובת בעמוד שמוגש — ?test=1', meta);
    ok(await pv.evaluate(() => window.KolData.version) === '1.2.0', 'KolData.version 1.2.0');
    await pv.click('#list .card:nth-of-type(1) .fb');
    ok(await pv.isVisible('#dlg') && await pv.textContent('#dw') === 'אַבָּא', 'פתיחת חלון המשוב מכרטיס, עם המילה');
    ok(await pv.$eval('[name=word_id]', e => e.value) === String(w1), 'word_id ← המילה (data-kol-value)');
    ok(!(await pv.isVisible('#done')) && !(await pv.isVisible('#err')), 'בפתיחה — שתי ההודעות מוסתרות');
    await pv.fill('#name', 'מבקר');
    await pv.fill('#msg', EVIL);
    await sleep(3200);                                                       // _t מינימלי 3 שניות
    const answer = pv.waitForResponse(r => r.url().includes('/form.php'));
    await pv.click('#send');
    const res = await answer;
    ok(res.status() === 200 && (await res.json()).ok === true, 'השליחה — {"ok":true}', res.status());
    await pv.waitForSelector('#done:not([hidden])');
    ok(await pv.isVisible('#done') && !(await pv.isVisible('#err')), 'אחרי הצלחה: אלמנט ההצלחה מוצג, השגיאה מוסתרת');
    ok(await pv.$eval('#msg', e => e.value) === '' && !(await pv.$eval('#send', e => e.disabled)) && !(await pv.getAttribute('#form', 'aria-busy')), 'הטופס התאפס, הכפתור חזר, בלי aria-busy');
    const p0 = posts[0] || {};
    ok(p0.method === 'POST' && !p0.cookie && /_form/.test(p0.body) && /_t/.test(p0.body) && /_page/.test(p0.body) && /m5\.html/.test(p0.body) && !/name="website"/.test(p0.body), 'FormData: _form, _t, _page — בלי עוגייה; המלכודת לא נשלחה בשמה', p0);
    await pv.click('#x');
    await pv.click('#list .card:nth-of-type(1) .fb');
    ok(!(await pv.isVisible('#done')) && !(await pv.isVisible('#err')), 'פתיחה מחדש מסתירה את שתי ההודעות');
    // שגיאת רשת
    await pv.route('**/form.php**', r => r.abort());
    await pv.fill('#msg', 'שגיאת רשת');
    await pv.click('#send');
    await pv.waitForSelector('#err:not([hidden])');
    ok(await pv.isVisible('#err') && !(await pv.isVisible('#done')) && await pv.$eval('#msg', e => e.value) === 'שגיאת רשת', 'שגיאת רשת ← אלמנט השגיאה, והטקסט נשאר');
    await pv.unroute('**/form.php**');
    // שרת שדוחה (ספאם נענה ok — לכן כאן: גודל)
    await pv.fill('#msg', 'x'.repeat(6000));
    const big = pv.waitForResponse(r => r.url().includes('/form.php'));
    await pv.click('#send');
    ok((await (await big).json()).reason === 'size', 'שדה ארוך מ-5,000 — השרת דוחה (size)');
    await pv.waitForSelector('#err:not([hidden])');
    ok(await pv.isVisible('#err'), '…ואלמנט השגיאה מוצג');
    ok(!pvErr.length, 'אין שגיאות JS בעמוד', pvErr);

    // ── "מהאתר": גרסה שנשלחה עם הטופס, ופנייה בלי test ──
    execFileSync('php', ['-r', `define('KOL_DATA_DIR', $argv[1]); require '${ROOT}/lib/db.php'; db()->prepare("INSERT INTO pkg_builds (status, by_sub, at, sent_at, forms) VALUES ('live', 'm5', ?, ?, ?)")->execute([now_iso(), now_iso(), json_encode(['feedback' => ['fields' => ['name', 'message', 'word_id'], 'required' => ['message'], 'external' => false, 'trap' => true, 'files' => false]])]);`, data]);
    const site = await (await fetch(base + '/form.php', { method: 'POST', body: new URLSearchParams({ _form: 'feedback', _t: '9000', _page: 'index.html', message: 'מהאתר — הערה', word_id: String(w1) }) })).json();
    ok(site.ok === true, 'פנייה "מהאתר" — התקבלה', site);

    // ── 📬 פניות בסטודיו ──────────────────────────────────
    await page.goto(base + '/#/inbox');
    await page.waitForSelector('.ib-entries, .ib-list .empty');
    await page.waitForFunction(() => /📬 פניות \(1\)/.test(document.querySelector('#tabs a[href$="#/inbox"]')?.textContent || ''));
    ok(true, 'המונה בלשונית: "📬 פניות (1)" — פניות 🧪 אינן נספרות');
    ok(await page.locator('.ib-entry').count() === 1 && (await page.textContent('.ib-entry summary')).includes('מהאתר'), 'בתיבה הרגילה — רק הפנייה מהאתר');
    await page.click('.ib-entry summary');
    await page.waitForFunction(() => !/\(1\)/.test(document.querySelector('#tabs a[href$="#/inbox"]').textContent));
    ok(true, 'פתיחה מסמנת "נקרא" — המונה ירד');
    await page.click('.ib-entry .btn.primary');                             // ✅ טופל
    await page.waitForSelector('.ib-entry .ib-body .hint:has-text("טופל: Owner Test")');
    ok(true, '"טופל" — עם מי ומתי');
    await page.click('.seg-tabs button:has-text("🧪 בדיקות")');
    await page.waitForSelector('.ib-entry');
    const tests = await page.locator('.ib-entry').count();
    ok(tests === 1, 'בסינון 🧪 — פניית התצוגה החיה (הנדחות לא נשמרו)', tests);
    ok((await page.textContent('.ib-entry summary')).includes('📚 אַבָּא'), 'הפנייה קשורה למילה');
    await page.click('.ib-entry summary');
    await page.waitForSelector('.ib-fields dd');
    const dd = await page.$$eval('.ib-fields dd', a => a.map(x => x.textContent));
    ok(dd.includes(EVIL), 'הערך מוצג כטקסט, בדיוק כמו שנשלח', dd);
    ok(await page.$$eval('.ib-fields img, .ib-fields script, .ib-entries img', a => a.length) === 0 && await page.evaluate(() => window.pwned) === undefined, '<img onerror> ו-<script> בפנייה — לא נוצרו ולא רצו');
    await page.click('.ib-entry button:has-text("ערוך את המילה")');
    await page.waitForFunction(() => location.hash === '#/words');
    await sleep(500);
    ok(true, '"✏️ ערוך את המילה" ← 📚 מילים');
    ok(await page.evaluate(() => !!document.querySelector('#sheet[open]') && document.querySelector('#sheet').textContent.includes('אַבָּא')), '…והמילה נפתחה לעריכה');
    await page.evaluate(() => document.querySelector('#sheet').open && document.querySelector('#sheet').close());

    // CSV של הסינון
    const csv = await page.evaluate(async () => { const b = new Uint8Array(await (await fetch('api.php?action=inbox_csv&filter=test')).arrayBuffer()); return [b[0], b[1], b[2], new TextDecoder().decode(b)]; });
    ok(csv[0] === 0xEF && csv[1] === 0xBB && csv[2] === 0xBF && csv[3].includes('<img src=x') && !csv[3].includes('מהאתר'), 'ייצוא CSV של הסינון (🧪 בלבד) — UTF-8 עם BOM, הערך כמו שהוא', csv.slice(0, 3));

    // ── מסך המפתח ─────────────────────────────────────────
    await page.goto(base + '/#/dev/forms');
    await page.waitForSelector('#ib-health li');
    await page.waitForFunction(() => document.querySelectorAll('#ib-health li').length === 2);
    const h = await page.$$eval('#ib-health li', a => a.map(x => x.className + ' ' + x.textContent));
    ok(h.every(x => x.startsWith('ok')), 'בדיקת התקינות: OPTIONS ← CORS, POST בלי טופס ← invalid', h);
    ok((await page.textContent('.dev-body')).includes('ספאם') && (await page.textContent('.dev-body')).includes('גודל'), 'הדחיות לפי סיבה');
    await page.fill('input[aria-label="כתובת השולח"]', 'kol@example.test');
    await page.click('button:has-text("שלח מייל בדיקה")');
    await page.waitForFunction(() => /בדיקה: (✅|❌)/.test(document.querySelector('#ib-mail-test').textContent));
    ok(true, '"שלח מייל בדיקה" — התוצאה מוצגת: ' + await page.textContent('#ib-mail-test'));

    // ── 390px ─────────────────────────────────────────────
    const ph = await browser.newContext({ viewport: { width: 390, height: 800 }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const pp = await ph.newPage();
    await pp.goto(base + '/#/inbox');
    await pp.waitForSelector('.ib-entry');
    ok(await pp.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), '390px: התיבה בלי גלילה לרוחב');
    ok(!errors.length, 'אין שגיאות JS בסטודיו', errors);
  } catch (e) {
    failed++;
    console.log('❌ ' + (e.stack || e.message));
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
    console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
    process.exit(failed ? 1 : 0);
  }
})();
