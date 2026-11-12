/**
 * העורך החזותי — טקסט במקום (פרוסה V2, 03 §5.1).
 *
 * הבדיקה הצרה (03 §16) עברה ב-Chromium: contenteditable עובד בתוך מסגרת sandbox בלי allow-scripts, והמעטפת (אותו
 * מקור) מנהלת אותו מבחוץ — מאזינים, בחירה, execCommand. לכן עורכים **על הקנבס עצמו**, ולא בשכבה של המעטפת.
 *
 * איך הקלדה הופכת לשינוי בקובץ: בתחילת העריכה כל צומת DOM שבתוך האלמנט מותאם לצומת שלו במודל המקור. בסיום (יציאה,
 * Esc, או שתי שניות בלי הקשה) משווים את ה-DOM למודל ומחשבים תיקונים נקודתיים:
 *   · צומת טקסט שקיים במקור והשתנה — מוחלף רק הקטע שבין התחילית לסיומת המשותפות; ישות שלא נגעו בה נשארת כמו שנכתבה.
 *   · מבנה שהשתנה (נוסף <br>, <strong>, נמחק אלמנט) — מוחלף רק הרצף שהשתנה; מה שלא נגעו בו נכתב חזרה מהמקור, אות באות.
 *   · מה שהדפדפן מוסיף מעצמו (<div>, &nbsp; של סוף שורה, style, <br> של מציין מיקום) — אינו נכתב לקובץ.
 * החלק הטהור (plan / textPatch) אינו נוגע ב-DOM, ונבדק ב-tests/editor/text.mjs.
 */
