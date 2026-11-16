// תנועות מגע על הגרף בטלפון (מטלות דחופות מהבעלים, 2026-11-16: "לא סידרת את זה"; "לא ניתן בכלל לגלול בתוך הגרף").
//
// כל הגרירות בתוך הגרף — כאן, לא בספרייה (`handleScroll.horzTouchDrag/vertTouchDrag: false`, `handleScale.pinch: false`).
// למה: עם `vertTouchDrag: false` הספרייה (4.2) מקבלת גרירה של אצבע אחת רק כשהתזוזה האנכית קטנה מחצי מהאופקית
// (בערך 26°), ואחרת זונחת אותה — גרירה רגילה של אצבע "לצדדים" כמעט לא הזיזה את הגרף.
//
// - **אצבע אחת:** הכיוון נקבע אחרי 6px. **רובה לרוחב** (|dx| ≥ |dy|) — הזזת הגרף בזמן, עם המשך קינטי. **רובה לגובה** —
//   לא נוגעים: הדף נגלל (`touch-action: pan-y` ב-CSS).
// - **שתי אצבעות:** נעילה אחרי 10px — לפי היחס בין שינוי המרחק בין האצבעות לבין תזוזת המרכז שלהן:
//   - **צביטה** (המרחק משתנה יותר): הכיוון לפי הזווית בין האצבעות. **אופקית** — טווח הזמן (מקרבים = פחות נרות, גדולים
//     יותר, סביב הנקודה שבין האצבעות). **אנכית** — מכווצת את הגרף במאונך: הנרות, השכבות (אליוט, אינדיקטורים — אותו
//     ציר) והנפח; מתיחה חוזרת עד הגובה הרגיל.
//   - **גרירה** (המרכז זז יותר): **לרוחב** — הזזה בזמן; **לגובה** — הזזת ציר המחיר (הנרות זזים עם האצבעות). ההזזה —
//     היסט קבוע על הטווח של autoScale (`autoscaleInfoProvider`), כך שהיא לא קופצת חזרה.
// - **"↺"** (בפינת הגרף, רק כשציר המחיר זז או כווץ) — מחזיר את ציר המחיר לרגיל.
// - בזמן ציור (`blocked()`) — לא נוגעים: השרטוטים מקבלים את המגע.
// הקואורדינטות דרך clientX/clientY — כך גם בשכבה המסובבת (rotate.js ממיר אותן).

// ציר המחיר של הנרות (series.priceScale() — לא priceScale('right'), שלא חל על הנרות כששני הצירים מוסתרים):
// ברירת המחדל של הספרייה (0.2 למעלה, 0.1 למטה); הנפח — 0.82/0.05 (market.js)
const PRICE = { top: 0.2, bottom: 0.1 };
const VOL = { top: 0.82, bottom: 0.05 };
const MIN_K = 0.25;     // הכי מכווץ: רבע מהגובה
const MIN_BARS = 8;     // הכי מקורב: 8 נרות על המסך
const SLOP1 = 6;        // אצבע אחת: כמה פיקסלים עד שמחליטים על כיוון
const SLOP2 = 10;       // שתי אצבעות: עד שמחליטים צביטה או גרירה
const FRICTION = 0.92;  // המשך קינטי: הקטנת המהירות בכל פריים

