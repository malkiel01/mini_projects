/**
 * ספייק V0 — רתמת הבדיקה ל-K1–K4, לבדיקת ההתקפה של K5 ולמדידות K6. רצה בדפדפן: ממסך המפתח ("הרץ בדיקות"),
 * ומ-dev/spike/run.mjs (Chromium בלי ראש). קלט אמיתי של עכבר ומגע (K5) — ב-run.mjs, לא כאן.
 *
 * כלל: שום קריטריון אינו "עובר" בלי בדיקה שרצה. היעדים והתנאים נקבעו לפני ההרצה; כישלון נרשם עם הקלט המדויק.
 */
(function () {
  'use strict';
  const K = window.KolSrc, C = window.KolCanvas, H = K.html, CS = K.css;
  const HTML = ['lexicon.html', 'handwritten.html', 'broken.html'];
  const FILES = ['lexicon.html', 'lexicon.css', 'handwritten.html', 'handwritten.css', 'broken.html', 'broken.css'];
  const eqBytes = (a, b) => { if (a.length !== b.length) return false; for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) return false; return true; };
  const median = a => { const s = a.slice().sort((x, y) => x - y); return s.length ? s[Math.floor(s.length / 2)] : 0; };
  const r1 = x => Math.round(x * 10) / 10;
  const WS = /[ \t\n\r\f]/;
  const backWs = (s, at, min) => { while (at > min && WS.test(s[at - 1])) at--; return at; };

  // ── בדיקת ה-diff, בלתי תלויה בפעולה ───────────────────────
  function lineStarts(s) { const a = [0]; for (let i = 0; i < s.length; i++) if (s.charCodeAt(i) === 10) a.push(i + 1); return a; }
  function lineIdx(starts, at) { let lo = 0, hi = starts.length - 1; while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (starts[mid] <= at) lo = mid; else hi = mid - 1; } return lo; }
  const splitLines = s => s.split('\n');
  /** שורות המקור שה-diff נוגע בהן (diff שורות אמיתי, לכל תיקון בנפרד), מול השורות המותרות. */
  function diffCheck(before, patches, spec) {
    const starts = lineStarts(before), allowed = new Set(), bl = splitLines(before);
    for (const [a, b] of spec.node || []) for (let l = lineIdx(starts, a), e = lineIdx(starts, Math.max(a, b - 1)); l <= e; l++) allowed.add(l);
    for (const [a, b] of spec.gaps || []) if (!/[\r\n]/.test(before.slice(a, b))) allowed.add(lineIdx(starts, a));   // הוספה בתוך שורה
    const touched = new Set();
    for (const p of patches) {
      const al = splitLines(K.applyPatches(before, [p]));
      let s = 0; while (s < bl.length && s < al.length && bl[s] === al[s]) s++;
      let e = 0; while (e < bl.length - s && e < al.length - s && bl[bl.length - 1 - e] === al[al.length - 1 - e]) e++;
      for (let l = s; l < bl.length - e; l++) touched.add(l);
    }
    // שורה ריקה (רווחים בלבד) שצמודה לצומת היא המפריד שלו, לא שורה של צומת אחר — נספרת בנפרד ומדווחת
    const blank = [...touched].filter(l => !allowed.has(l) && !/[^ \t\r]/.test(bl[l]));
    const outside = [...touched].filter(l => !allowed.has(l) && /[^ \t\r]/.test(bl[l]));
    const tight = patches.every(p => (spec.tight || spec.node || []).some(([a, b]) => a <= p.from && p.to <= b) || (p.from === p.to && (spec.gaps || []).some(([a, b]) => a <= p.from && p.from <= b)));
    let changed = 0; for (const p of patches) changed += (p.to - p.from);
    return { linesTouched: touched.size, blankSeparatorLines: blank.length, linesOutside: outside.map(l => l + 1), linesOk: outside.length === 0, tight, bytesRemoved: changed, bytesAdded: patches.reduce((n, p) => n + p.text.length, 0) };
  }

  // ── בוני פעולות: כל אחד מחזיר m → {run, node, tight, gaps, check, inverse} ──
  const zone = (m, par, sibs, i) => [i > 0 ? K.endOf(m, sibs[i - 1]) : (par.implied ? m.bom : par.st[1]), i < sibs.length ? sibs[i].from : (par.et ? par.et[0] : par.to)];
  const B = {
    setText: (get, text) => m => { const n = get(m), old = K.textOf(m, n), p = K.pathOf(n);
      return { run: m => H.setText(m, get(m), text), node: [[n.from, K.endOf(m, n)]], tight: [[n.st[1], n.et ? n.et[0] : n.to]], check: m2 => K.textOf(m2, K.byPath(m2, p)) === text, inverse: m2 => H.setText(m2, K.byPath(m2, p), old) }; },
    setAttr: (get, name, val) => m => { const n = get(m), a = K.attrOf(n, name), old = a.value, p = K.pathOf(n);
      return { run: m => H.setAttr(m, get(m), name, val), node: [n.st], tight: [[a.from, a.to]], check: m2 => K.attrOf(K.byPath(m2, p), name).value === val, inverse: m2 => H.setAttr(m2, K.byPath(m2, p), name, old) }; },
    addAttr: (get, name, val) => m => { const n = get(m), p = K.pathOf(n);
      return { run: m => H.setAttr(m, get(m), name, val), node: [n.st], check: m2 => (K.attrOf(K.byPath(m2, p), name) || {}).value === val, inverse: m2 => H.removeAttr(m2, K.byPath(m2, p), name) }; },
    removeAttr: (get, name) => m => { const n = get(m), i = n.attrs.findIndex(a => a.name === name), a = n.attrs[i], prev = n.attrs[i - 1], next = n.attrs[i + 1], p = K.pathOf(n);
      const old = m.src.slice(a.from, a.to).indexOf('=') < 0 ? null : a.value;
      return { run: m => H.removeAttr(m, get(m), name), node: [n.st], tight: [[backWs(m.src, a.from, n.st[0]), a.to]], check: m2 => !K.attrOf(K.byPath(m2, p), name),
        inverse: m2 => H.setAttr(m2, K.byPath(m2, p), name, old, prev ? { after: prev.name } : next ? { before: next.name } : undefined) }; },
    addClass: (get, cls) => m => { const n = get(m), a = K.attrOf(n, 'class'), p = K.pathOf(n);
      return { run: m => H.addClass(m, get(m), cls), node: [n.st], tight: [a ? [a.from, a.to] : n.st], check: m2 => K.classesOf(K.byPath(m2, p)).includes(cls), inverse: m2 => H.removeClass(m2, K.byPath(m2, p), cls) }; },
    removeClass: (get, cls) => m => { const n = get(m), a = K.attrOf(n, 'class'), p = K.pathOf(n);
      return { run: m => H.removeClass(m, get(m), cls), node: [n.st], tight: [[backWs(m.src, a.from, n.st[0]), a.to]], check: m2 => !K.classesOf(K.byPath(m2, p)).includes(cls), inverse: m2 => H.addClass(m2, K.byPath(m2, p), cls) }; },
    move: (get, getPar, index, getOrigPar) => m => { const n = get(m), par = getPar(m), html = K.outer(m, n), oi = n.parent.kids.indexOf(n), sibs = par.kids.filter(k => k !== n);
      const back = getOrigPar || getPar;
      return { run: m => H.move(m, get(m), getPar(m), index), node: [[n.from, K.endOf(m, n)]], tight: [[K.leadOf(m, n), K.endOf(m, n)]], gaps: [zone(m, par, sibs, index)],
        check: m2 => { const k = getPar(m2).kids[index]; return !!k && K.outer(m2, k) === html; }, inverse: m2 => H.move(m2, getPar(m2).kids[index], back(m2), oi) }; },
    insert: (getPar, index, html) => m => { const par = getPar(m), n0 = par.kids.length;
      return { run: m => H.insert(m, getPar(m), index, html), node: n0 ? [] : [[par.from, par.to]], tight: n0 ? [] : [[par.st[1], par.et[0]]], gaps: [zone(m, par, par.kids, Math.min(index, n0))],
        check: m2 => { const k = getPar(m2).kids[Math.min(index, n0)]; return getPar(m2).kids.length === n0 + 1 && K.outer(m2, k) === html; }, inverse: m2 => H.remove(m2, getPar(m2).kids[Math.min(index, n0)]) }; },
    duplicate: get => m => { const n = get(m), par = n.parent, pp = K.pathOf(par), i = par.kids.indexOf(n), html = K.outer(m, n), n0 = par.kids.length;
      return { run: m => H.duplicate(m, get(m)), node: [], gaps: [zone(m, par, par.kids, i + 1)],
        check: m2 => { const p2 = K.byPath(m2, pp); return p2.kids.length === n0 + 1 && K.outer(m2, p2.kids[i + 1]) === html && K.outer(m2, p2.kids[i]) === html; }, inverse: m2 => H.remove(m2, K.byPath(m2, pp).kids[i + 1]) }; },
    remove: get => m => { const n = get(m), par = n.parent, pp = K.pathOf(par), i = par.kids.indexOf(n), html = K.outer(m, n), n0 = par.kids.length;
      const only = n0 === 1 && par.et && !par.nodes.some(x => x.type === 'comment' || (x.type === 'text' && /[^ \t\n\r\f]/.test(x.value)));
      return { run: m => H.remove(m, get(m)), node: only ? [[par.from, par.to]] : [[n.from, K.endOf(m, n)]], tight: only ? [[par.st[1], par.et[0]]] : [[K.leadOf(m, n), K.endOf(m, n)]],
        check: m2 => K.byPath(m2, pp).kids.length === n0 - 1, inverse: m2 => H.insert(m2, K.byPath(m2, pp), i, html) }; },
    wrap: (get, tag, attrs) => m => { const n = get(m), p = K.pathOf(n), html = K.outer(m, n);
      return { run: m => H.wrap(m, get(m), tag, attrs), node: [[n.from, K.endOf(m, n)]], check: m2 => { const w = K.byPath(m2, p); return w.tag === tag && w.kids.length === 1 && K.outer(m2, w.kids[0]) === html; }, inverse: m2 => H.unwrap(m2, K.byPath(m2, p)) }; },
    retag: (get, tag) => m => { const n = get(m), p = K.pathOf(n), old = n.tag;
      return { run: m => H.retag(m, get(m), tag), node: [[n.from, K.endOf(m, n)]], check: m2 => K.byPath(m2, p).tag === tag, inverse: m2 => H.retag(m2, K.byPath(m2, p), old) }; },
    // CSS
    setDecl: (get, prop, val) => m => { const r = get(m), d = r.decls.filter(x => x.prop === prop).pop(), old = d.value, imp = d.important;
      const cur = m2 => get(m2).decls.filter(x => x.prop === prop).pop();
      return { run: m => CS.setDecl(m, get(m), prop, val), node: [[r.from, r.to]], tight: [[d.valFrom, d.valTo]], check: m2 => cur(m2).value === val && cur(m2).important === imp, inverse: m2 => CS.setDecl(m2, get(m2), prop, old) }; },
    addDecl: (get, prop, val) => m => { const r = get(m), n0 = r.decls.length;
      return { run: m => CS.addDecl(m, get(m), prop, val), node: [[r.from, r.to]], tight: [], gaps: declGaps(r),
        check: m2 => { const ds = get(m2).decls; return ds.length === n0 + 1 && ds[n0].prop === prop && ds[n0].value === val; }, inverse: m2 => CS.removeDecl(m2, get(m2), prop) }; },
    removeDecl: (get, prop) => m => { const r = get(m), d = r.decls.filter(x => x.prop === prop).pop(), i = r.decls.indexOf(d), n0 = r.decls.length, full = m.src.slice(d.valFrom, d.to);
      return { run: m => CS.removeDecl(m, get(m), prop), node: [[r.from, r.to]], tight: [[backWs(m.src, d.from, r.bFrom + 1), d.semi >= 0 ? d.semi : d.to]],
        check: m2 => get(m2).decls.length === n0 - 1 && !get(m2).decls.some(x => x.prop === prop), inverse: m2 => CS.addDecl(m2, get(m2), prop, full, i < n0 - 1 ? { index: i } : undefined) }; },
    addRule: (getPar, sel, decls, find) => m => { const par = getPar(m), n0 = par.kids.length, last = par.kids[n0 - 1];
      return { run: m => CS.addRule(m, getPar(m), sel, decls), node: [], gaps: [[last ? last.to : m.bom, par === m.root ? m.src.length : par.bTo - 1]],
        check: m2 => { const r = find(m2); return getPar(m2).kids.length === n0 + 1 && !!r && r.decls.length === decls.length && r.decls.every((d, i) => d.prop === decls[i][0] && d.value === decls[i][1]); }, inverse: m2 => CS.removeNode(m2, find(m2)) }; },
    addMedia: (prelude, rules) => m => { const n0 = m.root.kids.length, last = m.root.kids[n0 - 1];
      return { run: m => CS.addMedia(m, prelude, rules), node: [], gaps: [[last.to, m.src.length]],
        check: m2 => { const a = K.cssAt(m2, 'media', prelude); return !!a && a.kids.length === rules.length && a.kids[0].sel === rules[0].sel && a.kids[0].decls[0].value === rules[0].decls[0][1]; }, inverse: m2 => CS.removeNode(m2, K.cssAt(m2, 'media', prelude)) }; },
    removeRule: (get, getPar) => m => { const n = get(m), par = n.parent, i = par.kids.indexOf(n), n0 = par.kids.length, sel = n.sel, decls = n.decls.map(d => [d.prop, m.src.slice(d.valFrom, d.to)]);
      return { run: m => CS.removeNode(m, get(m)), node: [[n.from, n.to]], tight: [[backWs(m.src, n.from, 0), n.to]], check: m2 => getPar(m2).kids.length === n0 - 1 && !get(m2), inverse: m2 => CS.addRule(m2, getPar(m2), sel, decls, { index: i }) }; },
  };
  function declGaps(r) { const g = []; let at = r.bFrom + 1; for (const d of r.decls) { g.push([at, d.from]); at = d.to; } g.push([at, r.bTo - 1]); return g; }

  const q = (sel, nth) => m => K.q(m, sel, nth);
  const rule = (sel, media) => m => K.cssFind(m, sel, media === undefined ? null : media);
  const sheet = m => m.root;
  const media = p => m => K.cssAt(m, 'media', p);
  const NEW_CARD = '<article class="kadisha-word-card" data-id="new"><h3 class="kadisha-word-title">מילה חדשה</h3></article>';

  /** 30 הפעולות של K2. היעדים נקבעו מראש, לפי תפקיד האלמנט בעמוד — לא לפי מה שעובר. */
  const OPS = {
    lexicon: [
      ['H01', 'html', 'שינוי טקסט של כותרת', B.setText(q('#lexicon-hero-title'), 'קול מרוקו — מהדורה <חדשה> & מורחבת')],
      ['H02', 'html', 'שינוי תכונה', B.setAttr(q('#kadisha-lex-search'), 'placeholder', 'הקלידו מילה…')],
      ['H03', 'html', 'הוספת תכונה', B.addAttr(q('#lexicon-main'), 'data-test', '1')],
      ['H04', 'html', 'הסרת תכונה (מהאמצע)', B.removeAttr(q('#kadisha-lex-search'), 'placeholder')],
      ['H05', 'html', 'הוספת מחלקה', B.addClass(q('.kadisha-word-card', 10), 'is-featured')],
      ['H06', 'html', 'הסרת מחלקה', B.removeClass(q('.kadisha-lex-topic-btn', 0), 'is-active')],
      ['H07', 'html', 'הוספת מחלקה לאלמנט בלי class', B.addClass(q('#kadisha-lex-feedback-title'), 'modal-title')],
      ['H08', 'html', 'שינוי תכונה שנייה (dir)', B.setAttr(q('.kadisha-hero-eyebrow'), 'dir', 'rtl')],
      ['H09', 'html', 'הזזה בין אחים (מקום אחד)', B.move(q('.kadisha-word-card', 3), q('#kadisha-lex-grid'), 4)],
      ['H10', 'html', 'הזזה לראש הרשימה', B.move(q('.kadisha-word-card', 100), q('#kadisha-lex-grid'), 0)],
      ['H11', 'html', 'הזזה אל מכל אחר', B.move(q('.kadisha-hero-subtitle', 0), q('#kadisha-lex-panel-search'), 1, q('.kadisha-hero-inner'))],
      ['H12', 'html', 'הוספת אלמנט חדש (באמצע)', B.insert(q('#kadisha-lex-grid'), 5, NEW_CARD)],
      ['H13', 'html', 'הוספת אלמנט למכל ריק', B.insert(q('.kadisha-lex-feedback-backdrop'), 0, '<span class="x">חדש</span>')],
      ['H14', 'html', 'שכפול', B.duplicate(q('.kadisha-word-card', 7))],
      ['H15', 'html', 'מחיקה', B.remove(q('.kadisha-word-card', 20))],
      ['H16', 'html', 'מחיקת הילד הראשון', B.remove(q('.kadisha-lex-topic-btn', 0))],
      ['H17', 'html', 'עטיפה במכל', B.wrap(q('.kadisha-lexicon-search-wrap'), 'div', { class: 'outer' })],
      ['H18', 'html', 'שינוי תגית', B.retag(q('.kadisha-word-title', 2), 'h4')],
      ['C01', 'css', 'שינוי מאפיין', B.setDecl(rule('.kadisha-word-card'), 'border-radius', '8px')],
      ['C02', 'css', 'שינוי מאפיין עם !important', B.setDecl(rule('.kadisha-lexicon-page .kadisha-hero-subtitle'), 'font-size', '30px')],
      ['C03', 'css', 'הוספת מאפיין (כלל רב-שורתי)', B.addDecl(rule('.kadisha-word-card'), 'margin-bottom', '1rem')],
      ['C04', 'css', 'הוספת מאפיין (כלל בשורה אחת)', B.addDecl(rule('.kadisha-word-title'), 'text-align', 'start')],
      ['C05', 'css', 'הסרת מאפיין (מהאמצע)', B.removeDecl(rule('.kadisha-hero'), 'color')],
      ['C06', 'css', 'הסרת מאפיין (האחרון)', B.removeDecl(rule('.kadisha-word-head'), 'gap')],
      ['C07', 'css', 'הוספת כלל חדש', B.addRule(sheet, '.kadisha-word-card:hover', [['box-shadow', '0 4px 12px rgba(0, 0, 0, .12)']], rule('.kadisha-word-card:hover'))],
      ['C08', 'css', 'הוספת הצהרה בתוך @media קיים', B.addDecl(rule('.kadisha-hero', '(max-width: 782px)'), 'text-align', 'start')],
      ['C09', 'css', 'הוספת כלל בתוך @media קיים', B.addRule(media('(max-width: 782px)'), '.kadisha-word-title', [['font-size', '1.1rem']], rule('.kadisha-word-title', '(max-width: 782px)'))],
      ['C10', 'css', 'יצירת @media חדש', B.addMedia('(max-width: 480px)', [{ sel: '.kadisha-container', decls: [['padding', '0 .75rem']] }])],
      ['C11', 'css', 'מחיקת כלל', B.removeRule(rule('.kadisha-word-extid'), sheet)],
      ['C12', 'css', 'שינוי משתנה (--ka-gold)', B.setDecl(rule(':root'), '--ka-gold', '#b8860b')],
    ],
    handwritten: [
      ['H01', 'html', 'שינוי טקסט של כותרת', B.setText(q('h1.title'), 'שלום <עולם> & עוד')],
      ['H02', 'html', 'שינוי תכונה', B.setAttr(q('img'), 'alt', 'תמונה "חדשה"')],
      ['H03', 'html', 'הוספת תכונה', B.addAttr(q('#top'), 'data-test', '1')],
      ['H04', 'html', 'הסרת תכונה (מהאמצע)', B.removeAttr(q('#one'), 'data-x')],
      ['H05', 'html', 'הוספת מחלקה', B.addClass(q('li.current'), 'open')],
      ['H06', 'html', 'הסרת מחלקה', B.removeClass(q('body'), 'home')],
      ['H07', 'html', 'הוספת מחלקה לאלמנט בלי class', B.addClass(q('nav'), 'main-nav')],
      ['H08', 'html', 'שינוי תכונה בלי מרכאות', B.setAttr(q('img'), 'width', '240')],
      ['H09', 'html', 'הזזה בין אחים (מקום אחד)', B.move(q('#c1'), q('.cards'), 1)],
      ['H10', 'html', 'הזזה לראש הרשימה', B.move(q('#c3'), q('.cards'), 0)],
      ['H11', 'html', 'הזזה אל מכל אחר', B.move(q('#evil-btn'), q('#box'), 0, q('#one'))],
      ['H12', 'html', 'הוספת אלמנט חדש (באמצע)', B.insert(q('ul.menu'), 1, '<li><a href="#new">חדש</a></li>')],
      ['H13', 'html', 'הוספת אלמנט למכל ריק', B.insert(q('#box'), 0, '<p>חדש</p>')],
      ['H14', 'html', 'שכפול', B.duplicate(q('tr', 1))],
      ['H15', 'html', 'מחיקה', B.remove(q('#c2'))],
      ['H16', 'html', 'מחיקת הילד הראשון', B.remove(q('li', 0))],
      ['H17', 'html', 'עטיפה במכל', B.wrap(q('table.data'), 'div', { class: 'table-wrap' })],
      ['H18', 'html', 'שינוי תגית', B.retag(q('h2.block-title', 0), 'h3')],
      ['C01', 'css', 'שינוי מאפיין', B.setDecl(rule('.lead'), 'color', '#333')],
      ['C02', 'css', 'שינוי מאפיין עם !important', B.setDecl(rule('.title'), 'font-size', '3em')],
      ['C03', 'css', 'הוספת מאפיין (כלל רב-שורתי)', B.addDecl(rule('.lead'), 'margin', '0')],
      ['C04', 'css', 'הוספת מאפיין (כלל בשורה אחת)', B.addDecl(rule('.site-head'), 'color', 'red')],
      ['C05', 'css', 'הסרת מאפיין (מהאמצע)', B.removeDecl(rule('body.page'), 'font-family')],
      ['C06', 'css', 'הסרת מאפיין (האחרון)', B.removeDecl(rule('.title'), 'margin')],
      ['C07', 'css', 'הוספת כלל חדש', B.addRule(sheet, '.new-rule', [['color', 'red']], rule('.new-rule'))],
      ['C08', 'css', 'הוספת הצהרה בתוך @media קיים', B.addDecl(rule('.site-head', '(max-width: 600px)'), 'margin', '0')],
      ['C09', 'css', 'הוספת כלל בתוך @media קיים', B.addRule(media('(max-width: 600px)'), '.title', [['font-size', '1.5em']], rule('.title', '(max-width: 600px)'))],
      ['C10', 'css', 'יצירת @media חדש', B.addMedia('(min-width: 900px)', [{ sel: '.block', decls: [['max-width', '800px']] }])],
      ['C11', 'css', 'מחיקת כלל', B.removeRule(rule('.block-title'), sheet)],
      ['C12', 'css', 'שינוי משתנה (--gap)', B.setDecl(rule(':root'), '--gap', '16px')],
    ],
  };
  /** מקרי קצה מעבר ל-30 — נרשמים בדוח, אינם חלק מהקריטריון. */
  const PROBES = [
    ['P1', 'handwritten', 'html', 'טקסט עם ישות שאינה &nbsp; (&mdash;)', B.setText(m => K.q(m, 'footer').kids[0], 'חדש')],
    ['P2', 'handwritten', 'html', 'הוספת מחלקה לתכונה בלי מרכאות (class=lead)', B.addClass(q('p.lead'), 'big')],
    ['P3', 'handwritten', 'html', 'הסרת מחלקה כשיש שני רווחים (class="block  wide")', B.removeClass(q('#one'), 'wide')],
    ['P4', 'handwritten', 'html', 'מחיקת אלמנט בלי תגית סגירה (<p> שני)', B.remove(m => K.q(m, '#top').kids[2])],
    ['P5', 'handwritten', 'html', 'שכפול שורת טבלה בלי </tr>', B.duplicate(q('tr', 0))],
    ['P6', 'handwritten', 'html', 'הזזת <li> בלי סגירה לסוף הרשימה', B.move(q('li', 0), q('ul.menu'), 2)],
    ['P7', 'handwritten', 'css', 'הסרת מאפיין אחרון בלי נקודה-פסיק, אחרי הערה', B.removeDecl(rule('.lead'), 'line-height')],
    ['P8', 'handwritten', 'css', 'שינוי ערך שיש בו ; בתוך מחרוזת (url)', B.setDecl(rule('.card'), 'background', 'url("img/c;d.png") repeat-x')],
    ['P9', 'lexicon', 'html', 'מחיקת הכרטיס האחרון (1,496)', B.remove(q('.kadisha-word-card', 1495))],
    ['P10', 'lexicon', 'html', 'עטיפת כל רשת הכרטיסים', B.wrap(q('#kadisha-lex-grid'), 'section', { class: 'wrap' })],
  ];
  const BROKEN_OK = [
    ['html', 'שינוי טקסט — לפני האזור', B.setText(q('#b-title'), 'כותרת חדשה')],
    ['html', 'שינוי תכונה', B.setAttr(q('#b-lead'), 'class', 'lead big')],
    ['html', 'הוספת תכונה', B.addAttr(q('#b-ok'), 'data-a', '1')],
    ['html', 'הוספת מחלקה', B.addClass(q('#b-h2'), 'x')],
    ['html', 'הסרת מחלקה', B.removeClass(q('#b-li1'), 'it')],
    ['html', 'הזזה בין אחים', B.move(q('#b-li2'), q('#b-list'), 0)],
    ['html', 'שכפול', B.duplicate(q('#b-li1'))],
    ['html', 'הוספה', B.insert(q('#b-list'), 1, '<li>חדש</li>')],
    ['html', 'עטיפה', B.wrap(q('#b-h2'), 'div', { class: 'w' })],
    ['html', 'שינוי תגית', B.retag(q('#b-title'), 'h2')],
    ['html', 'שינוי טקסט — אחרי האזור', B.setText(q('#b-foot-p'), 'סוף חדש')],
    ['html', 'מחיקה — אחרי האזור', B.remove(q('#b-foot-p'))],
    ['html', 'הזזה מלפני האזור אל אחריו', B.move(q('#b-lead'), q('#b-foot'), 0, q('#b-top'))],
    ['html', 'הוספה לסוף המכל שמכיל את האזור (body)', B.insert(q('body'), 99, '<aside>חדש</aside>')],
    ['css', 'שינוי מאפיין', B.setDecl(rule('.top'), 'color', '#000')],
    ['css', 'הוספת מאפיין', B.addDecl(rule('.title'), 'color', 'red')],
    ['css', 'הסרת מאפיין', B.removeDecl(rule('.top'), 'padding')],
    ['css', 'שינוי מאפיין — אחרי הכלל השבור הראשון', B.setDecl(rule('.lead'), 'color', '#111')],
    ['css', 'הוספת כלל בתוך @media', B.addRule(media('(max-width: 600px)'), '.title', [['margin', '0']], rule('.title', '(max-width: 600px)'))],
    ['css', 'הוספת הצהרה בתוך @media', B.addDecl(rule('.top', '(max-width: 600px)'), 'margin', '0')],
  ];
  const cssBySel = sel => m => K.cssAll(m).find(n => n.sel === sel);
  const BROKEN_NO = [
    ['html', 'שינוי טקסט של התגית החצויה', m => H.setText(m, K.q(m, '#b-half'), 'x')],
    ['html', 'שינוי תכונה בקישור עם המרכאה הפתוחה', m => H.setAttr(m, K.q(m, 'a'), 'href', 'x.html')],
    ['html', 'מחיקת אלמנט שבתוך האזור', m => H.remove(m, K.q(m, '#b-after'))],
    ['html', 'שינוי טקסט של אלמנט שבתוך האזור', m => H.setText(m, K.q(m, '#b-after'), 'x')],
    ['html', 'הזזה אל תוך האזור', m => H.move(m, K.q(m, '#b-li1'), K.q(m, '#b-half'), 0)],
    ['html', 'הוספה אל תוך האזור', m => H.insert(m, K.q(m, '#b-half'), 0, '<i>x</i>')],
    ['html', 'מחיקת המכל שמכיל את האזור', m => H.remove(m, K.q(m, '#b-typing'))],
    ['html', 'שכפול המכל שמכיל את האזור', m => H.duplicate(m, K.q(m, '#b-typing'))],
    ['css', 'שינוי מאפיין בכלל עם המרכאה הפתוחה', m => CS.setDecl(m, cssBySel('.ok')(m), 'border', '0')],
    ['css', 'הוספת מאפיין לכלל בלי סוגר', m => CS.addDecl(m, cssBySel('.typing')(m), 'color', 'red')],
    ['css', 'שינוי בכלל שנבלע בכלל השבור', m => CS.setDecl(m, cssBySel('.foot')(m), 'text-align', 'start')],
    ['css', 'מחיקת הכלל השבור', m => CS.removeNode(m, cssBySel('.typing')(m))],
    ['css', 'הוספת כלל בסוף הקובץ (היה נבלע)', m => CS.addRule(m, m.root, '.z', [['color', 'red']])],
  ];

  function strictCanvas(canvas) {
    const fresh = C.browserParse(canvas.src);
    const diffs = C.sameDom(canvas.doc.documentElement, fresh.documentElement, true);
    return { ok: diffs.length === 0 && canvas.mapErrors.length === 0, diffs: diffs.slice(0, 5), mapErrors: canvas.mapErrors.slice(0, 5) };
  }
  /** מריץ פעולה אחת מקצה לקצה דרך הקנבס, ואת ההפוכה לה. */
  function runOp(canvas, kind, make) {
    const isHtml = kind === 'html', model = () => isHtml ? canvas.m : canvas.cssM, text = () => isHtml ? canvas.src : canvas.cssSrc;
    const before = text(), out = { ok: false };
    let spec, r;
    try { spec = make(model()); r = isHtml ? canvas.apply(spec.run) : canvas.applyCss(spec.run); }
    catch (e) { out.error = String(e && e.message || e); out.code = e && e.code; return out; }
    out.patches = r.patches.length;
    Object.assign(out, diffCheck(before, r.patches, spec));
    out.effect = !!spec.check(model());
    out.ms = r1(r.timing.total); out.timing = Object.fromEntries(Object.entries(r.timing).map(([k, v]) => [k, r1(v)]));
    const sc = isHtml ? strictCanvas(canvas) : { ok: true };
    out.canvas = sc.ok; if (!sc.ok) out.canvasDiffs = sc.diffs.concat(sc.mapErrors.map(e => e.path + ' ' + e.why));
    out.undo = K.applyPatches(r.after, K.invertPatches(before, r.patches)) === before;
    try {
      const r2 = isHtml ? canvas.apply(spec.inverse) : canvas.applyCss(spec.inverse);
      out.inverseOp = r2.after === before;
      if (!out.inverseOp) out.inverseDiff = firstDiff(before, r2.after);
      if (isHtml && out.inverseOp) { const s2 = strictCanvas(canvas); out.canvasAfterInverse = s2.ok; }
    } catch (e) { out.inverseOp = false; out.inverseError = String(e && e.message || e); }
    out.restored = text() === before;
    out.ok = out.linesOk && out.effect && out.canvas && out.undo;      // הקריטריון; inverseOp נרשם בנפרד
    return out;
  }
  function firstDiff(a, b) { let i = 0; while (i < a.length && i < b.length && a[i] === b[i]) i++; return { at: i, was: a.slice(Math.max(0, i - 25), i + 35), got: b.slice(Math.max(0, i - 25), i + 35) }; }

  async function run(opt) {
    const say = opt.progress || (() => {}), res = { startedAt: new Date().toISOString(), ua: navigator.userAgent, k1: {}, k2: {}, k3: {}, k4: {}, k5: {}, k6: {}, probes: [] };
    const bytes = {}, text = {};
    for (const f of FILES) { bytes[f] = await opt.fixture(f); text[f] = C.decode(bytes[f]); }
    const canvas = new C.Canvas(opt.host);
    const pair = base => [text[base + '.html'], text[base + '.css']];

    say('K1 — הלוך-חזור');
    for (const f of FILES) {
      const t = text[f], isHtml = f.endsWith('.html');
      let m, err = null, inv = 0, n = 0;
      try { m = isHtml ? K.parseHtml(t) : K.parseCss(t); } catch (e) { err = String(e.message || e); }
      if (m && isHtml) for (const x of K.all(m)) { if (x.implied) continue; n++; const p = x.parent; if (!(x.from <= x.st[0] && x.st[1] <= x.to && (!x.et || (x.st[1] <= x.et[0] && x.et[1] === x.to)) && (!p || p.implied || (p.st[1] <= x.from && x.to <= (p.et ? p.et[0] : p.to))))) inv++; }
      if (m && !isHtml) for (const x of K.cssAll(m)) { n++; if (!(x.from < x.to && x.parent.from <= x.from && x.to <= x.parent.to)) inv++; for (const d of x.decls) if (!(x.bFrom < d.from && d.from < d.colon && d.colon < d.valFrom + 1 && d.valTo <= d.to && d.to < x.bTo)) inv++; }
      res.k1[f] = { bytes: bytes[f].length, identical: !!m && eqBytes(C.encode(m.src), bytes[f]), nodes: n, rangeViolations: inv, error: err,
        bom: bytes[f][0] === 0xEF && bytes[f][1] === 0xBB, crlf: (t.match(/\r\n/g) || []).length, trailingSpaceLines: (t.match(/[ \t]+\r?\n/g) || []).length };
    }
    for (const base of ['lexicon', 'handwritten', 'broken']) {
      await canvas.load(...pair(base));
      res.k1[base + '.html'].viaCanvas = eqBytes(C.encode(canvas.src), bytes[base + '.html']);
      res.k1[base + '.css'].viaCanvas = eqBytes(C.encode(canvas.cssSrc), bytes[base + '.css']);
    }
    res.k1.pass = FILES.every(f => res.k1[f].identical && res.k1[f].viaCanvas && !res.k1[f].rangeViolations);

    say('K3 — כל אלמנט נבחר');
    for (const f of HTML) {
      const base = f.replace('.html', '');
      await canvas.load(...pair(base));
      const m = canvas.m, doc = canvas.doc, domAll = Array.prototype.filter.call(doc.querySelectorAll('*'), d => !d.hasAttribute(C.OWNED));
      let badRange = 0, attrMis = 0, textMis = 0, implied = [], unmappedDom = 0;
      for (const d of domAll) {
        const n = canvas.d2m.get(d);
        if (!n) { unmappedDom++; continue; }
        if (n.implied) { implied.push(n.tag); continue; }
        if (m.src.slice(n.st[0], n.st[0] + 1 + n.tag.length).toLowerCase() !== '<' + n.tag.toLowerCase()) badRange++;
        const da = Array.prototype.filter.call(d.attributes, a => !(d.localName === 'link' && a.name === 'disabled'));
        if (da.length !== n.attrs.length || n.attrs.some(a => d.getAttribute(a.name) !== a.value || a.from < 0 || m.src.slice(a.from, a.from + a.name.length).toLowerCase() !== a.name.toLowerCase())) attrMis++;
        let dt = ''; for (const c of d.childNodes) if (c.nodeType === 3) dt += c.nodeValue;
        if (dt !== n.nodes.filter(x => x.type === 'text').map(x => x.value).join('')) textMis++;
      }
      // דגימת בחירה בפועל: נקודת האמצע של אלמנט נראה מחזירה אלמנט ממופה, והבחירה מציירת מסגרת
      const step = Math.max(1, Math.floor(domAll.length / 300)); let sampled = 0, hit = 0, boxed = 0, zero = 0;
      for (const d of domAll) { const r = d.getBoundingClientRect(); if (!r.width || !r.height) zero++; }
      for (let i = 0; i < domAll.length; i += step) {
        const d = domAll[i]; let r = d.getBoundingClientRect();
        if (!r.width || !r.height) continue;
        d.scrollIntoView({ block: 'center' }); r = d.getBoundingClientRect();
        const t = doc.elementFromPoint(Math.min(r.right - 1, Math.max(r.left + 1, r.left + r.width / 2)), r.top + r.height / 2);
        sampled++; if (t && canvas.d2m.get(t)) hit++;
        canvas.select(canvas.d2m.get(d)); if (!canvas.boxSel.hidden) boxed++;
      }
      canvas.select(null);
      const modelUnmapped = K.all(m).filter(n => !canvas.m2d.get(n)).length;
      res.k3[f] = { domElements: domAll.length, modelElements: m.count, mapped: canvas.d2m.size, unmappedDom, modelUnmapped, mapErrors: canvas.mapErrors.slice(0, 5), mapErrorCount: canvas.mapErrors.length,
        impliedNotInSource: implied, badRange, attrMismatch: attrMis, textMismatch: textMis, zeroSize: zero, sampled, sampledHit: hit, sampledBoxed: boxed,
        pass: !unmappedDom && !modelUnmapped && !canvas.mapErrors.length && !badRange && !attrMis && !textMis && hit === sampled && boxed === sampled };
    }
    res.k3.pass = ['lexicon.html', 'handwritten.html'].every(f => res.k3[f].pass);

    say('K2 — 30 פעולות × 2 קבצים');
    for (const base of ['lexicon', 'handwritten']) {
      await canvas.load(...pair(base));
      const list = [];
      for (const [id, kind, name, make] of OPS[base]) {
        const r = runOp(canvas, kind, make); r.id = id; r.kind = kind; r.name = name; list.push(r);
        if (!r.restored) await canvas.load(...pair(base));
        say('K2 ' + base + ' ' + id + (r.ok ? ' ✓' : ' ✗'));
      }
      res.k2[base] = { ops: list, total: list.length, pass: list.filter(r => r.ok).length, tight: list.filter(r => r.tight).length, inverseOp: list.filter(r => r.inverseOp).length };
    }
    res.k2.pass = ['lexicon', 'handwritten'].every(b => res.k2[b].pass === res.k2[b].total);
    for (const [id, base, kind, name, make] of PROBES) {
      await canvas.load(...pair(base));
      const r = runOp(canvas, kind, make); r.id = id; r.file = base; r.kind = kind; r.name = name; res.probes.push(r);
    }

    say('K4 — קוד שבור');
    {
      const k4 = { loadError: null, ok: [], rejected: [] };
      try {
        await canvas.load(...pair('broken'));
        const m = canvas.m, els = K.all(m), inRegion = n => { for (let p = n; p; p = p.parent) if (p.broken) return true; return false; };
        k4.htmlErrors = m.errors.map(e => e.code + '@' + e.at); k4.cssErrors = canvas.cssM.errors.map(e => e.code + '@' + e.at);
        k4.elements = els.length; k4.inBrokenRegion = els.filter(inRegion).map(n => n.tag + ((K.attrOf(n, 'id') || {}).value ? '#' + K.attrOf(n, 'id').value : ''));
        k4.outsideMapped = els.filter(n => !inRegion(n)).every(n => !!canvas.m2d.get(n)); k4.mapErrors = canvas.mapErrors.length;
        k4.cssRules = K.cssAll(canvas.cssM).length; k4.cssBroken = K.cssAll(canvas.cssM).filter(n => n.broken).map(n => n.sel);
        for (const [kind, name, make] of BROKEN_OK) { const r = runOp(canvas, kind, make); r.kind = kind; r.name = name; k4.ok.push(r); await canvas.load(...pair('broken')); }
        for (const [kind, name, fn] of BROKEN_NO) {
          const b0 = canvas.src, c0 = canvas.cssSrc, r = { kind, name, rejected: false };
          try { kind === 'html' ? canvas.apply(fn) : canvas.applyCss(fn); } catch (e) { r.rejected = e instanceof K.SrcError; r.code = e.code; r.message = e.message; }
          r.unchanged = canvas.src === b0 && canvas.cssSrc === c0 && eqBytes(C.encode(canvas.src), bytes['broken.html']) && eqBytes(C.encode(canvas.cssSrc), bytes['broken.css']);
          k4.rejected.push(r);
          if (!r.unchanged) await canvas.load(...pair('broken'));
        }
      } catch (e) { k4.loadError = String(e && e.stack || e); }
      k4.pass = !k4.loadError && k4.outsideMapped && k4.ok.every(r => r.ok) && k4.rejected.every(r => r.rejected && r.unchanged);
      res.k4 = k4;
    }

    say('K5 — בדיקת התקפה');
    {
      const got = []; const onMsg = e => { if (typeof e.data === 'string' && e.data.indexOf('kol-spike-pwned') === 0) got.push(e.data); };
      window.addEventListener('message', onMsg);
      await canvas.load(...pair('handwritten'));
      await new Promise(r => setTimeout(r, 400));
      const k5 = { sandbox: canvas.frame.getAttribute('sandbox'), scriptsInSource: (text['handwritten.html'].match(/<script/g) || []).length,
        handlersInSource: (text['handwritten.html'].match(/\son\w+=/g) || []).length, messagesFromCanvas: got.slice(), canvasTitle: canvas.doc.title };
      // בקרה חיובית: אותו קובץ במסגרת שכן מריצה סקריפטים — הגלאי חייב לתפוס
      got.length = 0;
      const ctl = document.createElement('iframe'); ctl.setAttribute('sandbox', 'allow-scripts'); ctl.style.cssText = 'position:absolute;width:10px;height:10px;opacity:0';
      ctl.srcdoc = text['handwritten.html'].replace(/^﻿/, ''); document.body.append(ctl);
      await new Promise(r => setTimeout(r, 900));
      k5.controlMessages = got.slice(); ctl.remove(); got.length = 0;
      k5.attackPassStatic = k5.messagesFromCanvas.length === 0 && k5.canvasTitle.indexOf('PWNED') < 0 && k5.controlMessages.length > 0;
      res.k5 = k5; res._pwn = got; window.__spike = { canvas, pwn: got, text, bytes };
    }

    say('K6 — מהירות');
    {
      const loads = [];
      for (let i = 0; i < 5; i++) loads.push(await canvas.load(...pair('lexicon')));
      const ops = res.k2.lexicon.ops.filter(o => o.timing), htmlOps = ops.filter(o => o.kind === 'html'), cssOps = ops.filter(o => o.kind === 'css');
      const col = (a, k) => a.map(o => o.timing[k]);
      res.k6 = { cards: K.all(canvas.m).filter(n => K.classesOf(n).includes('kadisha-word-card')).length, htmlBytes: bytes['lexicon.html'].length, elements: canvas.m.count,
        load: { runs: loads.map(l => r1(l.total)), medianMs: r1(median(loads.map(l => l.total))), parseHtmlMs: r1(median(loads.map(l => l.parseHtml))), parseCssMs: r1(median(loads.map(l => l.parseCss))),
          renderMs: r1(median(loads.map(l => l.render))), mapMs: r1(median(loads.map(l => l.map))), layoutMs: r1(median(loads.map(l => l.layout))) },
        opHtml: { n: htmlOps.length, medianMs: r1(median(col(htmlOps, 'total'))), maxMs: Math.max(...col(htmlOps, 'total')), reparseMs: r1(median(col(htmlOps, 'reparse'))), domMs: r1(median(col(htmlOps, 'dom'))), mapMs: r1(median(col(htmlOps, 'map'))), layoutMs: r1(median(col(htmlOps, 'layout'))), opMs: r1(median(col(htmlOps, 'op'))) },
        opCss: { n: cssOps.length, medianMs: r1(median(col(cssOps, 'total'))), maxMs: Math.max(...col(cssOps, 'total')), layoutMs: r1(median(col(cssOps, 'layout'))) } };
      // כמה היה עולה עדכון הדרגתי במקום ניתוח מחדש: הזזת כל הטווחים שאחרי התיקון + ניתוח של כרטיס אחד. הערכה בלבד — לא מומש.
      { const t0 = performance.now(); let c = 0; K.walk(canvas.m.root, n => { if (n.from >= 0) { n.from += 0; n.to += 0; n.st[0] += 0; n.st[1] += 0; if (n.et) { n.et[0] += 0; n.et[1] += 0; } for (const a of n.attrs) { a.from += 0; a.to += 0; } for (const x of n.nodes) if (x.type !== 'el') { x.from += 0; x.to += 0; } c++; } });
        const t1 = performance.now(), card = K.q(canvas.m, '.kadisha-word-card', 700); K.parseHtml(K.outer(canvas.m, card)); const t2 = performance.now();
        res.k6.incrementalEstimate = { shiftAllRangesMs: r1(t1 - t0), nodes: c, parseOneCardMs: r1(t2 - t1) }; }
      res.k6.loadPass = res.k6.load.medianMs <= 2000; res.k6.opPass = res.k6.opHtml.maxMs <= 100 && res.k6.opCss.maxMs <= 100;
    }
    res.finishedAt = new Date().toISOString();
    return res;
  }

  window.KolSpikeHarness = { run, OPS, PROBES, B, diffCheck };
})();
