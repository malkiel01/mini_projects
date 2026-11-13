/**
 * העורך החזותי — 📄 עמודים (פרוסה V15 §2.3–§2.6): בורר העמוד בסרגל, מסך "📄 עמודים" (יצירה, שכפול, שינוי כתובת עם עדכון
 * קישורים, מחיקה לסל, עמוד ראשי, דף 404, סדר בגרירה), והעזרים של בורר הקישור ("עמוד בפרויקט" + עוגנים, קישור שבור באדום).
 *
 * עמוד = קובץ HTML רגיל בעץ. כל פעולה היא פעולת שרת (lib/project_ops.php) שכותבת לאותם קבצים שבעץ ובקוד, עם היסטוריה —
 * אין כאן רשימה משלו. הגבול: כתובת = SLUG בלבד (נבדק כאן ובשרת — 400), ולכן אין נתיב שיוצא מ-/lexicon/.
 * כל שם, כתובת ותוכן מהעמודים מוצגים כטקסט (h / textContent). התצוגה המקדימה — iframe sandbox בלי סקריפטים.
 *
 * החלק הטהור (slugify / prettyRel / pageOf) רץ גם ב-Node — tests/editor/pages.mjs.
 */
(function (g) {
  'use strict';
  const SLUG = /^[a-z0-9-]+(\/[a-z0-9-]+)*$/;
  const HEB = { א: '', ב: 'b', ג: 'g', ד: 'd', ה: 'h', ו: 'o', ז: 'z', ח: 'ch', ט: 't', י: 'y', כ: 'k', ך: 'k', ל: 'l', מ: 'm', ם: 'm', נ: 'n', ן: 'n', ס: 's', ע: '', פ: 'p', ף: 'f', צ: 'tz', ץ: 'tz', ק: 'k', ר: 'r', ש: 'sh', ת: 't' };
  /** שם ← כתובת מוצעת: אותיות לטיניות קטנות, ספרות ומקף; עברית — בתעתיק פשוט. תמיד עומדת ב-SLUG (או ריקה). */
  function slugify(name) {
    const s = String(name || '').trim().toLowerCase().replace(/[֑-ׇ]/g, '').replace(/[א-ת]/g, c => HEB[c] ?? '').normalize('NFKD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/-{2,}/g, '-').slice(0, 60).replace(/-+$/, '');
    return s;
  }
  /** about/index.html ← "about/"; index.html ← ""; x.html ← "x.html". */
  const urlOf = p => (p === 'index.html' ? '' : /(^|\/)index\.html$/.test(p) ? p.slice(0, -'index.html'.length) : p);
  /** קישור יחסי ויפה מקובץ אל עמוד: מ-index.html אל about/index.html ← "about/"; מ-about/ אל index.html ← "../". */
  function prettyRel(fromFile, toPath) {
    const from = fromFile.split('/').slice(0, -1), to = urlOf(toPath).split('/'), dirTarget = to[to.length - 1] === '';
    if (dirTarget) to.pop();
    let i = 0; while (i < from.length && i < to.length && from[i] === to[i]) i++;
    const rest = to.slice(i).map(encodeURIComponent).join('/');
    const out = '../'.repeat(from.length - i) + rest + (dirTarget && rest ? '/' : '');
    return out || './';
  }
  /** לאן קישור מפנה (נתיב בפרויקט) — או null לכתובת חיצונית / עוגן. base — "/lexicon/". */
  function resolveRef(fromFile, href, base) {
    if (!href || /^([a-z][a-z0-9+.-]*:|\/\/|#)/i.test(href)) return null;
    let clean = href.split(/[?#]/)[0];
    if (clean[0] === '/') { if (!base || !(clean + '/').startsWith(base)) return null; clean = clean.slice(base.length); }
    const dir = clean === href.split(/[?#]/)[0] && href[0] !== '/' ? fromFile.split('/').slice(0, -1) : [];
    const out = [...dir];
    for (const s of clean.split('/')) { if (s === '..') { if (!out.length) return null; out.pop(); } else if (s && s !== '.') out.push(s); }
    let p; try { p = decodeURIComponent(out.join('/')); } catch { p = out.join('/'); }
    return { path: p, dir: clean === '' || clean.endsWith('/') };
  }
  /** העמוד שהקישור מפנה אליו, מתוך רשימת הנתיבים; undefined — לא קישור לעמוד; null — עמוד שאינו קיים (שבור). */
  function pageOf(fromFile, href, paths, base) {
    const r = resolveRef(fromFile, href, base); if (!r) return undefined;
    const cands = r.path === '' ? ['index.html'] : r.dir ? [r.path + '/index.html'] : [r.path, r.path + '/index.html'];
    for (const c of cands) if (paths.includes(c)) return c;
    const pretty = r.dir || !/\.[a-z0-9]{1,6}$/i.test(r.path);
    return pretty || /\.html?$/i.test(r.path) ? null : undefined;
  }

  const hh = (...a) => g.h(...a);
  class Pages {
    /** o: { api, note, current(), openPath(path), pvUrl(path), phone(), canPublish(), live(path), files(), reload(), anchor (סרגל), overlay (מסך מלא), sheet } */
    constructor(o) { this.o = o; this.data = null; this.sel = null; this.btn = hh('button', { type: 'button', class: 'btn sm ed-pagebtn', 'data-pages-btn': true, 'aria-haspopup': 'true', 'aria-expanded': 'false', title: 'העמודים בפרויקט — מעבר, חיפוש, עמוד חדש', onclick: () => this.picker() }, '📄 ', hh('span', { class: 'ed-pagebtn-t' }, '…'), ' ▾'); }
    get paths() { return (this.data?.pages || []).map(p => p.path); }
    async load() {
      try { this.data = await this.o.api('pages_list'); } catch (e) { this.data = this.data || { pages: [], base: '/lexicon/', broken: [], moves: [] }; this.err = e.message; }
      this.drawBtn(); return this.data;
    }
    byPath(p) { return (this.data?.pages || []).find(x => x.path === p) || null; }
    label(p) { return p ? (p.entry ? '⭐ ' : '') + (p.is404 ? '🚫 ' : '') + (p.title || urlOf(p.path) || 'index') : ''; }
    drawBtn() {
      const cur = this.o.current(), p = this.byPath(cur), t = this.btn.querySelector('.ed-pagebtn-t'), live = this.o.liveTitle ? this.o.liveTitle() : '';
      t.textContent = live || (p ? (p.title || urlOf(p.path) || 'index') : cur ? cur.replace(/\.html?$/, '') : '…');   // הכותרת מהקובץ הפתוח — מתעדכנת עם הטופס
      this.btn.title = 'העמוד: ' + (cur || '') + ' — מעבר, חיפוש, עמוד חדש';
    }
    // ── הבורר בסרגל (§2.3) ──
    async picker() {
      if (this.pop) return this.closePicker();
      await this.load();
      const q = hh('input', { type: 'search', class: 'pg-search', placeholder: 'חיפוש עמוד…', 'aria-label': 'חיפוש עמוד', 'data-pg-search': true });
      const list = hh('div', { class: 'pg-plist', role: 'listbox', 'aria-label': 'העמודים' });
      const draw = () => {
        const t = q.value.trim().toLowerCase(), cur = this.o.current();
        list.replaceChildren(...this.data.pages.filter(p => !t || (p.title + ' ' + p.path).toLowerCase().includes(t)).map(p => hh('button', { type: 'button', role: 'option', class: 'pg-opt', 'aria-selected': String(p.path === cur), 'data-pg-open': p.path, onclick: () => { this.closePicker(); this.go(p.path); } },
          hh('b', {}, this.label(p)), hh('small', {}, hh('bdi', { dir: 'ltr' }, this.data.base + urlOf(p.path))))));
        if (!list.children.length) list.append(hh('p', { class: 'muted' }, 'אין עמוד כזה'));
      };
      q.addEventListener('input', draw);
      q.addEventListener('keydown', e => { if (e.key === 'Enter') { const b = list.querySelector('[data-pg-open]'); if (b) b.click(); } if (e.key === 'Escape') this.closePicker(); });
      draw();
      const pub = this.o.canPublish();
      const foot = hh('div', { class: 'pg-pfoot' }, hh('button', { type: 'button', class: 'btn sm', 'data-pg-new': true, disabled: !pub || null, title: pub ? 'עמוד חדש' : 'יצירת עמוד — למפרסם ומעלה', onclick: () => { this.closePicker(); this.create(); } }, '➕ עמוד חדש'),
        hh('button', { type: 'button', class: 'btn sm', 'data-pg-manage': true, onclick: () => { this.closePicker(); this.manager(); } }, '📄 כל העמודים'),
        this.o.headSettings ? hh('button', { type: 'button', class: 'btn sm', 'data-pg-head': true, onclick: () => { this.closePicker(); this.o.headSettings(); } }, '⚙️ הגדרות העמוד') : null,
        // P2 (E2E ⚪14): בטלפון ▶️ אינו בסרגל (T2) — הקשה אחת מכאן, במקום שלוש דרך "כל העמודים"
        this.o.phone() && this.o.current() ? hh('button', { type: 'button', class: 'btn sm', 'data-pg-live': true, onclick: () => { const cur = this.o.current(); this.closePicker(); this.o.live(cur); } }, '▶️ תצוגה חיה') : null);
      const box = hh('div', { class: 'pg-pop', role: 'dialog', 'aria-label': 'בחירת עמוד', 'data-pg-pop': true }, q, list, foot);
      if (this.o.phone()) { this.pop = this.o.sheet('📄 עמוד', box); this.pop.addEventListener('close', () => { this.pop = null; }, { once: true }); }
      else {
        this.pop = box; this.o.anchor().append(box);
        const r = this.btn.getBoundingClientRect(), host = this.o.anchor().getBoundingClientRect();
        box.style.top = (r.bottom - host.top + 4) + 'px'; box.style.insetInlineStart = Math.max(0, host.right - r.right) + 'px';
        this.btn.setAttribute('aria-expanded', 'true');
        this.outside = e => { if (!box.contains(e.target) && e.target !== this.btn && !this.btn.contains(e.target)) this.closePicker(); };
        setTimeout(() => document.addEventListener('pointerdown', this.outside, true));
      }
      q.focus();
    }
    closePicker() {
      if (!this.pop) return;
      if (this.pop.tagName === 'DIALOG') this.pop.close(); else this.pop.remove();
      this.pop = null; this.btn.setAttribute('aria-expanded', 'false'); document.removeEventListener('pointerdown', this.outside, true);
    }
    /** מעבר: העמוד הנוכחי נשמר, והבא נפתח באותו מכשיר. */
    async go(path) { if (path === this.o.current()) return; await this.o.openPath(path); this.drawBtn(); if (this.mgr) this.drawMgr(); }

    // ── 📄 עמודים (§2.4) ──
    async manager() {
      await this.load();
      this.sel = this.sel && this.byPath(this.sel) ? this.sel : this.o.current();
      const close = () => { this.mgr?.remove(); this.mgr = null; this.o.onManager && this.o.onManager(false); };
      this.mgrClose = close;
      if (this.o.phone()) {
        const box = hh('div', { class: 'pg-mgr phone', 'data-pg-mgr': true });
        this.mgr = box; const dlg = this.o.sheet('📄 עמודים', box); dlg.addEventListener('close', () => { this.mgr = null; }, { once: true });
      } else {
        this.mgr = hh('section', { class: 'pg-mgr', 'data-pg-mgr': true, role: 'dialog', 'aria-label': '📄 עמודים' });
        this.o.overlay().append(this.mgr);
        this.mgr.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
      }
      this.o.onManager && this.o.onManager(true);
      this.drawMgr();
    }
    drawMgr() {
      if (!this.mgr) return;
      const d = this.data, pub = this.o.canPublish(), phone = this.o.phone();
      const list = hh('div', { class: 'pg-list', role: 'list', 'data-pg-list': true, 'aria-label': 'העמודים — גרירה לסידור' });
      d.pages.forEach((p, i) => {
        const row = hh('div', { class: 'pg-row' + (p.path === this.sel ? ' sel' : '') + (p.listed ? '' : ' unlisted'), role: 'listitem', 'data-i': i, 'data-pg-row': p.path, tabindex: '0', onclick: e => { if (e.target.closest('button')) return; this.sel = p.path; this.drawMgr(); },
          onkeydown: e => { if (e.key === 'Enter') { this.sel = p.path; this.drawMgr(); } } },
          hh('button', { type: 'button', class: 'icon-btn pg-drag', 'data-drag': true, 'aria-label': 'גרירה לסידור', title: 'גרירה לסידור' + (phone ? ' (או לחיצה ארוכה על השורה)' : ''), disabled: !pub || null }, '⇅'),
          hh('span', { class: 'pg-name' }, hh('b', {}, this.label(p)), hh('small', {}, hh('bdi', { dir: 'ltr' }, d.base + urlOf(p.path))), hh('small', { class: 'muted' }, (p.listed ? '' : 'לא ברשימה · ') + 'שונה ' + (g.when ? g.when(p.at) : p.at) + (p.by ? ' · ' + p.by : ''))),
          hh('button', { type: 'button', class: 'icon-btn', 'data-up': true, 'aria-label': 'למעלה', disabled: !pub || i === 0 || null, onclick: () => this.reorder(i, i - 1) }, '↑'),
          hh('button', { type: 'button', class: 'icon-btn', 'data-down': true, 'aria-label': 'למטה', disabled: !pub || i === d.pages.length - 1 || null, onclick: () => this.reorder(i, i + 1) }, '↓'));
        list.append(row);
      });
      if (pub) g.KolHead.dragList(list, (from, to) => this.reorder(from, to));
      const mode = hh('p', { class: 'hint pg-mode', 'data-pg-mode': d.mode }, d.mode === 'auto' ? 'הסדר: אוטומטי (pages: "auto" ב-kol.json — הראשי ראשון, ואז לפי כתובת). גרירה תשמור רשימה מפורשת.' : 'הסדר: רשימה מפורשת ב-kol.json (pages). עמוד חדש נוסף בסופה. ',
        d.mode === 'list' && pub ? hh('button', { type: 'button', class: 'linkish', 'data-pg-auto': true, onclick: () => this.order('auto') }, 'חזור לאוטומטי') : null);
      const p = this.byPath(this.sel) || d.pages[0];
      const detail = p ? this.detail(p, pub) : hh('p', { class: 'muted' }, 'אין עמודים');
      const head = hh('div', { class: 'pg-head' }, hh('h2', {}, '📄 עמודים (' + d.pages.length + ')'),
        hh('button', { type: 'button', class: 'btn sm primary', 'data-pg-new': true, disabled: !pub || null, title: pub ? '' : 'למפרסם ומעלה', onclick: () => this.create() }, '➕ עמוד חדש'),
        !d.pages.some(x => x.is404) ? hh('button', { type: 'button', class: 'btn sm', 'data-pg-404new': true, disabled: !pub || null, onclick: () => this.set404(null) }, '🚫 צור דף 404') : null,
        phone ? null : hh('button', { type: 'button', class: 'icon-btn pg-x', 'aria-label': 'סגירה', 'data-pg-close': true, onclick: () => this.mgrClose() }, '✕'));
      const broken = (d.broken || []).length ? hh('details', { class: 'pg-broken', 'data-pg-broken': true }, hh('summary', {}, '⛓️‍💥 ' + d.broken.length + ' קישורים שבורים לעמודים'), hh('ul', {}, d.broken.slice(0, 50).map(b => hh('li', {}, hh('code', { dir: 'ltr' }, b.href), ' · ', hh('span', { dir: 'ltr' }, b.path + ':' + b.line))))) : null;
      this.mgr.replaceChildren(...[head, pub ? null : hh('p', { class: 'warnbox' }, 'יצירה, שינוי כתובת, מחיקה וסדר — למפרסם ומעלה. כאן — מעבר ותצוגה.'), hh('div', { class: 'pg-cols' }, hh('div', { class: 'pg-side' }, mode, list, broken), detail)].filter(Boolean));   // replaceChildren(null) כותב "null"
      this.mgr.querySelector('.pg-row.sel')?.scrollIntoView({ block: 'nearest' });
    }
    detail(p, pub) {
      const d = this.data, links = (d.pages || []).length;
      const b = (label, fn, attr, off, cls) => hh('button', Object.assign({ type: 'button', class: 'btn sm ' + (cls || ''), disabled: off || null, onclick: fn }, attr), label);
      const url = this.o.pvUrl(p.path);
      return hh('div', { class: 'pg-detail', 'data-pg-detail': p.path },
        hh('div', { class: 'pg-dhead' }, hh('div', {}, hh('h3', {}, this.label(p)), hh('p', { class: 'pg-addr' }, hh('bdi', { dir: 'ltr' }, hh('code', {}, d.base + urlOf(p.path))), ' · ', hh('bdi', { dir: 'ltr', class: 'muted' }, p.path))),
          hh('div', { class: 'pg-acts' },
            b('🎨 פתח בעורך', () => { this.mgrClose && !this.o.phone() && this.mgrClose(); if (this.o.phone()) document.getElementById('sheet')?.close(); this.go(p.path); }, { 'data-pg-act': 'open' }, p.path === this.o.current(), 'primary'),
            b('▶️ תצוגה חיה', () => this.o.live(p.path), { 'data-pg-act': 'live' }),
            b('✎ כתובת', () => this.move(p), { 'data-pg-act': 'move' }, !pub || p.path === 'index.html' || p.is404),
            b('⧉ שכפול', () => this.create({ start: 'copy', from: p.path }), { 'data-pg-act': 'dup' }, !pub),
            b('⭐ קבע כראשי', () => this.entry(p), { 'data-pg-act': 'entry' }, !pub || p.entry || p.is404),
            b('🚫 קבע כדף 404', () => this.set404(p), { 'data-pg-act': '404' }, !pub || p.is404 || p.entry || d.pages.some(x => x.is404)),
            b('🗑 מחיקה', () => this.remove(p), { 'data-pg-act': 'del' }, !pub || p.entry, 'danger'))),
        url ? hh('iframe', { class: 'pg-preview', title: 'תצוגה מקדימה: ' + p.path, sandbox: '', src: url, loading: 'lazy', 'data-pg-preview': p.path, referrerpolicy: 'no-referrer' }) : hh('p', { class: 'muted' }, 'אין תצוגה (אין חיבור לשרת התצוגה)'),
        links ? null : null);
    }
    async run(label, fn) {
      try { const r = await fn(); await this.o.reload(); await this.load(); this.drawMgr(); return r; }
      catch (e) { this.o.note(label + ': ' + e.message, true); return null; }
    }
    // ➕ עמוד חדש: שם, כתובת (נגזרת), "מתחיל מ"
    create(pre = {}) {
      const d = this.data, name = hh('input', { class: 'hd-in', 'data-pg-f': 'title', placeholder: 'למשל: אודות', required: true });
      const slug = hh('input', { class: 'hd-in', dir: 'ltr', 'data-pg-f': 'slug', placeholder: 'about', pattern: SLUG.source, spellcheck: 'false' });
      const start = hh('select', { class: 'hd-in', 'data-pg-f': 'start' }, hh('option', { value: 'blank' }, 'ריק — עם קובצי העיצוב המשותפים (kol.json ← styles)'), hh('option', { value: 'copy' }, 'שכפול של עמוד קיים'), hh('option', { value: 'tpl', disabled: !(d.templates || []).length || null }, (d.templates || []).length ? 'תבנית עמוד (מ-📄 תבניות)' : 'תבנית עמוד — אין עדיין ("שמור את העמוד כתבנית" בעורך)'));
      const tpl = hh('select', { class: 'hd-in', 'data-pg-f': 'template' }, (d.templates || []).map(t => hh('option', { value: t.name }, t.label + ' — templates/' + t.name + '/')));
      const from = hh('select', { class: 'hd-in', 'data-pg-f': 'from' }, (d.pages || []).map(p => hh('option', { value: p.path }, (p.title || p.path) + ' — ' + p.path)));
      const err = hh('p', { class: 'error', hidden: true, 'data-pg-err': true }), url = hh('code', { dir: 'ltr', 'data-pg-url': true });
      let touched = false;
      const sync = () => { if (!touched) slug.value = slugify(name.value); url.textContent = d.base + (slug.value ? slug.value + '/' : '…/'); from.closest('label').hidden = start.value !== 'copy'; tpl.closest('label').hidden = start.value !== 'tpl'; };
      name.addEventListener('input', sync); slug.addEventListener('input', () => { touched = true; sync(); }); start.addEventListener('change', sync);
      if (pre.start) start.value = pre.start; if (pre.from) from.value = pre.from;
      if (pre.from) { const src = this.byPath(pre.from); name.value = (src?.title || '') + ' (עותק)'; slug.value = slugify(urlOf(pre.from).replace(/\/$/, '').split('/').pop() || 'page') + '-copy'; touched = true; }
      const go = async () => {
        err.hidden = true;
        if (start.value !== 'copy' && !name.value.trim() && start.value === 'blank') { err.hidden = false; err.textContent = 'חסר שם לעמוד'; return; }
        if (!SLUG.test(slug.value)) { err.hidden = false; err.textContent = 'כתובת: אותיות לטיניות קטנות, ספרות ומקף; / בין חלקים. בלי .. ובלי / בהתחלה'; return; }
        const r = await this.run('עמוד חדש', () => this.o.api('page_create', { slug: slug.value, title: name.value.trim(), start: start.value, from: start.value === 'copy' ? from.value : undefined, template: start.value === 'tpl' ? tpl.value : undefined }));
        if (!r) return;
        document.getElementById('sheet')?.close();
        this.o.note('נוצר ' + r.file.path + ' — נפתח בעורך'); this.sel = r.file.path; this.mgrClose && this.mgrClose();
        await this.go(r.file.path);
      };
      const f = hh('form', { class: 'pg-form', 'data-pg-form': 'create', onsubmit: e => { e.preventDefault(); go(); } },
        hh('label', { class: 'hd-lab' }, 'שם (הכותרת של העמוד)', name), hh('label', { class: 'hd-lab' }, 'כתובת', slug), hh('p', { class: 'hint' }, 'הכתובת באתר: ', url),
        hh('label', { class: 'hd-lab' }, 'מתחיל מ', start), hh('label', { class: 'hd-lab' }, 'העמוד לשכפול', from), hh('label', { class: 'hd-lab' }, 'התבנית', tpl), err,
        hh('div', { class: 'row-btns' }, hh('button', { type: 'submit', class: 'btn primary', 'data-pg-create': true }, 'צור ופתח')));
      sync();
      this.o.sheet('➕ עמוד חדש', f); name.focus();
    }
    // ✎ שינוי כתובת: תצוגת הקישורים שיתעדכנו, סימון לכל אחד, עסקה אחת
    move(p) {
      const cur = urlOf(p.path).replace(/\/$/, '').replace(/\.html?$/, ''), slug = hh('input', { class: 'hd-in', dir: 'ltr', value: cur, 'data-pg-f': 'to', spellcheck: 'false' }), err = hh('p', { class: 'error', hidden: true, 'data-pg-err': true });
      const box = hh('div', { class: 'pg-plan', 'data-pg-plan': true });
      const show = async () => {
        err.hidden = true;
        if (!SLUG.test(slug.value)) { err.hidden = false; err.textContent = 'כתובת: אותיות לטיניות קטנות, ספרות ומקף; / בין חלקים'; return; }
        let plan; try { plan = await this.o.api('page_move', { from: p.path, to: slug.value }); } catch (e) { err.hidden = false; err.textContent = e.message; return; }
        const checks = plan.links.map(x => { const c = hh('input', { type: 'checkbox', 'data-pg-link': x.n }); c.checked = true; return [x, c]; });
        const red = hh('input', { type: 'checkbox', 'data-pg-redirect': true });
        box.replaceChildren(...[hh('p', {}, hh('code', { dir: 'ltr' }, this.data.base + plan.from_url), ' ← ', hh('code', { dir: 'ltr' }, this.data.base + plan.to_url), plan.unit.files > 1 ? ' · ' + plan.unit.files + ' קבצים בתיקייה זזים יחד' : ''),
          hh('h4', {}, 'קישורים שיתעדכנו (' + plan.links.length + ')'),
          plan.links.length ? hh('ul', { class: 'pg-links' }, checks.map(([x, c]) => hh('li', {}, hh('label', {}, c, ' ', hh('span', { dir: 'ltr' }, x.path + ':' + x.line), ' ', hh('code', { dir: 'ltr' }, x.old), ' ← ', hh('code', { dir: 'ltr' }, x.new))))) : hh('p', { class: 'muted' }, 'אין קישורים לכתובת הזו'),
          plan.manual.length ? hh('div', { class: 'warnbox', 'data-pg-manual': true }, hh('b', {}, '🔎 לבדיקה ידנית (' + plan.manual.length + ') — קישור שנכתב ב-JS אינו מוחלף:'), hh('ul', {}, plan.manual.map(x => hh('li', {}, hh('span', { dir: 'ltr' }, x.path + ':' + x.line), ' ', hh('code', { dir: 'ltr' }, x.text))))) : null,
          hh('label', { class: 'hd-check' }, red, ' השאר הפניה בכתובת הישנה (', hh('code', { dir: 'ltr' }, plan.from), ' עם meta refresh — קובץ רגיל בעץ)'),
          hh('p', { class: 'hint' }, 'קישורים מבחוץ (גוגל, וואטסאפ) לכתובת הישנה יקבלו 404 — אלא אם משאירים הפניה.'),
          hh('div', { class: 'row-btns' }, hh('button', { type: 'button', class: 'btn primary', 'data-pg-move-apply': true, onclick: async () => {
            const pick = checks.filter(([, c]) => c.checked).map(([x]) => x.n), wasCur = this.o.current() === p.path;
            const r = await this.run('שינוי כתובת', () => this.o.api('page_move', { from: p.path, to: slug.value, apply: true, token: plan.token, pick, redirect: red.checked }));
            if (!r) return;
            document.getElementById('sheet')?.close();
            this.sel = r.to; this.drawMgr();
            this.o.note('הכתובת שונתה: ' + r.to_url + ' · ' + r.done.links + ' קישורים עודכנו' + (r.done.redirect ? ' · הפניה ב-' + r.from : ''));
            if (wasCur) await this.o.openPath(r.to, true);
            this.drawBtn();
          } }, 'שנה כתובת ועדכן ' + plan.links.length + ' קישורים'))].filter(Boolean));
      };
      this.o.sheet('✎ כתובת — ' + (p.title || p.path), hh('form', { class: 'pg-form', 'data-pg-form': 'move', onsubmit: e => { e.preventDefault(); show(); } },
        hh('label', { class: 'hd-lab' }, 'כתובת חדשה', hh('span', { class: 'pg-slugrow', dir: 'ltr' }, hh('span', {}, this.data.base), slug, hh('span', {}, '/'))), err,
        hh('div', { class: 'row-btns' }, hh('button', { type: 'submit', class: 'btn', 'data-pg-move-plan': true }, 'הצג מה ישתנה')), box));
      slug.focus(); slug.select();
    }
    async entry(p) { const r = await this.run('עמוד ראשי', () => this.o.api('page_set_entry', { path: p.path })); if (r) this.o.note('⭐ העמוד הראשי (entry ב-kol.json): ' + p.path); }
    async set404(p) {
      const r = await this.run('דף 404', () => this.o.api('page_set_404', p ? { path: p.path } : {}));
      if (!r) return; this.sel = '404.html'; this.drawMgr();
      this.o.note(p ? '🚫 ' + p.path + ' הוא עכשיו 404.html' : '🚫 נוצר 404.html מתבנית "לא נמצא"');
      if (p && this.o.current() === p.path) await this.o.openPath('404.html', true);
    }
    async order(list) { const r = await this.run('סדר', () => this.o.api('pages_order', { order: list })); if (r) this.o.note(list === 'auto' ? 'הסדר: אוטומטי' : 'הסדר נשמר ב-kol.json (pages)'); }
    reorder(from, to) {
      const ps = this.paths.slice(); if (to < 0 || to >= ps.length || from === to) return;
      const [x] = ps.splice(from, 1); ps.splice(to, 0, x); this.sel = x; return this.order(ps);
    }
    async remove(p) {
      let refs = [];
      const dir = /\/index\.html$/.test(p.path) ? p.path.slice(0, -'/index.html'.length) : null;   // about/ — הקישורים הם לתיקייה
      try { refs = (await this.o.api('pf_refs', undefined, dir ? '&path=' + encodeURIComponent(dir) : '&id=' + p.id)).refs || []; } catch { /* בלי ספירה — עדיין מוחקים לסל */ }
      if (dir) refs = refs.filter(x => !x.path.startsWith(dir + '/'));
      const pages = [...new Set(refs.map(x => x.path))];
      const ok = hh('button', { type: 'button', class: 'btn danger', 'data-pg-del-ok': true, onclick: async () => {
        const wasCur = this.o.current() === p.path;
        const r = await this.run('מחיקה', () => this.o.api('pf_delete', { id: p.id }));
        if (!r) return;
        document.getElementById('sheet')?.close();
        if (this.sel === p.path) this.sel = null; this.drawMgr();
        this.o.note('🗑 ' + p.path + ' עבר לסל', false, { label: '↩ שחזור', run: async () => { const x = await this.run('שחזור', () => this.o.api('pf_restore', { ids: [p.id] })); if (x) { this.o.note('↩ ' + p.path + ' שוחזר'); this.sel = p.path; this.drawMgr(); } } });
        if (wasCur) { const e = this.data.pages.find(x => x.entry) || this.data.pages[0]; if (e) await this.o.openPath(e.path, true); }
      } }, '🗑 העבר לסל');
      this.o.sheet('🗑 מחיקת ' + (p.title || p.path), hh('p', {}, 'העמוד ', hh('code', { dir: 'ltr' }, p.path), ' יעבור לסל (📁 פרויקט ← סל), ואפשר לשחזר אותו.'),
        pages.length ? hh('p', { class: 'warnbox', 'data-pg-del-refs': pages.length }, '⚠️ ' + pages.length + ' עמודים מקשרים אליו: ' + pages.join(', ') + ' — הקישורים יסומנו "שבור" באדום.') : hh('p', { class: 'muted', 'data-pg-del-refs': 0 }, 'אין עמודים שמקשרים אליו.'),
        hh('div', { class: 'row-btns' }, ok));
    }

    // ── בורר הקישור (§2.5) ──
    /** האפשרויות ללשונית "עמוד בפרויקט": העמודים לפי הסדר, ועוגנים בתוכם. value — יחסי ויפה מהקובץ הנוכחי. */
    linkOptions(fromFile) {
      const out = [];
      for (const p of this.data?.pages || []) {
        const rel = p.path === fromFile ? '' : prettyRel(fromFile, p.path), name = (p.title || urlOf(p.path) || 'index') + ' — ' + (urlOf(p.path) || '/');
        if (p.path !== fromFile) out.push([rel, '📄 ' + name, p.path]);
        for (const id of (p.ids || []).slice(0, 30)) out.push([rel + '#' + id, '   # ' + id + ' · ' + (p.title || p.path), p.path]);
      }
      return out;
    }
    /** קישור לעמוד שאינו קיים: undefined — לא לעמוד; null — שבור. */
    target(fromFile, href) { return pageOf(fromFile, href, this.paths, this.data?.base || '/lexicon/'); }
    /** בקנבס: כל <a> לעמוד שאינו קיים — מסגרת אדומה (ב-DOM בלבד; הקובץ לא משתנה). */
    drawBroken(canvas, K, fromFile) {
      if (!canvas?.m?.root || !this.data || !fromFile) return;
      for (const el of canvas.doc?.querySelectorAll('[data-kol-broken-link]') || []) { el.removeAttribute('data-kol-broken-link'); el.style.removeProperty('outline'); el.style.removeProperty('outline-offset'); }
      K.walk(canvas.m.root, n => {
        if (n.tag !== 'a') return; const a = K.attrOf(n, 'href'); if (!a) return;
        if (this.target(fromFile, a.value) !== null) return;
        const el = canvas.elOf ? canvas.elOf(n) : null; if (!el || !el.style) return;
        el.setAttribute('data-kol-broken-link', a.value); el.style.setProperty('outline', '2px dashed #b3261e'); el.style.setProperty('outline-offset', '2px');
      });
    }
  }

  g.KolPages = { Pages, slugify, prettyRel, pageOf, resolveRef, urlOf, SLUG };
})(typeof window !== 'undefined' ? window : globalThis);
