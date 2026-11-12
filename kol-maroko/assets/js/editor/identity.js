/**
 * העורך החזותי — זהות ומחלקות (פרוסה V8, 03 §4.2, §8.2): "צור מחלקה משותפת" מבחירה מרובה, המחלקות כתגיות, "שנה שם בכל
 * הפרויקט", id, וכל תכונת HTML.
 *
 * שני חלקים:
 * 1. טהור (נבדק ב-node, tests/editor/identity.mjs): בדיקות קלט (שם מחלקה, id, תכונה — on* ו-javascript: אסורים כאן),
 *    שינוי שם של מחלקה / id ב-HTML, ב-CSS (בוררים בלבד) וב-<style>; מחרוזות ב-JS — מועמדות לבחירה ידנית, לא מוחלפות לבד;
 *    וההצהרות המשותפות להעברה.
 * 2. בדפדפן (Identity): הפאנלים והדיאלוגים. כל כתיבה — writeOps (עסקה אחת עם שם, ביטול מחזיר קבצים זהים).
 *
 * כל ערך מהעמוד — כטקסט בלבד; אין innerHTML בקובץ הזה.
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc;
  const E = (msg, code) => new (K().SrcError)(msg, code || 'invalid');
  const CLS = /^-?[_a-zA-Z][\w-]*$/, ID = /^[A-Za-z][\w-]*$/;
  const esc = s => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  // ── בדיקות קלט ─────────────────────────────────────────────
  function checkClass(v) {
    v = String(v == null ? '' : v).trim();
    if (!CLS.test(v)) throw E('שם מחלקה: אותיות לטיניות, ספרות, - ו-_ ; מתחיל באות (או ב- ואחריו אות). בלי רווח, נקודה, < או מרכאות.');
    return v;
  }
  function checkId(v) {
    v = String(v == null ? '' : v).trim();
    if (!ID.test(v)) throw E('id: מתחיל באות לטינית, ואחריו אותיות, ספרות, - ו-_ (כך הוא נכתב גם בבורר #id בלי בריחה).');
    return v;
  }
  /** תכונות שערכן כתובת — javascript: / vbscript: / data:text/html אסורים בהן מהפאנל. */
  const URL_ATTRS = /^(href|src|action|formaction|xlink:href|data|poster|cite|background|longdesc|manifest|ping|srcset|imagesrcset|codebase|archive|usemap|lowsrc|dynsrc)$/i;
  const BAD_URL = v => { const s = String(v).replace(/&#x([0-9a-f]+);?/gi, (m, x) => String.fromCodePoint(parseInt(x, 16) % 0x110000)).replace(/&#(\d+);?/g, (m, x) => String.fromCodePoint(+x % 0x110000)).replace(/&colon;/gi, ':').replace(/&(tab|newline|nbsp);/gi, '').replace(/[\u0000-\u0020\u007f-\u00a0\u200b-\u200f\u2028\u2029\ufeff]/g, '').toLowerCase(); return /^(javascript|vbscript|livescript):/.test(s) || /^data:(text\/html|image\/svg|application\/xhtml|text\/xml|application\/xml)/.test(s) || (s.includes(',') && /(^|,)\s*(javascript|vbscript):/.test(s)); };
  /**
   * בדיקת תכונת HTML לפני כתיבה מהפאנל. on* ו-javascript: — אסורים, ונאמר למה (03 §4.4: "קוד נכתב בקובץ JS — פתח בקוד").
   * זו אינה נעילה של העמוד: בקוד הם נכתבים ורצים בתצוגה החיה. זו הגנה על מי שפותח את העורך.
   */
  function checkAttr(name, value) {
    const n = String(name == null ? '' : name).trim();
    if (!/^[a-zA-Z_][-a-zA-Z0-9_.:]*$/.test(n) || /:$/.test(n)) throw E('שם תכונה: אותיות לטיניות, ספרות, - _ . : — מתחיל באות. בלי רווח, =, < או מרכאות.');
    if (/^on/i.test(n)) throw E('תכונות on… (onclick וכו׳) אינן נכתבות מהפאנל — קוד נכתב בקובץ JS. פתח בקוד.', 'code');
    if (/^(srcdoc)$/i.test(n)) throw E('srcdoc הוא עמוד HTML שלם בתוך תכונה — נכתב בקוד. פתח בקוד.', 'code');
    if (/^(style)$/i.test(n)) throw E('style נערך בלשונית "עיצוב" (יעד "רק האלמנט הזה") או בקוד.', 'style');
    if (/^(class|id)$/i.test(n)) throw E(n + ' נערך בשדה שלו למעלה.', 'own');
    if (value != null && (URL_ATTRS.test(n) || /^(xlink:)?href$/i.test(n.replace(/^.*:/, ''))) && BAD_URL(value)) throw E('כתובת javascript: / data:text/html אינה נכתבת מהפאנל — קוד נכתב בקובץ JS. פתח בקוד.', 'code');
    if (value != null && /^(http-equiv)$/i.test(n) && /refresh/i.test(value)) throw E('http-equiv="refresh" נכתב בקוד.', 'code');
    return n;
  }
  /** setAttr של המודל, ובנוסף < ו-> כישויות (בערך בלבד; השם כבר נבדק). */
  function setAttr(m, n, name, value) {
    const r = K().html.setAttr(m, n, name, value);
    return { patches: r.patches.map(p => ({ from: p.from, to: p.to, text: p.text.replace(/</g, '&lt;').replace(/>/g, '&gt;') })), muts: r.muts };
  }

  // ── שינוי שם ──────────────────────────────────────────────
  /** טווח הערך הגולמי של תכונה בקובץ: { vfrom, vto, raw }, או null (תכונה בלי ערך / בלי מיקום). */
  function rawValue(src, a) {
    if (!a || a.from < 0) return null;
    const raw = src.slice(a.from, a.to), eq = raw.indexOf('='); if (eq < 0) return null;
    let i = eq + 1; while (/\s/.test(raw[i])) i++;
    const q = raw[i] === '"' || raw[i] === "'" ? raw[i] : '';
    const vfrom = a.from + i + (q ? 1 : 0), vto = q ? a.to - 1 : a.to;
    return { vfrom, vto, raw: src.slice(vfrom, vto) };
  }
  /** תיקונים לשינוי מילה ברשימה מופרדת ברווחים (class, aria-labelledby…). */
  function tokenPatches(src, a, old, neu) {
    const v = rawValue(src, a); if (!v) return [];
    const out = [], re = /[^ \t\n\r\f]+/g;
    for (let x; (x = re.exec(v.raw));) if (x[0] === old) out.push({ from: v.vfrom + x.index, to: v.vfrom + x.index + x[0].length, text: neu });
    return out;
  }
  /** בוררים בלבד (לא ערכים): .old ← .neu (sigil '.'), או #old ← #neu. גם בתנאי @scope / :has וכו׳ שבתוך הבורר. */
  function renameCss(text, old, neu, sigil = '.', off = 0) {
    const m = K().parseCss(text), out = [], re = new RegExp(esc(sigil + old) + '(?![\\w-])', 'g');
    for (const n of K().cssAll(m)) {
      let a, b;
      if (n.type === 'rule' && !n.broken && n.bFrom > n.from) { a = n.from; b = n.bFrom; }
      else if (n.type === 'at' && n.selFrom >= 0 && /^(scope|container|supports)$/i.test(n.name)) { a = n.selFrom; b = n.selTo; }
      else continue;
      const seg = text.slice(a, b), skip = [];
      for (const s of seg.matchAll(/"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'|\[[^\]]*\]/g)) skip.push([s.index, s.index + s[0].length]);
      for (const x of seg.matchAll(re)) { if (skip.some(([p, q]) => x.index >= p && x.index < q)) continue; if (sigil === '#' && /[\w-]/.test(seg[x.index - 1] || '')) continue; out.push({ from: off + a + x.index + 1, to: off + a + x.index + x[0].length, text: neu }); }
    }
    return out;
  }
  const IDREFS = /^(for|aria-labelledby|aria-describedby|aria-controls|aria-owns|aria-activedescendant|aria-flowto|aria-details|aria-errormessage|list|form|headers|popovertarget|commandfor|anchor|itemref)$/i;
  /**
   * שינוי שם ב-HTML: kind 'class' — מחלקה בכל class="" ובבוררי <style>; kind 'id' — id="", כל הפניה (for, aria-*, href="#…")
   * ובוררי #id ב-<style>. מחזיר { patches, count }.
   */
  function renameHtml(text, old, neu, kind = 'class') {
    const K2 = K(), m = K2.parseHtml(text), out = [];
    K2.walk(m.root, n => {
      if (!n.attrs) return;
      if (n.tag === 'style' && n.st && n.et) { const a = n.st[1], b = n.et[0]; out.push(...renameCss(text.slice(a, b), old, neu, kind === 'id' ? '#' : '.', a)); }
      for (const a of n.attrs) {
        const nm = String(a.name).toLowerCase();
        if (kind === 'class' && nm === 'class') out.push(...tokenPatches(text, a, old, neu));
        if (kind === 'id') {
          if (nm === 'id' && a.value === old) { const v = rawValue(text, a); if (v && v.raw === old) out.push({ from: v.vfrom, to: v.vto, text: neu }); }
          else if (IDREFS.test(nm)) out.push(...tokenPatches(text, a, old, neu));
          else if ((nm === 'href' || nm === 'xlink:href') && a.value === '#' + old) { const v = rawValue(text, a); if (v && v.raw === '#' + old) out.push({ from: v.vfrom + 1, to: v.vto, text: neu }); }
        }
      }
    });
    return { patches: out.sort((x, y) => x.from - y.from), count: out.length };
  }
  /** מחרוזות ב-JS שמכילות את השם כמילה שלמה — מועמדות בלבד (".hero" ב-JS עשוי להיות משהו אחר). */
  function jsCandidates(text, tok) {
    const out = [], re = /(['"`])((?:(?!\1)[^\\\n]|\\.)*)\1/g, w = new RegExp('(^|[^\\w-])(' + esc(tok) + ')(?![\\w-])', 'g');
    for (const s of String(text).matchAll(re)) {
      for (const x of s[2].matchAll(w)) {
        const from = s.index + 1 + x.index + x[1].length, line = text.slice(0, from).split('\n').length, ls = text.lastIndexOf('\n', from - 1) + 1, le = text.indexOf('\n', from);
        out.push({ from, to: from + tok.length, line, ctx: text.slice(ls, le < 0 ? text.length : le).trim().slice(0, 140) });
      }
    }
    return out;
  }
  /** השורות שהשתנו (לתצוגה מקדימה): [{ line, old, neu }]. */
  function lineDiff(a, b) {
    const x = a.split('\n'), y = b.split('\n'), out = [];
    if (x.length === y.length) { for (let i = 0; i < x.length; i++) if (x[i] !== y[i]) out.push({ line: i + 1, old: x[i], neu: y[i] }); return out; }
    let s = 0; while (s < x.length && x[s] === y[s]) s++;
    let e = 0; while (e < x.length - s && e < y.length - s && x[x.length - 1 - e] === y[y.length - 1 - e]) e++;
    for (let i = s; i < Math.max(x.length - e, y.length - e); i++) out.push({ line: i + 1, old: i < x.length - e ? x[i] : null, neu: i < y.length - e ? y[i] : null });
    return out;
  }
  /** ההצהרות הזהות בכל הקבוצות (מאפיין + ערך + !important), בסדר של הקבוצה הראשונה. groups — [[{prop, value, important}]]. */
  function commonDecls(groups) {
    if (!groups.length || groups.some(gr => !gr.length)) return [];
    const key = d => d.prop.trim().toLowerCase() + ':' + String(d.value).trim().replace(/\s+/g, ' ') + (d.important ? '!' : '');
    const sets = groups.map(gr => new Set(gr.map(key))), seen = new Set(), out = [];
    for (const d of groups[0]) { const k = key(d); if (seen.has(k) || !sets.every(s => s.has(k))) continue; seen.add(k); out.push(d); }
    // מאפיין שמופיע פעמיים באחת הקבוצות עם ערכים שונים — לא מועבר (הסדר בתוך הכלל קובע)
    return out.filter(d => groups.every(gr => gr.filter(x => x.prop.trim().toLowerCase() === d.prop.trim().toLowerCase()).length === 1));
  }
  /** השם המוצע למחלקה משותפת: מחלקה שכולם כבר חולקים; אחרת — מההקשר המשותף + התגית (hero-title). */
  function sharedName(nodes, used) {
    const K2 = K(), cls = nodes.map(n => K2.classesOf(n)), common = cls[0].filter(c => cls.every(l => l.includes(c)));
    if (common.length) return { name: common[0], existing: true };
    const tags = new Set(nodes.map(n => n.tag)), tag = tags.size === 1 ? ({ h1: 'title', h2: 'title', h3: 'title', h4: 'title', h5: 'title', h6: 'title', p: 'text', a: 'link', img: 'image', li: 'item', button: 'button', section: 'section', div: 'box', span: 'text' }[[...tags][0]] || [...tags][0]) : 'item';
    // ההורה המשותף הקרוב ביותר שיש לו מחלקה
    const anc = n => { const out = []; for (let p = n.parent; p; p = p.parent) out.push(p); return out; };
    const first = anc(nodes[0]); let base = '';
    for (const p of first) { if (!nodes.every(n => anc(n).includes(p))) continue; const c = K2.classesOf(p)[0]; if (c) { base = c.replace(/(-inner|-wrap|-wrapper|-container|-box|-page)$/, ''); break; } }
    let stem = ((base ? base + '-' : '') + tag).replace(/[^\w-]/g, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'item';
    if (!/^-?[_a-zA-Z]/.test(stem)) stem = 'c-' + stem;
    let name = stem; for (let i = 2; used && used.has(name); i++) name = stem + '-' + i;
    return { name, existing: false };
  }

  // ══ בדפדפן ════════════════════════════════════════════════════
  const ST = () => g.KolStyle, DT = () => g.KolDetails;
  const normSel = s => String(s).replace(/\s+/g, ' ').trim();
  /** מחלקות של הפרויקט (להשלמה): class="" בעמוד ובכל קובצי ה-HTML הפתוחים, ובוררי .x בגיליונות. */
  function projectClasses(htmlTexts, cssTexts) {
    const out = new Set();
    for (const t of htmlTexts) for (const x of t.matchAll(/\sclass\s*=\s*(["'])([^"']*)\1/gi)) for (const c of x[2].split(/\s+/)) if (CLS.test(c)) out.add(c);
    for (const t of cssTexts) for (const x of t.matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)) out.add(x[1]);
    return [...out].sort();
  }
  const ARIA = ['aria-label', 'aria-labelledby', 'aria-describedby', 'aria-hidden', 'aria-expanded', 'aria-controls', 'aria-current', 'aria-live', 'aria-pressed', 'aria-selected', 'aria-haspopup', 'aria-disabled', 'aria-busy', 'aria-modal'];
  const COMMON = ['title', 'lang', 'dir', 'tabindex', 'role', 'hidden', 'translate', 'draggable', 'spellcheck', 'inert', 'popover', 'accesskey', 'data-'];
  const BY_TAG = { a: ['href', 'target', 'rel', 'download', 'hreflang', 'type', 'referrerpolicy'], img: ['src', 'alt', 'width', 'height', 'loading', 'decoding', 'srcset', 'sizes', 'fetchpriority'], button: ['type', 'name', 'value', 'disabled', 'form', 'popovertarget'], input: ['type', 'name', 'value', 'placeholder', 'required', 'disabled', 'readonly', 'checked', 'min', 'max', 'step', 'pattern', 'autocomplete', 'inputmode', 'maxlength'], form: ['action', 'method', 'enctype', 'novalidate', 'autocomplete'], label: ['for'], iframe: ['src', 'title', 'loading', 'allow', 'allowfullscreen', 'referrerpolicy', 'sandbox', 'width', 'height'], video: ['src', 'poster', 'controls', 'autoplay', 'muted', 'loop', 'playsinline', 'preload'], audio: ['src', 'controls', 'autoplay', 'muted', 'loop', 'preload'], source: ['src', 'type', 'srcset', 'media', 'sizes'], select: ['name', 'required', 'disabled', 'multiple'], textarea: ['name', 'rows', 'cols', 'placeholder', 'required', 'maxlength'], td: ['colspan', 'rowspan', 'headers'], th: ['colspan', 'rowspan', 'scope', 'headers'], ol: ['start', 'reversed', 'type'], details: ['open', 'name'], time: ['datetime'], meta: ['name', 'content'], link: ['rel', 'href', 'media', 'type'] };
  /** הסבר בעברית לתכונות data-kol-* (02 §8.2) — נערכות כאן כטקסט; העריכה החזותית — V11. */
  const KOL_DATA = { 'data-kol-text': 'הטקסט מגיע מהשדה הזה של הרשומה', 'data-kol-if': 'האלמנט מוצג רק כשלשדה יש ערך', 'data-kol-list': 'רשימה: האלמנט משוכפל לכל רשומה', 'data-kol-filter-value': 'ערך הסינון של הכפתור', 'data-kol-play': 'כפתור השמעה', 'data-kol-active-class': 'המחלקה שנוספת כשהאלמנט פעיל', 'data-kol-open': 'פותח את החלון בשם הזה', 'data-kol-close': 'סוגר את החלון', 'data-kol-html': 'HTML מגיע מהשדה הזה' };
  const kolHelp = name => KOL_DATA[name] || (/^data-kol-attr-/.test(name) ? 'התכונה ' + name.slice(14) + ' מקבלת את הערך של השדה' : 'תכונה של מנגנון הנתונים (02 §8.2)');

  class Identity {
    /** opt: { design, canvas, styler, writeOps, note, undo, pagePath, docOf, html(), files(), openDoc(path), highlight(els), canWrite(), openInCode(n), phone(), states } */
    constructor(opt) { Object.assign(this, opt); }
    get c() { return this.canvas; }
    els(sel) { try { return [...this.c.doc.querySelectorAll(sel)].filter(e => !e.closest('[data-kol-canvas]')); } catch { return []; } }
    sheetTexts() { const out = []; for (const s of this.styler.sheets().values()) out.push({ path: s.path, text: s.doc.text() }); return out; }
    /** כמה כללים משתמשים במחלקה (בוררים בגיליונות וב-<style> של העמוד). */
    rulesUsing(cls) {
      let n = 0; const re = new RegExp('\\.' + esc(cls) + '(?![\\w-])');
      const count = t => { for (const r of K().cssAll(K().parseCss(t))) if (r.type === 'rule' && re.test(r.sel)) n++; };
      for (const s of this.sheetTexts()) count(s.text);
      K().walk(this.c.m.root, x => { if (x.tag === 'style' && x.st && x.et) count(this.c.m.src.slice(x.st[1], x.et[0])); });
      return n;
    }
    inJs(tok) { return (this.jsTexts ? this.jsTexts() : []).some(x => jsCandidates(x.text, tok).length); }

    // ── "זהות" (לשונית "מתקדם") ──
    section(n) {
      const sec = h('section', { class: 'sg-sec', 'data-sec': 'identity' }, h('h3', { class: 'dt-h' }, 'זהות'));
      if (!n || n.implied) return [sec];
      sec.append(this.idField(n), this.classTags(n), this.attrList(n));
      return [sec];
    }
    idField(n) {
      const K2 = K(), a = K2.attrOf(n, 'id'), cur = a ? a.value : '', row = h('div', { class: 'sg-field', 'data-field': 'id' });
      const inp = h('input', { type: 'text', dir: 'ltr', value: cur, 'aria-label': 'id של האלמנט', spellcheck: 'false', 'data-id-input': true, disabled: !this.canWrite() || null });
      const dup = cur && this.els('[id="' + cur.replace(/["\\]/g, '\\$&') + '"]').length > 1;
      const msg = h('p', { class: dup ? 'sg-over' : 'hint', 'data-id-msg': true }, dup ? '⚠️ id כפול בעמוד — ' + this.els('[id="' + cur.replace(/["\\]/g, '\\$&') + '"]').length + ' אלמנטים עם id="' + cur + '"' : cur ? 'ההפניות אליו (for, aria-*, href="#…", #' + cur + ' ב-CSS) מתעדכנות עם השינוי.' : 'בלי id.');
      inp.addEventListener('change', () => this.changeId(n, cur, inp.value.trim(), inp));
      row.append(h('div', { class: 'sg-line' }, h('span', { class: 'sg-label' }, 'id'), h('span', { class: 'sg-ctl wide' }, inp)), msg);
      return row;
    }
    changeId(n, old, neu, inp) {
      const K2 = K(), path = K2.pathOf(n), label = 'id · ' + this.c.label(n) + ' · ' + (old || '—') + ' ← ' + (neu || 'מחיקה');
      try { if (neu) checkId(neu); } catch (e) { inp.classList.add('bad'); inp.title = e.message; this.note(e.message, true); return; }
      if (neu === old) return;
      if (!old) { this.writeOps([() => [{ doc: this.html(), html: true, patches: setAttr(this.c.m, K2.byPath(this.c.m, path), 'id', neu).patches }]], label); return; }
      if (!neu) { this.writeOps([() => [{ doc: this.html(), html: true, patches: K2.html.removeAttr(this.c.m, K2.byPath(this.c.m, path), 'id').patches }]], label); return; }
      // תצוגה מקדימה: id, ההפניות בעמוד, ובוררי #id בגיליונות
      const html = this.html(), hp = renameHtml(html.text(), old, neu, 'id').patches, files = [{ path: this.filePath(), doc: html, patches: hp }];
      for (const s of this.styler.sheets().values()) { const ps = renameCss(s.doc.text(), old, neu, '#'); if (ps.length) files.push({ path: s.path, doc: s.doc, patches: ps }); }
      this.preview('שינוי id: ' + old + ' ← ' + neu, files, [], () => this.writeOps(files.map(f => () => [{ doc: f.doc, html: f.doc === html, patches: renameFresh(f.doc.text(), old, neu, f.doc === html ? 'html-id' : 'css-id') }]), label));
    }
    classTags(n) {
      const K2 = K(), cls = K2.classesOf(n), row = h('div', { class: 'sg-field sg-classes', 'data-field': 'class' });
      const box = h('div', { class: 'sg-tags', 'data-tags': true, role: 'list', 'aria-label': 'המחלקות של האלמנט — גרירה משנה סדר' });
      const path = K2.pathOf(n);
      cls.forEach((c, i) => {
        const els = this.els('.' + c), rules = this.rulesUsing(c), unused = !rules && !this.inJs(c);
        const tag = h('span', { class: 'sg-tag' + (unused ? ' unused' : ''), role: 'listitem', 'data-tag': c, 'data-i': i, title: c + ' · ' + els.length + ' אלמנטים · ' + rules + ' כללים' + (unused ? ' · לא משמשת לעיצוב (אין לה כלל, ואינה ב-JS)' : '') },
          h('span', { class: 'sg-tag-grip', 'aria-hidden': 'true' }, '⋮⋮'), h('code', { dir: 'ltr' }, c), h('small', { 'data-tag-count': els.length + '/' + rules }, els.length + ' אלמ׳ · ' + rules + ' כללים' + (unused ? ' · לא משמשת לעיצוב' : '')),
          h('button', { type: 'button', class: 'sg-tag-btn', 'data-tag-rename': c, title: 'שינוי שם בכל הפרויקט', 'aria-label': 'שינוי שם של ' + c + ' בכל הפרויקט', disabled: !this.canWrite() || null, onclick: () => this.renameDialog(c) }, '✎'),
          h('button', { type: 'button', class: 'sg-tag-btn', 'data-tag-remove': c, title: 'הסרת המחלקה מהאלמנט', 'aria-label': 'הסרת ' + c, disabled: !this.canWrite() || null, onclick: () => this.writeOps([() => [{ doc: this.html(), html: true, patches: K2.html.removeClass(this.c.m, K2.byPath(this.c.m, path), c).patches }]], 'הסרת מחלקה .' + c + ' · ' + this.c.label(n)) }, '✕'));
        tag.addEventListener('mouseenter', () => this.highlight(els)); tag.addEventListener('mouseleave', () => this.highlight(null));
        this.dragTag(tag, box, n);
        box.append(tag);
      });
      const all = projectClasses([this.c.m.src], this.sheetTexts().map(s => s.text)), dl = h('datalist', { id: 'sg-cls-all' }, all.map(c => h('option', { value: c })));
      const add = h('input', { type: 'text', dir: 'ltr', list: 'sg-cls-all', placeholder: '+ מחלקה', 'aria-label': 'הוספת מחלקה (השלמה מכל המחלקות בפרויקט)', spellcheck: 'false', 'data-tag-add': true, disabled: !this.canWrite() || null });
      add.addEventListener('change', () => {
        let v; try { v = checkClass(add.value); } catch (e) { add.classList.add('bad'); add.title = e.message; this.note(e.message, true); return; }
        if (cls.includes(v)) { this.note('למחלקה ' + v + ' כבר יש את האלמנט'); return; }
        this.writeOps([() => [{ doc: this.html(), html: true, patches: K2.html.addClass(this.c.m, K2.byPath(this.c.m, path), v).patches }]], 'הוספת מחלקה .' + v + ' · ' + this.c.label(n));
      });
      row.append(h('div', { class: 'sg-line' }, h('span', { class: 'sg-label' }, 'מחלקות')), box, h('div', { class: 'sg-line' }, h('span', { class: 'sg-ctl wide' }, add, dl)),
        h('p', { class: 'hint' }, 'ליד כל מחלקה: כמה אלמנטים נושאים אותה · כמה כללים משתמשים בה. ריחוף מסמן בקנבס; גרירה — שינוי סדר; ✎ — שם חדש בכל הפרויקט.'));
      return row;
    }
    /** גרירת תגית לשינוי סדר (עכבר ומגע): בשחרור — class="" בסדר החדש. */
    dragTag(tag, box, n) {
      tag.addEventListener('pointerdown', e => {
        if (e.target.closest('button') || !this.canWrite()) return;
        e.preventDefault(); tag.setPointerCapture(e.pointerId); tag.classList.add('drag');
        const list = () => [...box.querySelectorAll('[data-tag]')];
        const move = ev => { const over = list().find(t => { if (t === tag) return false; const r = t.getBoundingClientRect(); return ev.clientX >= r.left && ev.clientX <= r.right && ev.clientY >= r.top && ev.clientY <= r.bottom; }); if (!over) return; const r = over.getBoundingClientRect(), rtl = getComputedStyle(box).direction === 'rtl', before = rtl ? ev.clientX > r.left + r.width / 2 : ev.clientX < r.left + r.width / 2; box.insertBefore(tag, before ? over : over.nextSibling); };
        const up = () => {
          tag.removeEventListener('pointermove', move); tag.removeEventListener('pointerup', up); tag.removeEventListener('pointercancel', up); tag.classList.remove('drag');
          const order = list().map(t => t.dataset.tag), K2 = K(), path = K2.pathOf(n);
          if (order.join(' ') === K2.classesOf(n).join(' ')) return;
          this.writeOps([() => [{ doc: this.html(), html: true, patches: setAttr(this.c.m, K2.byPath(this.c.m, path), 'class', order.join(' ')).patches }]], 'סדר המחלקות · ' + this.c.label(n));
        };
        tag.addEventListener('pointermove', move); tag.addEventListener('pointerup', up); tag.addEventListener('pointercancel', up);
      });
    }
    attrList(n) {
      const K2 = K(), path = K2.pathOf(n), row = h('div', { class: 'sg-field sg-attrs', 'data-field': 'attrs' }), list = h('div', { class: 'sg-attr-list', 'data-attr-list': true }), data = h('div', { class: 'sg-attr-list', 'data-attr-data': true });
      const write = (label, fn) => this.writeOps([() => { const cur = K2.byPath(this.c.m, path); return [{ doc: this.html(), html: true, patches: fn(cur).patches }]; }], label + ' · ' + this.c.label(n));
      for (const a of n.attrs || []) {
        const name = String(a.name), low = name.toLowerCase();
        if (low === 'class' || low === 'id') continue;
        const lock = /^on/i.test(name) || low === 'style' || low === 'srcdoc' || (URL_ATTRS.test(low) && BAD_URL(a.value || ''));
        const inp = h('input', { type: 'text', dir: 'auto', value: a.value == null ? '' : a.value, 'aria-label': 'ערך התכונה ' + name, spellcheck: 'false', 'data-attr': name, disabled: !this.canWrite() || lock || null });
        inp.addEventListener('change', () => { try { checkAttr(name, inp.value); } catch (e) { inp.classList.add('bad'); inp.title = e.message; this.note(e.message, true); return; } write('תכונה ' + name, cur => setAttr(this.c.m, cur, name, inp.value)); });
        const del = h('button', { type: 'button', class: 'sg-reset', 'data-attr-del': name, 'aria-label': 'מחיקת התכונה ' + name, title: 'מחיקת התכונה', disabled: !this.canWrite() || null, onclick: () => write('מחיקת תכונה ' + name, cur => K2.html.removeAttr(this.c.m, cur, name)) }, '✕');
        const line = h('div', { class: 'sg-line sg-attr' }, h('code', { class: 'sg-label', dir: 'ltr' }, name), h('span', { class: 'sg-ctl wide' }, inp), del);
        if (lock) line.append(h('p', { class: 'hint sg-attr-lock', 'data-attr-lock': name }, low === 'style' ? 'style — בלשונית "עיצוב" (רק האלמנט הזה)' : 'קוד — נערך בקובץ JS / בקוד, לא כאן. ', h('button', { type: 'button', class: 'btn sm', onclick: () => this.openInCode(n) }, '</> פתח בקוד')));
        if (/^data-kol-/.test(low)) data.append(line, h('p', { class: 'hint', 'data-kol-help': name }, kolHelp(low)));
        else list.append(line);
      }
      const known = [...new Set((BY_TAG[n.tag] || []).concat(COMMON, ARIA))], dl = h('datalist', { id: 'sg-attr-names' }, known.map(x => h('option', { value: x })));
      const nm = h('input', { type: 'text', dir: 'ltr', list: 'sg-attr-names', placeholder: 'שם', 'aria-label': 'שם התכונה החדשה', spellcheck: 'false', 'data-attr-new-name': true, disabled: !this.canWrite() || null });
      const val = h('input', { type: 'text', dir: 'auto', placeholder: 'ערך', 'aria-label': 'ערך התכונה החדשה', spellcheck: 'false', 'data-attr-new-value': true, disabled: !this.canWrite() || null });
      const go = () => {
        let name; try { name = checkAttr(nm.value, val.value); } catch (e) { nm.classList.add('bad'); nm.title = e.message; this.note(e.message, true); return; }
        if (K2.attrOf(n, name)) { this.note('לאלמנט כבר יש ' + name + ' — עורכים אותה ברשימה'); return; }
        write('תכונה חדשה ' + name, cur => setAttr(this.c.m, cur, name, val.value === '' && /^(hidden|disabled|required|checked|open|inert|controls|autoplay|muted|loop|playsinline|allowfullscreen|novalidate|readonly|multiple|reversed|download|async|defer)$/i.test(name) ? null : val.value));
      };
      const addBtn = h('button', { type: 'button', class: 'btn sm', 'data-attr-add': true, disabled: !this.canWrite() || null, onclick: go }, '＋ תכונה');
      val.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
      row.append(h('h4', { class: 'sg-h4' }, 'תכונות'), list.childNodes.length ? list : h('p', { class: 'hint' }, 'אין תכונות נוספות.'),
        h('div', { class: 'sg-line sg-attr-new' }, h('span', { class: 'sg-ctl wide' }, nm, val, dl), addBtn),
        h('p', { class: 'hint' }, 'on… (onclick) ו-href="javascript:…" — רק בקוד: הם קוד, ונכתבים בקובץ JS.'));
      if (data.childNodes.length) row.append(h('h4', { class: 'sg-h4' }, 'נתונים (data-kol-*)'), h('p', { class: 'hint' }, 'מחברים את האלמנט לנתונים. נערכים כאן כטקסט (העריכה החזותית — בהמשך).'), data);
      return row;
    }

    // ── מחלקה משותפת ──
    /** המחלקות שכל הנבחרים חולקים, ולכל אחת — הכלל שלה (בדיוק .x, לא בתוך @media), אם יש. */
    shared(nodes) {
      const K2 = K(), cls = nodes.map(n => K2.classesOf(n)), common = cls[0].filter(c => cls.every(l => l.includes(c)));
      const info = this.styler.info(nodes[0]);
      return common.map(c => { const r = info.rules.filter(x => x.located && !x.external && !x.inline && !x.media && x.node && normSel(x.sel) === '.' + c).pop() || null; return { cls: c, r, count: this.els('.' + c).length }; });
    }
    /** הדיאלוג: שם (מוצע), "העבר את המשותף למחלקה" עם תצוגה מקדימה, אישור — עסקה אחת. */
    createDialog(nodes0, done) {
      const K2 = K(), paths = nodes0.map(n => K2.pathOf(n)), nodes = () => paths.map(p => K2.byPath(this.c.m, p));
      const used = new Set(); for (const e of this.c.doc.querySelectorAll('[class]')) for (const c of e.classList) used.add(c);
      const sug = sharedName(nodes0, used), page = this.pagePath();
      if (!page) { this.note('לעמוד אין קובץ CSS מקושר — מקשרים קובץ (styles.page), ואז יוצרים מחלקה', true); return; }
      const name = h('input', { type: 'text', dir: 'ltr', value: sug.name, 'aria-label': 'שם המחלקה המשותפת', spellcheck: 'false', 'data-shared-name': true });
      const cnt = h('p', { class: 'hint', 'data-shared-count': true });
      const moveBox = h('input', { type: 'checkbox', 'data-shared-move': true }), moveList = h('ul', { class: 'sg-move-list', 'data-shared-preview': true });
      const err = h('p', { class: 'sg-over', hidden: true, 'data-shared-err': true });
      const plan = () => this.movePlan(nodes0);
      const draw = () => {
        let v = ''; try { v = checkClass(name.value); name.classList.remove('bad'); } catch (e) { name.classList.add('bad'); }
        const have = v ? nodes0.filter(n => K2.classesOf(n).includes(v)).length : 0, total = v ? this.els('.' + v).length - have + nodes0.length : nodes0.length;
        cnt.textContent = v ? 'משפיע על ' + total + (have ? ' (כבר אצל ' + have + ' מהנבחרים)' : '') + ' · המחלקה תתווסף ל-class של כל אחד (בסוף), וכלל ריק .' + v + ' ב-' + page + ' — המראה אינו משתנה.' : '';
        const p = plan(); moveList.replaceChildren(...(p.decls.length ? p.decls.map(d => h('li', { dir: 'ltr' }, d.prop + ': ' + d.value + (d.important ? ' !important' : '') + ';')) : [h('li', {}, p.why || 'אין הצהרות זהות בכל הכללים של האלמנטים (שהם כלל של אלמנט אחד).')]));
        moveBox.disabled = !p.decls.length;
      };
      name.addEventListener('input', draw);
      const ok = h('button', { type: 'button', class: 'btn primary', 'data-shared-ok': true, onclick: () => {
        let v; try { v = checkClass(name.value); } catch (e) { err.hidden = false; err.textContent = e.message; return; }
        const dlg = document.getElementById('sheet'); if (dlg && dlg.open) dlg.close();
        const r = this.createShared(nodes(), v, moveBox.checked && !moveBox.disabled);
        if (r && done) done(v);
      } }, 'צור מחלקה משותפת');
      draw();
      sheet('מחלקה משותפת ל-' + nodes0.length + ' אלמנטים', h('label', { class: 'sg-line' }, h('span', { class: 'sg-label' }, 'שם'), h('span', { class: 'sg-ctl wide' }, name)), cnt,
        h('label', { class: 'sg-line' }, moveBox, ' העבר את המשותף למחלקה'), h('p', { class: 'hint' }, 'הצהרות זהות בכל הכללים של האלמנטים — רק כלל של אלמנט אחד — עוברות לכלל החדש ונמחקות מהמקור. המראה נבדק לפני ואחרי; שינוי — והפעולה מבוטלת.'), moveList, err,
        h('div', { class: 'ed-ask-bar' }, ok));
    }
    /** מה יועבר: לכל אלמנט — הכללים שלו שהם "של אלמנט אחד" (חל על אחד בדיוק, בלי @media, כתוב בקובץ); ההצהרות הזהות בכולם. */
    movePlan(nodes) {
      const groups = [], srcs = [];
      for (const n of nodes) {
        const info = this.styler.info(n), own = info.rules.filter(r => r.located && !r.external && !r.inline && !r.media && !r.off && r.node && !r.node.broken && this.styler.count(r.sel) === 1 && DT().splitSel(r.sel).length === 1);
        if (!own.length) return { decls: [], why: 'ל-' + this.c.label(n) + ' אין כלל משלו (כלל שחל רק עליו) — אין מה להעביר.' };
        groups.push(own.flatMap(r => r.decls.filter(d => d.d))); srcs.push(own);
      }
      return { decls: commonDecls(groups), srcs };
    }
    /** הכתיבה: class לכל אלמנט + כלל ריק (או עם ההצהרות המועברות) + מחיקה מהמקור — עסקה אחת. */
    createShared(nodes, cls, move) {
      const K2 = K(), page = this.pagePath(), doc = this.docOf(page), html = this.html(), paths = nodes.map(n => K2.pathOf(n));
      if (!doc) { this.note('קובץ העיצוב ' + page + ' אינו פתוח לעריכה', true); return null; }
      const plan = move ? this.movePlan(nodes) : { decls: [] }, decls = plan.decls || [];
      const exists = K2.cssAll(K2.parseCss(doc.text())).some(r => r.type === 'rule' && normSel(r.sel) === '.' + cls);
      const before = move ? nodes.map(n => this.snap(n)) : null;
      const ops = paths.map(p => () => { const n = K2.byPath(this.c.m, p); if (!n || K2.classesOf(n).includes(cls)) return []; return [{ doc: html, html: true, patches: K2.html.addClass(this.c.m, n, cls).patches }]; });
      if (!exists || decls.length) ops.push(() => {
        const m = K2.parseCss(doc.text()), r = K2.cssAll(m).filter(x => x.type === 'rule' && normSel(x.sel) === '.' + cls && x.parent === m.root).pop();
        if (r) { let cur = doc.text(); for (const d of decls) { const mm = K2.parseCss(cur), rr = K2.cssAll(mm).find(x => x.type === 'rule' && x.from === r.from); cur = K2.applyPatches(cur, K2.css.addDecl(mm, rr, d.prop, d.value + (d.important ? ' !important' : '')).patches); } return [{ doc, patches: diff(doc.text(), cur) }]; }
        return [{ doc, patches: K2.css.addRule(m, m.root, '.' + cls, decls.map(d => [d.prop, d.value + (d.important ? ' !important' : '')])).patches }];
      });
      // מהסוף להתחלה: מחיקה בכלל מוקדם אינה מזיזה את הכללים שאחריו בקובץ שעוד לא טופלו
      if (decls.length) for (const r of plan.srcs.flat().sort((a, b) => (a.path === b.path ? b.node.from - a.node.from : a.path < b.path ? -1 : 1))) ops.push(() => this.removeDecls(r, decls));
      const label = 'מחלקה משותפת .' + cls + ' · ' + nodes.length + ' אלמנטים' + (decls.length ? ' · הועברו ' + decls.length + ' הצהרות' : '');
      const docs = this.writeOps(ops, label);
      if (!docs) return null;
      if (move && decls.length) {
        const after = paths.map(p => this.snap(K2.byPath(this.c.m, p)));
        const bad = after.findIndex((a, i) => a !== before[i]);
        if (bad >= 0) { this.undo(); this.note('ההעברה שינתה את המראה של ' + this.c.label(nodes[bad]) + ' (כלל חזק יותר דורס את המחלקה) — הפעולה בוטלה. אפשר ליצור את המחלקה בלי להעביר.', true); return null; }
      }
      return docs;
    }
    /** מחיקת ההצהרות המועברות מכלל מקור (על הטקסט העדכני). */
    removeDecls(r, decls) {
      const K2 = K(), src = r.src, html = this.html(), key = d => d.prop.trim().toLowerCase() + ':' + String(d.value).trim().replace(/\s+/g, ' ') + (d.important ? '!' : ''), want = new Set(decls.map(key));
      const text0 = src.html ? html.text().slice(src.off, src.off + src.text.length) : this.docOf(src.path).text();
      let cur = text0;
      for (;;) {
        const m = K2.parseCss(cur), rr = K2.cssAll(m).find(x => x.type === 'rule' && x.from === r.node.from); if (!rr) break;
        const d = rr.decls.find(x => want.has(key(x))); if (!d) break;
        cur = K2.applyPatches(cur, ST().removeDeclAt(m, rr, d));
      }
      const ps = diff(text0, cur);
      if (src.html) return [{ doc: html, html: true, patches: ps.map(p => ({ from: p.from + src.off, to: p.to + src.off, text: p.text })) }];
      return [{ doc: this.docOf(src.path), patches: ps }];
    }
    /** תמונת המראה המחושב של אלמנט (כל המאפיינים) — לבדיקת "זהה לפני ואחרי". */
    snap(n) { const e = this.c.elOf(n); if (!e) return ''; const cs = this.c.frame.contentWindow.getComputedStyle(e); let s = ''; for (let i = 0; i < cs.length; i++) s += cs[i] + ':' + cs.getPropertyValue(cs[i]) + ';'; return s; }

    // ── שינוי שם בכל הפרויקט ──
    async renameDialog(old) {
      const name = h('input', { type: 'text', dir: 'ltr', value: old, 'aria-label': 'השם החדש', spellcheck: 'false', 'data-rename-to': true });
      const out = h('div', { class: 'sg-rename', 'data-rename-preview': true }, h('p', { class: 'hint' }, 'טוען את קובצי הפרויקט…'));
      const err = h('p', { class: 'sg-over', hidden: true, 'data-rename-err': true });
      let files = [], js = [], picks = new Set();
      const ok = h('button', { type: 'button', class: 'btn primary', 'data-rename-ok': true, disabled: true, onclick: () => go() }, 'שנה שם בכל הפרויקט');
      sheet('שינוי שם של .' + old + ' בכל הפרויקט', h('label', { class: 'sg-line' }, h('span', { class: 'sg-label' }, 'שם חדש'), h('span', { class: 'sg-ctl wide' }, name)), out, err, h('div', { class: 'ed-ask-bar' }, ok));
      const docs = await this.projectDocs();
      const draw = () => {
        let neu = ''; err.hidden = true;
        try { neu = checkClass(name.value); } catch (e) { err.hidden = false; err.textContent = e.message; ok.disabled = true; return; }
        if (neu === old) { out.replaceChildren(h('p', { class: 'hint' }, 'מקלידים שם חדש.')); ok.disabled = true; return; }
        files = []; js = [];
        for (const d of docs) {
          const t = d.doc.text();
          if (/\.html?$/i.test(d.path)) { const r = renameHtml(t, old, neu, 'class'); if (r.patches.length) files.push({ path: d.path, doc: d.doc, patches: r.patches }); }
          else if (/\.css$/i.test(d.path)) { const ps = renameCss(t, old, neu, '.'); if (ps.length) files.push({ path: d.path, doc: d.doc, patches: ps }); }
          else if (/\.m?js$/i.test(d.path)) for (const c of jsCandidates(t, old)) js.push(Object.assign({ path: d.path, doc: d.doc, key: d.path + ':' + c.from }, c));
        }
        const kids = [];
        for (const f of files) { const after = K().applyPatches(f.doc.text(), f.patches); kids.push(h('h4', { class: 'sg-h4', dir: 'ltr' }, f.path + ' · ' + f.patches.length), h('pre', { class: 'sg-diff', dir: 'ltr', 'data-diff': f.path }, lineDiff(f.doc.text(), after).map(l => (l.old != null ? '- ' + l.line + ': ' + l.old.trim() + '\n' : '') + (l.neu != null ? '+ ' + l.line + ': ' + l.neu.trim() + '\n' : '')).join(''))); }
        if (js.length) {
          kids.push(h('h4', { class: 'sg-h4' }, 'מחרוזות ב-JS — לבחירה ידנית (לא מוחלפות לבד: "' + old + '" ב-JS עשוי להיות משהו אחר)'));
          for (const c of js) { const cb = h('input', { type: 'checkbox', 'data-rename-js': c.key, checked: picks.has(c.key) || null }); cb.addEventListener('change', () => { if (cb.checked) picks.add(c.key); else picks.delete(c.key); }); kids.push(h('label', { class: 'sg-line sg-js-cand', dir: 'ltr' }, cb, ' ' + c.path + ':' + c.line + ' — ' + c.ctx)); }
        }
        if (!kids.length) kids.push(h('p', { class: 'hint' }, 'המחלקה אינה מופיעה בשום קובץ.'));
        out.replaceChildren(...kids); ok.disabled = !files.length && !js.length;
      };
      const go = () => {
        let neu; try { neu = checkClass(name.value); } catch { return; }
        const dlg = document.getElementById('sheet'); if (dlg && dlg.open) dlg.close();
        const byDoc = new Map();
        for (const f of files) byDoc.set(f.doc, { doc: f.doc, path: f.path, kind: /\.css$/i.test(f.path) ? 'css' : 'html' });
        for (const c of js) if (picks.has(c.key)) { if (!byDoc.has(c.doc)) byDoc.set(c.doc, { doc: c.doc, path: c.path, kind: 'js', at: [] }); (byDoc.get(c.doc).at = byDoc.get(c.doc).at || []).push(c.from); }
        const html = this.html();
        this.writeOps([...byDoc.values()].map(f => () => [{ doc: f.doc, html: f.doc === html, patches: f.kind === 'js' ? f.at.map(a => ({ from: a, to: a + old.length, text: neu })).sort((x, y) => y.from - x.from) : renameFresh(f.doc.text(), old, neu, f.kind) }]), 'שינוי שם .' + old + ' ← .' + neu + ' בכל הפרויקט');
      };
      name.addEventListener('input', draw); draw();
    }
    /** כל קובצי ה-HTML / CSS / JS של הפרויקט, פתוחים כמסמכים (העמוד והגיליונות — אותם מסמכים של העורך). */
    async projectDocs() {
      const out = [], seen = new Set(), add = (path, doc) => { if (doc && !seen.has(path)) { seen.add(path); out.push({ path, doc }); } };
      add(this.filePath(), this.html());
      for (const s of this.styler.sheets().values()) add(s.path, s.doc);
      for (const f of (this.files() || [])) { if (f.kind === 'dir' || seen.has(f.path) || !/\.(html?|css|m?js)$/i.test(f.path) || f.id < 0) continue; try { add(f.path, await this.openDoc(f)); } catch { /* קובץ שלא נפתח — לא בשינוי */ } }
      return out;
    }
    /** תצוגה מקדימה כללית: diff לכל קובץ, ואישור. */
    preview(title, files, notes, run) {
      const kids = files.filter(f => f.patches.length).map(f => { const after = K().applyPatches(f.doc.text(), f.patches); return [h('h4', { class: 'sg-h4', dir: 'ltr' }, f.path), h('pre', { class: 'sg-diff', dir: 'ltr', 'data-diff': f.path }, lineDiff(f.doc.text(), after).map(l => (l.old != null ? '- ' + l.line + ': ' + l.old.trim() + '\n' : '') + (l.neu != null ? '+ ' + l.line + ': ' + l.neu.trim() + '\n' : '')).join(''))]; }).flat();
      sheet(title, ...kids, ...notes, h('div', { class: 'ed-ask-bar' }, h('button', { type: 'button', class: 'btn primary', 'data-preview-ok': true, onclick: () => { const dlg = document.getElementById('sheet'); if (dlg && dlg.open) dlg.close(); run(); } }, 'אישור')));
    }
  }
  /** התיקונים של שינוי שם, מחושבים מחדש על הטקסט העדכני (בתוך העסקה). */
  function renameFresh(text, old, neu, kind) {
    if (kind === 'css') return renameCss(text, old, neu, '.');
    if (kind === 'css-id') return renameCss(text, old, neu, '#');
    if (kind === 'html-id') return renameHtml(text, old, neu, 'id').patches;
    return renameHtml(text, old, neu, 'class').patches;
  }
  const diff = (was, now) => { if (was === now) return []; let a = 0; const max = Math.min(was.length, now.length); while (a < max && was.charCodeAt(a) === now.charCodeAt(a)) a++; let b = 0; while (b < max - a && was.charCodeAt(was.length - 1 - b) === now.charCodeAt(now.length - 1 - b)) b++; return [{ from: a, to: was.length - b, text: now.slice(a, now.length - b) }]; };

  g.KolIdentity = { Identity, checkClass, checkId, checkAttr, setAttr, renameCss, renameHtml, renameFresh, jsCandidates, lineDiff, commonDecls, sharedName, projectClasses, BAD_URL };
})(typeof window !== 'undefined' ? window : globalThis);
