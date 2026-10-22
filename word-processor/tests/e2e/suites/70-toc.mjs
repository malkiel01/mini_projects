/** תוכן עניינים (שלב 4): הוספה, מספרי עמודים לפי העימוד, עדכון, לחיצה למעבר; נשמר; זהה ב-PDF מהשרת. */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });
const H = (level, text) => ({ type: 'heading', attrs: { level }, content: [{ type: 'text', text }] });
const BR = { type: 'pageBreak' };

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'תוכן עניינים', { type: 'doc', content: [P('פתיחה'), H(1, 'פרק א'), P('טקסט'), BR, H(1, 'פרק ב'), H(2, 'סעיף ב1'), BR, H(1, 'פרק ג'), H(4, 'עמוק מדי')] });
    await t.openDoc(p, doc);
    const E = fn => p.evaluate(fn);
    const toc = () => E(() => document.querySelector('.wp-doc').editor.getJSON().content.find(b => b.type === 'toc')?.attrs.entries.map(e => `${e.t}:${e.p}`).join('|'));
    await E(() => document.querySelector('.wp-doc').editor.chain().focus().setTextSelection(1).run());
    t.ok('"עדכון" לא זמין לפני שיש תוכן עניינים', await p.locator('.wp-ribbon [aria-label="עדכון תוכן עניינים"]').isDisabled());
    await t.tool(p, 'הוספת תוכן עניינים');
    await p.waitForTimeout(1200);
    t.ok('השורות: עד רמה 3, עם מספרי העמודים', (await toc()) === 'פרק א:1|פרק ב:2|סעיף ב1:2|פרק ג:3', await toc());
    t.ok('במסך: כותרת ושורות עם נקודות ומספר', await p.locator('.wp-doc .wp-toc .wp-toc-line').count() === 4 && (await p.locator('.wp-doc .wp-toc-title').textContent()) === 'תוכן עניינים'
        && (await p.locator('.wp-doc .wp-toc-line').nth(1).locator('.wp-toc-p').textContent()) === '2');

    // מעבר עמוד לפני "פרק א" — העמודים זזים; עדכון
    await E(() => { const e = document.querySelector('.wp-doc').editor; let at = 0; e.state.doc.descendants((n, pos) => { if (!at && n.type.name === 'heading') at = pos; }); e.chain().insertContentAt(at, { type: 'pageBreak' }).run(); });
    await p.waitForTimeout(500);
    t.ok('בלי עדכון — השורות לא משתנות (כמו ב-Word)', (await toc()) === 'פרק א:1|פרק ב:2|סעיף ב1:2|פרק ג:3');
    await t.tool(p, 'עדכון תוכן עניינים');
    await p.waitForTimeout(1200);
    t.ok('אחרי עדכון — פרק א בעמוד 2, השאר זזו', (await toc()) === 'פרק א:2|פרק ב:3|סעיף ב1:3|פרק ג:4', await toc());
    await p.keyboard.press('Control+z');
    await p.waitForTimeout(300);
    t.ok('ביטול אחד מחזיר את השורות הקודמות', (await toc()) === 'פרק א:1|פרק ב:2|סעיף ב1:2|פרק ג:3', await toc());
    await p.keyboard.press('Control+y');
    await p.waitForTimeout(300);

    // לחיצה על שורה — הסמן בכותרת
    await p.locator('.wp-doc .wp-toc-line').nth(3).click();
    await p.waitForTimeout(200);
    const at = await E(() => { const e = document.querySelector('.wp-doc').editor; return e.state.selection.$from.parent.textContent; });
    t.ok('לחיצה על "פרק ג" — הסמן עובר אליו', at === 'פרק ג', at);

    await p.keyboard.press('Control+s');
    await p.waitForTimeout(600);
    const s = await t.api(p, 'my_open', { doc });
    t.ok('נשמר', JSON.stringify(s.json).includes('"type":"toc"') && JSON.stringify(s.json).includes('"p":"4"'));
    const res = await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`);
    const f = join(t.data, 'toc.pdf');
    writeFileSync(f, await res.body());
    const pages = t.pdfPages(f);
    t.ok('PDF מהשרת: תוכן העניינים בעמוד הראשון, עם השורות', pages[0].includes('תוכן עניינים') && pages[0].includes('סעיף ב1') && pages.length === 4, String(pages.length));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
