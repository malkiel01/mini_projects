/**
 * pdf-core: JS מול PHP (שלב 3, #971) — אותה רשימת ציור בשתי השפות, ואותו PDF במבנה (לא בבתים):
 * זרמי התוכן של כל עמוד זהים, המשאבים (ExtGState, Pattern, XObject) זהים, אובייקטי התבנית והתמונות זהים,
 * הודעות השגיאה זהות — ו-pdftoppm מצייר את שניהם אותו דבר.
 *   node creative-suite/pdf-core/tests/draw.test.mjs
 */
import { execFileSync } from 'node:child_process';
import { inflateSync } from 'node:zlib';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { pdfDraw, n } from '../src/js/draw.js';

const here = dirname(fileURLToPath(import.meta.url));
const drawPhp = join(here, '../src/php/draw.php');
let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => {
    if (cond) { pass++; console.log(`  ✓ ${name}`); }
    else { fail++; console.log(`  ✗ ${name}${detail ? ' — ' + String(detail).slice(0, 400) : ''}`); }
};

// PHP: אותה רשימה, ואותם קבצים (base64) → ה-PDF, או ERR:הודעה
const php = (list, media = {}) => execFileSync('php', ['-r', `
    require ${JSON.stringify(drawPhp)};
    $in = json_decode(stream_get_contents(STDIN), true);
    $media = array_map('base64_decode', $in['media']);
    try { echo pdf_draw($in['list'], fn($k) => $media[$k] ?? null); }
    catch (InvalidArgumentException $e) { echo 'ERR:' . $e->getMessage(); }`], { input: JSON.stringify({ list, media: Object.fromEntries(Object.entries(media).map(([k, v]) => [k, Buffer.from(v).toString('base64')])) }), maxBuffer: 64 << 20 });
const js = async (list, media = {}) => {
    try { return Buffer.from(await pdfDraw(list, k => media[k] ?? null)); }
    catch (e) { if (e instanceof TypeError) return Buffer.from('ERR:' + e.message); throw e; }
};

// פירוק PDF: אובייקטים לפי מספר; זרם — מפוענח
const parse = buf => {
    const s = buf.toString('latin1');
    const objs = {};
    for (const m of s.matchAll(/(\d+) 0 obj\n([\s\S]*?)\nendobj\n/g)) {
        const body = m[2];
        const st = body.match(/^(<<[\s\S]*?>>)\nstream\n([\s\S]*)\nendstream$/);
        if (st) {
            const raw = Buffer.from(st[2], 'latin1');
            let data = raw;
            if (/\/FlateDecode/.test(st[1]) && !/\/Subtype \/Image/.test(st[1])) data = inflateSync(raw);
            objs[m[1]] = { dict: st[1], data };
        } else objs[m[1]] = { dict: body };
    }
    return objs;
};
const norm = s => s.replace(/\d+ 0 R/g, 'R').replace(/ \/Length \d+/g, '');
const shape = buf => {
    const objs = parse(buf);
    const pages = Object.values(objs).filter(o => /^<< \/Type \/Page /.test(o.dict));
    const contents = pages.map(p => objs[p.dict.match(/\/Contents (\d+) 0 R/)[1]].data.toString('latin1'));
    const res = Object.values(objs).find(o => /^<< \/Font/.test(o.dict))?.dict || '';
    return {
        contents,
        boxes: pages.map(p => norm(p.dict)),
        res: norm(res),
        patterns: Object.values(objs).filter(o => /\/Type \/Pattern/.test(o.dict)).map(o => o.dict),
        images: Object.values(objs).filter(o => /\/Subtype \/Image/.test(o.dict)).map(o => norm(o.dict)),
        forms: Object.values(objs).filter(o => /\/Subtype \/Form/.test(o.dict)).map(o => norm(o.dict) + o.data.toString('latin1')),
        // גופנים: Type0 / CIDFontType2 / FontDescriptor, קובץ הגופן המוקטן (בבתים) ו-ToUnicode
        fonts: Object.values(objs).filter(o => /\/Type \/Font(Descriptor)? /.test(o.dict)).map(o => norm(o.dict)),
        fontFiles: Object.values(objs).filter(o => /\/Length1 /.test(o.dict)).map(o => norm(o.dict) + ' ' + o.data.toString('hex')),
        cmaps: Object.values(objs).filter(o => o.data && /begincmap/.test(o.data.toString('latin1'))).map(o => o.data.toString('latin1')),
    };
};

