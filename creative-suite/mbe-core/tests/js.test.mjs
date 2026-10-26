// בדיקות הליבה ב-JS, וגם בין השפות (קורא ל-PHP דרך tests/cli.php).
//   node --test creative-suite/mbe-core/tests/

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import * as M from '../src/js/mbe.js';
import { canonicalJson, utf8 } from '../src/js/canonical.js';
import { readZip, writeZip } from '../src/js/zip.js';
import { validateSchema } from '../src/js/schema.js';

const here = dirname(fileURLToPath(import.meta.url));
const fx = p => readFileSync(join(here, 'fixtures', p));
const KINDS = ['text', 'layout', 'raster', 'vector'];
const tmp = mkdtempSync(join(tmpdir(), 'mbe-'));
const php = (...args) => execFileSync('php', [join(here, 'cli.php'), ...args]).toString();
const codes = f => [...new Set(f.issues.map(i => i.code))].sort();
const docBytesOf = bytes => readZip(bytes).entries.find(e => e.name === 'document.json').read();
const parts = f => Object.fromEntries([...f.files].map(([k, v]) => [k, Buffer.from(v).toString('base64')]));

test('JSON קנוני — וקטורי RFC 8785', () => {
    for (const t of JSON.parse(readFileSync(join(here, 'canonical-vectors.json'), 'utf8'))) {
        assert.equal(canonicalJson(JSON.parse(t.in)), t.out, t.note);
    }
});

test('JSON קנוני — 3000 מספרים אקראיים זהים ב-JS וב-PHP', () => {
    const nums = [];
    const dv = new DataView(new ArrayBuffer(8));
    let seed = 12345;
    const rnd = () => (seed = (seed * 1103515245 + 12345) >>> 0);
    while (nums.length < 3000) {
        dv.setUint32(0, rnd()); dv.setUint32(4, rnd());
        const x = dv.getFloat64(0);
        if (Number.isFinite(x)) nums.push(x);
        nums.push(rnd() / 1000, rnd() * 1e-9, Math.round(rnd() / 7));
    }
    const file = join(tmp, 'nums.json');
    writeFileSync(file, JSON.stringify(nums));
    assert.equal(php('canon', file), canonicalJson(nums));
});

test('כל kind נפתח תקין ב-JS וב-PHP', async () => {
    for (const k of KINDS) {
        const f = await M.open(fx(`valid/${k}.mbe`));
        assert.deepEqual(f.issues, [], k);
        assert.equal(f.kind, k);
        assert.deepEqual(JSON.parse(php('validate', join(here, 'fixtures', `valid/${k}.mbe`))), { ok: true, codes: [] }, `PHP ${k}`);
    }
});

test('JS → PHP → JS → PHP: המודל, החלקים והשדות הלא מוכרים זהים בית-לבית', async () => {
    for (const k of KINDS) {
        const orig = fx(`valid/${k}.mbe`);
        const start = await M.open(orig);
        let bytes = await M.save(await M.open(orig), { app: 'js-a', now: new Date('2026-10-23T10:00:00Z') });
        for (const [i, lang] of ['php', 'js', 'php', 'js'].entries()) {
            const inFile = join(tmp, `${k}-${i}.mbe`), outFile = join(tmp, `${k}-${i}-out.mbe`);
            writeFileSync(inFile, bytes);
            if (lang === 'php') { php('resave', inFile, outFile, 'php-b'); bytes = readFileSync(outFile); }
            else bytes = await M.save(await M.open(bytes), { app: 'js-c' });
            const f = await M.open(bytes);
            assert.deepEqual(f.issues, [], `${k} שלב ${i} (${lang})`);
            assert.deepEqual(Buffer.from(docBytesOf(bytes)), Buffer.from(docBytesOf(orig)), `${k}: document.json השתנה בשלב ${i}`);
            assert.deepEqual(parts(f), parts(start), `${k}: חלק השתנה בשלב ${i}`);
            assert.deepEqual(f.manifest['x-future-manifest'], { keep: true, empty: {} });
            assert.equal(f.manifest.created.app, 'mbe-core-tests');
            assert.equal(f.id, start.id, `${k}: manifest.id השתנה בשלב ${i} (${lang})`);
            assert.equal(f.manifest.document.sha256, start.manifest.document.sha256);
            assert.ok(f.renditions().every(r => !r.stale), `${k}: ייצוג סומן מיושן בלי שהמסמך השתנה`);
        }
    }
});

