// פונטים עבריים חופשיים (OFL), מקומיים בתיקיית fonts/. ה-@font-face ב-css/fonts.css, ולכן
// הדפדפן מוריד קובץ רק כשמשתמשים בפונט. לפני ציור בקנבס חייבים לחכות לטעינה — אחרת הקנבס
// מצייר בפונט חלופי ולא מתעדכן לבד.

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

/** כל הפונטים שבמסמך. */
export function ensureDocFonts(doc) {
    return Promise.all(doc.layers.filter(l => l.type === 'text').map(l => ensureFont(l.style.font, l.style.weight)));
}
