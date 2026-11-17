// תנועות מגע על הגרף בטלפון (מטלות דחופות מהבעלים, 2026-11-16).
//
// - **אצבע אחת לרוחב** — הספרייה מזיזה בזמן (`horzTouchDrag`, כמו תמיד). הבעלים: "אצבע אחת גוללת וזה מושלם" — לא נוגעים.
// - **אצבע אחת לגובה** — גוללת את **הדף**: `handleScroll.vertTouchDrag: false` ו-`touch-action: pan-y` (CSS).
// - **שתי אצבעות** — כאן, לא בספרייה (`handleScale.pinch: false`; האירועים נעצרים ב-capture לפני הספרייה). **בלי נעילה**
//   (הבעלים, 2026-11-17: "לפעמים מכווץ ולא נותן להגביה, לפעמים כן, לפעמים נעצר… למה לא נותן להגביה בצורה חופשית"):
//   ההחלטה בכל צעד (כל STEP פיקסלים של תנועה), לפי מה שהאצבעות עשו מאז הצעד הקודם — לא לפי הזווית שבה הונחו. כך עוברים
//   בין צביטה לגרירה בלי להרים אצבעות, וצביטה באלכסון הולכת לפי הכיוון שבו האצבעות באמת נפתחות:
//   - **צביטה** (המרחק בין האצבעות משתנה יותר מתזוזת המרכז) — לפי מה שהשתנה יותר: הרוחב שביניהן או הגובה.
//     **ברוחב** — טווח הזמן (מקרבים = פחות נרות, גדולים יותר, סביב הנקודה שבין האצבעות). **בגובה** — הגובה של הגרף: הנרות,
//     השכבות (אליוט, אינדיקטורים — אותו ציר) והנפח. **חופשי:** מכיווץ לרבע (MIN_K) ועד מתיחה פי MAX_K — מעל הגובה הרגיל.
//   - **גרירה לגובה** (המרכז זז יותר, ובעיקר במאונך) — מזיזה את ציר המחיר: הנרות זזים עם האצבעות. ההזזה היא היסט קבוע על
//     הטווח של autoScale (`autoscaleInfoProvider`), כך שהיא לא קופצת חזרה.
//   - **גרירה לרוחב בשתי אצבעות** — הזמן (בקרה 11, 2026-11-17: "לגרור לאזור אחר"; מחליף את "2 אצבעות רק לגובה").
// - **crosshair דולק** (לחיצה ארוכה) — שתי אצבעות גוברות, והוא מעוגן לנקודת נתונים (נר + מחיר): בצביטה נשאר עליה, בגרירה זז איתה.
// - **"↺"** (בפינת הגרף, מרגע שהציר הותאם ביד) — מחזיר את ציר המחיר לרגיל, כולל autoScale.
// - **הקפאת הטווח:** אחרי התאמה ידנית, גרירה בזמן לא משנה את טווח המחיר (ראו למטה).
// - בזמן שהשרטוט תופס את המגע (`blocked()`) — לא נוגעים.
// הקואורדינטות דרך clientX/clientY — כך גם בשכבה המסובבת (rotate.js ממיר אותן).

// ציר המחיר של הנרות (series.priceScale() — לא priceScale('right'), שלא חל על הנרות כששני הצירים מוסתרים):
// ברירת המחדל של הספרייה (0.2 למעלה, 0.1 למטה); הנפח — 0.82/0.05 (market.js)
const PRICE = { top: 0.2, bottom: 0.1 };
const VOL = { top: 0.82, bottom: 0.05 };
const MIN_K = 0.25;     // הכי מכווץ: רבע מהגובה
const MAX_K = 8;        // הכי מתוח: פי 8 מהגובה הרגיל [שלנו — "תקרה סבירה"]
const MIN_BARS = 8;     // הכי מקורב: 8 נרות על המסך
const TAP_MS = 350;     // הקשה — מגע קצר מזה, בלי תזוזה
const STEP = 6;         // שתי אצבעות: כמה פיקסלים של תנועה עד שמחליטים מה הצעד הזה (צביטה / גרירה, רוחב / גובה)

