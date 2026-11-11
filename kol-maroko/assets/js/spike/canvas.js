/**
 * ספייק V0 — קנבס דומם (D3, שכבה ב).
 *
 * המסגרת היא sandbox="allow-same-origin" בלי allow-scripts: שום סקריפט של העמוד אינו רץ בה (גם לא onclick ולא
 * javascript:), אבל המעטפת — שהיא אותו מקור — מצמידה לה מאזינים וקוראת את ה-DOM שלה. מסגרות הבחירה והריחוף מצוירות
 * מעל המסגרת, במסמך של המעטפת; לקנבס עצמו נוסף רק <style data-kol-canvas> (ה-CSS של הפרויקט), שאינו חלק מהמיפוי.
 *
 * המיפוי אלמנט ↔ צומת במודל נעשה בהליכה מקבילה על שני העצים: הדפדפן ו-parse5 מממשים אותו אלגוריתם ניתוח, ולכן
 * הילד ה-i כאן הוא הילד ה-i שם. שום סימון אינו נכתב לקובץ.
 */
(function () {
  'use strict';
  const K = window.KolSrc;
  const OWNED = 'data-kol-canvas';
  const decode = bytes => new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(bytes);
  const encode = text => new TextEncoder().encode(text);
  const noBom = s => s.charCodeAt(0) === 0xFEFF ? s.slice(1) : s;
  /** הניתוח של הדפדפן עצמו, כשסקריפטים כבויים (DOMParser) — אמת המידה שמולה נבדק המודל. */
  const browserParse = src => new DOMParser().parseFromString(noBom(src), 'text/html');
  const kidsOf = d => Array.prototype.filter.call(d.localName === 'template' && d.content ? d.content.children : d.children, x => !x.hasAttribute(OWNED));

  function mapTrees(m, root) {
    const d2m = new Map(), m2d = new Map(), errors = [];
    let dom = 0;
    const go = (n, d) => {
      dom++;
      if (d.localName !== n.tag) errors.push({ path: K.pathOf(n).join('.'), why: 'תגית: ' + d.localName + ' ≠ ' + n.tag });
      d2m.set(d, n); m2d.set(n, d);
      const dk = kidsOf(d);
      if (dk.length !== n.kids.length) errors.push({ path: K.pathOf(n).join('.'), why: 'ילדים: ' + dk.length + ' בדפדפן, ' + n.kids.length + ' במודל (' + n.tag + ')' });
      for (let i = 0, c = Math.min(dk.length, n.kids.length); i < c; i++) go(n.kids[i], dk[i]);
    };
    if (m.root && root) go(m.root, root);
    return { d2m, m2d, errors, dom, model: m.count };
  }
  /** השוואה מלאה בין ה-DOM של הקנבס לניתוח טרי של הדפדפן: תגיות, תכונות וטקסט. מחזיר רשימת הבדלים (ריקה = זהה). */
  function sameDom(a, b, ownedOk) {
    const diffs = [];
    const text = d => { let s = ''; for (const c of d.childNodes) if (c.nodeType === 3) s += c.nodeValue; return s; };
    const go = (x, y, path) => {
      if (diffs.length > 20) return;
      if (x.localName !== y.localName) { diffs.push(path + ': ' + x.localName + ' ≠ ' + y.localName); return; }
      const xa = Array.prototype.filter.call(x.attributes, t => !(ownedOk && x.localName === 'link' && t.name === 'disabled'));
      if (xa.length !== y.attributes.length) diffs.push(path + ' <' + x.localName + '>: מספר התכונות ' + xa.length + ' ≠ ' + y.attributes.length);
      else for (const t of xa) if (y.getAttribute(t.name) !== t.value) diffs.push(path + ' <' + x.localName + '>: ' + t.name);
      if (text(x) !== text(y)) diffs.push(path + ' <' + x.localName + '>: טקסט ' + JSON.stringify(text(x).slice(0, 40)) + ' ≠ ' + JSON.stringify(text(y).slice(0, 40)));
      const xk = kidsOf(x), yk = kidsOf(y);
      if (xk.length !== yk.length) { diffs.push(path + ' <' + x.localName + '>: ילדים ' + xk.length + ' ≠ ' + yk.length); return; }
      for (let i = 0; i < xk.length; i++) go(xk[i], yk[i], path + '.' + i);
    };
    go(a, b, '');
    return diffs;
  }

  class Canvas {
    constructor(host) {
      this.host = host; this.selected = null; this.hovered = null; this.onSelect = null; this.onChange = null; this.onReject = null;
      this.history = []; this.timing = null; this.cssSrc = ''; this.cssM = null; this.styleEl = null;
      host.classList.add('spike-canvas');
      this.frame = document.createElement('iframe');
      this.frame.setAttribute('sandbox', 'allow-same-origin');             // בלי allow-scripts — זה כל העניין
      this.frame.setAttribute('title', 'קנבס');
      this.layer = document.createElement('div'); this.layer.className = 'spike-layer';
      this.boxSel = this.box('spike-box spike-sel'); this.boxHov = this.box('spike-box spike-hov'); this.boxDrop = this.box('spike-drop');
      host.replaceChildren(this.frame, this.layer);
    }
    box(cls) { const b = document.createElement('div'); b.className = cls; b.hidden = true; const l = document.createElement('span'); b.append(l); this.layer.append(b); return b; }
    get doc() { return this.frame.contentDocument; }

    /** טוען קובץ HTML (וטקסט CSS) לקנבס. מחזיר זמנים במילישניות. */
    async load(src, css) {
      const t0 = performance.now();
      this.src = src; this.m = K.parseHtml(src);
      const t1 = performance.now();
      if (css != null) { this.cssSrc = css; this.cssM = K.parseCss(css); }
      const t2 = performance.now();
      if (!this.ready || this.doctype !== this.m.doctype) {
        this.doctype = this.m.doctype;
        await new Promise(res => { this.frame.addEventListener('load', res, { once: true }); this.frame.srcdoc = (this.doctype ? '<!doctype html>' : '') + '<html><head></head><body></body></html>'; });
        this.ready = true; this.listen();
      }
      this.render();
      const t3 = performance.now();
      this.remap();
      const t4 = performance.now();
      void this.doc.documentElement.offsetHeight;                          // פריסה בפועל
      const t5 = performance.now();
      this.history = []; this.select(null);
      return (this.timing = { parseHtml: t1 - t0, parseCss: t2 - t1, render: t3 - t2, map: t4 - t3, layout: t5 - t4, total: t5 - t0 });
    }
    render() {
      const parsed = browserParse(this.src), doc = this.doc;
      // קובצי העיצוב של הפרויקט באים מהטקסט שבעורך, לא מהרשת
      for (const l of parsed.querySelectorAll('link[rel~="stylesheet" i]')) l.setAttribute('disabled', '');
      const root = doc.adoptNode(parsed.documentElement);
      if (doc.documentElement) doc.replaceChild(root, doc.documentElement); else doc.appendChild(root);
      this.styleEl = doc.createElement('style'); this.styleEl.setAttribute(OWNED, '');
      this.styleEl.textContent = noBom(this.cssSrc);
      const link = doc.querySelector('link[rel~="stylesheet" i]');
      if (link) link.after(this.styleEl); else (doc.head || root).append(this.styleEl);
    }
    remap() { const r = mapTrees(this.m, this.doc.documentElement); this.d2m = r.d2m; this.m2d = r.m2d; this.mapErrors = r.errors; this.mapDom = r.dom; }
    domOf(n) { return this.m2d.get(n) || null; }
    nodeOf(d) { for (; d; d = d.parentElement) { const n = this.d2m.get(d); if (n) return n; } return null; }

    /** מחיל פעולת HTML: fn(model) מחזירה {patches, muts}. זורק SrcError כשהפעולה נדחית — והקובץ אינו משתנה. */
    apply(fn) {
      const t0 = performance.now(), before = this.src, selPath = this.selected ? K.pathOf(this.selected) : null;
      const res = fn(this.m);
      const next = K.applyPatches(before, res.patches);
      const t1 = performance.now();
      const m2 = K.parseHtml(next);
      const t2 = performance.now();
      this.mutate(res.muts || [{ t: 'full' }], m2, next);
      const t3 = performance.now();
      this.remap();
      const t4 = performance.now();
      void this.doc.documentElement.offsetHeight;
      const t5 = performance.now();
      this.history.push({ kind: 'html', patches: res.patches, inverse: K.invertPatches(before, res.patches) });
      this.selected = selPath ? K.byPath(this.m, selPath) : null; this.draw();
      this.timing = { op: t1 - t0, reparse: t2 - t1, dom: t3 - t2, map: t4 - t3, layout: t5 - t4, total: t5 - t0 };
      if (this.onChange) this.onChange(res.patches, before);
      return { patches: res.patches, before, after: next, timing: this.timing };
    }
    mutate(muts, m2, next) {
      const doc = this.doc, plan = muts.map(mu => ({ mu, d: mu.node ? this.m2d.get(mu.node) : null, path: mu.node ? K.pathOf(mu.node) : null }));
      this.src = next; const old = this.m; this.m = m2;
      if (plan.some(p => p.mu.t === 'full' || !p.d)) return this.render();
      const frag = (parent, html) => { const r = doc.createRange(); r.selectNodeContents(parent.localName === 'template' ? doc.body : parent); return r.createContextualFragment(html.replace(/\r\n?/g, '\n')); };
      for (const { mu, d, path } of plan) {
        const par = d.parentNode;
        if (mu.t === 'attrs') {
          const n2 = K.byPath(m2, path);
          for (const a of Array.from(d.attributes)) if (!n2.attrs.some(x => x.name === a.name) && !(d.localName === 'link' && a.name === 'disabled')) d.removeAttribute(a.name);
          for (const a of n2.attrs) if (d.getAttribute(a.name) !== a.value) d.setAttribute(a.name, a.value);
        } else if (mu.t === 'inner') {
          const n2 = K.byPath(m2, path);
          if (!n2 || !n2.et) return this.render();
          d.innerHTML = next.slice(n2.st[1], n2.et[0]);
        } else if (mu.t === 'del') {
          const prev = d.previousSibling;
          if (mu.lead && prev && prev.nodeType === 3) prev.nodeValue = prev.nodeValue.replace(/[ \t\n\r\f]+$/, '');
          d.remove(); par.normalize();
        } else if (mu.t === 'before') {
          // בקובץ ההוספה נעשית לפני רווח ההזחה של d — וכך גם כאן: מפצלים את צומת הטקסט שלפניו בתחילת הרווח
          const t = d.previousSibling; let ref = d;
          if (mu.lead && t && t.nodeType === 3) ref = t.splitText(/[ \t\n\r\f]*$/.exec(t.nodeValue).index);
          par.insertBefore(frag(par, mu.html), ref); par.normalize();
        }
        else if (mu.t === 'after') { par.insertBefore(frag(par, mu.html), d.nextSibling); par.normalize(); }
        else if (mu.t === 'swap') { par.replaceChild(frag(par, mu.html), d); par.normalize(); }
      }
      void old;
    }
    applyCss(fn) {
      const t0 = performance.now(), before = this.cssSrc;
      const res = fn(this.cssM);
      const next = K.applyPatches(before, res.patches);
      const t1 = performance.now();
      this.cssSrc = next; this.cssM = K.parseCss(next);
      const t2 = performance.now();
      this.styleEl.textContent = noBom(next);
      void this.doc.documentElement.offsetHeight;
      const t3 = performance.now();
      this.history.push({ kind: 'css', patches: res.patches, inverse: K.invertPatches(before, res.patches) });
      this.timing = { op: t1 - t0, reparse: t2 - t1, dom: 0, map: 0, layout: t3 - t2, total: t3 - t0 };
      if (this.onChange) this.onChange(res.patches, before);
      return { patches: res.patches, before, after: next, timing: this.timing };
    }
    /** ביטול: התיקונים ההפוכים על הטקסט. */
    undo() {
      const h = this.history.pop();
      if (!h) return false;
      if (h.kind === 'css') { this.cssSrc = K.applyPatches(this.cssSrc, h.inverse); this.cssM = K.parseCss(this.cssSrc); this.styleEl.textContent = noBom(this.cssSrc); }
      else { this.src = K.applyPatches(this.src, h.inverse); this.m = K.parseHtml(this.src); this.render(); this.remap(); this.select(null); }
      return true;
    }

    // ── בחירה, ריחוף וגרירה ─────────────────────────────────
    listen() {
      const doc = this.doc, stop = e => { e.preventDefault(); e.stopPropagation(); };
      for (const ev of ['click', 'auxclick', 'dblclick', 'submit', 'dragstart', 'contextmenu']) doc.addEventListener(ev, stop, true);
      doc.addEventListener('pointerdown', e => {
        if (e.button > 0) return;
        const n = this.nodeOf(e.target);
        this.press = n ? { n, x: e.clientX, y: e.clientY, id: e.pointerId, drag: false, was: this.selected === n } : null;
        if (n) this.select(n);
        this.events.push('pointerdown:' + e.pointerType);
      }, true);
      doc.addEventListener('pointermove', e => {
        this.events.push('pointermove:' + e.pointerType);
        const p = this.press;
        if (p && p.id === e.pointerId) {
          if (!p.drag && Math.hypot(e.clientX - p.x, e.clientY - p.y) > 6) p.drag = true;
          if (p.drag) { p.drop = this.dropAt(p.n, e.clientX, e.clientY); this.drawDrop(p.drop); }
          return;
        }
        const n = this.nodeOf(e.target);
        if (n !== this.hovered) { this.hovered = n; this.draw(); }
      }, true);
      const up = e => {
        const p = this.press; this.press = null; this.boxDrop.hidden = true;
        this.events.push(e.type + ':' + e.pointerType);
        if (!p || !p.drag || e.type !== 'pointerup') return;
        const drop = this.dropAt(p.n, e.clientX, e.clientY);
        if (!drop) return;
        try { this.apply(m => K.html.move(m, p.n, drop.parent, drop.index)); this.select(K.byPath(this.m, K.pathOf(drop.parent).concat(drop.index))); }
        catch (err) { if (!(err instanceof K.SrcError)) throw err; if (this.onReject) this.onReject(err); }
      };
      doc.addEventListener('pointerup', up, true); doc.addEventListener('pointercancel', up, true);
      doc.addEventListener('pointerleave', () => { this.hovered = null; this.draw(); }, true);
      // מגע: גרירה של אלמנט שכבר נבחר אינה גוללת את העמוד
      doc.addEventListener('touchstart', e => { const n = this.nodeOf(e.target); for (let p = n; p; p = p.parent) if (p === this.selected) { e.preventDefault(); break; } }, { capture: true, passive: false });
      doc.addEventListener('scroll', () => this.draw(), true);
      this.frame.contentWindow.addEventListener('resize', () => this.draw());
      this.events = [];
    }
    /** לאן הגרירה תנחית: אח של האלמנט הנגרר שמתחת לסמן, ולפניו או אחריו. */
    dropAt(n, x, y) {
      const dom = this.m2d.get(n), par = dom && dom.parentElement;
      if (!par || !n.parent) return null;
      let t = this.doc.elementFromPoint(x, y);
      while (t && t.parentElement !== par) t = t.parentElement;
      if (!t || t === dom || t.hasAttribute(OWNED)) return null;
      const r = t.getBoundingClientRect(), dr = dom.getBoundingClientRect();
      const sameRow = Math.abs(r.top - dr.top) < Math.min(r.height, dr.height) / 2 && r.left !== dr.left;
      const rtl = this.frame.contentWindow.getComputedStyle(par).direction === 'rtl';
      const after = sameRow ? (rtl ? x < r.left + r.width / 2 : x > r.left + r.width / 2) : y > r.top + r.height / 2;
      const sibs = n.parent.kids.filter(k => k !== n), ref = this.d2m.get(t);
      const index = sibs.indexOf(ref) + (after ? 1 : 0);
      if (n.parent.kids.indexOf(n) === index) return null;
      return { parent: n.parent, index, rect: r, after, sameRow, rtl };
    }
    select(n) { this.selected = n || null; this.draw(); if (this.onSelect) this.onSelect(this.selected); }
    label(n) { const id = K.attrOf(n, 'id'), c = K.classesOf(n)[0]; return n.tag + (id ? '#' + id.value : c ? '.' + c : '') + (n.implied ? ' · לא במקור' : '') + (n.broken ? ' · שבור' : ''); }
    place(box, n) {
      const d = n && this.m2d.get(n);
      if (!d) { box.hidden = true; return; }
      const r = d.getBoundingClientRect();
      box.hidden = false;
      box.style.cssText = 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + Math.max(r.width, 2) + 'px;height:' + Math.max(r.height, 2) + 'px';
      box.firstChild.textContent = this.label(n);
    }
    draw() { if (!this.m2d) return; this.place(this.boxSel, this.selected); this.place(this.boxHov, this.hovered !== this.selected ? this.hovered : null); }
    drawDrop(drop) {
      if (!drop) { this.boxDrop.hidden = true; return; }
      const r = drop.rect, b = this.boxDrop; b.hidden = false;
      if (drop.sameRow) { const x = (drop.after !== drop.rtl) ? r.right : r.left; b.style.cssText = 'left:' + (x - 2) + 'px;top:' + r.top + 'px;width:4px;height:' + r.height + 'px'; }
      else b.style.cssText = 'left:' + r.left + 'px;top:' + ((drop.after ? r.bottom : r.top) - 2) + 'px;width:' + r.width + 'px;height:4px';
    }
  }

  window.KolCanvas = { Canvas, mapTrees, sameDom, browserParse, decode, encode, kidsOf, OWNED };
})();
