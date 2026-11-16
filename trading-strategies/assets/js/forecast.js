// השיטות על הגרף — spec-stage-a 17.2.2, 18.1.2 (F1), 18.4 (S4). השיטה רצה בשרת על הנרות המוצגים ומחזירה שתי שכבות
// נפרדות (lib/forecast.php): **ממצא** (`overlay` — הספירה על הנרות הסגורים, קו מלא) ו**תרחיש** (`forecast` — ההמשך האפשרי,
// קו מקווקו ואזור שקוף, רק אחרי הנר הסגור האחרון). הגרף מצייר את שתיהן **על הקנבס** (Primitive של Lightweight Charts, כמו
// הציור). הזמנים העתידיים (t + k·tf) נכנסים לציר כנקודות ריקות (whitespace) בסדרה נפרדת, כך שציר הזמן מתרחב ימינה בלי לגעת בנרות.
//
// **הבחירה (F1):** 🔮 פותח תפריט נפתח מעל הגרף — רשימת שיטות עם תיבות סימון. בלי sheet ובלי מסך אחר; הגרף גלוי.
// **אפשר כמה שיטות יחד**, והכול בבקשת chart_refresh אחת. הבחירה נשמרת במכשיר (localStorage).
//
// **הצבעים (S5, 18.5):** לכל שיטה צבע מפלטה של 8. ברירת המחדל — הפנוי הבא; בתפריט, מתחת לשיטה מסומנת, בוחרים צבע אחר —
// צבע של שיטה אחרת שעל הגרף לא ניתן לבחירה. הצבע נשמר **בפרופיל** (method_colors, lib/method-colors.php) — אותה שיטה, אותו
// צבע בכל זוג ובכל מכשיר. עד 8 שיטות יחד; בלי צבע פנוי — הצבע של השיטה הזמינה שלא סומנה הכי הרבה זמן (2.20). כל מה ששיטה מציירת — קו, אזור, רמה, תווית וסימון על נר — בצבע שלה; ספירה חלופית — אותו צבע, עמום.
// **המקרא** מעל הגרף: שיטה ונקודה בצבע; לחיצה מסתירה את השיטה מהגרף ומחזירה, בלי לבטל את הסימון שלה בתפריט.
//
// **הפס שמתחת לגרף (S4, 18.4)** — מחליף את מסך "🧭 ניתוח": לכל שיטה, בצבע שלה, שלוש כותרות — **ממצא** (כיוון, תנאים, רמות),
// **תרחיש** (התרחישים, האזור ורמת הביטול, האופק; "הסתר תרחיש"), **הסתברות** (הוודאות של הבדיקה לאחור, 6.3 — או "לא נבדק
// לאחור"). מעליהם — שקלול (6.5) כשיש כמה שיטות, ומסגרות הזמן לפי הפרופיל. "🔔 התראה מזה" ו"📓 פתח עסקה מזה" — בכל שיטה.
// כל שיטה מתקפלת; הגרף לא משנה גודל.
//
// **התוויות (18.1.2 סעיף 5):** תווית של קו או רמה — בצד ימין; חופפת לתווית שכבר צוירה — לא מצוירת (labelBoard, השיטה
// הראשונה והתרחיש המוביל קודמים); ספירות חלופיות — בלי טקסט, רק קו עמום (17.1.7).
//
// **הרענון (18.3):** המודול לא פונה לשרת בעצמו, חוץ מרשימת השיטות. התוצאות והכרטיס מגיעים בבקשה של הגרף (chart_refresh ב-
// market.js), עם הבדיקה לאחור השמורה בלבד. הבדיקה לאחור עצמה — רק בפעולה של המשתמש (market.js → analysis → onAnalysis).

import { timeToLogical, logicalX } from './drawings.js';
import { fmtNum } from './chartaxis.js';
import { cardParts, TF_LABEL } from './card.js';

// הפלטה של השיטות (S5) — = TS_METHOD_PALETTE בשרת. שיטה חדשה מקבלת את הצבע הפנוי הראשון
export const PALETTE = ['--brand', '--m2', '--m3', '--m4', '--m5', '--m6', '--m7', '--m8'];
const COLOR_NAME = { '--brand': 'כחול', '--m2': 'כתום', '--m3': 'סגול', '--m4': 'טורקיז', '--m5': 'ורוד', '--m6': 'ירוק', '--m7': 'חרדל', '--m8': 'אפור' };
const KEY = 'ts-forecast-methods';
const MAX = PALETTE.length;   // = TS_REFRESH_MAX_METHODS בשרת

function loadChosen() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || '[]');
    if (!Array.isArray(v)) return [];
    const out = [];
    for (const x of v) if (x && typeof x.id === 'string' && PALETTE.includes(x.color) && !out.some(o => o.id === x.id)) out.push({ id: x.id, color: x.color, hide: x.hide === true, fold: x.fold === true, off: x.off === true, solo: x.solo === true });
    return out.slice(0, MAX);
  } catch { return []; }
}

