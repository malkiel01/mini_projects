/**
 * העורך החזותי — ⚙️ הגדרות העמוד (פרוסה V15 §2.1–§2.2): כל מה שבתוך ה-<head> ומה שעל ה-<html>, כטופס שכותב ל-HTML עצמו.
 *
 * אין כאן מקום שני לנתונים: כל שדה נקרא מהמודל של העורך (KolSrc) ונכתב אליו כתיקון טקסט דרך act() של המסך — לכן כל
 * שינוי נראה ב"קוד" כשורה שהשתנתה, הוא Ctrl+Z אחד, ובהיסטוריה עם שם ("הגדרות · כותרת · 'א' ← 'ב'").
 * שדה שאין לו תגית — ריק; מילוי יוצר את התגית במקום הצפוי; ריקון מסיר אותה. תגית שהטופס אינו מכיר — ב"עוד בראש העמוד".
 * קוד מותאם (§2.2) — האזור שבין <!-- kol:head --> ל-<!-- /kol:head --> (ובין <!-- kol:body-end --> ל-<!-- /kol:body-end -->):
 * שורות רגילות בקובץ, בלי קובץ נסתר. בקנבס הוא אינו רץ (iframe בלי allow-scripts).
 *
 * החלק הטהור (scan / planSet / planCode / plan*) רץ גם ב-Node — tests/editor/head.mjs.
 * הבידוד: כל ערך מהעמוד מוצג כטקסט / כערך של שדה (h / textContent / value) — אין innerHTML. ערכים נכתבים מקודדים.
 */