test('קבצים עוינים — נדחים, ואותן בעיות בדיוק ב-PHP', async () => {
    const cases = JSON.parse(readFileSync(join(here, 'fixtures', 'hostile.json'), 'utf8'));
    assert.ok(cases.length >= 40);
    for (const c of cases) {
        const f = await M.open(fx(c.file), { limits: { ...M.LIMITS, ...(c.limits || {}) } });
        for (const code of c.codes) assert.ok(codes(f).includes(code), `${c.file}: חסר ${code} (יש: ${codes(f)})`);
        assert.equal(f.ok, c.ok ?? false, `${c.file}: ok`);
        const p = JSON.parse(php('validate', join(here, 'fixtures', c.file), ...(c.limits ? [JSON.stringify(c.limits)] : [])));
        assert.deepEqual(p, { ok: f.ok, codes: codes(f) }, `${c.file}: PHP שונה מ-JS`);
    }
});

test('mimetype ראשון, לא דחוס, החתימה בהיסט 30–38', () => {
    const b = fx('valid/text.mbe');
    assert.equal(Buffer.from(b.subarray(30, 38 + M.MIMETYPE.length)).toString(), 'mimetype' + M.MIMETYPE);
    assert.equal(b[8] | (b[9] << 8), 0); // Stored
});

test('שינוי במסמך → הייצוגים שלא חודשו מסומנים מיושנים; preview שחודש — לא', async () => {
    const f = await M.open(fx('valid/text.mbe'));
    f.document.flows.f1.doc.content[0].content[0].text = 'טקסט חדש';
    f.setRendition(M.PREVIEW, f.files.get(M.PREVIEW), { type: 'image/png' });
    await M.save(f, { app: 'word-processor' });
    const st = Object.fromEntries(f.renditions().map(r => [r.path, r.stale]));
    assert.deepEqual(st, { 'renditions/document.pdf': true, [M.PREVIEW]: false });
    assert.ok(codes(f).includes('rendition-stale'));
});

test('save: בלי preview — נעצר; עם מסמך פגום — נעצר; קובץ קל משאיר רק preview', async () => {
    const f = M.create('text', { surfaces: [] });
    await assert.rejects(M.save(f, { app: 'x' }), e => e.issues?.[0]?.code === 'preview-missing');
    f.setRendition(M.PREVIEW, (await M.open(fx('valid/text.mbe'))).files.get(M.PREVIEW));
    f.document.surfaces.push({ id: 'a', type: 'page', layers: [{ id: 'a', type: 'raster', src: 'https://x/y.png' }] });
    await assert.rejects(M.save(f, { app: 'x' }), e => e.issues.some(i => i.code === 'external-ref') && e.issues.some(i => i.code === 'duplicate-id'));
    const g = await M.open(fx('valid/text.mbe'));
    await M.save(g, { app: 'x', lite: true });
    assert.deepEqual(g.renditions().map(r => r.path), [M.PREVIEW]);
});

test('גרסאות: major חדש — סירוב; minor חדש — נשמר עם השדות שלו; הממיר 1→2 קיים', async () => {
    assert.ok(codes(await M.open(fx('hostile/version-2.mbe'))).includes('version-unsupported'));
    const f = await M.open(fx('hostile/version-newer-minor.mbe'));
    const out = await M.open(await M.save(f, { app: 'x' }));
    assert.equal(out.manifest.version, '1.7');
    assert.deepEqual(out.manifest['x-from-1.7'], { a: [] });
    const m = M.migrate({ surfaces: [] }, { version: '1.0' }, 2);
    assert.equal(m.manifest.version, '2.0');
    assert.throws(() => M.migrate({}, { version: '1.0' }, 3));
});

test('media לפי sha256: אותו תוכן פעמיים נשמר פעם אחת', async () => {
    const f = await M.open(fx('valid/raster.mbe'));
    assert.equal(f.media().length, 2);
    for (const m of f.media()) assert.equal(m.path, `media/${await M.sha256(m.data)}.png`);
});

test('הבודק דוחה מילת סכמה שאינו מכיר (כדי שסכמה לא תיבדק חלקית בשקט)', () => {
    assert.throws(() => validateSchema(1, { oneOf: [] }));
});

test('ZIP: כתיבה וקריאה של שם בעברית', () => {
    const z = writeZip([{ name: 'apps/x/קובץ.txt', data: utf8('שלום') }]);
    const r = readZip(z);
    assert.equal(r.entries[0].name, 'apps/x/קובץ.txt');
    assert.equal(new TextDecoder().decode(r.entries[0].read()), 'שלום');
});

