// JSON קנוני לפי RFC 8785 (JCS): מפתחות ממוינים לפי יחידות UTF-16, בלי רווחים, מספרים בצורה הקצרה של
// ECMAScript, ו-Unicode בלי escape. כך הגיבוב של document.json זהה ב-JS וב-PHP (src/php/canonical.php).

export const MAX_DEPTH = 256;

export class CanonicalError extends Error {}

export function canonicalJson(value) {
    return write(value, 0);
}

function write(v, depth) {
    if (depth > MAX_DEPTH) throw new CanonicalError(`עומק JSON מעל ${MAX_DEPTH}`);
    if (v === null) return 'null';
    switch (typeof v) {
        case 'boolean': return v ? 'true' : 'false';
        case 'number':
            if (!Number.isFinite(v)) throw new CanonicalError('מספר לא סופי (NaN/Infinity) אינו JSON');
            return JSON.stringify(v); // ‎-0 → "0", ‏1e21 → "1e+21" — בדיוק כמו RFC 8785
        case 'string': return string(v);
        case 'object': {
            if (Array.isArray(v)) return '[' + v.map(x => write(x, depth + 1)).join(',') + ']';
            const keys = Object.keys(v).sort(); // המיון ברירת המחדל של JS הוא לפי יחידות UTF-16
            return '{' + keys.filter(k => v[k] !== undefined)
                .map(k => string(k) + ':' + write(v[k], depth + 1)).join(',') + '}';
        }
        default: throw new CanonicalError(`ערך מסוג ${typeof v} אינו JSON`);
    }
}

function string(s) {
    if (/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/.test(s)) {
        throw new CanonicalError('מחרוזת עם surrogate בודד אינה UTF-8 תקין');
    }
    return JSON.stringify(s);
}

// קריאת JSON מבתים: UTF-8 תקין בלבד, בלי BOM, עם תקרת עומק — זהה לכללים של PHP.
export function parseJson(bytes) {
    let text;
    try {
        text = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(bytes);
    } catch {
        throw new CanonicalError('הקובץ אינו UTF-8 תקין');
    }
    let value;
    try { value = JSON.parse(text); } catch (e) { throw new CanonicalError('JSON לא תקין: ' + e.message); }
    if (depthOf(value, 0) > MAX_DEPTH) throw new CanonicalError(`עומק JSON מעל ${MAX_DEPTH}`);
    return value;
}

function depthOf(v, d) {
    if (d > MAX_DEPTH || v === null || typeof v !== 'object') return d;
    let max = d;
    for (const x of Array.isArray(v) ? v : Object.values(v)) {
        max = Math.max(max, depthOf(x, d + 1));
        if (max > MAX_DEPTH) break;
    }
    return max;
}

export function utf8(s) {
    return new TextEncoder().encode(s);
}
