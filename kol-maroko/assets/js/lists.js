// קול מרוקו — N3: רשימה נוספת ("פתגמים") ב-📚 נתונים. נטען מ-words.js לפי הצורך (בלי שורה ב-studio.html).
// אותו רכיב לכל רשימה, לפי השדות שלה: טבלה (התא — Fields.cell), "🧩 מבנה" (Fields.structure עם list), חיפוש, סינון, גרירה
// (עכבר ומגע — Pointer Events, על הידית ⠿), אקסל, ו"➕ הוסף לעמוד". השרת: lib/lists.php. הנושאים — רק במילים.
'use strict';

const Lists = (() => {
  const PAGE = 100;
  let D = null;                     // { list, fields, items, rev }
  let KEY = '';
  let q = '', ff = '', shown = PAGE;
  const picked = new Set();
  let hooks = {};

  const norm = s => String(s).toLowerCase().replace(/[֑-ׇ]/g, '');
  const enc = encodeURIComponent;
  const textOf = (f, v) => (v == null ? '' : Fields.show(f, v));
  const titleOf = it => { for (const f of D.fields) { const v = it.data[f.key]; if (v != null && v !== '' && typeof v !== 'object') return textOf(f, v).slice(0, 60); } return '#' + it.id; };
  const asRow = it => ({ id: it.id, extra: it.data, extra_src: it.src, word: titleOf(it) });      // הצורה ש-Fields.cell מצפה לה
  const itemLabel = () => D.list.item_label || 'פריט';

  async function view(host, key, h0 = {}) {
    KEY = key; hooks = h0;
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    try { D = await api('list_items', undefined, '&list=' + enc(key)); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    picked.clear(); shown = PAGE;
    draw(host);
  }
  async function reload(host) {
    try { D = await api('list_items', undefined, '&list=' + enc(KEY)); draw(host); hooks.refreshBar?.(); } catch (e) { toast(e.message, true); }
  }

  function draw(host) {
    const search = h('input', { type: 'search', class: 'w-search', placeholder: `חיפוש ב${D.list.label}…`, value: q, 'aria-label': 'חיפוש', oninput: e => { q = e.target.value; shown = PAGE; list(); } });
    const fsel = D.fields.length ? h('select', { 'aria-label': 'סינון לפי שדה', 'data-lfilter': true, onchange: e => { ff = e.target.value; shown = PAGE; list(); } },
      h('option', { value: '' }, 'כל הפריטים'),
      D.fields.map(f => [h('option', { value: `${f.key}:has`, selected: ff === `${f.key}:has` }, `${f.label}: יש ערך`), h('option', { value: `${f.key}:no`, selected: ff === `${f.key}:no` }, `${f.label}: אין ערך`),
        ...(f.type === 'choice' ? f.options.map(o => h('option', { value: `${f.key}=${o.value}`, selected: ff === `${f.key}=${o.value}` }, `${f.label} = ${o.label}`)) : [])]).flat()) : null;
    const csvIn = h('input', { type: 'file', accept: '.csv,text/csv', hidden: true, onchange: () => readFile(csvIn).then(b64 => b64 && csvPlan(b64, false, host)) });
    const bulk = h('div', { class: 'w-bulk', hidden: !picked.size },
      h('span', { 'data-picked': picked.size }, `${picked.size} נבחרו`),
      h('button', { type: 'button', class: 'btn sm', onclick: () => moveTo([...picked], host) }, '↕️ העבר ל…'),
      h('button', { type: 'button', class: 'btn sm ghost danger', onclick: () => remove([...picked], host) }, '🗑️ לסל'),
      h('button', { type: 'button', class: 'btn sm ghost', onclick: () => { picked.clear(); draw(host); } }, 'ביטול הבחירה'));
    const box = h('div', { class: 'w-box' });
    host.replaceChildren(
      h('div', { class: 'toolbar wrap' },
        h('button', { type: 'button', class: 'btn primary', 'data-add-item': true, onclick: () => add(host) }, h('span', { 'aria-hidden': 'true' }, '+'), ' ' + itemLabel()),
        h('button', { type: 'button', class: 'btn ghost', 'data-structure': true, onclick: () => Fields.structure(() => reload(host), KEY, D.list.label) }, '🧩 מבנה'),
        h('button', { type: 'button', class: 'btn ghost', 'data-place': true, onclick: () => place() }, '➕ הוסף לעמוד'),
        h('a', { class: 'btn ghost', href: 'api.php?action=list_csv&list=' + enc(KEY), download: '' }, '⬇️ לאקסל'),
        h('button', { type: 'button', class: 'btn ghost', onclick: () => csvIn.click() }, '⬆️ מאקסל'), csvIn,
        h('button', { type: 'button', class: 'btn ghost', 'data-list-settings': true, onclick: () => settings(host) }, '⚙️ הרשימה')),
      h('div', { class: 'summary' }, h('div', { class: 'line wrap' },
        h('b', {}, String(D.items.length)), h('span', { class: 'muted' }, `פריטים ב${D.list.label} ·`), h('b', {}, String(D.fields.length)), h('span', { class: 'muted' }, 'שדות'),
        D.list.file ? h('span', { class: 'muted', dir: 'ltr' }, ' · ' + D.list.file) : h('span', { class: 'muted' }, ' · אינה רשומה ב-kol.json'))),
      h('div', { class: 'w-filters l-filters' }, search, fsel), bulk, box);

    function rows() {
      const s = norm(q.trim());
      const m = ff.match(/^([a-z][a-z0-9_]*)(?::(has|no)|=(.*))$/);
      return D.items.filter(it => {
        if (m) {
          const v = it.data[m[1]];
          const has = v != null && v !== '' && !(Array.isArray(v) && !v.length);
          if (m[2] === 'has' ? !has : m[2] === 'no' ? has : v !== m[3]) return false;
        }
        return !s || D.fields.some(f => norm(textOf(f, it.data[f.key])).includes(s));
      });
    }
    function list() {
      const all = rows();
      const table = h('table', { class: 'w-table l-table' },
        h('thead', {}, h('tr', {}, h('th', { class: 'w-c-ord' }, h('span', { title: 'הסדר בעמוד — גרירה בידית ⠿' }, '#')),
          D.fields.map(f => h('th', { class: 'w-c-field', 'data-col': f.key, title: f.help || null }, f.label, f.published ? null : h('span', { title: 'פנימי — לא מתפרסם' }, ' 🔒'))),
          h('th', { class: 'w-c-act' }, h('span', { class: 'sr' }, 'פעולות')))),
        h('tbody', {}, all.slice(0, shown).map(it => row(it, host))));
      box.replaceChildren(...[
        all.length ? h('div', { class: 'w-table-wrap' }, table) : h('p', { class: 'empty' }, D.items.length ? 'לא נמצאו פריטים.' : `עוד אין ${D.list.label}. "+ ${itemLabel()}", או ⬆️ מאקסל.`),
        all.length > shown ? h('button', { type: 'button', class: 'btn ghost w-more', onclick: () => { shown += PAGE; list(); } }, `עוד ${Math.min(PAGE, all.length - shown)} (נשארו ${all.length - shown})`) : null,
        all.length !== D.items.length ? h('p', { class: 'hint', style: 'text-align:center' }, `${all.length} מתוך ${D.items.length}`) : null,
      ].filter(Boolean));
    }
    list();
  }

  async function save(it, patch, host) {
    const r = await api('list_item_save', { list: KEY, id: it.id, data: patch });
    D = r;
    hooks.refreshBar?.();
    return r;
  }

  function row(it, host) {
    const place = D.items.indexOf(it) + 1;
    const w = asRow(it);
    const grip = h('span', { class: 'w-grip l-grip', role: 'button', tabindex: '0', title: 'גרירה לשינוי הסדר (עכבר או אצבע). מקלדת: חץ למעלה / למטה', 'aria-label': `גרירה: ${w.word}` }, '⠿');
    grip.addEventListener('pointerdown', e => dragStart(e, it, host));
    grip.addEventListener('keydown', e => {
      if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
      e.preventDefault();
      const i = D.items.indexOf(it), j = i + (e.key === 'ArrowUp' ? -1 : 1);
      if (j < 0 || j >= D.items.length) return;
      move(e.key === 'ArrowUp' ? { ids: [it.id], before: D.items[j].id } : (D.items[j + 1] ? { ids: [it.id], before: D.items[j + 1].id } : { ids: [it.id], to: D.items.length }), host, it.id);
    });
    return h('tr', { 'data-item': it.id },
      h('td', { class: 'w-c-ord' }, h('div', { class: 'w-ord' }, grip,
        h('input', { type: 'checkbox', 'aria-label': `בחירה: ${w.word}`, checked: picked.has(it.id), onchange: e => { e.target.checked ? picked.add(it.id) : picked.delete(it.id); draw(host); } }),
        h('button', { type: 'button', class: 'w-place', title: 'העבר ל…', 'aria-label': `מקום ${place} — העבר ל…`, onclick: () => moveTo(picked.has(it.id) ? [...picked] : [it.id], host) }, String(place)))),
      D.fields.map(f => h('td', { class: 'w-c-field' }, Fields.cell(f, w, v => save(it, { [f.key]: v }, host).then(r => { if (['image', 'audio', 'choice', 'bool', 'rich', 'longtext'].includes(f.type)) draw(host); return r; })))),
      h('td', { class: 'w-c-act' }, h('button', { type: 'button', class: 'icon-btn', 'aria-label': `לסל: ${w.word}`, 'data-del-item': it.id, onclick: () => remove([it.id], host) }, '🗑️')));
  }

  // ── הסדר: גרירה בידית (Pointer Events — עכבר, עט ומגע), מקלדת, ו"העבר ל…". נכתב רק מה שזז ──
  function dragStart(e, it, host) {
    if (e.button !== undefined && e.button !== 0) return;
    e.preventDefault();
    const grip = e.currentTarget;
    const tbody = grip.closest('tbody');
    const ids = picked.has(it.id) ? [...picked] : [it.id];
    const srcTr = grip.closest('tr');
    grip.setPointerCapture?.(e.pointerId);
    srcTr.classList.add('l-dragging');
    const ghost = h('div', { class: 'l-ghost', 'aria-hidden': 'true' }, ids.length > 1 ? `${ids.length} פריטים` : asRow(it).word);
    document.body.append(ghost);
    let target = null, after = false, raf = 0, lastY = e.clientY, lastX = e.clientX;
    const clear = () => tbody.querySelectorAll('.w-drop').forEach(x => x.classList.remove('w-drop', 'w-drop-after'));
    const at = () => {
      ghost.style.transform = `translate(${lastX + 8}px, ${lastY + 8}px)`;
      const el = document.elementFromPoint(lastX, lastY)?.closest('tr[data-item]');
      clear();
      target = el && el.parentNode === tbody ? el : null;
      if (target) { const r = target.getBoundingClientRect(); after = lastY > r.top + r.height / 2; target.classList.add('w-drop'); target.classList.toggle('w-drop-after', after); }
      // גלילה אוטומטית ליד שולי המסך / הטבלה
      const edge = 60, vh = window.innerHeight;
      if (lastY < edge) window.scrollBy(0, -12); else if (lastY > vh - edge) window.scrollBy(0, 12);
      raf = requestAnimationFrame(at);
    };
    const onMove = ev => { lastX = ev.clientX; lastY = ev.clientY; };
    const end = ev => {
      cancelAnimationFrame(raf);
      grip.removeEventListener('pointermove', onMove); grip.removeEventListener('pointerup', end); grip.removeEventListener('pointercancel', end);
      ghost.remove(); srcTr.classList.remove('l-dragging'); clear();
      if (ev.type === 'pointercancel' || !target) return;
      const id = +target.dataset.item;
      if (ids.includes(id)) return;
      if (!after) return move({ ids, before: id }, host);
      const rest = D.items.filter(x => !ids.includes(x.id));
      const next = rest[rest.findIndex(x => x.id === id) + 1];
      move(next ? { ids, before: next.id } : { ids, to: rest.length + 1 }, host);
    };
    grip.addEventListener('pointermove', onMove);
    grip.addEventListener('pointerup', end);
    grip.addEventListener('pointercancel', end);
    raf = requestAnimationFrame(at);
  }
  async function move(body, host, focusId) {
    try {
      const r = await api('list_item_move', { list: KEY, ...body });
      for (const [id, v] of Object.entries(r.sort || {})) { const it = D.items.find(x => x.id === +id); if (it) it.sort_order = v; }
      D.items.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
      toast(r.moved ? `הסדר נשמר · ${r.moved} זזו` : 'הסדר לא השתנה');
      draw(host);
      if (focusId) host.querySelector(`tr[data-item="${focusId}"] .l-grip`)?.focus();
    } catch (e) { toast(e.message, true); }
  }
  function moveTo(ids, host) {
    if (!ids.length) return;
    const n = parseInt(prompt(`להעביר ${ids.length > 1 ? ids.length + ' פריטים' : 'את הפריט'} למקום (1–${D.items.length}):`, '1') || '', 10);
    if (!n || n < 1) return;
    picked.clear();
    move({ ids, to: n }, host);
  }
  async function remove(ids, host) {
    if (!confirm(ids.length > 1 ? `להעביר ${ids.length} פריטים לסל?` : 'להעביר את הפריט לסל?')) return;
    try { D = await api('list_item_delete', { list: KEY, ids }); picked.clear(); toast('הועבר לסל — שחזור ב-↩️ שחזור'); draw(host); hooks.refreshBar?.(); } catch (e) { toast(e.message, true); }
  }

  // ── פריט חדש: טופס לפי השדות (קבצים — אחר כך, בטבלה) ──
  function input(f) {
    if (f.type === 'bool') return h('input', { type: 'checkbox', 'data-new': f.key });
    if (f.type === 'choice') return h('select', { 'data-new': f.key }, h('option', { value: '' }, '—'), f.options.map(o => h('option', { value: o.value }, o.label)));
    if (f.type === 'longtext' || f.type === 'rich') return h('textarea', { rows: 4, dir: 'auto', 'data-new': f.key });
    return h('input', { type: { date: 'date', url: 'url' }[f.type] || 'text', dir: f.dir === 'auto' ? 'auto' : f.dir, 'data-new': f.key, inputmode: f.type === 'number' ? 'decimal' : null, placeholder: f.type === 'list' ? 'א; ב' : '' });
  }
  function value(f, el) {
    if (f.type === 'bool') return el.checked;
    const v = el.value.trim();
    if (v === '') return null;
    if (f.type === 'number') return Number(v.replace(',', '.'));
    if (f.type === 'list') return v.split(/\s*;\s*/).filter(Boolean);
    if (f.type === 'rich') return v.startsWith('<') ? v : v.split(/\n\s*\n/).map(p => `<p>${p.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c])}</p>`).join('');
    return v;
  }
  function add(host) {
    const fs = D.fields.filter(f => f.type !== 'image' && f.type !== 'audio');
    const els = fs.map(f => [f, input(f)]);
    const go = h('button', { type: 'button', class: 'btn primary', 'data-new-save': true }, '+ הוספה');
    const dlg = sheet(`+ ${itemLabel()}`,
      els.map(([f, el]) => f.type === 'bool' ? h('label', { class: 'line' }, el, ' ' + f.label) : h('label', { class: 'f' }, h('span', {}, f.label + (f.required ? ' *' : '')), el, f.help ? h('small', { class: 'hint' }, f.help) : null)),
      D.fields.length !== fs.length ? h('p', { class: 'hint' }, 'תמונה / הקלטה — אחרי ההוספה, בתא שבטבלה.') : null,
      h('div', { class: 'row-btns' }, go, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'בטל')));
    go.onclick = async () => {
      const data = {};
      for (const [f, el] of els) { const v = value(f, el); if (v !== null && !(f.type === 'bool' && v === false)) data[f.key] = v; }
      go.disabled = true;
      try { D = await api('list_item_save', { list: KEY, data }); dlg.close(); toast('נוסף'); draw(host); hooks.refreshBar?.(); } catch (e) { go.disabled = false; toast(e.message, true); }
    };
    els[0]?.[1].focus();
  }

  // ── אקסל — אותו מנוע כמו שמירת הקובץ: "מה ישתנה" לפי id, ורק אז "החל" ──
  async function readFile(inp) {
    const f = inp.files[0];
    inp.value = '';
    if (!f) return null;
    const buf = new Uint8Array(await f.arrayBuffer());
    let bin = '';
    for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
    return btoa(bin);
  }
  async function csvPlan(b64, replace, host, create = []) {
    let r;
    try { r = await api('list_csv_import', { list: KEY, csv_b64: b64, replace_all: replace, create_fields: create }); }
    catch (e) {
      if (e.status === 422 && e.data?.errors) sheet('⬆️ מאקסל — לא יובא', h('p', { class: 'hint' }, `זוהה קידוד: ${e.data.encoding || '?'} · הטבלה לא השתנתה.`),
        h('div', { class: 'warnbox', 'data-csv-errors': true }, h('ul', {}, e.data.errors.slice(0, 50).map(x => h('li', { dir: 'auto' }, x.msg)))));
      else toast(e.message, true);
      return;
    }
    const p = r.plan, c = p.counts;
    const fold = (title, n, items) => n ? h('details', {}, h('summary', {}, `${title} (${n})`), h('ul', {}, items)) : null;
    const label = Object.fromEntries(D.fields.map(f => [f.key, f.label]));
    const apply = h('button', { type: 'button', class: 'btn primary', 'data-csv-apply': true }, replace ? `החל — ${c.trashed} לסל` : 'החל');
    const dlg = sheet('⬆️ מאקסל — מה ישתנה',
      h('p', { class: 'hint' }, `זוהה קידוד: ${r.encoding}`), h('p', { 'data-csv-summary': true }, h('b', {}, p.summary)),
      p.warnings?.length ? h('div', { class: 'warnbox' }, h('ul', {}, p.warnings.slice(0, 20).map(x => h('li', { dir: 'auto' }, x.msg)))) : null,
      (p.field_proposals || []).length ? h('div', { class: 'warnbox' }, h('p', {}, 'עמודות בלי שדה — לא ייובאו:'), h('ul', {}, p.field_proposals.map(fp => h('li', {}, h('code', { dir: 'ltr' }, fp.key), ` · ${fp.count} ערכים · ${fp.type} `,
        h('button', { type: 'button', class: 'btn sm', onclick: () => { dlg.close(); csvPlan(b64, replace, host, [...create, fp.key]); } }, `+ צור שדה ${fp.key}`))))) : null,
      fold('ישתנו', c.changed, p.changed.map(w => h('li', {}, h('b', { dir: 'auto' }, w.word), h('ul', {}, Object.entries(w.fields).map(([k, [a, b]]) => h('li', { dir: 'auto' }, `${label[k] || k}: ${a || '—'} ← ${b || '—'}`)))))),
      fold('יתווספו', c.added, p.added.map(w => h('li', { dir: 'auto' }, w.word))),
      fold('יעברו לסל', c.trashed, p.trashed.map(w => h('li', { dir: 'auto' }, w.word))),
      h('label', { class: 'line' }, h('input', { type: 'checkbox', checked: replace, onchange: e => { dlg.close(); csvPlan(b64, e.target.checked, host, create); } }), ' החלף הכול — פריט שאינו בגיליון עובר לסל (מפרסם בלבד)'),
      p.snapshot ? h('p', { class: 'hint' }, '↩️ לפני ההחלה תיווצר נקודת שחזור.') : null,
      h('div', { class: 'row-btns' }, apply, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'בטל')));
    if (!c.changed && !c.added && !c.trashed && !c.moved && !c.new_fields) apply.disabled = true;
    apply.onclick = async () => {
      if (replace && !confirm(`${c.trashed} פריטים יעברו לסל. להמשיך?`)) return;
      apply.disabled = true;
      try { D = await api('list_csv_import', { list: KEY, csv_b64: b64, replace_all: replace, create_fields: create, apply: true, token: p.token }); dlg.close(); toast('הרשימה עודכנה · ' + p.summary); draw(host); hooks.refreshBar?.(); }
      catch (e) { apply.disabled = false; toast(e.message, true); }
    };
  }

  // ── ➕ רשימה חדשה — ריקה, או מאקסל (שדה לכל עמודה, תצוגה מקדימה, ואז יצירה + ייבוא באותה עסקה) ──
  function create(done) {
    const label = h('input', { maxlength: 80, dir: 'auto', 'data-l-label': true, placeholder: 'פתגמים' });
    const item = h('input', { maxlength: 80, dir: 'auto', 'data-l-item': true, placeholder: 'פתגם' });
    const key = h('input', { maxlength: 30, dir: 'ltr', 'data-l-key': true, pattern: '[a-z][a-z0-9_]*' });
    const file = h('input', { dir: 'ltr', 'data-l-file': true });
    let keyTouched = false, fileTouched = false;
    const sync = () => { if (!fileTouched) file.value = key.value ? `data/${key.value}.json` : ''; };
    label.addEventListener('input', () => { if (!keyTouched) { key.value = Fields.keyFrom(label.value, ['words', 'topics', 'items']).slice(0, 30).replace(/_+$/, ''); sync(); } });
    key.addEventListener('input', () => { keyTouched = true; sync(); });
    file.addEventListener('input', () => { fileTouched = true; });
    const csvIn = h('input', { type: 'file', accept: '.csv,text/csv', 'data-l-csv': true });
    const out = h('div');
    let b64 = null, cols = null;
    const go = h('button', { type: 'button', class: 'btn primary', 'data-l-create': true }, '+ יצירה');
    const body = () => ({ label: label.value, item_label: item.value, key: key.value, file: file.value });
    csvIn.addEventListener('change', async () => {
      b64 = await readFile(csvIn);
      if (!b64) return;
      if (!key.value) { toast('קודם — שם ומפתח לרשימה', true); return; }
      preview();
    });
    const preview = async () => {
      let r;
      try { r = await api('list_create', { ...body(), csv_b64: b64, columns: cols }); } catch (e) { out.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
      cols = null;
      const TY = { text: 'טקסט קצר', longtext: 'טקסט ארוך', number: 'מספר', bool: 'כן / לא', date: 'תאריך', url: 'קישור', list: 'רשימה' };
      const fields = r.fields.map(f => ({ ...f, k: h('input', { value: f.key, dir: 'ltr', maxlength: 40, 'aria-label': `מפתח: ${f.label}` }), t: h('select', { 'aria-label': `סוג: ${f.label}` }, Object.entries(TY).map(([v, l]) => h('option', { value: v, selected: v === f.type }, l))) }));
      out.replaceChildren(h('div', { class: 'l-preview' },
        h('p', { class: 'hint' }, `זוהה קידוד: ${r.encoding} · ${r.count} שורות · שדה לכל עמודה (אפשר לשנות מפתח וסוג):`),
        h('div', { class: 'w-table-wrap' }, h('table', { class: 'w-table', style: 'min-inline-size:0' },
          h('thead', {}, h('tr', {}, fields.map(f => h('th', {}, h('div', {}, f.label), f.k, f.t)))),
          h('tbody', {}, r.preview.map(row => h('tr', {}, row.map(v => h('td', { dir: 'auto' }, v == null ? '' : Array.isArray(v) ? v.join('; ') : String(v)))))))),
        r.errors.length ? h('div', { class: 'warnbox' }, h('ul', {}, r.errors.slice(0, 20).map(x => h('li', { dir: 'auto' }, x.msg)))) : null,
        h('button', { type: 'button', class: 'btn sm ghost', onclick: () => { cols = colsOf(fields); preview(); } }, '↻ תצוגה מחדש')));
      go.textContent = `+ יצירה וייבוא של ${r.count}`;
      shownFields = fields;
    };
    // העמודות לפי המקום שלהן בגיליון (col) — השרת ממפה לפי אינדקס; עמודה שדולגה (מזהה / סדר) נשארת ריקה
    let shownFields = null;
    const colsOf = fields => { const c = []; for (const f of fields) c[f.col] = { key: f.k.value, type: f.t.value, label: f.label }; return Array.from(c, x => x ?? null); };
    const dlg = sheet('➕ רשימה חדשה',
      h('label', { class: 'f' }, h('span', {}, 'שם הרשימה *'), label),
      h('label', { class: 'f' }, h('span', {}, 'שם לפריט'), item),
      h('label', { class: 'f' }, h('span', {}, 'מפתח (שם הקובץ ושם הרשימה בעמוד)'), key, h('small', { class: 'hint' }, 'נגזר מהשם — אותיות לטיניות קטנות, ספרות ו-_ . אפשר לשנות גם אחר כך.')),
      h('label', { class: 'f' }, h('span', {}, 'הקובץ בעץ'), file),
      h('details', { class: 'l-from-csv' }, h('summary', {}, '📊 מאקסל — השורה הראשונה היא הכותרות'), csvIn, out),
      h('div', { class: 'row-btns' }, go, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'בטל')));
    go.onclick = async () => {
      if (!label.value.trim()) { label.focus(); return; }
      go.disabled = true;
      try {
        const extra = b64 ? { csv_b64: b64, apply: true, columns: shownFields ? colsOf(shownFields) : null } : {};
        const r = await api('list_create', { ...body(), ...extra });
        dlg.close(); toast(`הרשימה "${label.value}" נוצרה — data/${r.created}.json בעץ`); done(r.created);
      } catch (e) { go.disabled = false; toast(e.message, true); }
    };
    label.focus();
  }

  // ── ⚙️ הרשימה: שם, שם לפריט, 🔑 מפתח (עם הקשירות בקבצים), 🗑️ מחיקה ──
  function settings(host) {
    const label = h('input', { value: D.list.label, maxlength: 80, dir: 'auto' });
    const item = h('input', { value: D.list.item_label, maxlength: 80, dir: 'auto' });
    const saveBtn = h('button', { type: 'button', class: 'btn primary' }, '✓ שמירה');
    const dlg = sheet(`⚙️ ${D.list.label}`,
      h('label', { class: 'f' }, h('span', {}, 'שם הרשימה'), label),
      h('label', { class: 'f' }, h('span', {}, 'שם לפריט'), item),
      h('div', { class: 'row-btns' }, saveBtn),
      h('hr'),
      h('p', {}, 'המפתח: ', h('code', { dir: 'ltr' }, KEY), D.list.file ? [' · הקובץ: ', h('code', { dir: 'ltr' }, D.list.file)] : null),
      h('div', { class: 'row-btns' },
        h('button', { type: 'button', class: 'btn ghost', 'data-list-rekey': true, onclick: () => { dlg.close(); Fields.rekey('list', KEY, D.list.label, to => hooks.onRenamed?.(to)); } }, '🔑 שנה מפתח'),
        h('button', { type: 'button', class: 'btn ghost danger', 'data-list-delete': true, onclick: () => drop() }, '🗑️ מחיקת הרשימה')));
    saveBtn.onclick = async () => {
      try { await api('list_save', { key: KEY, label: label.value, item_label: item.value }); dlg.close(); toast('נשמר'); reload(host); } catch (e) { toast(e.message, true); }
    };
    const drop = async () => {
      let d;
      try { d = await api('list_delete', { key: KEY, dry: true }); } catch (e) { toast(e.message, true); return; }
      const msg = `למחוק את הרשימה "${D.list.label}"? ${d.items} פריטים ו-${d.fields} שדות יעברו לסל (שחזור — ב-↩️ שחזור), והרישום ב-kol.json יוסר.`
        + (d.bindings ? `\n${d.bindings} קשירות בקבצי העמוד יישארו בלי נתונים.` : '');
      if (!confirm(msg)) return;
      try { await api('list_delete', { key: KEY }); dlg.close(); toast('הרשימה הועברה לסל'); hooks.onRemoved?.(); } catch (e) { toast(e.message, true); }
    };
  }

  // ── ➕ הוסף לעמוד: קטע HTML רגיל בקובץ העמוד + בלוק ב-css/page.css, דרך מנוע הקבצים (גרסה בהיסטוריה) ──
  async function place() {
    let T;
    try { T = await api('pf_tree'); } catch (e) { toast(e.message, true); return; }
    const pages = T.files.filter(f => f.kind === 'file' && !f.linked && /\.html?$/i.test(f.path) && !f.path.startsWith('from-current/'));
    if (!pages.length) { toast('אין בפרויקט קובץ עמוד (.html)', true); return; }
    const entry = T.kol?.config?.entry || 'index.html';
    const page = h('select', { 'data-place-page': true }, pages.map(f => h('option', { value: f.path, selected: f.path === entry }, f.path)));
    const where = h('select', { 'data-place-where': true }, h('option', { value: 'main' }, 'בסוף <main>'), h('option', { value: 'body' }, 'בסוף <body>'), h('option', { value: 'after' }, 'אחרי האלמנט עם id…'));
    const id = h('input', { dir: 'ltr', 'data-place-id': true, placeholder: 'למשל top', hidden: true });
    where.addEventListener('change', () => { id.hidden = where.value !== 'after'; if (!id.hidden) id.focus(); });
    const go = h('button', { type: 'button', class: 'btn primary', 'data-place-go': true }, '➕ הוסף');
    const pub = D.fields.filter(f => f.published);
    const dlg = sheet(`➕ הוסף לעמוד — ${D.list.label}`,
      h('p', { class: 'hint' }, `נוסף קטע HTML רגיל: כותרת, חיפוש, ${pub.some(f => f.type === 'choice') ? 'כפתורי סינון, ' : ''}מונה, הרשימה (${pub.map(f => f.label).join(', ') || '—'}), "טען עוד" ו"אין תוצאות". כל כיתוב — טקסט בעמוד, ואפשר לערוך הכול.`),
      h('label', { class: 'f' }, h('span', {}, 'העמוד'), page),
      h('label', { class: 'f' }, h('span', {}, 'המקום'), where, id),
      h('p', { class: 'hint' }, 'גם css/page.css מקבל בלוק קצר עם המחלקות של הקטע. שני הקבצים נשמרים כגרסה בהיסטוריה ("הוסף את הרשימה …").'),
      h('div', { class: 'row-btns' }, go, h('button', { type: 'button', class: 'btn ghost', 'data-close': true }, 'בטל')));
    go.onclick = async () => {
      go.disabled = true;
      try {
        const r = await api('list_place', { list: KEY, path: page.value, where: where.value, id: id.value.trim() });
        dlg.close();
        toast(`נוסף ל-${r.file.path}` + (r.added.includes('script') ? ' · עם js/kol-data.js' : ''));
      } catch (e) { go.disabled = false; toast(e.message, true); }
    };
  }

  return { view, create };
})();