// בדיקה: אותו מבנה, ואותו ציור (pdftoppm, PPM — בלי ספריות)
const hasPoppler = (() => { try { execFileSync('pdftoppm', ['-v'], { stdio: 'ignore' }); return true; } catch { return false; } })();
const tmp = mkdtempSync(join(tmpdir(), 'pdfcore-'));
const raster = (buf, name) => {
    writeFileSync(join(tmp, name + '.pdf'), buf);
    execFileSync('pdftoppm', ['-r', '36', join(tmp, name + '.pdf'), join(tmp, name)]);
    return readFileSync(join(tmp, name + '-1.ppm'));
};
async function same(label, list, media = {}) {
    const [a, b] = [php(list, media), await js(list, media)];
    if (a.toString('latin1').startsWith('ERR:') || b.toString('latin1').startsWith('ERR:'))
        return ok(`${label}: שתי השפות מציירות`, false, `PHP: ${a.toString().slice(0, 120)} / JS: ${b.toString().slice(0, 120)}`);
    const [p, j] = [shape(a), shape(b)];
    ok(`${label}: זרמי התוכן זהים`, p.contents.length > 0 && p.contents.every(c => c.length > 20) && JSON.stringify(p.contents) === JSON.stringify(j.contents), firstDiff(p.contents.join('\n'), j.contents.join('\n')));
    ok(`${label}: עמודים, משאבים, תבניות, תמונות ומסכות — אותו מבנה`, JSON.stringify([p.boxes, p.res, p.patterns, p.images, p.forms]) === JSON.stringify([j.boxes, j.res, j.patterns, j.images, j.forms]),
        firstDiff(JSON.stringify([p.boxes, p.res, p.patterns, p.images, p.forms]), JSON.stringify([j.boxes, j.res, j.patterns, j.images, j.forms])));
    if (p.fonts.length || j.fonts.length) {
        ok(`${label}: גופנים — אותם אובייקטים (שם, רוחבים, מידות) ואותו ToUnicode`, JSON.stringify([p.fonts, p.cmaps]) === JSON.stringify([j.fonts, j.cmaps]), firstDiff(JSON.stringify([p.fonts, p.cmaps]), JSON.stringify([j.fonts, j.cmaps])));
        ok(`${label}: קובץ הגופן המוקטן (subset) — זהה בבתים`, p.fontFiles.length > 0 && JSON.stringify(p.fontFiles) === JSON.stringify(j.fontFiles), `PHP ${p.fontFiles.map(x => x.length)} / JS ${j.fontFiles.map(x => x.length)}`);
    }
    if (hasPoppler) {
        const [ra, rb] = [raster(a, label.replace(/\W+/g, '') + 'p'), raster(b, label.replace(/\W+/g, '') + 'j')];
        let diff = 0;
        for (let i = 0; i < Math.min(ra.length, rb.length); i++) diff = Math.max(diff, Math.abs(ra[i] - rb[i]));
        ok(`${label}: ציור (pdftoppm) — אותו דבר`, ra.length === rb.length && diff <= 8, `הפרש מרבי ${diff}`);
    }
}
function firstDiff(x, y) {
    let i = 0;
    while (i < x.length && x[i] === y[i]) i++;
    return i >= x.length && i >= y.length ? '' : `במקום ${i}: PHP «${x.slice(Math.max(0, i - 40), i + 60)}» / JS «${y.slice(Math.max(0, i - 40), i + 60)}»`;
}

// תמונות לבדיקה — מ-GD (כמו בבדיקות של העורך): PNG עם שקיפות, PNG אטום, JPEG
const img = kind => execFileSync('php', ['-r', `
    $im = imagecreatetruecolor(8, 6); imagesavealpha($im, true); imagealphablending($im, false);
    for ($y = 0; $y < 6; $y++) for ($x = 0; $x < 8; $x++) imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $x * 30, $y * 40, 200, ${kind === 'alpha' ? '$x < 4 ? 0 : 127' : '0'}));
    ob_start(); ${kind === 'jpeg' ? 'imagejpeg($im, null, 90)' : 'imagepng($im)'}; echo ob_get_clean();`]);
