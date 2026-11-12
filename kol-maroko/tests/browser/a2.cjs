/**
 * פרוסה A2 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/a2.cjs
 *
 * הבאת ההקלטות מהאתר, מקצה לקצה, מול "אתר" מדומה מקומי (tests/fake-audio.php — בלי רשת אמיתית): שום בקשה לפני לחיצה;
 * חסימה גורפת ← "האתר חוסם את הכלי" עם שתי הדרכים; בדיקה (HEAD בלבד) ← מסך אישור (כמה, מאיפה, נפח, מקום) ← הבאה;
 * הדפדפן נסגר באמצע ← פתיחה מחדש ← "המשך" מאותה נקודה, בלי הורדה כפולה; כישלון עם סיבה ו"נסה שוב"; השמעה מהכלי
 * ושמירת המשך; "מדוד את כולן"; עורך אינו רואה ואינו יכול; טלפון 390px.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
const EDITOR = { sub: 'u-editor', name: 'Editor Test', email: 'editor@example.test', email_verified: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 600) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-a2-'));
  const aud = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-a2-site-'));
  const seed = (...a) => execFileSync('php', [path.join(__dirname, 'a2-seed.php'), data, ...a]);
  seed();
  const [port, aport] = [await freePort(), await freePort()];
  const base = `http://127.0.0.1:${port}`, site = `http://127.0.0.1:${aport}`;
  const fake = spawn('php', ['-S', `127.0.0.1:${aport}`, path.join(ROOT, 'tests', 'fake-audio.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_AUDIO: aud, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, stdio: 'ignore',
    env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4', KOL_TEST_REC_MAP: JSON.stringify({ 'https://maghreb.co.il': site, 'https://www.maghreb.co.il': site }) } });
  /** הבקשות שהאתר המדומה קיבל. */
  const siteLog = () => { try { return fs.readFileSync(path.join(aud, 'log.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)); } catch { return []; } };
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); await fetch(site + '/x'); break; } catch { await sleep(50); } }
    fs.writeFileSync(path.join(aud, 'log.jsonl'), '');   // בדיקת המוכנות של השרת המדומה אינה בקשה של הכלי
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const openLib = async page => { await page.goto(base + '/#/files'); await page.waitForSelector('.lib-list .lib-row'); };
    const sheetText = page => page.$eval('#sheet-body', e => e.innerText).catch(() => '');
    const waitSheet = (page, re, timeout = 30000) => page.waitForFunction(r => new RegExp(r).test(document.querySelector('#sheet-body')?.innerText || ''), re.source, { timeout });
    const clickBtn = async (page, re) => { const b = page.locator('#sheet-body button', { hasText: re }).first(); await b.click(); };

    const ctx = await mk(1280, 860), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/');
    await page.evaluate(() => sessionStorage.setItem('kol-lib-tab', 'audio'));
    await openLib(page);
    await page.waitForSelector('.lf-panel');
    await sleep(1500);
    ok(siteLog().length === 0, 'פתיחת הספרייה (בעלים) — אפס בקשות לאתר', siteLog());
    ok(/מוצגות ההקלטות של המילים\. קובצי שמע אחרים שבאתר עדיין אינם מוצגים\./.test(await page.innerText('#main, body')), 'ההודעה על קבצים שאינם מחוברים למילה (ש-13)');

    // ── 1. חסימה גורפת ──
    await page.click('.lf-panel button');
    await waitSheet(page, /בדוק מה יש באתר/);
    ok(siteLog().length === 0, 'פתיחת החלון — עדיין אפס בקשות');
    await clickBtn(page, /בדוק מה יש באתר/);
    await waitSheet(page, /האתר חוסם את הכלי/);
    const t1 = await sheetText(page);
    ok(/ייצוא ידני/.test(t1) && /KolMaroko-AudioFetch/.test(t1) && /Security rules/.test(t1), 'חסימה: שתי הדרכים — ייצוא ידני וכלל ב-Cloudflare (עם הביטוי)', t1.slice(0, 400));
    ok(!/הבא את ההקלטות/.test(t1), 'חסימה: אין כפתור הבאה');
    const l1 = siteLog();
    ok(l1.length >= 5 && l1.length < 20 && l1.every(x => x.method === 'HEAD'), 'חסימה: הסריקה נעצרה אחרי כמה בקשות HEAD — לא 20 כישלונות', l1.length);

    // ── 2. אחרי כלל ב-Cloudflare: בדיקה ← אישור ← הבאה ──
    seed('unblock');
    fs.writeFileSync(path.join(aud, 'log.jsonl'), '');
    await clickBtn(page, /בדוק שוב/);
    await waitSheet(page, /הבא את ההקלטות \(/);
    const t2 = await sheetText(page);
    ok(/מה יש באתר/.test(t2) && /1\s*חסרות/.test(t2) && /נפח/.test(t2) && /מקום פנוי/.test(t2), 'תוצאת הבדיקה: חסרות, נפח, מקום פנוי', t2.slice(0, 500));
    ok(/הבא את ההקלטות \(18\)/.test(t2), 'להבאה: 18 (בלי החסרה ובלי דף ה-HTML)', t2.match(/הבא את ההקלטות \(\d+\)/)?.[0]);
    await page.click('#sheet-body details.lf-list summary');
    ok(/#13\s*מילה 13/.test(await sheetText(page)) && /missing\/13\.mp3/.test(await sheetText(page)), 'הרשימה של החסרות — עם המילה, המזהה והכתובת');
    ok(siteLog().every(x => x.method === 'HEAD') && siteLog().length === 20, 'הבדיקה: HEAD בלבד, אחת לכל כתובת', siteLog().length);
    ok(siteLog().every(x => x.ua.startsWith('KolMaroko-AudioFetch/') && !x.cookie && !x.auth), 'בלי עוגיות ובלי הרשאה; User-Agent גלוי');
    await clickBtn(page, /הבא את ההקלטות/);
    await waitSheet(page, /להביא את ההקלטות אל הכלי\?/);
    const t3 = await sheetText(page);
    ok(/כמה: 18 הקלטות/.test(t3) && /מאיפה: maghreb\.co\.il/.test(t3) && /נפח: /.test(t3) && /יישאר: /.test(t3), 'מסך האישור: כמה, מאיפה, נפח, וכמה יישאר', t3.slice(0, 400));
    await sleep(800);
    ok(!siteLog().some(x => x.method === 'GET'), 'עד האישור — שום GET');
    await clickBtn(page, /^ביטול$/);
    await sleep(800);
    ok(!siteLog().some(x => x.method === 'GET'), 'ביטול — שום GET');
    await clickBtn(page, /הבא את ההקלטות/);
    await waitSheet(page, /כן, הבא 18 הקלטות/);
    await clickBtn(page, /כן, הבא/);
    await waitSheet(page, /מביא את ההקלטות/);
    await page.waitForFunction(() => { const s = LibraryFetch._state().S; return s && s.job.done >= 4; }, null, { timeout: 30000 });
    // ── 3. הדפדפן נסגר באמצע ──
    await page.close();
    await sleep(5000);                                  // הצעד שכבר היה בדרך — מסתיים בשרת
    seed('stale');
    const doneMid = siteLog().filter(x => x.method === 'GET').length;
    const page2 = await ctx.newPage();
    page2.on('pageerror', e => errors.push(e.message));
    await openLib(page2);
    await page2.waitForSelector('.lf-panel');
    ok(/ההבאה נעצרה באמצע/.test(await page2.innerText('.lf-panel')), 'פתיחה מחדש: "ההבאה נעצרה באמצע — אפשר להמשיך"', await page2.innerText('.lf-panel'));
    await sleep(1500);
    ok(siteLog().filter(x => x.method === 'GET').length === doneMid, 'פתיחה מחדש לבדה אינה ממשיכה להוריד');
    const st0 = await call(page2, 'rec_status');
    ok(st0.job.done >= 4 && st0.counts.todo > 0, 'במצב שבשרת: חלק הובא, חלק נותר', { done: st0.job.done, todo: st0.counts.todo });
    await page2.click('.lf-panel button');
    await waitSheet(page2, /▶ המשך/);
    await page2.locator('#sheet-body button', { hasText: /▶ המשך/ }).click();
    await waitSheet(page2, /ההבאה הסתיימה/, 60000);
    const t4 = await sheetText(page2);
    ok(/נכשלו בהבאה · 2/.test(t4) && /ההורדה נקטעה באמצע/.test(t4), 'כישלונות עם סיבה במילים (הנקטעת והנופלת)', t4.slice(0, 600));
    const gets = {};
    for (const x of siteLog().filter(x => x.method === 'GET')) gets[x.path] = (gets[x.path] || 0) + 1;
    ok(Object.values(gets).every(n => n === 1) && Object.keys(gets).length === 18, 'כל הקלטה ירדה פעם אחת בלבד — גם אחרי העצירה', gets);
    // נסה שוב — לאחת
    await page2.locator('#sheet-body .lf-fails li', { hasText: 'מילה 16' }).locator('button').click();
    await waitSheet(page2, /נכשלו בהבאה · 1/, 30000);
    ok((await call(page2, 'rec_status')).counts.tool === 17, '"נסה שוב" — הנופלת הובאה בניסיון השני');
    await page2.keyboard.press('Escape');

    // ── 4. בספרייה: "בכלי", מתנגנת מהכלי, המשך נשמר ──
    await page2.waitForFunction(() => document.querySelectorAll('.lib-row .chip.ok').length >= 17, null, { timeout: 15000 });
    const lib = await call(page2, 'library');
    const r7 = lib.rows.find(r => r.id === 7);
    ok(r7.state === 'tool' && r7.src.startsWith('api.php?action=media&') && r7.file.origin === 'https://maghreb.co.il/wp-content/uploads/ok/7.mp3', 'הקלטה שהובאה — "בכלי", מתנגנת מהכלי, והמקור נשמר', r7);
    const words = await call(page2, 'words');
    ok(words.words.find(w => w.id === 7).audio === 'https://maghreb.co.il/wp-content/uploads/ok/7.mp3', 'המילה עצמה לא השתנתה (עדיין מפנה לאתר — עד M2)');
    ok(lib.rows.find(r => r.id === 15).fetch?.reason === 'ההורדה נקטעה באמצע', 'בשורה של מה שנכשל — הסיבה');
    await page2.locator('.lib-row[data-id="7"] .lib-play').click();
    await page2.waitForFunction(() => { const a = document.querySelector('.lib-player') && [...document.querySelectorAll('audio')]; return true; });
    let saved = null;
    for (let i = 0; i < 40 && saved == null; i++) { await sleep(250); saved = (await call(page2, 'library')).rows.find(r => r.id === 7).file.duration; }
    ok(saved > 0, 'השמעה מהכלי — המשך נמדד ונשמר בשרת', saved);
    const before = (await call(page2, 'library')).rows.filter(r => r.state === 'tool' && r.file.duration == null).length;
    await page2.evaluate(() => { location.hash = '#/words'; }); await sleep(300);
    await openLib(page2);
    const mb = page2.locator('.lib-measure');
    ok(await mb.count() === 1 && (await mb.innerText()).includes(String(before)), '"מדוד את כולן" — עם מספר ההקלטות שלא נמדדו', before);
    await mb.click();
    await page2.waitForFunction(() => /נמדדו/.test(document.querySelector('.lib-measure')?.textContent || ''), null, { timeout: 60000 });
    ok((await call(page2, 'library')).rows.filter(r => r.state === 'tool' && r.file.duration == null).length === 0, '"מדוד את כולן" — כל ההקלטות שבכלי נמדדו ונשמרו');
    ok(!siteLog().some(x => !['GET', 'HEAD'].includes(x.method)), 'לאתר הגיעו רק GET ו-HEAD');

    // ── 5. עורך: אינו רואה ואינו יכול ──
    const ectx = await mk(1280, 800, EDITOR), ep = await ectx.newPage();
    await ep.goto(base + '/');
    await ep.evaluate(() => sessionStorage.setItem('kol-lib-tab', 'audio'));
    await openLib(ep);
    await sleep(500);
    ok(await ep.locator('.lf-panel').count() === 0, 'עורך — אין "הבאה מהאתר"');
    const denied = await ep.evaluate(async () => { const o = {}; for (const a of ['rec_status', 'rec_fetch_plan']) { try { await api(a); o[a] = 'ok'; } catch (e) { o[a] = e.status; } }
      for (const a of ['rec_scan_start', 'rec_fetch_start', 'rec_fetch_step', 'rec_job_op']) { try { await api(a, { confirm: true }); o[a] = 'ok'; } catch (e) { o[a] = e.status; } } return o; });
    ok(Object.values(denied).every(v => v === 403), 'עורך — כל פעולות ההבאה נדחות בשרת (403)', denied);

    // ── 6. טלפון ──
    const pctx = await mk(390, 780), pp = await pctx.newPage();
    await pp.goto(base + '/');
    await pp.evaluate(() => sessionStorage.setItem('kol-lib-tab', 'audio'));
    await openLib(pp);
    await pp.waitForSelector('.lf-panel');
    await pp.click('.lf-panel button');
    await waitSheet(pp, /נכשלו בהבאה/);
    const over = await pp.evaluate(() => ({ doc: document.documentElement.scrollWidth, w: innerWidth, sheet: document.querySelector('#sheet').scrollWidth, sw: document.querySelector('#sheet').clientWidth }));
    ok(over.doc <= over.w && over.sheet <= over.sw + 1, 'טלפון 390px — בלי גלילה לרוחב', over);
    const tiny = await pp.$$eval('#sheet-body button', bs => bs.filter(b => b.offsetParent && b.getBoundingClientRect().height < 32).map(b => b.textContent));
    ok(tiny.length === 0, 'טלפון — כפתורים בגובה נוח', tiny);
    ok(errors.length === 0, 'אין שגיאות JS בדף', errors);
  } catch (e) {
    failed++;
    console.log('❌ הבדיקה נפלה: ' + (e.stack || e.message));
  } finally {
    if (browser) await browser.close();
    php.kill(); fake.kill();
    fs.rmSync(data, { recursive: true, force: true }); fs.rmSync(aud, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
