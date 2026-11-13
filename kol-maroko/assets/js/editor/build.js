/**
 * קול מרוקו — 🎨 עורך (פרוסה V4): מבנה העמוד בעורך — "רובריקות בתוך רובריקות".
 *
 * כאן: הספרייה (לשונית ליד ה-Navigator), "＋" בין אחים, הסרגל הקטן של הנבחר (⠿ ↑ ⧉ 🗑 ⋯), הגרירה (KolDrag), כיוון
 * הרובריקה (↕ / ↔) ורווח, שכפול, עטיפה, פירוק, החלפת תגית, העתק / גזור / הדבק, מקלדת, "העבר אל…", והאזהרות.
 * כל כתיבה — דרך act (קובץ ה-HTML) או multi (כמה קבצים, עסקה אחת) של screen.js, וכתיבת עיצוב — דרך writeStyle
 * (Styler של V3). הפעולות עצמן — KolStruct (structure.js), שבודק לפני כל כתיבה שהדפדפן יקרא את התוצאה כפי שנועדה.
 * כל טקסט מהעמוד מוצג כטקסט בלבד (h / textContent); אין כאן innerHTML.
 */
(function () {
  'use strict';
  const HEAD = /^h[1-6]$/;
  const AX_LABEL = { col: '↕ עמודה', row: '↔ שורה' };

  class Build {
    /** opt: { canvas, nav, navHost, K, X, styler, act, multi, writeStyle, note, sheet, undo, canWrite, pick, rel, begin, panel, setTab, openInCode,
     *        files(), jsTexts(), linkSheet(path), filePath(), select(n) } */
    constructor(opt) {
      Object.assign(this, opt);
      const c = this.canvas, X = this.X;
      this.clip = null;
      this.drag = new window.KolDrag.Drag({ canvas: c, nav: this.nav, K: this.K, X, onDrop: (src, t) => this.drop(src, t), onCancel: t => { if (t && t.noop) this.note('האלמנט כבר שם'); } });
      c.ext = this.drag;
      c.onDragStart = (n, x, y) => { if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true); const sel = c.picked(); this.drag.start({ nodes: sel.includes(n) && sel.length > 1 ? sel : [n] }, x, y); };
      const mv = e => { if (this.drag.active) this.drag.move(e.clientX, e.clientY); };
      const up = e => { if (this.drag.active) { this.swallow = true; setTimeout(() => { this.swallow = false; }, 0); this.drag.end(e.type === 'pointerup', e.clientX, e.clientY); } };
      window.addEventListener('pointermove', mv); window.addEventListener('pointerup', up); window.addEventListener('pointercancel', up);
      this.off = () => { window.removeEventListener('pointermove', mv); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up); };
      this.navDrag();
      this.tools = h('div', { class: 'ed-seltools', hidden: true, role: 'toolbar', 'aria-label': 'האלמנט הנבחר' });
      this.plus = [0, 1].map(i => { const b = h('button', { type: 'button', class: 'ed-plus', hidden: true, 'data-plus': i ? 'after' : 'before', 'aria-label': i ? 'הוספה אחרי' : 'הוספה לפני', title: i ? 'הוספה אחרי' : 'הוספה לפני' }, '＋'); b.addEventListener('click', () => { this.plusHold = false; this.plusClick(b); }); b.addEventListener('pointerenter', () => { this.plusHold = true; }); b.addEventListener('pointerleave', () => { this.plusHold = false; this.plusAt = performance.now(); setTimeout(() => this.draw(), 320); }); return b; });
      // הסרגל של הנבחר — בשורת השביל, לא מעל הקנבס: בקנבס מוקטן כל שכבה מעל התוכן מכסה אחים ובולעת לחיצות (נמדד ב-v1a / v2 / v3)
      (this.toolsHost || c.layer).append(this.tools); c.layer.append(...this.plus);
      this.library();
    }
    unmount() { this.off(); this.drag.hide(); }
    sel() { return this.canvas.selected; }
    body() { const m = this.canvas.m; return m && m.root && m.root.kids.find(k => k.tag === 'body'); }

    // ── הסרגל הקטן של הנבחר, ו"＋" בין אחים ─────────────────
    draw() {
      const c = this.canvas, n = c.selected, r = n && c.rectOf(n), t = this.tools;
      if (!n || n.tag === 'html' || n.tag === 'head' || c.edit?.active) t.hidden = true;
      else { if (t._n !== n || t._w !== this.canWrite()) { t._n = n; t._w = this.canWrite(); this.drawTools(n); } t.hidden = false; }
      void r;
      // "＋" מחוץ לאלמנט (לפניו / אחריו, לפי ציר הרובריקה); יציאה מהקנבס אל ה-"＋" — 300ms חסד, כדי שאפשר יהיה ללחוץ עליו
      let hv = c.hovered && !this.drag.active ? c.hovered : null;
      if (!hv && !this.drag.active && this.plusN && (this.plusHold || performance.now() - this.plusAt < 300)) { hv = this.plusN; clearTimeout(this.plusT); this.plusT = setTimeout(() => this.draw(), 320); }
      const hr = hv && hv.parent && hv.tag !== 'body' && c.rectOf(hv);
      const ax = hr ? this.drag.axis(hv.parent) : null;
      // רק כשהסמן ליד שפת ההתחלה / הסוף של האלמנט (בציר הרובריקה) — "＋" אינו מכסה את האלמנט ואינו בולע לחיצות
      if (hr && hv === c.hovered && c.pt) {
        const px = c.ox + c.pt.x * c.scale, py = c.pt.y * c.scale, E = 10;
        const near = ax.axis === 'row' ? Math.min(Math.abs(px - hr.left), Math.abs(hr.left + hr.width - px)) < E : Math.min(Math.abs(py - hr.top), Math.abs(hr.top + hr.height - py)) < E;
        if (!near) { hv = null; this.dwell = null; }
        else {                                                              // כוונת ריחוף: רק אחרי 350ms ליד אותה שפה — תזוזה עוברת ולחיצה מהירה אינן פוגשות "＋"
          const key = hv; if (!this.dwell || this.dwell.n !== key) this.dwell = { n: key, at: performance.now() };
          const left = 350 - (performance.now() - this.dwell.at);
          if (left > 0 && !(this.plusN === hv)) { clearTimeout(this.dwellT); this.dwellT = setTimeout(() => this.draw(), left + 10); hv = null; }
        }
      }
      if (!hv || !hr || !this.canWrite() || hr.zero || hr.height < 16 || hr.width < 40) { this.plus.forEach(b => { b.hidden = true; }); if (!hv) this.plusN = this.plusHold ? this.plusN : null; return; }
      if (hv === c.hovered) { this.plusN = hv; this.plusAt = performance.now(); }
      this.plus.forEach((b, i) => {
        b.hidden = false; b._n = hv; b._after = !!i;
        let x, y;
        if (ax.axis === 'row') { const start = ax.rev ? hr.left + hr.width : hr.left, end = ax.rev ? hr.left : hr.left + hr.width; x = (i ? end : start) + ((i ? !ax.rev : ax.rev) ? 2 : -24); y = hr.top + hr.height / 2 - 11; }
        else { x = hr.left + hr.width / 2 - 11; y = i ? hr.top + hr.height + 2 : hr.top - 24; }
        b.style.cssText = 'left:' + x + 'px;top:' + Math.max(0, y) + 'px';
      });
    }
    drawTools(n) {
      const w = this.canWrite(), b = (label, icon, fn, off, attrs) => h('button', Object.assign({ type: 'button', class: 'icon-btn', 'aria-label': label, title: label, disabled: off || null, onclick: fn }, attrs || {}), icon);
      const grip = b('גרירה — לכל מקום בעמוד', '⠿', null, !w || this.X.ROOTS.has(n.tag), { 'data-grip': true });
      grip.addEventListener('pointerdown', e => { if (!this.canWrite() || this.X.ROOTS.has(n.tag)) return; e.preventDefault(); const sel = this.canvas.picked(); this.drag.start({ nodes: sel.length > 1 ? sel : [n] }, e.clientX, e.clientY); });
      const box = this.X.canHold(n) && !this.X.ROOTS.has(n.tag) && n.tag !== 'template';
      const ax = box ? this.dirState(n) : null;
      this.tools.replaceChildren(grip,
        b('בחר הורה', '↑', () => { if (n.parent) { this.canvas.select(n.parent); this.canvas.reveal(n.parent); } }, !n.parent),
        ax ? b('כיוון הרובריקה: ' + ax.text + ' — לחיצה מחליפה', ax.icon, () => this.toggleDir(n), !w, { 'data-dir': ax.kind }) : null,
        b('שכפול (Ctrl+D)', '⧉', () => this.duplicate(), !w || this.X.ROOTS.has(n.tag), { 'data-dup': true }),
        b('מחיקה (Delete)', '🗑️', () => this.remove(), !w),
        b('עוד פעולות', '⋯', () => this.menu(n), false, { 'data-more': true }));
    }
    plusClick(b) {
      const n = b._n; if (!n || !n.parent) return;
      const ref = b._after ? n.parent.kids[n.parent.kids.indexOf(n) + 1] || null : n;
      this.chooser(n.parent, ref);
    }
    /** "＋": בוחרים מה להוסיף, ונכנס במקום. */
    chooser(par, ref) {
      const dlg = document.getElementById('sheet');
      const go = it => () => { dlg.close(); this.add(it, par, ref); };
      this.sheet('הוספה · ' + (ref ? 'לפני ' + this.canvas.label(ref) : 'בסוף ' + this.canvas.label(par)), h('div', { class: 'ed-lib-grid' }, this.items().map(it => h('button', { type: 'button', class: 'ed-lib-item', 'data-lib': it.id, onclick: go(it) }, h('span', { 'aria-hidden': 'true' }, it.icon), ' ', it.label))));
    }

    // ── הספרייה ─────────────────────────────────────────────
    items() {
      const L = this.X.LIB, box = L[0];
      return [box, { id: 'row', icon: '↔', label: 'רובריקה ↔ (שורה)', row: 1 }, { id: 'row2', icon: '▥', label: 'שורה של 2', row: 2 }, { id: 'row3', icon: '▥', label: 'שורה של 3', row: 3 }, { id: 'row4', icon: '▥', label: 'שורה של 4', row: 4 }].map(x => (x === box ? Object.assign({}, box, { label: 'רובריקה ↕ (עמודה)' }) : x)).concat(L.slice(1));
    }
    library() {
      const host = this.navHost; if (!host) return;
      const grid = h('div', { class: 'ed-lib-grid', role: 'list', 'aria-label': 'הספרייה' });
      for (const it of this.items()) {
        const b = h('button', { type: 'button', class: 'ed-lib-item', role: 'listitem', 'data-lib': it.id, title: 'לחיצה — אחרי הנבחר (ברובריקה נבחרת — בסופה) · גרירה — לכל מקום' }, h('span', { 'aria-hidden': 'true' }, it.icon), ' ', it.label);
        let p0 = null;
        b.addEventListener('pointerdown', e => { if (e.button > 0) return; p0 = { x: e.clientX, y: e.clientY, id: e.pointerId }; try { b.setPointerCapture(e.pointerId); } catch { /* */ } });
        b.addEventListener('pointermove', e => { if (p0 && !this.drag.active && Math.hypot(e.clientX - p0.x, e.clientY - p0.y) > 4) { if (!this.canWrite()) { p0 = null; return; } try { b.releasePointerCapture(p0.id); } catch { /* */ } p0 = null; this.drag.start({ lib: it }, e.clientX, e.clientY); } });
        b.addEventListener('pointerup', () => { p0 = null; });
        b.addEventListener('click', () => { if (this.swallow) return; this.addHere(it); });
        grid.append(b);
      }
      this.lib = h('div', { class: 'ed-lib', hidden: true, 'data-library': true }, h('p', { class: 'hint' }, 'גוררים אל הקנבס או אל העץ, או לוחצים — נכנס אחרי האלמנט הנבחר (ברובריקה נבחרת — בסופה).'), grid);
      host.append(this.lib);
    }
    /** לחיצה על פריט: אחרי הנבחר; רובריקה נבחרת — בסופה; בלי בחירה — בסוף ה-body. */
    addHere(it) {
      let n = this.sel(), par, ref = null;
      for (let x = n; x; x = x.parent) if (x.tag === 'head') n = null;
      if (!n || n.tag === 'html') { par = this.body(); }
      else if (this.drag.isBox(n) && !this.X.inTemplate(n) && n.tag !== 'template') par = n;
      else { par = n.parent; ref = par.kids[par.kids.indexOf(n) + 1] || null; }
      if (par) this.add(it, par, ref);
    }
    /** הוספה בפועל. כותרת בין כותרות — התגית והמחלקות של השכנה; רובריקת-שורה — HTML + כלל CSS, עסקה אחת. */
    add(it, par, ref) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      const K = this.K, P = K.pathOf(par), idx = ref ? par.kids.indexOf(ref) : par.kids.length;
      if (it.row) return this.addRow(it.row, par, idx);
      let html = it.html, label = 'הוספה · ' + it.label;
      if (it.id === 'heading') {
        const sib = [par.kids[idx - 1], par.kids[idx]].find(k => k && HEAD.test(k.tag));
        if (sib) { const cls = K.attrOf(sib, 'class'); html = '<' + sib.tag + (cls ? ' class="' + window.KolText.escAttr(cls.value) + '"' : '') + '>כותרת</' + sib.tag + '>'; }
      }
      const ok = this.run(label, m => this.X.insert(m, K.byPath(m, P), idx, html), K.byPath(this.canvas.m, P), { tags: this.X.topTags(html), retry: (m, p) => this.X.insert(m, p, idx, html) });
      if (!ok) return false;
      const fresh = K.byPath(this.canvas.m, P.concat(idx)); if (!fresh) return true;
      this.canvas.select(fresh); this.canvas.reveal(fresh);
      if (/^(heading|text|button|link)$/.test(it.id)) setTimeout(() => this.begin(fresh, null, true), 0);
      if (/^(image|audio|video)$/.test(it.id)) this.media(fresh, it.id);
      return true;
    }
    async media(n, kind) {
      const path0 = this.K.pathOf(n), p = await this.pick(kind, kind === 'image' ? 'בחירת תמונה' : kind === 'audio' ? 'בחירת קובץ שמע' : 'בחירת וידאו');
      const cur = this.K.byPath(this.canvas.m, path0);
      if (!p || !cur) return this.note('האלמנט נשאר בלי קובץ — "בלי קובץ" מסומן בקנבס; בוחרים קובץ בלשונית "תוכן"');
      this.act('קובץ · ' + this.canvas.label(cur) + ' · ' + p.split('/').pop(), m => this.K.html.setAttr(m, this.K.byPath(m, path0), 'src', this.rel(p)), cur);
      const after = this.K.byPath(this.canvas.m, path0); if (after) this.canvas.select(after);
    }
    /** רובריקת-שורה: <div class="row-1"> (+ N רובריקות row-1-col) — HTML וכלל CSS בשם קריא, עסקה אחת, שני קבצים. */
    addRow(cols, par, idx) {
      const K = this.K, used = new Set(); for (const e of this.canvas.doc.querySelectorAll('[class]')) for (const c of e.classList) used.add(c);
      const sheetPath = this.pagePath(), doc = sheetPath && this.docOf(sheetPath);
      if (!doc) return this.note('אין קובץ CSS מקושר לעמוד — מקשרים אחד (📁 פרויקט), ואז מוסיפים רובריקת-שורה', true);
      let i = 1; while (used.has('row-' + i) || doc.text().includes('.row-' + i + ' ') || doc.text().includes('.row-' + i + '{')) i++;
      const name = 'row-' + i, col = name + '-col', kids = cols > 1 ? Array.from({ length: cols }, () => '<div class="' + col + '"></div>').join('') : '';
      const html = '<div class="' + name + '">' + kids + '</div>', P = K.pathOf(par);
      let res; try { res = this.X.insert(this.canvas.m, par, idx, html); } catch (e) { return this.reject(e, par, null); }
      const ST = window.KolStyle, rtl = true, c1 = ST.planNewRule(K.parseCss(doc.text()), '.' + name, [['display', 'flex'], ['flex-direction', 'row'], ['flex-wrap', 'wrap']], rtl);
      let css = K.applyPatches(doc.text(), c1.patches);
      if (cols > 1) css = K.applyPatches(css, ST.planNewRule(K.parseCss(css), '.' + col, [['flex', '1 1 0'], ['min-width', '0']], rtl).patches);
      const label = (cols > 1 ? 'שורה של ' + cols : 'רובריקה ↔') + ' · .' + name;
      if (!this.multi(label, [{ html: true, patches: res.patches }, { doc, patches: this.X.diff(doc.text(), css) }])) return false;
      const fresh = K.byPath(this.canvas.m, P.concat(idx)); if (fresh) { this.canvas.select(fresh); this.canvas.reveal(fresh); }
      this.note('נוספה ' + label + ' — ' + sheetPath + ': .' + name + (cols > 1 ? ', .' + col : ''), false, { label: 'בטל', run: this.undo });
      return true;
    }

    // ── גרירה ────────────────────────────────────────────────
    navDrag() {
      const nv = this.nav; if (!nv) return;
      let p0 = null;
      nv.list.addEventListener('pointerdown', e => {
        if (e.button > 0 || e.target.closest('[data-act]')) return;
        const row = e.target.closest('.nv-row'), r = row && nv.rows[+row.dataset.i]; if (!r) return;
        p0 = { x: e.clientX, y: e.clientY, n: r.n, id: e.pointerId, touch: e.pointerType === 'touch', t: performance.now() };
      });
      nv.list.addEventListener('pointermove', e => {
        if (!p0 || this.drag.active) return;
        const d = Math.hypot(e.clientX - p0.x, e.clientY - p0.y);
        if (p0.touch && performance.now() - p0.t < 400) { if (d > 8) p0 = null; return; }   // מגע: לחיצה ארוכה, ואז גרירה (אחרת — גלילה)
        if (d > 4) {
          const n = p0.n; p0 = null;
          if (this.X.ROOTS.has(n.tag) || !this.canWrite()) return;
          try { nv.list.setPointerCapture(e.pointerId); } catch { /* */ }
          const sel = this.canvas.picked(); this.drag.start({ nodes: sel.includes(n) && sel.length > 1 ? sel : [n] }, e.clientX, e.clientY);
        }
      });
      nv.list.addEventListener('touchmove', e => { if (this.drag.active) e.preventDefault(); }, { passive: false });
      nv.list.addEventListener('pointerup', () => { p0 = null; });
      nv.list.addEventListener('click', e => { if (this.swallow) { e.stopPropagation(); e.preventDefault(); } }, true);
    }
    /** השחרור: הזזה (אלמנטים) או הוספה (ספרייה). יעד שאינו תקין — דבר אינו נכתב, וההסבר (עם ההצעה, אם יש). */
    async drop(src, t) {
      const K = this.K, c = this.canvas;
      if (src.lib) return this.add(src.lib, t.par, t.ref);
      if (t.bad) return this.reject(Object.assign(new K.SrcError(t.bad.msg, 'invalid'), { fix: t.bad.fix || null }), t.par, src.nodes, t.ref);
      const nodes = src.nodes, P = K.pathOf(t.par), refP = t.ref ? K.pathOf(t.ref) : null;
      // הזזה שמוציאה אלמנט מהרובריקה שקוד הנתונים קשור אליה (או מכניסה לתוכה) — אזהרה, לא חסימה
      const moved = nodes.filter(n => this.X.bindingOf(n) !== this.X.bindingOf(t.par) && [...this.attrsDeep(n)].some(a => /^data-kol-/.test(a)));
      if (moved.length && !(await this.confirm('הזזה', ['האלמנט עובר אל מחוץ לרובריקה שקוד הנתונים (js/kol-data.js) קשור אליה — ' + moved.map(n => c.label(n)).join(', ') + ' עלול להפסיק לעבוד']))) return;
      // V7: במכשיר — "להזיז בכל המכשירים (HTML), או רק כאן (order)?" (רק כשהאלמנט נשאר באותה רובריקה — order אינו חוצה רובריקות)
      const dev = this.device && this.device();
      if (dev && nodes.length === 1 && nodes[0].parent === t.par && this.devices && this.devices()) {
        const pd = this.styler.computed(t.par, 'display'), flex = /flex|grid/.test(pd);
        const go = await this.ask('סדר ב' + dev.label, ['להזיז בכל המכשירים (שינוי ב-HTML), או רק ב' + dev.label + ' (order לאחים, ב-@media (max-width: ' + dev.max + 'px))?' + (flex ? '' : ' ברובריקה שאינה flex — "רק כאן" יכתוב לה גם display:flex (בעמודה) ב' + dev.label + '.')],
          [['all', 'בכל המכשירים'], ['here', 'רק ב' + dev.label], ['cancel', 'בטל']]);
        if (go === 'cancel' || !go) return;
        if (go === 'here') { this.devices().order(nodes[0], t.ref); return; }
      }
      const label = 'גרירה · ' + (nodes.length > 1 ? nodes.length + ' אלמנטים' : c.label(nodes[0])) + ' · אל ' + (t.ref ? 'לפני ' + c.label(t.ref) : 'סוף ' + c.label(t.par));
      const paths = nodes.map(n => K.pathOf(n)), outs = nodes.map(n => K.outer(c.m, n)); let start = -1;
      const ok = this.act(label, m => { const r = this.X.move(m, paths.map(p => K.byPath(m, p)), K.byPath(m, P), refP ? K.byPath(m, refP) : null); start = nodes.length === 1 ? r.start : -1; return r; }, nodes[0]);
      if (!ok) return;
      // הבחירה (והפאנל שמתעדכן איתה) — בתמונה הבאה: הקובץ והקנבס כבר עודכנו, והשחרור נשאר מהיר גם בעמוד גדול
      requestAnimationFrame(() => {
        if (start >= 0) { let hit = null; K.walk(c.m.root, x => { if (!hit && x.from === start && !x.implied) hit = x; }); if (hit) { c.select(hit); return; } }
        this.reselect(outs);
      });
    }
    /** אחרי הזזה: בוחרים את מה שהוזז (לפי הטקסט שלו, מהקרוב לסמן). */
    reselect(outs) {
      const K = this.K, c = this.canvas, found = [];
      K.walk(c.m.root, n => { if (!n.implied && outs.some(o => K.outer(c.m, n).trim() === o.trim()) && !found.some(f => { for (let p = n.parent; p; p = p.parent) if (p === f) return true; return false; })) found.push(n); });
      if (!found.length) return;
      c.keepMulti = true; c.select(found[0]); c.multi.clear(); for (const n of found.slice(1)) c.multi.add(n); c.keepMulti = false; c.draw(); c.reveal(found[0]);
    }
    *attrsDeep(n) { const st = [n]; while (st.length) { const x = st.pop(); for (const a of x.attrs) yield a.name; st.push(...x.kids); } }
    /** פעולה שנדחתה: ההסבר, וכשיש הצעה — כפתור שמבצע אותה (פעולה אחת, עסקה אחת). */
    reject(e, par, nodes, ref) {
      if (!(e instanceof this.K.SrcError)) throw e;
      if (e.code === 'noop') return this.note(e.message);
      const fix = e.fix;
      if (!fix || !par) return this.note(e.message, true, e.code === 'template' || e.code === 'broken' ? { label: '</> פתח בקוד', run: () => this.openInCode(par) } : null);
      const K = this.K, P = K.pathOf(par), refP = ref ? K.pathOf(ref) : null, paths = nodes ? nodes.map(n => K.pathOf(n)) : null;
      this.note(e.message, true, { label: fix.kind === 'retag' ? 'הפוך ל-<' + fix.tag + '> והכנס' : 'עטוף ב-<' + fix.tag + '> והכנס', run: () => {
        if (!paths) return;
        if (fix.kind === 'retag') this.act('הפוך ל-<' + fix.tag + '> והכנס', m => this.X.withFix(m, fix, K.byPath(m, P), (mm, p) => this.X.move(mm, paths.map(x => K.byPath(m, x)).map(n => this.relocate(mm, m, n)), p, refP ? this.relocate(mm, m, K.byPath(m, refP)) : null)), par);
        else this.act('עטוף ב-<' + fix.tag + '> והכנס', m => {
          const ns = paths.map(x => K.byPath(m, x)), w = this.X.wrap(m, ns, fix.tag), wrapped = this.relocate(w.model, m, ns[0]);
          const out = this.X.move(w.model, [wrapped], this.relocate(w.model, m, K.byPath(m, P)), refP ? this.relocate(w.model, m, K.byPath(m, refP)) : null);
          return { patches: this.X.diff(m.src, out.src) };
        }, par);
      } });
    }
    /** אותו אלמנט במודל אחר (אחרי צעד ראשון): לפי מיקומו בטקסט, עם ההזזה של מה שנכתב לפניו. */
    relocate(m2, m1, n) {
      if (!n) return null;
      const ps = this.X.diff(m1.src, m2.src)[0], at = !ps || n.from < ps.from ? n.from : n.from >= ps.to ? n.from + ps.text.length - (ps.to - ps.from) : n.from;
      let hit = null; this.K.walk(m2.root, x => { if (!hit && x.from === at && !x.implied) hit = x; });
      return hit || this.K.byPath(m2, this.K.pathOf(n));
    }
    /** כתיבה: act, ובדחייה עם הצעה — הכפתור. */
    run(label, fn, par, opt) {
      try { this.check(fn); } catch (e) { if (!(e instanceof this.K.SrcError)) throw e; if (e.fix && opt && opt.retry) { const P = this.K.pathOf(par); this.note(e.message, true, { label: e.fix.kind === 'retag' ? 'הפוך ל-<' + e.fix.tag + '> והכנס' : 'עטוף ב-<' + e.fix.tag + '> והכנס', run: () => this.act(label, m => this.X.withFix(m, e.fix, this.K.byPath(m, P), opt.retry), par) }); return false; } this.reject(e, par); return false; }
      return this.act(label, fn, par);
    }
    check(fn) { fn(this.canvas.m); }

    // ── כיוון הרובריקה ──────────────────────────────────────
    /** המצב המחושב: col / row (עם "הפוכה") / grid / אחר. */
    dirState(n) {
      const e = this.canvas.elOf(n); let s = null; try { s = e && this.canvas.frame.contentWindow.getComputedStyle(e); } catch { s = null; }
      if (!s) return { kind: 'col', icon: '↕', text: 'עמודה' };
      const d = s.display;
      if (/flex/.test(d)) { const f = s.flexDirection, rev = f.endsWith('reverse'); return f.startsWith('row') ? { kind: 'row', icon: '↔', text: 'שורה' + (rev ? ', הפוכה' : ''), rev, flex: true } : { kind: 'col', icon: '↕', text: 'עמודה' + (rev ? ', הפוכה' : ''), rev, flex: true }; }
      if (/grid/.test(d)) return { kind: 'grid', icon: '▦', text: 'רשת', other: d };
      if (d === 'block' || d === 'flow-root' || d === 'list-item') return { kind: 'col', icon: '↕', text: 'עמודה', block: true };
      return { kind: 'other', icon: '⋯', text: d, other: d };
    }
    /** מה נכתב, לפי הטבלה של V4: block ← שורה: flex + row + wrap; flex ← רק הכיוון; grid / אחר — מוצע, ונאמר מה ישתנה. */
    dirSets(n, want) {
      const s = this.dirState(n);
      if (s.kind === want && !s.rev) return [];
      if (s.flex) return [['flex-direction', want === 'row' ? 'row' : 'column']];
      if (s.block) return want === 'row' ? [['display', 'flex'], ['flex-direction', 'row'], ['flex-wrap', 'wrap']] : [];
      return want === 'row' ? [['display', 'flex'], ['flex-direction', 'row'], ['flex-wrap', 'wrap']] : [['display', 'flex'], ['flex-direction', 'column']];
    }
    toggleDir(n) { const s = this.dirState(n); this.setDir(n, s.kind === 'row' ? 'col' : 'row'); }
    async setDir(n, want) {
      const s = this.dirState(n), sets = this.dirSets(n, want);
      if (!sets.length) return this.note('הרובריקה כבר ' + (want === 'row' ? 'שורה' : 'עמודה') + (s.rev ? ' (הפוכה — נשארת כמו שהיא בקוד)' : ''));
      if (s.other && !(await this.confirm('הפוך ל' + (want === 'row' ? 'שורה' : 'עמודה'), ['התצוגה היום: display: ' + s.other + '. ייכתב: ' + sets.map(([p, v]) => p + ': ' + v).join('; ') + ' — עריכת הרשת עצמה ב-V6']))) return;
      const St = this.styler, t = St.targets(n)[0];
      if (!t) return this.note('אין לאן לכתוב את הכיוון — מקשרים קובץ CSS לעמוד', true);
      const blocked = sets.map(([p]) => [p, St.visible(n, t, p)]).filter(([, v]) => !v.ok);
      if (blocked.length) {
        const [p, v] = blocked[0], by = v.by;
        const go = await this.ask('הכיוון לא ייראה', p + ' נדרס בידי ' + by.r.sel + ' (' + (by.d.important ? '!important' : 'כלל חזק יותר') + (by.r.path ? ' · ' + by.r.path + ':' + by.r.line : '') + ')',
          [['rule', 'לשנות את הכלל הדורס'], ['cancel', 'בטל']]);
        if (go !== 'rule') return;
        const bt = St.targets(n).find(x => x.kind === 'rule' && x.sel === by.r.sel && x.path === by.r.path) || { kind: 'rule', sel: by.r.sel, path: by.r.path, line: by.r.line, media: by.r.media, r: by.r };
        return this.writeDir(n, s, want, sets, bt);
      }
      return this.writeDir(n, s, want, sets, t);
    }
    writeDir(n, s, want, sets, t) {
      const label = 'כיוון · ' + this.canvas.label(n) + ' · ' + (s.kind === 'row' ? 'שורה' : s.kind === 'col' ? 'עמודה' : s.text) + ' ← ' + (want === 'row' ? 'שורה' : 'עמודה');
      const docs = this.writeStyle(new Map([[n, sets.map(([p, v]) => [p, v, t])]]), label);
      if (docs && docs.length) this.note(label + ' — ' + t.sel + ' · ' + t.path, false, { label: 'בטל', run: this.undo });
      return docs;
    }
    /** "פריסה" בראש לשונית "מתקדם": מתג ↕ / ↔, רווח, ושורה לקריאה ל-flex-wrap. */
    layoutSection(n, design) {
      if (!n || !this.X.canHold(n) || this.X.ROOTS.has(n.tag) && n.tag !== 'body' || n.tag === 'template') return null;
      const s = this.dirState(n), w = this.canWrite();
      const btn = (kind, label) => h('button', { type: 'button', class: 'btn sm' + (s.kind === kind ? ' primary' : ''), 'aria-pressed': String(s.kind === kind), 'data-setdir': kind, disabled: !w || null, onclick: () => this.setDir(n, kind) }, label);
      const sec = h('section', { class: 'sg-sec', 'data-sec': 'layout' }, h('h3', { class: 'dt-h' }, 'פריסה · רובריקה'),
        h('div', { class: 'sg-line', role: 'group', 'aria-label': 'כיוון' }, h('span', { class: 'sg-lab' }, 'כיוון'), btn('col', '↕ עמודה'), btn('row', '↔ שורה'), h('span', { class: 'hint', 'data-dirnow': s.kind }, ' עכשיו: ' + s.text)));
      if (s.kind === 'grid' || s.kind === 'other') sec.append(h('p', { class: 'hint' }, 'התצוגה היא ' + s.text + ' (display: ' + s.other + '). "↕ / ↔" יציעו להפוך אותה ל-flex ויאמרו מה ישתנה. עריכת הרשת עצמה — V6.'));
      if (s.flex || s.kind === 'grid') { if (design && design.num) sec.append(design.num(s.kind === 'row' ? 'column-gap' : 'row-gap', 'רווח בין הילדים', { group: 'gap' })); }
      else if (s.block) {
        // זרימה רגילה: רווח שהוקלד כותב display:flex; flex-direction:column; gap — עסקה אחת (הערך עובר checkValue / CSS.supports)
        const inp = h('input', { type: 'text', class: 'sg-in', dir: 'ltr', inputmode: 'decimal', placeholder: '0', 'aria-label': 'רווח בין הילדים', 'data-blockgap': true, disabled: !w || null });
        const go = () => {
          let v = inp.value.trim(); if (!v) return; if (/^-?\d+(\.\d+)?$/.test(v)) v += 'px';
          const t = this.styler.targets(n)[0]; if (!t) return this.note('אין לאן לכתוב — מקשרים קובץ CSS לעמוד', true);
          const label = 'רווח · ' + this.canvas.label(n) + ' · ' + v;
          const docs = this.writeStyle(new Map([[n, [['display', 'flex', t], ['flex-direction', 'column', t], ['row-gap', v, t]]]]), label);
          if (docs && docs.length) this.note(label + ' — הרובריקה הפכה ל-flex בעמודה (display:flex; flex-direction:column), כדי שהרווח ייראה', false, { label: 'בטל', run: this.undo });
        };
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
        inp.addEventListener('change', go);
        sec.append(h('div', { class: 'sg-line' }, h('span', { class: 'sg-lab' }, 'רווח בין הילדים'), inp, h('span', { class: 'hint' }, ' ייכתב display:flex; flex-direction:column; gap')));
      }
      try { const cs = this.canvas.frame.contentWindow.getComputedStyle(this.canvas.elOf(n)); if (/flex/.test(cs.display)) sec.append(h('p', { class: 'hint', 'data-wrap': cs.flexWrap }, 'גלישה (flex-wrap): ' + cs.flexWrap + ' · יישור, גלישה ורוחב העמודות — V6; עד אז ', h('button', { type: 'button', class: 'btn sm', onclick: () => this.openInCode(n) }, '</> פתח בקוד'))); } catch { /* */ }
      if (!n.kids.length && !n.nodes.some(x => x.type === 'text' && /\S/.test(x.value))) sec.append(h('p', { class: 'hint', 'data-empty-note': true }, 'רובריקה ריקה — בתצוגה החיה גובהה 0. בעורך היא מוצגת עם גובה מינימלי וקו מקווקו (לא נכתב לקובץ).'));
      return sec;
    }

    // ── שכפול, עטיפה, פירוק, תגית ───────────────────────────
    duplicate() {
      const c = this.canvas, n = c.selected; if (!n) return this.note('בוחרים אלמנט, ואז משכפלים');
      const P = this.K.pathOf(n); let ch = [];
      const ok = this.act('שכפול · ' + c.label(n), m => { const r = this.X.duplicate(m, this.K.byPath(m, P)); ch = r.changes; return r; }, n);
      if (!ok) return;
      const copy = this.K.byPath(c.m, P.slice(0, -1).concat(P[P.length - 1] + 1)); if (copy) { c.select(copy); c.reveal(copy); }
      this.note('שוכפל: ' + c.label(n) + (ch.length ? ' · שונה בהעתק: ' + ch.map(([a, o, v]) => a + ' ' + o + ' ← ' + v).join(', ') : ''), false, { label: 'בטל', run: this.undo });
    }
    async wrap() {
      const c = this.canvas, ns = this.X.order(c.picked()); if (!ns.length) return this.note('בוחרים אלמנט (או כמה אחים), ואז עוטפים');
      const paths = ns.map(n => this.K.pathOf(n));
      try { this.X.wrap(c.m, ns); }
      catch (e) {
        if (!(e instanceof this.K.SrcError)) throw e;
        if (!e.gap) return this.reject(e, ns[0].parent);
        const go = await this.ask('הבחירה אינה רצופה', 'האלמנטים אינם אחים צמודים באותה רובריקה.', [['each', 'עטוף כל אחד בנפרד'], ['one', 'העבר לרובריקה אחת'], ['cancel', 'בטל']]);
        if (go === 'each') return this.act('עטיפה · כל אחד בנפרד · ' + ns.length, m => { let cur = m; for (const p of paths.slice().reverse()) { cur = this.X.wrap(cur, [this.K.byPath(cur, p)]).model; } return { patches: this.X.diff(m.src, cur.src) }; }, ns[0]);
        if (go === 'one') return this.act('עטיפה · ' + ns.length + ' אלמנטים לרובריקה אחת', m => {
          const first = this.K.byPath(m, paths[0]), w = this.X.wrap(m, [first]);
          const box = this.relocate(w.model, m, first), rest = paths.slice(1).map(p => this.relocate(w.model, m, this.K.byPath(m, p)));
          const mv = this.X.move(w.model, rest, box, null); return { patches: this.X.diff(m.src, mv.src) };
        }, ns[0]);
        return;
      }
      const ok = this.act('עטיפה ברובריקה · ' + (ns.length > 1 ? ns.length + ' אלמנטים' : c.label(ns[0])), m => this.X.wrap(m, paths.map(p => this.K.byPath(m, p))), ns[0]);
      if (!ok) return;
      const box = this.K.byPath(c.m, paths[0]); if (box) { c.select(box); c.reveal(box); }
      this.note('נעטף ברובריקה (<div>), בתוך השורה', false, { label: '🧹 סדר את הקוד של האלמנט', run: () => this.tidy(box) });
    }
    /** "סדר את הקוד של האלמנט" (03 §2.3): כל ילד בשורה משלו, בהזחה של הרובריקה — לפי בקשה בלבד. */
    tidy(n) {
      const K = this.K, c = this.canvas; n = n && K.byPath(c.m, K.pathOf(n)); if (!n || !n.et || !n.kids.length) return;
      const src = c.m.src, ind = (() => { let s = n.from; while (s > 0 && src[s - 1] !== '\n') s--; return src.slice(s, n.from).replace(/\S.*$/, ''); })(), unit = /\n(\t| {2,4})\S/.exec(src)?.[1] || '  ';
      if (n.nodes.some(x => x.type === 'text' && /\S/.test(x.value)) || /<(pre|textarea|script|style)\b/i.test(K.outer(c.m, n))) return this.note('יש בה טקסט חופשי או קוד שתלוי ברווחים — מסדרים בעורך הקוד');
      const inner = n.kids.map(k => c.m.nl + ind + unit + K.outer(c.m, k).trim()).join('') + c.m.nl + ind;
      this.act('סידור הקוד · ' + c.label(n), () => ({ patches: [{ from: n.st[1], to: n.et[0], text: inner }] }), n);
    }
    async unwrap() {
      const c = this.canvas, n = c.selected; if (!n) return;
      const cls = this.K.classesOf(n), rules = [];
      for (const s of this.sheetsList()) for (const r of this.K.cssAll(this.K.parseCss(s.doc.text()))) if (r.type === 'rule' && cls.some(k => new RegExp('\\.' + k.replace(/[^\w-]/g, '\\$&') + '(?![\\w-])').test(r.sel))) rules.push(r.sel.trim() + ' · ' + s.path);
      if (rules.length && !(await this.confirm('פירוק רובריקה', ['כללי ה-CSS של הרובריקה יפסיקו לחול על הילדים ועל הפריסה (הכללים עצמם נשארים בקובץ, בלי אלמנט שמשתמש בהם):', ...rules.slice(0, 8)]))) return;
      const P = this.K.pathOf(n), kids = n.kids.length;
      if (!this.act('פירוק · ' + c.label(n), m => this.X.unwrap(m, this.K.byPath(m, P)), n)) return;
      const first = kids ? this.K.byPath(c.m, P) : this.K.byPath(c.m, P.slice(0, -1)); if (first) c.select(first);
      this.note('פורק: ' + kids + ' ילדים נשארו במקום', false, { label: 'בטל', run: this.undo });
    }
    async retag(n, tag) {
      const c = this.canvas; n = n || c.selected; if (!n) return;
      tag = String(tag || '').trim().toLowerCase();
      const P = this.K.pathOf(n), deps = [];
      for (const s of this.sheetsList()) for (const r of this.K.cssAll(this.K.parseCss(s.doc.text()))) if (r.type === 'rule' && new RegExp('(^|[\\s>+~(,])' + n.tag + '(?![\\w-])').test(r.sel)) { let hit = false; try { hit = !!c.elOf(n) && window.KolDetails.splitSel(r.sel).some(p => c.elOf(n).matches(p)); } catch { hit = false; } if (hit) deps.push(r.sel.trim() + ' · ' + s.path); }
      try { this.X.retag(c.m, n, tag); } catch (e) { return this.reject(e, n.parent); }
      if (deps.length && !(await this.confirm('החלפת תגית', ['כללי CSS שנשענים על <' + n.tag + '> יפסיקו לחול:', ...deps.slice(0, 8), 'אפשר להוסיף לאלמנט מחלקה ולכתוב לה כלל (לשונית "עיצוב").']))) return;
      if (!this.act('תגית · ' + c.label(n) + ' · ' + n.tag + ' ← ' + tag, m => this.X.retag(m, this.K.byPath(m, P), tag), n)) return;
      const x = this.K.byPath(c.m, P); if (x) c.select(x);
    }
    /** בפאנל "תוכן": התגית — רשימה לפי הקשר, והקלדה חופשית. */
    tagField(n) {
      if (!n || this.X.ROOTS.has(n.tag) || n.tag === 'template' || this.X.isVoid(n.tag) || n.implied) return null;
      const id = 'ed-tags-' + Math.random().toString(36).slice(2, 7);
      const inp = h('input', { type: 'text', class: 'ed-tag-in', value: n.tag, list: id, dir: 'ltr', spellcheck: 'false', autocomplete: 'off', 'aria-label': 'תגית', 'data-retag': true, disabled: !this.canWrite() || null });
      const dl = h('datalist', { id }, this.X.TAGS.filter(t => t !== n.tag).map(t => h('option', { value: t })));
      const go = () => { const v = inp.value.trim().toLowerCase(); if (v && v !== n.tag) this.retag(n, v); };
      inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
      inp.addEventListener('change', go);
      return h('label', { class: 'ct-row ed-tag' }, h('span', {}, 'תגית'), inp, dl, h('span', { class: 'hint' }, ' נכתב בפתיחה ובסגירה בלבד'));
    }

    // ── העתק / גזור / הדבק ──────────────────────────────────
    copy(cut) {
      const c = this.canvas, ns = this.X.order(c.picked()); if (!ns.length) return false;
      const html = ns.map(n => this.K.outer(c.m, n)).join(c.m.nl);
      const cls = new Set(); for (const n of ns) this.K.walk(n, x => this.K.classesOf(x).forEach(k => cls.add(k)));
      const sheets = new Set(); for (const s of this.sheetsList()) { const t = s.doc.text(); if ([...cls].some(k => t.includes('.' + k))) sheets.add(s.path); }
      this.clip = { html, page: this.filePath(), sheets: [...sheets], at: Date.now() };
      try { if (window.ClipboardItem && navigator.clipboard?.write) navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([html], { type: 'text/plain' }) })]).catch(() => navigator.clipboard.writeText?.(html).catch(() => {})); else navigator.clipboard?.writeText?.(html).catch(() => {}); } catch { /* הלוח של המערכת חסום — הלוח של העורך עובד */ }
      if (cut) return this.remove(true);
      this.note('הועתק: ' + (ns.length > 1 ? ns.length + ' אלמנטים' : c.label(ns[0])) + ' — Ctrl+V מדביק אחרי הנבחר');
      return true;
    }
    /** הדבקה: text — מלוח המערכת (אם יש). מהלוח של העורך — כמו שהוא; מבחוץ — נוקה מסקריפטים ומאירועים, ונאמר. */
    async paste(text, where) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      const c = this.canvas, own = this.clip && (text == null || text.trim() === this.clip.html.trim());
      let html = own ? this.clip.html : text, cleaned = [];
      if (!html || !html.trim()) return this.note('הלוח ריק — מעתיקים אלמנט (Ctrl+C), ואז מדביקים');
      if (!own) { const r = this.clean(html); html = r.html; cleaned = r.removed; }
      let n = c.selected, par, idx;
      for (let x = n; x; x = x.parent) if (x.tag === 'head') n = null;
      if (n && this.drag.isBox(n) && n.tag !== 'template' && !this.X.inTemplate(n)) {
        if (!where) where = await this.ask('הדבקה', 'הנבחר הוא רובריקה — לאן?', [['in', 'בתוכה (בסוף)'], ['after', 'אחריה'], ['cancel', 'בטל']]);
        if (where === 'cancel' || !where) return;
      }
      if (!n || n.tag === 'html' || n.tag === 'body') { par = this.body(); idx = par ? par.kids.length : 0; }
      else if (where === 'in') { par = n; idx = n.kids.length; }
      else { par = n.parent; idx = par.kids.indexOf(n) + 1; }
      if (!par) return;
      // בין עמודים: העמוד הזה אינו מקשר את קובץ ה-CSS שבו הכללים של המחלקות — מציעים לקשר, ושום כלל אינו משוכפל בשקט
      if (own && this.clip.page !== this.filePath()) {
        const linked = new Set(this.linkedPaths ? this.linkedPaths() : this.sheetsList().map(s => s.path)), miss = this.clip.sheets.filter(p => !linked.has(p));
        if (miss.length) {
          const go = await this.ask('הדבקה מעמוד אחר', 'העיצוב של האלמנט נמצא ב-' + miss.join(', ') + ', והעמוד הזה אינו מקשר אותו.', [['link', 'קשר את ' + miss.join(', ') + ' לעמוד'], ['bare', 'הדבק בלי העיצוב'], ['cancel', 'בטל']]);
          if (go === 'cancel' || !go) return;
          if (go === 'link') for (const p of miss) if (!(await this.linkSheet(p))) return;
          par = this.K.byPath(c.m, this.K.pathOf(par)) || par;
        }
      }
      const P = this.K.pathOf(par);
      const ok = this.run('הדבקה · ' + (this.X.topTags(html).join(', ') || 'קטע'), m => this.X.paste(m, this.K.byPath(m, P), idx, html), par, { retry: (m, p) => this.X.paste(m, p, idx, html) });
      if (!ok) return;
      const fresh = this.K.byPath(c.m, P.concat(idx)); if (fresh) { c.select(fresh); c.reveal(fresh); }
      this.note('הודבק' + (cleaned.length ? ' · הוסרו מהקטע שבא מבחוץ: ' + cleaned.join(', ') : ''), false, { label: 'בטל', run: this.undo });
    }
    /** קטע מבחוץ (לא מהעורך): בלי סקריפטים, מסגרות, אירועים (on…) וכתובות javascript: — ומה שהוסר נאמר. */
    clean(html) {
      const removed = new Set();
      if (!/</.test(html)) return { html: '<p>' + window.KolText.escText(html.trim()).replace(/\r?\n/g, '<br>') + '</p>', removed: [] };
      const d = new DOMParser().parseFromString('<!doctype html><body>' + html, 'text/html');
      for (const e of d.body.querySelectorAll('script, iframe, object, embed, frame, frameset, base, meta, link, style, noscript, template')) { removed.add('<' + e.localName + '>'); e.remove(); }
      for (const e of d.body.querySelectorAll('*')) for (const a of [...e.attributes]) {
        const v = a.value.replace(/[\s\u0000-\u001f]+/g, '').toLowerCase();
        if (/^on/i.test(a.name)) { removed.add(a.name); e.removeAttribute(a.name); }
        else if (/^(href|src|action|formaction|xlink:href|poster|data|srcset)$/i.test(a.name) && /^(javascript|vbscript|data:text\/html)/.test(v)) { removed.add(a.name + '="' + v.slice(0, 12) + '…"'); e.removeAttribute(a.name); }
      }
      // T2 (V13 🟡B): <animate> / <set> של SVG שמכוונים לתכונת כתובת (href, xlink:href…) — הערך ב-to / from / by / values (רשימה ב-;)
      // הופך לכתובת אחרי הטעינה, ולכן הבדיקה למעלה אינה תופסת אותו. כתובת מסוכנת — האלמנט כולו יורד
      const BAD = v => /^(javascript|vbscript|data:text\/html)/.test(v.replace(/[\s\u0000-\u001f]+/g, '').toLowerCase());
      for (const e of [...d.body.querySelectorAll('*')].filter(x => /^(animate|set|animatemotion|animatetransform)$/i.test(x.localName))) {
        const an = (e.getAttribute('attributeName') || e.getAttribute('attributename') || '').trim().toLowerCase();
        if (!/^(xlink:)?(href|src|action|formaction|poster|data)$/.test(an)) continue;
        if (['to', 'from', 'by', 'values'].some(k => (e.getAttribute(k) || '').split(';').some(BAD))) { removed.add('<' + e.localName + ' attributeName="' + an + '">'); e.remove(); }
      }
      return { html: d.body.innerHTML.trim(), removed: [...removed] };
    }

    // ── מקלדת ו"העבר אל…" ───────────────────────────────────
    /** Alt + ↑ ↓ (בשורה: ← →, לפי הסדר הנראה) — בין אחים; Alt + Shift — מוציא מהרובריקה / מכניס לשכנה. */
    key(k, shift) {
      const c = this.canvas, n = c.selected; if (!n || !n.parent || this.X.ROOTS.has(n.tag)) return false;
      const par = n.parent, ax = this.drag.axis(par), sibs = par.kids, i = sibs.indexOf(n);
      const fwd = ax.axis === 'row' ? (k === (ax.rev ? 'ArrowLeft' : 'ArrowRight')) : k === 'ArrowDown';
      const back = ax.axis === 'row' ? (k === (ax.rev ? 'ArrowRight' : 'ArrowLeft')) : k === 'ArrowUp';
      if (!fwd && !back) return false;
      if (!shift) {
        if (back && i === 0) { this.note('האלמנט הראשון ברובריקה — Alt + Shift מוציא אותו ממנה'); return true; }
        if (fwd && i === sibs.length - 1) { this.note('האלמנט האחרון ברובריקה — Alt + Shift מוציא אותו ממנה'); return true; }
        return this.moveTo(n, par, back ? sibs[i - 1] : sibs[i + 2] || null, back ? 'למעלה' : 'למטה');
      }
      if (back) {                                                           // Shift + אחורה: מוציא — לפני הרובריקה
        if (!par.parent || this.X.ROOTS.has(par.tag)) { this.note('האלמנט כבר ברמה העליונה'); return true; }
        return this.moveTo(n, par.parent, par, 'הוצא מ-' + c.label(par));
      }
      const prev = sibs[i - 1];                                             // Shift + קדימה: מכניס לרובריקה שלפניו (בסופה)
      if (!prev || !this.drag.isBox(prev)) { this.note('אין רובריקה צמודה לפניו להכניס אליה'); return true; }
      return this.moveTo(n, prev, null, 'הכנס ל-' + c.label(prev));
    }
    moveTo(n, par, ref, how) {
      const K = this.K, P = K.pathOf(n), PP = K.pathOf(par), RP = ref ? K.pathOf(ref) : null, out = K.outer(this.canvas.m, n);
      try { this.X.move(this.canvas.m, [n], par, ref); } catch (e) { this.reject(e, par, [n], ref); return true; }
      if (this.act('הזזה · ' + this.canvas.label(n) + ' · ' + how, m => this.X.move(m, [K.byPath(m, P)], K.byPath(m, PP), RP ? K.byPath(m, RP) : null), n)) this.reselect([out]);
      return true;
    }
    /** "העבר אל…": בחירת יעד בעץ — כל יעד שהגרירה מגיעה אליו, גם מכאן (גם בטלפון). */
    moveSheet(n) {
      const c = this.canvas, K = this.K; n = n || c.selected; if (!n) return;
      const dlg = document.getElementById('sheet'), list = h('div', { class: 'ed-moveto', role: 'tree', 'aria-label': 'יעד' });
      const body = this.body(), rows = [];
      const go = (x, d) => {
        if (x === n || x.tag === 'head') return;
        if (this.drag.isBox(x) && !this.X.why(x, [n.tag], [n])) rows.push(h('button', { type: 'button', class: 'btn sm ed-moveto-row', 'data-moveto': K.pathOf(x).join('.'), style: 'margin-inline-start:' + Math.min(d, 12) * 10 + 'px', onclick: () => { dlg.close(); this.moveTo(n, x, null, 'אל ' + c.label(x)); } }, '⤵ בסוף ' + c.label(x)));
        if (x.parent && x !== body && !this.X.why(x.parent, [n.tag], [n])) rows.push(h('button', { type: 'button', class: 'btn sm ed-moveto-row', 'data-moveto-before': K.pathOf(x).join('.'), style: 'margin-inline-start:' + Math.min(d, 12) * 10 + 'px', onclick: () => { dlg.close(); this.moveTo(n, x.parent, x, 'לפני ' + c.label(x)); } }, '↱ לפני ' + c.label(x)));
        if (rows.length < 400) for (const k of x.kids) if (k !== n && !this.X.inTemplate(k) && k.tag !== 'template') go(k, d + 1);
      };
      if (body) go(body, 0);
      list.append(...rows);
      const par = n.parent, i = par ? par.kids.indexOf(n) : -1;
      this.sheet('העבר אל… · ' + c.label(n), h('div', { class: 'ed-menu' },
        h('button', { type: 'button', class: 'btn', 'data-mv': 'up', disabled: i <= 0 || null, onclick: () => { dlg.close(); this.moveTo(n, par, par.kids[i - 1], 'למעלה'); } }, '↑ למעלה'),
        h('button', { type: 'button', class: 'btn', 'data-mv': 'down', disabled: i < 0 || i >= par.kids.length - 1 || null, onclick: () => { dlg.close(); this.moveTo(n, par, par.kids[i + 2] || null, 'למטה'); } }, '↓ למטה'),
        h('button', { type: 'button', class: 'btn', 'data-mv': 'in', disabled: !(i > 0 && this.drag.isBox(par.kids[i - 1])) || null, onclick: () => { dlg.close(); this.moveTo(n, par.kids[i - 1], null, 'הכנס לרובריקה שלפני'); } }, '⤵ הכנס לרובריקה שלפני'),
        h('button', { type: 'button', class: 'btn', 'data-mv': 'out', disabled: !par || !par.parent || this.X.ROOTS.has(par.tag) || null, onclick: () => { dlg.close(); this.moveTo(n, par.parent, par, 'הוצא'); } }, '⤴ הוצא מהרובריקה')),
        h('p', { class: 'hint' }, 'או יעד בעץ:'), list);
    }
    /** תפריט ⋯ של הנבחר — פעולות המבנה. */
    menu(n) {
      const c = this.canvas, w = this.canWrite(), dlg = document.getElementById('sheet'), go = fn => () => { dlg.close(); fn(); };
      const item = (label, fn, off, attrs) => h('button', Object.assign({ type: 'button', class: 'btn ed-menu-item', disabled: !!off || null, onclick: go(fn) }, attrs || {}), label);
      const root = this.X.ROOTS.has(n.tag);
      this.sheet(c.label(n), h('div', { class: 'ed-menu', role: 'menu' },
        item('⧉ שכפול (Ctrl+D)', () => this.duplicate(), !w || root),
        item('▭ עטוף ברובריקה', () => this.wrap(), !w || root, { 'data-wrap': true }),
        item('⊟ פרק רובריקה', () => this.unwrap(), !w || root || !n.et || !n.kids.length, { 'data-unwrap': true }),
        item('↕ העבר אל…', () => this.moveSheet(n), !w || root, { 'data-moveto-open': true }),
        item('📋 העתק (Ctrl+C)', () => this.copy(false), root),
        item('✂️ גזור (Ctrl+X)', () => this.copy(true), !w || root),
        item('📥 הדבק אחרי (Ctrl+V)', () => this.paste(null, 'after'), !w || !this.clip),
        this.drag.isBox(n) ? item('📥 הדבק בתוכה', () => this.paste(null, 'in'), !w || !this.clip) : null,
        item('＋ הוסף לפני', () => this.chooser(n.parent, n), !w || !n.parent || root),
        item('＋ הוסף אחרי', () => this.chooser(n.parent, n.parent ? n.parent.kids[n.parent.kids.indexOf(n) + 1] || null : null), !w || !n.parent || root),
        this.drag.isBox(n) ? item('＋ הוסף בתוכה', () => this.chooser(n, null), !w) : null,
        item('🗑️ מחיקה', () => this.remove(), !w)));
    }

    // ── מחיקה עם אזהרה ─────────────────────────────────────
    /** "אין אלמנט חובה": כל דבר נמחק; כשקוד נשען עליו — אזהרה עם "בצע בכל זאת" ו"בטל". */
    remove(cut) {
      const c = this.canvas, ns = this.X.order(c.picked()); if (!ns.length) return this.note('בוחרים אלמנט, ואז מוחקים');
      const d = this.X.deps(c.m, ns, this.jsTexts());
      if (d.length) return this.confirm(cut ? 'גזירה' : 'מחיקה', d.map(x => x.text)).then(ok => ok && this.doRemove(ns.map(n => this.K.pathOf(n)), cut));
      return this.doRemove(ns.map(n => this.K.pathOf(n)), cut);
    }
    doRemove(paths, cut) {
      const ns = paths.map(p => this.K.byPath(this.canvas.m, p)).filter(Boolean); if (!ns.length) return false;
      if (ns.length === 1) return this.removeOne(ns[0], cut);
      const rev = paths.slice().reverse();
      return this.act((cut ? 'גזירה · ' : 'מחיקה · ') + ns.length + ' אלמנטים', m => { let cur = m; for (const p of rev) { const r = this.K.html.remove(cur, this.K.byPath(cur, p)); cur = this.K.parseHtml(this.K.applyPatches(cur.src, r.patches)); } return { patches: this.X.diff(m.src, cur.src) }; }, ns[0]);
    }

    // ── עזר ──────────────────────────────────────────────────
    confirm(title, lines) { return this.ask(title, lines, [['go', 'בצע בכל זאת'], ['cancel', 'בטל']]).then(v => v === 'go'); }
    ask(title, text, choices) {
      return new Promise(done => {
        const dlg = document.getElementById('sheet'); let settled = false;
        const fin = v => { if (settled) return; settled = true; dlg.removeEventListener('close', onClose); if (dlg.open) dlg.close(); done(v); };
        const onClose = () => fin(null);
        const lines = Array.isArray(text) ? text : [text];
        this.sheet(title, h('div', { class: 'ed-ask', 'data-ask': title }, lines.map(l => h('p', { class: 'ed-warn' }, '⚠️ ' + l)), h('div', { class: 'ed-ask-bar' }, choices.map(([v, l], i) => h('button', { type: 'button', class: 'btn' + (i === 0 ? ' primary' : ''), 'data-choice': v, onclick: () => fin(v) }, l)))));
        dlg.addEventListener('close', onClose);
      });
    }
  }
  window.KolBuild = { Build };
})();
