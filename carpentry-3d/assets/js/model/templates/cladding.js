// תבנית: חיפוי קיר מעץ.
//
// קיר אחד או כמה קירות ברצף (עד שלושה), כל פנייה ב-90° שמאלה או ימינה —
// כך החיפוי "מסתובב" סביב פינה. על כל קיר: לטות רוחב (מסד), ועליהן
// החיפוי — או הדבקה/הברגה ישירה לקיר, בלי לטות. בפינה: חיבור ישר (קיר אחד
// מכסה את קצה השני) או גרונג 45° — שני הלוחות נחתכים באלכסון ונפגשים בקו
// הפינה. כל קיר מחולק ל"שדות" לאורכו (ברירת מחדל שדה אחד), ולכל שדה
// רוחב ודוגמה משלו — בעורך החלוקה: רוחב נעוץ/אוטומטי, ודוגמה מתוך
// PATTERNS (סטריפים אנכיים/אופקיים, לוחות, ריבועים, לבנים, שחמט, תבליט,
// מסגרות). לפי בחירה פנל תחתון וקרניז עליון, וגובה חלקי.
//
// הצירים: הקיר הראשון לאורך X והחזית ל-+z (אל החדר). כל פינה מוגדרת
// בזווית שבין שני הקירות, נמדדת בצד החיפוי: 90° פינת חדר (פנימית), 270°
// עמוד או בליטה (חיצונית), 180° המשך ישר — וכל זווית אחרת. כל קיר נבנה
// במערכת מקומית (לאורך x, החדר ב-+z) ומוצב בעולם בסיבוב — ברבעים התיבות
// נשארות מקבילות לצירים, ובשאר הזוויות החלקים נושאים xf (xform.js).
// בפינה שאינה 90°/270° החיבור תמיד בגרונג, בחצי הזווית. קירות מעוגלים
// ודוגמאות אלכסוניות (אדרה, שברון) אינם כאן.

import { part } from '../blocks.js';
import { xfPart, partsAabb, shiftPart, normYaw } from '../xform.js';
import { boardT, applyFinish, FINISHES_FLUSH } from './common.js';
import { resolveShares, sectionLayout } from '../layout.js';

export const PATTERNS = [
  { id: 'slats', name: 'סטריפים אנכיים' },
  { id: 'hslats', name: 'סטריפים אופקיים' },
  { id: 'panels', name: 'לוחות עם חריץ (V)' },
  { id: 'flat', name: 'לוחות רצופים' },
  { id: 'squares', name: 'ריבועים (אריחי עץ)' },
  { id: 'bricks', name: 'לבנים בשיכול' },
  { id: 'checker', name: 'שחמט — ריבועי סטריפים לסירוגין' },
  { id: 'relief', name: 'תבליט — ריבועים בעומקים שונים' },
  { id: 'frames', name: 'מסגרות על לוח (פנלים קלאסיים)' },
];
const PATTERN_NAME = Object.fromEntries(PATTERNS.map((p) => [p.id, p.name]));

/** עובי שכבת הלטות: 0 כשהחיפוי מודבק/מוברג ישר לקיר. */
const battenDepth = (v) => (v.mount === 'direct' ? 0 : (v.battenT ?? 18));

/** זווית הפינה (בצד החיפוי) — מוגבלת, כדי שהחיתוך לא יתפוצץ. */
const cornerAngle = (a) => Math.min(340, Math.max(20, Number.isFinite(Number(a)) ? Number(a) : 90));
const isRight = (a) => a === 90 || a === 270;
const RAD = Math.PI / 180;

/**
 * מסלול הקירות — משותף לבנייה ולעורך. כל קיר = { i, len, yaw, ox, oz, alpha, k, mit, a, b, free }:
 * yaw — כיוון הקיר (0 = +x), ox/oz — תחילתו; alpha — זווית הפינה בתחילתו, k = cot(alpha/2):
 * בעומק d מהקיר, קו הגרונג נמצא d·k לאורך הקיר מהפינה (חיובי בפינה פנימית, שלילי בחיצונית).
 * mit — הפינה בתחילת הקיר בגרונג. [a, b] — הקטע לאורך הקיר שהחיפוי עצמו תופס.
 */
