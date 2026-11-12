/**
 * פרוסה A3 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/a3.cjs
 *
 * הפעולות על הקלטה, מקצה לקצה: הקלטה שבאתר (מושבתות עם הסבר, "החלף" זמין); שינוי שם בשורה; החלפה בהעלאה ובהקלטה
 * מהדפדפן (האזנה, "הקלט שוב", שמירה) — הקודמת נשמרת כגרסה, "חזור לגרסה הזו"; ניתוק וחיבור (כולל התנגשות); מחיקה לסל,
 * שחזור שמחזיר את החיבור, מחיקה סופית באישור; הוספה של כמה קבצים — מסך אישור לפני כל חיבור (הותאמו / מתנגשים / לא
 * הותאמו / נדחו), וגם בגרירה; בחירה מרובה והורדה כ-ZIP (זהה בבייט, sha256) ואחת; כפולות; סינונים; עורך מול בעלים;
 * טלפון 390px (לחיצה ארוכה, תפריט שורה). ובכל הזמן — אף בקשה לא יוצאת לשום מקום חוץ מהכלי.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net'), crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
const EDITOR = { sub: 'u-editor', name: 'Editor Test', email: 'editor@example.test', email_verified: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 600) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const sha = buf => crypto.createHash('sha256').update(buf).digest('hex');

/** ZIP → { שם: sha256 } (פייתון, zipfile — קורא בדיוק את מה שכל תוכנה תקרא). */
const zipShas = file => JSON.parse(execFileSync('python3', ['-I', '-c',
  'import sys,json,zipfile,hashlib;z=zipfile.ZipFile(sys.argv[1]);assert z.testzip() is None;print(json.dumps({n:hashlib.sha256(z.read(n)).hexdigest() for n in z.namelist()}))', file]).toString());
