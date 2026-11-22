/*
 * לוח שנה, זמני היום והלכה יומית בדפדפן אמיתי (אפיון 07 §5: 4, 5, 8, 9 — ו-3 בתהילים).
 * **לא רץ ב-run.php** — צריך Playwright ו-Chromium, שאין ב-runner של הפריסה. הרצה מקומית:  node sforno/tests/browser/daily.cjs
 *
 * מרים php -S על תיקיית ספורנו עם מסד זמני (כמו offline.cjs). "מצב טיסה" = context.setOffline(true). סירוב למיקום = context בלי
 * הרשאת geolocation; מיקום המכשיר = הרשאה + קואורדינטות. יוצא עם קוד 1 אם משהו נכשל. צילומי מסך — ב-$SF_SHOTS (אם הוגדר).
 */
'use strict';

const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..', '..');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'sforno-e2e-'));
const PORT = 8100 + Math.floor(Math.random() * 800);
const ORIGIN = `http://localhost:${PORT}/`;
const BASE = ORIGIN + 'index.html';
const SHOTS = process.env.SF_SHOTS || '';

fs.mkdirSync(path.join(TMP, 'data'));
fs.copyFileSync(path.join(ROOT, 'data', '.htaccess'), path.join(TMP, 'data', '.htaccess'));
fs.writeFileSync(path.join(TMP, 'prepend.php'), `<?php
define('SF_DATA_DIR', ${JSON.stringify(path.join(TMP, 'data'))});
define('SF_DB_PATH', ${JSON.stringify(path.join(TMP, 'data', 'sforno.sqlite'))});
$GLOBALS['sf_http'] = fn(string $u) => str_contains($u, 'sso.php') ? [200, '{"app":"sforno","connected":true,"login":{"platform":true}}'] : [403, '', 5];
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '907', 'name' => 'בודק', 'email' => 'e2e@example.test', 'email_verified' => true, 'platform_admin' => false, 'granted' => true]);
`);

let n = 0, bad = 0;
const check = (name, cond, got) => { n++; if (!cond) { bad++; console.log('  ✗ ' + name + (got !== undefined ? ' — ' + JSON.stringify(got) : '')); } };

