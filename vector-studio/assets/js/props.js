// לוח המאפיינים: לפי מה שנבחר — הלוח (בלי בחירה), או מיקום, גודל, מילוי, מתאר ופרמטרי הצורה.
// כל שינוי עובר ב-`edit(fn, commit)`: fn משנה את הנתיבים שנבחרו; commit=false — תצוגה חיה (גרירת צבע),
// commit=true — נכנס להיסטוריה.

import { makeGradient, endpoints, angleOf, hasPaint } from './gradient.js';

const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
// בשדות — עד 2 ספרות אחרי הנקודה; המודל עצמו שומר 3.
const n = (v) => String(Math.round((Number(v) || 0) * 100) / 100);

function seg(name, value, options) {
  return `<fieldset class="seg" data-k="${name}" aria-label="${esc(options.label)}">`
    + options.items.map(([v, label, title]) => `<label title="${esc(title || label)}"><input type="radio" name="${name}" value="${esc(v)}"${String(v) === String(value) ? ' checked' : ''}><span>${label}</span></label>`).join('')
    + '</fieldset>';
}

const DASHES = { solid: [], dash: [6, 4], dot: [1, 3] };
function dashName(d) {
  if (!Array.isArray(d) || !d.length) return 'solid';
  return d[0] <= 1.5 ? 'dot' : 'dash';
}

const SHAPE_NAMES = { rect: 'מלבן', ellipse: 'אליפסה', polygon: 'מצולע', star: 'כוכב', line: 'קו' };

/**
 * ctx: { doc, surface, paths: [path], box: [x y w h] יחסית ללוח, edit, setBox, setArtboard, action }
 */
// אייקוני יישור וסידור — קווים פשוטים, 20×20
const ICONS = {
  left: '<path d="M3 2v16M6 5h10M6 12h6"/>',
  hcenter: '<path d="M10 2v16M4 5h12M6 12h8"/>',
  right: '<path d="M17 2v16M4 5h10M8 12h6"/>',
  top: '<path d="M2 3h16M5 6v10M12 6v6"/>',
  vcenter: '<path d="M2 10h16M5 4v12M12 6v8"/>',
  bottom: '<path d="M2 17h16M5 4v10M12 8v6"/>',
  dh: '<path d="M3 3v14M17 3v14M8 6v8M12 6v8"/>',
  dv: '<path d="M3 3h14M3 17h14M6 8h8M6 12h8"/>',
  front: '<path d="M10 16V4M5 9l5-5 5 5M4 2h12"/>',
  forward: '<path d="M10 16V4M5 9l5-5 5 5"/>',
  backward: '<path d="M10 4v12M5 11l5 5 5-5"/>',
  back: '<path d="M10 4v12M5 11l5 5 5-5M4 18h12"/>',
};
const ib = (act, label, arg = '') => `<button class="icon-btn" type="button" data-arr="${act}" data-arg="${arg}" aria-label="${esc(label)}" title="${esc(label)}"><svg viewBox="0 0 20 20" aria-hidden="true">${ICONS[arg || act]}</svg></button>`;

