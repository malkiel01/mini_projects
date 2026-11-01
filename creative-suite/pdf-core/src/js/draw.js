/**
 * pdf-core ב-JS (שלב 3, #971): רשימת ציור → PDF, בדפדפן ובלי שרת — אותה רשימה ואותן הודעות כמו draw.php.
 * (pdf-core — עורכים ב-creative-suite/pdf-core/src ומריצים tools/sync.php)
 *
 *   import { pdfDraw } from './pdf-core/draw.js';
 *   const bytes = await pdfDraw(list, key => bytesOf(key));   // Uint8Array; resolve — Uint8Array / ArrayBuffer / Promise / null
 *
 * נתמך: image (PNG, JPEG), rect, line, path, clip … restore, מעבר צבע (גם שקיפות בעצירות), שקיפות — כמו ב-PHP, וזרם התוכן של כל עמוד
 * זהה לזה של draw.php (נבדק ב-tests/draw.test.mjs). **glyphs — עוד לא ב-JS** (גופנים ו-subset — רק כשיידרש); מי
 * שצריך טקסט ממיר אותו לקווי מתאר (path), או מצייר בשרת.
 * שונה מ-PHP: תמונת PNG לא מוקטנת (ב-PHP — עד 1600 בצלע), והשקיפות שלה מדויקת (ב-GD — 7 סיביות).
 */

const enc = new TextEncoder();
const bad = msg => new TypeError(msg);   // כמו InvalidArgumentException ב-PHP

/** מספר ל-PDF — כמו PdfWriter::n: שתי ספרות אחרי הנקודה, בלי אפסים מיותרים, ובלי "-0" */
export function n(v) {
    const r = Math.round(Math.abs(v) * 100 + 1e-9) / 100;
    if (r === 0) return '0';
    const s = r.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
    return v < 0 ? '-' + s : s;
}
// ההופכית של clip — שש ספרות, כמו sprintf('%.6F') ב-PHP
const n6 = v => { const t = v.toFixed(6).replace(/0+$/, '').replace(/\.$/, ''); return t === '-0' ? '0' : t; };

const textHex = s => {   // UTF-16BE עם BOM, כמו PdfWriter::text
    let h = 'FEFF';
    for (const ch of s) {
        const cp = ch.codePointAt(0);
        if (cp > 0xffff) { const v = cp - 0x10000; h += ((0xd800 + (v >> 10)).toString(16) + (0xdc00 + (v & 0x3ff)).toString(16)).toUpperCase(); }
        else h += cp.toString(16).padStart(4, '0').toUpperCase();
    }
    return `<${h}>`;
};

async function pipe(bytes, stream) {
    const out = new Response(new Blob([bytes]).stream().pipeThrough(stream));
    return new Uint8Array(await out.arrayBuffer());
}
const deflate = b => pipe(b, new CompressionStream('deflate'));      // zlib, כמו gzcompress
const inflate = b => pipe(b, new DecompressionStream('deflate'));

const asBytes = async v => {
    v = await v;
    if (v instanceof Uint8Array) return v;
    if (v instanceof ArrayBuffer) return new Uint8Array(v);
    if (ArrayBuffer.isView(v)) return new Uint8Array(v.buffer, v.byteOffset, v.byteLength);
    return null;
};

// ---------- תמונות ----------

function jpegInfo(b) {
    if (b[0] !== 0xff || b[1] !== 0xd8) return null;
    for (let i = 2; i + 9 < b.length;) {
        if (b[i] !== 0xff) return null;
        const m = b[i + 1];
        const len = (b[i + 2] << 8) | b[i + 3];
        if ((m >= 0xc0 && m <= 0xcf) && m !== 0xc4 && m !== 0xc8 && m !== 0xcc)
            return { h: (b[i + 5] << 8) | b[i + 6], w: (b[i + 7] << 8) | b[i + 8], ch: b[i + 9] };
        i += 2 + len;
    }
    return null;
}

