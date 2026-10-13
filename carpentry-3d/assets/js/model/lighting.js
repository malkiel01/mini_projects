// תאורת לד (README, "תאורה"): לכל קומה — איפה (מלמעלה / מתחת למדפים / בדפנות /
// גב עקיף, ושילובים), ולכל המוצר — איזה פס, איזה פרופיל ואיך שולטים. המודול
// מחשב את הפסים והפרופילים לפי מטר, החריצים לפרופיל שקוע (הערה על הלוח),
// את ההספק ואת השנאי המתאים (עם 20% מרווח), את המתגים והחיישנים, וחור לכבל.
//
// פס "כולל פרופיל ושנאי" (hw:led-strip, led.bundle) — ההתנהגות הישנה, בלי שורות
// נפרדות; units-engine בונה אותו בקוד הקודם, כך שפרויקטים קיימים לא משתנים.

import { material, materialsOfRole } from './materials.js';

/** מיקומי התאורה מתוך ערך כמו 'top+shelves'. */
export const ledPlaces = (x) => new Set(String(x || 'none').split('+').filter((p) => ['top', 'shelves', 'sides', 'back'].includes(p)));
export const LED_OPTIONS = [
  { id: 'none', name: 'ללא' }, { id: 'top', name: 'מלמעלה (מתחת לתקרת הקומה)' }, { id: 'shelves', name: 'מתחת לכל מדף' },
  { id: 'top+shelves', name: 'מלמעלה ומתחת לכל מדף' }, { id: 'sides', name: 'אנכי בדפנות' }, { id: 'top+sides', name: 'מלמעלה ובדפנות' },
  { id: 'back', name: 'גב עקיף (בפינות האחוריות)' }, { id: 'top+back', name: 'מלמעלה וגב עקיף' },
];
export const isBundle = (stripId) => !stripId || !!material(stripId).led?.bundle;

/** מצב התאורה לבנייה אחת — מצטבר מכל הקומות, ונסגר ב-finishLighting. */
export function lightingState(stripId, profileId) {
  const strip = material(stripId), profile = profileId && profileId !== 'none' ? material(profileId) : null;
  return { stripId, strip: strip.led || {}, profileId: profile ? profileId : null, groove: profile?.led?.groove || null, meters: 0, lit: [] };
}

const m = (mm) => Math.round(mm / 100) / 10;   // מטרים, בעשירית
const addNote = (p, text) => { if (p && !(p.note || '').includes(text)) p.note = p.note ? `${p.note}; ${text}` : text; };

/**
 * תאורה לקומה אחת. `places` — Set של מיקומים; `col` — רוחב העמודה; a..top — פנים
 * הקומה; `ceiling` — הלוח שמעל הקומה (גג / מדף קבוע); `shelves` — המדפים שבקומה;
 * `boards` — { L, R } הדופן/המחיצה משני הצדדים.
 */
export function lightFloor(ctx, st, { i, j, nFloors, col, a, top, z0, z1, places, ceiling, shelves: shelfParts, boards, adjustable, glassShelves, door }) {
  const tag = nFloors > 1 ? `-f${j + 1}` : '';
  const where = `עמודה ${i + 1}${nFloors > 1 ? `, קומה ${j + 1}` : ''}`;
  const strips = [];
  const strip = (id, len, extra, board, grooveAt) => {
    strips.push({ id, len, ...extra });
    if (st.groove && board) addNote(board, `חריץ לפרופיל לד שקוע ${st.groove.w}×${st.groove.d} מ"מ ${grooveAt}, 30 מ"מ מהחזית`);
  };
  const w = col.x1 - col.x0;
  if (places.has('top')) strip(`led-${i + 1}${tag}-top`, w, { pos: [col.x0, top - 4, z1 - 30], horizontal: true, note: `${where}, מלמעלה` }, ceiling, 'בפאה התחתונה');
  if (places.has('shelves')) {
    for (const p of shelfParts) strip(`led-${p.id}`, p.box.w, { pos: [p.box.x, p.box.y - 4, z1 - 30], horizontal: true, note: `מתחת ל${p.name}` }, p, 'בפאה התחתונה');
    if (adjustable && shelfParts.length) ctx.warnings.push(`${where}: לד מתחת למדפים מתכווננים — החוט מגביל את הזזת המדף; עדיף מדפים קבועים או לד בדפנות`);
    if (glassShelves) ctx.warnings.push(`${where}: מדפי זכוכית — לד מתחתיהם נראה דרכם; עדיף לד מלמעלה או בדפנות`);
  }
  if (places.has('sides')) for (const [k, x, b] of [['L', col.x0, boards.L], ['R', col.x1 - 6, boards.R]]) strip(`led-${i + 1}${tag}-${k}`, top - a, { pos: [x, a, z1 - 30], note: `${where}, ${k === 'L' ? 'שמאל' : 'ימין'}` }, b, 'בפאה הפנימית, לאורך הקומה');
  if (places.has('back')) for (const [k, x] of [['BL', col.x0 + 4], ['BR', col.x1 - 10]]) strip(`led-${i + 1}${tag}-${k}`, top - a, { pos: [x, a, z0 + 6], note: `${where}, גב עקיף ${k === 'BL' ? 'שמאל' : 'ימין'}` }, null, '');
  for (const s of strips) {
    ctx.hardware.push({ id: s.id, kind: 'led', material: st.stripId, qty: m(s.len), pos: s.pos, len: s.len, horizontal: !!s.horizontal, note: s.note, kelvin: st.strip.k });
    if (st.profileId) ctx.hardware.push({ id: `${s.id}-profile`, kind: 'misc', material: st.profileId, qty: m(s.len), note: s.note });
    st.meters += s.len / 1000;
  }
  if (strips.length) st.lit.push({ i, j, door, where });
}

