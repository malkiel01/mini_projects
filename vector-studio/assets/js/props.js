// לוח המאפיינים: לפי מה שנבחר — הלוח (בלי בחירה), או מיקום, גודל, מילוי, מתאר ופרמטרי הצורה.
// כל שינוי עובר ב-`edit(fn, commit)`: fn משנה את הנתיבים שנבחרו; commit=false — תצוגה חיה (גרירת צבע),
// commit=true — נכנס להיסטוריה.

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
export function renderProps(el, ctx) {
  const { paths } = ctx;
  if (!paths.length) {
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

  const p = paths[0];
  const single = paths.length === 1;
  const fill = p.fill || null, stroke = p.stroke || null;
  const shape = single && p.shape ? p.shape : null;
  const isLine = shape?.type === 'line';
  const [bx, by, bw, bh] = ctx.box;
  const title = single ? (shape ? SHAPE_NAMES[shape.type] || 'צורה' : 'נתיב') : `${paths.length} אובייקטים`;

  let shapeFields = '';
  if (shape?.type === 'rect') shapeFields = `<label class="f"><span>פינות (pt)</span><input data-s="radius" inputmode="decimal" dir="ltr" value="${n(shape.radius)}"></label>`;
  if (shape?.type === 'polygon') shapeFields = `<label class="f"><span>צלעות</span><input data-s="sides" type="number" min="3" max="64" dir="ltr" value="${shape.sides}"></label>`;
  if (shape?.type === 'star') shapeFields = `<label class="f"><span>קודקודים</span><input data-s="points" type="number" min="3" max="64" dir="ltr" value="${shape.points}"></label>
      <label class="f"><span>עומק (%)</span><input data-s="inner" type="number" min="5" max="100" dir="ltr" value="${Math.round((shape.inner ?? .5) * 100)}"></label>`;

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

    ${isLine ? '' : `<section class="group" aria-labelledby="g-fill">
      <div class="g-row"><h3 id="g-fill">מילוי</h3>
        <label class="switch"><input type="checkbox" data-on="fill"${fill?.color ? ' checked' : ''}><span class="sr">מילוי</span></label></div>
      <div class="g-row"${fill?.color ? '' : ' hidden'}>
        <input type="color" data-c="fill" value="${esc(fill?.color || '#2d4a8a')}" aria-label="צבע מילוי">
        <label class="f grow"><span>אטימות (%)</span><input data-o="fill" type="number" min="0" max="100" dir="ltr" value="${Math.round((fill?.opacity ?? 1) * 100)}"></label>
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

  el.querySelector('[data-act="duplicate"]').addEventListener('click', () => ctx.action('duplicate'));
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
    const color = el.querySelector(`[data-c="${k}"]`).value;
    ctx.edit(each(path => {
      if (!cb.checked) path[k] = null;
      else path[k] = k === 'fill' ? { color } : { color, width: 1, cap: 'butt', join: 'miter', dash: [] };
    }), true);
  }));

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
    ctx.edit(each(path => {
      if (!path.stroke) return;
      if (k === 'dash') path.stroke.dash = DASHES[v].map(d => d * Math.max(1, path.stroke.width ?? 1));
      else path.stroke[k] = v;
    }), true);
  }));
}
