/**
 * קול מרוקו — העורך החזותי, פרוסה V13: "🕘 היסטוריה" — היסטוריה אחת, עם שמות, לעריכה החזותית ולקוד (03 §11, 04 §1).
 *
 * העסקאות עצמן נשארות ב-screen.js (S.tx / S.rtx, pushTx / undoTx / redoTx). כאן:
 *  • מעקב אחרי המסמכים של העמוד (ה-HTML וקובצי ה-CSS שלו): שינוי שלא בא מהעורך החזותי — הקלדה בעורך הקוד — נרשם כעסקה
 *    "קוד · <קובץ> · N שורות" (הקלדה רצופה — שורה אחת), ולכן Ctrl+Z בקנבס מבטל גם אותה. ביטול בעורך הקוד (Ctrl+Z שם) — מזיז
 *    את העסקה שבראש הרשימה ל"חזור" כשהטקסט חזר בדיוק למצב שלפניה.
 *  • איחוד: פעולות רצופות על אותו ערך ("גודל · כותרת · 48 ← 50", ואחריה "… 50 ← 52") — שורה אחת "48 ← 52".
 *  • הלוח: כל צעד בשמו; לחיצה — חזרה לנקודה (מה שאחריה עובר ל"חזור"); "גרסאות" — היסטוריית הקבצים של F2 בשרת.
 * כל טקסט מהעמוד (שמות, בוררים, קבצים) — כטקסט בלבד (h / textContent); אין innerHTML.
 */