function wallPath(v) {
  const bt = battenDepth(v), st = v.slatT ?? 20, totalT = bt + st;
  const lens = [v.len1 ?? 3000, v.len2 ?? 2000, v.len3 ?? 2000], angles = [null, cornerAngle(v.angle2 ?? 90), cornerAngle(v.angle3 ?? 90)];
  const walls = [];
  let x = 0, z = 0, h = 0;
  for (let i = 0; i < (v.walls ?? 1); i++) {
    const alpha = i > 0 ? angles[i] : null;
    if (i > 0) h += alpha - 180;   // 90 = פנייה אל החדר (ימינה), 270 = הרחק ממנו (שמאלה)
    const k = i > 0 ? clean(1 / Math.tan(alpha / 2 * RAD)) : 0;
    const mit = i > 0 && alpha !== 180 && (v.corner === 'miter' || !isRight(alpha));
    walls.push({ i, len: lens[i], yaw: normYaw(h), ox: x, oz: z, alpha, k, mit, inside: i > 0 ? alpha < 180 : null });
    x += lens[i] * Math.cos(h * RAD); z -= lens[i] * Math.sin(h * RAD);
    x = clean(x); z = clean(z);
  }
  for (const w of walls) {
    const next = walls[w.i + 1];
    // התחלה: בגרונג — קו הגרונג (הנקודה הקרובה ביותר בשכבת החיפוי); בחיבור ישר — אחרי/לפני עובי הקודם
    w.a = w.i === 0 ? 0 : w.mit ? Math.min(bt * w.k, totalT * w.k) : totalT * w.k;
    w.inset = w.i === 0 ? 0 : totalT * w.k;
    w.b = next && next.mit ? w.len - Math.min(bt * next.k, totalT * next.k) : w.len;
    w.free = w.b - w.a;
  }
  return { walls, totalT, bt, st };
}
const clean = (n) => Math.round(n * 1e9) / 1e9;

