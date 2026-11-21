/**
 * העורך החזותי — רצף פעולות אקראי בזרע קבוע, לבדיקת השקילות של המודל ההדרגתי (V1).
 * בלי DOM: רץ בדפדפן (הרתמה, דרך הקנבס) וב-Node (tests/editor/equiv.mjs, על המודל לבדו).
 */
(function (g) {
  'use strict';
  const K = g.KolSrc, H = K.html;
  /** מחולל פסאודו-אקראי דטרמיניסטי (mulberry32). */
  function rng(seed) { let a = seed >>> 0; return () => { a = (a + 0x6D2B79F5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }
  const TAGS = ['div', 'section', 'span', 'p', 'article', 'b'];
  const SNIPPETS = ['<p class="x-new">חדש</p>', '<div class="x-box"><span>א</span><span>ב</span></div>', '<img src="a.png" alt="">', '<ul><li>אחת<li>שתיים</ul>', '<!-- הערה --><b>מודגש</b>'];
  const NAMES = ['remove', 'duplicate', 'move', 'setText', 'setAttr', 'removeAttr', 'addClass', 'removeClass', 'wrap', 'unwrap', 'retag', 'insert'];
  /** פעולה אקראית על המודל m: מחזיר { name, run } — run(m) מחזירה {patches} או זורקת SrcError. */
  function pick(m, r) {
    const els = K.all(m).filter(n => !n.implied && n.parent && n.tag !== 'head' && n.tag !== 'body' && n.tag !== 'html');
    if (!els.length) return { name: 'insert', run: mm => H.insert(mm, K.q(mm, 'body'), 0, '<div class="x-refill"><p>א</p><p>ב</p></div>') };
    const one = a => a[Math.floor(r() * a.length)], n = one(els), name = one(NAMES), path = K.pathOf(n), at = mm => K.byPath(mm, path);
    const run = {
      remove: mm => H.remove(mm, at(mm)),
      duplicate: mm => H.duplicate(mm, at(mm)),
      move: mm => { const x = at(mm), par = r() < 0.7 ? x.parent : one(els.filter(e => e.et)); return H.move(mm, x, par, Math.floor(r() * (par.kids.length + 1))); },
      setText: mm => H.setText(mm, at(mm), 'טקסט ' + Math.floor(r() * 1e6) + ' <&> '),
      setAttr: mm => H.setAttr(mm, at(mm), one(['title', 'data-x', 'id', 'hidden']), r() < 0.2 ? null : 'ערך "' + Math.floor(r() * 1e4) + '"'),
      removeAttr: mm => { const x = at(mm); if (!x.attrs.length) throw new K.SrcError('אין תכונות', 'noop'); return H.removeAttr(mm, x, one(x.attrs).name); },
      addClass: mm => H.addClass(mm, at(mm), 'k' + Math.floor(r() * 50)),
      removeClass: mm => { const x = at(mm), c = K.classesOf(x); if (!c.length) throw new K.SrcError('אין מחלקות', 'noop'); return H.removeClass(mm, x, one(c)); },
      wrap: mm => H.wrap(mm, at(mm), one(TAGS), r() < 0.5 ? { class: 'w' } : null),
      unwrap: mm => H.unwrap(mm, at(mm)),
      retag: mm => H.retag(mm, at(mm), one(TAGS)),
      insert: mm => { const x = at(mm); return H.insert(mm, x, Math.floor(r() * (x.kids.length + 1)), one(SNIPPETS)); },
    }[name];
    return { name, run };
  }
  /**
   * מריץ n פעולות שהתקבלו (פעולה שנדחתה אינה נספרת). apply(run) מחיל פעולה ומחזיר { mode, why, diff } —
   * diff הוא ההבדל מול ניתוח מלא (null = שקול). model() מחזיר את המודל הנוכחי. undo() מבטל את הפעולה האחרונה.
   */
  function randomRun(opt) {
    const r = rng(opt.seed), out = { seed: opt.seed, asked: opt.n, applied: 0, rejected: 0, broke: 0, inc: 0, full: 0, inequivalent: 0, firstDiff: null, fullWhy: {}, byOp: {}, maxElements: 0 };
    for (let guard = 0; out.applied < opt.n && guard < opt.n * 20; guard++) {
      const m = opt.model(), op = pick(m, r);
      let res;
      try { res = opt.apply(op.run, op.name); }
      catch (e) { if (e instanceof K.SrcError) { out.rejected++; continue; } throw e; }
      out.applied++; out.byOp[op.name] = (out.byOp[op.name] || 0) + 1;
      if (res.mode === 'inc') out.inc++; else { out.full++; out.fullWhy[res.why] = (out.fullWhy[res.why] || 0) + 1; }
      if (res.diff) { out.inequivalent++; if (!out.firstDiff) out.firstDiff = { op: op.name, step: out.applied, diff: res.diff }; }
      // פעולה ששברה את הקובץ (למשל <div> בתוך <p>) מבוטלת — אחרת כל ההמשך היה רץ בניתוח מלא ולא בודק דבר
      const now = opt.model();
      if (now.marks || now.loose.length || !now.lex) { out.broke++; const u = opt.undo(); if (u && u.diff) { out.inequivalent++; if (!out.firstDiff) out.firstDiff = { op: 'undo:' + op.name, step: out.applied, diff: u.diff }; } }
      out.maxElements = Math.max(out.maxElements, opt.model().count);
      if (opt.model().count > (opt.maxElements || Infinity)) break;
    }
    return out;
  }
  g.KolEquiv = { rng, pick, randomRun };
})(typeof window !== 'undefined' ? window : globalThis);