const media = { 'media/a.png': img('alpha'), 'media/b.png': img('opaque'), 'media/c.jpg': img('jpeg') };

const sq = (x, y, w, h) => `M${x} ${y} L${x + w} ${y} L${x + w} ${y + h} L${x} ${y + h} Z`;
const grad = (type, extra, stops) => ({ gradient: { type, ...extra, stops: stops.map(([offset, color]) => ({ offset, color })) } });

console.log('pdf-core: JS מול PHP');
ok('n(): כמו PdfWriter::n', [0, -0.001, 1.005, 2.5, -3.456, 100, 0.1 + 0.2, 1 / 3, -1e-9].map(n).join(' ') === '0 0 1.01 2.5 -3.46 100 0.3 0.33 0', [0, -0.001, 1.005, 2.5, -3.456, 100, 0.1 + 0.2, 1 / 3, -1e-9].map(n).join(' '));
await same('מלבנים וקווים, עם גלישה', { title: 'בדיקה', pages: [{ size: [300, 200], bleed: 9, ops: [
    { op: 'rect', box: [-9, -9, 318, 40], fill: '#336699' },
    { op: 'rect', box: [20, 160, 100, 20], stroke: '#0000ff', width: 2 },
    { op: 'rect', box: [130, 160, 50, 20], fill: '#ff0000', stroke: '#000000' },
    { op: 'line', from: [20, 150], to: [180, 150], color: '#00aa00', width: 1.5 },
]}] });
await same('path: מילוי בשקיפות, evenodd עם transform, Q → C, קו עם מקפים', { pages: [{ size: [100, 100], ops: [
    { op: 'path', d: sq(10, 10, 40, 40), fill: '#ff0000', fillOpacity: 0.5 },
    { op: 'path', d: 'M0 0 L40 0 L40 40 L0 40 Z M10 10 L30 10 L30 30 L10 30 Z', fill: '#000000', fillRule: 'evenodd', transform: [1, 0, 0, 1, 55, 55] },
    { op: 'path', d: 'M60 10 Q 80 40 95 10', stroke: '#0000ff', width: 2, cap: 'round', join: 'bevel', dash: [3, 2] },
    { op: 'path', d: 'M5 95 C 20 60, 40 60, 50 95', fill: '#00ff00', stroke: '#000000', strokeOpacity: 0.3, transform: [0.8, 0.2, -0.2, 0.8, 5, 0] },
]}] });
await same('clip מקונן, clip עם transform, ו-clip שלא נסגר', { pages: [{ size: [100, 100], ops: [
    { op: 'clip', d: sq(0, 0, 60, 100) },
    { op: 'clip', d: sq(40, 0, 60, 50), fillRule: 'evenodd' },
    { op: 'path', d: sq(0, 0, 100, 100), fill: '#000000' },
    { op: 'restore' }, { op: 'restore' },
    { op: 'clip', d: sq(0, 0, 10, 10), transform: [2, 0, 0, 2, 0, 60] },
    { op: 'path', d: sq(5, 65, 10, 10), fill: '#000000' },
]}] });
await same('מעברי צבע: לינארי בשלוש עצירות, רדיאלי עם מוקד, קו, ועצירות לא ממוינות', { pages: [{ size: [100, 100], ops: [
    { op: 'path', d: sq(0, 0, 100, 20), transform: [1, 0, 0, 1, 0, 10], fill: grad('linear', { x1: 0, y1: 0, x2: 100, y2: 0 }, [[0, '#ff0000'], [0.5, '#00ff00'], [1, '#0000ff']]) },
    { op: 'path', d: sq(30, 40, 40, 40), fill: grad('radial', { cx: 50, cy: 60, r: 20, fx: 45, fy: 55 }, [[0, '#ffffff'], [1, '#000000']]), fillOpacity: 0.8 },
    { op: 'path', d: 'M0 95 L100 95', width: 6, stroke: grad('linear', { x1: 0, y1: 0, x2: 100, y2: 0 }, [[0.7, '#ff0000'], [0.2, '#000000']]) },
]}] });
await same('שקיפות בעצירות: מסכה רכה, ומילוי + קו בשני מעברים', { pages: [{ size: [100, 100], ops: [
    { op: 'rect', box: [0, 0, 100, 100], fill: '#000000' },
    { op: 'path', d: sq(0, 0, 100, 40), fill: { gradient: { type: 'linear', x1: 0, y1: 0, x2: 100, y2: 0, stops: [{ offset: 0, color: '#ff0000' }, { offset: 0.5, color: '#ffff00', opacity: 0.5 }, { offset: 1, color: '#ff0000', opacity: 0 }] } } },
    { op: 'path', d: sq(10, 55, 80, 30), width: 4, stroke: '#00ff00', fillOpacity: 0.9,
        fill: { gradient: { type: 'radial', cx: 50, cy: 70, r: 40, stops: [{ offset: 0, color: '#ffffff', opacity: 1 }, { offset: 1, color: '#ffffff', opacity: 0 }] } } },
    { op: 'path', d: 'M5 95 L95 95', width: 5, stroke: { gradient: { type: 'linear', x1: 0, y1: 0, x2: 100, y2: 0, stops: [{ offset: 0, color: '#0000ff', opacity: 0.2 }, { offset: 1, color: '#0000ff' }] } } },
]}] });
await same('תמונות: PNG עם שקיפות (SMask), PNG אטום, JPEG, חיתוך ו-opacity, transform — ואותה תמונה פעמיים', { pages: [{ size: [100, 100], ops: [
    { op: 'image', src: 'media/a.png', box: [5, 5, 40, 30], clip: [5, 5, 30, 30] },
    { op: 'image', src: 'media/b.png', box: [50, 5, 40, 30], opacity: 0.25 },
    { op: 'image', src: 'media/c.jpg', box: [5, 50, 40, 30] },
    { op: 'image', src: 'media/a.png', box: [50, 50, 40, 30] },
    { op: 'image', src: 'media/b.png', box: [10, 10, 20, 15], transform: [-1, 0, 0, 1, 100, 0], opacity: 0.5 },
    { op: 'image', src: 'media/c.jpg', box: [0, 0, 20, 15], transform: [0, 1, -1, 0, 60, 50], clip: [0, 0, 10, 15] },
]}, { size: [50, 50], ops: [{ op: 'rect', box: [0, 0, 50, 50], fill: '#eeeeee' }] }] }, media);