export function renderProps(el, ctx) {
  const { paths, items } = ctx;
  if (!items.length) {
    const s = ctx.surface;
    el.innerHTML = `
      <h2 class="sec-title">לוח עבודה</h2>
      <div class="fields two">
        <label class="f wide"><span>שם</span><input data-a="name" value="${esc(s.name || '')}" maxlength="60"></label>
        <label class="f"><span>רוחב (pt)</span><input data-a="w" inputmode="decimal" dir="ltr" value="${n(s.size[0])}"></label>
        <label class="f"><span>גובה (pt)</span><input data-a="h" inputmode="decimal" dir="ltr" value="${n(s.size[1])}"></label>
      </div>
      <p class="hint" style="margin-top:12px">בוחרים כלי צורה וגוררים על הלוח. בטלפון: אצבע אחת על רקע — הזזת התצוגה, שתיים — זום.</p>`;
    el.querySelectorAll('[data-a]').forEach(inp => inp.addEventListener('change', () => {
      const v = inp.value.trim();
      if (inp.dataset.a === 'name') ctx.setArtboard({ name: v || s.name });
      else {
        const x = Number(v);
        if (!(x > 0 && x <= 20000)) { inp.value = n(inp.dataset.a === 'w' ? s.size[0] : s.size[1]); return; }
        ctx.setArtboard(inp.dataset.a === 'w' ? { w: x } : { h: x });
      }
    }));
    return;
  }

  const p = paths[0] || {};
  const fillOn = hasPaint(p.fill), grad = p.fill?.gradient || null;
  const single = items.length === 1 && items[0].type !== 'group';
  const grp = items.length === 1 && items[0].type === 'group';
  const fill = p.fill || null, stroke = p.stroke || null;
  const shape = single && p.shape ? p.shape : null;
  const isLine = shape?.type === 'line';
  const [bx, by, bw, bh] = ctx.box;
  const text = single && p.type === 'text' ? p : null;
  const title = text ? 'טקסט' : single ? (shape ? SHAPE_NAMES[shape.type] || 'צורה' : 'נתיב') : grp ? `קבוצה · ${paths.length}` : `${items.length} אובייקטים`;
  const layers = ctx.surface.layers.filter(l => l.type === 'vector');
  const arrange = `<section class="group" aria-labelledby="g-arr">
      <h3 id="g-arr">סידור</h3>
      <div class="icon-row phys" role="group" aria-label="יישור${items.length === 1 ? ' ללוח' : ''}">
        ${ib('align', 'יישור לשמאל', 'left')}${ib('align', 'מרכוז אופקי', 'hcenter')}${ib('align', 'יישור לימין', 'right')}
        ${ib('align', 'יישור למעלה', 'top')}${ib('align', 'מרכוז אנכי', 'vcenter')}${ib('align', 'יישור למטה', 'bottom')}
      </div>
      ${items.length >= 3 ? `<div class="icon-row phys" role="group" aria-label="פיזור">${ib('distribute', 'פיזור אופקי', 'dh')}${ib('distribute', 'פיזור אנכי', 'dv')}</div>` : ''}
      <div class="icon-row" role="group" aria-label="סדר הצגה">
        ${ib('order', 'לחזית (Ctrl+Shift+])', 'front')}${ib('order', 'קדימה (Ctrl+])', 'forward')}${ib('order', 'אחורה (Ctrl+[)', 'backward')}${ib('order', 'לרקע (Ctrl+Shift+[)', 'back')}
        ${items.length > 1 ? '<button class="btn sm ghost" type="button" data-arr="group" title="Ctrl+G">קיבוץ</button>' : ''}
        ${grp ? '<button class="btn sm ghost" type="button" data-arr="ungroup" title="Ctrl+Shift+G">פירוק</button>' : ''}
      </div>
      ${layers.length > 1 ? `<label class="f"><span>שכבה</span><select data-layer>${layers.slice().reverse().map(l => `<option value="${esc(l.id)}"${l.id === ctx.layerId ? ' selected' : ''}>${esc(l.name || l.id)}</option>`).join('')}</select></label>` : ''}
    </section>`;

  let shapeFields = '';
  if (shape?.type === 'rect') shapeFields = `<label class="f"><span>פינות (pt)</span><input data-s="radius" inputmode="decimal" dir="ltr" value="${n(shape.radius)}"></label>`;
  if (shape?.type === 'polygon') shapeFields = `<label class="f"><span>צלעות</span><input data-s="sides" type="number" min="3" max="64" dir="ltr" value="${shape.sides}"></label>`;
  if (shape?.type === 'star') shapeFields = `<label class="f"><span>קודקודים</span><input data-s="points" type="number" min="3" max="64" dir="ltr" value="${shape.points}"></label>
      <label class="f"><span>עומק (%)</span><input data-s="inner" type="number" min="5" max="100" dir="ltr" value="${Math.round((shape.inner ?? .5) * 100)}"></label>`;

  const f = text?.font || {};
  const textFields = !text ? '' : `<section class="group" aria-labelledby="g-text">
      <div class="g-row"><h3 id="g-text">טקסט</h3>
        <button class="btn sm ghost" type="button" data-act="edit-text">✏️ עריכה</button></div>
      <div class="fields two">
        <label class="f wide"><span>גופן</span><select data-t="family">${ctx.fonts.map(x => `<option value="${esc(x.family)}"${x.family === f.family ? ' selected' : ''} style="font-family:'${esc(x.family)}'">${esc(x.label)}</option>`).join('')}</select></label>
        <label class="f"><span>גודל (pt)</span><input data-t="size" inputmode="decimal" dir="ltr" value="${n(f.size)}"></label>
        <label class="f"><span>מרווח שורות</span><input data-t="lineHeight" inputmode="decimal" dir="ltr" value="${n(text.lineHeight || 1.25)}"></label>
      </div>
      ${seg('weight', (f.weight || 400) >= 600 ? 700 : 400, { label: 'עובי', items: [[400, 'רגיל'], [700, '<b>מודגש</b>', 'מודגש']] })}
      ${seg('align', text.align || 'right', { label: 'יישור', items: [['right', 'ימין'], ['center', 'מרכז'], ['left', 'שמאל']] })}
    </section>`;

  el.innerHTML = `
    <div class="props-head"><h2 class="sec-title one">${esc(title)}</h2>
      <button class="icon-btn" data-act="duplicate" aria-label="שכפול" title="שכפול (Ctrl+D)">⧉</button>
      <button class="icon-btn" data-act="delete" aria-label="מחיקה" title="מחיקה (Delete)">🗑️</button></div>
    <div class="fields quad">
      <label class="f"><span>X</span><input data-b="0" inputmode="decimal" dir="ltr" value="${n(bx)}"></label>
      <label class="f"><span>Y</span><input data-b="1" inputmode="decimal" dir="ltr" value="${n(by)}"></label>
      <label class="f"><span>רוחב</span><input data-b="2" inputmode="decimal" dir="ltr" value="${n(bw)}"></label>
      <label class="f"><span>גובה</span><input data-b="3" inputmode="decimal" dir="ltr" value="${n(bh)}"></label>
    </div>
    ${shapeFields ? `<div class="fields two" style="margin-top:8px">${shapeFields}</div>` : ''}
    ${textFields}
    ${arrange}

    ${isLine ? '' : `<section class="group" aria-labelledby="g-fill">
      <div class="g-row"><h3 id="g-fill">מילוי</h3>
        <label class="switch"><input type="checkbox" data-on="fill"${fillOn ? ' checked' : ''}><span class="sr">מילוי</span></label></div>
      <div class="g-stack"${fillOn ? '' : ' hidden'}>
        ${seg('fillmode', grad ? 'gradient' : 'color', { label: 'סוג מילוי', items: [['color', 'צבע'], ['gradient', 'מעבר צבע']] })}
        <div class="g-row">
          ${grad
            ? `<input type="color" data-g="0" value="${esc(grad.stops[0]?.color || '#ffffff')}" aria-label="צבע התחלה">
               <input type="color" data-g="1" value="${esc(grad.stops[grad.stops.length - 1]?.color || '#000000')}" aria-label="צבע סוף">
               <label class="f grow"><span>זווית (°)</span><input data-ga type="number" min="0" max="359" dir="ltr" value="${angleOf(grad)}"></label>`
            : `<input type="color" data-c="fill" value="${esc(fill?.color || '#2d4a8a')}" aria-label="צבע מילוי">`}
          <label class="f grow"><span>אטימות (%)</span><input data-o="fill" type="number" min="0" max="100" dir="ltr" value="${Math.round((fill?.opacity ?? 1) * 100)}"></label>
        </div>
      </div>
    </section>`}

    <section class="group" aria-labelledby="g-stroke">
      <div class="g-row"><h3 id="g-stroke">מתאר</h3>
        <label class="switch"><input type="checkbox" data-on="stroke"${stroke?.color ? ' checked' : ''}><span class="sr">מתאר</span></label></div>
      <div${stroke?.color ? '' : ' hidden'} class="g-stack">
        <div class="g-row">
          <input type="color" data-c="stroke" value="${esc(stroke?.color || '#16192b')}" aria-label="צבע מתאר">
          <label class="f grow"><span>עובי (pt)</span><input data-w inputmode="decimal" dir="ltr" value="${n(stroke?.width ?? 1)}"></label>
          <label class="f grow"><span>אטימות (%)</span><input data-o="stroke" type="number" min="0" max="100" dir="ltr" value="${Math.round((stroke?.opacity ?? 1) * 100)}"></label>
        </div>
        ${seg('dash', dashName(stroke?.dash), { label: 'סוג קו', items: [['solid', '━━', 'רציף'], ['dash', '╍╍', 'מקווקו'], ['dot', '┈┈', 'נקודות']] })}
        ${seg('cap', stroke?.cap || 'butt', { label: 'קצוות', items: [['butt', 'ישר'], ['round', 'עגול'], ['square', 'מרובע']] })}
        ${seg('join', stroke?.join || 'miter', { label: 'חיבורים', items: [['miter', 'חד'], ['round', 'עגול'], ['bevel', 'קטום']] })}
      </div>
    </section>`;

  const each = (fn) => (path) => fn(path);

  el.querySelectorAll('[data-arr]').forEach(b => b.addEventListener('click', () => ctx.arrange(b.dataset.arr, b.dataset.arg)));
  const ls = el.querySelector('[data-layer]');
  if (ls) ls.addEventListener('change', () => ctx.arrange('layer', ls.value));
  el.querySelector('[data-act="duplicate"]').addEventListener('click', () => ctx.action('duplicate'));
  el.querySelector('[data-act="edit-text"]')?.addEventListener('click', () => ctx.action('edit-text'));
  el.querySelectorAll('[data-t]').forEach(inp => inp.addEventListener('change', () => {
    const k = inp.dataset.t;
    let v = inp.value;
    if (k !== 'family') { v = Number(v); if (!(v > 0 && v <= (k === 'size' ? 2000 : 5))) { inp.value = k === 'size' ? n(f.size) : n(text.lineHeight || 1.25); return; } }
    ctx.edit(each(item => {
      if (item.type !== 'text') return;
      if (k === 'lineHeight') item.lineHeight = v; else item.font = { ...item.font, [k]: v };
      ctx.relayout(item);
    }), true);
  }));
  el.querySelector('[data-act="delete"]').addEventListener('click', () => ctx.action('delete'));

  el.querySelectorAll('[data-b]').forEach(inp => inp.addEventListener('change', () => {
    const v = Number(inp.value);
    const box = ctx.box.slice();
    const i = Number(inp.dataset.b);
    if (!Number.isFinite(v) || (i >= 2 && v <= 0)) { inp.value = n(box[i]); return; }
    box[i] = v;
    ctx.setBox(box);
  }));

  el.querySelectorAll('[data-s]').forEach(inp => inp.addEventListener('change', () => {
    const k = inp.dataset.s;
    let v = Number(inp.value);
    if (!Number.isFinite(v)) return;
    if (k === 'inner') v = Math.max(5, Math.min(100, v)) / 100;
    if (k === 'sides' || k === 'points') v = Math.max(3, Math.min(64, Math.round(v)));
    if (k === 'radius') v = Math.max(0, v);
    ctx.setShape({ [k]: v });
  }));

  el.querySelectorAll('[data-on]').forEach(cb => cb.addEventListener('change', () => {
    const k = cb.dataset.on;
    const color = el.querySelector(`[data-c="${k}"]`)?.value || '#2d4a8a';
    ctx.edit(each(path => {
      if (!cb.checked) path[k] = null;
      else path[k] = k === 'fill' ? { color } : { color, width: 1, cap: 'butt', join: 'miter', dash: [] };
    }), true);
  }));

  el.querySelectorAll('[data-g]').forEach(inp => {
    const i = Number(inp.dataset.g);
    const set = (commit) => ctx.edit(each(item => {
      const g = item.fill?.gradient;
      if (!g) return;
      const st = i === 0 ? g.stops[0] : g.stops[g.stops.length - 1];
      st.color = inp.value;
    }), commit);
    inp.addEventListener('input', () => set(false));
    inp.addEventListener('change', () => set(true));
  });
  const ga = el.querySelector('[data-ga]');
  if (ga) ga.addEventListener('change', () => {
    const a = ((Number(ga.value) % 360) + 360) % 360;
    ctx.edit(each(item => {
      const g = item.fill?.gradient;
      if (g) Object.assign(g, endpoints(ctx.pathBox(item), a));
    }), true);
  });

  el.querySelectorAll('[data-c]').forEach(inp => {
    const k = inp.dataset.c;
    const set = (commit) => ctx.edit(each(path => { path[k] = { ...(path[k] || {}), color: inp.value }; }), commit);
    inp.addEventListener('input', () => set(false));
    inp.addEventListener('change', () => set(true));
  });

  el.querySelectorAll('[data-o]').forEach(inp => inp.addEventListener('change', () => {
    const k = inp.dataset.o;
    const v = Math.max(0, Math.min(100, Number(inp.value) || 0)) / 100;
    ctx.edit(each(path => {
      if (!path[k]) return;
      if (v === 1) delete path[k].opacity; else path[k].opacity = v;
    }), true);
  }));

  const w = el.querySelector('[data-w]');
  if (w) w.addEventListener('change', () => {
    const v = Number(w.value);
    if (!(v >= 0 && v <= 1000)) { w.value = n(stroke?.width ?? 1); return; }
    ctx.edit(each(path => { if (path.stroke) path.stroke.width = v; }), true);
  });

  el.querySelectorAll('fieldset.seg').forEach(fs => fs.addEventListener('change', (e) => {
    const k = fs.dataset.k, v = e.target.value;
    if (k === 'fillmode') {
      return ctx.edit(each(item => {
        const f = item.fill || {};
        if (v === 'gradient' && !f.gradient) item.fill = { ...(f.opacity != null ? { opacity: f.opacity } : {}), gradient: makeGradient(ctx.pathBox(item), 0, f.color || '#2d4a8a', '#ffffff') };
        if (v === 'color' && f.gradient) item.fill = { ...(f.opacity != null ? { opacity: f.opacity } : {}), color: f.gradient.stops[0]?.color || '#2d4a8a' };
      }), true);
    }
    if (k === 'weight' || k === 'align') {
      return ctx.edit(each(item => {
        if (item.type !== 'text') return;
        if (k === 'weight') item.font = { ...item.font, weight: Number(v) }; else item.align = v;
        ctx.relayout(item);
      }), true);
    }
    ctx.edit(each(path => {
      if (!path.stroke) return;
      if (k === 'dash') path.stroke.dash = DASHES[v].map(d => d * Math.max(1, path.stroke.width ?? 1));
      else path.stroke[k] = v;
    }), true);
  }));
}