(function (g) {
  'use strict';
  const escText = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const escAttr = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const attr = (K, n, name) => { const a = K.attrOf(n, name); return a ? a.value : null; };
  const rels = (K, n) => (attr(K, n, 'rel') || '').toLowerCase().split(/\s+/).filter(Boolean);
  const metaKey = (K, n) => (attr(K, n, 'name') || attr(K, n, 'property') || '').toLowerCase();

  /** השדות: מפתח ← איך מזהים את התגית, מה התכונה שנושאת את הערך, ואיך נוצרת תגית חדשה. */
  const F = {
    title: { label: 'כותרת הלשונית', is: (K, n) => n.tag === 'title', text: true, make: v => '<title>' + escText(v) + '</title>' },
    description: { label: 'תיאור', is: (K, n) => n.tag === 'meta' && metaKey(K, n) === 'description', at: 'content', make: v => '<meta name="description" content="' + escAttr(v) + '">' },
    robots: { label: 'גוגל', is: (K, n) => n.tag === 'meta' && metaKey(K, n) === 'robots', at: 'content', make: v => '<meta name="robots" content="' + escAttr(v) + '">' },
    canonical: { label: 'כתובת קנונית', is: (K, n) => n.tag === 'link' && rels(K, n).includes('canonical'), at: 'href', url: true, make: v => '<link rel="canonical" href="' + escAttr(v) + '">' },
    theme: { label: 'צבע הדפדפן', is: (K, n) => n.tag === 'meta' && metaKey(K, n) === 'theme-color', at: 'content', make: v => '<meta name="theme-color" content="' + escAttr(v) + '">' },
    ogTitle: { label: 'כותרת לשיתוף', is: (K, n) => n.tag === 'meta' && metaKey(K, n) === 'og:title', at: 'content', make: v => '<meta property="og:title" content="' + escAttr(v) + '">' },
    ogDescription: { label: 'תיאור לשיתוף', is: (K, n) => n.tag === 'meta' && metaKey(K, n) === 'og:description', at: 'content', make: v => '<meta property="og:description" content="' + escAttr(v) + '">' },
    ogImage: { label: 'תמונת שיתוף', is: (K, n) => n.tag === 'meta' && metaKey(K, n) === 'og:image', at: 'content', url: true, make: v => '<meta property="og:image" content="' + escAttr(v) + '">' },
    icon: { label: 'סמל', is: (K, n) => n.tag === 'link' && rels(K, n).includes('icon') && !rels(K, n).includes('apple-touch-icon'), at: 'href', url: true, make: v => '<link rel="icon" href="' + escAttr(v) + '">' },
    apple: { label: 'סמל לטלפון', is: (K, n) => n.tag === 'link' && rels(K, n).some(r => r === 'apple-touch-icon' || r === 'apple-touch-icon-precomposed'), at: 'href', url: true, make: v => '<link rel="apple-touch-icon" href="' + escAttr(v) + '">' },
  };
  /** הסדר הצפוי בראש העמוד — תגית חדשה נכנסת אחרי האחרונה שלפניה בסדר הזה. */
  const ORDER = ['charset', 'viewport', 'title', 'description', 'robots', 'canonical', 'theme', 'ogTitle', 'ogDescription', 'ogImage', 'icon', 'apple'];
  const PRE = { charset: (K, n) => n.tag === 'meta' && K.attrOf(n, 'charset') != null, viewport: (K, n) => n.tag === 'meta' && metaKey(K, n) === 'viewport' };
  const MARK = { head: ['kol:head', '/kol:head'], body: ['kol:body-end', '/kol:body-end'] };

  class HeadError extends Error { constructor(msg, code) { super(msg); this.name = 'HeadError'; this.code = code || 'bad'; } }

  // ── בדיקות ערך (כל מה שנכתב לקובץ עובר כאן) ──
  const BAD_URL = v => /^\s*(javascript|vbscript|data:text\/html)/i.test(String(v).replace(/[\u0000- ]+/g, ''));
  function check(key, v) {
    v = String(v == null ? '' : v);
    if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(v)) throw new HeadError('בערך יש תו בקרה');
    if (key === 'lang') { if (v && !/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8}){0,3}$/.test(v)) throw new HeadError('שפה: קוד כמו he, ar, en-US'); return v; }
    if (key === 'dir') { if (v && !/^(rtl|ltr|auto)$/.test(v)) throw new HeadError('כיוון: rtl, ltr או auto'); return v; }
    if (key === 'theme') {
      if (!v) return v;
      const okCss = typeof CSS !== 'undefined' && CSS.supports ? CSS.supports('color', v) : /^(#[0-9a-f]{3,8}|[a-z]+|(rgb|hsl)a?\([\d\s.,%/-]+\))$/i.test(v);
      if (!okCss || /[<>"';{}]/.test(v)) throw new HeadError('צבע לא תקין: ' + v);
      return v;
    }
    if (F[key] && F[key].url && v) {
      if (BAD_URL(v)) throw new HeadError('כתובת javascript: / data:text/html אינה נכתבת כאן');
      if (/[\s<>"]/.test(v.trim())) throw new HeadError('בכתובת יש רווח או תו אסור');
      return v.trim();
    }
    if (v.length > 2000) throw new HeadError('הערך ארוך מדי');
    return v;
  }

  // ── קריאה ──
  function parts(K, m) {
    const root = m && m.root, html = root && root.tag === 'html' ? root : null;
    const head = html ? html.kids.find(k => k.tag === 'head') || null : null, body = html ? html.kids.find(k => k.tag === 'body') || null : null;
    return { html, head, body };
  }
  /** אזור הקוד המותאם: { open, close, from, to, code } — open/close הן ההערות (טווחים בקובץ); null כשאין. */
  function region(m, par, where) {
    if (!par) return null;
    const [a, b] = MARK[where], cm = par.nodes.filter(x => x.type === 'comment' && x.from >= 0);
    const txt = x => m.src.slice(x.from, x.to).replace(/^<!--\s*|\s*-->$/g, '');
    const open = cm.find(x => txt(x) === a), close = open && cm.find(x => x.from > open.from && txt(x) === b);
    if (!open || !close) return null;
    return { open, close, from: open.to, to: close.from, code: m.src.slice(open.to, close.from).replace(/^[ \t]*\r?\n/, '').replace(/\r?\n[ \t]*$/, '') };
  }
  /** מה בראש העמוד: לכל שדה — הצמתים שמתאימים לו (הראשון קובע), ושאר התגיות ("עוד בראש העמוד"). */
  function scan(K, m) {
    const { html, head, body } = parts(K, m), fields = {}, others = [];
    for (const k of Object.keys(F)) fields[k] = [];
    const hr = region(m, head, 'head'), br = region(m, body, 'body');
    const inRegion = (n, r) => r && n.from >= r.open.to && n.to <= r.close.from;
    if (head) for (const n of head.kids) {
      if (n.implied || inRegion(n, hr)) continue;
      const k = Object.keys(F).find(x => F[x].is(K, n));
      if (k) fields[k].push(n); else others.push(n);
    }
    const val = k => { const n = fields[k][0]; if (!n) return ''; if (F[k].text) { try { return K.textOf(m, n); } catch { return ''; } } return attr(K, n, F[k].at) || ''; };
    const values = {};
    for (const k of Object.keys(F)) values[k] = val(k);
    values.lang = html && !html.implied ? attr(K, html, 'lang') || '' : '';
    values.dir = html && !html.implied ? attr(K, html, 'dir') || '' : '';
    values.noindex = /(^|[\s,])noindex($|[\s,])/i.test(values.robots);
    return { html, head, body, fields, others, values, headCode: hr, bodyCode: br, dups: Object.keys(F).filter(k => fields[k].length > 1) };
  }

  // ── כתיבה: כל פונקציה מחזירה {patches} על הטקסט הנוכחי של m ──
  function insertIndex(K, head, key) {
    const kids = head.kids, i = ORDER.indexOf(key), is = (k, n) => (F[k] ? F[k].is(K, n) : PRE[k](K, n));
    let after = -1;
    kids.forEach((n, j) => { if (!n.implied && ORDER.slice(0, i).some(k => is(k, n))) after = j; });
    if (after >= 0) return after + 1;
    const before = kids.findIndex(n => !n.implied && ORDER.slice(i + 1).some(k => is(k, n)));
    return before >= 0 ? before : 0;
  }
  /** key ← value (ריק — מוחק את התגית; תגית חסרה — נוצרת). n — הצומת לעריכה (ברירת מחדל: הראשון). */
  function planSet(K, m, key, value, n) {
    const s = scan(K, m);
    if (key === 'lang' || key === 'dir') {
      if (!s.html || s.html.implied) throw new HeadError('לעמוד אין <html> כתוב — פתח בקוד', 'implied');
      if (value === '' || value == null) return removeAttr(K, m, s.html, key);
      return ltgt(K.html.setAttr(m, s.html, key, check(key, value)));
    }
    if (!F[key]) throw new HeadError('שדה לא מוכר: ' + key);
    const cur = n || s.fields[key][0];
    if (value === '' || value == null) {
      if (!cur) throw new HeadError('אין מה למחוק', 'noop');
      return K.html.remove(m, cur);
    }
    value = check(key, value);
    if (cur) return F[key].text ? K.html.setText(m, cur, value) : ltgt(K.html.setAttr(m, cur, F[key].at, value));
    if (!s.head || s.head.implied) throw new HeadError('לעמוד אין <head> כתוב — פתח בקוד', 'implied');
    return K.html.insert(m, s.head, insertIndex(K, s.head, key), F[key].make(value));
  }
  /** כמו KolIdentity.setAttr: < ו-> בערך — כישויות. */
  const ltgt = r => ({ patches: r.patches.map(p => ({ from: p.from, to: p.to, text: p.text.replace(/</g, '&lt;').replace(/>/g, '&gt;') })), muts: r.muts });
  function removeAttr(K, m, n, name) {
    const a = K.attrOf(n, name); if (!a) throw new HeadError('אין מה למחוק', 'noop');
    if (a.from < 0) throw new HeadError('לתכונה אין מיקום בקובץ', 'broken');
    let s = a.from; while (s > 0 && /[ \t\n\r\f]/.test(m.src[s - 1])) s--;
    return { patches: [{ from: s, to: a.to, text: '' }] };
  }
  /** "לא להופיע בחיפוש": noindex נכנס לתוכן של robots (או יוצר אותו), ויוצא ממנו (ומוחק אותו כשנשאר ריק). */
  function planNoindex(K, m, on) {
    const s = scan(K, m), cur = s.values.robots, toks = cur.split(/[\s,]+/).filter(Boolean).filter(t => t.toLowerCase() !== 'noindex');
    if (on) return planSet(K, m, 'robots', ['noindex'].concat(toks).join(', '));
    return planSet(K, m, 'robots', toks.join(', '));
  }
  /** קוד מותאם: where = 'head' | 'body'. ריק — האזור וההערות שלו נמחקים. */
  function planCode(K, m, where, code) {
    const s = scan(K, m), par = where === 'head' ? s.head : s.body, r = where === 'head' ? s.headCode : s.bodyCode;
    code = String(code || '').replace(/\r\n/g, '\n').replace(/^\n+|\s+$/g, '');
    if (!par || par.implied) throw new HeadError(where === 'head' ? 'לעמוד אין <head> כתוב — פתח בקוד' : 'לעמוד אין <body> כתוב — פתח בקוד', 'implied');
    if (/<!--\s*\/?kol:(head|body-end)\s*-->/.test(code)) throw new HeadError('הקוד אינו יכול להכיל את הערות הסימון kol:head / kol:body-end');
    if (r) {
      if (!code) {
        let a = r.open.from; while (a > 0 && /[ \t]/.test(m.src[a - 1])) a--; if (a > 0 && m.src[a - 1] === '\n') a--; if (a > 0 && m.src[a - 1] === '\r') a--;
        return { patches: [{ from: a, to: r.close.to, text: '' }] };
      }
      if (r.code === code) throw new HeadError('אין שינוי', 'noop');
      const ind = lineIndent(m.src, r.open.from);
      return { patches: [{ from: r.from, to: r.to, text: '\n' + code + '\n' + ind }] };
    }
    if (!code) throw new HeadError('אין שינוי', 'noop');
    const [a, b] = MARK[where];
    return K.html.insert(m, par, null, '<!-- ' + a + ' -->\n' + code + '\n<!-- ' + b + ' -->');
  }
  function lineIndent(src, at) { let s = at; while (s > 0 && src[s - 1] !== '\n') s--; const t = src.slice(s, at); return /^[ \t]*$/.test(t) ? t : ''; }

  /** תגית ב"עוד": שם קצר ותקציר. */
  function nameOf(K, n) {
    if (n.tag === 'meta') { const k = attr(K, n, 'name') || attr(K, n, 'property') || attr(K, n, 'http-equiv') || (K.attrOf(n, 'charset') ? 'charset' : ''); return 'meta' + (k ? ' · ' + k : ''); }
    if (n.tag === 'link') return 'link · ' + (attr(K, n, 'rel') || '?');
    if (n.tag === 'script') return 'script' + (attr(K, n, 'src') ? ' · src' : ' · בתוך העמוד');
    return n.tag;
  }
  function summaryOf(K, m, n) {
    if (n.tag === 'meta') return attr(K, n, 'content') || attr(K, n, 'charset') || '';
    if (n.tag === 'link') return attr(K, n, 'href') || '';
    if (n.tag === 'script') return attr(K, n, 'src') || m.src.slice(n.st[1], n.et ? n.et[0] : n.to).trim().slice(0, 80);
    if (n.tag === 'style') return m.src.slice(n.st[1], n.et ? n.et[0] : n.to).trim().slice(0, 80);
    return n.attrs.map(a => a.name + '=' + a.value).join(' ').slice(0, 80);
  }
  /** מקורות חיצוניים (https://מארח) שהקוד טוען — להודעה "נוסף X לרשימת המקורות" (§2.2, [ש-12]). */
  function origins(code) {
    const out = new Set();
    for (const mm of String(code || '').matchAll(/(?:https?:)?\/\/([a-z0-9][a-z0-9.-]*\.[a-z]{2,})(?::\d+)?(?=[/"'`\s?#)]|$)/gi)) out.add(mm[1].toLowerCase());
    return [...out];
  }
  const short = s => { s = String(s || ''); return s.length > 40 ? s.slice(0, 39) + '…' : s; };

  // ── גרירה ברשימה (משותף ל"עוד בראש העמוד" ול"📄 עמודים") ──
  /**
   * list — מכל הרשימה; כל שורה עם data-i. גרירה מהידית (⇅) מיד, ובמגע — לחיצה ארוכה על השורה.
   * onMove(from, to) — המיקום החדש (במספור בלי השורה עצמה).
   */
  function dragList(list, onMove) {
    let st = null, timer = 0;
    const rows = () => [...list.querySelectorAll(':scope > [data-i]')];
    const begin = (row, e) => {
      const rs = rows(); st = { row, from: rs.indexOf(row), y0: e.clientY, rects: rs.map(r => r.getBoundingClientRect()), to: rs.indexOf(row), id: e.pointerId };
      row.classList.add('dragging'); list.classList.add('drag-on');
      try { row.setPointerCapture(e.pointerId); } catch { /* אין לכידה — ממשיכים */ }
    };
    const move = e => {
      if (!st) return; e.preventDefault();
      const dy = e.clientY - st.y0; st.row.style.transform = 'translateY(' + dy + 'px)';
      const mid = st.rects[st.from].top + st.rects[st.from].height / 2 + dy;
      let to = 0; st.rects.forEach((r, i) => { if (i !== st.from && mid > r.top + r.height / 2) to++; });
      st.to = to; rows().forEach((r, i) => r.classList.toggle('drop-before', i !== st.from && (i < st.from ? i : i - 1) === to && to < st.from + (i > st.from ? 1 : 0)));
    };
    const end = () => {
      clearTimeout(timer); if (!st) return;
      const { row, from, to } = st; st = null; row.style.transform = ''; row.classList.remove('dragging'); list.classList.remove('drag-on'); rows().forEach(r => r.classList.remove('drop-before'));
      if (to !== from) onMove(from, to);
    };
    list.addEventListener('pointerdown', e => {
      const row = e.target.closest('[data-i]'); if (!row || row.parentElement !== list) return;
      if (e.target.closest('[data-drag]')) { e.preventDefault(); begin(row, e); return; }
      if (e.pointerType === 'touch' && !e.target.closest('button, input, select, textarea, a')) { const x = e; clearTimeout(timer); timer = setTimeout(() => { begin(row, x); if (navigator.vibrate) navigator.vibrate(15); }, 450); }
    });
    list.addEventListener('pointermove', e => { if (st) move(e); else if (timer && Math.abs(e.movementY) > 6) clearTimeout(timer); });
    list.addEventListener('pointerup', end); list.addEventListener('pointercancel', end);
    list.addEventListener('contextmenu', e => { if (st) e.preventDefault(); });
  }

  // ── הטופס (דפדפן) ──
  class Head {
    /** o: { canvas, K, act, joinTx, note, openInCode, pick, rel, url(path) → כתובת תצוגה, canWrite, file, editBody, phone, host, onClose, monaco } */
    constructor(o) { this.o = o; this.K = o.K; this.c = o.canvas; this.open = false; this.ed = {}; }
    get m() { return this.c && this.c.m; }
    show() {
      if (!this.m) return this.o.note('העמוד עוד לא נטען', true);
      this.open = true; this.draw();
      if (this.o.phone()) { const dlg = sheet('⚙️ הגדרות העמוד', this.box); dlg.classList.add('hd-sheet'); dlg.addEventListener('close', () => { this.open = false; this.disposeCode(); }, { once: true }); }
      else this.o.host.classList.add('on');
      this.o.onState && this.o.onState(true);
    }
    hide() { this.open = false; this.disposeCode(); this.o.host.classList.remove('on'); this.o.onState && this.o.onState(false); }
    /** אחרי כל שינוי במסמך — מצויר מחדש מהמודל (אם אין שדה בעריכה). */
    refresh() { if (!this.open) return; const a = document.activeElement; if (a && this.box && this.box.contains(a) && /^(INPUT|TEXTAREA)$/.test(a.tagName) && a.dataset.dirty) return; if (this.codeFocus) return; this.draw(); }
    disposeCode() { for (const k of Object.keys(this.ed)) { try { this.ed[k].dispose(); } catch { /* כבר נסגר */ } } this.ed = {}; }
    /** שינוי אחד = act אחד = Ctrl+Z אחד, עם שם. */
    set(key, value, label, n) {
      const K = this.K, s = scan(K, this.m), was = key === 'lang' || key === 'dir' ? s.values[key] : (n ? (F[key].text ? K.textOf(this.m, n) : attr(K, n, F[key].at) || '') : s.values[key]);
      if (String(value) === String(was)) return true;
      try { check(key, value); } catch (e) { this.o.note(e.message, true); return false; }
      const lab = 'הגדרות · ' + label + ' · ' + (was ? "'" + short(was) + "'" : 'ריק') + ' ← ' + (value ? "'" + short(value) + "'" : 'ריק');
      const ogLinked = (key === 'title' && this.linked(s, 'ogTitle')) ? 'ogTitle' : (key === 'description' && this.linked(s, 'ogDescription')) ? 'ogDescription' : null;
      if (!this.o.act(lab, m => this.wrap(() => planSet(K, m, key, value, n)))) return false;
      if (ogLinked && scan(K, this.m).fields[ogLinked][0]) { if (this.o.act(lab, m => this.wrap(() => planSet(K, m, ogLinked, value)))) this.o.joinTx(2, lab); }   // "כמו הכותרת" — נשאר צמוד
      return true;
    }
    /** og:title / og:description צמודים לכותרת / לתיאור = אין תגית משלהם (הרשתות לוקחות את <title> / description). ניתוק = כותבים תגית. */
    linked(s, k) { return !s.fields[k][0]; }
    wrap(fn) { try { return fn(); } catch (e) { if (e instanceof HeadError) throw new this.K.SrcError(e.message, e.code === 'noop' ? 'noop' : 'rejected'); throw e; } }
    draw() {
      const K = this.K, m = this.m; if (!m) return;
      const s = scan(K, m), v = s.values, ro = !this.o.canWrite();
      this.disposeCode();
      const field = (key, label, input, extra, hint) => h('div', { class: 'hd-field', 'data-hd': key }, h('label', { class: 'hd-lab' }, label, input), extra || null, hint ? h('p', { class: 'hint' }, hint) : null, this.dupNote(s, key));
      const text = (key, label, o = {}) => {
        const inp = h(o.area ? 'textarea' : 'input', { class: 'hd-in', 'data-hd-in': key, dir: o.dir || 'auto', placeholder: o.ph || '', disabled: ro || null, rows: o.area ? 3 : null });
        inp.value = o.value != null ? o.value : v[key];
        inp.addEventListener('input', () => { inp.dataset.dirty = '1'; o.onInput && o.onInput(inp.value); });
        const done = () => { if (!inp.dataset.dirty) return; delete inp.dataset.dirty; if (!this.set(key, inp.value.trim(), label)) inp.value = scan(K, this.m).values[key]; };
        inp.addEventListener('change', done); inp.addEventListener('keydown', e => { if (e.key === 'Enter' && !o.area) { e.preventDefault(); done(); } if (e.key === 'Escape') { delete inp.dataset.dirty; inp.value = scan(K, this.m).values[key]; } });
        return inp;
      };
      // תצוגת הלשונית והטלפון — מתעדכנת מהקובץ (כותרת, סמל, צבע הדפדפן)
      const tab = h('div', { class: 'hd-tab', 'data-hd-tab': true, style: v.theme ? '--hd-theme:' + v.theme : null },
        v.icon ? h('img', { class: 'hd-fav', alt: '', src: this.o.url(v.icon) || '' }) : h('span', { class: 'hd-fav none' }, '🌐'), h('span', { class: 'hd-tab-t' }, v.title || 'ללא כותרת'));
      // גוגל
      const count = h('span', { class: 'hd-count', 'data-hd-count': true }), gT = h('b', {}, v.title || 'ללא כותרת'), gD = h('span', {}, v.description || 'גוגל יבחר קטע מהעמוד');
      const setCount = t => { count.textContent = t.length + ' / 160'; count.classList.toggle('over', t.length > 160); gD.textContent = t || 'גוגל יבחר קטע מהעמוד'; };
      setCount(v.description);
      const google = h('div', { class: 'hd-google', 'data-hd-google': true, 'aria-label': 'כך העמוד ייראה בתוצאות של גוגל' }, h('small', { dir: 'ltr' }, this.o.pageUrl()), gT, gD, v.noindex ? h('em', { class: 'hd-noidx' }, '🚫 לא יופיע בחיפוש') : null);
      // כרטיס שיתוף
      const ogT = this.linked(s, 'ogTitle') ? v.title : v.ogTitle, ogD = this.linked(s, 'ogDescription') ? v.description : v.ogDescription;
      const card = h('div', { class: 'hd-card', 'data-hd-card': true, 'aria-label': 'כך העמוד ייראה כשמשתפים אותו בוואטסאפ / פייסבוק' },
        v.ogImage ? h('img', { alt: '', src: this.o.url(v.ogImage) || '' }) : h('div', { class: 'hd-noimg' }, 'אין תמונת שיתוף'), h('small', { dir: 'ltr' }, this.o.host_()), h('b', {}, ogT || 'ללא כותרת'), h('span', {}, ogD || ''));
      const image = (key, label, also) => {
        const cur = v[key], btns = h('div', { class: 'hd-btns' },
          h('button', { type: 'button', class: 'btn sm', 'data-hd-pick': key, disabled: ro || null, onclick: async () => { const p = await this.o.pick('image', label); if (!p) return; const ref = this.o.rel(p); if (this.set(key, ref, label) && also) { const s2 = scan(K, this.m); if (s2.values[also] !== ref && this.o.act('הגדרות · ' + label, mm => this.wrap(() => planSet(K, mm, also, ref)))) this.o.joinTx(2, 'הגדרות · ' + label + ' · ' + short(ref)); } } }, cur ? '🖼 החלף' : '🖼 בחר מהספרייה'),
          cur ? h('button', { type: 'button', class: 'btn sm ghost', 'data-hd-clear': key, disabled: ro || null, onclick: () => { if (this.set(key, '', label) && also && scan(K, this.m).fields[also][0]) { if (this.o.act('הגדרות · ' + label, mm => this.wrap(() => planSet(K, mm, also, '')))) this.o.joinTx(2, 'הגדרות · ' + label + ' · ריק'); } } }, '🗑 הסר') : null);
        return h('div', { class: 'hd-img' }, cur ? h('img', { class: 'hd-thumb', alt: '', src: this.o.url(cur) || '' }) : null, h('code', { dir: 'ltr' }, cur || '—'), btns);
      };
      const linkBox = (k, base, label) => {
        const on = this.linked(s, k);
        const cb = h('input', { type: 'checkbox', 'data-hd-link': k, disabled: ro || null }); cb.checked = on;
        cb.addEventListener('change', () => {
          const lab = 'הגדרות · ' + label + (cb.checked ? ' · כמו ' + (k === 'ogTitle' ? 'הכותרת' : 'התיאור') : ' · מנותק');
          if (cb.checked) { if (scan(K, this.m).fields[k][0]) this.o.act(lab, mm => this.wrap(() => planSet(K, mm, k, ''))); else this.draw(); }
          else { const val = scan(K, this.m).values[base]; if (val) this.o.act(lab, mm => this.wrap(() => planSet(K, mm, k, val))); else { this.o.note('קודם ממלאים את ' + (base === 'title' ? 'הכותרת' : 'התיאור'), true); cb.checked = true; } }
        });
        return h('label', { class: 'hd-check' }, cb, ' כמו ' + (k === 'ogTitle' ? 'הכותרת' : 'התיאור'));
      };
      const ni = h('input', { type: 'checkbox', 'data-hd-in': 'noindex', disabled: ro || null }); ni.checked = v.noindex;
      ni.addEventListener('change', () => { const lab = 'הגדרות · גוגל · ' + (ni.checked ? 'לא להופיע בחיפוש' : 'להופיע בחיפוש'); this.o.act(lab, mm => this.wrap(() => planNoindex(K, mm, ni.checked))); });
      const dirSel = h('select', { class: 'hd-in', 'data-hd-in': 'dir', disabled: ro || null }, [['', '— לא נקבע —'], ['rtl', 'מימין לשמאל (rtl)'], ['ltr', 'משמאל לימין (ltr)'], ['auto', 'אוטומטי']].map(([x, t]) => h('option', { value: x }, t)));
      dirSel.value = v.dir; dirSel.addEventListener('change', () => this.set('dir', dirSel.value, 'כיוון'));
      const color = h('input', { type: 'color', class: 'hd-color', 'data-hd-color': true, disabled: ro || null, 'aria-label': 'בחירת צבע' }); color.value = /^#[0-9a-f]{6}$/i.test(v.theme) ? v.theme : '#ffffff';
      color.addEventListener('change', () => this.set('theme', color.value, 'צבע הדפדפן'));
      const ogTitleIn = text('ogTitle', 'כותרת לשיתוף', { value: ogT }), ogDescIn = text('ogDescription', 'תיאור לשיתוף', { value: ogD, area: true });
      if (this.linked(s, 'ogTitle')) ogTitleIn.disabled = true; if (this.linked(s, 'ogDescription')) ogDescIn.disabled = true;
      const sec = (title, ...kids) => h('section', { class: 'hd-sec' }, h('h3', {}, title), ...kids);
      const body = [
        h('div', { class: 'hd-top' }, tab, h('div', { class: 'hd-phone', style: v.theme ? '--hd-theme:' + v.theme : null, 'data-hd-phone': true, title: 'צבע הדפדפן בטלפון' }, h('i'), h('span', {}, v.theme || 'ברירת מחדל'))),
        ro ? h('p', { class: 'warnbox' }, 'העמוד פתוח לקריאה בלבד') : null,
        sec('🔎 בגוגל ובלשונית',
          field('title', 'כותרת הלשונית', text('title', 'כותרת', { onInput: t => { gT.textContent = t || 'ללא כותרת'; } }), null, '<title> — בלשונית הדפדפן ובכותרת התוצאה בגוגל'),
          field('description', 'תיאור (גוגל)', text('description', 'תיאור', { area: true, onInput: setCount }), count, '<meta name="description"> — עד כ-160 תווים מוצגים'),
          google,
          h('div', { class: 'hd-field', 'data-hd': 'noindex' }, h('label', { class: 'hd-check' }, ni, ' לא להופיע בחיפוש של גוגל'), h('p', { class: 'hint' }, '<meta name="robots" content="noindex">'), this.dupNote(s, 'robots')),
          field('canonical', 'כתובת קנונית', text('canonical', 'כתובת קנונית', { dir: 'ltr', ph: 'https://…' }), null, '<link rel="canonical"> — כשאותו תוכן נמצא בכמה כתובות')),
        sec('📣 שיתוף (וואטסאפ / פייסבוק)',
          h('div', { class: 'hd-field', 'data-hd': 'ogImage' }, h('span', { class: 'hd-lab' }, 'תמונת שיתוף'), image('ogImage', 'תמונת שיתוף'), h('p', { class: 'hint' }, 'og:image — מהספרייה'), this.dupNote(s, 'ogImage')),
          field('ogTitle', 'כותרת לשיתוף', ogTitleIn, linkBox('ogTitle', 'title', 'כותרת לשיתוף')),
          field('ogDescription', 'תיאור לשיתוף', ogDescIn, linkBox('ogDescription', 'description', 'תיאור לשיתוף')),
          card),
        sec('🧩 סמל, שפה וצבע',
          h('div', { class: 'hd-field', 'data-hd': 'icon' }, h('span', { class: 'hd-lab' }, 'סמל (favicon)'), image('icon', 'סמל', 'apple'), h('p', { class: 'hint' }, '<link rel="icon"> וגם <link rel="apple-touch-icon">'), this.dupNote(s, 'icon')),
          h('div', { class: 'hd-row2' }, field('lang', 'שפה', text('lang', 'שפה', { dir: 'ltr', ph: 'he' }), null, '<html lang>'), field('dir', 'כיוון', dirSel, null, '<html dir>')),
          field('theme', 'צבע הדפדפן בטלפון', text('theme', 'צבע הדפדפן', { dir: 'ltr', ph: '#0b5' }), color, '<meta name="theme-color">')),
        sec('🖌 רקע וגופן',
          h('p', { class: 'hint' }, 'רקע הגוף וגופן ברירת המחדל נערכים על <body> עצמו — בקנבס ובפאנל העיצוב.'),
          h('button', { type: 'button', class: 'btn sm', 'data-hd-body': true, onclick: () => this.o.editBody() }, 'ערוך את הגוף ←')),
        this.codeSec(s, ro),
        this.othersSec(s, ro),
      ];
      if (!this.box) this.box = h('div', { class: 'hd', 'data-hd-form': true });
      this.box.replaceChildren(...body.filter(Boolean));
      if (!this.o.phone()) this.o.host.querySelector('.hd-body').replaceChildren(this.box);
      this.mountCode();
    }
    dupNote(s, key) {
      if (!s.fields[key] || s.fields[key].length < 2) return null;
      return h('p', { class: 'hd-dup', 'data-hd-dup': key }, '⚠️ נמצאו ' + (s.fields[key].length === 2 ? 'שתיים' : s.fields[key].length) + ' — הראשונה קובעת. ',
        h('button', { type: 'button', class: 'linkish', onclick: () => this.o.openInCode(s.fields[key][1]) }, '</> פתח בקוד'));
    }
    codeSec(s, ro) {
      const box = (where, title, hint) => {
        const r = where === 'head' ? s.headCode : s.bodyCode, host = h('div', { class: 'hd-code', 'data-hd-code': where });
        const save = h('button', { type: 'button', class: 'btn sm primary', 'data-hd-code-save': where, disabled: ro || null, onclick: () => this.saveCode(where) }, '💾 החל');
        this['code_' + where] = { host, value: r ? r.code : '' };
        return h('div', { class: 'hd-field' }, h('span', { class: 'hd-lab' }, title), h('p', { class: 'hint' }, hint), host, h('div', { class: 'hd-btns' }, save,
          r ? h('button', { type: 'button', class: 'linkish', onclick: () => this.o.openAt(r.open.from) }, '</> פתח בקוד') : null));
      };
      return h('section', { class: 'hd-sec' }, h('h3', {}, '⌨️ קוד מותאם'),
        h('p', { class: 'hint' }, 'סטטיסטיקה, פיקסל, אימות בעלות. הקוד נשמר ב-index.html בין שתי הערות סימון, ונראה בקוד כמו כל שורה. בקנבס הוא אינו רץ — רק בעמוד וב"▶️ תצוגה חיה".'),
        box('head', 'בראש העמוד', '<!-- kol:head --> … <!-- /kol:head --> — בסוף ה-<head>'), box('body', 'בסוף העמוד', '<!-- kol:body-end --> … <!-- /kol:body-end --> — לפני </body>'),
        h('p', { class: 'hd-origins', 'data-hd-origins': true, hidden: true }));
    }
    mountCode() {
      for (const where of ['head', 'body']) {
        const c = this['code_' + where]; if (!c) continue;
        const fallback = () => { const ta = h('textarea', { class: 'hd-in hd-ta', dir: 'ltr', 'data-hd-code-in': where, rows: 5, spellcheck: 'false' }); ta.value = c.value; ta.addEventListener('focus', () => { this.codeFocus = true; }); ta.addEventListener('blur', () => { this.codeFocus = false; }); c.get = () => ta.value; c.host.replaceChildren(ta); };
        if (!g.monaco || !g.monaco.editor) { fallback(); continue; }
        try {
          const ed = g.monaco.editor.create(c.host, { value: c.value, language: 'html', minimap: { enabled: false }, automaticLayout: true, lineNumbers: 'off', fontSize: 13, wordWrap: 'on', scrollBeyondLastLine: false, theme: matchMedia('(prefers-color-scheme: dark)').matches ? 'vs-dark' : 'vs' });
          ed.onDidFocusEditorText(() => { this.codeFocus = true; }); ed.onDidBlurEditorText(() => { this.codeFocus = false; });
          this.ed[where] = ed; c.get = () => ed.getValue(); c.host.dataset.monaco = '1';
        } catch { fallback(); }
      }
    }
    saveCode(where) {
      const c = this['code_' + where]; if (!c || !c.get) return;
      const code = c.get(), title = where === 'head' ? 'קוד בראש העמוד' : 'קוד בסוף העמוד', was = (where === 'head' ? scan(this.K, this.m).headCode : scan(this.K, this.m).bodyCode);
      const before = new Set(origins(was ? was.code : '')), added = origins(code).filter(x => !before.has(x));
      this.codeFocus = false;
      if (!this.o.act('הגדרות · ' + title + (code.trim() ? '' : ' · נמחק'), m => this.wrap(() => planCode(this.K, m, where, code)))) return;
      if (added.length) {
        const msg = 'כדי שהקוד הזה ירוץ, נוסף ' + added.join(', ') + ' לרשימת המקורות החיצוניים — היא מוצגת לפני כל פרסום ("מה ישתנה")';
        this.o.note(msg); this.o.origins && this.o.origins(added);
        const p = this.box && this.box.querySelector('[data-hd-origins]'); if (p) { p.hidden = false; p.textContent = '🌐 ' + msg; }
      }
    }
    othersSec(s, ro) {
      const K = this.K, m = this.m, list = h('div', { class: 'hd-others', role: 'list', 'data-hd-others': true });
      const kids = s.head ? s.head.kids.filter(k => !k.implied) : [];
      s.others.forEach((n, i) => {
        const row = h('div', { class: 'hd-orow', role: 'listitem', 'data-i': i, 'data-tag': n.tag },
          h('button', { type: 'button', class: 'icon-btn hd-drag', 'data-drag': true, 'aria-label': 'גרירה לסידור', title: 'גרירה לסידור (במגע — לחיצה ארוכה על השורה)', disabled: ro || null }, '⇅'),
          h('span', { class: 'hd-oname' }, h('b', { dir: 'ltr' }, nameOf(K, n)), h('small', { dir: 'ltr' }, short(summaryOf(K, m, n)))),
          h('button', { type: 'button', class: 'icon-btn', 'data-up': true, 'aria-label': 'למעלה', disabled: ro || i === 0 || null, onclick: () => this.moveOther(s, i, i - 1) }, '↑'),
          h('button', { type: 'button', class: 'icon-btn', 'data-down': true, 'aria-label': 'למטה', disabled: ro || i === s.others.length - 1 || null, onclick: () => this.moveOther(s, i, i + 1) }, '↓'),
          h('button', { type: 'button', class: 'icon-btn', 'data-edit': true, 'aria-label': 'עריכת התכונות', title: 'עריכת התכונות (שם = ערך)', disabled: ro || null, onclick: () => this.editAttrs(n) }, '✎'),
          h('button', { type: 'button', class: 'icon-btn', 'data-del': true, 'aria-label': 'מחיקה', disabled: ro || null, onclick: () => this.o.act('הגדרות · מחיקת ' + nameOf(K, n), mm => K.html.remove(mm, K.byPath(mm, K.pathOf(n)))) }, '🗑'),
          h('button', { type: 'button', class: 'linkish', 'data-code': true, onclick: () => this.o.openInCode(n) }, '</>'));
        list.append(row);
      });
      if (!ro) dragList(list, (from, to) => this.moveOther(s, from, to));
      this._kids = kids;
      return h('section', { class: 'hd-sec' }, h('h3', {}, '📋 עוד בראש העמוד (' + s.others.length + ')'),
        h('p', { class: 'hint' }, 'כל תגית אחרת ב-<head>: קובצי עיצוב, סקריפטים, meta נוספים. ⇅ גרירה לסידור · ✎ התכונות · 🗑 מחיקה · </> בקוד.'),
        s.others.length ? list : h('p', { class: 'muted' }, 'אין'),
        h('div', { class: 'hd-btns' }, h('button', { type: 'button', class: 'btn sm', 'data-hd-add': true, disabled: ro || !s.head || null, onclick: () => this.addTag() }, '＋ תגית meta / link')));
    }
    /** הזזה בין "האחרים" = הזזה ב-<head> אל המקום של היעד (לפני / אחרי), בין כל הילדים. */
    moveOther(s, from, to) {
      const K = this.K, n = s.others[from], ref = s.others[to]; if (!n || !ref || from === to) return;
      const label = 'הגדרות · סידור ' + nameOf(K, n);
      this.o.act(label, m => { const head = K.byPath(m, K.pathOf(s.head)), nn = K.byPath(m, K.pathOf(n)), rr = K.byPath(m, K.pathOf(ref)); const sibs = head.kids.filter(k => k !== nn); let idx = sibs.indexOf(rr); if (to > from) idx++; return K.html.move(m, nn, head, idx); });
    }
    editAttrs(n) {
      const K = this.K, rows = h('div', { class: 'hd-attrs' }), path = K.pathOf(n);
      const row = (name, value) => { const a = h('input', { class: 'hd-in', dir: 'ltr', value: name, 'aria-label': 'שם', 'data-an': true }), b = h('input', { class: 'hd-in', dir: 'ltr', value, 'aria-label': 'ערך', 'data-av': true }); return h('div', { class: 'hd-arow' }, a, h('span', {}, '='), b, h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'הסרה', onclick: e => e.target.closest('.hd-arow').remove() }, '✕')); };
      for (const a of n.attrs) rows.append(row(a.name, a.value));
      const err = h('p', { class: 'error', hidden: true });
      const apply = () => {
        const want = []; const ID = g.KolIdentity;
        try { for (const r of rows.children) { const nm = r.querySelector('[data-an]').value.trim(), vl = r.querySelector('[data-av]').value; if (!nm) continue; want.push([ID ? ID.checkAttr(nm, vl) : nm, vl]); } }
        catch (e) { err.hidden = false; err.textContent = e.message; return; }
        const cur = K.byPath(this.m, path); if (!cur) return;
        const label = 'הגדרות · תכונות ' + nameOf(K, cur);
        const ops = [];
        for (const a of cur.attrs) if (!want.some(([x]) => x === a.name)) ops.push(['del', a.name]);
        for (const [nm, vl] of want) if (attr(K, cur, nm) !== vl) ops.push(['set', nm, vl]);
        if (!ops.length) { document.getElementById('sheet').close(); return; }
        let done = 0;
        for (const op of ops) {
          const ok = this.o.act(label, m => { const x = K.byPath(m, path); if (op[0] === 'del') return removeAttr(K, m, x, op[1]); const r = K.html.setAttr(m, x, op[1], op[2]); return { patches: r.patches.map(p => ({ from: p.from, to: p.to, text: p.text.replace(/</g, '&lt;').replace(/>/g, '&gt;') })) }; });
          if (ok) done++; else break;
        }
        if (done > 1) this.o.joinTx(done, label);
        document.getElementById('sheet').close();
      };
      sheet('✎ ' + nameOf(K, n), h('p', { class: 'hint' }, 'התכונות של התגית, שם = ערך. on… ו-javascript: — נכתבים בקוד.'), rows,
        h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn sm', onclick: () => rows.append(row('', '')) }, '＋ תכונה'), h('button', { type: 'button', class: 'btn primary', 'data-hd-attrs-apply': true, onclick: apply }, 'החל')), err);
    }
    addTag() {
      const K = this.K, kind = h('select', { class: 'hd-in', 'data-new-kind': true }, h('option', { value: 'meta' }, 'meta'), h('option', { value: 'link' }, 'link'));
      const a = h('input', { class: 'hd-in', dir: 'ltr', placeholder: 'name / rel', 'data-new-a': true }), b = h('input', { class: 'hd-in', dir: 'ltr', placeholder: 'content / href', 'data-new-b': true }), err = h('p', { class: 'error', hidden: true });
      sheet('＋ תגית בראש העמוד', h('div', { class: 'hd-arow' }, kind, a, b), h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn primary', 'data-new-apply': true, onclick: () => {
        const isMeta = kind.value === 'meta', k1 = isMeta ? 'name' : 'rel', k2 = isMeta ? 'content' : 'href';
        try { if (!/^[a-zA-Z0-9:._-]+$/.test(a.value.trim())) throw new Error(k1 + ': אותיות לטיניות, ספרות, : . _ -'); if (g.KolIdentity) g.KolIdentity.checkAttr(k2, b.value); } catch (e) { err.hidden = false; err.textContent = e.message; return; }
        const html = '<' + kind.value + ' ' + k1 + '="' + escAttr(a.value.trim()) + '" ' + k2 + '="' + escAttr(b.value) + '">';
        if (this.o.act('הגדרות · תגית ' + kind.value + ' · ' + short(a.value.trim()), m => K.html.insert(m, parts(K, m).head, null, html))) document.getElementById('sheet').close();
      } }, 'הוסף')), err);
    }
  }

  g.KolHead = { Head, scan, planSet, planNoindex, planCode, check, origins, nameOf, summaryOf, dragList, HeadError, FIELDS: F, ORDER };
})(typeof window !== 'undefined' ? window : globalThis);
