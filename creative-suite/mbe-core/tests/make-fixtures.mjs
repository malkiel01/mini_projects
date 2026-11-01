// יוצר את קבצי הבדיקה: tests/fixtures/valid/<kind>.mbe (אחד לכל kind) ו-tests/fixtures/hostile/*.mbe,
// ואת tests/fixtures/hostile.json — מה כל קובץ עוין חייב להחזיר. דטרמיניסטי: אותו פלט בכל הרצה.
//   node creative-suite/mbe-core/tests/make-fixtures.mjs

import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { zlibSync } from '../src/js/vendor/fflate.js';
import * as M from '../src/js/mbe.js';
import { writeZip, crc32 } from '../src/js/zip.js';
import { canonicalJson, utf8 } from '../src/js/canonical.js';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, 'fixtures');
const NOW = new Date('2026-10-23T09:00:00Z');

/** PNG אמיתי וקטן בצבע אחד — preview ושכבות רסטר. */
export function png(w, h, [r, g, b]) {
    const row = new Uint8Array(1 + w * 3);
    for (let x = 0; x < w; x++) row.set([r, g, b], 1 + x * 3);
    const raw = new Uint8Array(row.length * h);
    for (let y = 0; y < h; y++) raw.set(row, y * row.length);
    const chunk = (type, data) => {
        const c = new Uint8Array(12 + data.length);
        const dv = new DataView(c.buffer);
        dv.setUint32(0, data.length);
        c.set(utf8(type), 4);
        c.set(data, 8);
        dv.setUint32(8 + data.length, crc32(c.subarray(4, 8 + data.length)));
        return c;
    };
    const ihdr = new Uint8Array(13);
    const dv = new DataView(ihdr.buffer);
    dv.setUint32(0, w); dv.setUint32(4, h); ihdr.set([8, 2, 0, 0, 0], 8);
    const parts = [Uint8Array.of(0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a), chunk('IHDR', ihdr), chunk('IDAT', zlibSync(raw)), chunk('IEND', new Uint8Array())];
    const res = new Uint8Array(parts.reduce((s, p) => s + p.length, 0));
    let o = 0;
    for (const p of parts) { res.set(p, o); o += p.length; }
    return res;
}

// שדות "מהעתיד" — כל קורא חייב לשמור אותם בית-לבית, כולל {} ו-[] ריקים, מספרים קשים ועברית.
const FUTURE = {
    'x-future': { empty: {}, list: [], listLike: { 0: 'a', 1: 'b' }, n: [0.1, 1e21, 1e-7, -0, 333333333.33333329, 4.5, 2e-3, 9007199254740993],
        he: 'שלום — "מירכאות" ו\\לוכסן/', emoji: '😀', '€': 1, '\r': 2, 'דּ': 3, '1': 4, '😀': 5, '\u0080': 6, 'ö': 7 },
};
const PDF = utf8('%PDF-1.4\n% placeholder rendition for tests\n%%EOF\n');
const SVG = utf8('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#36c"/></svg>');

