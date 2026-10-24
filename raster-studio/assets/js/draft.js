// טיוטה אוטומטית במכשיר (IndexedDB): כל שכבה כ-PNG + המאפיינים. נשמרת זמן קצר אחרי כל שינוי, ומיד כשהדף
// מוסתר (טלפון ברקע — שם הדפדפן נוטה לאפס את הגרפיקה). בפתיחה הבאה — משוחזרת.
// שכבה שלא השתנתה מאז הטיוטה הקודמת לא מקודדת מחדש (לפי layer.rev).

import { idbGet, idbSet, idbDel } from './idb.js';
import { layerPNG } from './export.js';
import { RasterDocument } from './engine/doc.js';
import { decodeImage } from './engine/webgl2.js';

const cache = new Map();   // layer.id → { rev, w, h, blob }

export const draftKey = doc => (doc.serverId ? 'draft:doc:' + doc.serverId : 'draft:local');

export async function saveDraft(renderer, doc) {
  const layers = [];
  for (const l of doc.layers) {
    let c = cache.get(l.id);
    if (!c || c.rev !== (l.rev ?? 0) || c.w !== doc.width || c.h !== doc.height) {
      c = { rev: l.rev ?? 0, w: doc.width, h: doc.height, blob: new Blob([await layerPNG(renderer, doc, l)], { type: 'image/png' }) };
      cache.set(l.id, c);
    }
    layers.push({ id: l.id, name: l.name, opacity: l.opacity, blend: l.blend, visible: l.visible, locked: l.locked, png: c.blob });
  }
  await idbSet(draftKey(doc), {
    v: 1, title: doc.title, width: doc.width, height: doc.height, serverId: doc.serverId ?? null,
    activeId: doc.activeId, layers, savedAt: new Date().toISOString(),
  });
}

export const loadDraft = key => idbGet(key).catch(() => null);
export const clearDraft = key => idbDel(key).catch(() => {});

/** טיוטה → מסמך עם השכבות (בציור דרך הרנדרר) */
export async function restoreDraft(renderer, d) {
  const doc = new RasterDocument(d.width, d.height, d.title);
  doc.serverId = d.serverId ?? undefined;
  for (const l of d.layers) {
    const layer = doc.addLayer({ name: l.name, opacity: l.opacity, blend: l.blend, visible: l.visible, locked: l.locked });
    layer.id = l.id;
    const bmp = await decodeImage(l.png);
    await renderer.drawImage(doc, layer, bmp, 0, 0);
    bmp.close();
    cache.set(layer.id, { rev: layer.rev ?? 0, w: d.width, h: d.height, blob: l.png });
  }
  doc.activeId = doc.layer(d.activeId) ? d.activeId : doc.layers.at(-1)?.id;
  return doc;
}