test('manifest.id (1.1): נוצר בשמירה הראשונה, נשמר, "שמירה כעותק" — חדש; קובץ 1.0 מקבל id', async () => {
    const preview = (await M.open(fx('valid/text.mbe'))).files.get(M.PREVIEW);
    const f = M.create('text', { surfaces: [] });
    assert.equal(f.id, null);
    f.setRendition(M.PREVIEW, preview);
    const a = await M.open(await M.save(f, { app: 'x' }));
    assert.match(a.id, M.ID_RE);
    assert.equal(a.manifest.version, '1.1');
    const b = await M.open(await M.save(a, { app: 'y' }));
    assert.equal(b.id, a.id);
    const c = await M.open(await M.save(b, { app: 'z', asCopy: true, now: new Date('2030-01-01T00:00:00Z') }));
    assert.match(c.id, M.ID_RE);
    assert.notEqual(c.id, a.id);
    assert.equal(c.manifest.created.at, '2030-01-01T00:00:00Z');
    const old = await M.open(fx('hostile/preview-too-big.mbe')); // קובץ 1.0 בלי id
    assert.equal(old.id, null);
    assert.match((await M.open(await M.save(old, { app: 'x' }))).id, M.ID_RE);
    assert.equal(new Set(Array.from({ length: 200 }, M.newId)).size, 200);
});


test('יומן (1.1): create → שמירות רצופות = שלב אחד → אפליקציה אחרת = שלב חדש → stage, restore, prune; וגם ב-PHP', async () => {
    const preview = (await M.open(fx('valid/text.mbe'))).files.get(M.PREVIEW);
    const doc = t => ({ surfaces: [], flows: { f1: { engine: 'prosemirror', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: t }] }] } } } });
    const f = M.create('text', doc('א'));
    f.setRendition(M.PREVIEW, preview);
    await M.save(f, { app: 'word-processor' });
    let j = f.journal();
    assert.equal(j.length, 1);
    assert.equal(j[0].kind, 'create');
    assert.equal(j[0].base, null);
    const v1 = j[0].result;

    f.document = doc('ב');
    await M.save(f, { app: 'word-processor' });
    f.document = doc('ג');
    await M.save(f, { app: 'word-processor' });
    j = f.journal();
    assert.equal(j.length, 1, 'שמירות רצופות באותה אפליקציה — שלב אחד');
    assert.equal(j[0].result, f.documentSha256);
    assert.deepEqual([...f.files.keys()].filter(p => /^history\/[0-9a-f]{64}\.json$/.test(p)), [`history/${f.documentSha256}.json`],
        'העותקים של הקשות ביניים נמחקו');

    await M.save(f, { app: 'word-processor' }); // בלי שינוי — לא נוגע ביומן
    assert.equal(f.journal().length, 1);

    f.document = doc('ד');
    await M.save(f, { app: 'layout-studio', who: 'המעמד' });
    j = f.journal();
    assert.equal(j.length, 2);
    assert.deepEqual([j[1].kind, j[1].app, j[1].who, j[1].id], ['edit', 'layout-studio', 'המעמד', 's2']);
    assert.equal(j[1].base, j[0].result);

    f.stage({ kind: 'export', who: 'המגיה', note: 'נשלח להגהה' });
    await M.save(f, { app: 'layout-studio' });
    assert.deepEqual(f.journal().at(-1), { ...f.journal().at(-1), kind: 'export', who: 'המגיה', note: 'נשלח להגהה' });

    f.restore('s1');
    assert.equal(f.document.flows.f1.doc.content[0].content[0].text, 'ג');
    await M.save(f, { app: 'layout-studio' });
    j = f.journal();
    assert.deepEqual([j.at(-1).kind, j.at(-1).note], ['restore', 'שוחזר מ-s1']);
    assert.equal(j.at(-1).result, j[0].result);
    assert.equal(f.version(v1), null, 'גרסת ביניים שנמחקה — null');
    assert.equal(f.version(j[1].result).flows.f1.doc.content[0].content[0].text, 'ד');

    // אותו קובץ ב-PHP: נפתח בלי אזהרות, ושמירה עם שינוי מוסיפה שלב בהמשך המספור
    const file = join(tmp, 'journal.mbe'), out = join(tmp, 'journal-php.mbe');
    writeFileSync(file, await M.save(f, { app: 'layout-studio' }));
    // הייצוגים מיושנים (preview לא חודש אחרי השינויים) — זו הכוונה; חוץ מזה — בלי בעיות
    assert.deepEqual(JSON.parse(php('validate', file)), { ok: true, codes: ['rendition-stale'] });
    php('resave', file, out, 'php-b');
    const g = await M.open(readFileSync(out));
    assert.deepEqual(g.journal(), f.journal(), 'שמירה ב-PHP בלי שינוי — לא נוגעת ביומן');
    assert.deepEqual(codes(g), ['rendition-stale']);

    const pruned = f.prune({ keep: 1 });
    assert.ok(pruned >= 1);
    await M.save(f, { app: 'layout-studio' });
    assert.ok(f.journal().some(s => s.pruned));
    assert.deepEqual(codes(f), ['rendition-stale'], 'אחרי גיזום — בלי אזהרות על ההיסטוריה');
    assert.throws(() => f.restore('s2'), /לא נמצאה/);
});

