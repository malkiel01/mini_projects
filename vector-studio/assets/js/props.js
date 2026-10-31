// לוח המאפיינים: לפי מה שנבחר — הלוח (בלי בחירה), או מיקום, גודל, מילוי, מתאר ופרמטרי הצורה.
// כל שינוי עובר ב-`edit(fn, commit)`: fn משנה את הנתיבים שנבחרו; commit=false — תצוגה חיה (גרירת צבע),
// commit=true — נכנס להיסטוריה.

import { confirm, prompt } from './ui-core/dialog.js';
import { makeGradient, endpoints, angleOf, hasPaint, isRadial, radialFor, drawable } from './gradient.js';

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

const TYPE_NAMES = { raster: 'רסטר', frame: 'מסגרת', group: 'קבוצת שכבות' };
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
  union: '<path d="M3 3h9v5h5v9H8v-5H3z" fill="currentColor" stroke="none"/>',
  subtract: '<path d="M3 3h9v5H8v4H3z" fill="currentColor" stroke="none"/><path d="M8 8h9v9H8z"/>',
  intersect: '<path d="M3 3h9v9H3zM8 8h9v9H8z"/><path d="M8 8h4v4H8z" fill="currentColor" stroke="none"/>',
  xor: '<path d="M3 3h9v5H8v4H3zM12 8h5v9H8v-5h4z" fill="currentColor" stroke="none"/>',
};
const ib = (act, label, arg = '') => `<button class="icon-btn" type="button" data-arr="${act}" data-arg="${arg}" aria-label="${esc(label)}" title="${esc(label)}"><svg viewBox="0 0 20 20" aria-hidden="true">${ICONS[arg || act]}</svg></button>`;

