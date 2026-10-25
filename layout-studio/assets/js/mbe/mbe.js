// ליבת ‎.mbe — קריאה, אימות וכתיבה בדפדפן (ES module, בלי בנייה). האפיון: creative-suite/docs/mbe-format.md.
// אותו API ואותם קודי בעיות ב-PHP (src/php/mbe.php). עורכים רק במקור: creative-suite/mbe-core/src.
//
//   const f = await open(bytes);           // לא זורק על קובץ פגום: f.ok, f.issues
//   f.document.surfaces[0].layers.push(…);
//   f.setRendition('renditions/preview.png', png, { type: 'image/png' });
//   const out = await save(f, { app: 'word-processor', appVersion: '2.3' });

import { canonicalJson, parseJson, utf8, CanonicalError } from './canonical.js';
import { validateSchema } from './schema.js';
import { readZip, writeZip, LIMITS } from './zip.js';
import { SCHEMAS } from './schemas.js';

export { canonicalJson, LIMITS };

export const FORMAT = 'mbe';
export const VERSION = '1.0';
export const CURRENT_MAJOR = 1;
export const MIMETYPE = 'application/vnd.mbe+zip';
export const PREVIEW = 'renditions/preview.png';
export const PREVIEW_MAX_PX = 1024;

const MEDIA_RE = /^media\/([0-9a-f]{64})(\.[a-z0-9]{1,8})?$/;
const RESERVED = new Set(['mimetype', 'manifest.json', 'document.json']);
const TYPES = {
    png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', svg: 'image/svg+xml',
    woff2: 'font/woff2', woff: 'font/woff', ttf: 'font/ttf', otf: 'font/otf', pdf: 'application/pdf',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ora: 'image/openraster',
    json: 'application/json', txt: 'text/plain',
};
const EXT = Object.fromEntries(Object.entries(TYPES).reverse().map(([e, t]) => [t, e]));

export class MbeError extends Error {
    constructor(message, issues = []) { super(message); this.issues = issues; }
}

/**
 * ממירים בין majors. המפתח — ה-major שממנו ממירים. היום יש רק major 1, ולכן הממיר 1→2 הוא שלד: כשיוגדר 2.0,
 * כאן נכתבת ההמרה, CURRENT_MAJOR עולה ל-2, וקבצי 1.x נפתחים דרכו.
 */
export const MIGRATIONS = {
    1: (document, manifest) => ({ document, manifest: { ...manifest, version: '2.0' } }),
};

export function migrate(document, manifest, toMajor = CURRENT_MAJOR) {
    let major = parseVersion(manifest.version)?.major;
    while (major < toMajor) {
        const step = MIGRATIONS[major];
        if (!step) throw new MbeError(`אין ממיר מגרסה ${major}`);
        ({ document, manifest } = step(document, manifest));
        major++;
    }
    return { document, manifest };
}

export function parseVersion(v) {
    const m = typeof v === 'string' && /^(\d+)\.(\d+)$/.exec(v);
    return m ? { major: +m[1], minor: +m[2] } : null;
}

export async function sha256(data) {
    const h = new Uint8Array(await crypto.subtle.digest('SHA-256', data));
    return Array.from(h, b => b.toString(16).padStart(2, '0')).join('');
}

export function typeForPath(path) {
    const ext = /\.([a-z0-9]+)$/i.exec(path)?.[1]?.toLowerCase();
    return TYPES[ext] || 'application/octet-stream';
}

export class MbeFile {
    constructor() {
        this.manifest = null;   // כולל שדות לא מוכרים — נשמרים
        this.document = null;   // המודל הקנוני; שדות לא מוכרים נשמרים
        this.files = new Map(); // כל שאר החלקים: media/, renditions/, apps/ ומה שלא מוכר — בית-לבית
        this.issues = [];
        this.fresh = new Map(); // ייצוגים שהאפליקציה סיפקה מאז הפתיחה → { type, gaps, by }
    }

    get ok() { return !this.issues.some(i => i.level === 'error'); }
    get kind() { return this.manifest?.kind ?? null; }
    get documentSha256() { const s = this.manifest?.document?.sha256; return typeof s === 'string' ? s : null; }

