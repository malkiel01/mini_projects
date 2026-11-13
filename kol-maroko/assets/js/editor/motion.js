/**
 * העורך החזותי — תנועה (פרוסה V10, 03 §4.3; 01-gap 4.10–4.13, 3.4): transform, מעבר (עם עורך cubic-bezier), אנימציית כניסה,
 * אפקטי גלילה ועכבר, נדבק (sticky), וידאו ברקע ומצגת רקע; ידיות סיבוב, נקודת ציר וקנה מידה (Ctrl על ידיות הגודל של V6);
 * "▶ הפעל אנימציות" בקנבס (מושהות כברירת מחדל — CSS של העורך, לא של הקובץ).
 *
 * מה נכתב לקבצים: transform / transition-* / position:sticky — לכלל היעד (כל שליטה עוברת Design.put: מכשיר, מצב, ⟲, Ctrl+Z).
 * אנימציית כניסה — מחלקה על האלמנט (kol-in kol-in-<שם>) + --kol-in-dur / --kol-in-delay / --kol-in-ease בכלל היעד, ו-@keyframes
 * ב-css/motion.css (קובץ הפרויקט; נוצר בשימוש הראשון ומקושר ל-<head>). גלילה — kol-sc + --kol-sc-*. מה שדורש JS — js/kol-motion.js
 * (קובץ מערכת, נוצר רק כשנבחר "כשנראה במסך" או אפקט עכבר; kol.json "system"). כל ערך — checkFx + checkValue.
 */
