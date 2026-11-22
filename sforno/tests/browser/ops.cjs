/*
 * התפעול בדפדפן אמיתי (אפיון 10): "דיווח על טעות" מתוך קטע (רק מזהים — לא ההערה), לוח הבקרה, הדיווחים, המדדים, התמיכה,
 * הגיבויים (גיבוי, בדיקת שחזור, הורדה) וטופס הקשר — ב-390px וב-320px.
 * **לא רץ ב-run.php** — צריך Playwright ו-Chromium. הרצה מקומית:  node sforno/tests/browser/ops.cjs  (SF_SHOTS=<תיקייה> — צילומים)
 */
'use strict';

const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..', '..');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'sforno-e2e-ops-'));
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
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '907', 'name' => 'בודק', 'email' => 'e2e@example.test', 'email_verified' => true, 'platform_admin' => true, 'granted' => true]);
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
        p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
        // ── הדיווח מתוך קטע (N1): המזהים בלבד ──────────────────
        await p.goto(BASE + '#/siddur');
        await p.locator('button:has-text("ליצור תוכן דוגמה")').click();
        await p.waitForSelector('.list a.use-row');
        await p.locator('.list a.use-row:has-text("שחרית")').click();
        await p.waitForSelector('.rd-text .sf-doc');
        await p.waitForTimeout(400);
        const sid = await p.evaluate(() => document.querySelector('.rd-text p[data-sid]').dataset.sid);
        await p.locator('.rd-text [data-sid="' + sid + '"]').click();
        await p.locator('.rd-act button[aria-label="הערה אישית"]').click();
        await p.fill('dialog textarea', 'הערה-פרטית-בדפדפן');
        await p.locator('dialog button:has-text("שמירה")').click();
        await p.waitForTimeout(300);
        if (await p.locator('.rd-act').isHidden()) await p.locator('.rd-text [data-sid="' + sid + '"]').click();
        await shot('10-reader-actions');
        await p.locator('.rd-act button[aria-label="דיווח על טעות"]').click();
        await p.waitForSelector('dialog[open]');
        await shot('10-report-sheet');
        await p.fill('dialog textarea', 'חסר ניקוד במילה');
        const [req] = await Promise.all([p.waitForRequest(r => r.url().includes('report-create')), p.locator('dialog button:has-text("שליחה")').click()]);
        const sent = JSON.parse(req.postData());
        check('N1 הדיווח נשלח עם edition / revision / sid', sent.kind === 'content' && /^[a-z0-9]{16}$/.test(sent.edition) && /^[a-z0-9]{16}$/.test(sent.revision) && sent.sid === sid, sent);
        check('N1 …ובלי ההערה', !JSON.stringify(sent).includes('הערה-פרטית'), sent);
        await p.waitForSelector('text=הדיווח נשלח');

        // ── הניהול ───────────────────────────────────────────
        await p.goto(BASE + '#/admin');
        await p.waitForSelector('a[href="#/admin/ops"]');
        await p.goto(BASE + '#/admin/ops');
        await p.waitForSelector('text=מצב השירותים');
        const dash = await p.locator('main').innerText();
        check('לוח הבקרה: התראות (מתזמן, גיבוי), דיווח פתוח, מה פורסם', dash.includes('המתזמן') && dash.includes('עוד אין גיבוי') && dash.includes('1 פתוחים') && dash.includes('פורסם לאחרונה'), dash.slice(0, 300));
        await shot('10-dashboard');
        await p.goto(BASE + '#/admin/reports');
        await p.waitForSelector('a.use-row[href^="#/admin/reports/"]');
        await shot('10-reports');
        await p.locator('a.use-row[href^="#/admin/reports/"]').first().click();
        await p.waitForSelector('h3.sec-title:has-text("המיקום")');
        check('דיווח: קפיצה לעורך ולקורא', await p.locator('a[href^="#/admin/content/e/"]').count() === 1 && await p.locator('a[href^="#/r/"]').count() === 1);
        await shot('10-report');
        await Promise.all([p.waitForResponse(r => r.url().includes('report-set')), p.locator('button:has-text("בטיפול")').click()]);
        await p.waitForSelector('.chip:has-text("בטיפול")');
        await p.goto(BASE + '#/admin/metrics');
        await p.waitForSelector('table.tbl');
        check('מדדים: פתיחה אחת של הסידור', (await p.locator('main').innerText()).includes('פתיחות 1'));
        await shot('10-metrics');
        await p.goto(BASE + '#/admin/support');
        await p.fill('input[type=search]', 'nobody@example.com');
        await p.keyboard.press('Enter');
        await p.waitForSelector('text=אין קורא');
        await p.goto(BASE + '#/dev/backup');
        await p.waitForSelector('text=עוד אין גיבוי');
        await Promise.all([p.waitForResponse(r => r.url().includes('dev-backup-now')), p.locator('button:has-text("גיבוי עכשיו")').click()]);
        await p.waitForSelector('button:has-text("בדיקת שחזור")');
        await Promise.all([p.waitForResponse(r => r.url().includes('dev-backup-verify')), p.locator('button:has-text("בדיקת שחזור")').first().click()]);
        await p.waitForSelector('text=בדיקת השחזור עברה');
        const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('a:has-text("הורדה")').first().click()]);
        check('הורדת הגיבוי — zip', /^sforno-\d{8}-\d{6}-[a-z0-9]{6}\.zip$/.test(dl.suggestedFilename()), dl.suggestedFilename());
        await shot('10-backup');
        await p.goto(BASE + '#/contact');
        await p.waitForSelector('textarea');
        await shot('10-contact');

        for (const route of ['#/admin/ops', '#/admin/reports', '#/admin/metrics', '#/admin/support', '#/dev/backup', '#/contact']) {
            await p.goto(BASE + route);
            await p.waitForTimeout(500);
            await p.setViewportSize({ width: 320, height: 700 });
            await p.waitForTimeout(200);
            const w = await p.evaluate(() => document.documentElement.scrollWidth);
            check(`320px ${route} — בלי גלילה אופקית`, w <= 320, w);
            await shot('10-320-' + route.replace(/[#/]+/g, '-'));
            await p.setViewportSize({ width: 390, height: 860 });
        }
        check('בלי שגיאות JS', errors.length === 0, errors.slice(0, 3));
    } finally {
        await browser.close();
        srv.kill();
        fs.rmSync(TMP, { recursive: true, force: true });
    }
    console.log(bad ? `  ${bad} מתוך ${n} נכשלו` : `  ✓ ${n} בדיקות`);
    process.exit(bad ? 1 : 0);
})();
