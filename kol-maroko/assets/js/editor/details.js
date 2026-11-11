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
  /** ייחודיות של בורר אחד: [מזהים, מחלקות / תכונות / פסאודו-מחלקות, תגיות / פסאודו-אלמנטים]. קירוב: ‎:is / :not נספרים לפי תוכנם, ‎:where — אפס. */
  function specificity(sel) {
    let s = sel.replace(/:where\((?:[^()]|\([^()]*\))*\)/g, '').replace(/"[^"]*"|'[^']*'/g, '');
    s = s.replace(/:(is|not|has|matches)\(/g, ' (');
    const a = (s.match(/#[\w-]+/g) || []).length;
    const b = (s.match(/\.[\w-]+|\[[^\]]*\]|(?<!:):(?!:)[\w-]+/g) || []).length;
    const c = (s.replace(/#[\w-]+|\.[\w-]+|\[[^\]]*\]|:+[\w-]+(\([^)]*\))?/g, ' ').match(/[a-zA-Z][\w-]*/g) || []).length + (s.match(/::[\w-]+/g) || []).length;
    return [a, b, c];
  }
  const covers = (short, long) => short === long || (!short.startsWith('--') && long.startsWith(short + '-'));

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
    collect(n) {
      const c = this.canvas, K = this.K, e = c.elOf(n), out = { rules: [], notes: [] };
      if (!e || !e.matches) return out;
      const win = c.frame.contentWindow, file = this.file(), sheets = this.sheets();
      let order = 0;
      const lineAt = (src, at) => (src.html ? K.lineOf(c.m.src, src.off + at) : K.lineOf(src.text, at));
      const walk = (list, ctx, src, rec, seen, media, off) => {
        for (const r of list) {
          if (r.type === 1 || (r.selectorText != null && r.style)) {
            const id = ctx + '|' + norm(r.selectorText), i = seen.get(id) || 0; seen.set(id, i + 1);
            let best = null, text = '';
            for (const part of splitSel(r.selectorText)) { let hit = false; try { hit = e.matches(part); } catch { hit = false; } if (hit) { const sp = specificity(part); if (!best || cmp(sp, best) > 0) { best = sp; text = part; } } }
            if (best) {
              const mr = rec && rec.index.get(id) ? rec.index.get(id)[i] : null, decls = [];
              if (mr) for (const d of mr.decls) decls.push({ prop: d.prop.trim(), value: d.value, important: d.important, line: lineAt(src, d.from) });
              else for (let j = 0; j < r.style.length; j++) { const p = r.style[j]; decls.push({ prop: p, value: r.style.getPropertyValue(p), important: r.style.getPropertyPriority(p) === 'important', line: 0 }); }
              out.rules.push({ sel: mr ? mr.sel : r.selectorText, matched: text, spec: best, order: order++, media, off, path: src.path, external: !!src.external, line: mr ? lineAt(src, mr.from) : (src.html ? K.lineOf(c.m.src, src.off) : 1), decls, located: !!mr });
            }
            if (r.cssRules && r.cssRules.length) walk(r.cssRules, ctx, src, rec, seen, media, off);   // קינון CSS
          } else if (r.cssRules) {
            const cond = r.conditionText != null ? r.conditionText : (r.name || ''), kind = r.constructor && r.constructor.name || '';
            let on = true;
            if (/Media/.test(kind)) { try { on = win.matchMedia(cond).matches; } catch { on = true; } }
            const at = /Media/.test(kind) ? 'media' : /Supports/.test(kind) ? 'supports' : /Layer/.test(kind) ? 'layer' : /Container/.test(kind) ? 'container' : /Scope/.test(kind) ? 'scope' : '';
            walk(r.cssRules, ctx + '/' + norm(at + cond), src, rec, seen, media + (media ? ' · ' : '') + '@' + at + ' ' + cond, off || !on);
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
        walk(rules, '', src, rec, new Map(), '', false);
      }
      const st = K.attrOf(n, 'style');
      if (st && st.value.trim()) {
        const line = st.from >= 0 ? K.lineOf(c.m.src, st.from) : 0, decls = [];
        try { const m = K.parseCss('x{' + st.value + '}'), r = m.root.kids[0]; if (r) for (const d of r.decls) decls.push({ prop: d.prop.trim(), value: d.value, important: d.important, line }); } catch { /* סגנון שאינו נקרא — לא מוצג */ }
        out.rules.push({ sel: 'style="…"', inline: true, spec: [0, 0, 0], order: order++, media: '', off: false, path: file.path, line, decls, located: line > 0 });
      }
      // מפל: לכל הצהרה — האם הצהרה חזקה ממנה (אותו מאפיין, או קיצור שמכסה אותו) דורסת אותה
      const rank = (r, d) => [d.important ? 1 : 0, r.inline ? 1 : 0, r.spec[0], r.spec[1], r.spec[2], r.order];
      const all = [];
      for (const r of out.rules) if (!r.off) for (const d of r.decls) all.push({ r, d, k: rank(r, d), p: d.prop.startsWith('--') ? d.prop : d.prop.toLowerCase() });
      for (const x of all) {
        let by = null;
        for (const y of all) if (y !== x && covers(y.p, x.p) && cmp(y.k, x.k) > 0 && (!by || cmp(y.k, by.k) > 0)) by = y;
        if (by) x.d.by = { sel: by.r.sel, path: by.r.path, line: by.d.line, important: by.d.important };
      }
      out.rules.sort((a, b) => (a.off ? 1 : 0) - (b.off ? 1 : 0) || (b.inline ? 1 : 0) - (a.inline ? 1 : 0) || cmp(b.spec, a.spec) || b.order - a.order);
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
  window.KolDetails = { Details, specificity, splitSel };
})();
