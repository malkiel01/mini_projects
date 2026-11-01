// "פתיחה באפליקציה אחרת" וחזרה — פרוטוקול mbe-handoff גרסה 1 (greeting-cards/spec/13-open-in.md). שני הצדדים:
//   המקור — openIn: פותח את היעד בחלון חדש, מחכה ל-ready, שולח את ה-‎.mbe, ומקבל אותו בחזרה (return → returned).
//   היעד — acceptHandoff / activeHandoff / returnHandoff / endHandoff: נפתח ב-?mbe-handoff=<id>, מקבל, ומחזיר.
// הקובץ עצמו נפתח דרך הליבה (open), כמו קובץ מהמכשיר. רק דפדפן — אין צד שרת.
//
// אבטחה: היעד מקבל רק מ-window.opener, רק ממקור מוכר (היום — אותו דומיין: location.origin), ורק עם proto / v / id
// תואמים; המקור מקבל רק מהחלון שפתח ומהמקור של הכתובת שלו. שולחים תמיד עם targetOrigin מפורש — לעולם לא "*".
// בין דף הפתיחה לעורך של היעד (ניווט באותו חלון — opener נשאר) הפרטים עוברים ב-sessionStorage.

export const PROTO = 'mbe-handoff';
export const V = 1;
const KEY = 'mbe-handoff';
const READY_MS = 10000;
const ORIGINS = () => [location.origin];

// ---- היעד ----

const param = () => new URLSearchParams(location.search).get(PROTO);
const valid = (e, id) => ORIGINS().includes(e.origin) && e.source === window.opener && e.source !== null
    && e.data?.proto === PROTO && e.data?.v === V && e.data?.id === id;
const send = (msg, transfer) => window.opener.postMessage({ proto: PROTO, v: V, ...msg }, ORIGINS()[0], transfer);

/**
 * דף הפתיחה: אם נפתחנו להעברה — שולחים ready מיד (לפני כל דבר כבד), ומחכים ל-open.
 * onOpen({ id, name, file: File, from }) — כשהקובץ מגיע. defaultName — שם הקובץ כשהמקור לא שלח שם.
 * מחזיר true אם זו העברה.
 */
export function acceptHandoff(onOpen, { defaultName = 'מסמך.mbe' } = {}) {
    const id = param();
    if (!id || !window.opener) return false;
    const onMessage = e => {
        if (!valid(e, id) || e.data.type !== 'open' || !(e.data.file instanceof ArrayBuffer)) return;
        window.removeEventListener('message', onMessage);
        const from = { app: String(e.data.from?.app ?? ''), name: String(e.data.from?.name ?? '').slice(0, 60) || 'האפליקציה הקודמת' };
        const name = String(e.data.name || defaultName);
        sessionStorage.setItem(KEY, JSON.stringify({ id, from }));
        onOpen({ id, name, file: new File([e.data.file], /\.mbe$/i.test(name) ? name : name + '.mbe', { type: 'application/vnd.mbe+zip' }), from });
    };
    window.addEventListener('message', onMessage);
    send({ type: 'ready', id });
    return true;
}

/** העורך: ההעברה הפעילה (אם הגענו ממנה ועדיין יש opener) — { id, from } או null */
export function activeHandoff() {
    const id = param();
    if (!id || !window.opener) return null;
    try {
        const h = JSON.parse(sessionStorage.getItem(KEY) ?? 'null');
        return h && h.id === id ? h : null;
    } catch { return null; }
}

/**
 * מחזיר את הקובץ לאפליקציה שפתחה אותנו ומחכה לאישור (returned). bytes — ה-‎.mbe (נשלח עותק: המקור נשאר
 * אצלנו כבסיס לשמירה הבאה). מחזיר Promise שמתממש באישור, או נדחה ('timeout' / 'closed').
 */
export function returnHandoff(h, bytes, timeout = 10000) {
    if (!window.opener || window.opener.closed) return Promise.reject(new Error('closed'));
    return new Promise((resolve, reject) => {
        const done = (fn, v) => { clearTimeout(t); window.removeEventListener('message', onMessage); fn(v); };
        const onMessage = e => { if (valid(e, h.id) && e.data.type === 'returned') done(resolve); };
        const t = setTimeout(() => done(reject, new Error('timeout')), timeout);
        window.addEventListener('message', onMessage);
        const copy = bytes.slice().buffer;
        send({ type: 'return', id: h.id, file: copy }, [copy]);
    });
}

/** ההעברה הסתיימה — לא מציעים "חזרה" שוב (למשל אחרי טעינה מחדש) */
export const endHandoff = () => sessionStorage.removeItem(KEY);

// ---- המקור ----

const pending = new Map();   // id → { win, origin, onReturn, onReady, onEvent }
let listening = false;

function listen() {
    if (listening) return;
    listening = true;
    window.addEventListener('message', e => {
        const m = e.data;
        if (!m || m.proto !== PROTO || !pending.has(m.id)) return;
        const p = pending.get(m.id);
        if (e.origin !== p.origin || e.source !== p.win) return;   // רק מהחלון שפתחנו, ומהמקור שלו
        if (m.type === 'ready') p.onReady();
        else if (m.type === 'return' && m.file instanceof ArrayBuffer) {
            p.win.postMessage({ proto: PROTO, v: V, type: 'returned', id: m.id }, p.origin);
            p.onEvent('return', { bytes: m.file.byteLength });
            p.onReturn(new Uint8Array(m.file));
        } else if (m.type === 'cancel') {
            p.onEvent('cancel', {});
            pending.delete(m.id);
        }
    });
}

/**
 * פותח את היעד ושולח לו את הקובץ. חייב לרוץ בתוך הלחיצה עצמה (אחרת חוסם החלונות הקופצים עוצר) — לכן הבתים
 * מגיעים כהבטחה. url — כתובת היעד (יחסית לדף או מלאה); הפרמטר ?mbe-handoff=<id> נוסף כאן.
 * from — { app, name }: מי שולח (היעד מציג "חזרה ל<name>").
 * onReturn(bytes) — הקובץ חזר (אפשר יותר מפעם אחת); onFallback(why, err) — 'blocked' / 'no-answer' / 'build-failed':
 * להוריד את הקובץ במקום; onEvent(type, data) — לרישום ('open', 'sent', 'return', 'cancel'). מחזיר את ה-id, או null.
 */
export function openIn(url, filePromise, { from, onReturn, onFallback, onEvent = () => {} }) {
    const id = [...crypto.getRandomValues(new Uint32Array(3))].map(n => n.toString(36)).join('');
    const u = new URL(url, location.href);
    u.searchParams.set(PROTO, id);
    const win = window.open(u.href, '_blank');
    if (!win) { onFallback('blocked'); return null; }
    listen();
    onEvent('open', { url: u.pathname });
    const p = { win, origin: u.origin, onReturn, onEvent };
    pending.set(id, p);
    let sent = false;
    const timer = setTimeout(() => { if (!sent) { pending.delete(id); onFallback('no-answer'); } }, READY_MS);
    p.onReady = async () => {
        if (sent) return;
        sent = true;
        clearTimeout(timer);
        try {
            const { bytes, name } = await filePromise;
            const file = bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
            win.postMessage({ proto: PROTO, v: V, type: 'open', id, name, file, from: { app: String(from?.app ?? ''), name: String(from?.name ?? '') } }, p.origin, [file]);
            onEvent('sent', { bytes: bytes.byteLength });
        } catch (e) {
            pending.delete(id);
            onFallback('build-failed', e);
        }
    };
    return id;
}
