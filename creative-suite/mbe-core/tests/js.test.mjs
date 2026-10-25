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

