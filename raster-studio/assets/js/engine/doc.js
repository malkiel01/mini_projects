// מודל המסמך בזיכרון — לפי mbe-format.md: משטח אחד (canvas) בפיקסלים, ושכבות raster עם
// opacity / blend / visible / locked. הפיקסלים עצמם — באריחים שהרנדרר מחזיק (layer.tiles).

export const TILE = 256;
export const BLEND_MODES = ['normal', 'multiply', 'screen', 'overlay'];

let seq = 0;
const newId = prefix => prefix + Date.now().toString(36) + (seq++).toString(36);

export class RasterDocument {
  constructor(width, height, title = '') {
    if (!(Number.isInteger(width) && Number.isInteger(height) && width > 0 && height > 0)) {
      throw new RangeError('bad_size');
    }
    this.id = newId('s');
    this.width = width;
    this.height = height;
    this.title = title;
    /** מלמטה למעלה: layers[0] הוא הרקע. */
    this.layers = [];
  }

  get tilesX() { return Math.ceil(this.width / TILE); }
  get tilesY() { return Math.ceil(this.height / TILE); }

  addLayer(props = {}) {
    const layer = {
      id: newId('L'),
      name: props.name || 'שכבה ' + (this.layers.length + 1),
      type: 'raster',
      opacity: props.opacity ?? 1,
      blend: props.blend ?? 'normal',
      visible: props.visible ?? true,
      locked: props.locked ?? false,
      /** "tx,ty" → אריח של הרנדרר. אריח חסר = שקוף. מותר גם מחוץ לקנבס — התוכן נשמר להזזה. */
      tiles: new Map(),
    };
    this.layers.splice(props.index ?? this.layers.length, 0, layer);
    return layer;
  }
}

/** האריחים שמלבן (במסמך) נוגע בהם — [tx, ty] לכל אחד. */
export function tilesInRect(x, y, w, h) {
  const out = [];
  if (w <= 0 || h <= 0) return out;
  const tx0 = Math.floor(x / TILE), ty0 = Math.floor(y / TILE);
  const tx1 = Math.floor((x + w - 1) / TILE), ty1 = Math.floor((y + h - 1) / TILE);
  for (let ty = ty0; ty <= ty1; ty++) for (let tx = tx0; tx <= tx1; tx++) out.push([tx, ty]);
  return out;
}
