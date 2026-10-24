// מודל המסמך בזיכרון — לפי mbe-format.md: משטח אחד (canvas) בפיקסלים, ועץ שכבות: raster ו-group (עם children),
// כל אחת עם opacity / blend / visible / locked. הפיקסלים עצמם — באריחים שהרנדרר מחזיק (layer.tiles).

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
    /** השכבה הפעילה — עליה פועלים הכלים והמאפיינים. */
    this.activeId = null;
  }

  // ---- העץ: שכבות וקבוצות. קבוצה = { type: 'group', children: [...] } (מלמטה למעלה, כמו layers) ----

  /** כל הפריטים בעץ, לעומק (קבוצה לפני הילדים שלה) */
  *walk(list = this.layers, depth = 0, parent = null) {
    for (const l of list) {
      yield { layer: l, depth, parent, list };
      if (l.type === 'group') yield* this.walk(l.children, depth + 1, l);
    }
  }
  /** כל שכבות הרסטר (בלי קבוצות), מלמטה למעלה */
  rasters() { return [...this.walk()].map(w => w.layer).filter(l => l.type !== 'group'); }
  layer(id) { for (const w of this.walk()) if (w.layer.id === id) return w.layer; return null; }
  /** המערך שהשכבה יושבת בו (layers או children של קבוצה) */
  listOf(layer) { for (const w of this.walk()) if (w.layer === layer) return w.list; return null; }
  parentOf(layer) { for (const w of this.walk()) if (w.layer === layer) return w.parent; return null; }
  /** המקום בתוך המערך שלה */
  indexOf(layer) { return this.listOf(layer)?.indexOf(layer) ?? -1; }
  /** הקבוצות שמעל השכבה, מהקרובה החוצה */
  ancestors(layer) {
    const out = [];
    for (let p = this.parentOf(layer); p; p = this.parentOf(p)) out.push(p);
    return out;
  }
  /** a בתוך b (או a הוא b)? — כדי שקבוצה לא תיכנס לתוך עצמה */
  contains(group, layer) {
    if (group === layer) return true;
    return group.type === 'group' && group.children.some(c => this.contains(c, layer));
  }

  get active() {
    return this.layer(this.activeId) ?? this.layers.at(-1) ?? null;
  }

  /** מוציא שכבה (או קבוצה) מהעץ. הפריט האחרון במסמך לא נמחק. הפעילה עוברת לשכנה. */
  removeLayer(layer) {
    const list = this.listOf(layer);
    if (!list || [...this.walk()].length <= 1 || (list === this.layers && this.layers.length <= 1)) return false;
    const parent = this.parentOf(layer);
    const i = list.indexOf(layer);
    list.splice(i, 1);
    if (this.activeId === layer.id || this.contains(layer, this.layer(this.activeId) ?? layer)) {
      this.activeId = (list[Math.min(i, list.length - 1)] ?? parent ?? this.layers.at(-1))?.id ?? null;
    }
    return true;
  }

  /** מעביר שכבה למקום to בתוך המערך שלה (0 = למטה). מחזיר אם זזה. */
  moveLayer(layer, to) {
    const list = this.listOf(layer);
    if (!list) return false;
    const i = list.indexOf(layer);
    to = Math.max(0, Math.min(list.length - 1, to));
    if (i === to) return false;
    list.splice(i, 1);
    list.splice(to, 0, layer);
    return true;
  }

  /** מעביר שכבה למערך אחר (לתוך קבוצה / החוצה) במקום index. קבוצה לא נכנסת לתוך עצמה. */
  reparent(layer, toList, index) {
    const from = this.listOf(layer);
    if (!from) return false;
    for (const w of this.walk()) if (w.layer.type === 'group' && w.layer.children === toList && this.contains(layer, w.layer)) return false;
    const i = from.indexOf(layer);
    from.splice(i, 1);
    if (from === toList && index > i) index--;
    toList.splice(Math.max(0, Math.min(toList.length, index)), 0, layer);
    return true;
  }

  get tilesX() { return Math.ceil(this.width / TILE); }
  get tilesY() { return Math.ceil(this.height / TILE); }

  /**
   * שכבה חדשה. בלי index — בסוף (למעלה) של props.parent או של המסמך. עם index — במערך של props.parent,
   * או (בלי parent) במערך של השכבה הפעילה — "מעל הפעילה" נשאר באותה קבוצה.
   */
  addLayer(props = {}) {
    const layer = {
      id: newId('L'),
      name: props.name || 'שכבה ' + (this.rasters().length + 1),
      type: 'raster',
      opacity: props.opacity ?? 1,
      blend: props.blend ?? 'normal',
      visible: props.visible ?? true,
      locked: props.locked ?? false,
      /** "tx,ty" → אריח של הרנדרר. אריח חסר = שקוף. מותר גם מחוץ לקנבס — התוכן נשמר להזזה. */
      tiles: new Map(),
    };
    this.#insert(layer, props);
    return layer;
  }

  /** קבוצה חדשה (ריקה) — אותם כללי מיקום כמו addLayer */
  addGroup(props = {}) {
    const g = {
      id: newId('G'),
      name: props.name || 'קבוצה ' + ([...this.walk()].filter(w => w.layer.type === 'group').length + 1),
      type: 'group',
      opacity: props.opacity ?? 1,
      blend: props.blend ?? 'normal',
      visible: props.visible ?? true,
      locked: props.locked ?? false,
      collapsed: false,
      children: [],
    };
    this.#insert(g, props);
    return g;
  }

  #insert(item, props) {
    const list = props.parent ? props.parent.children
      : props.index === undefined ? this.layers : (this.listOf(this.active) ?? this.layers);
    list.splice(props.index ?? list.length, 0, item);
    this.activeId = item.id;
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

export const tileKey = (tx, ty) => tx + ',' + ty;

/** המפתחות של האריחים שמלבן נוגע בהם. */
export const tileKeysInRect = (x, y, w, h) => tilesInRect(x, y, w, h).map(([tx, ty]) => tileKey(tx, ty));
