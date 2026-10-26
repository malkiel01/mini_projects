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
export const VERSION = '1.1';
export const CURRENT_MAJOR = 1;
export const MIMETYPE = 'application/vnd.mbe+zip';
export const PREVIEW = 'renditions/preview.png';
export const ID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

/** מזהה מסמך חדש — UUID v4 באותיות קטנות (mbe-format.md סעיף 3, manifest.id). ‏getRandomValues ולא randomUUID — זה עובד גם בדף שאינו https. */
export function newId() {
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
export const PREVIEW_MAX_PX = 1024;

const MEDIA_RE = /^media\/([0-9a-f]{64})(\.[a-z0-9]{1,8})?$/;
const RESERVED = new Set(['mimetype', 'manifest.json', 'document.json']);
export const JOURNAL = 'history/journal.json';
const SNAP_RE = /^history\/([0-9a-f]{64})\.json$/;
const snap = sha => `history/${sha}.json`;
const EMAIL_RE = /[^\s@"]+@[^\s@"]+\.[a-z]{2,}/i;
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
        this.pending = [];      // שלבים מפורשים (stage / restore) — נרשמים ביומן בשמירה הבאה
        this.openedDoc = null;  // הבתים של document.json כפי שנפתח — עותק הבסיס ב-history/
    }

    get ok() { return !this.issues.some(i => i.level === 'error'); }
    get kind() { return this.manifest?.kind ?? null; }
    /** מזהה המסמך — null עד השמירה הראשונה (או בקובץ 1.0). */
    get id() { const v = this.manifest?.id; return typeof v === 'string' ? v : null; }
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

    // ---- יומן שלבים וגרסאות (history/, מ-1.1 — mbe-format.md סעיף 3, docs/spec/hub-api.md 2.5) ----

    /** השלבים, מהישן לחדש. יומן פגום או חסר — רשימה ריקה (והאזהרה ב-issues). */
    journal() {
        return readJournal(this.files)?.stages.map(s => ({ ...s })) ?? [];
    }

    /** המסמך של גרסה (לפי sha256), או null אם נגזמה / חסרה. */
    version(sha) {
        const d = this.files.get(snap(sha));
        if (!d) return null;
        try { return parseJson(d); } catch { return null; }
    }

    /** שלב מפורש ("נשלח להגהה") — נרשם בשמירה הבאה, עם base / result של אותה שמירה. */
    stage({ kind = 'edit', who = null, note = null } = {}) {
        if (!/^[a-z][a-z0-9-]{0,31}$/.test(kind)) throw new MbeError(`סוג שלב לא חוקי: ${kind}`);
        this.pending.push({ kind, who, note });
    }

    /** חזרה לשלב (לפי id) או לגרסה (לפי sha256): המסמך הופך לגרסה ההיא, ובשמירה נרשם שלב restore. לא מוחק כלום. */
    restore(ref, { who = null } = {}) {
        const st = this.journal().find(s => s.id === ref || s.result === ref);
        const sha = st ? st.result : ref;
        const doc = typeof sha === 'string' ? this.version(sha) : null;
        if (!doc) throw new MbeError(`הגרסה ${ref} לא נמצאה בקובץ (נגזמה או חסרה)`);
        this.document = doc;
        this.pending.push({ kind: 'restore', who, note: `שוחזר מ-${st ? st.id : sha.slice(0, 12)}` });
    }

    /** גיזום: משאיר את keep הגרסאות האחרונות (ואת הנוכחית); שלב שהגרסה שלו נמחקה — pruned: true. היומן עצמו לא נגזם. */
    prune({ keep = 30 } = {}) {
        const j = readJournal(this.files);
        if (!j) return 0;
        const order = [...new Set(j.stages.map(s => s.result).filter(r => typeof r === 'string').reverse())];
        const keepSet = new Set(order.slice(0, keep));
        if (this.documentSha256) keepSet.add(this.documentSha256);
        let n = 0;
        for (const p of [...this.files.keys()]) {
            const m = SNAP_RE.exec(p);
            if (m && !keepSet.has(m[1])) { this.files.delete(p); n++; }
        }
        for (const s of j.stages) if (typeof s.result === 'string' && !keepSet.has(s.result)) s.pruned = true;
        this.files.set(JOURNAL, utf8(canonicalJson(j)));
        return n;
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
    // manifest.id (מ-1.1): קובץ 1.0 בלי id — תקין, ויקבל בשמירה הבאה. מזהה פגום — נשמר כמו שהוא (לא משנים זהות בשקט).
    if (Object.hasOwn(f.manifest, 'id')) {
        if (typeof f.manifest.id !== 'string' || !ID_RE.test(f.manifest.id)) {
            issue('warning', 'manifest-id-invalid', 'manifest.id אינו UUID v4 באותיות קטנות — נשמר כמו שהוא', 'manifest.json#/id');
        }
    } else if (ver && ver.minor >= 1) {
        issue('warning', 'manifest-id-missing', `לקובץ בגרסה ${f.manifest.version} חסר manifest.id — יתווסף בשמירה הבאה`, 'manifest.json#/id');
    }
    let manifestText = '';
    try { manifestText = canonicalJson(f.manifest); } catch (e) {
        issue('error', 'manifest-invalid', 'manifest.json: ' + e.message, 'manifest.json');
    }
    if (EMAIL_RE.test(manifestText)) {
        issue('warning', 'privacy', 'ב-manifest.json יש כתובת מייל — הוא לא אמור להכיל פרטים אישיים', 'manifest.json');
    }

    // document — מקור האמת
    if (!raw.has('document.json')) {
        issue('error', 'document-missing', 'חסר document.json');
    } else {
        const docBytes = raw.get('document.json');
        f.openedDoc = docBytes;
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
    await checkHistory(f, issue);
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

function readJournal(files) {
    const raw = files.get(JOURNAL);
    if (!raw) return null;
    try {
        const j = parseJson(raw);
        return j && typeof j === 'object' && !Array.isArray(j) && Array.isArray(j.stages) ? j : null;
    } catch { return null; }
}

// היסטוריה פגומה — אזהרות בלבד: היא לא מונעת פתיחה של המסמך עצמו.
async function checkHistory(f, issue) {
    for (const [p, d] of f.files) {
        if (!p.startsWith('history/') || p === JOURNAL) continue;
        const m = SNAP_RE.exec(p);
        if (m && m[1] !== await sha256(d)) issue('warning', 'history-hash-mismatch', 'שם הגרסה אינו הגיבוב של התוכן', p);
    }
    if (!f.files.has(JOURNAL)) return;
    const j = readJournal(f.files);
    if (!j) return issue('warning', 'history-invalid', 'history/journal.json אינו יומן תקין ({ stages: [] }) — נשמר כמו שהוא', JOURNAL);
    j.stages.forEach((s, i) => {
        const at = `${JOURNAL}#/stages/${i}`;
        if (!s || typeof s !== 'object' || Array.isArray(s) || typeof s.kind !== 'string' || typeof s.result !== 'string') {
            return issue('warning', 'history-invalid', 'שלב בלי kind או result', at);
        }
        if (!s.pruned && !f.files.has(snap(s.result))) issue('warning', 'history-missing', `העותק של הגרסה ${s.result.slice(0, 12)} חסר`, at);
        if (typeof s.base === 'string' && !f.files.has(snap(s.base)) && !j.stages.some(t => t && t.result === s.base && t.pruned)) {
            issue('warning', 'history-base-missing', `הבסיס ${s.base.slice(0, 12)} לא נמצא בהיסטוריה`, at);
        }
        if (typeof s.who === 'string' && EMAIL_RE.test(s.who)) issue('warning', 'privacy', 'ביומן יש כתובת מייל — who הוא כינוי, לא זהות', at);
    });
}

/**
 * רושם את השמירה ביומן. שמירה בלי שינוי במסמך ובלי שלב מפורש — לא נוגעת ביומן. שמירות רצופות של אותה אפליקציה ואותו
 * who — מעדכנות את ה-result של השלב האחרון (אחרת כל הקשה = שלב), והעותק הקודם נמחק אם אף שלב לא מפנה אליו.
 */
function applyJournal(f, files, { docSha, docBytes, app, who, at }) {
    const opened = f.documentSha256;
    const base = opened && opened !== '0'.repeat(64) ? opened : null;
    if (!f.pending.length && base === docSha) return;
    if (files.has(JOURNAL) && !readJournal(files)) return; // יומן פגום — נשמר כמו שהוא, לא כותבים עליו
    const j = readJournal(files) ?? { stages: [] };
    const stages = j.stages;
    if (base && f.openedDoc && !files.has(snap(base))) files.set(snap(base), f.openedDoc);
    files.set(snap(docSha), docBytes);
    const nextId = () => 's' + (Math.max(0, ...stages.map(s => +(/^s(\d+)$/.exec(s?.id ?? '')?.[1] ?? 0))) + 1);
    const entry = (kind, w, note) => {
        const e = { id: nextId(), at, app, kind, base, result: docSha };
        if (w) e.who = w;
        if (note) e.note = note;
        return e;
    };
    if (f.pending.length) {
        for (const p of f.pending) {
            const e = entry(p.kind, p.who || who, p.note);
            // שלב שהגרסה שלו אינה המסמך הנשמר (return במיזוג — מה שחזר מבחוץ): app / base / result משלו, והעותקים איתו
            for (const [sha, b] of Object.entries(p.snapshots || {})) if (!files.has(snap(sha))) files.set(snap(sha), b);
            if (p.app) e.app = p.app;
            if (p.base !== undefined) e.base = p.base;
            if (p.result) e.result = p.result;
            stages.push(e);
        }
    } else {
        const last = stages[stages.length - 1];
        if (last && last.app === app && (last.who ?? null) === (who ?? null) && (last.kind === 'edit' || last.kind === 'create')
            && last.result === base && base !== null) {
            const prev = last.result;
            last.result = docSha;
            last.at = at;
            if (!stages.some(s => s.base === prev || s.result === prev)) files.delete(snap(prev));
        } else {
            stages.push(entry(base ? 'edit' : 'create', who, null));
        }
    }
    files.set(JOURNAL, utf8(canonicalJson(j)));
}

function checkDocument(f, issue) {
    const ids = new Set();
    const flows = f.document.flows && typeof f.document.flows === 'object' ? f.document.flows : {};
    const seeId = (id, where) => {
        if (typeof id !== 'string') return;
        if (ids.has(id)) issue('error', 'duplicate-id', `המזהה "${id}" מופיע פעמיים`, 'document.json#' + where);
        ids.add(id);
    };
    const layerById = new Map();
    const walkLayers = (layers, where) => {
        if (!Array.isArray(layers)) return;
        layers.forEach((l, i) => {
            if (!l || typeof l !== 'object') return;
            const at = `${where}/${i}`;
            seeId(l.id, at);
            if (typeof l.id === 'string' && !layerById.has(l.id)) layerById.set(l.id, l);
            if (l.type === 'frame' && typeof l.flow === 'string' && !Object.hasOwn(flows, l.flow)) {
                issue('error', 'flow-missing', `המסגרת מפנה לשטף "${l.flow}" שאינו קיים`, `document.json#${at}/flow`);
            }
            // מסכה וקטורית של שכבת raster (requests/raster-studio-r2.md) — אותה בדיקה כמו נתיבי vector
            if (l.vectorMask && typeof l.vectorMask === 'object' && typeof l.vectorMask.d === 'string') {
                const bad = pathProblem(l.vectorMask.d);
                if (bad) issue('error', 'path-d-invalid', `d לא תקין: ${bad}`, `document.json#${at}/vectorMask/d`);
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
                // קבוצה בוליאנית חיה (union / subtract / intersect / exclude) נושאת d מחושב משלה — requests/spec-vector-g3.md
                if (Object.hasOwn(p, 'boolean')) {
                    const bad = typeof p.d === 'string' ? pathProblem(p.d) : null;
                    if (bad) issue('error', 'path-d-invalid', `d לא תקין: ${bad}`, `document.json#${at}/d`);
                } else if (Object.hasOwn(p, 'd') || Object.hasOwn(p, 'transform')) {
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
    // מזהה פסקה (מ-1.1): ייחודי בשטף, [A-Za-z0-9_]{1,32}. חסר — תקין (1.0), ו-save משלים. פגום או כפול — אזהרה, ו-save מחליף.
    for (const fid of Object.keys(flows).sort()) {
        const fl = flows[fid];
        if (!fl || fl.engine !== 'prosemirror' || !fl.doc || typeof fl.doc !== 'object') continue;
        const seen = new Set();
        walkBlocks(fl.doc, `/flows/${fid}/doc`, (node, at) => {
            const id = node.attrs?.id;
            if (id === undefined || id === null) return;
            if (typeof id !== 'string' || !PARA_ID_RE.test(id)) issue('warning', 'para-id-invalid', 'מזהה פסקה לא תקין — יוחלף בשמירה', `document.json#${at}/attrs/id`);
            else if (seen.has(id)) issue('warning', 'para-id-duplicate', `המזהה "${id}" מופיע פעמיים בשטף "${fid}" — השני יוחלף בשמירה`, `document.json#${at}/attrs/id`);
            seen.add(id);
        });
    }
    // סדר השרשור (סעיף 3, "מסגרות, שטפים"): כל מזהה ב-frames — שכבת frame קיימת שה-flow שלה הוא השטף הזה, פעם אחת.
    for (const fid of Object.keys(flows).sort()) {
        const list = flows[fid]?.frames;
        if (!Array.isArray(list)) continue;
        const seen = new Set();
        list.forEach((id, j) => {
            if (typeof id !== 'string') return;
            const at = `document.json#/flows/${fid}/frames/${j}`;
            const l = layerById.get(id);
            if (seen.has(id)) issue('error', 'flow-frames', `המסגרת "${id}" מופיעה פעמיים בשטף "${fid}"`, at);
            else if (!l) issue('error', 'flow-frames', `בשטף "${fid}" אין שכבה "${id}"`, at);
            else if (l.type !== 'frame') issue('error', 'flow-frames', `"${id}" בשטף "${fid}" אינה מסגרת (${JSON.stringify(l.type ?? null)})`, at);
            else if (l.flow !== fid) issue('error', 'flow-frames', `המסגרת "${id}" שייכת לשטף "${l.flow ?? '—'}" ולא ל-"${fid}"`, at);
            seen.add(id);
        });
    }
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

// ---- מזהה פסקה (מ-1.1, mbe-format.md סעיף 3; requests/spec-para-id.md) ----

export const PARA_ID_RE = /^[A-Za-z0-9_]{1,32}$/;
const PARA_TYPES = new Set(['paragraph', 'heading']);
const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

/** מזהה פסקה חדש: p + 10 תווים אלפאנומריים (בלי מקף — שם סימנייה ב-Word). */
export function paraId() {
    let out = 'p';
    while (out.length < 11) {
        for (const b of crypto.getRandomValues(new Uint8Array(16))) {
            if (b < 248 && out.length < 11) out += ALNUM[b % 62]; // 248 = 4×62 — בלי הטיה
        }
    }
    return out;
}

/** שם הסימנייה הנסתרת של פסקה ב-docx, וחזרה ממנו. */
export const paragraphBookmark = id => `_mbe_p${id}`;
export const bookmarkParaId = name => {
    const m = /^_mbe_p([A-Za-z0-9_]{1,32})$/.exec(name);
    return m ? m[1] : null;
};

/** עובר על כל צומת בלוק של טקסט (paragraph, heading) — גם בתוך רשימות וטבלאות, בסדר המסמך. */
function walkBlocks(node, where, fn, depth = 0) {
    if (!node || typeof node !== 'object' || depth > 200) return;
    if (PARA_TYPES.has(node.type)) fn(node, where);
    if (Array.isArray(node.content)) node.content.forEach((c, i) => walkBlocks(c, `${where}/content/${i}`, fn, depth + 1));
}

/** משלים מזהים בשטף אחד (ProseMirror JSON): חסר, פגום או כפול — מקבל חדש. מזהה תקין לא משתנה. מחזיר כמה הושלמו. */
export function ensureIds(doc) {
    const seen = new Set();
    const fresh = () => { let id; do id = paraId(); while (seen.has(id)); return id; };
    let n = 0;
    walkBlocks(doc, '', node => {
        const id = node.attrs?.id;
        if (typeof id === 'string' && PARA_ID_RE.test(id) && !seen.has(id)) { seen.add(id); return; }
        if (!node.attrs || typeof node.attrs !== 'object' || Array.isArray(node.attrs)) node.attrs = {};
        node.attrs.id = fresh();
        seen.add(node.attrs.id);
        n++;
    });
    return n;
}

/** כל שטפי ה-prosemirror במסמך. רץ לבד ב-save. */
export function ensureParaIds(document) {
    const flows = document?.flows;
    if (!flows || typeof flows !== 'object' || Array.isArray(flows)) return 0;
    let n = 0;
    for (const fid of Object.keys(flows).sort()) {
        const fl = flows[fid];
        if (fl && fl.engine === 'prosemirror' && fl.doc && typeof fl.doc === 'object') n += ensureIds(fl.doc);
    }
    return n;
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
 * asCopy: true — "שמירה כעותק": מסמך חדש — manifest.id חדש ו-created חדש.
 * who — כינוי לשלב ביומן ("המגיה"); שינוי ב-app או ב-who פותח שלב חדש.
 */
export async function save(f, { app, appVersion = '', who = null, now = new Date(), lite = false, asCopy = false } = {}) {
    if (!/^[a-z0-9][a-z0-9-]{0,63}$/.test(app || '')) throw new MbeError('save צריך app — מזהה האפליקציה ששומרת');
    if (!f.files.has(PREVIEW)) {
        throw new MbeError('חסר renditions/preview.png — חובה בכל שמירה',
            [{ level: 'error', code: 'preview-missing', message: 'חסר preview.png', path: PREVIEW }]);
    }
    ensureParaIds(f.document);
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
    applyJournal(f, files, { docSha, docBytes, app, who: who || null, at });
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
        // id: נוצר בשמירה הראשונה ולא משתנה; asCopy ("שמירה כעותק") — מסמך חדש: id חדש ו-created חדש
        id: !asCopy && Object.hasOwn(old, 'id') ? old.id : newId(),
        created: (!asCopy && old.created) || stamp, modified: stamp,
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
    f.pending = [];
    f.openedDoc = docBytes;
    f.issues = check.issues;
    return out;
}
