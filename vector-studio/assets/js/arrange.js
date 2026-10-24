// סידור: קבוצות, סדר הצגה, יישור ופיזור, ושכבות. פעולות טהורות על המסמך (בלי DOM) — נבדקות ב-node.

import { findPath, layout, worldBox, applyMatrix, ids, isGroup } from './model.js';
import { unionBox } from './geometry/path.js';
import { translate } from './geometry/matrix.js';

/** הפריטים שנבחרו לפי סדר ההצגה (מלמטה למעלה), עם השכבה והמיקום שלהם. */
function picked(doc, sel) {
  const out = [];
  for (const s of doc.surfaces) for (const l of s.layers || []) {
    if (!Array.isArray(l.paths)) continue;
    l.paths.forEach((p, i) => { if (sel.has(p.id)) out.push({ item: p, layer: l, surface: s, index: i }); });
  }
  return out;
}

/**
 * קיבוץ: הפריטים נכנסים לקבוצה אחת, במקום של העליון שבהם. פריטים משכבות או לוחות שונים — עוברים לשכבה
 * של העליון. מחזיר את מזהה הקבוצה, או null אם נבחר פחות משניים.
 */
export function group(doc, sel) {
  const items = picked(doc, sel);
  if (items.length < 2) return null;
  const top = items[items.length - 1];
  const id = ids(doc, 'g')();
  const g = { id, type: 'group', name: 'קבוצה', children: items.map(x => x.item) };
  const lay = layout(doc);
  // פריט מלוח אחר — שומרים על המקום שלו במרחב העבודה
  for (const x of items) {
    if (x.surface === top.surface) continue;
    const [ax, ay] = lay.get(x.surface.id), [bx, by] = lay.get(top.surface.id);
    applyMatrix(x.item, translate(ax - bx, ay - by));
  }
  let at = top.index;
  for (const x of items) {
    if (x.layer === top.layer && x.index < top.index) at--;
    x.layer.paths = x.layer.paths.filter(p => p !== x.item);
  }
  top.layer.paths.splice(at, 0, g);
  return id;
}

/** פירוק קבוצות: הילדים חוזרים לשכבה במקום הקבוצה. מחזיר את מזהי הילדים. */
export function ungroup(doc, sel) {
  const out = [];
  for (const x of picked(doc, sel)) {
    if (!isGroup(x.item)) { out.push(x.item.id); continue; }
    const i = x.layer.paths.indexOf(x.item);
    x.layer.paths.splice(i, 1, ...(x.item.children || []));
    out.push(...(x.item.children || []).map(c => c.id));
  }
  return out;
}

/**
 * סדר הצגה בתוך השכבה: 'front' / 'back' — לקצה; 'forward' / 'backward' — צעד אחד, מעל / מתחת לפריט
 * השכן שאינו נבחר.
 */
export function reorder(doc, sel, dir) {
  for (const s of doc.surfaces) for (const l of s.layers || []) {
    if (!Array.isArray(l.paths) || !l.paths.some(p => sel.has(p.id))) continue;
    const on = l.paths.filter(p => sel.has(p.id)), off = l.paths.filter(p => !sel.has(p.id));
    if (dir === 'front') { l.paths = [...off, ...on]; continue; }
    if (dir === 'back') { l.paths = [...on, ...off]; continue; }
    const a = l.paths.slice();
    if (dir === 'forward') {
      for (let i = a.length - 2; i >= 0; i--) if (sel.has(a[i].id) && !sel.has(a[i + 1].id)) [a[i], a[i + 1]] = [a[i + 1], a[i]];
    } else {
      for (let i = 1; i < a.length; i++) if (sel.has(a[i].id) && !sel.has(a[i - 1].id)) [a[i], a[i - 1]] = [a[i - 1], a[i]];
    }
    l.paths = a;
  }
}

/**
 * יישור: 'left' | 'hcenter' | 'right' | 'top' | 'vcenter' | 'bottom'. פריט אחד — ללוח שלו; כמה — לתיבה
 * של כולם. (ימין ושמאל — של הדף, לא של כיוון הקריאה.)
 */
