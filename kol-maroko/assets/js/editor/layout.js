/**
 * העורך החזותי — פריסה (flex / grid), ילד של flex, גודל, וידיות גודל על הקנבס (פרוסה V6, 03 §4.3).
 *
 * כל שליטה היא השליטה של V3 (Design.num / choice / seg): מקור, נדרס, איפוס, הקלדה חופשית — והכתיבה בכלל היעד. הסמלים
 * של היישור אומרים את הצד בפועל (שורה בעברית: "התחלה" = ימין). ידיות הגודל — ריבועים על קו המסגרת (שמונה); הרצועות
 * הצבועות של V3 הן מרווח. כל ערך מהעמוד — כטקסט; אין innerHTML.
 */
(function (g) {
  'use strict';
  const hh = (...a) => g.h(...a);
  /** הצד הפיזי של "התחלה" / "סוף" בציר: dir = flex-direction, rtl. */
  function sides(dir, rtl) {
    const col = /column/.test(dir), rev = /reverse/.test(dir);
    if (col) return rev ? ['למטה', 'למעלה'] : ['למעלה', 'למטה'];
    const st = rtl ? 'ימין' : 'שמאל', en = rtl ? 'שמאל' : 'ימין';
    return rev ? [en, st] : [st, en];
  }
  class Layout {
    /** o: { design (לשונית "מתקדם"), write, note, undo, phone, layer(), busy() } */
    constructor(o) { Object.assign(this, o); }
    get D() { return this.design; } get c() { return this.design.canvas; }
    win() { return this.c.frame.contentWindow; }
    cs(n) { const e = this.c.elOf(n); return e ? this.win().getComputedStyle(e) : null; }
    sections(n) {
      const e = this.c.elOf(n); if (!e || /^(html|head)$/.test(n.tag)) return [];
      return [this.display(n), this.flexBox(n), this.gridBox(n), this.child(n), this.size(n)].filter(Boolean);
    }
    display(n) {
      const D = this.D, sec = hh('section', { class: 'sg-sec', 'data-sec': 'display' }, hh('h3', { class: 'dt-h' }, 'תצוגה'));
      sec.append(D.choice('display', 'display', [['block', 'זרימה רגילה (block)'], ['flex', 'flex'], ['grid', 'grid'], ['inline', 'בתוך שורה (inline)'], ['inline-block', 'inline-block'], ['inline-flex', 'inline-flex'], ['inline-grid', 'inline-grid'], ['contents', 'contents'], ['none', 'הסתר (none)']]));
      if (this.cs(n).display === 'none') sec.append(hh('p', { class: 'hint' }, 'מוסתר (display: none) — נשאר ב-Navigator, "מוצג לעריכה".'));
      return sec;
    }
    flexBox(n) {
      const s = this.cs(n); if (!/flex/.test(s.display)) return null;
      const D = this.D, rtl = s.direction === 'rtl', [a, b] = sides(s.flexDirection, rtl), [c1, c2] = sides(/column/.test(s.flexDirection) ? 'row' : 'column', rtl);
      const sec = hh('section', { class: 'sg-sec', 'data-sec': 'flex' }, hh('h3', { class: 'dt-h' }, 'יישור הילדים · flex ' + s.flexDirection));
      sec.append(D.seg('justify-content', 'ציר ראשי', [['flex-start', '⇤', 'התחלה (' + a + ')'], ['center', '⇔', 'מרכז'], ['flex-end', '⇥', 'סוף (' + b + ')'], ['space-between', '↤↦', 'רווח בין'], ['space-around', '·↔·', 'רווח סביב'], ['space-evenly', '=', 'רווח שווה']]));
      sec.append(D.seg('align-items', 'ציר ניצב', [['flex-start', '⤒', 'התחלה (' + c1 + ')'], ['center', '⇕', 'מרכז'], ['flex-end', '⤓', 'סוף (' + c2 + ')'], ['stretch', '↕', 'מתיחה'], ['baseline', '_', 'קו בסיס']]));
      sec.append(D.seg('flex-wrap', 'גלישה', [['nowrap', '→', 'בלי גלישה'], ['wrap', '↩', 'גלישה'], ['wrap-reverse', '↪', 'גלישה הפוכה']]));
      if (s.flexWrap !== 'nowrap') sec.append(D.seg('align-content', 'שורות', [['flex-start', '⤒', 'התחלה'], ['center', '⇕', 'מרכז'], ['flex-end', '⤓', 'סוף'], ['space-between', '↕', 'רווח בין'], ['stretch', '▮', 'מתיחה']]));
      sec.append(D.num('row-gap', 'רווח שורות', { group: 'gap' }), D.num('column-gap', 'רווח עמודות', { group: 'gap' }));
      sec.append(hh('p', { class: 'hint' }, 'הסמלים לפי הכיוון בפועל (' + s.flexDirection + ', ' + s.direction + '). כל ערך — גם בהקלדה, בשדה הקוד.'));
      return sec;
    }
    gridBox(n) {
      const s = this.cs(n); if (!/grid/.test(s.display)) return null;
      const D = this.D, sec = hh('section', { class: 'sg-sec', 'data-sec': 'grid' }, hh('h3', { class: 'dt-h' }, 'רשת (grid)'));
      const tracks = (phys, label) => {
        const row = D.choice(phys, label, [['1fr 1fr', '2 עמודות שוות'], ['1fr 1fr 1fr', '3 שוות'], ['repeat(auto-fill, minmax(200px, 1fr))', 'אוטומטי (minmax)']]);
        const inp = hh('input', { type: 'text', dir: 'ltr', 'aria-label': label + ' — הקלדה חופשית', placeholder: '1fr 2fr auto', 'data-tracks': phys, disabled: !D.canWrite() || null, value: D.curDeclared(n, phys) || '' });
        inp.addEventListener('change', () => { const v = inp.value.trim(); if (!g.KolDesign.valid(phys, v)) { inp.classList.add('bad'); return; } D.put([[phys, v]], label + ' · ' + this.c.label(n) + ' · ' + v); });
        row.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-ctl wide' }, inp)));
        return row;
      };
      sec.append(tracks('grid-template-columns', 'עמודות'), tracks('grid-template-rows', 'שורות'));
      sec.append(D.choice('grid-auto-flow', 'זרימה', [['row', 'שורות'], ['column', 'עמודות'], ['row dense', 'שורות, צפוף'], ['column dense', 'עמודות, צפוף']]));
      sec.append(D.seg('justify-items', 'יישור בתא (אופקי)', [['start', '⇤', 'התחלה'], ['center', '⇔', 'מרכז'], ['end', '⇥', 'סוף'], ['stretch', '↔', 'מתיחה']]));
      sec.append(D.seg('align-items', 'יישור בתא (אנכי)', [['start', '⤒', 'למעלה'], ['center', '⇕', 'מרכז'], ['end', '⤓', 'למטה'], ['stretch', '↕', 'מתיחה']]));
      sec.append(D.num('row-gap', 'רווח שורות', { group: 'gap' }), D.num('column-gap', 'רווח עמודות', { group: 'gap' }));
      sec.append(hh('p', { class: 'hint' }, 'repeat() ו-minmax() נשמרים כמו שהם. קווי המסילות על הקנבס וגרירת span — טרם נבנו.'));
      return sec;
    }
    /** ילד של רובריקת flex / grid. */
    child(n) {
      const e = this.c.elOf(n), p = e && e.parentElement; if (!p) return null;
      const ps = this.win().getComputedStyle(p), D = this.D;
      if (/flex/.test(ps.display)) {
        const sec = hh('section', { class: 'sg-sec', 'data-sec': 'flex-item' }, hh('h3', { class: 'dt-h' }, 'בתוך שורה / עמודה (flex)'));
        sec.append(D.num('flex-grow', 'גדילה', { unitless: true, units: [] }), D.num('flex-shrink', 'כיווץ', { unitless: true, units: [] }), D.num('flex-basis', 'בסיס', { units: [] }));
        sec.append(D.seg('align-self', 'יישור עצמי', [['auto', 'A', 'כמו כולם'], ['flex-start', '⤒', 'התחלה'], ['center', '⇕', 'מרכז'], ['flex-end', '⤓', 'סוף'], ['stretch', '↕', 'מתיחה']]));
        const col = /column/.test(ps.flexDirection), pre = col ? 'margin-block-' : 'margin-inline-', rtl = this.cs(n).direction === 'rtl';
        const phys = k => (col ? (k === 'start' ? 'margin-top' : 'margin-bottom') : (k === 'start') === rtl ? 'margin-right' : 'margin-left');
        sec.append(hh('div', { class: 'sg-bgacts' },
          hh('button', { type: 'button', class: 'btn sm', 'data-push': 'end', disabled: !D.canWrite() || null, onclick: () => D.put([[phys('start'), 'auto']], 'דחוף לסוף · ' + this.c.label(n) + ' · ' + pre + 'start: auto') }, 'דחוף לסוף'),
          hh('button', { type: 'button', class: 'btn sm', 'data-push': 'start', disabled: !D.canWrite() || null, onclick: () => D.put([[phys('end'), 'auto']], 'דחוף להתחלה · ' + this.c.label(n) + ' · ' + pre + 'end: auto') }, 'דחוף להתחלה')));
        sec.append(D.num('order', 'סדר (order)', { unitless: true, units: [] }), hh('p', { class: 'hint' }, 'order משנה את הסדר הנראה בלבד; לסדר אמיתי — גוררים (קורא מסך קורא לפי המקור).'));
        return sec;
      }
      if (/grid/.test(ps.display)) {
        const sec = hh('section', { class: 'sg-sec', 'data-sec': 'grid-item' }, hh('h3', { class: 'dt-h' }, 'בתוך רשת (grid)'));
        for (const [p2, l] of [['grid-column-start', 'עמודה מ-'], ['grid-column-end', 'עמודה עד'], ['grid-row-start', 'שורה מ-'], ['grid-row-end', 'שורה עד']]) sec.append(D.num(p2, l, { unitless: true, units: [] }));
        sec.append(D.seg('justify-self', 'יישור אופקי', [['auto', 'A', 'כמו כולם'], ['start', '⇤', 'התחלה'], ['center', '⇔', 'מרכז'], ['end', '⇥', 'סוף'], ['stretch', '↔', 'מתיחה']]));
        sec.append(D.seg('align-self', 'יישור אנכי', [['auto', 'A', 'כמו כולם'], ['start', '⤒', 'למעלה'], ['center', '⇕', 'מרכז'], ['end', '⤓', 'למטה'], ['stretch', '↕', 'מתיחה']]));
        return sec;
      }
      return null;
    }
    /** מה קובע את הגודל, כשאינו בידי האלמנט — עם ההצעה. */
    why(n, axis) {
      const e = this.c.elOf(n), s = this.cs(n), p = e.parentElement, ps = p ? this.win().getComputedStyle(p) : null;
      if (/^inline$/.test(s.display)) return { text: 'אלמנט inline — רוחב וגובה אינם חלים עליו', fix: ['display', 'inline-block', 'הפוך ל-inline-block'] };
      if (ps && /flex/.test(ps.display)) {
        const main = /column/.test(ps.flexDirection) ? 'h' : 'w';
        if (axis !== main && (s.alignSelf === 'stretch' || (s.alignSelf === 'auto' && ps.alignItems === 'stretch') || ps.alignItems === 'normal')) return { text: 'נמתח ע״י ההורה (align: stretch)', fix: ['align-self', 'flex-start', 'בטל מתיחה'] };
      }
      const max = axis === 'w' ? s.maxWidth : s.maxHeight; if (max && max !== 'none') return { text: (axis === 'w' ? 'max-width: ' : 'max-height: ') + max + ' מגביל', fix: null, field: axis === 'w' ? 'max-width' : 'max-height' };
      return null;
    }
    size(n) {
      const D = this.D, sec = hh('section', { class: 'sg-sec', 'data-sec': 'size' }, hh('h3', { class: 'dt-h' }, 'גודל'));
      const r = this.c.elOf(n).getBoundingClientRect();
      sec.append(hh('p', { class: 'hint', dir: 'ltr' }, Math.round(r.width) + ' × ' + Math.round(r.height) + ' px'));
      for (const [p, l] of [['width', 'רוחב'], ['min-width', 'רוחב מינימלי'], ['max-width', 'רוחב מקסימלי'], ['height', 'גובה'], ['min-height', 'גובה מינימלי'], ['max-height', 'גובה מקסימלי']]) sec.append(D.num(p, l, { group: 'גודל', units: ['svh', 'dvh', 'fr'] }));
      for (const ax of ['w', 'h']) { const w = this.why(n, ax); if (w) sec.append(hh('p', { class: 'sg-over', 'data-size-why': ax }, '⚠️ ' + (ax === 'w' ? 'הרוחב' : 'הגובה') + ': ' + w.text + ' ', w.fix ? hh('button', { type: 'button', class: 'btn sm', disabled: !D.canWrite() || null, onclick: () => D.put([[w.fix[0], w.fix[1]]], w.fix[2] + ' · ' + this.c.label(n)) }, w.fix[2]) : hh('button', { type: 'button', class: 'btn sm', onclick: () => D.focusField(w.field) }, 'שנה את ' + w.field))); }
      sec.append(D.choice('aspect-ratio', 'יחס', [['auto', 'חופשי'], ['1 / 1', '1:1'], ['4 / 3', '4:3'], ['16 / 9', '16:9'], ['3 / 4', '3:4']]));
      const ov = [['visible', 'גלוי'], ['hidden', 'חתוך'], ['auto', 'גלילה כשצריך'], ['scroll', 'גלילה תמיד'], ['clip', 'clip']];
      sec.append(D.choice('overflow-x', 'גלישה אופקית', ov), D.choice('overflow-y', 'גלישה אנכית', ov));
      sec.append(D.choice('box-sizing', 'box-sizing', [['border-box', 'border-box'], ['content-box', 'content-box']]));
      const e = this.c.elOf(n); if (e.scrollHeight > e.clientHeight + 1 || e.scrollWidth > e.clientWidth + 1) sec.append(hh('p', { class: 'sg-over', 'data-overflow': true }, '⚠️ התוכן גולש מהאלמנט. ', hh('button', { type: 'button', class: 'btn sm', onclick: () => D.focusField('overflow-y') }, 'overflow')));
      return sec;
    }

    // ── ידיות גודל על הקנבס ──────────────────────────────
    /** שמונה ריבועים על קו המסגרת. היחידה הקיימת נשמרת (% נשאר %); בלי ערך — px. Shift — שומר יחס; Alt — משני הצדדים. */
    drawHandles() {
      const c = this.c, n = c.selected, L = this.layer();
      if (!this.hs) {
        this.hs = {}; this.tip = hh('span', { class: 'sz-tip', hidden: true, dir: 'ltr' });
        for (const k of ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw']) { const b = hh('div', { class: 'sz-h sz-' + k, hidden: true, 'data-size-h': k, title: '' }); b.addEventListener('pointerdown', ev => this.down(ev, k)); b.addEventListener('pointerenter', () => this.hint(k)); b.addEventListener('pointerleave', () => { if (!this.dragging) this.tip.hidden = true; }); this.hs[k] = b; L.prepend(b); }   // מתחת לידיות המרווח של V3: איפה ששתיהן — המרווח (הרצועה) גובר
        L.append(this.tip);
      }
      const on = this.on() && n && !this.dragging && this.D.canWrite() && c.elOf(n) && !/^(html|body|head|img)$/.test(n.tag) && !(this.busy && this.busy());
      const r = on ? c.rectOf(n) : null, z = this.phone() ? 44 : 9;
      for (const [k, b] of Object.entries(this.hs)) {
        b.hidden = !r || r.zero; if (b.hidden) continue;
        const x = /w/.test(k) ? r.left : /e/.test(k) ? r.left + r.width : r.left + r.width / 2, y = /n/.test(k) ? r.top : /s/.test(k) ? r.top + r.height : r.top + r.height / 2;
        b.style.cssText = 'left:' + (x - z / 2) + 'px;top:' + (y - z / 2) + 'px;width:' + z + 'px;height:' + z + 'px';
      }
    }
    props(k) { return [/[ew]/.test(k) ? 'width' : null, /[ns]/.test(k) ? 'height' : null].filter(Boolean); }
    hint(k) { const r = this.c.rectOf(this.c.selected); if (!r) return; this.tip.hidden = false; this.tip.textContent = 'גודל · ' + this.props(k).join(' + '); this.tip.style.cssText = 'left:' + (r.left + r.width / 2) + 'px;top:' + Math.max(0, r.top - 22) + 'px'; }
    down(ev, k) {
      const c = this.c, n = c.selected, e = n && c.elOf(n); if (!e || ev.button > 0) return;
      ev.preventDefault(); try { ev.target.setPointerCapture(ev.pointerId); } catch { /* */ }
      const r0 = e.getBoundingClientRect(), x0 = ev.clientX, y0 = ev.clientY, s = c.scale, rtl = this.cs(n).direction === 'rtl', D = this.D;
      D.info = D.styler.info(n); D.list = D.styler.targets(n, D.info); D.t = D.target(n, D.list);
      const unitOf = p => { const v = D.curDeclared(n, p); const m = /^-?[\d.]+(%|vw|vh|em|rem|svh|dvh)$/.exec(String(v).trim()); return m ? m[1] : 'px'; };
      const per = { width: unitOf('width'), height: unitOf('height') }, par = e.parentElement || e;
      const conv = (px, p) => { const u = per[p], base = p === 'width' ? par.clientWidth : par.clientHeight; if (u === '%') return Math.round(px / base * 10000) / 100 + '%'; if (u === 'vw') return Math.round(px / this.win().innerWidth * 10000) / 100 + 'vw'; if (u === 'vh' || u === 'svh' || u === 'dvh') return Math.round(px / this.win().innerHeight * 10000) / 100 + u; if (u === 'em' || u === 'rem') { const fs = parseFloat(u === 'rem' ? this.win().getComputedStyle(c.doc.documentElement).fontSize : this.cs(n).fontSize) || 16; return Math.round(px / fs * 100) / 100 + u; } return Math.round(px) + 'px'; };
      const ps = this.props(k), ratio = r0.height / (r0.width || 1); let w = r0.width, hgt = r0.height, moved = false;
      this.dragging = true;
      const mv = e2 => {
        let dx = (e2.clientX - x0) / s, dy = (e2.clientY - y0) / s; if (/w/.test(k)) dx = -dx; if (/n/.test(k)) dy = -dy;
        if (e2.altKey) { dx *= 2; dy *= 2; }
        w = Math.max(4, r0.width + (ps.includes('width') ? dx : 0)); hgt = Math.max(4, r0.height + (ps.includes('height') ? dy : 0));
        if (e2.shiftKey && ps.length === 2) hgt = w * ratio;
        moved = moved || Math.hypot(dx, dy) > 1;
        const sets = []; if (ps.includes('width')) sets.push(['width', Math.round(w) + 'px']); if (ps.includes('height') || (e2.shiftKey && ps.length === 2)) sets.push(['height', Math.round(hgt) + 'px']);
        D.preview([n], sets);
        this.tip.hidden = false; this.tip.textContent = sets.map(([p]) => p + ' ' + conv(p === 'width' ? w : hgt, p)).join(' · ');
        const r = c.rectOf(n); if (r) this.tip.style.cssText = 'left:' + (r.left + r.width / 2) + 'px;top:' + Math.max(0, r.top - 22) + 'px';
      };
      const up = e2 => {
        const t = e2.target; t.removeEventListener('pointermove', mv); t.removeEventListener('pointerup', up); t.removeEventListener('pointercancel', up);
        this.dragging = false; this.tip.hidden = true;
        if (e2.type !== 'pointerup' || !moved) { D.unpreview(); this.drawHandles(); return; }
        const sets = []; if (ps.includes('width')) sets.push(['width', conv(w, 'width')]); if (ps.includes('height')) sets.push(['height', conv(hgt, 'height')]);
        D.put(sets, 'גודל · ' + c.label(n) + ' · ' + sets.map(x => x[0] + ' ' + x[1]).join(' · '));
        const e3 = c.elOf(c.selected); if (e3 && ((ps.includes('width') && Math.abs(e3.getBoundingClientRect().width - w) > 2) || (ps.includes('height') && Math.abs(e3.getBoundingClientRect().height - hgt) > 2))) { const wy = this.why(c.selected, ps.includes('width') ? 'w' : 'h'); this.note('הגודל לא השתנה כמו שנגרר' + (wy ? ' — ' + wy.text : ' — משהו אחר קובע אותו (ראו "גודל" בלשונית מתקדם)'), true); }
        this.drawHandles();
      };
      ev.target.addEventListener('pointermove', mv); ev.target.addEventListener('pointerup', up); ev.target.addEventListener('pointercancel', up);
    }
  }
  g.KolLayout = { Layout, sides };
})(typeof window !== 'undefined' ? window : globalThis);
