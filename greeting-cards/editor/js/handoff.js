// פתיחה באפליקציה אחרת של החבילה, וחזרה — פרוטוקול mbe-handoff (spec/13-open-in.md).
// הפרוטוקול עצמו בליבה (mbe/handoff.js — עותק מסונכרן, לא עורכים כאן). כאן נשארים רק היעדים של הסטודיו
// ועטיפה דקה: מי אנחנו (from), והרישום ביומן.

import { log } from './log.js';
import { openIn as coreOpenIn } from './mbe/handoff.js';

/** היעדים. הנתיב יחסי לעורך (greeting-cards/editor/); האפליקציה מנתבת לפי ‎?mbe-handoff=<id>. */
export const TARGETS = {
    'raster-studio': { name: 'עיצוב רסטר', in: 'בעיצוב רסטר', from: 'מעיצוב רסטר', icon: '🎨', url: '../../raster-studio/', needsImage: true },
    'vector-studio': { name: 'וקטורי', in: 'בווקטורי', from: 'מווקטורי', icon: '✒️', url: '../../vector-studio/' },
};

const FROM = { app: 'greeting-cards', name: 'סטודיו העיצוב' };

/**
 * פותח את היעד. חייב לרוץ בתוך הלחיצה עצמה (אחרת חוסם החלונות הקופצים עוצר) — לכן הבתים מגיעים כהבטחה.
 * @param {string} target מפתח ב-TARGETS
 * @param {Promise<{bytes: Uint8Array, name: string}>} filePromise
 * @param {{onReturn: (bytes: Uint8Array) => void, onFallback: (why: string) => void}} cb
 */
export function openIn(target, filePromise, { onReturn, onFallback }) {
    return coreOpenIn(TARGETS[target].url, filePromise, {
        from: FROM, onReturn, onFallback,
        onEvent: (type, data) => log('handoff-' + type, { to: target, ...data }),
    });
}
