/**
 * קול מרוקו — 🎨 עורך (פרוסה V11): רשימת הנתונים בקנבס — הכרטיסים האמיתיים (03 §9; 02 §6, §8).
 *
 * הקנבס אינו מריץ את js/kol-data.js (אין בו סקריפטים), ולכן העורך מצייר את הכרטיסים בעצמו, במנוע הקשירה שלו (bind.js — אותה
 * סמנטיקה, נבדק מול kol-data.js), מתוך ה-<template> של כל data-kol-list ומהקובץ הווירטואלי של הנתונים (data/words.json, בגרסה
 * של העורך — כולל שדות פנימיים). הכרטיסים הם **צמתים של העורך**: מסומנים data-kol-canvas="card", אינם חלק מהמיפוי של
 * הקנבס (kidsOf מדלג עליהם), ולעולם אינם נכתבים לקובץ. כל אלמנט בכרטיס ממופה לצומת שבתבנית (בתוך data-kol-each — הצומת שחוזר),
 * ולכן בחירה בכרטיס בוחרת את הצומת בתבנית, וכל מה שהעורך יודע (V2–V10) חל עליו; אחרי כל שינוי — כל הכרטיסים מצוירים מחדש.
 *
 * "המילה הזו": ערך של שדה נערך במקום (contenteditable על הכרטיס בקנבס בלבד), ונשמר דרך המנוע של N1 — שמירת הקובץ הווירטואלי
 * (pf_write: "מה ישתנה" ← בדיקה שמשתנה בדיוק שדה אחד במילה אחת ← "החל" עם האסימון וה-rev). הביטול — אותה דרך, עם הערך הקודם,
 * כעסקה בהיסטוריה של העורך (Ctrl+Z). כל טקסט מהנתונים — textContent; HTML — רק ב-data-kol-html, כמו kol-data.js.
 */