async function valid() {
    const dir = join(out, 'valid');
    mkdirSync(dir, { recursive: true });
    const red = png(2, 2, [200, 40, 40]), blue = png(3, 2, [40, 40, 200]);
    // מזהי פסקה קבועים — אחרת save משלים מזהים אקראיים, וקבצי הבדיקה משתנים בכל הרצה
    const pm = (text, id = 'p0000000001') => ({ type: 'doc', content: [{ type: 'paragraph', attrs: { id }, content: [{ type: 'text', text }] },
        { type: 'heading', attrs: { level: 2, id: id + 'h' }, content: [{ type: 'text', text: 'כותרת' }] }] });

    const make = async (kind, build) => {
        const f = M.create(kind, { units: 'pt', color: { space: 'sRGB' }, styles: {}, surfaces: [], ...FUTURE });
        await build(f);
        f.setRendition(M.PREVIEW, png(16, 12, [240, 240, 240]), { type: 'image/png' });
        f.setApp(kind === 'text' ? 'word-processor' : `${kind}-studio`, 'view.json', utf8('{"zoom":1.25,"panel":{}}'));
        f.files.set('extensions/future.bin', Uint8Array.of(0, 1, 2, 3, 255));
        f.manifest['x-future-manifest'] = { keep: true, empty: {} };
        f.manifest.id = { text: '11111111-1111-4111-8111-111111111111', layout: '22222222-2222-4222-8222-222222222222',
            raster: '33333333-3333-4333-8333-333333333333', vector: '44444444-4444-4444-8444-444444444444' }[kind]; // קבוע — קבצי בדיקה דטרמיניסטיים
        const bytes = await M.save(f, { app: 'mbe-core-tests', appVersion: '1', now: NOW });
        writeFileSync(join(dir, `${kind}.mbe`), bytes);
    };

    await make('text', async f => {
        f.document.surfaces.push({ id: 'p1', type: 'page', size: [595.3, 841.9], master: null,
            layers: [{ id: 'fr1', type: 'frame', box: [56, 56, 483, 730], flow: 'f1' }] });
        f.document.flows = { f1: { engine: 'prosemirror', doc: pm('שלום עולם — מסמך טקסט בעברית, ו-English באמצע.') } };
        f.setRendition('renditions/document.pdf', PDF, { gaps: ['footnotes → endnotes'], by: 'word-processor' });
    });
    await make('layout', async f => {
        const img = await f.addMedia(red, 'image/png');
        f.document.surfaces.push(
            { id: 'p1', type: 'page', size: [595.3, 841.9], master: null, layers: [
                { id: 'bg', name: 'רקע', type: 'raster', src: img, opacity: 1, blend: 'normal', visible: true, locked: false },
                { id: 'g1', type: 'group', name: 'כותרת', children: [{ id: 'logo', type: 'vector', paths: [{ id: 'logo-p', d: 'M0 0 L10 10', stroke: { color: '#000000', opacity: 1, width: 1 } }] }] },
                { id: 'fa', type: 'frame', box: [56, 56, 483, 300], flow: 'story' }] },
            { id: 'p2', type: 'page', size: [595.3, 841.9], master: null, layers: [{ id: 'fb', type: 'frame', box: [56, 56, 483, 730], flow: 'story' }] });
        f.document.flows = { story: { engine: 'prosemirror', doc: pm('שטף אחד שעובר בין שתי מסגרות.'), frames: ['fa', 'fb'], autogrow: true, style: 'body' } };
        f.document.fonts = [{ family: 'David Libre' }];
        f.setRendition('renditions/document.pdf', PDF, { gaps: [] });
    });
    await make('raster', async f => {
        const a = await f.addMedia(red, 'image/png'), b = await f.addMedia(blue, 'image/png');
        await f.addMedia(red, 'image/png'); // אותו תוכן פעמיים — נשמר פעם אחת
        f.document.units = 'px';
        f.document.surfaces.push({ id: 'c1', type: 'canvas', size: [3, 2], layers: [
            { id: 'l1', name: 'רקע', type: 'raster', src: a, opacity: 1, blend: 'normal', visible: true, locked: true },
            { id: 'g', type: 'group', name: 'קבוצה', children: [{ id: 'l2', type: 'raster', src: b, opacity: 0.5, blend: 'multiply' }] }] });
        f.setRendition('renditions/image.png', blue, { gaps: ['blend-mode:multiply → normal'] });
    });
    await make('vector', async f => {
        f.document.surfaces.push({ id: 'a1', type: 'artboard', size: [100, 100], layers: [
            { id: 'v1', type: 'vector', name: 'ציור', paths: [
                // לפי mbe-format.md סעיף 3, "תוכן שכבה vector": נתיב, קבוצה, טקסט קצר, ופריט מסוג לא מוכר (נשמר)
                { id: 'rect', name: 'מלבן', d: 'M10 10 L90 10 L90 40 L10 40 Z', transform: [1, 0, 0, 1, 0, 0],
                    fill: { color: '#3366cc', opacity: 1 }, stroke: null, fillRule: 'nonzero', shape: { type: 'rect', box: [10, 10, 80, 30], radius: 0 } },
                { id: 'grp', type: 'group', name: 'קבוצה', children: [
                    { id: 'curve', type: 'path', d: 'M0 0 C10 0 20 10 20 20 Q30 30 40 20 Z', fill: { gradient: { type: 'linear', from: [0, 0], to: [40, 0],
                        stops: [{ at: 0, color: '#ffffff', opacity: 1 }, { at: 1, color: '#000000', opacity: 1 }] } },
                      stroke: { color: '#000000', opacity: 1, width: 1.5, cap: 'round', join: 'round', miter: 4, dash: [2, 1] } },
                    { id: 'subgrp', type: 'group', children: [{ id: 'dot', d: 'M50 50 L51 50 L51 51 Z' }] }] },
                { id: 'title', type: 'text', text: 'שלום\nעולם', box: [10, 60, 80, 30], font: { family: 'David Libre', size: 12, weight: 700 },
                    align: 'center', lineHeight: 1.2, auto: false, fill: { color: '#000000', opacity: 1 }, stroke: null },
                { id: 'future-item', type: 'spiral', turns: 3, from: { x: 0 } }] }] });
        f.document.fonts = [{ family: 'David Libre' }];
        f.setRendition('renditions/drawing.svg', SVG, { type: 'image/svg+xml' });
    });
}

