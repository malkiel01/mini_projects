/**
 * הקלטות: הגלריה, ודף ההקלטה הבודדת עם הנגן והקטלוג.
 *
 * הפוסטרים לסרטונים נוצרים כאן, בדפדפן: באחסון משותף אין ffmpeg, אז
 * הלקוח הראשון שרואה סרטון בלי ממוזערת טוען אותו ברקע, לוקח פריים,
 * ושולח לשרת. פעם אחת לכל סרטון; כולם אחריו מקבלים את הממוזערת מהשרת.
 */

import { api, upload, state, esc, fmtTime, fmtDur, fmtBytes, toast, modal, confirmDialog, isAdmin, isOperator,
         TRIGGER_LABEL, cameraName, topicName, localDay, dayRange, refreshOverview, navigate } from './app.js';
import { createPlayer } from './player.js';

export const mediaUrl = (id, thumb = false) => `media.php?r=${id}${thumb ? '&t=1' : ''}`;

/* ───────── כרטיס הקלטה ───────── */

export function recCard(r, { selectable = false, selected = false } = {}) {
  const thumb = r.thumb_path || r.kind === 'snapshot' ? `<img src="${mediaUrl(r.id, true)}" loading="lazy" alt="">` : `<span class="none" data-poster="${r.id}">🎬</span>`;
  const trig = r.trigger_kind ? `<span class="badge badge--${esc(r.trigger_kind)}">${esc(TRIGGER_LABEL[r.trigger_kind])}</span>` : '';
  return `<div class="rec ${selected ? 'is-sel' : ''}" data-rec="${r.id}">
    ${selectable ? `<input type="checkbox" class="rec__sel" data-sel="${r.id}" ${selected ? 'checked' : ''} aria-label="בחר">` : ''}
    <div class="rec__thumb">${thumb}
      ${r.starred ? '<span class="star">★</span>' : ''}${r.locked && !selectable ? '<span class="lock">🔒</span>' : ''}
      <span class="kind">${r.kind === 'video' ? '🎥' : '📷'}</span>
      ${r.duration_s ? `<span class="dur">${fmtDur(r.duration_s)}</span>` : ''}
    </div>
    <div class="rec__body">
      <div class="rec__title">${esc(r.title || cameraName(r.camera_id))}</div>
      <div class="rec__meta"><span>${esc(fmtTime(r.started_at))}</span>${trig}</div>
    </div>
  </div>`;
}

/** תור יצירת פוסטרים ברקע — שניים במקביל, לא יותר. */
const posterQueue = []; let posterBusy = 0; const posterDone = new Set();
export function ensurePosters(recs) {
  for (const r of recs) {
    if (r.kind === 'video' && !r.thumb_path && !posterDone.has(r.id)) { posterDone.add(r.id); posterQueue.push(r.id); }
  }
  pumpPosters();
}
function pumpPosters() {
  while (posterBusy < 2 && posterQueue.length) {
    const id = posterQueue.shift();
    posterBusy++;
    makePoster(id).catch(() => {}).finally(() => { posterBusy--; pumpPosters(); });
  }
}
function makePoster(id) {
  return new Promise((resolve, reject) => {
    const v = document.createElement('video');
    v.muted = true; v.preload = 'metadata'; v.crossOrigin = 'anonymous';
    v.src = mediaUrl(id);
    const fail = () => { cleanup(); reject(new Error('poster')); };
    const cleanup = () => { v.removeAttribute('src'); v.load(); };
    v.onerror = fail;
    v.onloadedmetadata = () => { v.currentTime = Math.min(1, (v.duration || 2) / 2); };
    v.onseeked = () => {
      try {
        const c = document.createElement('canvas');
        const scale = Math.min(1, 480 / (v.videoWidth || 480));
        c.width = Math.round((v.videoWidth || 480) * scale); c.height = Math.round((v.videoHeight || 270) * scale);
        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        c.toBlob(async (blob) => {
          cleanup();
          if (!blob) return reject(new Error('blob'));
          try {
            await upload('poster', { recording_id: id }, blob, 'poster.jpg');
            document.querySelectorAll(`[data-poster="${id}"]`).forEach((el) => {
              el.outerHTML = `<img src="${mediaUrl(id, true)}&x=${Date.now()}" alt="">`;
            });
            resolve();
          } catch (e) { reject(e); }
        }, 'image/jpeg', 0.8);
      } catch (e) { cleanup(); reject(e); }
    };
    setTimeout(fail, 20000);
  });
}

/* ───────── גלריה ───────── */

