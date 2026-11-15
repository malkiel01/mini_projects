// "🔮 תחזית שיטה" — spec-stage-a 17.2.2 (✅15): מצב נפרד מ"ניתוח". השיטה רצה בשרת על הנרות המוצגים ומחזירה overlay
// (החוזה — lib/overlay.php, 17.2.3), והגרף מצייר אותו: אזורים, קווים, תוויות וסימונים — **על הקנבס של הגרף**
// (Primitive של Lightweight Charts, כמו הציור), כולל הקרנה קדימה. הזמנים העתידיים (t + k·tf) נכנסים לציר כנקודות ריקות
// (whitespace) בסדרה נפרדת, כך שציר הזמן מתרחב ימינה בלי לגעת בנרות.
//
// בלי אחוז ודאות; הציון — רק במילים (✅16), כשיש לשיטה ציון.
//
// **הרענון (18.3, S3, ✅23):** המודול הזה לא פונה לשרת בעצמו. התוצאה מגיעה בבקשה של הגרף (chart_refresh ב-market.js) —
// בקשה אחת לנרות ולכל השיטות שנבחרו, גם בפתיחה, בשינוי טווח או מסגרת, ב-"↻" וברענון האוטומטי. בחירת שיטה מבקשת מהגרף
// טעינה (onChange). רענון שלא נסגר בו נר — אותה תוצאה מהשרת, והתרחיש שהמשתמש סימן נשאר מסומן.

import { timeToLogical } from './drawings.js';

const ROLE = { stop: '--danger', target: '--ok', invalidation: '--danger' };

