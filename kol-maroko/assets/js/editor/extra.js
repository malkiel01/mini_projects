/**
 * העורך החזותי — "מאפיינים נוספים" ו"צפה כ-CSS" (פרוסה V8, 03 §4.4): הרשת שמתחת לכל השליטות.
 *
 * בתחתית כל לשונית: כל ההצהרות שחלות על האלמנט (במצב ובמכשיר הנוכחיים) ואין להן שליטה ייעודית — שם, ערך, מקור, מכשיר,
 * מצב, !important, איפוס; נערכות במקום, עם השלמה של כל מאפייני ה-CSS שהדפדפן מכיר ומאפיינים מותאמים (--x). "＋ מאפיין" —
 * נכתב לכלל היעד, במכשיר ובמצב הנוכחיים. "צפה כ-CSS" — כל הצהרות כלל היעד כטקסט, נערכות כגוש.
 * כל ערך עובר checkValue (V3: '<' נדחה, וערך שאינו חוקי למאפיין); כל שם — checkProp. כטקסט בלבד; אין innerHTML.
 */
(function () {
  'use strict';
  const K = () => window.KolSrc, ST = () => window.KolStyle, DT = () => window.KolDetails;
  /** מאפיינים שיש להם שליטה ייעודית באחת הלשוניות (V3–V7). כל השאר — כאן. */
  const CONTROLLED = new Set(('font-family font-size font-weight font-style text-decoration-line text-decoration-style text-decoration-color text-transform line-height letter-spacing word-spacing text-align direction color ' +
    'background-color background-image background-position background-size background-repeat background-attachment background-origin background-clip background-blend-mode ' +
    'margin-top margin-right margin-bottom margin-left padding-top padding-right padding-bottom padding-left row-gap column-gap ' +
    'display position top right bottom left z-index width height min-width min-height max-width max-height box-sizing aspect-ratio overflow-x overflow-y ' +
    'flex-direction flex-wrap flex-grow flex-shrink flex-basis order justify-content align-items align-content align-self justify-items justify-self grid-auto-flow grid-template-columns grid-template-rows ' +
    'transition-property transition-duration transition-timing-function transition-delay ' +
    // V10: מסגרת, פינות, צל, אפקטים, מסיכה, transform, נדבק (top/bottom כבר), כניסה וגלילה (--kol-in-* / --kol-sc-*)
    'border border-radius border-top-left-radius border-top-right-radius border-bottom-right-radius border-bottom-left-radius ' +
    ['top', 'right', 'bottom', 'left'].map(s => ['width', 'style', 'color'].map(k => 'border-' + s + '-' + k).join(' ')).join(' ') + ' ' +
    'outline-style outline-width outline-color outline-offset opacity filter backdrop-filter mix-blend-mode isolation ' +
    'mask-image mask-size mask-position mask-repeat -webkit-mask-image -webkit-mask-size -webkit-mask-position -webkit-mask-repeat transform transform-origin').split(' '));
  /** V10: מה שהפאנל אינו מפרק (צל עם var() בשכבה, filter עם url(), transform עם matrix) — נשאר כאן, ונערך כאן. */
  const RAW = { 'box-shadow': v => window.KolFx.parseShadows(v) === null, 'text-shadow': v => window.KolFx.parseShadows(v, true) === null, filter: v => window.KolFx.parseFilter(v) === null, 'backdrop-filter': v => window.KolFx.parseFilter(v) === null, transform: v => window.KolFx.parseTransform(v) === null };
  const SHORT = ['margin', 'padding', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-radius', 'border-color', 'border-style', 'border-width', 'background', 'font', 'flex', 'flex-flow', 'grid', 'grid-area', 'grid-template', 'grid-row', 'grid-column', 'gap', 'inset', 'outline', 'transition', 'animation', 'text-decoration', 'list-style', 'columns', 'overflow', 'place-items', 'place-content', 'place-self', 'mask', 'text-emphasis', 'scroll-margin', 'scroll-padding', 'container', 'offset'];
  let PROPS = null;
  /** כל מאפייני ה-CSS שהדפדפן מכיר: הרשימה של getComputedStyle + קיצורים (CSS.supports). */
  function allProps() {
    if (PROPS) return PROPS;
    const out = new Set(), cs = getComputedStyle(document.documentElement);
    for (let i = 0; i < cs.length; i++) if (!cs[i].startsWith('--')) out.add(cs[i]);
    for (const p of SHORT) if (CSS.supports(p, 'initial')) out.add(p);
    PROPS = [...out].sort(); return PROPS;
  }
  /** שם מאפיין: מותאם (--x) או מאפיין שהדפדפן מכיר. */
  function checkProp(name) {
    const v = String(name == null ? '' : name).trim().toLowerCase(), E = K().SrcError;
    if (/^--[\w-]+$/.test(name.trim())) return name.trim();
    if (!/^-?[a-z][a-z-]*$/.test(v)) throw new E('שם מאפיין: אותיות לטיניות ו- בלבד (או --שם למאפיין מותאם)', 'invalid');
    if (!CSS.supports(v, 'initial') && !allProps().includes(v)) throw new E('הדפדפן אינו מכיר את המאפיין ' + v, 'invalid');
    return v;
  }
  const covers = (prop, rtl) => ST().covered(prop, rtl);
  const controlled = (prop, rtl, value) => { const p = prop.trim().toLowerCase(); if (/^--kol-(in|sc)-/.test(p)) return true; if (p.startsWith('--')) return false; if (RAW[p] && window.KolFx) return !RAW[p](value || ''); if (p === 'box-shadow' || p === 'text-shadow') return true; const c = covers(p, rtl); return c.every(x => CONTROLLED.has(x)) && !SHORT.includes(p) || CONTROLLED.has(p); };

  class Extra {
    /** opt: { canvas, styler, writeOps, note, html(), docOf(path) } */
    constructor(opt) { Object.assign(this, opt); }
    get c() { return this.canvas; }
    /** תיקונים לכלל שבפאנל "פרטים", מתוכננים מחדש על הטקסט העדכני. fn(m, rule, decl) ← patches. */
    ops(n, r, d, fn) {
      const K2 = K(), html = this.html();
      if (r.inline) {
        const st = K2.attrOf(n, 'style'), src = 'x{' + (st ? st.value : '') + '}', m = K2.parseCss(src), rule = m.root.kids[0];
        if (!rule || rule.broken) throw new K2.SrcError('תכונת style שאינה נקראת — מתקנים אותה בקוד', 'broken');
        const dd = d ? rule.decls.find(x => x.prop.trim() === d.prop && x.value === d.value) || null : null;
        const out = K2.applyPatches(src, fn(m, rule, dd)).slice(2, -1);
        return [{ doc: html, html: true, patches: (out.trim() ? K2.html.setAttr(this.c.m, n, 'style', out) : K2.html.removeAttr(this.c.m, n, 'style')).patches }];
      }
      const src = r.src, text = src.html ? src.text : this.docOf(src.path).text(), m = K2.parseCss(text), rule = K2.cssAll(m).find(x => x.type === 'rule' && x.from === r.node.from);
      if (!rule) throw new K2.SrcError('הכלל השתנה בינתיים — נסו שוב', 'stale');
      const dd = d && d.d ? rule.decls[r.node.decls.indexOf(d.d)] || null : null;
      const ps = fn(m, rule, dd);
      return src.html ? [{ doc: html, html: true, patches: ps.map(p => ({ from: p.from + src.off, to: p.to + src.off, text: p.text })) }] : [{ doc: this.docOf(src.path), patches: ps }];
    }
    edit(n, r, d, label, fn) { const path = K().pathOf(n); return this.writeOps([() => this.ops(K().byPath(this.c.m, path), r, d, fn)], label); }

    /** "מאפיינים נוספים": D — הפאנל (Design), עם info / t של המצב והמכשיר הנוכחיים. */
    section(n, D) {
      const rtl = this.styler.rtl(n), info = D.info, can = D.canWrite();
      const sec = h('section', { class: 'sg-sec sg-extra', 'data-sec': 'extra-' + D.mode }, h('h3', { class: 'dt-h' }, 'מאפיינים נוספים'));
      const rows = [];
      for (const r of info.rules) {
        if (r.off) continue;
        for (const d of r.decls) if (!controlled(d.prop, rtl, d.value)) rows.push({ r, d, k: DT().rankOf(r, d.important) });
      }
      rows.sort((a, b) => { for (let i = 0; i < a.k.length; i++) if (a.k[i] !== b.k[i]) return b.k[i] - a.k[i]; return 0; });
      const list = h('div', { class: 'sg-extra-list', 'data-extra-list': true });
      for (const { r, d } of rows) {
        const editable = can && (r.inline || (r.located && !r.external && r.node && !r.node.broken));
        const at = r.inline ? 'style=""' : r.sel.replace(/\s+/g, ' ') + ' · ' + r.path + (d.line ? ':' + d.line : '');
        const nm = h('input', { type: 'text', dir: 'ltr', value: d.prop, list: 'sg-props', 'aria-label': 'שם המאפיין', spellcheck: 'false', 'data-extra-name': d.prop, disabled: !editable || null });
        const val = h('input', { type: 'text', dir: 'ltr', value: d.value, 'aria-label': 'ערך ' + d.prop, spellcheck: 'false', 'data-extra-value': d.prop, disabled: !editable || null });
        const lab = '· ' + this.c.label(n) + ' · ' + d.prop;
        nm.addEventListener('change', () => {
          let p; try { p = checkProp(nm.value); ST().checkValue(p, d.value); } catch (e) { nm.classList.add('bad'); nm.title = e.message; this.note(e.message, true); return; }
          this.edit(n, r, d, 'שם מאפיין ' + lab + ' ← ' + p, (m, rule, dd) => (dd ? [{ from: dd.from, to: dd.from + dd.prop.length, text: p }] : []));
        });
        val.addEventListener('change', () => {
          const v = val.value.trim();
          try { if (!v) throw new (K().SrcError)('ערך ריק — לאיפוס: ⟲', 'invalid'); ST().checkValue(d.prop, v); } catch (e) { val.classList.add('bad'); val.title = e.message; val.setAttribute('aria-invalid', 'true'); this.note(e.message, true); return; }
          this.edit(n, r, d, 'מאפיין ' + lab + ' · ' + d.value + ' ← ' + v, (m, rule, dd) => (dd ? [{ from: dd.valFrom, to: dd.valTo, text: v }] : []));
        });
        const imp = h('button', { type: 'button', class: 'btn sm sg-extra-imp', 'aria-pressed': String(!!d.important), 'data-extra-imp': d.prop, title: d.important ? 'הסרת !important' : 'הוספת !important', disabled: !editable || null,
          onclick: () => this.edit(n, r, d, (d.important ? 'הסרת ' : '') + '!important ' + lab, (m, rule, dd) => (!dd ? [] : dd.important ? [{ from: dd.valTo, to: dd.to, text: '' }] : [{ from: dd.valTo, to: dd.valTo, text: ' !important' }])) }, '!');
        const reset = h('button', { type: 'button', class: 'sg-reset', 'data-extra-reset': d.prop, 'aria-label': 'איפוס — מחיקת ההצהרה ' + d.prop, title: 'איפוס: מוחק את ההצהרה', disabled: !editable || null,
          onclick: () => this.edit(n, r, d, 'איפוס ' + lab, (m, rule, dd) => (dd ? ST().removeDeclAt(m, rule, dd) : [])) }, '⟲');
        const meta = h('div', { class: 'sg-src' }, h('button', { type: 'button', class: 'sg-at', dir: 'ltr', title: 'פתח בקוד', disabled: r.external || r.inline || null, onclick: () => D.openAt(r.path, d.line || r.line || 1) }, at));
        if (r.media) meta.append(h('span', { class: 'sg-media', 'data-extra-media': true }, ' ' + r.media));
        if (r.stateHit) meta.append(h('span', { class: 'sg-extra-state', 'data-extra-state': true }, ' · במצב ' + D.stateLabel()));
        if (d.by) meta.append(h('span', { class: 'sg-extra-by', 'data-extra-over': d.prop }, ' · נדרס ע״י ' + d.by.sel));
        if (!r.located && !r.inline) meta.append(h('span', { class: 'hint' }, ' · לא נמצא בקובץ'));
        list.append(h('div', { class: 'sg-field sg-extra-row' + (d.by ? ' over' : ''), 'data-extra': d.prop }, h('div', { class: 'sg-line' }, h('span', { class: 'sg-ctl wide sg-extra-ctl' }, nm, val), h('span', { class: 'sg-acts' }, imp, reset)), meta));
      }
      if (!rows.length) list.append(h('p', { class: 'hint' }, 'אין הצהרות בלי שליטה ייעודית.'));
      sec.append(list, h('datalist', { id: 'sg-props' }, allProps().map(p => h('option', { value: p })), this.customProps().map(p => h('option', { value: p }))));
      // ＋ מאפיין — לכלל היעד, במכשיר ובמצב הנוכחיים
      const nm = h('input', { type: 'text', dir: 'ltr', list: 'sg-props', placeholder: 'מאפיין', 'aria-label': 'מאפיין חדש — שם', spellcheck: 'false', 'data-extra-new-name': true, disabled: !can || null });
      const val = h('input', { type: 'text', dir: 'ltr', placeholder: 'ערך', 'aria-label': 'מאפיין חדש — ערך', spellcheck: 'false', 'data-extra-new-value': true, disabled: !can || null });
      const go = () => {
        let p; const v = val.value.trim();
        try { p = checkProp(nm.value); if (!v) throw new (K().SrcError)('ערך ריק', 'invalid'); ST().checkValue(p, v); } catch (e) { nm.classList.add('bad'); nm.title = e.message; this.note(e.message, true); return; }
        D.put([[p, v]], 'מאפיין ' + p + ' · ' + this.c.label(n) + ' · ' + v);
      };
      val.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
      sec.append(h('div', { class: 'sg-line sg-extra-new' }, h('span', { class: 'sg-ctl wide sg-extra-ctl' }, nm, val), h('button', { type: 'button', class: 'btn sm', 'data-extra-add': true, disabled: !can || null, onclick: go }, '＋ מאפיין')),
        h('p', { class: 'hint', 'data-extra-where': true }, 'נכתב ל: ' + D.tlabel(D.t)));
      sec.append(this.cssView(n, D));
      return sec;
    }
    customProps() { const out = new Set(); for (const s of this.styler.sheets().values()) for (const m of s.doc.text().matchAll(/(--[\w-]+)\s*:/g)) out.add(m[1]); return [...out].slice(0, 200); }
    /** הכלל של היעד בפאנל: מצב / מכשיר / כלל / style. null — עוד לא קיים. */
    ownRule(D) {
      const t = D.t, info = D.info;
      if (t.kind === 'inline') return info.rules.find(r => r.inline) || { inline: true, decls: [], none: true };
      if (t.state) return this.styler.stOwn(info, t);
      if (t.dev) return this.styler.devOwn(info, t);
      if (t.kind === 'rule') return info.rules.find(r => r === t.r) || t.r;
      return null;
    }
    /** "צפה כ-CSS": כל ההצהרות של כלל היעד, נערכות כגוש. */
    cssView(n, D) {
      const wrap = h('details', { class: 'sg-cssview', 'data-cssview': true }), r = this.ownRule(D);
      const text = r && !r.none ? r.decls.map(d => d.prop + ': ' + d.value + (d.important ? ' !important' : '') + ';').join('\n') : '';
      const ta = h('textarea', { dir: 'ltr', rows: Math.max(3, Math.min(14, text.split('\n').length + 1)), spellcheck: 'false', 'aria-label': 'ההצהרות של כלל היעד, כטקסט', 'data-cssview-text': true, disabled: !D.canWrite() || null });
      ta.value = text;
      const err = h('p', { class: 'sg-over', hidden: true, 'data-cssview-err': true });
      const apply = () => {
        err.hidden = true;
        let list; try { list = parseBlock(ta.value); } catch (e) { err.hidden = false; err.textContent = e.message; return; }
        const label = 'CSS כגוש · ' + this.c.label(n) + ' · ' + D.tlabel(D.t);
        if (!r || r.none) { D.put(list.map(d => [d.prop, d.value]), label); return; }   // אין עדיין כלל — נכתב דרך היעד (נוצר)
        this.edit(n, r, null, label, (m, rule) => {
          const inner = m.src.slice(rule.bFrom + 1, rule.bTo - 1), multi = /\n/.test(inner);
          const ind = multi ? ((/\n([ \t]+)\S/.exec(inner) || [, '  '])[1]) : '', baseInd = multi ? ((/\n([ \t]*)$/.exec(inner) || [, ''])[1]) : '';
          const body = list.map(d => d.prop + ': ' + d.value + (d.important ? ' !important' : '') + ';');
          const txt = !body.length ? (multi ? m.nl + baseInd : ' ') : multi ? body.map(x => m.nl + ind + x).join('') + m.nl + baseInd : ' ' + body.join(' ') + ' ';
          return [{ from: rule.bFrom + 1, to: rule.bTo - 1, text: txt }];
        });
      };
      wrap.append(h('summary', {}, 'צפה כ-CSS — ' + (r && !r.none ? (r.inline ? 'style=""' : r.sel.replace(/\s+/g, ' ')) : 'כלל חדש: ' + D.tlabel(D.t))), ta, err, h('div', { class: 'sg-line' }, h('button', { type: 'button', class: 'btn sm', 'data-cssview-apply': true, disabled: !D.canWrite() || null, onclick: apply }, 'החל')));
      return wrap;
    }
  }
  /** גוש הצהרות שהוקלד ← [{prop, value, important}], או חריגה: כלל מקונן, סוגריים {} , '<', שם או ערך לא חוקיים. */
  function parseBlock(text) {
    const K2 = K(), E = K2.SrcError, src = 'x{' + text + '\n}';
    if (/[{}]/.test(text.replace(/"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'/g, ''))) throw new E('בגוש — הצהרות בלבד (שם: ערך;), בלי { } — כלל נוסף נכתב בקוד', 'invalid');
    const m = K2.parseCss(src), r = m.root.kids[0];
    if (!r || r.broken || m.root.kids.length !== 1 || r.kids.length || K2.cssAll(m).some(x => x.broken)) throw new E('הגוש אינו נקרא כהצהרות CSS — בודקים נקודה-פסיק ונקודתיים', 'invalid');
    const out = [];
    for (const d of r.decls) { const p = checkProp(d.prop); ST().checkValue(p, d.value); out.push({ prop: p, value: d.value.trim(), important: d.important }); }
    return out;
  }
  window.KolExtra = { Extra, checkProp, parseBlock, allProps, CONTROLLED };
})();
