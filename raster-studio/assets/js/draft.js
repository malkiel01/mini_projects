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
  const walk = async list => {
    const out = [];
    for (const l of list) {
      // mbe — ה-JSON המקורי מ-‎.mbe (שדות שלא הבנו נשמרים); foreign — שכבה של אפליקציה אחרת, בלי פיקסלים
      const props = { id: l.id, name: l.name, opacity: l.opacity, blend: l.blend, visible: l.visible, locked: l.locked, mbe: l.mbe ?? null };
      if (l.type === 'group') { out.push({ ...props, type: 'group', collapsed: !!l.collapsed, children: await walk(l.children) }); continue; }
      if (l.foreign) { out.push({ ...props, foreign: l.foreign }); continue; }
      let c = cache.get(l.id);
      if (!c || c.rev !== (l.rev ?? 0) || c.w !== doc.width || c.h !== doc.height) {
        c = { rev: l.rev ?? 0, w: doc.width, h: doc.height, blob: new Blob([await layerPNG(renderer, doc, l)], { type: 'image/png' }) };
        cache.set(l.id, c);
      }
      out.push({ ...props, png: c.blob });
    }
    return out;
  };
  const layers = await walk(doc.layers);
  await idbSet(draftKey(doc), {
    v: 1, title: doc.title, width: doc.width, height: doc.height, serverId: doc.serverId ?? null, serverVersion: doc.serverVersion ?? 0,
    activeId: doc.activeId, layers, savedAt: new Date().toISOString(),
    // הקובץ שנפתח — הבסיס לשמירה הבאה (כל מה שלא הבנו עובר ממנו)
    mbe: doc.mbe ? { bytes: doc.mbe.bytes, surfaceId: doc.mbe.surfaceId } : null,
  });
}

export const loadDraft = key => idbGet(key).catch(() => null);
export const clearDraft = key => idbDel(key).catch(() => {});

/** טיוטה → מסמך עם השכבות (בציור דרך הרנדרר) */
export async function restoreDraft(renderer, d) {
  const doc = new RasterDocument(d.width, d.height, d.title);
  doc.serverId = d.serverId ?? undefined;
  doc.serverVersion = d.serverVersion ?? 0;
  if (d.mbe) doc.mbe = { bytes: d.mbe.bytes, surfaceId: d.mbe.surfaceId };
  const walk = async (list, parent) => {
    for (const l of list) {
      const props = { name: l.name, opacity: l.opacity, blend: l.blend, visible: l.visible, locked: l.locked, parent };
      if (l.type === 'group') {
        const g = doc.addGroup(props);
        g.id = l.id;
        g.collapsed = !!l.collapsed;
        if (l.mbe) g.mbe = l.mbe;
        await walk(l.children, g);
        continue;
      }
      const layer = doc.addLayer(props);
      layer.id = l.id;
      if (l.mbe) layer.mbe = l.mbe;
      if (l.foreign) { layer.foreign = l.foreign; continue; }
      const bmp = await decodeImage(l.png);
      await renderer.drawImage(doc, layer, bmp, 0, 0);
      bmp.close();
      cache.set(layer.id, { rev: layer.rev ?? 0, w: d.width, h: d.height, blob: l.png });
    }
  };
  await walk(d.layers, undefined);
  doc.activeId = doc.layer(d.activeId) ? d.activeId : doc.layers.at(-1)?.id;
  return doc;
}