    media() {
        const listed = new Map((this.manifest?.media || []).map(m => [m.path, m]));
        return [...this.files.keys()].filter(p => p.startsWith('media/')).sort()
            .map(p => ({ ...listed.get(p), path: p, data: this.files.get(p) }));
    }

    getMedia(path) { return this.files.get(path) ?? null; }

    /** מוסיף משאב בשם הגיבוב שלו; אותו תוכן פעמיים — נשמר פעם אחת. מחזיר את הנתיב, לשימוש ב-src. */
    async addMedia(data, type) {
        const ext = EXT[type] || 'bin';
        const path = `media/${await sha256(data)}.${ext}`;
        this.files.set(path, data);
        return path;
    }

    renditions() {
        const listed = new Map((this.manifest?.renditions || []).map(r => [r.path, r]));
        return [...this.files.keys()].filter(p => p.startsWith('renditions/')).sort().map(p => {
            const r = { ...listed.get(p), path: p, data: this.files.get(p) };
            r.stale = !this.fresh.has(p) && r.sourceSha256 !== this.documentSha256;
            return r;
        });
    }

    /** האפליקציה מספקת bytes + gaps; בשמירה הליבה רושמת sourceSha256 של המסמך שנשמר. */
    setRendition(path, data, { type = typeForPath(path), gaps = [], by = null } = {}) {
        if (!/^renditions\/[^/]{1,128}$/.test(path)) throw new MbeError(`נתיב ייצוג לא חוקי: ${path}`);
        this.files.set(path, data);
        this.fresh.set(path, { type, gaps: [...gaps], by });
    }

    removeRendition(path) {
        this.files.delete(path);
        this.fresh.delete(path);
    }

    /** המידע הפרטי של אפליקציה: Map של שם יחסי → bytes. של אחרים — לא נוגעים. */
    apps(id) {
        const pre = `apps/${id}/`;
        return new Map([...this.files].filter(([p]) => p.startsWith(pre)).map(([p, d]) => [p.slice(pre.length), d]));
    }

    setApp(id, name, data) {
        if (!/^[a-z0-9][a-z0-9-]{0,63}$/.test(id)) throw new MbeError(`מזהה אפליקציה לא חוקי: ${id}`);
        this.files.set(`apps/${id}/${name}`, data);
    }
}

/** מסמך חדש. preview עדיין חסר — חובה לספק לפני save. */
export function create(kind, document = { surfaces: [] }) {
    const f = new MbeFile();
    f.manifest = { format: FORMAT, version: VERSION, kind, document: { path: 'document.json', sha256: '0'.repeat(64) } };
    f.document = document;
    return f;
}

