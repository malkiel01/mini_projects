/*
 * תרגום במכשיר — החלק הטהור (בלי דפדפן): פירוק Markdown לקטעים, וזיהוי "כנראה לא בעברית".
 * docs/spec.md §5.11.2: מתרגמים רק קטעי טקסט. בלוקי קוד, קוד בשורה, כתובות, נתיבים, שמות קבצים
 * ומזהים (‎#1234‎, ‎session_…‎) לא עוברים במודל ויוצאים כפי שנכנסו. הסימונים של Markdown (כותרת,
 * רשימה, ציטוט, טבלה) נשארים במקומם. כל משפט הוא יחידת תרגום — כך אפשר להציג משפט-משפט (§5.11.1).
 *
 * ‏segment(md) → { parts }: מערך של { lit } (נשאר) או { tr } (משפט לתרגום), שמחוברים חזרה ב-assemble.
 */

// מה שלא מתרגמים בתוך שורה — לפי הסדר: קוד בשורה, קישור Markdown (רק הכתובת), כתובת, נתיב/קובץ, מזהה
const PROTECT = new RegExp([
  '`[^`\\n]+`',                                   // קוד בשורה
  '\\]\\([^)\\s]+\\)',                            // ](url) של קישור
  'https?:\\/\\/[^\\s<>()]+',                     // כתובת
  '(?:[\\w.-]+\\/)+[\\w.-]+',                     // נתיב a/b/c.js
  '\\b[\\w-]+\\.(?:php|js|mjs|json|md|sh|css|html|yml|yaml|py|txt|sql|sqlite)\\b', // שם קובץ
  '#\\d+',                                        // ‎#1234‎
  '\\b(?:session|cse|trig|env)_[A-Za-z0-9]+',     // מזהי סשן / תזמון / סביבה
  '\\*\\*|__',                                    // סימוני הדגשה — נשארים, לא נשלחים למודל
].join('|'), 'g');

const LATIN = /[A-Za-z]/g;
const HEBREW = /[֐-׿]/g;

/** טקסט "לקריאה" — בלי קוד, כתובות ומזהים. לזיהוי השפה. */
function proseOf(md) {
  return String(md || '').replace(/```[\s\S]*?(```|$)/g, ' ').replace(PROTECT, ' ');
}

// מילים נפוצות — להבדיל אנגלית מספרדית (אותו אלפבית, כך שיחס הכתבים לא מספיק)
const EN_WORDS = new Set(['the', 'and', 'is', 'to', 'of', 'you', 'that', 'it', 'in', 'for', 'with', 'this', 'are', 'was', 'be', 'on', 'not', 'have', 'i', 'we', 'so', 'now', 'will', 'can']);
const ES_WORDS = new Set(['el', 'la', 'de', 'que', 'y', 'en', 'los', 'las', 'es', 'por', 'una', 'un', 'con', 'para', 'del', 'se', 'lo', 'su', 'al', 'como', 'pero', 'está', 'muy', 'ya']);

/**
 * כנראה צריך תרגום לשפת היעד (§5.11.2: "בדיקה זולה"). המקור תמיד אנגלית (שלב 1):
 * לפחות 15 אותיות לטיניות, עברית פחות מ-30% מהאותיות, ו — ליעד ספרדית — יותר מילים נפוצות באנגלית
 * מאשר בספרדית. קוד, כתובות ומזהים לא נספרים — תשובה בעברית עם הרבה קוד לא תסומן.
 */
export function needsTranslation(md, lang = 'he') {
  const p = proseOf(md);
  const lat = (p.match(LATIN) || []).length;
  const heb = (p.match(HEBREW) || []).length;
  if (lat < 15 || heb / (heb + lat) >= 0.3) return false;
  if (lang === 'he') return true;
  const words = p.toLowerCase().match(/[a-záéíóúñü]+/g) || [];
  const en = words.filter((w) => EN_WORDS.has(w)).length;
  const es = words.filter((w) => ES_WORDS.has(w)).length;
  return en > es;
}

/** משפטים בתוך רצף טקסט; הרווחים ביניהם נשארים כ-lit. */
function sentences(run, parts) {
  const lead = run.match(/^\s*/)[0];
  const trail = run.slice(lead.length).match(/\s*$/)[0];
  const core = run.slice(lead.length, run.length - trail.length);
  if (lead) parts.push({ lit: lead });
  if (core) {
    // בלי אותיות לטיניות (מספרים, סימנים, עברית) — אין מה לתרגם
    const bits = core.split(/(?<=[.!?;:])(\s+)(?=\S)/);
    for (const b of bits) {
      if (!b) continue;
      if (/^\s+$/.test(b)) parts.push({ lit: b });
      else if (/[A-Za-z]{2,}/.test(b)) parts.push({ tr: b });
      else parts.push({ lit: b });
    }
  }
  if (trail) parts.push({ lit: trail });
}

/** שורה אחת (לא בתוך בלוק קוד): קידומת Markdown, ואז רצפים שבין המוגנים. */
function line(text, parts) {
  // קידומת: כותרת, רשימה, רשימה ממוספרת, ציטוט, תיבת סימון
  const pre = text.match(/^(\s*(?:#{1,6}\s+|[-*+]\s+(?:\[[ xX]\]\s+)?|\d+[.)]\s+|>\s*)*)/)[0];
  if (pre) parts.push({ lit: pre });
  let rest = text.slice(pre.length);
  // טבלה: הקווים נשארים, תאים מתורגמים; שורת המפריד (---) נשארת כולה
  if (/^\s*\|/.test(text)) {
    if (/^[\s|:-]+$/.test(rest)) { parts.push({ lit: rest }); return; }
    rest.split(/(\|)/).forEach((cell) => (cell === '|' ? parts.push({ lit: cell }) : runs(cell, parts)));
    return;
  }
  runs(rest, parts);
}

function runs(text, parts) {
  let last = 0;
  for (const m of text.matchAll(PROTECT)) {
    if (m.index > last) sentences(text.slice(last, m.index), parts);
    parts.push({ lit: m[0] });
    last = m.index + m[0].length;
  }
  if (last < text.length) sentences(text.slice(last), parts);
}

export function segment(md) {
  const parts = [];
  const lines = String(md || '').split('\n');
  let fence = null;
  lines.forEach((l, i) => {
    if (i) parts.push({ lit: '\n' });
    const f = l.match(/^\s*(```+|~~~+)/);
    if (fence) { parts.push({ lit: l }); if (f && f[1][0] === fence[0] && f[1].length >= fence.length) fence = null; return; }
    if (f) { fence = f[1]; parts.push({ lit: l }); return; }
    line(l, parts);
  });
  return { parts, total: parts.filter((p) => 'tr' in p).length };
}

/** חיבור חזרה: ‏done[i] — התרגום של המשפט ה-i (או undefined — עוד לא; נשאר המקור). */
export function assemble(parts, done) {
  let i = 0;
  return parts.map((p) => ('lit' in p ? p.lit : (done[i++] ?? p.tr))).join('');
}
