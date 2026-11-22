/*
 * התזכורות בדפדפן אמיתי (אפיון 08 §6: 1, 2, 6, 7 — מה שאפשר בלי מכשיר אמיתי).
 * **לא רץ ב-run.php** — צריך Playwright ו-Chromium, שאין ב-runner של הפריסה. הרצה מקומית:  node sforno/tests/browser/reminders.cjs
 *
 * מרים php -S על תיקיית ספורנו עם מסד זמני (כמו offline.cjs). Push אמיתי (ספק, אפליקציה סגורה) — רק במכשיר: dev/push-report.md.
 * כאן: אורח, העורך, הקרובות, השתקה, .ics, באנר, מצב הערוצים, ו-320px. יוצא עם קוד 1 אם משהו נכשל. צילומים — ב-$SF_SHOTS.
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
        const ctx = await browser.newContext({ viewport: { width: 390, height: 860 }, permissions: [], acceptDownloads: true });
        const p = await ctx.newPage();
        const errors = [];
        p.on('pageerror', e => errors.push(e.message));
        const shot = async name => { if (SHOTS) await p.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }); };
        await p.goto(BASE + '#/');
        await p.waitForSelector('.tiles');
        check('בית: אריח תזכורות', await p.locator('a.tile[href="#/reminders"]').count() === 1);
        await p.goto(BASE + '#/reminders');
        await p.waitForSelector('text=התזכורות שלי');
        check('L6 המסך מציג את מגבלת היומן', (await p.locator('main').innerText()).includes('אירוע שנוסף ליומן לא יתעדכן ולא יבוטל מספורנו'));
        check('ערוץ Push — מצב אמיתי (לא "פעיל" לפני אישור)', !(await p.locator('main').innerText()).match(/התראה כשהאפליקציה סגורה\s*פעיל/));
        check('מייל לאורח — "דורש חשבון"', (await p.locator('main').innerText()).includes('דורש חשבון'));
        await shot('08-reminders-empty');

        // L7: אורח יוצר תזכורת — בלי מייל ובלי חשבון
        await p.locator('button:has-text("+ תזכורת")').click();
        await p.waitForSelector('dialog');
        await p.locator('dialog .seg label:has-text("מניין") input').check({ force: true });
        await p.locator('dialog input[type="text"]').fill('מניין ותיקין');
        const now = await p.evaluate(() => { const d = new Date(Date.now() + 3 * 3600000); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); });
        await p.locator('dialog input[type="time"]').fill(now);
        await p.locator('dialog .row-btns button:has-text("כל יום")').click();
        await shot('08-reminders-editor');
        await p.locator('dialog .sheet-head button:has-text("שמירה")').click();
        await p.waitForTimeout(500);
        const rems = await p.evaluate(() => SF.store.get('reminders'));
        check('L7 התזכורת נשמרה במכשיר (אורח)', Array.isArray(rems) && rems.length === 1 && rems[0].type === 'minyan' && rems[0].name === 'מניין ותיקין', rems);
        check('L7 הופיעה בקרובות', (await p.locator('main').innerText()).includes('מניין ותיקין'));
        check('L6 כפתור "ליומן" למופע שאינו מושתק', await p.locator('button[aria-label^="הוספה ליומן"]').count() > 0);
        const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('button[aria-label^="הוספה ליומן"]').first().click()]);
        const ics = fs.readFileSync(await dl.path(), 'utf8');
        check('L6 .ics הורד: VEVENT עם השם והקישור', ics.includes('BEGIN:VEVENT') && ics.includes('מניין ותיקין') && ics.includes('#/reminders'));
        await p.waitForTimeout(300);
        await shot('08-reminders-list');

        // מושתק: תזכורת בשבת הקרובה, 10:00
        await p.evaluate(async () => {
            await SF.rem.save({ name: 'שבת', type: 'fixed', time: '10:00', days: [6], active: true });
        });
        await p.waitForTimeout(500);
        const u = await p.evaluate(async () => (await SF.rem.upcoming(24 * 8)).occ.map(o => ({ n: o.r.name, m: o.muted })));
        check('L5 בתוך האפליקציה: שבת 10:00 מסומנת מושתקת', u.some(o => o.n === 'שבת' && o.m), u);
        const blocked = await p.evaluate(async () => { const x = await SF.rem.upcoming(24 * 8); const o = x.occ.find(y => y.muted); return o ? SF.rem.icsFile(o) === null : null; });
        check('L6 אין .ics למופע מושתק', blocked === true, blocked);

        // באנר בתוך האפליקציה
        await p.evaluate(async () => {
            const d = new Date(Date.now() + 1500);
            await SF.rem.save({ name: 'באנר עכשיו', type: 'fixed', time: String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'), days: [0, 1, 2, 3, 4, 5, 6], active: true });
        });
        await p.waitForTimeout(2500);
        const day = await p.evaluate(() => new Date().getDay());
        if (day !== 6) check('בתוך האפליקציה: באנר כשמגיע הזמן', await p.locator('.rem-banner:has-text("באנר עכשיו")').count() === 1);
        await shot('08-reminders-banner');

        await p.setViewportSize({ width: 320, height: 700 });
        await p.waitForTimeout(200);
        const w = await p.evaluate(() => document.documentElement.scrollWidth);
        check('320px — בלי גלילה אופקית', w <= 320, w);
        await shot('08-reminders-320');
        check('בלי שגיאות JS', errors.length === 0, errors.slice(0, 3));
    } finally {
        await browser.close();
        srv.kill();
        fs.rmSync(TMP, { recursive: true, force: true });
    }
    console.log(bad ? `  ${bad} מתוך ${n} נכשלו` : `  ✓ ${n} בדיקות`);
    process.exit(bad ? 1 : 0);
})();
