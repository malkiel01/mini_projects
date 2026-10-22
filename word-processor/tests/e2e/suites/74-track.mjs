/** מעקב שינויים (שלב 4): הקלדה ומחיקה במעקב, מחיקת תוספת, קבלה ודחייה, הבא / הקודם, ביטול; נשמר; PDF ו-Word. */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'מעקב', { type: 'doc', content: [P('אחת שתיים שלוש'), P('ארבע חמש')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const ed = 'document.querySelector(".wp-doc").editor';
    const at = pos => E(new Function(`${ed}.chain().focus().setTextSelection(${JSON.stringify(pos)}).run()`));
    /** המיקום של מחרוזת במסמך (אחרי k מופעים) — [from, to]. */
    const find = (str, end = false) => E(new Function(`let r = null; ${ed}.state.doc.descendants((n, pos) => { if (r || !n.isText) return; const i = n.text.indexOf(${JSON.stringify(str)}); if (i >= 0) r = [pos + i, pos + i + ${[...'x'].length} * 0 + ${JSON.stringify(str)}.length]; }); return r;`));
    const shown = () => E(() => [...document.querySelector('.wp-doc').querySelectorAll('p')].map(p => [...p.childNodes].map(n =>
        n.nodeName === 'INS' ? `+[${n.textContent}]` : n.nodeName === 'DEL' ? `-[${n.textContent}]` : n.textContent).join('')).join(' / '));

    await at(1);
    await p.keyboard.press('Control+Shift+KeyE');
    t.ok('Ctrl+Shift+E — המעקב פועל (נשמר במסמך), הכפתור לחוץ', await E(new Function(`return ${ed}.state.doc.attrs.track`)) === true
        && await p.locator('.wp-ribbon [aria-label="מעקב שינויים (Ctrl+Shift+E)"]').getAttribute('aria-pressed') === 'true');

    // הקלדה בתחילת הפסקה — תוספת
    await p.keyboard.type('חדש ');
    t.ok('הקלדה — מסומנת כתוספת', (await shown()) === '+[חדש ]אחת שתיים שלוש / ארבע חמש', await shown());
    // Backspace על תוספת — נמחקת באמת
    await p.keyboard.press('Backspace');
    t.ok('Backspace על תוספת — נמחקת באמת', (await shown()) === '+[חדש]אחת שתיים שלוש / ארבע חמש', await shown());
    // Backspace אחרי "שתיים" — המילה נשארת, מסומנת כמחיקה, והסמן זז שמאלה
    await at((await find('שתיים'))[1]);
    for (let i = 0; i < 5; i++) await p.keyboard.press('Backspace');
    t.ok('Backspace על טקסט — מסומן כמחיקה, לא נמחק', (await shown()) === '+[חדש]אחת -[שתיים] שלוש / ארבע חמש', await shown());
    // בחירה והקלדה במקום — מחיקה ותוספת
    const [a4, b4] = await find('ארבע');
    await at({ from: a4, to: b4 });
    await p.keyboard.type('שש');
    t.ok('החלפת בחירה — מחיקה ואחריה תוספת', (await shown()).endsWith('/ -[ארבע]+[שש] חמש'), await shown());
    // מחיקה שחוצה פסקאות — הפסקאות לא מתאחדות
    const before = await E(new Function(`return ${ed}.state.doc.childCount`));
    await at({ from: (await find('שלוש'))[0], to: (await find('חמש'))[0] });
    await p.keyboard.press('Delete');
    t.ok('מחיקה בין פסקאות — שתי פסקאות נשארות', await E(new Function(`return ${ed}.state.doc.childCount`)) === before, await shown());
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(100);

    // הבא / הקודם, ואישור / דחייה
    await at(1);
    await t.tool(p, 'השינוי הבא');
    const sel = await E(new Function(`const s = ${ed}.state.selection; return ${ed}.state.doc.textBetween(s.from, s.to)`));
    t.ok('השינוי הבא — בוחר את "שתיים"', sel === 'שתיים', sel);
    await t.tool(p, 'דחיית השינוי');
    t.ok('דחיית מחיקה — המילה חוזרת', (await shown()).startsWith('+[חדש]אחת שתיים שלוש'), await shown());
    await p.keyboard.press('Control+z');
    t.ok('ביטול — המחיקה חוזרת', (await shown()).startsWith('+[חדש]אחת -[שתיים]'), await shown());
    await t.tool(p, 'אישור כל השינויים');
    t.ok('אישור הכול — בלי סימונים, הטקסט הסופי', (await shown()) === 'חדשאחת  שלוש / שש חמש', await shown());
    t.ok('הכפתורים כבויים כשאין שינויים', await p.locator('.wp-ribbon [aria-label="השינוי הבא"]').isDisabled());
    await p.keyboard.press('Control+z');

    // כיבוי המעקב — עריכה רגילה
    await p.keyboard.press('Control+Shift+KeyE');
    await at(1);
    await p.keyboard.type('x');
    t.ok('בלי מעקב — הקלדה רגילה', (await shown()).startsWith('x+[חדש]'), await shown());

    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const saved = JSON.stringify((await t.api(p, 'my_open', { doc })).json);
    t.ok('נשמר: ins ו-del עם מחבר', saved.includes('"type":"ins"') && saved.includes('"type":"del"') && saved.includes('"author":"דנה לוי"'));
    const res = await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`);
    const f = join(t.data, 'track.pdf');
    writeFileSync(f, await res.body());
    const pages = t.pdfPages(f).join(' ');
    t.ok('PDF מהשרת: גם הטקסט המחוק מופיע (בקו חוצה, כמו בעורך)', pages.includes('שתיים') && pages.includes('ארבע'), pages);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
