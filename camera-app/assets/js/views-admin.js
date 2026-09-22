/**
 * נושאים, יומן אירועים, גשרים והגדרות (משתמשים, תיקיות, אחסון, cron).
 */

import { api, state, esc, fmtTime, fmtBytes, toast, modal, confirmDialog, formData, isAdmin, isOperator,
         ROLE_LABEL, refreshOverview, navigate, timeAgo, cameraName } from './app.js';
import { evLabel } from './views-cameras.js';

/* ───────── עץ כללי (נושאים / תיקיות מצלמות) ───────── */

function treeHtml(items, parentId, render) {
  const kids = items.filter((i) => (i.parent_id ?? null) === parentId);
  if (!kids.length) return '';
  return `<ul class="tree">${kids.map((k) => `<li><div class="tree__row">${render(k)}</div>${treeHtml(items, k.id, render)}</li>`).join('')}</ul>`;
}

async function editNode({ title, item, items, extraFields = '' }) {
  const opt = (v, t, cur) => `<option value="${v}" ${String(v) === String(cur ?? '') ? 'selected' : ''}>${esc(t)}</option>`;
  const parents = items.filter((i) => !item || i.id !== item.id);
  return modal({ title, body: `<form data-f>
      <div class="field"><label>שם</label><input name="name" value="${esc(item?.name || '')}" required maxlength="80"></div>
      <div class="field"><label>בתוך</label><select name="parent_id">${opt('', '— שורש —', item?.parent_id)}${parents.map((p) => opt(p.id, p.name, item?.parent_id)).join('')}</select></div>
      ${extraFields}
    </form>`,
    actions: [{ label: 'ביטול', value: null }, { label: 'שמור', cls: 'btn--primary', onClick: (box) => {
      const f = box.querySelector('[data-f]'); if (!f.reportValidity()) return false; return formData(f);
    } }] });
}

/* ───────── נושאים ───────── */

export async function renderTopics(container) {
  let topics = (await api('topics')).topics;
  const draw = () => {
    container.innerHTML = `<div class="section-head"><h2>תיקיות נושא</h2><span class="spacer"></span>${isOperator() ? '<button class="btn btn--primary btn--sm" data-add type="button">+ נושא</button>' : ''}</div>
      <p class="hint" style="margin-bottom:10px">הקלטה יכולה להיות בכמה נושאים. משייכים מדף ההקלטה, או בבחירה מרובה בגלריה.</p>
      ${topics.length ? treeHtml(topics, null, (t) => `<a class="name" href="#/recordings?topic=${t.id}">📁 ${esc(t.name)} <span class="badge">${t.count}</span>${t.description ? `<div class="hint">${esc(t.description)}</div>` : ''}</a>
        ${isOperator() ? `<button class="btn btn--sm btn--ghost" data-edit="${t.id}" type="button">✎</button>` : ''}${isAdmin() ? `<button class="btn btn--sm btn--ghost" data-del="${t.id}" type="button">✕</button>` : ''}`)
        : '<div class="empty"><b>אין נושאים</b>למשל: "שיפוץ", "חבילות", "השכנים".</div>'}`;
    container.querySelector('[data-add]')?.addEventListener('click', () => edit(null));
    container.querySelectorAll('[data-edit]').forEach((b) => b.onclick = () => edit(topics.find((t) => t.id === +b.dataset.edit)));
    container.querySelectorAll('[data-del]').forEach((b) => b.onclick = async () => {
      const t = topics.find((x) => x.id === +b.dataset.del);
      if (!(await confirmDialog(`למחוק את הנושא "${t.name}"? ההקלטות עצמן נשארות.`))) return;
      try { await api('topic-delete', { id: t.id }); await reload(); } catch (err) { toast(err.message, 'err'); }
    });
  };
  const reload = async () => { topics = (await api('topics')).topics; await refreshOverview().catch(() => {}); draw(); };
  const edit = async (item) => {
    const d = await editNode({ title: item ? 'עריכת נושא' : 'נושא חדש', item, items: topics,
      extraFields: `<div class="field"><label>תיאור</label><textarea name="description">${esc(item?.description || '')}</textarea></div>` });
    if (!d) return;
    try { await api('topic-save', { id: item?.id, name: d.name, parent_id: d.parent_id || null, description: d.description }); await reload(); }
    catch (err) { toast(err.message, 'err'); }
  };
  draw();
}

/* ───────── אירועים ───────── */