export function mountForecast({ chart, series, asset, api, h, sheet, toast, getTf, getSec, getTimes, col, fmtPrice, button, onChange }) {
  let methodId = null;     // השיטה שנבחרה (null — כבוי)
  let res = null;          // התשובה האחרונה מ-method_overlay
  let selected = null;     // מזהה התרחיש המודגש
  let requestUpdate = () => {};

  const whitespace = chart.addLineSeries({ visible: false, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
  const xOf = t => { const l = timeToLogical(getTimes(), getSec(), t); return l === null ? null : chart.timeScale().logicalToCoordinate(l); };
  const yOf = p => series.priceToCoordinate(p);
  const items = () => (res && res.overlay ? res.overlay.items : []);
  const isOn = it => !it.scenario || it.scenario === selected;
  // מחיר לתצוגה לפי ה-tick של הנכס (מהשרת) — float לתצוגה בלבד
  const price = v => (Number.isInteger(res?.tick_decimals) ? (+v).toFixed(res.tick_decimals) : fmtPrice(v));

  // ── ציור על הקנבס ───────────────────────────────────────
  function text(ctx, s, x, y, color, align = 'left') {
    ctx.font = '12px system-ui, sans-serif';
    ctx.textAlign = align;
    ctx.textBaseline = 'bottom';
    const w = ctx.measureText(s).width;
    ctx.fillStyle = col('--surface');
    ctx.globalAlpha *= 0.85;
    ctx.fillRect(align === 'right' ? x - w - 3 : x - 3, y - 15, w + 6, 15);
    ctx.globalAlpha /= 0.85;
    ctx.fillStyle = color;
    ctx.fillText(s, x, y - 2);
  }
  function drawItem(ctx, it, W) {
    const brand = col('--brand');
    ctx.globalAlpha = isOn(it) ? 1 : 0.3;   // החלופות עמומות (17.1.7)
    ctx.setLineDash([]);
    ctx.lineWidth = 2;
    if (it.kind === 'zone') {
      const x1 = xOf(it.t1), x2 = xOf(it.t2), y1 = yOf(it.p1), y2 = yOf(it.p2);
      if ([x1, x2, y1, y2].includes(null)) return;
      ctx.fillStyle = brand; ctx.globalAlpha *= 0.14;
      ctx.fillRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
      ctx.globalAlpha /= 0.14; ctx.strokeStyle = brand; ctx.lineWidth = 1;
      ctx.strokeRect(Math.min(x1, x2), Math.min(y1, y2), Math.abs(x2 - x1), Math.abs(y2 - y1));
      if (it.text && isOn(it)) text(ctx, it.text, Math.min(x1, x2) + 4, Math.min(y1, y2) + 16, brand);
    } else if (it.kind === 'hline') {
      const y = yOf(it.price);
      if (y === null) return;
      const c = col(ROLE[it.role] || '--muted');
      ctx.strokeStyle = c;
      ctx.setLineDash(it.role === 'invalidation' ? [6, 4] : [2, 3]);
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
      ctx.setLineDash([]);
      if (isOn(it)) text(ctx, `${it.text} · ${price(it.price)}`, W - 6, y, c, 'right');
    } else if (it.kind === 'polyline') {
      const pts = it.points.map(p => [xOf(p.t), yOf(p.price)]);
      if (pts.some(([x, y]) => x === null || y === null)) return;
      ctx.strokeStyle = brand;
      ctx.setLineDash(it.style === 'dashed' ? [7, 5] : []);
      ctx.beginPath(); pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y))); ctx.stroke();
    } else if (it.kind === 'label') {
      const x = xOf(it.t), y = yOf(it.price);
      if (x === null || y === null) return;
      ctx.fillStyle = brand; ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill();
      text(ctx, it.text, x + 5, y - 3, brand);
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
              // החלופות קודם, המודגש מעליהן
              for (const it of items().filter(x => !isOn(x))) drawItem(ctx, it, mediaSize.width);
              for (const it of items().filter(isOn)) drawItem(ctx, it, mediaSize.width);
              ctx.restore();
            });
          },
        }),
      }];
    },
  };
  series.attachPrimitive(primitive);

  // ── הפאנל מתחת לגרף ─────────────────────────────────────
  const panel = h('section', { class: 'forecast-panel', hidden: true, 'aria-live': 'polite', 'aria-label': 'תחזית שיטה' });
  // הכפתור בכותרת הגרף (market.js יוצר אותו לפני הגרף)
  button.addEventListener('click', () => pick());

  function paint(scroll = false) {
    const ov = res?.overlay;
    // סימונים על נרות — של הספרייה (גם הם על הקנבס)
    series.setMarkers(!ov ? [] : ov.items.filter(x => x.kind === 'marker' && isOn(x)).map(x => ({ time: x.t,
      position: x.side === 'long' ? 'belowBar' : 'aboveBar', shape: x.side === 'long' ? 'arrowUp' : 'arrowDown',
      color: col(x.side === 'long' ? '--ok' : '--danger'), text: x.text })));
    // ציר הזמן ימינה: נקודות ריקות עד הזמן העתידי הרחוק ביותר
    const sec = getSec();
    let far = null;
    if (ov) for (const it of ov.items) for (const t of [it.t, it.t1, it.t2, ...(it.points || []).map(p => p.t)]) if (t > ov.as_of) far = Math.max(far ?? t, t);
    const ws = [];
    if (far !== null) for (let t = ov.as_of + sec; t <= far; t += sec) ws.push({ time: t });
    whitespace.setData(ws);
    // תרחיש חדש — הגרף זז ימינה כך שההקרנה בתצוגה (בלי לשנות את הזום)
    if (scroll && ws.length) chart.timeScale().scrollToRealTime();
    requestUpdate();
    renderPanel();
  }

  function renderPanel() {
    button.setAttribute('aria-pressed', String(!!methodId));
    panel.hidden = !methodId;
    panel.dataset.asOf = res?.overlay?.as_of ?? '';   // הנר הסגור האחרון של התוצאה — לבדיקות ולמסך המפתח
    if (!methodId) { panel.replaceChildren(); return; }
    const close = h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => off() } }, 'הסתרה');
    if (!res) { panel.replaceChildren(h('p', { class: 'hint' }, 'טוען תרחיש…')); return; }
    const head = h('div', { class: 'forecast-head' }, h('h3', {}, '🔮 ' + res.title), close);
    if (res.status !== 'ok') {
      panel.replaceChildren(head, h('p', { class: 'hint' }, res.message || 'אין תרחיש'));
      return;
    }
    const chips = h('div', { class: 'chips' },
      h('span', { class: 'chip warn' }, res.basis),
      res.backtested ? null : h('span', { class: 'chip bad' }, 'לא נבדקה לאחור'),
      res.method.label ? h('span', { class: 'chip' }, res.method.label) : null);
    const sc = res.overlay.projection.scenarios;
    const list = sc.length
      ? h('div', { class: 'forecast-scenarios', role: 'group', 'aria-label': 'תרחישים' }, sc.map(s => h('button', {
          type: 'button', class: 'btn ghost sm', 'aria-pressed': String(s.id === selected),
          on: { click: () => { selected = s.id; paint(); } } }, s.label)))
      : h('p', { class: 'hint' }, res.overlay.note || 'אין כרגע תרחיש לפי כללי השיטה');
    panel.replaceChildren(head, chips,
      list,
      // אליוט: ההנחיות שהמחשב לא מודד (17.1.3) — "בדוק בעצמך"
      res.overlay.check?.length ? h('details', { class: 'param-notes' }, h('summary', {}, '🔎 בדוק בעצמך — מה שהמחשב כאן לא מודד'),
        h('ul', {}, res.overlay.check.map(x => h('li', {}, x)))) : null,
      h('p', { class: 'disclaimer' }, '⚖️ ' + res.disclaimer));
  }

  /** התוצאה של השיטה מתוך תשובת chart_refresh. נר חדש — הגרף זז ימינה אל ההקרנה; אותו נר — לא נוגעים בתצוגה */
  function onResult(r) {
    if (!methodId) return;
    const next = r?.methods?.[methodId] || { status: 'error', title: 'תחזית שיטה', message: 'אין תשובה לשיטה' };
    const moved = !res || res.overlay?.as_of !== next.overlay?.as_of;
    res = next;
    const ids = res.overlay?.projection.scenarios.map(s => s.id) || [];
    if (!ids.includes(selected)) selected = res.overlay?.projection.scenarios.find(s => s.primary)?.id ?? ids[0] ?? null;
    paint(moved);
  }

  /** הבקשה נכשלה. בלי תוצאה קודמת — הודעה בפאנל; עם תוצאה — היא נשארת (הגרף מציג "עודכן לפני X") */
  function onError(e) {
    if (!methodId || res) return;
    res = { status: 'error', title: 'תחזית שיטה', message: e.message };
    paint();
  }

  async function pick() {
    let methods = [];
    try { ({ methods } = await api('methods')); } catch (e) { toast(e.message, true); return; }
    const s = sheet('🔮 תחזית שיטה');
    const tf = getTf();
    s.body.append(h('p', { class: 'hint' }, 'תרחיש לפי כללי השיטה על הנרות שבגרף — לא ייעוץ, בלי אחוז ודאות.'));
    if (!methods.length) s.body.append(h('p', { class: 'hint' }, 'אין שיטות זמינות. מנהל מפעיל שיטות ב"עוד ← ניהול ← שיטות".'));
    for (const m of methods) {
      const ok = m.timeframes.includes(tf);
      s.body.append(h('button', { type: 'button', class: 'btn ghost block', disabled: !ok, 'aria-pressed': String(m.id === methodId),
        on: { click: () => { s.close(); if (m.id === methodId) return; methodId = m.id; res = null; selected = null; renderPanel(); onChange(); } } },
        m.name, m.label ? h('small', { class: 'muted' }, ' · ' + m.label) : null, ok ? null : h('small', { class: 'muted' }, ' · לא במסגרת הזו')));
    }
    if (methodId) s.body.append(h('button', { type: 'button', class: 'btn ghost block', on: { click: () => { s.close(); off(); } } }, 'הסתרת התחזית'));
  }

  function off() { methodId = null; res = null; selected = null; paint(); onChange(); }

  return {
    panel,
    /** השיטות שנבחרו — נשלחות בכל בקשה של הגרף. ריק = אין ניתוח פעיל, ואין רענון אוטומטי (18.3) */
    methods: () => (methodId ? [methodId] : []),
    onResult,
    onError,
    destroy() { try { series.detachPrimitive(primitive); } catch { /* ריק */ } },
    state: () => ({ methodId, selected, asOf: res?.overlay?.as_of ?? null, items: items().length }),
  };
}
