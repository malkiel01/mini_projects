// הצמדה — החשבון ב-ui-core/snap.js (קצוות ומרכזים, רשת). כאן רק מה שתלוי במודל של וקטורי: אילו תיבות הן יעדים.

import { objects, layout, worldBox } from './model.js';

export const GRID = 10; // pt

/** התיבות שאליהן נצמדים, במרחב העבודה: כל לוח, וכל אובייקט גלוי שאינו בבחירה. */
export function snapBoxes(doc, exclude) {
  const boxes = [];
  const lay = layout(doc);
  for (const s of doc.surfaces) { const [ox, oy] = lay.get(s.id); boxes.push([ox, oy, s.size[0], s.size[1]]); }
  for (const { path, layer, surface } of objects(doc)) {
    if (exclude.has(path.id) || layer.visible === false || path.hidden) continue;
    const b = worldBox(path, lay.get(surface.id));
    if (b) boxes.push(b);
  }
  return boxes;
}
