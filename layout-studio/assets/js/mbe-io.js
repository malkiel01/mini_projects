// ‎.mbe בדפדפן — דרך הליבה (assets/js/mbe — עותק מסונכרן של creative-suite/mbe-core, לא עורכים כאן).
//
//   buildMbe — הורדה: המודל הוא כבר document.json, כך שהייצוא הוא העתקה ולא תרגום. כשיש קובץ מקור (מסמך שנפתח
//              מ-.mbe, או שכבר ירד פעם) — פותחים אותו ומחליפים רק את מה שלנו: manifest.id, apps/ של אחרים,
//              history/ וחלקים לא מוכרים נשמרים בית-לבית.
//   readTextMbe — ‎.mbe של עורך התמלילים (kind: text) → ProseMirror לשטף, ודוח של מה לא עבר.
// בלי DOM — נבדק ב-tests/mbe.test.mjs.

import { open, create, save, PREVIEW } from './mbe/mbe.js';
import { sanitize } from './story.js';
import { withParaIds } from './frames.js';

export const APP = 'layout-studio';
export const APP_VERSION = '1';
const PDF = 'renditions/document.pdf';
export const KEEP_VERSIONS = 30;

/** כל ההפניות למשאבים במסמך (src / embedded, בכל עומק — גם בתוך שטף). */
export function mediaRefs(doc) {
  const out = new Set();
  const walk = v => {
    if (Array.isArray(v)) v.forEach(walk);
    else if (v && typeof v === 'object') {
      for (const [k, x] of Object.entries(v)) {
        if ((k === 'src' || k === 'embedded') && typeof x === 'string' && x.startsWith('media/')) out.add(x);
        else walk(x);
      }
    }
  };
  walk(doc);
  return out;
}

const TYPE = { png: 'image/png', jpg: 'image/jpeg', webp: 'image/webp', ttf: 'font/ttf' };

/**
 * בניית הקובץ.
 *   model — המסמך; source — bytes של קובץ המקור או null; fetchMedia(path) → bytes של משאב מהשרת;
 *   preview — PNG (חובה); pdf — PDF או null (ייצוג מומלץ; בלי — הישן נמחק, כדי שלא יישאר "מיושן").
 * מחזיר { bytes, file } — file הוא MbeFile אחרי השמירה (id, issues).
 */
export async function buildMbe({ model, source = null, fetchMedia, preview, pdf = null, stages = [], now = new Date() }) {
  let f = null;
  if (source) {
    f = await open(source);
    if (!f.ok || f.kind !== 'layout') f = null;   // מקור פגום — מתחילים מחדש (השרת בדק אותו כשנשמר; לא אמור לקרות)
  }
  f ||= create('layout');
  f.document = structuredClone(model);
  // משאבים: מה שהמסמך מפנה אליו ועוד אין בקובץ — מהשרת; מה שכבר לא בשימוש — יוצא
  const refs = mediaRefs(f.document);
  const have = new Set(f.media().map(m => m.path));
  for (const path of refs) {
    if (have.has(path)) continue;
    const ext = path.split('.').pop();
    if (!TYPE[ext]) throw new Error('media_missing:' + path);
    const got = await f.addMedia(new Uint8Array(await fetchMedia(path)), TYPE[ext]);
    if (got !== path) throw new Error('media_mismatch:' + path);
  }
  for (const path of have) if (!refs.has(path)) f.files.delete(path);
  f.setRendition(PREVIEW, preview, { type: 'image/png' });
  if (pdf) f.setRendition(PDF, pdf, { type: 'application/pdf', gaps: [] });
  else f.removeRendition(PDF);
  // יומן השלבים (history/): הליבה רושמת create / edit לבד; שלבים מפורשים (למשל שחזור גרסה) — מכאן.
  // נשמרות 30 הגרסאות האחרונות; היומן עצמו לא נגזם.
  for (const st of stages) f.stage(st);
  f.prune({ keep: KEEP_VERSIONS });
  const bytes = await save(f, { app: APP, appVersion: APP_VERSION, now });
  return { bytes, file: f };
}

