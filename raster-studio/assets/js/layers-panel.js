// לוח השכבות: רשימה (העליונה למעלה, כמו בכל עורך), מאפייני השכבה הפעילה, ופעולות.
// במחשב — לוח בצד; בטלפון — גיליון מלמטה (editor.html, CSS). כל שינוי נרשם בהיסטוריה (ביטול / ביצוע).

import { BLEND_MODES } from './engine/doc.js';

export const BLEND_LABELS = { normal: 'רגיל', multiply: 'הכפלה', screen: 'מסך', overlay: 'שכבת-על' };

const $ = (s, el = document) => el.querySelector(s);

/**
 * @param {object} o
 * @param {HTMLElement} o.root   ה-aside של הלוח
 * @param {() => import('./engine/doc.js').RasterDocument} o.doc
 * @param {object} o.renderer
 * @param {import('./engine/history.js').History} o.history
 * @param {(what: string) => void} o.onChange  אחרי כל שינוי (לצייר מחדש)
 * @param {(msg: string, err?: boolean) => void} o.toast
 */
export function createLayersPanel({ root, doc, renderer, history, onChange, toast, beforeChange = () => {} }) {
  const list = $('.layer-list', root);
  const opacity = $('#layer-opacity', root);
  const opacityOut = $('#layer-opacity-out', root);
  const blend = $('#layer-blend', root);
  const thumbs = new Map();   // id → { rev, data }

  blend.replaceChildren(...BLEND_MODES.map(m => new Option(BLEND_LABELS[m], m)));

  const changed = what => { renderer.invalidate(doc()); onChange(what); render(); };

  /** שינוי מאפיין של שכבה — מיושם ונרשם בהיסטוריה. */
  function setProp(layer, key, value, label) {
    if (layer[key] === value) return;
    beforeChange();
    const before = { [key]: layer[key] };
    layer[key] = value;
    history.props(doc(), layer, before, { [key]: value }, label);
    changed(key);
  }

  // ---- מאפיינים ----
  // האטימות משתנה חיה בזמן הגרירה, ונרשמת בהיסטוריה פעם אחת — כשהגרירה נגמרת
  let opacityStart = null;
  opacity.addEventListener('input', () => {
    const l = doc().active;
    if (!l || l.locked) return;
    if (!opacityStart) beforeChange();
    opacityStart ??= { layer: l, value: l.opacity };
    l.opacity = Number(opacity.value) / 100;
    opacityOut.textContent = opacity.value + '%';
    renderer.invalidate(doc());
    onChange('opacity');
  });
  opacity.addEventListener('change', () => {
    const s = opacityStart;
    opacityStart = null;
    if (s && s.layer.opacity !== s.value) {
      history.props(doc(), s.layer, { opacity: s.value }, { opacity: s.layer.opacity }, 'אטימות');
    }
    render();
  });
  blend.addEventListener('change', () => {
    const l = doc().active;
    if (!l || l.locked) return;
    setProp(l, 'blend', blend.value, 'מצב מיזוג');
  });

  // ---- פעולות ----
  const act = {
    add() {
      const d = doc();
      const l = d.addLayer({ index: d.indexOf(d.active) + 1 });
      history.addLayer(d, l, 'שכבה חדשה');
      changed('add');
    },
    duplicate() {
      const d = doc(), src = d.active;
      const copy = d.addLayer({ name: src.name + ' עותק', index: d.indexOf(src) + 1,
        opacity: src.opacity, blend: src.blend, visible: src.visible });
      renderer.copyLayer(src, copy);
      history.addLayer(d, copy, 'שכפול שכבה');
      changed('duplicate');
    },
    up() { move(doc().active, doc().indexOf(doc().active) + 1); },
    down() { move(doc().active, doc().indexOf(doc().active) - 1); },
    remove() {
      const d = doc(), l = d.active;
      if (d.layers.length <= 1) { toast('זו השכבה היחידה'); return; }
      if (l.locked) { toast('השכבה נעולה'); return; }
      // בלי שאלה: יש ביטול (Ctrl+Z / ↶), והאריחים נשמרים בהיסטוריה עד שהרשומה נזרקת
      const i = d.indexOf(l);
      d.removeLayer(l);
      history.removeLayer(d, l, i, 'מחיקת שכבה');
      changed('remove');
      toast(`"${l.name}" נמחקה — אפשר לבטל`);
    },
  };

  function move(layer, to) {
    beforeChange();
    const d = doc(), from = d.indexOf(layer);
    d.activeId = layer.id;
    if (d.moveLayer(layer, to)) { history.moveLayer(d, layer, from, d.indexOf(layer), 'סדר השכבות'); changed('move'); }
    else render();
  }
  root.addEventListener('click', e => {
    const b = e.target.closest('[data-act]');
    if (b && !b.disabled) { beforeChange(); act[b.dataset.act](); }
  });

  // ---- הרשימה ----
  function thumb(layer) {
    const rev = layer.rev ?? 0;
    let t = thumbs.get(layer.id);
    if (!t || t.rev !== rev) {
      t = { rev, data: renderer.thumbnail(doc(), layer, 64) };
      thumbs.set(layer.id, t);
    }
    return t.data;
  }

  function row(layer) {
    const d = doc();
    const li = document.createElement('li');
    li.className = 'layer-row';
    li.dataset.id = layer.id;
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', String(layer === d.active));
    li.classList.toggle('hidden-layer', !layer.visible);
    li.innerHTML = `
      <span class="drag" aria-hidden="true">⋮⋮</span>
      <canvas class="thumb" aria-hidden="true"></canvas>
      <button class="layer-name one" type="button"></button>
      <button class="icon-btn eye" type="button"></button>
      <button class="icon-btn lock" type="button"></button>`;
    const img = thumb(layer);
    const c = $('canvas', li);
    c.width = img.width; c.height = img.height;
    c.getContext('2d').putImageData(img, 0, 0);
    const name = $('.layer-name', li);
    name.textContent = layer.name;
    name.title = layer.name;
    const eye = $('.eye', li);
    eye.textContent = layer.visible ? '👁️' : '🚫';
    eye.setAttribute('aria-label', (layer.visible ? 'הסתרת ' : 'הצגת ') + layer.name);
    eye.setAttribute('aria-pressed', String(!layer.visible));
    const lock = $('.lock', li);
    lock.textContent = layer.locked ? '🔒' : '🔓';
    lock.classList.toggle('off', !layer.locked);
    lock.setAttribute('aria-label', (layer.locked ? 'שחרור ' : 'נעילת ') + layer.name);
    lock.setAttribute('aria-pressed', String(layer.locked));

    name.addEventListener('click', () => {
      if (layer === doc().active && !layer.locked) rename(layer, name);
      else { doc().activeId = layer.id; render(); }
    });
    li.addEventListener('click', e => {
      if (e.target.closest('button, input')) return;
      doc().activeId = layer.id;
      render();
    });
    eye.addEventListener('click', () => setProp(layer, 'visible', !layer.visible, layer.visible ? 'הסתרת שכבה' : 'הצגת שכבה'));
    lock.addEventListener('click', () => setProp(layer, 'locked', !layer.locked, layer.locked ? 'שחרור נעילה' : 'נעילת שכבה'));
    $('.drag', li).addEventListener('pointerdown', e => drag(e, layer, li));
    return li;
  }

  /** שינוי שם: לחיצה על שם השכבה הפעילה → שדה במקום. Enter / יציאה — שומר; Esc — מבטל. */
  function rename(layer, btn) {
    const input = document.createElement('input');
    input.className = 'layer-name-input';
    input.value = layer.name;
    input.maxLength = 80;
    input.setAttribute('aria-label', 'שם השכבה');
    btn.replaceWith(input);
    input.focus();
    input.select();
    let done = false;
    const finish = save => {
      if (done) return;
      done = true;
      const v = input.value.trim();
      if (save && v && v !== layer.name) setProp(layer, 'name', v, 'שם השכבה');
      else render();
    };
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') finish(false);
    });
    input.addEventListener('blur', () => finish(true));
  }

  /** גרירה בידית ⋮⋮ — מקום חדש בערימה. השורה שמתחת לאצבע מסומנת: לפניה או אחריה. */
  function drag(e, layer, li) {
    e.preventDefault();
    const handle = e.currentTarget;
    handle.setPointerCapture(e.pointerId);
    li.classList.add('dragging');
    let target = null, after = false;
    const clear = () => list.querySelectorAll('.drop-before, .drop-after').forEach(r => r.classList.remove('drop-before', 'drop-after'));
    const onMove = ev => {
      clear();
      target = null;
      for (const r of list.children) {
        const b = r.getBoundingClientRect();
        if (ev.clientY >= b.top && ev.clientY < b.bottom) {
          if (r === li) break;
          target = r;
          after = ev.clientY > b.top + b.height / 2;
          r.classList.add(after ? 'drop-after' : 'drop-before');
          break;
        }
      }
    };
    const up = () => {
      handle.removeEventListener('pointermove', onMove);
      handle.removeEventListener('pointerup', up);
      handle.removeEventListener('pointercancel', up);
      li.classList.remove('dragging');
      clear();
      if (!target) return;
      const d = doc();
      // הרשימה מוצגת מלמעלה למטה = מהסוף להתחלה. "לפני" שורה בתצוגה = מעליה בערימה.
      const t = d.layer(target.dataset.id);
      let to = d.indexOf(t) + (after ? 0 : 1);
      if (d.indexOf(layer) < to) to--;
      move(layer, to);
    };
    handle.addEventListener('pointermove', onMove);
    handle.addEventListener('pointerup', up);
    handle.addEventListener('pointercancel', up);
  }

  function render() {
    const d = doc();
    if (!d) return;
    list.replaceChildren(...[...d.layers].reverse().map(row));
    const l = d.active;
    opacity.value = Math.round(l.opacity * 100);
    opacityOut.textContent = opacity.value + '%';
    blend.value = l.blend;
    opacity.disabled = blend.disabled = l.locked;
    const i = d.indexOf(l);
    $('[data-act="up"]', root).disabled = i >= d.layers.length - 1;
    $('[data-act="down"]', root).disabled = i <= 0;
    $('[data-act="remove"]', root).disabled = d.layers.length <= 1 || l.locked;
    for (const b of document.querySelectorAll('[data-layer-count]')) b.dataset.layerCount = d.layers.length;
  }

  return { render, act };
}
