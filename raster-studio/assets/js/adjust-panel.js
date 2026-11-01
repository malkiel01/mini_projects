// לוח התיקונים: בהירות וניגודיות · גוון ורוויה · רמות · היפוך · פוסטריזציה · סף · מילוי (צבע / מעבר) · טשטוש · חידוד ·
// טשטוש תנועה · רעש · אמנותיים: ציור שמן, פסיפס, תבליט, קצוות.
// שלושה מצבים:
// - על שכבת פיקסלים: תיקון הורס — תצוגה חיה, ✓ כותב לשכבה (ובבחירה, אם יש) ברשומה אחת בהיסטוריה, ✕ מחזיר.
// - "➕ כשכבה" (לתיקוני צבע): שכבת התאמה חדשה מעל — לא נוגעת בפיקסלים, ואפשר לשנות אותה אחר כך. מסכה לבנה
//   (או מהבחירה), והעריכה עוברת אליה — מכחול שחור מסתיר את ההתאמה.
// - על שכבת התאמה / מילוי: עריכת הפרמטרים שלה, חי; ✓ — צעד ביטול אחד.
// מילוי: "✓ החלה" — מעבר צבעים על הפיקסלים (בבחירה, אם יש); "➕ כשכבה" — שכבת מילוי. המעבר נפרש על הבחירה, או על הקנבס.

import { newMask } from './engine/doc.js';
import { ADJUST_TYPES, ADJUST_FIELDS } from './engine/formats.js';
import { selectionBox } from './engine/selection.js';
import { $ } from './ui-core/dom.js';

export const ADJUSTMENTS = {
  bc: { label: 'בהירות וניגודיות', fields: { brightness: [-100, 100], contrast: [-100, 100] }, identity: { brightness: 0, contrast: 0 } },
  hsl: { label: 'גוון ורוויה', fields: { hue: [-180, 180], saturation: [-100, 100], lightness: [-100, 100] }, identity: { hue: 0, saturation: 0, lightness: 0 } },
  levels: { label: 'רמות', fields: { black: [0, 254, 0], white: [1, 255, 255], gamma: [10, 300, 100] }, identity: { black: 0, white: 255, gamma: 100 } },
  invert: { label: 'היפוך צבעים', fields: {} },
  posterize: { label: 'פוסטריזציה', fields: { levels: [2, 32, 4] } },
  threshold: { label: 'סף', fields: { level: [1, 255, 128] } },
  fill: { label: 'מילוי', fields: { fangle: [-180, 180, 90], fopacity: [0, 100, 100] } },
  blur: { label: 'טשטוש', fields: { radius: [0, 64] }, identity: { radius: 0 } },
  sharpen: { label: 'חידוד', fields: { amount: [0, 300, 100], sradius: [0, 16, 2] }, identity: { amount: 0 } },
  motion: { label: 'טשטוש תנועה', fields: { angle: [-180, 180], distance: [0, 128] }, identity: { distance: 0 } },
  noise: { label: 'רעש', fields: { noise: [0, 100] }, identity: { noise: 0 } },
  oil: { label: 'ציור שמן', fields: { oradius: [1, 8, 4] } },
  mosaic: { label: 'פסיפס', fields: { cell: [2, 64, 8] } },
  emboss: { label: 'תבליט', fields: { eangle: [-180, 180, 135], edepth: [1, 10, 2], eamount: [0, 500, 100] } },
  edges: { label: 'קצוות', fields: {} },
};

// יחידה לכל מחוון (לתצוגה ליד הערך)
const UNIT = { radius: 'px', sradius: 'px', distance: 'px', hue: '°', angle: '°', amount: '%', noise: '%', fangle: '°', fopacity: '%',
  oradius: 'px', cell: 'px', eangle: '°', edepth: 'px', eamount: '%' };
// גמא במחוון — פי 100 (שלמים); בשכבה ובמנוע — הערך עצמו
const SCALE = { gamma: 100 };
const fmt = (f, v) => (SCALE[f] ? (v / SCALE[f]).toFixed(2) : v + (UNIT[f] ?? ''));

/** סוג התיקון בלוח → type של שכבת התאמה (רק תיקוני צבע) */
const LAYER_TYPE = Object.fromEntries(Object.entries(ADJUST_TYPES).map(([t, k]) => [k, t]));

