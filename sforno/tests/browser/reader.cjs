/*
 * הקורא בדפדפן אמיתי (אפיון 04 §6: קריטריונים 2, 3, 6, 7, 9, 10, ו-1/11 בדרך). **לא רץ ב-run.php** — צריך Playwright ו-Chromium,
 * שאין ב-runner של הפריסה. הרצה מקומית:  node sforno/tests/browser/reader.cjs
 *
 * מרים php -S על תיקיית ספורנו עם מסד זמני (auto_prepend: SF_DATA_DIR, זהות מהשער, בלי רשת), יוצר תוכן דוגמה, ובודק.
 * יוצא עם קוד 1 אם משהו נכשל. צילומי מסך — ב-$SF_SHOTS (אם הוגדר).
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
const BASE = `http://localhost:${PORT}/index.html`;
const SHOTS = process.env.SF_SHOTS || '';

fs.mkdirSync(path.join(TMP, 'data'));
fs.copyFileSync(path.join(ROOT, 'data', '.htaccess'), path.join(TMP, 'data', '.htaccess'));
fs.writeFileSync(path.join(TMP, 'prepend.php'), `<?php
define('SF_DATA_DIR', ${JSON.stringify(path.join(TMP, 'data'))});
define('SF_DB_PATH', ${JSON.stringify(path.join(TMP, 'data', 'sforno.sqlite'))});
$GLOBALS['sf_http'] = fn(string $u) => str_contains($u, 'sso.php') ? [200, '{"app":"sforno","connected":true,"login":{"platform":true}}'] : [403, '', 5];
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '901', 'name' => 'בודק', 'email' => 'e2e@example.test', 'email_verified' => true, 'platform_admin' => false, 'granted' => true]);
`);

let n = 0, bad = 0;
const check = (name, cond, got) => { n++; if (!cond) { bad++; console.log('  ✗ ' + name + (got !== undefined ? ' — ' + JSON.stringify(got) : '')); } };

(async () => {
    const srv = spawn('php', ['-S', `localhost:${PORT}`, '-d', `auto_prepend_file=${path.join(TMP, 'prepend.php')}`], { cwd: ROOT, stdio: 'ignore' });
    await new Promise(r => setTimeout(r, 700));
    const browser = await chromium.launch();
    try {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 860 } });
        const p = await ctx.newPage();
        const errors = [];
        p.on('pageerror', e => errors.push(e.message));
        p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
        const shot = async name => { if (SHOTS) await p.screenshot({ path: path.join(SHOTS, name + '.png') }); };
        const idle = () => p.waitForTimeout(350);

        // ── תוכן דוגמה, וההסבר החד-פעמי (K11) ─────────────────
        await p.goto(BASE + '#/siddur');
        await p.locator('button:has-text("ליצור תוכן דוגמה")').click();
        await p.waitForSelector('.list a.use-row');
        check('K11 ההסבר על שמירה מקומית מוצג', await p.locator('.notice:has-text("עלולה להימחק")').count() === 1);
        await shot('siddur');
        await p.locator('.notice button').click();
        await p.goto(BASE + '#/tehillim');
        await p.waitForSelector('.th-grid');
        check('K11 ההסבר — פעם אחת', await p.locator('.notice:has-text("עלולה להימחק")').count() === 0);
        await shot('tehillim');

        // ── K2: המקום נשמר במעבר גלילה ↔ מקטע ↔ גלילה, ובשינוי גופן ─
        await p.goto(BASE + '#/siddur');
        await p.locator('.list a.use-row:has-text("שחרית")').click();
        await p.waitForSelector('.rd-text .sf-doc');
        await idle();
        const target = await p.evaluate(() => [...document.querySelectorAll('.rd-text p[data-sid]')].find(x => x.textContent.includes('מַלְבִּישׁ')).dataset.sid);
        await p.evaluate(sid => SF.reader.restore({ sid, off: 0.3 }), target);
        await idle();
        const at = async () => p.evaluate(() => SF.reader.capture());
        const p0 = await at();
        check('K2 נקודת ההתחלה', p0 && p0.sid === target, p0);
        await p.evaluate(() => SF.reader.setMode('section')); await idle();
        const p1 = await at();
        check('K2 גלילה → מקטע: אותו קטע בראש המסך', p1.sid === target && Math.abs(p1.off - p0.off) < 0.15, [p0, p1]);
        check('K8 במקטע יחיד — שאר המקטעים hidden (מחוץ לעץ הנגישות)', await p.evaluate(() => [...document.querySelectorAll('.rd-text section.sf-sec')].filter(x => x.hidden).length >= 3));
        await shot('reader-section');
        await p.evaluate(() => SF.reader.setMode('scroll')); await idle();
        const p2 = await at();
        check('K2 מקטע → גלילה: אותו קטע', p2.sid === target && Math.abs(p2.off - p0.off) < 0.15, [p0, p2]);
        await p.locator('button[aria-label="הגדרות תצוגה"]').click();
        await p.locator('dialog input[type=range][aria-label="גודל הגופן"]').fill('34');
        await idle();
        const p3 = await at();
        check('K2 שינוי גודל הגופן: אותו קטע', p3.sid === target && Math.abs(p3.off - p0.off) < 0.2, [p0, p3]);
        await shot('reader-settings');

        // ── K6: הסתרת ניקוד; העתקה עם ניקוד ומקור ─────────────
        await p.locator('dialog label.switch:has-text("ניקוד") input').uncheck();
        await idle();
        await p.locator('dialog button[aria-label="סגירה"]').click();
        const shown = await p.evaluate(sid => document.querySelector('.rd-text [data-sid="' + sid + '"]').textContent, target);
        check('K6 הסתרת ניקוד — הטקסט מוצג בלעדיו', shown.includes('מלביש ערמים') && !/[ְ-ּ]/.test(shown), shown);
        const copied = await p.evaluate(sid => {
            const el = document.querySelector('.rd-text [data-sid="' + sid + '"]');
            const tn = [...el.childNodes].find(x => x.nodeType === 3);
            const i = tn.nodeValue.indexOf('מלביש');
            const r = document.createRange(); r.setStart(tn, i); r.setEnd(tn, i + 'מלביש ערמים'.length);
            const sel = getSelection(); sel.removeAllRanges(); sel.addRange(r);
            const dt = new DataTransfer();
            document.dispatchEvent(new ClipboardEvent('copy', { clipboardData: dt, bubbles: true, cancelable: true }));
            sel.removeAllRanges();
            return dt.getData('text/plain');
        }, target);
        check('K6 העתקה מעתיקה עם ניקוד ועם מקור', copied === 'מַלְבִּישׁ עֲרֻמִּים\n— סידור (דוגמה) עדות המזרח — דוגמה, ברכות השחר', copied);
        await p.evaluate(() => SF.store.update('settings', {}, s => Object.assign(s, { niqqud: true })));

        // ── K1: ההגדרות נשמרות אחרי רענון ──────────────────────
        await p.reload();
        await p.waitForSelector('.rd-text .sf-doc');
        check('K1 אחרי רענון: הגודל נשמר', await p.evaluate(() => getComputedStyle(document.querySelector('.rd-text .sf-doc')).fontSize) === '34px');
        const back = await at();
        check('K1 אחרי רענון: חוזרים למקום', back && back.sid === target, back);

        // ── K7: קישור שיתוף — רק מזהים ────────────────────────
        await p.evaluate(() => { window.__shared = null; navigator.share = d => { window.__shared = d.url; return Promise.resolve(); }; });
        await p.locator('.rd-text [data-sid="' + target + '"]').click();
        await p.locator('.rd-act button[aria-label="שיתוף קישור לקטע"]').click();
        const shared = await p.evaluate(() => window.__shared);
        check('K7 הקישור: #/r/<edition>/<sid> בלבד', /index\.html#\/r\/[a-z0-9]{16}\/[a-z0-9]{8}$/.test(shared || ''), shared);
        check('K7 בלי שם תפילה או הערה', !/[א-ת]/.test(decodeURIComponent(shared || '')), shared);
        // הערה אישית + סימניה (נשמרות במכשיר)
        await p.locator('.rd-act button[aria-label="סימניה"]').click();
        await p.locator('.rd-act button[aria-label="הערה אישית"]').click();
        await p.locator('dialog textarea').fill('הערה פרטית');
        await p.locator('dialog button:has-text("שמירה")').click();
        await idle();
        check('סימניה והערה — במקום', await p.evaluate(sid => { const e = document.querySelector('.rd-text [data-sid="' + sid + '"]'); return e.dataset.bm === '1' && e.dataset.note === '1'; }, target));
        await shot('reader-actions');
        await p.locator('.rd-act button[aria-label="ביטול הסימון"]').click();

        // ── K9: wakeLock שנכשל — בלי שגיאה ─────────────────────
        await p.evaluate(() => { Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: { request: () => Promise.reject(new DOMException('no', 'NotAllowedError')) } }); });
        const before = errors.length;
        await p.locator('.rd-tools-btn').click();
        await p.locator('.rd-tools label.switch:has-text("מסך דולק") input').check();
        await idle();
        check('K9 כשל ב-wakeLock: בלי שגיאה ובלי הודעה', errors.length === before && await p.locator('.toast.err:not([hidden])').count() === 0, errors.slice(before));
        await p.locator('.rd-tools label.switch:has-text("מסך דולק") input').uncheck();

        // ── K3: מנהגים — סימון, פתיחה לצפייה, שינוי זמני, יציאה ─
        const chul = 'בחוץ לארץ: מתחילים';
        const vis = t => p.evaluate(t => [...document.querySelectorAll('.rd-text p')].some(x => x.textContent.includes(t) && x.offsetParent !== null), t);
        check('K3 תוספת שלא נבחרה — לא מוצגת', !(await vis(chul)));
        const marker = p.locator('.rd-text .sf-cond-btn:has-text("חוץ לארץ")');
        check('K3 …מוצגת כסימון', await marker.count() === 1);
        await marker.click();
        check('K3 פתיחה לצפייה', await vis(chul), await p.evaluate(() => { const b = document.querySelector('.rd-text .sf-cond-off'); return b && [b.outerHTML.slice(0, 300), b.offsetParent && b.offsetParent.tagName]; }));
        check('K3 הפתיחה לא משנה את ההעדפה', await p.evaluate(() => !SF.reader.conds().temp && SF.reader.conds().perm.place === 'eretz'));
        check('K3 ...ולא את מה שנשמר', JSON.stringify(await p.evaluate(() => SF.store.get('customs', {}))) === '{}');
        await p.locator('button[aria-label="מנהגים"]').click();
        await p.locator('dialog label:has-text("חוץ לארץ") input').check();
        await p.locator('dialog button[aria-label="סגירה"]').click();
        check('K3 שינוי בתוך התפילה — זמני ומסומן', await p.locator('.rd-temp:not([hidden]):has-text("שינוי זמני")').count() === 1);
        check('K3 …והטקסט התחלף', await vis(chul));
        await shot('reader-temp');
        await p.goto(BASE + '#/siddur');
        await p.waitForSelector('.list a.use-row');
        await p.locator('.list a.use-row:has-text("שחרית")').click();
        await p.waitForSelector('.rd-text .sf-doc');
        await idle();
        check('K3 יציאה מהתפילה מבטלת את השינוי הזמני', await p.locator('.rd-temp:not([hidden])').count() === 0 && !(await vis(chul)));
        check('K3 ההעדפה הקבועה לא השתנתה', JSON.stringify(await p.evaluate(() => SF.store.get('customs', {}))) === '{}');

        // ── חיפוש: פתיחת תוצאה מגיעה לקטע ומדגישה גם בכתיב חלופי ─
        await p.goto(BASE + '#/search');
        await p.locator('input[type=search]').fill('תפילה');
        await p.locator('button:has-text("חיפוש")').click();
        await p.waitForSelector('.list a.use-row');
        await p.locator('.list a.use-row').first().click();
        await p.waitForSelector('.rd-text mark.sf-match');
        check('חיפוש בכתיב מלא — ההדגשה על "תְּפִלָּה" (חסר)', (await p.locator('.rd-text mark.sf-match').first().textContent()) === 'תְּפִלָּה');
        await shot('reader-search');

        // ── K10: הסדר האישי לא נדרס כשהבסיס משתנה ──────────────
        await p.goto(BASE + '#/siddur');
        await p.waitForSelector('.list a.use-row');
        await p.locator('button[aria-label="סידור הרשימה שלי"]').click();
        await p.locator('button[aria-label="למעלה: ערבית"]').click();
        await p.locator('button[aria-label="למעלה: ערבית"]').click();
        await p.locator('button[aria-label="סידור הרשימה שלי"]').click();
        const mine = await p.locator('.list a.use-row b').allTextContents();
        await p.evaluate(async () => {
            const ed = (await SF.api('read-catalog')).titles[0].editions[0].pid;
            await SF.api('siddur-item-save', { body: { edition: ed, name: 'תפילה חדשה', kind: 'prayer', sid: '' } });
        });
        await p.reload();
        await p.waitForSelector('.list a.use-row');
        const after = await p.locator('.list a.use-row b').allTextContents();
        check('K10 שינוי בבסיס — הסדר האישי נשאר, החדש בסוף', JSON.stringify(after) === JSON.stringify(mine.concat(['תפילה חדשה'])), [mine, after]);

        // ── תהילים: "סיימתי" מפורש ─────────────────────────────
        await p.goto(BASE + '#/tehillim');
        await p.waitForSelector('.th-grid a');
        await p.locator('.th-grid a').first().click();
        await p.waitForSelector('.rd-text .sf-doc');
        await idle();
        check('פתיחת פרק אינה השלמה', JSON.stringify((await p.evaluate(() => SF.store.get('tehillim', {}))).done || {}) === '{}');
        await p.locator('.rd-text section p[data-sid]').first().click();
        await p.locator('.rd-act button:has-text("✓")').click();
        check('"סיימתי" — מסומן', Object.keys((await p.evaluate(() => SF.store.get('tehillim', {}))).done).length === 1);

        // ── בטלפון: בלי גלילה אופקית ───────────────────────────
        for (const w of [320, 390]) {
            await p.setViewportSize({ width: w, height: 800 });
            for (const h of ['#/siddur', '#/tehillim', '#/search']) {
                await p.goto(BASE + h); await idle();
                check(`בלי גלילה אופקית: ${h} ב-${w}px`, await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            }
        }
        check('בלי שגיאות בדפדפן', errors.length === 0, errors);
    } finally {
        await browser.close();
        srv.kill();
        fs.rmSync(TMP, { recursive: true, force: true });
    }
    console.log(bad ? `  ${bad} מתוך ${n} נכשלו` : `  ✓ ${n} בדיקות בדפדפן`);
    process.exit(bad ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
