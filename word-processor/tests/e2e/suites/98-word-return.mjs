/**
 * Word שחזר ("פנים", lib/face_import.php): הורדה כ-Word, שינוי כאן וגם ב-"Word", וייבוא של הקובץ שחזר — נכנס לאותו
 * מסמך (לא חדש). פסקה ששונתה רק ב-Word — נכנסת לבד; פסקה ששונתה בשני הצדדים — שאלה: מה שכאן / מ-Word / שתיהן.
 */
import { execFileSync } from 'node:child_process';

// "עריכה ב-Word": החלפת טקסט בתוך word/document.xml (הסימניות והמזהים נשארים — כמו ש-Word שומר)
const editDocx = (buf, repl) => execFileSync('php', ['-r', `
    $f = tempnam(sys_get_temp_dir(), 'wpface'); file_put_contents($f, stream_get_contents(STDIN));
    $z = new ZipArchive(); $z->open($f);
    $z->addFromString('word/document.xml', strtr($z->getFromName('word/document.xml'), json_decode($argv[1], true)));
    $z->close(); echo file_get_contents($f); unlink($f);`, JSON.stringify(repl)], { input: buf });

export default async function (t) {
    const p = await t.page();
    const { doc } = await t.api(p, 'my_new', { title: 'חזרה מוורד' });
    await t.openDoc(p, doc);
    const ed = fn => p.evaluate(fn);
    const texts = () => ed(() => document.querySelector('.wp-doc').editor.getJSON().content.map(b => (b.content || []).map(n => n.text || '').join('')));
    await ed(() => document.querySelector('.wp-doc').editor.commands.setContent({ type: 'doc', content: ['אחת', 'שתיים', 'שלוש']
        .map(x => ({ type: 'paragraph', content: [{ type: 'text', text: x }] })) }, true));
    await t.settle(p);

    // הורדה כ-Word — פנים: mbe-doc-id ב-docProps/custom.xml, וסימניית מזהה לכל פסקה
    const res = await p.request.get(t.editor + `api.php?action=my_export&format=docx&doc=${doc}`);
    const docx = Buffer.from(await res.body());
    const xml = execFileSync('php', ['-r', `$f = tempnam(sys_get_temp_dir(), 'wpface'); file_put_contents($f, stream_get_contents(STDIN));
        $z = new ZipArchive(); $z->open($f); echo (string)$z->getFromName('docProps/custom.xml'); unlink($f);`], { input: docx }).toString();
    t.ok('ההורדה כ-Word נושאת mbe-doc-id', /mbe-doc-id/.test(xml), xml.slice(0, 200));

    // כאן — משנים את הפסקה השלישית; ב-"Word" — את השנייה ואת השלישית
    await ed(() => { const e = document.querySelector('.wp-doc').editor; let at = 0; e.state.doc.descendants((n, pos) => { if (n.isText && n.text === 'שלוש') at = pos + n.text.length; }); e.chain().setTextSelection(at).insertContent(' כאן').run(); });
    await t.settle(p);
    const back = editDocx(docx, { '>שתיים<': '>שתיים בוורד<', '>שלוש<': '>שלוש בוורד<' });

    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('.list .card');
    const before = await p.locator('.list .card').count();
    await p.setInputFiles('input[type=file][accept*=docx]', { name: 'חזרה מוורד.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', buffer: back });
    await p.waitForSelector('dialog[aria-label="עדכון מ-Word — מה לשמור?"]');
    const sides = await p.locator('dialog .mq-side p').allTextContents();
    t.ok('התנגשות אחת — בפסקה השלישית: "כאן" מול "ב-Word"', await p.locator('dialog .mq').count() === 1 && sides[0] === 'שלוש כאן' && sides[1] === 'שלוש בוורד',
        JSON.stringify(sides));
    await p.click('dialog .mq label:has-text("מ-Word")');
    await p.click('dialog button[type=submit]');
    await p.waitForSelector('.wp-doc');
    await t.settle(p);
    const after = await texts();
    t.ok('אותו מסמך עודכן: השנייה — מ-Word (לבד), השלישית — לפי הבחירה', JSON.stringify(after) === JSON.stringify(['אחת', 'שתיים בוורד', 'שלוש בוורד']),
        JSON.stringify(after));
    t.ok('לא נוצר מסמך חדש', (await p.evaluate(() => location.hash)) === '#/d/' + doc);
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('.list .card');
    t.ok('ברשימה — אותו מספר מסמכים', await p.locator('.list .card').count() === before);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
    await p.context().close();
}
