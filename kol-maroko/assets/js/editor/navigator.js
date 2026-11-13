/**
 * העורך החזותי — Navigator (03 §11): עץ כל האלמנטים של הקובץ, כולל <head>, אלמנטים מוסתרים ותוכן של <template>.
 *
 * הרשימה שטוחה ווירטואלית: רק השורות שבחלון הגלילה קיימות ב-DOM (24,000 צמתים נגללים בלי תקיעה). אין כאן מצב משלו
 * על הקובץ: הצמתים הם של המודל (KolSrc), הבחירה היא של הקנבס. 👁 הסתרה ו-🔒 נעילה — בעורך בלבד; הקובץ אינו משתנה.
 * כל שם, מחלקה וטקסט מהעמוד נכתבים כטקסט בלבד (textContent) — אין בקובץ הזה innerHTML.
 */
(function () {
  'use strict';
  const ROW = 30, PAD = 8;
  const mk = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

  class Navigator {
    /** opt: { canvas, K, onPick(n), onToggle() } */
    constructor(host, opt) {
      Object.assign(this, opt);
      this.host = host; this.rows = []; this.index = new Map(); this.collapsed = new WeakSet(); this.seen = new WeakSet(); this.query = ''; this.uid = 'nv' + Math.random().toString(36).slice(2, 7);
      this.search = mk('input', 'nv-search'); this.search.type = 'search'; this.search.placeholder = 'חיפוש: תגית, .מחלקה, #מזהה או טקסט';
      this.search.setAttribute('aria-label', 'חיפוש אלמנט'); this.search.setAttribute('enterkeyhint', 'search');
      this.count = mk('span', 'nv-count'); this.count.setAttribute('role', 'status');
      this.list = mk('div', 'nv-list'); this.list.tabIndex = 0; this.list.dir = 'ltr';
      this.list.setAttribute('role', 'tree'); this.list.setAttribute('aria-label', 'עץ האלמנטים');
      this.space = mk('div', 'nv-space'); this.list.append(this.space);
      const head = mk('div', 'nv-head'); head.append(this.search, this.count);
      host.replaceChildren(head, this.list);
      let t = 0;
      this.search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { this.query = this.search.value.trim().toLowerCase(); this.list.scrollTop = 0; this.refresh(); }, 120); });
      this.search.addEventListener('keydown', e => { if (e.key === 'ArrowDown' || e.key === 'Enter') { e.preventDefault(); this.list.focus(); if (this.rows[0] && !this.index.has(this.canvas.selected)) this.pick(this.rows[0].n); } });
      this.list.addEventListener('scroll', () => this.draw(), { passive: true });
      this.list.addEventListener('click', e => this.onClick(e));
      // V13: תפריט ההקשר על שורה — לחיצה ימנית, או לחיצה ארוכה במגע
      const rowAt = e => { const el = e.target.closest && e.target.closest('.nv-row'); return el ? this.rows[+el.dataset.i] : null; };
      this.list.addEventListener('contextmenu', e => { const r = rowAt(e); if (!r || !this.onContext) return; e.preventDefault(); clearTimeout(this.longT); if (this.longDone) { this.longDone = false; return; } this.onContext(r.n, e.clientX, e.clientY); });
      this.list.addEventListener('pointerdown', e => { clearTimeout(this.longT); this.longDone = false; if (e.pointerType !== 'touch') return; const r = rowAt(e), x = e.clientX, y = e.clientY; if (r) this.longT = setTimeout(() => { if (this.onContext) { this.longDone = true; this.onContext(r.n, x, y); } }, 550); });
      for (const ev of ['pointerup', 'pointercancel', 'scroll']) this.list.addEventListener(ev, () => clearTimeout(this.longT), { passive: true });
      this.list.addEventListener('keydown', e => this.onKeys(e));
      new ResizeObserver(() => this.draw()).observe(this.list);
    }
    textOf(n) { let s = ''; for (const x of n.nodes) if (x.type === 'text') { s += x.value; if (s.length > 200) break; } return s.replace(/\s+/g, ' ').trim(); }
    name(n) { const id = this.K.attrOf(n, 'id'), c = this.K.classesOf(n)[0]; return n.tag + (id ? '#' + id.value : c ? '.' + c : ''); }
    match(n, q) {
      if (q[0] === '.') return this.K.classesOf(n).some(c => c.toLowerCase().includes(q.slice(1)));
      if (q[0] === '#') { const id = this.K.attrOf(n, 'id'); return !!id && id.value.toLowerCase().includes(q.slice(1)); }
      if (n.tag === q || this.name(n).toLowerCase().includes(q)) return true;
      const cls = this.K.attrOf(n, 'class');
      return (!!cls && cls.value.toLowerCase().includes(q)) || this.textOf(n).toLowerCase().includes(q);
    }
    /** בונה מחדש את רשימת השורות הנראות (אחרי שינוי במודל, קיפול או חיפוש). */
    refresh() {
      const m = this.canvas.m, rows = this.rows = [], q = this.query;
      this.index = new Map();
      if (m && m.root) {
        if (!this.seen.has(m.root)) { this.seen.add(m.root); for (const k of m.root.kids) if (k.tag === 'head') this.collapsed.add(k); }   // ראש העמוד — מקופל בפתיחה
        if (q) this.K.walk(m.root, n => { if (this.match(n, q)) rows.push({ n, depth: 0 }); });
        else { const go = (n, depth) => { rows.push({ n, depth }); if (!this.collapsed.has(n)) for (const k of n.kids) go(k, depth + 1); }; go(m.root, 0); }
      }
      rows.forEach((r, i) => this.index.set(r.n, i));
      this.space.style.height = rows.length * ROW + 'px';
      this.count.textContent = q ? rows.length + ' תוצאות' : (m ? m.count + ' אלמנטים' : '');
      this.draw();
    }
    /** מצייר רק את השורות שבחלון הגלילה. */
    draw() {
      const top = this.list.scrollTop, H = this.list.clientHeight || 400, first = Math.max(0, Math.floor(top / ROW) - 6), last = Math.min(this.rows.length, Math.ceil((top + H) / ROW) + 6);
      const sel = this.canvas.selected, out = [];
      for (let i = first; i < last; i++) out.push(this.row(this.rows[i], i, sel));
      this.space.replaceChildren(...out);
      const si = sel ? this.index.get(sel) : undefined;
      if (si != null && si >= first && si < last) this.list.setAttribute('aria-activedescendant', this.uid + '-' + si); else this.list.removeAttribute('aria-activedescendant');
    }
    row(r, i, sel) {
      const n = r.n, c = this.canvas, e = mk('div', 'nv-row' + (n === sel ? ' sel' : '') + (c.hiddenNodes.has(n) ? ' off' : '') + (c.devHidden && c.devHidden.has(n) ? ' devoff' : '') + (n.broken ? ' broken' : ''));
      e.id = this.uid + '-' + i; e.dataset.i = i; e.style.top = i * ROW + 'px'; e.style.paddingLeft = PAD + Math.min(r.depth, 24) * 12 + 'px';
      e.setAttribute('role', 'treeitem'); e.setAttribute('aria-level', r.depth + 1); e.setAttribute('aria-selected', String(n === sel));
      const open = !this.collapsed.has(n), tw = mk('button', 'nv-tw', n.kids.length && !this.query ? (open ? '▾' : '▸') : '');
      tw.type = 'button'; tw.tabIndex = -1; tw.dataset.act = 'fold';
      if (n.kids.length && !this.query) { e.setAttribute('aria-expanded', String(open)); tw.setAttribute('aria-label', open ? 'קיפול' : 'פתיחה'); } else tw.disabled = true;
      const lab = mk('span', 'nv-lab'); lab.append(mk('b', '', this.name(n)));
      const txt = this.textOf(n);
      const flags = (n.tag === 'head' ? ' ראש העמוד' : '') + (n.tag === 'template' ? ' תבנית' : '') + (n.implied ? ' · לא במקור' : '') + (n.broken ? ' · קוד שבור' : '');
      if (flags) lab.append(mk('i', '', flags));
      // V7: מוסתר במכשיר הנוכחי · סדר נראה (order) לצד סדר המקור
      if (c.devHidden && c.devHidden.has(n)) { const x = mk('i', 'nv-devhide', ' 🚫 מוסתר במכשיר הזה'); x.dataset.devHidden = '1'; lab.append(x); }
      const ord = this.order ? this.order(n) : null; if (ord) { const x = mk('i', 'nv-order', ' ⇅ ' + ord); x.dataset.order = ord; lab.append(x); }
      const bd = this.badge ? this.badge(n) : null; if (bd) { const x = mk('i', 'nv-comp', ' ' + bd.icon); x.dataset.comp = bd.state; x.title = bd.title; lab.append(x); }   // V12: ◆ מקושר · ◇ שונה
      if (txt) lab.append(mk('span', 'nv-txt', ' ' + txt.slice(0, 40)));
      const eye = mk('button', 'nv-ic' + (c.hiddenNodes.has(n) ? ' on' : ''), '👁'); eye.type = 'button'; eye.tabIndex = -1; eye.dataset.act = 'hide';
      eye.setAttribute('aria-label', 'הסתרה בעורך בלבד'); eye.title = 'הסתרה בעורך בלבד — הקובץ אינו משתנה (H)'; eye.setAttribute('aria-pressed', String(c.hiddenNodes.has(n)));
      const lock = mk('button', 'nv-ic' + (c.lockedNodes.has(n) ? ' on' : ''), '🔒'); lock.type = 'button'; lock.tabIndex = -1; lock.dataset.act = 'lock';
      lock.setAttribute('aria-label', 'נעילה מפני בחירה בטעות'); lock.title = 'נעילה מפני בחירה בטעות בקנבס — בעורך בלבד (L)'; lock.setAttribute('aria-pressed', String(c.lockedNodes.has(n)));
      e.append(tw, lab, eye, lock);
      return e;
    }
    onClick(e) {
      const rowEl = e.target.closest('.nv-row'); if (!rowEl) return;
      const r = this.rows[+rowEl.dataset.i]; if (!r) return;
      const act = e.target.closest('[data-act]')?.dataset.act;
      if (act === 'fold') return this.fold(r.n);
      if (act === 'hide') return this.flag('hide', r.n);
      if (act === 'lock') return this.flag('lock', r.n);
      this.pick(r.n);
    }
    fold(n, open) {
      if (!n.kids.length || this.query) return;
      const want = open == null ? this.collapsed.has(n) : open;
      if (want) this.collapsed.delete(n); else this.collapsed.add(n);
      this.refresh();
    }
    flag(kind, n) {
      const c = this.canvas;
      if (kind === 'hide') c.setHidden(n, !c.hiddenNodes.has(n)); else c.setLocked(n, !c.lockedNodes.has(n));
      this.draw(); if (this.onToggle) this.onToggle();
    }
    pick(n) { this.onPick(n); }
    onKeys(e) {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      const sel = this.canvas.selected, i = sel ? this.index.get(sel) : undefined, k = e.key;
      const go = j => { const r = this.rows[Math.max(0, Math.min(this.rows.length - 1, j))]; if (r) this.pick(r.n); };
      const page = Math.max(1, Math.floor(this.list.clientHeight / ROW) - 1);
      const hit = fn => { e.preventDefault(); e.stopPropagation(); fn(); };
      if (k === 'ArrowDown') return hit(() => go(i == null ? 0 : i + 1));
      if (k === 'ArrowUp') return hit(() => go(i == null ? 0 : i - 1));
      if (k === 'PageDown') return hit(() => go((i || 0) + page));
      if (k === 'PageUp') return hit(() => go((i || 0) - page));
      if (k === 'Home') return hit(() => go(0));
      if (k === 'End') return hit(() => go(this.rows.length - 1));
      if (!sel) return;
      if (k === 'ArrowRight') return hit(() => { if (this.collapsed.has(sel)) this.fold(sel, true); else if (sel.kids[0]) this.pick(sel.kids[0]); });
      if (k === 'ArrowLeft') return hit(() => { if (sel.kids.length && !this.collapsed.has(sel) && !this.query) this.fold(sel, false); else if (sel.parent) this.pick(sel.parent); });
      if (k === 'h' || k === 'H' || k === 'י') return hit(() => this.flag('hide', sel));
      if (k === 'l' || k === 'L' || k === 'ך') return hit(() => this.flag('lock', sel));
    }
    /** הבחירה השתנתה (בקנבס או כאן): פותחים את האבות, וגוללים אל השורה. */
    sync() {
      const n = this.canvas.selected;
      if (n && !this.query) {
        let opened = false;
        for (let p = n.parent; p; p = p.parent) if (this.collapsed.has(p)) { this.collapsed.delete(p); opened = true; }
        if (opened || !this.index.has(n)) this.refresh();
      }
      this.scrollTo(n); this.draw();
    }
    scrollTo(n) {
      const i = n ? this.index.get(n) : undefined; if (i == null) return;
      const top = i * ROW, st = this.list.scrollTop, H = this.list.clientHeight;
      if (H && (top < st || top + ROW > st + H)) this.list.scrollTop = Math.max(0, top - H / 2 + ROW / 2);
    }
  }
  window.KolNavigator = { Navigator, ROW };
})();
