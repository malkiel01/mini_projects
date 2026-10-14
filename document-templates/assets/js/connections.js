// חיבורים: מקורות נתונים (רשימות ממערכות חיצוניות לשדה "בחירה מרשימה"),
// מפתחות API (מערכת חיצונית מנפיקה מסמכים), ותיעוד ה-API.
(function () {
  'use strict';

  const ago = (iso) => iso ? new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }) : 'אף פעם';
  const host = (u) => { try { return new URL(u).host; } catch (e) { return u; } };
  let tab = 'sources';
  try { tab = sessionStorage.getItem('dt-conn-tab') || 'sources'; } catch (e) { /* חסום */ }

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
    const TABS = [['sources', '🔌 מקורות נתונים'], ['keys', '🔑 מפתחות API'], ['docs', '📘 איך מתחברים']];
    const render = () => {
      tabs.replaceChildren(...TABS.map(([k, t]) => el('button', { type: 'button', role: 'tab', 'data-tab': k,
        'aria-selected': String(tab === k), class: tab === k ? 'on' : '',
        onclick: () => { tab = k; try { sessionStorage.setItem('dt-conn-tab', k); } catch (e) { /* חסום */ } render(); } }, t)));
      ({ sources, keys, docs })[tab](body);
    };
    show(el('div', { class: 'screen' }, tabs, body));
    render();
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
        ep('POST', 'ext_issue', 'הנפקת מסמך: ערכים, ref שלכם (בקשה חוזרת עם אותו ref לא יוצרת כפול), ממלאים — וקבלת הקישורים'),
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
