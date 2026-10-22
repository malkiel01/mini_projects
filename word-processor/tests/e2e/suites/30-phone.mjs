/** טלפון: צביטה בשתי אצבעות מגדילה רק את המסמך, לא את הכותרת וסרגל הכלים. */
export default async function (t) {
    const p = await t.page({ ...t.devices['Pixel 7'] });
    const { doc } = await t.api(p, 'my_new', { title: 'צביטה' });
    await t.openDoc(p, doc);
    const label = () => p.locator('.tb-zoom').textContent();
    t.ok('בזמן עריכה הדפדפן לא מגדיל את הדף', await p.evaluate(() => getComputedStyle(document.body).touchAction) === 'pan-x pan-y');
    const before = await label();
    const cdp = await p.context().newCDPSession(p);
    const box = await p.locator('.wp-stage').boundingBox();
    const cx = box.x + box.width / 2, cy = box.y + 200;
    const pts = d => [{ x: cx - d, y: cy, id: 0 }, { x: cx + d, y: cy, id: 1 }];
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pts(40) });
    for (let d = 50; d <= 120; d += 10) await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pts(d) });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await p.waitForTimeout(300);
    const after = await label();
    t.ok('צביטה מגדילה את המסמך', parseInt(after) > parseInt(before) * 2, `${before} → ${after}`);
    t.ok('הכותרת והדף לא גדלו', await p.evaluate(() => visualViewport.scale) === 1 && (await p.locator('header.top').boundingBox()).height < 60);

    // ממשק הטלפון: שורת פעולות קבועה בתחתית, "כלים" — הסרט בגיליון, 44px, בלי גלילה הצידה
    t.ok('שורת פעולות בתחתית גלויה, הסרט מוסתר עד "כלים"', await p.locator('.wp-mobilebar').isVisible() && !(await p.locator('.wp-ribbon').isVisible()));
    const small = await p.$$eval('.wp-mobilebar button', bs => bs.filter(b => { const r = b.getBoundingClientRect(); return r.width < 44 || r.height < 44; }).map(b => b.title || b.textContent));
    t.ok('אזורי לחיצה בשורה — לפחות 44px', !small.length, small.join(', '));
    await p.tap('.wp-doc p');
    await p.tap('.wp-mobilebar button[aria-label="מודגש (Ctrl+B)"]');
    await p.keyboard.type('מודגש מהטלפון');
    t.ok('מודגש מהשורה בתחתית', (await p.locator('.wp-doc strong').textContent()) === 'מודגש מהטלפון'
        && await p.getAttribute('.wp-mobilebar button[aria-label="מודגש (Ctrl+B)"]', 'aria-pressed') === 'true');
    await p.tap('.mb-tools');
    await p.waitForTimeout(300);
    const names = await p.$$eval('.rb-panel:not([hidden]) .rb-name', els => els.map(e => e.textContent));
    t.ok('"כלים" פותח את הסרט בגיליון — עם הקבוצות', await p.locator('.wp-ribbon').isVisible() && names.includes('גופן') && names.includes('פסקה'), names.join(','));
    const smallIn = await p.$$eval('.rb-panel:not([hidden]) .tb', bs => bs.filter(b => { const r = b.getBoundingClientRect(); return r.height < 44; }).length);
    t.ok('בגיליון — אזורי לחיצה 44px, ובלי גלילה הצידה', !smallIn && await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), String(smallIn));
    await p.tap('.rb-close');
    await p.waitForTimeout(300);
    t.ok('סגירה — הגיליון יורד', !(await p.locator('.wp-ribbon').isVisible()));
    await p.tap('.mb-tools');
    await p.tap('.rb-tab:text-is("הוספה")');
    await p.tap('.wp-ribbon [aria-label="טבלה"]');
    await p.tap('dialog button[type=submit]');
    await p.waitForSelector('.wp-doc table');
    if (await p.locator('.wp-ribbon.sheet-open').count()) await p.tap('.rb-close');
    await p.tap('.wp-doc table td >> nth=0');
    await p.tap('.mb-tools');
    await p.waitForTimeout(300);
    t.ok('בטבלה — הגיליון נפתח ישר בלשונית "טבלה"', await p.getAttribute('.rb-tab.ctx', 'aria-selected') === 'true' && await p.locator('.rb-tab.ctx').textContent() === 'טבלה');
    await p.tap('.rb-close');
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForTimeout(400);
    t.ok('אחרי יציאה מהמסמך — ההגדלה הרגילה חוזרת', await p.evaluate(() => getComputedStyle(document.body).touchAction) === 'auto');
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