// ---- עוינים ----

async function manifestFor(doc, extra = {}) {
    const docBytes = doc instanceof Uint8Array ? doc : utf8(typeof doc === 'string' ? doc : canonicalJson(doc));
    const sha = await M.sha256(docBytes);
    const preview = { path: M.PREVIEW, type: 'image/png', sourceSha256: sha, gaps: [] };
    return { docBytes, manifest: { format: 'mbe', version: '1.0', kind: 'text', document: { path: 'document.json', sha256: sha }, media: [], renditions: [preview], ...extra } };
}

async function base(doc = { surfaces: [] }, extra = {}, more = []) {
    const { docBytes, manifest } = await manifestFor(doc, extra);
    return [
        { name: 'mimetype', data: utf8(M.MIMETYPE), store: true },
        { name: 'manifest.json', data: utf8(canonicalJson(manifest)) },
        { name: 'document.json', data: docBytes },
        { name: M.PREVIEW, data: png(4, 4, [1, 2, 3]) },
        ...more,
    ];
}

const zip = files => writeZip(files, NOW);

/** משנה שדה במדריך המרכזי של הרשומה ה-i (offset יחסי לתחילת הרשומה). */
function patchCentral(bytes, index, rel, value, size = 4) {
    const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    let eocd = bytes.length - 22;
    let p = dv.getUint32(eocd + 16, true);
    for (let i = 0; i < index; i++) p += 46 + dv.getUint16(p + 28, true) + dv.getUint16(p + 30, true) + dv.getUint16(p + 32, true);
    if (size === 4) dv.setUint32(p + rel, value, true); else dv.setUint16(p + rel, value, true);
    return bytes;
}