// glyphs (מ6): טקסט אמיתי מ-text-core — shapeLine עם ניקוד וטעמים ו-bidi, גופן חלופי ב-op נפרד (כמו בעימוד)
const { loadFont, shapeLine, fontMetrics } = await import('../../text-core/src/js/shaper.js');
const fontsDir = join(here, '../../../suite-cloud/fonts/builtin/');
const fontBytes = name => readFileSync(fontsDir + name);
const ab = b => b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength);
const fmedia = { 'media/noto.ttf': fontBytes('NotoSerifHebrew.ttf'), 'media/frank.ttf': fontBytes('FrankRuhlLibre.ttf'), 'media/Heebo.ttf': fontBytes('Heebo.ttf') };
const tc = { 'media/noto.ttf': await loadFont(ab(fmedia['media/noto.ttf']), 'noto'), 'media/frank.ttf': await loadFont(ab(fmedia['media/frank.ttf']), 'frank'),
    'media/Heebo.ttf': await loadFont(ab(fmedia['media/Heebo.ttf']), 'heebo') };
const keyOf = f => Object.keys(tc).find(k => tc[k] === f);
/** שורה → פעולות glyphs (op לכל רצף גופן, כמו layout-studio/assets/js/pdf.js) */
const line = (fonts, text, size, x, y, color = '#000000', base = 'rtl') => {
    const gl = shapeLine(fonts, text, base);
    const main = Array.isArray(fonts) ? fonts[0] : fonts;
    const s = size / fontMetrics(main).upem;
    const ops = [];
    let pen = 0, cur = null;
    for (const g of gl) {
        const f = g.f || main;
        if (!cur || cur.f !== f) { cur = { f, op: { op: 'glyphs', font: keyOf(f), size, color, x: x + pen, y, glyphs: [], text: ops.length ? '' : text } }; ops.push(cur); }
        // גליף מגופן חלופי — המידות כבר ביחידות של הראשי
        cur.op.glyphs.push([g.gid, g.dx * s, g.dy * s, g.ax * s]);
        pen += g.ax * s;
    }
    return ops.map(o => o.op);
};
const TAAMIM = 'בְּרֵאשִׁ֖ית בָּרָ֣א אֱלֹהִ֑ים';
await same('glyphs: עברית עם ניקוד, טעמים בגופן חלופי, שורה מעורבת, גלישה, ושני עמודים', { title: 'טקסט', pages: [{ size: [300, 160], bleed: 9, ops: [
    ...line(tc['media/noto.ttf'], 'בָּרוּךְ אַתָּה ה׳ אֱלֹהֵינוּ', 20, 20, 40),
    ...line([tc['media/frank.ttf'], tc['media/noto.ttf']], TAAMIM, 18, 20, 80, '#663300'),
    ...line(tc['media/Heebo.ttf'], 'מחיר: 3–4 ש"ח (PDF 1.7)', 14, 20, 120),
    { op: 'rect', box: [20, 130, 100, 2], fill: '#ff0000' },
]}, { size: [100, 50], ops: [...line(tc['media/Heebo.ttf'], 'עמוד 2', 12, 10, 30)] }] }, fmedia);
{
    // מה שנבנה מ-JS נקרא בחזרה: הטקסט בר-חיפוש (pdftotext), והגופנים מוטמעים כ-subset (pdffonts)
    const list = { pages: [{ size: [200, 60], ops: line(tc['media/Heebo.ttf'], 'שלום עולם', 16, 10, 30) }] };
    const b = await js(list, fmedia);
    writeFileSync(join(tmp, 'text.pdf'), b);
    const has = cmd => { try { execFileSync(cmd, ['-v'], { stdio: 'ignore' }); return true; } catch { return false; } };
    if (has('pdftotext')) {
        const t = execFileSync('pdftotext', [join(tmp, 'text.pdf'), '-']).toString();
        // pdftotext קורא כל גליף לפי ToUnicode, בסדר החזותי (כל גליף ממוקם לבד); הסדר הלוגי — ב-ActualText
        const rev = w => [...w].reverse().join('');
        ok('glyphs ב-JS: הטקסט בר-חיפוש — ToUnicode (pdftotext מוצא את האותיות של "שלום" ו"עולם")', [t, rev(t)].some(x => x.includes('שלום') && x.includes('עולם')), t);
    }
    if (has('pdffonts')) {
        const t = execFileSync('pdffonts', [join(tmp, 'text.pdf')]).toString();
        ok('glyphs ב-JS: הגופן מוטמע כ-subset (pdffonts: emb yes, sub yes)', /\+Heebo\s+CID TrueType\s+Identity-H\s+yes\s+yes\s+yes/.test(t), t);
    }
    ok('glyphs ב-JS: ActualText נושא את הטקסט הלוגי (חיפוש והעתקה בסדר הנכון)', shape(b).contents[0].includes('/ActualText <FEFF05E905DC05D505DD002005E205D505DC05DD>'));
    const small = b.length, full = fmedia['media/Heebo.ttf'].length;
    ok('glyphs ב-JS: ה-subset קטן מהגופן המלא', small < full / 2, `${small} מול ${full}`);
    // גופן בלי גליפים בשימוש — לא נכנס לקובץ (כמו ב-PHP)
    await same('glyphs: רשימה ריקה — הגופן לא מוטמע, ושאר הציור כרגיל', { pages: [{ size: [50, 50], ops: [
        { op: 'glyphs', font: 'media/Heebo.ttf', size: 10, x: 0, y: 10, glyphs: [] }, { op: 'rect', box: [0, 0, 50, 50], fill: '#00ff00' }] }] }, fmedia);
}
// WOFF 1.0 — מפורק לאותו sfnt בשתי השפות (PHP: zlib_decode; JS: DecompressionStream)
{
    const { deflateSync } = await import('node:zlib');
    const sf = fmedia['media/Heebo.ttf'];
    const num = sf.readUInt16BE(4);
    const ents = [];
    for (let i = 0; i < num; i++) {
        const e = 12 + i * 16;
        const off = sf.readUInt32BE(e + 8), len = sf.readUInt32BE(e + 12);
        const orig = sf.subarray(off, off + len), comp = deflateSync(orig);
        ents.push({ tag: sf.subarray(e, e + 4), chk: sf.readUInt32BE(e + 4), orig, data: comp.length < orig.length ? comp : orig });
    }
    let off = 44 + 20 * num;
    const head = Buffer.alloc(44 + 20 * num);
    head.write('wOFF', 0); head.writeUInt32BE(sf.readUInt32BE(0), 4); head.writeUInt16BE(num, 12);
    const bodies = [];
    ents.forEach((e, i) => {
        const p = 44 + i * 20;
        e.tag.copy(head, p); head.writeUInt32BE(off, p + 4); head.writeUInt32BE(e.data.length, p + 8); head.writeUInt32BE(e.orig.length, p + 12); head.writeUInt32BE(e.chk, p + 16);
        const pad = Buffer.alloc((4 - e.data.length % 4) % 4);
        bodies.push(e.data, pad); off += e.data.length + pad.length;
    });
    const woff = Buffer.concat([head, ...bodies]);
    woff.writeUInt32BE(woff.length, 8);
    const wm = { 'media/Heebo.woff': woff };
    const ops = line(tc['media/Heebo.ttf'], 'גופן WOFF', 16, 10, 30).map(o => ({ ...o, font: 'media/Heebo.woff' }));
    await same('glyphs: גופן WOFF 1.0', { pages: [{ size: [200, 60], ops }] }, wm);
}

