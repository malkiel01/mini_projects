// שכבת העל של הבמה: "נמלים צועדות" סביב הבחירה, ותצוגה מקדימה של צורה בזמן גרירה.
// קנבס דו-ממדי נפרד מעל קנבס ה-WebGL — קווים חדים בכל זום, בלי לגעת בפיקסלים של המסמך.

import { tracePath } from './engine/selection.js';

export function createOverlay(canvas, { doc, view }) {
  const ctx = canvas.getContext('2d');
  let preview = null;
  let box = null;   // { corners, handles } — שינוי צורה
  let phase = 0;
  let timer = 0;

  function size() {
    const r = canvas.parentElement;
    const dpr = devicePixelRatio || 1;
    const w = Math.round(r.clientWidth * dpr), h = Math.round(r.clientHeight * dpr);
    if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
    return dpr;
  }

  function ants(shapes, dpr, extraRect) {
    const v = view();
    const map = (x, y) => [Math.round((v.x + x * v.zoom) * dpr) + 0.5, Math.round((v.y + y * v.zoom) * dpr) + 0.5];
    ctx.beginPath();
    for (const s of shapes) tracePath(ctx, s, map);
    if (extraRect) tracePath(ctx, { type: 'rect', ...extraRect }, map);
    ctx.lineWidth = Math.max(1, Math.round(dpr));
    ctx.setLineDash([]);
    ctx.strokeStyle = '#fff';
    ctx.stroke();
    ctx.setLineDash([4 * dpr, 4 * dpr]);
    ctx.lineDashOffset = -phase * dpr;
    ctx.strokeStyle = '#000';
    ctx.stroke();
  }

  function draw() {
    const dpr = size();
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const d = doc();
    if (!d) return;
    const sel = d.selection;
    // בזמן שינוי צורה הבחירה זזה עם התוכן — במקום הנמלים, המסגרת
    if (sel && !box) ants(sel.shapes, dpr, sel.inverted ? { x: 0, y: 0, w: d.width, h: d.height } : null);
    if (preview) ants([preview], dpr);
    if (box) {
      const v = view();
      const map = ([x, y]) => [(v.x + x * v.zoom) * dpr, (v.y + y * v.zoom) * dpr];
      ctx.setLineDash([]);
      ctx.lineWidth = Math.max(1, dpr);
      ctx.beginPath();
      box.corners.map(map).forEach((p, i) => (i ? ctx.lineTo(...p) : ctx.moveTo(...p)));
      ctx.closePath();
      ctx.strokeStyle = '#2d4a8a';
      ctx.stroke();
      const r = 4 * dpr;
      for (const p of box.handles.map(map)) {
        ctx.fillStyle = '#fff';
        ctx.fillRect(p[0] - r, p[1] - r, r * 2, r * 2);
        ctx.strokeRect(p[0] - r, p[1] - r, r * 2, r * 2);
      }
    }
    // הנמלים זזות רק כשיש מה להראות
    const live = !!((sel && !box) || preview);
    if (live && !timer) timer = setInterval(() => { phase = (phase + 1) % 8; draw(); }, 120);
    if (!live && timer) { clearInterval(timer); timer = 0; }
  }

  return {
    draw,
    /** צורה בזמן גרירה (או null) */
    preview(shape) { preview = shape; draw(); },
    /** מסגרת שינוי הצורה עם הידיות (או null) */
    box(corners, handles) { box = corners ? { corners, handles } : null; draw(); },
  };
}
