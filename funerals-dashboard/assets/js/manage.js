/* פרוסה 12 — הממשק מעל פרוסות 10 ו-11.
 * - הגדרות: לשוניות "בדיקות", "תפקידים", "מה למדתי" (כללי הבינה), נוספות לחלון ההגדרות הקיים.
 * - המסך הראשי: "שאלות מהבינה" (תשובה בלחיצה אחת) ו"המשימות שלי".
 * - בתוך תיק: "משימות" — רשימה, הוספה (tasks.manage), סימון "בוצעה".
 * חיבור ל-app.js: window.FD (request, toast, can, openCase, me) והאירועים fd:ready / fd:case. בלי כתיבה ישירה לתיק. */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const FD = () => window.FD;
  const api = (action, data = null, params = {}) => FD().request(action, data === null ? 'GET' : 'POST', data, params);
  const can = p => FD() && FD().can(p);
  const isDev = () => { const m = FD() && FD().me && FD().me(); return !!(m && m.dev); };
  const toast = (t, err) => FD().toast(t, err);
  function node(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }
  function btn(cls, text, label) { const b = node('button', cls, text); b.type = 'button'; if (label) { b.setAttribute('aria-label', label); b.title = label; } return b; }
  function lab(text, el, wide) { const l = node('label', 'f' + (wide ? ' wide' : '')); l.append(node('span', '', text), el); return l; }
  function sel(options, value) {
    const s = node('select');
    for (const [v, t] of options) { const o = node('option', '', t); o.value = v; o.selected = String(v) === String(value ?? ''); s.append(o); }
    return s;
  }
  function row(title, sub, chips = [], onOpen = null) {
    const li = node('li', 'card'); if (!onOpen) li.style.cursor = 'default';
    const b = node('div', 'body'); const l1 = node('div', 'l1'); l1.append(node('span', '', title));
    chips.filter(Boolean).forEach(([t, c]) => l1.append(node('span', 'chip ' + (c || ''), t)));
    b.append(l1); if (sub) b.append(node('div', 'l2', sub));
    li.append(b);
    if (onOpen) { li.tabIndex = 0; li.setAttribute('role', 'button'); li.addEventListener('click', e => { if (!e.target.closest('button')) onOpen(); });
      li.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target === li) onOpen(); }); }
    return li;
  }
  const dm = d => d ? d.slice(8, 10) + '.' + d.slice(5, 7) : '';

  /* ===== גיליון עריכה אחד לכל המסכים ===== */
  const sheet = node('dialog'); sheet.id = 'mgSheet'; sheet.setAttribute('aria-labelledby', 'mgTitle');
  sheet.innerHTML = `<form method="dialog" novalidate><header class="sheet-head">
    <button type="button" class="icon-btn" data-close aria-label="סגירה">✕</button><h2 id="mgTitle"></h2>
    <button type="button" class="btn primary" id="mgSave">שמירה</button></header>
    <div class="sheet-body" id="mgBody"></div><p class="error" id="mgErr" role="alert" hidden></p></form>`;
  document.body.append(sheet);
  sheet.querySelector('[data-close]').addEventListener('click', () => sheet.close());
  let onSave = null;
  $('mgSave').addEventListener('click', async () => {
    if (!onSave) return;
    $('mgSave').disabled = true; $('mgErr').hidden = true;
    try { await onSave(); sheet.close(); } catch (e) { $('mgErr').textContent = e.message; $('mgErr').hidden = false; }
    $('mgSave').disabled = false;
  });
  function openSheet(title, body, save, saveText = 'שמירה') {
    $('mgTitle').textContent = title; $('mgBody').replaceChildren(...body); $('mgErr').hidden = true;
    $('mgSave').textContent = saveText; $('mgSave').hidden = !save; onSave = save; sheet.showModal();
  }

  /* ===== לשוניות נוספות בחלון ההגדרות ===== */
  const tabs = document.querySelector('#settingsDialog .seg-tabs');
  const body = document.querySelector('#settingsDialog .sheet-body');
  const extra = {};
  for (const [key, name] of [['checks', 'בדיקות'], ['roles', 'תפקידים'], ['learned', 'מה למדתי']]) {
    const t = btn('', name); t.setAttribute('role', 'tab'); t.dataset.mtab = key; t.hidden = true; t.setAttribute('aria-selected', 'false');
    const s = node('section'); s.id = 'sM' + key; s.hidden = true;
    tabs.append(t); body.append(s); extra[key] = {t, s};
    t.addEventListener('click', () => showTab(key));
  }
  tabs.classList.add('wrap4');
  function showTab(key) {
    body.querySelectorAll(':scope > section').forEach(s => { s.hidden = s !== extra[key].s; });
    tabs.querySelectorAll('button').forEach(b => b.setAttribute('aria-selected', String(b === extra[key].t)));
    ({checks: paintChecks, roles: paintRoles, learned: paintLearned})[key](extra[key].s);
  }
  // לחיצה על לשונית אחרת (של app.js או של ai.js) — מסתירה את שלנו
  tabs.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b || b.dataset.mtab) return;
    Object.values(extra).forEach(x => { x.s.hidden = true; x.t.setAttribute('aria-selected', 'false'); });
  }, true);
  $('settingsBtn') && $('settingsBtn').addEventListener('click', () => {
    Object.values(extra).forEach(x => { x.s.hidden = true; x.t.setAttribute('aria-selected', 'false'); });
    extra.checks.t.hidden = !(can('settings.manage') || isDev());
    extra.roles.t.hidden = !can('members.manage');
    extra.learned.t.hidden = !(can('case.view') || can('members.manage'));
  });

  /* ----- בדיקות ----- */
  let roleCache = null;
  async function roles() {
    if (roleCache) return roleCache;
    try { roleCache = (await api('roles')).roles; } catch (_) { roleCache = []; }
    return roleCache;
  }
  async function paintChecks(sec, data) {
    if (!data) { sec.replaceChildren(node('p', 'muted', 'טוען…')); try { data = await api('checks'); } catch (e) { sec.replaceChildren(node('p', 'error', e.message)); return; } }
    const rs = await roles();
    const roleName = id => (rs.find(r => r.id === id) || {}).name || '';
    let global = false;
    const draw = () => {
      const list = (global ? data.global : data.checks) || [];
      const active = list.filter(d => !d.removed), removed = list.filter(d => d.removed);
      const head = node('div', 'group');
      if (data.can_edit_global) {
        const seg = node('div', 'seg-tabs'); seg.setAttribute('role', 'tablist'); seg.setAttribute('aria-label', 'איזה הגדרות');
        for (const [g, t] of [[false, 'של הארגון'], [true, 'כללית (לכל הארגונים)']]) {
          const b = btn('', t); b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', String(g === global));
          b.addEventListener('click', () => { global = g; draw(); }); seg.append(b);
        }
        head.append(seg);
      }
      head.append(node('p', 'hint', global ? 'הבדיקות שכל ארגון מקבל. ארגון יכול לשנות לעצמו בדיקה — השינוי שלו גובר.'
        : 'מה צריך להשלים בכל תיק. בדיקה חדשה תופיע בכל התיקים כ"חסר"; הסרה לא מוחקת ערכים מהתיקים.'));
      const add = btn('btn primary', '+ בדיקה'); add.addEventListener('click', () => editCheck(null));
      const ar = node('div', 'row-btns'); ar.append(add); add.hidden = global ? !data.can_edit_global : !data.can_edit; head.append(ar);
      const ul = node('ul', 'list'); ul.setAttribute('aria-label', 'הבדיקות');
      active.forEach((d, i) => {
        const sub = [data.types[d.type] + (d.type === 'value' && d.value_kind ? ' · ' + (data.value_kinds[d.value_kind] || '') : ''),
          d.role ? 'אחראי: ' + roleName(d.role) : '', d.cond ? 'רק כש"' + ((list.find(x => x.key === d.cond.check) || {}).name || '') + '"' : ''].filter(Boolean).join(' · ');
        const li = row(d.name, sub, [d.critical && ['קריטי', 'bad'], !global && d.scope === 'org' && ['שונה בארגון', 'warn']], () => editCheck(d));
        const up = btn('icon-btn', '▲', 'להזיז למעלה: ' + d.name); up.disabled = i === 0;
        const dn = btn('icon-btn', '▼', 'להזיז למטה: ' + d.name); dn.disabled = i === active.length - 1;
        const move = async dir => {
          const keys = active.map(x => x.key); const j = i + dir; [keys[i], keys[j]] = [keys[j], keys[i]];
          try { data = await api('check_order', {keys, scope: global ? 'global' : 'org'}); draw(); } catch (e) { toast(e.message, true); }
        };
        up.addEventListener('click', () => move(-1)); dn.addEventListener('click', () => move(1));
        if (global ? data.can_edit_global : data.can_edit) li.append(up, dn);
        ul.append(li);
      });
      if (!active.length) ul.append(node('li', 'empty', 'אין בדיקות.'));
      const parts = [head, ul];
      if (removed.length) {
        parts.push(node('h3', 'sec-title', 'הוסרו'));
        const ru = node('ul', 'list');
        removed.forEach(d => { const li = row(d.name, 'הוסרה — הערכים נשמרו בתיקים'); const b = btn('btn ghost sm', 'החזרה');
          b.addEventListener('click', async () => { try { data = await api('check_save', {key: d.key, name: d.name, scope: global ? 'global' : 'org'}); draw(); } catch (e) { toast(e.message, true); } });
          li.append(b); ru.append(li); });
        parts.push(ru);
      }
      if (!global && data.can_edit) {
        const g = node('div', 'group'); g.append(node('h3', '', 'דחוף'));
        const days = node('input'); days.type = 'number'; days.min = '0'; days.max = '365'; days.inputMode = 'numeric'; days.value = data.urgent_days;
        const sv = btn('btn', 'שמירה');
        const f = node('div', 'fields two'); f.append(lab('תיק דחוף אחרי (ימים מהלוויה)', days)); const c = node('div', 'f'); c.append(node('span', '', ' '), sv); f.append(c);
        g.append(f, node('p', 'hint', 'תיק נחשב דחוף כשחסר בו מסמך קריטי, או כשעברו הימים האלה מהלוויה ועוד יש בו חסר.'));
        sv.addEventListener('click', async () => { try { data = await api('urgent_save', {days: Number(days.value)}); toast('נשמר'); draw(); } catch (e) { toast(e.message, true); } });
        parts.push(g);
      }
      sec.replaceChildren(...parts);
    };
    const editCheck = d => {
      const list = (global ? data.global : data.checks) || [];
      const editable = global ? data.can_edit_global : data.can_edit;
      const name = node('input'); name.maxLength = 80; name.value = d ? d.name : '';
      const type = sel(Object.entries(data.types), d ? d.type : 'document');
      const vk = sel(Object.entries(data.value_kinds), d ? d.value_kind || 'text' : 'text');
      const vkL = lab('סוג הערך', vk);
      const role = sel([['', 'בלי אחראי'], ...rs.map(r => [r.id, r.name])], d ? d.role : '');
      const cond = sel([['', 'תמיד'], ...list.filter(x => !x.removed && (!d || x.key !== d.key)).map(x => [x.key, 'רק כש"' + x.name + '" נמצא'])], d && d.cond ? d.cond.check : '');
      const crit = node('input'); crit.type = 'checkbox'; crit.checked = !!(d && d.critical);
      const cl = node('label', 'ai-check'); cl.append(crit, node('span', '', 'קריטי — חסר בו הופך את התיק לדחוף'));
      const kinds = node('fieldset', 'kinds'); kinds.append(node('legend', '', 'מסמכים שעונים על הבדיקה'));
      const opts = data.doc_kind_options || {};
      const have = new Set(d ? d.doc_kinds : []);
      Object.entries(opts).forEach(([k, t]) => { const c = node('input'); c.type = 'checkbox'; c.value = k; c.checked = have.has(k);
        const l = node('label', 'ai-check'); l.append(c, node('span', '', t)); kinds.append(l); });
      // ביקורת 12 ⚪6: בלי סוגי מסמכים (הבינה לא נטענה בשרת) — הודעה במקום בורר ריק
      const noKinds = node('p', 'hint', 'רשימת סוגי המסמכים לא זמינה כרגע, ולכן אי אפשר לבחור מסמכים שעונים על הבדיקה. אפשר לשמור, ולבחור אחר כך.');
      const sync = () => { vkL.hidden = type.value !== 'value'; kinds.hidden = type.value !== 'document' || !Object.keys(opts).length;
        noKinds.hidden = type.value !== 'document' || !!Object.keys(opts).length; };
      type.addEventListener('change', sync); sync();
      const f = node('div', 'fields two');
      f.append(lab('שם הבדיקה', name, true), lab('סוג', type), vkL, lab('תפקיד אחראי', role), lab('מתי נדרשת', cond));
      const parts = [f, kinds, noKinds, cl];
      if (d && editable) {
        const rr = node('div', 'row-btns');
        const rm = btn('btn ghost danger', '🗑️ הסרה');
        rm.addEventListener('click', async () => {
          if (!confirm('להסיר את "' + d.name + '"? הערכים שכבר בתיקים נשמרים, ואפשר להחזיר.')) return;
          try { data = await api('check_remove', {key: d.key, scope: global ? 'global' : 'org'}); sheet.close(); draw(); } catch (e) { toast(e.message, true); }
        });
        rr.append(rm);
        if (!global && d.scope === 'org' && (data.global || []).some(x => x.key === d.key) !== false) {
          const rs2 = btn('btn ghost', '↺ חזרה לכללית');
          rs2.addEventListener('click', async () => { try { data = await api('check_reset', {key: d.key}); sheet.close(); draw(); } catch (e) { toast(e.message, true); } });
          rr.append(rs2);
        }
        parts.push(rr);
      }
      openSheet(d ? d.name : 'בדיקה חדשה', parts, editable ? async () => {
        const r = await api('check_save', {key: d ? d.key : '', name: name.value, type: type.value, value_kind: type.value === 'value' ? vk.value : '',
          doc_kinds: [...kinds.querySelectorAll('input:checked')].map(c => c.value), role: role.value ? Number(role.value) : null,
          cond: cond.value ? {check: cond.value} : null, critical: crit.checked, scope: global ? 'global' : 'org'});
        data = r; draw(); toast('הבדיקה נשמרה');
      } : null);
    };
    draw();
  }

  /* ----- תפקידים ----- */
  async function paintRoles(sec) {
    sec.replaceChildren(node('p', 'muted', 'טוען…'));
    let data; try { data = await api('roles'); } catch (e) { sec.replaceChildren(node('p', 'error', e.message)); return; }
    roleCache = data.roles;
    const draw = () => {
      const head = node('div', 'row-btns'); const add = btn('btn primary', '+ תפקיד'); add.addEventListener('click', () => edit(null)); head.append(add);
      const ul = node('ul', 'list'); ul.setAttribute('aria-label', 'התפקידים');
      data.roles.forEach(r => {
        const n = r.perms.length, only = r.perms.includes('case.view_assigned') && !r.perms.includes('case.view');
        ul.append(row(r.name, `${r.members ?? 0} חברים · ${n} הרשאות` + (r.task_types.length ? ' · ' + r.task_types.join(', ') : ''),
          [r.builtin && ['מובנה', ''], only && ['רק שהוקצו', 'warn']], () => edit(r)));
      });
      sec.replaceChildren(node('p', 'hint', 'מה כל תפקיד רשאי לעשות. שינוי חל מיד על כל מי שבתפקיד.'), head, ul);
    };
    const edit = r => {
      const name = node('input'); name.maxLength = 60; name.value = r ? r.name : '';
      const f = node('div', 'fields'); f.append(lab('שם התפקיד', name, true));
      const ps = node('fieldset', 'kinds'); ps.append(node('legend', '', 'הרשאות'));
      const have = new Set(r ? r.perms : ['case.view_assigned', 'case.track']);
      Object.entries(data.perms).forEach(([k, t]) => { const c = node('input'); c.type = 'checkbox'; c.value = k; c.checked = have.has(k);
        const l = node('label', 'ai-check'); l.append(c, node('span', '', t)); ps.append(l); });
      const types = node('input'); types.value = r ? r.task_types.join(', ') : ''; types.placeholder = 'למשל: צילום מצבה, מיקום קבר';
      const tf = node('div', 'fields'); tf.append(lab('סוגי משימות (מופרדים בפסיק)', types, true));
      const parts = [f, ps, tf];
      if (r && !r.builtin) {
        const ar = btn('btn ghost danger', '🗄️ העברה לארכיון');
        ar.addEventListener('click', async () => {
          if (!confirm('להעביר את "' + r.name + '" לארכיון?')) return;
          try { data = await api('role_archive', {id: r.id}); roleCache = data.roles; sheet.close(); draw(); } catch (e) { toast(e.message, true); }
        });
        const rr = node('div', 'row-btns'); rr.append(ar); parts.push(rr);
      }
      openSheet(r ? r.name : 'תפקיד חדש', parts, async () => {
        const out = await api('role_save', {id: r ? r.id : undefined, name: name.value.trim(), perms: [...ps.querySelectorAll('input:checked')].map(c => c.value),
          task_types: types.value.split(/[,،]/).map(x => x.trim()).filter(Boolean)});
        data = out; roleCache = out.roles; draw(); toast('התפקיד נשמר');
      });
    };
    draw();
  }

  /* ----- מה למדתי (כללי הבינה — פרוסה 10) ----- */
  async function paintLearned(sec) {
    sec.replaceChildren(node('p', 'muted', 'טוען…'));
    let data; try { data = await api('link_rules'); } catch (e) { sec.replaceChildren(node('p', 'error', e.message)); return; }
    const st = data.stats || {};
    const sum = node('div', 'summary');
    const l = node('div', 'line'); l.append(node('b', '', String(st.week ?? 0)), node('span', 'muted', ' שאלות השבוע'),
      node('span', 'muted', ` · שבוע קודם ${st.prev_week ?? 0} · פתוחות ${st.open ?? 0} · ${st.linked ?? 0} תיקיות מקושרות`));
    sum.append(l);
    const ul = node('ul', 'list'); ul.setAttribute('aria-label', 'מה למדתי');
    data.rules.forEach(r => {
      const mine = r.layer === 'shared' ? data.can_shared : data.can_manage;
      const li = row(r.text, `${r.kind_label} · שימושים: ${r.uses}` + (r.layer === 'shared' ? ' · משותף לכל הארגונים' : ''),
        [r.auto && ['מאשר לבד', 'ok']], mine ? () => edit(r) : null);
      if (mine) { const del = btn('icon-btn', '🗑️', 'מחיקת הכלל');
        del.addEventListener('click', async () => { if (!confirm('למחוק את הכלל? הבינה תשאל שוב במקרה דומה.')) return;
          try { await api('link_rule_delete', {id: r.id}); paintLearned(sec); } catch (e) { toast(e.message, true); } });
        li.append(del); }
      ul.append(li);
    });
    if (!data.rules.length) ul.append(node('li', 'empty', 'עוד לא נלמד כלום. כל תשובה לשאלה מהבינה מוסיפה כאן כלל.'));
    sec.replaceChildren(sum, ul);
    const edit = r => {
      const value = node('input'); value.maxLength = 120; value.value = r.value;
      const f = node('div', 'fields'); f.append(lab('הכלל', Object.assign(node('input'), {value: r.pattern, readOnly: true}), true), lab('התשובה', value, true));
      const auto = node('input'); auto.type = 'checkbox'; auto.checked = r.auto;
      const al = node('label', 'ai-check'); al.append(auto, node('span', '', 'מאשר לבד — לשייך בלי לשאול'));
      openSheet('תיקון כלל', r.kind === 'file_kind' ? [f, al] : [f], async () => {
        await api('link_rule_save', {id: r.id, layer: r.layer, kind: r.kind, pattern: r.pattern, value: value.value, auto: auto.checked});
        toast('הכלל עודכן'); paintLearned(sec);
      });
    };
  }

  /* ===== המסך הראשי: שאלות מהבינה, המשימות שלי ===== */
  const main = $('main');
  const ask = node('section'); ask.id = 'mgAsk'; ask.hidden = true; ask.setAttribute('aria-labelledby', 'mgAskT');
  const mine = node('section'); mine.id = 'mgMine'; mine.hidden = true; mine.setAttribute('aria-labelledby', 'mgMineT');
  const anchor = main.querySelector('.tabs, .seg-tabs, [role="tablist"]');
  main.insertBefore(mine, anchor); main.insertBefore(ask, mine);
  async function paintAsk() {
    if (!can('case.view')) { ask.hidden = true; return; }
    let d; try { d = await api('link_questions'); } catch (_) { ask.hidden = true; return; }
    ask.hidden = !d.questions.length;
    const ul = node('ul', 'list');
    d.questions.slice(0, 5).forEach(q => {
      const li = node('li', 'card ask'); li.style.cursor = 'default';
      const b = node('div', 'body'); b.append(node('div', 'q', q.text));
      const opts = node('div', 'tiles');
      (q.options || []).forEach(o => {
        const t = btn('tile', o.label ?? o.text ?? String(o)); t.disabled = !d.can_answer;
        t.addEventListener('click', async () => {
          opts.querySelectorAll('button').forEach(x => x.disabled = true);
          try { const r = await api('link_answer', {id: q.id, answer: o.value ?? o.id ?? o.label ?? String(o)});
            li.remove(); toast(r.rule ? 'נענה, ונלמד כלל חדש' : 'נענה'); if (!ul.children.length) ask.hidden = true; }
          catch (e) { toast(e.message, true); opts.querySelectorAll('button').forEach(x => x.disabled = false); }
        });
        opts.append(t);
      });
      b.append(opts); li.append(b); ul.append(li);
    });
    const more = d.questions.length > 5 ? node('p', 'hint', `ועוד ${d.questions.length - 5} שאלות — יופיעו כאן אחרי שאלה עונים על אלה.`) : null;
    ask.replaceChildren(node('h2', 'sec-title', `שאלות מהבינה (${d.questions.length})`), ul, ...(more ? [more] : []));
    ask.firstChild.id = 'mgAskT';
  }
  async function paintMine() {
    let d; try { d = await api('my_tasks'); } catch (_) { mine.hidden = true; return; }
    mine.hidden = !d.items.length;
    const ul = node('ul', 'list');
    d.items.slice(0, 8).forEach(t => {
      const month = t.date ? t.date.slice(0, 7) : '';
      const li = row(t.title, [t.case_name, t.due ? 'עד ' + dm(t.due) : t.date ? 'לוויה ' + dm(t.date) : ''].filter(Boolean).join(' · '),
        [t.kind === 'check' ? [t.state_name, 'warn'] : ['משימה', '']], () => FD().openCase(t.case_id, month));
      if (t.kind === 'task') { const done = btn('icon-btn', '✓', 'סימון "בוצעה": ' + t.title);
        done.addEventListener('click', async () => { try { await api('task_status', {id: t.id, status: 'done'}); li.remove(); toast('סומנה כבוצעה'); if (!ul.children.length) mine.hidden = true; } catch (e) { toast(e.message, true); } });
        li.append(done); }
      ul.append(li);
    });
    mine.replaceChildren(node('h2', 'sec-title', `המשימות שלי (${d.items.length})`), ul);
    mine.firstChild.id = 'mgMineT';
  }
  document.addEventListener('fd:ready', () => { paintAsk(); paintMine(); });

  /* ===== בתוך תיק: משימות ===== */
  const pane = $('casePane');
  const tsec = node('section', 'group'); tsec.setAttribute('aria-labelledby', 'gTasks'); tsec.hidden = true;
  const thead = node('div', 'group-head'); const th = node('h3', '', 'משימות'); th.id = 'gTasks';
  const tadd = btn('btn ghost sm', '+ משימה'); thead.append(th, tadd);
  const tlist = node('div', 'list'); tsec.append(thead, tlist);
  if (pane) pane.insertBefore(tsec, pane.children[1] || null);
  let caseId = '';
  async function paintTasks() {
    tsec.hidden = !caseId; if (!caseId) return;
    tadd.hidden = !can('tasks.manage');
    let d; try { d = await api('tasks', null, {case_id: caseId}); } catch (_) { tsec.hidden = true; return; }
    const rs = can('tasks.manage') ? await roles() : [];
    tlist.replaceChildren();
    d.tasks.filter(t => t.status !== 'cancelled').forEach(t => {
      const who = t.assignee_role_id ? (rs.find(r => r.id === t.assignee_role_id) || {}).name || 'תפקיד' : t.assignee_user_id ? 'אדם' : '';
      const li = row(t.title, [who, t.due ? 'עד ' + dm(t.due) : '', t.note].filter(Boolean).join(' · '), [[t.status_name, t.status === 'done' ? 'ok' : '']]);
      li.classList.add('item');
      if (t.status === 'open') { const b = btn('icon-btn', '✓', 'סימון "בוצעה": ' + t.title);
        b.addEventListener('click', async () => { try { await api('task_status', {id: t.id, status: 'done'}); paintTasks(); paintMine(); } catch (e) { toast(e.message, true); } });
        li.append(b); }
      tlist.append(li);
    });
    if (!tlist.children.length) tlist.append(node('p', 'empty', 'אין משימות בתיק.'));
  }
  tadd.addEventListener('click', async () => {
    const rs = await roles();
    let members = [];
    try { members = (await api('members')).members.filter(m => m.active); } catch (_) {}
    const title = node('input'); title.maxLength = 120;
    const types = [...new Set(rs.flatMap(r => r.task_types))];
    const type = sel([['', 'בלי סוג'], ...types.map(t => [t, t])], '');
    type.addEventListener('change', () => { if (!title.value && type.value) title.value = type.value; });
    const who = sel([...rs.map(r => ['r' + r.id, 'תפקיד: ' + r.name]), ...members.map(m => ['u' + m.id, m.name || m.email])], '');
    const due = node('input'); due.type = 'date';
    const note = node('input'); note.maxLength = 300;
    const f = node('div', 'fields two');
    f.append(lab('מה צריך לעשות', title, true), lab('סוג', type), lab('למי', who), lab('עד מתי', due), lab('הערה', note, true));
    openSheet('משימה חדשה', [f], async () => {
      const w = who.value; const body = {case_id: caseId, title: title.value.trim(), type: type.value, due: due.value, note: note.value};
      if (w.startsWith('r')) body.assignee_role_id = Number(w.slice(1)); else if (w.startsWith('u')) body.assignee_user_id = Number(w.slice(1));
      await api('task_save', body); toast('המשימה נוספה'); paintTasks(); paintMine();
    }, 'הוספה');
  });
  document.addEventListener('fd:case', e => { caseId = (e.detail && e.detail.id) || ''; paintTasks(); });

  window.FDManage = {paintAsk, paintMine};
})();