// XMP (שורה 5 "פנים"): זרם Metadata לא דחוס בקטלוג, אותו דבר בשתי השפות; בלי xmp — בלי Metadata
{
    const xmp = '<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:mbe="https://mbe-plus.com/ns/mbe/1.0/" mbe:docId="d-אבג" mbe:base="7"/></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    const list = { title: 'פנים', xmp, pages: [{ size: [20, 20], ops: [{ op: 'rect', box: [0, 0, 20, 20], fill: '#336699' }] }] };
    const [a, b] = [php(list), await js(list)];
    const meta = buf => {
        const objs = parse(buf);
        const cat = Object.values(objs).find(o => /\/Type \/Catalog/.test(o.dict));
        const ref = cat?.dict.match(/\/Metadata (\d+) 0 R/);
        return ref ? objs[ref[1]] : null;
    };
    const [ma, mb] = [meta(a), meta(b)];
    const want = Buffer.from(xmp, 'utf8');
    ok('xmp: Metadata בקטלוג, /Subtype /XML, לא דחוס — הבתים כמו שנמסרו (PHP)', ma && /\/Type \/Metadata \/Subtype \/XML/.test(ma.dict) && !/Filter/.test(ma.dict) && ma.data.equals(want), ma?.dict);
    ok('xmp: אותו זרם ב-JS', mb && norm(mb.dict) === norm(ma?.dict || '') && mb.data.equals(want), mb?.dict);
    ok('xmp: mbe:docId גלוי בקובץ כמו שהוא (חיפוש בבתים, כמו identify)', a.includes(Buffer.from('mbe:docId="d-אבג"')) && b.includes(Buffer.from('mbe:docId="d-אבג"')));
    const plain = { pages: list.pages };
    ok('בלי xmp — בלי Metadata (הפלט לא השתנה)', !meta(php(plain)) && !meta(await js(plain)) && !php(plain).includes('/Metadata'));
    await same('xmp: הציור עצמו זהה', list);
    // מקצה לקצה: חבילה בצורה של xmpPacket (mbe-core/face.js) → PDF מ-JS ומ-PHP → identify() מוצא את המזהים
    const { identify } = await import('../../mbe-core/src/js/face.js');
    const docId = '3f2b8c1e-4d5a-4b6c-8e7f-9a0b1c2d3e4f', base = 'ab'.repeat(32);
    const packet = `<?xpacket begin="\uFEFF" id="W5M0MpCehiHzreSzNTczkc9d"?>\n<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">`
        + `<rdf:Description rdf:about="" xmlns:mbe="urn:mbe:face:1"><mbe:docId>${docId}</mbe:docId><mbe:base>${base}</mbe:base></rdf:Description></rdf:RDF></x:xmpmeta>\n<?xpacket end="w"?>`;
    const face = { xmp: packet, pages: list.pages };
    const ids = await Promise.all([php(face), await js(face)].map(x => identify(new Uint8Array(x))));
    ok('xmp → identify() של mbe-core: type "face", אותו docId ו-base (PHP ו-JS)', ids.every(r => r.type === 'face' && r.docId === docId && r.base === base), JSON.stringify(ids));
}

