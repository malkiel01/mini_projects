// אביזרים: ידיות, כפתורים, צירים וגלגלים — אלמנטים תלת־ממדיים אמיתיים, לא
// קוביות. כל סוג הוא מתכון פרמטרי שמחזיר גופים פשוטים (גליל, תיבה, כדור,
// טבעת) במערכת צירים מקומית: הראשית בנקודת ההרכבה על פני הלוח, +z החוצה
// מהלוח (אל החדר), +y למעלה, +x ימינה לאורך הלוח. הצופה מסובב את הקבוצה
// לפי הפאה שהאביזר יושב עליה (חזית, צד, תחתית) — ראו faceOf.
//
// האביזרים עצמם הם חומרים מסוג 'hardware' בספרייה, עם שדה `accessory:
// { type, params, finish }`; הנגר מנהל אותם במסך "אביזרים" ובוחר מהם
// בטופס (ידיות, צירים, גלגלים). המחיר — כמו כל פרזול.

export const FINISHES = [
  // מתכתיות מתונה: בלי מפת סביבה, metalness גבוה נראה שחור בסצנה; כך הצבע נקרא.
  { id: 'chrome', name: 'כרום מבריק', color: 0xe3e6ea, metalness: 0.6, roughness: 0.2 },
  { id: 'nickel', name: 'ניקל מוברש', color: 0xc4c8cd, metalness: 0.5, roughness: 0.45 },
  { id: 'black', name: 'שחור מט', color: 0x2a2b31, metalness: 0.2, roughness: 0.7 },
  { id: 'brass', name: 'פליז / זהב מוברש', color: 0xd4b05e, metalness: 0.55, roughness: 0.35 },
  { id: 'bronze', name: 'ברונזה כהה', color: 0x6e5238, metalness: 0.5, roughness: 0.45 },
  { id: 'white', name: 'לבן', color: 0xf3f2ee, metalness: 0.1, roughness: 0.5 },
  { id: 'wood', name: 'עץ', color: 0xb98a56, metalness: 0, roughness: 0.75 },
  { id: 'rubber', name: 'גומי שחור', color: 0x2a2a2a, metalness: 0, roughness: 0.95 },
];
export const finishOf = (id) => FINISHES.find((f) => f.id === id) || FINISHES[0];

export const KINDS = [
  { id: 'handle', name: 'ידיות' },
  { id: 'knob', name: 'כפתורים' },
  { id: 'hinge', name: 'צירים' },
  { id: 'wheel', name: 'גלגלים' },
];

// עזרי צורה — כל גוף: { shape, pos:[x,y,z], size, rot?:[rx,ry,rz], finish?, moving? }
// cylinder: size = [radius, length] לאורך ציר y המקומי שלו (rot מסובב); box: [w,h,d]; sphere: [r]; torus: [R, r]
const cyl = (pos, r, len, rot = [0, 0, 0], extra = {}) => ({ shape: 'cylinder', pos, size: [r, len], rot, ...extra });
const box = (pos, size, extra = {}) => ({ shape: 'box', pos, size, ...extra });
const sphere = (pos, r, extra = {}) => ({ shape: 'sphere', pos, size: [r], ...extra });
const torus = (pos, R, r, rot = [0, 0, 0], extra = {}) => ({ shape: 'torus', pos, size: [R, r], rot, ...extra });
const ALONG_X = [0, 0, Math.PI / 2];   // גליל שציר האורך שלו x
const ALONG_Z = [Math.PI / 2, 0, 0];   // גליל שציר האורך שלו z (החוצה מהלוח)

const P = (key, label, def, min, max, step = 1) => ({ key, label, default: def, min, max, step });

