// פונטים עבריים חופשיים (OFL), מקומיים בתיקיית fonts/. ה-@font-face ב-css/fonts.css, ולכן
// הדפדפן מוריד קובץ רק כשמשתמשים בפונט. לפני ציור בקנבס חייבים לחכות לטעינה — אחרת הקנבס
// מצייר בפונט חלופי ולא מתעדכן לבד.

import { assets } from './db.js';

export const FONTS = [
    { family: 'Heebo', weights: [100, 200, 300, 400, 500, 600, 700, 800, 900] },
    { family: 'Assistant', weights: [200, 300, 400, 500, 600, 700, 800] },
    { family: 'Rubik', weights: [300, 400, 500, 600, 700, 800, 900] },
    { family: 'Secular One', weights: [400] },
    { family: 'Frank Ruhl Libre', weights: [300, 400, 500, 600, 700, 800, 900] },
    { family: 'Suez One', weights: [400] },
    { family: 'Varela Round', weights: [400] },
    { family: 'Alef', weights: [400, 700] },
    { family: 'Karantina', weights: [400, 700] },
    { family: 'Amatic SC', weights: [400, 700] },
];

export const DEFAULT_FONT = 'Heebo';

/** המשקל הקרוב ביותר שיש לפונט — כדי שלא יצויר "מודגש מזויף". */
export function nearestWeight(family, weight) {
    const f = FONTS.find(x => x.family === family);
    if (!f) return 400;
    return f.weights.reduce((a, b) => (Math.abs(b - weight) < Math.abs(a - weight) ? b : a));
}

const loaded = new Map();

/** טוען פונט ומשקל (עברית ולטינית). מחזיר הבטחה שנפתרת גם בכישלון — אז מצויר הפונט החלופי. */
export function ensureFont(family, weight = 400) {
    const w = nearestWeight(family, weight);
    const key = family + ':' + w;
    if (!loaded.has(key)) {
        const spec = `${w} 32px "${family}"`;
        loaded.set(key, Promise.all([document.fonts.load(spec, 'אב'), document.fonts.load(spec, 'Ab')]).catch(() => {}));
    }
    return loaded.get(key);
}

/** כל הפונטים שבמסמך — קודם הגופנים שהועלו ומוטמעים בו (מקובץ, ממכשיר אחר), ואז הטעינה. */
export async function ensureDocFonts(doc) {
    for (const a of Object.values(doc.fonts || {})) {
        if (registered.has(faceKey(a))) continue;
        const blob = await assets.get(a.src);
        if (blob) await registerFont({ ...a, blob }).catch(() => {});
    }
    return Promise.all(doc.layers.filter(l => l.type === 'text').map(l => ensureFont(l.style.font, l.style.weight)));
}

// ─── גופנים שהמשתמש העלה ("שלי") ──────────────────────────────────────────
// גשר עד ספריית הגופנים של ענן החבילה (creative-suite/docs/spec/shared.md סעיף 4): הקובץ ב-IndexedDB, הרשימה במכשיר,
// וכל גופן שמשמש טקסט מוטמע בעיצוב עצמו (doc.fonts) — כך העיצוב נפתח נכון גם במכשיר אחר, ב-‎.gcard וב-‎.mbe.

const MY_KEY = 'gc-my-fonts';      // [{ family, weight, style, src }]
const registered = new Set();
const faceKey = a => `${a.family}|${a.weight || 400}|${a.style || 'normal'}`;
export const FONT_MIME = { woff2: 'font/woff2', woff: 'font/woff', ttf: 'font/ttf', otf: 'font/otf' };

export function myFonts() {
    try { return JSON.parse(localStorage.getItem(MY_KEY)) || []; } catch { return []; }
}
function saveMyFonts(list) {
    try { localStorage.setItem(MY_KEY, JSON.stringify(list)); } catch { /* אין אחסון — נשאר לשיחה הזו */ }
}

/** גופן (Blob) → FontFace בדף, ושורה ברשימת הפונטים של העורך. */
export async function registerFont({ family, weight = 400, style = 'normal', blob }) {
    const k = faceKey({ family, weight, style });
    if (registered.has(k)) return;
    const face = new FontFace(family, await blob.arrayBuffer(), { weight: String(weight), style });
    await face.load();   // קובץ פגום — נזרק כאן, לפני שהוא נכנס לרשימה
    document.fonts.add(face);
    registered.add(k);
    let f = FONTS.find(x => x.family === family);
    if (!f) FONTS.push(f = { family, weights: [], custom: true });
    if (!f.weights.includes(weight)) { f.weights.push(weight); f.weights.sort((a, b) => a - b); }
    loaded.delete(family + ':' + weight);
}

/** בהפעלת העורך: כל הגופנים שהועלו במכשיר הזה. */
export async function loadMyFonts() {
    for (const m of myFonts()) {
        const blob = await assets.get(m.src);
        if (blob) await registerFont({ ...m, blob }).catch(() => {});
    }
}

const WEIGHT_WORDS = [[/hairline|thin/i, 100], [/extra-?light|ultra-?light/i, 200], [/light/i, 300], [/medium/i, 500],
    [/semi-?bold|demi-?bold/i, 600], [/extra-?bold|ultra-?bold|heavy/i, 800], [/black/i, 900], [/bold/i, 700]];