export function mountGestures({ chart, series, shifted = [series], box, blocked = () => false }) {
  chart.applyOptions({ handleScroll: { horzTouchDrag: false, vertTouchDrag: false }, handleScale: { pinch: false } });
  let k = 1;            // הגובה היחסי (1 = רגיל)
  let off = 0;          // היסט ציר המחיר (במחיר; 0 = רגיל)
  let g = null;         // התנועה שבעבודה
  let fling = 0;        // requestAnimationFrame של ההמשך הקינטי

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
  const barPx = r => box.clientWidth / Math.max(1e-6, r.to - r.from);
  // dx בפיקסלים: ימינה = אחורה בזמן. לא יוצאים מהנתונים: לפחות חמישית מהמסך (ו-3 נרות לפחות) נשארת עם נרות
  function shiftTime(r0, dx) {
    const span = r0.to - r0.from, n = series.data().length;
    const keep = Math.min(Math.max(3, span * 0.2), Math.max(1, n));
    let d = dx / barPx(r0);
    d = Math.min(d, r0.to - (keep - 1));             // אחורה: הקצה הימני לא עובר את תחילת הנתונים
    d = Math.max(d, r0.from - (n - keep));           // קדימה: הקצה השמאלי לא עובר את סוף הנתונים
    ts().setVisibleLogicalRange({ from: r0.from - d, to: r0.to - d });
    return d;
  }
  function stopFling() { if (fling) cancelAnimationFrame(fling); fling = 0; }
  function startFling(v) {            // v — פיקסלים לפריים
    stopFling();
    const step = () => {
      const r = ts().getVisibleLogicalRange();
      if (!r || Math.abs(v) < 0.3) { fling = 0; return; }
      if (Math.abs(shiftTime(r, v)) < 1e-6) { fling = 0; return; }   // הגיע לקצה
      v *= FRICTION;
      fling = requestAnimationFrame(step);
    };
    fling = requestAnimationFrame(step);
  }

  // ── התחלה ──
  function begin(e) {
    stopFling();
    const r = ts().getVisibleLogicalRange();
    if (e.touches.length === 1) {
      const p = pts(e)[0];
      g = { n: 1, p0: p, mode: null, r0: r, last: [{ x: p.x, t: performance.now() }] };
      return;
    }
    const [a, b] = pts(e);
    const c = mid(a, b);
    g = { n: 2, a0: a, b0: b, c0: c, d0: dist(a, b), mode: null, r0: r, k0: k, off0: off,
      vertical: Math.abs(a.y - b.y) > Math.abs(a.x - b.x),
      ax0: Math.max(Math.abs(a.y - b.y) > Math.abs(a.x - b.x) ? Math.abs(a.y - b.y) : Math.abs(a.x - b.x), 20),
      anchor: ts().coordinateToLogical(local(c).x) ?? (r ? (r.from + r.to) / 2 : 0),
      // מחיר לפיקסל, לפי הציר בתחילת הגרירה
      ppp: (() => { const h = box.clientHeight / 2; const p1 = series.coordinateToPrice(h), p2 = series.coordinateToPrice(h + 100); return p1 != null && p2 != null ? (p1 - p2) / 100 : 0; })() };
  }

  // ── תנועה ──
  function move1(e) {
    const p = pts(e)[0];
    const dx = p.x - g.p0.x, dy = p.y - g.p0.y;
    if (!g.mode) {
      if (Math.hypot(dx, dy) < SLOP1) return false;
      g.mode = Math.abs(dx) >= Math.abs(dy) ? 'time' : 'page';
    }
    if (g.mode !== 'time' || !g.r0) return false;
    shiftTime(g.r0, dx);
    g.last.push({ x: p.x, t: performance.now() });
    if (g.last.length > 6) g.last.shift();
    return true;
  }
  function move2(e) {
    const [a, b] = pts(e);
    const c = mid(a, b);
    if (!g.mode) {
      const dd = Math.abs(dist(a, b) - g.d0), dc = Math.hypot(c.x - g.c0.x, c.y - g.c0.y);
      if (Math.max(dd, dc) < SLOP2) return;
      g.mode = dd > dc ? (g.vertical ? 'squeeze' : 'zoom')
        : Math.abs(c.x - g.c0.x) >= Math.abs(c.y - g.c0.y) ? 'time' : 'price';
    }
    if (g.mode === 'squeeze') {
      k = Math.min(1, Math.max(MIN_K, g.k0 * Math.max(Math.abs(a.y - b.y), 20) / g.ax0));
      applyPrice();
    } else if (g.mode === 'zoom' && g.r0) {
      const { from, to } = g.r0;
      const span = Math.max(MIN_BARS, (to - from) * g.ax0 / Math.max(Math.abs(a.x - b.x), 20));
      const ratio = (g.anchor - from) / (to - from);
      ts().setVisibleLogicalRange({ from: g.anchor - span * ratio, to: g.anchor + span * (1 - ratio) });
    } else if (g.mode === 'time' && g.r0) {
      shiftTime(g.r0, c.x - g.c0.x);
    } else if (g.mode === 'price') {
      off = g.off0 + (c.y - g.c0.y) * g.ppp;   // האצבעות יורדות → הטווח עולה → הנרות יורדים איתן
      applyPrice();
    }
  }

  // לפני הספרייה ולפני הגלילה של הדפדפן (capture על הקופסה, לא פסיבי)
  const onStart = e => {
    if (blocked()) { g = null; return; }
    if (e.touches.length > 2) { g = null; return; }
    begin(e);
    if (e.touches.length === 2) { e.stopPropagation(); if (e.cancelable) e.preventDefault(); }
  };
  const onMove = e => {
    if (!g) return;
    if (blocked()) { g = null; return; }   // השרטוט תפס את המגע באמצע (לחיצה ארוכה)
    if (e.touches.length !== g.n) return;
    if (g.n === 1) {
      if (move1(e)) { e.stopPropagation(); if (e.cancelable) e.preventDefault(); }
      return;
    }
    e.stopPropagation();
    if (e.cancelable) e.preventDefault();
    move2(e);
  };
  const onEnd = e => {
    if (!g) return;
    if (g.n === 1 && g.mode === 'time' && g.last.length > 1) {
      const a = g.last[0], b = g.last[g.last.length - 1];
      const dt = b.t - a.t;
      if (dt > 0 && performance.now() - b.t < 80) startFling((b.x - a.x) / dt * 16);
    }
    // אצבע אחת הורמה מתוך שתיים — מתחילים מחדש עם מה שנשאר (בלי לקפוץ)
    g = null;
    if (e.touches.length === 1 && !blocked()) begin(e);
  };
  box.addEventListener('touchstart', onStart, { capture: true, passive: false });
  box.addEventListener('touchmove', onMove, { capture: true, passive: false });
  box.addEventListener('touchend', onEnd, true);
  box.addEventListener('touchcancel', onEnd, true);

  return {
    vScale: () => k,
    offset: () => off,
    reset() { k = 1; off = 0; applyPrice(); },
    destroy() {
      stopFling();
      reset.remove();
      box.removeEventListener('touchstart', onStart, true);
      box.removeEventListener('touchmove', onMove, true);
      box.removeEventListener('touchend', onEnd, true);
      box.removeEventListener('touchcancel', onEnd, true);
    },
  };
}
