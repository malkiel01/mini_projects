// קול מרוקו — V14: העורך במגע (טלפון וטאבלט). לא משנה דבר במחשב: כל מה שכאן פועל רק מתחת ל-1000px או במגע (pointer: coarse).
//
// · הגיליון התחתון (Navigator / הפאנל): ידית אחיזה, שלושה גבהים — שורה · חצי · מלא — והחלקה למטה סוגרת; הגובה נזכר (localStorage, עם try).
// · האלמנט הנבחר נשאר גלוי: הקנבס מתקצר עד ראש הגיליון, והאלמנט נגלל מעליו (ראשו — כשהוא גבוה מהשטח שנשאר).
// · מקלדת וירטואלית (visualViewport): הגיליון ושורת עיצוב הטקסט צמודים מעל המקלדת; השדה / השורה הנערכת — גלויים.
// · בסרגל הנבחר: ↑ ↓ (החלפה עם האח) לצד "⋯ ← העבר אל…"; ב-Navigator: ↑ ↓ ⤵ ⤴ לשורה הנבחרת.
// · −/+ בפאנל: לחיצה ארוכה — חוזר ומאיץ.
// · צביטה בקנבס — הגדלה עד 300%, "⤢ התאם" מחזיר.
(() => {
  'use strict';
  const PHONE = '(max-width: 999px)', COARSE = '(pointer: coarse)', LAND = '(max-width: 999px) and (orientation: landscape) and (max-height: 599px)';
  const LS = 'kol-editor-sheet', LS_FS = 'kol-editor-fs', HEIGHTS = ['row', 'third', 'half', 'full'];
  const mq = q => window.matchMedia(q).matches;
  const h = (tag, attrs, ...kids) => { const e = document.createElement(tag); for (const [k, v] of Object.entries(attrs || {})) { if (v == null || v === false) continue; if (k.startsWith('on')) e.addEventListener(k.slice(2), v); else e.setAttribute(k, v === true ? '' : v); } e.append(...kids.flat().filter(x => x != null)); return e; };

  class Touch {
    /** o: { S, el, panel(id, on), phone() } */
    constructor(o) {
      this.o = o; this.S = o.S; this.el = o.el; this.offs = [];
      try { this.height = localStorage.getItem(LS); } catch { this.height = null; }
      if (!HEIGHTS.includes(this.height)) this.height = 'half';
      this.zoom = 1; this.vvFake = null;
      this.realWidth();
      this.paletteBtn(); this.fullBtn();
      this.sheets(); this.selTools(); this.navTools(); this.navRows(); this.repeat(); this.keyboard(); this.pinch();
      const c = this.S.canvas, prev = c.onSelect;
      c.onSelect = (...a) => { if (prev) prev(...a); this.afterSelect(); };
      c.onHeldTap = n => { if (this.hintAt && performance.now() - this.hintAt < 8000) return; this.hintAt = performance.now(); this.o.note?.('נשאר על ' + c.label(n) + ' — לבחירת ההורה: ↑ "בחר הורה" בסרגל הנבחר, או פירורי הלחם'); };
      this.mo = new MutationObserver(() => this.layout()); this.mo.observe(this.el.root, { attributes: true, attributeFilter: ['class'] });
      const rs = () => this.layout(); window.addEventListener('resize', rs); this.offs.push(() => window.removeEventListener('resize', rs));
      // גרירה פעילה (לחיצה ארוכה על כרטיס / פריט בספרייה): הדפדפן אינו מתחיל לגלול — אחרת pointercancel מסיים את הגרירה.
      // המאזין (לא passive) רשום רק מ-drag.start ועד drag.end — שאר הזמן הגלילה אינה ממתינה ל-main thread
      const D = this.S.build?.drag;
      if (D) {
        const tm = e => { if (D.active) e.preventDefault(); };
        let on = false; const add = () => { if (!on) { on = true; document.addEventListener('touchmove', tm, { capture: true, passive: false }); } }, rm = () => { if (on) { on = false; document.removeEventListener('touchmove', tm, { capture: true }); } };
        const st = D.start.bind(D), en = D.end.bind(D);
        D.start = (...a) => { add(); return st(...a); };
        D.end = (...a) => { try { return en(...a); } finally { if (!D.active) rm(); } };
        this.offs.push(() => { rm(); D.start = st; D.end = en; });
      }
      this.layout();
    }
    unmount() { this.mo?.disconnect(); this.offs.forEach(f => f()); this.offs = []; }
    phone() { return mq(PHONE); }
    land() { return mq(LAND); }
    /** הגיליון הפתוח (Navigator או הפאנל), אם הוא גיליון תחתון. */
    openSide() {
      const r = this.el.root; if (!this.phone()) return null;
      for (const id of ['info', 'nav']) if (r.classList.contains(id + '-open')) { const s = r.querySelector('.ed-side.ed-' + id); if (s && getComputedStyle(s).position === 'fixed') return s; }
      return null;
    }

    // ── מצב "טלפון" בטלפון — ברוחב האמיתי ────────────────────
    /** בטלפון, מכשיר צר שרוחבו קרוב לרוחב המסך (עד ‎+30%) נערך ברוחב הקנבס עצמו — קנה מידה 1, בלי הקטנה. רחב ממנו — מוקטן כמו קודם. */
    realWidth() {
      const c = this.S.canvas, orig = c.setViewport.bind(c);
      c.setViewport = w => {
        const hw = c.host.clientWidth, real = !!(this.phone() && w && hw && w > hw && w <= hw * 1.3);
        this.zoom = 1; if (this.fit) { this.fit.hidden = true; this.el.root.classList.remove('ts-zoomed'); c.zoomed = false; this.spacer?.remove(); }
        const out = orig(real ? null : w);
        c.realW = real; if (real) c.viewport = w;                            // המכשיר נשאר "טלפון · 390" (נקודות השבירה), רק בלי הקטנה
        return out;
      };
      if (c.m) c.setViewport(c.viewport);
    }

    // ── V13 במגע: חיפוש פעולה (Ctrl+E) מכפתור — במגע אין מקלדת ────
    paletteBtn() {
      const bar = this.el.bar; if (!bar || !this.S.palette) return;
      this.palBtn = h('button', { type: 'button', class: 'icon-btn ts-pal', 'data-ts-palette': true, 'aria-label': 'חיפוש פעולה או שליטה (Ctrl+E)', title: 'חיפוש פעולה או שליטה (Ctrl+E)', hidden: !this.touchy() || null, onclick: () => this.S.palette.open() }, '🔎');
      const ref = bar.querySelector('[data-panel="info"]'); if (ref) ref.after(this.palBtn); else bar.append(this.palBtn);
      const upd = () => { this.palBtn.hidden = !this.touchy(); }; window.addEventListener('resize', upd); this.offs.push(() => window.removeEventListener('resize', upd));
    }

    // ── מסך מלא: העורך מעל כותרת האפליקציה והלשוניות (במצב רוחב — תמיד) ──
    fullBtn() {
      const bar = this.el.bar; if (!bar) return;
      let on = false; try { on = localStorage.getItem(LS_FS) === '1'; } catch { /* */ }
      this.fsBtn = h('button', { type: 'button', class: 'icon-btn ts-fsb', 'data-ts-fs': true, 'aria-pressed': 'false', 'aria-label': 'מסך מלא לעורך — הקנבס מעל כותרת האפליקציה והלשוניות', title: 'מסך מלא לעורך', hidden: !this.phone() || null, onclick: () => this.setFull(!this.el.root.classList.contains('ts-fs')) }, '⛶');
      const ref = bar.querySelector('[data-panel="nav"]'); if (ref) ref.before(this.fsBtn); else bar.prepend(this.fsBtn);
      this.setFull(on, true);
    }
    setFull(on, quiet) {
      on = !!on && this.phone(); this.el.root.classList.toggle('ts-fs', on); this.fsBtn?.setAttribute('aria-pressed', String(on));
      if (!quiet) try { localStorage.setItem(LS_FS, on ? '1' : '0'); } catch { /* */ }
    }

    // ── הגיליון: ארבעה גבהים — שורה · שליש · חצי · מלא ────────────────────────────────
    sheets() {
      for (const side of this.el.root.querySelectorAll('.ed-side')) {
        const id = side.classList.contains('ed-nav') ? 'nav' : 'info';
        const grip = h('div', { class: 'ts-grip', 'data-ts-grip': id, role: 'button', tabindex: '0', 'aria-label': 'גובה הגיליון — גרירה למעלה / למטה; הקשה מחליפה (שורה · חצי · מלא)', title: 'גרירה: שורה · חצי · מלא · למטה — סגירה' }, h('span', { class: 'ts-grip-bar' }));
        side.prepend(grip);
        side.addEventListener('transitionend', e => { if (e.target === side && e.propertyName === 'height') this.layout(); });   // הקנבס מתקצר לפי הגובה הסופי, לא באמצע המעבר
        grip.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.cycle(); } });
        const head = side.querySelector('.ed-side-head');
        for (const t of [grip, head]) if (t) t.addEventListener('pointerdown', e => this.gripDown(e, side, id, t === grip));
      }
    }
    px(k) { const vh = this.vh(); return k === 'row' ? Math.min(132, vh * 0.3) : k === 'third' ? Math.round(vh * 0.36) : k === 'half' ? Math.round(vh * 0.5) : Math.round(vh - 56); }
    vh() { const v = this.vv(); return v ? v.height : window.innerHeight; }
    gripDown(e, side, id, isGrip) {
      if (!this.phone() || this.land() || getComputedStyle(side).position !== 'fixed') return;
      if (!isGrip && e.target.closest('button, input, select, a')) return;
      e.preventDefault();
      const y0 = e.clientY, h0 = side.getBoundingClientRect().height, t = e.currentTarget; let moved = false, vel = 0; const trail = [[performance.now(), y0]];
      try { t.setPointerCapture(e.pointerId); } catch { /* מגע מדומה */ }
      side.classList.add('ts-dragging');
      const mv = ev => { const dy = ev.clientY - y0; if (Math.abs(dy) > 4) moved = true; const now = performance.now(); trail.push([now, ev.clientY]); while (trail.length > 2 && now - trail[0][0] > 100) trail.shift(); vel = (ev.clientY - trail[0][1]) / Math.max(16, now - trail[0][0]); /* מהירות על פני 100ms אחרונים */ side.style.height = Math.max(48, Math.min(this.px('full'), h0 - dy)) + 'px'; };
      const up = ev => {
        t.removeEventListener('pointermove', mv); t.removeEventListener('pointerup', up); t.removeEventListener('pointercancel', up);
        side.classList.remove('ts-dragging'); side.style.height = '';
        if (!moved) { if (isGrip && ev.type === 'pointerup') this.cycle(); return; }
        if (performance.now() - trail[trail.length - 1][0] > 120) vel = 0;   // האצבע עצרה לפני השחרור — אין הטלה
        const hh = h0 - (ev.clientY - y0);
        if (hh < this.px('row') - 40 || (vel > 1.2 && this.height === 'row')) { this.o.panel(id, false); return; }   // החלקה למטה — סגירה
        let best = 'row'; for (const k of HEIGHTS) if (Math.abs(this.px(k) - hh) < Math.abs(this.px(best) - hh)) best = k;
        if (vel > 1.2 && best !== 'row') best = HEIGHTS[Math.max(0, HEIGHTS.indexOf(best) - 1)];   // הטלה למטה — גובה אחד פחות
        else if (vel < -1.2 && best !== 'full') best = HEIGHTS[HEIGHTS.indexOf(best) + 1];
        this.set(best);
      };
      t.addEventListener('pointermove', mv); t.addEventListener('pointerup', up); t.addEventListener('pointercancel', up);
    }
    cycle() { this.set(HEIGHTS[(HEIGHTS.indexOf(this.height) + 1) % HEIGHTS.length]); }
    set(k) {
      if (!HEIGHTS.includes(k)) return;
      this.height = k; try { localStorage.setItem(LS, k); } catch { /* אחסון חסום — לא חוסם עבודה */ }
      this.layout();
    }
    /** מחיל את הגובה, ומקצר את הקנבס עד ראש הגיליון — כך כל אלמנט אפשר לגלול אל מעליו. */
    layout() {
      const r = this.el.root; if (!r.isConnected) return;
      for (const k of HEIGHTS) r.classList.toggle('ts-' + k, this.phone() && k === this.height);
      for (const g of r.querySelectorAll('[data-ts-grip]')) { g.tabIndex = this.phone() ? 0 : -1; if (this.phone()) g.removeAttribute('aria-hidden'); else g.setAttribute('aria-hidden', 'true'); }   // במחשב — אין עצירת Tab בלתי נראית
      if (this.fsBtn) this.fsBtn.hidden = !this.phone();   // ⛶ — רק מתחת ל-1000px, שם מסך מלא פועל
      if (this.land() && !this.landHinted) { this.landHinted = true; let seen = false; try { seen = localStorage.getItem('kol-editor-landhint') === '1'; localStorage.setItem('kol-editor-landhint', '1'); } catch { /* */ } if (!seen) this.o.note?.('מצב רוחב — העורך על כל המסך. לכותרת וללשוניות של האפליקציה: סובבו את המכשיר לאורך'); }                   // מסך מלא — רק מתחת ל-1000px (שם הוא פועל)
      const side = this.openSide(), panes = r.querySelector('.ed-panes');
      let cover = 0;
      if (side && !this.land() && panes) { const top = side.getBoundingClientRect().top, cur = parseFloat(r.style.getPropertyValue('--ts-cover')) || 0, bot = panes.getBoundingClientRect().bottom + cur; cover = Math.max(0, Math.round(bot - top)); }
      if (this.height === 'full' && side) cover = 0;                         // מלא — הקנבס מתחת לגיליון; אין מה לשמור עליו גלוי
      if ((parseFloat(r.style.getPropertyValue('--ts-cover')) || 0) !== cover) r.style.setProperty('--ts-cover', cover + 'px');
      // גלילה אל הנבחר — רק כשהגיליון עצמו השתנה (נפתח, גובה, מקלדת), לא על כל שינוי מחלקה בעורך (גרירה, הקלדה)
      const key = [side ? side.className : '', this.height, cover, this.kb].join('|');
      if (key !== this.layoutKey) { this.layoutKey = key; clearTimeout(this.keepT); this.keepT = setTimeout(() => this.keep(), 60); }
    }

    // ── האלמנט הנבחר — גלוי מעל הגיליון ─────────────────────
    /** E2E 🟡3: אלמנט במיקום חופשי שנקבע במכשיר רחב יותר (המחשב) חל גם כאן — ועלול לכסות תוכן. אזהרה, ו"בזרימה רק במכשיר הזה" (position: static ב-@media). */
    warnFree() {
      const S = this.S, c = S.canvas, n = c && c.selected;
      if (!this.warn) { this.warn = h('div', { class: 'ed-strip warn ts-freewarn', role: 'status', 'data-ts-freewarn': true, hidden: true }); this.el.gapbar?.after(this.warn); }
      const w = this.warn; w.hidden = true;
      try {
        const dev = S.styler?.device?.(); if (!this.phone() || !n || !dev || !S.pos?.isFree(n) || this.warnOff === n) return;
        const rows = S.styler.perDevice(n, 'position'), row = rows.find(x => x.d.id === dev.id);
        if (!row || row.own || !/^(absolute|fixed)$/.test(row.value)) return;
        const from = rows.find(x => x.d.id === row.from), canW = S.build?.canWrite();
        w.replaceChildren(h('span', {}, '✥ ' + c.label(n) + ' ממוקם חופשית ' + (from ? 'מ"' + from.d.label + '"' : '') + ' — וזה חל גם ב"' + dev.label + '", שם הוא עלול לכסות תוכן.'),
          h('button', { type: 'button', class: 'btn sm', 'data-ts-flow': true, disabled: !canW || null, onclick: () => { if (S.pos.off(n)) w.hidden = true; } }, '⇩ בזרימה ב"' + dev.label + '" בלבד'),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => { this.warnOff = n; w.hidden = true; } }, '✕'));
        w.hidden = false;
      } catch (e) { console.error(e); }
    }
    afterSelect() { this.warnFree(); if (!this.phone()) return; clearTimeout(this.keepT); this.keepT = setTimeout(() => this.keep(), 420); }   // אחרי חלון ההקשה הכפולה (380ms) — גלילה באמצע הייתה מפילה את ההקשה השנייה על אלמנט אחר
    /** גולל את הקנבס כך שהאלמנט הנבחר (או הטקסט שנערך) בתוך השטח הגלוי — ראשו, כשהוא גבוה ממנו. */
    keep(n) {
      if (!this.phone()) return;
      const c = this.S.canvas; n = n || (c && c.selected); if (!c || !n || !c.doc) return;
      const d = c.elOf(n); if (!d || !d.getClientRects().length) return;
      const w = c.frame.contentWindow, s = c.scale || 1, f = c.frame.getBoundingClientRect(), host = c.host.getBoundingClientRect();
      const r = d.getBoundingClientRect(), top = f.top + r.top * s, bot = f.top + r.bottom * s;
      let lo = Math.max(host.top, f.top), hi = Math.min(host.bottom, this.visibleBottom());
      const bar = this.S.text?.active && document.querySelector('.ed-tbar:not([hidden])'); if (bar && this.kb > 0) hi = Math.min(hi, bar.getBoundingClientRect().top);
      if (hi - lo < 40) return;
      const pad = 8;
      if (top >= lo + pad && bot <= hi - pad) return;
      const want = (bot - top > hi - lo - 2 * pad || top < lo + pad) ? top - (lo + pad) : bot - (hi - pad);
      w.scrollBy(0, want / s); c.draw();
    }
    visibleBottom() { const v = this.vv(), vb = v ? v.offsetTop + v.height : window.innerHeight, side = this.openSide(); return side && !this.land() && this.height !== 'full' ? Math.min(vb, side.getBoundingClientRect().top) : vb; }

    // ── מקלדת וירטואלית ─────────────────────────────────────
    vv() { return this.vvFake || window.visualViewport || null; }
    keyboard() {
      const v = window.visualViewport, on = () => this.onViewport();
      if (v) { v.addEventListener('resize', on); v.addEventListener('scroll', on); this.offs.push(() => { v.removeEventListener('resize', on); v.removeEventListener('scroll', on); }); }
      // שדה בפאנל: הגיליון עולה (לפחות חצי), והשדה גלוי
      const fin = e => {
        if (!this.phone()) return; const side = e.target.closest && e.target.closest('.ed-side'); if (!side || !e.target.matches('input, textarea, select, [contenteditable]')) return;
        if (this.height === 'row') this.set('half');
        setTimeout(() => { try { e.target.scrollIntoView({ block: 'center' }); } catch { /* */ } }, 80);
      };
      this.el.root.addEventListener('focusin', fin); this.offs.push(() => this.el.root.removeEventListener('focusin', fin));
      this.onViewport();
    }
    onViewport() {
      const v = this.vv(), r = this.el.root;
      this.kb = v && this.phone() ? Math.max(0, Math.round(window.innerHeight - v.height - v.offsetTop)) : 0;
      if (this.kb < 80) this.kb = 0;                                         // שינוי קטן (סרגל הכתובת) — אינו מקלדת
      r.style.setProperty('--ts-kb', this.kb + 'px'); r.classList.toggle('ts-kb', this.kb > 0);
      if (this.kb > 0) { clearTimeout(this.kbT); this.kbT = setTimeout(() => { this.layout(); const a = document.activeElement; if (a && a.closest && a.closest('.ed-side')) try { a.scrollIntoView({ block: 'center' }); } catch { /* */ } }, 50); }
      else this.layout();
    }
    /** לבדיקות: מקלדת מדומה בגובה px (0 — סגורה). */
    fakeKeyboard(px) { this.vvFake = px ? { height: window.innerHeight - px, offsetTop: 0 } : null; this.onViewport(); }

    // ── ↑ ↓ בסרגל הנבחר; ↑ ↓ ⤵ ⤴ ב-Navigator ─────────────────
    touchy() { return this.phone() || mq(COARSE); }
    moves(n) {
      const B = this.S.build, par = n && n.parent, i = par ? par.kids.indexOf(n) : -1, X = window.KolStruct;
      if (!B || !par || X.ROOTS.has(n.tag)) return null;
      return {
        up: i > 0 ? () => B.moveTo(n, par, par.kids[i - 1], 'למעלה') : null,
        down: i >= 0 && i < par.kids.length - 1 ? () => B.moveTo(n, par, par.kids[i + 2] || null, 'למטה') : null,
        in: i > 0 && B.drag.isBox(par.kids[i - 1]) && !X.why(par.kids[i - 1], [n.tag], [n]) ? () => B.moveTo(n, par.kids[i - 1], null, 'הכנס לרובריקה שלפני') : null,
        out: par.parent && !X.ROOTS.has(par.tag) && !X.why(par.parent, [n.tag], [n]) ? () => B.moveTo(n, par.parent, par.parent.kids[par.parent.kids.indexOf(par) + 1] || null, 'הוצא') : null,
      };
    }
    selTools() {
      const B = this.S.build; if (!B) return;
      const orig = B.drawTools.bind(B);
      B.drawTools = n => {
        orig(n);
        if (!this.touchy()) return;
        const m = this.moves(n), w = B.canWrite();
        const b = (k, icon, label) => h('button', { type: 'button', class: 'icon-btn ts-mv', 'data-ts-mv': k, 'aria-label': label, title: label, disabled: !w || !m || !m[k] || null, onclick: () => { const mm = this.moves(this.S.canvas.selected); if (mm && mm[k]) mm[k](); } }, icon);
        const more = B.tools.querySelector('[data-more]');
        B.tools.insertBefore(b('up', '⬆', 'הזזה למעלה — לפני האח'), more); B.tools.insertBefore(b('down', '⬇', 'הזזה למטה — אחרי האח'), more);
      };
    }
    navTools() {
      const wrap = this.el.navWrap, B = this.S.build; if (!wrap || !B) return;
      const bar = this.navBar = h('div', { class: 'ts-navtools', role: 'toolbar', 'aria-label': 'הזזת השורה הנבחרת', 'data-ts-navtools': true, hidden: true });
      wrap.insertBefore(bar, this.el.navBody);
      const draw = () => {
        const n = this.S.canvas?.selected; bar.hidden = !this.touchy() || !n;
        if (bar.hidden) return;
        const m = this.moves(n), w = B.canWrite();
        const b = (k, icon, label) => h('button', { type: 'button', class: 'btn sm', 'data-ts-nv': k, 'aria-label': label, title: label, disabled: !w || !m || !m[k] || null, onclick: () => { const mm = this.moves(this.S.canvas.selected); if (mm && mm[k]) { mm[k](); draw(); } } }, icon);
        bar.replaceChildren(h('span', { class: 'ts-navname' }, this.S.canvas.label(n)), b('up', '↑', 'למעלה'), b('down', '↓', 'למטה'), b('in', '→', 'פנימה — לתוך הרובריקה שלפני'), b('out', '←', 'החוצה — אל מחוץ לרובריקה'),
          h('button', { type: 'button', class: 'btn sm', 'data-ts-nv': 'to', disabled: !w || !m || null, onclick: () => B.moveSheet(this.S.canvas.selected) }, '↕ העבר אל…'));
      };
      this.drawNav = draw;
      const c0 = this.S.canvas, prevDraw = c0.onDraw; c0.onDraw = (...a) => { if (prevDraw) prevDraw(...a); if (this.navN !== c0.selected) { this.navN = c0.selected; draw(); } };   // בחירה שלא עברה דרך onSelect (ביטול, היסטוריה)
      const c = this.S.canvas, prev = c.onSelect; c.onSelect = (...a) => { if (prev) prev(...a); draw(); };
      const prevCh = c.onChange; c.onChange = (...a) => { if (prevCh) prevCh(...a); draw(); this.warnFree(); };
      draw();
    }

    /** לכל שורה ב-Navigator (במגע): "↕" — ↑ ↓ → ← והעברה לכל יעד, לשורה הזו (גם כשאינה הנבחרת). */
    navRows() {
      const N = this.S.nav, B = this.S.build; if (!N || !B) return;
      const orig = N.row.bind(N);
      N.row = (r, i, sel) => {
        const e = orig(r, i, sel); if (!this.touchy() || !r.n.parent || window.KolStruct.ROOTS.has(r.n.tag)) return e;
        const b = h('button', { type: 'button', class: 'nv-ic ts-rowmv', 'data-ts-rowmv': i, tabindex: '-1', 'aria-label': 'הזזת השורה — למעלה, למטה, פנימה, החוצה, או לכל יעד', title: 'הזזה: ↑ ↓ → ← או "העבר אל…"' }, '↕');
        b.addEventListener('click', ev => { ev.stopPropagation(); if (!B.canWrite()) return; B.moveSheet(r.n); });
        e.insertBefore(b, e.querySelector('.nv-ic')); return e;
      };
      N.draw();
    }

    // ── −/+ : לחיצה ארוכה חוזרת ומאיצה ──────────────────────
    repeat() {
      let t = 0, iv = 0, fired = false;
      const stop = () => { clearTimeout(t); clearInterval(iv); t = iv = 0; };
      const down = e => {
        const b = e.target.closest && e.target.closest('.sg-step'); if (!b || b.disabled || !this.touchy()) return;
        stop(); fired = false;
        t = setTimeout(() => { let n = 0, ms = 160; const tick = () => { if (b.disabled || !b.isConnected) return stop(); fired = true; b.click(); n++; if (n === 6 || n === 16) { clearInterval(iv); ms = Math.max(40, ms / 2); iv = setInterval(tick, ms); } }; tick(); iv = setInterval(tick, ms); }, 450);
      };
      const click = e => { if (fired && e.isTrusted && e.target.closest && e.target.closest('.sg-step')) { fired = false; e.stopPropagation(); e.preventDefault(); } };
      const r = this.el.root;
      r.addEventListener('pointerdown', down); r.addEventListener('click', click, true);
      for (const ev of ['pointerup', 'pointercancel', 'pointerleave']) r.addEventListener(ev, stop, true);
      this.offs.push(() => { stop(); r.removeEventListener('pointerdown', down); r.removeEventListener('click', click, true); for (const ev of ['pointerup', 'pointercancel', 'pointerleave']) r.removeEventListener(ev, stop, true); });
    }

    // ── צביטה: הגדלה עד 300% ────────────────────────────────
    pinch() {
      const c = this.S.canvas; if (!c) return;
      this.fit = h('button', { type: 'button', class: 'btn sm ts-fit', 'data-ts-fit': true, hidden: true, onclick: () => this.setZoom(1) }, '⤢ התאם');
      (c.host.parentElement || c.host).append(this.fit);                   // מחוץ למכל הגולל — נשאר במקומו
      const pts = new Map(); let d0 = 0, z0 = 1;
      const scr = e => { const f = c.frame.getBoundingClientRect(); return [f.left + e.clientX * c.scale, f.top + e.clientY * c.scale]; };   // במסך — קנה המידה משתנה באמצע הצביטה
      const listen = doc => {
        const down = e => { if (e.pointerType !== 'touch') return; pts.set(e.pointerId, scr(e)); if (pts.size === 2) { const [a, b] = [...pts.values()]; d0 = Math.hypot(a[0] - b[0], a[1] - b[1]); z0 = this.zoom; c.press = null; c.armed = null; clearTimeout(c.longT); } };
        const move = e => { if (!pts.has(e.pointerId)) return; pts.set(e.pointerId, scr(e)); if (pts.size === 2 && d0 > 10) { const [a, b] = [...pts.values()]; this.setZoom(z0 * Math.hypot(a[0] - b[0], a[1] - b[1]) / d0); e.preventDefault(); } };
        const up = e => { pts.delete(e.pointerId); if (pts.size < 2) d0 = 0; };
        doc.addEventListener('pointerdown', down, true); doc.addEventListener('pointermove', move, true); doc.addEventListener('pointerup', up, true); doc.addEventListener('pointercancel', up, true);
      };
      const prev = c.onListen; c.onListen = doc => { if (prev) prev(doc); listen(doc); }; if (c.doc) listen(c.doc);
    }
    /** הגדלה של הקנבס (1–3). הקנבס עצמו מתרחב, והמכל גולל אותו; הידיות והמסגרות מחושבות לפי c.scale. */
    setZoom(z) {
      const c = this.S.canvas; z = Math.max(1, Math.min(3, Math.round(z * 100) / 100));
      if (!this.touchy() && z !== 1) return;
      this.zoom = z; this.fit.hidden = z === 1; this.fit.textContent = '⤢ התאם · ' + Math.round(z * 100) + '%';
      this.el.root.classList.toggle('ts-zoomed', z > 1);
      if (c.zoomBase == null || !c.zoomed) { c.zoomBase = { vp: c.viewport }; }
      c.zoomed = z > 1;
      if (z === 1) { c.setViewport(c.zoomBase.vp); c.host.scrollLeft = 0; c.host.scrollTop = 0; return; }
      // רוחב העמוד נשאר כמו שהוא (המכשיר); המסגרת מוגדלת ב-z, והמכל גולל
      const hw = c.host.clientWidth, hh = c.host.clientHeight, vp = c.realW ? hw : c.zoomBase.vp || hw, s = Math.min(1, hw / vp) * z;
      c.scale = s; c.ox = 0;
      c.frame.style.cssText = 'position:absolute;top:0;left:0;width:' + vp + 'px;height:' + (hh / s) + 'px;transform:scale(' + s + ');transform-origin:0 0';
      if (this.spacer) this.spacer.remove();
      this.spacer = h('div', { class: 'ts-spacer', 'aria-hidden': 'true' }); this.spacer.style.cssText = 'position:absolute;top:0;left:0;width:' + Math.ceil(vp * s) + 'px;height:' + Math.ceil(hh) + 'px;pointer-events:none';
      c.host.append(this.spacer); c.draw();
    }
  }
  window.KolTouch = { Touch };
})();
