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
    await t.tool(p, 'מודגש (Ctrl+B)');
    t.ok('מודגש מהסרגל', await p.locator('.wp-doc strong').count() === 1 && await p.getAttribute('button[aria-label="מודגש (Ctrl+B)"]', 'aria-pressed') === 'true');
    await t.tool(p, 'מרכוז');
    t.ok('מרכוז מהסרגל', (await p.locator('.wp-doc p').first().getAttribute('style') || '').includes('text-align: center'));
    await p.selectOption('select[aria-label="גודל גופן"]', '18');
    t.ok('גודל גופן מהסרגל', (await p.locator('.wp-doc span[style*="font-size"]').first().getAttribute('style') || '').includes('18pt'));
    await p.click('button[aria-label="ביטול (Ctrl+Z)"]');
    t.ok('ביטול מהסרגל', await p.locator('.wp-doc span[style*="font-size"]').count() === 0);
    await t.tool(p, 'תבליטים');
    t.ok('תבליטים מהסרגל', await p.locator('.wp-doc ul li').count() === 1);
    await t.tool(p, 'תבליטים');

    // טבלה: הסרגל להקשר נבנה מהרישום, והפקודות שלו עובדות
    await p.keyboard.press('End');
    await p.keyboard.press('Enter');
    await t.tool(p, 'טבלה');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('.rb-tab.ctx:not([hidden])');
    const rows = await p.locator('.wp-doc table tr').count();
    await t.tool(p, 'שורה מתחת');
    t.ok('טבלה: "שורה מתחת" מהסרגל להקשר', await p.locator('.wp-doc table tr').count() === rows + 1, `${rows} → ${rows + 1}`);
    t.ok('לשונית "טבלה" מופיעה כשהסמן בטבלה', await p.locator('.rb-tab.ctx:not([hidden])').textContent() === 'טבלה');
    await t.tool(p, 'מחיקת הטבלה');
    t.ok('טבלה: מחיקה', await p.locator('.wp-doc table').count() === 0);
    await p.waitForTimeout(100);
    t.ok('אחרי היציאה מהטבלה — הלשונית נעלמת, וחוזרים ללשונית הקודמת', await p.locator('.rb-tab.ctx').isHidden() && await p.getAttribute('.rb-tab.ctx', 'aria-selected') === 'false'
        && await p.locator('.rb-tab[aria-selected="true"]').count() === 1);

    // הסרט: לשוניות, חצים, כיווץ (Ctrl+F1), ושורת המצב
    const shown = () => p.$$eval('.rb-panel:not([hidden]) .rb-name', els => els.map(e => e.textContent));
    await p.click('.rb-tab:text-is("פריסה")');
    t.ok('לשונית "פריסה": הגדרת עמוד ומעברים', JSON.stringify(await shown()) === JSON.stringify(['הגדרת עמוד', 'מעברים']), (await shown()).join(','));
    await p.focus('.rb-tab:text-is("פריסה")');
    await p.keyboard.press('ArrowLeft');
    t.ok('חץ שמאלה — הלשונית הבאה (תצוגה)', await p.getAttribute('.rb-tab:text-is("תצוגה")', 'aria-selected') === 'true'
        && await p.evaluate(() => document.activeElement.textContent) === 'תצוגה');
    await p.click('.wp-doc');
    await p.keyboard.press('Control+F1');
    t.ok('Ctrl+F1 — הסרט מתכווץ (הלשוניות נשארות)', await p.locator('.rb-panel:not([hidden])').isHidden() && await p.locator('.rb-tabs').isVisible());
    await p.click('.rb-tab:text-is("בית")');
    t.ok('לחיצה על לשונית — הסרט חוזר', await p.locator('.rb-panel:not([hidden])').isVisible() && (await shown()).includes('גופן'));
    await p.click('.wp-doc');
    await p.keyboard.press('Control+End');
    await p.keyboard.type(' שלוש מילים נוספות');
    await p.waitForTimeout(500);
    const st = [await p.locator('.wp-statusbar .st-page').textContent(), await p.locator('.wp-statusbar .st-words').textContent()];
    const words = await p.evaluate(() => (document.querySelector('.wp-doc').innerText.match(/[^\s]+/g) || []).length);
    t.ok('שורת המצב: עמוד X מתוך Y ומספר מילים', st[0] === 'עמוד 1 מתוך 1' && st[1] === `${words} מילים` && words >= 3, st.join(' | ') + ` (במסמך: ${words})`);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