/** קובץ גופן → { family, weight, style }: מטבלאות name ו-OS/2 (TTF, OTF, WOFF), ואם אין — משם הקובץ. */
export async function readFontInfo(file) {
    const bytes = new Uint8Array(await file.arrayBuffer());
    const base = file.name.replace(/\.[^.]+$/, '');
    let info = null;
    try { info = await sfntInfo(bytes); } catch { /* WOFF2 או קובץ לא רגיל — לפי השם */ }
    const byName = WEIGHT_WORDS.find(([re]) => re.test(base))?.[1] || 400;
    const family = (info?.family || base.replace(/[-_ ]*(hairline|thin|extra-?light|ultra-?light|light|regular|book|normal|medium|semi-?bold|demi-?bold|extra-?bold|ultra-?bold|heavy|black|bold|italic|oblique)*$/gi, '').replace(/[-_]+/g, ' ')).trim().slice(0, 60) || 'הגופן שלי';
    return { family, weight: info?.weight || byName, style: info?.italic || /italic|oblique/i.test(base) ? 'italic' : 'normal', bytes };
}

async function sfntInfo(b) {
    const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    const tag = String.fromCharCode(...b.subarray(0, 4));
    const tables = {};
    if (tag === 'wOFF') {
        const n = dv.getUint16(12);
        for (let i = 0; i < n; i++) {
            const o = 44 + i * 20, t = String.fromCharCode(...b.subarray(o, o + 4));
            if (t !== 'name' && t !== 'OS/2') continue;
            const off = dv.getUint32(o + 4), comp = dv.getUint32(o + 8), orig = dv.getUint32(o + 12);
            let data = b.subarray(off, off + comp);
            if (comp < orig) data = new Uint8Array(await new Response(new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate'))).arrayBuffer());
            tables[t] = data;
        }
    } else if (tag === 'OTTO' || tag === 'true' || dv.getUint32(0) === 0x10000) {
        const n = dv.getUint16(4);
        for (let i = 0; i < n; i++) {
            const o = 12 + i * 16, t = String.fromCharCode(...b.subarray(o, o + 4));
            if (t === 'name' || t === 'OS/2') tables[t] = b.subarray(dv.getUint32(o + 8), dv.getUint32(o + 8) + dv.getUint32(o + 12));
        }
    } else return null;
    const out = {};
    if (tables['OS/2']?.length >= 64) {
        const os = new DataView(tables['OS/2'].buffer, tables['OS/2'].byteOffset, tables['OS/2'].byteLength);
        const w = os.getUint16(4);
        if (w >= 100 && w <= 900) out.weight = Math.round(w / 100) * 100;
        out.italic = !!(os.getUint16(62) & 1);
    }
    const nm = tables.name;
    if (nm) {
        const ndv = new DataView(nm.buffer, nm.byteOffset, nm.byteLength);
        const count = ndv.getUint16(2), strOff = ndv.getUint16(4);
        let best = null, score = -1;
        for (let i = 0; i < count; i++) {
            const r = 6 + i * 12;
            const plat = ndv.getUint16(r), lang = ndv.getUint16(r + 4), id = ndv.getUint16(r + 6), len = ndv.getUint16(r + 8), off = ndv.getUint16(r + 10);
            if (id !== 1 && id !== 16) continue;
            const raw = nm.subarray(strOff + off, strOff + off + len);
            const text = plat === 3 || plat === 0 ? new TextDecoder('utf-16be').decode(raw) : String.fromCharCode(...raw);
            // שם המשפחה הטיפוגרפי (16) לפני הרגיל (1); עברית, ואז אנגלית, ואז כל שפה
            const sc = (id === 16 ? 4 : 0) + (lang === 0x040d ? 2 : lang === 0x0409 || lang === 0 ? 1 : 0) + (plat === 3 ? 0.5 : 0);
            if (text.trim() && sc > score) { score = sc; best = text.trim(); }
        }
        if (best) out.family = best;
    }
    return out;
}

/** העלאה: הקובץ נבדק (הדפדפן טוען אותו), נשמר במכשיר ונכנס לרשימה. מחזיר את השורה. */
export async function addMyFont(file, info) {
    const ext = (/\.([a-z0-9]+)$/i.exec(file.name)?.[1] || '').toLowerCase();
    const head = String.fromCharCode(...info.bytes.subarray(0, 4));
    const mime = head === 'wOF2' ? FONT_MIME.woff2 : head === 'wOFF' ? FONT_MIME.woff : head === 'OTTO' ? FONT_MIME.otf : FONT_MIME[ext] || FONT_MIME.ttf;
    const blob = new Blob([info.bytes], { type: mime });
    const entry = { family: info.family, weight: info.weight, style: info.style };
    await registerFont({ ...entry, blob });
    entry.src = await assets.put(blob);
    saveMyFonts([...myFonts().filter(m => faceKey(m) !== faceKey(entry)), entry]);
    return entry;
}

/** מוריד גופן מהרשימה "שלי". עיצובים שמשתמשים בו שומרים עותק משלהם (doc.fonts), ולכן ממשיכים להיראות אותו דבר. */
export function removeMyFont(entry) {
    saveMyFonts(myFonts().filter(m => faceKey(m) !== faceKey(entry)));
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
        next['f' + Math.random().toString(36).slice(2, 9)] = { family: m.family, weight: m.weight, style: m.style, src: m.src };
    }
    if (Object.keys(next).length) doc.fonts = next; else delete doc.fonts;
}
