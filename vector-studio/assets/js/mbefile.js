// ‎.mbe דרך הליבה (assets/js/mbe — עותק מסונכרן; לא עורכים כאן). ‏kind: "vector".
//
// בונים: המסמך של העורך הוא document.json; הגופנים של הטקסטים נכנסים ל-media ול-`fonts` של המעטפת; ייצוגים —
// preview.png, ‏drawing.svg (הלוח הראשון) ו-document.pdf (כל הלוחות), כל אחד עם gaps. נפתח מקובץ קיים (base) —
// הכול נשמר בית-לבית (media, ‏apps/ של אחרים, חלקים לא מוכרים), ורק המסמך והייצוגים שלנו מתחלפים.

import { open, create, save, PREVIEW, PREVIEW_MAX_PX } from './mbe/mbe.js';
import { prepare, toSVG, toPDF } from './export.js';
import { fontFile } from './outline.js';
import { FONTS } from './text.js';
import { objects, leaves, ids } from './model.js';
import { KEEP } from './pages.js';

export const APP = 'vector-studio';
export const APP_VERSION = '1.0';

/** הטקסטים במסמך → הקבצים שצריך להטמיע: [{ family, file }]. */
function usedFonts(doc) {
  const out = new Map();
  for (const { path } of objects(doc)) for (const p of leaves(path)) {
    if (p.type !== 'text') continue;
    const f = p.font || {};
    const { file } = fontFile(f.family, f.weight || 400);
    out.set(file, { family: (FONTS.find(x => x.family === f.family) || FONTS[0]).family, file });
  }
  return [...out.values()];
}

/**
 * opts: { doc, base: Uint8Array | null, getFont, fontBytes(file) → Uint8Array, preview(surface, maxPx) → Uint8Array (PNG),
 *         getImage(src) — תמונות של שכבות מאפליקציות אחרות לייצוגים (export.prepare),
 *         getBackground(surfaceId) — עמוד המקור של לוח (מסמך של אפליקציה אחרת), extraPages — עמודי PDF עודפים שלו,
 *         sourcePdf — ה-PDF של המקור: נשמר ב-apps/vector-studio/, כי הייצוג document.pdf מוחלף כאן בשלנו,
 *         mediaBytes(src) — הבתים של תמונה בתוך הציור (פריט image) שעוד אין בקובץ }
 * מחזיר { bytes, gaps }.
 */
export async function buildMbe({ doc, base = null, getFont, fontBytes, preview, getImage = null, getBackground = null, extraPages = [], sourcePdf = null, mediaBytes = null }) {
  const f = base ? await open(base) : create('vector', doc);
  if (base && !f.ok) throw new Error('הקובץ המקורי פגום: ' + f.issues.filter(i => i.level === 'error').map(i => i.message).join(' · '));
  const document = JSON.parse(JSON.stringify(doc));

  // גופנים: שלנו — מחדש בכל שמירה; של אחרים — נשארים אם המשאב שלהם בקובץ
  const ours = new Set(FONTS.map(x => x.family));
  const keep = (Array.isArray(document.fonts) ? document.fonts : [])
    .filter(e => !ours.has(e?.family) && (!e?.embedded || f.files.has(e.embedded)));
  const before = new Set((Array.isArray(document.fonts) ? document.fonts : []).filter(e => ours.has(e?.family)).map(e => e.embedded));
  const add = [];
  for (const { family, file } of usedFonts(document)) {
    const embedded = await f.addMedia(await fontBytes(file), 'font/ttf');
    add.push({ family, file, embedded, license: 'OFL-1.1' });
  }
  // גופן שלנו שכבר לא בשימוש — יוצא מהקובץ (רק אם אף אחד אחר לא מפנה אליו)
  const still = new Set([...keep, ...add].map(e => e.embedded));
  for (const p of before) if (p && !still.has(p)) f.files.delete(p);
  if (keep.length || add.length) document.fonts = [...keep, ...add]; else delete document.fonts;
  f.document = document;

  const gaps = new Set();
  if (sourcePdf) f.setApp(APP, KEEP, sourcePdf);
  // תמונות בתוך הציור: הבתים נכנסים ל-media/ באותו שם (sha256) — הליבה רושמת אותם ב-manifest
  for (const { path } of objects(document)) {
    for (const p of leaves(path)) {
      if (p.type !== 'image' || !p.src || f.files.has(p.src)) continue;
      const bytes = mediaBytes ? await mediaBytes(p.src) : null;
      if (!bytes) throw new Error('תמונה בציור לא נטענה — נסו שוב בעוד רגע');
      f.files.set(p.src, bytes);
    }
  }
  const bg = async (id) => (getBackground ? getBackground(id) : null);
  const first = await prepare(document, document.surfaces[0].id, getFont, getImage, await bg(document.surfaces[0].id));
  first.gaps.forEach(g => gaps.add(g));
  f.setRendition(PREVIEW, await preview(document.surfaces[0], PREVIEW_MAX_PX), { type: 'image/png', gaps: [] });
  f.setRendition('renditions/drawing.svg', new TextEncoder().encode(toSVG(first)),
    { type: 'image/svg+xml', gaps: [...first.gaps, ...(document.surfaces.length > 1 ? ['artboards: רק הלוח הראשון'] : [])] });
  const pages = [first];
  for (const s of document.surfaces.slice(1)) pages.push(await prepare(document, s.id, getFont, getImage, await bg(s.id)));
  pages.push(...extraPages);
  const pdfGaps = [...new Set(pages.flatMap(p => p.gaps))];
  f.setRendition('renditions/document.pdf', toPDF(pages), { type: 'application/pdf', gaps: pdfGaps });
  pdfGaps.forEach(g => gaps.add(g));

  const bytes = await save(f, { app: APP, appVersion: APP_VERSION });
  return { bytes, gaps: [...gaps] };
}

/**
 * קריאת ‎.mbe לעורך: { doc, notes } — notes: מה שהעורך לא מציג (שכבות רסטר, מסגרות), ושנשמר כמו שהוא.
 * קובץ פגום — זורק עם ההודעות של הליבה.
 */
export async function readMbe(bytes) {
  const f = await open(bytes);
  if (!f.ok) throw new Error(f.issues.filter(i => i.level === 'error').map(i => i.message).join(' · '));
  const doc = f.document;
  if (!doc || !Array.isArray(doc.surfaces) || !doc.surfaces.length) throw new Error('אין בקובץ משטחים לציור');
  const notes = new Set();
  if (f.kind && f.kind !== 'vector') notes.add(`הקובץ מסוג ${f.kind} — נפתח כאן רק בשכבות הווקטוריות`);
  for (const s of doc.surfaces) {
    if (!Array.isArray(s.size) || !(s.size[0] > 0 && s.size[1] > 0)) s.size = [595.3, 841.9];
    s.layers ||= [];
    for (const l of s.layers) if (l.type !== 'vector') notes.add(`שכבות ${l.type} — מוצגות לקריאה בלבד, ונשמרות כמו שהן`);
    if (!s.layers.some(l => l.type === 'vector')) s.layers.push({ id: ids(doc, 'L')(), name: 'שכבה', type: 'vector', visible: true, locked: false, paths: [] });
  }
  return { doc, notes: [...notes], kind: f.kind, issues: f.issues };
}