test('יומן פגום — נשמר כמו שהוא, ולא נכתב עליו', async () => {
    const f = await M.open(fx('hostile/history-invalid.mbe'));
    const before = f.files.get(M.JOURNAL);
    f.document.x = 1;
    await M.save(f, { app: 'x' });
    assert.deepEqual(Buffer.from(f.files.get(M.JOURNAL)), Buffer.from(before));
    assert.deepEqual(f.journal(), []);
});

test('מזהה פסקה (1.1): save משלים חסר, מחליף פגום וכפול, ולא נוגע בתקין; ב-PHP — אותו דבר', async () => {
    for (let i = 0; i < 200; i++) assert.match(M.paraId(), /^p[A-Za-z0-9]{10}$/);
    assert.equal(M.paragraphBookmark('p1'), '_mbe_pp1');
    assert.equal(M.bookmarkParaId('_mbe_pp1'), 'p1');
    assert.equal(M.bookmarkParaId('_GoBack'), null);
    const para = (id, text) => ({ type: 'paragraph', ...(id === undefined ? {} : { attrs: { id } }), content: [{ type: 'text', text }] });
    const f = M.create('text', { surfaces: [], flows: {
        f1: { engine: 'prosemirror', doc: { type: 'doc', content: [para('keep_1', 'א'), para(undefined, 'ב'), para('bad-id', 'ג'),
            { type: 'bulletList', content: [{ type: 'listItem', content: [para('keep_1', 'ד')] }] }, { type: 'heading', attrs: { level: 1 }, content: [] }] } },
        f2: { engine: 'other', doc: { type: 'doc', content: [para(undefined, 'ה')] } } } });
    f.setRendition(M.PREVIEW, (await M.open(fx('valid/text.mbe'))).files.get(M.PREVIEW));
    await M.save(f, { app: 'x' });
    const c = f.document.flows.f1.doc.content;
    const ids = [c[0].attrs.id, c[1].attrs.id, c[2].attrs.id, c[3].content[0].content[0].attrs.id, c[4].attrs.id];
    assert.equal(ids[0], 'keep_1', 'מזהה תקין לא משתנה');
    assert.ok(ids.slice(1).every(id => /^p[A-Za-z0-9]{10}$/.test(id)), ids.join());
    assert.equal(new Set(ids).size, 5, 'כל המזהים שונים');
    assert.equal(c[4].attrs.level, 1, 'שאר ה-attrs נשמרים');
    assert.equal(f.document.flows.f2.doc.content[0].attrs, undefined, 'שטף שאינו prosemirror — לא נוגעים');
    assert.deepEqual(codes(f), []);

    const file = join(tmp, 'para.mbe'), out = join(tmp, 'para-php.mbe');
    writeFileSync(file, await M.save(f, { app: 'x' }));
    php('resave', file, out, 'php-b');
    assert.deepEqual(Buffer.from(docBytesOf(readFileSync(out))), Buffer.from(docBytesOf(readFileSync(file))), 'PHP לא משנה מזהים קיימים');
});

// ---- פנים: stampFace / identify (docs/spec/hub-api.md 2.1–2.2) ----

import { stampFace, identify, xmpPacket } from '../src/js/face.js';

const NOW = new Date('2026-10-25T10:00:00Z');
const savedText = async () => {
    const f = await M.open(fx('valid/text.mbe'));
    const bytes = await M.save(f, { app: 'js-face', now: NOW });
    return { f, bytes };
};
const zipParts = b => Object.fromEntries(readZip(b).entries.map(e => [e.name, Buffer.from(e.read())]));

