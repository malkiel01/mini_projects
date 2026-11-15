// "🔮 שיטות על הגרף" — spec-stage-a 17.2.2 (✅15), 18.1.2 (F1). השיטה רצה בשרת על הנרות המוצגים ומחזירה overlay
// (החוזה — lib/overlay.php, 17.2.3), והגרף מצייר אותו: אזורים, קווים, תוויות וסימונים — **על הקנבס של הגרף**
// (Primitive של Lightweight Charts, כמו הציור), כולל הקרנה קדימה. הזמנים העתידיים (t + k·tf) נכנסים לציר כנקודות ריקות
// (whitespace) בסדרה נפרדת, כך שציר הזמן מתרחב ימינה בלי לגעת בנרות.
//
// **הבחירה (F1, שינוי מהבעלים 2026-11-15):** 🔮 פותח תפריט נפתח מעל הגרף — רשימת שיטות עם תיבות סימון. בלי sheet ובלי
// מסך אחר; הגרף גלוי. **אפשר כמה שיטות יחד**, כל אחת בצבע הפנוי הבא מהפלטה, והכול בבקשת chart_refresh אחת.
// הבחירה נשמרת במכשיר (localStorage). סגירה — לחיצה מחוץ לתפריט, או Esc. המקרא ובחירת הצבע — S5.
//
// **התוויות (18.1.2 סעיף 5):** תווית של קו או רמה — בצד ימין; חופפת לתווית שכבר צוירה — לא מצוירת (labelBoard, השיטה
// הראשונה והתרחיש המוביל קודמים); ספירות חלופיות — בלי טקסט, רק קו עמום (17.1.7).
//
// בלי אחוז ודאות; הציון — רק במילים (✅16), כשיש לשיטה ציון.
//
// **הרענון (18.3, S3, ✅23):** המודול הזה לא פונה לשרת בעצמו, חוץ מרשימת השיטות כשפותחים את התפריט. התוצאה מגיעה בבקשה
// של הגרף (chart_refresh ב-market.js). שינוי בבחירה מבקש מהגרף טעינה (onChange).

import { timeToLogical, logicalX } from './drawings.js';
import { fmtNum } from './chartaxis.js';

const ROLE = { stop: '--danger', target: '--ok', invalidation: '--danger' };
// הפלטה של השיטות — שיטה חדשה מקבלת את הצבע הפנוי הראשון (S5 יוסיף בחירה ומקרא)
export const PALETTE = ['--brand', '--m2', '--m3', '--m4', '--m5', '--m6', '--m7', '--m8'];
const KEY = 'ts-forecast-methods';
const MAX = PALETTE.length;   // = TS_REFRESH_MAX_METHODS בשרת

function loadChosen() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || '[]');
    if (!Array.isArray(v)) return [];
    const out = [];
    for (const x of v) if (x && typeof x.id === 'string' && PALETTE.includes(x.color) && !out.some(o => o.id === x.id)) out.push({ id: x.id, color: x.color });
    return out.slice(0, MAX);
  } catch { return []; }
}

