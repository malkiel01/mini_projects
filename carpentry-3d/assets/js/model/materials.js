// ספריית החומרים.
//
// `SEED` היא הזריעה מהקוד — הערכים ההתחלתיים. הספרייה החיה (`library`)
// מתחילה מהזריעה, והמסך "ספריית חומרים" משנה אותה: מוסיף, עורך, משבית.
// השינויים נשמרים בדפדפן (store.js) ובשלב 4 יעברו לשרת. המודל מתייחס
// לחומר רק דרך המזהה, והתצוגה מחליטה איך הוא נראה — כך אפשר להחליף צבע,
// טקסטורה או תמונה בלי לגעת בגאומטריה.
//
// שדות:
//   kind      board | edge | glass | hardware — קובע איך החומר נספר ומתומחר
//   t         עובי במ"מ
//   color     צבע ההדמיה הבסיסי (hex)
//   finish    wood | melamine | paint | glass | none — איזו טקסטורה פרוצדורלית
//   grainMm   כמה מ"מ מכסה חזרה אחת של הטקסטורה (קנה המידה של הסיבים)
//   image     תמונה אמיתית של החומר (data URL), אם הועלתה; דורסת את finish
//   imageMm   כמה מ"מ מכסה התמונה לרוחבה
//   sheet     מידת הלוח [אורך, רוחב] לספירת לוחות
//   price     מחיר ליחידה; priceUnit: m2 | m | unit. מספרים משוערים לשלב 5 —
//             כל נגר דורס אותם בתעריפים שלו
//   active    חומר מושבת לא מוצע בטופס, אך פרויקט שכבר משתמש בו ממשיך לעבוד