(function () {
  'use strict';
  const OWNED = 'data-kol-canvas', CARD = 'card', CANVAS_PAGE = 12;
  const mk = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  const AR = /[؀-ۿ]/, LAT = /[A-Za-zÀ-ɏ]/;
  /** מקרי הקצה של "מילה לתצוגה" (01-gap 9.8). כל אחד: פריטים ← הפריט הנבחר (או undefined). */
  const PICKS = [
    ['', 'מילה לתצוגה: לפי הסדר'],
    ['longest', 'הפירוש הארוך ביותר', (it, B) => it.slice().sort((a, b) => B.plain(b.meaning).length - B.plain(a.meaning).length)[0]],
    ['noaudio', 'בלי הקלטה', it => it.find(x => !x.audio)],
    ['mosttopics', 'הכי הרבה נושאים', it => it.slice().sort((a, b) => (b.topics || []).length - (a.topics || []).length)[0]],
    ['notopics', 'בלי נושאים', it => it.find(x => !(x.topics || []).length)],
    ['arabic', 'בערבית', it => it.find(x => AR.test(String(x.word || '')))],
    ['latin', 'בלטינית', it => it.find(x => LAT.test(String(x.word || '')) && !AR.test(String(x.word || '')))],
    ['empty', 'שדה ריק: …'],
    ['search', 'לפי חיפוש: …'],
  ];
  const STATES = [['', 'רגיל'], ['filter', 'מסונן לפי נושא…'], ['search', 'חיפוש…'], ['empty', 'אין תוצאות'], ['more', 'אחרי "טען עוד"'], ['playing', 'מתנגן']];

  class ListView {
    /** opt: { K, B, load(path) → {data, rev, id, internal}, save(id, rev, text) → {rev, text}, resolve(ref) → נתיב | null, note, undo, pushFx(label, fx),
     *   strip (מכל לסרגל), kd() → { modified, why }, fields(list) → [{key,label,type,published}], pick(kind), openWord(info, item), canWrite() } */
    constructor(canvas, opt) {
      Object.assign(this, opt); this.c = canvas;
      this.d2m = new Map(); this.byNode = new Map(); this.lists = []; this.cache = new Map(); this.focus = null; this.more = 0;
      this.modes = new Map(); this.scope = 'tpl'; this.twins = []; this.timing = 0; this.busy = false; this.dialogState = new Map();
      this.bar = mk('div', 'lv-bar'); this.bar.hidden = true; this.bar.setAttribute('data-lv-bar', ''); this.bar.setAttribute('role', 'group'); this.bar.setAttribute('aria-label', 'התבנית או המילה הזו');
      canvas.layer.append(this.bar);
      this.decode = s => { const t = document.createElement('textarea'); t.innerHTML = s; return t.value; };   // ישויות — בטקסט של המעטפת בלבד, לא לקנבס
    }
    // ── נתונים ───────────────────────────────────────────
    pathOf(info) {
      const src = info.src;
      if (src && /^([a-z][a-z0-9+.-]*:|\/\/)/i.test(src)) return { external: src };
      const p = this.resolve(src || 'data/' + info.name + '.json');
      return p ? { path: p } : { external: src || '' };
    }
    data(info) {
      const where = this.pathOf(info);
      if (where.external != null) return { status: 'error', why: 'מקור הנתונים הוא כתובת חיצונית (' + where.external + ') — נטען רק בתצוגה החיה ואצל הגולש; בקנבס הרשימה ריקה' };
      let d = this.cache.get(where.path);
      if (!d) {
        d = { status: 'loading', path: where.path }; this.cache.set(where.path, d);
        Promise.resolve().then(() => this.load(where.path)).then(r => {
          Object.assign(d, { status: 'ok', json: r.data, rev: r.rev, id: r.id, internal: r.internal || [], state: { items: this.B.itemsOf(r.data), labels: this.B.labelsOf(r.data) } });
        }, e => { Object.assign(d, { status: 'error', why: e.message || String(e) }); }).then(() => { if (this.c.m) { this.draw(); this.c.draw(); this.drawStrip(); } });
      }
      return d;
    }
    // ── ציור ─────────────────────────────────────────────
    mode(name) { if (!this.modes.has(name)) this.modes.set(name, { state: '', value: '', pick: '', pickArg: '' }); return this.modes.get(name); }
    /** מסיר את הכרטיסים מה-DOM (לפני כל סנכרון של הקנבס), ומנקה את המיפוי. */
    clear() {
      const end = this.valueEdit; if (end) end.abort();
      for (const l of this.lists) for (const e of l.owned) e.remove();
      if (this.style) this.style.remove();
      for (const r of this.restore || []) r();
      this.restore = []; this.style = null; this.lists = []; this.d2m = new Map(); this.byNode = new Map();
    }
    /** כל המכלים שבעמוד ← כרטיסים, כפתורי סינון ומצבים. נקרא אחרי כל שינוי בקנבס. */
    draw() {
      const t0 = performance.now(), c = this.c, K = this.K, B = this.B;
      this.clear();
      if (!c.m || !c.m.root || !c.doc || !c.doc.body) return;
      const lists = [], doc = c.doc, decode = this.decode;
      K.walk(c.m.root, n => {
        const a = K.attrOf(n, 'data-kol-list'); if (!a || !a.value) return;
        for (let p = n.parent; p; p = p.parent) if (p.tag === 'template') return;     // רשימה בתוך תבנית — לא בשלב הזה
        const tpl = n.kids.find(k => k.tag === 'template'), el = c.m2d.get(n), tplEl = tpl && c.m2d.get(tpl);
        lists.push({ n, el, tpl, tplEl, name: a.value, src: (K.attrOf(n, 'data-kol-src') || {}).value || '', owned: [], cards: [] });
      });
      this.lists = lists;
      let css = '';
      for (const L of lists) {
        if (!L.el || !L.tplEl || !L.tplEl.content) { L.status = 'notpl'; continue; }
        const d = this.data(L); L.status = d.status; L.why = d.why; L.ds = d;
        if (d.status !== 'ok') continue;
        const M = this.mode(L.name), st = d.state, attr = n => (x => (K.attrOf(n, x) || {}).value);
        const searchN = this.find(n => attr(n)('data-kol-search') === L.name), fields = (searchN && attr(searchN)('data-kol-search-fields')) || 'word';
        const filterN = this.find(n => (attr(n)('data-kol-filter') || '').split(':')[0] === L.name), filterField = filterN ? (attr(filterN)('data-kol-filter').split(':')[1] || '') : '';
        const query = M.state === 'search' ? M.value : '', fv = M.state === 'filter' ? M.value : '';
        let list = M.state === 'empty' ? [] : B.matched(st, { query, fields, filterField, filterValue: fv }, decode);
        list = B.sorted(list, attr(L.n)('data-kol-sort'), attr(L.n)('data-kol-sort-lang'));
        const pk = M.pick && PICKS.find(p => p[0] === M.pick);
        let first = null;
        if (pk && M.pick === 'empty') first = list.find(x => B.isEmpty(x[M.pickArg]));
        else if (pk && M.pick === 'search') { const q = B.plain(M.pickArg, decode); first = q ? list.find(x => B.haystack(st, x, 'word meaning', decode).includes(q)) : null; }
        else if (pk && pk[2]) first = pk[2](list, B);
        if (pk && M.pick && !first) L.pickMiss = true;
        if (first) list = [first, ...list.filter(x => x !== first)];
        const page = parseInt(attr(L.n)('data-kol-page'), 10) || 0, limit = M.state === 'more' ? (page || CANVAS_PAGE) * 2 : (page || Infinity);
        L.matched = list.length; L.total = st.items.length; L.page = page;
        const n = Math.min(list.length, limit, CANVAS_PAGE * (1 + this.more));
        L.drawn = n; L.items = list.slice(0, n);
        const frag = doc.createDocumentFragment();
        L.items.forEach((item, i) => {
          const roots = B.build(L.tplEl, [item], st, { copy: (x, o) => this.pair(x, o) });
          for (const r of roots) { r.setAttribute(OWNED, CARD); r.__lv = { L, i, item }; frag.append(r); L.owned.push(r); }
          L.cards.push(roots);
        });
        L.el.append(frag);
        // שדה פנימי (N2: לא מתפרסם) — מסומן בכרטיס: "לא יופיע אצל הגולש"
        if (d.internal && d.internal.length) {
          const keys = new Set(d.internal.map(x => x.key));
          for (const r of L.owned) for (const e of [r, ...r.querySelectorAll('[data-kol-text],[data-kol-html],[data-kol-if]')]) {
            const sp = e.getAttribute('data-kol-text') || e.getAttribute('data-kol-html') || (e.getAttribute('data-kol-if') || '').replace(/^!/, '');
            if (sp && B.fieldsIn(sp).some(k => keys.has(k))) { e.setAttribute('data-lv-internal', ''); e.setAttribute('title', 'שדה פנימי — לא יופיע אצל הגולש'); }
          }
          css += '[data-lv-internal]{outline:1px dashed #b45309!important;outline-offset:1px}\n';
        }
        // הנגן: מצב "מתנגן" — הכרטיס הראשון (המחלקה של data-kol-active-class, ו-aria-pressed — כמו markPlaying)
        if (M.state === 'playing' && L.cards[0]) for (const r of L.cards[0]) for (const b of [r, ...r.querySelectorAll('[data-kol-play]')]) if (b.hasAttribute('data-kol-play')) { b.setAttribute('aria-pressed', 'true'); const ac = b.getAttribute('data-kol-active-class'); if (ac) b.classList.add(ac); }
        // כפתורי הסינון מתבנית הכפתור
        if (filterN) {
          const fEl = c.m2d.get(filterN), fTpl = fEl && B.templateOf(fEl);
          if (fTpl && filterField) {
            const ff = doc.createDocumentFragment(), made = [];
            for (const layer of B.filterLayers(st, filterField)) for (const node of B.build(fTpl, [layer], st, { copy: (x, o) => this.pair(x, o) })) {
              if (!node.hasAttribute('data-kol-filter-value')) node.setAttribute('data-kol-filter-value', layer.value);
              node.setAttribute(OWNED, CARD); node.__lv = { L, i: -1, filter: true }; ff.append(node); L.owned.push(node); made.push(node);
            }
            fEl.append(ff);
            B.markActive(fEl, made, fv);
            if (fv) css += this.sel(filterN) + ' > [data-kol-filter-value=""]:not([' + OWNED + ']){outline:1px dashed rgba(0,0,0,.25)}\n';
          }
        }
        // המונה, "אין תוצאות", "טען עוד": לפי המצב — בכלל CSS של העורך (התכונות של העמוד אינן משתנות)
        const empty = L.matched === 0, moreOn = !!page && Math.min(L.matched, limit) < L.matched;
        this.each('data-kol-empty', L.name, sel => { css += sel + (empty ? '{display:block!important}' : '{display:none!important}') + '\n'; });
        this.each('data-kol-more', L.name, sel => { css += sel + (moreOn ? '' : '{display:none!important}') + '\n'; });
        if (searchN) { const se = c.m2d.get(searchN); if (se && 'value' in se) { const was = se.value; se.value = query; this.restore.push(() => { se.value = was; }); } }
        if (filterN && !fv) { /* "הכל" — כמו בעמוד */ }
      }
      // חלון (data-kol-dialog): "פתח בקנבס" עם המילה הנבחרת; ומצב הטופס (נשלח / שגיאה)
      for (const [name, st] of this.dialogState) {
        if (!st.open) continue;
        const dn = this.find(n => (K.attrOf(n, 'data-kol-dialog') || {}).value === name), de = dn && c.m2d.get(dn);
        if (!de) continue;
        css += this.sel(dn) + '{display:flex!important;visibility:visible!important}\n';
        const L = lists.find(x => x.items && x.items.length), item = st.item || (L && L.items[0]);
        if (item) for (const e of de.querySelectorAll('[data-kol-text]')) { if (e.childNodes.length) continue; e.textContent = this.B.fill([item], e.getAttribute('data-kol-text')); this.restore.push(() => { e.textContent = ''; }); }
        const done = st.form === 'done', err = st.form === 'error';
        for (const e of de.querySelectorAll('[data-kol-form-done]:not([data-kol-form]), [data-kol-form-error]:not([data-kol-form])')) {
          const n = c.nodeOf(e); if (!n) continue;
          css += this.sel(n) + ((e.hasAttribute('data-kol-form-done') ? done : err) ? '{display:block!important}' : '{display:none!important}') + '\n';
        }
      }
      if (css) { this.style = doc.createElement('style'); this.style.setAttribute(OWNED, 'lvstate'); this.style.textContent = css; (doc.head || doc.documentElement).append(this.style); }
      for (const [e, n] of this.d2m) { if (!this.byNode.has(n)) this.byNode.set(n, []); this.byNode.get(n).push(e); }
      if (this.focus && !lists.some(L => L.name === this.focus.name && L.cards[this.focus.i])) this.focus = null;
      this.timing = performance.now() - t0;
    }
    find(fn) { let hit = null; this.K.walk(this.c.m.root, n => { if (!hit && fn(n)) hit = n; }); return hit; }
    each(attr, name, fn) { this.K.walk(this.c.m.root, n => { const a = this.K.attrOf(n, attr); if (a && a.value === name) fn(this.sel(n)); }); }
    sel(n) { return this.c.selOf(n); }
    /** עותק ← הצומת בתבנית (המקור הוא אלמנט בתבנית, או אלמנט בכרטיס שכבר ממופה). */
    pair(x, o) {
      const n = this.d2m.get(o) || this.c.d2m.get(o); if (n) this.d2m.set(x, n);
      const xs = x.children, os = o.children; for (let i = 0; i < Math.min(xs.length, os.length); i++) this.pair(xs[i], os[i]);
    }
    // ── מיפוי לקנבס ──────────────────────────────────────
    /** הכרטיס של אלמנט בקנבס: { L, i, item } או null. */
    cardOf(d) { for (let e = d; e && e.nodeType === 1; e = e.parentElement) if (e.__lv) return e.__lv; return null; }
    /** הלחיצה בכרטיס — הוא הכרטיס "הנוכחי" (המסגרת החזקה, הפאנל, הידיות). */
    focusAt(d) { const k = this.cardOf(d); if (k && k.i >= 0) this.focus = { name: k.L.name, i: k.i }; return k; }
    covers(tplNode) { return this.lists.some(L => L.tpl === tplNode && L.cards.length); }
    inList(n) { for (let p = n; p; p = p.parent) if (p.tag === 'template') { const L = this.lists.find(x => x.tpl === p); return L && L.cards.length ? L : null; } return null; }
    /** האלמנט בקנבס שמייצג צומת בתבנית: בכרטיס הנוכחי, ואם אין בו — בראשון שיש. התבנית עצמה — הכרטיס הראשון. */
    elOf(n) {
      const L = this.lists.find(x => x.tpl === n && x.cards.length); if (L) { const i = this.focus && this.focus.name === L.name ? this.focus.i : 0; return (L.cards[i] || L.cards[0])[0] || null; }
      const els = this.byNode.get(n); if (!els || !els.length) return null;
      if (this.focus) { const hit = els.find(e => { const k = this.cardOf(e); return k && k.L.name === this.focus.name && k.i === this.focus.i; }); if (hit) return hit; }
      return els[0];
    }
    els(n) { return this.byNode.get(n) || []; }
    item(n) { const e = this.elOf(n), k = e && this.cardOf(e); return k && k.item ? { L: k.L, item: k.item, el: e } : null; }
    // ── מסגרות: אותו חלק בכל הכרטיסים (חלשה), והסרגל "התבנית / המילה הזו" ──
    drawBoxes() {
      const c = this.c, n = c.selected, hov = c.hovered && c.hovered !== n ? c.hovered : null, want = [];
      const H = c.host.clientHeight, cur = n && this.elOf(n);
      for (const [x, cls] of [[n, 'lv-twin'], [hov, 'lv-twin lv-hov']]) {
        if (!x || !this.byNode.has(x)) continue;
        const me = this.elOf(x);
        for (const e of this.els(x)) { if (e === me || want.length > 120) continue; const r = this.rect(e); if (r && r.top < H && r.top + r.height > 0) want.push({ r, cls }); }
      }
      while (this.twins.length < want.length) { const b = mk('div', 'lv-twin'); b.setAttribute('aria-hidden', 'true'); c.layer.append(b); this.twins.push(b); }
      this.twins.forEach((b, i) => { const w = want[i]; b.hidden = !w; if (w) { b.className = w.cls; b.style.cssText = 'left:' + w.r.left + 'px;top:' + w.r.top + 'px;width:' + Math.max(w.r.width, 2) + 'px;height:' + Math.max(w.r.height, 2) + 'px'; } });
      this.drawBar(n, cur);
    }
    rect(e) { if (!e.getClientRects().length) return null; const r = e.getBoundingClientRect(), s = this.c.scale; return { left: this.c.ox + r.left * s, top: r.top * s, width: r.width * s, height: r.height * s }; }
    /** מה האלמנט קושר: { kind: text|html|play|each|attr, field, spec } או null (חלק מהתבנית בלבד). */
    binding(n) {
      const K = this.K, a = x => (K.attrOf(n, x) || {}).value;
      if (a('data-kol-each') != null) return { kind: 'each', field: a('data-kol-each').split('.')[0] };
      if (a('data-kol-html') != null) return { kind: 'html', spec: a('data-kol-html'), field: this.B.fieldsIn(a('data-kol-html'))[0] };
      if (a('data-kol-text') != null) { const sp = a('data-kol-text'), fs = this.B.fieldsIn(sp); return { kind: 'text', spec: sp, field: fs.length === 1 && sp.indexOf('{') < 0 ? fs[0] : null, mixed: sp.indexOf('{') >= 0, fields: fs }; }
      if (a('data-kol-play') != null) return { kind: 'play', field: a('data-kol-play') || 'audio' };
      const src = a('data-kol-attr-src'); if (src != null && /^(audio|source|img|video)$/.test(n.tag)) return { kind: n.tag === 'img' ? 'image' : 'play', field: this.B.fieldsIn(src)[0] };
      for (let p = n.parent; p; p = p.parent) { if (p.tag === 'template') break; const e = K.attrOf(p, 'data-kol-each'); if (e) return { kind: 'each', field: e.value.split('.')[0], inner: true }; }
      return null;
    }
    drawBar(n, cur) {
      const b = this.bar, L = n && this.inList(n), k = cur && this.cardOf(cur);
      if (!L || !k || !k.item || this.c.edit?.active || this.valueEdit) { b.hidden = true; return; }
      const r = this.rect(cur); if (!r) { b.hidden = true; return; }
      const word = String(k.item.word != null ? k.item.word : k.item.label != null ? k.item.label : '#' + k.item.id);
      const key = [n.from, k.L.name, k.i, this.scope, word].join('|');
      if (b._key !== key) {
        b._key = key; b.replaceChildren();
        const seg = (id, label, title) => { const x = mk('button', 'lv-seg', label); x.type = 'button'; x.title = title; x.setAttribute('data-lv-scope', id); x.setAttribute('aria-pressed', String(this.scope === id)); x.addEventListener('click', ev => { ev.stopPropagation(); this.scope = id; b._key = ''; this.drawBar(this.c.selected, this.elOf(this.c.selected)); if (this.onScope) this.onScope(); }); return x; };
        b.append(seg('tpl', 'התבנית — חל על כל המילים', 'כל שינוי (עיצוב, מבנה, גרירה) — ב-<template>, וכל הכרטיסים מצוירים מחדש'),
          seg('word', 'המילה הזו: ' + word.slice(0, 30), 'עריכת הערך במילה הזו — נשמר בטבלה (📚), לא בעמוד'));
        if (this.scope === 'word') {
          const bd = this.binding(n), btn = (label, fn, attr) => { const x = mk('button', 'lv-act', label); x.type = 'button'; if (attr) x.setAttribute(attr, ''); x.addEventListener('click', ev => { ev.stopPropagation(); fn(); }); return x; };
          if (!bd) b.append(mk('span', 'lv-why', 'זה חלק מהתבנית; אין לו ערך משלו במילה'));
          else if ((bd.kind === 'text' || bd.kind === 'html') && bd.field) b.append(btn('✏️ ערוך את ה' + this.fieldLabel(k.L, bd.field), () => this.beginValue(n), 'data-lv-edit'));
          else if (bd.kind === 'text' && bd.mixed) b.append(mk('span', 'lv-why', 'טקסט מעורב — השדות: ' + bd.fields.join(', ') + '; עורכים כל שדה במקום שלו, או ב"פתח את המילה"'));
          else if (bd.kind === 'play') b.append(btn('🔁 החלף הקלטה', () => this.replaceMedia(n, bd.field, 'audio'), 'data-lv-audio'));
          else if (bd.kind === 'image') b.append(btn('🖼 החלף תמונה', () => this.replaceMedia(n, bd.field, 'image'), 'data-lv-image'));
          else if (bd.kind === 'each') b.append(btn('🏷 ' + this.fieldLabel(k.L, bd.field) + '…', () => this.pickValues(n, bd.field), 'data-lv-each'));
          b.append(btn('✏️ פתח את המילה', () => this.openWord && this.openWord(k.L, k.item), 'data-lv-open'));
        }
      }
      b.hidden = false;
      const top = Math.max(0, r.top - 34 - (this.c.boxSel && !this.c.boxSel.hidden ? 16 : 0));
      b.style.cssText = 'left:' + Math.max(0, r.left) + 'px;top:' + top + 'px';
    }
    fieldLabel(L, key) { const f = (this.fieldsOf(L) || []).find(x => x.key === key); return f ? f.label : key; }
    fieldsOf(L) { return this.fieldCache && this.fieldCache.get(L.name) || null; }
    // ── "המילה הזו" ──────────────────────────────────────
    /** עריכה במקום של הערך: contenteditable על האלמנט שבכרטיס (בקנבס בלבד). Enter (טקסט) / יציאה — שומר; Esc — מבטל. */
    beginValue(n) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      const hit = this.item(n), bd = this.binding(n);
      if (!hit || !bd || !bd.field || (bd.kind !== 'text' && bd.kind !== 'html')) return false;
      const e = hit.el, html = bd.kind === 'html', was = html ? e.innerHTML : e.textContent, L = hit.L, item = hit.item, field = bd.field;
      if (this.valueEdit) this.valueEdit.end();
      e.setAttribute('contenteditable', html ? 'true' : 'plaintext-only'); if (e.getAttribute('contenteditable') !== 'plaintext-only' && !html) e.setAttribute('contenteditable', 'true');
      e.setAttribute('data-lv-editing', '');
      const doc = this.c.doc, win = this.c.frame.contentWindow;
      try { e.focus(); const s = doc.getSelection(), rg = doc.createRange(); rg.selectNodeContents(e); s.removeAllRanges(); s.addRange(rg); } catch { /* */ }
      this.bar.hidden = true;
      const done = save => {
        if (!this.valueEdit || this.valueEdit.e !== e) return;
        this.valueEdit = null; e.removeAttribute('contenteditable'); e.removeAttribute('data-lv-editing');
        e.removeEventListener('keydown', key, true); e.removeEventListener('blur', blur);
        const now = html ? e.innerHTML : e.textContent;
        if (!save || now === was) { if (html) e.innerHTML = was; else e.textContent = was; this.c.draw(); return; }
        this.setValue(L, item, field, html ? now : now.replace(/\s*\n\s*/g, ' ').trim());
      };
      const key = ev => { if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); done(false); } else if (ev.key === 'Enter' && !html) { ev.preventDefault(); ev.stopPropagation(); done(true); } else ev.stopPropagation(); };
      const blur = () => setTimeout(() => done(true), 0);
      e.addEventListener('keydown', key, true); e.addEventListener('blur', blur);
      this.valueEdit = { e, owns: t => !!t && (t === e || e.contains(t)), end: () => done(true), abort: () => done(false) };
      void win;
      this.c.draw();
      return true;
    }
    /** כתיבה של שדה אחד במילה אחת — דרך המנוע של N1 — ורישום עסקה עם ביטול. */
    async setValue(L, item, field, value, label) {
      const id = item.id, old = item[field] === undefined ? null : item[field], word = String(item.word != null ? item.word : '#' + id);
      label = label || 'המילה הזו · ' + word + ' · ' + this.fieldLabel(L, field);
      const run = async v => { await this.write(L, id, field, v); };
      try { await run(value); }
      catch (e) { this.note(e.message || String(e), true); return false; }
      this.pushFx(label, { undo: () => run(old).catch(e => this.note('הביטול לא נשמר: ' + e.message, true)), redo: () => run(value).catch(e => this.note(e.message, true)) });
      this.note('נשמר בטבלה · ' + word + ' · ' + this.fieldLabel(L, field), false, { label: 'בטל', run: this.undo });
      return true;
    }
    /** pf_write על הקובץ הווירטואלי: "מה ישתנה" — חייב להיות בדיוק שינוי אחד, באותה מילה — ואז "החל" עם האסימון. */
    async write(L, id, field, value) {
      const d = L.ds; if (!d || d.status !== 'ok') throw new Error('הנתונים של הרשימה לא נטענו');
      this.busy = true;
      try {
        const fresh = await this.load(d.path), json = fresh.data, items = this.B.itemsOf(json), it = items.find(x => x.id === id);
        if (!it) throw new Error('המילה לא נמצאה בטבלה (אולי נמחקה בינתיים)');
        if (value == null || value === '' || (Array.isArray(value) && !value.length && field !== 'topics')) delete it[field]; else it[field] = value;
        if (field === 'topics' && value == null) it.topics = [];
        const text = JSON.stringify(json, null, 2) + '\n';
        const r = await this.save(fresh.id, fresh.rev, text, plan => {
          const c = plan.counts || {};
          if ((c.changed || 0) > 1 || c.added || c.trashed || c.moved) throw new Error('השמירה הייתה משנה יותר ממילה אחת (' + (plan.summary || '') + ') — בוטלה; עורכים ב-📚');
          const ch = (plan.changed || [])[0]; if (ch && ch.id !== id) throw new Error('השמירה הייתה משנה מילה אחרת — בוטלה');
        });
        Object.assign(d, { json: r.data, rev: r.rev, state: { items: this.B.itemsOf(r.data), labels: this.B.labelsOf(r.data) } });
      } finally { this.busy = false; }
      if (this.c.m) { this.draw(); this.c.draw(); this.drawStrip(); }
    }
    async replaceMedia(n, field, kind) {
      const hit = this.item(n); if (!hit) return;
      const f = await this.pick(kind); if (!f) return;
      await this.setValue(hit.L, hit.item, field, f.id, 'המילה הזו · ' + (hit.item.word || '#' + hit.item.id) + ' · ' + (kind === 'audio' ? 'החלפת הקלטה' : 'החלפת תמונה'));
    }
    /** בורר ערכים לשדה רשימה (נושאים): כל התוויות מטבלת התוויות; סימון ← שמירה. */
    pickValues(n, field) {
      const hit = this.item(n); if (!hit) return;
      const L = hit.L, st = L.ds.state, cur = new Set(hit.item[field] || []), table = st.labels[field] || {};
      const ids = Object.keys(table).length ? Object.keys(table) : [...new Set(st.items.flatMap(x => Array.isArray(x[field]) ? x[field] : []))];
      const box = mk('div', 'lv-pick'); box.setAttribute('data-lv-values', field);
      for (const v of ids) { const lb = mk('label', ''), ch = mk('input'); ch.type = 'checkbox'; ch.value = v; ch.checked = cur.has(v); lb.append(ch, ' ' + (table[v] ? table[v].label : v)); box.append(lb); }
      const go = mk('button', 'btn primary', 'שמירה'); go.type = 'button'; go.setAttribute('data-lv-values-save', '');
      const dlg = this.sheet(this.fieldLabel(L, field) + ' · ' + (hit.item.word || '#' + hit.item.id), box, go);
      go.addEventListener('click', () => { const v = [...box.querySelectorAll('input:checked')].map(x => x.value); dlg.close(); this.setValue(L, hit.item, field, v); });
    }
    // ── הסרגל של הרשימה: מצב, מילה לתצוגה, "הצג עוד", קובץ מערכת שונה ──
    drawStrip() {
      const host = this.strip; if (!host) return;
      const rows = [];
      const kd = this.kd ? this.kd() : null;
      for (const L of this.lists) {
        const M = this.mode(L.name), row = mk('div', 'lv-strip-row'); row.setAttribute('data-lv-strip', L.name);
        const title = mk('b', '', '📋 ' + L.name); row.append(title);
        if (L.status === 'loading') row.append(mk('span', 'hint', ' · טוען את הנתונים…'));
        else if (L.status === 'error' || L.status === 'notpl') row.append(mk('span', 'hint', ' · ' + (L.status === 'notpl' ? 'אין <template> במכל — אין מה לצייר' : L.why)));
        else {
          row.append(mk('span', 'lv-count', ' · ' + L.drawn + ' מוצגים מתוך ' + L.matched + (L.matched !== L.total ? ' (' + L.total + ' בסך הכול)' : '') + ' '));
          if (L.drawn < L.matched && L.drawn >= CANVAS_PAGE * (1 + this.more)) { const more = mk('button', 'btn sm', '＋ הצג עוד ' + CANVAS_PAGE); more.type = 'button'; more.setAttribute('data-lv-more', ''); more.addEventListener('click', () => { this.more++; this.redraw(); }); row.append(more); }
          // מצב
          const sel = mk('select', ''); sel.setAttribute('aria-label', 'מצב הרשימה בקנבס'); sel.setAttribute('data-lv-state', L.name);
          for (const [v, label] of STATES) { const o = mk('option', '', label); o.value = v; o.selected = M.state === v; sel.append(o); }
          sel.addEventListener('change', () => { M.state = sel.value; if (M.state === 'filter' && !M.value) { const ls = Object.keys(L.ds.state.labels.topics || {}); M.value = ls[0] || ''; } if (M.state !== 'filter' && M.state !== 'search') M.value = ''; this.redraw(); });
          row.append(mk('span', 'hint', ' מצב: '), sel);
          if (M.state === 'filter') {
            const fs = mk('select', ''); fs.setAttribute('aria-label', 'הנושא'); fs.setAttribute('data-lv-state-value', '');
            for (const [id, t] of Object.entries(L.ds.state.labels.topics || {})) { const o = mk('option', '', t.label || id); o.value = id; o.selected = M.value === id; fs.append(o); }
            fs.addEventListener('change', () => { M.value = fs.value; this.redraw(); }); row.append(fs);
          }
          if (M.state === 'search') { const q = mk('input', ''); q.type = 'search'; q.value = M.value; q.placeholder = 'מה מחפשים'; q.setAttribute('aria-label', 'חיפוש בקנבס'); q.setAttribute('data-lv-state-value', ''); q.addEventListener('change', () => { M.value = q.value; this.redraw(); }); row.append(q); }
          // מילה לתצוגה
          const pk = mk('select', ''); pk.setAttribute('aria-label', 'מילה לתצוגה'); pk.setAttribute('data-lv-pick', L.name);
          for (const [v, label] of PICKS) { const o = mk('option', '', label); o.value = v; o.selected = M.pick === v; pk.append(o); }
          pk.addEventListener('change', () => { M.pick = pk.value; M.pickArg = ''; if (M.pick === 'empty') { const f = (this.fieldsOf(L) || []).find(x => !['word'].includes(x.key)); M.pickArg = f ? f.key : 'audio'; } this.redraw(); });
          row.append(mk('span', 'hint', ' · '), pk);
          if (M.pick === 'empty') {
            const fs = mk('select', ''); fs.setAttribute('aria-label', 'השדה הריק'); fs.setAttribute('data-lv-pick-arg', '');
            const keys = this.fieldsOf(L) ? this.fieldsOf(L).map(f => [f.key, f.label]) : [...new Set(L.ds.state.items.slice(0, 50).flatMap(x => Object.keys(x)))].map(k => [k, k]);
            for (const [k, label] of keys) { const o = mk('option', '', label); o.value = k; o.selected = M.pickArg === k; fs.append(o); }
            fs.addEventListener('change', () => { M.pickArg = fs.value; this.redraw(); }); row.append(fs);
          }
          if (M.pick === 'search') { const q = mk('input', ''); q.type = 'search'; q.value = M.pickArg; q.placeholder = 'מילה'; q.setAttribute('aria-label', 'חיפוש מילה לתצוגה'); q.setAttribute('data-lv-pick-arg', ''); q.addEventListener('input', () => { M.pickArg = q.value; clearTimeout(this.qt); this.qt = setTimeout(() => this.redraw(), 200); }); row.append(q); }
          if (L.pickMiss) row.append(mk('span', 'hint', ' — אין מילה כזו'));
        }
        rows.push(row);
      }
      // חלונות (data-kol-dialog): "פתח בקנבס"
      const dlgs = []; if (this.c.m && this.c.m.root) this.K.walk(this.c.m.root, n => { const a = this.K.attrOf(n, 'data-kol-dialog'); if (a && a.value && !dlgs.includes(a.value)) dlgs.push(a.value); });
      for (const name of dlgs) {
        const st = this.dialogState.get(name) || { open: false, form: '' }, row = mk('div', 'lv-strip-row'); row.setAttribute('data-lv-dialog', name);
        const sel = mk('select', ''); sel.setAttribute('aria-label', 'החלון ' + name);
        for (const [v, label] of [['', 'סגור'], ['open', 'פתח בקנבס'], ['done', 'פתוח · "נשלח"'], ['error', 'פתוח · "שגיאה"']]) { const o = mk('option', '', label); o.value = v; o.selected = (st.open ? (st.form || 'open') : '') === v; sel.append(o); }
        sel.addEventListener('change', () => { const v = sel.value; this.dialogState.set(name, { open: !!v, form: v === 'done' || v === 'error' ? v : '', item: null }); this.redraw(); });
        row.append(mk('b', '', '🗔 החלון ' + name + ' '), sel, mk('span', 'hint', ' (עם המילה הראשונה בקנבס)'));
        rows.push(row);
      }
      if (kd && kd.modified && this.lists.length) { const w = mk('div', 'lv-strip-row lv-warn', 'הכרטיסים מצוירים לפי המימוש של הכלי; js/kol-data.js שלך שונה — ייתכן שבתצוגה החיה הם ייראו אחרת'); w.setAttribute('data-lv-modified', ''); w.setAttribute('role', 'note'); rows.unshift(w); }
      host.replaceChildren(...rows); host.hidden = !rows.length;
    }
    redraw() { if (!this.c.m) return; this.draw(); this.c.draw(); this.drawStrip(); }
  }

  window.KolList = { ListView, PICKS, STATES, CANVAS_PAGE, OWNED, CARD };
})();
