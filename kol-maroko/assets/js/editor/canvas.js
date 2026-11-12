/**
 * העורך החזותי — קנבס דומם (D3, שכבה ב). נולד בספייק V0; מ-V1 הוא המודול הקבוע של העורך.
 *
 * המסגרת היא sandbox="allow-same-origin" בלי allow-scripts: שום סקריפט של העמוד אינו רץ בה (גם לא onclick ולא
 * javascript:), אבל המעטפת — שהיא אותו מקור — מצמידה לה מאזינים וקוראת את ה-DOM שלה. מסגרות הבחירה והריחוף מצוירות
 * מעל המסגרת, במסמך של המעטפת; לקנבס עצמו נוסף רק <style data-kol-canvas> (ה-CSS של הפרויקט), שאינו חלק מהמיפוי.
 *
 * המיפוי אלמנט ↔ צומת במודל נעשה בהליכה מקבילה על שני העצים: הדפדפן ו-parse5 מממשים אותו אלגוריתם ניתוח, ולכן
 * הילד ה-i כאן הוא הילד ה-i שם. שום סימון אינו נכתב לקובץ.
 *
 * עדכון (V1): תיקון טקסט ← KolSrc.update (מודל הדרגתי, במקומו) ← sync: רק צמתי ה-DOM של האזור שהוחלף מוחלפים,
 * ורק הם ממופים מחדש. כל אי-התאמה ← רינדור מלא (נפילה בטוחה). verify — בדיקת שקילות מול ניתוח מלא אחרי כל פעולה.
 */
