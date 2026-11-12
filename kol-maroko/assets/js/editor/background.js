/**
 * העורך החזותי — רקע של רובריקה: תמונה, כיסוי, שכבות (פרוסה V5, 03 §4.3, §5.2).
 *
 * החלק הטהור (נבדק ב-node, tests/editor/background.mjs):
 * - קריאת קיצור `background` לשכבות (תמונה, מיקום / גודל, חזרה, קיבוע, origin/clip, צבע) — לקריאה בלבד: העורך **לעולם
 *   אינו מפרק קיצור** ואינו ממזג הצהרות לקיצור; כתיבה לרכיב של קיצור — תמיד הצהרה מפורשת מיד אחריו.
 * - כל url() בתוך ערך, עם המיקום המדויק והמרכאות — כדי להחליף רק אותו.
 * - **בדיקת הזרקה לכל כתובת שנכתבת:** תו בקרה, '<' / '>' — נדחים; scheme שאינו http(s) / blob / data:image — נדחה;
 *   מרכאות, סוגריים, לוכסן הפוך ורווחים — מקודדים (%27, %28…) ולכן אינם יכולים לסגור את ה-url() / ההצהרה / הכלל.
 * - תכנון ההסרה (הצהרה / שכבה מרשימה עם ההצהרות המקבילות / הצהרה מפורשת אחרי קיצור), ההחלפה וסדר השכבות.
 *
 * החלק שבדפדפן (Bg) — קבוצת "רקע" בלשונית עיצוב, הכיסוי, ✥ הזזה על הקנבס. כל ערך מהעמוד — כטקסט; אין innerHTML.
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc, ST = () => g.KolStyle;
  const PARALLEL = ['background-position', 'background-size', 'background-repeat', 'background-attachment', 'background-origin', 'background-clip', 'background-blend-mode', 'background-position-x', 'background-position-y'];
  const IMG = /^(url\(|(-webkit-)?(repeating-)?(linear|radial|conic)-gradient\(|(-webkit-)?image-set\(|cross-fade\(|element\(|image\(|-moz-element\(|none$)/i;
  const REPEAT = /^(repeat|repeat-x|repeat-y|no-repeat|space|round)$/i, ATT = /^(scroll|fixed|local)$/i, BOX = /^(border-box|padding-box|content-box|text)$/i;
  const LEN = /^(-?(\d+\.?\d*|\.\d+)([a-z]+|%)?|(calc|clamp|min|max|env)\(.*\))$/i, POSW = /^(left|right|top|bottom|center)$/i, SIZEW = /^(cover|contain|auto)$/i;

  /** מפצל בפסיקים של הרמה העליונה. */
  function splitComma(v) {
    const out = []; let cur = '', depth = 0, q = '';
    for (const ch of String(v)) {
      if (q) { cur += ch; if (ch === q) q = ''; continue; }
      if (ch === '"' || ch === "'") { q = ch; cur += ch; continue; }
      if (ch === '(') depth++; else if (ch === ')') depth = Math.max(0, depth - 1);
      if (ch === ',' && !depth) { out.push(cur.trim()); cur = ''; } else cur += ch;
    }
    out.push(cur.trim());
    return out;
  }
  /** אסימונים של שכבה: רווח ו-'/' ברמה העליונה מפרידים ('/' — אסימון משלו). */
  function tokens(s) {
    const out = []; let cur = '', depth = 0, q = '';
    const flush = () => { if (cur) out.push(cur); cur = ''; };
    for (const ch of String(s)) {
      if (q) { cur += ch; if (ch === q) q = ''; continue; }
      if (ch === '"' || ch === "'") { q = ch; cur += ch; continue; }
      if (ch === '(') depth++; else if (ch === ')') depth = Math.max(0, depth - 1);
      if (!depth && /\s/.test(ch)) flush();
      else if (!depth && ch === '/') { flush(); out.push('/'); }
      else cur += ch;
    }
    flush();
    return out;
  }
  /** שכבה של קיצור background ← רכיבים. null — תחביר שהעורך אינו מפרק. */
  function parseLayer(s, last) {
    const L = { image: null, pos: [], size: [], repeat: [], att: null, box: [], color: null };
    const t = tokens(s); let slash = false;
    for (const x of t) {
      if (x === '/') { if (!L.pos.length || slash) return null; slash = true; continue; }
      if (slash) { if ((SIZEW.test(x) || LEN.test(x) || /^var\(/i.test(x)) && L.size.length < 2) { L.size.push(x); continue; } if (!L.size.length) return null; slash = false; }
      if (IMG.test(x) && !L.image) L.image = x;
      else if (REPEAT.test(x) && L.repeat.length < 2) L.repeat.push(x);
      else if (ATT.test(x) && !L.att) L.att = x;
      else if (BOX.test(x) && L.box.length < 2) L.box.push(x);
      else if ((POSW.test(x) || LEN.test(x)) && L.pos.length < 4) L.pos.push(x);
      else if (/^var\(/i.test(x)) { if (last && !L.color) L.color = x; else if (L.pos.length < 4) L.pos.push(x); else return null; }
      else if (last && !L.color && !/^(inherit|initial|unset|revert|revert-layer)$/i.test(x)) L.color = x;
      else return null;
    }
    if (slash && !L.size.length) return null;
    return L;
  }
  /** קיצור background ← שכבות; null — לא מפורק. */
  function parseBg(v) {
    const parts = splitComma(v); if (parts.some(p => !p)) return null;
    const out = [];
    for (let i = 0; i < parts.length; i++) { const L = parseLayer(parts[i], i === parts.length - 1); if (!L) return null; out.push(L); }
    return out;
  }
  const comp = {
    'background-image': L => L.image || 'none', 'background-position': L => L.pos.join(' ') || '0% 0%', 'background-size': L => L.size.join(' ') || 'auto',
    'background-repeat': L => L.repeat.join(' ') || 'repeat', 'background-attachment': L => L.att || 'scroll', 'background-origin': L => L.box[0] || 'padding-box',
    'background-clip': L => L.box[1] || L.box[0] || 'border-box',
  };
  const BG_COVERED = ['background-color', 'background-image', 'background-position', 'background-size', 'background-repeat', 'background-attachment', 'background-origin', 'background-clip'];
  /** הרכיב של מאפיין בתוך קיצור background (לקריאה בלבד); null — לא מפורק. */
  function bgComponent(v, phys) {
    const ls = parseBg(v); if (!ls) return null;
    if (phys === 'background-color') return ls[ls.length - 1].color || 'transparent';
    const f = comp[phys]; return f ? ls.map(f).join(', ') : null;
  }

  // ── url() ────────────────────────────────────────────────
  /** כל url() בערך: { from, to (בתוך הערך), url (כפי שכתוב, בלי מרכאות), q (מרכאה או ''), layer }. */
  function urls(v) {
    const s = String(v), out = []; let depth = 0, q = '', layer = 0;
    for (let i = 0; i < s.length; i++) {
      const ch = s[i];
      if (q) { if (ch === '\\') i++; else if (ch === q) q = ''; continue; }
      if (ch === '"' || ch === "'") { q = ch; continue; }
      if (/u/i.test(ch) && /^url\(/i.test(s.slice(i, i + 4)) && !/[\w-]/.test(s[i - 1] || '')) {
        let j = i + 4; while (/\s/.test(s[j] || '')) j++;
        let url = '', qq = '';
        if (s[j] === '"' || s[j] === "'") { qq = s[j]; let k = j + 1; while (k < s.length && s[k] !== qq) { if (s[k] === '\\') k++; k++; } url = s.slice(j + 1, k); j = k + 1; while (/\s/.test(s[j] || '')) j++; }
        else { let k = j; while (k < s.length && s[k] !== ')') { if (s[k] === '\\') k++; k++; } url = s.slice(j, k).trim(); j = k; }
        if (s[j] !== ')') { i = j; continue; }
        out.push({ from: i, to: j + 1, url, q: qq, layer }); i = j; continue;
      }
      if (ch === '(') depth++; else if (ch === ')') depth = Math.max(0, depth - 1); else if (ch === ',' && !depth) layer++;
    }
    return out;
  }
  const isUrlLayer = s => /^url\(/i.test(String(s).trim());
  const err = (msg, code) => new (K().SrcError)(msg, code || 'invalid');
  /** בדיקת כתובת לפני כתיבה. מחזיר את הכתובת (מנוקה מרווחים בקצוות) או זורק. */
  function checkUrl(u) {
    u = String(u == null ? '' : u).trim();
    if (!u) throw err('כתובת ריקה');
    if (/[\u0000-\u001f\u007f<>]/.test(u)) throw err('הכתובת מכילה תו אסור (< > או תו בקרה) — לא נכתב');
    const sch = /^([a-z][a-z0-9+.-]*):/i.exec(u);
    if (sch) {
      const s = sch[1].toLowerCase();
      if (!(s === 'http' || s === 'https' || s === 'blob' || (s === 'data' && /^data:image\/[\w.+-]+[;,]/i.test(u)))) throw err('כתובת מסוג ' + s + ': אינה מותרת כתמונת רקע — רק http(s), נתיב יחסי, data:image או blob');
    }
    return u;
  }
  /** url() לכתיבה: מקודד כך ששום תו אינו יכול לסגור את המרכאות / הסוגריים / ההצהרה. q — '\'' / '"' / '' (כמו בקובץ). */
  function urlCss(u, q) {
    u = checkUrl(u); q = q === '"' || q === '' ? q : "'";
    const enc = u.replace(/[\s'"()\\]/g, ch => '%' + ch.charCodeAt(0).toString(16).toUpperCase().padStart(2, '0'));
    return 'url(' + q + enc + q + ')';
  }
  /** המרכאה שהקובץ משתמש בה ב-url() (ברירת מחדל: '). */
  function quoteOf(text) { const u = urls(text); if (!u.length) return "'"; const n = { "'": 0, '"': 0, '': 0 }; for (const x of u) n[x.q]++; return n['"'] > n["'"] && n['"'] >= n[''] ? '"' : n[''] > n["'"] ? '' : "'"; }
  /** נתיב בפרויקט ← הפניה יחסית מקובץ (css/page.css + media/a.jpg ← ../media/a.jpg). */
  function relFrom(fromFile, toPath) {
    const from = String(fromFile).split('/').slice(0, -1).filter(Boolean), to = String(toPath).split('/').filter(Boolean);
    let i = 0; while (i < from.length && i < to.length - 1 && from[i] === to[i]) i++;
    return '../'.repeat(from.length - i) + to.slice(i).map(encodeURIComponent).join('/');
  }
  /** איפה הכתובת: 'project' (יחסית / data / blob), 'host' (האתר המארח), 'other' (שרת אחר). */
  function urlWhere(u, hostRe) {
    if (!/^(https?:)?\/\//i.test(u)) return 'project';
    return hostRe && hostRe.test(u) ? 'host' : 'other';
  }

  // ── תכנון כתיבה (על מודל CSS של קובץ / <style> / style="") ──
  const lc = p => String(p).trim().toLowerCase();
  /** מחיקת הצהרה אחת בדיוק (כמו planReset). */
  function delDecl(m, rule, d) {
    const ds = rule.decls, at = ds.indexOf(d);
    let from = d.from; while (from > rule.bFrom + 1 && /\s/.test(m.src[from - 1])) from--;
    const to = d.semi >= 0 ? d.semi : d.to;   // semi — אחרי ה-';' (model.js: declEnd)
    if (d.semi < 0 && at === ds.length - 1 && ds.length > 1 && m.src[from - 1] === ';') from--;
    return { from, to, text: '' };
  }
  const imp = d => (d.important ? ' !important' : '');
  /** "הסר את התמונה": מסיר שכבות url() (או drop — אינדקסים) מההצהרה שקובעת background-image בכלל.
   *  מחזיר { patches, kind: 'decl' | 'layers' | 'after-shorthand', decl } או null (אין תמונה בכלל). */
  function planRemoveImage(m, rule, rtl, drop) {
    const d = ST().declFor(rule, 'background-image', rtl); if (!d) return null;
    if (lc(d.prop) === 'background') {
      const ls = parseBg(d.value); if (!ls) throw err('קיצור background שהעורך אינו קורא — מתקנים בעורך הקוד', 'unsupported');
      const dr = drop || ls.map((L, i) => (L.image && isUrlLayer(L.image) ? i : -1)).filter(i => i >= 0);
      if (!dr.length) return null;
      const rest = ls.map((L, i) => (dr.includes(i) ? null : L.image || 'none')).filter(x => x != null);
      const val = rest.length && rest.some(x => x !== 'none') ? rest.join(', ') : 'none';
      return { patches: K().css.addDecl(m, rule, 'background-image', val + imp(d), { index: rule.decls.indexOf(d) + 1 }).patches, kind: 'after-shorthand', decl: d };
    }
    const L = splitComma(d.value), dr = drop || L.map((x, i) => (isUrlLayer(x) ? i : -1)).filter(i => i >= 0);
    if (!dr.length) return null;
    const keep = L.filter((_, i) => !dr.includes(i));
    if (!keep.length) return { patches: [delDecl(m, rule, d)], kind: 'decl', decl: d };
    const ps = [{ from: d.valFrom, to: d.valTo, text: keep.join(', ') }];
    for (const p of PARALLEL) {
      const pd = rule.decls.filter(x => lc(x.prop) === p).pop(); if (!pd) continue;
      const PL = splitComma(pd.value); if (PL.length !== L.length) continue;
      ps.push({ from: pd.valFrom, to: pd.valTo, text: PL.filter((_, i) => !dr.includes(i)).join(', ') });
    }
    return { patches: ps, kind: 'layers', decl: d };
  }
  /** החלפת url() אחד בתוך הצהרה (גם בתוך קיצור, גם בהגדרת משתנה): רק הכתובת משתנה, באותן מרכאות. */
  function planReplaceUrl(d, url, layer) {
    const us = urls(d.value), u = (layer == null ? us[0] : us.find(x => x.layer === layer)) || null;
    if (!u) throw err('בהצהרה אין url() להחלפה', 'noop');
    return { patches: [{ from: d.valFrom + u.from, to: d.valFrom + u.to, text: urlCss(url, u.q) }], was: u };
  }
  /** סדר שכבות: order — האינדקסים הישנים בסדר החדש. נכתב בכל ההצהרות המקבילות שהן רשימה באותו אורך. */
  function planReorder(m, rule, rtl, order) {
    const d = ST().declFor(rule, 'background-image', rtl); if (!d || lc(d.prop) !== 'background-image') throw err('סדר שכבות — רק כשהשכבות כתובות ב-background-image (קיצור אינו מפורק)', 'unsupported');
    const L = splitComma(d.value); if (order.length !== L.length) throw err('מספר השכבות השתנה — נסו שוב', 'stale');
    const ps = [{ from: d.valFrom, to: d.valTo, text: order.map(i => L[i]).join(', ') }];
    for (const p of PARALLEL) { const pd = rule.decls.filter(x => lc(x.prop) === p).pop(); if (!pd) continue; const PL = splitComma(pd.value); if (PL.length === L.length) ps.push({ from: pd.valFrom, to: pd.valTo, text: order.map(i => PL[i]).join(', ') }); }
    return { patches: ps };
  }
  /** הוספת שכבה עליונה (כיסוי חדש: linear-gradient) לפני הקיימות; בקיצור — הצהרה מפורשת אחריו. */
  function planAddLayer(m, rule, rtl, layer) {
    const d = ST().declFor(rule, 'background-image', rtl);
    if (!d) return { patches: K().css.addDecl(m, rule, 'background-image', layer).patches, kind: 'add' };
    if (lc(d.prop) === 'background') {
      const ls = parseBg(d.value); if (!ls) throw err('קיצור background שהעורך אינו קורא — מתקנים בעורך הקוד', 'unsupported');
      const imgs = ls.map(L => L.image || 'none').filter(x => x !== 'none');
      return { patches: K().css.addDecl(m, rule, 'background-image', [layer].concat(imgs).join(', ') + imp(d), { index: rule.decls.indexOf(d) + 1 }).patches, kind: 'after-shorthand' };
    }
    const cur = d.value.trim();
    return { patches: [{ from: d.valFrom, to: d.valTo, text: /^none$/i.test(cur) ? layer : layer + ', ' + cur }], kind: 'layers' };
  }
  /** משתנה בתוך ערך: rgba(10, 16, 32, var(--ka-hero-overlay)) ← ['--ka-hero-overlay']. */
  const varsIn = v => [...String(v).matchAll(/var\(\s*(--[\w-]+)/g)].map(x => x[1]);

  // ══ בדפדפן ════════════════════════════════════════════════
  const hh = (...a) => g.h(...a);
  const fileName = u => { try { return decodeURIComponent(String(u).split(/[?#]/)[0].split('/').pop() || u); } catch { return String(u).split('/').pop(); } };
  const cssUrlOf = v => { const m = /url\(\s*(["']?)(.*?)\1\s*\)/i.exec(String(v || '')); return m ? m[2] : ''; };
  const ruleT = (r, st) => ({ kind: 'rule', sel: r.sel, path: r.path, line: r.line, media: r.media, r, count: st.count(r.sel) });
  const at = (D, r, d, pre) => { const b = hh('button', { type: 'button', class: 'sg-at', dir: 'ltr', title: 'פתח בקוד', disabled: r.external || null }, (pre || '') + r.sel.replace(/\s+/g, ' ') + ' · ' + r.path + (d && d.line ? ':' + d.line : r.line ? ':' + r.line : '')); if (!r.external) b.addEventListener('click', () => D.openAt(r.path, (d && d.line) || r.line || 1)); return b; };

  /** קבוצת "רקע" בלשונית עיצוב, הכיסוי, ו-✥ הזזה על הקנבס. D — ה-Design של לשונית עיצוב; o — מה-screen. */
  class Bg {
    constructor(D, o) { this.D = D; Object.assign(this, o); this.after = null; }
    get st() { return this.D.styler; }
    canWrite() { return this.D.canWrite(); }
    /** יעד הכתיבה של ה-Design — גם כשהפאנל סגור (בטלפון) ולא צויר. */
    ready(n) { const D = this.D; D.info = D.styler.info(n); D.list = D.styler.targets(n, D.info); D.t = D.target(n, D.list); }
    win() { return this.D.canvas.frame.contentWindow; }
    /** תיקונים לכלל מהמפל (r — מ-details.collect), מתוכננים על הטקסט העדכני: fn(m, rule) ← {patches} | null. */
    ruleOps(r, fn) {
      const K2 = K(), src = r.src; if (!r.node || r.external || src.inline) throw err('הכלל אינו בקובץ שנערך כאן', 'missing');
      let doc, text, off = 0;
      if (src.html) { doc = this.html(); const full = doc.text(); off = src.off; text = full.slice(off, off + src.text.length); if (text !== src.text) throw err('הקובץ השתנה בינתיים — נסו שוב', 'stale'); }
      else { doc = this.docOf(src.path); if (!doc) throw err('קובץ העיצוב ' + src.path + ' אינו פתוח לעריכה', 'missing'); text = doc.text(); }
      const m = K2.parseCss(text), rn = K2.cssAll(m).find(x => x.type === 'rule' && x.from === r.node.from && x.sel === r.node.sel);
      if (!rn) throw err('הכלל השתנה בינתיים — נסו שוב', 'stale');
      if (rn.broken || rn.hasBroken) throw err('הכלל נמצא באזור שבור ב-CSS — מתקנים אותו בעורך הקוד', 'broken');
      const res = fn(m, rn); if (!res) return [];
      return [{ doc, html: !!src.html, patches: res.patches.map(p => ({ from: p.from + off, to: p.to + off, text: p.text })) }];
    }
    /** התמונה של האלמנט: ההצהרה שקובעת background-image, השכבות, ואיפה כל url() כתוב (גם דרך var()). */
    image(n, info) {
      const st = this.st, s = st.source(n, 'background-image', info), w = s.winner;
      const e = this.D.canvas.elOf(n), cs = e ? this.win().getComputedStyle(e) : null, computed = cs ? cs.backgroundImage : 'none';
      if (!w) return { w: null, layers: [], computed };
      const declared = s.declared || 'none', layers = splitComma(declared).map((text, i) => {
        const L = { i, text, kind: /^url\(/i.test(text) ? 'url' : /gradient\(/i.test(text) ? 'gradient' : /^none$/i.test(text) ? 'none' : /^var\(/i.test(text) ? 'var' : 'custom' };
        if (L.kind === 'url') { L.url = urls(text)[0]?.url || ''; L.loc = { r: w.r, d: w.d, layer: lc(w.d.prop) === 'background' || lc(w.d.prop) === 'background-image' ? i : null }; }
        if (L.kind === 'var') {
          const name = varsIn(text)[0], vs = name && st.source(n, name, info);
          if (vs && vs.winner) { const u = urls(vs.winner.d.value)[0]; L.via = { name, w: vs.winner }; if (u) { L.kind = 'url'; L.url = u.url; L.loc = { r: vs.winner.r, d: vs.winner.d, layer: null }; } else if (/gradient\(/i.test(vs.winner.d.value)) L.kind = 'gradient'; }
        }
        return L;
      });
      return { w, s, layers, computed, short: lc(w.d.prop) === 'background' };
    }
    /** הכיסוי: ::before / ::after עם content ריק, absolute, שמכסה את האלמנט. */
    overlay(n) {
      const e = this.D.canvas.elOf(n); if (!e) return null;
      for (const ps of ['before', 'after']) {
        const pc = this.win().getComputedStyle(e, '::' + ps);
        if (!/^(""|'')$/.test(pc.content) || pc.position !== 'absolute') continue;
        const r0 = e.getBoundingClientRect(), wd = parseFloat(pc.width) || 0, ht = parseFloat(pc.height) || 0;
        if (wd < r0.width * 0.9 || ht < r0.height * 0.9) continue;
        const info = this.st.details.collect(n, { pseudo: ps }), rtl = this.st.rtl(n);
        const cands = p => this.st.cands(info, p, rtl), col = cands('background-color')[0] || cands('background-image')[0];
        const rule = (cands('content')[0] || col || {}).r || null;
        const color = col ? (KolStyle_().componentOf(col.d.prop, col.d.value, 'background-color', rtl) || col.d.value) : '';
        const vars = varsIn(col ? col.d.value : '').map(name => ({ name, s: this.st.source(n, name) })).filter(x => x.s.winner);
        const disp = cands('display')[0];
        return { ps, info, rule, col, color, vars, hidden: !!(disp && /^none$/i.test(disp.d.value.trim())), disp, computed: pc };
      }
      return null;
    }
    section(n) {
      const D = this.D, st = this.st, info = D.info, e = D.canvas.elOf(n);
      if (!e || /^(img|video|audio|br|hr|input|svg)$/.test(n.tag)) return null;
      const sec = hh('section', { class: 'sg-sec sg-bg', 'data-sec': 'bg' }, hh('h3', { class: 'dt-h' }, 'רקע'));
      const im = this.image(n, info), urlsHere = im.layers.filter(L => L.kind === 'url'), w = this.canWrite();
      if (this.after && this.after.n === n) sec.append(this.afterBox(n));
      if (urlsHere.length) {
        for (const L of urlsHere) sec.append(this.imageRow(n, im, L));
      } else if (im.computed && im.computed !== 'none' && /url\(/i.test(im.computed)) {
        sec.append(hh('p', { class: 'hint' }, 'יש כאן תמונת רקע, אבל העורך אינו מוצא איפה היא כתובה (ייתכן שמקובץ חיצוני). '), hh('code', { dir: 'ltr' }, cssUrlOf(im.computed)));
      } else {
        sec.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'hint' }, 'אין תמונת רקע.'), hh('button', { type: 'button', class: 'btn sm', 'data-bg-add': true, disabled: !w || null, onclick: () => this.replace(n) }, '＋ תמונת רקע')));
      }
      const others = im.layers.filter(L => L.kind !== 'url');
      if (im.layers.length > 1 || others.some(L => L.kind !== 'none')) sec.append(this.layersBox(n, im));
      const ov = this.overlay(n);
      sec.append(ov ? this.overlayBox(n, ov) : hh('div', { class: 'sg-line' }, hh('span', { class: 'hint' }, 'אין כיסוי (הכהיה).'), hh('button', { type: 'button', class: 'btn sm', 'data-bg-cover-add': true, disabled: !w || null, onclick: () => this.addCover(n) }, '＋ כיסוי')));
      if (urlsHere.length || (im.computed && im.computed !== 'none')) {
        sec.append(this.posGrid(n));
        sec.append(D.num('background-position', 'מיקום', { group: 'מיקום הרקע', units: [] }));
        const size = D.num('background-size', 'גודל', { group: 'גודל הרקע', units: [] });
        size.querySelector('.sg-line')?.after(hh('div', { class: 'sg-presets' }, ['cover', 'contain', 'auto'].map(v => hh('button', { type: 'button', class: 'btn sm', 'data-bg-size': v, disabled: !w || null, onclick: () => D.put([['background-size', v]], 'גודל הרקע · ' + D.canvas.label(n) + ' · ' + v) }, { cover: 'כיסוי (cover)', contain: 'הכלה (contain)', auto: 'מקורי (auto)' }[v]))));
        sec.append(size);
        sec.append(D.choice('background-repeat', 'חזרה', [['no-repeat', 'בלי חזרה'], ['repeat', 'חוזרת'], ['repeat-x', 'אופקית'], ['repeat-y', 'אנכית'], ['space', 'מרווחת (space)'], ['round', 'מעוגלת (round)']]));
        sec.append(D.choice('background-attachment', 'קיבוע', [['scroll', 'נגללת (scroll)'], ['fixed', 'קבועה במסך (fixed)'], ['local', 'עם התוכן (local)']]));
        sec.append(D.choice('background-origin', 'מוצא', [['padding-box', 'padding-box'], ['border-box', 'border-box'], ['content-box', 'content-box']]));
        sec.append(D.choice('background-clip', 'חיתוך', [['border-box', 'border-box'], ['padding-box', 'padding-box'], ['content-box', 'content-box'], ['text', 'הטקסט (text)']]));
        sec.append(D.choice('background-blend-mode', 'מיזוג', ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'soft-light', 'hard-light', 'difference', 'luminosity'].map(v => [v, v])));
      }
      sec.append(D.num('min-height', 'גובה מינימלי', { group: 'גובה מינימלי', units: ['svh', 'dvh'] }));
      return sec;
    }
    imageRow(n, im, L) {
      const D = this.D, w = this.canWrite(), box = hh('div', { class: 'sg-bgimg', 'data-bg-image': L.url });
      const shown = cssUrlOf(im.computed.split(/,(?![^(]*\))/)[L.i] || im.computed) || '', where = urlWhere(L.url, /maghreb\.co\.il/i), blocked = [...this.blocked().keys()].some(u => u === shown || u === L.url);
      const th = hh('span', { class: 'sg-bgthumb', 'aria-hidden': 'true' });
      if (shown && where === 'project') th.style.backgroundImage = 'url("' + shown.replace(/["\\\n]/g, '') + '")';
      box.append(hh('div', { class: 'sg-bghead' }, th, hh('div', {}, hh('b', { dir: 'ltr' }, fileName(L.url)), hh('p', { class: 'hint sg-bgwhat', 'data-bg-what': true }, '🖼 זו תמונת רקע של הרובריקה, לא אלמנט תמונה.'))));
      const src = hh('div', { class: 'sg-src', 'data-bg-src': true }, 'מ: ', at(D, L.loc.r, L.loc.d));
      if (L.via) src.append(hh('span', {}, ' · דרך ', hh('code', { dir: 'ltr' }, L.via.name), ' — '), at(D, L.via.w.r, L.via.w.d, L.via.name + ' ב: '));
      box.append(src);
      if (where !== 'project') box.append(hh('p', { class: 'hint', 'data-bg-where': where }, where === 'host' ? '🌐 באתר — טרם הובאה לספרייה (A2). ההחלפה בתמונה מהספרייה עובדת כבר עכשיו.' : '🌐 משרת אחר — לא מוצגת בעורך (מדיניות האבטחה), נראית ב"▶️ תצוגה חיה". הכתובת נערכת.'));
      else if (blocked) box.append(hh('p', { class: 'hint' }, 'לא מוצגת בעורך — נראית בתצוגה החיה.'));
      const kb = this.sizeOf(L.url); if (kb > 500) box.append(hh('p', { class: 'hint sg-heavy', 'data-bg-heavy': kb }, '⚖️ ' + kb + 'KB — תמונה כבדה; כדאי לדחוס (לא חוסם).'));
      const inp = hh('input', { type: 'text', dir: 'ltr', value: L.url, 'aria-label': 'כתובת התמונה', spellcheck: 'false', 'data-bg-url': true, disabled: !w || null });
      inp.addEventListener('change', () => { try { checkUrl(inp.value); this.setUrl(n, L, inp.value.trim()); } catch (x) { inp.classList.add('bad'); inp.title = x.message; } });
      box.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'כתובת'), hh('span', { class: 'sg-ctl wide' }, inp)));
      box.append(hh('div', { class: 'sg-bgacts' },
        hh('button', { type: 'button', class: 'btn sm', 'data-bg-replace': true, disabled: !w || null, onclick: () => this.replace(n, L) }, '🖼 החלף'),
        hh('button', { type: 'button', class: 'btn sm', 'data-bg-remove': true, disabled: !w || null, onclick: () => this.remove(n, L) }, '🗑 הסר'),
        hh('button', { type: 'button', class: 'btn sm', 'data-bg-move': true, disabled: !w || null, onclick: () => this.move(n) }, '✥ הזז')));
      return box;
    }
    /** רשת 3×3 של מיקום (עוגנים); המספרים — בשליטה שמתחתיה. */
    posGrid(n) {
      const D = this.D, cur = (this.st.computed(n, 'background-position') || '').split(',')[0].trim(), w = this.canWrite();
      const grid = hh('div', { class: 'sg-pos9', role: 'group', 'aria-label': 'מיקום הרקע — עוגן', 'data-bg-pos9': true });
      for (const y of ['top', 'center', 'bottom']) for (const x of ['left', 'center', 'right']) {
        const v = x === 'center' && y === 'center' ? 'center' : x + ' ' + y, pc = { left: '0%', center: '50%', right: '100%', top: '0%', bottom: '100%' }, on = cur === pc[x] + ' ' + pc[y];
        grid.append(hh('button', { type: 'button', 'aria-pressed': String(on), 'aria-label': v, title: v, 'data-pos': v, disabled: !w || null, onclick: () => D.put([['background-position', v]], 'מיקום הרקע · ' + D.canvas.label(n) + ' · ' + v) }, on ? '●' : '·'));
      }
      return hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'עוגן'), grid);
    }
    sizeOf(u) { try { const path = this.pathOf(u); const f = path && this.files().find(x => x.path === path); const b = f && (f.size || f.bytes || (f.media_info && f.media_info.size)); return b ? Math.round(b / 1024) : 0; } catch { return 0; } }
    pathOf(u, base) { if (/^([a-z]+:|\/\/)/i.test(u)) return null; const dir = String(base || '').split('/').slice(0, -1); for (const p of decodeURIComponent(u).split('/')) { if (p === '..') dir.pop(); else if (p && p !== '.') dir.push(p); } return dir.join('/'); }
    layersBox(n, im) {
      const D = this.D, w = this.canWrite() && !im.short, box = hh('div', { class: 'sg-layers', 'data-bg-layers': im.layers.length });
      box.append(hh('h4', { class: 'sg-h4' }, 'שכבות — מלמעלה למטה' + (im.short ? ' (כתובות בקיצור background — עורכים כל שכבה בנפרד בקוד; העורך אינו מפרק קיצור)' : '')));
      const name = { url: '🖼 תמונה', gradient: '🌈 מעבר צבע', none: '∅ ריקה', var: '🔣 משתנה', custom: '✎ ערך מותאם' };
      im.layers.forEach((L, i) => {
        const row = hh('div', { class: 'sg-layer', 'data-layer': L.kind });
        const inp = hh('input', { type: 'text', dir: 'ltr', value: L.text, 'aria-label': 'שכבה ' + (i + 1), spellcheck: 'false', disabled: !w || null });
        inp.addEventListener('change', () => this.setLayer(n, im, i, inp));
        const mv = (d, t) => hh('button', { type: 'button', class: 'sg-step', 'aria-label': t, disabled: !w || i + d < 0 || i + d >= im.layers.length || null, onclick: () => { const o = im.layers.map((_, k) => k); [o[i], o[i + d]] = [o[i + d], o[i]]; this.reorder(n, im, o); } }, d < 0 ? '↑' : '↓');
        row.append(hh('span', { class: 'sg-label' }, name[L.kind]), hh('span', { class: 'sg-ctl wide' }, inp), mv(-1, 'שכבה למעלה'), mv(1, 'שכבה למטה'),
          hh('button', { type: 'button', class: 'sg-reset', 'aria-label': 'מחיקת השכבה', disabled: !this.canWrite() || null, onclick: () => this.remove(n, L, true) }, '🗑'));
        box.append(row);
      });
      box.append(hh('p', { class: 'hint' }, 'צבע הרקע — "צבע רקע" למעלה (השכבה התחתונה). ערך שהעורך אינו מפרק נשמר כמו שהוא.'));
      return box;
    }
    overlayBox(n, ov) {
      const D = this.D, w = this.canWrite(), box = hh('div', { class: 'sg-cover', 'data-bg-cover': ov.ps });
      box.append(hh('h4', { class: 'sg-h4' }, 'כיסוי (הכהיה) · ::' + ov.ps));
      if (ov.rule) box.append(hh('div', { class: 'sg-src' }, 'מ: ', at(D, ov.rule, ov.col ? ov.col.d : null)));
      const inp = hh('input', { type: 'text', dir: 'ltr', value: ov.color || ov.computed.backgroundColor, 'aria-label': 'צבע הכיסוי', spellcheck: 'false', 'data-cover-color': true, disabled: !w || !ov.rule || null });
      inp.addEventListener('change', () => { const v = inp.value.trim(); if (!g.KolDesign.valid('background-color', v)) { inp.classList.add('bad'); return; } this.writePseudo(n, ov, [['background-color', v]], 'כיסוי · צבע · ' + v); });
      box.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'צבע'), hh('span', { class: 'sg-ctl wide' }, inp)));
      for (const v of ov.vars) {
        const wv = v.s.winner, cur = String(wv.d.value).trim(), num = parseFloat(cur), big = num > 1;
        const rng = hh('input', { type: 'range', min: 0, max: 100, value: Math.round(Math.min(1, big ? num / 100 : num) * 100), 'aria-label': 'שקיפות הכיסוי · ' + v.name, 'data-cover-var': v.name, disabled: !w || null });
        const out = hh('output', { dir: 'ltr' }, cur);
        const val = () => { const x = +rng.value / 100; return String(Math.round(x * 100) / 100).replace(/^0\./, '.'); };
        rng.addEventListener('input', () => { out.textContent = val(); try { this.D.canvas.elOf(n).style.setProperty(v.name, val()); } catch { /* */ } });
        rng.addEventListener('change', () => { try { this.D.canvas.elOf(n).style.removeProperty(v.name); } catch { /* */ } this.D.write(new Map([[n, [[v.name, val(), ruleT(wv.r, this.st)]]]]), 'כיסוי · ' + v.name + ' · ' + cur + ' ← ' + val()); });
        box.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'עוצמה'), hh('span', { class: 'sg-ctl wide' }, rng, out)), hh('div', { class: 'sg-src' }, v.name + ' מ: ', at(D, wv.r, wv.d)));
        if (big) box.append(hh('p', { class: 'hint', 'data-cover-big': true }, '⚠️ הערך ' + cur + ' גדול מ-1 — בתוך rgba() הוא נחתך ל-1 (כיסוי מלא). המחוון כותב בין 0 ל-1.'));
      }
      const cls = this.coverClass(n, ov);
      box.append(hh('div', { class: 'sg-bgacts' },
        hh('button', { type: 'button', class: 'btn sm', 'data-cover-hide': true, 'aria-pressed': String(ov.hidden), disabled: !w || !ov.rule || null, onclick: () => this.writePseudo(n, ov, [['display', ov.hidden ? null : 'none']], 'כיסוי · ' + (ov.hidden ? 'הצגה' : 'הסתרה')) }, ov.hidden ? '👁 הצג' : '🙈 הסתר'),
        hh('button', { type: 'button', class: 'btn sm', 'data-cover-del': true, disabled: !w || !ov.rule || null, onclick: () => this.delCover(n, ov) }, '🗑 מחק את הכלל' + (ov.rule ? ' (' + this.st.count(ov.rule.sel.replace(/::?(before|after)/gi, '')) + ' אלמנטים)' : '')),
        cls ? hh('button', { type: 'button', class: 'btn sm', 'data-cover-class': cls.name, disabled: !w || null, onclick: () => this.dropClass(n, cls.name) }, '✂ הסר את המחלקה .' + cls.name + ' (מהאלמנט הזה)') : null));
      return box;
    }
    coverClass(n, ov) {
      if (!ov.rule) return null;
      const mine = K().classesOf(n), base = ov.rule.sel.replace(/::?(before|after)/gi, ''), hit = mine.find(c => new RegExp('\\.' + c.replace(/[^\w-]/g, '\\$&') + '(?![\\w-])').test(base) && !this.D.styler.info(n).rules.some(r => !r.inline && new RegExp('\\.' + c.replace(/[^\w-]/g, '\\$&') + '(?![\\w-])').test(r.sel) && r.decls.some(d => !/^(background-image|--)/.test(lc(d.prop)))));
      return hit ? { name: hit } : null;
    }
    afterBox(n) {
      const a = this.after, box = hh('div', { class: 'sg-after', role: 'status', 'data-bg-after': true }, hh('b', {}, 'התמונה הוסרה. מה שנשאר:'));
      if (a.cover) box.append(hh('div', { class: 'sg-line', 'data-after-cover': true }, hh('span', {}, 'נשאר כיסוי כהה (::' + a.cover + ')'), hh('button', { type: 'button', class: 'btn sm', onclick: () => { this.after = null; const ov = this.overlay(n); if (ov) this.delCover(n, ov); } }, 'הסר גם אותו'), hh('button', { type: 'button', class: 'btn sm', onclick: () => { this.after.cover = null; this.D.draw(); } }, 'השאר')));
      if (a.color) box.append(hh('div', { class: 'sg-line', 'data-after-color': true }, hh('span', {}, 'נשאר צבע הרקע ', hh('code', { dir: 'ltr' }, a.color)), hh('button', { type: 'button', class: 'btn sm', onclick: () => { this.after = null; this.D.draw(); this.D.host.querySelector('[data-color="background-color"]')?.click(); } }, 'שנה')));
      if (a.still) box.append(hh('p', { class: 'hint', 'data-after-still': true }, '⚠️ עדיין נראית תמונה — מכלל אחר, שנחשף: ' + a.still + '. היא מוצגת למטה, עם המקור שלה.'));
      box.append(hh('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => { this.after = null; this.D.draw(); } }, '✕'));
      return box;
    }

    // ── פעולות ───────────────────────────────────────────
    label(n) { return this.D.canvas.label(n); }
    /** 🗑 הסר: מהכלל שבו ה-url() כתוב. דרך var() — השכבה מוסרת מההצהרה שמשתמשת במשתנה. */
    remove(n, L, layerOnly) {
      const D = this.D, im = this.image(n, D.styler.info(n)), w = im.w; if (!w) return;
      const rtl = this.st.rtl(n), drop = layerOnly || im.layers.length > 1 ? [L.i] : null;
      const r = this.write([() => this.ruleOps(w.r, (m, rn) => planRemoveImage(m, rn, rtl, drop))], (layerOnly && L.kind !== 'url' ? 'רקע · מחיקת שכבה · ' : 'רקע · הסרת התמונה · ') + this.label(n));
      if (!r || !r.length) return;
      if (L.kind !== 'url') return;
      const e = D.canvas.elOf(n), cs = e && this.win().getComputedStyle(e), ov = this.overlay(n), info = D.styler.info(n), col = D.styler.source(n, 'background-color', info);
      const still = cs && /url\(/i.test(cs.backgroundImage) ? cssUrlOf(cs.backgroundImage) : '';
      this.after = { n, cover: ov && !ov.hidden ? ov.ps : null, color: col.winner ? col.declared : (cs && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' ? cs.backgroundColor : ''), still };
      D.draw(); this.note('התמונה הוסרה · ' + this.label(n), false, { label: 'בטל', run: this.undo });
    }
    /** 🖼 החלף / ＋ תמונת רקע: מהספרייה (בחירה / העלאה). */
    async replace(n, L) { const p = await this.pick('image', L ? 'החלפת תמונת הרקע' : 'תמונת רקע'); if (p) this.usePath(n, p, L); }
    usePath(n, path, L) {
      const D = this.D;
      if (!L) { const im = this.image(n, D.styler.info(n)); L = im.layers.find(x => x.kind === 'url') || null; }
      if (L) { const base = L.loc.r.src.html ? this.filePath() : L.loc.r.path; return this.setUrl(n, L, relFrom(base, path)); }
      // אין תמונה: לכלל היעד — וגם כיסוי / מיקום / חזרה, אם אין ערך מחושב אחר
      this.ready(n);
      const t = D.effTarget(n, 'background-image'), base = t.kind === 'inline' ? this.filePath() : t.path || this.filePath(), doc = t.kind === 'inline' ? null : this.docOf(t.path);
      const q = doc ? quoteOf(doc.text()) : "'", e = D.canvas.elOf(n), cs = this.win().getComputedStyle(e);
      const sets = [['background-image', urlCss(relFrom(base, path), t.kind === 'inline' ? "'" : q)]];
      if (cs.backgroundSize === 'auto' || cs.backgroundSize === 'auto auto') sets.push(['background-size', 'cover']);
      if (/^0% 0%$/.test(cs.backgroundPosition)) sets.push(['background-position', 'center']);
      if (cs.backgroundRepeat === 'repeat') sets.push(['background-repeat', 'no-repeat']);
      D.put(sets, 'רקע · תמונה חדשה · ' + this.label(n) + ' · ' + path.split('/').pop());
      this.note('נוספה תמונת רקע', false, { label: 'בטל', run: this.undo });
    }
    setUrl(n, L, url) {
      const loc = L.loc, d0 = loc.d, idx = loc.r.node ? loc.r.node.decls.indexOf(d0.d || d0) : -1;
      if (idx < 0) return this.note('ההצהרה אינה נמצאת בקובץ — פתחו בקוד', true);
      const r = this.write([() => this.ruleOps(loc.r, (m, rn) => planReplaceUrl(rn.decls[idx], url, loc.layer))], 'רקע · החלפת התמונה · ' + this.label(n) + ' · ' + fileName(url));
      if (r && r.length) this.note('התמונה הוחלפה' + (L.via ? ' (בהגדרת ' + L.via.name + ')' : ''), false, { label: 'בטל', run: this.undo });
    }
    setLayer(n, im, i, inp) {
      const v = inp.value.trim(); if (!v || v.includes('<') || /[;{}]/.test(v.replace(/(["'])(?:\\.|(?!\1).)*\1/g, '')) || splitComma(v).length !== 1) { inp.classList.add('bad'); inp.title = 'שכבה אחת, בלי ; { } <'; return; }
      if (urls(v).length) { try { for (const u of urls(v)) checkUrl(u.url); } catch (x) { inp.classList.add('bad'); inp.title = x.message; return; } }
      const L = splitComma(im.s.declared); L[i] = v; const all = L.join(', ');
      if (typeof CSS !== 'undefined' && !CSS.supports('background-image', all)) { inp.classList.add('bad'); inp.title = 'ערך לא חוקי'; return; }
      const idx = im.w.r.node.decls.indexOf(im.w.d.d || im.w.d);
      this.write([() => this.ruleOps(im.w.r, (m, rn) => { const d = rn.decls[idx]; return { patches: [{ from: d.valFrom, to: d.valTo, text: all }] }; })], 'רקע · שכבה ' + (i + 1) + ' · ' + this.label(n));
    }
    reorder(n, im, order) { const rtl = this.st.rtl(n); this.write([() => this.ruleOps(im.w.r, (m, rn) => planReorder(m, rn, rtl, order))], 'רקע · סדר השכבות · ' + this.label(n)); }
    addCover(n) {
      const D = this.D, rtl = this.st.rtl(n), info = D.styler.info(n), im = this.image(n, info), layer = 'linear-gradient(rgba(0, 0, 0, .45), rgba(0, 0, 0, .45))';
      if (im.w && im.w.r.node && !im.w.r.external && !im.w.r.inline) return this.write([() => this.ruleOps(im.w.r, (m, rn) => planAddLayer(m, rn, rtl, layer))], 'רקע · כיסוי חדש · ' + this.label(n));
      this.ready(n); D.put([['background-image', layer]], 'רקע · כיסוי חדש · ' + this.label(n));
    }
    writePseudo(n, ov, sets, label) { if (!ov.rule) return; return this.D.write(new Map([[n, sets.map(([p, v]) => [p, v, ruleT(ov.rule, this.st)])]]), label + ' · ' + this.label(n)); }
    delCover(n, ov) { const r = this.write([() => this.ruleOps(ov.rule, (m, rn) => K().css.removeNode(m, rn))], 'כיסוי · מחיקת הכלל ' + ov.rule.sel.replace(/\s+/g, ' ')); if (r && r.length) this.note('הכיסוי נמחק (הכלל ' + ov.rule.sel.replace(/\s+/g, ' ') + ')', false, { label: 'בטל', run: this.undo }); }
    dropClass(n, cls) {
      const c = this.D.canvas, p = K().pathOf(n);
      this.write([() => { const cur = K().byPath(c.m, p); return [{ doc: this.html(), html: true, patches: K().html.removeClass(c.m, cur, cls).patches }]; }], 'הסרת המחלקה .' + cls + ' · ' + this.label(n));
    }
    write(list, label) { this.D.unpreview?.(); const r = this.writeOps(list, label); this.D.draw(); return r; }
    /** קובץ שנגרר על רובריקה עם תמונת רקע: כרקע או כאלמנט? (ברירת המחדל: מה שהיה שם — רקע). */
    ask(n) {
      return new Promise(done => {
        const dlg = document.getElementById('sheet'); let ok = false;
        const fin = v => { if (ok) return; ok = true; dlg.removeEventListener('close', cl); if (dlg.open) dlg.close(); done(v); }, cl = () => fin(null);
        this.sheet('איך להוסיף את התמונה?', hh('p', {}, 'לרובריקה ' + this.label(n) + ' כבר יש תמונת רקע.'),
          hh('div', { class: 'sg-bgacts' }, hh('button', { type: 'button', class: 'btn primary', 'data-ask': 'bg', autofocus: true, onclick: () => fin('bg') }, '🖼 כרקע שלה (מחליף)'), hh('button', { type: 'button', class: 'btn', 'data-ask': 'img', onclick: () => fin('img') }, '＋ כאלמנט תמונה בתוך הרובריקה')));
        dlg.addEventListener('close', cl);
      });
    }
    hasImage(n) { const e = this.D.canvas.elOf(n); if (!e || n.tag === 'img') return false; try { return /url\(/i.test(this.win().getComputedStyle(e).backgroundImage); } catch { return false; } }

    // ── ✥ הזזה על הקנבס ──────────────────────────────────
    /** גרירה בתוך הרובריקה ← background-position באחוזים; גלגלת / צביטה / −+ ← background-size (רוחב באחוזים). */
    move(n) {
      this.stop();
      const D = this.D, c = D.canvas, e = c.elOf(n); if (!e) return;
      const cs = this.win().getComputedStyle(e), pos = cs.backgroundPosition.split(',')[0].trim().split(/\s+/), r0 = e.getBoundingClientRect();
      const pct = (v, total) => (/%$/.test(v) ? parseFloat(v) : /px$/.test(v) && total ? (parseFloat(v) / total) * 100 : v === 'center' ? 50 : v === 'right' || v === 'bottom' ? 100 : 0);
      let px = pct(pos[0] || '50%', 0), py = pct(pos[1] || pos[0] || '50%', 0), sz = null, moved = false;
      const sz0 = cs.backgroundSize.split(',')[0].trim();
      if (/^[\d.]+%( auto)?$/.test(sz0)) sz = parseFloat(sz0);
      const img = new Image(); const u = cssUrlOf(cs.backgroundImage);
      const initSize = () => { if (sz != null) return; const iw = img.naturalWidth, ih = img.naturalHeight; if (iw && ih) { const W = r0.width, H = r0.height, k = sz0 === 'contain' ? Math.min(W / iw, H / ih) : sz0 === 'cover' ? Math.max(W / iw, H / ih) : 1; sz = Math.round(iw * k / W * 100); } else sz = 100; };
      if (u) { img.src = u; img.onload = () => initSize(); }
      const ov = hh('div', { class: 'bg-move', 'data-bg-moving': true, role: 'application', 'aria-label': 'הזזת תמונת הרקע — גרירה מזיזה, גלגלת מגדילה, Esc יוצא', tabindex: '0' });
      const num = hh('span', { class: 'bg-move-num', dir: 'ltr' });
      const show = () => { num.textContent = Math.round(px) + '% ' + Math.round(py) + '%' + (sz != null ? ' · ' + Math.round(sz) + '%' : ''); };
      const pv = () => { const sets = [['background-position', Math.round(px) + '% ' + Math.round(py) + '%']]; if (sz != null && this.sized) sets.push(['background-size', Math.round(sz) + '% auto']); D.preview([n], sets); show(); moved = true; };
      const commit = () => {
        if (!moved) return; moved = false;
        const sets = [['background-position', Math.round(px) + '% ' + Math.round(py) + '%']]; if (this.sized && sz != null) sets.push(['background-size', Math.round(sz) + '% auto']);
        this.ready(n); D.put(sets, 'רקע · הזזה · ' + this.label(n) + ' · ' + sets.map(x => x[1]).join(' / '));
        if (this.mv) this.place();
      };
      const nudge = (dx, dy) => { px = Math.max(0, Math.min(100, px + dx)); py = Math.max(0, Math.min(100, py + dy)); pv(); clearTimeout(this.t); this.t = setTimeout(commit, 500); };
      const zoom = k => { initSize(); this.sized = true; sz = Math.max(5, Math.min(1000, sz * k)); pv(); clearTimeout(this.t); this.t = setTimeout(commit, 500); };
      const ptrs = new Map(); let start = null;
      ov.addEventListener('pointerdown', ev => {
        if (ev.target.closest('button')) return;
        ev.preventDefault(); ov.setPointerCapture(ev.pointerId); ptrs.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
        const pts = [...ptrs.values()];
        start = pts.length === 2 ? { pinch: Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y), sz: (initSize(), sz) } : { x: ev.clientX, y: ev.clientY, px, py };
      });
      ov.addEventListener('pointermove', ev => {
        if (!ptrs.has(ev.pointerId) || !start) return; ptrs.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
        const pts = [...ptrs.values()], R = ov.getBoundingClientRect();
        if (start.pinch && pts.length === 2) { this.sized = true; sz = Math.max(5, Math.min(1000, start.sz * Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y) / (start.pinch || 1))); pv(); return; }
        if (start.pinch) return;
        px = Math.max(0, Math.min(100, start.px - (ev.clientX - start.x) / (R.width || 1) * 100)); py = Math.max(0, Math.min(100, start.py - (ev.clientY - start.y) / (R.height || 1) * 100)); pv();
      });
      const up = ev => { ptrs.delete(ev.pointerId); if (!ptrs.size) { start = null; commit(); } else start = null; };
      ov.addEventListener('pointerup', up); ov.addEventListener('pointercancel', up);
      ov.addEventListener('wheel', ev => { ev.preventDefault(); zoom(ev.deltaY < 0 ? 1.05 : 1 / 1.05); }, { passive: false });
      ov.addEventListener('keydown', ev => {
        const k = ev.shiftKey ? 10 : 1;
        if (ev.key === 'Escape') { ev.preventDefault(); this.stop(); }
        else if (ev.key === 'ArrowLeft') { ev.preventDefault(); nudge(k, 0); } else if (ev.key === 'ArrowRight') { ev.preventDefault(); nudge(-k, 0); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); nudge(0, k); } else if (ev.key === 'ArrowDown') { ev.preventDefault(); nudge(0, -k); }
        else if (ev.key === '+' || ev.key === '=') { ev.preventDefault(); zoom(1.05); } else if (ev.key === '-') { ev.preventDefault(); zoom(1 / 1.05); }
      });
      const b = (t, lab, fn, data) => hh('button', { type: 'button', class: 'bg-move-b', 'aria-label': lab, title: lab, 'data-mv': data, onclick: fn }, t);
      ov.append(hh('div', { class: 'bg-move-bar', role: 'toolbar', 'aria-label': 'הזזת תמונת הרקע' },
        b('←', 'הזזה שמאלה', () => nudge(5, 0), 'left'), b('↑', 'הזזה למעלה', () => nudge(0, 5), 'up'), b('↓', 'הזזה למטה', () => nudge(0, -5), 'down'), b('→', 'הזזה ימינה', () => nudge(-5, 0), 'right'),
        b('−', 'הקטנה', () => zoom(1 / 1.1), 'out'), b('+', 'הגדלה', () => zoom(1.1), 'in'), num, b('✓ סיום', 'סיום (Esc)', () => this.stop(), 'done')));
      this.sized = /%/.test(sz0); this.mv = { n, ov, e };
      this.layer().append(ov); this.place(); show(); ov.focus();
      this.note('✥ גוררים את התמונה בתוך הרובריקה · גלגלת / צביטה / −+ — גודל · Esc — סיום');
    }
    place() { const m = this.mv; if (!m) return; const r = this.D.canvas.rectOf(m.n); if (!r) return this.stop(); m.ov.style.cssText = 'left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px'; }
    stop() { clearTimeout(this.t); if (!this.mv) return; this.mv.ov.remove(); this.mv = null; }
  }
  const KolStyle_ = () => g.KolStyle;

  g.KolBg = { Bg, splitComma, tokens, parseLayer, parseBg, bgComponent, BG_COVERED, urls, isUrlLayer, checkUrl, urlCss, quoteOf, relFrom, urlWhere, delDecl, planRemoveImage, planReplaceUrl, planReorder, planAddLayer, varsIn, PARALLEL };
})(typeof window !== 'undefined' ? window : globalThis);
