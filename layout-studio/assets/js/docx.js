// ייבוא טקסט מ-Word (docx) → ProseMirror, בשמות של עורך התמלילים (paragraph, heading, hardBreak, bold).
// בלי ספרייה: ZIP נקרא כאן, הדחיסה נפתחת ב-DecompressionStream של הדפדפן, וה-XML — במפרק קטן משלנו
// (עובד גם ב-node, ולכן נבדק ב-tests/docx.test.mjs).
//
// מה עובר: פסקאות, כותרות 1–3 (לפי שם הסגנון — באנגלית או בעברית), מודגש, שבירת שורה, טאב (כרווח), ניקוד וטעמים.
// מה לא — ונכתב בדוח: טבלאות (התאים הופכים לפסקאות), תמונות, מספור רשימות, הערות שוליים, תיבות טקסט.

// ===== ZIP =====
const u32 = (b, o) => (b[o] | b[o + 1] << 8 | b[o + 2] << 16 | b[o + 3] << 24) >>> 0;
const u16 = (b, o) => b[o] | b[o + 1] << 8;

async function inflateRaw(data) {
  const ds = new DecompressionStream('deflate-raw');
  const out = new Response(new Blob([data]).stream().pipeThrough(ds));
  return new Uint8Array(await out.arrayBuffer());
}

/** הקבצים שביקשנו מתוך ה-ZIP: Map(שם → Uint8Array). שם שלא קיים — לא במפה. */
export async function readZip(bytes, wanted) {
  const b = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  let eocd = -1;
  for (let i = b.length - 22; i >= Math.max(0, b.length - 65557); i--) if (u32(b, i) === 0x06054b50) { eocd = i; break; }
  if (eocd < 0) throw new Error('not_zip');
  const count = u16(b, eocd + 10);
  let p = u32(b, eocd + 16);
  const out = new Map();
  const dec = new TextDecoder();
  for (let n = 0; n < count; n++) {
    if (u32(b, p) !== 0x02014b50) throw new Error('bad_zip');
    const method = u16(b, p + 10), csize = u32(b, p + 20), nlen = u16(b, p + 28), xlen = u16(b, p + 30), clen = u16(b, p + 32);
    const local = u32(b, p + 42);
    const name = dec.decode(b.subarray(p + 46, p + 46 + nlen));
    p += 46 + nlen + xlen + clen;
    if (!wanted.includes(name)) continue;
    const start = local + 30 + u16(b, local + 26) + u16(b, local + 28);
    const raw = b.subarray(start, start + csize);
    if (method === 0) out.set(name, raw);
    else if (method === 8) out.set(name, await inflateRaw(raw));
    else throw new Error('zip_method');
  }
  return out;
}

// ===== XML (מספיק ל-docx: תגיות, מאפיינים, טקסט; בלי DTD) =====
const ENT = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
const unescape = s => s.replace(/&(#x[0-9a-f]+|#\d+|\w+);/gi, (m, e) =>
  e[0] === '#' ? String.fromCodePoint(e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10)) : ENT[e] ?? m);

