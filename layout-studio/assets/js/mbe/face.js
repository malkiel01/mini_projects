// פנים (mbe-format.md סעיף 7, docs/spec/hub-api.md 2.1–2.2): מחתימים docx / png שיוצאים מהחבילה ב-mbe-doc-id וב-mbe-base,
// ומזהים קובץ שחוזר. את התוכן עצמו (docx ↔ שטף) ממירה האפליקציה; כאן — רק המזהים. אותו קוד ב-PHP (src/php/face.php).
//
//   await save(f, { app });                            // קודם שומרים — mbe-base הוא הגיבוב של מה שנשמר
//   const docx = await stampFace(f, 'docx', bytes);    // + docProps/custom.xml + customXml (document.json)
//   const id = await identify(returned);               // { type: 'mbe'|'face'|'foreign', format, docId, base, model, document?, issues }

import { readZip, writeZip, crc32, LIMITS } from './zip.js';
import { canonicalJson, parseJson, utf8 } from './canonical.js';
import { sha256, newId, MbeError, ID_RE } from './mbe.js';

export const FACE_NS = 'urn:mbe:document:1';
const XMP_NS = 'urn:mbe:face:1';
const FMTID = '{D5CDD505-2E9C-101B-9397-08002B2CF9AE}';
const CT_CUSTOM = 'application/vnd.openxmlformats-officedocument.custom-properties+xml';
const CT_ITEMPROPS = 'application/vnd.openxmlformats-officedocument.customXmlProperties+xml';
const REL_CUSTOM = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties';
const REL_CUSTOMXML = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml';
const REL_ITEMPROPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps';
const RELS_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
const SHA_RE = /^[0-9a-f]{64}$/;
const PNG_SIG = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];
const dec = b => new TextDecoder().decode(b);

const fail = (code, message) => new MbeError(message, [{ level: 'error', code, message }]);

/** המזהים של הפנים — רק אחרי save: המסמך הנוכחי זהה למה שנשמר, ויש לו id. */
async function faceIds(f) {
    const docBytes = utf8(canonicalJson(f.document));
    const base = await sha256(docBytes);
    if (!f.id || base !== f.documentSha256) {
        throw fail('face-not-saved', 'מחתימים פנים רק אחרי save — המסמך השתנה מאז השמירה, או שעוד אין לו id');
    }
    return { docId: f.id, base, docBytes };
}

/** מחתים פנים: 'docx' או 'png'. ל-PDF — xmpPacket(f), שהכותב מטמיע בזמן היצירה. */
export async function stampFace(f, format, bytes) {
    const ids = await faceIds(f);
    if (!(bytes instanceof Uint8Array)) bytes = new Uint8Array(bytes);
    let out;
    if (format === 'docx') out = stampDocx(bytes, ids);
    else if (format === 'png') out = stampPng(bytes, ids);
    else if (format === 'pdf') throw fail('face-stamp-failed', 'PDF מחתימים בזמן הכתיבה: xmpPacket(f) → מטא-נתונים של המסמך');
    else throw fail('face-stamp-failed', `פנים מסוג ${format} אינן נתמכות`);
    exported(f, format);
    return out;
}

/** הפנים נרשמות ביומן כשלב export (hub-api.md 2.2) — בשמירה הבאה, פעם אחת לכל פורמט. */
function exported(f, format) {
    if (!f.pending.some(p => p.kind === 'export' && p.note === format)) f.pending.push({ kind: 'export', who: null, note: format });
}

/** חבילת XMP עם המזהים — להטמעה כ-Metadata של ה-PDF (לא דחוס, כדי ש-identify ימצא). */
export async function xmpPacket(f) {
    const { docId, base } = await faceIds(f);
    exported(f, 'pdf');
    return `<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?>\n<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">`
        + `<rdf:Description rdf:about="" xmlns:mbe="${XMP_NS}"><mbe:docId>${docId}</mbe:docId><mbe:base>${base}</mbe:base></rdf:Description>`
        + `</rdf:RDF></x:xmpmeta>\n<?xpacket end="w"?>`;
}

