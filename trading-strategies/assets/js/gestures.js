// תנועות מגע על הגרף בטלפון (מטלות דחופות מהבעלים, 2026-11-16).
//
// - **אצבע אחת לרוחב** — הספרייה מזיזה בזמן (`horzTouchDrag`, כמו תמיד). הבעלים: "אצבע אחת גוללת וזה מושלם" — לא נוגעים.
// - **אצבע אחת לגובה** — גוללת את **הדף**: `handleScroll.vertTouchDrag: false` ו-`touch-action: pan-y` (CSS).
// - **שתי אצבעות** — כאן, לא בספרייה (`handleScale.pinch: false`; האירועים נעצרים ב-capture לפני הספרייה). נעילה אחרי 10px,
//   לפי היחס בין שינוי המרחק בין האצבעות לבין תזוזת המרכז שלהן:
//   - **צביטה** (המרחק משתנה יותר) — הכיוון לפי הזווית בין האצבעות. **אופקית** — טווח הזמן (מקרבים = פחות נרות, גדולים יותר,
//     סביב הנקודה שבין האצבעות). **אנכית** — מכווצת את הגרף במאונך: הנרות, השכבות (אליוט, אינדיקטורים — אותו ציר)
//     והנפח; מתיחה חוזרת עד הגובה הרגיל.
//   - **גרירה לגובה** (המרכז זז יותר, ובעיקר במאונך) — מזיזה את ציר המחיר: הנרות זזים עם האצבעות. ההזזה היא היסט קבוע על
//     הטווח של autoScale (`autoscaleInfoProvider`), כך שהיא לא קופצת חזרה.
//   - **גרירה לרוחב בשתי אצבעות — אין** (הבעלים: "2 אצבעות רק לגובה"). התנועה ננעלת ולא עושה כלום.
// - **"↺"** (בפינת הגרף, רק כשציר המחיר זז או כווץ) — מחזיר את ציר המחיר לרגיל.
// - בזמן שהשרטוט תופס את המגע (`blocked()`) — לא נוגעים.
// הקואורדינטות דרך clientX/clientY — כך גם בשכבה המסובבת (rotate.js ממיר אותן).

// ציר המחיר של הנרות (series.priceScale() — לא priceScale('right'), שלא חל על הנרות כששני הצירים מוסתרים):
// ברירת המחדל של הספרייה (0.2 למעלה, 0.1 למטה); הנפח — 0.82/0.05 (market.js)
const PRICE = { top: 0.2, bottom: 0.1 };
const VOL = { top: 0.82, bottom: 0.05 };
const MIN_K = 0.25;     // הכי מכווץ: רבע מהגובה
const MIN_BARS = 8;     // הכי מקורב: 8 נרות על המסך
const SLOP2 = 10;       // שתי אצבעות: עד שמחליטים צביטה או גרירה

