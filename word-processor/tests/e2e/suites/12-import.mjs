/**
 * ייבוא Word (ליקוי ו' באפיון): מה שלא עבר בדיוק — בדוח שנפתח עם המסמך; מסמך שעבר כולו — הודעה קצרה בלבד.
 * אותו כפתור מייבא גם ODT (LibreOffice), RTF, HTML וטקסט — לפי התוכן.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

// docx קטן: טקסט, הערה (comment), גופן שאינו זמין, ומספר עמוד בכותרת התחתונה
const docx = (extra) => execFileSync('php', ['-r', `
    $W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $f = tempnam(sys_get_temp_dir(), 'wpdocx');
    $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
    $font = ${extra ? `'<w:rPr><w:rFonts w:ascii="Segoe UI" w:cs="Segoe UI"/></w:rPr>'` : `''`};
    $z->addFromString('word/document.xml', "<w:document $W><w:body><w:p><w:r>$font<w:t>שלום מ-Word</w:t></w:r></w:p>"
        . "<w:sectPr><w:footerReference w:type=\\"default\\" r:id=\\"rF\\"/><w:pgSz w:w=\\"11906\\" w:h=\\"16838\\"/></w:sectPr></w:body></w:document>");
    $z->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rF" Target="footer1.xml"/></Relationships>');
    $z->addFromString('word/footer1.xml', "<w:ftr $W><w:p><w:pPr><w:bidi/><w:jc w:val=\\"center\\"/></w:pPr><w:fldSimple w:instr=\\" PAGE \\"><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p></w:ftr>");
    ${extra ? `$z->addFromString('word/comments.xml', "<w:comments $W><w:comment w:id=\\"0\\"><w:p><w:r><w:t>הערה</w:t></w:r></w:p></w:comment></w:comments>");` : ''}
    $z->close(); echo file_get_contents($f); unlink($f);`]);

export default async function (t) {
    const p = await t.page();
    const upload = async (buffer, name, mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') => {
        await p.setInputFiles('input[type=file][accept*=docx]', { name, mimeType, buffer });
        await p.waitForSelector('.wp-doc');
    };

    await upload(docx(true), 'מ-Word.docx');
    await p.waitForSelector('dialog[aria-label="דוח ייבוא"]');
    const rows = await p.locator('dialog .import-report li').allTextContents();
    // הערה (Comments) בלי טווח במסמך — לא מיובאת, וגם לא בדוח (הערות עם טווח — מיובאות; tests/run.php)
    t.ok('דוח נפתח עם המסמך: גופן חסר', rows.length === 1 && rows.some(r => r.includes('Segoe UI') && r.includes('Heebo')), rows.join(' | '));
    t.ok('הטקסט עצמו יובא', (await p.locator('.wp-doc').textContent()).includes('שלום מ-Word'));
    await p.click('dialog button[type=submit]');
    // חיווי גופן מוחלף (ליקוי ח'): הטקסט ב-Segoe UI מוצג ב-Heebo, והשם המקורי נשמר
    await p.waitForSelector('dialog', { state: 'detached', timeout: 2000 }).catch(() => null);
    await p.click('.wp-doc p >> text=שלום');
    await p.waitForTimeout(200);
    t.ok('תיבת הגופן: "(מוחלף)"', (await p.locator('.wp-ribbon select.w-font').evaluate(s => s.selectedOptions[0]?.textContent)) === 'Segoe UI (מוחלף)');
    t.ok('שורת המצב: מה מוצג במקום', (await p.locator('.st-font').textContent()).includes('Segoe UI ← Heebo') && await p.locator('.st-font').isVisible());
    const ff = await p.locator('.wp-doc span[data-font="Segoe UI"]').evaluate(e => getComputedStyle(e).fontFamily);
    t.ok('מוצג ב-Heebo בלבד (כמו ב-PDF), השם המקורי ב-data-font', /^"?Heebo"?, sans-serif$/.test(ff), ff);
    await p.keyboard.press('Control+a');
    await p.selectOption('.wp-ribbon select.w-font', 'David');
    await p.click('.wp-doc p >> text=שלום');
    await p.waitForTimeout(200);
    t.ok('גופן Word זמין (David) — בלי חיווי, וההסבר: באיזה גופן חופשי', await p.locator('.st-font').isHidden()
        && (await p.getAttribute('.wp-ribbon select.w-font', 'title')).includes('David Libre')
        && await p.locator('.wp-ribbon select.w-font option.sub').count() === 0);
    await p.waitForSelector('dialog', { state: 'detached', timeout: 2000 }).catch(() => null);
    t.ok('"ייבוא" סוגר, והמסמך נשאר', await p.locator('dialog').count() === 0 && await p.locator('.wp-doc').count() === 1);
    await t.settle(p);
    const label = await p.locator('.wp-ftr .wp-num.al-center').first().textContent().catch(() => '');
    t.ok('מספר העמוד מהכותרת התחתונה של Word', label.trim() === '1', label);

    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    await upload(docx(false), 'נקי.docx');
    await p.waitForTimeout(300);
    t.ok('מסמך שעבר כולו — בלי דוח, רק הודעה', await p.locator('dialog').count() === 0
        && (await p.locator('.toast:not([hidden])').textContent()).includes('יובא במלואו'));

    // דוח עם הערות, ו"ביטול הייבוא" — המסמך נמחק לגמרי וחוזרים לרשימה (גם סגירה ב-✕ או Esc)
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    await upload(docx(true), 'לא לייבא.docx');
    await p.waitForSelector('dialog[aria-label="דוח ייבוא"]');
    const cancelled = await p.evaluate(() => location.hash.split('/').pop());
    t.ok('הדוח שואל: "לייבא בכל זאת?", עם "ייבוא" ו"ביטול הייבוא"', (await p.locator('dialog .import-report').textContent()).includes('לייבא בכל זאת?')
        && await p.locator('dialog button[type=submit]').textContent() === 'ייבוא' && await p.locator('dialog button:has-text("ביטול הייבוא")').count() === 1);
    await p.click('dialog button:has-text("ביטול הייבוא")');
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    await p.waitForSelector('.list li:not(:text-is("טוען…"))');
    t.ok('"ביטול הייבוא" — חזרה לרשימה, המסמך לא נוצר (גם לא בסל)', !(await p.locator('.list .card .l1 > span:first-child').allTextContents()).includes('לא לייבא')
        && (await t.api(p, 'my_open', { doc: cancelled }))?.code === 'not_found' && !JSON.stringify(await t.api(p, 'my_trash', {})).includes(cancelled));
    await upload(docx(true), 'סגירה.docx');
    await p.waitForSelector('dialog[aria-label="דוח ייבוא"]');
    const closed = await p.evaluate(() => location.hash.split('/').pop());
    await p.keyboard.press('Escape');
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    t.ok('סגירת הדוח בלי "ייבוא" (Esc) — גם היא מבטלת', (await t.api(p, 'my_open', { doc: closed }))?.code === 'not_found');

    // ODT מ-LibreOffice (tests/fixtures): כותרת, טבלה, תמונה; השם — בלי הסיומת (כבר ברשימה)
    await upload(readFileSync(new URL('../../fixtures/libreoffice.odt', import.meta.url)), 'מליברה.odt', 'application/vnd.oasis.opendocument.text');
    await t.settle(p);
    const odt = await p.evaluate(() => ({ h1: document.querySelector('.wp-doc h1')?.textContent, td: document.querySelectorAll('.wp-doc td').length,
        img: !!document.querySelector('.wp-doc img[src]'), doc: location.hash.split('/').pop() }));
    odt.title = (await t.api(p, 'my_open', { doc: odt.doc })).title;
    t.ok('ODT: נפתח עם הכותרת, הטבלה והתמונה, והשם בלי הסיומת', odt.h1 === 'כותרת ראשית' && odt.td === 3 && odt.img && odt.title === 'מליברה', JSON.stringify(odt));
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    // RTF מ-LibreOffice (tests/fixtures): כותרת, ומעקב השינויים — מי הוסיף ומי מחק
    await upload(readFileSync(new URL('../../fixtures/libreoffice-track.rtf', import.meta.url)), 'שינויים.rtf', 'application/rtf');
    await t.settle(p);
    const rtf = await p.evaluate(() => ({ h1: document.querySelector('.wp-doc h1')?.textContent, ins: document.querySelectorAll('.wp-doc ins.wp-ins').length,
        del: document.querySelectorAll('.wp-doc del.wp-del').length, who: document.querySelector('.wp-doc ins.wp-ins')?.dataset.author, doc: location.hash.split('/').pop() }));
    rtf.title = (await t.api(p, 'my_open', { doc: rtf.doc })).title;
    t.ok('RTF: נפתח עם הכותרת והשינויים במעקב (עם השם), והשם בלי הסיומת', rtf.h1 === 'מעקב שינויים' && rtf.ins >= 12 && rtf.del >= 12
        && ['יוסי כהן', 'דנה לוי', 'רונית'].includes(rtf.who) && rtf.title === 'שינויים', JSON.stringify(rtf));
    await p.click('button[aria-label="חזרה לרשימה"]');
    await p.waitForSelector('h1:text-is("עורך התמלילים")');
    await upload(Buffer.from('<html><body><h2>מדף אינטרנט</h2><p>פסקה <i>נטויה</i></p></body></html>'), 'דף.html', 'text/html');
    await t.settle(p);
    t.ok('HTML: נפתח עם הכותרת והעיצוב', (await p.locator('.wp-doc h2').textContent()) === 'מדף אינטרנט' && await p.locator('.wp-doc em').count() === 1);
    t.ok('בלי שגיאות JS', !p.errors.length, p.errors.join(' | '));
}