test('stampFace docx — מזהים, מודל ב-customXml, ושאר החלקים בית-לבית', async () => {
    const { f, bytes } = await savedText();
    const docx = readFileSync(join(here, 'faces/plain.docx'));
    const out = await stampFace(f, 'docx', docx);
    const before = zipParts(docx), after = zipParts(out);
    const touched = ['[Content_Types].xml', '_rels/.rels', 'word/_rels/document.xml.rels'];
    for (const [n, d] of Object.entries(before)) if (!touched.includes(n)) assert.deepEqual(after[n], d, n);
    for (const n of ['docProps/custom.xml', 'customXml/item1.xml', 'customXml/itemProps1.xml', 'customXml/_rels/item1.xml.rels']) assert.ok(after[n], n);
    const id = await identify(out);
    assert.equal(id.type, 'face');
    assert.equal(id.format, 'docx');
    assert.equal(id.docId, f.id);
    assert.equal(id.base, f.documentSha256);
    assert.equal(id.model, true);
    assert.equal(canonicalJson(id.document), canonicalJson(f.document));
    assert.deepEqual(id.issues, []);
    // החתמה שנייה מחליפה — לא מכפילה
    const again = zipParts(await stampFace(f, 'docx', out));
    assert.deepEqual(Object.keys(again).sort(), Object.keys(after).sort());
    assert.equal((again['docProps/custom.xml'].toString().match(/mbe-doc-id/g) || []).length, 1);
    // ה-PHP מזהה אותו דבר
    const file = join(tmp, 'face.docx');
    writeFileSync(file, out);
    const p = JSON.parse(php('identify', file));
    assert.deepEqual([p.type, p.format, p.docId, p.base, p.model], ['face', 'docx', f.id, f.documentSha256, true]);
    assert.equal(canonicalJson(p.document), canonicalJson(f.document));
    void bytes;
    // שלב export — בשמירה הבאה, פעם אחת
    await M.save(f, { app: 'js-face', now: NOW });
    const ex = f.journal().filter(s => s.kind === 'export');
    assert.deepEqual(ex.map(s => s.note), ['docx']);
});

test('stampFace ב-PHP — JS מזהה, וזה docx שעובר unzip', async () => {
    const { f, bytes } = await savedText();
    const mbe = join(tmp, 'face-src.mbe'), out = join(tmp, 'face-php.docx');
    writeFileSync(mbe, bytes);
    assert.deepEqual(JSON.parse(php('stamp', mbe, 'docx', join(here, 'faces/plain.docx'), out)), { ok: true });
    const id = await identify(readFileSync(out));
    assert.deepEqual([id.type, id.docId, id.base, id.model], ['face', f.id, f.documentSha256, true]);
    execFileSync('unzip', ['-tq', out]);
});

test('stampFace png — iTXt אחרי IHDR, הפיקסלים לא נגעו', async () => {
    const { f } = await savedText();
    const png = f.files.get(M.PREVIEW);
    const out = await stampFace(f, 'png', png);
    assert.equal(Buffer.from(out.subarray(12, 16)).toString(), 'IHDR');
    assert.equal(Buffer.from(out.subarray(37, 41)).toString(), 'iTXt');
    const id = await identify(out);
    assert.deepEqual([id.type, id.format, id.docId, id.base, id.model], ['face', 'png', f.id, f.documentSha256, false]);
    assert.deepEqual(await stampFace(f, 'png', out), out, 'החתמה חוזרת זהה');
    const file = join(tmp, 'face.png');
    writeFileSync(file, out);
    const p = JSON.parse(php('identify', file));
    assert.deepEqual([p.type, p.docId, p.base], ['face', f.id, f.documentSha256]);
    // PHP מחתים בדיוק אותם בתים
    const mbe = join(tmp, 'face-src2.mbe'), src = join(tmp, 'plain.png'), outp = join(tmp, 'face-php.png');
    writeFileSync(mbe, await M.save(f, { app: 'js-face', now: NOW }));
    writeFileSync(src, png);
    php('stamp', mbe, 'png', src, outp);
    assert.deepEqual(Buffer.from(readFileSync(outp)), Buffer.from(out));
});

test('xmpPacket → identify מ-PDF; mbe; קובץ זר', async () => {
    const { f, bytes } = await savedText();
    const pdf = utf8(`%PDF-1.7\n1 0 obj\n<< /Type /Metadata /Subtype /XML >>\nstream\n${await xmpPacket(f)}\nendstream\nendobj\n%%EOF\n`);
    const id = await identify(pdf);
    assert.deepEqual([id.type, id.format, id.docId, id.base], ['face', 'pdf', f.id, f.documentSha256]);
    const file = join(tmp, 'face.pdf');
    writeFileSync(file, pdf);
    const p = JSON.parse(php('identify', file));
    assert.deepEqual([p.type, p.format, p.docId, p.base], ['face', 'pdf', f.id, f.documentSha256]);

    const m = await identify(bytes);
    assert.deepEqual([m.type, m.format, m.docId, m.base, m.model], ['mbe', 'mbe', f.id, f.documentSha256, true]);
    for (const b of [readFileSync(join(here, 'faces/plain.docx')), utf8('%PDF-1.4\n%%EOF'), f.files.get(M.PREVIEW), utf8('שלום'), new Uint8Array()]) {
        const r = await identify(b);
        assert.equal(r.type, 'foreign');
        assert.equal(r.docId, null);
    }
    const bad = await identify(utf8('PK\x03\x04garbage'));
    assert.equal(bad.type, 'foreign');
});

