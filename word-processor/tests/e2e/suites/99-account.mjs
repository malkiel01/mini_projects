/**
 * 👤 תפריט המשתמש (ui-core account.js, integration.md שורה 19): העיגול בקצה הסרגל — ברשימה ובמסמך; התפריט; הגדרות
 * אישיות שנשמרות במסד שלנו (ערכה כהה — נשארת אחרי טעינה מחדש); "ייצוא כל הנתונים שלי" מחזיר את המסמכים; בטלפון — גיליון.
 */
export default async function (t) {
    const p = await t.page();
    await p.goto(t.editor);
    await p.waitForSelector('.list');
    const btn = 'header.top .account-btn';
    t.ok('העיגול בסרגל — ברשימה', await p.locator(btn).isVisible());

    await p.click(btn);
    const items = await p.locator('[role="menuitem"]').allTextContents();
    t.ok('בתפריט: המסמכים שלי, הגדרות אישיות, החשבון, יציאה', ['המסמכים שלי', 'הגדרות אישיות', 'החשבון', 'יציאה'].every(x => items.some(i => i.includes(x))), JSON.stringify(items));
    await p.click('[role="menuitem"]:has-text("הגדרות אישיות")');
    await p.click('dialog[open] label:has-text("כהה")');
    await p.waitForTimeout(600);
    t.ok('ערכה כהה — מוחלת מיד', await p.evaluate(() => document.documentElement.dataset.theme) === 'dark');
    await p.keyboard.press('Escape');

    await p.reload();
    await p.waitForSelector('.list');
    t.ok('ערכה כהה — נשמרת (במסד של העורך) ונשארת אחרי טעינה', await p.evaluate(() => document.documentElement.dataset.theme) === 'dark');
    const prefs = await t.api(p, 'account_prefs', {});
    t.ok('account_prefs מחזיר את ההגדרה', prefs.prefs?.general?.theme === 'dark', JSON.stringify(prefs));

    // ייצוא כל הנתונים: גם המסמכים
    const { doc } = await t.api(p, 'my_new', { title: 'לייצוא' });
    const exp = await t.api(p, 'account_export', {});
    t.ok('ייצוא כל הנתונים — כולל המסמכים', JSON.stringify(exp).includes(doc), JSON.stringify(exp).slice(0, 200));
    const del = await p.evaluate(() => fetch('api.php?action=account_delete', { method: 'POST', headers: { 'X-WP': '1', 'Content-Type': 'application/json' }, body: JSON.stringify({ confirm: 'DELETE' }) }).then(r => r.status));
    t.ok('מחיקת חשבון — לא זמינה כאן (501)', del === 501, String(del));

    // בתוך מסמך — העיגול נשאר בסרגל
    await t.openDoc(p, doc);
    t.ok('העיגול בסרגל — גם בתוך מסמך', await p.locator(btn).isVisible());
    await t.api(p, 'account_prefs_set', { ns: 'general', value: { theme: 'system' } });

    // טלפון: התפריט — גיליון מלמטה
    await p.setViewportSize({ width: 390, height: 800 });
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('.list');
    await p.click(btn);
    await p.waitForTimeout(400);
    t.ok('בטלפון — העיגול בתוך המסך, והתפריט נפתח כגיליון מלמטה', await p.evaluate(sel => { const r = document.querySelector(sel).getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }, btn)
        && await p.locator('dialog[open]:has-text("הגדרות אישיות")').isVisible());
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
