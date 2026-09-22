/**
 * מצלמות: מסך הבית (כל המצלמות לפי תיקיות), ודף המצלמה — חי, PTZ,
 * ציר זמן יומי, וההקלטות של היום.
 *
 * "חי" עובד רק דרך גשר: הדפדפן קורא ל-live כל 8 שניות (וזה מה שאומר
 * לגשר להמשיך לשדר), ומרגע שיש פלייליסט — הנגן מתחבר אליו.
 */

import { api, upload, state, esc, fmtTime, fmtDur, toast, modal, confirmDialog, formData, isAdmin, isOperator,
         TRIGGER_LABEL, MODE_LABEL, localDay, dayRange, refreshOverview, navigate, timeAgo } from './app.js';
import { createPlayer } from './player.js';
import { recCard, ensurePosters, mediaUrl } from './views-recordings.js';

/* ───────── עץ תיקיות ───────── */

function folderPath(folders, id) {
  const parts = []; let cur = folders.find((f) => f.id === id); let guard = 0;
  while (cur && guard++ < 20) { parts.unshift(cur.name); cur = folders.find((f) => f.id === cur.parent_id); }
  return parts.join(' › ');
}

/* ───────── מסך הבית ───────── */

export async function renderHome(container) {
  let ov;
  try { ov = await refreshOverview(); } catch (err) { container.innerHTML = `<div class="empty">${esc(err.message)}</div>`; return; }

  const draw = () => {
    const groups = new Map();
    for (const c of ov.cameras) {
      const k = c.folder_id ? folderPath(ov.folders, c.folder_id) : '';
      if (!groups.has(k)) groups.set(k, []);
      groups.get(k).push(c);
    }
    const noBridge = ov.cameras.length && !ov.cameras.some((c) => c.bridge_id);
    container.innerHTML = `
      <div class="section-head"><h2>המצלמות</h2><span class="spacer"></span>
        ${isAdmin() ? '<button class="btn btn--primary btn--sm" data-add type="button">+ מצלמה</button>' : ''}
        ${ov.cameras.length ? '<a class="btn btn--sm" href="#/recordings">כל ההקלטות</a>' : ''}
      </div>
      ${!ov.cameras.length ? `<div class="empty"><b>אין עדיין מצלמות</b>${isAdmin() ? 'לחץ "+ מצלמה". צריך רק שם — הכתובת והסיסמה נכנסות כשמחברים גשר, וה-FTP עובד גם בלעדיהן.' : 'המנהל טרם הוסיף מצלמות.'}</div>` : ''}
      ${noBridge && isAdmin() ? `<p class="hint" style="margin-bottom:10px">💡 בלי גשר, המצלמות מעלות לבד ב-FTP (אירועים וצילומים). שידור חי, PTZ והקלטה יזומה — כשיש <a href="#/bridges">גשר</a>.</p>` : ''}
      ${[...groups.entries()].sort(([a], [b]) => a.localeCompare(b, 'he')).map(([name, cams]) => `
        ${name ? `<div class="folder-title">📁 ${esc(name)}</div>` : (groups.size > 1 ? '<div class="folder-title">ללא תיקייה</div>' : '')}
        <div class="cams">${cams.map(camCard).join('')}</div>`).join('')}`;
    container.querySelector('[data-add]')?.addEventListener('click', async () => { if (await openCameraForm(null)) { ov = await refreshOverview(); draw(); } });
  };

  const camCard = (c) => `<a class="cam" href="#/camera/${c.id}">
    <div class="cam__thumb">
      ${c.last_snapshot_id ? `<img src="${mediaUrl(c.last_snapshot_id, true)}" alt="">` : '<span class="none">📷</span>'}
      <div class="cam__tag"><span class="badge"><span class="dot dot--${esc(c.status)}"></span> ${{ online: 'מחובר', offline: 'מנותק', unknown: c.bridge_id ? 'ממתין לגשר' : 'FTP' }[c.status]}</span>
        ${c.has_ptz ? '<span class="badge">PTZ</span>' : ''}</div>
      ${c.manual_recording ? '<span class="cam__rec badge badge--err">● REC</span>' : (c.live_active ? '<span class="cam__rec badge" style="color:var(--live)">● חי</span>' : '')}
    </div>
    <div class="cam__body"><span class="cam__name">${esc(c.name)}</span><span class="cam__meta">${esc(MODE_LABEL[c.record_mode])}</span></div>
  </a>`;

  draw();
  const t = setInterval(async () => { try { ov = await refreshOverview(); draw(); } catch {} }, 30000);
  return () => clearInterval(t);
}

