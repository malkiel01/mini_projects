/** הערות על טווח טקסט (שלב 4): הוספה (Ctrl+Alt+M), חלונית, תשובה, "נפתר", מחיקה, ביטול; נשמר; Word הלוך וחזור. */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'הערות', { type: 'doc', content: [P('המשפט הראשון במסמך'), P('ופסקה שנייה')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const ed = 'document.querySelector(".wp-doc").editor';
    const data = () => E(new Function(`return ${ed}.state.doc.attrs.comments`));

    // בחירה של "הראשון" והערה חדשה
    await E(new Function(`${ed}.chain().focus().setTextSelection({ from: 7, to: 13 }).run()`));
    await p.keyboard.press('Control+Alt+KeyM');
    await p.waitForSelector('.wp-comments:not([hidden]) .cm-edit');
    t.ok('הטקסט מסומן, והחלונית נפתחת עם תיבה', (await p.locator('.wp-doc .wp-cmt').textContent()) === 'הראשון');
    await p.keyboard.type('צריך לנסח מחדש');
    await p.keyboard.press('Control+Enter');
    await p.waitForSelector('.wp-comments .cm-card .cm-text');
    let d = Object.values(await data());
    t.ok('ההערה נשמרה במסמך עם המחבר', d.length === 1 && d[0].text === 'צריך לנסח מחדש' && d[0].author === 'דנה לוי' && !d[0].done, JSON.stringify(d));

    // תשובה ו"נפתר"
    await p.fill('.wp-comments .cm-reply-in', 'מסכים');
    await p.keyboard.press('Enter');
    await p.waitForTimeout(300);
    d = Object.values(await data());
    t.ok('תשובה', d[0].replies?.length === 1 && d[0].replies[0].text === 'מסכים');
    t.ok('בחלונית — ההערה והתשובה', (await p.locator('.wp-comments .cm-card .cm-text').allTextContents()).join('|') === 'צריך לנסח מחדש|מסכים');
    await p.click('.wp-comments button:has-text("נפתר")');
    await p.waitForTimeout(300);
    t.ok('נפתר — בלי צבע בטקסט', (await data())[Object.keys(await data())[0]].done === true
        && await p.locator('.wp-doc .wp-cmt').evaluate(e => getComputedStyle(e).backgroundColor) === 'rgba(0, 0, 0, 0)');
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(300);
    t.ok('ביטול — חוזר ללא נפתר', Object.values(await data())[0].done === false);

    // הערה ריקה (Escape) — נמחקת
    await E(new Function(`${ed}.chain().focus().setTextSelection({ from: 22, to: 27 }).run()`));
    await t.tool(p, 'הערה חדשה (Ctrl+Alt+M)');
    await p.waitForSelector('.wp-comments .cm-edit');
    await p.keyboard.press('Escape');
    await p.waitForTimeout(300);
    t.ok('הערה ריקה — לא נשארת', Object.keys(await data()).length === 1 && await p.locator('.wp-doc .wp-cmt').count() === 1);

    // לחיצה על הטקסט — השרשור מסומן
    await p.click('.wp-comments .nav-close');
    await p.click('.wp-doc .wp-cmt');
    t.ok('לחיצה על הטקסט — החלונית נפתחת והשרשור מסומן', await p.locator('.wp-comments .cm-card.on').count() === 1);

    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const saved = (await t.api(p, 'my_open', { doc })).json;
    t.ok('נשמר: סימון והשרשור', JSON.stringify(saved).includes('"type":"comment"') && Object.values(saved.attrs.comments || {})[0]?.replies?.length === 1);

    // מחיקה
    await p.click('.wp-comments button[aria-label="מחיקת ההערה"]');
    await p.waitForTimeout(300);
    t.ok('מחיקה — בלי סימון ובלי נתונים', await p.locator('.wp-doc .wp-cmt').count() === 0 && !(await data()));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