/**
 * לשונית השכבות של הלוח: העליונה ראשונה. ctx: { surface, activeId, counts: Map, setActive, toggle, rename, move,
 * add, remove }.
 */
export function renderLayers(el, ctx) {
  const layers = ctx.surface.layers.filter(l => l.type === 'vector').slice().reverse();
  el.innerHTML = `
    <div class="props-head"><h2 class="sec-title one">שכבות · ${esc(ctx.surface.name || '')}</h2>
      <button class="btn sm primary" type="button" data-l="add"><span aria-hidden="true">+</span> שכבה</button></div>
    <p class="hint">אובייקט חדש נכנס לשכבה המסומנת. 🔒 — אי אפשר לבחור בה.</p>
    <ul class="list layers">${layers.map(l => `
      <li class="layer-row${l.id === ctx.activeId ? ' on' : ''}" data-id="${esc(l.id)}">
        <button class="icon-btn" type="button" data-l="visible" aria-pressed="${l.visible !== false}" aria-label="${l.visible === false ? 'הצגה' : 'הסתרה'}">${l.visible === false ? '🙈' : '👁️'}</button>
        <button class="icon-btn" type="button" data-l="locked" aria-pressed="${!!l.locked}" aria-label="${l.locked ? 'שחרור נעילה' : 'נעילה'}">${l.locked ? '🔒' : '🔓'}</button>
        <button class="layer-name" type="button" data-l="active" aria-pressed="${l.id === ctx.activeId}">
          <b class="one">${esc(l.name || l.id)}</b><small>${ctx.counts.get(l.id) || 0} אובייקטים</small></button>
        <button class="icon-btn" type="button" data-l="up" aria-label="למעלה">▲</button>
        <button class="icon-btn" type="button" data-l="down" aria-label="למטה">▼</button>
        <button class="icon-btn" type="button" data-l="rename" aria-label="שינוי שם">✏️</button>
        ${layers.length > 1 ? '<button class="icon-btn" type="button" data-l="remove" aria-label="מחיקת שכבה">🗑️</button>' : ''}
      </li>`).join('')}
    </ul>`;
  el.querySelectorAll('[data-l]').forEach(b => b.addEventListener('click', () => {
    const a = b.dataset.l, id = b.closest('[data-id]')?.dataset.id;
    if (a === 'add') return ctx.add();
    if (a === 'active') return ctx.setActive(id);
    if (a === 'visible' || a === 'locked') return ctx.toggle(id, a);
    if (a === 'up' || a === 'down') return ctx.move(id, a === 'up' ? 1 : -1);
    if (a === 'rename') {
      const l = ctx.surface.layers.find(x => x.id === id);
      const name = prompt('שם השכבה', l.name || '');
      if (name && name.trim()) ctx.rename(id, name.trim().slice(0, 60));
      return;
    }
    if (a === 'remove') {
      const n = ctx.counts.get(id) || 0;
      if (n && !confirm(`בשכבה ${n} אובייקטים. למחוק אותה יחד איתם?`)) return;
      ctx.remove(id);
    }
  }));
}