test('face-not-saved — מסמך שהשתנה אחרי save, או שלא נשמר', async () => {
    const { f } = await savedText();
    f.document.surfaces.push({ id: 'zz', type: 'page' });
    for (const fmt of ['docx', 'png']) {
        await assert.rejects(stampFace(f, fmt, readFileSync(join(here, 'faces/plain.docx'))), e => e.issues[0].code === 'face-not-saved');
    }
    await assert.rejects(xmpPacket(f), e => e.issues[0].code === 'face-not-saved');
    await assert.rejects(stampFace(M.create('text'), 'docx', readFileSync(join(here, 'faces/plain.docx'))), e => e.issues[0].code === 'face-not-saved');
    const { f: g } = await savedText();
    await assert.rejects(stampFace(g, 'pdf', new Uint8Array()), e => e.issues[0].code === 'face-stamp-failed');
    await assert.rejects(stampFace(g, 'docx', utf8('not a zip')), e => e.issues[0].code === 'face-stamp-failed');
    await assert.rejects(stampFace(g, 'png', utf8('not a png')), e => e.issues[0].code === 'face-stamp-failed');
    const file = join(tmp, 'changed.mbe');
    writeFileSync(file, await M.save(g, { app: 'js-face', now: NOW }));
    assert.deepEqual(JSON.parse(php('stamp', file, 'pdf', file, join(tmp, 'x'))), { error: 'face-stamp-failed' });
});

// ---- מיזוג: reconcile / applyMerge (hub-api.md 2.3–2.4, סעיף 3) ----

import { reconcile, applyMerge, alignByContent, MERGE_SPAN } from '../src/js/merge.js';

const P = (id, text) => ({ type: 'paragraph', ...(id ? { attrs: { id } } : {}), content: [{ type: 'text', text }] });
const START = [P('pa', 'פסקה ראשונה על הים'), P('pb', 'פסקה שנייה על ההרים הגבוהים'), P('123', 'מזהה שכולו ספרות'),
    P('pd', 'פסקה רביעית שתימחק'), { type: 'horizontalRule' }, P('pe', 'פסקה אחרונה וארוכה למדי עם הרבה מילים בתוכה')];
const flowOf = f => f.document.flows.f1.doc.content;
const texts = f => flowOf(f).map(b => b.content?.[0]?.text ?? b.type);
async function mergeBase() {
    const f = await M.open(fx('valid/text.mbe'));
    f.document.flows.f1.doc.content = structuredClone(START);
    await M.save(f, { app: 'js-merge', now: NOW });
    return f;
}
const theirsDoc = blocks => ({ type: 'doc', content: structuredClone(blocks) });
const KNOWN = new Set(['pa', 'pb', '123', 'pd', 'pe']);
const summary = p => ({
    status: p.status,
    changes: p.changes.map(c => [c.flow, KNOWN.has(c.para) || c.para.startsWith('~') ? c.para : 'NEW', c.op]),
    conflicts: p.conflicts.map(c => [c.flow, c.para, c.kind]),
    gaps: p.gaps, codes: p.issues.map(i => i.code),
});
const summaryPhp = async (f, opts) => {
    const mbe = join(tmp, 'merge.mbe'), o = join(tmp, 'merge-opts.json');
    writeFileSync(mbe, await M.save(f, { app: 'js-merge', now: NOW }));
    writeFileSync(o, JSON.stringify(opts));
    const p = JSON.parse(php('reconcile', mbe, o));
    p.changes = p.changes.map(([fl, k, op]) => [fl, KNOWN.has(k) || k.startsWith('~') ? k : 'NEW', op]);
    return p;
};

