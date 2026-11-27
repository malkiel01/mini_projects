/*
 * הורדות, גרסאות ו-PWA בדפדפן אמיתי (אפיון 06 §5: 1, 2, 3, 4, 5, 6, 8 — ו-9 בחלקו: manifest ו-service worker נטענים).
 * **לא רץ ב-run.php** — צריך Playwright ו-Chromium, שאין ב-runner של הפריסה. הרצה מקומית:  node sforno/tests/browser/offline.cjs
 *
 * מרים php -S על תיקיית ספורנו עם מסד זמני (כמו reader.cjs). localhost = הקשר מאובטח: service worker, Cache API ו-crypto.subtle
 * עובדים. "מצב טיסה" = context.setOffline(true). יוצא עם קוד 1 אם משהו נכשל. צילומי מסך — ב-$SF_SHOTS (אם הוגדר).
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
$_SERVER['PLATFORM_USER'] = json_encode(['sub' => '902', 'name' => 'בודק', 'email' => 'e2e@example.test', 'email_verified' => true, 'platform_admin' => false, 'granted' => true]);
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
        p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|ERR_INTERNET_DISCONNECTED/.test(m.text())) errors.push(m.text()); });
        const shot = async name => { if (SHOTS) await p.screenshot({ path: path.join(SHOTS, name + '.png') }); };
        const idle = (ms = 350) => p.waitForTimeout(ms);

        // ── תוכן דוגמה; PWA ───────────────────────────────────
        await p.goto(BASE + '#/siddur');
        await p.locator('button:has-text("ליצור תוכן דוגמה")').click();
        await p.waitForSelector('.list a.use-row');
        const sw = await p.evaluate(async () => { const r = await navigator.serviceWorker.ready; return r.scope; });
        check('K9 service worker רשום — scope בתיקייה של ספורנו', sw === ORIGIN, sw);
        const mf = await p.evaluate(async () => (await fetch(document.querySelector('link[rel=manifest]').href, { credentials: 'include' })).json());
        check('K9 manifest נטען: standalone', mf.display === 'standalone' && mf.icons.length >= 3);
        const cat = await p.evaluate(() => SF.catalog(true));
        const S = cat.titles.find(t => t.kind === 'siddur').editions[0].pid;
        const T = cat.titles.find(t => t.kind === 'tehillim').editions[0].pid;
        check('שורת "בלי אינטרנט" בסידור — "לא זמין בלי אינטרנט" ועם כפתור הורדה', await p.locator('.dl-line:has-text("לא זמין בלי אינטרנט") button:has-text("הורדה")').count() === 1);
        check('§1.2 שום דבר לא ירד מעצמו', (await p.evaluate(() => SF.offline.records())).length === 0);

        // ── קורא: נעיצה בגרסה, הערה ─────────────────────────────
        await p.goto(BASE + '#/r/' + S);
        await p.waitForSelector('.rd-text .sf-doc');
        const rev1 = await p.evaluate(() => SF.reader.shown);
        check('K3 קריאה ראשונה — נעיצה בגרסה הנוכחית', (await p.evaluate(ed => SF.store.get('ver:' + ed), S)).rev === rev1);
        const sids = await p.evaluate(() => [...document.querySelectorAll('.rd-text p[data-sid]')].map(x => x.dataset.sid));
        const [keep, gone] = [sids[0], sids[1]];
        await p.evaluate(async ([ed, keep, gone, rev]) => {
            const now = new Date().toISOString();
            await SF.store.set('marks', [{ id: 'k', edition: ed, rev, sid: keep, type: 'note', note: 'נשארת', excerpt: 'a', created_at: now, updated_at: now },
                { id: 'g', edition: ed, rev, sid: gone, type: 'note', note: 'על שורה שתימחק', excerpt: 'הטקסט המקורי של השורה', created_at: now, updated_at: now }]);
        }, [S, keep, gone, rev1]);

        // ── K1: הורדה ← מצב טיסה ← נפתח ונקרא ─────────────────────
        await p.goto(BASE + '#/downloads');
        await p.waitForSelector('.list');
        await p.locator('li.card:has-text("סידור") button:has-text("הורדה")').click();
        await p.waitForSelector('.dl-card .dl-st.ready', { timeout: 15000 });
        const rec = await p.evaluate(ed => SF.offline.record(ed), S);
        check('K1 הורדה: ready, גרסה, גודל, תאריך', rec.state === 'ready' && rec.revision === rev1 && rec.size > 0 && !!rec.completed_at, rec);
        await shot('downloads');
        await ctx.setOffline(true);
        await p.goto(BASE + '#/r/' + S).catch(() => {});
        await p.waitForSelector('.rd-text .sf-doc', { timeout: 8000 }).catch(() => {});
        check('K1 מצב טיסה: הכותר שהורד נפתח ונקרא (גם אחרי טעינה מחדש)', await p.locator('.rd-text p[data-sid]').count() >= 3);
        check('K8 מצב טיסה: "בלי אינטרנט" בכותרת', await p.locator('#net-chip:not([hidden])').count() === 1);
        await shot('offline-reader');
        await p.goto(BASE + '#/r/' + T).catch(() => {});
        await p.waitForSelector('.fail', { timeout: 8000 }).catch(() => {});
        check('K1 כותר שלא הורד — "לא זמין בלי אינטרנט", בלי גלגל טעינה', await p.locator('.fail:has-text("לא זמין בלי אינטרנט")').count() === 1 && await p.locator('.loading').count() === 0);
        await shot('offline-missing');
        await p.goto(BASE + '#/downloads').catch(() => {});
        await p.waitForSelector('.dl-card', { timeout: 8000 }).catch(() => {});
        check('K8 מסך ההורדות בלי רשת: מה זמין, ומה "לא זמין בלי אינטרנט"', await p.locator('.dl-card .dl-st.ready').count() === 1 && await p.locator('li.card:has-text("תהילים") .dl-st.none').count() === 1);
        await shot('offline-downloads');
        await ctx.setOffline(false);

        // ── K3/K4: פרסום גרסה חדשה (בלי השורה השנייה) ─────────────
        await p.goto(BASE + '#/');
        await p.waitForSelector('.tiles');
        await p.evaluate(async ([ed, gone]) => {
            const r = await SF.api('read-revision', { params: { edition: ed } });
            const html = r.html.replace(new RegExp('<p[^>]*\\ssid="' + gone + '"[^>]*>[\\s\\S]*?</p>'), '');
            const d = await SF.api('content-save', { body: { edition: ed, html, base: 0, from: r.revision.n } });
            await SF.api('content-publish', { body: { edition: ed, base: d.draft.v } });
        }, [S, gone]);
        await p.goto(BASE + '#/r/' + S);
        await p.waitForSelector('.rd-text .sf-doc');
        check('K3 אחרי פרסום — הקורא ממשיך בישנה (מההורדה)', await p.evaluate(() => SF.reader.shown) === rev1 && await p.locator('.rd-text [data-sid="' + gone + '"]').count() === 1);
        check('K3 "יש גרסה חדשה — לעבור?"', await p.locator('.rd-ver:has-text("יש גרסה חדשה") button:has-text("לעבור")').count() === 1);
        await shot('new-version');
        // גם מקוון, בלי הורדה
        await p.evaluate(ed => SF.offline.remove(ed), S);
        await p.reload();
        await p.waitForSelector('.rd-text .sf-doc');
        check('K3 גם מקוון (בלי הורדה) — הגרסה הישנה', await p.evaluate(() => [SF.reader.shown, SF.reader.from]).then(x => x[0] === rev1 && x[1] === 'net'));
        check('K5 מחיקת ההורדה לא מחקה את ההערות', (await p.evaluate(() => SF.store.get('marks'))).length === 2);
        await p.evaluate(ed => SF.offline.download(ed), S);
        await p.locator('.rd-ver button:has-text("לעבור")').click();
        await p.waitForFunction(r => SF.reader && SF.reader.shown && SF.reader.shown !== r, rev1, { timeout: 8000 });
        const marks = await p.evaluate(() => SF.store.get('marks'));
        const by = Object.fromEntries(marks.map(m => [m.id, m]));
        check('K4 הערה על sid קיים — עברה לגרסה החדשה', by.k.rev !== rev1);
        check('K4 הערה על sid שנמחק — נשארה, צמודה לישנה', by.g && by.g.rev === rev1 && marks.length === 2);
        await p.locator('.rd-tools-btn').click();
        check('K4 "לא הותאמו" עם הטקסט המקורי, ו"חזרה לגרסה הקודמת"', await p.locator('.rd-tools:has-text("לא הותאמו לגרסה הזו (1)"):has-text("על שורה שתימחק")').count() === 1
            && await p.locator('.rd-tools button:has-text("חזרה לגרסה הקודמת")').count() === 1);
        const rec2 = await p.evaluate(ed => SF.offline.record(ed), S);
        check('מעבר עם הורדה — ההורדה עברה לגרסה החדשה', rec2 && rec2.state === 'ready' && rec2.revision !== rev1);
        await shot('unmatched');
        await p.locator('.rd-tools button:has-text("חזרה לגרסה הקודמת")').click();
        await p.waitForFunction(r => SF.reader && SF.reader.shown === r, rev1, { timeout: 8000 });
        check('K4 חזרה לגרסה הקודמת — הכול מותאם שוב', (await p.evaluate(() => SF.store.get('marks'))).every(m => m.rev === rev1));

        // ── K2: הורדה שנקטעה ──────────────────────────────────────
        await p.route('**/api.php?action=read-revision*', r => r.abort('internetdisconnected'));
        await p.evaluate(ed => SF.offline.download(ed).catch(() => {}), T);
        await p.unroute('**/api.php?action=read-revision*');
        const recT = await p.evaluate(ed => SF.offline.record(ed), T);
        check('K2 נקטעה ← "לא הושלמה"', recT && recT.state === 'partial');
        await p.goto(BASE + '#/downloads');
        await p.waitForSelector('.dl-card');
        check('K2 במסך: "לא הושלמה" ו"להוריד שוב"', await p.locator('.dl-card:has-text("תהילים"):has-text("לא הושלמה") button:has-text("להוריד שוב")').count() === 1);
        await ctx.setOffline(true);
        await p.goto(BASE + '#/r/' + T).catch(() => {});
        await p.waitForSelector('.fail', { timeout: 8000 }).catch(() => {});
        check('K2 לא נפתחת כזמינה בלי רשת', await p.locator('.fail:has-text("לא זמין בלי אינטרנט")').count() === 1);
        await ctx.setOffline(false);

        // ── K6: עדכון תוכנה — פס, לא החלפה; המידע וההורדות נשארים ─────
        await p.goto(BASE + '#/');
        await p.waitForSelector('.tiles');
        const before = await p.evaluate(async () => ({ marks: (await SF.store.get('marks')).length, dl: (await SF.offline.records()).length, caches: await caches.keys() }));
        const swPath = path.join(ROOT, 'sw.js');
        const orig = fs.readFileSync(swPath, 'utf8');
        try {
            fs.writeFileSync(swPath, orig.replace(/const BUILD = '[^']*';/, "const BUILD = 'e2e000000000';"));
            await p.evaluate(async () => (await navigator.serviceWorker.getRegistration()).update());
            await p.waitForSelector('#sw-update', { timeout: 10000 }).catch(() => {});
            check('K6 גרסת תוכנה חדשה ← "עדכון זמין — לרענן" (בלי החלפה לבד)', await p.locator('#sw-update:has-text("עדכון זמין")').count() === 1);
            await shot('sw-update');
            await Promise.all([p.waitForEvent('load', { timeout: 10000 }).catch(() => {}), p.locator('#sw-update button:has-text("לרענן")').click()]);
            await p.waitForSelector('.tiles');
            const after = await p.evaluate(async () => ({ marks: (await SF.store.get('marks')).length, dl: (await SF.offline.records()).length, caches: await caches.keys() }));
            check('K6 אחרי העדכון: הפריטים האישיים וההורדות נשארו', after.marks === before.marks && after.dl === before.dl && after.caches.includes('sforno-content'), [before, after]);
            check('K6 המעטפת הישנה נמחקה, החדשה במטמון', after.caches.some(k => k.endsWith('e2e000000000')) && !after.caches.some(k => k.startsWith('sforno-shell-') && !k.endsWith('e2e000000000')), after.caches);
        } finally {
            fs.writeFileSync(swPath, orig);
        }
        check('בלי שגיאות JS', errors.length === 0, errors.slice(0, 5));
    } catch (e) {
        bad++;
        console.log('  ✗ ' + (e && e.stack || e));
    } finally {
        await browser.close();
        srv.kill();
        fs.rmSync(TMP, { recursive: true, force: true });
    }
    console.log(bad ? `  ${bad} מתוך ${n} נכשלו` : `  ✓ ${n} בדיקות בדפדפן`);
    process.exit(bad ? 1 : 0);
})();
