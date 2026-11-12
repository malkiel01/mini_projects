/**
 * פרוסה N3b — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/n3b.cjs
 *
 * (1) הקלטות לפריטי רשימה ב-📼 הספרייה: רשימה "פתגמים" עם שדה שמע ← סינון "פתגמים · הקלטה" ← גרירת קובץ אמיתי על שורה "חסרה"
 *     (חיבור) ועל שורה מלאה (החלפה + גרסה) ← "גרסאות" ← "חזור" ← "העבר לפריט אחר…" ← מחיקה לסל ← שחזור — חזר לאותו פריט.
 *     בטלפון (390, מגע) — מתפריט השורה: "נתק מהפריט" ← משורה "חסרה" "🔗 חבר הקלטה מהספרייה". שם עוין — טקסט.
 * (2) "➕ הוסף לעמוד" ← Ctrl+Z — העמוד וה-CSS חוזרים בדיוק; ← שוב, ובטלפון "↶ בטל" באצבע; עמוד שהשתנה בינתיים — הביטול נעצר.
 * 1920 ו-390, צילומים (N3B_SHOTS, ברירת מחדל — תיקייה זמנית; הראיות ב-dev/n3b/). רץ מול tests/router.php ומסד זמני — data/ לא נפתחת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net'), crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
// הצילומים — לתיקייה זמנית (כדי שהרצה לא תלכלך את הריפו, כמו l1.cjs); N3B_SHOTS=kol-maroko/dev/n3b — לראיות ב-PR
const SHOTS = process.env.N3B_SHOTS ? path.resolve(process.env.N3B_SHOTS) : path.join(os.tmpdir(), 'kol-n3b-shots');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
const HOSTILE = '"><img src=x onerror=alert(1)>';
let failed = 0, ran = 0;
const ok = (cond, what, extra) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (extra !== undefined ? ' — ' + JSON.stringify(extra).slice(0, 400) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
/** mp3 קטן (כמו a3_bytes) — מזוהה לפי התוכן; salt — כדי שכל קובץ יהיה שונה. */
const mp3 = salt => Buffer.concat([Buffer.from('ID3\x03\x00\x00\x00\x00\x00\x00', 'latin1'),
  ...Array.from({ length: 5 }, () => Buffer.concat([Buffer.from([0xff, 0xfb, 0x90, 0x64]), Buffer.alloc(413)])), Buffer.from(salt)]);
