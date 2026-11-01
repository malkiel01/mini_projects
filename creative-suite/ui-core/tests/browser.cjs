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
        ok(await toastText() === 'stay', 'choose → המפתח (אפשרות null מדולגת)');

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
                names: [fileName('כרטיס: "חג" שמח?/', 'png'), fileName('', 'mbe'), fileName('..‏מסמך', '.pdf'), fileName(' ', 'png', 'תמונה')],
                pref: s.get('a').x, def: s.get('none', 7),
                blob: await blob.text(), keys, after: await db.get('k'),
            };
        });
        ok(JSON.stringify(fx.names) === JSON.stringify(['כרטיס חג שמח.png', 'ללא שם.mbe', 'מסמך.pdf', 'תמונה.png']), 'fileName: ניקוי תווים, סיומת ושם חלופי');
        ok(fx.pref === 1 && fx.def === 7, 'pref: JSON וברירת מחדל');
        ok(fx.blob === 'שלום' && fx.keys.includes('k') && fx.after === undefined, 'kv: Blob נשמר, נקרא ונמחק');

        // keys: לפי e.code — גם כשהמקלדת בעברית
        const press = (key, code, mods = {}, target = 'body') => p.evaluate(([key, code, mods, target]) => {
            const t = document.querySelector(target); t.focus?.();
            t.dispatchEvent(new KeyboardEvent('keydown', { key, code, bubbles: true, cancelable: true, ...mods }));
        }, [key, code, mods, target]);
        await press('z', 'KeyZ', { ctrlKey: true });
        ok(await toastText() === 'ביטול', 'קיצור: Ctrl+Z (אנגלית)');
        await press('ז', 'KeyZ', { ctrlKey: true, shiftKey: true });
        ok(await toastText() === 'ביצוע', 'קיצור: Ctrl+Shift+Z במקלדת עברית (e.key = ז)');
        await press('ט', 'KeyY', { ctrlKey: true });
        ok(await toastText() === 'ביצוע', 'קיצור: Ctrl+Y במקלדת עברית');
        await press('+', 'Equal', { ctrlKey: true, shiftKey: true });
        ok(await toastText() === 'זום +', 'קיצור: Shift? — Ctrl+Shift+=');
        await press('ה', 'KeyV');
        ok(await toastText() === 'כלי: בחירה', 'קיצור: אות בלי מגביל במקלדת עברית');
        await press('ה', 'KeyV', {}, 'main input:not([type])');
        ok(await toastText() === 'כלי: בחירה' , 'קיצור: לא נורה בתוך שדה (הטוסט לא השתנה)');
        await press('z', 'KeyZ', { ctrlKey: true }, 'main input:not([type])');
        ok(await toastText() === 'כלי: בחירה', 'קיצור: Ctrl+Z בשדה — של השדה');
        await press('ד', 'KeyS', { ctrlKey: true }, 'main input:not([type])');
        ok(await toastText() === 'שמירה', 'קיצור: inFields — Ctrl+S גם בשדה');
        await press('z', 'KeyZ', { ctrlKey: true, altKey: true });
        ok(await toastText() === 'שמירה', 'קיצור: מגבילים מדויקים (Ctrl+Alt+Z לא נורה)');
        const t2 = await p.$eval('#undo-btn', b => [b.title, b.getAttribute('aria-keyshortcuts')]);
        const ko = await p.evaluate(async () => {
            const { keyOf } = await import('../src/js/keys.js');
            const k = (key, code) => keyOf(new KeyboardEvent('keydown', { key, code }));
            return [k('ז', 'KeyZ'), k('1', 'Numpad1'), k('+', 'Equal'), k('+', 'NumpadAdd'), k('-', 'Minus'), k('ץ', 'Period'), k('Escape', 'Escape'), k(' ', 'Space'), k('ArrowLeft', 'ArrowLeft'), k('ש', '')];
        });
        ok(JSON.stringify(ko) === JSON.stringify(['z', '1', '=', '=', '-', '.', 'escape', ' ', 'arrowleft', 'ש']), 'keyOf: המקש הפיזי בכל שפה [' + ko + ']');
        ok(t2[0] === 'ביטול (Ctrl+Z)' && t2[1] === 'Control+Z', 'קיצור: title ו-aria-keyshortcuts');

        // viewport — חשבון טהור
        const vm = await p.evaluate(async () => {
            const V = await import('../src/js/viewport.js');
            const v = { zoom: 1, x: 0, y: 0 };
            const z = V.zoomTo(v, 2, 100, 100);
            const back = V.screenToDoc(z, 100, 100);
            const f = V.fit(800, 600, 400, 300, 0);
            const w1 = V.wheel(v, { deltaX: 0, deltaY: 3, deltaMode: 1 }, 0, 0);
            const w2 = V.wheel(v, { deltaX: 0, deltaY: -100, deltaMode: 0, ctrlKey: true }, 50, 50);
            const w3 = V.wheel(v, { deltaX: 0, deltaY: 10, deltaMode: 0, shiftKey: true }, 0, 0);
            return { back, f, w1, w2z: w2.zoom, w2keep: V.screenToDoc(w2, 50, 50), w3, steps: [V.nextStep(1, 1), V.nextStep(1, -1)], lbl: V.zoomLabel(12.345) };
        });
        ok(Math.abs(vm.back[0] - 100) < 1e-9 && Math.abs(vm.back[1] - 100) < 1e-9, 'viewport: zoomTo שומר את הנקודה מתחת לסמן');
        ok(vm.f.zoom === 0.5 && vm.f.x === 0 && vm.f.y === 0, 'viewport: fit');
        ok(vm.w1.y === -48 && vm.w1.zoom === 1, 'viewport: גלגלת בשורות (deltaMode 1) → הזזה ×16');
        ok(vm.w2z > 2.7 && vm.w2z < 2.72 && Math.abs(vm.w2keep[0] - 50) < 1e-9, 'viewport: Ctrl+גלגלת → זום סביב הסמן');
        ok(vm.w3.x === -10 && vm.w3.y === 0, 'viewport: Shift+גלגלת → הזזה לרוחב');
        ok(vm.steps[0] === 1.5 && vm.steps[1] === 0.67 && vm.lbl === '1,235%', 'viewport: מדרגות ותווית');

        // gestures — מצביעים מדומים על הקנבס
        const cvBox = await p.$eval('#cv', e => e.getBoundingClientRect().toJSON());
        const ptr = (type, id, x, y, extra = {}) => p.evaluate(([type, id, x, y, extra]) => {
            document.querySelector('#cv').dispatchEvent(new PointerEvent(type, { pointerId: id, pointerType: 'touch', isPrimary: id === 1,
                clientX: x, clientY: y, bubbles: true, cancelable: true, button: 0, ...extra }));
        }, [type, id, x, y, extra]);
        const vp = () => p.evaluate(() => ({ view: { ...window.__vp.view }, n: window.__vp.strokes.length, len: window.__vp.strokes.map(s => s.length) }));
        const L = cvBox.left, T = cvBox.top;
        const before = await vp();
        await ptr('pointerdown', 1, L + 50, T + 50);
        await ptr('pointermove', 1, L + 80, T + 60);
        await ptr('pointerup', 1, L + 90, T + 70);
        const afterDraw = await vp();
        ok(afterDraw.n === before.n + 1 && afterDraw.len[afterDraw.n - 1] >= 3 && afterDraw.view.x === before.view.x, 'gestures: אצבע אחת — לכלי (קו נוסף, התצוגה לא זזה)');
        await ptr('pointerdown', 1, L + 100, T + 100);
        await ptr('pointermove', 1, L + 110, T + 100);
        await ptr('pointerdown', 2, L + 200, T + 100);
        const mid = await vp();
        ok(mid.n === afterDraw.n, 'gestures: אצבע שנייה מבטלת את הקו שהתחיל');
        await ptr('pointermove', 2, L + 300, T + 100);
        const pinched = await vp();
        ok(pinched.view.zoom > mid.view.zoom * 1.5, 'gestures: צביטה מגדילה');
        await ptr('pointerup', 2, L + 300, T + 100);
        await ptr('pointermove', 1, L + 130, T + 120);
        const panned = await vp();
        ok(Math.abs(panned.view.zoom - pinched.view.zoom) < 1e-9 && Math.abs(panned.view.x - pinched.view.x - 20) < 1e-6, 'gestures: מצביטה לאצבע אחת — הזזה בלי קפיצה');
        await ptr('pointerup', 1, L + 130, T + 120);
        ok((await vp()).n === afterDraw.n, 'gestures: אחרי הצביטה האצבע לא מציירת');
        await p.evaluate(() => document.querySelector('#cv').dispatchEvent(new WheelEvent('wheel', { deltaY: -100, ctrlKey: true, clientX: 0, clientY: 0, bubbles: true, cancelable: true })));
        ok((await vp()).view.zoom > panned.view.zoom * 2.5, 'gestures: Ctrl+גלגלת על הקנבס');
        await p.check('#hand');
        const h0 = await vp();
        await ptr('pointerdown', 3, L + 50, T + 50);
        await ptr('pointermove', 3, L + 70, T + 50);
        await ptr('pointerup', 3, L + 70, T + 50);
        const h1 = await vp();
        ok(h1.n === h0.n && Math.abs(h1.view.x - h0.view.x - 20) < 1e-6, 'gestures: כלי יד — אצבע אחת מזיזה');
        await p.uncheck('#hand');

        // panOnEmpty + onTap — אלמנט נפרד, הכלי מסרב (רקע ריק)
        const pe = await p.evaluate(async () => {
            const { bindViewport } = await import('../src/js/gestures.js');
            const el = document.createElement('div');
            el.style.cssText = 'position:fixed;left:0;top:0;width:300px;height:300px;touch-action:none';
            document.body.append(el);
            let view = { zoom: 1, x: 0, y: 0 }, taps = [];
            const g = bindViewport(el, { get: () => view, set: v => { view = v; }, pointer: { down: () => false },
                panOnEmpty: e => e.pointerType === 'touch', onTap: (e, pt) => taps.push(pt), wheel: false });
            const fire = (type, id, x, y, kind = 'touch') => el.dispatchEvent(new PointerEvent(type, { pointerId: id, pointerType: kind,
                clientX: x, clientY: y, bubbles: true, button: 0 }));
            fire('pointerdown', 11, 100, 100); fire('pointermove', 11, 140, 120); fire('pointerup', 11, 140, 120);
            const panned = { ...view }, tapsAfterPan = taps.length;
            fire('pointerdown', 12, 50, 50); fire('pointerup', 12, 52, 51);
            const tapped = taps.slice();
            fire('pointerdown', 13, 50, 50, 'mouse'); fire('pointermove', 13, 90, 90, 'mouse'); fire('pointerup', 13, 90, 90, 'mouse');
            const mouseView = { ...view };
            fire('pointerdown', 14, 100, 100); fire('pointerdown', 15, 200, 100); fire('pointerup', 15, 200, 100); fire('pointerup', 14, 100, 100);
            const tapsAfterPinch = taps.length;
            g.destroy(); el.remove();
            return { panned, tapsAfterPan, tapped, mouseView, tapsAfterPinch };
        });
        ok(pe.panned.x === 40 && pe.panned.y === 20 && pe.tapsAfterPan === 0, 'panOnEmpty: אצבע אחת על רקע ריק מזיזה, ולא נחשבת הקשה');
        ok(pe.tapped.length === 1 && Math.abs(pe.tapped[0][0] - (52 - 40)) < 1e-9, 'onTap: הקשה בלי תזוזה — בקואורדינטות מסמך');
        ok(pe.mouseView.x === 40, 'panOnEmpty כפונקציה: עכבר לא מזיז (נשאר למלבן בחירה)');
        ok(pe.tapsAfterPinch === 2 - 1, 'onTap: צביטה אינה הקשה');

        // account — העיגול, התפריט, ההגדרות
        await p.evaluate(() => { try { localStorage.removeItem('ui-core-account:general'); } catch {} });
        const av = await p.$eval('.top .account-btn', b => ({ text: b.textContent, label: b.getAttribute('aria-label'), hidden: b.hidden }));
        ok(av.text === 'יי' && av.label.includes('ישראל') && !av.hidden, 'account: העיגול עם ראשי תיבות בסרגל');
        await p.click('.top .account-btn');
        await p.waitForTimeout(100);
        const menuTexts = touch
            ? await p.$$eval('dialog[open] .use-row', bs => bs.map(b => b.textContent))
            : await p.$$eval('.menu [role="menuitem"]', bs => bs.map(b => b.textContent));
        ok(menuTexts.some(t => t.includes('המסמכים שלי')) && menuTexts.some(t => t.includes('הגדרות אישיות')) && menuTexts.some(t => t.includes('יציאה')),
            'account: התפריט — מסמכים, הגדרות, יציאה' + (touch ? ' (גיליון בטלפון)' : ' (תפריט קופץ במחשב)'));
        ok(!menuTexts.some(t => t.includes('ניהול')), 'account: "ניהול" מוסתר ממי שאינו מנהל');
        ok(await p.$(touch ? 'dialog[open] .account-head' : '.menu .account-head') !== null, 'account: ראש התפריט — שם ותג ענן');
        if (touch) await p.click('dialog[open] .use-row:has-text("הגדרות אישיות")');
        else await p.click('.menu [role="menuitem"]:has-text("הגדרות אישיות")');
        await p.waitForTimeout(150);
        await p.click('dialog[open] input[name="theme"][value="dark"]');
        await p.click('dialog[open] input[name="scale"][value="1.15"]');
        await p.click('dialog[open] input[name="units"][value="mm"]');
        const st = await p.evaluate(() => ({ theme: document.documentElement.dataset.theme, scale: document.documentElement.style.getPropertyValue('--ui-scale'),
            bg: getComputedStyle(document.body).backgroundColor, saved: JSON.parse(localStorage.getItem('ui-core-account:general')), last: window.__lastPrefs }));
        ok(st.theme === 'dark' && st.bg === 'rgb(17, 19, 24)', 'account: ערכה כהה — data-theme והצבעים מתחלפים');
        ok(st.scale === '1.15', 'account: גודל טקסט → --ui-scale');
        ok(st.saved.units === 'mm' && st.last.units === 'mm', 'account: ההגדרות נשמרות ו-onPrefs נקרא');
        ok(await p.$('dialog[open] kbd') !== null, 'account: רשימת קיצורי המקלדת בהגדרות');
        ok(await p.$('dialog[open] select option[value="Rubik"]') !== null, 'account: גופן ברירת מחדל');
        await p.keyboard.press('Escape');
        await p.reload();
        await p.waitForFunction(() => document.querySelector('.account-btn'));
        ok(await p.evaluate(() => document.documentElement.dataset.theme) === 'dark', 'account: הערכה נשמרת אחרי טעינה');
        await p.evaluate(() => { window.__acc.setPrefs({ theme: 'system', scale: 1 }); });

        // accountServer — מתאם לצד השרת המשותף (call מדומה)
        const sv = await p.evaluate(async () => {
            const { accountServer } = await import('../src/js/account.js');
            const calls = [], db = { general: { theme: 'dark' } };
            const call = async (action, body) => {
                calls.push(action);
                if (action === 'account_prefs') return { prefs: { ...db } };
                if (action === 'account_prefs_set') { db[body.ns] = body.value; return { ok: true }; }
                if (action === 'account_export') return { prefs: db, data: { docs: 2 } };
                if (action === 'account_delete') return body.confirm === 'DELETE' ? { ok: true } : Promise.reject(new Error('x'));
            };
            const orig = URL.createObjectURL; let blobs = 0; URL.createObjectURL = b => { blobs++; return orig.call(URL, b); };
            const server = await accountServer(call);
            const first = server.prefs.get('general');
            await server.prefs.set('raster-studio', { engine: 'webgpu' });
            await server.exportData();
            const del = await server.deleteAccount();
            URL.createObjectURL = orig;
            const empty = await accountServer(async () => ({ prefs: [] }));   // PHP מחזיר [] כשאין הגדרות
            return { first, stored: db['raster-studio'], calls, blobs, del, emptyGet: empty.prefs.get('general') };
        });
        ok(sv.first?.theme === 'dark' && sv.stored?.engine === 'webgpu', 'accountServer: טוען פעם אחת ושומר בשרת');
        ok(sv.blobs === 1 && sv.calls.includes('account_export'), 'accountServer: ייצוא — קובץ JSON יורד');
        ok(sv.del?.ok === true && sv.calls.filter(c => c === 'account_prefs').length === 1, 'accountServer: מחיקה עם אישור; ההגדרות נטענו רק פעם אחת');
        ok(sv.emptyGet === null, 'accountServer: [] מהשרת (אין הגדרות) — לא נשבר');

        // 📁 files-window — ניהול, פתיחה, שמירה
        await p.evaluate(() => { window.__fwDone = null; window.__fw.openDocuments(window.__fw.sources).then(r => { window.__fwDone = r; }); });
        await p.waitForSelector('dialog[open] .fw-list .card');
        const fw1 = await p.evaluate(() => ({ tabs: [...document.querySelectorAll('dialog[open] [role="tab"]')].map(t => t.textContent),
            rows: [...document.querySelectorAll('dialog[open] .fw-list .card .l1')].map(r => r.textContent) }));
        ok(fw1.tabs.length === 2 && fw1.tabs[0].includes('באפליקציה') && fw1.tabs[1].includes('ענן'), 'files: לשונית לכל מקור');
        ok(fw1.rows[0] === 'כרטיסים' && fw1.rows.length === 3, 'files: תיקיות קודם, ואז קבצים');
        const bidi = await p.$eval('dialog[open] .fw-list .card:has-text("לוגו") .l1', l => {
            const [base, e] = l.querySelectorAll('bdi'); return { ext: e?.textContent, extLeft: e && e.getBoundingClientRect().right <= base.getBoundingClientRect().left + 1 };
        });
        ok(bidi.ext === '.png' && bidi.extLeft, 'files: "לוגו.png" — השם מימין, הסיומת משמאלו, בלי היפוך');
        await p.click('dialog[open] .fw-list .card:has-text("כרטיסים")');
        await p.waitForSelector('dialog[open] .fw-list .card:has-text("שנה טובה")');
        ok((await p.$$eval('dialog[open] .fw-crumbs .btn', b => b.map(x => x.textContent))).join('/') === 'באפליקציה/כרטיסים', 'files: כניסה לתיקייה ופירורי לחם');
        await p.click('dialog[open] .fw-crumbs .btn:first-child');
        await p.waitForSelector('dialog[open] .fw-list .card:has-text("לוגו")');
        await p.fill('dialog[open] input[type="search"]', 'לוגו');
        ok((await p.$$eval('dialog[open] .fw-list .card', c => c.length)) === 1, 'files: חיפוש');
        await p.fill('dialog[open] input[type="search"]', '');
        await p.click('dialog[open] .fw-list .card:has-text("לוגו") .icon-btn');
        await p.click('.menu [role="menuitem"]:has-text("שינוי שם")');
        await p.waitForTimeout(80);
        await p.keyboard.type('סמל');
        await p.keyboard.press('Enter');
        await p.waitForSelector('dialog[open] .fw-list .card:has-text("סמל.png")');
        ok(await p.evaluate(() => window.__fw.lib.some(x => x.name === 'סמל.png')), 'files: שינוי שם (השם בלי הסיומת נבחר)');
        await p.click('dialog[open] .fw-list .card:has-text("סמל") .icon-btn');
        await p.click('.menu [role="menuitem"]:has-text("מחיקה")');
        await p.click('dialog[open] .btn.danger');
        await p.waitForTimeout(150);
        ok(!(await p.evaluate(() => window.__fw.lib.some(x => x.name === 'סמל.png'))), 'files: מחיקה אחרי אישור');
        await p.click('dialog[open] [role="tab"]:has-text("ענן")');
        await p.waitForSelector('dialog[open] .fw-list .empty .btn');
        ok((await p.textContent('dialog[open] .fw-list .empty')).includes('לא מחובר'), 'files: ענן לא מחובר — כפתור "חיבור"');
        await p.click('dialog[open] .sheet-head [data-close]');
        await p.waitForTimeout(80);

        await p.evaluate(() => { window.__fwDone = 'x'; window.__fw.pickFile(window.__fw.sources, { accept: ['.mbe'] }).then(r => { window.__fwDone = r && r.item.name; }); });
        await p.waitForSelector('dialog[open] .fw-list .card');
        const onlyMbe = await p.$$eval('dialog[open] .fw-list .card .l1', r => r.map(x => x.textContent));
        await p.click('dialog[open] .fw-list .card:has-text("הזמנה")');
        await p.waitForTimeout(80);
        ok(!onlyMbe.some(n => n.endsWith('.png')) && await p.evaluate(() => window.__fwDone) === 'הזמנה לחתונה.mbe', 'files: פתיחה — רק הסוג המבוקש, והבחירה חוזרת');

        await p.evaluate(() => { window.__fwDone = 'x'; window.__fw.saveFile(window.__fw.sources, { name: 'הזמנה לחתונה', ext: 'mbe', blob: new Blob(['abc']) }).then(r => { window.__fwDone = r && r.item.name; }); });
        await p.waitForSelector('dialog[open] .fw-save');
        await p.click('dialog[open] .fw-save .btn.primary');
        await p.waitForSelector('dialog[open] .row-btns .btn');
        await p.click('dialog[open] .row-btns .btn:has-text("שניהם")');
        await p.waitForTimeout(150);
        ok(await p.evaluate(() => window.__fwDone) === 'הזמנה לחתונה (2).mbe', 'files: שמירה — שם קיים → "לשמור את שניהם"');

        // transform / snap — חשבון טהור
        const tm = await p.evaluate(async () => {
            const T = await import('../src/js/transform.js'), S = await import('../src/js/snap.js');
            const t0 = { cx: 50, cy: 25, sx: 1, sy: 1, angle: 0 };
            const corner = T.dragHandle(t0, 100, 50, [1, 1], [150, 100]);             // יחס נשמר
            const free = T.dragHandle(t0, 100, 50, [1, 1], [150, 100], { free: true });
            const side = T.dragHandle(t0, 100, 50, [1, 0], [200, 30]);
            const cen = T.dragHandle(t0, 100, 50, [1, 0], [150, 30], { fromCenter: true });
            const flat = T.dragHandle({ cx: 50, cy: 0, sx: 1, sy: 1, angle: 0 }, 100, 0, [1, 1], [200, 80]);
            const mag = T.snapAngle(47 * Math.PI / 180), nomag = T.snapAngle(60 * Math.PI / 180), st = T.snapAngle(22 * Math.PI / 180, { step: 15 });
            const s = S.snapper({ boxes: [[0, 0, 100, 100]], tol: 5 });
            const g = S.snapper({ grid: 10, tol: 3 });
            return { corner, free, side, cen, flat, mag: [mag.angle * 180 / Math.PI, mag.snapped], nomag: nomag.snapped, st: st.angle * 180 / Math.PI,
                b: s.box([103, 47, 20, 10]), pt: s.point(98, 52, 'x'), gr: g.point(21, 38) };
        });
        const near = (a, b) => Math.abs(a - b) < 1e-6;
        ok(near(tm.corner.sx, tm.corner.sy) && tm.corner.sx > 1.4, 'transform: פינה — יחס נשמר');
        ok(near(tm.free.sx, 1.5) && near(tm.free.sy, 2) && near(tm.free.cx, 75) && near(tm.free.cy, 50), 'transform: פינה חופשית, הפינה הנגדית במקום');
        ok(near(tm.side.sx, 2) && near(tm.side.sy, 1) && near(tm.side.cx, 100), 'transform: צד — ציר אחד');
        ok(near(tm.cen.sx, 2) && near(tm.cen.cx, 50), 'transform: Alt — מהמרכז');
        ok(near(tm.flat.sx, 2) && tm.flat.sy === 1, 'transform: קו שטוח — הציר באפס לא משתנה, בלי יחס');
        ok(near(tm.mag[0], 45) && tm.mag[1] && !tm.nomag && near(tm.st, 15), 'transform: מגנט 45° בטווח 6°, Shift — 15°');
        ok(tm.b.dx === -3 && tm.b.dy === -2 && tm.b.guides.length === 2 && tm.b.guides[0].v === 100, 'snap: תיבה — הקצה למול קצה, המרכז למול מרכז + קווים');
        ok(tm.pt.x === 100 && tm.pt.y === 52, 'snap: נקודה — רק בצירים שהותרו');
        ok(tm.gr.x === 20 && tm.gr.y === 40, 'snap: רשת');

        // handles — על דף התצוגה (זום 1, המסך = המסמך)
        await p.$eval('#hs', el => el.scrollIntoView({ block: 'center' }));
        const hr = await p.evaluate(() => {
            const H = window.__hd, e = (o = {}) => ({ pointerType: 'mouse', ...o });
            const out = {};
            out.miss = H.h.down(e(), [200, 130]);                             // באמצע — לא ידית: הכלי ממשיך
            out.hitTouch = H.h.at([258, 168], { pointerType: 'touch' });      // הפינה (250,160) — 11px, בתוך 22 באצבע
            out.hitMouse = H.h.at([258, 168], e());                           // אבל לא בתוך 10 בעכבר
            out.took = H.h.down(e(), [250, 160]);                             // פינה ימנית-תחתונה
            H.h.move(e(), [300, 190]);
            out.ratio = H.h.value.sx / H.h.value.sy;
            out.status = document.querySelector('.uic-status').textContent;
            H.h.move(e({ shiftKey: true }), [300, 170]);
            out.freeSx = H.h.value.sx; out.freeSy = H.h.value.sy;
            H.h.cancel();                                                     // אצבע שנייה — חוזרים
            out.cancelled = H.h.value.sx;
            H.h.down(e(), [150, 130]); H.h.move(e(), [84, 130]);              // צד שמאל עד ליד הקצה של האפור (80)
            out.snapRight = H.h.value.cx - 50 * H.h.value.sx;                 // הקצה השמאלי
            out.guides = document.querySelectorAll('.uic-guides line').length;
            H.h.move(e({ ctrlKey: true }), [84, 130]);
            out.noSnap = H.h.value.cx - 50 * H.h.value.sx;
            H.h.cancel();
            const rotAt = document.querySelector('.uic-rotate');
            const rp = [+rotAt.getAttribute('cx'), +rotAt.getAttribute('cy')];
            out.rotAbove = rp[1] < 100 - 20;
            H.h.down(e(), rp);
            H.h.move(e(), [200 + 80, 130 - 75]);                              // ~47° — נצמד ל-45
            out.angle = H.h.value.angle * 180 / Math.PI;
            out.rstatus = document.querySelector('.uic-status').textContent;
            H.h.up(e());
            out.statusGone = document.querySelector('.uic-status').hidden;
            // מצב rect
            document.querySelector('#hs-rect').checked = true; H.mount();
            out.noRotate = !document.querySelector('.uic-rotate');
            H.h.down(e(), [280, 160]); H.h.move(e(), [100.4, 30.6]);          // הפינה עוברת את הנגדית — מתנרמל
            out.rect = H.h.value;
            H.h.up(e());
            document.querySelector('#hs-rect').checked = false; H.mount();
            return out;
        });
        ok(hr.miss === false && hr.took === true, 'handles: down מחזיר true רק על ידית');
        ok(Array.isArray(hr.hitTouch) && hr.hitMouse === null, 'handles: אזור מגע 22px באצבע, 10px בעכבר');
        ok(near(hr.ratio, 1) && hr.status.includes('←') && hr.status.includes('100×60'), 'handles: פינה — יחס נשמר, שורת מצב "רוחב ← רוחב" (RTL)');
        ok(!near(hr.freeSx, hr.freeSy), 'handles: Shift — פינה חופשית');
        ok(hr.cancelled === 1, 'handles: cancel — חזרה ל-t0');
        ok(near(hr.snapRight, 80) && hr.guides === 1 && !near(hr.noSnap, 80), 'handles: הקצה נצמד + קו עזר; Ctrl — בלי הצמדה');
        ok(hr.rotAbove && near(hr.angle, 45) && hr.rstatus.includes('✓'), 'handles: סיבוב 28px מעל, מגנט 45° + ✓');
        ok(hr.statusGone, 'handles: שורת המצב נעלמת בשחרור');
        ok(hr.noRotate && hr.rect.x === 100 && hr.rect.y === 31 && hr.rect.w === 20 && hr.rect.h === 29, 'handles rect: בלי סיבוב, נרמול, פיקסלים שלמים');

        ok(errors.length === 0, 'בלי שגיאות JS' + (errors.length ? ': ' + errors.join(' | ') : ''));
        await ctx.close();
    }
    await browser.close();
    server.close();
    console.log(failed ? `\n✗ ${failed} נכשלו` : '\n✓ הכול עבר');
    process.exit(failed ? 1 : 0);
})();
