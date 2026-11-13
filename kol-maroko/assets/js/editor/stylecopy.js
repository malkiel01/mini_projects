/**
 * קול מרוקו — העורך החזותי, פרוסה V13: העתק / הדבק / אפס סגנון (03 §11, 01-gap 1.6).
 *
 * "שנכתבו לאלמנט" = הכלל של היעד שלו (03 §4.2 — הכלל של המחלקה שחל רק עליו) ותכונת style="", לכל מכשיר (@media של נקודות
 * השבירה) ולכל מצב (:hover / :focus …). כלל של מחלקה משותפת (חל על יותר מאלמנט אחד) — נפרד: בתצוגה המקדימה "המחלקה .btn
 * משותפת — להעתיק גם אותה?". ההדבקה — לפי כלל היעד של כל אלמנט, דרך writeStyle (checkValue, מכשיר, מצב, עסקה אחת, Ctrl+Z).
 * כל טקסט מהעמוד — כטקסט בלבד; אין innerHTML.
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc, ST = () => g.KolStyle;
  const STATES = ['hover', 'focus', 'focus-visible', 'focus-within', 'active', 'visited'];
  const norm = s => String(s).replace(/\s+/g, ' ').trim();
  const SYS = /^(kol-in(-[\w-]+)?|kol-sc|is-in|kol-motion-on|kol-slides-[\w-]+|bg-slides-\d+)$/;

  // ── חלק טהור (נבדק ב-tests/editor/stylecopy.mjs) ──
  /** הכללים של בורר בגיליון: הבסיס, בתוך @media של מכשיר (isDev(max)), ומצבים (<בורר>:hover). מחזיר
   *  [{ dev: max | null, state: pc | null, decls: [{prop, value, important}], node }]. */
  function collectSel(text, sel, isDev) {
    const m = K().parseCss(text), out = [], want = norm(sel);
    const stateOf = s => { s = norm(s); if (s === want) return ''; for (const pc of STATES) if (s === want + ':' + pc) return pc; return null; };
    const visit = (par, max) => {
      for (const r of par.kids) {
        if (r.type === 'rule') { const pc = stateOf(r.sel); if (pc == null || r.broken) continue; out.push({ dev: max, state: pc || null, decls: r.decls.filter(d => !d.broken).map(d => ({ prop: d.prop.trim(), value: d.value.trim(), important: d.important })), node: r }); }
        else if (r.type === 'at' && r.name.toLowerCase() === 'media' && max == null) { const mx = ST().mediaMax(r.sel); if (mx != null && (!isDev || isDev(mx))) visit(r, mx); }
      }
    };
    visit(m.root, null);
    return { m, items: out };
  }
  /** ההצהרות שבתכונת style. */
  function inlineDecls(style) {
    if (!String(style || '').trim()) return [];
    const m = K().parseCss('x{' + style + '}'), r = m.root.kids[0];
    return r && !r.broken ? r.decls.map(d => ({ prop: d.prop.trim(), value: d.value.trim(), important: d.important })) : [];
  }
  /** המאפיין שנכתב (writeStyle מקבל מאפיין פיזי): שם לוגי / פיזי של צד אחד ← הפיזי; קיצור — כמו שהוא. */
  function physOf(prop, rtl) { const c = ST().covered(prop, rtl); return c.length === 1 ? c[0] : String(prop).toLowerCase(); }
  /** קבוצות לכתיבה: [{ dev, state, sets: [[phys, value]] }] — אותו (מכשיר, מצב) מתאחד; ההצהרה האחרונה לכל מאפיין גוברת. */
  function groups(items, rtl) {
    const map = new Map();
    for (const it of items) {
      const k = (it.dev == null ? '' : it.dev) + '|' + (it.state || '');
      if (!map.has(k)) map.set(k, { dev: it.dev == null ? null : it.dev, state: it.state || null, sets: new Map() });
      for (const d of it.decls) { const p = physOf(d.prop, rtl); map.get(k).sets.delete(p); map.get(k).sets.set(p, d.value); }
    }
    return [...map.values()].map(x => ({ dev: x.dev, state: x.state, sets: [...x.sets] })).filter(x => x.sets.length).sort((a, b) => (a.dev == null ? 0 : 1) - (b.dev == null ? 0 : 1) || (a.state ? 1 : 0) - (b.state ? 1 : 0));
  }
  const declCount = items => items.reduce((s, it) => s + it.decls.length, 0);
  /** תיקונים לאיפוס: מחיקת הכללים (ו-@media שהתרוקן) בגיליון — טקסט חדש. */
  function resetText(text, nodes) {
    const m = K().parseCss(text), ids = new Set(nodes.map(n => n.from + ':' + n.to)), kill = [];
    const all = K().cssAll(m), hits = all.filter(r => r.type === 'rule' && ids.has(r.from + ':' + r.to));
    for (const r of hits) { const p = r.parent; if (p && p.type === 'at' && p.kids.every(k => hits.includes(k))) { if (!kill.includes(p)) kill.push(p); } else kill.push(r); }
    let out = text; for (const n of kill.sort((a, b) => b.from - a.from)) { const mm = K().parseCss(out), x = K().cssAll(mm).find(y => y.from === n.from && y.to === n.to); if (x) out = K().applyPatches(out, K().css.removeNode(mm, x).patches); }
    return out;
  }

  class StyleCopy {
    /** opt: { canvas, styler, writeStyle, writeOps, joinTx, txCount, note, sheet, undo, canWrite, html(), file(), devs() } */
    constructor(opt) { Object.assign(this, opt); this.clip = null; }
    isDev(mx) { return this.devs().some(d => d.max === mx); }
    devOf(mx) { const d = this.devs().find(x => x.max === mx); return d ? { id: d.id, label: d.label, max: d.max } : { id: 'bp-' + mx, label: mx + 'px', max: mx }; }
    /** מה נכתב לאלמנט: { label, own: {sel, path, items} | null, inline: [decls], shared: [{sel, path, items, count}] }. */
    gather(n) {
      const St = this.styler, c = this.canvas, info = St.info(n), ts = St.targets(n, info), out = { label: c.label(n), own: null, inline: inlineDecls((K().attrOf(n, 'style') || {}).value), shared: [] };
      const fromSheet = t => { if (t.r && t.r.src && t.r.src.html) return null; const doc = St.docOf(t.path); if (!doc) return null; const r = collectSel(doc.text(), t.sel, mx => this.isDev(mx)); return { sel: norm(t.sel), path: t.path, doc, items: r.items, count: t.count }; };
      const rules = ts.filter(t => t.kind === 'rule' && t.why === 'class' && !t.media);
      const own = rules.find(t => t.count === 1);
      if (own) out.own = fromSheet(own);
      const seen = new Set(own ? [norm(own.sel)] : []);
      for (const t of rules) { if (t.count <= 1 || seen.has(norm(t.sel))) continue; const cls = K().classesOf(n); if (cls.length && cls.every(x => SYS.test(x))) continue; seen.add(norm(t.sel)); const x = fromSheet(t); if (x && x.items.length) out.shared.push(x); }
      return out;
    }
    /** "העתק סגנון" (Ctrl+Shift+C). */
    copy(n) {
      n = n || this.canvas.selected; if (!n) return this.note('בוחרים אלמנט, ואז מעתיקים את הסגנון שלו');
      const x = this.gather(n), own = (x.own ? declCount(x.own.items) : 0) + x.inline.length;
      this.clip = Object.assign(x, { at: Date.now() });
      this.note('📋 הסגנון של ' + x.label + ' הועתק: ' + own + ' הצהרות' + (x.shared.length ? ' · ' + x.shared.length + ' מחלקות משותפות (ההדבקה תשאל)' : '') + ' — Ctrl+Shift+V מדביק');
      return x;
    }
    /** כל מה שיודבק, לפי הבחירה: items (כולל style="" כבסיס). */
    items(clip, withShared) {
      const out = []; if (clip.own) out.push(...clip.own.items);
      if (clip.inline.length) out.push({ dev: null, state: null, decls: clip.inline });
      for (const s of clip.shared) if (withShared.has(s.sel)) out.push(...s.items);
      return out;
    }
    /** "הדבק סגנון" (Ctrl+Shift+V): תצוגה מקדימה של השורות לכל אלמנט ← אישור ← עסקה אחת. */
    paste(nodes, clip) {
      clip = clip || this.clip; nodes = (nodes || this.canvas.picked()).filter(Boolean);
      if (!clip) return this.note('אין סגנון בלוח — "העתק סגנון" (Ctrl+Shift+C) קודם', true);
      if (!nodes.length) return this.note('בוחרים אלמנט (או כמה), ואז מדביקים סגנון');
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      const want = new Set(), list = h('div', { class: 'scp-lines', 'data-scp-lines': true }), sum = h('p', { class: 'scp-sum', role: 'status', 'data-scp-sum': true });
      const draw = () => {
        const its = this.items(clip, want), rows = [];
        let total = 0;
        for (const n of nodes) {
          const t = this.styler.targets(n)[0], rtl = this.styler.rtl(n), gs = groups(its, rtl), sel = t.kind === 'inline' ? 'style=""' : t.kind === 'newclass' ? '.' + t.name + ' (מחלקה חדשה)' : t.sel;
          total += gs.reduce((s, x) => s + x.sets.length, 0);
          rows.push(h('div', { class: 'scp-el', 'data-scp-el': this.canvas.label(n) }, h('b', {}, this.canvas.label(n)), h('span', { class: 'hint', dir: 'ltr' }, ' → ' + sel + (t.path ? ' · ' + t.path : '')),
            ...gs.map(x => h('pre', { class: 'scp-pre', dir: 'ltr' }, (x.dev != null ? '@media (max-width: ' + x.dev + 'px) · ' : '') + (x.state ? ':' + x.state + ' · ' : '') + x.sets.map(([p, v]) => p + ': ' + v + ';').join('\n')))));
          if (t.kind === 'inline' && gs.some(x => x.dev != null || x.state)) rows.push(h('p', { class: 'ed-warn' }, '⚠️ לאלמנט אין כלל CSS — מכשיר ומצב ייכתבו למחלקה חדשה.'));
        }
        list.replaceChildren(...rows);
        sum.textContent = 'ייכתבו ' + total + ' הצהרות ל-' + nodes.length + (nodes.length === 1 ? ' אלמנט' : ' אלמנטים');
        go.disabled = !total;
      };
      const shared = clip.shared.map(s => { const cb = h('input', { type: 'checkbox', 'data-scp-shared': s.sel }); cb.addEventListener('change', () => { if (cb.checked) want.add(s.sel); else want.delete(s.sel); draw(); }); return h('label', { class: 'scp-shared' }, cb, ' המחלקה ', h('code', { dir: 'ltr' }, s.sel), ' משותפת (' + s.count + ' אלמנטים) — להעתיק גם אותה? ' + declCount(s.items) + ' הצהרות'); });
      const go = h('button', { type: 'button', class: 'btn primary', 'data-scp-go': true }, '🖌 הדבק סגנון');
      const dlg = this.sheet('🖌 הדבק סגנון · מ-' + clip.label, h('p', { class: 'hint' }, 'ההצהרות שנכתבו ל-' + clip.label + ' (הכלל שלו ו-style="", לכל מכשיר ומצב) נכתבות לכלל היעד של כל אלמנט.'), ...shared, sum, list, h('div', { class: 'ed-ask-bar' }, go));
      draw(); setTimeout(() => go.focus(), 0);
      go.addEventListener('click', () => { dlg.close(); this.apply(nodes, this.items(clip, want), clip.label); });
      return dlg;
    }
    /** הכתיבה: לכל אלמנט ולכל (מכשיר, מצב) — writeStyle על היעד העדכני (מחלקה חדשה נוצרת פעם אחת); הכול — עסקה אחת. */
    apply(nodes, its, from) {
      const K2 = K(), c = this.canvas, St = this.styler, paths = nodes.map(n => K2.pathOf(n)), n0 = this.txCount(); let count = 0;
      for (const p of paths) {
        for (const gr of groups(its, St.rtl(K2.byPath(c.m, p)))) {
          const n = K2.byPath(c.m, p); if (!n) break;
          let t = St.targets(n)[0];
          if (t.kind === 'inline' && (gr.dev != null || gr.state)) t = { kind: 'newclass', sel: '.' + St.suggest(n), name: St.suggest(n), path: St.pagePath(), why: 'class', count: 1 };
          t = Object.assign({}, t, { dev: gr.dev != null && t.kind !== 'inline' ? this.devOf(gr.dev) : null });
          if (gr.state) t = St.st(t, { pc: ':' + gr.state, label: gr.state });
          const docs = this.writeStyle(new Map([[n, gr.sets.map(([ph, v]) => [ph, v, t])]]), 'הדבק סגנון · ' + c.label(n));
          if (docs == null) { const k = this.txCount() - n0; if (k > 1) this.joinTx(k, 'הדבק סגנון (חלקי)'); return false; }
          count += gr.sets.length;
        }
      }
      const k = this.txCount() - n0, label = 'הדבק סגנון · ' + (nodes.length > 1 ? nodes.length + ' אלמנטים' : c.label(K2.byPath(c.m, paths[0]) || nodes[0])) + ' ← ' + from + ' · ' + count + ' הצהרות';
      if (k > 1) this.joinTx(k, label); else if (k === 1) { const S = this.state(); S.tx[S.tx.length - 1].label = label; }
      const first = K2.byPath(c.m, paths[0]); if (first) c.select(first);
      if (k) this.note('🖌 ' + label, false, { label: 'בטל', run: this.undo }); else this.note('אין מה להדביק — הסגנון כבר זהה');
      return true;
    }
    /** "אפס סגנון": מוחק את מה שנכתב לאלמנט (הכלל שלו — בכל מכשיר ומצב — ו-style=""), לא מחלקות משותפות; רשימה לפני. */
    reset(n) {
      n = n || this.canvas.selected; if (!n) return this.note('בוחרים אלמנט, ואז מאפסים');
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      const x = this.gather(n), lines = [];
      if (x.own) for (const it of x.own.items) lines.push((it.dev != null ? '@media (max-width: ' + it.dev + 'px) · ' : '') + x.own.sel + (it.state ? ':' + it.state : '') + ' { ' + it.decls.map(d => d.prop + ': ' + d.value).join('; ') + ' } · ' + x.own.path);
      if (x.inline.length) lines.push('style="' + x.inline.map(d => d.prop + ': ' + d.value).join('; ') + '"');
      const cnt = (x.own ? declCount(x.own.items) : 0) + x.inline.length;
      if (!cnt) return this.note('לא נכתב ל-' + x.label + ' סגנון משלו' + (x.shared.length ? ' — רק מחלקות משותפות (' + x.shared.map(s => s.sel).join(', ') + '), והן אינן מתאפסות מכאן' : ''));
      const go = h('button', { type: 'button', class: 'btn danger', 'data-reset-go': true }, '⟲ אפס ' + cnt + ' הצהרות');
      const dlg = this.sheet('⟲ אפס סגנון · ' + x.label, h('p', { class: 'hint', 'data-reset-sum': cnt }, 'יימחקו ' + cnt + ' הצהרות שנכתבו לאלמנט הזה:'), h('pre', { class: 'scp-pre', dir: 'ltr', 'data-reset-lines': true }, lines.join('\n')),
        x.shared.length ? h('p', { class: 'hint' }, 'נשארות (משותפות): ' + x.shared.map(s => s.sel).join(', ')) : null, h('div', { class: 'ed-ask-bar' }, go));
      setTimeout(() => go.focus(), 0);
      go.addEventListener('click', () => {
        dlg.close();
        const K2 = K(), P = K2.pathOf(n), ops = [];
        if (x.own && x.own.items.length) ops.push(() => { const d = x.own.doc, t = d.text(), fresh = collectSel(t, x.own.sel, mx => this.isDev(mx)), nt = resetText(t, fresh.items.map(i => i.node)); return nt === t ? [] : [{ doc: d, patches: [this.diff(t, nt)] }]; });
        if (x.inline.length) ops.push(() => { const m = this.canvas.m, cur = K2.byPath(m, P); return cur && K2.attrOf(cur, 'style') ? [{ doc: this.html(), patches: K2.html.removeAttr(m, cur, 'style').patches }] : []; });
        const label = 'אפס סגנון · ' + x.label + ' · ' + cnt + ' הצהרות';
        if (this.writeOps(ops, label)) this.note('⟲ ' + label, false, { label: 'בטל', run: this.undo });
      });
      return dlg;
    }
    diff(a, b) { let i = 0; while (i < a.length && i < b.length && a[i] === b[i]) i++; let j = 0; while (j < a.length - i && j < b.length - i && a[a.length - 1 - j] === b[b.length - 1 - j]) j++; return { from: i, to: a.length - j, text: b.slice(i, b.length - j) }; }

    // ── Alt + גרירה: מכחול ──
    /** מהקנבס: start (המקור), move (היעד שמתחת), drop / cancel. */
    altDrag(phase, src, tgt) {
      const c = this.canvas, root = c.doc && c.doc.documentElement;
      if (phase === 'start') { this.brush = { src }; if (root) root.style.setProperty('cursor', 'copy', 'important'); this.tip = this.tip || h('div', { class: 'scp-tip', 'data-brush': true }); c.layer.append(this.tip); this.tip.textContent = '🖌 הדבק סגנון מ-' + c.label(src); c.select(src); return; }
      if (!this.brush) return;
      if (phase === 'move') { this.brush.tgt = tgt && tgt !== this.brush.src ? tgt : null; if (this.tip) { const r = this.brush.tgt && c.rectOf(this.brush.tgt); this.tip.textContent = '🖌 ' + c.label(this.brush.src) + (this.brush.tgt ? ' → ' + c.label(this.brush.tgt) : ''); if (r) this.tip.style.cssText = 'left:' + r.left + 'px;top:' + Math.max(0, r.top - 26) + 'px'; } if (this.brush.tgt) c.hover?.(this.brush.tgt); return; }
      const b = this.brush; this.brush = null; if (root) root.style.removeProperty('cursor'); this.tip?.remove();
      if (phase !== 'drop' || !b.tgt) return;
      const clip = Object.assign(this.gather(b.src), { at: Date.now() });
      c.select(b.tgt);
      return this.paste([b.tgt], clip);
    }
  }

  g.KolStyleCopy = { StyleCopy, collectSel, inlineDecls, physOf, groups, resetText, declCount, STATES };
})(typeof window !== 'undefined' ? window : globalThis);