(async () => {
    const srv = spawn('php', ['-S', `localhost:${PORT}`, '-d', `auto_prepend_file=${path.join(TMP, 'prepend.php')}`], { cwd: ROOT, stdio: 'ignore' });
    await new Promise(r => setTimeout(r, 700));
    const browser = await chromium.launch();
    try {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 860 }, permissions: [] });
        const p = await ctx.newPage();
        const errors = [];
        p.on('pageerror', e => errors.push(e.message));
        p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|ERR_INTERNET_DISCONNECTED/.test(m.text())) errors.push(m.text()); });
        const shot = async name => { if (SHOTS) await p.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }); };
        const idle = (ms = 400) => p.waitForTimeout(ms);

        // ── מסך הבית בלי הלכה (K4) ────────────────────────────
        await p.goto(BASE + '#/');
        await p.waitForSelector('.tiles');
        await idle(800);
        check('K4 בית: אין הלכה — אין רכיב הלכה', await p.locator('.hl-home').count() === 0);
        check('K4 בית: אין טקסט חלופי', !(await p.locator('main').innerText()).includes('אין הלכה'));
        check('בית: אריחי הלכה יומית וזמני היום פתוחים', await p.locator('a.tile[href="#/zmanim"]').count() === 1 && await p.locator('a.tile[href="#/halacha"]').count() === 1);

        // ── K9: סירוב למיקום ← בחירת עיר, בלי שגיאה ──────────────
        await p.goto(BASE + '#/zmanim');
        await p.waitForSelector('.zm-need');
        await shot('07-zmanim-empty');
        await p.locator('.zm-need button:has-text("בחירת מיקום")').click();
        await p.waitForSelector('dialog .loc-sheet');
        await p.locator('dialog button:has-text("מיקום המכשיר")').click();
        await p.waitForSelector('dialog .notice');
        check('K9 סירוב ← "לא אושרה גישה למיקום. בחרו עיר מהרשימה."', (await p.locator('dialog .notice').innerText()).includes('בחרו עיר מהרשימה'));
        check('K9 בלי toast של שגיאה', await p.locator('#toast.err:not([hidden])').count() === 0);
        await p.locator('dialog input[type=search]').fill('ירוש');
        await shot('07-loc-sheet');
        await p.locator('dialog .loc-list .card:has-text("ירושלים") button').click();
        await idle();

        // ── K5: אין שיטה מאושרת ← בחירה, בלי שעות ─────────────────
        check('K5 אין ברירת מחדל — מבקש לבחור שיטה', await p.locator('.zm-need:has-text("בחרו שיטת חישוב")').count() === 1);
        check('K5 …ולא מוצגת שום שעה', await p.locator('.zm-time').count() === 0);
        const def = { day_end: 'tzeit', zmanim: [
            { key: 'sunrise', name: 'זריחה', type: 'angle', deg: 0.833, dir: 'am' }, { key: 'shma', name: 'סוף זמן ק"ש', type: 'hours', from: 'sunrise', to: 'sunset', hours: 3 },
            { key: 'sunset', name: 'שקיעה', type: 'angle', deg: 0.833, dir: 'pm' }, { key: 'tzeit', name: 'צאת הכוכבים', type: 'angle', deg: 8.5, dir: 'pm' }] };
        await p.evaluate(async def => {
            const r = await SF.api('zmanim-method-save', { body: { name: 'שיטה לבדיקה בדפדפן', def, base: 0 } });
            await SF.api('zmanim-method-status', { body: { method: r.method.pid, status: 'approved' } });
        }, def);
        await p.reload();
        await p.waitForSelector('.zm-need');
        await p.locator('.zm-need button:has-text("בחירת שיטה")').click();
        await p.waitForSelector('dialog .card');
        check('שיטת הבדיקה מוצגת למנהל, מסומנת "לבדיקה בלבד"', await p.locator('dialog .card:has-text("לבדיקה בלבד")').count() === 1);
        await p.locator('dialog .card:has-text("שיטה לבדיקה בדפדפן")').click();
        await p.waitForSelector('.zm-time');
        const head = await p.locator('.summary').innerText();
        check('המסך מציג שיטה, מיקום, תאריך ואזור זמן', head.includes('ירושלים') && head.includes('שיטה לבדיקה בדפדפן') && head.includes('Asia/Jerusalem') && /תש/.test(head), head);
        check('4 זמנים בשעות', await p.locator('.zm-time').count() === 4);
        await shot('07-zmanim');

        // ── K8: הורדת חודש ← מצב טיסה ─────────────────────────────
        await p.locator('.dl-line button:has-text("הורדת חודש")').click();
        await p.waitForSelector('.dl-line .dl-st.ready');
        const before = await p.locator('.zm-time').allInnerTexts();
        await ctx.setOffline(true);
        await p.reload();
        await p.waitForSelector('.zm-list');
        check('K8 בלי רשת — הזמנים מהחבילה, אותן שעות', JSON.stringify(await p.locator('.zm-time').allInnerTexts()) === JSON.stringify(before));
        check('K8 …עם שם העיר והשיטה', (await p.locator('main').innerText()).includes('מהחבילה שהורדה (שיטה לבדיקה בדפדפן'));
        await p.locator('.summary .line:has-text("מיקום") button').click();
        await p.locator('dialog input[type=search]').fill('בני ברק');
        await p.locator('dialog .loc-list .card:has-text("בני ברק") button').click();
        await p.waitForSelector('.notice.warn');
        check('K8 שינוי עיר בלי רשת ← "הזמנים מחושבים לירושלים — נדרש עדכון"', (await p.locator('.notice.warn').innerText()).includes('הזמנים מחושבים לירושלים — נדרש עדכון'));
        await shot('07-zmanim-offline-moved');
        await ctx.setOffline(false);
        await p.reload();   // הדף עלה בלי רשת — כקורא; מנהל מזוהה שוב רק בטעינה מחדש (06)
        await p.waitForSelector('.zm-list');

        // ── הלכה: פרסום להיום ← מסך הבית ──────────────────────────
        const today = await p.evaluate(async () => (await SF.daily.state()).today.heb);
        await p.goto(BASE + '#/admin/halacha/' + today.m + '/' + today.d);
        await p.waitForSelector('textarea.sf-html');
        await p.locator('textarea.sf-html').fill('<h3>הלכה לבדיקה</h3><p>מי שאמר <b>ברוך שאמר</b> — אומר בעמידה.</p>');
        await idle();
        await shot('07-admin-halacha-editor');
        p.once('dialog', d => d.accept());
        await p.locator('button:has-text("🚀 פרסום")').click();
        await p.waitForSelector('.chip.ok:has-text("פורסם")');
        await p.goto(BASE + '#/admin/halacha');
        await p.waitForSelector('.hl-day.published');
        await shot('07-admin-halacha');
        await p.goto(BASE + '#/');
        await p.waitForSelector('.hl-home');
        check('בית: הלכה של היום — רכיב עם התוכן', (await p.locator('.hl-home').innerText()).includes('ברוך שאמר'));
        await shot('07-home');
        await p.goto(BASE + '#/halacha');
        await p.waitForSelector('.hl-item');
        check('מסך ההלכה: "מתחלף בצאת הכוכבים · בני ברק" (המיקום הפעיל)', (await p.locator('.summary').innerText()).includes('מתחלף בצאת הכוכבים · בני ברק'));
        await shot('07-halacha');
        await p.goto(BASE + '#/admin/zmanim');
        await p.waitForSelector('.list .card');
        await shot('07-admin-methods');
        await p.locator('.list .card a.body:has-text("שיטה לבדיקה בדפדפן")').click();
        await p.waitForSelector('.zm-edit-row');
        await shot('07-admin-method-editor');

        // ── מיקום המכשיר (הרשאה) ─────────────────────────────────
        const ctx2 = await browser.newContext({ viewport: { width: 320, height: 700 }, permissions: ['geolocation'], geolocation: { latitude: 32.0837, longitude: 34.8331 } });
        const q = await ctx2.newPage();
        q.on('pageerror', e => errors.push(e.message));
        await q.goto(BASE + '#/zmanim');
        await q.waitForSelector('.zm-need');
        await q.locator('.zm-need button:has-text("בחירת מיקום")').click();
        await q.locator('dialog button:has-text("מיקום המכשיר")').click();
        await q.waitForTimeout(600);
        const loc = await q.evaluate(() => SF.store.get('loc'));
        check('מיקום המכשיר — מעוגל, אזור הזמן של העיר הקרובה', loc && loc.kind === 'device' && loc.lat === 32.08 && loc.tz === 'Asia/Jerusalem', loc);
        if (SHOTS) await q.screenshot({ path: path.join(SHOTS, '07-zmanim-320.png'), fullPage: true });
        const w = await q.evaluate(() => document.documentElement.scrollWidth);
        check('320px — בלי גלילה אופקית', w <= 320, w);

        check('בלי שגיאות JS', errors.length === 0, errors.slice(0, 3));
    } finally {
        await browser.close();
        srv.kill();
        fs.rmSync(TMP, { recursive: true, force: true });
    }
    console.log(bad ? `  ${bad} מתוך ${n} נכשלו` : `  ✓ ${n} בדיקות`);
    process.exit(bad ? 1 : 0);
})();
