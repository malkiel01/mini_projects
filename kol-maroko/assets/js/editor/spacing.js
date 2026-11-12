/**
 * העורך החזותי — ידיות מרווח על הקנבס (פרוסה V3, 03 §7.1).
 *
 * לאלמנט הנבחר: הריפוד (מבפנים) והשוליים (מבחוץ) כאזורים צבועים עם המספר; גרירת שפת אזור משנה את המרווח, והקנבס
 * מתעדכן בזמן הגרירה (ה-DOM של הקנבס בלבד); בשחרור — עסקה אחת. Shift — צעדים של 8; Alt — שני הצדדים הנגדיים יחד.
 * בין האלמנט לאח הבא — ידית אחת: גרירה עד אפס ומתחת לאפס. gap של הורה flex / grid ← ה-gap ("משנה את הרווח בין כל N
 * הילדים", עם "רק כאן"); אחרת ← margin-block-start של השני, ושוליים שמתמזגים — מי תורם כמה, ושניהם מתאפסים.
 * הגרירה משנה את הכלל שממנו המרווח מגיע (גם !important של הכלי הישן — נשמר); כלל שמשפיע על כמה אלמנטים ← "רק כאן".
 * בטלפון: ידיות של 44px, והקשה על ידית פותחת סרגל עם − / + ושדה מספר לאותו מרווח.
 */
