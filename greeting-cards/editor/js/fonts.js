// הגופנים — מ-ui-core (fonts.js, שלב 8; המקור היה הקטלוג והגשר של הסטודיו): הקטלוג, הטעינה, הקריאה מקובץ,
// "שלי" (userFonts) והחיבור לספריית הענן. הקבצים עצמם — editor/fonts/ui-core (עותק מסונכרן).
// כאן נשאר רק מה שתלוי במבנה העיצוב: ensureDocFonts ו-embedDocFonts (doc.fonts).

export * from './ui-core/fonts.js';
import { FONTS, ensureFont, registerFont, faceKey, nearestWeight, userFonts } from './ui-core/fonts.js';
import { assets } from './db.js';

// "שלי" — באותו מפתח כמו קודם (gc-my-fonts), כך שמה שהועלה במכשיר נשאר
const mine = userFonts({ key: 'gc-my-fonts', assets });
export const myFonts = () => mine.list();
export const loadMyFonts = () => mine.load();
export const addMyFont = (file, info) => mine.add(file, info);
export const removeMyFont = entry => mine.remove(entry);
export const syncCloudFonts = (drives, cloudId) => mine.syncCloud(drives, cloudId);
export const saveFontToCloud = (drives, cloudId, entry) => mine.saveToCloud(drives, cloudId, entry);

/** הגופנים שהעיצוב נושא (doc.fonts — הועלו, אולי במכשיר אחר) נרשמים, וכל גופן של טקסט נטען לפני הציור. */
export async function ensureDocFonts(doc) {
    for (const a of Object.values(doc.fonts || {})) {
        const blob = await assets.get(a.src);
        if (blob) await registerFont({ ...a, blob }).catch(() => {});
    }
    return Promise.all(doc.layers.filter(l => l.type === 'text').map(l => ensureFont(l.style.font, l.style.weight)));
}

/**
 * מטמיע בעיצוב את הגופנים שהועלו ומשמשים בו (doc.fonts), ומוריד את מה שכבר לא בשימוש. גופן שהגיע עם העיצוב
 * (ממכשיר אחר) — נשאר כמו שהוא, עם remote.
 */
export function embedDocFonts(doc) {
    const custom = new Set(FONTS.filter(f => f.custom).map(f => f.family));
    const used = new Set(doc.layers.filter(l => l.type === 'text' && custom.has(l.style.font))
        .map(l => faceKey({ family: l.style.font, weight: nearestWeight(l.style.font, l.style.weight), style: l.style.italic ? 'italic' : 'normal' })));
    const next = {};
    for (const [id, a] of Object.entries(doc.fonts || {})) if (used.has(faceKey(a))) { next[id] = a; used.delete(faceKey(a)); }
    for (const k of used) {
        const m = myFonts().find(x => faceKey(x) === k) || myFonts().find(x => faceKey({ ...x, style: 'normal' }) === k);
        if (!m) continue;
        next['f' + Math.random().toString(36).slice(2, 9)] = { family: m.family, weight: m.weight, style: m.style, src: m.src, ...(m.lib ? { lib: m.lib } : {}) };
    }
    if (Object.keys(next).length) doc.fonts = next; else delete doc.fonts;
}
