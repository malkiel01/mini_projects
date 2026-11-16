// תנועות מגע על הגרף בטלפון (מטלה דחופה מהבעלים, 2026-11-16: "לא סידרת את זה").
//
// - **אצבע אחת לאורך הגרף** — מזיזה את הגרף בזמן (הספרייה, כמו קודם).
// - **אצבע אחת במאונך** — גוללת את **הדף**, לא את הגרף: `handleScroll.vertTouchDrag: false` ו-`touch-action: pan-y` (CSS).
// - **צביטה** — כאן, לא בספרייה (`handleScale.pinch: false`). הכיוון נקבע לפי הזווית בין שתי האצבעות כשהשנייה נוגעת:
//   - **אופקית** (האצבעות זו לצד זו) — טווח הזמן: מקרבים = פחות נרות וגדולים יותר, סביב הנקודה שבין האצבעות.
//   - **אנכית** (זו מעל זו) — מכווצת את הגרף במאונך: הנרות, השכבות שעליהם (אליוט, אינדיקטורים — אותו ציר מחיר)
//     והנפח נמוכים יותר. מתיחה חוזרת עד הגובה הרגיל, לא מעבר לו.
// הקואורדינטות דרך clientX/clientY — כך גם בשכבה המסובבת (rotate.js ממיר אותן).

// ציר המחיר של הנרות (series.priceScale() — לא priceScale('right')) — ברירת המחדל של הספרייה (0.2 למעלה, 0.1 למטה);
// הנפח — 0.82/0.05 (market.js)
const PRICE = { top: 0.2, bottom: 0.1 };
const VOL = { top: 0.82, bottom: 0.05 };
const MIN_K = 0.25;     // הכי מכווץ: רבע מהגובה
const MIN_BARS = 8;     // הכי מקורב: 8 נרות על המסך

export function mountGestures({ chart, series, box, onVScale = () => {} }) {
  chart.applyOptions({ handleScroll: { vertTouchDrag: false }, handleScale: { pinch: false } });
  let k = 1;            // הגובה היחסי (1 = רגיל)
  let g = null;         // הצביטה שבעבודה

  function applyK() {
    const price = (1 - PRICE.top - PRICE.bottom) * (1 - k) / 2;
    series.priceScale().applyOptions({ scaleMargins: { top: PRICE.top + price, bottom: PRICE.bottom + price } });
    const vh = 1 - VOL.top - VOL.bottom;
    chart.priceScale('vol').applyOptions({ scaleMargins: { top: 1 - VOL.bottom - vh * k, bottom: VOL.bottom } });
    onVScale(k);
  }

  const pts = e => [...e.touches].slice(0, 2).map(t => ({ x: t.clientX, y: t.clientY }));
  const local = p => { const r = box.getBoundingClientRect(); return { x: p.x - r.left, y: p.y - r.top }; };

  function start(e) {
    const [a, b] = pts(e);
    const dx = Math.abs(a.x - b.x), dy = Math.abs(a.y - b.y);
    const ts = chart.timeScale();
    const r = ts.getVisibleLogicalRange();
    const mid = local({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
    g = { vertical: dy > dx, d0: Math.max(dy > dx ? dy : dx, 20), k0: k, r0: r, anchor: ts.coordinateToLogical(mid.x) ?? (r ? (r.from + r.to) / 2 : 0) };
  }

  function move(e) {
    const [a, b] = pts(e);
    const d = Math.max(g.vertical ? Math.abs(a.y - b.y) : Math.abs(a.x - b.x), 20);
    const f = d / g.d0;   // >1 — האצבעות מתרחקות
    if (g.vertical) {
      k = Math.min(1, Math.max(MIN_K, g.k0 * f));
      applyK();
    } else if (g.r0) {
      const { from, to } = g.r0;
      let span = (to - from) / f;
      span = Math.max(MIN_BARS, span);
      const ratio = (g.anchor - from) / (to - from);
      chart.timeScale().setVisibleLogicalRange({ from: g.anchor - span * ratio, to: g.anchor + span * (1 - ratio) });
    }
  }

  // לפני הספרייה (capture על הקופסה): שתי אצבעות — שלנו, והספרייה לא רואה אותן (אחרת גם היא גוררת)
  const onStart = e => { if (e.touches.length < 2) return; e.stopPropagation(); if (e.cancelable) e.preventDefault(); start(e); };
  const onMove = e => { if (!g || e.touches.length < 2) return; e.stopPropagation(); if (e.cancelable) e.preventDefault(); move(e); };
  const onEnd = e => { if (g && e.touches.length < 2) g = null; };
  box.addEventListener('touchstart', onStart, { capture: true, passive: false });
  box.addEventListener('touchmove', onMove, { capture: true, passive: false });
  box.addEventListener('touchend', onEnd, true);
  box.addEventListener('touchcancel', onEnd, true);

  return {
    vScale: () => k,
    reset() { k = 1; applyK(); },
    destroy() {
      box.removeEventListener('touchstart', onStart, true);
      box.removeEventListener('touchmove', onMove, true);
      box.removeEventListener('touchend', onEnd, true);
      box.removeEventListener('touchcancel', onEnd, true);
    },
  };
}
