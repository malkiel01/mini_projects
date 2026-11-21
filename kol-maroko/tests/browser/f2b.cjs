/**
 * פרוסה F2b — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/f2b.cjs
 *
 * העברה ("העבר אל…" וגרירה) עם עדכון הפניות; שכפול; מחיקה עם רשימת הפניות; גרסאות — השוואה ושחזור; חיפוש בכל הקבצים
 * עם קפיצה לשורה, והחלפה עם תצוגה מקדימה; ZIP — הורדה, העלאה עם תצוגה של מה ישתנה, ו-ZIP עוין שנדחה. שם קובץ ותוכן
 * עוינים מוצגים כטקסט בלבד. 1280px ו-390px. רץ מול tests/router.php ומסד זמני — data/ לא נפתחת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net'), zlib = require('zlib');

const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.KOL_SHOTS || '';
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

/** ZIP מינימלי (stored) — כדי לבנות גם ZIP עוין. */
function zipOf(entries) {
  const crcT = []; for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; crcT[n] = c >>> 0; }
  const crc = b => { let c = 0xFFFFFFFF; for (const x of b) c = crcT[(c ^ x) & 0xFF] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; };
  const locals = [], cents = []; let off = 0;
  for (const [name, data] of entries) {
    const n = Buffer.from(name), d = Buffer.from(data), c = crc(d);
    const lh = Buffer.alloc(30); lh.writeUInt32LE(0x04034b50, 0); lh.writeUInt16LE(20, 4); lh.writeUInt16LE(0x0800, 6); lh.writeUInt32LE(c, 14); lh.writeUInt32LE(d.length, 18); lh.writeUInt32LE(d.length, 22); lh.writeUInt16LE(n.length, 26);
    const ch = Buffer.alloc(46); ch.writeUInt32LE(0x02014b50, 0); ch.writeUInt16LE(20, 4); ch.writeUInt16LE(20, 6); ch.writeUInt16LE(0x0800, 8); ch.writeUInt32LE(c, 16); ch.writeUInt32LE(d.length, 20); ch.writeUInt32LE(d.length, 24); ch.writeUInt16LE(n.length, 28); ch.writeUInt32LE(off, 42);
    locals.push(lh, n, d); cents.push(ch, n); off += 30 + n.length + d.length;
  }
  const cd = Buffer.concat(cents), end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(entries.length, 8); end.writeUInt16LE(entries.length, 10); end.writeUInt32LE(cd.length, 12); end.writeUInt32LE(off, 16);
  return Buffer.concat([...locals, cd, end]);
}