(function () {
  'use strict';
  const SIDES = ['top', 'right', 'bottom', 'left'];
  const OPP = { top: 'bottom', bottom: 'top', left: 'right', right: 'left' };
  const px = v => parseFloat(v) || 0;
  const r1 = x => Math.round(x * 10) / 10;
  /** איחוד שוליים שמתמזגים (CSS 2 §8.3.1): החיובי הגדול ועוד השלילי הקטן. */
  /** ערך לכתיבה: אפס נכתב 0, השאר בפיקסלים. */
  const P = v => (Math.abs(v) < 0.05 ? '0' : r1(v) + 'px');
  const collapse = (a, b) => Math.max(0, a, b) + Math.min(0, a, b);

  class Spacing {
    /** opt: { styler, design, write, preview, unpreview, canWrite(), note, undo, phone(), on(), sheetHost() } */
    constructor(canvas, opt) {
      Object.assign(this, opt); this.c = canvas; this.side = 'bottom';
      this.root = document.createElement('div'); this.root.className = 'sp'; canvas.layer.append(this.root);
      this.areas = {}; this.handles = {};
      for (const k of ['margin', 'padding']) for (const s of SIDES) {
        const a = h('div', { class: 'sp-area sp-' + k, 'data-area': k + '-' + s }, h('span', { class: 'sp-v' }));
        const hd = h('button', { type: 'button', class: 'sp-h sp-h-' + k, 'data-sp': k + '-' + s, 'aria-label': (k === 'margin' ? 'שוליים' : 'ריפוד') + ' — ' + { top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' }[s] + ' (גרירה)' });
        hd.addEventListener('pointerdown', e => this.dragSide(e, k + '-' + s));
        this.root.append(a, hd); this.areas[k + '-' + s] = a; this.handles[k + '-' + s] = hd;
      }
      this.gapH = h('button', { type: 'button', class: 'sp-gap', 'data-sp-gap': true, 'aria-label': 'הרווח בין האלמנט לאח שלו (גרירה)' }, h('span', { class: 'sp-gv' }));
      this.gapH.addEventListener('pointerdown', e => this.dragGap(e));
      this.fsH = h('button', { type: 'button', class: 'sp-fs', 'data-sp-fs': true, 'aria-label': 'גודל הטקסט — גרירה למטה מגדילה', title: 'גודל הטקסט · גרירה למטה מגדילה · Shift — צעדים של 4' }, 'A');
      this.fsH.addEventListener('pointerdown', e => this.dragFont(e));
      this.num = h('span', { class: 'sp-num', hidden: true });
      this.hi = []; this.root.append(this.gapH, this.fsH, this.num);
    }
    win() { return this.c.frame.contentWindow; }
    cs(n) { const e = this.c.elOf(n); return e ? this.win().getComputedStyle(e) : null; }
    /** מלבן במסגרת (פיקסלים של העמוד) ← מיקום בשכבה. */
    L(x, y, w, hgt) { const s = this.c.scale; return 'left:' + (this.c.ox + x * s) + 'px;top:' + (y * s) + 'px;width:' + Math.max(0, w * s) + 'px;height:' + Math.max(0, hgt * s) + 'px'; }
    draw() {
      const n = this.c.selected, on = this.on() && n && this.c.elOf(n) && this.c.visual(n) !== 'nonvisual' && !this.drag;
      this.root.classList.toggle('phone', !!this.phone());
      if (this.drag) return;
      this.root.hidden = !on && !this.hi.length;
      if (!on) { this.gapH.hidden = true; this.fsH.hidden = true; for (const k in this.areas) { this.areas[k].hidden = true; this.handles[k].hidden = true; } this.drawHi(); return; }
      const e = this.c.elOf(n), cs = this.cs(n), r = e.getBoundingClientRect(), adv = this.on() === 'adv' || this.on() === true;
      const b = { top: px(cs.borderTopWidth), right: px(cs.borderRightWidth), bottom: px(cs.borderBottomWidth), left: px(cs.borderLeftWidth) };
      for (const k of ['margin', 'padding']) for (const s of SIDES) {
        const v = px(cs[k + s[0].toUpperCase() + s.slice(1)]), a = this.areas[k + '-' + s], hd = this.handles[k + '-' + s], av = Math.abs(v);
        let x, y, w, hh, hx, hy;
        if (k === 'margin') {
          if (s === 'top') { x = r.left; w = r.width; y = v >= 0 ? r.top - v : r.top; hh = av; hx = r.left + r.width / 2; hy = r.top - v; }
          if (s === 'bottom') { x = r.left; w = r.width; y = v >= 0 ? r.bottom : r.bottom + v; hh = av; hx = r.left + r.width / 2; hy = r.bottom + v; }
          if (s === 'left') { y = r.top; hh = r.height; x = v >= 0 ? r.left - v : r.left; w = av; hx = r.left - v; hy = r.top + r.height / 2; }
          if (s === 'right') { y = r.top; hh = r.height; x = v >= 0 ? r.right : r.right + v; w = av; hx = r.right + v; hy = r.top + r.height / 2; }
        } else {
          const L = r.left + b.left, R = r.right - b.right, T = r.top + b.top, B = r.bottom - b.bottom;
          if (s === 'top') { x = L; w = R - L; y = T; hh = v; hx = L + (R - L) / 2; hy = T + v; }
          if (s === 'bottom') { x = L; w = R - L; y = B - v; hh = v; hx = L + (R - L) / 2; hy = B - v; }
          if (s === 'left') { y = T; hh = B - T; x = L; w = v; hx = L + v; hy = T + (B - T) / 2; }
          if (s === 'right') { y = T; hh = B - T; x = R - v; w = v; hx = R - v; hy = T + (B - T) / 2; }
        }
        a.hidden = !adv; hd.hidden = !adv || !this.canWrite();
        a.style.cssText = this.L(x, y, w, hh); a.classList.toggle('neg', v < 0); a.classList.toggle('hint', this.hinted === k + '-' + s);
        a.firstChild.textContent = av || this.hinted === k + '-' + s ? r1(v) + 'px' : '';
        const s2 = this.c.scale, big = this.phone() ? 44 : 0, vert = s === 'top' || s === 'bottom';
        const hw = big || (vert ? Math.min(36, Math.max(10, w * s2 * 0.4)) : 6), hh2 = big || (vert ? 6 : Math.min(36, Math.max(10, hh * s2 * 0.4)));
        hd.style.cssText = 'left:' + (this.c.ox + hx * s2 - hw / 2) + 'px;top:' + (hy * s2 - hh2 / 2) + 'px;width:' + hw + 'px;height:' + hh2 + 'px';
        hd.title = (k === 'margin' ? 'שוליים ' : 'ריפוד ') + r1(v) + 'px · גרירה משנה · Shift — צעדים של 8 · Alt — גם הצד הנגדי' + (this.phone() ? ' · הקשה — − / +' : '');
      }
      // בין שני אחים
      const g = adv ? this.gapOf(n) : null;
      this.gapH.hidden = !g || !this.canWrite();
      if (g) {
        const s2 = this.c.scale, big = this.phone() ? 44 : 0;
        if (g.axis === 'y') { const w = big || 44, hh = big || Math.max(8, Math.abs(g.d) * s2); this.gapH.style.cssText = 'left:' + (this.c.ox + g.mid * s2 - w / 2) + 'px;top:' + ((g.a + g.d / 2) * s2 - hh / 2) + 'px;width:' + w + 'px;height:' + hh + 'px'; }
        else { const hh = big || 44, w = big || Math.max(8, Math.abs(g.d) * s2); this.gapH.style.cssText = 'left:' + (this.c.ox + (g.a + g.d / 2) * s2 - w / 2) + 'px;top:' + (g.mid * s2 - hh / 2) + 'px;width:' + w + 'px;height:' + hh + 'px'; }
        this.gapH.firstChild.textContent = r1(g.d) + 'px';
        this.gapH.title = this.gapText(g);
        this.gapH.classList.toggle('neg', g.d < 0);
      }
      // גודל הטקסט: ידית בפינת אלמנט טקסט
      const text = this.on() === 'design' && /\S/.test(e.textContent || '') && this.canWrite();
      this.fsH.hidden = !text;
      if (text) { const s2 = this.c.scale, z = this.phone() ? 44 : 18; this.fsH.style.cssText = 'left:' + (this.c.ox + r.left * s2 - z / 2) + 'px;top:' + (r.bottom * s2 - z / 2) + 'px;width:' + z + 'px;height:' + z + 'px'; this.fsH.title = 'גודל הטקסט ' + cs.fontSize + ' · גרירה למטה מגדילה · Shift — צעדים של 4'; }
      this.drawHi();
    }
    /** סימון האלמנטים שהיעד משפיע עליהם (ריחוף על הבורר "משנה את"). */
    highlight(els) { this.hiEls = els || null; this.drawHi(); this.root.hidden = !this.hiEls && this.root.hidden; }
    drawHi() {
      for (const b of this.hi) b.remove(); this.hi = [];
      if (!this.hiEls) return;
      this.root.hidden = false;
      for (const e of this.hiEls.slice(0, 200)) { const r = e.getBoundingClientRect(); if (!r.width && !r.height) continue; const b = h('div', { class: 'sp-hi', 'data-sp-hi': true }); b.style.cssText = this.L(r.left, r.top, r.width, r.height); this.root.append(b); this.hi.push(b); }
    }
    hint(phys) { this.hinted = phys; if (phys && /^(margin|padding)-/.test(phys)) this.side = phys.split('-')[1]; this.draw(); }

    // ── הרווח בין שני אחים ────────────────────────────────────
    /** האלמנט הבא (או הקודם) שמוצג, והרווח ביניהם: { A, B, axis, d, a (תחילת הרווח), mid, mode, mA, mB, extra, between[] }. */
    gapOf(n) {
      const c = this.c, par = n.parent; if (!par || !c.elOf(n)) return null;
      const shown = k => { const e = c.elOf(k); if (!e) return false; const r = e.getBoundingClientRect(); return (r.width > 0 || r.height > 0) && this.win().getComputedStyle(e).position !== 'absolute' && this.win().getComputedStyle(e).position !== 'fixed'; };
      const kids = par.kids.filter(k => !k.implied || c.elOf(k)), i = kids.indexOf(n);
      let A = n, B = null, between = [];
      for (let j = i + 1; j < kids.length; j++) { if (shown(kids[j])) { B = kids[j]; break; } between.push(kids[j]); }
      if (!B) { between = []; for (let j = i - 1; j >= 0; j--) { if (shown(kids[j])) { A = kids[j]; B = n; break; } between.unshift(kids[j]); } }
      if (!B) return null;
      const pcs = this.cs(par) || {}, flex = /flex|grid/.test(pcs.display || ''), rtl = (pcs.direction || 'rtl') === 'rtl';
      const ra = c.elOf(A).getBoundingClientRect(), rb = c.elOf(B).getBoundingClientRect(), csA = this.cs(A), csB = this.cs(B);
      const row = flex ? (/grid/.test(pcs.display) ? Math.abs(ra.top - rb.top) < 2 && Math.abs(ra.left - rb.left) > 2 : !/column/.test(pcs.flexDirection)) : (Math.abs(ra.top - rb.top) < 2 && /inline/.test(csB.display));
      const g = { A, B, par, between, rtl, flex };
      if (!row) {
        g.axis = 'y'; g.a = ra.bottom; g.d = rb.top - ra.bottom; g.mid = (Math.max(ra.left, rb.left) + Math.min(ra.right, rb.right)) / 2 || (ra.left + ra.width / 2);
        g.endA = 'margin-bottom'; g.startB = 'margin-top'; g.mA = px(csA.marginBottom); g.mB = px(csB.marginTop); g.gapProp = 'row-gap';
      } else {
        g.axis = 'x';
        const first = rtl ? (ra.left > rb.left ? [ra, rb] : [rb, ra]) : (ra.left < rb.left ? [ra, rb] : [rb, ra]);
        g.a = rtl ? first[1].right : first[0].right; g.d = rtl ? first[0].left - first[1].right : first[1].left - first[0].right;
        g.mid = (Math.max(ra.top, rb.top) + Math.min(ra.bottom, rb.bottom)) / 2;
        g.endA = rtl ? 'margin-left' : 'margin-right'; g.startB = rtl ? 'margin-right' : 'margin-left';
        g.mA = px(csA[rtl ? 'marginLeft' : 'marginRight']); g.mB = px(csB[rtl ? 'marginRight' : 'marginLeft']); g.gapProp = 'column-gap';
      }
      g.gap = flex ? px(pcs[g.axis === 'y' ? 'rowGap' : 'columnGap']) : 0;
      g.collapse = !flex && g.axis === 'y' && !between.length && !/inline|table-cell/.test(csA.display) && !/inline/.test(csB.display) && csA.float === 'none' && csB.float === 'none';
      const expect = g.flex ? g.gap + g.mA + g.mB : g.collapse ? collapse(g.mA, g.mB) : g.mA + g.mB;
      g.extra = r1(g.d - expect);
      g.mode = flex && (g.gap > 0 || this.declaresGap(par)) ? 'gap' : 'margin';
      g.count = par.kids.filter(shown).length;
      return g;
    }
    declaresGap(par) { const info = this.styler.info(par); return this.styler.cands(info, 'row-gap', true).length + this.styler.cands(info, 'column-gap', true).length > 0; }
    gapText(g) {
      const L = n => this.c.label(n);
      if (g.mode === 'gap') return 'רווח ' + r1(g.d) + 'px · gap של ההורה (' + r1(g.gap) + 'px) — משנה את הרווח בין כל ' + g.count + ' הילדים' + (g.mA || g.mB ? ' · ועוד שוליים ' + r1(g.mA) + ' + ' + r1(g.mB) : '');
      let t = 'רווח ' + r1(g.d) + 'px בין ' + L(g.A) + ' ל-' + L(g.B) + ' · שוליים: ' + L(g.A) + ' ' + r1(g.mA) + ' · ' + L(g.B) + ' ' + r1(g.mB) + (g.collapse && g.mA && g.mB ? ' (מתמזגים — נראה ' + r1(collapse(g.mA, g.mB)) + ')' : '');
      if (Math.abs(g.extra) >= 1) t += ' · עוד ' + r1(g.extra) + 'px שאינם שוליים — ראו "מה יוצר את הרווח"';
      return t;
    }
    /** "מה יוצר את הרווח" — לרווח שאינו שוליים, ריפוד או gap: אומרים מה, ומובילים אליו. */
    whyNode(n) {
      const g = n && this.c.elOf(n) ? this.gapOf(n) : null, e = n && this.c.elOf(n);
      if (!e) return null;
      const items = [], cs = this.cs(n);
      if (g) {
        items.push(h('li', { 'data-why': 'gap' }, this.gapText(g)));
        for (const k of g.between) {
          const d = this.c.elOf(k), r = d ? d.getBoundingClientRect() : null;
          if (k.tag === 'br') items.push(h('li', { 'data-why': 'br' }, 'שורה ריקה (<br>) בין האלמנטים יוצרת רווח. ', this.goBtn(k)));
          else if (r && r.height > 0.5) items.push(h('li', { 'data-why': 'empty' }, 'אלמנט ' + this.c.label(k) + ' בין השניים תופס ' + r1(r.height) + 'px. ', this.goBtn(k)));
        }
      }
      const fs = px(cs.fontSize), lh = cs.lineHeight === 'normal' ? fs * 1.2 : px(cs.lineHeight);
      if (/\S/.test(e.textContent || '') && lh > fs * 1.35) items.push(h('li', { 'data-why': 'line-height' }, 'חלק מהרווח שנראה מעל ומתחת לטקסט הוא גובה השורה (line-height: ' + cs.lineHeight + ') — בתוך הקופסה. משנים ב"🎨 עיצוב" ← גובה שורה.'));
      const hs = this.styler.source(n, 'height');
      if (hs.winner && !/auto|%/.test(hs.declared || '') && e.scrollHeight + 2 < e.clientHeight) items.push(h('li', { 'data-why': 'height' }, 'גובה קבוע (height: ' + hs.declared + ') — הקופסה גבוהה מהתוכן שלה. גודל — V6; בינתיים: ', h('button', { type: 'button', class: 'btn sm', onclick: () => window.Project?.openAt(hs.winner.r.path, hs.winner.d.line || 1) }, '</> פתח בקוד')));
      if (!items.length) return null;
      return h('section', { class: 'sg-sec', 'data-sec': 'why' }, h('h3', { class: 'dt-h' }, 'מה יוצר את הרווח'), h('ul', { class: 'sg-why' }, items));
    }
    goBtn(k) { return h('button', { type: 'button', class: 'btn sm', onclick: () => { this.c.select(k); this.c.reveal(k); } }, 'בחר אותו'); }

    // ── לאן נכתב ──────────────────────────────────────────────
    /** היעד של מרווח שנגרר: הכלל שממנו המרווח מגיע (כולל !important); בלי — יעד הפאנל; כלל שנדרס — הכלל הדורס. */
    targetFor(n, phys) {
      const St = this.styler, info = St.info(n), list = St.targets(n, info), src = St.source(n, phys, info);
      const of = r => (r.inline ? list.find(x => x.kind === 'inline') : list.find(x => x.kind === 'rule' && x.r === r));
      if (src.winner && !src.winner.r.external) { const t = of(src.winner.r); if (t) return t; }
      const t = this.design.target(n, list), vis = St.visible(n, t, phys, info);
      if (!vis.ok) { const o = of(vis.by.r); if (o) return o; }
      return t;
    }
    /** כותב, ואם הכלל משפיע על יותר מאלמנט אחד — "רק כאן" (מבטל וכותב ב-style של האלמנט). */
    put(list, label) {
      const per = new Map(), many = [];
      for (const [n, phys, v] of list) { const t = this.targetFor(n, phys); if (!per.has(n)) per.set(n, []); per.get(n).push([phys, v, t]); if (t.kind === 'rule' && t.count > 1) many.push(t); }
      const done = this.write(per, label);
      if (done && done.length && many.length) {
        const t = many[0];
        this.note('שונה ב-' + t.sel + ' (' + t.path + ') — משפיע על ' + t.count + ' אלמנטים', false, { label: 'רק כאן', run: () => {
          this.undo();
          const per2 = new Map(); for (const [n, phys, v] of list) { const nn = window.KolSrc.byPath(this.c.m, window.KolSrc.pathOf(n)) || n; if (!per2.has(nn)) per2.set(nn, []); per2.get(nn).push([phys, v, { kind: 'inline', sel: 'style=""', path: '' }]); }
          this.write(per2, label + ' · רק כאן');
        } });
      }
      return done;
    }
    showNum(text, ev) { this.num.hidden = false; this.num.textContent = text; const f = this.c.layer.getBoundingClientRect(); this.num.style.cssText = 'left:' + (ev.clientX - f.left + 14) + 'px;top:' + (ev.clientY - f.top + 14) + 'px'; }
    /** גרירה משותפת: fn(delta בפיקסלי עמוד, אירוע) מחזירה את הטקסט שליד הסמן; done(moved) בשחרור. */
    track(e, el, fn, done) {
      if (!this.canWrite()) return;
      e.preventDefault(); e.stopPropagation(); el.setPointerCapture(e.pointerId);
      const x0 = e.clientX, y0 = e.clientY; let moved = false; this.drag = true;
      const move = ev => { const dx = (ev.clientX - x0) / this.c.scale, dy = (ev.clientY - y0) / this.c.scale; if (!moved && Math.hypot(dx, dy) < 2) return; moved = true; this.showNum(fn(dx, dy, ev, ev.clientX - x0, ev.clientY - y0), ev); };
      const up = ev => { el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', up); this.num.hidden = true; this.drag = false; done(moved && ev.type === 'pointerup'); this.draw(); };
      el.addEventListener('pointermove', move); el.addEventListener('pointerup', up); el.addEventListener('pointercancel', up);
    }
    stepped(v, ev, k) { return ev.shiftKey ? Math.round(v / k) * k : Math.round(v); }
    dragSide(e, phys) {
      const n = this.c.selected; if (!n) return;
      const [kind, s] = phys.split('-'), cs = this.cs(n), v0 = px(cs[kind + s[0].toUpperCase() + s.slice(1)]);
      // כיוון: שוליים — גרירה החוצה מגדילה; ריפוד — גרירה פנימה מגדילה
      const sign = { top: -1, bottom: 1, left: -1, right: 1 }[s] * (kind === 'margin' ? 1 : -1), vert = s === 'top' || s === 'bottom';
      let v = v0, both = false; this.side = s;
      this.track(e, this.handles[phys], (dx, dy, ev) => {
        v = this.stepped(v0 + sign * (vert ? dy : dx), ev, 8); if (kind === 'padding' && v < 0) v = 0; both = ev.altKey;
        this.preview([n], [[phys, v + 'px']].concat(both ? [[kind + '-' + OPP[s], v + 'px']] : []));
        return (kind === 'margin' ? 'שוליים ' : 'ריפוד ') + v + 'px' + (both ? ' · שני הצדדים' : '');
      }, moved => {
        this.unpreview();
        if (!moved) return this.openBar(n, phys);
        if (v === v0 && !both) return;
        const list = [[n, phys, P(v)]]; if (both) list.push([n, kind + '-' + OPP[s], P(v)]);
        this.put(list, 'מרווח · ' + (kind === 'margin' ? 'שוליים ' : 'ריפוד ') + { top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' }[s] + (both ? ' + נגדי' : '') + ' · ' + this.c.label(n) + ' · ' + r1(v0) + ' ← ' + v);
      });
    }
    /** הרווח בין שני אחים ← D: מה נכתב, לפי מקור הרווח. */
    gapSets(g, D) {
      const out = [];
      if (g.mode === 'gap') {
        const rest = D - g.mA - g.mB;
        out.push([g.par, g.gapProp, P(Math.max(0, rest))]);
        if (rest < 0) out.push([g.B, g.startB, P(D - g.mA)]);
        return out;
      }
      const ex = Math.abs(g.extra) >= 1 ? g.extra : 0, want = D - ex;
      if (g.collapse) {
        if (want >= g.mA && g.mA >= 0 && want >= 0) out.push([g.B, g.startB, P(want)]);
        else { if (g.mA) out.push([g.A, g.endA, '0']); out.push([g.B, g.startB, P(want)]); }
      } else {
        if (want >= g.mA) out.push([g.B, g.startB, P(want - g.mA)]);
        else { if (g.mA) out.push([g.A, g.endA, '0']); out.push([g.B, g.startB, P(want)]); }
      }
      return out;
    }
    dragGap(e) {
      const n = this.c.selected, g = n && this.gapOf(n); if (!g) return;
      const sign = g.axis === 'y' ? 1 : g.rtl ? -1 : 1; let D = g.d;
      this.track(e, this.gapH, (dx, dy, ev) => {
        D = this.stepped(g.d + (g.axis === 'y' ? dy : sign * dx), ev, 8);
        const sets = this.gapSets(g, D), byNode = new Map();
        for (const [x, p, v] of sets) { if (!byNode.has(x)) byNode.set(x, []); byNode.get(x).push([p, v]); }
        this.unpreview(); for (const [x, ps] of byNode) this.preview([x], ps);
        return 'רווח ' + D + 'px' + (g.mode === 'gap' ? ' · gap בין ' + g.count + ' הילדים' : g.collapse && g.mA && D < g.mA ? ' · שני השוליים' : '');
      }, moved => {
        this.unpreview();
        if (!moved) return this.openBar(n, 'gap');
        this.setGap(g, D);
      });
    }
    setGap(g, D) {
      if (D === r1(g.d)) return;
      const sets = this.gapSets(g, D), L = 'מרווח · בין ' + this.c.label(g.A) + ' ל-' + this.c.label(g.B) + ' · ' + r1(g.d) + ' ← ' + D;
      const done = this.put(sets, L);
      if (done && g.mode === 'gap') this.note('משנה את הרווח בין כל ' + g.count + ' הילדים (gap של ' + this.c.label(g.par) + ')', false, { label: 'רק כאן', run: () => {
        this.undo();
        const B = window.KolSrc.byPath(this.c.m, window.KolSrc.pathOf(g.B)); if (!B) return;
        this.write(new Map([[B, [[g.startB, P(D - g.gap - g.mA), { kind: 'inline', sel: 'style=""', path: '' }]]]]), L + ' · רק כאן');
      } });
    }
    /** גודל הטקסט — בפיקסלי מסך (בקנבס מוקטן הידית אינה רגישה פי כמה). */
    dragFont(e) {
      const n = this.c.selected; if (!n) return;
      const fs0 = px(this.cs(n).fontSize); let v = fs0;
      this.track(e, this.fsH, (dx, dy, ev, rx, ry) => { v = Math.max(1, this.stepped(fs0 + ry / 2, ev, 4)); this.preview([n], [['font-size', v + 'px']]); return 'גודל ' + v + 'px'; }, moved => {
        this.unpreview(); if (!moved || v === fs0) return;
        const per = new Map([[n, [['font-size', v + 'px', this.targetFor(n, 'font-size')]]]]);
        this.write(per, 'גודל גופן · ' + this.c.label(n) + ' · ' + r1(fs0) + ' ← ' + v);
      });
    }
    /** מקלדת: Alt + ↑ / ↓ משנים את השוליים בצד שנבחר (Shift — 10); Alt + ← / → בוחרים צד. */
    nudge(key, shift) {
      const n = this.c.selected; if (!n || !this.canWrite()) return;
      if (key === 'ArrowLeft' || key === 'ArrowRight') { const order = ['top', 'right', 'bottom', 'left'], i = order.indexOf(this.side); this.side = order[(i + (key === 'ArrowRight' ? 1 : 3)) % 4]; this.hint('margin-' + this.side); this.note('Alt + ↑ / ↓ — שוליים ' + { top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' }[this.side]); return; }
      const phys = 'margin-' + this.side, d = (key === 'ArrowDown' ? 1 : -1) * (shift ? 10 : 1);
      if (!this.kb || this.kb.n !== n || this.kb.phys !== phys) { this.flushKb(); this.kb = { n, phys, v0: px(this.cs(n)[phys.replace(/-(\w)/, (a, b) => b.toUpperCase())]), v: null }; }
      const k = this.kb; k.v = (k.v == null ? k.v0 : k.v) + d;
      this.preview([n], [[phys, k.v + 'px']]); this.hinted = phys; this.draw();
      clearTimeout(this.kbT); this.kbT = setTimeout(() => this.flushKb(), 450);
    }
    flushKb() {
      clearTimeout(this.kbT); const k = this.kb; this.kb = null; if (!k || k.v == null) return;
      this.unpreview(); if (k.v === k.v0) return;
      this.put([[k.n, k.phys, P(k.v)]], 'מרווח · שוליים ' + { top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' }[k.phys.split('-')[1]] + ' · ' + this.c.label(k.n) + ' · ' + r1(k.v0) + ' ← ' + k.v);
    }
    /** הסרגל: − / + ושדה מספר לאותו מרווח (בטלפון — ליד הידיות; במחשב — בהקשה על ידית בלי גרירה). */
    openBar(n, phys) {
      const host = this.sheetHost(); if (!host) return;
      const g = phys === 'gap' ? this.gapOf(n) : null;
      const cur = () => (g ? r1(this.gapOf(n)?.d ?? g.d) : r1(px(this.cs(n)[phys.replace(/-(\w)/g, (a, b) => b.toUpperCase())])));
      const name = g ? 'רווח בין ' + this.c.label(g.A) + ' ל-' + this.c.label(g.B) : ({ margin: 'שוליים', padding: 'ריפוד' }[phys.split('-')[0]] + ' ' + { top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' }[phys.split('-')[1]] + ' · ' + this.c.label(n));
      const inp = h('input', { type: 'text', dir: 'ltr', inputmode: 'decimal', value: String(cur()), 'aria-label': name, 'data-bar-input': true });
      const set = v => { if (!Number.isFinite(v)) return; if (g) { const gg = this.gapOf(n); if (gg) this.setGap(gg, v); } else { if (/^padding/.test(phys) && v < 0) v = 0; this.put([[n, phys, P(v)]], 'מרווח · ' + name + ' · ' + cur() + ' ← ' + v); } inp.value = String(cur()); };
      inp.addEventListener('change', () => set(parseFloat(inp.value)));
      host.replaceChildren(h('span', { class: 'sp-bar-name' }, name),
        h('button', { type: 'button', class: 'btn sm sp-bar-b', 'aria-label': 'פחות', 'data-bar': '-', onclick: () => set(cur() - 1) }, '−'), inp,
        h('button', { type: 'button', class: 'btn sm sp-bar-b', 'aria-label': 'יותר', 'data-bar': '+', onclick: () => set(cur() + 1) }, '+'),
        h('button', { type: 'button', class: 'btn sm', 'data-bar': '0', onclick: () => set(0) }, '0'),
        h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => { host.hidden = true; } }, '✕'));
      host.hidden = false;
    }
  }
  window.KolSpacing = { Spacing, collapse };
})();
