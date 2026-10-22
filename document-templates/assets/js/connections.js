// חיבורים: רשימות ערכים (קבועות מראש, שטוחות או מקוננות — גם מאקסל),
// מקורות נתונים (רשימות ממערכות חיצוניות לשדה "בחירה מרשימה"),
// מפתחות API (מערכת חיצונית מנפיקה מסמכים), ותיעוד ה-API.
(function () {
  'use strict';

  const ago = (iso) => iso ? new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }) : 'אף פעם';
  const host = (u) => { try { return new URL(u).host; } catch (e) { return u; } };
  let tab = 'lists';
  try { tab = sessionStorage.getItem('dt-conn-tab') || 'lists'; } catch (e) { /* חסום */ }

  function copyBtn(text, label = 'העתקה') {
    const { el } = window.DT;
    const b = el('button', { type: 'button', class: 'small', onclick: async () => {
      try { await navigator.clipboard.writeText(text); b.textContent = '✓ הועתק'; } catch (e) { b.textContent = 'סמנו והעתיקו'; }
      setTimeout(() => { b.textContent = label; }, 1500);
    } }, label);
    return b;
  }

  async function view() {
    const { el, show, setTab } = window.DT;
    setTab('connections');
    const tabs = el('div', { class: 'seg-tabs', role: 'tablist', 'aria-label': 'חיבורים' });
    const body = el('section', { class: 'conn-body' });
    const TABS = [['lists', '📚 רשימות'], ['sources', '🔌 מקורות נתונים'], ['keys', '🔑 מפתחות API'], ['docs', '📘 איך מתחברים']];
    const render = () => {
      tabs.replaceChildren(...TABS.map(([k, t]) => el('button', { type: 'button', role: 'tab', 'data-tab': k,
        'aria-selected': String(tab === k), class: tab === k ? 'on' : '',
        onclick: () => { tab = k; try { sessionStorage.setItem('dt-conn-tab', k); } catch (e) { /* חסום */ } render(); } }, t)));
      ({ lists, sources, keys, docs })[tab](body);
    };
    show(el('div', { class: 'screen' }, tabs, body));
    render();
  }

  // ── רשימות ערכים ──
  async function lists(box) {
    const { api, el, notice } = window.DT;
    box.replaceChildren(el('p', { class: 'muted' }, 'טוען…'));
    let all;
    try { all = (await api('list_index')).lists; } catch (e) { box.replaceChildren(notice(e.message, 'bad')); return; }
    box.replaceChildren(
      el('div', { class: 'toolbar' },
        el('p', { class: 'hint grow' }, 'ערכים קבועים מראש לשדות, בכל תבנית: רשימה פשוטה (סוגי קבר), או מקוננת (בית עלמין › גוש › חלקה › שורה) — ' +
          'בשדה הבא מופיעים רק הערכים ששייכים למה שנבחר. בעורך: הגדרות השדה ← "📚 ערכים מרשימה".'),
        el('button', { class: 'primary', onclick: () => listSheet(null, () => lists(box)) }, '+ רשימה')),
      all.length ? el('ul', { class: 'list card-list' }, all.map(l => el('li', {},
        el('button', { type: 'button', class: 'use-row', onclick: () => listSheet(l, () => lists(box)) },
          el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📚'),
          el('span', { class: 'use-tx' }, el('b', {}, l.name),
            el('small', {}, l.levels.join(' › ') + ((l.langs || []).length ? ' · 🌐 ' + l.langs.join(', ') : '') + ' · עודכן ' + ago(l.updated_at))),
          el('span', { class: 'badge' }, String(l.row_count))))))
        : el('p', { class: 'empty' }, 'עוד אין רשימות. "+ רשימה" — כותבים, או מייבאים מקובץ Excel.'));
  }

  /** מה זוהה כעמודה לפלט (במסמך במקום הערך שנבחר) — או שלא זוהתה אף אחת. */
  const outText = (r) => (r.outputs || []).length
    ? ' עמודות למסמך: ' + r.outputs.map(o => `${o.column} ← ${o.level}`).join(', ') + '.'
    : ' עמודה אחת לכל רמה (בלי עמודה באנגלית).';

  /** עריכת רשימה: שם, רמות, שורות (טבלה עם חיפוש), וייבוא מ-Excel / CSV. */
  async function listSheet(l, after) {
    const { api, el, notice, sheet } = window.DT;
    let data = { name: '', levels: ['ערך'], rows: [], translations: {} };
    if (l) {
      try { data = (await api('list_get', undefined, { id: l.id })).list; } catch (e) { window.DT.toast(e.message, true); return; }
    }
    if (!data.translations || Array.isArray(data.translations)) data.translations = {};
    const msg = el('div');
    const name = el('input', { value: data.name, maxlength: 80, required: true, placeholder: 'למשל: מיקומי קברים' });
    const levels = el('input', { value: data.levels.join(' › '), placeholder: 'בית עלמין › גוש › חלקה › שורה' });
    const search = el('input', { type: 'search', placeholder: 'חיפוש בשורות', 'aria-label': 'חיפוש בשורות' });
    const grid = el('div', { class: 'list-grid' });
    const count = el('span', { class: 'muted small' });
    const SHOW = 200;
    const lv = () => levels.value.split(/[›>,|]/).map(x => x.trim()).filter(Boolean).slice(0, 8);
    // רשימה גדולה (אלפי שורות): לא עורכים בטבלה — מחפשים בשרת, ומעדכנים בייבוא קובץ מחדש
    const big = !!data.big;
    if (big) levels.disabled = true;
    let bigTimer = 0;
    function drawGrid() {
      if (big) return drawBig();
      const L = lv().length ? lv() : ['ערך'];
      const q = search.value.trim();
      const idx = data.rows.map((r, i) => i).filter(i => !q || data.rows[i].some(v => String(v).includes(q)));
      count.textContent = `${data.rows.length} שורות` + (q ? ` · ${idx.length} מתאימות` : '') + (idx.length > SHOW ? ` · מוצגות ${SHOW} הראשונות` : '');
      grid.replaceChildren(el('table', { class: 'grid' },
        el('thead', {}, el('tr', {}, L.map(n => el('th', {}, n)), el('th'))),
        el('tbody', {}, idx.slice(0, SHOW).map(i => el('tr', {}, L.map((_, j) => {
          const inp = el('input', { value: data.rows[i][j] ?? '', 'aria-label': L[j] });
          inp.addEventListener('input', () => { data.rows[i][j] = inp.value; });
          return el('td', { 'data-label': L[j] }, inp);
        }), el('td', { class: 'row-del' }, el('button', { type: 'button', class: 'link small', title: 'מחיקת שורה',
          onclick: () => { data.rows.splice(i, 1); drawGrid(); } }, '✕')))))));
    }
    function drawBig() {
      const L = data.levels;
      count.textContent = `${data.row_count.toLocaleString('he-IL')} שורות` + (search.value.trim() ? ` · ${data.matches.toLocaleString('he-IL')} מתאימות` : '')
        + (data.matches > data.rows.length ? ` · מוצגות ${data.rows.length}` : '');
      grid.replaceChildren(el('table', { class: 'grid' },
        el('thead', {}, el('tr', {}, L.map(n => el('th', {}, n)))),
        el('tbody', {}, data.rows.map(r => el('tr', {}, L.map((n, j) => el('td', { 'data-label': n }, r[j] || '')))))));
    }
    levels.addEventListener('change', () => { drawGrid(); drawTr(); });

    // ── תרגומים: לכל ערך ברמה — איך הוא נכתב במסמך בשפה אחרת (בוחרים בעברית, מדפיסים באנגלית) ──
    const LANGS = { en: 'אנגלית · English', fr: 'צרפתית · Français', ar: 'ערבית · العربية', ru: 'רוסית · Русский', es: 'ספרדית · Español', de: 'גרמנית · Deutsch' };
    const trLevel = el('select', { 'aria-label': 'רמה לתרגום' });
    const trLang = el('select', { 'aria-label': 'שפה' }, Object.entries(LANGS).map(([k, n]) => el('option', { value: k }, n)));
    const trSearch = el('input', { type: 'search', placeholder: 'חיפוש ערך', 'aria-label': 'חיפוש ערך לתרגום' });
    const trBox = el('div', { class: 'list-grid' });
    const trCount = el('span', { class: 'muted small' });
    function drawTr() {
      const L = lv().length ? lv() : ['ערך'];
      const keep = Number(trLevel.value) || 0;
      trLevel.replaceChildren(...L.map((n, i) => el('option', { value: i, selected: i === keep }, `${i + 1}. ${n}`)));
      const level = Math.min(keep, L.length - 1), lang = trLang.value, q = trSearch.value.trim();
      const vals = big ? (data.values[level] || []) : [...new Set(data.rows.map(r => String(r[level] ?? '').trim()).filter(Boolean))];
      const t = (data.translations[level] ??= {});
      const shown = vals.filter(v => !q || v.includes(q) || String(t[v]?.[lang] ?? '').toLowerCase().includes(q.toLowerCase()));
      const done = vals.filter(v => t[v]?.[lang]).length;
      trCount.textContent = `${done} מתוך ${vals.length} מתורגמים` + (shown.length > SHOW ? ` · מוצגים ${SHOW} הראשונים` : '');
      trBox.replaceChildren(el('table', { class: 'grid' },
        el('thead', {}, el('tr', {}, el('th', {}, L[level] + ' (כמו שנבחר)'), el('th', {}, data.translations.names?.[level]?.[lang] || LANGS[lang]))),
        el('tbody', {}, shown.slice(0, SHOW).map(v => {
          const inp = el('input', { value: t[v]?.[lang] ?? '', dir: ['ar'].includes(lang) ? 'rtl' : 'ltr', 'aria-label': v + ' — ' + LANGS[lang] });
          inp.addEventListener('input', () => {
            const x = inp.value.trim();
            if (x) (t[v] ??= {})[lang] = x; else if (t[v]) { delete t[v][lang]; if (!Object.keys(t[v]).length) delete t[v]; }
          });
          inp.addEventListener('change', () => { trCount.textContent = `${vals.filter(w => t[w]?.[lang]).length} מתוך ${vals.length} מתורגמים`; });
          return el('tr', {}, el('td', { 'data-label': L[level] }, v), el('td', { 'data-label': LANGS[lang] }, inp));
        }))));
      if (!vals.length) trBox.replaceChildren(el('p', { class: 'muted small' }, 'אין עדיין ערכים ברמה הזו.'));
      if (big && data.values_more[level]) trBox.prepend(el('p', { class: 'hint' }, 'ברמה הזו יש יותר מ-3,000 ערכים — מוצגים הראשונים. לתרגום של כולם: עמודת "(en)" בקובץ, וייבוא מחדש.'));
    }
    trLevel.addEventListener('change', drawTr); trLang.addEventListener('change', drawTr); trSearch.addEventListener('input', drawTr);
    search.addEventListener('input', () => {
      if (!big) return drawGrid();
      clearTimeout(bigTimer);
      bigTimer = setTimeout(async () => {
        try { const r = (await api('list_get', undefined, { id: l.id, q: search.value.trim() })).list; data.rows = r.rows; data.matches = r.matches; drawBig(); }
        catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
      }, 300);
    });
    const file = el('input', { type: 'file', accept: '.xlsx,.csv', hidden: true });
    file.addEventListener('change', async () => {
      if (!file.files[0]) return;
      const fl = file.files[0];
      if (big || fl.size > 300 * 1024) { await importDirect(fl); file.value = ''; return; }
      const fd = new FormData(); fd.append('file', fl);
      try {
        const r = await api('list_import', fd);
        if (data.rows.length && !confirm(`לייבא ${r.rows.length} שורות במקום ${data.rows.length} השורות שכאן?`)) return;
        data.rows = r.rows; levels.value = r.levels.join(' › ');
        if (r.translations && Object.keys(r.translations).length) data.translations = r.translations;
        drawTr();
        if (!name.value) name.value = file.files[0].name.replace(/\.[^.]+$/, '');
        msg.replaceChildren(notice(`יובאו ${r.rows.length} שורות, ברמות: ${r.levels.join(' › ')}.` + outText(r) + ' בדקו ושמרו.', 'ok'));
        drawGrid();
      } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
      file.value = '';
    });
    /** קובץ גדול: נשלח בחלקים (כל חלק קטן ממגבלת ההעלאה של השרת), ונשמר ישר בשרת — בלי להעביר את כל השורות דרך הדפדפן. */
    async function importDirect(fl) {
      if (!confirm(`"${fl.name}" — קובץ גדול. הוא ייקלט ויישמר ישר ברשימה` + (l ? ' ויחליף את השורות שבה' : '') + '. להמשיך?')) return;
      const stage = [...crypto.getRandomValues(new Uint8Array(8))].map(b => b.toString(16).padStart(2, '0')).join('');
      const CHUNK = 1024 * 1024;
      const parts = Math.max(1, Math.ceil(fl.size / CHUNK));
      try {
        let r;
        for (let i = 0; i < parts; i++) {
          msg.replaceChildren(notice(`מעלה… ${Math.round((i / parts) * 100)}%`));
          const fd = new FormData();
          fd.append('file', fl.slice(i * CHUNK, (i + 1) * CHUNK), 'part.bin');
          fd.append('stage', stage); fd.append('part', String(i));
          if (i === parts - 1) {
            fd.append('last', '1'); fd.append('apply', '1');
            if (l) fd.append('id', String(l.id)); else fd.append('name', name.value.trim() || fl.name.replace(/\.[^.]+$/, ''));
            msg.replaceChildren(notice('קולט ושומר…'));
          }
          r = await api('list_import', fd);
        }
        dlg.close(); after();
        window.DT.toast(`נשמר: ${r.list.name} · ${r.count.toLocaleString('he-IL')} שורות · ${r.levels.join(' › ')}.` + outText(r));
      } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    }
    const save = el('button', { type: 'button', class: 'primary small', onclick: async () => {
      save.disabled = true;
      try {
        const r = await api('list_save', big ? { id: l.id, name: name.value, translations: data.translations, keep_rows: true }
          : { id: l ? l.id : undefined, name: name.value, levels: lv(), rows: data.rows, translations: data.translations });
        dlg.close(); after(); window.DT.toast(`נשמר: ${r.list.name} · ${r.list.row_count} שורות`);
      } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); save.disabled = false; }
    } }, 'שמירה');
    const dlg = sheet(l ? 'רשימה: ' + l.name : 'רשימה חדשה', el('div', { class: 'field-sheet list-sheet' },
      el('label', { class: 'f' }, el('span', {}, 'שם'), name),
      el('label', { class: 'f' }, el('span', {}, 'רמות — מהכללי לפרטי, מופרדות ב-›'), levels,
        el('small', { class: 'muted' }, 'רמה אחת — רשימה פשוטה. כמה רמות — מקוננת: כל שורה היא מסלול מלא.')),
      el('div', { class: 'row-btns wrap' },
        el('button', { type: 'button', class: 'small', onclick: () => file.click() }, '📥 ייבוא מ-Excel / CSV'), file,
        big ? null : el('button', { type: 'button', class: 'small', onclick: () => { data.rows.push(lv().map(() => '')); search.value = ''; drawGrid();
          grid.querySelector('tbody tr:last-child input')?.focus(); } }, '+ שורה')),
      big ? el('p', { class: 'notice' }, `רשימה גדולה (${data.row_count.toLocaleString('he-IL')} שורות): בטופס האפשרויות נטענות מהשרת לפי מה שנבחר. ` +
        'כאן — חיפוש ותצוגה; לשינוי השורות מעדכנים את הקובץ ומייבאים מחדש. שם ותרגומים — נשמרים מכאן.') : null,
      el('p', { class: 'hint' }, 'בקובץ: השורה הראשונה — שמות הרמות; כל שורה אחריה — מסלול. תא ריק = כמו בשורה שמעליו (כמו שכותבים היררכיה באקסל).'),
      msg, el('div', { class: 'row-btns' }, search, count), grid,
      el('details', { class: 'fs-group tr-group' }, el('summary', {}, '🌐 תרגומים — הערך במסמך בשפה אחרת'),
        el('p', { class: 'hint' }, 'בטופס בוחרים בעברית; בשדה שהוגדר לו "🌐 שפה במסמך" — נכתב במסמך התרגום מכאן. ערך בלי תרגום נכתב כמו שהוא. ' +
          'מקובץ: עמודה באנגלית ליד כל עמודה בעברית ("בית עלמין, Cemetery, גוש, Block…"), או כולן בסוף באותו סדר; לשפה אחרת — "בית עלמין (fr)".'),
        el('div', { class: 'fields two' }, el('label', { class: 'f' }, el('span', {}, 'רמה'), trLevel), el('label', { class: 'f' }, el('span', {}, 'שפה'), trLang)),
        el('div', { class: 'row-btns' }, trSearch, trCount), trBox),
      l ? el('details', { class: 'fs-group adv' }, el('summary', {}, 'מתקדם'),
        el('button', { type: 'button', class: 'small danger', onclick: async () => {
          if (!confirm(`למחוק את הרשימה "${l.name}"? שדות שקשורים אליה יישארו בלי רשימה.`)) return;
          try { await api('list_delete', { id: l.id }); dlg.close(); after(); } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
        } }, 'מחיקת הרשימה')) : null), { action: save });
    drawGrid(); drawTr();
  }

  // ── מקורות נתונים ──
  async function sources(box) {
    const { api, el, notice } = window.DT;
    box.replaceChildren(el('p', { class: 'muted' }, 'טוען…'));
    let list;
    try { list = (await api('src_list')).sources; } catch (e) { box.replaceChildren(notice(e.message, 'bad')); return; }
    box.replaceChildren(
      el('div', { class: 'toolbar' },
        el('p', { class: 'hint grow' }, 'מקור = כתובת במערכת חיצונית שמחזירה רשימה (JSON): לקוחות, מוצרים, סניפים. שדה "בחירה מרשימה" מציג ממנה את האפשרויות, ויכול למלא שדות נוספים מהרשומה שנבחרה.'),
        el('button', { class: 'primary', onclick: () => sourceSheet(null, () => sources(box)) }, '+ מקור')),
      list.length ? el('ul', { class: 'list card-list' }, list.map(s => el('li', {},
        el('button', { type: 'button', class: 'use-row', onclick: () => sourceSheet(s, () => sources(box)) },
          el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🔌'),
          el('span', { class: 'use-tx' }, el('b', {}, s.name),
            el('small', {}, host(s.url) + ' · ' + (s.last_error ? '⚠ ' + s.last_error : `${s.row_count} רשומות · עודכן ${ago(s.fetched_at)}`))),
          s.last_error ? el('span', { class: 'badge bad' }, 'שגיאה') : el('span', { class: 'badge ok' }, String(s.row_count))))))
        : el('p', { class: 'empty' }, 'עוד אין מקורות. "+ מקור" — כתובת, ואם צריך אסימון.'));
  }

  function sourceSheet(s, after) {
    const { api, el, notice, sheet } = window.DT;
    const v = s || { name: '', url: '', headers: '', path: '', value_key: '', label_key: '', ttl_min: 60, keys: [], sample: [] };
    const inp = (label, name, attrs = {}, hint) => {
      const i = el(attrs.rows ? 'textarea' : 'input', { name, ...attrs });
      i.value = v[name] ?? '';
      return el('label', { class: 'f' }, el('span', {}, label), i, hint ? el('small', { class: 'muted' }, hint) : null);
    };
    const ttl = el('select', { name: 'ttl_min' }, [[5, '5 דקות'], [60, 'שעה'], [360, '6 שעות'], [1440, 'יום'], [10080, 'שבוע']]
      .map(([m, t]) => el('option', { value: m, selected: v.ttl_min === m }, t)));
    const form = el('form', { class: 'field-sheet' });
    const msg = el('div');
    const preview = el('div');
    const save = el('button', { type: 'submit', class: 'primary small', form: 'src-form' }, s ? 'שמירה ובדיקה' : 'הוספה ובדיקה');
    form.id = 'src-form';
    const showPreview = (x) => {
      preview.replaceChildren(...[
        x.last_error ? notice('⚠ ' + x.last_error, 'bad') : null,
        x.row_count ? el('p', { class: 'hint' }, `✓ ${x.row_count} רשומות. שדות: ${x.keys.join(' · ')}`) : null,
        x.sample?.length ? el('div', { class: 'table-wrap' }, el('table', { class: 'sample' },
          el('thead', {}, el('tr', {}, x.keys.slice(0, 6).map(k => el('th', {}, k)))),
          el('tbody', {}, x.sample.map(r => el('tr', {}, x.keys.slice(0, 6).map(k => el('td', {}, r[k] ?? ''))))))) : null].filter(Boolean));
    };
    form.append(...[
      el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, 'המקור'),
        inp('שם', 'name', { required: true, maxlength: 80, placeholder: 'למשל: לקוחות מה-CRM' }),
        inp('כתובת (GET)', 'url', { required: true, dir: 'ltr', type: 'url', placeholder: 'https://crm.example.com/api/customers' }),
        inp('כותרות — אם המערכת דורשת הרשאה', 'headers', { rows: 2, dir: 'ltr', placeholder: 'Authorization: Bearer xxxxx' },
          'שורה לכל כותרת. נשמר מוצפן, ונשלח רק מהשרת — אף ממלא לא רואה אותו.')),
      el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, 'איפה הרשימה בתשובה'),
        inp('נתיב לרשימה', 'path', { dir: 'ltr', placeholder: 'data.items — ריק אם התשובה היא הרשימה עצמה' }),
        el('div', { class: 'fields two' },
          inp('שדה הערך (מזהה)', 'value_key', { dir: 'ltr', placeholder: 'id' }),
          inp('שדה התצוגה', 'label_key', { dir: 'ltr', placeholder: 'name' })),
        el('label', { class: 'f' }, el('span', {}, 'רענון אוטומטי כל'), ttl)),
      msg, preview,
      s ? el('details', { class: 'fs-group adv' }, el('summary', {}, 'מתקדם'),
        el('div', { class: 'ops' },
          el('button', { type: 'button', class: 'small', onclick: async (e) => {
            e.target.disabled = true;
            try { const r = (await api('src_sync', { id: s.id })).source; showPreview(r); msg.replaceChildren(notice('סונכרן', 'ok')); }
            catch (err) { msg.replaceChildren(notice(err.message, 'bad')); }
            e.target.disabled = false;
          } }, '🔄 סנכרון עכשיו'),
          el('button', { type: 'button', class: 'small danger', onclick: async () => {
            if (!confirm(`למחוק את המקור "${s.name}"? שדות שמשתמשים בו יישארו בלי רשימה.`)) return;
            try { await api('src_delete', { id: s.id }); dlg.close(); after(); } catch (err) { msg.replaceChildren(notice(err.message, 'bad')); }
          } }, 'מחיקה'))) : null].filter(Boolean));
    if (s) showPreview(s);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const b = Object.fromEntries(new FormData(form));
      b.ttl_min = Number(b.ttl_min);
      if (s) b.id = s.id;
      save.disabled = true; save.textContent = 'בודק…';
      try {
        const r = (await api('src_save', b)).source;
        s = r; Object.assign(v, r);
        showPreview(r);
        msg.replaceChildren(r.last_error ? notice('נשמר, אבל המשיכה נכשלה — בדקו את הכתובת וההרשאה.', 'bad') : notice('נשמר ונמשך בהצלחה.', 'ok'));
        after();
      } catch (err) { msg.replaceChildren(notice(err.message, 'bad')); }
      save.disabled = false; save.textContent = 'שמירה ובדיקה';
    });
    const dlg = sheet(s ? 'מקור: ' + s.name : 'מקור נתונים חדש', form, { action: save });
  }

  // ── מפתחות API ──
  async function keys(box) {
    const { api, el, notice } = window.DT;
    box.replaceChildren(el('p', { class: 'muted' }, 'טוען…'));
    let r;
    try { r = await api('key_list'); } catch (e) { box.replaceChildren(notice(e.message, 'bad')); return; }
    box.replaceChildren(
      el('div', { class: 'toolbar' },
        el('p', { class: 'hint grow' }, 'מפתח = מערכת חיצונית (CRM, אתר, מערכת הזמנות) שמנפיקה מסמכים מהתבניות שלך, ומקבלת הודעה כשהם מולאו. הממלאים מקבלים קישור רגיל — בלי שם המערכת.'),
        el('button', { class: 'primary', onclick: () => newKey(() => keys(box)) }, '+ מפתח')),
      r.keys.length ? el('ul', { class: 'list card-list' }, r.keys.map(k => el('li', { class: k.revoked ? 'archived' : '' },
        el('button', { type: 'button', class: 'use-row', disabled: k.revoked, onclick: () => keySheet(k, () => keys(box)) },
          el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🔑'),
          el('span', { class: 'use-tx' }, el('b', {}, k.name),
            el('small', { dir: 'auto' }, k.prefix + '… · שימוש אחרון: ' + ago(k.last_used_at) + (k.webhook ? ' · 🔔 ' + host(k.webhook) : ''))),
          k.revoked ? el('span', { class: 'badge' }, 'בוטל') : el('span', { class: 'badge ok' }, 'פעיל')))))
        : el('p', { class: 'empty' }, 'עוד אין מפתחות.'));
  }

  function newKey(after) {
    const { api, el, notice, sheet } = window.DT;
    const name = el('input', { name: 'name', maxlength: 80, placeholder: 'למשל: CRM, אתר החנות', required: true });
    const hook = el('input', { name: 'webhook', dir: 'ltr', type: 'url', placeholder: 'https://… (לא חובה)' });
    const body = el('div', { class: 'field-sheet' });
    const go = el('button', { type: 'button', class: 'primary small' }, 'יצירה');
    const form = () => body.replaceChildren(
      el('label', { class: 'f' }, el('span', {}, 'שם — של מי המפתח'), name),
      el('label', { class: 'f' }, el('span', {}, 'webhook — לאן לשלוח הודעה כשממלא סיים / מסמך הושלם'), hook),
      el('p', { class: 'hint' }, 'המפתח יוצג פעם אחת בלבד. שמרו אותו במערכת החיצונית.'));
    form();
    go.onclick = async () => {
      if (!name.value.trim()) { name.focus(); return; }
      go.disabled = true;
      try {
        const r = await api('key_create', { name: name.value, webhook: hook.value });
        go.hidden = true;
        body.replaceChildren(
          notice('✓ המפתח נוצר. העתיקו אותו עכשיו — הוא לא יוצג שוב.', 'ok'),
          el('label', { class: 'f' }, el('span', {}, 'מפתח ה-API'), el('code', { class: 'secret', dir: 'ltr' }, r.key)), copyBtn(r.key, 'העתקת המפתח'),
          el('label', { class: 'f' }, el('span', {}, 'סוד לאימות ה-webhook (אפשר לראות שוב מהמפתח)'), el('code', { class: 'secret', dir: 'ltr' }, r.secret)), copyBtn(r.secret, 'העתקת הסוד'));
        after();
      } catch (e) { body.prepend(notice(e.message, 'bad')); go.disabled = false; }
    };
    sheet('מפתח API חדש', body, { action: go });
  }

  function keySheet(k, after) {
    const { api, el, notice, sheet } = window.DT;
    const msg = el('div');
    const name = el('input', { value: k.name, maxlength: 80 });
    const hook = el('input', { value: k.webhook, dir: 'ltr', type: 'url', placeholder: 'https://…' });
    const save = el('button', { type: 'button', class: 'primary small', onclick: async () => {
      try { await api('key_update', { id: k.id, name: name.value, webhook: hook.value }); msg.replaceChildren(notice('נשמר', 'ok')); after(); }
      catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    } }, 'שמירה');
    const secretBox = el('div');
    const dlg = sheet('מפתח: ' + k.name, el('div', { class: 'field-sheet' },
      el('p', { class: 'hint', dir: 'auto' }, `${k.prefix}… · נוצר ${ago(k.created_at)} · שימוש אחרון: ${ago(k.last_used_at)}`),
      el('label', { class: 'f' }, el('span', {}, 'שם'), name),
      el('label', { class: 'f' }, el('span', {}, 'webhook'), hook),
      msg,
      el('div', { class: 'ops' },
        el('button', { type: 'button', class: 'small', onclick: async () => {
          try { const r = await api('key_test', { id: k.id }); msg.replaceChildren(notice(r.status >= 200 && r.status < 300 ? `✓ נשלחה הודעת בדיקה — HTTP ${r.status}` : `⚠ ההודעה לא התקבלה (HTTP ${r.status || 'אין חיבור'})`, r.status >= 200 && r.status < 300 ? 'ok' : 'bad')); }
          catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
        } }, '🔔 בדיקת webhook'),
        el('button', { type: 'button', class: 'small', onclick: async () => {
          try { const s = (await api('key_secret', { id: k.id })).secret; secretBox.replaceChildren(el('code', { class: 'secret', dir: 'ltr' }, s), copyBtn(s)); }
          catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
        } }, '🔐 הצגת הסוד')),
      secretBox,
      el('details', { class: 'fs-group adv' }, el('summary', {}, 'ביטול המפתח'),
        el('p', { class: 'hint' }, 'מערכת שמשתמשת במפתח תקבל 401 מיד. מסמכים שכבר הונפקו נשארים.'),
        el('button', { type: 'button', class: 'small danger', onclick: async () => {
          if (!confirm(`לבטל את המפתח "${k.name}"? אי אפשר לבטל את הביטול.`)) return;
          try { await api('key_revoke', { id: k.id }); dlg.close(); after(); } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
        } }, 'ביטול המפתח'))), { action: save });
  }

  // ── תיעוד ──
  async function docs(box) {
    const { api, el, notice } = window.DT;
    let base = location.href.split('#')[0].replace(/[^/]*$/, '');
    let templates = [];
    try { base = (await api('key_list')).base || base; templates = (await api('tpl_list')).templates || []; } catch (e) { /* ממשיכים עם מה שיש */ }
    const url = (a) => base + 'api.php?action=' + a;
    const code = (t) => el('div', { class: 'code-box' }, el('pre', { dir: 'ltr' }, t), copyBtn(t));
    const ep = (method, action, what) => el('li', {}, el('code', { dir: 'ltr' }, method + ' ' + action), ' — ', what);
    const tplPick = el('select', { 'aria-label': 'תבנית' }, el('option', { value: '' }, '— בחרו תבנית לדוגמה —'),
      templates.map(t => el('option', { value: t.id }, t.name)));
    const tplOut = el('div');
    tplPick.addEventListener('change', async () => {
      if (!tplPick.value) { tplOut.replaceChildren(); return; }
      try {
        const t = (await api('tpl_get', undefined, { id: tplPick.value })).template;
        const fields = (t.schema.fields || []).filter(f => !['calc', 'system'].includes(f.type));
        const sample = {};
        for (const f of fields) {
          sample[f.key] = f.type === 'select' ? (f.options?.[0]?.value ?? 'ערך מהרשימה') : f.type === 'number' ? 100 : f.type === 'date' ? '2026-10-14'
            : f.type === 'checkbox' ? true : f.type === 'table' ? [{}] : f.type === 'signature' || f.type === 'file' ? undefined : f.label;
        }
        const roles = (t.schema.roles || []).filter(r => r.key !== 'owner');
        const issue = { template_id: t.id, ref: 'ORDER-1001', title: t.name + ' — לקוח לדוגמה', sender_name: 'שם העסק שלך', values: sample,
          participants: roles.map(r => ({ role: r.key, name: 'שם הממלא', email: 'client@example.com', send: 'link' })) };
        tplOut.replaceChildren(
          el('div', { class: 'table-wrap' }, el('table', { class: 'sample' },
            el('thead', {}, el('tr', {}, ['key', 'שדה', 'סוג', 'חובה', 'ממלא'].map(h => el('th', {}, h)))),
            el('tbody', {}, (t.schema.fields || []).map(f => el('tr', {},
              el('td', { dir: 'ltr' }, f.key), el('td', {}, f.label), el('td', {}, f.type + (f.type === 'select' && f.options?.length ? ` (${f.options.length})` : '')),
              el('td', {}, f.required ? '✓' : ''), el('td', {}, ['calc', 'system'].includes(f.type) ? 'לבד' : f.role === 'owner' || !f.role ? 'מנפיק' : f.role)))))),
          el('p', { class: 'hint' }, 'ערכים אפשר לשלוח לפי key, לפי הסימון {{…}} או לפי שם השדה. שדות שמתמלאים לבד — לא שולחים.'),
          code(`curl -X POST '${url('ext_issue')}' \\\n  -H 'X-API-Key: dtk_…' -H 'Content-Type: application/json' \\\n  -d '${JSON.stringify(issue, null, 2)}'`));
      } catch (e) { tplOut.replaceChildren(notice(e.message, 'bad')); }
    });
    box.replaceChildren(el('div', { class: 'docs-api' },
      el('h3', { class: 'sec-title' }, 'הכתובת'),
      code(base + 'api.php?action=<פעולה>'),
      el('p', { class: 'hint' }, 'הזדהות: הכותרת X-API-Key עם המפתח (או Authorization: Bearer). JSON בשני הכיוונים. עד 600 בקשות בשעה למפתח.'),
      el('h3', { class: 'sec-title' }, 'פעולות'),
      el('ul', { class: 'endpoints' },
        ep('GET', 'ext_templates', 'התבניות שלך'),
        ep('GET', 'ext_template&id=…', 'השדות של תבנית: key, סוג, חובה, אפשרויות, ממלאים'),
        ep('POST', 'ext_issue', 'הנפקת מסמך: ערכים, ref שלכם, ממלאים — וקבלת הקישורים. אטומית: שגיאה לא משאירה מסמך חלקי. בקשה חוזרת עם אותו ref ואותם נתונים מחזירה את אותו מסמך ואותם קישורים (בלי מייל שני); אותו ref עם נתונים אחרים — 409'),
        ep('GET', 'ext_document&id=… / &ref=…', 'מצב המסמך, הערכים, מי סיים'),
        ep('POST', 'ext_update', 'עדכון ערכים במסמך שעוד לא הושלם (id או ref)'),
        ep('GET', 'ext_file&id=…', 'קובץ Word ממולא (תבנית Word או שנכתבה בכלי)')),
      el('h3', { class: 'sec-title' }, 'דוגמה לתבנית שלך'),
      tplPick, tplOut,
      el('h3', { class: 'sec-title' }, 'ממלאים — בלי שהם יודעים על המערכת'),
      el('p', { class: 'hint' }, 'בכל ממלא: send: "link" — מקבלים את הקישור בתשובה ושולחים אותו בעצמכם (SMS, וואטסאפ, מייל שלכם); send: "email" — נשלח מכאן, בשם sender_name; send: "none" — רק שומר את הפרטים. דף המילוי ניטרלי: "מילוי מסמך", בלי שם המערכת.'),
      el('h3', { class: 'sec-title' }, 'webhook — כשמשהו קורה'),
      el('p', { class: 'hint' }, 'למסמכים שהונפקו דרך המפתח: participant.completed (ממלא סיים) ו-document.completed (הכול מולא, ננעל). POST עם JSON, וכותרת X-DT-Signature לאימות:'),
      code(`{ "event": "document.completed", "at": "2026-10-14T10:00:00Z",\n  "document": { "id": 17, "ref": "ORDER-1001", "number": "2026-0017", "status": "completed" }, "hash": "…" }`),
      code(`// אימות (Node):\nconst sig = 'sha256=' + crypto.createHmac('sha256', SECRET).update(rawBody).digest('hex');\nif (sig !== req.headers['x-dt-signature']) return res.status(401).end();`),
      el('h3', { class: 'sec-title' }, 'רשימות ממערכת חיצונית'),
      el('p', { class: 'hint' }, 'בכיוון ההפוך: שדה "בחירה מרשימה" יכול לקבל את האפשרויות מכתובת שלכם (לשונית "מקורות נתונים"). השרת מושך אותה, שומר עותק, ומרענן לפי הזמן שנקבע — או ב"סנכרון עכשיו".')));
  }

  window.DTConn = { view };
})();