/** פותח קובץ. לעולם לא זורק על תוכן פגום — הבעיות ב-f.issues, ומה שנקרא — זמין. */
export async function open(bytes, { limits = LIMITS } = {}) {
    const f = new MbeFile();
    const issue = (level, code, message, path) => f.issues.push({ level, code, message, ...(path ? { path } : {}) });
    if (!(bytes instanceof Uint8Array)) bytes = new Uint8Array(bytes);

    const zip = readZip(bytes, limits);
    if (!zip.entries.length) {
        f.issues.push(...zip.issues);
        if (f.ok) issue('error', 'not-mbe', 'ה-ZIP ריק');
        return f;
    }

    // mimetype — ראשון, לא דחוס, בלי שדה נוסף: כך מזהים ‎.mbe מהבתים הראשונים (כמו ODF).
    const first = zip.entries[0];
    const mt = zip.entries.find(e => e.name === 'mimetype');
    const raw = new Map();
    for (const e of zip.entries) {
        if (e.dir) continue;
        const data = e.read();
        if (data) raw.set(e.name, data);
    }
    f.issues.push(...zip.issues); // כולל בעיות שעלו בפתיחת החלקים (CRC, גודל)
    if (!mt) {
        issue('error', raw.has('manifest.json') ? 'mimetype-invalid' : 'not-mbe',
            raw.has('manifest.json') ? 'חסר הקובץ mimetype' : 'זה אינו קובץ ‎.mbe (אין mimetype ואין manifest.json)');
        if (!raw.has('manifest.json')) return f;
    } else {
        const text = raw.has('mimetype') ? new TextDecoder().decode(raw.get('mimetype')) : null;
        if (text !== null && text !== MIMETYPE) issue('error', 'mimetype-invalid', `mimetype הוא "${text.slice(0, 60)}" ולא ${MIMETYPE}`, 'mimetype');
        if (first !== mt) issue('warning', 'mimetype-not-first', 'mimetype אינו הקובץ הראשון — זיהוי לפי הבתים הראשונים לא יעבוד', 'mimetype');
        else if (mt.method !== 0) issue('warning', 'mimetype-compressed', 'mimetype דחוס — צריך להיות Stored', 'mimetype');
        else if (mt.localExtra !== 0) issue('warning', 'mimetype-extra', 'לכותרת של mimetype יש שדה נוסף — החתימה לא בהיסט 38', 'mimetype');
    }

    // manifest
    if (!raw.has('manifest.json')) { issue('error', 'manifest-missing', 'חסר manifest.json'); return f; }
    try { f.manifest = parseJson(raw.get('manifest.json')); } catch (e) {
        issue('error', /עומק/.test(e.message) ? 'json-too-deep' : 'manifest-invalid', 'manifest.json: ' + e.message, 'manifest.json');
        return f;
    }
    if (f.manifest === null || typeof f.manifest !== 'object' || Array.isArray(f.manifest)) {
        issue('error', 'manifest-invalid', 'manifest.json אינו אובייקט', 'manifest.json');
        f.manifest = null;
        return f;
    }
    const ver = parseVersion(f.manifest.version);
    if (ver && ver.major > CURRENT_MAJOR) {
        issue('error', 'version-unsupported', `הקובץ נוצר בגרסה חדשה של הפורמט (${f.manifest.version}) — עדכן את האפליקציה`, 'manifest.json');
        return f;
    }
    if (ver && ver.major < CURRENT_MAJOR) {
        issue('error', 'version-unsupported', `גרסת פורמט ${f.manifest.version} ישנה מדי ואין לה ממיר`, 'manifest.json');
        return f;
    }
    for (const s of validateSchema(f.manifest, SCHEMAS.manifest)) issue('error', 'manifest-schema', s.message, 'manifest.json#' + s.path);
    if (ver && ver.minor > +VERSION.split('.')[1]) {
        issue('warning', 'version-newer-minor', `הקובץ בגרסה ${f.manifest.version}, חדשה מ-${VERSION} — מה שלא מוכר נשמר כמו שהוא`, 'manifest.json');
    }
    let manifestText = '';
    try { manifestText = canonicalJson(f.manifest); } catch (e) {
        issue('error', 'manifest-invalid', 'manifest.json: ' + e.message, 'manifest.json');
    }
    if (/[^\s@"]+@[^\s@"]+\.[a-z]{2,}/i.test(manifestText)) {
        issue('warning', 'privacy', 'ב-manifest.json יש כתובת מייל — הוא לא אמור להכיל פרטים אישיים', 'manifest.json');
    }

    // document — מקור האמת
    if (!raw.has('document.json')) {
        issue('error', 'document-missing', 'חסר document.json');
    } else {
        const docBytes = raw.get('document.json');
        try {
            f.document = parseJson(docBytes);
            canonicalJson(f.document);
        } catch (e) {
            issue('error', e instanceof CanonicalError && /עומק/.test(e.message) ? 'json-too-deep' : 'document-invalid', 'document.json: ' + e.message, 'document.json');
            f.document = null;
        }
        if (f.document !== null) {
            if (typeof f.document !== 'object' || Array.isArray(f.document)) {
                issue('error', 'document-invalid', 'document.json אינו אובייקט', 'document.json');
            } else {
                for (const s of validateSchema(f.document, SCHEMAS.document)) issue('error', 'document-schema', s.message, 'document.json#' + s.path);
            }
        }
        const sha = await sha256(docBytes);
        if (f.documentSha256 !== null && f.documentSha256 !== sha) {
            issue('error', 'hash-mismatch', 'הגיבוב של document.json לא תואם ל-manifest — הקובץ פגום חלקית', 'document.json');
        }
    }

    for (const [p, d] of raw) if (!RESERVED.has(p)) f.files.set(p, d);
    await checkParts(f, issue);
    if (f.document && typeof f.document === 'object' && !Array.isArray(f.document)) checkDocument(f, issue);
    return f;
}