export async function renderGallery(container, q = {}) {
  const ov = state.overview || await refreshOverview();
  const f = {
    camera_id: q.camera || '', kind: q.kind || '', trigger: q.trigger || '', topic_id: q.topic || '',
    day: q.day || '', from: q.from || '', to: q.to || '', q: q.q || '', starred: q.starred || '',
  };
  const sel = new Set();
  let recs = []; let more = true;

  container.innerHTML = `
    <div class="section-head"><h2>הקלטות וצילומים</h2><span class="spacer"></span>
      <button class="btn btn--sm" data-selmode type="button">בחירה</button></div>
    <div class="filters">
      <select name="camera_id"><option value="">כל המצלמות</option>${ov.cameras.map((c) => `<option value="${c.id}" ${String(c.id) === f.camera_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>
      <select name="kind"><option value="">וידאו וצילומים</option><option value="video" ${f.kind === 'video' ? 'selected' : ''}>וידאו</option><option value="snapshot" ${f.kind === 'snapshot' ? 'selected' : ''}>צילומים</option></select>
      <select name="trigger"><option value="">כל הטריגרים</option>${['motion', 'person', 'pet', 'vehicle', 'manual', 'continuous', 'schedule'].map((t) => `<option value="${t}" ${f.trigger === t ? 'selected' : ''}>${TRIGGER_LABEL[t]}</option>`).join('')}</select>
      <select name="topic_id"><option value="">כל הנושאים</option>${ov.topics.map((t) => `<option value="${t.id}" ${String(t.id) === f.topic_id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
      <input type="date" name="day" value="${esc(f.day)}" title="יום">
      <input type="search" name="q" value="${esc(f.q)}" placeholder="חיפוש בכותרת, תיאור, תגיות">
      <label class="check" style="margin:0"><input type="checkbox" name="starred" ${f.starred ? 'checked' : ''}> רק ★</label>
    </div>
    <div class="gallery" data-grid></div>
    <div class="actions" style="justify-content:center;margin-top:12px"><button class="btn" data-more type="button" hidden>טען עוד</button></div>
    <div class="bulkbar" data-bulk hidden></div>`;

  const grid = container.querySelector('[data-grid]');
  const moreBtn = container.querySelector('[data-more]');
  const bulk = container.querySelector('[data-bulk]');
  let selMode = false;

  function filtersToQuery() {
    const p = new URLSearchParams();
    if (f.camera_id) p.set('camera', f.camera_id);
    if (f.kind) p.set('kind', f.kind);
    if (f.trigger) p.set('trigger', f.trigger);
    if (f.topic_id) p.set('topic', f.topic_id);
    if (f.day) p.set('day', f.day);
    if (f.q) p.set('q', f.q);
    if (f.starred) p.set('starred', '1');
    const s = p.toString();
    history.replaceState(null, '', '#/recordings' + (s ? '?' + s : ''));
  }

  async function load(reset) {
    if (reset) { recs = []; more = true; grid.innerHTML = '<div class="empty">טוען…</div>'; }
    const req = { camera_id: f.camera_id || undefined, kind: f.kind || undefined, trigger: f.trigger || undefined,
                  topic_id: f.topic_id || undefined, q: f.q || undefined, starred: f.starred ? 1 : undefined, limit: 60 };
    if (f.day) Object.assign(req, dayRange(f.day)); else { if (f.from) req.from = f.from; if (f.to) req.to = f.to; }
    if (!reset && recs.length) req.before_id = recs[recs.length - 1].id;
    let j;
    try { j = await api('recordings', req); } catch (err) { grid.innerHTML = `<div class="empty">${esc(err.message)}</div>`; return; }
    more = j.recordings.length === 60;
    recs = reset ? j.recordings : recs.concat(j.recordings);
    draw();
    ensurePosters(j.recordings);
  }

  function draw() {
    if (!recs.length) {
      grid.innerHTML = `<div class="empty" style="grid-column:1/-1"><b>אין הקלטות שמתאימות</b>${ov.cameras.length ? 'כשהמצלמה תעלה קובץ ל-FTP או שהגשר יקליט — זה יופיע כאן.' : 'קודם מוסיפים מצלמה.'}</div>`;
    } else {
      grid.innerHTML = recs.map((r) => recCard(r, { selectable: selMode, selected: sel.has(r.id) })).join('');
    }
    moreBtn.hidden = !more || !recs.length;
    drawBulk();
  }

  function drawBulk() {
    bulk.hidden = !selMode;
    if (!selMode) return;
    bulk.innerHTML = `<b>${sel.size} נבחרו</b>
      <button class="btn btn--sm" data-b="all" type="button">בחר הכול</button>
      <span class="spacer"></span>
      ${isOperator() ? `
      <select data-b-topic><option value="">הוסף לנושא…</option>${ov.topics.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select>
      <button class="btn btn--sm" data-b="star" type="button">★</button>
      <button class="btn btn--sm" data-b="lock" type="button">🔒</button>` : ''}
      ${isAdmin() ? `<button class="btn btn--sm btn--danger" data-b="delete" type="button">מחק</button>` : ''}
      <button class="btn btn--sm btn--ghost" data-b="done" type="button">סיום</button>`;
    bulk.querySelector('[data-b="all"]').onclick = () => { recs.forEach((r) => sel.add(r.id)); draw(); };
    bulk.querySelector('[data-b="done"]').onclick = () => { selMode = false; sel.clear(); draw(); };
    const run = async (op, extra = {}) => {
      if (!sel.size) return toast('לא נבחר כלום');
      if (op === 'delete' && !(await confirmDialog(`למחוק ${sel.size} הקלטות? נעולות יישארו.`))) return;
      try {
        const j = await api('recordings-bulk', { ids: [...sel], op, ...extra });
        toast(`בוצע על ${j.done}`, 'ok'); sel.clear(); load(true);
      } catch (err) { toast(err.message, 'err'); }
    };
    bulk.querySelector('[data-b="star"]')?.addEventListener('click', () => run('star', { value: 1 }));
    bulk.querySelector('[data-b="lock"]')?.addEventListener('click', () => run('lock', { value: 1 }));
    bulk.querySelector('[data-b="delete"]')?.addEventListener('click', () => run('delete'));
    bulk.querySelector('[data-b-topic]')?.addEventListener('change', (e) => { if (e.target.value) run('topic-add', { topic_id: +e.target.value }); });
  }

  container.querySelector('[data-selmode]').onclick = () => { selMode = !selMode; if (!selMode) sel.clear(); draw(); };
  moreBtn.onclick = () => load(false);
  container.querySelectorAll('.filters select, .filters input').forEach((el) => {
    el.addEventListener(el.type === 'search' ? 'change' : 'change', () => {
      f[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
      filtersToQuery(); load(true);
    });
  });
  grid.addEventListener('click', (e) => {
    const cb = e.target.closest('[data-sel]');
    if (cb) { const id = +cb.dataset.sel; if (cb.checked) sel.add(id); else sel.delete(id); cb.closest('.rec').classList.toggle('is-sel', cb.checked); drawBulk(); return; }
    const card = e.target.closest('[data-rec]');
    if (!card) return;
    if (selMode) { const id = +card.dataset.rec; const c = card.querySelector('[data-sel]'); c.checked = !c.checked; if (c.checked) sel.add(id); else sel.delete(id); card.classList.toggle('is-sel', c.checked); drawBulk(); return; }
    navigate('#/recording/' + card.dataset.rec);
  });

  load(true);
}

/* ───────── דף הקלטה ───────── */

export async function renderRecording(container, { id }) {
  const ov = state.overview || await refreshOverview();
  let j;
  try { j = await api('recording', { id }); } catch (err) { container.innerHTML = `<div class="empty">${esc(err.message)}</div>`; return; }
  let r = j.recording; let bookmarks = j.bookmarks;
  const cam = ov.cameras.find((c) => c.id === r.camera_id);
  const day = localDay(new Date(r.started_at));

  container.innerHTML = `
    <div class="section-head">
      <a class="btn btn--sm btn--ghost" href="#/camera/${r.camera_id}?day=${day}">← ${esc(cam?.name || 'מצלמה')}</a>
      <h2 data-title>${esc(r.title || (r.kind === 'video' ? 'הקלטה' : 'צילום'))}</h2>
      <span class="badge">${esc(fmtTime(r.started_at))}</span>
      ${r.trigger_kind ? `<span class="badge badge--${esc(r.trigger_kind)}">${esc(TRIGGER_LABEL[r.trigger_kind])}</span>` : ''}
      <span class="spacer"></span>
      <button class="btn btn--sm" data-prev type="button" title="הקודמת">⏴</button>
      <button class="btn btn--sm" data-next type="button" title="הבאה">⏵</button>
    </div>
    <div class="rec-page">
      <div>
        <div data-player></div>
        <p class="hint" style="margin-top:6px">קיצורים: <span class="kbd">רווח</span> נגן · <span class="kbd">,</span> <span class="kbd">.</span> פריים · <span class="kbd">J</span> <span class="kbd">L</span> מהירות · <span class="kbd">+</span> <span class="kbd">-</span> זום · <span class="kbd">S</span> צילום מסך · <span class="kbd">B</span> סימנייה</p>
        <div class="card" style="margin-top:12px">
          <div class="card__title">סימניות <span class="spacer"></span>${isOperator() ? '<button class="btn btn--sm" data-bm-add type="button">+ בנקודה הנוכחית</button>' : ''}</div>
          <div data-bms></div>
        </div>
        <div class="card" data-shots-card hidden>
          <div class="card__title">צילומי מסך מההקלטה הזו</div>
          <div class="gallery" data-shots></div>
        </div>
      </div>
      <div>
        <div class="card">
          <form data-meta>
            <div class="field"><label>כותרת</label><input name="title" value="${esc(r.title)}" maxlength="200" ${isOperator() ? '' : 'disabled'}></div>
            <div class="field"><label>תיאור</label><textarea name="description" ${isOperator() ? '' : 'disabled'}>${esc(r.description)}</textarea></div>
            <div class="field"><label>תגיות (מופרדות בפסיק)</label><input name="tags" value="${esc(r.tags.join(', '))}" ${isOperator() ? '' : 'disabled'}></div>
            <div class="field"><span class="label">תיקיות נושא</span>
              ${ov.topics.length ? ov.topics.map((t) => `<label class="check"><input type="checkbox" name="topic_${t.id}" ${r.topics.includes(t.id) ? 'checked' : ''} ${isOperator() ? '' : 'disabled'}> ${esc(t.name)}</label>`).join('') : '<span class="hint">אין עדיין נושאים — יוצרים במסך "נושאים"</span>'}
            </div>
            <label class="check"><input type="checkbox" name="starred" ${r.starred ? 'checked' : ''} ${isOperator() ? '' : 'disabled'}> ★ חשוב</label>
            <label class="check"><input type="checkbox" name="locked" ${r.locked ? 'checked' : ''} ${isOperator() ? '' : 'disabled'}> 🔒 נעול — לא נמחק אוטומטית</label>
            ${isOperator() ? '<div class="actions actions--end"><button class="btn btn--primary" type="submit">שמור</button></div>' : ''}
          </form>
        </div>
        <div class="card">
          <table class="kv">
            <tr><th>מצלמה</th><td><a href="#/camera/${r.camera_id}">${esc(cam?.name || '')}</a></td></tr>
            <tr><th>צולם</th><td>${esc(fmtTime(r.started_at))}</td></tr>
            ${r.duration_s ? `<tr><th>משך</th><td>${fmtDur(r.duration_s)}</td></tr>` : ''}
            ${r.width ? `<tr><th>רזולוציה</th><td class="mono">${r.width}×${r.height}</td></tr>` : ''}
            <tr><th>גודל</th><td>${fmtBytes(r.size_bytes)}</td></tr>
            <tr><th>מקור</th><td>${{ ftp: 'המצלמה (FTP)', bridge: 'הגשר', player: 'צילום מסך מהנגן', upload: 'העלאה' }[r.source] || r.source}</td></tr>
            ${r.parent_id ? `<tr><th>מתוך</th><td><a href="#/recording/${r.parent_id}">הקלטה #${r.parent_id}</a></td></tr>` : ''}
            <tr><th>קובץ</th><td class="mono" style="word-break:break-all">${esc(r.path)}</td></tr>
          </table>
          <div class="actions" style="margin-top:10px">
            <a class="btn btn--sm" href="${mediaUrl(r.id)}&dl=1">⬇ הורדה</a>
            ${isAdmin() ? '<button class="btn btn--sm btn--danger" data-del type="button">מחק</button>' : ''}
          </div>
        </div>
      </div>
    </div>`;

  const player = createPlayer(container.querySelector('[data-player]'), {
    src: mediaUrl(r.id), kind: r.kind === 'video' ? 'video' : 'image', poster: r.thumb_path ? mediaUrl(r.id, true) : undefined,
    bookmarks, muted: false,
    onScreenshot: isOperator() ? async (blob, at) => {
      try {
        const j = await upload('screenshot', { recording_id: r.id, at: at.toFixed(2) }, blob, 'shot.jpg');
        toast('צילום המסך נשמר', 'ok');
        shots.unshift(j.recording); drawShots();
      } catch (err) { toast(err.message, 'err'); }
    } : null,
    onBookmark: isOperator() ? (at) => addBookmark(at) : null,
  });

  const bmsEl = container.querySelector('[data-bms]');
  function drawBms() {
    bmsEl.innerHTML = bookmarks.length ? bookmarks.map((b) => `<div class="bm"><time data-seek="${b.at_seconds}">${fmtDur(b.at_seconds)}</time><span class="spacer">${esc(b.note)}</span>${isOperator() ? `<button class="btn btn--sm btn--ghost" data-bm-del="${b.id}" type="button">✕</button>` : ''}</div>`).join('') : '<p class="hint">אין סימניות. לחיצה על B בזמן הצפייה מוסיפה.</p>';
    player.setBookmarks(bookmarks);
  }
  async function addBookmark(at) {
    const note = await modal({ title: 'סימנייה ב-' + fmtDur(at), body: '<div class="field"><label>הערה</label><input name="note" maxlength="500"></div>',
      actions: [{ label: 'ביטול', value: null }, { label: 'הוסף', cls: 'btn--primary', onClick: (box) => box.querySelector('[name=note]').value || '' }] });
    if (note === null) return;
    try {
      const j = await api('bookmark-add', { recording_id: r.id, at, note });
      bookmarks.push({ id: j.id, at_seconds: at, note }); bookmarks.sort((a, b) => a.at_seconds - b.at_seconds); drawBms();
    } catch (err) { toast(err.message, 'err'); }
  }
  bmsEl.addEventListener('click', async (e) => {
    const s = e.target.closest('[data-seek]'); if (s) { player.seek(+s.dataset.seek); player.play(); return; }
    const d = e.target.closest('[data-bm-del]');
    if (d) { try { await api('bookmark-delete', { id: +d.dataset.bmDel }); bookmarks = bookmarks.filter((b) => b.id !== +d.dataset.bmDel); drawBms(); } catch (err) { toast(err.message, 'err'); } }
  });
  container.querySelector('[data-bm-add]')?.addEventListener('click', () => addBookmark(player.currentTime()));
  drawBms();

  // צילומי מסך שנעשו מההקלטה הזו.
  let shots = [];
  const shotsCard = container.querySelector('[data-shots-card]');
  const shotsEl = container.querySelector('[data-shots]');
  function drawShots() { shotsCard.hidden = !shots.length; shotsEl.innerHTML = shots.map((s) => recCard(s)).join(''); }
  shotsEl.addEventListener('click', (e) => { const c = e.target.closest('[data-rec]'); if (c) navigate('#/recording/' + c.dataset.rec); });
  if (r.kind === 'video') {
    api('recordings', { camera_id: r.camera_id, kind: 'snapshot', from: r.started_at, to: new Date(new Date(r.started_at).getTime() + ((r.duration_s || 3600) + 1) * 1000).toISOString(), limit: 100 })
      .then((j) => { shots = j.recordings.filter((s) => s.parent_id === r.id); drawShots(); }).catch(() => {});
  }

  container.querySelector('[data-meta]').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const topics = ov.topics.filter((t) => fd.get('topic_' + t.id)).map((t) => t.id);
    try {
      const j = await api('recording-update', { id: r.id, title: fd.get('title'), description: fd.get('description'), tags: fd.get('tags'),
        topics, starred: !!fd.get('starred'), locked: !!fd.get('locked') });
      r = j.recording;
      container.querySelector('[data-title]').textContent = r.title || (r.kind === 'video' ? 'הקלטה' : 'צילום');
      toast('נשמר', 'ok');
    } catch (err) { toast(err.message, 'err'); }
  });

  container.querySelector('[data-del]')?.addEventListener('click', async () => {
    if (!(await confirmDialog('למחוק את ההקלטה? הקובץ יימחק מהשרת.'))) return;
    try { await api('recording-delete', { id: r.id }); toast('נמחקה', 'ok'); navigate('#/camera/' + r.camera_id + '?day=' + day); }
    catch (err) { toast(err.message, 'err'); }
  });

  // הקודמת/הבאה באותה מצלמה.
  const nav = async (dir) => {
    const req = { camera_id: r.camera_id, limit: 1 };
    if (dir < 0) { req.to = r.started_at; req.order = 'desc'; } else { req.from = new Date(new Date(r.started_at).getTime() + 1000).toISOString(); req.order = 'asc'; }
    try { const j = await api('recordings', req); if (j.recordings.length) navigate('#/recording/' + j.recordings[0].id); else toast(dir < 0 ? 'זו הראשונה' : 'זו האחרונה'); }
    catch (err) { toast(err.message, 'err'); }
  };
  container.querySelector('[data-prev]').onclick = () => nav(-1);
  container.querySelector('[data-next]').onclick = () => nav(1);

  return () => player.destroy();
}