export function createAdjustPanel({ root, doc, renderer, history, onChange, draw, toast, beforeOpen, color = () => '#000000' }) {
  let kind = 'bc';
  let open = false;
  let editing = null;   // { layer, field: 'adjust' | 'fill', before } — עריכת שכבת התאמה / מילוי קיימת

  /** אפשר ליצור מזה שכבה: תיקוני צבע, ומילוי */
  const canLayer = k => !!LAYER_TYPE[k] || k === 'fill';

  /** המילוי מהשדות: צבע אחיד, או מעבר משני הצבעים על הבחירה (או על הקנבס) — בזווית, או מהמרכז */
  function fillValue() {
    const d = doc();
    const style = $('#adj-fstyle', root).value, c1 = $('#adj-color1', root).value, c2 = $('#adj-color2', root).value;
    const opacity = Number($('#adj-fopacity', root).value) / 100;
    if (style === 'solid') return { color: c1, opacity };
    const b = selectionBox(d.selection, d.width, d.height) ?? [0, 0, d.width, d.height];
    const cx = (b[0] + b[2]) / 2, cy = (b[1] + b[3]) / 2, w = b[2] - b[0], h = b[3] - b[1];
    const stops = [{ at: 0, color: c1, opacity: 1 }, { at: 1, color: c2, opacity: 1 }];
    const r = n => Math.round(n * 100) / 100;
    if (style === 'radial') return { gradient: { type: 'radial', center: [r(cx), r(cy)], radius: r(Math.max(w, h) / 2), stops }, opacity };
    // לינארי: הזווית ב-מעלות נגד כיוון השעון מימין (90° — מלמטה למעלה); אורך — עד קצות המלבן בכיוון הזה
    const a = Number($('#adj-fangle', root).value) * Math.PI / 180, dx = Math.cos(a), dy = -Math.sin(a);
    const half = (Math.abs(w * dx) + Math.abs(h * dy)) / 2;
    return { gradient: { type: 'linear', from: [r(cx - dx * half), r(cy - dy * half)], to: [r(cx + dx * half), r(cy + dy * half)], stops }, opacity };
  }

  const values = () => {
    const v = { kind };
    if (kind === 'fill') { v.fill = fillValue(); return v; }
    for (const f of Object.keys(ADJUSTMENTS[kind].fields)) v[f] = Number($('#adj-' + f, root).value) / (SCALE[f] ?? 1);
    return v;
  };

  /** שכבת התאמה מהערכים: { type, …פרמטרים }; מילוי — המילוי עצמו */
  const toLayer = v => {
    if (v.kind === 'fill') return v.fill;
    const type = LAYER_TYPE[v.kind];
    return Object.fromEntries([['type', type], ...ADJUST_FIELDS[type].map(f => [f, v[f]])]);
  };

  /** שדות המילוי מתוך מילוי קיים (עריכת שכבת מילוי) */
  function loadFill(f) {
    const g = f.gradient;
    $('#adj-fstyle', root).value = !g ? 'solid' : g.type === 'radial' ? 'radial' : 'linear';
    $('#adj-color1', root).value = g ? g.stops[0].color : f.color;
    $('#adj-color2', root).value = g ? g.stops.at(-1).color : '#ffffff';
    if (g?.type === 'linear') setField('fangle', Math.round(Math.atan2(-(g.to[1] - g.from[1]), g.to[0] - g.from[0]) * 180 / Math.PI));
    setField('fopacity', Math.round((f.opacity ?? 1) * 100));
  }

  function setField(f, value) {
    const el = $('#adj-' + f, root);
    el.value = String(Math.round(value * (SCALE[f] ?? 1)));
    $(`output[for="adj-${f}"]`, root).textContent = fmt(f, Number(el.value));
  }

  function reset() {
    for (const [k, a] of Object.entries(ADJUSTMENTS)) {
      for (const [f, [, , def = 0]] of Object.entries(a.fields)) setField(f, def / (SCALE[f] ?? 1));
      $(`[data-adj="${k}"]`, root).hidden = k !== kind;
      const tab = $(`[data-adj-tab="${k}"]`, root);
      tab.setAttribute('aria-selected', String(k === kind));
      // בעריכת שכבה — רק מה שהשכבה יכולה להיות: שכבת התאמה — תיקוני צבע; שכבת מילוי — מילוי
      tab.hidden = !!editing && (editing.field === 'fill' ? k !== 'fill' : !LAYER_TYPE[k]);
    }
    $('#adj-color1', root).value = color();
    $('#adj-color2', root).value = '#ffffff';
    $('#adj-fstyle', root).value = 'linear';
    $('[data-adj-act="layer"]', root).hidden = !!editing || !canLayer(kind);
    $('[data-adj-act="apply"]', root).textContent = editing ? '✓ עדכון' : '✓ החלה';
  }

  function preview() {
    const d = doc();
    if (editing) {
      editing.layer[editing.field] = toLayer(values());
      renderer.invalidate(d);
    } else {
      renderer.setAdjust(d, d.active, values());
    }
    draw();
  }

  root.addEventListener('input', e => {
    if (e.target.closest('[data-adj="fill"]') && e.target.type !== 'range') { preview(); return; }   // צבעים, סגנון
    const el = e.target.closest('input[type=range]');
    if (!el) return;
    const f = el.id.slice(4);
    $(`output[for="${el.id}"]`, root).textContent = fmt(f, Number(el.value));
    preview();
  });
  root.addEventListener('click', e => {
    const tab = e.target.closest('[data-adj-tab]');
    if (tab) { kind = tab.dataset.adjTab; reset(); preview(); return; }
    const b = e.target.closest('[data-adj-act]');
    if (b) ({ apply, cancel: close, layer: asLayer })[b.dataset.adjAct]();
  });

  function show() {
    const d = doc(), layer = d?.active;
    if (!layer) return;
    if (layer.type === 'group') { toast('בחרו שכבה בתוך הקבוצה — תיקון חל על שכבה', true); return; }
    if (layer.foreign) { toast(`שכבה של אפליקציה אחרת (${layer.foreign.type}) — לא נערכת כאן`, true); return; }
    if (layer.locked) { toast('השכבה נעולה — 🔓 בלוח השכבות', true); return; }
    if (!layer.visible) { toast('השכבה מוסתרת — 👁️ בלוח השכבות', true); return; }
    beforeOpen?.();
    open = true;
    root.hidden = false;
    editing = layer.adjust ? { layer, field: 'adjust', before: { ...layer.adjust } }
      : layer.fill ? { layer, field: 'fill', before: structuredClone(layer.fill) } : null;
    if (editing) kind = editing.field === 'fill' ? 'fill' : ADJUST_TYPES[layer.adjust.type];
    reset();
    if (editing?.field === 'adjust') for (const f of ADJUST_FIELDS[layer.adjust.type]) setField(f, layer.adjust[f]);
    if (editing?.field === 'fill') loadFill(layer.fill);
    $('#adjust-title', root).textContent = editing ? (editing.field === 'fill' ? 'שכבת מילוי' : 'שכבת התאמה') : 'תיקונים';
    $('#adjust-layer', root).textContent = layer.name;
    preview();
  }

  function close() {
    if (!open) return;
    open = false;
    root.hidden = true;
    if (editing) { editing.layer[editing.field] = editing.before; renderer.invalidate(doc()); editing = null; }
    renderer.setAdjust(doc(), null, null);
    draw();
  }

  function apply() {
    const d = doc();
    const v = values();
    if (editing) {
      const { layer, field, before } = editing;
      const after = toLayer(v);
      editing = null;
      open = false;
      root.hidden = true;
      layer[field] = after;
      if (JSON.stringify(after) !== JSON.stringify(before)) history.props(d, layer, { [field]: before }, { [field]: after }, ADJUSTMENTS[kind].label);
      renderer.invalidate(d);
      onChange('adjust-layer');
      return;
    }
    const id = ADJUSTMENTS[kind].identity;
    const neutral = (id && Object.entries(id).every(([f, x]) => v[f] * (SCALE[f] ?? 1) === x)) || renderer.constructor.adjustNoop?.(v);
    if (neutral) { close(); return; }
    const layer = d.active;
    renderer.setAdjust(d, layer, v);
    const before = renderer.commitAdjust(d);
    if (before.size) history.pixels(d, layer, before, ADJUSTMENTS[kind].label);
    open = false;
    root.hidden = true;
    onChange('adjust');
  }

  /** ➕ כשכבה: שכבת התאמה מעל הפעילה, עם מסכה (לבנה, או מהבחירה); העריכה עוברת למסכה */
  function asLayer() {
    const d = doc(), v = values();
    renderer.setAdjust(d, null, null);
    const layer = d.addLayer({ name: ADJUSTMENTS[kind].label, index: d.indexOf(d.active) + 1 });
    if (kind === 'fill') layer.fill = v.fill; else layer.adjust = toLayer(v);
    layer.mask = newMask(layer);
    renderer.fill(d, layer.mask, d.selection ? [0, 0, 0] : [255, 255, 255]);
    if (d.selection) renderer.freeTextures(renderer.fillSelection(d, layer.mask, 'paint', [255, 255, 255], 1).values());
    layer.editMask = true;
    d.activeId = layer.id;
    history.addLayer(d, layer, kind === 'fill' ? 'שכבת מילוי' : 'שכבת התאמה');
    open = false;
    root.hidden = true;
    renderer.invalidate(d);
    onChange('adjust-layer');
  }

  return { show, close, apply, get open() { return open; }, values };
}
