// T1 — חלונית הסבר על נקודת תחזית (spec-stage-a 18.1.6, 2.33). לחיצה על מה ששיטה ציירה — נקודת מפנה או סימון, אזור, קו אופקי
// או קו תרחיש — פותחת חלונית צפה מעל הגרף: לבנה (כהה במצב כהה), מעוגלת, עם ✕, וזנב שמצביע על נקודת הלחיצה — מעליה או מתחתיה,
// לפי המקום הפנוי. בתוכה: כותרת, מחיר וזמן, "שיטה · ממצא/תרחיש", הסבר, רמת ביטול, קישורים ו"לא ייעוץ".
//
// - **התוכן** — משדה הרשות `tip` של הפריט (חוזה ה-overlay, 17.2.3): {title, sub?, explain?, invalidation?{price, side, rule?},
//   links?[]}. **בלי `tip`** — הכותרת היא `text` של הפריט, בלי הסבר. החלונית לא שולחת שום בקשה: הכול כבר ב-overlay (סעיף 4).
// - **מה לוחצים** — forecast.hitsAt: כל מה שעד 24px מהאצבע; הקטן קודם (נקודה, קו, אזור). כמה — "עוד N פריטים כאן ›" עובר לבא.
// - **לחיצה ולא גרירה:** הורדה והרמה בלי תזוזה. גרירה, צביטה או גלגלת של הגרף — סוגרות. כלי ציור פעיל — אין חלונית.
//   סגירה גם ב-✕, Esc ולחיצה מחוץ לחלונית. לחיצה על פריט אחר — מחליפה (חלונית אחת).
// - **רענון:** אחרי כל ציור מחדש (forecast.onPaint) — אותו פריט במקום החדש שלו; נעלם — נסגרת.
// - **מקום:** בתוך גבולות הגרף, ולא על המקרא. בתוך `.chart-wrap` — כך גם במסך מלא ובשכבה המסובבת (המיקום מ-offsetLeft/Top —
//   פריסה, לא מסך; המגע כבר מומר לשכבה ב-rotate.js).

const MOVE = 8;        // תזוזה (px) שהופכת לחיצה לגרירה
const HOLD = 600;      // לחיצה ארוכה מזה — לא "לחיצה"
const GAP = 8;         // בין החלונית לנקודה — הזנב ממלא אותו (ריבוע של 12px מסובב ב-45°: חצי אלכסון ≈ 8)
const EDGE = 6;        // שוליים מקצות הגרף
const WIDTH = 280;     // 18.1.6 סעיף 3: עד 280px, או 85% מרוחב הגרף — הקטן

const KIND_NAME = { label: 'נקודה של השיטה', marker: 'סימון של השיטה', zone: 'אזור של השיטה', hline: 'רמה של השיטה', polyline: 'קו של השיטה' };   // בלי tip ובלי text

/** התוכן של החלונית: {title, sub: [ערכים], tag, explain, inv: {price, below, rule} | null, links: [{label, href}]} */
export function tipContent(hit, fmtTime) {
  const { it, price } = hit;
  const tip = it.tip && typeof it.tip === 'object' ? it.tip : {};
  // שורת המשנה — מחיר וזמן (לאזור — הטווח). כל ערך מבודד ב-<bdi>, כדי שהעברית סביבו לא תהפוך אותו
  let sub;
  if (typeof tip.sub === 'string' && tip.sub) sub = [tip.sub];
  else if (it.kind === 'zone') sub = [[price(Math.min(it.p1, it.p2)), price(Math.max(it.p1, it.p2))].join('–')];
  else if (it.kind === 'hline') sub = [price(it.price)];
  else sub = [hit.p != null ? price(hit.p) : '', hit.t != null ? fmtTime(hit.t) : ''].filter(Boolean);
  const iv = tip.invalidation;
  return {
    title: tip.title || it.text || KIND_NAME[it.kind] || 'פריט',
    sub,
    tag: `${hit.name} · ${it.scen ? 'תרחיש' : 'ממצא'}`,
    explain: tip.explain || null,
    inv: iv && Number.isFinite(+iv.price) ? { price: price(iv.price), below: iv.side !== 'above', rule: iv.rule || '' } : null,
    // רק קישורים בתוך האפליקציה (השרת כבר בודק — ts_overlay_check)
    links: Array.isArray(tip.links) ? tip.links.filter(l => typeof l?.href === 'string' && l.href.startsWith('#/') && l.label) : [],
  };
}

/**
 * מחבר את החלונית לגרף. box — אלמנט הגרף; wrap — `.chart-wrap` (ההורה הממוקם); legend() — המקרא (לא מכסים אותו);
 * canTip() — false כשכלי ציור פעיל או כשהתפריט 🔮 פתוח; onDetails(id) — "פירוט השיטה ›".
 */
