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
    if (p === 'transition') return ['transition-property', 'transition-duration', 'transition-timing-function', 'transition-delay', 'transition-behavior'];   // V8: "מעבר" — קריאה; כתיבה אחרי הקיצור
    // V10: מסגרת ופינות — שמות לוגיים ← פיזיים (קיצור של צד: שלושת הרכיבים; `border` ו-`border-radius` נשארים כשם עצמם)
    if ((m = /^border-(block|inline)-(start|end)-(width|style|color)$/.exec(p))) return ['border-' + sideOf(m[1], m[2], rtl) + '-' + m[3]];
    if ((m = /^border-(block|inline)-(start|end)$/.exec(p))) { const sd = sideOf(m[1], m[2], rtl); return ['border-' + sd + '-width', 'border-' + sd + '-style', 'border-' + sd + '-color']; }
    if ((m = /^border-(top|right|bottom|left)$/.exec(p))) return ['border-' + m[1] + '-width', 'border-' + m[1] + '-style', 'border-' + m[1] + '-color'];
    if ((m = /^border-(start|end)-(start|end)-radius$/.exec(p))) return ['border-' + (m[1] === 'start' ? 'top' : 'bottom') + '-' + sideOf('inline', m[2], rtl) + '-radius'];
    if (p === 'outline') return ['outline-width', 'outline-style', 'outline-color'];
    // `border` / `border-radius`: השם עצמו (כתיבה של "כל הצדדים") ואחריו הרכיבים — כדי ש"מאיפה הערך" של צד יראה את הקיצור
    if (p === 'border') return ['border'].concat(...SIDES.map(sd => ['border-' + sd + '-width', 'border-' + sd + '-style', 'border-' + sd + '-color']));
    if ((m = /^border-(width|style|color)$/.exec(p))) return SIDES.map(sd => 'border-' + sd + '-' + m[1]);
    if (p === 'border-radius') return ['border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius'];
    if (p === 'background') return ['background-color', 'background-image', 'background-position', 'background-size', 'background-repeat', 'background-attachment', 'background-origin', 'background-clip'];   // V5: כל הרכיבים (לקריאה; הכתיבה — אחרי הקיצור)
    return [p];
  }
  /** השם שהעורך כותב להצהרה חדשה: מאפיין לוגי (margin-block-start), לא פיזי. */
  function logical(phys, rtl) {
    const m = /^(margin|padding)-(top|right|bottom|left)$/.exec(phys);
    let b;
    if ((b = /^border-(top|right|bottom|left)-(width|style|color)$/.exec(phys))) return 'border-' + (b[1] === 'top' ? 'block-start' : b[1] === 'bottom' ? 'block-end' : (b[1] === 'right') === !!rtl ? 'inline-start' : 'inline-end') + '-' + b[2];   // V10
    if ((b = /^border-(top|bottom)-(left|right)-radius$/.exec(phys))) return 'border-' + (b[1] === 'top' ? 'start' : 'end') + '-' + ((b[2] === 'right') === !!rtl ? 'start' : 'end') + '-radius';
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
  /** V10: קו (מסגרת של צד / outline): [עובי, סוג, צבע], בכל סדר; null — var() או משהו שאינו מזוהה. */
  const LSTYLE = /^(none|hidden|dotted|dashed|solid|double|groove|ridge|inset|outset|auto)$/i, LWIDTH = /^(thin|medium|thick|0|-?[\d.]+[a-z%]+|(calc|clamp|min|max)\(.*\))$/i;
  function lineParse(v) {
    const t = splitTop(String(v).trim()); if (!t.length || t.length > 3 || t.some(x => /var\(|env\(/i.test(x))) return null;
    const out = ['medium', 'none', 'currentcolor']; let seen = 0;
    for (const x of t) { const k = LSTYLE.test(x) ? 1 : LWIDTH.test(x) ? 0 : 2; if (seen & (1 << k)) return null; seen |= 1 << k; out[k] = x; }
    return out;
  }
  function lineSet(v, i, nv) { const f = lineParse(v); if (!f) return null; f[i] = nv; return f.join(' '); }
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
    if (p === 'background' || p === 'transition' || p === 'grid-area' || p === 'grid-template' || /^grid-(row|column)$/.test(p)) return null;
    if (p === 'outline' || /^border-(top|right|bottom|left|block-start|block-end|inline-start|inline-end)$/.test(p)) return lineSet(value, i, nv);   // V10
    if (p === 'border' || p === 'border-radius') return i === 0 ? nv : null;   // V10: רכיב של צד / פינה בתוך הקיצור — הצהרה נפרדת אחריו
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
    if (p === 'outline' || /^border-(top|right|bottom|left|block-start|block-end|inline-start|inline-end)$/.test(p)) { const f = lineParse(value); return f ? f[i] : null; }   // V10
    if (p === 'border') { if (!i) return value; const f = lineParse(value); return f ? f[(i - 1) % 3] : null; }
    if (p === 'border-radius') { if (!i) return value; if (/\//.test(value)) return null; const t = splitTop(value); return t.length <= 4 && !t.some(x => /var\(/i.test(x)) ? expand4(t)[i - 1] : null; }
    if (p === 'flex') { const f = flexParse(value); return f ? f[i] : null; }
    if (p === 'flex-flow') { const f = flexFlowParse(value); return f ? f[i] : null; }
    if (/^grid-(row|column)$/.test(p)) { const f = String(value).split('/').map(x => x.trim()); return f[i] || (i === 1 && /^\d+$|^-?\d+$/.test(f[0]) ? 'auto' : f[0]); }
    if (p === 'grid-area' || p === 'grid-template' || p === 'transition') return null;
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
  /** מחיקת הצהרה מסוימת מכלל (לא "האחרונה בשם הזה"), בלי לשבור את סגנון הנקודה-פסיק. */
  function removeDeclAt(m, rule, d) {
    const ds = rule.decls, at = ds.indexOf(d);
    let from = d.from; while (from > rule.bFrom + 1 && /\s/.test(m.src[from - 1])) from--;
    const to = d.semi >= 0 ? d.semi : d.to;
    if (d.semi < 0 && at === ds.length - 1 && ds.length > 1 && m.src[from - 1] === ';') from--;
    return [{ from, to, text: '' }];
  }
  /** איפוס: מוחקים את ההצהרה (לא כותבים ברירת מחדל). קיצור — נמחק כולו, וזה נאמר. null — אין מה לאפס. */
  function planReset(m, rule, phys, rtl) {
    const d = declFor(rule, phys, rtl); if (!d) return null;
    return { patches: removeDeclAt(m, rule, d), whole: covered(d.prop, rtl).length > 1 ? d.prop.trim() : '', decl: d };
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

  // ── מכשירים (V7, 03 §8.1): נקודת שבירה = @media (max-width: <max>px), בדיוק ──────────────────────
  /** ה-max של תנאי @media שהוא "מכשיר": (max-width: 767px) / (width <= 767px) / (767px >= width), רווחים ואותיות לא משנים.
   *  כל תנאי אחר (min-width, screen and, print, prefers-…, כמה תנאים) — null: אינו מכשיר, ונערך כמו שהוא. */
  function mediaMax(cond) {
    const s = String(cond == null ? '' : cond).trim().replace(/^@media\s+/i, '').replace(/\s+/g, ' ').toLowerCase();
    const n = '(\\d+(?:\\.\\d+)?)px', m = new RegExp('^\\( ?max-width ?: ?' + n + ' ?\\)$').exec(s) || new RegExp('^\\( ?width ?<= ?' + n + ' ?\\)$').exec(s) || new RegExp('^\\( ?' + n + ' ?>= ?width ?\\)$').exec(s);
    return m ? +m[1] : null;
  }
  /** התנאי שהעורך כותב למכשיר. */
  const devCond = max => '(max-width: ' + max + 'px)';
  /** max של כלל מפאנל "פרטים" (r.media: "@media (max-width: 767px)", או כמה הקשרים מופרדים ב-" · "): רק @media אחד בלי עוטף. */
  function ruleMax(r) { const md = r && r.media ? String(r.media) : ''; if (!md || md.includes(' · ') || !/^@media /.test(md)) return null; return mediaMax(md.slice(7)); }
  const normSel = s => String(s).replace(/\s+/g, ' ').trim();
  const isDevBlock = (n, max) => n && n.type === 'at' && n.name.toLowerCase() === 'media' && mediaMax(n.sel) === max;
  /** הכלל של המכשיר לבורר: באותו הקשר של הכלל הבסיסי (בדרך כלל רמת הגיליון), בתוך @media של המכשיר, ואחרי הבסיס —
   *  כלל שלפני הבסיס היה נדרס בידו (אותה ייחודיות, מוקדם יותר). כמה כאלה — האחרון קובע. */
  function devRule(m, sel, max, base) {
    const ctx = base ? base.parent : m.root;
    return K().cssAll(m).filter(r => r.type === 'rule' && !r.broken && normSel(r.sel) === normSel(sel) && isDevBlock(r.parent, max) && r.parent.parent === ctx && (!base || r.from > base.to)).pop() || null;
  }
  /** בלוק @media של המכשיר באותו הקשר, אחרי הבסיס (הראשון כזה). */
  function devBlock(m, max, base) {
    const ctx = base ? base.parent : m.root;
    return ctx.kids.find(k => isDevBlock(k, max) && !k.broken && !k.hasBroken && (!base || k.from > base.to)) || null;
  }
  const blank = (m, n) => !m.src.slice(n.bFrom + 1, n.bTo - 1).trim();
  /**
   * כתיבה למכשיר, על טקסט CSS (קובץ, או תוכן <style>): sets — [[מאפיין פיזי, ערך | null], …], כל אחד על הטקסט שאחרי הקודם.
   * o: { sel, max, baseFrom (תחילת הכלל הבסיסי בטקסט; -1 — אין), rtl }. מחזיר { text, notes }.
   * יש כלל של המכשיר ← ההצהרה בו · יש בלוק @media זהה ← כלל חדש בסופו · אין ← בלוק חדש מיד אחרי הכלל הבסיסי (בלי בסיס — בסוף).
   * איפוס (null): ההצהרה נמחקת; כלל שנשאר ריק — נמחק; בלוק שנשאר ריק לגמרי — נמחק (בלוק עם הערה — נשאר).
   */
  function planDevice(text, o) {
    const K2 = K(); let cur = text; const notes = [];
    const baseOf = m => (o.baseFrom >= 0 ? K2.cssAll(m).find(x => x.type === 'rule' && x.from === o.baseFrom) || null : null);
    for (const [phys, value] of o.sets) {
      const m = K2.parseCss(cur), base = baseOf(m);
      if (o.baseFrom >= 0 && !base) throw new K2.SrcError('הכלל השתנה בינתיים — נסו שוב', 'stale');
      const r = devRule(m, o.sel, o.max, base);
      if (value == null) {
        if (!r) continue;
        const res = planReset(m, r, phys, o.rtl); if (!res) continue;
        if (res.whole) notes.push(res.whole);
        cur = K2.applyPatches(cur, res.patches);
        const m2 = K2.parseCss(cur), r2 = K2.cssAll(m2).find(x => x.type === 'rule' && x.from === r.from);
        if (r2 && !r2.decls.length && blank(m2, r2)) {
          const blk = r2.parent, alone = blk.kids.length === 1 && !m2.src.slice(blk.bFrom + 1, blk.bTo - 1).replace(m2.src.slice(r2.from, r2.to), '').trim();
          cur = K2.applyPatches(cur, K2.css.removeNode(m2, alone ? blk : r2).patches);
        }
        continue;
      }
      if (r) { cur = K2.applyPatches(cur, planRule(m, r, phys, value, o.rtl).patches); continue; }
      const decls = [[logical(phys, o.rtl), value]], blk = devBlock(m, o.max, base);
      if (blk && blk.kids.length) { cur = K2.applyPatches(cur, K2.css.addRule(m, blk, o.sel, decls).patches); continue; }
      if (blk) {   // בלוק ריק (רק הערה / רווח) — הכלל נכתב בתוכו, בהזחה של הקובץ
        const ind = (/\n([ \t]+)\S/.exec(m.src.slice(blk.bFrom)) || /\n([ \t]+)\S/.exec(m.src) || [, '  '])[1]; let at = blk.bTo - 1;
        while (at > blk.bFrom + 1 && /\s/.test(m.src[at - 1])) at--;
        cur = K2.applyPatches(cur, [{ from: at, to: at, text: m.nl + ind + o.sel + ' { ' + decls[0][0] + ': ' + decls[0][1] + '; }' + (/\n/.test(m.src.slice(at, blk.bTo - 1)) ? '' : m.nl) }]);
        continue;
      }
      const add = K2.css.addMedia(m, devCond(o.max), [{ sel: o.sel, decls }]).patches[0], body = add.text.replace(/^\s+/, '');
      if (!base) { cur = K2.applyPatches(cur, [add]); continue; }
      const lead = m.src.slice(0, base.from).match(/(\s*)$/)[1], sep = lead.includes('\n') ? lead.replace(/^[ \t]+|[ \t]+$/g, '') : m.nl;
      cur = K2.applyPatches(cur, [{ from: base.to, to: base.to, text: sep + body }]);
    }
    return { text: cur, notes };
  }

  // ── מצבים (V8, 03 §8.2): <יעד>:<מצב> מיד אחרי כלל היעד ───────────────────────────────────
  const PSEUDO_EL = /(::?(before|after|first-line|first-letter)|::[\w-]+(\([^)]*\))?)$/i;
  /** הבורר של המצב: הפסאודו-מחלקה לכל חלק ברשימה, לפני פסאודו-אלמנט (.x::before ← .x:hover::before).
   *  of — בורר ההורה ("מצב של הורה"): <הורה>:<מצב> <נושא>. */
  function stateSel(sel, pc, of) {
    return splitList(sel).map(part => { if (of) return of + pc + ' ' + subject(part); const m = PSEUDO_EL.exec(part); return m ? part.slice(0, m.index) + pc + m[0] : part + pc; }).join(', ');
  }
  /** בדיקת מצב שהוקלד: פסאודו-מחלקה אחת (או כמה צמודות), שהדפדפן מקבל כבורר; בלי < ובלי {}; ;. */
  function checkState(pc) {
    const E = K().SrcError, v = String(pc == null ? '' : pc).trim();
    if (!/^:[\w-]/.test(v)) throw new E('מצב מתחיל ב-: (פסאודו-מחלקה, למשל :hover). פסאודו-אלמנט (::before) נבחר כאלמנט, לא כמצב.', 'invalid');
    if (/[<>{};\\]|\/\*/.test(v.replace(/\([^]*\)/, m => m.replace(/>/g, '')))) throw new E('מצב: בלי < { } ; ובלי הערה', 'invalid');
    let d = 0, q = '';
    for (const ch of v) {   // ברמה העליונה: בלי רווח, פסיק וקומבינטור — רק פסאודו-מחלקות צמודות; סוגריים מאוזנים
      if (q) { if (ch === q) q = ''; continue; }
      if (ch === '"' || ch === "'") { q = ch; continue; }
      if (ch === '(') d++; else if (ch === ')') { d--; if (d < 0) break; }
      else if (!d && /[\s,>+~\[\].#*|]/.test(ch)) throw new E('מצב: פסאודו-מחלקה אחת (או כמה צמודות), בלי רווח, פסיק או בורר נוסף — אלה נכתבים ב"בורר בהקלדה"', 'invalid');
    }
    if (d !== 0 || q) throw new E('מצב: סוגריים או מרכאות שאינם נסגרים', 'invalid');
    if (typeof CSS !== 'undefined' && CSS.supports && !CSS.supports('selector(*' + v + ')')) throw new E('הדפדפן אינו מכיר את ' + v + ' כבורר', 'invalid');
    return v;
  }
  /** בדיקת בורר שהוקלד (כלל היעד): בלי < ובלי {} ; (היו סוגרים את הכלל או את <style>), והדפדפן מקבל אותו. */
  function checkSelector(sel) {
    const E = K().SrcError, v = String(sel == null ? '' : sel).trim();
    if (!v) throw new E('בורר ריק', 'invalid');
    if (/[<{};]|\/\*|\*\//.test(v.replace(/"[^"]*"|'[^']*'/g, m => (/[<]/.test(m) ? '<' : '""')))) throw new E('בורר אינו יכול להכיל < { } ; או הערה — הם היו סוגרים את הכלל (או את <style>)', 'invalid');
    if (typeof CSS !== 'undefined' && CSS.supports && !CSS.supports('selector(' + v + ')')) { let okSel = false; try { if (typeof document !== 'undefined') { document.createDocumentFragment().querySelector(v); okSel = true; } } catch { okSel = false; } if (!okSel) throw new E('בורר לא חוקי: ' + v, 'invalid'); }
    return v;
  }
  /** פיצול רשימת בוררים בפסיקים של הרמה העליונה (בלי KolDetails — ב-node). */
  const splitList = s => { if (g.KolDetails) return g.KolDetails.splitSel(s); const out = []; let d = 0, q = '', cur = ''; for (const ch of String(s)) { if (q) { if (ch === q) q = ''; cur += ch; continue; } if (ch === '"' || ch === "'") q = ch; else if (ch === '(' || ch === '[') d++; else if (ch === ')' || ch === ']') d--; if (ch === ',' && !d && !q) { out.push(cur.trim()); cur = ''; } else cur += ch; } if (cur.trim()) out.push(cur.trim()); return out; };
  const normParts = s => splitList(s).map(normSel);
  /** הכלל של המצב באותו הקשר של הבסיס: בדיוק הבורר, או כלל רשימה שאחד מחלקיו הוא הבורר (.btn:hover, .btn:focus). */
  function stateRule(m, sel, base) {
    const ctx = base ? base.parent : m.root, want = normParts(sel);
    const all = K().cssAll(m).filter(r => r.type === 'rule' && !r.broken && r.parent === ctx);
    const exact = all.filter(r => normSel(r.sel) === normSel(sel)), shared = all.filter(r => { const ps = normParts(r.sel); return want.every(w => ps.includes(w)); });
    const after = list => list.filter(r => !base || r.from > base.to);
    return after(exact).pop() || exact.pop() || after(shared).pop() || shared.pop() || null;
  }
  /**
   * כתיבה למצב, על טקסט CSS: sets — [[מאפיין פיזי, ערך | null], …]. o: { sel (בורר המצב), baseFrom (-1 — אין בסיס), rtl }.
   * יש כלל למצב (גם משותף לשני מצבים) ← ההצהרה בו · אין ← כלל חדש מיד אחרי כלל הבסיס (בלי בסיס — בסוף).
   * איפוס: ההצהרה נמחקת; כלל מצב שנשאר ריק — נמחק. מחזיר { text, notes, shared (הבורר של כלל משותף) }.
   */
  function planState(text, o) {
    const K2 = K(); let cur = text; const notes = []; let shared = '';
    const baseOf = m => (o.baseFrom >= 0 ? K2.cssAll(m).find(x => x.type === 'rule' && x.from === o.baseFrom) || null : null);
    for (const [phys, value] of o.sets) {
      const m = K2.parseCss(cur), base = baseOf(m);
      if (o.baseFrom >= 0 && !base) throw new K2.SrcError('הכלל השתנה בינתיים — נסו שוב', 'stale');
      const r = stateRule(m, o.sel, base);
      if (r && normSel(r.sel) !== normSel(o.sel)) shared = r.sel.replace(/\s+/g, ' ').trim();
      if (value == null) {
        if (!r) continue;
        const res = planReset(m, r, phys, o.rtl); if (!res) continue;
        if (res.whole) notes.push(res.whole);
        cur = K2.applyPatches(cur, res.patches);
        const m2 = K2.parseCss(cur), r2 = K2.cssAll(m2).find(x => x.type === 'rule' && x.from === r.from);
        if (r2 && !r2.decls.length && blank(m2, r2) && !shared) cur = K2.applyPatches(cur, K2.css.removeNode(m2, r2).patches);
        continue;
      }
      if (r) { cur = K2.applyPatches(cur, planRule(m, r, phys, value, o.rtl).patches); continue; }
      const decls = [[logical(phys, o.rtl), value]];
      if (!base) { cur = K2.applyPatches(cur, K2.css.addRule(m, m.root, o.sel, decls).patches); continue; }
      const add = K2.css.addRule(m, base.parent === m.root ? m.root : base.parent, o.sel, decls).patches[0], body = add.text.replace(/^\s+/, '');
      const lead = m.src.slice(0, base.from).match(/(\s*)$/)[1];
      cur = K2.applyPatches(cur, [{ from: base.to, to: base.to, text: (lead.includes('\n') ? lead : m.nl) + body }]);
    }
    return { text: cur, notes, shared };
  }

  /** שינוי max של נקודת שבירה: כל בלוק @media שתנאיו הם בדיוק המכשיר הישן ← התנאי החדש. { patches, blocks, rules }. */
  function mediaRetarget(text, oldMax, newMax) {
    const m = K().parseCss(text), patches = []; let rules = 0;
    for (const n of K().cssAll(m)) if (n.type === 'at' && n.name.toLowerCase() === 'media' && !n.broken && mediaMax(n.sel) === oldMax) { patches.push({ from: n.selFrom, to: n.selTo, text: devCond(newMax) }); rules += n.kids.filter(k => k.type === 'rule').length; }
    return { patches: patches.sort((a, b) => b.from - a.from), blocks: patches.length, rules };
  }
  /** כמה כללים כתובים למכשיר (לאזהרה במחיקה). */
  const mediaCount = (text, max) => mediaRetarget(text, max, max).rules;
  /** kol.json ← breakpoints חדשים, בלי לגעת בשאר הקובץ: הערך של "breakpoints" מוחלף במקומו (אין — נוסף ראשון),
   *  שורה לכל נקודה, בהזחה של הקובץ. קובץ שאינו JSON תקין — חריגה (מתקנים בקוד). */
  function kolBreakpoints(text, list) {
    const E = K().SrcError; let obj;
    try { obj = JSON.parse(text.replace(/^﻿/, '')); } catch (e) { throw new E('kol.json אינו JSON תקין — מתקנים אותו בעורך הקוד, ואז משנים נקודות שבירה', 'broken'); }
    if (!obj || typeof obj !== 'object' || Array.isArray(obj)) throw new E('kol.json חייב להיות אובייקט', 'broken');
    const nl = text.includes('\r\n') ? '\r\n' : '\n', ind = (/\n([ \t]+)"/.exec(text) || [, '  '])[1];
    const arr = '[' + list.map(b => nl + ind + ind + '{ ' + [['id', b.id], ['label', b.label], ['max', b.max], ['preview', b.preview]].map(([k, v]) => JSON.stringify(k) + ': ' + JSON.stringify(v)).join(', ') + ' }').join(',') + nl + ind + ']';
    // הערך הקיים — סריקה של מחרוזות וסוגריים ברמה העליונה
    let depth = 0, i = 0, keyAt = -1;
    for (; i < text.length; i++) {
      const ch = text[i];
      if (ch === '"') { let j = i + 1; while (j < text.length && text[j] !== '"') j += text[j] === '\\' ? 2 : 1; const str = text.slice(i, j + 1); if (depth === 1 && str === '"breakpoints"' && /^\s*:/.test(text.slice(j + 1))) { keyAt = i; break; } i = j; continue; }
      if (ch === '{' || ch === '[') depth++; else if (ch === '}' || ch === ']') depth--;
    }
    if (keyAt < 0) { const open = text.indexOf('{'); return text.slice(0, open + 1) + nl + ind + '"breakpoints": ' + arr + (/^\s*\}/.test(text.slice(open + 1)) ? nl : ',') + text.slice(open + 1); }
    let a = text.indexOf(':', keyAt) + 1; while (/\s/.test(text[a])) a++;
    let b = a, d = 0;
    for (; b < text.length; b++) {
      const ch = text[b];
      if (ch === '"') { b++; while (b < text.length && text[b] !== '"') b += text[b] === '\\' ? 2 : 1; if (!d) { b++; break; } continue; }
      if (ch === '{' || ch === '[') d++; else if (ch === '}' || ch === ']') { d--; if (!d) { b++; break; } }
      else if (!d && /[,\s}]/.test(ch)) break;
    }
    return text.slice(0, a) + arr + text.slice(b);
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
  /** הדירוג במפל (V8: גם @layer ו-@scope — details.js: rankOf). */
  const UNL = 999999999, INFP = 1e9;
  const rank = (r, important) => (g.KolDetails && g.KolDetails.rankOf ? g.KolDetails.rankOf(r, important) : [important ? 1 : 0, r.inline ? 1 : 0, important ? -(r.layer ?? UNL) : (r.layer ?? UNL), r.spec[0], r.spec[1], r.spec[2], -(r.prox ?? INFP), r.order]);
  /** דירוג של כלל שעוד לא קיים: בלי שכבה, בלי @scope (או של הכלל שלידו). */
  const rankNew = (spec, order, near) => rank({ spec, order, layer: near ? near.layer : UNL, prox: near ? near.prox : INFP }, false);
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
    info(n, pc) { return this.details.collect(n, pc ? { state: pc } : undefined); }
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
      // V10: מחלקות של מערכת התנועה (kol-in-*, kol-sc, bg-slides-N…) — כלל שלהן אינו היעד הראשון (ב-css/motion.css הוא משותף)
      const SYS = /^(kol-in(-[\w-]+)?|kol-sc|is-in|kol-motion-on|kol-slides-[\w-]+|bg-slides-\d+)$/, own = cls.filter(c => !SYS.test(c));
      const sysOnly = r => !own.some(c => new RegExp('\\.' + c.replace(/[^\w-]/g, '\\$&') + '(?![\\w-])').test(r.sel));
      const mine = rules.filter(r => ofClass(r) && !r.media).sort((a, b) => (sysOnly(a) - sysOnly(b)) || cmp(rank(b, false), rank(a, false)));
      const add = t => { const key = t.kind + '|' + (t.path || '') + '|' + (t.line || '') + '|' + t.sel; if (seen.has(key)) return; seen.add(key); out.push(t); };
      const ruleT = (r, why) => ({ kind: 'rule', sel: r.sel, path: r.path, line: r.line, media: r.media, r, why, count: this.count(r.sel) });
      for (const r of mine) if (!sysOnly(r)) add(ruleT(r, 'class'));
      if (!mine.some(r => !sysOnly(r))) {
        const page = this.pagePath();
        if (own.length && page) add({ kind: 'new', sel: '.' + own[0], path: page, why: 'class', count: this.count('.' + own[0]) });
        else if (page) add({ kind: 'newclass', sel: '.' + this.suggest(n), name: this.suggest(n), path: page, why: 'class', count: 1 });
      }
      for (const r of mine) if (sysOnly(r)) add(ruleT(r, 'class'));
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
    // ── V7: מכשירים ──
    /** המכשיר הנוכחי: null — מחשב (הבסיס); אחרת { id, label, max }. devices() — כל המכשירים, מהרחב לצר (מחשב: max null). */
    device() { return this.getDevice ? this.getDevice() : null; }
    devs() { return this.getDevices ? this.getDevices() : [{ id: 'desktop', label: 'מחשב', max: null }]; }
    /** האם max הוא של נקודת שבירה (ולא סתם max-width אחר, כמו 600px / 782px). */
    isDev(mx) { return mx != null && this.devs().some(d => d.max === mx); }
    /** היעד במכשיר: כלל (שאינו בתוך @media) / כלל חדש / מחלקה חדשה ← אותו בורר בתוך @media של המכשיר. יעד שכבר בתוך @media
     *  (אחר, או של המכשיר) נשאר כמו שהוא; יעד שקבע מכשיר במפורש ('dev' in t — גם null = הבסיס) — כמו שהוא. */
    dev(t, d) {
      d = d === undefined ? this.device() : d;
      if (!t || 'dev' in t || (t.kind === 'rule' && t.media)) return t;
      return d && d.max ? Object.assign({}, t, { dev: d }) : t;
    }
    /** הכלל של המכשיר ליעד, מתוך פאנל "פרטים" (אותו בורר, אותו קובץ, @media של המכשיר). */
    // ── V8: מצבים ──
    /** היעד במצב: { pc, of (בורר ההורה, או null), sel (בורר המצב), label }. בלי מצב — היעד כמו שהוא. */
    st(t, s) {
      if (!t || !s || !s.pc || t.kind === 'inline') return t;
      const base = t.kind === 'newclass' ? '.' + t.name : t.sel;
      return Object.assign({}, t, { state: { pc: s.pc, of: s.of || null, label: s.label || s.pc, sel: stateSel(base, s.pc, s.of) } });
    }
    /** כלל המצב של היעד בפאנל "פרטים": אותו קובץ; בורר המצב (או רשימה שהוא חלק ממנה); במכשיר — בתוך ה-@media שלו. */
    stOwn(info, t) {
      const want = normParts(t.state.sel), path = t.r ? t.r.path : t.path, mx = t.dev ? t.dev.max : null;
      return info.rules.filter(r => r.located && !r.inline && r.path === path && (mx == null ? !r.media : ruleMax(r) === mx) && (normSel(r.sel) === normSel(t.state.sel) || want.every(w => normParts(r.sel).includes(w)))).pop() || null;
    }
    devOwn(info, t) {
      const sel = normSel(t.state ? t.state.sel : t.kind === 'newclass' ? '.' + t.name : t.sel);
      return info.rules.filter(r => r.located && !r.inline && normSel(r.sel) === sel && ruleMax(r) === t.dev.max && r.path === (t.r ? t.r.path : t.path)).pop() || null;
    }
    /** ההצהרה ביעד (לאיפוס): במכשיר — בכלל של המכשיר. */
    ownDecl(n, t, phys, info) {
      const rtl = this.rtl(n);
      if (t.state && t.kind !== 'inline') { const r = this.stOwn(info || this.info(n, t.state.pc), t); return r && r.node ? declFor(r.node, phys, rtl) : null; }
      if (t.dev && t.kind !== 'inline') { const r = this.devOwn(info || this.info(n), t); return r && r.node ? declFor(r.node, phys, rtl) : null; }
      if (t.kind === 'rule') return declFor(t.r.node, phys, rtl);
      return null;
    }
    /** הערך בכל מכשיר, מהכללים (לא מהרינדור): מחשב — הכללים בלי @media; מכשיר — גם @media של כל מכשיר רחב ממנו או שווה.
     *  [{ d, value, own (נכתב במכשיר הזה), from (המכשיר שממנו הוא עובר: id) }]. @media שאינו מכשיר — אינו נספר כאן. */
    perDevice(n, phys, info) {
      info = info || this.info(n);
      const rtl = this.rtl(n), devs = this.devs(), out = [];
      for (const d of devs) {
        let w = null;
        for (const r of info.rules) {
          const mx = r.inline ? null : r.media ? ruleMax(r) : null;
          if (r.media && !this.isDev(mx)) continue;   // @media שאינו מכשיר — אינו נספר כאן
          if (mx != null && (d.max == null || mx < d.max)) continue;
          for (const dc of r.decls) if (covered(dc.prop, rtl).includes(phys)) { const k = rank(r, dc.important); if (!w || cmp(k, w.k) >= 0) w = { r, d: dc, k, mx }; }
        }
        const src = w ? (w.mx == null ? devs[0] : devs.find(x => x.max === w.mx) || null) : null;
        out.push({ d, value: w ? (componentOf(w.d.prop, w.d.value, phys, rtl) ?? w.d.value) : '', own: !!w && (d.max == null ? w.mx == null : w.mx === d.max), from: src ? src.id : null, w });
      }
      return out;
    }
    /** האם כתיבה ליעד תיראה. מחזיר { ok, by (מי דורס), media (נכתב שם — 03: "עורך את ההצהרה ההיא") }. */
    visible(n, t, phys, info) {
      info = info || this.info(n);
      const rtl = this.rtl(n), cs = this.cands(info, phys, rtl);
      let mineK, own = null;
      if (t.dev && t.kind !== 'inline') {   // V7: היעד הוא הכלל של המכשיר (קיים או שייווצר אחרי הבסיס)
        own = this.devOwn(info, t);
        const oc = own && cs.filter(c => c.r === own);
        // כלל שעוד לא קיים: הייחודיות של החלק שחל (לא של כל הרשימה), ומיקום — מיד אחרי הכלל הבסיסי (בלי בסיס — בסוף הקובץ)
        const sb = t.state ? this.stOwn(info, Object.assign({}, t, { dev: null })) : null;   // מצב × מכשיר: נכתב אחרי כלל המצב של המחשב
        mineK = oc && oc.length ? oc[0].k : own ? rank(own, false) : rankNew(t.state ? g.KolDetails.specificity(splitList(t.state.sel)[0]) : t.r ? t.r.spec : g.KolDetails.specificity(t.kind === 'newclass' ? '.' + t.name : t.sel), sb ? sb.order + 0.5 : t.r ? t.r.order + 0.5 : 1e9, t.r);
        const by = cs.find(c => c.r !== own && cmp(c.k, mineK) > 0);
        if (!by) return { ok: true };
        return { ok: false, by, media: !!by.r.media && by.r.located && !by.r.external && !this.isDev(ruleMax(by.r)) };
      }
      if (t.state && t.kind !== 'inline') {   // V8: היעד הוא כלל המצב (קיים, או שייכתב מיד אחרי הבסיס)
        own = this.stOwn(info, t); const oc = own && cs.filter(c => c.r === own);
        mineK = oc && oc.length ? oc[0].k : own ? rank(own, false) : rankNew(g.KolDetails.specificity(t.state.sel.split(',')[0]), t.r ? t.r.order + 0.5 : 1e9, t.r);
        const by = cs.find(c => c.r !== own && cmp(c.k, mineK) > 0);
        return by ? { ok: false, by, media: false } : { ok: true };
      }
      if (t.kind === 'rule') { const d = declFor(t.r.node, phys, rtl); mineK = rank(t.r, d ? d.important : false); const own = cs.filter(c => c.r === t.r); if (own.length) mineK = own[0].k; }
      else if (t.kind === 'inline') { const own = cs.find(c => c.r.inline); mineK = own ? own.k : rank({ inline: true, spec: [0, 0, 0], order: 1e9 }, false); }
      else mineK = rankNew(g.KolDetails.specificity(t.sel), 1e9);
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
      if (t.state) {   // V8: כתיבה למצב — <יעד>:<מצב> מיד אחרי כלל היעד (במכשיר — בתוך ה-@media שלו)
        if (t.kind === 'inline') throw new K2.SrcError('ל-style="" אין מצבים (ריחוף, מיקוד…). בוחרים ב"משנה את" את כלל המחלקה.', 'state');
        if (opt.unimportant) return [];
        let doc, text, off = 0, inHtml = false, baseFrom = -1;
        if (t.kind === 'rule') { const src = t.r.src; baseFrom = t.r.node.from; if (src.html) { inHtml = true; doc = html; text = src.text; off = src.off; } else { doc = this.docOf(src.path); text = doc && doc.text(); } }
        else { doc = this.docOf(t.path); text = doc && doc.text(); }
        if (!doc) throw new K2.SrcError('קובץ העיצוב ' + (t.path || '') + ' אינו פתוח לעריכה', 'missing');
        // מצב × מכשיר: הבלוק נכתב אחרי כלל המצב של המחשב, אם יש (אחרת — אחרי הבסיס): כך הוא מנצח אותו ברוחב של המכשיר
        let devBase = baseFrom;
        if (t.dev) { const m0 = K2.parseCss(text), b0 = baseFrom >= 0 ? K2.cssAll(m0).find(x => x.type === 'rule' && x.from === baseFrom) : null, sr = stateRule(m0, t.state.sel, b0); if (sr && (!b0 || sr.from > b0.from)) devBase = sr.from; }
        const out = t.dev ? planDevice(text, { sel: t.state.sel, max: t.dev.max, baseFrom: devBase, rtl, sets }) : planState(text, { sel: t.state.sel, baseFrom, rtl, sets });
        const ps = diff(text, out.text).map(p => ({ from: p.from + off, to: p.to + off, text: p.text }));
        const ops = ps.length ? [inHtml ? { doc: html, html: true, patches: ps } : { doc, patches: ps }] : [];
        if (t.kind === 'newclass' && ps.length) ops.unshift({ doc: html, html: true, patches: K2.html.addClass(this.canvas.m, n, t.name).patches });
        return Object.assign(ops, { notes: out.notes, shared: out.shared });
      }
      if (t.dev) {   // V7: כתיבה למכשיר — בתוך @media (max-width: <max>px) של נקודת השבירה
        if (t.kind === 'inline') throw new K2.SrcError('"רק האלמנט הזה" (style="") אינו יכול לקבל מכשיר — ל-style אין @media. בוחרים ב"משנה את" את כלל המחלקה (או "מחלקה חדשה" עם שם קריא), וכך הערך נכתב ל' + t.dev.label + ' בלבד.', 'device');
        if (opt.unimportant) return [];
        const sel = t.kind === 'newclass' ? '.' + t.name : t.sel;
        let doc, text, off = 0, inHtml = false, baseFrom = -1;
        if (t.kind === 'rule') { const src = t.r.src; baseFrom = t.r.node.from; if (src.html) { inHtml = true; doc = html; text = src.text; off = src.off; } else { doc = this.docOf(src.path); text = doc && doc.text(); } }
        else { doc = this.docOf(t.path); text = doc && doc.text(); }
        if (!doc) throw new K2.SrcError('קובץ העיצוב ' + (t.path || '') + ' אינו פתוח לעריכה', 'missing');
        const out = planDevice(text, { sel, max: t.dev.max, baseFrom, rtl, sets }), ps = diff(text, out.text).map(p => ({ from: p.from + off, to: p.to + off, text: p.text }));
        const ops = ps.length ? [inHtml ? { doc: html, html: true, patches: ps } : { doc, patches: ps }] : [];
        if (t.kind === 'newclass' && ps.length) ops.unshift({ doc: html, html: true, patches: K2.html.addClass(this.canvas.m, n, t.name).patches });
        return Object.assign(ops, { notes: out.notes });
      }
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

  g.KolStyle = { stateSel, checkState, checkSelector, stateRule, planState, removeDeclAt, mediaRetarget, mediaCount, kolBreakpoints, mediaMax, devCond, ruleMax, devRule, devBlock, planDevice, flexParse, checkValue, splitTop, covered, logical, lineParse, setComponent, componentOf, fontParse, declFor, planRule, planReset, planUnimportant, planInline, planNewRule, subject, Styler, INHERITED };
})(typeof window !== 'undefined' ? window : globalThis);
