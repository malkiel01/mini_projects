/**
 * ייצוא ל-ODT (שלב 5): ⋯ ← "הורדה כ-ODT" — קובץ OpenDocument (mimetype ראשון), עם הכותרת, הטבלה והמעקב;
 * מייבאים אותו בחזרה מאותו כפתור ייבוא — והכול שם. ביומן הפעילות: "הורדה · ODT".
 */
const T = (text, marks) => ({ type: 'text', text, ...(marks ? { marks } : {}) });
const P = (...c) => ({ type: 'paragraph', content: c });
const C = (t, type = 'tableCell') => ({ type, content: [P(T(t))] });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'לייצוא', { type: 'doc', content: [
        { type: 'heading', attrs: { level: 1 }, content: [T('כותרת לייצוא')] },
        P(T('מודגש', [{ type: 'bold' }]), T(' ונוסף', [{ type: 'ins', attrs: { author: 'דנה לוי', date: '2026-10-24T10:00:00Z' } }])),
        { type: 'table', content: [{ type: 'tableRow', content: [C('א', 'tableHeader'), C('ב', 'tableHeader')] }, { type: 'tableRow', content: [C('1'), C('2')] }] },
    ] });
    await t.openDoc(p, doc);
    await p.click('button[aria-label="עוד"]');
    const [dl] = await Promise.all([p.waitForEvent('download'), p.click('.menu button:has-text("הורדה כ-ODT")')]);
    const buf = Buffer.from(await (await dl.createReadStream()).toArray().then(c => Buffer.concat(c)));
    // שם הקובץ — ב-filename* (UTF-8); Playwright לא מדווח אותו בהורדה מניווט, אז בודקים את הכותרת עצמה
    const head = await p.request.get(t.editor + `api.php?action=my_export&format=odt&doc=${doc}`);
    const cd = head.headers()['content-disposition'] || '';
    t.ok('"הורדה כ-ODT" — קובץ .odt בשם המסמך, ה-mimetype ראשון בלי דחיסה', cd.endsWith("UTF-8''" + encodeURIComponent('לייצוא.odt'))
        && head.headers()['content-type'] === 'application/vnd.oasis.opendocument.text'
        && buf.subarray(30, 38).toString() === 'mimetype' && buf.subarray(38, 77).toString() === 'application/vnd.oasis.opendocument.text', cd);

    // בחזרה דרך הייבוא
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    await p.setInputFiles('input[type=file][accept*=docx]', { name: 'חזרה.odt', mimeType: 'application/vnd.oasis.opendocument.text', buffer: buf });
    await p.waitForSelector('.wp-doc');
    await t.settle(p);
    const back = await p.evaluate(() => ({ h1: document.querySelector('.wp-doc h1')?.textContent, b: document.querySelector('.wp-doc strong')?.textContent,
        ins: document.querySelector('.wp-doc ins.wp-ins')?.dataset.author, th: document.querySelectorAll('.wp-doc th').length, td: document.querySelectorAll('.wp-doc td').length }));
    t.ok('הייבוא בחזרה: הכותרת, המודגש, ההוספה במעקב (עם השם), שורת הכותרת והתאים', back.h1 === 'כותרת לייצוא' && back.b === 'מודגש' && back.ins === 'דנה לוי'
        && back.th === 2 && back.td === 2, JSON.stringify(back));
    const act = await t.api(p, 'my_activity', { doc });
    t.ok('ביומן: "הורדה · ODT"', JSON.stringify(act).includes('ODT'), JSON.stringify(act).slice(0, 300));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
