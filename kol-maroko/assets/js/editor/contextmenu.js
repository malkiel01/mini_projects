/**
 * קול מרוקו — העורך החזותי, פרוסה V13: תפריט ההקשר (01-gap 1.7) ורשימת הפעולות המשותפת לו, לקיצורים ול-Ctrl+E.
 *
 * לחיצה ימנית (במגע — לחיצה ארוכה) על אלמנט בקנבס או על שורה ב-Navigator ← תפריט צף ליד הסמן (בטלפון — מלמטה). כל פעולה
 * עם הקיצור שלה; פעולה שאינה אפשרית — מושבתת, והסיבה בריחוף (ובהקשה — בהודעה). כל פעולה עוברת דרך אותן פונקציות של
 * העורך (act / writeStyle / writeOps), ולכן נרשמת בהיסטוריה בשמה.
 * כל טקסט מהעמוד — כטקסט בלבד (h / textContent); אין innerHTML.
 */
(function (g) {
  'use strict';
  const ROOTS = /^(html|head|body)$/;

  class Actions {
    /** opt: { S, K, X, canvas(), build(), sc(), comp(), tpl(), canWrite(), readOnly(), note, undo, openInCode, report, panel, setTab, select, editText, save, live, palette, help, hist } */
    constructor(opt) { Object.assign(this, opt); }
    c() { return this.canvas(); }
    /** כל הפעולות על אלמנט: { id, icon, label, keys, run(n), why(n) — '' כשאפשר }. */
    list() {
      const c = () => this.c(), B = () => this.build(), w = () => (this.canWrite() ? '' : this.readOnly() || 'הקובץ פתוח לקריאה בלבד');
      const root = n => (ROOTS.test(n.tag) ? 'את <' + n.tag + '> אי אפשר' : '');
      const tpl = n => (B() && B().X.inTemplate && B().X.inTemplate(n) ? 'האלמנט בתוך <template> — עורכים את התבנית בלשונית "נתונים"' : '');
      const clip = () => (B() && B().clip ? '' : 'הלוח של העורך ריק — מעתיקים אלמנט קודם (Ctrl+C)');
      const box = n => (B() && B().drag.isBox(n) && n.tag !== 'template' ? '' : 'האלמנט אינו רובריקה — אין בו מקום להדביק לתוכו');
      const any = (...fs) => n => { for (const f of fs) { const r = f(n); if (r) return r; } return ''; };
      const sib = (n, d) => { const p = n.parent; if (!p) return null; const i = p.kids.indexOf(n); return p.kids[i + d] || null; };
      return [
        { id: 'copy', icon: '📋', label: 'העתק', keys: 'Ctrl+C', why: any(root), run: n => { c().select(n); B().copy(false); } },
        { id: 'paste-before', icon: '📥', label: 'הדבק לפני', keys: '', why: any(w, root, clip), run: n => this.pasteAt(n, 'before') },
        { id: 'paste-after', icon: '📥', label: 'הדבק אחרי', keys: 'Ctrl+V', why: any(w, root, clip), run: n => this.pasteAt(n, 'after') },
        { id: 'paste-in', icon: '📥', label: 'הדבק בתוך', keys: '', why: any(w, clip, box), run: n => this.pasteAt(n, 'in') },
        { id: 'duplicate', icon: '⧉', label: 'שכפל', keys: 'Ctrl+D', why: any(w, root), run: n => { c().select(n); B().duplicate(); } },
        { id: 'delete', icon: '🗑️', label: 'מחק', keys: 'Delete', why: any(w, n => (n.tag === 'html' ? 'את <html> אי אפשר למחוק' : '')), run: n => { c().select(n); B().remove(); } },
        { id: 'wrap-col', icon: '▭', label: 'עטוף ברובריקה ↕ (עמודה)', keys: '', why: any(w, root, tpl), run: n => { c().select(n); B().wrap(); } },
        { id: 'wrap-row', icon: '▭', label: 'עטוף ברובריקה ↔ (שורה)', keys: '', why: any(w, root, tpl), run: n => this.wrapRow(n) },
        { id: 'move-out', icon: '⤴', label: 'הוצא מהרובריקה', keys: 'Alt+Shift+↑', why: any(w, root, n => (!n.parent || !n.parent.parent || ROOTS.test(n.parent.tag) ? 'האלמנט כבר ברמה העליונה של העמוד' : '')), run: n => B().moveTo(n, n.parent.parent, n.parent, 'הוצא מ-' + c().label(n.parent)) },
        { id: 'parent', icon: '↑', label: 'בחר הורה', keys: 'Esc', why: n => (n.parent ? '' : 'לאלמנט אין הורה'), run: n => this.select(n.parent) },
        { id: 'similar', icon: '⁂', label: 'בחר את כל הדומים (אותה מחלקה)', keys: '', why: n => (this.K.classesOf(n).length ? (this.similar(n).length > 1 ? '' : 'אין עוד אלמנט עם ' + (this.K.classesOf(n).length > 1 ? 'אחת המחלקות שלו' : 'המחלקה .' + this.K.classesOf(n)[0])) : 'לאלמנט אין מחלקה'), run: n => this.selectSimilar(n) },
        { id: 'copy-style', icon: '🎨', label: 'העתק סגנון', keys: 'Ctrl+Shift+C', why: any(root), run: n => this.sc().copy(n) },
        { id: 'paste-style', icon: '🖌', label: 'הדבק סגנון', keys: 'Ctrl+Shift+V', why: any(w, n => (n.tag === 'html' || n.tag === 'head' ? 'ל-<' + n.tag + '> אין מראה' : ''), () => (this.sc().clip ? '' : 'אין סגנון בלוח — "העתק סגנון" קודם')), run: n => { if (!c().picked().includes(n)) c().select(n); this.sc().paste(); } },
        { id: 'reset-style', icon: '⟲', label: 'אפס סגנון', keys: '', why: any(w, n => (n.tag === 'html' || n.tag === 'head' ? 'ל-<' + n.tag + '> אין מראה' : '')), run: n => this.sc().reset(n) },
        { id: 'save-component', icon: '🧩', label: 'שמור כרכיב', keys: '', why: any(w, root, tpl, n => (this.comp() && this.comp().stateOf && this.comp().stateOf(n) ? 'האלמנט כבר מופע של רכיב' : ''), () => (/^components\//.test(this.S.file?.path || '') ? 'זו תבנית של רכיב — רכיב בתוך רכיב נשמר מעמוד' : '')), run: n => this.comp().create(n) },
        { id: 'save-template', icon: '📄', label: 'שמור כתבנית', keys: '', why: any(w, n => (n.tag === 'head' ? 'ל-<head> אין מראה' : ''), tpl), run: n => this.tpl().save(n) },
        { id: 'open-code', icon: '</>', label: 'פתח בקוד', keys: 'C', why: () => '', run: n => this.openInCode(n) },
        { id: 'edit-text', icon: '✏️', label: 'ערוך טקסט', keys: 'Enter', why: any(w, root), run: n => this.editText(n) },
        { id: 'move-to', icon: '↕', label: 'העבר אל…', keys: 'Alt+↑ ↓', why: any(w, root), run: n => B().moveSheet(n) },
        { id: 'prev', icon: '→', label: 'האח הקודם', keys: '→', why: n => (sib(n, -1) ? '' : 'זה האלמנט הראשון ברובריקה'), run: n => this.select(sib(n, -1)), hidden: true },
        { id: 'next', icon: '←', label: 'האח הבא', keys: '←', why: n => (sib(n, 1) ? '' : 'זה האלמנט האחרון ברובריקה'), run: n => this.select(sib(n, 1)), hidden: true },
        { id: 'bug', icon: '🐞', label: 'לא הצלחתי לשנות את זה', keys: 'B', why: () => '', run: n => this.report(n) },
      ];
    }
    /** פעולות שאינן על אלמנט (לחיפוש הפעולה ולקיצורים). */
    global() {
      return [
        { id: 'undo', icon: '↶', label: 'בטל', keys: 'Ctrl+Z', run: () => this.undo() },
        { id: 'redo', icon: '↷', label: 'חזור', keys: 'Ctrl+Y · Ctrl+Shift+Z', run: () => this.redo() },
        { id: 'save', icon: '💾', label: 'שמור', keys: 'Ctrl+S', run: () => this.save() },
        { id: 'live', icon: '▶️', label: 'תצוגה חיה', keys: 'Ctrl+P', run: () => this.live() },
        { id: 'history', icon: '🕘', label: 'היסטוריה', keys: '', run: () => this.hist() },
        { id: 'templates', icon: '📄', label: 'תבניות — הוספה מתבנית', keys: '', run: () => this.tpls() },
        { id: 'add-heading', icon: 'H', label: 'הוסף כותרת', keys: '', run: () => this.addLib('heading') },
        { id: 'add-text', icon: '¶', label: 'הוסף טקסט', keys: '', run: () => this.addLib('text') },
        { id: 'add-image', icon: '🖼️', label: 'הוסף תמונה', keys: '', run: () => this.addImage() },
        { id: 'add-button', icon: '🔘', label: 'הוסף כפתור', keys: '', run: () => this.addLib('button') },
        { id: 'add-box', icon: '▭', label: 'הוסף רובריקה', keys: '', run: () => this.addLib('box') },
        { id: 'navigator', icon: '🌳', label: 'Navigator — עץ האלמנטים', keys: 'N', run: () => this.panel('nav', true) },
        { id: 'split', icon: '◫', label: 'מבט מפוצל — קוד לצד הקנבס', keys: 'S', run: () => this.split() },
        { id: 'keys', icon: '?', label: 'רשימת הקיצורים', keys: '?', run: () => this.help() },
        { id: 'page-template', icon: '📄', label: 'שמור את העמוד כתבנית', keys: '', run: () => this.tpl().save(null) },
      ];
    }
    /** הדבקה לפני / אחרי / בתוך — "לפני" אינה בתפריט של המבנה, ולכן כאן (אותו X.paste, אותה עסקה). */
    pasteAt(n, where) {
      const B = this.build(), c = this.c(); c.select(n);
      if (where !== 'before') return B.paste(null, where);
      const K = this.K, html = B.clip.html, par = n.parent, idx = par.kids.indexOf(n), P = K.pathOf(par);
      const ok = B.run('הדבקה · ' + (B.X.topTags(html).join(', ') || 'קטע') + ' · לפני ' + c.label(n), m => B.X.paste(m, K.byPath(m, P), idx, html), par, { retry: (m, p) => B.X.paste(m, p, idx, html) });
      if (ok) { const fresh = K.byPath(c.m, P.concat(idx)); if (fresh) { c.select(fresh); c.reveal(fresh); } this.note('הודבק לפני ' + c.label(n), false, { label: 'בטל', run: this.undo }); }
    }
    async wrapRow(n) {
      const B = this.build(), c = this.c(), n0 = this.S.tx.length; c.select(n);
      await B.wrap();
      if (this.S.tx.length === n0) return;
      const box = c.selected; if (!box) return;
      await B.setDir(box, 'row');
      const k = this.S.tx.length - n0; if (k > 1) this.joinTx(k, 'עטיפה ברובריקה ↔ · ' + c.label(n));
    }
    /** "הדומים": המחלקה של האלמנט שמשותפת להכי הרבה אלמנטים (אחת מהן — כשיש כמה). */
    simClass(n) {
      const c = this.c(), count = new Map(); if (!c.m || !c.m.root) return null;
      this.K.walk(c.m.root, x => { if (!x.implied && c.elOf(x)) for (const k of this.K.classesOf(x)) count.set(k, (count.get(k) || 0) + 1); });
      return this.K.classesOf(n).filter(k => !/^(kol-|is-in$)/.test(k)).sort((a, b) => (count.get(b) || 0) - (count.get(a) || 0))[0] || null;
    }
    similar(n) { const cls = this.simClass(n); if (!cls) return []; const c = this.c(), out = []; this.K.walk(c.m.root, x => { if (!x.implied && this.K.classesOf(x).includes(cls) && c.elOf(x)) out.push(x); }); return out; }
    selectSimilar(n) {
      const c = this.c(), all = this.similar(n), cls = this.simClass(n);
      c.select(n); for (const x of all) if (x !== n) c.toggleMulti(x);
      this.note('נבחרו ' + all.length + ' אלמנטים עם המחלקה .' + cls + ' — שינוי עיצוב חל על כולם');
    }
    /** מריצים פעולה — הסיבה, כשאינה אפשרית. */
    exec(a, n) {
      const why = n && a.why ? a.why(n) : '';
      if (why) return this.note('⛔ ' + a.label + ': ' + why, true);
      this.recent?.(a.id);
      return a.run(n);
    }
  }

  /** התפריט הצף. */
  class Menu {
    constructor(actions, opt) { this.A = actions; Object.assign(this, opt); this.el = null; }
    close() { if (!this.el) return; this.el.remove(); this.el = null; document.removeEventListener('pointerdown', this.off, true); document.removeEventListener('keydown', this.key, true); this.c()?.doc?.removeEventListener('pointerdown', this.off, true); this.back?.focus?.(); }
    open(n, x, y, from) {
      this.close(); const c = this.c(); if (!c || !n) return;
      if (!c.picked().includes(n)) c.select(n);
      this.back = document.activeElement;
      const items = this.A.list().filter(a => !a.hidden), groups = [['copy', 'paste-before', 'paste-after', 'paste-in', 'duplicate', 'delete'], ['wrap-col', 'wrap-row', 'move-out', 'move-to'], ['parent', 'similar'], ['copy-style', 'paste-style', 'reset-style'], ['save-component', 'save-template'], ['edit-text', 'open-code', 'bug', /* V15 */ 'page-settings']];
      const btn = a => { const why = a.why ? a.why(n) : ''; const b = h('button', { type: 'button', role: 'menuitem', class: 'btn ed-menu-item ed-ctx-item', 'data-ctx': a.id, 'aria-disabled': why ? 'true' : null, title: why || (a.keys ? a.label + ' · ' + a.keys : a.label) },
        h('span', { class: 'ed-ctx-ic', 'aria-hidden': 'true' }, a.icon), h('span', { class: 'ed-ctx-lab' }, a.label), a.keys ? h('kbd', { class: 'ed-ctx-key', dir: 'ltr' }, a.keys.replace(/Ctrl/g, this.mac ? '⌘' : 'Ctrl')) : null, why ? h('span', { class: 'ed-ctx-why', 'data-why': a.id }, why) : null);
        b.addEventListener('click', () => { if (why) { this.A.note('⛔ ' + a.label + ': ' + why, true); return; } this.close(); this.A.exec(a, n); }); return b; };
      const body = groups.map(gr => h('div', { class: 'ed-ctx-g', role: 'group' }, gr.map(id => items.find(a => a.id === id)).filter(Boolean).map(btn)));
      const many = c.picked().length;
      this.el = h('div', { class: 'ed-ctx ed-menu', role: 'menu', 'aria-label': 'פעולות · ' + c.label(n), 'data-ctx-from': from || 'canvas' }, h('div', { class: 'ed-ctx-head' }, h('b', {}, c.label(n)), many > 1 ? h('span', { class: 'hint' }, ' · ' + many + ' נבחרים') : null, h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', 'data-ctx-close': true, onclick: () => this.close() }, '✕')), ...body);
      document.body.append(this.el);
      const r = this.el.getBoundingClientRect(), W = innerWidth, H = innerHeight;
      if (!this.phone()) { const left = Math.max(8, Math.min(x == null ? W / 2 - r.width / 2 : x, W - r.width - 8)), top = Math.max(8, Math.min(y == null ? H / 3 : y, H - r.height - 8)); this.el.style.left = left + 'px'; this.el.style.top = top + 'px'; }
      this.off = e => { if (this.el && !this.el.contains(e.target)) this.close(); };
      this.key = e => {
        if (!this.el) return;
        const list = [...this.el.querySelectorAll('.ed-ctx-item')], i = list.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(); }
        else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); e.stopPropagation(); const j = (i + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length; list[j].focus(); }
        else if (e.key === 'Tab') { e.stopPropagation(); }
      };
      setTimeout(() => { if (!this.el) return; document.addEventListener('pointerdown', this.off, true); c.doc?.addEventListener('pointerdown', this.off, true); }, 0);
      document.addEventListener('keydown', this.key, true);
      (this.el.querySelector('.ed-ctx-item:not([aria-disabled])') || this.el.querySelector('.ed-ctx-item')).focus();
      return this.el;
    }
  }

  g.KolCtx = { Actions, Menu };
})(typeof window !== 'undefined' ? window : globalThis);
