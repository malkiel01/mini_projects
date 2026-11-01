// שכבת העל של הבמה: "נמלים צועדות" סביב הבחירה, תצוגה מקדימה של צורה בזמן גרירה, האזור המוחשך של החיתוך ונקודת המקור
// של חותמת השכפול. קנבס דו-ממדי נפרד מעל קנבס ה-WebGL — קווים חדים בכל זום, בלי לגעת בפיקסלים של המסמך.
// הידיות (שינוי צורה וחיתוך) — handles.js של ui-core, ב-SVG מעל אותה במה (host); onDraw — לעדכן אותן בזום / הזזה.

import { tracePath } from './engine/selection.js';

export function createOverlay(canvas, { doc, view }) {
  const ctx = canvas.getContext('2d');
  let preview = null;
  let box = false;  // שינוי צורה פתוח — הבחירה זזה עם התוכן, ולכן בלי נמלים
  const hooks = [];
  let crop = null;  // { x0, y0, x1, y1 } — מה שמחוץ לו מוחשך
  let mark = null;  // { p: [x, y], r } — נקודת המקור של החותמת, במסמך
  let grid = null;  // { pts, cols } — נקודות השליטה של עיוות (2×2 פינות / 4×4 Warp), במסמך
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
    if (crop) {
      const v = view();
      const X = x => (v.x + x * v.zoom) * dpr, Y = y => (v.y + y * v.zoom) * dpr;
      ctx.beginPath();
      ctx.rect(0, 0, canvas.width, canvas.height);
      ctx.rect(X(crop.x0), Y(crop.y0), X(crop.x1) - X(crop.x0), Y(crop.y1) - Y(crop.y0));
      ctx.fillStyle = 'rgba(10, 15, 30, .5)';
      ctx.fill('evenodd');
    }
    if (grid) {
      // קווי הרשת בין נקודות השליטה, ועיגול לכל נקודה (בגודל קבוע במסך)
      const v = view();
      const S = ([x, y]) => [(v.x + x * v.zoom) * dpr, (v.y + y * v.zoom) * dpr];
      // cols = 0 — נקודות בלי קווים (סיכות של Puppet)
      const { pts, cols } = grid, rows = cols ? pts.length / cols : 0, at = (i, j) => S(pts[j * cols + i]);
      ctx.setLineDash([]);
      ctx.beginPath();
      if (!cols) { /* בלי קווים */ } else if (cols === 2) { [at(0, 0), at(1, 0), at(1, 1), at(0, 1)].forEach((p, i) => (i ? ctx.lineTo(...p) : ctx.moveTo(...p))); ctx.closePath(); }
      else {
        for (let j = 0; j < rows; j++) for (let i = 0; i < cols; i++) {
          if (i < cols - 1) { ctx.moveTo(...at(i, j)); ctx.lineTo(...at(i + 1, j)); }
          if (j < rows - 1) { ctx.moveTo(...at(i, j)); ctx.lineTo(...at(i, j + 1)); }
        }
      }
      ctx.lineWidth = Math.max(1, dpr); ctx.strokeStyle = 'rgba(45, 74, 138, .9)'; ctx.stroke();
      for (const p of pts.map(S)) {
        ctx.beginPath(); ctx.arc(p[0], p[1], 6 * dpr, 0, Math.PI * 2);
        ctx.fillStyle = '#fff'; ctx.fill(); ctx.strokeStyle = '#2d4a8a'; ctx.stroke();
      }
    }
    if (mark) {
      const v = view();
      const [x, y] = [(v.x + mark.p[0] * v.zoom) * dpr, (v.y + mark.p[1] * v.zoom) * dpr];
      const r = Math.max(6 * dpr, mark.r * v.zoom * dpr);
      ctx.setLineDash([]);
      ctx.beginPath();
      ctx.arc(x, y, r, 0, Math.PI * 2);
      ctx.moveTo(x - 5 * dpr, y); ctx.lineTo(x + 5 * dpr, y);
      ctx.moveTo(x, y - 5 * dpr); ctx.lineTo(x, y + 5 * dpr);
      ctx.lineWidth = 3 * dpr; ctx.strokeStyle = 'rgba(0, 0, 0, .55)'; ctx.stroke();
      ctx.lineWidth = Math.max(1, dpr); ctx.strokeStyle = '#fff'; ctx.stroke();
    }
    // הנמלים זזות רק כשיש מה להראות
    const live = !!((sel && !box) || preview);
    if (live && !timer) timer = setInterval(() => { phase = (phase + 1) % 8; draw(); }, 120);
    if (!live && timer) { clearInterval(timer); timer = 0; }
  }

  const drawAll = () => { draw(); for (const f of hooks) f(); };
  return {
    draw: drawAll,
    /** האלמנט של הבמה — שם יושבות הידיות של ui-core */
    host: canvas.parentElement,
    /** נקרא בכל ציור (זום, הזזה) — הידיות מתעדכנות */
    onDraw(f) { hooks.push(f); },
    /** צורה בזמן גרירה (או null) */
    preview(shape) { preview = shape; draw(); },
    /** שינוי צורה פתוח (המסגרת והידיות — handles.js) */
    box(on) { box = !!on; draw(); },
    /** אזור חיתוך — מחוץ לו מוחשך (או null) */
    crop(r) { crop = r; draw(); },
    /** נקודות השליטה של עיוות (או null): pts במסמך, cols — 2 (פינות, פרספקטיבה), 4 (Warp) או 0 (סיכות, בלי קווים) */
    grid(pts, cols = 2) { grid = pts ? { pts, cols } : null; draw(); },
    /** נקודת המקור של חותמת השכפול (או null); r — רדיוס המכחול בפיקסלים של המסמך */
    mark(p, r = 0) { mark = p ? { p, r } : null; draw(); },
  };
}
