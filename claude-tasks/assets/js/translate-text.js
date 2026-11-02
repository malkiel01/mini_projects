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
  'https?:\\/\\/[^\\s<>()]*[^\\s<>().,;:!?]',   // כתובת — בלי סימן הפיסוק שאחריה
  '(?:[\\w.-]+\\/)+[\\w.-]*[\\w-]',               // נתיב a/b/c.js — בלי נקודה בסוף משפט
  '\\b[\\w-]+\\.(?:php|js|mjs|json|md|sh|css|html|yml|yaml|py|txt|sql|sqlite)\\b', // שם קובץ
  '#\\d+',                                        // ‎#1234‎
  '\\b(?:session|cse|trig|env)_[A-Za-z0-9]+',     // מזהי סשן / תזמון / סביבה
  '§\\s?\\d+(?:\\.\\d+)*',                         // ‎§5.9.1‎ — סעיף באפיון
  // טקסט בעברית בתוך משפט באנגלית — כבר בשפת היעד, והמודל מתבלבל ממנו (12e.6: יצא "□ □ Ch'Aoo")
  '["“„]?[\\u0590-\\u05FF][\\u0590-\\u05FF\\s\'"׳״־-]*[\\u0590-\\u05FF]["”]?',
  '[\\p{Extended_Pictographic}\\u2190-\\u21FF\\u2600-\\u27BF][\\uFE0F]?',  // אימוג'י וחצים
  '[—–]',                                         // מקף ארוך — המודל לא יודע לכתוב אותו (יצא "□")
].join('|'), 'gu');

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

/*
 * איכות (12e.6, האפיון): מחלקים לפי **משפטים**, לא לפי מזהים. מזהה בתוך משפט (קוד, נתיב, #n…) מוחלף
 * במסמן ‎XX0‎ — המודל משאיר אותו כמו שהוא (נמדד: 5/5, מול ‎{0}‎ ‎<0>‎ ‎⟦0⟧‎ שנהרסו) — והמשפט כולו
 * נשלח למודל עם ההקשר שלו; אחרי התרגום המזהה חוזר למקומו, ואם המודל השמיט את המסמן — המזהה
 * נוסף בסוף המשפט, כך ששום קוד לא הולך לאיבוד. משפט עם פחות מ-3 מילים מצטרף לשכן שלו; קטע שנשאר
 * קצר, או שכולו מזהה / מספר — נשאר במקור. כותרת מתורגמת כמשפט (עם נקודה זמנית שמוסרת אחר כך).
 */
