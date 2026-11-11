/**
 * העורך החזותי — מודל מקור ל-HTML ול-CSS (D3, שכבה א). נולד בספייק V0; מ-V1 הוא המודול הקבוע של העורך.
 *
 * הקובץ עצמו הוא מקור האמת: המודל מחזיק את הטקסט כמו שהוא, ועץ שבו לכל צומת יש טווח תווים בטקסט. פעולה אינה
 * משנה את העץ ואינה "כותבת את הקובץ מחדש" — היא מחזירה תיקוני טקסט ({from, to, text}), ואחרי החלתם מנתחים שוב.
 * לכן מה שלא נגעו בו לא משתנה, בהגדרה. אין DOM כאן: הקובץ רץ גם בדפדפן וגם ב-Node.
 *
 * תלוי ב-KolParsers (assets/vendor/spike-parsers.js): parse5 ל-HTML, css-tree ל-CSS.
 */
(function (g) {
  'use strict';
  const VOID = new Set('area base br col embed hr img input link meta source track wbr'.split(' '));
  const OPT_END = new Set('li p dt dd tr td th thead tbody tfoot option optgroup colgroup caption rt rp html head body'.split(' '));
  const BLOCK_PARENT = new Set('html body div section article main header footer nav aside ul ol dl table thead tbody tfoot tr form fieldset figure details'.split(' '));
  // שגיאות ניתוח שאינן "קוד שבור": המבנה שנוצר חד-משמעי, והטווחים נכונים.
  const BENIGN = new Set(['missing-doctype', 'non-conforming-doctype', 'non-void-html-element-start-tag-with-trailing-solidus',
    'missing-semicolon-after-character-reference', 'unknown-named-character-reference', 'abandoned-head-element-child']);
  const IMPLIED_OK = new Set('html head body tbody colgroup tr'.split(' '));   // אלמנטים שהדפדפן מוסיף במקום שבו הילדים שלהם כתובים
  const XHTML = 'http://www.w3.org/1999/xhtml';
  const WS = c => c === ' ' || c === '\t' || c === '\n' || c === '\r' || c === '\f';

  class SrcError extends Error { constructor(msg, code) { super(msg); this.name = 'SrcError'; this.code = code || 'rejected'; } }

  // ── תיקונים ───────────────────────────────────────────────
  function applyPatches(src, patches) {
    const ps = patches.map((p, i) => ({ from: p.from, to: p.to, text: p.text, i })).sort((a, b) => a.from - b.from || a.i - b.i);
    for (let i = 1; i < ps.length; i++) if (ps[i - 1].to > ps[i].from) throw new SrcError('תיקונים חופפים', 'overlap');
    let out = '', at = 0;
    for (const p of ps) { out += src.slice(at, p.from) + p.text; at = p.to; }
    return out + src.slice(at);
  }
  /** התיקונים ההפוכים: מוחלים על הטקסט החדש ומחזירים את הישן. */
  function invertPatches(src, patches) {
    const ps = patches.map((p, i) => ({ from: p.from, to: p.to, text: p.text, i })).sort((a, b) => a.from - b.from || a.i - b.i);
    let d = 0; const out = [];
    for (const p of ps) { out.push({ from: p.from + d, to: p.from + d + p.text.length, text: src.slice(p.from, p.to) }); d += p.text.length - (p.to - p.from); }
    return out;
  }
  const backWs = (src, at, min) => { while (at > min && WS(src[at - 1])) at--; return at; };
  const fwdWs = (src, at, max) => { while (at < max && WS(src[at])) at++; return at; };
  const hasNl = s => s.indexOf('\n') >= 0 || s.indexOf('\r') >= 0;
  const lineIndent = (src, at) => { let s = at; while (s > 0 && src[s - 1] !== '\n' && src[s - 1] !== '\r') s--; let e = s; while (src[e] === ' ' || src[e] === '\t') e++; return src.slice(s, e); };
  function indentUnit(src) {
    const m = /\n([ \t]+)\S/.exec(src);
    if (!m) return '  ';
    return m[1][0] === '\t' ? '\t' : m[1].length <= 4 ? m[1] : '  ';
  }

  // ══ HTML ══════════════════════════════════════════════════
  function parseHtml(src) {
    const bom = src.charCodeAt(0) === 0xFEFF ? 1 : 0;
    const errors = [];
    const doc = g.KolParsers.parseHtml(bom ? src.slice(1) : src, { sourceCodeLocationInfo: true, scriptingEnabled: false,
      onParseError(e) { errors.push({ code: e.code, at: e.startOffset + bom }); } });
    const m = { kind: 'html', src, bom, errors, nl: src.indexOf('\r\n') >= 0 ? '\r\n' : '\n', count: 0, located: 0, marks: 0, root: null, loose: [], doctypeText: '' };
    const build = (p5, parent) => buildNode(m, p5, parent, bom);
    const dt = (doc.childNodes || []).find(c => c.nodeName === '#documentType');
    if (dt && dt.sourceCodeLocation) m.doctypeText = src.slice(dt.sourceCodeLocation.startOffset + bom, dt.sourceCodeLocation.endOffset + bom);
    const html = (doc.childNodes || []).find(c => c.tagName);
    m.root = html ? build(html, null) : null;
    m.doctype = (doc.childNodes || []).some(c => c.nodeName === '#documentType');
    // אזורים שבורים: שגיאת ניתוח שאינה ברשימת הלא-מזיקות מסמנת את האלמנט העמוק ביותר שמכיל אותה.
    const mark = n => { n.broken = true; m.marks++; for (let p = n.parent; p; p = p.parent) p.hasBroken = true; };
    const deepest = (n, at) => {
      for (const k of n.kids) if (k.implied ? deepest(k, at) : (k.from <= at && at <= k.to)) return k.implied ? deepest(k, at) : (deepest(k, at) || k);
      return null;
    };
    if (m.root) {
      for (const e of errors) {
        if (BENIGN.has(e.code)) continue;
        const hit = deepest(m.root, e.at) || (!m.root.implied && m.root.from <= e.at && e.at <= m.root.to ? m.root : null);
        if (hit) { mark(hit); e.node = hit; } else m.loose.push(e);
      }
      walk(m.root, n => {
        if (n.implied || n.et || VOID.has(n.tag) || OPT_END.has(n.tag)) return;
        if (n.foreign && src.slice(n.st[1] - 2, n.st[1]) === '/>') return; // נסגר בעצמו — רק ב-SVG / MathML; <div/> ב-HTML נשאר פתוח
        mark(n);                                                          // תגית שחייבת סגירה ולא נסגרה
      });
    }
    m.lex = !m.root || lexOk([{ type: 'el', node: m.root }], -1, Infinity) >= 0;
    return m;
  }
  /** בונה צומת (ותת-עץ) מצומת של parse5; off — ההיסט שמוסיפים למיקומים (BOM, או מיקום הקטע בעדכון הדרגתי). */
  function buildNode(m, p5, parent, off) {
    const loc = p5.sourceCodeLocation;
    const n = { tag: p5.tagName, parent, kids: [], nodes: [], attrs: [], implied: !loc, foreign: !!p5.namespaceURI && p5.namespaceURI !== XHTML, broken: false, hasBroken: false, from: -1, to: -1, st: null, et: null };
    m.count++;
    if (loc) {
      m.located++;
      n.from = loc.startOffset + off; n.to = loc.endOffset + off;
      const st = loc.startTag || loc;
      n.st = [st.startOffset + off, st.endOffset + off];
      if (loc.endTag) n.et = [loc.endTag.startOffset + off, loc.endTag.endOffset + off];
      const al = loc.attrs || st.attrs || {};
      for (const a of p5.attrs) {
        const name = a.prefix ? a.prefix + ':' + a.name : a.name, l = al[name] || al[name.toLowerCase()] || al[a.name];   // parse5 מתקן אותיות ב-SVG (viewBox)
        n.attrs.push({ name, value: a.value, from: l ? l.startOffset + off : -1, to: l ? l.endOffset + off : -1 });
      }
    } else for (const a of p5.attrs) n.attrs.push({ name: a.name, value: a.value, from: -1, to: -1 });
    for (const c of p5kids(p5)) pushChild(m, n, c, off);
    return n;
  }
  const p5kids = p5 => (p5.tagName === 'template' && p5.content ? p5.content.childNodes : p5.childNodes) || [];
  function pushChild(m, n, c, off) {
    const cl = c.sourceCodeLocation;
    if (c.tagName) { const k = buildNode(m, c, n, off); n.kids.push(k); n.nodes.push({ type: 'el', node: k }); }
    else if (c.nodeName === '#text') n.nodes.push({ type: 'text', value: c.value, from: cl ? cl.startOffset + off : -1, to: cl ? cl.endOffset + off : -1 });
    else if (c.nodeName === '#comment') n.nodes.push({ type: 'comment', from: cl ? cl.startOffset + off : -1, to: cl ? cl.endOffset + off : -1 });
  }
  /**
   * "הקינון בעץ תואם לקינון בטקסט": כל ילד כתוב בין התגיות של ההורה שלו, והאחים בסדר שבו נכתבו ובלי חפיפה.
   * parse5 אינו מדווח על כל שגיאות בניית העץ (אימוץ אל מחוץ לטבלה, שכפול תגיות עיצוב) — זו הבדיקה שתופסת אותן,
   * והיא התנאי לעדכון הדרגתי. מחזיר את המיקום שאחרי האחרון, או ‎-1 כשהתנאי מופר.
   */
  function lexOk(nodes, pos, max) {
    for (const x of nodes) {
      if (x.type !== 'el') { if (x.from < pos || x.to > max || x.to < x.from) return -1; pos = x.to; continue; }   // גם טקסט "מאומץ" נתפס כאן
      const k = x.node;
      if (k.implied) { if (!IMPLIED_OK.has(k.tag)) return -1; pos = lexOk(k.nodes, pos, max); if (pos < 0) return -1; continue; }   // <p> שנוצר מ-</p> יתום אינו "במקום"
      if (k.from < pos || k.to > max || k.to < k.st[1]) return -1;
      const open = k.tag === 'html' || k.tag === 'body';                    // טקסט שאחרי </body> שייך ל-body
      if (lexOk(k.nodes, k.st[1], open ? Infinity : (k.et ? k.et[0] : k.to)) < 0) return -1;
      pos = k.to;
    }
    return pos;
  }
  function walk(n, fn) { fn(n); for (const k of n.kids) walk(k, fn); }
  function all(m) { const out = []; if (m.root) walk(m.root, n => out.push(n)); return out; }
  function pathOf(n) { const p = []; for (; n.parent; n = n.parent) p.unshift(n.parent.kids.indexOf(n)); return p; }
  function byPath(m, path) { let n = m.root; for (const i of path) { n = n && n.kids[i]; } return n || null; }
  const attrOf = (n, name) => n.attrs.find(a => a.name === name) || null;
  const classesOf = n => { const a = attrOf(n, 'class'); return a ? a.value.split(/[ \t\n\r\f]+/).filter(Boolean) : []; };
  /** בורר מינימלי: "#id", ".class", "tag", או "tag.class"; nth — הכמה מבין המתאימים (מ-0). */
  function q(m, sel, nth) {
    const mm = /^([a-z0-9-]*)(?:#([\w-]+))?(?:\.([\w-]+))?$/i.exec(sel);
    if (!mm) throw new Error('בורר לא נתמך: ' + sel);
    let i = 0, found = null;
    const visit = n => {
      if (found) return;
      if ((!mm[1] || n.tag === mm[1]) && (!mm[2] || (attrOf(n, 'id') || {}).value === mm[2]) && (!mm[3] || classesOf(n).includes(mm[3]))) { if (i++ === (nth || 0)) { found = n; return; } }
      for (const k of n.kids) visit(k);
    };
    if (m.root) visit(m.root);
    return found;
  }
  const isSimple = (m, n) => !n.implied && (!!n.et || VOID.has(n.tag) || (n.foreign && m.src.slice(n.st[1] - 2, n.st[1]) === '/>'));
  /** סוף האלמנט בקובץ. לאלמנט בלי תגית סגירה (li, p) — בלי הרווחים שבסופו: הם ההזחה של האח הבא. */
  function endOf(m, n) { return isSimple(m, n) ? n.to : backWs(m.src, n.to, n.st[1]); }
  /** תחילת הרווח שלפני האלמנט, כשהאלמנט בשורה משלו; אחרת — תחילת האלמנט. */
  function leadOf(m, n) {
    const min = n.parent && !n.parent.implied ? n.parent.st[1] : m.bom;
    const s = backWs(m.src, n.from, min);
    return hasNl(m.src.slice(s, n.from)) ? s : n.from;
  }
  const leadText = (m, n) => m.src.slice(leadOf(m, n), n.from);
  const outer = (m, n) => m.src.slice(n.from, endOf(m, n));
  const innerRange = (m, n) => [n.st[1], n.et ? n.et[0] : n.to];
  function lineOf(src, at) { let l = 1; for (let i = 0; i < at; i++) if (src.charCodeAt(i) === 10) l++; return l; }

  function guard(n, structural) {
    if (!n) throw new SrcError('האלמנט לא נמצא', 'missing');
    if (n.implied) throw new SrcError('<' + n.tag + '> נוסף בידי הדפדפן ואינו כתוב בקובץ', 'implied');
    for (let p = n; p; p = p.parent) if (p.broken) throw new SrcError('<' + n.tag + '> נמצא באזור שבור בקוד — מתקנים אותו בעורך הקוד', 'broken');
    if (structural && n.hasBroken) throw new SrcError('בתוך <' + n.tag + '> יש קוד שבור — אי אפשר להזיז, לשכפל או למחוק אותו עד שיתוקן', 'broken');
  }
  function inBroken(par) { for (let p = par; p; p = p.parent) if (p.broken) throw new SrcError('המכל נמצא באזור שבור בקוד', 'broken'); }
  const escText = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/ /g, '&nbsp;');
  const escAttr = (s, quote) => { s = s.replace(/&/g, '&amp;').replace(/ /g, '&nbsp;'); return quote === "'" ? s.replace(/'/g, '&#39;') : s.replace(/"/g, '&quot;'); };
  function attrParts(m, a) {
    const s = m.src.slice(a.from, a.to), eq = s.indexOf('=');
    if (eq < 0) return { hasValue: false };
    let i = eq + 1; while (i < s.length && WS(s[i])) i++;
    const c = s[i];
    if (c === '"' || c === "'") return { hasValue: true, quote: c, vfrom: a.from + i + 1, vto: a.to - 1 };
    return { hasValue: true, quote: '', vfrom: a.from + i, vto: a.to };
  }
  function quoteStyle(m, n) {
    for (let i = n.attrs.length - 1; i >= 0; i--) { const a = n.attrs[i]; if (a.from < 0) continue; const p = attrParts(m, a); if (p.hasValue) return p.quote; }
    return '"';
  }
  const UNQUOTED_OK = /^[^\s"'=<>`&]+$/;
  /** הטקסט של האלמנט כפי שהוא נקרא (מפוענח), בלי הרווחים שבקצוות — מה ש-setText מחליף. */
  function textOf(m, n) {
    const t = textTarget(m, n);
    return t.value.replace(/^[ \t\n\r\f]+|[ \t\n\r\f]+$/g, '');
  }
  function textTarget(m, n) {
    if (n.tag === 'script' || n.tag === 'style') throw new SrcError('טקסט של <' + n.tag + '> נערך בעורך הקוד', 'raw');
    const texts = n.nodes.filter(x => x.type === 'text' && /[^ \t\n\r\f]/.test(x.value));
    if (!n.kids.length) { const [a, b] = innerRange(m, n); return { from: a, to: b, value: n.nodes.filter(x => x.type === 'text').map(x => x.value).join('') }; }
    if (texts.length === 1) return texts[0];
    throw new SrcError('ב-<' + n.tag + '> יש כמה קטעי טקסט ואלמנטים — בוחרים את הקטע עצמו', 'mixed');
  }

  const H = {
    setText(m, n, text) {
      guard(n);
      const t = textTarget(m, n), a = fwdWs(m.src, t.from, t.to), b = Math.max(a, backWs(m.src, t.to, t.from));
      return { patches: [{ from: a, to: b, text: escText(text) }], muts: [{ t: 'inner', node: n }] };
    },
    /** value === null — תכונה בלי ערך. opt.before / opt.after — שם תכונה קיימת שלפניה או אחריה מוסיפים. */
    setAttr(m, n, name, value, opt) {
      guard(n);
      const a = attrOf(n, name), muts = [{ t: 'attrs', node: n }];
      if (a) {
        if (a.from < 0) throw new SrcError('לתכונה אין מיקום בקובץ', 'broken');
        const p = attrParts(m, a);
        if (value === null) return { patches: p.hasValue ? [{ from: a.from + name.length, to: a.to, text: '' }] : [], muts };
        if (!p.hasValue) return { patches: [{ from: a.to, to: a.to, text: '="' + escAttr(value, '"') + '"' }], muts };
        if (p.quote) return { patches: [{ from: p.vfrom, to: p.vto, text: escAttr(value, p.quote) }], muts };
        return { patches: [{ from: p.vfrom, to: p.vto, text: UNQUOTED_OK.test(value) ? value : '"' + escAttr(value, '"') + '"' }], muts };
      }
      let qs = quoteStyle(m, n);
      if (value !== null && qs === '' && !UNQUOTED_OK.test(value)) qs = '"';
      const txt = name + (value === null ? '' : '=' + qs + escAttr(value, qs) + qs);
      const ref = opt && opt.before ? attrOf(n, opt.before) : null;
      if (ref && ref.from >= 0) return { patches: [{ from: ref.from, to: ref.from, text: txt + ' ' }], muts };
      const aft = opt && opt.after ? attrOf(n, opt.after) : null;
      if (aft && aft.from >= 0) return { patches: [{ from: aft.to, to: aft.to, text: ' ' + txt }], muts };
      const located = n.attrs.filter(x => x.from >= 0);
      const at = located.length ? located[located.length - 1].to : n.st[0] + 1 + n.tag.length;
      return { patches: [{ from: at, to: at, text: ' ' + txt }], muts };
    },
    removeAttr(m, n, name) {
      guard(n);
      const a = attrOf(n, name);
      if (!a) throw new SrcError('אין תכונה כזאת', 'noop');
      return { patches: [{ from: backWs(m.src, a.from, n.st[0]), to: a.to, text: '' }], muts: [{ t: 'attrs', node: n }] };
    },
    addClass(m, n, cls) {
      guard(n);
      const a = attrOf(n, 'class');
      if (!a) return H.setAttr(m, n, 'class', cls);
      if (classesOf(n).includes(cls)) throw new SrcError('המחלקה כבר קיימת', 'noop');
      const p = attrParts(m, a), muts = [{ t: 'attrs', node: n }];
      if (!p.hasValue) return H.setAttr(m, n, 'class', cls);
      const cur = m.src.slice(p.vfrom, p.vto);
      if (!p.quote) return { patches: [{ from: p.vfrom, to: p.vto, text: '"' + cur + ' ' + cls + '"' }], muts };
      const e = Math.max(p.vfrom, backWs(m.src, p.vto, p.vfrom));
      return { patches: [{ from: e, to: e, text: (e > p.vfrom ? ' ' : '') + cls }], muts };
    },
    removeClass(m, n, cls) {
      guard(n);
      const a = attrOf(n, 'class'), list = classesOf(n);
      if (!a || !list.includes(cls)) throw new SrcError('אין מחלקה כזאת', 'noop');
      if (list.length === 1) return H.removeAttr(m, n, 'class');
      const p = attrParts(m, a), raw = m.src.slice(p.vfrom, p.vto), re = /[^ \t\n\r\f]+/g, toks = [];
      for (let x; (x = re.exec(raw));) toks.push([x.index, x.index + x[0].length, x[0]]);
      const i = toks.findIndex(t => t[2] === cls);
      if (i < 0) throw new SrcError('המחלקה כתובה בקובץ בצורה שאינה ניתנת לעריכה נקודתית', 'broken');
      const from = i ? toks[i - 1][1] : toks[i][0], to = i ? toks[i][1] : toks[i + 1][0];
      return { patches: [{ from: p.vfrom + from, to: p.vfrom + to, text: '' }], muts: [{ t: 'attrs', node: n }] };
    },
    remove(m, n) {
      guard(n, true);
      const par = n.parent;
      if (par && !par.implied && par.et && par.kids.length === 1 && !par.nodes.some(x => x.type === 'comment' || (x.type === 'text' && /[^ \t\n\r\f]/.test(x.value)))) {
        const [a, b] = innerRange(m, par);                                 // ילד יחיד: המכל נשאר ריק, בלי שורות ריקות
        return { patches: [{ from: a, to: b, text: '' }], muts: [{ t: 'inner', node: par }] };
      }
      const lead = leadOf(m, n);
      return { patches: [{ from: lead, to: endOf(m, n), text: '' }], muts: fine(m, par) ? [{ t: 'del', node: n, lead: lead < n.from }] : coarse(par) };
    },
    /** מוסיף HTML כילד במקום index (מספר בין האלמנטים-הילדים); בלי index — בסוף. */
    insert(m, par, index, html) {
      if (!par) throw new SrcError('המכל לא נמצא', 'missing');
      inBroken(par);
      return insertAt(m, par, par.kids, index, html, null);
    },
    duplicate(m, n) {
      guard(n, true);
      const e = endOf(m, n), txt = leadText(m, n) + outer(m, n);
      return { patches: [{ from: e, to: e, text: txt }], muts: fine(m, n.parent) ? [{ t: 'after', node: n, html: txt }] : coarse(n.parent) };
    },
    /** מזיז את n להיות הילד ה-index של par (המספור — בלי n עצמו). */
    move(m, n, par, index) {
      guard(n, true);
      if (!par) throw new SrcError('המכל לא נמצא', 'missing');
      for (let p = par; p; p = p.parent) if (p === n) throw new SrcError('אי אפשר להזיז אלמנט לתוך עצמו', 'cycle');
      inBroken(par);
      const sibs = par.kids.filter(k => k !== n);
      if (index == null || index > sibs.length) index = sibs.length;
      if (par === n.parent && par.kids.indexOf(n) === index) throw new SrcError('האלמנט כבר שם', 'noop');
      const del = H.remove(m, n), ins = insertAt(m, par, sibs, index, outer(m, n), n);
      if (del.muts[0].t === 'inner' && del.muts[0].node === n.parent && par !== n.parent) {
        // המכל הישן מתרוקן: שני השינויים נפרדים
        return { patches: del.patches.concat(ins.patches), muts: ins.muts.concat(del.muts) };
      }
      const exact = del.muts[0].t === 'del' && (ins.muts[0].t === 'before' || ins.muts[0].t === 'after');
      return { patches: del.patches.concat(ins.patches), muts: exact ? del.muts.concat(ins.muts) : coarse(common(n.parent, par)) };
    },
    wrap(m, n, tag, attrs) {
      guard(n, true);
      const open = '<' + tag + Object.entries(attrs || {}).map(([k, v]) => ' ' + k + '="' + escAttr(v, '"') + '"').join('') + '>', close = '</' + tag + '>', e = endOf(m, n);
      return { patches: [{ from: n.from, to: n.from, text: open }, { from: e, to: e, text: close }],
        muts: fine(m, n.parent) ? [{ t: 'swap', node: n, html: open + outer(m, n) + close }] : coarse(n.parent) };
    },
    unwrap(m, n) {
      guard(n, true);
      if (!n.et) throw new SrcError('לאלמנט אין תגית סגירה כתובה', 'broken');
      return { patches: [{ from: n.st[0], to: n.st[1], text: '' }, { from: n.et[0], to: n.et[1], text: '' }],
        muts: fine(m, n.parent) ? [{ t: 'swap', node: n, html: m.src.slice(n.st[1], n.et[0]) }] : coarse(n.parent) };
    },
    retag(m, n, tag) {
      guard(n, true);
      const ps = [{ from: n.st[0] + 1, to: n.st[0] + 1 + n.tag.length, text: tag }];
      if (n.et) ps.push({ from: n.et[0] + 2, to: n.et[0] + 2 + n.tag.length, text: tag });
      const html = '<' + tag + m.src.slice(n.st[0] + 1 + n.tag.length, n.et ? n.et[0] : endOf(m, n)) + (n.et ? '</' + tag + '>' : '');
      return { patches: ps, muts: fine(m, n.parent) ? [{ t: 'swap', node: n, html }] : coarse(n.parent) };
    },
  };
  /** עדכון נקודתי של הקנבס אפשרי כשכל הילדים של המכל סגורים — אחרת רווחי ההזחה יושבים ב-DOM בתוך האח הקודם. */
  function fine(m, par) { return !!par && par.kids.every(k => isSimple(m, k)); }
  function coarse(n) { for (; n; n = n.parent) if (!n.implied && n.et) return [{ t: 'inner', node: n }]; return [{ t: 'full' }]; }
  function common(a, b) { const seen = new Set(); for (let p = a; p; p = p.parent) seen.add(p); for (let p = b; p; p = p.parent) if (seen.has(p)) return p; return null; }
  function insertAt(m, par, sibs, index, html, moving) {
    if (index == null || index > sibs.length) index = sibs.length;
    const ok = fine(m, par) && (!moving || isSimple(m, moving));
    if (index < sibs.length) {
      const ref = sibs[index];
      if (ref.implied) throw new SrcError('אין מיקום בקובץ להוספה לפני אלמנט שהדפדפן הוסיף', 'implied');
      // ההזחה נלמדת מהאח הקודם (האלמנט החדש מתיישר איתו); בלי אח קודם — מהאח שלפניו מוסיפים
      const at = leadOf(m, ref), lt = index > 0 && !sibs[index - 1].implied ? leadText(m, sibs[index - 1]) : leadText(m, ref);
      return { patches: [{ from: at, to: at, text: lt + html }], muts: ok ? [{ t: 'before', node: ref, html: lt + html, lead: at < ref.from }] : coarse(par) };
    }
    if (sibs.length) {
      const last = sibs[sibs.length - 1];
      if (last.implied) throw new SrcError('אין מיקום בקובץ להוספה אחרי אלמנט שהדפדפן הוסיף', 'implied');
      const at = endOf(m, last), lt = leadText(m, last);
      return { patches: [{ from: at, to: at, text: lt + html }], muts: ok ? [{ t: 'after', node: last, html: lt + html }] : coarse(par) };
    }
    if (par.implied || !par.et) throw new SrcError('למכל אין מיקום כתוב בקובץ', 'implied');
    const [a, b] = innerRange(m, par), cur = m.src.slice(a, b);
    if (/[^ \t\n\r\f]/.test(cur)) { const e = backWs(m.src, b, a); return { patches: [{ from: e, to: e, text: html }], muts: [{ t: 'inner', node: par }] }; }
    const ind = lineIndent(m.src, par.st[0]);
    const txt = BLOCK_PARENT.has(par.tag) ? m.nl + ind + indentUnit(m.src) + html + m.nl + ind : html;
    return { patches: [{ from: a, to: b, text: txt }], muts: [{ t: 'inner', node: par }] };
  }

  // ══ CSS ═══════════════════════════════════════════════════
  function parseCss(src) {
    const bom = src.charCodeAt(0) === 0xFEFF ? 1 : 0;
    const errors = [];
    const ast = g.KolParsers.parseCss(bom ? src.slice(1) : src, { positions: true, onParseError(e) { errors.push({ code: e.message, at: (e.offset || 0) + bom }); } });
    const m = { kind: 'css', src, bom, errors, nl: src.indexOf('\r\n') >= 0 ? '\r\n' : '\n', root: null, count: 0 };
    const o = l => l.offset + bom;
    const build = (a, parent) => {
      const n = { type: a.type === 'Rule' ? 'rule' : a.type === 'Atrule' ? 'at' : 'sheet', parent, from: a.loc ? o(a.loc.start) : bom, to: a.loc ? o(a.loc.end) : src.length,
        kids: [], decls: [], broken: false, hasBroken: false, name: a.name || '', sel: '', selFrom: -1, selTo: -1, bFrom: -1, bTo: -1 };
      m.count++;
      if (a.prelude && a.prelude.loc) { n.selFrom = o(a.prelude.loc.start); n.selTo = backWs(src, o(a.prelude.loc.end), n.selFrom); n.sel = src.slice(n.selFrom, n.selTo); }
      const block = a.type === 'StyleSheet' ? a : a.block;
      if (a.block) { n.bFrom = o(a.block.loc.start); n.bTo = o(a.block.loc.end); }
      if (block && block.children) block.children.forEach(c => {
        if (c.type === 'Rule' || c.type === 'Atrule') n.kids.push(build(c, n));
        else if (c.type === 'Declaration') n.decls.push(decl(c));
        else if (c.type === 'Raw' && /\S/.test(c.value)) n.raw = true;
      });
      return n;
    };
    const decl = c => {
      const from = o(c.loc.start), colon = src.indexOf(':', from), to = backWs(src, o(c.loc.end), colon + 1);   // css-tree כולל את הרווח שאחרי הערך
      const raw = src.slice(colon + 1, to), imp = /\s*!\s*important\s*$/i.exec(raw);
      const valTo = to - (imp ? imp[0].length : 0), valFrom = Math.min(valTo, fwdWs(src, colon + 1, to));
      const p = fwdWs(src, to, src.length);
      return { prop: src.slice(from, backWs(src, colon, from)), from, to, colon, valFrom, valTo, value: src.slice(valFrom, valTo), important: !!imp, semi: src[p] === ';' ? p + 1 : -1, broken: false };
    };
    m.root = build(ast, null);
    const mark = n => { n.broken = true; for (let p = n.parent; p; p = p.parent) p.hasBroken = true; };
    const deepest = (n, at) => { for (const k of n.kids) if (k.from <= at && at <= k.to) return deepest(k, at) || k; return null; };
    for (const e of errors) { const hit = deepest(m.root, e.at); if (hit) { mark(hit); e.node = hit; } }
    const visit = n => { if (n.bFrom >= 0 && src[n.bTo - 1] !== '}') mark(n); if (n.raw && n !== m.root) mark(n); n.kids.forEach(visit); };
    visit(m.root);
    return m;
  }
  function cssAll(m) { const out = []; const v = n => { if (n !== m.root) out.push(n); n.kids.forEach(v); }; v(m.root); return out; }
  /** כלל לפי בורר (טקסט מדויק אחרי צמצום רווחים); media — תנאי ה-@media שבתוכו, או null לרמה העליונה. */
  function cssFind(m, sel, media) {
    const norm = s => s.replace(/\s+/g, ' ').trim();
    return cssAll(m).find(n => n.type === 'rule' && norm(n.sel) === norm(sel) && (media == null ? n.parent === m.root : n.parent.type === 'at' && norm(n.parent.sel) === norm(media))) || null;
  }
  const cssAt = (m, name, prelude) => cssAll(m).find(n => n.type === 'at' && n.name === name && n.sel.replace(/\s+/g, ' ').trim() === prelude) || null;
  function cssGuard(n) {
    if (!n) throw new SrcError('הכלל לא נמצא', 'missing');
    for (let p = n; p; p = p.parent) if (p.broken) throw new SrcError('הכלל נמצא באזור שבור ב-CSS — מתקנים אותו בעורך הקוד', 'broken');
  }
  const cssLead = (m, n) => backWs(m.src, n.from, n.parent && n.parent.bFrom >= 0 ? n.parent.bFrom + 1 : m.bom);
  const declEnd = d => d.semi >= 0 ? d.semi : d.to;
  function ruleText(m, tpl, sel, decls) {
    let gap = ' ', lead0 = m.nl + '  ', leadN = null, between = ': ', semi = true, closing = m.nl;
    if (tpl && tpl.decls.length) {
      const d0 = tpl.decls[0], dl = tpl.decls[tpl.decls.length - 1];
      gap = m.src.slice(tpl.selTo, tpl.bFrom); lead0 = m.src.slice(tpl.bFrom + 1, d0.from);
      leadN = tpl.decls[1] ? m.src.slice(declEnd(d0), tpl.decls[1].from) : null;
      between = m.src.slice(d0.from + d0.prop.length, d0.valFrom); semi = dl.semi >= 0; closing = m.src.slice(declEnd(dl), tpl.bTo - 1);
    }
    if (leadN === null) leadN = lead0;
    return sel + gap + '{' + decls.map(([p, v], i) => (i ? leadN : lead0) + p + between + v + (i < decls.length - 1 || semi ? ';' : '')).join('') + closing + '}';
  }
  function appendTo(m, par, text, index) {
    if (index != null && index < par.kids.length) { const ref = par.kids[index], at = cssLead(m, ref); return [{ from: at, to: at, text: m.src.slice(at, ref.from) + text }]; }
    if (par.kids.length) { const last = par.kids[par.kids.length - 1]; if (last.broken) throw new SrcError('הכלל האחרון בקובץ שבור — כלל חדש אחריו היה נבלע בו', 'broken'); let lt = m.src.slice(cssLead(m, last), last.from); if (!lt) lt = m.nl; return [{ from: last.to, to: last.to, text: lt + text }]; }
    if (par === m.root) { const e = backWs(m.src, m.src.length, m.bom); return [{ from: e, to: e, text: (e > m.bom ? m.nl : '') + text }]; }
    throw new SrcError('הוספה לבלוק ריק אינה ממומשת בספייק', 'unsupported');
  }
  const C = {
    setDecl(m, rule, prop, value) {
      cssGuard(rule);
      const d = rule.decls.filter(x => x.prop === prop).pop();
      if (!d) return C.addDecl(m, rule, prop, value);
      return { patches: [{ from: d.valFrom, to: d.valTo, text: value }] };
    },
    /** opt.index — המקום בין ההצהרות; בלי — בסוף. הסגנון (רווחים, נקודה-פסיק, הזחה) נלמד מההצהרות השכנות. */
    addDecl(m, rule, prop, value, opt) {
      cssGuard(rule);
      if (rule.bFrom < 0) throw new SrcError('לכלל אין בלוק', 'unsupported');
      const ds = rule.decls, idx = opt && opt.index != null ? opt.index : ds.length;
      if (!ds.length) {
        const inner = m.src.slice(rule.bFrom + 1, rule.bTo - 1);
        const txt = hasNl(inner) ? m.nl + lineIndent(m.src, rule.from) + indentUnit(m.src) + prop + ': ' + value + ';' : ' ' + prop + ': ' + value + ';' + (inner ? '' : ' ');
        return { patches: [{ from: rule.bFrom + 1, to: rule.bFrom + 1, text: txt }] };
      }
      const L = ds[ds.length - 1], between = m.src.slice(L.from + L.prop.length, L.valFrom);
      if (idx < ds.length) {
        const ref = ds[idx], at = backWs(m.src, ref.from, rule.bFrom + 1);
        return { patches: [{ from: at, to: at, text: m.src.slice(at, ref.from) + prop + between + value + ';' }] };
      }
      const lead = m.src.slice(backWs(m.src, L.from, rule.bFrom + 1), L.from);
      if (L.semi >= 0) return { patches: [{ from: L.semi, to: L.semi, text: lead + prop + between + value + ';' }] };
      return { patches: [{ from: L.to, to: L.to, text: ';' + lead + prop + between + value }] };
    },
    removeDecl(m, rule, prop) {
      cssGuard(rule);
      const ds = rule.decls, d = ds.filter(x => x.prop === prop).pop();
      if (!d) throw new SrcError('אין הצהרה כזאת', 'noop');
      let from = backWs(m.src, d.from, rule.bFrom + 1);
      // סגנון "בלי נקודה-פסיק אחרי האחרונה": מסירים גם את זו שלפניה, כדי שהסגנון יישמר
      if (d.semi < 0 && ds.indexOf(d) === ds.length - 1 && ds.length > 1 && m.src[from - 1] === ';') from--;
      return { patches: [{ from, to: declEnd(d), text: '' }] };
    },
    /** par — הגיליון (m.root) או @media. decls — [[מאפיין, ערך], …]. */
    addRule(m, par, sel, decls, opt) {
      if (par !== m.root) cssGuard(par);
      const okT = k => k && k.type === 'rule' && k.decls.length && !k.broken, i = opt && opt.index != null ? opt.index : par.kids.length;
      const tpl = (okT(par.kids[i - 1]) && par.kids[i - 1]) || (okT(par.kids[i]) && par.kids[i]) || par.kids.filter(okT).pop() || cssAll(m).filter(k => k.type === 'rule' && k.decls.length && !k.broken).pop();
      return { patches: appendTo(m, par, ruleText(m, tpl, sel, decls), opt && opt.index) };
    },
    addMedia(m, prelude, rules) {
      const T = m.root.kids.filter(k => k.type === 'at' && k.name === 'media' && k.kids.length && !k.broken && !k.hasBroken).pop();
      let gap = ' ', lead = m.nl + indentUnit(m.src), closing = m.nl, tpl = null;
      if (T) { gap = m.src.slice(T.selTo, T.bFrom); lead = m.src.slice(T.bFrom + 1, T.kids[0].from); closing = m.src.slice(T.kids[T.kids.length - 1].to, T.bTo - 1); tpl = T.kids.filter(k => k.type === 'rule' && k.decls.length).pop(); }
      if (!tpl) tpl = cssAll(m).filter(k => k.type === 'rule' && k.decls.length && !k.broken).pop();
      const text = '@media ' + prelude + gap + '{' + rules.map(r => lead + ruleText(m, tpl, r.sel, r.decls)).join('') + closing + '}';
      return { patches: appendTo(m, m.root, text) };
    },
    removeNode(m, n) {
      cssGuard(n);
      if (n.hasBroken) throw new SrcError('בתוך הכלל יש קוד שבור', 'broken');
      return { patches: [{ from: cssLead(m, n), to: n.to, text: '' }] };
    },
  };

  // ══ עדכון הדרגתי (V1) ═════════════════════════════════════
  // אחרי תיקון לא מנתחים את כל הקובץ: מוצאים את המכל הקטן ביותר שהתיקון כולו בתוכו, מנתחים מחדש רק את הילדים
  // שנגעו בהם — בתוך "מסמך סינתטי" שמשחזר את שרשרת האבות (כדי שמצב המנתח יהיה זהה לזה של הקובץ המלא) — ומזיזים את
  // הטווחים של כל מה שבא אחריהם. כל ספק ← ניתוח מלא (נפילה בטוחה). התנאי לכל העניין: קובץ בלי שגיאות ניתוח.
  const NL_DROP = new Set(['pre', 'textarea', 'listing']);                 // שורה חדשה מיד אחרי תגית הפתיחה נבלעת
  const GAP = 2000;                                                        // תיקונים קרובים מזה מטופלים כאזור אחד
  const p5simple = (syn, c) => { const l = c.sourceCodeLocation; return !l || !!l.endTag || VOID.has(c.tagName) || c.namespaceURI !== XHTML && syn.slice((l.startTag || l).endOffset - 2, (l.startTag || l).endOffset) === '/>'; };

  /**
   * מחיל תיקונים על המודל. מחזיר { model, mode: 'inc' | 'full', why, regions }.
   * 'inc' — המודל עודכן במקומו (צמתים שלא נגעו בהם שומרים על זהותם), ו-regions מתאר מה הוחלף.
   * 'full' — הוחזר מודל חדש מניתוח מלא; המודל הישן אינו שמיש עוד.
   */
  function update(m, patches) {
    const next = applyPatches(m.src, patches);
    const full = why => ({ model: parseHtml(next), mode: 'full', why, regions: null });
    if (m.kind !== 'html' || !m.root) return full('אין שורש');
    if (m.marks || m.loose.length || !m.errors.every(e => BENIGN.has(e.code))) return full('בקובץ יש קוד שבור');
    if (!m.lex) return full('הקינון בעץ אינו תואם לטקסט');
    const ps = patches.filter(p => p.to > p.from || p.text).map((p, i) => ({ from: p.from, to: p.to, text: p.text, i })).sort((a, b) => a.from - b.from || a.i - b.i);
    if (!ps.length) return { model: m, mode: 'inc', why: '', regions: [] };
    const clusters = [];
    for (const p of ps) { const l = clusters[clusters.length - 1]; if (l && p.from - l[l.length - 1].to < GAP) l.push(p); else clusters.push([p]); }
    const regions = [];
    try {
      for (let c = clusters.length - 1; c >= 0; c--) {                      // מהסוף להתחלה: המיקומים שלפני נשארים תקפים
        const r = updateRegion(m, clusters[c], false);
        if (typeof r === 'string') return full(r);
        regions.push(r);
      }
    } catch (e) { if (e instanceof SrcError) throw e; return full('חריגה: ' + (e && e.message || e)); }
    if (m.src !== next) return full('הטקסט אינו תואם');
    return { model: m, mode: 'inc', why: '', regions };
  }
  /** מחזיר תיאור אזור, או מחרוזת — הסיבה לנפילה לניתוח מלא. המודל אינו משתנה לפני שכל הבדיקות עברו. */
  function updateRegion(m, ps, forceAll) {
    const src = m.src, lo = ps[0].from, hi = ps[ps.length - 1].to;
    // 1. המכל: האלמנט העמוק ביותר, עם תגית סגירה כתובה, שהתיקון כולו בין התגיות שלו
    let P = null;
    const find = n => {
      for (const k of n.kids) {
        if (k.implied) { find(k); continue; }
        if (k.from <= lo && hi <= k.to) { if (k.et && k.st[1] <= lo && hi <= k.et[0] && k.tag !== 'html') P = k; find(k); return; }
      }
    };
    if (!m.root.implied && m.root.from <= lo && hi <= m.root.to) find(m.root); else if (m.root.implied) find(m.root);
    if (!P) return 'אין מכל סגור סביב התיקון';
    // 2. הילדים שנגעו בהם, בתוספת טקסט צמוד (צמתי טקסט מתמזגים) ואח קודם בלי תגית סגירה (הוא נסגר במה שבא אחריו)
    const nodes = P.nodes, rng = x => x.type === 'el' ? (x.node.implied ? null : [x.node.from, x.node.to]) : (x.from < 0 ? null : [x.from, x.to]);
    let all = forceAll || NL_DROP.has(P.tag), i0 = -1, i1 = -1;
    if (!all) {
      for (let i = 0; i < nodes.length; i++) {
        const r = rng(nodes[i]);
        if (!r) { all = true; break; }
        if (r[0] <= hi && r[1] >= lo) { if (i0 < 0) i0 = i; i1 = i; }
      }
      if (!all && i0 < 0 && nodes.length) all = true;
    }
    if (!all && nodes.length) {
      while (i0 > 0 && nodes[i0 - 1].type === 'text') i0--;
      if (i0 > 0 && nodes[i0 - 1].type === 'el' && !isSimple(m, nodes[i0 - 1].node)) i0--;
      while (i1 < nodes.length - 1 && nodes[i1 + 1].type === 'text') i1++;
    }
    let a, b;
    if (all || !nodes.length) { a = P.st[1]; b = P.et[0]; i0 = 0; i1 = nodes.length - 1; }
    else { a = rng(nodes[i0])[0]; b = rng(nodes[i1])[1]; if (a < P.st[1] || b > P.et[0] || a > lo || b < hi) return forceAll ? 'טווח הילדים חורג מהמכל' : updateRegion(m, ps, true); }
    if (all && P.tag === 'body') return 'שינוי בכל תוכן ה-body';              // טקסט שאחרי </body> שייך ל-body ואינו בין התגיות
    // </body> או </html> בתוך הקטע (לפני או אחרי התיקון) קובעים את הטווח של body / html, שמחוץ לקטע
    for (let n = P; n; n = n.parent) if ((n.tag === 'body' || n.tag === 'html') && n.et && n.et[0] >= a && n.et[0] < b) return '</' + n.tag + '> בתוך הקטע';
    if (/<\/(body|html)[\s>\/]/i.test(src.slice(a, b)) || ps.some(p => /<\/(body|html)[\s>\/]/i.test(p.text))) return 'תגית סגירה של body / html בתוך הקטע';
    const whole = i0 === 0 && i1 === nodes.length - 1 && a === P.st[1] && b === P.et[0];
    // 3. המסמך הסינתטי: doctype, תגיות הפתיחה של שרשרת האבות, הקטע החדש, ותגיות הסגירה
    const chain = []; for (let n = P; n; n = n.parent) chain.unshift(n);
    let pre = m.doctypeText; const starts = [];
    for (const n of chain) { starts.push(pre.length); pre += n.implied ? '<' + n.tag + '>' : src.slice(n.st[0], n.st[1]); }
    let suf = ''; for (let i = chain.length - 1; i >= 0; i--) if (chain[i].et) suf += src.slice(chain[i].et[0], chain[i].et[1]);
    const patched = applyPatches(src.slice(a, b), ps.map(p => ({ from: p.from - a, to: p.to - a, text: p.text })));
    const syn = pre + patched + suf, errs = [];
    const doc = g.KolParsers.parseHtml(syn, { sourceCodeLocationInfo: true, scriptingEnabled: false, onParseError(e) { errs.push({ code: e.code, at: e.startOffset }); } });
    const end = pre.length + patched.length, off = a - pre.length, added = [];
    for (const e of errs) {
      if (!BENIGN.has(e.code)) return 'שגיאת ניתוח בקטע: ' + e.code;
      if (e.at >= pre.length && e.at < end) added.push({ code: e.code, at: e.at + off });
    }
    let cur = { childNodes: doc.childNodes, tagName: '' };
    for (let i = 0; i < chain.length; i++) {
      // כל ילד אחר — משהו "ברח" מהקטע אל אחד האבות (אימוץ מחוץ לטבלה, <p> שנוצר מ-</p> יתום). רק head / body של html מותרים
      const hit = p5kids(cur).filter(c => c.nodeName !== '#documentType' && !(cur.tagName === 'html' && !c.sourceCodeLocation && (c.tagName === 'head' || c.tagName === 'body')));
      if (hit.length !== 1 || hit[0].tagName !== chain[i].tag || !hit[0].sourceCodeLocation || hit[0].sourceCodeLocation.startOffset !== starts[i]) return 'שרשרת האבות לא שוחזרה ב-<' + chain[i].tag + '>';
      cur = hit[0];
    }
    const cl = cur.sourceCodeLocation;
    if (!cl.endTag || cl.endTag.startOffset !== end || (cl.startTag || cl).endOffset !== pre.length) return 'הקטע פרץ את גבולות המכל';
    const fresh = p5kids(cur);
    // 4. הקצוות: אלמנט אחרון בלי תגית סגירה היה בולע את מה שבא אחריו; ושורה חדשה בתחילת הקטע אינה בתחילת המכל
    if (!whole) {
      const last = fresh[fresh.length - 1];
      if (last && last.tagName && !p5simple(syn, last)) return forceAll ? 'אלמנט אחרון פתוח' : updateRegion(m, ps, true);
    }
    if (!NL_DROP.has(P.tag)) {                                              // הילדים מכסים את הקטע כולו, ברצף
      let at = pre.length;
      for (const c of fresh) { const l = c.sourceCodeLocation; if (!l) { at = -1; break; } if (l.startOffset !== at) return 'הקטע אינו מכוסה ברצף'; at = l.endOffset; }
      if (at >= 0 && at !== end) return 'הקטע אינו מכוסה ברצף';
    }
    // 5. הכול נבדק — מחליפים. מכאן אין נפילה.
    const tmp = { count: 0, located: 0 }, holder = { kids: [], nodes: [] };
    for (const c of fresh) pushChild(tmp, holder, c, off);
    let bad = false;
    for (const k of holder.kids) walk(k, n => { if (!(n.implied || n.et || VOID.has(n.tag) || OPT_END.has(n.tag) || (n.foreign && syn.slice(n.st[1] - off - 2, n.st[1] - off) === '/>'))) bad = true; });
    if (bad) return 'תגית שחייבת סגירה ולא נסגרה';
    if (lexOk(holder.nodes, a, a + patched.length) < 0) return 'הקינון בקטע אינו תואם לטקסט';
    for (const k of holder.kids) k.parent = P;
    const d = patched.length - (b - a), oldCount = nodes.length ? i1 - i0 + 1 : 0, removed = [];
    let gone = 0, goneLoc = 0;
    const after = nodes.slice(i0 + oldCount);
    for (let i = i0; i < i0 + oldCount; i++) if (nodes[i].type === 'el') { removed.push(nodes[i].node); walk(nodes[i].node, n => { gone++; if (!n.implied) goneLoc++; }); }
    P.nodes = nodes.slice(0, i0).concat(holder.nodes, after);
    P.kids = []; for (const x of P.nodes) if (x.type === 'el') P.kids.push(x.node);
    m.count += tmp.count - gone; m.located += tmp.located - goneLoc;
    if (d) {
      const shiftEl = n => walk(n, x => {
        if (x.implied) { shiftLoose(x); return; }
        x.from += d; x.to += d; x.st[0] += d; x.st[1] += d; if (x.et) { x.et[0] += d; x.et[1] += d; }
        for (const t of x.attrs) if (t.from >= 0) { t.from += d; t.to += d; }
        shiftLoose(x);
      });
      const shiftLoose = x => { for (const t of x.nodes) if (t.type !== 'el' && t.from >= 0) { t.from += d; t.to += d; } };
      const shiftEntry = x => { if (x.type === 'el') shiftEl(x.node); else if (x.from >= 0) { x.from += d; x.to += d; } };
      after.forEach(shiftEntry);
      for (let n = P; n; n = n.parent) {
        if (!n.implied) { n.to += d; if (n.et) { n.et[0] += d; n.et[1] += d; } }
        if (n.parent) { const sib = n.parent.nodes; let at = sib.length; for (let i = 0; i < sib.length; i++) if (sib[i].type === 'el' && sib[i].node === n) { at = i; break; } for (let i = at + 1; i < sib.length; i++) shiftEntry(sib[i]); }
      }
    }
    const kept = [];
    for (const e of m.errors) { if (e.at >= a && e.at < b) continue; if (e.at >= b) e.at += d; kept.push(e); }
    m.errors = kept.concat(added).sort((x, y) => x.at - y.at);
    m.src = src.slice(0, a) + patched + src.slice(b);
    m.nl = m.nl === '\r\n' && patched.indexOf('\r\n') < 0 ? (m.src.indexOf('\r\n') >= 0 ? '\r\n' : '\n') : (patched.indexOf('\r\n') >= 0 ? '\r\n' : m.nl);
    return { parent: P, index: i0, oldCount, newCount: holder.nodes.length, html: patched, whole, removed, from: a, to: a + patched.length };
  }
  /** השוואה מלאה בין שני מודלים — אותם צמתים, אותם טווחים. מחזיר null כשזהים, אחרת תיאור ההבדל הראשון. */
  function sameModel(x, y) {
    if (x.src !== y.src) return 'הטקסט שונה';
    for (const k of ['bom', 'nl', 'count', 'located', 'marks', 'lex', 'doctype', 'doctypeText']) if (x[k] !== y[k]) return k + ': ' + x[k] + ' ≠ ' + y[k];
    const es = m => m.errors.map(e => e.at + ':' + e.code).sort().join('|');
    if (es(x) !== es(y)) return 'רשימת שגיאות הניתוח שונה';
    const pair = (p, q) => (p === null) === (q === null) && (p === null || (p[0] === q[0] && p[1] === q[1]));
    const go = (p, q, path) => {
      if (!p || !q) return (p || q) ? path + ': צומת חסר' : null;
      for (const k of ['tag', 'implied', 'foreign', 'broken', 'hasBroken', 'from', 'to']) if (p[k] !== q[k]) return path + ' <' + p.tag + '>: ' + k + ' ' + p[k] + ' ≠ ' + q[k];
      if (!pair(p.st, q.st) || !pair(p.et, q.et)) return path + ' <' + p.tag + '>: טווח התגיות';
      if (p.attrs.length !== q.attrs.length) return path + ' <' + p.tag + '>: מספר התכונות';
      for (let i = 0; i < p.attrs.length; i++) { const s = p.attrs[i], t = q.attrs[i]; if (s.name !== t.name || s.value !== t.value || s.from !== t.from || s.to !== t.to) return path + ' <' + p.tag + '>: התכונה ' + s.name; }
      if (p.nodes.length !== q.nodes.length || p.kids.length !== q.kids.length) return path + ' <' + p.tag + '>: מספר הילדים ' + p.nodes.length + ' ≠ ' + q.nodes.length;
      let ki = 0;
      for (let i = 0; i < p.nodes.length; i++) {
        const s = p.nodes[i], t = q.nodes[i];
        if (s.type !== t.type) return path + ' <' + p.tag + '>: סוג הילד ' + i;
        if (s.type === 'el') { if (s.node !== p.kids[ki] || s.node.parent !== p) return path + ' <' + p.tag + '>: קישורי העץ'; const r = go(s.node, t.node, path + '.' + ki); if (r) return r; ki++; }
        else if (s.from !== t.from || s.to !== t.to || s.value !== t.value) return path + ' <' + p.tag + '>: ' + s.type + ' ' + i + ' [' + s.from + ',' + s.to + '] ≠ [' + t.from + ',' + t.to + ']';
      }
      return null;
    };
    return go(x.root, y.root, '');
  }

  g.KolSrc = { SrcError, applyPatches, invertPatches, parseHtml, parseCss, update, sameModel, html: H, css: C, all, walk, q, pathOf, byPath, attrOf, classesOf, textOf,
    leadOf, endOf, outer, isSimple, lineOf, cssAll, cssFind, cssAt, VOID, BENIGN };
})(typeof window !== 'undefined' ? window : globalThis);