export function align(doc, sel, how) {
  const items = [...sel].map(id => findPath(doc, id)).filter(Boolean);
  if (!items.length) return;
  const lay = layout(doc);
  const boxes = items.map(o => worldBox(o.path, lay.get(o.surface.id)));
  let ref;
  if (items.length === 1) { const s = items[0].surface; const [ox, oy] = lay.get(s.id); ref = [ox, oy, s.size[0], s.size[1]]; }
  else ref = unionBox(boxes);
  items.forEach((o, i) => {
    const b = boxes[i];
    if (!b) return;
    let dx = 0, dy = 0;
    if (how === 'left') dx = ref[0] - b[0];
    if (how === 'right') dx = ref[0] + ref[2] - (b[0] + b[2]);
    if (how === 'hcenter') dx = ref[0] + ref[2] / 2 - (b[0] + b[2] / 2);
    if (how === 'top') dy = ref[1] - b[1];
    if (how === 'bottom') dy = ref[1] + ref[3] - (b[1] + b[3]);
    if (how === 'vcenter') dy = ref[1] + ref[3] / 2 - (b[1] + b[3] / 2);
    if (dx || dy) applyMatrix(o.path, translate(dx, dy));
  });
}

/** פיזור: רווחים שווים בין הפריטים ('h' / 'v'). צריך שלושה לפחות; הקיצוניים לא זזים. */
export function distribute(doc, sel, axis) {
  const lay = layout(doc);
  const items = [...sel].map(id => findPath(doc, id)).filter(Boolean)
    .map(o => ({ o, b: worldBox(o.path, lay.get(o.surface.id)) })).filter(x => x.b);
  if (items.length < 3) return false;
  const k = axis === 'h' ? 0 : 1;
  items.sort((a, b) => a.b[k] - b.b[k]);
  const first = items[0].b, last = items[items.length - 1].b;
  const total = items.reduce((n, x) => n + x.b[k + 2], 0);
  const gap = (last[k] + last[k + 2] - first[k] - total) / (items.length - 1);
  let pos = first[k] + first[k + 2] + gap;
  for (const x of items.slice(1, -1)) {
    const d = pos - x.b[k];
    applyMatrix(x.o.path, k === 0 ? translate(d, 0) : translate(0, d));
    pos += x.b[k + 2] + gap;
  }
  return true;
}

// ---------- שכבות ----------

export function addLayer(doc, surface, name) {
  const l = { id: ids(doc, 'L')(), name: name || 'שכבה ' + ((surface.layers || []).length + 1), type: 'vector', opacity: 1, blend: 'normal', visible: true, locked: false, paths: [] };
  (surface.layers ||= []).push(l);
  return l;
}

/** מחיקת שכבה — רק אם נשארת עוד שכבה וקטורית. */
export function removeLayer(surface, layerId) {
  const vectors = surface.layers.filter(l => l.type === 'vector');
  if (vectors.length < 2) return false;
  surface.layers = surface.layers.filter(l => l.id !== layerId);
  return true;
}

/** הזזת שכבה למעלה (+1) או למטה (‎-1) בסדר ההצגה. */
export function moveLayer(surface, layerId, dir) {
  const a = surface.layers, i = a.findIndex(l => l.id === layerId), j = i + dir;
  if (i < 0 || j < 0 || j >= a.length) return;
  [a[i], a[j]] = [a[j], a[i]];
}

/** העברת הפריטים שנבחרו לשכבה אחרת (בראש שלה), בלי לשנות את הסדר ביניהם. */
export function moveToLayer(doc, sel, layerId) {
  let target = null, targetSurface = null;
  for (const s of doc.surfaces) for (const l of s.layers || []) if (l.id === layerId) { target = l; targetSurface = s; }
  if (!target) return;
  const lay = layout(doc);
  for (const x of picked(doc, sel)) {
    if (x.layer === target) continue;
    if (x.surface !== targetSurface) {
      const [ax, ay] = lay.get(x.surface.id), [bx, by] = lay.get(targetSurface.id);
      applyMatrix(x.item, translate(ax - bx, ay - by));
    }
    x.layer.paths = x.layer.paths.filter(p => p !== x.item);
    target.paths.push(x.item);
  }
}