// ---- docx ----

const xmlEsc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const xmlUnesc = s => s.replace(/&(lt|gt|quot|apos|amp|#\d+|#x[0-9a-fA-F]+);/g, (m, e) =>
    e === 'lt' ? '<' : e === 'gt' ? '>' : e === 'quot' ? '"' : e === 'apos' ? "'" : e === 'amp' ? '&'
        : String.fromCodePoint(e[1] === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10)));

function addRel(xml, type, target) {
    xml ??= `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="${RELS_NS}"></Relationships>`;
    if (new RegExp(`Target="${target.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}"`).test(xml)) return xml;
    let n = 1;
    while (xml.includes(`Id="rIdMbe${n}"`)) n++;
    return xml.replace(/<\/Relationships>\s*$/, `<Relationship Id="rIdMbe${n}" Type="${type}" Target="${target}"/></Relationships>`);
}

function ctOverride(xml, part, type) {
    if (xml.includes(`PartName="${part}"`)) return xml;
    return xml.replace(/<\/Types>\s*$/, `<Override PartName="${part}" ContentType="${type}"/></Types>`);
}

function ctDefaultXml(xml) {
    if (/<Default\b[^>]*\bExtension="xml"/i.test(xml)) return xml;
    return xml.replace(/(<Types\b[^>]*>)/, '$1<Default Extension="xml" ContentType="application/xml"/>');
}

function customProps(old, props) {
    let xml = old ?? `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"></Properties>`;
    xml = xml.replace(/<property\b[^>]*\bname="mbe-(?:doc-id|base)"[^>]*>[\s\S]*?<\/property>/g, '');
    if (/<Properties\b[^>]*\/>/.test(xml)) xml = xml.replace(/<Properties\b([^>]*)\/>/, '<Properties$1></Properties>');
    let pid = Math.max(1, ...[...xml.matchAll(/\bpid="(\d+)"/g)].map(m => +m[1]));
    const add = Object.entries(props).map(([name, v]) => `<property fmtid="${FMTID}" pid="${++pid}" name="${name}"><vt:lpwstr>${xmlEsc(v)}</vt:lpwstr></property>`).join('');
    return xml.replace(/<\/Properties>\s*$/, add + '</Properties>');
}

