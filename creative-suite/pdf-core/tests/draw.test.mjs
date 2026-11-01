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
];
const mism = [];
for (const list of errs) {
    const [a, b] = [php(list).toString(), (await js(list)).toString()];
    if (!a.startsWith('ERR:') || a !== b) mism.push(`PHP «${a.slice(0, 90)}» / JS «${b.slice(0, 90)}»`);
}
ok(`שגיאות: ${errs.length} רשימות פגומות — אותה הודעה בשתי השפות`, !mism.length, mism.join(' | '));
const g = (await js({ pages: [{ size: [10, 10], ops: [{ op: 'glyphs', font: 'x', size: 9, x: 0, y: 0, glyphs: [] }] }] })).toString();
ok('glyphs ב-JS — שגיאה ברורה (עוד לא), עם המקום', g.startsWith('ERR:glyphs') && g.includes('עמוד 1, פעולה 1'), g);

rmSync(tmp, { recursive: true, force: true });
console.log(fail ? `\n✗ ${fail} נכשלו, ${pass} עברו` : `\n✓ כל ${pass} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
