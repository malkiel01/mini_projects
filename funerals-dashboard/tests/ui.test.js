// פרוסה 12b — הממשק בדפדפן (Playwright). רץ מ-tests/ui.sh (שרת, מסד ו-env משלו).
// U1 צופה · U2 מונים · U5 פס לפי בדיקות חלות · U6 צילום בלי תיק · U7 המיקום שלי · U9 שפה · 320px · ביטול קישור · תבנית תיקייה.
'use strict';
const fs = require('fs');
const path = require('path');
const {chromium} = require('playwright');
const {BASE, IDF, BOSS, MONTH, SHOTS} = process.env;
let fails = 0;
const ok = (c, m) => { if (c) console.log('  ✓ ' + m); else { fails++; console.error('FAIL: ' + m); } };
fs.mkdirSync(SHOTS, {recursive: true});

// PDF קטן ותקין, עמוד אחד — לצופה (U1), בלי רשת ובלי Dropbox
function tinyPdf() {
  const objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 120] /Contents 4 0 R /Resources << >> >>'];
  const stream = '0 0 1 rg 20 20 160 80 re f';
  objs.push(`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`);
  let out = '%PDF-1.4\n'; const offs = [];
  objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const x = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF`;
  return Buffer.from(out, 'latin1');
}
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

/**
 * U9: כל הטקסט הגלוי במסך (כולל aria-label, title ו-placeholder של רכיבים גלויים) — בלי מילה באנגלית ובלי מזהה טכני.
 * חוץ מנתונים: מה שבתוך <bdi>, dir="auto"/"ltr" (שמות קבצים, אנשים, מיילים, נתיבים) וערכי שדות. מחזיר את מה שנמצא.
 */
async function scanEnglish(page) {
  return page.evaluate(() => {
    const bad = new Set();
    const isData = el => !!(el && el.closest('bdi, [dir="auto"], [dir="ltr"], script, style, input, textarea, select option[value=""]'));
    const visible = el => el && el.checkVisibility ? el.checkVisibility({checkOpacity: true, checkVisibilityCSS: true}) : !!(el && el.offsetParent);
    const test = (t, where) => {
      const s = String(t || '').trim(); if (!s) return;
      if (/[A-Za-z]{2,}/.test(s) || /\b\w+_\w+\b/.test(s)) bad.add(where + ': ' + s.slice(0, 90));
    };
    const root = document.querySelector('dialog[open]') || document.body;
    const walk = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let n = walk.nextNode(); n; n = walk.nextNode()) {
      const el = n.parentElement;
      if (!visible(el) || isData(el)) continue;
      test(n.nodeValue, el.tagName.toLowerCase());
    }
    for (const el of root.querySelectorAll('[aria-label], [title], [placeholder]')) {
      if (!visible(el)) continue;
      for (const a of ['aria-label', 'title', 'placeholder']) if (el.hasAttribute(a) && !el.closest('bdi')) test(el.getAttribute(a), '@' + a);
    }
    return [...bad];
  });
}
/** 320px: אין גלילה אופקית — לא לדף ולא לגוף החלון הפתוח. */
async function noHScroll(page) {
  return page.evaluate(() => {
    const over = [];
    const d = document.documentElement;
    if (d.scrollWidth > d.clientWidth + 1) over.push('הדף: ' + d.scrollWidth + '>' + d.clientWidth);
    const dlg = document.querySelector('dialog[open] .sheet-body');
    if (dlg && dlg.scrollWidth > dlg.clientWidth + 1) over.push('החלון: ' + dlg.scrollWidth + '>' + dlg.clientWidth);
    return over;
  });
}
const seen = [];
async function screen(page, name, width) {
  const en = await scanEnglish(page);
  ok(!en.length, `U9 ${name}: בלי אנגלית ובלי מזהה טכני` + (en.length ? ' — ' + JSON.stringify(en) : ''));
  for (const w of width ? [width] : [390, 320]) {
    await page.setViewportSize({width: w, height: 800});
    await page.waitForTimeout(80);
    if (w === 320) { const o = await noHScroll(page); ok(!o.length, `320px ${name}: בלי גלילה אופקית` + (o.length ? ' — ' + o.join(', ') : '')); }
    await page.screenshot({path: path.join(SHOTS, `${name}-${w}.png`)});
  }
  await page.setViewportSize({width: 390, height: 800});
  seen.push(name);
}
async function closeDialog(page) { await page.keyboard.press('Escape'); await page.waitForTimeout(100); }

(async () => {
  fs.writeFileSync(IDF, BOSS);
  const browser = await chromium.launch();
  const ctx = await browser.newContext({viewport: {width: 390, height: 800}, deviceScaleFactor: 1, locale: 'he-IL',
    geolocation: {latitude: 31.7712345, longitude: 35.2178123, accuracy: 8}, permissions: ['geolocation']});
  await ctx.addInitScript(m => { try { sessionStorage.setItem('fd_month', m); sessionStorage.setItem('fd_filter', 'all'); } catch (e) {} }, MONTH);
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));

  // U1: קבצי תיק מדומים (תמונה, PDF, Word) — file_view מחזיר PDF/תמונה; התיקייה קושרה לבד (ביטול קישור)
  let undo = null;
  await page.route(/api\.php\?action=case_files/, r => r.fulfill({json: {ok: true, connected: true, status: 'connected', has_root: true,
    folder: {path: '/Funerals/x', dropbox_url: 'https://www.dropbox.com/home/x'}, link: {auto: true, why: 'ת"ז זהה'}, can_upload: true,
    files: [{id: 1, name: 'רישיון קבורה.pdf', size: 2048, preview: 'pdf', thumb: false, added_via: 'dropbox', kind: ''},
      {id: 2, name: 'מכתב.docx', size: 4096, preview: 'pdf', thumb: false, added_via: 'ui', added_by: 'מנהל א', kind: ''},
      {id: 3, name: 'קבר.jpg', size: 900, preview: 'image', thumb: true, added_via: 'field', added_by: 'צלם', kind: 'grave_photo'}]}}));
  await page.route(/api\.php\?action=file_view/, r => {
    const id = new URL(r.request().url()).searchParams.get('id');
    return id === '3' ? r.fulfill({body: PNG, contentType: 'image/png'}) : r.fulfill({body: tinyPdf(), contentType: 'application/pdf'});
  });
  await page.route(/api\.php\?action=link_undo/, r => { undo = r.request().postDataJSON(); return r.fulfill({json: {ok: true, questions: []}}); });
  await page.route(/api\.php\?action=dropbox_status/, r => r.fulfill({json: {ok: true, dropbox: {status: 'connected', account_name: 'חשבון הארגון',
    root_path: '/Funerals', last_sync_at: null, last_webhook_at: null, last_error: null}, app_configured: true, can_admin: true, can_upload: true,
    folder_template: '{month}/{date} {name}', folder_template_default: '{month}/{date} {name}', folder_template_example: '2026-10/2026-10-14 ישראל ישראלי'}}));

  await page.goto(BASE + '/index.html');
  await page.waitForSelector('#records li.card');

  const first = process.env.FIRST;
  await screen(page, 'main');

  // U2: כל מונה, בלחיצה, מסנן — ומספר השורות = המונה
  for (const t of ['todo', 'done', 'bad', 'verify']) {
    await page.click(`[data-tile="${t}"]`);
    const n = +(await page.textContent(`[data-tile="${t}"] b`));
    const rows = await page.locator('#records li.card').count();
    ok(rows === n && (await page.getAttribute(`[data-tile="${t}"]`, 'aria-pressed')) === 'true', `U2 ${t}: ${n} במונה = ${rows} שורות`);
  }
  await page.click('[data-filter="all"]');
  ok(await page.locator('#records li.card').count() === 22, 'U2: "כל התיקים" — 22');

  // U5: מסך התיק — הבדיקה המותנית לא חלה ולא נספרת; המקור נפתח בצופה
  await page.locator('#records li.card', {hasText: first}).first().click();
  await page.waitForSelector('#editDialog[open] #checks .ck');
  await page.waitForSelector('#dbxFiles li');
  const sum = await page.textContent('#caseSummary .line');
  ok(/מתוך 8/.test(sum) && /1 לא חלות/.test(sum), 'U5: הפס סופר 8 בדיקות חלות, והמותנית "לא חלה" — ' + sum.trim());
  ok(await page.locator('#checks .ck.na').count() === 1, 'U5: שורת הבדיקה המותנית מסומנת "לא חלה"');
  const srcBtn = page.locator('#checks .ck .src button', {hasText: 'פתיחה'});
  ok(await srcBtn.count() === 1, 'U5: לבדיקה עם מקור — כפתור שפותח את המסמך');
  await screen(page, 'case');

  // ביטול קישור אוטומטי (L2א, מסך מ-12b)
  ok(await page.locator('.link-auto', {hasText: 'ת"ז זהה'}).count() === 1, 'ביטול קישור: הסיבה מוצגת בתיק');
  page.once('dialog', d => d.accept());
  await page.locator('.link-auto button', {hasText: 'ביטול הקישור'}).click();
  await page.waitForTimeout(300);
  ok(undo && typeof undo.case_id === 'string' && undo.case_id.length > 8, 'ביטול קישור: link_undo נשלח עם מזהה התיק');
  await page.waitForSelector('#editDialog[open] #checks .ck');
  await page.waitForSelector('#dbxFiles li');

  // U1: PDF ו-Word נפתחים בתוך האפליקציה כעמודים (pdf.js), תמונה — כתמונה; בלי קישור החוצה
  await page.locator('#checks .ck .src button', {hasText: 'פתיחה'}).click();
  await page.waitForSelector('#previewDialog[open]');
  await page.waitForSelector('#preview canvas, #preview iframe', {timeout: 15000});
  ok(await page.locator('#preview canvas').count() >= 1, 'U1: PDF מצויר בתוך האפליקציה');
  ok(await page.locator('#preview a[href*="dropbox"]').count() === 0, 'U1: אין קישור החוצה בצופה');
  await screen(page, 'viewer-pdf');
  await closeDialog(page);
  await page.locator('#dbxFiles button.use-row', {hasText: 'מכתב.docx'}).click();
  await page.waitForSelector('#preview canvas, #preview iframe', {timeout: 15000});
  ok(await page.locator('#preview canvas').count() >= 1, 'U1: Word (כ-PDF מהשרת) מצויר בתוך האפליקציה');
  await closeDialog(page);
  await page.locator('#dbxFiles button.use-row', {hasText: 'קבר.jpg'}).click();
  ok(await page.locator('#preview img.dbx-preview').count() === 1, 'U1: תמונה בתוך האפליקציה');
  await closeDialog(page);

  // U7 במשרד: 📍 ממלא נקודה בשדה מיקום הקבר
  await page.click('#fGps');
  await page.waitForFunction(() => /^31\.77/.test(document.getElementById('fGrave').value));
  ok(/^31\.771235, 35\.217812/.test(await page.inputValue('#fGrave')), 'U7: "המיקום שלי" בתיק ממלא את נקודת המיקום');
  await page.click('#caseTabs [data-pane="history"]'); await page.waitForTimeout(300);
  await screen(page, 'case-history');
  await closeDialog(page);

  // ההגדרות — כל הלשוניות הגלויות
  await page.click('#settingsBtn').catch(async () => { await page.click('#moreBtn'); await page.locator('#moreList button', {hasText: 'הגדרות'}).click(); });
  await page.waitForSelector('#settingsDialog[open]');
  const tabs = await page.$$eval('#settingsDialog [role="tab"]', bs => bs.filter(b => b.checkVisibility()).map(b => b.textContent.trim()));
  for (const [i, t] of tabs.entries()) {
    await page.locator('#settingsDialog [role="tab"]', {hasText: t}).first().click();
    await page.waitForTimeout(500);
    await screen(page, 'settings-' + i);
  }
  // L3: תבנית התיקייה — בעברית במסך, נשלחת לשרת כמו שהוא שומר
  await page.locator('#settingsDialog [role="tab"]', {hasText: 'קבצים'}).first().click();
  await page.waitForSelector('input[aria-label="תבנית התיקייה"]');
  ok(await page.inputValue('input[aria-label="תבנית התיקייה"]') === '{חודש}/{תאריך} {שם}', 'L3: התבנית מוצגת בעברית');
  let sent = null;
  await page.route(/api\.php\?action=dropbox_template_set/, r => { sent = r.request().postDataJSON(); return r.fulfill({json: {ok: true, folder_template: sent.template,
    folder_template_default: '{month}/{date} {name}', folder_template_example: '2026/14.10 ישראל ישראלי'}}); });
  await page.fill('input[aria-label="תבנית התיקייה"]', '{שנה}/{יום.חודש} {שם}');
  await page.locator('#sDropbox button', {hasText: 'שמירה'}).last().click();
  await page.waitForTimeout(300);
  ok(sent && sent.template === '{year}/{dm} {name}', 'L3: נשמר בשמות של השרת — ' + JSON.stringify(sent));
  await closeDialog(page);

  // חלונות נוספים
  for (const [btn, dlg, name] of [['#userBtn', '#userDialog', 'user'], ['#moreBtn', '#moreDialog', 'more']]) {
    await page.click(btn); await page.waitForSelector(dlg + '[open]'); await screen(page, name); await closeDialog(page);
  }

  // עובד שטח: U6 צילום בלי תיק, U7 המיקום שלי, U9 ו-320
  await page.goto(BASE + '/index.html#/field');
  await page.waitForSelector('#fieldApp #fwLoose');
  await screen(page, 'field');
  let upload = null;
  await page.route(/api\.php\?action=field_upload/, r => { upload = r.request().postData() || ''; return r.fulfill({json: {ok: true, duplicate: false, inbox: true, file: {file_id: 9, name: 'צילום מהשטח.jpg', link: null}, check: null}}); });
  await page.setInputFiles('#fwLooseIn', {name: 'IMG_1.png', mimeType: 'image/png', buffer: PNG});
  await page.waitForFunction(() => true, null, {timeout: 500}).catch(() => {});
  for (let i = 0; i < 30 && upload === null; i++) await page.waitForTimeout(200);
  ok(upload !== null && /name="case_id"\r\n\r\n\r\n/.test(upload), 'U6: צילום בלי תיק נשלח עם case_id ריק');
  await page.fill('#fwSearch', first.slice(0, 3));
  await page.waitForSelector('#fwResults li.card', {timeout: 5000});
  await page.locator('#fwResults li.card').first().click();
  await page.waitForSelector('#fieldDialog[open]');
  await page.click('#fwEdit');
  await page.click('#fwGps');
  await page.waitForFunction(() => /^31\.77/.test(document.getElementById('fwGrave').value));
  ok(true, 'U7: "המיקום שלי" בשטח ממלא את נקודת המיקום, והשדה נשאר לעריכה');
  await page.fill('#fwGrave', 'גוש 5 שורה 3');
  ok(await page.inputValue('#fwGrave') === 'גוש 5 שורה 3', 'U7: תיקון ידני');
  await screen(page, 'field-case');

  ok(!errors.length, 'בלי שגיאות JavaScript בדף' + (errors.length ? ' — ' + errors.join(' | ') : ''));
  await browser.close();
  console.log('  מסכים שנבדקו: ' + seen.join(', '));
  if (fails) { console.error(`ui.test.js: ${fails} כשלים`); process.exit(1); }
})().catch(e => { console.error(e); process.exit(1); });
