// בדיקות ייבוא Word: node --test layout-studio/tests/docx.test.mjs
// הקובץ tests/fixtures/sample.docx נוצר ב-python-docx (Word אמיתי היה נותן אותו מבנה).
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { docxToDoc, parseXml, reportLines, readZip } from '../assets/js/docx.js';
import { docBlocks } from '../assets/js/frames.js';

const bytes = readFileSync(new URL('./fixtures/sample.docx', import.meta.url));

test('מפרק XML: מאפיינים, ישויות ותגית סגורה', () => {
  const x = parseXml('<?xml version="1.0"?><a b="1&amp;2"><c/>x &lt; y<d e=\'q\'>t</d></a>');
  const a = x.children[0];
  assert.equal(a.attrs.b, '1&2');
  assert.equal(a.children[0].name, 'c');
  assert.equal(a.children[1].text, 'x < y');
  assert.equal(a.children[2].attrs.e, 'q');
});

test('docx → ProseMirror: כותרות, מודגש, ניקוד וטעמים, שבירת שורה', async () => {
  const { doc } = await docxToDoc(bytes);
  const c = doc.content;
  assert.deepEqual(c[0], { type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'פרק א' }] });
  assert.equal(c[1].type, 'paragraph');
  assert.deepEqual(c[1].content.map(n => n.marks ? 'B' : '-'), ['-', 'B', '-']);
  assert.equal(c[1].content[1].text, 'אֱלֹהִ֑ים');
  assert.ok(c[1].content[2].text.includes('& "הארץ"'));
  assert.deepEqual(c[2].content.map(n => n.type), ['text', 'hardBreak', 'text']);
  assert.equal(c[2].content[2].text, 'שורה שנייה אחרי טאב');
  assert.equal(c[3].type, 'paragraph');           // פסקה ריקה נשארת
  assert.equal(c[3].content, undefined);
  assert.deepEqual(c[4].attrs, { level: 2 });
  const last = c[c.length - 1];
  assert.equal(last.content[0].text, 'סוף');      // פסקאות ריקות בסוף — יורדות
  // כותרת 4 → 3
  assert.ok(c.some(n => n.type === 'heading' && n.attrs.level === 3 && n.content[0].text === 'תת סעיף'));
});

test('טבלה הופכת לפסקאות, רשימה בלי מספור — והדוח אומר', async () => {
  const { doc, report } = await docxToDoc(bytes);
  const texts = doc.content.map(n => (n.content || []).map(x => x.text || '').join(''));
  assert.ok(texts.includes('תא 1') && texts.includes('תא 2'));
  assert.equal(report.tables, 1);
  assert.equal(report.lists, 1);
  const lines = reportLines(report);
  assert.ok(lines.some(l => l.includes('טבלאות')) && lines.some(l => l.includes('רשימה')));
});

test('המסמך המיובא נפרס כמו כל שטף: כותרות ומודגש', async () => {
  const { doc } = await docxToDoc(bytes);
  const b = docBlocks(doc);
  assert.equal(b[0].style, 'h1');
  assert.ok(b[1].runs.some(r => r.bold && r.text === 'אֱלֹהִ֑ים'));
});

test('קובץ שאינו docx — נדחה', async () => {
  await assert.rejects(docxToDoc(new TextEncoder().encode('hello')), /not_zip/);
  const zipOnly = await readZip(bytes, ['nothing.xml']);
  assert.equal(zipOnly.size, 0);
});
