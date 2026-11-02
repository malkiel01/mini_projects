/**
 * pdf-core ב-JS (מ6): גופני TrueType ל-PDF — העתקה של font.php (TtfFont) ו-subset.php (TtfSubset), כדי ש-glyphs ב-JS
 * יוציא אותו PDF כמו PHP: אותו קובץ גופן מוקטן (בבתים), אותם רוחבים, אותו ToUnicode ואותו שם (תג ה-subset מ-md5).
 * (pdf-core — עורכים ב-creative-suite/pdf-core/src ומריצים tools/sync.php)
 */

const bad = msg => new TypeError(msg);   // כמו InvalidArgumentException ב-PHP
const tag4 = (b, o) => String.fromCharCode(b[o], b[o + 1], b[o + 2], b[o + 3]);
const dvOf = b => new DataView(b.buffer, b.byteOffset, b.byteLength);
const pad4 = len => (4 - len % 4) % 4;
const roundHalf = v => Math.sign(v) * Math.round(Math.abs(v));   // round() של PHP — חצי הרחק מאפס

async function inflate(b) {
    const out = new Response(new Blob([b]).stream().pipeThrough(new DecompressionStream('deflate')));
    return new Uint8Array(await out.arrayBuffer());
}

/** בונה sfnt מטבלאות: [[tag, bytes, checksum]] בסדר הנתון */
function buildSfnt(flavor, tables) {
    const num = tables.length;
    const es = Math.floor(Math.log2(num));
    const search = (2 ** es) * 16;
    let size = 12 + 16 * num;
    for (const [, d] of tables) size += d.length + pad4(d.length);
    const out = new Uint8Array(size), dv = dvOf(out);
    dv.setUint32(0, flavor); dv.setUint16(4, num); dv.setUint16(6, search); dv.setUint16(8, es); dv.setUint16(10, num * 16 - search);
    let off = 12 + 16 * num;
    tables.forEach(([tag, d, chk], i) => {
        const e = 12 + i * 16;
        for (let k = 0; k < 4; k++) out[e + k] = (tag + '    ').charCodeAt(k);
        dv.setUint32(e + 4, chk >>> 0); dv.setUint32(e + 8, off); dv.setUint32(e + 12, d.length);
        out.set(d, off);
        off += d.length + pad4(d.length);
    });
    return out;
}

/** WOFF 1.0 → sfnt: פורקים כל טבלה (zlib) ובונים מחדש את ספריית הטבלאות — כמו woffToSfnt */
async function woffToSfnt(w) {
    const dv = dvOf(w);
    const flavor = dv.getUint32(4), num = dv.getUint16(12);
    const tables = [];
    for (let i = 0; i < num; i++) {
        const e = 44 + i * 20;
        const off = dv.getUint32(e + 4), comp = dv.getUint32(e + 8), orig = dv.getUint32(e + 12);
        let data = w.subarray(off, off + comp);
        if (comp < orig) data = await inflate(data);
        tables.push([tag4(w, e), data, dv.getUint32(e + 16)]);
    }
    return buildSfnt(flavor, tables);
}

function tablesOf(s) {
    const t = new Map();
    const dv = dvOf(s);
    const num = dv.getUint16(4);
    for (let i = 0; i < num; i++) {
        const e = 12 + i * 16;
        const off = dv.getUint32(e + 8), len = dv.getUint32(e + 12);
        t.set(tag4(s, e), s.subarray(off, off + len));
    }
    return t;
}

export class TtfFont {
    /** גופן מה-bytes (TTF / WOFF) — אותו קובץ שהלקוח עיצב איתו (HarfBuzz), כדי שמזהי הגליפים יתאימו */
    static async fromBytes(bytes, name) {
        const f = new TtfFont();
        f.name = String(name).replace(/[^A-Za-z0-9-]/g, '') || 'Font';
        f.sfnt = tag4(bytes, 0) === 'wOFF' ? await woffToSfnt(bytes) : bytes;
        if (f.sfnt.length < 12 || !['\0\x01\0\0', 'true'].includes(tag4(f.sfnt, 0))) throw bad('גופן שאינו TrueType');
        f.parse();
        return f;
    }

