/**
 * בדיקות הדפדפן של עורך התמלילים.
 *
 *   cd word-processor/tests/e2e && npm install && npx playwright install chromium   (פעם אחת)
 *   node run.mjs                 — הכול
 *   node run.mjs parity save     — רק חלק מהקבוצות
 *
 * מרים בעצמו שני שרתי php -S (העורך עם משתמש מדומה ומסד זמני — prepend.php, ומארחת בדיקה — host/), מריץ את
 * הקבוצות שב-suites/, ומחזיר 0 רק כשהכול עבר. הבדל ידוע (known במסמך מהאוסף) מדווח ולא מכשיל.
 * דרישות: php 8.1+ (sqlite, sodium, gd), pdftotext (poppler-utils), Node 20+.
 * PW=<נתיב ל-playwright/index.mjs> — כש-playwright מותקן במקום אחר (למשל גלובלית).
 */
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const app = join(here, '..', '..');            // word-processor/
const repo = join(app, '..');
const { chromium, devices } = await import(process.env.PW || 'playwright');

const EP = 8090, HP = 8091;
const editor = `http://localhost:${EP}/word-processor/`;
const host = `http://localhost:${HP}/`;
const data = mkdtempSync(join(tmpdir(), 'wp-e2e-'));
const env = { ...process.env, PHP_CLI_SERVER_WORKERS: '4', WP_E2E_DATA: data, WP_E2E_BASE: editor, HOST_E2E_DATA: data, HOST_E2E_URL: host };

const servers = [
    spawn('php', ['-d', `auto_prepend_file=${join(here, 'prepend.php')}`, '-S', `localhost:${EP}`], { cwd: repo, env, stdio: 'ignore' }),
    spawn('php', ['-S', `localhost:${HP}`], { cwd: join(here, 'host'), env, stdio: 'ignore' }),
];
const stop = () => { servers.forEach(s => s.kill()); rmSync(data, { recursive: true, force: true }); };
process.on('exit', stop);
process.on('SIGINT', () => process.exit(130));

async function waitUp(url) {
    for (let i = 0; i < 50; i++) {
        try { await fetch(url); return; } catch { await new Promise(r => setTimeout(r, 100)); }
    }
    throw new Error('השרת לא עלה: ' + url);
}
await waitUp(editor + 'index.html');
await waitUp(host + 'api.php');

// ---------- כלים לקבוצות ----------
let fails = 0, passes = 0, known = 0;
const t = {
    editor, host, app, data, devices,
    ok(name, cond, detail = '') {
        console.log(`  ${cond ? '✓' : '✗'} ${name}${detail ? ' — ' + detail : ''}`);
        cond ? passes++ : fails++;
        return cond;
    },
    known(name, detail) { console.log(`  ⚠ ${name} — ${detail}`); known++; },
    browser: await chromium.launch(),
    /** דף באפליקציה, עם מעקב שגיאות JS (נבדקות בסוף כל קבוצה). */
    async page(opts = {}) {
        const ctx = await t.browser.newContext({ viewport: { width: 1200, height: 900 }, ...opts });
        const p = await ctx.newPage();
        p.errors = [];
        p.on('pageerror', e => p.errors.push(e.message));
        await p.goto(editor);
        await p.waitForSelector('.list, .login-box');
        return p;
    },
    /** קריאה ל-API של האפליקציה מתוך הדף (כמו הדפדפן). */
    api: (p, action, body = {}) => p.evaluate(async ([a, b]) => (await fetch('api.php?action=' + a, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP': '1', 'X-Tab': 'e2e' }, body: JSON.stringify(b) })).json(), [action, body]),
    /** מסמך חדש עם תוכן והגדרות עמוד. */
    async newDoc(p, title, json, page = null) {
        const { doc } = await t.api(p, 'my_new', { title });
        const o = await t.api(p, 'my_open', { doc });
        const j = structuredClone(json);
        if (page) j.attrs = { ...(j.attrs || {}), page };
        const r = await t.api(p, 'my_save', { doc, json: j, base_version: o.version });
        if (!r.version) throw new Error('my_save: ' + JSON.stringify(r));
        await t.api(p, 'my_lock', { doc, op: 'release' });
        return doc;
    },
    async openDoc(p, doc) {
        await p.goto(editor + '#/d/' + doc);
        await p.waitForSelector('.wp-doc');
        await t.settle(p);
    },
    /** מחכה שהעימוד יתייצב: גופנים נטענו, ומספר המעברים לא משתנה חצי שנייה. */
    async settle(p) {
        // כל הגופנים נטענים מראש: הדפדפן טוען גופן רק כשמשתמשים בו, ובמחשב איטי (CI) המדידה הראשונה יכולה לתפוס
        // את גופן הגיבוי. העורך עצמו מודד שוב כשגופן נטען (loadingdone) — כאן רק מחכים לתוצאה הסופית
        await p.evaluate(() => Promise.all([...document.fonts].map(f => f.load().catch(() => null))));
        await p.evaluate(() => document.fonts.ready);
        let last = '', same = 0;
        for (let i = 0; i < 150 && same < 10; i++) {
            const sig = await p.evaluate(() => document.fonts.status + [...document.querySelectorAll('.wp-break')].map(b => b.dataset.page + ':' + b.style.height).join('|'));
            same = sig === last ? same + 1 : 0;
            last = sig;
            await p.waitForTimeout(100);
        }
    },
    /** לחיצה על פקד בסרט (לפי aria-label): קודם פותחים את הלשונית שבה הוא נמצא. */
    async tool(p, label) {
        const sel = `.wp-ribbon [aria-label="${label}"]`;
        await p.evaluate(s => {
            const pn = document.querySelector(s)?.closest('[role=tabpanel]');
            if (pn?.hidden) document.getElementById(pn.getAttribute('aria-labelledby')).click();
        }, sel);
        await p.click(sel);
    },
    /** טקסט כל עמוד ב-PDF (pdftotext), בלי סימני כיוון. */
    pdfPages(file) {
        const out = execFileSync('pdftotext', [file, '-'], { encoding: 'utf8' });
        return out.split('\f').slice(0, -1).map(s => s.replace(/[‎‏‪-‮]/g, ''));
    },
};

// ---------- הרצה ----------
const want = process.argv.slice(2);
const suites = readdirSync(join(here, 'suites')).filter(f => f.endsWith('.mjs')).sort()
    .filter(f => !want.length || want.some(w => f.includes(w)));
const started = Date.now();
for (const f of suites) {
    console.log(`\n${f.replace(/^\d+-|\.mjs$/g, '')}`);
    try {
        await (await import(join(here, 'suites', f))).default(t);
    } catch (e) {
        t.ok('הקבוצה רצה עד הסוף', false, e.stack || e.message);
    }
}
await t.browser.close();
console.log(`\n${fails ? `✗ ${fails} נכשלו, ` : '✓ '}${passes} עברו${known ? `, ${known} הבדלים ידועים` : ''} (${Math.round((Date.now() - started) / 1000)} שניות)`);
process.exit(fails ? 1 : 0);
