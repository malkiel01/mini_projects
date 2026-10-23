/**
 * מעקב שינויים — שינויי עיצוב (כמו rPrChange ב-Word): מודגש / נטוי במעקב נרשמים עם העיצוב הקודם; חזרה לעיצוב
 * המקורי — כבר לא שינוי; תצוגת המקור מציגה את העיצוב הקודם; דחייה מחזירה אותו, אישור משאיר את החדש; עיצוב של
 * טקסט שנוסף במעקב — לא נרשם. נשמר (גרסת מבנה 7).
 */
const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'מעקב עיצוב', { type: 'doc', attrs: { track: true }, content: [P('אחת שתיים שלוש')] });
    await t.openDoc(p, doc);
    await t.settle(p);
    const E = (fn, arg) => p.evaluate(fn, arg);
    const select = word => E(w => {
        const e = document.querySelector('.wp-doc').editor;
        let r = null;
        e.state.doc.descendants((n, pos) => { if (!r && n.isText && n.text.includes(w)) r = pos + n.text.indexOf(w); });
        e.chain().focus().setTextSelection({ from: r, to: r + w.length }).run();
    }, word);
    /** לכל קטע טקסט: הטקסט, הסימונים, והעיצוב הקודם של fmt. */
    const runs = () => E(() => {
        const out = [];
        document.querySelector('.wp-doc').editor.state.doc.descendants(n => {
            if (n.isText) out.push({ t: n.text, m: n.marks.map(m => m.type.name).sort().join(','), old: n.marks.find(m => m.type.name === 'fmt')?.attrs.old.map(o => o.type).join(',') });
        });
        return out;
    });
    const of = async w => (await runs()).find(r => r.t.includes(w));
    const weight = w => E(word => {
        // האלמנט הפנימי ביותר שכל הטקסט שלו הוא המילה (בתוך span.wp-fmt יש strong)
        let last = null;
        for (const el of document.querySelectorAll('.wp-doc *')) if (el.textContent === word) last = el;
        return last && getComputedStyle(last).fontWeight;
    }, w);

    await select('שתיים');
    await p.keyboard.press('Control+b');
    const r1 = await of('שתיים');
    t.ok('מודגש במעקב — נרשם כשינוי עיצוב, העיצוב הקודם: בלי', r1.m === 'bold,fmt' && r1.old === '', JSON.stringify(r1));
    t.ok('המילה מודגשת במסך, עם סימון השינוי', await weight('שתיים') === '700' && await E(() => !!document.querySelector('.wp-doc .wp-fmt')));
    await p.keyboard.press('Control+b');
    const r2 = await of('שתיים');
    t.ok('ביטול ההדגשה — חזר לעיצוב המקורי, כבר לא שינוי', r2.m === '', JSON.stringify(r2));

    await p.keyboard.press('Control+b');
    await select('שלוש');
    await p.keyboard.press('Control+i');
    const n = await E(() => [...document.querySelectorAll('.wp-doc .wp-fmt')].length);
    t.ok('שני שינויי עיצוב', n === 2, String(n));

    // תצוגת המקור — העיצוב הקודם; סופי — החדש
    await E(() => { const pn = document.querySelector('.wp-ribbon select.w-tview').closest('[role=tabpanel]'); if (pn?.hidden) document.getElementById(pn.getAttribute('aria-labelledby')).click(); });
    await p.selectOption('.wp-ribbon select.w-tview', 'original');
    await t.settle(p);
    t.ok('תצוגת המקור: "שתיים" לא מודגשת (העיצוב הקודם)', await weight('שתיים') === '400', String(await weight('שתיים')));
    await p.selectOption('.wp-ribbon select.w-tview', 'final');
    t.ok('תצוגה סופית: מודגשת', await weight('שתיים') === '700');
    await p.selectOption('.wp-ribbon select.w-tview', 'all');

    // נשמר
    await p.waitForTimeout(2500);
    const s = await t.api(p, 'my_open', { doc });
    const flat = JSON.stringify(s.json);
    t.ok('נשמר: סימון fmt עם העיצוב הקודם, גרסת מבנה 7', flat.includes('"fmt"') && flat.includes('"old"') && s.json.attrs.schema >= 7, flat.slice(0, 300));

    // דחייה — העיצוב הקודם חוזר; אישור — החדש נשאר
    await select('שתיים');
    await t.tool(p, 'דחיית השינוי');
    const r3 = await of('שתיים');
    t.ok('דחיית שינוי עיצוב — חזר לעיצוב הקודם', r3.m === '', JSON.stringify(r3));
    await select('שלוש');
    await t.tool(p, 'אישור השינוי');
    const r4 = await of('שלוש');
    t.ok('אישור שינוי עיצוב — הנטוי נשאר, בלי סימון', r4.m === 'italic', JSON.stringify(r4));

    // טקסט שנוסף במעקב — העיצוב שלו לא נרשם
    await E(() => { const e = document.querySelector('.wp-doc').editor; e.chain().focus().setTextSelection(1).run(); });
    await p.keyboard.type('חדש ');
    await select('חדש');
    await p.keyboard.press('Control+b');
    const r5 = await of('חדש');
    t.ok('עיצוב של תוספת במעקב — לא נרשם כשינוי עיצוב', r5.m === 'bold,ins', JSON.stringify(r5));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