async function hostile() {
    const dir = join(out, 'hostile');
    mkdirSync(dir, { recursive: true });
    const cases = [];
    const add = (name, bytes, codes, opts = {}) => {
        writeFileSync(join(dir, `${name}.mbe`), bytes);
        cases.push({ file: `hostile/${name}.mbe`, codes, ...opts });
    };
    const note = utf8('x');

    add('zip-slip-dotdot', zip(await base(undefined, {}, [{ name: '../evil.txt', data: note }])), ['zip-slip']);
    add('zip-slip-absolute', zip(await base(undefined, {}, [{ name: '/etc/evil', data: note }])), ['zip-slip']);
    add('zip-slip-backslash', zip(await base(undefined, {}, [{ name: 'media\\..\\..\\evil', data: note }])), ['zip-slip']);
    add('zip-slip-drive', zip(await base(undefined, {}, [{ name: 'C:/evil', data: note }])), ['zip-slip']);
    add('name-control-char', zip(await base(undefined, {}, [{ name: 'apps/x/a\u0001b', data: note }])), ['name-invalid']);
    add('duplicate-name', zip(await base(undefined, {}, [{ name: 'apps/x/a', data: note }, { name: 'apps/x/a', data: note }])), ['duplicate-name']);
    add('zip-bomb', zip(await base(undefined, {}, [{ name: 'apps/x/zeros.bin', data: new Uint8Array(20 * 1024 * 1024) }])), ['zip-bomb']);
    {
        // גודל מוצהר קטן (1KB) והתוכן האמיתי 200KB — לא נפתח מעבר למוצהר
        const files = await base(undefined, {}, [{ name: 'apps/x/lie.bin', data: new Uint8Array(200 * 1024).fill(7) }]);
        add('size-lie', patchCentral(zip(files), files.length - 1, 24, 1024), ['size-mismatch']);
    }
    {
        const files = await base(undefined, {}, [{ name: 'apps/x/data.txt', data: utf8('hello hello hello') , store: true }]);
        const bytes = zip(files);
        const at = Buffer.from(bytes).indexOf('hello hello hello');
        bytes[at] ^= 0xff;
        add('crc-mismatch', bytes, ['crc-mismatch']);
    }
    add('encrypted', patchCentral(zip(await base()), 2, 8, 1, 2), ['encrypted']);
    add('compression-bzip2', patchCentral(zip(await base()), 2, 10, 12, 2), ['compression-unsupported']);
    {
        const bytes = zip(await base());
        new DataView(bytes.buffer).setUint16(bytes.length - 22 + 10, 0xFFFF, true);
        new DataView(bytes.buffer).setUint16(bytes.length - 22 + 8, 0xFFFF, true);
        add('zip64', bytes, ['zip64-unsupported']);
    }
    add('too-many-entries', zip(await base(undefined, {}, Array.from({ length: 10 }, (_, i) => ({ name: `apps/x/${i}`, data: note })))),
        ['too-many-entries'], { limits: { maxEntries: 8 } });
    add('too-large', zip(await base(undefined, {}, [{ name: 'apps/x/big.bin', data: new Uint8Array(4096).map((_, i) => i * 7) }])),
        ['too-large'], { limits: { maxTotalBytes: 2048 } });
    add('not-zip', utf8('%PDF-1.4 this is not a zip at all, just text pretending to be something'), ['not-zip']);
    { const ok = zip(await base()); add('truncated', ok.slice(0, ok.length - 40), ['zip-invalid']); }
    add('not-mbe', zip([{ name: 'readme.txt', data: utf8('just a zip') }]), ['not-mbe']);
    add('mimetype-wrong', zip((await base()).map(f => f.name === 'mimetype' ? { ...f, data: utf8('application/zip') } : f)), ['mimetype-invalid']);
    {
        const files = await base();
        add('mimetype-compressed-not-first', zip([files[1], { ...files[0], store: false }, ...files.slice(2)]), ['mimetype-not-first'], { ok: true });
    }
    add('manifest-id-invalid', zip(await base(undefined, { version: '1.1', id: 'NOT-A-UUID' })), ['manifest-id-invalid'], { ok: true });
    add('manifest-id-missing', zip(await base(undefined, { version: '1.1' })), ['manifest-id-missing'], { ok: true });
    {
        // history/ (1.1) — אזהרות בלבד: יומן פגום, עותק שהשם שלו אינו הגיבוב, שלב בלי עותק
        const files = await base({ surfaces: [] });
        const docSha = await M.sha256(files.find(x => x.name === 'document.json').data);
        add('history-invalid', zip([...files, { name: M.JOURNAL, data: utf8('{"stages":"x"}') }]), ['history-invalid'], { ok: true });
        add('history-hash-mismatch', zip([...files, { name: `history/${'d'.repeat(64)}.json`, data: utf8('{"surfaces":[]}') }]), ['history-hash-mismatch'], { ok: true });
        add('history-missing', zip([...files, { name: M.JOURNAL, data: utf8(canonicalJson({ stages: [
            { id: 's1', at: '2026-10-25T09:00:00Z', app: 'x', kind: 'edit', base: 'e'.repeat(64), result: docSha, who: 'me@example.com' }] })) }]),
            ['history-missing', 'history-base-missing', 'privacy'], { ok: true });
    }
    add('version-2', zip(await base(undefined, { version: '2.0' })), ['version-unsupported']);
    add('version-newer-minor', zip(await base(undefined, { version: '1.7', 'x-from-1.7': { a: [] } })), ['version-newer-minor'], { ok: true });
    add('manifest-not-json', zip((await base()).map(f => f.name === 'manifest.json' ? { ...f, data: utf8('{"format":"mbe",') } : f)), ['manifest-invalid']);
    add('manifest-schema', zip((await base()).map(f => f.name === 'manifest.json' ? { ...f, data: utf8('{"format":"zip","document":{}}') } : f)), ['manifest-schema']);
    {
        const files = await base({ surfaces: [] });
        add('hash-mismatch', zip(files.map(f => f.name === 'document.json' ? { ...f, data: utf8('{"surfaces":[],"x":1}') } : f)), ['hash-mismatch']);
    }
    {
        let deep = '0';
        for (let i = 0; i < 300; i++) deep = `[${deep}]`;
        add('json-too-deep', zip(await base(`{"surfaces":[],"deep":${deep}}`)), ['json-too-deep']);
    }
    add('document-bad-utf8', zip(await base(Uint8Array.of(0x7b, 0x22, 0xff, 0x22, 0x3a, 0x31, 0x7d))), ['document-invalid']);
    add('document-lone-surrogate', zip(await base('{"surfaces":[],"t":"\\ud800"}')), ['document-invalid']);
    add('document-schema', zip(await base({ surfaces: [{ id: 'p1', type: 'page', size: [0, 10], layers: [{ id: 'x', type: 'raster', opacity: 2 }] }] })), ['document-schema']);
    {
        const svg = utf8('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        const path = `media/${await M.sha256(svg)}.svg`;
        add('svg-script', zip(await base({ surfaces: [] }, { media: [{ path, sha256: await M.sha256(svg), type: 'image/svg+xml', bytes: svg.length }] }, [{ name: path, data: svg }])), ['svg-unsafe']);
        const svg2 = utf8('<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example/x.png"/><rect onload="x()"/></svg>');
        const p2 = `renditions/drawing.svg`;
        add('svg-external', zip(await base({ surfaces: [] }, {}, [{ name: p2, data: svg2 }])), ['svg-unsafe', 'rendition-unlisted']);
    }
    add('external-ref', zip(await base({ surfaces: [{ id: 'p', type: 'page', layers: [{ id: 'r', type: 'raster', src: 'https://evil.example/x.png' }] }],
        flows: { f: { engine: 'prosemirror', doc: { type: 'doc', content: [{ type: 'image', attrs: { src: 'data:image/png;base64,AAAA' } }] } } } })), ['external-ref']);
    add('ref-missing', zip(await base({ surfaces: [{ id: 'p', type: 'page', layers: [{ id: 'r', type: 'raster', src: `media/${'a'.repeat(64)}.png` }] }] })), ['ref-missing']);
    add('duplicate-id', zip(await base({ surfaces: [{ id: 'p', type: 'page', layers: [{ id: 'p', type: 'group', children: [{ id: 'q', type: 'vector' }, { id: 'q', type: 'vector' }] }] }] })), ['duplicate-id']);
    const vec = paths => ({ surfaces: [{ id: 'a', type: 'artboard', layers: [{ id: 'v', type: 'vector', paths }] }] });
    add('path-d-missing', zip(await base(vec([{ id: 'p1', fill: null }]))), ['path-d-missing']);
    add('path-d-relative', zip(await base(vec([{ id: 'p1', d: 'm0 0 l10 10 z' }]))), ['path-d-invalid', 'document-schema']);
    add('path-d-args', zip(await base(vec([{ id: 'p1', d: 'M0 0 C1 2 3 4' }]))), ['path-d-invalid']);
    add('path-id-missing', zip(await base(vec([{ d: 'M0 0 L1 1' }]))), ['document-schema']);
    add('path-id-duplicate', zip(await base(vec([{ id: 'v', d: 'M0 0 L1 1' }, { id: 'g', type: 'group', children: [{ id: 'q', d: 'M0 0 L1 1' }, { id: 'q', d: 'M0 0 L2 2' }] }]))), ['duplicate-id']);
    add('path-transform', zip(await base(vec([{ id: 'p1', d: 'M0 0 L1 1', transform: [1, 0, 0, 1, 0] }]))), ['document-schema']);
    // סמלים (וקטורי גרסה 2): מזהה בתוך הגדרה — ייחודי בכל המסמך; d בהגדרה נבדק; use לסמל שלא קיים — אזהרה
    const sym = (paths, symbols) => ({ ...vec(paths), symbols });
    add('symbol-id-duplicate', zip(await base(sym([{ id: 'p1', d: 'M0 0 L1 1' }], { s1: { name: 'כוכב', paths: [{ id: 'p1', d: 'M0 0 L2 2' }] } }))), ['duplicate-id']);
    add('symbol-d-invalid', zip(await base(sym([{ id: 'u1', type: 'use', symbol: 's1' }], { s1: { paths: [{ id: 'sp', d: 'L0 0' }] } }))), ['path-d-invalid']);
    add('symbol-missing', zip(await base(sym([{ id: 'u1', type: 'use', symbol: 'nope', transform: [1, 0, 0, 1, 0, 0] }, { id: 'u2', type: 'use', symbol: 's1' }],
        { s1: { name: 'כוכב', paths: [{ id: 'sp', d: 'M0 0 L1 1' }] } }))), ['symbol-missing'], { ok: true });
    add('group-geometry', zip(await base(vec([{ id: 'g', type: 'group', d: 'M0 0 L1 1', children: [] }]))), ['group-geometry']);
    {
        // flows.<id>.frames (סעיף 3, "מסגרות, שטפים"): מזהה שלא קיים, מזהה כפול, שכבה שאינה frame, מסגרת של שטף אחר
        const page = { id: 'p', type: 'page', layers: [
            { id: 'a', type: 'frame', flow: 's1' }, { id: 'b', type: 'frame', flow: 's2' }, { id: 'r', type: 'raster', src: undefined }] };
        delete page.layers[2].src;
        const flows = { s1: { engine: 'prosemirror', frames: ['a', 'a', 'nope'] }, s2: { engine: 'prosemirror', frames: ['b', 'r', 'a'] } };
        add('flow-frames', zip(await base({ surfaces: [page], flows })), ['flow-frames']);
        add('flow-frames-ok', zip(await base({ surfaces: [{ id: 'p', type: 'page', layers: [{ id: 'a', type: 'frame', flow: 's1' }, { id: 'b', type: 'frame', flow: 's1' }] }],
            flows: { s1: { engine: 'prosemirror', frames: ['b', 'a'] }, s2: { engine: 'prosemirror' } } })), [], { ok: true });
    }
    {
        // מזהה פסקה (1.1): פגום / כפול — אזהרה (save מחליף); קבוצה בוליאנית — d מותר, ונבדק
        const flow = content => ({ surfaces: [], flows: { f: { engine: 'prosemirror', doc: { type: 'doc', content } } } });
        const para = (id, text = 'א') => ({ type: 'paragraph', attrs: id === undefined ? {} : { id }, content: [{ type: 'text', text }] });
        add('para-id-invalid', zip(await base(flow([para('has-dash'), para('ok_1')]))), ['para-id-invalid'], { ok: true });
        add('para-id-duplicate', zip(await base(flow([para('p1'), { type: 'bulletList', content: [{ type: 'listItem', content: [para('p1')] }] }]))),
            ['para-id-duplicate'], { ok: true });
        add('para-id-missing-ok', zip(await base(flow([para(), para('p2')]))), [], { ok: true });
        add('group-boolean', zip(await base(vec([{ id: 'g', type: 'group', boolean: 'union', d: 'M0 0 L10 0 L10 10 Z', children: [
            { id: 'ga', d: 'M0 0 L5 0 L5 5 Z' }, { id: 'gb', d: 'M2 2 L10 2 L10 10 Z' }] }]))), [], { ok: true });
        const raster = vectorMask => ({ surfaces: [{ id: 'c', type: 'canvas', layers: [{ id: 'r', type: 'raster', vectorMask }] }] });
        add('vector-mask-ok', zip(await base(raster({ d: 'M0 0 L10 0 L10 10 Z', feather: 2, enabled: true }))), [], { ok: true });
        add('vector-mask-bad-d', zip(await base(raster({ d: 'M0 0 C1 2' }))), ['path-d-invalid']);
        add('group-boolean-bad-d', zip(await base(vec([{ id: 'g', type: 'group', boolean: 'subtract', d: 'M0 0 L1', children: [] }]))), ['path-d-invalid']);
    }
    add('flow-missing', zip(await base({ surfaces: [{ id: 'p', type: 'page', layers: [{ id: 'f', type: 'frame', flow: 'nope' }] }], flows: {} })), ['flow-missing']);
    {
        const data = png(2, 2, [9, 9, 9]);
        const real = await M.sha256(data);
        add('media-name-mismatch', zip(await base({ surfaces: [] }, {}, [{ name: `media/${'b'.repeat(64)}.png`, data }])), ['media-name-mismatch', 'media-unlisted']);
        add('media-missing', zip(await base({ surfaces: [] }, { media: [{ path: `media/${real}.png`, sha256: real, type: 'image/png', bytes: data.length }] })), ['media-missing']);
    }
    add('rendition-missing', zip(await base({ surfaces: [] }, { renditions: [{ path: 'renditions/document.pdf', type: 'application/pdf', sourceSha256: '0'.repeat(64), gaps: [] }] })), ['rendition-missing']);
    add('rendition-stale', zip(await base({ surfaces: [] }, { renditions: [
        { path: M.PREVIEW, type: 'image/png', sourceSha256: 'c'.repeat(64), gaps: [] }] })), ['rendition-stale'], { ok: true });
    add('preview-missing', zip((await base(undefined, { renditions: [] })).filter(f => f.name !== M.PREVIEW)), ['preview-missing'], { ok: true });
    add('preview-too-big', zip((await base()).map(f => f.name === M.PREVIEW ? { ...f, data: png(1100, 1, [0, 0, 0]) } : f)), ['preview-invalid'], { ok: true });
    add('privacy-email', zip(await base(undefined, { created: { app: 'x', at: '2026-10-23T09:00:00Z', appVersion: 'user@example.com' } })), ['privacy'], { ok: true });

    writeFileSync(join(out, 'hostile.json'), JSON.stringify(cases, null, 2) + '\n');
}

rmSync(out, { recursive: true, force: true });
await valid();
await hostile();
console.log('fixtures →', out);