export const TYPES = [
  // ---- ידיות ----
  { id: 'bar', kind: 'handle', name: 'ידית מוט (עגולה)', params: [P('length', 'מרחק בין הברגים', 128, 32, 1200, 32), P('diameter', 'קוטר המוט', 10, 6, 20), P('projection', 'בליטה מהחזית', 35, 20, 60)],
    build: ({ length, diameter, projection }) => {
      const r = diameter / 2, post = projection - r;
      return [cyl([-length / 2, 0, post / 2], r * 0.8, post, ALONG_Z), cyl([length / 2, 0, post / 2], r * 0.8, post, ALONG_Z), cyl([0, 0, projection - r], r, length + diameter, ALONG_X), sphere([-length / 2 - r, 0, projection - r], r), sphere([length / 2 + r, 0, projection - r], r)];
    } },
  { id: 'bar-flat', kind: 'handle', name: 'ידית פס שטוח (מלבני)', params: [P('length', 'מרחק בין הברגים', 160, 32, 1200, 32), P('width', 'רוחב הפס', 12, 6, 30), P('thickness', 'עובי הפס', 8, 4, 16), P('projection', 'בליטה מהחזית', 32, 20, 60)],
    build: ({ length, width, thickness, projection }) => {
      const post = projection - thickness;
      return [box([-length / 2, 0, post / 2], [width, width, post]), box([length / 2, 0, post / 2], [width, width, post]), box([0, 0, projection - thickness / 2], [length + width, width, thickness])];
    } },
  { id: 'bow', kind: 'handle', name: 'ידית קשת (מעוגלת)', params: [P('length', 'מרחק בין הברגים', 128, 32, 600, 32), P('diameter', 'קוטר', 10, 6, 18), P('projection', 'בליטה מהחזית', 30, 20, 50)],
    build: ({ length, diameter, projection }) => {
      const r = diameter / 2, R = Math.min(projection - r, length / 2 - 1);
      // שני רבעי טבעת בקצוות (קשת) וקטע ישר ביניהם
      return [torus([-length / 2 + R, 0, 0], R, r, [0, Math.PI / 2, 0], { arc: [Math.PI / 2, Math.PI / 2] }), torus([length / 2 - R, 0, 0], R, r, [0, Math.PI / 2, 0], { arc: [0, Math.PI / 2] }), cyl([0, 0, R], r, Math.max(2, length - 2 * R), ALONG_X)];
    } },
  { id: 't-bar', kind: 'handle', name: 'ידית T (בורג אחד)', params: [P('length', 'אורך המוט', 60, 20, 300, 10), P('diameter', 'קוטר', 10, 6, 18), P('projection', 'בליטה מהחזית', 32, 20, 50)],
    build: ({ length, diameter, projection }) => {
      const r = diameter / 2;
      return [cyl([0, 0, (projection - r) / 2], r * 0.9, projection - r, ALONG_Z), cyl([0, 0, projection - r], r, length, ALONG_X), sphere([-length / 2, 0, projection - r], r), sphere([length / 2, 0, projection - r], r)];
    } },
  { id: 'edge-profile', kind: 'handle', name: 'ידית פרופיל קצה (לאורך הקצה)', params: [P('length', 'אורך', 200, 50, 2400, 10), P('height', 'גובה הפרופיל', 22, 12, 40), P('projection', 'בליטה', 10, 4, 25)],
    build: ({ length, height, projection }) => [box([0, height / 2 - 2, 2], [length, height, 4]), box([0, height - 2 - projection / 4, projection / 2 + 2], [length, projection / 2, projection])] },
  { id: 'shell', kind: 'handle', name: 'ידית קונכייה (שקועה)', params: [P('width', 'רוחב', 90, 40, 200, 5), P('height', 'גובה', 40, 20, 80), P('depth', 'עומק השקע', 12, 6, 25)],
    build: ({ width, height, depth }) => [box([0, 0, -depth / 2], [width, height, depth], { finish: 'black' }), box([0, height / 2 + 2, 1], [width + 8, 4, 2]), box([-width / 2 - 2, 0, 1], [4, height + 8, 2]), box([width / 2 + 2, 0, 1], [4, height + 8, 2])] },
  { id: 'leather', kind: 'handle', name: 'ידית רצועת עור', params: [P('length', 'מרחק בין הברגים', 96, 32, 300, 16), P('width', 'רוחב הרצועה', 18, 10, 30), P('projection', 'בליטה', 28, 15, 45)],
    build: ({ length, width, projection }) => [cyl([-length / 2, 0, 3], 5, 6, ALONG_Z, { finish: 'brass' }), cyl([length / 2, 0, 3], 5, 6, ALONG_Z, { finish: 'brass' }), box([-length / 2, 0, projection / 2], [width, 3, projection], { finish: 'bronze' }), box([length / 2, 0, projection / 2], [width, 3, projection], { finish: 'bronze' }), box([0, 0, projection - 1.5], [length + width, width, 3], { finish: 'bronze' })] },
  // ---- כפתורים ----
  { id: 'knob-round', kind: 'knob', name: 'כפתור כדור', params: [P('diameter', 'קוטר', 28, 12, 60), P('projection', 'בליטה', 30, 15, 50)],
    build: ({ diameter, projection }) => { const r = diameter / 2; return [cyl([0, 0, (projection - r) / 2], r * 0.35, projection - r, ALONG_Z), sphere([0, 0, projection - r], r)]; } },
  { id: 'knob-mushroom', kind: 'knob', name: 'כפתור פטרייה (דיסקה)', params: [P('diameter', 'קוטר', 32, 15, 70), P('thickness', 'עובי הראש', 10, 4, 25), P('projection', 'בליטה', 26, 12, 45)],
    build: ({ diameter, thickness, projection }) => [cyl([0, 0, (projection - thickness) / 2], diameter * 0.2, projection - thickness, ALONG_Z), cyl([0, 0, projection - thickness / 2], diameter / 2, thickness, ALONG_Z), torus([0, 0, projection - thickness / 2], diameter / 2 - thickness / 4, thickness / 4, [0, 0, 0])] },
  { id: 'knob-cylinder', kind: 'knob', name: 'כפתור גליל', params: [P('diameter', 'קוטר', 20, 8, 50), P('projection', 'אורך', 30, 10, 60)],
    build: ({ diameter, projection }) => [cyl([0, 0, projection / 2], diameter / 2, projection, ALONG_Z)] },
  { id: 'knob-square', kind: 'knob', name: 'כפתור מרובע', params: [P('size', 'צלע', 25, 10, 60), P('thickness', 'עובי', 10, 4, 25), P('projection', 'בליטה', 25, 12, 45)],
    build: ({ size, thickness, projection }) => [cyl([0, 0, (projection - thickness) / 2], size * 0.18, projection - thickness, ALONG_Z), box([0, 0, projection - thickness / 2], [size, size, thickness])] },
  { id: 'knob-ring', kind: 'knob', name: 'כפתור טבעת (תלויה)', params: [P('diameter', 'קוטר הטבעת', 40, 20, 80), P('wire', 'עובי החוט', 5, 3, 10)],
    build: ({ diameter, wire }) => [cyl([0, 0, 4], wire * 1.6, 8, ALONG_Z), sphere([0, 0, 8], wire * 1.4), torus([0, -diameter / 2, 8], diameter / 2, wire / 2, [0, 0, 0])] },
  // ---- צירים ---- (הראשית: הפאה האחורית של הדלת, 22 מ"מ מקצה הציר; +z לתוך הדלת; `side` בא מהצופה)
  { id: 'concealed', kind: 'hinge', name: 'ציר נסתר (כוס 35)', params: [P('angle', 'זווית פתיחה', 110, 90, 180, 5), P('cup', 'קוטר הכוס', 35, 26, 40)],
    // `reach` — המרחק ממרכז הכוס אל הפאה הפנימית של הדופן/המחיצה, לכיוון קצה הציר
    // (דלת חופה מלאה על דופן 18: 22.5 − 18 = 4.5). הפלטה יושבת על הפאה הזו, 37 מ"מ
    // מהקצה הקדמי — כמו בקידוחים (physics.js); הזרוע מחברת את הכוס אל הפלטה, בתוך הארון.
    build: ({ cup }, { side = 'left', reach = 4.5 } = {}) => {
      const s = side === 'left' ? -1 : 1;
      const plateT = 10, armW = 14;
      const plateX = s * (reach - plateT / 2), armX = s * (reach - plateT - armW / 2);
      return [cyl([0, 0, 6], cup / 2, 12, ALONG_Z, { moving: true }), box([0, 0, -2], [cup + 12, 10, 4], { moving: true }),   // כוס וזרוע בדלת — נעים איתה
        box([armX, 0, -24], [armW, 14, 44]),                                    // גוף הציר: מהכוס אל הפלטה, לאורך הדופן
        box([plateX, 0, -37], [plateT, 50, 34], { finish: 'nickel' })];        // פלטת ההרכבה על הפאה הפנימית
    } },
  { id: 'butt', kind: 'hinge', name: 'ציר פרפר (כנפיים גלויות)', params: [P('length', 'אורך', 60, 30, 120, 5), P('width', 'רוחב כנף', 20, 12, 40)],
    build: ({ length, width }, { side = 'left' } = {}) => {
      const s = side === 'left' ? -1 : 1;
      return [box([s * (width / 2 - 22), 0, 1.5], [width, length, 3], { moving: true }), cyl([s * 22, 0, 3], 4, length + 4, [0, 0, 0]), box([s * (22 + width / 2), 0, -1.5], [width, length, 3])];
    } },
  { id: 'pivot', kind: 'hinge', name: 'ציר פיבוט (עליון/תחתון)', params: [P('diameter', 'קוטר הפין', 10, 6, 16)],
    build: ({ diameter }, { side = 'left' } = {}) => { const s = side === 'left' ? -1 : 1; return [cyl([s * 22, 0, 0], diameter / 2, 24, [0, 0, 0], { moving: true }), box([s * 22, 0, -8], [30, 10, 12])]; } },
  // ---- גלגלים ---- (הראשית: נקודת ההרכבה על פני התחתית; +z כלפי מטה אחרי סיבוב הצופה)
  { id: 'caster-swivel', kind: 'wheel', name: 'גלגל מסתובב', params: [P('diameter', 'קוטר הגלגל', 50, 25, 125, 5), P('width', 'רוחב הגלגל', 20, 10, 40), P('brake', 'בלם (0/1)', 0, 0, 1)],
    build: ({ diameter, width, brake }) => {
      const R = diameter / 2, h = R + 12 + 10;   // גובה כולל: פלטה + מזלג + רדיוס
      const out = [box([0, 0, 2], [45, 45, 4]), cyl([0, 0, 8], 10, 8, ALONG_Z), box([-width / 2 - 2, 0, 12 + R / 2], [3, 24, R], {}), box([width / 2 + 2, 0, 12 + R / 2], [3, 24, R]), cyl([0, 0, 12 + R], R, width, ALONG_X, { finish: 'rubber' }), cyl([0, 0, 12 + R], R * 0.45, width + 2, ALONG_X)];
      if (brake) out.push(box([0, R + 4, 14], [16, 10, 12], { finish: 'black' }));
      return out;
    } },
  { id: 'caster-fixed', kind: 'wheel', name: 'גלגל קבוע', params: [P('diameter', 'קוטר הגלגל', 50, 25, 125, 5), P('width', 'רוחב הגלגל', 20, 10, 40)],
    build: ({ diameter, width }) => { const R = diameter / 2; return [box([0, 0, 2], [40, 50, 4]), box([-width / 2 - 2, 0, 4 + R / 2], [3, 24, R]), box([width / 2 + 2, 0, 4 + R / 2], [3, 24, R]), cyl([0, 0, 4 + R], R, width, ALONG_X, { finish: 'rubber' }), cyl([0, 0, 4 + R], R * 0.45, width + 2, ALONG_X)]; } },
  { id: 'ball-caster', kind: 'wheel', name: 'גלגל כדורי', params: [P('diameter', 'קוטר הכדור', 30, 15, 60)],
    build: ({ diameter }) => { const R = diameter / 2; return [box([0, 0, 2], [40, 40, 4]), cyl([0, 0, 4 + R * 0.5], R * 0.9, R, ALONG_Z), sphere([0, 0, 4 + R], R, { finish: 'rubber' })]; } },
];
export const typeOf = (id) => TYPES.find((t) => t.id === id);