export function mountForecast({ ctx, asset, pair, chart, series, h, host, isAdmin = () => false, api, toast, getTf, getSec, getTimes, col, buttons, board, onChange,
  axisWidth = () => 0, tools = [], tfs = () => [], setTf = () => {} }) {
  const C = cardParts(ctx);
  let chosen = loadChosen();     // [{id, color, hide, fold, off, solo}] — לפי סדר הבחירה; הראשונה מובילה. off — מוסתרת במקרא; solo — "הסתר רמות בודדות" (פיבונאצ'י, Z9)
  const res = new Map();         // id → התשובה האחרונה מ-chart_refresh
  let an = null;                 // הכרטיסים והשקלול (chart_refresh.analysis, או analysis אחרי בדיקה לאחור)
  let running = false;           // בדיקה לאחור רצה עכשיו (analysis)
  const selected = new Map();    // id → מזהה התרחיש המודגש
  let list = null;               // השיטות הזמינות (api methods) — נטענות כשפותחים את התפריט
  let requestUpdate = () => {};

  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(chosen)); } catch { /* ריק */ } };

  // ── הצבעים (S5, 2.20): הפרופיל הוא האמת; הבחירה במכשיר רק זוכרת ──
  // המפה: {id: {color, at}} — at = מתי השיטה סומנה (או הייתה על הגרף) בפעם האחרונה, בשניות. המבנה הישן ({id: צבע}) נקרא
  // בסלחנות (at: 0). צבע שמור לשיטה רק כל עוד היא זמינה: שיטה שכובתה או שהוסרה נשארת במפה, אבל הצבע שלה פנוי.
  const nowSec = () => Math.floor(Date.now() / 1000);
  const entry = v => (typeof v === 'string' ? (PALETTE.includes(v) ? { color: v, at: 0 } : null)
    : v && PALETTE.includes(v.color) ? { color: v.color, at: Number.isInteger(v.at) && v.at > 0 ? v.at : 0 } : null);
  const saved = () => {
    const v = ctx.me()?.profile?.method_colors;
    const out = {};
    if (v && typeof v === 'object' && !Array.isArray(v)) for (const [k, x] of Object.entries(v)) { const e = entry(x); if (e) out[k] = e; }
    return out;
  };
  const setSaved = v => { const me = ctx.me(); if (me?.profile) me.profile.method_colors = v && !Array.isArray(v) ? v : {}; };
  /** זמינה = ברשימת השיטות של המשתמש. לפני שהרשימה נטענה — כל שיטה נחשבת זמינה (שמרני: לא נוגעים בצבע שלה) */
  const available = id => !list || list.some(m => m.id === id);
  /**
   * הצבע של שיטה: מהפרופיל, אם לא תפוס על הגרף; אחרת הנוכחי; אחרת הפנוי הראשון שאינו שמור לשיטה זמינה אחרת; ובלי
   * פנוי — הצבע של השיטה הזמינה שלא סומנה הכי הרבה זמן (at הקטן), והיא יורדת מהמפה ב-remember (2.20).
   */
  function colorFor(id, used, cur) {
    const map = saved();
    const mine = map[id]?.color;
    if (mine && !used.has(mine)) return mine;
    const others = Object.entries(map).filter(([k]) => k !== id && available(k) && !chosen.some(c => c.id === k));
    const reserved = new Set(others.map(([, e]) => e.color));
    if (PALETTE.includes(cur) && !used.has(cur) && !reserved.has(cur)) return cur;
    const free = PALETTE.find(p => !used.has(p) && !reserved.has(p));
    if (free) return free;
    const lru = others.filter(([, e]) => !used.has(e.color)).sort((a, b) => a[1].at - b[1].at)[0];
    return lru ? lru[1].color : PALETTE.find(p => !used.has(p));
  }
  function reconcile() {
    const used = new Set();
    for (const c of chosen) { c.color = colorFor(c.id, used, c.color); used.add(c.color); }
  }
  /**
   * הצבעים שעל הגרף → לפרופיל. $marked — שיטות שסומנו או בוטלו עכשיו: at שלהן = עכשיו. שיטה שעל הגרף מקבלת at חדש
   * לכל היותר פעם בשעה (כדי לא לכתוב בכל פתיחת גרף). צבע ששמור לשיטה זמינה אחרת (לא על הגרף) — יורד ממנה: אותו צבע
   * לשתי שיטות זמינות אסור. שיטה לא זמינה נשארת במפה כמו שהיא.
   */
  function remember(marked = []) {
    const was = saved();
    const next = { ...was };
    const now = nowSec();
    for (const id of marked) if (next[id] && !chosen.some(c => c.id === id)) next[id] = { ...next[id], at: now };
    for (const c of chosen) {
      for (const k of Object.keys(next)) if (k !== c.id && next[k].color === c.color && available(k)) delete next[k];
      const at = marked.includes(c.id) || !next[c.id] || now - next[c.id].at >= 3600 ? now : next[c.id].at;
      next[c.id] = { color: c.color, at };
    }
    // שתי שיטות זמינות באותו צבע (אחת חזרה אחרי שכובתה) — נשארת זו שסומנה אחרונה; לשנייה ייבחר צבע כשתסומן
    const byColor = new Map();
    for (const [k, e] of Object.entries(next)) {
      if (!available(k)) continue;
      const o = byColor.get(e.color);
      if (o === undefined) { byColor.set(e.color, k); continue; }
      const onChart = x => chosen.some(c => c.id === x);
      const lose = onChart(o) ? k : onChart(k) ? o : next[o].at >= e.at ? k : o;
      delete next[lose];
      byColor.set(e.color, lose === k ? o : k);
    }
    const same = Object.keys(next).length === Object.keys(was).length
      && Object.keys(next).every(k => was[k] && was[k].color === next[k].color && was[k].at === next[k].at);
    if (same) return;
    setSaved(next);
    api('method_colors_save', { colors: next }).then(r => setSaved(r.method_colors)).catch(e => toast(e.message, true));
  }
  function setColor(id, color) {
    const c = pick(id);
    if (!c || !PALETTE.includes(color) || chosen.some(o => o.id !== id && o.color === color)) return;   // תפוס — לא אפשרי
    c.color = color;
    save();
    remember();
    if (openNow()) renderPop();
    paint();
  }
  const nameOf = id => res.get(id)?.method?.name || list?.find(m => m.id === id)?.name || an?.cards?.find(c => c.method.id === id)?.method?.name || 'שיטה';
  const shown = () => chosen.filter(c => !c.off);   // מה שמצויר על הגרף (המקרא מסתיר בלי לבטל סימון)
  let markers = [];  // לבדיקות: הסימונים על הנרות, באותה צורה
  let drawn = [];   // לבדיקות: מה צויר בפעם האחרונה ובאיזה צבע — {m, k, c, alt}
  const colorOf = id => col(chosen.find(c => c.id === id)?.color || '--brand');
  const whitespace = chart.addLineSeries({ visible: false, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
  const xOf = t => { const l = timeToLogical(getTimes(), getSec(), t); return l === null ? null : logicalX(chart.timeScale(), l); };
  const yOf = p => series.priceToCoordinate(p);
  const pick = id => chosen.find(c => c.id === id);
  // הממצא תמיד; התרחיש — אלא אם המשתמש הסתיר אותו (18.4 קריטריון 6). הסימון: scen — לציור מקווקו ולתווית "תרחיש"
  // רמה בודדת (single — פיבונאצ'י, Z9) נשארת בממצא; "הסתר רמות בודדות" רק לא מצייר אותה
  const findingOf = id => (res.get(id)?.overlay?.items || []).filter(it => !(it.single && pick(id)?.solo));
  const singlesOf = id => (res.get(id)?.overlay?.items || []).filter(it => it.single).length;
  const scenarioOf = id => (pick(id)?.hide ? [] : (res.get(id)?.forecast?.items || []).map(it => ({ ...it, scen: true })));
  const itemsOf = id => [...findingOf(id), ...scenarioOf(id)];
  const isOn = (id, it) => !it.scenario || it.scenario === selected.get(id);
  // מחיר לתצוגה לפי ה-tick של הנכס (מהשרת) — מפריד אלפים, בלי אפסים מיותרים (18.1.2 סעיף 4)
  const price = (id, v) => fmtNum(v, Number.isInteger(res.get(id)?.tick_decimals) ? res.get(id).tick_decimals : 8);

  // ── ציור על הקנבס ───────────────────────────────────────
  // תווית: נרשמת בלוח; חופפת — לא מצוירת. מחזירה את המלבן (לבדיקות) או null
  function text(ctx, s, x, y, color, align, W) {
    ctx.font = '12px system-ui, sans-serif';
    ctx.direction = 'rtl';   // טקסט עברי עם מספר: "רמת ביטול … · 82,912.72", ולא המספר בהתחלה (הקנבס של הגרף LTR)
    ctx.textAlign = 'left';
    ctx.textBaseline = 'bottom';
    // נצמדת לימין ואין לה מקום (טלפון): מקצרים את התיאור ב-"…", והמחיר שבסוף נשאר (🟡 F1 — לא גולשים אל מספרי הציר)
    if (align === 'right' && ctx.measureText(s).width + 6 > x) {
      const i = s.lastIndexOf(' · ');
      const tail = i > 0 ? s.slice(i) : '';
      let head = i > 0 ? s.slice(0, i) : s;
      while (head.length > 1 && ctx.measureText(head + '…' + tail).width + 6 > x) head = head.slice(0, -1);
      s = head.trimEnd() + '…' + tail;
    }
    const w = ctx.measureText(s).width + 6;
    let left = align === 'right' ? x - w : x - 3;
    left = Math.max(0, Math.min(left, W - w));
    const r = { x: left, y: y - 15, w, h: 15, by: 'forecast', text: s };
    if (!board.place(r)) return null;
    ctx.fillStyle = col('--surface');
    ctx.globalAlpha = 0.85;
    ctx.fillRect(r.x, r.y, r.w, r.h);
    ctx.globalAlpha = 1;
    ctx.fillStyle = color;
    ctx.fillText(s, left + 3, y - 2);
    return r;
  }
  /** התווית של פריט — איפה ובאיזה צבע (תמיד של השיטה, S5); null — אין (גם לחלופות: בלי טקסט, 17.1.7) */
  function labelOf(id, it, W) {
    if (!isOn(id, it)) return null;
    if (it.kind === 'zone') {
      const x1 = xOf(it.t1), x2 = xOf(it.t2), y1 = yOf(it.p1), y2 = yOf(it.p2);
      if (!it.text || [x1, x2, y1, y2].includes(null)) return null;
      // בפינה הימנית העליונה של האזור (ההווה), לא בנקודת ההתחלה
      const xr = it.extend ? W - 6 - axisWidth() : Math.min(Math.max(x1, x2) - 4, W - 6);   // נמשך עד הקצה — התווית משמאל לציר
      return { s: (it.scen ? 'תרחיש: ' : '') + it.text, x: xr, y: Math.min(y1, y2) + 16, c: colorOf(id), align: 'right' };
    }
    if (it.kind === 'hline') {
      const y = yOf(it.price);
      // משמאל לעמודת המספרים של הציר — לא מעליהם (🟡 F1: "רמת ביטול" כיסתה את 82,500)
      return y === null ? null : { s: `${it.text} · ${price(id, it.price)}`, x: W - 6 - axisWidth(), y, c: colorOf(id), align: 'right' };
    }
    if (it.kind === 'label') {
      const x = xOf(it.t), y = yOf(it.price);
      return x === null || y === null ? null : { s: it.text, x: x + 5, y: y - 3, c: colorOf(id), align: 'left' };
    }
    return null;
  }
  /** הקווים, האזורים והנקודות של פריט — בלי טקסט (התוויות אחרי כל הקווים, labelOf) */
  function drawShape(ctx, id, it, W) {
    const c = colorOf(id);
    const on = isOn(id, it);
    ctx.globalAlpha = on ? 1 : 0.3;   // החלופות עמומות (17.1.7)
    ctx.setLineDash([]);
    ctx.lineWidth = 2;
    const mark = () => drawn.push({ m: id, k: it.kind, c, alt: !on, ...(it.single ? { faint: true } : {}), ...(it.bold ? { bold: true } : {}), ...(it.extend ? { extend: true } : {}), ...(it.scen ? { scen: true } : {}) });
    if (it.kind === 'zone') {
      // extend — האזור נמשך ימינה עד קצה הגרף (פיבונאצ'י, R3), לא רק עד הנר האחרון
      const x1 = xOf(it.t1), x2 = it.extend ? W : xOf(it.t2), y1 = yOf(it.p1), y2 = yOf(it.p2);
      if ([x1, x2, y1, y2].includes(null)) return;
      mark();
      if (it.single) ctx.globalAlpha *= 0.35;   // קופסה דהויה (פיבונאצ'י בלי כיוון, 22.5ד)
      ctx.fillStyle = c; ctx.globalAlpha *= 0.14;
      ctx.fillRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
      ctx.globalAlpha /= 0.14; ctx.strokeStyle = c; ctx.lineWidth = 1;
      if (it.scen) ctx.setLineDash([4, 3]);   // אזור של תרחיש — מסגרת מקווקוות, כמו קו התרחיש
      ctx.strokeRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
      return;
    }
    if (it.kind === 'hline') {
      const y = yOf(it.price);
      if (y === null) return;
      mark();
      ctx.strokeStyle = c;   // בצבע השיטה; סוג הרמה — בקו (ביטול: מקווקו ארוך) ובתווית שלה
      ctx.setLineDash(it.role === 'invalidation' ? [6, 4] : [2, 3]);
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
      ctx.setLineDash([]);
      return;
    }
    if (it.kind === 'polyline') {
      const pts = it.points.map(p => [xOf(p.t), yOf(p.price)]);
      if (pts.some(([x, y]) => x === null || y === null)) return;
      if (it.extend) pts[pts.length - 1][0] = W;   // עד קצה הגרף (R3)
      mark();
      if (it.single) { ctx.globalAlpha *= 0.35; ctx.lineWidth = 1; }   // רמה בודדת — דהויה (Z9)
      if (it.bold) ctx.lineWidth = 3;   // יעד בתוך אזור חפיפה — מודגש (פיבונאצ'י, 22.5ד)
      ctx.strokeStyle = c;
      ctx.setLineDash(it.style === 'dashed' || it.scen ? [7, 5] : []);   // ממצא — קו מלא; תרחיש — מקווקו (18.4 קריטריון 3)
      ctx.beginPath(); pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y))); ctx.stroke();
      return;
    }
    if (it.kind === 'label') {
      const x = xOf(it.t), y = yOf(it.price);
      if (x === null || y === null) return;
      mark();
      ctx.fillStyle = c; ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill();
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
            board.begin('forecast');
            drawn = [];
            // המקרא יושב מעל הגרף — תווית לא מצוירת מתחתיו
            const lr = legendRect();
            if (lr) board.place(lr);
            target.useMediaCoordinateSpace(({ context: ctx, mediaSize }) => {
              const W = mediaSize.width;
              ctx.save();
              // הקווים: השיטה האחרונה קודם, המובילה מעליה; בכל שיטה — החלופות קודם
              for (const { id } of [...shown()].reverse()) {
                for (const it of itemsOf(id)) if (!isOn(id, it)) drawShape(ctx, id, it, W);
                for (const it of itemsOf(id)) if (isOn(id, it)) drawShape(ctx, id, it, W);
              }
              // התוויות: השיטה המובילה ראשונה — היא שגוברת בחפיפה
              ctx.setLineDash([]);
              for (const { id } of shown()) {
                for (const it of itemsOf(id)) {
                  const l = labelOf(id, it, W);
                  if (l && text(ctx, l.s, l.x, l.y, l.c, l.align, W)) drawn.push({ m: id, k: 'text', c: l.c, alt: false });
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

  // ── התפריט הנפתח (popover) מעל הגרף ───────────────────
  const pop = h('div', { class: 'fc-pop', id: 'fc-pop', hidden: true, role: 'group', 'aria-label': 'שיטות על הגרף' });
  host.append(pop);
  const openNow = () => !pop.hidden;
  function setButtons() {
    for (const b of buttons) {
      b.setAttribute('aria-pressed', String(chosen.length > 0));
      b.setAttribute('aria-expanded', String(openNow()));
      b.setAttribute('aria-controls', 'fc-pop');
    }
  }
  function renderPop() {
    const tf = getTf();
    const head = h('div', { class: 'fc-pop-head' }, h('b', {}, '🔮 שיטות על הגרף'), h('small', { class: 'muted' }, 'אפשר כמה יחד'));
    if (!list) { pop.replaceChildren(head, h('p', { class: 'hint' }, 'טוען…')); return; }
    if (!list.length) {
      // אין שיטה פעילה: צ'יפ אפור, בלי הפניה לניהול אצל משתמש רגיל (18.1.2 סעיף 1)
      pop.replaceChildren(head, h('p', { class: 'chip fc-none' }, 'אין שיטה פעילה'),
        isAdmin() ? h('a', { class: 'fc-admin', href: '#/admin' }, 'ניהול השיטות') : null);
      return;
    }
    pop.replaceChildren(head, h('div', { class: 'fc-opts' }, list.map(m => {
      const ok = m.timeframes.includes(tf);
      const mine = pick(m.id);
      const inp = h('input', { type: 'checkbox', value: m.id });
      inp.checked = !!mine;
      inp.disabled = !ok || (!mine && chosen.length >= MAX);
      inp.addEventListener('change', () => toggle(m.id, inp.checked));
      const opt = h('label', { class: 'fc-opt' + (ok ? '' : ' off'), style: mine ? `--mc: var(${mine.color})` : null }, inp,
        mine ? h('i', { class: 'fc-dot', 'aria-hidden': 'true' }) : null,
        h('span', { class: 'fc-opt-tx' }, h('span', {}, m.name),
          m.label ? h('small', { class: 'muted' }, m.label) : null,
          ok ? null : h('small', { class: 'muted' }, 'לא במסגרת הזו')));
      return h('div', { class: 'fc-row' }, opt, mine ? swatches(m, mine) : null);
    })), chosen.length >= MAX ? h('p', { class: 'fc-max', role: 'status' }, `עד ${MAX} שיטות יחד`) : null,
    h('p', { class: 'fc-note' }, 'תרחיש לפי כללי השיטה על הנרות שבגרף — לא ייעוץ, בלי אחוז ודאות.'));
  }
  /** בחירת הצבע של שיטה מסומנת: 8 עיגולים; צבע של שיטה אחרת שעל הגרף — לא ניתן לבחירה (18.5 קריטריון 2) */
  function swatches(m, mine) {
    return h('div', { class: 'fc-colors', role: 'radiogroup', 'aria-label': `הצבע של ${m.name}` }, PALETTE.map(p => {
      const owner = chosen.find(c => c.color === p && c.id !== m.id);
      const tip = owner ? `${COLOR_NAME[p]} — תפוס (${nameOf(owner.id)})` : COLOR_NAME[p];
      const r = h('input', { type: 'radio', name: 'fc-color-' + m.id, value: p, 'aria-label': tip });
      r.checked = mine.color === p;
      r.disabled = !!owner;
      r.addEventListener('change', () => { if (r.checked) setColor(m.id, p); });
      return h('label', { class: 'fc-sw' + (owner ? ' taken' : ''), title: tip, style: `--sw: var(${p})` }, r);
    }));
  }
  async function openPop() {
    pop.hidden = false;
    setButtons();
    renderPop();
    try {
      list = (await api('methods')).methods;
    } catch (e) { toast(e.message, true); if (!list) { closePop(); return; } }
    remember();   // עכשיו ידוע מה זמין — צבע כפול בין שתי שיטות זמינות נפתר
    if (openNow()) renderPop();
  }
  function closePop() { pop.hidden = true; setButtons(); }
  for (const b of buttons) b.addEventListener('click', () => (openNow() ? closePop() : openPop()));
  // סגירה בלחיצה מחוץ לתפריט (ולא על כפתור 🔮 — הוא סוגר בעצמו), או ב-Esc
  const outside = e => { if (openNow() && !pop.contains(e.target) && !buttons.some(b => b.contains(e.target))) closePop(); };
  const esc = e => { if (e.key === 'Escape' && openNow()) { e.stopImmediatePropagation(); closePop(); buttons[0]?.focus?.(); } };
  document.addEventListener('pointerdown', outside, true);
  document.addEventListener('keydown', esc, true);

  function toggle(id, on) {
    if (on) {
      if (chosen.some(c => c.id === id) || chosen.length >= MAX) return;
      chosen.push({ id, color: colorFor(id, new Set(chosen.map(c => c.color))) });
      remember([id]);
    } else {
      chosen = chosen.filter(c => c.id !== id);
      res.delete(id); selected.delete(id);
      remember([id]);   // הפעם האחרונה שהייתה מסומנת — לכלל "לא סומנה הכי הרבה זמן" (2.20)
    }
    save();
    if (openNow()) renderPop();
    paint();
    onChange();
  }

  // ── המקרא מעל הגרף (S5, 18.5): כל שיטה מסומנת, עם הנקודה בצבע שלה. לחיצה — מסתירה/מציגה, הסימון בתפריט נשאר ──
  const legend = h('div', { class: 'fc-legend', role: 'group', 'aria-label': 'מקרא השיטות', hidden: true });
  host.append(legend);
  function renderLegend() {
    legend.hidden = !chosen.length;
    legend.replaceChildren(...chosen.map(c => h('button', { type: 'button', class: 'fc-leg' + (c.off ? ' off' : ''), 'data-method': c.id,
      style: `--mc: var(${c.color})`, 'aria-pressed': String(!c.off), title: c.off ? 'הצגה על הגרף' : 'הסתרה מהגרף',
      on: { click: () => { c.off = !c.off; save(); paint(); } } }, h('i', { class: 'fc-dot', 'aria-hidden': 'true' }), nameOf(c.id))));
  }
  /** המלבן של המקרא בקואורדינטות של הגרף — ללוח התוויות */
  function legendRect() {
    if (legend.hidden || !chosen.length) return null;
    const el = chart.chartElement?.();
    if (!el) return null;
    const a = legend.getBoundingClientRect(), b = el.getBoundingClientRect();
    if (!a.width) return null;
    return { x: a.left - b.left, y: a.top - b.top, w: a.width, h: a.height, by: 'legend', text: legend.textContent };
  }

  // ── הפס מתחת לגרף (S4, 18.4): ממצא · תרחיש · הסתברות, בלוק לכל שיטה בצבע שלה ──
  const blocks = h('div', { class: 'forecast-blocks' });
  const tfRow = h('div', { class: 'an-tfs', role: 'group', 'aria-label': 'מסגרות זמן לפי הפרופיל' });
  const blendSlot = h('div');
  const panel = h('section', { class: 'forecast-list an-panel', 'aria-label': 'ממצא · תרחיש · הסתברות' },
    h('div', { class: 'an-bar' }, h('h2', { class: 'an-title' }, 'ממצא · תרחיש · הסתברות'), ...tools), tfRow, blendSlot, blocks);
  const card = id => an?.cards?.find(c => c.method.id === id) || null;
  const anR = () => ({ ...(an || {}), asset, tf: getTf(), pair });   // ההקשר של הכרטיס — הנכס והזוג של הגרף
  const set = (id, k, v) => { const c = pick(id); if (c) { c[k] = v; save(); } };

  function paint(scroll = false) {
    // סימונים על נרות — של הספרייה (גם הם על הקנבס)
    // בצבע השיטה (S5); הכיוון — בצורה (חץ למעלה/למטה)
    const marks = [];
    for (const { id } of shown()) for (const x of itemsOf(id)) if (x.kind === 'marker' && isOn(id, x)) marks.push({ time: x.t,
      position: x.side === 'long' ? 'belowBar' : 'aboveBar', shape: x.side === 'long' ? 'arrowUp' : 'arrowDown',
      color: colorOf(id), text: x.text, m: id });
    marks.sort((a, b) => a.time - b.time);
    markers = marks.map(x => ({ m: x.m, k: 'marker', c: x.color, alt: false }));
    series.setMarkers(marks.map(({ m, ...x }) => x));
    // ציר הזמן ימינה: נקודות ריקות עד הזמן העתידי הרחוק ביותר של תרחיש מוצג
    const sec = getSec();
    let far = null, asOf = null;
    for (const { id } of shown()) {
      const ov = res.get(id)?.overlay;
      if (!ov) continue;
      asOf = Math.max(asOf ?? ov.as_of, ov.as_of);
      for (const it of itemsOf(id)) for (const t of [it.t, it.t1, it.t2, ...(it.points || []).map(p => p.t)]) if (t > ov.as_of) far = Math.max(far ?? t, t);
    }
    const ws = [];
    if (far !== null) for (let t = asOf + sec; t <= far; t += sec) ws.push({ time: t });
    whitespace.setData(ws);
    // תרחיש חדש — הגרף זז ימינה כך שההקרנה בתצוגה (בלי לשנות את הזום)
    if (scroll && ws.length) chart.timeScale().scrollToRealTime();
    renderLegend();
    requestUpdate();
    renderPanel();
    for (const f of paintHooks) f();   // החלונית על הגרף — עוקבת אחרי הפריט, או נסגרת כשהוא נעלם (T1)
  }

  const sub = (cls, title, ...body) => h('div', { class: 'an-sec ' + cls }, h('h4', {}, title), ...body.flat().filter(Boolean));

  /** תרחיש: הבחירה בין התרחישים, האזור ורמת הביטול של התרחיש שנבחר, האופק — ו"הסתר תרחיש" */
  function scenarioPart(id, r, c) {
    const fc = r.forecast;
    const sc = fc?.scenarios || [];
    const hidden = !!pick(id)?.hide;
    const hide = h('button', { class: 'btn ghost sm', type: 'button', 'aria-pressed': String(hidden),
      on: { click: () => { set(id, 'hide', !hidden); paint(); } } }, hidden ? 'הצג תרחיש' : 'הסתר תרחיש');
    const titleLine = h('p', { class: 'an-line' }, h('b', {}, r.title), ' ', h('span', { class: 'chip warn' }, r.basis));
    // בלי תרחיש, אבל עם פריטים אחרי הנר האחרון (פיבונאצ'י בלי כיוון — קופסאות דהויות, 22.5ד): גם הם ניתנים להסתרה
    if (!sc.length) return sub('scenario', 'תרחיש', titleLine, h('p', { class: 'hint' }, r.overlay?.note || 'אין כרגע תרחיש לפי כללי השיטה'), c ? C.horizon(c) : null,
      (fc?.items || []).length ? h('div', {}, hide) : null);
    const on = selected.get(id);
    const mine = (fc.items || []).filter(it => !it.scenario || it.scenario === on);
    const zone = mine.find(it => it.kind === 'zone');
    const inv = mine.find(it => it.kind === 'hline' && it.role === 'invalidation');
    const rng = z => [price(id, Math.min(z.p1, z.p2)), price(id, Math.max(z.p1, z.p2))].join(' – ');
    return sub('scenario', 'תרחיש',
      titleLine,
      sc.length > 1 || sc[0].label ? h('div', { class: 'forecast-scenarios', role: 'group', 'aria-label': 'תרחישים' }, sc.map(x => h('button', {
        type: 'button', class: 'btn ghost sm', 'aria-pressed': String(x.id === on),
        on: { click: () => { selected.set(id, x.id); paint(); } } }, x.label))) : null,
      hidden ? h('p', { class: 'hint' }, 'התרחיש מוסתר בגרף. הממצא לא משתנה.') : [
        zone ? h('p', { class: 'an-line' }, 'אזור: ', h('span', { class: 'num' }, rng(zone)), h('small', { class: 'muted' }, ' — ' + zone.text)) : null,
        inv ? h('p', { class: 'an-line' }, 'רמת ביטול: ', h('span', { class: 'num' }, price(id, inv.price)), h('small', { class: 'muted' }, ' — ' + inv.text)) : null],
      c ? C.horizon(c) : null,
      h('p', { class: 'hint', style: 'margin:0' }, 'קו מקווקו ואזור שקוף אחרי הנר האחרון שנסגר. לתרחיש אין אחוז — הוא לא נכנס לשקלול ולא להתראות.'),
      h('div', {}, hide));
  }

  function block(id, color) {
    const r = res.get(id);
    const c = card(id);
    const name = nameOf(id);
    const fold = !!pick(id)?.fold;
    const sec = h('section', { class: 'forecast-panel analysis-card', 'data-method': id, 'aria-label': name, style: `--mc: var(${color})` });
    sec.dataset.asOf = r?.overlay?.as_of ?? '';   // הנר הסגור האחרון של התוצאה — לבדיקות ולמסך המפתח
    const close = h('button', { class: 'btn ghost sm', type: 'button', title: 'הסרת השיטה מהגרף (ביטול הסימון)', on: { click: () => toggle(id, false) } }, 'הסרה');
    const bodyId = 'an-' + id;
    // שורת הסיכום — גם כשהשיטה מקופלת: הכיוון והוודאות, כל אחד עם השם שלו (קריטריון 2)
    const bt = c?.backtest;
    const brief = [C.biasWord(c), bt?.status === 'ok' ? `ודאות ${bt.certainty}%` : c?.status === 'ok' ? 'לא נבדק לאחור' : ''].filter(Boolean).join(' · ');
    const tog = h('button', { class: 'an-fold', type: 'button', 'aria-expanded': String(!fold), 'aria-controls': bodyId,
      on: { click: () => { set(id, 'fold', !fold); renderPanel(); } } },
      h('i', { class: 'fc-dot', 'aria-hidden': 'true' }), h('h3', {}, name), pick(id)?.off ? h('small', { class: 'chip' }, 'מוסתרת בגרף') : null, brief ? h('small', { class: 'muted an-brief' }, brief) : null,
      h('span', { class: 'an-chev', 'aria-hidden': 'true' }, fold ? '▾' : '▴'));
    const head = h('div', { class: 'forecast-head' }, tog, close);
    const body = h('div', { class: 'an-body', id: bodyId, hidden: fold });
    sec.append(head, body);
    if (!r) { body.append(h('p', { class: 'hint' }, 'טוען…')); return sec; }
    if (r.status !== 'ok') { body.append(h('p', { class: 'hint' }, r.message || 'אין תוצאה')); return sec; }
    const alertable = list?.find(m => m.id === id)?.alerts;
    body.append(
      h('div', { class: 'chips' }, r.method.label ? h('span', { class: 'chip' }, r.method.label) : null, h('span', { class: 'chip' }, TF_LABEL[getTf()] || getTf())),
      sub('finding', 'ממצא', c ? C.finding(c, anR()) : h('p', { class: 'hint' }, 'טוען…'),
        // פיבונאצ'י: רמות בודדות (Z9) — דהויות; אפשר להסתיר כשהמסך עמוס. נשמר במכשיר, כמו "הסתר תרחיש"
        singlesOf(id) ? h('div', {}, h('button', { class: 'btn ghost sm fc-solo', type: 'button', 'aria-pressed': String(!!pick(id)?.solo),
          on: { click: () => { set(id, 'solo', !pick(id)?.solo); paint(); } } }, pick(id)?.solo ? `הצג רמות בודדות (${singlesOf(id)})` : 'הסתר רמות בודדות')) : null,
        // אליוט: ההנחיות שהמחשב לא מודד (17.1.3) — "בדוק בעצמך"
        r.overlay.check?.length && !c?.conditions?.some(x => x.met === null) ? h('details', { class: 'param-notes' }, h('summary', {}, '🔎 בדוק בעצמך — מה שהמחשב כאן לא מודד'),
          h('ul', {}, r.overlay.check.map(x => h('li', {}, x)))) : null),
      scenarioPart(id, r, c?.status === 'ok' ? c : null),
      sub('prob', 'הסתברות', c && c.status === 'ok' ? C.probability(c, anR(), running && (bt?.status === 'missing' || bt?.stale)) : h('p', { class: 'hint' }, c ? 'לא נבדק לאחור' : 'טוען…')),
      c ? h('div', { class: 'row-btns wrap' }, ...C.actions(c, anR(), alertable)) : null,
      h('p', { class: 'disclaimer' }, '⚖️ ' + r.disclaimer));
    return sec;
  }

  /** מסגרות הזמן לפי טווח ההשקעה שבפרופיל (5, היה במסך הניתוח) — לחיצה מחליפה את המסגרת של הגרף */
  function drawTfs() {
    const me = ctx.me();
    const want = (me.horizon_tfs?.[me.profile?.horizon] || []).filter(t => tfs().includes(t)
      && chosen.some(c => (list?.find(m => m.id === c.id)?.timeframes || [t]).includes(t)));
    tfRow.hidden = !want.length;
    tfRow.replaceChildren(h('span', { class: 'muted' }, 'לפי הפרופיל: '), ...want.map(t => h('button', {
      class: 'btn ghost sm', type: 'button', 'aria-pressed': String(t === getTf()), on: { click: () => { if (t !== getTf()) setTf(t); } } }, TF_LABEL[t] || t)),
      h('a', { class: 'an-prof', href: '#/profile' }, 'פרופיל'));
  }
  function renderPanel() {
    setButtons();
    panel.hidden = !chosen.length;
    if (!chosen.length) { blendSlot.replaceChildren(); blocks.replaceChildren(); return; }
    drawTfs();
    // שקלול — רק כשיש יותר משיטה אחת עם כרטיס (6.5)
    const cards = an?.cards?.filter(c => chosen.some(x => x.id === c.method.id)) || [];
    const alertable = cards.every(c => list?.find(m => m.id === c.method.id)?.alerts);
    blendSlot.replaceChildren(...(cards.length > 1 && an.blend ? [C.blendCard({ ...anR(), cards }, alertable)] : []));
    blocks.replaceChildren(...chosen.map(c => block(c.id, c.color)));
  }

  /** התוצאות מתוך תשובת chart_refresh. נר חדש — הגרף זז ימינה אל ההקרנה; אותו נר — לא נוגעים בתצוגה */
  function onResult(r) {
    if (!chosen.length) return;
    let moved = false;
    const gone = [];
    for (const { id } of chosen) {
      const next = r?.methods?.[id] || { status: 'error', title: 'תחזית שיטה', message: 'אין תשובה לשיטה' };
      // שיטה שנשמרה בבחירה ובינתיים כובתה (או לא קיימת) — יוצאת מהבחירה בשקט
      if (next.status === 'unavailable') { gone.push(id); continue; }
      const prev = res.get(id);
      if (!prev || prev.overlay?.as_of !== next.overlay?.as_of) moved = true;
      res.set(id, next);
      const sc = next.forecast?.scenarios || [];
      const ids = sc.map(s => s.id);
      if (!ids.includes(selected.get(id))) selected.set(id, sc.find(s => s.primary)?.id ?? ids[0] ?? null);
    }
    if (gone.length) { chosen = chosen.filter(c => !gone.includes(c.id)); for (const id of gone) res.delete(id); save(); }
    if (r?.analysis) an = r.analysis;
    paint(moved);
  }

  /** הבקשה נכשלה. שיטה בלי תוצאה קודמת — הודעה בבלוק שלה; עם תוצאה — היא נשארת (הגרף מציג "עודכן לפני X") */
  function onError(e) {
    let any = false;
    for (const { id } of chosen) if (!res.has(id)) { res.set(id, { status: 'error', title: 'תחזית שיטה', message: e.message }); any = true; }
    if (any) paint();
  }

  // ── החלונית על הגרף (T1, 18.1.6) — מה מצויר בנקודה, מאותה גאומטריה של הציור (xOf/yOf) ──
  // רק מה שמוצג: שיטה גלויה, התרחיש הנבחר (לא חלופה עמומה). הקטן קודם: נקודה, קו, אזור (18.1.6 סעיף 1)
  const RANK = { label: 0, marker: 0, hline: 1, polyline: 1, zone: 2 };
  /** הנקודה על פריט שהכי קרובה ל-(px, py) — שם הזנב מצביע: נקודה — היא; קו — בגובה הלחיצה; אזור — הלחיצה בתוכו */
  function anchorOf(it, px, py, W) {
    if (it.kind === 'label' || it.kind === 'marker') {
      const x = xOf(it.t);
      let y = it.kind === 'label' ? yOf(it.price) : null;
      if (it.kind === 'marker') {
        // הסימון של הספרייה — מתחת לנר (לונג) או מעליו (שורט)
        const l = timeToLogical(getTimes(), getSec(), it.t), bar = l === null ? null : series.dataByIndex?.(Math.round(l));
        if (bar) y = it.side === 'long' ? yOf(bar.low) + 12 : yOf(bar.high) - 12;
      }
      return x === null || y === null ? null : { x, y };
    }
    if (it.kind === 'hline') { const y = yOf(it.price); return y === null ? null : { x: px, y }; }
    if (it.kind === 'polyline') {
      const pts = it.points.map(q => [xOf(q.t), yOf(q.price)]);
      if (pts.some(([x, y]) => x === null || y === null)) return null;
      if (it.extend) pts[pts.length - 1][0] = W;
      let best = null;
      for (let i = 1; i < pts.length; i++) {
        const [x1, y1] = pts[i - 1], [x2, y2] = pts[i], dx = x2 - x1, dy = y2 - y1, l2 = dx * dx + dy * dy;
        const k = l2 ? Math.max(0, Math.min(1, ((px - x1) * dx + (py - y1) * dy) / l2)) : 0;
        const c = { x: x1 + k * dx, y: y1 + k * dy };
        if (!best || Math.hypot(px - c.x, py - c.y) < Math.hypot(px - best.x, py - best.y)) best = c;
      }
      return best;
    }
    if (it.kind === 'zone') {
      const x1 = xOf(it.t1), x2 = it.extend ? W : xOf(it.t2), y1 = yOf(it.p1), y2 = yOf(it.p2);
      if ([x1, x2, y1, y2].includes(null)) return null;
      return { x: Math.max(Math.min(x1, x2), Math.min(px, Math.max(x1, x2))), y: Math.max(Math.min(y1, y2), Math.min(py, Math.max(y1, y2))) };
    }
    return null;
  }
  // זהות של פריט בין ציור לציור (רענון — הנר הבא נסגר): בלי מחירים וזמנים שזזים, חוץ מהזמן של נקודה
  const keyOf = (id, it) => [id, it.kind, it.role || '', it.scenario ?? '', it.fib || '', it.r ?? '', it.kind === 'label' || it.kind === 'marker' ? it.t : '', it.kind === 'label' ? it.text : ''].join('|');
  const hitOf = (id, it, a, px, py) => ({ id, it, key: keyOf(id, it), x: a.x, y: a.y, px, py,
    t: it.kind === 'label' || it.kind === 'marker' ? it.t : chart.timeScale().coordinateToTime(a.x), p: it.kind === 'label' ? it.price : series.coordinateToPrice(a.y),
    name: nameOf(id), color: pick(id)?.color || '--brand', r: res.get(id), price: v => price(id, v) });
  /** כל מה שבטווח R מהלחיצה (אצבע: 24px), הקטן והקרוב ראשון. [] — כלום */
  function hitsAt(px, py, W, R = 24) {
    const out = [];
    for (const { id } of shown()) for (const it of itemsOf(id)) {
      if (!isOn(id, it) || !(it.kind in RANK)) continue;
      const a = anchorOf(it, px, py, W);
      if (!a) continue;
      const d = Math.hypot(px - a.x, py - a.y);
      if (d <= R) out.push({ ...hitOf(id, it, a, px, py), d, rank: RANK[it.kind] });
    }
    return out.sort((a, b) => a.rank - b.rank || a.d - b.d);
  }
  /** אחרי ציור מחדש: אותו פריט, במקום החדש שלו — או null אם הוא כבר לא מוצג */
  function relocate(hit, W) {
    for (const { id } of shown()) for (const it of itemsOf(id)) {
      if (!isOn(id, it) || keyOf(id, it) !== hit.key) continue;
      const a = anchorOf(it, hit.px, hit.py, W);
      return a ? hitOf(id, it, a, hit.px, hit.py) : null;
    }
    return null;
  }
  const paintHooks = new Set();

  // רשימת השיטות — לשמות, למסגרות ול"התראה מזה" (alerts). פעם אחת; התפריט טוען שוב כשנפתח
  api('methods').then(x => { list ??= x.methods; remember(); renderLegend(); renderPanel(); }).catch(() => {});
  reconcile();
  save();
  remember();   // צבע שהיה רק במכשיר (לפני S5) — נכנס לפרופיל
  renderLegend();
  renderPanel();

  return {
    panel,
    /** השיטות שנבחרו — נשלחות בכל בקשה של הגרף. ריק = אין ניתוח פעיל, ואין רענון אוטומטי (18.3) */
    methods: () => chosen.map(c => c.id),
    onResult,
    onError,
    /** חסרה בדיקה לאחור (אין שמורה, או שעבר יום) לשיטה שמוצגת — הגרף יבקש analysis, רק בפעולה של המשתמש (18.3) */
    needsBacktest: () => !!an?.cards?.some(c => c.status === 'ok' && chosen.some(x => x.id === c.method.id) && (c.backtest?.status === 'missing' || c.backtest?.stale)),
    /** הבדיקה לאחור רצה (analysis) — מציגים "מריץ…" בהסתברות; התשובה מחליפה את הכרטיסים */
    backtesting(on) { running = on; renderPanel(); },
    onAnalysis(a) {
      running = false;
      if (a?.cards) an = { ...(an || {}), ...a };
      renderPanel();
    },
    /** מסגרת הזמן השתנתה — התפריט פתוח מסמן מחדש מה זמין; הכרטיס של המסגרת הקודמת לא תקף */
    retf: () => { an = null; if (openNow()) renderPop(); renderPanel(); },
    popOpen: openNow,
    closePop,
    hitsAt,
    relocate,
    /** fn — נקרא אחרי כל ציור מחדש של השיטות (תוצאה חדשה, מקרא, תרחיש). מחזיר ביטול */
    onPaint: fn => { paintHooks.add(fn); return () => paintHooks.delete(fn); },
    /** לבדיקות: מה שמוצג עכשיו מסוג kind (label / hline / zone), ונקודה עליו בקואורדינטות של הגרף */
    points: (kind = 'label') => {
      const W = chart.chartElement?.()?.clientWidth || 0;   // timeScale().width() — 0, כי ציר הזמן מוסתר (הצירים בתוך הגרף)
      return shown().flatMap(({ id }) => itemsOf(id).filter(it => it.kind === kind && isOn(id, it)).map(it => {
        if (kind === 'hline') return { id, role: it.role, x: W / 2, y: yOf(it.price) };
        if (kind === 'zone') { const x1 = xOf(it.t1), x2 = it.extend ? W : xOf(it.t2); const y1 = yOf(it.p1), y2 = yOf(it.p2); return { id, scen: !!it.scen, x: x1 === null || x2 === null ? null : (x1 + x2) / 2, y: (y1 + y2) / 2, box: [x1, y1, x2, y2] }; }
        return { id, text: it.text, x: xOf(it.t), y: yOf(it.price) };
      })).filter(q => q.x !== null && q.y !== null && Number.isFinite(q.y));
    },
    destroy() {
      document.removeEventListener('pointerdown', outside, true);
      document.removeEventListener('keydown', esc, true);
      try { series.detachPrimitive(primitive); } catch { /* ריק */ }
    },
    state: () => ({ methods: chosen.map(c => ({ ...c, off: !!c.off, selected: selected.get(c.id) ?? null, asOf: res.get(c.id)?.overlay?.as_of ?? null, items: itemsOf(c.id).length,
      finding: findingOf(c.id).length, scenario: scenarioOf(c.id).length })), drawn: [...drawn, ...markers], colors: { ...saved() } }),
  };
}
