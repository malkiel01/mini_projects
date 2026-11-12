/**
 * העורך החזותי — מצבים (פרוסה V8, 03 §8.2): רגיל / ריחוף / מיקוד / לחוץ / ביקור / מושבת / ראשון / אחרון, וכל פסאודו-מחלקה
 * שהדפדפן מקבל; "מצב של: האלמנט / <הורה>".
 *
 * הקנבס מציג את המצב בלי עכבר: האלמנט (ובריחוף / לחיצה — גם האבות שלו, כמו בדפדפן) מקבל data-kol-st, ולכל כלל בקנבס
 * שיש בו את הפסאודו-מחלקה נוסף עותק מיד אחריו, שבו היא מוחלפת בתכונה — אותה ייחודיות ואותו מקום במפל. בקנבס בלבד, לא
 * בקובץ; הכללים המקוריים לא נוגעים, ולכן ריחוף אמיתי בקנבס עובד כרגיל. מצב מבני (‎:nth-child, ‎:first-child, ‎:has) — חל ממילא.
 */
(function () {
  'use strict';
  const DT = () => window.KolDetails, ST = () => window.KolStyle;
  const LIST = [['', 'רגיל'], [':hover', 'ריחוף'], [':focus', 'מיקוד'], [':active', 'לחוץ'], [':visited', 'ביקור (קישור)'], [':disabled', 'מושבת'], [':first-child', 'ראשון'], [':last-child', 'אחרון']];
  const NAMES = Object.fromEntries(LIST.filter(x => x[0]));
  const CHAIN = /^:(hover|active|focus-within)$/i;   // בדפדפן: ריחוף / לחיצה חלים גם על כל האבות

  class States {
    /** opt: { canvas, onChange(), note(msg, bad), K } */
    constructor(opt) { Object.assign(this, opt); this.pc = ''; this.ofPath = null; this.done = new WeakMap(); }
    get c() { return this.canvas; }
    label(pc) { return NAMES[pc] || pc; }
    /** המצב הנוכחי ליעד: { pc, of (בורר ההורה), label, ofNode } — או null (רגיל). */
    cur(n) {
      if (!this.pc) return null;
      const of = this.ofNode(n);
      return { pc: this.pc, of: of ? this.ofSel(of) : null, ofNode: of, label: this.label(this.pc) + (of ? ' של ' + this.c.label(of) : '') };
    }
    ofNode(n) { if (!this.ofPath || !n) return null; const p = this.K.byPath(this.c.m, this.ofPath); for (let x = n.parent; x; x = x.parent) if (x === p) return p; return null; }
    /** בורר ההורה: המחלקה הראשונה שלו, אחרת התגית. */
    ofSel(p) { const c = this.K.classesOf(p)[0]; return c ? '.' + c : p.tag; }
    set(pc) {
      try { if (pc) ST().checkState(pc); } catch (e) { this.note(e.message, true); return false; }
      this.pc = pc || ''; if (!pc) this.ofPath = null; this.apply(); this.onChange(); return true;
    }
    setOf(p) { this.ofPath = p ? this.K.pathOf(p) : null; this.apply(); this.onChange(); }
    /** האלמנטים שמקבלים את התכונה. */
    owners() {
      const n = this.c.selected; if (!this.pc || !n || !DT().stateRe(this.pc)) return [];
      const of = this.ofNode(n), e = this.c.elOf(of || n); if (!e) return [];
      const out = [e]; if (CHAIN.test(this.pc)) for (let x = e.parentElement; x; x = x.parentElement) out.push(x);
      return out;
    }
    /** מסנכרן את הקנבס עם המצב (נקרא בכל ציור — זול כשאין שינוי). */
    apply() {
      const c = this.c, doc = c && c.doc; if (!doc || !doc.documentElement) return;
      const A = DT().STATE_ATTR, key = this.pc ? DT().stateKey(this.pc) : '', want = new Set(this.owners());
      for (const e of doc.querySelectorAll('[' + A + ']')) if (!want.has(e)) e.removeAttribute(A);
      for (const e of want) if (e.getAttribute(A) !== key) e.setAttribute(A, key);
      const re = this.pc ? DT().stateRe(this.pc) : null, tag = '[' + A + '~="' + key + '"]';
      for (const sh of doc.styleSheets) {
        if (this.done.get(sh) === (re ? key : '')) continue;
        let rules; try { rules = sh.cssRules; } catch { continue; }
        strip(sh);
        if (re) copy(sh, re, tag);
        this.done.set(sh, re ? key : '');
      }
    }
    /** בורר המצב בראש הלשונית. */
    bar(n) {
      const box = h('div', { class: 'sg-states', 'data-states': true });
      const seg = h('span', { class: 'sg-seg sg-state-seg', role: 'group', 'aria-label': 'מצב' });
      for (const [pc, label] of LIST) seg.append(h('button', { type: 'button', 'data-state': pc || 'normal', 'aria-pressed': String(pc === this.pc), title: pc ? 'מעצבים את ' + pc + ' — הקנבס מציג אותו בלי עכבר' : 'המצב הרגיל', onclick: () => this.set(pc) }, label));
      const known = LIST.some(x => x[0] === this.pc);
      const typed = h('input', { type: 'text', dir: 'ltr', value: known ? '' : this.pc, placeholder: ':focus-visible · :nth-child(2n) · :has(img)', 'aria-label': 'מצב בהקלדה — כל פסאודו-מחלקה', spellcheck: 'false', list: 'sg-state-list', 'data-state-typed': true });
      const dl = h('datalist', { id: 'sg-state-list' }, [':focus-visible', ':focus-within', ':checked', ':invalid', ':placeholder-shown', ':nth-child(2n)', ':nth-child(odd)', ':only-child', ':empty', ':target', ':has(img)', ':not(:last-child)'].map(v => h('option', { value: v })));
      typed.addEventListener('change', () => { const v = typed.value.trim(); if (!v) { this.set(''); return; } if (!this.set(v)) { typed.classList.add('bad'); typed.setAttribute('aria-invalid', 'true'); } });
      box.append(seg, h('span', { class: 'sg-ctl wide sg-state-typed' }, typed, dl));
      if (this.pc && n) {
        const sel = h('select', { 'aria-label': 'מצב של', 'data-state-of': true }), of = this.ofNode(n);
        sel.append(h('option', { value: '' }, 'האלמנט עצמו'));
        let i = 0; for (let p = n.parent; p && i < 6; p = p.parent) { if (!this.c.elOf(p) || p.tag === 'html' || p.tag === 'body') continue; sel.append(h('option', { value: this.K.pathOf(p).join('.'), selected: p === of || null }, this.ofSel(p) + ' — ' + this.c.label(p))); i++; }
        sel.addEventListener('change', () => { const p = sel.value ? this.K.byPath(this.c.m, sel.value.split('.').map(Number)) : null; this.setOf(p); });
        box.append(h('label', { class: 'sg-line sg-state-of' }, h('span', { class: 'sg-label' }, 'מצב של:'), h('span', { class: 'sg-ctl wide' }, sel)));
        const sim = !!DT().stateRe(this.pc);
        box.append(h('p', { class: 'hint', 'data-state-note': true }, (sim ? '🖼️ הקנבס מציג את ' + this.label(this.pc) + ' בלי עכבר. ' : this.pc + ' הוא מצב מבני — חל לפי מקום האלמנט, ולא מדומה. ') + 'הערכים נכתבים לכלל ' + this.pc + ' מיד אחרי כלל היעד.'));
      }
      return box;
    }
  }
  /** מוחק את העותקים של המצב מהגיליון (בכל רמה). */
  function strip(sh) {
    const A = DT().STATE_ATTR;
    const go = list => { if (!list) return; for (let i = list.length - 1; i >= 0; i--) { const r = list[i]; if (r.selectorText != null && r.selectorText.includes(A)) { try { (r.parentRule || sh).deleteRule(i); } catch { /* */ } continue; } if (r.cssRules && r.selectorText == null) go(r.cssRules); } };
    try { go(sh.cssRules); } catch { /* */ }
  }
  /** לכל כלל עם הפסאודו-מחלקה — עותק מיד אחריו, שבו היא תכונה. */
  function copy(sh, re, tag) {
    const A = DT().STATE_ATTR;
    const go = (owner, list) => {
      for (let i = 0; i < list.length; i++) {
        const r = list[i];
        if (r.selectorText != null && r.style) {
          if (r.selectorText.includes(A)) continue;
          re.lastIndex = 0; if (!re.test(r.selectorText)) continue; re.lastIndex = 0;
          const sel = r.selectorText.replace(re, tag), body = r.cssText.startsWith(r.selectorText) ? r.cssText.slice(r.selectorText.length) : r.cssText.slice(r.cssText.indexOf('{'));
          try { owner.insertRule(sel + body, i + 1); i++; } catch { /* בורר שהדפדפן אינו מקבל — בלי עותק */ }
        } else if (r.cssRules && r.insertRule) go(r, r.cssRules);
      }
    };
    try { go(sh, sh.cssRules); } catch { /* */ }
  }
  window.KolStates = { States, LIST };
})();