/**
 * סוגר את התאורה: שנאי לפי ההספק (+20%), שליטה לפי `control`, חור לכבל בגב (או
 * בגג) של כל עמודה מוארת, ואזהרות. 'auto' — חיישן דלת לכל קומה מוארת עם דלת, ומתג
 * מגע אחד לכל השאר.
 */
export function finishLighting(ctx, st, { control = 'auto', holeBoard } = {}) {
  if (!st.lit.length || !st.strip.v) return null;
  const watts = st.meters * (st.strip.w || 0);
  const need = watts * 1.2;
  const drivers = materialsOfRole('led-driver').filter((d) => d.led.v === st.strip.v).sort((x, y) => x.led.w - y.led.w);
  let driver = null, qty = 1;
  if (drivers.length) {
    driver = drivers.find((d) => d.led.w >= need) || drivers[drivers.length - 1];
    qty = Math.max(1, Math.ceil(need / driver.led.w));
    ctx.hardware.push({ id: 'led-driver', kind: 'misc', material: driver.id, qty, note: `${Math.round(watts)}W לפסים, ${st.strip.v}V, +20% מרווח` });
    if (qty > 1) ctx.warnings.push(`תאורה: ${Math.round(watts)}W — ${qty} שנאים; לתכנן מקום לכולם ופיצול הפסים ביניהם`);
  } else ctx.warnings.push(`תאורה: אין בספרייה שנאי ${st.strip.v}V פעיל`);
  const byMode = (mode) => materialsOfRole('led-control').find((c) => c.led.mode === mode);
  const doors = st.lit.filter((x) => x.door), open = st.lit.filter((x) => !x.door);
  const push = (id, mode, n, note) => { const c = byMode(mode); if (c && n > 0) ctx.hardware.push({ id, kind: 'misc', material: c.id, qty: n, note }); };
  if (control === 'auto') {
    push('led-door', 'door', doors.length, `קומות עם דלת: ${doors.map((x) => x.where).join(' · ')}`);
    push('led-touch', 'touch', open.length ? 1 : 0, 'לקומות הפתוחות');
  } else {
    push(`led-${control}`, control, control === 'door' ? doors.length : control === 'motion' ? st.lit.length : 1, 'שליטה בתאורה');
    if (control !== 'door' && doors.length) ctx.warnings.push(`תאורה מאחורי דלת (${doors.map((x) => x.where).join(' · ')}) — כדאי חיישן דלת שמדליק בפתיחה`);
    if (control === 'door' && open.length) ctx.warnings.push(`חיישן דלת — בקומות בלי דלת (${open.map((x) => x.where).join(' · ')}) התאורה לא תידלק`);
  }
  if (holeBoard) for (const i of new Set(st.lit.map((x) => x.i))) addNote(holeBoard(i), 'חור 10 מ"מ לכבל לד');
  return { watts, driver: driver?.id || null, qty };
}
