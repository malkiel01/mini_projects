/**
 * בדיקות ui-core בדפדפן אמיתי (Chromium של Playwright), על דף התצוגה demo/.
 *   node creative-suite/ui-core/tests/browser.cjs
 * מעלה שרת קטן (מודולים לא נטענים מ-file://), ובודק בטלפון (390px) ובמחשב (1280px).
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
let failed = 0;
const ok = (cond, msg) => { console.log((cond ? '  ✓ ' : '  ✗ ') + msg); if (!cond) failed++; };

const server = http.createServer((req, res) => {
    const file = path.join(ROOT, decodeURIComponent(req.url.split('?')[0]));
    if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
});

(async () => {
    await new Promise(r => server.listen(0, r));
    const url = `http://127.0.0.1:${server.address().port}/demo/index.html`;
    const browser = await chromium.launch();
    for (const [name, width, height, touch] of [['טלפון', 390, 800, true], ['מחשב', 1280, 800, false]]) {
        console.log(`\n${name} (${width}px)`);
        const ctx = await browser.newContext({ viewport: { width, height }, hasTouch: touch });
        const p = await ctx.newPage();
        const errors = [];
        p.on('pageerror', e => errors.push(e.message));
        await p.goto(url);
        await p.waitForFunction(() => document.querySelector('[role="tab"][aria-selected="true"]'));
        const toastText = () => p.$eval('.toast', t => t.hidden ? '' : t.textContent);

        // dom.h
        const built = await p.evaluate(async () => {
            const { h } = await import('../src/js/dom.js');
            const el = h('button#x.btn.primary', { class: 'big', 'aria-label': 'א', 'data-k': 1, disabled: true, hidden: false, '--c': 'red', onclick: () => {} },
                'טקסט ', ['<b>לא קוד</b>', null, false], h('span', 'ילד'));
            return { cls: el.className, id: el.id, aria: el.getAttribute('aria-label'), data: el.dataset.k, dis: el.disabled,
                hid: el.hasAttribute('hidden'), c: el.style.getPropertyValue('--c'), html: el.innerHTML };
        });
        ok(built.cls === 'btn primary big' && built.id === 'x', 'h: בורר + class');
        ok(built.aria === 'א' && built.data === '1' && built.dis && !built.hid && built.c === 'red', 'h: מאפיינים, property, משתנה CSS');
        ok(built.html.includes('&lt;b&gt;') && built.html.includes('<span>ילד</span>'), 'h: מחרוזת נכנסת כטקסט, לא כקוד');

        // toast
        await p.click('#show-toast');
        ok(await toastText() === 'נשמר', 'טוסט מוצג');
        await p.click('#show-undo');
        ok(await p.$('.toast .toast-act') !== null, 'טוסט עם כפתור פעולה');
        await p.click('.toast .toast-act');
        ok(await toastText() === 'שוחזרה', 'כפתור הפעולה רץ');

        // <dialog> מה-HTML: מיקום, סגירה ברקע, [data-close]
        await p.click('#open-sheet');
        await p.waitForTimeout(250);
        const r = await p.$eval('#sheet', d => d.getBoundingClientRect().toJSON());
        if (touch) ok(Math.round(r.bottom) === height && Math.round(r.width) === width, 'טלפון: החלון צמוד לתחתית, לכל הרוחב');
        else ok(Math.abs((r.top + r.bottom) / 2 - height / 2) < 2 && Math.round(r.width) === 560, 'מחשב: החלון במרכז, 560px');
        await p.mouse.click(5, 5);
        ok(!(await p.$eval('#sheet', d => d.open)), 'לחיצה ברקע סוגרת');
        await p.click('#open-sheet');
        await p.click('#sheet [data-close]');
        ok(!(await p.$eval('#sheet', d => d.open)), '[data-close] סוגר');
        ok(await p.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--vvh')) === height + 'px', '--vvh נקבע');

        // sheet מ-JS: שגיאה נשארת בפנים, הצלחה סוגרת ומוחקת
        await p.click('#js-sheet');
        await p.fill('dialog[open] input[name="w"]', '-3');
        await p.click('dialog[open] [type="submit"]');
        ok(await p.$eval('dialog[open] .error', e => !e.hidden && e.textContent.includes('חיובי')), 'sheet: שגיאה מוצגת והחלון נשאר');
        await p.fill('dialog[open] input[name="w"]', '1080');
        await p.click('dialog[open] [type="submit"]');
        await p.waitForTimeout(50);
        ok(await p.$('dialog[open]') === null && await p.$$eval('dialog', d => d.length) === 2, 'sheet: נסגר ונמחק מה-DOM');
        ok((await toastText()).includes('1080×1920'), 'sheet: onSubmit רץ');

        // confirm / prompt / choose
        await p.click('#ask-confirm');
        await p.click('dialog[open] .btn.danger');
        await p.waitForTimeout(50);
        ok(await toastText() === 'נמחקה', 'confirm → true');
        await p.click('#ask-confirm');
        await p.keyboard.press('Escape');
        await p.waitForTimeout(50);
        ok(await toastText() === 'בוטל', 'confirm: Esc → false');
        await p.click('#ask-prompt');
        await p.waitForTimeout(50);
        const sel = await p.$eval('dialog[open] input', i => i.value.slice(i.selectionStart, i.selectionEnd));
        ok(sel === 'כרטיס ברכה', 'prompt: נבחר השם בלי הסיומת');
        await p.keyboard.type('הזמנה');
        await p.keyboard.press('Enter');
        await p.waitForTimeout(50);
        ok(await toastText() === 'הזמנה.png', 'prompt: Enter מחזיר את התשובה');
        await p.click('#ask-choose');
        await p.click('dialog[open] .btn.ghost:not(.danger)');
        await p.waitForTimeout(50);
        ok(await toastText() === 'stay', 'choose → המפתח');

        // menu: מקלדת, Esc מחזיר פוקוס, שגיאה בטוסט, נשאר במסך
        await p.click('#menu-btn');
        const m = await p.$eval('.menu', e => ({ ...e.getBoundingClientRect().toJSON(), focus: document.activeElement.textContent }));
        ok(m.left >= 8 && m.right <= width - 8, 'תפריט: בתוך המסך');
        ok(m.focus.includes('שכפול'), 'תפריט: פוקוס על הפריט הראשון');
        await p.keyboard.press('ArrowDown');
        await p.keyboard.press('ArrowDown');
        ok((await p.evaluate(() => document.activeElement.textContent)).includes('מחיקה'), 'תפריט: חצים מדלגים על פריט מושבת');
        await p.keyboard.press('Escape');
        ok(await p.$('.menu') === null && await p.evaluate(() => document.activeElement.id) === 'menu-btn', 'תפריט: Esc סוגר ומחזיר פוקוס');
        await p.click('#menu-btn2');
        const anchor = await p.$eval('#menu-btn2', b => b.getBoundingClientRect().toJSON());
        const m2 = await p.$eval('.menu', e => e.getBoundingClientRect().toJSON());
        ok(Math.abs(m2.right - anchor.right) < 1 || m2.left === 8, 'תפריט: מיושר לימין הכפתור (RTL)');
        await p.click('.menu .danger');
        await p.waitForTimeout(50);
        { const tt = await toastText(); ok(tt.includes('שכבה אחרונה'), 'תפריט: שגיאה ב-run מוצגת בטוסט [' + tt + ']'); }

        // contextMenu
        const box = await p.$eval('#ctx', e => e.getBoundingClientRect().toJSON());
        await p.mouse.click(box.left + 20, box.top + 20, { button: 'right' });
        ok(await p.$('.menu') !== null, 'תפריט הקשר: לחיצה ימנית');
        await p.keyboard.press('Escape');

        // tabs: RTL — חץ שמאלה = הבאה, זוכר
        await p.focus('[role="tab"][aria-selected="true"]');
        await p.keyboard.press('ArrowLeft');
        const t = await p.evaluate(() => ({ sel: document.querySelector('[role="tab"][aria-selected="true"]').dataset.tab,
            hidden: ['tab-a', 'tab-b', 'tab-c'].map(id => document.getElementById(id).hidden) }));
        ok(t.sel === 'b' && JSON.stringify(t.hidden) === '[true,false,true]', 'לשוניות: חץ שמאלה → הבאה, הלוח מתחלף');
        await p.reload();
        await p.waitForFunction(() => document.querySelector('[role="tab"][aria-selected="true"]'));
        ok(await p.$eval('[role="tab"][aria-selected="true"]', e => e.dataset.tab) === 'b', 'לשוניות: נזכרת אחרי טעינה');

        // files + store
        const fx = await p.evaluate(async () => {
            const { fileName } = await import('../src/js/files.js');
            const { pref, kv } = await import('../src/js/store.js');
            const s = pref('ui-core-test');
            s.set('a', { x: 1 });
            const db = kv('ui-core-test');
            await db.set('k', new Blob(['שלום']));
            const blob = await db.get('k');
            const keys = await db.keys();
            await db.del('k');
            return {
                names: [fileName('כרטיס: "חג" שמח?/', 'png'), fileName('', 'mbe'), fileName('..‏מסמך', '.pdf')],
                pref: s.get('a').x, def: s.get('none', 7),
                blob: await blob.text(), keys, after: await db.get('k'),
            };
        });
        ok(JSON.stringify(fx.names) === JSON.stringify(['כרטיס חג שמח.png', 'ללא שם.mbe', 'מסמך.pdf']), 'fileName: ניקוי תווים וסיומת');
        ok(fx.pref === 1 && fx.def === 7, 'pref: JSON וברירת מחדל');
        ok(fx.blob === 'שלום' && fx.keys.includes('k') && fx.after === undefined, 'kv: Blob נשמר, נקרא ונמחק');

        ok(errors.length === 0, 'בלי שגיאות JS' + (errors.length ? ': ' + errors.join(' | ') : ''));
        await ctx.close();
    }
    await browser.close();
    server.close();
    console.log(failed ? `\n✗ ${failed} נכשלו` : '\n✓ הכול עבר');
    process.exit(failed ? 1 : 0);
})();
