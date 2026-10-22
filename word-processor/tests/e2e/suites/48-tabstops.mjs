/**
 * עצירות טאב מותאמות ומובילים (R10 ב): לכל יישור (התחלה, סוף, מרכז, עשרוני) — המילה שאחרי הטאב במקום של העצירה,
 * נמדד מהשוליים בצד ההתחלה (ימין, גם בפסקה מוזחת), זהה במסך, בהדפסה וב-PDF מהשרת (נק' מקצה הדף); מוביל נקודות —
 * במסך (רקע) וב-PDF (ציור). חלון הפסקה: הוספת עצירה → נשמרת ב-attrs.tabs, גרסת מבנה 6.
 */
import { writeFileSync, readFileSync } from 'node:fs';
import { inflateSync } from 'node:zlib';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const P = (text, tabs, attrs = {}) => ({ type: 'paragraph', attrs: { tabs, ...attrs }, content: [{ type: 'text', text }] });
// A4, שוליים 25 מ"מ: הקצה הימני של אזור התוכן — 595.28 − 70.87 נק' מהקצה השמאלי של הדף
const LEFT = 25 * 72 / 25.4, RIGHT = 595.28 - LEFT;
const heb = s => s.split('').reverse().join('');

/** המילים של כל עמוד ב-PDF: [{t, x0, x1, y0}] בנקודות. */
function pdfWords(file) {
    const x = execFileSync('pdftotext', ['-bbox', file, '-'], { encoding: 'utf8' });
    return [...x.matchAll(/<page [^>]*>([\s\S]*?)<\/page>/g)].map(([, body]) =>
        [...body.matchAll(/xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)</g)].map(m => ({ t: m[5], x0: +m[1], y0: +m[2], x1: +m[3] })));
}

