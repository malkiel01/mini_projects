/**
 * העורך החזותי — כלל היעד וכתיבת CSS (פרוסה V3, 03 §4.1–§4.2).
 *
 * שני חלקים:
 * 1. טהור (נבדק ב-node, tests/editor/style.mjs): איזה מאפיין "פיזי" כל הצהרה קובעת (margin ← ארבעה צדדים,
 *    margin-inline-start ← ימין ב-RTL), עריכת רכיב בתוך קיצור (margin: 0 0 .5em ← הרכיב, לא margin-bottom לצידו),
 *    והתיקונים לכלל קיים / כלל חדש / תכונת style. המיקומים — של מודל ה-CSS (KolSrc.parseCss), והסגנון נלמד מהכלל.
 * 2. בדפדפן (Styler): המפל על האלמנט (מפאנל "פרטים"), היעדים לבורר, "מאיפה הערך", "נדרס", ותכנון הכתיבה לקבצים.
 *
 * העורך אינו כותב !important מעצמו: הצהרה קיימת עם !important נשארת כזו (מחליפים את הערך בלבד).
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc;
  const SIDES = ['top', 'right', 'bottom', 'left'];

  /** מפצל ערך CSS ברווחים של הרמה העליונה (לא בתוך סוגריים או מרכאות). */
  function splitTop(v) {
    const out = []; let cur = '', depth = 0, q = '';
    for (const ch of String(v)) {
      if (q) { cur += ch; if (ch === q) q = ''; continue; }
      if (ch === '"' || ch === "'") { q = ch; cur += ch; continue; }
      if (ch === '(') depth++; else if (ch === ')') depth = Math.max(0, depth - 1);
      if (/\s/.test(ch) && !depth) { if (cur) out.push(cur); cur = ''; } else cur += ch;
    }
    if (cur) out.push(cur);
    return out;
  }
  const sideOf = (axis, edge, rtl) => (axis === 'block' ? (edge === 'start' ? 'top' : 'bottom') : (edge === 'start') === rtl ? 'right' : 'left');
  /** המאפיינים הפיזיים שהצהרה קובעת, לפי הסדר של הקיצור (margin-inline: [התחלה, סוף]). */
  function covered(prop, rtl) {
    const p = String(prop).trim().toLowerCase(); let m;
    if ((m = /^(margin|padding)$/.exec(p))) return SIDES.map(s => m[1] + '-' + s);
    if (/^(margin|padding)-(top|right|bottom|left)$/.test(p)) return [p];
    if ((m = /^(margin|padding)-(block|inline)$/.exec(p))) return [m[1] + '-' + sideOf(m[2], 'start', rtl), m[1] + '-' + sideOf(m[2], 'end', rtl)];
    if ((m = /^(margin|padding)-(block|inline)-(start|end)$/.exec(p))) return [m[1] + '-' + sideOf(m[2], m[3], rtl)];
    if (p === 'gap' || p === 'grid-gap') return ['row-gap', 'column-gap'];
    if (p === 'grid-row-gap') return ['row-gap'];
    if (p === 'grid-column-gap') return ['column-gap'];
    if (p === 'font') return ['font-style', 'font-weight', 'font-size', 'line-height', 'font-family'];
    if (p === 'text-decoration') return ['text-decoration-line', 'text-decoration-style', 'text-decoration-color'];
    if (p === 'background') return ['background-color'];
    return [p];
  }
  /** השם שהעורך כותב להצהרה חדשה: מאפיין לוגי (margin-block-start), לא פיזי. */
  function logical(phys, rtl) {
    const m = /^(margin|padding)-(top|right|bottom|left)$/.exec(phys);
    if (!m) return phys;
    const s = m[2];
    return m[1] + (s === 'top' ? '-block-start' : s === 'bottom' ? '-block-end' : ((s === 'right') === rtl ? '-inline-start' : '-inline-end'));
  }
  function expand4(t) { return t.length === 1 ? [t[0], t[0], t[0], t[0]] : t.length === 2 ? [t[0], t[1], t[0], t[1]] : t.length === 3 ? [t[0], t[1], t[2], t[1]] : t.slice(0, 4); }
  /** הצורה הקצרה ביותר שאינה קצרה מהמקור (margin: 0 0 .5em נשאר בשלושה ערכים). */
  function compress4(v, min) {
    for (let n = Math.max(1, min); n <= 4; n++) {
      if (n === 1 && v[0] === v[1] && v[0] === v[2] && v[0] === v[3]) return [v[0]];
      if (n === 2 && v[0] === v[2] && v[1] === v[3]) return [v[0], v[1]];
      if (n === 3 && v[1] === v[3]) return [v[0], v[1], v[2]];
      if (n === 4) return v;
    }
    return v;
  }
  const SIZE = /^(-?[\d.]+(px|em|rem|%|pt|pc|cm|mm|in|q|ex|ch|vw|vh|vmin|vmax|vi|vb|lh|rlh|cqw|cqh|cqi|cqb)|0|xx-small|x-small|small|medium|large|x-large|xx-large|xxx-large|larger|smaller|(calc|clamp|min|max|var|env)\(.*\))$/i;
  const WEIGHT = /^(bold|bolder|lighter|[1-9]\d{0,2})$/i, FSTYLE = /^(italic|oblique)$/i;
  /** font: [סגנון / משקל / וריאנט]* גודל[/גובה שורה] משפחה. */
  function fontParse(v) {
    if (/^\s*(caption|icon|menu|message-box|small-caption|status-bar|inherit|initial|unset|revert)\s*$/i.test(v)) return null;
    const t = splitTop(String(v).replace(/\s*\/\s*/, '/'));
    for (let k = 0; k < t.length; k++) {
      const [size, lh] = t[k].split('/');
      if (SIZE.test(size) && (k > 0 || t.length > 1)) return { pre: t.slice(0, k), size, lh: lh == null ? null : lh, family: t.slice(k + 1).join(' ') };
    }
    return null;
  }
  function fontSet(v, phys, nv) {
    const f = fontParse(v); if (!f || !f.family) return null;
    if (phys === 'font-size') f.size = nv;
    else if (phys === 'line-height') f.lh = nv;
    else if (phys === 'font-family') f.family = nv;
    else if (phys === 'font-weight' || phys === 'font-style') {
      const re = phys === 'font-weight' ? WEIGHT : FSTYLE, i = f.pre.findIndex(x => re.test(x)), drop = /^normal$/i.test(nv);
      if (i >= 0) { if (drop) f.pre.splice(i, 1); else f.pre[i] = nv; }
      else if (!drop) { if (phys === 'font-style') f.pre.unshift(nv); else f.pre.push(nv); }
    } else return null;
    return f.pre.concat(f.size + (f.lh != null ? '/' + f.lh : ''), f.family).join(' ');
  }
  const DLINE = /^(none|underline|overline|line-through|blink)$/i, DSTYLE = /^(solid|double|dotted|dashed|wavy)$/i, DTHICK = /^(auto|from-font|-?[\d.]+[a-z%]*)$/i;
  function decoSet(v, phys, nv) {
    const t = splitTop(v);
    if (t.some(x => /^var\(/i.test(x))) return null;
    const lines = t.filter(x => DLINE.test(x)), style = t.find(x => DSTYLE.test(x)), thick = t.find(x => DTHICK.test(x) && !DLINE.test(x)), color = t.find(x => !DLINE.test(x) && !DSTYLE.test(x) && !DTHICK.test(x));
    const out = { line: lines.join(' '), style: style || '', thick: thick || '', color: color || '' };
    if (phys === 'text-decoration-line') out.line = nv; else if (phys === 'text-decoration-style') out.style = nv; else if (phys === 'text-decoration-color') out.color = nv; else return null;
    return [out.line || 'none', out.style, out.thick, out.color].filter(Boolean).join(' ');
  }
  /** הערך החדש של קיצור כשמשנים רכיב אחד שלו; null — אי אפשר בבטחה (var() שעשוי להיות כמה ערכים, background). */
  function setComponent(prop, value, phys, nv, rtl) {
    const p = prop.trim().toLowerCase(), cov = covered(p, rtl), i = cov.indexOf(phys);
    if (i < 0) return null;
    if (cov.length === 1) return nv;
    if (p === 'font') return fontSet(value, phys, nv);
    if (p === 'text-decoration') return decoSet(value, phys, nv);
    if (p === 'background') return null;
    const t = splitTop(value);
    if (!t.length || t.some(x => /var\(|env\(/i.test(x))) return null;
    if (cov.length === 4) { if (t.length > 4) return null; const v = expand4(t); v[i] = nv; return compress4(v, t.length).join(' '); }
    if (t.length > 2) return null;
    const v = t.length === 1 ? [t[0], t[0]] : t.slice(); v[i] = nv;
    return t.length === 1 && v[0] === v[1] ? v[0] : v.join(' ');
  }
  /** הרכיב של מאפיין פיזי בתוך הצהרה (לקריאה — "מאיפה הערך"). */
  function componentOf(prop, value, phys, rtl) {
    const p = prop.trim().toLowerCase(), cov = covered(p, rtl), i = cov.indexOf(phys);
    if (i < 0) return null;
    if (cov.length === 1) return value;
    if (p === 'font') { const f = fontParse(value); if (!f) return null; return phys === 'font-size' ? f.size : phys === 'line-height' ? f.lh : phys === 'font-family' ? f.family : (f.pre.find(x => (phys === 'font-weight' ? WEIGHT : FSTYLE).test(x)) || 'normal'); }
    const t = splitTop(value); if (t.some(x => /var\(/i.test(x))) return null;
    if (cov.length === 4) return t.length <= 4 ? expand4(t)[i] : null;
    if (p === 'text-decoration' || p === 'background') return null;
    return t.length === 1 ? t[0] : t[i] || null;
  }
  /** ההצהרה בכלל שקובעת את המאפיין: עם !important קודם, אחרת האחרונה. */
  function declFor(rule, phys, rtl) {
    const ds = rule.decls.filter(d => covered(d.prop, rtl).includes(phys));
    return ds.filter(d => d.important).pop() || ds.pop() || null;
  }
  /** תיקונים לכלל קיים במודל CSS: קובעים את המאפיין הפיזי לערך. */
  function planRule(m, rule, phys, value, rtl) {
    const d = declFor(rule, phys, rtl);
    if (d) {
      if (covered(d.prop, rtl).length === 1) return { patches: [{ from: d.valFrom, to: d.valTo, text: value }], kind: 'value', decl: d };
      const nv = setComponent(d.prop, d.value, phys, value, rtl);
      if (nv != null) return { patches: [{ from: d.valFrom, to: d.valTo, text: nv }], kind: 'component', decl: d };
      return { patches: K().css.addDecl(m, rule, logical(phys, rtl), value + (d.important ? ' !important' : ''), { index: rule.decls.indexOf(d) + 1 }).patches, kind: 'after-shorthand', decl: d };
    }
    return { patches: K().css.addDecl(m, rule, logical(phys, rtl), value).patches, kind: 'add' };
  }
  /** איפוס: מוחקים את ההצהרה (לא כותבים ברירת מחדל). קיצור — נמחק כולו, וזה נאמר. null — אין מה לאפס. */
  function planReset(m, rule, phys, rtl) {
    const d = declFor(rule, phys, rtl); if (!d) return null;
    const ds = rule.decls, at = ds.indexOf(d), K2 = K();
    // removeDecl מוחק את האחרונה בשם הזה; כאן — בדיוק ההצהרה שנמצאה
    let from = d.from; while (from > rule.bFrom + 1 && /\s/.test(m.src[from - 1])) from--;
    let to = d.semi >= 0 ? d.semi : d.to;
    if (d.semi < 0 && at === ds.length - 1 && ds.length > 1 && m.src[from - 1] === ';') from--;
    void K2;
    return { patches: [{ from, to, text: '' }], whole: covered(d.prop, rtl).length > 1 ? d.prop.trim() : '', decl: d };
  }
  /** הסרת !important מההצהרה (כפתור — לא מעצמו). */
  function planUnimportant(m, rule, phys, rtl) {
    const d = declFor(rule, phys, rtl); if (!d || !d.important) return null;
    return { patches: [{ from: d.valTo, to: d.to, text: '' }] };
  }
  /** אותה כתיבה על תכונת style: מחזיר את הטקסט החדש של התכונה. */
  function planInline(style, phys, value, rtl, reset) {
    const s = String(style || '');
    if (!s.trim() && !reset) return logical(phys, rtl) + ': ' + value;
    const src = 'x{' + s + '}', m = K().parseCss(src), r = m.root.kids[0];
    if (!r || r.broken) throw new (K().SrcError)('תכונת style שאינה נקראת — מתקנים אותה בקוד', 'broken');
    let res;
    if (reset) { res = planReset(m, r, phys, rtl); if (!res) return s; }
    else if (!r.decls.length) return s.replace(/[\s;]*$/, '') + (s.trim() ? '; ' : '') + logical(phys, rtl) + ': ' + value;
    else { res = planRule(m, r, phys, value, rtl); if (res.kind === 'add') return s.replace(/[\s;]*$/, '') + '; ' + logical(phys, rtl) + ': ' + value; }
    const out = K().applyPatches(src, res.patches);
    return out.slice(2, -1).replace(/^\s+$/, '');
  }
  /** כלל חדש בסוף הגיליון (או בתוך at-rule par), בסגנון הכללים שכבר בקובץ. */
  function planNewRule(m, sel, decls, rtl) {
    return K().css.addRule(m, m.root, sel, decls.map(([p, v]) => [logical(p, rtl), v]));
  }

  // ── המפל, בדפדפן ─────────────────────────────────────────
  const INHERITED = /^(color|font-.*|line-height|letter-spacing|word-spacing|text-align|text-transform|direction|text-indent|white-space|visibility|cursor|quotes|list-style.*|text-shadow|text-decoration-color)$/;
  const cmp = (a, b) => { for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) return a[i] - b[i]; return 0; };
  const rank = (r, important) => [important ? 1 : 0, r.inline ? 1 : 0, r.spec[0], r.spec[1], r.spec[2], r.order];
  /** החלק האחרון של בורר (הנושא) — לבדיקה אם הכלל הוא "של המחלקה". */
  function subject(sel) {
    let depth = 0, q = '', last = 0;
    for (let i = 0; i < sel.length; i++) {
      const ch = sel[i];
      if (q) { if (ch === q) q = ''; continue; }
      if (ch === '"' || ch === "'") q = ch; else if (ch === '(' || ch === '[') depth++; else if (ch === ')' || ch === ']') depth--;
      else if (!depth && /[\s>+~]/.test(ch)) last = i + 1;
    }
    return sel.slice(last).replace(/::?[\w-]+(\([^)]*\))?/g, m => (/^::/.test(m) ? m : m)).trim();
  }

  class Styler {
    /** opt: { canvas, details, sheets(): Map<href,{path,doc}>, file(), doc(), pagePath() } */
    constructor(opt) { Object.assign(this, opt); }
    rtl(n) { const e = this.canvas.elOf(n); try { return this.canvas.frame.contentWindow.getComputedStyle(e).direction === 'rtl'; } catch { return true; } }
    computed(n, phys) { const e = this.canvas.elOf(n); if (!e) return ''; try { return this.canvas.frame.contentWindow.getComputedStyle(e).getPropertyValue(phys); } catch { return ''; } }
    info(n) { return this.details.collect(n); }
    docOf(path) { for (const s of this.sheets().values()) if (s.path === path) return s.doc; return null; }
    /** כל ההצהרות הפעילות שקובעות את המאפיין, מהחזקה לחלשה. */
    cands(info, phys, rtl) {
      const out = [];
      for (const r of info.rules) if (!r.off) for (const d of r.decls) if (covered(d.prop, rtl).includes(phys)) out.push({ r, d, k: rank(r, d.important) });
      return out.sort((a, b) => cmp(b.k, a.k));
    }
    /** כמה אלמנטים בעמוד הכלל משפיע עליהם (העותקים של העורך — לא). */
    count(sel) {
      if (sel === null) return 1;
      try { let n = 0; for (const e of this.canvas.doc.querySelectorAll(sel)) if (!e.closest('[data-kol-canvas]')) n++; return n; } catch { return 0; }
    }
    elementsOf(t) { if (t.kind === 'inline' || t.kind === 'newclass') return null; try { return [...this.canvas.doc.querySelectorAll(t.sel)].filter(e => !e.closest('[data-kol-canvas]')); } catch { return []; } }
    /** היעדים לבורר "משנה את": ברירת המחדל קודם. */
    targets(n, info) {
      info = info || this.info(n);
      const K2 = K(), cls = K2.classesOf(n), out = [], seen = new Set();
      const writable = r => r.located && !r.external && !r.inline && r.node && !r.node.broken;
      const ofClass = r => { const s = subject(r.matched || r.sel); return cls.some(c => new RegExp('\\.' + c.replace(/[^\w-]/g, '\\$&') + '(?![\\w-])').test(s)); };
      const rules = info.rules.filter(r => writable(r) && !r.off);
      const mine = rules.filter(r => ofClass(r) && !r.media).sort((a, b) => cmp(rank(b, false), rank(a, false)));
      const add = t => { const key = t.kind + '|' + (t.path || '') + '|' + (t.line || '') + '|' + t.sel; if (seen.has(key)) return; seen.add(key); out.push(t); };
      const ruleT = (r, why) => ({ kind: 'rule', sel: r.sel, path: r.path, line: r.line, media: r.media, r, why, count: this.count(r.sel) });
      for (const r of mine) add(ruleT(r, 'class'));
      if (!mine.length) {
        const page = this.pagePath();
        if (cls.length && page) add({ kind: 'new', sel: '.' + cls[0], path: page, why: 'class', count: this.count('.' + cls[0]) });
        else if (page) add({ kind: 'newclass', sel: '.' + this.suggest(n), name: this.suggest(n), path: page, why: 'class', count: 1 });
      }
      for (const r of rules) if (!mine.includes(r)) add(ruleT(r, r.media ? 'media' : 'source'));
      add({ kind: 'inline', sel: 'style=""', path: this.file().path, why: 'inline', count: 1 });
      return out;
    }
    /** שם מחלקה קריא, מההקשר: המחלקה של האב הקרוב ביותר + התגית, ייחודי בעמוד (hero-title-2). לעולם לא אקראי. */
    suggest(n) {
      const K2 = K(); let base = '';
      for (let p = n.parent; p && !base; p = p.parent) { const c = K2.classesOf(p)[0]; if (c) base = c; }
      const tag = { h1: 'title', h2: 'title', h3: 'title', h4: 'title', p: 'text', span: 'text', a: 'link', img: 'image', li: 'item', div: 'box', section: 'section', button: 'button' }[n.tag] || n.tag;
      let stem = (base ? base.replace(/(-inner|-wrap|-container|-box)$/, '') + '-' : '') + tag;
      stem = stem.replace(/[^\w-]/g, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'item';
      const used = new Set(); for (const e of this.canvas.doc.querySelectorAll('[class]')) for (const c of e.classList) used.add(c);
      let name = stem; for (let i = 2; used.has(name); i++) name = stem + '-' + i;
      if (name === stem && used.has(stem)) name = stem + '-2';
      return name;
    }
    /** מה שנראה ומאיפה: { value, from: {sel,path,line,media,important,inline} | null, inherited, browser, winner }. */
    source(n, phys, info) {
      info = info || this.info(n);
      const rtl = this.rtl(n), cs = this.cands(info, phys, rtl), w = cs[0] || null, value = this.computed(n, phys);
      if (w) return { value, winner: w, declared: componentOf(w.d.prop, w.d.value, phys, rtl) ?? w.d.value, all: cs };
      if (INHERITED.test(phys)) {
        for (let p = n.parent, i = 0; p && i < 8; p = p.parent, i++) {
          if (!this.canvas.elOf(p)) continue;
          const c = this.cands(this.info(p), phys, rtl)[0];
          if (c) return { value, inherited: { n: p, label: this.canvas.label(p), winner: c }, all: [] };
        }
      }
      return { value, browser: true, all: [] };
    }
    /** האם כתיבה ליעד תיראה. מחזיר { ok, by (מי דורס), media (נכתב שם — 03: "עורך את ההצהרה ההיא") }. */
    visible(n, t, phys, info) {
      info = info || this.info(n);
      const rtl = this.rtl(n), cs = this.cands(info, phys, rtl);
      let mineK;
      if (t.kind === 'rule') { const d = declFor(t.r.node, phys, rtl); mineK = rank(t.r, d ? d.important : false); const own = cs.filter(c => c.r === t.r); if (own.length) mineK = own[0].k; }
      else if (t.kind === 'inline') { const own = cs.find(c => c.r.inline); mineK = own ? own.k : [0, 1, 0, 0, 0, 1e9]; }
      else mineK = [0, 0, ...g.KolDetails.specificity(t.sel), 1e9];
      const by = cs.find(c => c.r !== t.r && !(t.kind === 'inline' && c.r.inline) && cmp(c.k, mineK) > 0);
      if (!by) return { ok: true };
      return { ok: false, by, media: !!by.r.media && by.r.located && !by.r.external };
    }
    /** תיקונים לכל קובץ: [{ doc, patches, html }] — או חריגה (SrcError) עם הסבר. */
    plan(n, t, phys, value, opt = {}) {
      const K2 = K(), rtl = this.rtl(n), html = this.doc(), file = this.file();
      const fromRule = (r, fn) => {
        const src = r.src;
        if (src.html) { const res = fn(r.node, this.details.model('#' + src.off + ':' + src.path, src.text).m); return res && [{ doc: html, html: true, patches: res.patches.map(p => ({ from: p.from + src.off, to: p.to + src.off, text: p.text })), res }]; }
        const doc = this.docOf(src.path); if (!doc) throw new K2.SrcError('קובץ העיצוב ' + src.path + ' אינו פתוח לעריכה', 'missing');
        const m = K2.parseCss(doc.text());
        const node = K2.cssAll(m).find(x => x.type === 'rule' && x.from === r.node.from && x.sel === r.node.sel);   // אותו כלל בטקסט העדכני
        if (!node) throw new K2.SrcError('הכלל השתנה בינתיים — נסו שוב', 'stale');
        const res = fn(node, m); return res && [{ doc, patches: res.patches, res }];
      };
      if (t.kind === 'rule') {
        if (opt.reset) return fromRule(t.r, (rn, m) => planReset(m, rn, phys, rtl)) || [];
        if (opt.unimportant) return fromRule(t.r, (rn, m) => planUnimportant(m, rn, phys, rtl)) || [];
        return fromRule(t.r, (rn, m) => planRule(m, rn, phys, value, rtl));
      }
      if (t.kind === 'inline') {
        const st = K2.attrOf(n, 'style'), cur = st ? st.value : '', next = planInline(cur, phys, value, rtl, !!opt.reset);
        if (next === cur) return [];
        const m = this.canvas.m;
        return [{ doc: html, html: true, patches: (next.trim() ? K2.html.setAttr(m, n, 'style', next) : K2.html.removeAttr(m, n, 'style')).patches }];
      }
      if (opt.reset || opt.unimportant) return [];
      const doc = this.docOf(t.path); if (!doc) throw new K2.SrcError('קובץ העיצוב ' + t.path + ' אינו מקושר לעמוד', 'missing');
      const m = K2.parseCss(doc.text()), ops = [{ doc, patches: planNewRule(m, t.sel, [[phys, value]], rtl).patches }];
      if (t.kind === 'newclass') ops.unshift({ doc: html, html: true, patches: K2.html.addClass(this.canvas.m, n, t.name).patches });
      void file;
      return ops;
    }
  }

  g.KolStyle = { splitTop, covered, logical, setComponent, componentOf, fontParse, declFor, planRule, planReset, planUnimportant, planInline, planNewRule, subject, Styler, INHERITED };
})(typeof window !== 'undefined' ? window : globalThis);