export function renderProps(el, ctx) {
  const { paths, items } = ctx;
  if (!items.length) {
    const s = ctx.surface;
    el.innerHTML = `
      <div class="props-head"><h2 class="sec-title one">לוח עבודה</h2>
        ${ctx.canPaste ? '<button class="btn sm ghost" type="button" data-act="paste" title="הדבקה (Ctrl+V)">📋 הדבקה</button>' : ''}</div>
      <div class="fields two">
        <label class="f wide"><span>שם</span><input data-a="name" value="${esc(s.name || '')}" maxlength="60"></label>
        <label class="f"><span>רוחב (pt)</span><input data-a="w" inputmode="decimal" dir="ltr" value="${n(s.size[0])}"></label>
        <label class="f"><span>גובה (pt)</span><input data-a="h" inputmode="decimal" dir="ltr" value="${n(s.size[1])}"></label>
      </div>
      <section class="group" aria-labelledby="g-boards">
        <div class="g-row"><h3 id="g-boards">לוחות בציור</h3>
          <button class="btn sm ghost" type="button" data-board="add"><span aria-hidden="true">+</span> לוח</button></div>
        <ul class="list boards">${ctx.surfaces.map(b => `
          <li class="layer-row${b.id === s.id ? ' on' : ''}" data-id="${esc(b.id)}">
            <button class="layer-name" type="button" data-board="select" aria-pressed="${b.id === s.id}">
              <b class="one">${esc(b.name || b.id)}</b><small dir="ltr">${n(b.size[0])} × ${n(b.size[1])}</small></button>
            ${ctx.surfaces.length > 1 ? '<button class="icon-btn" type="button" data-board="remove" aria-label="מחיקת הלוח">🗑️</button>' : ''}
          </li>`).join('')}
        </ul>
      </section>
      <p class="hint" style="margin-top:12px">בוחרים כלי צורה וגוררים על הלוח. בטלפון: אצבע אחת על רקע — הזזת התצוגה, שתיים — זום.</p>`;
    el.querySelector('[data-act="paste"]')?.addEventListener('click', () => ctx.action('paste'));
    el.querySelectorAll('[data-board]').forEach(b => b.addEventListener('click', () => {
      const id = b.closest('[data-id]')?.dataset.id;
      ctx.boards(b.dataset.board, id);
    }));
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
  const fillOn = hasPaint(p.fill), grad = drawable(p.fill?.gradient) ? p.fill.gradient : null; // סוג לא מוכר — כמו צבע
  const single = items.length === 1 && items[0].type !== 'group';
  const grp = items.length === 1 && items[0].type === 'group';
  const fill = p.fill || null, stroke = p.stroke || null;
  const shape = single && p.shape ? p.shape : null;
  const isLine = shape?.type === 'line';
  const [bx, by, bw, bh] = ctx.box;
  const text = single && p.type === 'text' ? p : null;
  const image = single && p.type === 'image' ? p : null;
  const title = text ? 'טקסט' : image ? 'תמונה' : single ? (shape ? SHAPE_NAMES[shape.type] || 'צורה' : 'נתיב') : grp ? `קבוצה · ${paths.length}` : `${items.length} אובייקטים`;
  const layers = ctx.surface.layers.filter(l => l.type === 'vector');
  const arrange = `<section class="group" aria-labelledby="g-arr">
      <h3 id="g-arr">סידור</h3>
      <div class="icon-row phys" role="group" aria-label="יישור${items.length === 1 ? ' ללוח' : ''}">
        ${ib('align', 'יישור לשמאל', 'left')}${ib('align', 'מרכוז אופקי', 'hcenter')}${ib('align', 'יישור לימין', 'right')}
        ${ib('align', 'יישור למעלה', 'top')}${ib('align', 'מרכוז אנכי', 'vcenter')}${ib('align', 'יישור למטה', 'bottom')}
      </div>
      ${items.length >= 3 ? `<div class="icon-row phys" role="group" aria-label="פיזור">${ib('distribute', 'פיזור אופקי', 'dh')}${ib('distribute', 'פיזור אנכי', 'dv')}</div>` : ''}
      ${items.length > 1 ? `<div class="icon-row phys" role="group" aria-label="פעולות צורה">
        ${ib('bool', 'איחוד', 'union')}${ib('bool', 'חיסור — התחתון פחות העליונים', 'subtract')}${ib('bool', 'חיתוך — רק החפיפה', 'intersect')}${ib('bool', 'הפרדה — בלי החפיפה', 'xor')}
      </div>` : ''}
      <div class="icon-row" role="group" aria-label="סיבוב והיפוך">
        <button class="icon-btn" type="button" data-arr="rotate" data-arg="-90" aria-label="סיבוב 90° נגד השעון" title="‎-90°">⟲</button>
        <button class="icon-btn" type="button" data-arr="rotate" data-arg="90" aria-label="סיבוב 90° עם השעון" title="90°">⟳</button>
        <button class="icon-btn" type="button" data-arr="flip" data-arg="h" aria-label="היפוך אופקי" title="היפוך אופקי">⇋</button>
        <button class="icon-btn" type="button" data-arr="flip" data-arg="v" aria-label="היפוך אנכי" title="היפוך אנכי">⇵</button>
        <label class="f rot"><span>סיבוב (°)</span><input data-rot inputmode="decimal" dir="ltr" placeholder="0"></label>
      </div>
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
        <button class="btn sm ghost" type="button" data-act="edit-text">✏️ עריכה</button>
        <button class="btn sm ghost" type="button" data-arr="outline" title="הופך לנתיבים — אפשר לערוך בעט">לקווי מתאר</button></div>
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
      <button class="icon-btn" data-act="copy" aria-label="העתקה" title="העתקה (Ctrl+C)">📋</button>
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

    ${image ? `<section class="group" aria-labelledby="g-img">
      <div class="g-row"><h3 id="g-img">תמונה</h3>
        <button class="btn sm ghost" type="button" data-act="image-ratio" title="מחזיר את היחס בין הרוחב לגובה של התמונה">יחס מקורי</button></div>
      <label class="f"><span>אטימות (%)</span><input data-io type="number" min="0" max="100" dir="ltr" value="${Math.round((image.opacity ?? 1) * 100)}"></label>
    </section>` : ''}
    ${isLine || image ? '' : `<section class="group" aria-labelledby="g-fill">
      <div class="g-row"><h3 id="g-fill">מילוי</h3>
        <label class="switch"><input type="checkbox" data-on="fill"${fillOn ? ' checked' : ''}><span class="sr">מילוי</span></label></div>
      <div class="g-stack"${fillOn ? '' : ' hidden'}>
        ${seg('fillmode', grad ? (isRadial(grad) ? 'radial' : 'gradient') : 'color', { label: 'סוג מילוי', items: [['color', 'צבע'], ['gradient', 'מעבר ישר'], ['radial', 'מעבר עגול']] })}
        <div class="g-row">
          ${grad
            ? `<input type="color" data-g="0" value="${esc(grad.stops[0]?.color || '#ffffff')}" aria-label="צבע התחלה">
               <input type="color" data-g="1" value="${esc(grad.stops[grad.stops.length - 1]?.color || '#000000')}" aria-label="צבע סוף">
               ${isRadial(grad) ? '' : `<label class="f grow"><span>זווית (°)</span><input data-ga type="number" min="0" max="359" dir="ltr" value="${angleOf(grad)}"></label>`}`
            : `<input type="color" data-c="fill" value="${esc(fill?.color || '#2d4a8a')}" aria-label="צבע מילוי">`}
          <label class="f grow"><span>אטימות (%)</span><input data-o="fill" type="number" min="0" max="100" dir="ltr" value="${Math.round((fill?.opacity ?? 1) * 100)}"></label>
        </div>
      </div>
    </section>`}

    ${image ? '' : `<section class="group" aria-labelledby="g-stroke">
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
    </section>`}
    ${image ? '' : savedSection(ctx.prefs)}`;

  const each = (fn) => (path) => fn(path);

  el.querySelectorAll('[data-arr]').forEach(b => b.addEventListener('click', () => ctx.arrange(b.dataset.arr, b.dataset.arg)));
  const rot = el.querySelector('[data-rot]');
  if (rot) rot.addEventListener('change', () => { const v = Number(rot.value); if (v && Number.isFinite(v)) ctx.arrange('rotate', v); });
  const ls = el.querySelector('[data-layer]');
  if (ls) ls.addEventListener('change', () => ctx.arrange('layer', ls.value));
  el.querySelector('[data-act="duplicate"]').addEventListener('click', () => ctx.action('duplicate'));
  el.querySelector('[data-act="copy"]').addEventListener('click', () => ctx.action('copy'));
  el.querySelector('[data-act="edit-text"]')?.addEventListener('click', () => ctx.action('edit-text'));
  el.querySelector('[data-act="image-ratio"]')?.addEventListener('click', () => ctx.action('image-ratio'));
  wireSaved(el, ctx, p);
  const io = el.querySelector('[data-io]');
  if (io) io.addEventListener('change', () => {
    const v = Math.max(0, Math.min(100, Number(io.value) || 0)) / 100;
    ctx.edit(each(item => { if (item.type !== 'image') return; if (v === 1) delete item.opacity; else item.opacity = v; }), true);
  });
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
      if (g && !isRadial(g)) Object.assign(g, endpoints(ctx.pathBox(item), a));
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
        const f = item.fill || {}, op = f.opacity != null ? { opacity: f.opacity } : {};
        const box = ctx.pathBox(item);
        if (v === 'color') { if (f.gradient) item.fill = { ...op, color: f.gradient.stops[0]?.color || '#2d4a8a' }; return; }
        const type = v === 'radial' ? 'radial' : 'linear';
        if (!f.gradient) { item.fill = { ...op, gradient: makeGradient(box, 0, f.color || '#2d4a8a', '#ffffff', type) }; return; }
        if ((f.gradient.type || 'linear') === type) return;
        // ישר ↔ עגול: אותם צבעים, גאומטריה חדשה לפי התיבה
        const stops = f.gradient.stops;
        f.gradient = type === 'radial' ? { type, ...radialFor(box), stops } : { type, ...endpoints(box, 0), stops };
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
  const foreign = ctx.surface.layers.filter(l => l.type !== 'vector').slice().reverse();
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
    </ul>
    ${foreign.length ? `<p class="hint" style="margin-top:12px">שכבות של אפליקציות אחרות — מוצגות ונשמרות כמו שהן, בלי עריכה כאן:</p>
    <ul class="list">${foreign.map(l => `<li class="layer-row"><span class="use-ic" aria-hidden="true">${l.type === 'raster' ? '🖼️' : l.type === 'frame' ? '📰' : '📁'}</span>
      <span class="layer-name"><b class="one">${esc(l.name || l.id)}</b><small>${esc(TYPE_NAMES[l.type] || l.type)}${l.visible === false ? ' · מוסתרת' : ''}</small></span></li>`).join('')}</ul>` : ''}`;
  el.querySelectorAll('[data-l]').forEach(b => b.addEventListener('click', async () => {
    const a = b.dataset.l, id = b.closest('[data-id]')?.dataset.id;
    if (a === 'add') return ctx.add();
    if (a === 'active') return ctx.setActive(id);
    if (a === 'visible' || a === 'locked') return ctx.toggle(id, a);
    if (a === 'up' || a === 'down') return ctx.move(id, a === 'up' ? 1 : -1);
    if (a === 'rename') {
      const l = ctx.surface.layers.find(x => x.id === id);
      const name = await prompt('שם השכבה', { value: l.name || '' });
      if (name && name.trim()) ctx.rename(id, name.trim().slice(0, 60));
      return;
    }
    if (a === 'remove') {
      const n = ctx.counts.get(id) || 0;
      if (n && !await confirm(`בשכבה ${n} אובייקטים. למחוק אותה יחד איתם?`, { ok: 'מחיקה', danger: true })) return;
      ctx.remove(id);
    }
  }));
}

// ---------- צבעים וסגנונות שמורים (של המשתמש, לכל הציורים) ----------

// פלטה ראשונה למי שעוד לא שמר כלום — נשמרת רק כשמשנים אותה
const STARTER = ['#16192b', '#ffffff', '#2d4a8a', '#1e7b3a', '#e19a00', '#c0392b', '#8e44ad', '#5d6475'];
let target = 'fill';   // הקשה על צבע — למילוי או למתאר
let managing = false;  // מצב עריכה: ✕ על כל צבע וסגנון

function savedSection(prefs) {
  const sw = prefs?.swatches?.length ? prefs.swatches : STARTER;
  const styles = prefs?.styles || [];
  const prev = (st) => `<i class="sw-prev" style="background:${esc(st.fill?.color || 'transparent')};border-color:${esc(st.stroke?.color || 'var(--line)')}"></i>`;
  return `<section class="group" aria-labelledby="g-saved">
      <div class="g-row"><h3 id="g-saved">צבעים וסגנונות</h3>
        <button class="btn sm ghost" type="button" data-sv="manage" aria-pressed="${managing}">${managing ? 'סיום' : 'עריכה'}</button></div>
      ${seg('svtarget', target, { label: 'הקשה על צבע', items: [['fill', 'למילוי'], ['stroke', 'למתאר']] })}
      <div class="swatches" role="group" aria-label="צבעים שמורים">
        ${sw.map((c, i) => `<button type="button" class="swatch${managing ? ' del' : ''}" data-sw="${i}" style="background:${esc(c)}" aria-label="${managing ? 'הסרת ' : ''}${esc(c)}" title="${esc(c)}"></button>`).join('')}
        <button type="button" class="swatch add" data-sv="add-color" aria-label="שמירת הצבע הנוכחי" title="שמירת הצבע הנוכחי">+</button>
      </div>
      <div class="styles-list" role="group" aria-label="סגנונות שמורים">
        ${styles.map((st, i) => `<button type="button" class="btn sm ghost style-chip${managing ? ' del' : ''}" data-st="${i}">${prev(st)}${esc(st.name)}${managing ? ' ✕' : ''}</button>`).join('')}
        <button type="button" class="btn sm ghost" data-sv="add-style">+ שמירת הסגנון</button>
      </div>
    </section>`;
}

function wireSaved(el, ctx, p) {
  const prefs = ctx.prefs || { swatches: [], styles: [] };
  const swatches = () => (prefs.swatches.length ? prefs.swatches.slice() : STARTER.slice());
  const save = (next) => ctx.savePrefs({ swatches: prefs.swatches, styles: prefs.styles, ...next });
  el.querySelector('fieldset[data-k="svtarget"]')?.addEventListener('change', (e) => { target = e.target.value; e.stopPropagation(); }, true);
  el.querySelector('[data-sv="manage"]')?.addEventListener('click', () => { managing = !managing; ctx.refresh(); });
  el.querySelectorAll('[data-sw]').forEach(b => b.addEventListener('click', () => {
    const i = Number(b.dataset.sw), list = swatches();
    if (managing) { list.splice(i, 1); return save({ swatches: list }); }
    const color = list[i];
    ctx.edit((path) => {
      if (path.type === 'image') return;
      if (target === 'stroke') path.stroke = { ...(path.stroke || { width: 1, cap: 'butt', join: 'miter', dash: [] }), color };
      else path.fill = { ...(path.fill?.opacity != null ? { opacity: path.fill.opacity } : {}), color };
    }, true);
  }));
  el.querySelector('[data-sv="add-color"]')?.addEventListener('click', () => {
    const c = (target === 'stroke' ? p.stroke?.color : p.fill?.color || p.fill?.gradient?.stops?.[0]?.color) || '#16192b';
    const list = swatches();
    if (!list.includes(c.toLowerCase())) list.push(c.toLowerCase());
    save({ swatches: list.slice(-64) });
  });
  el.querySelectorAll('[data-st]').forEach(b => b.addEventListener('click', () => {
    const i = Number(b.dataset.st), st = prefs.styles[i];
    if (!st) return;
    if (managing) { const list = prefs.styles.slice(); list.splice(i, 1); return save({ styles: list }); }
    ctx.edit((path) => {
      if (path.type === 'image') return;
      path.fill = st.fill ? JSON.parse(JSON.stringify(st.fill)) : null;
      // מעבר צבע — באותה זווית / צורה, אבל לפי התיבה של הפריט הזה (הנקודות השמורות הן של המקור)
      const g = path.fill?.gradient;
      if (g && drawable(g)) Object.assign(g, isRadial(g) ? radialFor(ctx.pathBox(path)) : endpoints(ctx.pathBox(path), angleOf(g)));
      path.stroke = st.stroke ? JSON.parse(JSON.stringify(st.stroke)) : null;
    }, true);
  }));
  el.querySelector('[data-sv="add-style"]')?.addEventListener('click', async () => {
    const name = await prompt('שם לסגנון');
    if (!name || !name.trim()) return;
    const fill = p.fill ? JSON.parse(JSON.stringify(p.fill)) : null;
    save({ styles: [...prefs.styles, { name: name.trim().slice(0, 40), fill, stroke: p.stroke ? JSON.parse(JSON.stringify(p.stroke)) : null }].slice(-32) });
  });
}
