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

  // ══ בדפדפן: הפאנל "🌐 כללי" ═══════════════════════════════
  function makeUi() {
    const G = () => g.KolGlobals;
    const TAGS = [['body', 'גוף הטקסט'], ['h1', 'כותרת 1'], ['h2', 'כותרת 2'], ['h3', 'כותרת 3'], ['h4', 'כותרת 4'], ['h5', 'כותרת 5'], ['h6', 'כותרת 6'], ['p', 'פסקה'], ['a', 'קישור'], ['button', 'כפתור'], ['img', 'תמונה'], ['input', 'שדה טופס'], ['ul', 'רשימה'], ['li', 'פריט ברשימה']];
    const LAYOUT = [['--content-max', 'רוחב תוכן מרבי', '1100px'], ['--gap', 'רווח ברירת מחדל', '16px']];
    const SAMPLE = 'שלום עולם · אבגדהו · Hello 123';
    const COLOR_P = /(^|-)color$|^(fill|stroke|caret-color|accent-color)$/;
    const isColor = v => { try { return CSS.supports('color', v) && !/^(inherit|initial|unset|revert|currentcolor)$/i.test(String(v).trim()); } catch { return false; } };
    const kindOfProp = phys => (phys === 'font-family' ? 'font' : COLOR_P.test(phys) ? 'color' : /^(font-size|line-height|letter-spacing|word-spacing|width|height|min-|max-|margin|padding|gap|row-gap|column-gap|top|right|bottom|left|inset|border-.*width|border-.*radius|outline-width|text-indent|flex-basis)/.test(phys) ? 'size' : null);
    const KB = n => Math.max(1, Math.round(n / 1024));
    const loaded = new Set();
    let gList = null;
    /** נתיב יחסי מקובץ ← נתיב בפרויקט (ובחזרה). */
    const relFrom = (from, to) => { const a = from.split('/').slice(0, -1), b = to.split('/'); let i = 0; while (i < a.length && i < b.length - 1 && a[i] === b[i]) i++; return '../'.repeat(a.length - i) + b.slice(i).join('/'); };
    const resolve = (from, ref) => { if (/^([a-z][a-z0-9+.-]*:|\/\/)/i.test(ref)) return null; const out = from.split('/').slice(0, -1); for (const p of ref.split(/[?#]/)[0].split('/')) { if (p === '..') out.pop(); else if (p && p !== '.') out.push(p); } try { return decodeURIComponent(out.join('/')); } catch { return out.join('/'); } };
    const toHex = v => { const c = window.KolDesign && window.KolDesign.rgba(v); if (!c) return '#000000'; return '#' + c.slice(0, 3).map(x => Math.round(x).toString(16).padStart(2, '0')).join(''); };
    const b64 = file => new Promise((ok, bad) => { const r = new FileReader(); r.onload = () => ok(String(r.result).replace(/^data:[^,]*,/, '')); r.onerror = () => bad(new Error('הקובץ לא נקרא')); r.readAsDataURL(file); });

    class Globals {
      /** o: { canvas, styler, writeOps, joinTx, attachFx(fx), note, html(), filePath(), sheets(): [{path, doc}], docOf(path), pages(): Promise<[{path, doc}]>,
       *       files(), reload(), kol(), design(), setTab(id), basePath(), linkSheet(path), pvUrl(path), canWrite(), highlight(els), openAt(path, line), jsTexts() } */
      constructor(host, o) { this.host = host; this.o = o; this.sub = 'fonts'; this.q = ''; }
      ro() { return !this.o.canWrite(); }
      /** הגיליונות המנותחים — מטמון לפי הטקסט (הפאנל קורא לזה בכל שדה). */
      sheets() { const c = this.cache || (this.cache = new Map()); return this.o.sheets().map(x => { const text = x.doc.text(), hit = c.get(x.path); if (hit && hit.text === text) return hit; const o = { path: x.path, doc: x.doc, text, m: K().parseCss(text) }; c.set(x.path, o); return o; }); }
      memo(key, fn) { const sig = this.sheets().map(s => s.text).concat(this.o.html()?.text() || '', String((this.o.files() || []).length)); const m = (this.memos || (this.memos = {}))[key]; if (m && m.sig.length === sig.length && m.sig.every((x, i) => x === sig[i])) return m.v; const v = fn(); this.memos[key] = { sig, v }; return v; }
      texts() { return this.sheets().map(s => ({ path: s.path, text: s.text })).concat(this.o.html() ? [{ path: this.o.filePath(), text: this.o.html().text() }] : []); }
      tokensPath() { const want = this.o.kol()?.styles?.tokens, list = this.o.sheets().map(x => x.path); return list.includes(want) ? want : list.find(p => /tokens\.css$/i.test(p)) || list[list.length - 1] || null; }
      tokensDoc() { const p = this.tokensPath(); if (!p) { this.o.note('לעמוד אין קובץ CSS מקושר — מקשרים את css/tokens.css (📁 פרויקט / עורך הקוד)', true); return null; } return this.o.docOf(p); }

      // ── הציור ────────────────────────────────────────────
      draw() {
        if (this.host.hidden) return;
        const tabs = h('div', { class: 'gl-tabs', role: 'tablist', 'aria-label': 'סגנון כללי' }, [['fonts', '🔤 גופנים'], ['vars', '🎛 משתנים'], ['tags', '🏷 תגיות']].map(([id, l]) => h('button', { type: 'button', role: 'tab', 'data-gl-tab': id, 'aria-selected': String(this.sub === id), onclick: () => { this.sub = id; this.draw(); } }, l)));
        let body;
        try { body = this.sub === 'vars' ? this.drawVars() : this.sub === 'tags' ? this.drawTags() : this.drawFonts(); } catch (e) { console.error(e); body = h('p', { class: 'error' }, e.message); }
        const sc = this.host.scrollTop;
        this.host.replaceChildren(h('p', { class: 'gl-intro hint' }, 'סגנון כללי — חל על כל העמוד: הגופנים, המשתנים (צבעים, גופנים, מידות) וברירות המחדל לכל תגית.'), tabs, body);
        this.host.scrollTop = sc;
      }

      // ── גופנים ───────────────────────────────────────────
      /** כל הגופנים: קובץ (@font-face) · Google (<link>) · בשימוש בלבד (מערכת / לא נטען). */
      fonts() { return this.memo('fonts', () => this.fontsNow()); }
      vars() { return this.memo('vars', () => G().collectVars(this.sheets())); }
      fontsNow() {
        const out = new Map(), files = this.o.files(), sheets = this.sheets();
        const add = (name, src) => { const k = name.toLowerCase(); if (!out.has(k)) out.set(k, { name, src, weights: new Set(), range: null, faces: [], links: [], bytes: 0, files: [], gweights: new Set(), grange: null, italic: false }); const f = out.get(k); if (src === 'file' || (src === 'google' && f.src === 'page')) f.src = src; return f; };
        for (const s of sheets) for (const ff of G().fontFaces(s.m)) {
          if (!ff.family) continue;
          const f = add(ff.family, 'file'); f.faces.push({ path: s.path, family: ff.family, weight: ff.weight, style: ff.style, url: ff.url });
          const w = ff.weight.trim().split(/\s+/); if (w.length === 2) f.range = [+w[0], +w[1]]; else f.weights.add(+w[0] || (/bold/i.test(w[0]) ? 700 : 400));
          if (/italic/i.test(ff.style)) f.italic = true;
          const fp = ff.url && resolve(s.path, ff.url), file = fp && files.find(x => x.path === fp && x.kind !== 'dir');
          if (file && !f.files.includes(file)) { f.bytes += file.size || 0; f.files.push(file); }
        }
        const html = this.o.html()?.text() || '';
        for (const l of G().googleLinks(html)) for (const gf of l.fams) { const f = add(gf.name, 'google'); f.links.push(l.href); if (gf.range) f.grange = gf.range; for (const w of gf.weights || []) f.gweights.add(w); if (gf.italic) f.gitalic = true; f.google = true; }
        for (const s of sheets) for (const n of K().cssAll(s.m)) if (n.type === 'rule' && !n.broken) for (const d of n.decls) {
          const p = d.prop.toLowerCase(); if (p !== 'font-family' && !(p.startsWith('--') && G().kindOf(d.value) === 'font')) continue;
          const f0 = G().families(d.value)[0]; if (f0 && !/var\(/i.test(f0) && !G().GENERIC.has(f0.toLowerCase()) && G().FAMILY.test(f0)) add(f0, 'page');
        }
        for (const f of out.values()) { f.uses = []; for (const s of sheets) for (const u of G().familyUses(s.m, f.name)) f.uses.push({ path: s.path, sel: u.sel, line: u.line, prop: u.decl.prop }); }
        return out;
      }
      /** בורר המשקל (design.js): המשקלים שהגופן מספק — { weights, range } או null (לא ידוע). */
      weightsOf(name) {
        const f = this.fonts().get(String(name).toLowerCase()); if (!f) return null;
        if (f.src === 'file') return f.range ? { weights: null, range: f.range } : { weights: [...f.weights].sort((a, b) => a - b).map(String), range: null };
        if (f.src === 'google') return f.grange ? { weights: null, range: f.grange } : { weights: [...f.gweights].sort((a, b) => a - b).map(String), range: null };
        return null;
      }
      /** הערך שבחירת גופן כותבת: var(--x) כשיש משתנה שהגופן הראשון שלו הוא המשפחה; אחרת 'Family', <גיבוי>. */
      familyFor(name, fallback) {
        const low = String(name).toLowerCase();
        for (const v of this.vars().values()) { const d = G().mainDef(v); if (G().kindOf(d.value) === 'font' && (G().families(d.value)[0] || '').toLowerCase() === low) return 'var(' + v.name + ')'; }
        const gf = (gList || []).find(f => f.family.toLowerCase() === low);
        return G().familyValue(name, fallback || (gf && /serif/.test(gf.category) && !/sans/.test(gf.category) ? 'serif' : gf && gf.category === 'monospace' ? 'monospace' : 'sans-serif'));
      }
      /** טוען את הגופן גם במסמך של העורך — לתצוגה המקדימה בשמו (הקנבס טוען לבד). */
      preview(el, f) {
        el.style.fontFamily = "'" + f.name + "', system-ui, sans-serif";
        const key = f.src + ':' + f.name; if (loaded.has(key)) return; loaded.add(key);
        try {
          if (f.src === 'file') for (const ff of f.faces) { const p = resolve(ff.path, ff.url); if (!p) continue; const face = new FontFace(f.name, 'url("' + this.o.pvUrl(p) + '")', { weight: ff.weight, style: ff.style }); document.fonts.add(face); face.load().catch(() => {}); }
          else if ((f.src === 'google' || f.gfont) && /^[A-Za-z0-9 ]+$/.test(f.name)) document.head.append(h('link', { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=' + f.name.replace(/ /g, '+') + '&text=' + encodeURIComponent(f.name + SAMPLE), 'data-kol-fontpv': f.name }));
        } catch { /* תצוגה מקדימה בלבד */ }
      }
      /** האלמנטים בקנבס שהגופן הראשון שלהם הוא המשפחה (לסימון בריחוף). */
      elsOf(family) {
        const c = this.o.canvas, win = c.frame?.contentWindow, low = family.toLowerCase(), out = [];
        if (!c.doc || !win) return out;
        for (const e of [...c.doc.body.querySelectorAll('*')].slice(0, 3000)) { if (e.closest('[data-kol-canvas]')) continue; const f0 = G().families(win.getComputedStyle(e).fontFamily)[0]; if (f0 && f0.toLowerCase() === low && /\S/.test(e.textContent || '') && ![...e.children].some(k => G().families(win.getComputedStyle(k).fontFamily)[0]?.toLowerCase() === low)) out.push(e); }
        return out;
      }
      drawFonts() {
        const order = { file: 0, google: 1, page: 2 }, fonts = [...this.fonts().values()].sort((a, b) => order[a.src] - order[b.src] || a.name.localeCompare(b.name));
        const box = h('section', { class: 'gl-sec', 'data-gl': 'fonts' }, h('div', { class: 'gl-bar' }, h('button', { type: 'button', class: 'btn primary', 'data-font-add': true, disabled: this.ro() || null, onclick: () => this.addFont() }, '＋ הוסף גופן')));
        for (const f of fonts) box.append(this.fontRow(f));
        if (!fonts.length) box.append(h('p', { class: 'hint' }, 'אין עדיין גופנים בעמוד.'));
        return box;
      }
      fontRow(f) {
        const src = { file: '📁 קובץ בפרויקט', google: '🌐 Google Fonts', page: '💻 מערכת / לא נטען' }[f.src];
        const ws = f.src === 'file' ? (f.range ? f.range.join('–') + ' (משתנה)' : [...f.weights].sort((a, b) => a - b).join(' · ')) : f.src === 'google' ? (f.grange ? f.grange.join('–') + ' (משתנה)' : [...f.gweights].join(' · ')) : '—';
        const name = h('b', { class: 'gl-fname' }, f.name); this.preview(name, f);
        const row = h('div', { class: 'gl-font', 'data-font': f.name, 'data-src': f.src },
          h('div', { class: 'gl-fline' }, name, h('span', { class: 'gl-badge' }, src + (f.src === 'file' && f.google ? ' + Google' : ''))),
          h('div', { class: 'gl-meta' }, 'משקלים: ', h('span', { dir: 'ltr' }, ws || '—'), f.bytes ? ' · ' + KB(f.bytes) + ' ק"ב' : '', ' · ', h('span', { 'data-font-uses': f.uses.length }, f.uses.length ? 'בשימוש ב-' + f.uses.length + (f.uses.length === 1 ? ' כלל' : ' כללים') : 'לא בשימוש')));
        const hi = on => this.o.highlight(on ? this.elsOf(f.name) : null);
        row.addEventListener('mouseenter', () => hi(true)); row.addEventListener('mouseleave', () => hi(false));
        const acts = h('div', { class: 'gl-acts' });
        if (f.src === 'google' || f.google) acts.append(h('button', { type: 'button', class: 'btn sm', 'data-font-fetch': f.name, disabled: this.ro() || null, title: 'השרת מוריד את קובצי ה-woff2 ושומר אותם ב-media/fonts/ — אחרי זה הגופן אינו בקשה חיצונית', onclick: () => this.fetchFont(f) }, '⬇ הבא לפרויקט'));
        if (f.src !== 'page') acts.append(h('button', { type: 'button', class: 'btn sm', 'data-font-more': f.name, disabled: this.ro() || null, onclick: () => this.addFont({ family: f.name, google: f.src === 'google' }) }, '＋ משקל'));
        acts.append(h('button', { type: 'button', class: 'btn sm', 'data-font-uses-btn': f.name, disabled: !f.uses.length || null, onclick: () => { hi(true); const l = row.querySelector('.gl-uses'); if (l) l.remove(); else row.append(this.useList(f.uses)); } }, '📍 איפה'));
        if (f.src !== 'page') acts.append(h('button', { type: 'button', class: 'btn sm', 'data-font-del': f.name, disabled: this.ro() || null, onclick: () => this.delFont(f, row) }, '🗑'));
        row.append(acts);
        return row;
      }
      useList(uses) { return h('ul', { class: 'gl-uses' }, uses.slice(0, 40).map(u => h('li', {}, h('button', { type: 'button', class: 'sg-at', dir: 'ltr', onclick: () => this.o.openAt(u.path, u.line) }, u.sel + ' { ' + u.prop + ' } · ' + u.path + ':' + u.line)))); }

      /** "＋ הוסף גופן": קובץ / Google Fonts. מחזיר את שם המשפחה שנוספה, או null. */
      addFont(opt = {}) {
        if (this.ro()) return Promise.resolve(null);
        return new Promise(done => {
          const dlg = document.getElementById('sheet'); let settled = false, uploaded = [];
          const finish = v => { if (settled) return; settled = true; dlg.removeEventListener('close', onClose); if (!v && uploaded.length) this.trash(uploaded.map(x => x.file.id)); if (dlg.open) dlg.close(); done(v); };
          const onClose = () => finish(null);
          const pane = h('div', { class: 'gl-add' }), msg = h('p', { class: 'hint', role: 'status', 'data-font-msg': true });
          const tab = id => { for (const b of tabs.children) b.setAttribute('aria-selected', String(b.dataset.addTab === id)); pane.replaceChildren(id === 'google' ? this.googlePane(opt, finish, msg) : this.uploadPane(opt, finish, msg, list => { uploaded = list; })); };
          const tabs = h('div', { class: 'gl-tabs', role: 'tablist' }, h('button', { type: 'button', role: 'tab', 'data-add-tab': 'file', onclick: () => tab('file') }, '⬆️ קובץ מהמחשב'), h('button', { type: 'button', role: 'tab', 'data-add-tab': 'google', onclick: () => tab('google') }, '🌐 Google Fonts'));
          sheet(opt.family ? '＋ משקל — ' + opt.family : '＋ הוסף גופן', tabs, msg, pane);
          dlg.addEventListener('close', onClose);
          tab(opt.google ? 'google' : 'file');
        });
      }
      uploadPane(opt, finish, msg, keep) {
        const items = [], rows = h('div', { class: 'gl-up-rows' });
        const inp = h('input', { type: 'file', multiple: true, accept: '.woff2,.woff,.ttf,.otf,font/woff2,font/woff,font/ttf,font/otf', 'data-font-file': true, hidden: true });
        const ok = h('button', { type: 'button', class: 'btn primary', 'data-font-ok': true, disabled: true, onclick: async () => { ok.disabled = true; const fam = await this.commitUpload(items); if (fam) finish(fam); else ok.disabled = false; } }, '✓ הוסף לפרויקט');
        const take = async list => {
          for (const file of list) {
            msg.textContent = 'מעלה ' + file.name + '…';
            try {
              const r = await api('font_upload', { name: file.name, content_b64: await b64(file) });
              if (r.info.hebrew == null) r.info.hebrew = await this.hebrewIn(file);
              const it = { file: r.file, format: r.format, family: opt.family || r.info.family, weight: r.info.variable ? r.info.variable.join(' ') : String(r.info.weight || 400), style: r.info.style, notes: r.notes.slice(), hebrew: r.info.hebrew, name: file.name, read: r.info.read };
              if (r.info.hebrew === false && !it.notes.some(x => /עבר/.test(x))) it.notes.push('בקובץ אין אותיות עבריות — הטקסט העברי יוצג בגופן הגיבוי.');
              items.push(it); rows.append(this.upRow(it));
            } catch (e) { rows.append(h('p', { class: 'sg-over', 'data-font-err': true }, '⚠️ ' + file.name + ': ' + e.message)); }
          }
          keep(items); msg.textContent = items.length ? 'בדקו את השם, המשקל והסגנון — ואשרו.' : ''; ok.disabled = !items.length;
        };
        inp.addEventListener('change', () => take([...inp.files]));
        const drop = h('div', { class: 'gl-drop', tabindex: '0', role: 'button', 'aria-label': 'בחירת קובצי גופן', onclick: () => inp.click(), onkeydown: e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } } }, '⬆️ בחירת קבצים — או גרירה לכאן', h('br'), h('small', {}, 'woff2 · woff · ttf · otf — אפשר כמה יחד (משקלים ונטויים). הקובץ נשמר כמו שהוא ב-media/fonts/.'));
        drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('on'); }); drop.addEventListener('dragleave', () => drop.classList.remove('on'));
        drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('on'); take([...e.dataTransfer.files]); });
        return h('div', {}, drop, inp, rows, h('div', { class: 'gl-bar' }, ok));
      }
      upRow(it) {
        const fam = h('input', { type: 'text', value: it.family, 'aria-label': 'שם המשפחה', 'data-up-family': true, spellcheck: 'false' });
        const w = h('input', { type: 'text', dir: 'ltr', inputmode: 'numeric', value: it.weight, 'aria-label': 'משקל', 'data-up-weight': true, class: 'gl-w' });
        const st = h('select', { 'aria-label': 'סגנון', 'data-up-style': true }, h('option', { value: 'normal', selected: it.style !== 'italic' || null }, 'רגיל'), h('option', { value: 'italic', selected: it.style === 'italic' || null }, 'נטוי'));
        fam.addEventListener('input', () => { it.family = fam.value; fam.classList.toggle('bad', !G().FAMILY.test(fam.value.trim())); });
        w.addEventListener('input', () => { it.weight = w.value.trim(); w.classList.toggle('bad', !/^\d{1,4}( \d{1,4})?$/.test(it.weight)); });
        st.addEventListener('change', () => { it.style = st.value; });
        const pv = h('p', { class: 'gl-pv' }, SAMPLE);
        try { const face = new FontFace('kol-up-' + it.file.id, 'url("' + this.o.pvUrl(it.file.path) + '")'); document.fonts.add(face); face.load().then(() => { pv.style.fontFamily = "'kol-up-" + it.file.id + "', serif"; }).catch(() => {}); } catch { /* בלי תצוגה */ }
        return h('div', { class: 'gl-up', 'data-up': it.file.path }, h('div', { class: 'gl-meta', dir: 'ltr' }, it.name + ' → ' + it.file.path + ' · ' + KB(it.file.size) + ' KB' + (it.read ? '' : ' · (woff2 — השם והמשקל נוחשו משם הקובץ)')),
          h('div', { class: 'gl-up-f' }, h('label', {}, 'משפחה ', fam), h('label', {}, 'משקל ', w), h('label', {}, 'סגנון ', st)), pv,
          it.hebrew === true ? h('p', { class: 'hint', 'data-up-heb': 'yes' }, '✓ יש בקובץ אותיות עבריות') : null,
          it.notes.map(x => h('p', { class: 'sg-over', 'data-up-note': true }, '⚠️ ' + x)));
      }
      /** האם יש בגופן אותיות עבריות: אותו טקסט עם שני גופני גיבוי שונים — אם הרוחב זהה, הגופן עצמו צייר אותו. */
      async hebrewIn(file) {
        try {
          const name = 'kol-heb-' + Math.random().toString(36).slice(2), face = new FontFace(name, await file.arrayBuffer()); await face.load(); document.fonts.add(face);
          const c = document.createElement('canvas').getContext('2d'), m = fb => { c.font = '40px "' + name + '", ' + fb; return c.measureText('אבגדהוזחטשלום').width; };
          const r = Math.abs(m('monospace') - m('serif')) < 0.5; document.fonts.delete(face); return r;
        } catch { return null; }
      }
      async commitUpload(items) {
        const doc = this.tokensDoc(); if (!doc) return null;
        for (const it of items) { try { G().checkFamily(it.family); if (!/^\d{1,4}( \d{1,4})?$/.test(it.weight)) throw new Error('משקל: מספר (או טווח "100 900")'); } catch (e) { this.o.note(e.message, true); return null; } }
        const tp = this.tokensPath(), fams = [...new Set(items.map(i => G().checkFamily(i.family)))];
        const ops = items.map(it => () => [{ doc, patches: G().planFontFace(K().parseCss(doc.text()), { family: it.family, weight: it.weight, style: it.style, url: relFrom(tp, it.file.path), format: it.format }) }]);
        const docs = this.o.writeOps(ops, 'הוספת גופן · ' + fams.join(', ') + ' · ' + items.length + (items.length === 1 ? ' קובץ' : ' קבצים'));
        if (!docs || !docs.length) return null;
        this.fx(items.map(i => i.file.id), 'restore');
        await this.o.reload(); this.draw();
        this.o.note('נוסף: ' + fams.join(', ') + ' — זמין בכל בורר גופן', false);
        return fams[0];
      }
      /** קבצים שנוצרו בשרת יחד עם עסקה: ביטול ← לסל, החזרה ← משחזר. */
      fx(ids, kind) { this.o.attachFx({ undo: () => this.trash(ids), redo: () => api('pf_restore', { ids }).then(() => this.o.reload()).catch(e => this.o.note('השחזור של קובצי הגופן נכשל: ' + e.message, true)) }); }
      trash(ids) { return Promise.all(ids.map(id => api('pf_delete', { id }).catch(() => null))).then(() => this.o.reload()).catch(() => {}); }

      googlePane(opt, finish, msg) {
        const box = h('div', { class: 'gl-gf' }), list = h('div', { class: 'gl-gf-list', role: 'listbox', 'aria-label': 'Google Fonts' });
        const q = h('input', { type: 'search', placeholder: 'חיפוש גופן (Heebo, Rubik…) — או כל שם מ-Google Fonts', 'aria-label': 'חיפוש ב-Google Fonts', 'data-gf-q': true, dir: 'ltr', value: opt.family || '' });
        const heb = h('input', { type: 'checkbox', checked: true, 'data-gf-heb': true });
        const pick = h('div', { class: 'gl-gf-pick' });
        const show = () => {
          const s = q.value.trim().toLowerCase(), all = gList || [], hits = all.filter(f => (!heb.checked || f.hebrew) && f.family.toLowerCase().includes(s));
          list.replaceChildren(...hits.map(f => { const b = h('button', { type: 'button', role: 'option', class: 'gl-gf-item', 'data-gf': f.family, onclick: () => this.gfPick(pick, f, opt, finish, msg) }, h('span', { class: 'gl-gf-name' }, f.family), h('small', {}, f.hebrew ? 'עברית ✓' : 'בלי עברית')); this.preview(b.firstChild, { name: f.family, src: 'gpv', gfont: true }); return b; }));
          if (s && !all.some(f => f.family.toLowerCase() === s) && /^[a-z0-9 ]{2,60}$/i.test(q.value.trim())) list.append(h('button', { type: 'button', class: 'gl-gf-item', 'data-gf-any': true, onclick: () => this.gfPick(pick, { family: q.value.trim().replace(/\s+/g, ' '), weights: [400, 700], hebrew: null }, opt, finish, msg) }, '🔎 "' + q.value.trim() + '" — לבדוק ב-Google Fonts'));
          if (!list.childNodes.length) list.append(h('p', { class: 'hint' }, 'לא נמצא. אפשר לבטל את "רק עם עברית".'));
        };
        q.addEventListener('input', show); heb.addEventListener('change', show);
        box.append(h('div', { class: 'gl-bar' }, q, h('label', { class: 'gl-chk' }, heb, ' רק עם עברית')), list, pick,
          h('p', { class: 'hint' }, '🌐 גופן מ-Google נטען אצל הגולש מהשרת של Google, ומופיע ברשימת המשאבים החיצוניים בפרסום. "⬇ הבא לפרויקט" מעתיק אותו לפרויקט.'));
        (gList ? Promise.resolve() : api('font_google_list').then(r => { gList = r.fonts; })).then(() => { show(); if (opt.family) { const f = gList.find(x => x.family.toLowerCase() === opt.family.toLowerCase()); if (f) this.gfPick(pick, f, opt, finish, msg); } }).catch(e => { msg.textContent = '⚠️ ' + e.message; });
        return box;
      }
      gfPick(host, f, opt, finish, msg) {
        const cand = f.range ? [100, 200, 300, 400, 500, 600, 700, 800, 900].filter(w => w >= f.range[0] && w <= f.range[1]) : (f.weights || [400]);
        const have = this.weightsOf(f.family)?.weights || [];
        const boxes = cand.map(w => h('label', { class: 'gl-chk' }, h('input', { type: 'checkbox', value: String(w), checked: (have.length ? have.includes(String(w)) : [400, 700].includes(w) || cand.length === 1) || null, 'data-gf-w': w }), ' ' + w));
        const ital = f.italic ? h('label', { class: 'gl-chk' }, h('input', { type: 'checkbox', 'data-gf-ital': true }), ' + נטוי') : null;
        const pv = h('p', { class: 'gl-pv' }, SAMPLE); this.preview(pv, { name: f.family, src: 'gpv', gfont: true });
        const add = h('button', { type: 'button', class: 'btn primary', 'data-gf-add': true, onclick: async () => {
          const ws = boxes.map(b => b.firstChild).filter(x => x.checked).map(x => +x.value); if (!ws.length) { msg.textContent = 'בוחרים משקל אחד לפחות'; return; }
          add.disabled = true; msg.textContent = 'בודק ב-Google Fonts…';
          try { const r = await api('font_google_add', { family: f.family, weights: ws, italic: !!(ital && ital.firstChild.checked) }); const ok = await this.commitGoogle(r); if (ok) finish(r.family); else add.disabled = false; }
          catch (e) { msg.textContent = '⚠️ ' + e.message; add.disabled = false; }
        } }, '✓ הוסף לעמוד');
        host.replaceChildren(h('h4', { class: 'sg-h4' }, f.family + (f.range ? ' · גופן משתנה ' + f.range.join('–') : '')), pv, h('div', { class: 'gl-ws', role: 'group', 'aria-label': 'משקלים' }, boxes, ital), h('div', { class: 'gl-bar' }, add));
        host.scrollIntoView?.({ block: 'nearest' });
      }
      /** <link> ב-<head> של כל העמודים (עמוד שכבר טוען את המשפחה — ה-href הישן מוחלף). עסקה אחת. */
      async commitGoogle(r) {
        const pages = await this.o.pages(), ops = [];
        for (const p of pages) { ops.push(() => [{ doc: p.doc, patches: G().planLinkDrop(p.doc.text(), r.family) }]); ops.push(() => [{ doc: p.doc, patches: G().planLinkInsert(p.doc.text(), r.href) }]); }
        const docs = this.o.writeOps(ops, 'Google Fonts · ' + r.family + ' · ' + r.weights.join(', ') + ' · ' + pages.length + (pages.length === 1 ? ' עמוד' : ' עמודים'));
        if (!docs) return false;
        this.draw(); return true;
      }
      /** "⬇ הבא לפרויקט": השרת מוריד את ה-woff2; כאן — @font-face מקומי ב-tokens.css, והסרת ה-<link> (תיבת סימון). עסקה אחת. */
      fetchFont(f) {
        const ws = f.grange ? null : [...f.gweights], dlg = document.getElementById('sheet');
        const drop = h('input', { type: 'checkbox', checked: true, 'data-fetch-drop': true }), msg = h('p', { class: 'hint', role: 'status' });
        const go = h('button', { type: 'button', class: 'btn primary', 'data-fetch-ok': true, onclick: async () => {
          go.disabled = true; msg.textContent = 'מוריד מ-Google Fonts…';
          try {
            const r = await api('font_fetch', { family: f.name, weights: ws || [], range: f.grange || undefined, italic: !!f.gitalic });
            await this.o.reload();
            const doc = this.tokensDoc(); if (!doc) { this.trash(r.files.map(x => x.file.id)); return; }
            const tp = this.tokensPath(), ops = r.files.map(x => () => [{ doc, patches: G().planFontFace(K().parseCss(doc.text()), { family: f.name, weight: x.weight, style: x.style, url: relFrom(tp, x.file.path), format: x.format, range: x.range || undefined }) }]);
            if (drop.checked) for (const p of await this.o.pages()) ops.push(() => [{ doc: p.doc, patches: G().planLinkDrop(p.doc.text(), f.name) }]);
            const docs = this.o.writeOps(ops, 'הבא לפרויקט · ' + f.name + ' · ' + r.files.length + ' קבצים · ' + KB(r.bytes) + ' ק"ב');
            if (!docs || !docs.length) { this.trash(r.files.map(x => x.file.id)); msg.textContent = '⚠️ לא נכתב'; go.disabled = false; return; }
            this.fx(r.files.map(x => x.file.id));
            dlg.close(); this.draw();
            this.o.note(f.name + ' — בפרויקט (' + r.files.length + ' קבצים, ' + KB(r.bytes) + ' ק"ב)' + (drop.checked ? '; ה-<link> ל-Google הוסר' : ''), false);
          } catch (e) { msg.textContent = '⚠️ ' + e.message; go.disabled = false; }
        } }, '⬇ הבא לפרויקט');
        sheet('⬇ הבא לפרויקט — ' + f.name, h('p', {}, 'השרת מוריד את קובצי ה-woff2 של ', h('b', {}, f.name), ' (' + (f.grange ? 'משקלים ' + f.grange.join('–') : 'משקלים ' + ws.join(', ')) + ') מ-Google Fonts, ושומר אותם ב-media/fonts/. ב-' + (this.tokensPath() || 'tokens.css') + ' נכתב @font-face מקומי לכל קובץ.'),
          h('label', { class: 'gl-chk' }, drop, ' להסיר את ה-<link> ל-Google מכל העמודים (אחרי זה הגופן אינו בקשה חיצונית — לא בעורך ולא אצל הגולש)'), msg, h('div', { class: 'gl-bar' }, go));
      }
      /** מחיקת גופן: @font-face, ה-<link>, והקבצים (לסל). בשימוש — אזהרה עם הרשימה, ו"החלף בגופן אחר בכל מקום". */
      delFont(f, row) {
        const old = row.querySelector('.gl-del'); if (old) { old.remove(); return; }
        const others = [...this.fonts().values()].filter(x => x.name !== f.name && x.src !== 'page').map(x => x.name).concat(['system-ui', 'Arial', 'serif', 'sans-serif']);
        const rep = h('select', { 'aria-label': 'גופן חלופי', 'data-font-rep': true }, others.map(x => h('option', { value: x }, x)));
        const box = h('div', { class: 'gl-del sg-over', 'data-font-del-box': true });
        if (f.uses.length) box.append(h('p', {}, '⚠️ ' + f.name + ' בשימוש ב-' + f.uses.length + ' כללים:'), this.useList(f.uses), h('label', { class: 'gl-chk' }, 'להחליף בכל מקום ב-', rep));
        box.append(h('div', { class: 'gl-bar' }, h('button', { type: 'button', class: 'btn sm danger', 'data-font-del-ok': true, onclick: () => this.removeFont(f, f.uses.length ? rep.value : null) }, f.uses.length ? 'החלף ומחק' : 'מחק את ' + f.name), h('button', { type: 'button', class: 'btn sm', onclick: () => box.remove() }, 'ביטול')));
        row.append(box);
      }
      async removeFont(f, repl) {
        const pages = await this.o.pages(), ops = [];
        const val = repl ? (G().GENERIC.has(repl) ? repl : "'" + G().checkFamily(repl) + "'") : null;
        for (const s of this.o.sheets()) {
          if (val) ops.push(() => [{ doc: s.doc, patches: G().planReplaceFamily(K().parseCss(s.doc.text()), f.name, val) }]);
          const n = G().fontFaces(K().parseCss(s.doc.text())).filter(x => x.family.toLowerCase() === f.name.toLowerCase()).length;
          for (let i = 0; i < n; i++) ops.push(() => { const m = K().parseCss(s.doc.text()), ff = G().fontFaces(m).find(x => x.family.toLowerCase() === f.name.toLowerCase()); return ff ? [{ doc: s.doc, patches: K().css.removeNode(m, ff.node).patches }] : []; });
        }
        for (const p of pages) ops.push(() => [{ doc: p.doc, patches: G().planLinkDrop(p.doc.text(), f.name) }]);
        const docs = this.o.writeOps(ops, 'מחיקת גופן · ' + f.name + (repl ? ' ← ' + repl + ' בכל מקום' : ''));
        if (!docs) return;
        const ids = f.files.map(x => x.id);
        if (ids.length) { await this.trash(ids); this.o.attachFx({ undo: () => api('pf_restore', { ids }).then(() => this.o.reload()).catch(() => {}), redo: () => this.trash(ids) }); }
        this.draw();
      }

      // ── משתנים ───────────────────────────────────────────
      drawVars() {
        const vars = this.vars(), texts = this.texts(), q = this.q.toLowerCase();
        const groups = { color: [], font: [], size: [], other: [] };
        for (const v of vars.values()) { if (q && !v.name.toLowerCase().includes(q)) continue; groups[G().kindOf(G().mainDef(v).value, isColor)].push(v); }
        const search = h('input', { type: 'search', dir: 'ltr', value: this.q, placeholder: '--color…', 'aria-label': 'חיפוש משתנה', 'data-var-q': true });
        search.addEventListener('input', () => { this.q = search.value; const at = search.selectionStart; this.draw(); const s2 = this.host.querySelector('[data-var-q]'); if (s2) { s2.focus(); s2.setSelectionRange(at, at); } });
        const box = h('section', { class: 'gl-sec', 'data-gl': 'vars' }, h('div', { class: 'gl-bar' }, search, h('button', { type: 'button', class: 'btn sm primary', 'data-var-new': true, disabled: this.ro() || null, onclick: () => box.querySelector('.gl-newvar') ? box.querySelector('.gl-newvar').remove() : box.firstChild.after(this.newVarForm()) }, '＋ משתנה')));
        for (const [k, label] of [['color', '🎨 צבע'], ['font', '🔤 גופן'], ['size', '📏 מידה'], ['other', '… אחר']]) {
          if (!groups[k].length) continue;
          box.append(h('h4', { class: 'gl-h' }, label + ' (' + groups[k].length + ')'), ...groups[k].map(v => this.varRow(v, k, texts)));
        }
        if (!vars.size) box.append(h('p', { class: 'hint' }, 'אין משתנים (--…) בקובצי ה-CSS של העמוד. "＋ משתנה" יוצר אחד ב-' + (this.tokensPath() || 'tokens.css') + '.'));
        return box;
      }
      varRow(v, kind, texts) {
        const d = G().mainDef(v), uses = G().usages(texts, v.name);
        const row = h('div', { class: 'gl-var', 'data-var': v.name, 'data-kind': kind });
        const at = def => h('button', { type: 'button', class: 'sg-at', dir: 'ltr', title: 'פתח בקוד', onclick: () => this.o.openAt(def.path, def.line) }, def.path + ':' + def.line + (def.root ? '' : ' · ' + (def.media ? def.media + ' ' : '') + def.sel));
        row.append(h('div', { class: 'gl-vline' }, h('code', { dir: 'ltr', class: 'gl-vname' }, v.name), this.valueCtl(kind, d.value, val => this.setVar(v, d, val), live => this.liveVar(v.name, live), v.name),
          h('button', { type: 'button', class: 'sg-reset', title: 'שינוי שם — בכל ההופעות', 'aria-label': 'שינוי שם ' + v.name, 'data-var-rename': v.name, disabled: this.ro() || null, onclick: () => this.renameForm(row, v, texts) }, '✎')),
          h('div', { class: 'gl-meta' }, at(d), ' · ', h('span', { 'data-var-uses': uses.length }, uses.length ? 'בשימוש ב-' + uses.length + ' הצהרות' : 'לא בשימוש')));
        for (const o of v.defs) if (o !== d) row.append(h('div', { class: 'gl-vdef', 'data-var-def': o.path + ':' + o.line }, h('span', { class: 'gl-meta' }, o.media ? '📱 ' : '↳ ', at(o)), this.valueCtl(kind, o.value, val => this.setVar(v, o, val), null, v.name + ' · ' + (o.media || o.sel))));
        return row;
      }
      /** שליטה לערך לפי הסוג: צבע (בוחר + טקסט), גופן (רשימה), מידה / אחר (טקסט). commit בשחרור / Enter; live — בזמן גרירה. */
      valueCtl(kind, value, commit, live, label) {
        const txt = h('input', { type: 'text', dir: 'ltr', value, 'aria-label': 'ערך ' + label, spellcheck: 'false', 'data-var-value': true, disabled: this.ro() || null });
        const okv = v => { try { G().checkRaw(v); return kind !== 'color' || isColor(v) || /var\(/.test(v); } catch { return false; } };
        const send = v => { v = v.trim(); if (v === value) return; if (!okv(v)) { txt.classList.add('bad'); txt.setAttribute('aria-invalid', 'true'); return; } txt.classList.remove('bad'); commit(v); };
        txt.addEventListener('change', () => send(txt.value));
        txt.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); send(txt.value); } });
        if (kind === 'color' && isColor(value)) {
          const col = h('input', { type: 'color', value: toHex(value), 'aria-label': 'בוחר צבע — ' + label, 'data-var-color': true, disabled: this.ro() || null });
          col.addEventListener('input', () => { txt.value = col.value; if (live) live(col.value); });
          col.addEventListener('change', () => { if (live) live(null); send(col.value); });
          return h('span', { class: 'gl-vctl' }, col, txt);
        }
        if (kind === 'font') { txt.setAttribute('list', 'gl-fonts'); return h('span', { class: 'gl-vctl' }, txt, h('datalist', { id: 'gl-fonts' }, [...this.fonts().values()].map(f => h('option', { value: G().familyValue(f.name) })))); }
        if (live) txt.addEventListener('input', () => { if (okv(txt.value.trim())) live(txt.value.trim()); });
        txt.addEventListener('blur', () => { if (live) live(null); });
        return h('span', { class: 'gl-vctl' }, txt);
      }
      /** תצוגה בזמן גרירה — בקנבס בלבד (style על <html>), מוסר לפני הכתיבה. */
      liveVar(name, v) { const r = this.o.canvas.doc?.documentElement; if (!r) return; if (v == null) r.style.removeProperty(name); else r.style.setProperty(name, v); }
      setVar(v, def, value) {
        this.liveVar(v.name, null);
        const doc = this.o.docOf(def.path); if (!doc) return;
        const same = this.sheets().find(s => s.path === def.path), idx = v.defs.filter(x => x.path === def.path).indexOf(def);
        this.o.writeOps([() => { const m = K().parseCss(doc.text()), cur = (G().collectVars([{ path: def.path, m }]).get(v.name)?.defs || [])[idx]; if (!cur) throw new Error('ההגדרה של ' + v.name + ' השתנתה — פותחים שוב'); return [{ doc, patches: G().planSetVar(m, cur, value) }]; }],
          'משתנה ' + v.name + (def.media ? ' · ' + def.media : '') + ' · ' + def.value + ' ← ' + value);
        void same;
      }
      renameForm(row, v, texts) {
        const old = row.querySelector('.gl-rename'); if (old) { old.remove(); return; }
        const inp = h('input', { type: 'text', dir: 'ltr', value: v.name, 'aria-label': 'שם חדש', 'data-var-newname': true, spellcheck: 'false' }), info = h('p', { class: 'hint', 'data-rename-info': true });
        const plan = () => { const neu = inp.value.trim(); const per = []; try { G().checkVarName(neu); } catch (e) { info.textContent = e.message; return null; } if (neu === v.name) { info.textContent = ''; return null; } if (this.vars().has(neu)) { info.textContent = 'כבר יש משתנה ' + neu; return null; }
          for (const s of this.o.sheets()) { const ps = G().planRename(s.doc.text(), v.name, neu); if (ps.length) per.push({ path: s.path, doc: s.doc, n: ps.length }); }
          const hd = this.o.html(); if (hd) { const ps = G().planRename(hd.text(), v.name, neu, true); if (ps.length) per.push({ path: this.o.filePath(), doc: hd, n: ps.length, html: true }); }
          const js = (this.o.jsTexts ? this.o.jsTexts() : []).filter(x => x.text.includes(v.name)).map(x => x.path);
          info.textContent = 'ישתנה ב-' + per.reduce((a, x) => a + x.n, 0) + ' מקומות: ' + per.map(x => x.path + ' (' + x.n + ')').join(', ') + (js.length ? ' · ⚠️ מופיע גם ב-' + js.join(', ') + ' — שם לא משתנה' : '');
          return { neu, per }; };
        inp.addEventListener('input', plan);
        const go = h('button', { type: 'button', class: 'btn sm primary', 'data-rename-ok': true, onclick: () => { const p = plan(); if (!p) return; this.o.writeOps(p.per.map(x => () => [{ doc: x.doc, patches: G().planRename(x.doc.text(), v.name, p.neu, x.html) }]), 'שינוי שם משתנה · ' + v.name + ' ← ' + p.neu); } }, 'שנה בכל המקומות');
        row.append(h('div', { class: 'gl-rename' }, inp, go, info)); inp.focus(); inp.select();
      }
      newVarForm(pre = {}) {
        const name = h('input', { type: 'text', dir: 'ltr', value: pre.name || '--', 'aria-label': 'שם המשתנה', 'data-newvar-name': true, spellcheck: 'false' });
        const val = h('input', { type: 'text', dir: 'ltr', value: pre.value || '', 'aria-label': 'ערך', 'data-newvar-value': true, spellcheck: 'false', placeholder: '#14213d · 16px · \'Heebo\', sans-serif' });
        const go = h('button', { type: 'button', class: 'btn sm primary', 'data-newvar-ok': true, onclick: () => this.newVar(name.value.trim(), val.value.trim()) }, 'צור ב-' + (this.tokensPath() || 'tokens.css'));
        return h('div', { class: 'gl-newvar gl-rename' }, name, val, go);
      }
      newVar(name, value, label) {
        const doc = this.tokensDoc(); if (!doc) return null;
        try { G().checkVarName(name); G().checkRaw(value); if (this.vars().has(name)) throw new Error('כבר יש משתנה ' + name); } catch (e) { this.o.note(e.message, true); return null; }
        return this.o.writeOps([() => [{ doc, patches: G().planNewVar(K().parseCss(doc.text()), name, value) }]], label || 'משתנה חדש · ' + name + ': ' + value);
      }

      // ── "⛓ קשר למשתנה" מכל שליטה (design.js) ─────────────
      /** הכפתור ליד ⟲. */
      bindBtn(n, phys, label, D) {
        const kind = kindOfProp(phys); if (!kind) return null;
        return h('button', { type: 'button', class: 'sg-varbtn', 'data-var-bind': phys, title: 'קשר למשתנה / צור משתנה מהערך', 'aria-label': 'משתנה — ' + label, disabled: !D.canWrite() || null, onclick: () => this.bindMenu(n, phys, label, D, kind) }, '⛓ משתנה');
      }
      /** ערך שקשור למשתנה: צ'יפ עם השם והערך המחושב, ו"נתק". */
      chip(n, phys, s, D) {
        const decl = (s.winner ? s.declared : '') || '', m = /^var\(\s*(--[\w-]+)\s*(?:,[^)]*)?\)$/.exec(decl.trim()); if (!m) return null;
        const v = this.vars().get(m[1]), plain = v ? G().mainDef(v).value : '';
        const cut = /var\(/.test(plain) || !plain ? s.value : plain;
        return h('div', { class: 'sg-vchip', 'data-var-chip': m[1] }, h('span', { class: 'sg-chip var' }, h('i', { style: COLOR_P.test(phys) ? 'background:' + (s.value || 'transparent') : 'display:none' }), h('span', { dir: 'ltr' }, '⛓ ' + m[1])), h('span', { dir: 'ltr', class: 'hint' }, ' = ' + (s.value || '?')),
          h('button', { type: 'button', class: 'btn sm', 'data-var-unbind': phys, disabled: !D.canWrite() || null, title: 'כותב את הערך (' + cut + ') במקום var(' + m[1] + ')', onclick: () => D.put([[phys, cut]], 'נתק מ-' + m[1] + ' · ' + phys + ' · ' + this.o.canvas.label(n)) }, 'נתק'),
          this.bindBtn(n, phys, phys, D),
          v ? h('button', { type: 'button', class: 'btn sm', title: 'המשתנה ב"סגנון כללי"', onclick: () => { this.sub = 'vars'; this.q = m[1]; this.o.setTab('glob'); } }, '🎛') : h('span', { class: 'sg-over' }, ' המשתנה אינו מוגדר'));
      }
      bindMenu(n, phys, label, D, kind) {
        const row = D.host.querySelector('[data-field="' + phys + '"]'); if (!row) return;
        const old = row.querySelector('.sg-varmenu'); if (old) { old.remove(); return; }
        const vars = this.vars(), s = D.styler.source(n, phys, D.info), cur = ((s.winner ? s.declared : '') || s.value || '').trim();
        const box = h('div', { class: 'sg-varmenu', 'data-var-menu': phys, role: 'dialog', 'aria-label': 'משתנה — ' + label });
        const list = [...vars.values()].filter(v => G().kindOf(G().mainDef(v).value, isColor) === kind);
        box.append(h('h4', { class: 'sg-h4' }, 'קשר למשתנה (' + list.length + ')'));
        const chips = h('div', { class: 'sg-page-colors' }, list.slice(0, 60).map(v => { const val = G().mainDef(v).value, b = h('button', { type: 'button', class: 'sg-chip var', 'data-bind-var': v.name, title: v.name + ': ' + val, onclick: () => { box.remove(); D.put([[phys, 'var(' + v.name + ')']], label + ' · ' + this.o.canvas.label(n) + ' ← var(' + v.name + ')'); } }, h('i', {}), h('span', { dir: 'ltr' }, v.name)); if (kind === 'color') b.firstChild.style.background = val; else b.firstChild.remove(); return b; }));
        box.append(list.length ? chips : h('p', { class: 'hint' }, 'אין משתנים מהסוג הזה.'));
        if (cur && !/^var\(/.test(cur)) {
          const ctx = D.t && D.t.kind !== 'inline' ? (D.t.kind === 'newclass' ? D.t.name : D.t.sel) : this.o.canvas.label(n);
          const name = h('input', { type: 'text', dir: 'ltr', value: G().suggestName(kind, ctx, phys, new Set(vars.keys())), 'aria-label': 'שם המשתנה החדש', 'data-newvar-from': true, spellcheck: 'false' });
          const same = this.sameIn(cur, kind, D, n, phys), also = h('input', { type: 'checkbox', 'data-newvar-also': true, checked: same.length > 0 || null, disabled: !same.length || null });
          box.append(h('h4', { class: 'sg-h4' }, 'צור משתנה מהערך ', h('code', { dir: 'ltr' }, cur)), h('div', { class: 'gl-rename' }, name,
            h('button', { type: 'button', class: 'btn sm primary', 'data-newvar-go': true, onclick: () => { box.remove(); this.fromValue(n, phys, label, D, name.value.trim(), cur, also.checked ? same : []); } }, 'צור ב-' + (this.tokensPath() || 'tokens.css'))),
            same.length ? h('label', { class: 'gl-chk' }, also, ' להחליף גם ב-' + same.length + ' מקומות אחרים שבהם מופיע ' + cur) : h('p', { class: 'hint' }, 'הערך אינו מופיע במקום אחר.'));
          if (same.length) box.append(h('ul', { class: 'gl-uses', 'data-newvar-list': true }, same.slice(0, 30).map(x => h('li', { dir: 'ltr' }, x.sel + ' { ' + x.prop + ' } · ' + x.path + ':' + x.line))));
        }
        row.append(box);
      }
      /** אותו ערך במקומות אחרים (לא בהגדרות משתנים, ולא בהצהרה הנוכחית). */
      sameIn(value, kind, D, n, phys) {
        const eq = kind === 'color' ? (a, b) => { const x = window.KolDesign.rgba(a), y = window.KolDesign.rgba(b); return !!x && !!y && x.every((c, i) => Math.abs(c - y[i]) < 0.01) && !/var\(/.test(a); } : null, out = [];
        const own = D.t && D.t.kind === 'rule' ? { sel: D.t.sel.replace(/\s+/g, ' ').trim(), path: D.t.path } : null;
        for (const s of this.sheets()) for (const x of G().planReplaceValue(s.m, value, '--x', eq, (r, d) => own && own.path === s.path && r.sel.replace(/\s+/g, ' ').trim() === own.sel && window.KolStyle.covered(d.prop, true).includes(phys)).hits) out.push({ ...x, path: s.path });
        return out;
      }
      /** "צור משתנה מהערך": המשתנה ב-tokens.css, ההצהרה הנוכחית ← var(), ואם נבחר — גם N המקומות האחרים. עסקה אחת. */
      fromValue(n, phys, label, D, name, value, others) {
        try { G().checkVarName(name); G().checkRaw(value); if (this.vars().has(name)) throw new Error('כבר יש משתנה ' + name); } catch (e) { this.o.note(e.message, true); return; }
        const tx = 'צור משתנה ' + name + ' מ-' + value + ' · ' + label + (others.length ? ' · ועוד ' + others.length + ' מקומות' : '');
        const r1 = D.put([[phys, 'var(' + name + ')']], tx); if (!r1) return;
        const doc = this.tokensDoc(); if (!doc) return;
        const kind = kindOfProp(phys), eq = kind === 'color' ? (a, b) => { const x = window.KolDesign.rgba(a), y = window.KolDesign.rgba(b); return !!x && !!y && x.every((c, i) => Math.abs(c - y[i]) < 0.01) && !/var\(/.test(a); } : null;
        const ops = [() => [{ doc, patches: G().planNewVar(K().parseCss(doc.text()), name, value) }]];
        if (others.length) for (const s of this.o.sheets()) ops.push(() => [{ doc: s.doc, patches: G().planReplaceValue(K().parseCss(s.doc.text()), value, name, eq).patches }]);
        const r2 = this.o.writeOps(ops, tx);
        if (r2 && r2.length) this.o.joinTx(2, tx);
      }

      // ── ברירות מחדל לתגיות ───────────────────────────────
      drawTags() {
        const c = this.o.canvas, bp = this.o.basePath(), want = this.o.kol()?.styles?.base || 'css/base.css', box = h('section', { class: 'gl-sec', 'data-gl': 'tags' });
        if (!bp) box.append(h('p', { class: 'sg-over' }, 'קובץ ברירות המחדל לתגיות (' + want + ') אינו מקושר לעמוד הזה.', ' ', h('button', { type: 'button', class: 'btn sm', disabled: this.ro() || !this.o.files().some(f => f.path === want) || null, onclick: async () => { await this.o.linkSheet(want); this.draw(); } }, 'לקשר את ' + want)));
        else box.append(h('p', { class: 'hint' }, 'בוחרים תגית — ואותו פאנל ("🎨 עיצוב", "📐 מתקדם") כותב לכלל שלה ב-' + bp + '. כל ' + 'התגיות מהסוג משתנות יחד.'));
        const sheet = bp && this.sheets().find(s => s.path === bp);
        for (const [tag, label] of TAGS) {
          const els = c.doc ? [...c.doc.querySelectorAll(tag)].filter(e => !e.closest('[data-kol-canvas]')) : [];
          const rules = sheet ? K().cssAll(sheet.m).filter(r => r.type === 'rule' && !r.broken && window.KolDetails.splitSel(r.sel).some(x => x.trim().toLowerCase() === tag)) : [];
          const decl = rules.flatMap(r => r.decls.map(d => d.prop + ': ' + d.value)).slice(0, 4).join('; ');
          box.append(h('div', { class: 'gl-tag', 'data-tag': tag }, h('button', { type: 'button', class: 'btn sm', 'data-tag-edit': tag, disabled: !els.length || !bp || null, onclick: () => this.editTag(tag) }, '✎'),
            h('b', {}, label), h('code', { dir: 'ltr' }, '<' + tag + '>'), h('span', { class: 'gl-meta' }, els.length ? els.length + ' בעמוד' : 'אין בעמוד'), decl ? h('span', { class: 'gl-meta', dir: 'ltr' }, decl) : null));
        }
        box.append(h('h4', { class: 'gl-h' }, '📐 פריסה כללית'));
        const vars = this.vars();
        for (const [name, label, def] of LAYOUT) {
          const v = vars.get(name);
          box.append(v ? h('div', { class: 'gl-var', 'data-var': name }, h('div', { class: 'gl-vline' }, h('span', {}, label), h('code', { dir: 'ltr' }, name), this.valueCtl('size', G().mainDef(v).value, val => this.setVar(v, G().mainDef(v), val), live => this.liveVar(name, live), name)))
            : h('div', { class: 'gl-var' }, h('span', {}, label + ' '), h('button', { type: 'button', class: 'btn sm', 'data-layout-new': name, disabled: this.ro() || null, onclick: () => this.newVar(name, def) }, 'צור ' + name + ': ' + def)));
        }
        box.append(h('p', { class: 'hint' }, 'משתמשים בהם בכל שליטת מידה (⛓ ← קשר למשתנה): max-width של המכל, gap של פריסה.'));
        return box;
      }
      /** עריכת תגית: האלמנט הראשון נבחר, "🎨 עיצוב" נפתח, והיעד = הכלל של התגית ב-base.css. כמה לא ישתנו — מסומנים. */
      editTag(tag) {
        const c = this.o.canvas, e = [...c.doc.querySelectorAll(tag)].find(x => !x.closest('[data-kol-canvas]')), n = e && c.nodeOf(e); if (!n) return;
        c.select(n); c.reveal(n); this.o.setTab('design');
        const D = this.o.design(), t = D.list && D.list.find(x => x.why === 'tag'); if (t) D.setTarget(t);
        const props = ['font-size', 'font-family', 'font-weight', 'color', 'line-height', 'margin-top', 'margin-bottom', 'letter-spacing'], over = [];
        const all = [...c.doc.querySelectorAll(tag)].filter(x => !x.closest('[data-kol-canvas]'));
        for (const x of all.slice(0, 300)) { const xn = c.nodeOf(x); if (!xn) continue; const info = this.o.styler.info(xn); if (info.rules.some(r => !r.off && (r.inline || window.KolDetails.splitSel(r.sel).every(s => s.trim().toLowerCase() !== tag)) && r.decls.some(d => props.includes(d.prop) || /^(font|margin)$/.test(d.prop)))) over.push(x); }
        if (over.length) { this.o.highlight(over); this.o.note(over.length + ' מתוך ' + all.length + ' <' + tag + '> עם כלל של מחלקה שדורס חלק מהעיצוב — הם מסומנים, וחלק מהשינויים לא יחולו עליהם', false); }
        else this.o.note('כל השינויים נכתבים לכלל ' + tag + ' — ' + all.length + ' אלמנטים', false);
      }
    }
    return Globals;
  }

  g.KolGlobals = { VAR_NAME, FAMILY, checkVarName, checkFamily, checkRaw, families, familyValue, kindOf, collectVars, mainDef, usages, planSetVar, planRename, planNewVar, planReplaceValue,
    suggestName, fontFaceDecls, planFontFace, fontFaces, googleLinks, planLinkInsert, planLinkDrop, familyUses, planReplaceFamily, cssRanges, lead, GENERIC };
  if (typeof window !== 'undefined' && window.document) g.KolGlobals.Globals = makeUi();
})(typeof window !== 'undefined' ? window : globalThis);
