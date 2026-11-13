/**
 * פרוסה N3 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/n3.cjs
 *
 * 📚 נתונים ← ➕ רשימה חדשה "פתגמים" (מפתח נגזר) ← 🧩 שדה בחירה ← שלושה פריטים ← עריכה בתא ← גרירה בעכבר ובמגע ←
 * ➕ הוסף לעמוד ← בתצוגה החיה: הכותרת, שלושת הפריטים, החיפוש מוצא אחד, הסינון עובד ← "גרסאות" של העמוד מחזירות אותו בלי הקטע.
 * שם עוין — טקסט. 1440px (כל רוחב המסך) ו-390px (מגע). רץ מול tests/router.php ומסד זמני — data/ לא נפתחת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, extra) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (extra !== undefined ? ' — ' + JSON.stringify(extra).slice(0, 300) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-n3-'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const foreign = [];
    const mk = async (o) => {
      const ctx = await browser.newContext({ extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) }, ...o });
      ctx.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !/^(data|blob):/.test(u)) foreign.push(u); });
      return ctx;
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const items = async page => (await call(page, 'list_items', null, '&list=proverbs')).items;
    const titles = async page => (await items(page)).map(x => x.data.title);

    const ctx = await mk({ viewport: { width: 1440, height: 900 } }), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    let promptAnswer = null;
    page.on('dialog', d => (d.type() === 'prompt' ? d.accept(promptAnswer ?? '') : d.accept()));
    await page.goto(base + '/#/project');                               // הפרויקט נוצר (kol.json, index.html)
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'word_save', { word: 'שלום', meaning: '<p>פ</p>', topics: [] });
    const wordsBefore = (await call(page, 'pf_read', null, '&id=-1')).content;
    ok((await page.textContent('#tabs')).includes('📚 נתונים'), 'הלשונית — "📚 נתונים"');
    await page.evaluate(() => { location.hash = '#/words'; });
    await page.waitForSelector('.d-lists [data-list="words"]');
    await page.waitForSelector('.w-table tbody tr');
    ok(await page.getAttribute('.d-lists [data-list="words"]', 'aria-pressed') === 'true', 'המילים — הרשימה הראשונה, נבחרת');
    // T2 (E2E ⚪8): "+ מילה" ← הלשונית "מילים" מתעדכנת יחד עם הסיכום, בלי רענון
    { const cnt = () => page.evaluate(() => ({ tab: document.querySelector('.d-lists [data-list="words"] small').textContent.trim(), sum: document.querySelector('.summary b').textContent.trim() }));
      const c0 = await cnt();
      await page.click('button.btn.primary:has-text("מילה")'); await page.waitForSelector('dialog[open] input[required]');
      await page.fill('dialog[open] input[required]', 'בדיקת-מונה'); await page.click('dialog[open] button:has-text("✓ שמירה")');
      await page.waitForFunction(n => document.querySelector('.summary b').textContent.trim() === String(n), Number(c0.sum) + 1, { timeout: 8000 }).catch(() => {});
      const c1 = await cnt();
      ok(c0.tab === c0.sum && c1.sum === String(Number(c0.sum) + 1) && c1.tab === c1.sum, '"+ מילה": הלשונית "מילים" והסיכום — אותו מספר, לפני ואחרי (' + c0.tab + ' ← ' + c1.tab + ')', { c0, c1 });
      const id = (await call(page, 'words')).words.find(w => w.word === 'בדיקת-מונה')?.id; if (id) await call(page, 'word_delete', { id }); }

    // ── ➕ רשימה חדשה ─────────────────────────────────────────
    await page.click('[data-new-list]');
    await page.fill('[data-l-label]', 'פתגמים');
    await page.fill('[data-l-item]', 'פתגם');
    const derived = await page.inputValue('[data-l-key]');
    ok(/^[a-z][a-z0-9_]*$/.test(derived), 'מפתח נגזר מהשם', derived);
    await page.fill('[data-l-key]', 'proverbs');
    ok(await page.inputValue('[data-l-file]') === 'data/proverbs.json', 'הקובץ — data/<key>.json');
    await page.click('[data-l-create]');
    await page.waitForSelector('.d-lists [data-list="proverbs"][aria-pressed="true"]');
    await page.waitForSelector('[data-add-item]');
    ok((await page.textContent('[data-add-item]')).includes('פתגם'), 'הכפתור — "+ פתגם"');
    const tree = (await call(page, 'pf_tree')).files.find(f => f.path === 'data/proverbs.json');
    ok(tree && tree.linked === 'list', 'הקובץ בעץ — מקושר לטבלה', tree);

    // ── 🧩 מבנה: שדה בחירה "מקור" ─────────────────────────────
    await page.click('[data-structure]');
    await page.waitForSelector('#sheet .f-list .f-row[data-key="title"]');
    await page.click('#sheet [data-add-field]');
    await page.fill('#sheet label.f:has-text("השם") input', 'מקור');
    await page.fill('#sheet [data-f-key]', 'source');
    await page.selectOption('#sheet [data-f-type]', 'choice');
    await page.fill('#sheet textarea', 'morocco | מרוקו\ntalmud | תלמוד');
    await page.click('#sheet [data-f-save]');
    await page.waitForSelector('#sheet .f-row[data-key="source"]');
    await page.click('#sheet [data-close]');
    await page.waitForSelector('.l-table th[data-col="source"], [data-add-item]');

    // ── שלושה פריטים ───────────────────────────────────────────
    for (const [t, s] of [['מי שמחכה מקבל', 'morocco'], ['אל תעשה לחברך', 'talmud'], ['ההר והעכבר', 'morocco']]) {
      await page.click('[data-add-item]');
      await page.fill('#sheet [data-new="title"]', t);
      await page.selectOption('#sheet [data-new="source"]', s);
      await page.click('#sheet [data-new-save]');
      await page.waitForFunction(n => document.querySelectorAll('.l-table tbody tr').length === n, (await page.$$('.l-table tbody tr')).length + 1);
    }
    ok((await titles(page)).join('|') === 'מי שמחכה מקבל|אל תעשה לחברך|ההר והעכבר', 'שלושה פריטים, בסדר ההוספה');
    ok((await items(page)).map(x => x.id).join() === '1,2,3', 'id מ-1');
    // עריכה בתא
    await page.fill('tr[data-item="3"] [data-field="title"]', 'ההר והעכבר!');
    await page.press('tr[data-item="3"] [data-field="title"]', 'Tab');
    await page.waitForFunction(() => true);
    await sleep(300);
    ok((await titles(page))[2] === 'ההר והעכבר!', 'עריכה בתא נשמרת');

    // ── גרירה בעכבר: השלישי ← לפני הראשון ─────────────────────
    const g3 = await page.$('tr[data-item="3"] .l-grip'), r1 = await page.$('tr[data-item="1"]');
    const a = await g3.boundingBox(), b = await r1.boundingBox();
    await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
    await page.mouse.down();
    for (let i = 1; i <= 8; i++) { await page.mouse.move(a.x + a.width / 2, a.y + (b.y + 4 - a.y) * i / 8); await sleep(30); }
    await page.mouse.up();
    await page.waitForFunction(() => document.querySelector('.l-table tbody tr')?.dataset.item === '3');
    ok((await items(page)).map(x => x.id).join() === '3,1,2', 'גרירה בעכבר — הסדר נשמר');
    // מקלדת: חץ למטה
    await page.focus('tr[data-item="3"] .l-grip');
    await page.keyboard.press('ArrowDown');
    await page.waitForFunction(() => document.querySelector('.l-table tbody tr')?.dataset.item === '1');
    ok((await items(page)).map(x => x.id).join() === '1,3,2', 'מקלדת — חץ למטה');
    const wide = await page.evaluate(() => document.querySelector('main').getBoundingClientRect().width);
    ok(wide > 1300, 'במחשב — הטבלה על כל רוחב המסך', wide);

    // ── שם עוין — טקסט ─────────────────────────────────────────
    await call(page, 'list_save', { key: 'proverbs', label: '<img src=x onerror=window.__x=1>"פתגמים"' });
    await page.evaluate(() => { location.hash = '#/project'; });
    await page.waitForSelector('.pj-tree .pj-row');
    await page.evaluate(() => { location.hash = '#/words'; });
    await page.waitForSelector('.d-lists [data-list="proverbs"][aria-pressed="true"]');
    await page.waitForSelector('.l-table tbody tr');
    ok(await page.evaluate(() => !window.__x && !document.querySelector('.d-lists img')), 'שם עוין — טקסט, לא HTML');
    await call(page, 'list_save', { key: 'proverbs', label: 'פתגמים' });

    // ── ➕ הוסף לעמוד ───────────────────────────────────────────
    const indexId = (await call(page, 'pf_tree')).files.find(f => f.path === 'index.html').id;
    const vBefore = (await call(page, 'pf_versions', null, '&id=' + indexId)).versions.length;
    await page.click('[data-place]');
    await page.waitForSelector('#sheet [data-place-page]');
    ok(await page.inputValue('#sheet [data-place-page]') === 'index.html', 'העמוד — ברירת המחדל index.html');
    await page.selectOption('#sheet [data-place-where]', 'main');
    await page.click('#sheet [data-place-go]');
    await page.waitForFunction(() => !document.querySelector('#sheet')?.open);
    const html = (await call(page, 'pf_read', null, '&id=' + indexId)).content;
    ok(html.includes('data-kol-list="proverbs"') && html.includes('data-kol-filter="proverbs:source"') && html.includes('js/kol-data.js'), 'הקטע בעמוד, עם הסינון ו-kol-data.js');
    const v = (await call(page, 'pf_versions', null, '&id=' + indexId)).versions;
    ok(v.length === vBefore + 1 && v[0].note.includes('הוסף את הרשימה'), 'גרסה בהיסטוריה של העמוד', v[0]);

    // ── בתצוגה החיה ────────────────────────────────────────────
    const tok = (await call(page, 'pv_token')).token;
    const live = await ctx.newPage();
    live.on('pageerror', e => errors.push('live: ' + e.message));
    await live.goto(`${base}/preview/${tok}/`);
    await live.waitForFunction(() => document.querySelectorAll('[data-kol-list="proverbs"] article').length === 3, null, { timeout: 8000 }).catch(() => {});
    ok(await live.locator('h2.kol-list-proverbs__title').textContent() === 'פתגמים', 'הכותרת');
    const arts = await live.$$eval('[data-kol-list="proverbs"] article h3', a => a.map(x => x.textContent));
    ok(arts.join('|') === 'מי שמחכה מקבל|ההר והעכבר!|אל תעשה לחברך', 'שלושת הפריטים, בסדר של הטבלה', arts);
    await live.fill('[data-kol-search="proverbs"]', 'עכבר');
    await live.waitForFunction(() => [...document.querySelectorAll('[data-kol-list="proverbs"] article')].filter(x => x.offsetParent).length === 1);
    ok(true, 'החיפוש מוצא אחד');
    await live.fill('[data-kol-search="proverbs"]', '');
    await live.waitForFunction(() => [...document.querySelectorAll('[data-kol-list="proverbs"] article')].filter(x => x.offsetParent).length === 3);
    const btn = live.locator('[data-kol-filter="proverbs:source"] button', { hasText: 'תלמוד' });
    ok(await btn.count() === 1, 'כפתור סינון עם השם (טבלת התוויות)');
    await btn.click();
    await live.waitForFunction(() => [...document.querySelectorAll('[data-kol-list="proverbs"] article')].filter(x => x.offsetParent).length === 1);
    ok((await live.$$eval('[data-kol-list="proverbs"] article h3', a => a.filter(x => x.offsetParent).map(x => x.textContent))).join() === 'אל תעשה לחברך', 'הסינון עובד');
    await live.close();

    // ── "גרסאות" של העמוד: השחזור מחזיר אותו בלי הקטע (Ctrl+Z בעורך — ראו היומן) ──
    const prev = (await call(page, 'pf_versions', null, '&id=' + indexId)).versions[1];
    await call(page, 'pf_version_restore', { id: indexId, hid: prev.hid, rev: (await call(page, 'pf_read', null, '&id=' + indexId)).file.rev });
    ok(!(await call(page, 'pf_read', null, '&id=' + indexId)).content.includes('data-kol-list="proverbs"'), 'שחזור הגרסה הקודמת — הקטע הוסר');

    // ── המילים לא השתנו ────────────────────────────────────────
    ok((await call(page, 'pf_read', null, '&id=-1')).content === wordsBefore, 'data/words.json זהה בבייטים');

    // ── 390px, מגע: גרירה באצבע ────────────────────────────────
    const tctx = await mk({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true, deviceScaleFactor: 1 });
    const tp = await tctx.newPage();
    tp.on('pageerror', e => errors.push('touch: ' + e.message));
    await tp.goto(base + '/#/words');
    await tp.waitForSelector('.d-lists [data-list="proverbs"]');
    await tp.click('.d-lists [data-list="proverbs"]');
    await tp.waitForSelector('.l-table tbody tr[data-item="2"]');
    const before = (await call(tp, 'list_items', null, '&list=proverbs')).items.map(x => x.id).join();
    const tg = await (await tp.$('tr[data-item="2"] .l-grip')).boundingBox(), tr1 = await (await tp.$('tr[data-item="1"]')).boundingBox();
    const cdp = await tctx.newCDPSession(tp);
    const pt = (x, y) => [{ x, y, id: 1 }];
    const x0 = tg.x + tg.width / 2, y0 = tg.y + tg.height / 2, y1 = tr1.y + 4;
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pt(x0, y0) });
    for (let i = 1; i <= 10; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pt(x0, y0 + (y1 - y0) * i / 10) }); await sleep(30); }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await tp.waitForFunction(() => document.querySelector('.l-table tbody tr')?.dataset.item === '2', null, { timeout: 5000 }).catch(() => {});
    const after = (await call(tp, 'list_items', null, '&list=proverbs')).items.map(x => x.id).join();
    ok(before === '1,3,2' && after === '2,1,3', 'גרירה באצבע — הסדר נשמר', [before, after]);
    ok(await tp.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), '390px — בלי גלילה אופקית של העמוד');
    await tctx.close();

    ok(!errors.length, 'בלי שגיאות JS', errors);
    ok(!foreign.length, 'בלי פנייה לכתובת חיצונית', foreign.slice(0, 3));
  } catch (e) {
    failed++;
    console.log('❌ נפל: ' + (e.stack || e.message));
  } finally {
    await browser?.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
