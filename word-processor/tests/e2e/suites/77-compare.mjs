/**
 * השוואת גרסאות (שלב 4, כמו "השוואה" ב-Word): בחלון הגרסאות — "השוואה לנוכחית" פותח מסמך חדש שבו מה שהשתנה מאז
 * הגרסה מסומן כמעקב שינויים (המחבר "השוואה"): מילה שהוחלפה — מחיקה ותוספת, פסקה שנוספה — תוספת. המסמך המקורי לא
 * משתנה; במסמך ההשוואה מאשרים / דוחים כרגיל.
 */
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'להשוואה', { type: 'doc', content: [P('אחת שתיים שלוש'), P('פסקה קבועה')] });
    await t.openDoc(p, doc);
    // הגרסה הראשונה — סגורה (כאילו עברו 10 דקות): העריכה יוצרת גרסה חדשה
    execFileSync('php', ['-r', `$d = new PDO('sqlite:' . $argv[1]); $d->prepare('UPDATE versions SET started_at = started_at - 700 WHERE doc = ?')->execute([$argv[2]]);`,
        join(t.data, 'word-processor.sqlite'), doc]);
    await p.evaluate(() => {
        const e = document.querySelector('.wp-doc').editor;
        let r = null;
        e.state.doc.descendants((n, pos) => { if (!r && n.isText && n.text.includes('שתיים')) r = pos + n.text.indexOf('שתיים'); });
        e.chain().focus().setTextSelection({ from: r, to: r + 5 }).insertContent('ארבע').run();
        e.chain().focus('end').run();
    });
    await p.keyboard.press('Enter');
    await p.keyboard.type('פסקה חדשה');
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);

    await t.tool(p, 'גרסאות');
    await p.waitForSelector('dialog .ver-cmp');
    t.ok('חלון הגרסאות: "השוואה לנוכחית" בגרסה הקודמת (לא בנוכחית)', await p.locator('dialog .ver-row').first().locator('.ver-cmp').count() === 0
        && await p.locator('dialog .ver-cmp').count() >= 1);
    await p.locator('dialog .ver-cmp').last().click();
    await p.waitForFunction(id => !location.hash.includes(id) && location.hash.startsWith('#/d/'), doc);
    await p.waitForSelector('.wp-doc ins, .wp-doc del');
    await t.settle(p);
    const shown = await p.evaluate(() => [...document.querySelectorAll('.wp-doc p')].map(el => [...el.childNodes].map(n =>
        n.nodeName === 'INS' ? `+[${n.textContent}]` : n.nodeName === 'DEL' ? `-[${n.textContent}]` : n.textContent).join('')).join(' / '));
    t.ok('מסמך ההשוואה: המילה שהוחלפה — מחיקה ותוספת, הפסקה החדשה — תוספת', shown === 'אחת -[שתיים]+[ארבע] שלוש / פסקה קבועה / +[פסקה חדשה]', shown);
    const meta = await p.evaluate(() => ({ author: document.querySelector('.wp-doc ins')?.dataset.author, track: document.querySelector('.wp-doc').editor.state.doc.attrs.track }));
    t.ok('המחבר "השוואה", והמעקב לא פועל במסמך ההשוואה', meta.author === 'השוואה' && !meta.track, JSON.stringify(meta));
    const orig = await t.api(p, 'my_open', { doc });
    t.ok('המסמך המקורי לא השתנה (בלי סימונים)', !JSON.stringify(orig.json).includes('"ins"') && JSON.stringify(orig.json).includes('ארבע'));
    // אישור כל השינויים — המסמך כמו הנוכחי
    await t.tool(p, 'אישור כל השינויים');
    await t.settle(p);
    const after = await p.evaluate(() => [...document.querySelectorAll('.wp-doc p')].map(el => el.textContent).join(' / '));
    t.ok('אישור הכול במסמך ההשוואה — התוכן הנוכחי', after === 'אחת ארבע שלוש / פסקה קבועה / פסקה חדשה' && !await p.locator('.wp-doc ins, .wp-doc del').count(), after);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
