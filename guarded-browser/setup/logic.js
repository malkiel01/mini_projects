/**
 * הקמה מלאה דרך מחשב — הלוגיקה הטהורה (בלי USB), נבדקת ב-node (tests/setup.mjs).
 *
 * מה שהטלפון מחזיר לפקודות ה-shell, ומה אומרים להורה בעברית. השמות שרואים כאן (חשבונות, משתמשים) לא נשלחים
 * לשום מקום — הם מוצגים רק בדף, כדי שההורה יידע מה עוד להסיר.
 */

export const PKG = 'com.mbeplus.guardedbrowser';
export const ADMIN = `${PKG}/.MigdalorAdmin`;

/** שמות ידידותיים לסוגי החשבונות הנפוצים. */
const ACCOUNT_TYPES = {
    'com.google': 'גוגל',
    'com.osp.app.signin': 'סמסונג',
    'com.samsung.android.mobileservice': 'סמסונג',
    'com.xiaomi': 'שיאומי',
    'com.whatsapp': 'וואטסאפ',
    'com.whatsapp.w4b': 'וואטסאפ עסקי',
    'org.telegram.messenger': 'טלגרם',
    'com.microsoft.workaccount': 'מיקרוסופט',
    'com.facebook.auth.login': 'פייסבוק',
};

/** `dumpsys account` ← [{name, type, label}] — בלי כפילויות. */
export function parseAccounts(out) {
    const seen = new Set(), list = [];
    for (const m of String(out).matchAll(/Account \{name=([^,]*), type=([^}]*)\}/g)) {
        const key = m[1] + '|' + m[2];
        if (seen.has(key)) continue;
        seen.add(key);
        list.push({ name: m[1], type: m[2], label: ACCOUNT_TYPES[m[2]] || m[2] });
    }
    return list;
}

/** `pm list users` ← [{id, name}]. משתמש 0 הוא הראשי; כל השאר (מרחב שני, תיקייה מאובטחת, אורח) חוסמים. */
export function parseUsers(out) {
    return [...String(out).matchAll(/UserInfo\{(\d+):([^:}]*)/g)].map(m => ({ id: Number(m[1]), name: m[2] }));
}

