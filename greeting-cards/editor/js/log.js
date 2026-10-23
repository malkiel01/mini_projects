// יומן אבחון: כל נגיעה, צביטה, בחירה, שינוי גודל וזום — בזיכרון בלבד (טבעת של 5000 רשומות).
// נשלח לשרת רק כשהמשתמש לוחץ "🐞 שליחת יומן", ומקבל קוד קצר; לפי הקוד אפשר לקרוא אותו (editor/log.php).
// רשומה: [מילישניות מהפתיחה, סוג, נתונים]. מספרים מעוגלים, כדי שהיומן יישאר קטן וקריא.

const MAX = 5000;
const rows = [];
const t0 = performance.now();

// מספר קטן (זום, יחס) — שלוש ספרות אחרי הנקודה; מספר גדול (פיקסלים) — עשירית.
const round = v => (typeof v !== 'number' ? v : Math.abs(v) < 10 ? Math.round(v * 1000) / 1000 : Math.round(v * 10) / 10);

export function log(type, data = null) {
    if (data && typeof data === 'object') {
        for (const k of Object.keys(data)) data[k] = round(data[k]);
    }
    rows.push([Math.round(performance.now() - t0), type, data]);
    if (rows.length > MAX) rows.splice(0, rows.length - MAX);
}

/** הכול, עם פרטי המכשיר — למשלוח. */
export function logDump(app = {}) {
    return {
        v: 1, at: new Date().toISOString(), ua: navigator.userAgent,
        screen: { w: innerWidth, h: innerHeight, dpr: devicePixelRatio, touch: navigator.maxTouchPoints || 0 },
        app, rows: rows.slice(),
    };
}

window.addEventListener('error', e => log('error', { msg: String(e.message).slice(0, 300), src: (e.filename || '').split('/').pop(), line: e.lineno }));
window.addEventListener('unhandledrejection', e => log('error', { msg: String(e.reason?.message || e.reason).slice(0, 300) }));