/** הפרמטרים בפועל: ברירות המחדל של הסוג ומעליהן מה שנשמר, מוצמדים לטווח. */
export function paramsOf(type, params = {}) {
  const out = {};
  for (const p of type.params) {
    const v = Number(params[p.key]);
    out[p.key] = Number.isFinite(v) ? Math.min(p.max, Math.max(p.min, v)) : p.default;
  }
  return out;
}

/**
 * גופי האביזר של חומר (hardware עם accessory). `opts.side` לצירים.
 * מחזיר { prims, finish, type } או null אם לחומר אין מתכון.
 */
export function buildAccessory(materialDef, opts = {}) {
  const spec = materialDef && materialDef.accessory;
  const type = spec && typeOf(spec.type);
  if (!type) return null;
  const params = paramsOf(type, spec.params);
  const finish = finishOf(spec.finish);
  const prims = type.build(params, opts).map((pr) => ({ ...pr, finish: finishOf(pr.finish || finish.id) }));
  return { prims, finish, type, params };
}

/** גובה הגלגל (כמה המודל מורם) — לפי הסוג והפרמטרים. */
export function wheelHeight(materialDef) {
  const a = buildAccessory(materialDef);
  if (!a || a.type.kind !== 'wheel') return 0;
  let maxZ = 0;
  for (const pr of a.prims) {
    const ext = pr.shape === 'cylinder' ? (pr.rot === ALONG_Z ? pr.size[1] / 2 : pr.size[0]) : pr.shape === 'box' ? pr.size[2] / 2 : pr.size[0];
    maxZ = Math.max(maxZ, pr.pos[2] + ext);
  }
  return Math.round(maxZ);
}

