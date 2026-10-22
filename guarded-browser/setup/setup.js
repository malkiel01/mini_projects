/**
 * הקמה מלאה מהמחשב (בעל המערכת, 2026-10-22): הדף מתחבר לטלפון ב-WebUSB (webadb.js — Tango), בודק מה חוסם,
 * מגדיר את מגדלור כ"בעל מכשיר" (dpm set-device-owner), ובסוף מכבה את ניפוי ה-USB.
 * בלי שרת: שום פרט מהטלפון לא נשלח לשום מקום. הלוגיקה הטהורה — logic.js (tests/setup.mjs).
 */
import { supported, connect } from './webadb.js';
import { PKG, ADMIN, parseAccounts, parseUsers, ownerState, explainSetOwner, blockers } from './logic.js';

const $ = id => document.getElementById(id);
const el = (tag, props = {}, kids = []) => {
    const n = Object.assign(document.createElement(tag), props);
    for (const k of [].concat(kids)) if (k != null) n.append(k);
    return n;
};
const log = (icon, text, sub = '') => {
    const li = el('li', {}, [el('span', { textContent: icon }), el('div', {}, [text, sub ? el('small', { textContent: sub }) : null])]);
    $('log').append(li);
    return li;
};
const result = (cls, icon, title, lines = [], raw = '') => $('result').replaceChildren(...[
    el('div', { className: `banner ${cls}` }, [el('span', { textContent: icon }),
        el('div', {}, [el('b', { textContent: title }), ...lines.map(t => el('div', { textContent: t }))])]),
    raw ? el('pre', { className: 'raw', textContent: raw }) : null].filter(Boolean));   // ‏null היה מוצג כטקסט

let phone = null;

/** בדיקה והקמה על טלפון מחובר. נקרא שוב (בלי להתחבר מחדש) אחרי שההורה תיקן משהו. */
async function run() {
    $('log').replaceChildren(); $('result').replaceChildren();
    const sh = async cmd => (await phone.run(cmd)).trim();

    const model = await sh('getprop ro.product.model');
    const sdk = Number(await sh('getprop ro.build.version.sdk')) || 0;
    log('📱', `${model || 'טלפון'} · אנדרואיד API ${sdk}`);

    const installed = (await sh(`pm path ${PKG}`)).startsWith('package:');
    log(installed ? '✅' : '❌', installed ? 'מגדלור מותקן' : 'מגדלור לא מותקן');

    const owner = ownerState(await sh('dumpsys device_policy'));
    if (owner.owner) { log('✅', 'מגדלור כבר "בעל המכשיר"'); return done(true); }

    const accounts = parseAccounts(await sh('dumpsys account'));
    log(accounts.length ? '❌' : '✅', accounts.length ? `${accounts.length} חשבונות בטלפון` : 'אין חשבונות בטלפון',
        accounts.map(a => `${a.label}: ${a.name}`).join(' · '));
    const users = parseUsers(await sh('pm list users'));
    const extra = users.filter(u => u.id !== 0);
    log(extra.length ? '❌' : '✅', extra.length ? 'יש משתמש נוסף בטלפון' : 'משתמש אחד בטלפון', extra.map(u => u.name).join(', '));

    const stop = blockers({ installed, accounts, users, owner });
    if (stop.length) {
        result('warn', '✋', 'עוד לא — צריך לתקן קודם בטלפון:', stop.map(b => `• ${b.title} — ${b.fix}`));
        $('result').append(el('button', { className: 'btn primary', type: 'button', textContent: '🔄 תיקנתי — לבדוק שוב', onclick: () => guard(run) }));
        return;
    }

    const wait = log('⏳', 'מגדיר את מגדלור כ"בעל המכשיר"…');
    const r = explainSetOwner(await sh(`dpm set-device-owner ${ADMIN}`));
    wait.remove();
    if (!r.ok) {
        log('❌', 'אנדרואיד סירב');
        result('bad', '❌', r.why, [r.fix], r.raw);
        $('result').append(el('button', { className: 'btn', type: 'button', textContent: '🔄 לנסות שוב', onclick: () => guard(run) }));
        return;
    }
    log('✅', 'מגדלור הוא "בעל המכשיר"');
    // ווידוא — לא סומכים על "Success" בלבד.
    if (!ownerState(await sh('dumpsys device_policy')).owner) {
        result('bad', '❌', 'אנדרואיד אמר "הצליח", אבל הבדיקה החוזרת לא מוצאת את מגדלור כבעל המכשיר', ['נסו שוב, או הקימו בקוד QR אחרי איפוס.']);
        return;
    }
    await done(false);
}

/** סיום: מגדלור נפתח ומחיל את ההגנות, וניפוי ה-USB נכבה (החיבור נקטע — זה צפוי). */
async function done(already) {
    // הכוונה של מסך הבית של המכשיר — עובדת גם כשהמסך הראשי של מגדלור אינו exported.
    try { await phone.run(`monkey -p ${PKG} -c android.intent.category.LAUNCHER 1`); } catch (e) { /* לא קריטי */ }
    const off = log('⏳', 'מכבה את ניפוי ה-USB והאפשרויות למפתחים…');
    try { await phone.run('settings put global development_settings_enabled 0'); } catch (e) { /* */ }
    try { await phone.run('settings put global adb_enabled 0'); } catch (e) { /* החיבור נסגר — צפוי */ }
    off.replaceWith(log('✅', 'ניפוי ה-USB כובה'));
    phone.close(); phone = null;
    result('', '🎉', already ? 'הטלפון כבר בהקמה מלאה' : 'הקמה מלאה הושלמה', [
        'מגדלור נפתח בטלפון ומחיל את ההגנות. כפתור "הסרה" של מגדלור — אפור.',
        'אפשר לנתק את הכבל.',
        'להחזרת חשבון גוגל לטלפון: באפליקציית ההורה ← מכשירים ← ⚙️ הגדרות המכשיר ← 👤 חשבונות ← מותר. מוסיפים את החשבון בטלפון, ואז חוסמים שוב.',
    ]);
}

/** שגיאות החיבור — בעברית. */
async function guard(fn) {
    $('go').disabled = true;
    try { await fn(); }
    catch (e) {
        const code = e && e.code;
        const msg = {
            'no-device': ['לא נבחר טלפון', 'לחצו שוב "חיבור לטלפון", ובחרו את הטלפון ברשימה. לא מופיע? ראו "לא מצליח?" למטה.'],
            busy: ['הטלפון תפוס בתוכנה אחרת במחשב', 'סגרו את Android Studio / adb / תוכנות היצרן, ונסו שוב.'],
            usb: ['החיבור לטלפון נכשל', 'נתקו וחברו את הכבל, ובדקו שניפוי USB דלוק בטלפון.'],
        }[code] || ['החיבור נקטע', 'בדקו שאישרתם בטלפון "לאפשר ניפוי באגים", נתקו וחברו את הכבל, ונסו שוב.'];
        result('bad', '⚠️', msg[0], [msg[1]], code ? '' : String(e && e.message || e));
        if (phone) { phone.close(); phone = null; }
    } finally { $('go').disabled = false; }
}

if (!supported()) { $('nousb').hidden = false; $('go').disabled = true; }

$('go').addEventListener('click', () => guard(async () => {
    $('progress').hidden = false;
    $('log').replaceChildren(); $('result').replaceChildren();
    const w = log('⏳', 'בוחרים את הטלפון ברשימה, ומאשרים בטלפון "לאפשר ניפוי באגים"');
    if (phone) { phone.close(); phone = null; }
    phone = await connect();
    w.remove();
    await run();
}));
