// טקסט: פריט ב-`paths` — { id, type: "text", text, box: [x y w h], font: { family, size, weight }, align, lineHeight,
// auto, fill, stroke }. auto=true — "טקסט נקודה": שורות רק לפי ‏\n, והרוחב לפי התוכן; auto=false — "טקסט בתיבה":
// גלישה לפי הרוחב. הגובה תמיד מחושב. בלי DOM — המדידה מוזרקת מהעורך (setMeasure), ובבדיקות יש קירוב.

// גופנים חופשיים (OFL) שבתיקייה assets/fonts — הרישיונות לידם.
export const FONTS = [
  { family: 'Heebo', label: 'Heebo', files: [{ file: 'Heebo.ttf', weight: '100 900' }] },
  { family: 'Rubik', label: 'Rubik', files: [{ file: 'Rubik.ttf', weight: '300 900' }] },
  { family: 'Assistant', label: 'Assistant', files: [{ file: 'Assistant.ttf', weight: '200 800' }] },
  { family: 'Frank Ruhl Libre', label: 'פרנק רוהל', files: [{ file: 'FrankRuhlLibre.ttf', weight: '300 900' }] },
  { family: 'David Libre', label: 'דוד', files: [{ file: 'DavidLibre-Regular.ttf', weight: '400' }, { file: 'DavidLibre-Bold.ttf', weight: '700' }] },
  { family: 'Secular One', label: 'Secular One', files: [{ file: 'SecularOne-Regular.ttf', weight: '400' }] },
  { family: 'Amatic SC', label: 'Amatic', files: [{ file: 'AmaticSC-Regular.ttf', weight: '400' }, { file: 'AmaticSC-Bold.ttf', weight: '700' }] },
];

export const TEXT_DEFAULT = { family: 'Heebo', size: 36, weight: 400 };

export const fontCss = (f) => `${f.weight || 400} ${f.size}px "${f.family}", sans-serif`;

// קירוב לבדיקות: חצי מגודל הגופן לתו
let measure = (str, font) => str.length * font.size * 0.5;
export function setMeasure(fn) { measure = fn; }

/** גלישת שורה לפי רוחב: מילים שלמות; מילה ארוכה מהרוחב — נשברת לפי תווים. */
function wrapLine(line, font, width) {
  if (!line) return [''];
  const words = line.split(/(\s+)/);
  const out = [];
  let cur = '';
  for (const w of words) {
    const next = cur + w;
    if (!cur || measure(next.trimEnd(), font) <= width) { cur = next; continue; }
    out.push(cur.trimEnd());
    cur = w.trimStart();
    while (cur && measure(cur, font) > width) {
      let k = cur.length - 1;
      while (k > 1 && measure(cur.slice(0, k), font) > width) k--;
      out.push(cur.slice(0, k));
      cur = cur.slice(k);
    }
  }
  out.push(cur.trimEnd());
  return out;
}

/** השורות של הפריט: [{ text, w }], לפי auto / רוחב התיבה. */
export function lines(item) {
  const font = item.font || TEXT_DEFAULT;
  const raw = String(item.text ?? '').split('\n');
  const ls = item.auto === false ? raw.flatMap(l => wrapLine(l, font, Math.max(1, item.box[2]))) : raw;
  return ls.map(text => ({ text, w: measure(text, font) }));
}

export const lineHeight = (item) => (item.font?.size || TEXT_DEFAULT.size) * (item.lineHeight || 1.25);

/**
 * מחשב את התיבה מחדש: בטקסט נקודה — רוחב לפי השורה הארוכה, והקצה של היישור נשאר במקום (ימין לטקסט מיושר
 * לימין); תמיד — גובה לפי מספר השורות.
 */
export function relayout(item) {
  const ls = lines(item);
  const [x, y, w] = item.box;
  const h = Math.max(1, ls.length) * lineHeight(item);
  if (item.auto === false) { item.box = [x, y, w, r3(h)]; return; }
  const nw = Math.max(1, ...ls.map(l => l.w));
  const nx = item.align === 'left' ? x : item.align === 'center' ? x + w / 2 - nw / 2 : x + w - nw;
  item.box = [r3(nx), r3(y), r3(nw), r3(h)];
}
const r3 = (v) => Math.round(v * 1000) / 1000 || 0;

/** הזזה / שינוי גודל במטריצה בלי סיבוב: טקסט נקודה — הגופן גדל עם הגובה; טקסט בתיבה — התיבה גדלה. */
export function transformText(item, m) {
  const [x, y, w, h] = item.box;
  const ax = m[0] * x + m[4], ay = m[3] * y + m[5];
  const bx = m[0] * (x + w) + m[4], by = m[3] * (y + h) + m[5];
  const box = [Math.min(ax, bx), Math.min(ay, by), Math.abs(bx - ax), Math.abs(by - ay)];
  if (item.auto !== false && Math.abs(m[3] - 1) > 1e-9) {
    item.font = { ...item.font, size: r3(Math.max(1, (item.font?.size || TEXT_DEFAULT.size) * Math.abs(m[3]))) };
  }
  item.box = box.map(r3);
  relayout(item);
}