    parse() {
        this.unitsPerEm = 1000; this.ascent = 800; this.descent = -200; this.capHeight = 700;
        this.cmap = new Map(); this.widths = []; this.used = new Map(); this.rcmap = null;
        this.tables = tablesOf(this.sfnt);
        const tb = tag => this.tables.get(tag) || new Uint8Array(0);
        const head = dvOf(tb('head'));
        this.unitsPerEm = head.getUint16(18);
        this.bbox = [36, 38, 40, 42].map(o => head.getInt16(o));
        const hheaB = tb('hhea'), hhea = dvOf(hheaB);
        this.ascent = hhea.getInt16(4);
        this.descent = hhea.getInt16(6);
        const numH = hhea.getUint16(34);
        const os2 = tb('OS/2');
        this.capHeight = (os2.length >= 90 ? dvOf(os2).getInt16(88) : 0) || Math.trunc(this.ascent * 0.7);
        const hmtx = dvOf(tb('hmtx'));
        const numGlyphs = dvOf(tb('maxp')).getUint16(4);
        let last = 0;
        for (let g = 0; g < numGlyphs; g++) {
            if (g < numH) last = hmtx.getUint16(g * 4);
            this.widths[g] = last;
        }
        this.parseCmap(tb('cmap'));
    }

    /** cmap: פורמט 12 (אם יש) או 4, Unicode */
    parseCmap(c) {
        if (c.length < 4) return;
        const dv = dvOf(c);
        const u16 = o => (o + 2 <= c.length ? dv.getUint16(o) : null);
        const n = dv.getUint16(2);
        let best = null, bestFmt = 0;
        for (let i = 0; i < n; i++) {
            const plat = dv.getUint16(4 + i * 8), encId = dv.getUint16(6 + i * 8), off = dv.getUint32(8 + i * 8);
            const fmt = u16(off);
            const unicode = plat === 0 || (plat === 3 && (encId === 1 || encId === 10));
            if (!unicode) continue;
            if (fmt === 12 && bestFmt !== 12) { best = off; bestFmt = 12; }
            else if (fmt === 4 && !best) { best = off; bestFmt = 4; }
        }
        if (best === null) return;
        if (bestFmt === 12) {
            const groups = dv.getUint32(best + 12);
            for (let i = 0; i < groups; i++) {
                const o = best + 16 + i * 12;
                const start = dv.getUint32(o), end = dv.getUint32(o + 4), gid = dv.getUint32(o + 8);
                for (let cp = start; cp <= end && cp - start < 70000; cp++) this.cmap.set(cp, gid + (cp - start));
            }
            return;
        }
        const segX2 = dv.getUint16(best + 6), seg = segX2 / 2;
        const at = (base, i) => dv.getUint16(base + i * 2);
        const endsO = best + 14, startsO = best + 16 + segX2, deltasO = best + 16 + 2 * segX2, rangeOff = best + 16 + 3 * segX2;
        for (let i = 0; i < seg; i++) {
            const s = at(startsO, i), e = at(endsO, i), delta = at(deltasO, i), range = at(rangeOff, i);
            for (let cp = s; cp <= e && cp !== 0xffff; cp++) {
                let gid;
                if (range === 0) gid = (cp + delta) & 0xffff;
                else {
                    gid = u16(rangeOff + i * 2 + range + (cp - s) * 2) ?? 0;
                    if (gid) gid = (gid + delta) & 0xffff;
                }
                if (gid) this.cmap.set(cp, gid);
            }
        }
    }

    numGlyphs() { return this.widths.length; }
    widthUnits(gid) { return this.widths[gid] ?? 0; }

    /** מסמן גליף לפי מזהה ומחזיר אותו כ-hex. התו — מה-cmap אם יש (ל-ToUnicode), אחרת -1 */
    encodeGid(gid) {
        if (!this.used.has(gid)) {
            if (this.rcmap === null) { this.rcmap = new Map(); for (const [cp, g] of this.cmap) if (!this.rcmap.has(g)) this.rcmap.set(g, cp); }
            this.used.set(gid, this.rcmap.get(gid) ?? -1);
        }
        return gid.toString(16).toUpperCase().padStart(4, '0');
    }