function stampDocx(bytes, { docId, base, docBytes }) {
    const z = readZip(bytes, LIMITS);
    if (!z.entries.length) throw fail('face-stamp-failed', 'ה-docx אינו ZIP תקין');
    const files = new Map();
    for (const e of z.entries) {
        if (e.dir) continue;
        const d = e.read();
        if (!d) throw fail('face-stamp-failed', `החלק ${e.name} פגום`);
        files.set(e.name, d);
    }
    if (!files.has('[Content_Types].xml') || !files.has('word/document.xml')) throw fail('face-stamp-failed', 'זה אינו docx');
    const text = n => files.has(n) ? dec(files.get(n)) : null;
    const set = (n, s) => files.set(n, utf8(s));

    set('docProps/custom.xml', customProps(text('docProps/custom.xml'), { 'mbe-doc-id': docId, 'mbe-base': base }));
    // המודל בגרסה שיצאה — חלק customXml שלנו (מזוהה לפי ה-namespace); קיים — מחליפים, אחרת — מספר פנוי
    let n = 0, max = 0;
    for (const name of files.keys()) {
        const m = /^customXml\/item(\d+)\.xml$/.exec(name);
        if (!m) continue;
        max = Math.max(max, +m[1]);
        if (!n && text(name).includes(FACE_NS)) n = +m[1];
    }
    n ||= max + 1;
    set(`customXml/item${n}.xml`, `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<mbe:document xmlns:mbe="${FACE_NS}" base="${base}">${xmlEsc(dec(docBytes))}</mbe:document>`);
    if (!files.has(`customXml/itemProps${n}.xml`)) {
        set(`customXml/itemProps${n}.xml`, `<?xml version="1.0" encoding="UTF-8" standalone="no"?>\n<ds:datastoreItem ds:itemID="{${newId().toUpperCase()}}" xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml"><ds:schemaRefs/></ds:datastoreItem>`);
    }
    set(`customXml/_rels/item${n}.xml.rels`, addRel(text(`customXml/_rels/item${n}.xml.rels`), REL_ITEMPROPS, `itemProps${n}.xml`));
    set('word/_rels/document.xml.rels', addRel(text('word/_rels/document.xml.rels'), REL_CUSTOMXML, `../customXml/item${n}.xml`));
    set('_rels/.rels', addRel(text('_rels/.rels'), REL_CUSTOM, 'docProps/custom.xml'));
    let ct = ctDefaultXml(text('[Content_Types].xml'));
    ct = ctOverride(ct, '/docProps/custom.xml', CT_CUSTOM);
    ct = ctOverride(ct, `/customXml/itemProps${n}.xml`, CT_ITEMPROPS);
    set('[Content_Types].xml', ct);

    const order = [...z.entries.filter(e => !e.dir).map(e => e.name)];
    for (const k of files.keys()) if (!order.includes(k)) order.push(k);
    return writeZip(order.map(name => ({ name, data: files.get(name) })));
}

// ---- png ----

function pngChunks(b) {
    if (b.length < 8 || PNG_SIG.some((x, i) => b[i] !== x)) return null;
    const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    const out = [];
    for (let p = 8; p + 12 <= b.length;) {
        const len = dv.getUint32(p);
        if (p + 12 + len > b.length) return null;
        out.push({ type: dec(b.subarray(p + 4, p + 8)), data: b.subarray(p + 8, p + 8 + len), raw: b.subarray(p, p + 12 + len) });
        p += 12 + len;
    }
    return out;
}

function itxt(keyword, text) {
    const data = utf8(`${keyword}\0\0\0\0\0${text}`); // keyword, null, compression flag 0, method 0, language "", translated ""
    const c = new Uint8Array(12 + data.length);
    const dv = new DataView(c.buffer);
    dv.setUint32(0, data.length);
    c.set(utf8('iTXt'), 4);
    c.set(data, 8);
    dv.setUint32(8 + data.length, crc32(c.subarray(4, 8 + data.length)));
    return c;
}

const itxtKeyword = data => { const z = data.indexOf(0); return z > 0 ? dec(data.subarray(0, z)) : ''; };

function stampPng(bytes, { docId, base }) {
    const chunks = pngChunks(bytes);
    if (!chunks || chunks[0]?.type !== 'IHDR') throw fail('face-stamp-failed', 'זה אינו PNG תקין');
    const keep = chunks.filter(c => !(c.type === 'iTXt' && /^mbe-(doc-id|base)$/.test(itxtKeyword(c.data))));
    const parts = [Uint8Array.from(PNG_SIG), keep[0].raw, itxt('mbe-doc-id', docId), itxt('mbe-base', base), ...keep.slice(1).map(c => c.raw)];
    const out = new Uint8Array(parts.reduce((s, p) => s + p.length, 0));
    let o = 0;
    for (const p of parts) { out.set(p, o); o += p.length; }
    return out;
}

// ---- identify ----