export function parseXml(str) {
  const root = { name: '#root', attrs: {}, children: [] };
  const stack = [root];
  const re = /<(\/?)([\w:.-]+)((?:\s+[\w:.-]+\s*=\s*(?:"[^"]*"|'[^']*'))*)\s*(\/?)>|<\?[\s\S]*?\?>|<!--[\s\S]*?-->|<!\[CDATA\[([\s\S]*?)\]\]>|([^<]+)/g;
  let m;
  while ((m = re.exec(str))) {
    const top = stack[stack.length - 1];
    if (m[6] !== undefined) { top.children.push({ text: unescape(m[6]) }); continue; }
    if (m[5] !== undefined) { top.children.push({ text: m[5] }); continue; }
    if (!m[2]) continue;   // הוראת עיבוד / הערה
    if (m[1]) { if (stack.length > 1) stack.pop(); continue; }
    const attrs = {};
    for (const a of m[3].matchAll(/([\w:.-]+)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) attrs[a[1]] = unescape(a[2] ?? a[3]);
    const el = { name: m[2], attrs, children: [] };
    top.children.push(el);
    if (!m[4]) stack.push(el);
  }
  return root;
}

const kids = (el, name) => (el?.children || []).filter(c => c.name === name);
const kid = (el, name) => (el?.children || []).find(c => c.name === name);
const textOf = el => el.text !== undefined ? el.text : (el.children || []).map(textOf).join('');
const on = el => !!el && !['0', 'false', 'off', 'none'].includes(String(el.attrs['w:val'] ?? 'true').toLowerCase());

// ===== docx → ProseMirror =====

/**
 * מיפוי סגנונות פסקה: heads — styleId → 1–3 לכותרות (לפי השם: heading 1 / כותרת 1 / Title);
 * lists — סגנונות עם מספור משלהם (Word שם את המספור לפעמים בסגנון ולא בפסקה).
 */
function paragraphStyles(stylesXml) {
  const map = new Map(), lists = new Set();
  if (!stylesXml) return { heads: map, lists };
  for (const st of kids(kid(parseXml(stylesXml), 'w:styles'), 'w:style')) {
    if (st.attrs['w:type'] !== 'paragraph') continue;
    if (kid(kid(st, 'w:pPr'), 'w:numPr')) lists.add(st.attrs['w:styleId']);
    const name = (kid(st, 'w:name')?.attrs['w:val'] || '').toLowerCase().trim();
    const m = name.match(/^(?:heading|כותרת)\s*([1-9])$/);
    if (m) map.set(st.attrs['w:styleId'], Math.min(3, Number(m[1])));
    else if (name === 'title' || name === 'כותרת טקסט' || name === 'כותרת') map.set(st.attrs['w:styleId'], 1);
  }
  return { heads: map, lists };
}

/**
 * docx (ArrayBuffer / Uint8Array) → { doc, report }.
 *   doc — ProseMirror JSON (doc → paragraph / heading{level} → text {marks: bold} / hardBreak)
 *   report — { paragraphs, tables, images, lists, footnotes, textboxes } — מה עבר ומה לא, לדוח הייבוא.
 */
export async function docxToDoc(bytes) {
  const files = await readZip(bytes, ['word/document.xml', 'word/styles.xml', 'word/footnotes.xml']);
  const main = files.get('word/document.xml');
  if (!main) throw new Error('not_docx');
  const dec = new TextDecoder();
  const { heads, lists } = paragraphStyles(files.has('word/styles.xml') ? dec.decode(files.get('word/styles.xml')) : null);
  const body = kid(kid(parseXml(dec.decode(main)), 'w:document'), 'w:body');
  const report = { paragraphs: 0, tables: 0, images: 0, lists: 0, footnotes: 0, textboxes: 0 };
  if (files.has('word/footnotes.xml')) {
    report.footnotes = kids(kid(parseXml(dec.decode(files.get('word/footnotes.xml'))), 'w:footnotes'), 'w:footnote')
      .filter(f => !['separator', 'continuationSeparator'].includes(f.attrs['w:type'])).length;
  }
  const out = [];

  const runInline = (r, inline) => {
    const bold = on(kid(kid(r, 'w:rPr'), 'w:b')) || on(kid(kid(r, 'w:rPr'), 'w:bCs'));
    const add = t => {
      const last = inline[inline.length - 1];
      if (last && last.type === 'text' && !!last.marks === bold) last.text += t;
      else inline.push({ type: 'text', text: t, ...(bold ? { marks: [{ type: 'bold' }] } : {}) });
    };
    for (const c of r.children || []) {
      if (c.name === 'w:t') { const t = textOf(c); if (t) add(t); }
      else if (c.name === 'w:tab') add(' ');
      else if (c.name === 'w:br' && (c.attrs['w:type'] || 'textWrapping') === 'textWrapping') inline.push({ type: 'hardBreak' });
      else if (c.name === 'w:drawing' || c.name === 'w:pict') {
        if (JSON.stringify(c).includes('w:txbxContent')) report.textboxes++; else report.images++;
      }
    }
  };
  const paragraph = p => {
    const pPr = kid(p, 'w:pPr');
    const styleId = kid(pPr, 'w:pStyle')?.attrs['w:val'];
    const level = heads.get(styleId);
    if (kid(pPr, 'w:numPr') || lists.has(styleId)) report.lists++;
    const inline = [];
    const walk = el => {
      for (const c of el.children || []) {
        if (c.name === 'w:r') runInline(c, inline);
        else if (['w:hyperlink', 'w:smartTag', 'w:ins', 'w:sdt', 'w:sdtContent', 'w:fldSimple'].includes(c.name)) walk(c);
      }
    };
    walk(p);
    // רווחים בקצוות — יורדים; פסקה ריקה — נשארת (שורה ריקה)
    if (inline.length && inline[0].type === 'text') inline[0].text = inline[0].text.replace(/^\s+/, '');
    const content = inline.filter(n => n.type !== 'text' || n.text);
    report.paragraphs++;
    return level
      ? { type: 'heading', attrs: { level }, ...(content.length ? { content } : {}) }
      : { type: 'paragraph', ...(content.length ? { content } : {}) };
  };
  const block = el => {
    for (const c of el.children || []) {
      if (c.name === 'w:p') out.push(paragraph(c));
      else if (c.name === 'w:tbl') {   // טבלה — כל תא הופך לפסקאות שלו, לפי הסדר
        report.tables++;
        for (const tr of kids(c, 'w:tr')) for (const tc of kids(tr, 'w:tc')) block(tc);
      } else if (c.name === 'w:sdt') block(kid(c, 'w:sdtContent') || {});
    }
  };
  block(body || {});
  // פסקאות ריקות בסוף — יורדות
  while (out.length > 1 && !out[out.length - 1].content) out.pop();
  return { doc: { type: 'doc', content: out.length ? out : [{ type: 'paragraph' }] }, report };
}

/** דוח הייבוא בעברית: מה לא עבר. מערך שורות; ריק — הכול עבר. */
export function reportLines(r) {
  const lines = [];
  if (r.tables) lines.push(`${r.tables} טבלאות — התאים הפכו לפסקאות רגילות`);
  if (r.images) lines.push(`${r.images} תמונות — לא יובאו (אפשר להוסיף כמסגרת תמונה)`);
  if (r.lists) lines.push(`${r.lists} פריטי רשימה — בלי מספור או תבליט`);
  if (r.footnotes) lines.push(`${r.footnotes} הערות שוליים — לא יובאו`);
  if (r.textboxes) lines.push(`${r.textboxes} תיבות טקסט — לא יובאו`);
  return lines;
}
