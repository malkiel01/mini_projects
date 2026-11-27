/*
 * בדיקת דפדפן למסך הניהול (admin.html). מקומית בלבד, כמו check.js:
 *   php -S 127.0.0.1:8765 -t . -d auto_prepend_file=$PWD/cyber-academy/tests/browser/dev-identity.php   (משורש הריפו)
 *   node cyber-academy/tests/browser/admin-check.js
 * התשובות של api/integrations.php מוזרקות דרך page.route (לא תלויות בקונפיג). האסימון הוא ערך מזויף.
 * יוצא בקוד 1 על שגיאת קונסול, גלילה אופקית, או אסימון שנשאר בדף / באחסון.
 */
'use strict';
const { chromium } = require('playwright');

const BASE = process.env.CA_BASE || 'http://127.0.0.1:8765/cyber-academy/';
const URL = BASE + 'admin.html';
const FAKE_TOKEN = 'admin-check-fake-token-0123456789';
const WIDTHS = [320, 390, 1280];
let failures = 0;
const check = (name, ok, extra = '') => {
  console.log((ok ? '✓ ' : '✗ ') + name + (extra ? ' — ' + extra : ''));
  if (!ok) failures++;
};

/** מצב מוקי של ה-API. mode: 'manager' | 'viewer' | 'outage'. */
function mockApi(page, mode, state) {
  return page.route('**/api/integrations.php', async (route) => {
    const req = route.request();
    if (mode === 'viewer') return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'forbidden', error: 'למנהל בלבד' }) });
    if (mode === 'outage') return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'internal' }) });
    if (req.method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, pathConfigured: true, tokenStored: state.stored }) });
    }
    const body = JSON.parse(req.postData() || '{}');
    if (body.action === 'save_token') {
      state.received = body.token;
      state.stored = true;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, configured: true }) });
    }
    if (body.action === 'sync') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, status: 'no_source_connected', results: [] }) });
    }
    return route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'bad_request' }) });
  });
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  try {
    for (const w of WIDTHS) {
      const page = await browser.newPage({ viewport: { width: w, height: 800 } });
      const errors = [];
      // שגיאות המשאב 403/500 הן התגובות המוזרקות בכוונה (page.route), לא שגיאות אמיתיות
      const isInjected = (t) => /^Failed to load resource: the server responded with a status of (403|500)/.test(t);
      page.on('console', (m) => { if (m.type() === 'error' && !isInjected(m.text())) errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      const state = { stored: false, received: null };

      // ---- לא מנהל ----
      await mockApi(page, 'viewer', state);
      await page.goto(URL, { waitUntil: 'networkidle' });
      check(`${w}: לא מנהל — מוצג "למנהל בלבד"`, await page.isVisible('#denied') && !(await page.isVisible('#panel')));
      check(`${w}: לא מנהל — אין שדה אסימון בדף בכלל`, (await page.locator('#token').count()) === 0);
      await page.unroute('**/api/integrations.php');

      // ---- מנהל: הנתיב לא מוגדר ----
      await page.route('**/api/integrations.php', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, pathConfigured: false, tokenStored: false }) }));
      await page.goto(URL, { waitUntil: 'networkidle' });
      check(`${w}: נתיב לא מוגדר — הכפתור שמור מושבת`, await page.isDisabled('#save'));
      await page.unroute('**/api/integrations.php');

      // ---- מנהל: זרימה מלאה ----
      await mockApi(page, 'manager', state);
      await page.goto(URL, { waitUntil: 'networkidle' });
      check(`${w}: מנהל — השדה מסוג סיסמה ואוטו-השלמה כבויה`,
        (await page.getAttribute('#token', 'type')) === 'password' && (await page.getAttribute('#token', 'autocomplete')) === 'new-password');
      await page.fill('#token', FAKE_TOKEN);
      await page.click('#save');
      await page.waitForFunction(() => document.getElementById('token').value === '');
      check(`${w}: אחרי שמירה — השדה מתנקה`, (await page.inputValue('#token')) === '');
      check(`${w}: אחרי שמירה — ההודעה מאשרת שמירה`, (await page.textContent('#save-msg')).includes('נשמר'));
      check(`${w}: האסימון לא מופיע בתוכן הדף`, !(await page.content()).includes(FAKE_TOKEN));
      const storage = await page.evaluate(() => JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
      check(`${w}: האסימון לא נשמר באחסון דפדפן`, !storage.includes(FAKE_TOKEN));
      check(`${w}: נשלח לשרת בדיוק פעם אחת`, state.received === FAKE_TOKEN);
      check(`${w}: הסטטוס מתעדכן ל"אסימון שמור"`, (await page.textContent('#token-state')).includes('שמור בשרת'));

      // ---- סנכרון: אין מקור מחובר — לא "הצלחה" ----
      await page.click('#sync');
      await page.waitForFunction(() => document.getElementById('sync-msg').textContent.includes('אין מקור'));
      const syncText = await page.textContent('#sync-msg');
      check(`${w}: סנכרון בלי מקור — "אין מקור דיווחים מחובר", לא הצלחה`, syncText.includes('אין מקור דיווחים מחובר') && !syncText.includes('הצלחה') && !syncText.includes('סונכרן'));

      // ---- שגיאת שרת לא מציגה תוכן ----
      await page.unroute('**/api/integrations.php');
      await mockApi(page, 'outage', state);
      await page.click('#sync');
      await page.waitForFunction(() => document.getElementById('sync-msg').textContent.length > 0 && !document.getElementById('sync-msg').textContent.includes('מסנכרן'));
      check(`${w}: שגיאת שרת — הודעה כללית`, (await page.textContent('#sync-msg')).includes('נכשל'));

      // ---- גלילה אופקית ----
      await page.unroute('**/api/integrations.php');
      await mockApi(page, 'manager', state);
      await page.goto(URL, { waitUntil: 'networkidle' });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
      check(`${w}: אין גלילה אופקית`, !overflow);

      check(`${w}: אין שגיאות קונסול`, errors.length === 0, errors.join(' | '));
      await page.close();
    }
  } finally {
    await browser.close();
  }
  console.log(failures === 0 ? '\nכל הבדיקות עברו' : `\n${failures} בדיקות נכשלו`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(1); });
