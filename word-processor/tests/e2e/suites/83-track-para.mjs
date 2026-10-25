/**
 * מעקב שינויים — עיצוב פסקה (כמו pPrChange ב-Word): יישור, הזחה וסגנון (פסקה → כותרת) במעקב נרשמים עם הפסקה
 * כפי שהייתה (attrs.pchg) ופס שינוי בשוליים; חזרה למקור — כבר לא שינוי; דחייה מחזירה את הפסקה (גם את הסוג), אישור
 * משאיר. הבא / הקודם עוברים גם עליהם; בתצוגה הסופית — בלי הפס. נשמר (גרסת מבנה 11); PDF במקור — הפסקה הקודמת.
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'מעקב פסקה', { type: 'doc', attrs: { track: true }, content: [P('PARAONE'), P('PARATWO'), P('PARATHREE')] });
    await t.openDoc(p, doc);
    await t.settle(p);
    const E = (fn, arg) => p.evaluate(fn, arg);
    const at = i => E(i => {
        const e = document.querySelector('.wp-doc').editor;
        let pos = 0; for (let k = 0; k < i; k++) pos += e.state.doc.child(k).nodeSize;
        e.chain().focus().setTextSelection(pos + 2).run();
    }, i);
    const blk = i => E(i => { const n = document.querySelector('.wp-doc').editor.state.doc.child(i); return { type: n.type.name, align: n.attrs.textAlign, indent: n.attrs.indent, pchg: n.attrs.pchg }; }, i);
    const bar = i => E(i => { const el = document.querySelector('.wp-doc').children[i]; return el.classList.contains('wp-pchg') && getComputedStyle(el, '::after').display !== 'none'; }, i);

    await at(0);
    await t.tool(p, 'מרכוז');
    let b = await blk(0);
    t.ok('מרכוז במעקב — נרשם, עם הפסקה כפי שהייתה (בלי יישור); פס שינוי', b.align === 'center' && b.pchg?.old?.type === 'paragraph' && !b.pchg.old.attrs.textAlign && !!b.pchg.author
        && await bar(0), JSON.stringify(b));
    await t.tool(p, 'יישור לשמאל');
    await E(() => document.querySelector('.wp-doc').editor.chain().focus().unsetTextAlign().run());   // בלי יישור — כמו במקור
    b = await blk(0);
    t.ok('חזרה ליישור המקורי — כבר לא שינוי', !b.pchg && !b.align, JSON.stringify(b));
    await t.tool(p, 'מרכוז');

    // הזחה בפסקה השנייה, כותרת 2 בשלישית
    await at(1);
    await t.tool(p, 'הגדלת הזחה');
    await at(2);
    await E(() => document.querySelector('.wp-doc').editor.chain().focus().setHeading({ level: 2 }).run());
    const b2 = await blk(1), b3 = await blk(2);
    t.ok('הזחה ופסקה → כותרת 2 — נרשמים (הסוג הקודם: פסקה)', b2.indent === 1 && b2.pchg?.old?.attrs.indent === 0 && b3.type === 'heading' && b3.pchg?.old?.type === 'paragraph',
        JSON.stringify([b2, b3]));

    // הבא / הקודם — עוברים על שינויי הפסקה
    await at(0);
    await t.tool(p, 'השינוי הבא');
    const sel = await E(() => document.querySelector('.wp-doc').editor.state.selection.$from.parent.textContent);
    t.ok('"השינוי הבא" — לפסקה השנייה (שינוי עיצוב פסקה)', sel === 'PARATWO', sel);

    // תצוגה סופית — בלי הפס
    await E(() => { const pn = document.querySelector('.wp-ribbon select.w-tview').closest('[role=tabpanel]'); if (pn?.hidden) document.getElementById(pn.getAttribute('aria-labelledby')).click(); });
    await p.selectOption('.wp-ribbon select.w-tview', 'final');
    t.ok('תצוגה סופית — בלי פס השינוי', !await bar(0));
    await p.selectOption('.wp-ribbon select.w-tview', 'all');

    // נשמר; PDF במקור — הפסקה השלישית כפסקה רגילה (לא בגודל כותרת)
    await p.keyboard.press('Control+s');
    await p.waitForTimeout(800);
    const s = await t.api(p, 'my_open', { doc });
    t.ok('נשמר: pchg בשלוש הפסקאות, גרסת מבנה 11', s.json.content.filter(n => n.attrs?.pchg).length === 3 && s.json.attrs.schema === 11, String(s.json.attrs.schema));
    const f = join(t.data, 'pc-orig.pdf'), g = join(t.data, 'pc-all.pdf');
    writeFileSync(f, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}&track=original`)).body());
    writeFileSync(g, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
    const { execFileSync } = await import('node:child_process');
    const h = file => { const m = execFileSync('pdftotext', ['-bbox', file, '-'], { encoding: 'utf8' }).match(/yMin="([\d.]+)" xMax="[\d.]+" yMax="([\d.]+)">PARATHREE</); return m && +m[2] - +m[1]; };
    t.ok('PDF: במקור — "PARATHREE" בגובה של פסקה רגילה; בסימון המלא — כותרת (גדולה יותר)', h(f) && h(g) && h(g) > h(f) + 1, `${h(f)} / ${h(g)}`);

    // דחייה של הכותרת — חוזרת לפסקה; אישור ההזחה — נשארת
    await at(2);
    await t.tool(p, 'דחיית השינוי');
    b = await blk(2);
    t.ok('דחיית שינוי הפסקה — חזרה להיות פסקה רגילה, בלי סימון', b.type === 'paragraph' && !b.pchg, JSON.stringify(b));
    await at(1);
    await t.tool(p, 'אישור השינוי');
    b = await blk(1);
    t.ok('אישור — ההזחה נשארת, בלי סימון', b.indent === 1 && !b.pchg, JSON.stringify(b));
    await p.keyboard.press('Control+z');
    b = await blk(1);
    t.ok('ביטול מחזיר את הסימון', !!b.pchg, JSON.stringify(b));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
