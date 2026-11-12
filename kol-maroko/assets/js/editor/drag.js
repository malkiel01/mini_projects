/**
 * קול מרוקו — 🎨 עורך (פרוסה V4): גרירה — בקנבס וב-Navigator, מכל מקור (אלמנט, כמה אלמנטים, פריט מהספרייה).
 *
 * המקור: { nodes } או { lib: { html, label } }. היעד: { par, ref } — לפני האח ref, או בסוף par (ref = null).
 * הקו: אופקי ברובריקת-עמודה, אנכי ברובריקת-שורה (flex row), ותא מסומן ב-grid; הרובריקה שנכנסים אליה — מסגרת מלאה.
 * רמה: 8px החיצוניים של רובריקה שייכים להורה; ובמקום שבו כמה רובריקות מסתיימות יחד — תזוזה אופקית (בשורה: אנכית)
 * עולה / יורדת רמה. יעד שהדפדפן אינו מרשה (KolStruct.why) — הקו אדום, והתווית אומרת למה ומה כן.
 * הכול בשכבה שמעל הקנבס ובשורות ה-Navigator; ה-DOM של העמוד אינו משתנה. כל טקסט — textContent.
 */
(function () {
  'use strict';
  const EDGE = 8, LEVEL_PX = 24, OPEN_MS = 600, SCROLL_ZONE = 40;
  const mk = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

  class Drag {
    /** opt: { canvas, nav, K, X, onDrop(src, target), onCancel() } */
    constructor(opt) {
      Object.assign(this, opt);
      const c = this.canvas;
      this.line = mk('div', 'dg-line'); this.box = mk('div', 'dg-box'); this.tag = mk('div', 'dg-tag'); this.ghost = mk('div', 'dg-ghost');
      for (const e of [this.line, this.box, this.tag]) { e.hidden = true; c.layer.append(e); }
      this.ghost.hidden = true; document.body.append(this.ghost);
      this.active = null;
      this.onKey = e => { if (this.active && e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.cancel(); } };
      document.addEventListener('keydown', this.onKey, true);
      c.doc && c.doc.addEventListener('keydown', this.onKey, true);
    }
    /** מתחילים גרירה. x, y — במסך של המעטפת. */
    start(src, x, y) {
      if (this.active) this.cancel();
      const nodes = src.nodes ? this.X.order(src.nodes) : null;
      this.active = { src: Object.assign({}, src, { nodes }), x0: x, y0: y, lv: 0, acc: 0, key: '', last: null, hoverT: 0, hoverN: null, raf: 0 };
      this.ghost.textContent = nodes ? (nodes.length > 1 ? nodes.length + ' אלמנטים' : this.label(nodes[0])) : '＋ ' + src.lib.label;
      this.ghost.hidden = false; this.place(this.ghost, x + 14, y + 14);
      this.canvas.host.classList.add('dragging'); document.documentElement.classList.add('ed-dragging');
      this.move(x, y);
    }
    label(n) { return this.canvas.label(n); }
    place(e, x, y) { e.style.left = x + 'px'; e.style.top = y + 'px'; }
    tags() { const s = this.active.src; return s.nodes ? s.nodes.map(n => n.tag) : this.X.topTags(s.lib.html); }
    /** הסמן זז (במסך של המעטפת). */
    move(x, y) {
      const a = this.active; if (!a) return;
      a.px = x; a.py = y; this.place(this.ghost, x + 14, y + 14);
      if (a.raf) return;
      a.raf = requestAnimationFrame(() => { a.raf = 0; if (this.active === a) this.track(a.px, a.py); });
    }
    track(x, y) {
      const a = this.active, c = this.canvas, fr = c.frame.getBoundingClientRect(), navR = this.nav && this.nav.list.getBoundingClientRect();
      let t = null;
      if (navR && navR.width && x >= navR.left && x <= navR.right && y >= navR.top && y <= navR.bottom && this.nav.list.offsetParent) { t = this.navTarget(x, y, navR); this.autoscroll(this.nav.list, y, navR); }
      else if (x >= fr.left && x <= fr.right && y >= fr.top && y <= fr.bottom) { t = this.canvasTarget((x - fr.left) / c.scale, (y - fr.top) / c.scale, x, y); this.autoscrollFrame(y, fr); }
      a.last = t; this.draw(t);
    }
    // ── יעד בקנבס ───────────────────────────────────────────
    win() { return this.canvas.frame.contentWindow; }
    cs(n) { const e = this.canvas.elOf(n); try { return e ? this.win().getComputedStyle(e) : null; } catch { return null; } }
    rect(n) { const e = this.canvas.elOf(n); if (!e || !e.getClientRects().length) return null; return e.getBoundingClientRect(); }
    /** 'row' | 'col' | 'grid', והאם הסדר הנראה הפוך (RTL / ‎-reverse). */
    axis(n) {
      const s = this.cs(n); if (!s) return { axis: 'col', rev: false };
      const rtl = s.direction === 'rtl';
      if (/grid/.test(s.display)) return { axis: 'grid', rev: rtl };
      if (/flex/.test(s.display)) { const d = s.flexDirection; if (d.startsWith('row')) return { axis: 'row', rev: rtl !== d.endsWith('reverse') }; return { axis: 'col', rev: d.endsWith('reverse'), crev: true }; }
      return { axis: 'col', rev: false };
    }
    moving(n) { const s = this.active.src.nodes; if (!s) return false; for (let p = n; p; p = p.parent) if (s.includes(p)) return true; return false; }
    /** רובריקה: מכילה אלמנטים (לא void / טקסט גולמי), ואין בה טקסט ישיר — טקסט הוא מקום לפני / אחרי. */
    isBox(n) {
      if (!n || !this.X.canHold(n) || n.tag === 'html' || n.tag === 'head') return false;
      if (n.tag === 'body') return true;
      return !n.nodes.some(x => x.type === 'text' && /\S/.test(x.value));
    }
    /** הילד שלפניו נכנסים, לפי מיקום הסמן על ציר הרובריקה. */
    indexIn(par, fx, fy) {
      const ax = this.axis(par), kids = par.kids.filter(k => !this.moving(k) && this.rect(k));
      for (const k of kids) {
        const r = this.rect(k);
        if (ax.axis === 'row') { const mid = r.left + r.width / 2; if (ax.rev ? fx > mid : fx < mid) return { ref: k, ax }; }
        else if (ax.axis === 'grid') { if (fy < r.top) return { ref: k, ax }; if (fy <= r.bottom && (ax.rev ? fx > r.left + r.width / 2 : fx < r.left + r.width / 2)) return { ref: k, ax }; }
        else { const mid = r.top + r.height / 2; if (ax.rev ? fy > mid : fy < mid) return { ref: k, ax }; }
      }
      return { ref: null, ax };
    }
    canvasTarget(fx, fy) {
      const c = this.canvas; let el = null;
      try { el = c.doc.elementFromPoint(fx, fy); } catch { el = null; }
      let n = el ? c.nodeOf(el) : null;
      while (n && this.moving(n)) n = n.parent;
      if (!n || n.tag === 'html' || n.tag === 'head') { const b = c.m.root && c.m.root.kids.find(k => k.tag === 'body'); if (!b) return null; n = b; }
      this.hover(n);
      let base;
      const r = this.rect(n), pax = n.parent && n.tag !== 'body' ? this.axis(n.parent) : null;
      const near = r && pax && (pax.axis === 'row' ? Math.min(fx - r.left, r.right - fx) < EDGE : Math.min(fy - r.top, r.bottom - fy) < EDGE);
      if (this.isBox(n) && !near) { const i = this.indexIn(n, fx, fy); base = { par: n, ref: i.ref }; }
      else if (n.parent && n.tag !== 'body') {
        const ax = pax || { axis: 'col', rev: false };
        let after;
        if (!r) after = false;
        else if (ax.axis === 'row') after = ax.rev ? fx < r.left + r.width / 2 : fx > r.left + r.width / 2;
        else after = ax.rev ? fy < r.top + r.height / 2 : fy > r.top + r.height / 2;
        const sibs = n.parent.kids, i = sibs.indexOf(n);
        let ref = after ? sibs.slice(i + 1).find(k => !this.moving(k)) || null : n;
        base = { par: n.parent, ref };
      } else base = { par: n, ref: null };
      return this.level(base);
    }
    /** רמות באותו מקום: הבסיס, ולמעלה — "אחרי הרובריקה" כשהיעד בסוף שלה (או "לפניה" כשבתחילתה); למטה — לתוך האח שלפני. */
    level(base) {
      const a = this.active, chain = [base];
      for (let t = base; ;) {
        const par = t.par; if (!par.parent || par.tag === 'body') break;
        const kids = par.kids.filter(k => !this.moving(k)), atEnd = t.ref === null, atStart = t.ref && kids[0] === t.ref;
        if (!atEnd && !atStart) break;
        const gp = par.parent, sib = gp.kids, i = sib.indexOf(par);
        t = { par: gp, ref: atEnd ? sib.slice(i + 1).find(k => !this.moving(k)) || null : par }; chain.push(t);
      }
      const down = [];
      for (let t = base; down.length < 8;) {
        const kids = t.par.kids.filter(k => !this.moving(k)), prev = t.ref ? kids[kids.indexOf(t.ref) - 1] : kids[kids.length - 1];
        if (!prev || !this.isBox(prev)) break;
        t = { par: prev, ref: null }; down.push(t);
      }
      const all = down.reverse().concat(chain), at = down.length;
      const key = all.map(t => this.K.pathOf(t.par).join('.') + ':' + (t.ref ? t.par.kids.indexOf(t.ref) : 'end')).join('|');
      if (key !== a.key) { a.key = key; a.lv = 0; a.ax0 = a.px; a.ay0 = a.py; }
      const ax = this.axis(base.par), vert = ax.axis === 'row', rtl = (this.cs(base.par) || {}).direction === 'rtl';
      const d = vert ? a.py - a.ay0 : (rtl ? a.ax0 - a.px : a.px - a.ax0);         // חיובי = עמוק יותר
      const lv = Math.max(-(all.length - 1 - at), Math.min(at, Math.round(d / LEVEL_PX)));
      const t = all[at - lv] || base;
      t.levels = all.length; t.level = lv;
      return t;
    }
    /** ריחוף של 600ms מעל רובריקה סגורה (שורה מקופלת ב-Navigator, אלמנט מוסתר) — פתיחה לזמן הגרירה. */
    hover(n) {
      const a = this.active;
      if (a.hoverN === n) return;
      a.hoverN = n; clearTimeout(a.hoverT);
      a.hoverT = setTimeout(() => {
        if (this.active !== a || a.hoverN !== n) return;
        if (this.nav && this.nav.collapsed.has(n)) { this.nav.fold(n, true); }
        const v = this.canvas.visual(n);
        if (v === 'none' && !this.moving(n)) { this.canvas.show(n); a.shown = n; this.canvas.draw(); }
      }, OPEN_MS);
    }
    // ── יעד ב-Navigator ──────────────────────────────────────
    navTarget(x, y, navR) {
      const nv = this.nav, ROW = window.KolNavigator.ROW, yy = y - navR.top + nv.list.scrollTop, i = Math.floor(yy / ROW), r = nv.rows[i];
      if (!r) { const b = this.canvas.m.root && this.canvas.m.root.kids.find(k => k.tag === 'body'); return b ? this.withValid({ par: b, ref: null, nav: true }) : null; }
      let n = r.n; this.hover(n);
      const f = (yy - i * ROW) / ROW;
      if (this.moving(n)) return null;
      let t;
      if (n.tag === 'html' || n.tag === 'head' || n.tag === 'body' || (f > 0.3 && f < 0.7 && this.isBox(n))) {
        if (n.tag === 'html' || n.tag === 'head') n = this.canvas.m.root.kids.find(k => k.tag === 'body') || n;
        t = { par: n, ref: null };
      } else if (f <= 0.5) t = { par: n.parent, ref: n };
      else {
        const open = n.kids.length && !nv.collapsed.has(n) && !nv.query && this.isBox(n);
        if (open) t = { par: n, ref: n.kids.find(k => !this.moving(k)) || null };
        else {
          // אחרי n — ובשמאל השורה (הזחה קטנה יותר) עולים רמה, כל עוד n האחרון ברובריקה שלו
          const want = Math.max(0, Math.floor((x - navR.left - 8) / 12));
          let cur = n, depth = r.depth;
          while (depth > want && cur.parent && cur.parent.tag !== 'body' && cur.parent.parent && cur.parent.kids.filter(k => !this.moving(k)).slice(-1)[0] === cur) { cur = cur.parent; depth--; }
          const sibs = cur.parent.kids, j = sibs.indexOf(cur);
          t = { par: cur.parent, ref: sibs.slice(j + 1).find(k => !this.moving(k)) || null };
        }
      }
      t.nav = true;
      this.autoscroll(nv.list, y, navR);
      return t;
    }
    autoscroll(el, y, r) { if (y < r.top + SCROLL_ZONE) el.scrollTop -= 12; else if (y > r.bottom - SCROLL_ZONE) el.scrollTop += 12; }
    autoscrollFrame(y, r) { const w = this.win(); if (y < r.top + SCROLL_ZONE) w.scrollBy(0, -14); else if (y > r.bottom - SCROLL_ZONE) w.scrollBy(0, 14); }
    // ── תקינות ותווית ──────────────────────────────────────
    withValid(t) {
      if (!t) return null;
      const a = this.active, s = a.src, w = this.X.why(t.par, this.tags(), s.nodes);
      t.bad = w; t.noop = false;
      if (!w && s.nodes && s.nodes.length === 1 && s.nodes[0].parent === t.par) {
        const n = s.nodes[0], sibs = t.par.kids.filter(k => k !== n);
        t.noop = (t.ref ? sibs.indexOf(t.ref) : sibs.length) === t.par.kids.indexOf(n);
      }
      const L = n => this.label(n);
      t.text = w ? w.msg : t.noop ? 'האלמנט כבר שם' : (t.ref ? 'לפני ' + L(t.ref) + ' · ' : t.par.kids.some(k => !this.moving(k)) ? 'בסוף · ' : 'ראשון · ') + 'בתוך ' + L(t.par) + (t.levels > 1 ? ' · ↔ רמה' : '');
      return t;
    }
    draw(t) {
      t = this.withValid(t); if (this.active) this.active.last = t;
      const c = this.canvas, s = c.scale, ox = c.ox;
      this.line.hidden = this.box.hidden = this.tag.hidden = true;
      if (this.nav) for (const e of this.nav.list.querySelectorAll('.dg-nav')) e.classList.remove('dg-nav', 'dg-before', 'dg-after', 'dg-in', 'dg-bad');
      if (!t) return;
      const rr = r => ({ left: ox + r.left * s, top: r.top * s, width: r.width * s, height: r.height * s });
      const pr = this.rect(t.par);
      if (pr) { const b = rr(pr); this.box.hidden = false; this.box.className = 'dg-box' + (t.bad ? ' bad' : ''); this.box.style.cssText = 'left:' + b.left + 'px;top:' + b.top + 'px;width:' + b.width + 'px;height:' + b.height + 'px'; }
      const ax = this.axis(t.par), kids = t.par.kids.filter(k => !this.moving(k) && this.rect(k));
      let ln = null;
      const refR = t.ref && this.rect(t.ref), last = !t.ref && kids.length ? this.rect(kids[kids.length - 1]) : null;
      if (ax.axis === 'grid' && refR) { ln = { cell: rr(refR) }; }
      else if (ax.axis === 'row' && (refR || last)) {
        const r = rr(refR || last), startRight = ax.rev, x = refR ? (startRight ? r.left + r.width : r.left) : (startRight ? r.left : r.left + r.width);
        ln = { left: x - 2, top: r.top, width: 4, height: Math.max(r.height, 16) };
      } else if (refR || last) {
        const r = rr(refR || last), yy = refR ? (ax.rev ? r.top + r.height : r.top) : (ax.rev ? r.top : r.top + r.height);
        ln = { left: r.left, top: yy - 2, width: Math.max(r.width, 16), height: 4 };
      } else if (pr) { const b = rr(pr); ln = { left: b.left + 4, top: b.top + 4, width: Math.max(b.width - 8, 16), height: 4 }; }
      if (ln) {
        this.line.hidden = false; this.line.className = 'dg-line' + (t.bad ? ' bad' : '') + (ln.cell ? ' cell' : ax.axis === 'row' ? ' v' : ' h');
        const g = ln.cell || ln; this.line.style.cssText = 'left:' + g.left + 'px;top:' + g.top + 'px;width:' + g.width + 'px;height:' + g.height + 'px';
      }
      this.tag.hidden = false; this.tag.className = 'dg-tag' + (t.bad ? ' bad' : t.noop ? ' noop' : '');
      this.tag.textContent = t.text;
      const at = ln ? (ln.cell || ln) : pr ? rr(pr) : { left: 8, top: 8 };
      this.tag.style.cssText = 'left:' + Math.max(0, at.left) + 'px;top:' + Math.max(0, at.top + (at.height || 0) + 6) + 'px';
      if (this.nav) {
        const row = n => { const i = this.nav.index.get(n); return i == null ? null : this.nav.space.querySelector('[data-i="' + i + '"]'); };
        const ref = t.ref && row(t.ref), par = row(t.par), bad = t.bad ? ' dg-bad' : '';
        if (ref) ref.classList.add('dg-nav', 'dg-before'); else { const lk = t.par.kids.filter(k => !this.moving(k)).slice(-1)[0], e = lk && row(lk); if (e) e.classList.add('dg-nav', 'dg-after'); }
        if (par) par.classList.add('dg-nav', 'dg-in');
        if (bad) for (const e of this.nav.list.querySelectorAll('.dg-nav')) e.classList.add('dg-bad');
      }
    }
    /** שחרור: commit=false (Esc, מחוץ ליעד) — דבר אינו נכתב. */
    end(commit = true) {
      const a = this.active; if (!a) return;
      cancelAnimationFrame(a.raf); clearTimeout(a.hoverT);
      let t = null;
      if (commit && a.px != null) { this.track(a.px, a.py); t = a.last; }
      this.active = null; this.hide();
      if (a.shown) { this.canvas.unshow(); this.canvas.show(this.canvas.selected); }
      if (!commit || !t || t.noop) { if (this.onCancel) this.onCancel(t); return; }
      this.onDrop(a.src, t);
    }
    cancel() { this.end(false); }
    hide() {
      this.line.hidden = this.box.hidden = this.tag.hidden = this.ghost.hidden = true;
      this.canvas.host.classList.remove('dragging'); document.documentElement.classList.remove('ed-dragging');
      if (this.nav) for (const e of this.nav.list.querySelectorAll('.dg-nav')) e.classList.remove('dg-nav', 'dg-before', 'dg-after', 'dg-in', 'dg-bad');
    }
  }
  window.KolDrag = { Drag, EDGE, LEVEL_PX };
})();