export const SEED = [
  { id: 'board:melamine-white-18', kind: 'board', name: 'מלמין לבן 18', t: 18, color: 0xf2f0ea, finish: 'paint', sheet: [2800, 2070], price: 120, priceUnit: 'm2' },
  { id: 'board:melamine-oak-18',   kind: 'board', name: 'מלמין אלון 18', t: 18, color: 0xc9a46c, finish: 'melamine', grainMm: 600, sheet: [2800, 2070], price: 140, priceUnit: 'm2' },
  { id: 'board:melamine-grey-18',  kind: 'board', name: 'מלמין אפור 18', t: 18, color: 0xb8b4ae, finish: 'paint', sheet: [2800, 2070], price: 130, priceUnit: 'm2' },
  { id: 'board:veneer-oak-18',     kind: 'board', name: 'פורניר אלון 18', t: 18, color: 0xd1a86e, finish: 'wood', grainMm: 700, sheet: [2500, 1250], price: 320, priceUnit: 'm2' },
  { id: 'board:veneer-walnut-18',  kind: 'board', name: 'פורניר אגוז 18', t: 18, color: 0x6e4b33, finish: 'wood', grainMm: 700, sheet: [2500, 1250], price: 380, priceUnit: 'm2' },
  { id: 'board:veneer-ash-18',     kind: 'board', name: 'פורניר מייפל 18', t: 18, color: 0xe4cfae, finish: 'wood', grainMm: 800, sheet: [2500, 1250], price: 340, priceUnit: 'm2' },
  { id: 'board:mdf-paint-18',      kind: 'board', name: 'MDF לצבע בתנור 18', t: 18, color: 0xeeeae2, finish: 'paint', sheet: [2800, 2070], price: 260, priceUnit: 'm2' },
  { id: 'board:mdf-paint-22',      kind: 'board', name: 'MDF לצבע בתנור 22', t: 22, color: 0xeeeae2, finish: 'paint', sheet: [2800, 2070], price: 290, priceUnit: 'm2' },
  { id: 'board:mdf-paint-sage',    kind: 'board', name: 'MDF צבע בתנור — ירוק מרווה', t: 18, color: 0x9fae95, finish: 'paint', sheet: [2800, 2070], price: 260, priceUnit: 'm2' },
  { id: 'board:mdf-paint-navy',    kind: 'board', name: 'MDF צבע בתנור — כחול כהה', t: 18, color: 0x34405a, finish: 'paint', sheet: [2800, 2070], price: 260, priceUnit: 'm2' },
  { id: 'board:back-hdf-6',        kind: 'board', name: 'גב HDF 6', t: 6, color: 0xd9cdb8, finish: 'paint', sheet: [2800, 2070], price: 40, priceUnit: 'm2', back: true },
  { id: 'board:back-mdf-8',        kind: 'board', name: 'גב MDF 8', t: 8, color: 0xdcd2bf, finish: 'paint', sheet: [2800, 2070], price: 55, priceUnit: 'm2', back: true },
  { id: 'board:solid-oak',         kind: 'board', name: 'עץ מלא אלון (רגליים, מסגרות)', t: 40, color: 0xc8a26a, finish: 'wood', grainMm: 500, price: 900, priceUnit: 'm2', solid: true },
  { id: 'board:solid-beech',       kind: 'board', name: 'עץ מלא אשור', t: 40, color: 0xe0c49c, finish: 'wood', grainMm: 500, price: 700, priceUnit: 'm2', solid: true },
  { id: 'board:countertop-38',     kind: 'board', name: 'משטח עבודה למינציה 38', t: 38, color: 0x6b6560, finish: 'paint', sheet: [4100, 600], price: 350, priceUnit: 'm2', top: true },
  { id: 'board:countertop-quartz', kind: 'board', name: 'משטח קוורץ 20', t: 20, color: 0xe9e6e0, finish: 'paint', sheet: [3000, 1400], price: 1600, priceUnit: 'm2', top: true },
  { id: 'edge:pvc-1',              kind: 'edge', name: 'קנט PVC 1 מ"מ', t: 1, price: 4, priceUnit: 'm' },
  { id: 'edge:pvc-2',              kind: 'edge', name: 'קנט PVC 2 מ"מ', t: 2, price: 6, priceUnit: 'm' },
  { id: 'edge:veneer-0.5',         kind: 'edge', name: 'קנט פורניר 0.5', t: 0.5, price: 7, priceUnit: 'm' },
  { id: 'glass:clear-4',           kind: 'glass', name: 'זכוכית שקופה 4', t: 4, color: 0xbfe0ea, finish: 'glass', opacity: 0.3, price: 180, priceUnit: 'm2' },
  { id: 'glass:frosted-4',         kind: 'glass', name: 'זכוכית חלבית 4', t: 4, color: 0xe9eff0, finish: 'glass', opacity: 0.75, price: 220, priceUnit: 'm2' },
  { id: 'glass:smoked-4',          kind: 'glass', name: 'זכוכית מעושנת 4', t: 4, color: 0x5a5e62, finish: 'glass', opacity: 0.5, price: 240, priceUnit: 'm2' },
  // אביזרים: פרזול עם מתכון תלת־ממדי (accessories.js). הנגר מוסיף ועורך במסך "אביזרים".
  { id: 'hw:hinge-110',            kind: 'hardware', name: 'ציר נסתר 110°', price: 12, priceUnit: 'unit', accessory: { type: 'concealed', params: { angle: 110 }, finish: 'nickel' } },
  { id: 'hw:hinge-165',            kind: 'hardware', name: 'ציר נסתר 165°', price: 22, priceUnit: 'unit', accessory: { type: 'concealed', params: { angle: 165 }, finish: 'nickel' } },
  { id: 'hw:hinge-soft',           kind: 'hardware', name: 'ציר נסתר 110° בטריקה שקטה', price: 24, priceUnit: 'unit', accessory: { type: 'concealed', params: { angle: 110 }, finish: 'nickel' } },
  { id: 'hw:hinge-glass',          kind: 'hardware', name: 'ציר לוויטרינה (כוס 26)', price: 28, priceUnit: 'unit', accessory: { type: 'concealed', params: { angle: 110, cup: 26 }, finish: 'chrome' } },
  { id: 'hw:hinge-butt',           kind: 'hardware', name: 'ציר פרפר פליז 60', price: 9, priceUnit: 'unit', accessory: { type: 'butt', params: { length: 60, width: 20 }, finish: 'brass' } },
  { id: 'hw:handle-bar-128',       kind: 'hardware', name: 'ידית מוט 128 ניקל', price: 25, priceUnit: 'unit', accessory: { type: 'bar', params: { length: 128 }, finish: 'nickel' } },
  { id: 'hw:handle-bar-160-black', kind: 'hardware', name: 'ידית מוט 160 שחור', price: 28, priceUnit: 'unit', accessory: { type: 'bar', params: { length: 160, diameter: 12 }, finish: 'black' } },
  { id: 'hw:handle-bar-320-brass', kind: 'hardware', name: 'ידית מוט 320 פליז', price: 55, priceUnit: 'unit', accessory: { type: 'bar', params: { length: 320, diameter: 12 }, finish: 'brass' } },
  { id: 'hw:handle-flat-160',      kind: 'hardware', name: 'ידית פס שטוח 160 שחור', price: 32, priceUnit: 'unit', accessory: { type: 'bar-flat', params: { length: 160 }, finish: 'black' } },
  { id: 'hw:handle-bow-96',        kind: 'hardware', name: 'ידית קשת 96 כרום', price: 22, priceUnit: 'unit', accessory: { type: 'bow', params: { length: 96 }, finish: 'chrome' } },
  { id: 'hw:handle-tbar',          kind: 'hardware', name: 'ידית T 60 ניקל', price: 18, priceUnit: 'unit', accessory: { type: 't-bar', params: { length: 60 }, finish: 'nickel' } },
  { id: 'hw:handle-edge',          kind: 'hardware', name: 'ידית פרופיל קצה 200 שחור', price: 30, priceUnit: 'unit', accessory: { type: 'edge-profile', params: { length: 200 }, finish: 'black' } },
  { id: 'hw:handle-shell',         kind: 'hardware', name: 'ידית קונכייה שקועה', price: 26, priceUnit: 'unit', accessory: { type: 'shell', params: {}, finish: 'nickel' } },
  { id: 'hw:handle-leather',       kind: 'hardware', name: 'ידית רצועת עור', price: 35, priceUnit: 'unit', accessory: { type: 'leather', params: {}, finish: 'bronze' } },
  { id: 'hw:handle-knob',          kind: 'hardware', name: 'כפתור כדור 28 ניקל', price: 15, priceUnit: 'unit', accessory: { type: 'knob-round', params: { diameter: 28 }, finish: 'nickel' } },
  { id: 'hw:knob-mushroom-brass',  kind: 'hardware', name: 'כפתור פטרייה 32 פליז', price: 19, priceUnit: 'unit', accessory: { type: 'knob-mushroom', params: { diameter: 32 }, finish: 'brass' } },
  { id: 'hw:knob-cylinder-black',  kind: 'hardware', name: 'כפתור גליל 20 שחור', price: 16, priceUnit: 'unit', accessory: { type: 'knob-cylinder', params: {}, finish: 'black' } },
  { id: 'hw:knob-square-wood',     kind: 'hardware', name: 'כפתור מרובע עץ', price: 14, priceUnit: 'unit', accessory: { type: 'knob-square', params: {}, finish: 'wood' } },
  { id: 'hw:knob-ring',            kind: 'hardware', name: 'כפתור טבעת ברונזה', price: 21, priceUnit: 'unit', accessory: { type: 'knob-ring', params: {}, finish: 'bronze' } },
  { id: 'hw:caster-swivel-50',     kind: 'hardware', name: 'גלגל מסתובב 50', price: 14, priceUnit: 'unit', accessory: { type: 'caster-swivel', params: { diameter: 50 }, finish: 'nickel' } },
  { id: 'hw:caster-brake-75',      kind: 'hardware', name: 'גלגל מסתובב 75 עם בלם', price: 24, priceUnit: 'unit', accessory: { type: 'caster-swivel', params: { diameter: 75, width: 25, brake: 1 }, finish: 'black' } },
  { id: 'hw:caster-fixed-50',      kind: 'hardware', name: 'גלגל קבוע 50', price: 11, priceUnit: 'unit', accessory: { type: 'caster-fixed', params: { diameter: 50 }, finish: 'nickel' } },
  { id: 'hw:caster-ball-30',       kind: 'hardware', name: 'גלגל כדורי 30', price: 9, priceUnit: 'unit', accessory: { type: 'ball-caster', params: {}, finish: 'chrome' } },
  { id: 'hw:shelf-pin',            kind: 'hardware', name: 'פין מדף', price: 0.5, priceUnit: 'unit' },
  { id: 'hw:slide-std',            kind: 'hardware', name: 'מסילות למגירה — רגילות (זוג)', price: 35, priceUnit: 'unit' },
  { id: 'hw:slide-tandem',         kind: 'hardware', name: 'מסילות טנדם בטריקה שקטה (זוג)', price: 120, priceUnit: 'unit' },
  { id: 'hw:rod-chrome',           kind: 'hardware', name: 'מוט תלייה כרום', price: 40, priceUnit: 'unit' },
  { id: 'hw:track-sliding',        kind: 'hardware', name: 'מסילה כפולה לדלתות הזזה', price: 250, priceUnit: 'unit' },
  { id: 'hw:leg-adjust',           kind: 'hardware', name: 'רגלית מתכווננת', price: 6, priceUnit: 'unit' },
  { id: 'hw:hinge-bifold',         kind: 'hardware', name: 'ציר קיפול לדלת פינה', price: 18, priceUnit: 'unit' },
  { id: 'hw:carousel',             kind: 'hardware', name: 'קרוסלה לארון פינתי', price: 650, priceUnit: 'unit' },
  { id: 'hw:led-strip',            kind: 'hardware', name: 'פס לד (כולל פרופיל ושנאי יחסי)', price: 90, priceUnit: 'm' },
];

