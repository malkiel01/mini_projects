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
    // V6: מיקום, flex, יישור, grid, overflow
    if (p === 'inset') return ['top', 'right', 'bottom', 'left'];
    if (p === 'inset-block') return ['top', 'bottom'];
    if (p === 'inset-inline') return [rtl ? 'right' : 'left', rtl ? 'left' : 'right'];
    if ((m = /^inset-(block|inline)-(start|end)$/.exec(p))) return [m[1] === 'block' ? (m[2] === 'start' ? 'top' : 'bottom') : (m[2] === 'start') === !!rtl ? 'right' : 'left'];
    if (p === 'flex') return ['flex-grow', 'flex-shrink', 'flex-basis'];
    if (p === 'flex-flow') return ['flex-direction', 'flex-wrap'];
    if ((m = /^place-(items|content|self)$/.exec(p))) return ['align-' + m[1], 'justify-' + m[1]];
    if (p === 'grid-area') return ['grid-row-start', 'grid-column-start', 'grid-row-end', 'grid-column-end'];
    if ((m = /^grid-(row|column)$/.exec(p))) return ['grid-' + m[1] + '-start', 'grid-' + m[1] + '-end'];
    if (p === 'grid-template') return ['grid-template-rows', 'grid-template-columns', 'grid-template-areas'];
    if (p === 'overflow') return ['overflow-x', 'overflow-y'];
    if (p === 'background') return ['background-color', 'background-image', 'background-position', 'background-size', 'background-repeat', 'background-attachment', 'background-origin', 'background-clip'];   // V5: כל הרכיבים (לקריאה; הכתיבה — אחרי הקיצור)
    return [p];
  }
  /** השם שהעורך כותב להצהרה חדשה: מאפיין לוגי (margin-block-start), לא פיזי. */
  function logical(phys, rtl) {
    const m = /^(margin|padding)-(top|right|bottom|left)$/.exec(phys);
    if (/^(top|right|bottom|left)$/.test(phys)) return phys === 'top' ? 'inset-block-start' : phys === 'bottom' ? 'inset-block-end' : (phys === 'right') === !!rtl ? 'inset-inline-start' : 'inset-inline-end';   // V6 — רק להצהרה חדשה
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
  /** flex: none | auto | initial | <grow> [<shrink>] [<basis>] | <basis> ← [grow, shrink, basis]; null — לא מפורק. */
  function flexParse(v) {
    const t = splitTop(String(v).trim()), num = x => /^(\d+\.?\d*|\.\d+)$/.test(x);
    if (t.length === 1) {
      const k = t[0].toLowerCase();
      if (k === 'none') return ['0', '0', 'auto']; if (k === 'auto') return ['1', '1', 'auto']; if (k === 'initial') return ['0', '1', 'auto'];
      if (num(t[0])) return [t[0], '1', '0%']; if (/var\(/i.test(t[0])) return null; return ['1', '1', t[0]];
    }
    if (t.length === 2) { if (!num(t[0])) return null; return num(t[1]) ? [t[0], t[1], '0%'] : [t[0], '1', t[1]]; }
    if (t.length === 3 && num(t[0]) && num(t[1])) return t;
    return null;
  }
  function flexFlowParse(v) { const t = splitTop(v), dir = t.find(x => /^(row|row-reverse|column|column-reverse)$/i.test(x)), wrap = t.find(x => /^(nowrap|wrap|wrap-reverse)$/i.test(x)); if (t.length !== (dir ? 1 : 0) + (wrap ? 1 : 0)) return null; return [dir || 'row', wrap || 'nowrap']; }
  /** הערך החדש של קיצור כשמשנים רכיב אחד שלו; null — אי אפשר בבטחה (var() שעשוי להיות כמה ערכים, background). */
  function setComponent(prop, value, phys, nv, rtl) {
    const p = prop.trim().toLowerCase(), cov = covered(p, rtl), i = cov.indexOf(phys);
    if (i < 0) return null;
    if (cov.length === 1) return nv;
    if (p === 'font') return fontSet(value, phys, nv);
    if (p === 'text-decoration') return decoSet(value, phys, nv);
    if (p === 'background' || p === 'grid-area' || p === 'grid-template' || /^grid-(row|column)$/.test(p)) return null;
    if (p === 'flex') { const f = flexParse(value); if (!f) return null; f[i] = nv; return f.join(' '); }
    if (p === 'flex-flow') { const f = flexFlowParse(value); if (!f) return null; f[i] = nv; return f.join(' '); }
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
    if (p === 'background') return g.KolBg ? g.KolBg.bgComponent(value, phys) : null;   // V5: background.js
    if (p === 'flex') { const f = flexParse(value); return f ? f[i] : null; }
    if (p === 'flex-flow') { const f = flexFlowParse(value); return f ? f[i] : null; }
    if (/^grid-(row|column)$/.test(p)) { const f = String(value).split('/').map(x => x.trim()); return f[i] || (i === 1 && /^\d+$|^-?\d+$/.test(f[0]) ? 'auto' : f[0]); }
    if (p === 'grid-area' || p === 'grid-template') return null;
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

  /** בדיקת ערך לפני כל כתיבה (הגנה כפולה — לא סומכים רק על הפאנל): '<' נדחה בכל יעד (ב-<style> של עמוד HTML הוא היה
   *  סוגר את התגית: "</style><img onerror=…>" עובר CSS.supports כמחרוזת), וערך שאינו חוקי למאפיין — נדחה. */
  function checkValue(phys, v) {
    if (v == null) return;
    const E = K().SrcError;
    if (String(v).includes('<')) throw new E('הערך מכיל < — בתוך <style> של עמוד HTML זה היה סוגר את התגית. ערך CSS אינו צריך <.', 'invalid');
    if (typeof CSS !== 'undefined' && CSS.supports && !CSS.supports(phys, v)) throw new E('ערך לא חוקי ל-' + phys + ': ' + v, 'invalid');
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
      const e = this.canvas.elOf(n), hasCls = sel => { const s = subject(sel); return cls.some(c => new RegExp('\\.' + c.replace(/[^\w-]/g, '\\$&') + '(?![\\w-])').test(s)); };
      /** "של המחלקה": אחד מחלקי הבורר שחל על האלמנט מסתיים במחלקה שלו (.x .hero-title, ולא .x h1 שבאותה רשימה). */
      const ofClass = r => g.KolDetails.splitSel(r.sel).some(part => { let hit = false; try { hit = !!e && e.matches(part); } catch { hit = false; } return hit && hasCls(part); });
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
    /** תיקונים לכל קובץ: [{ doc, patches, html }] — או חריגה (SrcError) עם הסבר.
     *  sets: [[מאפיין פיזי, ערך | null (איפוס)], …] — כולם בעסקה אחת; כל אחד מתוכנן על הטקסט שאחרי הקודם, והתיקון לכל קובץ
     *  הוא הקטע שבין התחילית לסיומת המשותפות. opt.unimportant — הסרת !important מהמאפיינים. */
    plan(n, t, sets, opt = {}) {
      const K2 = K(), rtl = this.rtl(n), html = this.doc();
      if (!Array.isArray(sets[0])) sets = [sets];
      if (!opt.unimportant) for (const [phys, value] of sets) checkValue(phys, value);
      const diff = (was, now) => { if (was === now) return []; let a = 0; const max = Math.min(was.length, now.length); while (a < max && was.charCodeAt(a) === now.charCodeAt(a)) a++; let b = 0; while (b < max - a && was.charCodeAt(was.length - 1 - b) === now.charCodeAt(now.length - 1 - b)) b++; return [{ from: a, to: was.length - b, text: now.slice(a, now.length - b) }]; };
      const step = (m, rn, phys, value) => (opt.unimportant ? planUnimportant(m, rn, phys, rtl) : value == null ? planReset(m, rn, phys, rtl) : planRule(m, rn, phys, value, rtl));
      /** כלל קיים: מתכננים ברצף על טקסט הגיליון (או תוכן ה-<style>). תחילת הכלל אינה זזה — כל התיקונים בתוכו. */
      const onRule = (text, from, sel) => {
        let cur = text; const notes = [];
        for (const [phys, value] of sets) {
          const m = K2.parseCss(cur), rn = K2.cssAll(m).find(x => x.type === 'rule' && x.from === from && x.sel === sel);
          if (!rn) throw new K2.SrcError('הכלל השתנה בינתיים — נסו שוב', 'stale');
          if (K2.cssAll(m).some(x => x.broken && x.from <= from && from <= x.to)) throw new K2.SrcError('הכלל נמצא באזור שבור ב-CSS — מתקנים אותו בעורך הקוד', 'broken');
          const res = step(m, rn, phys, value); if (!res) continue;
          if (res.whole) notes.push(res.whole);
          cur = K2.applyPatches(cur, res.patches);
        }
        return { text: cur, notes };
      };
      if (t.kind === 'rule') {
        const src = t.r.src;
        if (src.html) {
          const out = onRule(src.text, t.r.node.from, t.r.node.sel), ps = diff(src.text, out.text).map(p => ({ from: p.from + src.off, to: p.to + src.off, text: p.text }));
          return Object.assign(ps.length ? [{ doc: html, html: true, patches: ps }] : [], { notes: out.notes });
        }
        const doc = this.docOf(src.path); if (!doc) throw new K2.SrcError('קובץ העיצוב ' + src.path + ' אינו פתוח לעריכה', 'missing');
        const was = doc.text(), out = onRule(was, t.r.node.from, t.r.node.sel), ps = diff(was, out.text);
        return Object.assign(ps.length ? [{ doc, patches: ps }] : [], { notes: out.notes });
      }
      if (t.kind === 'inline') {
        const st = K2.attrOf(n, 'style'), was = st ? st.value : '';
        let cur = was;
        for (const [phys, value] of sets) {
          if (opt.unimportant) { const src = 'x{' + cur + '}', m = K2.parseCss(src), r = m.root.kids[0], res = r && planUnimportant(m, r, phys, rtl); if (res) cur = K2.applyPatches(src, res.patches).slice(2, -1); }
          else cur = planInline(cur, phys, value, rtl, value == null);
        }
        if (cur === was) return [];
        const m = this.canvas.m;
        return [{ doc: html, html: true, patches: (cur.trim() ? K2.html.setAttr(m, n, 'style', cur) : K2.html.removeAttr(m, n, 'style')).patches }];
      }
      const decls = sets.filter(([, v]) => v != null);
      if (!decls.length || opt.unimportant) return [];
      const doc = this.docOf(t.path); if (!doc) throw new K2.SrcError('קובץ העיצוב ' + t.path + ' אינו מקושר לעמוד', 'missing');
      const ops = [{ doc, patches: planNewRule(K2.parseCss(doc.text()), t.sel, decls, rtl).patches }];
      if (t.kind === 'newclass') ops.unshift({ doc: html, html: true, patches: K2.html.addClass(this.canvas.m, n, t.name).patches });
      return ops;
    }
  }

  g.KolStyle = { flexParse, checkValue, splitTop, covered, logical, setComponent, componentOf, fontParse, declFor, planRule, planReset, planUnimportant, planInline, planNewRule, subject, Styler, INHERITED };
})(typeof window !== 'undefined' ? window : globalThis);