/** "מה הקובץ הזה?" — קורא רק את המזהים, לא זורק. */
export async function identify(bytes) {
    if (!(bytes instanceof Uint8Array)) bytes = new Uint8Array(bytes);
    const r = { type: 'foreign', format: null, docId: null, base: null, model: false, issues: [] };
    try {
        if (bytes[0] === 0x50 && bytes[1] === 0x4b) identifyZip(bytes, r);
        else if (dec(bytes.subarray(0, 5)) === '%PDF-') identifyPdf(bytes, r);
        else if (PNG_SIG.every((x, i) => bytes[i] === x)) identifyPng(bytes, r);
    } catch (e) {
        r.issues.push({ level: 'warning', code: 'face-unknown-doc', message: 'לא הצלחתי לקרוא את המזהים: ' + e.message });
    }
    if (r.docId !== null && !ID_RE.test(r.docId)) {
        r.issues.push({ level: 'warning', code: 'face-unknown-doc', message: 'mbe-doc-id אינו UUID v4' });
        r.docId = null;
    }
    if (r.base !== null && !SHA_RE.test(r.base)) {
        r.issues.push({ level: 'warning', code: 'face-unknown-doc', message: 'mbe-base אינו sha256' });
        r.base = null;
    }
    if (r.type !== 'mbe') r.type = r.docId ? 'face' : 'foreign';
    return r;
}

function identifyZip(bytes, r) {
    const z = readZip(bytes, LIMITS);
    const get = name => z.entries.find(e => e.name === name)?.read() ?? null;
    if (z.entries.some(e => e.name === 'mimetype') && z.entries.some(e => e.name === 'manifest.json')) {
        const m = parseJson(get('manifest.json'));
        r.type = 'mbe'; r.format = 'mbe'; r.model = true;
        r.docId = typeof m?.id === 'string' ? m.id : null;
        r.base = typeof m?.document?.sha256 === 'string' ? m.document.sha256 : null;
        return;
    }
    if (!z.entries.some(e => e.name === '[Content_Types].xml') || !z.entries.some(e => e.name === 'word/document.xml')) return;
    r.format = 'docx';
    const custom = get('docProps/custom.xml');
    if (custom) {
        const prop = name => {
            const m = new RegExp(`<property\\b[^>]*\\bname="${name}"[^>]*>\\s*<(?:\\w+:)?lpwstr>([^<]*)<`).exec(dec(custom));
            return m ? xmlUnesc(m[1]).trim() : null;
        };
        r.docId = prop('mbe-doc-id');
        r.base = prop('mbe-base');
    }
    for (const e of z.entries) {
        if (!/^customXml\/item\d+\.xml$/.test(e.name)) continue;
        const t = dec(e.read() ?? new Uint8Array());
        if (!t.includes(FACE_NS)) continue;
        const m = /<(\w+:)?document\b[^>]*>([\s\S]*)<\/\1?document>/.exec(t);
        if (!m) continue;
        try {
            r.document = parseJson(utf8(xmlUnesc(m[2])));
            r.model = true;
        } catch (err) {
            r.issues.push({ level: 'warning', code: 'face-base-missing', message: 'המודל ב-customXml פגום: ' + err.message });
        }
        break;
    }
}

function identifyPdf(bytes, r) {
    r.format = 'pdf';
    const t = new TextDecoder('latin1').decode(bytes);
    const pick = name => (new RegExp(`mbe:${name}(?:>|="|='|\\s*=\\s*")([^<"']+)`).exec(t) || [])[1]?.trim() ?? null;
    if (t.includes(XMP_NS)) { r.docId = pick('docId'); r.base = pick('base'); }
}

function identifyPng(bytes, r) {
    r.format = 'png';
    for (const c of pngChunks(bytes) || []) {
        if (c.type !== 'iTXt') continue;
        const k = itxtKeyword(c.data);
        if (k !== 'mbe-doc-id' && k !== 'mbe-base') continue;
        // keyword\0 flag method language\0 translated\0 text
        let p = k.length + 3;
        for (let z = 0; z < 2 && p > 0; z++) p = c.data.indexOf(0, p) + 1;
        if (p <= 0) continue;
        const v = dec(c.data.subarray(p)).trim();
        if (k === 'mbe-doc-id') r.docId = v; else r.base = v;
    }
}