/** `dumpsys device_policy` ← האם מגדלור כבר "בעל מכשיר"; או חבילה אחרת שהיא בעלים. */
export function ownerState(out) {
    const s = String(out);
    const block = s.match(/Device Owner:?\s*\{?[\s\S]{0,400}/i);
    if (!block) return { owner: false, other: '' };
    if (block[0].includes(PKG)) return { owner: true, other: '' };
    const pkg = block[0].match(/(?:package|admin)=?\s*(?:ComponentInfo\{)?([a-z][\w.]+)/i);
    return { owner: false, other: pkg ? pkg[1] : 'אפליקציה אחרת' };
}

/** הפלט של `dpm set-device-owner` ← {ok} או {ok:false, why, fix}. */
export function explainSetOwner(out) {
    const s = String(out);
    if (/^Success/m.test(s)) return { ok: true };
    const r = (why, fix) => ({ ok: false, why, fix, raw: s.trim().slice(0, 400) });
    if (/already some accounts|accounts on the device/i.test(s))
        return r('יש עדיין חשבונות בטלפון', 'הסירו את כל החשבונות (הגדרות ← חשבונות), ונסו שוב. אחרי ההקמה מחזירים אותם.');
    if (/several users|multiple users|already.*users/i.test(s))
        return r('יש בטלפון משתמש נוסף, "מרחב שני" או "תיקייה מאובטחת"', 'מחקו אותם בהגדרות הטלפון, ונסו שוב.');
    if (/device owner is already set|already has a device owner/i.test(s))
        return r('לטלפון כבר יש "בעל מכשיר" אחר', 'צריך להסיר אותו (או לאפס את הטלפון) לפני שמגדלור יכול להיות בעל המכשיר.');
    if (/already been provisioned|already provisioned/i.test(s))
        return r('הטלפון כבר הוגדר, ואנדרואיד בגרסה הזו לא מאפשר בעל מכשיר בדרך הזו', 'הדרך היחידה כאן: איפוס הטלפון והקמה בקוד QR מאפליקציית ההורה.');
    if (/Unknown admin|not.*installed|does not exist/i.test(s))
        return r('מגדלור לא מותקן בטלפון, או שהגרסה ישנה', 'התקינו או עדכנו את מגדלור, ונסו שוב.');
    if (/MANAGE_PROFILE_AND_DEVICE_OWNERS|not allowed to.*shell|USB debugging \(Security settings\)/i.test(s))
        return r('הטלפון חוסם את הפקודה (קורה בשיאומי)', 'באפשרויות המפתחים הפעילו גם "ניפוי USB (הגדרות אבטחה)", ונסו שוב.');
    return r('אנדרואיד סירב', 'הפרטים למטה. אפשר לנסות שוב, או להקים בקוד QR אחרי איפוס.');
}

/**
 * מה עוד חוסם, לפני שמנסים: [{key, title, fix}]. ריק — אפשר לנסות.
 * ‏installed — מגדלור מותקן; ‏accounts / users — מ-parseAccounts / parseUsers.
 */
export function blockers({ installed, accounts, users, owner }) {
    const out = [];
    if (owner.owner) return out;
    if (!installed) out.push({ key: 'install', title: 'מגדלור לא מותקן בטלפון', fix: 'מתקינים מגדלור ומצמדים אותו להורה, ואז חוזרים לכאן.' });
    if (owner.other) out.push({ key: 'other', title: `לטלפון כבר יש "בעל מכשיר": ${owner.other}`, fix: 'צריך להסיר אותו, או לאפס את הטלפון.' });
    if (accounts.length) out.push({ key: 'accounts', title: `${accounts.length} חשבונות בטלפון`,
        fix: 'בטלפון: הגדרות ← חשבונות — להסיר כל אחד: ' + accounts.map(a => `${a.label} (${a.name})`).join(' · ') });
    const extra = users.filter(u => u.id !== 0);
    if (extra.length) out.push({ key: 'users', title: 'יש בטלפון משתמש נוסף',
        fix: 'למחוק: ' + extra.map(u => u.name || `משתמש ${u.id}`).join(', ') + ' (מרחב שני / תיקייה מאובטחת / אורח).' });
    return out;
}

/* ── "הטלפון לא מופיע ברשימה" ─────────────────────────────────────
 * תקלה מדווחת (2026-10-22, Mac + כרום): ניפוי USB דלוק, וחלון הבחירה של הדפדפן "לא נמצאו מכשירים תואמים".
 * החלון מציג רק מכשירים שיש בהם ממשק ADB. בדיקה שנייה בלי סינון מבחינה בין "המחשב לא רואה את הטלפון
 * בכלל" (כבל / יציאה) לבין "רואה אותו, אבל בלי ממשק ADB" (מצב החיבור בטלפון, ניפוי USB).
 */

/** ממשק ADB ב-USB: מחלקה 255, תת-מחלקה 66, פרוטוקול 1 (כמו adb ו-Tango). */
export const ADB_IFACE = { classCode: 255, subclassCode: 66, protocolCode: 1 };

/** מכשיר WebUSB ← האם יש בו ממשק ADB, בתצורה כלשהי. */
export function hasAdbInterface(device) {
    for (const c of (device && device.configurations) || []) {
        for (const i of c.interfaces || []) {
            for (const a of i.alternates || []) {
                if (a.interfaceClass === ADB_IFACE.classCode && a.interfaceSubclass === ADB_IFACE.subclassCode
                    && a.interfaceProtocol === ADB_IFACE.protocolCode) return true;
            }
        }
    }
    return false;
}

/** מה אומרים להורה אחרי הבדיקה בלי סינון. ‏device — מה שנבחר (או null: לא נבחר / הרשימה ריקה). */
export function usbDiagnosis(device) {
    if (!device) return {
        kind: 'none', title: 'המחשב לא רואה את הטלפון בכלל',
        steps: [
            'כבל שמעביר נתונים — הרבה כבלים מעבירים רק טעינה. הכי טוב: הכבל שהגיע עם הטלפון.',
            'חיבור ישיר למחשב — בלי מפצל ובלי מתאם, וביציאה אחרת אם יש.',
            'ב-Mac: אם מופיעה שאלה "לאפשר לאביזר להתחבר?" — מאשרים.',
            'הטלפון פתוח (לא במסך נעילה) בזמן החיבור.',
        ],
    };
    const name = [device.manufacturerName, device.productName].filter(Boolean).join(' ') || 'הטלפון';
    if (!hasAdbInterface(device)) return {
        kind: 'no-adb', title: `המחשב רואה את ${name}, אבל ניפוי ה-USB לא פעיל בחיבור`,
        steps: [
            'בטלפון: מושכים את סרגל ההתראות ← "טעינת המכשיר דרך USB" (או "USB") ← בוחרים "העברת קבצים".',
            'מכבים ומדליקים שוב את "ניפוי באגים ב-USB" (הגדרות ← אפשרויות למפתחים), עם הכבל מחובר.',
            'אם זה לא עוזר: "ביטול הרשאות לניפוי ב-USB" באותו מסך, ומנתקים ומחברים את הכבל.',
            'כשזה עובד — בסרגל ההתראות של הטלפון מופיע "ניפוי באגים ב-USB מחובר".',
        ],
    };
    return { kind: 'adb', title: `${name} מוכן`, steps: ['לוחצים שוב "חיבור לטלפון" ובוחרים אותו ברשימה.'] };
}
