/**
 * העורך החזותי — לשונית "🎨 עיצוב" (טיפוגרפיה וצבע) ולשונית "📐 מתקדם" (מרווחים), פרוסה V3 (03 §4.1–§4.3).
 *
 * השליטה המספרית (Num) — פעם אחת לכל הפאנל: כל ערך חוקי ב-CSS בהקלדה חופשית (CSS.supports — ערך לא חוקי מסומן ואינו
 * נכתב); גלגלת, חיצים, −/+ וגרירה על התווית (Shift ×10, Alt ×0.1); לצד כל ערך — מאיפה הוא מגיע (קובץ, כלל, שורה;
 * "בירושה"; "ברירת מחדל של הדפדפן"), "נדרס" כשהכתוב אינו הנראה, ⟲ איפוס שמוחק את ההצהרה.
 *
 * כל ערך מהעמוד (בוררים, ערכים, שמות קבצים) — כטקסט בלבד; אין innerHTML בקובץ הזה.
 */
(function () {
  'use strict';
  const ST = () => window.KolStyle;
  const normSel = s => String(s).replace(/\s+/g, ' ').trim();
  const NUM = /^(-?(?:\d+\.?\d*|\.\d+))([a-z%]*)$/i;
  const UNITS = ['px', '%', 'em', 'rem', 'vw', 'vh', 'ch'];
  const SYSTEM_FONTS = ['system-ui', 'Arial', 'Helvetica', 'Tahoma', 'Verdana', 'Georgia', 'Times New Roman', 'Courier New', 'serif', 'sans-serif', 'monospace'];
  const valid = (prop, v) => { if (String(v).includes('<')) return false; try { return CSS.supports(prop, v); } catch { return false; } };   // '<' — לעולם לא (style.js: checkValue)
  const round = x => Math.round(x * 1000) / 1000;
  const fmt = x => String(round(x)).replace(/^(-?)0\./, '$1.').replace(/^(-?)\.(\d)/, '$10.$2');

  // ── צבע ──────────────────────────────────────────────────
  let cctx = null;
  /** כל צבע שהדפדפן מבין ← [r, g, b, a]; null — לא צבע (var() שאינו נפתר, currentColor). */
  function rgba(v) {
    if (!v || !valid('color', v) || /var\(|currentcolor/i.test(v)) return null;
    cctx = cctx || document.createElement('canvas').getContext('2d');
    cctx.fillStyle = '#010203'; cctx.fillStyle = v; const s = cctx.fillStyle;
    if (s === '#010203' && !/^#010203$/i.test(v.trim())) { cctx.fillStyle = '#040506'; cctx.fillStyle = v; if (cctx.fillStyle === '#040506') return null; }
    const out = cctx.fillStyle;
    if (out[0] === '#') return [parseInt(out.slice(1, 3), 16), parseInt(out.slice(3, 5), 16), parseInt(out.slice(5, 7), 16), 1];
    const m = /rgba?\(([^)]+)\)/.exec(out); if (!m) return null;
    const p = m[1].split(/[\s,/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p[3] == null ? 1 : p[3]];
  }
  const hex2 = x => Math.round(x).toString(16).padStart(2, '0');
  const toCss = ([r, g, b, a]) => (a >= 1 ? '#' + hex2(r) + hex2(g) + hex2(b) : 'rgba(' + Math.round(r) + ', ' + Math.round(g) + ', ' + Math.round(b) + ', ' + round(a) + ')');
  function hsv2rgb(hh, s, v) { const f = n => { const k = (n + hh / 60) % 6; return v - v * s * Math.max(0, Math.min(k, 4 - k, 1)); }; return [f(5) * 255, f(3) * 255, f(1) * 255]; }
  function rgb2hsv(r, g, b) { r /= 255; g /= 255; b /= 255; const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn; let hh = 0; if (d) hh = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; hh = (hh * 60 + 360) % 360; return [hh, mx ? d / mx : 0, mx]; }
  const lum = ([r, g, b]) => { const c = x => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b); };
  const over = (top, bot) => { const a = top[3]; return [top[0] * a + bot[0] * (1 - a), top[1] * a + bot[1] * (1 - a), top[2] * a + bot[2] * (1 - a), 1]; };
  /** יחס הניגודיות בין צבע הטקסט לרקע שמתחתיו; null — יש תמונה / מעבר ברקע, ואי אפשר לחשב. */
  function contrast(win, e) {
    const cs = win.getComputedStyle(e), fg = rgba(cs.color); if (!fg) return null;
    const layers = [];
    for (let x = e; x && x.nodeType === 1; x = x.parentElement) {
      const s = win.getComputedStyle(x);
      if (s.backgroundImage && s.backgroundImage !== 'none') return { image: x };
      const bg = rgba(s.backgroundColor); if (bg && bg[3] > 0) { layers.push(bg); if (bg[3] >= 1) break; }
    }
    let bg = [255, 255, 255, 1]; for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
    const f = over(fg, bg), L1 = lum(f), L2 = lum(bg);
    return { ratio: (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05) };
  }

  /** "חל כשהמסך צר מ-782px" — תנאי @media במילים (מה שלא מזוהה — כמו שהוא). */
  function mediaWhen(md) {
    const s = String(md || '').replace(/^.*@media\s+/, ''); let m;
    if ((m = /max-width\s*:\s*(\d+(?:\.\d+)?)px/i.exec(s)) && !/min-width/i.test(s)) return 'חל כשהמסך צר מ-' + (+m[1] + 1) + 'px';
    if ((m = /min-width\s*:\s*(\d+(?:\.\d+)?)px/i.exec(s)) && !/max-width/i.test(s)) return 'חל כשהמסך רחב מ-' + (+m[1] - 1) + 'px';
    return 'חל כש-' + s;
  }
  /** מי ינצח כשכותבים למכשיר בזמן ש-@media אחר (by) קובע את הערך: ייחודיות, ואחריה — מי כתוב אחרי בקובץ (הכלל של המכשיר
   *  נכתב מיד אחרי הכלל הבסיסי). */
  function whoWins(by, t, D) {
    const spec = window.KolDetails.specificity(t.kind === 'newclass' ? '.' + t.name : t.sel), c = by.r.spec.map((x, i) => x - spec[i]).find(x => x) || 0, other = by.r.media.replace(/^.*@media\s+/, '');
    if (by.d.important) return other + ' ינצח — !important';
    if (c > 0) return other + ' ינצח — הבורר שלו חזק יותר';
    if (c < 0) return 'הכלל של ' + D.label + ' ינצח — הבורר שלו חזק יותר';
    const later = t.r && t.r.src && by.r.src && by.r.path === t.r.path ? by.r.order > t.r.order : true;
    return later ? other + ' ינצח ברוחב שבו שניהם חלים — הוא כתוב אחרי הכלל הבסיסי בקובץ. כדי ש' + D.label + ' ינצח — עורכים את ההצהרה ההיא (ברירת המחדל)' : 'הכלל של ' + D.label + ' ינצח — הוא נכתב אחרי';
  }

  class Design {
    /** opt: { canvas, styler, nodes(): [נבחרים], write(nodes, sets, {target, label, unimportant}), preview(nodes, sets), unpreview(),
     *         openAt(path, line), canWrite(), highlight(els), hint(phys), sheets(): Map, mode } */
    constructor(host, opt) { Object.assign(this, opt); this.host = host; this.tgt = new WeakMap(); this.drafts = new Map(); this.lock = { margin: 'none', padding: 'none' }; }
    get n() { return this.canvas.selected; }
    target(n, list) {
      const want = this.tgt.get(n), hit = want && list.find(t => t.kind === want.kind && t.sel === want.sel && t.path === want.path && (t.line || 0) === (want.line || 0));
      return hit || list[0];
    }
    setTarget(t) { if (this.n) this.tgt.set(this.n, { kind: t.kind, sel: t.sel, path: t.path, line: t.line || 0, name: t.name }); this.draw(); }
    draw() {
      const n = this.n, c = this.canvas;
      this.unpreview?.();
      if (!n || !c.m) { this.host.replaceChildren(h('p', { class: 'hint' }, 'בוחרים אלמנט — בקנבס או ב-Navigator — ומשנים כאן את ' + (this.mode === 'adv' ? 'המרווחים שלו.' : 'הגופן והצבע שלו.'))); return; }
      if (!c.elOf(n) || c.visual(n) === 'nonvisual') { this.host.replaceChildren(h('p', { class: 'hint' }, 'לאלמנט הזה אין מראה בעמוד (ראש העמוד / סקריפט / שדה מוסתר).')); return; }
      const scroll = this.host.scrollTop;
      // V8: מצב — הקנבס מסומן לפני קריאת המפל; היעדים מחושבים מהמצב הרגיל, והמפל — מהמצב
      this.stt = this.states ? this.states.cur(n) : null; if (this.states) this.states.apply();
      this.infoBase = this.styler.info(n); this.info = this.stt ? this.styler.info(n, this.stt.pc) : this.infoBase;
      this.list = this.moreTargets(n, this.styler.targets(n, this.infoBase));
      const many = this.nodes().length;
      this.t0 = many > 1 ? this.multiTarget(n) || this.target(n, this.list) : this.target(n, this.list);
      this.t = this.styler.st(this.styler.dev(this.t0), this.stt);   // V7: במכשיר — אותו בורר בתוך @media; V8: במצב — <יעד>:<מצב>
      const kids = [];
      if (this.states) kids.push(this.states.bar(n));
      kids.push(this.drawTarget(n));
      if (many > 1) kids.push(this.drawMulti(n, many));
      if (this.mode === 'adv') { const lay = this.layout && this.layout(n, this); if (lay) kids.push(lay); kids.push(...this.drawSpacing(n)); if (this.extra) kids.push(...this.extra(n)); } else kids.push(...this.drawType(n), ...this.drawColor(n), ...(this.fx ? this.fx(n) : []), this.transition ? this.transition(n, this) : this.drawMotion(n));   // V10: מסגרת / צל / אפקטים / מסיכה / מפריד, ומעבר עם עורך עקומה
      if (this.extraProps) kids.push(this.extraProps(n, this));
      this.host.replaceChildren(...kids);
      this.host.scrollTop = scroll;
    }

    // ── "משנה את:" ──────────────────────────────────────────
    stateLabel() { return this.stt ? this.stt.label : 'רגיל'; }
    tlabel(t) {
      if (t.state) return t.state.sel.replace(/\s+/g, ' ') + ' · ' + (t.r ? t.r.path : t.path) + (t.dev ? ' · ' + t.dev.label : '') + ' · מצב ' + this.stateLabel();
      if (t.kind === 'inline') return 'רק האלמנט הזה · style=""';
      if (t.kind === 'newclass') return 'מחלקה חדשה .' + t.name + ' · ' + t.path;
      return t.sel.replace(/\s+/g, ' ') + ' · ' + t.path + (t.line ? ':' + t.line : '') + (t.kind === 'new' ? ' (כלל חדש)' : '') + (t.media ? ' · ' + t.media : '');
    }
    drawTarget(n) {
      const t = this.t, why = { class: 'הכלל של המחלקה', source: 'כלל אחר שחל', media: 'בתוך @media', inline: '' };
      const sel = h('select', { class: 'sg-tsel', 'aria-label': 'משנה את — לאן השינויים נכתבים', 'data-target': true });
      Object.assign(why, { tag: 'כל התגיות ' + n.tag + ' (styles.base)', typed: 'בורר בהקלדה', shared: 'מחלקה של האלמנט' });
      const wins = this.wins(n);
      this.list.forEach((x, i) => sel.append(h('option', { value: String(i), selected: x === this.t0 || null, 'data-win': wins.get(x) ? 'over' : 'ok' }, (i === 0 ? '★ ' : '') + this.tlabel(this.styler.st(x, this.stt)) + (x.kind !== 'inline' && x.kind !== 'newclass' ? ' · משפיע על ' + x.count : '') + (why[x.why] && i ? ' — ' + why[x.why] : '') + (wins.get(x) ? ' · ⚠ נדרס ע״י ' + wins.get(x) : ' · ✓'))));
      sel.append(h('option', { value: 'typed' }, '✎ בורר בהקלדה…'));
      sel.addEventListener('change', () => { if (sel.value === 'typed') { this.typing = true; this.draw(); setTimeout(() => this.host.querySelector('[data-typed-sel]')?.focus(), 0); return; } this.setTarget(this.list[+sel.value]); });
      const hi = on => this.highlight(on ? this.styler.elementsOf(this.t) : null);
      sel.addEventListener('mouseenter', () => hi(true)); sel.addEventListener('mouseleave', () => hi(false)); sel.addEventListener('focus', () => hi(true)); sel.addEventListener('blur', () => hi(false));
      const box = h('div', { class: 'sg-target' }, h('label', { class: 'sg-tlabel' }, 'משנה את:', sel));
      const cnt = t.kind === 'inline' ? 1 : t.count;
      if (this.typing || t.why === 'typed') box.append(this.typedField(n, t));
      box.append(h('p', { class: 'sg-tinfo', 'data-tinfo': true }, h('code', { dir: 'ltr' }, t.state ? t.state.sel.replace(/\s+/g, ' ') : t.kind === 'newclass' ? '.' + t.name : t.sel.replace(/\s+/g, ' ')), ' · ', h('span', { dir: 'ltr' }, t.path), ' · ', h('b', { 'data-count': cnt }, 'משפיע על ' + cnt + (cnt === 1 ? ' אלמנט' : ' אלמנטים'))));
      if (t.kind === 'newclass') {
        const inp = h('input', { type: 'text', dir: 'ltr', value: t.name, 'aria-label': 'שם המחלקה החדשה', 'data-newclass': true, spellcheck: 'false' });
        inp.addEventListener('change', () => { const v = inp.value.trim(); if (/^-?[_a-zA-Z][\w-]*$/.test(v)) { t.name = v; t.sel = '.' + v; this.tgt.set(n, { kind: 'newclass', sel: '.' + v, path: t.path, line: 0, name: v }); inp.classList.remove('bad'); } else inp.classList.add('bad'); });
        box.append(h('p', { class: 'hint sg-newclass' }, 'לאלמנט אין מחלקה. בשינוי הראשון תיווסף לו המחלקה ', inp, ' ב-HTML, וכלל בשמה ב-' + t.path + ' — עסקה אחת, שני קבצים.'));
      } else if (t.kind === 'new') box.append(h('p', { class: 'hint' }, 'אין עדיין כלל למחלקה — בשינוי הראשון ייווצר ' + t.sel + ' ב-' + t.path + '.'));
      const D = this.styler.device();
      if (D && t.kind === 'inline') box.append(h('p', { class: 'sg-over', 'data-dev-inline': true }, '📱 ' + D.label + ': "רק האלמנט הזה" (style="") אינו יכול לקבל מכשיר — ל-style אין @media. ', h('button', { type: 'button', class: 'btn sm', onclick: () => { const c2 = this.list.find(x => x.kind !== 'inline'); if (c2) this.setTarget(c2); } }, this.list.some(x => x.kind === 'newclass') ? 'ליצור מחלקה (שם קריא)' : 'לכתוב לכלל המחלקה')));
      else if (D && t.dev) box.append(h('p', { class: 'hint sg-devt', 'data-dev-target': D.id }, '📱 נכתב ל-' + D.label + ': ', h('code', { dir: 'ltr' }, '@media (max-width: ' + D.max + 'px) { ' + (t.kind === 'newclass' ? '.' + t.name : t.sel.replace(/\s+/g, ' ')) + ' }'), ' · ' + t.path));
      return box;
    }

    // ── מקור, נדרס, איפוס ───────────────────────────────────
    srcLine(n, phys) {
      const s = this.styler.source(n, phys, this.info), out = h('div', { class: 'sg-src', 'data-src': phys });
      const at = (w, pre) => { const b = h('button', { type: 'button', class: 'sg-at', dir: 'ltr', title: 'פתח בקוד', disabled: w.r.external || null }, (pre || '') + w.r.sel.replace(/\s+/g, ' ') + ' · ' + w.r.path + (w.d.line ? ':' + w.d.line : '') + (w.d.important ? ' !important' : '')); if (!w.r.external) b.addEventListener('click', () => this.openAt(w.r.path, w.d.line || w.r.line || 1)); return b; };
      if (s.winner) { out.append('מ: ', at(s.winner)); if (s.winner.r.media) out.append(h('span', { class: 'sg-media' }, ' ' + s.winner.r.media)); if (s.winner.r.inline) out.append(' (style="" של האלמנט)'); }
      else if (s.inherited) { out.append('בירושה מ-' + s.inherited.label + ': ', at(s.inherited.winner)); }
      else out.append(h('span', { class: 'hint' }, 'ברירת מחדל של הדפדפן'));
      const vc = this.varChip ? this.varChip(n, phys, s, this) : null; if (vc) out.prepend(vc);   // V9: ערך קשור למשתנה — צ'יפ
      if (this.varBtn && !vc) { const vb = this.varBtn(n, phys, phys, this); if (vb) out.append(' ', vb); }   // V9: ⛓ קשר למשתנה / צור משתנה מהערך — בשורת המקור (לא גוזל רוחב מהשדה)
      const dr = this.devRow(n, phys); if (dr) out.prepend(dr);
      const sr = this.stRow(n, phys, s); if (sr) out.prepend(sr);
      const vis = this.styler.visible(n, this.t, phys, this.info);
      if (!vis.ok) {
        const by = vis.by, w = h('div', { class: 'sg-over' + (vis.media ? ' media' : ''), 'data-over': phys });
        const mine = this.t.state || this.t.dev ? this.styler.ownDecl(n, this.t, phys, this.info) : this.t.kind === 'rule' ? ST().declFor(this.t.r.node, phys, this.styler.rtl(n)) : null;
        if (vis.media) {
          w.append('הערך הנראה מגיע מתוך ' + by.r.media + ' (' + mediaWhen(by.r.media) + ') — שינוי כאן נכתב שם.');
          const D = this.styler.device();
          if (D && this.t.dev) w.append(h('div', { class: 'sg-over-go' }, h('button', { type: 'button', class: 'btn sm', 'data-over-dev': phys, title: 'הערך ייכתב ל-@media (max-width: ' + D.max + 'px). מי ינצח: ' + whoWins(by, this.t, D), onclick: () => { this.redirect = { phys, t: this.t }; this.focusField(phys); } }, 'לכתוב ל' + D.label + ' בכל זאת'), h('span', { class: 'hint', 'data-who-wins': true }, ' — ' + whoWins(by, this.t, D))));
        }
        else {
          w.append('⚠️ ' + (mine ? 'כתוב כאן: ' + (ST().componentOf(mine.prop, mine.value, phys, this.styler.rtl(n)) || mine.value) + ' — אבל ' : 'ערך שייכתב כאן לא ייראה — ') + 'נדרס ע״י ', at(by), ' (' + (by.d.important && !(mine && mine.important) ? '!important' : 'כלל חזק יותר') + ')');
          const tb = this.list.find(x => x.kind === 'rule' && x.r === by.r) || (by.r.inline ? this.list.find(x => x.kind === 'inline') : null);
          if (tb && !by.r.external) {
            w.append(h('div', { class: 'sg-over-go' },
              h('button', { type: 'button', class: 'btn sm', 'data-over-write': phys, onclick: () => { this.redirect = { phys, t: tb }; this.focusField(phys); } }, 'לשנות את הכלל הדורס'),
              h('button', { type: 'button', class: 'btn sm', 'data-over-move': phys, onclick: () => this.setTarget(tb) }, 'להעביר את היעד אליו')));
          }
        }
        out.append(w);
      }
      return { el: out, s, vis };
    }
    /** היעד בפועל של מאפיין: כשהערך הנראה מגיע מ-@media — ההצהרה ההיא (03 §4 "מה לא כלול"); "לשנות את הכלל הדורס" — הכלל ההוא. */
    effTarget(n, phys) {
      if (this.redirect && this.redirect.phys === phys) return this.redirect.t;
      const vis = this.styler.visible(n, this.t, phys, this.info);
      if (!vis.ok && vis.media) return this.list.find(x => x.kind === 'rule' && x.r === vis.by.r) || this.t;
      return this.t;
    }
    focusField(phys) { const f = this.host.querySelector('[data-field="' + phys + '"] input'); if (f) { f.focus(); f.select?.(); } }
    /** כתיבה: לכל אלמנט נבחר — ביעד שלו (לאלמנט הראשי — היעד שבבורר). */
    put(sets, label, opt = {}) {
      const n = this.n, nodes = this.nodes(), per = new Map();
      sets = sets.flatMap(([p, v]) => { const f = /^(margin|padding)-/.exec(p); return f ? linked(p, this.lock[f[1]]).map(q => [q, v]) : [[p, v]]; });
      const base = /^transition-/;   // V8: "מעבר" נכתב לכלל הרגיל (בלי מצב) — הוא חל בכניסה למצב וביציאה ממנו
      const shared = nodes.length > 1 && this.multiOn();   // V8: מחלקה משותפת — פעם אחת, לכולם
      for (const x of nodes) {
        if (x === n) { per.set(x, sets.map(([p, v]) => [p, v, base.test(p) ? this.styler.dev(this.t0) : this.effTarget(n, p)])); continue; }
        if (shared) continue;
        const list = this.styler.targets(x); per.set(x, sets.map(([p, v]) => [p, v, base.test(p) ? list[0] : this.styler.st(list[0], this.stt)]));
      }
      this.redirect = null;
      return this.write(per, label, opt);
    }
    curDeclared(n, phys) { const s = this.styler.source(n, phys, this.info); return s.winner ? s.declared : ''; }

    // ── V8: מצבים, כלל היעד המלא, מחלקה משותפת ──
    /** שורת המצב של ערך: ● נכתב במצב · ○ עובר מרגיל (עם הערך) · כלל משותף לשני מצבים — נאמר. */
    stRow(n, phys, s) {
      const t = this.t; if (!t.state) return null;
      const own = this.styler.stOwn(this.info, t), d = own && own.node ? ST().declFor(own.node, phys, this.styler.rtl(n)) : null;
      const row = h('div', { class: 'sg-dev sg-st', 'data-st-state': phys });
      if (d) { row.dataset.state = 'own'; row.append(h('span', { class: 'sg-dev-sym' }, '●'), 'נכתב במצב ' + this.stateLabel()); if (window.KolDetails.splitSel(own.sel).length > 1) row.append(h('span', { class: 'hint', 'data-st-shared': true }, ' · כלל משותף: ' + own.sel.replace(/\s+/g, ' '))); }
      else { const v = this.styler.source(n, phys, this.infoBase); row.dataset.state = 'from'; row.append(h('span', { class: 'sg-dev-sym from' }, '○'), 'עובר מרגיל', h('span', { dir: 'ltr', class: 'sg-dev-val' }, ' ' + ((v.winner ? v.declared : v.value) || '—'))); }
      return row;
    }
    /** יעדים נוספים (03 §4.2): כל מחלקה של האלמנט, התגית (styles.base), ובורר שהוקלד. */
    moreTargets(n, list) {
      const K2 = window.KolSrc, page = this.styler.pagePath && this.styler.pagePath(), basePath = this.styler.basePath ? this.styler.basePath() : null;
      const has = sel => list.some(x => x.kind !== 'inline' && normSel(x.kind === 'newclass' ? '.' + x.name : x.sel) === normSel(sel));
      const at = list.findIndex(x => x.kind === 'inline'), add = t => list.splice(at < 0 ? list.length : list.indexOf(list.find(x => x.kind === 'inline')), 0, t);
      for (const c of K2.classesOf(n)) if (page && !has('.' + c)) add({ kind: 'new', sel: '.' + c, path: page, why: 'shared', count: this.styler.count('.' + c) });
      if (basePath && n.tag && !has(n.tag)) {
        const r = this.infoBase.rules.filter(x => x.located && !x.external && !x.inline && !x.media && x.node && x.path === basePath && normSel(x.sel) === n.tag).pop();
        add(r ? { kind: 'rule', sel: r.sel, path: r.path, line: r.line, r, why: 'tag', count: this.styler.count(n.tag) } : { kind: 'new', sel: n.tag, path: basePath, why: 'tag', count: this.styler.count(n.tag) });
      }
      const ty = this.typedSel && this.typedSel.get(n);
      if (ty && !has(ty)) {
        const r = this.infoBase.rules.filter(x => x.located && !x.external && !x.inline && !x.media && x.node && normSel(x.sel) === normSel(ty)).pop();
        add(r ? { kind: 'rule', sel: r.sel, path: r.path, line: r.line, r, why: 'typed', count: this.styler.count(ty) } : { kind: 'new', sel: ty, path: page, why: 'typed', count: this.styler.count(ty) });
      }
      return list;
    }
    /** "בורר בהקלדה": כל בורר; על כמה ועל אילו אלמנטים הוא חל, ואזהרה אם אינו חל על הנבחר. */
    typedField(n, t) {
      const cur = t.why === 'typed' ? t.sel : '', inp = h('input', { type: 'text', dir: 'ltr', value: cur, placeholder: '.page h2 · main > section:first-child', 'aria-label': 'בורר בהקלדה', spellcheck: 'false', 'data-typed-sel': true });
      const msg = h('p', { class: 'hint', 'data-typed-msg': true });
      const show = v => {
        if (!v) { msg.textContent = 'בורר CSS כלשהו — הכלל נכתב ל-' + (this.styler.pagePath() || '') + '.'; return; }
        let els; try { ST().checkSelector(v); els = [...this.canvas.doc.querySelectorAll(v)].filter(e => !e.closest('[data-kol-canvas]')); } catch (e) { msg.className = 'sg-over'; msg.textContent = e.message || 'בורר לא חוקי'; inp.classList.add('bad'); return false; }
        const e0 = this.canvas.elOf(n), hit = els.includes(e0);
        msg.className = hit ? 'hint' : 'sg-over'; inp.classList.remove('bad');
        msg.textContent = 'חל על ' + els.length + ': ' + els.slice(0, 5).map(e => { const x = this.canvas.nodeOf(e); return x ? this.canvas.label(x) : e.localName; }).join(', ') + (els.length > 5 ? '…' : '') + (hit ? '' : ' — ⚠️ אינו חל על האלמנט הנבחר');
        this.highlight(els); return true;
      };
      inp.addEventListener('input', () => show(inp.value.trim()));
      inp.addEventListener('change', () => { const v = inp.value.trim(); if (!v || show(v) === false) return; (this.typedSel = this.typedSel || new WeakMap()).set(n, v); this.typing = false; this.tgt.set(n, { kind: 'x', sel: v }); this.list = null; const list = this.moreTargets(n, this.styler.targets(n, this.infoBase)), tt = list.find(x => x.why === 'typed'); if (tt) this.setTarget(tt); else this.draw(); });
      inp.addEventListener('blur', () => this.highlight(null));
      show(cur);
      return h('div', { class: 'sg-typed' }, h('label', { class: 'sg-line' }, h('span', { class: 'sg-label' }, 'בורר'), h('span', { class: 'sg-ctl wide' }, inp)), msg);
    }
    /** לכל יעד ברשימה — האם ינצח את מה שנראה היום (לפני הבחירה): המאפיינים שכתובים היום על האלמנט, ומי דורס. */
    wins(n) {
      const out = new Map(), rtl = this.styler.rtl(n), props = new Set();
      for (const r of this.info.rules) if (!r.off) for (const d of r.decls) for (const p of ST().covered(d.prop, rtl)) if (props.size < 40) props.add(p);
      for (const x of this.list) {
        const tt = this.styler.st(this.styler.dev(x), this.stt), by = new Set();
        for (const p of props) { const v = this.styler.visible(n, tt, p, this.info); if (!v.ok && v.by && !v.media) by.add(v.by.r.sel.replace(/\s+/g, ' ')); }
        if (by.size) out.set(x, [...by].slice(0, 2).join(' / ') + (by.size > 2 ? ' ועוד' : ''));
      }
      return out;
    }
    /** בחירה מרובה: מפתח לבחירה (הנתיבים), והמצב — "כל אחד בכלל שלו" / מחלקה משותפת. */
    multiKey() { return this.nodes().map(x => window.KolSrc.pathOf(x).join('.')).sort().join('|'); }
    multiOn() { const m = (this.multiMode || {})[this.multiKey()]; if (m === 'each') return null; const sh = this.identity ? this.identity.shared(this.nodes()) : []; if (m) return sh.find(x => x.cls === m) || null; return sh.find(x => x.r) || null; }
    multiTarget(n) {
      const on = this.multiOn(); if (!on) return null;
      const r = on.r && this.list.find(x => x.kind === 'rule' && x.r && normSel(x.sel) === '.' + on.cls && !x.media);
      return r || { kind: 'new', sel: '.' + on.cls, path: this.styler.pagePath(), why: 'class', count: on.count };
    }
    drawMulti(n, many) {
      const sh = this.identity ? this.identity.shared(this.nodes()) : [], on = this.multiOn();
      const sel = h('select', { 'aria-label': 'בחירה מרובה — משנה את', 'data-multi-mode': true });
      sel.append(h('option', { value: 'each', selected: !on || null }, 'כל אחד בכלל שלו (' + many + ')'));
      for (const x of sh) sel.append(h('option', { value: x.cls, selected: on && on.cls === x.cls || null }, '.' + x.cls + ' — מחלקה משותפת · משפיע על ' + x.count + (x.r ? '' : ' (כלל חדש)')));
      sel.addEventListener('change', () => { this.multiMode = this.multiMode || {}; this.multiMode[this.multiKey()] = sel.value; this.draw(); });
      const mk = h('button', { type: 'button', class: 'btn sm', 'data-shared-create': true, disabled: !this.canWrite() || null, onclick: () => this.identity.createDialog(this.nodes(), v => { this.multiMode = this.multiMode || {}; this.multiMode[this.multiKey()] = v; this.draw(); }) }, '✳️ צור מחלקה משותפת');
      return h('div', { class: 'sg-multi', 'data-multi': many }, h('label', { class: 'sg-line' }, h('span', { class: 'sg-label' }, '✳️ ' + many + ' נבחרים — משנה את:'), h('span', { class: 'sg-ctl wide' }, sel)), mk,
        h('p', { class: 'hint' }, on ? 'שינוי נכתב פעם אחת, לכלל .' + on.cls + ' — חל על כל ' + on.count + '.' : 'שינוי חל על כולם, כל אחד בכלל שלו (Shift + לחיצה מוסיף).'));
    }
    /** "תנועה" הבסיסית: מעבר בין רגיל למצב (שאר תנועה — V10). נכתב לכלל הרגיל. */
    drawMotion(n) {
      const sec = h('section', { class: 'sg-sec', 'data-sec': 'motion' }, h('h3', { class: 'dt-h' }, 'תנועה — מעבר בין מצבים'));
      sec.append(this.choice('transition-property', 'מאפיין', [['all', 'הכול'], ['color', 'צבע'], ['background-color', 'צבע רקע'], ['opacity', 'שקיפות'], ['transform', 'transform'], ['box-shadow', 'צל'], ['none', 'בלי מעבר']]));
      sec.append(this.num('transition-duration', 'משך', { units: ['s', 'ms'], step: 0.05, unitless: false }), this.num('transition-delay', 'השהיה', { units: ['s', 'ms'], step: 0.05 }));
      sec.append(this.choice('transition-timing-function', 'עקומה', [['ease', 'ease'], ['linear', 'linear'], ['ease-in', 'ease-in'], ['ease-out', 'ease-out'], ['ease-in-out', 'ease-in-out'], ['steps(4, end)', 'steps(4)'], ['cubic-bezier(.2, .8, .2, 1)', 'cubic-bezier(.2,.8,.2,1)']]));
      sec.append(h('p', { class: 'hint' }, 'נכתב לכלל הרגיל (לא למצב) — כך המעבר חל בכניסה ובציאה.'));
      return sec;
    }

    // ── V7: מכשירים — "נכתב כאן / עובר מ- / נדרס" לכל ערך ──
    /** שורת המכשיר של ערך: ● נכתב כאן · ○ עובר ממחשב / מטאבלט (עם הערך) · ⚠ נכתב כאן אבל נדרס. ריחוף — הערך בכל מכשיר;
     *  לחיצה על "עובר מ-" — עוברת למכשיר ההוא. ולצד ערך שנכתב במכשיר: "לכל המכשירים" / "מכאן ומטה". */
    devRow(n, phys) {
      const devs = this.styler.devs(); if (devs.length < 2 || !this.info) return null;
      const D = this.styler.device(), all = this.styler.perDevice(n, phys, this.info), cur = all.find(x => (D ? x.d.id === D.id : x.d.max == null)); if (!cur) return null;
      const rtl = this.styler.rtl(n), w = this.styler.cands(this.info, phys, rtl)[0], here = D ? D.max : null, others = all.filter(x => x.own && x !== cur);
      // בלי מידע — בלי שורה: במחשב, כשאף מכשיר אינו שונה; במכשיר, כשהערך אינו כתוב בשום מקום (ברירת מחדל / ירושה)
      if (!D && !others.length) return null;
      if (D && !cur.own && !cur.value) return null;
      const wonHere = w && (here == null ? !w.r.media : ST().ruleMax(w.r) === here);
      const row = h('div', { class: 'sg-dev', 'data-dev-state': phys, title: all.map(x => x.d.label + ': ' + (x.value || '—') + (x.own ? ' (נכתב שם)' : '')).join('\n') });
      let state;
      if (cur.own) { state = w && !wonHere ? 'over' : 'own'; row.append(h('span', { class: 'sg-dev-sym ' + state }, state === 'over' ? '⚠' : '●'), state === 'over' ? 'נכתב ב' + cur.d.label + ' — אבל נדרס' : 'נכתב ב' + cur.d.label); }
      else if (!D) { state = 'base'; row.append(h('span', { class: 'sg-dev-sym from' }, '○'), 'במחשב — לא נכתב'); }
      else if (cur.value) { state = 'from'; const from = devs.find(x => x.id === cur.from); row.append(h('span', { class: 'sg-dev-sym from' }, '○'), h('button', { type: 'button', class: 'sg-dev-from', 'data-dev-from': cur.from, title: 'מעבר ל' + (from ? from.label : ''), onclick: () => this.setDevice && this.setDevice(cur.from) }, 'עובר מ' + (from ? from.label : '…')), h('span', { dir: 'ltr', class: 'sg-dev-val' }, ' ' + cur.value)); }
      else { state = 'none'; row.append(h('span', { class: 'sg-dev-sym from' }, '○'), 'לא נכתב בשום מכשיר'); }
      row.dataset.state = state;
      if (others.length) row.append(h('span', { class: 'sg-dev-others', 'data-dev-others': true }, ' · שונה ב: ' + others.map(x => x.d.label + ' ' + x.value).join(', ')));
      if (cur.own && this.canWrite() && (D || others.length)) {
        row.append(h('button', { type: 'button', class: 'btn sm sg-dev-all', 'data-dev-all': phys, title: 'הערך ' + cur.value + ' — בכל המכשירים: נכתב לבסיס, ונמחק מכל @media של מכשיר', onclick: () => this.copyDev(n, phys, cur.value, 'all') }, '⇊ לכל המכשירים'));
        const below = devs.filter(x => x.max && (here == null || x.max < here) && all.find(y => y.d.id === x.id && y.own));
        if (below.length) row.append(h('button', { type: 'button', class: 'btn sm sg-dev-down', 'data-dev-down': phys, title: 'המכשירים הצרים מ' + cur.d.label + ' (' + below.map(x => x.label).join(', ') + ') יקבלו את הערך הזה: ההצהרות שלהם נמחקות', onclick: () => this.copyDev(n, phys, cur.value, 'down') }, '↧ מכאן ומטה'));
      }
      return row;
    }
    /** "העתק לכל המכשירים" / "החל מהמכשיר הזה ומטה" — עסקה אחת. */
    copyDev(n, phys, v, how) {
      const devs = this.styler.devs(), D = this.styler.device(), base = this.t0 || this.t, sets = [];
      if (base.kind === 'inline') return;
      const owned = d => this.styler.perDevice(n, phys, this.info).find(x => x.d.id === d.id)?.own;
      if (how === 'all') { sets.push([phys, v, Object.assign({}, base, { dev: null })]); for (const d of devs) if (d.max && owned(d)) sets.push([phys, null, this.styler.dev(base, d)]); }
      else for (const d of devs) if (d.max && (!D || d.max < D.max) && owned(d)) sets.push([phys, null, this.styler.dev(base, d)]);
      if (!sets.length) return;
      this.write(new Map([[n, sets]]), (how === 'all' ? 'לכל המכשירים' : 'מ' + (D ? D.label : 'מחשב') + ' ומטה') + ' · ' + this.canvas.label(n) + ' · ' + phys + ': ' + v);
    }

    // ── השליטה המספרית ──────────────────────────────────────
    num(phys, label, opt = {}) {
      const n = this.n, row = h('div', { class: 'sg-field', 'data-field': phys });
      const { el: src, s } = this.srcLine(n, phys);
      const declared = s.winner ? s.declared : '', shown = declared || s.value || '';
      const inp = h('input', { type: 'text', dir: 'ltr', inputmode: 'decimal', value: shown, 'aria-label': label, spellcheck: 'false', autocomplete: 'off', disabled: !this.canWrite() || null });
      const unit = h('select', { class: 'sg-unit', 'aria-label': 'יחידה — ' + label, disabled: !this.canWrite() || null }, UNITS.concat(opt.units || [], 'auto').map(u => h('option', { value: u }, u)), h('option', { value: '' }, '…'));
      const neg = valid(phys, '-1px');
      const parts = v => { const m = NUM.exec(String(v).trim()); return m ? { x: parseFloat(m[1]), u: m[2] } : null; };
      const syncUnit = v => { const p = parts(v); unit.value = p ? (UNITS.includes(p.u) || (opt.units || []).includes(p.u) ? p.u : '') : (/^auto$/i.test(v.trim()) ? 'auto' : ''); };
      syncUnit(shown);
      let committed = shown, t = 0;
      const check = v => { const ok = v === '' || valid(phys, v); inp.classList.toggle('bad', !ok); inp.setAttribute('aria-invalid', String(!ok)); inp.title = ok ? '' : 'ערך לא חוקי ל-' + phys + ' — לא נכתב'; return ok; };
      const commit = v => {
        clearTimeout(t); v = v.trim();
        if (!check(v) || v === committed) { this.unpreview(); return; }
        const was = committed; committed = v;
        this.put([[phys, v === '' ? null : v]], (opt.group || label) + ' · ' + this.canvas.label(n) + ' · ' + (was || '—') + ' ← ' + (v || 'איפוס'));
      };
      const step = (dir, e) => {
        const cur = inp.value.trim(), p = parts(cur) || (cur === '' || /^auto|normal$/i.test(cur) ? parts(this.styler.computed(n, phys)) : null);
        if (!p) return;
        const k = e && e.shiftKey ? 10 : e && e.altKey ? 0.1 : 1, u = p.u || (opt.unitless ? '' : 'px');
        let x = round(p.x + dir * k * (opt.step || 1)); if (x < 0 && !neg) x = 0;
        const v = fmt(x) + u; inp.value = v; syncUnit(v); check(v);
        this.preview(this.nodes(), [[phys, v]]);
        clearTimeout(t); t = setTimeout(() => commit(inp.value), 450);
      };
      inp.addEventListener('keydown', e => {
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); step(e.key === 'ArrowUp' ? 1 : -1, e); }
        else if (e.key === 'Enter') { e.preventDefault(); commit(inp.value); }
        else if (e.key === 'Escape') { inp.value = committed; this.unpreview(); check(committed); }
      });
      inp.addEventListener('input', () => { syncUnit(inp.value); if (check(inp.value.trim()) && inp.value.trim()) this.preview(this.nodes(), [[phys, inp.value.trim()]]); });
      inp.addEventListener('change', () => commit(inp.value));
      inp.addEventListener('wheel', e => { if (document.activeElement !== inp) return; e.preventDefault(); step(e.deltaY < 0 ? 1 : -1, e); }, { passive: false });
      unit.addEventListener('change', () => {
        const u = unit.value; if (!u) return;
        const p = parts(inp.value) || parts(this.styler.computed(n, phys)) || { x: 0 };
        const v = u === 'auto' ? 'auto' : fmt(p.x) + u; inp.value = v; commit(v);
      });
      // גרירה על התווית: ימינה / שמאלה משנה את המספר
      const lab = h('span', { class: 'sg-label', title: 'גרירה ימינה / שמאלה משנה את הערך · Shift ×10 · Alt ×0.1', 'data-scrub': phys }, label);
      lab.addEventListener('pointerdown', e => {
        if (!this.canWrite()) return;
        const p0 = parts(inp.value) || parts(this.styler.computed(n, phys)); if (!p0) return;
        e.preventDefault(); lab.setPointerCapture(e.pointerId);
        const x0 = e.clientX, u = p0.u || (opt.unitless ? '' : 'px');
        const move = ev => { const k = ev.shiftKey ? 10 : ev.altKey ? 0.1 : 1; let x = round(p0.x + Math.round((ev.clientX - x0) / 2) * k * (opt.step || 1)); if (x < 0 && !neg) x = 0; inp.value = fmt(x) + u; this.preview(this.nodes(), [[phys, inp.value]]); };
        const up = () => { lab.removeEventListener('pointermove', move); lab.removeEventListener('pointerup', up); lab.removeEventListener('pointercancel', up); commit(inp.value); };
        lab.addEventListener('pointermove', move); lab.addEventListener('pointerup', up); lab.addEventListener('pointercancel', up);
      });
      const minus = h('button', { type: 'button', class: 'sg-step', 'aria-label': 'הקטנה — ' + label, disabled: !this.canWrite() || null, onclick: e => step(-1, e) }, '−');
      const plus = h('button', { type: 'button', class: 'sg-step', 'aria-label': 'הגדלה — ' + label, disabled: !this.canWrite() || null, onclick: e => step(1, e) }, '+');
      const reset = this.resetBtn(n, phys, label);
      row.append(h('div', { class: 'sg-line' }, lab, h('span', { class: 'sg-ctl' }, minus, inp, plus, unit), reset), src);
      if (declared && s.value && declared !== s.value && !/var\(|calc\(|%|em|auto|normal/.test(declared)) src.prepend(h('span', { class: 'sg-computed' }, 'נראה: ' + s.value + ' · '));
      if (opt.hint) row.addEventListener('mouseenter', () => this.hint(phys)), row.addEventListener('mouseleave', () => this.hint(null));
      return row;
    }
    resetBtn(n, phys, label) {
      const t = this.effTarget(n, phys), rtl = this.styler.rtl(n);
      let has = false, imp = false;
      if (t.kind === 'rule' || t.dev || t.state) { const d = this.styler.ownDecl(n, t, phys, this.info); has = !!d; imp = !!(d && d.important) && !t.dev && !t.state; }
      else if (t.kind === 'inline') { const r = this.info.rules.find(x => x.inline); const d = r && r.decls.find(x => ST().covered(x.prop, rtl).includes(phys)); has = !!d; imp = !!(d && d.important); }
      const box = h('span', { class: 'sg-acts' });
      if (imp) box.append(h('button', { type: 'button', class: 'btn sm sg-unimp', 'data-unimportant': phys, title: 'ההצהרה כתובה עם !important — העורך שומר עליו בעריכה; כאן מסירים אותו', disabled: !this.canWrite() || null, onclick: () => this.put([[phys, '']], 'הסרת !important · ' + label + ' · ' + this.canvas.label(n), { unimportant: true }) }, '!important ✕'));
      box.append(h('button', { type: 'button', class: 'sg-reset', 'data-reset': phys, 'aria-label': (t.state ? 'איפוס במצב ' + this.stateLabel() + ' — ' : t.dev ? 'איפוס ב' + t.dev.label + ' — ' : 'איפוס — ') + 'מחיקת ההצהרה של ' + label + ' מהיעד', title: has ? (t.dev ? 'איפוס במכשיר הזה: מוחק רק את ההצהרה שבתוך @media (max-width: ' + t.dev.max + 'px)' : 'איפוס: מוחק את ההצהרה מהיעד (לא כותב ברירת מחדל)') : 'אין הצהרה ביעד', disabled: !has || !this.canWrite() || null,
        onclick: () => this.put([[phys, null]], label + ' · ' + this.canvas.label(n) + ' · איפוס') }, '⟲'));
      return box;
    }
    choice(phys, label, options, opt = {}) {
      const n = this.n, row = h('div', { class: 'sg-field', 'data-field': phys }), { el: src, s } = this.srcLine(n, phys);
      const cur = (s.winner ? s.declared : s.value) || '';
      const sel = h('select', { 'aria-label': label, disabled: !this.canWrite() || null });
      const opts = options.slice(); if (cur && !opts.some(o => o[0] === cur)) opts.unshift([cur, cur]);
      for (const [v, t] of opts) sel.append(h('option', { value: v, selected: v === cur || null }, t));
      sel.addEventListener('change', () => this.put([[phys, sel.value]], label + ' · ' + this.canvas.label(n) + ' · ' + (cur || '—') + ' ← ' + sel.value));
      row.append(h('div', { class: 'sg-line' }, h('span', { class: 'sg-label' }, label), h('span', { class: 'sg-ctl' }, sel), this.resetBtn(n, phys, label)), src);
      void opt; return row;
    }
    seg(phys, label, options) {
      const n = this.n, row = h('div', { class: 'sg-field', 'data-field': phys }), { el: src, s } = this.srcLine(n, phys);
      const cur = (s.winner ? s.declared : s.value) || '';
      const g2 = h('span', { class: 'sg-seg', role: 'group', 'aria-label': label });
      for (const [v, icon, t] of options) g2.append(h('button', { type: 'button', 'aria-pressed': String(v === cur), 'data-v': v, title: t, 'aria-label': t, disabled: !this.canWrite() || null, onclick: () => this.put([[phys, v]], label + ' · ' + this.canvas.label(n) + ' · ' + (cur || '—') + ' ← ' + v) }, icon));
      row.append(h('div', { class: 'sg-line' }, h('span', { class: 'sg-label' }, label), g2, this.resetBtn(n, phys, label)), src);
      return row;
    }

    // ── טיפוגרפיה ───────────────────────────────────────────
    fonts() {
      const out = new Map();
      for (const s of this.sheets().values()) {
        const tx = s.doc.text();
        for (const m of tx.matchAll(/@font-face\s*\{[^}]*font-family\s*:\s*(['"]?)([^;'"}]+)\1/gi)) out.set(m[2].trim(), { weights: null, page: true });
        for (const m of tx.matchAll(/font-family\s*:\s*([^;}]+)/gi)) { const f = m[1].split(',')[0].trim().replace(/^['"]|['"]$/g, ''); if (f && !/var\(/.test(f) && !out.has(f)) out.set(f, { weights: null, page: true }); }
      }
      const html = this.canvas.m.src;
      for (const m of html.matchAll(/fonts\.googleapis\.com\/css2?\?([^"'\s>]+)/g)) {
        for (const fam of m[1].replace(/&amp;/g, '&').split('&').filter(x => /^family=/.test(x))) {
          const [name, axes] = decodeURIComponent(fam.slice(7).replace(/\+/g, ' ')).split(':');
          const w = axes && /wght@([\d;.,]+)/.exec(axes); out.set(name.trim(), { weights: w ? w[1].split(/[;,]/).map(x => x.split('..')[0]).filter(Boolean) : null, page: true, google: true });
        }
      }
      return out;
    }
    /** V9: גופן משתנה — מחוון בטווח שלו (font-weight רציף). */
    weightRange(n, [lo, hi], fam) {
      const row = h('div', { class: 'sg-field', 'data-field': 'font-weight', 'data-weights-of': fam }), { el: src, s } = this.srcLine(n, 'font-weight');
      const cur = parseInt((s.winner ? s.declared : '') || s.value, 10) || 400;
      const r = h('input', { type: 'range', min: lo, max: hi, step: 1, value: Math.min(hi, Math.max(lo, cur)), 'aria-label': 'משקל — ' + fam + ' (גופן משתנה ' + lo + '–' + hi + ')', 'data-weight-range': true, disabled: !this.canWrite() || null });
      const out = h('output', { dir: 'ltr' }, String(r.value));
      r.addEventListener('input', () => { out.textContent = r.value; this.preview(this.nodes(), [['font-weight', r.value]]); });
      r.addEventListener('change', () => this.put([['font-weight', r.value]], 'משקל · ' + this.canvas.label(n) + ' · ' + cur + ' ← ' + r.value));
      row.append(h('div', { class: 'sg-line' }, h('span', { class: 'sg-label' }, 'משקל'), h('span', { class: 'sg-ctl wide' }, r, out), this.resetBtn(n, 'font-weight', 'משקל')), src);
      src.append(h('span', { class: 'hint' }, ' · ' + fam + ': גופן משתנה ' + lo + '–' + hi));
      return row;
    }
    drawType(n) {
      const sec = h('section', { class: 'sg-sec', 'data-sec': 'type' }, h('h3', { class: 'dt-h' }, 'טיפוגרפיה'));
      const fonts = this.fonts(), fam = this.styler.source(n, 'font-family', this.info), curFam = (fam.winner ? fam.declared : fam.value) || '';
      // גופן: מהגופנים שבפרויקט (קובץ, Google, בשימוש) ומגופני מערכת; הקלדה חופשית; "＋ הוסף גופן" (V9)
      const row = h('div', { class: 'sg-field', 'data-field': 'font-family' }), { el: src } = this.srcLine(n, 'font-family');
      const gf = this.glob ? [...this.glob.fonts().values()] : [];
      const dl = h('datalist', { id: 'sg-fonts' }, gf.length ? gf.map(f => h('option', { value: "'" + f.name + "'" }, f.name + ' · ' + ({ file: 'קובץ', google: 'Google', page: 'בעמוד' }[f.src]))) : [...fonts.keys()].map(f => h('option', { value: "'" + f + "'" }, f + ' · בעמוד')), SYSTEM_FONTS.map(f => h('option', { value: f }, f + ' · מערכת')));
      const inp = h('input', { type: 'text', dir: 'ltr', list: 'sg-fonts', value: curFam, 'aria-label': 'גופן', spellcheck: 'false', disabled: !this.canWrite() || null });
      const write = v => this.put([['font-family', v]], 'גופן · ' + this.canvas.label(n) + ' · ' + (curFam || '—') + ' ← ' + v);
      inp.addEventListener('change', () => {
        let v = inp.value.trim();
        const one = window.KolGlobals && window.KolGlobals.families(v);                 // שם אחד מהרשימה ← עם גופן גיבוי (או var של המשתנה שלו)
        if (this.glob && one && one.length === 1 && !/var\(/.test(v) && gf.some(f => f.name.toLowerCase() === one[0].toLowerCase())) { try { v = this.glob.familyFor(one[0]); } catch { /* נבדק למטה */ } }
        if (!v || !valid('font-family', v)) { inp.classList.add('bad'); return; } inp.classList.remove('bad'); write(v);
      });
      const add = this.glob ? h('button', { type: 'button', class: 'btn sm', 'data-font-add-here': true, title: 'הוסף גופן — קובץ מהמחשב או Google Fonts — ושים אותו כאן', disabled: !this.canWrite() || null, onclick: async () => { const fam = await this.glob.addFont(); if (fam) write(this.glob.familyFor(fam)); } }, '＋') : null;
      row.append(h('div', { class: 'sg-line' }, h('span', { class: 'sg-label' }, 'גופן'), h('span', { class: 'sg-ctl wide' }, inp, dl, add), this.resetBtn(n, 'font-family', 'גופן')), src);
      sec.append(row);
      sec.append(this.num('font-size', 'גודל', { group: 'גודל גופן', units: ['pt'] }));
      // משקל: רק מה שהגופן מספק (V9) — @font-face בפרויקט / ה-<link> של Google; גופן משתנה — מחוון רציף בטווח שלו
      const first = (window.KolGlobals ? window.KolGlobals.families(this.styler.computed(n, 'font-family'))[0] : curFam.split(',')[0].trim().replace(/^['"]|['"]$/g, '')) || '';
      const wi = this.glob ? this.glob.weightsOf(first) : null, fi = fonts.get(first);
      const ws = wi && wi.weights && wi.weights.length ? wi.weights : fi && fi.weights ? fi.weights : ['100', '200', '300', '400', '500', '600', '700', '800', '900'];
      if (wi && wi.range) sec.append(this.weightRange(n, wi.range, first));
      else sec.append(this.choice('font-weight', 'משקל', ws.map(w => [w, w + ({ 400: ' · רגיל', 700: ' · מודגש' }[w] || '')]).concat([['normal', 'normal'], ['bold', 'bold']])));
      if (wi && wi.weights) { const last = sec.lastChild; last.dataset.weightsOf = first; last.querySelector('.sg-src')?.append(h('span', { class: 'hint', 'data-weights-hint': true }, ' · משקלים של ' + first + ': ' + ws.join(', '))); }
      sec.append(this.seg('font-style', 'נטוי', [['normal', 'A', 'רגיל'], ['italic', '𝐼', 'נטוי']]));
      sec.append(this.seg('text-decoration-line', 'קו', [['none', '∅', 'בלי קו'], ['underline', 'U̲', 'קו תחתון'], ['overline', 'Ō', 'קו עליון'], ['line-through', 'S̶', 'קו חוצה']]));
      sec.append(this.choice('text-decoration-style', 'סגנון הקו', [['solid', 'רציף'], ['double', 'כפול'], ['dotted', 'נקודות'], ['dashed', 'מקווקו'], ['wavy', 'גלי']]));
      sec.append(this.colorField(n, 'text-decoration-color', 'צבע הקו'));
      sec.append(this.choice('text-transform', 'רישיות', [['none', 'כמו שנכתב'], ['uppercase', 'אותיות גדולות'], ['lowercase', 'אותיות קטנות'], ['capitalize', 'גדולה בתחילת מילה']]));
      sec.append(this.num('line-height', 'גובה שורה', { unitless: true, step: 0.05, units: [] }));
      sec.append(this.num('letter-spacing', 'ריווח אותיות', { step: 0.5 }));
      sec.append(this.num('word-spacing', 'ריווח מילים', {}));
      sec.append(this.seg('text-align', 'יישור', [['start', '⇤', 'לתחילת השורה (start)'], ['center', '↔', 'למרכז'], ['end', '⇥', 'לסוף השורה (end)'], ['justify', '☰', 'לשני הצדדים'], ['right', 'R', 'לימין'], ['left', 'L', 'לשמאל']]));
      sec.append(this.choice('direction', 'כיוון', [['rtl', 'מימין לשמאל (rtl)'], ['ltr', 'משמאל לימין (ltr)']]));
      return [sec];
    }

    // ── צבע ─────────────────────────────────────────────────
    /** "צבעים בעמוד": כל הצבעים שבקובצי ה-CSS, ומשתני הצבע (--…). */
    pageColors() {
      const cols = new Map(), vars = new Map();
      for (const s of this.sheets().values()) {
        const tx = s.doc.text();
        for (const m of tx.matchAll(/(--[\w-]+)\s*:\s*([^;}]+)/g)) { const v = m[2].trim(); if (rgba(v)) vars.set(m[1], v); }
        for (const m of tx.matchAll(/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/gi)) if (rgba(m[0]) && !/var\(/.test(m[0])) cols.set(m[0].toLowerCase(), true);
      }
      return { cols: [...cols.keys()].slice(0, 40), vars: [...vars.entries()].slice(0, 40) };
    }
    colorField(n, phys, label) {
      const row = h('div', { class: 'sg-field', 'data-field': phys }), { el: src, s } = this.srcLine(n, phys);
      const cur = (s.winner ? s.declared : '') || s.value || '';
      const sw = h('button', { type: 'button', class: 'sg-swatch', 'aria-label': 'בוחר צבע — ' + label, 'data-color': phys, disabled: !this.canWrite() || null });
      sw.style.setProperty('--c', s.value || 'transparent');
      const inp = h('input', { type: 'text', dir: 'ltr', value: cur, 'aria-label': label, spellcheck: 'false', disabled: !this.canWrite() || null });
      const put = v => { this.put([[phys, v]], label + ' · ' + this.canvas.label(n) + ' · ' + (cur || '—') + ' ← ' + v); };
      inp.addEventListener('change', () => { const v = inp.value.trim(); if (!valid(phys, v)) { inp.classList.add('bad'); inp.setAttribute('aria-invalid', 'true'); return; } put(v); });
      sw.addEventListener('click', () => this.picker(row, phys, label, s.value, put));
      row.append(h('div', { class: 'sg-line' }, h('span', { class: 'sg-label' }, label), h('span', { class: 'sg-ctl wide' }, sw, inp), this.resetBtn(n, phys, label)), src);
      return row;
    }
    /** בוחר הצבע: מרחב (רוויה / בהירות), גוון, שקיפות, הקלדה בכל פורמט, טפטפת, "צבעים בעמוד". */
    picker(row, phys, label, value, put) {
      const old = this.host.querySelector('.sg-pick'); if (old) { const same = old.dataset.for === phys; old.remove(); if (same) return; }
      const c0 = rgba(value) || [0, 0, 0, 1]; let [hh, sat, val] = rgb2hsv(c0[0], c0[1], c0[2]), a = c0[3];
      const box = h('div', { class: 'sg-pick', 'data-for': phys, role: 'dialog', 'aria-label': 'בוחר צבע — ' + label });
      const sv = h('div', { class: 'sg-sv', 'aria-label': 'רוויה ובהירות', role: 'slider', tabindex: '0' }), dot = h('span', { class: 'sg-dot' }); sv.append(dot);
      const hue = h('input', { type: 'range', min: 0, max: 360, value: Math.round(hh), 'aria-label': 'גוון', class: 'sg-hue' });
      const alpha = h('input', { type: 'range', min: 0, max: 100, value: Math.round(a * 100), 'aria-label': 'שקיפות', class: 'sg-alpha', 'data-alpha': true });
      const txt = h('input', { type: 'text', dir: 'ltr', 'aria-label': 'צבע — כל פורמט (#hex, rgb, hsl, שם)', spellcheck: 'false', 'data-pick-text': true });
      const cur = () => { const [r, g2, b] = hsv2rgb(hh, sat, val); return toCss([r, g2, b, a]); };
      const paint = () => { sv.style.background = 'linear-gradient(to top, #000, transparent), linear-gradient(to left, hsl(' + hh + ' 100% 50%), #fff)'; dot.style.cssText = 'right:' + ((1 - sat) * 100) + '%;top:' + ((1 - val) * 100) + '%'; txt.value = cur(); alpha.style.setProperty('--c', toCss(hsv2rgb(hh, sat, val).concat(1))); };
      const live = () => { paint(); this.preview(this.nodes(), [[phys, cur()]]); };
      const done = () => put(cur());
      sv.addEventListener('pointerdown', e => {
        e.preventDefault(); sv.setPointerCapture(e.pointerId);
        const at = ev => { const r = sv.getBoundingClientRect(); sat = Math.min(1, Math.max(0, 1 - (r.right - ev.clientX) / r.width)); val = Math.min(1, Math.max(0, 1 - (ev.clientY - r.top) / r.height)); live(); };
        at(e); const up = () => { sv.removeEventListener('pointermove', at); sv.removeEventListener('pointerup', up); done(); };
        sv.addEventListener('pointermove', at); sv.addEventListener('pointerup', up);
      });
      hue.addEventListener('input', () => { hh = +hue.value; live(); }); hue.addEventListener('change', done);
      alpha.addEventListener('input', () => { a = +alpha.value / 100; live(); }); alpha.addEventListener('change', done);
      txt.addEventListener('change', () => { const v = txt.value.trim(); if (!valid(phys, v)) { txt.classList.add('bad'); return; } txt.classList.remove('bad'); put(v); });
      const tools = h('div', { class: 'sg-pick-tools' }, txt);
      if (window.EyeDropper) tools.append(h('button', { type: 'button', class: 'btn sm', 'aria-label': 'טפטפת — צבע מהמסך', onclick: async () => { try { const r = await new window.EyeDropper().open(); put(r.sRGBHex); } catch { /* בוטל */ } } }, '💧'));
      const pc = this.pageColors(), swatches = h('div', { class: 'sg-page-colors', 'data-page-colors': true });
      for (const [name, v] of pc.vars) { const b = h('button', { type: 'button', class: 'sg-chip var', title: name + ': ' + v, 'data-var': name, onclick: () => put('var(' + name + ')') }, h('i', {}), h('span', { dir: 'ltr' }, name)); b.firstChild.style.background = v; swatches.append(b); }
      for (const v of pc.cols) { const b = h('button', { type: 'button', class: 'sg-chip', title: v, 'aria-label': v, 'data-col': v, onclick: () => put(v) }, h('i', {})); b.firstChild.style.background = v; swatches.append(b); }
      box.append(sv, hue, alpha, tools, h('h4', { class: 'sg-h4' }, 'צבעים בעמוד' + (pc.vars.length ? ' · משתנים (var)' : '')), swatches.childNodes.length ? swatches : h('p', { class: 'hint' }, 'אין עדיין צבעים בקובצי ה-CSS.'));
      row.append(box); paint();
    }
    drawColor(n) {
      const sec = h('section', { class: 'sg-sec', 'data-sec': 'color' }, h('h3', { class: 'dt-h' }, 'צבע'));
      sec.append(this.colorField(n, 'color', 'צבע טקסט'), this.colorField(n, 'background-color', 'צבע רקע'));
      const e = this.canvas.elOf(n), k = e ? contrast(this.canvas.frame.contentWindow, e) : null, hasText = e && /\S/.test(e.textContent || '');
      if (k && hasText) {
        if (k.image) sec.append(h('p', { class: 'hint sg-contrast', 'data-contrast': 'image' }, 'ניגודיות: מתחת לטקסט יש תמונת רקע — היחס אינו מחושב. בודקים בעין.'));
        else sec.append(h('p', { class: 'sg-contrast' + (k.ratio < 4.5 ? ' low' : ''), 'data-contrast': round(k.ratio) }, 'ניגודיות טקסט / רקע: ' + k.ratio.toFixed(2) + ':1' + (k.ratio < 4.5 ? ' — ⚠️ נמוכה מ-4.5 (קשה לקריאה)' : ' ✓')));
      }
      const bg = this.bgSection ? this.bgSection(n) : null;                     // V5: background.js — "רקע"
      return bg ? [sec, bg] : [sec];
    }

    // ── מרווחים ─────────────────────────────────────────────
    drawSpacing(n) {
      const e = this.canvas.elOf(n), win = this.canvas.frame.contentWindow, cs = win.getComputedStyle(e), out = [];
      const sec = h('section', { class: 'sg-sec', 'data-sec': 'space' }, h('h3', { class: 'dt-h' }, 'מרווחים'));
      const box = h('div', { class: 'sg-box', 'data-box': true });
      const side = (kind, s) => {
        const phys = kind + '-' + s, f = this.num(phys, { top: 'עליון', bottom: 'תחתון', right: 'ימין', left: 'שמאל' }[s], { group: kind === 'margin' ? 'שוליים' : 'ריפוד', hint: true });
        f.classList.add('sg-side', 's-' + s);
        if (kind === 'margin' && this.pickSide) f.addEventListener('pointerdown', () => this.pickSide(s), true);   // V4: Alt + חיצים משנים שוליים רק כשצד נבחר כאן
        return f;
      };
      const lockBtn = kind => {
        const L = this.lock[kind], next = { none: 'pairs', pairs: 'all', all: 'none' }[L];
        return h('button', { type: 'button', class: 'btn sm sg-lock', 'data-lock': kind, 'aria-label': 'נעילת צדדים — ' + (kind === 'margin' ? 'שוליים' : 'ריפוד') + ': ' + { none: 'כל צד לחוד', pairs: 'זוגות', all: 'הכול' }[L],
          onclick: () => { this.lock[kind] = next; this.draw(); } }, { none: '🔓 כל צד', pairs: '⇅⇆ זוגות', all: '🔒 הכול' }[L]);
      };
      const margin = h('div', { class: 'sg-m' }, h('span', { class: 'sg-cap' }, 'שוליים (חוץ)', lockBtn('margin')), side('margin', 'top'), side('margin', 'right'), side('margin', 'left'), side('margin', 'bottom'));
      const pad = h('div', { class: 'sg-p' }, h('span', { class: 'sg-cap' }, 'ריפוד (פנים)', lockBtn('padding')), side('padding', 'top'), side('padding', 'right'), side('padding', 'left'), side('padding', 'bottom'),
        h('span', { class: 'sg-core', dir: 'ltr' }, Math.round(e.getBoundingClientRect().width) + ' × ' + Math.round(e.getBoundingClientRect().height)));
      margin.append(pad); box.append(margin); sec.append(box);
      sec.append(h('p', { class: 'hint' }, 'ערך שלילי מותר בשוליים; auto — מהרשימה. גרירה על הקנבס: ידיות בשפות האזורים ובין אחים.'));
      out.push(sec);
      if (/flex|grid/.test(cs.display)) {
        const gs = h('section', { class: 'sg-sec', 'data-sec': 'gap' }, h('h3', { class: 'dt-h' }, 'רווח בין הילדים (gap) · ' + cs.display));
        gs.append(this.num('row-gap', 'בין שורות', { group: 'gap' }), this.num('column-gap', 'בין עמודות', { group: 'gap' }));
        out.push(gs);
      }
      const why = this.whyGap ? this.whyGap(n) : null; if (why) out.push(why);
      return out;
    }
  }
  /** נעילת צדדים: איזה צדדים משתנים יחד עם הצד שנערך. */
  function linked(phys, mode) {
    const m = /^(margin|padding)-(top|right|bottom|left)$/.exec(phys); if (!m || mode === 'none') return [phys];
    if (mode === 'all') return ['top', 'right', 'bottom', 'left'].map(s => m[1] + '-' + s);
    return [phys, m[1] + '-' + { top: 'bottom', bottom: 'top', left: 'right', right: 'left' }[m[2]]];
  }
  window.KolDesign = { Design, rgba, toCss, contrast, linked, valid };
})();
