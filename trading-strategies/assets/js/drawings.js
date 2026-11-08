// ציור על הגרף — spec-stage-a פרק 7 (וטלפון: פרק 1 — סרגל צף, לחיצה ארוכה לנקודת עיגון).
//
// שרטוט = סוג + נקודות עיגון {t: זמן בשניות UTC, p: מחיר} + סגנון. כי העיגון בזמן ולא באינדקס נר, אותו שרטוט נופל
// באותו מקום בכל מסגרת זמן — הזמן ממופה לאינדקס "לוגי" על ציר הנרות הנוכחי (גם בין נרות וגם אחרי האחרון).
// ציור: Primitive של Lightweight Charts (series.attachPrimitive) — מצייר את כל השרטוטים על הקנבס של הגרף.
// שמירה: לשרת רק בסוף שינוי (שחרור אצבע/עכבר), לא בכל תזוזה. ביטול ושחזור — בדפדפן, וכל צעד נשמר לשרת.
//
// מחשב: לחיצה מניחה נקודה; גרירה רגילה ממשיכה להזיז את הגרף.
// מגע: גרירה מזיזה את הגרף; לחיצה ארוכה (0.45 שנ') מניחה נקודה, וממשיכים לגרור לנקודה השנייה ומשחררים.

export const KINDS = {
  trend: { n: 2, icon: '╱', label: 'קו מגמה' },
  hline: { n: 1, icon: '─', label: 'קו אופקי' },
  ray: { n: 2, icon: '↗', label: 'קרן' },
  rect: { n: 2, icon: '▭', label: 'מלבן' },
  fib: { n: 2, icon: '𝐅', label: 'פיבונאצ׳י' },
  text: { n: 1, icon: 'T', label: 'טקסט' },
  arrow: { n: 2, icon: '➚', label: 'חץ' },
};
export const COLORS = ['#2d4a8a', '#b3261e', '#1e7b3a', '#e19a00', '#7a3fa0', '#5d6475'];
const FIB = [0, 0.236, 0.382, 0.5, 0.618, 0.786, 1];
const LONG_PRESS = 450, MOVE_TOL = 8, HIT = 10;

/** זמן ↔ אינדקס לוגי על ציר הנרות (מחוץ לנתונים — לפי אורך המסגרת). פונקציות טהורות, לבדיקה. */
export function timeToLogical(times, sec, t) {
  const n = times.length;
  if (!n) return null;
  if (t <= times[0]) return (t - times[0]) / sec;
  if (t >= times[n - 1]) return n - 1 + (t - times[n - 1]) / sec;
  let lo = 0, hi = n - 1;
  while (hi - lo > 1) { const m = (lo + hi) >> 1; if (times[m] <= t) lo = m; else hi = m; }
  return lo + (t - times[lo]) / (times[hi] - times[lo]);
}
export function logicalToTime(times, sec, l) {
  const n = times.length;
  if (!n) return null;
  if (l <= 0) return Math.round(times[0] + l * sec);
  if (l >= n - 1) return Math.round(times[n - 1] + (l - (n - 1)) * sec);
  const i = Math.floor(l);
  return Math.round(times[i] + (l - i) * (times[i + 1] - times[i]));
}

/** מרחק נקודה מקטע */
function distSeg(px, py, ax, ay, bx, by) {
  const dx = bx - ax, dy = by - ay, L = dx * dx + dy * dy;
  const u = L ? Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / L)) : 0;
  return Math.hypot(px - (ax + u * dx), py - (ay + u * dy));
}