/**
 * על איזו פאה של התיבה יושבת נקודה: '+z' חזית, '-z' אחור, '+x' ימין, '-x' שמאל, '-y' תחתית, '+y' גג.
 * לפי הפאה הקרובה ביותר (המרחק הקטן ביותר).
 */
export function faceOf(b, pos) {
  const d = [
    ['+z', Math.abs(pos[2] - (b.z + b.d))], ['-z', Math.abs(pos[2] - b.z)],
    ['+x', Math.abs(pos[0] - (b.x + b.w))], ['-x', Math.abs(pos[0] - b.x)],
    ['-y', Math.abs(pos[1] - b.y)], ['+y', Math.abs(pos[1] - (b.y + b.h))],
  ];
  d.sort((a, c) => a[1] - c[1]);
  return d[0][0];
}

/** סיבוב (אוילר, רדיאנים) שמביא את +z המקומי לנורמל הנתון; +y נשאר למעלה ככל האפשר. */
export function rotationForNormal(n) {
  return { '+z': [0, 0, 0], '-z': [0, Math.PI, 0], '+x': [0, Math.PI / 2, 0], '-x': [0, -Math.PI / 2, 0], '-y': [Math.PI / 2, 0, 0], '+y': [-Math.PI / 2, 0, 0] }[n] || [0, 0, 0];
}

/** כיוון +x המקומי בעולם, לפי הנורמל — כדי לדעת מאיזה צד של הדלת הציר. */
export function localXOf(n) {
  return { '+z': [1, 0, 0], '-z': [-1, 0, 0], '+x': [0, 0, -1], '-x': [0, 0, 1], '-y': [1, 0, 0], '+y': [1, 0, 0] }[n] || [1, 0, 0];
}