export const KINDS = [
  { id: 'board', name: 'לוחות' },
  { id: 'edge', name: 'קנטים' },
  { id: 'glass', name: 'זכוכית' },
  { id: 'hardware', name: 'פרזול' },
];

export const FINISHES = [
  { id: 'wood', name: 'עץ (סיבים)' },
  { id: 'melamine', name: 'מלמין (סיבים עדינים)' },
  { id: 'paint', name: 'צבע / חלק' },
  { id: 'glass', name: 'זכוכית' },
];

// הספרייה החיה. מפה שומרת על סדר ההכנסה — הסדר שבו החומרים מוצגים.
const library = new Map();
let version = 0;   // עולה בכל שינוי; התצוגה משווה ומנקה את המטמון שלה
reset();

export function reset() {
  library.clear();
  for (const m of SEED) library.set(m.id, { ...m, active: m.active !== false, seed: true });
  version += 1;
}

/** טוען רשימה שנשמרה (דריסות ותוספות מעל הזריעה). */
export function load(list) {
  reset();
  for (const m of list || []) {
    const base = library.get(m.id) || {};
    library.set(m.id, { ...base, ...m, seed: !!base.seed });
  }
  version += 1;
}

/** מה ששונה מהזריעה — רק זה נשמר. */
export function diff() {
  const out = [];
  for (const m of library.values()) {
    const s = SEED.find((x) => x.id === m.id);
    if (!s) { out.push(stripImage(m)); continue; }
    const changed = {};
    for (const k of Object.keys(m)) {
      if (k === 'seed' || k === 'image') continue;
      if (JSON.stringify(m[k]) !== JSON.stringify(k === 'active' ? (s.active !== false) : s[k])) changed[k] = m[k];
    }
    if (Object.keys(changed).length) out.push({ id: m.id, ...changed });
  }
  return out;
}
// התמונות נשמרות בנפרד (גדולות) — ראו store.js.
function stripImage(m) { const { image, seed, ...rest } = m; return rest; }

