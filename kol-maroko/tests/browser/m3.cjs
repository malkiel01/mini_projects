/**
 * פרוסה M3 — מסך "🔁 מעבר" בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/m3.cjs
 *
 *  א. בעלים: עשר שורות בסדר של 09 §6.1, לכל שורה מצב, מקור ו"מה לעשות"; התוסף האמיתי 1.x (tests/fake-wp.php) ← ✗ "2.0";
 *     המשוב ⏳ (M5 טרם); אין "מוכן למעבר"; כלל הכיבוי גלוי.
 *  ב. ראיה מהממשק: בלי "אני מאשר/ת" — נדחית; הערה + אישור ← ✓ עם השם; קובץ (העלאה רגילה) ← 📎; ביטול ← אדום בראש המסך.
 *     "🛟 צור עכשיו" ← נקודת השחזור ✓.
 *  ג. מפרסם — רואה בלי כפתורי שינוי; עורך — אין חלק "מעבר".
 *  ד. "מוכן" שמוצג בממשק (תשובה מזויפת) — השרת עדיין דוחה את "פתח מטלה" (409). מצב "באוויר" — המפסק לקריאה בלבד,
 *     ואין שום כפתור הדלקה / כיבוי.
 *  ה. 390px — בלי גלילה אופקית; 0 שגיאות קונסול.
 *
 * רץ מול tests/router.php ומסד זמני — data/ לא נפתחת. האתר המדומה — בתיקייה זמנית.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const USERS = {
  owner: { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true },
  publisher: { sub: 'u-pub', name: 'Pub Test', email: 'pub@example.test', email_verified: true },
  editor: { sub: 'u-ed', name: 'Ed Test', email: 'ed@example.test', email_verified: true },
};
let failed = 0, ran = 0;
const ok = (cond, what, detail = '') => { ran++; if (cond) console.log('✅ ' + what + (detail ? ' — ' + detail : '')); else { failed++; console.log('❌ ' + what + (detail ? ' — ' + detail : '')); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m3-'));
  const wpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m3wp-'));
  const [port, wpPort] = [await freePort(), await freePort()];
  const base = `http://127.0.0.1:${port}`, wp = `http://127.0.0.1:${wpPort}`;
  execFileSync('php', ['-r', `
    define('KOL_DATA_DIR', $argv[1]);
    foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'build'] as $l) require_once $argv[2] . "/lib/$l.php";
    $now = now_iso();
    foreach ([['u-pub', 'pub@example.test', 'Pub Test', 'publisher'], ['u-ed', 'ed@example.test', 'Ed Test', 'editor']] as $m)
      db()->prepare('INSERT INTO members (sub, email, name, role, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([...$m, 'test', $now]);
    setting_set('site_url', $argv[3]);
    file_put_contents($argv[4] . '/options.json', json_encode(['kol_maroko_keys' => pub_key_line(pub_key())]));
  `, data, ROOT, wp, wpDir]);
  const env = { ...process.env, PHP_CLI_SERVER_WORKERS: '4' };
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...env, KOL_TEST_DATA: data }, stdio: 'ignore' });
  const wpPhp = spawn('php', ['-S', `127.0.0.1:${wpPort}`, path.join(ROOT, 'tests', 'fake-wp.php')], { cwd: ROOT, env: { ...env, KOL_TEST_WP: wpDir }, stdio: 'ignore' });
  let browser;
  const errors = [];
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); await fetch(wp + '/'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const open = async (who, width = 1280) => {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      await ctx.addCookies([{ name: 'kol_test_user', value: Buffer.from(JSON.stringify(USERS[who])).toString('base64'), url: base, httpOnly: true, sameSite: 'Lax' }]);
      const page = await ctx.newPage();
      page.on('pageerror', e => errors.push(who + ': ' + e.message));
      page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(who + ': ' + m.text()); });
      page.on('dialog', d => d.accept());
      return page;
    };
    const rowOf = (page, key) => page.locator(`.co-row[data-key="${key}"]`);
    const statusOf = async (page, key) => (await rowOf(page, key).getAttribute('class')).match(/co-(ok|bad|wait)/)[1];
    const ready = page => page.waitForSelector('.co .co-rows', { timeout: 15000 }).catch(async e => { throw new Error(e.message + ' · ' + (await page.textContent('#main').catch(() => ''))); });

    // ════ א. בעלים ════
    console.log('\n── א. המסך ──');
    const page = await open('owner');
    await page.goto(base + '/#/publish');
    await ready(page);
    const keys = await page.$$eval('.co-row', els => els.map(e => e.dataset.key));
    ok(JSON.stringify(keys) === JSON.stringify(['fidelity', 'build', 'plugin', 'attack_kit', 'feedback', 'recordings', 'q15', 'q4', 'backup', 'owner_checklist']), 'עשר שורות, בסדר של 09 §6.1', keys.join(','));
    const meta = await page.$$eval('.co-row', els => els.map(e => ({ src: !!e.querySelector('.co-src')?.textContent.trim(), todo: !!e.querySelector('.co-todo'), ok: e.classList.contains('co-ok') })));
    ok(meta.every(m => m.src), 'לכל שורה — מאיפה המצב בא');
    ok(meta.every(m => m.ok || m.todo), 'לכל שורה שאינה ✓ — "מה לעשות"');
    ok(await statusOf(page, 'plugin') === 'bad' && /2\.0/.test(await rowOf(page, 'plugin').textContent()), 'התוסף האמיתי 1.x ← ✗, "להתקין 2.0"');
    ok(await statusOf(page, 'feedback') === 'wait', 'המשוב ⏳ — M5 טרם');
    ok(await statusOf(page, 'recordings') === 'bad', 'הקלטות ✗ (אין בכלי)');
    ok(await statusOf(page, 'build') === 'bad', 'אין פרסום בפורמט החדש ← ✗');
    ok(!(await page.$('.co-ready')) && !!(await page.$('.co-notready')), 'אין "מוכן למעבר"');
    ok(/מכבים/.test(await page.textContent('.co-kill')), 'כלל הכיבוי גלוי');
    ok(!/הדלק/.test(await page.$$eval('.co button', bs => bs.map(b => b.textContent).join('|'))), 'אין כפתור "הדלק" בכלי');

    // ════ ב. ראיה ════
    console.log('\n── ב. ראיה ──');
    await rowOf(page, 'q15').locator('button', { hasText: 'ראיה' }).click();
    await page.waitForSelector('.co-form');
    await page.fill('.co-form textarea', 'כל ההבדלים הוכרעו');
    await page.click('.co-form .btn.primary');
    ok(/אני מאשר/.test(await page.textContent('.co-form [role="status"]')), 'בלי "אני מאשר/ת" — נדחית');
    await page.check('.co-form .co-confirm input');
    await page.click('.co-form .btn.primary');
    await page.waitForSelector('.co-row[data-key="q15"].co-ok', { timeout: 8000 }).catch(() => {});
    ok(await statusOf(page, 'q15') === 'ok', 'הערה + אישור ← ✓');
    ok(/Owner Test/.test(await rowOf(page, 'q15').locator('.co-ev').textContent()), 'הראיה עם השם');

    await rowOf(page, 'attack_kit').locator('button', { hasText: 'ראיה' }).click();
    await page.waitForSelector('.co-form');
    await page.setInputFiles('.co-form input[type="file"]', { name: 'attack-kit.txt', mimeType: 'text/plain', buffer: Buffer.from('3/3 green\n') });
    await page.check('.co-form .co-confirm input');
    await page.click('.co-form .btn.primary');
    await page.waitForSelector('.co-row[data-key="attack_kit"].co-ok', { timeout: 8000 }).catch(() => {});
    ok(/📎 attack-kit\.txt/.test(await rowOf(page, 'attack_kit').textContent()), 'קובץ ראיה ← 📎 עם שם הקובץ');

    await rowOf(page, 'q15').locator('.co-revoke').click();
    await page.waitForSelector('.co-fell', { timeout: 8000 }).catch(() => {});
    ok(await statusOf(page, 'q15') === 'bad', 'ביטול ← ✗');
    ok(/ש-15/.test(await page.textContent('.co-fell').catch(() => '')), '✓ שנפל — אדום בראש המסך');
    const firstChild = await page.$eval('.co', el => [...el.children].findIndex(c => c.classList.contains('co-fell')));
    ok(firstChild === 1, 'האדום מתחת לכותרת, לפני השורות');

    await rowOf(page, 'backup').locator('button', { hasText: 'צור עכשיו' }).click();
    await page.waitForSelector('.co-row[data-key="backup"].co-ok', { timeout: 8000 }).catch(() => {});
    ok(await statusOf(page, 'backup') === 'ok', '"🛟 צור עכשיו" ← ✓');

    // ════ ג. תפקידים ════
    console.log('\n── ג. תפקידים ──');
    const pub = await open('publisher');
    await pub.goto(base + '/#/publish');
    await ready(pub);
    ok(await pub.$$eval('.co-row', e => e.length) === 10, 'מפרסם רואה את התנאים');
    ok(await pub.$$eval('.co button', bs => bs.filter(b => /ראיה|צור עכשיו|בטל|מוכן/.test(b.textContent)).length) === 0, 'מפרסם — בלי כפתורי שינוי');
    const r403 = await pub.evaluate(() => api('cutover_evidence', { cond: 'q15', confirm: true, note: 'x' }).then(() => 200, e => e.status));
    ok(r403 === 403, 'מפרסם — ראיה נדחית בשרת', String(r403));
    const ed = await open('editor');
    await ed.goto(base + '/#/publish');
    await ed.waitForSelector('#main h2', { timeout: 15000 });
    await sleep(500);
    ok(!(await ed.$('.co')), 'עורך — אין חלק "מעבר"');
    const e403 = await ed.evaluate(() => api('cutover').then(() => 200, e => e.status));
    ok(e403 === 403, 'עורך — קריאה נדחית בשרת', String(e403));

    // ════ ד. "מוכן" ו"באוויר" — מה שמוצג אינו מה שמוחלט ════
    console.log('\n── ד. מוכן / באוויר ──');
    const real = await page.evaluate(() => api('cutover'));
    const fake = { ...real, rows: real.rows.map(r => ({ ...r, status: 'ok', todo: '' })), ready: true, fell: [] };
    await page.route('**/api.php?action=cutover', route => route.request().method() === 'GET'
      ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, ...fake }) }) : route.continue());
    await page.reload();
    await ready(page);
    ok(/וורדפרס ← קול מרוקו ← העמוד החדש ← הדלק/.test(await page.textContent('.co-ready').catch(() => '')), '"מוכן": ההוראה המדויקת לבעל האתר');
    await page.click('.co-ready .btn.primary');
    await sleep(800);
    ok(/עוד לא מוכן/.test(await page.textContent('.toast')), 'השרת דוחה "פתח מטלה" כשהתנאים אינם ✓ באמת (409)');
    const task = await page.evaluate(() => api('cutover_ready', {}).then(() => 200, e => e.status));
    ok(task === 409, 'cutover_ready ← 409', String(task));

    await page.unroute('**/api.php?action=cutover');
    const live = { ...fake, on: true, live: { since: new Date().toISOString(), version: 7 }, stats: { served: 12, e404: 0 } };
    await page.route('**/api.php?action=cutover', route => route.request().method() === 'GET'
      ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, ...live }) }) : route.continue());
    await page.reload();
    await ready(page);
    const lt = await page.textContent('.co-live').catch(() => '');
    ok(/באוויר מאז/.test(lt) && /גרסה 7/.test(lt), '"באוויר מאז …"');
    ok(/לקריאה בלבד/.test(lt) && /דולק/.test(lt), 'המפסק מוצג — לקריאה');
    ok(/בדיקה מיידית/.test(lt), 'הבדיקה המיידית');
    ok(!(await page.$$eval('.co button', bs => bs.some(b => /הדלק|כבה/.test(b.textContent)))), 'אין כפתור הדלקה / כיבוי');
    await page.unroute('**/api.php?action=cutover');

    // ════ ה. טלפון ════
    console.log('\n── ה. טלפון ──');
    const ph = await open('owner', 390);
    await ph.goto(base + '/#/publish');
    await ready(ph);
    const over = await ph.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok(over <= 0, '390px — בלי גלילה אופקית', `${over}px`);
    ok(errors.length === 0, '0 שגיאות קונסול', errors.slice(0, 3).join(' | '));
  } catch (e) {
    failed++;
    console.log('❌ ' + e.message);
  } finally {
    if (browser) await browser.close();
    php.kill(); wpPhp.kill();
    fs.rmSync(data, { recursive: true, force: true }); fs.rmSync(wpDir, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
