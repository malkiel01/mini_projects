// קול מרוקו — P1: 👥 אנשים (בעל העמוד מוסיף עוזרים בעצמו), ⚙️ בעל האתר (הכול במקום אחד), הפעמון, ו"לקריאה בלבד" לעורך תוכן.
// משתמש בעזרים של app.js (h, api, toast, sheet, when, hue, initials, state). כל טקסט מהשרת — כטקסט (textContent), אף פעם לא כ-HTML.
'use strict';

const People = (() => {
  const rank = r => (state.rank || {})[r] || 0;
  const atLeast = min => rank(state.user && state.user.role) >= rank(min);
  const roleName = r => (r && state.roles[r]) || 'בלי תפקיד';
  const RO_TABS = ['studio', 'code', 'project', 'editor', 'publish'];
  const RO_TEXT = 'התפקיד שלך: עורך תוכן — מילים, הקלטות ופניות. לשינוי עיצוב ופרסום — פנה לבעל העמוד';

  // ── לקריאה בלבד (עורך תוכן) ─────────────────────────────
  /** בלשוניות של העיצוב, הקוד, הקבצים והפרסום — פס "לקריאה בלבד". השרת דוחה כל כתיבה בכל מקרה. */
  function readOnly(tabId) {
    const bar = $('#ro-note');
    const on = state.user.role === 'content_editor' && RO_TABS.includes(tabId);
    document.body.classList.toggle('ro', on);
    bar.hidden = !on;
    if (on) bar.replaceChildren(h('b', {}, '👁️ לקריאה בלבד. '), RO_TEXT);
  }

  // ── 👥 אנשים ─────────────────────────────────────────────
  let host = null;

  async function view(el) {
    host = el;
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    try { render(await api('people')); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  async function act(action, body, keepOpen) {
    try {
      const d = await api(action, body);
      render(d);
      if (d.message) toast(d.message);
      return d;
    } catch (e) { toast(e.message, true); return null; }
  }

  function roleSelect(list, value) {
    return h('select', { name: 'role', 'aria-label': 'תפקיד' }, list.map(k => h('option', { value: k, selected: k === value }, state.roles[k])));
  }

  const inviteChip = p => ({
    invited: ['warn', 'הוזמן — עוד לא נכנס'], pending: ['bad', 'ממתינה — ' + (p.invite_note || 'הפלטפורמה לא ענתה')],
    blocked: ['bad', 'חסום בפלטפורמה'], granted: ['ok', 'ניתנה גישה'],
  })[p.invite];

  function render(d) {
    const email = h('input', { type: 'email', name: 'email', dir: 'ltr', inputmode: 'email', placeholder: 'מייל', 'aria-label': 'מייל', required: true, autocomplete: 'off' });
    const name = h('input', { type: 'text', name: 'name', placeholder: 'שם (לא חובה)', 'aria-label': 'שם', maxlength: 80 });
    const role = roleSelect(d.assignable, 'content_editor');
    const form = h('form', {
      class: 'add-form people-add', onsubmit: async e => {
        e.preventDefault();
        const r = await act('person_invite', { email: email.value, name: name.value, role: role.value });
        if (r) { email.value = ''; name.value = ''; }
      },
    }, email, name, role, h('button', { class: 'btn primary', type: 'submit' }, h('span', { 'aria-hidden': 'true' }, '+'), ' הוסף עוזר'));

    const requests = d.requests.length ? [
      h('h2', { class: 'sec-title' }, `ביקשו גישה · ${d.requests.length}`),
      h('ul', { class: 'list' }, d.requests.map(r => h('li', { class: 'card', tabindex: 0, role: 'button', onclick: () => requestSheet(r, d) },
        h('span', { class: 'avatar', style: `--h:${hue(r.email || r.name || '?')}`, 'aria-hidden': 'true' }, initials(r.name || r.email || '?')),
        h('div', { class: 'body' },
          h('div', { class: 'l1' }, h('span', {}, r.name || 'בלי שם'), h('span', { class: 't' }, when(r.at))),
          h('div', { class: 'l2', dir: 'auto' }, r.email || '')),
        h('span', { class: 'chip warn' }, 'ממתין')))),
    ] : [];

    host.replaceChildren(
      h('p', { class: 'hint' }, 'מי עובד על העמוד, ובאיזה תפקיד. "הוסף עוזר" שולח לו הזמנה במייל — בלי לבקש מבעל האתר. עורך תוכן: מילים, הקלטות ופניות; עורך: גם עיצוב וקוד; מפרסם: גם שולח לאתר.'),
      form, ...requests,
      h('h2', { class: 'sec-title' }, `אנשים · ${d.people.length}`),
      h('ul', { class: 'list', id: 'people-list' }, d.people.map(p => {
        const ic = inviteChip(p);
        return h('li', { class: 'card', tabindex: 0, role: 'button', 'data-email': p.email || '', onclick: () => personSheet(p, d) },
          h('span', { class: 'avatar', style: `--h:${hue(p.email || p.name || '?')}`, 'aria-hidden': 'true' }, initials(p.name || p.email || '?')),
          h('div', { class: 'body' },
            h('div', { class: 'l1' }, h('span', {}, p.name || p.email || '—')),
            h('div', { class: 'l2' }, p.joined ? (p.last_seen ? `נראה לאחרונה ${when(p.last_seen)}` : 'עוד לא נכנס') : 'עוד לא נכנס',
              p.invited_by ? ` · הוזמן בידי ${p.invited_by}` : '')),
          ic && !p.last_seen ? h('span', { class: `chip ${ic[0]}` }, ic[1]) : null,
          h('span', { class: `chip role ${p.role === 'publisher' || p.role === 'page_owner' ? 'ok' : ''}` }, roleName(p.role)));
      })),
      ...(d.provision && d.provision.error ? [h('p', { class: 'hint' }, `הקריאה האחרונה לפלטפורמה (${when(d.provision.at)}): ${d.provision.error}`)] : []));   // replaceChildren(null) כותב "null"
    host.querySelectorAll('.card[role=button]').forEach(c => c.addEventListener('keydown', e => { if (e.key === 'Enter') c.click(); }));
  }

  function requestSheet(r, d) {
    const role = roleSelect(d.assignable, 'content_editor');
    const dlg = sheet(r.name || r.email || 'בקשת גישה',
      h('p', { class: 'muted', dir: 'auto' }, r.email || ''),
      h('label', { class: 'f' }, h('span', {}, 'תפקיד'), role),
      h('div', { class: 'row-btns' },
        h('button', { type: 'button', class: 'btn primary', onclick: async () => { if (await act('person_invite', { id: r.id, role: role.value })) dlg.close(); } }, '✓ הוספה'),
        h('button', { type: 'button', class: 'btn ghost danger', onclick: async () => { if (await act('request_dismiss', { id: r.id })) dlg.close(); } }, '✕ התעלמות')));
  }

  function personSheet(p, d) {
    const fixed = p.role === 'site_owner';
    const list = d.assignable.includes(p.role) || fixed ? d.assignable : [p.role, ...d.assignable];
    const role = roleSelect(list, p.role);
    const quota = h('input', { type: 'number', min: 0, step: 10000, dir: 'ltr', value: p.ai_quota ?? '', placeholder: 'ברירת המחדל' });
    const ic = inviteChip(p);
    const dlg = sheet(p.name || p.email,
      h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'מייל'), h('dd', { dir: 'ltr', class: 'one' }, p.email || '—')),
        h('div', {}, h('dt', {}, 'נוסף'), h('dd', {}, when(p.created_at))),
        h('div', {}, h('dt', {}, 'נראה לאחרונה'), h('dd', {}, p.last_seen ? when(p.last_seen) : '—')),
        h('div', {}, h('dt', {}, 'הוזמן בידי'), h('dd', {}, p.invited_by || '—'))),
      ic ? h('p', { class: 'hint' }, 'ההזמנה: ' + ic[1]) : null,
      fixed ? h('p', { class: 'hint' }, 'בעל האתר נקבע בכלים הדיגיטליים (מנהל), לא כאן.') : h('label', { class: 'f' }, h('span', {}, 'תפקיד'), role),
      atLeast('site_owner') ? h('label', { class: 'f' }, h('span', {}, '✨ מכסת בינה לחודש (טוקנים) — ריק: ברירת המחדל'), quota) : null,
      fixed ? null : h('div', { class: 'row-btns wrap' },
        h('button', { type: 'button', class: 'btn primary', onclick: async () => {
          if (atLeast('site_owner')) {
            try { await api('ai_quota_set', { member: p.id, tokens: +quota.value || 0, inherit: quota.value === '' }); } catch (e) { toast(e.message, true); return; }
          }
          if (role.value === p.role) { dlg.close(); view(host); return; }
          if (await act('person_role', { id: p.id, role: role.value })) dlg.close();
        } }, 'שמירה'),
        p.invite === 'pending' ? h('button', { type: 'button', class: 'btn ghost', onclick: async () => { if (await act('person_retry', { id: p.id })) dlg.close(); } }, '↻ נסה שוב') : null,
        h('button', {
          type: 'button', class: 'btn ghost danger', onclick: async () => {
            if (confirm(`להסיר את ${p.name || p.email} מהכלי? הוא ייזרק מיד, וההרשאה שלו בכלים הדיגיטליים לאפליקציה הזו תבוטל.`) && await act('person_remove', { id: p.id })) dlg.close();
          },
        }, '🗑️ הסרה')));
    return dlg;
  }

  // ── ⚙️ בעל האתר ──────────────────────────────────────────
  async function owner(el) {
    el.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let o;
    try { o = (await api('owner_settings')).owner; } catch (e) { el.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const groups = {};
    for (const r of o.rows) (groups[r.group] = groups[r.group] || []).push(r);
    const list = h('div', { class: 'owner-rows', id: 'owner-rows' }, Object.entries(groups).map(([g, rows]) => h('section', { class: 'summary' },
      h('div', { class: 'line' }, h('b', {}, g)),
      h('ul', { class: 'owner-list' }, rows.map(r => h('li', {},
        h('span', { class: 'k' }, r.label + ': '), h('b', { dir: 'auto' }, r.value),
        o.can_edit ? h('span', { class: 'hint' }, ' — ' + r.where) : h('span', { class: 'muted' }, ' — נקבע בידי בעל האתר')))))));
    const parts = [
      h('p', { class: 'hint' }, o.can_edit
        ? 'כל מה ששמור לבעל האתר — במקום אחד. בעל העמוד רואה את הרשימה הזו לקריאה, במילים.'
        : 'מה שנקבע בידי בעל האתר, ומשפיע על העמוד שלך. לשינוי — פנה לבעל האתר.'),
      list,
    ];
    if (o.can_edit) {
      const sw = h('input', { type: 'checkbox', id: 'owner-approval', checked: o.approval });
      sw.addEventListener('change', async () => {
        try { await api('owner_set', { approval: sw.checked }); toast(sw.checked ? 'מעכשיו כל פרסום מחכה לאישורך' : 'פרסום — מיד, ואת/ה מקבל/ת הודעה'); owner(el); }
        catch (e) { sw.checked = !sw.checked; toast(e.message, true); }
      });
      parts.push(
        h('h2', { class: 'sec-title' }, '🚦 פרסום'),
        h('label', { class: 'f-check' }, sw, ' כל פרסום מחכה לאישורי (כבוי: מתפרסם מיד, ואני מקבל/ת הודעה)'),
        o.pending.length ? h('ul', { class: 'list', id: 'owner-pending' }, o.pending.map(p => h('li', { class: 'card' },
          h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, `${p.by_name} · בנייה ${p.id}`), h('span', { class: 't' }, when(p.at)))),
          h('div', { class: 'row-btns' },
            h('button', { type: 'button', class: 'btn primary', onclick: async () => {
              try { await api('publish_decide', { id: p.id, approve: true }); toast('אושר ונשלח לאתר'); owner(el); } catch (e) { toast(e.message, true); }
            } }, '✓ אישור'),
            h('button', { type: 'button', class: 'btn ghost danger', onclick: async () => {
              const why = prompt('למה? (נשלח למבקש)');
              if (!why) return;
              try { await api('publish_decide', { id: p.id, reason: why }); toast('נדחה'); owner(el); } catch (e) { toast(e.message, true); }
            } }, '✕ דחייה'))))) : h('p', { class: 'hint' }, 'אין פרסומים שממתינים.'),
        h('h2', { class: 'sec-title' }, '🛠️ מסך המפתח ו-DevLoop'),
        h('p', {}, h('a', { href: '#/dev' }, 'פתיחת מסך המפתח'), ' · ', h('a', { href: '#/dev/tasks' }, 'המטלות')),
        h('h2', { class: 'sec-title' }, '👥 בעל העמוד'),
        h('p', { class: 'hint' }, 'מינוי והסרה של בעל העמוד — ב-', h('a', { href: '#/members' }, '👥 אנשים'), ' (המינוי הראשון — רק בעל האתר).'),
        h('h2', { class: 'sec-title' }, '🪟 אופן ההצבה'),
        mountBlock(),
        pluginBlock());
      const rest = h('div', { id: 'owner-connection' });
      parts.push(rest);
      el.replaceChildren(...parts);
      Publish.settings(rest);                        // החיבור לאתר, הייבוא, ההקלטות, הבינה — המסכים הקיימים
      return;
    }
    el.replaceChildren(...parts);
  }

  // אופן ההצבה באתר (M4-BC; עבר מ-publish.js ב-P1): עמוד מלא / חלון בתוך האתר / פס האתר. פעולה חתומה לתוסף — מיידי, בלי פרסום מחדש
  function mountBlock() {
    const box = h('div', { class: 'summary', id: 'mount-block' }, h('p', { class: 'hint' }, 'טוען…'));
    api('mount').then(st => {
      let cur = st.mount;
      const status = h('p', { class: 'hint', role: 'status' });
      const rows = Object.entries(st.options).map(([id, o]) => h('label', { class: 'mount-opt', style: 'display:block;margin:6px 0' },
        h('input', { type: 'radio', name: 'kol-mount', value: id, checked: id === cur }), ' ', h('b', {}, o.name), ' — ', o.text,
        o.loses.length ? h('ul', { class: 'hint', style: 'margin:4px 0 0' }, ...o.loses.map(t => h('li', {}, t))) : ''));
      const save = h('button', { type: 'button', class: 'btn primary', onclick: async () => {
        const id = (box.querySelector('input[name=kol-mount]:checked') || {}).value;
        if (!id || id === cur) { toast('לא השתנה'); return; }
        const o = st.options[id];
        if (!confirm('לשנות את אופן ההצבה באתר ל"' + o.name + '"? השינוי מיידי, בלי פרסום מחדש. אפשר לחזור בכל רגע.' + (o.loses.length ? '\n\nמה העמוד מפסיד:\n• ' + o.loses.join('\n• ') : ''))) return;
        save.disabled = true; status.textContent = 'שולח לאתר…';
        try {
          const r = await api('mount_set', { mount: id });
          // M7: בערוץ המשיכה — בתור; האופן משתנה כאן רק כשהאתר מדווח שהחיל
          if (r.queued) { status.textContent = `⏳ בתור (#${r.seq}): ${st.options[id].name} — יוחל כשהאתר ימשוך. באתר עכשיו: ${st.options[r.mount].name}`; toast('בתור — ממתין למשיכה'); }
          else { cur = r.mount; status.textContent = '✓ באתר עכשיו: ' + st.options[cur].name; toast('נשמר באתר'); }
        }
        catch (e) { status.replaceChildren(h('span', { class: 'error' }, e.message)); }
        save.disabled = false;
      } }, 'שמירה באתר');
      box.replaceChildren(h('div', { class: 'line' }, h('b', {}, '🪟 איך העמוד מוצג באתר'),
        h('span', { class: 'muted' }, '— בכל אופן הקוד של העמוד רץ מבודד; החבילה עצמה לא משתנה')), ...rows, h('div', { class: 'row-btns' }, save), status);
    }).catch(e => box.replaceChildren(h('p', { class: 'error' }, e.message)));
    return box;
  }

  // T1: התוסף לוורדפרס כ-ZIP, מהשרת (wordpress-plugin/kol-maroko/) — להעלאה ב"תוספים ← הוספת תוסף ← העלאה". בעל האתר בלבד
  function pluginBlock() {
    const status = h('p', { class: 'hint', role: 'status' });
    const btn = h('button', { type: 'button', class: 'btn', 'data-plugin-zip': '', onclick: async () => {
      btn.disabled = true; status.textContent = 'מכין את הקובץ…';
      try {
        const res = await fetch('api.php?action=plugin_zip');
        if (!res.ok) { let m = 'ההורדה נכשלה'; try { m = (await res.json()).error || m; } catch { /* לא JSON */ } throw new Error(m); }
        const ver = res.headers.get('X-Kol-Plugin-Version') || '';
        const url = URL.createObjectURL(new Blob([await res.arrayBuffer()], { type: 'application/zip' }));
        const a = h('a', { href: url, download: 'kol-maroko' + (ver ? '-' + ver : '') + '.zip' });
        document.body.append(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 30000);
        status.textContent = '✓ ירד' + (ver ? ' — גרסה ' + ver : '') + '. בוורדפרס: תוספים ← הוספת תוסף ← העלאת תוסף.';
      } catch (e) { status.replaceChildren(h('span', { class: 'error' }, e.message)); }
      btn.disabled = false;
    } }, '⬇️ הורד את התוסף (zip)');
    return h('div', { class: 'summary', id: 'plugin-block' },
      h('div', { class: 'line' }, h('b', {}, '🧩 התוסף לוורדפרס'), h('span', { class: 'muted' }, '— הגרסה שבשרת, להתקנה או לעדכון באתר')),
      h('div', { class: 'row-btns' }, btn), status);
  }

  // ── 🔔 הפעמון ────────────────────────────────────────────
  let bellBtn = null;

  async function bellLoad() {
    try {
      const d = await api('notices');
      bellBtn.querySelector('.n').textContent = d.unread ? String(d.unread) : '';
      bellBtn.setAttribute('aria-label', d.unread ? `הודעות: ${d.unread} חדשות` : 'הודעות');
      return d;
    } catch (e) { return null; }
  }

  async function bellOpen() {
    const d = await bellLoad();
    if (!d) { toast('ההודעות לא נטענו', true); return; }
    const dlg = sheet('🔔 הודעות',
      d.notices.length
        ? h('ul', { class: 'list', id: 'bell-list' }, d.notices.map(n => h('li', { class: 'card' },
          h('div', { class: 'body' },
            h('div', { class: 'l1' }, h('span', { dir: 'auto' }, n.text), h('span', { class: 't' }, when(n.at))),
            n.by_name ? h('div', { class: 'l2' }, n.by_name) : null),
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סמן כנקרא', onclick: async ev => {
            try { await api('notice_read', { id: n.id }); ev.target.closest('li').remove(); bellLoad(); } catch (e) { toast(e.message, true); }
          } }, '✓'))))
        : h('p', { class: 'empty' }, 'אין הודעות חדשות.'),
      d.notices.length ? h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn', onclick: async () => {
        try { await api('notices_read', {}); bellLoad(); dlg.close(); } catch (e) { toast(e.message, true); }
      } }, '✓ סמן הכול כנקרא')) : null);
  }

  function bell() {
    if (bellBtn || !state.user.role) return;
    bellBtn = h('button', { type: 'button', class: 'icon-btn bell', id: 'bell', 'aria-label': 'הודעות', onclick: bellOpen },
      h('span', { 'aria-hidden': 'true' }, '🔔'), h('span', { class: 'n', 'aria-hidden': 'true' }));
    $('#top-end').before(bellBtn);
    bellLoad();
    setInterval(() => { if (!document.hidden) bellLoad(); }, 120000);
  }

  return { view, owner, mountBlock, bell, readOnly, atLeast };
})();
