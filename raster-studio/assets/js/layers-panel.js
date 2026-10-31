// לוח השכבות: רשימה (העליונה למעלה, כמו בכל עורך), מאפייני השכבה הפעילה, ופעולות — כולל מסכת שכבה (🎭).
// במחשב — לוח בצד; בטלפון — גיליון מלמטה (editor.html, CSS). כל שינוי נרשם בהיסטוריה (ביטול / ביצוע).

import { BLEND_MODES, newMask } from './engine/doc.js';

export const BLEND_LABELS = { normal: 'רגיל', multiply: 'הכפלה', screen: 'מסך', overlay: 'שכבת-על' };

const $ = (s, el = document) => el.querySelector(s);

/** תמונה ממוזערת של שכבת מילוי — CSS (צבע, או מעבר בכיוון שלו) */
function fillCss(f) {
  if (!f.gradient) return f.color;
  const stops = f.gradient.stops.map(st => `${st.color} ${Math.round(st.at * 100)}%`).join(', ');
  if (f.gradient.type === 'radial') return `radial-gradient(circle, ${stops})`;
  const [x0, y0] = f.gradient.from ?? [0, 0], [x1, y1] = f.gradient.to ?? [1, 0];
  return `linear-gradient(${Math.round(Math.atan2(x1 - x0, -(y1 - y0)) * 180 / Math.PI)}deg, ${stops})`;
}

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
      if (src.foreign) { toast('שכבה של אפליקציה אחרת — אי אפשר לשכפל אותה כאן', true); return; }
      // עותק עמוק — לקבוצה גם כל השכבות שבתוכה
      const copyInto = (item, props) => {
        if (item.type === 'group') {
          const g = d.addGroup({ ...props, opacity: item.opacity, blend: item.blend, visible: item.visible });
          for (const c of item.children) if (!c.foreign) copyInto(c, { name: c.name, parent: g });
          return g;
        }
        const l = d.addLayer({ ...props, opacity: item.opacity, blend: item.blend, visible: item.visible });
        renderer.copyLayer(item, l);
        if (item.adjust) l.adjust = { ...item.adjust };
        if (item.fill) l.fill = structuredClone(item.fill);
        return l;
      };
      const copy = copyInto(src, { name: src.name + ' עותק', index: d.indexOf(src) + 1 });
      d.activeId = copy.id;
      history.addLayer(d, copy, src.type === 'group' ? 'שכפול קבוצה' : 'שכפול שכבה');
      changed('duplicate');
    },
    /** קבוצה חדשה במקום הפעילה, והפעילה בתוכה (Ctrl+G) */
    group() {
      const d = doc(), l = d.active;
      const list = d.listOf(l), before = [...list];
      const i = list.indexOf(l);
      const g = d.addGroup({ index: i + 1 });
      d.reparent(l, g.children, 0);
      d.activeId = g.id;
      history.structure(d, [[list, before, [...list]], [g.children, [], [...g.children]]], 'קיבוץ');
      changed('group');
    },
    /** פירוק קבוצה: הילדים יוצאים למקומה (Ctrl+Shift+G) */
    ungroup() {
      const d = doc(), g = d.active;
      if (g.type !== 'group') return;
      const list = d.listOf(g), before = [...list], kids = [...g.children];
      list.splice(list.indexOf(g), 1, ...kids);
      g.children.length = 0;
      d.activeId = (kids.at(-1) ?? list.at(-1))?.id;
      history.structure(d, [[list, before, [...list]], [g.children, kids, []]], 'פירוק קבוצה');
      changed('ungroup');
    },
    up() { move(doc().active, doc().indexOf(doc().active) + 1); },
    down() { move(doc().active, doc().indexOf(doc().active) - 1); },
    /**
     * 🎭 מסכת שכבה: בלי מסכה — חדשה (עם בחירה — לבן בתוכה ושחור מחוצה לה; בלי — לבן בכל השכבה), ועוברים לעריכתה.
     * יש מסכה — מעבר בין עריכת המסכה לעריכת הפיקסלים.
     */
    mask() {
      const d = doc(), l = d.active;
      if (l.type === 'group') { toast('מסכה — לשכבה, לא לקבוצה', true); return; }
      if (l.foreign) { toast('שכבה של אפליקציה אחרת — לא נערכת כאן', true); return; }
      if (l.mask) { l.editMask = !l.editMask; changed('mask-edit'); return; }
      if (l.locked) { toast('השכבה נעולה — 🔓 בלוח השכבות', true); return; }
      const m = newMask(l);
      renderer.fill(d, m, d.selection ? [0, 0, 0] : [255, 255, 255]);
      if (d.selection) renderer.freeTextures(renderer.fillSelection(d, m, 'paint', [255, 255, 255], 1).values());
      l.mask = m;
      l.editMask = true;
      history.mask(d, l, null, m, d.selection ? 'מסכה מהבחירה' : 'מסכת שכבה');
      changed('mask');
    },
    remove() {
      const d = doc(), l = d.active;
      if (l.locked) { toast('השכבה נעולה'); return; }
      // בלי שאלה: יש ביטול (Ctrl+Z / ↶), והאריחים נשמרים בהיסטוריה עד שהרשומה נזרקת
      const list = d.listOf(l), i = list.indexOf(l);
      if (!d.removeLayer(l)) { toast('זו השכבה היחידה'); return; }
      history.removeLayer(d, l, i, l.type === 'group' ? 'מחיקת קבוצה' : 'מחיקת שכבה', list);
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
    const mb = e.target.closest('[data-mask]');
    if (mb && !mb.disabled) { beforeChange(); maskAct[mb.dataset.mask](); }
  });

  // ---- המסכה של השכבה הפעילה ----
  const maskAct = {
    edit() { const l = doc().active; l.editMask = !l.editMask; changed('mask-edit'); },
    toggle() {
      const l = doc().active, m = l.mask;
      history.props(doc(), m, { enabled: m.enabled }, { enabled: !m.enabled }, m.enabled ? 'השבתת המסכה' : 'הפעלת המסכה');
      m.enabled = !m.enabled;
      changed('mask');
    },
    /** החלה: הפיקסלים כפול המסכה, והמסכה יוצאת — צעד ביטול אחד */
    apply() {
      const d = doc(), l = d.active, m = l.mask;
      if (l.locked) { toast('השכבה נעולה — 🔓 בלוח השכבות', true); return; }
      const pixels = m.enabled ? renderer.applyMask(d, l) : new Map();
      l.mask = null;
      l.editMask = false;
      history.mask(d, l, m, null, 'החלת המסכה', pixels);
      changed('mask');
    },
    remove() {
      const d = doc(), l = d.active, m = l.mask;
      l.mask = null;
      l.editMask = false;
      history.mask(d, l, m, null, 'מחיקת המסכה');
      changed('mask');
      toast('המסכה נמחקה — אפשר לבטל');
    },
  };

  // ---- הרשימה ----
  // הקריאה מהכרטיס אסינכרונית: הקנבס מצויר כשהתמונה מוכנה (ואם השכבה השתנתה בינתיים — תמונה חדשה תבוא)
  function thumb(layer, canvas, id = layer.id) {
    const rev = layer.rev ?? 0;
    let t = thumbs.get(id);
    if (!t || t.rev !== rev) {
      t = { rev, data: renderer.thumbnail(doc(), layer, 64) };
      thumbs.set(id, t);
    }
    t.data.then(img => {
      canvas.width = img.width; canvas.height = img.height;
      canvas.getContext('2d').putImageData(img, 0, 0);
    }, err => console.warn('thumbnail', err));
  }

  function row(layer, depth) {
    const d = doc();
    const li = document.createElement('li');
    li.className = 'layer-row' + (layer.type === 'group' ? ' group-row' : '');
    li.style.setProperty('--depth', depth);
    li.dataset.id = layer.id;
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', String(layer === d.active));
    li.classList.toggle('hidden-layer', !layer.visible);
    li.innerHTML = `
      <span class="drag" aria-hidden="true">⋮⋮</span>
      ${layer.type === 'group' ? '<button class="icon-btn fold" type="button"></button><span class="thumb folder" aria-hidden="true">📁</span>'
    : layer.foreign ? '<span class="thumb folder" aria-hidden="true">🧩</span>'
    : layer.adjust ? '<span class="thumb folder" aria-hidden="true" title="שכבת התאמה">🎚️</span>'
    : layer.fill ? `<span class="thumb folder fill-thumb" aria-hidden="true" title="שכבת מילוי" style="background:${fillCss(layer.fill)}"></span>`
    : '<canvas class="thumb" aria-hidden="true"></canvas>'}
      ${layer.mask ? '<button class="mask-thumb" type="button"><canvas class="thumb"></canvas></button>' : ''}
      <button class="layer-name one" type="button"></button>
      <button class="icon-btn eye" type="button"></button>
      <button class="icon-btn lock" type="button"></button>`;
    if (layer.type === 'group') {
      const fold = $('.fold', li);
      fold.textContent = layer.collapsed ? '‹' : '⌄';
      fold.setAttribute('aria-label', (layer.collapsed ? 'פתיחת ' : 'קיפול ') + layer.name);
      fold.setAttribute('aria-expanded', String(!layer.collapsed));
      fold.addEventListener('click', () => { layer.collapsed = !layer.collapsed; render(); });
    } else if (!layer.foreign && !layer.adjust && !layer.fill) {
      thumb(layer, li.querySelector(':scope > canvas.thumb'));
      li.classList.toggle('editing-pixels', !!layer.mask && !layer.editMask);
    }
    if (layer.mask) {
      // לחיצה על המסכה — עריכתה; Shift — השבתה / הפעלה (כמו בפוטושופ)
      const mt = $('.mask-thumb', li);
      thumb(layer.mask, $('canvas', mt), layer.id + ':mask');
      mt.classList.toggle('editing', !!layer.editMask);
      mt.classList.toggle('off', !layer.mask.enabled);
      mt.setAttribute('aria-label', `המסכה של ${layer.name}${layer.editMask ? ' (בעריכה)' : ''}`);
      mt.setAttribute('aria-pressed', String(!!layer.editMask));
      mt.title = 'המסכה — לחיצה: עריכה · Shift: השבתה';
      mt.addEventListener('click', e => {
        doc().activeId = layer.id;
        if (e.shiftKey) { maskAct.toggle(); return; }
        layer.editMask = true;
        changed('mask-edit');
      });
      li.querySelector(':scope > .thumb')?.addEventListener('click', () => { if (layer.editMask) { layer.editMask = false; doc().activeId = layer.id; changed('mask-edit'); } });
    }
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
    if (layer.foreign) li.title = `של אפליקציה אחרת (${layer.foreign.type}) — נשמרת כמו שהיא, ולא נערכת כאן`;
    lock.addEventListener('click', () => layer.foreign ? toast(li.title, true) : setProp(layer, 'locked', !layer.locked, layer.locked ? 'שחרור נעילה' : 'נעילת שכבה'));
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
    let target = null, after = false, into = false;
    const clear = () => list.querySelectorAll('.drop-before, .drop-after, .drop-into').forEach(r => r.classList.remove('drop-before', 'drop-after', 'drop-into'));
    const onMove = ev => {
      clear();
      target = null;
      for (const r of list.children) {
        const b = r.getBoundingClientRect();
        if (ev.clientY >= b.top && ev.clientY < b.bottom) {
          if (r === li) break;
          target = r;
          // שליש אמצעי של שורת קבוצה — לתוך הקבוצה
          const rel = (ev.clientY - b.top) / b.height;
          into = r.classList.contains('group-row') && rel > 1 / 3 && rel < 2 / 3;
          after = rel > 0.5;
          r.classList.add(into ? 'drop-into' : after ? 'drop-after' : 'drop-before');
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
      const t = d.layer(target.dataset.id);
      if (!t || d.contains(layer, t)) return;   // קבוצה לא נגררת לתוך עצמה
      // הרשימה מוצגת מלמעלה למטה = מהסוף להתחלה. "לפני" שורה בתצוגה = מעליה בערימה.
      const toList = into ? t.children : d.listOf(t);
      let to = into ? t.children.length : toList.indexOf(t) + (after ? 0 : 1);
      if (toList === d.listOf(layer)) {
        if (d.indexOf(layer) < to) to--;
        move(layer, to);
      } else {
        beforeChange();
        const from = d.listOf(layer), fromIdx = from.indexOf(layer);
        d.reparent(layer, toList, to);
        if (into) t.collapsed = false;
        d.activeId = layer.id;
        history.reparent(d, layer, from, fromIdx, toList, toList.indexOf(layer), into ? 'העברה לקבוצה' : 'סדר השכבות');
        changed('reparent');
      }
    };
    handle.addEventListener('pointermove', onMove);
    handle.addEventListener('pointerup', up);
    handle.addEventListener('pointercancel', up);
  }

  function render() {
    const d = doc();
    if (!d) return;
    // עץ: העליונה למעלה בכל רמה, וילדי קבוצה פתוחה — מתחתיה, מוזחים
    const rows = [];
    const add = (items, depth) => {
      for (const item of [...items].reverse()) {
        rows.push(row(item, depth));
        if (item.type === 'group' && !item.collapsed) add(item.children, depth + 1);
      }
    };
    add(d.layers, 0);
    list.replaceChildren(...rows);
    const l = d.active;
    opacity.value = Math.round(l.opacity * 100);
    opacityOut.textContent = opacity.value + '%';
    blend.value = l.blend;
    opacity.disabled = blend.disabled = l.locked;
    const sib = d.listOf(l), i = sib.indexOf(l);
    $('[data-act="up"]', root).disabled = i >= sib.length - 1;
    $('[data-act="down"]', root).disabled = i <= 0;
    $('[data-act="remove"]', root).disabled = [...d.walk()].length <= 1 || l.locked;
    $('[data-act="ungroup"]', root).hidden = l.type !== 'group';
    $('[data-act="group"]', root).hidden = l.type === 'group';
    const mb = $('[data-act="mask"]', root);
    mb.disabled = l.type === 'group' || !!l.foreign;
    mb.setAttribute('aria-pressed', String(!!l.editMask));
    const mbl = l.mask ? (l.editMask ? 'חזרה לעריכת הפיקסלים' : 'עריכת המסכה') : d.selection ? 'מסכה מהבחירה' : 'מסכת שכבה';
    mb.title = mbl;
    mb.setAttribute('aria-label', mbl);
    const mo = $('.mask-opts', root);
    mo.hidden = !l.mask;
    if (l.mask) {
      $('[data-mask="edit"]', mo).setAttribute('aria-pressed', String(!!l.editMask));
      $('[data-mask="toggle"]', mo).setAttribute('aria-pressed', String(!l.mask.enabled));
      $('[data-mask="toggle"]', mo).textContent = l.mask.enabled ? '⊘ השבתה' : '◉ הפעלה';
      for (const b of mo.querySelectorAll('[data-mask="apply"], [data-mask="remove"]')) b.disabled = l.locked;
    }
    for (const b of document.querySelectorAll('[data-layer-count]')) b.dataset.layerCount = d.rasters().length;
  }

  return { render, act };
}
