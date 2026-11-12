// קול מרוקו — N2: "מבנה המילה" (השדות של כל מילה), מסך הנושאים, והתאים של שדה מותאם בטבלת המילים.
// נטען מ-words.js לפי הצורך (בלי לשונית ובלי שורה ב-studio.html). השרת: lib/lexicon.php (fields_*, topic_*) ו-lib/fields_bind.php.
// מבנה (הוספה, מחיקה, סוג, מפתח, "מתפרסם") — מפרסם; ערכים ונושאים — עורך.
'use strict';

const Fields = (() => {
  const TYPES = { text: 'טקסט קצר', longtext: 'טקסט ארוך', rich: 'טקסט מעוצב', number: 'מספר', bool: 'כן / לא', choice: 'בחירה', date: 'תאריך',
    url: 'קישור', image: 'תמונה', audio: 'הקלטה', list: 'רשימה', topics: 'נושאים' };
  const DIRS = { auto: 'אוטומטי', rtl: 'ימין לשמאל', ltr: 'שמאל לימין' };
  const canStructure = () => RANK[state.user?.role] >= RANK.publisher;

  // תעתיק לטיני פשוט — הצעה למפתח מהשם. המשתמש יכול לשנות; השרת בודק בכל מקרה
  const TR = { א: 'a', ב: 'b', ג: 'g', ד: 'd', ה: 'h', ו: 'v', ז: 'z', ח: 'ch', ט: 't', י: 'y', כ: 'k', ך: 'k', ל: 'l', מ: 'm', ם: 'm', נ: 'n', ן: 'n',
    ס: 's', ע: 'a', פ: 'p', ף: 'f', צ: 'tz', ץ: 'tz', ק: 'k', ר: 'r', ש: 'sh', ת: 't' };
  function keyFrom(label, taken) {
    let k = [...label.toLowerCase()].map(c => TR[c] ?? c).join('').replace(/[^a-z0-9]+/g, '_').replace(/^[^a-z]+|_+$/g, '').slice(0, 40);
    if (!k || taken.includes(k)) { let n = taken.length + 1; while (taken.includes('field_' + n)) n++; k = k && !taken.includes(k) ? k : 'field_' + n; }
    return k;
  }
  const KEY_RX = /^[a-z][a-z0-9_]{0,39}$/;

  // ── ערך של שדה ← טקסט קצר (לטבלה, לתצוגה) ─────────────────
  function show(f, v) {
    if (v == null || v === '') return '';
    if (f.type === 'bool') return v ? '✓' : '✗';
    if (f.type === 'list') return v.join(', ');
    if (f.type === 'choice') return (f.options.find(o => o.value === v) || { label: v }).label;
    if (f.type === 'rich') { const d = document.createElement('div'); d.innerHTML = v; return d.textContent.trim(); }
    return String(v);
  }

  /**
   * התא של שדה מותאם בטבלה. save(ערך) — שומר את המילה עם { extra: { key: ערך } }. טקסט / מספר / תאריך / קישור — עריכה בתא;
   * כן/לא — תיבה; בחירה — רשימה; טקסט ארוך / מעוצב — בחלון; תמונה / הקלטה — Media.pick; רשימה — תגיות מופרדות ב-";".
   */
  function cell(f, w, save) {
    const v = w.extra?.[f.key];
    const attrs = { 'aria-label': `${f.label}: ${w.word}`, dir: f.dir === 'auto' ? 'auto' : f.dir, title: f.help || null, 'data-field': f.key };
    const run = async val => { try { await save(val); } catch (e) { toast(e.message, true); throw e; } };
    if (f.type === 'bool') return h('input', { type: 'checkbox', ...attrs, checked: v === true, onchange: e => run(e.target.checked).catch(() => { e.target.checked = !e.target.checked; }) });
    if (f.type === 'choice') return h('select', { class: 'w-cell', ...attrs, onchange: e => run(e.target.value || null).catch(() => {}) },
      h('option', { value: '' }, '—'), f.options.map(o => h('option', { value: o.value, selected: o.value === v }, o.label)));
    if (f.type === 'image' || f.type === 'audio') {
      const src = w.extra_src?.[f.key];
      return h('div', { class: 'f-file' },
        h('button', { type: 'button', class: 'w-aud', ...attrs, onclick: async () => { const [x] = await Media.pick({ kind: f.type }); if (x) run(x.id).catch(() => {}); } },
          v ? (f.type === 'image' && src ? h('img', { src, alt: '', class: 'f-thumb' }) : h('span', { class: 'chip ok' }, f.type === 'image' ? '🖼️' : '🎙️')) : h('span', { class: 'muted' }, '+')),
        v ? h('button', { type: 'button', class: 'icon-btn', 'aria-label': `הסרת ${f.label}`, onclick: () => run(null).catch(() => {}) }, '✕') : null);
    }
    if (f.type === 'rich' || f.type === 'longtext') return h('button', { type: 'button', class: 'w-mean', ...attrs, onclick: () => longEdit(f, w, run) },
      show(f, v).slice(0, 100) || h('span', { class: 'muted' }, '+'));
    // שורה אחת: text / number / date / url / list
    const type = { number: 'text', date: 'date', url: 'url' }[f.type] || 'text';
    const text = f.type === 'list' ? (v || []).join('; ') : v == null ? '' : String(v);
    const inp = h('input', { class: 'w-cell', type, value: text, ...attrs, inputmode: f.type === 'number' ? 'decimal' : null, placeholder: f.type === 'list' ? 'א; ב' : '—' });
    let saved = text;
    inp.addEventListener('change', async () => {
      if (inp.value.trim() === saved.trim()) return;
      let val = inp.value.trim();
      if (val === '') val = null;
      else if (f.type === 'number') { val = Number(val.replace(',', '.')); if (!Number.isFinite(val)) { inp.classList.add('err'); toast(`${f.label}: מספר`, true); return; } }
      else if (f.type === 'list') val = val.split(/\s*;\s*/).filter(Boolean);
      inp.classList.add('saving');
      try { await run(val); saved = inp.value; inp.classList.remove('err'); } catch { inp.classList.add('err'); }
      inp.classList.remove('saving');
    });
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } if (e.key === 'Escape') { inp.value = saved; inp.blur(); } });
    return inp;
  }

  function longEdit(f, w, run) {
    const v = w.extra?.[f.key] || '';
    let get;
    let box;
    if (f.type === 'rich') {
      const ed = h('div', { class: 'rte', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': f.label, dir: f.dir === 'auto' ? 'auto' : f.dir });
      ed.innerHTML = v;
      ed.addEventListener('paste', e => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')); });
      const b = (ic, lbl, c) => h('button', { type: 'button', class: 'icon-btn', 'aria-label': lbl, onmousedown: e => e.preventDefault(), onclick: () => { ed.focus(); document.execCommand(c); } }, ic);
      box = h('div', { class: 'rte-box' }, h('div', { class: 'rte-bar' }, b(h('b', {}, 'B'), 'מודגש', 'bold'), b(h('i', {}, 'I'), 'נטוי', 'italic'), b('•', 'רשימה', 'insertUnorderedList')), ed);
      get = () => ed.innerHTML;
    } else {
      box = h('textarea', { rows: 8, dir: f.dir === 'auto' ? 'auto' : f.dir, 'aria-label': f.label, maxlength: 20000 }, v);
      get = () => box.value;
    }
    const save = h('button', { type: 'button', class: 'btn primary' }, '✓ שמירה');
    const dlg = sheet(`${f.label} · ${w.word}`, f.help ? h('p', { class: 'hint' }, f.help) : null, box,
      h('div', { class: 'row-btns' }, save, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'בטל')));
    save.onclick = async () => { save.disabled = true; try { await run(get() || null); dlg.close(); } catch { save.disabled = false; } };
  }

  // ── "🧩 מבנה המילה" ─────────────────────────────────────────
  async function structure(onChange) {
    let S;
    try { S = await api('fields'); } catch (e) { toast(e.message, true); return; }
    const can = S.can_structure;
    let drag = null;
    const reorder = async keys => {
      try { S = await api('field_save', { order: keys }); toast('הסדר נשמר'); onChange(); draw(); } catch (e) { toast(e.message, true); }
    };
    const draw = () => {
      const keys = S.fields.map(f => f.key);
      const li = (f, i) => h('li', { class: 'f-row', 'data-key': f.key, draggable: can ? 'true' : 'false',
        ondragstart: e => { drag = f.key; e.dataTransfer.setData('text/plain', f.key); }, ondragover: e => { if (drag) e.preventDefault(); },
        ondrop: e => { e.preventDefault(); if (!drag || drag === f.key) return; const k = keys.filter(x => x !== drag); k.splice(k.indexOf(f.key), 0, drag); drag = null; reorder(k); } },
        can ? h('span', { class: 'w-grip', 'aria-hidden': 'true', title: 'גרירה לשינוי הסדר' }, '⠿') : null,
        h('span', { class: 'use-tx' },
          h('b', { class: 'one' }, f.label, f.required ? h('span', { class: 'muted' }, ' *') : null),
          h('small', { dir: 'ltr', class: 'f-key' }, f.key),
          h('small', {}, [TYPES[f.type] || f.type, f.builtin ? 'מובנה' : null, f.published ? 'מתפרסם' : '🔒 פנימי', `${f.filled} מילים עם ערך`].filter(Boolean).join(' · '))),
        can ? h('span', { class: 'f-acts' },
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': `למעלה: ${f.label}`, disabled: !i, onclick: () => { const k = [...keys]; [k[i - 1], k[i]] = [k[i], k[i - 1]]; reorder(k); } }, '▲'),
          h('button', { type: 'button', class: 'btn sm ghost', 'data-edit': f.key, onclick: () => form(f) }, '✏️'),
          h('button', { type: 'button', class: 'btn sm ghost', 'data-rekey': f.key, onclick: () => rekey('field', f.key, f.label, () => { onChange(); structure(onChange); }) }, '🔑'),
          f.builtin ? null : h('button', { type: 'button', class: 'btn sm ghost danger', 'data-del': f.key, onclick: () => remove(f) }, '🗑️')) : null);
      sheet('🧩 מבנה המילה',
        h('p', { class: 'hint' }, 'השדות של כל מילה. כל שדה הוא עמודה בטבלה, מפתח ב-data/words.json ועמודה באקסל. "פנימי" — לא מגיע לעמוד.'),
        can ? null : h('p', { class: 'hint' }, 'שינוי המבנה — למפרסם. ערכים אפשר לערוך בטבלה.'),
        h('ul', { class: 'list f-list' }, S.fields.map(li)),
        can ? h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn primary', 'data-add-field': true, onclick: () => form(null) }, '+ שדה')) : null);
    };
    const remove = async f => {
      let d;
      try { d = await api('field_delete', { key: f.key, dry: true }); } catch (e) { toast(e.message, true); return; }
      const msg = `למחוק את השדה "${f.label}"? ${d.values} ערכים יעברו איתו לסל (שחזור — ב-↩️ שחזור).` + (d.bindings ? `\n${d.bindings} קשירות בקבצי העמוד יישארו בלי ערך.` : '');
      if (!confirm(msg)) return;
      try { S = await api('field_delete', { key: f.key }); toast('השדה הועבר לסל'); onChange(); draw(); } catch (e) { toast(e.message, true); }
    };
    // הוספה / עריכה
    const form = f => {
      const isNew = !f;
      const cur = f || { label: '', key: '', type: 'text', required: false, def: null, help: '', options: [], published: true, dir: 'auto', builtin: false, col: null };
      const label = h('input', { value: cur.label, maxlength: 80, required: true, dir: 'auto' });
      const key = h('input', { value: cur.key, maxlength: 40, dir: 'ltr', pattern: '[a-z][a-z0-9_]*', disabled: !isNew || null, 'data-f-key': true });
      let keyTouched = !isNew;
      label.addEventListener('input', () => { if (!keyTouched) key.value = keyFrom(label.value, S.fields.map(x => x.key)); });
      key.addEventListener('input', () => { keyTouched = true; key.setCustomValidity(KEY_RX.test(key.value) ? '' : 'אותיות לטיניות קטנות, ספרות ו-_ , מתחיל באות'); });
      const allowed = cur.col ? S.col_types[cur.col] : S.types;
      const type = h('select', { disabled: allowed.length < 2 || null, 'data-f-type': true }, allowed.map(t => h('option', { value: t, selected: t === cur.type }, TYPES[t] || t)));
      const opts = h('textarea', { rows: 4, dir: 'auto', placeholder: 'אפשרות בכל שורה. אפשר "ערך | תווית"' }, cur.options.map(o => o.value === o.label ? o.value : `${o.value} | ${o.label}`).join('\n'));
      const optsBox = h('label', { class: 'f', hidden: type.value !== 'choice' }, h('span', {}, 'האפשרויות'), opts);
      type.addEventListener('change', () => { optsBox.hidden = type.value !== 'choice'; });
      const req = h('input', { type: 'checkbox', checked: cur.required, disabled: cur.col === 'word' || null });
      const pub = h('input', { type: 'checkbox', checked: cur.published, 'data-f-pub': true });
      const help = h('input', { value: cur.help, maxlength: 300, dir: 'auto' });
      const def = h('input', { value: cur.def == null ? '' : Array.isArray(cur.def) ? cur.def.join('; ') : String(cur.def), dir: 'auto', placeholder: 'ריק — בלי' });
      const dir = h('select', {}, Object.entries(DIRS).map(([v, l]) => h('option', { value: v, selected: v === cur.dir }, l)));
      const out = h('div');
      const save = h('button', { type: 'button', class: 'btn primary', 'data-f-save': true }, isNew ? '+ הוספה' : '✓ שמירה');
      sheet(isNew ? '+ שדה חדש' : `✏️ ${cur.label}`,
        h('label', { class: 'f' }, h('span', {}, 'השם *'), label),
        h('label', { class: 'f' }, h('span', {}, 'המפתח (בקובץ ובקשירה)'), key, isNew ? h('small', { class: 'hint' }, 'נגזר מהשם — אפשר לשנות. אחר כך: 🔑 "שנה מפתח".') : null),
        h('label', { class: 'f' }, h('span', {}, 'הסוג'), type), optsBox,
        h('label', { class: 'line' }, pub, ' מתפרסם (מגיע לעמוד). בלי — הערה פנימית'),
        h('label', { class: 'line' }, req, ' חובה'),
        cur.col ? null : h('label', { class: 'f' }, h('span', {}, 'ברירת מחדל (למילה חדשה)'), def),
        h('label', { class: 'f' }, h('span', {}, 'עזרה למזין'), help),
        h('label', { class: 'f' }, h('span', {}, 'כיוון'), dir), out,
        h('div', { class: 'row-btns' }, save, h('button', { type: 'button', class: 'btn ghost', onclick: () => draw() }, 'חזרה')));
      const body = extra => {
        const t = type.value;
        const options = t === 'choice' ? opts.value.split('\n').map(s => s.trim()).filter(Boolean).map(s => { const [v, l] = s.split('|').map(x => x.trim()); return { value: v, label: l || v }; }) : [];
        let d = def.value.trim() === '' ? null : def.value.trim();
        if (d != null && t === 'number') d = Number(d.replace(',', '.'));
        if (d != null && t === 'bool') d = /^(1|כן|true|v)$/i.test(d);
        if (d != null && t === 'list') d = d.split(/\s*;\s*/).filter(Boolean);
        return { ...(isNew ? { key: key.value } : { orig: cur.key }), label: label.value, type: t, options, required: req.checked, published: pub.checked, help: help.value, dir: dir.value,
          ...(cur.col ? {} : { def: d }), ...extra };
      };
      const go = async extra => {
        save.disabled = true;
        try { S = await api('field_save', body(extra)); toast(isNew ? 'השדה נוסף' : 'נשמר'); onChange(); draw(); }
        catch (e) {
          save.disabled = false;
          if (e.status === 422 && e.data?.convert) convert(e.data.convert);
          else toast(e.message, true);
        }
      };
      // שינוי סוג: קודם "יומרו / ייכשלו"
      const convert = c => out.replaceChildren(h('div', { class: 'warnbox', 'data-convert': true },
        h('p', {}, h('b', {}, `יומרו ${c.converted}, ייכשלו ${c.failed_count}`)),
        c.failed_count ? h('ul', {}, c.failed.slice(0, 30).map(x => h('li', { dir: 'auto' }, `${x.word}: ${x.value}`))) : null,
        c.failed_count ? h('p', { class: 'hint' }, 'ערך שנכשל אינו נמחק בשקט: תקנו אותו בטבלה, או רוקנו במפורש (עם נקודת שחזור לפני).') : null,
        c.failed_count ? h('button', { type: 'button', class: 'btn danger', 'data-clear-failed': true, onclick: () => go({ clear_failed: true }) }, `רוקן את ${c.failed_count} הנכשלים ושמור`) : null));
      save.onclick = async () => {
        if (!label.value.trim()) { label.focus(); return; }
        if (isNew && !KEY_RX.test(key.value)) { key.focus(); toast('מפתח לא תקין', true); return; }
        if (!isNew && !cur.col && type.value !== cur.type) {
          try { const r = await api('field_save', body({ dry: true })); if (r.convert && r.convert.failed_count) { convert(r.convert); return; } }
          catch (e) { toast(e.message, true); return; }
        }
        go({});
      };
    };
    draw();
  }

  // ── "🔑 שנה מפתח" — לשדה ולנושא: כל הקשירות בקבצים, לפני ← אחרי, עם סימון לכל שורה ──
  async function rekey(kind, key, label, done) {
    const to = prompt(`מפתח חדש ל"${label}" (אותיות לטיניות קטנות, ספרות ו-_ ):`, key);
    if (!to || to === key) return;
    if (!KEY_RX.test(to)) { toast('מפתח לא תקין', true); return; }
    let p;
    try { p = await api('field_key_preview', undefined, `&kind=${kind}&key=${encodeURIComponent(key)}&to=${encodeURIComponent(to)}`); } catch (e) { toast(e.message, true); return; }
    const boxes = new Map();
    const apply = h('button', { type: 'button', class: 'btn primary', 'data-rekey-apply': true }, 'החל');
    const dlg = sheet(`🔑 ${key} ← ${to}`,
      h('p', {}, h('b', {}, `${p.hits.filter(x => !x.manual).length} קשירות ב-${p.files} קבצים`), kind === 'field' && p.values ? ` · ${p.values} ערכים יעברו למפתח החדש` : ''),
      p.hits.length ? h('ul', { class: 'list f-hits' }, p.hits.map(x => {
        const cb = h('input', { type: 'checkbox', checked: !x.manual, disabled: x.manual || null, 'aria-label': `${x.path}:${x.line}` });
        boxes.set(x.id, cb);
        return h('li', { class: 'f-hit' + (x.manual ? ' manual' : '') }, cb,
          h('span', { class: 'use-tx' }, h('small', { dir: 'ltr' }, `${x.path}:${x.line} · ${x.what}${x.manual ? ' · לבדיקה ידנית' : ''}`),
            h('code', { dir: 'ltr', class: 'f-before' }, x.before), x.manual ? null : h('code', { dir: 'ltr', class: 'f-after' }, x.after)));
      })) : h('p', { class: 'hint' }, 'אין קשירות בקבצי העמוד — רק המבנה והקובץ ישתנו.'),
      h('p', { class: 'hint' }, '↩️ לפני ההחלה תיווצר נקודת שחזור. כל קובץ נשמר כגרסה בהיסטוריה.'),
      h('div', { class: 'row-btns' }, apply, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'בטל')));
    apply.onclick = async () => {
      apply.disabled = true;
      const skip = p.hits.filter(x => !x.manual && !boxes.get(x.id).checked).map(x => x.id);
      const revs = Object.fromEntries(p.hits.map(x => [x.file, x.rev]));
      try { const r = await api('field_key_apply', { kind, key, to, skip, revs }); dlg.close(); toast(`המפתח שונה · ${r.replaced} קשירות עודכנו`); done(); }
      catch (e) { apply.disabled = false; toast(e.message, true); }
    };
  }

  // ── "🏷️ נושאים" — כל הנושאים: סדר, שם, צבע, תמונה, מוסתר, מיזוג, מפתח, הסרה ──
  async function topics(onChange) {
    let T;
    const load = async () => { T = await api('topics'); };
    try { await load(); } catch (e) { toast(e.message, true); return; }
    let drag = null;
    const save = async (body, msg) => { try { T = await api('topic_save', body); toast(msg); onChange(); draw(); } catch (e) { toast(e.message, true); } };
    const draw = () => {
      const slugs = T.topics.map(t => t.slug);
      sheet('🏷️ נושאים',
        h('p', { class: 'hint' }, 'שינוי שם אינו משנה את המפתח — הכפתורים והקישורים בעמוד נשארים. גרירה — הסדר בעמוד.'),
        T.topics.length ? h('ul', { class: 'list f-list' }, T.topics.map((t, i) => {
          const name = h('input', { value: t.name, maxlength: 80, dir: 'auto', 'aria-label': 'שם הנושא', class: 'w-cell', 'data-t-name': t.slug,
            onchange: e => { if (e.target.value.trim() && e.target.value.trim() !== t.name) save({ slug: t.slug, name: e.target.value.trim() }, 'השם נשמר — המפתח לא השתנה'); } });
          const color = h('input', { type: 'color', value: t.color || '#888888', 'aria-label': 'צבע', onchange: e => save({ slug: t.slug, color: e.target.value }, 'הצבע נשמר') });
          return h('li', { class: 'f-row', 'data-slug': t.slug, draggable: 'true', ondragstart: e => { drag = t.slug; e.dataTransfer.setData('text/plain', t.slug); },
            ondragover: e => { if (drag) e.preventDefault(); },
            ondrop: e => { e.preventDefault(); if (!drag || drag === t.slug) return; const k = slugs.filter(x => x !== drag); k.splice(k.indexOf(t.slug), 0, drag); drag = null; save({ order: k }, 'הסדר נשמר'); } },
            h('span', { class: 'w-grip', 'aria-hidden': 'true' }, '⠿'),
            h('span', { class: 'use-tx' }, name, h('small', { dir: 'ltr' }, `${t.slug} · `, h('span', { dir: 'rtl' }, `${t.count} מילים${t.hidden ? ' · מוסתר' : ''}`))),
            h('span', { class: 'f-acts' },
              h('button', { type: 'button', class: 'icon-btn', 'aria-label': `למעלה: ${t.name}`, disabled: !i, onclick: () => { const k = [...slugs]; [k[i - 1], k[i]] = [k[i], k[i - 1]]; save({ order: k }, 'הסדר נשמר'); } }, '▲'),
              color, t.color ? h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'בלי צבע', onclick: () => save({ slug: t.slug, color: '' }, 'הצבע הוסר') }, '⌀') : null,
              h('button', { type: 'button', class: 'w-aud f-timg', 'aria-label': 'תמונה', onclick: async () => { const [f] = await Media.pick({ kind: 'image' }); if (f) save({ slug: t.slug, image: f.id }, 'התמונה נשמרה'); } },
                t.image_src ? h('img', { src: t.image_src, alt: '', class: 'f-thumb' }) : '🖼️'),
              t.image ? h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'הסרת התמונה', onclick: () => save({ slug: t.slug, image: '' }, 'התמונה הוסרה') }, '✕') : null,
              h('label', { class: 'line', title: 'מוסתר: בלי כפתור בעמוד; נשאר על המילים' }, h('input', { type: 'checkbox', checked: t.hidden, 'data-t-hidden': t.slug,
                onchange: e => save({ slug: t.slug, hidden: e.target.checked }, e.target.checked ? 'הנושא מוסתר' : 'הנושא מוצג') }), ' מוסתר'),
              h('button', { type: 'button', class: 'btn sm ghost', onclick: () => merge(t) }, 'מזג אל…'),
              T.can_slug ? h('button', { type: 'button', class: 'btn sm ghost', onclick: () => rekey('topic', t.slug, t.name, async () => { await load(); onChange(); draw(); }) }, '🔑') : null,
              h('button', { type: 'button', class: 'btn sm ghost danger', 'aria-label': `הסרה: ${t.name}`, onclick: async () => {
                if (!confirm(`להסיר את הנושא "${t.name}" מ-${t.count} מילים? המילים נשארות; שחזור — ב-↩️ שחזור.`)) return;
                try { await api('topic_op', { op: 'delete', name: t.name }); await load(); toast('הוסר'); onChange(); draw(); } catch (e) { toast(e.message, true); }
              } }, '🗑️')));
        })) : h('p', { class: 'empty' }, 'אין נושאים.'));
    };
    const merge = async t => {
      const others = T.topics.filter(x => x.slug !== t.slug);
      const sel = h('select', { 'aria-label': 'אל' }, others.map(x => h('option', { value: x.slug }, x.name)));
      const go = h('button', { type: 'button', class: 'btn primary' }, 'מזג');
      const info = h('div');
      sheet(`מיזוג "${t.name}" אל…`, sel, info, h('div', { class: 'row-btns' }, go, h('button', { type: 'button', class: 'btn ghost', onclick: () => draw() }, 'חזרה')));
      const dry = async () => {
        try {
          const d = await api('topic_merge', { from: t.slug, to: sel.value, dry: true });
          info.replaceChildren(h('p', {}, `${d.words} מילים יקבלו את "${sel.selectedOptions[0].text}". "${t.name}" יעבור לסל.`),
            d.bindings.length ? h('p', { class: 'hint' }, `${d.bindings.length} קשירות בקבצי העמוד ל-${t.slug} — אחרי המיזוג: 🔑 בנושא היעד, או עדכון ידני.`) : null);
        } catch (e) { info.replaceChildren(h('p', { class: 'error' }, e.message)); }
      };
      sel.addEventListener('change', dry);
      dry();
      go.onclick = async () => { go.disabled = true; try { T = await api('topic_merge', { from: t.slug, to: sel.value }); toast('מוזג — "שחזור" מחזיר את שניהם'); onChange(); draw(); } catch (e) { go.disabled = false; toast(e.message, true); } };
    };
    draw();
  }

  return { structure, topics, cell, show, keyFrom, TYPES };
})();