export default async function (t) {
    const p = await t.page();
    // 200 נק' = 70.56 מ"מ מימין. בכל שורה: מילה לפני הטאב, ומילה ייחודית אחריו
    const doc = await t.newDoc(p, 'עצירות טאב', { type: 'doc', content: [
        P('התחלה\tאחריהתחלה', [{ pos: 200, align: 'start' }]),
        P('סוף\t12345', [{ pos: 200, align: 'end', leader: 'dot' }]),
        P('מרכז\tבמרכזזזזז', [{ pos: 200, align: 'center' }]),
        P('עשרוני\t3.25', [{ pos: 200, align: 'decimal' }]),
        P('מוזחת\tאחרימוזחת', [{ pos: 200, align: 'start' }], { indent: 1 }),
        P('שתיים\tראשונה\tשנייה', [{ pos: 100 }, { pos: 300, align: 'end' }]),
        P('Left\tAfterLeft', [{ pos: 200 }], { dir: 'ltr' }),
    ] });
    await t.openDoc(p, doc);
    await t.settle(p);
    await p.waitForTimeout(300);
    // במסך: לכל מילה — הקצה הימני והשמאלי בנקודות מהקצה השמאלי של הדף, וגם מיקום הנקודה ב-"3.25"
    const screen = await p.evaluate(words => {
        const pg = document.querySelector('.wp-pages .wp-page').getBoundingClientRect();
        const z = pg.width / document.querySelector('.wp-pages .wp-page').offsetWidth;
        const pt = x => (x - pg.left) / z * 0.75;
        const out = {};
        const walk = document.createTreeWalker(document.querySelector('.wp-doc'), NodeFilter.SHOW_TEXT);
        for (let n = walk.nextNode(); n; n = walk.nextNode()) for (const w of words) {
            const i = n.data.indexOf(w);
            if (i < 0 || out[w]) continue;
            const r = document.createRange(); r.setStart(n, i); r.setEnd(n, i + w.length);
            const b = r.getBoundingClientRect();
            out[w] = { l: pt(b.left), r: pt(b.right) };
            if (w === '3.25') { const d = document.createRange(); d.setStart(n, i + 1); d.setEnd(n, i + 2); out.dot = pt(d.getBoundingClientRect().left); }
        }
        const tabs = [...document.querySelectorAll('.wp-doc .wp-tab')].map(s => ({ w: s.getBoundingClientRect().width / z, cls: s.className }));
        return { out, tabs };
    }, ['אחריהתחלה', '12345', 'במרכזזזזז', '3.25', 'אחרימוזחת', 'ראשונה', 'שנייה', 'AfterLeft']);
    const S = screen.out, stop = RIGHT - 200, near = (a, b, d = 2) => Math.abs(a - b) <= d;
    t.ok('במסך: יישור לתחילה — המילה מתחילה בעצירה (200 נקודות מהשוליים)', near(S['אחריהתחלה']?.r, stop), JSON.stringify(S['אחריהתחלה']));
    t.ok('במסך: יישור לסוף — המספר מסתיים בעצירה', near(S['12345']?.l, stop), JSON.stringify(S['12345']));
    t.ok('במסך: יישור למרכז — מרכז המילה בעצירה', near((S['במרכזזזזז']?.l + S['במרכזזזזז']?.r) / 2, stop), JSON.stringify(S['במרכזזזזז']));
    t.ok('במסך: עשרוני — הנקודה בעצירה', near(S.dot, stop, 2.5), `${S.dot} / ${stop}`);
    t.ok('במסך: פסקה מוזחת — העצירה נמדדת מהשוליים, לא מההזחה', near(S['אחרימוזחת']?.r, stop), JSON.stringify(S['אחרימוזחת']));
    t.ok('במסך: שתי עצירות — לתחילה ב-100, לסוף ב-300', near(S['ראשונה']?.r, RIGHT - 100) && near(S['שנייה']?.l, RIGHT - 300), JSON.stringify([S['ראשונה'], S['שנייה']]));
    t.ok('במסך: פסקה משמאל לימין — העצירה נמדדת מהשוליים השמאליים', near(S.AfterLeft?.l, LEFT + 200), JSON.stringify(S.AfterLeft));
    t.ok('במסך: מוביל נקודות — על הטאב של יישור לסוף', screen.tabs.some(x => /lead-dot/.test(x.cls) && x.w > 100), JSON.stringify(screen.tabs.map(x => [Math.round(x.w), x.cls])));

    // הדפסה ו-PDF מהשרת: אותם מקומות
    const printPdf = join(t.data, 'tabs.print.pdf'), serverPdf = join(t.data, 'tabs.pdf');
    writeFileSync(printPdf, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
    writeFileSync(serverPdf, await (await p.request.get(t.editor + `api.php?action=my_export&format=pdf&doc=${doc}`)).body());
    for (const [name, f] of [['הדפסה', printPdf], ['PDF מהשרת', serverPdf]]) {
        const ws = pdfWords(f)[0] || [];
        const at = w => ws.find(q => q.t === w || q.t === heb(w));
        const bad = [['אחריהתחלה', 'x1', stop], ['12345', 'x0', stop], ['אחרימוזחת', 'x1', stop], ['ראשונה', 'x1', RIGHT - 100], ['שנייה', 'x0', RIGHT - 300]]
            .filter(([w, k, x]) => !near(at(w)?.[k], x, 2)).map(([w, k, x]) => `${w}: ${at(w)?.[k]?.toFixed(1)} / ${x.toFixed(1)}`);
        const c = at('במרכזזזזז');
        if (!c || !near((c.x0 + c.x1) / 2, stop, 2)) bad.push(`מרכז: ${c && ((c.x0 + c.x1) / 2).toFixed(1)}`);
        // עשרוני: "3.25" — הנקודה (בערך 0.285 מרוחב המילה משמאל, בגופן הזה) בעצירה
        const dc = at('3.25');
        if (!dc || !near(dc.x0 + (dc.x1 - dc.x0) * 0.285, stop, 2.5)) bad.push(`עשרוני: ${dc && (dc.x0 + (dc.x1 - dc.x0) * 0.285).toFixed(1)}`);
        if (!near(at('AfterLeft')?.x0, LEFT + 200, 2)) bad.push(`משמאל לימין: ${at('AfterLeft')?.x0}`);
        t.ok(`${name}: כל מילה בעצירה שלה (כמו במסך)`, !bad.length, bad.join(' | '));
    }
    // המוביל ב-PDF מהשרת: נקודות מצוירות (מלבנים קטנים) בין "סוף" ל-12345
    const buf = readFileSync(serverPdf), bin = buf.toString('latin1');
    let raw = '';
    for (const m of bin.matchAll(/stream\r?\n/g)) {
        const end = bin.indexOf('endstream', m.index);
        try { raw += inflateSync(buf.subarray(m.index + m[0].length, end)).toString('latin1'); } catch { /* לא דחוס / לא תוכן */ }
    }
    t.ok('PDF מהשרת: מוביל נקודות מצויר', (raw.match(/ 0\.9 0\.9 re/g) || []).length > 30, String((raw.match(/ 0\.9 0\.9 re/g) || []).length));

    // חלון הפסקה: עצירה ב-5 ס"מ, יישור לסוף, מוביל נקודות → נשמרת
    await p.click('.wp-doc p:first-of-type');
    await t.tool(p, 'פסקה: ריווח, הזחה, כיוון');
    const d = p.locator('dialog[open]');
    await d.locator('.tab-row').first().locator('input[name=tab_pos]').fill('5');
    await d.locator('.tab-row').first().locator('select[name=tab_align]').selectOption('end');
    await d.locator('.tab-row').first().locator('select[name=tab_leader]').selectOption('dot');
    await d.locator('button', { hasText: '+ עצירה' }).click();
    await d.locator('.tab-row').nth(1).locator('input[name=tab_pos]').fill('2,5');
    await d.locator('button[type=submit]').click();
    await d.waitFor({ state: 'detached' }).catch(() => {});
    await p.waitForTimeout(2500);
    const s = await t.api(p, 'my_open', { doc });
    const tabs = s.json.content[0].attrs.tabs;
    t.ok('חלון הפסקה: שתי עצירות נשמרו — 2.5 ס"מ לתחילה, 5 ס"מ לסוף עם נקודות; גרסת מבנה 6',
        tabs?.length === 2 && Math.abs(tabs[0].pos - 70.9) < 0.2 && tabs[0].align === 'start' && Math.abs(tabs[1].pos - 141.7) < 0.2 && tabs[1].align === 'end' && tabs[1].leader === 'dot' && s.json.attrs.schema === 6,
        JSON.stringify(tabs));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
