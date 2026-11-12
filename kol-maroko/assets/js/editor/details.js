/**
 * העורך החזותי — פאנל "פרטים" (V1: לקריאה בלבד; הבסיס של הפאנל שייערך ב-V3).
 *
 * לאלמנט הנבחר: התגית, המזהה והמחלקות; הקובץ והשורות; וכל הצהרות ה-CSS שחלות עליו — מאיזה קובץ, כלל ושורה, ומה נדרס
 * (03 §4.1 "מקור"). לחיצה על הצהרה — "פתח בקוד", על השורה שלה.
 *
 * מי חל — שואלים את הדפדפן (ה-CSSOM של הקנבס + element.matches); איפה זה כתוב — מהמודל של הקובץ (KolSrc.parseCss על
 * הטקסט שבפרויקט, לא על מה שהוזרק לקנבס). כל שם, בורר וערך מהעמוד נכתבים כטקסט בלבד — אין בקובץ הזה innerHTML.
 */
(function () {
  'use strict';
  const OWNED = 'data-kol-canvas';
  const mk = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const norm = s => String(s).toLowerCase().replace(/\s+/g, '').replace(/'/g, '"').replace(/::/g, ':');
  /** מפצל רשימת בוררים בפסיקים של הרמה העליונה. */
  function splitSel(sel) {
    const out = []; let depth = 0, q = '', cur = '';
    for (const ch of sel) {
      if (q) { if (ch === q) q = ''; cur += ch; continue; }
      if (ch === '"' || ch === "'") { q = ch; cur += ch; continue; }
      if (ch === '(' || ch === '[') depth++; else if (ch === ')' || ch === ']') depth--;
      if (ch === ',' && depth === 0) { out.push(cur.trim()); cur = ''; } else cur += ch;
    }
    if (cur.trim()) out.push(cur.trim());
    return out;
  }
  /** ייחודיות של בורר אחד: [מזהים, מחלקות / תכונות / פסאודו-מחלקות, תגיות / פסאודו-אלמנטים] — לפי Selectors 4, לא בקירוב (V8):
   *  ‎:where() — אפס; ‎:is() / :not() / :has() — הבורר החזק ברשימה שבתוכם; ‎:nth-child(An+B of S) — פסאודו-מחלקה + S;
   *  ‎::before ו-‎:before (הצורה הישנה) — פסאודו-אלמנט; ‎* ו-& — אפס. */
  const PE_LEGACY = /^(before|after|first-line|first-letter)$/;
  const maxSpec = list => splitSel(list).map(specificity).reduce((m, x) => (cmp(x, m) > 0 ? x : m), [0, 0, 0]);
  function specificity(sel) {
    const s = String(sel); let a = 0, b = 0, c = 0, i = 0;
    const ident = () => { const st = i; while (i < s.length && (/[\w-]/.test(s[i]) || s.charCodeAt(i) > 127 || s[i] === '\\')) i += s[i] === '\\' ? 2 : 1; return s.slice(st, i); };
    const paren = () => { if (s[i] !== '(') return null; let d = 0, q = '', st = i + 1; for (; i < s.length; i++) { const ch = s[i]; if (q) { if (ch === '\\') i++; else if (ch === q) q = ''; continue; } if (ch === '"' || ch === "'") q = ch; else if (ch === '(') d++; else if (ch === ')') { d--; if (!d) { i++; return s.slice(st, i - 1); } } } return s.slice(st); };
    while (i < s.length) {
      const ch = s[i];
      if (ch === '"' || ch === "'") { const q = ch; i++; while (i < s.length && s[i] !== q) i += s[i] === '\\' ? 2 : 1; i++; continue; }
      if (ch === '#') { i++; ident(); a++; continue; }
      if (ch === '.') { i++; ident(); b++; continue; }
      if (ch === '[') { let q = ''; for (; i < s.length; i++) { if (q) { if (s[i] === q) q = ''; continue; } if (s[i] === '"' || s[i] === "'") q = s[i]; else if (s[i] === ']') { i++; break; } } b++; continue; }
      if (ch === ':') {
        if (s[i + 1] === ':') { i += 2; ident(); paren(); c++; continue; }
        i++; const name = ident().toLowerCase(), arg = paren();
        if (PE_LEGACY.test(name)) { c++; continue; }
        if (name === 'where') continue;
        if (/^(is|not|has|matches|-webkit-any|-moz-any)$/.test(name)) { const m = maxSpec(arg || ''); a += m[0]; b += m[1]; c += m[2]; continue; }
        b++;
        if (/^nth-(last-)?child$/.test(name) && arg) { const of = /\sof\s+([\s\S]+)$/i.exec(arg); if (of) { const m = maxSpec(of[1]); a += m[0]; b += m[1]; c += m[2]; } }
        continue;
      }
      if (/[a-zA-Z_]/.test(ch) || ch.charCodeAt(0) > 127 || (ch === '-' && /[a-zA-Z_-]/.test(s[i + 1] || ''))) { ident(); c++; continue; }
      if (ch === '\\') { i += 2; ident(); c++; continue; }
      i++;
    }
    return [a, b, c];
  }
  const covers = (short, long) => short === long || (!short.startsWith('--') && long.startsWith(short + '-'));

  // ── V8: המפל המלא — @layer, @scope, מצב ─────────────────────────────
  const STATE_ATTR = 'data-kol-st', UNLAYERED = 999999999, INF_PROX = 1e9;
  /** מצבים שהקנבס מדמה (תכונה במקום פסאודו-מחלקה); מצב מבני (:nth-child, :has, :first-child…) חל ממילא ואינו מדומה. */
  const DYNAMIC = /^:(hover|focus|focus-visible|focus-within|active|visited|link|any-link|target|checked|disabled|enabled|invalid|valid|in-range|out-of-range|placeholder-shown|open|indeterminate|required|optional|read-only|read-write|user-invalid|user-valid|autofill|popover-open|default|playing|paused)$/i;
  const stateKey = st => String(st).replace(/^:+/, '').toLowerCase();
  const stateRe = st => (DYNAMIC.test(st) ? new RegExp(String(st).replace(/[^\w-]/g, '\\$&') + '(?![\\w(-])', 'gi') : null);
  /** מפתח מספרי של שכבה (גבוה = מנצח בהצהרה רגילה): 3 רמות, כל רמה — המקום בין האחיות (999 = כללים ישירות בהורה). */
  function layerKey(lay, path) { let k = 0; for (let d = 0; d < 3; d++) { let idx = 999; if (d < path.length) { let node = lay.root; for (let j = 0; j <= d && node; j++) node = node.kids.get(path[j]); idx = node ? node.idx : 999; } k = k * 1000 + idx; } return k; }
  /** עץ השכבות של המסמך, לפי ההופעה הראשונה של כל שם (בלוק או הצהרה), בסדר הגיליונות. */
  function layerTree(doc) {
    const root = { kids: new Map() }, anon = new WeakMap(); let an = 0;
    const reg = path => { let node = root; for (const nm of path) { if (!node.kids.has(nm)) node.kids.set(nm, { idx: node.kids.size, kids: new Map() }); node = node.kids.get(nm); } };
    const go = (list, path) => { for (const r of list) { const kind = r.constructor && r.constructor.name || ''; if (/LayerStatement/.test(kind)) { for (const nm of r.nameList || []) reg(path.concat(nm.split('.'))); continue; } if (/LayerBlock/.test(kind)) { let nm = r.name; if (!nm) { nm = '#anon' + (an++); anon.set(r, nm); } const p = path.concat(nm.split('.')); reg(p); go(r.cssRules, p); continue; } if (r.cssRules && !(r.selectorText != null && r.style)) go(r.cssRules, path); } };
    for (const sh of doc.styleSheets) { let rules; try { rules = sh.cssRules; } catch { continue; } if (!sh.disabled) go(rules, []); }
    return { root, anon };
  }
  /** @scope: הקרבה (מספר הדורות בין האלמנט לשורש הקרוב שחל), או null — לא חל. כמה @scope מקוננים — כולם צריכים לחול. */
  function scopeMatch(e, part, scopes) {
    let prox = null;
    for (const sc of scopes) {
      let found = null, hops = 0;
      for (let root = e; root && root.nodeType === 1; root = root.parentElement, hops++) {
        let isRoot = false; try { isRoot = sc.start ? root.matches(sc.start) : root === (sc.owner && sc.owner.parentElement); } catch { isRoot = false; }
        if (!isRoot) continue;
        if (sc.end) { let lim = false; for (let x = e; x && x !== root; x = x.parentElement) { try { if (x.matches(sc.end)) { lim = true; break; } } catch { /* */ } } if (lim) continue; }
        if (sc === scopes[scopes.length - 1]) { let hit = false; const p2 = part.replace(/&/g, ':scope'); try { hit = e === root ? root.matches(p2.replace(/:scope/g, '*')) && !/[\s>+~]/.test(p2.trim()) || /^:scope$/.test(p2.trim()) : [...root.querySelectorAll(p2)].includes(e); } catch { hit = false; } if (!hit) continue; }
        found = hops; break;
      }
      if (found == null) return null;
      prox = found;
    }
    return prox;
  }
  /** הדירוג במפל: חשיבות, style="", שכבה (הפוכה ב-!important), ייחודיות, קרבת @scope, סדר. */
  function rankOf(r, important) { const L = r.layer == null ? UNLAYERED : r.layer, P = r.prox == null ? INF_PROX : r.prox; return [important ? 1 : 0, r.inline ? 1 : 0, important ? -L : L, r.spec[0], r.spec[1], r.spec[2], -P, r.order]; }

  class Details {
    /** opt: { canvas, K, sheets(): Map<href, {path, doc}>, file(): {path}, openAt(path, line), openEl() } */
    constructor(host, opt) { Object.assign(this, opt); this.host = host; this.cache = new Map(); this.last = null; }
    /** מודל המקור של גיליון, ואינדקס הכללים שלו לפי (הקשר | בורר) — כדי להתאים כלל של הדפדפן לשורה בקובץ. */
    model(key, text) {
      const hit = this.cache.get(key);
      if (hit && hit.text === text) return hit;
      const m = this.K.parseCss(text), index = new Map();
      const go = (n, ctx) => { for (const k of n.kids) { if (k.type === 'rule') { const id = ctx + '|' + norm(k.sel); if (!index.has(id)) index.set(id, []); index.get(id).push(k); } else go(k, ctx + '/' + norm(k.name + k.sel)); } };
      go(m.root, '');
      let broken = null; for (const n of this.K.cssAll(m)) if (n.broken) { broken = n; break; }
      const rec = { text, m, index, brokenLine: broken ? this.K.lineOf(text, broken.from) : 0 };
      this.cache.set(key, rec);
      return rec;
    }
    /** כל הכללים שחלים על האלמנט (וגם כאלה שבתוך @media שאינו חל ברוחב הנוכחי — מסומנים). */
    /** opt.pseudo — 'before' / 'after': הכללים של הפסאודו-אלמנט (V5 — הכיסוי). */
    collect(n, opt) {
      const pseudo = opt && opt.pseudo ? opt.pseudo : '';
      const c = this.canvas, K = this.K, e = c.elOf(n), out = { rules: [], notes: [] };
      if (!e || !e.matches) return out;
      const win = c.frame.contentWindow, file = this.file(), sheets = this.sheets();
      let order = 0;
      const lineAt = (src, at) => (src.html ? K.lineOf(c.m.src, src.off + at) : K.lineOf(src.text, at));
      // V8: מצב (ריחוף / מיקוד / …) — הקנבס מסמן את האלמנט ב-data-kol-st, והחלק של הבורר נבדק עם התכונה במקום הפסאודו-מחלקה
      const st = opt && opt.state ? opt.state : null, stRe = st ? stateRe(st) : null;
      const stPart = part => (stRe ? part.replace(stRe, '[' + STATE_ATTR + '~="' + stateKey(st) + '"]') : part);
      // V8: @layer — סדר השכבות לפי ההופעה הראשונה (גם @layer a, b;), שכבות מקוננות בתוך ההורה
      const lay = layerTree(c.doc), anonOf = lay.anon;
      const walk = (list, ctx, src, rec, seen, media, off, lpath, scopes) => {
        for (const r of list) {
          if (r.type === 1 || (r.selectorText != null && r.style)) {
            if (r.selectorText.includes(STATE_ATTR)) continue;                       // V8: העותקים של מצב בקנבס — לא של הקובץ
            const id = ctx + '|' + norm(r.selectorText), i = seen.get(id) || 0; seen.set(id, i + 1);
            let best = null, text = '', prox = INF_PROX, stHit = false;
            for (const part of splitSel(r.selectorText)) {
              let base = part, hit = false;
              if (pseudo) { const pm = /::?(before|after)\s*$/i.exec(part); if (!pm || pm[1].toLowerCase() !== pseudo) continue; base = part.slice(0, pm.index).trim() || '*'; }   // V5: ::before / ::after של האלמנט
              const tb = stPart(base);
              let px = INF_PROX;
              if (scopes.length) { px = scopeMatch(e, tb, scopes); hit = px != null; if (!hit) px = INF_PROX; }
              else { try { hit = e.matches(tb); } catch { hit = false; } }
              if (hit) { const sp = specificity(part); if (!best || cmp(sp, best) > 0 || (!cmp(sp, best) && px < prox)) { best = sp; text = part; prox = px; stHit = tb !== base; } }
            }
            if (best) {
              const mr = rec && rec.index.get(id) ? rec.index.get(id)[i] : null, decls = [];
              if (mr) for (const d of mr.decls) decls.push({ prop: d.prop.trim(), value: d.value, important: d.important, line: lineAt(src, d.from), d });
              else for (let j = 0; j < r.style.length; j++) { const p = r.style[j]; decls.push({ prop: p, value: r.style.getPropertyValue(p), important: r.style.getPropertyPriority(p) === 'important', line: 0 }); }
              out.rules.push({ sel: mr ? mr.sel : r.selectorText, matched: text, spec: best, order: order++, media, off, path: src.path, external: !!src.external, line: mr ? lineAt(src, mr.from) : (src.html ? K.lineOf(c.m.src, src.off) : 1), decls, located: !!mr, node: mr, src, layer: layerKey(lay, lpath), layerName: lpath.join('.'), prox, scoped: scopes.length > 0, stateHit: stHit });
            }
            if (r.cssRules && r.cssRules.length) walk(r.cssRules, ctx, src, rec, seen, media, off, lpath, scopes);   // קינון CSS
          } else if (r.cssRules) {
            const kind = r.constructor && r.constructor.name || '';
            if (/LayerBlock/.test(kind)) { const nm = r.name || anonOf.get(r) || ''; walk(r.cssRules, ctx + '/' + norm('layer' + r.name), src, rec, seen, media + (media ? ' · ' : '') + '@layer ' + (r.name || '(בלי שם)'), off, lpath.concat(nm.split('.')), scopes); continue; }
            if (/Scope/.test(kind)) { walk(r.cssRules, ctx + '/' + norm('scope' + (r.start ? '(' + r.start + ')' : '') + (r.end ? 'to(' + r.end + ')' : '')), src, rec, seen, media + (media ? ' · ' : '') + '@scope ' + (r.start || '') + (r.end ? ' to ' + r.end : ''), off, lpath, scopes.concat([{ start: r.start || null, end: r.end || null, owner: src.owner }])); continue; }
            const cond = r.conditionText != null ? r.conditionText : (r.name || '');
            let on = true;
            if (/Media/.test(kind)) { try { on = win.matchMedia(cond).matches; } catch { on = true; } }
            if (/Supports/.test(kind)) { try { on = win.CSS.supports(cond); } catch { on = true; } }
            const at = /Media/.test(kind) ? 'media' : /Supports/.test(kind) ? 'supports' : /Container/.test(kind) ? 'container' : '';
            walk(r.cssRules, ctx + '/' + norm(at + cond), src, rec, seen, media + (media ? ' · ' : '') + '@' + at + ' ' + cond, off || !on, lpath, scopes);
          }
        }
      };
      for (const sh of c.doc.styleSheets) {
        const owner = sh.ownerNode; if (!owner || sh.disabled) continue;
        const own = owner.getAttribute(OWNED);
        let src;
        if (own != null) { const s = sheets.get(own); if (!s) continue; src = { path: s.path, text: s.doc.text() }; }
        else if (owner.localName === 'style') { const mn = c.d2m.get(owner); if (!mn || mn.implied) continue; const a = mn.st[1], b = mn.et ? mn.et[0] : mn.to; src = { path: file.path, text: c.m.src.slice(a, b), off: a, html: true }; }
        else src = { path: owner.getAttribute('href') || '', external: true };
        let rules; try { rules = sh.cssRules; } catch { out.notes.push({ text: 'קובץ עיצוב חיצוני שאינו בפרויקט, ואינו נקרא כאן: ' + src.path }); continue; }
        const rec = src.text != null ? this.model((src.html ? '#' + src.off + ':' : '') + src.path, src.text) : null;
        if (rec && rec.brokenLine) { const line = src.html ? K.lineOf(c.m.src, src.off) + rec.brokenLine - 1 : rec.brokenLine; out.notes.push({ text: 'שגיאה ב-CSS בשורה ' + line + ' של ' + src.path + ' — מה שאחריה עלול שלא להיקרא', path: src.path, line }); }
        src.owner = owner; walk(rules, '', src, rec, new Map(), '', false, [], []);
      }
      const sty = pseudo ? null : K.attrOf(n, 'style');
      if (sty && sty.value.trim()) {
        const line = sty.from >= 0 ? K.lineOf(c.m.src, sty.from) : 0, decls = [];
        try { const m = K.parseCss('x{' + sty.value + '}'), r = m.root.kids[0]; if (r) for (const d of r.decls) decls.push({ prop: d.prop.trim(), value: d.value, important: d.important, line }); } catch { /* סגנון שאינו נקרא — לא מוצג */ }
        out.rules.push({ sel: 'style="…"', inline: true, spec: [0, 0, 0], layer: UNLAYERED, prox: INF_PROX, order: order++, media: '', off: false, path: file.path, line, decls, located: line > 0, src: { inline: true } });
      }
      // מפל: לכל הצהרה — האם הצהרה חזקה ממנה (אותו מאפיין, או קיצור שמכסה אותו) דורסת אותה
      const rank = (r, d) => rankOf(r, d.important);
      const all = [];
      for (const r of out.rules) if (!r.off) for (const d of r.decls) all.push({ r, d, k: rank(r, d), p: d.prop.startsWith('--') ? d.prop : d.prop.toLowerCase() });
      for (const x of all) {
        let by = null;
        for (const y of all) if (y !== x && covers(y.p, x.p) && cmp(y.k, x.k) > 0 && (!by || cmp(y.k, by.k) > 0)) by = y;
        if (by) x.d.by = { sel: by.r.sel, path: by.r.path, line: by.d.line, important: by.d.important };
      }
      out.rules.sort((a, b) => (a.off ? 1 : 0) - (b.off ? 1 : 0) || cmp(rankOf(b, false), rankOf(a, false)));
      return out;
    }
    draw() {
      const c = this.canvas, K = this.K, n = c.selected, file = this.file(), kids = [];
      if (!n || !c.m || !file) { this.host.replaceChildren(mk('p', 'hint dt-empty', 'בוחרים אלמנט — בקנבס או ב-Navigator — ורואים כאן מה הוא, איפה הוא כתוב, ואיזה עיצוב חל עליו.')); this.last = null; return; }
      const row = (label, ...vals) => { const r = mk('div', 'dt-row'); r.append(mk('span', 'dt-k', label)); const v = mk('span', 'dt-v'); v.append(...vals); r.append(v); return r; };
      const code = t => { const e = mk('code', '', t); e.dir = 'ltr'; return e; };
      const id = K.attrOf(n, 'id'), cls = K.classesOf(n);
      kids.push(row('תגית', code('<' + n.tag + '>')));
      kids.push(row('מזהה', id && id.value ? code('#' + id.value) : mk('span', 'hint', 'אין')));
      const chips = mk('span', 'dt-chips'); for (const x of cls) chips.append(code('.' + x));
      kids.push(row('מחלקות', cls.length ? chips : mk('span', 'hint', 'אין')));
      let src = n; while (src && src.implied) src = src.parent;
      const where = mk('span', '');
      if (n.implied) where.append('לא במקור — הדפדפן הוסיף. ');
      if (src) { const a = K.lineOf(c.m.src, src.from), b = K.lineOf(c.m.src, K.endOf(c.m, src)); where.append(code(file.path), ' · ' + (a === b ? 'שורה ' + a : 'שורות ' + a + '–' + b) + (n.implied ? ' (ההורה הכתוב)' : '')); }
      const open = mk('button', 'btn sm dt-open', '</> פתח בקוד'); open.type = 'button'; open.addEventListener('click', () => this.openEl(n));
      kids.push(row('בקוד', where), open);
      const vis = c.visual(n);
      kids.push(row('בעמוד', { visible: 'נראה', shown: c.shownNote + ' (מוסתר בעמוד עצמו)', zero: 'בגודל אפס', nonvisual: 'אינו נראה בעמוד (ראש העמוד / סקריפט)', none: 'אינו מוצג' }[vis] || ''));
      const info = this.last = this.collect(n);
      for (const x of info.notes) { const p = mk('p', 'dt-note'); p.append(x.text); if (x.path) { const b = mk('button', 'btn sm', '</> פתח בקוד'); b.type = 'button'; b.addEventListener('click', () => this.openAt(x.path, x.line)); p.append(' ', b); } kids.push(p); }
      const total = info.rules.reduce((a, r) => a + r.decls.length, 0);
      kids.push(mk('h3', 'dt-h', 'העיצוב שחל · ' + total + ' הצהרות ב-' + info.rules.length + ' כללים'));
      if (!info.rules.length) kids.push(mk('p', 'hint', vis === 'nonvisual' ? 'לאלמנט הזה אין מראה.' : 'שום כלל בקובצי העיצוב אינו חל ישירות על האלמנט הזה (ערכים שעוברים בירושה מההורה אינם מוצגים כאן).'));
      for (const r of info.rules) {
        const box = mk('section', 'dt-rule' + (r.off ? ' off' : ''));
        const head = mk('button', 'dt-sel'); head.type = 'button'; head.dir = 'ltr';
        head.append(mk('b', '', r.sel), mk('span', 'dt-src', (r.external ? 'חיצוני: ' : '') + r.path + (r.line ? ':' + r.line : '')));
        head.title = 'פתח בקוד — ' + r.path + (r.line ? ', שורה ' + r.line : '');
        if (r.external) head.disabled = true; else head.addEventListener('click', () => this.openAt(r.path, r.line || 1));
        box.append(head);
        if (r.media) { const md = mk('div', 'dt-media', r.media + (r.off ? ' — אינו חל ברוחב הנוכחי' : '')); md.dir = 'auto'; box.append(md); }
        for (const d of r.decls) {
          const b = mk('button', 'dt-decl' + (d.by ? ' over' : '')); b.type = 'button'; b.dir = 'ltr';
          b.append(mk('span', 'dt-p', d.prop), ': ', mk('span', 'dt-val', d.value + (d.important ? ' !important' : '')));
          if (d.by) { const o = mk('span', 'dt-by', 'נדרס — ' + d.by.sel + (d.by.important ? ' !important' : '') + (d.by.line ? ' · ' + d.by.path + ':' + d.by.line : '')); o.dir = 'auto'; b.append(o); }
          b.title = 'פתח בקוד — ' + r.path + (d.line ? ', שורה ' + d.line : '');
          if (r.external) b.disabled = true; else b.addEventListener('click', () => this.openAt(r.path, d.line || r.line || 1));
          box.append(b);
        }
        kids.push(box);
      }
      this.host.replaceChildren(...kids);
    }
  }
  function cmp(a, b) { for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) return a[i] - b[i]; return 0; }
  window.KolDetails = { Details, specificity, splitSel, rankOf, stateKey, stateRe, STATE_ATTR, DYNAMIC, UNLAYERED, INF_PROX };
})();
