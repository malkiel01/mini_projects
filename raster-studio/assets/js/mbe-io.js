// ‎.mbe — שמירה ופתיחה דרך הליבה (assets/js/mbe — עותק מסונכרן; לא עורכים אותו).
//
// שמירה: כל שכבת רסטר → PNG בגודל הקנבס ב-media/ (לפי הגיבוב — שכבה שלא השתנתה נשמרת באותו שם), document.json
// עם עץ השכבות, והייצוגים: preview.png (עד 1024px), image.png (משוטחת) ו-image.ora (שכבות). מצב התצוגה —
// ב-apps/raster-studio/view.json.
// קובץ שנפתח נשמר כבסיס (doc.mbe.bytes): כל מה שלא הבנו — שדות לא מוכרים, משטחים אחרים, שכבות של אפליקציות
// אחרות, apps/ של אחרים וחלקים לא מוכרים — עובר בית-לבית. משאב שרק השכבות שלנו הפנו אליו ושכבר לא בשימוש — נמחק.

import { open, create, save, PREVIEW } from './mbe/mbe.js';
import { encodePNG } from './engine/png.js';
import { RasterDocument } from './engine/doc.js';
import { decodeImage } from './engine/webgl2.js';
import { mbeDocument, mediaRefs, canvasSurface, gapsFor } from './engine/formats.js';
import { flatRGBA, layerPNG, scaledPNG, buildORA } from './export.js';

export const APP_ID = 'raster-studio';
export const APP_VERSION = '1';
const VIEW = 'view.json';

const png = new Map();   // layer.id → { rev, w, h, bytes } — שכבה שלא השתנתה לא מקודדת מחדש

async function layerBytes(renderer, doc, l) {
  const c = png.get(l.id);
  if (c && c.rev === (l.rev ?? 0) && c.w === doc.width && c.h === doc.height) return c.bytes;
  const bytes = await layerPNG(renderer, doc, l);
  png.set(l.id, { rev: l.rev ?? 0, w: doc.width, h: doc.height, bytes });
  return bytes;
}

/**
 * ‎.mbe מהמסמך. view — { zoom, x, y, tool, color } (נשמר ב-apps/raster-studio). lite — preview בלבד.
 * מחזיר את הבתים; doc.mbe מתעדכן לבסיס החדש (כך שמירה נוספת שומרת את מה שכבר נשמר).
 */
export async function buildMbe(renderer, doc, { view = null, lite = false, now } = {}) {
  const base = doc.mbe ?? null;
  const f = base ? await open(base.bytes) : create('raster', { units: 'pt', color: { space: 'sRGB' }, surfaces: [] });
  if (base && !f.ok) throw new Error('mbe-base');
  const before = mediaRefs(f.document);

  const src = new Map();
  const walk = async list => {
    for (const l of list) {
      if (l.type === 'group') { await walk(l.children); continue; }
      if (l.foreign) continue;
      src.set(l, await f.addMedia(await layerBytes(renderer, doc, l), 'image/png'));
    }
  };
  await walk(doc.layers);
  f.document = mbeDocument(doc, doc.layers, base && { document: f.document, surfaceId: base.surfaceId }, l => src.get(l));

  // משאב שהשכבות הישנות הפנו אליו ואף אחד כבר לא — יוצא מהקובץ. משאב שאף אחד לא הפנה אליו גם קודם — לא שלנו לגעת
  const after = mediaRefs(f.document);
  for (const p of before) if (p.startsWith('media/') && !after.has(p)) f.files.delete(p);

  const flat = await flatRGBA(renderer, doc);
  f.setRendition(PREVIEW, await scaledPNG(flat, doc.width, doc.height, 1024), { gaps: gapsFor('png', doc.layers) });
  if (lite) {
    f.removeRendition('renditions/image.png');
    f.removeRendition('renditions/image.ora');
  } else {
    f.setRendition('renditions/image.png', await encodePNG(flat, doc.width, doc.height), { gaps: gapsFor('png', doc.layers) });
    f.setRendition('renditions/image.ora', await buildORA(renderer, doc), { type: 'image/openraster', gaps: gapsFor('ora', doc.layers) });
  }
  if (view) f.setApp(APP_ID, VIEW, new TextEncoder().encode(JSON.stringify({ v: 1, ...view })));

  const bytes = await save(f, { app: APP_ID, appVersion: APP_VERSION, ...(now ? { now } : {}) });
  doc.mbe = { bytes, surfaceId: canvasSurface(f.document).id };
  return bytes;
}