(function (g) {
  'use strict';
  const hh = (...a) => g.h(...a), FX = () => g.KolFx, K = () => g.KolSrc;
  const MOTION_CSS = 'css/motion.css', MOTION_JS = 'js/kol-motion.js';
  const clsOf = n => ((K().attrOf(n, 'class') || {}).value || '').split(/\s+/).filter(Boolean);
  const r2 = x => Math.round(x * 100) / 100;
  const tick = () => new Promise(r => setTimeout(r, 80));
  const RANGES = [['cover 0%', 'נכנס מלמטה'], ['entry 100%', 'נכנס כולו'], ['contain 0%', 'כולו במסך'], ['cover 50%', 'באמצע המסך'], ['exit 0%', 'מתחיל לצאת'], ['cover 100%', 'יצא למעלה']];

  class Motion {
    /** o: { design, adv, write, writeOps, joinTx, attachFx, txCount(), note, undo, phone, layer(), busy(), on(), html(), docOf(path), sheets(), linkSheet(path),
     *       files(), reload(), filePath(), pick(kind, title), seedUrl(name), api, bar } */
    constructor(o) { Object.assign(this, o); this.open = new Set(); this.scrub = 0.5; }
    get A() { return this.adv; } get c() { return this.adv.canvas; }
    win() { return this.c.frame.contentWindow; }
    cs(n) { const e = this.c.elOf(n); return e ? this.win().getComputedStyle(e) : null; }
    decl(D, n, phys) { const s = D.styler.source(n, phys, D.info); return s.winner ? String(s.declared) : ''; }
    put(D, sets, label, opt) { try { FX().checkSets(sets, opt); } catch (e) { this.note(e.message, true); return null; } return D.put(sets, label + ' · ' + this.c.label(D.n), opt); }
    group(id, title, body) {
      const d = hh('details', { class: 'sg-sec sg-fx', 'data-sec': id, open: this.open.has(id) || null }, hh('summary', { class: 'dt-h' }, title));
      const fill = () => { if (d.children.length === 1) d.append(...body().filter(Boolean)); };
      d.addEventListener('toggle', () => { if (d.open) { this.open.add(id); fill(); } else this.open.delete(id); this.drawHandles(); });
      if (d.open) fill();
      return d;
    }
    /** קבוצות "מתקדם". */
    sections(n) {
      const e = this.c.elOf(n); if (!e || /^(html|head)$/.test(n.tag)) return [];
      const box = e.children.length && !/^(p|h[1-6]|a|span|button|li|img|video|svg|body)$/.test(n.tag);
      return [this.group('transform', 'Transform — הזזה, סיבוב, קנה מידה', () => this.transform(n)), this.group('entrance', 'אנימציית כניסה', () => this.entrance(n)),
        this.group('scroll', 'אפקטי גלילה ועכבר', () => this.scroll(n)), this.group('sticky', 'נדבק (sticky)', () => this.sticky(n)),
        box ? this.group('bgvideo', 'וידאו ומצגת ברקע', () => this.bgMedia(n)) : null].filter(Boolean);
    }

    // ── Transform ──
    transform(n) {
      const D = this.A, w = D.canWrite(), cur = this.decl(D, n, 'transform'), t = FX().parseTransform(cur);
      const out = [hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'transform'), D.resetBtn(n, 'transform', 'transform')), D.srcLine(n, 'transform').el];
      if (!t) { out.push(hh('p', { class: 'hint', 'data-transform-raw': true }, 'הערך מורכב (' + cur + ') — נערך ב"מאפיינים נוספים" ובקוד; הפאנל אינו דורס אותו.')); return out; }
      const save = (k, v) => { const nt = Object.assign({}, t); if (k === 'scale') { nt.sx = v; nt.sy = v; } else nt[k] = v; const jv = FX().joinTransform(nt); this.put(D, [['transform', jv === 'none' ? null : jv]], 'Transform · ' + k + ' ← ' + v); };
      const f = (k, lab, unit) => { const inp = hh('input', { type: 'text', dir: 'ltr', value: t[k], 'aria-label': lab, 'data-tf': k, disabled: !w || null, spellcheck: 'false' }); inp.addEventListener('change', () => { let v = inp.value.trim(); if (/^-?[\d.]+$/.test(v) && unit) v += unit; save(k, v || (unit === '' ? '1' : '0' + unit)); }); return hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, lab), hh('span', { class: 'sg-ctl wide' }, inp)); };
      out.push(f('tx', 'הזזה X', 'px'), f('ty', 'הזזה Y', 'px'), f('rot', 'סיבוב', 'deg'), f('sx', 'קנה מידה X', ''), f('sy', 'קנה מידה Y', ''), f('kx', 'הטיה X', 'deg'), f('ky', 'הטיה Y', 'deg'));
      out.push(hh('div', { class: 'sg-bgacts' },
        hh('button', { type: 'button', class: 'btn sm', 'data-tf-lock': true, title: 'קנה מידה אחיד: X = Y', disabled: !w || null, onclick: () => save('scale', t.sx) }, '🔗 X = Y'),
        hh('button', { type: 'button', class: 'btn sm', 'data-tf-flip': 'h', disabled: !w || null, onclick: () => save('sx', String(-(+t.sx || 1))) }, '⇋ היפוך אופקי'),
        hh('button', { type: 'button', class: 'btn sm', 'data-tf-flip': 'v', disabled: !w || null, onclick: () => save('sy', String(-(+t.sy || 1))) }, '⇅ היפוך אנכי')));
      // נקודת ציר: רשת 3×3 + הקלדה
      const og = this.decl(D, n, 'transform-origin'), grid = hh('div', { class: 'tf-origin', role: 'group', 'aria-label': 'נקודת ציר' });
      for (const y of ['top', 'center', 'bottom']) for (const x of ['left', 'center', 'right']) { const v = x === 'center' && y === 'center' ? 'center' : x + ' ' + y; grid.append(hh('button', { type: 'button', 'data-origin': v, 'aria-pressed': String(og === v), 'aria-label': 'נקודת ציר ' + v, disabled: !w || null, onclick: () => this.put(D, [['transform-origin', v]], 'נקודת ציר ← ' + v) }, '·')); }
      out.push(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'נקודת ציר'), grid), D.num('transform-origin', 'ציר (הקלדה)', { units: [] }));
      out.push(hh('p', { class: 'hint' }, 'על הקנבס: ⟳ מעל האלמנט — סיבוב (Shift: 15°); ⊕ — נקודת הציר; Ctrl + ידית פינה — קנה מידה. במצב ריחוף — נכתב למצב.'));
      return out;
    }

    // ── מעבר (בלשונית "עיצוב") — מחליף את drawMotion של V8, עם עורך עקומה גרפי ──
    transition(n, D) {
      const sec = hh('section', { class: 'sg-sec', 'data-sec': 'motion' }, hh('h3', { class: 'dt-h' }, 'תנועה — מעבר בין מצבים'));
      sec.append(D.choice('transition-property', 'מאפיין', [['all', 'הכול'], ['color', 'צבע'], ['background-color', 'צבע רקע'], ['opacity', 'שקיפות'], ['transform', 'transform'], ['box-shadow', 'צל'], ['opacity, transform', 'שקיפות + transform'], ['none', 'בלי מעבר']]));
      const list = hh('input', { type: 'text', dir: 'ltr', placeholder: 'color, transform', 'aria-label': 'רשימת מאפיינים (בפסיקים)', 'data-tr-list': true, disabled: !D.canWrite() || null, spellcheck: 'false' });
      list.addEventListener('change', () => { const v = list.value.trim(); if (v) this.put(D, [['transition-property', v]], 'מעבר · מאפיינים ← ' + v); });
      sec.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'רשימה'), hh('span', { class: 'sg-ctl wide' }, list)));
      sec.append(D.num('transition-duration', 'משך', { units: ['s', 'ms'], step: 0.05, unitless: false }), D.num('transition-delay', 'השהיה', { units: ['s', 'ms'], step: 0.05 }));
      sec.append(D.choice('transition-timing-function', 'עקומה', [['ease', 'ease'], ['linear', 'linear'], ['ease-in', 'ease-in'], ['ease-out', 'ease-out'], ['ease-in-out', 'ease-in-out'], ['steps(4, end)', 'steps(4)'], ['cubic-bezier(.2, .8, .2, 1)', 'cubic-bezier(.2,.8,.2,1)']]));
      sec.append(this.bezier(n, D, 'transition-timing-function', 'עקומה'));
      sec.append(hh('p', { class: 'hint' }, 'נכתב לכלל הרגיל (לא למצב) — כך המעבר חל בכניסה וביציאה.'));
      return sec;
    }
    /** עורך cubic-bezier: ריבוע, שתי ידיות גרירה (P1, P2); שחרור ← כתיבה. y חורג (−0.5…1.5) מותר. */
    bezier(n, D, phys, label, write) {
      const cur = this.decl(D, n, phys) || (this.cs(n) || {}).transitionTimingFunction || 'ease', p = FX().bezierOf(String(cur).split(',')[0].trim()) || [0.25, 0.1, 0.25, 1], W = 160, H = 160, pad = 28;
      const NS = 'http://www.w3.org/2000/svg', mk = (t, a) => { const e = document.createElementNS(NS, t); for (const [k, v] of Object.entries(a)) e.setAttribute(k, v); return e; };
      const svg = mk('svg', { viewBox: '0 0 ' + W + ' ' + (H + pad * 2), class: 'bz', 'data-bezier': phys, role: 'img', 'aria-label': 'עקומה ' + cur });
      const X = x => x * W, Y = y => pad + (1 - y) * H, path = mk('path', { class: 'bz-c' }), l1 = mk('line', { class: 'bz-l' }), l2 = mk('line', { class: 'bz-l' });
      const h1 = mk('circle', { r: 7, class: 'bz-h', 'data-bz': '1' }), h2 = mk('circle', { r: 7, class: 'bz-h', 'data-bz': '2' });
      svg.append(mk('rect', { x: 0, y: pad, width: W, height: H, class: 'bz-box' }), l1, l2, path, h1, h2);
      const val = hh('span', { class: 'fx-val', dir: 'ltr', 'data-bz-val': true });
      const draw = () => { path.setAttribute('d', 'M' + X(0) + ' ' + Y(0) + ' C' + X(p[0]) + ' ' + Y(p[1]) + ' ' + X(p[2]) + ' ' + Y(p[3]) + ' ' + X(1) + ' ' + Y(1)); for (const [l, h, a, b, ox, oy] of [[l1, h1, p[0], p[1], 0, 0], [l2, h2, p[2], p[3], 1, 1]]) { l.setAttribute('x1', X(ox)); l.setAttribute('y1', Y(oy)); l.setAttribute('x2', X(a)); l.setAttribute('y2', Y(b)); h.setAttribute('cx', X(a)); h.setAttribute('cy', Y(b)); } val.textContent = FX().bezierCss(p); };
      draw();
      const drag = (hEl, i) => hEl.addEventListener('pointerdown', ev => {
        if (!D.canWrite()) return; ev.preventDefault(); try { hEl.setPointerCapture(ev.pointerId); } catch { /* */ }
        const R = svg.getBoundingClientRect(), sx = (W) / R.width, sy = (H + pad * 2) / R.height;
        const mv = e2 => { p[i] = r2(Math.max(0, Math.min(1, (e2.clientX - R.left) * sx / W))); p[i + 1] = r2(Math.max(-0.5, Math.min(1.5, 1 - ((e2.clientY - R.top) * sy - pad) / H))); draw(); D.preview(D.nodes(), [[phys, FX().bezierCss(p)]]); };
        const up = () => { hEl.removeEventListener('pointermove', mv); hEl.removeEventListener('pointerup', up); hEl.removeEventListener('pointercancel', up); const v = FX().bezierCss(p); if (write) write(v); else this.put(D, [[phys, v]], label + ' ← ' + v); };
        hEl.addEventListener('pointermove', mv); hEl.addEventListener('pointerup', up); hEl.addEventListener('pointercancel', up);
      });
      drag(h1, 0); drag(h2, 2);
      return hh('div', { class: 'sg-field bz-wrap' }, hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'עורך עקומה'), val), svg);
    }

    // ── אנימציית כניסה ──
    inOf(n) { const c = clsOf(n), id = (c.find(x => /^kol-in-[a-z-]+$/.test(x)) || '').slice(7); return c.includes('kol-in') ? id || 'fade' : ''; }
    entrance(n) {
      const D = this.A, w = D.canWrite(), id = this.inOf(n), mode = ((K().attrOf(n, 'data-kol-in') || {}).value) || 'load';
      const sel = hh('select', { 'aria-label': 'אנימציית כניסה', 'data-in': true, disabled: !w || null }, hh('option', { value: '' }, 'בלי'), FX().ENTRANCES.map(([k, l]) => hh('option', { value: k, selected: k === id || null }, l)));
      sel.addEventListener('change', () => this.setEntrance(n, sel.value, mode));
      const out = [hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'אנימציה'), hh('span', { class: 'sg-ctl wide' }, sel))];
      if (!id) { out.push(hh('p', { class: 'hint' }, 'נכתב: מחלקה kol-in על האלמנט ו-@keyframes ב-css/motion.css (נוצר בשימוש הראשון).'), hh('div', { class: 'sg-bgacts' }, this.playPanel())); return out; }
      out.push(hh('div', { class: 'sg-field' }, hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'מתי'), hh('span', { class: 'sg-seg', role: 'group', 'aria-label': 'מתי' }, [['load', 'בטעינה'], ['view', 'כשנראה — פעם אחת'], ['repeat', 'בכל כניסה']].map(([v, t]) => hh('button', { type: 'button', 'aria-pressed': String(v === mode), 'data-in-mode': v, disabled: !w || null, onclick: () => this.setEntrance(n, id, v) }, t))))));
      out.push(D.num('--kol-in-dur', 'משך', { units: ['s', 'ms'], step: 0.1 }), D.num('--kol-in-delay', 'השהיה', { units: ['s', 'ms'], step: 0.1 }));
      out.push(D.choice('--kol-in-ease', 'עקומה', [['ease', 'ease'], ['ease-out', 'ease-out'], ['ease-in-out', 'ease-in-out'], ['linear', 'linear'], ['cubic-bezier(.2, .8, .2, 1)', 'רכה']]));
      out.push(this.bezier(n, D, '--kol-in-ease', 'כניסה · עקומה'));
      out.push(hh('div', { class: 'sg-bgacts' }, hh('button', { type: 'button', class: 'btn sm', 'data-in-replay': true, onclick: () => this.replay(n) }, '↻ הצג שוב'), this.playPanel()));
      out.push(hh('p', { class: 'hint' }, mode === 'load' ? 'בטעינה — CSS בלבד.' : '"כשנראה במסך" — js/kol-motion.js (קובץ מערכת, גלוי). בלעדיו (או בלי JS) — האנימציה רצה בטעינה. מי שביקש פחות תנועה — רואה את המצב הסופי.'));
      return out;
    }
    /** מחלקות + data-kol-in ב-HTML, ו-motion.css (וקובץ המערכת כשצריך) — עסקה אחת. */
    async setEntrance(n, id, mode) {
      if (!this.A.canWrite()) return;
      const path = K().pathOf(n), html = this.html(), t0 = this.txCount();
      if (id && !await this.ensureCss(['base', 'in:' + id])) return;
      if (id && mode !== 'load' && !await this.ensureJs()) return;
      const ops = [() => { const m = this.c.m, at = K().byPath(m, path), ps = []; let cur = K().byPath(m, path); void cur;
        const cls = clsOf(at).filter(x => x !== 'kol-in' && !/^kol-in-/.test(x)); if (id) cls.push('kol-in', 'kol-in-' + id);
        return [{ doc: html, patches: (cls.length ? K().html.setAttr(m, at, 'class', cls.join(' ')) : K().html.removeAttr(m, at, 'class')).patches.concat(ps) }]; },
      () => { const m = this.c.m, at = K().byPath(m, path), has = K().attrOf(at, 'data-kol-in'), want = id && mode !== 'load' ? (mode === 'repeat' ? 'repeat' : 'view') : null; if ((has ? has.value : null) === want) return []; return [{ doc: html, patches: (want ? K().html.setAttr(m, at, 'data-kol-in', want) : K().html.removeAttr(m, at, 'data-kol-in')).patches }]; }];
      const r = this.writeOps(ops, 'אנימציית כניסה · ' + this.c.label(n) + ' · ' + (id ? FX().ENTRANCES.find(x => x[0] === id)[1] + ' · ' + { load: 'בטעינה', view: 'כשנראה', repeat: 'בכל כניסה' }[mode] : 'הסרה'));
      if (r == null) return;
      this.joinTx(this.txCount() - t0, 'אנימציית כניסה · ' + this.c.label(n));
      this.A.draw();
    }

    // ── קובצי הפרויקט: css/motion.css ו-js/kol-motion.js ──
    fileByPath(p) { return (this.files() || []).find(f => f.path === p && f.kind === 'file') || null; }
    sheetDoc(p) { for (const x of this.sheets().values()) if (x.path === p) return x.doc; return null; }
    /** יוצר קובץ בפרויקט (שרת) ורושם את הביטול שלו לעסקה האחרונה: ביטול ← לסל, החזרה ← שחזור. */
    async create(path, content) {
      const r = await this.api('pf_create', { path, content }); await this.reload();
      return r && r.file ? r.file.id : (this.fileByPath(path) || {}).id;
    }
    fxFor(id) { if (id) this.attachFx({ undo: () => this.api('pf_delete', { id }).then(() => this.reload()).catch(() => {}), redo: () => this.api('pf_restore', { ids: [id] }).then(() => this.reload()).catch(() => {}) }); }
    /** motion.css: קיים ומקושר ← רק מה שחסר; קיים בפרויקט ← קישור; חסר ← נוצר, מקושר, ונכתב. */
    async ensureCss(need) {
      let doc = this.sheetDoc(MOTION_CSS), made = null;
      try {
        if (!doc) {
          if (!this.fileByPath(MOTION_CSS)) made = await this.create(MOTION_CSS, '');
          if (!await this.linkSheet(MOTION_CSS)) return false;
          if (made) this.fxFor(made);
          doc = this.sheetDoc(MOTION_CSS); if (!doc) return false;
        }
        const ps = FX().planMotion(doc.text(), need);
        if (ps.length) { const r = this.writeOps([{ doc, patches: ps }], 'css/motion.css · ' + need.join(', ')); if (r == null) return false; }
        return true;
      } catch (e) { this.note('css/motion.css: ' + e.message, true); return false; }
    }
    /** js/kol-motion.js: נוצר מהעותק של הכלי, נרשם ב-kol.json (system), ו-<script defer> בעמוד. */
    async ensureJs() {
      try {
        const html = this.html(), has = /<script\b[^>]*\bsrc\s*=\s*["']?[^"'>]*kol-motion\.js/i.test(html.text());
        let made = null;
        if (!this.fileByPath(MOTION_JS)) {
          const r = await fetch(this.seedUrl('kol-motion.js'), { credentials: 'same-origin' }); if (!r.ok) throw new Error('העותק של הכלי לא נטען');
          made = await this.create(MOTION_JS, await r.text());
        }
        const ops = [];
        const kf = this.fileByPath('kol.json'), kd = kf && await this.openDoc(kf);
        if (kd && !kd.binary) ops.push(() => { const t = kd.text(), j = JSON.parse(t); if (j.system && j.system[MOTION_JS]) return []; return [{ doc: kd, patches: planSystem(t, !!this.fileByPath('js/kol-data.js')) }]; });
        if (!has) ops.push(() => { const m = this.c.m, head = m.root && m.root.kids.find(k => k.tag === 'head' && !k.implied); if (!head) throw new (K().SrcError)('לעמוד אין <head> כתוב — מוסיפים את js/kol-motion.js בקוד', 'broken'); return [{ doc: html, patches: K().html.insert(m, head, head.kids.length, '<script src="' + this.rel(MOTION_JS) + '" defer></script>').patches }]; });
        if (ops.length) { const r = this.writeOps(ops, 'js/kol-motion.js · קובץ מערכת'); if (r == null) return false; }
        if (made) this.fxFor(made);
        return true;
      } catch (e) { this.note('js/kol-motion.js: ' + e.message, true); return false; }
    }

    // ── אפקטי גלילה ועכבר ──
    scroll(n) {
      const D = this.A, w = D.canWrite(), on = clsOf(n).includes('kol-sc'), v = p => this.decl(D, n, p);
      const out = [hh('label', { class: 'sg-line' }, hh('input', { type: 'checkbox', checked: on || null, disabled: !w || null, 'data-sc-on': true, onchange: ev => this.setScroll(n, ev.target.checked) }), ' אפקט לפי מיקום האלמנט במסך (CSS בלבד)')];
      if (on) {
        const pair = (k, lab, unit, a, b, hint) => {
          const f = (i, lab2) => { const p = '--kol-sc-' + k + i, inp = hh('input', { type: 'text', dir: 'ltr', value: v(p), placeholder: i ? b : a, 'aria-label': lab + ' · ' + lab2, 'data-sc': k + i, disabled: !w || null, spellcheck: 'false' }); inp.addEventListener('change', () => { let x = inp.value.trim(); if (/^-?[\d.]+$/.test(x) && unit) x += unit; this.put(D, [[p, x || null]], 'גלילה · ' + lab + ' · ' + lab2 + ' ← ' + (x || 'איפוס')); }); return inp; };
          return hh('div', { class: 'sg-line', title: hint || '' }, hh('span', { class: 'sg-label' }, lab), hh('span', { class: 'sg-ctl wide' }, f(0, 'בהתחלה'), ' → ', f(1, 'בסוף')));
        };
        const sp = hh('input', { type: 'number', step: '0.1', min: '-3', max: '3', dir: 'ltr', value: String(r2((parseFloat(v('--kol-sc-y0')) || 0) / 100)), 'aria-label': 'parallax — מהירות', 'data-sc-parallax': true, disabled: !w || null });
        sp.addEventListener('change', () => { const s = +sp.value || 0; this.put(D, s ? [['--kol-sc-y0', r2(s * 100) + 'px'], ['--kol-sc-y1', r2(-s * 100) + 'px']] : [['--kol-sc-y0', null], ['--kol-sc-y1', null]], 'גלילה · parallax ' + s); });
        out.push(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'parallax (מהירות)'), hh('span', { class: 'sg-ctl wide' }, sp)),
          pair('o', 'שקיפות', '', '0', '1'), pair('b', 'טשטוש', 'px', '8px', '0px'), pair('x', 'הזזה X', 'px', '-60px', '0px'), pair('r', 'סיבוב', 'deg', '-10deg', '0deg'), pair('s', 'קנה מידה', '', '0.8', '1'));
        const rs = (p, lab) => { const cur = v(p); const s = hh('select', { 'aria-label': lab, 'data-sc-range': p, disabled: !w || null }, (cur && !RANGES.some(x => x[0] === cur) ? [[cur, cur]] : []).concat(RANGES).map(([x, t]) => hh('option', { value: x, selected: x === (cur || (p.endsWith('from') ? 'cover 0%' : 'cover 100%')) || null }, t + ' (' + x + ')'))); s.addEventListener('change', () => this.put(D, [[p, s.value]], 'גלילה · ' + lab + ' ← ' + s.value)); return hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, lab), hh('span', { class: 'sg-ctl wide' }, s)); };
        out.push(rs('--kol-sc-from', 'טווח · התחלה'), rs('--kol-sc-to', 'טווח · סוף'));
        const sim = hh('input', { type: 'range', min: '0', max: '1', step: '0.01', value: String(this.scrub), 'aria-label': 'מיקום גלילה מדומה', 'data-sc-sim': true });
        sim.addEventListener('input', () => { this.scrub = +sim.value; this.scrubAt(n, this.scrub); });
        out.push(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'מיקום גלילה מדומה'), hh('span', { class: 'sg-ctl wide' }, sim)));
        out.push(hh('p', { class: 'hint' }, 'animation-timeline: view() בתוך @supports — דפדפן בלי תמיכה: האלמנט סטטי. האפקט מחליף transform של האלמנט בזמן הגלילה.'));
      }
      const tilt = K().attrOf(n, 'data-kol-tilt'), fol = K().attrOf(n, 'data-kol-follow');
      out.push(hh('h4', { class: 'sg-sub' }, 'אפקטי עכבר (js/kol-motion.js)'),
        hh('label', { class: 'sg-line' }, hh('input', { type: 'checkbox', checked: tilt || null, disabled: !w || null, 'data-mouse': 'tilt', onchange: ev => this.setMouse(n, 'tilt', ev.target.checked) }), ' הטיה בתלת-ממד לפי העכבר'),
        hh('label', { class: 'sg-line' }, hh('input', { type: 'checkbox', checked: fol || null, disabled: !w || null, 'data-mouse': 'follow', onchange: ev => this.setMouse(n, 'follow', ev.target.checked) }), ' מעקב אחרי העכבר'));
      return out;
    }
    async setScroll(n, on) {
      const path = K().pathOf(n), html = this.html(), t0 = this.txCount();
      if (on && !await this.ensureCss(['scroll'])) return;
      const r = this.writeOps([() => { const m = this.c.m, at = K().byPath(m, path), cls = clsOf(at).filter(x => x !== 'kol-sc'); if (on) cls.push('kol-sc'); return [{ doc: html, patches: (cls.length ? K().html.setAttr(m, at, 'class', cls.join(' ')) : K().html.removeAttr(m, at, 'class')).patches }]; }], 'אפקט גלילה · ' + this.c.label(n) + ' · ' + (on ? 'הפעלה' : 'הסרה'));
      if (r == null) return;
      if (on) { this.ready(this.A, K().byPath(this.c.m, path)); this.put(this.A, [['--kol-sc-o0', '0'], ['--kol-sc-y0', '60px']], 'אפקט גלילה · ברירת מחדל: הופעה + עלייה'); }
      this.joinTx(this.txCount() - t0, 'אפקט גלילה · ' + this.c.label(n));
    }
    async setMouse(n, kind, on) {
      const path = K().pathOf(n), html = this.html(), t0 = this.txCount(), attr = 'data-kol-' + kind;
      if (on && !await this.ensureJs()) return;
      const r = this.writeOps([() => { const m = this.c.m, at = K().byPath(m, path); return [{ doc: html, patches: (on ? K().html.setAttr(m, at, attr, kind === 'tilt' ? '10' : '12') : K().html.removeAttr(m, at, attr)).patches }]; }], 'אפקט עכבר · ' + kind + ' · ' + this.c.label(n));
      if (r == null) return;
      if (on) { this.ready(this.A, K().byPath(this.c.m, path)); this.put(this.A, [['transform', kind === 'tilt' ? 'perspective(800px) rotateX(var(--kol-tilt-x, 0deg)) rotateY(var(--kol-tilt-y, 0deg))' : 'translate(var(--kol-follow-x, 0px), var(--kol-follow-y, 0px))'], ['transition', 'transform .15s ease-out']], 'אפקט עכבר · transform'); }
      this.joinTx(this.txCount() - t0, 'אפקט עכבר · ' + kind + ' · ' + this.c.label(n));
    }

    // ── נדבק ──
    sticky(n) {
      const D = this.A, s = this.cs(n), w = D.canWrite(), cur = s.position === 'sticky' ? (s.bottom !== 'auto' && s.top === 'auto' ? 'bottom' : 'top') : 'off';
      const out = [hh('div', { class: 'sg-field' }, hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'נדבק'), hh('span', { class: 'sg-seg', role: 'group', 'aria-label': 'נדבק' },
        [['off', 'לא'], ['top', '⤒ למעלה'], ['bottom', '⤓ למטה']].map(([v, t]) => hh('button', { type: 'button', 'aria-pressed': String(v === cur), 'data-sticky': v, disabled: !w || null, onclick: () => this.put(D, v === 'off' ? [['position', null], ['top', null], ['bottom', null]] : [['position', 'sticky'], [v, '0px'], [v === 'top' ? 'bottom' : 'top', null]], 'נדבק · ' + t) }, t)))))];
      if (cur !== 'off') out.push(D.num(cur, 'היסט', { group: 'נדבק' }), hh('p', { class: 'hint' }, 'נדבק כל עוד ההורה שלו על המסך — בסוף ההורה הוא ממשיך איתו. הקנבס מראה את זה בגלילה. (position: sticky לא עובד כשלהורה overflow: hidden.)'));
      return out;
    }

    // ── וידאו ומצגת ברקע ──
    bgMedia(n) {
      const e = this.c.elOf(n), w = this.A.canWrite(), vid = e.querySelector(':scope > video.bg-video'), sl = e.querySelector(':scope > .bg-slides');
      const out = [];
      if (vid) out.push(hh('p', { class: 'hint' }, '🎬 יש וידאו ברקע — נבחר ב-Navigator (הילד הראשון), ונערך כמו וידאו.'), hh('button', { type: 'button', class: 'btn sm', 'data-bgv-select': true, onclick: () => this.c.select(this.c.nodeOf(vid)) }, 'בחר את הווידאו'));
      else out.push(hh('label', { class: 'sg-line' }, hh('input', { type: 'checkbox', 'data-bgv-desk': true, checked: true }), ' רק במחשב (בטלפון — תמונת הפתיחה)'),
        hh('button', { type: 'button', class: 'btn sm', 'data-bgv-add': true, disabled: !w || null, onclick: ev => this.addVideo(n, ev.target.closest('details').querySelector('[data-bgv-desk]').checked) }, '＋ 🎬 וידאו ברקע'));
      if (sl) {
        const k = sl.children.length, mode = /kol-slides-slide/.test(sl.className) ? 'slide' : 'fade', kb = /-kb-/.test(sl.className);
        out.push(hh('h4', { class: 'sg-sub' }, 'מצגת · ' + k + ' תמונות'), hh('div', { class: 'sg-bgacts' },
          hh('button', { type: 'button', class: 'btn sm', 'data-sl-add': true, disabled: !w || k >= 10 || null, onclick: () => this.slideAdd(n) }, '＋ תמונה'),
          hh('button', { type: 'button', class: 'btn sm', 'data-sl-mode': mode === 'fade' ? 'slide' : 'fade', disabled: !w || null, onclick: () => this.slideCfg(n, k, mode === 'fade' ? 'slide' : 'fade', kb) }, mode === 'fade' ? 'מעבר: דהייה → החלקה' : 'מעבר: החלקה → דהייה'),
          hh('button', { type: 'button', class: 'btn sm', 'aria-pressed': String(kb), 'data-sl-kb': true, disabled: !w || mode === 'slide' || null, onclick: () => this.slideCfg(n, k, mode, !kb) }, 'קן ברנס')));
        const st = (K().attrOf(this.c.nodeOf(sl), 'style') || {}).value || '', d0 = (/--kol-slide-d:\s*([^;]+)/.exec(st) || [])[1] || '5s';
        const dur = hh('input', { type: 'text', dir: 'ltr', value: d0.trim(), 'aria-label': 'משך לכל תמונה', 'data-sl-dur': true, disabled: !w || null });
        dur.addEventListener('change', () => { let v = dur.value.trim(); if (/^[\d.]+$/.test(v)) v += 's'; try { FX().checkFx(v); if (!CSS.supports('animation-duration', v)) throw new Error('משך לא חוקי'); } catch (er) { this.note(er.message, true); return; } const p = K().pathOf(this.c.nodeOf(sl)), html = this.html(); this.writeOps([() => { const m = this.c.m, at = K().byPath(m, p); return [{ doc: html, patches: K().html.setAttr(m, at, 'style', '--kol-slide-d: ' + v).patches }]; }], 'מצגת · משך ← ' + v); });
        out.push(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'משך לכל תמונה'), hh('span', { class: 'sg-ctl wide' }, dur)), hh('p', { class: 'hint' }, 'כל תמונה — שכבה (div.bg-slide) שנבחרת ב-Navigator ונמחקת כמו כל אלמנט. CSS בלבד.'));
      } else out.push(hh('button', { type: 'button', class: 'btn sm', 'data-sl-new': true, disabled: !w || null, onclick: () => this.slidesNew(n) }, '＋ 🖼 מצגת רקע (2 תמונות ומעלה)'));
      return out;
    }
    rel(p) { return g.KolBg.relFrom(this.filePath(), p); }
    /** <video class="bg-video"> כילד ראשון + .bg-video ב-CSS + position:relative לרובריקה; "רק במחשב" — display:none ב-@media של הטלפון. */
    async addVideo(n, desk) {
      const src = await this.pick('video', 'וידאו לרקע'); if (!src) return;
      await tick();   // הדיאלוג הקודם נסגר (close אסינכרוני) — אחרת הבורר הבא נסגר איתו
      const poster = await this.pick('image', 'תמונת פתיחה (לא חובה — ביטול: בלי)');
      const esc = g.KolText.escAttr, path = K().pathOf(n), html = this.html(), t0 = this.txCount(), cssPath = this.pagePath(), cssDoc = cssPath && this.docOf(cssPath);
      try { g.KolBg.checkUrl(src); if (poster) g.KolBg.checkUrl(poster); } catch (e) { this.note(e.message, true); return; }
      const tag = '<video class="bg-video" src="' + esc(this.rel(src)) + '"' + (poster ? ' poster="' + esc(this.rel(poster)) + '"' : '') + ' autoplay muted loop playsinline aria-hidden="true"></video>';
      const ops = [() => { const m = this.c.m, at = K().byPath(m, path); return [{ doc: html, patches: K().html.insert(m, at, 0, tag).patches }]; }];
      if (cssDoc && !/\.bg-video\s*\{/.test(cssDoc.text())) ops.push(() => { const t = cssDoc.text(); return [{ doc: cssDoc, patches: [{ from: t.length, to: t.length, text: (t.endsWith('\n') || !t ? '' : '\n') + '\n' + FX().VIDEO_CSS }] }]; });
      this.fx.relative(n, ops);
      if (this.writeOps(ops, 'וידאו ברקע · ' + src + ' · ' + this.c.label(n)) == null) return;
      const v = K().byPath(this.c.m, path).kids.find(k => k.tag === 'video');
      if (desk && v) {
        const dev = this.devs().filter(d => d.max).sort((a, b) => a.max - b.max)[0], t = this.A.styler.targets(v).find(x => x.kind === 'rule' && /\.bg-video/.test(x.sel)) || this.A.styler.targets(v)[0];
        if (dev && t) this.write(new Map([[v, [['display', 'none', this.A.styler.dev(t, dev)]]]]), 'וידאו ברקע · רק במחשב (' + dev.label + ': display none)');
      }
      this.joinTx(this.txCount() - t0, 'וידאו ברקע · ' + this.c.label(n));
      if (v) this.c.select(K().byPath(this.c.m, K().pathOf(v)));
    }
    slideHtml(p) { return '<div class="bg-slide" style="background-image: ' + g.KolText.escAttr(g.KolBg.urlCss(this.rel(p), "'")) + '"></div>'; }
    async slidesNew(n) {
      const a = await this.pick('image', 'מצגת · תמונה 1'); if (!a) return; await tick(); const b = await this.pick('image', 'מצגת · תמונה 2'); if (!b) return;
      const path = K().pathOf(n), html = this.html(), t0 = this.txCount();
      if (!await this.ensureCss(['slides:2:fade:0'])) return;
      const ops = [() => { const m = this.c.m, at = K().byPath(m, path); return [{ doc: html, patches: K().html.insert(m, at, 0, '<div class="bg-slides bg-slides-2 kol-slides-fade-2" style="--kol-slide-d: 5s" aria-hidden="true">' + this.slideHtml(a) + this.slideHtml(b) + '</div>').patches }]; }];
      this.fx.relative(n, ops);
      if (this.writeOps(ops, 'מצגת רקע · 2 תמונות · ' + this.c.label(n)) == null) return;
      this.joinTx(this.txCount() - t0, 'מצגת רקע · ' + this.c.label(n));
    }
    async slideAdd(n) { const p = await this.pick('image', 'מצגת · תמונה נוספת'); if (!p) return; const e = this.c.elOf(n).querySelector(':scope > .bg-slides'), k = e.children.length + 1, mode = /kol-slides-slide/.test(e.className) ? 'slide' : 'fade', kb = /-kb-/.test(e.className); await this.slideCfg(n, k, mode, kb, p); }
    async slideCfg(n, k, mode, kb, add) {
      const sl = this.c.nodeOf(this.c.elOf(n).querySelector(':scope > .bg-slides')), sp = K().pathOf(sl), html = this.html(), t0 = this.txCount();
      if (!await this.ensureCss(['slides:' + k + ':' + mode + ':' + (kb ? 1 : 0)])) return;
      const name = FX().slidesCss(k, mode, kb).name, ops = [() => { const m = this.c.m, at = K().byPath(m, sp), cls = clsOf(at).filter(x => !/^bg-slides-\d+$|^kol-slides-/.test(x)).concat('bg-slides-' + k, name); return [{ doc: html, patches: K().html.setAttr(m, at, 'class', cls.join(' ')).patches }]; }];
      if (add) ops.push(() => { const m = this.c.m, at = K().byPath(m, sp); return [{ doc: html, patches: K().html.insert(m, at, at.kids.length, this.slideHtml(add)).patches }]; });
      if (this.writeOps(ops, 'מצגת רקע · ' + k + ' תמונות · ' + (mode === 'slide' ? 'החלקה' : 'דהייה') + (kb ? ' · קן ברנס' : '')) == null) return;
      this.joinTx(this.txCount() - t0, 'מצגת רקע · ' + this.c.label(n));
    }

    // ── הקנבס: הפעלה, "הצג שוב", גלילה מדומה ──
    /** T2: ▶ / ⏸ בפאנל — גם בטלפון, שבו ▶ אינו בסרגל. */
    playPanel() {
      const lab = () => (this.playing ? '⏸ עצור אנימציות בקנבס' : '▶ הפעל אנימציות בקנבס');
      const b = hh('button', { type: 'button', class: 'btn sm', 'data-play-panel': true, 'aria-pressed': String(!!this.playing), onclick: () => { this.setPlay(!this.playing); b.setAttribute('aria-pressed', String(!!this.playing)); b.textContent = lab(); } }, lab());
      return b;
    }
    setPlay(on) { this.playing = on; this.c.setPlay(on); if (this.playBtn) { this.playBtn.setAttribute('aria-pressed', String(on)); this.playBtn.lastChild.textContent = on ? ' עצור אנימציות' : ' הפעל אנימציות'; } }
    button() { this.playBtn = hh('button', { type: 'button', class: 'btn sm', 'data-play': true, 'aria-pressed': 'false', title: 'אנימציות בקנבס — מושהות כברירת מחדל (CSS של העורך, הקובץ אינו משתנה)', onclick: () => this.setPlay(!this.playing) }, '▶', hh('span', { class: 'ed-word' }, ' הפעל אנימציות')); return this.playBtn; }
    replay(n) { const e = this.c.elOf(n); if (!e) return; e.setAttribute('data-kol-replay', ''); e.style.animationName = 'none'; void e.offsetWidth; e.style.animationName = ''; clearTimeout(this.rt); this.rt = setTimeout(() => { e.removeAttribute('data-kol-replay'); }, 4000); }
    /** "מיקום גלילה מדומה": האנימציה של kol-sc מושהית בנקודה p (0–1) — style על האלמנט בקנבס בלבד. */
    scrubAt(n, p) { const e = this.c.elOf(n); if (!e) return; e.setAttribute('data-kol-replay', ''); for (const [k, v] of [['animation-name', 'kol-sc'], ['animation-timeline', 'auto'], ['animation-duration', '1s'], ['animation-delay', (-p) + 's'], ['animation-play-state', 'paused'], ['animation-fill-mode', 'both'], ['animation-timing-function', 'linear'], ['animation-range', 'normal']]) e.style.setProperty(k, v, 'important'); }
    ready(D, n) { D.info = D.styler.info(n); D.list = D.styler.targets(n, D.info); D.t = D.styler.st(D.styler.dev(D.target(n, D.list)), D.stt); }

    // ── ידיות: סיבוב, נקודת ציר, Ctrl + פינה = קנה מידה ──
    drawHandles() {
      const c = this.c, n = c.selected, L = this.layer();
      if (!this.rot) {
        this.rot = hh('div', { class: 'tf-rot', hidden: true, 'data-rotate-h': true, title: 'סיבוב — גרירה; Shift: קפיצות של 15°' }, '⟳');
        this.org = hh('div', { class: 'tf-org', hidden: true, 'data-origin-h': true, title: 'נקודת הציר (transform-origin) — גרירה' });
        this.tip = hh('span', { class: 'fx-tip', hidden: true, dir: 'auto' });
        this.rot.addEventListener('pointerdown', ev => this.rotDown(ev)); this.org.addEventListener('pointerdown', ev => this.orgDown(ev));
        L.append(this.rot, this.org, this.tip);
        L.addEventListener('pointerdown', ev => { const hz = ev.target.closest && ev.target.closest('.sz-h'); if (hz && (ev.ctrlKey || ev.metaKey) && /^(ne|nw|se|sw)$/.test(hz.dataset.sizeH)) { ev.stopPropagation(); ev.preventDefault(); this.scaleDown(ev, hz); } }, true);
      }
      const on = this.on() && n && !this.dragging && this.A.canWrite() && c.elOf(n) && !/^(html|body|head)$/.test(n.tag) && !(this.busy && this.busy()) && this.open.has('transform');
      const r = on ? c.rectOf(n) : null; this.rot.hidden = this.org.hidden = !r || r.zero; if (this.rot.hidden) return;
      const z = this.phone() ? 44 : 22, s = this.cs(n), e = c.elOf(n), og = s.transformOrigin.split(' ').map(parseFloat), sc = c.scale;
      this.rot.style.cssText = 'left:' + (r.left + r.width / 2 - z / 2) + 'px;top:' + (r.top - z - 14) + 'px;width:' + z + 'px;height:' + z + 'px';
      // נקודת הציר: transform-origin ביחס לקופסה שלפני ה-transform (offsetWidth) — מוצגת על המלבן שעל הקנבס (בקירוב, בלי סיבוב)
      const ox = r.left + (og[0] / (e.offsetWidth || 1)) * r.width, oy = r.top + (og[1] / (e.offsetHeight || 1)) * r.height, zo = this.phone() ? 30 : 14; void sc;
      this.org.style.cssText = 'left:' + (ox - zo / 2) + 'px;top:' + (oy - zo / 2) + 'px;width:' + zo + 'px;height:' + zo + 'px';
    }
    tf(n) { const t = FX().parseTransform(this.decl(this.A, n, 'transform')); if (!t) this.note('ה-transform כתוב בצורה שהפאנל אינו מפרק — נערך ב"מאפיינים נוספים"', true); return t; }
    showTip(e2, text) { const LR = this.layer().getBoundingClientRect(); this.tip.hidden = false; this.tip.textContent = text; this.tip.style.cssText = 'left:' + (e2.clientX - LR.left) + 'px;top:' + Math.max(0, e2.clientY - LR.top - 28) + 'px'; }
    drag(ev, h0, calc, done) {
      ev.preventDefault(); try { h0.setPointerCapture(ev.pointerId); } catch { /* */ }
      let moved = false; this.dragging = true;
      const mv = e2 => { moved = moved || Math.hypot(e2.clientX - ev.clientX, e2.clientY - ev.clientY) > 1; calc(e2); };
      const up = e2 => { h0.removeEventListener('pointermove', mv); h0.removeEventListener('pointerup', up); h0.removeEventListener('pointercancel', up); this.dragging = false; this.tip.hidden = true; if (e2.type !== 'pointerup' || !moved) { this.A.unpreview(); this.drawHandles(); return; } done(); this.drawHandles(); };
      h0.addEventListener('pointermove', mv); h0.addEventListener('pointerup', up); h0.addEventListener('pointercancel', up);
    }
    rotDown(ev) {
      const c = this.c, n = c.selected; if (!n || ev.button > 0) return; this.ready(this.A, n); const t = this.tf(n); if (!t) return;
      const r = c.rectOf(n), LR = this.layer().getBoundingClientRect(), cx = LR.left + r.left + r.width / 2, cy = LR.top + r.top + r.height / 2, a0 = Math.atan2(ev.clientY - cy, ev.clientX - cx), d0 = FX().deg(t.rot);
      let v = t.rot;
      this.drag(ev, ev.currentTarget, e2 => { let d = d0 + (Math.atan2(e2.clientY - cy, e2.clientX - cx) - a0) * 180 / Math.PI; d = e2.shiftKey ? Math.round(d / 15) * 15 : Math.round(d); d = ((d + 180) % 360 + 360) % 360 - 180; v = d + 'deg'; this.A.preview([n], [['transform', FX().joinTransform(Object.assign({}, t, { rot: v }))]]); this.showTip(e2, 'rotate(' + v + ')'); },
        () => { const jv = FX().joinTransform(Object.assign({}, t, { rot: v })); this.put(this.A, [['transform', jv === 'none' ? null : jv]], 'סיבוב · ' + v); });
    }
    orgDown(ev) {
      const c = this.c, n = c.selected, e = c.elOf(n); if (!e || ev.button > 0) return; this.ready(this.A, n);
      const r = c.rectOf(n), LR = this.layer().getBoundingClientRect(); let v = '';
      this.drag(ev, ev.currentTarget, e2 => { let px = (e2.clientX - LR.left - r.left) / r.width * 100, py = (e2.clientY - LR.top - r.top) / r.height * 100; const snap = x => (Math.abs(x - 50) < 4 ? 50 : x < 4 ? 0 : x > 96 ? 100 : Math.round(x)); px = snap(px); py = snap(py); v = px + '% ' + py + '%'; this.A.preview([n], [['transform-origin', v]]); ev.currentTarget && (this.org.style.left = (e2.clientX - LR.left - this.org.offsetWidth / 2) + 'px', this.org.style.top = (e2.clientY - LR.top - this.org.offsetHeight / 2) + 'px'); this.showTip(e2, 'transform-origin: ' + v); },
        () => this.put(this.A, [['transform-origin', v]], 'נקודת ציר · ' + v));
    }
    scaleDown(ev, hz) {
      const c = this.c, n = c.selected; if (!n || ev.button > 0) return; this.ready(this.A, n); const t = this.tf(n); if (!t) return;
      const r = c.rectOf(n), LR = this.layer().getBoundingClientRect(), cx = LR.left + r.left + r.width / 2, cy = LR.top + r.top + r.height / 2, d0 = Math.hypot(ev.clientX - cx, ev.clientY - cy) || 1, s0 = +t.sx || 1;
      let v = String(s0);
      this.drag(ev, hz, e2 => { v = String(r2(Math.max(0.05, s0 * Math.hypot(e2.clientX - cx, e2.clientY - cy) / d0))); this.A.preview([n], [['transform', FX().joinTransform(Object.assign({}, t, { sx: v, sy: v }))]]); this.showTip(e2, 'scale(' + v + ')'); },
        () => { const jv = FX().joinTransform(Object.assign({}, t, { sx: v, sy: v })); this.put(this.A, [['transform', jv === 'none' ? null : jv]], 'קנה מידה · ' + v); });
    }
  }
  /** kol.json: "system" — מוסיף את js/kol-motion.js (ואת js/kol-data.js, כשהמפתח לא היה — כדי שלא ייעלם מהרשימה). תיקון טקסט, לא כתיבה מחדש. */
  function planSystem(text, withData) {
    let j; try { j = JSON.parse(text); } catch { throw new (K().SrcError)('kol.json אינו JSON תקין — מתקנים אותו בקוד, ואז מוסיפים את js/kol-motion.js', 'broken'); }
    const entry = '"js/kol-motion.js": { "version": "1.0.0", "modified": false }';
    if (j.system && typeof j.system === 'object') { const m = /"system"\s*:\s*\{/.exec(text); const at = m.index + m[0].length, empty = !Object.keys(j.system).length; return [{ from: at, to: at, text: ' ' + entry + (empty ? ' ' : ',') }]; }
    const end = text.lastIndexOf('}'); let b = end; while (b > 0 && /\s/.test(text[b - 1])) b--;
    const ind = (/\n([ \t]+)"/.exec(text) || [, '  '])[1];
    return [{ from: b, to: b, text: ',\n' + ind + '"system": { ' + (withData ? '"js/kol-data.js": { "version": "1.0.0", "modified": false }, ' : '') + entry + ' }' }];
  }
  g.KolMotion = { Motion, planSystem, RANGES };
})(typeof window !== 'undefined' ? window : globalThis);
