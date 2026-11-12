/**
 * העורך החזותי — מיקום חופשי, עוגן, קווי עזר ושכבות (פרוסה V6, 03 §4.3, §7.2, צעד 5 של התרחיש).
 *
 * "✥ מיקום חופשי": האלמנט אינו קופץ — ההיסטים מחושבים מהמקום שבו הוא נראה. נכתב (עסקה אחת, בכלל היעד של V3):
 * position:absolute + שני היסטים לוגיים באחוזים (לפי העוגן), ולרובריקת הייחוס position:relative אם אין לה. "ביחס אל" אב
 * רחוק מהאב הממוקם הקרוב — האלמנט עובר ב-HTML להיות ילד ישיר שלו, באותה עסקה. גרירה — תצוגה ב-DOM בלבד, כתיבה בשחרור,
 * והעוגן הוא הפינה / הצלע הקרובה. כל ערך מהעמוד — כטקסט; אין innerHTML.
 */
(function (g) {
  'use strict';
  const hh = (...a) => g.h(...a);
  const K = () => g.KolSrc, ST = () => g.KolStyle;
  const SIDES = ['top', 'right', 'bottom', 'left'];
  const pc = (v, of) => (of ? Math.round((v / of) * 10000) / 100 : 0) + '%';
  /** ההיסטים לעוגן: rect — מלבן האלמנט בתוך ה-padding-box של הייחוס (x, y, w, h), W/H — מידות הייחוס, m — שוליים.
   *  h: 'start' | 'center' | 'end' (בכיוון הקריאה), v: 'top' | 'center' | 'bottom'. מחזיר [[מאפיין פיזי, ערך | null]…]. */
  function insets(rect, W, H, m, anchor, rtl, unit) {
    const u = (v, of) => (unit === 'px' ? Math.round(v * 100) / 100 + 'px' : pc(v, of));
    const out = [], startSide = rtl ? 'right' : 'left', endSide = rtl ? 'left' : 'right';
    const fromLeft = rect.x, fromRight = W - rect.x - rect.w;
    if (anchor.h === 'center') { const c = rtl ? W - (rect.x + rect.w / 2) : rect.x + rect.w / 2; out.push([startSide, u(c - (rtl ? m.right : m.left), W)], [endSide, null]); }
    else { const side = anchor.h === 'start' ? startSide : endSide, dist = side === 'left' ? fromLeft - m.left : fromRight - m.right; out.push([side, u(dist, W)], [side === 'left' ? 'right' : 'left', null]); }
    if (anchor.v === 'center') out.push(['top', u(rect.y + rect.h / 2 - m.top, H)], ['bottom', null]);
    else if (anchor.v === 'top') out.push(['top', u(rect.y - m.top, H)], ['bottom', null]);
    else out.push(['bottom', u(H - rect.y - rect.h - m.bottom, H)], ['top', null]);
    const tx = anchor.h === 'center' ? (rtl ? '50%' : '-50%') : '0', ty = anchor.v === 'center' ? '-50%' : '0';
    out.push(['translate', tx === '0' && ty === '0' ? null : tx + ' ' + ty]);
    return out;
  }
  /** העוגן הקרוב: לאיזה צד / פינה האלמנט קרוב יותר (מרכז — כשמרכזו בתוך 3% ממרכז הייחוס). */
  function nearest(rect, W, H, rtl) {
    const cx = rect.x + rect.w / 2, cy = rect.y + rect.h / 2;
    const h = Math.abs(cx - W / 2) < W * 0.03 ? 'center' : (cx < W / 2) === !rtl ? 'start' : 'end';
    const v = Math.abs(cy - H / 2) < H * 0.03 ? 'center' : cy < H / 2 ? 'top' : 'bottom';
    return { h, v };
  }

  /** "מרווח שווה בין שלושה": boxes — [התחלה, סוף] של האחרים בציר אחד, size — אורך הנגרר. מחזיר מקומות התחלה אפשריים, כל
   *  אחד עם שני הקטעים השווים: אחרי B (המרווח של A–B), לפני A, ובאמצע בין A ל-B. רק זוגות סמוכים. */
  function eqSnaps(boxes, size) {
    const b = boxes.slice().sort((p, q) => p[0] - q[0]), out = [];
    for (let i = 0; i + 1 < b.length; i++) {
      const A = b[i], B = b[i + 1], gap = B[0] - A[1]; if (gap < 0) continue;
      out.push({ at: B[1] + gap, gap, segs: [[A[1], B[0]], [B[1], B[1] + gap]] });
      out.push({ at: A[0] - gap - size, gap, segs: [[A[0] - gap, A[0]], [A[1], B[0]]] });
      if (gap >= size) { const g2 = (gap - size) / 2; out.push({ at: A[1] + g2, gap: g2, segs: [[A[1], A[1] + g2], [A[1] + g2 + size, B[0]]] }); }
    }
    return out;
  }
  class Pos {
    /** o: { design (לשונית "מתקדם"), write (writeStyle), act, joinTx, note, undo, phone, layer(), X (KolStruct), setDevice } */
    constructor(o) { Object.assign(this, o); this.anchor = new WeakMap(); this.unit = 'pct'; }
    get D() { return this.design; } get c() { return this.design.canvas; } get st() { return this.design.styler; }
    win() { return this.c.frame.contentWindow; }
    cs(n) { const e = this.c.elOf(n); return e ? this.win().getComputedStyle(e) : null; }
    isFree(n) { const s = this.cs(n); return !!s && /^(absolute|fixed)$/.test(s.position); }
    ready(n) { const D = this.D; D.info = D.styler.info(n); D.list = D.styler.targets(n, D.info); D.t = D.target(n, D.list); }
    /** הרובריקה הממוקמת הקרובה (containing block), כצומת במודל. */
    cb(n) { const e = this.c.elOf(n); let p = e && e.parentElement; while (p && p.localName !== 'body' && this.win().getComputedStyle(p).position === 'static') p = p.parentElement; return p ? this.c.nodeOf(p) : null; }
    chain(n) { const out = []; for (let p = n.parent; p && p.tag !== 'html'; p = p.parent) if (this.c.elOf(p)) out.push(p); return out; }
    /** המלבן של האלמנט בתוך ה-padding-box של הייחוס, ושוליו. */
    geom(e, ref) {
      const r = e.getBoundingClientRect(), R = ref.getBoundingClientRect(), s = this.win().getComputedStyle(e);
      const x0 = R.left + ref.clientLeft, y0 = R.top + ref.clientTop;
      return { rect: { x: r.left - x0, y: r.top - y0, w: r.width, h: r.height }, W: ref.clientWidth, H: ref.clientHeight, m: { top: parseFloat(s.marginTop) || 0, right: parseFloat(s.marginRight) || 0, bottom: parseFloat(s.marginBottom) || 0, left: parseFloat(s.marginLeft) || 0 } };
    }
    rtl(n) { return this.st.rtl(n); }
    label(n) { return this.c.label(n); }
    /** "✥ מיקום חופשי" — הפעלה. ref — רובריקת הייחוס (ברירת מחדל: הממוקמת הקרובה). */
    on(n, ref) {
      const c = this.c, e = c.elOf(n); if (!e || !this.D.canWrite()) return false;
      const near = this.cb(n); ref = ref || near || n.parent;
      const rtl = this.rtl(n), path = K().pathOf(n), refPath = K().pathOf(ref), r0 = e.getBoundingClientRect(), m0 = this.geom(e, c.elOf(ref)).m;
      const anchor = { h: 'start', v: 'top' }, moveHtml = ref !== near && n.parent !== ref;
      let txs = 0, cur = n;
      if (moveHtml) {
        let start = -1;
        if (!this.act('מיקום חופשי · ' + this.label(n) + ' · מועבר אל ' + this.label(ref), m => { const r = this.X.move(m, [K().byPath(m, path)], K().byPath(m, refPath), null); start = r.start; return r; }, n)) return false;
        txs++; cur = null; K().walk(c.m.root, x => { if (!cur && x.from === start && !x.implied) cur = x; }); if (!cur) return false;
      }
      // הגיאומטריה של הייחוס — כשהאלמנט כבר מחוץ לזרימה (הרובריקה עשויה להתכווץ), והמלבן — היכן שנראה לפני
      const refNow = K().byPath(c.m, refPath) || ref, e2 = c.elOf(cur), refEl = c.elOf(refNow);
      this.D.preview([cur], [['position', 'absolute']]);
      const R = refEl.getBoundingClientRect(), w1 = e2.getBoundingClientRect().width, W = refEl.clientWidth, H = refEl.clientHeight;
      this.D.unpreview();
      const rect = { x: r0.left - (R.left + refEl.clientLeft), y: r0.top - (R.top + refEl.clientTop), w: r0.width, h: r0.height };
      const sets = [['position', 'absolute'], ...insets(rect, W, H, m0, anchor, rtl, this.unit)];
      if (Math.abs(w1 - r0.width) > 1) sets.push(['width', pc(r0.width, W)]);
      this.ready(cur);
      const per = new Map([[cur, this.setsFor(cur, sets)]]);
      const refStatic = this.win().getComputedStyle(c.elOf(refNow)).position === 'static';
      if (refStatic) per.set(refNow, [['position', 'relative', this.st.targets(refNow)[0]]]);
      const label = 'מיקום חופשי · ' + this.label(cur) + ' · ביחס אל ' + this.label(refNow);
      const r = this.write(per, label); if (!r) return false; txs++;
      if (txs > 1) this.joinTx(txs, label);
      this.anchor.set(cur, anchor);
      const fresh = K().byPath(c.m, K().pathOf(cur)); if (fresh) c.select(fresh);
      this.note((moveHtml ? 'האלמנט הועבר ב-HTML להיות ילד ישיר של ' + this.label(refNow) + '. ' : '') + (refStatic ? 'נוסף position:relative ל-' + this.label(refNow) + '. ' : '') + 'האלמנט יצא מהזרימה — אחיו נסגרים על מקומו.', false, { label: 'בטל', run: this.undo });
      this.after(fresh || cur);
      return true;
    }
    /** [מאפיין, ערך|null] ← [מאפיין, ערך|null, יעד]: איפוס (null) רק כשיש הצהרה ממש של המאפיין ביעד (לא קיצור שלם). */
    setsFor(n, sets) {
      const D = this.D, rtl = this.rtl(n), out = [];
      for (const [p, v] of sets) {
        const t = D.effTarget(n, p);
        if (v == null) { if (t.kind !== 'rule') continue; const d = ST().declFor(t.r.node, p, rtl); if (!d || ST().covered(d.prop, rtl).length > 1) continue; }
        out.push([p, v, t]);
      }
      return out;
    }
    /** "חזרה לזרימה": נמחקים position, ההיסטים וה-translate (וה-width שהעורך כתב באחוזים — אם יש); ה-relative של הייחוס נשאר. */
    off(n) {
      this.ready(n);
      const sets = [['position', null], ...SIDES.map(s => [s, null]), ['translate', null]];
      const per = new Map([[n, sets.map(([p]) => [p, null, this.D.effTarget(n, p)])]]);
      const r = this.write(per, 'חזרה לזרימה · ' + this.label(n));
      if (r) this.note('האלמנט חזר לזרימה. ה-position:relative של רובריקת הייחוס נשאר (אינו משנה דבר בלי ילדים ממוקמים).', false, { label: 'בטל', run: this.undo });
      return r;
    }
    /** כתיבת מיקום לפי מלבן ועוגן (אחרי גרירה / שינוי עוגן / חיצים). */
    place(n, rect, anchor, label) {
      const e = this.c.elOf(n), ref = e && e.offsetParent; if (!ref) return null;
      const g0 = this.geom(e, ref); if (rect) g0.rect = rect;
      this.ready(n);
      this.anchor.set(n, anchor);
      const r = this.write(new Map([[n, this.setsFor(n, insets(g0.rect, g0.W, g0.H, g0.m, anchor, this.rtl(n), this.unit))]]), label);
      this.after(n);
      return r;
    }
    /** אחרי כתיבה: מכוסה? נחתך? הרובריקה איבדה גובה? */
    after(n) {
      const c = this.c, e = c.elOf(K().byPath(c.m, K().pathOf(n)) || n); if (!e) return;
      const r = e.getBoundingClientRect(), d = c.doc, pts = [[0.3, 0.3], [0.7, 0.3], [0.5, 0.5], [0.3, 0.7], [0.7, 0.7]];
      let under = 0, by = null;
      for (const [fx, fy] of pts) { const t = d.elementFromPoint(r.left + r.width * fx, r.top + r.height * fy); if (t && t !== e && !e.contains(t) && !t.closest('[data-kol-canvas]')) { under++; by = t; } }
      this.covered = under >= 3 ? by : null;
      let clip = null; for (let p = e.parentElement; p && p.localName !== 'body'; p = p.parentElement) { const s = this.win().getComputedStyle(p); if (/hidden|clip/.test(s.overflow)) { const R = p.getBoundingClientRect(); if (r.left < R.left - 1 || r.right > R.right + 1 || r.top < R.top - 1 || r.bottom > R.bottom + 1) { clip = p; break; } } }
      this.clipped = clip;
      const par = e.parentElement; this.collapsed = par && par.localName !== 'body' && par.getBoundingClientRect().height < 2 && [...par.children].every(k => /^(absolute|fixed)$/.test(this.win().getComputedStyle(k).position)) ? par : null;
    }

    // ── גרירה על הקנבס ───────────────────────────────────
    /** ידית הזזה מעל אלמנט ממוקם: גרירה (תצוגה ב-DOM), קווי עזר והצמדה (Ctrl — בלי), מרחקים; שחרור — עסקה אחת. */
    drawHandle() {
      const c = this.c, n = c.selected, L = this.layer();
      if (!this.box) {
        this.box = hh('div', { class: 'ps-move', hidden: true, 'data-ps-move': true, title: 'גרירה מזיזה (Ctrl — בלי הצמדה) · חיצים — פיקסל, Shift — 10', 'aria-label': 'הזזת האלמנט הממוקם' });
        this.guides = hh('div', { class: 'ps-guides', 'aria-hidden': 'true' }); this.dist = hh('span', { class: 'ps-dist', hidden: true, dir: 'ltr' });
        this.box.addEventListener('pointerdown', ev => this.down(ev));
        L.append(this.guides, this.dist, this.box);
      }
      const on = n && !this.dragging && this.D.canWrite() && this.isFree(n) && !(this.busy && this.busy());
      this.box.hidden = !on; if (!on) return;
      const r = c.rectOf(n); if (!r) { this.box.hidden = true; return; }
      const big = this.phone() ? 44 : 0;
      this.box.style.cssText = 'left:' + (r.left - (big ? Math.max(0, (big - r.width) / 2) : 0)) + 'px;top:' + (r.top - (big ? Math.max(0, (big - r.height) / 2) : 0)) + 'px;width:' + Math.max(r.width, big) + 'px;height:' + Math.max(r.height, big) + 'px';
    }
    down(ev) {
      const c = this.c, n = c.selected, e = n && c.elOf(n); if (!e || ev.button > 0) return;
      ev.preventDefault(); try { this.box.setPointerCapture(ev.pointerId); } catch { /* */ }
      const ref = e.offsetParent || c.doc.body, g0 = this.geom(e, ref), x0 = ev.clientX, y0 = ev.clientY, s = c.scale, rtl = this.rtl(n);
      const R = ref.getBoundingClientRect(), ox = R.left + ref.clientLeft, oy = R.top + ref.clientTop;
      // יעדי הצמדה: קצוות ומרכז של הייחוס, קצוות ומרכזים של אחים ממוקמים / בזרימה (בתוך הייחוס)
      const xs = [0, g0.W / 2, g0.W], ys = [0, g0.H / 2, g0.H], sib = [];
      for (const k of ref.children) { if (k === e || !k.getClientRects().length || k.closest('[data-kol-canvas]')) continue; const b = k.getBoundingClientRect(); xs.push(b.left - ox, b.left - ox + b.width / 2, b.right - ox); ys.push(b.top - oy, b.top - oy + b.height / 2, b.bottom - oy); sib.push({ x: b.left - ox, y: b.top - oy, w: b.width, h: b.height }); }
      const TH = 6 / s; let rect = { ...g0.rect }, moved = false;
      this.dragging = true;
      const mv = e2 => {
        let x = g0.rect.x + (e2.clientX - x0) / s, y = g0.rect.y + (e2.clientY - y0) / s; const lines = [];
        if (!e2.ctrlKey && !e2.metaKey) {
          const snap = (v, size, list) => { let best = null; for (const t of list) for (const [k, off] of [[0, 0], [1, size / 2], [2, size]]) { const d = t - (v + off); if (Math.abs(d) < TH && (!best || Math.abs(d) < Math.abs(best.d))) best = { d, at: t, k }; } return best; };
          const bx = snap(x, rect.w, xs), by = snap(y, rect.h, ys);
          // מרווח שווה בין שלושה — מול האחים שחופפים בציר הניצב; גובר על קצה אלא אם הקצה קרוב ממנו ביותר מפיקסל
          const eq = (v, size, list) => { let best = null; for (const c2 of eqSnaps(list, size)) { const d = c2.at - v; if (Math.abs(d) < TH && (!best || Math.abs(d) < Math.abs(best.d))) best = { ...c2, d }; } return best; };
          const ex = eq(x, rect.w, sib.filter(b => b.y < y + rect.h && b.y + b.h > y).map(b => [b.x, b.x + b.w])), ey = eq(y, rect.h, sib.filter(b => b.x < x + rect.w && b.x + b.w > x).map(b => [b.y, b.y + b.h]));
          if (ex && (!bx || Math.abs(ex.d) <= Math.abs(bx.d) + 1)) { x += ex.d; lines.push(['eq', 'x', ex.segs, ex.gap]); } else if (bx) { x += bx.d; lines.push(['v', bx.at]); }
          if (ey && (!by || Math.abs(ey.d) <= Math.abs(by.d) + 1)) { y += ey.d; lines.push(['eq', 'y', ey.segs, ey.gap]); } else if (by) { y += by.d; lines.push(['h', by.at]); }
        }
        rect = { x, y, w: g0.rect.w, h: g0.rect.h }; moved = moved || Math.hypot(e2.clientX - x0, e2.clientY - y0) > 2;
        this.D.preview([n], [['left', (x - g0.m.left) + 'px'], ['top', (y - g0.m.top) + 'px'], ['right', 'auto'], ['bottom', 'auto'], ['translate', 'none']]);
        this.drawGuides(lines, ox, oy, g0, rect, rtl);
      };
      const up = e2 => {
        this.box.removeEventListener('pointermove', mv); this.box.removeEventListener('pointerup', up); this.box.removeEventListener('pointercancel', up);
        this.dragging = false; this.drawGuides([]);
        if (e2.type !== 'pointerup' || !moved) { this.D.unpreview(); this.drawHandle(); return; }
        const a = nearest(rect, g0.W, g0.H, rtl);
        this.place(n, rect, a, 'מיקום חופשי · גרירה · ' + this.label(n) + ' · ' + this.anchorName(a));
        this.drawHandle();
      };
      this.box.addEventListener('pointermove', mv); this.box.addEventListener('pointerup', up); this.box.addEventListener('pointercancel', up);
    }
    anchorName(a) { return { start: 'התחלה', center: 'מרכז', end: 'סוף' }[a.h] + ' / ' + { top: 'למעלה', center: 'אמצע', bottom: 'למטה' }[a.v]; }
    drawGuides(lines, ox, oy, g0, rect) {
      const c = this.c, s = c.scale, fr = c.frame.getBoundingClientRect(), L = this.layer().getBoundingClientRect();
      const X = v => fr.left - L.left + (ox + v) * s, Y = v => fr.top - L.top + (oy + v) * s;
      const cx = rect ? rect.x + rect.w / 2 : 0, cy = rect ? rect.y + rect.h / 2 : 0;
      const eqs = lines.filter(l => l[0] === 'eq').flatMap(([, ax, segs, gap]) => segs.map(([a, b]) => { const d = hh('div', { class: 'ps-eq', 'data-eq': ax }, '=' + Math.round(gap)); d.style.cssText = ax === 'x' ? 'left:' + X(a) + 'px;width:' + Math.max(2, (b - a) * s) + 'px;top:' + (Y(cy) - 7) + 'px;height:14px' : 'top:' + Y(a) + 'px;height:' + Math.max(2, (b - a) * s) + 'px;left:' + (X(cx) - 14) + 'px;width:28px'; return d; }));
      this.guides.replaceChildren(...eqs, ...lines.filter(l => l[0] !== 'eq').map(([k, at]) => { const d = hh('div', { class: 'ps-guide ' + k }); d.style.cssText = k === 'v' ? 'left:' + X(at) + 'px;top:' + Y(0) + 'px;height:' + g0.H * s + 'px' : 'top:' + Y(at) + 'px;left:' + X(0) + 'px;width:' + g0.W * s + 'px'; return d; }));
      if (!rect) { this.dist.hidden = true; return; }
      this.dist.hidden = false;
      this.dist.textContent = '↑' + Math.round(rect.y) + ' ↓' + Math.round(g0.H - rect.y - rect.h) + ' ←' + Math.round(rect.x) + ' →' + Math.round(g0.W - rect.x - rect.w);
      this.dist.style.cssText = 'left:' + X(rect.x) + 'px;top:' + (Y(rect.y + rect.h) + 4) + 'px';
    }
    /** חיצים בלי Alt, כשהאלמנט ממוקם: פיקסל (Shift — 10). */
    nudge(n, key, big) {
      const e = this.c.elOf(n), ref = e && e.offsetParent; if (!ref) return;
      const g0 = this.geom(e, ref), k = big ? 10 : 1, r = { ...g0.rect };
      if (key === 'ArrowLeft') r.x -= k; else if (key === 'ArrowRight') r.x += k; else if (key === 'ArrowUp') r.y -= k; else if (key === 'ArrowDown') r.y += k;
      const a = this.anchor.get(n) || this.guess(n);
      clearTimeout(this.nt); this.D.preview([n], [['left', (r.x - g0.m.left) + 'px'], ['top', (r.y - g0.m.top) + 'px'], ['right', 'auto'], ['bottom', 'auto'], ['translate', 'none']]);
      this.pending = { n, r, a }; this.nt = setTimeout(() => { const p = this.pending; this.pending = null; if (p) this.place(p.n, p.r, p.a, 'מיקום חופשי · חיצים · ' + this.label(p.n)); }, 400);
    }
    /** העוגן הנוכחי מתוך הערכים הכתובים. */
    guess(n) {
      const s = this.cs(n), rtl = this.rtl(n), info = this.D.styler.info(n), has = p => this.D.styler.cands(info, p, rtl).some(c2 => !/^auto$/i.test(String(ST().componentOf(c2.d.prop, c2.d.value, p, rtl) ?? c2.d.value).trim()));
      const startSide = rtl ? 'right' : 'left', endSide = rtl ? 'left' : 'right', tr = (s && s.translate) || 'none';
      const h = /^-?50%/.test(tr) && has(startSide) ? 'center' : has(startSide) ? 'start' : has(endSide) ? 'end' : 'start';
      const v = /\s-50%/.test(tr) ? 'center' : has('top') ? 'top' : has('bottom') ? 'bottom' : 'top';
      return { h, v };
    }

    // ── הפאנל: "מיקום" ו"שכבות" בלשונית "מתקדם" ──────────
    section(n) {
      const D = this.D, c = this.c, e = c.elOf(n); if (!e || n.tag === 'body' || n.tag === 'html') return [];
      const w = D.canWrite(), free = this.isFree(n), sec = hh('section', { class: 'sg-sec', 'data-sec': 'pos' }, hh('h3', { class: 'dt-h' }, 'מיקום'));
      const tog = hh('button', { type: 'button', class: 'btn sm' + (free ? ' on' : ''), 'aria-pressed': String(free), 'data-free': true, disabled: !w || null, onclick: () => (free ? this.off(n) : this.on(n, this.pickRef)) }, free ? '✥ מיקום חופשי — פעיל · חזרה לזרימה' : '✥ מיקום חופשי');
      sec.append(hh('div', { class: 'sg-line' }, tog));
      // "ביחס אל"
      const near = free ? c.nodeOf(e.offsetParent) : this.cb(n), list = this.chain(n);
      const sel = hh('select', { 'aria-label': 'ביחס אל — רובריקת הייחוס', 'data-free-ref': true, disabled: !w || null });
      list.forEach((p, i) => sel.append(hh('option', { value: String(i), selected: (this.pickRef ? this.pickRef === p : p === near) || null }, c.label(p) + (p === near ? ' · הממוקם הקרוב' : '') + (p === n.parent ? ' · האב' : ''))));
      sel.addEventListener('change', () => { const p = list[+sel.value]; this.pickRef = p; if (free && p !== near) { this.off(n); const m2 = K().byPath(c.m, K().pathOf(n)); if (m2) this.on(m2, p); } });
      sel.addEventListener('mouseover', () => { const p = list[+sel.value]; D.highlight?.(p ? [c.elOf(p)] : null); }); sel.addEventListener('mouseleave', () => D.highlight?.(null));
      sec.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'ביחס אל'), hh('span', { class: 'sg-ctl wide' }, sel)));
      if (!free) sec.append(hh('p', { class: 'hint' }, 'בחירת אב רחוק מהממוקם הקרוב מעבירה את האלמנט ב-HTML להיות ילד ישיר שלו — כך "בפינת התמונה" היא פינת התמונה.'));
      if (free) {
        const a = this.anchor.get(n) || this.guess(n), grid = hh('div', { class: 'sg-pos9', role: 'group', 'aria-label': 'עוגן — לאיזו פינה / צלע האלמנט צמוד', 'data-anchor': a.h + '-' + a.v });
        const hs = this.rtl(n) ? ['end', 'center', 'start'] : ['start', 'center', 'end'];   // הרשת משמאל לימין, כמו במסך
        for (const v of ['top', 'center', 'bottom']) for (const h of hs) {
          const on = a.h === h && a.v === v;
          grid.append(hh('button', { type: 'button', 'aria-pressed': String(on), 'data-anc': h + '-' + v, title: this.anchorName({ h, v }), 'aria-label': 'עוגן ' + this.anchorName({ h, v }), disabled: !w || null, onclick: () => this.place(n, null, { h, v }, 'עוגן · ' + this.label(n) + ' · ' + this.anchorName({ h, v })) }, on ? '●' : '·'));
        }
        sec.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'עוגן'), grid, hh('button', { type: 'button', class: 'btn sm', 'data-unit': this.unit, onclick: () => { this.unit = this.unit === 'px' ? 'pct' : 'px'; D.draw(); } }, this.unit === 'px' ? 'px → %' : '% → px')));
        sec.append(hh('p', { class: 'hint' }, 'שינוי עוגן אינו מזיז את האלמנט. גרירה על הקנבס — לפינה הקרובה; חיצים — פיקסל, Shift — 10.'));
        if (this.covered) sec.append(hh('p', { class: 'sg-over', 'data-covered': true }, '⚠️ האלמנט מכוסה ברובו — ', hh('button', { type: 'button', class: 'btn sm', 'data-front-fix': true, onclick: () => this.layerTo(n, 'front') }, 'הבא לחזית')));
        if (this.clipped) sec.append(hh('p', { class: 'sg-over', 'data-clipped': true }, '⚠️ נחתך ע״י overflow של ' + c.label(c.nodeOf(this.clipped))));
        if (this.collapsed) sec.append(hh('p', { class: 'sg-over', 'data-collapsed': true }, '⚠️ ' + c.label(c.nodeOf(this.collapsed)) + ' איבדה את גובהה (כל ילדיה ממוקמים) — ', hh('button', { type: 'button', class: 'btn sm', onclick: () => { const p = c.nodeOf(this.collapsed); const h0 = Math.round(this.collapsed.scrollHeight || 200); this.write(new Map([[p, [['min-height', h0 + 'px', this.st.targets(p)[0]]]]]), 'גובה מינימלי · ' + c.label(p)); } }, 'קבע גובה מינימלי')));
        sec.append(hh('p', { class: 'hint', 'data-device-note': true }, '📱 הונח במחשב — לא נבדק ברוחב אחר. ', hh('button', { type: 'button', class: 'btn sm', onclick: () => this.setDevice?.('phone') }, 'הצג ב-390px')));
        sec.append(hh('p', { class: 'hint' }, 'סדר הקריאה (קורא מסך, מנוע חיפוש) — לפי המקור, ולא השתנה.'));
      }
      sec.append(D.choice('position', 'position', ['static', 'relative', 'absolute', 'fixed', 'sticky'].map(v => [v, v])));
      for (const [p, l] of [['top', 'למעלה (top)'], ['bottom', 'למטה (bottom)'], [this.rtl(n) ? 'right' : 'left', 'התחלה (' + (this.rtl(n) ? 'right' : 'left') + ')'], [this.rtl(n) ? 'left' : 'right', 'סוף (' + (this.rtl(n) ? 'left' : 'right') + ')']]) sec.append(D.num(p, l, { group: 'היסט', units: [] }));
      // שכבות
      const ls = hh('section', { class: 'sg-sec', 'data-sec': 'layers' }, hh('h3', { class: 'dt-h' }, 'שכבות'));
      const st = this.cs(n), stat = st.position === 'static';
      if (stat) ls.append(hh('p', { class: 'hint', 'data-z-static': true }, 'z-index אינו משפיע על אלמנט בזרימה (position: static). מפעילים "מיקום חופשי", או position: relative.'));
      const lb = (k, t) => hh('button', { type: 'button', class: 'btn sm', 'data-layer-to': k, disabled: !w || stat || null, onclick: () => this.layerTo(n, k) }, t);
      ls.append(hh('div', { class: 'sg-bgacts' }, lb('up', '⬆ קדימה'), lb('down', '⬇ אחורה'), lb('front', '⏫ לחזית'), lb('back', '⏬ לרקע')));
      ls.append(D.num('z-index', 'z-index', { unitless: true, units: [] }));
      return [sec, ls];
    }
    /** קדימה / אחורה / לחזית / לרקע — z-index בערך הקטן שמשיג את הסדר מול האחים החופפים, בלי למספר אחרים. */
    layerTo(n, k) {
      const e = this.c.elOf(n), par = e && e.parentElement; if (!par) return;
      const r = e.getBoundingClientRect(), zOf = x => { const z = parseInt(this.win().getComputedStyle(x).zIndex, 10); return Number.isFinite(z) ? z : 0; };
      const sibs = [...par.children].filter(x => x !== e && x.getClientRects().length && this.win().getComputedStyle(x).position !== 'static').filter(x => { const b = x.getBoundingClientRect(); return b.left < r.right && b.right > r.left && b.top < r.bottom && b.bottom > r.top; });
      const zs = sibs.map(zOf), me = zOf(e);
      let z;
      if (!sibs.length) z = k === 'front' || k === 'up' ? Math.max(me, 0) + 1 : Math.min(me, 0) - 1;
      else if (k === 'front') z = Math.max(...zs) + 1; else if (k === 'back') z = Math.min(...zs) - 1;
      else if (k === 'up') { const above = zs.filter(x => x >= me).sort((a, b) => a - b); z = above.length ? above[0] + 1 : me + 1; }
      else { const below = zs.filter(x => x <= me).sort((a, b) => b - a); z = below.length ? below[0] - 1 : me - 1; }
      this.ready(n);
      return this.write(new Map([[n, [['z-index', String(z), this.D.effTarget(n, 'z-index')]]]]), 'שכבות · ' + this.label(n) + ' · z-index ' + z);
    }
  }
  g.KolPos = { Pos, insets, nearest, eqSnaps };
})(typeof window !== 'undefined' ? window : globalThis);