// שגיאות — אותה הודעה בשתי השפות
const errs = [
    { pages: [] },
    { pages: [{ size: [10], ops: [] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'circle' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'rect', box: [0, 0, 1, 1] }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'rect', box: [0, 0, 1], fill: '#000000' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'line', from: [0, 0], to: [1], color: '#000' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'm0 0 l5 5', fill: '#000000' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5', fill: '#000000' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'L5 5', fill: '#000000' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5 5 ?', fill: '#000000' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5 5', fill: '#000000', fillOpacity: 2 }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5 5', stroke: '#000000', cap: 'flat' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5 5', fill: '#000000', fillRule: 'odd' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5 5', fill: '#000000', transform: [1, 0, 0] }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'restore' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'clip', d: 'M0 0 L5 5 Z', transform: [0, 0, 0, 0, 0, 0] }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5 5 Z', fill: grad('conic', {}, [[0, '#000000']]) }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5 5 Z', fill: grad('radial', { cx: 1, cy: 1, r: 0 }, [[0, '#000000']]) }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'path', d: 'M0 0 L5 5 Z', fill: { gradient: { type: 'linear', x1: 0, y1: 0, x2: 5, y2: 0, stops: [{ offset: 0, color: '#000000', opacity: 1.5 }] } } }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'image', src: 'media/none.png', box: [0, 0, 1, 1] }] }] },
    { xmp: 5, pages: [{ size: [10, 10], ops: [] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'glyphs', font: 'media/none.ttf', size: 9, x: 0, y: 0, glyphs: [] }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'glyphs', font: 'media/c.jpg', size: 9, x: 0, y: 0, glyphs: [] }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'glyphs', font: 'media/Heebo.ttf', size: 9, x: 0, glyphs: [] }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'glyphs', font: 'media/Heebo.ttf', size: 9, x: 0, y: 0, glyphs: 'אבג' }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'glyphs', font: 'media/Heebo.ttf', size: 9, x: 0, y: 0, glyphs: [[99999, 0, 0, 1]] }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'glyphs', font: 'media/Heebo.ttf', size: 9, x: 0, y: 0, glyphs: [[5, 0, 0]] }] }] },
    { pages: [{ size: [10, 10], ops: [{ op: 'glyphs', font: 'media/Heebo.ttf', size: 9, x: 0, y: 0, color: 'red', glyphs: [[5, 0, 0, 1]] }] }] },
];
const emedia = { ...media, 'media/Heebo.ttf': fmedia['media/Heebo.ttf'] };
const mism = [];
for (const list of errs) {
    const [a, b] = [php(list, emedia).toString(), (await js(list, emedia)).toString()];
    if (!a.startsWith('ERR:') || a !== b) mism.push(`PHP «${a.slice(0, 90)}» / JS «${b.slice(0, 90)}»`);
}
ok(`שגיאות: ${errs.length} רשימות פגומות — אותה הודעה בשתי השפות`, !mism.length, mism.join(' | '));

rmSync(tmp, { recursive: true, force: true });
console.log(fail ? `\n✗ ${fail} נכשלו, ${pass} עברו` : `\n✓ כל ${pass} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
