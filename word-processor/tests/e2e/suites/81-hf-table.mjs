/**
 * טבלה בכותרת (שלב 3, כמו בכותרת של Word — סמליל בצד אחד, פרטים בצד השני): בעורך הקטן של הכותרת — "⊞" מוסיף
 * טבלה של שני תאים בלי גבולות; טקסט בכל תא. בעמוד — הטבלה בכותרת, שני התאים זה לצד זה; נשמרת, וחוזרת לחלון;
 * ה-PDF מהשרת — שני הטקסטים בשוליים העליונים, משני הצדדים.
 */
import { writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'טבלה בכותרת', { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'גוף המסמך' }] }] });
    await t.openDoc(p, doc);
    await t.settle(p);
    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    const d = p.locator('dialog[open]');
    const hdr = d.locator('.hf-field').first();
    await hdr.locator('.hf-edit-doc').click();
    await hdr.locator('button[aria-label="טבלה בכותרת (שני תאים, בלי גבולות)"]').click();
    await hdr.locator('.hf-edit-doc td').first().waitFor();
    t.ok('בעורך הקטן: טבלה של שני תאים, בלי גבולות', await hdr.locator('.hf-edit-doc td').count() === 2 && await hdr.locator('.hf-edit-doc table[data-borders=none]').count() === 1);
    // הסמן בתא הראשון (מימין); Tab — לתא הבא
    await p.keyboard.type('RIGHTCELL');
    await p.keyboard.press('Tab');
    await p.keyboard.type('LEFTCELL');
    await d.locator('button[type=submit]').click();
    await d.waitFor({ state: 'detached' }).catch(() => {});
    await t.settle(p);
    const shown = await p.evaluate(() => {
        const tds = [...document.querySelectorAll('.wp-pages .wp-page .wp-hf-rich td')].map(td => ({ t: td.textContent, x: td.getBoundingClientRect().left, b: getComputedStyle(td).borderTopColor }));
        return { tds, page: document.querySelector('.wp-pages .wp-page').getBoundingClientRect().top, top: document.querySelector('.wp-pages .wp-page .wp-hf-rich')?.getBoundingClientRect().top };
    });
    const [r, l] = shown.tds;
    t.ok('בעמוד: הטבלה בכותרת — RIGHTCELL מימין, LEFTCELL משמאל, בלי גבולות', shown.tds.length === 2 && r.t === 'RIGHTCELL' && l.t === 'LEFTCELL' && r.x > l.x
        && /rgba\(0, 0, 0, 0\)|transparent/.test(r.b), JSON.stringify(shown));
    await p.waitForTimeout(2500);
    const s = await t.api(p, 'my_open', { doc });
    const h = s.json.attrs.page.header;
    t.ok('נשמר: כותרת עשירה עם טבלה (שני תאים)', Array.isArray(h) && h[0]?.type === 'table' && h[0].content[0].content.length === 2
        && JSON.stringify(h).includes('LEFTCELL'), JSON.stringify(h));

    // חוזרת לחלון
    await t.tool(p, 'הגדרות עמוד ומספור עמודים');
    const d2 = p.locator('dialog[open]');
    t.ok('בחלון: העורך הקטן מציג את הטבלה השמורה', await d2.locator('.hf-field').first().locator('.hf-edit-doc td').count() === 2
        && (await d2.locator('.hf-field').first().locator('.hf-edit-doc td').nth(1).textContent()) === 'LEFTCELL');
    await d2.locator('button[type=submit]').click();
    await d2.waitFor({ state: 'detached' }).catch(() => {});

    // PDF מהשרת: שני הטקסטים בשוליים העליונים, RIGHTCELL בחצי הימני ו-LEFTCELL בשמאלי
    const f = join(t.data, 'hft.pdf');
    writeFileSync(f, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
    const box = execFileSync('pdftotext', ['-bbox', f, '-'], { encoding: 'utf8' });
    const at = w => { const m = box.match(new RegExp(`xMin="([\\d.]+)" yMin="([\\d.]+)"[^>]*>${w}<`)); return m && { x: +m[1], y: +m[2] }; };
    const R = at('RIGHTCELL'), L = at('LEFTCELL');
    t.ok('PDF מהשרת: הטבלה בכותרת — בשוליים העליונים, התאים משני הצדדים', R && L && R.y < 70 && L.y < 70 && R.x > 297 && L.x < 297, JSON.stringify([R, L]));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
