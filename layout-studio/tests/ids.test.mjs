// בדיקות מזהה הפסקה (mbe-format.md סעיף 3): node --test layout-studio/tests/ids.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { withParaIds, textToDoc, PARA_ID, autoflow } from '../assets/js/frames.js';
import { sanitize, makeSchema, idsPlugin } from '../assets/js/story.js';
import { docxToDoc } from '../assets/js/docx.js';

const P = await import('../assets/vendor/prosemirror/prosemirror.mjs');
const S = makeSchema(P);
const ids = doc => doc.content.map(n => n.attrs?.id);
const para = (text, id) => ({ type: 'paragraph', ...(id ? { attrs: { id } } : {}), content: [{ type: 'text', text }] });

test('השלמה: חסר או לא תקין — חדש; כפול — השני חדש; קיימים — נשמרים', () => {
  const d = withParaIds({ type: 'doc', content: [para('א', 'keep1'), para('ב'), para('ג', 'keep1'), para('ד', 'רע!'),
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'ה' }] }] });
  const got = ids(d);
  assert.equal(got[0], 'keep1');
  assert.equal(new Set(got).size, 5);
  assert.ok(got.every(x => PARA_ID.test(x)));
  assert.equal(d.content[4].attrs.level, 2);
  const same = { type: 'doc', content: [para('א', 'a1'), para('ב', 'b2')] };
  assert.equal(withParaIds(same), same);   // אין מה להשלים — אותו אובייקט
});

test('טקסט חדש, ייבוא מ-Word ושטף חדש — עם מזהים', async () => {
  assert.ok(ids(textToDoc('שורה\n\nעוד')).every(x => PARA_ID.test(x)));
  const b = readFileSync(new URL('./fixtures/sample.docx', import.meta.url));
  const { doc } = await docxToDoc(b);
  const got = ids(doc);
  assert.ok(got.length > 3 && got.every(x => PARA_ID.test(x)) && new Set(got).size === got.length);
});

test('sanitize שומר את המזהה, ומוריד מזהה לא תקין', () => {
  const { doc } = sanitize({ type: 'doc', content: [para('א', 'x1'), para('ב', '../x'), { type: 'heading', attrs: { level: 1, id: 'h1' } }] });
  assert.deepEqual(ids(doc), ['x1', undefined, 'h1']);
});

const stateOf = doc => P.EditorState.create({ doc: S.nodeFromJSON(withParaIds(sanitize(doc).doc)), plugins: [idsPlugin(P)] });

test('בעורך: הקלדה ושינוי סגנון — המזהה נשאר; פיצול — הראשון שומר, השני חדש', () => {
  let st = stateOf({ type: 'doc', content: [para('שלום עולם', 'first'), para('שנייה', 'second')] });
  st = st.apply(st.tr.insertText('!', 3));
  st = st.apply(st.tr.setBlockType(1, 2, S.nodes.heading, { level: 2 }));
  assert.deepEqual(ids(st.doc.toJSON()), ['first', 'second']);
  assert.equal(st.doc.firstChild.type.name, 'heading');
  st = st.apply(st.tr.split(5));   // באמצע הפסקה הראשונה
  const got = ids(st.doc.toJSON());
  assert.equal(got.length, 3);
  assert.equal(got[0], 'first');
  assert.equal(got[2], 'second');
  assert.ok(PARA_ID.test(got[1]) && !['first', 'second'].includes(got[1]));
});

test('בעורך: הדבקה של פסקה קיימת לפני המקור — המקור שומר, העותק חדש', () => {
  let st = stateOf({ type: 'doc', content: [para('א', 'aa'), para('ב', 'bb')] });
  const copy = st.doc.child(1);   // פסקה ב, עם המזהה bb
  st = st.apply(st.tr.insert(0, copy));
  const got = ids(st.doc.toJSON());
  assert.equal(got.length, 3);
  assert.deepEqual([got[1], got[2]], ['aa', 'bb']);
  assert.ok(!['aa', 'bb'].includes(got[0]));
});
