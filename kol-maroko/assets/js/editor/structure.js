/**
 * קול מרוקו — 🎨 עורך (פרוסה V4): מבנה — "רובריקות בתוך רובריקות".
 *
 * החלק הטהור (בלי DOM; נבדק ב-node: tests/editor/structure.mjs): כל פעולת מבנה מחזירה תיקונים לקובץ ה-HTML, ולפני
 * שהיא מוחזרת — **הקובץ המתוקן מנותח מחדש ומושווה לעץ המבוקש**: האלמנטים שנוספו או הוזזו נמצאים תחת ההורה שנבחר,
 * באותו סדר תגיות כמו בניתוח של הקטע לבדו, ושום דבר אחר בעץ לא השתנה (מספר האלמנטים, אלמנטים שהדפדפן הוסיף, קוד שבור).
 * כשהדפדפן היה קורא את התוצאה אחרת — SrcError עם הסבר ועם הצעה (fix), והקובץ אינו משתנה.
 *
 * כאן אין innerHTML, ושום ערך אינו נכתב לקובץ בלי בריחה: שם תגית — רק [a-z][a-z0-9-]*, ערך תכונה — עם &quot;.
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc;
  const E = (msg, code, extra) => Object.assign(new (K().SrcError)(msg, code), extra || {});

  /** הספרייה: HTML מינימלי ותקני, בלי מחלקות של העורך ובלי style="". */
  const LIB = [
    { id: 'box', icon: '▭', label: 'רובריקה', html: '<div></div>' },
    { id: 'heading', icon: 'H', label: 'כותרת', html: '<h2>כותרת</h2>' },
    { id: 'text', icon: '¶', label: 'טקסט', html: '<p>טקסט</p>' },
    { id: 'image', icon: '🖼️', label: 'תמונה', html: '<img src="" alt="">' },
    { id: 'button', icon: '🔘', label: 'כפתור', html: '<button type="button">כפתור</button>' },
    { id: 'link', icon: '🔗', label: 'קישור', html: '<a href="">קישור</a>' },
    { id: 'list', icon: '☰', label: 'רשימה', html: '<ul><li>פריט</li></ul>' },
    { id: 'hr', icon: '―', label: 'מפריד', html: '<hr>' },
    { id: 'audio', icon: '🔊', label: 'שמע', html: '<audio controls></audio>' },
    { id: 'video', icon: '🎬', label: 'וידאו', html: '<video controls></video>' },
  ];
  /** תגיות להחלפה, לפי הקשר; וכל שם תגית תקני בהקלדה חופשית. */
  const TAGS = 'div section article header footer main nav aside ul ol li h1 h2 h3 h4 h5 h6 p span a button'.split(' ');
  const TAG_RE = /^[a-z][a-z0-9]*(-[a-z0-9]+)*$/;
  const RAW = new Set('script style textarea title xmp iframe noembed noframes noscript plaintext'.split(' '));
  const ROOTS = new Set(['html', 'head', 'body']);
  const HEADINGS = new Set('h1 h2 h3 h4 h5 h6'.split(' '));
  /** תוכן "בלוק" — פותח בדפדפן סגירה אוטומטית של <p> (ושל כותרת, כשזו כותרת). */
  const BLOCKY = new Set('address article aside blockquote details dialog dir div dl fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 header hgroup hr main menu nav ol p pre section table ul li dd dt'.split(' '));
  const INTERACTIVE = new Set(['a', 'button']);
  const IDREF_LIST = new Set(['aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-flowto', 'aria-details', 'aria-errormessage', 'aria-activedescendant', 'headers']);
  const IDREF_ONE = new Set(['for', 'list', 'form']);

  const isVoid = tag => K().VOID.has(tag);
  /** אפשר להכניס ילדים לאלמנט הזה (רובריקה): לא void ולא תוכן גולמי (סקריפט, textarea…). */
  const canHold = n => !!n && !isVoid(n.tag) && !RAW.has(n.tag) && !n.foreign;
  const inTemplate = n => { for (let p = n && n.parent; p; p = p.parent) if (p.tag === 'template') return p; return null; };
  const escAttr = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const label = n => { const id = K().attrOf(n, 'id'), c = K().classesOf(n)[0]; return n.tag + (id ? '#' + id.value : c ? '.' + c : ''); };
  const TPL_MSG = 'תבנית הכרטיס נערכת בגרירה ב-V11 — עד אז: פתח בקוד';

  // ── החלפת טקסט: כמה צעדים, תיקון אחד ─────────────────────
  function diff(was, now) {
    if (was === now) return [];
    let a = 0; const max = Math.min(was.length, now.length);
    while (a < max && was.charCodeAt(a) === now.charCodeAt(a)) a++;
    let b = 0; while (b < max - a && was.charCodeAt(was.length - 1 - b) === now.charCodeAt(now.length - 1 - b)) b++;
    return [{ from: a, to: was.length - b, text: now.slice(a, now.length - b) }];
  }
  /** מיקום בטקסט הישן ← בטקסט החדש (אחרי התיקונים). מה שנכתב בדיוק במיקום — לפניו. */
  function mapOff(patches, pos) {
    let d = 0;
    for (const p of patches) if (p.to <= pos && !(p.from === pos && p.to === pos)) d += p.text.length - (p.to - p.from); else if (p.from < pos && p.to > pos) return -1;
    return pos + d;
  }

  // ── האם הדפדפן יקרא את התוצאה כפי שנועדה ─────────────────
  /** סדר התגיות של קטע HTML כשהוא מנותח לבדו (בתוך <template> — שם כל תגית נשארת במקומה). */
  function intended(html) {
    const m = K().parseHtml('<!doctype html><html><head></head><body><template>' + html + '</template></body></html>');
    const body = m.root && m.root.kids.find(k => k.tag === 'body'), t = body && body.kids[0];
    if (!t || t.tag !== 'template') return null;
    const out = []; for (const k of t.kids) K().walk(k, n => out.push(n.tag));
    return { tags: out, top: t.kids.length };
  }
  const count = (m, pred) => { let c = 0; if (m.root) K().walk(m.root, n => { if (pred(n)) c++; }); return c; };
  /**
   * בודק את הקובץ המתוקן: pieces — [{ start, len, parentFrom, html }] במיקומים של הטקסט החדש. כל קטע: האלמנטים שבטווח
   * שלו הם בדיוק העץ המבוקש, והעליונים שבהם ילדים של ההורה שמתחיל ב-parentFrom. delta — כמה אלמנטים נוספו בסך הכול.
   * מחזיר את המודל החדש, או זורק SrcError (code: 'parse') עם הסבר.
   */
  function verify(m, src, pieces, delta) {
    const fresh = K().parseHtml(src);
    const bad = why => { throw E('הדפדפן היה קורא את התוצאה אחרת ממה שנועד (' + why + ') — הקובץ לא שונה', 'parse'); };
    if (fresh.count !== m.count + delta) bad('מספר האלמנטים ' + fresh.count + ' במקום ' + (m.count + delta));
    if (count(fresh, n => n.implied) !== count(m, n => n.implied)) bad('הדפדפן הוסיף אלמנט שאינו כתוב');
    if ((fresh.marks || 0) > (m.marks || 0) || count(fresh, n => n.broken) > count(m, n => n.broken)) bad('נוצר קוד שבור');
    for (const p of pieces) {
      const want = intended(p.html); if (!want) bad('הקטע אינו HTML תקין');
      const inside = []; K().walk(fresh.root, n => { if (!n.implied && n.from >= p.start && n.from < p.start + p.len) inside.push(n); });
      if (inside.map(n => n.tag).join(' ') !== want.tags.join(' ')) bad('המבנה בתוך האלמנט');
      const tops = inside.filter(n => !inside.includes(n.parent));
      if (tops.length !== want.top) bad('האלמנט נפתח לכמה אלמנטים');
      for (const t of tops) if (!t.parent || t.parent.implied || t.parent.from !== p.parentFrom) bad('<' + t.tag + '> נפל מחוץ לרובריקה');
    }
    return fresh;
  }

  /** האם האלמנטים (תגיות) יכולים להיכנס להורה — לפני כל כתיבה, גם כדי לצבוע יעד בגרירה. null — אפשר. */
  function why(par, tags, moving) {
    if (!par) return { msg: 'אין יעד' };
    if (par.implied && !ROOTS.has(par.tag)) return { msg: '<' + par.tag + '> נוסף בידי הדפדפן ואינו כתוב בקובץ' };
    if (par.tag === 'html' || par.tag === 'head') return { msg: 'בתוך <' + par.tag + '> אין תוכן נראה — גוררים אל תוך <body>' };
    if (par.tag === 'template' || inTemplate(par)) return { msg: TPL_MSG, code: 'template' };
    if (moving) for (const n of moving) if (inTemplate(n)) return { msg: TPL_MSG, code: 'template' };
    if (isVoid(par.tag)) return { msg: '<' + par.tag + '> אינו יכול להכיל אלמנטים' };
    if (!canHold(par)) return { msg: '<' + par.tag + '> מכיל טקסט גולמי בלבד, לא אלמנטים' };
    for (let p = par; p; p = p.parent) if (p.broken) return { msg: 'הרובריקה נמצאת באזור שבור בקוד', code: 'broken' };
    if (moving) for (const n of moving) for (let p = par; p; p = p.parent) if (p === n) return { msg: 'אי אפשר להכניס אלמנט לתוך עצמו', code: 'cycle' };
    if (par.tag === 'ul' || par.tag === 'ol') {
      const bad = tags.find(t => t !== 'li' && t !== 'script' && t !== 'template');
      if (bad) return { msg: 'בתוך <' + par.tag + '> רק <li> — עטוף את <' + bad + '> ב-<li> והכנס', fix: { kind: 'wrap', tag: 'li' } };
    }
    if (par.tag === 'p' || HEADINGS.has(par.tag)) {
      const bad = tags.find(t => BLOCKY.has(t) || (HEADINGS.has(par.tag) && HEADINGS.has(t)));
      if (bad) return { msg: '<' + par.tag + '> אינו יכול להכיל ' + (bad === 'div' ? 'רובריקה' : '<' + bad + '>') + ' — הפוך אותו ל-<div> והכנס', fix: { kind: 'retag', tag: 'div' } };
    }
    for (const t of tags) if (INTERACTIVE.has(t)) for (let p = par; p; p = p.parent) if (INTERACTIVE.has(p.tag)) return { msg: '<' + t + '> בתוך <' + p.tag + '> — הדפדפן סוגר את הראשון. מכניסים לפני או אחרי' };
    return null;
  }
  const tagsOf = html => { const w = intended(html); return w ? w.tags.slice(0, 1).concat() : []; };
  const topTags = html => { const m = K().parseHtml('<!doctype html><html><body><template>' + html + '</template></body></html>'); const b = m.root.kids.find(k => k.tag === 'body'); return b && b.kids[0] ? b.kids[0].kids.map(k => k.tag) : []; };
  function guardRoot(n, what) {
    if (ROOTS.has(n.tag)) throw E('<' + n.tag + '> — יש אחד כזה בעמוד; אינו ' + what + '. כל מה שבתוכו — כן', 'root');
    if (inTemplate(n)) throw E(TPL_MSG, 'template');
  }
  function check(par, tags, moving) { const w = why(par, tags, moving); if (w) throw E(w.msg, w.code || 'invalid', { fix: w.fix || null }); }

  // ── הזחה ───────────────────────────────────────────────────
  const lineIndent = (src, at) => { let s = at; while (s > 0 && src[s - 1] !== '\n' && src[s - 1] !== '\r') s--; let e = s; while (src[e] === ' ' || src[e] === '\t') e++; return src.slice(s, e); };
  /** אלמנט שהוזז מקבל את ההזחה של שכניו החדשים: כל שורה בתוכו מוזזת בהפרש (בלי pre / textarea / script / style). */
  function reindent(text, from, to) {
    if (from === to || !/\n/.test(text) || /<(pre|textarea|script|style|xmp|listing)\b/i.test(text)) return text;
    return text.replace(/(\r?\n)([ \t]*)/g, (all, nl, ws) => (ws.startsWith(from) ? nl + to + ws.slice(from.length) : all));
  }

  // ── הפעולות ───────────────────────────────────────────────
  /** הוספת html כילד ה-index של par. מחזיר { patches, start, model }. */
  function insert(m, par, index, html) {
    check(par, topTags(html), null);
    let res;
    const empty = !par.kids.length && par.et && !par.nodes.some(x => x.type !== 'text' || /\S/.test(x.value));
    if (empty && !/[\r\n]/.test(m.src.slice(par.from, par.to)) && par.tag !== 'body' && par.tag !== 'head') res = { patches: [{ from: par.st[1], to: par.et[0], text: html }] };   // רובריקה ריקה שכתובה בשורה אחת — נשארת בשורה אחת
    else res = K().html.insert(m, par, index, html);
    const p = res.patches[0];
    const at = mapOff(res.patches, p.from), start = at + p.text.indexOf(html);
    const src = K().applyPatches(m.src, res.patches), want = intended(html);
    const model = verify(m, src, [{ start, len: html.length, parentFrom: mapOff(res.patches, par.from), html }], want ? want.tags.length : 0);
    return { patches: res.patches, start, model, src };
  }
  /** הזזה של אלמנט אחד: הטווח עובר (עם השורה הריקה שצמודה אליו), ההזחה — של השכנים החדשים. */
  function move1(m, n, par, index) {
    guardRoot(n, 'נגרר');
    check(par, [n.tag], [n]);
    const res = K().html.move(m, n, par, index), ps = res.patches.map(p => Object.assign({}, p)), ins = ps[ps.length - 1];
    const out = K().outer(m, n), i = ins.text.lastIndexOf(out);
    let body = out, start = -1;
    if (i >= 0) {
      const before = ins.text.slice(0, i), nl = Math.max(before.lastIndexOf('\n'), before.lastIndexOf('\r'));
      const to = nl >= 0 ? before.slice(nl + 1) : null, from = K().leadOf(m, n) < n.from ? lineIndent(m.src, n.from) : null;
      if (to != null && from != null && /^[ \t]*$/.test(to)) body = reindent(out, from, to);
      const tail = ins.text.slice(i + out.length); ins.text = before + body + tail;
      const own = m.src.slice(K().leadOf(m, n), n.from);                  // שורה ריקה צמודה — נעה עם האלמנט
      if (/\n[ \t]*\r?\n/.test(own) && /^\r?\n/.test(ins.text) && !/^\r?\n[ \t]*\r?\n/.test(ins.text)) ins.text = m.nl + ins.text;
      start = mapOff(ps, ins.from) + ins.text.length - tail.length - body.length;
    }
    const src = K().applyPatches(m.src, ps);
    const model = verify(m, src, start >= 0 ? [{ start, len: body.length, parentFrom: mapOff(ps, par.from), html: body }] : [], 0);
    return { patches: ps, start, model, src };
  }
  /** כמה אלמנטים נגררים יחד: נוחתים ברצף, בסדר המקור, לפני האח ref (null — בסוף par). תיקון אחד. */
  function move(m, nodes, par, ref) {
    nodes = order(nodes);
    if (!nodes.length) throw E('אין מה להזיז', 'noop');
    for (const n of nodes) guardRoot(n, 'נגרר');
    check(par, nodes.map(n => n.tag), nodes);
    if (nodes.length === 1) {
      const n = nodes[0], sibs = par.kids.filter(k => k !== n), idx = ref ? sibs.indexOf(ref) : sibs.length;
      if (ref && idx < 0) throw E('היעד השתנה — נסו שוב', 'stale');
      return move1(m, n, par, idx);
    }
    // ברצף: כל אלמנט מוזז על הטקסט שאחרי הקודם; ההורה והאח מאותרים מחדש לפי המיקום שלהם
    const P = K().pathOf, B = K().byPath;
    let cur = m, refPath = ref ? P(ref) : null, parPath = P(par);
    const paths = nodes.map(P), starts = [];
    const relocate = (path, oldM, ps) => { const n = B(oldM, path); if (!n) return null; const at = mapOff(ps, n.from); let hit = null; K().walk(cur.root, x => { if (!hit && x.from === at && x.tag === n.tag) hit = x; }); return hit ? P(hit) : null; };
    let movedPaths = paths.slice(), lastMoved = null;
    for (let i = 0; i < nodes.length; i++) {
      const n = B(cur, movedPaths[i]), p = B(cur, parPath), r = refPath ? B(cur, refPath) : null;
      if (!n || !p) throw E('היעד השתנה — נסו שוב', 'stale');
      const sibs = p.kids.filter(k => k !== n);
      let idx = lastMoved ? sibs.indexOf(B(cur, lastMoved)) + 1 : r ? sibs.indexOf(r) : sibs.length;
      if (idx < 0) idx = sibs.length;
      if (p.kids.indexOf(n) === idx && p === n.parent) { lastMoved = movedPaths[i]; continue; }
      const res = move1(cur, n, p, idx), old = cur; cur = res.model;
      const rp = (path, self) => { if (!path) return null; if (self) { let hit = null; K().walk(cur.root, x => { if (!hit && x.from === res.start && x.tag === n.tag) hit = x; }); return hit ? P(hit) : null; } return relocate(path, old, res.patches); };
      lastMoved = rp(movedPaths[i], true);
      parPath = rp(parPath); refPath = refPath ? rp(refPath) : null;
      for (let j = i + 1; j < nodes.length; j++) movedPaths[j] = rp(movedPaths[j]);
      if (!parPath || !lastMoved) throw E('היעד השתנה — נסו שוב', 'stale');
      starts.push(res.start);
    }
    return { patches: diff(m.src, cur.src), model: cur, src: cur.src };
  }
  /** סדר המקור, בלי כפילויות ובלי אלמנט שאביו כבר ברשימה. */
  function order(nodes) {
    const set = new Set(nodes);
    return [...set].filter(n => { for (let p = n.parent; p; p = p.parent) if (set.has(p)) return false; return true; }).sort((a, b) => a.from - b.from);
  }

  /** שכפול: העתק מיד אחרי המקור. id כפול ← סיומת (-2), והפניות בתוך ההעתק (for, aria-*, href="#") — אליו. */
  function duplicate(m, n) {
    guardRoot(n, 'משוכפל');
    const res = K().html.duplicate(m, n), p = res.patches[0], out = K().outer(m, n);
    const used = new Set(); K().walk(m.root, x => { const a = K().attrOf(x, 'id'); if (a) used.add(a.value); });
    const ren = new Map(), changes = [];
    K().walk(n, x => { const a = K().attrOf(x, 'id'); if (!a || !a.value || ren.has(a.value)) return; let base = a.value.replace(/-\d+$/, ''), i = 2, v; do v = base + '-' + i++; while (used.has(v)); used.add(v); ren.set(a.value, v); changes.push(['id', a.value, v]); });
    let copy = out;
    if (ren.size) {
      const edits = [];
      K().walk(n, x => { for (const a of x.attrs) {
        if (a.from < 0) continue;
        let v = null;
        if (a.name === 'id' && ren.has(a.value)) v = ren.get(a.value);
        else if (IDREF_ONE.has(a.name) && ren.has(a.value)) v = ren.get(a.value);
        else if (IDREF_LIST.has(a.name) && a.value.split(/\s+/).some(t => ren.has(t))) v = a.value.split(/(\s+)/).map(t => ren.get(t) || t).join('');
        else if (a.name === 'href' && a.value[0] === '#' && ren.has(a.value.slice(1))) v = '#' + ren.get(a.value.slice(1));
        if (v == null) continue;
        if (a.name !== 'id') changes.push([a.name, a.value, v]);
        edits.push({ from: a.from - n.from, to: a.to - n.from, text: a.name + '="' + escAttr(v) + '"' });
      } });
      copy = K().applyPatches(out, edits);
    }
    const lead = p.text.slice(0, p.text.length - out.length), patches = [{ from: p.from, to: p.to, text: lead + copy }];
    const src = K().applyPatches(m.src, patches), start = mapOff(patches, p.from) + lead.length, want = intended(copy);
    const model = verify(m, src, [{ start, len: copy.length, parentFrom: mapOff(patches, n.parent.from), html: copy }], want ? want.tags.length : 0);
    return { patches, start, model, src, changes };
  }
  /** עטיפה ברובריקה (<div> כברירת מחדל) — אחים רצופים, בתוך השורה. */
  function wrap(m, nodes, tag = 'div', cls) {
    nodes = order(nodes);
    if (!nodes.length) throw E('אין מה לעטוף', 'noop');
    for (const n of nodes) { guardRoot(n, 'נעטף'); if (n.implied) throw E('<' + n.tag + '> נוסף בידי הדפדפן ואינו כתוב בקובץ', 'implied'); }
    const par = nodes[0].parent;
    if (!nodes.every(n => n.parent === par)) throw E('האלמנטים אינם באותה רובריקה', 'gap', { gap: true });
    const idx = nodes.map(n => par.kids.indexOf(n));
    for (let i = 1; i < idx.length; i++) if (idx[i] !== idx[i - 1] + 1) throw E('הבחירה אינה רצופה', 'gap', { gap: true });
    if (!TAG_RE.test(tag)) throw E('שם תגית לא תקין', 'invalid');
    check(par, [tag], null);
    const w = why({ tag, parent: par, kids: [], attrs: [] }, nodes.map(n => n.tag), null);
    if (w && !w.code) throw E(w.msg, 'invalid');
    const open = '<' + tag + (cls ? ' class="' + escAttr(cls) + '"' : '') + '>', close = '</' + tag + '>', last = nodes[nodes.length - 1], e = K().endOf(m, last);
    const patches = [{ from: nodes[0].from, to: nodes[0].from, text: open }, { from: e, to: e, text: close }];
    const src = K().applyPatches(m.src, patches), start = nodes[0].from, html = open + m.src.slice(nodes[0].from, e) + close;
    const model = verify(m, src, [{ start, len: html.length, parentFrom: mapOff(patches, par.from), html }], 1);
    return { patches, start, model, src };
  }
  /** פירוק: הרובריקה מוסרת, הילדים נשארים במקומה. */
  function unwrap(m, n) {
    guardRoot(n, 'מפורק');
    const w = n.parent && why(n.parent, n.kids.map(k => k.tag), null);
    if (w && !w.code) throw E('אחרי הפירוק ' + w.msg, 'invalid');
    const res = K().html.unwrap(m, n), src = K().applyPatches(m.src, res.patches);
    return { patches: res.patches, model: verify(m, src, [], -1), src };
  }
  /** החלפת תגית: השם בפתיחה ובסגירה בלבד. */
  function retag(m, n, tag) {
    tag = String(tag || '').trim().toLowerCase();
    if (!TAG_RE.test(tag)) throw E('"' + tag.slice(0, 40) + '" אינו שם תגית — אותיות לטיניות, ספרות ומקף בלבד', 'invalid');
    guardRoot(n, 'מוחלף');
    if (tag === n.tag) throw E('זו כבר התגית', 'noop');
    if (ROOTS.has(tag) || tag === 'template') throw E('<' + tag + '> אינו תגית לאלמנט בתוך העמוד', 'invalid');
    if (isVoid(tag) && (n.kids.length || n.nodes.some(x => x.type === 'text' && /\S/.test(x.value)))) throw E('<' + tag + '> אינו יכול להכיל תוכן', 'invalid');
    if (isVoid(n.tag) !== isVoid(tag)) throw E('<' + n.tag + '> ו-<' + tag + '> שונים במבנה (אחד מהם בלי תגית סגירה) — מוחקים ומוסיפים מהספרייה', 'invalid');
    if (!n.et && !isVoid(n.tag)) throw E('לאלמנט אין תגית סגירה כתובה — מוסיפים אותה בקוד, ואז מחליפים', 'invalid');
    const w = n.parent && why(n.parent, [tag], null);
    if (w && !w.code) throw E(w.msg, 'invalid');
    const res = K().html.retag(m, n, tag), src = K().applyPatches(m.src, res.patches);
    const html = src.slice(n.from, n.from + (n.to - n.from) + (n.et ? 2 * (tag.length - n.tag.length) : tag.length - n.tag.length));
    return { patches: res.patches, model: verify(m, src, [{ start: n.from, len: html.length, parentFrom: n.parent.from, html }], 0), src };
  }
  /** הדבקה: קטע HTML (מהלוח של העורך או מלוח המערכת) כילד ה-index של par. */
  function paste(m, par, index, html) {
    html = String(html || '').replace(/^﻿/, '');
    if (!html.trim()) throw E('הלוח ריק', 'noop');
    if (/<\/?(html|head|body)\b/i.test(html)) throw E('הקטע שבלוח הוא עמוד שלם — מעתיקים אלמנט', 'invalid');
    return insert(m, par, index, html.trim());
  }

  /** הצעה (fix) שהתווית הציעה: "הפוך ל-<div> והכנס" / "עטוף ב-<li>" — כמה צעדים, תיקון אחד. */
  function withFix(m, fix, par, run) {
    if (!fix) return run(m, par);
    const P = K().pathOf(par);
    if (fix.kind === 'retag') { const r = retag(m, par, fix.tag), p2 = K().byPath(r.model, P); const out = run(r.model, p2); return { patches: diff(m.src, out.src), model: out.model, src: out.src }; }
    return run(m, par);
  }

  // ── מה נשען על האלמנט ─────────────────────────────────────
  const FEATURES = { 'data-kol-search': 'החיפוש', 'data-kol-search-scope': 'הרחבת החיפוש', 'data-kol-list': 'רשימת המילים', 'data-kol-filter': 'סינון הנושאים', 'data-kol-count': 'המונה',
    'data-kol-empty': 'ההודעה "לא נמצאו"', 'data-kol-form': 'טופס המשוב', 'data-kol-dialog': 'חלון המשוב', 'data-kol-close': 'סגירת החלון', 'data-kol-text': 'טקסט מהנתונים' };
  /** אזהרות (לא חסימה): [{ kind, text }] — תכונות data-kol-*, id שמפנים אליו, ומחרוזות בקובצי JS (jsTexts: [{path,text}]). */
  function deps(m, nodes, jsTexts) {
    const out = [], ids = new Set(), classes = new Set(), inside = new Set();
    for (const n of order(nodes)) K().walk(n, x => {
      inside.add(x);
      for (const a of x.attrs) {
        if (/^data-kol-/.test(a.name)) { const f = FEATURES[a.name] || null; if (f && !out.some(o => o.text.includes(f))) out.push({ kind: 'data', text: f + ' (' + a.name + ' ב-' + label(x) + ') יפסיק לעבוד' }); }
        if (a.name === 'id' && a.value) ids.add(a.value);
        if (a.name === 'class') for (const c of a.value.split(/\s+/)) if (c) classes.add(c);
      }
    });
    if (ids.size) K().walk(m.root, x => {
      if (inside.has(x)) return;
      for (const a of x.attrs) {
        const refs = IDREF_ONE.has(a.name) ? [a.value] : IDREF_LIST.has(a.name) || a.name === 'data-panel' ? a.value.split(/\s+/) : a.name === 'href' && a.value[0] === '#' ? [a.value.slice(1)] : [];
        for (const r of refs) if (ids.has(r)) out.push({ kind: 'id', text: label(x) + ' מפנה אל #' + r + ' (' + a.name + ')' });
      }
    });
    for (const f of jsTexts || []) {
      const hit = [...ids].find(i => f.text.includes(i)) || [...classes].find(c => c.length > 3 && f.text.includes(c));
      if (hit) out.push({ kind: 'js', text: 'ייתכן ש-' + f.path + ' נשען על "' + hit + '"' });
    }
    return out;
  }
  /** הזזה שמוציאה אלמנט מתוך רובריקה עם data-kol-list / filter / form / dialog (או מכניסה לתוכה) — משנה למה הוא שייך. */
  function bindingOf(n) { for (let p = n; p; p = p.parent) for (const a of p.attrs) if (/^data-kol-(list|filter|form|dialog)$/.test(a.name)) return p; return null; }

  g.KolStruct = { LIB, TAGS, TAG_RE, TPL_MSG, ROOTS, canHold, inTemplate, why, check, insert, move, move1, order, duplicate, wrap, unwrap, retag, paste, withFix, deps, bindingOf, diff, verify, intended, topTags, reindent, mapOff, label, isVoid };
})(typeof window !== 'undefined' ? window : globalThis);
