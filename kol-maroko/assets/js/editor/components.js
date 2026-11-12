/**
 * קול מרוקו — 🎨 עורך (פרוסה V12): רכיבים בעורך — 03 §10, 02 §7.
 *
 * כאן: לשונית "🧩 רכיבים" ליד ה-Navigator (גרירה / לחיצה) · ההגדרות מהסכמה בלשונית "תוכן" (הערך נקרא מהמופע עצמו) ·
 * התג ◆ / ◇ על שורש המופע (בקנבס וב-Navigator), "נתק", "חזור לרכיב", "הצג את ההבדלים" · "✏️ ערוך את הרכיב" (template.html
 * בקנבס) ו"שמור ועדכן מופעים" (כל הקבצים — עסקה אחת) · "הוסף לכל העמודים" · "שמור כרכיב" (template / component.json / style.css,
 * עם הצעה אילו כללי CSS להעביר) · "➕ הוסף הגדרה" (בהצבעה, בלי JSON) · מחיקת רכיב (לסל) · הערה בפאנל העיצוב כשהיעד הוא
 * style.css של רכיב ("ישנה את כל N המופעים").
 * החלק הטהור (מצב, יעדים, ציור) — component-state.js (KolComp). כל כתיבה — דרך act / writeOps של screen.js (עסקה עם שם,
 * Ctrl+Z אחד); קבצים חדשים בשרת — pf_create, והביטול שלהם — attachFx (לסל). כל טקסט מהעמוד — h / textContent, בלי innerHTML.
 */