/** PNG → { w, h, rgb, alpha | null } (8 / 16 סיביות, וגם פלטה ואפור ב-1 / 2 / 4); interlace — לא נתמך (null) */
async function pngDecode(b) {
    const sig = [137, 80, 78, 71, 13, 10, 26, 10];
    if (sig.some((v, i) => b[i] !== v)) return null;
    const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    let w = 0, h = 0, depth = 0, type = 0, interlace = 0, plte = null, trns = null;
    const idat = [];
    for (let i = 8; i + 8 <= b.length;) {
        const len = dv.getUint32(i), kind = String.fromCharCode(b[i + 4], b[i + 5], b[i + 6], b[i + 7]);
        const data = b.subarray(i + 8, i + 8 + len);
        if (kind === 'IHDR') { w = dv.getUint32(i + 8); h = dv.getUint32(i + 12); depth = b[i + 16]; type = b[i + 17]; interlace = b[i + 20]; }
        else if (kind === 'PLTE') plte = data;
        else if (kind === 'tRNS') trns = data;
        else if (kind === 'IDAT') idat.push(data);
        else if (kind === 'IEND') break;
        i += 12 + len;
    }
    if (!w || !h || interlace) return null;
    const chans = { 0: 1, 2: 3, 3: 1, 4: 2, 6: 4 }[type];
    if (!chans || ![1, 2, 4, 8, 16].includes(depth)) return null;
    const joined = new Uint8Array(idat.reduce((s, d) => s + d.length, 0));
    idat.reduce((o, d) => (joined.set(d, o), o + d.length), 0);
    const raw = await inflate(joined);
    const bpp = Math.max(1, (chans * depth) >> 3);           // בתים לפיקסל (לסינון)
    const stride = Math.ceil((w * chans * depth) / 8);
    const px = new Uint8Array(stride * h);
    let prev = new Uint8Array(stride);
    for (let y = 0, p = 0; y < h; y++) {
        const f = raw[p++], line = raw.subarray(p, p + stride), cur = px.subarray(y * stride, (y + 1) * stride);
        p += stride;
        for (let x = 0; x < stride; x++) {
            const a = x >= bpp ? cur[x - bpp] : 0, up = prev[x], c = x >= bpp ? prev[x - bpp] : 0;
            let v = line[x];
            if (f === 1) v += a; else if (f === 2) v += up; else if (f === 3) v += (a + up) >> 1;
            else if (f === 4) { const pp = a + up - c, pa = Math.abs(pp - a), pb = Math.abs(pp - up), pc = Math.abs(pp - c); v += pa <= pb && pa <= pc ? a : pb <= pc ? up : c; }
            cur[x] = v & 255;
        }
        prev = cur;
    }
    const sample = (row, i) => {   // הערך ה-i בשורה, מנורמל ל-8 סיביות (פלטה — האינדקס עצמו)
        if (depth === 8) return row[i];
        if (depth === 16) return row[i * 2];
        const per = 8 / depth, v = (row[(i / per) | 0] >> ((per - 1 - (i % per)) * depth)) & ((1 << depth) - 1);
        return type === 3 ? v : Math.round((v * 255) / ((1 << depth) - 1));
    };
    const rgb = new Uint8Array(w * h * 3), alpha = new Uint8Array(w * h);
    let hasAlpha = false;
    for (let y = 0; y < h; y++) {
        const row = px.subarray(y * stride, (y + 1) * stride);
        for (let x = 0; x < w; x++) {
            const o = y * w + x;
            let r, g, bl, al = 255;
            if (type === 3) {
                const k = sample(row, x);
                [r, g, bl] = plte ? [plte[k * 3], plte[k * 3 + 1], plte[k * 3 + 2]] : [0, 0, 0];
                if (trns && k < trns.length) al = trns[k];
            } else if (type === 0 || type === 4) {
                r = g = bl = sample(row, x * chans);
                if (type === 4) al = sample(row, x * chans + 1);
            } else {
                r = sample(row, x * chans); g = sample(row, x * chans + 1); bl = sample(row, x * chans + 2);
                if (type === 6) al = sample(row, x * chans + 3);
            }
            rgb[o * 3] = r; rgb[o * 3 + 1] = g; rgb[o * 3 + 2] = bl;
            alpha[o] = al;
            if (al < 255) hasAlpha = true;
        }
    }
    return { w, h, rgb, alpha: hasAlpha ? alpha : null };
}

// ---------- הכותב ----------

class Writer {
    constructor() { this.pages = []; this.images = new Map(); this.gstates = new Map(); this.patterns = []; this.masks = []; }

    addPage(w, h, bleed = 0) {
        this.pages.push({ w: w + 2 * bleed, h: h + 2 * bleed, bleed, main: '' });
        return this.pages.length - 1;
    }

