/**
 * העורך החזותי — מכשירים (פרוסה V7, 03 §8.1): הסתרה לפי מכשיר, היפוך סדר, וסדר שונה במכשיר אחד (order).
 *
 * הכתיבה עצמה — כמו כל עיצוב: writeStyle עם יעד שמכשירו נקבע (Styler.dev), כלומר אותו בורר בתוך @media (max-width: …px)
 * של נקודת השבירה; "מחשב" — הכלל הבסיסי. הקובץ הזה רק מחליט מה לכתוב ולאן. אין כאן innerHTML — כל טקסט מהעמוד כטקסט.
 */
(function () {
  'use strict';
  const hh = (tag, attrs, ...kids) => window.h(tag, attrs, ...kids);
  const INLINE = /^(span|a|b|i|em|strong|small|code|label|abbr|cite|q|sub|sup|mark|time|u|s)$/;

  class Devices {
    /** o: { design (לשונית "מתקדם"), write (writeStyle), note, undo, device() (null — מחשב), devs() (כל המכשירים, מהרחב), setDevice } */
    constructor(o) { Object.assign(this, o); }
    get D() { return this.design; }
    get st() { return this.D.styler; }
    get c() { return this.D.canvas; }
    /** היעד הבסיסי של האלמנט (הכלל של המחלקה / כלל חדש / מחלקה חדשה), ובמכשיר d — אותו בורר ב-@media שלו. */
    target(n, info, d) {
      const list = this.st.targets(n, info), base = list.find(t => t.kind !== 'inline') || list[0];
      return d && d.max ? this.st.dev(base, d) : Object.assign({}, base, { dev: null });
    }
    /** display בכל מכשיר — מהכללים. */
    states(n, info) { return this.st.perDevice(n, 'display', info || this.st.info(n)); }
    hidden(n, d, info) { const x = this.states(n, info).find(s => s.d.id === d.id); return !!x && /^none$/i.test(x.value); }
    /** ה-display שהאלמנט חוזר אליו: הצהרה כתובה שאינה none (flex / grid…), אחרת התצוגה הנוכחית, אחרת לפי התגית. */
    prev(n, info) {
      for (const r of info.rules) for (const dc of r.decls) if (dc.prop.toLowerCase() === 'display' && !/^none$/i.test(dc.value.trim()) && !/var\(/.test(dc.value)) return dc.value.trim();
      const cs = this.st.computed(n, 'display');
      if (cs && cs !== 'none' && !this.c.devHidden?.has(n)) return cs;
      return INLINE.test(n.tag) ? 'inline' : n.tag === 'li' ? 'list-item' : 'block';
    }
    /** "הסתר במכשיר" / "הצג": display:none ב-@media של המכשיר; "הצג" — מוחק אותה, ואם הערך שעובר אליו הוא none — כותב את
     *  ה-display הקודם. הסתרה במחשב (הבסיס) — המכשירים הצרים שלא הוסתרו שומרים על התצוגה שלהם (נכתב להם display). */
    set(n, d, hide) {
      const info = this.st.info(n), devs = this.devs(), states = this.states(n, info), me = states.find(s => s.d.id === d.id), prev = this.prev(n, info), sets = [];
      if (!me || this.hidden(n, d, info) === hide) return null;
      if (hide) {
        sets.push(['display', 'none', this.target(n, info, d)]);
        if (!d.max) for (const s of states) if (s.d.max && !s.own && !/^none$/i.test(s.value)) { sets.push(['display', prev, this.target(n, info, s.d)]); break; }   // הרחב שבמכשירים — והצרים יורשים ממנו
      } else {
        // מה יעבור למכשיר אחרי מחיקת ההצהרה שלו: המכשיר הרחב הקרוב (או הבסיס)
        const wider = states.filter(s => s.d.id !== d.id && (d.max ? (s.d.max == null || s.d.max > d.max) : false)).sort((a, b) => (a.d.max ?? 1e9) - (b.d.max ?? 1e9));
        const inh = wider.find(s => s.own) || wider.find(s => s.d.max == null);
        if (me.own && d.max && inh && !/^none$/i.test(inh.value)) sets.push(['display', null, this.target(n, info, d)]);
        else sets.push(['display', prev, this.target(n, info, d)]);
      }
      void devs;
      return this.write(new Map([[n, sets]]), (hide ? 'הסתרה' : 'הצגה') + ' · ' + this.c.label(n) + ' · ' + d.label);
    }
    /** "היפוך סדר" ברובריקה (flex): flex-direction: *-reverse במכשיר הנוכחי. */
    reverse(n) {
      const dir = this.st.computed(n, 'flex-direction') || 'row', nv = /-reverse$/.test(dir) ? dir.replace(/-reverse$/, '') : dir + '-reverse', d = this.device();
      const info = this.st.info(n);
      return this.write(new Map([[n, [['flex-direction', nv, this.target(n, info, d)]]]]), 'היפוך סדר · ' + this.c.label(n) + ' · ' + dir + ' ← ' + nv);
    }
    /** סדר שונה במכשיר אחד: n עובר לפני ref (null — לסוף) בין אחיו — order לכל האחים, ב-@media; ההורה שאינו flex/grid — display:flex
     *  (וכיוון עמודה, כדי שהאחים יישארו זה מתחת לזה). מחזיר את הכתיבה, או null. */
    order(n, ref) {
      const d = this.device(); if (!d) return null;
      const par = n.parent, c = this.c, sibs = par.kids.filter(k => c.elOf(k) && c.visual(k) !== 'nonvisual');
      const cur = sibs.slice().sort((a, b) => (+this.st.computed(a, 'order') || 0) - (+this.st.computed(b, 'order') || 0) || sibs.indexOf(a) - sibs.indexOf(b));
      const next = cur.filter(k => k !== n); let at = ref ? next.indexOf(ref) : next.length; if (at < 0) at = next.length; next.splice(at, 0, n);
      const per = new Map(), pd = this.st.computed(par, 'display');
      next.forEach((k, i) => { if ((+this.st.computed(k, 'order') || 0) !== i + 1) per.set(k, [['order', String(i + 1), this.target(k, this.st.info(k), d)]]); });
      if (!per.size) return null;
      if (!/flex|grid/.test(pd)) per.set(par, [['display', 'flex', this.target(par, this.st.info(par), d)], ['flex-direction', 'column', this.target(par, this.st.info(par), d)]]);
      return this.write(per, 'סדר · ' + c.label(n) + ' · ' + d.label + ' בלבד · מקום ' + (at + 1));
    }
    /** הסדר הנראה מול סדר המקור (Navigator): "נראה 1 · במקור 3"; null — אין order שמשנה דבר. */
    seen(n) {
      const c = this.c, par = n.parent; if (!par || !c.m2d || !c.elOf(n)) return null;
      const pd = this.st.computed(par, 'display'); if (!/flex|grid/.test(pd)) return null;
      const sibs = par.kids.filter(k => c.elOf(k)), ords = sibs.map(k => +this.st.computed(k, 'order') || 0); if (ords.every(x => !x)) return null;
      const vis = sibs.map((k, i) => i).sort((a, b) => ords[a] - ords[b] || a - b), si = sibs.indexOf(n), vi = vis.indexOf(si);
      return vi === si ? null : 'נראה ' + (vi + 1) + ' · במקור ' + (si + 1);
    }
    /** "מתקדם" ← "מכשירים": תיבה לכל מכשיר ("מוסתר"), והיפוך סדר לרובריקה flex. */
    section(n) {
      if (!n || /^(html|head|body)$/.test(n.tag) || !this.c.elOf(n)) return [];
      const info = this.st.info(n), devs = this.devs(), w = this.D.canWrite(), d = this.device();
      const sec = hh('section', { class: 'sg-sec', 'data-sec': 'devices' }, hh('h3', { class: 'dt-h' }, 'מכשירים'));
      const row = hh('div', { class: 'sg-devhide', role: 'group', 'aria-label': 'הסתרה לפי מכשיר' });
      for (const x of devs) {
        const on = this.hidden(n, x, info), cb = hh('input', { type: 'checkbox', checked: on || null, disabled: !w || null, 'data-devhide': x.id });
        cb.addEventListener('change', () => this.set(n, x, cb.checked));
        row.append(hh('label', { class: 'sg-devhide-i' + (on ? ' on' : '') }, cb, ' מוסתר ב' + x.label));
      }
      sec.append(row, hh('p', { class: 'hint' }, 'מוסתר במכשיר — מוסתר גם בצרים ממנו. בקנבס הוא נשאר שקוף למחצה ומקווקו, כדי שאפשר יהיה לבחור אותו.'));
      if (/flex/.test(this.st.computed(n, 'display'))) sec.append(hh('div', { class: 'sg-line' }, hh('button', { type: 'button', class: 'btn sm', 'data-reverse': true, disabled: !w || null, onclick: () => this.reverse(n) }, '⇅ היפוך סדר הילדים' + (d ? ' ב' + d.label : ''))));
      if (d) sec.append(hh('p', { class: 'hint' }, 'סדר שונה רק כאן: גוררים את האלמנט בקנבס ובוחרים "רק כאן" — נכתב order לאחים, ב-@media.'));
      return [sec];
    }
  }
  window.KolDevices = { Devices };
})();