(function () {
  'use strict';
  const K = () => window.KolSrc, C = () => window.KolComp;
  const TPL_RE = /^components\/([^/]+)\/template\.html$/;
  const JSON_RE = /^components\/([^/]+)\/component\.json$/;
  const KINDS = { text: 'הטקסט שלו', attr: 'תכונה', class: 'מחלקה מתוך קבוצה', var: 'משתנה CSS', src: 'קובץ', children: 'הילדים — רשימה' };
  const VAR_PROPS = ['color', 'background-color', 'font-size', 'font-family', 'padding', 'margin', 'gap', 'border-radius', 'border-color', 'width', 'max-width', 'height', 'opacity'];
  const STATE = { linked: ['◆', 'מקושר לרכיב'], modified: ['◇', 'שונה במקום'], missing: ['⚠', 'קובצי הרכיב חסרים'] };

  class Components {
    /** opt: { canvas, K, X, build, content, nav, act, writeOps, joinTx, attachFx, txCount, note, sheet, undo, canWrite, pick, rel(path), refPath(ref),
     *        file(), doc(), pages(): Promise<[{path, doc}]>, openPath(path), linkSheet(path), drawAll(), panel(id, on), setTab(id), host, strip, styler } */
    constructor(opt) {
      Object.assign(this, opt); this.c = this.canvas;
      this.comps = new Map(); this.counts = new Map(); this.offs = []; this.snap = null; this.cache = { src: null, ver: -1, map: new Map() }; this.ver = 0;
      if (this.content) this.content.compSection = n => this.section(n);
      if (this.nav) this.nav.badge = n => this.badge(n);
      this.mountLib(); this.wrapDesign();
      this.tags = h('div', { class: 'ed-comp-tags', 'data-comp-tags': true }); this.c.layer.append(this.tags);
      window.KolComponentsLive = this;
      this.load();
    }
    unmount() { this.offs.forEach(f => f()); this.offs = []; this.tags.remove(); }

    // ── הקבצים של הרכיבים ─────────────────────────────────
    /** components/<שם>/component.json ← מסמכים פתוחים (template, json); שינוי בהם (גם מעורך הקוד / 📁) ← הפאנל מתעדכן. */
    async load() {
      let files = []; try { files = await Project.docs.files(); } catch { files = []; }
      const names = [...new Set(files.filter(f => f.kind === 'file' && (JSON_RE.test(f.path) || TPL_RE.test(f.path))).map(f => f.path.split('/')[1]))].sort();
      const next = new Map();
      for (const name of names) {
        const old = this.comps.get(name), jf = files.find(f => f.path === 'components/' + name + '/component.json'), tf = files.find(f => f.path === 'components/' + name + '/template.html');
        const x = old && old.jf?.id === jf?.id && old.tf?.id === tf?.id ? old : { name, jf, tf, json: null, tpl: null };
        x.css = files.some(f => f.path === 'components/' + name + '/style.css') ? 'components/' + name + '/style.css' : null;
        x.js = files.some(f => f.path === 'components/' + name + '/script.js') ? 'components/' + name + '/script.js' : null;
        if (x !== old) {
          for (const k of ['jf', 'tf']) { const f = x[k]; if (!f) continue; const d = await Project.docs.open(f.id).catch(() => null); if (!d || d.binary) continue; x[k === 'jf' ? 'json' : 'tpl'] = d; this.offs.push(d.on(kind => { if (kind === 'text') { this.ver++; this.reparse(x); this.drawLib(); this.redraw(); this.recount(); } })); }
          this.reparse(x);
        }
        next.set(name, x);
      }
      this.comps = next; this.ver++;
      this.drawLib(); this.redraw(); this.recount();
    }
    reparse(x) {
      x.schema = x.json ? C().parseSchema(x.json.text(), x.name) : { error: 'אין components/' + x.name + '/component.json', line: 1 };
      if (!x.tpl) x.schema = Object.assign({}, x.schema, { tplError: 'אין components/' + x.name + '/template.html' });
    }
    comp(name) { const x = this.comps.get(name); return x ? { schema: x.schema, template: x.tpl ? x.tpl.text() : null, x } : null; }
    /** מספרי המופעים בכל העמודים (לתגית "ישנה את כל N המופעים" ולמחיקה), מחושב מחדש אחרי שינוי — לא בכל ציור. */
    recount() {
      clearTimeout(this.rcT);
      this.rcT = setTimeout(async () => {
        const out = new Map();
        for (const p of await this.pages().catch(() => [])) {
          const t = p.doc.text(); if (t.indexOf(C().MARK) < 0) continue;
          let m; try { m = K().parseHtml(t); } catch { continue; }
          for (const i of C().instances(m)) { const o = out.get(i.name) || { total: 0, linked: 0, modified: 0, pages: new Set() }; const s = C().state(m, i.node, this.comp(i.name)).state; o.total++; if (s === 'linked') o.linked++; if (s === 'modified') o.modified++; o.pages.add(p.path); out.set(i.name, o); }
        }
        this.counts = out; this.drawLib();
      }, 250);
    }

    // ── המצב בעמוד הפתוח: מקושר / שונה / מנותק — מחושב (נשמר רק במטמון לפי הטקסט) ──
    stateOf(n) {
      const c = this.c; if (!c.m || !n) return null;
      if (this.cache.src !== c.m.src || this.cache.ver !== this.ver) this.cache = { src: c.m.src, ver: this.ver, map: new Map() };
      if (this.cache.map.has(n)) return this.cache.map.get(n);
      const a = K().attrOf(n, C().MARK); let s = null;
      if (a && !n.implied) { try { s = C().state(c.m, n, this.comp(a.value)); s.name = a.value; } catch (e) { s = { state: 'missing', error: e.message, name: a.value }; } }
      this.cache.map.set(n, s); return s;
    }
    badge(n) {
      const s = this.stateOf(n); if (!s) return null;
      const [icon, t] = STATE[s.state] || ['', '']; return { icon: icon + ' ' + s.name, state: s.state, title: '🧩 ' + s.name + ' · ' + t };
    }
    /** התגים על הקנבס: ◆ / ◇ ושם הרכיב בפינת השורש של כל מופע (לחיצה — בוחרת אותו). */
    drawTags() {
      const c = this.c; if (!c.m || !c.m2d) return;
      const want = [], H = c.host.clientHeight;
      if (c.m.src.indexOf(C().MARK) >= 0) for (const i of C().instances(c.m)) { const r = c.rectOf(i.node); if (r && !r.zero && r.top < H && r.top + r.height > 0) want.push({ n: i.node, r, b: this.badge(i.node) }); }
      const kids = this.tags.children;
      while (kids.length > want.length) kids[kids.length - 1].remove();
      want.forEach((w, i) => {
        let b = kids[i];
        if (!b) { b = h('button', { type: 'button', class: 'ed-comp-tag' }); b.addEventListener('click', () => { if (b._n) { c.select(b._n); c.reveal(b._n); } }); this.tags.append(b); }
        b._n = w.n; b.dataset.compTag = w.b ? w.b.state : ''; b.textContent = w.b ? w.b.icon : ''; b.title = w.b ? w.b.title + ' — לחיצה בוחרת את המופע' : '';
        b.style.cssText = 'left:' + Math.max(0, w.r.left + w.r.width) + 'px;top:' + Math.max(0, w.r.top - 18) + 'px;transform:translateX(-100%)';   // בפינה הנגדית לתווית הבחירה
      });
    }

    // ── הספרייה: לשונית "🧩 רכיבים" ────────────────────────
    mountLib() {
      if (!this.host) return;
      this.lib = h('div', { class: 'ed-lib cm-lib', 'data-comp-lib': true });
      this.host.append(this.lib);
    }
    drawLib() {
      if (!this.lib) return;
      const list = [...this.comps.values()], cats = new Map();
      for (const x of list) { const k = x.schema && !x.schema.error ? x.schema.category : 'שגיאה'; if (!cats.has(k)) cats.set(k, []); cats.get(k).push(x); }
      const out = [h('p', { class: 'hint' }, 'הרכיבים של הפרויקט (components/). גוררים אל הקנבס, או לוחצים — נכנס אחרי האלמנט הנבחר. כל רכיב הוא תיקייה של קבצים רגילים, פתוחה ב-📁.')];
      if (!list.length) out.push(h('p', { class: 'hint', 'data-comp-empty': true }, 'עוד אין רכיבים. בוחרים אלמנט בעמוד ← לשונית "תוכן" ← "🧩 שמור כרכיב".'));
      for (const [cat, xs] of cats) {
        out.push(h('h4', { class: 'dp-h' }, cat), h('div', { class: 'ed-lib-grid cm-grid', role: 'list', 'aria-label': 'רכיבים · ' + cat }, xs.map(x => {
          const s = x.schema || {}, n = this.counts.get(x.name), bad = s.error || s.tplError;
          const it = { id: 'comp-' + x.name, icon: s.icon || '🧩', label: s.label || x.name, run: t => this.add(x.name, t) };
          const b = this.libBtn(it, !!bad);
          b.title = (bad ? '⚠️ ' + bad : (s.label || x.name) + ' · components/' + x.name + '/') + (n ? ' · ' + n.total + ' מופעים ב-' + n.pages.size + ' עמודים' : ' · אין מופעים') + ' — לחיצה: אחרי הנבחר · גרירה: לכל מקום';
          if (n) b.append(h('span', { class: 'cm-count', 'data-comp-count': n.total }, String(n.total)));
          const row = h('div', { class: 'cm-item', role: 'listitem', 'data-comp': x.name }, b,
            h('button', { type: 'button', class: 'icon-btn cm-edit', 'data-comp-edit': x.name, 'aria-label': 'ערוך את הרכיב ' + (s.label || x.name), title: '✏️ ערוך את הרכיב', onclick: () => this.edit(x.name) }, '✏️'),
            h('button', { type: 'button', class: 'icon-btn cm-del', 'data-comp-del': x.name, 'aria-label': 'מחיקת הרכיב ' + (s.label || x.name), title: '🗑 מחיקת הרכיב (לסל)', onclick: () => this.remove(x.name) }, '🗑'));
          return row;
        })));
      }
      this.lib.replaceChildren(...out);
    }
    libBtn(it, off) {
      const bd = this.build, b = h('button', { type: 'button', class: 'ed-lib-item', 'data-lib': it.id, disabled: off || null }, h('span', { 'aria-hidden': 'true' }, it.icon), ' ', it.label);
      let p0 = null;
      b.addEventListener('pointerdown', e => { if (e.button > 0) return; p0 = { x: e.clientX, y: e.clientY, id: e.pointerId }; try { b.setPointerCapture(e.pointerId); } catch { /* */ } });
      b.addEventListener('pointermove', e => { if (p0 && !bd.drag.active && Math.hypot(e.clientX - p0.x, e.clientY - p0.y) > 4) { if (!this.canWrite()) { p0 = null; return; } try { b.releasePointerCapture(p0.id); } catch { /* */ } p0 = null; bd.drag.start({ lib: it }, e.clientX, e.clientY); } });
      b.addEventListener('pointerup', () => { p0 = null; });
      b.addEventListener('click', () => { if (bd.swallow) return; it.run(); });
      return b;
    }

    // ── הוספה לעמוד ──────────────────────────────────────
    /** ה-HTML של template.html עם ערכי ברירת המחדל, data-kol-component על השורש; ופעם אחת לעמוד — <link> ו-<script defer>.
     *  כל הכתיבות — עסקה אחת. t — יעד הגרירה ({ par, ref }); בלי — אחרי הנבחר. */
    async add(name, t) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      if (this.snap) return this.note('בעריכת רכיב לא מוסיפים רכיב (רכיב בתוך רכיב נתמך כ-HTML — בעמוד)', true);
      const cp = this.comp(name); if (!cp || cp.schema.error || cp.template == null) return this.note((cp && (cp.schema.error || cp.schema.tplError)) || 'הרכיב ' + name + ' חסר', true);
      let html; try { html = C().render(cp.template, cp.schema, C().defaults(cp.schema)).html; } catch (e) { return this.note(e.message, true); }
      const at = t || this.here(); if (!at || !at.par) return this.note('אין לאן להוסיף', true);
      const n0 = this.txCount(), it = { id: 'comp-' + name, label: cp.schema.label, html: this.indentIn(at.par, at.ref, html) };
      const ok = this.build.add(it, at.par, at.ref);
      if (ok === false || this.txCount() === n0) return;
      const sel = this.c.selected;
      await this.ensureAssets(name);
      const k = this.txCount() - n0; if (k > 1) this.joinTx(k, 'הוספת רכיב · ' + cp.schema.label);
      if (sel) { const P = K().pathOf(sel), cur = K().byPath(this.c.m, P); if (cur) { this.c.select(cur); this.c.reveal(cur); } }
      this.recount();
      this.note('נוסף הרכיב ' + cp.schema.label + (cp.x.js ? ' — יש לרכיב קוד (script.js): בקנבס הוא אינו רץ, ראו ▶️ תצוגה חיה' : ''), false, { label: 'בטל', run: this.undo });
    }
    /** כמו "לחיצה" בספרייה (V4): אחרי הנבחר; רובריקה נבחרת — בסופה; בלי בחירה — בסוף ה-body. */
    here() {
      const bd = this.build, m = this.c.m; let n = this.c.selected;
      for (let x = n; x; x = x.parent) if (x.tag === 'head') n = null;
      if (!n || n.tag === 'html') return { par: bd.body(), ref: null };
      if (bd.drag.isBox(n) && !this.X.inTemplate(n) && n.tag !== 'template' && n.tag !== 'body') return { par: n, ref: null };
      if (n.tag === 'body') return { par: n, ref: null };
      void m; return { par: n.parent, ref: n.parent.kids[n.parent.kids.indexOf(n) + 1] || null };
    }
    /** שורות ההמשך של ה-HTML מוזחות להזחה של האחים במקום ההוספה (template.html כתוב מעמודה 0). */
    indentIn(par, ref, html) {
      const m = this.c.m, sib = ref || [...par.kids].reverse().find(k => !k.implied);
      let ind = '';
      if (sib) ind = m.src.slice(K().leadOf(m, sib), sib.from).replace(/^[\s\S]*[\r\n]/, '');
      else if (!par.implied) { const ls = m.src.lastIndexOf('\n', par.st[0]) + 1; ind = (/^[ \t]*/.exec(m.src.slice(ls, par.st[0])) || [''])[0] + '  '; }
      return /^[ \t]+$/.test(ind) ? html.replace(/\r?\n(?=[^\r\n])/g, nl => nl + ind) : html;
    }
    /** <link> ל-style.css ו-<script defer> ל-script.js של הרכיב — רק אם העמוד עוד אינו מפנה אליהם. */
    async ensureAssets(name) {
      const x = this.comps.get(name); if (!x) return;
      const refs = this.refs();
      if (x.css && !refs.has(x.css)) await this.linkSheet(x.css);
      if (x.js && !refs.has(x.js)) {
        const head = this.c.m.root && this.c.m.root.kids.find(k => k.tag === 'head' && !k.implied);
        if (head) this.act('קישור ' + x.js + ' לעמוד', m => K().html.insert(m, K().byPath(m, K().pathOf(head)), null, '<script defer src="' + window.KolText.escAttr(this.rel(x.js)) + '"></script>'), head);
      }
    }
    /** הנתיבים בפרויקט שהעמוד הפתוח מפנה אליהם (link href, script src). */
    refs(m) {
      const out = new Set(); m = m || this.c.m; if (!m || !m.root) return out;
      K().walk(m.root, n => { const a = n.tag === 'link' ? K().attrOf(n, 'href') : n.tag === 'script' ? K().attrOf(n, 'src') : null; if (a) { const p = this.refPath(a.value); if (p) out.add(p); } });
      return out;
    }

    // ── "תוכן": ההגדרות של המופע ───────────────────────────
    section(n) {
      if (!n || !this.c.m) return [];
      const tm = this.tplName();
      if (tm) return [this.boardFor(tm, n)];
      const root = C().instanceOf(n);
      if (!root) {
        if (/^(html|head|body|title|meta|link|script|style|base|template)$/.test(n.tag) || n.implied) return [];
        for (let p = n; p; p = p.parent) if (p.tag === 'head' || p.tag === 'template') return [];
        return [this.group('🧩 רכיב', h('p', { class: 'hint' }, 'האלמנט הזה (עם כל מה שבתוכו) יכול להפוך לרכיב: תיקייה ב-components/, והאלמנט — המופע הראשון שלו.'), this.btn('🧩 שמור כרכיב', () => this.create(n), !this.canWrite(), 'comp-save'))];
      }
      const s = this.stateOf(root), name = s ? s.name : '', cp = this.comp(name), sch = cp && cp.schema, label = sch && !sch.error ? sch.label : name;
      const head = h('div', { class: 'cm-head' }, h('b', {}, (sch && sch.icon || '🧩') + ' ' + label), ' ', h('span', { class: 'cm-state', 'data-comp-state': s ? s.state : '' }, s ? (STATE[s.state] || ['', ''])[0] + ' ' + (STATE[s.state] || ['', ''])[1] : ''));
      const kids = [head];
      if (n !== root) kids.push(h('p', { class: 'hint' }, 'חלק ברכיב ' + label + '. עיצוב, תוכן ומבנה — כמו כל אלמנט.'), this.btn('↑ בחר את שורש הרכיב', () => { this.c.select(root); this.c.reveal(root); }, false, 'comp-root'));
      if (s && s.state === 'missing') kids.push(h('p', { class: 'ed-warn', 'data-comp-error': true }, '⚠️ ' + (s.error || sch && (sch.error || sch.tplError) || 'קובצי הרכיב חסרים') + ' — ההגדרות אינן מוצגות; ה-HTML של המופע נשאר כמו שהוא.'));
      if (cp && cp.x.js && n === root) kids.push(h('p', { class: 'hint', 'data-comp-js': true }, '⚙️ יש לרכיב קוד (script.js) — בקנבס הוא אינו רץ, וזה המצב הסטטי. ראו ▶️ תצוגה חיה.'));
      const w = !this.canWrite();
      if (n === root && s && s.state !== 'missing') {
        kids.push(h('div', { class: 'cm-acts' },
          this.btn('✏️ ערוך את הרכיב', () => this.edit(name), false, 'comp-edit'),
          s.state === 'modified' ? this.btn('🔍 הצג את ההבדלים', () => this.showDiff(root), false, 'comp-diff') : null,
          s.state === 'modified' ? this.btn('↺ חזור לרכיב', () => this.revert(root), w, 'comp-revert') : null,
          this.btn('⛓ נתק', () => this.detach(root), w, 'comp-detach'),
          this.btn('➕ הוסף לכל העמודים', () => this.addAll(root), w, 'comp-all')));
      }
      if (sch && !sch.error && s && s.state !== 'missing') kids.push(...this.fields(root, sch, w));
      if (sch && sch.error) kids.push(h('p', { class: 'ed-warn', 'data-comp-json-error': sch.line || 1 }, '⚠️ ' + sch.error), this.btn('📁 פתח את component.json', () => Project.openAt('components/' + name + '/component.json', sch.line || 1), false, 'comp-json'));
      if (s && s.state !== 'missing' && sch && !sch.error) kids.push(this.btn('➕ הוסף הגדרה — מהחלק הנבחר', () => this.addSetting(n), w || s.state === 'modified', 'comp-add-setting'), s.state === 'modified' ? h('p', { class: 'hint' }, 'במופע ששונה במקום מוסיפים הגדרה מתוך "✏️ ערוך את הרכיב".') : null);
      return [this.group('🧩 רכיב · ' + label, ...kids.filter(Boolean))];
    }
    group(title, ...kids) { return h('section', { class: 'ct-group cm-group', 'data-group': title, 'data-comp-panel': true }, h('h3', { class: 'dt-h' }, title), ...kids); }
    btn(text, fn, off, key) { return h('button', { type: 'button', class: 'btn sm', disabled: !!off, 'data-w': key || true, onclick: fn }, text); }
    /** השדות של הסכמה, בסדר שלהם, עם הערך מהמופע. חלק שחסר — שדה מושבת עם ההסבר. */
    fields(root, sch, ro) {
      const { values, missing } = C().readValues(this.c.m, root, sch.fields), out = [];
      if (!sch.fields.length) out.push(h('p', { class: 'hint', 'data-comp-nofields': true }, 'לרכיב עוד אין הגדרות. בוחרים חלק בו (בקנבס או ב-Navigator) ← "➕ הוסף הגדרה".'));
      for (const f of sch.fields) {
        const row = h('div', { class: 'ct-row cm-field', 'data-comp-field': f.id }, h('span', { class: 'ct-k' }, f.label));
        if (f.bad) { row.append(h('span', { class: 'ed-warn' }, '⚠️ ' + f.bad)); out.push(row); continue; }
        if (missing.includes(f.id)) { const ctl = h('input', { type: 'text', class: 'ct-in', disabled: true, 'data-comp-missing': f.id }); row.append(ctl, h('span', { class: 'hint ct-hint' }, 'החלק ' + (f.target.text || f.target.src || f.target.children || f.target.on || '') + ' חסר במופע הזה')); out.push(row); continue; }
        row.append(this.control(root, f, values[f.id], ro));
        out.push(row);
      }
      return out;
    }
    control(root, f, v, ro) {
      const set = val => this.setField(root, f, val), k = C().kindOf(f);
      const inp = (type, val, extra) => { const e = h('input', Object.assign({ type, class: 'ct-in', dir: type === 'text' && /size|number|link|font/.test(f.control) ? 'ltr' : 'auto', 'data-cf': f.id, disabled: ro || null }, extra || {})); e.value = val == null ? '' : String(val); e.addEventListener('change', () => set(e.value.trim())); return e; };
      if (k === 'children') return this.repeater(root, f, v, ro);
      if (f.control === 'toggle') { const e = h('input', { type: 'checkbox', 'data-cf': f.id, disabled: ro || null }); e.checked = !!v; e.addEventListener('change', () => set(e.checked)); return e; }
      if (f.control === 'select' || k === 'class') { const e = h('select', { class: 'ct-in', 'data-cf': f.id, disabled: ro || null }, h('option', { value: '' }, '—'), f.options.map(o => h('option', { value: o }, o))); e.value = v == null ? '' : String(v); e.addEventListener('change', () => set(e.value)); return e; }
      if (f.control === 'rich') { const e = h('textarea', { class: 'ct-in', rows: 3, 'data-cf': f.id, disabled: ro || null }); e.value = v == null ? '' : String(v); e.addEventListener('change', () => set(e.value)); return e; }
      if (f.control === 'number') return inp('number', v, { step: 'any' });
      if (f.control === 'color') {
        const txt = inp('text', v, { dir: 'ltr', placeholder: '#000000' }), pk = h('input', { type: 'color', 'data-cf-color': f.id, disabled: ro || null });
        if (/^#[0-9a-f]{6}$/i.test(String(v || ''))) pk.value = v; pk.addEventListener('change', () => set(pk.value));
        return h('span', { class: 'cm-color' }, pk, txt);
      }
      if (f.control === 'media') {
        const e = inp('text', v, { dir: 'ltr' });
        return h('span', { class: 'cm-media' }, e, this.btn('בחר…', async () => { const p = await this.pick(/img/.test(f.target.src || '') ? 'image' : 'any', 'בחירת קובץ · ' + f.label); if (p) set(this.rel(p)); }, ro, 'cf-pick-' + f.id));
      }
      return inp('text', v, f.control === 'link' ? { dir: 'ltr', placeholder: 'https://…' } : {});
    }
    /** כתיבת ערך של שדה למופע: היעד של 02 §7.1 (component-state), עסקה אחת עם שם. */
    setField(root, f, value) {
      const P = K().pathOf(root), lab = this.c.label(root);
      if (f.control === 'size' && value && C().kindOf(f) === 'var') { try { C().checkVarValue(value); } catch (e) { this.note(e.message, true); return this.redraw(); } }
      const ok = this.act('הגדרה · ' + f.label + ' · ' + lab + ' ← ' + (typeof value === 'boolean' ? (value ? 'כן' : 'לא') : String(value).slice(0, 30)), m => ({ patches: C().planField(m, K().byPath(m, P), f, value) }), root);
      const cur = K().byPath(this.c.m, P); if (cur && this.c.selected !== cur) this.c.select(cur);
      if (!ok) this.redraw();
      return ok;
    }
    /** repeater: שורה לכל פריט — ⠿ גרירה לסדר · ↑ ↓ · ⧉ שכפול · 🗑 מחיקה; "＋ פריט". בקנבס — גרירה רגילה של V4. */
    repeater(root, f, v, ro) {
      const P = K().pathOf(root), items = C().itemsOf(root, f), list = h('ol', { class: 'cm-rep', 'data-cf': f.id });
      const op = (label, fn) => { this.act(label + ' · ' + f.label, m => { const r = K().byPath(m, P), its = C().itemsOf(r, f); return fn(m, its); }, root); const cur = K().byPath(this.c.m, P); if (cur) this.c.select(cur); };
      items.forEach((it, i) => {
        const txt = (() => { try { return K().textOf(this.c.m, it); } catch { return ''; } })() || this.c.label(it);
        const grip = h('button', { type: 'button', class: 'icon-btn cm-grip', 'aria-label': 'גרירה לסדר', title: 'גרירה לסדר', 'data-rep-grip': i, disabled: ro || null }, '⠿');
        const li = h('li', { class: 'cm-rep-row', 'data-rep': i }, grip, h('span', { class: 'cm-rep-txt' }, txt.slice(0, 50)),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'למעלה', 'data-rep-up': i, disabled: ro || !i || null, onclick: () => op('סדר', (m, its) => K().html.move(m, its[i], its[i].parent, its[i].parent.kids.indexOf(its[i - 1]))) }, '↑'),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'למטה', 'data-rep-down': i, disabled: ro || i === items.length - 1 || null, onclick: () => op('סדר', (m, its) => K().html.move(m, its[i + 1], its[i + 1].parent, its[i + 1].parent.kids.indexOf(its[i]))) }, '↓'),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'שכפול', 'data-rep-dup': i, disabled: ro || null, onclick: () => op('שכפול פריט', (m, its) => K().html.duplicate(m, its[i])) }, '⧉'),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'מחיקה', 'data-rep-del': i, disabled: ro || items.length < 2 || null, title: items.length < 2 ? 'הפריט האחרון — משכפלים לפני שמוחקים' : 'מחיקה', onclick: () => op('מחיקת פריט', (m, its) => K().html.remove(m, its[i])) }, '🗑'));
        li.addEventListener('mouseenter', () => { const d = this.c.elOf(it); if (d) { this.c.hovered = it; this.c.draw(); } });
        // גרירה לסדר ברשימה (עכבר ומגע): שחרור מעל שורה אחרת ← הפריט עובר למקומה
        grip.addEventListener('pointerdown', e => {
          if (ro) return; e.preventDefault(); try { grip.setPointerCapture(e.pointerId); } catch { /* */ }
          li.classList.add('dragging'); let to = i;
          const mv = ev => { const rows = [...list.children]; to = i; rows.forEach((r, j) => { const b = r.getBoundingClientRect(); if (ev.clientY > b.top + b.height / 2) to = j; else if (j < i && ev.clientY < b.top + b.height / 2 && to === i) to = Math.min(to, j); }); for (let j = 0; j < rows.length; j++) { const b = rows[j].getBoundingClientRect(); if (ev.clientY < b.top + b.height / 2) { to = j > i ? j - 1 : j; break; } to = j; } rows.forEach((r, j) => r.classList.toggle('drop-at', j === to && to !== i)); };
          const up = () => { grip.removeEventListener('pointermove', mv); grip.removeEventListener('pointerup', up); grip.removeEventListener('pointercancel', up); li.classList.remove('dragging'); if (to !== i) op('סדר בגרירה', (m, its) => { const a = its[i], b = its[to]; return K().html.move(m, a, a.parent, a.parent.kids.indexOf(b) + (to > i ? 1 : 0)); }); else this.redraw(); };
          grip.addEventListener('pointermove', mv); grip.addEventListener('pointerup', up); grip.addEventListener('pointercancel', up);
        });
        list.append(li);
      });
      return h('div', { class: 'cm-repwrap' }, list, items.length ? h('button', { type: 'button', class: 'btn sm', 'data-rep-add': f.id, disabled: ro || null, onclick: () => op('הוספת פריט', (m, its) => K().html.duplicate(m, its[its.length - 1])) }, '＋ פריט') : h('p', { class: 'hint' }, 'אין פריטים במופע.'), h('p', { class: 'hint' }, 'בקנבס — גוררים פריט כמו כל אלמנט.'));
    }

    // ── מקושר / שונה / מנותק ──────────────────────────────
    showDiff(root, other) {
      const s = this.stateOf(root); if (!s || !s.expected) return;
      this.sheet('ההבדלים מהרכיב · ' + s.name, h('p', { class: 'hint' }, '− מה שבמופע ואינו ברכיב · + מה שהרכיב היה כותב (עם ערכי ההגדרות של המופע).'), this.diffView(s.actual, other || s.expected));
    }
    diffView(a, b) {
      const d = C().diffLines(a, b);
      return h('pre', { class: 'cm-diff', dir: 'ltr', 'data-comp-diffview': true }, d.filter((x, i) => x.t !== ' ' || d.slice(Math.max(0, i - 2), i + 3).some(y => y.t !== ' ')).map(x => h('div', { class: x.t === '-' ? 'del' : x.t === '+' ? 'add' : '' }, x.t + ' ' + x.line.trim())));
    }
    detach(root) {
      const P = K().pathOf(root), name = (K().attrOf(root, C().MARK) || {}).value;
      if (this.act('נתק מהרכיב · ' + name, m => K().html.removeAttr(m, K().byPath(m, P), C().MARK), root)) { const cur = K().byPath(this.c.m, P); if (cur) this.c.select(cur); this.recount(); this.note('המופע נותק — HTML רגיל', false, { label: 'בטל', run: this.undo }); }
    }
    /** "חזור לרכיב": המופע מצויר מחדש מהתבנית (עם ערכי ההגדרות שלו) — אחרי ה-diff. */
    revert(root) {
      const s = this.stateOf(root); if (!s || !s.expected) return;
      const go = h('button', { type: 'button', class: 'btn primary', 'data-comp-revert-go': true }, '↺ החלף את המופע');
      const dlg = this.sheet('חזרה לרכיב · ' + s.name, h('p', { class: 'hint' }, 'השינוי המקומי יאבד — זה מה שיוחלף (− יוסר, + ייכתב):'), this.diffView(s.actual, s.expected), h('div', { class: 'ed-ask-bar' }, go));
      go.addEventListener('click', () => {
        dlg.close();
        const P = K().pathOf(root);
        if (this.act('חזרה לרכיב · ' + s.name, m => { const r = K().byPath(m, P); return { patches: [{ from: r.from, to: K().endOf(m, r), text: this.indent(m, r, C().render(this.comp(s.name).template, this.comp(s.name).schema, C().readValues(m, r, this.comp(s.name).schema.fields).values).html) }] }; }, root)) {
          const cur = K().byPath(this.c.m, P); if (cur) { this.c.select(cur); this.c.reveal(cur); } this.recount();
        }
      });
    }
    /** HTML שמחליף מופע: שורות ההמשך מוזחות כמו שורת השורש בקובץ. */
    indent(m, r, html) {
      const lead = m.src.slice(K().leadOf(m, r), r.from).replace(/^[\r\n]+/, '').replace(/^.*[\r\n]/s, '');
      return /^[ \t]*$/.test(lead) && lead ? html.replace(/\r?\n(?=[^\r\n])/g, nl => nl + lead).replace(/\s+$/, '') : html.replace(/\s+$/, '');
    }
    /** "הוסף לכל העמודים" (כותרת / תחתית משותפת, 01-gap 8.4): בכל עמוד שאין בו את הרכיב — בראש ה-body (אם המופע הראשון
     *  בגוף) או בסופו, עם ערכי ההגדרות של המופע הזה, ו-<link> / <script> — עסקה אחת. */
    async addAll(root) {
      const s = this.stateOf(root); if (!s) return;
      const cp = this.comp(s.name), body = root.parent, first = body && body.tag === 'body' && body.kids.find(k => !k.implied && k.tag !== 'script') === root;
      const html = C().render(cp.template, cp.schema, C().readValues(this.c.m, root, cp.schema.fields).values).html;
      const pages = (await this.pages()).filter(p => p.doc !== this.doc() && !C().instances(K().parseHtml(p.doc.text()), s.name).length);
      if (!pages.length) return this.note('הרכיב כבר נמצא בכל העמודים');
      const ops = [];
      for (const p of pages) ops.push(() => {
        const m = K().parseHtml(p.doc.text()), b = m.root && m.root.kids.find(k => k.tag === 'body'); if (!b) return [];
        const idx = first ? 0 : b.kids.filter(k => !k.implied).length && b.kids[b.kids.length - 1].tag === 'script' ? b.kids.findIndex(k => k.tag === 'script' && b.kids.slice(b.kids.indexOf(k)).every(x => x.tag === 'script')) : null;
        return [{ doc: p.doc, patches: window.KolStruct.insert(m, b, idx == null || idx < 0 ? null : idx, html).patches }];
      }, () => this.headOps(p, cp.x));
      const docs = this.writeOps(ops, 'הוסף לכל העמודים · ' + cp.schema.label + ' · ' + pages.length + ' עמודים');
      if (docs) { this.recount(); this.note('הרכיב ' + cp.schema.label + ' נוסף ל-' + pages.length + ' עמודים: ' + pages.map(p => p.path).join(', '), false, { label: 'בטל', run: this.undo }); }
    }
    /** <link> / <script> של הרכיב בעמוד אחר (לא הפתוח) — מה שחסר בלבד. */
    headOps(p, x) {
      const m = K().parseHtml(p.doc.text()), head = m.root && m.root.kids.find(k => k.tag === 'head' && !k.implied); if (!head) return [];
      const dir = p.path.includes('/') ? p.path.slice(0, p.path.lastIndexOf('/') + 1) : '', rel = path => '../'.repeat(dir.split('/').filter(Boolean).length) + path.split('/').map(encodeURIComponent).join('/');
      const has = path => { let hit = false; K().walk(m.root, n => { const a = n.tag === 'link' ? K().attrOf(n, 'href') : n.tag === 'script' ? K().attrOf(n, 'src') : null; if (a && a.value.split(/[?#]/)[0].replace(/^\.\//, '') === rel(path)) hit = true; }); return hit; };
      let add = '';
      if (x.css && !has(x.css)) add += '<link rel="stylesheet" href="' + window.KolText.escAttr(rel(x.css)) + '">';
      if (x.js && !has(x.js)) add += '<script defer src="' + window.KolText.escAttr(rel(x.js)) + '"></script>';
      return add ? [{ doc: p.doc, patches: K().html.insert(m, head, null, add).patches }] : [];
    }

    // ── עריכת הרכיב עצמו ─────────────────────────────────
    tplName() { const f = this.file(); const m = f && TPL_RE.exec(f.path); return m ? m[1] : null; }
    async edit(name) {
      const x = this.comps.get(name); if (!x || !x.tf) return this.note('לרכיב ' + name + ' אין template.html', true);
      const f = this.file(); if (f && !TPL_RE.test(f.path)) this.back = f.path;
      await this.openPath(x.tf.path);
    }
    /** נקרא אחרי שקובץ נפתח בעורך: תבנית של רכיב ← תמונת המצב שלפני העריכה (למופעים), והפס "עורך את הרכיב". */
    opened(f) {
      const m = f && TPL_RE.exec(f.path);
      if (!m) { this.snap = null; this.drawStrip(); return; }
      const x = this.comps.get(m[1]);
      this.snap = { name: m[1], tpl: this.doc().text(), json: x && x.json ? x.json.text() : null };
      this.drawStrip();
    }
    drawStrip() {
      const el = this.strip; if (!el) return;
      if (!this.snap) { el.hidden = true; el.replaceChildren(); return; }
      const x = this.comps.get(this.snap.name), lab = x && x.schema && !x.schema.error ? x.schema.label : this.snap.name, n = this.counts.get(this.snap.name);
      el.hidden = false;
      el.replaceChildren(h('b', {}, '✏️ עורך את הרכיב ' + lab), h('span', { class: 'hint' }, ' · components/' + this.snap.name + '/template.html' + (x && x.css ? ' + style.css' : '') + (n ? ' · ' + n.total + ' מופעים ב-' + n.pages.size + ' עמודים' : ' · אין מופעים')),
        h('button', { type: 'button', class: 'btn sm primary', 'data-comp-apply': true, onclick: () => this.applyAll() }, '💾 שמור ועדכן מופעים'),
        h('button', { type: 'button', class: 'btn sm', 'data-comp-json-open': true, onclick: () => Project.openAt('components/' + this.snap.name + '/component.json', 1) }, '{ } component.json'),
        this.back ? h('button', { type: 'button', class: 'btn sm', 'data-comp-back': true, onclick: () => this.openPath(this.back) }, '↩ חזרה ל-' + this.back) : null);
    }
    /** "שמור ועדכן מופעים": כל המופעים בכל העמודים — לכל אחד ה-diff; מקושרים ← עדכון (הערכים נשמרים); שונים ← עדכן / נתק / דלג.
     *  "החל" — כל הקבצים בעסקה אחת (Ctrl+Z אחד). */
    async applyAll() {
      const sn = this.snap; if (!sn) return;
      const x = this.comps.get(sn.name); if (!x || !x.tpl) return;
      const oldS = sn.json != null ? C().parseSchema(sn.json, sn.name) : x.schema, newS = x.schema;
      if (newS.error) return this.note('component.json אינו תקין — ' + newS.error, true);
      const newT = x.tpl.text(), rows = [];
      for (const p of await this.pages()) {
        const m = K().parseHtml(p.doc.text());
        C().instances(m, sn.name).forEach((inst, i) => {
          const st = C().state(m, inst.node, { schema: oldS, template: sn.tpl }).state, actual = C().outerOf(m, inst.node);
          let html; try { html = C().updated(m, inst.node, oldS, newS, newT); } catch (e) { html = null; }
          const same = html != null && C().canon(html) === C().canon(actual);
          rows.push({ p, i, st, actual, html, same, choice: same ? 'skip' : st === 'linked' ? 'update' : 'skip' });
        });
      }
      const list = h('div', { class: 'cm-upd', 'data-comp-upd': true });
      const sum = h('p', { class: 'hint', role: 'status' });
      const draw = () => {
        list.replaceChildren(...(rows.length ? rows.map((r, k) => {
          const box = h('div', { class: 'cm-upd-row', 'data-upd': r.p.path + '#' + r.i, 'data-upd-state': r.st });
          box.append(h('div', { class: 'cm-upd-h' }, h('b', { dir: 'ltr' }, r.p.path), ' · מופע ' + (r.i + 1) + ' · ', h('span', {}, r.same ? '✓ כבר זהה' : r.st === 'linked' ? '◆ מקושר — יעודכן (ערכי ההגדרות נשמרים)' : r.st === 'modified' ? '◇ שונה במקום' : '⚠ ' + r.st)));
          if (!r.same && r.st !== 'linked') {
            const opts = [['update', 'עדכן (השינוי המקומי יאבד — מוצג)'], ['detach', 'נתק'], ['skip', 'דלג']];
            box.append(h('div', { class: 'cm-upd-ch', role: 'radiogroup' }, opts.map(([v, t]) => { const i2 = h('input', { type: 'radio', name: 'upd' + k, value: v, 'data-upd-choice': v, checked: r.choice === v || null }); i2.addEventListener('change', () => { r.choice = v; draw(); }); return h('label', {}, i2, ' ' + t); })));
          } else if (!r.same) { const cb = h('input', { type: 'checkbox', value: 'update', 'data-upd-choice': 'update', checked: r.choice === 'update' || null }); cb.addEventListener('change', () => { r.choice = cb.checked ? 'update' : 'skip'; draw(); }); box.append(h('label', {}, cb, ' עדכן')); }
          if (!r.same && r.html != null && r.choice === 'update') box.append(this.diffView(r.actual, r.html));
          return box;
        }) : [h('p', { class: 'hint' }, 'אין מופעים של הרכיב באף עמוד — השינוי בתבנית כבר שמור.')]));
        const n = rows.filter(r => !r.same && r.choice !== 'skip').length;
        sum.textContent = n ? n + ' מופעים ישתנו, ב-' + new Set(rows.filter(r => !r.same && r.choice !== 'skip').map(r => r.p.path)).size + ' קבצים — פעולה אחת (Ctrl+Z אחד מחזיר הכול).' : 'אין מה לעדכן.';
      };
      draw();
      const go = h('button', { type: 'button', class: 'btn primary', 'data-comp-apply-go': true }, 'החל');
      const dlg = this.sheet('שמור ועדכן מופעים · ' + (newS.label || sn.name), h('p', { class: 'hint' }, 'השינויים בתבנית כבר שמורים בקובץ. כאן — מה יקרה לכל מופע בעמודים.'), list, sum, h('div', { class: 'ed-ask-bar' }, go));
      go.addEventListener('click', () => {
        dlg.close();
        const todo = rows.filter(r => !r.same && r.choice !== 'skip').sort((a, b) => (a.p.path === b.p.path ? b.i - a.i : a.p.path.localeCompare(b.p.path)));
        if (!todo.length) { this.snap = Object.assign({}, sn, { tpl: newT, json: x.json ? x.json.text() : sn.json }); return this.note('אין מה לעדכן'); }
        const ops = todo.map(r => () => {
          const m = K().parseHtml(r.p.doc.text()), inst = C().instances(m, sn.name)[r.i]; if (!inst) return [];
          if (r.choice === 'detach') return [{ doc: r.p.doc, patches: K().html.removeAttr(m, inst.node, C().MARK).patches }];
          return [{ doc: r.p.doc, patches: [{ from: inst.node.from, to: K().endOf(m, inst.node), text: this.indent(m, inst.node, C().updated(m, inst.node, oldS, newS, newT)) }] }];
        });
        const docs = this.writeOps(ops, 'עדכון מופעים · ' + (newS.label || sn.name) + ' · ' + todo.length + ' מופעים ב-' + new Set(todo.map(r => r.p.path)).size + ' קבצים');
        if (!docs) return;
        this.snap = Object.assign({}, sn, { tpl: newT, json: x.json ? x.json.text() : sn.json });
        this.recount();
        this.note('עודכנו ' + todo.filter(r => r.choice === 'update').length + ' מופעים' + (todo.some(r => r.choice === 'detach') ? ', נותקו ' + todo.filter(r => r.choice === 'detach').length : ''), false, { label: 'בטל', run: this.undo });
      });
    }
    /** "הגדרות הרכיב" — בעריכת התבנית: השדות (שם, סוג, יעד), סדר, מחיקה, ו"➕ הוסף הגדרה" מהחלק הנבחר. */
    boardFor(name, n) {
      const x = this.comps.get(name), sch = x && x.schema;
      const kids = [h('p', { class: 'hint' }, 'הפאנל שבעל העמוד רואה במופע. נשמר ב-components/' + name + '/component.json — קובץ רגיל, פתוח ב-📁.')];
      if (!sch || sch.error) kids.push(h('p', { class: 'ed-warn', 'data-comp-json-error': sch ? sch.line : 1 }, '⚠️ ' + (sch ? sch.error : 'אין component.json')), this.btn('📁 פתח את component.json', () => Project.openAt('components/' + name + '/component.json', sch ? sch.line : 1), false, 'comp-json'));
      else {
        const ro = !this.canWrite();
        kids.push(h('ol', { class: 'cm-board', 'data-comp-board': name }, sch.fields.map((f, i) => h('li', { 'data-board-field': f.id },
          h('b', {}, f.label), ' ', h('code', { dir: 'ltr' }, f.control + ' → ' + JSON.stringify(f.target)), f.bad ? h('span', { class: 'ed-warn' }, ' ⚠️ ' + f.bad) : null,
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'למעלה', disabled: ro || !i || null, onclick: () => this.editFields(name, fs => { const [a] = fs.splice(i, 1); fs.splice(i - 1, 0, a); return fs; }, 'סדר הגדרות') }, '↑'),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'מחיקת ההגדרה', 'data-board-del': f.id, disabled: ro || null, onclick: () => this.editFields(name, fs => fs.filter((_, j) => j !== i), 'מחיקת הגדרה · ' + f.label) }, '🗑')))));
        if (!sch.fields.length) kids.push(h('p', { class: 'hint' }, 'עוד אין הגדרות.'));
        kids.push(this.btn('➕ הוסף הגדרה — מהחלק הנבחר', () => this.addSetting(n), ro, 'comp-add-setting'));
      }
      return this.group('⚙ הגדרות הרכיב · ' + (sch && sch.label || name), ...kids);
    }
    editFields(name, fn, label) {
      const x = this.comps.get(name); if (!x || !x.json) return;
      let next; try { next = C().editFieldsText(x.json.text(), fn); } catch (e) { return this.note(e.message, true); }
      const was = x.json.text(); this.writeOps([{ doc: x.json, patches: [{ from: 0, to: was.length, text: next }] }], label + ' · ' + name);
    }

    // ── "➕ הוסף הגדרה" ───────────────────────────────────
    /** מצביעים על חלק (הנבחר) ובוחרים מה נחשף ← שורה ב-fields. הבורר נגזר (מחלקה קיימת; אחרת מחלקה חדשה בשם קריא — נכתבת
     *  לתבנית ולכל המופעים המקושרים, כדי שיישארו מקושרים). הכול — עסקה אחת. */
    async addSetting(n) {
      if (!n) return this.note('בוחרים חלק ברכיב, ואז "הוסף הגדרה"');
      const tm = this.tplName(), m0 = this.c.m;
      const root = tm ? C().rootOf(m0) : C().instanceOf(n);
      if (!root) return this.note('בוחרים חלק בתוך מופע של רכיב', true);
      if (tm && !(n === root || (() => { for (let p = n; p; p = p.parent) if (p === root) return true; return false; })())) return this.note('בוחרים חלק בתוך הרכיב', true);
      const name = tm || (K().attrOf(root, C().MARK) || {}).value, x = this.comps.get(name); if (!x || !x.json || !x.tpl) return this.note('קובצי הרכיב חסרים', true);
      const rel = []; for (let p = n; p && p !== root; p = p.parent) rel.unshift(p.parent.kids.indexOf(p));
      const attrs = n.attrs.map(a => a.name).filter(a => { try { C().checkAttrName(a); return !/^(class|id)$/.test(a); } catch { return false; } });
      const hasText = (() => { try { K().textOf(m0, n); return true; } catch { return false; } })();
      const kinds = Object.keys(KINDS).filter(k => k === 'text' ? hasText : k === 'attr' ? true : k === 'src' ? /^(img|audio|video|source|a|iframe)$/.test(n.tag) : k === 'children' ? n.kids.some(c2 => !c2.implied) : true);
      const kindSel = h('select', { class: 'ct-in', 'data-as': 'kind', 'aria-label': 'מה נחשף' }, kinds.map(k => h('option', { value: k }, KINDS[k])));
      const label = h('input', { type: 'text', class: 'ct-in', 'data-as': 'label', 'aria-label': 'שם ההגדרה', placeholder: 'למשל: כיתוב הכפתור' });
      const extra = h('div', { class: 'cm-as-extra' }), err = h('p', { class: 'ed-warn', hidden: true, 'data-as-err': true });
      let get = () => ({});
      const drawExtra = () => {
        const k = kindSel.value;
        if (k === 'attr') {
          const sel = h('select', { class: 'ct-in', 'data-as': 'attr' }, attrs.map(a => h('option', { value: a }, a + '="' + (K().attrOf(n, a).value || '').slice(0, 30) + '"')), h('option', { value: '__new' }, 'תכונה אחרת…'));
          const other = h('input', { type: 'text', class: 'ct-in', dir: 'ltr', 'data-as': 'attr-new', placeholder: 'שם התכונה (למשל title)' });
          const tg = h('input', { type: 'checkbox', 'data-as': 'toggle' });
          extra.replaceChildren(h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'התכונה'), sel), h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'או'), other), h('label', { class: 'ct-check' }, tg, h('span', {}, 'מתג (קיימת / אינה קיימת)')));
          get = () => ({ attr: sel.value === '__new' || !attrs.length ? other.value.trim() : sel.value, toggle: tg.checked });
        } else if (k === 'class') {
          const opts = h('input', { type: 'text', class: 'ct-in', dir: 'ltr', 'data-as': 'options', placeholder: 'big small boxed', value: C().selectorFor(root, n) ? '' : '' });
          extra.replaceChildren(h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'האפשרויות (שמות מחלקות, ברווח)'), opts));
          get = () => ({ options: opts.value.trim().split(/[\s,]+/).filter(Boolean) });
        } else if (k === 'var') {
          const prop = h('select', { class: 'ct-in', 'data-as': 'prop' }, VAR_PROPS.map(p => h('option', { value: p }, p)));
          extra.replaceChildren(h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'המאפיין'), prop), h('p', { class: 'hint' }, 'הערך הנוכחי יהפוך ל-var(--…, <הערך>) ב-style.css של הרכיב, וההגדרה תכתוב את המשתנה בשורש המופע.'));
          get = () => ({ prop: prop.value });
        } else extra.replaceChildren();
        if (k !== 'attr' && k !== 'class' && k !== 'var') get = () => ({});
      };
      kindSel.addEventListener('change', drawExtra); drawExtra();
      const go = h('button', { type: 'button', class: 'btn primary', 'data-as-go': true }, 'הוסף הגדרה');
      const dlg = this.sheet('➕ הוסף הגדרה · ' + this.c.label(n), h('p', { class: 'hint' }, 'החלק: ' + this.c.label(n) + '. בוחרים מה בעל העמוד ישנה בפאנל, ונותנים שם.'), h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'מה נחשף'), kindSel), extra, h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'שם ההגדרה'), label), err, h('div', { class: 'ed-ask-bar' }, go));
      setTimeout(() => label.focus(), 0);
      go.addEventListener('click', async () => {
        try {
          const k = kindSel.value, o = get(), lab = label.value.trim() || KINDS[k];
          await this.writeSetting({ name, x, n, root, rel, k, o, lab, tm });
          dlg.close();
        } catch (e) { err.hidden = false; err.textContent = '⚠️ ' + e.message; }
      });
    }
    async writeSetting({ name, x, n, root, rel, k, o, lab, tm }) {
      const sch = x.schema; if (sch.error) throw new Error(sch.error);
      const id = C().fieldId(lab, sch.fields.map(f => f.id));
      // בורר: מחלקה קיימת שמזהה את החלק; אחרת מחלקה חדשה "<רכיב>__<הגדרה>"
      let cls = null, sel = n === root ? null : C().selectorFor(root, n);
      if (n !== root && !sel) { cls = name + '__' + id.replace(/_/g, '-'); sel = '.' + cls; }
      const field = { id, label: lab, control: 'text', target: {} };
      if (k === 'text') { field.target = { text: sel || '[' + C().MARK + ']' }; if (!sel) throw new Error('הטקסט של השורש — בוחרים את החלק שהטקסט בתוכו'); }
      else if (k === 'attr') { C().checkAttrName(o.attr); field.target = sel ? { attr: o.attr, on: sel } : { attr: o.attr }; field.control = o.toggle ? 'toggle' : /^(href)$/.test(o.attr) ? 'link' : 'text'; }
      else if (k === 'class') { if (!o.options || !o.options.length) throw new Error('מקלידים לפחות אפשרות אחת'); field.control = 'select'; field.options = o.options; field.target = sel ? { class: '{value}', on: sel } : { class: '{value}' }; }
      else if (k === 'src') { field.control = 'media'; field.target = { src: sel || '[' + C().MARK + ']' }; if (!sel) throw new Error('בוחרים את התמונה / המדיה עצמה'); }
      else if (k === 'children') {
        const kid = n.kids.find(c2 => !c2.implied), kc = K().classesOf(kid).find(c2 => /^-?[_a-zA-Z][\w-]*$/.test(c2));
        field.control = 'repeater'; field.target = { children: (sel || '[' + C().MARK + '="' + name + '"]') + ' > ' + (kc ? '.' + kc : kid.tag) };
      } else if (k === 'var') { field.control = /color/.test(o.prop) ? 'color' : /family/.test(o.prop) ? 'font' : /opacity/.test(o.prop) ? 'number' : 'size'; field.target = { var: '--' + name + '-' + id.replace(/_/g, '-') }; }
      C().checkField(Object.assign({ options: [] }, field));
      const jsonNext = C().addFieldText(x.json.text(), field);
      // המופעים המקושרים (מול התבנית הנוכחית) — מקבלים את אותה מחלקה חדשה באותו מקום, כדי שיישארו מקושרים
      const pages = tm ? await this.pages() : await this.pages(), ops = [];
      const tplText = x.tpl.text();
      if (cls) {
        ops.push(() => { const m = K().parseHtml(x.tpl.text()), r = C().rootOf(m), t = this.at(r, rel); if (!t) throw new Error('החלק אינו בתבנית — המופע שונה ממנה; מוסיפים את ההגדרה מתוך "✏️ ערוך את הרכיב"'); return [{ doc: x.tpl, patches: K().html.addClass(m, t, cls).patches }]; });
        for (const p of pages) {
          const m0 = K().parseHtml(p.doc.text()), list = C().instances(m0, name);
          list.forEach((inst, i) => { if (C().state(m0, inst.node, { schema: sch, template: tplText }).state !== 'linked') return; ops.push(() => { const m = K().parseHtml(p.doc.text()), r = C().instances(m, name)[i].node, t = this.at(r, rel); return t && !K().classesOf(t).includes(cls) ? [{ doc: p.doc, patches: K().html.addClass(m, t, cls).patches }] : []; }); });
        }
      }
      if (k === 'var') {
        const cssPath = 'components/' + name + '/style.css', d = await this.cssDoc(name);
        const el = this.c.elOf(n), cur = el ? this.c.frame.contentWindow.getComputedStyle(el).getPropertyValue(o.prop).trim() : '';
        const rootSel = n === root ? (K().classesOf(root)[0] ? '.' + K().classesOf(root)[0] : '[' + C().MARK + '="' + name + '"]') : sel;
        ops.push(() => {
          const text = d.text(), cm = K().parseCss(text), rule = K().cssFind(cm, rootSel), def = (rule && (rule.decls.find(z => z.prop.trim() === o.prop) || {}).value || cur || 'initial').trim();
          const val = 'var(' + field.target.var + ', ' + def.replace(/^var\([^,]+,\s*(.*)\)$/, '$1') + ')';
          C().checkVarValue(def); if (window.KolStyle && window.KolStyle.checkValue) window.KolStyle.checkValue(o.prop, val);
          if (rule) { const dd = rule.decls.find(z => z.prop.trim() === o.prop); return [{ doc: d, patches: (dd ? K().css.setDecl(cm, rule, o.prop, val) : K().css.addDecl(cm, rule, o.prop, val)).patches }]; }
          return [{ doc: d, patches: [{ from: text.length, to: text.length, text: (text && !text.endsWith('\n') ? '\n' : '') + rootSel + ' {\n  ' + o.prop + ': ' + val + ';\n}\n' }] }];
        });
        void cssPath;
      }
      ops.push(() => { const was = x.json.text(); return [{ doc: x.json, patches: [{ from: 0, to: was.length, text: jsonNext }] }]; });
      const docs = this.writeOps(ops, 'הוסף הגדרה · ' + lab + ' · ' + (sch.label || name));
      if (!docs) throw new Error('הכתיבה נדחתה');
      this.recount(); this.redraw();
      this.note('נוספה ההגדרה "' + lab + '" לרכיב ' + (sch.label || name) + ' — component.json' + (cls ? ', המחלקה .' + cls : '') + (k === 'var' ? ', style.css' : ''), false, { label: 'בטל', run: this.undo });
    }
    at(root, rel) { let t = root; for (const i of rel) { t = t && t.kids[i]; } return t || null; }
    async cssDoc(name) {
      const x = this.comps.get(name), path = 'components/' + name + '/style.css';
      let f = Project.docs.byPath(path);
      if (!f) { const d = await api('pf_create', { path, content: '' }); await Project.docs.reload(); f = Project.docs.byPath(path) || d.file; x.css = path; }
      return Project.docs.open(f.id);
    }

    // ── "שמור כרכיב" ─────────────────────────────────────
    /** אלמנט נבחר ← components/<שם>/: template.html (ה-HTML שלו), component.json, style.css (הצעה: הכללים שחלים רק עליו
     *  ועל צאצאיו — העבר / העתק / השאר, לכל כלל). האלמנט בעמוד הופך למופע המקושר הראשון. */
    create(n) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      const c = this.c, m = c.m, cls = K().classesOf(n)[0] || '', guess = C().slug(cls || K().attrOf(n, 'id')?.value || n.tag);
      const nm = h('input', { type: 'text', class: 'ct-in', dir: 'ltr', value: this.free(guess), 'data-cs': 'name', 'aria-label': 'שם (באנגלית, kebab-case)' });
      const lab = h('input', { type: 'text', class: 'ct-in', value: '', 'data-cs': 'label', 'aria-label': 'שם לתצוגה', placeholder: 'למשל: שורת חיפוש' });
      const icon = h('input', { type: 'text', class: 'ct-in', value: '🧩', maxlength: '4', 'data-cs': 'icon', 'aria-label': 'סמל' });
      const cat = h('input', { type: 'text', class: 'ct-in', value: 'כללי', 'data-cs': 'category', 'aria-label': 'קטגוריה', list: 'cm-cats' });
      const cats = h('datalist', { id: 'cm-cats' }, [...new Set([...this.comps.values()].map(x => x.schema && x.schema.category).filter(Boolean))].map(v => h('option', { value: v })));
      lab.addEventListener('input', () => { if (!nm.dataset.touched) nm.value = this.free(C().slug(lab.value) === 'component' ? guess : C().slug(lab.value)); });
      nm.addEventListener('input', () => { nm.dataset.touched = '1'; });
      const rules = this.ownRules(n), err = h('p', { class: 'ed-warn', hidden: true, 'data-cs-err': true });
      const rlist = h('div', { class: 'cm-rules', 'data-cs-rules': true }, rules.length ? rules.map((r, i) => h('div', { class: 'cm-rule', 'data-cs-rule': i }, h('code', { dir: 'ltr' }, r.sel + (r.media ? ' · ' + r.media : '')), h('span', { class: 'hint' }, ' · ' + r.path + ':' + r.line),
        h('select', { class: 'ct-in', 'data-cs-rule-act': i, 'aria-label': 'מה לעשות בכלל ' + r.sel, onchange: e => { r.act = e.target.value; } }, [['move', 'העבר'], ['copy', 'העתק'], ['keep', 'השאר בעמוד']].map(([v, t]) => h('option', { value: v }, t))))) : [h('p', { class: 'hint' }, 'אין בקובצי ה-CSS של העמוד כללים שחלים רק על האלמנט ועל צאצאיו.')]);
      rules.forEach(r => { r.act = 'move'; });
      const go = h('button', { type: 'button', class: 'btn primary', 'data-cs-go': true }, '🧩 צור את הרכיב');
      const dlg = this.sheet('🧩 שמור כרכיב · ' + c.label(n), h('p', { class: 'hint' }, 'נוצרת תיקייה components/<שם>/ עם template.html (ה-HTML של האלמנט), component.json ו-style.css. האלמנט בעמוד הופך למופע המקושר הראשון.'),
        h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'שם לתצוגה'), lab), h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'שם (תיקייה)'), nm), h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'סמל'), icon), h('label', { class: 'ct-row' }, h('span', { class: 'ct-k' }, 'קטגוריה'), cat), cats,
        h('h4', { class: 'dp-h' }, 'כללי CSS של האלמנט (style.css)'), rlist, err, h('div', { class: 'ed-ask-bar' }, go));
      setTimeout(() => lab.focus(), 0);
      go.addEventListener('click', async () => {
        go.disabled = true;
        try { await this.doCreate(n, { name: nm.value.trim(), label: lab.value.trim() || nm.value.trim(), icon: icon.value.trim(), category: cat.value.trim() }, rules); dlg.close(); }
        catch (e) { err.hidden = false; err.textContent = '⚠️ ' + e.message; go.disabled = false; }
      });
      void m;
    }
    free(base) { let s = base, i = 2; while (this.comps.has(s) || (Project.docs.byPath && Project.docs.byPath('components/' + s + '/component.json'))) s = base + '-' + i++; return s; }
    /** הכללים בקובצי ה-CSS של העמוד שכל מה שהם תופסים בעמוד נמצא בתוך האלמנט (ותופסים לפחות משהו). */
    ownRules(n) {
      const c = this.c, el = c.elOf(n), out = []; if (!el) return out;
      for (const s of this.styler.sheets().values()) {
        if (/^components\//.test(s.path)) continue;
        let cm; try { cm = K().parseCss(s.doc.text()); } catch { continue; }
        const visit = (node, media) => {
          for (const r of node.kids || []) {
            if (r.type === 'rule') {
              const sel = String(r.sel || '').trim(); if (!sel) continue;
              const plain = sel.replace(/::?(before|after|hover|focus|focus-visible|focus-within|active|visited|first-child|last-child|placeholder|marker)\b(\([^)]*\))?/g, '') || '*';
              let all; try { all = [...c.doc.querySelectorAll(plain)].filter(e => !e.closest('[data-kol-canvas]')); } catch { continue; }
              if (all.length && all.every(e => el === e || el.contains(e))) out.push({ sel, media, path: s.path, doc: s.doc, line: K().lineOf(s.doc.text(), r.from), from: r.from, to: r.to, text: s.doc.text().slice(r.from, r.to) });
            } else if (r.type === 'at' && r.name === 'media' && r.kids.length && !media) visit(r, '@media ' + r.sel);
          }
        };
        visit(cm.root, '');
      }
      return out.slice(0, 60);
    }
    async doCreate(n, meta, rules) {
      const name = C().checkName(meta.name);
      if (this.comps.has(name) || Project.docs.byPath('components/' + name + '/component.json')) throw new Error('כבר יש רכיב בשם ' + name);
      const c = this.c, m = c.m, P = K().pathOf(n);
      let tpl = C().outerOf(m, n);
      const lead = m.src.slice(K().leadOf(m, n), n.from).replace(/^[\s\S]*[\r\n]/, '');
      if (/^[ \t]+$/.test(lead)) tpl = tpl.replace(new RegExp('(\\r?\\n)' + lead, 'g'), '$1');
      { const tm = K().parseHtml(tpl), r = C().rootOf(tm); if (r && K().attrOf(r, C().MARK)) tpl = K().applyPatches(tpl, K().html.removeAttr(tm, r, C().MARK).patches); }
      const json = C().schemaText({ name, label: meta.label, icon: meta.icon, category: meta.category, fields: [] });
      const take = rules.filter(r => r.act !== 'keep');
      const css = take.map(r => (r.media ? r.media + ' {\n  ' + r.text.replace(/\n/g, '\n  ') + '\n}' : r.text)).join('\n\n') + (take.length ? '\n' : '');
      const base = 'components/' + name + '/', ids = [];
      for (const [f, content] of [['template.html', tpl + '\n'], ['component.json', json], ['style.css', css]]) { const d = await api('pf_create', { path: base + f, content }); ids.push(d.file.id); }
      await Project.docs.reload();
      await this.load();
      const n0 = this.txCount();
      // העמוד: סימון המופע, והכללים שהועברו — יוצאים מקובצי העמוד (מהסוף להתחלה בכל קובץ)
      const ops = [() => { const mm = c.m, t = K().byPath(mm, P); return [{ doc: this.doc(), patches: K().html.setAttr(mm, t, C().MARK, name).patches }]; }];
      const byDoc = new Map(); for (const r of rules.filter(r => r.act === 'move')) { if (!byDoc.has(r.doc)) byDoc.set(r.doc, []); byDoc.get(r.doc).push(r); }
      for (const [doc, list] of byDoc) ops.push(() => {
        const text = doc.text(), cm = K().parseCss(text), ps = [];
        for (const r of list.slice().sort((a, b) => b.from - a.from)) { const hit = K().cssAll(cm).find(x => x.from === r.from && x.to === r.to); if (hit) ps.push(...K().css.removeNode(cm, hit).patches); }
        return ps.length ? [{ doc, patches: ps.sort((a, b) => b.from - a.from).reduce((acc, p) => (acc.length && acc[acc.length - 1].from < p.to ? acc : acc.concat(p)), []).sort((a, b) => a.from - b.from) }] : [];
      });
      const docs = this.writeOps(ops, 'שמור כרכיב · ' + name);
      if (!docs) { await Promise.all(ids.map(id => api('pf_delete', { id }).catch(() => null))); await Project.docs.reload(); await this.load(); throw new Error('הכתיבה לעמוד נדחתה — הקבצים הוחזרו לסל'); }
      if (css || true) await this.linkSheet(base + 'style.css');
      const k = this.txCount() - n0; if (k > 1) this.joinTx(k, 'שמור כרכיב · ' + name);
      this.attachFx({ undo: () => Promise.all(ids.map(id => api('pf_delete', { id }).catch(() => null))).then(() => Project.docs.reload()).then(() => this.load()), redo: () => api('pf_restore', { ids }).then(() => Project.docs.reload()).then(() => this.load()).catch(e => this.note('השחזור של קובצי הרכיב נכשל: ' + e.message, true)) });
      this.ver++; this.recount();
      const cur = K().byPath(c.m, P); if (cur) { c.select(cur); c.reveal(cur); }
      this.note('נוצר הרכיב ' + meta.label + ' (components/' + name + '/) — והאלמנט הוא המופע הראשון שלו', false, { label: 'בטל', run: this.undo });
    }

    // ── מחיקת רכיב ───────────────────────────────────────
    /** רק בלי מופעים מקושרים (אחרת: "נתק את N המופעים קודם?" — ניתוק בכל העמודים ומחיקה, עסקה אחת); הקבצים — לסל. */
    async remove(name) {
      const x = this.comps.get(name); if (!x) return;
      const pages = await this.pages(), hits = [];
      for (const p of pages) { const m = K().parseHtml(p.doc.text()); C().instances(m, name).forEach((inst, i) => hits.push({ p, i })); }
      const lab = x.schema && x.schema.label || name;
      const files = (await Project.docs.files()).filter(f => f.path.startsWith('components/' + name + '/') && f.kind !== 'dir'), ids = files.map(f => f.id);
      const go = h('button', { type: 'button', class: 'btn danger', 'data-comp-del-go': true }, hits.length ? 'נתק את ' + hits.length + ' המופעים ומחק' : 'מחק את ' + lab);
      const dlg = this.sheet('🗑 מחיקת הרכיב ' + lab, hits.length ? h('p', { class: 'ed-warn', 'data-comp-del-warn': hits.length }, 'לרכיב ' + hits.length + ' מופעים (' + [...new Set(hits.map(x2 => x2.p.path))].join(', ') + '). נתק את ' + hits.length + ' המופעים קודם? הם יישארו בעמודים כ-HTML רגיל.') : h('p', { class: 'hint' }, 'אין לרכיב מופעים.'),
        h('p', { class: 'hint' }, 'הקבצים (' + files.map(f => f.path.split('/').pop()).join(', ') + ') עוברים לסל של הפרויקט, ואפשר לשחזר.'), h('div', { class: 'ed-ask-bar' }, go));
      go.addEventListener('click', async () => {
        dlg.close();
        const ops = hits.slice().reverse().map(x2 => () => { const m = K().parseHtml(x2.p.doc.text()), inst = C().instances(m, name)[x2.i]; return inst ? [{ doc: x2.p.doc, patches: K().html.removeAttr(m, inst.node, C().MARK).patches }] : []; });
        if (hits.length && !this.writeOps(ops, 'נתק ' + hits.length + ' מופעים · ' + lab)) return;
        await api('pf_delete', { path: 'components/' + name }).catch(e => this.note(e.message, true));
        const fx = { undo: () => api('pf_restore', { ids }).then(() => Project.docs.reload()).then(() => this.load()), redo: () => api('pf_delete', { path: 'components/' + name }).then(() => Project.docs.reload()).then(() => this.load()) };
        if (hits.length) this.attachFx(fx); else this.pushFx && this.pushFx('מחיקת הרכיב ' + lab, fx);
        await Project.docs.reload(); await this.load();
        this.note('הרכיב ' + lab + ' עבר לסל' + (hits.length ? ' — ' + hits.length + ' מופעים נותקו' : ''), false, { label: 'בטל', run: this.undo });
      });
    }

    // ── פאנל העיצוב: יעד ב-style.css של רכיב ────────────────
    /** כשהיעד הוא כלל ב-components/<שם>/style.css — "ישנה את כל N המופעים — או כתוב לעמוד הזה בלבד". */
    wrapDesign() {
      const D = window.KolDesign && window.KolDesign.Design; if (!D || D.prototype._cmWrapped) return;
      const orig = D.prototype.drawTarget, self = this;
      D.prototype._cmWrapped = true;
      D.prototype.drawTarget = function (n) {
        const box = orig.call(this, n), me = window.KolComponentsLive || self, t = this.t;
        try {
          const cm = t && t.path && /^components\/([^/]+)\/style\.css$/.exec(t.path);
          if (cm && me && !me.tplName()) {
            const cnt = me.counts.get(cm[1]), x = me.comps.get(cm[1]), lab = x && x.schema && x.schema.label || cm[1], tot = cnt ? cnt.total : 1;
            const alt = this.list.find(y => y.path && !/^components\//.test(y.path) && y.kind !== 'inline') || this.list.find(y => y.kind === 'inline');
            box.append(h('p', { class: 'sg-over cm-shared', 'data-comp-shared': tot }, '🧩 כלל ב-style.css של הרכיב ' + lab + ' — ישנה את כל ' + tot + ' המופעים' + (cnt && cnt.pages.size > 1 ? ' (ב-' + cnt.pages.size + ' עמודים)' : '') + '. ',
              alt ? h('button', { type: 'button', class: 'btn sm', 'data-comp-local': true, onclick: () => this.setTarget(alt) }, 'כתוב לעמוד הזה בלבד') : null));
          }
        } catch (e) { console.error(e); }
        return box;
      };
    }
    redraw() { try { this.drawAll(); } catch (e) { console.error(e); } }
  }

  window.KolComponents = { Components };
})();
