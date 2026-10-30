// לוח התיקונים: בהירות וניגודיות · גוון ורוויה · רמות · היפוך · פוסטריזציה · סף · טשטוש · חידוד · טשטוש תנועה · רעש.
// שלושה מצבים:
// - על שכבת פיקסלים: תיקון הורס — תצוגה חיה, ✓ כותב לשכבה (ובבחירה, אם יש) ברשומה אחת בהיסטוריה, ✕ מחזיר.
// - "➕ כשכבה" (לתיקוני צבע): שכבת התאמה חדשה מעל — לא נוגעת בפיקסלים, ואפשר לשנות אותה אחר כך. מסכה לבנה
//   (או מהבחירה), והעריכה עוברת אליה — מכחול שחור מסתיר את ההתאמה.
// - על שכבת התאמה: עריכת הפרמטרים שלה, חי; ✓ — צעד ביטול אחד.

import { newMask } from './engine/doc.js';
import { ADJUST_TYPES, ADJUST_FIELDS } from './engine/formats.js';

const $ = (s, el = document) => el.querySelector(s);

export const ADJUSTMENTS = {
  bc: { label: 'בהירות וניגודיות', fields: { brightness: [-100, 100], contrast: [-100, 100] }, identity: { brightness: 0, contrast: 0 } },
  hsl: { label: 'גוון ורוויה', fields: { hue: [-180, 180], saturation: [-100, 100], lightness: [-100, 100] }, identity: { hue: 0, saturation: 0, lightness: 0 } },
  levels: { label: 'רמות', fields: { black: [0, 254, 0], white: [1, 255, 255], gamma: [10, 300, 100] }, identity: { black: 0, white: 255, gamma: 100 } },
  invert: { label: 'היפוך צבעים', fields: {} },
  posterize: { label: 'פוסטריזציה', fields: { levels: [2, 32, 4] } },
  threshold: { label: 'סף', fields: { level: [1, 255, 128] } },
  blur: { label: 'טשטוש', fields: { radius: [0, 64] }, identity: { radius: 0 } },
  sharpen: { label: 'חידוד', fields: { amount: [0, 300, 100], sradius: [0, 16, 2] }, identity: { amount: 0 } },
  motion: { label: 'טשטוש תנועה', fields: { angle: [-180, 180], distance: [0, 128] }, identity: { distance: 0 } },
  noise: { label: 'רעש', fields: { noise: [0, 100] }, identity: { noise: 0 } },
};

// יחידה לכל מחוון (לתצוגה ליד הערך)
const UNIT = { radius: 'px', sradius: 'px', distance: 'px', hue: '°', angle: '°', amount: '%', noise: '%' };
// גמא במחוון — פי 100 (שלמים); בשכבה ובמנוע — הערך עצמו
const SCALE = { gamma: 100 };
const fmt = (f, v) => (SCALE[f] ? (v / SCALE[f]).toFixed(2) : v + (UNIT[f] ?? ''));

/** סוג התיקון בלוח → type של שכבת התאמה (רק תיקוני צבע) */
const LAYER_TYPE = Object.fromEntries(Object.entries(ADJUST_TYPES).map(([t, k]) => [k, t]));

export function createAdjustPanel({ root, doc, renderer, history, onChange, draw, toast, beforeOpen }) {
  let kind = 'bc';
  let open = false;
  let editing = null;   // { layer, before } — עריכת שכבת התאמה קיימת

  const values = () => {
    const v = { kind };
    for (const f of Object.keys(ADJUSTMENTS[kind].fields)) v[f] = Number($('#adj-' + f, root).value) / (SCALE[f] ?? 1);
    return v;
  };

  /** שכבת התאמה מהערכים: { type, …פרמטרים } */
  const toLayer = v => {
    const type = LAYER_TYPE[v.kind];
    return Object.fromEntries([['type', type], ...ADJUST_FIELDS[type].map(f => [f, v[f]])]);
  };

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
      // בעריכת שכבת התאמה — רק תיקוני צבע (מה ששכבה יכולה להיות)
      tab.hidden = !!editing && !LAYER_TYPE[k];
    }
    $('[data-adj-act="layer"]', root).hidden = !!editing || !LAYER_TYPE[kind];
    $('[data-adj-act="apply"]', root).textContent = editing ? '✓ עדכון' : '✓ החלה';
  }

  function preview() {
    const d = doc();
    if (editing) {
      editing.layer.adjust = toLayer(values());
      renderer.invalidate(d);
    } else {
      renderer.setAdjust(d, d.active, values());
    }
    draw();
  }

  root.addEventListener('input', e => {
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
    editing = layer.adjust ? { layer, before: { ...layer.adjust } } : null;
    if (editing) kind = ADJUST_TYPES[layer.adjust.type];
    reset();
    if (editing) for (const f of ADJUST_FIELDS[layer.adjust.type]) setField(f, layer.adjust[f]);
    $('#adjust-title', root).textContent = editing ? 'שכבת התאמה' : 'תיקונים';
    $('#adjust-layer', root).textContent = layer.name;
    preview();
  }

  function close() {
    if (!open) return;
    open = false;
    root.hidden = true;
    if (editing) { editing.layer.adjust = editing.before; renderer.invalidate(doc()); editing = null; }
    renderer.setAdjust(doc(), null, null);
    draw();
  }

  function apply() {
    const d = doc();
    const v = values();
    if (editing) {
      const { layer, before } = editing;
      const after = toLayer(v);
      editing = null;
      open = false;
      root.hidden = true;
      layer.adjust = after;
      if (JSON.stringify(after) !== JSON.stringify(before)) history.props(d, layer, { adjust: before }, { adjust: after }, ADJUSTMENTS[kind].label);
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
    layer.adjust = toLayer(v);
    layer.mask = newMask(layer);
    renderer.fill(d, layer.mask, d.selection ? [0, 0, 0] : [255, 255, 255]);
    if (d.selection) renderer.freeTextures(renderer.fillSelection(d, layer.mask, 'paint', [255, 255, 255], 1).values());
    layer.editMask = true;
    d.activeId = layer.id;
    history.addLayer(d, layer, 'שכבת התאמה');
    open = false;
    root.hidden = true;
    renderer.invalidate(d);
    onChange('adjust-layer');
  }

  return { show, close, apply, get open() { return open; }, values };
}