(function (g) {
  'use strict';
  const MAX = 500, MERGE_MS = 2500, CODE_MS = 4000;

  // ── חלק טהור (נבדק ב-tests/editor/history.mjs) ──
  /** המפתח לאיחוד: השם בלי הקטע האחרון כשהוא "ערך ← ערך". null — לא מתאחד. */
  function mergeKey(label) {
    const p = String(label || '').split(' · ');
    if (p.length < 2 || !/ ← /.test(p[p.length - 1])) return null;
    return p.slice(0, -1).join(' · ');
  }
  /** שני שמות רצופים ← שם אחד: "גודל · X · 48 ← 50" + "גודל · X · 50 ← 52" = "גודל · X · 48 ← 52". */
  function mergeLabel(a, b) {
    const k = mergeKey(a), pa = String(a).split(' · ').pop().split(' ← '), pb = String(b).split(' · ').pop().split(' ← ');
    return k + ' · ' + pa[0] + ' ← ' + pb[pb.length - 1];
  }
  /** כמה שורות השתנו בין שני טקסטים (הטווח שבין התחילית לסיומת המשותפות, בטקסט החדש — לפחות 1). */
  function linesChanged(a, b) {
    a = String(a); b = String(b); if (a === b) return 0;
    let i = 0; const max = Math.min(a.length, b.length); while (i < max && a.charCodeAt(i) === b.charCodeAt(i)) i++;
    let j = 0; while (j < max - i && a.charCodeAt(a.length - 1 - j) === b.charCodeAt(b.length - 1 - j)) j++;
    const seg = s => s.slice(i, s.length - j).split('\n').length;
    return Math.max(1, seg(a), seg(b));
  }
  const codeLabel = (path, n) => 'קוד · ' + path + ' · ' + (n === 1 ? 'שורה אחת' : n + ' שורות');

  class History {
    /** opt: { S, undo(), redo(), drawState(), note(), sheet(), api(), pathOf(doc), canWrite(), host } */
    constructor(opt) {
      Object.assign(this, opt);
      this.seen = new Map(); this.offs = new Map(); this.pend = new Map(); this.replaying = 0; this.flushing = false;
    }
    // ── מעקב ──
    /** מסמך של העמוד: הטקסט הנוכחי הוא נקודת המוצא; כל שינוי ממנו והלאה — נבדק. */
    track(doc) {
      if (!doc || this.offs.has(doc)) return;
      this.seen.set(doc, doc.text());
      this.offs.set(doc, doc.on((kind, info) => { if (kind === 'text') this.changed(doc, info); }));
    }
    untrackAll() { for (const off of this.offs.values()) off(); this.offs.clear(); this.seen.clear(); this.pend.clear(); }
    changed(doc, info) {
      if (this.replaying) return;
      let p = this.pend.get(doc);
      if (!p) { p = { pre: this.seen.has(doc) ? this.seen.get(doc) : doc.text(), lastTx: doc.lastTx, undo: false, redo: false }; this.pend.set(doc, p); }
      if (info && info.undo) p.undo = true;
      if (info && info.redo) p.redo = true;
      if (!this.flushing) { this.flushing = true; queueMicrotask(() => this.flush()); }
    }
    /** אחרי כל שינוי (מיקרו-משימה): פעולה של העורך — כבר נרשמה (pushTx); קוד — עסקה חדשה; ביטול / החזרה בעורך הקוד — התאמה. */
    flush() {
      this.flushing = false;
      const list = [...this.pend]; this.pend.clear();
      let any = false;
      for (const [doc, p] of list) {
        const now = doc.text();
        if (now === p.pre) { this.seen.set(doc, now); continue; }
        if (doc.lastTx !== p.lastTx && !p.undo && !p.redo) { this.seen.set(doc, now); continue; }   // עסקה עם שם (doc.edit) שלא דרך pushTx
        if (p.undo || p.redo) this.external(doc, now, p.undo);
        else this.record(doc, p.pre, now);
        this.seen.set(doc, now); any = true;
      }
      if (any) { this.drawState?.(); this.draw(); }
    }
    /** pushTx: הטקסט שלפני העסקה (מה שנראה אחרון), והטקסט אחריה — נקודת המוצא החדשה. */
    pushed(t) {
      t.before = t.before || new Map(); t.at = Date.now();
      for (const d of t.docs) { if (!t.before.has(d)) t.before.set(d, this.seen.has(d) ? this.seen.get(d) : null); this.pend.delete(d); }
      for (const d of t.docs) if (this.offs.has(d)) this.seen.set(d, t.after && t.after.has(d) ? t.after.get(d) : d.text());   // אותה מחרוזת — "לפני" של הבאה = "אחרי" של זו (בלי עותק)
    }
    /** ביטול / החזרה מהקנבס: השינויים אינם "קוד", ונקודת המוצא — הטקסט שאחריהם. */
    quiet(fn) {
      this.replaying++;
      try { return fn(); } finally { this.replaying--; for (const d of this.offs.keys()) this.seen.set(d, d.text()); for (const d of this.offs.keys()) this.pend.delete(d); }
    }
    /** הקלדה בעורך הקוד ← עסקה; הקלדה רצופה באותו קובץ (עד 4 שניות) — אותה שורה. */
    record(doc, pre, now) {
      const S = this.S, top = S.tx[S.tx.length - 1], path = this.pathOf(doc) || 'קובץ';
      if (top && top.code && top.docs.length === 1 && top.docs[0] === doc && Date.now() - top.at < CODE_MS && top.after.get(doc) === pre && !S.rtx.length) {
        top.after.set(doc, now); top.at = Date.now(); top.label = codeLabel(path, linesChanged(top.before.get(doc), now)); return;
      }
      S.tx.push({ docs: [doc], label: codeLabel(path, linesChanged(pre, now)), before: new Map([[doc, pre]]), after: new Map([[doc, now]]), code: true, at: Date.now() });
      S.rtx = []; if (S.tx.length > MAX) S.tx.shift();
    }
    /** Ctrl+Z / Ctrl+Y בעורך הקוד: העסקה שבראש הרשימה עוברת לצד השני כשהטקסט חזר בדיוק אליה. */
    external(doc, now, undo) {
      const S = this.S;
      if (undo) {
        const t = S.tx[S.tx.length - 1];
        if (t && t.docs.includes(doc)) {
          const others = t.docs.filter(d => d !== doc);
          if (t.before && t.before.get(doc) === now && others.every(d => d.text() === t.before.get(d))) { S.tx.pop(); S.rtx.push(t); return; }
          if (t.code && t.docs.length === 1) { t.after.set(doc, now); t.label = codeLabel(this.pathOf(doc) || 'קובץ', linesChanged(t.before.get(doc), now)); return; }   // חלק מההקלדה בוטל
        }
        return;
      }
      const t = S.rtx[S.rtx.length - 1];
      if (t && t.docs.includes(doc) && t.after.get(doc) === now) { S.rtx.pop(); S.tx.push(t); }
    }
    /** איחוד עם העסקה הקודמת (אותו ערך, ברצף): true — אוחדה ואין להוסיף. */
    merge(docs, label) {
      const S = this.S, top = S.tx[S.tx.length - 1], k = mergeKey(label);
      if (!k || !top || top.code || top.fx || S.rtx.length || mergeKey(top.label) !== k || Date.now() - (top.at || 0) > MERGE_MS) return false;
      const a = [...new Set(top.docs)], b = [...new Set(docs)];
      if (a.length !== b.length || a.some(d => !b.includes(d))) return false;
      top.docs = top.docs.concat(docs); top.label = mergeLabel(top.label, label); top.at = Date.now();
      for (const d of docs) { top.after.set(d, d.text()); if (this.offs.has(d)) this.seen.set(d, d.text()); this.pend.delete(d); }
      return true;
    }

    // ── הלוח ──
    /** הרשימה: הישן למעלה, החדש למטה; מה שבוטל ("חזור") — מתחת, מעומעם. */
    draw() {
      const host = this.host; if (!host || host.hidden) return;
      const S = this.S, done = S.tx, undone = S.rtx.slice().reverse();
      const row = (t, i, state) => h('button', { type: 'button', class: 'hs-row ' + state, 'data-hist': state === 'done' ? i : 'r' + i, 'aria-current': state === 'cur' ? 'step' : null,
        title: state === 'undone' ? 'בוטל — לחיצה מחזירה עד כאן' : 'לחיצה — חזרה לנקודה שאחרי הצעד הזה', onclick: () => this.jump(state === 'undone' ? done.length + i + 1 : i + 1) },
      h('span', { class: 'hs-ic', 'aria-hidden': 'true' }, t.code ? '</>' : t.fx && !t.docs.length ? '☁' : '✎'), h('span', { class: 'hs-lab', dir: 'auto' }, t.label || 'פעולה'));
      const list = h('div', { class: 'hs-list', role: 'list', 'data-hist-list': true },
        h('button', { type: 'button', class: 'hs-row ' + (done.length ? 'done' : 'cur'), 'data-hist': 'start', title: 'חזרה למצב שבו נפתח העמוד', onclick: () => this.jump(0) }, h('span', { class: 'hs-ic', 'aria-hidden': 'true' }, '⌂'), h('span', { class: 'hs-lab' }, 'פתיחת העמוד')),
        ...done.map((t, i) => row(t, i, i === done.length - 1 ? 'cur' : 'done')), ...undone.map((t, i) => row(t, i, 'undone')));
      const vers = h('div', { class: 'hs-vers', 'data-hist-vers': true });
      const vbtn = h('button', { type: 'button', class: 'btn sm', 'data-hist-vers-load': true, onclick: () => this.versions(vers) }, '🕘 הצג את הגרסאות השמורות');
      host.replaceChildren(h('p', { class: 'hint' }, done.length + ' צעדים' + (S.rtx.length ? ' · ' + S.rtx.length + ' ב"חזור"' : '') + ' · עד ' + MAX + ' בזיכרון. לחיצה על צעד — חזרה לנקודה (מה שאחריו נשאר ב"חזור"). אחרי רענון ההיסטוריה הזו נעלמת — הגרסאות השמורות למטה.'),
        list, h('h4', { class: 'dp-h' }, 'גרסאות (שמורות בשרת)'), h('p', { class: 'hint' }, 'כל שמירה של קובץ — עם שם הפעולה. נשארות גם אחרי רענון.'), vbtn, vers);
      const cur = list.querySelector('[aria-current]'); if (cur) cur.scrollIntoView({ block: 'nearest' });
    }
    /** חזרה לנקודה: k צעדים שבוצעו (0 — פתיחת העמוד). אחורה — ביטולים; קדימה — החזרות. */
    jump(k) {
      const S = this.S; let guard = 0;
      while (S.tx.length > k && guard++ < MAX + 5) { const n = S.tx.length; this.undo(); if (S.tx.length === n) break; }
      while (S.tx.length < k && S.rtx.length && guard++ < 2 * MAX + 10) { const n = S.tx.length; this.redo(); if (S.tx.length === n) break; }
      this.draw();
    }
    /** "גרסאות": היסטוריית הקבצים של F2 — לעמוד ולכל קובץ CSS שלו; "פתח" (השוואה) ו"חזור לגרסה" (כעסקה בעורך — Ctrl+Z). */
    async versions(box) {
      const S = this.S, docs = [S.doc, ...new Set([...S.sheets.values()].map(x => x.doc))].filter(Boolean);
      box.replaceChildren(h('p', { class: 'hint' }, 'טוען…'));
      const out = [];
      for (const d of docs) {
        const f = Project.docs.byPath(this.pathOf(d)); if (!f) continue;
        let r; try { r = await this.api('pf_versions', undefined, '&id=' + f.id); } catch (e) { out.push(h('p', { class: 'error' }, f.path + ': ' + e.message)); continue; }
        const vs = (r.versions || []).slice(0, 15);
        out.push(h('details', { class: 'hs-file', open: d === S.doc || null, 'data-vers-file': f.path }, h('summary', { dir: 'ltr' }, f.path + ' · ' + vs.length),
          vs.length ? h('ol', { class: 'hs-vlist' }, vs.map((v, i) => h('li', { 'data-rev': v.rev },
            h('span', {}, h('b', {}, 'גרסה ' + v.rev), ' · ', h('span', { dir: 'auto' }, v.note || ''), h('span', { class: 'hint' }, ' — ' + (v.by || '') + ' · ' + String(v.at || '').replace('T', ' ').slice(0, 16))),
            v.hid ? h('span', { class: 'hs-vbtn' },
              h('button', { type: 'button', class: 'btn sm', 'data-ver-open': v.hid, onclick: () => this.openVersion(f, d, v) }, '👁 פתח'),
              i > 0 ? h('button', { type: 'button', class: 'btn sm', 'data-ver-back': v.hid, disabled: !this.canWrite() || null, onclick: () => this.backTo(f, d, v) }, '↩️ חזור לגרסה') : h('span', { class: 'hint' }, ' (הנוכחית)')) : null))) : h('p', { class: 'hint' }, 'אין גרסאות שמורות.')));
      }
      box.replaceChildren(...(out.length ? out : [h('p', { class: 'hint' }, 'אין קבצים לעמוד הזה.')]));
    }
    async fetchVersion(f, v) { const r = await this.api('pf_version', undefined, '&id=' + f.id + '&hid=' + encodeURIComponent(v.hid)); if (r.binary) throw new Error('קובץ בינארי'); return r.content; }
    async openVersion(f, d, v) {
      try {
        const old = await this.fetchVersion(f, v);
        this.sheet('גרסה ' + v.rev + ' · ' + f.path, h('p', { class: 'hint' }, 'מימין לשמאל: גרסה ' + v.rev + ' מול מה שבעורך עכשיו.'), Project.diffView(old, d.text()),
          h('div', { class: 'ed-ask-bar' }, h('button', { type: 'button', class: 'btn primary', 'data-ver-back-go': v.hid, disabled: !this.canWrite() || null, onclick: () => { document.getElementById('sheet').close(); this.backTo(f, d, v, old); } }, '↩️ חזור לגרסה ' + v.rev)));
      } catch (e) { this.note(e.message, true); }
    }
    /** "חזור לגרסה": התוכן של הגרסה נכתב לקובץ כעסקה אחת בעורך — נשמר כגרסה חדשה, ו-Ctrl+Z מבטל. */
    async backTo(f, d, v, text) {
      try {
        if (text == null) text = await this.fetchVersion(f, v);
        const was = d.text(); if (was === text) return this.note('הקובץ כבר זהה לגרסה ' + v.rev);
        this.writeOps([{ doc: d, patches: [{ from: 0, to: was.length, text }] }], 'חזרה לגרסה ' + v.rev + ' · ' + f.path);
        this.note('הקובץ ' + f.path + ' חזר לגרסה ' + v.rev + ' — נשמר כגרסה חדשה', false, { label: 'בטל', run: this.undo });
        this.draw();
      } catch (e) { this.note(e.message, true); }
    }
  }

  g.KolHistory = { History, mergeKey, mergeLabel, linesChanged, codeLabel, MAX };
})(typeof window !== 'undefined' ? window : globalThis);