test('reconcile: same, fast-forward (עריכה, מחיקה, פסקה חדשה בלי מזהה), other-doc — ו-PHP זהה', async () => {
    const f = await mergeBase();
    const opts = blocks => ({ docId: f.id, base: f.documentSha256, flows: { f1: theirsDoc(blocks) }, app: 'external:word', who: 'המגיה' });

    const same = await reconcile(f, opts(START));
    assert.equal(same.status, 'same');
    assert.deepEqual(await summaryPhp(f, opts(START)), summary(same));

    const t = structuredClone(START);
    t[1] = P('pb', 'פסקה שנייה על ההרים הגבוהים מאוד');
    t.splice(3, 1);                                  // pd נמחקה
    t.splice(1, 0, P(null, 'פסקה חדשה מ-Word'));      // בלי סימנייה
    const plan = await reconcile(f, opts(t));
    assert.equal(plan.status, 'fast-forward');
    assert.deepEqual(summary(plan).changes, [['f1', 'NEW', 'insert'], ['f1', 'pb', 'edit'], ['f1', 'pd', 'delete']].sort((a, b) => 0) && summary(plan).changes);
    assert.deepEqual(new Set(summary(plan).changes.map(c => c.join('/'))), new Set(['f1/pb/edit', 'f1/pd/delete', 'f1/NEW/insert']));
    assert.deepEqual(plan.conflicts, []);
    assert.deepEqual(await summaryPhp(f, opts(t)), summary(plan));
    const before = canonicalJson(f.document);
    assert.equal(canonicalJson(f.document), before, 'reconcile לא נוגע ב-f');

    await applyMerge(f, plan, {});
    assert.deepEqual(texts(f), ['פסקה ראשונה על הים', 'פסקה חדשה מ-Word', 'פסקה שנייה על ההרים הגבוהים מאוד', 'מזהה שכולו ספרות', 'horizontalRule', 'פסקה אחרונה וארוכה למדי עם הרבה מילים בתוכה']);
    assert.match(flowOf(f)[1].attrs.id, /^p[A-Za-z0-9]{10}$/);
    await M.save(f, { app: 'js-merge', now: NOW });
    const j = f.journal();
    assert.deepEqual(j.slice(-2).map(s => [s.kind, s.app]), [['return', 'external:word'], ['merge', 'js-merge']]);
    assert.ok(f.version(j[j.length - 2].result), 'הגרסה שחזרה שמורה');
    assert.deepEqual(codes(f).filter(c => c.startsWith('history')), []);

    const other = await reconcile(f, { ...opts(START), docId: M.newId() });
    assert.equal(other.status, 'other-doc');
    assert.deepEqual(other.issues.map(i => i.code), ['face-unknown-doc']);
    await assert.rejects(applyMerge(f, other, {}), e => e.issues[0].code === 'face-unknown-doc');
    const nobase = await reconcile(f, { ...opts(START), base: 'a'.repeat(64) });
    assert.deepEqual(nobase.issues.map(i => i.code), ['face-base-missing']);
});

test('reconcile: diverged — both-edited, edited-deleted, תשובות, merge-unanswered; וגם ב-PHP מקצה לקצה', async () => {
    const f = await mergeBase();
    const base = f.documentSha256;
    await stampFace(f, 'docx', readFileSync(join(here, 'faces/plain.docx'))); // יצא החוצה — הגרסה הזו נשמרת כבסיס
    flowOf(f)[1] = P('pb', 'אצלנו: פסקה שנייה על ההרים');
    flowOf(f)[2] = P('123', 'אצלנו: מזהה שכולו ספרות, ערוך');
    await M.save(f, { app: 'js-merge', now: NOW });
    const t = structuredClone(START);
    t[1] = P('pb', 'מבחוץ: פסקה שנייה על ההרים');
    t.splice(2, 1);                                                  // 123 — נמחקה מבחוץ, ונערכה אצלנו
    t[t.length - 1] = P('pe', 'פסקה אחרונה וארוכה למדי עם הרבה מילים בתוכה, ועוד');
    const opts = { docId: f.id, base, flows: { f1: theirsDoc(t) }, app: 'external:word' };
    const plan = await reconcile(f, opts);
    assert.equal(plan.status, 'diverged');
    assert.deepEqual(summary(plan).conflicts, [['f1', 'pb', 'both-edited'], ['f1', '123', 'edited-deleted']]);
    assert.deepEqual(summary(plan).changes, [['f1', 'pe', 'edit']]);
    assert.deepEqual(await summaryPhp(f, opts), summary(plan));

    await assert.rejects(applyMerge(f, plan, { 'f1/pb': 'ours' }), e => e.issues.map(i => i.code).join() === 'merge-unanswered');
    await applyMerge(f, plan, { 'f1/pb': 'both', 'f1/123': 'ours' });
    assert.deepEqual(texts(f), ['פסקה ראשונה על הים', 'אצלנו: פסקה שנייה על ההרים', 'מבחוץ: פסקה שנייה על ההרים', 'אצלנו: מזהה שכולו ספרות, ערוך',
        'פסקה רביעית שתימחק', 'horizontalRule', 'פסקה אחרונה וארוכה למדי עם הרבה מילים בתוכה, ועוד']);
    const ids = flowOf(f).filter(b => b.attrs).map(b => b.attrs.id);
    assert.equal(new Set(ids).size, ids.length, '"both" — השנייה מקבלת מזהה חדש');

    // PHP מקצה לקצה: אותה תוצאה בטקסט
    const g = await M.open(fx('valid/text.mbe'));
    g.document.flows.f1.doc.content = structuredClone(START);
    await M.save(g, { app: 'js-merge', now: NOW });
    const gBase = g.documentSha256;
    await stampFace(g, 'docx', readFileSync(join(here, 'faces/plain.docx')));
    flowOf(g)[1] = P('pb', 'אצלנו: פסקה שנייה על ההרים');
    flowOf(g)[2] = P('123', 'אצלנו: מזהה שכולו ספרות, ערוך');
    const mbe = join(tmp, 'm2.mbe'), o = join(tmp, 'm2.json'), a = join(tmp, 'm2-a.json'), out = join(tmp, 'm2-out.mbe');
    writeFileSync(mbe, await M.save(g, { app: 'js-merge', now: NOW }));
    writeFileSync(o, JSON.stringify({ ...opts, docId: g.id, base: gBase }));
    writeFileSync(a, JSON.stringify({ 'f1/pb': 'ours' }));
    assert.deepEqual(JSON.parse(php('merge', mbe, o, a, out)), { error: ['merge-unanswered'] });
    writeFileSync(a, JSON.stringify({ 'f1/pb': 'both', 'f1/123': 'ours' }));
    assert.deepEqual(JSON.parse(php('merge', mbe, o, a, out)), { ok: true });
    const h = await M.open(readFileSync(out));
    assert.deepEqual(codes(h), ['rendition-stale']); // preview לא חודש במיזוג — צפוי
    assert.deepEqual(texts(h), texts(f));
    assert.deepEqual(h.journal().slice(-2).map(s => s.kind), ['return', 'merge']);
});