    /** אובייקטי ה-PDF של הגופן (בלי הזרמים): כמו PdfWriter::output */
    pdfParts() {
        const k = 1000 / this.unitsPerEm;
        const base = subsetTag(this.name) + '+' + this.name;
        const used = [...this.used.entries()].sort((a, b) => a[0] - b[0]);
        const w = used.map(([gid]) => `${gid} [${roundHalf(this.widthUnits(gid) * k)}] `).join('');
        const bbox = this.bbox.map(v => roundHalf(v * k)).join(' ');
        return { base, used, w, bbox, ascent: roundHalf(this.ascent * k), descent: roundHalf(this.descent * k), capHeight: roundHalf(this.capHeight * k) };
    }
}

// ---------- subset — כמו TtfSubset::make ----------

const KEEP = ['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap', 'cvt ', 'fpgm', 'prep', 'OS/2', 'name', 'post'];

function sum(d) {
    const p = new Uint8Array(d.length + pad4(d.length));
    p.set(d);
    const dv = dvOf(p);
    let s = 0;
    for (let i = 0; i < p.length; i += 4) s = (s + dv.getUint32(i)) >>> 0;
    return s;
}

function components(d) {
    if (d.length < 10) return [];
    const dv = dvOf(d);
    if (dv.getUint16(0) < 0x8000) return [];
    const out = [];
    let p = 10, f;
    do {
        if (p + 4 > d.length) break;
        f = dv.getUint16(p);
        out.push(dv.getUint16(p + 2));
        p += 4 + ((f & 0x1) ? 4 : 2);
        if (f & 0x8) p += 2; else if (f & 0x40) p += 4; else if (f & 0x80) p += 8;
    } while (f & 0x20);
    return out;
}

/** @param {Uint8Array} sfnt @param {number[]} gids */
export function subset(sfnt, gids) {
    if (tag4(sfnt, 0) === 'OTTO') return sfnt;
    const t = tablesOf(sfnt);
    if (!t.has('glyf') || !t.has('loca') || !t.has('head') || !t.has('maxp')) return sfnt;
    const headIn = t.get('head');
    const long = dvOf(headIn).getUint16(50) === 1;
    const num = dvOf(t.get('maxp')).getUint16(4);
    const lv = dvOf(t.get('loca'));
    const offs = [];
    for (let g = 0; g <= num; g++) offs.push(long ? lv.getUint32(g * 4) : lv.getUint16(g * 2) * 2);
    const glyfIn = t.get('glyf');
    const glyph = g => (g < num ? glyfIn.subarray(offs[g], offs[g + 1]) : new Uint8Array(0));
    // הסגירה: גליף 0, מה שבשימוש, ורכיבים של גליפים מורכבים — בכל עומק
    const keep = new Set([0]);
    const todo = gids.filter(g => g >= 0 && g < num);
    while (todo.length) {
        const g = todo.pop();
        if (keep.has(g) && g !== 0) continue;
        keep.add(g);
        for (const c of components(glyph(g))) if (!keep.has(c) && c < num) todo.push(c);
    }
    let glen = 0;
    for (let g = 0; g < num; g++) if (keep.has(g)) { const l = offs[g + 1] - offs[g]; glen += l + pad4(l); }
    const glyf = new Uint8Array(glen), loca = new Uint8Array((num + 1) * 4), ld = dvOf(loca);
    let pos = 0;
    for (let g = 0; g < num; g++) {
        ld.setUint32(g * 4, pos);
        if (!keep.has(g)) continue;
        const d = glyph(g);
        glyf.set(d, pos);
        pos += d.length + pad4(d.length);
    }
    ld.setUint32(num * 4, pos);
    const head = new Uint8Array(headIn);   // checkSumAdjustment = 0, indexToLocFormat = 1
    head.fill(0, 8, 12);
    dvOf(head).setUint16(50, 1);
    t.set('glyf', glyf); t.set('loca', loca); t.set('head', head);
    const post = t.get('post');
    if (post && post.length >= 32) { const p = new Uint8Array(32); p.set(post.subarray(0, 32)); dvOf(p).setUint32(0, 0x00030000); t.set('post', p); }
    const tags = [...t.keys()].filter(k => KEEP.includes(k)).sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const out = buildSfnt(0x00010000, tags.map(k => [k, t.get(k), sum(t.get(k))]));
    // checkSumAdjustment: 0xB1B0AFBA פחות סכום הקובץ כולו
    const hi = tags.indexOf('head');
    if (hi >= 0) { const dv = dvOf(out); dv.setUint32(dv.getUint32(12 + hi * 16 + 8) + 8, (0xB1B0AFBA - sum(out)) >>> 0); }
    return out;
}

