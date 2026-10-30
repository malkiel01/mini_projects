// ניסוי נ3 (Google Docs): docx מוחתם עם פסקאות בעברית וסימנייה _mbe_p<id> לכל פסקה, ולידו הזיכרון (.mbe) שלו.
//   node creative-suite/mbe-core/tests/faces/make-gdocs.mjs
// בעל המערכת מעלה את gdocs.docx ל-Google Drive, פותח ב-Google Docs, עורך משפט ומוריד כ-docx; את מה שחזר בודקים כך:
//   identify (נשארו mbe-doc-id / mbe-base / customXml?) · אילו סימניות _mbe_p שרדו · reconcile מול gdocs.mbe.

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as M from '../../src/js/mbe.js';
import { stampFace, identify } from '../../src/js/face.js';
import { readZip, writeZip } from '../../src/js/zip.js';
import { utf8 } from '../../src/js/canonical.js';

const here = dirname(fileURLToPath(import.meta.url));
const PARAS = [
    ['g001', 'ניסוי נ3 — האם Google Docs שומר את המזהים של חבילת היצירה.'],
    ['g002', 'זו הפסקה הראשונה של הגוף. אפשר לערוך בה משפט אחד, כדי לראות שהשינוי חוזר.'],
    ['g003', 'פסקה שנייה, עם English באמצע ומספר 2026 — כדי לבדוק כיווניות מעורבת.'],
    ['g004', 'פסקה שלישית: אפשר למחוק אותה, או להעביר אותה למקום אחר.'],
    ['g005', 'פסקה אחרונה. אחריה אפשר להוסיף פסקה חדשה משלכם.'],
];

const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const body = PARAS.map(([id, t], i) =>
    `<w:p><w:pPr><w:bidi/><w:jc w:val="right"/></w:pPr><w:bookmarkStart w:id="${i}" w:name="${M.paragraphBookmark(id)}"/>`
    + `<w:r><w:rPr><w:rtl/></w:rPr><w:t xml:space="preserve">${esc(t)}</w:t></w:r><w:bookmarkEnd w:id="${i}"/></w:p>`).join('');

// ה-docx: חבילה של LibreOffice (plain.docx), עם גוף משלנו
const plain = readFileSync(join(here, 'plain.docx'));
const parts = readZip(plain).entries.map(e => ({ name: e.name, data: e.read() }));
const doc = parts.find(p => p.name === 'word/document.xml');
doc.data = utf8(new TextDecoder().decode(doc.data).replace(/<w:body>[\s\S]*?(<w:sectPr)/, `<w:body>${body}$1`));
const docx = writeZip(parts, new Date('2026-10-30T12:00:00Z'));

// הזיכרון: אותו טקסט, אותם מזהים
const f = await M.open(readFileSync(join(here, '..', 'fixtures', 'valid', 'text.mbe')));
f.document.flows.f1.doc.content = PARAS.map(([id, t]) => ({ type: 'paragraph', attrs: { id }, content: [{ type: 'text', text: t }] }));
await M.save(f, { app: 'mbe-core', now: new Date('2026-10-30T12:00:00Z') });
const stamped = await stampFace(f, 'docx', docx);
const mbe = await M.save(f, { app: 'mbe-core', now: new Date('2026-10-30T12:00:00Z') }); // שלב export נכתב

writeFileSync(join(here, 'gdocs.docx'), stamped);
writeFileSync(join(here, 'gdocs.mbe'), mbe);
const id = await identify(stamped);
console.log(JSON.stringify({ docId: id.docId, base: id.base, model: id.model, journal: f.journal().map(s => s.kind) }));
