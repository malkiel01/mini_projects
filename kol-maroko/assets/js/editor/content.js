/**
 * העורך החזותי — לשונית "תוכן" (פרוסה V2, 03 §4.3): הראשונה בפאנל שנערכת. לפי האלמנט הנבחר:
 *   טקסט (עריכה במקום, תגית) · תמונה (החלפה, הסרה, alt, כיתוב, קישור, object-fit ונקודת מיקוד, גודל, טעינה עצלה) ·
 *   קישור וכפתור (יעד, חלון חדש, הסרה) · שמע ווידאו (קובץ, פקדים, לולאה, השתקה, ניגון אוטומטי, תמונת פתיחה) · הטמעה.
 *
 * כל שליטה כותבת עסקה אחת עם שם, דרך אותו צינור של שאר העורך (opt.act ← מודל המקור ← שירות המסמכים). הפאנל אינו
 * מחזיק מצב משלו: אחרי כל שינוי הוא מצויר מחדש מהמודל. כל ערך מהעמוד מוצג כטקסט / כערך של שדה — אין כאן innerHTML.
 * מה ש-V2 אינה עושה נאמר כאן במילים, במקום שבו בעל העמוד מחפש אותו (תמונת רקע — V5; מה שכפתור עושה — בקוד).
 */
(function () {
  'use strict';
  const K = window.KolSrc, T = window.KolText;
  const IMG_EXT = /\.(png|jpe?g|gif|webp|avif|svg|bmp|ico)$/i, AUD_EXT = /\.(mp3|m4a|aac|ogg|oga|wav|flac|opus|weba)$/i, VID_EXT = /\.(mp4|m4v|webm|ogv|mov)$/i;
  const kb = n => (n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : n >= 1024 ? Math.round(n / 1024) + ' KB' : n + ' B');
  const escA = s => T.escAttr(String(s));
  const TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div'];

  /** עריכת מאפיין אחד בתכונת style: מחזיר את הטקסט החדש של התכונה ('' — אין עוד הצהרות). value === null — הסרה. */
  function styleEdit(style, prop, value) {
    let decls = [];
    try { const m = K.parseCss('x{' + style + '}'), r = m.root.kids[0]; decls = r ? r.decls : []; } catch { decls = []; }
    const d = decls.find(x => x.prop.trim().toLowerCase() === prop);
    if (d && value != null) return style.slice(0, d.valFrom - 2) + value + style.slice(d.valTo - 2);
    if (d) return decls.filter(x => x !== d).map(x => style.slice(x.from - 2, x.to - 2).trim()).join('; ');   // הסרה: שאר ההצהרות, כמו שנכתבו
    if (value == null) return style;
    const base = style.replace(/[\s;]+$/, '');
    return (base ? base + '; ' : '') + prop + ': ' + value;
  }
  const styleGet = (style, prop) => { try { const m = K.parseCss('x{' + style + '}'), r = m.root.kids[0], d = r && r.decls.find(x => x.prop.trim().toLowerCase() === prop); return d ? d.value.trim() : ''; } catch { return ''; } };

  class Content {
    /**
     * opt: { canvas, file(), files(): [{path, kind, size, media_info}], act(label, fn(m), n): bool, canWrite(): bool, begin(n), openEl(n), openAt(path, line),
     *        note(text, err), pick(kind, title): Promise<path|null>, url(ref): string|'' (כתובת להצגה), resolve(ref): path|null, rel(path): string, details }
     */
    constructor(host, opt) { Object.assign(this, opt); this.host = host; }
    get n() { return this.canvas.selected; }
    attr(n, name) { const a = K.attrOf(n, name); return a ? a.value : null; }
    /** תכונה: value === null מסיר; '' עם bool — תכונה בלי ערך. */
    setAttr(n, name, value, label, bool) {
      const has = K.attrOf(n, name);
      if (value == null) return has ? this.act(label, m => K.html.removeAttr(m, n, name), n) : true;
      if (has && has.value === value && !bool) return true;
      return this.act(label, m => K.html.setAttr(m, n, name, bool ? null : value), n);
    }
    setStyle(n, prop, value, label) {
      const cur = this.attr(n, 'style') || '', next = styleEdit(cur, prop, value);
      if (next === cur) return true;
      return this.setAttr(n, 'style', next === '' ? null : next, label);
    }
    lab(n) { return this.canvas.label(n); }

    draw() {
      const c = this.canvas, n = this.n, kids = [];
      if (!n || !c.m || !this.file()) { this.host.replaceChildren(h('p', { class: 'hint dt-empty' }, 'בוחרים אלמנט — ורואים כאן את התוכן שלו: טקסט, תמונה, קישור, שמע או וידאו.')); return; }
      const ro = !this.canWrite();
      if (ro) kids.push(h('p', { class: 'dt-note' }, 'הקובץ פתוח לקריאה בלבד — אפשר לראות, לא לשנות.'));
      const tag = n.tag, link = this.linkOf(n);
      if (tag === 'img') kids.push(...this.image(n));
      else if (tag === 'svg' && n.foreign) kids.push(...this.svg(n));
      else if (tag === 'audio' || tag === 'video') kids.push(...this.media(n));
      else if (tag === 'iframe') kids.push(...this.frame(n));
      else if (tag === 'source' && n.parent && /^(audio|video)$/.test(n.parent.tag)) kids.push(this.group('מקור של ' + (n.parent.tag === 'audio' ? 'שמע' : 'וידאו'), h('p', { class: 'hint' }, 'ההגדרות — באלמנט שמעליו.'), this.btn('↑ בחר את ה-<' + n.parent.tag + '>', () => { c.select(n.parent); c.reveal(n.parent); })));
      else kids.push(...this.text(n));
      if (tag === 'button') kids.push(this.button(n));
      if (link) kids.push(...this.link(link, link !== n));
      else if (!/^(html|head|body|title|meta|link|script|style|base|template|source|track|option)$/.test(tag) && !this.inHead(n)) kids.push(this.group('קישור', h('p', { class: 'hint' }, 'האלמנט הזה אינו קישור.'), this.btn('🔗 הוסף קישור', () => this.addLink(n), ro, 'add-link')));
      const bg = this.background(n); if (bg) kids.push(bg);
      this.host.replaceChildren(...kids.filter(Boolean));
      for (const e of this.host.querySelectorAll('input, select, textarea, button[data-w]')) if (ro) e.disabled = true;
    }
    inHead(n) { for (let x = n; x; x = x.parent) if (x.tag === 'head') return true; return false; }
    group(title, ...kids) { return h('section', { class: 'ct-group', 'data-group': title }, h('h3', { class: 'dt-h' }, title), ...kids); }
    btn(text, fn, off, key) { return h('button', { type: 'button', class: 'btn sm', disabled: !!off, 'data-w': key || true, onclick: fn }, text); }
    row(label, control, hint) { return h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, label), control, hint ? h('span', { class: 'hint ct-hint' }, hint) : null); }
    input(value, onchange, o = {}) { const e = h('input', { type: o.type || 'text', class: 'ct-in', dir: o.dir || 'auto', placeholder: o.placeholder || null, 'data-f': o.key || null, min: o.min ?? null, inputmode: o.type === 'number' ? 'numeric' : null }); e.value = value ?? ''; e.addEventListener('change', () => onchange(e.value.trim(), e)); return e; }
    check(label, on, onchange, key) { const e = h('input', { type: 'checkbox', 'data-f': key || null }); e.checked = !!on; e.addEventListener('change', () => onchange(e.checked)); return h('label', { class: 'ct-check' }, e, h('span', {}, label)); }
    select(value, options, onchange, key) { const e = h('select', { class: 'ct-in', 'data-f': key || null }, options.map(([v, t]) => h('option', { value: v }, t))); e.value = value; e.addEventListener('change', () => onchange(e.value)); return e; }

    // ── טקסט ──
    text(n) {
      const out = [], host = T.hostFor(n), can = host && !T.blocker(this.canvas.m, host);
      if (host) {
        const body = [h('p', { class: 'ct-say' }, host === n ? 'הטקסט נערך במקום, על הקנבס: לחיצה כפולה, Enter או F2.' : 'הטקסט שבתוך האלמנט נערך במקום, על הקנבס.'),
          this.btn('✏️ ערוך את הטקסט', () => this.begin(host), !can, 'edit-text')];
        if (!can) body.push(h('p', { class: 'dt-note' }, T.blocker(this.canvas.m, host).message), this.btn('</> פתח בקוד', () => this.openEl(host)));
        out.push(this.group('טקסט', ...body));
      } else if (!/^(html|head|body)$/.test(n.tag) && !this.inHead(n)) out.push(this.group('טקסט', h('p', { class: 'hint' }, 'באלמנט הזה אין טקסט לעריכה במקום.')));
      const tf = this.tagField && this.tagField(n);                       // V4: כל תגית — רשימה לפי הקשר והקלדה חופשית, דרך KolStruct.retag
      if (tf) out.push(this.group('תגית', tf));
      else if (TEXT_TAGS.includes(n.tag) && !n.implied)
        out.push(this.group('תגית', this.row('סוג האלמנט', this.select(n.tag, TEXT_TAGS.map(t => [t, '<' + t + '>' + ({ h1: ' — כותרת ראשית', h2: ' — כותרת משנה', p: ' — פסקה', span: ' — קטע בתוך שורה', div: ' — מכל' }[t] || '')]), v => { if (v !== n.tag) this.act('תגית ' + v + ' · ' + this.lab(n), m => K.html.retag(m, n, v), n); }, 'tag'))));
      return out;
    }

    // ── תמונה ──
    image(n) {
      const c = this.canvas, e = c.elOf(n), src = this.attr(n, 'src') || '', path = this.resolve(src), f = path ? this.files().find(x => x.path === path) : null, out = [];
      const site = !path && /^(https?:)?\/\//i.test(src);
      const pv = h('div', { class: 'ct-preview', 'data-f': 'focus', title: 'לחיצה על התמונה קובעת את נקודת המיקוד (object-position)' });
      const url = this.url(src);
      const lost = () => h('div', { class: 'ct-missing' }, src ? '⚠️ תמונה חסרה' : 'אין קובץ', src ? h('code', { dir: 'ltr' }, src) : null);
      if (!src || !url) pv.append(lost());
      else { const im = h('img', { alt: '' }); im.addEventListener('error', () => im.replaceWith(lost())); im.src = url; pv.append(im); }   // אלמנט שבקנבס נוצר מחדש אחרי כל שינוי, ולכן "חסרה" נקבע לפי הטעינה של התצוגה עצמה
      const style = this.attr(n, 'style') || '', pos = styleGet(style, 'object-position');
      if (pos) { const mt = /^([\d.]+)%\s+([\d.]+)%$/.exec(pos); if (mt) pv.append(h('span', { class: 'ct-focus', style: 'left:' + mt[1] + '%;top:' + mt[2] + '%' })); }
      pv.addEventListener('click', ev => { const im = pv.querySelector('img'); if (!im || !this.canWrite()) return; const r = im.getBoundingClientRect(); const x = Math.round(Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width)) * 100), y = Math.round(Math.max(0, Math.min(1, (ev.clientY - r.top) / r.height)) * 100); this.setStyle(n, 'object-position', x + '% ' + y + '%', 'נקודת מיקוד · ' + this.lab(n)); });
      const name = src.split(/[?#]/)[0].split('/').pop() || '—';
      const meta = [name, e && e.naturalWidth ? e.naturalWidth + '×' + e.naturalHeight : null, f ? kb(f.size) : null, site ? 'באתר (טרם הובאה לפרויקט)' : null].filter(Boolean).join(' · ');
      out.push(this.group('תמונה', h('p', { class: 'ct-say' }, 'זו תמונה (אלמנט) — אפשר להחליף אותה, להסיר אותה ולשנות את הגודל שלה כאן.'), pv, h('p', { class: 'hint ct-meta', dir: 'auto' }, meta),
        h('div', { class: 'ct-btns' }, this.btn('🖼️ החלף', () => this.replaceImage(n), false, 'replace'), this.btn('🗑️ הסר', () => this.remove(), false, 'remove')),
        K.attrOf(n, 'srcset') ? h('p', { class: 'dt-note' }, 'לתמונה יש srcset (גדלים מותאמים). החלפה תסיר אותו — גדלים מותאמים: V10.') : null));
      const alt = this.attr(n, 'alt');
      out.push(this.group('טקסט חלופי', this.row('alt', this.input(alt || '', v => this.setAttr(n, 'alt', v, 'טקסט חלופי · ' + this.lab(n)), { key: 'alt', placeholder: 'מה רואים בתמונה' }), !alt ? '⚠️ ריק — קורא מסך לא יאמר מה יש בתמונה (לתמונת קישוט — משאירים ריק)' : null)));
      const fig = n.parent && n.parent.tag === 'figure' ? n.parent : n.parent && n.parent.tag === 'a' && n.parent.parent && n.parent.parent.tag === 'figure' ? n.parent.parent : null, cap = fig && fig.kids.find(k => k.tag === 'figcaption');
      let capText = ''; if (cap) { try { capText = K.textOf(c.m, cap); } catch { capText = ''; } }
      out.push(this.group('כיתוב', this.row('מתחת לתמונה', this.input(capText, v => this.caption(n, fig, cap, v), { key: 'caption', placeholder: 'בלי כיתוב' }), 'נכתב כ-<figure> + <figcaption>; מחיקת הטקסט מסירה אותם')));
      const w = styleGet(style, 'width') || (this.attr(n, 'width') ? this.attr(n, 'width') + 'px' : ''), fit = styleGet(style, 'object-fit');
      out.push(this.group('גודל ותצוגה',
        this.row('רוחב (px)', this.input(/^[\d.]+px$/.test(w) ? String(parseFloat(w)) : '', v => this.resize(n, v), { type: 'number', key: 'width', min: 1, dir: 'ltr', placeholder: e ? String(Math.round(e.getBoundingClientRect().width)) : '' }), 'נכתב בתכונת style של התמונה. גם בגרירת הפינה בקנבס (Shift — בלי לשמור יחס). ריק — הגודל הטבעי'),
        this.row('התאמה (object-fit)', this.select(fit, [['', 'ברירת מחדל'], ['cover', 'cover — ממלא וחותך'], ['contain', 'contain — כולו נראה'], ['fill', 'fill — נמתח'], ['none', 'none — גודל טבעי'], ['scale-down', 'scale-down']], v => this.setStyle(n, 'object-fit', v || null, 'התאמת תמונה · ' + this.lab(n)), 'fit'), pos ? 'נקודת מיקוד: ' + pos : 'נקודת מיקוד — לחיצה על התצוגה המקדימה'),
        pos ? this.btn('איפוס נקודת המיקוד', () => this.setStyle(n, 'object-position', null, 'איפוס נקודת מיקוד · ' + this.lab(n))) : null,
        this.check('טעינה עצלה (loading="lazy")', this.attr(n, 'loading') === 'lazy', on => this.setAttr(n, 'loading', on ? 'lazy' : null, 'טעינה עצלה · ' + this.lab(n)), 'lazy')));
      return out;
    }
    remove() { this.removeEl(); }
    async replaceImage(n) {
      const path = await this.pick('image', 'בחירת תמונה'); if (!path) return;
      const cur = this.n; if (!cur || cur.tag !== 'img') return;
      const had = !!K.attrOf(cur, 'srcset');
      const ok = this.act('החלפת תמונה · ' + this.lab(cur), m => ({ patches: K.html.setAttr(m, cur, 'src', this.rel(path)).patches.concat(had ? K.html.removeAttr(m, cur, 'srcset').patches : []) }), cur);
      if (ok && had) this.note('התמונה הוחלפה, וה-srcset שלה הוסר (הוא הצביע על הקבצים הקודמים). גדלים מותאמים — V10.');
    }
    caption(n, fig, cap, text) {
      const c = this.canvas, lab = this.lab(n), html = T.escText(text);
      if (cap && text) return this.act('כיתוב · ' + lab, m => K.html.setText(m, cap, text), cap);
      if (cap && !text) {
        const bare = fig.attrs.length === 0 && fig.kids.length === 2 && fig.et;
        return this.act('הסרת כיתוב · ' + lab, m => ({ patches: K.html.remove(m, cap).patches.concat(bare ? K.html.unwrap(m, fig).patches : []) }), cap);
      }
      if (!text) return true;
      if (fig) return this.act('כיתוב · ' + lab, m => K.html.insert(m, fig, null, '<figcaption>' + html + '</figcaption>'), fig);
      const inner = n.parent && n.parent.tag === 'a' && n.parent.kids.length === 1 ? n.parent : n;
      return this.act('כיתוב · ' + lab, m => { const e = K.endOf(m, inner); return { patches: [{ from: inner.from, to: inner.from, text: '<figure>' }, { from: e, to: e, text: '<figcaption>' + html + '</figcaption></figure>' }] }; }, n);
    }
    resize(n, v) {
      if (v === '') return this.setStyle(n, 'width', null, 'איפוס רוחב · ' + this.lab(n));
      const px = Math.round(parseFloat(v)); if (!(px > 0)) return this.note('רוחב: מספר חיובי, בפיקסלים', true);
      const cur = this.attr(n, 'style') || '', next = styleEdit(styleEdit(cur, 'width', px + 'px'), 'height', styleGet(cur, 'height') ? 'auto' : null);
      return this.setAttr(n, 'style', next, 'רוחב ' + px + 'px · ' + this.lab(n));
    }
    svg(n) {
      return [this.group('SVG שכתוב בתוך הדף', h('p', { class: 'ct-say' }, 'זה ציור SVG שהקוד שלו כתוב בתוך העמוד. אפשר להחליף אותו בקובץ תמונה מהספרייה (ייכתב <img>), או לערוך את הקוד שלו.'),
        h('div', { class: 'ct-btns' }, this.btn('🖼️ החלף בקובץ מהספרייה', async () => { const path = await this.pick('image', 'בחירת תמונה'); const cur = this.n; if (!path || !cur || cur.tag !== 'svg') return; this.act('החלפת SVG בתמונה · ' + this.lab(cur), m => { if (cur.implied || cur.broken) throw new K.SrcError('ה-SVG אינו ניתן להחלפה מכאן — עורכים בקוד', 'broken'); return { patches: [{ from: cur.from, to: K.endOf(m, cur), text: '<img src="' + escA(this.rel(path)) + '" alt="">' }] }; }, cur); }, false, 'replace'),
          this.btn('</> פתח בקוד', () => this.openEl(n)), this.btn('🗑️ הסר', () => this.remove(), false, 'remove')))];
    }

    // ── כפתור ──
    /** מה הכפתור עושה נקבע בקוד של העמוד. מה שהקוד קורא מהכפתור (data-…, aria-controls) — נערך כאן, כדי שאפשר יהיה לשנות לאן הוא מוביל. */
    button(n) {
      const c = this.canvas, ids = K.all(c.m).map(x => this.attr(x, 'id')).filter(Boolean), lab = this.lab(n), rows = [];
      for (const a of n.attrs) {
        if (!/^(data-|aria-controls$|form$|value$|name$)/.test(a.name) || a.from < 0) continue;
        const set = v => this.setAttr(n, a.name, v, a.name + ' · ' + lab);
        rows.push(this.row(a.name, ids.includes(a.value) ? this.select(a.value, ids.map(i => [i, '#' + i]), set, a.name) : this.input(a.value, set, { key: a.name, dir: 'ltr' }), ids.includes(a.value) ? 'מזהה של אלמנט בעמוד' : null));
      }
      return this.group('כפתור', h('p', { class: 'ct-say', 'data-f': 'button-say' }, 'הטקסט של הכפתור נערך במקום. מה הכפתור עושה כשלוחצים עליו נקבע בקוד (JavaScript) של העמוד — לא כאן.'),
        rows.length ? h('p', { class: 'hint' }, 'התכונות שהקוד של העמוד קורא מהכפתור (למשל: איזה חלון הוא פותח):') : null, ...rows, this.btn('</> פתח בקוד', () => this.openEl(n)));
    }

    // ── קישור ──
    linkOf(n) { for (let x = n; x; x = x.parent) if (x.tag === 'a') return x; return null; }
    kindOf(href) {
      if (/^tel:/i.test(href)) return 'tel'; if (/^mailto:/i.test(href)) return 'mail'; if (href[0] === '#') return 'anchor';
      const p = this.resolve(href); if (p && this.files().some(f => f.path === p)) return /\.html?$/i.test(p) ? 'page' : 'file';
      return 'url';
    }
    link(a, outer) {
      const c = this.canvas, href = this.attr(a, 'href') || '', kind = this.kindOf(href), lab = this.lab(a), set = (v, what) => this.setAttr(a, 'href', v, (what || 'יעד קישור') + ' · ' + lab);
      const KINDS = [['url', 'כתובת'], ['page', 'עמוד בפרויקט'], ['anchor', 'עוגן בעמוד'], ['file', 'קובץ מהספרייה'], ['tel', 'טלפון'], ['mail', 'מייל']];
      let ctl;
      if (kind === 'page') { const pages = this.files().filter(f => f.kind === 'file' && /\.html?$/i.test(f.path)).map(f => f.path), cur = this.resolve(href); ctl = this.select(cur, pages.map(p => [p, p]), v => set(this.rel(v)), 'href'); }
      else if (kind === 'anchor') { const ids = K.all(c.m).map(x => this.attr(x, 'id')).filter(Boolean), cur = href.slice(1); ctl = this.select(cur, (ids.includes(cur) ? [] : [[cur, cur ? '#' + cur + ' — אין מזהה כזה בעמוד' : '— בוחרים —']]).concat(ids.map(i => [i, '#' + i])), v => set('#' + v), 'href'); }
      else if (kind === 'file') ctl = h('div', { class: 'ct-btns' }, h('code', { dir: 'ltr' }, href), this.btn('בחר קובץ', async () => { const p = await this.pick('any', 'בחירת קובץ'); if (p) { const cur = this.linkOf(this.n); if (cur) this.setAttr(cur, 'href', this.rel(p), 'יעד קישור · ' + this.lab(cur)); } }, false, 'pick-file'));
      else if (kind === 'tel') ctl = this.input(href.replace(/^tel:/i, ''), v => set('tel:' + v.replace(/[^\d+*#]/g, '')), { key: 'href', dir: 'ltr', placeholder: '+972…' });
      else if (kind === 'mail') ctl = this.input(href.replace(/^mailto:/i, ''), v => set('mailto:' + v), { key: 'href', dir: 'ltr', placeholder: 'name@example.com' });
      else ctl = this.input(href, v => { if (!T.SAFE_HREF.test(v)) return this.note('כתובת של קישור אינה יכולה להיות קוד (javascript:)', true); set(v); }, { key: 'href', dir: 'ltr', placeholder: 'https://…' });
      const change = v => {
        if (v === kind) return;
        if (v === 'page') { const pages = this.files().filter(f => f.kind === 'file' && /\.html?$/i.test(f.path) && f.path !== this.file().path); const dir = this.file().path.replace(/[^/]*$/, ''); pages.sort((x, y) => (y.path.startsWith(dir) ? 1 : 0) - (x.path.startsWith(dir) ? 1 : 0) || x.path.localeCompare(y.path)); /* קודם עמודים מאותה תיקייה */ set(pages[0] ? this.rel(pages[0].path) : this.rel(this.file().path)); }
        else if (v === 'anchor') { const id = K.all(c.m).map(x => this.attr(x, 'id')).find(Boolean); set('#' + (id || '')); }
        else if (v === 'file') this.pick('any', 'בחירת קובץ').then(p => { const cur = this.linkOf(this.n); if (p && cur) this.setAttr(cur, 'href', this.rel(p), 'יעד קישור · ' + this.lab(cur)); else this.draw(); });
        else set(v === 'tel' ? 'tel:' : v === 'mail' ? 'mailto:' : 'https://');
      };
      const blank = this.attr(a, 'target') === '_blank';
      return [this.group(outer ? 'קישור (האלמנט שבתוך <a>)' : 'קישור',
        this.row('לאן', this.select(kind, KINDS, change, 'kind')), this.row('יעד', ctl),
        this.check('בחלון חדש', blank, on => this.newWindow(a, on), 'blank'),
        h('div', { class: 'ct-btns' }, this.btn('↗ פתח את הקישור', () => this.openLink(href), !href, 'open-link'), this.btn('הסר קישור', () => this.act('הסרת קישור · ' + lab, m => K.html.unwrap(m, a), a), false, 'unlink'), outer ? this.btn('↑ בחר את הקישור', () => { c.select(a); c.reveal(a); }) : null),
        h('p', { class: 'hint' }, 'לחיצה על קישור בקנבס בוחרת אותו — היא אינה מנווטת.'))];
    }
    newWindow(a, on) {
      const lab = 'בחלון חדש · ' + this.lab(a), rel = K.attrOf(a, 'rel'), tg = K.attrOf(a, 'target');
      if (on) return this.act(lab, m => {
        const r = K.html.setAttr(m, a, 'target', '_blank');
        if (!rel) { if (tg) r.patches.push(...K.html.setAttr(m, a, 'rel', 'noopener', { after: 'target' }).patches); else r.patches[0].text += ' rel="noopener"'; }
        else if (!/\bnoopener\b/.test(rel.value)) r.patches.push(...K.html.setAttr(m, a, 'rel', (rel.value + ' noopener').trim()).patches);
        return r;
      }, a);
      if (!tg) return true;
      return this.act(lab, m => ({ patches: K.html.removeAttr(m, a, 'target').patches.concat(rel && rel.value.trim() === 'noopener' ? K.html.removeAttr(m, a, 'rel').patches : []) }), a);
    }
    addLink(n) { const path = K.pathOf(n); if (this.act('הוספת קישור · ' + this.lab(n), m => K.html.wrap(m, n, 'a', { href: '#' }), n)) { const a = K.byPath(this.canvas.m, path); if (a) { this.canvas.select(a); this.draw(); } } }
    openLink(href) {
      if (!href || !T.SAFE_HREF.test(href)) return;
      const u = /^(https?:|mailto:|tel:)/i.test(href) ? href : href[0] === '#' ? '' : this.url(href);
      if (!u) return this.note(href[0] === '#' ? 'עוגן בעמוד הזה: ' + href : 'אין לקישור כתובת לפתיחה');
      window.open(u, '_blank', 'noopener');
    }

    // ── שמע ווידאו ──
    media(n) {
      const c = this.canvas, vid = n.tag === 'video', what = vid ? 'וידאו' : 'שמע', lab = this.lab(n), srcNode = K.attrOf(n, 'src') ? n : n.kids.find(k => k.tag === 'source') || n, src = this.attr(srcNode, 'src') || '';
      const setSrc = v => { const cur = this.n; if (!cur) return; const t = K.attrOf(cur, 'src') ? cur : cur.kids.find(k => k.tag === 'source') || cur; this.setAttr(t, 'src', v, 'קובץ ' + what + ' · ' + this.lab(cur)); };
      const player = h('div', { class: 'ct-player' });
      const play = this.btn('▶️ השמע כאן', () => { const u = this.url(this.attr(srcNode, 'src') || ''); if (!u) return this.note('אין קובץ להשמעה'); const p = document.createElement(vid ? 'video' : 'audio'); p.controls = true; p.src = u; p.className = 'ct-media'; player.replaceChildren(p); p.play().catch(() => {}); }, !src, 'play');
      play.disabled = !src; play.removeAttribute('data-w');
      const flag = (name, label) => this.check(label, !!K.attrOf(n, name), on => this.setAttr(n, name, on ? '' : null, label + ' · ' + lab, true), name);
      const out = [this.group(what, h('p', { class: 'ct-say' }, 'בקנבס ה' + what + ' אינו מתנגן מעצמו. ▶️ משמיע כאן, בפאנל.'),
        this.row('קובץ', this.input(src, v => setSrc(v), { key: 'src', dir: 'ltr', placeholder: 'נתיב או כתובת' })),
        h('div', { class: 'ct-btns' }, this.btn('🗂️ בחר מהספרייה', async () => { const p = await this.pick(vid ? 'video' : 'audio', 'בחירת ' + what); if (p) setSrc(this.rel(p)); }, false, 'pick-media'), play, this.btn('🗑️ הסר', () => this.remove(), false, 'remove')), player),
        this.group('התנהגות', flag('controls', 'פקדים (ניגון, עוצמה)'), flag('loop', 'לולאה'), flag('muted', 'מושתק'), flag('autoplay', 'ניגון אוטומטי'),
          K.attrOf(n, 'autoplay') && !K.attrOf(n, 'muted') ? h('p', { class: 'dt-note' }, 'דפדפנים חוסמים ניגון אוטומטי עם קול. כדי שיתנגן לבד — מסמנים גם "מושתק".') : null)];
      if (vid) {
        const poster = this.attr(n, 'poster') || '';
        out.push(this.group('תמונת פתיחה (poster)', poster ? h('p', { class: 'hint', dir: 'ltr' }, poster) : h('p', { class: 'hint' }, 'אין'),
          h('div', { class: 'ct-btns' }, this.btn('🖼️ בחר תמונה', async () => { const p = await this.pick('image', 'בחירת תמונת פתיחה'); const cur = this.n; if (p && cur) this.setAttr(cur, 'poster', this.rel(p), 'תמונת פתיחה · ' + this.lab(cur)); }, false, 'poster'), poster ? this.btn('הסר', () => this.setAttr(n, 'poster', null, 'הסרת תמונת פתיחה · ' + lab), false, 'poster-off') : null)));
      }
      return out;
    }
    frame(n) {
      const lab = this.lab(n), src = this.attr(n, 'src') || '', num = name => this.input(this.attr(n, name) || '', v => this.setAttr(n, name, v === '' ? null : String(Math.round(parseFloat(v)) || ''), (name === 'width' ? 'רוחב' : 'גובה') + ' הטמעה · ' + lab), { type: 'number', key: name, min: 1, dir: 'ltr' });
      const tube = /(^|\.)(youtube(-nocookie)?\.com|youtu\.be|vimeo\.com)$/i.test((() => { try { return new URL(src, 'https://x.invalid/').hostname; } catch { return ''; } })());
      return [this.group('הטמעה (iframe)', h('p', { class: 'ct-say' }, 'בעורך ההטמעה אינה נטענת — מוצגת מסגרת עם הכתובת. היא נטענת בעמוד עצמו וב"▶️ תצוגה חיה".'),
        this.row('כתובת', this.input(src, v => { if (v && !/^(https?:)?\/\/|^[^:]*$/i.test(v)) return this.note('כתובת של הטמעה: https://…', true); this.setAttr(n, 'src', v || null, 'כתובת הטמעה · ' + lab); }, { key: 'src', dir: 'ltr', placeholder: 'https://…' })),
        this.row('רוחב', num('width')), this.row('גובה', num('height')),
        this.row('כותרת (title)', this.input(this.attr(n, 'title') || '', v => this.setAttr(n, 'title', v || null, 'כותרת הטמעה · ' + lab), { key: 'title', placeholder: 'מה יש במסגרת — לקורא מסך' })),
        tube ? h('p', { class: 'dt-note', 'data-f': 'sandbox-note' }, '⚠️ העמוד מוגש בתוך ארגז חול מבודד, ונגן מוטמע (יוטיוב, וימאו) עלול שלא לעבוד בו. אם הנגן אינו מתנגן בעמוד החי — מקשרים לסרטון במקום להטמיע אותו.') : null,
        h('div', { class: 'ct-btns' }, this.btn('🗑️ הסר', () => this.remove(), false, 'remove')))];
    }

    // ── תמונת רקע: נאמר במפורש מה V2 אינה עושה ──
    background(n) {
      const c = this.canvas, win = c.frame.contentWindow;
      let at = null;
      for (let x = n; x && !at; x = x.parent) { const e = c.elOf(x); if (!e || !e.isConnected || e.nodeType !== 1) continue; let bg = ''; try { bg = win.getComputedStyle(e).backgroundImage; } catch { bg = ''; } if (/url\(/.test(bg)) at = x; }
      if (!at) return null;
      let decl = null;
      try { const info = this.details.collect(at); for (const r of info.rules) for (const d of r.decls) if (!decl && /^background(-image)?$/i.test(d.prop) && /url\(/.test(d.value) && !r.external) decl = { path: r.path, line: d.line || r.line || 1, sel: r.sel }; } catch { decl = null; }
      const self = at === n;
      return h('section', { class: 'ct-group ct-bg', 'data-group': 'תמונת רקע' }, h('h3', { class: 'dt-h' }, 'תמונת רקע'),
        h('p', { class: 'ct-say' }, self ? 'לאלמנט הזה (' : 'האלמנט יושב על ', h('code', { dir: 'ltr' }, this.lab(at)), self ? ') יש תמונת רקע. היא אינה אלמנט — היא כתובה ב-CSS.' : ', שיש לו תמונת רקע. היא אינה אלמנט — היא כתובה ב-CSS.'),
        h('p', { class: 'dt-note', 'data-f': 'bg-note' }, '❌ החלפה והסרה של תמונת רקע עדיין אינן על המסך — הן מגיעות בשלב "רקע" (V5). עד אז: בקוד.'),
        h('div', { class: 'ct-btns' }, decl ? this.btn('</> פתח את ההצהרה בקוד (' + decl.path + ':' + decl.line + ')', () => this.openAt(decl.path, decl.line), false, 'bg-code') : null, self ? null : this.btn('↑ בחר את ' + this.lab(at), () => { c.select(at); c.reveal(at); })));
    }
  }

  window.KolContent = { Content, styleEdit, styleGet, IMG_EXT, AUD_EXT, VID_EXT };
})();
