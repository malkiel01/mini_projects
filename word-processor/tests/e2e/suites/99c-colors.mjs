/**
 * 🎨 בורר הצבעים של ui-core (color.js, integration.md שורה 13) בעורך: כפתור "צבע טקסט" בסרגל פותח חלון עם הבורר,
 * "החלה" צובעת את הבחירה (ביטול אחד); "בעיצוב" — הצבעים שבמסמך; האחרונים נשמרים; בחלון הסגנונות — אותו בורר.
 */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'צבעים', { type: 'doc', content: [P('שלום עולם'),
        { type: 'paragraph', content: [{ type: 'text', text: 'כבר צבוע', marks: [{ type: 'textStyle', attrs: { color: '#123456' } }] }] }] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    // בחירת "שלום"
    await E(() => document.querySelector('.wp-doc').editor.chain().focus().setTextSelection({ from: 1, to: 5 }).run());

    const btn = p.locator('button.tb-color[aria-label="צבע טקסט"]').first();
    await btn.click();
    await p.waitForSelector('dialog[open] .cp-panel:not([hidden])');
    t.ok('הכפתור בסרגל פותח את הבורר של ui-core — פתוח', await p.locator('dialog[open] .cp-trigger[aria-expanded="true"]').count() === 1);
    const docRow = await p.locator('dialog[open] .cp-panel').evaluate(el => el.textContent);
    const sw = await p.locator('dialog[open] .cp-panel [style*="background"]').evaluateAll(els => els.map(e => getComputedStyle(e).backgroundColor));
    t.ok('"בעיצוב" — הצבע שבמסמך (#123456) ובסגנונות (#1f3864)', docRow.includes('בעיצוב') && sw.includes('rgb(18, 52, 86)') && sw.includes('rgb(31, 56, 100)'), JSON.stringify(sw.slice(0, 20)));

    await p.fill('dialog[open] .cp-hex', '#2e7d32');
    await p.press('dialog[open] .cp-hex', 'Enter');
    await p.click('dialog[open] button[type=submit]');
    await p.waitForSelector('dialog[open]', { state: 'detached' });
    const marks = await E(() => document.querySelector('.wp-doc').editor.getJSON().content[0].content.map(x => [x.text, x.marks?.[0]?.attrs?.color || null]));
    t.ok('"החלה" — רק הבחירה צבועה', JSON.stringify(marks) === JSON.stringify([['שלום', '#2e7d32'], [' עולם', null]]), JSON.stringify(marks));
    t.ok('הפס שמתחת לסמל — הצבע שהוחל', await btn.locator('span').evaluate(e => getComputedStyle(e).borderBottomColor) === 'rgb(46, 125, 50)');
    t.ok('המיקוד חוזר למסמך', await E(() => document.activeElement?.closest('.ProseMirror') !== null));

    await p.keyboard.press('Control+z');
    await p.waitForTimeout(150);
    t.ok('ביטול אחד — הצבע ירד', await E(() => !document.querySelector('.wp-doc').editor.getJSON().content[0].content[0].marks));

    // אחרונים — במכשיר, ומופיעים בפתיחה הבאה
    await btn.click();
    await p.waitForSelector('dialog[open] .cp-panel:not([hidden])');
    const recent = await E(() => JSON.parse(localStorage.getItem('wp-colors-recent') || '[]'));
    t.ok('🕘 אחרונים — נשמר במכשיר (wp-colors-recent)', recent[0] === '#2e7d32', JSON.stringify(recent));
    await p.click('dialog[open] button[data-close][aria-label="סגירה"]');
    await p.waitForSelector('dialog[open]', { state: 'detached' });

    // חלון הסגנונות — אותו בורר, והערך נשמר בסגנון
    await t.tool(p, 'עריכת סגנונות');
    await p.waitForSelector('dialog[open]');
    const f = 'dialog[open] .cp-field:has(> label:text-is("צבע"))';
    await p.click(f + ' .cp-trigger');
    await p.fill(f + ' .cp-hex', '#7030a0');
    await p.press(f + ' .cp-hex', 'Enter');
    await p.click('dialog[open] button[type=submit]');
    await p.waitForSelector('dialog[open]', { state: 'detached' });
    const st = await E(() => document.querySelector('.wp-doc').editor.state.doc.attrs.page.styles.normal?.color);
    t.ok('חלון הסגנונות: הבורר של ui-core, הצבע נכנס לסגנון', st === '#7030a0', st);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
