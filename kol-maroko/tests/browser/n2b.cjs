/**
 * פרוסה N2b — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/n2b.cjs
 *
 * הקלטה שנייה (שדה "הקלטה בצרפתית") בספרייה, מקצה לקצה: על כל המסך ב-1920 וב-1366 (בלי ריק בצדדים, עמודות שדה · משך ·
 * גודל); מונה וסינון "שדה"; "חסרה" לכל שדה; הוספה בגרירה על הרשימה עם התאמה לפי שם הקובץ ("בסטילה - בצרפתית.mp3") ומסך
 * אישור; הקלטה מהדפדפן ל"כוסכוס"; גרירת קובץ על שורה — החלפה עם גרסה ו"חזור"; מחיקה לסל ושחזור לאותו מקום; גרירת שורה על
 * שורה חסרה — חיבור / העברה באישור; Esc מבטל גרירה; "העבר לשדה…"; ZIP עם <id>-<key>; טלפון 390; ובלי שדה נוסף — המסך של A3.
 * ובכל הזמן — אף בקשה לא יוצאת מחוץ לכלי.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net'), crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
const FR = 'audio_fr';
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 600) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const zipShas = file => JSON.parse(execFileSync('python3', ['-I', '-c',
  'import sys,json,zipfile,hashlib;z=zipfile.ZipFile(sys.argv[1]);assert z.testzip() is None;print(json.dumps({n:hashlib.sha256(z.read(n)).hexdigest() for n in z.namelist()}))', file]).toString());
const zipText = (file, name) => execFileSync('python3', ['-I', '-c', 'import sys,zipfile;sys.stdout.write(zipfile.ZipFile(sys.argv[1]).read(sys.argv[2]).decode("utf-8-sig"))', file, name]).toString();

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-n2b-'));
  const up = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-n2b-up-'));
  const S = JSON.parse(execFileSync('php', [path.join(__dirname, 'n2b-seed.php'), data, up]).toString());
  const port = await freePort();
  const base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, stdio: 'ignore',
    env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' } });
  let browser;
  const errors = [], foreign = [];
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] });
    const mk = async (width, height) => {
      const c = await browser.newContext({ viewport: { width, height }, hasTouch: width < 600, acceptDownloads: true,
        permissions: ['microphone'], extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
      await c.addInitScript(() => { try { sessionStorage.setItem('kol-lib-tab', 'audio'); } catch { /* */ } });
      const p = await c.newPage();
      p.on('pageerror', e => errors.push(e.message));
      p.on('request', r => { if (!r.url().startsWith(base) && !r.url().startsWith('blob:') && !r.url().startsWith('data:')) foreign.push(r.url()); });
      return [c, p];
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q).catch(e => ({ error: e.message, status: e.status })), [action, body ?? null, query]);
    const lib = page => call(page, 'library');
    const slot = async (page, id) => (await lib(page)).rows.find(r => String(r.id) === String(id));
    const openLib = async page => { await page.goto(base + '/#/files'); await page.waitForSelector('.lib-list .lib-row'); };
    const row = (page, id) => page.locator(`.lib-row[data-id="${id}"]`);
    const rowText = (page, id) => row(page, id).innerText().catch(() => '');
    const sheetText = page => page.$eval('#sheet-body', e => e.innerText).catch(() => '');
    const waitSheet = (page, re, timeout = 15000) => page.waitForFunction(r => new RegExp(r).test(document.querySelector('#sheet[open]')?.innerText || ''), re.source, { timeout });
    const sheetBtn = (page, re) => page.locator('#sheet-body button', { hasText: re }).first();
    const waitToast = (page, re, timeout = 15000) => page.waitForFunction(r => { const t = document.querySelector('.toast'); return t && !t.hidden && new RegExp(r).test(t.textContent); }, re.source, { timeout });
    const menu = async (page, id) => { await row(page, id).locator('.lib-more').click(); await page.waitForSelector('#sheet[open] .lib-acts'); };
    const filter = (page, label, value) => page.selectOption(`.lib-filters select[aria-label="${label}"]`, value);
    const width = page => page.evaluate(() => ({ main: Math.round(document.querySelector('main').getBoundingClientRect().width), list: Math.round(document.querySelector('.lib-list').getBoundingClientRect().width), w: innerWidth, doc: document.documentElement.scrollWidth }));
    /** קובץ מהתיקייה, כ-File בדף, וגרירה שלו (dragover + drop) על אלמנט. מחזיר את הטקסט שהוצג בזמן הגרירה. */
    const dropFile = (page, selector, name, mime) => page.evaluate(([sel, n, b64, type]) => {
      const el = document.querySelector(sel);
      const bin = atob(b64), u = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
      const dt = new DataTransfer(); dt.items.add(new File([u], n, { type }));
      el.dispatchEvent(new DragEvent('dragenter', { bubbles: true, dataTransfer: dt }));
      el.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
      const tip = document.querySelector('.lib-dragtip:not([hidden])')?.textContent || '';
      const hl = !!el.closest('.lib-row')?.classList.contains('lib-target');
      el.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
      return { tip, hl };
    }, [selector, name, fs.readFileSync(path.join(up, name)).toString('base64'), mime]);
    /** גרירת שורה על שורה. esc — Esc לפני השחרור. */
    const dragRow = (page, from, to, esc = false) => page.evaluate(([a, b, e]) => {
      const src = document.querySelector(`.lib-row[data-id="${a}"]`), dst = document.querySelector(`.lib-row[data-id="${b}"]`);
      const dt = new DataTransfer();
      src.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
      dst.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
      const tip = document.querySelector('.lib-dragtip:not([hidden])')?.textContent || '';
      const hl = dst.classList.contains('lib-target');
      if (e) document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      const after = !!document.querySelector('.lib-dragtip:not([hidden])');
      dst.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
      src.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: dt }));
      return { tip, hl, after };
    }, [from, to, esc]);

    // ── 1. במחשב 1920: על כל הרוחב, העמודות, המונים והסינון ──
    const [ctx, page] = await mk(1920, 1080);
    await openLib(page);
    let w = await width(page);
    ok(w.main >= w.w - 40 && w.list >= w.w - 80 && w.doc <= w.w, '1920: הספרייה ממלאת את הרוחב — בלי ריק בצדדים', w);
    ok(await row(page, `1:${FR}`).locator('.lib-c-field').isVisible() && await row(page, 1).locator('.lib-c-dur').isVisible() && await row(page, 1).locator('.lib-c-size').isVisible(),
      '1920: עמודות שדה · משך · גודל בכל שורה');
    ok(/בצרפתית/.test(await rowText(page, `1:${FR}`)) && /הקלטה/.test(await row(page, 1).locator('.lib-c-field').innerText()), 'עמודה "שדה": "בצרפתית" / "הקלטה"');
    const stats = await page.innerText('.lib-stats');
    ok(/הקלטה\s*5/.test(stats) && /בצרפתית\s*1/.test(stats), 'מונה לכל שדה ("הקלטה 5 · בצרפתית 1")', stats);
    await page.screenshot({ path: path.join(up, 'n2b-1920.png') });
    await filter(page, 'שדה', FR);
    await filter(page, 'מצב', 'none');
    const ids = await page.$$eval('.lib-list .lib-row', ls => ls.map(l => l.dataset.id));
    ok(ids.length === 5 && ids.every(i => i.endsWith(':' + FR)), 'סינון "בצרפתית" + "חסרות": כל המילים בלי הקלטה בצרפתית', ids);
    ok(/בסטילה[\s\S]*אין הקלטה בצרפתית/.test(await rowText(page, `2:${FR}`)), '"בסטילה — אין הקלטה בצרפתית"', await rowText(page, `2:${FR}`));
    ok(await row(page, `2:${FR}`).locator('.lib-rep').count() === 1 && await row(page, `2:${FR}`).locator('.lib-link').count() === 1, 'משורה חסרה: 🎙️ הקלט / העלה · 🔗 חבר הקלטה מהספרייה');

    // ── 2. גרירת קובץ על הרשימה: התאמה לפי שם הקובץ, מסך אישור, חובר לבצרפתית ──
    await dropFile(page, '.lib-filters', 'בסטילה - בצרפתית.mp3', 'audio/mpeg');
    await waitSheet(page, /הותאמו · 1[\s\S]*#2 בסטילה · בצרפתית \(משם הקובץ\)/);
    ok(/להוסיף ל:/.test(await sheetText(page)), 'מסך האישור: "להוסיף ל:" ועמודת שדה');
    ok((await slot(page, `2:${FR}`)).state === 'none', 'לפני האישור — שום דבר לא חובר');
    await sheetBtn(page, /אישור — העלאה של 1/).click();
    await waitToast(page, /חוברו 1/, 20000);
    let s2 = await slot(page, `2:${FR}`);
    ok(s2.state === 'tool' && s2.file.sha === S.sha['בסטילה - בצרפתית.mp3'], 'חובר לבצרפתית של "בסטילה" — זהה בבייט', s2);
    ok((await slot(page, 2)).file.id === S.files[2], 'הראשית של "בסטילה" לא השתנתה');

    // ── 3. הקלטה מהדפדפן ל"כוסכוס" (בצרפתית) ──
    if (await page.evaluate(() => !!window.MediaRecorder)) {
      await row(page, `3:${FR}`).locator('.lib-rep').click();
      await page.waitForSelector('dialog.picker[open]');
      await page.locator('dialog.picker .tile', { hasText: 'הקלטה' }).click();
      const pk = sel => page.locator(`dialog.picker ${sel}`);
      await pk('.rec-main').click(); await sleep(1300); await pk('.rec-main').click();
      await page.waitForSelector('dialog.picker .rec audio:not([hidden])');
      await pk('button:has-text("שמירה")').click();
      await waitToast(page, /נשמרה/, 20000);
      const s3 = await slot(page, `3:${FR}`);
      ok(s3.state === 'tool' && /^audio\//.test(s3.file.type) && (await slot(page, 3)).state === 'none', 'הקלטה מהדפדפן נשמרה לבצרפתית של "כוסכוס" (הראשית — עדיין חסרה)', s3);
    } else ok(false, 'MediaRecorder אינו זמין — הקלטה לא נבדקה');

    // ── 4. גרירת קובץ על שורה: החלפה עם גרסה; "חזור" ──
    await filter(page, 'מצב', '');
    await page.waitForSelector(`.lib-row[data-id="2:${FR}"]`);
    const d4 = await dropFile(page, `.lib-row[data-id="2:${FR}"] .lib-main`, 'repl.ogg', 'audio/ogg');
    ok(d4.hl && /↻ החלף הקלטה בצרפתית של "בסטילה"/.test(d4.tip), 'בזמן הגרירה: היעד מודגש, "↻ החלף הקלטה בצרפתית של "בסטילה""', d4);
    await waitToast(page, /הוחלפה/, 20000);
    s2 = await slot(page, `2:${FR}`);
    ok(s2.file.sha === S.sha['repl.ogg'] && s2.versions === 1, 'גרירה על שורה — הוחלפה; הקודמת — גרסה', s2);
    await menu(page, `2:${FR}`);
    await sheetBtn(page, /גרסאות/).click();
    await waitSheet(page, /חזור לגרסה הזו/);
    await sheetBtn(page, /חזור לגרסה הזו/).click();
    await waitToast(page, /חזרנו/);
    ok((await slot(page, `2:${FR}`)).file.sha === S.sha['בסטילה - בצרפתית.mp3'], '"חזור לגרסה הזו" — של בצרפתית');

    // ── 5. מחיקה לסל ושחזור — לאותו מקום ──
    const rec2 = (await slot(page, `2:${FR}`)).rec;
    await menu(page, `2:${FR}`);
    await sheetBtn(page, /מחיקה לסל/).click();
    await sheetBtn(page, /^מחיקה לסל$/).click();
    await waitToast(page, /לסל/);
    ok((await slot(page, `2:${FR}`)).state === 'none', 'נמחקה — המקום "חסרה"');
    await filter(page, 'מצב', 'trash');
    await page.waitForSelector('.lib-trash');
    ok(/בסטילה · הקלטה בצרפתית/.test(await page.innerText('.lib-trash')), 'בסל — המקום ("מהמילה #2 בסטילה · הקלטה בצרפתית")', await page.innerText('.lib-trash'));
    await page.locator('.lib-trash li', { hasText: 'בסטילה' }).locator('button', { hasText: 'שחזור' }).click();
    await waitToast(page, /שוחזרה/);
    s2 = await slot(page, `2:${FR}`);
    ok(s2.rec === rec2 && s2.state === 'tool' && (await slot(page, 2)).file.id === S.files[2], 'שחזור — חזר לבצרפתית של "בסטילה" (אותה רשומה), לא לראשית', s2);
    await filter(page, 'מצב', '');

    // ── 6. גרירת שורה: לא מחוברת → חסרה (חיבור); Esc מבטל; מחוברת → חסרה (העברה באישור) ──
    await filter(page, 'שדה', '');
    await filter(page, 'מצב', 'all');                      // "הכול — גם חסרות": המקור והיעד על המסך יחד
    const L0 = await lib(page);
    const looseId = L0.loose.find(r => r.name === 'הקלטה ישנה').id;
    await page.waitForSelector(`.lib-row[data-id="${looseId}"]`);
    const esc = await dragRow(page, looseId, `4:${FR}`, true);
    await sleep(500);
    ok(esc.hl && /חבר להקלטה בצרפתית של "מילה 4"/.test(esc.tip) && !esc.after && (await slot(page, `4:${FR}`)).state === 'none', 'Esc מבטל גרירה — שום דבר לא חובר', esc);
    const d6 = await dragRow(page, looseId, `4:${FR}`);
    await waitToast(page, /חוברה/);
    ok((await slot(page, `4:${FR}`)).state === 'tool' && /🔗/.test(d6.tip), 'גרירת הקלטה לא מחוברת על שורה חסרה — חוברה', d6);
    await page.waitForSelector(`.lib-row[data-id="1:${FR}"]`);
    await dragRow(page, `1:${FR}`, 3);
    await waitSheet(page, /להעביר מהקלטה בצרפתית של "מילה 1" להקלטה של "כוסכוס"\?/);
    await sheetBtn(page, /^העבר$/).click();
    await waitToast(page, /הועברה/);
    const s3a = await slot(page, 3);
    ok(s3a.file?.id === S.files.fr1 && (await slot(page, `1:${FR}`)).state === 'none', 'גרירת שורה מחוברת — אחרי אישור "להעביר מ-X ל-Y?" — הועברה', s3a);

    // ── 7. "העבר לשדה…" מתפריט השורה; מקום תפוס — אישור ──
    await menu(page, 3);
    await sheetBtn(page, /העבר לשדה/).click();
    await waitSheet(page, /הקלטה בצרפתית/);
    await page.locator('#sheet-body .use-row', { hasText: 'הקלטה בצרפתית' }).click();
    await waitSheet(page, /כבר יש הקלטה בצרפתית/);
    await sheetBtn(page, /החלף \(הנוכחית תהפוך ללא מחוברת\)/).click();
    await waitToast(page, /הועברה/);
    const L7 = await lib(page);
    ok(L7.rows.find(r => r.id === `3:${FR}`).file.id === S.files.fr1 && L7.rows.find(r => r.id === 3).state === 'none' && L7.loose.length >= 1, '"העבר לשדה…" — אותה מילה, שדה אחר; הקודמת — לא מחוברת');

    // ── 8. ZIP של השדה: <id>-<key>.<ext> + רשימה עם השדה ──
    await filter(page, 'מצב', '');
    await filter(page, 'שדה', FR);
    await page.locator('.lib-list .lib-row').first().locator('.lib-check').check();
    await page.locator('.lib-selbar button', { hasText: 'בחר את כל המסוננות' }).click();
    await page.locator('.lib-selbar button', { hasText: 'הורדה' }).click();
    await waitSheet(page, /מוכן: \d+ קבצים/, 30000);
    const href = await page.locator('#sheet-body a', { hasText: 'שמירת הקובץ' }).getAttribute('href');
    const b64 = await page.evaluate(async u => { const r = await fetch(u); const b = new Uint8Array(await r.arrayBuffer()); let s = ''; for (const x of b) s += String.fromCharCode(x); return btoa(s); }, href);
    const zp = path.join(up, 'fr.zip');
    fs.writeFileSync(zp, Buffer.from(b64, 'base64'));
    const zs = zipShas(zp);
    ok(zs[`2-${FR}.mp3`] === S.sha['בסטילה - בצרפתית.mp3'] && zs[`3-${FR}.mp3`] === S.sha.fr1 && 'recordings.csv' in zs && !('2.mp3' in zs), 'ZIP: 2-audio_fr.mp3, 3-audio_fr.mp3 — זהים בבייט', zs);
    ok(/"words","audio_fr","הקלטה בצרפתית"/.test(zipText(zp, 'recordings.csv')), 'ZIP: רשימה עם list · field · field_label');
    await page.click('#sheet [data-close]');
    await page.locator('.lib-selbar button[aria-label="ביטול הבחירה"]').click();
    await ctx.close();

    // ── 9. 1366: אותו דבר ──
    const [c2, p2] = await mk(1366, 768);
    await openLib(p2);
    w = await width(p2);
    ok(w.main >= w.w - 40 && w.doc <= w.w && await p2.locator('.lib-row .lib-c-field').first().isVisible(), '1366: על כל הרוחב, עם העמודות', w);
    await p2.screenshot({ path: path.join(up, 'n2b-1366.png') });
    await c2.close();

    // ── 10. טלפון 390: השדה — שורה שנייה בכרטיס; כל הפעולות מהתפריט ──
    const [c3, p3] = await mk(390, 780);
    await openLib(p3);
    ok(await p3.locator(`.lib-row[data-id="2:${FR}"] .lib-field-m`).isVisible() && await p3.locator(`.lib-row[data-id="2:${FR}"] .lib-c-field`).isHidden(), 'טלפון: השדה כשורה שנייה בכרטיס (בלי עמודות)');
    const o3 = await width(p3);
    ok(o3.doc <= o3.w, 'טלפון 390 — בלי גלילה לרוחב', o3);
    await menu(p3, `2:${FR}`);
    ok(/העבר לשדה/.test(await sheetText(p3)) && /חבר ל|העבר למילה/.test(await sheetText(p3)), 'טלפון: "העבר לשדה…" ו"חבר ל…" מתפריט השורה');
    await sheetBtn(p3, /העבר לשדה/).click();
    const sw = await p3.evaluate(() => ({ w: document.querySelector('#sheet').getBoundingClientRect().width, s: document.querySelector('#sheet').scrollWidth, c: document.querySelector('#sheet').clientWidth }));
    ok(sw.w >= 380 && sw.s <= sw.c + 1, 'טלפון: גיליון לכל הרוחב, בלי גלילה לרוחב', sw);
    await c3.close();

    // ── 11. בלי שדה שמע נוסף — המסך של A3 (בלי עמודה וסינון "שדה") ──
    const [c4, p4] = await mk(1280, 900);
    await openLib(p4);
    const del = await call(p4, 'field_delete', { key: FR });
    ok(!del.error && del.recordings >= 2, 'מחיקת השדה — ההקלטות שלו נספרו (יישארו כלא מחוברות)', del);
    await p4.reload(); await p4.waitForSelector('.lib-list .lib-row');
    ok(await p4.locator('.lib-filters select[aria-label="שדה"]').count() === 0 && await p4.locator('.lib-c-field').count() === 0 && await p4.locator('.lib-fstat').count() === 0
      && /מיל(ה|ים) בלי הקלטה/.test(await p4.innerText('.lib-stats')), 'שדה אחד — בלי עמודה, סינון ומונה "שדה" (המסך של A3)',
      [await p4.locator('.lib-filters select[aria-label="שדה"]').count(), await p4.locator('.lib-c-field').count(), await p4.locator('.lib-fstat').count(), await p4.innerText('.lib-stats')]);
    const L11 = await lib(p4);
    ok(L11.loose.some(r => r.dropped === 'הקלטה בצרפתית'), 'ההקלטות של השדה שנמחק — "משדה שנמחק: הקלטה בצרפתית"');
    await c4.close();

    ok(foreign.length === 0, 'אף בקשה לא יצאה מחוץ לכלי', foreign);
    ok(errors.length === 0, 'אין שגיאות JS בדף', errors);
  } catch (e) {
    failed++;
    console.log('❌ הבדיקה נפלה: ' + (e.stack || e.message));
  } finally {
    if (browser) await browser.close();
    php.kill();
    if (process.env.KEEP_SHOTS) for (const f of ['n2b-1920.png', 'n2b-1366.png']) { try { fs.copyFileSync(path.join(up, f), path.join(process.env.KEEP_SHOTS, f)); } catch { /* */ } }
    fs.rmSync(data, { recursive: true, force: true }); fs.rmSync(up, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
