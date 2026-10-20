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
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForTimeout(400);
    t.ok('אחרי יציאה מהמסמך — ההגדלה הרגילה חוזרת', await p.evaluate(() => getComputedStyle(document.body).touchAction) === 'auto');
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
