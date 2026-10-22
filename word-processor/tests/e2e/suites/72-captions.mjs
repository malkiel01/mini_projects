/** כיתובים ממוספרים והפניות צולבות (שלב 4): הוספת כיתוב, מספור לפי הסדר, הפניה שמתעדכנת, Ctrl+לחיצה; נשמר; PDF מהשרת. */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'כיתובים', { type: 'doc', content: [P('פתיחה'), P('תמונה ראשונה'), P('תמונה שנייה'), P('סוף')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const ed = 'document.querySelector(".wp-doc").editor';
    const at = text => E(new Function(`const e = ${ed}; let at = 0; e.state.doc.descendants((n, pos) => { if (!at && n.isTextblock && n.textContent === ${JSON.stringify(text)}) at = pos + 1; }); e.chain().focus().setTextSelection(at).run();`));
    const caption = async (label, text) => {
        await t.tool(p, 'הוספת כיתוב');
        await p.waitForSelector('dialog input[name=text]');
        await p.click(`dialog label:has(input[name=label][value="${label}"])`);
        await p.fill('dialog input[name=text]', text);
        await p.click('dialog button[type=submit]');
        await p.waitForSelector('dialog', { state: 'detached' });
    };
    const shown = () => p.locator('.wp-doc .wp-caption').evaluateAll(els => els.map(e => e.textContent + [...e.querySelectorAll('.wp-seq')].map(s => '#' + s.dataset.n).join('')));

    await at('תמונה שנייה');
    await caption('איור', 'השנייה');
    await at('תמונה ראשונה');
    await caption('איור', 'הראשונה');
    t.ok('שני כיתובים, ממוספרים לפי הסדר במסמך', (await shown()).join('|') === 'איור : הראשונה#1|איור : השנייה#2', (await shown()).join('|'));
    t.ok('בסגנון "כיתוב" — קטן ונטוי', await p.locator('.wp-doc p.wp-caption').first().evaluate(e => getComputedStyle(e).fontStyle === 'italic' && parseFloat(getComputedStyle(e).fontSize) < 13));

    // הפניה צולבת לכיתוב השני, בפסקת "סוף"
    await at('סוף');
    await p.keyboard.press('End');
    await p.keyboard.type(' ראו ');
    await t.tool(p, 'הפניה צולבת');
    await p.waitForSelector('dialog select[name=target]');
    await p.selectOption('dialog select[name=target]', { index: 1 });
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    const xref = () => p.locator('.wp-doc .wp-xref').first().getAttribute('data-n');
    t.ok('ההפניה מציגה "איור 2"', (await xref()) === 'איור 2', await xref());

    // מחיקת הכיתוב הראשון — "השנייה" הופך ל-1, וההפניה מתעדכנת
    await E(new Function(`const e = ${ed}; let r = null; e.state.doc.descendants((n, pos) => { if (!r && n.attrs?.pstyle === 'caption') r = [pos, pos + n.nodeSize]; }); e.chain().deleteRange({ from: r[0], to: r[1] }).run();`));
    t.ok('אחרי מחיקת הכיתוב הראשון — המספור וההפניה מתעדכנים', (await shown()).join('|') === 'איור : השנייה#1' && (await xref()) === 'איור 1', (await shown()).join('|') + ' / ' + await xref());

    // Ctrl+לחיצה על ההפניה — הסמן עובר לכיתוב
    await p.locator('.wp-doc .wp-xref').click({ modifiers: ['Control'] });
    const where = await E(new Function(`return ${ed}.state.selection.$from.parent.attrs.pstyle`));
    t.ok('Ctrl+לחיצה — מעבר לכיתוב', where === 'caption', String(where));

    // כיתוב לטבלה — ברירת המחדל "טבלה", מעל
    await E(new Function(`const e = ${ed}; e.chain().focus().setTextSelection(1).insertTable({ rows: 2, cols: 2, withHeaderRow: false }).run();`));
    await t.tool(p, 'הוספת כיתוב');
    await p.waitForSelector('dialog input[name=text]');
    t.ok('בטבלה: ברירת המחדל — "טבלה", מעל', await p.locator('dialog input[name=label][value="טבלה"]').isChecked() && await p.locator('dialog input[name=pos][value=above]').isChecked());
    await p.fill('dialog input[name=text]', 'נתונים');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    const order = await E(new Function(`return ${ed}.getJSON().content.map(b => b.type === 'paragraph' && b.attrs?.pstyle === 'caption' ? 'cap:' + b.content.map(c => c.text || c.attrs?.label).join('') : b.type).join(',')`));
    t.ok('כיתוב הטבלה מעליה, ומספרו 1', order.includes('cap:טבלה טבלה: נתונים,table') && (await shown())[0].endsWith('#1'), order);

    // רשימת איורים בתחילת המסמך: הכיתוב "איור" עם מספר העמוד; כיתוב חדש — מופיע אחרי "עדכון"
    await E(new Function(`${ed}.chain().focus().setTextSelection(1).run()`));
    await t.tool(p, 'רשימת איורים');
    await p.waitForSelector('dialog input[name=label]');
    t.ok('ברירת המחדל — התווית שבשימוש', await p.locator('dialog input[name=label][value="איור"]').isChecked());
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('dialog', { state: 'detached' });
    await p.waitForTimeout(1200);
    const tof = () => p.locator('.wp-doc .wp-toc').first().evaluate(e => [...e.querySelectorAll('.wp-toc-title, .wp-toc-line')].map(x => x.textContent).join('|'));
    t.ok('רשימת איורים: הכותרת והכיתוב עם המספר והעמוד', (await tof()) === 'רשימת איורים|איור 1: השנייה1', await tof());
    await E(new Function(`const e = ${ed}; e.chain().focus().setTextSelection(e.state.doc.content.size - 1).run();`));
    await caption('איור', 'שלישי');
    await t.tool(p, 'עדכון תוכן עניינים');
    await p.waitForTimeout(1200);
    t.ok('אחרי עדכון — גם הכיתוב החדש (בסוף)', (await tof()) === 'רשימת איורים|איור 1: השנייה1|איור 2: שלישי1', await tof());
    await p.locator('.wp-doc .wp-toc-line').nth(1).click();
    await p.waitForTimeout(200);
    const atCap = await E(new Function(`return ${ed}.state.selection.$from.parent.textContent`));
    t.ok('לחיצה על שורה — מעבר לכיתוב', atCap === 'איור : שלישי', atCap);

    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const saved = JSON.stringify((await t.api(p, 'my_open', { doc })).json);
    t.ok('נשמר: seq ו-xref', saved.includes('"type":"seq"') && saved.includes('"type":"xref"'));
    const res = await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`);
    const f = join(t.data, 'captions.pdf');
    writeFileSync(f, await res.body());
    const pages = t.pdfPages(f).join(' ');
    t.ok('PDF מהשרת: רשימת האיורים', pages.includes('רשימת איורים'), pages);
    t.ok('PDF מהשרת: המספרים וההפניה כטקסט', /טבלה\s+:1נתונים/.test(pages) && /איור\s+:1השנייה/.test(pages) && pages.includes('ראו איור 1'), pages);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
