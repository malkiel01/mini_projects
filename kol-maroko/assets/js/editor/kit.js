/**
 * קול מרוקו — 🎨 עורך (פרוסה V16): ספריית הרכיבים המוכנים — 02 §6, §7.3; V16 §2.1, §2.5.
 *
 * המקור: assets/kit/components/<שם>/ בכלי — אותו מבנה כמו רכיב של בעל העמוד (component.json, template.html, style.css,
 * script.js) + kit.json. **אין כאן קוד שמייצר רכיב** — כאן רק:
 *   · הספרייה: בלשונית "🧩 רכיבים" (חיפוש, קטגוריות, תמונה מקדימה, תג "יועתק לפרויקט" / "בפרויקט" / "בפרויקט · שונה"),
 *     ו"⤢ לוח רחב" — רשת שמתפרסת על כל רוחב הקנבס (במחשב), גיליון (בטלפון). גרירה לכל נקודה בקנבס, או לחיצה — אחרי הנבחר.
 *   · שימוש ראשון: kit_copy (השרת מעתיק ל-components/<שם>/ ורושם ב-kol.json system) ← המסלול של V12 (components.js add).
 *     הכול פעולה אחת: Ctrl+Z מחזיר גם את העמוד וגם את ההעתקה (kit_undo). שימוש שני — אין העתקה.
 *   · שם תפוס: "השתמש בשלך" / "הוסף את המוכן בשם <שם>-2".
 *   · עדכון: לא שונה ← kit_update take ← עדכון המופעים במסלול של V12 (applyAll, עם בסיס = התבנית הישנה);
 *     שונה ← "יש גרסה חדשה — השוואה": קח את החדשה · השאר את שלי · שתיהן זו לצד זו.
 *   · מצבים בקנבס (בלי סקריפטים): "states" ב-component.json — העורך מחליף מצב **ב-DOM של הקנבס בלבד**; הקובץ לא משתנה.
 *     { id, label, each: בורר, attr | class, root?: מחלקות, all?, scroll? } — אחד מתוך הפריטים;
 *     { id, label, options: [{ label, root?, on?: בורר, attr? | class? }] } — אחת מתוך אפשרויות.
 *   · "➕ פריט" על הרכיב (בקנבס ובפאנל) — שכפול הפריט הנבחר / האחרון (שדה repeater בסכמה).
 */