const KIND_APP = { text: 'עורך התמלילים', layout: 'העימוד', vector: 'וקטורי' };

/** שגיאה עם הבעיות מהליבה (לתצוגה למשתמש) */
export class MbeOpenError extends Error {
  constructor(code, detail = '', issues = []) { super(code); this.detail = detail; this.issues = issues; }
}

/**
 * פותח ‎.mbe למסמך: הקנבס הראשון, שכבות רסטר וקבוצות. שכבה מסוג אחר (vector, frame…) נשמרת כמו שהיא
 * (layer.foreign), נעולה, ולא מצוירת. מחזיר { doc, view, warnings }.
 */
export async function openMbe(renderer, bytes, title = '') {
  bytes = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  const f = await open(bytes);
  const errors = f.issues.filter(i => i.level === 'error');
  if (errors.length) throw new MbeOpenError('mbe-invalid', errors.map(e => e.message).join(' · '), errors);
  const surface = canvasSurface(f.document);
  if (!surface) {
    const app = KIND_APP[f.kind];
    throw new MbeOpenError('mbe-not-raster', app ? `הקובץ הוא מסמך מסוג "${f.kind}" — פותחים אותו ב${app}` : '');
  }
  const [w, h] = surface.size.map(v => Math.max(1, Math.round(v)));
  const d = new RasterDocument(w, h, title || 'תמונה');
  const walk = async (list, parent) => {
    for (const raw of list ?? []) {
      if (!raw || typeof raw !== 'object') continue;
      const props = {
        name: typeof raw.name === 'string' && raw.name ? raw.name : raw.type === 'group' ? undefined : 'שכבה',
        opacity: typeof raw.opacity === 'number' ? raw.opacity : 1,
        blend: typeof raw.blend === 'string' ? raw.blend : 'normal',
        visible: raw.visible !== false, locked: !!raw.locked, parent,
      };
      if (raw.type === 'group') {
        const g = d.addGroup(props);
        if (typeof raw.id === 'string') g.id = raw.id;
        g.mbe = raw;
        await walk(raw.children, g);
        continue;
      }
      const layer = d.addLayer(props);
      if (typeof raw.id === 'string') layer.id = raw.id;
      if (raw.type === 'raster' && typeof raw.src === 'string') {
        layer.mbe = raw;
        const data = f.getMedia(raw.src);
        if (data) {
          const bmp = await decodeImage(new Blob([data], { type: 'image/png' }));
          await renderer.drawImage(d, layer, bmp, 0, 0);
          bmp.close();
        }
      } else {
        // של אפליקציה אחרת — נשמרת בית-לבית, לא נערכת כאן
        layer.foreign = raw;
        layer.locked = true;
        layer.name = `${props.name === 'שכבה' ? raw.type : props.name} (${raw.type})`;
      }
    }
  };
  await walk(surface.layers, undefined);
  if (!d.layers.length) d.addLayer({ name: 'רקע' });
  d.activeId = d.rasters().filter(l => !l.foreign).at(-1)?.id ?? d.layers.at(-1).id;
  d.mbe = { bytes, surfaceId: surface.id };
  let view = null;
  try { view = JSON.parse(new TextDecoder().decode(f.apps(APP_ID).get(VIEW))); } catch { /* אין / פגום — לא נורא */ }
  return { doc: d, view, warnings: f.issues.filter(i => i.level === 'warning') };
}

/** הבתים הראשונים של הקובץ: ‎.mbe / ORA / אחר (לפי ה-mimetype בתחילת ה-ZIP, לא לפי הסיומת) */
export async function sniffZip(blob) {
  const head = new TextDecoder('latin1').decode(new Uint8Array(await blob.slice(0, 80).arrayBuffer()));
  if (!head.startsWith('PK')) return null;
  if (head.includes('mimetypeapplication/vnd.mbe+zip')) return 'mbe';
  if (head.includes('mimetypeimage/openraster')) return 'ora';
  return null;
}
