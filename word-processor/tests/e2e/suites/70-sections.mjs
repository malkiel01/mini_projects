/** אב-טיפוס (שלב 0, חלק 4): מקטעים — מעבר מקטע לעמוד בכיוון ההפוך, עמודים בגדלים שונים, שמירה וביטול. */
export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'מקטעים' });
    await t.openDoc(p, doc);
    const pages = () => p.evaluate(() => [...document.querySelectorAll('.wp-pages .wp-page')].map(x => Math.round(x.getBoundingClientRect().width)));

    await p.click('.wp-doc');
    await p.keyboard.type('עמוד לאורך');
    await p.click('button[aria-label="מעבר מקטע: העמוד הבא בכיוון ההפוך"]');
    await p.keyboard.type('עמוד לרוחב');
    await t.settle(p);
    const w = await pages();
    t.ok('מעבר מקטע → עמוד חדש לרוחב', w.length === 2 && w[1] > w[0] * 1.3, w.join(' / '));
    t.ok('הטקסט במקטע החדש ברוחב העמוד שלו', await p.evaluate(() => {
        const ps = [...document.querySelectorAll('.wp-doc > p')];
        const a = ps[0].getBoundingClientRect(), b = ps[ps.length - 1].getBoundingClientRect();
        const ca = a.width - parseFloat(getComputedStyle(ps[0]).borderLeftWidth) - parseFloat(getComputedStyle(ps[0]).borderRightWidth);
        const cb = b.width - parseFloat(getComputedStyle(ps[ps.length - 1]).borderLeftWidth) - parseFloat(getComputedStyle(ps[ps.length - 1]).borderRightWidth);
        return cb > ca * 1.3;
    }));
    await p.waitForTimeout(2500);
    let s = await t.api(p, 'my_open', { doc });
    const sb = s.json.content.find(n => n.type === 'sectionBreak');
    t.ok('נשמר: מעבר מקטע עם כיוון לרוחב', sb?.attrs?.page?.orientation === 'landscape');

    // מעבר מקטע נוסף — חזרה לאורך
    await p.click('button[aria-label="מעבר מקטע: העמוד הבא בכיוון ההפוך"]');
    await p.keyboard.type('שוב לאורך');
    await t.settle(p);
    const w2 = await pages();
    t.ok('מעבר מקטע שני → חזרה לאורך', w2.length === 3 && w2[2] === w2[0], w2.join(' / '));

    // ביטול (Ctrl+Z) עד שנשאר מעבר מקטע אחד — הטקסט וההוספה עשויים להתאחד לצעד אחד
    for (let i = 0; i < 6 && await p.locator('.wp-doc .wp-section-break').count() > 1; i++) await p.keyboard.press('Control+z');
    await t.settle(p);
    const w3 = await pages();
    t.ok('ביטול מוחק את מעבר המקטע, והעמודים חוזרים', await p.locator('.wp-doc .wp-section-break').count() === 1 && w3.length === 2 && w3[1] > w3[0], w3.join(' / '));

    // חלון הגדרות העמוד — לפי המקטע שבו הסמן
    const setup = async (fill, scope) => {
        await p.click('button[aria-label="הגדרות עמוד ומספור עמודים"]');
        const d = p.locator('dialog[open]');
        if (scope) await d.locator(`input[name=scope][value=${scope}]`).check({ force: true });
        await fill(d);
        await d.locator('button[type=submit]').click();
        await d.waitFor({ state: 'detached' }).catch(() => {});
        await t.settle(p);
    };
    await p.click('.wp-doc > p:last-of-type');
    await setup(async d => {
        await d.locator('input[name=m_top]').fill('40');
        await d.locator('input[name=header]').fill('כותרת לרוחב');
    }, 'section');
    await p.waitForTimeout(2500);
    s = await t.api(p, 'my_open', { doc });
    const sb2 = s.json.content.find(n => n.type === 'sectionBreak')?.attrs?.page || {};
    t.ok('"המקטע הזה": השוליים והכותרת נכנסו למעבר המקטע', sb2.margins?.top === 40 && sb2.header === 'כותרת לרוחב' && sb2.orientation === 'landscape', JSON.stringify(sb2));
    t.ok('"המקטע הזה": המקטע הראשון לא השתנה', (s.page?.margins?.top ?? s.json.attrs?.page?.margins?.top) !== 40 && !(s.page?.header ?? s.json.attrs?.page?.header));
    t.ok('הכותרת מופיעה רק בעמוד של המקטע', await p.evaluate(() => [...document.querySelectorAll('.wp-pages .wp-page')].map(x => x.textContent.includes('כותרת לרוחב')).join()) === 'false,true');

    await setup(async d => { await d.locator('input[name=orientation][value=portrait]').check({ force: true }); }, 'all');
    const w4 = await pages();
    t.ok('"כל המסמך": כל העמודים באותו גודל', w4.length === 2 && w4[0] === w4[1], w4.join(' / '));
    await p.keyboard.press('Control+z');
    await t.settle(p);
    const w5 = await pages();
    t.ok('ביטול אחד מחזיר את כל המקטעים', w5[1] > w5[0], w5.join(' / '));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