const MARK = (i) => `XX${i}`;
const words = (t) => (t.replace(/XX\d+/g, ' ').match(/[A-Za-z][A-Za-z'’-]*/g) || []).length;

/** רצף טקסט (שורה או תא): מסמנים, משפטים, חיבור קצרים — ואז יחידות תרגום. */
function runs(text, parts, heading = false) {
  const lead = text.match(/^\s*/)[0];
  const trail = text.slice(lead.length).match(/\s*$/)[0];
  const core = text.slice(lead.length, text.length - trail.length);
  if (lead) parts.push({ lit: lead });
  if (core) {
    // מסמנים לכל מוגן; מוגנים צמודים (רק רווח / גרש / סוגר / חץ ביניהם) — מסמן אחד, כדי שהמודל לא
    // יקבל שורה של מסמנים. המיפוי לפי סדר ההופעה
    const spans = [];
    for (const m of core.matchAll(PROTECT)) {
      const prev = spans[spans.length - 1];
      if (prev && /^[\s"'“”„()[\],]*$/.test(core.slice(prev.end, m.index))) prev.end = m.index + m[0].length;
      else spans.push({ start: m.index, end: m.index + m[0].length });
    }
    const map = [];
    let masked = '', at = 0;
    for (const sp of spans) {
      masked += core.slice(at, sp.start) + MARK(map.length);
      map.push(core.slice(sp.start, sp.end));
      at = sp.end;
    }
    masked += core.slice(at);
    // משפטים; משפט קצר (פחות מ-3 מילים) מצטרף לקודם — או לבא, כשהוא הראשון
    const sents = masked.split(/(?<=[.!?;:])\s+(?=\S)/);
    const units = [];
    for (const t of sents) {
      if (units.length && words(t) < 3) units[units.length - 1] += ` ${t}`;
      else if (units.length && words(units[units.length - 1]) < 3) units[units.length - 1] += ` ${t}`;
      else units.push(t);
    }
    // הרווח המקורי בין המשפטים — רווח אחד (כך נכתבים משפטים בפסקה)
    units.forEach((u, k) => {
      if (k) parts.push({ lit: ' ' });
      const used = [...u.matchAll(/XX(\d+)/g)].map((m) => +m[1]);
      const orig = u.replace(/XX(\d+)/g, (_, n) => map[+n]);
      // קצר מ-3 מילים — נשאר במקור; חוץ מכותרת, שמתורגמת כמשפט שלם (האפיון, 12e.6)
      if (words(u) < (heading ? 1 : 3)) { parts.push({ lit: orig }); return; }
      // מספור מחדש מ-0 בתוך היחידה — המודל רואה ‎XX0, XX1…‎
      const local = used.map((n) => map[n]);
      let j = 0;
      const send = u.replace(/XX\d+/g, () => MARK(j++));
      const head = heading && !/[.!?:]$/.test(send);
      // הדגשה בתוך משפט (‎**once**‎) — הכוכביות לא נשלחות למודל (יצא ‎XX0onceXX1‎ שלא תורגם); בתרגום
      // ההדגשה יורדת, המילים מתורגמות
      const plain = send.replace(/\*\*|__/g, '');
      parts.push({ tr: head ? `${plain}.` : plain, map: local, orig, heading: head });
    });
  }
  if (trail) parts.push({ lit: trail });
}

/** שורה אחת (לא בתוך בלוק קוד): קידומת Markdown, ואז הטקסט כמשפטים. */
function line(text, parts) {
  // קידומת: כותרת, רשימה, רשימה ממוספרת, ציטוט, תיבת סימון
  const pre = text.match(/^(\s*(?:#{1,6}\s+|[-*+]\s+(?:\[[ xX]\]\s+)?|\d+[.)]\s+|>\s*)*)/)[0];
  if (pre) parts.push({ lit: pre });
  const rest = text.slice(pre.length);
  // טבלה: הקווים נשארים, תאים מתורגמים; שורת המפריד (---) נשארת כולה
  if (/^\s*\|/.test(text)) {
    if (/^[\s|:-]+$/.test(rest)) { parts.push({ lit: rest }); return; }
    rest.split(/(\|)/).forEach((cell) => (cell === '|' ? parts.push({ lit: cell }) : runs(cell, parts)));
    return;
  }
  runs(rest, parts, /^\s*#{1,6}\s/.test(pre));
}

/** תרגום של יחידה → טקסט סופי: המזהים חוזרים; מסמן שהושמט — המזהה בסוף; נקודת הכותרת מוסרת. */
export function restore(p, out) {
  let t = String(out);
  const missing = [];
  p.map.forEach((orig, i) => {
    const re = new RegExp(`XX\\s?${i}(?!\\d)`);
    if (re.test(t)) t = t.replace(re, () => orig); else missing.push(orig);
  });
  if (missing.length) t = `${t.replace(/\s+$/, '')} ${missing.join(' ')}`;
  t = t.replace(/\s?XX\s?\d+/g, '');   // מסמן שהמודל שכפל — לא נשאר על המסך
  if (p.heading) t = t.replace(/\s*[.։]\s*$/, '');
  // המודל מוסיף רווחים בתוך ‎**…**‎ — ואז זה לא מודגש
  t = t.replace(/\*\*\s*([^*]+?)\s*\*\*/g, '**$1**');
  return t;
}

/** שורות שנשברו באמצע משפט (פסקה רגילה, או המשך של פריט ברשימה) — מחוברות לשורה אחת לפני הפירוק.
 * ב-Markdown שבירת שורה כזו מוצגת כרווח, כך שהתצוגה לא משתנה; והמודל מקבל משפט שלם (12e.6). */
const BLOCK = /^\s*(?:#{1,6}\s|[-*+]\s|\d+[.)]\s|>|\||```|~~~|$)/;
function joinSoftWraps(lines) {
  const out = [];
  let fence = false;
  for (const l of lines) {
    if (/^\s*(```|~~~)/.test(l)) { fence = !fence; out.push(l); continue; }
    const prev = out[out.length - 1];
    if (!fence && prev !== undefined && prev.trim() !== '' && !/^\s*(```|~~~|\|)/.test(prev) && !BLOCK.test(l)) {
      out[out.length - 1] = `${prev.replace(/\s+$/, '')} ${l.trim()}`;
    } else out.push(l);
  }
  return out;
}

export function segment(md) {
  const parts = [];
  const lines = joinSoftWraps(String(md || '').split('\n'));
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

/** חיבור חזרה: ‏done[i] — התרגום של היחידה ה-i (או undefined — עוד לא; נשאר המקור). */
export function assemble(parts, done) {
  let i = 0;
  return parts.map((p) => {
    if ('lit' in p) return p.lit;
    const d = done[i++];
    return d === undefined ? p.orig : restore(p, d);
  }).join('');
}