// ---------- ToUnicode ותג ה-subset ----------

export function toUnicode(used) {
    const lines = [];
    for (const [gid, cp] of used) {
        if (cp < 0) continue;
        const u = cp > 0xffff ? [0xd800 + ((cp - 0x10000) >> 10), 0xdc00 + ((cp - 0x10000) & 0x3ff)] : [cp];
        lines.push(`<${gid.toString(16).toUpperCase().padStart(4, '0')}> <${u.map(x => x.toString(16).toUpperCase().padStart(4, '0')).join('')}>`);
    }
    let out = '/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n'
        + '/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n';
    for (let i = 0; i < lines.length; i += 100) { const ch = lines.slice(i, i + 100); out += `${ch.length} beginbfchar\n${ch.join('\n')}\nendbfchar\n`; }
    return out + 'endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend';
}

/** שש אותיות מ-md5 של השם — כמו PdfWriter::subsetTag */
export function subsetTag(name) {
    let h = parseInt(md5(name).slice(0, 8), 16), t = '';
    for (let i = 0; i < 6; i++) { t += String.fromCharCode(65 + h % 26); h = Math.floor(h / 26); }
    return t;
}

/** md5 של מחרוזת (UTF-8) → hex. קצר, בלי תלויות — רק לתג ה-subset */
export function md5(str) {
    const msg = new TextEncoder().encode(str);
    const len = ((msg.length + 8) >> 6) + 1;
    const words = new Uint32Array(len * 16);
    for (let i = 0; i < msg.length; i++) words[i >> 2] |= msg[i] << ((i % 4) * 8);
    words[msg.length >> 2] |= 0x80 << ((msg.length % 4) * 8);
    words[len * 16 - 2] = (msg.length * 8) >>> 0;
    words[len * 16 - 1] = Math.floor(msg.length / 0x20000000);
    const S = [7, 12, 17, 22, 5, 9, 14, 20, 4, 11, 16, 23, 6, 10, 15, 21];
    const K = Array.from({ length: 64 }, (_, i) => Math.floor(Math.abs(Math.sin(i + 1)) * 2 ** 32) >>> 0);
    let a0 = 0x67452301, b0 = 0xefcdab89, c0 = 0x98badcfe, d0 = 0x10325476;
    for (let o = 0; o < words.length; o += 16) {
        let a = a0, b = b0, c = c0, d = d0;
        for (let i = 0; i < 64; i++) {
            const r = i >> 4;
            let f, g;
            if (r === 0) { f = (b & c) | (~b & d); g = i; }
            else if (r === 1) { f = (d & b) | (~d & c); g = (5 * i + 1) % 16; }
            else if (r === 2) { f = b ^ c ^ d; g = (3 * i + 5) % 16; }
            else { f = c ^ (b | ~d); g = (7 * i) % 16; }
            const tmp = d;
            d = c; c = b;
            const x = (a + f + K[i] + words[o + g]) >>> 0, s = S[r * 4 + (i % 4)];
            b = (b + ((x << s) | (x >>> (32 - s)))) >>> 0;
            a = tmp;
        }
        a0 = (a0 + a) >>> 0; b0 = (b0 + b) >>> 0; c0 = (c0 + c) >>> 0; d0 = (d0 + d) >>> 0;
    }
    return [a0, b0, c0, d0].map(v => [0, 8, 16, 24].map(s => ((v >>> s) & 255).toString(16).padStart(2, '0')).join('')).join('');
}
