/**
 * קול מרוקו — 🎨 עורך (פרוסה V16c): בונה הטופס של הרכיב המוכן "טופס" — V16 §2.3.
 *
 * הטופס הוא HTML רגיל (<form data-kol-form>, M5). כל שדה — <div class="kol-form__field" data-kol-field>: תווית (אלמנט), פקד,
 * עזרה (אלמנט), והודעת אימות (אלמנט data-kol-msg). כאן:
 *   · החלק הטהור (נבדק ב-tests/editor/kit-form.mjs): readField — השדה מהמודל ← { type, label, name, required, value, pattern,
 *     msg, help, width, options }; fieldHtml — חזרה ל-HTML (אותן מחלקות; כל טקסט מקודד).
 *   · הפאנל: "✏️ שדה" (סוג, שם, חובה, ברירת מחדל, אפשרויות, pattern + הודעה, רוחב, ↑ ↓ ⧉ 🗑) ו"📝 טופס" (➕ שדה, ➕ שלב,
 *     אחרי שליחה: הודעה / מייל / הפניה / webhook). כל שינוי — פעולה אחת (Ctrl+Z).
 *   · ensureKd: js/kol-data.js בפרויקט ו-<script> בעמוד — בלעדיו הטופס אינו נשלח.
 * "קובץ" — מושבת: M5 אינה מקבלת קבצים (forms_receive: "קובץ מצורף" נדחה).
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc, C = () => g.KolComp;
  const TYPES = [['text', 'טקסט'], ['email', 'אימייל'], ['tel', 'טלפון'], ['number', 'מספר'], ['date', 'תאריך'], ['select', 'בחירה (רשימה)'], ['radio', 'רדיו (אחת)'], ['checkbox', 'תיבות סימון'], ['textarea', 'טקסט ארוך'], ['file', 'קובץ — לא נתמך (M5)']];
  const WIDTHS = [['full', 'רוחב מלא'], ['half', 'חצי'], ['third', 'שליש']];
  const NAME = /^[\p{L}\p{N}_.:-]{1,64}$/u;
  const RESERVED = ['_form', '_t', '_page', '_trap'];
  const escT = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const escA = s => escT(s).replace(/"/g, '&quot;');

  // ── החלק הטהור ─────────────────────────────────────────
  function kidsDeep(n, test) { const out = []; K().walk(n, x => { if (!x.implied && x !== n && test(x)) out.push(x); }); return out; }
  const cls = (n, c) => K().classesOf(n).includes(c);
  const attr = (n, a) => { const x = K().attrOf(n, a); return x ? x.value : null; };
  const text = (m, n) => { try { return n ? K().textOf(m, n).replace(/\s+/g, ' ').trim() : ''; } catch { return ''; } };
  /** השדה מהמודל. null — זה אינו שדה של טופס. */
  function readField(m, f) {
    if (!f || !cls(f, 'kol-form__field')) return null;
    const lab = kidsDeep(f, x => cls(x, 'kol-form__label'))[0], help = kidsDeep(f, x => cls(x, 'kol-form__help'))[0], msg = kidsDeep(f, x => K().attrOf(x, 'data-kol-msg'))[0];
    const choices = kidsDeep(f, x => cls(x, 'kol-form__choices'))[0];
    const ctl = choices ? null : kidsDeep(f, x => /^(input|select|textarea)$/.test(x.tag) && attr(x, 'type') !== 'hidden')[0];
    const p = { label: text(m, lab), help: help ? text(m, help) : null, msg: msg ? text(m, msg) : null, width: cls(f, 'kol-form__field--half') ? 'half' : cls(f, 'kol-form__field--third') ? 'third' : 'full', options: [], value: '', pattern: '', required: false, name: '', id: '' };
    if (choices) {
      const ins = kidsDeep(choices, x => x.tag === 'input');
      p.type = attr(ins[0], 'type') === 'checkbox' ? 'checkbox' : 'radio';
      p.name = attr(ins[0], 'name') || ''; p.required = !!K().attrOf(ins[0], 'required');
      p.id = attr(lab, 'id') ? attr(lab, 'id').replace(/-l$/, '') : 'kf-' + p.name;
      const labs = kidsDeep(choices, x => cls(x, 'kol-form__choice'));
      p.options = labs.map(l => { const sp = kidsDeep(l, x => x.tag === 'span')[0], inp = kidsDeep(l, x => x.tag === 'input')[0]; return text(m, sp) || attr(inp, 'value') || ''; }); p.value = ins.filter(x => K().attrOf(x, 'checked')).map(x => attr(x, 'value') || '').join(', ');
    } else if (ctl) {
      p.type = ctl.tag === 'select' ? 'select' : ctl.tag === 'textarea' ? 'textarea' : (attr(ctl, 'type') || 'text').toLowerCase();
      p.name = attr(ctl, 'name') || ''; p.id = attr(ctl, 'id') || ''; p.required = !!K().attrOf(ctl, 'required'); p.pattern = attr(ctl, 'pattern') || '';
      if (ctl.tag === 'select') { const os = kidsDeep(ctl, x => x.tag === 'option'); p.options = os.filter(o => attr(o, 'value') !== '').map(o => text(m, o)); const sel = os.find(o => K().attrOf(o, 'selected')); p.value = sel ? text(m, sel) : ''; }
      else if (ctl.tag === 'textarea') p.value = text(m, ctl);
      else p.value = attr(ctl, 'value') || '';
    } else return null;
    return p;
  }
  /** השדה ← HTML (מוזח בשתי רמות פנימיות; ההזחה החיצונית — של המקום). */
  function fieldHtml(p, ind = '  ') {
    const id = p.id || ('kf-' + p.name), req = p.required ? ' required' : '', w = WIDTHS.some(x => x[0] === p.width) ? p.width : 'full';
    const L = [], i1 = ind, i2 = ind + '  ';
    L.push('<div class="kol-form__field kol-form__field--' + w + '" data-kol-field>');
    if (p.type === 'radio' || p.type === 'checkbox') {
      L.push(i1 + '<span class="kol-form__label" id="' + escA(id) + '-l">' + escT(p.label) + '</span>');
      L.push(i1 + '<div class="kol-form__choices" role="' + (p.type === 'radio' ? 'radiogroup' : 'group') + '" aria-labelledby="' + escA(id) + '-l">');
      const vals = String(p.value || '').split(',').map(s => s.trim()).filter(Boolean);
      (p.options.length ? p.options : ['אפשרות 1', 'אפשרות 2']).forEach((o, k) => L.push(i2 + '<label class="kol-form__choice"><input type="' + p.type + '" name="' + escA(p.name) + '" value="' + escA(o) + '"' + (vals.includes(o) ? ' checked' : '') + (k === 0 && p.required && p.type === 'radio' ? ' required' : '') + '> <span>' + escT(o) + '</span></label>'));
      L.push(i1 + '</div>');
    } else {
      L.push(i1 + '<label class="kol-form__label" for="' + escA(id) + '">' + escT(p.label) + '</label>');
      const pat = p.pattern && p.type !== 'select' && p.type !== 'date' && p.type !== 'number' ? ' pattern="' + escA(p.pattern) + '"' : '';
      if (p.type === 'textarea') L.push(i1 + '<textarea class="kol-form__input" id="' + escA(id) + '" name="' + escA(p.name) + '" rows="4"' + req + '>' + escT(p.value) + '</textarea>');
      else if (p.type === 'select') {
        L.push(i1 + '<select class="kol-form__input" id="' + escA(id) + '" name="' + escA(p.name) + '"' + req + '>');
        L.push(i2 + '<option value="">— בחרו —</option>');
        for (const o of (p.options.length ? p.options : ['אפשרות 1', 'אפשרות 2'])) L.push(i2 + '<option' + (o === p.value ? ' selected' : '') + '>' + escT(o) + '</option>');
        L.push(i1 + '</select>');
      } else {
        const type = ['text', 'email', 'tel', 'number', 'date'].includes(p.type) ? p.type : 'text';
        L.push(i1 + '<input class="kol-form__input" id="' + escA(id) + '" name="' + escA(p.name) + '" type="' + type + '"' + req + pat + (p.value ? ' value="' + escA(p.value) + '"' : '') + '>');
      }
    }
    if (p.help != null) L.push(i1 + '<small class="kol-form__help">' + escT(p.help) + '</small>');
    if (p.msg != null) L.push(i1 + '<small class="kol-form__msg" data-kol-msg hidden>' + escT(p.msg) + '</small>');
    L.push('</div>');
    return L.join('\n');
  }
  function checkName(name, taken) {
    name = String(name || '').trim();
    if (!NAME.test(name) || RESERVED.includes(name)) throw new Error('שם השדה — אותיות, ספרות, _ . : - (עד 64), לא ' + RESERVED.join(' / '));
    if (taken && taken.has(name)) throw new Error('כבר יש בטופס שדה בשם ' + name);
    return name;
  }
  function checkPattern(p) { if (!p) return ''; try { new RegExp('^(?:' + p + ')$', 'v'); } catch { try { new RegExp('^(?:' + p + ')$', 'u'); } catch { throw new Error('ה-pattern אינו ביטוי תקין'); } } if (p.length > 300) throw new Error('pattern ארוך מדי'); return p; }
  function checkRedirect(v) {
    v = String(v || '').trim(); if (!v) return '';
    if (/^\s*(javascript|vbscript|data):/i.test(v)) throw new Error('הפניה רק לעמוד בפרויקט (נתיב) או לכתובת https');
    if (/^[a-z][\w+.-]*:/i.test(v) && !/^https:\/\//i.test(v)) throw new Error('הפניה רק לעמוד בפרויקט (נתיב) או לכתובת https');
    if (/[\s<>"]/.test(v)) throw new Error('כתובת לא תקינה');
    return v;
  }
  const PURE = { readField, fieldHtml, checkName, checkPattern, checkRedirect, TYPES, WIDTHS };

  // ── בעורך ──────────────────────────────────────────────
  const fieldOf = (root, n) => { for (let p = n; p && p !== root.parent; p = p.parent) if (!p.implied && cls(p, 'kol-form__field')) return p; return null; };
  const namesIn = root => new Set(kidsDeep(root, x => /^(input|select|textarea)$/.test(x.tag)).map(x => attr(x, 'name')).filter(Boolean));
  const idsIn = m => { const s = new Set(); K().walk(m.root, x => { const i = !x.implied && attr(x, 'id'); if (i) s.add(i); }); return s; };
  const indentOf = (m, n) => m.src.slice(K().leadOf(m, n), n.from).replace(/^[\s\S]*[\r\n]/, '');
  const reindent = (html, ind) => html.replace(/\n/g, '\n' + ind);

  function section(kit, root, n) {
    const m = kit.c.m, out = [], ro = !kit.canWrite(), P = K().pathOf(root);
    const btn = (t, fn, key, off) => h('button', { type: 'button', class: 'btn sm', 'data-kf': key, disabled: ro || off || null, onclick: fn }, t);
    const fld = fieldOf(root, n);
    if (fld) {
      const p = readField(m, fld);
      if (p) {
        const FP = K().pathOf(fld), write = (label, next) => {
          try { next.name = checkName(next.name, (() => { const t = namesIn(K().byPath(kit.c.m, P)); t.delete(p.name); return t; })()); next.pattern = checkPattern(next.pattern); } catch (e) { kit.note(e.message, true); return kit.comp.redraw(); }
          if (next.type === 'file') { kit.note('קובץ — M5 אינה מקבלת קבצים (הפנייה נדחית: "קובץ מצורף"). בוחרים סוג אחר', true); return kit.comp.redraw(); }
          kit.act(label + ' · ' + (next.label || next.name), mm => { const f2 = K().byPath(mm, FP); return { patches: [{ from: f2.from, to: K().endOf(mm, f2), text: reindent(fieldHtml(next), indentOf(mm, f2)) }] }; }, fld);
          const cur = K().byPath(kit.c.m, FP); if (cur) { kit.c.select(cur); }
        };
        const sel = (key, val, list) => { const e = h('select', { 'data-kf-in': key, disabled: ro || null }, list.map(([v, t]) => h('option', { value: v, disabled: v === 'file' || null, selected: v === val || null }, t))); return e; };
        const typeSel = sel('type', p.type, TYPES); typeSel.addEventListener('change', () => write('סוג שדה', Object.assign({}, p, { type: typeSel.value })));
        const name = h('input', { type: 'text', dir: 'ltr', 'data-kf-in': 'name', value: p.name, disabled: ro || null }); name.addEventListener('change', () => write('שם שדה', Object.assign({}, p, { name: name.value, id: p.id === 'kf-' + p.name ? 'kf-' + name.value.trim() : p.id })));
        const req = h('input', { type: 'checkbox', 'data-kf-in': 'required', checked: p.required || null, disabled: ro || null }); req.addEventListener('change', () => write('חובה', Object.assign({}, p, { required: req.checked })));
        const def = h('input', { type: 'text', 'data-kf-in': 'value', value: p.value, disabled: ro || null }); def.addEventListener('change', () => write('ברירת מחדל', Object.assign({}, p, { value: def.value })));
        const opts = h('textarea', { rows: 3, 'data-kf-in': 'options', disabled: ro || null }); opts.value = p.options.join('\n'); opts.addEventListener('change', () => write('אפשרויות', Object.assign({}, p, { options: opts.value.split('\n').map(x => x.trim()).filter(Boolean).slice(0, 60) })));
        const pat = h('input', { type: 'text', dir: 'ltr', 'data-kf-in': 'pattern', value: p.pattern, placeholder: '[0-9]{9,10}', disabled: ro || null }); pat.addEventListener('change', () => write('תבנית אימות', Object.assign({}, p, { pattern: pat.value.trim(), msg: pat.value.trim() ? (p.msg == null ? 'הערך אינו בפורמט הנכון' : p.msg) : p.msg })));
        const help = h('input', { type: 'checkbox', 'data-kf-in': 'help', checked: p.help != null || null, disabled: ro || null }); help.addEventListener('change', () => write('עזרה', Object.assign({}, p, { help: help.checked ? (p.help || 'הסבר קצר') : null })));
        const msg = h('input', { type: 'checkbox', 'data-kf-in': 'msg', checked: p.msg != null || null, disabled: ro || null }); msg.addEventListener('change', () => write('הודעת אימות', Object.assign({}, p, { msg: msg.checked ? (p.msg || 'הערך אינו תקין') : null })));
        const wid = sel('width', p.width, WIDTHS); wid.addEventListener('change', () => write('רוחב שדה', Object.assign({}, p, { width: wid.value })));
        const sibs = () => { const f2 = K().byPath(kit.c.m, FP); return f2 ? f2.parent.kids.filter(x => !x.implied && x.tag) : []; };
        const move = d => { const f2 = K().byPath(kit.c.m, FP), s = sibs(), i = s.indexOf(f2), t = s[i + d]; if (!t) return; kit.act('סדר שדות', mm => { const a = K().byPath(mm, FP), b = K().byPath(mm, K().pathOf(t)); return K().html.move(mm, a, a.parent, a.parent.kids.indexOf(b) + (d > 0 ? 1 : 0)); }, f2); };
        out.push(h('section', { class: 'ct-group kit-group', 'data-group': 'שדה', 'data-kf-field': p.name }, h('h3', { class: 'dt-h' }, '✏️ שדה · ' + (p.label || p.name)),
          h('p', { class: 'hint' }, 'התווית, העזרה וההודעה — טקסט על הקנבס (לחיצה כפולה). כאן — מה שאינו נראה.'),
          h('label', { class: 'kit-st' }, h('span', {}, 'סוג: '), typeSel),
          p.type === 'file' ? null : h('p', { class: 'hint', 'data-kf-nofile': true }, 'קובץ — מושבת: תיבת הפניות (M5) אינה מקבלת קבצים מצורפים.'),
          h('label', { class: 'kit-st' }, h('span', {}, 'שם (name): '), name), h('label', { class: 'kit-st' }, req, h('span', {}, ' חובה')),
          /select|radio|checkbox/.test(p.type) ? h('label', { class: 'kit-st' }, h('span', {}, 'אפשרויות (שורה לכל אחת): '), opts) : null,
          h('label', { class: 'kit-st' }, h('span', {}, 'ברירת מחדל: '), def),
          /select|radio|checkbox|date|number/.test(p.type) ? null : h('label', { class: 'kit-st' }, h('span', {}, 'תבנית אימות (pattern): '), pat),
          h('label', { class: 'kit-st' }, help, h('span', {}, ' עזרה מתחת לשדה')), h('label', { class: 'kit-st' }, msg, h('span', {}, ' הודעה כשהשדה אינו תקין (אלמנט)')),
          h('label', { class: 'kit-st' }, h('span', {}, 'רוחב: '), wid),
          h('div', { class: 'cm-acts' }, btn('↑', () => move(-1), 'up'), btn('↓', () => move(1), 'down'),
            btn('⧉ שכפול', () => { const f2 = K().byPath(kit.c.m, FP); const taken = namesIn(K().byPath(kit.c.m, P)); let i = 2, nn = p.name + '-' + i; while (taken.has(nn)) nn = p.name + '-' + ++i; const ids = idsIn(kit.c.m); let id = 'kf-' + nn; for (let j = 2; ids.has(id); j++) id = 'kf-' + nn + '-' + j; const html = reindent(fieldHtml(Object.assign({}, p, { name: nn, id })), indentOf(kit.c.m, f2)); kit.act('שכפול שדה · ' + p.label, mm => { const a = K().byPath(mm, FP); return K().html.insert(mm, a.parent, a.parent.kids.indexOf(a) + 1, html); }, f2); }, 'dup'),
            btn('🗑 מחיקה', () => { const f2 = K().byPath(kit.c.m, FP); kit.act('מחיקת שדה · ' + p.label, mm => K().html.remove(mm, K().byPath(mm, FP)), f2); }, 'del', sibs().length < 2 && !kidsDeep(root, x => cls(x, 'kol-form__step')).length))));
      }
    }
    // "📝 טופס" — מכל חלק של הטופס
    const form = root, fname = attr(form, 'data-kol-form') || '';
    const steps = kidsDeep(form, x => cls(x, 'kol-form__step'));
    const addField = () => {
      const m2 = kit.c.m, r = K().byPath(m2, P), taken = namesIn(r), ids = idsIn(m2); let i = 1, nm; do { nm = 'field-' + i++; } while (taken.has(nm)); let id = 'kf-' + nm; for (let j = 2; ids.has(id); j++) id = 'kf-' + nm + '-' + j;
      const cur = fld ? K().byPath(m2, K().pathOf(fld)) : null, box = cur ? cur.parent : (kidsDeep(r, x => cls(x, 'kol-form__step')).pop() || kidsDeep(r, x => cls(x, 'kol-form__fields'))[0]);
      if (!box) return kit.note('אין בטופס מכל שדות (.kol-form__fields / שלב)', true);
      const at = cur ? box.kids.indexOf(cur) + 1 : box.kids.length, ref = box.kids.find(x => !x.implied && x.tag && cls(x, 'kol-form__field')) || box.kids.find(x => !x.implied && x.tag);
      const html = reindent(fieldHtml({ type: 'text', label: 'שדה חדש', name: nm, id, required: false, value: '', pattern: '', help: null, msg: null, width: 'full', options: [] }), ref ? indentOf(m2, ref) : '');
      const BP = K().pathOf(box);
      if (kit.act('➕ שדה · ' + nm, mm => K().html.insert(mm, K().byPath(mm, BP), at, html), box)) { const nb = K().byPath(kit.c.m, BP), nn = nb && nb.kids.filter(x => !x.implied && x.tag)[nb.kids.slice(0, at).filter(x => !x.implied && x.tag).length]; if (nn) { kit.c.select(nn); kit.c.reveal(nn); } }
    };
    const addStep = () => {
      const m2 = kit.c.m, r = K().byPath(m2, P), st = kidsDeep(r, x => cls(x, 'kol-form__step')), taken = namesIn(r), ids = idsIn(m2);
      let i = 1, nm; do { nm = 'field-' + i++; } while (taken.has(nm)); let id = 'kf-' + nm; for (let j = 2; ids.has(id); j++) id = 'kf-' + nm + '-' + j;
      const nf = fieldHtml({ type: 'text', label: 'שדה חדש', name: nm, id, required: false, value: '', pattern: '', help: null, msg: null, width: 'full', options: [] });
      const fs = (k, inner) => '<fieldset class="kol-form__step" data-kol-step>\n  <legend class="kol-form__legend">שלב ' + k + '</legend>\n  ' + inner + '\n</fieldset>';
      if (!st.length) {
        const box = kidsDeep(r, x => cls(x, 'kol-form__fields'))[0]; if (!box) return kit.note('אין בטופס .kol-form__fields', true);
        const ind = indentOf(m2, box), BP = K().pathOf(box), outer = C().outerOf(m2, box);
        const inner = outer.replace(/^<div\b[^>]*>\s*/, '').replace(/\s*<\/div>\s*$/, '').replace(new RegExp('\\n' + ind + '  ', 'g'), '\n');
        const html = reindent(fs(1, inner.replace(/\n/g, '\n  ')) + '\n' + fs(2, nf.replace(/\n/g, '\n  ')), ind);
        kit.act('➕ שלב · שלבים בטופס', mm => { const b = K().byPath(mm, BP); return { patches: [{ from: b.from, to: K().endOf(mm, b), text: html }] }; }, box);
      } else {
        const last = st[st.length - 1], ind = indentOf(m2, last), LP = K().pathOf(last);
        const html = reindent(fs(st.length + 1, nf.replace(/\n/g, '\n  ')), ind);
        kit.act('➕ שלב ' + (st.length + 1), mm => { const l = K().byPath(mm, LP); return K().html.insert(mm, l.parent, l.parent.kids.indexOf(l) + 1, html); }, last);
      }
    };
    const sentState = kit.raw((attr(root, C().MARK) || '')).states.find(s => s.id === 'sent');
    const mail = h('p', { class: 'hint', 'data-kf-mail': true }, 'מייל: טוען…');
    api('forms_settings').then(r => { const s = r.settings || {}; mail.textContent = 'מייל (M5): ' + (s.mail_on ? 'פעיל' : 'כבוי') + ' · ' + (s.to || []).length + ' נמענים — ההגדרות ב-📬 פניות (משותפות לכל הטפסים).'; }).catch(() => { mail.textContent = 'מייל (M5): ההגדרות ב-📬 פניות.'; });
    const pages = h('select', { 'data-kf-in': 'redirect-page', disabled: ro || null }, h('option', { value: '' }, '— עמוד בפרויקט —'));
    kit.comp.pages().then(ps => { for (const p of ps) pages.append(h('option', { value: kit.comp.rel(p.path) }, p.path)); }).catch(() => {});
    pages.addEventListener('change', () => { if (!pages.value) return; let v; try { v = checkRedirect(pages.value); } catch (e) { return kit.note(e.message, true); } kit.act('אחרי שליחה — הפניה · ' + v, mm => ({ patches: K().html.setAttr(mm, K().byPath(mm, P), 'data-kol-redirect', v).patches.map(x => ({ from: x.from, to: x.to, text: x.text.replace(/</g, '&lt;').replace(/>/g, '&gt;') })) }), root); });
    const hook = h('input', { type: 'url', dir: 'ltr', 'data-kf-in': 'webhook', placeholder: 'https://…', disabled: ro || null });
    const hookSt = h('p', { class: 'hint', 'data-kf-hook-state': true });
    const drawHook = r => { hook.value = r.url || ''; hookSt.textContent = r.url ? 'webhook פעיל · ממתינים: ' + r.pending + (r.failed && r.failed.length ? ' · נכשלו: ' + r.failed.length + ' (ביומן המפתח; הפניות שמורות בתיבה)' : '') + (r.last ? ' · אחרון: ' + r.last.msg : '') : 'אין webhook לטופס הזה.'; };
    if (fname) api('forms_hook_state', undefined, '&form=' + encodeURIComponent(fname)).then(drawHook).catch(() => {});
    const saveHook = async () => { try { drawHook(await api('forms_hook_set', { form: fname, url: hook.value.trim() })); kit.note(hook.value.trim() ? 'webhook נשמר — כל פנייה לטופס ' + fname + ' תישלח לשם (JSON), אחרי השמירה בתיבה' : 'ה-webhook הוסר'); } catch (e) { kit.note(e.message, true); } };
    out.push(h('section', { class: 'ct-group kit-group', 'data-group': 'טופס', 'data-kf-form': fname }, h('h3', { class: 'dt-h' }, '📝 טופס · ' + fname),
      h('p', { class: 'hint' }, 'השדות נרשמים לקבלה בבנייה, מתוך ה-HTML (M5) — שדה שנוסף כאן נשלח מיד אחרי הפרסום. ' + (steps.length ? steps.length + ' שלבים.' : 'בלי שלבים.')),
      h('div', { class: 'cm-acts' }, btn('➕ שדה', addField, 'add-field'), btn('➕ שלב', addStep, 'add-step')),
      h('h4', { class: 'dp-h' }, 'אחרי שליחה'),
      h('div', { class: 'cm-acts' }, btn('👁 הצג "נשלח" בקנבס (לעריכת ההודעה)', () => sentState && kit.set(K().byPath(kit.c.m, P), sentState, '0'), 'show-done', !sentState)),
      mail, h('label', { class: 'kit-st' }, h('span', {}, 'הפניה לעמוד: '), pages), h('p', { class: 'hint' }, 'או כתובת https — בשדה "אחרי שליחה — לעבור לעמוד / כתובת" למעלה.'),
      h('label', { class: 'kit-st' }, h('span', {}, 'webhook: '), hook, btn('שמור', saveHook, 'hook-save', !fname)), hookSt,
      h('p', { class: 'hint' }, 'webhook: כל פנייה נשלחת (POST, JSON) לכתובת https, אחרי שנשמרה בתיבה; כשל — ניסיון חוזר (דקה, 5, 30, שעתיים, 6), והפנייה לא אובדת. כתובת פנימית / IP — נדחית. מפרסם ומעלה.')));
    return out;
  }

  /** js/kol-data.js — קובץ מערכת בפרויקט, ו-<script defer> בעמוד. בלעדיו הטופס אינו נשלח. כל הכתיבות — בתוך הפעולה של ההוספה. */
  async function ensureKd(kit, opts = {}) {
    const c = kit.c, m = c.m; if (!m) return;
    if (opts.script !== false && /<script\b[^>]*\bsrc\s*=\s*["']?[^"'>]*kol-data\.js/i.test(m.src)) return;
    const KD = 'js/kol-data.js';
    if (!Project.docs.byPath(KD)) {
      try {
        const r = await fetch('assets/project-seed/kol-data.js', { credentials: 'same-origin' }); if (!r.ok) throw new Error('העותק של הכלי לא נטען');
        const t = await r.text(), d = await api('pf_create', { path: KD, content: t }), ver = (/version:\s*'([\d.]+)'/.exec(t) || [])[1] || '1.0.0';
        await Project.docs.reload();
        const kf = Project.docs.byPath('kol.json'), kd = kf && await Project.docs.open(kf.id);
        if (kd && !kd.binary) kit.writeOps([() => { const j = JSON.parse(kd.text()); j.system = j.system || {}; if (j.system[KD]) return []; j.system[KD] = { version: ver, modified: false }; return [{ doc: kd, patches: [{ from: 0, to: kd.text().length, text: JSON.stringify(j, null, 4) + '\n' }] }]; }], KD + ' · קובץ מערכת');
        kit.attachFx({ undo: () => api('pf_delete', { id: d.file.id }).then(() => Project.docs.reload()).catch(() => null), redo: () => api('pf_restore', { ids: [d.file.id] }).then(() => Project.docs.reload()).catch(() => null) });
      } catch (e) { return kit.note('js/kol-data.js לא נוסף: ' + e.message + ' — בלעדיו הטופס אינו נשלח', true); }
    }
    if (opts.script === false) return;
    const head = c.m.root && c.m.root.kids.find(k => k.tag === 'head' && !k.implied);
    if (!head) return kit.note('לעמוד אין <head> כתוב — מוסיפים <script src="js/kol-data.js"> בקוד', true);
    kit.act('<script> של js/kol-data.js', mm => K().html.insert(mm, K().byPath(mm, K().pathOf(head)), K().byPath(mm, K().pathOf(head)).kids.length, '<script src="' + g.KolText.escAttr(kit.comp.rel(KD)) + '" defer></script>'), head);
  }

  g.KolKitForm = Object.assign({ section, ensureKd }, PURE);
})(typeof window !== 'undefined' ? window : globalThis);
