// הקמה מלאה דרך מחשב (setup/logic.js) — מה הטלפון מחזיר, ומה אומרים להורה.
//   node guarded-browser/tests/setup.mjs
// הפלטים כאן — בצורה שאנדרואיד מדפיס (dumpsys / pm / dpm). במכשיר אמיתי — לא נבדק עדיין (docs/device-matrix.md).
import { parseAccounts, parseUsers, ownerState, explainSetOwner, blockers, PKG, hasAdbInterface, usbDiagnosis } from '../setup/logic.js';

let pass = 0, fail = 0;
const check = (name, got, want) => {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    ok ? pass++ : fail++;
    console.log(ok ? `  ok   ${name}` : `  FAIL ${name}\n       got:  ${JSON.stringify(got)}\n       want: ${JSON.stringify(want)}`);
};

console.log('\n— חשבונות —');
const dumpAccounts = `User UserInfo{0:Owner:c13}:
  Accounts: 3
    Account {name=dana@gmail.com, type=com.google}
    Account {name=dana@gmail.com, type=com.google}
    Account {name=WhatsApp, type=com.whatsapp}
    Account {name=d.cohen, type=com.osp.app.signin}
  AccountId, Action_Type, timestamp, UID, TableName, Key`;
const acc = parseAccounts(dumpAccounts);
check('שלושה חשבונות, בלי כפילות', acc.map(a => a.name), ['dana@gmail.com', 'WhatsApp', 'd.cohen']);
check('שמות ידידותיים לסוג', acc.map(a => a.label), ['גוגל', 'וואטסאפ', 'סמסונג']);
check('בלי חשבונות — ריק', parseAccounts('User UserInfo{0:Owner:c13}:\n  Accounts: 0\n'), []);

console.log('\n— "לא נמצאו מכשירים תואמים" —');
const usb = (...alts) => ({ manufacturerName: 'Unihertz', productName: 'Jelly', configurations: [{ interfaces: [{ alternates: alts }] }] });
const MTP = { interfaceClass: 6, interfaceSubclass: 1, interfaceProtocol: 1 };
const ADB = { interfaceClass: 255, interfaceSubclass: 66, interfaceProtocol: 1 };
check('יש ממשק ADB', hasAdbInterface(usb(MTP, ADB)), true);
check('רק העברת קבצים — אין ADB', hasAdbInterface(usb(MTP)), false);
check('מכשיר בלי תצורות', hasAdbInterface({}), false);
check('לא נבחר כלום — כבל / יציאה', usbDiagnosis(null).kind, 'none');
const noAdb = usbDiagnosis(usb(MTP));
check('נראה בלי ADB — מצב החיבור בטלפון, עם שם הטלפון', [noAdb.kind, noAdb.title.includes('Unihertz Jelly'), noAdb.steps[0].includes('העברת קבצים')], ['no-adb', true, true]);
check('נראה עם ADB — מוכן', usbDiagnosis(usb(ADB)).kind, 'adb');

console.log('\n— משתמשים —');
check('ראשי + מרחב שני', parseUsers('Users:\n\tUserInfo{0:Owner:c13} running\n\tUserInfo{10:Second space:1030} running\n'),
      [{ id: 0, name: 'Owner' }, { id: 10, name: 'Second space' }]);

console.log('\n— בעל מכשיר —');
check('אין', ownerState('Current Device Policy Manager state:\n  Immutable state:\n'), { owner: false, other: '' });
check('מגדלור כבר בעלים (אנדרואיד 10+)', ownerState(`  Device Owner:
    admin=ComponentInfo{${PKG}/${PKG}.MigdalorAdmin}
    name=
    package=${PKG}`).owner, true);
check('בעלים אחר — מזוהה בשם', ownerState(`Device Owner: {
    admin=ComponentInfo{com.mdm.agent/com.mdm.agent.Admin}
    package=com.mdm.agent
  }`), { owner: false, other: 'com.mdm.agent' });

console.log('\n— הפקודה —');
check('הצליח', explainSetOwner(`Success: Device owner set to package ComponentInfo{${PKG}/${PKG}.MigdalorAdmin}\nActive admin set to component {${PKG}/${PKG}.MigdalorAdmin}`).ok, true);
const accErr = explainSetOwner('java.lang.IllegalStateException: Not allowed to set the device owner because there are already some accounts on the device');
check('חשבונות — בעברית, עם מה לעשות', [accErr.ok, accErr.why, accErr.fix.includes('חשבונות')], [false, 'יש עדיין חשבונות בטלפון', true]);
check('משתמשים', explainSetOwner('Not allowed to set the device owner because there are already several users on the device').why.includes('משתמש נוסף'), true);
check('שיאומי — ניפוי USB (הגדרות אבטחה)', explainSetOwner('java.lang.SecurityException: Neither user 2000 nor current process has android.permission.MANAGE_PROFILE_AND_DEVICE_OWNERS.').fix.includes('הגדרות אבטחה'), true);
check('לא מוכר — לא "הצליח", ועם הפלט', (e => [e.ok, e.raw])(explainSetOwner('Error: something odd')), [false, 'Error: something odd']);

console.log('\n— מה חוסם לפני הניסיון —');
check('הכול נקי — כלום', blockers({ installed: true, accounts: [], users: [{ id: 0 }], owner: { owner: false, other: '' } }), []);
const b = blockers({ installed: false, accounts: acc, users: [{ id: 0 }, { id: 10, name: 'Second space' }], owner: { owner: false, other: '' } });
check('לא מותקן, חשבונות ומשתמש נוסף — שלושתם', b.map(x => x.key), ['install', 'accounts', 'users']);
check('...ושם כל חשבון להסרה', b[1].fix.includes('dana@gmail.com') && b[1].fix.includes('וואטסאפ'), true);
check('כבר בעלים — אין מה לחסום', blockers({ installed: true, accounts: acc, users: [], owner: { owner: true, other: '' } }), []);

console.log(`\n${pass} עברו, ${fail} נכשלו`);
process.exit(fail ? 1 : 0);
