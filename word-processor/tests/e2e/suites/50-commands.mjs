/** רישום הפקודות (assets/js/commands.js): שלם, ייחודי, סרגל הכלים בנוי ממנו, והפקודות עובדות מהסרגל. */
export default async function (t) {
    const p = await t.page();
    const reg = await p.evaluate(async () => {
        const m = await import('./assets/js/commands.js');
        return { cmds: m.COMMANDS.map(c => ({ id: c.id, label: c.label, group: c.group, keys: c.keys || '' })), groups: Object.keys(m.GROUPS) };
    });
    const ids = reg.cmds.map(c => c.id);
    t.ok('לכל פקודה מזהה ייחודי', new Set(ids).size === ids.length, `${ids.length} פקודות`);
    t.ok('לכל פקודה שם וקבוצה מוכרת', reg.cmds.every(c => c.label && reg.groups.includes(c.group)));

    const { doc } = await t.api(p, 'my_new', { title: 'פקודות' });
    await t.openDoc(p, doc);
    const titles = new Set(reg.cmds.map(c => c.label + (c.keys ? ` (${c.keys})` : '')));
    const bar = await p.$$eval('.wp-toolbar:not(.wp-ctx) button.tb, .wp-toolbar:not(.wp-ctx) select, .wp-toolbar:not(.wp-ctx) label.tb', els => els.map(e => e.title || e.getAttribute('aria-label')));
    const orphans = bar.filter(x => !titles.has(x) && !['גופן', 'גודל'].includes(x));
    t.ok('כל כפתור בסרגל — פקודה מהרישום', !orphans.length, orphans.join(', ') || `${bar.length} פקדים`);

    await p.click('.wp-doc');
    await p.keyboard.type('בדיקת פקודות');
    await p.keyboard.press('Control+a');
    await p.click('button[aria-label="מודגש (Ctrl+B)"]');
    t.ok('מודגש מהסרגל', await p.locator('.wp-doc strong').count() === 1 && await p.getAttribute('button[aria-label="מודגש (Ctrl+B)"]', 'aria-pressed') === 'true');
    await p.click('button[aria-label="מרכוז"]');
    t.ok('מרכוז מהסרגל', (await p.locator('.wp-doc p').first().getAttribute('style') || '').includes('text-align: center'));
    await p.selectOption('select[aria-label="גודל גופן"]', '18');
    t.ok('גודל גופן מהסרגל', (await p.locator('.wp-doc span[style*="font-size"]').first().getAttribute('style') || '').includes('18pt'));
    await p.click('button[aria-label="ביטול (Ctrl+Z)"]');
    t.ok('ביטול מהסרגל', await p.locator('.wp-doc span[style*="font-size"]').count() === 0);
    await p.click('button[aria-label="תבליטים"]');
    t.ok('תבליטים מהסרגל', await p.locator('.wp-doc ul li').count() === 1);
    await p.click('button[aria-label="תבליטים"]');

    // טבלה: הסרגל להקשר נבנה מהרישום, והפקודות שלו עובדות
    await p.keyboard.press('End');
    await p.keyboard.press('Enter');
    await p.click('button[aria-label="טבלה"]');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('.wp-ctx:not([hidden]) button[aria-label="שורה מתחת"]');
    const rows = await p.locator('.wp-doc table tr').count();
    await p.click('button[aria-label="שורה מתחת"]');
    t.ok('טבלה: "שורה מתחת" מהסרגל להקשר', await p.locator('.wp-doc table tr').count() === rows + 1, `${rows} → ${rows + 1}`);
    await p.click('button[aria-label="מחיקת הטבלה"]');
    t.ok('טבלה: מחיקה', await p.locator('.wp-doc table').count() === 0);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