async function checkParts(f, issue) {
    const listedMedia = new Map((Array.isArray(f.manifest.media) ? f.manifest.media : []).map(m => [m?.path, m]));
    for (const [path, m] of listedMedia) {
        if (typeof path !== 'string') continue;
        if (!f.files.has(path)) { issue('error', 'media-missing', 'משאב שרשום ב-manifest חסר בקובץ', path); continue; }
        if (m.sha256 !== await sha256(f.files.get(path))) issue('error', 'hash-mismatch', 'הגיבוב של המשאב לא תואם ל-manifest', path);
    }
    for (const [path, data] of f.files) {
        if (path.startsWith('media/')) {
            const m = MEDIA_RE.exec(path);
            if (!m) issue('warning', 'media-unexpected-name', 'שם משאב שאינו sha256 — נשמר כמו שהוא', path);
            else if (m[1] !== await sha256(data)) issue('error', 'media-name-mismatch', 'שם המשאב אינו הגיבוב של התוכן', path);
            if (!listedMedia.has(path)) issue('warning', 'media-unlisted', 'משאב שלא רשום ב-manifest — יירשם בשמירה הבאה', path);
        }
        if (path.endsWith('.svg') || listedMedia.get(path)?.type === 'image/svg+xml') {
            const bad = svgProblem(new TextDecoder().decode(data));
            if (bad) issue('error', 'svg-unsafe', `SVG לא בטוח: ${bad}`, path);
        }
    }
    const listedR = new Map((Array.isArray(f.manifest.renditions) ? f.manifest.renditions : []).map(r => [r?.path, r]));
    for (const [path, r] of listedR) {
        if (typeof path !== 'string') continue;
        if (!f.files.has(path)) issue('error', 'rendition-missing', 'ייצוג שרשום ב-manifest חסר בקובץ', path);
        else if (r.sourceSha256 !== f.documentSha256) issue('warning', 'rendition-stale', 'הייצוג נגזר מגרסה קודמת של המסמך (מיושן)', path);
    }
    for (const path of f.files.keys()) {
        if (path.startsWith('renditions/') && !listedR.has(path)) issue('warning', 'rendition-unlisted', 'ייצוג שלא רשום ב-manifest — מיושן', path);
    }
    if (!f.files.has(PREVIEW)) issue('warning', 'preview-missing', 'חסר renditions/preview.png (חובה בכל שמירה)', PREVIEW);
    else {
        const dims = pngSize(f.files.get(PREVIEW));
        if (!dims) issue('warning', 'preview-invalid', 'preview.png אינו PNG', PREVIEW);
        else if (Math.max(dims[0], dims[1]) > PREVIEW_MAX_PX) issue('warning', 'preview-invalid', `preview.png גדול מ-${PREVIEW_MAX_PX}px`, PREVIEW);
    }
}

