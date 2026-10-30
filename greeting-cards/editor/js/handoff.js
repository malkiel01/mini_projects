// פתיחה באפליקציה אחרת של החבילה, וחזרה — פרוטוקול mbe-handoff (spec/13-open-in.md).
// הסטודיו פותח את האפליקציה בחלון חדש, מחכה ל-ready, שולח את ה-‎.mbe, ומקבל אותו בחזרה כשלוחצים שם "חזרה".
// אפליקציה שעוד לא מכירה את הפרוטוקול לא עונה — אז הקובץ יורד, ופותחים אותו שם מהמכשיר (עובד מאז מ1).

import { log } from './log.js';

export const PROTO = 'mbe-handoff';
const V = 1;
const READY_MS = 10000;

/** היעדים. הנתיב יחסי לעורך (greeting-cards/editor/); האפליקציה מנתבת לפי ‎?mbe-handoff=<id>. */
export const TARGETS = {
    'raster-studio': { name: 'עיצוב רסטר', in: 'בעיצוב רסטר', icon: '🎨', url: '../../raster-studio/', needsImage: true },
    'vector-studio': { name: 'וקטורי', in: 'בווקטורי', icon: '✒️', url: '../../vector-studio/' },
};

const pending = new Map();   // id → { win, origin, target, onReturn }
let listening = false;

function listen() {
    if (listening) return;
    listening = true;
    addEventListener('message', e => {
        const m = e.data;
        if (!m || m.proto !== PROTO || !pending.has(m.id)) return;
        const p = pending.get(m.id);
        if (e.origin !== p.origin || e.source !== p.win) return;   // רק מהחלון שפתחנו, ומהמקור שלו
        if (m.type === 'ready') p.onReady?.();
        else if (m.type === 'return' && m.file instanceof ArrayBuffer) {
            p.win.postMessage({ proto: PROTO, v: V, type: 'returned', id: m.id }, p.origin);
            log('handoff-return', { to: p.target, bytes: m.file.byteLength });
            p.onReturn(new Uint8Array(m.file));
        } else if (m.type === 'cancel') {
            log('handoff-cancel', { to: p.target });
            pending.delete(m.id);
        }
    });
}

/**
 * פותח את היעד. חייב לרוץ בתוך הלחיצה עצמה (אחרת חוסם החלונות הקופצים עוצר) — לכן הבתים מגיעים כהבטחה.
 * @param {string} target מפתח ב-TARGETS
 * @param {Promise<{bytes: Uint8Array, name: string}>} filePromise
 * @param {{onReturn: (bytes: Uint8Array) => void, onFallback: (why: string) => void}} cb
 */
export function openIn(target, filePromise, { onReturn, onFallback }) {
    const t = TARGETS[target];
    const id = crypto.getRandomValues(new Uint32Array(3)).reduce((s, n) => s + n.toString(36), '');
    const url = new URL(t.url, location.href);
    url.searchParams.set('mbe-handoff', id);
    const win = window.open(url.href, '_blank');
    if (!win) { onFallback('blocked'); return; }
    listen();
    log('handoff-open', { to: target });
    const p = { win, origin: url.origin, target, onReturn };
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
            win.postMessage({ proto: PROTO, v: V, type: 'open', id, name, file,
                from: { app: 'greeting-cards', name: 'סטודיו העיצוב' } }, p.origin, [file]);
            log('handoff-sent', { to: target, bytes: bytes.byteLength });
        } catch (e) {
            pending.delete(id);
            onFallback('build-failed', e);
        }
    };
}
