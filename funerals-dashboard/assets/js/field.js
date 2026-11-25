// פרוסה 04 — מסך עובד השטח: "היום", תיק עם שלושה כפתורים (📷 צילום קבר · 📎 שליחת קובץ · ✏️ עדכון), ותור שליחה במכשיר.
// ברירת המחדל לתפקיד field. כל אחד אחר — דרך ‎#/field‎ (מפתח, משרד, מנהל — לבדיקה). הליבה (בלי DOM) — field-core.js.
// החיבור היחיד ל-app.js: האירוע fd:me (מי המשתמש וה-CSRF) — אחרי שהכותרת צוירה.
(function () {
  'use strict';
  const W = window.FDField, F = window.FDForm;
  if (!W || !F) return;
  const $ = id => document.getElementById(id);
  let me = null, csrf = '', queue = null, day = null, current = null, started = false, pendingFile = null;

  function node(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = String(text);
    return e;
  }
  function toast(text, error = false) {
    const el = $('toast'); if (!el) return;
    el.textContent = text; el.classList.toggle('err', error); el.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, error ? 5000 : 2600);
  }
  async function api(action, params = {}, data = null) {
    const opts = {method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: {}};
    if (data) { opts.headers['Content-Type'] = 'application/json'; opts.headers['X-CSRF-Token'] = csrf; opts.body = JSON.stringify(data); }
    const res = await fetch('api.php?' + new URLSearchParams({action, ...params}), opts);
    const body = await res.json().catch(() => ({error: 'תגובת שרת לא תקינה'}));
    if (!res.ok) { const e = new Error(body.error || 'שגיאה בבקשה'); e.status = res.status; throw e; }
    return body;
  }
  const dm = d => d ? d.slice(8) + '/' + d.slice(5, 7) : '';

  /* ---------- התור במכשיר (IndexedDB) ---------- */

  // אותו מחסן (fd_field) שה-service worker קורא ממנו — field-core.js
  const store = 'indexedDB' in window ? W.idbStore(indexedDB) : W.memoryStore();
  const send = item => W.sendItem(item, {fetchFn: (u, o) => fetch(u, o), csrf, FormDataCtor: FormData});
  // לשונית אחת (או ה-service worker) שולחת בכל רגע
  const lock = fn => (navigator.locks ? navigator.locks.request('fd-field-flush', fn) : fn());
  let swReg = null;
  /** Background Sync: הדפדפן יעיר את sw.js כשחוזרת רשת, גם אם המסך נסגר. בלי תמיכה (iOS) — הדף שולח בפתיחה הבאה. */
  async function registerSync() {
    try { if (swReg && swReg.sync) await swReg.sync.register('fd-field-queue'); } catch (e) { /* אין הרשאה / אין תמיכה */ }
  }
  const prepDeps = {
    readBytes: async b => new Uint8Array(await b.arrayBuffer()),
    decode: async b => {
      if ('createImageBitmap' in window) return createImageBitmap(b, {imageOrientation: 'from-image'});
      const url = URL.createObjectURL(b);
      try { const i = new Image(); i.src = url; await i.decode(); return i; } finally { setTimeout(() => URL.revokeObjectURL(url), 0); }
    },
    encode: (img, w, h, q) => new Promise((ok, no) => {
      const c = document.createElement('canvas'); c.width = w; c.height = h;
      c.getContext('2d').drawImage(img, 0, 0, w, h);
      if (img.close) img.close();
      c.toBlob(b => b ? b.arrayBuffer().then(a => ok(new Uint8Array(a)), no) : no(new Error('toBlob')), 'image/jpeg', q);
    }),
    makeBlob: (u8, type) => new Blob([u8], {type}),
  };

  /* ---------- מסך "היום" ---------- */

  function build() {
    document.body.classList.add('field-mode');
    const sec = node('section', 'field'); sec.id = 'fieldApp'; sec.setAttribute('aria-label', 'עובד שטח');
    sec.innerHTML = `
      <div class="summary fw-sum"><div class="line"><b id="fwDay">היום</b><span class="muted" id="fwCount"></span></div>
        <div class="chips" id="fwQueueSum"></div></div>
      <button type="button" class="fw-big fw-loose" id="fwLoose"><span aria-hidden="true">📷</span>צילום בלי תיק</button>
      <input type="file" id="fwLooseIn" accept="image/*" capture="environment" hidden>
      <div class="search"><input type="search" id="fwSearch" placeholder="חיפוש תיק בשם" aria-label="חיפוש תיק בשם" autocomplete="off"></div>
      <div id="fwResults"></div>
      <h2 class="sec-title" id="fwTodayT">היום</h2><ul class="list" id="fwToday" aria-labelledby="fwTodayT"></ul>
      <h2 class="sec-title" id="fwTomT">מחר</h2><ul class="list" id="fwTomorrow" aria-labelledby="fwTomT"></ul>
      <h2 class="sec-title" id="fwQT" hidden>בתור לשליחה</h2><ul class="list" id="fwQueue" aria-labelledby="fwQT" hidden></ul>
      <p class="hint fw-all"><button type="button" class="btn ghost sm" id="fwAll">📋 כל התיקים</button></p>`;
    $('main').before(sec);
    const dlg = node('dialog'); dlg.id = 'fieldDialog'; dlg.setAttribute('aria-labelledby', 'fwName');
    dlg.innerHTML = `<form method="dialog" novalidate>
      <header class="sheet-head"><button type="button" class="icon-btn" data-close aria-label="סגירה">✕</button>
        <h2 id="fwName">תיק</h2><button type="button" class="icon-btn" id="fwReload" aria-label="רענון" title="רענון">⟳</button></header>
      <div class="sheet-body">
        <div class="summary" id="fwCase"></div>
        <div class="fw-acts">
          <button type="button" class="fw-big" id="fwPhoto"><span aria-hidden="true">📷</span>צילום קבר</button>
          <button type="button" class="fw-big" id="fwFile"><span aria-hidden="true">📎</span>שליחת קובץ</button>
          <button type="button" class="fw-big" id="fwEdit" aria-expanded="false"><span aria-hidden="true">✏️</span>עדכון</button>
        </div>
        <input type="file" id="fwPhotoIn" accept="image/*" capture="environment" hidden>
        <input type="file" id="fwFileIn" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" hidden>
        <div class="card fw-kind" id="fwKindBox" hidden>
          <div class="one" id="fwKindName"></div>
          <label class="f"><span>סוג הקובץ</span><select id="fwKind"></select></label>
          <div class="row-btns"><button type="button" class="btn primary" id="fwKindGo">שליחה</button><button type="button" class="btn ghost" id="fwKindNo">ביטול</button></div>
        </div>
        <h3 class="sec-title" id="fwCaseQT" hidden>בתור מהתיק הזה</h3><ul class="list" id="fwCaseQueue" hidden></ul>
        <div id="fwEditBox" hidden>
          <h3 class="sec-title">בדיקות</h3><div class="list" id="fwChecks"></div>
          <div class="gps-row"><label class="f wide"><span>מיקום הקבר</span><input id="fwGrave" maxlength="180" autocomplete="off"></label>
            <button type="button" class="btn ghost" id="fwGps">📍 המיקום שלי</button></div>
          <label class="f wide"><span>הערה</span><textarea id="fwNotes" class="grow" rows="2" maxlength="1500"></textarea></label>
          <button type="button" class="btn primary" id="fwSave">שמירה</button>
        </div>
        <h3 class="sec-title">קבצים בתיק</h3><ul class="list" id="fwFiles"></ul>
      </div></form>`;
    document.body.append(dlg);
    const viewer = node('dialog'); viewer.id = 'fieldViewer'; viewer.setAttribute('aria-labelledby', 'fwvName');
    viewer.innerHTML = `<form method="dialog"><header class="sheet-head"><button type="button" class="icon-btn" data-close aria-label="סגירה">✕</button>
      <h2 id="fwvName"></h2></header><div class="sheet-body fw-view" id="fwvBody"></div></form>`;
    document.body.append(viewer);
    for (const d of [dlg, viewer]) d.querySelector('[data-close]').addEventListener('click', () => d.close());
    dlg.addEventListener('close', () => { current = null; if (location.hash.startsWith('#/field/')) history.replaceState(null, '', '#/field'); loadToday(); });

    let st;
    $('fwSearch').addEventListener('input', () => { clearTimeout(st); st = setTimeout(search, 300); });
    $('fwAll').addEventListener('click', () => leave());
    $('fwReload').addEventListener('click', () => current && openCase(current.id));
    $('fwPhoto').addEventListener('click', () => $('fwPhotoIn').click());
    $('fwFile').addEventListener('click', () => $('fwFileIn').click());
    $('fwEdit').addEventListener('click', () => { const b = $('fwEditBox'); b.hidden = !b.hidden; $('fwEdit').setAttribute('aria-expanded', String(!b.hidden)); });
    $('fwPhotoIn').addEventListener('change', e => { const f = e.target.files[0]; e.target.value = ''; if (f) enqueue(f, 'grave_photo'); });
    $('fwFileIn').addEventListener('change', e => { const f = e.target.files[0]; e.target.value = ''; if (f) askKind(f); });
    $('fwKindGo').addEventListener('click', () => { const f = pendingFile; pendingFile = null; $('fwKindBox').hidden = true; if (f) enqueue(f, $('fwKind').value); });
    $('fwKindNo').addEventListener('click', () => { pendingFile = null; $('fwKindBox').hidden = true; });
    $('fwSave').addEventListener('click', save);
    // U6: צילום מהמסך הראשי, לא מתוך תיק — עולה לתיקייה הראשית, והזיהוי (10) משייך לתיק או שואל
    $('fwLoose').addEventListener('click', () => $('fwLooseIn').click());
    $('fwLooseIn').addEventListener('change', e => { const f = e.target.files[0]; e.target.value = ''; if (f) enqueueTo({id: '', name: 'בלי תיק', kinds: []}, f, 'other'); });
    // U7: "המיקום שלי" — נקודת GPS בשדה, שאפשר לתקן ידנית
    $('fwGps').addEventListener('click', () => myLocation($('fwGrave'), $('fwGps')));
  }
  function leave() {
    document.body.classList.remove('field-mode');
    $('fieldApp').hidden = true;
    if (location.hash.startsWith('#/field')) history.replaceState(null, '', location.pathname + location.search);
  }
  function enter() {
    if (!$('fieldApp')) build();
    document.body.classList.add('field-mode'); $('fieldApp').hidden = false;
    loadToday();
    const m = location.hash.match(/^#\/field\/([A-Za-z0-9_-]{8,100})$/);
    if (m) openCase(m[1]);
  }

  function caseRow(c, onOpen) {
    const li = node('li', 'card case'); li.tabIndex = 0; li.setAttribute('role', 'button');
    const t = node('span', 'fw-time', c.time || '—'); t.setAttribute('aria-label', c.time ? 'בשעה ' + c.time : 'בלי שעה');
    const body = node('div', 'body');
    const l1 = node('div', 'l1'); l1.append(node('span', 'one', c.name), node('span', 't', c.date !== day.today && c.date !== day.tomorrow ? dm(c.date) : ''));
    const chips = node('div', 'chips');
    const needs = W.needsText(c, F);
    if (!needs.length) chips.append(node('span', 'chip ok', 'אין חוסר מהשטח ✓'));
    needs.forEach((n, i) => chips.append(node('span', i < 2 && !n.includes('בדיקות') ? 'chip warn' : 'chip', n)));
    body.append(l1, node('div', 'l2 one', [c.origin, c.grave].filter(Boolean).join(' · ') || ' '), chips);
    li.append(t, body, node('span', 'use-go', '‹'));
    const open = () => onOpen(c);
    li.addEventListener('click', open);
    li.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    return li;
  }
  async function loadToday() {
    try {
      const d = await api('field_today');
      day = d;
      // פרוסה 12: "רק תיקים שהוקצו לי" — רשימה אחת של התיקים שהוקצו, בכל התאריכים
      const mine = !!d.assigned_only;
      const today = mine ? d.cases : d.cases.filter(c => c.date === d.today), tom = mine ? [] : d.cases.filter(c => c.date === d.tomorrow);
      $('fwTodayT').textContent = mine ? 'המשימות שלי' : 'היום';
      $('fwTomT').hidden = $('fwTomorrow').hidden = mine;
      $('fwDay').textContent = 'היום ' + dm(d.today);
      $('fwCount').textContent = mine ? ' · ' + today.length + ' תיקים שהוקצו לי' : ' · ' + today.length + ' היום · ' + tom.length + ' מחר';
      for (const [id, list, empty] of [['fwToday', today, mine ? 'עוד לא הוקצה לך תיק' : 'אין לוויות היום'], ['fwTomorrow', tom, 'אין לוויות מחר']]) {
        const ul = $(id); ul.replaceChildren();
        if (!list.length) ul.append(node('li', 'empty', empty));
        list.forEach(c => ul.append(caseRow(c, x => openCase(x.id))));
      }
    } catch (e) { toast(e.message, true); }
    paintQueue();
  }
  async function search() {
    const q = $('fwSearch').value.trim(), box = $('fwResults'); box.replaceChildren();
    if (q.length < 2) return;
    try {
      const r = await api('field_search', {q});
      const ul = node('ul', 'list'); ul.setAttribute('aria-label', 'תוצאות חיפוש');
      if (!r.cases.length) ul.append(node('li', 'empty', 'לא נמצא תיק בשם הזה'));
      r.cases.forEach(c => ul.append(caseRow(c, x => openCase(x.id))));
      box.append(node('h2', 'sec-title', 'תוצאות'), ul);
    } catch (e) { toast(e.message, true); }
  }

  /* ---------- תיק ---------- */

  async function openCase(id) {
    let d;
    try { d = await api('field_case', {id}); } catch (e) { toast(e.message, true); return; }
    current = d.case; current.kinds = d.kinds;
    if (location.hash !== '#/field/' + id) history.replaceState(null, '', '#/field/' + id);
    $('fwName').textContent = current.name;
    const sum = $('fwCase'); sum.replaceChildren();
    sum.append(node('div', 'line one', [current.date ? dm(current.date) : '', current.time, current.origin].filter(Boolean).join(' · ') || 'בלי פרטי לוויה'));
    const chips = node('div', 'chips');
    W.needsText({...current, needs: d.needs}, F).forEach(n => chips.append(node('span', 'chip warn', n)));
    if (!chips.children.length) chips.append(node('span', 'chip ok', 'אין חוסר מהשטח ✓'));
    const nx = F.nextUp(current);
    sum.append(chips, node('div', 'muted one', nx ? 'הבא בתור: ' + F.labels[nx] + ' — ' + F.statusLabels[(current.checks[nx] || {}).status || 'unknown'] : 'התיק הושלם ✓'));
    for (const b of ['fwPhoto', 'fwFile', 'fwEdit']) $(b).disabled = !d.can_track;
    const sel = $('fwKind'); sel.replaceChildren();
    d.kinds.filter(k => k.kind !== 'grave_photo').forEach(k => { const o = node('option', '', k.label); o.value = k.kind; sel.append(o); });
    $('fwGrave').value = current.grave || ''; $('fwNotes').value = current.notes || '';
    const ck = $('fwChecks'); ck.replaceChildren();
    F.KEYS.forEach(k => {
      const c = current.checks[k] || {}, row = node('label', 'f fw-ck');
      const s = node('select'); s.dataset.k = k;
      for (const [v, l] of Object.entries(F.statusLabels)) { const o = node('option', '', l); o.value = v; o.selected = v === (c.status || 'unknown'); s.append(o); }
      row.append(node('span', 'one', F.labels[k]), s);
      ck.append(row);
    });
    const files = $('fwFiles'); files.replaceChildren();
    if (!d.files.length) files.append(node('li', 'empty', 'עוד אין קבצים בתיק'));
    d.files.forEach(f => {
      const li = node('li', 'item fw-file'); const b = node('button', 'btn ghost sm', 'פתיחה'); b.type = 'button';
      b.addEventListener('click', () => view(f.name, f.link));
      const kind = (d.kinds.find(k => k.kind === f.kind) || {}).label || f.kind || '';
      li.append(node('div', 'one', f.name), node('div', 'muted one', [kind, f.added_via === 'field' ? 'מהשטח' : '', f.added_by].filter(Boolean).join(' · ')), b);
      files.append(li);
    });
    if (!$('fieldDialog').open) $('fieldDialog').showModal();
    paintQueue();
  }
  function askKind(file) {
    pendingFile = file;
    $('fwKindName').textContent = '📎 ' + file.name;
    $('fwKindBox').hidden = false; $('fwKind').focus();
  }
  async function enqueue(file, kind) {
    if (!current) return;
    // התיק נקבע עכשיו — לא אחרי ההקטנה: בינתיים העובד יכול לסגור את התיק או לפתוח אחר
    return enqueueTo({id: current.id, name: current.name, kinds: current.kinds || []}, file, kind);
  }
  async function enqueueTo(target, file, kind) {
    const takenAt = Date.now();
    try {
      let blob = file, name = file.name || 'קובץ';
      try {
        const r = await W.prepareImage(file, prepDeps);
        blob = r.blob;
        if (r.changed) name = name.replace(/\.[^.]+$/, '') + '.jpg';
      } catch (e) { /* לא הוקטן — נשלח המקור */ }
      if (kind === 'grave_photo' && !/\.jpe?g$/i.test(name) && blob.type === 'image/jpeg') name += '.jpg';
      const k = target.kinds.find(x => x.kind === kind) || {label: target.id ? kind : 'צילום'};
      await queue.add({case_id: target.id, case_name: target.name, kind, kind_label: kind === 'grave_photo' ? 'צילום קבר' : k.label, name, blob, taken_at: takenAt});
    } catch (e) { toast('לא נשמר במכשיר: ' + e.message, true); return; }
    toast(navigator.onLine ? 'שולח…' : 'נשמר במכשיר — יישלח כשתחזור קליטה');
    paintQueue();
    registerSync();
    queue.flush(true).then(paintQueue).catch(() => {});
  }
  async function save() {
    if (!current) return;
    const checks = {};
    document.querySelectorAll('#fwChecks select').forEach(s => {
      if (s.value !== ((current.checks[s.dataset.k] || {}).status || 'unknown')) checks[s.dataset.k] = {status: s.value};
    });
    const data = {id: current.id, checks, grave: $('fwGrave').value.trim(), notes: $('fwNotes').value};
    try { await api('field_save', {}, data); toast('נשמר'); $('fwEditBox').hidden = true; $('fwEdit').setAttribute('aria-expanded', 'false'); openCase(current.id); }
    catch (e) { toast(e.message, true); }
  }
  function view(name, link) {
    const b = $('fwvBody'); b.replaceChildren();
    $('fwvName').textContent = name;
    $('fieldViewer').showModal();
    // U1: אותו צופה כמו במשרד (dropbox.js) — PDF ו-Word כעמודים, גם בטלפון
    if (window.FDDropbox && window.FDDropbox.renderFile) { window.FDDropbox.renderFile(b, name, link); return; }
    if (/\.(jpe?g|png|gif|webp|heic|heif)$/i.test(name)) { const i = node('img'); i.alt = name; i.src = link; b.append(i); }
    else { const f = node('iframe'); f.title = name; f.src = link; b.append(f); }
  }

  /* ---------- U7: המיקום שלי ---------- */

  function myLocation(input, b) {
    if (!navigator.geolocation) { toast('המכשיר לא נותן מיקום', true); return; }
    b.disabled = true; toast('מאתר מיקום…');
    navigator.geolocation.getCurrentPosition(p => {
      b.disabled = false;
      input.value = W.gpsText(p.coords);
      input.dispatchEvent(new Event('input', {bubbles: true})); input.dispatchEvent(new Event('change', {bubbles: true}));
      toast('המיקום נכנס לשדה — אפשר לתקן ידנית'); input.focus();
    }, e => { b.disabled = false; toast(e.code === 1 ? 'אין הרשאה למיקום — לאשר בהגדרות הדפדפן' : 'המיקום לא נמצא — לנסות שוב בחוץ', true); },
    {enableHighAccuracy: true, timeout: 15000, maximumAge: 30000});
  }
  window.FDLocation = myLocation;   // גם לשדה "מיקום הקבר" בטופס התיק (app.js)

  /* ---------- התור על המסך ---------- */

  async function paintQueue() {
    if (!queue || !$('fieldApp')) return;
    const all = (await queue.list()).filter(i => (!i.owner || i.owner === (me.user.email || me.user.name)) && (i.org === undefined || i.org === null || i.org === me.org.id)).sort((a, b) => b.created - a.created);
    const open = all.filter(i => i.state !== 'done');
    const sum = $('fwQueueSum'); sum.replaceChildren();
    if (open.length) sum.append(node('span', 'chip warn', '⏳ ' + open.length + ' ממתינים לשליחה'));
    else if (all.length) sum.append(node('span', 'chip ok', 'הכול נשלח ✓'));
    const paint = (ul, title, list, withCase) => {
      ul.replaceChildren(); ul.hidden = title.hidden = !list.length;
      list.forEach(i => {
        const li = node('li', 'item fw-q'); li.dataset.state = i.state;
        const tx = node('div', 'body');
        tx.append(node('div', 'one', (i.kind === 'grave_photo' ? '📷 ' : '📎 ') + (withCase ? i.case_name + ' · ' : '') + (i.kind_label || i.name)),
          node('div', ['blocked', 'failed'].includes(i.state) ? 'fw-err' : 'one muted', i.message || W.STATE_LABELS[i.state]));
        const acts = node('div', 'acts');
        if (i.state === 'done' && i.file && i.file.link) { const b = node('button', 'btn ghost sm', 'פתיחה'); b.type = 'button'; b.addEventListener('click', () => view(i.file.name || i.name, i.file.link)); acts.append(b); }
        if (['pending', 'blocked', 'failed'].includes(i.state)) {
          const r = node('button', 'icon-btn', '⟳'); r.type = 'button'; r.setAttribute('aria-label', 'ניסיון חוזר'); r.title = 'ניסיון חוזר';
          r.addEventListener('click', async () => { await queue.retry(i.id); queue.flush(true).then(paintQueue); });
          const c = node('button', 'icon-btn', '✕'); c.type = 'button'; c.setAttribute('aria-label', 'ביטול השליחה'); c.title = 'ביטול';
          c.addEventListener('click', async () => { if (confirm('לבטל את השליחה? הקובץ יימחק מהמכשיר.')) { await queue.cancel(i.id); paintQueue(); } });
          acts.append(r, c);
        }
        li.append(tx, acts); ul.append(li);
      });
    };
    paint($('fwQueue'), $('fwQT'), open, true);
    if (current) paint($('fwCaseQueue'), $('fwCaseQT'), all.filter(i => i.case_id === current.id), false);
  }

  /* ---------- התחלה ---------- */

  function start(detail) {
    me = detail; csrf = detail.csrf || '';
    // מי המשתמש — ל-service worker, שישלח בשמו כשהמסך סגור (csrf של ה-session הנוכחי בלבד)
    if (store.putMeta) store.putMeta({csrf, owner: me.user.email || me.user.name, org: me.org.id, at: Date.now()}).catch(() => {});
    // משתמש וארגון — בכל fd:me מחדש (החלפת ארגון לא טוענת את הדף מחדש)
    queue = W.createQueue({store, send, lock, owner: me.user.email || me.user.name, org: me.org.id,
      onChange: i => { paintQueue(); if (i.state === 'done') { toast('נשלח ✓'); if (current && i.case_id === current.id) openCase(current.id); } }});
    if (!started) {
      started = true;
      const kick = () => queue.flush().then(paintQueue).catch(() => {});
      if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').then(r => { swReg = r; return queue.remaining(); })
        .then(n => { if (n) registerSync(); }).catch(() => {});
      window.addEventListener('online', kick);
      document.addEventListener('visibilitychange', () => { if (!document.hidden) kick(); });
      setInterval(kick, 20000);
      kick();
      window.addEventListener('hashchange', () => { if (location.hash.startsWith('#/field')) enter(); });
    }
    if ((me.role === 'field' && !me.dev) || location.hash.startsWith('#/field')) enter();
  }
  window.addEventListener('fd:me', e => start(e.detail));
})();