export function mountDrawings({ chart, series, box, asset, api, toast, h, sheet, getTf, getSec, getTimes, fmtPrice }) {
  let all = [];               // כל השרטוטים של הנכס (כל המסגרות)
  let selected = null;        // id
  let tool = null;            // null = בחירה
  let pending = null;         // {kind, points:[...]} — בתהליך הנחה
  let hover = null;           // נקודת העכבר/האצבע לתצוגה מקדימה
  let drag = null;            // {id, idx|null, start:{x,y}, orig:points}
  let onlyTf = false;         // "רק במסגרת הזו" לשרטוטים חדשים
  let style = { color: COLORS[0], width: 2 };
  const undo = [], redo = [];
  let requestUpdate = () => {};

  const visible = () => all.filter(d => d.tf === null || d.tf === getTf());
  const xOf = t => { const l = timeToLogical(getTimes(), getSec(), t); return l === null ? null : chart.timeScale().logicalToCoordinate(l); };
  const yOf = p => series.priceToCoordinate(p);
  const toAnchor = (x, y) => {
    const l = chart.timeScale().coordinateToLogical(x);
    const p = series.coordinateToPrice(y);
    if (l === null || p === null) return null;
    return { t: logicalToTime(getTimes(), getSec(), l), p: +p };
  };

  // ── ציור על הקנבס ───────────────────────────────────────
  function drawOne(ctx, d, w, hgt, preview = false) {
    const pts = d.points.map(a => [xOf(a.t), yOf(a.p)]);
    if (pts.some(([x, y]) => x === null || y === null)) return;
    const st = d.style || {};
    ctx.strokeStyle = ctx.fillStyle = st.color || COLORS[0];
    ctx.lineWidth = st.width || 2;
    ctx.setLineDash(preview ? [6, 4] : []);
    const line = (a, b) => { ctx.beginPath(); ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]); ctx.stroke(); };
    const [a, b] = pts;
    switch (d.kind) {
      case 'trend': line(a, b); break;
      case 'arrow': {
        line(a, b);
        const ang = Math.atan2(b[1] - a[1], b[0] - a[0]), s = 8 + 2 * (st.width || 2);
        ctx.beginPath(); ctx.moveTo(b[0], b[1]);
        ctx.lineTo(b[0] - s * Math.cos(ang - 0.45), b[1] - s * Math.sin(ang - 0.45));
        ctx.lineTo(b[0] - s * Math.cos(ang + 0.45), b[1] - s * Math.sin(ang + 0.45));
        ctx.closePath(); ctx.fill();
        break;
      }
      case 'ray': {
        const dx = b[0] - a[0], dy = b[1] - a[1], k = (w + hgt) * 4 / (Math.hypot(dx, dy) || 1);
        line(a, [a[0] + dx * k, a[1] + dy * k]);
        break;
      }
      case 'hline': {
        line([0, a[1]], [w, a[1]]);
        ctx.font = '11px system-ui, sans-serif';
        ctx.fillText(fmtPrice(d.points[0].p), 4, a[1] - 4);
        break;
      }
      case 'rect': {
        const x = Math.min(a[0], b[0]), y = Math.min(a[1], b[1]), rw = Math.abs(b[0] - a[0]), rh = Math.abs(b[1] - a[1]);
        ctx.globalAlpha = 0.15; ctx.fillRect(x, y, rw, rh); ctx.globalAlpha = 1; ctx.strokeRect(x, y, rw, rh);
        break;
      }
      case 'fib': {
        const x1 = Math.min(a[0], b[0]), x2 = Math.max(a[0], b[0]);
        ctx.font = '10px system-ui, sans-serif';
        for (const lv of FIB) {
          const price = d.points[0].p + (d.points[1].p - d.points[0].p) * lv;
          const y = yOf(price);
          if (y === null) continue;
          ctx.globalAlpha = lv === 0 || lv === 1 ? 1 : 0.7;
          line([x1, y], [x2, y]);
          ctx.fillText(`${lv} (${fmtPrice(price)})`, x1 + 3, y - 3);
        }
        ctx.globalAlpha = 1;
        line(a, b);
        break;
      }
      case 'text': {
        ctx.font = `${10 + 2 * (st.width || 2)}px system-ui, sans-serif`;
        ctx.fillText(st.text || '', a[0], a[1]);
        break;
      }
    }
    if (!preview && d.id === selected) {
      ctx.setLineDash([]);
      for (const [x, y] of pts) {
        ctx.beginPath(); ctx.arc(x, y, 6, 0, Math.PI * 2);
        ctx.fillStyle = '#fff'; ctx.fill(); ctx.lineWidth = 2; ctx.strokeStyle = st.color; ctx.stroke();
      }
    }
  }
  const primitive = {
    attached(p) { requestUpdate = p.requestUpdate; },
    detached() { requestUpdate = () => {}; },
    updateAllViews() {},
    paneViews() {
      return [{
        zOrder: () => 'top',
        renderer: () => ({
          draw(target) {
            target.useMediaCoordinateSpace(({ context: ctx, mediaSize }) => {
              ctx.save();
              for (const d of visible()) drawOne(ctx, d, mediaSize.width, mediaSize.height);
              if (pending) {
                const pts = [...pending.points];
                if (hover && pts.length < KINDS[pending.kind].n) pts.push(hover);
                if (pts.length === KINDS[pending.kind].n) drawOne(ctx, { ...pending, points: pts, style: { ...style, text: pending.text } }, mediaSize.width, mediaSize.height, true);
                else for (const a of pts) {
                  const x = xOf(a.t), y = yOf(a.p);
                  if (x !== null && y !== null) { ctx.fillStyle = style.color; ctx.beginPath(); ctx.arc(x, y, 5, 0, Math.PI * 2); ctx.fill(); }
                }
              }
              ctx.restore();
            });
          },
        }),
      }];
    },
  };
  series.attachPrimitive(primitive);

  // ── בחירה (hit test) ────────────────────────────────────
  function hitTest(x, y) {
    const w = box.clientWidth;
    let best = null;
    for (const d of visible().slice().reverse()) {
      const pts = d.points.map(a => [xOf(a.t), yOf(a.p)]);
      if (pts.some(([px, py]) => px === null || py === null)) continue;
      for (let i = 0; i < pts.length; i++) if (d.id === selected && Math.hypot(x - pts[i][0], y - pts[i][1]) <= HIT + 4) return { id: d.id, idx: i };
      let dist = Infinity;
      const [a, b] = pts;
      if (d.kind === 'hline') dist = Math.abs(y - a[1]);
      else if (d.kind === 'text') dist = Math.hypot(x - a[0] - 20, y - a[1] + 6) - 20;
      else if (d.kind === 'rect') {
        const x1 = Math.min(a[0], b[0]), x2 = Math.max(a[0], b[0]), y1 = Math.min(a[1], b[1]), y2 = Math.max(a[1], b[1]);
        dist = Math.min(distSeg(x, y, x1, y1, x2, y1), distSeg(x, y, x2, y1, x2, y2), distSeg(x, y, x2, y2, x1, y2), distSeg(x, y, x1, y2, x1, y1));
        if (x > x1 && x < x2 && y > y1 && y < y2) dist = Math.min(dist, 4);
      } else if (d.kind === 'ray') {
        const dx = b[0] - a[0], dy = b[1] - a[1], k = w * 4 / (Math.hypot(dx, dy) || 1);
        dist = distSeg(x, y, a[0], a[1], a[0] + dx * k, a[1] + dy * k);
      } else if (d.kind === 'fib') {
        const x1 = Math.min(a[0], b[0]), x2 = Math.max(a[0], b[0]);
        for (const lv of FIB) { const yy = yOf(d.points[0].p + (d.points[1].p - d.points[0].p) * lv); if (yy !== null) dist = Math.min(dist, distSeg(x, y, x1, yy, x2, yy)); }
      } else dist = distSeg(x, y, a[0], a[1], b[0], b[1]);
      if (dist <= HIT && (!best || dist < best.dist)) best = { id: d.id, idx: null, dist };
    }
    return best;
  }

  // ── שמירה, ביטול ושחזור ─────────────────────────────────
  const body = d => ({ id: d.id ?? undefined, asset, tf: d.tf, kind: d.kind, points: d.points, style: d.style });
  const replaceId = (oldId, newId) => {
    for (const st of [undo, redo]) for (const op of st) for (const k of ['before', 'after']) if (op[k]?.id === oldId) op[k] = { ...op[k], id: newId };
    if (selected === oldId) selected = newId;
  };
  async function apply(before, after, record = true) {
    try {
      if (after && after.id == null) {                              // יצירה
        const r = await api('drawing_save', body(after));
        all.push(r.drawing);
        after = r.drawing;
      } else if (after) {                                           // עדכון
        const r = await api('drawing_save', body(after));
        all = all.map(d => (d.id === r.drawing.id ? r.drawing : d));
        after = r.drawing;
      } else if (before) {                                          // מחיקה
        await api('drawing_delete', { id: before.id });
        all = all.filter(d => d.id !== before.id);
        if (selected === before.id) selected = null;
      }
      if (record) { undo.push({ before, after }); if (undo.length > 100) undo.shift(); redo.length = 0; }
      return after;
    } catch (e) {
      toast(e.message, true);
      await reload();
      return null;
    } finally {
      requestUpdate(); syncBar();
    }
  }
  async function doUndo() {
    const op = undo.pop(); if (!op) return;
    if (op.after && !op.before) { await apply(op.after, null, false); }                     // ביטול יצירה → מחיקה
    else if (op.before && !op.after) {                                                      // ביטול מחיקה → יצירה מחדש
      const re = await apply(null, { ...op.before, id: null }, false);
      if (re) { replaceId(op.before.id, re.id); op.before = re; }
    } else await apply(op.after, op.before, false);
    redo.push(op); syncBar();
  }
  async function doRedo() {
    const op = redo.pop(); if (!op) return;
    if (op.after && !op.before) {
      const re = await apply(null, { ...op.after, id: null }, false);
      if (re) { replaceId(op.after.id, re.id); op.after = re; }
    } else if (op.before && !op.after) await apply(op.before, null, false);
    else await apply(op.before, op.after, false);
    undo.push(op); syncBar();
  }
  async function reload() {
    try { all = (await api('drawings', null, { asset })).drawings; } catch (e) { toast(e.message, true); }
    requestUpdate(); syncBar();
  }

  // ── סיום הנחה ────────────────────────────────────────────
  async function addPoint(a) {
    if (!pending) pending = { kind: tool, points: [] };
    pending.points.push(a);
    if (pending.points.length < KINDS[pending.kind].n) { requestUpdate(); return; }
    const d = { id: null, kind: pending.kind, tf: onlyTf ? getTf() : null, points: pending.points, style: { ...style } };
    pending = null; hover = null;
    if (d.kind === 'text') {
      const input = h('input', { maxlength: 200, 'aria-label': 'טקסט' });
      const s = sheet('טקסט על הגרף', { onSave: async () => {
        if (!input.value.trim()) throw new Error('חסר טקסט');
        d.style.text = input.value.trim();
        const r = await apply(null, d);
        if (r) selected = r.id;
      } });
      s.body.append(input);
      setTimeout(() => input.focus(), 50);
    } else {
      const r = await apply(null, d);
      if (r) selected = r.id;
    }
    requestUpdate(); syncBar();
  }

  // ── אירועים ─────────────────────────────────────────────
  // כשאנחנו "תופסים" את המגע (גרירת נקודה, הנחה במגע) — עוצרים את האירועים לפני שהגרף מזיז את עצמו
  let capturing = false, pressTimer = null, downAt = null, downType = '';
  const stop = e => { if (capturing) { e.stopPropagation(); if (e.cancelable) e.preventDefault(); } };
  ['mousedown', 'mousemove', 'touchstart', 'touchmove', 'wheel'].forEach(ev => box.addEventListener(ev, stop, { capture: true, passive: false }));
  const local = e => { const r = box.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
  const inPlot = ({ x }) => x < box.clientWidth - (chart.priceScale('right').width() || 0);

  function onDown(e) {
    if (!active) return;
    const pt = local(e);
    if (!inPlot(pt)) return;
    downAt = pt; downType = e.pointerType;
    if (!tool) {                                                    // בחירה / גרירה
      const hit = hitTest(pt.x, pt.y);
      selected = hit ? hit.id : null;
      if (hit) {
        capturing = true;
        const d = all.find(x => x.id === hit.id);
        drag = { id: hit.id, idx: hit.idx, start: pt, orig: d.points.map(p => ({ ...p })), moved: false, beforeD: structuredClone(d) };
        box.setPointerCapture?.(e.pointerId);
      }
      requestUpdate(); syncBar();
      return;
    }
    if (e.pointerType === 'mouse') { capturing = true; return; }   // עכבר — לחיצה מניחה (ב-up)
    // מגע — לחיצה ארוכה מניחה נקודה; גרירה רגילה מזיזה את הגרף
    clearTimeout(pressTimer);
    pressTimer = setTimeout(() => {
      capturing = true;
      navigator.vibrate?.(15);
      const a = toAnchor(pt.x, pt.y);
      if (a) addPoint(a);
    }, LONG_PRESS);
  }
  function onMove(e) {
    if (!active) return;
    const pt = local(e);
    if (downAt && Math.hypot(pt.x - downAt.x, pt.y - downAt.y) > MOVE_TOL) clearTimeout(pressTimer);
    if (drag) {
      const d = all.find(x => x.id === drag.id);
      const a0 = toAnchor(drag.start.x, drag.start.y), a1 = toAnchor(pt.x, pt.y);
      if (!d || !a0 || !a1) return;
      drag.moved = true;
      const dt = a1.t - a0.t, dp = a1.p - a0.p;
      d.points = drag.orig.map((p, i) => (drag.idx === null || drag.idx === i ? { t: Math.round(p.t + dt), p: p.p + dp } : p));
      requestUpdate();
      return;
    }
    if (tool && (pending || e.pointerType === 'mouse' || capturing)) { hover = toAnchor(pt.x, pt.y); requestUpdate(); }
  }
  async function onUp(e) {
    clearTimeout(pressTimer);
    if (!active) { capturing = false; return; }
    const pt = local(e);
    if (drag) {
      const d = all.find(x => x.id === drag.id);
      const was = drag; drag = null; capturing = false;
      if (was.moved && d) await apply(was.beforeD, { ...d });       // נשמר בסוף הגרירה — לא בכל תזוזה
      return;
    }
    if (tool && downType === 'mouse' && downAt && Math.hypot(pt.x - downAt.x, pt.y - downAt.y) <= MOVE_TOL) {
      const a = toAnchor(pt.x, pt.y);
      if (a) await addPoint(a);
    } else if (tool && downType !== 'mouse' && capturing && pending) {
      // מגע: אחרי לחיצה ארוכה גוררים ומשחררים — השחרור הוא הנקודה השנייה (אם זזו)
      if (downAt && Math.hypot(pt.x - downAt.x, pt.y - downAt.y) > MOVE_TOL) { const a = toAnchor(pt.x, pt.y); if (a) await addPoint(a); }
    }
    capturing = false; downAt = null;
  }
  box.addEventListener('pointerdown', onDown, true);
  box.addEventListener('pointermove', onMove, true);
  box.addEventListener('pointerup', onUp, true);
  box.addEventListener('pointercancel', () => { clearTimeout(pressTimer); capturing = false; drag = null; }, true);
  const onKey = e => {
    if (!active || e.target.closest?.('input, textarea')) return;
    if ((e.key === 'Delete' || e.key === 'Backspace') && selected) { e.preventDefault(); del(); }
    else if (e.key === 'Escape') { pending = null; hover = null; setTool(null); }
    else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? doRedo() : doUndo(); }
  };
  document.addEventListener('keydown', onKey);

  // ── הסרגל הצף ────────────────────────────────────────────
  let active = false;
  const btn = (label, aria, fn, extra = {}) => h('button', { type: 'button', class: 'draw-btn', 'aria-label': aria, title: aria, on: { click: fn }, ...extra }, label);
  const toolBtns = {};
  const tools = h('div', { class: 'draw-row', role: 'toolbar', 'aria-label': 'כלי ציור' },
    toolBtns.select = btn('✋', 'בחירה והזזה', () => setTool(null)),
    Object.entries(KINDS).map(([k, v]) => (toolBtns[k] = btn(v.icon, v.label, () => setTool(k)))));
  const colorBtns = COLORS.map(c => btn('', 'צבע', () => setStyle({ color: c }), { style: `--c:${c}`, class: 'draw-btn swatch' }));
  const widthSel = h('select', { class: 'draw-width', 'aria-label': 'עובי', on: { change: () => setStyle({ width: +widthSel.value }) } },
    [1, 2, 3, 4].map(n => h('option', { value: n }, `${n}px`)));
  widthSel.value = '2';
  const onlyChk = h('input', { type: 'checkbox', on: { change: () => setOnlyTf(onlyChk.checked) } });
  const undoBtn = btn('↶', 'ביטול', doUndo), redoBtn = btn('↷', 'שחזור', doRedo), delBtn = btn('🗑', 'מחיקת השרטוט', () => del());
  const hint = h('p', { class: 'draw-hint', 'aria-live': 'polite' });
  const opts = h('div', { class: 'draw-row wrap' }, colorBtns, widthSel,
    h('label', { class: 'draw-only' }, onlyChk, h('span', {}, 'רק במסגרת הזו')), undoBtn, redoBtn, delBtn);
  const bar = h('div', { class: 'draw-bar', hidden: true }, tools, opts, hint);

  function setTool(k) {
    tool = k; pending = null; hover = null;
    if (k) selected = null;
    requestUpdate(); syncBar();
  }
  async function setStyle(patch) {
    style = { ...style, ...patch };
    const d = all.find(x => x.id === selected);
    if (d) await apply(structuredClone(d), { ...d, style: { ...d.style, ...patch } });
    syncBar();
  }
  async function setOnlyTf(on) {
    onlyTf = on;
    const d = all.find(x => x.id === selected);
    if (d) await apply(structuredClone(d), { ...d, tf: on ? getTf() : null });
    syncBar();
  }
  async function del() {
    const d = all.find(x => x.id === selected);
    if (d) await apply(structuredClone(d), null);
  }
  function syncBar() {
    const sel = all.find(x => x.id === selected);
    for (const [k, b] of Object.entries(toolBtns)) b.setAttribute('aria-pressed', String((k === 'select' && !tool) || k === tool));
    const st = sel ? sel.style : style;
    colorBtns.forEach((b, i) => b.setAttribute('aria-pressed', String(COLORS[i] === st.color)));
    widthSel.value = String(st.width || 2);
    onlyChk.checked = sel ? sel.tf !== null : onlyTf;
    undoBtn.disabled = !undo.length; redoBtn.disabled = !redo.length; delBtn.disabled = !sel;
    const touch = matchMedia('(pointer: coarse)').matches;
    hint.textContent = tool
      ? `${KINDS[tool].label}: ${touch ? 'לחיצה ארוכה' : 'לחיצה'} לנקודה${KINDS[tool].n > 1 ? (touch ? ', גוררים ומשחררים בשנייה' : ', ולחיצה שנייה לסיום') : ''}. ${visible().length}/${all.length} שרטוטים.`
      : sel ? 'גוררים את השרטוט או את הנקודות שלו. 🗑 — מחיקה.' : `בוחרים שרטוט בלחיצה, או כלי למעלה. ${all.length ? all.length + ' שרטוטים בנכס.' : ''}`;
  }
  function setActive(on) {
    active = on; bar.hidden = !on;
    if (!on) { tool = null; pending = null; hover = null; selected = null; capturing = false; }
    box.classList.toggle('drawing', on);
    requestUpdate(); syncBar();
  }

  return {
    bar,
    load: reload,
    toggle: () => setActive(!active),
    isActive: () => active,
    redraw: () => { requestUpdate(); syncBar(); },
    count: () => all.length,
    destroy() {
      document.removeEventListener('keydown', onKey);
      try { series.detachPrimitive(primitive); } catch { /* הגרף כבר הוסר */ }
    },
  };
}
