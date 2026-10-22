/**
 * גבולות הדף בהדפסה (ביקורת 04, F16): בהדפסה השוליים התחתונים מוקטנים ברזרבה (עד 15 מ"מ — editor.js, slack) כדי
 * לבלוע הבדלי עיגול. הרזרבה לא אמורה להתמלא: כל מילה של הגוף ושל הערות השוליים — בתוך אזור התוכן של העמוד שלה,
 * כפי שהוא מוגדר בהגדרות העמוד (ולא באזור המוגדל), ובשוליים — רק מספר העמוד והכותרות.
 * בודקים את ה-PDF של ההדפסה עצמו (pdftotext -bbox): לכל מסמכי האוסף, ולמסמכי ההערות הארוכות.
 */
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const P = (text, ...more) => ({ type: 'paragraph', content: [{ type: 'text', text }, ...more] });
const FN = text => ({ type: 'footnote', attrs: { text } });
const long = n => Array.from({ length: n }, (_, i) => `שורה ${i + 1} של טקסט ארוך מספיק כדי להישבר לכמה שורות בעמוד, עם מספרים 2025 ו-English words.`).join(' ');
const note = n => Array.from({ length: n }, (_, i) => `מילה${i + 1}`).join(' ');

/** המילים של כל עמוד ב-PDF, עם מלבן בנקודות: [{w, h, words: [{t, x0, y0, x1, y1}]}]. */
function words(file) {
    const x = execFileSync('pdftotext', ['-bbox', file, '-'], { encoding: 'utf8' });
    return [...x.matchAll(/<page width="([\d.]+)" height="([\d.]+)">([\s\S]*?)<\/page>/g)].map(([, w, h, body]) => ({
        w: +w, h: +h,
        words: [...body.matchAll(/xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)</g)]
            .map(m => ({ x0: +m[1], y0: +m[2], x1: +m[3], y1: +m[4], t: m[5] })),
    }));
}

export default async function (t) {
    const p = await t.page();
    // אזור התוכן של כל עמוד, כחלק מהעמוד — מהעורך (הכותרת העליונה והתחתונה בגובה השוליים, הריפוד — השוליים בצדדים)
    const boxes = () => p.evaluate(() => [...document.querySelectorAll('.wp-pages .wp-page')].map(pg => {
        const W = pg.offsetWidth, H = pg.offsetHeight;
        const hdr = pg.querySelector('.wp-hdr'), ftr = pg.querySelector('.wp-ftr');
        const cs = getComputedStyle(hdr);
        return { top: hdr.offsetHeight / H, bottom: 1 - ftr.offsetHeight / H, left: parseFloat(cs.paddingLeft) / W, right: 1 - parseFloat(cs.paddingRight) / W };
    }));
    const check = async (doc, label) => {
        await t.settle(p);
        const bx = await boxes();
        const f = join(t.data, `print-bounds-${doc}.pdf`);
        writeFileSync(f, await p.pdf({ preferCSSPageSize: true, printBackground: true }));
        const pages = words(f);
        const bad = [];
        pages.forEach((pg, k) => {
            const b = bx[k];
            if (!b) { bad.push(`עמוד ${k + 1} בהדפסה בלי עמוד בעורך`); return; }
            const L = b.left * pg.w, R = b.right * pg.w, T = b.top * pg.h, B = b.bottom * pg.h;
            for (const w of pg.words) {
                const vt = (w.y1 - w.y0) / 4;   // המלבן של הגופן גבוה מהשורה — כמו בשער הגבולות במסך
                const inside = w.x0 >= L - 1.5 && w.x1 <= R + 1.5 && w.y0 >= T - vt && w.y1 <= B + vt;
                if (inside) continue;
                // בשוליים: מספר העמוד (ספרות / אותיות) — כולו מחוץ לאזור התוכן, לא חוצה אותו
                const inMargin = w.y0 >= B || w.y1 <= T;
                if (inMargin && /^[\dא-ת\-–]{1,6}$/.test(w.t)) continue;
                bad.push(`עמוד ${k + 1}: "${w.t}" ${[w.x0, w.y0, w.x1, w.y1].map(Math.round)} מול ${[L, T, R, B].map(Math.round)}`);
            }
        });
        t.ok(`הדפסה בתוך אזור התוכן: ${label}`, !bad.length && pages.length === bx.length, `${pages.length}/${bx.length} עמודים · ${bad.slice(0, 3).join(' | ')}`);
    };

    const dir = join(t.app, 'tests', 'corpus');
    for (const f of readdirSync(dir).filter(f => f.endsWith('.json')).sort()) {
        const c = JSON.parse(readFileSync(join(dir, f), 'utf8'));
        const doc = await t.newDoc(p, c.title, c.json, c.page);
        await t.openDoc(p, doc);
        await check(doc, c.title);
    }
    // הערות ארוכות מכמה עמודים (R08) — גם הן בתוך אזור התוכן בהדפסה
    const fn = await t.newDoc(p, 'הערות בהדפסה', { type: 'doc', content: [P(long(10)), P('סימן', FN(note(1800))), P(long(20))] });
    await t.openDoc(p, fn);
    await check(fn, 'הערה ארוכה מכמה עמודים');
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
