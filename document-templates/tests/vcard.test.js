// קובץ אנשי קשר מהטלפון: אנדרואיד (2.1, עברית ב-QUOTED-PRINTABLE) ואייפון (3.0).
// הרצה: node document-templates/tests/vcard.test.js
const V = require('../assets/js/vcard.js');
let fail = 0;
const eq = (name, got, want) => { const ok = JSON.stringify(got) === JSON.stringify(want); if (!ok) fail++; console.log((ok ? '✓ ' : '✗ ') + name + (ok ? '' : `\n  ${JSON.stringify(got)}\n  ≠ ${JSON.stringify(want)}`)); };

// אנדרואיד: "יאיר כהן" מקודד, שורה שבורה ב-"=", שני טלפונים — הנייד נבחר
const android = [
  'BEGIN:VCARD', 'VERSION:2.1',
  'N;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:=D7=9B=D7=94=D7=9F;=D7=99=D7=90=D7=99=D7=A8;;;',
  'FN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:=D7=99=D7=90=D7=99=D7=A8=20=',
  '=D7=9B=D7=94=D7=9F',
  'TEL;HOME:03-5555555', 'TEL;CELL:050-1234567', 'EMAIL;HOME:yair@example.com',
  'END:VCARD',
  'BEGIN:VCARD', 'VERSION:2.1', 'N:;;;;', 'TEL;CELL:+972 52-111-2222', 'END:VCARD',
].join('\r\n');
eq('אנדרואיד', V.parse(android), [
  { name: 'יאיר כהן', phone: '050-1234567', email: 'yair@example.com' },
  { name: '', phone: '+972 52-111-2222', email: '' },
]);

// אייפון: שבירת שורה ברווח, item1., בלי FN — השם מ-N
const iphone = '﻿BEGIN:VCARD\nVERSION:3.0\nN:לוי;רינה;;;\nitem1.TEL;type=CELL;type=VOICE;type=pref:054-77\n 7-7777\nitem2.EMAIL;type=INTERNET:rina@\n example.com\nNOTE:שורה\\, עם פסיק\nEND:VCARD\n';
eq('אייפון', V.parse(iphone), [{ name: 'רינה לוי', phone: '054-777-7777', email: 'rina@example.com' }]);
eq('קובץ ריק / לא קובץ אנשי קשר', V.parse('שלום'), []);
console.log(fail ? `\n${fail} נכשלו` : '\nהכול עבר');
process.exit(fail ? 1 : 0);
