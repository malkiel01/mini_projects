/**
 * שער גבולות הדף (ביקורת 04, R01 / R02): אחרי כל פעולה, במצב יציב — כל שורת טקסט בתוך אזור התוכן של העמוד שלה.
 * מסמכי האוסף, ואז רצף פעולות על מסמך עם מקטעים: הוספת מקטע, שינוי כיוון, ביטול / שחזור, זום, תצוגה מקדימה,
 * הקלדה ומחיקה של מעבר מקטע. כשל — מדפיס את השורות שבחוץ (טקסט, עמוד, מלבן).
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { checkBounds } from '../lib/bounds.mjs';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });
const long = n => Array.from({ length: n }, (_, i) => `שורה ${i + 1} של טקסט ארוך מספיק כדי להישבר לכמה שורות בעמוד, עם מספרים 2025 ו-English words כדי לבדוק גם כיוון מעורב.`).join(' ');

export default async function (t) {
    const p = await t.page();
    const gate = async label => {
        await t.settle(p);
        const bad = await checkBounds(p);
        t.ok(`גבולות: ${label}`, bad.length === 0, bad.slice(0, 3).map(b => `${b.why} (עמוד ${b.page}) "${b.text}" ${b.rect} / ${b.box || ''}`).join(' | '));
    };

    // מסמכי האוסף — כולם
    const dir = join(t.app, 'tests', 'corpus');
    for (const f of readdirSync(dir).filter(f => f.endsWith('.json')).sort()) {
        const c = JSON.parse(readFileSync(join(dir, f), 'utf8'));
        const doc = await t.newDoc(p, c.title, c.json, c.page);
        await t.openDoc(p, doc);
        await gate(`אוסף — ${c.title}`);
    }

    // רצף פעולות במסמך עם מקטעים
    const doc = await t.newDoc(p, 'גבולות', { type: 'doc', content: [P(long(6)), P(long(4)), P(long(5))] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const ed = 'document.querySelector(".wp-doc").editor';
    const at = pos => E(new Function(`${ed}.chain().focus().setTextSelection(${pos}).run()`));
    await gate('מסמך פשוט');
    // מעבר מקטע אחרי הפסקה הראשונה — לרוחב
    await at(`${ed}.state.doc.child(0).nodeSize - 1`);
    await t.tool(p, 'מעבר מקטע: העמוד הבא בכיוון ההפוך');
    await gate('אחרי מעבר מקטע לרוחב');
    await p.keyboard.type(long(1));
    await gate('הקלדה במקטע לרוחב');
    // מעבר מקטע נוסף, חזרה לאורך
    await at(`${ed}.state.doc.content.size - 1`);
    await t.tool(p, 'מעבר מקטע: העמוד הבא בכיוון ההפוך');
    await p.keyboard.type(long(2));
    await gate('שני מעברי מקטע (לאורך–לרוחב–לאורך)');
    // ביטול ושחזור
    for (let i = 0; i < 3; i++) await p.keyboard.press('Control+z');
    await gate('אחרי ביטול');
    for (let i = 0; i < 3; i++) await p.keyboard.press('Control+y');
    await gate('אחרי שחזור');
    // זום
    for (const z of ['הקטנה', 'הגדלה', 'הגדלה']) await t.tool(p, z);
    await gate('אחרי זום');
    // תצוגה מקדימה וחזרה
    await t.tool(p, 'תצוגה לפני הדפסה');
    await p.waitForSelector('.wp-editor.wp-preview');
    await gate('בתצוגה מקדימה');
    await p.keyboard.press('Escape');
    await gate('אחרי יציאה מתצוגה מקדימה');
    // מחיקת מעבר המקטע הראשון (בחירה שלו ומחיקה)
    await E(new Function(`const e = ${ed}; let at = -1; e.state.doc.forEach((n, pos) => { if (at < 0 && n.type.name === 'sectionBreak') at = pos; }); e.chain().focus().setNodeSelection(at).deleteSelection().run();`));
    await gate('אחרי מחיקת מעבר מקטע');
    await p.keyboard.press('Control+z');
    await gate('ביטול מחיקת מעבר המקטע');
    // שינוי כיוון של המקטע הראשון (הגדרות עמוד, "כל המסמך")
    await at(1);
    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    await p.waitForSelector('dialog');
    await p.click('dialog label:has(input[name=orientation][value=landscape])');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await gate('המקטע הראשון לרוחב');
    await p.keyboard.press('Control+z');
    await gate('ביטול שינוי הכיוון');
    // פתיחה מחדש
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    await t.openDoc(p, doc);
    await gate('אחרי פתיחה מחדש');
    // מעבר מקטע כשהסמן בתוך רשימה / בתוך טבלה — הצומת יכול היה להיכנס פנימה (העימוד סופר אותו, רוחב הבלוקים — לא)
    const LI = text => ({ type: 'listItem', content: [P(text)] });
    const nested = await t.newDoc(p, 'מקטע ברשימה', { type: 'doc', content: [P(long(3)),
        { type: 'bulletList', content: [LI(long(2)), LI(long(2)), LI(long(2))] }, P(long(4)),
        { type: 'table', content: [{ type: 'tableRow', content: [{ type: 'tableCell', content: [P(long(1))] }, { type: 'tableCell', content: [P('תא')] }] }] }, P(long(4))] });
    await t.openDoc(p, nested);
    const inside = name => E(new Function(`const e = ${ed}; let at = -1; e.state.doc.descendants((n, pos) => { if (at < 0 && n.type.name === '${name}') at = pos; }); e.chain().focus().setTextSelection(at + 3).run();`));
    await inside('listItem');
    await t.tool(p, 'מעבר מקטע: העמוד הבא בכיוון ההפוך');
    await p.keyboard.type(long(1));
    const depth = () => E(new Function(`const out = []; ${ed}.state.doc.descendants((n, pos) => { if (n.type.name === 'sectionBreak') out.push(${ed}.state.doc.resolve(pos).depth); }); return out;`));
    t.ok('מעבר מקטע מתוך רשימה — ברמה העליונה של המסמך', (await depth()).every(d => d === 0), JSON.stringify(await depth()));
    await gate('מעבר מקטע שהוכנס מתוך רשימה');
    await inside('tableCell');
    await t.tool(p, 'מעבר מקטע: העמוד הבא בכיוון ההפוך');
    t.ok('מעבר מקטע מתוך טבלה — ברמה העליונה של המסמך', (await depth()).every(d => d === 0), JSON.stringify(await depth()));
    await gate('מעבר מקטע שהוכנס מתוך טבלה');
    // מסמך שכבר נשמר עם מעבר מקטע בתוך רשימה (מגרסה קודמת / הדבקה) — מוצג בתוך העמודים
    const saved = await t.newDoc(p, 'מקטע פנימי שמור', { type: 'doc', content: [P(long(3)),
        { type: 'bulletList', content: [LI(long(2)), { type: 'listItem', content: [P(long(2)), { type: 'sectionBreak', attrs: { page: { orientation: 'landscape' } } }, P(long(3))] }] }, P(long(6))] });
    await t.openDoc(p, saved);
    await gate('מסמך שמור עם מעבר מקטע בתוך רשימה');
    // R01 — שחזור: מקטע ראשון לרוחב, מעבר מקטע (לאורך) בתוך רשימה, ואחריו מעבר מקטע רגיל. העימוד ספר את המעבר
    // הפנימי (ועבר לעמוד צר), ורוחב הבלוקים — לא: טקסט ברוחב עמוד לרוחב על עמוד צר, וטקסט בין העמודים
    const r01 = await t.newDoc(p, 'R01', { type: 'doc', content: [P(long(3)),
        { type: 'bulletList', content: [LI(long(2)), { type: 'listItem', content: [P(long(2)), { type: 'sectionBreak', attrs: { page: { orientation: 'portrait' } } }, P(long(6))] }] },
        P(long(8)), { type: 'sectionBreak', attrs: { page: { orientation: 'portrait' } } }, P(long(5))] }, { orientation: 'landscape' });
    await t.openDoc(p, r01);
    await gate('R01: מעבר מקטע בתוך רשימה ועוד מעבר אחריו');
    // התיקון לא מאבד תוכן: כל הטקסט נשאר, ושני מעברי המקטע — ברמה העליונה
    const r01Text = await E(new Function(`return ${ed}.state.doc.textContent.replace(/\\s+/g, '')`));
    const srcText = [long(3), long(2), long(2), long(6), long(8), long(5)].join('').replace(/\s+/g, '');
    t.ok('R01: אף מילה לא נעלמה, ומעברי המקטע ברמה העליונה', r01Text === srcText && (await depth()).join() === '0,0', (await depth()).join());
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