    alpha(fill = 1, stroke = 1) {
        const key = n(Math.max(0, Math.min(1, fill))) + ' ' + n(Math.max(0, Math.min(1, stroke)));
        if (!this.gstates.has(key)) this.gstates.set(key, '/GS' + (this.gstates.size + 1));
        return this.gstates.get(key);
    }

    softMask(shading) {
        this.masks.push(shading);
        return '/SM' + this.masks.length;
    }

    pattern(shading, matrix) {
        const name = '/P' + (this.patterns.length + 1);
        this.patterns.push([name, shading, matrix]);
        return name;
    }

    /** תמונה → { name, w, h } או null (פורמט שלא נתמך) */
    async image(bytes) {
        const key = await crypto.subtle.digest('SHA-256', bytes).then(d => [...new Uint8Array(d)].map(x => x.toString(16).padStart(2, '0')).join(''));
        if (this.images.has(key)) return this.images.get(key);
        const name = '/Im' + (this.images.size + 1);
        const j = jpegInfo(bytes);
        let im;
        if (j) {
            const cs = j.ch === 1 ? '/DeviceGray' : j.ch === 4 ? '/DeviceCMYK' : '/DeviceRGB';
            im = { name, w: j.w, h: j.h, dict: `/Type /XObject /Subtype /Image /Width ${j.w} /Height ${j.h} /ColorSpace ${cs} /BitsPerComponent 8 /Filter /DCTDecode`
                + (j.ch === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : ''), data: bytes, smask: null };
        } else {
            const p = await pngDecode(bytes).catch(() => null);
            if (!p) return null;
            im = { name, w: p.w, h: p.h, dict: `/Type /XObject /Subtype /Image /Width ${p.w} /Height ${p.h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode`,
                data: await deflate(p.rgb), smask: p.alpha ? await deflate(p.alpha) : null };
        }
        this.images.set(key, im);
        return im;
    }

