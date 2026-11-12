/**
 * העורך החזותי — "🌐 סגנון כללי" (פרוסה V9; 03 §8.3): גופנים, משתנים, ברירות מחדל לתגיות.
 *
 * החלק הטהור (KolGlobals.*) — בלי DOM, נבדק ב-tests/editor/globals.mjs: איסוף המשתנים מכל קובצי ה-CSS, תכנון כתיבה
 * (ערך משתנה, שם חדש בכל ההופעות, משתנה חדש ב-:root, החלפת ערך ב-var() ב-N מקומות), @font-face, <link> של Google Fonts
 * (הוספה בכל עמוד, הסרה של משפחה אחת מתוך href), "איפה בשימוש", והחלפת גופן בכל מקום.
 *
 * כתיבה ל-CSS — רק מתבניות מוגבלות: שם משפחה (אותיות, ספרות, רווח, מקף), שם משתנה (--[A-Za-z_][\w-]*), נתיב קובץ
 * ([A-Za-z0-9/._-]), משקל וסגנון מרשימה; ערך — checkValue (style.js) ובלי { } ;. כל ערך מהעמוד מוצג כטקסט בלבד.
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc;
  const VAR_NAME = /^--[A-Za-z_][\w-]{0,60}$/;
  const FAMILY = /^[\p{L}\p{N} _-]{1,60}$/u;
  const FONT_PATH = /^(\.\.\/)*[A-Za-z0-9_-][A-Za-z0-9/._-]{0,200}$/;
  const RANGE = /^[Uu]\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?(\s*,\s*[Uu]\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?)*$/;
  const FORMATS = ['woff2', 'woff', 'truetype', 'opentype'];
  const GENERIC = new Set(['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'math', 'emoji', 'fangsong']);
  const ROOT_SEL = /^(:root|html)(\s*,\s*(:root|html))*$/i;
  const esc = s => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const fail = msg => { const e = new Error(msg); e.code = 'input'; throw e; };

  function checkVarName(n) { if (!VAR_NAME.test(String(n))) fail('שם משתנה: מתחיל ב-"--", אותיות לטיניות, ספרות, מקף וקו תחתון (למשל --color-title)'); return String(n); }
  function checkFamily(f) { f = String(f).replace(/\s+/g, ' ').trim(); if (!FAMILY.test(f)) fail('שם גופן: אותיות, ספרות, רווח ומקף בלבד (בלי מרכאות וסימנים)'); return f; }
  /** ערך שמותר לכתוב כערך של משתנה / הצהרה: בלי < { } ; ובלי javascript:. checkValue של style.js — בנוסף, בדפדפן. */
  function checkRaw(v) {
    v = String(v).trim();
    if (!v || /[<>{};]|\/\*|javascript:|expression\(/i.test(v) || v.length > 400) fail('ערך לא חוקי — בלי < > { } ; ובלי הערות');
    let depth = 0, q = '';
    for (const ch of v) { if (q) { if (ch === q) q = ''; continue; } if (ch === '"' || ch === "'") q = ch; else if (ch === '(') depth++; else if (ch === ')' && --depth < 0) fail('סוגריים לא מאוזנים'); }
    if (depth || q) fail('סוגריים או מרכאות לא סגורים');
    return v;
  }
  /** "Heebo", 'Frank Ruhl Libre', sans-serif ← ["Heebo", "Frank Ruhl Libre", "sans-serif"] */
  function families(v) { return String(v || '').split(',').map(x => x.trim().replace(/^(['"])(.*)\1$/, '$2').trim()).filter(Boolean); }
  /** הצהרת font-family עם גופן גיבוי: 'Heebo', sans-serif. */
  function familyValue(f, fallback) { checkFamily(f); const fb = GENERIC.has(fallback) ? fallback : 'sans-serif'; return (GENERIC.has(f.toLowerCase()) ? f : "'" + f + "'") + (f.toLowerCase() === fb ? '' : ', ' + fb); }

  /** סוג משתנה לפי הערך: color · font · size · other. isColor — בדפדפן (CSS.supports), אחרת תבנית. */
  function kindOf(v, isColor) {
    v = String(v || '').trim(); if (!v) return 'other';
    const col = isColor ? isColor(v) : /^(#[0-9a-f]{3,8}|(rgb|hsl|hwb|lab|lch|oklab|oklch|color)a?\(.*\)|transparent|currentcolor|[a-z]+)$/i.test(v) && !/^(auto|none|normal|inherit|initial|unset|bold|italic|serif|sans-serif|monospace|cursive|fantasy|system-ui)$/i.test(v);
    if (col) return 'color';
    if (/^-?(\d+\.?\d*|\.\d+)(px|r?em|%|vw|vh|vmin|vmax|ch|ex|pt|cm|mm|in|fr|svh|lvh|dvh|cqw|cqh)?$/i.test(v) || /^(calc|clamp|min|max)\(/i.test(v)) return 'size';
    const fam = families(v);
    if (fam.length > 1 || /^['"]/.test(v) || GENERIC.has(v.toLowerCase()) || fam.some(f => GENERIC.has(f.toLowerCase()))) return 'font';
    return 'other';
  }

  /**
   * כל המשתנים בכל הגיליונות. sheets — [{path, m}] (m = KolSrc.parseCss) בסדר הטעינה. מחזיר Map: שם ← { name, defs } ;
   * def = { path, rule, decl, sel, media, line, value, root } — root: :root / html ברמה העליונה (ההגדרה "הגלובלית").
   */
  function collectVars(sheets) {
    const out = new Map();
    for (const { path, m } of sheets) {
      for (const n of K().cssAll(m)) {
        if (n.type !== 'rule' || n.broken) continue;
        const media = n.parent && n.parent.type === 'at' ? '@' + n.parent.name + ' ' + n.parent.sel.replace(/\s+/g, ' ').trim() : null;
        for (const d of n.decls) {
          if (!d.prop.startsWith('--')) continue;
          if (!out.has(d.prop)) out.set(d.prop, { name: d.prop, defs: [] });
          out.get(d.prop).defs.push({ path, rule: n, decl: d, sel: n.sel.replace(/\s+/g, ' ').trim(), media, line: K().lineOf(m.src, d.from), value: d.value, root: !media && ROOT_SEL.test(n.sel.trim()) });
        }
      }
    }
    return out;
  }
  /** ההגדרה שקובעת לעמוד: האחרונה מבין :root / html ברמה העליונה (סדר הטעינה), אחרת הראשונה. */
  function mainDef(v) { const r = v.defs.filter(d => d.root); return r.length ? r[r.length - 1] : v.defs[0]; }

  /** "איפה בשימוש": כל var(--x) בטקסטים (CSS ו-HTML). texts — [{path, text}]. */
  function usages(texts, name) {
    const re = new RegExp('var\\(\\s*' + esc(name) + '(?![\\w-])', 'g'), out = [];
    for (const { path, text } of texts) for (const m of text.matchAll(re)) out.push({ path, line: text.slice(0, m.index).split('\n').length });
    return out;
  }

  /** ערך חדש למשתנה, בהצהרה עצמה. */
  function planSetVar(m, def, value) { checkRaw(value); return K().css.setDecl(m, def.rule, def.decl.prop, value).patches; }

  /** אזורי CSS בתוך HTML: style="" ו-<style>. */
  function cssRanges(html) {
    const out = [];
    for (const x of html.matchAll(/\sstyle\s*=\s*("([^"]*)"|'([^']*)')/gi)) out.push([x.index + x[0].indexOf(x[1]) + 1, x.index + x[0].length - 1]);
    for (const x of html.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)) { const a = x.index + x[0].indexOf('>') + 1; out.push([a, a + x[1].length]); }
    return out;
  }
  /** שינוי שם של משתנה: ההגדרות וכל var(--x) — ב-CSS, וב-HTML רק בתוך style="" ו-<style>. */
  function planRename(text, old, neu, isHtml) {
    checkVarName(old); checkVarName(neu);
    const re = new RegExp('(^|[^\\w-])' + esc(old) + '(?![\\w-])', 'g'), ranges = isHtml ? cssRanges(text) : [[0, text.length]], ps = [];
    for (const [a, b] of ranges) for (const m of text.slice(a, b).matchAll(re)) { const at = a + m.index + m[1].length; ps.push({ from: at, to: at + old.length, text: neu }); }
    return ps;
  }
  /** משתנה חדש: בכלל :root הראשון ברמה העליונה של הקובץ, או כלל :root חדש בראש הקובץ (אחרי @font-face / @import). */
  function planNewVar(m, name, value) {
    checkVarName(name); checkRaw(value);
    const root = m.root.kids.find(n => n.type === 'rule' && !n.broken && ROOT_SEL.test(n.sel.trim()));
    if (root) { if (root.decls.some(d => d.prop === name)) fail('כבר יש משתנה ' + name + ' בקובץ'); return K().css.addDecl(m, root, name, value).patches; }
    return K().css.addRule(m, m.root, ':root', [[name, value]], { index: lead(m) }).patches;
  }
  /** המקום אחרי @charset / @import / @font-face שבראש הקובץ. */
  function lead(m) { let i = 0; while (i < m.root.kids.length && m.root.kids[i].type === 'at' && /^(charset|import|font-face|layer)$/i.test(m.root.kids[i].name) && !m.root.kids[i].kids.length) i++; return i; }
  const normVal = v => String(v).trim().toLowerCase().replace(/\s+/g, ' ').replace(/\s*,\s*/g, ',');
  /**
   * "החלף גם ב-N מקומות": כל הצהרה (לא הגדרת משתנה) שערכה זהה ל-value ← var(name). same(a, b) — השוואה (בדפדפן: אותו צבע).
   * מחזיר { patches, hits: [{ sel, prop, line, media }] }. skip(rule, decl) — הצהרה שלא נוגעים בה.
   */
  function planReplaceValue(m, value, name, same, skip) {
    const eqv = same || ((a, b) => normVal(a) === normVal(b)), patches = [], hits = [];
    for (const n of K().cssAll(m)) {
      if (n.type !== 'rule' || n.broken) continue;
      for (const d of n.decls) {
        if (d.prop.startsWith('--') || (skip && skip(n, d)) || !eqv(d.value, value)) continue;
        patches.push({ from: d.valFrom, to: d.valTo, text: 'var(' + name + ')' });
        hits.push({ sel: n.sel.replace(/\s+/g, ' ').trim(), prop: d.prop, line: K().lineOf(m.src, d.from), media: n.parent && n.parent.type === 'at' ? n.parent.sel : null });
      }
    }
    return { patches, hits };
  }
  /** שם מוצע ל"צור משתנה מהערך": --<סוג>-<מחלקה/תגית>[-<מאפיין>]. */
  function suggestName(kind, ctx, prop, taken) {
    const base = '--' + ({ color: 'color', font: 'font', size: 'size' }[kind] || 'value') + '-' + (String(ctx || 'x').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30) || 'x') + (kind === 'color' && /background/.test(prop || '') ? '-bg' : kind === 'size' && prop ? '-' + prop.replace(/[^a-z]+/g, '-').replace(/-$/, '') : '');
    let n = base; for (let i = 2; taken && taken.has(n); i++) n = base + '-' + i;
    return n;
  }

  // ── גופנים ────────────────────────────────────────────────
  /** @font-face ← [[מאפיין, ערך]] — כל שדה מתבנית מוגבלת. url — נתיב יחסי ל-CSS. */
  function fontFaceDecls(f) {
    const fam = checkFamily(f.family);
    if (!FONT_PATH.test(f.url) || /(^|\/)\.\.?(\/|$)/.test(f.url.replace(/^(\.\.\/)+/, ''))) fail('נתיב קובץ הגופן לא תקין');
    const w = String(f.weight || '400').trim(); if (!/^\d{1,4}( \d{1,4})?$/.test(w)) fail('משקל: מספר (או טווח "100 900")');
    const st = f.style === 'italic' ? 'italic' : 'normal', fmt = FORMATS.includes(f.format) ? f.format : fail('פורמט גופן לא מוכר');
    const out = [['font-family', "'" + fam + "'"], ['src', "url('" + f.url + "') format('" + fmt + "')"], ['font-weight', w], ['font-style', st], ['font-display', 'swap']];
    if (f.range) { if (!RANGE.test(f.range)) fail('unicode-range לא תקין'); out.push(['unicode-range', f.range]); }
    return out;
  }
  /** @font-face חדש — אחרי ה-@font-face האחרון שבראש הקובץ (או בראש הקובץ). */
  function planFontFace(m, f) { return K().css.addRule(m, m.root, '@font-face', fontFaceDecls(f), { index: lead(m) }).patches; }
  /** כל ה-@font-face בגיליון: [{ node, family, weight, style, url, range }]. */
  function fontFaces(m) {
    const out = [];
    for (const n of m.root.kids) {
      if (n.type !== 'at' || n.name.toLowerCase() !== 'font-face' || n.broken) continue;
      const get = p => (n.decls.filter(d => d.prop.toLowerCase() === p).pop() || {}).value || '';
      const u = /url\(\s*(['"]?)([^'")]+)\1\s*\)/i.exec(get('src'));
      out.push({ node: n, family: families(get('font-family'))[0] || '', weight: get('font-weight') || '400', style: get('font-style') || 'normal', url: u ? u[2] : '', range: get('unicode-range'), line: K().lineOf(m.src, n.from) });
    }
    return out;
  }
  /** <link> של Google Fonts בעמוד: [{ from, to, href, fams: [{ name, weights, range, italic, raw }] }]. */
  function googleLinks(html) {
    const out = [];
    for (const x of html.matchAll(/<link\b[^>]*>/gi)) {
      const hr = /\bhref\s*=\s*(?:"([^"]*)"|'([^']*)')/i.exec(x[0]); const href = hr ? (hr[1] ?? hr[2]) : '';
      if (!/^https:\/\/fonts\.googleapis\.com\/css2?\?/i.test(href)) continue;
      const q = href.slice(href.indexOf('?') + 1).replace(/&amp;/g, '&'), fams = [];
      for (const part of q.split('&').filter(p => /^family=/.test(p))) {
        let raw = part.slice(7), dec; try { dec = decodeURIComponent(raw.replace(/\+/g, ' ')); } catch { dec = raw; }
        const [name, axes] = dec.split(':'), wm = axes && /wght@([\d;.,]+)/.exec(axes), list = wm ? [...new Set(wm[1].split(';').map(x => x.split(',').pop()))] : null;
        const range = list && list.length === 1 && list[0].includes('..') ? list[0].split('..').map(Number) : null;
        fams.push({ name: name.trim(), raw: part, italic: /ital/.test(axes || ''), range, weights: range ? null : list ? [...new Set(list.map(x => +x))].sort((a, b) => a - b) : [400] });
      }
      out.push({ from: x.index, to: x.index + x[0].length, href, tag: x[0], fams });
    }
    return out;
  }
  /** <link> חדש ב-<head>: אחרי ה-<link> האחרון של Google (או לפני ה-stylesheet הראשון, או לפני </head>). כבר קיים — []. */
  function planLinkInsert(html, href) {
    if (!/^https:\/\/fonts\.googleapis\.com\/css2\?family=[A-Za-z0-9+:;@,.&=_-]+$/.test(href)) fail('כתובת Google Fonts לא תקינה');
    if (googleLinks(html).some(l => l.href.replace(/&amp;/g, '&') === href)) return [];
    const head = /<\/head\s*>/i.exec(html); if (!head) fail('אין <head> בעמוד');
    const gl = googleLinks(html).filter(l => l.from < head.index).pop(), first = /<link\b[^>]*\brel\s*=\s*["']?stylesheet[^>]*>/i.exec(html.slice(0, head.index));
    const tag = '<link rel="stylesheet" href="' + href.replace(/&/g, '&amp;') + '">';
    const at = gl ? gl.to : first ? first.index : head.index, lineStart = html.lastIndexOf('\n', at - 1) + 1, indent = /^[ \t]*/.exec(html.slice(lineStart))[0];
    return gl ? [{ from: at, to: at, text: '\n' + indent + tag }] : [{ from: at, to: at, text: tag + '\n' + (first ? indent : '  ') }];
  }
  /** הסרת משפחה מ-<link> של Google: משפחה יחידה — כל השורה; כמה משפחות — רק family=… מתוך ה-href. */
  function planLinkDrop(html, family) {
    const ps = [];
    for (const l of googleLinks(html)) {
      const hit = l.fams.find(f => f.name.toLowerCase() === family.toLowerCase()); if (!hit) continue;
      if (l.fams.length === 1) { const s = html.lastIndexOf('\n', l.from - 1) + 1, before = html.slice(s, l.from), e = html[l.to] === '\n' ? l.to + 1 : l.to; ps.push(/^\s*$/.test(before) ? { from: s, to: e, text: '' } : { from: l.from, to: l.to, text: '' }); continue; }
      const i = l.tag.indexOf(hit.raw), amp = l.tag.slice(0, i).match(/(&amp;|&)$/), cut = amp ? [i - amp[0].length, i + hit.raw.length] : [i, i + hit.raw.length + (l.tag.slice(i + hit.raw.length).match(/^(&amp;|&)/) || [''])[0].length];
      ps.push({ from: l.from + cut[0], to: l.from + cut[1], text: '' });
    }
    return ps;
  }
  /** הצהרות font-family (וערכי משתנים) שהמשפחה היא הראשונה בהן. */
  function familyUses(m, family) {
    const out = [], low = family.toLowerCase();
    for (const n of K().cssAll(m)) {
      if (n.type !== 'rule' || n.broken) continue;
      for (const d of n.decls) {
        const p = d.prop.toLowerCase();
        if (p === 'font-family' || p === 'font' || (p.startsWith('--') && kindOf(d.value) === 'font')) { if (families(p === 'font' ? ((/[\d.]+[a-z%]+(?:\s*\/\s*[\d.]+[a-z%]*)?\s+(.+)$/i.exec(d.value) || [])[1] || '') : d.value).some(f => f.toLowerCase() === low)) out.push({ rule: n, decl: d, sel: n.sel.replace(/\s+/g, ' ').trim(), line: K().lineOf(m.src, d.from) }); }
      }
    }
    return out;
  }
  /** "החלף בגופן אחר בכל מקום": המשפחה הישנה ← החדשה, בכל font-family / font / משתנה גופן. */
  function planReplaceFamily(m, old, neu) {
    const nv = families(neu).length ? neu : fail('חסר גופן חלופי'), low = old.toLowerCase(), ps = [];
    for (const u of familyUses(m, old)) {
      const d = u.decl, v = d.value, parts = v.split(',');
      const out = parts.map(x => (families(x)[0] || '').toLowerCase() === low ? (/^\s/.test(x) ? ' ' : '') + nv : x).join(',');
      if (out !== v && d.prop.toLowerCase() !== 'font') ps.push({ from: d.valFrom, to: d.valTo, text: out });
    }
    return ps;
  }

  g.KolGlobals = { VAR_NAME, FAMILY, checkVarName, checkFamily, checkRaw, families, familyValue, kindOf, collectVars, mainDef, usages, planSetVar, planRename, planNewVar, planReplaceValue,
    suggestName, fontFaceDecls, planFontFace, fontFaces, googleLinks, planLinkInsert, planLinkDrop, familyUses, planReplaceFamily, cssRanges, lead };
})(typeof window !== 'undefined' ? window : globalThis);