const zipText = (file, name) => execFileSync('python3', ['-I', '-c', 'import sys,zipfile;sys.stdout.write(zipfile.ZipFile(sys.argv[1]).read(sys.argv[2]).decode("utf-8-sig"))', file, name]).toString();

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-a3-'));
  const up = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-a3-up-'));
  const S = JSON.parse(execFileSync('php', [path.join(__dirname, 'a3-seed.php'), data, up]).toString());
  const port = await freePort();
  const base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, stdio: 'ignore',
    env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' } });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] });
    const mk = async (width, height, user = OWNER) => {
      const c = await browser.newContext({ viewport: { width, height }, hasTouch: width < 600, acceptDownloads: true,
        permissions: ['microphone'], extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
      await c.addInitScript(() => { try { sessionStorage.setItem('kol-lib-tab', 'audio'); } catch { /* */ } });
      return c;
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q).catch(e => ({ error: e.message, status: e.status })), [action, body ?? null, query]);
    const lib = page => call(page, 'library');
    const word = async (page, id) => (await lib(page)).rows.find(r => r.id === id);
    const openLib = async page => {
      await page.goto(base + '/#/files');
      await page.waitForSelector('.lib-list .lib-row');
    };
    /** הרצף האמיתי: הסטודיו (/) ומיד הקבצים — היציאה מהסטודיו באמצע הטעינה (studio.js: mount ממתין ל-lock) לא זורקת ולא נתקעת. */
    const fromStudio = async (page, errs) => {
      // תשובת ה-lock מתעכבת — כך העזיבה בוודאות קורית בזמן ש-mount ממתין לה (בלי העיכוב המרוץ נדיר ותלוי עומס)
      const slow = async route => { await sleep(1500); await route.continue().catch(() => {}); };
      await page.route(/action=lock\b/, slow);
      await page.goto(base + '/');
      await page.waitForSelector('#main .studio');
      await openLib(page);
      await sleep(2000);                                   // ה-lock חוזר עכשיו — אחרי שהסטודיו כבר לא על המסך
      await page.unroute(/action=lock\b/, slow);
      ok(/#\/files$/.test(page.url()) && await page.locator('#main .lib-list').count() === 1 && await page.locator('#main .studio').count() === 0 && !errs.some(e => /beat/.test(e)),
        'מהסטודיו מיד לקבצים — המסך הוא הקבצים, בלי שגיאה', errs);
    };
    const row = (page, id) => page.locator(`.lib-row[data-id="${id}"]`);
    const rowText = (page, id) => row(page, id).innerText().catch(() => '');
    const sheetText = page => page.$eval('#sheet-body', e => e.innerText).catch(() => '');
    const waitSheet = (page, re, timeout = 15000) => page.waitForFunction(r => new RegExp(r).test(document.querySelector('#sheet-body')?.innerText || ''), re.source, { timeout });
    const sheetBtn = (page, re) => page.locator('#sheet-body button', { hasText: re }).first();
    // מנהל ההורדות של Chromium אינו שולח את X-Test-User של הבדיקה (בייצור — העוגייה של השער, כמו כל קישור), ולכן את
    // הבייטים של קישור ההורדה מושכים בתוך הדף, מאותה כתובת בדיוק
    let lastName = '';
    const bytesOf = async (page, url) => {
      const [b64, disp] = await page.evaluate(async u => {
        const r = await fetch(u);
        if (!r.ok || !/attachment/.test(r.headers.get('content-disposition') || '')) throw new Error('ההורדה: ' + r.status);
        const b = new Uint8Array(await r.arrayBuffer()); let s = ''; for (const x of b) s += String.fromCharCode(x); return [btoa(s), r.headers.get('content-disposition')];
      }, url);
      lastName = decodeURIComponent((disp.match(/filename\*=UTF-8''([^;]+)/) || [])[1] || '');
      return Buffer.from(b64, 'base64');
    };
    const sheetLink = (page, re) => page.locator('#sheet-body a', { hasText: re }).first();
    const waitToast = (page, re, timeout = 15000) => page.waitForFunction(r => { const t = document.querySelector('.toast'); return t && !t.hidden && new RegExp(r).test(t.textContent); }, re.source, { timeout });
    const menu = async (page, id) => { await row(page, id).locator('.lib-more').click(); await page.waitForSelector('#sheet[open] .lib-acts'); };
    const looseId = async (page, name) => (await lib(page)).loose.find(r => r.name === name)?.id;
    const filter = (page, label, value) => page.selectOption(`.lib-filters select[aria-label="${label}"]`, value);

    const ctx = await mk(1280, 900), page = await ctx.newPage();
    const errors = [], foreign = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => { if (!r.url().startsWith(base) && !r.url().startsWith('blob:') && !r.url().startsWith('data:')) foreign.push(r.url()); });
    await fromStudio(page, errors);

    // ── 1. השורות: לא מחוברת, משך, נכשלה ──
    const lid = await looseId(page, 'הקלטה ישנה');
    ok(lid < 0 && /לא מחוברת/.test(await rowText(page, lid)), 'הקלטה לא מחוברת — שורה בספרייה, "לא מחוברת"', await rowText(page, lid));
    ok(/⏱ 0:01/.test(await rowText(page, 1)), 'משך מוצג בשורה "בכלי"', await rowText(page, 1));
    ok(/משך לא נמדד/.test(await rowText(page, 2)), 'משך שעוד לא נמדד — כתוב', await rowText(page, 2));
    await filter(page, 'מצב', 'failed');
    const failedRows = await page.$$eval('.lib-list .lib-row', ls => ls.map(l => +l.dataset.id));
    ok(JSON.stringify(failedRows) === '[9]', 'סינון "נכשלו בהבאה"', failedRows);
    await filter(page, 'מצב', 'loose');
    ok((await page.$$eval('.lib-list .lib-row', ls => ls.map(l => +l.dataset.id))).every(x => x < 0), 'סינון "לא בשימוש (לא מחוברות)"');
    await filter(page, 'מצב', '');

    // ── 2. הקלטה שבאתר: מושבתות עם הסבר; "החלף" זמין ──
    await menu(page, 6);
    const siteBtns = await page.$$eval('#sheet-body .lib-act', bs => bs.map(b => [b.textContent, b.disabled]));
    const dis = siteBtns.filter(([t]) => /שינוי שם|גרסאות|העבר|נתק|הורדה|מחיקה/.test(t));
    ok(dis.length === 6 && dis.every(([t, d]) => d && /תיפתח אחרי ההבאה/.test(t)), 'באתר: שינוי שם / גרסאות / חיבור / ניתוק / הורדה / מחיקה — מושבתות, עם "תיפתח אחרי ההבאה"', siteBtns);
    ok(siteBtns.some(([t, d]) => /החלף/.test(t) && !d), 'באתר: "החלף" זמין', siteBtns);
    await page.click('#sheet [data-close], #sheet .sheet-head button');

    // ── 3. שינוי שם — בשורה ──
    await menu(page, 2);
    await sheetBtn(page, /שינוי שם/).click();
    await page.waitForSelector('.lib-row[data-id="2"] .lib-rename');
    await page.fill('.lib-row[data-id="2"] .lib-rename', 'שם חדש <b>x</b>');
    await page.press('.lib-row[data-id="2"] .lib-rename', 'Enter');
    await waitToast(page, /השם נשמר/);
    await page.waitForFunction(() => /«שם חדש <b>x<\/b>»/.test(document.querySelector('.lib-row[data-id="2"]')?.innerText || ''));
    const w2 = await word(page, 2);
    ok(w2.name === 'שם חדש <b>x</b>' && w2.file.id === S.files[2], 'השם נשמר; הקובץ והמזהה לא השתנו', w2);
    ok(await page.$$eval('.lib-row[data-id="2"] b b', x => x.length) === 0, 'השם מוצג כטקסט (לא HTML)');

    // ── 4. החלפה בהעלאה → גרסה → "חזור לגרסה הזו" ──
    await menu(page, 1);
    await sheetBtn(page, /החלף/).click();
    await page.waitForSelector('dialog.picker[open]');
    await page.setInputFiles('dialog.picker input[type=file]', path.join(up, 'new-1.flac'));
    await waitToast(page, /הוחלפה — הקודמת נשמרה כגרסה/);
    const w1 = await word(page, 1);
    ok(w1.file.id !== S.files[1] && w1.file.type === 'audio/flac' && w1.versions === 1 && w1.rec, 'flac הוחלף; גרסה אחת; אותה רשומה', w1);
    ok(/1 גרסה קודמת/.test(await rowText(page, 1)), 'השורה: "1 גרסה קודמת"', await rowText(page, 1));
    await menu(page, 1);
    await sheetBtn(page, /גרסאות \(1\)/).click();
    await waitSheet(page, /הנוכחית[\s\S]*מילה 1\.mp3/);
    ok(await page.$$eval('#sheet-body audio', a => a.length) === 2, 'הנוכחית והגרסה — עם נגן');
    await sheetBtn(page, /חזור לגרסה הזו/).click();
    await waitToast(page, /חזרנו לגרסה/);
    const w1b = await word(page, 1);
    ok(w1b.file.id === S.files[1] && w1b.versions === 1 && w1b.rec === w1.rec && w1b.file.duration === 1.5, 'חזרה לקובץ המקורי (והמשך שלו); ה-flac — גרסה', w1b);

    // ── 5. הקלטה מהדפדפן: האזנה לפני שמירה, "הקלט שוב", שמירה ──
    const canRec = await page.evaluate(() => !!window.MediaRecorder);
    if (canRec) {
      await menu(page, 4);
      await sheetBtn(page, /החלף/).click();
      await page.waitForSelector('dialog.picker[open]');
      await page.locator('dialog.picker .tile', { hasText: 'הקלטה' }).click();
      const pk = sel => page.locator(`dialog.picker ${sel}`);
      await pk('.rec-main').click();
      await sleep(1300);
      await pk('.rec-main').click();
      await page.waitForSelector('dialog.picker .rec audio:not([hidden])');
      ok(await pk('button:has-text("הקלט שוב")').isVisible() && await pk('button:has-text("שמירה")').isVisible(), 'אחרי עצירה: נגן להאזנה, "הקלט שוב" ו"שמירה"');
      await pk('button:has-text("הקלט שוב")').click();
      ok(await pk('.rec-main').isVisible() && await pk('.rec audio').isHidden(), '"הקלט שוב" — חוזר להתחלה, ההקלטה הקודמת לא נשמרה');
      await pk('.rec-main').click(); await sleep(1300); await pk('.rec-main').click();
      await page.waitForSelector('dialog.picker .rec audio:not([hidden])');
      await pk('button:has-text("שמירה")').click();
      await waitToast(page, /הוחלפה/, 20000);
      const w4 = await word(page, 4);
      ok(w4.file.id !== S.files[4] && w4.versions === 1 && /^audio\//.test(w4.file.type), 'ההקלטה נשמרה; הקודמת — גרסה', w4.file);
    } else ok(false, '❌ MediaRecorder אינו זמין בדפדפן הבדיקה — הקלטה לא נבדקה');

    // ── 6. ניתוק, חיבור (עם התנגשות) ──
    await menu(page, 3);
    await sheetBtn(page, /נתק מהמילה/).click();
    await waitSheet(page, /תחת "לא מחוברות"/);
    await sheetBtn(page, /^נתק$/).click();
    await waitToast(page, /נותקו 1/);
    ok((await word(page, 3)).state === 'none' && (await row(page, 3).count()) === 0, 'נותקה: המילה נשארת, "בלי הקלטה" (יורדת מרשימת ההקלטות)');
    await filter(page, 'מצב', 'none');
    ok(/בלי הקלטה/.test(await rowText(page, 3)), 'נותקה: ב"מילים בלי הקלטה"', await rowText(page, 3));
    await filter(page, 'מצב', '');
    const l3 = await looseId(page, 'מילה 3');
    ok(!!l3 && /לא מחוברת/.test(await rowText(page, l3)), 'ההקלטה — תחת "לא מחוברות"');
    await menu(page, l3);
    await sheetBtn(page, /חבר למילה/).click();
    await page.fill('#sheet-body input[type=search]', '2');
    await sheetBtn(page, /#2 מילה 2/).click();
    await waitSheet(page, /«שם חדש <b>x<\/b>»[\s\S]*לא נמחקת/);
    ok(/למילה "מילה 2" כבר יש הקלטה/.test(await page.innerText('#sheet-title')), 'התנגשות: "למילה … כבר יש הקלטה"');
    ok((await word(page, 2)).file.id === S.files[2], 'התנגשות — לפני אישור שום דבר לא השתנה');
    await sheetBtn(page, /חבר בכל זאת/).click();
    await waitToast(page, /חוברה ל-#2/);
    ok((await word(page, 2)).file.id === S.files[3] && !!(await looseId(page, 'שם חדש <b>x</b>')), 'חוברה; הקודמת של מילה 2 — "לא מחוברת" (לא נמחקה)');

    // ── 7. מחיקה לסל (אזהרה עם שם המילה), שחזור מחזיר את החיבור ──
    await menu(page, 2);
    await sheetBtn(page, /מחיקה לסל/).click();
    await waitSheet(page, /משמשת את המילה #2 מילה 2/);
    await sheetBtn(page, /^מחיקה לסל$/).click();
    await waitToast(page, /הועברו לסל/);
    ok((await word(page, 2)).state === 'none', 'נמחקה: המילה "בלי הקלטה"');
    ok(/1\s*🗑️ בסל/.test(await page.innerText('.lib-stats')), 'מונה הסל', await page.innerText('.lib-stats'));
    await page.locator('.lib-stat', { hasText: 'בסל' }).click();
    await page.waitForSelector('.lib-trash');
    ok(/מילה 3[\s\S]*מהמילה #2 מילה 2/.test(await page.innerText('.lib-trash')), 'בסל: השם והמילה', await page.innerText('.lib-trash'));
    await page.locator('.lib-trash button', { hasText: 'שחזור' }).first().click();
    await waitToast(page, /וחוברה שוב ל-#2/);
    ok((await word(page, 2)).file.id === S.files[3], 'השחזור החזיר את החיבור למילה');

    // ── 8. מחיקה סופית — רק מהסל, רק באישור מפורש ──
    await filter(page, 'מצב', '');
    await menu(page, lid);
    await sheetBtn(page, /מחיקה לסל/).click();
    await sheetBtn(page, /^מחיקה לסל$/).click();
    await waitToast(page, /הועברו לסל/);
    await page.locator('.lib-stat', { hasText: 'בסל' }).click();
    await page.locator('.lib-trash button', { hasText: 'מחיקה סופית' }).first().click();
    await waitSheet(page, /אי אפשר לבטל/);
    const fin = sheetBtn(page, /^מחיקה סופית$/);
    ok(await fin.isDisabled(), 'מחיקה סופית — מושבתת עד "אני מבין שזה סופי"');
    await page.check('#sheet-body label.check input');
    await fin.click();
    await waitToast(page, /נמחקה לצמיתות/);
    const filesNow = (await call(page, 'files')).files.map(f => f.id);
    ok(!filesNow.includes(S.files.loose) && (await lib(page)).trash.length === 0, 'הקובץ נמחק; הסל ריק');
    await filter(page, 'מצב', '');

    // ── 9. הוספה: כמה קבצים — מסך אישור לפני כל חיבור ──
    const before = (await lib(page)).rows.map(r => [r.id, r.file?.id || r.src]);
    // Playwright משמיט בשקט נתיב עם שם עברי — לכן כל הקבצים עוברים כבייטים, כל אחד עם השם שלו
    const MIME = { mp3: 'audio/mpeg', ogg: 'audio/ogg', wav: 'audio/wav', txt: 'text/plain' };
    const asFile = n => ({ name: n, mimeType: MIME[n.split('.').pop()], buffer: fs.readFileSync(path.join(up, n)) });
    await page.setInputFiles('.lib-tools input[type=file]', ['5.mp3', 'מילה 1.ogg', 'zzz.wav', 'notes.txt'].map(asFile));
    await waitSheet(page, /הותאמו · 1/);
    const st = await sheetText(page);
    ok(/5\.mp3[\s\S]*#5 מילה 5 · לפי המזהה/.test(st), 'הותאמו: 5.mp3 → #5 (לפי המזהה)', st);
    ok(/מתנגשים עם הקלטה קיימת · 1[\s\S]*מילה 1\.ogg[\s\S]*#1 מילה 1/.test(st), 'מתנגשים: "מילה 1.ogg" → #1 (לפי המילה), עם הקלטה קיימת', st);
    ok(/לא הותאמו · 1[\s\S]*zzz\.wav/.test(st) && /נדחו · 1[\s\S]*notes\.txt[\s\S]*אינו קובץ שמע/.test(st), 'לא הותאמו · נדחו (עם הסיבה)', st);
    await sleep(300);
    ok(JSON.stringify((await lib(page)).rows.map(r => [r.id, r.file?.id || r.src])) === JSON.stringify(before), 'לפני האישור — שום דבר לא חובר ולא עלה');
    await sheetBtn(page, /דלג על כולן/).click();
    await sheetBtn(page, /אישור — העלאה של 2/).click();
    await waitToast(page, /חוברו 1 · נוספו כלא מחוברות 1/);
    const L9 = await lib(page);
    ok(L9.rows.find(r => r.id === 5).state === 'tool' && L9.rows.find(r => r.id === 1).file.id === S.files[1] && L9.loose.some(r => r.name === 'zzz'), '5 חוברה; 1 לא השתנתה (דלג); zzz — לא מחוברת');
    // גרירה
    await page.evaluate(() => {
      const box = document.querySelector('.lib-filters').parentElement;
      const dt = new DataTransfer();
      dt.items.add(new File([new Uint8Array([73, 68, 51, 3, 0, 0, 0, 0, 0, 0, 255, 251, 144, 100])], '10.mp3', { type: 'audio/mpeg' }));
      box.dispatchEvent(new DragEvent('dragenter', { bubbles: true, dataTransfer: dt }));
      box.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
    });
    await waitSheet(page, /הותאמו · 1[\s\S]*#10 מילה 10/);
    ok(true, 'גרירה: קובץ שנגרר → מסך האישור, מותאם למילה 10');
    await sheetBtn(page, /^ביטול$/).click();
    ok((await word(page, 10)).state === 'none', 'ביטול — שום דבר לא חובר');

    // ── 10. בחירה מרובה והורדה כ-ZIP — זהה בבייט ──
    await row(page, 1).locator('.lib-check').check();
    await row(page, 7).locator('.lib-check').check();
    ok(/נבחרו 2/.test(await page.innerText('.lib-selbar')), 'בחירה מרובה: "נבחרו 2"');
    await page.locator('.lib-selbar button', { hasText: 'הורדה' }).click();
    await waitSheet(page, /מוכן: 2 קבצים/, 30000);
    const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), sheetLink(page, /שמירת הקובץ/).click()]);
    ok(/action=rec_zip_get&token=[0-9a-f]{32}$/.test(dl.url()), 'ZIP: הלחיצה מתחילה הורדה', dl.url());
    const zp = path.join(up, 'out.zip');
    fs.writeFileSync(zp, await bytesOf(page, dl.url()));
    const zs = zipShas(zp);
    ok(zs['1.mp3'] === S.sha[1] && zs['7.mp3'] === S.sha[7] && 'recordings.csv' in zs && Object.keys(zs).length === 3, 'ZIP: הקבצים זהים בבייט למה שהועלה (sha256) + recordings.csv', zs);
    const csv = zipText(zp, 'recordings.csv');
    ok(/"1","מילה 1","מילה 1","מילה 1\.mp3","1\.mp3","1\.5"/.test(csv), 'קובץ הרשימה: מזהה, מילה, שם הקובץ, משך', csv);
    await page.click('#sheet [data-close]');
    ok(/נבחרו 2/.test(await page.innerText('.lib-selbar')), 'הבחירה נשמרת אחרי ההורדה');
    await page.locator('.lib-selbar button', { hasText: 'בחר את כל המסוננות' }).click();
    const nAll = await page.innerText('.lib-selbar');
    ok(/נבחרו (1[0-9])/.test(nAll), '"בחר את כל המסוננות"', nAll);
    await page.locator('.lib-selbar button[aria-label="ביטול הבחירה"]').click();
    ok(await page.isHidden('.lib-selbar'), 'ביטול הבחירה');
    // אחת — הקובץ המקורי
    await menu(page, 8);
    const [d1] = await Promise.all([page.waitForEvent('download'), sheetBtn(page, /הורדה \(הקובץ המקורי\)/).click()]);
    ok(sha(await bytesOf(page, d1.url())) === S.sha[8] && lastName === 'כפולה ב.mp3', 'הורדה אחת — הקובץ המקורי, אותו שם, זהה בבייט', lastName);

    // ── 11. כפולות — "השאר אחת" ──
    await page.locator('.lib-tools button', { hasText: 'כפולות' }).click();
    await page.waitForSelector('.lib-group');
    ok(/2 עותקים זהים/.test(await page.innerText('.lib-list')), 'כפולות: הקבוצה מוצגת (אותו sha256)');
    await row(page, 7).locator('.lib-keep').click();
    await waitSheet(page, /#8 מילה 8 — תעבור להשתמש בעותק הזה/);
    await sheetBtn(page, /השאר את זו/).click();
    await waitToast(page, /נשארה אחת/);
    ok((await word(page, 8)).file.id === S.files[7] && (await word(page, 8)).versions === 1, 'מילה 8 על העותק שנשאר; הקודם — גרסה');
    await filter(page, 'מצב', '');

    // ── 12. סינון לפי משך וגודל ──
    await filter(page, 'משך', 'short');
    const shortRows = await page.$$eval('.lib-list .lib-row', ls => ls.map(l => +l.dataset.id));
    ok(JSON.stringify(shortRows) === '[1]', 'סינון: משך עד 2 שניות', shortRows);
    await filter(page, 'משך', '');
    await filter(page, 'גודל', 'l');
    ok(await page.$$eval('.lib-list .lib-row', ls => ls.length) === 0, 'סינון: מעל 1MB — אין');
    await filter(page, 'גודל', '');

    // ── 13. גרסאות: המקום, ו"נקה" — לבעלים ──
    ok(/גרסאות קודמות: \d+ · /.test(await page.innerText('.summary')) && await page.locator('.lib-vline button', { hasText: 'נקה גרסאות ישנות' }).count() === 1, 'כמה מקום הגרסאות תופסות + "נקה גרסאות ישנות מ-…" (בעלים)');
    ok(foreign.length === 0, 'אף בקשה לא יצאה מחוץ לכלי (גם לא לאתר)', foreign);
    ok(errors.length === 0, 'אין שגיאות JS בדף (בעלים)', errors);
    await ctx.close();

    // ── 14. עורך: בלי מחיקה סופית ובלי ניקוי; השרת דוחה ──
    const ectx = await mk(1280, 900, EDITOR), ep = await ectx.newPage();
    ep.on('pageerror', e => errors.push(e.message));
    await fromStudio(ep, errors);
    const z = (await lib(ep)).loose.find(r => r.name === 'zzz');
    ok(!(await call(ep, 'rec_delete', { ids: [z.rec] })).error, 'עורך: מחיקה לסל');
    const purge = await call(ep, 'rec_purge', { id: z.rec, confirm: true });
    const cleanR = await call(ep, 'rec_versions_clean', { before: '2099-01-01', confirm: true });
    ok(purge.status === 403 && cleanR.status === 403, 'עורך: מחיקה סופית וניקוי גרסאות — 403 בשרת', [purge, cleanR]);
    await ep.reload();
    await ep.waitForSelector('.lib-list .lib-row');
    await ep.locator('.lib-stat', { hasText: 'בסל' }).click();
    await ep.waitForSelector('.lib-trash');
    ok(await ep.locator('.lib-trash button', { hasText: 'מחיקה סופית' }).count() === 0 && /לבעל העמוד/.test(await ep.innerText('#main')), 'עורך: אין "מחיקה סופית" בסל');
    ok(await ep.locator('.lib-vline button').count() === 0, 'עורך: אין "נקה גרסאות"');
    await ectx.close();

    // ── 15. טלפון 390px ──
    const pctx = await mk(390, 780), pp = await pctx.newPage();
    pp.on('pageerror', e => errors.push(e.message));
    await fromStudio(pp, errors);
    ok(await pp.locator('.lib-row[data-id="1"] .lib-check').isHidden() && await pp.locator('.lib-row[data-id="1"] .lib-more').isVisible(), 'טלפון: בלי תיבות סימון; ⋯ בכל שורה');
    await pp.evaluate(() => document.querySelector('.lib-row[data-id="1"]').dispatchEvent(new Event('touchstart', { bubbles: true })));
    await sleep(800);
    await pp.evaluate(() => document.querySelector('.lib-row[data-id="1"]').dispatchEvent(new Event('touchend', { bubbles: true })));
    ok(/נבחרו 1/.test(await pp.innerText('.lib-selbar').catch(() => '')) && await pp.locator('.lib-row[data-id="4"] .lib-check').isVisible(), 'טלפון: לחיצה ארוכה — בחירה מרובה');
    await pp.locator('.lib-row[data-id="4"] .lib-check').check();
    ok(/נבחרו 2/.test(await pp.innerText('.lib-selbar')), 'טלפון: בחירה של שורה נוספת');
    const over = await pp.evaluate(() => ({ doc: document.documentElement.scrollWidth, w: innerWidth }));
    ok(over.doc <= over.w, 'טלפון 390px — בלי גלילה לרוחב (עם סרגל הבחירה)', over);
    await pp.locator('.lib-selbar button[aria-label="ביטול הבחירה"]').click();
    await menu(pp, 1);
    const acts = await pp.$$eval('#sheet-body .lib-act', bs => bs.map(b => [b.textContent.trim().slice(0, 14), b.getBoundingClientRect().height]));
    ok(acts.length >= 9 && acts.every(([, hh]) => hh >= 32), 'טלפון: כל הפעולות מתפריט השורה, בגובה נוח', acts);
    const over2 = await pp.evaluate(() => ({ sheet: document.querySelector('#sheet').scrollWidth, sw: document.querySelector('#sheet').clientWidth }));
    ok(over2.sheet <= over2.sw + 1, 'טלפון: התפריט בלי גלילה לרוחב', over2);
    ok(errors.length === 0, 'אין שגיאות JS בדף', errors);
  } catch (e) {
    failed++;
    console.log('❌ הבדיקה נפלה: ' + (e.stack || e.message));
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true }); fs.rmSync(up, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
