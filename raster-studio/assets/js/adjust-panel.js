// לוח התיקונים: בהירות וניגודיות · גוון ורוויה · טשטוש — על השכבה הפעילה (ובבחירה, אם יש).
// תצוגה חיה על הקנבס בזמן הגרירה; ✓ כותב לשכבה (רשומה אחת בהיסטוריה), ✕ מחזיר.

const $ = (s, el = document) => el.querySelector(s);

export const ADJUSTMENTS = {
  bc: { label: 'בהירות וניגודיות', fields: { brightness: [-100, 100], contrast: [-100, 100] } },
  hsl: { label: 'גוון ורוויה', fields: { hue: [-180, 180], saturation: [-100, 100], lightness: [-100, 100] } },
  blur: { label: 'טשטוש', fields: { radius: [0, 64] } },
};

export function createAdjustPanel({ root, doc, renderer, history, onChange, draw, toast, beforeOpen }) {
  let kind = 'bc';
  let open = false;

  const values = () => {
    const v = { kind };
    for (const f of Object.keys(ADJUSTMENTS[kind].fields)) v[f] = Number($('#adj-' + f, root).value);
    return v;
  };

  function reset() {
    for (const [k, a] of Object.entries(ADJUSTMENTS)) {
      for (const f of Object.keys(a.fields)) {
        const el = $('#adj-' + f, root);
        el.value = '0';
        $(`output[for="adj-${f}"]`, root).textContent = '0';
      }
      $(`[data-adj="${k}"]`, root).hidden = k !== kind;
      $(`[data-adj-tab="${k}"]`, root).setAttribute('aria-selected', String(k === kind));
    }
  }

  function preview() {
    const d = doc();
    renderer.setAdjust(d, d.active, values());
    draw();
  }

  root.addEventListener('input', e => {
    const el = e.target.closest('input[type=range]');
    if (!el) return;
    $(`output[for="${el.id}"]`, root).textContent = el.value + (el.id === 'adj-radius' ? 'px' : el.id === 'adj-hue' ? '°' : '');
    preview();
  });
  root.addEventListener('click', e => {
    const tab = e.target.closest('[data-adj-tab]');
    if (tab) { kind = tab.dataset.adjTab; reset(); preview(); return; }
    const b = e.target.closest('[data-adj-act]');
    if (b) (b.dataset.adjAct === 'apply' ? apply : close)();
  });

  function show() {
    const d = doc(), layer = d?.active;
    if (!layer) return;
    if (layer.type === 'group') { toast('בחרו שכבה בתוך הקבוצה — תיקון חל על שכבה', true); return; }
    if (layer.locked) { toast('השכבה נעולה — 🔓 בלוח השכבות', true); return; }
    if (!layer.visible) { toast('השכבה מוסתרת — 👁️ בלוח השכבות', true); return; }
    beforeOpen?.();
    open = true;
    root.hidden = false;
    reset();
    $('#adjust-layer', root).textContent = layer.name;
    preview();
  }

  function close() {
    if (!open) return;
    open = false;
    root.hidden = true;
    renderer.setAdjust(doc(), null, null);
    draw();
  }

  function apply() {
    const d = doc();
    const v = values();
    const neutral = Object.keys(ADJUSTMENTS[kind].fields).every(f => v[f] === 0);
    if (neutral) { close(); return; }
    const layer = d.active;
    renderer.setAdjust(d, layer, v);
    const before = renderer.commitAdjust(d);
    if (before.size) history.pixels(d, layer, before, ADJUSTMENTS[kind].label);
    open = false;
    root.hidden = true;
    onChange('adjust');
  }

  return { show, close, apply, get open() { return open; }, values };
}