function checkDocument(f, issue) {
    const ids = new Set();
    const flows = f.document.flows && typeof f.document.flows === 'object' ? f.document.flows : {};
    const seeId = (id, where) => {
        if (typeof id !== 'string') return;
        if (ids.has(id)) issue('error', 'duplicate-id', `המזהה "${id}" מופיע פעמיים`, 'document.json#' + where);
        ids.add(id);
    };
    const walkLayers = (layers, where) => {
        if (!Array.isArray(layers)) return;
        layers.forEach((l, i) => {
            if (!l || typeof l !== 'object') return;
            const at = `${where}/${i}`;
            seeId(l.id, at);
            if (l.type === 'frame' && typeof l.flow === 'string' && !Object.hasOwn(flows, l.flow)) {
                issue('error', 'flow-missing', `המסגרת מפנה לשטף "${l.flow}" שאינו קיים`, `document.json#${at}/flow`);
            }
            walkLayers(l.children, `${at}/children`);
            walkPaths(l.paths, `${at}/paths`);
        });
    };
    // תוכן שכבת vector (סעיף 3): נתיב — d חובה ותקין; קבוצה — בלי d ו-transform משלה; type לא מוכר — נשמר, לא נבדק.
    const walkPaths = (items, where) => {
        if (!Array.isArray(items)) return;
        items.forEach((p, i) => {
            if (!p || typeof p !== 'object' || Array.isArray(p)) return;
            const at = `${where}/${i}`;
            seeId(p.id, at);
            const type = p.type ?? 'path';
            if (type === 'path') {
                if (typeof p.d !== 'string') issue('error', 'path-d-missing', 'לנתיב חסר d', `document.json#${at}`);
                else {
                    const bad = pathProblem(p.d);
                    if (bad) issue('error', 'path-d-invalid', `d לא תקין: ${bad}`, `document.json#${at}/d`);
                }
            } else if (type === 'group') {
                if (Object.hasOwn(p, 'd') || Object.hasOwn(p, 'transform')) {
                    issue('error', 'group-geometry', 'לקבוצה אין d ו-transform משלה', `document.json#${at}`);
                }
                walkPaths(p.children, `${at}/children`);
            }
        });
    };
    (Array.isArray(f.document.surfaces) ? f.document.surfaces : []).forEach((s, i) => {
        if (!s || typeof s !== 'object') return;
        seeId(s.id, `/surfaces/${i}`);
        walkLayers(s.layers, `/surfaces/${i}/layers`);
    });
    // הפניות למשאבים: כל src / embedded — רק ל-media/ שבתוך הקובץ. קישורים בטקסט (href) — מותרים.
    const walk = (v, where, depth) => {
        if (depth > 300 || v === null || typeof v !== 'object') return;
        for (const k of Object.keys(v).sort()) {
            const x = v[k], at = `${where}/${k}`;
            if ((k === 'src' || k === 'embedded') && typeof x === 'string' && !Array.isArray(v)) {
                if (!MEDIA_RE.test(x)) issue('error', 'external-ref', 'הפניה למשאב מחוץ לקובץ — כל משאב חייב להיות ב-media/', 'document.json#' + at);
                else if (!f.files.has(x)) issue('error', 'ref-missing', `המשאב ${x} לא נמצא בקובץ`, 'document.json#' + at);
            } else walk(x, at, depth + 1);
        }
    };
    walk(f.document, '', 0);
}

const PATH_ARGS = { M: 2, L: 2, C: 6, Q: 4, Z: 0 };

/**
 * null אם d תקין; אחרת — מה לא. רק פקודות מוחלטות M L C Q Z, מתחיל ב-M, ולכל פקודה כפולות של מספר הארגומנטים שלה.
 * סורק ולא ביטוי רגולרי אחד — כדי שנתיב של מגה-בתים לא יפיל את PCRE ב-PHP (src/php/mbe.php pathProblem).
 */
export function pathProblem(d) {
    const num = /[-+]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][-+]?[0-9]+)?/y;
    let i = 0, cmd = null, count = 0;
    const close = () => {
        if (cmd === null) return null;
        const k = PATH_ARGS[cmd];
        return (k === 0 ? count === 0 : count > 0 && count % k === 0) ? null : `${cmd} צריכה ${k ? `כפולות של ${k} מספרים` : 'בלי מספרים'}`;
    };
    for (;;) {
        while (i < d.length && ' \t\n\r\f,'.includes(d[i])) i++;
        if (i >= d.length) break;
        const c = d[i];
        if (Object.hasOwn(PATH_ARGS, c)) {
            if (cmd === null && c !== 'M') return 'נתיב מתחיל ב-M';
            const bad = close();
            if (bad) return bad;
            cmd = c; count = 0; i++;
            continue;
        }
        num.lastIndex = i;
        if (cmd === null || !num.exec(d)) return `תו לא צפוי "${c}" במקום ${i}`;
        i = num.lastIndex;
        count++;
    }
    if (cmd === null) return 'נתיב ריק';
    return close();
}