export function mountGestures({ chart, series, shifted = [series], box, blocked = () => false, crosshair = () => null }) {
  chart.applyOptions({ handleScroll: { vertTouchDrag: false }, handleScale: { pinch: false } });
  let k = 1;            // הגובה היחסי (1 = רגיל; < 1 — מכווץ, > 1 — מתוח)
  let off = 0;          // היסט ציר המחיר (במחיר; 0 = רגיל)
  let g = null;         // התנועה שבעבודה

  // ── ציר המחיר: כיווץ (scaleMargins), היסט והקפאה (autoscaleInfoProvider) ──
  // מרגע שהמשתמש התאים את הציר בעצמו (כיווץ או גרירה לגובה) — הטווח של כל סדרה **מוקפא** על מה שהיה באותו רגע. בלי זה
  // autoScale מתאים את הטווח לנרות שעל המסך בכל גרירה בזמן: בכניסה לתחזית יש פחות נרות, הטווח מצטמק והנרות "מתנפחים" —
  // והכיווץ מתבטל בפועל (הבעלים: "ככל שאני נכנס לעומק התחזית הגרף גדל, בלי קשר לכמה צמצמתי אותו"). "↺" — חזרה ל-autoScale.
  let frozen = null;    // Map: סדרה → הטווח שלה ברגע ההקפאה; null — autoScale רגיל
  const providerOf = s => orig => {
    let r = orig();
    if (frozen) {
      if (!frozen.has(s) && r?.priceRange) frozen.set(s, { ...r.priceRange });
      const pr = frozen.get(s);
      if (pr) r = { ...(r || {}), priceRange: { ...pr } };
    }
    if (!r?.priceRange || (!off && k <= 1)) return r;
    let { minValue: lo, maxValue: hi } = r.priceRange;
    // מתיחה מעל הגובה הרגיל — טווח מחיר צר יותר סביב האמצע (השוליים של הציר לא יכולים לרדת מתחת ל-0)
    if (k > 1) { const m = (lo + hi) / 2, h = (hi - lo) / 2 / k; lo = m - h; hi = m + h; }
    return { ...r, priceRange: { minValue: lo + off, maxValue: hi + off } };
  };
  const providers = shifted.map(s => [s, providerOf(s)]);
  for (const [s, pv] of providers) s.applyOptions({ autoscaleInfoProvider: pv });
  const freeze = () => { if (!frozen) frozen = new Map(); };

  const reset = document.createElement('button');
  reset.type = 'button';
  reset.className = 'chart-reset';
  reset.textContent = '↺';
  reset.title = 'ציר המחיר לרגיל';
  reset.setAttribute('aria-label', 'החזרת ציר המחיר לרגיל');
  reset.hidden = true;
  reset.addEventListener('click', e => { e.stopPropagation(); unfreeze(); });
  function unfreeze() { k = 1; off = 0; frozen = null; applyPrice(); }
  box.append(reset);

  function applyPrice() {
    const kk = Math.min(1, k);   // כיווץ — בשוליים; מתיחה — בטווח המחיר (providerOf)
    const m = (1 - PRICE.top - PRICE.bottom) * (1 - kk) / 2;
    series.priceScale().applyOptions({ scaleMargins: { top: PRICE.top + m, bottom: PRICE.bottom + m } });
    const vh = 1 - VOL.top - VOL.bottom;
    chart.priceScale('vol').applyOptions({ scaleMargins: { top: 1 - VOL.bottom - vh * kk, bottom: VOL.bottom } });
    for (const [s, pv] of providers) s.applyOptions({ autoscaleInfoProvider: pv });   // מחשב מחדש את autoScale
    reset.hidden = !frozen;
  }

  // ── עזרים ──
  const ts = () => chart.timeScale();
  const local = p => { const r = box.getBoundingClientRect(); return { x: p.x - r.left, y: p.y - r.top }; };
  const pts = e => [...e.touches].slice(0, 2).map(t => ({ x: t.clientX, y: t.clientY }));
  const mid = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

  // מחיר לפיקסל — לפי הציר עכשיו (משתנה עם המתיחה)
  const ppp = () => { const h = box.clientHeight / 2; const p1 = series.coordinateToPrice(h), p2 = series.coordinateToPrice(h + 100); return p1 != null && p2 != null ? (p1 - p2) / 100 : 0; };

  // ── התחלה: נקודת הייחוס של הצעד הראשון ──
  function begin(e) {
    const [a, b] = pts(e);
    g = { sx: Math.abs(a.x - b.x), sy: Math.abs(a.y - b.y), c: mid(a, b), last: null, pin: null };
    // ה-crosshair דולק (לחיצה ארוכה) — מעוגן לנקודת נתונים: זמן הנר + מחיר, לא למקום במסך (בקרה 11, הבעלים 2026-11-17).
    // צביטה — נשאר על אותו נר ואותו מחיר; גרירה — הנקודה זזה עם הגרף. כך הוא לא נעלם ולא קופץ.
    // [לא נבנה] crosshair בשטח הריק מימין לנר האחרון (אין שם זמן) — לא מעוגן
    const x = crosshair();
    if (x) {
      const time = ts().coordinateToTime(x.x), price = series.coordinateToPrice(x.y);
      if (time != null && price != null) g.pin = { time, price };
    }
  }
  const repin = () => { if (g?.pin) { try { chart.setCrosshairPosition(g.pin.price, g.pin.time, series); } catch { /* ריק */ } } };

  // ── תנועה: בכל צעד — מה האצבעות עשו מאז הצעד הקודם ──
  function move(e) {
    const [a, b] = pts(e);
    const c = mid(a, b);
    const sx = Math.abs(a.x - b.x), sy = Math.abs(a.y - b.y);
    const dsx = sx - g.sx, dsy = sy - g.sy, dcx = c.x - g.c.x, dcy = c.y - g.c.y;
    const pinch = Math.max(Math.abs(dsx), Math.abs(dsy)), drag = Math.hypot(dcx, dcy);
    if (Math.max(pinch, drag) < STEP) return;   // עוד לא צעד — מצטבר
    if (pinch > drag && Math.abs(dsy) > Math.abs(dsx)) {
      // צביטה בגובה — מתיחה / כיווץ חופשיים
      freeze();
      k = Math.min(MAX_K, Math.max(MIN_K, k * Math.max(sy, 20) / Math.max(g.sy, 20)));
      applyPrice();
      g.last = 'squeeze';
    } else if (pinch > drag) {
      // צביטה ברוחב — טווח הזמן, סביב הנקודה שבין האצבעות
      const r = ts().getVisibleLogicalRange();
      if (r && r.to > r.from) {
        const anchor = ts().coordinateToLogical(local(c).x) ?? (r.from + r.to) / 2;
        const span = Math.max(MIN_BARS, (r.to - r.from) * Math.max(g.sx, 20) / Math.max(sx, 20));
        const ratio = (anchor - r.from) / (r.to - r.from);
        ts().setVisibleLogicalRange({ from: anchor - span * ratio, to: anchor + span * (1 - ratio) });
      }
      g.last = 'zoom';
    } else {
      // גרירה — הגרף זז עם האצבעות. **לגובה:** ציר המחיר (יורדות → הטווח עולה → הנרות יורדים). **לרוחב:** הזמן (בקרה 11, הבעלים
      // 2026-11-17 — "לגרור לאזור אחר"; גובר על "2 אצבעות רק לגובה" מ-11-16). באלכסון — שניהם. רכיב קטן מחצי השני — לא נחשב
      // (כך גרירה לרוחב לא מקפיאה את ציר המחיר בגלל רעד)
      g.last = '';
      if (Math.abs(dcy) > Math.abs(dcx) / 2) {
        freeze();
        off += dcy * ppp();
        applyPrice();
        g.last = 'price';
      }
      const r = ts().getVisibleLogicalRange();
      const l0 = ts().coordinateToLogical(local(g.c).x), l1 = ts().coordinateToLogical(local(c).x);
      if (Math.abs(dcx) > Math.abs(dcy) / 2 && r && l0 != null && l1 != null) {
        const sh = l0 - l1;   // האצבעות ימינה → אחורה בזמן
        ts().setVisibleLogicalRange({ from: r.from + sh, to: r.to + sh });
        g.last += 'time';
      }
    }
    g.sx = sx; g.sy = sy; g.c = c;
    repin();
  }

  // שתי אצבעות — שלנו: נעצרות ב-capture על הקופסה, לפני הספרייה (אחרת גם היא גוררת) ולפני הגלילה של הדפדפן
  // crosshair שהוחזר בקוד (setCrosshairPosition) — הספרייה לא מכבה אותו בהקשה; הקשה (אצבע אחת בלי תזוזה) מכבה, כמו קודם
  let pinned = false, tap = null, hiddenMode = null;   // hiddenMode — מצב ה-crosshair לפני שהסתרנו אותו (null — לא מוסתר)
  const onStart = e => {
    if (hiddenMode !== null) { chart.applyOptions({ crosshair: { mode: hiddenMode } }); hiddenMode = null; }
    // הקשה על כפתור שעל הגרף ("↺", מקרא, חלונית) — לא הקשה שמכבה את ה-crosshair
    if (e.touches.length === 1 && pinned && !e.target?.closest?.('button, a, input, .ctip, .fc-legend')) { const t = e.touches[0]; tap = { x: t.clientX, y: t.clientY, at: performance.now() }; }
    else tap = null;
    if (e.touches.length !== 2 || blocked()) { g = null; return; }
    e.stopPropagation();
    if (e.cancelable) e.preventDefault();
    begin(e);
  };
  const onMove = e => {
    if (tap && e.touches.length === 1 && Math.hypot(e.touches[0].clientX - tap.x, e.touches[0].clientY - tap.y) > 8) tap = null;
    if (!g || e.touches.length !== 2) return;
    if (blocked()) { g = null; return; }   // השרטוט תפס את המגע באמצע (לחיצה ארוכה)
    e.stopPropagation();
    if (e.cancelable) e.preventDefault();
    move(e);
  };
  const onEnd = e => {
    if (tap && !e.touches.length && performance.now() - tap.at > TAP_MS) tap = null;   // לחיצה ארוכה — לא הקשה
    if (tap && !e.touches.length) {
      // אחרי שהספרייה סיימה עם ההקשה (היא מטפלת אחרינו, ומציבה את ה-crosshair בנקודת ההקשה).
      tap = null; pinned = false;
      // גם אחרי אירועי העכבר שהדפדפן מייצר מההקשה — הספרייה מציבה את ה-crosshair גם מהם
      // הספרייה עדיין "במעקב" (ראתה את האצבע הראשונה של כל מגע בשתי אצבעות) ומציבה את ה-crosshair שלה בנקודת ההקשה —
      // clearCrosshairPosition לא מכבה אותו. לכן: מצב crosshair מוסתר (2), והמצב המקורי חוזר במגע הבא — לחיצה ארוכה עובדת כרגיל
      setTimeout(() => {
        try { chart.clearCrosshairPosition(); } catch { /* ריק */ }
        if (hiddenMode === null) { hiddenMode = chart.options().crosshair.mode; chart.applyOptions({ crosshair: { mode: 2 } }); }
      }, 120);
      return;
    }
    if (!g || e.touches.length >= 2) return;
    const pin = g.pin;
    g = null;
    // אחרי שהאצבעות עזבו — ה-crosshair על אותה נקודה (הספרייה מסתירה אותו כשהמגע נגמר)
    if (pin) { pinned = true; requestAnimationFrame(() => { try { chart.setCrosshairPosition(pin.price, pin.time, series); } catch { /* ריק */ } }); }
  };
  box.addEventListener('touchstart', onStart, { capture: true, passive: false });
  box.addEventListener('touchmove', onMove, { capture: true, passive: false });
  box.addEventListener('touchend', onEnd, true);
  box.addEventListener('touchcancel', onEnd, true);

  return {
    vScale: () => k,
    /** לבדיקות: מה הצעד האחרון היה (squeeze / zoom / price / none) */
    lastStep: () => g?.last ?? null,
    /** לבדיקות: ה-crosshair הוסתר אחרי הקשה (עד המגע הבא) */
    crossHidden: () => hiddenMode !== null,
    offset: () => off,
    /** חזרה לרגיל — "↺", וגם כשהנתונים מתחלפים (מסגרת, טווח, רענון ידני) */
    reset: unfreeze,
    destroy() {
      reset.remove();
      box.removeEventListener('touchstart', onStart, true);
      box.removeEventListener('touchmove', onMove, true);
      box.removeEventListener('touchend', onEnd, true);
      box.removeEventListener('touchcancel', onEnd, true);
    },
  };
}