export default {
  key: 'cladding',
  name: 'חיפוי קיר',
  description: 'סטריפים, לוחות, ריבועים ועוד — על לטות או ישר על הקיר; קיר אחד או כמה, עם פינה ישרה או בגרונג; שדות עם דוגמאות שונות.',
  laborHours: 10,

  params: [
    { key: 'walls', label: 'מספר קירות', type: 'int', min: 1, max: 3, default: 1, group: 'מידות' },
    { key: 'len1', label: 'אורך קיר 1', type: 'mm', min: 300, max: 12000, default: 3000, group: 'מידות' },
    { key: 'angle2', label: 'זווית הפינה בין קיר 1 לקיר 2', type: 'deg', min: 20, max: 340, default: 90, presets: [90, 180, 270], group: 'מידות', showIf: { walls: [2, 3] }, hint: 'נמדדת בצד החיפוי: 90° פינת חדר, 270° עמוד/בליטה, 180° ישר — או כל זווית' },
    { key: 'len2', label: 'אורך קיר 2', type: 'mm', min: 300, max: 12000, default: 2000, group: 'מידות', showIf: { walls: [2, 3] } },
    { key: 'angle3', label: 'זווית הפינה בין קיר 2 לקיר 3', type: 'deg', min: 20, max: 340, default: 90, presets: [90, 180, 270], group: 'מידות', showIf: { walls: [3] } },
    { key: 'len3', label: 'אורך קיר 3', type: 'mm', min: 300, max: 12000, default: 2000, group: 'מידות', showIf: { walls: [3] } },
    { key: 'corner', label: 'חיבור בפינה', type: 'enum', default: 'butt', group: 'מידות', showIf: { walls: [2, 3] }, options: [{ id: 'butt', name: 'ישר — קיר אחד מכסה את קצה השני' }, { id: 'miter', name: 'גרונג — חיתוך אלכסוני בחצי הזווית' }], hint: 'בזווית שאינה 90° או 270° — תמיד גרונג' },
    { key: 'height', label: 'גובה החיפוי', type: 'mm', min: 300, max: 3500, default: 2500, group: 'מידות', hint: 'עד התקרה, או חלקי (למשל 1000)' },
    { key: 'fromFloor', label: 'התחלה מהרצפה', type: 'mm', min: 0, max: 1500, default: 0, group: 'מידות', hint: '0 = מהרצפה' },

    { key: 'style', label: 'דוגמה (ברירת מחדל לכל השדות)', type: 'enum', default: 'slats', group: 'עיצוב', options: PATTERNS },
    { key: 'panelFinish', label: 'גימור הלוחות', type: 'enum', default: 'flat', group: 'עיצוב', options: FINISHES_FLUSH, showIf: { style: ['flat'] }, hint: 'בדוגמת "לוחות" — חירוץ CNC על כל לוח (גם בשדה שבחרת לו לוחות)' },
    { key: 'fields', label: 'שדות בכל קיר', type: 'int', min: 1, max: 6, default: 1, group: 'עיצוב', hint: 'חלוקה לאורך הקיר; לכל שדה רוחב ודוגמה משלו — למטה' },
    { key: 'columnsLayout', label: 'עריכת השדות', type: 'json', default: null, group: 'עיצוב', editor: 'columns', hint: 'רוחב ודוגמה לכל שדה בכל קיר' },
    { key: 'slatW', label: 'רוחב סטריפ / לוח', type: 'mm', min: 20, max: 1200, default: 40, group: 'עיצוב' },
    { key: 'slatGap', label: 'מרווח / חריץ', type: 'mm', min: 0, max: 200, default: 20, group: 'עיצוב' },
    { key: 'slatT', label: 'עובי', type: 'mm', min: 6, max: 60, default: 20, group: 'עיצוב' },
    { key: 'tileS', label: 'מידת ריבוע / לבנה', type: 'mm', min: 60, max: 1200, default: 200, group: 'עיצוב', hint: 'לריבועים, לבנים, שחמט, תבליט ומסגרות' },
    { key: 'frameW', label: 'רוחב פס מסגרת', type: 'mm', min: 15, max: 120, default: 40, group: 'עיצוב', hint: 'לדוגמת המסגרות' },
    { key: 'mount', label: 'התקנה', type: 'enum', default: 'battens', group: 'עיצוב', options: [{ id: 'battens', name: 'על לטות' }, { id: 'direct', name: 'ישר על הקיר (הדבקה / הברגה)' }] },
    { key: 'battenRows', label: 'שורות לטות רוחב', type: 'int', min: 2, max: 8, default: 3, group: 'עיצוב', showIf: { mount: ['battens'] } },
    { key: 'battenT', label: 'עובי הלטות', type: 'mm', min: 12, max: 40, default: 18, group: 'עיצוב', showIf: { mount: ['battens'] } },
    { key: 'baseH', label: 'פנל תחתון — גובה', type: 'mm', min: 0, max: 300, default: 0, group: 'סיומות', hint: '0 = ללא' },
    { key: 'crownH', label: 'קרניז עליון — גובה', type: 'mm', min: 0, max: 200, default: 0, group: 'סיומות', hint: '0 = ללא' },

    { key: 'slatMaterial', label: 'הסטריפים / הלוחות', type: 'material', kind: 'board', back: false, top: false, default: 'board:solid-oak', group: 'חומרים' },
    { key: 'battenMaterial', label: 'הלטות', type: 'material', kind: 'board', back: false, top: false, default: 'board:melamine-white-18', group: 'חומרים', showIf: { mount: ['battens'] } },
    { key: 'trimMaterial', label: 'פנל וקרניז', type: 'material', kind: 'board', back: false, top: false, default: 'board:solid-oak', group: 'חומרים' },
  ],
  joinery: [
    { key: 'edgeMaterial', label: 'קנט ללוחות', type: 'material', kind: 'edge', default: 'edge:veneer-0.5', group: 'חיבורים' },
    { key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים',
      options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] },
  ],

  /** פרויקטים ישנים: "פנייה שמאלה/ימינה" → זווית הפינה (שמאלה = חיצונית 270°, ימינה = פנימית 90°). */
  migrate(v) {
    for (const [t, a] of [['turn2', 'angle2'], ['turn3', 'angle3']]) {
      if (v[t] !== undefined) { if (v[a] === undefined || v[a] === null) v[a] = v[t] === 'right' ? 90 : 270; delete v[t]; }
    }
    return v;
  },

  /**
   * ההגדרות שנוגעות לרכיב שהוקש בתלת מימד: לוח בשדה — סוג החיפוי וגובה השדה
   * (בעורך החלוקה), החומר והמידות של הדוגמה, אורך הקיר וזוויות הפינות שלו.
   */
  partParams(p, v) {
    const m = /^w(\d+)-(?:f(\d+)-)?/.exec(p.id);
    if (!m) return null;
    const k = Number(m[1]), f = m[2] ? Number(m[2]) : null;
    const corners = [k > 1 ? `angle${k}` : null, k < (v.walls ?? 1) ? `angle${k + 1}` : null].filter(Boolean);
    if (/batten/.test(p.id)) return ['mount', 'battenT', 'battenRows', 'battenMaterial', `len${k}`, ...corners, 'height'];
    if (/-base$/.test(p.id)) return ['baseH', 'trimMaterial', `len${k}`, ...corners];
    if (/crown/.test(p.id)) return ['crownH', 'trimMaterial', `len${k}`, ...corners];
    const out = [];
    if (f) {
      out.push({ layout: `wall${k}`, col: f - 1, prop: 'kind', label: `סוג החיפוי (שדה ${f})`, type: 'enum', options: PATTERNS, fallback: v.style });
      out.push({ layout: `wall${k}`, col: f - 1, prop: 'height', label: `גובה השדה ${f}`, type: 'mm', fallback: v.height, min: minFieldH(v), max: v.height });
    }
    out.push('slatMaterial', 'panelFinish', 'slatW', 'slatGap', 'slatT', 'tileS', 'frameW', `len${k}`, ...corners, 'height', 'fromFloor', 'mount', 'corner');
    return out;
  },

  /** לעורך החלוקה: קבוצה לכל קיר, פריט לכל שדה — רוחב ודוגמה (אין תאים). */
  columnSpace(v) {
    const { walls } = wallPath(v);
    const n = v.fields ?? 1;
    return { sections: walls.map((w) => ({
      key: `wall${w.i + 1}`, title: walls.length > 1 ? `קיר ${w.i + 1}` : 'שדות החיפוי', total: w.free, sizeLabel: 'רוחב', allLabel: 'השדות',
      modes: { next: 'מהשדה הבא', prev: 'מהשדה הקודם' },
      items: Array.from({ length: n }, (_, f) => ({ label: `שדה ${f + 1}`, kinds: PATTERNS, kind: v.style ?? 'slats', editable: false, note: '',
        height: { full: v.height ?? 2400, min: minFieldH(v) } })),
    })) };
  },

  build(v) {
    const all = [], hardware = [], warnings = [];
    let parts = [];   // החלקים של הקיר הנבנה — במערכת המקומית שלו
    const H = v.height, y0 = v.fromFloor, y1 = y0 + H;
    const { walls, totalT, bt, st } = wallPath(v);
    const direct = bt === 0;
    /**
     * מציב תיבה על קיר, במערכת המקומית של הקיר: `u` לאורך הקיר (מתחילתו, x), `depth` מרחק
     * מהקיר (z, 0 = צמוד, החדר ב-+z), `len` אורך לאורך הקיר, `t` עובי. ההצבה בעולם — בסוף הקיר.
     */
    const place = (w, id, name, u, yA, yB, len, depth, t, opts) => part(id, name, { x: u, y: yA, z: depth, w: len, h: yB - yA, d: t }, { axis: 'z', grain: opts.grainAlong ? 'x' : 'y', face: '+z', ...opts });

    const S = v.tileS, g = v.slatGap, sw = v.slatW;
    /** פריסה ממורכזת של n פריטים ברוחב `size` עם מרווח `gap` בתוך `total`: מחזירה [התחלה, n]. */
    const centered = (total, size, gap) => {
      const n = Math.max(1, Math.floor((total + gap) / (size + gap)));
      return [(total - (n * (size + gap) - gap)) / 2, n];
    };

    /**
     * ממלא שדה אחד של קיר בדוגמה: `u0` תחילת השדה לאורך הקיר, `fw` רוחבו, בין yA ל-yB.
     * כל הדוגמאות נבנות מתיבות מקבילות לצירים ויושבות על הלטות (depth = bt).
     */
    const fillField = (w, k, f, u0, fw, yA, yB, pattern) => {
      const fh = yB - yA, pre = `w${k}-f${f}`, note = `קיר ${k} · שדה ${f} · ${PATTERN_NAME[pattern]}`;
      const slat = (id, name, u, ya, yb, len, t, qty, grainAlong = false) => parts.push(place(w, id, name, u, ya, yb, len, bt, t, { material: v.slatMaterial, qtyKey: qty, note, grainAlong }));
      if (pattern === 'slats' || pattern === 'panels') {
        const [start, n] = centered(fw, sw, g);
        for (let i = 0; i < n; i++) slat(`${pre}-slat-${i + 1}`, pattern === 'slats' ? 'סטריפ' : 'לוח חיפוי', u0 + start + i * (sw + g), yA, yB, sw, st, `slat-${Math.round(sw)}x${Math.round(fh)}`);
        if (pattern === 'panels' && g > 12) warnings.push(`קיר ${k}: חריץ ${g} מ"מ בין לוחות — רחב ל-V; 6–12 מקובל`);
      } else if (pattern === 'hslats') {
        const [start, n] = centered(fh, sw, g);
        for (let i = 0; i < n; i++) slat(`${pre}-slat-${i + 1}`, 'סטריפ אופקי', u0, yA + start + i * (sw + g), yA + start + i * (sw + g) + sw, fw, st, `hslat-${Math.round(sw)}x${Math.round(fw)}`, true);
      } else if (pattern === 'flat') {
        const n = Math.ceil(fw / 1200), pw = fw / n;
        for (let i = 0; i < n; i++) {
          const pnl = place(w, `${pre}-panel-${i + 1}`, 'לוח חיפוי', u0 + i * pw, yA, yB, pw, bt, st, { material: v.slatMaterial, qtyKey: `panel-${Math.round(pw)}x${Math.round(fh)}`, edges: { top: true, bottom: true }, note });
          parts.push(pnl);
          // גימור (חירוץ CNC וכו') על הפאה שפונה אל החדר — לפי כיוון הקיר
          if (v.panelFinish && v.panelFinish !== 'flat') parts.push(...applyFinish(pnl, v.panelFinish, { material: v.slatMaterial, normal: '+z' }));
        }
      } else if (pattern === 'squares' || pattern === 'relief' || pattern === 'checker') {
        const [su, nx] = centered(fw, S, g), [sy, ny] = centered(fh, S, g);
        for (let j = 0; j < ny; j++) for (let i = 0; i < nx; i++) {
          const u = u0 + su + i * (S + g), ya = yA + sy + j * (S + g);
          if (pattern === 'squares') slat(`${pre}-tile-${j + 1}-${i + 1}`, 'אריח עץ', u, ya, ya + S, S, st, `tile-${S}`, (i + j) % 2 === 1);
          else if (pattern === 'relief') {
            const t = [st, st * 1.6, st * 0.6][(i * 2 + j) % 3];   // שלושה עומקים, מפוזרים
            slat(`${pre}-tile-${j + 1}-${i + 1}`, 'אריח תבליט', u, ya, ya + S, S, Math.round(t), `relief-${S}-${Math.round(t)}`);
          } else {
            // שחמט: בכל ריבוע סטריפים, אנכיים וארופקיים לסירוגין
            const vertical = (i + j) % 2 === 0;
            const [s0, n] = centered(S, sw, g);
            for (let q = 0; q < n; q++) {
              if (vertical) slat(`${pre}-tile-${j + 1}-${i + 1}-s${q + 1}`, 'סטריפ (שחמט)', u + s0 + q * (sw + g), ya, ya + S, sw, st, `chk-${Math.round(sw)}x${S}`);
              else slat(`${pre}-tile-${j + 1}-${i + 1}-s${q + 1}`, 'סטריפ (שחמט)', u, ya + s0 + q * (sw + g), ya + s0 + q * (sw + g) + sw, S, st, `chk-${Math.round(sw)}x${S}`, true);
            }
          }
        }
      } else if (pattern === 'bricks') {
        // לבנים S × S/2, כל שורה מוזזת בחצי לבנה; בקצוות חצאי לבנים כדי למלא את השדה
        const bh = S / 2, rowP = bh + g, colP = S + g;
        const ny = Math.max(1, Math.floor((fh + g) / rowP)), sy = (fh - (ny * rowP - g)) / 2;
        for (let j = 0; j < ny; j++) {
          const ya = yA + sy + j * rowP, off = j % 2 === 0 ? 0 : -(S / 2 + g / 2);
          let i = 0;
          for (let u = off; u < fw; u += colP, i++) {
            const a = Math.max(0, u), b = Math.min(fw, u + S);
            if (b - a < S / 4) continue;   // שבריר צר מדי בקצה — נבלע בשוליים
            slat(`${pre}-brick-${j + 1}-${i + 1}`, 'לבנת עץ', u0 + a, ya, ya + bh, b - a, st, `brick-${Math.round(b - a)}x${Math.round(bh)}`, true);
          }
        }
      } else if (pattern === 'frames') {
        // לוח רקע שטוח, ועליו מסגרות מפסי עץ — שורות × עמודות של מלבנים
        const n = Math.ceil(fw / 1200), pw = fw / n;
        for (let i = 0; i < n; i++) parts.push(place(w, `${pre}-panel-${i + 1}`, 'לוח רקע', u0 + i * pw, yA, yB, pw, bt, st, { material: v.slatMaterial, qtyKey: `panel-${Math.round(pw)}x${Math.round(fh)}`, note }));
        const fwid = v.frameW, m = Math.max(g, 40), ft = Math.max(8, Math.round(st * 0.6));
        // מסגרת צריכה מקום: תא יעד של לפחות 450 (או מידת הריבוע אם גדולה ממנה)
        const cell = Math.max(S, 450);
        const nx = Math.max(1, Math.round(fw / cell)), ny = Math.max(1, Math.round(fh / cell));
        const cw = fw / nx, ch = fh / ny;
        if (cw - 2 * m >= 3 * fwid && ch - 2 * m >= 3 * fwid) {
          for (let j = 0; j < ny; j++) for (let i = 0; i < nx; i++) {
            const ua = u0 + i * cw + m, ub = u0 + (i + 1) * cw - m, ya = yA + j * ch + m, yb = yA + (j + 1) * ch - m;
            const id = `${pre}-frame-${j + 1}-${i + 1}`;
            const strip = (sid, name, u, y0s, y1s, len, grainAlong) => parts.push(place(w, sid, name, u, y0s, y1s, len, bt + st, ft, { material: v.trimMaterial, qtyKey: `frame-${Math.round(len)}`, note, grainAlong }));
            strip(`${id}-B`, 'פס מסגרת', ua, ya, ya + fwid, ub - ua, true);
            strip(`${id}-T`, 'פס מסגרת', ua, yb - fwid, yb, ub - ua, true);
            strip(`${id}-L`, 'פס מסגרת', ua, ya + fwid, yb - fwid, fwid, false);
            strip(`${id}-R`, 'פס מסגרת', ub - fwid, ya + fwid, yb - fwid, fwid, false);
          }
        } else warnings.push(`קיר ${k} שדה ${f}: השדה קטן מדי למסגרות — להגדיל את השדה או להצר את פס המסגרת`);
      }
    };

    for (const w of walls) {
      const k = w.i + 1;
      const startInset = w.a, len = w.free;
      parts = [];
      const next = walls[w.i + 1];
      // השדות: רוחב נעוץ/אוטומטי, דוגמה וגובה לכל שדה (ברירת מחדל — גובה החיפוי)
      const lay = sectionLayout(v.columnsLayout, `wall${k}`, v.fields);
      const widths = resolveShares(len, lay.widths);
      const fieldH = Array.from({ length: v.fields }, (_, f) => { const h = lay.cols[f]?.height; return Number.isFinite(h) && h > 0 ? Math.round(Math.max(minFieldH(v), Math.min(H, h))) : H; });
      const stepped = fieldH.some((h) => h !== H);
      const battens = (id, uA, ln, hh, note) => {
        for (let r = 0; r < v.battenRows; r++) {
          const by = y0 + (hh - 60) * (v.battenRows === 1 ? 0.5 : r / (v.battenRows - 1));
          parts.push(place(w, `${id}-${r + 1}`, 'לטת רוחב', uA, by, by + 60, ln, 0, bt, { material: v.battenMaterial, grainAlong: true, qtyKey: `batten-${Math.round(ln)}`, note }));
        }
      };
      // לטות רוחב: לכל הקיר; כשהשדות בגבהים שונים — לכל שדה, בגובה שלו
      // הלטות על הקיר עצמו: בגרונג — מהפינה (בפנימית אחרי שכבת הקיר הקודם), עד סוף הקיר
      // ובזווית חדה הן נעצרות לפני הקיר הבא, כדי לא לחדור אליו
      const bA = w.mit ? Math.max(0, totalT * w.k) : w.inset;
      const bB = next && next.alpha < 90 ? w.len - bt / Math.tan(next.alpha * RAD) : w.len;
      const bLen = bB - bA;
      if (!direct && !stepped) battens(`w${k}-batten`, bA, bLen, H, `קיר ${k}`);
      let u = startInset;
      for (let f = 0; f < v.fields; f++) {
        const pattern = PATTERN_NAME[lay.cols[f]?.kind] ? lay.cols[f].kind : v.style;
        const fTop = y0 + fieldH[f];
        if (!direct && stepped) {
          // לטות השדה — רק על הקיר עצמו (לא מעבר לפינה)
          const la = Math.max(u, bA), lb = Math.min(u + widths[f], bB);
          if (lb - la > 60) battens(`w${k}-f${f + 1}-batten`, la, lb - la, fieldH[f], `קיר ${k} · שדה ${f + 1}`);
        }
        fillField(w, k, f + 1, u, widths[f], y0 + v.baseH, fTop - v.crownH, pattern);
        if (stepped && v.crownH > 0) parts.push(place(w, `w${k}-f${f + 1}-crown`, 'קרניז עליון', u, fTop - v.crownH, fTop, widths[f], bt, st, { material: v.trimMaterial, grainAlong: true, qtyKey: `crown-${Math.round(widths[f])}`, note: `קיר ${k} · שדה ${f + 1}` }));
        u += widths[f];
      }
      if (v.baseH > 0) parts.push(place(w, `w${k}-base`, 'פנל תחתון', startInset, y0, y0 + v.baseH, len, bt, st, { material: v.trimMaterial, grainAlong: true, qtyKey: `base-${Math.round(len)}`, note: `קיר ${k}` }));
      if (!stepped && v.crownH > 0) parts.push(place(w, `w${k}-crown`, 'קרניז עליון', startInset, y1 - v.crownH, y1, len, bt, st, { material: v.trimMaterial, grainAlong: true, qtyKey: `crown-${Math.round(len)}`, note: `קיר ${k}` }));
      // גרונג: כל חלק בשכבת החיפוי שמגיע לקצה הפינתי נחתך בקו הגרונג — בפינה פנימית הפאה שאל
      // החדר קצרה יותר, בחיצונית הפאה שאל הקיר. במערכת המקומית: התחלה = '-x', סוף = '+x'.
      const ends = [];
      if (w.mit) ends.push({ at: w.a, end: '-x', k: w.k, alpha: w.alpha });
      if (next && next.mit) ends.push({ at: w.b, end: '+x', k: next.k, alpha: next.alpha });
      for (const p of parts) {
        if (/batten/.test(p.id)) continue;
        const u0 = p.box.x, u1 = p.box.x + p.box.w, depth = p.box.d;
        for (const e of ends) {
          if (Math.abs((e.end === '-x' ? u0 : u1) - e.at) > 0.5) continue;
          const cut = Math.round(depth * Math.abs(e.k) * 1000) / 1000;
          if (cut < 0.1) continue;
          (p.miter ||= []).push({ end: e.end, short: e.k > 0 ? '+z' : '-z', cut });
          const deg = Math.round(Math.abs(180 - e.alpha) / 2 * 10) / 10;
          p.note = [p.note, `גרונג ${deg}° בקצה הפינה`].filter(Boolean).join(' — ');
        }
      }
      // הצבה בעולם: סיבוב הקיר סביב תחילתו (ברבעים — נאפה לתיבה מקבילה)
      const xf = { yaw: w.yaw, x: w.ox, z: w.oz };
      for (const p of parts) all.push(xfPart(p, xf));
      if (w.i > 0 && !isRight(w.alpha) && w.alpha !== 180) warnings.push(`פינה ${k - 1}–${k} בזווית ${w.alpha}° — החיבור בגרונג ${Math.round(Math.abs(180 - w.alpha) / 2 * 10) / 10}°`);
    }
    // נירמול: מזיזים הכול כך שהמינימום יהיה 0 (הגבולות חייבים להיות חיוביים)
    const box = partsAabb(all);
    parts = all.map((p) => shiftPart(p, [-box.x, 0, -box.z]));
    if (direct) hardware.push({ id: 'glue', kind: 'misc', material: 'hw:leg-adjust', qty: 0, note: 'התקנה ישירה: דבק פוליאוריתן/MS לקיר, ולפי הצורך מסמרי פנאומטיקה או ברגים נסתרים. הקיר חייב להיות ישר — סטייה של מעל 3 מ"מ למטר דורשת לטות' });
    else hardware.push({ id: 'screws', kind: 'misc', material: 'hw:leg-adjust', qty: 0, note: `ברגים ודיבלים: ~${Math.round(walls.reduce((s, w) => s + w.len, 0) / 400) * v.battenRows} לקיבוע הלטות` });
    if (sw / st > 4 && parts.some((p) => p.name === 'סטריפ')) warnings.push(`סטריפ ברוחב ${sw} ובעובי ${st} — דק ליחס; נוטה להתעקם`);
    if (H > 2800) warnings.push(`חיפוי בגובה ${H} — מעל 2800 הסטריפים מתחלקים לשניים${direct ? '' : ' (חיבור באמצע הלטה)'}`);
    if (v.corner === 'miter' && walls.length > 1 && st < 12) warnings.push(`גרונג בעובי ${st} — דק לחיתוך 45°; קצה הגרונג נשבר בקלות`);
    if (parts.length > 1500) warnings.push(`${parts.length} חלקים — דוגמה צפופה; רשימת החיתוך מקבצת זהים, אבל ההדמיה תהיה כבדה`);

    return { parts, hardware, warnings, bounds: { w: box.w, h: y1, d: box.d } };
  },
};

/** הגובה הנמוך ביותר לשדה: פנל תחתון, קרניז ו-100 של דוגמה. */
function minFieldH(v) { return (v.baseH ?? 0) + (v.crownH ?? 0) + 100; }

