<?php
/**
 * בדיקות לפירוק איש קשר ששותף מהטלפון
 * tests/vcard_test.php
 *
 * הרצה: php tests/vcard_test.php
 */

require_once __DIR__ . '/../includes/vcard.php';

$pass = 0;
$fail = 0;

function check($label, $condition, $detail = '') {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "\nvCard\n";

$v = parseVcard("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:דנה לוי\r\nN:לוי;דנה;;;\r\nTEL;TYPE=HOME:03-1234567\r\nTEL;TYPE=CELL:054-765-4321\r\nEMAIL;TYPE=INTERNET:Dana@Example.com\r\nEND:VCARD\r\n");
check('שם מ-FN', $v['name'] === 'דנה לוי', $v['name']);
check('מייל באותיות קטנות', $v['email'] === 'dana@example.com', $v['email']);
check('מעדיף נייד ישראלי על פני קווי', $v['phone'] === '054-765-4321', $v['phone']);

$qp = "BEGIN:VCARD\nVERSION:2.1\nN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:=D7=9B=D7=94=D7=9F;=D7=99=D7=95=D7=A1=D7=99;;;\nFN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:=D7=99=D7=95=D7=A1=D7=99 =D7=9B=\n=D7=94=D7=9F\nTEL;CELL:+972 52-111-2233\nEND:VCARD";
$v = parseVcard($qp);
check('QUOTED-PRINTABLE עם שורה שבורה', $v['name'] === 'יוסי כהן', $v['name']);
check('+972', $v['phone'] === '+972 52-111-2233', $v['phone']);

$v = parseVcard("BEGIN:VCARD\nVERSION:3.0\nN:מזרחי;רינה;;;\nitem1.TEL:0501112233\nEND:VCARD\nBEGIN:VCARD\nFN:אחר\nEND:VCARD");
check('בלי FN - שם מ-N (פרטי משפחה)', $v['name'] === 'רינה מזרחי', $v['name']);
check('item1.TEL', $v['phone'] === '0501112233', $v['phone']);
check('רק איש הקשר הראשון', $v['name'] !== 'אחר');
check('בלי מייל - ריק', $v['email'] === '');

$v = parseVcard("BEGIN:VCARD\nVERSION:4.0\nFN:Moshe\nTEL;VALUE=uri;TYPE=cell:tel:+1-555-0100\nEMAIL:not-an-email\nEND:VCARD");
check('tel: uri, מספר לא ישראלי - נלקח הראשון', $v['phone'] === '+1-555-0100', $v['phone']);
check('מייל לא תקין - לא נלקח', $v['email'] === '');

echo "\nטקסט חופשי\n";
$t = parseSharedText("אבי ישראלי\n052-333-4455\navi@example.com");
check('שם, טלפון ומייל מטקסט', $t['name'] === 'אבי ישראלי' && $t['phone'] === '052-333-4455' && $t['email'] === 'avi@example.com', json_encode($t, JSON_UNESCAPED_UNICODE));
$t = parseSharedText("BEGIN:VCARD\nFN:בתוך טקסט\nEND:VCARD");
check('vCard בתוך טקסט', $t['name'] === 'בתוך טקסט');

echo "\n" . str_repeat('=', 55) . "\n";
echo "עבר: $pass | נכשל: $fail\n";
exit($fail > 0 ? 1 : 0);
