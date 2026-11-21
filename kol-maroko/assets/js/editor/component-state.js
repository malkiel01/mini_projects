/**
 * קול מרוקו — 🎨 עורך (פרוסה V12): רכיבים — החלק הטהור (בלי DOM; נבדק ב-tests/editor/component-state.mjs).
 *
 * רכיב = components/<שם>/{component.json, template.html, style.css, script.js} (02 §7). מופע = ה-HTML המלא של הרכיב בתוך
 * העמוד, עם data-kol-component="<שם>" על השורש (02 §7.2). כאן:
 *   · parseSchema — component.json ← { name, label, icon, category, fields }, ושגיאה עם שורה כשאינו תקין.
 *   · sel / findIn / findAllIn — בורר מצומצם (תגית, #id, .מחלקה, [תכונה], [תכונה="ערך"], רווח, >) על מודל המקור (KolSrc).
 *   · readValues — ערכי ההגדרות **מהמופע עצמו** (לא משמור צדדי).
 *   · planField — היעד של 02 §7.1 ← תיקוני טקסט: text · attr · class · var · src · children.
 *   · render — template.html + ערכים ← ה-HTML של המופע (אותם מתכננים — מה שהפאנל כותב ומה שהציור מצייר הם אותו קוד).
 *   · state — מקושר / שונה / מנותק: **מחושב, לא נשמר** — מציירים את התבנית עם ערכי המופע, ומשווים אחרי נרמול.
 *   · diffLines — "הצג את ההבדלים".
 * כל ערך שנכתב לקובץ עובר כאן בדיקה (שם תכונה, on*, javascript:, משתנה CSS, מחלקה מתוך הקבוצה) — הזרקה נדחית.
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc;
  const NAME = /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/;
  const CONTROLS = ['text', 'rich', 'number', 'size', 'color', 'font', 'select', 'toggle', 'media', 'link', 'repeater'];
  const ATTR = /^[a-zA-Z_][-a-zA-Z0-9_.:]*$/;
  const VAR = /^--[a-zA-Z_][\w-]*$/;
  const CLS = /^-?[_a-zA-Z][\w-]*$/;
  const ID = /^[a-zA-Z_][\w-]*$/;
  const MARK = 'data-kol-component';
  const BAD_URL = /^\s*(javascript|vbscript|data\s*:\s*text\/html)\s*:?/i;
  const err = (msg, code) => { const K0 = K(); const e = K0 && K0.SrcError ? new K0.SrcError(msg, code || 'invalid') : new Error(msg); e.code = code || 'invalid'; return e; };

  // ── הבורר ──────────────────────────────────────────────
  /** "a .b > c[x='1']" ← [{ comb: ' ' | '>', tag, id, classes, attrs: [[שם, ערך | null]] }]. בורר אחר — שגיאה ברורה. */
  function sel(text) {
    const s = String(text == null ? '' : text).trim();
    if (!s || s.length > 200) throw err('בורר ריק או ארוך מדי');
    const out = []; let i = 0, comb = ' ';
    while (i < s.length) {
      if (/\s/.test(s[i])) { i++; if (comb !== '>') comb = ' '; continue; }
      if (s[i] === '>') { comb = '>'; i++; continue; }
      const c = { comb: out.length ? comb : ' ', tag: '', id: '', classes: [], attrs: [] };
      const m0 = /^(\*|[a-zA-Z][a-zA-Z0-9-]*)/.exec(s.slice(i)); if (m0) { c.tag = m0[1] === '*' ? '' : m0[1].toLowerCase(); i += m0[1].length; }
      let any = !!m0;
      for (;;) {
        const r = s.slice(i);
        let m = /^\.(-?[_a-zA-Z][\w-]*)/.exec(r); if (m) { c.classes.push(m[1]); i += m[0].length; any = true; continue; }
        m = /^#([a-zA-Z_][\w-]*)/.exec(r); if (m) { c.id = m[1]; i += m[0].length; any = true; continue; }
        m = /^\[\s*([a-zA-Z_][-\w.:]*)\s*(?:=\s*(?:"([^"]*)"|'([^']*)'|([^\]\s'"]+)))?\s*\]/.exec(r);
        if (m) { c.attrs.push([m[1].toLowerCase(), m[2] != null ? m[2] : m[3] != null ? m[3] : m[4] != null ? m[4] : null]); i += m[0].length; any = true; continue; }
        break;
      }
      if (!any || (i < s.length && !/[\s>]/.test(s[i]))) throw err('בורר לא נתמך: "' + s + '" — תגית, ‎.מחלקה, ‎#id, ‎[תכונה], רווח ו-> בלבד');
      out.push(c); comb = ' ';
    }
    if (!out.length) throw err('בורר ריק');
    return out;
  }
  function one(n, c) {
    if (!n || !n.tag) return false;
    if (c.tag && n.tag !== c.tag) return false;
    const A = K().attrOf;
    if (c.id && (A(n, 'id') || {}).value !== c.id) return false;
    if (c.classes.length) { const cl = K().classesOf(n); if (!c.classes.every(x => cl.includes(x))) return false; }
    for (const [a, v] of c.attrs) { const x = A(n, a); if (!x || (v != null && x.value !== v)) return false; }
    return true;
  }
  /** האם n מתאים לבורר, כשהאבות נבדקים רק עד root (כולל). */
  function matches(n, list, root) {
    const go = (node, i) => {
      if (!one(node, list[i])) return false;
      if (i === 0) return true;
      if (node === root) return false;
      if (list[i].comb === '>') return go(node.parent, i - 1);
      for (let p = node.parent; p; p = p.parent) { if (go(p, i - 1)) return true; if (p === root) break; }
      return false;
    };
    return go(n, list.length - 1);
  }
  function findAllIn(root, text) {
    const list = typeof text === 'string' ? sel(text) : text, out = [];
    K().walk(root, n => { if (!n.implied && matches(n, list, root)) out.push(n); });
    return out;
  }
  const findIn = (root, text) => findAllIn(root, text)[0] || null;

  // ── הסכמה ──────────────────────────────────────────────
  /** שורה ועמודה של שגיאת JSON (כמו kol.json): מנסים את המיקום מההודעה; בלי — לפי הניתוח החלקי. */
  function jsonError(text, e) {
    const msg = String(e && e.message || e);
    let at = null; const p = /position (\d+)/i.exec(msg); if (p) at = +p[1]; else at = jsonAt(text);
    const lc = /line (\d+) column (\d+)/i.exec(msg);
    let line = lc ? +lc[1] : 1;
    if (at != null) { line = 1; for (let i = 0; i < at && i < text.length; i++) if (text.charCodeAt(i) === 10) line++; }
    return { line, message: msg };
  }
  /** המיקום של שגיאת התחביר הראשונה ב-JSON (הדפדפן / Node אינם תמיד מוסרים אותו). */
  function jsonAt(t) {
    let i = 0; const ws = () => { while (i < t.length && /\s/.test(t[i])) i++; }, fail = () => { throw i; };
    const val = () => {
      ws(); const c = t[i];
      if (c === '{') { i++; ws(); if (t[i] === '}') { i++; return; } for (;;) { ws(); if (t[i] !== '"') fail(); strg(); ws(); if (t[i] !== ':') fail(); i++; val(); ws(); if (t[i] === ',') { i++; continue; } if (t[i] === '}') { i++; return; } fail(); } }
      if (c === '[') { i++; ws(); if (t[i] === ']') { i++; return; } for (;;) { val(); ws(); if (t[i] === ',') { i++; continue; } if (t[i] === ']') { i++; return; } fail(); } }
      if (c === '"') return strg();
      const m = /^(-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?|true|false|null)/.exec(t.slice(i)); if (!m) fail(); i += m[0].length;
    };
    const strg = () => { i++; while (i < t.length && t[i] !== '"') { if (t[i] === '\\') i++; else if (t.charCodeAt(i) < 32) fail(); i++; } if (i >= t.length) fail(); i++; };
    try { val(); ws(); if (i < t.length) fail(); return null; } catch (x) { return typeof x === 'number' ? x : null; }
  }
  /** component.json ← סכמה מנורמלת. שגיאה: { error, line }. שדה פגום — נשמר עם bad (הפאנל מציג אותו מושבת, עם הסיבה). */
  function parseSchema(text, folder) {
    let j; const src = String(text == null ? '' : text).replace(/^﻿/, '');
    try { j = JSON.parse(src); } catch (e) { const x = jsonError(src, e); return { error: 'component.json אינו JSON תקין — שורה ' + x.line + ': ' + x.message, line: x.line }; }
    if (!j || typeof j !== 'object' || Array.isArray(j)) return { error: 'component.json צריך להיות אובייקט { … }', line: 1 };
    const name = typeof j.name === 'string' && j.name ? j.name : folder || '';
    if (!NAME.test(name) || name.length > 40) return { error: 'שם הרכיב "' + name + '" — באנגלית, אותיות קטנות, ספרות ומקפים (kebab-case)', line: 1 };
    if (folder && name !== folder) return { error: 'השם ב-component.json ("' + name + '") שונה משם התיקייה ("' + folder + '")', line: 1 };
    const s = { name, label: str(j.label, 60) || name, icon: str(j.icon, 8) || '🧩', category: str(j.category, 40) || 'כללי', fields: [] };
    const seen = new Set();
    for (const f of Array.isArray(j.fields) ? j.fields.slice(0, 80) : []) {
      if (!f || typeof f !== 'object') continue;
      const x = { id: str(f.id, 60), label: str(f.label, 80) || str(f.id, 60), control: str(f.control, 20) || 'text', target: f.target && typeof f.target === 'object' ? f.target : {}, options: Array.isArray(f.options) ? f.options.filter(o => typeof o === 'string' || typeof o === 'number').map(String).slice(0, 60) : [], default: f.default };
      try { checkField(x); } catch (e) { x.bad = e.message; }
      if (!x.id || seen.has(x.id)) x.bad = x.bad || (x.id ? 'מזהה כפול: ' + x.id : 'לשדה אין id');
      seen.add(x.id);
      s.fields.push(x);
    }
    return s;
  }
  function str(v, max) { return typeof v === 'string' ? v.trim().slice(0, max) : ''; }
  /** סוג היעד של שדה: text / attr / class / var / children / src. */
  function kindOf(f) { const t = f.target || {}; for (const k of ['text', 'attr', 'class', 'var', 'children', 'src']) if (t[k] != null) return k; return null; }
  function checkField(f) {
    if (!f.id || !/^[a-zA-Z_][\w-]{0,59}$/.test(f.id)) throw err('id של שדה — אותיות לטיניות, ספרות, _ ו-- (' + (f.id || 'ריק') + ')');
    if (!CONTROLS.includes(f.control)) throw err('סוג שליטה לא מוכר: ' + f.control);
    const t = f.target, k = kindOf(f);
    if (!k) throw err('לשדה ' + f.id + ' אין יעד (text / attr / class / var / children / src)');
    if (t.on != null) sel(t.on);
    if (k === 'text' || k === 'children' || k === 'src') sel(t[k]);
    if (k === 'src' && t.attr != null && !/^(src|href|poster)$/.test(t.attr)) throw err('יעד src כותב רק ל-src / href / poster');
    if (k === 'attr') checkAttrName(t.attr);
    if (k === 'var' && !VAR.test(t.var)) throw err('שם משתנה CSS — ‎--שם (' + t.var + ')');
    if (k === 'class') {
      if (typeof t.class !== 'string' || !t.class) throw err('יעד class צריך תבנית, למשל "acc--{value}"');
      for (const o of f.options) if (!CLS.test(String(t.class).split('{value}').join(o))) throw err('האפשרות "' + o + '" אינה שם מחלקה תקין');
    }
    if (k === 'children' && f.control !== 'repeater') f.control = 'repeater';
  }
  function checkAttrName(name) {
    if (typeof name !== 'string' || !ATTR.test(name)) throw err('שם תכונה לא תקין: ' + name);
    if (/^on/i.test(name) || /^(style|srcdoc|data-kol-component)$/i.test(name)) throw err('התכונה ' + name + ' אינה נחשפת כהגדרה (קוד / עיצוב / סימון הרכיב)');
  }
  function checkUrl(v) { if (BAD_URL.test(String(v))) throw err('כתובת מסוג javascript: / data:text/html אינה נכתבת'); }
  function checkVarValue(v) {
    const s = String(v);
    if (/[;{}<>]/.test(s) || /\/\*/.test(s) || s.length > 300) throw err('ערך לא תקין למשתנה: ' + s.slice(0, 40));
    let d = 0; for (const ch of s) { if (ch === '(') d++; else if (ch === ')' && --d < 0) break; }
    if (d !== 0) throw err('סוגריים לא מאוזנים בערך');
    if (/url\(/i.test(s) && BAD_URL.test(s.replace(/^.*url\(\s*['"]?/i, ''))) throw err('url() מסוג javascript: אינו נכתב');
  }

  // ── שורש התבנית והמופעים ───────────────────────────────
  /** השורש של template.html: האלמנט הכתוב הראשון בגוף (הקובץ הוא קטע HTML). */
  function rootOf(m) {
    if (!m.root) return null;
    const body = m.root.kids.find(k => k.tag === 'body');
    return (body && body.kids.find(k => !k.implied)) || null;
  }
  const outerOf = (m, n) => m.src.slice(n.from, K().endOf(m, n));
  /** כל המופעים בעמוד: [{ node, name }] (גם מופע בתוך מופע — רמה אחת של עדכון, 04 "מה לא כלול"). */
  function instances(m, name) {
    const out = [];
    if (m.root) K().walk(m.root, n => { const a = !n.implied && K().attrOf(n, MARK); if (a && (!name || a.value === name)) out.push({ node: n, name: a.value }); });
    return out;
  }
  /** המופע שהצומת שייך אליו (הקרוב ביותר), או null. */
  function instanceOf(n) { for (let p = n; p; p = p.parent) if (!p.implied && K().attrOf(p, MARK)) return p; return null; }

  // ── קריאה וכתיבה של ערך ───────────────────────────────
  function targetEl(root, f) {
    const t = f.target, k = kindOf(f);
    const s = k === 'text' ? t.text : k === 'src' ? t.src : k === 'children' ? t.children : t.on;
    if (!s) return root;
    return k === 'children' ? findAllIn(root, s)[0] || null : findIn(root, s);
  }
  const missSel = f => { const t = f.target; return t.text || t.src || t.children || t.on || ''; };
  /** כתיבת תכונה: < ו-> מקודדים גם בתוך הערך (כמו identity.setAttr של V8). */
  function setA(m, el, name, value) { return K().html.setAttr(m, el, name, value).patches.map(p => ({ from: p.from, to: p.to, text: p.text.replace(/</g, '&lt;').replace(/>/g, '&gt;') })); }
  function srcAttr(n, f) { return f.target.attr || (/^(a|link|area)$/.test(n.tag) ? 'href' : 'src'); }
  function styleDecls(style) {
    try { const m = K().parseCss('x{' + style + '}'), r = m.root.kids[0]; return r ? r.decls : []; } catch { return []; }
  }
  function styleGet(style, prop) { const d = styleDecls(style).find(x => x.prop.trim().toLowerCase() === prop.toLowerCase()); return d ? d.value.trim() : null; }
  function styleEdit(style, prop, value) {
    const decls = styleDecls(style), d = decls.find(x => x.prop.trim().toLowerCase() === prop.toLowerCase());
    if (d && value != null) return style.slice(0, d.valFrom - 2) + value + style.slice(d.valTo - 2);
    if (d) return decls.filter(x => x !== d).map(x => style.slice(x.from - 2, x.to - 2).trim().replace(/;$/, '')).join('; ');
    if (value == null) return style;
    const base = style.replace(/[\s;]+$/, '');
    return (base ? base + '; ' : '') + prop + ': ' + value;
  }
  /** הילדים של repeater: המתאימים לבורר שהם אחים של הראשון (ילדים של אותו מכל). */
  function itemsOf(root, f) {
    const all = findAllIn(root, f.target.children); if (!all.length) return [];
    return all.filter(n => n.parent === all[0].parent);
  }
  /** הערך של שדה, מהמופע. undefined — החלק חסר; null — אין ערך (ברירת המחדל של התבנית). */
  function readField(m, root, f) {
    const k = kindOf(f), A = K().attrOf;
    if (k === 'var') { const st = A(root, 'style'); return st ? styleGet(st.value, f.target.var) : null; }
    if (k === 'children') {
      const items = itemsOf(root, f); if (!items.length) { const any = findIn(root, f.target.children); if (!any) return { count: 0, raw: '', items: [] }; }
      return { count: items.length, raw: items.length ? m.src.slice(items[0].from, K().endOf(m, items[items.length - 1])) : '', items: items.map(n => outerOf(m, n)) };
    }
    const el = targetEl(root, f); if (!el) return undefined;
    if (k === 'text') { try { return K().textOf(m, el); } catch { return undefined; } }
    if (k === 'attr') { const a = A(el, f.target.attr); if (f.control === 'toggle') return !!a; return a ? a.value : null; }
    if (k === 'src') { const a = A(el, srcAttr(el, f)); return a ? a.value : null; }
    if (k === 'class') {
      const cl = K().classesOf(el); for (const o of f.options) if (cl.includes(f.target.class.split('{value}').join(o))) return o;
      return null;
    }
    return null;
  }
  function readValues(m, root, fields) {
    const values = {}, missing = [];
    for (const f of fields) { if (f.bad) continue; const v = readField(m, root, f); if (v === undefined) missing.push(f.id); else values[f.id] = v; }
    return { values, missing };
  }
  /** היעד ← תיקונים במודל m (המופע ששורשו root). value === null / undefined — אין מה לכתוב. */
  function planField(m, root, f, value) {
    const H = K().html, A = K().attrOf, k = kindOf(f);
    if (f.bad) throw err(f.bad);
    if (value === undefined) return [];
    if (k === 'var') {
      const st = A(root, 'style'), cur = st ? st.value : '';
      if (value != null && value !== '') checkVarValue(value);
      const next = styleEdit(cur, f.target.var, value === '' ? null : value);
      if (next === cur) return [];
      if (!next.trim()) return st ? H.removeAttr(m, root, 'style').patches : [];
      return setA(m, root, 'style', next);
    }
    if (k === 'children') {
      if (value == null) return [];
      const items = itemsOf(root, f);
      if (!items.length) { if (!value.raw) return []; throw err('החלק "' + f.target.children + '" חסר', 'missing'); }
      const from = items[0].from, to = K().endOf(m, items[items.length - 1]);
      return m.src.slice(from, to) === value.raw ? [] : [{ from, to, text: value.raw }];
    }
    const el = targetEl(root, f);
    if (!el) throw err('החלק "' + missSel(f) + '" חסר במופע', 'missing');
    if (value === null) return [];
    if (k === 'text') { if (K().textOf(m, el) === String(value)) return []; return H.setText(m, el, String(value)).patches; }
    if (k === 'attr') {
      const name = f.target.attr; checkAttrName(name);
      const a = A(el, name);
      if (f.control === 'toggle') { if (value && !a) return H.setAttr(m, el, name, null).patches; if (!value && a) return H.removeAttr(m, el, name).patches; return []; }
      if (/^(href|src|action|formaction|poster|xlink:href)$/i.test(name)) checkUrl(value);
      if (value === '') return a ? H.removeAttr(m, el, name).patches : [];
      return a && a.value === String(value) ? [] : setA(m, el, name, String(value));
    }
    if (k === 'src') {
      const name = srcAttr(el, f), a = A(el, name); checkUrl(value);
      if (value === '') return a ? H.removeAttr(m, el, name).patches : [];
      return a && a.value === String(value) ? [] : setA(m, el, name, String(value));
    }
    if (k === 'class') {
      const pat = f.target.class, group = f.options.map(o => pat.split('{value}').join(o));
      if (value !== '' && !f.options.includes(String(value))) throw err('"' + value + '" אינה אחת האפשרויות');
      const want = value === '' ? null : pat.split('{value}').join(String(value));
      if (want && !CLS.test(want)) throw err('שם מחלקה לא תקין: ' + want);
      const cur = K().classesOf(el), next = cur.filter(c => !group.includes(c) || c === want);
      if (want && !next.includes(want)) next.push(want);
      if (next.join(' ') === cur.join(' ')) return [];
      if (!next.length) return H.removeAttr(m, el, 'class').patches;
      return setA(m, el, 'class', next.join(' '));
    }
    return [];
  }

  // ── הציור ──────────────────────────────────────────────
  /** template.html + ערכים ← { html, missing }. השורש מקבל data-kol-component; repeater קודם (הפריטים של המופע), ואז השאר. */
  function render(tplText, schema, values) {
    let m = K().parseHtml(String(tplText)), root = rootOf(m);
    if (!root) throw err('template.html של ' + schema.name + ' ריק — אין בו אלמנט', 'empty');
    let text = outerOf(m, root);
    const step = fn => { const mm = K().parseHtml(text), r = rootOf(mm), ps = fn(mm, r); if (ps.length) text = K().applyPatches(text, ps); };
    step((mm, r) => { const a = K().attrOf(r, MARK); return a && a.value === schema.name ? [] : K().html.setAttr(mm, r, MARK, schema.name).patches; });
    const missing = [], vals = values || {};
    const order = schema.fields.filter(f => !f.bad && kindOf(f) === 'children').concat(schema.fields.filter(f => !f.bad && kindOf(f) !== 'children'));
    for (const f of order) {
      if (!(f.id in vals)) continue;
      try { step((mm, r) => planField(mm, r, f, vals[f.id])); }
      catch (e) { if (e.code === 'missing') missing.push(f.id); else throw e; }
    }
    return { html: text, missing };
  }
  /** ערכי ברירת המחדל של הסכמה (default של כל שדה), להוספה לעמוד. */
  function defaults(schema) {
    const v = {};
    for (const f of schema.fields) if (!f.bad && f.default != null && kindOf(f) !== 'children') v[f.id] = f.control === 'toggle' ? !!f.default : String(f.default);
    return v;
  }

  // ── הנרמול וההשוואה ───────────────────────────────────
  function canonAttr(name, value) {
    if (name === 'class') return value.split(/\s+/).filter(Boolean).sort().join(' ');
    if (name === 'style') return styleDecls(value).map(d => d.prop.trim().toLowerCase() + ':' + d.value.trim().replace(/\s+/g, ' ')).sort().join(';');
    return value;
  }
  function canonNode(m, n) {
    const attrs = n.attrs.map(a => a.name.toLowerCase() + '=' + JSON.stringify(canonAttr(a.name.toLowerCase(), a.value))).sort().join(' ');
    let s = '<' + n.tag + (attrs ? ' ' + attrs : '') + '>';
    for (const x of n.nodes) {
      if (x.type === 'el') s += canonNode(m, x.node);
      else if (x.type === 'text') { const t = x.value.replace(/[ \t\n\r\f]+/g, ' ').trim(); if (t) s += JSON.stringify(t); }
    }
    return s + '</' + n.tag + '>';
  }
  /** HTML של שורש אחד ← צורה קנונית: רווחים מכווצים, סדר תכונות ומחלקות והצהרות style ממוינים, בלי הערות. */
  function canon(html) { const m = K().parseHtml(String(html)), r = rootOf(m); return r ? canonNode(m, r) : ''; }
  /**
   * המצב של מופע: { state: 'linked' | 'modified' | 'detached', values, missing, expected, actual }.
   * comp: { schema, template } — הטקסט של template.html ו-component.json המנותח. רכיב שאין לו קבצים — 'missing'.
   */
  function state(m, root, comp) {
    const a = K().attrOf(root, MARK), actual = outerOf(m, root);
    if (!a) return { state: 'detached', actual };
    if (!comp || comp.template == null || !comp.schema || comp.schema.error) return { state: 'missing', actual, error: comp && comp.schema && comp.schema.error };
    const { values, missing } = readValues(m, root, comp.schema.fields);
    let expected;
    try { expected = render(comp.template, comp.schema, values).html; } catch (e) { return { state: 'missing', actual, error: e.message }; }
    return { state: canon(actual) === canon(expected) ? 'linked' : 'modified', values, missing, expected, actual };
  }
  /** עדכון מופע לתבנית חדשה: הערכים נקראים בסכמה הישנה (לפי id), ונכתבים בחדשה. */
  function updated(m, root, oldSchema, newSchema, newTpl) {
    const { values } = readValues(m, root, oldSchema.fields), keep = {};
    for (const f of newSchema.fields) if (f.id in values) keep[f.id] = values[f.id];
    return render(newTpl, newSchema, keep).html;
  }

  // ── "הצג את ההבדלים" ──────────────────────────────────
  /** diff שורות (LCS): [{ t: ' ' | '-' | '+', line }]. השוואה בלי ההזחה; עד 400 שורות לכל צד. */
  function diffLines(a, b) {
    const A = String(a).split(/\r?\n/).slice(0, 400), B = String(b).split(/\r?\n/).slice(0, 400), ka = A.map(x => x.trim()), kb = B.map(x => x.trim());
    const L = Array.from({ length: A.length + 1 }, () => new Int16Array(B.length + 1));
    for (let i = A.length - 1; i >= 0; i--) for (let j = B.length - 1; j >= 0; j--) L[i][j] = ka[i] === kb[j] ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1]);
    const out = []; let i = 0, j = 0;
    while (i < A.length && j < B.length) { if (ka[i] === kb[j]) { out.push({ t: ' ', line: B[j] }); i++; j++; } else if (L[i + 1][j] >= L[i][j + 1]) out.push({ t: '-', line: A[i++] }); else out.push({ t: '+', line: B[j++] }); }
    while (i < A.length) out.push({ t: '-', line: A[i++] });
    while (j < B.length) out.push({ t: '+', line: B[j++] });
    return out;
  }

  // ── יצירת רכיב ─────────────────────────────────────────
  /** שם לתצוגה / כל טקסט ← שם תיקייה (kebab-case). עברית / ריק — 'component'. */
  function slug(text) {
    const s = String(text || '').normalize('NFKD').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/, '');
    return NAME.test(s) ? s : 'component';
  }
  function checkName(name) { if (!NAME.test(String(name)) || String(name).length > 40) throw err('שם הרכיב — באנגלית, אותיות קטנות, ספרות ומקפים (kebab-case), עד 40 תווים'); return name; }
  /** component.json חדש (טקסט, מוזח): { name, label, icon, category, fields: [] }. */
  function schemaText(o) {
    const j = { name: checkName(o.name), label: str(o.label, 60) || o.name, icon: str(o.icon, 8) || '🧩', category: str(o.category, 40) || 'כללי', fields: Array.isArray(o.fields) ? o.fields : [] };
    return JSON.stringify(j, null, 2) + '\n';
  }
  /** מוסיף שדה ל-component.json — תיקון טקסט שמשנה רק את "fields" (שאר הקובץ — כמו שנכתב). מחזיר את הטקסט החדש. */
  function addFieldText(text, field) {
    const s = parseSchema(text); if (s.error) throw err(s.error);
    const x = { id: field.id, label: field.label, control: field.control, target: field.target };
    if (field.options && field.options.length) x.options = field.options;
    if (field.default != null) x.default = field.default;
    checkField(Object.assign({ options: [] }, x, { options: x.options || [] }));
    if (s.fields.some(f => f.id === x.id)) throw err('כבר יש הגדרה בשם ' + x.id);
    const j = JSON.parse(String(text).replace(/^﻿/, '')); j.fields = Array.isArray(j.fields) ? j.fields : []; j.fields.push(x);
    return JSON.stringify(j, null, 2) + '\n';
  }
  /** מסיר / מזיז שדה ב-component.json. */
  function editFieldsText(text, fn) {
    const j = JSON.parse(String(text).replace(/^﻿/, '')); j.fields = Array.isArray(j.fields) ? j.fields : [];
    j.fields = fn(j.fields.slice()) || j.fields;
    return JSON.stringify(j, null, 2) + '\n';
  }
  /** מזהה פנוי לשדה, מהשם לתצוגה. */
  function fieldId(label, taken) {
    let base = slug(label).replace(/-/g, '_'); if (base === 'component') base = 'field';
    let id = base, i = 2; while ((taken || []).includes(id)) id = base + '_' + i++;
    return id;
  }
  /** בורר מחלקה לחלק ברכיב: מחלקה קיימת שמזהה אותו לבדו בתוך השורש; אחרת null (העורך ייתן מחלקה חדשה). */
  function selectorFor(root, n) {
    if (n === root) return null;
    for (const c of K().classesOf(n)) { if (!CLS.test(c)) continue; const all = findAllIn(root, '.' + c); if (all.length && all[0] === n) return '.' + c; }
    return null;
  }

  g.KolComp = { MARK, NAME, CONTROLS, sel, findIn, findAllIn, matches, parseSchema, kindOf, checkField, checkAttrName, checkUrl, checkVarValue, rootOf, outerOf, instances, instanceOf,
    readField, readValues, planField, render, defaults, canon, state, updated, diffLines, slug, checkName, schemaText, addFieldText, editFieldsText, fieldId, selectorFor, styleGet, styleEdit, itemsOf, jsonError };
})(typeof window !== 'undefined' ? window : globalThis);