(async () => {
  void zlib;
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-f2b-'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const foreign = [];
    const mk = async (width, height) => {
      const ctx = await browser.newContext({ viewport: { width, height }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) }, acceptDownloads: true });
      ctx.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !/^(data|blob):/.test(u)) foreign.push(u); });
      return ctx;
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const idOf = async (page, p) => (await call(page, 'pf_tree')).files.find(f => f.path === p)?.id;
    const read = async (page, p) => (await call(page, 'pf_read', null, `&id=${await idOf(page, p)}`)).content;

    const ctx = await mk(1280, 860), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    for (const [p, c] of [['b/page.html', '<link href="c.css"><img src="img/p.png" alt="">\n<p>needle שלום</p>\n'], ['b/c.css', 'body{}'], ['b/img/p.png', '\u0089PNG'],
      ['b/x.js', "import y from './y.js';\nneedle()\n"], ['b/y.js', 'export default 1'], ['<img src=x onerror=window.pwned=1>.html', '<b>needle</b> <script>window.pwned=2</script>']]) {
      await call(page, 'pf_create', { path: p, content: c });
    }
    await call(page, 'pf_mkdir', { path: 'dest' });
    await page.reload();
    await page.waitForSelector('.pj-tree .pj-row');
    const row = name => page.locator('.pj-tree .pj-row', { has: page.locator('.pj-name .one', { hasText: new RegExp('^' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$') }) }).first();
    const rowMenu = async (name, item) => { await row(name).locator('.pj-more').click(); await page.click(`#sheet .pj-menu button:has-text("${item}")`); };

    // ── העברה דרך "העבר אל…", עם עדכון הפניות; הקובץ המפנה פתוח בעורך — מתעדכן ─────
    await row('page.html').locator('.pj-name').click();
    await page.waitForSelector('.pj-ed .monaco-editor .view-line');
    await rowMenu('p.png', 'העבר אל');
    await page.click('#sheet [data-move-to="dest"]');
    await page.waitForSelector('#sheet [data-refs-update]');
    const refsText = await page.textContent('#sheet .pj-refs');
    ok(refsText.includes('b/page.html:1') && refsText.includes('img/p.png') && refsText.includes('../dest/p.png'), 'לפני ההעברה: רשימת ההפניות, עם הערך החדש');
    await page.click('#sheet [data-refs-update]');
    await page.waitForFunction(() => Project._state.files.some(f => f.path === 'dest/p.png'));
    await sleep(300);
    ok((await read(page, 'b/page.html')).includes('src="../dest/p.png"'), 'ההפניה עודכנה בקובץ');
    await page.waitForFunction(() => Project._state.editor.getModel().getValue().includes('../dest/p.png'), null, { timeout: 5000 }).catch(() => {});
    ok(await page.evaluate(() => Project._state.editor.getModel().getValue().includes('../dest/p.png')), 'הקובץ הפתוח בעורך התעדכן (בלי לסגור ולפתוח)');
    ok(await page.evaluate(() => { const m = Project._state.editor.getModel(); m.undo(); const v = m.getValue(); m.redo(); return v.includes('img/p.png'); }), 'העדכון הוא צעד שאפשר לבטל בעורך');

    // ── גרירה במחשב: x.js אל dest ─────
    await row('x.js').dragTo(row('dest'));
    await page.waitForSelector('#sheet [data-refs-update]', { timeout: 5000 }).catch(() => {});
    if (await page.isVisible('#sheet [data-refs-update]')) await page.click('#sheet [data-refs-update]');
    await page.waitForFunction(() => Project._state.files.some(f => f.path === 'dest/x.js'), null, { timeout: 5000 }).catch(() => {});
    ok(await page.evaluate(() => Project._state.files.some(f => f.path === 'dest/x.js')), 'גרירה של שורה אל תיקייה — העברה');
    ok((await read(page, 'dest/x.js')).startsWith("import y from '../b/y.js'"), 'ההפניות היחסיות של קובץ שזז — מעודכנות מהמקום החדש');

    // ── שכפול ─────
    await rowMenu('c.css', 'שכפול');
    await page.waitForSelector('#sheet input');
    ok((await page.inputValue('#sheet input')) === 'b/c (עותק).css', 'שם מוצע לעותק');
    await page.click('#sheet .row-btns .btn.primary');
    await page.waitForFunction(() => Project._state.files.some(f => f.path === 'b/c (עותק).css'));
    ok((await read(page, 'b/c (עותק).css')) === 'body{}', 'שכפול — אותו תוכן');

    // ── מחיקה עם הפניות: רשימה, ולא חוסם ─────
    await rowMenu('c.css', 'מחיקה לסל');
    await page.waitForSelector('#sheet [data-refs-delete]');
    ok((await page.textContent('#sheet .pj-refs')).includes('b/page.html:1'), 'מחיקה: מי מפנה לקובץ');
    await page.click('#sheet [data-refs-delete]');
    await page.waitForFunction(() => !Project._state.files.some(f => f.path === 'b/c.css'));
    ok(true, 'מחיקה בכל זאת — עבר לסל');

    // ── גרסאות: השוואה ושחזור; תוכן עוין מוצג כטקסט ─────
    const hostile = '<img src=x onerror="window.pwned=3"><script>window.pwned=4</script>';
    await row('page.html').locator('.pj-name').click();
    await page.waitForFunction(() => Project._state.byId.get(Project._state.cur)?.path === 'b/page.html');
    await page.evaluate(t => Project._state.editor.getModel().setValue(t), hostile + '\n<p>needle שלום</p>\n');
    await page.keyboard.press('Control+s');
    await page.waitForFunction(() => document.querySelector('.pj-status').textContent.includes('✓'));
    await rowMenu('page.html', 'גרסאות');
    await page.waitForSelector('#sheet [data-ver-diff]');
    const diffs = await page.$$('#sheet [data-ver-diff]');
    await diffs[diffs.length - 1].click();
    await page.waitForSelector('#sheet .pj-diffv .pj-dl.add');
    const dtext = await page.textContent('#sheet .pj-diffv');
    ok(dtext.includes('window.pwned=3') && await page.$('#sheet .pj-diffv img') === null, 'השוואה: התוכן העוין מוצג כטקסט (אין <img> במסך)');
    ok(await page.$$eval('#sheet .pj-diffv .pj-dl.del', e => e.length) >= 1 && await page.$$eval('#sheet .pj-diffv .pj-dl.add', e => e.length) >= 1, 'השוואה: שורות שהוסרו ושנוספו מסומנות');
    await page.click('#sheet .row-btns [data-ver-restore]');
    await page.waitForFunction(() => Project._state.editor.getModel().getValue().startsWith('<link href="c.css">'), null, { timeout: 5000 });
    ok((await read(page, 'b/page.html')).startsWith('<link href="c.css"><img src="img/p.png"'), 'שחזור: התוכן של הגרסה הראשונה חזר');
    await page.waitForSelector('#sheet [data-ver-diff]');
    ok((await page.textContent('#sheet .pj-ver-list li')).includes('שוחזר מגרסה 1'), 'שחזור יצר גרסה חדשה, וההיסטוריה נשארה');
    await page.keyboard.press('Escape');

    // ── חיפוש בכל הקבצים, קפיצה לשורה ─────
    await page.click('[data-search]');
    await page.fill('#sheet [data-s-q]', 'needle');
    await page.press('#sheet [data-s-q]', 'Enter');
    await page.waitForSelector('#sheet [data-s-total]');
    ok(+(await page.getAttribute('#sheet [data-s-total]', 'data-s-total')) === 3, 'חיפוש: שלוש התאמות בשלושה קבצים');
    ok((await page.textContent('#sheet .pj-sres')).includes('<img src=x onerror=window.pwned=1>.html') && (await page.$('#sheet .pj-sres img')) === null, 'שם קובץ עוין בתוצאות — כטקסט');
    await page.click('#sheet [data-hit="dest/x.js:2"]');
    await page.waitForFunction(() => Project._state.byId.get(Project._state.cur)?.path === 'dest/x.js' && Project._state.editor.getPosition().lineNumber === 2, null, { timeout: 5000 });
    ok(await page.evaluate(() => Project._state.editor.getModel().getValueInRange(Project._state.editor.getSelection()) === 'needle'), 'קפיצה לשורה, וההתאמה מסומנת');

    // ── החלפה בכל הקבצים: תצוגה מקדימה, הורדת סימון מקובץ, ביצוע ─────
    await page.click('[data-search]');
    await page.fill('#sheet [data-s-q]', 'needle');
    await page.fill('#sheet [data-s-with]', 'סיכה');
    await page.click('#sheet [data-s-preview]');
    await page.waitForSelector('#sheet [data-s-apply]');
    ok((await read(page, 'dest/x.js')).includes('needle'), 'התצוגה המקדימה לא כתבה');
    await page.uncheck('#sheet [data-s-file="dest/x.js"]');
    ok((await page.textContent('#sheet [data-s-apply]')).includes('2'), 'הכפתור סופר את הקבצים המסומנים');
    await page.click('#sheet [data-s-apply]');
    await page.waitForSelector('#sheet [data-s-total]');
    ok((await read(page, 'b/page.html')).includes('סיכה') && (await read(page, 'dest/x.js')).includes('needle'), 'החלפה — רק בקבצים המסומנים');
    await page.keyboard.press('Escape');
    ok(await page.evaluate(() => window.pwned === undefined), 'שום תוכן או שם עוין לא רץ במקור של הפלטפורמה');

    // ── ZIP: הורדה ─────
    await page.click('[data-zip]');
    const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#sheet .pj-menu button:has-text("הורדת")')]);
    const zpath = path.join(data, 'dl.zip');
    await dl.saveAs(zpath);
    const zbuf = fs.readFileSync(zpath);
    ok(zbuf.slice(0, 2).toString() === 'PK' && zbuf.includes(Buffer.from('dest/p.png')) && zbuf.includes(Buffer.from('from-current/')), 'הורדת ZIP — עם כל הקבצים, כולל from-current/');

    // ── ZIP: העלאה — קודם מה ישתנה; אחרי אישור — הקובץ חוזר ─────
    await call(page, 'pf_write', { id: await idOf(page, 'b/y.js'), rev: (await call(page, 'pf_read', null, `&id=${await idOf(page, 'b/y.js')}`)).file.rev, content: 'export default 2' });
    const upload = async buf => {
      const fp = path.join(data, 'up-' + Math.random().toString(36).slice(2) + '.zip');
      fs.writeFileSync(fp, buf);
      await page.click('[data-zip]');
      const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('#sheet .pj-menu button:has-text("העלאת")')]);
      await chooser.setFiles(fp);
    };
    await upload(zbuf);
    await page.waitForSelector('#sheet [data-zip-apply]');
    ok((await page.textContent('#sheet [data-zip-list="replace"]')).includes('b/y.js'), 'תצוגת הייבוא: מה יוחלף');
    ok(!!(await page.$('#sheet [data-zip-list="same"]')), 'תצוגת הייבוא: מה זהה');
    ok((await read(page, 'b/y.js')) === 'export default 2', 'התצוגה לא כתבה');
    await page.click('#sheet [data-zip-apply]');
    await page.waitForFunction(() => !document.querySelector('#sheet').open, null, { timeout: 5000 });
    ok((await read(page, 'b/y.js')) === 'export default 1', 'אחרי הייבוא — הקובץ חזר לגרסה שב-ZIP');

    // ── ZIP עוין: נדחה כולו ─────
    await upload(zipOf([['ok.txt', 'a'], ['../../evil.php', '<?php']]));
    await page.waitForSelector('#sheet [data-zip-error]');
    ok((await page.textContent('#sheet [data-zip-error]')).includes('נדחה כולו'), 'ZIP עם ../ — נדחה כולו, בהודעה');
    ok(!(await call(page, 'pf_tree')).files.some(f => f.path === 'ok.txt'), 'ושום דבר ממנו לא נכתב');
    await page.keyboard.press('Escape');
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'f2b-1280.png') });
    ok(errors.length === 0, '1280px: אין שגיאות JavaScript' + (errors.length ? ' · ' + errors.join(' | ') : ''));
    await ctx.close();

    // ── 390px: כל הפעולות החדשות מתפריט השורה ובגיליון ─────
    const m = await mk(390, 760), mp = await m.newPage();
    const merr = [];
    mp.on('pageerror', e => merr.push(e.message));
    mp.on('dialog', d => d.accept());
    await mp.goto(base + '/#/project');
    await mp.waitForSelector('.pj-tree .pj-row');
    const mrow = name => mp.locator('.pj-tree .pj-row', { has: mp.locator('.pj-name .one', { hasText: new RegExp('^' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$') }) }).first();
    await mrow('y.js').locator('.pj-more').click();
    const items = await mp.$$eval('#sheet .pj-menu button', b => b.map(x => x.textContent));
    ok(['גרסאות', 'העבר אל', 'שכפול', 'שינוי שם', 'מחיקה'].every(w => items.some(t => t.includes(w))), '390px: העבר אל…, שכפול וגרסאות — בתפריט השורה');
    await mp.click('#sheet .pj-menu button:has-text("העבר אל")');
    await mp.click('#sheet [data-move-to=""]');
    await mp.waitForSelector('#sheet [data-refs-update]');
    await mp.click('#sheet [data-refs-update]');
    await mp.waitForFunction(() => Project._state.files.some(f => f.path === 'y.js'), null, { timeout: 5000 });
    ok((await read(mp, 'dest/x.js')).startsWith("import y from '../y.js'"), '390px: העברה לשורש עם עדכון ההפניה');
    ok(await mp.isVisible('[data-search]') && await mp.isVisible('[data-zip]'), '390px: חיפוש ו-ZIP זמינים בגיליון הקבצים');
    await mp.click('[data-search]');
    await mp.fill('#sheet [data-s-q]', 'needle');
    await mp.press('#sheet [data-s-q]', 'Enter');
    await mp.waitForSelector('#sheet [data-s-total]');
    const overflow = await mp.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    ok(overflow <= 1, '390px: אין גלילה אופקית בחיפוש');
    if (SHOTS) await mp.screenshot({ path: path.join(SHOTS, 'f2b-390-search.png') });
    ok(merr.length === 0, '390px: אין שגיאות JavaScript' + (merr.length ? ' · ' + merr.join(' | ') : ''));
    await m.close();
    ok(foreign.length === 0, 'שום בקשה לשרת אחר' + (foreign.length ? ' · ' + [...new Set(foreign)].slice(0, 3).join(', ') : ''));
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