export function mountGestures({ chart, series, shifted = [series], box, blocked = () => false }) {
  chart.applyOptions({ handleScroll: { vertTouchDrag: false }, handleScale: { pinch: false } });
  let k = 1;            // הגובה היחסי (1 = רגיל)
  let off = 0;          // היסט ציר המחיר (במחיר; 0 = רגיל)
  let g = null;         // התנועה שבעבודה

  // ── ציר המחיר: כיווץ (scaleMargins) והיסט (autoscaleInfoProvider) ──
  const provider = orig => {
    const r = orig();
    if (!r || !off || !r.priceRange) return r;
    return { ...r, priceRange: { minValue: r.priceRange.minValue + off, maxValue: r.priceRange.maxValue + off } };
  };
  for (const s of shifted) s.applyOptions({ autoscaleInfoProvider: provider });

  const reset = document.createElement('button');
  reset.type = 'button';
  reset.className = 'chart-reset';
  reset.textContent = '↺';
  reset.title = 'ציר המחיר לרגיל';
  reset.setAttribute('aria-label', 'החזרת ציר המחיר לרגיל');
  reset.hidden = true;
  reset.addEventListener('click', e => { e.stopPropagation(); k = 1; off = 0; applyPrice(); });
  box.append(reset);

  function applyPrice() {
    const m = (1 - PRICE.top - PRICE.bottom) * (1 - k) / 2;
    series.priceScale().applyOptions({ scaleMargins: { top: PRICE.top + m, bottom: PRICE.bottom + m } });
    const vh = 1 - VOL.top - VOL.bottom;
    chart.priceScale('vol').applyOptions({ scaleMargins: { top: 1 - VOL.bottom - vh * k, bottom: VOL.bottom } });
    for (const s of shifted) s.applyOptions({ autoscaleInfoProvider: provider });   // מחשב מחדש את autoScale
    reset.hidden = k === 1 && off === 0;
  }

  // ── עזרים ──
  const ts = () => chart.timeScale();
  const local = p => { const r = box.getBoundingClientRect(); return { x: p.x - r.left, y: p.y - r.top }; };
  const pts = e => [...e.touches].slice(0, 2).map(t => ({ x: t.clientX, y: t.clientY }));
  const mid = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

  // ── התחלה ──
  function begin(e) {
    const r = ts().getVisibleLogicalRange();
    const [a, b] = pts(e);
    const c = mid(a, b);
    g = { a0: a, b0: b, c0: c, d0: dist(a, b), mode: null, r0: r, k0: k, off0: off,
      vertical: Math.abs(a.y - b.y) > Math.abs(a.x - b.x),
      ax0: Math.max(Math.abs(a.y - b.y) > Math.abs(a.x - b.x) ? Math.abs(a.y - b.y) : Math.abs(a.x - b.x), 20),
      anchor: ts().coordinateToLogical(local(c).x) ?? (r ? (r.from + r.to) / 2 : 0),
      // מחיר לפיקסל, לפי הציר בתחילת הגרירה
      ppp: (() => { const h = box.clientHeight / 2; const p1 = series.coordinateToPrice(h), p2 = series.coordinateToPrice(h + 100); return p1 != null && p2 != null ? (p1 - p2) / 100 : 0; })() };
  }

  // ── תנועה ──
  function move(e) {
    const [a, b] = pts(e);
    const c = mid(a, b);
    if (!g.mode) {
      const dd = Math.abs(dist(a, b) - g.d0), dc = Math.hypot(c.x - g.c0.x, c.y - g.c0.y);
      if (Math.max(dd, dc) < SLOP2) return;
      g.mode = dd > dc ? (g.vertical ? 'squeeze' : 'zoom')
        : Math.abs(c.y - g.c0.y) > Math.abs(c.x - g.c0.x) ? 'price' : 'none';   // לרוחב בשתי אצבעות — כלום
    }
    if (g.mode === 'squeeze') {
      k = Math.min(1, Math.max(MIN_K, g.k0 * Math.max(Math.abs(a.y - b.y), 20) / g.ax0));
      applyPrice();
    } else if (g.mode === 'zoom' && g.r0) {
      const { from, to } = g.r0;
      const span = Math.max(MIN_BARS, (to - from) * g.ax0 / Math.max(Math.abs(a.x - b.x), 20));
      const ratio = (g.anchor - from) / (to - from);
      ts().setVisibleLogicalRange({ from: g.anchor - span * ratio, to: g.anchor + span * (1 - ratio) });
    } else if (g.mode === 'price') {
      off = g.off0 + (c.y - g.c0.y) * g.ppp;   // האצבעות יורדות → הטווח עולה → הנרות יורדים איתן
      applyPrice();
    }
  }

  // שתי אצבעות — שלנו: נעצרות ב-capture על הקופסה, לפני הספרייה (אחרת גם היא גוררת) ולפני הגלילה של הדפדפן
  const onStart = e => {
    if (e.touches.length !== 2 || blocked()) { g = null; return; }
    e.stopPropagation();
    if (e.cancelable) e.preventDefault();
    begin(e);
  };
  const onMove = e => {
    if (!g || e.touches.length !== 2) return;
    if (blocked()) { g = null; return; }   // השרטוט תפס את המגע באמצע (לחיצה ארוכה)
    e.stopPropagation();
    if (e.cancelable) e.preventDefault();
    move(e);
  };
  const onEnd = e => { if (g && e.touches.length < 2) g = null; };
  box.addEventListener('touchstart', onStart, { capture: true, passive: false });
  box.addEventListener('touchmove', onMove, { capture: true, passive: false });
  box.addEventListener('touchend', onEnd, true);
  box.addEventListener('touchcancel', onEnd, true);

  return {
    vScale: () => k,
    offset: () => off,
    reset() { k = 1; off = 0; applyPrice(); },
    destroy() {
      reset.remove();
      box.removeEventListener('touchstart', onStart, true);
      box.removeEventListener('touchmove', onMove, true);
      box.removeEventListener('touchend', onEnd, true);
      box.removeEventListener('touchcancel', onEnd, true);
    },
  };
}