export async function renderEvents(container, q = {}) {
  const ov = state.overview || await refreshOverview();
  let events = []; let kind = q.kind || ''; let camera = q.camera || '';
  container.innerHTML = `<div class="section-head"><h2>יומן אירועים</h2></div>
    <div class="filters">
      <select data-kind><option value="">כל הסוגים</option><option value="detect">זיהויים</option><option value="camera">מצלמות</option><option value="bridge">גשרים</option><option value="record">הקלטה</option><option value="ingest">קליטה</option><option value="retention">מחיקה</option><option value="login">כניסות</option><option value="server">שגיאות שרת</option></select>
      <select data-cam><option value="">כל המצלמות</option>${ov.cameras.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
    </div>
    <div class="card" data-list></div>
    <div class="actions" style="justify-content:center;margin-top:10px"><button class="btn" data-more type="button" hidden>טען עוד</button></div>`;
  container.querySelector('[data-kind]').value = kind; container.querySelector('[data-cam]').value = camera;
  const list = container.querySelector('[data-list]'); const more = container.querySelector('[data-more]');
  let lastDay = '';
  const load = async (reset) => {
    if (reset) { events = []; lastDay = ''; list.innerHTML = ''; }
    const j = await api('events', { kind: kind || undefined, camera_id: camera || undefined, limit: 100, before_id: events.length ? events[events.length - 1].id : undefined }).catch((e) => { toast(e.message, 'err'); return { events: [] }; });
    events = events.concat(j.events);
    let html = '';
    for (const ev of j.events) {
      const d = new Date(ev.at).toLocaleDateString('he-IL');
      if (d !== lastDay) { html += `<div class="folder-title">${d}</div>`; lastDay = d; }
      html += `<div class="ev ev--${esc(ev.level)}"><time>${esc(fmtTime(ev.at, false))}</time><span class="ev__msg">${ev.camera_id ? `<a href="#/camera/${ev.camera_id}">${esc(cameraName(+ev.camera_id))}</a> · ` : ''}${esc(evLabel(ev))}</span></div>`;
    }
    list.insertAdjacentHTML('beforeend', html || (reset ? '<p class="empty">אין אירועים</p>' : ''));
    more.hidden = j.events.length < 100;
  };
  container.querySelector('[data-kind]').onchange = (e) => { kind = e.target.value; load(true); };
  container.querySelector('[data-cam]').onchange = (e) => { camera = e.target.value; load(true); };
  more.onclick = () => load(false);
  load(true);
}

/* ───────── גשרים ───────── */