const sha = b => crypto.createHash('sha256').update(b).digest('hex');

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-n3b-'));
  fs.mkdirSync(SHOTS, { recursive: true });
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, stdio: 'ignore',
    env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' } });
  let browser;
  const errors = [], foreign = [];
  const F = { a: mp3('first-' + Date.now()), b: mp3('second-' + Date.now()), c: mp3('third-' + Date.now()) };
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = async (width, height, touch = false) => {
      const c = await browser.newContext({ viewport: { width, height }, hasTouch: touch, isMobile: touch, deviceScaleFactor: 1,
        extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
      await c.addInitScript(() => { try { sessionStorage.setItem('kol-lib-tab', 'audio'); } catch { /* */ } });
      const p = await c.newPage();
      p.on('pageerror', e => errors.push(e.message));
      p.on('dialog', d => d.accept());
      p.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !/^(data|blob):/.test(u)) foreign.push(u); });
      return [c, p];
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q).catch(e => ({ error: e.message, status: e.status })), [action, body ?? null, query]);
    const lib = page => call(page, 'library');
    const slot = async (page, id) => (await lib(page)).rows.find(r => String(r.id) === String(id));
    const value = async (page, id, key = 'rec') => (await call(page, 'list_items', null, '&list=proverbs')).items.find(x => x.id === id)?.data?.[key] ?? null;
    const openLib = async page => { await page.goto(base + '/#/files'); await page.waitForSelector('.lib-list .lib-row, .lib-list + div, p.empty'); };
    const row = (page, id) => page.locator(`.lib-row[data-id="${id}"]`);
    const rowText = (page, id) => row(page, id).innerText().catch(() => '');
    const sheetText = page => page.$eval('#sheet-body', e => e.innerText).catch(() => '');
    const waitSheet = (page, re, timeout = 15000) => page.waitForFunction(r => new RegExp(r).test(document.querySelector('#sheet[open]')?.innerText || ''), re.source, { timeout });
    const sheetBtn = (page, re) => page.locator('#sheet-body button', { hasText: re }).first();
    const waitToast = (page, re, timeout = 15000) => page.waitForFunction(r => { const t = document.querySelector('.toast'); return t && !t.hidden && new RegExp(r).test(t.textContent); }, re.source, { timeout });
    const menu = async (page, id) => { await row(page, id).locator('.lib-more').click(); await page.waitForSelector('#sheet[open] .lib-acts'); };
    const filter = (page, label, v) => page.selectOption(`.lib-filters select[aria-label="${label}"]`, v);
    const dropFile = (page, selector, name, buf) => page.evaluate(([sel, n, b64]) => {
      const el = document.querySelector(sel);
      const bin = atob(b64), u = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
      const dt = new DataTransfer(); dt.items.add(new File([u], n, { type: 'audio/mpeg' }));
      el.dispatchEvent(new DragEvent('dragenter', { bubbles: true, dataTransfer: dt }));
      el.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
      const tip = document.querySelector('.lib-dragtip:not([hidden])')?.textContent || '';
      const hl = !!el.closest('.lib-row')?.classList.contains('lib-target');
      el.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
      return { tip, hl };
    }, [selector, name, buf.toString('base64')]);

    // ── הכנה: רשימה "פתגמים" עם שדה שמע, שלושה פריטים (אחד עם שם עוין) ──
    const [ctx, page] = await mk(1920, 1080);
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    ok(!(await call(page, 'list_create', { label: 'פתגמים', item_label: 'פתגם', key: 'proverbs' })).error, 'רשימה');
    ok(!(await call(page, 'field_save', { list: 'proverbs', key: 'rec', label: 'הקלטה', type: 'audio' })).error, 'שדה שמע');
    for (const t of ['מי שמחכה', 'אין עשן בלי אש', HOSTILE]) await call(page, 'list_item_save', { list: 'proverbs', data: { title: t } });

    // ── 1. 1920: הספרייה — שורות של הרשימה, הסינון "פתגמים · הקלטה" ──
    await openLib(page);
    await filter(page, 'מצב', 'all');
    await filter(page, 'שדה', 'proverbs.rec');
    await page.waitForSelector('.lib-row[data-id="proverbs:1:rec"]');
    const ids = await page.$$eval('.lib-list .lib-row', ls => ls.map(l => l.dataset.id));
    ok(ids.join() === 'proverbs:1:rec,proverbs:2:rec,proverbs:3:rec', 'סינון "פתגמים · הקלטה": שורה לכל פריט', ids);
    ok(/פתגמים · הקלטה/.test(await row(page, 'proverbs:1:rec').locator('.lib-c-field').innerText()), 'עמודת "שדה": "פתגמים · הקלטה"');
    ok(await row(page, 'proverbs:3:rec').locator('img').count() === 0 && (await rowText(page, 'proverbs:3:rec')).includes(HOSTILE), 'שם עוין — טקסט, לא HTML');
    const w = await page.evaluate(() => ({ list: Math.round(document.querySelector('.lib-list').getBoundingClientRect().width), w: innerWidth, doc: document.documentElement.scrollWidth }));
    ok(w.list >= w.w - 80 && w.doc <= w.w, '1920: על כל הרוחב', w);

    // ── 2. גרירת קובץ על שורה "חסרה" — חיבור; על שורה מלאה — החלפה + גרסה ──
    const d1 = await dropFile(page, '.lib-row[data-id="proverbs:1:rec"] .lib-main', 'p1.mp3', F.a);
    ok(d1.hl && /חבר להקלטה של "מי שמחכה"|חבר לפתגמים · הקלטה של "מי שמחכה"/.test(d1.tip), 'בזמן הגרירה: מודגש + "＋ חבר ל… של "מי שמחכה""', d1);
    await waitToast(page, /חוברה/, 20000);
    let s1 = await slot(page, 'proverbs:1:rec');
    ok(s1?.state === 'tool' && s1.file.sha === sha(F.a), 'חוברה — זהה בבייטים', s1);
    ok((await value(page, 1)) === s1.file.id, 'הערך בטבלה של הרשימה — מזהה הקובץ');
    const pj = (await call(page, 'pf_read', null, '&id=' + (await call(page, 'pf_tree')).files.find(f => f.path === 'data/proverbs.json').id)).content;
    ok(pj.includes('media/audio/proverbs-1-rec.mp3'), 'data/proverbs.json: media/audio/proverbs-1-rec.mp3');
    ok((await call(page, 'pf_tree')).files.some(f => f.path === 'media/audio/proverbs-1-rec.mp3'), 'נגישה גם מ-</> קוד (העץ)');
    await page.screenshot({ path: path.join(SHOTS, 'n3b-1920.png') });
    const d2 = await dropFile(page, '.lib-row[data-id="proverbs:1:rec"] .lib-main', 'p1-new.mp3', F.b);
    ok(/↻ החלף/.test(d2.tip), 'על שורה מלאה — "↻ החלף…"', d2);
    await waitToast(page, /הוחלפה/, 20000);
    s1 = await slot(page, 'proverbs:1:rec');
    ok(s1.file.sha === sha(F.b) && s1.versions === 1, 'הוחלפה; הקודמת — גרסה', s1);
    await menu(page, 'proverbs:1:rec');
    await sheetBtn(page, /גרסאות/).click();
    await waitSheet(page, /חזור/);
    await sheetBtn(page, /חזור/).click();
    await waitToast(page, /חזרנו לגרסה/);
    s1 = await slot(page, 'proverbs:1:rec');
    ok(s1.file.sha === sha(F.a), '"חזור לגרסה" — הקובץ הראשון חזר', s1);

    // ── 3. "העבר לפריט אחר…" — מפריט 1 לפריט 2 ──
    await menu(page, 'proverbs:1:rec');
    await sheetBtn(page, /העבר לפריט אחר/).click();
    await waitSheet(page, /#2 אין עשן בלי אש/);
    await page.locator('#sheet-body .lib-pickword button', { hasText: '#2 אין עשן בלי אש' }).click();
    await waitToast(page, /חוברה ל-#2/);
    ok((await value(page, 2)) === s1.file.id && (await value(page, 1)) === null, 'עברה לפריט 2; פריט 1 — בלי הקלטה');

    // ── 4. מחיקה לסל ← שחזור — חוזרת לאותו פריט ──
    await menu(page, 'proverbs:2:rec');
    await sheetBtn(page, /מחיקה לסל/).click();
    await waitSheet(page, /הפריט יישאר "בלי הקלטה"/);
    await sheetBtn(page, /^מחיקה לסל$/).click();
    await waitToast(page, /לסל|נמחקו|נמחקה/);
    ok((await value(page, 2)) === null, 'בסל — הפריט בלי הקלטה');
    await filter(page, 'מצב', 'trash');
    await page.waitForSelector('.lib-trash li');
    ok(/מפתגמים #2 אין עשן בלי אש · פתגמים · הקלטה/.test(await page.innerText('.lib-trash')), 'בסל: "מפתגמים #2 …"', await page.innerText('.lib-trash'));
    await page.locator('.lib-trash button', { hasText: 'שחזור' }).first().click();
    await waitToast(page, /שוחזרה/);
    ok((await value(page, 2)) === s1.file.id, 'שוחזרה — וחזרה לפריט 2');
    await ctx.close();

    // ── 5. 390, מגע: הכול מתפריט השורה — "נתק מהפריט" ← משורה חסרה "🔗 חבר הקלטה מהספרייה" ──
    const [tctx, tp] = await mk(390, 800, true);
    await openLib(tp);
    await filter(tp, 'מצב', 'all');
    await filter(tp, 'שדה', 'proverbs.rec');
    await tp.waitForSelector('.lib-row[data-id="proverbs:2:rec"] .lib-more');
    await tp.tap('.lib-row[data-id="proverbs:2:rec"] .lib-more');
    await tp.waitForSelector('#sheet[open] .lib-acts');
    await tp.locator('#sheet-body button', { hasText: 'נתק מהפריט' }).tap();
    await waitSheet(tp, /נתק/);
    await tp.locator('#sheet-body .row-btns button', { hasText: /^נתק$/ }).tap();
    await waitToast(tp, /נותקו 1/);
    ok((await value(tp, 2)) === null, 'טלפון: נותקה מהפריט');
    await tp.waitForSelector('.lib-row[data-id="proverbs:3:rec"] .lib-link');
    await tp.tap('.lib-row[data-id="proverbs:3:rec"] .lib-link');
    await waitSheet(tp, /חבר הקלטה/);
    const nameBox = await tp.locator('#sheet-body .lib-pickword .use-tx').first().boundingBox();
    ok(nameBox && nameBox.width > 80, '390: בבחירת הקלטה השם נראה (הנגן אינו מכסה אותו)', nameBox);
    await tp.locator('#sheet-body .lib-pickword button .use-tx').first().tap();
    await waitToast(tp, /חוברה/);
    ok((await value(tp, 3)) === s1.file.id, 'טלפון: "🔗 חבר הקלטה מהספרייה" — חוברה לפריט 3');
    ok(await tp.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), '390px — בלי גלילה אופקית');
    await tp.screenshot({ path: path.join(SHOTS, 'n3b-390.png') });

    // ── 6. "➕ הוסף לעמוד" ← Ctrl+Z ──
    const [pctx, pp] = await mk(1440, 900);
    await pp.goto(base + '/#/words');
    await pp.waitForFunction(() => typeof api === 'function');
    const tree = (await call(pp, 'pf_tree')).files;
    const index = tree.find(f => f.path === 'index.html'), cssF = tree.find(f => f.path === 'css/page.css');
    const before = (await call(pp, 'pf_read', null, '&id=' + index.id)).content;
    const cssBefore = cssF ? (await call(pp, 'pf_read', null, '&id=' + cssF.id)).content : null;
    const place = async p => {
      await p.goto(base + '/#/words');
      await p.waitForSelector('.d-lists [data-list="proverbs"]');
      await p.click('.d-lists [data-list="proverbs"]');
      await p.waitForSelector('[data-place]');
      await p.click('[data-place]');
      await p.waitForSelector('#sheet[open] [data-place-go]');
      await p.selectOption('#sheet [data-place-page]', 'index.html');
      await p.click('#sheet [data-place-go]');
      await p.waitForSelector('.l-undo [data-place-undo]');
    };
    await place(pp);
    ok((await call(pp, 'pf_read', null, '&id=' + index.id)).content.includes('data-kol-list="proverbs"'), 'נוסף לעמוד');
    ok(/נוסף ל-index\.html/.test(await pp.innerText('.l-undo')), 'הפס: "פתגמים" נוסף ל-index.html · ↶ בטל');
    await pp.screenshot({ path: path.join(SHOTS, 'n3b-undo-1440.png') });
    await pp.keyboard.press('Control+z');
    await waitToast(pp, /בוטל/);
    ok((await call(pp, 'pf_read', null, '&id=' + index.id)).content === before, 'Ctrl+Z — העמוד חזר בדיוק');
    const cssNow = (await call(pp, 'pf_tree')).files.find(f => f.path === 'css/page.css');
    ok(cssBefore === null ? !cssNow : (await call(pp, 'pf_read', null, '&id=' + cssNow.id)).content === cssBefore, 'וה-CSS');
    ok(await pp.locator('.l-undo').count() === 0, 'הפס נסגר');
    const hist = (await call(pp, 'pf_versions', null, '&id=' + index.id)).versions;
    ok(/^בוטל: הוסף את הרשימה פתגמים/.test(hist[0].note), 'בהיסטוריה: "בוטל: הוסף את הרשימה פתגמים" (ההיסטוריה לא נמחקה)', hist[0]);
    // העמוד השתנה בינתיים — הביטול נעצר, ושום דבר לא נדרס
    await place(pp);
    const cur = await call(pp, 'pf_read', null, '&id=' + index.id);
    const edited = cur.content.replace('</body>', '<!-- n3b --></body>');
    await call(pp, 'pf_write', { id: index.id, rev: cur.file.rev, content: edited });
    await pp.keyboard.press('Control+z');
    await waitToast(pp, /השתנה מאז ההוספה/);
    ok((await call(pp, 'pf_read', null, '&id=' + index.id)).content === edited, 'עמוד שהשתנה — הביטול נעצר (rev), שום דבר לא נדרס');
    await pctx.close();
    // בטלפון: "↶ בטל" באצבע
    await call(tp, 'pf_write', { id: index.id, rev: (await call(tp, 'pf_read', null, '&id=' + index.id)).file.rev, content: before });
    await place(tp);
    ok(await tp.evaluate(() => { const r = document.querySelector('.l-undo').getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }), '390: הפס בתוך המסך');
    await tp.tap('.l-undo [data-place-undo]');
    await waitToast(tp, /בוטל/);
    ok((await call(tp, 'pf_read', null, '&id=' + index.id)).content === before, 'טלפון: "↶ בטל" — העמוד חזר');
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