(function () {
  'use strict';
  const K = () => window.KolSrc, C = () => window.KolComp;
  const CATS = ['תוכן', 'קופסאות', 'מספרים', 'מדיה', 'ניווט', 'חלונות', 'תנועה', 'טפסים', 'רשתות'];
  const TAG = { kit: ['📦', 'יועתק לפרויקט'], in: ['✓', 'בפרויקט'], mod: ['✎', 'בפרויקט · שונה'], taken: ['⚠', 'השם תפוס'], upd: ['⬆', 'יש גרסה חדשה'] };
  const SAFE_SEL = /^[\w\s.#>\-\[\]="':]+$/;

  class Kit {
    /** opt: { comp, canvas, build, mid, host, act, writeOps, joinTx, attachFx, txCount, note, sheet, undo, canWrite, phone, select(n) } */
    constructor(opt) {
      Object.assign(this, opt); this.c = this.canvas;
      this.items = []; this.q = ''; this.cat = ''; this.modes = new Map(); this.stCache = new Map(); this.scrolled = new Map();
      this.box = h('div', { class: 'kit', 'data-kit': true }); if (this.host) this.host.append(this.box);   // אחרי הרכיבים של בעל העמוד (V12)
      this.board = h('div', { class: 'kit-board', hidden: true, 'data-kit-board': true, role: 'dialog', 'aria-label': 'ספריית הרכיבים המוכנים' });
      if (this.mid) this.mid.append(this.board);
      this.chip = h('div', { class: 'kit-chip', hidden: true, 'data-kit-chip': true, role: 'toolbar', 'aria-label': 'הרכיב הנבחר' }); this.c.layer.append(this.chip);
      const orig = this.comp.content && this.comp.content.compSection;
      if (orig) this.comp.content.compSection = n => [...orig(n), ...this.section(n)];
      this.onKey = e => { if (e.key === 'Escape' && !this.board.hidden) { e.preventDefault(); this.openBoard(false); } };
      document.addEventListener('keydown', this.onKey);
      window.KolKitLive = this;
      this.load();
    }
    unmount() { document.removeEventListener('keydown', this.onKey); this.board.remove(); this.chip.remove(); this.box.remove(); }

    // ── הנתונים ──────────────────────────────────────────
    async load() {
      try { const r = await api('kit_list'); this.items = r.items || []; } catch (e) { this.items = []; this.err = e.message; }
      this.drawLib();
    }
    item(name) { return this.items.find(x => x.name === name) || null; }
    /** הרכיב בפרויקט שהועתק מ-name (התיקייה הראשונה שרשומה). */
    inst(it) { return it && it.installed && it.installed[0] || null; }
    tagOf(it) {
      const i = this.inst(it);
      if (i && i.update) return 'upd';
      if (i) return i.modified ? 'mod' : 'in';
      return it.taken ? 'taken' : 'kit';
    }

    // ── הספרייה (לשונית "🧩 רכיבים") והלוח הרחב ─────────────
    drawLib() {
      const head = h('div', { class: 'kit-head' }, h('h4', { class: 'dp-h' }, '📦 רכיבים מוכנים'),
        h('button', { type: 'button', class: 'btn sm', 'data-kit-wide': true, title: 'הספרייה כלוח רחב מעל הקנבס (Esc — סגירה)', onclick: () => this.openBoard(true) }, '⤢ לוח רחב'));
      const kids = [head, h('p', { class: 'hint' }, 'רכיב מוכן מועתק לפרויקט בשימוש הראשון (components/<שם>/) — ומאותו רגע הוא קבצים שלכם: כל טקסט, עיצוב וקוד נערכים.')];
      if (this.err) kids.push(h('p', { class: 'ed-warn' }, '⚠️ ' + this.err));
      kids.push(...this.filters('lib'), this.grid('lib'));
      this.box.replaceChildren(...kids);
      if (!this.board.hidden) this.drawBoard();
    }
    filters(where) {
      const q = h('input', { type: 'search', class: 'kit-q', placeholder: '🔍 חיפוש רכיב', 'aria-label': 'חיפוש ברכיבים המוכנים', value: this.q, 'data-kit-q': where });
      q.addEventListener('input', () => { this.q = q.value; this.redrawGrids(); });
      const cats = h('div', { class: 'kit-cats', role: 'group', 'aria-label': 'קטגוריות' }, ['', ...CATS].map(c => {
        const n = this.items.filter(x => !c || x.category === c).length;
        return h('button', { type: 'button', class: 'kit-cat', 'data-kit-cat': c || 'all', 'aria-pressed': String(this.cat === c), disabled: c && !n || null, onclick: () => { this.cat = c; this.drawLib(); } }, (c || 'הכול') + ' · ' + n);
      }));
      return [q, cats];
    }
    shown() {
      const q = this.q.trim().toLowerCase();
      return this.items.filter(x => (!this.cat || x.category === this.cat) && (!q || (x.label + ' ' + x.name + ' ' + x.description + ' ' + x.category).toLowerCase().includes(q)));
    }
    grid(where) {
      const list = this.shown(), g = h('div', { class: 'kit-grid', role: 'list', 'data-kit-grid': where, 'aria-label': 'רכיבים מוכנים' });
      if (!list.length) g.append(h('p', { class: 'hint', 'data-kit-none': true }, this.items.length ? 'אין רכיב שמתאים לחיפוש.' : 'טוען…'));
      for (const it of list) g.append(this.card(it, where));
      return g;
    }
    redrawGrids() { for (const g of document.querySelectorAll('[data-kit-grid]')) g.replaceWith(this.grid(g.dataset.kitGrid)); }
    card(it, where) {
      const t = this.tagOf(it), [ic, lab] = TAG[t], i = this.inst(it);
      const b = h('button', { type: 'button', class: 'kit-card', 'data-kit-item': it.name, 'data-kit-tag': t, role: 'listitem',
        title: it.label + ' — ' + it.description + (it.external.length ? ' · טוען מבחוץ (בתצוגה החיה בלבד): ' + it.external.join(', ') : '') + ' — לחיצה: אחרי הנבחר · גרירה: לכל מקום' + (this.phone() ? ' (לחיצה ארוכה)' : '') },
        it.preview ? h('img', { class: 'kit-prev', src: it.preview, alt: '', loading: 'lazy', draggable: 'false' }) : h('span', { class: 'kit-prev kit-noprev', 'aria-hidden': 'true' }, it.icon),
        h('span', { class: 'kit-name' }, h('span', { 'aria-hidden': 'true' }, it.icon), ' ', it.label),
        h('span', { class: 'kit-tag', 'data-kit-state': t }, ic + ' ' + lab + (i ? ' · ' + i.folder : '')));
      this.dragOn(b, { id: 'kit-' + it.name, icon: it.icon, label: it.label, run: tt => this.use(it.name, tt) }, where);
      const wrap = h('div', { class: 'kit-cell' }, b);
      if (i && i.update) wrap.append(h('button', { type: 'button', class: 'btn sm kit-upd', 'data-kit-update': it.name, onclick: () => this.update(it.name) }, '⬆ ' + (i.modified ? 'יש גרסה חדשה — השוואה' : 'עדכן ל-' + it.version)));
      return wrap;
    }
    /** גרירה (עכבר: אחרי 4px; מגע: לחיצה ארוכה) ← Drag של V4 עם קו היעד; לחיצה ← אחרי הנבחר. */
    dragOn(b, it, where) {
      const bd = this.build; let p0 = null, lp = 0;
      const unfold = () => { removeEventListener('pointerup', unfold, true); removeEventListener('pointercancel', unfold, true); setTimeout(() => this.board.classList.remove('kit-dragging'), 0); };
      const go = e => {
        if (!this.canWrite()) return; try { b.releasePointerCapture(p0.id); } catch { /* */ } p0 = null;
        if (where === 'board') { this.board.classList.add('kit-dragging'); addEventListener('pointerup', unfold, true); addEventListener('pointercancel', unfold, true); }   // הלוח חוזר אחרי השחרור — בכל מקום
        bd.drag.start({ lib: it }, e.clientX, e.clientY);
      };
      b.addEventListener('pointerdown', e => {
        if (e.button > 0) return; p0 = { x: e.clientX, y: e.clientY, id: e.pointerId, touch: e.pointerType === 'touch' }; try { b.setPointerCapture(e.pointerId); } catch { /* */ }
        if (p0.touch) { clearTimeout(lp); const e0 = e; lp = setTimeout(() => { if (p0) { b.classList.add('kit-hold'); go(e0); } }, 450); }
      });
      b.addEventListener('pointermove', e => {
        if (!p0 || bd.drag.active) return;
        const d = Math.hypot(e.clientX - p0.x, e.clientY - p0.y);
        if (p0.touch) { if (d > 10) { clearTimeout(lp); p0 = null; } return; }      // במגע: תזוזה לפני הלחיצה הארוכה = גלילה
        if (d > 4) go(e);
      });
      const end = () => { clearTimeout(lp); p0 = null; b.classList.remove('kit-hold'); setTimeout(() => this.board.classList.remove('kit-dragging'), 0); };
      b.addEventListener('pointerup', end); b.addEventListener('pointercancel', end);
      b.addEventListener('contextmenu', e => { if (this.phone()) e.preventDefault(); });
      b.addEventListener('click', () => { if (bd.swallow) return; if (where === 'board' && this.phone()) this.openBoard(false); it.run(); });
    }
    openBoard(on) {
      this.board.hidden = !on;
      if (on) { this.drawBoard(); const q = this.board.querySelector('.kit-q'); if (q && !this.phone()) q.focus(); }
    }
    drawBoard() {
      const close = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירת הלוח', 'data-kit-close': true, onclick: () => this.openBoard(false) }, '✕');
      this.board.replaceChildren(h('div', { class: 'kit-bhead' }, h('strong', {}, '📦 רכיבים מוכנים'),
        h('span', { class: 'hint' }, this.phone() ? 'הקשה — "➕ הוסף אחרי הנבחר" · לחיצה ארוכה — גרירה לקנבס' : 'גוררים לכל נקודה בקנבס (הלוח מתקפל בזמן הגרירה), או לוחצים — אחרי האלמנט הנבחר'), close),
        h('div', { class: 'kit-bfilters' }, ...this.filters('board')), this.grid('board'));
    }

    // ── שימוש: העתקה בפעם הראשונה ← המסלול של V12 ──────────
    async use(name, t) {
      if (!this.canWrite()) return this.note('הקובץ פתוח לקריאה בלבד', true);
      const it = this.item(name); if (!it) return;
      const i = this.inst(it);
      if (i) return this.addAs(i.folder, t, null);                              // שימוש שני: אין העתקה
      if (it.taken) return this.askTaken(it, t);
      return this.copyAdd(it, it.name, t);
    }
    askTaken(it, t) {
      const free = this.free(it.name);
      const mine = h('button', { type: 'button', class: 'btn', 'data-kit-mine': true }, 'השתמש בשלך (components/' + it.name + '/)');
      const kit = h('button', { type: 'button', class: 'btn primary', 'data-kit-as': free }, 'הוסף את המוכן בשם ' + free);
      const dlg = this.sheet('השם ' + it.name + ' תפוס', h('p', { class: 'hint' }, 'בפרויקט כבר יש components/' + it.name + '/ משלכם. הרכיב המוכן "' + it.label + '" לא ידרוס אותו.'), h('div', { class: 'ed-ask-bar' }, mine, kit));
      mine.addEventListener('click', () => { dlg.close(); this.addAs(it.name, t, null); });
      kit.addEventListener('click', () => { dlg.close(); this.copyAdd(it, free, t); });
    }
    free(base) {
      const taken = s => this.comp.comps.has(s) || this.items.some(x => x.installed.some(y => y.folder === s)) || (Project.docs.byPath && (Project.docs.byPath('components/' + s + '/component.json') || Project.docs.byPath('components/' + s + '/template.html')));
      let s = base, i = 2; while (taken(s)) s = base + '-' + i++; return s;
    }
    async copyAdd(it, as, t) {
      let r; try { r = await api('kit_copy', { name: it.name, as }); } catch (e) {
        if (e.status === 409 && e.data && e.data.free) { await this.load(); return this.askTaken(this.item(it.name) || it, t); }
        return this.note(e.message, true);
      }
      await Project.docs.reload(); await this.comp.load(); this.loadSoon();
      const fx = r.copied ? { undo: () => api('kit_undo', { folder: r.folder, ids: r.ids }).then(() => Project.docs.reload()).then(() => this.comp.load()).then(() => this.load()).catch(e => this.note('ביטול ההעתקה נכשל: ' + e.message, true)),
        redo: () => api('kit_copy', { name: it.name, as: r.folder }).then(x => { r = Object.assign(r, x); }).then(() => Project.docs.reload()).then(() => this.comp.load()).then(() => this.load()).catch(e => this.note('ההעתקה החוזרת נכשלה: ' + e.message, true)) } : null;
      const ok = await this.addAs(r.folder, t, fx, it);
      if (!ok && r.copied) { await api('kit_undo', { folder: r.folder, ids: r.ids }).catch(() => null); await Project.docs.reload(); await this.comp.load(); this.load(); }
    }
    /** ההוספה עצמה — components.js (V12): ה-HTML עם data-kol-component, <link> / <script> פעם אחת לעמוד. fx — ביטול ההעתקה, באותה פעולה. */
    async addAs(folder, t, fx, it) {
      const n0 = this.txCount();
      await this.comp.add(folder, t);
      if (this.txCount() === n0) return false;
      if (fx) { this.attachFx(fx); this.relabel('רכיב מוכן · ' + ((it && it.label) || folder) + ' · הועתק לפרויקט (components/' + folder + '/)'); }
      const ext = it && it.external && it.external.length ? ' · בתצוגה החיה בלבד הוא טוען מבחוץ: ' + it.external.join(', ') + ' (בעורך — תמונה עד לחיצה)' : '';
      if (fx || ext) this.note((fx ? 'הרכיב "' + ((it && it.label) || folder) + '" הועתק לפרויקט (components/' + folder + '/) ונוסף לעמוד — הקבצים שלכם מעכשיו' : 'נוסף ' + folder) + ext, false, { label: 'בטל', run: this.undo });
      this.loadSoon();
      return true;
    }
    relabel(label) { const S = typeof Editor !== 'undefined' && Editor._state; const tx = S && S.tx[S.tx.length - 1]; if (tx) tx.label = label; }
    loadSoon() { clearTimeout(this.lT); this.lT = setTimeout(() => this.load(), 300); }

    // ── עדכון מהכלי ──────────────────────────────────────
    async update(name) {
      const it = this.item(name), i = this.inst(it); if (!i) return;
      if (!i.modified) return this.take(it, i, false);
      let kit; try { kit = await api('kit_get', undefined, '&name=' + encodeURIComponent(name) + '&folder=' + encodeURIComponent(i.folder)); } catch (e) { return this.note(e.message, true); }
      const mine = {}; for (const f of Object.keys(kit.files).concat(['component.json', 'template.html', 'style.css', 'script.js'])) { const d = await this.docText('components/' + i.folder + '/' + f); if (d != null) mine[f] = d; }
      const files = [...new Set([...Object.keys(mine), ...Object.keys(kit.files)])].filter(f => mine[f] !== kit.files[f]);
      const side = h('div', { class: 'kit-cmp', hidden: true, 'data-kit-cmp': true }, files.map(f => h('section', {}, h('h4', { class: 'dp-h', dir: 'ltr' }, f),
        h('div', { class: 'kit-cmp2' }, h('div', {}, h('b', {}, 'שלי'), h('pre', { dir: 'ltr' }, mine[f] == null ? '(אין)' : mine[f])), h('div', {}, h('b', {}, 'החדשה · ' + kit.version), h('pre', { dir: 'ltr' }, kit.files[f] == null ? '(אין)' : kit.files[f]))),
        this.comp.diffView(mine[f] || '', kit.files[f] || ''))));
      const take = h('button', { type: 'button', class: 'btn', 'data-kit-take': true }, '⬇ קח את החדשה');
      const keep = h('button', { type: 'button', class: 'btn', 'data-kit-keep': true }, '✋ השאר את שלי');
      const both = h('button', { type: 'button', class: 'btn', 'data-kit-both': true, onclick: () => { side.hidden = !side.hidden; } }, '⇆ שתיהן זו לצד זו');
      const dlg = this.sheet('יש גרסה חדשה — ' + it.label + ' ' + i.version + ' ← ' + it.version, h('p', { class: 'hint' }, 'הרכיב שונה בפרויקט (components/' + i.folder + '/), ולכן הכלי אינו דורס אותו. ' + files.length + ' קבצים שונים מהגרסה החדשה.'),
        h('div', { class: 'ed-ask-bar' }, take, keep, both), side);
      take.addEventListener('click', () => { dlg.close(); this.take(it, i, true); });
      keep.addEventListener('click', async () => { dlg.close(); try { await api('kit_update', { folder: i.folder, action: 'keep' }); await Project.docs.reload(); this.note('נשאר שלכם — ' + it.label + '. הגרסה ' + it.version + ' סומנה כנראתה'); } catch (e) { this.note(e.message, true); } this.load(); });
    }
    async docText(path) { const f = Project.docs.byPath(path); if (!f) return null; try { return (await Project.docs.open(f.id)).text(); } catch { return null; } }
    /** הקבצים החדשים נכתבים (בשרת — שינוי קובץ רגיל, בהיסטוריה) ← המופעים בעמודים מתעדכנים במסלול של V12 (מקושר — מתעדכן; שונה — שואלים). */
    async take(it, i, force) {
      const base = 'components/' + i.folder + '/', tpl = await this.docText(base + 'template.html'), json = await this.docText(base + 'component.json');
      let r; try { r = await api('kit_update', { folder: i.folder, action: 'take', force: !!force }); } catch (e) { return this.note(e.message, true); }
      await Project.docs.reload(); for (const p of ['template.html', 'component.json', 'style.css', 'script.js']) await this.sync(base + p);
      await this.sync('kol.json');
      await this.comp.load();
      const fx = { undo: () => api('kit_revert', { folder: i.folder, before: r.before, after: r.after, created: r.created, removed: r.removed, entry: r.entry }).then(() => this.resync(base)).catch(e => this.note('ביטול העדכון נכשל: ' + e.message, true)),
        redo: () => api('kit_update', { folder: i.folder, action: 'take', force: true }).then(x => { r = x; }).then(() => this.resync(base)).catch(e => this.note(e.message, true)) };
      this.comp.pushFx('עדכון רכיב מוכן · ' + it.label + ' ' + i.version + ' ← ' + it.version, fx);
      this.note('הרכיב ' + it.label + ' עודכן ל-' + it.version + ' — שינוי קבצים רגיל (בהיסטוריה, Ctrl+Z)', false, { label: 'בטל', run: this.undo });
      this.load();
      if (tpl != null) await this.comp.applyAll({ name: i.folder, tpl, json, kit: true });
    }
    async resync(base) { await Project.docs.reload(); for (const p of ['template.html', 'component.json', 'style.css', 'script.js']) await this.sync(base + p); await this.sync('kol.json'); await this.comp.load(); this.load(); }
    /** קובץ פתוח שהשרת שינה ← התוכן החדש במסמך (בלי "שמירה" נוספת). */
    async sync(path) {
      const f = Project.docs.byPath(path); if (!f) return;
      const d = Project._state && Project._state.docs && Project._state.docs.get(f.id); if (!d) return;
      for (let i = 0; i < 80 && d.rev !== f.rev && !d.dirty; i++) await new Promise(r => setTimeout(r, 50));
    }

    // ── מצבים בקנבס ──────────────────────────────────────
    raw(name) {
      const x = this.comp.comps.get(name), t = x && x.json ? x.json.text() : '';
      const hit = this.stCache.get(name); if (hit && hit.t === t) return hit.v;
      let v = { states: [] }; try { const j = JSON.parse(t); v = { states: Array.isArray(j.states) ? j.states.filter(s => s && typeof s === 'object' && typeof s.id === 'string').slice(0, 8) : [] }; } catch { /* */ }
      this.stCache.set(name, { t, v }); return v;
    }
    keyOf(root, s) { return (K().attrOf(root, C().MARK) || {}).value + '@' + K().pathOf(root).join('.') + '#' + s.id; }
    /** האפשרויות של מצב: [{ value, label }] — הראשונה תמיד "כמו בקובץ". */
    opts(root, s) {
      const out = [{ value: '', label: 'כמו בקובץ' }];
      if (Array.isArray(s.options)) s.options.slice(0, 12).forEach((o, i) => out.push({ value: String(i), label: String(o && o.label || i + 1) }));
      else if (s.each || s.on != null) {
        if (s.each) { const n = this.targets(root, s.each).length; if (s.all) out.push({ value: 'none', label: 'הכול סגור' }, { value: 'all', label: 'הכול' }); for (let i = 0; i < n; i++) out.push({ value: String(i), label: (s.item || s.label) + ' ' + (i + 1) + ' מתוך ' + n }); }
        else out.push({ value: 'on', label: s.label });
      }
      return out;
    }
    targets(root, sel) {
      if (sel === '' || sel == null) return [root];
      if (typeof sel !== 'string' || !SAFE_SEL.test(sel)) return [];
      try { const all = C().findAllIn(root, sel); return all.filter(n => C().instanceOf(n) === root); } catch { return []; }
    }
    set(root, s, value) { const k = this.keyOf(root, s); if (value === '') this.modes.delete(k); else this.modes.set(k, value); this.scrolled.delete(k); this.c.draw(); this.drawChip(); this.comp.redraw(); }
    /** כל מה שהמצבים נוגעים בו בקנבס: מתחילים מהמודל (האמת), ומוסיפים את מה שהמצב הנבחר אומר — ונוגעים ב-DOM רק בהבדל. */
    apply() {
      const c = this.c; if (!c.m || !c.doc) return;
      if (c.m.src.indexOf(C().MARK) < 0 && !this.modes.size) return;
      const want = new Map(), add = (n, kind, name, v) => { if (!n || !this.okName(kind, name)) return; let w = want.get(n); if (!w) want.set(n, w = []); w.push([kind, name, v]); };
      const scroll = [];
      for (const inst of C().instances(c.m)) {
        const root = inst.node, def = this.raw(inst.name);
        for (const s of def.states) {
          const v = this.modes.get(this.keyOf(root, s));
          const base = (n, x) => { if (x.attr) add(n, 'attr', x.attr, null); if (x.class) add(n, 'class', x.class, null); };
          const roots = (x, on) => String(x && x.root || '').split(/\s+/).filter(Boolean).forEach(cl => add(root, 'class', cl, on ? true : null));
          if (Array.isArray(s.options)) {
            s.options.forEach((o, i) => { roots(o, false); for (const n of this.targets(root, o.on)) base(n, o); });
            const o = v != null ? s.options[+v] : null;
            if (o) { roots(o, true); const val = o.set !== false; for (const n of this.targets(root, o.on)) { if (o.attr) add(n, 'attr', o.attr, val); if (o.class) add(n, 'class', o.class, val); } }
            continue;
          }
          const list = s.each ? this.targets(root, s.each) : this.targets(root, s.on);
          roots(s, false); list.forEach(n => base(n, s));
          if (v == null) continue;
          roots(s, true);
          list.forEach((n, i) => { const on = v === 'all' || v === 'on' || (v !== 'none' && String(i) === v); const val = on ? true : false; if (s.attr) add(n, 'attr', s.attr, val); if (s.class) add(n, 'class', s.class, val); });
          if (s.scroll && /^\d+$/.test(v) && list[+v]) scroll.push([this.keyOf(root, s), list[+v]]);
        }
      }
      for (const [n, w] of want) {
        const el = c.elOf(n); if (!el) continue;
        const fin = new Map(); for (const [kind, name, v] of w) { const k = kind + ':' + name; if (v !== null || !fin.has(k)) fin.set(k, v); }
        for (const [k, v] of fin) {
          const [kind, name] = [k.slice(0, k.indexOf(':')), k.slice(k.indexOf(':') + 1)];
          if (kind === 'attr') {
            const a = K().attrOf(n, name), on = v === null ? !!a : v, val = a ? a.value : '';
            if (on && el.getAttribute(name) !== val) el.setAttribute(name, val); else if (!on && el.hasAttribute(name)) el.removeAttribute(name);
          } else {
            const on = v === null ? K().classesOf(n).includes(name) : v;
            if (el.classList.contains(name) !== on) el.classList.toggle(name, on);
          }
        }
      }
      for (const [k, n] of scroll) { if (this.scrolled.get(k) === n) continue; this.scrolled.set(k, n); const el = c.elOf(n); if (el && el.parentElement) { const p = el.parentElement; try { p.scrollTo({ left: el.offsetLeft - p.offsetLeft - (p.clientWidth - el.offsetWidth) / 2 * 0, top: 0, behavior: 'instant' }); } catch { /* */ } } }
    }
    /** שם מחלקה / תכונה ממצב ב-component.json (קובץ שבעל העמוד עורך): מחלקה — שם תקין; תכונה — לא on*, style, srcdoc, src, href. */
    okName(kind, name) {
      if (typeof name !== 'string' || !name || name.length > 60) return false;
      if (kind === 'class') return /^-?[_a-zA-Z][\w-]*$/.test(name);
      try { C().checkAttrName(name); } catch { return false; }
      return !/^(src|href|action|formaction|poster|xlink:href|data)$/i.test(name);
    }
    /** הפאנל ("תוכן"): "מצב בקנבס" ו"➕ פריט" — לכל חלק במופע. */
    section(n) {
      if (!n || !this.c.m) return [];
      const root = C().instanceOf(n); if (!root) return [];
      const name = (K().attrOf(root, C().MARK) || {}).value, def = this.raw(name), kids = [];
      for (const s of def.states) {
        const sel = h('select', { 'data-kit-state': s.id, 'aria-label': 'מצב בקנבס · ' + (s.label || s.id) });
        const cur = this.modes.get(this.keyOf(root, s)) || '';
        for (const o of this.opts(root, s)) sel.append(h('option', { value: o.value, selected: o.value === cur || null }, o.label));
        sel.addEventListener('change', () => this.set(root, s, sel.value));
        kids.push(h('label', { class: 'kit-st' }, h('span', {}, (s.label || s.id) + ': '), sel));
      }
      const rep = this.rep(root);
      if (rep) kids.push(h('button', { type: 'button', class: 'btn sm', 'data-kit-add-item': true, disabled: !this.canWrite() || null, onclick: () => this.addItem(n) }, '➕ פריט (' + rep.f.label + ')'));
      if (!kids.length) return [];
      return [h('section', { class: 'ct-group kit-group', 'data-group': 'מצב בקנבס', 'data-kit-panel': true }, h('h3', { class: 'dt-h' }, '👁 מצב בקנבס'),
        def.states.length ? h('p', { class: 'hint' }, 'בקנבס סקריפטים אינם רצים — כאן בוחרים איזה מצב לראות ולערוך. רק בקנבס: הקובץ אינו משתנה. ▶️ תצוגה חיה — ההתנהגות עצמה.') : null, ...kids)];
    }
    rep(root) {
      const name = (K().attrOf(root, C().MARK) || {}).value, cp = this.comp.comp(name), sch = cp && cp.schema;
      const f = sch && !sch.error && sch.fields.find(x => !x.bad && C().kindOf(x) === 'children'); if (!f) return null;
      return { f, items: C().itemsOf(root, f) };
    }
    /** ➕ פריט: שכפול הפריט שהנבחר בתוכו (או האחרון) — אותה פעולה של ⧉ ב-V4 / ב-repeater של V12. */
    addItem(n) {
      const root = C().instanceOf(n); if (!root) return;
      const r = this.rep(root); if (!r || !r.items.length) return this.note('אין לרכיב פריטים לשכפל', true);
      let it = r.items[r.items.length - 1]; for (let p = n; p && p !== root; p = p.parent) if (r.items.includes(p)) { it = p; break; }
      const P = K().pathOf(root), idx = r.items.indexOf(it), label = 'הוספת פריט · ' + r.f.label + ' · ' + this.c.label(root);
      const ok = this.act(label, m => { const rr = K().byPath(m, P), its = C().itemsOf(rr, r.f); return K().html.duplicate(m, its[idx]); }, root);
      if (!ok) return;
      let rr = K().byPath(this.c.m, P), its = rr && C().itemsOf(rr, r.f), nw = its && its[idx + 1];
      if (!nw) return;
      // id כפול בפריט החדש ← id פנוי (לשוניות: הקישור #id שבתוך הפריט מתעדכן איתו) — אותה פעולה
      const taken = new Set(); K().walk(this.c.m.root, x => { const a = !x.implied && K().attrOf(x, 'id'); if (a) taken.add(a.value); });
      const ren = [];
      K().walk(nw, x => { const a = !x.implied && K().attrOf(x, 'id'); if (!a) return; const base = a.value.replace(/-\d+$/, ''); let i = 2, v; do { v = base + '-' + i++; } while (taken.has(v)); taken.add(v); ren.push([a.value, v]); });
      if (ren.length) {
        const NP = K().pathOf(nw);
        const ok2 = this.act(label, m => {
          const n2 = K().byPath(m, NP), ps = [];
          K().walk(n2, x => { if (x.implied) return; const a = K().attrOf(x, 'id'), hr = K().attrOf(x, 'href'), ctl = K().attrOf(x, 'aria-controls'), fo = K().attrOf(x, 'for');
            for (const [o, v] of ren) { if (a && a.value === o) ps.push(...K().html.setAttr(m, x, 'id', v).patches); if (hr && hr.value === '#' + o) ps.push(...K().html.setAttr(m, x, 'href', '#' + v).patches); if (ctl && ctl.value === o) ps.push(...K().html.setAttr(m, x, 'aria-controls', v).patches); if (fo && fo.value === o) ps.push(...K().html.setAttr(m, x, 'for', v).patches); } });
          return { patches: ps.sort((x, y) => x.from - y.from) };
        }, nw);
        if (ok2) this.joinTx(2, label);
        rr = K().byPath(this.c.m, P); its = rr && C().itemsOf(rr, r.f); nw = its && its[idx + 1];
      }
      if (nw) { this.c.select(nw); this.c.reveal(nw); }
    }
    /** הסרגל הקטן על הקנבס, ליד המופע הנבחר: מצב ▸ (מחזורי — גם במגע) ו-➕ פריט. */
    drawChip() {
      const c = this.c, n = c.selected, root = n && c.m && C().instanceOf(n), ch = this.chip;
      if (!root || c.edit?.active || this.build.drag.active) { ch.hidden = true; ch._k = ''; return; }
      const r = c.rectOf(root); if (!r || r.zero) { ch.hidden = true; return; }
      const name = (K().attrOf(root, C().MARK) || {}).value, def = this.raw(name), rep = this.rep(root);
      const key = K().pathOf(root).join('.') + '|' + n.from + '|' + [...this.modes.entries()].join(',') + '|' + (rep ? rep.items.length : 0) + '|' + this.canWrite();
      if (ch._k !== key) {
        ch._k = key; const kids = [];
        for (const s of def.states.slice(0, 2)) {
          const os = this.opts(root, s), cur = this.modes.get(this.keyOf(root, s)) || '', i = Math.max(0, os.findIndex(o => o.value === cur)), nx = os[(i + 1) % os.length];
          kids.push(h('button', { type: 'button', class: 'kit-chip-b', 'data-kit-cycle': s.id, title: 'מצב בקנבס (רק בקנבס) — לחיצה: ' + nx.label, onclick: e => { e.stopPropagation(); this.set(root, s, nx.value); } }, '👁 ' + os[i].label + ' ▸'));
        }
        if (rep) kids.push(h('button', { type: 'button', class: 'kit-chip-b', 'data-kit-chip-add': true, disabled: !this.canWrite() || null, title: 'שכפול הפריט — ' + rep.f.label, onclick: e => { e.stopPropagation(); this.addItem(c.selected); } }, '➕ פריט'));
        ch.replaceChildren(...kids);
      }
      ch.hidden = !ch.children.length;
      if (!ch.hidden) { ch.style.cssText = 'left:0;top:0'; const w = ch.offsetWidth; ch.style.cssText = 'left:' + Math.max(0, r.left + (r.width - w) / 2) + 'px;top:' + (r.top > 30 ? r.top - 26 : r.top + r.height + 4) + 'px'; }   // מעל המופע, באמצע — בין תווית הבחירה לתג ◆
    }
    draw() { try { this.apply(); this.drawChip(); } catch (e) { console.error(e); } }
  }

  window.KolKit = { Kit };
})();
