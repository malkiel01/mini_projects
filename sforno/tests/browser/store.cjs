/*
 * החנות בדפדפן אמיתי (אפיון 09 §5: 2 ו-4 במסך, 320px).
 * **לא רץ ב-run.php** — צריך Playwright ו-Chromium, שאין ב-runner של הפריסה. הרצה מקומית:  node sforno/tests/browser/store.cjs
 *
 * מרים php -S על תיקיית ספורנו עם מסד זמני (כמו offline.cjs). החנות — נתוני הדוגמה (lib/store-sample.json); מול החנות האמיתית — 👁 8 אצל הבעלים.
 * כאן: טעינת נתוני דוגמה בניהול, הקטלוג, חיפוש, עמוד מוצר ("לרכישה באתר" בלשונית חדשה, בלי סל), מועדפים, 320px.
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
        await p.goto(BASE + '#/');
        await p.waitForSelector('.tiles');
        check('בית: אריח החנות פותח #/store', await p.locator('a.tile[href="#/store"]').count() === 1);
        await p.goto(BASE + '#/admin/store');
        await p.waitForSelector('text=עוד אין חיבור לחנות');
        await shot('09-admin-empty');
        await Promise.all([p.waitForResponse(r => r.url().includes('store-sample')), p.click('text=טעינת נתוני דוגמה')]);
        await p.waitForSelector('text=נתוני דוגמה');
        check('ניהול: 12 מוצרים אחרי טעינת הדוגמה', (await p.locator('main').innerText()).includes('12 מוצרים'));
        await shot('09-admin-sample');
        await p.click('text=🔑 חיבור לחנות');
        await p.waitForSelector('dialog[open]');
        const types = await p.locator('dialog input[type=password]').count();
        check('חיבור: שדות המפתח — password ותמיד ריקים', types === 3 && (await p.locator('dialog input[type=password]').evaluateAll(a => a.every(i => i.value === ''))));
        await shot('09-admin-connection');
        await p.click('dialog button[aria-label="סגירה"]');

        await p.goto(BASE + '#/store');
        await p.waitForSelector('.st-card');
        const txt = await p.locator('main').innerText();
        check('קטלוג: חיווי נתוני דוגמה', txt.includes('נתוני דוגמה'));
        check('קטלוג: 12 ספרים', txt.includes('12 ספרים'), txt.slice(0, 200));
        await shot('09-catalog');
        await Promise.all([p.waitForResponse(r => r.url().includes('read-store-catalog') && r.url().includes('q=')), p.fill('input[type=search]', 'הלכה יומית')]);
        await p.waitForTimeout(150);
        check('חיפוש: 2 תוצאות', await p.locator('.st-card').count() === 2);
        await p.fill('input[type=search]', '');
        await p.waitForTimeout(600);
        await p.click('text=סינון וסדר');
        await Promise.all([p.waitForResponse(r => r.url().includes('author=')), p.selectOption('.st-filters select >> nth=1', 'ספורנו')]);
        await p.waitForTimeout(150);
        check('סינון לפי מחבר: 3 ספרים', await p.locator('.st-card').count() === 3, await p.locator('.st-card').count());
        await shot('09-catalog-filter');

        await p.goto(BASE + '#/store/p/101');
        await p.waitForSelector('.st-buy');
        const buy = p.locator('.st-buy');
        check('M2 לרכישה — עמוד המוצר בחנות, לשונית חדשה, noopener', await buy.getAttribute('href') === 'https://store.example/product/p101/'
            && await buy.getAttribute('target') === '_blank' && (await buy.getAttribute('rel')).includes('noopener'));
        const page = await p.locator('main').innerText();
        check('M2 אין סל, כמות או תשלום', !/סל|כמות|תשלום|קופון|לקופה/.test(page), page.match(/סל|כמות|תשלום|קופון/));
        check('מוצר: מחבר, הוצאה, מהדורה, כריכה, תוכן עניינים, ביקורות', ['ספורנו הוצאה לאור', 'מהדורה שנייה', 'כריכה', 'תוכן עניינים', 'ביקורות (3)', 'ספרים קשורים'].every(s => page.includes(s)));
        await shot('09-product');
        const out = p.waitForResponse(r => r.url().includes('store-out'));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), buy.click()]);
        check('store-out נשלח ונספר', (await out).status() === 200);
        await popup.close();
        await p.click('.st-fav');
        await p.waitForSelector('.st-fav[aria-pressed="true"]');
        await p.goto(BASE + '#/store/favs');
        await p.waitForSelector('.card');
        check('מועדפים: הספר ברשימה', (await p.locator('main').innerText()).includes('סידור ספורנו'));

        for (const route of ['#/store', '#/store/p/101', '#/admin/store']) {
            await p.goto(BASE + route);
            await p.waitForTimeout(400);
            await p.setViewportSize({ width: 320, height: 700 });
            await p.waitForTimeout(200);
            const w = await p.evaluate(() => document.documentElement.scrollWidth);
            check(`320px ${route} — בלי גלילה אופקית`, w <= 320, w);
            await shot('09-320-' + route.replace(/[#/]+/g, '-'));
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