/* ───────── טופס מצלמה ───────── */

const DAYS = ['א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ש'];

export async function openCameraForm(cam) {
  const ov = state.overview || await refreshOverview();
  let bridges = ov.bridges || [];
  const c = cam || { name: '', key: '', folder_id: null, bridge_id: null, brand: 'reolink', host: '', rtsp_port: 554, http_port: 80, onvif_port: 8000,
    username: 'admin', stream_main: '', stream_sub: '', has_ptz: 0, has_audio: 1, record_audio: 0, record_mode: 'motion', schedule: [],
    pre_seconds: 10, post_seconds: 20, retention_days: 30, notes: '', has_password: 0 };
  const opt = (v, t, cur) => `<option value="${v}" ${String(v) === String(cur ?? '') ? 'selected' : ''}>${esc(t)}</option>`;
  const body = `<form data-cam>
    <div class="tabs"><button class="tab is-on" type="button" data-t="basic">בסיס</button><button class="tab" type="button" data-t="conn">חיבור</button><button class="tab" type="button" data-t="rec">הקלטה</button><button class="tab" type="button" data-t="ftp">FTP</button></div>
    <div data-pane="basic">
      <div class="row row--2">
        <div class="field"><label>שם</label><input name="name" value="${esc(c.name)}" required maxlength="80"></div>
        <div class="field"><label>מפתח (באנגלית; שם תיקיית ה-FTP)</label><input name="key" value="${esc(c.key)}" ${cam ? 'disabled' : ''} placeholder="נוצר אוטומטית" pattern="[a-z0-9][a-z0-9\\-_]{1,31}" class="mono"></div>
      </div>
      <div class="row row--2">
        <div class="field"><label>תיקייה</label><select name="folder_id">${opt('', '— ללא —', c.folder_id)}${ov.folders.map((f) => opt(f.id, folderPath(ov.folders, f.id), c.folder_id)).join('')}</select></div>
        <div class="field"><label>גשר</label><select name="bridge_id">${opt('', '— ללא (FTP בלבד) —', c.bridge_id)}${bridges.map((b) => opt(b.id, b.name + (b.online ? ' ●' : ' ○'), c.bridge_id)).join('')}</select></div>
      </div>
      <label class="check"><input type="checkbox" name="has_ptz" ${c.has_ptz ? 'checked' : ''}> מצלמה מסתובבת (PTZ) / זום</label>
      <label class="check"><input type="checkbox" name="has_audio" ${c.has_audio ? 'checked' : ''}> יש מיקרופון</label>
      <label class="check"><input type="checkbox" name="record_audio" ${c.record_audio ? 'checked' : ''}> להקליט שמע <span class="hint">(הקלטת שמע של אנשים ללא ידיעתם רגישה חוקית)</span></label>
      <div class="field"><label>הערות</label><textarea name="notes">${esc(c.notes)}</textarea></div>
    </div>
    <div data-pane="conn" hidden>
      <p class="hint" style="margin-bottom:10px">הפרטים האלה משמשים את הגשר בלבד. בלי גשר אפשר להשאיר ריק.</p>
      <div class="row row--2">
        <div class="field"><label>סוג</label><select name="brand">${opt('reolink', 'Reolink', c.brand)}${opt('onvif', 'ONVIF כללי', c.brand)}${opt('rtsp', 'RTSP ידני', c.brand)}</select></div>
        <div class="field"><label>כתובת IP / שם</label><input name="host" value="${esc(c.host)}" placeholder="192.168.1.118" class="mono" dir="ltr"></div>
      </div>
      <div class="row row--3">
        <div class="field"><label>RTSP</label><input name="rtsp_port" type="number" value="${c.rtsp_port}" min="1" max="65535"></div>
        <div class="field"><label>HTTP</label><input name="http_port" type="number" value="${c.http_port}" min="1" max="65535"></div>
        <div class="field"><label>ONVIF</label><input name="onvif_port" type="number" value="${c.onvif_port}" min="1" max="65535"></div>
      </div>
      <div class="row row--2">
        <div class="field"><label>משתמש</label><input name="username" value="${esc(c.username)}" class="mono" dir="ltr"></div>
        <div class="field"><label>סיסמה ${c.has_password ? '<span class="hint">(שמורה; ריק = לא לשנות)</span>' : ''}</label><input name="password" type="password" autocomplete="new-password" dir="ltr"></div>
      </div>
      <details><summary class="hint" style="cursor:pointer">כתובות זרם ידניות (רק אם ברירת המחדל לא מתאימה)</summary>
        <div class="field"><label>זרם ראשי</label><input name="stream_main" value="${esc(c.stream_main)}" class="mono" dir="ltr" placeholder="rtsp://…"></div>
        <div class="field"><label>זרם משני</label><input name="stream_sub" value="${esc(c.stream_sub)}" class="mono" dir="ltr"></div>
      </details>
    </div>
    <div data-pane="rec" hidden>
      <div class="field"><span class="label">מצב הקלטה (דרך הגשר)</span>
        ${Object.entries(MODE_LABEL).map(([k, v]) => `<label class="check"><input type="radio" name="record_mode" value="${k}" ${c.record_mode === k ? 'checked' : ''}> ${v}</label>`).join('')}
        <p class="hint">בלי גשר: המצלמה מעלה לבד לפי ההגדרות שלה באפליקציית היצרן (FTP באירוע).</p>
      </div>
      <div data-sched ${c.record_mode === 'schedule' ? '' : 'hidden'}>
        <div class="field"><span class="label">חלונות לו״ז</span><div data-windows></div><button class="btn btn--sm" type="button" data-addwin>+ חלון</button></div>
      </div>
      <div class="row row--3">
        <div class="field"><label>שניות לפני אירוע</label><input name="pre_seconds" type="number" value="${c.pre_seconds}" min="0" max="60"></div>
        <div class="field"><label>שניות אחרי</label><input name="post_seconds" type="number" value="${c.post_seconds}" min="0" max="300"></div>
        <div class="field"><label>ימי שמירה (0 = לנצח)</label><input name="retention_days" type="number" value="${c.retention_days}" min="0" max="3650"></div>
      </div>
      <p class="hint">הקלטות עם ★ או 🔒 לא נמחקות אוטומטית.</p>
    </div>
    <div data-pane="ftp" hidden>
      <p>כדי שהמצלמה תעלה לכאן בעצמה, באפליקציית Reolink: הגדרות ← <b>FTP</b>:</p>
      <table class="kv">
        <tr><th>שרת</th><td class="mono" dir="ltr">${esc(location.hostname)}</td></tr>
        <tr><th>פורט</th><td class="mono">21</td></tr>
        <tr><th>משתמש / סיסמה</th><td>חשבון FTP שנוצר ב-cPanel עם תיקיית בית: <code data-ftp-path>…/camera-app/data/inbox/${esc(c.key || '<מפתח>')}</code></td></tr>
        <tr><th>מה להעלות</th><td>וידאו + תמונה, באירוע (תנועה/אדם)</td></tr>
      </table>
      <p class="hint" style="margin-top:8px">הנתיב המלא בשרת מופיע במסך ההגדרות. הקבצים נקלטים אוטומטית תוך דקה מסיום ההעלאה.</p>
    </div>
  </form>`;

  let windows = Array.isArray(c.schedule) ? c.schedule.map((w) => ({ ...w })) : [];
  return modal({ title: cam ? 'עריכת מצלמה' : 'מצלמה חדשה', body, wide: true,
    onOpen: (box) => {
      box.querySelectorAll('[data-t]').forEach((b) => b.onclick = () => {
        box.querySelectorAll('[data-t]').forEach((x) => x.classList.toggle('is-on', x === b));
        box.querySelectorAll('[data-pane]').forEach((p) => { p.hidden = p.dataset.pane !== b.dataset.t; });
      });
      box.querySelectorAll('[name=record_mode]').forEach((r) => r.onchange = () => { box.querySelector('[data-sched]').hidden = r.value !== 'schedule'; });
      const winEl = box.querySelector('[data-windows]');
      const drawWin = () => {
        winEl.innerHTML = windows.map((w, i) => `<div class="item" style="margin-bottom:6px"><span>${DAYS.map((d, di) => `<label class="check" style="display:inline-flex;margin:0 4px 0 0"><input type="checkbox" data-w="${i}" data-d="${di}" ${w.days.includes(di) ? 'checked' : ''}>${d}</label>`).join('')}</span>
          <input type="time" value="${esc(w.from)}" data-wfrom="${i}"> – <input type="time" value="${esc(w.to)}" data-wto="${i}">
          <button class="btn btn--sm btn--ghost" type="button" data-wdel="${i}">✕</button></div>`).join('') || '<p class="hint">אין חלונות — מקליט תמיד.</p>';
      };
      drawWin();
      winEl.addEventListener('change', (e) => {
        const t = e.target;
        if (t.dataset.w !== undefined) { const w = windows[+t.dataset.w]; const d = +t.dataset.d; w.days = t.checked ? [...new Set([...w.days, d])].sort() : w.days.filter((x) => x !== d); }
        if (t.dataset.wfrom !== undefined) windows[+t.dataset.wfrom].from = t.value;
        if (t.dataset.wto !== undefined) windows[+t.dataset.wto].to = t.value;
      });
      winEl.addEventListener('click', (e) => { const d = e.target.closest('[data-wdel]'); if (d) { windows.splice(+d.dataset.wdel, 1); drawWin(); } });
      box.querySelector('[data-addwin]').onclick = () => { windows.push({ days: [0, 1, 2, 3, 4, 5, 6], from: '22:00', to: '06:00' }); drawWin(); };
      box.querySelector('[name=key]')?.addEventListener('input', (e) => { box.querySelector('[data-ftp-path]').textContent = `…/camera-app/data/inbox/${e.target.value || '<מפתח>'}`; });
    },
    actions: [{ label: 'ביטול', value: null }, { label: cam ? 'שמור' : 'צור', cls: 'btn--primary', onClick: async (box) => {
      const form = box.querySelector('[data-cam]');
      if (!form.reportValidity()) return false;
      const data = formData(form);
      data.schedule = windows;
      if (cam) { data.id = cam.id; delete data.key; }
      const j = await api('camera-save', data);
      toast(cam ? 'נשמר' : 'המצלמה נוצרה', 'ok');
      return j.camera;
    } }] });
}

