/**
 * ‎.mbe (חבילת היצירה): ⋯ ← "הורדה כ-‎.mbe" — קובץ ZIP שה-mimetype שלו ראשון ולא דחוס, בשם המסמך; בתוכו המסמך כשטף,
 * התמונה הממוזערת וה-PDF וה-Word. ביומן הפעילות: "הורדה · .mbe".
 */
const T = text => ({ type: 'text', text });
const P = (...c) => ({ type: 'paragraph', content: c });

export default async function (t) {
    const p = await t.page();
    const doc = await t.newDoc(p, 'לחבילה', { type: 'doc', content: [{ type: 'heading', attrs: { level: 1 }, content: [T('כותרת לחבילה')] }, P(T('פסקה בעברית'))] });
    await t.openDoc(p, doc);
    await p.click('button[aria-label="עוד"]');
    const [dl] = await Promise.all([p.waitForEvent('download'), p.click('.menu-pop button:has-text("הורדה כ-‎.mbe")')]);
    const buf = Buffer.concat(await (await dl.createReadStream()).toArray());
    const head = await p.request.get(t.editor + `api.php?action=my_export&format=mbe&doc=${doc}`);
    const cd = head.headers()['content-disposition'] || '';
    const names = [];
    // מרכז הקבצים של ה-ZIP — שמות החלקים
    const eocd = buf.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
    let at = buf.readUInt32LE(eocd + 16);
    for (let i = 0, n = buf.readUInt16LE(eocd + 10); i < n; i++) {
        const nl = buf.readUInt16LE(at + 28), el = buf.readUInt16LE(at + 30), cl = buf.readUInt16LE(at + 32);
        names.push(buf.subarray(at + 46, at + 46 + nl).toString());
        at += 46 + nl + el + cl;
    }
    t.ok('"הורדה כ-‎.mbe" — קובץ בשם המסמך, mimetype ראשון בלי דחיסה', cd.endsWith("UTF-8''" + encodeURIComponent('לחבילה.mbe'))
        && head.headers()['content-type'] === 'application/vnd.mbe+zip' && buf.subarray(30, 61).toString() === 'mimetypeapplication/vnd.mbe+zip', cd);
    t.ok('בתוכו: המסמך, המניפסט, התמונה הממוזערת, PDF ו-Word', ['mimetype', 'manifest.json', 'document.json', 'renditions/preview.png',
        'renditions/document.pdf', 'renditions/document.docx', 'apps/word-processor/meta.json'].every(x => names.includes(x)), JSON.stringify(names));
    const act = await t.api(p, 'my_activity', { doc });
    t.ok('ביומן: "הורדה · .mbe"', JSON.stringify(act).includes('.mbe'), JSON.stringify(act).slice(0, 200));
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