export function upsert(m) {
  const base = library.get(m.id) || { seed: false, active: true };
  library.set(m.id, { ...base, ...m });
  version += 1;
}
export function remove(id) {
  const m = library.get(id);
  if (!m) return;
  if (m.seed) upsert({ id, active: false });   // חומר מהזריעה רק מושבת, לא נעלם
  else { library.delete(id); version += 1; }
}
export function setImage(id, image, imageMm) {
  const m = library.get(id);
  if (!m) return;
  m.image = image || undefined;
  if (imageMm) m.imageMm = imageMm;
  version += 1;
}

export function libraryVersion() { return version; }
export function all() { return [...library.values()]; }

/** החומרים הפעילים מסוג נתון, לרשימות בחירה. `back` מסנן לוחות גב (true / false / undefined = הכול). */
export function materialsOfKind(kind, { back, solid, top } = {}) {
  return all().filter((m) => m.kind === kind && m.active
    && (back === undefined || !!m.back === back)
    && (solid === undefined || !!m.solid === solid)
    && (top === undefined || !!m.top === top));
}

/** אביזרים לפי תפקיד: 'handle' (ידיות וכפתורים), 'hinge', 'wheel'. */
export function materialsOfRole(role) {
  const kinds = role === 'handle' ? ['handle', 'knob'] : [role];
  return all().filter((m) => m.kind === 'hardware' && m.active && m.accessory && kinds.includes(accessoryKind(m.accessory.type)));
}
const ACCESSORY_KINDS = { bar: 'handle', 'bar-flat': 'handle', bow: 'handle', 't-bar': 'handle', 'edge-profile': 'handle', shell: 'handle', leather: 'handle', 'knob-round': 'knob', 'knob-mushroom': 'knob', 'knob-cylinder': 'knob', 'knob-square': 'knob', 'knob-ring': 'knob', concealed: 'hinge', butt: 'hinge', pivot: 'hinge', 'caster-swivel': 'wheel', 'caster-fixed': 'wheel', 'ball-caster': 'wheel' };
export const accessoryKind = (type) => ACCESSORY_KINDS[type] || null;

export function material(id) {
  return library.get(id) || { id, kind: 'unknown', name: id, color: 0xff00ff, finish: 'paint' };
}

/** מזהה חדש מתוך שם: אותיות לטיניות וספרות בלבד, עם קידומת הסוג. */
export function newId(kind, name) {
  const slug = String(name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'custom';
  let id = `${kind}:${slug}`;
  let n = 2;
  while (library.has(id)) id = `${kind}:${slug}-${n++}`;
  return id;
}
