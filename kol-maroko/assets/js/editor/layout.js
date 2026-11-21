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
  const K = () => g.KolSrc;
  /** הצד הפיזי של "התחלה" / "סוף" בציר: dir = flex-direction, rtl. */
  function sides(dir, rtl) {
    const col = /column/.test(dir), rev = /reverse/.test(dir);
    if (col) return rev ? ['למטה', 'למעלה'] : ['למעלה', 'למטה'];
    const st = rtl ? 'ימין' : 'שמאל', en = rtl ? 'שמאל' : 'ימין';
    return rev ? [en, st] : [st, en];
  }
  /** מסילות grid: פירוק ברמה העליונה (רווחים מחוץ לסוגריים). [a] — שם קו; repeat(...) — קבוצה שנערכת כמו שהיא. */
  function tracks(str) {
    const out = []; let cur = '', depth = 0;
    for (const ch of String(str || '').trim()) {
      if (ch === '(' || ch === '[') depth++; else if (ch === ')' || ch === ']') depth = Math.max(0, depth - 1);
      if (/\s/.test(ch) && !depth) { if (cur) out.push(cur); cur = ''; } else cur += ch;
    }
    if (cur) out.push(cur);
    return out.map(v => ({ v, kind: /^\[/.test(v) ? 'line' : /^repeat\(/i.test(v) ? 'repeat' : 'track' }));
  }
  const joinTracks = list => list.map(t => t.v).join(' ');
  /** גודל חדש למסילה i אחרי גרירת הקו שאחריה. vals — הטוקנים (מסילות בלבד), px — הגדלים בפועל, d — כמה פיקסלים נוספו
   *  למסילה i (ונגרעו מ-i+1). fr ← fr: חלוקה מחדש של סכום שתיהן; fr — יחסית ליחידה שלה; % — אחוז מ-W; אחרת — px. */
  function resize(vals, px, i, d, W) {
    const out = vals.slice(), a = Math.max(8, px[i] + d), b = Math.max(8, px[i] + px[i + 1] - a), r2 = x => String(Math.round(x * 100) / 100);
    const fr = v => { const m = /^([\d.]+)fr$/.exec(v); return m ? +m[1] : null; }, fa = fr(vals[i]), fb = i + 1 < vals.length ? fr(vals[i + 1]) : null;
    if (fa != null && fb != null && px[i] + px[i + 1] > 0) { const sum = fa + fb; out[i] = r2(sum * a / (a + b)) + 'fr'; out[i + 1] = r2(sum - +r2(sum * a / (a + b))) + 'fr'; }
    else if (fa != null) out[i] = r2(px[i] > 0 ? fa * a / px[i] : fa) + 'fr';
    else if (/^-?[\d.]+%$/.test(vals[i]) && W) out[i] = r2(a / W * 100) + '%';
    else out[i] = Math.round(a) + 'px';
    return out;
  }
  class Layout {
    /** o: { design (לשונית "מתקדם"), write, note, undo, phone, layer(), busy() } */
    constructor(o) { Object.assign(this, o); }
    /** הערך הכתוב (לא המחושב) — מהמקור העדכני, לא מהמטמון של הפאנל (בטלפון הפאנל סגור). */
    decl(n, phys) { const s = this.D.styler.source(n, phys); return s.winner ? String(s.declared) : ''; }
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
        row.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-ctl wide' }, inp)), this.trackList(n, phys, label));
        return row;
      };
      sec.append(tracks('grid-template-columns', 'עמודות'), tracks('grid-template-rows', 'שורות'));
      sec.append(D.choice('grid-auto-flow', 'זרימה', [['row', 'שורות'], ['column', 'עמודות'], ['row dense', 'שורות, צפוף'], ['column dense', 'עמודות, צפוף']]));
      sec.append(D.seg('justify-items', 'יישור בתא (אופקי)', [['start', '⇤', 'התחלה'], ['center', '⇔', 'מרכז'], ['end', '⇥', 'סוף'], ['stretch', '↔', 'מתיחה']]));
      sec.append(D.seg('align-items', 'יישור בתא (אנכי)', [['start', '⤒', 'למעלה'], ['center', '⇕', 'מרכז'], ['end', '⤓', 'למטה'], ['stretch', '↕', 'מתיחה']]));
      sec.append(D.num('row-gap', 'רווח שורות', { group: 'gap' }), D.num('column-gap', 'רווח עמודות', { group: 'gap' }));
      sec.append(hh('p', { class: 'hint' }, 'repeat() ו-minmax() נשמרים כמו שהם. על הקנבס — קווי המסילות; גרירת קו משנה את גודל המסילה. (גרירת span ו-grid-template-areas כטבלה — טרם נבנו.)'));
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

    /** רשימת מסילות נערכת: ערך חופשי לכל מסילה, ✕, ↑↓, גרירה (⠿) לשינוי סדר, "+ מסילה". repeat() — קבוצה, בלי פירוק. */
    trackList(n, phys, label) {
      const D = this.D, w = D.canWrite(), list = tracks(this.decl(n, phys)), box = hh('ol', { class: 'gt-list', 'data-track-list': phys, 'aria-label': label + ' — מסילות' });
      const save = (next, what) => { const v = joinTracks(next); if (v && !g.KolDesign.valid(phys, v)) { this.note('ערך לא חוקי ל-' + phys + ': ' + v + ' — לא נכתב', true); return; } D.put([[phys, v || null]], label + ' · ' + this.c.label(n) + ' · ' + what + ' · ' + (v || 'איפוס')); };
      list.forEach((t, i) => {
        const inp = hh('input', { type: 'text', dir: 'ltr', value: t.v, 'data-track-i': String(i), 'aria-label': (t.kind === 'line' ? 'שם קו ' : t.kind === 'repeat' ? 'קבוצת repeat ' : 'מסילה ') + (i + 1), disabled: !w || null, spellcheck: 'false' });
        inp.addEventListener('change', () => { const v = inp.value.trim(); if (!v) return; const nx = list.map(x => ({ ...x })); nx[i] = { v, kind: t.kind }; save(nx, 'מסילה ' + (i + 1) + ' ← ' + v); });
        const mv = k => { const j = i + k; if (j < 0 || j >= list.length) return; const nx = list.slice(); [nx[i], nx[j]] = [nx[j], nx[i]]; save(nx, 'סדר · מסילה ' + (i + 1) + ' ← ' + (j + 1)); };
        const grip = hh('span', { class: 'gt-grip', 'data-track-grip': String(i), title: 'גרירה לשינוי הסדר', 'aria-hidden': 'true' }, '⠿');
        grip.addEventListener('pointerdown', ev => this.reorder(ev, box, i, list, save));
        box.append(hh('li', { class: 'gt-row', 'data-kind': t.kind }, grip,
          t.kind === 'repeat' ? hh('span', { class: 'gt-tag', title: 'repeat() — קבוצה, נערכת כמו שהיא' }, '⟳') : t.kind === 'line' ? hh('span', { class: 'gt-tag', title: 'שם קו' }, '[ ]') : null, inp,
          hh('button', { type: 'button', class: 'btn sm', 'data-track-up': String(i), title: 'הקדם', disabled: !w || !i || null, onclick: () => mv(-1) }, '↑'),
          hh('button', { type: 'button', class: 'btn sm', 'data-track-down': String(i), title: 'אחר', disabled: !w || i === list.length - 1 || null, onclick: () => mv(1) }, '↓'),
          hh('button', { type: 'button', class: 'btn sm', 'data-track-del': String(i), title: 'מחק את המסילה', 'aria-label': 'מחק מסילה ' + (i + 1), disabled: !w || null, onclick: () => save(list.filter((_, j) => j !== i), 'מחיקת מסילה ' + (i + 1)) }, '✕')));
      });
      box.append(hh('li', { class: 'gt-row add' }, hh('button', { type: 'button', class: 'btn sm', 'data-track-add': phys, disabled: !w || null, onclick: () => save([...list, { v: '1fr', kind: 'track' }], 'מסילה חדשה 1fr') }, '+ מסילה')));
      return box;
    }
    /** גרירת ⠿ ברשימה: המקום החדש לפי הסמן; שחרור — עסקה אחת. */
    reorder(ev, box, i, list, save) {
      if (!this.D.canWrite()) return; ev.preventDefault(); const t = ev.currentTarget; try { t.setPointerCapture(ev.pointerId); } catch { /* */ }
      const rows = [...box.querySelectorAll('.gt-row:not(.add)')]; let to = i; rows[i].classList.add('drag');
      const mv = e2 => { to = rows.length - 1; for (let j = 0; j < rows.length; j++) { const r = rows[j].getBoundingClientRect(); if (e2.clientY < r.top + r.height / 2) { to = j; break; } } rows.forEach((r, j) => r.classList.toggle('over', j === to && j !== i)); };
      const up = e2 => { t.removeEventListener('pointermove', mv); t.removeEventListener('pointerup', up); t.removeEventListener('pointercancel', up); rows.forEach(r => r.classList.remove('drag', 'over')); if (e2.type !== 'pointerup' || to === i) return; const nx = list.slice(), [x] = nx.splice(i, 1); nx.splice(to, 0, x); save(nx, 'סדר · מסילה ' + (i + 1) + ' ← ' + (to + 1)); };
      t.addEventListener('pointermove', mv); t.addEventListener('pointerup', up); t.addEventListener('pointercancel', up);
    }

    // ── ידית חלוקה בין ילדי רובריקת-שורה ────────────────
    /** הזוגות הסמוכים (בסדר המקור, באותה שורה) של רובריקת flex בכיוון שורה. */
    pairs(n) {
      const e = this.c.elOf(n), s = e && this.cs(n); if (!s || !/flex/.test(s.display) || !/^row/.test(s.flexDirection)) return [];
      const W = this.win(), kids = [...e.children].filter(k => k.getClientRects().length && !/^(absolute|fixed)$/.test(W.getComputedStyle(k).position) && !k.closest('[data-kol-canvas]') && this.c.nodeOf(k));
      const out = [];
      for (let i = 0; i + 1 < kids.length; i++) { const a = kids[i].getBoundingClientRect(), b = kids[i + 1].getBoundingClientRect(); if (a.top < b.bottom - 1 && b.top < a.bottom - 1) out.push([kids[i], kids[i + 1]]); }
      return out;
    }
    /** מה הידית תכתוב: לשניהם flex-grow (כשהבסיס 0 של שניהם), אחרת flex-basis באחוזים לזה שנגרר. */
    divMode(a, b) { const W = this.win(), sa = W.getComputedStyle(a), sb = W.getComputedStyle(b), zero = x => /^0(px|%)?$/.test(x.flexBasis); return zero(sa) && zero(sb) && parseFloat(sa.flexGrow) > 0 && parseFloat(sb.flexGrow) > 0 ? 'grow' : 'basis'; }
    drawDividers() {
      const c = this.c, n = c.selected, L = this.layer(); this.dvs = this.dvs || [];
      const on = this.on() && n && !this.dragging && this.D.canWrite() && c.elOf(n) && !(this.busy && this.busy());
      const ps = on ? this.pairs(n) : [], s = c.scale, big = this.phone() ? 44 : 0, fr = c.frame.getBoundingClientRect(), LR = L.getBoundingClientRect();
      while (this.dvs.length < ps.length) {
        const b = hh('div', { class: 'dv-h', hidden: true, 'data-divider': String(this.dvs.length), role: 'separator', 'aria-orientation': 'vertical' }); const k = this.dvs.length;
        b.addEventListener('pointerdown', ev => this.divDown(ev, k)); b.addEventListener('pointerenter', () => this.divHint(k)); b.addEventListener('pointerleave', () => { if (!this.dragging) this.tip.hidden = true; });
        this.dvs.push(b); L.append(b);
      }
      this.dvs.forEach((b, i) => {
        const p = ps[i]; b.hidden = !p; if (!p) return;
        const [a, bb] = p, ra = a.getBoundingClientRect(), rb = bb.getBoundingClientRect(), edge = rb.left >= ra.right - 1 ? ra.right : ra.left;   // הצלע של הראשון (בסדר המקור), הפונה אל השני
        const top = Math.max(ra.top, rb.top), bot = Math.min(ra.bottom, rb.bottom), hgt = Math.max(big || 24, Math.min(bot - top, 80) * s * 0.6), wd = big || 8;
        const x = fr.left - LR.left + edge * s, y = fr.top - LR.top + ((top + bot) / 2) * s;
        b.style.cssText = 'left:' + (x - wd / 2) + 'px;top:' + (y - hgt / 2) + 'px;width:' + wd + 'px;height:' + hgt + 'px';
        b.title = this.divText(a, bb); b.setAttribute('aria-label', b.title);
      });
      this.pairsNow = ps;
    }
    divText(a, b) { const m = this.divMode(a, b), la = this.c.label(this.c.nodeOf(a)), lb = this.c.label(this.c.nodeOf(b)); return 'חלוקה · ' + (m === 'grow' ? 'flex-grow לשניהם (' + la + ', ' + lb + ')' : 'flex-basis % ל-' + la) + ' — גרירה; Shift — 5%'; }
    divHint(k) { const p = this.pairsNow && this.pairsNow[k]; if (!p) return; this.tip.hidden = false; this.tip.textContent = this.divText(...p); const b = this.dvs[k].getBoundingClientRect(), LR = this.layer().getBoundingClientRect(); this.tip.style.cssText = 'left:' + (b.left - LR.left + b.width / 2) + 'px;top:' + Math.max(0, b.top - LR.top - 22) + 'px'; }
    /** יעד לכתיבה לאלמנט אחד בלבד: כלל היעד אם הוא חל רק עליו; אחרת — מחלקה חדשה משלו (HTML + CSS, באותה עסקה). */
    ownTarget(n, taken) {
      const D = this.D, St = D.styler, list = St.targets(n), t = D.target(n, list);
      if (t.kind === 'inline' || (t.kind !== 'new' && (t.count || 0) <= 1)) return t;
      const page = list.find(x => x.path && x.kind !== 'inline'); let name = St.suggest(n);
      for (let i = 2; taken.has(name); i++) name = St.suggest(n) + '-' + i;
      taken.add(name);
      return { kind: 'newclass', sel: '.' + name, name, path: (page && page.path) || St.pagePath(), why: 'class', count: 1 };
    }
    divDown(ev, k) {
      const c = this.c, p = this.pairsNow && this.pairsNow[k]; if (!p || ev.button > 0 || !this.D.canWrite()) return;
      ev.preventDefault(); const h0 = ev.currentTarget; try { h0.setPointerCapture(ev.pointerId); } catch { /* */ }
      const [a, b] = p, na = c.nodeOf(a), nb = c.nodeOf(b), mode = this.divMode(a, b), W = this.win(), sa = W.getComputedStyle(a), sb = W.getComputedStyle(b);
      const ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect(), wa = ra.width, tot = ra.width + rb.width, x0 = ev.clientX, s = c.scale, aLeft = rb.left >= ra.right - 1;
      const par = a.parentElement, ps = W.getComputedStyle(par), cw = par.clientWidth - (parseFloat(ps.paddingLeft) || 0) - (parseFloat(ps.paddingRight) || 0);
      const G = (parseFloat(sa.flexGrow) || 0) + (parseFloat(sb.flexGrow) || 0), r2 = x => Math.round(x * 100) / 100;
      let pct = wa / tot * 100, moved = false, vals = null; this.dragging = true;
      const calc = e2 => {
        const d = (e2.clientX - x0) / s * (aLeft ? 1 : -1);
        let w2 = Math.max(tot * 0.02, Math.min(tot * 0.98, wa + d)); pct = w2 / tot * 100;
        if (e2.shiftKey) { pct = Math.max(5, Math.min(95, Math.round(pct / 5) * 5)); w2 = tot * pct / 100; }
        if (mode === 'grow') { const ga = r2(G * pct / 100); vals = [[na, [['flex-grow', String(ga)]]], [nb, [['flex-grow', String(r2(G - ga))]]]]; }
        else vals = [[na, [['flex-basis', r2(w2 / (cw || tot) * 100) + '%']]]];
        moved = moved || Math.abs(e2.clientX - x0) > 1;
      };
      const mv = e2 => {
        calc(e2); this.D.unpreview(); for (const [m, sets] of vals) this.D.preview([m], sets);
        const LR = this.layer().getBoundingClientRect();
        this.tip.hidden = false; this.tip.textContent = Math.round(pct) + '% · ' + Math.round(100 - pct) + '%' + (mode === 'grow' ? '  (flex-grow ' + vals[0][1][0][1] + ' / ' + vals[1][1][0][1] + ')' : '  (flex-basis ' + vals[0][1][0][1] + ')');
        this.tip.style.cssText = 'left:' + (e2.clientX - LR.left) + 'px;top:' + Math.max(0, e2.clientY - LR.top - 28) + 'px';
        this.drawDividers();
      };
      const up = e2 => {
        h0.removeEventListener('pointermove', mv); h0.removeEventListener('pointerup', up); h0.removeEventListener('pointercancel', up);
        this.dragging = false; this.tip.hidden = true;
        if (e2.type !== 'pointerup' || !moved || !vals) { this.D.unpreview(); this.drawDividers(); return; }
        const taken = new Set(), per = new Map(vals.map(([m, sets]) => { const t = this.ownTarget(m, taken); return [m, sets.map(([pp, v]) => [pp, v, t])]; }));
        const r = this.write(per, 'חלוקה · ' + c.label(na) + ' · ' + c.label(nb) + ' · ' + Math.round(pct) + '% / ' + Math.round(100 - pct) + '%');
        const a2 = r && c.elOf(K().byPath(c.m, K().pathOf(na)));
        if (r && a2 && Math.abs(a2.getBoundingClientRect().width / tot * 100 - pct) > 3) this.note('החלוקה לא השתנה כמו שנגרר — משהו אחר קובע את הרוחב (min-width, max-width או flex-shrink). ראו "גודל" ו"בתוך שורה" בלשונית מתקדם.', true);
        this.drawDividers();
      };
      h0.addEventListener('pointermove', mv); h0.addEventListener('pointerup', up); h0.addEventListener('pointercancel', up);
    }

    // ── קווי מסילות grid על הקנבס ───────────────────────
    /** הקווים שבין המסילות של רובריקת grid נבחרת. נגררים רק כשכל מסילה כתובה בנפרד (בלי repeat / auto-fill). */
    gridLines(n) {
      const e = this.c.elOf(n), s = e && this.cs(n); if (!s || !/grid/.test(s.display)) return [];
      const out = [], r = e.getBoundingClientRect(), rtl = s.direction === 'rtl', P = x => parseFloat(x) || 0;
      for (const [ax, phys, comp, gap] of [['x', 'grid-template-columns', s.gridTemplateColumns, P(s.columnGap)], ['y', 'grid-template-rows', s.gridTemplateRows, P(s.rowGap)]]) {
        const px = String(comp).split(/\s+/).filter(v => /px$/.test(v)).map(parseFloat); if (px.length < 2) continue;
        const decl = this.decl(n, phys), toks = tracks(decl), vals = toks.filter(t => t.kind === 'track').map(t => t.v), editable = !toks.some(t => t.kind === 'repeat') && vals.length === px.length;
        let at = ax === 'x' ? (rtl ? r.right - P(s.borderRightWidth) - P(s.paddingRight) : r.left + P(s.borderLeftWidth) + P(s.paddingLeft)) : r.top + P(s.borderTopWidth) + P(s.paddingTop);
        for (let i = 0; i + 1 < px.length; i++) { at += (ax === 'x' && rtl ? -1 : 1) * (px[i] + gap / 2); out.push({ ax, i, at, phys, px, vals, toks, editable, rtl: ax === 'x' && rtl, gap, W: ax === 'x' ? e.clientWidth - P(s.paddingLeft) - P(s.paddingRight) : e.clientHeight - P(s.paddingTop) - P(s.paddingBottom), r }); at += (ax === 'x' && rtl ? -1 : 1) * gap / 2; }
      }
      return out;
    }
    drawGridLines() {
      const c = this.c, n = c.selected, L = this.layer(); this.gls = this.gls || [];
      const on = this.on() && n && !this.dragging && c.elOf(n) && !(this.busy && this.busy());
      const ls = on ? this.gridLines(n) : [], s = c.scale, big = this.phone() ? 44 : 10, fr = c.frame.getBoundingClientRect(), LR = L.getBoundingClientRect();
      while (this.gls.length < ls.length) { const b = hh('div', { class: 'gt-line', hidden: true, 'data-grid-line': '' }), k = this.gls.length; b.addEventListener('pointerdown', ev => this.lineDown(ev, k)); b.addEventListener('pointerenter', () => { const l = this.linesNow && this.linesNow[k]; if (!l) return; this.tip.hidden = false; this.tip.textContent = b.title; const bb = b.getBoundingClientRect(); this.tip.style.cssText = 'left:' + (bb.left - LR.left + bb.width / 2) + 'px;top:' + Math.max(0, bb.top - LR.top - 22) + 'px'; }); b.addEventListener('pointerleave', () => { if (!this.dragging) this.tip.hidden = true; }); this.gls.push(b); L.append(b); }
      this.gls.forEach((b, i) => {
        const l = ls[i]; b.hidden = !l; if (!l) return;
        const ox = fr.left - LR.left, oy = fr.top - LR.top;
        b.dataset.gridLine = l.ax + l.i; b.classList.toggle('ro', !l.editable || !this.D.canWrite()); b.classList.toggle('h', l.ax === 'y');
        b.style.cssText = l.ax === 'x' ? 'left:' + (ox + l.at * s - big / 2) + 'px;top:' + (oy + l.r.top * s) + 'px;width:' + big + 'px;height:' + l.r.height * s + 'px' : 'top:' + (oy + l.at * s - big / 2) + 'px;left:' + (ox + l.r.left * s) + 'px;height:' + big + 'px;width:' + l.r.width * s + 'px';
        b.title = l.editable ? 'מסילה · ' + l.phys + ' · ' + (l.ax === 'x' ? 'עמודה ' : 'שורה ') + (l.i + 1) + ' (' + l.vals[l.i] + ') — גרירה משנה את גודלה' : 'קו בין מסילות — repeat() / auto-fill: נערך ברשימת המסילות בפאנל, לא בגרירה';
      });
      this.linesNow = ls;
    }
    lineDown(ev, k) {
      const c = this.c, n = c.selected, l = this.linesNow && this.linesNow[k]; if (!l || !l.editable || ev.button > 0 || !this.D.canWrite()) return;
      ev.preventDefault(); const h0 = ev.currentTarget; try { h0.setPointerCapture(ev.pointerId); } catch { /* */ }
      const x0 = ev.clientX, y0 = ev.clientY, s = c.scale; let v = null, moved = false; this.dragging = true;
      const calc = e2 => { let d = (l.ax === 'x' ? e2.clientX - x0 : e2.clientY - y0) / s; if (l.rtl) d = -d; const nv = resize(l.vals, l.px, l.i, d, l.W); let j = 0; v = joinTracks(l.toks.map(t => (t.kind === 'track' ? { v: nv[j++] } : t))); moved = moved || Math.abs(d) > 1; return d; };
      const mv = e2 => { const d = calc(e2); this.D.preview([n], [[l.phys, v]]); const LR = this.layer().getBoundingClientRect(); this.tip.hidden = false; this.tip.textContent = l.phys + ': ' + v + '  (' + Math.round(l.px[l.i] + d) + 'px)'; this.tip.style.cssText = 'left:' + (e2.clientX - LR.left) + 'px;top:' + Math.max(0, e2.clientY - LR.top - 28) + 'px'; };
      const up = e2 => {
        h0.removeEventListener('pointermove', mv); h0.removeEventListener('pointerup', up); h0.removeEventListener('pointercancel', up);
        this.dragging = false; this.tip.hidden = true;
        if (e2.type !== 'pointerup' || !moved || !v || !g.KolDesign.valid(l.phys, v)) { this.D.unpreview(); this.drawGridLines(); return; }
        this.D.info = this.D.styler.info(n); this.D.list = this.D.styler.targets(n, this.D.info); this.D.t = this.D.target(n, this.D.list);
        this.D.put([[l.phys, v]], 'מסילה · ' + c.label(n) + ' · ' + (l.ax === 'x' ? 'עמודה ' : 'שורה ') + (l.i + 1) + ' · ' + v);
        this.drawGridLines();
      };
      h0.addEventListener('pointermove', mv); h0.addEventListener('pointerup', up); h0.addEventListener('pointercancel', up);
    }

    // ── ידיות גודל על הקנבס ──────────────────────────────
    /** שמונה ריבועים על קו המסגרת. היחידה הקיימת נשמרת (% נשאר %); בלי ערך — px. Shift — שומר יחס; Alt — משני הצדדים. */
    drawHandles() {
      const c = this.c, n = c.selected, L = this.layer();
      if (!this.hs) {
        this.hs = {}; this.tip = hh('span', { class: 'sz-tip', hidden: true, dir: 'auto' });
        for (const k of ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw']) { const b = hh('div', { class: 'sz-h sz-' + k, hidden: true, 'data-size-h': k, title: '' }); b.addEventListener('pointerdown', ev => this.down(ev, k)); b.addEventListener('pointerenter', () => this.hint(k)); b.addEventListener('pointerleave', () => { if (!this.dragging) this.tip.hidden = true; }); this.hs[k] = b; L.prepend(b); }   // מתחת לידיות המרווח של V3: איפה ששתיהן — המרווח (הרצועה) גובר
        L.append(this.tip);
      }
      this.drawDividers(); this.drawGridLines();
      const on = this.on() && n && !this.dragging && this.D.canWrite() && c.elOf(n) && !/^(html|body|head|img)$/.test(n.tag) && !(this.busy && this.busy());
      const r = on ? c.rectOf(n) : null, z = this.phone() ? 44 : 9;
      for (const [k, b] of Object.entries(this.hs)) {
        b.hidden = !r || r.zero; if (b.hidden) continue;
        let x = /w/.test(k) ? r.left : /e/.test(k) ? r.left + r.width : r.left + r.width / 2; const y = /n/.test(k) ? r.top : /s/.test(k) ? r.top + r.height : r.top + r.height / 2;
        if ((k === 'n' || k === 's') && (this.dvs || []).some(d => !d.hidden && Math.abs(parseFloat(d.style.left) + parseFloat(d.style.width) / 2 - x) < z)) x = r.left + r.width * 0.25;   // ידית חלוקה באמצע — הגודל זז הצידה, שלא יחפפו (44px בטלפון)
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
  g.KolLayout = { Layout, sides, tracks, joinTracks, resize };
})(typeof window !== 'undefined' ? window : globalThis);