test('reconcile: בלי זיכרון — הבסיס מ-customXml; בלי סימניות — לפי תוכן; הזזה; gaps', async () => {
    const f = await mergeBase();
    const base = f.documentSha256, baseDocument = structuredClone(f.document);
    flowOf(f)[0] = P('pa', 'אצלנו: פסקה ראשונה על הים');
    await M.save(f, { app: 'js-merge', now: NOW });
    f.files.delete(`history/${base}.json`);                       // הבסיס כבר לא בזיכרון
    const strip = b => { const c = structuredClone(b); delete c.attrs; return c; };
    const t = [START[5], START[0], START[1], START[2], START[4]].map(strip); // pe הוזז להתחלה, pd נמחקה — ואין סימניות בכלל
    t[3] = P(null, 'מזהה שכולו ספרות ממש');                         // נערכה (Dice)
    const opts = { docId: f.id, base, flows: { f1: theirsDoc(t) }, styles: ['Heading 4'], app: 'external:word' };
    assert.deepEqual((await reconcile(f, { ...opts, baseDocument: undefined })).issues.map(i => i.code), ['face-base-missing']);
    const plan = await reconcile(f, { ...opts, baseDocument });
    assert.equal(plan.status, 'diverged');
    assert.deepEqual(new Set(summary(plan).changes.map(c => c.slice(1).join('/'))), new Set(['123/edit', 'pd/delete', 'pe/move']));
    assert.deepEqual(plan.conflicts, []);
    assert.ok(plan.gaps.includes('merge:by-content — 4 פסקאות הותאמו לפי תוכן (בלי סימנייה)'), plan.gaps.join());
    assert.ok(plan.gaps.includes('style:Heading 4 — לא קיים במסמך'));
    const phpOpts = { ...opts, baseDocument };
    assert.deepEqual(await summaryPhp(f, phpOpts), summary(plan));
    await applyMerge(f, plan, {});
    assert.deepEqual(texts(f), ['פסקה אחרונה וארוכה למדי עם הרבה מילים בתוכה', 'אצלנו: פסקה ראשונה על הים', 'פסקה שנייה על ההרים הגבוהים',
        'מזהה שכולו ספרות ממש', 'horizontalRule']);
    await M.save(f, { app: 'js-merge', now: NOW });
    assert.ok(f.files.has(`history/${base}.json`), 'הבסיס מ-customXml נשמר בהיסטוריה');
    assert.deepEqual(codes(f).filter(c => c.startsWith('history')), []);
});

test('alignByContent: עוגנים, Dice, ומגבלת הקטע — זהה ב-JS וב-PHP', () => {
    const r = alignByContent(['א ב ג', 'ד ה ו ז', 'ח', 'ט י כ ל'], ['א ב ג', 'ד ה ו ז ש', 'ט י כ ל', 'חדש לגמרי']);
    assert.deepEqual(r.pairs, [[0, 0], [1, 1], [3, 2]]);
    const big = alignByContent(Array(MERGE_SPAN + 1).fill('x'), Array(MERGE_SPAN + 1).fill('y'));
    assert.deepEqual([big.pairs.length, big.limited], [0, true]);
});
