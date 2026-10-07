// לוחות: כמה צריך, ואיך מסדרים את החלקים עליהם.
//
// הסידור הוא "גיליוטינה במדפים" (shelf packing): החלקים ממוינים מהגבוה
// לנמוך ומונחים בשורות לרוחב הלוח; שורה נגמרת — פותחים שורה חדשה מתחתיה;
// הלוח נגמר — לוח חדש. חלק שהסיבים שלו חשובים (עץ, מלמין) לא מסובב —
// הסיבים רצים לאורך הלוח; חלק צבוע מסתובב אם זה חוסך מקום. זו הערכה טובה
// לכמות ולפחת, לא תוכנית חיתוך אופטימלית — ראו README, "מה טרם הוכרע".
// בלי DOM — רץ גם ב-node.

import { material } from './materials.js';
import { PRICING_DEFAULTS } from './pricing.js';

/**
 * @param cut   תוצאת cutList — { boards: [{ name, qty, l, w, t, material(שם), ids, grain }] }
 * @param parts רשימת החלקים (כדי לדעת את מזהה החומר של כל שורה)
 * @returns לכל חומר: { id, name, sheet: [L, W], count, waste, sheets: [{ L, W, places: [{ x, y, w, h, name, rotated }] }] }
 */
export function nest(cut, parts, { kerf = PRICING_DEFAULTS.kerf } = {}) {
  const idOf = new Map(parts.map((p) => [p.id, p.material]));
  const groups = new Map();
  for (const row of cut.boards) {
    const matId = idOf.get(row.ids[0]);
    const m = material(matId);
    if (!m.sheet) continue;   // חומר בלי מידת לוח (קנט וכו') לא נספר כלוחות
    const g = groups.get(matId) || { id: matId, name: m.name, sheet: m.sheet, grainMatters: m.finish === 'wood' || m.finish === 'melamine' || !!m.image, items: [] };
    for (let i = 0; i < row.qty; i++) g.items.push({ name: row.name, l: row.l, w: row.w, id: row.ids[i] || row.ids[0] });
    groups.set(matId, g);
  }
  const out = [];
  for (const g of groups.values()) out.push(packGroup(g, kerf));
  return out;
}

function packGroup(g, kerf) {
  const [L, W] = g.sheet;   // L — לאורך הסיבים של הלוח
  // כל פריט: רוחב על הלוח (לאורך L) = האורך שלו (הסיבים לאורך), גובה = הרוחב שלו.
  // חלק שאפשר לסובב — בוחרים את הכיוון שבו הוא "נמוך" יותר, כדי שהשורות יהיו צפופות.
  const items = g.items.map((it) => {
    let w = it.l, h = it.w, rotated = false;
    if (!g.grainMatters && it.w > it.l && it.w <= L) { w = it.w; h = it.l; rotated = true; }
    return { ...it, w, h, rotated, ok: w <= L && h <= W };
  });
  const tooBig = items.filter((it) => !it.ok);
  const sheets = [];
  const sorted = items.filter((it) => it.ok).sort((a, b) => b.h - a.h || b.w - a.w);
  for (const it of sorted) {
    let placed = false;
    for (const s of sheets) {
      // שורה קיימת עם מקום לרוחב ושגובהה מספיק
      for (const row of s.rows) {
        if (it.h <= row.h && row.x + it.w <= L) {
          s.places.push({ x: row.x, y: row.y, w: it.w, h: it.h, name: it.name, rotated: it.rotated, id: it.id });
          row.x += it.w + kerf; placed = true; break;
        }
      }
      if (placed) break;
      // שורה חדשה בלוח הזה
      const last = s.rows[s.rows.length - 1];
      const y = last ? last.y + last.h + kerf : 0;
      if (y + it.h <= W) {
        s.rows.push({ y, h: it.h, x: it.w + kerf });
        s.places.push({ x: 0, y, w: it.w, h: it.h, name: it.name, rotated: it.rotated, id: it.id });
        placed = true; break;
      }
    }
    if (!placed) {
      sheets.push({ L, W, rows: [{ y: 0, h: it.h, x: it.w + kerf }], places: [{ x: 0, y: 0, w: it.w, h: it.h, name: it.name, rotated: it.rotated, id: it.id }] });
    }
  }
  const used = items.filter((it) => it.ok).reduce((s, it) => s + it.w * it.h, 0);
  const waste = sheets.length ? 1 - used / (sheets.length * L * W) : 0;
  return {
    id: g.id, name: g.name, sheet: g.sheet, count: sheets.length,
    waste: Math.round(waste * 100) / 100,
    sheets: sheets.map(({ L, W, places }) => ({ L, W, places })),
    tooBig: tooBig.map((it) => `${it.name} ${it.l}×${it.w}`),
  };
}

/** ספירה מהירה בלי סידור: שטח נטו חלקי שטח לוח, עם פחת. */
export function sheetCount(cut, parts, waste = PRICING_DEFAULTS.waste) {
  const idOf = new Map(parts.map((p) => [p.id, p.material]));
  const by = new Map();
  for (const row of cut.boards) {
    const matId = idOf.get(row.ids[0]);
    const m = material(matId);
    if (!m.sheet) continue;
    const g = by.get(matId) || { id: matId, name: m.name, sheet: m.sheet, area: 0 };
    g.area += row.qty * row.l * row.w;
    by.set(matId, g);
  }
  return [...by.values()].map((g) => ({
    ...g, area: Math.round(g.area / 1e4) / 100,
    count: Math.ceil((g.area * waste) / (g.sheet[0] * g.sheet[1])),
  }));
}
