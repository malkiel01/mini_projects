/**
 * קול מרוקו — 🎨 עורך (פרוסה V11): פאנל "📊 נתונים" — קשירה, המכל, רשימת השדות ורכיבי הרשימה (03 §9; 01-gap 7.10, 9.1–9.4).
 *
 * כאן אין תחביר משלנו: כל שליטה כותבת תכונת data-kol-* רגילה על אלמנט רגיל (02 §8.2), דרך אותו צינור של העורך (writeOps ←
 * המודל ← שירות המסמכים): עסקה אחת עם שם, Ctrl+Z. שם תכונה — KolIdentity.checkAttr (on*, srcdoc, style — לא); ערך —
 * KolIdentity.setAttr (< > כישויות). כל טקסט שהגולש רואה (כיתובי "טען עוד", "אין תוצאות", "הכול") — תוכן של אלמנט בקובץ.
 * רשימת השדות (N2: שם, סוג, "פנימי") — בספרייה; גרירה אל הכרטיס יוצרת אלמנט קשור, וגרירה על אלמנט קיים — קושרת אותו.
 */
(function () {
  'use strict';
  const K = () => window.KolSrc, I = () => window.KolIdentity, B = () => window.KolBind;
  const ICON = { text: '𝐓', longtext: '¶', rich: '✍', number: '#', bool: '✓', choice: '◉', date: '📅', url: '🔗', image: '🖼', audio: '🔊', list: '☰', topics: '🏷' };
  const TYPE = { text: 'טקסט', longtext: 'טקסט ארוך', rich: 'טקסט עשיר', number: 'מספר', bool: 'כן/לא', choice: 'בחירה', date: 'תאריך', url: 'קישור', image: 'תמונה', audio: 'הקלטה', list: 'רשימה', topics: 'נושאים' };
  const ATTR_TARGETS = ['src', 'href', 'alt', 'title', 'aria-label', 'data-id', 'value', 'datetime'];
  const LANGS = [['', 'ברירת המחדל (he)'], ['he', 'עברית'], ['ar', 'ערבית'], ['fr', 'צרפתית'], ['en', 'אנגלית'], ['es', 'ספרדית']];
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const escT = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  /** תנאי (data-kol-if / class) ← { op: '' | has | no | eq | ne | count | raw, f, v }. */
  function parseCond(c) {
    c = String(c == null ? '' : c).trim();
    if (!c) return { op: '' };
    const m = /^(.*?)(!=|=)(.*)$/.exec(c);
    if (m) { const f = m[1].trim(); if (/\.count$/.test(f) && m[2] === '=') return { op: 'count', f: f.replace(/\.count$/, ''), v: m[3].trim() }; return { op: m[2] === '=' ? 'eq' : 'ne', f, v: m[3].trim() }; }
    if (c[0] === '!') return { op: 'no', f: c.slice(1).trim() };
    return { op: 'has', f: c };
  }
  function makeCond(o) {
    if (!o || !o.op || !o.f) return '';
    return o.op === 'has' ? o.f : o.op === 'no' ? '!' + o.f : o.op === 'eq' ? o.f + '=' + (o.v || '') : o.op === 'ne' ? o.f + '!=' + (o.v || '') : o.op === 'count' ? o.f + '.count=' + (parseInt(o.v, 10) || 0) : '';
  }
  /** אלמנט קשור לפי סוג השדה (גרירה מרשימת השדות). label — כיתוב גלוי (טקסט בקובץ). */
  function fieldHtml(f) {
    const k = f.key;
    switch (f.type) {
      case 'rich': return '<div data-kol-html="' + esc(k) + '"></div>';
      case 'image': return '<img data-kol-attr-src="' + esc(k) + '" data-kol-attr-alt="{word}" alt="">';
      case 'audio': return '<button type="button" data-kol-play="' + esc(k) + '">▶ השמעה</button>';
      case 'topics': return '<ul><li data-kol-each="' + esc(k) + '" data-kol-text="label"></li></ul>';
      case 'list': return '<ul><li data-kol-each="' + esc(k) + '" data-kol-text="."></li></ul>';
      case 'url': return '<a data-kol-attr-href="' + esc(k) + '" data-kol-text="' + esc(k) + '"></a>';
      case 'bool': return '<span data-kol-if="' + esc(k) + '">✓ ' + escT(f.label || k) + '</span>';
      case 'choice': return '<span data-kol-text="' + esc(k) + '"></span>';
      default: return '<span data-kol-text="' + esc(k) + '"></span>';
    }
  }
  /** "רכיבי רשימה" (01-gap 9.3, 9.4): כל אחד — אלמנט רגיל, הכיתוב בעברית כטקסט באלמנט. */
  function aroundItems(name, filterField) {
    const n = esc(name);
    return [
      { id: 'kl-search', icon: '🔎', label: 'חיפוש', html: '<input type="search" data-kol-search="' + n + '" data-kol-search-fields="word" placeholder="חיפוש…" aria-label="חיפוש">' },
      { id: 'kl-scope', icon: '☑', label: 'הרחב את החיפוש', html: '<label><input type="checkbox" data-kol-search-scope="' + n + '" data-kol-search-fields="word meaning topics"> <span>הרחב את החיפוש גם לפירוש ולנושאים</span></label>' },
      { id: 'kl-filter', icon: '🏷', label: 'כפתורי סינון', html: '<div data-kol-filter="' + n + ':' + esc(filterField || 'topics') + '" data-kol-active-class="is-active"><button type="button" data-kol-filter-value="">הכול</button><template><button type="button"><span data-kol-text="label"></span></button></template></div>' },
      { id: 'kl-count', icon: '🔢', label: 'מונה', html: '<p><span data-kol-count="' + n + '" data-kol-count-text="{shown} מתוך {total}">מספר המילים</span></p>' },
      { id: 'kl-more', icon: '⤵', label: '"טען עוד"', html: '<button type="button" data-kol-more="' + n + '">טען עוד</button>' },
      { id: 'kl-empty', icon: '∅', label: '"אין תוצאות"', html: '<p data-kol-empty="' + n + '" hidden>אין תוצאות</p>' },
    ];
  }
  /** הקטע של N3 §2.5 ("➕ הוסף לעמוד") — אותו HTML כמו list_snippet ב-lib/lists.php, להוספה במקום שנבחר בקנבס. */
  function listSnippet(l, fields) {
    const k = l.key; if (!/^[a-z][a-z0-9_]{0,29}$/.test(k)) throw new Error('מפתח לא תקין');
    const c = 'kol-list-' + k, pub = fields.filter(f => f.published !== false && /^[a-z][a-z0-9_]{0,39}$/.test(f.key));
    const search = pub.filter(f => ['text', 'longtext', 'rich', 'list', 'choice'].includes(f.type)).map(f => f.key);
    const choice = pub.find(f => f.type === 'choice'), label = esc(l.label); let first = true; const tpl = [];
    for (const f of pub) {
      const fk = f.key, cls = c + '__f-' + fk;
      tpl.push(({ rich: '        <div class="' + cls + '" data-kol-html="' + fk + '"></div>', longtext: '        <p class="' + cls + '" data-kol-text="' + fk + '"></p>',
        image: '        <img class="' + cls + '" data-kol-if="' + fk + '" data-kol-attr-src="' + fk + '" alt="">', audio: '        <button type="button" class="' + cls + '" data-kol-if="' + fk + '" data-kol-play="' + fk + '">▶ השמעה</button>',
        url: '        <a class="' + cls + '" data-kol-if="' + fk + '" data-kol-attr-href="' + fk + '" data-kol-text="' + fk + '"></a>', list: '        <ul class="' + cls + '" data-kol-if="' + fk + '"><li data-kol-each="' + fk + '" data-kol-text="."></li></ul>',
        bool: '        <span class="' + cls + '" data-kol-if="' + fk + '">✓ ' + esc(f.label) + '</span>' })[f.type] || (first ? '        <h3 class="' + cls + '" data-kol-text="' + fk + '"></h3>' : '        <p class="' + cls + '" data-kol-if="' + fk + '" data-kol-text="' + fk + '"></p>'));
      if (['text', 'number', 'date', 'choice', 'longtext', 'rich'].includes(f.type)) first = false;
    }
    return '<section class="' + c + '" id="list-' + k + '">\n  <h2 class="' + c + '__title">' + label + '</h2>\n'
      + (search.length ? '  <input type="search" class="' + c + '__search" data-kol-search="' + k + '" data-kol-search-fields="' + search.join(' ') + '" placeholder="חיפוש…" aria-label="חיפוש ב' + label + '">\n' : '')
      + (choice ? '  <div class="' + c + '__filter" data-kol-filter="' + k + ':' + choice.key + '" data-kol-active-class="is-active">\n    <button type="button" data-kol-filter-value="">הכול</button>\n    <template><button type="button" data-kol-text="label"></button></template>\n  </div>\n' : '')
      + '  <p class="' + c + '__count" data-kol-count="' + k + '" data-kol-count-text="{shown} מתוך {total}"></p>\n'
      + '  <div class="' + c + '__items" data-kol-list="' + k + '" data-kol-sort="manual" data-kol-page="30">\n    <template>\n      <article class="' + c + '__item">\n' + tpl.join('\n') + '\n      </article>\n    </template>\n  </div>\n'
      + '  <p class="' + c + '__empty" data-kol-empty="' + k + '">אין תוצאות</p>\n  <button type="button" class="' + c + '__more" data-kol-more="' + k + '">טען עוד</button>\n</section>';
  }

  class Data {
    /** opt: { canvas, lv, K, X, build, writeOps, act, multi, html() (המסמך), note, sheet, undo, canWrite, api, pagePath, docOf, select, content, libHost, drawAll, styler, write } */
    constructor(opt) {
      Object.assign(this, opt); this.c = this.canvas; this.fields = new Map(); this.listsMeta = null; this.lv.fieldCache = this.fields;
      if (this.content) this.content.dataSection = n => this.section(n);
      this.mountLib(); this.wrapDrop();
    }
    // ── נתוני עזר ────────────────────────────────────────
    async loadFields(name) {
      if (this.fields.has(name) || this['_f' + name]) return;
      this['_f' + name] = true;
      try {
        const d = await this.api('fields', undefined, name === 'words' ? '' : '&list=' + encodeURIComponent(name));
        this.fields.set(name, (d.fields || []).map(f => ({ key: f.key, label: f.label || f.key, type: f.type, published: f.published !== false, builtin: !!f.builtin })));
      } catch { this.fields.set(name, null); }
      this.drawLib(); this.drawAll(); this.lv.drawStrip();
    }
    async loadLists() {
      if (this.listsMeta || this._lists) return; this._lists = true;
      try { this.listsMeta = (await this.api('lists')).lists || []; } catch { this.listsMeta = []; }
      this.drawLib(); this.drawAll();
    }
    /** השדות של רשימה: מ-N2 (שם, סוג, פנימי); עד שנטענו — מהמפתחות שבנתונים. */
    fieldsOf(name) {
      const f = this.fields.get(name); if (f) return f;
      this.loadFields(name);
      const L = this.lv.lists.find(x => x.name === name), items = L && L.ds && L.ds.state ? L.ds.state.items.slice(0, 80) : [];
      const keys = [...new Set(items.flatMap(x => Object.keys(x)))].filter(k => !k.startsWith('__'));
      return keys.map(k => ({ key: k, label: k, type: items.some(x => Array.isArray(x[k])) ? (k === 'topics' ? 'topics' : 'list') : 'text', published: true }));
    }
    primary() { const L = this.lv.lists[0]; return L ? L.name : null; }
    /** הרשימה שהצומת שייך לתבנית שלה: { name, container, tpl } או null. */
    listOf(n) {
      for (let p = n; p; p = p.parent) if (p.tag === 'template' && p.parent) { const a = K().attrOf(p.parent, 'data-kol-list'); if (a) return { name: a.value, container: p.parent, tpl: p }; return null; }
      return null;
    }
    attr(n, name) { const a = K().attrOf(n, name); return a ? a.value : null; }
    /** כתיבת תכונות — עסקה אחת: sets = [[שם, ערך | null], …] (null — הסרה). */
    setAttrs(n, sets, label) {
      if (!this.canWrite()) { this.note('הקובץ פתוח לקריאה בלבד', true); return false; }
      for (const [name, val] of sets) { try { I().checkAttr(name, val); } catch (e) { this.note(e.message, true); return false; } }
      const path = K().pathOf(n), c = this.c;
      const ops = sets.map(([name, val]) => () => {
        const cur = K().byPath(c.m, path); if (!cur) return [];
        if (val == null) return K().attrOf(cur, name) ? [{ doc: this.html(), patches: K().html.removeAttr(c.m, cur, name).patches }] : [];
        const a = K().attrOf(cur, name); if (a && a.value === val) return [];
        return [{ doc: this.html(), patches: I().setAttr(c.m, cur, name, val).patches }];
      });
      const r = this.writeOps(ops, label);
      const back = K().byPath(c.m, path); if (back && c.selected !== back) c.select(back);
      return !!r;
    }
    /** יעד התכונה (src, href…): שם תקין, ולא on* / srcdoc / style. */
    checkTarget(t) {
      try { I().checkAttr(t, null); } catch (e) { if (e.code !== 'own') throw e; }
      if (/^(style|srcdoc)$/i.test(t) || /^on/i.test(t)) throw new Error('"' + t + '" — אינה נקשרת לנתונים (קוד / עיצוב)');
      return t;
    }
    // ── הפאנל: "📊 נתונים" בלשונית "תוכן" ────────────────
    section(n) {
      if (!n || !this.c.m) return [];
      const out = [], K2 = K();
      if (K2.attrOf(n, 'data-kol-list')) out.push(this.containerSec(n));
      const L = this.listOf(n);
      if (L) out.push(this.bindSec(n, L));
      const around = ['data-kol-search', 'data-kol-search-scope', 'data-kol-filter', 'data-kol-count', 'data-kol-more', 'data-kol-empty'].filter(a => K2.attrOf(n, a));
      if (around.length) out.push(this.aroundSec(n, around));
      if (!L && K2.attrOf(n, 'data-kol-play')) out.push(this.group('📊 נתונים · נגן', h('p', { class: 'hint' }, 'כפתור השמעה מחוץ לתבנית: data-kol-play="' + this.attr(n, 'data-kol-play') + '" — כתובת הקלטה קבועה.')));
      return out;
    }
    group(title, ...kids) { return h('section', { class: 'ct-group dp-group', 'data-group': title, 'data-dp': true }, h('h3', { class: 'dt-h' }, title), ...kids.filter(Boolean)); }
    row(label, ...kids) { return h('div', { class: 'ct-row dp-row' }, h('span', { class: 'ct-k' }, label), ...kids.filter(Boolean)); }
    sel(opts, cur, attrs = {}) { return h('select', attrs, opts.map(([v, l]) => h('option', { value: v, selected: v === cur }, l))); }
    fieldOpts(name, filter, none = '—') { return [['', none], ...this.fieldsOf(name).filter(filter || (() => true)).map(f => [f.key, f.label + (f.label !== f.key ? ' (' + f.key + ')' : '') + (f.published === false ? ' · פנימי' : '')])]; }
    bindSec(n, L) {
      const name = L.name, a = x => this.attr(n, x), fs = this.fieldsOf(name), fType = k => (fs.find(f => f.key === k) || {}).type;
      const kids = [];
      const html = a('data-kol-html'), text = a('data-kol-text'), mixed = text != null && text.indexOf('{') >= 0;
      // 1. הטקסט = השדה
      const curF = html != null ? html : (text != null && !mixed ? text : ''), mode = mixed ? '__mixed' : curF;
      const textSel = this.sel([...this.fieldOpts(name, null, '— הטקסט שבקובץ'), ['__mixed', 'טקסט מעורב…']], mode, { 'data-dp': 'text', 'aria-label': 'הטקסט = השדה' });
      textSel.addEventListener('change', () => {
        const v = textSel.value;
        if (v === '__mixed') return this.setAttrs(n, [['data-kol-text', text && mixed ? text : '{' + (curF || (fs[0] || {}).key || 'word') + '}'], ['data-kol-html', null]], 'קשירה · טקסט מעורב · ' + this.c.label(n));
        if (!v) return this.setAttrs(n, [['data-kol-text', null], ['data-kol-html', null]], 'קשירה · בלי שדה · ' + this.c.label(n));
        const rich = fType(v) === 'rich';
        this.setAttrs(n, [[rich ? 'data-kol-html' : 'data-kol-text', v], [rich ? 'data-kol-text' : 'data-kol-html', null]], 'קשירה · הטקסט = ' + v + ' · ' + this.c.label(n));
      });
      kids.push(this.row('הטקסט = השדה', textSel));
      if (mixed) {
        const inp = h('input', { type: 'text', value: text, dir: 'auto', 'data-dp': 'mixed', 'aria-label': 'טקסט מעורב' });
        const add = this.sel([['', '＋ הוסף שדה'], ...fs.map(f => [f.key, f.label])], '', { 'data-dp': 'mixed-add', 'aria-label': 'הוסף שדה' });
        add.addEventListener('change', () => { const k = add.value; if (!k) return; const s = inp.selectionStart ?? inp.value.length, e = inp.selectionEnd ?? s; inp.value = inp.value.slice(0, s) + '{' + k + '}' + inp.value.slice(e); add.value = ''; inp.focus(); inp.setSelectionRange(s + k.length + 2, s + k.length + 2); });
        const go = h('button', { type: 'button', class: 'btn sm', 'data-w': true, 'data-dp': 'mixed-apply' }, 'החל');
        go.addEventListener('click', () => this.setAttrs(n, [['data-kol-text', inp.value]], 'קשירה · טקסט מעורב · ' + this.c.label(n)));
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go.click(); } });
        kids.push(h('div', { class: 'dp-mixed' }, inp, add, go), h('p', { class: 'hint' }, 'לדוגמה: "השמע את {word}" — {שדה} מוחלף בערך; שדה שאינו קיים — ריק.'));
      }
      if ((html != null || text != null) && n.kids.length) kids.push(h('p', { class: 'hint' }, 'בתוכן — הנתונים; מה שכתוב בתוך האלמנט בקובץ אינו מוצג.'));
      // 2. תכונות
      const attrs = n.attrs.filter(x => x.name.startsWith('data-kol-attr-'));
      for (const x of attrs) {
        const t = x.name.slice(14), inp = h('input', { type: 'text', value: x.value, dir: 'ltr', 'data-dp': 'attr-val', 'data-attr': t, 'aria-label': 'התכונה ' + t + ' = ' });
        inp.addEventListener('change', () => this.setAttrs(n, [[x.name, inp.value]], 'קשירה · ' + t + ' = ' + inp.value + ' · ' + this.c.label(n)));
        kids.push(this.row('התכונה ' + t + ' =', inp, h('button', { type: 'button', class: 'icon-btn', 'data-w': true, 'aria-label': 'הסר את הקשירה של ' + t, onclick: () => this.setAttrs(n, [[x.name, null]], 'קשירה · הסרת ' + t + ' · ' + this.c.label(n)) }, '✕')));
      }
      const tSel = this.sel([['', 'התכונה…'], ...ATTR_TARGETS.map(t => [t, t]), ['__other', 'אחרת…']], '', { 'data-dp': 'attr-target', 'aria-label': 'התכונה' });
      const tOther = h('input', { type: 'text', dir: 'ltr', placeholder: 'שם התכונה', hidden: true, 'aria-label': 'שם התכונה', 'data-dp': 'attr-other' });
      tSel.addEventListener('change', () => { tOther.hidden = tSel.value !== '__other'; });
      const fSel = this.sel(this.fieldOpts(name, null, 'השדה…'), '', { 'data-dp': 'attr-field', 'aria-label': 'השדה' });
      const addA = h('button', { type: 'button', class: 'btn sm', 'data-w': true, 'data-dp': 'attr-add' }, '＋ קשור');
      addA.addEventListener('click', () => {
        const t = (tSel.value === '__other' ? tOther.value : tSel.value).trim(), f = fSel.value;
        if (!t || !f) return this.note('בוחרים תכונה ושדה');
        try { this.checkTarget(t); } catch (e) { return this.note(e.message, true); }
        this.setAttrs(n, [['data-kol-attr-' + t.toLowerCase(), f]], 'קשירה · ' + t + ' = ' + f + ' · ' + this.c.label(n));
      });
      kids.push(this.row('התכונה ___ = השדה ___', tSel, tOther, fSel, addA));
      // 3. מוצג רק אם
      kids.push(this.condRow('מוצג רק אם', name, a('data-kol-if'), v => this.setAttrs(n, [['data-kol-if', v || null]], 'קשירה · מוצג רק אם ' + (v || '— תמיד') + ' · ' + this.c.label(n)), 'if'));
      // 4. מחלקות לפי תנאי
      const pairs = [];
      for (const x of n.attrs) if (x.name.startsWith('data-kol-class-')) pairs.push({ name: x.name.slice(15), cond: x.value, attr: x.name });
      if (a('data-kol-class') != null) for (const [nm, cond] of B().classPairs(a('data-kol-class'))) pairs.push({ name: nm, cond, general: true });
      for (const p of pairs) {
        const rm = () => p.general ? this.setAttrs(n, [['data-kol-class', B().classPairs(a('data-kol-class')).filter(q => q[0] !== p.name).map(q => q[0] + ': ' + q[1]).join('; ') || null]], 'קשירה · הסרת המחלקה ' + p.name) : this.setAttrs(n, [[p.attr, null]], 'קשירה · הסרת המחלקה ' + p.name);
        kids.push(this.row('מחלקה', h('code', {}, p.name), h('span', { class: 'hint' }, ' כש-' + p.cond + ' '), h('button', { type: 'button', class: 'icon-btn', 'data-w': true, 'aria-label': 'הסר את המחלקה ' + p.name, onclick: rm }, '✕')));
      }
      const cName = h('input', { type: 'text', dir: 'ltr', placeholder: 'is-active', 'data-dp': 'class-name', 'aria-label': 'שם המחלקה', size: 10 });
      const cCond = h('input', { type: 'text', dir: 'ltr', placeholder: 'audio · !audio · topics.count=1', 'data-dp': 'class-cond', 'aria-label': 'התנאי', size: 14 });
      const addC = h('button', { type: 'button', class: 'btn sm', 'data-w': true, 'data-dp': 'class-add' }, '＋');
      addC.addEventListener('click', () => {
        const nm = cName.value.trim(), cond = cCond.value.trim();
        try { I().checkClass(nm); } catch (e) { return this.note(e.message, true); }
        if (!cond) return this.note('כותבים תנאי: שדה, !שדה, שדה=ערך או שדה.count=N');
        if (/[;:]/.test(cond)) return this.note('בתנאי אין ; או :', true);
        if (/[A-Z]/.test(nm)) { const cur = a('data-kol-class'); return this.setAttrs(n, [['data-kol-class', (cur ? cur.replace(/[;\s]+$/, '') + '; ' : '') + nm + ': ' + cond]], 'קשירה · מחלקה ' + nm + ' כש-' + cond); }
        this.setAttrs(n, [['data-kol-class-' + nm, cond]], 'קשירה · מחלקה ' + nm + ' כש-' + cond);
      });
      kids.push(this.row('מחלקה ___ כש-___', cName, cCond, addC), h('p', { class: 'hint' }, 'שם עם אות גדולה נכתב בצורה הכללית data-kol-class="שם: תנאי" (kol-data.js 1.4.0).'));
      // 5. חזור לכל
      const eachSel = this.sel(this.fieldOpts(name, f => ['list', 'topics'].includes(f.type), '— לא חוזר'), a('data-kol-each') || '', { 'data-dp': 'each', 'aria-label': 'חזור לכל' });
      eachSel.addEventListener('change', () => this.setAttrs(n, [['data-kol-each', eachSel.value || null]], 'קשירה · חזור לכל ' + (eachSel.value || '—') + ' · ' + this.c.label(n)));
      kids.push(this.row('חזור לכל', eachSel), h('p', { class: 'hint' }, 'בתוך החזרה: {label}, {id}, או "." לערך עצמו.'));
      // 6. נגן
      if (/^(button|a|span|div)$/.test(n.tag) || a('data-kol-play') != null) {
        const pSel = this.sel(this.fieldOpts(name, f => f.type === 'audio', '— אינו נגן'), a('data-kol-play') != null ? (a('data-kol-play') || 'audio') : '', { 'data-dp': 'play', 'aria-label': 'משמיע את' });
        pSel.addEventListener('change', () => this.setAttrs(n, [['data-kol-play', pSel.value || null]], 'קשירה · נגן ' + (pSel.value || '—')));
        kids.push(this.row('לחיצה משמיעה את', pSel));
      }
      const st = this.lv.scope === 'word' ? 'עכשיו: "המילה הזו" — עריכה במקום נשמרת בטבלה' : 'עכשיו: "התבנית" — כל שינוי חל על כל המילים';
      return this.group('📊 נתונים · קשירה (' + name + ')', h('p', { class: 'hint', 'data-dp-scope': this.lv.scope }, st), ...kids);
    }
    condRow(label, name, cur, set, key) {
      const o = parseCond(cur), raw = cur && !makeCond(o) ? cur : null;
      const op = this.sel([['', 'תמיד'], ['has', 'יש ערך ב…'], ['no', 'אין ערך ב…'], ['eq', '… שווה ל'], ['ne', '… שונה מ'], ['count', 'מספר הערכים ב… ='], ...(raw ? [['raw', 'כמו שכתוב: ' + raw]] : [])], raw ? 'raw' : o.op, { 'data-dp': key + '-op', 'aria-label': label });
      const f = this.sel(this.fieldOpts(name, null, 'השדה…'), o.f || '', { 'data-dp': key + '-field', 'aria-label': 'השדה' });
      const v = h('input', { type: 'text', value: o.v || '', dir: 'auto', size: 8, 'data-dp': key + '-value', 'aria-label': 'הערך', hidden: !['eq', 'ne', 'count'].includes(o.op) });
      const go = () => { if (op.value === 'raw') return; v.hidden = !['eq', 'ne', 'count'].includes(op.value); f.hidden = !op.value; if (op.value && !f.value) return; const next = makeCond({ op: op.value, f: f.value, v: v.value }); if (next !== (cur || '')) set(next); };
      op.addEventListener('change', go); f.addEventListener('change', go); v.addEventListener('change', go);
      f.hidden = !o.op;
      return this.row(label, op, f, v);
    }
    containerSec(n) {
      const a = x => this.attr(n, x), name = a('data-kol-list'), kids = [];
      this.loadLists();
      const lists = this.listsMeta || [{ key: 'words', label: 'מילים' }];
      const src = this.sel(lists.map(l => [l.key, l.label + ' (' + l.key + ')']).concat(lists.some(l => l.key === name) ? [] : [[name, name]]), name, { 'data-dp': 'list-src', 'aria-label': 'מקור הנתונים' });
      src.addEventListener('change', () => this.setAttrs(n, [['data-kol-list', src.value]], 'רשימה · מקור ' + src.value));
      kids.push(this.row('מקור הנתונים', src));
      const file = h('input', { type: 'text', dir: 'ltr', value: a('data-kol-src') || '', placeholder: 'data/' + name + '.json', 'data-dp': 'list-file', 'aria-label': 'קובץ הנתונים' });
      file.addEventListener('change', () => { const v = file.value.trim(); if (v && /^(javascript|data|vbscript):/i.test(v)) return this.note('כתובת לא תקינה', true); this.setAttrs(n, [['data-kol-src', v || null]], 'רשימה · קובץ ' + (v || 'ברירת מחדל')); });
      kids.push(this.row('קובץ אחר', file), h('p', { class: 'hint' }, 'נתיב בתוך הפרויקט. כתובת חיצונית — נטענת רק בתצוגה החיה ואצל הגולש; בקנבס הרשימה ריקה.'));
      const sort = a('data-kol-sort') || 'manual', fs = this.fieldsOf(name);
      const sSel = this.sel([['manual', 'ידני (הסדר בטבלה)'], ...fs.flatMap(f => [[f.key, f.label + ' ↑'], ['-' + f.key, f.label + ' ↓']]), ['id', 'מזהה ↑'], ['-id', 'מזהה ↓']], sort, { 'data-dp': 'sort', 'aria-label': 'מיון' });
      sSel.addEventListener('change', () => this.setAttrs(n, [['data-kol-sort', sSel.value]], 'רשימה · מיון ' + sSel.value));
      const lSel = this.sel(LANGS, a('data-kol-sort-lang') || '', { 'data-dp': 'sort-lang', 'aria-label': 'שפת המיון' });
      lSel.addEventListener('change', () => this.setAttrs(n, [['data-kol-sort-lang', lSel.value || null]], 'רשימה · שפת המיון ' + (lSel.value || 'he')));
      kids.push(this.row('מיון', sSel), this.row('שפת המיון', lSel));
      const page = h('input', { type: 'number', min: 0, max: 5000, value: a('data-kol-page') || '', placeholder: 'הכול', 'data-dp': 'page', 'aria-label': 'כמה בכל טעינה', size: 5 });
      page.addEventListener('change', () => { const v = parseInt(page.value, 10); this.setAttrs(n, [['data-kol-page', v > 0 ? String(Math.min(5000, v)) : null]], 'רשימה · בכל טעינה ' + (v > 0 ? v : 'הכול')); });
      kids.push(this.row('כמה בכל טעינה', page, h('span', { class: 'hint' }, ' (בקנבס: 12, ו"הצג עוד" בסרגל)')));
      const lay = (kind, sets) => h('button', { type: 'button', class: 'btn sm', 'data-w': true, 'data-dp-layout': kind, onclick: () => this.layout(n, sets, kind) }, kind);
      kids.push(this.row('פריסת כרטיסים', lay('רשת', [['display', 'grid'], ['grid-template-columns', 'repeat(auto-fill, minmax(240px, 1fr))'], ['gap', '16px']]),
        lay('רשימה', [['display', 'flex'], ['flex-direction', 'column'], ['gap', '12px']]), lay('עמודות', [['display', 'block'], ['columns', '3 240px'], ['column-gap', '16px']])),
        h('p', { class: 'hint' }, 'עמודות, רווח, יישור — בלשונית "📐 מתקדם", כמו בכל רובריקה.'));
      return this.group('📊 נתונים · הרשימה ' + name, ...kids);
    }
    layout(n, sets, kind) {
      const t = this.styler.targets(n)[0]; if (!t) return this.note('אין יעד לעיצוב', true);
      this.write(new Map([[n, sets.map(([p, v]) => [p, v, t])]]), 'פריסת כרטיסים · ' + kind + ' · ' + this.c.label(n));
    }
    aroundSec(n, which) {
      const kids = [], names = [...new Set(this.lv.lists.map(L => L.name).concat(this.listsMeta ? this.listsMeta.map(l => l.key) : []))];
      for (const at of which) {
        const v = this.attr(n, at), isF = at === 'data-kol-filter', [lname, field] = isF ? v.split(':') : [v, ''];
        const lsel = this.sel(names.concat(names.includes(lname) ? [] : [lname]).map(x => [x, x]), lname, { 'data-dp': 'of-list', 'aria-label': 'של הרשימה' });
        lsel.addEventListener('change', () => this.setAttrs(n, [[at, isF ? lsel.value + ':' + field : lsel.value]], 'רכיב רשימה · של ' + lsel.value));
        kids.push(this.row({ 'data-kol-search': 'חיפוש של הרשימה', 'data-kol-search-scope': '"הרחב" של הרשימה', 'data-kol-filter': 'סינון של הרשימה', 'data-kol-count': 'מונה של הרשימה', 'data-kol-more': '"טען עוד" של הרשימה', 'data-kol-empty': '"אין תוצאות" של הרשימה' }[at], lsel));
        if (isF) {
          const fsel = this.sel(this.fieldOpts(lname, f => ['topics', 'choice', 'list', 'text'].includes(f.type), 'השדה…'), field, { 'data-dp': 'filter-field', 'aria-label': 'לפי השדה' });
          fsel.addEventListener('change', () => this.setAttrs(n, [[at, lname + ':' + fsel.value]], 'סינון · לפי ' + fsel.value));
          const ac = h('input', { type: 'text', dir: 'ltr', value: this.attr(n, 'data-kol-active-class') || '', placeholder: 'is-active', size: 10, 'data-dp': 'active-class', 'aria-label': 'מחלקת הכפתור הפעיל' });
          ac.addEventListener('change', () => { const x = ac.value.trim(); if (x) { try { I().checkClass(x); } catch (e) { return this.note(e.message, true); } } this.setAttrs(n, [['data-kol-active-class', x || null]], 'סינון · מחלקת הפעיל ' + (x || '—')); });
          kids.push(this.row('לפי השדה', fsel), this.row('מחלקת הכפתור הפעיל', ac), h('p', { class: 'hint' }, 'הכפתור הקבוע ("הכול") — כפתור רגיל עם data-kol-filter-value=""; תבנית הכפתור — ה-<template> שבמכל. מצב "מסונן" — בסרגל הרשימה.'));
        }
        if (at === 'data-kol-search' || at === 'data-kol-search-scope') {
          const cur = (this.attr(n, 'data-kol-search-fields') || 'word').split(/\s+/).filter(Boolean), box = h('div', { class: 'dp-checks', 'data-dp': 'search-fields' });
          for (const f of this.fieldsOf(lname)) { const ch = h('input', { type: 'checkbox', value: f.key, checked: cur.includes(f.key) }); ch.addEventListener('change', () => { const keys = [...box.querySelectorAll('input:checked')].map(x => x.value); this.setAttrs(n, [['data-kol-search-fields', keys.join(' ') || 'word']], 'חיפוש · בשדות ' + (keys.join(' ') || 'word')); }); box.append(h('label', {}, ch, ' ' + f.label)); }
          kids.push(this.row('באילו שדות', box));
        }
        if (at === 'data-kol-count') {
          const inp = h('input', { type: 'text', dir: 'auto', value: this.attr(n, 'data-kol-count-text') || '{shown}', 'data-dp': 'count-text', 'aria-label': 'הנוסח של המונה' });
          inp.addEventListener('change', () => this.setAttrs(n, [['data-kol-count-text', inp.value]], 'מונה · ' + inp.value));
          const ins = k => h('button', { type: 'button', class: 'btn sm', 'data-w': true, onclick: () => { inp.value += '{' + k + '}'; inp.dispatchEvent(new Event('change')); } }, '{' + k + '}');
          kids.push(this.row('הנוסח', inp, ins('shown'), ins('total')));
        }
        if (at === 'data-kol-empty') kids.push(h('button', { type: 'button', class: 'btn sm', 'data-dp': 'show-empty', onclick: () => { this.lv.mode(lname).state = 'empty'; this.lv.redraw(); } }, 'הצג במצב "אין תוצאות"'));
        if (at === 'data-kol-more') kids.push(h('button', { type: 'button', class: 'btn sm', 'data-dp': 'show-more', onclick: () => { this.lv.mode(lname).state = 'more'; this.lv.redraw(); } }, 'הצג במצב "אחרי טען עוד"'));
      }
      return this.group('📊 נתונים · רכיב רשימה', ...kids, h('p', { class: 'hint' }, 'הכיתוב — טקסט באלמנט: לחיצה כפולה בקנבס.'));
    }
    // ── הספרייה: שדות ורכיבי רשימה ────────────────────────
    mountLib() {
      if (!this.libHost) return;
      this.lib = h('div', { class: 'ed-lib dp-lib', 'data-dp-lib': true });
      this.libHost.prepend(this.lib);
      this.drawLib();
    }
    libBtn(it) {
      const b = h('button', { type: 'button', class: 'ed-lib-item', role: 'listitem', 'data-lib': it.id, title: (it.title || '') + 'לחיצה — אחרי הנבחר · גרירה — לכל מקום' + (it.field ? ', או על אלמנט בכרטיס — קושרת אותו' : '') }, h('span', { 'aria-hidden': 'true' }, it.icon), ' ', it.label, it.internal ? h('small', { class: 'dp-int' }, ' · לא יופיע אצל הגולש') : null);
      let p0 = null; const bd = this.build;
      b.addEventListener('pointerdown', e => { if (e.button > 0) return; p0 = { x: e.clientX, y: e.clientY, id: e.pointerId }; try { b.setPointerCapture(e.pointerId); } catch { /* */ } });
      b.addEventListener('pointermove', e => { if (p0 && !bd.drag.active && Math.hypot(e.clientX - p0.x, e.clientY - p0.y) > 4) { if (!this.canWrite()) { p0 = null; return; } try { b.releasePointerCapture(p0.id); } catch { /* */ } p0 = null; bd.drag.start({ lib: it }, e.clientX, e.clientY); } });
      b.addEventListener('pointerup', () => { p0 = null; });
      b.addEventListener('click', () => { if (bd.swallow) return; if (it.run) return it.run(); bd.addHere(it); });
      return b;
    }
    drawLib() {
      if (!this.lib) return;
      const name = this.primary(), key = name + '|' + (name ? this.fieldsOf(name).map(f => f.key + f.type + f.published).join() : '');
      if (this.lib._key === key) return; this.lib._key = key;
      const out = [];
      if (name) {
        const fs = this.fieldsOf(name);
        out.push(h('h4', { class: 'dp-h' }, '📊 השדות של ' + name), h('p', { class: 'hint' }, 'גוררים אל הכרטיס — נוצר אלמנט קשור; על אלמנט קיים — קושרת אותו.'),
          h('div', { class: 'ed-lib-grid', role: 'list', 'aria-label': 'השדות', 'data-dp-fields': name }, fs.map(f => this.libBtn({ id: 'field-' + f.key, icon: ICON[f.type] || '•', label: f.label, title: TYPE[f.type] ? TYPE[f.type] + ' · ' : '', field: f.key, ftype: f.type, internal: f.published === false, html: fieldHtml(f) }))));
        const L = this.lv.lists.find(x => x.name === name), filterF = L && L.ds && L.ds.state && L.ds.state.labels.topics ? 'topics' : (this.fieldsOf(name).find(f => f.type === 'choice') || {}).key;
        out.push(h('h4', { class: 'dp-h' }, '📋 רכיבי רשימה'), h('div', { class: 'ed-lib-grid', role: 'list', 'aria-label': 'רכיבי רשימה' }, aroundItems(name, filterF).map(it => this.libBtn(it))));
      }
      out.push(h('div', { class: 'ed-lib-grid', role: 'list', 'aria-label': 'רשימה' }, this.libBtn({ id: 'kl-list', icon: '📋', label: 'רשימה (מקור נתונים)', run: () => this.addList() })));
      this.lib.replaceChildren(...out);
    }
    /** "רשימה" (N3): בוחרים מקור; נוצר הקטע של N3 §2.5 אחרי האלמנט הנבחר, וה-CSS שלו — עסקה אחת. */
    async addList(at) {
      await this.loadLists();
      const lists = (this.listsMeta || []).filter(l => !l.words);
      if (!this.listsMeta) return this.note('דורש רשימות נוספות (N3)', true);
      const pick = h('select', { 'aria-label': 'מקור', 'data-dp': 'add-list' }, lists.map(l => h('option', { value: l.key }, l.label + ' (' + l.key + ', ' + l.count + ')')), h('option', { value: '__new' }, '➕ רשימה חדשה…'));
      const go = h('button', { type: 'button', class: 'btn primary', 'data-dp': 'add-list-go' }, 'הוסף כאן');
      const dlg = this.sheet('רשימה בעמוד', h('p', { class: 'hint' }, 'נוסף קטע HTML רגיל — כותרת, חיפוש, סינון, מונה, הכרטיסים, "טען עוד" ו"אין תוצאות" — אחרי האלמנט הנבחר (ברובריקה נבחרת — בסופה).'), pick, go);
      go.addEventListener('click', async () => {
        if (pick.value === '__new') { dlg.close(); return this.note('רשימה חדשה נוצרת ב-📚 נתונים ← ➕ רשימה (N3); אחר כך היא מופיעה כאן'); }
        const l = lists.find(x => x.key === pick.value); if (!l) return;
        let fs; try { fs = (await this.api('fields', undefined, '&list=' + encodeURIComponent(l.key))).fields || []; } catch (e) { return this.note(e.message, true); }
        dlg.close();
        let html; try { html = listSnippet(l, fs); } catch (e) { return this.note(e.message, true); }
        const it = { id: 'list-' + l.key, label: 'רשימה ' + l.label, html };
        const ok = at ? this.build.add(it, at.par, at.ref) : this.build.addHere(it);
        if (ok === false) return;
        const cssPath = this.pagePath(), doc = cssPath && this.docOf(cssPath), block = listCss(l.key);
        if (doc && !doc.text().includes('.kol-list-' + l.key + ' {')) { this.writeOps([{ doc, patches: [{ from: doc.text().length, to: doc.text().length, text: (doc.text().endsWith('\n') ? '' : '\n') + block }] }], 'הוסף את הרשימה ' + l.label + ' · CSS'); this.joinTx && this.joinTx(2, 'הוסף את הרשימה ' + l.label); }
        this.note('נוספה הרשימה ' + l.label + (this.lv.c.doc && ![...this.lv.c.doc.querySelectorAll('script[src]')].some(s => /kol-data\.js/.test(s.getAttribute('src'))) ? ' — לעמוד אין <script src="js/kol-data.js">: בתצוגה החיה הרשימה לא תתמלא עד שיתווסף (📁 / פתח בקוד)' : ''), false, { label: 'בטל', run: this.undo });
      });
    }
    // ── גרירת שדה על אלמנט קיים — קושרת אותו ─────────────
    wrapDrop() {
      const bd = this.build; if (!bd || !bd.drag) return;
      const dr = bd.drag, track = dr.track.bind(dr), drop = bd.drop.bind(bd);
      dr.track = (x, y) => { this.pt = { x, y }; return track(x, y); };
      bd.drop = async (src, t) => {
        if (src.lib && src.lib.run) return src.lib.run(t);
        if (src.lib && src.lib.field && this.pt) { const n = this.bindTarget(this.pt.x, this.pt.y); if (n) return this.bindTo(n, src.lib); }
        return drop(src, t);
      };
    }
    /** האלמנט בכרטיס שמתחת לסמן, כשהסמן במרכזו (60% הפנימיים) — יעד לקשירה; אחרת null (הוספה לפני / אחרי). */
    bindTarget(x, y) {
      const c = this.c, fr = c.frame.getBoundingClientRect(), fx = (x - fr.left) / c.scale, fy = (y - fr.top) / c.scale;
      let el = null; try { el = c.doc.elementFromPoint(fx, fy); } catch { el = null; }
      if (!el) return null;
      this.lv.focusAt(el);
      const n = c.nodeOf(el); if (!n || !this.listOf(n) || n.tag === 'template') return null;
      if (n.kids.length && !/^(a|button|ul|ol|li)$/.test(n.tag)) return null;
      const e = c.elOf(n), r = e && e.getBoundingClientRect(); if (!r) return null;
      const ix = (fx - r.left) / (r.width || 1), iy = (fy - r.top) / (r.height || 1);
      return ix > 0.2 && ix < 0.8 && iy > 0.2 && iy < 0.8 ? n : null;
    }
    bindTo(n, it) {
      const k = it.field, t = it.ftype, lab = 'קשירה · ' + this.c.label(n) + ' ← ' + it.label;
      if (n.tag === 'img') return this.setAttrs(n, [['data-kol-attr-src', k], ...(K().attrOf(n, 'data-kol-attr-alt') ? [] : [['data-kol-attr-alt', '{word}']])], lab);
      if (n.tag === 'audio' || n.tag === 'video' || n.tag === 'source') return this.setAttrs(n, [['data-kol-attr-src', k]], lab);
      if (n.tag === 'a' && t === 'url') return this.setAttrs(n, [['data-kol-attr-href', k]], lab);
      if (t === 'audio') return this.setAttrs(n, [['data-kol-play', k]], lab);
      if (t === 'topics' || t === 'list') { const li = n.tag === 'li' ? n : n.kids.find(x => x.tag === 'li'); if (li) return this.setAttrs(li, [['data-kol-each', k], ['data-kol-text', t === 'topics' ? 'label' : '.']], lab); }
      if (t === 'image') return this.note('תמונה נקשרת ל-<img> — גוררים אל תמונה, או אל מקום ריק ונוצרת אחת', true);
      return this.setAttrs(n, t === 'rich' ? [['data-kol-html', k], ['data-kol-text', null]] : [['data-kol-text', k], ['data-kol-html', null]], lab);
    }
    // ── "✏️ פתח את המילה" — חלון עם כל השדות ─────────────
    openWord(L, item) {
      const fs = this.fieldsOf(L.name), rows = [];
      const keys = fs.map(f => f.key).concat(Object.keys(item).filter(k => k !== 'id' && !k.startsWith('__') && !fs.some(f => f.key === k)));
      for (const key of keys) {
        const f = fs.find(x => x.key === key) || { key, label: key, type: Array.isArray(item[key]) ? 'list' : 'text' }, v = item[key];
        if (f.type === 'topics' || f.type === 'list' || f.type === 'image' || f.type === 'audio') { rows.push(h('div', { class: 'ct-row' }, h('span', { class: 'ct-k' }, f.label), h('span', { dir: 'auto' }, Array.isArray(v) ? v.join(', ') : String(v == null ? '' : v)))); continue; }
        const inp = f.type === 'rich' || f.type === 'longtext' ? h('textarea', { rows: 3, dir: 'auto', 'data-dp-word': key }) : h('input', { type: 'text', dir: 'auto', 'data-dp-word': key });
        inp.value = v == null ? '' : String(v);
        inp.addEventListener('change', () => this.lv.setValue(L, item, key, f.type === 'number' ? (inp.value === '' ? null : Number(inp.value)) : inp.value));
        rows.push(h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, f.label + (f.published === false ? ' · פנימי' : '')), inp));
      }
      this.sheet('✏️ ' + (item.word || '#' + item.id), h('p', { class: 'hint' }, 'כל שינוי נשמר בטבלה (📚) — כמו עריכה בתא. הקלטה, תמונה ונושאים — מהכרטיס בקנבס ("המילה הזו"), או ב-📚 נתונים.'), ...rows,
        h('a', { href: 'index.html#/words', target: '_blank', rel: 'noopener', class: 'btn sm' }, '📚 פתח בטבלת המילים'));
    }
  }
  function listCss(k) {
    const c = 'kol-list-' + k;
    return '\n/* הרשימה ' + k + ' — נוסף מהעורך החזותי. אפשר לשנות הכול */\n.' + c + ' { margin: 32px 0; }\n.' + c + '__search { width: 100%; max-width: 420px; padding: 8px 12px; font: inherit; border: 1px solid #c9c9d1; border-radius: 8px; }\n'
      + '.' + c + '__filter { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0; }\n.' + c + '__filter button { padding: 4px 12px; border: 1px solid #c9c9d1; border-radius: 999px; background: none; font: inherit; cursor: pointer; }\n'
      + '.' + c + '__filter .is-active { background: var(--color-accent, #a3322a); border-color: transparent; color: #fff; }\n.' + c + '__count { color: #666; font-size: .9em; }\n.' + c + '__items { display: grid; gap: 12px; }\n'
      + '.' + c + '__item { padding: 12px 16px; border: 1px solid #e3e3ea; border-radius: 10px; }\n.' + c + '__item h3 { margin: 0 0 4px; }\n.' + c + '__item img { max-width: 100%; border-radius: 8px; }\n.' + c + '__more { margin-top: 12px; }\n';
  }

  window.KolDataPanel = { Data, parseCond, makeCond, fieldHtml, aroundItems, listSnippet };
})();
