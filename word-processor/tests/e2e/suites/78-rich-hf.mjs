/**
 * כותרת עליונה / תחתונה עשירה (שלב 3): בחלון הגדרות העמוד — עורך קטן לכל כותרת (מודגש, יישור, סמליל). כותרת
 * מעוצבת נשמרת כפסקאות ומוצגת בכל עמוד בעיצוב שלה; כותרת בלי עיצוב — נשארת טקסט פשוט; PDF מהשרת — עם הכותרת.
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const P = text => ({ type: 'paragraph', content: [{ type: 'text', text }] });
// PNG תקין, 4×2 (אדום) — "סמליל"
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAAASFvFNAAAAEklEQVR4nGP8z8DAwMDAwMAAAAcAAf+v3tAAAAAASUVORK5CYII=', 'base64');

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'כותרת עשירה', { type: 'doc', content: [P('גוף המסמך')] }, { footer: 'תחתונה פשוטה' });
    await t.openDoc(p, doc);
    await t.settle(p);
    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    const d = p.locator('dialog[open]');
    const hdr = d.locator('.hf-field').first();
    await hdr.locator('.hf-edit-doc').click();
    await p.keyboard.type('חברה בעמ');
    await p.keyboard.press('Control+a');
    await hdr.locator('button[aria-label="מודגש"]').click();
    await hdr.locator('button[aria-label="יישור לשמאל"]').click();
    await p.keyboard.press('End');
    await hdr.locator('input[type=file]').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: PNG });
    await hdr.locator('.hf-edit-doc img[data-media]').waitFor();
    await d.locator('button[type=submit]').click();
    await d.waitFor({ state: 'detached' }).catch(() => {});
    await t.settle(p);
    const shown = await p.evaluate(() => {
        const r = document.querySelector('.wp-pages .wp-page .wp-hf-rich');
        return r && { bold: !!r.querySelector('strong, b'), text: r.textContent, img: !!r.querySelector('img[src]'), align: getComputedStyle(r.querySelector('p')).textAlign,
            footer: document.querySelector('.wp-pages .wp-page .wp-ftr .wp-hf-text')?.textContent };
    });
    t.ok('בעמוד: הכותרת בעיצוב שלה — מודגשת, לשמאל, עם הסמליל; התחתונה הפשוטה — כמו קודם', shown?.bold && shown.text.includes('חברה בעמ') && shown.img && shown.align === 'left'
        && shown.footer === 'תחתונה פשוטה', JSON.stringify(shown));
    await p.waitForTimeout(2500);
    const s = await t.api(p, 'my_open', { doc });
    const h = s.json.attrs.page.header;
    t.ok('נשמר: כותרת עשירה (פסקאות עם יישור, מודגש, תמונה); התחתונה — טקסט', Array.isArray(h) && h[0].attrs?.textAlign === 'left'
        && h[0].content.some(n => n.type === 'image' && n.attrs.media) && h[0].content.some(n => n.marks?.some(m => m.type === 'bold')) && s.json.attrs.page.footer === 'תחתונה פשוטה',
        JSON.stringify(h));
    const f = join(t.data, 'rich-hf.pdf');
    writeFileSync(f, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
    const txt = t.pdfPages(f)[0] || '';
    t.ok('PDF מהשרת: הכותרת העשירה והתחתונה', /חברה בעמ|מעב הרבח/.test(txt) && /תחתונה פשוטה|הטושפ הנותחת/.test(txt), txt.slice(0, 200));

    // פתיחה שנייה של החלון: העורך מציג את הכותרת; בלי שינוי — נשמרת כמו שהיא
    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    const d2 = p.locator('dialog[open]');
    t.ok('בחלון: העורך הקטן מציג את הכותרת השמורה (מודגש + תמונה)', await d2.locator('.hf-field').first().locator('.hf-edit-doc strong').count() > 0
        && await d2.locator('.hf-field').first().locator('.hf-edit-doc img[data-media]').count() === 1);
    await d2.locator('button[type=submit]').click();
    await d2.waitFor({ state: 'detached' }).catch(() => {});
    await p.waitForTimeout(2500);
    const s2 = await t.api(p, 'my_open', { doc });
    t.ok('אישור בלי שינוי — הכותרת לא השתנתה', JSON.stringify(s2.json.attrs.page.header) === JSON.stringify(h) && s2.json.attrs.page.footer === 'תחתונה פשוטה');
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
