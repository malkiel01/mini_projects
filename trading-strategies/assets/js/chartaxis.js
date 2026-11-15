// הצירים והתוויות של הגרף — spec-stage-a 18.1.2 (F1), סעיפים 4–5.
//
// **הצירים בתוך הגרף (4):** אין עמודה למחירים ואין פס לזמן. המספרים מצוירים על הקנבס, צמודים לקצה, בגופן קטן ושקופים
// למחצה, **מתחת** לנרות (zOrder: bottom) — כך שמספר לא מסתיר נר, והנר האחרון מגיע עד הקצה.
// פורמט: מפריד אלפים, ורק כמה ספרות שהמחיר צריך — `82,000` ולא `82000.00`.
//
// **התוויות (5):** כל תווית על הגרף (שיטות ושרטוטים) נרשמת בלוח אחד לכל ציור. תווית שחופפת לתווית שכבר נרשמה — לא
// מצוירת. מי שמצייר קודם (השיטה המובילה, התרחיש המוביל) — גובר.

/** מספר לתצוגה: מפריד אלפים, עד `dec` ספרות אחרי הנקודה, בלי אפסים מיותרים בסוף (float — תצוגה בלבד) */
export function fmtNum(v, dec = 2) {
  const n = +v;
  if (!Number.isFinite(n)) return '';
  return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: Math.max(0, Math.min(dec, 10)) });
}

/**
 * לוח התוויות של ציור אחד. כל מצייר קורא begin(owner) בתחילת הציור שלו; כשאותו מצייר מתחיל שוב — זה ציור חדש,
 * והלוח מתרוקן. כך הלוח משותף לכמה Primitives בלי לדעת מי מצייר ראשון.
 */
export function labelBoard() {
  let rects = [];
  let seen = new Set();
  const hit = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
  return {
    begin(owner) {
      if (seen.has(owner)) { rects = []; seen = new Set(); }
      seen.add(owner);
    },
    /** מקום פנוי → נרשם ומחזיר true; חופף → false (לא מציירים) */
    place(r) {
      if (rects.some(o => hit(o, r))) return false;
      rects.push(r);
      return true;
    },
    rects: () => rects.map(r => ({ ...r })),
  };
}

const STEPS = [1, 2, 2.5, 5, 10];
function niceStep(raw) {
  if (!(raw > 0)) return 1;
  const e = 10 ** Math.floor(Math.log10(raw));
  return (STEPS.find(s => s * e >= raw) || 10) * e;
}
// מרווחים בין סימוני זמן, בשניות — הקטן שמשאיר לפחות 72 פיקסלים בין תווית לתווית
const MIN = 60, HOUR = 3600, DAY = 86400;
const T_STEPS = [MIN, 5 * MIN, 15 * MIN, 30 * MIN, HOUR, 2 * HOUR, 4 * HOUR, 6 * HOUR, 12 * HOUR, DAY, 2 * DAY, 7 * DAY, 14 * DAY,
  30 * DAY, 91 * DAY, 182 * DAY, 365 * DAY, 730 * DAY, 1825 * DAY];

/**
 * צירים בתוך הגרף. series — הסדרה שלפיה מחשבים מחירים; time: false — רק ציר מחירים (חלון ה-RSI/ATR).
 * getDec() — הספרות של הנכס (לפי ה-tick); fmtTime(t, kind) — הטקסט של סימון זמן. מחזיר destroy().
 */