export async function renderBridges(container) {
  let bridges = (await api('bridge-list')).bridges;
  const ov = state.overview || await refreshOverview();
  const base = location.origin + location.pathname.replace(/[^/]*$/, '');
  const draw = () => {
    container.innerHTML = `<div class="section-head"><h2>גשרים</h2><span class="spacer"></span><button class="btn btn--primary btn--sm" data-add type="button">+ גשר</button></div>
      <div class="card" style="margin-bottom:12px">
        <p><b>מה זה גשר?</b> תוכנה קטנה שרצה על מחשב/רספברי ברשת של המצלמות. היא היחידה שמגיעה אליהן — ולכן שידור חי, PTZ, הקלטה רציפה וידנית עוברים דרכה. השרת הזה לא נכנס לבית; הגשר יוצא ממנו אליו.</p>
        <p class="hint" style="margin-top:6px">גשר אחד לרשת אחת (בית, עסק). כל המצלמות שבאותה רשת — על אותו גשר.</p>
      </div>
      <div class="list">${bridges.length ? bridges.map((b) => `<div class="item">
        <span class="dot dot--${b.online ? 'online' : (b.paired_at ? 'offline' : 'unknown')}"></span>
        <div class="item__main"><b>${esc(b.name)}</b><small>${b.paired_at ? `${b.online ? 'מחובר' : 'לא מדווח'} · נראה ${esc(timeAgo(b.last_seen_at))} · גרסה ${esc(b.version || '?')} · IP ${esc(b.local_ip || '?')} · ${b.cameras} מצלמות` : 'ממתין לצימוד'}</small>
          ${b.info?.ffmpeg === false ? '<small style="color:var(--err)">⚠ ffmpeg לא מותקן בגשר</small>' : ''}
          ${b.info?.load ? `<small>עומס: ${esc(String(b.info.load))}</small>` : ''}</div>
        ${b.pair_code_valid ? `<span class="paircode">${esc(b.pair_code)}</span>` : ''}
        <button class="btn btn--sm" data-inst="${b.id}" type="button">התקנה</button>
        <button class="btn btn--sm btn--ghost" data-renew="${b.id}" type="button" title="קוד צימוד חדש">🔑</button>
        <button class="btn btn--sm btn--ghost" data-rename="${b.id}" type="button">✎</button>
        <button class="btn btn--sm btn--ghost" data-del="${b.id}" type="button">✕</button>
      </div>`).join('') : '<div class="empty"><b>אין גשרים</b>בלי גשר המצלמות עובדות ב-FTP בלבד.</div>'}</div>`;

    container.querySelector('[data-add]').onclick = async () => {
      const name = await modal({ title: 'גשר חדש', body: '<div class="field"><label>שם (למשל "בית")</label><input name="name" maxlength="60"></div>',
        actions: [{ label: 'ביטול', value: null }, { label: 'צור', cls: 'btn--primary', onClick: (b) => b.querySelector('[name=name]').value || 'גשר' }] });
      if (name === null) return;
      try { const j = await api('bridge-create', { name }); await reload(); showInstall(bridges.find((b) => b.id === j.id)); } catch (err) { toast(err.message, 'err'); }
    };
    container.querySelectorAll('[data-inst]').forEach((b) => b.onclick = () => showInstall(bridges.find((x) => x.id === +b.dataset.inst)));
    container.querySelectorAll('[data-renew]').forEach((b) => b.onclick = async () => {
      if (!(await confirmDialog('קוד חדש מנתק את הגשר הקיים עד שיצומד מחדש. להמשיך?', 'צור קוד'))) return;
      try { await api('bridge-renew', { id: +b.dataset.renew }); await reload(); } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelectorAll('[data-rename]').forEach((b) => b.onclick = async () => {
      const br = bridges.find((x) => x.id === +b.dataset.rename);
      const name = await modal({ title: 'שם הגשר', body: `<div class="field"><input name="name" value="${esc(br.name)}" maxlength="60"></div>`,
        actions: [{ label: 'ביטול', value: null }, { label: 'שמור', cls: 'btn--primary', onClick: (x) => x.querySelector('[name=name]').value }] });
      if (!name) return;
      try { await api('bridge-rename', { id: br.id, name }); await reload(); } catch (err) { toast(err.message, 'err'); }
    });
    container.querySelectorAll('[data-del]').forEach((b) => b.onclick = async () => {
      const br = bridges.find((x) => x.id === +b.dataset.del);
      if (!(await confirmDialog(`למחוק את הגשר "${br.name}"? המצלמות שלו יישארו, בלי גשר.`))) return;
      try { await api('bridge-delete', { id: br.id }); await reload(); } catch (err) { toast(err.message, 'err'); }
    });
  };
  const reload = async () => { bridges = (await api('bridge-list')).bridges; await refreshOverview().catch(() => {}); draw(); };
  const showInstall = (b) => modal({ title: 'התקנת הגשר: ' + b.name, wide: true, body: `
    <p>על הקופסה (Raspberry Pi OS / Debian / Ubuntu), בטרמינל:</p>
    <pre class="mono">curl -fsSL ${esc(base)}bridge/install.sh | sudo bash -s -- ${esc(base)} ${esc(b.pair_code || '<קוד>')}</pre>
    ${b.pair_code_valid ? `<p>קוד הצימוד: <span class="paircode">${esc(b.pair_code)}</span> <span class="hint">(תקף 15 דקות)</span></p>` : '<p class="hint">אין קוד תקף — לחץ 🔑 כדי ליצור חדש.</p>'}
    <p style="margin-top:10px">הסקריפט מתקין ffmpeg ו-Python, מצמיד את הגשר לשרת הזה, ומגדיר אותו לעלות אוטומטית אחרי חשמל. תוך דקה הוא יופיע כאן כ"מחובר", ואז משייכים אליו מצלמות (עריכת מצלמה ← גשר).</p>
    <p class="hint" style="margin-top:6px">ידנית: <code>bridge/bridge.py</code> עם <code>--server ${esc(base)} --pair &lt;קוד&gt;</code>. הגשר צריך גישה ל-HTTPS החוצה בלבד; שום פורט לא נפתח בראוטר.</p>`,
    actions: [{ label: 'סגור', value: true }] });
  draw();
  const t = setInterval(reload, 15000);
  return () => clearInterval(t);
}

/* ───────── הגדרות ───────── */

export async function renderSettings(container, q = {}) {
  let tab = q.tab || 'users';
  container.innerHTML = `<div class="section-head"><h2>הגדרות</h2></div>
    <div class="tabs">${[['users', 'משתמשים'], ['folders', 'תיקיות מצלמות'], ['storage', 'אחסון ומחיקה'], ['system', 'מערכת ו-cron']].map(([k, v]) => `<button class="tab ${tab === k ? 'is-on' : ''}" data-tab="${k}" type="button">${v}</button>`).join('')}</div>
    <div data-pane></div>`;
  const pane = container.querySelector('[data-pane]');
  container.querySelectorAll('[data-tab]').forEach((b) => b.onclick = () => { tab = b.dataset.tab; container.querySelectorAll('[data-tab]').forEach((x) => x.classList.toggle('is-on', x === b)); history.replaceState(null, '', '#/settings?tab=' + tab); show(); });
  const show = () => ({ users: usersTab, folders: foldersTab, storage: storageTab, system: systemTab }[tab])(pane);
  show();
}

async function usersTab(pane) {
  let users = (await api('users')).users;
  const draw = () => {
    pane.innerHTML = `<div class="actions" style="margin-bottom:10px"><button class="btn btn--primary btn--sm" data-add type="button">+ משתמש</button><span class="hint">מנהל: הכול · מפעיל: צופה, מקליט, מסובב, מקטלג · צופה: רק צופה</span></div>
      <div class="list">${users.map((u) => `<div class="item"><div class="item__main"><b>${esc(u.display_name)} <span class="badge">${esc(ROLE_LABEL[u.role])}</span> ${u.blocked ? '<span class="badge badge--err">חסום</span>' : ''}</b><small class="mono">${esc(u.username)}</small> <small>· כניסה אחרונה ${esc(timeAgo(u.last_login_at))}</small></div>
        <button class="btn btn--sm" data-edit="${u.id}" type="button">✎</button>${u.id !== state.user.id ? `<button class="btn btn--sm btn--ghost" data-del="${u.id}" type="button">✕</button>` : ''}</div>`).join('')}</div>`;
    pane.querySelector('[data-add]').onclick = () => edit(null);
    pane.querySelectorAll('[data-edit]').forEach((b) => b.onclick = () => edit(users.find((u) => u.id === +b.dataset.edit)));
    pane.querySelectorAll('[data-del]').forEach((b) => b.onclick = async () => {
      const u = users.find((x) => x.id === +b.dataset.del);
      if (!(await confirmDialog(`למחוק את המשתמש ${u.username}?`))) return;
      try { await api('user-delete', { id: u.id }); users = (await api('users')).users; draw(); } catch (err) { toast(err.message, 'err'); }
    });
  };
  const edit = async (u) => {
    const r = await modal({ title: u ? 'עריכת משתמש' : 'משתמש חדש', body: `<form data-f>
      <div class="field"><label>שם משתמש</label><input name="username" value="${esc(u?.username || '')}" ${u ? 'disabled' : 'required'} minlength="3" maxlength="32" autocapitalize="off" class="mono"></div>
      <div class="field"><label>שם תצוגה</label><input name="display_name" value="${esc(u?.display_name || '')}" maxlength="60"></div>
      <div class="field"><label>תפקיד</label><select name="role">${['admin', 'operator', 'viewer'].map((k) => `<option value="${k}" ${(u?.role || 'viewer') === k ? 'selected' : ''}>${ROLE_LABEL[k]}</option>`).join('')}</select></div>
      <div class="field"><label>סיסמה ${u ? '<span class="hint">(ריק = לא לשנות)</span>' : ''}</label><input name="password" type="password" ${u ? '' : 'required'} minlength="8" autocomplete="new-password"></div>
      ${u ? `<label class="check"><input type="checkbox" name="blocked" ${u.blocked ? 'checked' : ''}> חסום</label>` : ''}
    </form>`, actions: [{ label: 'ביטול', value: null }, { label: 'שמור', cls: 'btn--primary', onClick: async (box) => {
      const f = box.querySelector('[data-f]'); if (!f.reportValidity()) return false;
      const d = formData(f);
      if (u) await api('user-update', { id: u.id, ...d }); else await api('user-create', d);
      return true;
    } }] });
    if (r) { users = (await api('users')).users; draw(); }
  };
  draw();
}

async function foldersTab(pane) {
  const ov = await refreshOverview();
  let folders = ov.folders;
  const draw = () => {
    pane.innerHTML = `<div class="actions" style="margin-bottom:10px"><button class="btn btn--primary btn--sm" data-add type="button">+ תיקייה</button><span class="hint">למשל: בית › קומה 1 › כניסה. מצלמה משויכת לתיקייה בטופס שלה.</span></div>
      ${folders.length ? treeHtml(folders, null, (f) => `<span class="name">📁 ${esc(f.name)} <span class="badge">${ov.cameras.filter((c) => c.folder_id === f.id).length}</span></span>
        <button class="btn btn--sm btn--ghost" data-edit="${f.id}" type="button">✎</button><button class="btn btn--sm btn--ghost" data-del="${f.id}" type="button">✕</button>`) : '<div class="empty">אין תיקיות</div>'}`;
    pane.querySelector('[data-add]').onclick = () => edit(null);
    pane.querySelectorAll('[data-edit]').forEach((b) => b.onclick = () => edit(folders.find((f) => f.id === +b.dataset.edit)));
    pane.querySelectorAll('[data-del]').forEach((b) => b.onclick = async () => {
      const f = folders.find((x) => x.id === +b.dataset.del);
      if (!(await confirmDialog(`למחוק את "${f.name}"? המצלמות ותת-התיקיות יעלו לתיקיית האב.`))) return;
      try { await api('folder-delete', { id: f.id }); folders = (await refreshOverview()).folders; draw(); } catch (err) { toast(err.message, 'err'); }
    });
  };
  const edit = async (item) => {
    const d = await editNode({ title: item ? 'עריכת תיקייה' : 'תיקייה חדשה', item, items: folders });
    if (!d) return;
    try { await api('folder-save', { id: item?.id, name: d.name, parent_id: d.parent_id || null }); folders = (await refreshOverview()).folders; draw(); }
    catch (err) { toast(err.message, 'err'); }
  };
  draw();
}

async function storageTab(pane) {
  const [ov, s] = await Promise.all([refreshOverview(), api('settings')]);
  const st = ov.storage;
  const cap = s.settings.storage_cap_bytes;
  pane.innerHTML = `<div class="card">
      <div class="card__title">שימוש</div>
      <p><b>${fmtBytes(st.bytes)}</b> ב-${st.count} קבצים (${st.videos} סרטונים, ${st.snapshots} צילומים)${st.oldest ? ` · הישן ביותר: ${esc(fmtTime(st.oldest))}` : ''}</p>
      ${cap ? `<div class="meter" style="margin:8px 0"><i style="width:${Math.min(100, 100 * st.bytes / cap)}%"></i></div><p class="hint">תקרה: ${fmtBytes(cap)}</p>` : ''}
      <table class="kv" style="margin-top:8px">${st.per_camera.map((p) => `<tr><th>${esc(cameraName(p.camera_id))}</th><td>${fmtBytes(p.bytes)} · ${p.count}</td></tr>`).join('')}</table>
    </div>
    <div class="card"><div class="card__title">מדיניות מחיקה</div>
      <form data-f>
        <div class="field"><label>תקרת נפח כללית (GB; 0 = ללא)</label><input name="cap_gb" type="number" step="0.5" min="0" value="${(cap / 1073741824).toFixed(1)}"></div>
        <p class="hint" style="margin-bottom:10px">כשעוברים את התקרה נמחקות ההקלטות הישנות ביותר, חוץ מ-★ ו-🔒. ימי השמירה נקבעים לכל מצלמה בטופס שלה. ⚠ באחסון משותף (Bluehost) יש מגבלת "שימוש הוגן" — ראה README.</p>
        <div class="actions"><button class="btn btn--primary" type="submit">שמור</button><button class="btn" data-run type="button">הרץ מחיקה וסריקה עכשיו</button></div>
      </form>
    </div>`;
  pane.querySelector('[data-f]').onsubmit = async (e) => {
    e.preventDefault();
    try { await api('settings-save', { storage_cap_bytes: Math.round(+e.target.cap_gb.value * 1073741824) }); toast('נשמר', 'ok'); } catch (err) { toast(err.message, 'err'); }
  };
  pane.querySelector('[data-run]').onclick = async () => {
    try { const j = await api('maintenance'); toast(`נקלטו ${j.result?.inbox?.ingested ?? 0}, נמחקו ${j.result?.retention?.deleted ?? 0}`, 'ok'); storageTab(pane); } catch (err) { toast(err.message, 'err'); }
  };
}

async function systemTab(pane) {
  const s = (await api('settings')).settings;
  const base = location.origin + location.pathname.replace(/[^/]*$/, '');
  pane.innerHTML = `<div class="card"><div class="card__title">cron — קליטה מה-FTP גם כשאף אחד לא בדף</div>
      <p>ב-cPanel → <b>Cron Jobs</b>, כל דקה (<code>* * * * *</code>):</p>
      <pre class="mono">php -q ${esc(s.cron_path)}</pre>
      <p class="hint">או מבחוץ (למשל cron-job.org): <code dir="ltr">${esc(base)}cron.php?key=${esc(s.cron_key)}</code></p>
      <p style="margin-top:8px">ריצה אחרונה: ${s.maintenance_last_ts ? esc(timeAgo(new Date(s.maintenance_last_ts * 1000).toISOString())) : 'אף פעם'} · מחיקה אחרונה: ${s.retention_last_ts ? esc(timeAgo(new Date(s.retention_last_ts * 1000).toISOString())) : 'אף פעם'}</p>
    </div>
    <div class="card"><div class="card__title">FTP — לאן המצלמות מעלות</div>
      <p>תיקיית התיבה בשרת: <code dir="ltr">${esc(s.inbox_dir)}</code></p>
      <p class="hint">לכל מצלמה תת-תיקייה בשם המפתח שלה. ב-cPanel → FTP Accounts יוצרים חשבון שתיקיית הבית שלו היא תת-התיקייה של המצלמה — כך המצלמה רואה רק את שלה.</p>
    </div>
    <div class="card"><div class="card__title">כללי</div>
      <form data-f>
        <div class="row row--3">
          <div class="field"><label>היסט שעון המצלמות (דקות מ-UTC)</label><input name="camera_tz_offset_min" type="number" value="${s.camera_tz_offset_min}"><span class="hint">ישראל: 180 בקיץ, 120 בחורף. משפיע על פענוח זמן משם הקובץ ב-FTP</span></div>
          <div class="field"><label>אורך מקטע הקלטה בגשר (שניות)</label><input name="segment_seconds" type="number" min="10" max="600" value="${s.segment_seconds}"></div>
          <div class="field"><label>אורך מקטע חי (שניות)</label><input name="live_segment_seconds" type="number" min="1" max="6" value="${s.live_segment_seconds}"></div>
        </div>
        <div class="actions"><button class="btn btn--primary" type="submit">שמור</button></div>
      </form>
      <table class="kv" style="margin-top:12px">
        <tr><th>PHP</th><td class="mono">${esc(s.php_version)}</td></tr>
        <tr><th>GD (ממוזערות)</th><td>${s.gd ? '✓' : '✗ — לא ייווצרו ממוזערות לצילומים'}</td></tr>
        <tr><th>sodium (הצפנת סיסמאות)</th><td>${s.sodium ? '✓' : '✗ — חסר; סיסמאות מצלמה לא יישמרו'}</td></tr>
      </table>
    </div>`;
  pane.querySelector('[data-f]').onsubmit = async (e) => {
    e.preventDefault();
    try { await api('settings-save', formData(e.target)); toast('נשמר', 'ok'); } catch (err) { toast(err.message, 'err'); }
  };
}

/* ───────── החשבון שלי ───────── */

export function renderMe(container) {
  container.innerHTML = `<div class="section-head"><h2>החשבון שלי</h2></div>
    <div class="card card--auth"><table class="kv"><tr><th>שם</th><td>${esc(state.user.display_name)}</td></tr><tr><th>משתמש</th><td class="mono">${esc(state.user.username)}</td></tr><tr><th>תפקיד</th><td>${esc(ROLE_LABEL[state.user.role])}</td></tr></table>
      <form data-f style="margin-top:14px">
        <div class="field"><label>סיסמה נוכחית</label><input name="current" type="password" required autocomplete="current-password"></div>
        <div class="field"><label>סיסמה חדשה (8+)</label><input name="new" type="password" required minlength="8" autocomplete="new-password"></div>
        <div class="actions actions--end"><button class="btn btn--primary" type="submit">שנה סיסמה</button></div>
      </form></div>`;
  container.querySelector('[data-f]').onsubmit = async (e) => {
    e.preventDefault();
    try { await api('change-password', formData(e.target)); toast('הסיסמה שונתה', 'ok'); e.target.reset(); } catch (err) { toast(err.message, 'err'); }
  };
}
