/**
 * פרוסה P1 — אנשים ותפקידים, בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/p1.cjs
 *
 * לכל תפקיד, דרך הסטודיו האמיתי (tests/router.php) ו-provision מדומה (tests/fake-provision.php):
 *   בעל האתר  — "⚙️ בעל האתר": כל השורות, המתג "כל פרסום מחכה לאישורי", אופן ההצבה (עבר מ-🚀 פרסום), החיבור.
 *   בעל העמוד — "👥 אנשים" ← "הוסף עוזר" (מייל + שם + עורך תוכן) ← "נשלחה הזמנה"; שם עוין מוצג כטקסט; "⚙️ בעל האתר" — לקריאה.
 *   עורך תוכן — נכנס (ה-sub מהפלטפורמה), המילים פתוחות; הקוד — "לקריאה בלבד", ושמירה נדחית (403); בלי 👥 ו-⚙️.
 *   הסרה      — בעל העמוד מסיר ← בטעינה הבאה "הוסרת מהכלי".
 *   בלי תפקיד — "נכנסת. בעל העמוד עוד לא נתן לך תפקיד"; אצל בעל העמוד — ב"ביקשו גישה" ובפעמון.
 *   מפרסם     — בלי 👥 ובלי ⚙️.
 * צילומים: תיקייה זמנית (os.tmpdir()/kol-p1-shots, או KOL_SHOTS) — 1920 (כל המסך) ו-390.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
// T1: כמו l1.cjs — הצילומים לתיקייה זמנית, לא לריפו. KOL_SHOTS=<תיקייה> — מיקום אחר.
const SHOTS = process.env.KOL_SHOTS || path.join(os.tmpdir(), 'kol-p1-shots');
const USERS = {
  site: { sub: '501', name: 'בעל האתר', email: 'site@example.test', email_verified: true, platform_admin: true },
  page: { sub: '502', name: 'בעלת העמוד', email: 'page@example.test', email_verified: true },
  pub: { sub: '503', name: 'מפרסם', email: 'pub@example.test', email_verified: true },
  helper: { sub: '777', name: 'עוזרת', email: 'helper@example.test', email_verified: true },
  nobody: { sub: '504', name: 'מישהו חדש', email: 'new@example.test', email_verified: true },
};
const EVIL = '<img src=x onerror="window.__p1xss=1">עוזרת';
let failed = 0, ran = 0;
const ok = (cond, what, detail = '') => { ran++; if (cond) console.log('✅ ' + what + (detail ? ' — ' + detail : '')); else { failed++; console.log('❌ ' + what + (detail ? ' — ' + detail : '')); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-p1-'));
  const provFile = path.join(data, 'prov.json');
  fs.writeFileSync(provFile, JSON.stringify({ mode: 'invited', sub: '777' }));
  fs.mkdirSync(SHOTS, { recursive: true });
  const [port, provPort] = [await freePort(), await freePort()];
  const base = `http://127.0.0.1:${port}`;
  execFileSync('php', ['-r', `
    define('KOL_DATA_DIR', $argv[1]);
    foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'build'] as $l) require_once $argv[2] . "/lib/$l.php";
    foreach ([['502', 'page@example.test', 'בעלת העמוד', 'page_owner'], ['503', 'pub@example.test', 'מפרסם', 'publisher']] as $m)
      db()->prepare("INSERT INTO users (sub, email, name, role, status, created_at) VALUES (?, ?, ?, ?, 'active', ?)")->execute([...$m, now_iso()]);
    db()->exec("INSERT INTO words (word, meaning, updated_at) VALUES ('שלום', 'hello', '2026-01-01T00:00:00Z')");
  `, data, ROOT]);
  const env = { ...process.env, PHP_CLI_SERVER_WORKERS: '4' };
  const prov = spawn('php', ['-S', `127.0.0.1:${provPort}`, path.join(ROOT, 'tests', 'fake-provision.php')], { cwd: ROOT, env: { ...env, KOL_TEST_PROV: provFile }, stdio: 'ignore' });
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT,
    env: { ...env, KOL_TEST_DATA: data, KOL_TEST_PROVISION: `http://127.0.0.1:${provPort}/provision.php` }, stdio: 'ignore' });
  let browser;
  const errors = [];
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); await fetch(`http://127.0.0.1:${provPort}/`); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const open = async (who, width = 1280, height = 900) => {
      const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: width === 390 ? 2 : 1, acceptDownloads: true });
      await ctx.addCookies([{ name: 'kol_test_user', value: Buffer.from(JSON.stringify(USERS[who])).toString('base64'), url: base, httpOnly: true, sameSite: 'Lax' }]);
      const page = await ctx.newPage();
      page.on('pageerror', e => errors.push(who + ': ' + e.message));
      page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|status of 403/.test(m.text())) errors.push(who + ': ' + m.text()); });
      page.on('dialog', d => d.accept());
      return page;
    };
    const tabs = p => p.$$eval('#tabs a', els => els.map(e => e.textContent.trim()));
    const shot = async (p, name) => { await p.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }); };

    // ════ א. בעל האתר ════
    console.log('\n── א. בעל האתר ──');
    const site = await open('site', 1920, 1080);
    await site.goto(base + '/#/settings');
    await site.waitForSelector('#owner-rows', { timeout: 15000 });
    const rowsText = await site.textContent('#owner-rows');
    ok(['המפסק', 'המכסות', 'החיבור לאתר', 'גודל קובץ מקסימלי', 'בעל העמוד'].every(t => rowsText.includes(t)), 'כל ההגדרות של בעל האתר — במקום אחד');
    ok((await tabs(site)).includes('⚙️ בעל האתר') && (await tabs(site)).includes('👥 אנשים'), 'הלשוניות של בעל האתר');
    ok(await site.$('#owner-approval') !== null, 'המתג "כל פרסום מחכה לאישורי"');
    ok(await site.waitForSelector('#mount-block .mount-opt', { timeout: 8000 }).then(() => true).catch(() => false), 'אופן ההצבה — עבר ל"⚙️ בעל האתר"');
    ok(await site.waitForSelector('#owner-connection .keybox', { timeout: 8000 }).then(() => true).catch(() => false), 'החיבור לאתר (המפתח) — באותו מסך');
    // T1: ⬇️ הורד את התוסף (zip) — ליד אופן ההצבה; הקובץ שיורד הוא ZIP של wordpress-plugin/kol-maroko/
    const plugVer = (fs.readFileSync(path.join(ROOT, 'wordpress-plugin', 'kol-maroko', 'kol-maroko.php'), 'utf8').match(/^\s*\*\s*Version:\s*(\S+)/m) || [])[1];
    const [dl] = await Promise.all([site.waitForEvent('download', { timeout: 15000 }), site.click('#plugin-block [data-plugin-zip]')]);
    const dlPath = await dl.path(), dlBuf = fs.readFileSync(dlPath);
    ok(dl.suggestedFilename() === `kol-maroko-${plugVer}.zip` && dlBuf.slice(0, 2).toString() === 'PK' && dlBuf.includes(Buffer.from('kol-maroko/kol-maroko.php')),
      `⬇️ הורד את התוסף (zip) — ${dl.suggestedFilename()}, ${dlBuf.length} בייט, kol-maroko/kol-maroko.php בפנים`);
    ok(/גרסה/.test(await site.textContent('#plugin-block [role=status]')), 'ההורדה — אישור עם הגרסה ומה עושים בוורדפרס');
    await site.check('#owner-approval');
    await site.waitForFunction(() => /דולק/.test(document.querySelector('#owner-rows')?.textContent || ''), null, { timeout: 8000 }).catch(() => {});
    ok(/כל פרסום מחכה לאישור בעל האתר: דולק/.test(await site.textContent('#owner-rows')), 'המתג נשמר — "דולק"');
    await site.uncheck('#owner-approval');
    await site.waitForFunction(() => /כבוי/.test(document.querySelector('#owner-rows')?.textContent || ''), null, { timeout: 8000 }).catch(() => {});
    await shot(site, 'owner-site-1920');
    const pubPage = await open('pub');
    await pubPage.goto(base + '/#/publish');
    await pubPage.waitForSelector('#tabs a', { timeout: 15000 });
    const pt = await tabs(pubPage);
    ok(!pt.includes('👥 אנשים') && !pt.includes('⚙️ בעל האתר'), 'מפרסם — בלי 👥 ובלי ⚙️', pt.join(' '));

    // ════ ב. בעל העמוד מוסיף עוזרת ════
    console.log('\n── ב. בעל העמוד ──');
    const page = await open('page', 1920, 1080);
    await page.goto(base + '/#/members');
    await page.waitForSelector('.people-add', { timeout: 15000 });
    await page.fill('.people-add input[name=email]', 'helper@example.test');
    await page.fill('.people-add input[name=name]', EVIL);
    await page.selectOption('.people-add select', 'content_editor');
    await page.click('.people-add button[type=submit]');
    const toast = await page.waitForFunction(() => { const t = document.querySelector('.toast'); return t && !t.hidden && t.textContent; }, null, { timeout: 8000 }).then(h => h.jsonValue()).catch(() => '');
    ok(/נשלחה הזמנה ל-helper@example\.test, תקפה 7 ימים/.test(toast), '"הוסף עוזר" ← "נשלחה הזמנה"', toast);
    await page.waitForSelector('#people-list [data-email="helper@example.test"]', { timeout: 8000 });
    const row = page.locator('#people-list [data-email="helper@example.test"]');
    ok((await row.textContent()).includes(EVIL) && (await row.locator('img').count()) === 0, 'שם עוין מוצג כטקסט, בלי <img>');
    ok((await row.textContent()).includes('עורך תוכן'), 'התפקיד בשורה: עורך תוכן');
    ok(!(await page.evaluate(() => window.__p1xss)), 'שום סקריפט לא רץ מהשם');
    await shot(page, 'people-1920');
    const p390 = await open('page', 390, 844);
    await p390.goto(base + '/#/members');
    await p390.waitForSelector('#people-list .card', { timeout: 15000 });
    ok(await p390.evaluate(() => document.documentElement.scrollWidth <= 390), '390px: בלי גלילה לרוחב');
    await shot(p390, 'people-390');
    await p390.goto(base + '/#/settings');
    await p390.waitForSelector('#owner-rows', { timeout: 15000 });
    await shot(p390, 'owner-page-390');
    await page.goto(base + '/#/settings');
    await page.waitForSelector('#owner-rows', { timeout: 15000 });
    ok(await page.$('#owner-approval') === null && await page.$('#mount-block') === null && await page.$('#plugin-block') === null, 'בעל העמוד — הרשימה לקריאה, בלי מתגים, בלי החיבור ובלי הורדת התוסף');
    ok((await page.$$eval('.owner-list li', l => l.every(x => x.textContent.includes('נקבע בידי בעל האתר')))), 'כל שורה — "נקבע בידי בעל האתר"');
    await shot(page, 'owner-page-1920');

    // ════ ג. עורכת התוכן נכנסת ════
    console.log('\n── ג. עורך תוכן ──');
    const helper = await open('helper');
    await helper.goto(base + '/#/words');
    await helper.waitForSelector('#tabs a', { timeout: 15000 });
    ok(/עורך תוכן/.test(await helper.textContent('#who')), 'נכנסה — עורך תוכן', await helper.textContent('#who'));
    const ht = await tabs(helper);
    ok(!ht.includes('👥 אנשים') && !ht.includes('⚙️ בעל האתר'), 'בלי 👥 ובלי ⚙️');
    ok(await helper.isHidden('#ro-note'), 'המילים — בלי "לקריאה בלבד"');
    const ws = await helper.evaluate(async () => { try { await api('word_save', { word: 'מעורכת התוכן', meaning: 'x' }); return 200; } catch (e) { return e.status; } });
    ok(ws === 200, 'שומרת מילה', String(ws));
    await helper.goto(base + '/#/code');
    await helper.waitForSelector('#ro-note:not([hidden])', { timeout: 8000 }).catch(() => {});
    const ro = await helper.textContent('#ro-note');
    ok(/לקריאה בלבד/.test(ro) && /התפקיד שלך: עורך תוכן — מילים, הקלטות ופניות\. לשינוי עיצוב ופרסום — פנה לבעל העמוד/.test(ro), 'הקוד — "לקריאה בלבד" עם המשפט');
    const cs = await helper.evaluate(async () => { try { await api('pf_write', { id: 1, content: 'x', rev: 1 }); return 200; } catch (e) { return [e.status, e.message]; } });
    ok(Array.isArray(cs) && cs[0] === 403 && /עורך תוכן/.test(cs[1]), 'שמירת קוד — נדחית בשרת (403)', JSON.stringify(cs));
    const cr = await helper.evaluate(async () => { try { await api('code', undefined); return 200; } catch (e) { return e.status; } });
    ok(cr === 200, 'קריאת הקוד — מותרת');

    // ════ ד. בלי תפקיד ════
    console.log('\n── ד. בלי תפקיד ──');
    const nobody = await open('nobody');
    await nobody.goto(base + '/');
    await nobody.waitForSelector('.gate-msg', { timeout: 15000 });
    ok(/נכנסת\. בעל העמוד עוד לא נתן לך תפקיד/.test(await nobody.textContent('.gate-msg')), 'מסך אחד: "נכנסת. בעל העמוד עוד לא נתן לך תפקיד"');
    await page.goto(base + '/#/members');
    await page.reload();
    await page.waitForSelector('.people-add', { timeout: 15000 });
    ok(/ביקשו גישה/.test(await page.textContent('#main')) && /מישהו חדש/.test(await page.textContent('#main')), 'אצל בעל העמוד — ב"ביקשו גישה"');
    await page.waitForFunction(() => document.querySelector('#bell .n')?.textContent, null, { timeout: 8000 }).catch(() => {});
    const n = await page.textContent('#bell .n');
    ok(+n >= 1, 'הפעמון — מונה', n);
    await page.click('#bell');
    await page.waitForSelector('#bell-list', { timeout: 8000 });
    ok(/מישהו חדש/.test(await page.textContent('#bell-list')), 'בפעמון — מי שביקש גישה');
    await page.keyboard.press('Escape');

    // ════ ה. הסרה ════
    console.log('\n── ה. הסרה ──');
    await page.click('#people-list [data-email="helper@example.test"]');
    await page.waitForSelector('#sheet[open] .btn.danger', { timeout: 8000 });
    await page.click('#sheet[open] .btn.danger');
    await page.waitForFunction(() => !document.querySelector('#people-list [data-email="helper@example.test"]'), null, { timeout: 8000 }).catch(() => {});
    ok(!(await page.$('#people-list [data-email="helper@example.test"]')), 'הוסרה מהרשימה');
    const after = await helper.evaluate(async () => { try { await api('words'); return 200; } catch (e) { return e.status; } });
    ok(after === 403, 'הבקשה הבאה שלה — 403 (נזרקה מיד)', String(after));
    await helper.reload();
    await helper.waitForSelector('.gate-msg', { timeout: 15000 });
    ok(/הוסרת מהכלי/.test(await helper.textContent('.gate-msg')), 'בטעינה — "הוסרת מהכלי"');

    ok(!errors.length, 'בלי שגיאות JS', errors.slice(0, 5).join(' | '));
  } catch (e) {
    ok(false, 'הבדיקה נפלה', e.stack);
  } finally {
    if (browser) await browser.close();
    php.kill(); prov.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
