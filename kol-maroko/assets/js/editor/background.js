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

  g.KolBg = { splitComma, tokens, parseLayer, parseBg, bgComponent, BG_COVERED, urls, isUrlLayer, checkUrl, urlCss, quoteOf, relFrom, urlWhere, delDecl, planRemoveImage, planReplaceUrl, planReorder, planAddLayer, varsIn, PARALLEL };
})(typeof window !== 'undefined' ? window : globalThis);
