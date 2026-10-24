// ZIP מהזיכרון — קורא וכותב, בלי לחלץ לדיסק. רק Deflate נעשה ב-fflate; המבנה (כותרות, מדריך, בדיקות) כאן,
// כדי שאותם כללים ואותם קודי בעיות יחולו גם ב-PHP (src/php/zip.php). ZIP64 — לא בגרסה 1: נדחה בהודעה ברורה.

import { deflateSync, Inflate } from './vendor/fflate.js';

export const LIMITS = {
    maxEntries: 10000,
    maxTotalBytes: 1024 * 1024 * 1024,   // תקרה קשיחה לגודל הפתוח של כל הקובץ
    softTotalBytes: 200 * 1024 * 1024,   // מעל זה — אזהרה (mbe-format.md סעיף 8)
    maxRatio: 100,                       // יחס דחיסה חריג = zip-bomb
    ratioFloor: 1024 * 1024,             // יחס נבדק רק לחלק שגדול מ-1MB פתוח
    maxNameBytes: 512,
};

const CRC_TABLE = (() => {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
        t[n] = c >>> 0;
    }
    return t;
})();

export function crc32(data) {
    let c = 0xFFFFFFFF;
    for (let i = 0; i < data.length; i++) c = CRC_TABLE[(c ^ data[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
}

// שם חלק בטוח: יחסי, בלי .., בלי \, בלי תווי בקרה, בלי אות כונן. מחזיר קוד בעיה או null.
export function nameProblem(name) {
    if (name === '' || name.startsWith('/') || name.includes('\\') || /^[A-Za-z]:/.test(name)) return 'zip-slip';
    if (name.split('/').some(seg => seg === '..' || seg === '.')) return 'zip-slip';
    if (/[\u0000-\u001f\u007f]/.test(name) || /\/\//.test(name)) return 'name-invalid';
    return null;
}

const u16 = (b, o) => b[o] | (b[o + 1] << 8);
const u32 = (b, o) => (b[o] | (b[o + 1] << 8) | (b[o + 2] << 16) | (b[o + 3] << 24)) >>> 0;

/**
 * קורא את המדריך המרכזי ובודק כל רשומה לפני שפותחים בית אחד.
 * מחזיר { entries, issues }; לכל רשומה read() → Uint8Array, או null עם בעיה ב-issues.
 */
export function readZip(bytes, limits = LIMITS) {
    const issues = [];
    const fail = (code, message, path) => { issues.push({ level: 'error', code, message, ...(path ? { path } : {}) }); return { entries: [], issues }; };
    if (bytes.length < 22 || u32(bytes, 0) !== 0x04034b50) return fail('not-zip', 'זה אינו קובץ ZIP');

    let eocd = -1;
    for (let i = bytes.length - 22; i >= Math.max(0, bytes.length - 22 - 65535); i--) {
        if (u32(bytes, i) === 0x06054b50) { eocd = i; break; }
    }
    if (eocd < 0) return fail('zip-invalid', 'סוף ה-ZIP (EOCD) לא נמצא — הקובץ קטוע או פגום');
    const disk = u16(bytes, eocd + 4), cdDisk = u16(bytes, eocd + 6);
    const count = u16(bytes, eocd + 10), cdSize = u32(bytes, eocd + 12), cdOffset = u32(bytes, eocd + 16);
    if ((eocd >= 20 && u32(bytes, eocd - 20) === 0x07064b50) || count === 0xFFFF || cdOffset === 0xFFFFFFFF) {
        return fail('zip64-unsupported', 'ZIP64 (מעל 4GB או מעל 65,535 קבצים) אינו נתמך בגרסה 1');
    }
    if (disk !== 0 || cdDisk !== 0 || u16(bytes, eocd + 8) !== count) return fail('zip-invalid', 'ZIP מפוצל לכמה דיסקים אינו נתמך');
    if (count > limits.maxEntries) return fail('too-many-entries', `יותר מ-${limits.maxEntries} קבצים בתוך ה-ZIP`);
    if (cdOffset + cdSize > eocd) return fail('zip-invalid', 'המדריך המרכזי חורג מגבולות הקובץ');

    const entries = [];
    const seen = new Set();
    let total = 0;
    let p = cdOffset;
    for (let i = 0; i < count; i++) {
        if (p + 46 > eocd || u32(bytes, p) !== 0x02014b50) return fail('zip-invalid', 'רשומה פגומה במדריך המרכזי');
        const flags = u16(bytes, p + 8), method = u16(bytes, p + 10), crc = u32(bytes, p + 16);
        const csize = u32(bytes, p + 20), usize = u32(bytes, p + 24);
        const nlen = u16(bytes, p + 28), xlen = u16(bytes, p + 30), clen = u16(bytes, p + 32);
        const local = u32(bytes, p + 42);
        if (p + 46 + nlen > eocd) return fail('zip-invalid', 'שם חורג מהמדריך המרכזי');
        const rawName = bytes.subarray(p + 46, p + 46 + nlen);
        p += 46 + nlen + xlen + clen;
        if (csize === 0xFFFFFFFF || usize === 0xFFFFFFFF || local === 0xFFFFFFFF) {
            return fail('zip64-unsupported', 'ZIP64 (מעל 4GB או מעל 65,535 קבצים) אינו נתמך בגרסה 1');
        }
        let name;
        try { name = new TextDecoder('utf-8', { fatal: true }).decode(rawName); } catch {
            return fail('name-invalid', 'שם קובץ בתוך ה-ZIP אינו UTF-8 תקין');
        }
        if (nlen > limits.maxNameBytes) return fail('name-invalid', 'שם ארוך מדי', name.slice(0, 80));
        const bad = nameProblem(name);
        if (bad) return fail(bad, bad === 'zip-slip' ? 'נתיב שיוצא מהקובץ (zip-slip) — נדחה' : 'שם קובץ עם תווים אסורים', name);
        if (seen.has(name)) return fail('duplicate-name', 'אותו שם מופיע פעמיים', name);
        seen.add(name);
        if (flags & 1) return fail('encrypted', 'ZIP מוצפן אינו נתמך', name);
        if (method !== 0 && method !== 8) return fail('compression-unsupported', `שיטת דחיסה ${method} אינה נתמכת`, name);
        if (method === 0 && csize !== usize) return fail('zip-invalid', 'חלק לא דחוס שגודלו לא תואם', name);
        total += usize;
        if (total > limits.maxTotalBytes) return fail('too-large', `הקובץ הפתוח גדול מ-${Math.round(limits.maxTotalBytes / 1048576)}MB`);
        if (usize > limits.ratioFloor && usize > csize * limits.maxRatio) return fail('zip-bomb', 'יחס דחיסה חריג (zip-bomb) — נדחה', name);
        if (local + 30 > bytes.length || u32(bytes, local) !== 0x04034b50) return fail('zip-invalid', 'כותרת מקומית חסרה', name);
        const lnlen = u16(bytes, local + 26), lxlen = u16(bytes, local + 28);
        const localName = bytes.subarray(local + 30, local + 30 + lnlen);
        if (lnlen !== nlen || localName.some((b, j) => b !== rawName[j])) return fail('zip-invalid', 'השם בכותרת המקומית שונה מהמדריך', name);
        const start = local + 30 + lnlen + lxlen;
        if (start + csize > cdOffset) return fail('zip-invalid', 'נתוני החלק חורגים מגבולות הקובץ', name);
        entries.push({ name, method, crc, csize, usize, offset: local, localExtra: lxlen, start, dir: name.endsWith('/') });
    }
    if (total > limits.softTotalBytes) {
        issues.push({ level: 'warning', code: 'size-soft-limit', message: `הקובץ הפתוח גדול מ-${Math.round(limits.softTotalBytes / 1048576)}MB` });
    }
    for (const e of entries) e.read = () => readEntry(bytes, e, issues);
    return { entries, issues };
}

function readEntry(bytes, e, issues) {
    const raw = bytes.subarray(e.start, e.start + e.csize);
    let out;
    if (e.method === 0) {
        out = raw.slice();
    } else {
        out = new Uint8Array(e.usize);
        let n = 0, over = false;
        const inf = new Inflate();
        inf.ondata = d => {
            if (n + d.length > e.usize) { over = true; throw new Error('over'); }
            out.set(d, n); n += d.length;
        };
        try {
            for (let i = 0; i < raw.length && !over; i += 4096) inf.push(raw.subarray(i, i + 4096), i + 4096 >= raw.length);
            if (raw.length === 0) inf.push(raw, true);
        } catch {
            issues.push({ level: 'error', code: over ? 'size-mismatch' : 'zip-invalid',
                message: over ? 'החלק גדול מהגודל שהוצהר — נדחה' : 'הדחיסה פגומה', path: e.name });
            return null;
        }
        if (n !== e.usize) {
            issues.push({ level: 'error', code: 'size-mismatch', message: 'החלק קטן מהגודל שהוצהר', path: e.name });
            return null;
        }
    }
    if (crc32(out) !== e.crc) {
        issues.push({ level: 'error', code: 'crc-mismatch', message: 'בדיקת CRC נכשלה — החלק פגום', path: e.name });
        return null;
    }
    return out;
}

/**
 * כותב ZIP: [{ name, data, store }] בסדר הנתון. store=true — בלי דחיסה (ל-mimetype). מחזיר Uint8Array.
 * החלק נשמר לא דחוס גם כשהדחיסה לא מקטינה אותו (PNG, woff2).
 */
export function writeZip(files, date = new Date()) {
    if (files.length > 0xFFFF) throw new Error('יותר מ-65,535 קבצים — ZIP64 אינו נתמך בגרסה 1');
    const enc = new TextEncoder();
    const time = ((date.getUTCHours() << 11) | (date.getUTCMinutes() << 5) | (date.getUTCSeconds() >> 1)) & 0xFFFF;
    const day = (((Math.max(1980, date.getUTCFullYear()) - 1980) << 9) | ((date.getUTCMonth() + 1) << 5) | date.getUTCDate()) & 0xFFFF;
    const parts = [], central = [];
    let offset = 0;
    for (const f of files) {
        const name = enc.encode(f.name);
        const crc = crc32(f.data);
        let method = 0, body = f.data;
        if (!f.store && f.data.length > 0) {
            const d = deflateSync(f.data, { level: 6 });
            if (d.length < f.data.length) { method = 8; body = d; }
        }
        if (body.length >= 0xFFFFFFFF || offset >= 0xFFFFFFFF) throw new Error('חלק גדול מ-4GB — ZIP64 אינו נתמך בגרסה 1');
        const utf = /[^\x20-\x7e]/.test(f.name) ? 0x0800 : 0;
        const head = new Uint8Array(30 + name.length);
        const h = new DataView(head.buffer);
        h.setUint32(0, 0x04034b50, true); h.setUint16(4, 20, true); h.setUint16(6, utf, true); h.setUint16(8, method, true);
        h.setUint16(10, time, true); h.setUint16(12, day, true); h.setUint32(14, crc, true);
        h.setUint32(18, body.length, true); h.setUint32(22, f.data.length, true); h.setUint16(26, name.length, true); h.setUint16(28, 0, true);
        head.set(name, 30);
        const cd = new Uint8Array(46 + name.length);
        const c = new DataView(cd.buffer);
        c.setUint32(0, 0x02014b50, true); c.setUint16(4, 20, true); c.setUint16(6, 20, true); c.setUint16(8, utf, true);
        c.setUint16(10, method, true); c.setUint16(12, time, true); c.setUint16(14, day, true); c.setUint32(16, crc, true);
        c.setUint32(20, body.length, true); c.setUint32(24, f.data.length, true); c.setUint16(28, name.length, true);
        c.setUint32(42, offset, true);
        cd.set(name, 46);
        parts.push(head, body);
        central.push(cd);
        offset += head.length + body.length;
    }
    const cdSize = central.reduce((s, x) => s + x.length, 0);
    const end = new Uint8Array(22);
    const e = new DataView(end.buffer);
    e.setUint32(0, 0x06054b50, true); e.setUint16(8, files.length, true); e.setUint16(10, files.length, true);
    e.setUint32(12, cdSize, true); e.setUint32(16, offset, true);
    const out = new Uint8Array(offset + cdSize + 22);
    let p = 0;
    for (const x of [...parts, ...central, end]) { out.set(x, p); p += x.length; }
    return out;
}