(function () {
  'use strict';
  const K = window.KolSrc;
  const OWNED = 'data-kol-canvas', CAP = 60, REAL = ':not([' + OWNED + '])';
  /** אלמנטים שאין להם מראה בעמוד — נבחרים ב-Navigator, ואינם "מוצגים לעריכה". */
  /** עריכת טקסט במקום (V2): מסגרת לאלמנט הנערך, ו"הקלד כאן" לאלמנט ריק. contenteditable נוסף ל-DOM של הקנבס בלבד, לזמן העריכה. */
  const EDIT_CSS = '[contenteditable="true"]{outline:2px solid #1a73e8!important;outline-offset:2px;cursor:text;min-height:1em;-webkit-user-modify:read-write}[contenteditable="true"]:empty::before{content:"הקלד כאן";opacity:.45}'
    + 'iframe,object,embed,audio,video{pointer-events:none}iframe{outline:1px dashed #8a8f98;outline-offset:-1px}';   // הטמעה ומדיה: בקנבס הן דוממות — הלחיצה בוחרת, לא מפעילה
  const DEAD = 'iframe,object,embed,audio,video';
  const NONVIS = new Set('html head title meta link script style base noscript template'.split(' '));
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
      this.verify = false; this.inequiv = []; this.onInequiv = null; this.lastMode = ''; this.lastWhy = ''; this.stats = { inc: 0, full: 0 };
      this.sheets = null; this.baseHref = ''; this.marksOn = false; this.zeroAt = 0; this.onKey = null; this.viewport = null; this.drag = true; this.repeatUp = false; this.scale = 1; this.ox = 0; this.capOff = false; this.capInfo = null;
      this.hiddenNodes = new Set(); this.lockedNodes = new Set(); this.shownMap = new Map(); this.shownD2m = new Map(); this.shownUndo = []; this.shownNote = ''; this.onPoint = null; this.onContext = null; this.longT = 0;
      host.classList.add('spike-canvas');
      this.frame = document.createElement('iframe');
      this.frame.setAttribute('sandbox', 'allow-same-origin');             // בלי allow-scripts — זה כל העניין
      this.frame.setAttribute('title', 'קנבס');
      this.layer = document.createElement('div'); this.layer.className = 'spike-layer';
      this.multi = new Set(); this.multiBoxes = []; this.keepMulti = false;
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
      if (typeof css === 'function') { this.sheets = css; this.cssSrc = ''; this.cssM = null; }           // העורך: href ← טקסט הקובץ מהפרויקט, או null
      else if (css != null) { this.sheets = null; this.cssSrc = css; this.cssM = K.parseCss(css); }
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
      this.history = []; this.zeroAt = 0; this.hiddenNodes = new Set(); this.lockedNodes = new Set(); this.select(null);
      return (this.timing = { parseHtml: t1 - t0, parseCss: t2 - t1, render: t3 - t2, map: t4 - t3, layout: t5 - t4, total: t5 - t0 });
    }
    render() {
      const parsed = browserParse(this.src), doc = this.doc, win = this.frame.contentWindow, sx = win ? win.scrollX : 0, sy = win ? win.scrollY : 0;
      const root = doc.adoptNode(parsed.documentElement);
      if (doc.documentElement) doc.replaceChild(root, doc.documentElement); else doc.appendChild(root);
      this.restyle();
      if (win && (sx || sy)) win.scrollTo(sx, sy);
    }
    /**
     * קובצי העיצוב באים מהטקסט שבעורך, לא מהרשת: ל-<link> נוסף disabled ב-DOM של הקנבס בלבד, ולידו <style> של העורך.
     * זו הסטייה היחידה של הקנבס מהמקור (ועוד <base> של העורך, כדי שתמונות הפרויקט ייטענו מכתובת ההגשה שלו).
     */
    restyle() {
      const doc = this.doc, head = doc.head || doc.documentElement;
      for (const o of doc.querySelectorAll('[' + OWNED + ']')) o.remove();
      this.styleEl = null; this.linked = [];
      const mk = (text, href) => { const st = doc.createElement('style'); st.setAttribute(OWNED, href == null ? '' : href); st.textContent = noBom(text); return st; };
      const links = Array.from(doc.querySelectorAll('link[rel~="stylesheet" i]'));
      if (this.sheets) {
        for (const l of links) {
          const href = l.getAttribute('href') || '', text = this.sheets(href);
          this.linked.push({ href, inProject: text != null });
          if (text == null) continue;                                       // לא מהפרויקט ("באתר"): נטען מהכתובת שלו
          l.setAttribute('disabled', ''); l.after(mk(text, href));
        }
      } else {
        for (const l of links) l.setAttribute('disabled', '');
        this.styleEl = mk(this.cssSrc, null);
        if (links[0]) links[0].after(this.styleEl); else head.append(this.styleEl);
      }
      if (this.baseHref && head) { const b = doc.createElement('base'); b.setAttribute(OWNED, 'base'); b.setAttribute('href', this.baseHref); head.prepend(b); }
      this.capStyle = doc.createElement('style'); this.capStyle.setAttribute(OWNED, 'cap'); head.append(this.capStyle);
      const ed = doc.createElement('style'); ed.setAttribute(OWNED, 'edit'); ed.textContent = EDIT_CSS; head.append(ed);   // V2: המראה של אלמנט בזמן עריכת טקסט — בקנבס בלבד
      this.recap();
    }
    /** מחליף את הטקסט של קובץ CSS מקושר (אחרי עריכה שלו), בלי לגעת בשאר. */
    setSheet(href, text) { for (const st of this.doc.querySelectorAll('style[' + OWNED + ']')) if (st.getAttribute(OWNED) === href) st.textContent = noBom(text); }
    /** רשימה ארוכה: יותר מ-CAP אחים זהים במבנה ← הקנבס מציג CAP, בכלל CSS של העורך (ה-DOM והמיפוי נשארים שלמים). */
    recap() {
      if (!this.capStyle || !this.sheets) return;
      const found = []; let rules = '';
      if (this.m.root) K.walk(this.m.root, n => {
        if (n.kids.length <= CAP) return;
        const sig = k => k.tag + '.' + K.classesOf(k).join('.'), s0 = sig(n.kids[0]);
        if (!n.kids.every(k => sig(k) === s0)) return;
        found.push({ node: n, total: n.kids.length });
        rules += this.selOf(n) + ' > :nth-child(n+' + (CAP + 1) + ' of ' + REAL + '){display:none!important}\n';
      });
      this.capInfo = found.length ? { lists: found, hidden: found.reduce((a, f) => a + f.total - CAP, 0), shown: this.capOff } : null;
      this.capStyle.textContent = (this.capOff ? '' : rules) + this.hideRules() + this.emptyRules();
    }
    /** בורר CSS לצומת, לפי מקומו בין האחים שבמקור (אלמנטים של העורך אינם נספרים). לשימוש בכללי העורך בלבד. */
    selOf(n) { let sel = ''; for (let x = n; x; x = x.parent) sel = (x.parent ? ' > :nth-child(' + (x.parent.kids.indexOf(x) + 1) + ' of ' + REAL + ')' : 'html') + sel; return sel; }
    /** 👁 הסתרה בעורך בלבד: כלל CSS של העורך. הקובץ וה-DOM של העמוד אינם משתנים. */
    hideRules() { let r = ''; for (const n of this.hiddenNodes) if (this.m2d && this.m2d.has(n) && n !== this.selected) r += this.selOf(n) + '{display:none!important}\n'; return r; }
    /**
     * V4: רובריקה ריקה אינה נעלמת — בקנבס בלבד: גובה מינימלי, קו מקווקו ו"＋ גרור לכאן" (כלל של העורך; הקובץ, התצוגה
     * החיה וה-DOM של העמוד אינם משתנים). רובריקה = div / section… בלי ילדים ובלי טקסט.
     */
    emptyRules() {
      let r = ''; const BOX = /^(div|section|article|header|footer|main|nav|aside|figure|form|fieldset|li|ul|ol|blockquote|details)$/;
      if (this.m.root) K.walk(this.m.root, n => {
        if (/^(img|video)$/.test(n.tag) && !n.implied && !(K.attrOf(n, 'src') || {}).value && !n.kids.some(k => k.tag === 'source')) { r += this.selOf(n) + '{min-width:96px;min-height:64px;display:inline-block;outline:2px dashed rgba(180,83,9,.7)!important;outline-offset:-2px}\n'; return; }
        if (n.implied || !BOX.test(n.tag) || n.kids.length || n.nodes.some(x => x.type === 'text' && /\S/.test(x.value))) return;
        for (let p = n.parent; p; p = p.parent) if (p.tag === 'template' || p.tag === 'head') return;
        const sel = this.selOf(n) + ':not([contenteditable])';             // בעריכת טקסט — מציין המיקום של העורך, לא זה
        r += sel + '{min-height:48px!important;min-width:48px;outline:2px dashed rgba(37,99,235,.55)!important;outline-offset:-2px}' + sel + '::before{content:"＋ גרור לכאן";display:block;padding:14px 8px;font:13px/1.4 system-ui,sans-serif;color:rgba(37,99,235,.8);text-align:center;pointer-events:none}\n';
      });
      return r;
    }
    setHidden(n, on) { if (on) this.hiddenNodes.add(n); else this.hiddenNodes.delete(n); this.recap(); this.zeroAt = 0; this.draw(); }
    setLocked(n, on) { if (on) this.lockedNodes.add(n); else this.lockedNodes.delete(n); }
    elOf(n) { return this.shownMap.get(n) || this.m2d.get(n) || null; }
    /** מה רואים מהאלמנט: 'nonvisual' (ראש העמוד, סקריפט…) · 'shown' (מוסתר בעמוד, מוצג זמנית לעריכה) · 'zero' · 'visible' · 'none'. */
    visual(n) {
      if (!n) return 'none';
      for (let x = n; x; x = x.parent) if (x.tag === 'head') return 'nonvisual';
      if (NONVIS.has(n.tag) && !(n.tag === 'template' && this.shownNote)) return 'nonvisual';
      const r = this.rectOf(n);
      return !r ? 'none' : this.shownNote && n === this.selected ? 'shown' : r.zero ? 'zero' : 'visible';
    }
    /** מחזיר את הקנבס למצבו: מסיר את מה ש"הוצג לעריכה". נקרא לפני כל שינוי ב-DOM, כדי שהסנכרון יעבוד על DOM נקי. */
    unshow() {
      for (const u of this.shownUndo) u();
      this.shownUndo = []; this.shownMap = new Map(); this.shownD2m = new Map(); this.shownNote = '';
    }
    /**
     * "מוצג לעריכה": אלמנט נבחר שאינו נראה (display:none, hidden, חלון סגור, או תוכן של <template>) מוצג זמנית בקנבס.
     * תוכן <template> — עותק שלו מוצב מיד אחרי התבנית (מסומן כשל העורך, ואינו חלק מהמיפוי); הסתרה — נדרסת בסגנון
     * על האלמנט ב-DOM של הקנבס, ומוחזרת כשהבחירה עוברת. הקובץ אינו משתנה.
     */
    show(n) {
      this.unshow();
      if (!n || !this.m2d.has(n) || !this.doc.body) return;
      for (let x = n; x; x = x.parent) if (x.tag === 'head') return;
      if (NONVIS.has(n.tag) && n.tag !== 'template') return;
      const win = this.frame.contentWindow, chain = [];
      for (let x = n.parent; x; x = x.parent) if (x.tag === 'template') chain.unshift(x);
      if (n.tag === 'template') chain.push(n);
      const pair = (x, e) => { this.shownMap.set(x, e); this.shownD2m.set(e, x); const dk = kidsOf(e); for (let i = 0; i < Math.min(dk.length, x.kids.length); i++) pair(x.kids[i], dk[i]); };
      for (const tpl of chain) {
        const te = this.elOf(tpl);
        if (!te || !te.isConnected) break;
        let tops = tpl.kids;
        if (tpl !== n) { let top = n; while (top.parent !== tpl) top = top.parent; tops = [top]; }
        let after = te;
        for (const t of tops) {
          const src = this.elOf(t); if (!src) continue;
          const c = src.cloneNode(true); c.setAttribute(OWNED, 'shown'); after.after(c); after = c; pair(t, c);
          this.shownUndo.push(() => c.remove());
        }
        this.shownNote = 'מוצג לעריכה · תוכן תבנית';
      }
      // הסתרה (display:none, hidden, חלון סגור): נדרסת בכלל CSS של העורך, לפי מקום האלמנט ב-DOM — בלי לגעת בתכונות שלו
      const domSel = e => { let sel = ''; for (let x = e; x.parentElement; x = x.parentElement) sel = ' > :nth-child(' + (Array.prototype.indexOf.call(x.parentElement.children, x) + 1) + ')' + sel; return 'html' + sel; };
      const path = []; for (let x = n; x; x = x.parent) path.unshift(x);
      let st = null, rules = '';
      const put = text => { if (!st) { st = this.doc.createElement('style'); st.setAttribute(OWNED, 'show'); (this.doc.head || this.doc.documentElement).append(st); this.shownUndo.push(() => st.remove()); } st.textContent = text; };
      for (const x of path) {
        const e = this.elOf(x);
        if (!e || !e.isConnected) continue;
        const cs = win.getComputedStyle(e), none = cs.display === 'none', inv = cs.visibility === 'hidden' && x === n;
        if (!none && !inv) continue;
        const sel = domSel(e);
        if (none) { put(rules + sel + '{display:revert!important}'); rules += sel + (win.getComputedStyle(e).display === 'none' ? '{display:block!important}' : '{display:revert!important}'); }
        if (inv) rules += sel + '{visibility:visible!important}';
        put(rules);
        this.shownNote = this.shownNote || 'מוצג לעריכה';
      }
    }
    domIndex(n) { const d = this.m2d && this.m2d.get(n); if (d && d.parentElement) return Array.prototype.indexOf.call(d.parentElement.children, d); return n.parent ? n.parent.kids.indexOf(n) : 0; }
    showAll(on) { this.capOff = !!on; this.recap(); this.draw(); }
    remap() { const r = mapTrees(this.m, this.doc.documentElement); this.d2m = r.d2m; this.m2d = r.m2d; this.mapErrors = r.errors; this.mapDom = r.dom; }
    domOf(n) { return this.m2d.get(n) || null; }
    /** האלמנט שמתחת לסמן. הטמעה ומדיה אינן מקבלות אירועים בקנבס (pointer-events: none) — מאתרים אותן לפי המלבן שלהן. */
    hit(e) {
      const t = e.target, doc = this.doc;
      if (!t || t.nodeType !== 1 || !this.deadTags) return t;
      if (!this.deadTags.some(l => l.length)) return t;
      let best = null;
      for (const f of t.querySelectorAll(DEAD)) { const r = f.getBoundingClientRect(); if (e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom) best = f; }
      return best || t;
    }
    nodeOf(d) { for (; d; d = d.parentElement) { const n = this.shownD2m.get(d) || this.d2m.get(d); if (n) return n; } return null; }
    /** האלמנט שלחיצה בנקודה בוחרת: העמוק ביותר שאינו 🔒 נעול (נעול — מדלגים להורה). */
    pick(d) { let n = this.nodeOf(d); while (n && this.lockedNodes.has(n)) n = n.parent; return n; }

    /** מחיל פעולת HTML: fn(model) מחזירה {patches}. זורק SrcError כשהפעולה נדחית — והקובץ אינו משתנה. */
    apply(fn) {
      const t0 = performance.now(), before = this.src, res = fn(this.m), t1 = performance.now();
      const r = this.patch(res.patches);
      this.history.push({ kind: 'html', patches: res.patches, inverse: K.invertPatches(before, res.patches) });
      this.timing = Object.assign(r.timing, { op: t1 - t0, total: r.timing.total + (t1 - t0) });
      if (this.onChange) this.onChange(res.patches, before);
      return { patches: res.patches, before, after: this.src, timing: this.timing, mode: this.lastMode, why: this.lastWhy, diff: this.lastDiff };
    }
    /** מחיל תיקוני טקסט על הקובץ שבקנבס — מכל מקור: פעולה חזותית, ביטול, או הקלדה בעורך הקוד. */
    patch(patches) {
      if (this.edit) this.edit.abort();                                     // עריכת טקסט פתוחה שלא היא שכתבה — נסגרת; ה-DOM שלה עומד להתחלף
      const t0 = performance.now(), sel = this.selected, selPath = sel ? K.pathOf(sel) : null, hov = this.hovered;
      this.unshow();
      const u = K.update(this.m, patches);
      const t1 = performance.now();
      this.m = u.model; this.src = u.model.src; this.lastMode = u.mode; this.lastWhy = u.why; this.lastDiff = null;
      let ok = u.mode === 'inc';
      if (ok) for (const r of u.regions) if (!this.sync(r)) { ok = false; this.lastWhy = 'ה-DOM של הקנבס לא תאם לאזור — רינדור מלא'; break; }
      const t2 = performance.now();
      if (!ok) { this.render(); this.remap(); } else if (this.sheets) this.recap();
      this.stats[ok ? 'inc' : 'full']++;
      const t3 = performance.now();
      void this.doc.documentElement.offsetHeight;
      const t4 = performance.now();
      if (this.verify) {                                                    // מצב פיתוח: שקילות מול ניתוח מלא אחרי כל פעולה
        const fresh = K.parseHtml(this.src), diff = K.sameModel(this.m, fresh);
        if (diff) { this.lastDiff = diff; this.inequiv.push({ diff, why: u.why, mode: u.mode }); this.m = fresh; this.render(); this.remap(); if (this.onInequiv) this.onInequiv(diff, patches); }
      }
      const alive = n => { for (let x = n; x; x = x.parent) if (x === this.m.root) return true; return false; };
      this.selected = sel && this.m2d.has(sel) && alive(sel) ? sel : (selPath ? K.byPath(this.m, selPath) : null);
      this.hovered = hov && this.m2d.has(hov) && alive(hov) ? hov : null;
      for (const set of [this.hiddenNodes, this.lockedNodes]) for (const x of set) if (!this.m2d.has(x) || !alive(x)) set.delete(x);
      if (this.sheets) this.recap();
      this.show(this.selected);
      this.zeroAt = 0; this.draw();
      return { mode: this.lastMode, why: this.lastWhy, timing: { op: 0, reparse: t1 - t0, dom: t2 - t1, map: t3 - t2, layout: t4 - t3, total: t4 - t0 } };
    }
    /** מחליף ב-DOM את הצמתים של אזור אחד. מחזיר false על כל אי-התאמה (לפני או אחרי השינוי) — ואז מרנדרים הכול. */
    sync(r) {
      const doc = this.doc;
      if (r.attrs) {                                                        // תכונות בלבד: מעדכנים אותן על האלמנט הקיים
        const e = this.m2d.get(r.node);
        if (!e) return false;
        for (const a of Array.from(e.attributes)) if (!r.node.attrs.some(x => x.name === a.name) && !(e.localName === 'link' && a.name === 'disabled')) e.removeAttribute(a.name);
        try { for (const a of r.node.attrs) if (e.getAttribute(a.name) !== a.value) e.setAttribute(a.name, a.value); } catch (err) { return false; }   // שם תכונה שה-DOM אינו מקבל
        if (e.attributes.length - (e.localName === 'link' && e.hasAttribute('disabled') ? 1 : 0) !== r.node.attrs.length) return false;
        if (/^(link|style|base)$/.test(r.node.tag)) this.restyle();
        this.mapErrors = [];
        return true;
      }
      const d = this.m2d.get(r.parent);
      if (!d) return false;
      const host = d.localName === 'template' && d.content ? d.content : d;
      const list = Array.prototype.filter.call(host.childNodes, x => !(x.nodeType === 1 && x.hasAttribute(OWNED)));
      if (list.length !== r.total - r.newCount + r.oldCount) return false;   // r.total: מספר הילדים מיד אחרי האזור הזה (אזורים נוספים באותו הורה מוחלים אחריו)
      let html = r.html.replace(/\r\n?/g, '\n');
      if (r.whole && /^(pre|textarea|listing)$/.test(r.parent.tag) && html[0] === '\n') html = html.slice(1);
      const range = doc.createRange(); range.selectNodeContents(d.localName === 'template' ? doc.body : d);
      const frag = range.createContextualFragment(html), fresh = Array.from(frag.childNodes);
      if (fresh.length !== r.newCount) return false;
      for (let i = 0; i < fresh.length; i++) { const x = r.nodes[i], t = fresh[i].nodeType; if ((x.type === 'el') !== (t === 1) || (x.type === 'text') !== (t === 3) || (x.type === 'el' && fresh[i].localName !== x.node.tag)) return false; }
      const ref = list[r.index + r.oldCount] || null;
      let links = /<link|<style|<base/i.test(r.html);
      for (let i = r.index; i < r.index + r.oldCount; i++) list[i].remove();
      for (const n of r.removed) K.walk(n, x => { this.m2d.delete(x); if (x.tag === 'link' || x.tag === 'style') links = true; });
      host.insertBefore(frag, ref && ref.parentNode === host ? ref : null);
      let bad = false;
      const go = (n, e) => {
        if (e.localName !== n.tag) { bad = true; return; }
        this.d2m.set(e, n); this.m2d.set(n, e);
        const dk = kidsOf(e);
        if (dk.length !== n.kids.length) { bad = true; return; }
        for (let i = 0; i < dk.length; i++) go(n.kids[i], dk[i]);
      };
      for (let i = 0; i < fresh.length; i++) if (fresh[i].nodeType === 1) go(r.nodes[i].node, fresh[i]);
      if (bad) return false;
      if (links) this.restyle();
      this.mapErrors = [];
      return true;
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
      else this.patch(h.inverse);
      return true;
    }

    // ── בחירה, ריחוף וגרירה ─────────────────────────────────
    listen() {
      const doc = this.doc, stop = e => { e.preventDefault(); e.stopPropagation(); };
      for (const ev of ['click', 'auxclick', 'dblclick', 'submit', 'dragstart', 'contextmenu']) doc.addEventListener(ev, stop, true);
      // תפריט ההקשר: לחיצה ימנית, או לחיצה ארוכה במגע (iOS אינו שולח contextmenu)
      this.deadTags = DEAD.split(',').map(t => doc.getElementsByTagName(t));
      const ctxAt = (t, x, y) => { const n = this.nodeOf(t); if (n && this.onContext) { const f = this.frame.getBoundingClientRect(); this.onContext(n, f.left + x * this.scale, f.top + y * this.scale); } };
      doc.addEventListener('contextmenu', e => { if (this.onDragStart && this.press && this.press.touch) return; clearTimeout(this.longT); if (!this.longDone) ctxAt(this.hit(e), e.clientX, e.clientY); this.longDone = false; }, true);
      doc.addEventListener('pointerdown', e => {
        if (e.button > 0) return;
        if (this.edit && this.edit.owns(e.target)) { this.press = null; return; }   // בתוך טקסט שנערך: הלחיצה מציבה את הסמן, ותו לא
        clearTimeout(this.longT); this.longDone = false;
        const tg = this.hit(e);
        if (this.onPoint) { const f = this.onPoint, n = this.nodeOf(tg); this.onPoint = null; this.press = null; e.preventDefault(); if (n) f(n); return; }   // "מצביעים על כל דבר" — גם נעול, גם נבחר
        if (e.pointerType === 'touch') {
          const t = tg, x = e.clientX, y = e.clientY;
          // V4: לחיצה ארוכה (400ms) מחמשת גרירה; תזוזה אחריה — גרירה, ושחרור בלי תזוזה — תפריט ההקשר
          if (this.onDragStart) this.longT = setTimeout(() => { const p = this.press; if (p) { p.armed = true; p.t = t; this.armed = p; } }, 400);
          else this.longT = setTimeout(() => { this.longDone = true; this.press = null; ctxAt(t, x, y); }, 600);
        }
        const n = this.pick(tg);
        this.press = n ? { n, x: e.clientX, y: e.clientY, id: e.pointerId, drag: false, was: this.selected === n, touch: e.pointerType === 'touch' } : null;
        if (n && e.shiftKey && this.selected && this.selected !== n) { this.press = null; e.preventDefault(); this.toggleMulti(n); return; }   // Shift + לחיצה: בחירה מרובה (V3)
        if (n && e.altKey) { this.press = null; this.select(this.under(e.clientX, e.clientY)); }          // Alt: האלמנט הבא מבין המונחים זה על זה
        else if (n && !(this.repeatUp && this.selected === n)) this.select(n);
        this.events.push('pointerdown:' + e.pointerType);
      }, true);
      doc.addEventListener('pointermove', e => {
        this.events.push('pointermove:' + e.pointerType);
        if (this.ext && this.ext.active) { const f = this.frame.getBoundingClientRect(); this.ext.move(f.left + e.clientX * this.scale, f.top + e.clientY * this.scale); return; }
        this.pt = { x: e.clientX, y: e.clientY };                           // V4: "＋" מופיע רק ליד שפת האלמנט
        if (this.press && e.pointerType === 'mouse' && e.buttons === 0) this.press = null;   // השחרור אבד (מחוץ לחלון) — אין לחיצה
        const p = this.press;
        if (this.edit && this.edit.active) { if (this.hovered) { this.hovered = null; this.draw(); } return; }
        if (p && p.id === e.pointerId && this.onDragStart && !p.drag && (e.pointerType === 'touch' ? p.armed && Math.hypot(e.clientX - p.x, e.clientY - p.y) * this.scale > 6 : Math.hypot(e.clientX - p.x, e.clientY - p.y) * this.scale > 4)) {
          p.drag = true; this.press = null; this.armed = null; clearTimeout(this.longT);       // V4: גרירה אחרי 4px — כדי שלחיצה תישאר בחירה
          const f = this.frame.getBoundingClientRect(); this.onDragStart(p.n, f.left + e.clientX * this.scale, f.top + e.clientY * this.scale); return;
        }
        if (p && Math.hypot(e.clientX - p.x, e.clientY - p.y) > 8) clearTimeout(this.longT);
        if (p && p.id === e.pointerId) {
          if (this.drag && !p.drag && Math.hypot(e.clientX - p.x, e.clientY - p.y) > 6) p.drag = true;
          if (p.drag) { p.drop = this.dropAt(p.n, e.clientX, e.clientY); this.drawDrop(p.drop); }
          return;
        }
        const n = this.pick(this.hit(e));
        if (n !== this.hovered) { this.hovered = n; this.draw(); }
      }, true);
      const up = e => {
        clearTimeout(this.longT);
        if (this.ext && this.ext.active) { this.press = null; const f = this.frame.getBoundingClientRect(); this.ext.end(e.type === 'pointerup', f.left + e.clientX * this.scale, f.top + e.clientY * this.scale); return; }
        if (this.armed && this.press === this.armed && e.type === 'pointerup' && !this.armed.drag) { const p = this.armed; this.armed = null; this.press = null; this.longDone = true; ctxAt(p.t, p.x, p.y); return; }
        this.armed = null;
        const p = this.press; this.press = null; this.boxDrop.hidden = true;
        this.events.push(e.type + ':' + e.pointerType);
        if (p && !p.drag && e.type === 'pointerup' && e.pointerType === 'touch') {   // הקשה כפולה במגע = לחיצה כפולה (iOS אינו שולח dblclick)
          const now = performance.now(), t = this.tap; this.tap = { at: now, x: e.clientX, y: e.clientY };
          if (t && now - t.at < 380 && Math.hypot(e.clientX - t.x, e.clientY - t.y) < 24 && this.onDbl) { this.tap = null; const n = this.nodeOf(this.hit(e)); if (n) { e.preventDefault(); this.dblAt = now; return this.onDbl(n, { x: e.clientX, y: e.clientY }); } }
        }
        if (p && !p.drag && e.type === 'pointerup' && this.repeatUp && p.was && this.selected === p.n && p.n.parent) return this.select(p.n.parent);   // לחיצה חוזרת — ההורה
        if (!p || !p.drag || e.type !== 'pointerup') return;
        const drop = this.dropAt(p.n, e.clientX, e.clientY);
        if (!drop) return;
        try { this.apply(m => K.html.move(m, p.n, drop.parent, drop.index)); this.select(K.byPath(this.m, K.pathOf(drop.parent).concat(drop.index))); }
        catch (err) { if (!(err instanceof K.SrcError)) throw err; if (this.onReject) this.onReject(err); }
      };
      doc.addEventListener('pointerup', up, true); doc.addEventListener('pointercancel', up, true);
      doc.addEventListener('pointerleave', () => { this.hovered = null; this.draw(); }, true);
      // מגע: גרירה של אלמנט שכבר נבחר אינה גוללת את העמוד
      doc.addEventListener('dblclick', e => { if (this.edit && this.edit.owns(e.target)) return; if (this.dblAt && performance.now() - this.dblAt < 600) return; const n = this.nodeOf(this.hit(e)); if (n && this.onDbl) this.onDbl(n, { x: e.clientX, y: e.clientY }); }, true);
      doc.addEventListener('touchmove', e => { if ((this.armed && this.press === this.armed) || (this.ext && this.ext.active)) e.preventDefault(); }, { capture: true, passive: false });
      doc.addEventListener('touchstart', e => { if (this.edit && this.edit.active) return; const n = this.nodeOf(e.target); for (let p = n; p; p = p.parent) if (p === this.selected) { e.preventDefault(); break; } }, { capture: true, passive: false });
      doc.addEventListener('scroll', () => { clearTimeout(this.longT); this.draw(); }, true);
      // משאב של העמוד שהמדיניות של העורך חסמה (הקנבס יורש אותה) — העורך אומר זאת, לא שותק
      doc.addEventListener('securitypolicyviolation', e => { if (this.onBlocked && /^https?:/i.test(e.blockedURI || '')) this.onBlocked(e.blockedURI, e.effectiveDirective || ''); });
      doc.addEventListener('keydown', e => { if (this.onKey) this.onKey(e); }, true);
      // מדיה אינה מתנגנת בקנבס (גם לא autoplay): ההשמעה — מהפאנל
      doc.addEventListener('play', e => { try { e.target.pause(); } catch { /* לא מדיה */ } }, true);
      // תמונה שנטענה או נכשלה — הסימון "תמונה חסרה" מתעדכן
      const imgEv = e => { if (e.target && e.target.localName === 'img') { this.zeroAt = 0; clearTimeout(this.imgT); this.imgT = setTimeout(() => this.draw(), 60); } };
      doc.addEventListener('error', imgEv, true); doc.addEventListener('load', imgEv, true);
      // קובץ שנגרר מהמחשב אל הקנבס: קו הכנסה, ובשחרור — onDropFile(file, האלמנט שמתחת לסמן)
      const hasFiles = e => !!e.dataTransfer && Array.prototype.includes.call(e.dataTransfer.types || [], 'Files');
      doc.addEventListener('dragover', e => {
        if (!hasFiles(e) || !this.onDropFile) return;
        e.preventDefault(); e.dataTransfer.dropEffect = 'copy';
        const n = this.pick(this.hit(e)), r = n && this.rectOf(n);
        if (!r) { this.boxDrop.hidden = true; return; }
        this.boxDrop.hidden = false;
        this.boxDrop.style.cssText = n.tag === 'img' ? 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px;opacity:.35' : 'left:' + r.left + 'px;top:' + (r.top + r.height - 2) + 'px;width:' + r.width + 'px;height:4px';
      }, true);
      doc.addEventListener('dragleave', () => { this.boxDrop.hidden = true; }, true);
      doc.addEventListener('drop', e => {
        this.boxDrop.hidden = true;
        if (!hasFiles(e) || !this.onDropFile) return;
        e.preventDefault(); e.stopPropagation();
        const f = e.dataTransfer.files && e.dataTransfer.files[0]; if (f) this.onDropFile(f, this.pick(this.hit(e)));
      }, true);
      this.frame.contentWindow.addEventListener('resize', () => this.draw());
      this.events = [];
      if (this.onListen) this.onListen(doc);
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
    select(n) {
      if (this.edit && this.edit.active && !this.edit.busy) {               // בוחרים משהו אחר בזמן עריכת טקסט: קודם נכתב מה שהוקלד
        const path = n ? K.pathOf(n) : null; this.edit.end();
        if (n && !this.m2d.has(n)) n = K.byPath(this.m, path);
      }
      if (!this.keepMulti) this.multi.clear();
      const was = this.selected; this.unshow(); this.selected = n || null;
      if (this.sheets && (this.hiddenNodes.has(was) || this.hiddenNodes.has(this.selected))) this.recap();   // נבחר שהוסתר בעורך — מוצג כל עוד הוא נבחר
      this.show(this.selected); this.zeroAt = 0; this.draw(); if (this.onSelect) this.onSelect(this.selected);
    }
    /** בחירה מרובה (Shift + לחיצה): האלמנט נוסף או יוצא; הנבחר הראשי נשאר. */
    toggleMulti(n) {
      if (!n || n === this.selected) return;
      for (let p = n.parent; p; p = p.parent) if (p === this.selected) return;   // אלמנט שבתוך הנבחר — לא
      if (this.multi.has(n)) this.multi.delete(n); else this.multi.add(n);
      this.draw(); if (this.onSelect) this.onSelect(this.selected);
    }
    /** כל הנבחרים: הראשי, ואחריו מה שנוסף ב-Shift. */
    picked() { return this.selected ? [this.selected, ...[...this.multi].filter(x => x !== this.selected && this.m2d.has(x))] : []; }
    /** Alt + לחיצה: כל האלמנטים שמתחת לנקודה, מהעליון לתחתון; מחזיר את זה שאחרי הנבחר. */
    under(x, y) {
      const stack = []; for (const d of this.doc.elementsFromPoint(x, y)) { const n = this.shownD2m.get(d) || this.d2m.get(d); if (n && !stack.includes(n)) stack.push(n); }
      if (!stack.length) return null;
      return stack[(stack.indexOf(this.selected) + 1) % stack.length];
    }
    /** ניווט במקלדת: 'parent' | 'child' | 'next' | 'prev'. מחזיר את הצומת החדש, או null כשאין לאן. */
    step(dir) {
      const n = this.selected; if (!n) return null;
      const sib = d => { const k = n.parent ? n.parent.kids : [], i = k.indexOf(n) + d; return k[i] || null; };
      const to = dir === 'parent' ? n.parent : dir === 'child' ? n.kids[0] : dir === 'next' ? sib(1) : sib(-1);
      if (to) { this.select(to); this.reveal(to); }
      return to || null;
    }
    reveal(n) { const d = this.elOf(n); if (!d) return; const r = d.getBoundingClientRect(), w = this.frame.contentWindow; if (r.width + r.height > 0 && (r.bottom < 0 || r.top > w.innerHeight)) d.scrollIntoView({ block: 'center' }); this.draw(); }
    label(n) { const id = K.attrOf(n, 'id'), c = K.classesOf(n)[0]; return n.tag + (id ? '#' + id.value : c ? '.' + c : '') + (n.implied ? ' · לא במקור' : '') + (n.broken ? ' · שבור' : ''); }
    /** רוחב הקנבס בפיקסלים (null — כרוחב המכל). כשאין מקום — המסגרת מוקטנת, והמסגרות שמעליה מחושבות לפי ההקטנה. */
    setViewport(width) {
      this.viewport = width || null;
      const hw = this.host.clientWidth, hh = this.host.clientHeight, f = this.frame.style;
      if (!this.viewport) { this.scale = 1; this.ox = 0; f.cssText = ''; }
      else {
        this.scale = Math.min(1, hw / this.viewport); this.ox = Math.max(0, (hw - this.viewport * this.scale) / 2);
        f.cssText = 'position:absolute;top:0;left:' + this.ox + 'px;width:' + this.viewport + 'px;height:' + (hh / this.scale) + 'px;transform:scale(' + this.scale + ');transform-origin:0 0';
      }
      this.zeroAt = 0; this.draw();
    }
    rectOf(n) {
      const d = n && this.elOf(n);
      if (!d) return null;
      let r;
      if (d.getClientRects().length) r = d.getBoundingClientRect();
      else if (n.tag === 'template' && n === this.selected && this.shownMap.size) {   // תבנית שתוכנה מוצג: המסגרת עוטפת את התוכן
        let a = null; for (const k of n.kids) { const e = this.shownMap.get(k), b = e && e.getClientRects().length ? e.getBoundingClientRect() : null; if (b) a = a ? { left: Math.min(a.left, b.left), top: Math.min(a.top, b.top), right: Math.max(a.right, b.right), bottom: Math.max(a.bottom, b.bottom) } : { left: b.left, top: b.top, right: b.right, bottom: b.bottom }; }
        if (!a) return null; r = { left: a.left, top: a.top, width: a.right - a.left, height: a.bottom - a.top };
      } else return null;                                                   // לא מרונדר (head, סקריפט) — ב-Navigator
      const s = this.scale;
      return { left: this.ox + r.left * s, top: r.top * s, width: r.width * s, height: r.height * s, zero: r.width === 0 || r.height === 0 };
    }
    place(box, n) {
      const r = this.rectOf(n);
      if (!r) { box.hidden = true; return; }
      box.hidden = false;
      box.style.cssText = 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + Math.max(r.width, 2) + 'px;height:' + Math.max(r.height, 2) + 'px';
      box.firstChild.textContent = this.label(n) + (box === this.boxSel && this.shownNote ? ' · ' + this.shownNote : '');
    }
    draw() {
      if (!this.m2d) return;
      this.place(this.boxSel, this.selected); this.place(this.boxHov, this.hovered !== this.selected ? this.hovered : null);
      const more = this.picked().slice(1);
      while (this.multiBoxes.length < more.length) this.multiBoxes.push(this.box('spike-box ed-multi'));
      this.multiBoxes.forEach((b, i) => { if (more[i]) this.place(b, more[i]); else b.hidden = true; });
      if (this.marksOn) this.drawMarks();
      if (this.onDraw) this.onDraw();
    }
    /**
     * סימונים מעל הקנבס (לא בתוכו): אזור שבור — מסגרת מקווקוות; אלמנט מרונדר בגודל אפס — מצביע קטן שאפשר ללחוץ עליו.
     * הסריקה (מי בגודל אפס) נעשית פעם אחת אחרי כל שינוי, לא בכל גלילה.
     */
    drawMarks() {
      if (!this.zeroAt) {
        this.zeroAt = 1; this.zero = []; this.brokenNodes = []; this.noted = [];
        if (this.m.root) K.walk(this.m.root, n => {
          if (n.broken) this.brokenNodes.push(n);
          if (this.zero.length >= 400 || n.tag === 'br' || n.tag === 'wbr') return;
          const d = this.m2d.get(n);
          if (d && this.noted.length < 200) {                                 // V2: תמונה שהקובץ שלה חסר, והטמעה (שאינה נטענת בעורך) — מסגרת עם הנתיב
            if (n.tag === 'img' && d.complete && d.naturalWidth === 0 && d.getAttribute('src')) this.noted.push({ n, cls: 'ed-mark-missing', text: '⚠️ תמונה חסרה · ' + d.getAttribute('src') });
            else if (/^(img|audio|video)$/.test(n.tag) && !d.getAttribute('src') && !d.querySelector('source[src]')) this.noted.push({ n, cls: 'ed-mark-missing', text: '⚠️ בלי קובץ' });   // V4: נוסף מהספרייה ולא נבחר קובץ
            else if (n.tag === 'iframe') this.noted.push({ n, cls: 'ed-mark-frame', text: 'הטמעה · ' + (d.getAttribute('src') || 'בלי כתובת') });
          }
          if (d && (d.offsetWidth === 0 || d.offsetHeight === 0) && d.getClientRects().length && d.ownerSVGElement === undefined) this.zero.push(n);
        });
      }
      const want = [], H = this.host.clientHeight;
      for (const n of this.brokenNodes) { const r = this.rectOf(n); if (r && r.top < H && r.top + r.height > 0) want.push({ n, r, cls: 'ed-mark-broken' }); }
      for (const x of this.noted) { const r = this.rectOf(x.n); if (r && !r.zero && r.top < H && r.top + r.height > 0) want.push({ n: x.n, r, cls: x.cls, text: x.text }); }
      for (const n of this.zero) { if (want.length > 120) break; const r = this.rectOf(n); if (r && r.zero && r.top < H && r.top > -12) want.push({ n, r, cls: 'ed-mark-zero' }); }
      if (!this.markBox) { this.markBox = document.createElement('div'); this.markBox.className = 'ed-marks'; this.layer.append(this.markBox); }
      const kids = this.markBox.children;
      while (kids.length > want.length) kids[kids.length - 1].remove();
      want.forEach((w, i) => {
        let b = kids[i];
        if (!b) { b = document.createElement('button'); b.type = 'button'; b.addEventListener('click', () => { if (b._n) this.select(b._n); }); this.markBox.append(b); }
        b._n = w.n; b.className = w.cls; b.title = this.label(w.n) + (w.text ? ' · ' + w.text : w.cls === 'ed-mark-zero' ? ' · בגודל אפס' : ' · קוד שבור'); b.textContent = w.text || '';
        b.style.cssText = w.cls === 'ed-mark-zero' ? 'left:' + (w.r.left - 1) + 'px;top:' + (w.r.top - 1) + 'px' : 'left:' + w.r.left + 'px;top:' + w.r.top + 'px;width:' + Math.max(w.r.width, 8) + 'px;height:' + Math.max(w.r.height, 8) + 'px';
      });
    }
    drawDrop(drop) {
      if (!drop) { this.boxDrop.hidden = true; return; }
      const r = drop.rect, b = this.boxDrop; b.hidden = false;
      if (drop.sameRow) { const x = (drop.after !== drop.rtl) ? r.right : r.left; b.style.cssText = 'left:' + (x - 2) + 'px;top:' + r.top + 'px;width:4px;height:' + r.height + 'px'; }
      else b.style.cssText = 'left:' + r.left + 'px;top:' + ((drop.after ? r.bottom : r.top) - 2) + 'px;width:' + r.width + 'px;height:4px';
    }
  }

  window.KolCanvas = { Canvas, mapTrees, sameDom, browserParse, decode, encode, kidsOf, OWNED, NONVIS };
})();