/* ───────── דף מצלמה ───────── */

export async function renderCamera(container, { id, day, rec }) {
  let cam;
  try { cam = (await api('camera', { id })).camera; } catch (err) { container.innerHTML = `<div class="empty">${esc(err.message)}</div>`; return; }
  const ov = state.overview || await refreshOverview();
  let curDay = day || localDay();
  let segments = []; let dayRecs = [];
  let playing = null;          // ההקלטה שמנוגנת עכשיו, או null = חי/צילום אחרון
  let liveTimer = null, liveAttached = false, statusTimer = null;

  container.innerHTML = `
    <div class="section-head">
      <a class="btn btn--sm btn--ghost" href="#/">←</a>
      <h2>${esc(cam.name)}</h2>
      <span class="badge" data-status><span class="dot dot--${esc(cam.status)}"></span> ${{ online: 'מחובר', offline: 'מנותק', unknown: cam.bridge_id ? 'ממתין לגשר' : 'FTP בלבד' }[cam.status]}</span>
      <span class="spacer"></span>
      ${isOperator() && cam.bridge_id ? `<button class="btn btn--sm btn--rec ${cam.manual_recording ? 'is-on' : ''}" data-rec type="button">● REC</button>
        <button class="btn btn--sm" data-snap type="button">📷 צלם</button>` : ''}
      ${isAdmin() ? `<button class="btn btn--sm" data-edit type="button">⚙</button>` : ''}
    </div>
    <div class="cam-page">
      <div>
        <div data-player></div>
        <div class="actions" style="margin-top:8px">
          <button class="btn btn--sm btn--live" data-backlive type="button" hidden>◀ חזרה לחי</button>
          <span class="hint" data-playing></span>
        </div>
        ${cam.has_ptz && cam.bridge_id && isOperator() ? `
        <div class="card" style="margin-top:12px">
          <div class="card__title">שליטה</div>
          <div class="ptz">
            <span></span><button class="btn" data-ptz="up" type="button">▲</button><span></span>
            <button class="btn" data-ptz="left" type="button">◀</button><button class="btn" data-ptz="stop" type="button" title="עצור">■</button><button class="btn" data-ptz="right" type="button">▶</button>
            <span></span><button class="btn" data-ptz="down" type="button">▼</button><span></span>
          </div>
          <div class="ptz__zoom"><button class="btn" data-zoom="out" type="button">🔍−</button><button class="btn" data-zoom="in" type="button">🔍+</button></div>
          <div class="presets">${[1, 2, 3, 4].map((n) => `<button class="btn btn--sm" data-preset="${n}" type="button" title="לחיצה ארוכה שומרת את העמדה הנוכחית">עמדה ${n}</button>`).join('')}</div>
          <p class="hint" style="text-align:center;margin-top:6px">לחיצה ארוכה על "עמדה" שומרת אותה</p>
        </div>` : ''}
        <div class="card" style="margin-top:12px">
          <div class="daynav">
            <button class="btn btn--sm" data-day="-1" type="button">◀</button>
            <input type="date" data-date value="${curDay}">
            <button class="btn btn--sm" data-day="1" type="button">▶</button>
            <button class="btn btn--sm btn--ghost" data-day="0" type="button">היום</button>
            <span class="spacer"></span><span class="hint" data-count></span>
          </div>
          <div class="tl" data-tl><div class="tl__hours">${Array.from({ length: 24 }, (_, h) => `<span>${h}</span>`).join('')}</div></div>
          <div class="tl__legend"><span><i style="background:var(--person)"></i>אדם/חיה/רכב</span><span><i style="background:var(--motion)"></i>תנועה</span><span><i style="background:var(--manual)"></i>ידני/רציף/לו״ז</span><span><i style="background:var(--ink)"></i>צילום</span></div>
          <div class="gallery" data-daylist style="margin-top:12px"></div>
        </div>
      </div>
      <div>
        <div class="card">
          <table class="kv">
            <tr><th>מצב הקלטה</th><td>${esc(MODE_LABEL[cam.record_mode])}</td></tr>
            <tr><th>גשר</th><td>${cam.bridge_id ? esc(ov.bridges?.find((b) => b.id === cam.bridge_id)?.name || '#' + cam.bridge_id) : 'אין — FTP בלבד'}</td></tr>
            ${cam.host ? `<tr><th>כתובת</th><td class="mono" dir="ltr">${esc(cam.host)}</td></tr>` : ''}
            <tr><th>נראתה לאחרונה</th><td>${esc(timeAgo(cam.last_seen_at))}</td></tr>
            <tr><th>שמירה</th><td>${cam.retention_days ? cam.retention_days + ' ימים' : 'לנצח'}</td></tr>
            ${cam.notes ? `<tr><th>הערות</th><td>${esc(cam.notes)}</td></tr>` : ''}
          </table>
          <div class="actions" style="margin-top:10px"><a class="btn btn--sm" href="#/recordings?camera=${cam.id}">כל ההקלטות</a>
            ${isAdmin() ? '<button class="btn btn--sm btn--danger" data-del type="button">מחק מצלמה</button>' : ''}</div>
        </div>
        <div class="card"><div class="card__title">אירועים אחרונים</div><div data-events class="hint">טוען…</div></div>
      </div>
    </div>`;

  const player = createPlayer(container.querySelector('[data-player]'), {
    muted: true,
    onScreenshot: isOperator() ? async (blob) => {
      if (playing) {
        try { await upload('screenshot', { recording_id: playing.id, at: player.currentTime().toFixed(2) }, blob, 'shot.jpg'); toast('צילום המסך נשמר', 'ok'); loadDay(); }
        catch (err) { toast(err.message, 'err'); }
      } else {
        // בחי אין הקלטת-אם; שומרים דרך הגשר (צילום איכותי מהמצלמה עצמה).
        sendCommand('snapshot', {}, 'המצלמה צילמה');
      }
    } : null,
    onEnded: () => playNext(),
  });

  /* ───── חי ───── */

  const showIdle = () => {
    if (cam.last_snapshot_id) player.setSrc(mediaUrl(cam.last_snapshot_id), 'image');
    else player.setSrc('', 'image');
    player.showOverlay(cam.bridge_id
      ? (cam.status === 'offline' ? '<b>המצלמה מנותקת</b>הגשר לא מצליח להגיע אליה' : '<b>ממתין לשידור מהגשר…</b>עד ~15 שניות')
      : '<b>אין שידור חי</b>המצלמה מעלה ל-FTP בלבד. חי, PTZ והקלטה יזומה — דרך גשר.' + (cam.last_snapshot_id ? '<br>מוצג הצילום האחרון.' : ''));
    liveAttached = false;
  };

  async function pollLive() {
    if (playing) return;
    try {
      const j = await api('live', { id: cam.id });
      if (j.live.available) {
        if (!liveAttached) { player.setSrc(j.live.playlist + '&x=' + Date.now(), 'live'); liveAttached = true; }
      } else {
        if (liveAttached || !player.kind) showIdle();
        if (!j.live.bridge_online && cam.bridge_id) player.showOverlay('<b>הגשר לא מחובר</b>בדוק שהקופסה דולקת ומחוברת לרשת');
      }
    } catch {}
  }

  if (cam.bridge_id) { showIdle(); pollLive(); liveTimer = setInterval(pollLive, 8000); }
  else showIdle();

  // מצב המצלמה מתעדכן כל 15 שניות.
  statusTimer = setInterval(async () => {
    try {
      const c = (await api('camera', { id })).camera;
      const changed = c.status !== cam.status || c.manual_recording !== cam.manual_recording;
      cam = c;
      if (changed) {
        container.querySelector('[data-status]').innerHTML = `<span class="dot dot--${esc(cam.status)}"></span> ${{ online: 'מחובר', offline: 'מנותק', unknown: 'ממתין' }[cam.status]}`;
        container.querySelector('[data-rec]')?.classList.toggle('is-on', !!cam.manual_recording);
      }
    } catch {}
  }, 15000);

  /* ───── פקודות ───── */

  async function sendCommand(type, payload = {}, okMsg = null) {
    try {
      const j = await api('command', { id: cam.id, type, payload });
      if (okMsg) {
        // מחכים לתוצאה עד 10 שניות.
        for (let i = 0; i < 10; i++) {
          await new Promise((r) => setTimeout(r, 1000));
          const s = (await api('command-status', { id: j.command_id })).command;
          if (s.status === 'done') { toast(okMsg, 'ok'); loadDay(); return; }
          if (s.status === 'failed') { toast('נכשל: ' + (s.result?.error || ''), 'err'); return; }
        }
        toast('הגשר לא ענה בזמן', 'err');
      }
    } catch (err) { toast(err.message, 'err'); }
  }

  container.querySelector('[data-rec]')?.addEventListener('click', async (e) => {
    try {
      const j = await api('record', { id: cam.id, on: !cam.manual_recording });
      cam = j.camera; e.currentTarget.classList.toggle('is-on', !!cam.manual_recording);
      toast(cam.manual_recording ? 'מקליט' : 'ההקלטה נעצרה', 'ok');
    } catch (err) { toast(err.message, 'err'); }
  });
  container.querySelector('[data-snap]')?.addEventListener('click', () => sendCommand('snapshot', {}, 'הצילום נשמר'));

  // PTZ: לחיצה מתחילה תנועה, שחרור עוצר.
  container.querySelectorAll('[data-ptz]').forEach((b) => {
    const dir = b.dataset.ptz;
    if (dir === 'stop') { b.onclick = () => sendCommand('ptz_stop'); return; }
    let down = false;
    b.addEventListener('pointerdown', (e) => { e.preventDefault(); down = true; sendCommand('ptz', { dir }); });
    const up = () => { if (down) { down = false; sendCommand('ptz_stop'); } };
    b.addEventListener('pointerup', up); b.addEventListener('pointerleave', up); b.addEventListener('pointercancel', up);
  });
  container.querySelectorAll('[data-zoom]').forEach((b) => {
    let down = false;
    b.addEventListener('pointerdown', (e) => { e.preventDefault(); down = true; sendCommand('zoom', { dir: b.dataset.zoom }); });
    const up = () => { if (down) { down = false; sendCommand('ptz_stop'); } };
    b.addEventListener('pointerup', up); b.addEventListener('pointerleave', up); b.addEventListener('pointercancel', up);
  });
  container.querySelectorAll('[data-preset]').forEach((b) => {
    let t = null, long = false;
    b.addEventListener('pointerdown', () => { long = false; t = setTimeout(() => { long = true; sendCommand('preset_set', { id: +b.dataset.preset }, 'העמדה נשמרה'); }, 800); });
    const up = () => { clearTimeout(t); };
    b.addEventListener('pointerup', up); b.addEventListener('pointerleave', up);
    b.addEventListener('click', () => { if (!long) sendCommand('preset_goto', { id: +b.dataset.preset }); });
  });

  container.querySelector('[data-edit]')?.addEventListener('click', async () => {
    const c = await openCameraForm(cam);
    if (c) { await refreshOverview().catch(() => {}); renderCamera(container, { id, day: curDay }); }
  });
  container.querySelector('[data-del]')?.addEventListener('click', async () => {
    if (!(await confirmDialog(`למחוק את "${cam.name}" עם כל ההקלטות שלה?`))) return;
    try { await api('camera-delete', { id: cam.id }); toast('נמחקה', 'ok'); await refreshOverview().catch(() => {}); navigate('#/'); }
    catch (err) { toast(err.message, 'err'); }
  });

  /* ───── ציר זמן ויום ───── */

  const tl = container.querySelector('[data-tl]');
  const dayList = container.querySelector('[data-daylist]');
  const dateInp = container.querySelector('[data-date]');

  async function loadDay() {
    dateInp.value = curDay;
    history.replaceState(null, '', `#/camera/${cam.id}?day=${curDay}`);
    try {
      const r = dayRange(curDay);
      const j = await api('recordings', { camera_id: cam.id, from: r.from, to: r.to, limit: 500, order: 'asc' });
      dayRecs = j.recordings; segments = dayRecs;
    } catch (err) { toast(err.message, 'err'); return; }
    drawTimeline(); drawList();
    ensurePosters(dayRecs);
    container.querySelector('[data-count]').textContent = dayRecs.length ? `${dayRecs.filter((r) => r.kind === 'video').length} סרטונים · ${dayRecs.filter((r) => r.kind === 'snapshot').length} צילומים` : 'אין הקלטות ביום הזה';
  }

  function drawTimeline() {
    tl.querySelectorAll('.tl__seg, .tl__now').forEach((s) => s.remove());
    const dayStart = new Date(curDay + 'T00:00:00').getTime();
    for (const s of segments) {
      const t0 = (new Date(s.started_at).getTime() - dayStart) / 86400000;
      const w = Math.max(0.0005, (s.duration_s || 0) / 86400);
      const el = document.createElement('div');
      el.className = 'tl__seg' + (s.kind === 'snapshot' ? ' tl__seg--snapshot' : (s.trigger_kind ? ' tl__seg--' + s.trigger_kind : '')) + (playing?.id === s.id ? ' is-on' : '');
      el.style.right = (100 * t0) + '%'; el.style.width = (100 * w) + '%';
      el.title = fmtTime(s.started_at, false) + (s.duration_s ? ' · ' + fmtDur(s.duration_s) : '') + (s.trigger_kind ? ' · ' + TRIGGER_LABEL[s.trigger_kind] : '');
      el.dataset.id = s.id;
      tl.appendChild(el);
    }
    if (curDay === localDay()) {
      const now = document.createElement('div'); now.className = 'tl__now';
      now.style.right = (100 * (Date.now() - dayStart) / 86400000) + '%';
      tl.appendChild(now);
    }
  }
  function drawList() {
    dayList.innerHTML = dayRecs.length ? [...dayRecs].reverse().map((r) => recCard(r)).join('') : '';
    dayList.querySelectorAll('[data-rec]').forEach((c) => c.classList.toggle('is-sel', playing?.id === +c.dataset.rec));
  }

  function playRec(r) {
    playing = r;
    if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
    liveAttached = false;
    player.setSrc(mediaUrl(r.id), r.kind === 'video' ? 'video' : 'image', { poster: r.thumb_path ? mediaUrl(r.id, true) : undefined, autoplay: true });
    container.querySelector('[data-backlive]').hidden = false;
    container.querySelector('[data-playing]').innerHTML = `${esc(fmtTime(r.started_at))} ${r.trigger_kind ? '· ' + esc(TRIGGER_LABEL[r.trigger_kind]) : ''} · <a href="#/recording/${r.id}">פתח בקטלוג</a>`;
    drawTimeline(); drawList();
  }
  function playNext() {
    if (!playing) return;
    const i = segments.findIndex((s) => s.id === playing.id);
    const next = segments.slice(i + 1).find((s) => s.kind === 'video');
    if (next) playRec(next);
  }
  function backToLive() {
    playing = null;
    container.querySelector('[data-backlive]').hidden = true;
    container.querySelector('[data-playing]').textContent = '';
    showIdle();
    if (cam.bridge_id && !liveTimer) { pollLive(); liveTimer = setInterval(pollLive, 8000); }
    drawTimeline(); drawList();
  }
  container.querySelector('[data-backlive]').onclick = backToLive;

  tl.addEventListener('click', (e) => {
    const seg = e.target.closest('.tl__seg');
    if (seg) { const r = segments.find((s) => s.id === +seg.dataset.id); if (r) playRec(r); return; }
    // לחיצה על מקום ריק: ההקלטה הקרובה ביותר אחרי הנקודה.
    const rect = tl.getBoundingClientRect();
    const frac = 1 - (e.clientX - rect.left) / rect.width;   // RTL: 0 בימין
    const t = new Date(curDay + 'T00:00:00').getTime() + frac * 86400000;
    const r = segments.find((s) => s.kind === 'video' && new Date(s.started_at).getTime() >= t) || [...segments].reverse().find((s) => s.kind === 'video');
    if (r) playRec(r);
  });
  dayList.addEventListener('click', (e) => { const c = e.target.closest('[data-rec]'); if (c) { const r = dayRecs.find((x) => x.id === +c.dataset.rec); if (r) playRec(r); } });
  container.querySelectorAll('[data-day]').forEach((b) => b.onclick = () => {
    const n = +b.dataset.day;
    if (n === 0) curDay = localDay();
    else { const d = new Date(curDay + 'T12:00:00'); d.setDate(d.getDate() + n); curDay = localDay(d); }
    loadDay();
  });
  dateInp.onchange = () => { if (dateInp.value) { curDay = dateInp.value; loadDay(); } };

  await loadDay();
  if (rec) { const r = dayRecs.find((x) => x.id === +rec); if (r) playRec(r); }

  api('events', { camera_id: cam.id, limit: 20 }).then((j) => {
    const el = container.querySelector('[data-events]');
    el.className = '';
    el.innerHTML = j.events.length ? j.events.map((ev) => `<div class="ev ev--${esc(ev.level)}"><time>${esc(fmtTime(ev.at, false))}</time><span class="ev__msg">${esc(evLabel(ev))}</span></div>`).join('') : '<p class="hint">אין אירועים</p>';
  }).catch(() => {});

  return () => { clearInterval(liveTimer); clearInterval(statusTimer); player.destroy(); };
}

export function evLabel(ev) {
  const k = ev.kind;
  const m = ev.message ? ' — ' + ev.message : '';
  const map = { 'camera.online': 'המצלמה התחברה', 'camera.offline': 'המצלמה התנתקה', 'record.start': 'הקלטה ידנית החלה', 'record.stop': 'הקלטה ידנית נעצרה',
    'detect.motion': 'תנועה', 'detect.person': 'זוהה אדם', 'detect.pet': 'זוהתה חיה', 'detect.vehicle': 'זוהה רכב', ingest: 'קליטה מה-FTP',
    'ingest.error': 'שגיאת קליטה', retention: 'מחיקה לפי מדיניות', 'bridge.online': 'הגשר התחבר', 'bridge.paired': 'גשר צומד', 'bridge.create': 'גשר נוצר',
    'bridge.delete': 'גשר נמחק', 'camera.create': 'מצלמה נוספה', 'camera.update': 'מצלמה עודכנה', 'camera.delete': 'מצלמה נמחקה', 'recording.delete': 'הקלטה נמחקה',
    login: 'כניסה', setup: 'התקנה', 'server.error': 'שגיאת שרת' };
  return (map[k] || k) + m;
}