    async output(title = '') {
        const objs = [];   // [מספר] → מחרוזת או [מחרוזת, bytes, מחרוזת]
        let next = 1;
        const alloc = () => next++;
        const stream = (dict, data, flate = false) => [`<< ${dict}${flate ? ' /Filter /FlateDecode' : ''} /Length ${data.length} >>\nstream\n`, data, '\nendstream'];
        const catalog = alloc(), pagesId = alloc(), info = alloc();
        let imgRefs = '';
        for (const im of this.images.values()) {
            const id = alloc();
            let smask = '';
            if (im.smask) {
                const sid = alloc();
                objs[sid] = stream(`/Type /XObject /Subtype /Image /Width ${im.w} /Height ${im.h} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode`, im.smask);
                smask = ` /SMask ${sid} 0 R`;
            }
            objs[id] = stream(im.dict + smask, im.data);
            imgRefs += `${im.name} ${id} 0 R `;
        }
        const res = alloc();
        let pat = '';
        for (const [name, sh, mx] of this.patterns) {
            const id = alloc();
            objs[id] = `<< /Type /Pattern /PatternType 2 /Shading ${sh} /Matrix [${mx.map(n).join(' ')}] >>`;
            pat += `${name} ${id} 0 R `;
        }
        let gs = '';
        for (const [key, name] of this.gstates) { const [ca, CA] = key.split(' '); gs += `${name} << /Type /ExtGState /ca ${ca} /CA ${CA} >> `; }
        for (const [i, sh] of this.masks.entries()) {
            const id = alloc();
            objs[id] = stream('/Type /XObject /Subtype /Form /BBox [-10000 -10000 10000 10000] /Group << /Type /Group /S /Transparency /CS /DeviceGray >>'
                + ` /Resources << /Shading << /Sh0 ${sh} >> >>`, enc.encode('/Sh0 sh'));
            gs += `/SM${i + 1} << /Type /ExtGState /SMask << /Type /Mask /S /Luminosity /G ${id} 0 R >> >> `;
        }
        objs[res] = `<< /Font << >> /XObject << ${imgRefs}>>` + (gs ? ` /ExtGState << ${gs}>>` : '') + (pat ? ` /Pattern << ${pat}>>` : '') + ' /ProcSet [/PDF /Text /ImageB /ImageC] >>';
        const pids = this.pages.map(() => alloc());
        const kids = [];
        for (const [pn, p] of this.pages.entries()) {
            const pid = pids[pn], cid = alloc();
            objs[cid] = stream('', await deflate(enc.encode(`q\nQ\nq\n${p.main}Q\nq\nQ\n`)), true);
            const b = p.bleed;
            const box = b > 0 ? ` /BleedBox [0 0 ${n(p.w)} ${n(p.h)}] /TrimBox [${[b, b, p.w - b, p.h - b].map(n).join(' ')}]` : '';
            objs[pid] = `<< /Type /Page /Parent ${pagesId} 0 R /MediaBox [0 0 ${n(p.w)} ${n(p.h)}]${box} /Resources ${res} 0 R /Contents ${cid} 0 R >>`;
            kids.push(`${pid} 0 R`);
        }
        objs[pagesId] = `<< /Type /Pages /Kids [${kids.join(' ')}] /Count ${kids.length} >>`;
        objs[catalog] = `<< /Type /Catalog /Pages ${pagesId} 0 R /ViewerPreferences << /Direction /R2L /DisplayDocTitle true >> /Lang (he-IL) >>`;
        const d = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
        objs[info] = `<< /Title ${textHex(title)} /Producer (pdf-core) /CreationDate (D:${d}Z) >>`;

        const parts = [enc.encode('%PDF-1.7\n'), new Uint8Array([0x25, 0xe2, 0xe3, 0xcf, 0xd3, 0x0a])];
        let size = parts[0].length + parts[1].length;
        const offsets = [];
        const push = x => { const u = typeof x === 'string' ? enc.encode(x) : x; parts.push(u); size += u.length; };
        for (let i = 1; i < next; i++) {
            offsets[i] = size;
            push(`${i} 0 obj\n`);
            const o = objs[i];
            if (Array.isArray(o)) o.forEach(push); else push(o);
            push('\nendobj\n');
        }
        const xref = size;
        let tail = `xref\n0 ${next}\n0000000000 65535 f \n`;
        for (let i = 1; i < next; i++) tail += String(offsets[i]).padStart(10, '0') + ' 00000 n \n';
        push(tail + `trailer\n<< /Size ${next} /Root ${catalog} 0 R /Info ${info} 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
        const out = new Uint8Array(size);
        parts.reduce((o, u) => (out.set(u, o), o + u.length), 0);
        return out;
    }
}

// ---------- רשימת הציור ----------

/** @param {(key: string) => Uint8Array|ArrayBuffer|null|Promise<Uint8Array|ArrayBuffer|null>} resolve */
export async function pdfDraw(list, resolve) {
    const w = new Writer();
    const isNum = v => typeof v === 'number' && Number.isFinite(v);
    const num = (v, where) => { if (!isNum(v)) throw bad(`מספר חסר: ${where}`); return v; };
    const unit = (v, where) => { const x = num(v, where); if (x < 0 || x > 1) throw bad(`ערך בין 0 ל-1: ${where}`); return x; };
    const box = (v, where) => { if (!Array.isArray(v) || v.length !== 4) throw bad(`מלבן [x, y, w, h] חסר: ${where}`); return v.map(x => num(x, where)); };
    const color = (v, where) => {
        if (typeof v !== 'string' || !/^#[0-9a-f]{6}$/i.test(v)) throw bad(`צבע #rrggbb: ${where}`);
        return [1, 3, 5].map(i => n(parseInt(v.slice(i, i + 2), 16) / 255)).join(' ');
    };
    const bytes = async (key, what) => {
        const b = await asBytes(resolve(key));
        if (!b || !b.length) throw bad(`${what} חסר: ${key}`);
        return b;
    };
    const matrix = (t, where, b, H) => {
        t ??= [1, 0, 0, 1, 0, 0];
        if (!Array.isArray(t) || t.length !== 6) throw bad(`transform [a, b, c, d, e, f]: ${where}`);
        const [ta, tb, tc, td, te, tf] = t.map(v => num(v, where));
        return [ta, -tb, tc, -td, te + b, H - b - tf];
    };
    const inverse = ([a, b, c, d, e, f], where) => {
        const det = a * d - b * c;
        if (Math.abs(det) < 1e-12) throw bad(`transform לא הפיך: ${where}`);
        return [d / det, -b / det, -c / det, a / det, (c * f - d * e) / det, (b * e - a * f) / det];
    };
    // alpha — אותו מעבר באפור, מהאטימות של העצירות (למסכה הרכה)
    const shading = (g, where, alpha = false) => {
        if (!g || typeof g !== 'object' || Array.isArray(g)) throw bad(`gradient: ${where}`);
        const stops = g.stops;
        if (!Array.isArray(stops) || !stops.length) throw bad(`gradient: stops חסרים: ${where}`);
        let st = stops.map(k => {
            if (!k || typeof k !== 'object') throw bad(`gradient: עצירה { offset, color }: ${where}`);
            const c = color(k.color ?? null, where);
            const a = k.opacity !== undefined && k.opacity !== null ? unit(k.opacity, where) : 1;
            return [Math.max(0, Math.min(1, num(k.offset ?? null, where))), alpha ? n(a) : c];
        });
        st = st.map((s, i) => [s, i]).sort((x, y) => x[0][0] - y[0][0] || x[1] - y[1]).map(x => x[0]);   // יציב, כמו usort
        if (st[0][0] > 0) st.unshift([0, st[0][1]]);
        if (st[st.length - 1][0] < 1) st.push([1, st[st.length - 1][1]]);
        const seg = (c0, c1) => `<< /FunctionType 2 /Domain [0 1] /C0 [${c0}] /C1 [${c1}] /N 1 >>`;
        let fn;
        if (st.length === 2) fn = seg(st[0][1], st[1][1]);
        else {
            const parts = [], bounds = [], encd = [];
            for (let i = 0; i < st.length - 1; i++) { parts.push(seg(st[i][1], st[i + 1][1])); encd.push('0 1'); if (i) bounds.push(n(st[i][0])); }
            fn = `<< /FunctionType 3 /Domain [0 1] /Functions [${parts.join(' ')}] /Bounds [${bounds.join(' ')}] /Encode [${encd.join(' ')}] >>`;
        }
        let coords, stype;
        if (g.type === 'linear') { coords = [g.x1 ?? null, g.y1 ?? null, g.x2 ?? null, g.y2 ?? null]; stype = 2; }
        else if (g.type === 'radial') {
            const cx = g.cx ?? null, cy = g.cy ?? null;
            const r = num(g.r ?? null, where);
            if (r <= 0) throw bad(`gradient: r חיובי: ${where}`);
            coords = [g.fx ?? cx, g.fy ?? cy, 0, cx, cy, r]; stype = 3;
        } else throw bad(`gradient: type — linear / radial: ${where}`);
        return `<< /ShadingType ${stype} /ColorSpace ${alpha ? '/DeviceGray' : '/DeviceRGB'} /Coords [${coords.map(v => n(num(v, where))).join(' ')}] /Function ${fn} /Extend [true true] >>`;
    };
    // מעבר צבע עם שקיפות באחת העצירות — צריך מסכה רכה
    const masked = v => !!v && typeof v === 'object' && Array.isArray(v.gradient?.stops)
        && v.gradient.stops.some(k => k && typeof k === 'object' && typeof k.opacity === 'number' && k.opacity < 1);
    const paint = (v, stroke, where, m) => {
        if (v && typeof v === 'object' && !Array.isArray(v) && 'gradient' in v)
            return `/Pattern ${stroke ? 'CS ' : 'cs '}${w.pattern(shading(v.gradient, where), m)}${stroke ? ' SCN' : ' scn'}`;
        return color(v, where) + (stroke ? ' RG' : ' rg');
    };
    const NUM = /[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/;
    const pathOps = (d, where) => {
        if (typeof d !== 'string' || d.trim() === '') throw bad(`d חסר: ${where}`);
        const tok = d.match(new RegExp(`[A-Za-z]|${NUM.source}`, 'g')) || [];
        if (!tok.length || d.replace(new RegExp(`[A-Za-z]|${NUM.source}|[\\s,]+`, 'g'), '') !== '') throw bad(`d לא תקין: ${where}`);
        const need = { M: 2, L: 2, C: 6, Q: 4, Z: 0 };
        let out = '', cmd = null, cx = 0, cy = 0, sx = 0, sy = 0, open = false;
        for (let i = 0; i < tok.length;) {
            if (/^[A-Za-z]$/.test(tok[i])) {
                cmd = tok[i++];
                if (!(cmd in need)) throw bad(`פקודה "${cmd}" — רק M L C Q Z מוחלטות: ${where}`);
                if (cmd === 'Z') { if (open) out += 'h\n'; cx = sx; cy = sy; continue; }
            } else if (cmd === null || cmd === 'Z') throw bad(`d חייב להתחיל בפקודה: ${where}`);
            const a = [];
            for (let j = 0; j < need[cmd]; j++) {
                if (i >= tok.length || /^[A-Za-z]$/.test(tok[i])) throw bad(`חסרים מספרים אחרי ${cmd}: ${where}`);
                a.push(parseFloat(tok[i++]));
            }
            if (cmd !== 'M' && !open) throw bad(`נתיב בלי M בהתחלה: ${where}`);
            if (cmd === 'M') { out += `${n(a[0])} ${n(a[1])} m\n`; [cx, cy, sx, sy] = [a[0], a[1], a[0], a[1]]; open = true; cmd = 'L'; }
            else if (cmd === 'L') { out += `${n(a[0])} ${n(a[1])} l\n`; [cx, cy] = a; }
            else if (cmd === 'C') { out += a.map(n).join(' ') + ' c\n'; [cx, cy] = [a[4], a[5]]; }
            else {   // Q → C: נקודות הבקרה ב-2/3 מהקצוות אל נקודת הבקרה
                const [qx, qy, x, y] = a;
                out += [cx + 2 / 3 * (qx - cx), cy + 2 / 3 * (qy - cy), x + 2 / 3 * (qx - x), y + 2 / 3 * (qy - y), x, y].map(n).join(' ') + ' c\n';
                [cx, cy] = [x, y];
            }
        }
        if (!open) throw bad(`d בלי נקודות: ${where}`);
        return out;
    };

    const pages = list?.pages;
    if (!Array.isArray(pages) || !pages.length) throw bad('אין עמודים');
    for (const [pi, page] of pages.entries()) {
        const at = `עמוד ${pi + 1}`;
        const size = page?.size;
        if (!Array.isArray(size) || size.length !== 2) throw bad(`size [w, h]: ${at}`);
        const [pw, ph] = [num(size[0], at), num(size[1], at)];
        const b = page.bleed !== undefined && page.bleed !== null ? Math.max(0, num(page.bleed, at)) : 0;
        if (pw <= 0 || ph <= 0) throw bad(`size [w, h]: ${at}`);
        const idx = w.addPage(pw, ph, b);
        const H = ph + 2 * b;
        const X = x => x + b;
        const Y = y => H - (y + b);
        let c = '', depth = 0;
        for (const [oi, op] of (page.ops || []).entries()) {
            const where = `${at}, פעולה ${oi + 1}`;
            switch (op?.op ?? '') {
                case 'glyphs':
                    throw bad(`glyphs — עוד לא ב-pdf-core של JS (טקסט כקווי מתאר — path, או ציור בשרת): ${where}`);
                case 'image': {
                    const im = await w.image(await bytes(String(op.src ?? ''), 'תמונה'));
                    if (!im) throw bad(`תמונה בפורמט שלא נתמך: ${where}`);
                    const [x, y, bw, bh] = box(op.box ?? null, where);
                    c += 'q\n';
                    if (op.opacity !== undefined && op.opacity !== null) { const o = unit(op.opacity, where); if (o < 1) c += w.alpha(o, o) + ' gs\n'; }
                    if (op.clip !== undefined) {
                        const [cx, cy, cw, ch] = box(op.clip, where);
                        c += `${n(X(cx))} ${n(Y(cy + ch))} ${n(cw)} ${n(ch)} re W n\n`;
                    }
                    c += `${n(bw)} 0 0 ${n(bh)} ${n(X(x))} ${n(Y(y + bh))} cm ${im.name} Do\nQ\n`;
                    break;
                }
                case 'rect': {
                    const [x, y, bw, bh] = box(op.box ?? null, where);
                    const fill = op.fill !== undefined && op.fill !== null ? color(op.fill, where) : null;
                    const stroke = op.stroke !== undefined && op.stroke !== null ? color(op.stroke, where) : null;
                    if (fill === null && stroke === null) throw bad(`rect בלי fill ובלי stroke: ${where}`);
                    c += 'q\n' + (fill !== null ? `${fill} rg\n` : '') + (stroke !== null ? `${stroke} RG ${n(num(op.width ?? 1, where))} w\n` : '')
                        + `${n(X(x))} ${n(Y(y + bh))} ${n(bw)} ${n(bh)} re ${fill !== null && stroke !== null ? 'B' : fill !== null ? 'f' : 'S'}\nQ\n`;
                    break;
                }
                case 'line': {
                    const from = op.from, to = op.to;
                    if (!Array.isArray(from) || from.length !== 2 || !Array.isArray(to) || to.length !== 2) throw bad(`from / to [x, y]: ${where}`);
                    c += `q\n${color(op.color ?? '#000000', where)} RG ${n(num(op.width ?? 1, where))} w\n`
                        + `${n(X(num(from[0], where)))} ${n(Y(num(from[1], where)))} m ${n(X(num(to[0], where)))} ${n(Y(num(to[1], where)))} l S\nQ\n`;
                    break;
                }
                case 'path': {
                    const hasFill = op.fill !== undefined && op.fill !== null;
                    const hasStroke = op.stroke !== undefined && op.stroke !== null;
                    if (!hasFill && !hasStroke) throw bad(`path בלי fill ובלי stroke: ${where}`);
                    const m = matrix(op.transform ?? null, where, b, H);
                    const rule = op.fillRule ?? 'nonzero';
                    if (rule !== 'nonzero' && rule !== 'evenodd') throw bad(`fillRule: nonzero / evenodd: ${where}`);
                    const eo = rule === 'evenodd' ? '*' : '';
                    const d = pathOps(op.d ?? null, where);
                    const fo = op.fillOpacity !== undefined && op.fillOpacity !== null ? unit(op.fillOpacity, where) : 1;
                    const so = op.strokeOpacity !== undefined && op.strokeOpacity !== null ? unit(op.strokeOpacity, where) : 1;
                    // מסכה רכה חלה על כל מה שנצבע אחריה — מילוי וקו עם מסכה נצבעים בנפרד (קודם המילוי, כמו ב-B)
                    const parts = hasFill && hasStroke && (masked(op.fill) || masked(op.stroke)) ? [[true, false], [false, true]] : [[hasFill, hasStroke]];
                    for (const [pf, ps] of parts) {
                        c += 'q\n' + m.map(n).join(' ') + ' cm\n';
                        if ((pf && fo < 1) || (ps && so < 1)) c += w.alpha(pf ? fo : 1, ps ? so : 1) + ' gs\n';
                        const mk = pf ? op.fill : op.stroke;
                        if (masked(mk)) c += w.softMask(shading(mk.gradient, where, true)) + ' gs\n';
                        if (pf) c += paint(op.fill, false, where, m) + '\n';
                        if (ps) {
                            const caps = { butt: 0, round: 1, square: 2 }, joins = { miter: 0, round: 1, bevel: 2 };
                            const cap = op.cap ?? 'butt', join = op.join ?? 'miter';
                            if (!(cap in caps) || !(join in joins)) throw bad(`cap / join: ${where}`);
                            c += paint(op.stroke, true, where, m) + ` ${n(num(op.width ?? 1, where))} w ${caps[cap]} J ${joins[join]} j ${n(Math.max(1, num(op.miter ?? 4, where)))} M\n`;
                            const dash = op.dash ?? [];
                            if (!Array.isArray(dash)) throw bad(`dash [on, off, …]: ${where}`);
                            if (dash.length) c += `[${dash.map(v => n(Math.max(0, num(v, where)))).join(' ')}] 0 d\n`;
                        }
                        c += d + (pf && ps ? `B${eo}` : pf ? `f${eo}` : 'S') + '\nQ\n';
                    }
                    break;
                }
                case 'clip': {
                    const m = matrix(op.transform ?? null, where, b, H);
                    const rule = op.fillRule ?? 'nonzero';
                    if (rule !== 'nonzero' && rule !== 'evenodd') throw bad(`fillRule: nonzero / evenodd: ${where}`);
                    c += 'q\n' + m.map(n).join(' ') + ' cm\n' + pathOps(op.d ?? null, where) + (rule === 'evenodd' ? 'W*' : 'W') + ' n\n'
                        + inverse(m, where).map(n6).join(' ') + ' cm\n';
                    depth++;
                    break;
                }
                case 'restore':
                    if (depth === 0) throw bad(`restore בלי clip פתוח: ${where}`);
                    c += 'Q\n';
                    depth--;
                    break;
                default:
                    throw bad(`פעולה לא מוכרת "${typeof op?.op === 'string' ? op.op : ''}": ${where}`);
            }
        }
        w.pages[idx].main = c + 'Q\n'.repeat(depth);
    }
    return w.output(String(list.title ?? ''));
}