/** האם הבתים הם ‎.mbe (החתימה בהיסט 30: mimetype ואחריו הסוג — ראשון ולא דחוס). */
export function isMbe(bytes) {
  const b = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  return new TextDecoder().decode(b.subarray(30, 30 + 31)) === 'mimetypeapplication/vnd.mbe+zip';
}

/**
 * הטקסט של ‎.mbe — בעיקר מסמך של עורך התמלילים (kind: text), אבל כל קובץ עם שטף ProseMirror.
 * מחזיר { doc, kind, report: { paragraphs, flows, lost: [...] } } — lost: מה הומר (רשימות, טבלאות, סימונים…).
 * @throws Error not_mbe / mbe_invalid:<קודים> / no_text
 */
export async function readTextMbe(bytes) {
  const f = await open(bytes);
  if (!f.ok) {
    const codes = [...new Set(f.issues.filter(i => i.level === 'error').map(i => i.code))];
    throw new Error(codes.includes('not-mbe') ? 'not_mbe' : 'mbe_invalid:' + codes.slice(0, 5).join(','));
  }
  const flows = Object.values(f.document?.flows || {}).filter(x => x && x.engine === 'prosemirror' && x.doc);
  if (!flows.length) throw new Error('no_text');
  // כמה שטפים — אחד אחרי השני, לפי הסדר במסמך
  const content = flows.flatMap(x => x.doc.content || []);
  const { doc, lost } = sanitize({ type: 'doc', content });
  const images = mediaRefs({ content }).size;
  return {
    doc: withParaIds(doc),
    kind: f.kind,
    report: { paragraphs: doc.content.length, flows: flows.length, lost: [...lost, ...(images ? [`image:${images}`] : [])] },
  };
}

/** הדוח בעברית: מה לא עבר. */
export function textReportLines(r) {
  const NAMES = { bulletList: 'רשימות תבליטים', orderedList: 'רשימות ממוספרות', listItem: 'פריטי רשימה', table: 'טבלאות',
    tableRow: 'שורות טבלה', tableCell: 'תאי טבלה', tableHeader: 'כותרות טבלה', blockquote: 'ציטוטים', codeBlock: 'קטעי קוד',
    image: 'תמונות', horizontalRule: 'קווים מפרידים', footnote: 'הערות שוליים' };
  const lines = [];
  if (r.flows > 1) lines.push(`${r.flows} שטפי טקסט — חוברו לשטף אחד, לפי הסדר`);
  for (const x of r.lost) {
    if (x.startsWith('image:')) { lines.push(`${x.slice(6)} תמונות בתוך הטקסט — לא יובאו (אפשר להוסיף כמסגרת תמונה)`); continue; }
    if (x.startsWith('mark:')) { lines.push(`סימון "${x.slice(5)}" — ירד (הטקסט נשאר)`); continue; }
    lines.push(`${NAMES[x] || x} — הטקסט שבהם הפך לפסקאות רגילות`);
  }
  return lines;
}

// ---- יומן השלבים: מה קרה למסמך, ושחזור גרסה ----
const KINDS = { create: 'נוצר', edit: 'עריכה', export: 'יצא', return: 'חזר', merge: 'מוזג', restore: 'שוחזר' };
const APPS = { 'layout-studio': 'עימוד', 'word-processor': 'עורך התמלילים', 'raster-studio': 'רסטר', 'vector-studio': 'וקטורי',
  'greeting-cards': 'סטודיו העיצוב' };

/**
 * היומן של קובץ ‎.mbe: [{ id, at, kind, kindLabel, app, appLabel, who, note, sha, available }] מהחדש לישן.
 * available — הגרסה עצמה עדיין בקובץ (לא נגזמה), ואפשר לשחזר אותה.
 */
export async function readHistory(bytes) {
  const f = await open(bytes);
  return f.journal().reverse().map(s => {
    const app = String(s.app || '');
    return { id: s.id, at: s.at, kind: s.kind, kindLabel: KINDS[s.kind] || s.kind, app,
      appLabel: APPS[app] || (app.startsWith('external:') ? app.slice(9) : app), who: s.who || '', note: s.note || '',
      sha: s.result, available: !s.pruned && !!f.version(s.result) };
  });
}

/** המסמך של גרסה מהיומן (לשחזור), או null. */
export async function historyVersion(bytes, sha) {
  return (await open(bytes)).version(sha);
}
