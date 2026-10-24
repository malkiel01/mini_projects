// עוגני סימנים (ניקוד, דגש, טעמים) מטבלת GPOS של הגופן — MarkBasePos (lookup 4) ו-MarkMarkPos (lookup 6), גם בתוך
// Extension (9).
// opentype.js לא קורא אותם, אז כאן קוראים את הבתים ישירות. בלי DOM.

/**
 * buffer (ArrayBuffer של הקובץ) → [{ kind, marks: Map(glyph → { cls, x, y }), bases: Map(glyph → [{ x, y } | null]) }]
 * — תת-טבלה לכל רשומה, כי המחלקות של הסימנים מקומיות לתת-הטבלה. kind: 'base' (סימן על אות) או 'mark' (סימן על
 * סימן — אז bases הם הסימנים שמתחת). שתי הטבלאות באותו מבנה בקובץ. גופן בלי GPOS → [].
 */
export function parseMarks(buffer) {
  try {
    const v = new DataView(buffer);
    const u16 = (o) => v.getUint16(o), i16 = (o) => v.getInt16(o), u32 = (o) => v.getUint32(o);
    const n = u16(4);
    let gpos = -1;
    for (let i = 0; i < n; i++) {
      const rec = 12 + i * 16;
      if (String.fromCharCode(v.getUint8(rec), v.getUint8(rec + 1), v.getUint8(rec + 2), v.getUint8(rec + 3)) === 'GPOS') gpos = u32(rec + 8);
    }
    if (gpos < 0) return [];
    const coverage = (o) => {
      const out = [];
      if (u16(o) === 1) { for (let i = 0, c = u16(o + 2); i < c; i++) out.push(u16(o + 4 + i * 2)); }
      else {
        for (let i = 0, c = u16(o + 2); i < c; i++) {
          const r = o + 4 + i * 6;
          for (let g = u16(r); g <= u16(r + 2); g++) out.push(g);
        }
      }
      return out;
    };
    const anchor = (o) => ({ x: i16(o + 2), y: i16(o + 4) });
    const markBase = (st) => {
      if (u16(st) !== 1) return null;
      const markCov = coverage(st + u16(st + 2)), baseCov = coverage(st + u16(st + 4));
      const classes = u16(st + 6), ma = st + u16(st + 8), ba = st + u16(st + 10);
      const marks = new Map(), bases = new Map();
      markCov.forEach((g, i) => {
        const rec = ma + 2 + i * 4;
        marks.set(g, { cls: u16(rec), ...anchor(ma + u16(rec + 2)) });
      });
      baseCov.forEach((g, i) => {
        const rec = ba + 2 + i * classes * 2;
        bases.set(g, Array.from({ length: classes }, (_, c) => { const off = u16(rec + c * 2); return off ? anchor(ba + off) : null; }));
      });
      return { marks, bases };
    };
    const list = gpos + u16(gpos + 8);
    const out = [];
    for (let i = 0, c = u16(list); i < c; i++) {
      const lk = list + u16(list + 2 + i * 2);
      let type = u16(lk);
      for (let s = 0, sc = u16(lk + 4); s < sc; s++) {
        let st = lk + u16(lk + 6 + s * 2), t = type;
        if (t === 9) { t = u16(st + 2); st += u32(st + 4); }
        if (t === 4 || t === 6) { const mb = markBase(st); if (mb) out.push({ kind: t === 4 ? 'base' : 'mark', ...mb }); }
      }
    }
    return out;
  } catch {
    return []; // טבלה פגומה — בלי עוגנים, הסימנים במקום של opentype
  }
}

/** ההיסט (ביחידות הגופן, y למעלה) של סימן מעל/מתחת לאות (kind 'base') או לסימן אחר ('mark') — או null. */
export function markOffset(tables, base, mark, kind = 'base') {
  for (const t of tables || []) {
    if ((t.kind || 'base') !== kind) continue;
    const m = t.marks.get(mark), b = t.bases.get(base);
    const a = m && b?.[m.cls];
    if (a) return { x: a.x - m.x, y: a.y - m.y };
  }
  return null;
}
