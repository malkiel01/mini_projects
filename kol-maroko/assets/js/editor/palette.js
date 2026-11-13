/**
 * קול מרוקו — העורך החזותי, פרוסה V13: Ctrl+E — חיפוש פעולה (01-gap 1.14).
 *
 * חלון חיפוש על כל הפעולות (התפריט, הקיצורים, הוספה) ועל כל השליטות של הפאנל — בעברית ובשמות CSS: "צל" ← הקבוצה "צל וקו
 * מתאר" נפתחת ומודגשת; "letter-spacing" ← "ריווח אותיות"; "הוסף כותרת" ← הפעולה. שימוש אחרון — בראש (נשמר בדפדפן).
 * הטקסטים — כטקסט בלבד; אין innerHTML.
 */
(function (g) {
  'use strict';
  const LS = 'kol-editor-palette';
  /** השליטות שבפאנל: [לשונית, קבוצה (data-sec), שם, מאפיין CSS (data-field) | null, מילים נוספות]. הקבוצות המתקפלות
   *  (V10) נבנות רק כשנפתחות — ולכן הרשימה כתובה כאן, ובנוסף נאספת מהפאנל כפי שהוא מצויר (draw). */
  const CONTROLS = [
    ['design', 'type', 'גופן', 'font-family', 'font family גופנים'], ['design', 'type', 'גודל גופן', 'font-size', 'גודל טקסט size'], ['design', 'type', 'משקל', 'font-weight', 'מודגש bold'],
    ['design', 'type', 'נטוי', 'font-style', 'italic'], ['design', 'type', 'קו תחתון / חוצה', 'text-decoration-line', 'underline'], ['design', 'type', 'רישיות', 'text-transform', 'uppercase אותיות גדולות'],
    ['design', 'type', 'גובה שורה', 'line-height', 'ריווח שורות'], ['design', 'type', 'ריווח אותיות', 'letter-spacing', 'tracking'], ['design', 'type', 'ריווח מילים', 'word-spacing', ''],
    ['design', 'type', 'יישור טקסט', 'text-align', 'align מרכז'], ['design', 'type', 'כיוון', 'direction', 'rtl ltr'],
    ['design', 'color', 'צבע טקסט', 'color', 'צבע color'], ['design', 'color', 'צבע רקע', 'background-color', 'רקע background'],
    ['design', 'border', 'מסגרת ופינות', 'border', 'border-radius פינות עגולות מסגרת קו'], ['design', 'shadow', 'צל וקו מתאר', 'box-shadow', 'צל shadow text-shadow outline הצללה'],
    ['design', 'effects', 'שקיפות, מסננים ומיזוג', 'opacity', 'שקיפות filter blur mix-blend-mode backdrop-filter'], ['design', 'mask', 'מסיכה', 'mask-image', 'mask'],
    ['design', 'motion', 'מעבר בין מצבים', 'transition', 'transition אנימציה מעבר'],
    ['adv', 'space', 'מרווחים — שוליים וריפוד', 'margin', 'margin padding שוליים ריפוד מרווח'], ['adv', 'space', 'ריפוד', 'padding', 'padding'],
    ['adv', 'gap', 'רווח בין הילדים', 'gap', 'gap row-gap column-gap'], ['adv', 'display', 'פריסה — flex / grid', 'display', 'display flex grid פריסה'], ['adv', 'layout', 'כיוון — עמודה / שורה', null, 'flex-direction כיוון שורה עמודה'], ['design', 'bg', 'רקע — תמונה, מעבר צבע', 'background-image', 'background רקע תמונת רקע gradient'],
    ['adv', 'size', 'גודל — רוחב וגובה', 'width', 'width height רוחב גובה min-width max-width'], ['adv', 'pos', 'מיקום', 'position', 'position absolute fixed z-index'], ['adv', 'layers', 'שכבות', 'z-index', 'z-index שכבה'], ['adv', 'sticky', 'נדבק בגלילה', null, 'sticky נדבק'], ['adv', 'entrance', 'אנימציית כניסה', null, 'animation כניסה אנימציה'], ['adv', 'devices', 'נראות לפי מכשיר', null, 'display none הסתרה טלפון מכשיר'],
    ['adv', 'transform', 'הזזה, סיבוב והגדלה', 'transform', 'transform rotate scale סיבוב'], ['adv', 'identity', 'שם, מחלקות ותכונות', null, 'class id מחלקה זהות תכונות'],
  ];
  const norm = s => String(s || '').toLowerCase().replace(/[֑-ׇ]/g, '').replace(/\s+/g, ' ').trim();
  /** ציון התאמה: 0 — לא מתאים. */
  function score(q, text) {
    q = norm(q); text = norm(text); if (!q) return 1; if (!text) return 0;
    if (text === q) return 100; if (text.startsWith(q)) return 80; if (text.split(/[\s·,—/()-]+/).some(w => w.startsWith(q))) return 60; if (text.includes(q)) return 40;
    const ws = q.split(' ').filter(Boolean); if (ws.length > 1 && ws.every(w => text.includes(w))) return 30;
    return 0;
  }

  class Palette {
    /** opt: { actions (KolCtx.Actions), canvas, panel(id, on), setTab(id), tab(), designHost(), advHost(), note, phone } */
    constructor(opt) { Object.assign(this, opt); this.recent = []; try { this.recent = JSON.parse(localStorage.getItem(LS) || '[]') || []; } catch { this.recent = []; } }
    used(id) { this.recent = [id, ...this.recent.filter(x => x !== id)].slice(0, 12); try { localStorage.setItem(LS, JSON.stringify(this.recent)); } catch { /* חסום — בלי זיכרון */ } }
    /** הפריטים: פעולות על הנבחר, פעולות כלליות, ושליטות הפאנל (מהרשימה ומהפאנל המצויר). */
    items() {
      const A = this.actions, n = this.canvas.selected, out = [];
      for (const a of A.list()) out.push({ id: 'a:' + a.id, kind: 'action', icon: a.icon, label: a.label, keys: a.keys, words: a.id, why: n ? (a.why ? a.why(n) : '') : 'בוחרים אלמנט קודם', run: () => A.exec(a, n) });
      for (const a of A.global()) out.push({ id: 'g:' + a.id, kind: 'action', icon: a.icon, label: a.label, keys: a.keys, words: a.id, why: '', run: () => { A.recent?.(a.id); a.run(); } });
      const seen = new Set();
      for (const [tab, sec, label, prop, words] of CONTROLS) { seen.add(tab + '|' + (prop || sec)); out.push({ id: 'c:' + tab + ':' + sec + ':' + (prop || ''), kind: 'control', icon: tab === 'adv' ? '📐' : '🎨', label, keys: prop || '', words: (prop || '') + ' ' + words + ' ' + sec, why: n ? '' : 'בוחרים אלמנט קודם', run: () => this.reveal(tab, sec, prop) }); }
      for (const [tab, host] of [['design', this.designHost()], ['adv', this.advHost()]]) {
        if (!host) continue;
        for (const f of host.querySelectorAll('[data-field]')) {
          const prop = f.dataset.field, key = tab + '|' + prop; if (seen.has(key)) continue; seen.add(key);
          const sec = f.closest('[data-sec]')?.dataset.sec || '', lab = (f.querySelector('.sg-label')?.textContent || prop).trim();
          out.push({ id: 'c:' + tab + ':' + sec + ':' + prop, kind: 'control', icon: tab === 'adv' ? '📐' : '🎨', label: lab, keys: prop, words: prop + ' ' + sec, why: n ? '' : 'בוחרים אלמנט קודם', run: () => this.reveal(tab, sec, prop) });
        }
      }
      return out;
    }
    find(q) {
      const all = this.items(), rank = id => { const i = this.recent.indexOf(id); return i < 0 ? 0 : 12 - i; };
      if (!norm(q)) return all.filter(x => this.recent.includes(x.id)).sort((a, b) => rank(b.id) - rank(a.id)).concat(all.filter(x => !this.recent.includes(x.id))).slice(0, 40);
      return all.map(x => ({ x, s: Math.max(score(q, x.label), score(q, x.keys) * 0.95, score(q, x.words) * 0.8) })).filter(y => y.s > 0)
        .sort((a, b) => b.s - a.s || rank(b.x.id) - rank(a.x.id) || (a.x.why ? 1 : 0) - (b.x.why ? 1 : 0)).map(y => y.x).slice(0, 40);
    }
    /** פותח את הקבוצה בפאנל (גם מתקפלת), גולל אליה ומדגיש; השדה עצמו — כשקיים. */
    reveal(tab, sec, prop) {
      if (!this.canvas.selected) return this.note('בוחרים אלמנט, ואז מחפשים שליטה');
      this.panel('info', true); this.setTab(tab);
      const go = (tries) => {
        const host = tab === 'adv' ? this.advHost() : this.designHost(); if (!host) return;
        const box = host.querySelector('[data-sec="' + sec + '"]') || (prop && host.querySelector('[data-field="' + prop + '"]'));
        if (!box) { if (tries > 0) return setTimeout(() => go(tries - 1), 80); return this.note('השליטה הזו אינה זמינה לאלמנט הנבחר', true); }
        if (box.localName === 'details' && !box.open) { box.open = true; return setTimeout(() => go(tries), 60); }
        const f = (prop && box.querySelector('[data-field="' + prop + '"]')) || box;
        f.scrollIntoView({ block: 'center' });
        for (const x of host.querySelectorAll('.ed-flash')) x.classList.remove('ed-flash');
        f.classList.add('ed-flash'); box.classList.add('ed-flash'); setTimeout(() => { f.classList.remove('ed-flash'); box.classList.remove('ed-flash'); }, 2400);
        (f.querySelector('input, select, button:not([disabled])') || f).focus?.({ preventScroll: true });
      };
      setTimeout(() => go(6), 40);
    }
    open() {
      if (this.el) return this.close();
      const inp = h('input', { type: 'search', class: 'pl-in', dir: 'auto', placeholder: 'פעולה או שליטה — "צל", "letter-spacing", "הוסף כותרת"…', 'aria-label': 'חיפוש פעולה', autocomplete: 'off', spellcheck: 'false', 'data-palette-q': true });
      const list = h('div', { class: 'pl-list', role: 'listbox', 'aria-label': 'תוצאות', 'data-palette-list': true });
      let cur = 0, rows = [];
      const draw = () => {
        rows = this.find(inp.value); cur = Math.min(cur, Math.max(0, rows.length - 1));
        list.replaceChildren(...(rows.length ? rows.map((x, i) => {
          const b = h('button', { type: 'button', role: 'option', class: 'pl-row' + (i === cur ? ' on' : ''), 'aria-selected': String(i === cur), 'aria-disabled': x.why ? 'true' : null, 'data-pl': x.id, title: x.why || '' },
            h('span', { class: 'pl-ic', 'aria-hidden': 'true' }, x.icon), h('span', { class: 'pl-lab' }, x.label), x.keys ? h('code', { class: 'pl-key', dir: 'ltr' }, x.keys) : null, x.why ? h('span', { class: 'pl-why' }, x.why) : null,
            !inp.value && this.recent.includes(x.id) ? h('span', { class: 'pl-rec' }, '🕘') : null);
          b.addEventListener('click', () => pick(x)); return b;
        }) : [h('p', { class: 'hint' }, 'אין פעולה או שליטה כזו')]));
        list.querySelector('.on')?.scrollIntoView({ block: 'nearest' });
      };
      const pick = x => { if (!x) return; if (x.why) return this.note('⛔ ' + x.label + ': ' + x.why, true); this.used(x.id); this.close(); x.run(); };
      inp.addEventListener('input', () => { cur = 0; draw(); });
      inp.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown') { e.preventDefault(); cur = Math.min(rows.length - 1, cur + 1); draw(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); cur = Math.max(0, cur - 1); draw(); }
        else if (e.key === 'Enter') { e.preventDefault(); pick(rows[cur]); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(); }
      });
      this.back = document.activeElement;
      this.el = h('div', { class: 'pl', role: 'dialog', 'aria-label': 'חיפוש פעולה (Ctrl+E)', 'data-palette': true }, h('div', { class: 'pl-box' }, inp, list, h('p', { class: 'hint pl-foot' }, '↑ ↓ — בחירה · Enter — הפעלה · Esc — סגירה · שימוש אחרון — בראש')));
      this.el.addEventListener('pointerdown', e => { if (e.target === this.el) this.close(); });
      document.body.append(this.el); draw(); inp.focus();
      return this.el;
    }
    close() { if (!this.el) return; this.el.remove(); this.el = null; try { this.back?.focus?.({ preventScroll: true }); } catch { /* */ } }
  }

  g.KolPalette = { Palette, score, CONTROLS };
})(typeof window !== 'undefined' ? window : globalThis);