(function (g) {
  'use strict';
  const K = g.KolSrc, NBSP = ' ';
  const WSRE = /[^ \t\n\r\f]/;
  /** תגיות שהן "עיצוב בתוך טקסט": לחיצה כפולה עליהן עורכת את הפסקה שמסביב, לא רק אותן. */
  const INLINE = new Set('a abbr b bdi bdo cite code em i kbd mark q s small span strong sub sup u font'.split(' '));
  /** תגיות שהעורך כותב לקובץ כשהן חדשות; כל תגית אחרת שנוצרה בזמן העריכה נכתבת כתוכן שלה בלבד. */
  const KEEP = new Set('a abbr b bdi bdo br cite code em i kbd mark q s small span strong sub sup u wbr'.split(' '));
  const TAGMAP = { b: 'strong', i: 'em' };
  const BLOCKISH = new Set('div p li h1 h2 h3 h4 h5 h6 blockquote pre tr section article header footer'.split(' '));
  const FORMAT = new Set('b strong i em u s mark font'.split(' '));
  const NOEDIT = new Set('html head title meta link script style base noscript template img br hr input select textarea option svg math audio video iframe canvas object embed picture source track table thead tbody tfoot tr colgroup col ul ol dl'.split(' '));
  const escText = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/ /g, '&nbsp;');
  const escAttr = s => s.replace(/&/g, '&amp;').replace(/ /g, '&nbsp;').replace(/"/g, '&quot;');
  const SrcError = K.SrcError;

  // ── החלק הטהור ────────────────────────────────────────────
  const ENT = /&(?:#[xX]?[0-9a-fA-F]+;?|[a-zA-Z][a-zA-Z0-9]*;?)/y;
  const decCache = new Map();
  function decodeEnt(tok) {
    let v = decCache.get(tok);
    if (v === undefined) { const m = K.parseHtml('<p>' + tok + '</p>'), p = K.q(m, 'p'); v = p ? p.nodes.filter(x => x.type === 'text').map(x => x.value).join('') : tok; decCache.set(tok, v); }
    return v;
  }
  /** מפרק טקסט מקור ליחידות: {s — כמו שנכתב, v — כמו שנקרא}. ישות היא יחידה אחת; ‎\r\n נקרא ‎\n. */
  function units(src) {
    const out = [];
    for (let i = 0; i < src.length;) {
      const c = src[i];
      if (c === '\r') { const two = src[i + 1] === '\n'; out.push({ s: two ? '\r\n' : '\r', v: '\n' }); i += two ? 2 : 1; continue; }
      if (c === '&') { ENT.lastIndex = i; const x = ENT.exec(src); if (x) { out.push({ s: x[0], v: decodeEnt(x[0]) }); i += x[0].length; continue; } }
      out.push({ s: c, v: c }); i++;
    }
    return out;
  }
  const count = (s, ch) => { let n = 0; for (let i = 0; i < s.length; i++) if (s[i] === ch) n++; return n; };
  /** ‎&nbsp; שהדפדפן הוסיף מעצמו (רווח בסוף שורה) אינו נכתב: אם מספר הרווחים הקשיחים בקטע השתנה — כולם חוזרים לרווח רגיל. */
  const tidy = (now, was) => (count(now, NBSP) === count(was, NBSP) ? now : now.replace(/ /g, ' '));
  /** תיקון לצומת טקסט אחד: רק מה שבין התחילית לסיומת המשותפות. null — לא השתנה. */
  function textPatch(m, t, now) {
    if (t.from < 0) throw new SrcError('לטקסט אין מיקום בקובץ', 'implied');
    if (now === t.value) return null;
    const src = m.src.slice(t.from, t.to), u = units(src);
    if (u.map(x => x.v).join('') !== t.value) return { from: t.from, to: t.to, text: escText(tidy(now, t.value)) };   // קריאה שלא שוחזרה — מחליפים את כל הצומת
    // רווח קשיח ורווח רגיל נחשבים כאן שווים: הדפדפן מחליף ביניהם מעצמו ליד מקום ההקלדה, ומה שכתוב בקובץ נשאר
    const eq = (v, at) => now.startsWith(v, at) || (v.length === 1 && (v === NBSP || v === ' ') && (now[at] === NBSP || now[at] === ' '));
    let a = 0, pos = 0, sa = 0;
    while (a < u.length && u[a].v.length && pos < now.length && eq(u[a].v, pos)) { pos += u[a].v.length; sa += u[a].s.length; a++; }
    let b = 0, end = now.length, sb = 0;
    while (b < u.length - a) { const x = u[u.length - 1 - b], l = x.v.length; if (!l || l > end - pos || !eq(x.v, end - l)) break; end -= l; sb += x.s.length; b++; }
    if (a + b === u.length && pos === end) return null;                     // ההבדל היחיד: רווחים שהדפדפן החליף
    const was = u.slice(a, u.length - b).map(x => x.v).join('');
    return { from: t.from + sa, to: t.to - sb, text: escText(tidy(now.slice(pos, end), was)) };
  }
  const startE = e => (e.type === 'el' ? e.node.from : e.from), endE = e => (e.type === 'el' ? e.node.to : e.to);
  /**
   * view — מה שיש עכשיו בתוך האלמנט: רשימת {o, type, data, tag, attrs, kids}. o — הרשומה המקורית שב-n.nodes (זהות), או
   * null לצומת חדש; kids === null — אלמנט אטום (לא נכנסים לתוכו). מחזיר תיקונים על m.src.
   */
  function plan(m, n, view) {
    const out = [];
    kidsPatches(m, n, tidyView(view), out);
    return out.sort((x, y) => x.from - y.from);
  }
  /** מציין המיקום של הדפדפן: <br> חדש בסוף אלמנט שהתרוקן, או <br> כפול חדש בסוף — אחד מהם אינו תוכן. */
  function tidyView(view) {
    const v = view.slice(), isBr = x => x && x.type === 'el' && !x.o && x.tag === 'br', last = v[v.length - 1];
    if (isBr(last) && (v.length === 1 || isBr(v[v.length - 2]))) v.pop();
    return v;
  }
  function kidsPatches(m, n, vk, out) {
    const old = n.nodes;
    if (n.implied || !n.et) throw new SrcError('ל-<' + n.tag + '> אין תגיות כתובות בקובץ', 'implied');
    let a = 0; while (a < old.length && a < vk.length && vk[a].o === old[a]) a++;
    let b = 0; while (b < old.length - a && b < vk.length - a && vk[vk.length - 1 - b].o === old[old.length - 1 - b]) b++;
    for (let i = 0; i < a; i++) inner(m, old[i], vk[i], out);
    for (let i = 0; i < b; i++) inner(m, old[old.length - 1 - i], vk[vk.length - 1 - i], out);
    if (a + b === old.length && a + b === vk.length) return;
    const from = a ? endE(old[a - 1]) : n.st[1], to = b ? startE(old[old.length - b]) : n.et[0];
    if (from < 0 || to < from) throw new SrcError('אין מיקום בקובץ לשינוי הזה', 'implied');
    const mid = vk.slice(a, vk.length - b);
    out.push({ from, to, text: mid.map((v, i) => emit(m, v, i === 0 && a === 0)).join('') });
  }
  function inner(m, e, v, out) {
    if (e.type === 'text') { const p = textPatch(m, e, v.data); if (p) out.push(p); }
    else if (e.type === 'el' && v.kids && !K.VOID.has(e.node.tag) && (v.kids.length || e.node.nodes.length)) kidsPatches(m, e.node, v.kids, out);
  }
  /** הטקסט שנכתב לקובץ לצומת אחד שבתוך רצף שהוחלף. צומת מקורי — מהמקור, עם התיקונים שבתוכו; חדש — נבנה כאן. */
  function emit(m, v, first) {
    if (v.o) {
      const e = v.o, from = startE(e), to = endE(e), ps = [];
      if (from < 0) throw new SrcError('אין מיקום בקובץ לשינוי הזה', 'implied');
      inner(m, e, v, ps);
      return K.applyPatches(m.src.slice(from, to), ps.map(p => ({ from: p.from - from, to: p.to - from, text: p.text })));
    }
    if (v.type === 'text') return escText(v.data.replace(/ /g, ' '));
    if (v.type !== 'el') return '';
    const kids = v.kids || [], onlyBr = kids.length === 1 && kids[0].type === 'el' && kids[0].tag === 'br';
    const body = onlyBr && BLOCKISH.has(v.tag) ? '' : kids.map((k, i) => emit(m, k, i === 0)).join('');
    if (BLOCKISH.has(v.tag)) return (first ? '' : '<br>') + body;          // <div> שהדפדפן יצר ל"שורה חדשה" — נכתב כ-<br>
    const tag = TAGMAP[v.tag] || v.tag;
    if (!KEEP.has(tag)) return body;
    const attrs = (v.attrs || []).filter(([k]) => k !== 'style' && k !== 'contenteditable' && k !== 'spellcheck' && !/^data-kol/.test(k) && !/^on/i.test(k));
    if (tag === 'span' && !attrs.length) return body;
    const open = '<' + tag + attrs.map(([k, val]) => ' ' + k + (val === '' && k !== 'href' && k !== 'class' ? '' : '="' + escAttr(val) + '"')).join('') + '>';
    return K.VOID.has(tag) ? open : open + body + '</' + tag + '>';
  }

  /** יש לאלמנט טקסט משלו (לא רק רווחים)? */
  const ownText = n => n.nodes.some(x => x.type === 'text' && WSRE.test(x.value));
  const emptyLeaf = n => !n.kids.length && !ownText(n) && !K.VOID.has(n.tag);
  /** תגיות של טקסט: גם כשכל הטקסט שלהן עטוף בתגיות עיצוב (<h1><em>…</em> <strong>…</strong></h1>) — עורכים אותן עצמן. */
  const TEXTY = new Set('h1 h2 h3 h4 h5 h6 p li a button label td th dt dd figcaption caption summary legend blockquote'.split(' '));
  const anyText = n => ownText(n) || n.kids.some(anyText);
  const phrasing = n => (TEXTY.has(n.tag) || INLINE.has(n.tag)) && n.kids.length > 0 && n.kids.every(k => INLINE.has(k.tag) || k.tag === 'br' || k.tag === 'wbr') && anyText(n);
  const editable = n => !!n && !NOEDIT.has(n.tag) && !n.foreign && (ownText(n) || emptyLeaf(n) || (TEXTY.has(n.tag) && phrasing(n)));
  /** האלמנט שנערך כשלוחצים פעמיים על n: n עצמו; תגית עיצוב שבתוך טקסט — הפסקה שמסביב; מכל — הילד הראשון שיש בו טקסט. */
  function hostFor(n) {
    if (!n) return null;
    while (INLINE.has(n.tag) && n.parent && ownText(n.parent) && !NOEDIT.has(n.parent.tag)) n = n.parent;
    if (editable(n)) return n;
    let hit = null;
    const go = x => { if (hit) return; if (x !== n && editable(x)) { hit = x; return; } if (x.tag === 'template' || NOEDIT.has(x.tag) && x !== n && !/^(ul|ol|dl|table|thead|tbody|tfoot|tr)$/.test(x.tag)) return; x.kids.forEach(go); };
    go(n);
    return hit;
  }
  /** מה מונע עריכה במקום (SrcError), או null. */
  function blocker(m, n) {
    if (n.implied) return new SrcError('<' + n.tag + '> נוסף בידי הדפדפן ואינו כתוב בקובץ', 'implied');
    for (let p = n; p; p = p.parent) if (p.broken) return new SrcError('<' + n.tag + '> נמצא באזור שבור בקוד — מתקנים אותו בעורך הקוד', 'broken');
    if (n.hasBroken) return new SrcError('בתוך <' + n.tag + '> יש קוד שבור — מתקנים אותו בעורך הקוד', 'broken');
    if (!n.et) return new SrcError('ל-<' + n.tag + '> אין תגית סגירה כתובה — מתקנים אותה בעורך הקוד', 'broken');
    let bad = null;
    K.walk(n, x => { if (!bad && x !== n && (x.implied || !K.isSimple(m, x))) bad = x; });
    if (bad) return new SrcError('בתוך <' + n.tag + '> יש <' + bad.tag + '> בלי תגית סגירה כתובה — עורכים בעורך הקוד', 'broken');
    return null;
  }

  // ── העריכה עצמה (דפדפן) ────────────────────────────────────
  const OWNED = 'data-kol-canvas';
  const mk = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const realKids = d => Array.prototype.filter.call(d.childNodes, x => !(x.nodeType === 1 && x.hasAttribute(OWNED)));
  const SAFE_HREF = /^(https?:|mailto:|tel:|#|\/|\.{0,2}\/|[^:]*$)/i;

  class TextEdit {
    /** opt: { canWrite(): string|'' (סיבה שאי אפשר), commit(patches, label): bool, run(label, fn(m)): bool, undo(), redo(), note(text, err, action), openInCode(n) } */
    constructor(canvas, opt) {
      this.c = canvas; this.opt = opt; this.active = false; this.busy = false; this.n = null; this.e = null; this.map = null; this.dirty = false; this.idle = 0; this.composing = false; this.rich = false; this.saved = null;
      canvas.edit = this;
      this.bar = mk('div', 'ed-tbar'); this.bar.hidden = true; this.bar.setAttribute('role', 'toolbar'); this.bar.setAttribute('aria-label', 'עריכת טקסט'); this.bar.dir = 'rtl';
      for (const ev of ['pointerdown', 'mousedown']) this.bar.addEventListener(ev, e => { if (!/^(input|select)$/.test(e.target.localName)) e.preventDefault(); });   // הלחיצה על הסרגל אינה מוציאה את הסמן מהטקסט
      canvas.layer.append(this.bar);
      canvas.onListen = doc => this.listen(doc);
      if (canvas.ready) this.listen(canvas.doc);
      // המקלדת עולה / יורדת: החלון הנראה משתנה, והקנבס מתכווץ אחריו — בודקים אחרי שהפריסה התעדכנה (שתי מסגרות)
      const later = () => requestAnimationFrame(() => requestAnimationFrame(() => { this.place(); this.keepVisible(); }));
      if (g.visualViewport) for (const ev of ['resize', 'scroll']) g.visualViewport.addEventListener(ev, later);
      g.addEventListener('resize', later);
    }
    listen(doc) {
      const on = (ev, fn) => doc.addEventListener(ev, e => { if (this.active) fn(e); }, true);
      on('beforeinput', e => {
        const t = e.inputType || '';
        if (t === 'insertParagraph') { e.preventDefault(); doc.execCommand('insertLineBreak'); }      // Enter = <br>, כמו Shift+Enter; אין <div> של הדפדפן
        else if (/^format|^insertFromDrop|^insertHorizontalRule|^insert(Un)?orderedList/.test(t)) e.preventDefault();
      });
      on('input', () => this.touched());
      on('compositionstart', () => { this.composing = true; });
      on('compositionend', () => { this.composing = false; this.touched(); });
      on('paste', e => this.paste(e));
      on('drop', e => e.preventDefault());
      on('selectionchange', () => this.drawBar());
      doc.defaultView.addEventListener('blur', () => { if (this.active) setTimeout(() => { if (this.active && !this.busy && !this.bar.contains(document.activeElement) && !(doc.hasFocus() && this.e && this.e.contains(doc.activeElement))) this.end(); }, 0); });
    }
    owns(target) { return this.active && this.e && target && (target === this.e || this.e.contains(target)); }

    /** נכנס לעריכה. n — האלמנט שנלחץ או נבחר; pt — {x, y} במסגרת (לחיצה כפולה), או בלעדיו — הסמן בסוף. */
    begin(n, pt, keep, exact) {
      const c = this.c;
      if (this.active) this.end();
      const why = this.opt.canWrite();
      if (why) { this.opt.note(why, true); return false; }
      const host = exact ? n : hostFor(n && c.m2d.has(n) ? n : c.selected);
      if (!host) { this.opt.note('באלמנט הזה אין טקסט לעריכה במקום. תמונה, מדיה וקישור — בלשונית "תוכן".'); return false; }
      const err = blocker(c.m, host);
      if (err) { this.opt.note(err.message, true, { label: '</> פתח בקוד', run: () => this.opt.openInCode(host) }); return false; }
      this.busy = true;
      try {
        if (c.selected !== host) c.select(host);
        let e = c.elOf(host), map = e && this.pair(host, e);
        if (!map) { c.render(); c.remap(); c.show(c.selected); e = c.elOf(host); map = e && this.pair(host, e); }   // ה-DOM פוצל אחרת מהמודל (צמתי טקסט צמודים) — מרנדרים ומנסים שוב
        if (!map) { this.opt.note('הקנבס אינו תואם לקובץ במקום הזה — עורכים בעורך הקוד', true, { label: '</> פתח בקוד', run: () => this.opt.openInCode(host) }); return false; }
        this.n = host; this.e = e; this.map = map; this.path = K.pathOf(host); this.dirty = false; this.active = true; this.label = c.label(host);
        e.setAttribute('contenteditable', 'true'); e.setAttribute('spellcheck', 'false');
        c.frame.contentWindow.focus(); e.focus({ preventScroll: !!pt });
        const doc = c.doc, sel = doc.getSelection();
        let r = null;
        if (pt && doc.caretRangeFromPoint) r = doc.caretRangeFromPoint(pt.x, pt.y);
        else if (pt && doc.caretPositionFromPoint) { const p = doc.caretPositionFromPoint(pt.x, pt.y); if (p) { r = doc.createRange(); r.setStart(p.offsetNode, p.offset); } }
        if (r && !e.contains(r.startContainer)) r = null;
        if (keep != null) this.caret(keep);
        else if (r) { sel.removeAllRanges(); sel.addRange(r); }
        else { sel.selectAllChildren(e); sel.collapseToEnd(); }
        if (!pt) e.scrollIntoView({ block: 'nearest' });
      } finally { this.busy = false; }
      c.hovered = null; c.draw(); this.drawBar(); this.keepVisible();
      if (this.opt.onState) this.opt.onState();
      return true;
    }
    /** התאמת ה-DOM למודל, צומת מול צומת. null — אין התאמה. */
    pair(n, e) {
      const map = new Map(); let ok = true;
      const go = (x, d) => {
        const dk = realKids(d);
        if (dk.length !== x.nodes.length) { ok = false; return; }
        for (let i = 0; i < dk.length && ok; i++) {
          const en = x.nodes[i], t = dk[i].nodeType;
          if ((en.type === 'el') !== (t === 1) || (en.type === 'text') !== (t === 3) || (en.type === 'comment') !== (t === 8)) { ok = false; return; }
          if (en.type === 'el' && dk[i].localName !== en.node.tag) { ok = false; return; }
          if (en.type === 'text' && dk[i].data !== en.value) { ok = false; return; }
          map.set(dk[i], en);
          if (en.type === 'el' && en.node.tag !== 'template') go(en.node, dk[i]);
        }
      };
      go(n, e);
      return ok ? map : null;
    }
    view(d) {
      return realKids(d).map(x => {
        const o = this.map.get(x) || null;
        if (x.nodeType === 3) return { o, type: 'text', data: x.data };
        if (x.nodeType !== 1) return { o, type: 'comment' };
        return { o, type: 'el', tag: x.localName, attrs: Array.from(x.attributes, a => [a.name, a.value]), kids: x.localName === 'template' ? null : this.view(x) };
      });
    }
    patches() { return plan(this.c.m, this.n, this.view(this.e)); }
    touched() {
      this.dirty = true; clearTimeout(this.idle);
      this.idle = setTimeout(() => { if (this.active && !this.composing) this.flush(true); }, 2000);
      this.c.draw(); this.place(); this.keepVisible();
    }
    /** מיקום הסמן כמספר תווים מתחילת האלמנט (<br> נספר כתו) — כך הוא משוחזר אחרי שה-DOM הוחלף. */
    offset() {
      const sel = this.c.doc.getSelection();
      if (!sel || !sel.rangeCount || !this.e.contains(sel.focusNode)) return null;
      const at = (node, off) => { const r = this.c.doc.createRange(); r.selectNodeContents(this.e); r.setEnd(node, off); let n = r.toString().length; const f = r.cloneContents(); n += f.querySelectorAll('br').length; return n; };
      return { a: at(sel.anchorNode, sel.anchorOffset), f: at(sel.focusNode, sel.focusOffset) };
    }
    caret(o) {
      const doc = this.c.doc, sel = doc.getSelection();
      const find = n => {
        const w = doc.createTreeWalker(this.e, 5); let x, last = null;       // טקסט ואלמנטים
        while ((x = w.nextNode())) {
          if (x.nodeType === 3) { if (n <= x.data.length) return [x, n]; n -= x.data.length; last = [x, x.data.length]; }
          else if (x.localName === 'br') { if (n === 0) return [x.parentNode, Array.prototype.indexOf.call(x.parentNode.childNodes, x)]; n -= 1; last = [x.parentNode, Array.prototype.indexOf.call(x.parentNode.childNodes, x) + 1]; }
        }
        return last || [this.e, 0];
      };
      const a = find(o.a), f = find(o.f);
      try { sel.setBaseAndExtent(a[0], a[1], f[0], f[1]); } catch { sel.selectAllChildren(this.e); sel.collapseToEnd(); }
    }
    /** כותב לקובץ את מה שהוקלד מאז הכתיבה הקודמת, כעסקה אחת. keep — נשארים בעריכה (והסמן במקומו). */
    flush(keep, label) {
      if (!this.active) return true;
      clearTimeout(this.idle);
      const c = this.c, host = this.n;
      let ps;
      try { ps = this.patches(); }
      catch (err) { if (!(err instanceof SrcError)) throw err; this.opt.note(err.message, true, { label: '</> פתח בקוד', run: () => this.opt.openInCode(host) }); this.quit(true); return false; }
      if (!ps.length) { this.dirty = false; if (!keep) this.quit(false); return true; }
      const at = keep ? this.offset() : null, path = this.path;
      this.busy = true; this.active = false;
      let ok;
      try { ok = this.opt.commit(ps, label || 'טקסט · ' + this.label); } finally { this.busy = false; }
      this.dirty = false; this.e = null; this.map = null; this.n = null;
      if (!ok) { c.render(); c.remap(); c.show(c.selected); c.draw(); this.done(); return false; }   // הכתיבה נדחתה — הקנבס חוזר לקובץ
      if (keep) { const n2 = K.byPath(c.m, path); if (n2 && !NOEDIT.has(n2.tag) && this.begin(n2, null, at || undefined, true)) return true; }
      this.done();
      return true;
    }
    /** יוצא מהעריכה בלי לכתוב. restore — מחזיר את ה-DOM של הקנבס לקובץ. */
    quit(restore) {
      clearTimeout(this.idle);
      const c = this.c, e = this.e;
      this.active = false; this.e = null; this.map = null; this.n = null; this.dirty = false;
      if (e && e.isConnected) { e.removeAttribute('contenteditable'); e.removeAttribute('spellcheck'); }
      if (restore) { c.render(); c.remap(); c.show(c.selected); }
      this.done();
    }
    done() { this.bar.hidden = true; this.form = null; const s = this.c.doc && this.c.doc.getSelection(); if (s && !this.active) s.removeAllRanges(); this.c.draw(); if (this.opt.onState) this.opt.onState(); }
    /** יציאה מהעריכה, עם שמירה. */
    end() { if (this.active) this.flush(false); }
    /** הקנבס עומד להתעדכן ממקור אחר (עורך הקוד, ביטול) — העריכה נסגרת בלי לכתוב. */
    abort() { if (this.active && !this.busy) { clearTimeout(this.idle); this.active = false; this.e = null; this.map = null; this.n = null; this.dirty = false; this.bar.hidden = true; this.form = null; if (this.opt.onState) this.opt.onState(); } }

    key(e) {
      const mod = e.ctrlKey || e.metaKey, k = e.key, low = (k || '').toLowerCase();
      if (e.isComposing || this.composing) return;
      if (k === 'Escape') { e.preventDefault(); this.end(); return; }
      if (mod && !e.altKey && (low === 'z' || low === 'y' || k === 'ז' || k === 'ט')) {
        const redo = low === 'y' || k === 'ט' || e.shiftKey;
        if (this.dirty && !redo) return;                                    // הקלדה שעוד לא נכתבה — הביטול של הדפדפן
        e.preventDefault();
        const path = this.path; this.flush(false);
        if (redo) this.opt.redo(); else this.opt.undo();
        const n2 = K.byPath(this.c.m, path); if (n2 && !NOEDIT.has(n2.tag) && !blocker(this.c.m, n2)) this.begin(n2, null, undefined, true);
        return;
      }
      if (mod && !e.altKey && (low === 'b' || low === 'i' || low === 'u')) { e.preventDefault(); this.format(low === 'b' ? 'strong' : low === 'i' ? 'em' : 'u'); }
    }
    /** הטווח המסומן, אם הוא כולו בתוך האלמנט הנערך. */
    range() {
      const sel = this.c.doc.getSelection();
      if (!this.active || !sel || !sel.rangeCount) return null;
      const r = sel.getRangeAt(0);
      return this.e.contains(r.commonAncestorContainer) ? r : null;
    }
    inside(tags) { const r = this.range(); if (!r) return null; for (let x = r.commonAncestorContainer; x && x !== this.e; x = x.parentNode) if (x.nodeType === 1 && tags.includes(x.localName)) return x; return null; }
    /** מפרק אלמנט ומשאיר את התוכן שלו במקומו. צמתי טקסט צמודים אינם ממוזגים — ה-plan מתמודד איתם, והזהות של המקוריים נשמרת. */
    unwrapEl(el) { const p = el.parentNode; while (el.firstChild) p.insertBefore(el.firstChild, el); el.remove(); }
    wrapSel(tag, attrs) {
      const r = this.range();
      if (!r || r.collapsed) { this.opt.note('מסמנים טקסט, ואז לוחצים'); return null; }
      const el = this.c.doc.createElement(tag);
      for (const [k, v] of Object.entries(attrs || {})) el.setAttribute(k, v);
      el.append(r.extractContents()); r.insertNode(el);
      const sel = this.c.doc.getSelection(); sel.selectAllChildren(el);
      return el;
    }
    /** מודגש / נטוי / קו תחתון: עוטף את המסומן בתגית רגילה; אם המסומן כבר בתוך תגית כזו — מסיר אותה. */
    format(tag) {
      if (!this.active) return;
      const same = tag === 'strong' ? ['strong', 'b'] : tag === 'em' ? ['em', 'i'] : [tag], cur = this.inside(same);
      if (cur) this.unwrapEl(cur); else if (!this.wrapSel(tag)) return;
      this.flush(true, { strong: 'מודגש', em: 'נטוי', u: 'קו תחתון' }[tag] + ' · ' + this.label);
    }
    unformat() {
      const r = this.range(); if (!r) return;
      const hit = Array.from(this.e.querySelectorAll('*')).filter(x => FORMAT.has(x.localName) && r.intersectsNode(x));
      if (!hit.length) { this.opt.note('במסומן אין עיצוב להסיר'); return; }
      for (const x of hit) this.unwrapEl(x);
      this.flush(true, 'הסרת עיצוב · ' + this.label);
    }
    link(href, blank) {
      const cur = this.inside(['a']);
      if (cur) { if (href) { cur.setAttribute('href', href); if (blank) { cur.setAttribute('target', '_blank'); cur.setAttribute('rel', 'noopener'); } } else this.unwrapEl(cur); }
      else if (href) { if (!this.wrapSel('a', blank ? { href, target: '_blank', rel: 'noopener' } : { href })) return; }
      else return;
      this.flush(true, (href ? 'קישור' : 'הסרת קישור') + ' · ' + this.label);
    }
    wrapClass(name) {
      name = name.trim();
      if (!/^-?[_a-zA-Z -￿][-_a-zA-Z0-9 -￿]*$/.test(name)) { this.opt.note('שם מחלקה: אותיות, ספרות, מקף וקו תחתון — בלי רווחים, ולא מתחיל בספרה', true); return; }
      if (!this.wrapSel('span', { class: name })) return;
      this.flush(true, 'עטיפה במחלקה .' + name + ' · ' + this.label);
    }
    /** הדבקה: טקסט נקי. "הדבק עם עיצוב" (לפעם הבאה) — רק b / i / u / a / br. */
    paste(e) {
      e.preventDefault();
      const cd = e.clipboardData, doc = this.c.doc; if (!cd) return;
      if (this.rich && cd.getData('text/html')) {
        this.rich = false;
        const frag = this.clean(cd.getData('text/html')), r = this.range();
        if (r) { r.deleteContents(); const last = frag.lastChild; r.insertNode(frag); if (last) { const s = doc.getSelection(); s.removeAllRanges(); const nr = doc.createRange(); nr.setStartAfter(last); s.addRange(nr); } }
        this.flush(true, 'הדבקה עם עיצוב · ' + this.label);
        return;
      }
      const lines = cd.getData('text/plain').replace(/\r\n?/g, '\n').split('\n');
      lines.forEach((l, i) => { if (i) doc.execCommand('insertLineBreak'); if (l) doc.execCommand('insertText', false, l); });
      this.touched(); this.drawBar();
    }
    /** מנקה HTML שהודבק: נשארים רק strong / em / u / a[href] / br וטקסט. נבנה במסמך דומם, בלי להריץ דבר. */
    clean(html) {
      const src = new DOMParser().parseFromString(html, 'text/html'), doc = this.c.doc, out = doc.createDocumentFragment();
      const go = (from, to) => {
        for (const x of from.childNodes) {
          if (x.nodeType === 3) { to.append(doc.createTextNode(x.data.replace(/\s+/g, ' '))); continue; }
          if (x.nodeType !== 1 || /^(script|style|head|title|meta|link|template|noscript|iframe|object|svg|math)$/.test(x.localName)) continue;
          const t = x.localName, map = { b: 'strong', strong: 'strong', i: 'em', em: 'em', u: 'u', a: 'a', br: 'br' }[t];
          if (map === 'br') { to.append(doc.createElement('br')); continue; }
          if (map === 'a' && !(x.getAttribute('href') && SAFE_HREF.test(x.getAttribute('href').trim()))) { go(x, to); continue; }
          if (map) { const el = doc.createElement(map); if (map === 'a') el.setAttribute('href', x.getAttribute('href').trim()); go(x, el); if (el.childNodes.length) to.append(el); continue; }
          const block = BLOCKISH.has(t);
          if (block && to.lastChild && to.lastChild.localName !== 'br') to.append(doc.createElement('br'));
          go(x, to);
        }
      };
      go(src.body, out);
      return out;
    }

    // ── הסרגל הצף ───────────────────────────────────────────
    drawBar() {
      if (!this.active) { this.bar.hidden = true; return; }
      if (this.form) { this.place(); return; }
      const r = this.range(), has = !!r && !r.collapsed, n = this.n;
      const btn = (text, label, fn, on, off) => { const b = mk('button', 'ed-tb' + (on ? ' on' : ''), text); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label); if (on != null) b.setAttribute('aria-pressed', String(!!on)); b.disabled = !!off; b.addEventListener('click', fn); return b; };
      const kids = [
        btn('B', 'מודגש (Ctrl+B)', () => this.format('strong'), !!this.inside(['strong', 'b']), !has && !this.inside(['strong', 'b'])),
        btn('I', 'נטוי (Ctrl+I)', () => this.format('em'), !!this.inside(['em', 'i']), !has && !this.inside(['em', 'i'])),
        btn('U', 'קו תחתון (Ctrl+U)', () => this.format('u'), !!this.inside(['u']), !has && !this.inside(['u'])),
        btn('🔗', 'קישור', () => this.ask('link'), !!this.inside(['a']), !has && !this.inside(['a'])),
        btn('⌫', 'הסר עיצוב', () => this.unformat(), null, !has),
        btn('{ }', 'עטוף במחלקה…', () => this.ask('class'), null, !has),
      ];
      if (n.tag === 'li' || n.tag === 'p') kids.push(btn('¶＋', n.tag === 'li' ? 'פריט חדש אחריו' : 'פסקה חדשה אחריה', () => this.sibling()));
      const dir = (K.attrOf(n, 'dir') || {}).value || '';
      kids.push(btn(dir === 'rtl' ? '⇠' : dir === 'ltr' ? '⇢' : '⇄', 'כיוון הטקסט: ' + (dir === 'rtl' ? 'ימין לשמאל' : dir === 'ltr' ? 'שמאל לימין' : 'אוטומטי') + ' — לחיצה מחליפה', () => this.dir(dir === '' ? 'rtl' : dir === 'rtl' ? 'ltr' : '')));
      kids.push(btn('📋', 'הדבק עם עיצוב — ההדבקה הבאה שומרת מודגש, נטוי, קו וקישור', () => { this.rich = !this.rich; this.drawBar(); }, this.rich));
      kids.push(btn('✓', 'סיום (Esc)', () => this.end()));
      kids[0].classList.add('b'); kids[1].classList.add('i'); kids[2].classList.add('u');
      this.bar.replaceChildren(...kids); this.bar.hidden = false; this.place();
    }
    /** טופס קטן בתוך הסרגל (כתובת של קישור, שם של מחלקה). הבחירה בטקסט נשמרת ומשוחזרת. */
    ask(kind) {
      const r = this.range(); if (!r) return;
      const saved = r.cloneRange(), cur = kind === 'link' ? this.inside(['a']) : null;
      const inp = mk('input', 'ed-tb-in'); inp.type = 'text'; inp.dir = 'ltr'; inp.setAttribute('aria-label', kind === 'link' ? 'כתובת הקישור' : 'שם המחלקה'); inp.placeholder = kind === 'link' ? 'https://… · #עוגן · עמוד.html' : 'שם-המחלקה';
      if (cur) inp.value = cur.getAttribute('href') || '';
      const back = () => { this.form = null; this.c.frame.contentWindow.focus(); if (this.e) this.e.focus({ preventScroll: true }); const s = this.c.doc.getSelection(); s.removeAllRanges(); s.addRange(saved); };
      const go = () => { const v = inp.value.trim(); back(); if (kind === 'link') { if (v && !SAFE_HREF.test(v)) { this.opt.note('כתובת של קישור אינה יכולה להיות קוד (javascript:)', true); this.drawBar(); return; } this.link(v); } else if (v) this.wrapClass(v); this.drawBar(); };
      const okB = mk('button', 'ed-tb', '✓'); okB.type = 'button'; okB.setAttribute('aria-label', 'אישור'); okB.addEventListener('click', go);
      const noB = mk('button', 'ed-tb', '✕'); noB.type = 'button'; noB.setAttribute('aria-label', 'ביטול'); noB.addEventListener('click', () => { back(); this.drawBar(); });
      const kids = [inp, okB];
      if (cur) { const rm = mk('button', 'ed-tb', 'הסר קישור'); rm.type = 'button'; rm.addEventListener('click', () => { back(); this.link(''); this.drawBar(); }); kids.push(rm); }
      kids.push(noB);
      inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } else if (e.key === 'Escape') { e.preventDefault(); back(); this.drawBar(); } e.stopPropagation(); });
      this.form = kind; this.bar.replaceChildren(...kids); this.bar.hidden = false; this.place(); inp.focus();
    }
    /**
     * טלפון: האלמנט הנערך (ליתר דיוק — הסמן שבו) נשאר מעל המקלדת ומעל הסרגל הצף. החלון הנראה (visualViewport) מתכווץ
     * כשהמקלדת עולה; אם הסמן מתחתיו — גוללים את הקנבס (ולא את המעטפת) בדיוק בהפרש.
     */
    keepVisible() {
      if (!this.active || !this.e || !(g.matchMedia && g.matchMedia('(max-width: 999px)').matches)) return;
      const c = this.c, win = c.frame.contentWindow, vv = g.visualViewport, s = c.scale || 1, vTop = vv ? vv.offsetTop : 0, vBot = vv ? vv.offsetTop + vv.height : g.innerHeight;
      let f = c.frame.getBoundingClientRect();
      if (f.top > vBot - 120 || f.bottom < vTop + 40) { c.host.scrollIntoView({ block: 'start' }); f = c.frame.getBoundingClientRect(); }   // המעטפת עצמה דחפה את הקנבס אל מתחת למקלדת
      const bottom = Math.min(f.bottom, vBot) - (this.bar.hidden ? 0 : this.bar.offsetHeight) - 8, top = Math.max(f.top, vTop) + 8;
      const sel = c.doc.getSelection();
      let r = sel && sel.rangeCount && this.e.contains(sel.focusNode) ? sel.getRangeAt(0).getBoundingClientRect() : null;
      if (!r || (!r.width && !r.height && !r.top)) r = this.e.getBoundingClientRect();
      const y1 = f.top + r.top * s, y2 = f.top + r.bottom * s;
      let dy = 0;
      if (y2 > bottom) dy = Math.min(y2 - bottom, y1 - top);               // לא גוללים את ראש האלמנט אל מעבר לראש החלון
      else if (y1 < top) dy = y1 - top;
      if (Math.abs(dy) > 1) { win.scrollBy(0, dy / s); c.draw(); this.place(); }
    }
    place() {
      if (this.bar.hidden || !this.active) return;
      const c = this.c, r = c.rectOf(this.n), H = c.host.clientHeight, W = c.host.clientWidth, bh = this.bar.offsetHeight || 40, bw = this.bar.offsetWidth || 300;
      if (g.matchMedia && g.matchMedia('(max-width: 999px)').matches) {          // טלפון: צמוד לתחתית החלון הנראה — מעל המקלדת
        const vv = g.visualViewport, hr = c.host.getBoundingClientRect(), bottom = vv ? vv.offsetTop + vv.height : g.innerHeight;
        this.bar.style.cssText = 'left:0;right:0;top:' + Math.max(0, Math.min(H - bh, bottom - hr.top - bh - 4)) + 'px;max-width:none';
        return;
      }
      let top = r ? r.top - bh - 22 : 4; if (top < 2) top = r ? Math.min(H - bh - 2, r.top + r.height + 6) : 2;
      const left = Math.max(2, Math.min(W - bw - 2, r ? r.left + r.width - bw : 2));
      this.bar.style.cssText = 'left:' + left + 'px;top:' + Math.max(2, top) + 'px';
    }
    /** כיוון הטקסט: תכונת dir על האלמנט. '' — אוטומטי (התכונה מוסרת). */
    dir(v) {
      const path = this.path; if (!this.flush(false)) return;
      const c = this.c, n = K.byPath(c.m, path); if (!n) return;
      this.opt.run('כיוון הטקסט · ' + c.label(n), m => (v ? K.html.setAttr(m, n, 'dir', v) : K.html.removeAttr(m, n, 'dir')));
      const n2 = K.byPath(c.m, path); if (n2) this.begin(n2, null, undefined, true);
    }
    /** "פסקה / פריט חדש": אלמנט אח אחרי הנערך, באותה תגית ובאותה מחלקה — ונכנסים לעריכה שלו. */
    sibling() {
      const path = this.path; if (!this.flush(false)) return;
      const c = this.c, n = K.byPath(c.m, path); if (!n || !n.parent) return;
      const cls = K.attrOf(n, 'class'), i = n.parent.kids.indexOf(n), par = n.parent, pp = K.pathOf(par);
      const ok = this.opt.run((n.tag === 'li' ? 'פריט חדש' : 'פסקה חדשה') + ' · ' + c.label(n), m => K.html.insert(m, par, i + 1, '<' + n.tag + (cls && cls.value ? ' class="' + escAttr(cls.value) + '"' : '') + '></' + n.tag + '>'));
      if (!ok) return;
      const fresh = K.byPath(c.m, pp.concat(i + 1)); if (fresh) this.begin(fresh);
    }
  }

  g.KolText = { TextEdit, plan, textPatch, units, emit, hostFor, blocker, editable, ownText, escText, escAttr, INLINE, SAFE_HREF };
})(typeof window !== 'undefined' ? window : globalThis);