export function mountForecast({ chart, series, h, host, isAdmin = () => false, api, toast, getTf, getSec, getTimes, col, buttons, board, onChange, axisWidth = () => 0 }) {
  let chosen = loadChosen();     // [{id, color}] — לפי סדר הבחירה; הראשונה מובילה
  const res = new Map();         // id → התשובה האחרונה מ-chart_refresh
  const selected = new Map();    // id → מזהה התרחיש המודגש
  let list = null;               // השיטות הזמינות (api methods) — נטענות כשפותחים את התפריט
  let requestUpdate = () => {};

  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(chosen)); } catch { /* ריק */ } };
  const colorOf = id => col(chosen.find(c => c.id === id)?.color || '--brand');
  const whitespace = chart.addLineSeries({ visible: false, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
  const xOf = t => { const l = timeToLogical(getTimes(), getSec(), t); return l === null ? null : logicalX(chart.timeScale(), l); };
  const yOf = p => series.priceToCoordinate(p);
  const itemsOf = id => res.get(id)?.overlay?.items || [];
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
  /** התווית של פריט — איפה ובאיזה צבע; null — אין (גם לחלופות: בלי טקסט, 17.1.7) */
  function labelOf(id, it, W) {
    if (!isOn(id, it)) return null;
    if (it.kind === 'zone') {
      const x1 = xOf(it.t1), x2 = xOf(it.t2), y1 = yOf(it.p1), y2 = yOf(it.p2);
      if (!it.text || [x1, x2, y1, y2].includes(null)) return null;
      // בפינה הימנית העליונה של האזור (ההווה), לא בנקודת ההתחלה
      return { s: it.text, x: Math.min(Math.max(x1, x2) - 4, W - 6), y: Math.min(y1, y2) + 16, c: colorOf(id), align: 'right' };
    }
    if (it.kind === 'hline') {
      const y = yOf(it.price);
      // משמאל לעמודת המספרים של הציר — לא מעליהם (🟡 F1: "רמת ביטול" כיסתה את 82,500)
      return y === null ? null : { s: `${it.text} · ${price(id, it.price)}`, x: W - 6 - axisWidth(), y, c: col(ROLE[it.role] || '--muted'), align: 'right' };
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
    if (it.kind === 'zone') {
      const x1 = xOf(it.t1), x2 = xOf(it.t2), y1 = yOf(it.p1), y2 = yOf(it.p2);
      if ([x1, x2, y1, y2].includes(null)) return;
      ctx.fillStyle = c; ctx.globalAlpha *= 0.14;
      ctx.fillRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
      ctx.globalAlpha /= 0.14; ctx.strokeStyle = c; ctx.lineWidth = 1;
      ctx.strokeRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
      return;
    }
    if (it.kind === 'hline') {
      const y = yOf(it.price);
      if (y === null) return;
      const rc = col(ROLE[it.role] || '--muted');
      ctx.strokeStyle = rc;
      ctx.setLineDash(it.role === 'invalidation' ? [6, 4] : [2, 3]);
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
      ctx.setLineDash([]);
      return;
    }
    if (it.kind === 'polyline') {
      const pts = it.points.map(p => [xOf(p.t), yOf(p.price)]);
      if (pts.some(([x, y]) => x === null || y === null)) return;
      ctx.strokeStyle = c;
      ctx.setLineDash(it.style === 'dashed' ? [7, 5] : []);
      ctx.beginPath(); pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y))); ctx.stroke();
      return;
    }
    if (it.kind === 'label') {
      const x = xOf(it.t), y = yOf(it.price);
      if (x === null || y === null) return;
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
            target.useMediaCoordinateSpace(({ context: ctx, mediaSize }) => {
              const W = mediaSize.width;
              ctx.save();
              // הקווים: השיטה האחרונה קודם, המובילה מעליה; בכל שיטה — החלופות קודם
              for (const { id } of [...chosen].reverse()) {
                for (const it of itemsOf(id)) if (!isOn(id, it)) drawShape(ctx, id, it, W);
                for (const it of itemsOf(id)) if (isOn(id, it)) drawShape(ctx, id, it, W);
              }
              // התוויות: השיטה המובילה ראשונה — היא שגוברת בחפיפה
              ctx.setLineDash([]);
              for (const { id } of chosen) {
                for (const it of itemsOf(id)) {
                  const l = labelOf(id, it, W);
                  if (l) text(ctx, l.s, l.x, l.y, l.c, l.align, W);
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
      const on = chosen.some(c => c.id === m.id);
      const inp = h('input', { type: 'checkbox', value: m.id });
      inp.checked = on;
      inp.disabled = !ok || (!on && chosen.length >= MAX);
      inp.addEventListener('change', () => toggle(m.id, inp.checked));
      return h('label', { class: 'fc-opt' + (ok ? '' : ' off') }, inp,
        h('span', { class: 'fc-opt-tx' }, h('span', {}, m.name),
          m.label ? h('small', { class: 'muted' }, m.label) : null,
          ok ? null : h('small', { class: 'muted' }, 'לא במסגרת הזו')));
    })), h('p', { class: 'fc-note' }, 'תרחיש לפי כללי השיטה על הנרות שבגרף — לא ייעוץ, בלי אחוז ודאות.'));
  }
  async function openPop() {
    pop.hidden = false;
    setButtons();
    renderPop();
    try {
      list = (await api('methods')).methods;
    } catch (e) { toast(e.message, true); if (!list) { closePop(); return; } }
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
      const used = new Set(chosen.map(c => c.color));
      chosen.push({ id, color: PALETTE.find(p => !used.has(p)) });
    } else {
      chosen = chosen.filter(c => c.id !== id);
      res.delete(id); selected.delete(id);
    }
    save();
    if (openNow()) renderPop();
    paint();
    onChange();
  }

  // ── הפאנל מתחת לגרף — בלוק לכל שיטה ──────────────────
  const panel = h('div', { class: 'forecast-list', 'aria-live': 'polite' });

  function paint(scroll = false) {
    // סימונים על נרות — של הספרייה (גם הם על הקנבס)
    const marks = [];
    for (const { id } of chosen) for (const x of itemsOf(id)) if (x.kind === 'marker' && isOn(id, x)) marks.push({ time: x.t,
      position: x.side === 'long' ? 'belowBar' : 'aboveBar', shape: x.side === 'long' ? 'arrowUp' : 'arrowDown',
      color: col(x.side === 'long' ? '--ok' : '--danger'), text: x.text });
    marks.sort((a, b) => a.time - b.time);
    series.setMarkers(marks);
    // ציר הזמן ימינה: נקודות ריקות עד הזמן העתידי הרחוק ביותר
    const sec = getSec();
    let far = null, asOf = null;
    for (const { id } of chosen) {
      const ov = res.get(id)?.overlay;
      if (!ov) continue;
      asOf = Math.max(asOf ?? ov.as_of, ov.as_of);
      for (const it of ov.items) for (const t of [it.t, it.t1, it.t2, ...(it.points || []).map(p => p.t)]) if (t > ov.as_of) far = Math.max(far ?? t, t);
    }
    const ws = [];
    if (far !== null) for (let t = asOf + sec; t <= far; t += sec) ws.push({ time: t });
    whitespace.setData(ws);
    // תרחיש חדש — הגרף זז ימינה כך שההקרנה בתצוגה (בלי לשנות את הזום)
    if (scroll && ws.length) chart.timeScale().scrollToRealTime();
    requestUpdate();
    renderPanel();
  }

  function block(id, color) {
    const r = res.get(id);
    const name = list?.find(m => m.id === id)?.name;
    const sec = h('section', { class: 'forecast-panel', 'data-method': id, 'aria-label': 'תחזית שיטה', style: `--mc: var(${color})` });
    sec.dataset.asOf = r?.overlay?.as_of ?? '';   // הנר הסגור האחרון של התוצאה — לבדיקות ולמסך המפתח
    const close = h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => toggle(id, false) } }, 'הסתרה');
    const dot = h('i', { class: 'fc-dot', 'aria-hidden': 'true' });
    if (!r) { sec.append(h('div', { class: 'forecast-head' }, dot, h('h3', {}, '🔮 ' + (name || 'שיטה')), close), h('p', { class: 'hint' }, 'טוען תרחיש…')); return sec; }
    const head = h('div', { class: 'forecast-head' }, dot, h('h3', {}, '🔮 ' + r.title), close);
    if (r.status !== 'ok') { sec.append(head, h('p', { class: 'hint' }, r.message || 'אין תרחיש')); return sec; }
    const chips = h('div', { class: 'chips' },
      h('span', { class: 'chip warn' }, r.basis),
      r.backtested ? null : h('span', { class: 'chip bad' }, 'לא נבדקה לאחור'),
      r.method.label ? h('span', { class: 'chip' }, r.method.label) : null);
    const sc = r.overlay.projection.scenarios;
    const scl = sc.length
      ? h('div', { class: 'forecast-scenarios', role: 'group', 'aria-label': 'תרחישים' }, sc.map(s => h('button', {
          type: 'button', class: 'btn ghost sm', 'aria-pressed': String(s.id === selected.get(id)),
          on: { click: () => { selected.set(id, s.id); paint(); } } }, s.label)))
      : h('p', { class: 'hint' }, r.overlay.note || 'אין כרגע תרחיש לפי כללי השיטה');
    sec.append(head, chips, scl,
      // אליוט: ההנחיות שהמחשב לא מודד (17.1.3) — "בדוק בעצמך"
      r.overlay.check?.length ? h('details', { class: 'param-notes' }, h('summary', {}, '🔎 בדוק בעצמך — מה שהמחשב כאן לא מודד'),
        h('ul', {}, r.overlay.check.map(x => h('li', {}, x)))) : null,
      h('p', { class: 'disclaimer' }, '⚖️ ' + r.disclaimer));
    return sec;
  }
  function renderPanel() {
    setButtons();
    panel.hidden = !chosen.length;
    panel.replaceChildren(...chosen.map(c => block(c.id, c.color)));
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
      const ids = next.overlay?.projection.scenarios.map(s => s.id) || [];
      if (!ids.includes(selected.get(id))) selected.set(id, next.overlay?.projection.scenarios.find(s => s.primary)?.id ?? ids[0] ?? null);
    }
    if (gone.length) { chosen = chosen.filter(c => !gone.includes(c.id)); for (const id of gone) res.delete(id); save(); }
    paint(moved);
  }

  /** הבקשה נכשלה. שיטה בלי תוצאה קודמת — הודעה בבלוק שלה; עם תוצאה — היא נשארת (הגרף מציג "עודכן לפני X") */
  function onError(e) {
    let any = false;
    for (const { id } of chosen) if (!res.has(id)) { res.set(id, { status: 'error', title: 'תחזית שיטה', message: e.message }); any = true; }
    if (any) paint();
  }

  renderPanel();

  return {
    panel,
    /** השיטות שנבחרו — נשלחות בכל בקשה של הגרף. ריק = אין ניתוח פעיל, ואין רענון אוטומטי (18.3) */
    methods: () => chosen.map(c => c.id),
    onResult,
    onError,
    /** מסגרת הזמן השתנתה — התפריט פתוח מסמן מחדש מה זמין */
    retf: () => { if (openNow()) renderPop(); },
    popOpen: openNow,
    closePop,
    destroy() {
      document.removeEventListener('pointerdown', outside, true);
      document.removeEventListener('keydown', esc, true);
      try { series.detachPrimitive(primitive); } catch { /* ריק */ }
    },
    state: () => ({ methods: chosen.map(c => ({ ...c, selected: selected.get(c.id) ?? null, asOf: res.get(c.id)?.overlay?.as_of ?? null, items: itemsOf(c.id).length })) }),
  };
}