/** null אם ה-SVG בטוח; אחרת — מה נמצא. ניקוי בפועל — באחריות האפליקציה שמייצרת את ה-SVG. */
export function svgProblem(text) {
    const t = text.toLowerCase();
    if (/<script[\s>/]/.test(t)) return '<script>';
    if (/<foreignobject[\s>/]/.test(t)) return '<foreignObject>';
    if (/<!entity/.test(t)) return '<!ENTITY>';
    if (/[\s"'/]on[a-z]+\s*=/.test(t)) return 'on*=';
    if (/javascript:/.test(t)) return 'javascript:';
    if (/@import/.test(t)) return '@import';
    if (/(?:href|src)\s*=\s*["']\s*(?!#|data:image\/(?:png|jpeg|gif|webp);)/.test(t)) return 'הפניה חיצונית';
    if (/url\(\s*["']?\s*(?!#|data:image\/(?:png|jpeg|gif|webp);)/.test(t)) return 'url() חיצוני';
    return null;
}

function pngSize(b) {
    const sig = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];
    if (b.length < 24 || sig.some((x, i) => b[i] !== x)) return null;
    const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    return [dv.getUint32(16), dv.getUint32(20)];
}

/** עוטף את open — רק רשימת הבעיות. */
export async function validate(bytes, opts) {
    return (await open(bytes, opts)).issues;
}

/**
 * כותב קובץ. זורק MbeError כשהתוצאה לא הייתה עוברת אימות — ‎save לעולם לא מייצר קובץ פגום.
 * lite: true — "קובץ קל": preview בלבד (שאר הייצוגים נבנים בייצוא).
 */
export async function save(f, { app, appVersion = '', now = new Date(), lite = false } = {}) {
    if (!/^[a-z0-9][a-z0-9-]{0,63}$/.test(app || '')) throw new MbeError('save צריך app — מזהה האפליקציה ששומרת');
    if (!f.files.has(PREVIEW)) {
        throw new MbeError('חסר renditions/preview.png — חובה בכל שמירה',
            [{ level: 'error', code: 'preview-missing', message: 'חסר preview.png', path: PREVIEW }]);
    }
    let docBytes;
    try { docBytes = utf8(canonicalJson(f.document)); } catch (e) {
        throw new MbeError('document אינו JSON תקין: ' + e.message, [{ level: 'error', code: 'document-invalid', message: e.message }]);
    }
    const docSha = await sha256(docBytes);
    const at = now.toISOString().replace(/\.\d{3}Z$/, 'Z');
    const stamp = { app, ...(appVersion ? { appVersion } : {}), at };
    const old = f.manifest || {};
    const oldVer = parseVersion(old.version);
    const ours = parseVersion(VERSION);
    const version = oldVer && oldVer.major === ours.major && oldVer.minor > ours.minor ? old.version : VERSION;

    const files = new Map(f.files);
    if (lite) for (const p of [...files.keys()]) if (p.startsWith('renditions/') && p !== PREVIEW) files.delete(p);
    const names = [...files.keys()].sort();
    const oldMedia = new Map((Array.isArray(old.media) ? old.media : []).map(m => [m?.path, m]));
    const oldR = new Map((Array.isArray(old.renditions) ? old.renditions : []).map(r => [r?.path, r]));
    const media = [];
    for (const p of names.filter(p => p.startsWith('media/') && MEDIA_RE.test(p))) {
        const d = files.get(p);
        const prev = oldMedia.get(p);
        media.push({ ...prev, path: p, sha256: await sha256(d), type: prev?.type || typeForPath(p), bytes: d.length });
    }
    const renditions = names.filter(p => p.startsWith('renditions/')).map(p => {
        const prev = oldR.get(p) || {};
        const fresh = f.fresh.get(p);
        const r = { ...prev, path: p, type: fresh?.type || prev.type || typeForPath(p),
            sourceSha256: fresh ? docSha : (prev.sourceSha256 || '0'.repeat(64)), gaps: fresh ? fresh.gaps : (prev.gaps || []) };
        const by = fresh ? (fresh.by || app) : prev.by;
        if (by) r.by = by; else delete r.by;
        return r;
    });
    const manifest = {
        ...old, format: FORMAT, version, kind: old.kind,
        created: old.created || stamp, modified: stamp,
        document: { ...(old.document || {}), path: 'document.json', sha256: docSha },
        media, renditions,
    };
    const out = writeZip([
        { name: 'mimetype', data: utf8(MIMETYPE), store: true },
        { name: 'manifest.json', data: utf8(canonicalJson(manifest)) },
        { name: 'document.json', data: docBytes },
        ...names.map(name => ({ name, data: files.get(name) })),
    ], now);

    const check = await open(out);
    const errors = check.issues.filter(i => i.level === 'error');
    if (errors.length) throw new MbeError('השמירה נעצרה — הקובץ לא היה עובר אימות: ' + errors.map(e => e.code).join(', '), errors);
    f.manifest = check.manifest;
    f.files = check.files;
    f.fresh = new Map();
    f.issues = check.issues;
    return out;
}
