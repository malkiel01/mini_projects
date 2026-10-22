/** חיפוש והחלפה (שלב 2): Ctrl+F / Ctrl+H, ניקוד, רישיות, מילים שלמות, ביטוי רגולרי, החלפה והחלפת הכול בביטול אחד. */
const P = (...content) => ({ type: 'paragraph', content });
const T = (text, marks) => ({ type: 'text', text, ...(marks ? { marks } : {}) });
const DOC = { type: 'doc', content: [
    P(T('שָׁלוֹם עולם, שלום לכולם')),
    P(T('Shalom shalom SHALOM shalomit')),
    P(T('של'), T('ום', [{ type: 'bold' }]), T(' מודגש באמצע')),
    { type: 'table', content: [{ type: 'tableRow', content: [{ type: 'tableCell', content: [P(T('שלום בטבלה'))] }] }] },
] };

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'חיפוש', DOC);
    await t.openDoc(p, doc);
    const count = () => p.locator('.wp-findbar .find-count').textContent();
    const marks = () => p.locator('.wp-doc .wp-find').count();
    const find = async q => { await p.fill('.wp-findbar .find-in', q); await p.waitForTimeout(100); };

    // החיפוש מתחיל מהסמן, כמו ב-Word — הסמן בתחילת המסמך
    await p.click('.wp-doc p >> nth=0');
    await p.waitForTimeout(150);   // הבחירה מהלחיצה מגיעה באיחור (selectionchange) — שלא תדרוס את זו שאחריה
    await p.evaluate(() => document.querySelector('.wp-doc').editor.commands.setTextSelection(1));
    await p.keyboard.press('Control+f');
    t.ok('Ctrl+F — פס החיפוש נפתח והמיקוד בו', await p.locator('.wp-findbar').isVisible()
        && await p.evaluate(() => document.activeElement.classList.contains('find-in')));
    await find('שלום');
    t.ok('"שלום" — גם עם ניקוד, גם מעבר לעיצוב, גם בטבלה', (await count()) === '1 מתוך 4', await count());
    t.ok('כל ההתאמות מסומנות, הנוכחית בנפרד', await marks() >= 4 && await p.locator('.wp-doc .wp-find-cur').count() >= 1);
    await p.keyboard.press('Enter');
    t.ok('Enter — הבא', (await count()) === '2 מתוך 4', await count());
    await p.keyboard.press('Shift+Enter');
    await p.keyboard.press('Shift+Enter');
    t.ok('Shift+Enter — הקודם (מעגלי)', (await count()) === '4 מתוך 4', await count());

    await p.click('.wp-findbar button[aria-label="אפשרויות חיפוש"]');
    await p.click('.wp-findbar label:has-text("התעלמות מניקוד") input');
    t.ok('בלי "התעלמות מניקוד" — הניקוד חשוב', (await count()) === '1 מתוך 3' || (await count()).endsWith('מתוך 3'), await count());
    await p.click('.wp-findbar label:has-text("התעלמות מניקוד") input');

    await find('shalom');
    t.ok('אנגלית בלי רישיות — 4 (כולל shalomit)', (await count()).endsWith('מתוך 4'), await count());
    await p.click('.wp-findbar label:has-text("מילים שלמות") input');
    t.ok('מילים שלמות — 3', (await count()).endsWith('מתוך 3'), await count());
    await p.click('.wp-findbar label:has-text("התאמת רישיות") input');
    t.ok('ורישיות — 1', (await count()) === '1 מתוך 1', await count());
    await p.click('.wp-findbar label:has-text("מילים שלמות") input');
    await p.click('.wp-findbar label:has-text("התאמת רישיות") input');

    await p.click('.wp-findbar label:has-text("ביטוי רגולרי") input');
    await find('sh(alom)');
    t.ok('ביטוי רגולרי', (await count()).endsWith('מתוך 4'), await count());
    await find('sh(alom');
    t.ok('ביטוי שגוי — הודעה, בלי קריסה', (await count()).includes('לא תקין'), await count());
    await find('Sh(alom)\\b');
    await p.keyboard.press('Control+h');
    await p.fill('.wp-findbar .repl-in', 'X$1');
    await p.click('.wp-findbar button:text-is("החלפה")');
    const p2 = () => p.locator('.wp-doc p >> nth=1').textContent();
    t.ok('החלפה עם $1 — רק הנוכחית', (await p2()).startsWith('Xalom shalom'), await p2());
    await p.click('.wp-findbar label:has-text("ביטוי רגולרי") input');

    await find('שלום');
    await p.fill('.wp-findbar .repl-in', 'היי');
    await p.click('.wp-findbar button:text-is("החלף הכול")');
    const text = () => p.locator('.wp-doc').textContent();
    t.ok('החלף הכול — כל ארבעה', !(await text()).includes('שלום') && !(await text()).includes('שָׁלוֹם') && ((await text()).match(/היי/g) || []).length === 4, await text());
    t.ok('הודעה: כמה הוחלפו', (await p.locator('.toast:not([hidden])').textContent()).includes('הוחלפו 4'));
    t.ok('העיצוב של ההתאמה נשמר (מודגש בתחילת "היי")', await p.locator('.wp-doc p >> nth=2').evaluate(e => e.textContent.startsWith('היי')));
    await p.keyboard.press('Escape');
    t.ok('Escape — הפס נסגר, הסימונים נעלמים', await p.locator('.wp-findbar').isHidden() && await marks() === 0);
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(200);
    t.ok('Ctrl+Z אחד מבטל את כל ההחלפות', ((await text()).match(/שלום/g) || []).length === 3 && (await text()).includes('שָׁלוֹם'), await text());

    // בלי סימני חיפוש בהדפסה; ובקריאה בלבד — אין החלפה
    await p.keyboard.press('Control+f');
    t.ok('הקלדה אחרי פתיחה מחדש — הבחירה נכנסה לחיפוש', (await p.inputValue('.wp-findbar .find-in')).length > 0);
    await p.emulateMedia({ media: 'print' });
    t.ok('בהדפסה — הפס מוסתר', await p.locator('.wp-findbar').isHidden());
    await p.emulateMedia({ media: 'screen' });

    // R10 ד — חיפוש גם בתוך הערות שוליים והערות סיום, והחלפה בתוכן ההערה (עם העיצוב שלה)
    const FN = (text, content, end) => ({ type: 'footnote', attrs: { text, content: content || null, ...(end ? { end: true } : {}) } });
    const nd = await t.newDoc(p, 'חיפוש בהערות', { type: 'doc', content: [
        P(T('גוף עם תפוח'), FN('הערה על תפוח ירוק'), T(' ועוד')),
        P(T('סוף'), FN('תפוח בסיום', [T('תפוח', [{ type: 'bold' }]), T(' בסיום')], true)),
    ] });
    await t.openDoc(p, nd);
    await p.click('.wp-doc p >> nth=0');
    await p.waitForTimeout(150);
    await p.evaluate(() => document.querySelector('.wp-doc').editor.commands.setTextSelection(1));
    await p.keyboard.press('Control+f');
    await find('תפוח');
    t.ok('R10 ד: התאמות גם בהערות — 3 (גוף, שוליים, סיום)', (await count()) === '1 מתוך 3', await count());
    await p.keyboard.press('Enter');
    t.ok('R10 ד: ההתאמה בהערת השוליים — מסומנת על הסימן, והמונה אומר איפה', (await count()) === '2 מתוך 3 · בהערת שוליים'
        && await p.locator('.wp-doc .wp-fn-ref.wp-find-cur').count() === 1, await count());
    const notes = () => p.evaluate(() => { const o = []; document.querySelector('.wp-doc').editor.state.doc.descendants(n => { if (n.type.name === 'footnote') o.push(n.attrs); }); return o; });
    await p.keyboard.press('Control+h');
    await p.fill('.wp-findbar .repl-in', 'אגס');
    await p.click('.wp-findbar button:has-text("החלפה")');
    t.ok('R10 ד: החלפה בתוך הערת השוליים — הטקסט בהערה, הגוף לא השתנה', (await notes())[0].text === 'הערה על אגס ירוק'
        && await p.evaluate(() => document.querySelector('.wp-doc').editor.state.doc.textContent.includes('גוף עם תפוח')), JSON.stringify(await notes()));
    await p.click('.wp-findbar button:has-text("החלף הכול")');
    const after = await notes();
    t.ok('R10 ד: החלף הכול — גם בהערת הסיום, עם העיצוב (מודגש נשאר)', after[1].text === 'אגס בסיום' && after[1].content?.[0]?.text === 'אגס'
        && after[1].content[0].marks?.[0]?.type === 'bold' && await p.evaluate(() => !document.querySelector('.wp-doc').editor.state.doc.textContent.includes('תפוח')), JSON.stringify(after));
    await p.keyboard.press('Escape');
    await p.keyboard.press('Control+z');
    t.ok('R10 ד: Ctrl+Z אחד מבטל את החלף הכול — גם בהערות', (await notes())[1].text === 'תפוח בסיום' && (await notes())[0].text === 'הערה על אגס ירוק', JSON.stringify(await notes()));
    // התאמה שחוצה שתי ריצות בהערה (רגיל + מודגש): הטקסט החדש בריצה הראשונה, מה שנתפס בשנייה — נמחק
    const cross = await p.evaluate(async url => {
        const { replaceInNote } = await import(url);
        return replaceInNote({ text: 'אבג דהו', content: [{ type: 'text', text: 'אבג ' }, { type: 'text', text: 'דהו', marks: [{ type: 'bold' }] }] }, 2, 5, 'X');
    }, t.editor + 'assets/js/find.js');
    t.ok('R10 ד: החלפה שחוצה ריצות בהערה — הטקסט והריצות מתואמים', cross.text === 'אבXהו' && cross.content.map(r => r.text).join('') === cross.text
        && cross.content[1].marks?.[0]?.type === 'bold' && cross.content[1].text === 'הו', JSON.stringify(cross));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
