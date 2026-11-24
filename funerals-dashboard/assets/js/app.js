// ניהול לוויות חברה קדישא — הממשק: תיקים, הכותרת (ארגון, משתמש), הגדרות (חברים, ארגונים) ומסך המפתח.
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const fields = {name:'fName',month:'fMonth',date:'fDate',time:'fTime',origin:'fOrigin',team:'fTeam',grave:'fGrave',notes:'fNotes',folder_url:'fFolder'};
  // הבדיקות, הסטטוסים, המסלול ו"הבא בתור" — assets/js/form.js (נבדק ב-tests/form.test.js)
  const F = window.FDForm, labels = F.labels, statusLabels = F.statusLabels, groupOf = F.groupOf;
  const groups = {bad:{icon:'⛔', chip:'bad'}, open:{icon:'❔', chip:''}, verify:{icon:'🔎', chip:'warn'}, done:{icon:'✅', chip:'ok'}};
  const monthNames = ['ינואר','פברואר','מרץ','אפריל','מאי','יוני','יולי','אוגוסט','ספטמבר','אוקטובר','נובמבר','דצמבר'];
  const TOTAL = F.TOTAL;
  let openedSnap = '';
  let items = [], current = null, csrf = '', embedKey = '', filter = 'todo', me = null, rows = {};
  // trace — הבקשות והשגיאות האחרונות של הדפדפן, להקשר של 🐞 (DevLoop)
  const trace = {requests: [], errors: []};
  const keep = (list, x) => { list.push(x); if (list.length > 15) list.shift(); };
  const can = p => !!(me && me.perms && me.perms[p]);
  try { filter = sessionStorage.getItem('fd_filter') || 'todo'; } catch (e) {}

  (() => { const vv = window.visualViewport; if (!vv) return;
    const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
    vv.addEventListener('resize', set); set(); })();

  function node(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = String(text);
    return e;
  }
  // שדה טקסט שגדל לפי התוכן — רואים את כל ההערה בלי לגלול בתוכה
  function grow(t) { t.style.height = 'auto'; t.style.height = (t.scrollHeight + 2) + 'px'; }
  document.addEventListener('input', e => { if (e.target.matches && e.target.matches('textarea.grow')) grow(e.target); });
  function toast(text, error=false) {
    const el = $('toast'); el.textContent = text; el.classList.toggle('err', error); el.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, error ? 5000 : 2600);
  }
  function newRecord() {
    return {id:'',month:$('month').value,name:'',date:'',time:'',origin:'',team:'',grave:'',notes:'',folder_url:'',documents:[],payments:[],checks:{}};
  }
  // הפעולה והפרמטרים נבנים בנפרד — אחרת "&" מקודד לתוך שם הפעולה והשרת עונה "פעולה לא מוכרת"
  async function request(action, method='GET', data=null, params={}) {
    const opts = {method,credentials:'same-origin',headers:{}};
    if (data !== null) { opts.headers['Content-Type']='application/json'; opts.headers['X-CSRF-Token']=csrf; opts.body=JSON.stringify(data); }
    const t0 = performance.now();
    const res = await fetch('api.php?' + new URLSearchParams({action, ...params}), opts);
    const response = await res.json().catch(()=>({error:'תגובת שרת לא תקינה'}));
    const row = {action, method, status: res.status, id: res.headers.get('X-Request-Id') || '', ms: Math.round(performance.now() - t0), at: new Date().toISOString()};
    keep(trace.requests, row);
    if (!res.ok) {
      keep(trace.errors, {kind: 'api', message: action + ': ' + (response.error || res.status), at: row.at});
      const err = new Error(response.error || 'שגיאה בבקשה'); err.status = res.status; err.code = response.code || ''; err.body = response;
      throw err;
    }
    return response;
  }
  function showSignin(error='') {
    $('signin').hidden = false; $('application').hidden = true; $('noorg').hidden = true;
    $('signinError').hidden = !error; $('signinError').textContent = error;
    $('signinError').className = error && error.startsWith('יצאת') ? 'hint' : 'error';
  }
  function monthLabel(m) { const [y, mm] = (m || '').split('-'); return mm ? monthNames[+mm - 1] + ' ' + y : ''; }
  function defaultMonth() {
    try { const s = sessionStorage.getItem('fd_month'); if (/^\d{4}-\d\d$/.test(s || '')) return s; } catch (e) {}
    const d = new Date(); d.setMonth(d.getMonth() - 1);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  }
  async function begin() {
    let auth;
    try { auth = await request('me'); }
    catch (e) {
      // משתמש בלי ארגון (או מושבת) — מסך אחד, בלי שום נתון
      if (e.code === 'no_org') { $('signin').hidden = true; $('application').hidden = true; $('noorg').hidden = false; $('noorgText').textContent = e.message; return; }
      showSignin(e.message); return;
    }
    me = auth; csrf = auth.csrf; embedKey = auth.embed_key || '';
    $('signin').hidden = true; $('noorg').hidden = true; $('application').hidden = false;
    paintHeader();
    if (me.dev && typeof DevLoop !== 'undefined' && !begin.dl) {
      begin.dl = true;
      DevLoop.setup({
        api: (action, o = {}) => request(action, o.body !== undefined ? 'POST' : 'GET', o.body !== undefined ? o.body : null, o.params || {}),
        toast: (m, err) => toast(m, !!err), isDev: () => !!(me && me.dev), appName: 'ניהול לוויות חברה קדישא', endpoint: 'api.php',
        tasksHref: '#/dev/tasks', mountBefore: '#userBtn', headers: () => ({'X-CSRF-Token': csrf}),
        version: () => me.version, lang: () => 'he', builtinLog: true, trace: () => trace,
      });
      DevLoop.mount();
    }
    if (!$('month').value) $('month').value = defaultMonth();
    await load();
    route();
    document.dispatchEvent(new Event('fd:ready'));   // dropbox.js: סנכרון בפתיחה, וחזרה מ-OAuth (#dropbox)
  }
  /** הכותרת: הארגון הפעיל (ובורר אם יש יותר מאחד), והכפתורים לפי ההרשאות. */
  function paintHeader() {
    $('orgName').textContent = me.org.name;
    const pick = $('orgPick'); pick.replaceChildren();
    me.orgs.forEach(o => { const opt = node('option', '', o.name); opt.value = o.id; opt.selected = o.id === me.org.id; pick.append(opt); });
    pick.hidden = me.orgs.length < 2; $('orgName').hidden = !pick.hidden;
    $('importBtn').hidden = !can('case.import'); $('exportBtn').hidden = !can('case.export');
    $('settingsBtn').hidden = !(can('members.manage') || can('orgs.manage') || can('dropbox.manage')); $('devBtn').hidden = !can('dev');
    $('newBtn').hidden = !can('case.edit');
    $('uName').textContent = me.user.name || me.user.email; $('uEmail').textContent = me.user.email || '';
    $('uRole').textContent = me.dev ? 'מפתח' + (me.role ? ' · ' + me.role_label : '') : me.role_label;
    $('uOrg').textContent = me.org.name; $('uVersion').textContent = me.version;
    $('tokenBtn').hidden = !(can('token.manage') && me.org.id === 1);
  }
  async function load() {
    const month = $('month').value;
    try { sessionStorage.setItem('fd_month', month); } catch (e) {}
    $('topSub').textContent = monthLabel(month);
    try {
      const data = await request('list', 'GET', null, {month});
      items = (data.records || []).sort((a, b) => ((a.date || '9') + (a.time || '')).localeCompare((b.date || '9') + (b.time || '')) || a.name.localeCompare(b.name, 'he'));
      renderList();
    } catch(e) { toast(e.message, true); }
  }
  /** מצב התיק — F.caseState: "הושלם" רק כשהכול סגור ויש לפחות בדיקה מאומתת אחת. */
  const state = F.caseState;
  function chipsFor(s) {
    const n = s.n, out = [];
    if (s.state === 'unchecked') out.push(node('span', 'chip', 'טרם נבדק · ' + s.pending + ' פתוחות'));
    if (n.bad) out.push(node('span', 'chip bad', n.bad + ' חסר / לא קריא'));
    if (n.verify) out.push(node('span', 'chip warn', n.verify + ' לאימות'));
    if (n.open && s.state !== 'unchecked') out.push(node('span', 'chip', n.open + ' טרם נבדקו'));
    if (s.complete) out.push(node('span', 'chip ok', 'הושלם ✓'));
    else if (!out.length) out.push(node('span', 'chip', 'אין בדיקה מאומתת'));
    return out;
  }
  function renderList() {
    const target = $('records'); target.replaceChildren();
    const q = $('search').value.trim();
    const all = items.map(x => ({x, s: state(x)}));
    const tot = {bad:0, open:0, verify:0, done:0}; let noLinks = 0;
    all.forEach(({s}) => { for (const g in tot) tot[g] += s.n[g]; noLinks += s.noLinks; });
    const doneCases = all.filter(a => a.s.complete).length;
    const pct = items.length ? Math.round(tot.done * 100 / (items.length * TOTAL)) : 0;
    $('sumDone').textContent = doneCases;
    $('sumOf').textContent = 'מתוך ' + items.length + ' תיקים הושלמו';
    $('sumPct').textContent = items.length ? pct + '% מהבדיקות' : '';
    $('trackBar').style.inlineSize = pct + '%'; $('track').setAttribute('aria-valuenow', pct);
    const sc = $('sumChips'); sc.replaceChildren();
    if (items.length) {
      if (tot.bad) sc.append(node('span', 'chip bad', tot.bad + ' חסר / לא קריא'));
      if (tot.verify) sc.append(node('span', 'chip warn', tot.verify + ' לאימות'));
      if (tot.open) sc.append(node('span', 'chip', tot.open + ' טרם נבדקו'));
      if (noLinks) sc.append(node('span', 'chip warn', '🔗 ' + noLinks + ' בלי קישור'));
    }
    const count = f => all.filter(a => F.matches(f, a.s)).length;
    $('nTodo').textContent = count('todo'); $('nBad').textContent = count('bad') || ''; $('nVerify').textContent = count('verify') || '';
    $('nUnchecked').textContent = count('unchecked') || ''; $('nDone').textContent = doneCases; $('nAll').textContent = items.length;
    document.querySelectorAll('[data-filter]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.filter === filter)));
    const shown = all.filter(({x, s}) => F.matches(filter, s)
      && [x.name, x.date, x.team, x.origin].join(' ').includes(q));
    if (!shown.length) {
      target.append(node('li', 'empty', !items.length ? 'אין תיקים ב' + monthLabel($('month').value) + '. 📥 לייבוא ממצאים, או "+ תיק".'
        : q ? 'אין תוצאות לחיפוש.' : filter === 'done' ? 'עוד אין תיקים שהושלמו.' : filter === 'todo' ? 'כל התיקים הושלמו 🎉' : 'אין תיקים בסינון הזה.'));
      return;
    }
    shown.forEach(({x, s}) => {
      const li = node('li', 'card case'); li.tabIndex = 0; li.setAttribute('role', 'button');
      li.dataset.g = s.n.bad ? 'bad' : s.complete ? 'done' : 'open';
      li.dataset.state = s.state;
      const ring = node('span', 'ring', s.n.done + '/' + TOTAL); ring.style.setProperty('--p', Math.round(s.n.done * 100 / TOTAL));
      ring.setAttribute('aria-label', s.n.done + ' מתוך ' + TOTAL + ' בדיקות הושלמו');
      const body = node('div', 'body');
      const l1 = node('div', 'l1'); l1.append(node('span', '', x.name), node('span', 't', [x.date ? x.date.slice(8) + '/' + x.date.slice(5, 7) : '', x.time].filter(Boolean).join(' ')));
      const l3 = node('div', 'chips'); chipsFor(s).forEach(c => l3.append(c));
      body.append(l1, node('div', 'l2', [x.origin, x.team, x.grave].filter(Boolean).join(' · ') || 'אין פרטי לוויה'), l3);
      li.append(ring, body, node('span', 'use-go', '‹'));
      const open = () => edit(x);
      li.addEventListener('click', open);
      li.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
      target.append(li);
    });
  }
  function edit(item) {
    current = JSON.parse(JSON.stringify(item));
    current.checks = current.checks || {}; current.payments = current.payments || []; current.documents = current.documents || [];
    $('editorTitle').textContent = current.name || 'תיק חדש';
    for (const [field, id] of Object.entries(fields)) $(id).value = current[field] || '';
    renderChecks(); renderSummary(); renderPayments(); renderDocuments();
    document.dispatchEvent(new CustomEvent('fd:case', {detail: {id: current.id || ''}}));   // קבצי Dropbox (dropbox.js)
    openedSnap = JSON.stringify(formRecord());   // לזיהוי שינויים שלא נשמרו (fd:changed)
    showPane('case'); $('caseTabs').hidden = !current.id;
    // עובד שטח: בדיקות, הערות ומיקום קבר בלבד (השרת אוכף; כאן רק מה שמוצג)
    const ro = !can('case.edit');
    for (const id of Object.values(fields)) if (id !== 'fNotes' && id !== 'fGrave') $(id).disabled = ro;
    $('addPayment').hidden = ro; $('addDocument').hidden = ro;
    $('payments').querySelectorAll('input, button').forEach(x => { x.disabled = ro; });
    $('documents').querySelectorAll('.ck-edit input, .ck-edit button').forEach(x => { x.disabled = ro; });
    if (!$('editDialog').open) $('editDialog').showModal();
    grow($('fNotes'));
    $('editDialog').querySelector('.sheet-body').scrollTop = 0;
  }
  /** בראש התיק: כמה הושלם, ומה הדבר הבא לטפל בו. */
  function renderSummary() {
    current.folder_url = $('fFolder').value;
    const s = state(current), box = $('caseSummary'); box.replaceChildren();
    const line = node('div', 'line'); line.append(node('b', '', s.n.done + ' מתוך ' + TOTAL), node('span', 'muted', 'בדיקות הושלמו'));
    if (current.date) line.append(node('span', 'pct', [current.date.slice(8) + '/' + current.date.slice(5, 7), current.time].filter(Boolean).join(' ')));
    const track = node('span', 'track'); const bar = node('span'); bar.style.inlineSize = Math.round(s.n.done * 100 / TOTAL) + '%'; track.append(bar);
    const chips = node('div', 'chips'); chipsFor(s).forEach(c => chips.append(c));
    if (s.noLinks) chips.append(node('span', 'chip warn', '🔗 ' + s.noLinks + ' בלי קישור'));
    // "הבא בתור" ראשון — לפני המונה, כדי שייראה בטלפון בלי גלילה
    const next = F.nextUp(current), nx = node('div', 'next-up');
    if (next) {
      const b = node('button', 'btn primary next-btn'); b.type = 'button';
      b.append(node('span', 'next-k', 'הבא בתור'), node('b', '', labels[next]), node('span', 'chip', statusLabels[(current.checks[next] || {}).status || 'unknown']));
      b.addEventListener('click', () => { showPane('case'); if (rows[next]) rows[next].open(); });
      nx.append(b);
    } else {
      nx.append(node('p', s.complete ? 'done-msg' : 'warn-box', s.complete ? 'התיק הושלם ✓' : 'אין בדיקה פתוחה, אבל גם אף בדיקה לא מאומתת — לאמת לפחות אחת.'));
    }
    box.append(nx, line, track, chips);
  }
  /** לשוניות התיק: "התיק" ו"היסטוריה" (נטענת מהשרת בכל פתיחה). */
  function showPane(p) {
    document.querySelectorAll('[data-pane]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.pane === p)));
    $('casePane').hidden = p !== 'case'; $('historyPane').hidden = p !== 'history';
    if (p === 'history') loadHistory();
  }
  function whenAt(at) {
    const d = new Date(at); if (isNaN(d)) return at || '';
    return d.toLocaleString('he-IL', {day:'2-digit', month:'2-digit', year:'2-digit', hour:'2-digit', minute:'2-digit'});
  }
  async function loadHistory() {
    const box = $('history'); box.replaceChildren(node('p', 'empty', 'טוען…'));
    if (!current || !current.id) { box.replaceChildren(node('p', 'empty', 'תיק חדש — עוד אין היסטוריה.')); return; }
    const id = current.id;
    try {
      const data = await request('history', 'GET', null, {id});
      if (!current || current.id !== id) return;
      box.replaceChildren();
      (data.events || []).forEach(ev => {
        const it = node('div', 'ck hist'), head = node('div', 'hist-head');
        head.append(node('b', '', whenAt(ev.at)), node('span', 'chip', F.viaLabels[ev.source] || ev.source || 'יומן ישן'));
        const who = node('small', 'src'), w = node('bdi', '', ev.actor || '—'); who.append(w, ' · ' + (F.actionLabels[ev.action] || ev.action || ''));
        it.append(head, who);
        if ((ev.changes || []).length) {
          const ul = node('ul', 'hist-ch');
          ev.changes.forEach(c => { const li = node('li'); li.append(node('span', 'muted', F.fieldLabel(c.field) + ': '),
            node('bdi', '', F.valueText(c.before)), ' ← ', node('bdi', '', F.valueText(c.after))); ul.append(li); });
          it.append(ul);
        }
        box.append(it);
      });
      if (!box.childNodes.length) box.append(node('p', 'empty', 'עוד אין שינויים רשומים לתיק.'));
    } catch (e) { box.replaceChildren(node('p', 'error', e.message)); }
  }
  function field(labelText, value, change, opts={}) {
    const wrap = node('label', 'f' + (opts.wide ? ' wide' : '')); wrap.append(node('span', '', labelText));
    const input = node(opts.area ? 'textarea' : 'input');
    if (opts.area) { input.className = 'grow'; input.rows = 1; } else input.type = opts.type || 'text';
    input.value = value === null || value === undefined ? '' : String(value);
    if (opts.ltr) input.dir = 'ltr';
    if (opts.inputmode) input.inputMode = opts.inputmode;
    input.addEventListener('input', () => change(input.value)); wrap.append(input); return wrap;
  }
  function iconBtn(icon, label, onClick) {
    const b = node('button', 'icon-btn', icon); b.type = 'button'; b.setAttribute('aria-label', label); b.title = label;
    b.addEventListener('click', onClick); return b;
  }
  /** הבדיקות כמסלול: בעיות, לאימות, טרם נבדק, הושלם. ההערה גלויה במלואה; לחיצה על השורה פותחת עדכון. */
  function renderChecks() {
    const box = $('checks'); box.replaceChildren(); rows = {};
    const keys = F.KEYS;
    keys.forEach(k => { current.checks[k] = current.checks[k] || {status:'unknown', value:'', source:'', note:''}; });
    const order = ['bad', 'verify', 'open', 'done'];
    keys.slice().sort((a, b) => order.indexOf(groupOf(current.checks[a].status)) - order.indexOf(groupOf(current.checks[b].status)))
      .forEach(key => box.append(checkRow(key)));
  }
  function checkRow(key) {
    const check = current.checks[key], wrap = node('div', 'ck'), saved = JSON.stringify([check.status, check.value, check.note]);
    const head = node('button', 'use-row'); head.type = 'button'; head.setAttribute('aria-expanded', 'false');
    const ic = node('span', 'use-ic'), tx = node('span', 'use-tx'), sub = node('small'), st = node('span', 'chip');
    tx.append(node('b', '', labels[key]), sub); head.append(ic, tx, st);
    const info = node('div', 'ck-info');
    const editBox = node('div', 'ck-edit'); editBox.hidden = true;
    const paint = () => {
      const g = groupOf(check.status); wrap.dataset.g = g; ic.textContent = groups[g].icon;
      st.className = 'chip ' + groups[g].chip; st.textContent = statusLabels[check.status] || check.status;
      sub.textContent = check.value || (g === 'open' ? 'לא נבדק עדיין' : ''); sub.hidden = !sub.textContent;
      info.replaceChildren();
      if (check.note) info.append(node('p', 'ck-note', check.note));
      if (check.source && check.source !== 'עדכון ידני') { const src = node('small', 'src', 'מסמך: '), b = node('bdi', '', check.source); b.dir = 'ltr'; src.append(b); info.append(src); }
      // מי אמר שזה בסדר — מהשרת בלבד (by/via/at). שינוי שעוד לא נשמר — "לא נשמר עדיין"
      const pv = F.provenance(check), dirty = JSON.stringify([check.status, check.value, check.note]) !== saved;
      if (dirty) info.append(node('small', 'src prov', '✎ לא נשמר עדיין'));
      else if (pv) {
        const src = node('small', 'src prov' + (pv.legacy ? ' legacy' : ''));
        if (pv.legacy) src.textContent = 'מייבוא ישן';
        else { const b = node('bdi', '', pv.by || '—'); src.append('👤 ', b, ' · ' + pv.via + (pv.at ? ' · ' + whenAt(pv.at) : '')); }
        info.append(src);
      }
      info.hidden = !info.childNodes.length;
    };
    const seg = node('fieldset', 'seg grid'); seg.style.setProperty('--cols', 4); seg.setAttribute('aria-label', 'סטטוס ' + labels[key]);
    for (const [value, t] of Object.entries(statusLabels)) {
      const l = node('label'), r = node('input'); r.type = 'radio'; r.name = 'st_' + key; r.value = value; r.checked = check.status === value;
      r.addEventListener('change', () => { check.status = value; paint(); renderSummary(); });
      l.append(r, node('span', '', t)); seg.append(l);
    }
    const f = node('div', 'fields two');
    f.append(field('ערך / מספר', check.value || '', v => { check.value = v; paint(); }, {wide:true}),
      field('הערה', check.note || '', v => { check.note = v; paint(); }, {wide:true, area:true}));
    editBox.append(seg, f);
    const toggle = force => {
      editBox.hidden = force === undefined ? !editBox.hidden : !force; head.setAttribute('aria-expanded', String(!editBox.hidden));
      if (!editBox.hidden) editBox.querySelectorAll('textarea.grow').forEach(grow);
    };
    head.addEventListener('click', () => toggle());
    rows[key] = {open: () => { toggle(true); wrap.scrollIntoView({block:'start', behavior:'smooth'}); const r = seg.querySelector('input:checked') || seg.querySelector('input'); if (r) r.focus({preventScroll:true}); }};
    paint(); wrap.append(head, info, editBox); return wrap;
  }
  function renderPayments() {
    const box = $('payments'); box.replaceChildren();
    current.payments.forEach((p, i) => {
      const row = node('div', 'item'), f = node('div', 'fields');
      f.append(field('סכום ₪', p.amount, v => p.amount = v === '' ? null : Number(v), {inputmode:'decimal', ltr:true}),
        field('אסמכתה', p.reference || '', v => p.reference = v, {ltr:true}),
        field('מטרה', p.purpose || '', v => p.purpose = v),
        field('מקור', p.source || '', v => p.source = v));
      const acts = node('div', 'acts');
      acts.append(iconBtn('🗑️', 'הסרת התשלום', () => { current.payments.splice(i, 1); renderPayments(); }));
      row.append(f, acts); box.append(row);
    });
    if (!current.payments.length) box.append(node('p', 'empty', 'לא הוזנו אסמכתאות תשלום.'));
  }
  /** מסמכים כשורות. בלי קישור — מסומן, כי אי אפשר לפתוח אותו מכאן. ✏️ — עדכון. */
  function renderDocuments() {
    const box = $('documents'); box.replaceChildren();
    const folder = $('fFolder').value, linked = current.documents.filter(d => d.url).length, missing = current.documents.length - linked;
    const st = $('docsState'); st.replaceChildren();
    if (!folder || missing) {
      st.append(node('p', 'warn-box', '🔗 ' + [!folder ? 'אין קישור לתיקייה ב-Dropbox' : '',
        missing ? missing + ' מתוך ' + current.documents.length + ' מסמכים בלי קישור, ולכן אי אפשר לפתוח אותם מכאן' : ''].filter(Boolean).join(' · ')
        + '. קישור שיתוף מוסיפים ב-✏️ של המסמך, או ב"פרטי הלוויה" לתיקייה.'));
    }
    if (folder) {
      const a = node('a', 'use-row'); a.href = folder; a.target = '_blank'; a.rel = 'noopener noreferrer';
      const tx = node('span', 'use-tx'); tx.append(node('b', '', 'התיקייה ב-Dropbox'), node('small', '', 'כל המסמכים של התיק'));
      a.append(node('span', 'use-ic', '📁'), tx, node('span', 'use-go', '↗'));
      box.append(a);
    }
    current.documents.forEach((d, i) => {
      const wrap = node('div', 'ck'); wrap.dataset.g = d.url ? 'done' : 'verify';
      const row = node('div', 'use-row');
      const tx = node('span', 'use-tx'), name = node('b'); name.append(node('bdi', '', d.label || 'מסמך'));
      tx.append(name, node('small', '', d.url ? (d.kind || 'מסמך') : 'אין קישור' + (d.kind ? ' · ' + d.kind : '')));
      row.append(node('span', 'use-ic', /\.pdf$/i.test(d.label || '') ? '📄' : '🖼️'), tx);
      if (d.url) {
        row.append(iconBtn('👁️', 'תצוגה', () => previewFile(d.url)));
        const a = node('a', 'icon-btn', '↗'); a.href = d.url; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.setAttribute('aria-label', 'פתיחה ב-Dropbox'); row.append(a);
      }
      const ed = node('div', 'ck-edit'); ed.hidden = true;
      const f = node('div', 'fields two');
      f.append(field('תיאור', d.label || '', v => d.label = v, {wide:true}), field('סוג', d.kind || '', v => d.kind = v, {ltr:true}),
        field('קישור Dropbox (HTTPS)', d.url || '', v => d.url = v, {ltr:true, inputmode:'url'}));
      const del = node('button', 'btn danger sm', '🗑️ הסרת המסמך'); del.type = 'button';
      del.addEventListener('click', () => { current.documents.splice(i, 1); renderDocuments(); });
      ed.append(f, del);
      row.append(iconBtn('✏️', 'עדכון המסמך', () => { ed.hidden = !ed.hidden; if (ed.hidden) renderDocuments(); }));
      wrap.append(row, ed); box.append(wrap);
    });
    if (!current.documents.length && !folder) box.append(node('p', 'empty', 'אין מסמכים בתיק.'));
  }
  async function previewFile(url) {
    if (!embedKey) { toast('תצוגה מוטמעת דורשת App Key של Dropbox. בינתיים — ↗ פתיחה ב-Dropbox.'); return; }
    const p = new URL(url);
    if (p.protocol !== 'https:' || !['www.dropbox.com','dropbox.com'].includes(p.hostname)) { toast('מותר להציג רק קישורי Dropbox מאומתים.', true); return; }
    const box = $('preview'); box.replaceChildren(); $('previewDialog').showModal();
    try {
      if (!window.Dropbox) {
        await new Promise((resolve, reject) => {
          const s = document.createElement('script');
          s.src = 'https://www.dropbox.com/static/api/2/dropins.js'; s.id = 'dropboxjs'; s.dataset.appKey = embedKey;
          s.onload = resolve; s.onerror = () => reject(new Error('לא ניתן לטעון את רכיב Dropbox')); document.head.append(s);
        });
      }
      if (!window.Dropbox || !window.Dropbox.isBrowserSupported()) throw new Error('התצוגה המוטמעת אינה נתמכת בדפדפן זה');
      window.Dropbox.embed({link:url, file:{zoom:'best'}, folder:{view:'list'}}, box);
    } catch(e) {
      box.append(node('p', 'error', e.message));
      const a = node('a', 'btn ghost', '↗ פתיחה ב-Dropbox'); a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer'; box.append(a);
    }
  }

  document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });
  document.querySelectorAll('[data-filter]').forEach(b => b.addEventListener('click', () => {
    filter = b.dataset.filter; try { sessionStorage.setItem('fd_filter', filter); } catch (e) {} renderList(); }));
  $('editDialog').addEventListener('close', () => { current = null; rows = {}; });
  // שינוי שנשמר מחוץ לטופס (✓ על הצעה של העוזר, ai.js): הרשימה נטענת מחדש, ותיק פתוח מתעדכן — כדי ש"שמירה" לא תדרוס את השינוי
  // טופס עם שינויים שלא נשמרו לא מתאפס: הודעה בלבד (ביקורת 06 🟡6)
  document.addEventListener('fd:changed', async e => {
    const id = e.detail && e.detail.id;
    const open = current && current.id === id;
    const dirty = open && JSON.stringify(formRecord()) !== openedSnap;
    await load();
    const x = open && !dirty ? items.find(i => i.id === id) : null;
    if (x) { edit(x); toast('התיק עודכן מההצעה שאושרה'); }
    else if (dirty) toast('ההצעה נשמרה בתיק, אבל בטופס יש שינויים שלא נשמרו. כדי לא לדרוס אותה — לסגור בלי לשמור ולפתוח שוב.', true);
  });
  document.querySelectorAll('[data-pane]').forEach(b => b.addEventListener('click', () => showPane(b.dataset.pane)));
  $('retryBtn').addEventListener('click', () => begin());
  $('tokenBtn').addEventListener('click', async () => {
    if (!confirm('ליצור אסימון API חדש? האסימון הקיים יבוטל, ומערכות שמשתמשות בו יפסיקו לעבוד.')) return;
    try { const res = await request('api_token', 'POST', {}); $('tokenValue').value = res.token; $('tokenDialog').showModal(); $('tokenValue').select(); }
    catch(e) { toast(e.message, true); }
  });
  $('copyToken').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('tokenValue').value); toast('האסימון הועתק'); } catch(e) { $('tokenValue').select(); } });
  $('tokenDialog').addEventListener('close', () => { $('tokenValue').value = ''; });
  $('month').addEventListener('change', () => load());
  $('search').addEventListener('input', renderList);
  $('newBtn').addEventListener('click', () => edit(newRecord()));
  $('addPayment').addEventListener('click', () => { current.payments.push({amount:null, purpose:'', reference:'', source:''}); renderPayments(); });
  $('addDocument').addEventListener('click', () => { current.documents.push({label:'', kind:'', url:''}); renderDocuments();
    const eds = $('documents').querySelectorAll('.ck-edit'); if (eds.length) eds[eds.length - 1].hidden = false; });
  $('fFolder').addEventListener('change', () => { renderDocuments(); renderSummary(); });
  /** התיק כפי שהוא בטופס עכשיו (מה ש"שמירה" תשלח). */
  function formRecord() {
    const record = JSON.parse(JSON.stringify(current));
    for (const [key, id] of Object.entries(fields)) record[key] = $(id).value;
    return record;
  }
  $('editor').addEventListener('submit', async e => {
    e.preventDefault();
    if (!$('fName').value.trim()) { toast('נדרש שם נפטר', true); $('fName').focus(); return; }
    const record = formRecord();
    try {
      const result = await request('save', 'POST', record);
      $('editDialog').close();
      toast('נשמר'); $('month').value = result.record.month; await load();
    } catch(err) { toast(err.message, true); }
  });
  $('exportBtn').addEventListener('click', async () => {
    try {
      const month = $('month').value;
      const data = await request('export', 'GET', null, {month});
      const blob = new Blob([JSON.stringify(data, null, 2)], {type:'application/json'});
      const url = URL.createObjectURL(blob), a = node('a'); a.href = url; a.download = 'funerals-' + month + '.json'; document.body.append(a); a.click(); a.remove(); URL.revokeObjectURL(url);
      toast('הקובץ הורד — לשמור במקום מאובטח');
    } catch(err) { toast(err.message, true); }
  });
  $('importBtn').addEventListener('click', () => $('importFile').click());
  $('importFile').addEventListener('change', async () => {
    const file = $('importFile').files[0]; if (!file) return;
    if (file.size > 1200000) { toast('הקובץ גדול מדי', true); return; }
    try {
      const data = JSON.parse(await file.text());
      if (!Array.isArray(data.records)) throw new Error('קובץ JSON חייב לכלול records');
      const result = await request('import', 'POST', {records:data.records});
      const months = [...new Set(data.records.map(r => r && r.month).filter(Boolean))];
      if (months.length === 1) $('month').value = months[0];
      toast('נקלטו ' + result.imported + ' תיקים'); await load();
    } catch(err) { toast(err.message, true); }
    finally { $('importFile').value = ''; }
  });
  /* ===== תפריט משתמש, בורר ארגון ===== */
  $('userBtn').addEventListener('click', () => $('userDialog').showModal());
  $('orgPick').addEventListener('change', async () => {
    try { await request('org_switch', 'POST', {org_id: +$('orgPick').value}); await begin(); toast('עברת ל' + me.org.name); }
    catch (e) { toast(e.message, true); paintHeader(); }
  });

  /* ===== ⚙️ הגדרות: חברים, ארגונים ===== */
  let stab = 'members';
  function openSettings(tab) { stab = tab || (can('members.manage') ? 'members' : can('orgs.manage') ? 'orgs' : 'dropbox'); $('settingsDialog').showModal(); paintSettings(); }
  $('settingsBtn').addEventListener('click', () => openSettings());
  document.querySelectorAll('[data-stab]').forEach(b => b.addEventListener('click', () => { stab = b.dataset.stab; paintSettings(); }));
  function paintSettings() {
    document.querySelector('[data-stab="members"]').hidden = !can('members.manage');
    document.querySelector('[data-stab="orgs"]').hidden = !can('orgs.manage');
    document.querySelectorAll('[data-stab]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.stab === stab)));
    document.querySelector('[data-stab="dropbox"]').hidden = !can('dropbox.manage');
    $('sMembers').hidden = stab !== 'members'; $('sOrgs').hidden = stab !== 'orgs'; $('sDropbox').hidden = stab !== 'dropbox';
    if (stab === 'members') loadMembers(); else if (stab === 'orgs') loadOrgs(); else if (window.FDDropbox) window.FDDropbox.settings($('sDropbox'));
  }
  async function loadMembers(data) {
    try { data = data || await request('members'); } catch (e) { toast(e.message, true); return; }
    const sel = $('invRole');
    if (!sel.options.length) for (const [k, v] of Object.entries(data.roles)) { const o = node('option', '', v); o.value = k; o.selected = k === 'field'; sel.append(o); }
    const list = $('membersList'); list.replaceChildren();
    data.members.forEach(m => {
      const li = node('li', 'card member'); li.style.cursor = 'default';
      const body = node('div', 'body');
      const l1 = node('div', 'l1'); l1.append(node('span', '', (m.name || m.email || 'משתמש') + (m.me ? ' (את/ה)' : '')));
      if (!m.active) l1.append(node('span', 'chip bad', 'מושבת'));
      const l2 = node('div', 'l2'); const em = node('bdi', '', m.email || ''); em.dir = 'ltr'; l2.append(em);
      body.append(l1, l2);
      const role = node('select', 'mini'); role.setAttribute('aria-label', 'תפקיד ' + (m.name || m.email));
      for (const [k, v] of Object.entries(data.roles)) { const o = node('option', '', v); o.value = k; o.selected = k === m.role; role.append(o); }
      role.addEventListener('change', async () => {
        try { loadMembers(await request('member_role', 'POST', {user_id: m.id, role: role.value})); toast('התפקיד עודכן'); }
        catch (e) { toast(e.message, true); loadMembers(); }
      });
      const act = node('button', 'btn sm ' + (m.active ? 'danger' : ''), m.active ? 'השבתה' : 'הפעלה'); act.type = 'button'; act.hidden = m.me;
      act.addEventListener('click', async () => {
        if (m.active && !confirm('להשבית את ' + (m.name || m.email) + '? הוא לא יוכל להיכנס לארגון הזה (בארגונים אחרים — לא משתנה).')) return;
        try { loadMembers(await request('member_active', 'POST', {user_id: m.id, active: !m.active})); } catch (e) { toast(e.message, true); }
      });
      li.append(body, role, act); list.append(li);
    });
    if (!data.members.length) list.append(node('li', 'empty', 'אין חברים בארגון.'));
    const inv = $('invitesList'); inv.replaceChildren();
    data.invites.forEach(i => {
      const li = node('li', 'card'); li.style.cursor = 'default';
      const body = node('div', 'body'); const e = node('bdi', '', i.email); e.dir = 'ltr';
      const l1 = node('div', 'l1'); l1.append(e); body.append(l1, node('div', 'l2', (data.roles[i.role] || i.role) + ' · ממתין לכניסה'));
      const x = node('button', 'btn sm danger', 'ביטול'); x.type = 'button';
      x.addEventListener('click', async () => { try { loadMembers(await request('invite_cancel', 'POST', {id: i.id})); } catch (err) { toast(err.message, true); } });
      li.append(body, x); inv.append(li);
    });
    if (!data.invites.length) inv.append(node('li', 'empty', 'אין הזמנות פתוחות.'));
  }
  $('invSend').addEventListener('click', async () => {
    const email = $('invEmail').value.trim();
    if (!email) { toast('נדרש מייל', true); $('invEmail').focus(); return; }
    try { loadMembers(await request('invite', 'POST', {email, role: $('invRole').value})); $('invEmail').value = ''; toast('ההזמנה נשמרה'); }
    catch (e) { toast(e.message, true); }
  });
  async function loadOrgs(data) {
    try { data = data || await request('orgs'); } catch (e) { toast(e.message, true); return; }
    const list = $('orgsList'); list.replaceChildren();
    data.orgs.forEach(o => {
      const li = node('li', 'card'); li.style.cursor = 'default';
      const body = node('div', 'body');
      const l1 = node('div', 'l1'); l1.append(node('span', '', o.name)); if (o.id === me.org.id) l1.append(node('span', 'chip ok', 'פעיל'));
      body.append(l1, node('div', 'l2', o.cases + ' תיקים · ' + o.members + ' חברים'));
      const ren = node('button', 'btn sm', 'שינוי שם'); ren.type = 'button';
      ren.addEventListener('click', async () => {
        const name = prompt('השם החדש של הארגון', o.name); if (!name || name === o.name) return;
        try { loadOrgs(await request('org_rename', 'POST', {org_id: o.id, name})); await begin(); } catch (e) { toast(e.message, true); }
      });
      const go = node('button', 'btn sm primary', 'מעבר'); go.type = 'button'; go.hidden = o.id === me.org.id;
      go.addEventListener('click', async () => {
        try { await request('org_switch', 'POST', {org_id: o.id}); $('settingsDialog').close(); await begin(); toast('עברת ל' + me.org.name); }
        catch (e) { toast(e.message, true); }
      });
      li.append(body, ren, go); list.append(li);
    });
  }
  $('orgCreate').addEventListener('click', async () => {
    const name = $('orgNew').value.trim(); if (!name) { toast('נדרש שם', true); return; }
    try { const r = await request('org_create', 'POST', {name}); $('orgNew').value = ''; await begin(); loadOrgs(r); toast('הארגון נוצר'); }
    catch (e) { toast(e.message, true); }
  });

  /* ===== 🛠️ מסך המפתח ===== */
  let dtab = 'health';
  $('devBtn').addEventListener('click', () => openDev('health'));
  $('devReload').addEventListener('click', () => paintDev());
  document.querySelectorAll('[data-dtab]').forEach(b => b.addEventListener('click', () => {
    dtab = b.dataset.dtab; if (dtab === 'tasks') { location.hash = '#/dev/tasks'; } paintDev(); }));
  function openDev(tab) { dtab = tab; if (!$('devDialog').open) $('devDialog').showModal(); paintDev(); }
  $('devDialog').addEventListener('close', () => { if (location.hash.startsWith('#/dev')) history.replaceState(null, '', location.pathname + location.search); });
  const when = iso => iso ? new Date(iso).toLocaleString('he-IL', {day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '';
  let errorsOnly = false;
  async function paintDev() {
    document.querySelectorAll('[data-dtab]').forEach(b => b.setAttribute('aria-selected', String(b.dataset.dtab === dtab)));
    const box = $('devBody'); box.replaceChildren(node('p', 'hint', 'טוען…'));
    try {
      if (dtab === 'tasks') { box.replaceChildren(); if (typeof DevLoop !== 'undefined') DevLoop.show(box); return; }
      if (dtab === 'health') {
        const d = await request('dev_health'); box.replaceChildren();
        const list = node('ul', 'list');
        d.checks.forEach(c => {
          const li = node('li', 'card'); li.style.cursor = 'default'; li.dataset.g = c.ok ? 'done' : 'bad';
          const body = node('div', 'body'); const l1 = node('div', 'l1'); l1.append(node('span', '', (c.ok ? '✅ ' : '⛔ ') + c.label));
          body.append(l1); if (c.detail) { const l2 = node('div', 'l2'); const b = node('bdi', '', c.detail); l2.append(b); body.append(l2); }
          li.append(body); list.append(li);
        });
        box.append(list, node('h3', 'sec-title', 'חיבורים חיצוניים'));
        const ext = node('ul', 'list');
        d.external.forEach(x => { const li = node('li', 'card'); li.style.cursor = 'default'; const b = node('div', 'body'); b.append(node('div', 'l1', (x.ok === undefined ? '⏳ ' : x.ok ? '✅ ' : '⛔ ') + x.name), node('div', 'l2', x.ok === undefined ? 'עוד לא מחובר — ' + x.state : x.state)); li.append(b); ext.append(li); });
        box.append(ext, node('p', 'hint', 'PHP ' + d.php));
        return;
      }
      if (dtab === 'requests') {
        const d = await request('dev_requests', 'GET', null, errorsOnly ? {errors: 1} : {}); box.replaceChildren();
        const lab = node('label', 'chips'), cb = node('input'); cb.type = 'checkbox'; cb.checked = errorsOnly;
        cb.addEventListener('change', () => { errorsOnly = cb.checked; paintDev(); }); lab.append(cb, node('span', '', 'רק שגיאות'));
        const list = node('ul', 'list');
        d.rows.forEach(r => {
          const li = node('li', 'card'); li.style.cursor = 'default'; li.dataset.g = r.status >= 500 ? 'bad' : r.status >= 400 ? 'verify' : 'done';
          const body = node('div', 'body'); const l1 = node('div', 'l1');
          const a = node('bdi', '', r.method + ' ' + (r.action || '—')); a.dir = 'ltr';
          l1.append(a, node('span', 'chip ' + (r.status >= 500 ? 'bad' : r.status >= 400 ? 'warn' : 'ok'), r.status), node('span', 't', when(r.at)));
          body.append(l1, node('div', 'l2', [r.user || '', r.ms + 'ms', r.error].filter(Boolean).join(' · ')));
          li.append(body); list.append(li);
        });
        if (!d.rows.length) list.append(node('li', 'empty', 'אין בקשות.'));
        box.append(lab, list); return;
      }
      const d = await request('dev_events'); box.replaceChildren();
      const list = node('ul', 'list');
      d.rows.forEach(r => {
        const li = node('li', 'card'); li.style.cursor = 'default';
        const body = node('div', 'body'); const l1 = node('div', 'l1');
        l1.append(node('span', '', r.action + (r.case_id ? ' · ' + r.case_id : '')), node('span', 'chip', r.source), node('span', 't', when(r.at)));
        const keys = Object.keys(r.diff || {});
        body.append(l1, node('div', 'l2', [r.org || 'מערכת', r.actor, keys.length ? 'שינוי: ' + keys.join(', ') : ''].filter(Boolean).join(' · ')));
        li.append(body); list.append(li);
      });
      if (!d.rows.length) list.append(node('li', 'empty', 'אין פעולות.'));
      box.append(list);
    } catch (e) { box.replaceChildren(node('p', 'error', e.message)); }
  }
  /** #/dev/tasks[/FD-001] — קישור ישר למטלות (DevLoop, התראות). */
  function route() { if (me && me.dev && location.hash.startsWith('#/dev/tasks')) openDev('tasks'); }
  addEventListener('hashchange', route);

  $('logoutBtn').addEventListener('click', async () => {
    try { await request('logout', 'POST', {}); csrf = ''; current = null; items = [];
      $('userDialog').close(); showSignin('יצאת מהאפליקציה. "כניסה מחדש" תחבר אותך שוב לפי ההרשאה בפלטפורמה.'); }
    catch(e) { toast(e.message, true); }
  });
  window.FD = {request, toast, csrf: () => csrf, can: p => can(p), openSettings};   // לשימוש dropbox.js
  begin();
})();