export function mountChartTip({ h, box, wrap, chart, forecast, fmtTime, legend = () => null, canTip = () => true, onDetails = null }) {
  let tip = null;        // {el, hits, i}
  let down = null;       // {x, y, at, id, moved, ok}
  const ptrs = new Set();
  let seq = 0;
  if (!box.hasAttribute('tabindex')) box.tabIndex = -1;   // הפוקוס חוזר לגרף כשהחלונית נסגרת

  const local = e => { const r = box.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
  function close(focus = false) {
    if (!tip) return;
    const had = tip.el.contains(document.activeElement);
    tip.el.remove();
    tip = null;
    if (focus || had) box.focus({ preventScroll: true });
  }

  function render() {
    const hit = tip.hits[tip.i];
    const c = tipContent(hit, fmtTime);
    const tid = 'ctip-t' + (++seq);
    const x = h('button', { class: 'icon-btn ctip-x', type: 'button', 'aria-label': 'סגירה', on: { click: e => { e.stopPropagation(); close(true); } } }, '✕');
    const links = c.links.map(l => h('a', { href: l.href, on: { click: () => close() } }, l.label + ' ›'));
    if (onDetails) links.unshift(h('a', { href: '#', on: { click: e => { e.preventDefault(); close(); onDetails(hit.id); } } }, 'פירוט השיטה ›'));
    const more = tip.hits.length > 1 ? h('button', { class: 'ctip-more', type: 'button', on: { click: e => { e.stopPropagation(); tip.i = (tip.i + 1) % tip.hits.length; render(); } } },
      `עוד ${tip.hits.length - 1} ${tip.hits.length - 1 === 1 ? 'פריט' : 'פריטים'} כאן ›`) : null;
    tip.el.dataset.method = hit.id;
    tip.el.dataset.kind = hit.it.kind;
    tip.el.style.setProperty('--mc', `var(${hit.color})`);
    tip.el.setAttribute('aria-labelledby', tid);
    const focused = tip.el.contains(document.activeElement);   // ציור מחדש (רענון) — הפוקוס נשאר בחלונית
    if (more) links.push(more);
    // הגוף והזנב נפרדים: כשהחלונית לא נכנסת בגרף (גרף נמוך, לרוחב) — רק הגוף נגלל, והזנב נשאר על הנקודה
    tip.el.replaceChildren(h('div', { class: 'ctip-body' },
      h('div', { class: 'ctip-head' }, h('b', { class: 'ctip-title', id: tid }, c.title), x),
      c.sub.length ? h('small', { class: 'ctip-sub' }, ...c.sub.flatMap((v, i) => [i ? ' · ' : '', h('bdi', {}, v)])) : null,
      h('p', { class: 'ctip-row' }, h('span', { class: 'ctip-tag' }, c.tag)),
      c.explain ? h('p', { class: 'ctip-text' }, c.explain) : null,
      c.inv ? h('p', { class: 'ctip-inv' }, h('span', { class: 'ctip-tag danger' }, 'מתבטל אם'), ' ',
        `מחיר ${c.inv.below ? 'מתחת ל-' : 'מעל ל-'}`, h('bdi', { class: 'num' }, c.inv.price), c.inv.rule ? ` — ${c.inv.rule}` : '') : null,
      links.length ? h('p', { class: 'ctip-links' }, ...links) : null,
      h('small', { class: 'ctip-note' }, '⚖️ לפי כללי השיטה — לא ייעוץ השקעות')),
      h('i', { class: 'ctip-tail', 'aria-hidden': 'true' }));
    if (focused) x.focus({ preventScroll: true });
    place();
  }

  function open(hits) {
    if (!tip) {
      const el = h('div', { class: 'ctip', role: 'dialog' });
      wrap.append(el);
      tip = { el, hits, i: 0 };
    } else Object.assign(tip, { hits, i: 0 });   // פריט אחר — מחליפה, לא שנייה
    render();
    tip.el.querySelector('.ctip-x').focus({ preventScroll: true });
  }

  // המיקום: הזנב על הנקודה. מעל — אם יש מקום (ולא על המקרא); אחרת מתחת; אחרת איפה שיותר. בגבולות הגרף, במערכת של wrap
  function place() {
    const { el } = tip, hit = tip.hits[tip.i];
    const bx = box.offsetLeft, by = box.offsetTop, bw = box.clientWidth, bh = box.clientHeight;
    const ax = bx + hit.x, ay = by + hit.y;
    const body = el.firstChild;
    el.style.inlineSize = Math.min(WIDTH, Math.round(bw * 0.85)) + 'px';
    body.style.maxBlockSize = '';
    const lg = legend();
    const top0 = lg && !lg.hidden && lg.offsetHeight ? Math.max(by + EDGE, lg.offsetTop + lg.offsetHeight + 4) : by + EDGE;
    const roomUp = ay - GAP - top0, roomDown = by + bh - EDGE - (ay + GAP);
    let hh = el.offsetHeight;
    const above = hh <= roomUp || (hh > roomDown && roomUp > roomDown);
    // לא נכנסת בשום צד — הגוף מוגבל לצד הגדול ונגלל (גרף נמוך מאוד). כך החלונית בגבולות הגרף, והזנב על הנקודה
    if (hh > (above ? roomUp : roomDown)) { body.style.maxBlockSize = Math.max(60, above ? roomUp : roomDown) - (hh - body.offsetHeight) + 'px'; hh = el.offsetHeight; }
    const w = el.offsetWidth;
    const left = Math.max(bx + EDGE, Math.min(ax - w / 2, bx + bw - EDGE - w));
    el.dataset.side = above ? 'above' : 'below';
    el.style.left = left + 'px';
    el.style.top = (above ? ay - GAP - hh : ay + GAP) + 'px';
    el.style.setProperty('--tail-x', Math.max(14, Math.min(ax - left, w - 14)) + 'px');
  }

  // אחרי ציור מחדש (רענון, מקרא) או תזוזה של הגרף: אותו פריט במקומו החדש; נעלם, או יצא מהגרף — נסגרת
  function follow() {
    if (!tip) return;
    const W = box.clientWidth;
    const hits = tip.hits.map(x => forecast.relocate(x, W)).filter(Boolean);
    const cur = hits.find(x => x.key === tip.hits[tip.i].key);
    if (!cur || cur.x < 0 || cur.x > W || cur.y < 0 || cur.y > box.clientHeight) { close(); return; }
    tip.hits = hits;
    tip.i = hits.indexOf(cur);
    render();
  }

  // ── לחיצה מול גרירה ──
  // canTip נבדק על window, לפני כל מאזין אחר: התפריט 🔮 נסגר כבר ב-pointerdown של document — לחיצה שסגרה אותו לא פותחת חלונית
  let okAtDown = true;
  const early = e => { if (box.contains(e.target)) okAtDown = canTip(); };
  const onDown = e => {
    ptrs.add(e.pointerId);
    if (ptrs.size > 1) { down = null; close(); return; }   // צביטה
    down = { ...local(e), at: performance.now(), id: e.pointerId, moved: false, ok: okAtDown };
  };
  const onMove = e => {
    if (!down || e.pointerId !== down.id || down.moved) return;
    const p = local(e);
    if (Math.hypot(p.x - down.x, p.y - down.y) > MOVE) { down.moved = true; close(); }   // גרירה של הגרף
  };
  const onUp = e => {
    ptrs.delete(e.pointerId);
    const d = down;
    down = null;
    if (!d || e.pointerId !== d.id || d.moved || performance.now() - d.at > HOLD) return;
    if (!d.ok || !canTip()) { close(); return; }
    const hits = forecast.hitsAt(d.x, d.y, box.clientWidth);
    if (hits.length) open(hits); else close();
  };
  const onCancel = e => { ptrs.delete(e.pointerId); if (down?.id === e.pointerId) down = null; };
  const onWheel = () => close();
  window.addEventListener('pointerdown', early, true);
  box.addEventListener('pointerdown', onDown, true);
  box.addEventListener('pointermove', onMove, true);
  box.addEventListener('pointerup', onUp, true);
  box.addEventListener('pointercancel', onCancel, true);
  box.addEventListener('wheel', onWheel, { capture: true, passive: true });
  // לחיצה מחוץ לחלונית ומחוץ לגרף — סוגרת (בגרף — onUp מחליט: פריט אחר או סגירה)
  const outside = e => { if (tip && !tip.el.contains(e.target) && !box.contains(e.target)) close(); };
  const esc = e => { if (e.key === 'Escape' && tip) { e.stopImmediatePropagation(); close(true); } };
  document.addEventListener('pointerdown', outside, true);
  document.addEventListener('keydown', esc, true);
  const onRange = () => { if (tip && !down) follow(); };
  chart.timeScale().subscribeVisibleLogicalRangeChange(onRange);
  const offPaint = forecast.onPaint(follow);

  return {
    close,
    isOpen: () => !!tip,
    /** לבדיקות: מה פתוח, ואיפה הנקודה (במערכת של wrap) */
    state: () => {
      if (!tip) return null;
      const x = tip.hits[tip.i];
      return { id: x.id, kind: x.it.kind, side: tip.el.dataset.side, n: tip.hits.length, i: tip.i, x: box.offsetLeft + x.x, y: box.offsetTop + x.y };
    },
    destroy() {
      close();
      offPaint();
      window.removeEventListener('pointerdown', early, true);
      document.removeEventListener('pointerdown', outside, true);
      document.removeEventListener('keydown', esc, true);
      try { chart.timeScale().unsubscribeVisibleLogicalRangeChange(onRange); } catch { /* הגרף כבר הוסר */ }
    },
  };
}