export function mountAxis({ chart, series, col, getDec, getTimes = () => [], fmtTime = () => '', time = true }) {
  let requestUpdate = () => {};
  let shown = { price: [], time: [] };   // מה צויר בפעם האחרונה — לבדיקות
  let cross = null;   // {x, y, t} — הסמן, כדי להראות את המחיר והזמן שלו (אין עוד ציר שמראה אותם)
  const onCross = p => { cross = p?.point && p.time !== undefined ? { x: p.point.x, y: p.point.y, t: p.time } : p?.point ? { x: p.point.x, y: p.point.y, t: null } : null; requestUpdate(); };
  chart.subscribeCrosshairMove(onCross);

  function chip(ctx, s, x, y, align) {
    ctx.font = '600 10px system-ui, sans-serif';
    const w = ctx.measureText(s).width + 8;
    const left = align === 'right' ? x - w : align === 'center' ? x - w / 2 : x;
    ctx.globalAlpha = 1;
    ctx.fillStyle = col('--ink');
    ctx.fillRect(left, y - 8, w, 16);
    ctx.fillStyle = col('--surface');
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(s, left + 4, y);
  }

  function priceTicks(ctx, W, H) {
    const top = series.coordinateToPrice(0), bot = series.coordinateToPrice(H);
    shown.price = [];
    if (top === null || bot === null || !(top > bot)) return;
    const step = niceStep((top - bot) / Math.max(2, H / 56));
    const dec = Math.min(getDec(), Math.max(0, Math.ceil(-Math.log10(step) - 1e-9)));
    ctx.font = '10px system-ui, sans-serif';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'bottom';
    for (let v = Math.ceil(bot / step) * step; v <= top; v += step) {
      const y = series.priceToCoordinate(v);
      if (y === null || y < 14 || y > H - (time ? 18 : 4)) continue;
      ctx.globalAlpha = 0.5;
      ctx.strokeStyle = col('--line');
      ctx.beginPath(); ctx.moveTo(0, Math.round(y) + 0.5); ctx.lineTo(W, Math.round(y) + 0.5); ctx.stroke();
      ctx.globalAlpha = 0.72;
      ctx.fillStyle = col('--muted');
      const s = fmtNum(v, dec);
      shown.price.push(s);
      ctx.fillText(s, W - 4, y - 2);
    }
  }

  function timeTicks(ctx, W, H) {
    const ts = chart.timeScale();
    const lr = ts.getVisibleLogicalRange();
    const times = getTimes();
    shown.time = [];
    if (!lr || times.length < 2) return;
    const i0 = Math.max(0, Math.floor(lr.from)), i1 = Math.min(times.length - 1, Math.ceil(lr.to));
    if (i1 <= i0) return;
    const pxPerSec = W / Math.max(1, (lr.to - lr.from) * ((times.at(-1) - times[0]) / (times.length - 1)));
    const step = T_STEPS.find(s => s * pxPerSec >= 72) || T_STEPS.at(-1);
    const kind = step >= 365 * DAY ? 'year' : step >= 30 * DAY ? 'month' : step >= DAY ? 'day' : 'time';
    const key = t => {
      const d = new Date(t * 1000);
      if (kind === 'year') return Math.floor(d.getUTCFullYear() / (step / (365 * DAY)));
      if (kind === 'month') return Math.floor((d.getUTCFullYear() * 12 + d.getUTCMonth()) / Math.round(step / (30 * DAY)));
      return Math.floor(t / step);
    };
    ctx.font = '10px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'bottom';
    let lastRight = -Infinity;
    for (let i = i0 + 1; i <= i1; i++) {
      if (key(times[i]) === key(times[i - 1])) continue;
      const x = ts.logicalToCoordinate(i);
      if (x === null || x < 0 || x > W) continue;
      const s = fmtTime(times[i], kind);
      const w = ctx.measureText(s).width;
      if (x - w / 2 < lastRight + 8 || x + w / 2 > W - 2 || x - w / 2 < 2) continue;
      lastRight = x + w / 2;
      shown.time.push(s);
      ctx.globalAlpha = 0.35;
      ctx.strokeStyle = col('--line');
      ctx.beginPath(); ctx.moveTo(Math.round(x) + 0.5, 0); ctx.lineTo(Math.round(x) + 0.5, H); ctx.stroke();
      ctx.globalAlpha = 0.72;
      ctx.fillStyle = col('--muted');
      ctx.fillText(s, x, H - 3);
    }
  }

  const bottom = {
    zOrder: () => 'bottom',
    renderer: () => ({
      draw(target) {
        target.useMediaCoordinateSpace(({ context: ctx, mediaSize: { width: W, height: H } }) => {
          ctx.save();
          priceTicks(ctx, W, H);
          if (time) timeTicks(ctx, W, H);
          ctx.restore();
        });
      },
    }),
  };
  // המחיר והזמן של הסמן — מעל הכול, בקצוות
  const top = {
    zOrder: () => 'top',
    renderer: () => ({
      draw(target) {
        if (!cross) return;
        target.useMediaCoordinateSpace(({ context: ctx, mediaSize: { width: W, height: H } }) => {
          ctx.save();
          const p = series.coordinateToPrice(cross.y);
          if (p !== null) chip(ctx, fmtNum(p, getDec()), W - 2, Math.min(Math.max(cross.y, 9), H - 9), 'right');
          if (time && cross.t !== null) {
            const s = fmtTime(cross.t, 'cross');
            ctx.font = '600 10px system-ui, sans-serif';
            const half = ctx.measureText(s).width / 2 + 6;
            chip(ctx, s, Math.min(Math.max(cross.x, half), W - half), H - 9, 'center');
          }
          ctx.restore();
        });
      },
    }),
  };
  const primitive = {
    attached(p) { requestUpdate = p.requestUpdate; },
    detached() { requestUpdate = () => {}; },
    updateAllViews() {},
    paneViews: () => [bottom, top],
  };
  series.attachPrimitive(primitive);
  return {
    update: () => requestUpdate(),
    shown: () => ({ price: [...shown.price], time: [...shown.time] }),
    destroy() {
      try { chart.unsubscribeCrosshairMove(onCross); series.detachPrimitive(primitive); } catch { /* ריק */ }
    },
  };
}
