// אפליקציית מתכונים — צד הדפדפן.
//
// אין ספרייה, יש ניתוב לפי hash ורינדור ל-innerHTML דרך פונקציית בריחה
// אחת. כל טקסט שמגיע מהשרת — שם מתכון, רכיב, תגובה — נכתב על ידי מישהו
// שאיני מכיר, ולכן הוא עובר דרך esc() בלי יוצא מן הכלל.

// ───────────────────────── תשתית ─────────────────────────

const api = async (action, payload) => {
  const res = await fetch(`./api.php?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload || {}),
  });
  let data;
  try { data = await res.json(); }
  catch { throw new Error('השרת החזיר תשובה שאינה תקינה'); }
  if (!data.success) { const e = new Error(data.error || 'שגיאה לא מזוהה'); e.status = res.status; throw e; }
  return data;
};

// שגיאות JavaScript נרשמות ביומן השרת — כי "אצלי בטלפון זה לא עובד"
// הוא הדיווח הכי נפוץ והכי פחות שימושי. עד 5 לטעינת דף, כדי שלולאה
// שבורה לא תציף את היומן.
let clientLogBudget = 5;
const clientLog = (message, where) => {
  if (clientLogBudget-- <= 0) return;
  fetch('./api.php?action=client-log', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
    body: JSON.stringify({ level: 'error', message: String(message).slice(0, 500), where: String(where || '').slice(0, 200), hash: location.hash }),
  }).catch(() => {});
};
window.addEventListener('error', (e) => clientLog(e.message, `${e.filename || ''}:${e.lineno || 0}`));
window.addEventListener('unhandledrejection', (e) => clientLog(e.reason?.message || e.reason, 'promise'));

// הגרסה של הקוד הזה — מה-?v= שבו נטען. השרת מדווח ב-me מה הגרסה
// שבשרת; כשהן שונות, הטאב הזה ישן (טלפון שמחזיק טאב פתוח ימים) ומוצגת
// שורה "יש גרסה חדשה". בלי זה המשתמש רואה באגים שכבר תוקנו.
const MY_VERSION = new URL(import.meta.url).searchParams.get('v') || '';
function checkVersion(serverVersion) {
  if (!serverVersion || !MY_VERSION || serverVersion === MY_VERSION) return;
  if ($('#stale')) return;
  const bar = document.createElement('div');
  bar.id = 'stale'; bar.className = 'stale';
  bar.innerHTML = '<span>יש גרסה חדשה של האפליקציה.</span> <button class="btn btn--primary" type="button">רענן</button>';
  bar.querySelector('button').addEventListener('click', () => location.reload());
  document.body.prepend(bar);
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') api('me').then((r) => checkVersion(r.assets_version)).catch(() => {});
});

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

const UNITS = {
  gram: 'גרם', kg: 'ק"ג', ml: 'מ"ל', liter: 'ליטר', cup: 'כוס',
  tbsp: 'כף', tsp: 'כפית', unit: 'יחידה', package: 'חבילה', pinch: 'קורט',
};
const DIFFICULTY = { easy: 'קל', medium: 'בינוני', hard: 'מאתגר' };
const AXES = { topic: 'נושא', method: 'סוג', kosher: 'כשרות', suitable: 'מתאים ל', cuisine: 'מטבח' };

const state = { user: null, tags: null, products: [] };

// ───────────────────────── מספרים ─────────────────────────

/**
 * הצגת כמות אחרי המרה (3.3): שבר למחצית ולרבע, עיגול לשאר.
 * 4.5 → "4½", 2.25 → "2¼", 2.33 → "2", 0.5 → "½".
 */
function fmtAmount(n) {
  if (n == null || Number.isNaN(n)) return '';
  const whole = Math.floor(n);
  const frac = n - whole;
  const glyph = frac >= 0.875 ? null
    : frac >= 0.625 ? '¾' : frac >= 0.375 ? '½' : frac >= 0.125 ? '¼' : '';
  if (glyph === null) return String(whole + 1);
  if (whole === 0 && glyph) return glyph;
  return `${whole}${glyph}`;
}

function scaled(ing, factor) {
  if (ing.amount_min == null || !ing.unit) return null;
  const min = fmtAmount(ing.amount_min * factor);
  const max = ing.amount_max != null ? fmtAmount(ing.amount_max * factor) : null;
  return `${min}${max ? `–${max}` : ''} ${UNITS[ing.unit] || ing.unit}`;
}

function minutes(m) {
  if (!m) return '';
  if (m < 60) return `${m} דק׳`;
  const h = Math.floor(m / 60), r = m % 60;
  return r ? `${h} ש׳ ${r} דק׳` : `${h} ש׳`;
}

// ───────────────────────── מדיה ─────────────────────────

const humanBytes = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)}MB` : `${Math.round(n / 1024)}KB`);

/**
 * הקטנת תמונה בדפדפן לפני העלאה (3.4): צלע ארוכה 1600px, JPEG באיכות 0.85.
 * תמונה מהטלפון שוקלת 4–8MB ומעלה בלי סיבה; אחרי ההקטנה — 200–400KB.
 * GIF ו-PNG שקוף נשלחים כמו שהם, כי ההמרה ל-JPEG הורסת אותם.
 */
async function shrinkImage(file, maxSide = 1600) {
  if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024) return file;
  const bitmap = await createImageBitmap(file).catch(() => null);
  if (!bitmap) return file;
  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
  if (scale === 1 && file.type === 'image/jpeg') return file;
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', 0.85));
  // אם ההקטנה לא עזרה (תמונה קטנה עם דחיסה טובה), המקור עדיף.
  return blob && blob.size < file.size ? new File([blob], 'image.jpg', { type: 'image/jpeg' }) : file;
}

/** העלאה עם התקדמות. fetch אינו מדווח התקדמות, ולכן XHR. */
function uploadFile(recipeId, file, onProgress) {
  return new Promise((resolve, reject) => {
    const fd = new FormData();
    fd.append('recipe_id', recipeId);
    fd.append('file', file);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', './upload.php');
    xhr.withCredentials = true;
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
    xhr.onload = () => {
      let data;
      try { data = JSON.parse(xhr.responseText); } catch { return reject(new Error('השרת החזיר תשובה שאינה תקינה')); }
      data.success ? resolve(data) : reject(new Error(data.error || 'ההעלאה נכשלה'));
    };
    xhr.onerror = () => reject(new Error('ההעלאה נכשלה — אין חיבור'));
    xhr.send(fd);
  });
}

/** קישור יוטיוב → כתובת הטמעה. כל קישור אחר נשאר קישור רגיל. */
function embedUrl(url) {
  const m = url.match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/))([\w-]{11})/);
  if (m) return `https://www.youtube-nocookie.com/embed/${m[1]}`;
  const v = url.match(/vimeo\.com\/(?:video\/)?(\d{6,12})/);
  return v ? `https://player.vimeo.com/video/${v[1]}?dnt=1` : null;
}

/** סרטון אחד לתצוגה: קובץ שלנו או קישור ישיר לקובץ — נגן; יוטיוב/וימאו — מוטמע; אחר — קישור. */
function videoHtml(url, uploaded = false) {
  if (uploaded || /\.(mp4|webm|mov)(\?|$)/i.test(url)) {
    return `<video class="gallery__video" controls playsinline preload="metadata" src="${esc(url)}"></video>`;
  }
  const emb = embedUrl(url);
  return emb
    ? `<iframe class="gallery__video" src="${esc(emb)}" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin" title="סרטון"></iframe>`
    : `<a class="btn" href="${esc(url)}" target="_blank" rel="noopener">▶ סרטון (קישור חיצוני)</a>`;
}

function mediaGallery(r) {
  if (!r.media.length) return '';
  const main = r.media.find((m) => m.id === r.main_media_id) || r.media.find((m) => m.kind === 'image');
  const images = r.media.filter((m) => m.kind === 'image' && m !== main);
  const videos = r.media.filter((m) => m.kind === 'video');
  return `
    <section class="gallery">
      ${main ? `<img class="gallery__main" src="${esc(main.url)}" alt="${esc(r.title)}" loading="lazy">` : ''}
      ${images.length ? `<div class="gallery__thumbs">${images.map((m) =>
        `<a href="${esc(m.url)}" target="_blank" rel="noopener"><img src="${esc(m.url)}" alt="" loading="lazy"></a>`).join('')}</div>` : ''}
      ${videos.map((v) => videoHtml(v.url, v.source === 'upload')).join('')}
    </section>`;
}

// ───────────────────────── מסך אורח ─────────────────────────

const guest = $('#guest');
const app = $('#app');
const msg = $('#guest-msg');

function say(text, kind) {
  msg.textContent = text;
  msg.className = 'note' + (kind ? ` note--${kind}` : '');
  msg.hidden = !text;
}

function showPane(name) {
  $$('.pane').forEach((p) => { p.hidden = p.dataset.pane !== name; });
  $$('.tab').forEach((t) => t.classList.toggle('is-on', t.dataset.pane === name));
  say('');
}

async function withButton(form, fn) {
  const btn = form.querySelector('button[type="submit"]');
  const label = btn.textContent;
  btn.disabled = true; btn.textContent = 'רגע…';
  try { await fn(); }
  catch (err) { say(err.message, 'err'); }
  finally { btn.disabled = false; btn.textContent = label; }
}

$$('.tab').forEach((t) => t.addEventListener('click', () => showPane(t.dataset.pane)));
$$('[data-goto]').forEach((b) => b.addEventListener('click', () => showPane(b.dataset.goto)));
$('#forgot').addEventListener('click', () => showPane('forgot'));

$('#login-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const f = e.target;
  withButton(f, async () => {
    try {
      const { user } = await api('login', { username: f.username.value, password: f.password.value });
      f.reset();
      setUser(user);
      go('#/');
    } catch (err) {
      // חשבון שטרם אומת: במקום שגיאה בלבד, כפתור שמאפשר לצאת מהמצב הזה.
      // בלעדיו מי שהמייל שלו אבד תקוע בלי שום דרך להמשיך.
      if (!/טרם אומת/.test(err.message)) throw err;
      say(err.message, 'warn');
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'link'; btn.textContent = 'שלח שוב את דוא"ל האימות';
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try { say((await api('resend-verification', { username: f.username.value })).message, 'ok'); }
        catch (e2) { say(e2.message, 'err'); }
      });
      msg.append(document.createElement('br'), btn);
    }
  });
});

$('#register-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const f = e.target;
  withButton(f, async () => {
    const res = await api('register', {
      username: f.username.value, display_name: f.display_name.value,
      email: f.email.value, password: f.password.value,
    });
    f.reset();
    showPane('login');
    say(res.message, res.verified ? 'ok' : (res.mail_sent ? 'ok' : 'warn'));
  });
});

$('#forgot-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const f = e.target;
  withButton(f, async () => {
    const res = await api('request-reset', { email: f.email.value });
    f.reset(); showPane('login'); say(res.message, 'ok');
  });
});

$('#logout').addEventListener('click', async () => {
  try { await api('logout'); }
  finally { setUser(null); showPane('login'); }
});

function setUser(user) {
  state.user = user;
  const on = Boolean(user);
  guest.hidden = on;
  app.hidden = !on;
  $('#who').hidden = !on;
  $('#logout').hidden = !on;
  $('#menu').hidden = !on;
  $('#bell').hidden = !on;
  $$('[data-dev]').forEach((a) => { a.hidden = !(on && user.is_developer); });
  if (on) { $('#who').textContent = user.display_name || user.username; refreshBell(); }
}

// ───────────────────────── ניתוב ─────────────────────────

const view = $('#view');
const go = (hash) => { location.hash = hash; };

async function route() {
  if (!state.user) return;
  // כל ניווט סוגר את התפריט — אחרת הוא נשאר פתוח מעל המסך החדש.
  // לפני ה-try, כדי שגם ניווט שנכשל יסגור אותו.
  const menu = $('#menu');
  if (menu) menu.open = false;
  const h = location.hash || '#/';
  let m;
  try {
    if (h === '#/' || h === '' || h.startsWith('#/?')) await renderList(listParams(h));
    else if ((m = h.match(/^#\/r\/(\d+)$/))) await renderRecipe(+m[1]);
    else if (h === '#/new') await renderEditor(null);
    else if (h === '#/import' || h.startsWith('#/import?')) await renderImport(new URLSearchParams(h.split('?')[1] || '').get('url') || '');
    else if ((m = h.match(/^#\/edit\/(\d+)$/))) await renderEditor(+m[1]);
    else if (h === '#/favorites') await renderFavorites();
    else if (h === '#/collections') await renderCollections();
    else if (h === '#/foryou') await renderForYou();
    else if (h === '#/activity') await renderActivity();
    else if ((m = h.match(/^#\/c\/(\d+)$/))) await renderCollection(+m[1]);
    else if (h === '#/pantry') await renderPantry();
    else if (h === '#/shopping') await renderShoppingLists();
    else if ((m = h.match(/^#\/shopping\/(\d+)$/))) await renderShoppingList(+m[1]);
    else if ((m = h.match(/^#\/cook\/(\d+)$/))) await renderCook(+m[1]);
    else if (h === '#/diag') await renderDiag();
    else if (h === '#/logs') await renderLogs();
    else if (h === '#/logs/import') await renderImportLog();
    else if (h === '#/scout') await renderScout();
    else if (h === '#/index') await renderIndexSites();
    else if (h === '#/rewrite') await renderRewrite();
    else if (h === '#/scan') await renderScan();
    else if (h === '#/people') await renderPeople();
    else if (h === '#/circles') await renderCircles();
    else if ((m = h.match(/^#\/circle\/(\d+)$/))) await renderCircle(+m[1]);
    else if (h === '#/reports') await renderReports();
    else if ((m = h.match(/^#\/u\/(\d+)$/))) await renderPerson(+m[1]);
    else if (h === '#/scans') await renderScansDev();
    else if (h === '#/web' || h.startsWith('#/web?')) { const p = new URLSearchParams(h.split('?')[1] || ''); await renderWebSearch(p.get('q') || '', p.get('topic') || ''); }
    else if ((m = h.match(/^#\/web\/(\d+)$/))) await renderWebRecipe(+m[1]);
    else if (h === '#/settings') await renderSettingsPrivate();
    else if (h === '#/settings/public') await renderSettingsPublic();
    else if (h === '#/settings/users') await renderUsers();
    else go('#/');
  } catch (err) {
    view.innerHTML = `<section class="card"><p class="note note--err">${esc(err.message)}</p>
      <a class="btn" href="#/">לרשימה</a></section>`;
  }
}
window.addEventListener('hashchange', route);

/** 🔔 — כמה חדש במרכז הפעילות. בכל ניווט (וכשחוזרים ללשונית); כשל — פשוט לא מתעדכן. */
async function refreshBell() {
  if (!state.user) return;
  try {
    const { unread } = await api('activity-count');
    const b = $('#bell-n');
    b.textContent = unread > 9 ? '9+' : unread;
    b.hidden = !unread;
  } catch { /* לא חשוב */ }
}
window.addEventListener('hashchange', () => { if (location.hash !== '#/activity') refreshBell(); });
document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshBell(); });

/** "לפני 5 דקות" / "אתמול" / תאריך. */
function ago(iso) {
  const s = (Date.now() - Date.parse(iso)) / 1000;
  if (s < 60) return 'עכשיו';
  if (s < 3600) return `לפני ${Math.floor(s / 60)} דק׳`;
  if (s < 86400) return `לפני ${Math.floor(s / 3600)} ש׳`;
  if (s < 2 * 86400) return 'אתמול';
  if (s < 7 * 86400) return `לפני ${Math.floor(s / 86400)} ימים`;
  return iso.slice(0, 10).split('-').reverse().join('.');
}

const ACTIVITY_ICON = { share: '👥', collection: '📁', reco: '📨', follow: '➕', published: '🆕', comment: '💬', reply: '↩️' };

async function renderActivity() {
  const d = await api('activity');
  const b = $('#bell-n'); b.hidden = true; b.textContent = '';
  view.innerHTML = `
    <section class="card activity">
      <a class="link link--back" href="#/">‹ לרשימה</a>
      <h2>🔔 פעילות</h2>
      ${d.items.length ? `<ul class="act-list">${d.items.map((it) => `
        <li class="act ${it.new ? 'act--new' : ''}">
          <a class="act__who" href="#/u/${it.actor.id}" aria-label="${esc(it.actor.name)}"><span class="avatar" aria-hidden="true">${esc((it.actor.name || '?').charAt(0))}</span>
            <span class="act__icon" aria-hidden="true">${ACTIVITY_ICON[it.kind] || '•'}</span></a>
          <a class="act__main" href="${esc(it.link)}">
            <span><strong>${esc(it.actor.name)}</strong> ${esc(it.text)}${it.title ? `: <strong>${esc(it.title)}</strong>` : ''}</span>
            ${it.snippet ? `<span class="act__snippet">“${esc(it.snippet)}”</span>` : ''}
            <span class="muted small">${ago(it.at)}</span>
          </a>
        </li>`).join('')}</ul>`
        : '<p class="muted">אין עדיין פעילות. כשמישהו ישתף איתך, ימליץ, יעקוב אחריך או יגיב על מתכון שלך — זה יופיע כאן.</p>'}
    </section>`;
}

async function ensureTags() {
  if (!state.tags) state.tags = (await api('tags')).tags;
  return state.tags;
}

// ───────────────────────── בשבילך (SPEC 15.5) ─────────────────────────

/** לחיצה על המלצה — נרשמת (למדידה), והניווט ממשיך כרגיל. */
function fyClick(e) {
  const a = e.target.closest('[data-fy]');
  if (!a) return;
  const [kind, id] = a.dataset.fy.split(':');
  if (kind !== 'reco') api('event', { kind: 'foryou_click', [kind === 'web' ? 'entry_id' : 'recipe_id']: +id }).catch(() => {});
}
const fyKey = (it) => it.kind === 'web' ? `web:${it.entry_id}` : it.kind === 'reco' ? `reco:${it.reco_id}` : `recipe:${it.id}`;

/** שורת "בשבילך" בבית: כרטיסים שנגללים לרוחב, עם הסיבה. */
async function drawForYouRow(box) {
  const d = await api('foryou', { limit: 10 });
  if (!d.items.length && !d.needs_taste) return;
  box.hidden = false;
  box.innerHTML = `
    <div class="fy-row__head"><h3>✨ בשבילך</h3><a class="link link--inline small" href="#/foryou">הכול ›</a></div>
    <div class="fy-cards">
      ${d.needs_taste ? `<a class="fy-card fy-card--taste" href="#/foryou"><span class="fy-card__img">🎯</span>
        <span class="fy-card__body"><strong>מה אתה אוהב?</strong><span class="fy-card__why">בחר נושאים — ונמליץ בהתאם</span></span></a>` : ''}
      ${d.items.map((it) => `
        <a class="fy-card" href="${esc(it.open)}" data-fy="${fyKey(it)}">
          <span class="fy-card__img">${it.thumb ? `<img src="${esc(it.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : (it.kind === 'web' ? '🌐' : '🍲')}</span>
          <span class="fy-card__body"><strong>${esc(it.title)}</strong><span class="fy-card__why">${esc(it.reason)}</span></span>
        </a>`).join('')}
    </div>`;
  box.addEventListener('click', fyClick);
  box.addEventListener('error', (e) => { if (e.target.tagName === 'IMG') e.target.remove(); }, true);
}

/** בחירת טעם: נושאים (מעדיפים) ותזונה (מסננת). */
function tasteForm(taste, tags, icons) {
  return `
    <form id="taste" class="share-box taste">
      <h3>🎯 מה אתה אוהב?</h3>
      <p class="muted small">נושאים — כדי שנדע מה להציע קודם (כדאי 3 לפחות).</p>
      <div class="chips">${(tags.topic || []).map((t) => `<label class="chip chip--pick"><input type="checkbox" name="topic" value="${t.id}" ${taste.topics.includes(t.id) ? 'checked' : ''}>
        ${topicIcon(t.name, icons)} ${esc(t.name)}</label>`).join('')}</div>
      <p class="muted small">תזונה — <strong>מסננת</strong>: נציע רק מה שמתאים (טבעוני לא יקבל בשר).</p>
      <div class="chips">${(tags.suitable || []).map((t) => `<label class="chip chip--pick"><input type="checkbox" name="diet" value="${t.id}" ${taste.diet.includes(t.id) ? 'checked' : ''}> ${esc(t.name)}</label>`).join('')}</div>
      <div class="actions"><button class="btn btn--primary" type="submit">שמור</button></div>
    </form>`;
}

async function renderForYou() {
  const [d, tags, counts] = await Promise.all([api('foryou', { limit: 30 }), ensureTags(), api('tag-counts').catch(() => ({ icons: {} }))]);
  view.innerHTML = `
    <section class="card fy-page">
      <a class="link link--back" href="#/">‹ לרשימה</a>
      <h2>✨ בשבילך</h2>
      <p class="muted small">לפי מה ששמרת, בישלת ופתחת, מי שאתה עוקב אחריו${d.season.length ? `, ו${d.season.join(' ו')}` : ''}.
        <button class="link link--inline" type="button" id="taste-open">🎯 מה אני אוהב</button></p>
      ${d.personalize ? '' : '<p class="note note--warn small">ההתאמה האישית כבויה — מוצגים רק עונה ופופולרי. <a class="link link--inline" href="#/settings">להדליק ›</a></p>'}
      <div id="taste-slot"></div>
    </section>
    <section class="list" id="fy-list">${d.items.length ? d.items.map((it) => `
      <div class="item fy-item" data-key="${fyKey(it)}">
        <a class="item__media" href="${esc(it.open)}" data-fy="${fyKey(it)}">${it.thumb
          ? `<img class="item__thumb" src="${esc(it.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="item__thumb item__thumb--empty">${it.kind === 'web' ? '🌐' : '🍲'}</span>`}</a>
        <a class="item__main" href="${esc(it.open)}" data-fy="${fyKey(it)}">
          <strong>${esc(it.title)}</strong>
          <span class="fy-why">${esc(it.reason)}</span>
          <span class="muted small">${it.kind === 'web' ? '🌐 ' : ''}${esc(it.by || '')}</span>
        </a>
        ${it.kind !== 'reco' ? `<details class="actbar__more fy-more"><summary class="btn btn--ghost btn--small" aria-label="עוד">⋯</summary>
          <div class="more-menu">
            <button type="button" data-not="${fyKey(it)}">✕ לא מעניין</button>
            ${it.topic_id ? `<button type="button" data-less="${it.topic_id}">פחות ${esc(it.topic)}</button>` : ''}
          </div></details>` : ''}
      </div>`).join('') : '<p class="muted">עוד אין מה להציע. שומרים ומבשלים — וההמלצות מתחילות.</p>'}</section>`;

  const slot = $('#taste-slot');
  const openTaste = () => {
    slot.innerHTML = tasteForm(d.taste, tags, counts.icons || {});
    $('#taste').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      try {
        await api('foryou-taste', { topics: $$('[name="topic"]:checked', f).map((c) => +c.value), diet: $$('[name="diet"]:checked', f).map((c) => +c.value) });
        renderForYou();
      } catch (err) { alert(err.message); }
    });
  };
  $('#taste-open').addEventListener('click', () => { if (slot.innerHTML) slot.innerHTML = ''; else openTaste(); });
  if (d.needs_taste) openTaste();

  const list = $('#fy-list');
  list.addEventListener('click', async (e) => {
    fyClick(e);
    const not = e.target.closest('[data-not]');
    const less = e.target.closest('[data-less]');
    if (!not && !less) return;
    try {
      if (not) {
        const [kind, id] = not.dataset.not.split(':');
        await api('event', { kind: 'not_interested', [kind === 'web' ? 'entry_id' : 'recipe_id']: +id });
        not.closest('.fy-item').remove();
      } else {
        await api('event', { kind: 'less_topic', tag_id: +less.dataset.less });
        renderForYou();
      }
    } catch (err) { alert(err.message); }
  });
  list.addEventListener('error', (e) => { if (e.target.tagName === 'IMG') e.target.remove(); }, true);
}

// ───────────────────────── קטגוריות (SPEC 15.1) ─────────────────────────

const TIME_CHOICES = [15, 30, 45, 60, 90, 120, 180];

/** מצב הרשימה בכתובת: #/?s=following&q=…&topic=3&tags=12,15&d=easy&m=60 — "חזור" עובד, ואפשר לשלוח קישור. */
function listParams(h) {
  const p = new URLSearchParams(h.split('?')[1] || '');
  const ids = (v) => [...new Set((v || '').split(',').map(Number).filter((n) => n > 0))];
  return {
    s: ['following', 'shared', 'web'].includes(p.get('s')) ? p.get('s') : 'all',
    q: (p.get('q') || '').slice(0, 120),
    topic: +p.get('topic') || 0,
    tags: ids(p.get('tags')),
    d: DIFFICULTY[p.get('d')] ? p.get('d') : '',
    m: +p.get('m') > 0 ? +p.get('m') : 0,
  };
}
function listHash(f) {
  const p = new URLSearchParams();
  if (f.s !== 'all') p.set('s', f.s);
  if (f.q) p.set('q', f.q);
  if (f.topic) p.set('topic', f.topic);
  if (f.tags.length) p.set('tags', f.tags.join(','));
  if (f.d) p.set('d', f.d);
  if (f.m) p.set('m', f.m);
  const qs = p.toString().replaceAll('%2C', ',');
  return '#/' + (qs ? '?' + qs : '');
}
const topicIcon = (name, icons) => icons[name] || '🏷';

/** גיליון הסינון: כשרות (אחד), מתאים ל (כמה — וגם), סוג ומטבח (אחד), קושי, זמן כולל. */
function filterSheet(f, tags, counts) {
  const n = (id) => (counts[id] ? `<span class="muted small">${counts[id]}</span>` : '');
  const one = (axis) => `
    <fieldset class="filter__axis"><legend>${AXES[axis]}</legend>
      <label class="chip chip--pick"><input type="radio" name="${axis}" value="" ${!(tags[axis] || []).some((t) => f.tags.includes(t.id)) ? 'checked' : ''}> הכול</label>
      ${(tags[axis] || []).map((t) => `<label class="chip chip--pick"><input type="radio" name="${axis}" value="${t.id}" ${f.tags.includes(t.id) ? 'checked' : ''}> ${esc(t.name)} ${n(t.id)}</label>`).join('')}
    </fieldset>`;
  return `
    <form id="filter-sheet" class="filter-sheet" hidden>
      ${one('kosher')}
      <fieldset class="filter__axis"><legend>${AXES.suitable} <span class="muted small">(אפשר כמה)</span></legend>
        ${(tags.suitable || []).map((t) => `<label class="chip chip--pick"><input type="checkbox" name="suitable" value="${t.id}" ${f.tags.includes(t.id) ? 'checked' : ''}> ${esc(t.name)} ${n(t.id)}</label>`).join('')}
      </fieldset>
      ${one('method')}
      ${one('cuisine')}
      <fieldset class="filter__axis"><legend>קושי</legend>
        <label class="chip chip--pick"><input type="radio" name="d" value="" ${!f.d ? 'checked' : ''}> הכול</label>
        ${Object.entries(DIFFICULTY).map(([k, v]) => `<label class="chip chip--pick"><input type="radio" name="d" value="${k}" ${f.d === k ? 'checked' : ''}> ${v}</label>`).join('')}
      </fieldset>
      <label class="filter__time">זמן כולל עד
        <select name="m"><option value="">ללא הגבלה</option>${TIME_CHOICES.map((m) => `<option value="${m}" ${f.m === m ? 'selected' : ''}>${minutes(m)}</option>`).join('')}</select>
      </label>
      <div class="actions">
        <button class="btn btn--primary" type="submit">הצג</button>
        <button class="btn" type="button" id="filter-clear">נקה</button>
        <button class="btn btn--ghost" type="button" id="filter-close">סגור</button>
      </div>
    </form>`;
}

// ───────────────────────── רשימה וחיפוש ─────────────────────────

async function renderList(f = listParams('')) {
  const filtered = !!(f.topic || f.tags.length || f.d || f.m);
  const nFilters = f.tags.length + (f.d ? 1 : 0) + (f.m ? 1 : 0);
  const tab = (s, label) => `<a href="${listHash({ ...f, s })}" class="${f.s === s ? 'is-on' : ''}">${label}</a>`;
  view.innerHTML = `
    <section class="toolbar">
      <form id="search" class="search" role="search">
        <input name="q" type="search" placeholder="חיפוש בשם או ברכיב…" autocomplete="off" value="${esc(f.q)}">
        <button class="btn btn--primary search__go" type="submit" aria-label="חפש" title="חפש">🔍</button>
      </form>
      <!-- פעולות מהירות — שורה אחת של גלולות שנגללת לרוחב, לא כפתורים גדולים שנשברים לשורות -->
      <nav class="quick" aria-label="פעולות">
        <a class="quick__btn quick__btn--primary" href="#/new">＋ מתכון חדש</a>
        <a class="quick__btn" href="#/web">🔎 ברשת</a>
        <a class="quick__btn" href="#/pantry">🥕 מה יש בבית</a>
        <a class="quick__btn" href="#/scan">📷 סריקה</a>
        <a class="quick__btn" href="#/collections">📁 אוספים</a>
      </nav>
      <div class="topics">
        <button class="topic topic--filter ${nFilters ? 'is-on' : ''}" type="button" id="filter-open" aria-expanded="false" aria-label="סינון">⚙︎${nFilters ? ` ${nFilters}` : ' סינון'}</button>
        <span id="topics" class="topics__list"></span>
      </div>
      <div class="filters" ${filtered ? '' : 'hidden'}><span id="filter-chips" class="filters__chips"></span></div>
      <div id="filter-slot"></div>
      <nav class="subnav subnav--list" aria-label="סינון">
        ${tab('all', 'הכול')}
        ${tab('following', 'נעקבים')}
        <a href="${listHash({ ...f, s: 'shared' })}" class="${f.s === 'shared' ? 'is-on' : ''}">שותפו איתי <span class="count-badge" id="shared-new" hidden></span></a>
        ${tab('web', 'מהרשת')}
      </nav>
    </section>
    <section id="fy-row" class="fy-row" hidden></section>
    <section id="results" class="list"></section>`;

  const results = $('#results');
  const scope = f.s;
  const q = f.q;
  results.innerHTML = '<p class="muted">טוען…</p>';

  // נושאים וסינון — מהתגים ומהמונים; כשל כאן לא מפיל את הרשימה
  Promise.all([ensureTags(), api('tag-counts')]).then(([tags, { counts, icons }]) => {
    const topics = (tags.topic || []).filter((t) => counts[t.id] || t.id === f.topic);
    $('#topics').innerHTML = topics.map((t) => `
      <a class="topic ${t.id === f.topic ? 'is-on' : ''}" href="${listHash({ ...f, topic: t.id === f.topic ? 0 : t.id })}" ${t.id === f.topic ? 'aria-current="true"' : ''}>
        <span class="topic__icon" aria-hidden="true">${topicIcon(t.name, icons)}</span><span>${esc(t.name)}</span><span class="topic__n">${counts[t.id] || 0}</span></a>`).join('');
    const byId = {};
    for (const list of Object.values(tags)) for (const t of list) byId[t.id] = t.name;
    const chip = (label, nf) => `<a class="chip chip--item" href="${listHash({ ...f, ...nf })}" aria-label="הסר ${esc(label)}">${esc(label)} ✕</a>`;
    $('#filter-chips').innerHTML = [
      ...f.tags.filter((id) => byId[id]).map((id) => chip(byId[id], { tags: f.tags.filter((x) => x !== id) })),
      f.d ? chip(DIFFICULTY[f.d], { d: '' }) : '',
      f.m ? chip(`עד ${minutes(f.m)}`, { m: 0 }) : '',
      filtered ? `<a class="link small" href="${listHash({ ...f, topic: 0, tags: [], d: '', m: 0 })}">נקה הכול</a>` : '',
    ].join('');
    $('#filter-slot').innerHTML = filterSheet(f, tags, counts);
    const sheet = $('#filter-sheet');
    const toggle = (on) => { sheet.hidden = !on; $('#filter-open').setAttribute('aria-expanded', String(on)); };
    $('#filter-open').addEventListener('click', () => toggle(sheet.hidden));
    $('#filter-close').addEventListener('click', () => toggle(false));
    $('#filter-clear').addEventListener('click', () => go(listHash({ ...f, tags: [], d: '', m: 0 })));
    sheet.addEventListener('submit', (e) => {
      e.preventDefault();
      const el = (name) => sheet.elements.namedItem(name);
      const ids = ['kosher', 'method', 'cuisine'].map((a) => +el(a)?.value || 0)
        .concat($$('[name="suitable"]:checked', sheet).map((c) => +c.value)).filter(Boolean);
      // תג מנושא שהגיע בקישור — נשאר; כל השאר נקבע מהגיליון
      go(listHash({ ...f, tags: ids, d: el('d').value, m: +el('m').value || 0 }));
    });
  }).catch(() => {});

  // ✨ בשבילך — בראש הבית, רק במצב הרגיל (בלי חיפוש וסינון); כשל כאן לא מפיל את הרשימה
  if (scope === 'all' && !q && !filtered) drawForYouRow($('#fy-row')).catch(() => {});
  const [{ recipes }, reco] = await Promise.all([
    api('search', { q, from_web: scope === 'web', following: scope === 'following', shared: scope === 'shared',
                    tag_ids: [f.topic, ...f.tags].filter(Boolean), difficulty: f.d || null, max_minutes: f.m || null }),
    scope === 'shared' || (scope === 'all' && !q && !filtered) ? api('recommendations') : Promise.resolve(null),
  ]);
  const sharedColls = scope === 'shared' && !filtered && !q ? (await api('collections').catch(() => ({ shared: [] }))).shared : [];
  // תג "חדש" ללשונית: מתכונים ששותפו ועוד לא נפתחו (מתוך "הכול"), ועוד המלצות שלא נפתחו
  if (scope === 'all' && !q && !filtered) { const n = recipes.filter((r) => r.shared_new).length + (reco?.unseen || 0) + (reco?.collections_unseen || 0); const b = $('#shared-new'); b.textContent = n; b.hidden = !n; }
  const collHtml = sharedColls.length ? `<h3 class="list__head">📁 אוספים ששותפו איתי</h3><div class="coll-grid">${sharedColls.map(collectionCard).join('')}</div>` : '';
  const recoHtml = scope === 'shared' && !filtered && (reco?.items.length || collHtml) ? `${collHtml}${reco?.items.length ? `<h3 class="list__head">📨 המלצות</h3>${reco.items.map(recoItem).join('')}` : ''}
    ${recipes.length ? '<h3 class="list__head">👥 מתכונים ששותפו איתי</h3>' : ''}` : '';
  if (recoHtml) results.innerHTML = recoHtml + recipes.map(recipeItem).join('');
  else if (!recipes.length) {
    results.innerHTML = `<p class="muted">${filtered ? `לא נמצא מתכון שמתאים לסינון. <a class="link link--inline" href="${listHash({ ...f, topic: 0, tags: [], d: '', m: 0 })}">נקה סינון</a>`
      : q ? 'לא נמצא כלום.' : {
      web: 'עדיין לא יובא כלום מהרשת. "🌐 ייבוא מהרשת" — מדביקים קישור למתכון.',
      following: 'כאן יופיעו מתכונים של מי שאתה עוקב אחריו. <a href="#/people">מצא אנשים ›</a>',
      shared: 'עוד לא שיתפו איתך מתכונים.',
    }[scope] || 'עדיין אין מתכונים. זה הרגע.'}</p>`;
  } else results.innerHTML = recipes.map(recipeItem).join('');

  results.addEventListener('click', async (e) => {
    const open = e.target.closest('[data-reco-open]');
    if (open) { api('recommendation-seen', { id: +open.dataset.recoOpen }).catch(() => {}); return; }   // הניווט ממשיך כרגיל
    const del = e.target.closest('[data-reco-dismiss]');
    if (!del) return;
    try { await api('recommendation-dismiss', { id: +del.dataset.recoDismiss }); del.closest('[data-reco]').remove(); } catch (err) { alert(err.message); }
  });
  $('#search').addEventListener('submit', (e) => { e.preventDefault(); go(listHash({ ...f, q: e.target.q.value.trim() })); });
}

/** המלצה שקיבלתי: ממי, על מה, ההערה; פתיחה — דרך החיפוש ברשת (או ייבוא מקישור). */
function recoItem(i) {
  return `
    <div class="item reco ${i.new ? 'item--new' : ''}" data-reco="${i.id}">
      <a class="item__media" href="${esc(i.open)}" data-reco-open="${i.id}">${i.image
        ? `<img class="item__thumb" src="${esc(i.image)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : '<span class="item__thumb item__thumb--empty">📨</span>'}</a>
      <a class="item__main" href="${esc(i.open)}" data-reco-open="${i.id}">
        <strong>${esc(i.title)}</strong>
        <span class="muted">${esc(i.from.display_name)} ממליץ/ה${i.site ? ` · 🌐 ${esc(i.site)}` : ''}</span>
        ${i.note ? `<span class="small">💬 ${esc(i.note)}</span>` : ''}
      </a>
      <button class="btn btn--ghost btn--small" type="button" data-reco-dismiss="${i.id}" aria-label="הסרה">✕</button>
    </div>`;
}

/** שורה ברשימת מתכונים — ברשימה הראשית, בפרופיל. */
function recipeItem(r) {
  const badge = r.is_mine ? ['badge--mine', 'שלי']
    : r.shared_with_me ? ['badge--shared', r.shared_new ? 'חדש · שותף איתך' : 'שותף איתך'] : ['badge--public', 'ציבורי'];
  return `
      <a class="item ${r.shared_new ? 'item--new' : ''}" href="#/r/${r.id}">
        <span class="item__media">${r.thumb ? `<img class="item__thumb" src="${esc(r.thumb)}" alt="" loading="lazy">` : '<span class="item__thumb item__thumb--empty">🍲</span>'}${
          r.has_video ? '<span class="item__play" title="יש סרטון" aria-label="יש סרטון">▶</span>' : ''}</span>
        <div class="item__main">
          <strong>${esc(r.title)}</strong>
          <span class="muted">${esc(r.owner_name)}${r.source_name ? ` · 🌐 ${esc(r.source_name)}` : ''}${r.difficulty ? ' · ' + DIFFICULTY[r.difficulty] : ''}${
            r.work_minutes || r.wait_minutes ? ' · ' + minutes((r.work_minutes || 0) + (r.wait_minutes || 0)) : ''}</span>
        </div>
        <span class="badge ${badge[0]}">${badge[1]}</span>
      </a>`;
}

// ───────────────────────── תצוגת מתכון ─────────────────────────

async function renderRecipe(id) {
  const loaded = await api('recipe', { id });
  const r = loaded.recipe;
  let servings = r.servings;
  let mult = 1;   // לתיאור חופשי ("עוגה אחת"): ×½ / ×2 / ×3 על הכמויות המחושבות
  const social = { comments: loaded.comments, note: loaded.note, isFavorite: loaded.is_favorite };

  const draw = () => {
    const factor = r.servings && servings ? servings / r.servings : mult;
    const tagsByAxis = {};
    for (const t of r.tags) (tagsByAxis[t.axis] ||= []).push(t.name);

    view.innerHTML = `
      <article class="recipe">
        <header class="recipe__head">
          <a class="link link--back" href="#/">‹ לרשימה</a>
          <h2>${esc(r.title)}</h2>
          <p class="muted recipe__by">${r.is_mine ? esc(r.owner_name) : `<a class="link link--inline" href="#/u/${r.owner_id}">${esc(r.owner_name)}</a>`} ·
            <span class="badge ${r.is_mine ? 'badge--mine' : 'badge--public'}">${r.visibility === 'public' ? 'ציבורי' : 'פרטי'}</span>
            ${loaded.share_count ? ` · <span class="badge badge--shared">👥 ${loaded.share_count}</span>` : ''}
            ${loaded.shared_with_me ? ` · <span class="badge badge--shared">👥 שותף איתך</span>` : ''}
            ${r.updated_at !== r.created_at ? ` · עודכן ${esc(r.updated_at.slice(0, 10))}` : ''}
          </p>
          ${r.source ? `
          <p class="credit">
            🌐 <strong>${r.source.rewritten ? 'מבוסס על המתכון של' : 'מקור:'}</strong> <a href="${esc(r.source.url)}" target="_blank" rel="noopener nofollow">${esc(r.source.name || r.source.url)}</a>${
              r.source.author ? ` · מאת ${esc(r.source.author)}` : ''}${
              r.source.imported_at ? ` · יובא ${esc(r.source.imported_at.slice(0, 10))}` : ''}
            <span class="muted small">— המתכון, התמונות והסרטונים שייכים למקור. הגרסה המקורית והמלאה שם.</span>
          </p>` : ''}
          ${r.difficulty || r.work_minutes || r.wait_minutes ? `<div class="meta">
            ${r.work_minutes ? `<span title="זמן עבודה">⏱ ${minutes(r.work_minutes)}</span>` : ''}
            ${r.wait_minutes ? `<span title="זמן המתנה">⏳ ${minutes(r.wait_minutes)}</span>` : ''}
            ${r.difficulty ? `<span title="קושי">📶 ${DIFFICULTY[r.difficulty]}</span>` : ''}
          </div>` : ''}
          ${Object.keys(tagsByAxis).length ? `<div class="chips">${
            Object.entries(tagsByAxis).map(([axis, names]) =>
              names.map((n) => `<span class="chip">${esc(n)}</span>`).join('')).join('')}</div>` : ''}
          ${r.servings ? `
            <div class="servings">
              <span>מנות:</span>
              <button class="btn btn--ghost" data-serv="-1" type="button" ${servings <= 1 ? 'disabled' : ''}>−</button>
              <strong>${servings}</strong>
              <button class="btn btn--ghost" data-serv="1" type="button">+</button>
              ${servings !== r.servings ? `<button class="link" data-serv="0" type="button">אפס (${r.servings})</button>` : ''}
            </div>` : r.yield_text ? `
            <div class="servings">
              <span>כמות: <strong>${esc(r.yield_text)}</strong></span>
              ${[0.5, 1, 2, 3].map((m) => `<button class="btn btn--ghost btn--tiny ${mult === m ? 'is-on' : ''}" data-mult="${m}" type="button">×${m === 0.5 ? '½' : m}</button>`).join('')}
            </div>` : ''}
          <a class="btn btn--primary btn--wide" href="#/cook/${r.id}">🍳 מצב בישול</a>
          <!-- סרגל פעולות: עמודות שוות, סמל ומתחת מילה — לא נשבר לשורות. מה שנדיר — ב"עוד" -->
          <nav class="actbar" aria-label="פעולות">
            ${r.is_mine
              ? `<button class="actbar__btn" id="share-open" type="button" data-panel="share-box"><span aria-hidden="true">👥</span>שיתוף</button>`
              : `<button class="actbar__btn ${social.isFavorite ? 'is-on' : ''}" id="fav" type="button" aria-pressed="${social.isFavorite}"><span aria-hidden="true">${social.isFavorite ? '♥' : '♡'}</span>${social.isFavorite ? 'נשמר' : 'שמירה'}</button>`}
            <button class="actbar__btn" id="coll-open" type="button" data-panel="coll-box"><span aria-hidden="true">📁</span>לאוסף</button>
            <button class="actbar__btn" id="to-shopping" type="button" data-panel="shopping-pick"><span aria-hidden="true">🛒</span>לקניות</button>
            ${r.is_mine ? `<a class="actbar__btn" href="#/edit/${r.id}"><span aria-hidden="true">✏️</span>עריכה</a>`
              : r.source ? `<button class="actbar__btn" id="reco-open" type="button" data-panel="reco-box"><span aria-hidden="true">📨</span>המלצה</button>` : ''}
            <details class="actbar__more">
              <summary class="actbar__btn"><span aria-hidden="true">⋯</span>עוד</summary>
              <div class="more-menu">
                <button type="button" id="print">🖨 הדפסה</button>
                ${r.is_mine && r.source ? '<button type="button" id="reco-open" data-panel="reco-box">📨 המלצה על המקור</button>' : ''}
                ${!r.is_mine ? '<button type="button" id="report-open" data-panel="report-slot">⚑ דיווח</button>' : ''}
                ${r.is_mine ? '<button type="button" class="is-danger" id="del">🗑 מחיקה</button>' : ''}
              </div>
            </details>
          </nav>
          ${r.is_mine ? '<div id="share-box" class="share-box" hidden></div>' : ''}
          ${r.source ? '<div id="reco-box" class="share-box" hidden></div>' : ''}
          <div id="coll-box" class="share-box" hidden></div>
          <div id="shopping-pick" hidden></div>
          <div id="report-slot"></div>
          ${loaded.shared_with_me ? '<p class="small"><button class="link" type="button" id="leave-share">הסר מהרשימה שלי</button></p>' : ''}
        </header>

        ${mediaGallery(r)}

        ${r.sections.map((s) => `
          <section class="part">
            ${s.name ? `<h3>${esc(s.name)}</h3>` : ''}
            ${s.ingredients.length ? `<ul class="ings">${s.ingredients.map((i) => {
              const calc = scaled(i, factor);
              return `<li class="${i.optional ? 'is-optional' : ''}">
                <span class="ing__free">${esc(i.free_text)}${i.product && !i.free_text.includes(i.product) ? ` ${esc(i.product)}` : ''}</span>
                ${calc ? `<span class="ing__calc">${esc(calc)}</span>` : ''}
                ${i.optional ? '<span class="muted">(לא חובה)</span>' : ''}
              </li>`;
            }).join('')}</ul>` : ''}
            ${s.steps.length ? `<ol class="steps">${s.steps.map((st) => `<li>${esc(st.text)}</li>`).join('')}</ol>` : ''}
          </section>`).join('')}

        ${r.tips ? `<section class="part"><h3>טיפים והערות</h3><p class="tips">${esc(r.tips)}</p></section>` : ''}

        ${r.source && r.is_mine ? '<section class="part snapshot" id="snapshot"></section>' : ''}
        <section class="part note-box" id="note-box"></section>
        ${r.visibility === 'public' ? '<section class="part comments" id="comments"></section>' : ''}
      </article>`;

    drawNote();
    if (r.visibility === 'public') drawComments();
    if (r.source && r.is_mine) drawSnapshot();

    $$('[data-mult]').forEach((b) => b.addEventListener('click', () => { mult = +b.dataset.mult; draw(); }));
    $$('[data-serv]').forEach((b) => b.addEventListener('click', () => {
      const d = +b.dataset.serv;
      servings = d === 0 ? r.servings : Math.max(1, servings + d);
      draw();   // ההמרה היא תצוגה בלבד — לא נשמרת (3.3)
    }));
    // לוח אחד פתוח בכל פעם; בחירה מ"עוד" סוגרת אותו
    $$('[data-panel]').forEach((b) => b.addEventListener('click', () => {
      for (const id of ['share-box', 'reco-box', 'coll-box', 'shopping-pick', 'report-slot']) {
        const p = $('#' + id);
        if (!p || id === b.dataset.panel) continue;
        if (id === 'report-slot') p.innerHTML = ''; else p.hidden = true;
      }
    }));
    $$('.more-menu button').forEach((b) => b.addEventListener('click', () => { b.closest('details').open = false; }));
    $('#del')?.addEventListener('click', async () => {
      if (!confirm(`למחוק את "${r.title}"? הפעולה אינה הפיכה.`)) return;
      const { deleted_comments } = await api('recipe-delete', { id: r.id });
      if (deleted_comments) alert(`נמחק, יחד עם ${deleted_comments} תגובות.`);
      go('#/');
    });
    $('#print').addEventListener('click', () => window.print());
    $('#share-open')?.addEventListener('click', () => {
      const box = $('#share-box');
      box.hidden = !box.hidden;
      if (!box.hidden) renderShareBox(box, r);
    });
    $('#report-open')?.addEventListener('click', () => {
      const slot = $('#report-slot');
      if (slot.innerHTML) { slot.innerHTML = ''; return; }
      reportBox(slot, 'recipe', r.id, 'המתכון');
    });
    $('#reco-open')?.addEventListener('click', () => {
      const box = $('#reco-box');
      box.hidden = !box.hidden;
      if (!box.hidden) renderRecoBox(box, { recipe_id: r.id }, r.title);
    });
    $('#coll-open').addEventListener('click', () => {
      const box = $('#coll-box');
      box.hidden = !box.hidden;
      if (!box.hidden) collectionPicker(box, { recipe_id: r.id });
    });
    $('#leave-share')?.addEventListener('click', async () => {
      if (!confirm('להסיר את המתכון מהרשימה שלך? הוא יישאר אצל מי ששיתף.')) return;
      try { await api('recipe-leave-share', { recipe_id: r.id }); go('#/'); } catch (err) { alert(err.message); }
    });
    // לרשימת קניות: בוחרים רשימה קיימת או חדשה. ה-factor הוא המרת המנות
    // שמוצגת כרגע — מי שהכפיל ל-16 מנות רוצה לקנות ל-16.
    $('#to-shopping').addEventListener('click', async () => {
      const box = $('#shopping-pick');
      if (!box.hidden) { box.hidden = true; return; }
      const factor = r.servings && servings ? servings / r.servings : mult;
      try {
        const { lists } = await api('shopping-lists');
        box.hidden = false;
        box.innerHTML = `
          <div class="note pick">
            <strong>להוסיף "${esc(r.title)}"${factor !== 1 ? ` (×${fmtAmount(factor)})` : ''} אל:</strong>
            <div class="actions actions--wrap">
              ${lists.slice(0, 6).map((l) => `<button class="btn" type="button" data-list="${l.id}">${esc(l.name || 'רשימה ' + l.id)} <span class="muted">(${l.items})</span></button>`).join('')}
              <button class="btn btn--primary" type="button" data-list="new">+ רשימה חדשה</button>
            </div>
          </div>`;
        $$('[data-list]', box).forEach((b) => b.addEventListener('click', async () => {
          b.disabled = true;
          try {
            const res = b.dataset.list === 'new'
              ? await api('shopping-create', { recipes: { [r.id]: factor }, name: r.title })
              : await api('shopping-add-recipes', { id: +b.dataset.list, recipes: { [r.id]: factor } });
            go(`#/shopping/${res.list.id}`);
          } catch (err) { box.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; }
        }));
      } catch (err) { alert(err.message); }
    });
    $('#fav')?.addEventListener('click', async () => {
      const b = $('#fav'); b.disabled = true;
      try { social.isFavorite = (await api('favorite-toggle', { recipe_id: r.id })).is_favorite; draw(); }
      catch (err) { alert(err.message); b.disabled = false; }
    });
  };

  // ── התיעוד הפרטי של הייבוא: מה חולץ מהמקור, כלשונו. רק המייבא. ──
  const drawSnapshot = () => {
    const box = $('#snapshot');
    box.innerHTML = `
      <details>
        <summary><h3>המקור כפי שיובא <span class="muted">— רק אתה רואה</span></h3></summary>
        <div class="snapshot__body muted">טוען…</div>
      </details>`;
    $('details', box).addEventListener('toggle', async (e) => {
      if (!e.target.open || box.dataset.loaded) return;
      const body = $('.snapshot__body', box);
      try {
        const { snapshot: s } = await api('import-snapshot', { recipe_id: r.id });
        box.dataset.loaded = '1';
        if (!s) {
          box.dataset.loaded = '';
          body.innerHTML = `<p>אין תיעוד למתכון הזה — יובא לפני שהתיעוד נבנה.</p>
            <button class="btn" type="button" id="snap-refresh">משוך מהמקור עכשיו</button>`;
          $('#snap-refresh', box).addEventListener('click', async () => {
            $('#snap-refresh', box).disabled = true; body.append(' מביא…');
            try { await api('import-snapshot-refresh', { recipe_id: r.id }); $('details', box).open = false; $('details', box).open = true; }
            catch (err) { body.textContent = err.message; }
          });
          return;
        }
        const raw = s.raw || {};
        body.className = 'snapshot__body';
        body.innerHTML = `
          <p class="muted small">חולץ ב-${esc(s.fetched_at.slice(0, 16).replace('T', ' '))} (${esc(s.extracted_by)}) מ-<a href="${esc(s.source_url)}" target="_blank" rel="noopener nofollow">${esc(raw.publisher || s.source_url)}</a>${raw.author ? ` · מאת ${esc(raw.author)}` : ''}. תיעוד פרטי — לא מוצג לאיש מלבדך.</p>
          ${raw.title ? `<p><strong>${esc(raw.title)}</strong></p>` : ''}
          ${raw.description ? `<p class="tips">${esc(raw.description)}</p>` : ''}
          ${raw.ingredients?.length ? `<h4>רכיבים במקור</h4><ul class="ings">${raw.ingredients.map((i) => `<li>${esc(i)}</li>`).join('')}</ul>` : ''}
          ${(raw.sections || []).map((sec) => `${sec.name ? `<h4>${esc(sec.name)}</h4>` : '<h4>שלבים במקור</h4>'}<ol class="steps">${sec.steps.map((st) => `<li>${esc(st)}</li>`).join('')}</ol>`).join('')}
          ${raw.yield ? `<p class="muted small">כמות במקור: ${esc(raw.yield)}</p>` : ''}`;
      } catch (err) { body.textContent = err.message; }
    });
  };

  // ── פתק פרטי (7): רק כותבו רואה. מקופל כשריק, כדי לא להעמיס על הדף. ──
  const drawNote = () => {
    const box = $('#note-box');
    const n = social.note;
    box.innerHTML = `
      <details ${n ? 'open' : ''}>
        <summary><h3>פתק פרטי <span class="muted">— רק אתה רואה</span></h3></summary>
        ${n ? `<p class="tips note-box__text">${esc(n.text)}</p>
               <p class="muted small">עודכן ${esc(n.updated_at.slice(0, 10))}</p>` : ''}
        <form class="note-form" id="note-form" ${n ? 'hidden' : ''}>
          <textarea name="text" rows="3" maxlength="4000" placeholder="לדוגמה: אצלי 5 דקות פחות בתנור">${n ? esc(n.text) : ''}</textarea>
          <div class="actions">
            <button class="btn btn--primary" type="submit">שמור פתק</button>
            ${n ? '<button class="btn btn--ghost" type="button" data-note-cancel>ביטול</button>' : ''}
          </div>
        </form>
        ${n ? `<div class="actions">
          <button class="link" type="button" data-note-edit>עריכה</button>
          <button class="link link--danger" type="button" data-note-del>מחיקת הפתק</button>
        </div>` : ''}
        <p class="note" id="note-msg" hidden></p>
      </details>`;
    const msgEl = $('#note-msg');
    const fail = (t) => { msgEl.textContent = t; msgEl.className = 'note note--err'; msgEl.hidden = false; };
    $('[data-note-edit]', box)?.addEventListener('click', () => {
      $('#note-form').hidden = false; $('.note-box__text', box).hidden = true; $('#note-form textarea').focus();
    });
    $('[data-note-cancel]', box)?.addEventListener('click', drawNote);
    $('[data-note-del]', box)?.addEventListener('click', async () => {
      if (!confirm('למחוק את הפתק?')) return;
      try { social.note = (await api('note-save', { recipe_id: r.id, text: '' })).note; drawNote(); }
      catch (err) { fail(err.message); }
    });
    $('#note-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      try { social.note = (await api('note-save', { recipe_id: r.id, text: e.target.text.value })).note; drawNote(); }
      catch (err) { fail(err.message); }
    });
  };

  // ── תגובות (7): שתי רמות, במתכון ציבורי בלבד ──
  const when = (iso) => esc(iso.slice(0, 16).replace('T', ' '));
  const commentHtml = (c, isReply) => `
    <article class="comment ${isReply ? 'comment--reply' : ''}" data-cid="${c.id}">
      <header class="comment__head">
        <strong>${esc(c.user_name)}</strong>
        <span class="muted small">${when(c.created_at)}${c.edited_at ? ' · נערכה' : ''}</span>
        ${c.before_update ? '<span class="badge badge--warn">נכתבה לפני עדכון המתכון</span>' : ''}
      </header>
      <p class="comment__text">${esc(c.text)}</p>
      <div class="comment__actions">
        ${!isReply && r.comments_open ? `<button class="link" type="button" data-reply="${c.id}">השב</button>` : ''}
        ${c.can_edit ? `<button class="link" type="button" data-cedit="${c.id}">עריכה</button>` : ''}
        ${c.can_delete ? `<button class="link link--danger" type="button" data-cdel="${c.id}">מחיקה</button>` : ''}
        ${!c.is_mine ? `<button class="link link--muted" type="button" data-creport="${c.id}">דווח</button>` : ''}
      </div>
      <div class="comment__slot" data-slot="${c.id}"></div>
      ${c.replies?.length ? `<div class="comment__replies">${c.replies.map((x) => commentHtml(x, true)).join('')}</div>` : ''}
    </article>`;

  const commentForm = (parentId, initial = '', label = 'שלח') => `
    <form class="comment-form" data-parent="${parentId || ''}">
      <textarea name="text" rows="2" maxlength="2000" required placeholder="${parentId ? 'תשובה…' : 'מה יצא לך? מה שינית?'}">${esc(initial)}</textarea>
      <div class="actions">
        <button class="btn btn--primary" type="submit">${label}</button>
        ${parentId || initial ? '<button class="btn btn--ghost" type="button" data-cancel>ביטול</button>' : ''}
      </div>
    </form>`;

  const drawComments = () => {
    const box = $('#comments');
    const list = social.comments;
    const total = list.reduce((n, c) => n + 1 + (c.replies?.length || 0), 0);
    box.innerHTML = `
      <div class="comments__head">
        <h3>תגובות <span class="muted">(${total})</span></h3>
        ${r.is_mine ? `<button class="link" type="button" id="toggle-comments">${r.comments_open ? 'סגור תגובות' : 'פתח תגובות'}</button>` : ''}
      </div>
      ${!r.comments_open ? '<p class="muted">הכותב סגר את התגובות במתכון הזה.</p>' : ''}
      ${list.length ? list.map((c) => commentHtml(c, false)).join('') : (r.comments_open ? '<p class="muted">עדיין אין תגובות. מישהו צריך להיות ראשון.</p>' : '')}
      ${r.comments_open ? `<div id="new-comment">${commentForm(null)}</div>` : ''}
      <p class="note" id="c-msg" hidden></p>`;

    const msgEl = $('#c-msg');
    const fail = (t) => { msgEl.textContent = t; msgEl.className = 'note note--err'; msgEl.hidden = false; };
    const refresh = (comments) => { social.comments = comments; drawComments(); };

    const bindForm = (form, handler) => {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type="submit"]'); btn.disabled = true;
        try { refresh((await handler(form.text.value)).comments); }
        catch (err) { fail(err.message); btn.disabled = false; }
      });
      form.querySelector('[data-cancel]')?.addEventListener('click', drawComments);
    };

    $$('#new-comment .comment-form', box).forEach((f) => bindForm(f, (text) => api('comment-add', { recipe_id: r.id, text })));
    $$('[data-reply]', box).forEach((b) => b.addEventListener('click', () => {
      const slot = $(`[data-slot="${b.dataset.reply}"]`, box);
      slot.innerHTML = commentForm(+b.dataset.reply);
      const f = slot.querySelector('form'); f.text.focus();
      bindForm(f, (text) => api('comment-add', { recipe_id: r.id, parent_id: +b.dataset.reply, text }));
    }));
    $$('[data-cedit]', box).forEach((b) => b.addEventListener('click', () => {
      const art = b.closest('.comment');
      const slot = $(`[data-slot="${b.dataset.cedit}"]`, box);
      slot.innerHTML = commentForm(null, art.querySelector('.comment__text').textContent, 'שמור');
      art.querySelector('.comment__text').hidden = true;
      bindForm(slot.querySelector('form'), (text) => api('comment-edit', { id: +b.dataset.cedit, text }));
    }));
    $$('[data-cdel]', box).forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('למחוק את התגובה? תשובות עליה יימחקו איתה.')) return;
      try { refresh((await api('comment-delete', { id: +b.dataset.cdel })).comments); }
      catch (err) { fail(err.message); }
    }));
    $$('[data-creport]', box).forEach((b) => b.addEventListener('click', () => {
      reportBox($(`[data-slot="${b.dataset.creport}"]`, box), 'comment', +b.dataset.creport, 'התגובה');
    }));
    $('#toggle-comments')?.addEventListener('click', async () => {
      try { r.comments_open = (await api('recipe-comments-open', { recipe_id: r.id, open: !r.comments_open })).comments_open; drawComments(); }
      catch (err) { fail(err.message); }
    });
  };

  draw();
}

// ───────────────────────── מועדפים ─────────────────────────

/** המועדפים הם עכשיו האוסף "שמורים" — הכתובת הישנה מפנה אליו. */
async function renderFavorites() {
  const { mine } = await api('collections');
  location.replace('#/c/' + mine.find((c) => c.is_saved).id);
}

// ───────────────────────── אוספים (SPEC 15.3) ─────────────────────────

const COLLECTION_ICONS = ['📁', '📖', '🎂', '🍪', '🥗', '🍲', '🍗', '🥘', '🍝', '🥖', '🍰', '🥧', '🍷', '🎉', '🕯️', '🌙', '🏖️', '❤️', '⭐', '👵'];

/** כרטיס אוסף: תמונת שער מארבעת הראשונים (או הסמל), שם, כמה, פרטיות. */
function collectionCard(c) {
  const cover = c.cover.length
    ? `<span class="coll__cover coll__cover--${Math.min(c.cover.length, 4)}">${c.cover.map((u) => `<img src="${esc(u)}" alt="" loading="lazy" referrerpolicy="no-referrer">`).join('')}</span>`
    : `<span class="coll__cover coll__cover--icon" aria-hidden="true">${esc(c.icon)}</span>`;
  return `
    <a class="coll ${c.new ? 'item--new' : ''}" href="#/c/${c.id}">
      ${cover}
      <span class="coll__body">
        <strong>${c.cover.length ? esc(c.icon) + ' ' : ''}${esc(c.name)}</strong>
        <span class="muted small">${c.count === 1 ? 'מתכון אחד' : `${c.count} מתכונים`}${c.owner ? ` · ${esc(c.owner.display_name)}` : ''}</span>
        <span class="coll__badges">${c.visibility === 'public' ? '<span class="badge badge--public">ציבורי</span>' : ''}${
          c.share_count ? `<span class="badge badge--shared">👥 ${c.share_count}</span>` : ''}${c.new ? '<span class="badge badge--shared">חדש</span>' : ''}</span>
      </span>
    </a>`;
}

/** טופס אוסף (חדש או עריכה): סמל, שם, תיאור, ציבורי. */
function collectionForm(c = null) {
  const icon = c ? c.icon : '📁';
  return `
    <form class="coll-form share-box" id="coll-form">
      <div class="coll-form__icons" role="radiogroup" aria-label="סמל">${COLLECTION_ICONS.map((i) => `
        <label class="coll-form__icon"><input type="radio" name="icon" value="${i}" ${i === icon ? 'checked' : ''}><span>${i}</span></label>`).join('')}</div>
      ${c && c.is_saved ? '' : `<label>שם <input name="name" maxlength="60" required value="${esc(c ? c.name : '')}" placeholder="למשל: ספר מתכונים לאמא"></label>`}
      <label>תיאור <span class="muted small">(לא חובה)</span> <input name="description" maxlength="300" value="${esc(c ? c.description : '')}"></label>
      <label class="check"><input type="checkbox" name="public" ${c && c.visibility === 'public' ? 'checked' : ''}> ציבורי — מוצג בפרופיל שלי</label>
      <p class="muted small">גם באוסף ציבורי רואים רק מתכונים שגלויים לכל אחד: המתכונים הפרטיים שלך לא נחשפים. לאנשים מסוימים — 👥 שיתוף.</p>
      <div class="actions"><button class="btn btn--primary" type="submit">${c ? 'שמור' : 'צור אוסף'}</button>
        <button class="btn btn--ghost" type="button" data-cancel>ביטול</button></div>
      <p class="note" id="coll-msg" hidden></p>
    </form>`;
}
const collectionFormData = (f) => ({ icon: f.icon.value, ...(f.name ? { name: f.name.value } : {}), description: f.description.value,
                                     visibility: f.public.checked ? 'public' : 'private' });

async function renderCollections() {
  const d = await api('collections');
  view.innerHTML = `
    <section class="card settings settings--wide colls">
      <a class="link" href="#/">‹ לרשימה</a>
      <h2>📁 האוספים שלי</h2>
      <p class="muted">רשימות בשם שלך, ממתכונים מכל מקום: שלך, של אחרים, וגם מהחיפוש ברשת — בלי לייבא.
        ♡ במתכון מוסיף ל"שמורים"; 📁 — לכל אוסף.</p>
      <button class="btn btn--primary" type="button" id="coll-new">+ אוסף חדש</button>
      <div id="coll-new-slot"></div>
      <div class="coll-grid">${d.mine.map(collectionCard).join('')}</div>
      ${d.shared.length ? `<h3 class="list__head">👥 שותפו איתי</h3><div class="coll-grid">${d.shared.map(collectionCard).join('')}</div>` : ''}
    </section>`;
  $('#coll-new').addEventListener('click', () => {
    const slot = $('#coll-new-slot');
    if (slot.innerHTML) { slot.innerHTML = ''; return; }
    slot.innerHTML = collectionForm();
    const f = $('#coll-form');
    f.name.focus();
    f.querySelector('[data-cancel]').addEventListener('click', () => { slot.innerHTML = ''; });
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { const { collection } = await api('collection-create', collectionFormData(f)); go('#/c/' + collection.id); }
      catch (err) { const m = $('#coll-msg'); m.textContent = err.message; m.className = 'note note--err'; m.hidden = false; }
    });
  });
}

/** שורת פריט באוסף. לבעלים — ▲▼✕. */
function collectionItemRow(it, mine) {
  const ctl = mine ? `<span class="coll-item__ctl">
      <button class="btn btn--ghost btn--small" type="button" data-move="-1" data-item="${it.item_id}" aria-label="למעלה">▲</button>
      <button class="btn btn--ghost btn--small" type="button" data-move="1" data-item="${it.item_id}" aria-label="למטה">▼</button>
      <button class="btn btn--ghost btn--small btn--danger" type="button" data-remove="${it.item_id}" aria-label="הסר מהאוסף">✕</button></span>` : '';
  if (it.status !== 'ok') {
    return `<div class="item item--gone">
      <span class="item__thumb item__thumb--empty">${it.status === 'gone' ? '🗑' : '🔒'}</span>
      <div class="item__main"><strong>${esc(it.title)}</strong>
        <span class="muted">${it.status === 'gone' ? 'הוסר על ידי הכותב' : 'המתכון אינו זמין כרגע'}</span></div>${ctl}</div>`;
  }
  const href = it.kind === 'web' ? it.open : `#/r/${it.id}`;
  const thumb = it.thumb ? `<img class="item__thumb" src="${esc(it.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer">`
    : `<span class="item__thumb item__thumb--empty">${it.kind === 'web' ? '🌐' : '🍲'}</span>`;
  const sub = it.kind === 'web' ? `🌐 ${esc(it.site || '')} · מהרשת, לא יובא`
    : `${esc(it.owner_name || '')}${it.difficulty ? ' · ' + DIFFICULTY[it.difficulty] : ''}${
        it.work_minutes || it.wait_minutes ? ' · ' + minutes((it.work_minutes || 0) + (it.wait_minutes || 0)) : ''}`;
  return `<div class="item coll-item">
      <a class="item__media" href="${esc(href)}">${thumb}</a>
      <a class="item__main" href="${esc(href)}"><strong>${esc(it.title)}</strong><span class="muted">${sub}</span></a>${ctl}</div>`;
}

async function renderCollection(id) {
  const d = await api('collection', { id });
  const c = d.collection;
  const mine = c.is_mine;
  view.innerHTML = `
    <section class="card coll-page">
      <div class="recipe__top">
        <a class="link" href="#/collections">‹ האוספים</a>
        <span class="recipe__icons">${mine ? `
          <button class="icon-btn" id="coll-edit" type="button" aria-label="עריכה" title="עריכה">✏️</button>
          <button class="icon-btn" id="coll-share" type="button" aria-label="שיתוף עם אנשים" title="שיתוף עם אנשים">👥</button>
          ${c.is_saved ? '' : '<button class="icon-btn icon-btn--danger" id="coll-del" type="button" aria-label="מחיקה" title="מחיקה">🗑</button>'}` : ''}
        </span>
      </div>
      <h2>${esc(c.icon)} ${esc(c.name)}</h2>
      ${c.description ? `<p>${esc(c.description)}</p>` : ''}
      <p class="muted small">${mine ? '' : `של <a class="link link--inline" href="#/u/${c.owner.id}">${esc(c.owner.display_name)}</a> · `}${
        c.count === 1 ? 'מתכון אחד' : `${c.count} מתכונים`}${c.visibility === 'public' ? ' · <span class="badge badge--public">ציבורי</span>' : ' · פרטי'}${
        c.share_count ? ` · <span class="badge badge--shared">👥 משותף עם ${c.share_count}</span>` : ''}${c.shared_with_me ? ' · <span class="badge badge--shared">👥 שותף איתך</span>' : ''}</p>
      <div id="coll-slot"></div>
      ${c.shared_with_me ? '<p class="small"><button class="link" type="button" id="coll-leave">הסר מהרשימה שלי</button></p>' : ''}
    </section>
    <section class="list" id="coll-items">${d.items.length ? d.items.map((it) => collectionItemRow(it, mine)).join('')
      : `<p class="muted">${mine ? 'עוד ריק. מוסיפים מתוך מתכון (📁 למעלה), או מתוצאה בחיפוש ברשת.' : 'אין כאן מתכונים שגלויים לך.'}</p>`}</section>`;

  const slot = $('#coll-slot');
  $('#coll-edit')?.addEventListener('click', () => {
    if (slot.innerHTML) { slot.innerHTML = ''; return; }
    slot.innerHTML = collectionForm(c);
    const f = $('#coll-form');
    f.querySelector('[data-cancel]').addEventListener('click', () => { slot.innerHTML = ''; });
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await api('collection-update', { id, ...collectionFormData(f) }); renderCollection(id); }
      catch (err) { const m = $('#coll-msg'); m.textContent = err.message; m.className = 'note note--err'; m.hidden = false; }
    });
  });
  $('#coll-share')?.addEventListener('click', () => {
    if (slot.innerHTML) { slot.innerHTML = ''; return; }
    slot.innerHTML = '<div class="share-box" id="coll-share-box"></div>';
    renderCollectionShareBox($('#coll-share-box'), c);
  });
  $('#coll-del')?.addEventListener('click', async () => {
    if (!confirm(`למחוק את האוסף "${c.name}"?

המתכונים עצמם לא יימחקו — רק הרשימה.`)) return;
    try { await api('collection-delete', { id }); go('#/collections'); } catch (err) { alert(err.message); }
  });
  $('#coll-leave')?.addEventListener('click', async () => {
    if (!confirm('להסיר את האוסף מהרשימה שלך? הוא יישאר אצל מי ששיתף.')) return;
    try { await api('collection-leave', { id }); go('#/collections'); } catch (err) { alert(err.message); }
  });
  $('#coll-items').addEventListener('click', async (e) => {
    const mv = e.target.closest('[data-move]');
    const rm = e.target.closest('[data-remove]');
    if (!mv && !rm) return;
    try {
      if (mv) await api('collection-item-move', { item_id: +mv.dataset.item, dir: +mv.dataset.move });
      else await api('collection-item-remove', { item_id: +rm.dataset.remove });
      renderCollection(id);
    } catch (err) { alert(err.message); }
  });
}

/** לוח השיתוף של אוסף: מי ששותף רואה את האוסף, ואת המתכונים הפרטיים *שלך* שבו. */
async function renderCollectionShareBox(box, c) {
  box.innerHTML = `
    <h3>👥 שיתוף האוסף</h3>
    <p class="muted small">מי שברשימה רואה את האוסף — <strong>כולל המתכונים הפרטיים שלך שבתוכו</strong>. מתכונים פרטיים של אחרים
      נשארים מוסתרים מהם. מה שתוסיף לאוסף — יראו מיד.</p>
    <div id="cshare-circles"></div>
    <div id="cshare-list" class="people-list"></div>
    <div id="cshare-pick"></div>`;
  let current = [];
  const drawList = () => {
    $('#cshare-list', box).innerHTML = current.length
      ? current.map((p) => personRow(p, `<span class="muted small">${p.seen ? 'פתח/ה ✓' : 'עוד לא פתח/ה'}</span>
          <button class="btn btn--ghost btn--small" type="button" data-unshare="${p.id}" aria-label="הסרה">✕</button>`)).join('')
      : '<p class="muted small">עוד לא שותף עם אף אחד בשמו.</p>';
  };
  let sharedCircles = [];
  try { ({ shares: current, circles: sharedCircles } = await api('collection-shares', { id: c.id })); } catch (err) { box.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; return; }
  drawList();
  circleShareRow($('#cshare-circles', box), 'collection', c.id, sharedCircles);
  $('#cshare-list', box).addEventListener('click', async (e) => {
    const uid = e.target.dataset.unshare;
    if (!uid) return;
    try { current = (await api('collection-unshare', { id: c.id, user_id: +uid })).shares; drawList(); picker.refresh(); } catch (err) { alert(err.message); }
  });
  const picker = peoplePicker($('#cshare-pick', box), {
    placeholder: 'חיפוש לפי שם…',
    actionFor: (p) => current.some((x) => x.id === p.id) ? '<span class="muted small">משותף ✓</span>'
      : '<button class="btn btn--primary btn--small" type="button" data-act="share">שתף</button>',
    onAction: async (pid) => { current = (await api('collection-share', { id: c.id, user_ids: [pid] })).shares; drawList(); },
  });
  picker.input.focus();
}

/**
 * גיליון "📁 לאוסף": האוספים שלי עם סימון במה שהפריט כבר נמצא, ו"אוסף חדש"
 * באותו מקום. target: { recipe_id } או { entry_id } (מהרשת, בלי לייבא).
 */
async function collectionPicker(box, target) {
  box.innerHTML = '<p class="muted small">טוען…</p>';
  let list;
  try { list = (await api('collections-for', target)).collections; } catch (err) { box.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; return; }
  const draw = () => {
    box.innerHTML = `
      <h3>📁 לאוסף</h3>
      <div class="coll-pick">${list.map((c) => `
        <label class="chip chip--pick"><input type="checkbox" data-coll="${c.id}" ${c.has ? 'checked' : ''}> ${esc(c.icon)} ${esc(c.name)}</label>`).join('')}</div>
      <form class="coll-pick__new"><input name="name" maxlength="60" placeholder="+ אוסף חדש…" autocomplete="off" aria-label="שם לאוסף חדש">
        <button class="btn btn--small" type="submit">צור והוסף</button></form>
      <p class="muted small">${target.entry_id ? 'נשמר כקישור — בלי לייבא ובלי לבזבז פתיחה.' : 'הפניה למתכון, לא העתק.'} <a class="link link--inline" href="#/collections">האוספים שלי ›</a></p>`;
    $$('[data-coll]', box).forEach((cb) => cb.addEventListener('change', async () => {
      cb.disabled = true;
      try { const { has } = await api('collection-toggle', { id: +cb.dataset.coll, ...target, on: cb.checked }); list.find((c) => c.id === +cb.dataset.coll).has = has; }
      catch (err) { cb.checked = !cb.checked; alert(err.message); }
      cb.disabled = false;
    }));
    $('.coll-pick__new', box).addEventListener('submit', async (e) => {
      e.preventDefault();
      const name = e.target.name.value.trim();
      if (!name) return;
      try {
        const { collection } = await api('collection-create', { name });
        await api('collection-toggle', { id: collection.id, ...target, on: true });
        list.push({ id: collection.id, name: collection.name, icon: collection.icon, has: true });
        draw();
      } catch (err) { alert(err.message); }
    });
  };
  draw();
}

// ───────────────────────── עורך ─────────────────────────

/**
 * גרירה לשינוי סדר בתוך container, על השורות שתואמות rowSel, מהידית
 * [data-handle]. בסיום נקרא onDrop עם סדר האינדקסים המקוריים (מ-data-<key>).
 * הדפדפן לא גולל תוך כדי (touch-action: none על הידית ב-CSS).
 */
function makeSortable(container, rowSel, onDrop, key) {
  $$(`${rowSel} > * [data-handle], ${rowSel} > [data-handle]`, container).forEach((handle) => {
    const row = handle.closest(rowSel);
    handle.addEventListener('pointerdown', (e) => {
      if (e.button !== undefined && e.button !== 0) return;
      e.preventDefault();
      handle.setPointerCapture(e.pointerId);
      row.classList.add('is-dragging');
      const rows = () => $$(rowSel, container).filter((r) => r.dataset.si === row.dataset.si);
      const move = (ev) => {
        const y = ev.clientY;
        for (const other of rows()) {
          if (other === row) continue;
          const b = other.getBoundingClientRect();
          const mid = b.top + b.height / 2;
          const before = row.compareDocumentPosition(other) & Node.DOCUMENT_POSITION_FOLLOWING;
          if (before && y > mid) other.after(row);
          else if (!before && y < mid) other.before(row);
        }
      };
      const up = () => {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', up);
        handle.removeEventListener('pointercancel', up);
        row.classList.remove('is-dragging');
        const order = rows().map((r) => +r.dataset[key]);
        const changed = order.some((v, i) => v !== i);
        if (changed) onDrop(order);
      };
      handle.addEventListener('pointermove', move);
      handle.addEventListener('pointerup', up);
      handle.addEventListener('pointercancel', up);
    });
  });
}

const emptyIng = () => ({ free_text: '', amount_min: '', amount_max: '', unit: '', product: '', optional: false });
const emptySection = () => ({ name: '', ingredients: [emptyIng()], steps: [''] });

async function renderEditor(id, draft = null) {
  const tags = await ensureTags();
  // draft: טיוטה מהייבוא — כבר בצורת המודל, עם מקור ומדיה שממתינה לשמירה
  let r = draft ? {
    ...draft, tags: draft.tag_ids.map((tid) => ({ id: tid })),
    servings: draft.servings ?? '', yield_text: draft.yield_text ?? '',
    // השלבים נשארים {text} — המודל למטה קורא st.text, כמו במתכון שנטען מהשרת.
    // (הבאג שהיה כאן: המרה למחרוזות כבר פה, ואז .text על מחרוזת = undefined,
    // וכל השלבים של מתכון מיובא נעלמו בשמירה.)
    sections: draft.sections,
  } : id ? (await api('recipe', { id })).recipe : {
    title: '', visibility: 'private', servings: '', yield_text: '', difficulty: '', work_minutes: '', wait_minutes: '',
    tips: '', tags: [], sections: [emptySection()], comments_open: true,
  };
  // הטופס עובד על עותק שאפשר לשנות בלי לגעת במה שהגיע מהשרת.
  const model = {
    title: r.title, visibility: r.visibility, servings: r.servings ?? '', yield_text: r.yield_text ?? '',
    // מצב הכמות נגזר ממה שיש: מספר → מנות, טקסט → חופשי, כלום → מוסתר.
    // מתכון חדש מתחיל ב"מספר מנות" — הברירה הנפוצה.
    yield_mode: r.servings ? 'servings' : r.yield_text ? 'text' : (id ? 'none' : 'servings'),
    difficulty: r.difficulty ?? '',
    work_minutes: r.work_minutes ?? '', wait_minutes: r.wait_minutes ?? '', tips: r.tips ?? '',
    comments_open: r.comments_open !== false,
    source: draft ? { url: draft.source_url, name: draft.source_name, author: draft.source_author, rewritten: !!draft.source_rewritten } : (r.source || null),
    snapshot: draft ? draft.snapshot : null,
    import_log_id: draft ? draft.import_log_id || null : null,   // השורה ביומן הייבוא — תקושר למתכון בשמירה
    ai_available: draft ? !!draft.ai_available : null,
    pending_media: draft ? draft.pending_media : null,
    kosher_suggestion: draft ? draft.kosher_suggestion || null : null,   // הצעה בלבד — מסמנים רק באישור
    tag_ids: new Set(r.tags.map((t) => t.id)),
    sections: r.sections.map((s) => ({
      name: s.name ?? '',
      ingredients: s.ingredients.length ? s.ingredients.map((i) => ({
        free_text: i.free_text, amount_min: i.amount_min ?? '', amount_max: i.amount_max ?? '',
        unit: i.unit ?? '', product: i.product ?? '', optional: !!i.optional,
      })) : [emptyIng()],
      steps: s.steps.length ? s.steps.map((st) => st.text) : [''],
    })),
  };

  // מתכון חדש: תמונות, סרטונים וקישורים נבחרים כבר עכשיו, ועולים מיד אחרי השמירה
  // הראשונה (לפני שיש מזהה אין לאן להעלות). נשמרים כאן — מחוץ ל-draw — כדי לשרוד ציור מחדש.
  const newMedia = { images: [], videos: [], links: [] };
  let limitsCache = null;
  const drawNewMedia = () => {
    const box = $('#media-new');
    if (!box) return;
    const row = (label, key, i) => `<div class="media-row"><span>${label}</span>
      <button class="btn btn--ghost btn--danger" type="button" data-nm-del="${key}:${i}" aria-label="הסרה">✕</button></div>`;
    box.innerHTML = `
      <h4>תמונות וסרטונים</h4>
      ${newMedia.images.length ? `<div class="media-grid">${newMedia.images.map((it, i) => `
        <figure class="media-item"><img src="${it.url}" alt=""><figcaption>${i === 0 ? '<span class="badge badge--mine">ראשית</span>' : ''}
          <button class="btn btn--ghost btn--danger" type="button" data-nm-del="images:${i}" aria-label="הסרה">✕</button></figcaption></figure>`).join('')}</div>` : ''}
      ${newMedia.videos.map((it, i) => row(`🎬 ${esc(it.file.name)} · ${humanBytes(it.file.size)}`, 'videos', i)).join('')}
      ${newMedia.links.map((u, i) => row(`🔗 ${esc(u)}`, 'links', i)).join('')}
      <div class="scan-pick">
        <label class="btn">📷 תמונות<input type="file" accept="image/*" multiple hidden data-nm="images"></label>
        <label class="btn">🎥 צילום סרטון<input type="file" accept="video/*" capture="environment" hidden data-nm="videos"></label>
        <label class="btn">🎞 סרטון מהגלריה<input type="file" accept="video/mp4,video/quicktime,video/webm" hidden data-nm="videos"></label>
      </div>
      <div class="media-link">
        <input id="nm-link" type="text" inputmode="url" placeholder="קישור ליוטיוב או וימאו" autocomplete="off" aria-label="קישור לסרטון">
        <button class="btn" type="button" id="nm-link-add">הוסף</button>
      </div>
      <small class="muted">יעלו מיד אחרי השמירה. התמונה הראשונה תהיה הראשית.</small>
      <p class="note" id="nm-msg" hidden></p>`;
    const note = (t, k) => { const el = $('#nm-msg'); el.textContent = t; el.className = 'note' + (k ? ' note--' + k : ''); el.hidden = !t; };
    $$('[data-nm]', box).forEach((inp) => inp.addEventListener('change', async () => {
      const key = inp.dataset.nm;
      limitsCache ||= (await api('media-limits')).limits;
      for (const file of inp.files) {
        if (key === 'videos' && file.size > limitsCache.video_max) { note(`${file.name} גדול מדי (${humanBytes(file.size)}, התקרה ${humanBytes(limitsCache.video_max)}). סרטון ארוך — העלה ליוטיוב והדבק קישור.`, 'err'); continue; }
        if (key === 'videos' && newMedia.videos.length + newMedia.links.length >= limitsCache.max_videos) { note(`עד ${limitsCache.max_videos} סרטונים למתכון.`, 'warn'); break; }
        if (key === 'images' && newMedia.images.length >= limitsCache.max_images) { note(`עד ${limitsCache.max_images} תמונות למתכון.`, 'warn'); break; }
        newMedia[key].push({ file, url: key === 'images' ? URL.createObjectURL(file) : null });
      }
      drawNewMedia();
    }));
    box.addEventListener('click', (e) => {
      const d = e.target.dataset.nmDel;
      if (!d) return;
      const [key, i] = d.split(':');
      const [gone] = newMedia[key].splice(+i, 1);
      if (gone?.url) URL.revokeObjectURL(gone.url);
      drawNewMedia();
    });
    $('#nm-link-add').addEventListener('click', () => {
      const u = $('#nm-link').value.trim();
      if (!/^https?:\/\/\S{6,}$/i.test(u)) { note('זה לא קישור תקין (http/https)', 'warn'); return; }
      newMedia.links.push(u);
      drawNewMedia();
    });
  };
  /** אחרי השמירה הראשונה: מעלה את מה שנבחר. מחזיר את השגיאות (המתכון כבר שמור בכל מקרה). */
  const uploadNewMedia = async (rid, out) => {
    const errs = [];
    const say = (t) => { out.textContent = t; out.className = 'note'; out.hidden = false; };
    for (const it of newMedia.images) {
      try { await uploadFile(rid, await shrinkImage(it.file), (p) => say(`מעלה תמונה… ${Math.round(p * 100)}%`)); } catch (e) { errs.push(e.message); }
    }
    for (const it of newMedia.videos) {
      try { await uploadFile(rid, it.file, (p) => say(`מעלה ${it.file.name}… ${Math.round(p * 100)}%`)); } catch (e) { errs.push(`${it.file.name}: ${e.message}`); }
    }
    for (const u of newMedia.links) {
      try { await api('media-link', { recipe_id: rid, url: u }); } catch (e) { errs.push(`${u}: ${e.message}`); }
    }
    return errs;
  };

  const draw = () => {
    // מתכון פשוט = חלק אחד: שדה השם שלו מוסתר, והחלוקה לא נראית (3.1).
    const multi = model.sections.length > 1;
    view.innerHTML = `
      <form id="editor" class="form editor">
        <a class="link" href="${id ? `#/r/${id}` : '#/'}">‹ ביטול</a>
        <h2>${id ? 'עריכת מתכון' : draft ? 'ייבוא מהרשת — בדיקה לפני שמירה' : 'מתכון חדש'}</h2>
        ${model.source ? `
          <p class="credit">🌐 מקור: <a href="${esc(model.source.url)}" target="_blank" rel="noopener nofollow">${esc(model.source.name || model.source.url)}</a>${
            model.source.author ? ` · מאת ${esc(model.source.author)}` : ''}
            <span class="muted small">— הקרדיט מוצג תמיד ואינו ניתן להסרה.</span></p>` : ''}
        ${draft && draft.warnings.length ? `<ul class="note note--warn import-warn">${draft.warnings.map((w) => `<li>${esc(w)}</li>`).join('')}</ul>` : ''}
        ${draft && (model.pending_media.images.length || model.pending_media.videos.length) ? `
          <p class="muted">יצורפו אחרי השמירה: ${model.pending_media.images.length} תמונות, ${model.pending_media.videos.length} סרטונים — כקישורים לאתר המקורי.</p>
          <div class="gallery__thumbs">${model.pending_media.images.map((u) => `<img src="${esc(u)}" alt="" loading="lazy" referrerpolicy="no-referrer">`).join('')}</div>` : ''}

        <label>שם המתכון <input name="title" value="${esc(model.title)}" required maxlength="120"></label>

        <div class="row">
          <label>כמות
            <select name="yield_mode">
              <option value="servings" ${model.yield_mode === 'servings' ? 'selected' : ''}>מספר מנות</option>
              <option value="text" ${model.yield_mode === 'text' ? 'selected' : ''}>תיאור חופשי</option>
              <option value="none" ${model.yield_mode === 'none' ? 'selected' : ''}>לא להציג</option>
            </select>
          </label>
          <label ${model.yield_mode !== 'servings' ? 'hidden' : ''}>מנות
            <input name="servings" type="number" min="1" inputmode="numeric" value="${esc(model.servings)}">
          </label>
          <label ${model.yield_mode !== 'text' ? 'hidden' : ''}>למשל: עוגה אחת, תבנית 26
            <input name="yield_text" maxlength="60" value="${esc(model.yield_text)}" placeholder="עוגה אחת">
          </label>
          <label>קושי <select name="difficulty">
            <option value="">—</option>
            ${Object.entries(DIFFICULTY).map(([k, v]) => `<option value="${k}" ${model.difficulty === k ? 'selected' : ''}>${v}</option>`).join('')}
          </select></label>
        </div>
        <div class="row">
          <label>זמן עבודה (דק׳) <input name="work_minutes" type="number" min="0" inputmode="numeric" value="${esc(model.work_minutes)}"></label>
          <label>זמן המתנה (דק׳) <input name="wait_minutes" type="number" min="0" inputmode="numeric" value="${esc(model.wait_minutes)}"></label>
        </div>

        ${model.source ? `
          <div class="rewrite" id="rewrite">
            <p class="muted small">${model.source.rewritten
              ? '✅ השלבים נוסחו מחדש — הקרדיט הוא "מבוסס על המתכון של…".'
              : 'השלבים עדיין בניסוח המקור. ניסוח מחדש במילים אחרות שומר את הכמויות, הזמנים והסדר, ומוריד את החשיפה המשפטית.'}</p>
            <div class="actions">
              <button class="btn ${model.source.rewritten ? '' : 'btn--primary'}" type="button" id="rewrite-btn">✨ נסח מחדש בבינה</button>
              <button class="btn btn--ghost" type="button" id="rewrite-undo" hidden>בטל ניסוח</button>
              ${id && !model.sections.some((s) => s.steps.some((t) => t.trim())) ? '<button class="btn btn--ghost" type="button" id="steps-from-snapshot">מלא שלבים מהמקור</button>' : ''}
              <label class="check"><input type="checkbox" name="rewritten_manual" ${model.source.rewritten ? 'checked' : ''}> ניסחתי בעצמי</label>
            </div>
            <p class="note" id="rewrite-msg" hidden></p>
          </div>` : ''}
        <fieldset class="parts">
          ${model.sections.map((s, si) => `
            <div class="part part--edit" data-si="${si}">
              ${multi ? `<div class="part__head">
                <input name="sname" data-si="${si}" placeholder="שם החלק (בצק, מלית…)" value="${esc(s.name)}">
                <button class="btn btn--ghost btn--tiny" type="button" data-move-section="${si}:-1" title="למעלה" ${si === 0 ? 'disabled' : ''}>▲</button>
                <button class="btn btn--ghost btn--tiny" type="button" data-move-section="${si}:1" title="למטה" ${si === model.sections.length - 1 ? 'disabled' : ''}>▼</button>
                <button class="btn btn--ghost btn--danger" type="button" data-del-section="${si}" title="הסר חלק">✕</button>
              </div>` : ''}

              <h4>רכיבים</h4>
              ${s.ingredients.map((ing, ii) => `
                <div class="ing-row" data-si="${si}" data-ii="${ii}">
                  <div class="row-tools">
                    <span class="drag-handle" data-handle title="גרור לשינוי הסדר" aria-label="גרור לשינוי הסדר">⋮⋮</span>
                    <button class="btn btn--ghost btn--tiny" type="button" data-move-ing="${si}:${ii}:-1" title="למעלה" ${ii === 0 ? 'disabled' : ''}>▲</button>
                    <button class="btn btn--ghost btn--tiny" type="button" data-move-ing="${si}:${ii}:1" title="למטה" ${ii === s.ingredients.length - 1 ? 'disabled' : ''}>▼</button>
                    <input class="ing-free" placeholder="כפי שקוראים: 2 כוסות קמח" value="${esc(ing.free_text)}" data-f="free_text">
                  </div>
                  <div class="ing-calc">
                    <input type="number" step="any" min="0" placeholder="כמות" inputmode="decimal" value="${esc(ing.amount_min)}" data-f="amount_min">
                    <input type="number" step="any" min="0" placeholder="עד" inputmode="decimal" value="${esc(ing.amount_max)}" data-f="amount_max">
                    <select data-f="unit">
                      <option value="">יחידה</option>
                      ${Object.entries(UNITS).map(([k, v]) => `<option value="${k}" ${ing.unit === k ? 'selected' : ''}>${v}</option>`).join('')}
                    </select>
                    <input list="products" placeholder="מוצר (לקטלוג)" value="${esc(ing.product)}" data-f="product">
                    <label class="check"><input type="checkbox" ${ing.optional ? 'checked' : ''} data-f="optional"> לא חובה</label>
                    <button class="btn btn--ghost btn--danger" type="button" data-del-ing="${si}:${ii}" title="הסר">✕</button>
                  </div>
                </div>`).join('')}
              <button class="btn btn--ghost" type="button" data-add-ing="${si}">+ רכיב</button>

              <h4>שלבים</h4>
              ${s.steps.map((st, ki) => `
                <div class="step-row" data-si="${si}" data-ki="${ki}">
                  <div class="step-side">
                    <span class="drag-handle" data-handle title="גרור לשינוי הסדר" aria-label="גרור לשינוי הסדר">⋮⋮</span>
                    <span class="step-no">${ki + 1}</span>
                  </div>
                  <textarea rows="2" placeholder="מה עושים בשלב הזה" data-f="step">${esc(st)}</textarea>
                  <div class="step-side">
                    <button class="btn btn--ghost btn--tiny" type="button" data-move-step="${si}:${ki}:-1" title="למעלה" ${ki === 0 ? 'disabled' : ''}>▲</button>
                    <button class="btn btn--ghost btn--tiny" type="button" data-move-step="${si}:${ki}:1" title="למטה" ${ki === s.steps.length - 1 ? 'disabled' : ''}>▼</button>
                    <button class="btn btn--ghost btn--danger" type="button" data-del-step="${si}:${ki}" title="הסר">✕</button>
                  </div>
                </div>`).join('')}
              <button class="btn btn--ghost" type="button" data-add-step="${si}">+ שלב</button>
            </div>`).join('')}
          <button class="btn" type="button" id="add-section">+ חלק נוסף (בצק / מלית / זיגוג)</button>
        </fieldset>
        <datalist id="products">${state.products.map((p) => `<option value="${esc(p)}">`).join('')}</datalist>

        <label>טיפים והערות <textarea name="tips" rows="3">${esc(model.tips)}</textarea></label>

        ${id ? `<fieldset class="media-edit" id="media-edit"></fieldset>`
             : `<fieldset class="media-edit" id="media-new"></fieldset>`}

        <fieldset class="tags">
          ${Object.entries(AXES).map(([axis, label]) => tags[axis] ? `
            <div class="tags__axis"><span class="muted">${label}</span>
              ${tags[axis].map((t) => `<label class="chip chip--pick">
                <input type="checkbox" data-tag="${t.id}" ${model.tag_ids.has(t.id) ? 'checked' : ''}> ${esc(t.name)}</label>`).join('')}
              ${axis === 'kosher' && model.kosher_suggestion && !tags.kosher.some((t) => model.tag_ids.has(t.id)) ? `
                <p class="tags__hint" id="kosher-hint">💡 לפי המתכון נראה <strong>${esc(model.kosher_suggestion.name)}</strong> —
                  <button class="link link--inline" type="button" data-kosher-accept="${model.kosher_suggestion.id}">לסמן?</button>
                  <span class="muted small">(בדקו — הכשרות באחריותכם)</span></p>` : ''}
            </div>` : '').join('')}
        </fieldset>

        <label class="check check--big ${model.source ? 'is-disabled' : ''}">
          <input type="checkbox" name="public" ${model.visibility === 'public' && !model.source ? 'checked' : ''} ${model.source ? 'disabled' : ''}>
          מתכון ציבורי — כולם רואים, ואפשר להגיב
        </label>
        ${model.source ? '<p class="muted small">🔒 מתכון מהרשת נשמר <strong>כפרטי בלבד</strong>, עם הקרדיט והקישור למקור. אפשר לשתף אותו עם אנשים מסוימים (👥) או להמליץ להם על המקור (📨) — מתוך המתכון אחרי השמירה.</p>' : ''}
        <label class="check">
          <input type="checkbox" name="comments_open" ${model.comments_open ? 'checked' : ''}>
          לאפשר תגובות (במתכון ציבורי)
        </label>

        <button class="btn btn--primary btn--wide" type="submit">${id ? 'שמור שינויים' : 'צור מתכון'}</button>
        <p id="edit-msg" class="note" hidden></p>
      </form>`;

    bindEditor(id);
    const ka = $('[data-kosher-accept]');
    if (ka) ka.addEventListener('click', () => {
      const c = $(`[data-tag="${ka.dataset.kosherAccept}"]`);
      if (c) c.checked = true;
      model.kosher_suggestion = null;
      $('#kosher-hint').remove();
    });
  };

  // כל שינוי בשדה נכתב למודל מיד, כך שהוספה או הסרה של שורה (שמציירת
  // מחדש) לא מאבדת מה שהוקלד.
  const collect = () => {
    const f = $('#editor');
    model.title = f.title.value;
    model.yield_mode = f.yield_mode.value;
    model.servings = f.servings.value;
    model.yield_text = f.yield_text.value;
    model.difficulty = f.difficulty.value;
    model.work_minutes = f.work_minutes.value;
    model.wait_minutes = f.wait_minutes.value;
    model.tips = f.tips.value;
    model.visibility = f.public.checked && !model.source ? 'public' : 'private';
    model.comments_open = f.comments_open.checked;
    if (model.source && f.rewritten_manual) model.source.rewritten = f.rewritten_manual.checked;
    model.tag_ids = new Set($$('[data-tag]:checked', f).map((c) => +c.dataset.tag));
    $$('input[name="sname"]', f).forEach((i) => { model.sections[+i.dataset.si].name = i.value; });
    $$('.ing-row', f).forEach((row) => {
      const ing = model.sections[+row.dataset.si].ingredients[+row.dataset.ii];
      $$('[data-f]', row).forEach((el) => {
        ing[el.dataset.f] = el.type === 'checkbox' ? el.checked : el.value;
      });
    });
    $$('.step-row', f).forEach((row) => {
      model.sections[+row.dataset.si].steps[+row.dataset.ki] = $('[data-f="step"]', row).value;
    });
  };

  const bindEditor = (editId) => {
    const f = $('#editor');
    const redraw = () => { collect(); draw(); };

    f.yield_mode.addEventListener('change', () => { collect(); draw(); (f.yield_mode.value === 'text' ? $('#editor [name="yield_text"]') : $('#editor [name="servings"]'))?.focus(); });

    // ── ניסוח מחדש בבינה: השלבים בלבד, הרכיבים נשארים. הקודם נשמר לביטול. ──
    $('#rewrite-btn')?.addEventListener('click', async () => {
      collect();
      const btn = $('#rewrite-btn'), out = $('#rewrite-msg');
      const note2 = (t, k) => { out.textContent = t; out.className = 'note' + (k ? ' note--' + k : ''); out.hidden = false; };
      btn.disabled = true; note2('מנסח מחדש… (עד דקה)');
      try {
        const res = await api('import-rewrite', {
          title: model.title,
          sections: model.sections.map((s) => ({ name: s.name, steps: s.steps.filter((t) => t.trim()) })),
        });
        model.prev_steps = model.sections.map((s) => [...s.steps]);
        // התאמה לפי מיקום: הבינה מחזירה חלק לכל חלק. חלק חסר — נשאר כמו שהיה.
        res.sections.forEach((s, i) => { if (model.sections[i]) model.sections[i].steps = s.steps; });
        model.source.rewritten = !res.too_close;
        draw();
        const out2 = $('#rewrite-msg');
        out2.textContent = res.too_close
          ? `הניסוח יצא קרוב מדי למקור (${Math.round(res.similarity * 100)}% זהה). לערוך ידנית, או לנסות שוב.`
          : `נוסח מחדש · ${Math.round(res.similarity * 100)}% מהמשפטים זהים למקור · ${res.model}`;
        out2.className = 'note note--' + (res.too_close ? 'warn' : 'ok'); out2.hidden = false;
        $('#rewrite-undo').hidden = false;
      } catch (err) { note2(err.message, 'err'); btn.disabled = false; }
    });
    $('#steps-from-snapshot')?.addEventListener('click', async () => {
      const out = $('#rewrite-msg');
      try {
        let { snapshot } = await api('import-snapshot', { recipe_id: editId });
        if (!snapshot) ({ snapshot } = await api('import-snapshot-refresh', { recipe_id: editId }));
        const secs = snapshot?.raw?.sections || [];
        if (!secs.length) throw new Error('גם במקור לא נמצאו שלבים');
        collect();
        secs.forEach((s, i) => {
          if (!model.sections[i]) model.sections.push({ name: s.name || '', ingredients: [], steps: [] });
          model.sections[i].steps = [...s.steps];
        });
        draw();
      } catch (err) { out.textContent = err.message; out.className = 'note note--err'; out.hidden = false; }
    });
    $('#rewrite-undo')?.addEventListener('click', () => {
      if (!model.prev_steps) return;
      collect();
      model.prev_steps.forEach((steps, i) => { if (model.sections[i]) model.sections[i].steps = steps; });
      model.prev_steps = null; model.source.rewritten = false; draw();
    });
    $('#add-section').addEventListener('click', () => { collect(); model.sections.push(emptySection()); draw(); });
    $$('[data-del-section]', f).forEach((b) => b.addEventListener('click', () => {
      collect(); model.sections.splice(+b.dataset.delSection, 1); draw();
    }));
    $$('[data-add-ing]', f).forEach((b) => b.addEventListener('click', () => {
      collect(); model.sections[+b.dataset.addIng].ingredients.push(emptyIng()); draw();
    }));
    $$('[data-del-ing]', f).forEach((b) => b.addEventListener('click', () => {
      const [si, ii] = b.dataset.delIng.split(':').map(Number);
      collect(); model.sections[si].ingredients.splice(ii, 1); draw();
    }));
    $$('[data-add-step]', f).forEach((b) => b.addEventListener('click', () => {
      collect(); model.sections[+b.dataset.addStep].steps.push(''); draw();
    }));
    $$('[data-del-step]', f).forEach((b) => b.addEventListener('click', () => {
      const [si, ki] = b.dataset.delStep.split(':').map(Number);
      collect(); model.sections[si].steps.splice(ki, 1); draw();
    }));

    // ── סדר: חצים (נגישים, עובדים בכל מקום) וגרירה בידית (עכבר ואצבע) ──
    const moveIn = (arr, from, to) => { const [x] = arr.splice(from, 1); arr.splice(to, 0, x); };
    $$('[data-move-ing]', f).forEach((b) => b.addEventListener('click', () => {
      const [si, ii, d] = b.dataset.moveIng.split(':').map(Number);
      collect(); moveIn(model.sections[si].ingredients, ii, ii + d); draw();
      $(`.ing-row[data-si="${si}"][data-ii="${ii + d}"] .ing-free`)?.focus();
    }));
    $$('[data-move-step]', f).forEach((b) => b.addEventListener('click', () => {
      const [si, ki, d] = b.dataset.moveStep.split(':').map(Number);
      collect(); moveIn(model.sections[si].steps, ki, ki + d); draw();
      $(`.step-row[data-si="${si}"][data-ki="${ki + d}"] textarea`)?.focus();
    }));
    $$('[data-move-section]', f).forEach((b) => b.addEventListener('click', () => {
      const [si, d] = b.dataset.moveSection.split(':').map(Number);
      collect(); moveIn(model.sections, si, si + d); draw();
    }));
    // גרירה: הידית תופסת את המצביע, השורה זזה ב-DOM תוך כדי, ובשחרור
    // הסדר נקרא מה-DOM (לפי data-ii/data-ki המקוריים) אל המודל. Pointer
    // Events ולא HTML5 drag-and-drop — כי האחרון לא עובד במגע.
    $$('.part--edit', f).forEach((part) => {
      makeSortable(part, '.ing-row', (order) => {
        collect(); const sec = model.sections[+part.dataset.si];
        sec.ingredients = order.map((i) => sec.ingredients[i]); draw();
      }, 'ii');
      makeSortable(part, '.step-row', (order) => {
        collect(); const sec = model.sections[+part.dataset.si];
        sec.steps = order.map((i) => sec.steps[i]); draw();
      }, 'ki');
    });

    if (editId) renderMediaEdit(editId); else drawNewMedia();

    // השלמת מוצר מהקטלוג: הקטלוג גדל מעצמו, וזו הדרך ששמות מתכנסים (3.2).
    let timer;
    $$('[data-f="product"]', f).forEach((inp) => inp.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const q = inp.value.trim();
        if (q.length < 2) return;
        const { products } = await api('products', { q });
        state.products = products;
        $('#products').innerHTML = products.map((p) => `<option value="${esc(p)}">`).join('');
      }, 200);
    }));

    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      collect();
      const out = $('#edit-msg');
      const btn = f.querySelector('button[type="submit"]');
      btn.disabled = true;
      try {
        const payload = {
          id: editId || undefined, title: model.title, visibility: model.visibility,
          servings: model.yield_mode === 'servings' ? (model.servings || null) : null,
          yield_text: model.yield_mode === 'text' ? model.yield_text.trim() : '',
          difficulty: model.difficulty || null,
          work_minutes: model.work_minutes || null, wait_minutes: model.wait_minutes || null,
          tips: model.tips, tag_ids: [...model.tag_ids], comments_open: model.comments_open,
          ...(model.source && !editId ? { source_url: model.source.url, source_name: model.source.name, source_author: model.source.author } : {}),
          ...(model.source ? { source_rewritten: !!model.source.rewritten } : {}),
          ...(model.snapshot && !editId ? { snapshot: model.snapshot } : {}),
          ...(model.import_log_id && !editId ? { import_log_id: model.import_log_id } : {}),
          sections: model.sections.map((s) => ({
            name: s.name,
            ingredients: s.ingredients.filter((i) => i.free_text.trim()),
            steps: s.steps.filter((t) => t.trim()).map((t) => ({ text: t })),
          })),
        };
        const { id: savedId } = await api('recipe-save', payload);
        // ייבוא: התמונות והסרטונים מצורפים כקישורים אחרי שיש מזהה. כשל
        // באחד מהם לא מפיל את השמירה — המתכון כבר קיים, והמדיה היא תוספת.
        if (model.pending_media && !editId) {
          out.textContent = 'מצרף תמונות וסרטונים…'; out.className = 'note'; out.hidden = false;
          for (const u of model.pending_media.images) { try { await api('media-link', { recipe_id: savedId, url: u, kind: 'image' }); } catch { /* ממשיכים */ } }
          for (const u of model.pending_media.videos) { try { await api('media-link', { recipe_id: savedId, url: u, kind: 'video' }); } catch { /* ממשיכים */ } }
        }
        if (!editId) {
          const errs = await uploadNewMedia(savedId, out);
          // המתכון נשמר; מה שלא עלה — לעורך, כדי לנסות שוב משם
          if (errs.length) { alert('המתכון נשמר, אבל חלק מהמדיה לא עלה:\n' + errs.join('\n')); go(`#/edit/${savedId}`); return; }
        }
        go(`#/r/${savedId}`);
      } catch (err) {
        out.textContent = err.message; out.className = 'note note--err'; out.hidden = false;
        btn.disabled = false;
      }
    });
  };

  draw();
}

// ───────────────────────── רשימות קניות ─────────────────────────

const qtyText = (i) => i.amount != null
  ? `${fmtAmount(i.amount)}${i.amount_max != null ? '–' + fmtAmount(i.amount_max) : ''} ${UNITS[i.unit] || i.unit}`
  : (i.free_text || '');

async function renderShoppingLists() {
  const { lists } = await api('shopping-lists');
  view.innerHTML = `
    <section class="card settings">
      <a class="link" href="#/">‹ לרשימה</a>
      <h2>🛒 רשימות קניות</h2>
      <p class="muted">רשימה נבנית ממתכונים — בכפתור "לרשימת קניות" בכל מתכון — ורכיבים זהים מתאחדים. אפשר להוסיף שורות ידנית.</p>
      <form id="list-new" class="pantry__add">
        <input name="name" placeholder="שם לרשימה חדשה (לא חובה)" maxlength="60" autocomplete="off">
        <button class="btn btn--primary" type="submit">+ רשימה</button>
      </form>
      <section class="list" id="lists">
        ${lists.length ? lists.map((l) => `
          <a class="item" href="#/shopping/${l.id}">
            <span class="item__thumb item__thumb--empty">🛒</span>
            <div class="item__main">
              <strong>${esc(l.name || 'רשימה ' + l.id)}</strong>
              <span class="muted">${l.recipes} מתכונים · ${l.checked}/${l.items} נקנו · ${esc(l.created_at.slice(0, 10))}</span>
            </div>
          </a>`).join('') : '<p class="muted">עדיין אין רשימות.</p>'}
      </section>
    </section>`;
  $('#list-new').addEventListener('submit', async (e) => {
    e.preventDefault();
    try { const { list } = await api('shopping-create', { recipes: {}, name: e.target.name.value }); go(`#/shopping/${list.id}`); }
    catch (err) { alert(err.message); }
  });
}

async function renderShoppingList(id) {
  let { list } = await api('shopping-get', { id });
  const draw = () => {
    const open = list.items.filter((i) => !i.checked), done = list.items.filter((i) => i.checked);
    const row = (i) => `
      <li class="shop-item ${i.checked ? 'is-done' : ''} ${i.optional ? 'is-optional' : ''}" data-id="${i.id}">
        <label class="shop-item__check">
          <input type="checkbox" ${i.checked ? 'checked' : ''}>
          <span class="shop-item__text">
            <strong>${esc(i.label)}</strong>${qtyText(i) ? ` — ${esc(qtyText(i))}` : ''}
            ${i.recipe_count > 1 ? `<span class="muted small">(מ-${i.recipe_count} מתכונים)</span>` : ''}
            ${i.optional ? '<span class="muted small">(לא חובה)</span>' : ''}
            ${i.manual ? '<span class="muted small">✍</span>' : ''}
          </span>
        </label>
        <button class="btn btn--ghost btn--tiny" type="button" data-rm="${i.id}" aria-label="הסר">✕</button>
      </li>`;
    view.innerHTML = `
      <section class="card settings shopping">
        <a class="link" href="#/shopping">‹ כל הרשימות</a>
        <h2 class="shopping__title">🛒 <span id="list-name" contenteditable="true" spellcheck="false">${esc(list.name || 'רשימה ' + list.id)}</span></h2>
        <p class="muted small">${list.recipes.length ? 'מתכונים: ' + list.recipes.map((r) => r.id
          ? `<a href="#/r/${r.id}">${esc(r.title)}</a>${r.factor !== 1 ? ` ×${fmtAmount(r.factor)}` : ''} <button class="link link--danger" type="button" data-rm-recipe="${r.id}" aria-label="הסר מתכון">✕</button>`
          : esc(r.title)).join(' · ') : 'בלי מתכונים — רשימה ידנית.'}</p>
        <form id="item-add" class="pantry__add">
          <input name="label" placeholder="שורה ידנית: שקיות אשפה, חלב…" maxlength="80" autocomplete="off" required>
          <button class="btn" type="submit">הוסף</button>
        </form>
        <ul class="shop-list">${open.map(row).join('') || '<li class="muted">הכול נקנה 🎉</li>'}</ul>
        ${done.length ? `<h3 class="muted">נקנה (${done.length})</h3><ul class="shop-list">${done.map(row).join('')}</ul>` : ''}
        <div class="actions actions--wrap">
          <button class="btn" type="button" id="share">📤 שתף</button>
          <button class="btn btn--ghost" type="button" id="clear-checked" ${done.length ? '' : 'disabled'}>נקה מסומנים</button>
          <button class="btn btn--ghost btn--danger" type="button" id="del-list">מחק רשימה</button>
        </div>
        <p class="note" id="shop-msg" hidden></p>
      </section>`;
    const msg = $('#shop-msg');
    const note = (t, k) => { msg.textContent = t; msg.className = 'note' + (k ? ' note--' + k : ''); msg.hidden = !t; };
    $$('.shop-item input').forEach((c) => c.addEventListener('change', async () => {
      const li = c.closest('.shop-item'); const it = list.items.find((i) => i.id === +li.dataset.id);
      it.checked = c.checked;
      try { await api('shopping-check', { item_id: it.id, checked: c.checked }); draw(); } catch (err) { note(err.message, 'err'); }
    }));
    $$('[data-rm]').forEach((b) => b.addEventListener('click', async () => {
      try { await api('shopping-remove-item', { item_id: +b.dataset.rm }); list.items = list.items.filter((i) => i.id !== +b.dataset.rm); draw(); }
      catch (err) { note(err.message, 'err'); }
    }));
    $$('[data-rm-recipe]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('להסיר את המתכון מהרשימה? הרכיבים שלו יוסרו (ידני וסימונים נשארים).')) return;
      try { ({ list } = await api('shopping-remove-recipe', { id, recipe_id: +b.dataset.rmRecipe })); draw(); } catch (err) { note(err.message, 'err'); }
    }));
    $('#item-add').addEventListener('submit', async (e) => {
      e.preventDefault();
      try { ({ list } = await api('shopping-add-item', { id, label: e.target.label.value })); draw(); $('#item-add input').focus(); }
      catch (err) { note(err.message, 'err'); }
    });
    $('#clear-checked').addEventListener('click', async () => {
      try { ({ list } = await api('shopping-clear-checked', { id })); draw(); } catch (err) { note(err.message, 'err'); }
    });
    $('#del-list').addEventListener('click', async () => {
      if (!confirm('למחוק את הרשימה?')) return;
      try { await api('shopping-delete', { id }); go('#/shopping'); } catch (err) { note(err.message, 'err'); }
    });
    $('#share').addEventListener('click', async () => {
      try {
        const { text } = await api('shopping-text', { id });
        if (navigator.share) { await navigator.share({ title: list.name || 'רשימת קניות', text }); return; }
        await navigator.clipboard.writeText(text);
        note('הרשימה הועתקה — אפשר להדביק בוואטסאפ', 'ok');
      } catch (err) { if (err.name !== 'AbortError') note(err.message, 'err'); }
    });
    const nameEl = $('#list-name');
    nameEl.addEventListener('blur', async () => {
      const name = nameEl.textContent.trim();
      if (name === (list.name || 'רשימה ' + list.id)) return;
      try { ({ list } = await api('shopping-rename', { id, name })); } catch (err) { note(err.message, 'err'); }
    });
    nameEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); nameEl.blur(); } });
  };
  draw();
}

// ───────────────────────── מצב בישול ─────────────────────────

/** "10 דקות", "כ-30 דק׳", "שעה", "שעה וחצי" בטקסט של שלב → דקות לטיימרים. */
function timersIn(text) {
  const out = [];
  const re = /(\d+(?:[.,]\d+)?)\s*(?:-|–)?\s*(\d+)?\s*(דקות|דקה|דק׳|דק'|שעות|שעה|שניות)/g;
  let m;
  while ((m = re.exec(text))) {
    const n = parseFloat((m[2] || m[1]).replace(',', '.'));
    const unit = m[3];
    const mins = unit.startsWith('שע') ? n * 60 : unit.startsWith('שנ') ? n / 60 : n;
    if (mins >= 0.5 && mins <= 24 * 60) out.push({ label: m[0].trim(), minutes: mins });
  }
  if (/שעה וחצי/.test(text)) out.push({ label: 'שעה וחצי', minutes: 90 });
  else if (/חצי שעה/.test(text)) out.push({ label: 'חצי שעה', minutes: 30 });
  else if (/רבע שעה/.test(text)) out.push({ label: 'רבע שעה', minutes: 15 });
  else if (/(^|[^\d])שעה(?![\d])/.test(text) && !out.some((t) => t.minutes === 60)) out.push({ label: 'שעה', minutes: 60 });
  return out.slice(0, 3);
}

const cookTimers = [];   // {id, label, end, timer, el}
function cookTick() {
  for (const t of cookTimers) {
    const left = Math.max(0, Math.round((t.end - Date.now()) / 1000));
    const mm = String(Math.floor(left / 60)).padStart(2, '0'), ss = String(left % 60).padStart(2, '0');
    if (t.el) t.el.querySelector('.timer__left').textContent = `${mm}:${ss}`;
    if (left === 0 && !t.fired) {
      t.fired = true; t.el?.classList.add('is-done');
      try { navigator.vibrate?.([300, 150, 300, 150, 600]); } catch {}
      try { const ctx = new (window.AudioContext || window.webkitAudioContext)(); const o = ctx.createOscillator(); o.frequency.value = 880; o.connect(ctx.destination); o.start(); setTimeout(() => { o.stop(); ctx.close(); }, 900); } catch {}
    }
  }
}
setInterval(cookTick, 500);

async function renderCook(id) {
  const [{ recipe: r }, { done }] = await Promise.all([api('recipe', { id }), api('cook-progress', { recipe_id: id })]);
  const steps = [];
  r.sections.forEach((s, si) => s.steps.forEach((st, ki) => steps.push({ ...st, si, ki, section: s.name, ingredients: s.ingredients })));
  if (!steps.length) { view.innerHTML = '<section class="card"><p class="note note--warn">למתכון הזה אין שלבים.</p><a class="btn" href="#/r/' + id + '">למתכון</a></section>'; return; }
  const doneSet = new Set(done);
  let idx = Math.max(0, steps.findIndex((s) => !doneSet.has(s.id)));
  if (idx === -1 || steps.every((s) => doneSet.has(s.id))) idx = 0;

  // המסך לא נכבה בזמן הבישול (Wake Lock; דורש HTTPS, ובדפדפן ישן פשוט אין)
  let lock = null;
  const acquire = async () => { try { lock = await navigator.wakeLock?.request('screen'); } catch { lock = null; } };
  acquire();
  const onVis = () => { if (document.visibilityState === 'visible' && !lock) acquire(); };
  document.addEventListener('visibilitychange', onVis);
  const release = () => { document.removeEventListener('visibilitychange', onVis); window.removeEventListener('hashchange', release); try { lock?.release(); } catch {} };
  window.addEventListener('hashchange', release);

  const draw = () => {
    const s = steps[idx];
    const multi = r.sections.length > 1;
    const doneCount = steps.filter((x) => doneSet.has(x.id)).length;
    view.innerHTML = `
      <section class="cook">
        <header class="cook__head">
          <a class="link" href="#/r/${r.id}">‹ ${esc(r.title)}</a>
          <span class="muted">${doneCount}/${steps.length} בוצעו</span>
        </header>
        ${multi ? `<nav class="subnav cook__parts" aria-label="חלקים">${r.sections.map((sec, si) => `
          <a href="#/cook/${r.id}" data-part="${si}" class="${si === s.si ? 'is-on' : ''}">${esc(sec.name || 'חלק ' + (si + 1))}</a>`).join('')}</nav>` : ''}
        <div class="cook__timers" id="timers"></div>
        <article class="cook__step ${doneSet.has(s.id) ? 'is-done' : ''}">
          <div class="cook__no">שלב ${s.ki + 1}${multi ? ` · ${esc(s.section || 'חלק ' + (s.si + 1))}` : ''}</div>
          <p class="cook__text">${esc(s.text)}</p>
          ${timersIn(s.text).length ? `<div class="actions actions--wrap">${timersIn(s.text).map((t, i) => `<button class="btn btn--ghost" type="button" data-timer="${t.minutes}">⏱ ${esc(t.label)}</button>`).join('')}</div>` : ''}
        </article>
        <div class="cook__nav">
          <button class="btn btn--big" type="button" id="prev" ${idx === 0 ? 'disabled' : ''}>‹ הקודם</button>
          <button class="btn btn--big btn--primary" type="button" id="done">${doneSet.has(s.id) ? (idx < steps.length - 1 ? 'הבא ›' : 'סיימתי 🎉') : '✓ בוצע' + (idx < steps.length - 1 ? ', הבא ›' : '')}</button>
        </div>
        <details class="cook__ings" ${s.ki === 0 ? 'open' : ''}>
          <summary>רכיבים${multi ? ' של החלק' : ''} (${s.ingredients.length})</summary>
          <ul class="ings">${s.ingredients.map((i) => `<li class="${i.optional ? 'is-optional' : ''}"><span class="ing__free">${esc(i.free_text)}</span>${scaled(i, 1) ? `<span class="ing__calc">${esc(scaled(i, 1))}</span>` : ''}</li>`).join('')}</ul>
        </details>
        <div class="actions"><button class="link" type="button" id="reset">התחל מחדש</button></div>
      </section>`;
    drawTimers();
    $$('[data-part]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); idx = steps.findIndex((x) => x.si === +a.dataset.part); draw(); }));
    $('#prev').addEventListener('click', () => { idx--; draw(); });
    $('#done').addEventListener('click', async () => {
      if (!doneSet.has(s.id)) { doneSet.add(s.id); api('cook-done', { step_id: s.id, done: true }).catch(() => {}); }
      if (idx < steps.length - 1) { idx++; draw(); }
      else { draw(); }
    });
    $('#reset').addEventListener('click', async () => {
      if (!confirm('לאפס את הסימונים?')) return;
      await api('cook-reset', { recipe_id: r.id }).catch(() => {});
      doneSet.clear(); idx = 0; draw();
    });
    $$('[data-timer]').forEach((b) => b.addEventListener('click', () => {
      const mins = +b.dataset.timer;
      cookTimers.push({ id: Date.now(), label: `${esc(s.text.slice(0, 24))}…`, end: Date.now() + mins * 60000, fired: false });
      drawTimers();
    }));
  };
  const drawTimers = () => {
    const box = $('#timers'); if (!box) return;
    box.innerHTML = cookTimers.map((t) => `<div class="timer ${t.fired ? 'is-done' : ''}" data-tid="${t.id}"><span class="timer__left">--:--</span> <span class="muted small">${t.label}</span> <button class="btn btn--ghost btn--tiny" type="button" data-tkill="${t.id}">✕</button></div>`).join('');
    cookTimers.forEach((t) => { t.el = box.querySelector(`[data-tid="${t.id}"]`); });
    $$('[data-tkill]').forEach((b) => b.addEventListener('click', () => { const i = cookTimers.findIndex((t) => t.id === +b.dataset.tkill); if (i >= 0) cookTimers.splice(i, 1); drawTimers(); }));
    cookTick();
  };
  draw();
}

// ───────────────────────── מה יש לי בבית ─────────────────────────

async function renderPantry() {
  let pantry = await api('pantry');
  let maxMissing = 2;
  let aiOk = false;
  api('ai-status').then((r) => { aiOk = r.available; $('#photo-hint').textContent = aiOk ? 'מצלמים את המקרר, המזווה או שקית הקניות — הבינה מזהה את המוצרים ואתה מאשר.' : 'זיהוי בתמונה דורש מפתח API (המפתח מגדיר). בינתיים — להקליד.'; }).catch(() => {});

  view.innerHTML = `
    <section class="card settings pantry">
      <a class="link" href="#/">‹ לרשימה</a>
      <h2>🥕 מה יש לי בבית</h2>
      <p class="muted">מזינים את המוצרים שיש — בהקלדה או בצילום — ומקבלים את המתכונים שאפשר להכין מהם, עם "חסר: …" למה שכמעט.</p>

      <form id="pantry-add" class="pantry__add">
        <input name="name" placeholder="מוצר, למשל: עגבניות, גבינה צהובה" autocomplete="off" maxlength="60" required>
        <button class="btn btn--primary" type="submit">הוסף</button>
      </form>
      <label class="upload pantry__photo">
        <input type="file" accept="image/*" capture="environment" hidden id="pantry-file">
        <span class="btn">📷 צלם מוצרים</span>
        <small class="muted" id="photo-hint"></small>
      </label>
      <p class="note" id="pantry-msg" hidden></p>
      <div id="pantry-confirm" hidden></div>

      <h3>במזווה <span class="muted" id="pantry-count"></span></h3>
      <div class="chips" id="pantry-chips"></div>
      <div class="actions"><button class="link link--danger" type="button" id="pantry-clear">נקה הכול</button></div>

      <h3>יש לי תמיד <span class="muted">— מוצרי יסוד, נחשבים קיימים</span></h3>
      <div class="chips" id="pantry-staples"></div>

      <h3>מה אפשר להכין</h3>
      <div class="logfilter">
        <select id="pantry-miss" aria-label="חסרים">
          <option value="0">רק מה שאפשר להכין עכשיו</option>
          <option value="1">חסר עד מוצר אחד</option>
          <option value="2" selected>חסרים עד 2 מוצרים</option>
          <option value="3">חסרים עד 3 מוצרים</option>
        </select>
      </div>
      <div id="pantry-results" class="list"></div>
    </section>`;

  const msgEl = $('#pantry-msg');
  const note = (t, k) => { msgEl.textContent = t; msgEl.className = 'note' + (k ? ' note--' + k : ''); msgEl.hidden = !t; };

  const drawPantry = () => {
    $('#pantry-count').textContent = pantry.items.length ? `(${pantry.items.length})` : '';
    $('#pantry-chips').innerHTML = pantry.items.length
      ? pantry.items.map((it) => `<span class="chip chip--item">${it.source === 'photo' ? '📷 ' : ''}${esc(it.name)} <button type="button" data-rm="${it.id}" aria-label="הסר">✕</button></span>`).join('')
      : '<p class="muted">עדיין ריק. מוסיפים מוצר למעלה.</p>';
    $$('[data-rm]').forEach((b) => b.addEventListener('click', async () => {
      try { pantry = await api('pantry-remove', { id: +b.dataset.rm }); drawPantry(); match(); } catch (err) { note(err.message, 'err'); }
    }));
    $('#pantry-staples').innerHTML = pantry.staples.map((s) => `
      <label class="chip chip--pick"><input type="checkbox" data-staple="${esc(s.name)}" ${s.on ? 'checked' : ''}> ${esc(s.name)}</label>`).join('');
    $$('[data-staple]').forEach((c) => c.addEventListener('change', async () => {
      try { pantry = await api('pantry-staple', { name: c.dataset.staple, on: c.checked }); match(); } catch (err) { note(err.message, 'err'); }
    }));
  };

  const match = async () => {
    const box = $('#pantry-results');
    if (!pantry.items.length) { box.innerHTML = '<p class="muted">כשיהיו מוצרים במזווה — המתכונים יופיעו כאן.</p>'; return; }
    box.innerHTML = '<p class="muted">מחפש…</p>';
    try {
      const { recipes } = await api('pantry-match', { max_missing: maxMissing });
      box.innerHTML = recipes.length ? recipes.map((r) => `
        <a class="item item--pantry" href="#/r/${r.id}">
          ${r.thumb ? `<img class="item__thumb" src="${esc(r.thumb)}" alt="" loading="lazy">` : '<span class="item__thumb item__thumb--empty">🍲</span>'}
          <div class="item__main">
            <strong>${esc(r.title)}</strong>
            <span class="muted">${esc(r.owner_name)}${r.source_name ? ` · 🌐 ${esc(r.source_name)}` : ''}${r.work_minutes || r.wait_minutes ? ' · ' + minutes((r.work_minutes || 0) + (r.wait_minutes || 0)) : ''}</span>
            <span class="coverage"><span class="coverage__bar"><span style="width:${Math.round(r.coverage * 100)}%"></span></span> ${r.have}/${r.need} רכיבים</span>
            ${r.missing.length ? `<span class="muted small">חסר: ${r.missing.map(esc).join(', ')}</span>` : '<span class="badge badge--public">אפשר להכין עכשיו ✓</span>'}
          </div>
        </a>`).join('')
      : '<p class="muted">אין מתכון שמתאים. אפשר להרחיב ל"חסרים עד 3 מוצרים", או להוסיף עוד מוצרים.</p>';
    } catch (err) { box.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; }
  };

  $('#pantry-add').addEventListener('submit', async (e) => {
    e.preventDefault();
    const names = e.target.name.value.split(/[,،\n]+/).map((s) => s.trim()).filter(Boolean);
    if (!names.length) return;
    try { pantry = await api('pantry-add', { names, source: 'text' }); e.target.reset(); e.target.name.focus(); drawPantry(); match(); }
    catch (err) { note(err.message, 'err'); }
  });
  $('#pantry-clear').addEventListener('click', async () => {
    if (!pantry.items.length || !confirm('לרוקן את המזווה?')) return;
    try { pantry = await api('pantry-clear'); drawPantry(); match(); } catch (err) { note(err.message, 'err'); }
  });
  $('#pantry-miss').addEventListener('change', (e) => { maxMissing = +e.target.value; match(); });

  // ── צילום: מכווצים בדפדפן, שולחים לזיהוי, ומאשרים מה להוסיף ──
  $('#pantry-file').addEventListener('change', async (e) => {
    const raw = e.target.files?.[0]; if (!raw) return;
    note('מזהה מוצרים בתמונה… (עד חצי דקה)');
    try {
      const file = await shrinkImage(raw);
      const fd = new FormData(); fd.append('file', file);
      const res = await fetch('./pantry.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json().catch(() => ({}));
      if (!data.success) throw new Error(data.error || 'הזיהוי נכשל');
      note('');
      const box = $('#pantry-confirm');
      if (!data.products.length) { note('לא זוהו מוצרי מזון בתמונה. לנסות צילום קרוב יותר, או להקליד.', 'warn'); return; }
      box.hidden = false;
      box.innerHTML = `
        <div class="note note--ok pantry__confirm">
          <strong>זוהו ${data.products.length} מוצרים — סמן מה להוסיף:</strong>
          <div class="chips">${data.products.map((p, i) => `<label class="chip chip--pick"><input type="checkbox" value="${esc(p)}" checked> ${esc(p)}</label>`).join('')}</div>
          <div class="actions">
            <button class="btn btn--primary" type="button" id="confirm-add">הוסף למזווה</button>
            <button class="btn btn--ghost" type="button" id="confirm-cancel">ביטול</button>
          </div>
        </div>`;
      $('#confirm-add').addEventListener('click', async () => {
        const names = $$('#pantry-confirm input:checked').map((c) => c.value);
        try { pantry = await api('pantry-add', { names, source: 'photo' }); box.hidden = true; drawPantry(); match(); note(`נוספו ${names.length} מוצרים`, 'ok'); }
        catch (err) { note(err.message, 'err'); }
      });
      $('#confirm-cancel').addEventListener('click', () => { box.hidden = true; });
    } catch (err) { note(err.message, 'err'); }
    e.target.value = '';
  });

  drawPantry();
  match();
}

// ───────────────────────── ייבוא מהרשת ─────────────────────────

async function renderImport(presetUrl = '') {
  view.innerHTML = `
    <section class="card settings">
      <a class="link" href="#/">‹ לרשימה</a>
      <h2>🌐 ייבוא מתכון מהרשת</h2>
      <p class="muted">מדביקים קישור לדף של מתכון. האפליקציה מחלצת את השם, הרכיבים, השלבים, הזמנים, התמונות והסרטון,
        ופותחת אותם בעורך לבדיקה — שום דבר לא נשמר עד שלוחצים "צור מתכון".</p>
      <form id="import-form" class="form">
        <label>קישור למתכון
          <input name="url" type="url" inputmode="url" dir="ltr" placeholder="https://…" required autocomplete="off" value="${esc(presetUrl)}">
        </label>
        <button class="btn btn--primary btn--wide" type="submit">חלץ מתכון</button>
        <p class="note" id="import-msg" hidden></p>
      </form>
      <details class="import-help">
        <summary>איך זה עובד, ומה עם זכויות יוצרים</summary>
        <p>רוב אתרי המתכונים מסמנים את המתכון בפורמט שגוגל קורא (schema.org/Recipe). האפליקציה קוראת את אותו סימון.
           כשאין סימון כזה, היא מנחשת לפי כותרות כמו "מצרכים" ו"אופן ההכנה" — ואז כדאי לבדוק היטב.</p>
        <p><strong>קרדיט:</strong> שם האתר, שם הכותב וקישור למקור נשמרים עם המתכון ומוצגים תמיד. אי אפשר למחוק אותם.
           התמונות והסרטונים אינם מועתקים לשרת — הם נשארים אצל בעליהם, ואנחנו רק מצביעים עליהם.</p>
        <p>מתכון מיובא נוצר <strong>פרטי</strong>. לפרסם אותו לציבור זו החלטה שלך, ועל אחריותך.</p>
      </details>
    </section>`;
  const form = $('#import-form');
  const out = $('#import-msg');
  const note = (t, k) => { out.textContent = t; out.className = 'note' + (k ? ' note--' + k : ''); out.hidden = !t; };
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button'); btn.disabled = true;
    note('מביא את הדף ומחלץ… (עד 15 שניות)');
    try {
      const { draft, ai_available } = await api('import-preview', { url: form.url.value.trim() });
      draft.ai_available = ai_available;
      await renderEditor(null, draft);
      window.scrollTo(0, 0);
    } catch (err) { note(err.message, 'err'); btn.disabled = false; }
  });
  if (presetUrl) form.requestSubmit();
}

// ───────────────────────── עורך: מדיה ─────────────────────────

/**
 * אזור המדיה בעורך. עצמאי מהטופס: העלאה נשמרת מיד בשרת ואינה חלק
 * מ"שמור שינויים" — אחרת המשתמש היה מעלה ארבע תמונות, שוכח ללחוץ שמור,
 * ומאבד את כולן.
 */
async function renderMediaEdit(recipeId) {
  const box = $('#media-edit');
  if (!box) return;
  const [{ recipe: r }, { limits }] = await Promise.all([api('recipe', { id: recipeId }), api('media-limits')]);
  const images = r.media.filter((m) => m.kind === 'image');
  const videos = r.media.filter((m) => m.kind === 'video');

  box.innerHTML = `
    <h4>תמונות <span class="muted">(${images.length}/${limits.max_images})</span></h4>
    <div class="media-grid">
      ${images.map((m) => `
        <figure class="media-item ${m.id === r.main_media_id ? 'is-main' : ''}">
          <img src="${esc(m.url)}" alt="">
          <figcaption>
            ${m.id === r.main_media_id ? '<span class="badge badge--mine">ראשית</span>'
              : `<button class="link" type="button" data-main="${m.id}">קבע כראשית</button>`}
            <button class="btn btn--ghost btn--danger" type="button" data-del-media="${m.id}">✕</button>
          </figcaption>
        </figure>`).join('')}
    </div>
    ${images.length < limits.max_images ? `
      <label class="upload">
        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden data-up="image">
        <span class="btn">+ תמונה</span>
        <small class="muted">מוקטנת בטלפון לפני ההעלאה · עד ${humanBytes(limits.image_max)}</small>
      </label>` : ''}

    <h4>סרטונים <span class="muted">(${videos.length}/${limits.max_videos})</span></h4>
    ${videos.map((v) => `
      <div class="media-row media-row--video">
        ${v.source === 'upload' ? `<video src="${esc(v.url)}" controls playsinline preload="metadata"></video>` : `<span>🔗 ${esc(v.url)}</span>`}
        <button class="btn btn--ghost btn--danger" type="button" data-del-media="${v.id}">✕</button>
      </div>`).join('')}
    ${videos.length < limits.max_videos ? `
      <div class="media-add">
        <!-- לא <form>: אזור המדיה יושב בתוך טופס העורך, ודפדפן זורק תג form
             מקונן — כך שדה הקישור הפך לשדה חובה של "שמור שינויים" וחסם אותו. -->
        <div class="media-link" id="media-link">
          <input name="video_url" type="text" inputmode="url" placeholder="קישור ליוטיוב או וימאו (מומלץ לסרטון ארוך)"
                 autocomplete="off" aria-label="קישור לסרטון">
          <button class="btn" type="button" id="media-link-add">הוסף</button>
        </div>
        <div class="scan-pick">
          <label class="btn">🎥 צילום סרטון<input type="file" accept="video/*" capture="environment" hidden data-up="video"></label>
          <label class="btn">🎞 סרטון מהגלריה<input type="file" accept="video/mp4,video/quicktime,video/webm" hidden data-up="video"></label>
        </div>
        <small class="muted">עד ${humanBytes(limits.video_max)} — כ-10–15 שניות מהטלפון. סרטון ארוך — ביוטיוב, והקישור כאן.</small>
      </div>` : ''}

    <div class="quota">
      <div class="quota__bar"><span style="width:${Math.min(100, (limits.used / limits.quota) * 100).toFixed(1)}%"></span></div>
      <small class="muted">אחסון: ${humanBytes(limits.used)} מתוך ${humanBytes(limits.quota)}</small>
    </div>
    <p id="media-msg" class="note" hidden></p>`;

  const note = (t, k) => { const el = $('#media-msg'); el.textContent = t; el.className = 'note' + (k ? ' note--' + k : ''); el.hidden = !t; };

  $$('[data-up]', box).forEach((inp) => inp.addEventListener('change', async () => {
    const files = Array.from(inp.files || []);
    for (const raw of files) {
      try {
        note(`מכין ${raw.name}…`);
        const file = inp.dataset.up === 'image' ? await shrinkImage(raw) : raw;
        // בדיקה בדפדפן לפני שליחה — חוסכת העלאה של 100MB שתידחה בסוף.
        const cap = inp.dataset.up === 'image' ? limits.image_max : limits.video_max;
        if (file.size > cap) throw new Error(`${raw.name} גדול מדי (${humanBytes(file.size)}, התקרה ${humanBytes(cap)})`
          + (inp.dataset.up === 'video' ? '. סרטון ארוך — העלה ליוטיוב והדבק קישור.' : ''));
        await uploadFile(recipeId, file, (p) => note(`מעלה ${raw.name}… ${Math.round(p * 100)}%`));
      } catch (err) { note(err.message, 'err'); return; }
    }
    renderMediaEdit(recipeId);
  }));

  const addLink = async () => {
    const inp = $('#media-link input');
    const url = inp.value.trim();
    if (!url) { note('יש להדביק קישור קודם', 'warn'); inp.focus(); return; }
    try { await api('media-link', { recipe_id: recipeId, url }); renderMediaEdit(recipeId); }
    catch (err) { note(err.message, 'err'); }
  };
  $('#media-link-add')?.addEventListener('click', addLink);
  // Enter בשדה הקישור מוסיף קישור — ולא שולח את טופס המתכון כולו.
  $('#media-link input')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addLink(); } });

  $$('[data-del-media]', box).forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('למחוק?')) return;
    try { await api('media-delete', { id: +b.dataset.delMedia }); renderMediaEdit(recipeId); }
    catch (err) { note(err.message, 'err'); }
  }));
  $$('[data-main]', box).forEach((b) => b.addEventListener('click', async () => {
    try { await api('media-main', { recipe_id: recipeId, id: +b.dataset.main }); renderMediaEdit(recipeId); }
    catch (err) { note(err.message, 'err'); }
  }));
}

// ───────────────────────── הגדרות ─────────────────────────

const MB = 1048576;
const toMB = (b) => (b / MB) % 1 === 0 ? String(b / MB) : (b / MB).toFixed(1);
const fromMB = (v) => Math.round(parseFloat(v) * MB);

function settingsNav(active) {
  const items = [
    ['#/settings', 'פרטיות', false],
    ['#/settings/public', 'ציבוריות', true],
    ['#/settings/users', 'משתמשים', true],
    ['#/diag', 'פיתוח', true],
    ['#/logs', 'יומן', true],
    ['#/scout', 'סורק', true],
    ['#/index', 'אינדקס', true],
    ['#/rewrite', 'ניסוח', true],
    ['#/scans', 'סריקות', true],
    ['#/reports', 'דיווחים', true],
  ];
  return `<nav class="subnav" aria-label="הגדרות">${items
    .filter(([, , dev]) => !dev || state.user.is_developer)
    .map(([href, label]) => `<a href="${href}" class="${location.hash === href ? 'is-on' : ''}">${label}</a>`)
    .join('')}</nav>`;
}

/** הגדרות פרטיות — לכל משתמש. מה שהוא קובע לעצמו, ומה שחל עליו. */
async function renderSettingsPrivate() {
  const { limits, display_name } = await api('settings-private');
  view.innerHTML = `
    <section class="card settings">
      ${settingsNav()}
      <h2>הגדרות פרטיות</h2>
      <table class="kv">
        <tr><th>שם משתמש</th><td dir="ltr">${esc(state.user.username)}</td></tr>
        <tr><th>סוג החשבון</th><td>${state.user.is_developer ? 'מפתח — הגדרות ציבוריות, ניהול משתמשים ואזור פיתוח נמצאים בתפריט ☰'
                                    : state.user.role === 'admin' ? 'מנהל' : 'משתמש'}</td></tr>
      </table>
      <form id="priv" class="form">
        <label>שם לתצוגה <input name="display_name" value="${esc(display_name)}" maxlength="60" required></label>
        <button class="btn btn--primary" type="submit">שמור</button>
        <p id="priv-msg" class="note" hidden></p>
      </form>
      <h3>המגבלות שחלות עליך</h3>
      <p class="muted">נקבעות על ידי המפתח. אפשר לבקש שינוי.</p>
      <table class="kv">
        <tr><th>גודל מרבי לסרטון</th><td>${toMB(limits.video_max)} MB</td></tr>
        <tr><th>גודל מרבי לתמונה</th><td>${toMB(limits.image_max)} MB</td></tr>
        <tr><th>אחסון כולל</th><td>${toMB(limits.used)} מתוך ${toMB(limits.quota)} MB</td></tr>
      </table>
      <div class="quota"><div class="quota__bar"><span style="width:${Math.min(100, (limits.used / limits.quota) * 100).toFixed(1)}%"></span></div></div>
      <h3>🔒 פרטיות</h3>
      <form id="privacy" class="form">
        <label class="check"><input type="checkbox" name="discoverable"> אנשים יכולים למצוא אותי בחיפוש לפי שם</label>
        <label>מי יכול לשתף איתי מתכונים ולהמליץ לי
          <select name="share_from">
            <option value="all">כולם</option>
            <option value="following">רק מי שאני עוקב אחריו</option>
            <option value="none">אף אחד</option>
          </select></label>
        <label class="check"><input type="checkbox" name="personalize"> התאמה אישית — המלצות לפי מה שאני שומר ומבשל</label>
        <p class="note" id="privacy-msg" hidden></p>
      </form>
      <h4>אנשים שחסמתי</h4>
      <div id="blocks" class="people-list"><p class="muted small">טוען…</p></div>
      <h3>📲 אפליקציה בטלפון</h3>
      <div id="install-box" class="install"></div>
      <h3>מסמכים</h3>
      <p class="small"><a href="./privacy.html" target="_blank" rel="noopener">מדיניות פרטיות</a> · <a href="./delete-account.html" target="_blank" rel="noopener">מחיקת חשבון ונתונים</a></p>
      <details class="danger-zone">
        <summary>מחיקת החשבון</summary>
        <p class="small">מוחק לצמיתות את החשבון ואת כל מה שבו: המתכונים שלך (גם הציבוריים), תמונות וסרטונים שהעלית, תגובות,
          רשימות קניות, מעקבים ושיתופים. <strong>אי אפשר לשחזר.</strong></p>
        <form id="del-account" class="form">
          <label>הסיסמה שלך, לאישור <input name="password" type="password" autocomplete="current-password" required></label>
          <button class="btn btn--danger" type="submit">מחק את החשבון לצמיתות</button>
          <p class="note" id="del-msg" hidden></p>
        </form>
      </details>
    </section>`;
  drawInstall($('#install-box'));
  const pf = $('#privacy');
  const drawBlocks = (blocks) => {
    $('#blocks').innerHTML = blocks.length ? blocks.map((p) => personRow(p, `<button class="btn btn--small" type="button" data-unblock="${p.id}">ביטול חסימה</button>`)).join('')
      : '<p class="muted small">לא חסמת אף אחד.</p>';
  };
  api('privacy').then(({ prefs, blocks }) => {
    pf.discoverable.checked = prefs.discoverable; pf.share_from.value = prefs.share_from; pf.personalize.checked = prefs.personalize;
    drawBlocks(blocks);
  }).catch(() => {});
  pf.addEventListener('change', async () => {
    const m = $('#privacy-msg');
    try {
      await api('privacy-save', { discoverable: pf.discoverable.checked, share_from: pf.share_from.value, personalize: pf.personalize.checked });
      m.textContent = 'נשמר ✓'; m.className = 'note note--ok';
    } catch (err) { m.textContent = err.message; m.className = 'note note--err'; }
    m.hidden = false;
  });
  $('#blocks').addEventListener('click', async (e) => {
    const id = e.target.dataset.unblock;
    if (!id) return;
    try { drawBlocks((await api('unblock', { id: +id })).blocks); } catch (err) { alert(err.message); }
  });
  $('#del-account').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!confirm('למחוק את החשבון לצמיתות? אי אפשר לשחזר.')) return;
    const m = $('#del-msg');
    try { await api('account-delete', { password: e.target.password.value }); alert('החשבון נמחק.'); location.href = './index.html'; }
    catch (err) { m.textContent = err.message; m.className = 'note note--err'; m.hidden = false; }
  });
  $('#priv').addEventListener('submit', async (e) => {
    e.preventDefault();
    const out = $('#priv-msg');
    try {
      await api('settings-private-save', { display_name: e.target.display_name.value });
      state.user.display_name = e.target.display_name.value;
      $('#who').textContent = state.user.display_name;
      out.textContent = 'נשמר'; out.className = 'note note--ok'; out.hidden = false;
    } catch (err) { out.textContent = err.message; out.className = 'note note--err'; out.hidden = false; }
  });
}

// ───────────────────────── התקנה כאפליקציה ─────────────────────────
// Chrome/Edge/Samsung באנדרואיד שולחים beforeinstallprompt — שומרים אותו,
// וכפתור בהגדרות מפעיל אותו. באייפון אין אירוע כזה: ההתקנה היא רק דרך
// "שיתוף" → "הוספה למסך הבית" בספארי, ולכן שם מוצגת הוראה במקום כפתור.
let installPrompt = null;
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); installPrompt = e; const box = $('#install-box'); if (box) drawInstall(box); });
window.addEventListener('appinstalled', () => { installPrompt = null; const box = $('#install-box'); if (box) drawInstall(box, true); });
const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

function drawInstall(box, justInstalled = false) {
  const ua = navigator.userAgent;
  const ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (isStandalone() || justInstalled) {
    box.innerHTML = '<p class="note note--ok">האפליקציה מותקנת ופתוחה כאפליקציה. ✓</p>';
    return;
  }
  if (installPrompt) {
    box.innerHTML = `
      <p class="muted">אייקון במסך הבית, מסך מלא בלי שורת הכתובת, ונפתח ישר למתכונים.</p>
      <button class="btn btn--primary" type="button" id="install-btn">📲 התקן את האפליקציה</button>`;
    $('#install-btn', box).addEventListener('click', async () => {
      const p = installPrompt; installPrompt = null;
      p.prompt();
      const { outcome } = await p.userChoice.catch(() => ({ outcome: 'dismissed' }));
      box.innerHTML = outcome === 'accepted' ? '<p class="note note--ok">מותקנת. האייקון במסך הבית. ✓</p>'
        : '<p class="muted">ההתקנה בוטלה. אפשר גם מתפריט הדפדפן ⋮ → "התקנת אפליקציה" / "הוספה למסך הבית".</p>';
    });
    return;
  }
  box.innerHTML = ios ? `
      <p class="muted">באייפון ההתקנה היא דרך ספארי:</p>
      <ol class="install__steps">
        <li>לפתוח את הדף הזה ב<strong>ספארי</strong> (לא בכרום ולא מתוך וואטסאפ).</li>
        <li>ללחוץ על כפתור <strong>השיתוף</strong> ⬆️ בתחתית המסך.</li>
        <li>לבחור <strong>"הוספה למסך הבית"</strong>, ואז "הוסף".</li>
      </ol>` : `
      <p class="muted">הדפדפן עוד לא הציע התקנה. אפשר להתקין מהתפריט שלו:</p>
      <ol class="install__steps">
        <li>בכרום: תפריט <strong>⋮</strong> למעלה.</li>
        <li><strong>"התקנת אפליקציה"</strong> או <strong>"הוספה למסך הבית"</strong>.</li>
      </ol>
      <p class="muted small">אם נפתח מתוך וואטסאפ או פייסבוק — קודם "פתח בכרום".</p>`;
}

/** הגדרות ציבוריות — המפתח בלבד. חלות על כל מי שאין לו דריסה אישית. */
async function renderSettingsPublic() {
  const { settings, is_developer } = await api('settings-public');
  if (!is_developer) { go('#/settings'); return; }
  view.innerHTML = `
    <section class="card settings">
      ${settingsNav()}
      <h2>הגדרות ציבוריות</h2>
      <p class="muted">חלות על כל חשבון סטנדרטי. משתמש עם ערך אישי (ב"ניהול משתמשים") מקבל אותו במקום.</p>
      <form id="pub" class="form">
        ${Object.entries(settings).map(([key, s]) => `
          <label>${esc(s.label)}
            <span class="input-unit">
              <input name="${key}" type="number" step="0.5" min="${toMB(s.min)}" max="${toMB(s.max)}"
                     value="${toMB(s.value)}" inputmode="decimal" required>
              <span>MB</span>
            </span>
            <small class="muted">בין ${toMB(s.min)} ל-${toMB(s.max)} MB · ברירת מחדל ${toMB(s.default)}</small>
          </label>`).join('')}
        <button class="btn btn--primary" type="submit">שמור</button>
        <p id="pub-msg" class="note" hidden></p>
      </form>

      <h3>מפתחות API</h3>
      <p class="muted">נשמרים בקובץ בשרת, מחוץ לגיט ומחוץ לפריסה. המפתח עצמו לא מוצג שוב אחרי השמירה — רק סיומת לזיהוי.</p>
      <div id="secrets"></div>
    </section>`;
  const drawSecrets = (secrets) => {
    $('#secrets').innerHTML = Object.entries(secrets).map(([key, s]) => `
      <form class="form secret" data-key="${key}">
        <label>${esc(s.label)}
          ${s.set ? `<span class="badge badge--public">מוגדר ${esc(s.hint || '')}</span>` : '<span class="badge">לא מוגדר</span>'}
          <input name="value" type="password" autocomplete="off" dir="ltr" placeholder="${esc(s.prefix)}…" ${s.set ? '' : 'required'}>
        </label>
        <div class="actions">
          <button class="btn btn--primary" type="submit">${s.set ? 'החלף' : 'שמור'}</button>
          ${s.set ? '<button class="btn btn--ghost btn--danger" type="button" data-remove>הסר</button>' : ''}
        </div>
        <p class="note" hidden></p>
      </form>`).join('');
    $$('.secret').forEach((f) => {
      const out = $('.note', f);
      const say2 = (t, k) => { out.textContent = t; out.className = 'note note--' + k; out.hidden = false; };
      f.addEventListener('submit', async (e) => {
        e.preventDefault();
        try { drawSecrets((await api('secret-set', { key: f.dataset.key, value: f.value.value })).secrets); }
        catch (err) { say2(err.message, 'err'); }
      });
      $('[data-remove]', f)?.addEventListener('click', async () => {
        if (!confirm('להסיר את המפתח? הניסוח בבינה יפסיק לעבוד.')) return;
        try { drawSecrets((await api('secret-remove', { key: f.dataset.key })).secrets); }
        catch (err) { say2(err.message, 'err'); }
      });
    });
  };
  api('secrets').then(({ secrets }) => drawSecrets(secrets)).catch((err) => { $('#secrets').innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; });
  $('#pub').addEventListener('submit', async (e) => {
    e.preventDefault();
    const out = $('#pub-msg');
    const values = {};
    for (const key of Object.keys(settings)) values[key] = fromMB(e.target[key].value);
    try {
      await api('settings-public-save', { values });
      out.textContent = 'נשמר — חל מעכשיו על כל העלאה'; out.className = 'note note--ok'; out.hidden = false;
    } catch (err) { out.textContent = err.message; out.className = 'note note--err'; out.hidden = false; }
  });
}

/** ניהול משתמשים — המפתח בלבד. */
async function renderUsers() {
  const { users } = await api('users');
  const draw = (list) => {
    view.innerHTML = `
      <section class="card settings settings--wide">
        ${settingsNav()}
        <h2>ניהול משתמשים <span class="muted">(${list.length})</span></h2>
        <p class="muted">שדה ריק = ברירת המחדל הציבורית. ערך = מגבלה אישית למשתמש הזה.</p>
        <div class="users">
          ${list.map((u) => `
            <article class="user ${u.blocked ? 'is-blocked' : ''}">
              <header class="user__head">
                <div>
                  <strong>${esc(u.display_name)}</strong>
                  <span class="muted">@${esc(u.username)} · ${esc(u.email)}</span>
                </div>
                <div class="user__badges">
                  ${u.is_developer ? '<span class="badge badge--mine">מפתח</span>' : ''}
                  ${u.role === 'admin' && !u.is_developer ? '<span class="badge">מנהל</span>' : ''}
                  ${u.email_verified ? '' : '<span class="badge badge--warn">לא מאומת</span>'}
                  ${u.blocked ? '<span class="badge badge--err">חסום</span>' : ''}
                </div>
              </header>
              <div class="user__stats muted">
                ${u.recipes} מתכונים · ${toMB(u.used)} מתוך ${toMB(u.effective_quota)} MB
                ${u.last_mail_ok === null ? ' · דוא"ל: לא נשלח'
                  : u.last_mail_ok ? ` · דוא"ל: נשלח ✅ ${esc((u.last_mail_at || '').slice(0, 16).replace('T', ' '))}`
                  : ' · דוא"ל: <span class="link--danger">השרת דחה ❌</span>'}
              </div>
              <form class="user__limits" data-uid="${u.id}">
                <label>סרטון (MB)
                  <input name="video_max_bytes" type="number" step="0.5" inputmode="decimal"
                         placeholder="${toMB(u.effective_video)}" value="${u.limit_video != null ? toMB(u.limit_video) : ''}">
                </label>
                <label>אחסון (MB)
                  <input name="quota_bytes" type="number" step="0.5" inputmode="decimal"
                         placeholder="${toMB(u.effective_quota)}" value="${u.limit_quota != null ? toMB(u.limit_quota) : ''}">
                </label>
                <button class="btn" type="submit">שמור</button>
              </form>
              <div class="user__actions">
                ${!u.email_verified ? `<button class="link" type="button" data-resend="${u.id}">שלח אימות שוב</button>
                                       <button class="link" type="button" data-verify="${u.id}">אמת ידנית</button>` : ''}
                ${!u.is_developer ? `<button class="link ${u.blocked ? '' : 'link--danger'}" type="button" data-block="${u.id}" data-to="${u.blocked ? 0 : 1}">${u.blocked ? 'בטל חסימה' : 'חסום'}</button>` : ''}
              </div>
            </article>`).join('')}
        </div>
        <p id="users-msg" class="note" hidden></p>
      </section>`;

    const note = (t, k) => { const el = $('#users-msg'); el.textContent = t; el.className = 'note' + (k ? ' note--' + k : ''); el.hidden = !t; };
    $$('.user__limits').forEach((f) => f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const uid = +f.dataset.uid;
      try {
        let latest;
        for (const key of ['video_max_bytes', 'quota_bytes']) {
          const v = f[key].value.trim();
          ({ users: latest } = await api('user-limit', { user_id: uid, key, value: v === '' ? null : fromMB(v) }));
        }
        note('נשמר', 'ok'); draw(latest);
      } catch (err) { note(err.message, 'err'); }
    }));
    $$('[data-block]').forEach((b) => b.addEventListener('click', async () => {
      try { const { users: latest } = await api('user-block', { user_id: +b.dataset.block, blocked: b.dataset.to === '1' }); draw(latest); }
      catch (err) { note(err.message, 'err'); }
    }));
    $$('[data-resend]').forEach((b) => b.addEventListener('click', async () => {
      try {
        const { mail_sent, users: latest } = await api('user-resend', { user_id: +b.dataset.resend });
        note(mail_sent ? 'נשלח — השרת קיבל את ההודעה' : 'השרת דחה את השליחה — ראה באזור הפיתוח', mail_sent ? 'ok' : 'err');
        draw(latest);
      } catch (err) { note(err.message, 'err'); }
    }));
    $$('[data-verify]').forEach((b) => b.addEventListener('click', async () => {
      try { const { users: latest } = await api('user-verify', { user_id: +b.dataset.verify }); draw(latest); }
      catch (err) { note(err.message, 'err'); }
    }));
  };
  draw(users);
}

// ───────────────────────── אבחון ─────────────────────────

// ───────────────────────── יומן (למפתח) ─────────────────────────

const TTL_PRESETS = [[30, 'חצי שעה'], [60, 'שעה'], [1440, 'יום'], [10080, 'שבוע'], [0, 'מותאם…']];
const fmtWhen = (iso) => (iso ? esc(iso.slice(0, 16).replace('T', ' ')) : '—');
const fmtLeft = (iso) => {
  const ms = new Date(iso).getTime() - Date.now();
  if (ms <= 0) return 'פג';
  const m = Math.round(ms / 60000);
  if (m < 60) return `${m} דק׳`;
  if (m < 60 * 48) return `${Math.round(m / 60)} שעות`;
  return `${Math.round(m / 1440)} ימים`;
};

async function renderLogs() {
  if (!state.user.is_developer) { go('#/settings'); return; }
  const filters = { level: '', action: '', user: '', q: '' };
  let rows = [];
  let stats = null;
  let autoTimer = null;

  view.innerHTML = `
    <section class="card settings settings--wide logs">
      ${settingsNav()}
      <h2>יומן — כל צעד במערכת</h2>
      ${logsNav('system')}
      <p class="muted" id="log-stats">טוען…</p>

      <form class="logfilter" id="log-filter">
        <select name="level" aria-label="רמה">
          <option value="">כל הרמות</option>
          <option value="warn">בעיות (warn + error)</option>
          <option value="error">שגיאות בלבד</option>
        </select>
        <select name="action" aria-label="פעולה"><option value="">כל הפעולות</option></select>
        <select name="user" aria-label="משתמש"><option value="">כל המשתמשים</option></select>
        <input type="search" name="q" placeholder="חיפוש בהודעה / בפרטים" autocomplete="off">
        <div class="actions">
          <button class="btn btn--primary" type="submit">סנן</button>
          <label class="check"><input type="checkbox" id="log-auto"> רענון כל 10 שניות</label>
        </div>
      </form>

      <div class="logtable-wrap"><table class="logtable" id="log-table">
        <thead><tr><th>זמן</th><th>רמה</th><th>מי</th><th>פעולה</th><th>הודעה / פרטים</th><th>ms</th></tr></thead>
        <tbody></tbody>
      </table></div>
      <div class="actions"><button class="btn" type="button" id="log-more" hidden>שורות ישנות יותר ›</button></div>

      <h3>טוקן צפייה — להעביר למי שמפתח</h3>
      <p class="muted">קישור שמציג את היומן בלי כניסה, קריאה בלבד, עד שהתוקף פג או שביטלת. הטוקן מוצג פעם אחת.</p>
      <form class="form tokenform" id="token-form">
        <label>שם (למי / למה) <input name="label" maxlength="60" placeholder="לדוגמה: קלוד, באג בהעלאה" required></label>
        <label>תוקף
          <select name="preset">${TTL_PRESETS.map(([m, l]) => `<option value="${m}">${l}</option>`).join('')}</select>
        </label>
        <div class="tokenform__custom" id="ttl-custom" hidden>
          <label>כמה <input name="amount" type="number" min="1" max="999" value="2" inputmode="numeric"></label>
          <label>יחידה
            <select name="unit">
              <option value="60">שעות</option>
              <option value="1440">ימים</option>
              <option value="1">דקות</option>
            </select>
          </label>
        </div>
        <button class="btn btn--primary" type="submit">צור טוקן</button>
        <p class="note" id="token-msg" hidden></p>
      </form>
      <div id="token-new" hidden></div>
      <div id="token-list"></div>
    </section>`;

  const statsEl = $('#log-stats');
  const tbody = $('#log-table tbody');
  const more = $('#log-more');

  const rowHtml = (r) => `
    <tr class="log--${esc(r.level)}">
      <td class="mono" dir="ltr">${esc(r.at.slice(5, 19).replace('T', ' '))}</td>
      <td><span class="badge badge--${r.level === 'error' ? 'err' : r.level === 'warn' ? 'warn' : 'public'}">${esc(r.level)}</span></td>
      <td>${esc(r.username || '—')}</td>
      <td class="mono" dir="ltr"><button class="link" type="button" data-req="${esc(r.request_id || '')}" title="כל השורות של הבקשה">${esc(r.action)}</button></td>
      <td>${esc(r.message)}${r.meta ? ` <code class="mono small" dir="ltr">${esc(JSON.stringify(r.meta))}</code>` : ''}</td>
      <td class="mono" dir="ltr">${r.duration_ms ?? ''}</td>
    </tr>`;

  const fillSelect = (name, values) => {
    const sel = $(`#log-filter select[name="${name}"]`);
    const cur = sel.value;
    sel.innerHTML = sel.options[0].outerHTML + values.map((v) => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
    sel.value = cur;
  };

  const load = async (append = false) => {
    const req = { ...filters, limit: 100 };
    if (append && rows.length) req.before = rows[rows.length - 1].id;
    const res = await api('log', req);
    rows = append ? rows.concat(res.rows) : res.rows;
    stats = res.stats;
    statsEl.textContent = `${stats.rows} שורות מאז ${stats.oldest ? stats.oldest.slice(0, 10) : '—'} · ב-24 השעות האחרונות: ${stats.problems_24h} אזהרות, ${stats.errors_24h} שגיאות · נשמר ${stats.keep_days} יום / עד ${stats.keep_rows} שורות`;
    fillSelect('action', stats.actions);
    fillSelect('user', stats.users);
    tbody.innerHTML = rows.length ? rows.map(rowHtml).join('') : '<tr><td colspan="6" class="muted">אין שורות שתואמות.</td></tr>';
    more.hidden = res.rows.length < 100;
    $$('[data-req]', tbody).forEach((b) => b.addEventListener('click', () => {
      filters.request_id = b.dataset.req; $('#log-filter input[name="q"]').value = `בקשה ${b.dataset.req}`;
      load().catch((e) => { statsEl.textContent = e.message; });
    }));
  };

  $('#log-filter').addEventListener('submit', (e) => {
    e.preventDefault();
    const f = e.target;
    Object.assign(filters, { level: f.level.value, action: f.action.value, user: f.user.value, q: f.q.value.trim() });
    delete filters.request_id;
    if (/^בקשה /.test(filters.q)) { filters.request_id = filters.q.slice(5).trim(); filters.q = ''; }
    load().catch((err) => { statsEl.textContent = err.message; });
  });
  more.addEventListener('click', () => load(true).catch((err) => { statsEl.textContent = err.message; }));
  $('#log-auto').addEventListener('change', (e) => {
    clearInterval(autoTimer);
    if (e.target.checked) autoTimer = setInterval(() => { if (location.hash !== '#/logs') { clearInterval(autoTimer); return; } load().catch(() => {}); }, 10000);
  });

  // ── טוקנים ──
  const tokenList = $('#token-list');
  const drawTokens = (tokens) => {
    tokenList.innerHTML = tokens.length ? `
      <table class="kv tokens">
        <thead><tr><th>שם</th><th>תוקף</th><th>שימושים</th><th></th></tr></thead>
        <tbody>${tokens.map((t) => `
          <tr class="${t.active ? '' : 'is-dead'}">
            <td>${esc(t.label)}<br><span class="muted small">נוצר ${fmtWhen(t.created_at)}</span></td>
            <td>${t.revoked_at ? 'בוטל' : t.active ? `עוד ${fmtLeft(t.expires_at)}` : 'פג'}<br><span class="muted small" dir="ltr">${fmtWhen(t.expires_at)}</span></td>
            <td>${t.uses}${t.last_used_at ? `<br><span class="muted small">אחרון ${fmtWhen(t.last_used_at)}</span>` : ''}</td>
            <td>${t.active ? `<button class="link link--danger" type="button" data-revoke="${t.id}">בטל</button>` : ''}</td>
          </tr>`).join('')}</tbody>
      </table>` : '<p class="muted">אין טוקנים.</p>';
    $$('[data-revoke]', tokenList).forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('לבטל את הטוקן? הקישור יפסיק לעבוד מיד.')) return;
      try { drawTokens((await api('log-token-revoke', { id: +b.dataset.revoke })).tokens); }
      catch (err) { alert(err.message); }
    }));
  };

  $('#token-form select[name="preset"]').addEventListener('change', (e) => { $('#ttl-custom').hidden = e.target.value !== '0'; });
  $('#token-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const out = $('#token-msg');
    const ttl = +f.preset.value || Math.max(1, +f.amount.value) * +f.unit.value;
    try {
      const { token, tokens } = await api('log-token-create', { label: f.label.value, ttl_minutes: ttl });
      const url = new URL('./logs.php', location.href); url.searchParams.set('token', token.token);
      const box = $('#token-new');
      box.hidden = false;
      box.innerHTML = `
        <div class="note note--ok tokennew">
          <strong>הטוקן "${esc(token.label)}" נוצר — תקף עד ${fmtWhen(token.expires_at)} UTC.</strong>
          <p>הקישור מוצג פעם אחת. להעתיק ולהעביר:</p>
          <input class="mono" dir="ltr" readonly value="${esc(url.href)}" id="token-url">
          <div class="actions">
            <button class="btn btn--primary" type="button" id="token-copy">העתק קישור</button>
            <a class="btn" href="${esc(url.href)}" target="_blank" rel="noopener">פתח</a>
          </div>
          <p class="muted small">לקלוד: <code dir="ltr">&amp;format=text</code> — טקסט להדבקה בצ'אט; <code dir="ltr">&amp;view=import&amp;format=text</code> — יומן הייבוא, עם מה שהיה בכל דף ומה שחולץ.</p>
        </div>`;
      $('#token-copy').addEventListener('click', async () => {
        const inp = $('#token-url'); inp.select();
        try { await navigator.clipboard.writeText(inp.value); $('#token-copy').textContent = 'הועתק ✓'; }
        catch { document.execCommand('copy'); $('#token-copy').textContent = 'הועתק ✓'; }
      });
      out.hidden = true;
      f.label.value = '';
      drawTokens(tokens);
    } catch (err) { out.textContent = err.message; out.className = 'note note--err'; out.hidden = false; }
  });

  await load();
  drawTokens((await api('log-tokens')).tokens);
}

const logsNav = (active) => `
  <nav class="subnav subnav--logs" aria-label="יומנים">
    <a href="#/logs" class="${active === 'system' ? 'is-on' : ''}">יומן המערכת</a>
    <a href="#/logs/import" class="${active === 'import' ? 'is-on' : ''}">יומן ייבוא</a>
  </nav>`;

const IMPORT_KIND_HE = { preview: 'ייבוא מהעורך', 'scout-preview': 'תצוגה מקדימה בסורק', 'scout-import': 'ייבוא בסורק', refresh: 'משיכה חוזרת', 'editor-save': 'שמירה מהעורך', 'web-open': 'פתיחה מהחיפוש', 'web-save': 'שמירה מהחיפוש' };

// ───────────────────────── יומן ייבוא (מפתח) ─────────────────────────
// כל הבאה של דף: מה היה בדף ומה חולץ ממנו, עם קישור למקור. כאן רואים, ובטקסט
// מעתיקים — זה מה שמדביקים בצ'אט כדי לאבחן למה דף מסוים לא חולץ נכון.
async function renderImportLog() {
  if (!state.user.is_developer) { go('#/settings'); return; }
  const filters = { host: '', kind: '', ok: '', q: '' };
  let rows = [];

  view.innerHTML = `
    <section class="card settings settings--wide logs importlog">
      ${settingsNav()}
      <h2>יומן ייבוא</h2>
      ${logsNav('import')}
      <p class="muted">כל הבאה של דף מהרשת — ייבוא מהעורך, תצוגה מקדימה וייבוא בסורק — עם מה שהיה בדף (JSON-LD, כותרות, Microdata)
        ומה שחולץ ממנו כלשונו, וקישור למקור להשוואה. "העתק כטקסט" נותן את הכול להדבקה בצ'אט.</p>
      <p class="muted" id="ilog-stats">טוען…</p>
      <form class="logfilter" id="ilog-filter">
        <select name="host" aria-label="אתר"><option value="">כל האתרים</option></select>
        <select name="kind" aria-label="סוג"><option value="">כל הסוגים</option>${Object.entries(IMPORT_KIND_HE).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>
        <select name="ok" aria-label="תוצאה"><option value="">הצליח ונכשל</option><option value="0">נכשל בלבד</option><option value="1">הצליח בלבד</option></select>
        <input type="search" name="q" placeholder="חיפוש בשם / בכתובת / בשגיאה" autocomplete="off">
        <div class="actions">
          <button class="btn btn--primary" type="submit">סנן</button>
          <button class="btn" type="button" id="ilog-copy">העתק כטקסט</button>
        </div>
      </form>
      <div id="ilog-list" class="importlog__list"></div>
      <div class="actions"><button class="btn" type="button" id="ilog-more" hidden>ישנים יותר ›</button></div>
    </section>`;

  const statsEl = $('#ilog-stats');
  const listEl = $('#ilog-list');
  const more = $('#ilog-more');
  const entryHtml = (r) => `
    <article class="ilog ilog--${r.ok ? 'ok' : 'err'}">
      <header class="ilog__head">
        <span class="badge badge--${r.ok ? 'public' : 'err'}">${r.ok ? 'הצליח' : 'נכשל'}</span>
        <span>${esc(IMPORT_KIND_HE[r.kind] || r.kind)}</span>
        <span class="muted small" dir="ltr">${esc(r.at.slice(5, 16).replace('T', ' '))}</span>
        <span class="muted small">${esc(r.username || '—')}${r.duration_ms != null ? ` · ${r.duration_ms}ms` : ''}</span>
      </header>
      <strong>${esc(r.title || (r.ok ? '(בלי שם)' : 'ההבאה נכשלה — ' + r.host))}</strong>
      ${r.ok && !['editor-save', 'web-save'].includes(r.kind) ? `<p class="muted small">חולץ ב-${esc(r.method || '?')} · ${r.ingredients_n} רכיבים · ${r.steps_n} שלבים ב-${r.sections_n} חלקים · ${r.images_n} תמונות${r.videos_n ? ` · ${r.videos_n} סרטונים` : ''}</p>` : ''}
      ${['editor-save', 'web-save'].includes(r.kind) ? `<p class="muted small">נשמר: ${r.ingredients_n} רכיבים · ${r.steps_n} שלבים ב-${r.sections_n} חלקים</p>` : ''}
      ${!r.ok ? `<p class="note note--err small">${esc(r.error || '')}</p>` : ''}
      ${r.warnings?.length ? `<p class="note note--warn small">${r.warnings.map(esc).join(' · ')}</p>` : ''}
      <p class="ilog__links">
        <a href="${esc(r.source_url)}" target="_blank" rel="noopener nofollow">↗ המקור</a>
        <span class="muted small scout-item__url" dir="auto">${esc(prettyUrl(r.source_url))}</span>
        ${r.recipe_id ? `· <a href="#/r/${r.recipe_id}">למתכון #${r.recipe_id} ›</a>` : ''}
        ${r.scout_id ? `· <span class="muted small">מועמד בסורק #${r.scout_id}</span>` : ''}
      </p>
      <details><summary class="link">הטקסט המלא — מה היה בדף ומה חולץ</summary><pre class="ilog__text" dir="auto">${esc(r.text)}</pre></details>
    </article>`;

  const load = async (append = false) => {
    const req = { ...filters, limit: 50 };
    if (append && rows.length) req.before = rows[rows.length - 1].id;
    const res = await api('import-log', req);
    rows = append ? rows.concat(res.rows) : res.rows;
    const st = res.stats;
    statsEl.textContent = `${st.rows} הבאות · ${st.failed_24h} נכשלו ב-24 השעות האחרונות · נשמר ${st.keep_days} יום / עד ${st.keep_rows} שורות`;
    const sel = $('#ilog-filter select[name="host"]'); const cur = sel.value;
    sel.innerHTML = '<option value="">כל האתרים</option>' + st.hosts.map((h) => `<option value="${esc(h)}">${esc(h)}</option>`).join('');
    sel.value = cur;
    listEl.innerHTML = rows.length ? rows.map(entryHtml).join('') : '<p class="muted">עדיין אין הבאות שתואמות. כל ייבוא מהעורך או מהסורק יופיע כאן.</p>';
    more.hidden = res.rows.length < 50;
  };
  $('#ilog-filter').addEventListener('submit', (e) => {
    e.preventDefault();
    const f = e.target;
    Object.assign(filters, { host: f.host.value, kind: f.kind.value, ok: f.ok.value, q: f.q.value.trim() });
    load().catch((err) => { statsEl.textContent = err.message; });
  });
  more.addEventListener('click', () => load(true).catch((err) => { statsEl.textContent = err.message; }));
  $('#ilog-copy').addEventListener('click', async () => {
    const text = rows.slice().reverse().map((r) => r.text).join('\n');
    const b = $('#ilog-copy');
    try { await navigator.clipboard.writeText(text); b.textContent = `הועתק ✓ (${rows.length})`; }
    catch { const ta = document.createElement('textarea'); ta.value = text; document.body.append(ta); ta.select(); document.execCommand('copy'); ta.remove(); b.textContent = 'הועתק ✓'; }
    setTimeout(() => { b.textContent = 'העתק כטקסט'; }, 2500);
  });
  await load().catch((err) => { statsEl.textContent = err.message; });
}

// ───────────────────────── חיפוש מתכונים ברשת ─────────────────────────
// באינדקס משלנו (lib/web_index.php): האתרים שהמפתח בחר. התוצאות — שם, אתר
// וקישור, כמו מנוע חיפוש. פתיחה מביאה, מחלצת ומנסחת מחדש; שמירה — אצלך, פרטי.

const webState = { q: '', topic: '', data: null, offset: 0 };

async function renderWebSearch(q = '', topic = '') {
  view.innerHTML = `
    <section class="card settings settings--wide websearch">
      <a class="link" href="#/">‹ לרשימה</a>
      <h2>🔎 חיפוש מתכונים ברשת</h2>
      <form id="web-form" class="search" role="search">
        <input name="q" type="search" placeholder="למשל: עוגת גבינה אפויה" autocomplete="off" value="${esc(q || webState.q)}">
        <button class="btn btn--primary search__go" type="submit" aria-label="חפש" title="חפש">🔍</button>
      </form>
      <nav class="topics" id="web-topics" aria-label="נושא"></nav>
      <p class="muted small" id="web-stats"></p>
      <div id="web-results" class="scout__list"></div>
      <div class="actions"><button class="btn" type="button" id="web-more" hidden>עוד תוצאות ›</button></div>
      <p class="muted small">פתיחת מתכון מביאה אותו מהאתר, מסדרת אותו במבנה שלנו ומנסחת את אופן ההכנה מחדש בבינה.
        שמירה — אצלך, פרטי, עם קרדיט וקישור למקור. <a href="#/import">יש לך קישור? ייבוא מקישור ›</a></p>
    </section>`;
  const resEl = $('#web-results');
  const stats = $('#web-stats');
  const draw = () => {
    const d = webState.data;
    const local = d.local || [];
    stats.textContent = d.total || local.length ? `${local.length ? `${local.length} מהאפליקציה · ` : ''}${d.total} ברשת · מתוך ${d.indexed.toLocaleString('he-IL')} מתכונים ב-${d.sites} אתרים`
      : d.indexed ? `לא נמצא. מחפשים בשם המתכון — כדאי לנסות מילה אחת או שתיים. (${d.indexed.toLocaleString('he-IL')} מתכונים ב-${d.sites} אתרים)`
      : 'האינדקס עדיין ריק — המפתח מוסיף אתרים ב"אינדקס".';
    // קודם מה שכבר באפליקציה, אחר כך מהרשת (השרת מסדר: הורדות, פתיחות, התאמה)
    resEl.innerHTML = local.map((r) => `
      <article class="scout-item web-item web-item--local">
        <a class="web-item__thumb" href="#/r/${r.id}" tabindex="-1" aria-hidden="true">${r.thumb
          ? `<img src="${esc(r.thumb)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">` : ''}</a>
        <div class="scout-item__main">
          <a class="web-item__title" href="#/r/${r.id}"><strong>${esc(r.title)}</strong></a>
          <span class="muted small"><span class="web-item__here">📗 באפליקציה</span> · ${esc(r.owner_name)}${r.is_mine ? ' · שלי' : ''}</span>
        </div>
        <div class="scout-item__actions"><a class="btn btn--primary btn--small" href="#/r/${r.id}">פתח</a></div>
      </article>`).join('') + d.results.map((r) => `
      <article class="scout-item web-item">
        <a class="web-item__thumb" href="#/web/${r.id}" tabindex="-1" aria-hidden="true">${r.image
          ? `<img src="${esc(r.image)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">` : ''}</a>
        <div class="scout-item__main">
          <a class="web-item__title" href="#/web/${r.id}"><strong>${esc(r.title)}</strong></a>
          <span class="muted small">${esc(r.site)}${r.saves ? ` · <span class="web-item__saves">⬇ ${r.saves === 1 ? 'משתמש אחד שמר' : `${r.saves} משתמשים שמרו`}</span>` : ''}</span>
          <a class="muted small scout-item__url" dir="auto" href="${esc(r.url)}" target="_blank" rel="noopener nofollow">${esc(prettyUrl(r.url))}</a>
        </div>
        <div class="scout-item__actions"><a class="btn btn--primary btn--small" href="#/web/${r.id}">פתח</a>
          <button class="btn btn--small" type="button" data-coll-entry="${r.id}" aria-label="לאוסף — בלי לפתוח" title="לאוסף — בלי לפתוח">📁</button></div>
        <div class="share-box web-item__coll" data-coll-box="${r.id}" hidden></div>
      </article>`).join('');
    $('#web-more').hidden = d.results.length >= d.total;
  };
  const search = async (append = false) => {
    webState.offset = append ? webState.data.results.length : 0;
    try {
      const d = await api('web-search', { q: webState.q, topic: webState.topic, offset: webState.offset });
      webState.data = append ? { ...d, local: webState.data.local, results: webState.data.results.concat(d.results) } : d;
      draw();
    } catch (err) { stats.textContent = err.message; }
  };
  const webHash = () => '#/web?' + new URLSearchParams({ q: webState.q, ...(webState.topic ? { topic: webState.topic } : {}) });
  $('#web-form').addEventListener('submit', (e) => {
    e.preventDefault();
    webState.q = e.target.q.value.trim();
    if (!webState.q && !webState.topic) return;
    history.replaceState(null, '', webHash());
    search();
  });
  // נושא — מוסק משם הערך (לאינדקס אין יותר). בלי כשרות: אותה אי אפשר לדעת מהשם
  const drawTopics = (icons = {}) => {
    $('#web-topics').innerHTML = (state.tags?.topic || []).map((t) => `<button type="button" class="topic topic--small ${webState.topic === t.name ? 'is-on' : ''}" data-topic="${esc(t.name)}">
      <span class="topic__icon" aria-hidden="true">${topicIcon(t.name, icons)}</span><span>${esc(t.name)}</span></button>`).join('');
  };
  let topicIcons = {};
  Promise.all([ensureTags(), api('tag-counts')]).then(([, c]) => { topicIcons = c.icons; drawTopics(topicIcons); }).catch(() => {});
  $('#web-topics').addEventListener('click', (e) => {
    const b = e.target.closest('[data-topic]');
    if (!b) return;
    webState.topic = webState.topic === b.dataset.topic ? '' : b.dataset.topic;
    webState.q = $('#web-form').q.value.trim();
    drawTopics(topicIcons);
    history.replaceState(null, '', webHash());
    if (webState.q || webState.topic) search(); else { webState.data = null; resEl.innerHTML = ''; stats.textContent = ''; $('#web-more').hidden = true; }
  });
  $('#web-more').addEventListener('click', () => search(true));
  // 📁 בתוצאה: שמירה לאוסף כקישור — בלי לפתוח (פתיחה נספרת במכסה היומית)
  resEl.addEventListener('click', (e) => {
    const b = e.target.closest('[data-coll-entry]');
    if (!b) return;
    const box = $(`[data-coll-box="${b.dataset.collEntry}"]`, resEl);
    box.hidden = !box.hidden;
    if (!box.hidden) collectionPicker(box, { entry_id: +b.dataset.collEntry });
  });
  // תמונה שלא נטענה (נמחקה באתר, חסימת קישור חיצוני) — חוזרים לסמל, בלי אייקון שבור
  resEl.addEventListener('error', (e) => { if (e.target.tagName === 'IMG') e.target.remove(); }, true);
  // כתובת עם חיפוש — היא הקובעת (קישור, "חזור"); בלי — ממשיכים מהחיפוש האחרון
  if ((q || topic) && (q !== webState.q || topic !== webState.topic)) { webState.q = q; webState.topic = topic; webState.data = null; }
  if ((webState.q || webState.topic) && webState.data) draw();     // חזרה מפתיחה — בלי לחפש שוב
  else if (webState.q || webState.topic) search();
  else $('#web-form input').focus();
}

async function renderWebRecipe(id) {
  view.innerHTML = `<section class="card"><p class="muted">מביא את המתכון מהאתר, מסדר ומנסח מחדש… (עד חצי דקה בפעם הראשונה)</p></section>`;
  let res;
  try { res = await api('web-open', { id }); }
  catch (err) {
    const hit = webState.data?.results.find((r) => r.id === id);
    const back = `#/web${webState.q ? '?q=' + encodeURIComponent(webState.q) : ''}`;
    // 422 — הדף אינו מתכון, והשרת כבר הוציא אותו מהחיפוש: גם מהתוצאות שכאן
    if (err.status === 422 && webState.data) {
      webState.data.results = webState.data.results.filter((r) => r.id !== id);
      webState.data.total = Math.max(0, webState.data.total - 1);
    }
    view.innerHTML = `<section class="card settings">
      <a class="link link--back" href="${back}">‹ לתוצאות</a>
      <p class="${err.status === 422 ? 'muted' : 'note note--err'}">${esc(err.message)}</p>
      <div class="actions">${err.status === 422 ? `<a class="btn btn--primary" href="${back}">חזרה לתוצאות</a>`
        : hit ? `<a class="btn" href="${esc(hit.url)}" target="_blank" rel="noopener nofollow">↗ לפתוח באתר המקור</a>` : ''}</div>
    </section>`;
    return;
  }
  const d = res.draft;
  const img = d.pending_media?.images?.[0];
  view.innerHTML = `
    <article class="recipe webrecipe">
      <header class="recipe__head">
        <a class="link link--back" href="#/web${webState.q ? '?q=' + encodeURIComponent(webState.q) : ''}">‹ לתוצאות</a>
        <h2>${esc(d.title)}</h2>
        <p class="credit">
          🌐 <strong>${res.rewritten ? 'מבוסס על המתכון של' : 'מקור:'}</strong>
          <a href="${esc(d.source_url)}" target="_blank" rel="noopener nofollow">${esc(d.source_name || res.entry.site)}</a>${d.source_author ? ` · מאת ${esc(d.source_author)}` : ''}
          <span class="muted small">— ${res.rewritten ? 'אופן ההכנה נוסח מחדש; ' : ''}המתכון המקורי, התמונות והפרטים המלאים — באתר המקור.</span>
        </p>
        ${!res.rewritten && d.rewrite_note ? `<p class="muted small">ℹ️ אופן ההכנה מוצג כמו במקור — ${
          state.user.is_developer ? esc(d.rewrite_note) : 'הניסוח מחדש לא זמין כרגע'}.</p>` : ''}
        <div class="meta">
          ${d.servings ? `<span>🍽 ${d.servings} מנות</span>` : d.yield_text ? `<span>🍽 ${esc(d.yield_text)}</span>` : ''}
          ${d.work_minutes ? `<span title="זמן עבודה">⏱ ${minutes(d.work_minutes)}</span>` : ''}
          ${d.wait_minutes ? `<span title="זמן המתנה">⏳ ${minutes(d.wait_minutes)}</span>` : ''}
        </div>
        <button class="btn btn--primary btn--wide" type="button" id="web-save">💾 שמור אצלי</button>
        <nav class="actbar" aria-label="פעולות">
          <button class="actbar__btn" type="button" id="web-edit"><span aria-hidden="true">✏️</span>עריכה</button>
          <button class="actbar__btn" type="button" id="web-coll"><span aria-hidden="true">📁</span>לאוסף</button>
          <button class="actbar__btn" type="button" id="web-reco"><span aria-hidden="true">📨</span>המלצה</button>
          <a class="actbar__btn" href="${esc(d.source_url)}" target="_blank" rel="noopener nofollow"><span aria-hidden="true">↗</span>במקור</a>
        </nav>
        <div id="reco-box" class="share-box" hidden></div>
        <div id="coll-box" class="share-box" hidden></div>
        <p class="note" id="web-msg" hidden></p>
        ${res.opens_left != null ? `<p class="muted small">נשארו לך היום ${res.opens_left} פתיחות חדשות. מתכונים שכבר נפתחו — בלי הגבלה.</p>` : ''}
      </header>
      ${img ? `<figure class="webrecipe__img"><img src="${esc(img)}" alt="" loading="lazy" referrerpolicy="no-referrer"><figcaption class="muted small">תמונה: ${esc(d.source_name || res.entry.site)}</figcaption></figure>` : ''}
      ${(d.pending_media?.videos || []).length ? `<section class="gallery">${d.pending_media.videos.map((u) => videoHtml(u)).join('')}
        <p class="muted small">סרטון: ${esc(d.source_name || res.entry.site)}</p></section>` : ''}
      ${d.sections.map((s) => `
        <section class="part">
          ${s.name ? `<h3>${esc(s.name)}</h3>` : ''}
          ${s.ingredients.length ? `<h4>רכיבים</h4><ul class="ings">${s.ingredients.map((i) => `<li class="${i.optional ? 'is-optional' : ''}"><span class="ing__free">${esc(i.free_text)}</span></li>`).join('')}</ul>` : ''}
          ${s.steps.length ? `<h4>אופן ההכנה</h4><ol class="steps">${s.steps.map((st) => `<li>${esc(st.text)}</li>`).join('')}</ol>` : '<p class="muted">לא נמצאו שלבי הכנה — הם באתר המקור.</p>'}
        </section>`).join('')}
      ${d.tips ? `<section class="part"><h3>על המתכון</h3><p class="tips">${esc(d.tips)}</p></section>` : ''}
    </article>`;
  const msg = $('#web-msg');
  $('#web-reco').addEventListener('click', () => {
    const box = $('#reco-box');
    box.hidden = !box.hidden;
    if (!box.hidden) renderRecoBox(box, { entry_id: res.entry.id }, d.title);
  });
  $('#web-coll').addEventListener('click', () => {
    const box = $('#coll-box');
    box.hidden = !box.hidden;
    if (!box.hidden) collectionPicker(box, { entry_id: res.entry.id });
  });
  $('#web-save').addEventListener('click', async (e) => {
    e.target.disabled = true;
    try {
      const r = await api('web-save', { id });
      go(`#/r/${r.recipe_id}`);
    } catch (err) { msg.textContent = err.message; msg.className = 'note note--err'; msg.hidden = false; e.target.disabled = false; }
  });
  $('#web-edit').addEventListener('click', async () => {
    d.ai_available = res.ai_available;
    await renderEditor(null, structuredClone(d));   // השמירה בעורך — פרטית, עם המקור והקרדיט
    window.scrollTo(0, 0);
  });
}

// ───────────────────────── ניסוח ידני (מפתח) ─────────────────────────
// כשאין קרדיט לבינה: מורידים את התור, מנסחים בחוץ (בצ'אט), ומעלים את הקובץ
// המנוסח. השרת בודק כל פריט לפני שהוא מחליף משהו (lib/rewrite_queue.php).

async function renderRewrite() {
  if (!state.user.is_developer) { go('#/settings'); return; }
  view.innerHTML = `
    <section class="card settings settings--wide rewrite-page">
      ${settingsNav()}
      <h2>✍️ ניסוח ידני</h2>
      <p class="muted">כשהבינה לא זמינה (נגמר הקרדיט), מתכונים מהרשת נשמרים בלי ניסוח מחדש. כאן מורידים אותם לקובץ, מנסחים בחוץ —
        למשל שולחים לקלוד — ומעלים את הקובץ המנוסח. כל פריט נבדק: אותו מבנה, ולא קרוב מדי למקור.</p>
      <p id="rw-count" class="muted">טוען…</p>
      <div class="actions actions--wrap">
        <button class="btn btn--primary" type="button" id="rw-download">⬇️ הורד קובץ לניסוח</button>
      </div>
      <p class="muted small">לקלוד אפשר גם בלי קובץ: הטוקן של היומן עם <code dir="ltr">&amp;view=rewrite</code>.</p>
      <h3>העלאת קובץ מנוסח</h3>
      <form id="rw-form" class="form">
        <label>קובץ <input type="file" name="file" accept=".json,application/json,text/plain"></label>
        <label>או הדבקה <textarea name="text" rows="5" dir="ltr" placeholder='{"format":"recipes-rewrite-1","items":[…]}'></textarea></label>
        <button class="btn btn--primary" type="submit">החל את הניסוח</button>
        <p class="note" id="rw-msg" hidden></p>
      </form>
      <ul class="site-results" id="rw-results" hidden></ul>
    </section>`;
  const msg = $('#rw-msg');
  const note = (t, k) => { msg.textContent = t; msg.className = 'note' + (k ? ' note--' + k : ''); msg.hidden = !t; };
  const count = async () => {
    try { const d = await api('rewrite-export', { limit: 1 }); $('#rw-count').textContent = d.total_waiting ? `${d.total_waiting} מתכונים ממתינים לניסוח.` : 'אין מתכונים שממתינים לניסוח. ✓'; }
    catch (err) { $('#rw-count').textContent = err.message; }
  };
  $('#rw-download').addEventListener('click', async () => {
    try {
      const d = await api('rewrite-export', { limit: 40 });
      delete d.success;
      if (!d.items.length) { note('אין מה לנסח.', 'ok'); return; }
      const blob = new Blob([JSON.stringify(d, null, 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob); a.download = `rewrite-${d.exported_at.slice(0, 10)}.json`;
      document.body.append(a); a.click(); a.remove();
      note(`הורדו ${d.items.length} מתכונים${d.total_waiting > d.items.length ? ` (מתוך ${d.total_waiting} — השאר בפעם הבאה)` : ''}.`, 'ok');
    } catch (err) { note(err.message, 'err'); }
  });
  $('#rw-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const text = f.file.files[0] ? await f.file.files[0].text() : f.text.value.trim();
    if (!text) { note('בחר קובץ או הדבק את התוכן.', 'warn'); return; }
    try {
      const d = await api('rewrite-apply', { text });
      const icon = { applied: '✅', too_close: '⚠️', mismatch: '❌', missing: '❌', empty: '❌' };
      const ul = $('#rw-results');
      ul.innerHTML = d.results.map((r) => `<li class="${r.status === 'applied' ? '' : 'is-err'}">${icon[r.status] || '•'} ${esc(r.title || r.id)} <span class="muted small">${esc(r.message)}</span></li>`).join('');
      ul.hidden = false;
      note(`נוסחו ${d.applied} מתוך ${d.results.length}. נשארו ${d.waiting} בתור.`, d.applied ? 'ok' : 'warn');
      f.reset();
    } catch (err) { note(err.message, 'err'); }
  });
  await count();
}

// ───────────────────────── רשת: שיתוף, אנשים, פרופיל ─────────────────────────

/** שורת אדם: שם (לפרופיל), ופעולה בצד. */
const personRow = (p, action = '') => `
  <div class="person-row" data-pid="${p.id}">
    <a class="person-row__name" href="#/u/${p.id}"><span class="avatar" aria-hidden="true">${esc((p.display_name || '?').trim().charAt(0))}</span>
      <span><strong>${esc(p.display_name)}</strong> <span class="muted small" dir="ltr">@${esc(p.username)}</span>${
        p.public_n != null ? ` <span class="muted small">· ${p.public_n} מתכונים</span>` : ''}</span></a>
    ${action}
  </div>`;

/** חיפוש אנשים עם הקלדה: onPick(person) כשבוחרים; actionFor(person) — הכפתור בשורה. */
function peoplePicker(box, { placeholder, actionFor, onAction }) {
  box.innerHTML = `<input type="search" class="people-q" placeholder="${esc(placeholder)}" autocomplete="off" aria-label="${esc(placeholder)}">
    <div class="people-results"></div>`;
  const inp = $('.people-q', box);
  const out = $('.people-results', box);
  let timer;
  const run = async () => {
    const q = inp.value.trim();
    if (q.length < 2) { out.innerHTML = q ? '<p class="muted small">עוד אות…</p>' : ''; return; }
    try {
      const { people } = await api('people-search', { q });
      out.innerHTML = people.length ? people.map((p) => personRow(p, actionFor(p))).join('') : '<p class="muted small">לא נמצא אף אחד בשם הזה.</p>';
    } catch (err) { out.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; }
  };
  inp.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 250); });
  out.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    b.disabled = true;
    try { await onAction(+b.closest('[data-pid]').dataset.pid, b.dataset.act); await run(); } catch (err) { alert(err.message); b.disabled = false; }
  });
  return { refresh: run, input: inp };
}

const REPORT_REASONS_UI = { spam: 'ספאם / פרסומת', offensive: 'פוגעני', copyright: 'הפרת זכויות יוצרים', unsafe: 'מסוכן / לא בטוח', other: 'אחר' };

/** דיווח על מתכון / תגובה / משתמש: סיבה, הערה, שליחה. מוצג בתוך $slot. */
function reportBox(slot, kind, id, what) {
  slot.innerHTML = `
    <form class="share-box report-box">
      <h3>⚑ דיווח על ${esc(what)}</h3>
      <div class="report-reasons">${Object.entries(REPORT_REASONS_UI).map(([k, v], i) =>
        `<label class="check"><input type="radio" name="reason" value="${k}" ${i === 0 ? 'checked' : ''}> ${v}</label>`).join('')}</div>
      <input name="note" maxlength="500" placeholder="פרטים (לא חובה)" autocomplete="off">
      <div class="actions"><button class="btn btn--primary btn--small" type="submit">שליחה</button>
        <button class="btn btn--ghost btn--small" type="button" data-cancel>ביטול</button></div>
      <p class="muted small">הדיווח מגיע למנהל האפליקציה. מי שדווח עליו לא יודע מי דיווח.</p>
    </form>`;
  const f = slot.querySelector('form');
  f.querySelector('[data-cancel]').addEventListener('click', () => { slot.innerHTML = ''; });
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await api('report', { kind, id, reason: f.reason.value, note: f.note.value }); slot.innerHTML = '<p class="note note--ok">תודה — הדיווח נשלח.</p>'; }
    catch (err) { slot.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; }
  });
}

/** לוח השיתוף של מתכון (לבעלים): עם מי משותף, והוספה. */
async function renderShareBox(box, r) {
  box.innerHTML = `
    <h3>👥 שיתוף עם אנשים</h3>
    <p class="muted small">${r.visibility === 'public'
      ? 'המתכון ציבורי — כולם רואים אותו. שיתוף מוסיף אותו אצלם ל"שותפו איתי".'
      : 'המתכון פרטי — רק מי שברשימה יראה אותו (ויוכל להסיר את עצמו). עריכה שלך נראית להם מיד.'}</p>
    <div id="share-circles"></div>
    <div id="share-list" class="people-list"></div>
    <div id="share-pick"></div>`;
  let current = [];
  const drawList = () => {
    $('#share-list', box).innerHTML = current.length
      ? current.map((p) => personRow(p, `<span class="muted small">${p.seen ? 'פתח/ה ✓' : 'עוד לא פתח/ה'}</span>
          <button class="btn btn--ghost btn--small" type="button" data-unshare="${p.id}" aria-label="הסרה">✕</button>`)).join('')
      : '<p class="muted small">עוד לא שותף עם אף אחד בשמו.</p>';
  };
  let sharedCircles = [];
  try { ({ shares: current, circles: sharedCircles } = await api('recipe-shares', { recipe_id: r.id })); } catch (err) { box.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; return; }
  drawList();
  circleShareRow($('#share-circles', box), 'recipe', r.id, sharedCircles);
  $('#share-list', box).addEventListener('click', async (e) => {
    const id = e.target.dataset.unshare;
    if (!id) return;
    try { current = (await api('recipe-unshare', { recipe_id: r.id, user_id: +id })).shares; drawList(); picker.refresh(); } catch (err) { alert(err.message); }
  });
  const picker = peoplePicker($('#share-pick', box), {
    placeholder: 'חיפוש לפי שם…',
    actionFor: (p) => current.some((c) => c.id === p.id) ? '<span class="muted small">משותף ✓</span>'
      : '<button class="btn btn--primary btn--small" type="button" data-act="share">שתף</button>',
    onAction: async (pid) => { current = (await api('recipe-share', { recipe_id: r.id, user_ids: [pid] })).shares; drawList(); },
  });
  picker.input.focus();
}

/** המלצה: למי לשלוח, ועם הערה קצרה. ההמלצה היא קישור למקור — לא העתק. */
function renderRecoBox(box, target, title) {
  box.innerHTML = `
    <h3>📨 המלצה על "${esc(title)}"</h3>
    <p class="muted small">המתכון יגיע אליהם כהמלצה לעיין בו, עם קישור למקור — לא כהעתק.</p>
    <input type="text" id="reco-note" maxlength="300" placeholder="הערה (לא חובה) — למשל: נראה טעים, ננסה בשבת?" autocomplete="off">
    <div id="reco-circles"></div>
    <div id="reco-pick"></div>
    <p class="note" id="reco-msg" hidden></p>`;
  const sent = new Set();
  const say = (t, k) => { const m = $('#reco-msg', box); m.textContent = t; m.className = 'note' + (k ? ' note--' + k : ''); m.hidden = !t; };
  // מעגל בהמלצה — שליחה לכל מי שבו עכשיו (מי שלא מקבל ממך — מדולג)
  api('circles').then(({ circles }) => {
    if (!circles.length) return;
    const row = $('#reco-circles', box);
    row.innerHTML = `<div class="circle-row"><span class="muted small">למעגל:</span>${circles.map((c) => `
      <button type="button" class="chip chip--circle" data-circle="${c.id}">${esc(c.icon)} ${esc(c.name)} <span class="muted small">(${c.n})</span></button>`).join('')}</div>`;
    row.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-circle]');
      if (!b || b.classList.contains('is-on')) return;
      b.disabled = true;
      try {
        const r = await api('recommend', { ...target, circle_ids: [+b.dataset.circle], note: $('#reco-note', box).value });
        b.classList.add('is-on'); b.insertAdjacentText('beforeend', ' ✓');
        say(r.sent ? `ההמלצה נשלחה ל-${r.sent} ✓${r.already ? ` (ל-${r.already} היא כבר ממתינה)` : ''}` : 'כבר ממתינה אצל כולם המלצה על המתכון הזה.', r.sent ? 'ok' : 'warn');
      } catch (err) { say(err.message, 'err'); }
      b.disabled = false;
    });
  }).catch(() => {});
  const picker = peoplePicker($('#reco-pick', box), {
    placeholder: 'למי? חיפוש לפי שם…',
    actionFor: (p) => sent.has(p.id) ? '<span class="muted small">נשלח ✓</span>' : '<button class="btn btn--primary btn--small" type="button" data-act="send">שלח</button>',
    onAction: async (pid) => {
      const r = await api('recommend', { ...target, user_ids: [pid], note: $('#reco-note', box).value });
      sent.add(pid);
      say(r.sent ? 'ההמלצה נשלחה ✓' : 'כבר ממתינה אצלו/ה המלצה על המתכון הזה.', r.sent ? 'ok' : 'warn');
    },
  });
  picker.input.focus();
}

// ───────────────────────── מעגלים (SPEC 15.4) ─────────────────────────

const CIRCLE_ICONS = ['👪', '👥', '👵', '👴', '🏠', '🕍', '🍳', '💼', '🎓', '⚽', '❤️', '⭐'];

/**
 * שורת המעגלים בלוח שיתוף (מתכון / אוסף): לחיצה משתפת עם כל מי שבמעגל, לחיצה
 * נוספת מבטלת. החברות דינמית — מי שיצטרף למעגל אחר כך יקבל גם הוא.
 */
async function circleShareRow(slot, kind, targetId, shared) {
  let mine = [];
  try { mine = (await api('circles')).circles; } catch { return; }
  const draw = () => {
    slot.innerHTML = mine.length ? `<div class="circle-row"><span class="muted small">מעגלים:</span>${mine.map((c) => {
      const on = shared.some((x) => x.id === c.id);
      return `<button type="button" class="chip chip--circle ${on ? 'is-on' : ''}" data-circle="${c.id}" aria-pressed="${on}"
        title="${on ? 'ביטול השיתוף עם המעגל' : 'שיתוף עם כל מי שבמעגל'}">${esc(c.icon)} ${esc(c.name)} <span class="small">(${c.n})</span>${on ? ' ✓' : ''}</button>`;
    }).join('')}</div>`
      : '<p class="muted small">👪 אפשר ליצור <a class="link link--inline" href="#/circles">מעגל</a> — "משפחה", "חברים" — ולשתף עם כולם בלחיצה אחת.</p>';
  };
  draw();
  slot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-circle]');
    if (!b) return;
    const on = b.getAttribute('aria-pressed') === 'true';
    b.disabled = true;
    try { shared = (await api(on ? 'circle-unshare' : 'circle-share', { kind, target_id: targetId, circle_id: +b.dataset.circle })).circles; draw(); }
    catch (err) { alert(err.message); b.disabled = false; }
  });
}

/** טופס מעגל: סמל ושם. */
function circleForm(c = null) {
  const icon = c ? c.icon : '👪';
  return `
    <form class="coll-form share-box" id="circle-form">
      <div class="coll-form__icons" role="radiogroup" aria-label="סמל">${CIRCLE_ICONS.map((i) => `
        <label class="coll-form__icon"><input type="radio" name="icon" value="${i}" ${i === icon ? 'checked' : ''}><span>${i}</span></label>`).join('')}</div>
      <label>שם <input name="name" maxlength="40" required value="${esc(c ? c.name : '')}" placeholder="למשל: משפחה"></label>
      <div class="actions"><button class="btn btn--primary" type="submit">${c ? 'שמור' : 'צור מעגל'}</button>
        <button class="btn btn--ghost" type="button" data-cancel>ביטול</button></div>
      <p class="note" id="circle-msg" hidden></p>
    </form>`;
}
function bindCircleForm(slot, submit) {
  const f = $('#circle-form');
  f.name.focus();
  f.querySelector('[data-cancel]').addEventListener('click', () => { slot.innerHTML = ''; });
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    try { await submit({ icon: f.icon.value, name: f.name.value }); }
    catch (err) { const m = $('#circle-msg'); m.textContent = err.message; m.className = 'note note--err'; m.hidden = false; }
  });
}

async function renderCircles() {
  const { circles } = await api('circles');
  view.innerHTML = `
    <section class="card settings settings--wide colls">
      <a class="link" href="#/people">‹ אנשים</a>
      <h2>👪 המעגלים שלי</h2>
      <p class="muted">מעגל הוא רשימת אנשים בשם שלך — "משפחה", "חברים מהעבודה" — כדי לשתף ולהמליץ לכולם בלחיצה אחת.
        <strong>רק אתה רואה את המעגלים.</strong> מי שבמעגל לא יודע את שמו ולא מי עוד בו — רק שמשהו שותף איתו.</p>
      <button class="btn btn--primary" type="button" id="circle-new">+ מעגל חדש</button>
      <div id="circle-new-slot"></div>
      <div class="list">${circles.length ? circles.map((c) => `
        <a class="item" href="#/circle/${c.id}">
          <span class="item__thumb item__thumb--empty">${esc(c.icon)}</span>
          <div class="item__main"><strong>${esc(c.name)}</strong><span class="muted">${c.n === 1 ? 'אדם אחד' : `${c.n} אנשים`}</span></div>
        </a>`).join('') : '<p class="muted">עוד אין מעגלים.</p>'}</div>
    </section>`;
  $('#circle-new').addEventListener('click', () => {
    const slot = $('#circle-new-slot');
    if (slot.innerHTML) { slot.innerHTML = ''; return; }
    slot.innerHTML = circleForm();
    bindCircleForm(slot, async (data) => { const { circle } = await api('circle-create', data); go('#/circle/' + circle.id); });
  });
}

async function renderCircle(id) {
  let d = await api('circle', { id });
  view.innerHTML = `
    <section class="card settings settings--wide circle-page">
      <div class="recipe__top">
        <a class="link" href="#/circles">‹ המעגלים</a>
        <span class="recipe__icons">
          <button class="icon-btn" id="circle-edit" type="button" aria-label="עריכה" title="עריכה">✏️</button>
          <button class="icon-btn icon-btn--danger" id="circle-del" type="button" aria-label="מחיקה" title="מחיקה">🗑</button>
        </span>
      </div>
      <h2 id="circle-title"></h2>
      <p class="muted small" id="circle-used"></p>
      <div id="circle-slot"></div>
      <div id="circle-members" class="people-list"></div>
      <h3>הוספה למעגל</h3>
      <div id="circle-pick"></div>
      <p class="muted small">מי שתוסיף יקבל מיד את כל מה שכבר שותף עם המעגל; מי שתוציא — יפסיק לראות (אלא אם שיתפת איתו גם בשמו).</p>
    </section>`;
  const draw = () => {
    const c = d.circle;
    $('#circle-title').textContent = `${c.icon} ${c.name}`;
    $('#circle-used').textContent = `${c.n === 1 ? 'אדם אחד' : `${c.n} אנשים`} · משותפים עם המעגל: ${d.used.recipe} מתכונים, ${d.used.collection} אוספים`;
    $('#circle-members').innerHTML = d.members.length ? d.members.map((p) => personRow(p, `${p.reachable ? ''
      : '<span class="muted small" title="חסם אותך, או בחר שרק מי שהוא עוקב אחריו / אף אחד לא ישתף איתו">לא מקבל שיתופים ממך</span>'}
      <button class="btn btn--ghost btn--small" type="button" data-remove="${p.id}" aria-label="הוצאה מהמעגל">✕</button>`)).join('')
      : '<p class="muted small">המעגל ריק — מוסיפים למטה.</p>';
  };
  draw();
  $('#circle-members').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-remove]');
    if (!b) return;
    try { d = await api('circle-remove', { id, user_id: +b.dataset.remove }); draw(); picker.refresh(); } catch (err) { alert(err.message); }
  });
  const picker = peoplePicker($('#circle-pick'), {
    placeholder: 'חיפוש לפי שם…',
    actionFor: (p) => d.members.some((m) => m.id === p.id) ? '<span class="muted small">במעגל ✓</span>'
      : '<button class="btn btn--primary btn--small" type="button" data-act="add">הוסף</button>',
    onAction: async (pid) => { d = await api('circle-add', { id, user_ids: [pid] }); draw(); },
  });
  $('#circle-edit').addEventListener('click', () => {
    const slot = $('#circle-slot');
    if (slot.innerHTML) { slot.innerHTML = ''; return; }
    slot.innerHTML = circleForm(d.circle);
    bindCircleForm(slot, async (data) => { d = await api('circle-update', { id, ...data }); slot.innerHTML = ''; draw(); });
  });
  $('#circle-del').addEventListener('click', async () => {
    if (!confirm(`למחוק את המעגל "${d.circle.name}"?\n\nמי שקיבל מתכונים או אוספים רק דרך המעגל — יפסיק לראות אותם.`)) return;
    try { await api('circle-delete', { id }); go('#/circles'); } catch (err) { alert(err.message); }
  });
}

const followBtn = (p) => p.is_me ? '' : p.following
  ? '<button class="btn btn--small" type="button" data-act="unfollow">✓ עוקב</button>'
  : '<button class="btn btn--primary btn--small" type="button" data-act="follow">+ מעקב</button>';

/** דף משתמש: מונים, מעקב, והמתכונים שגלויים לי. */
async function renderPerson(id) {
  const d = await api('person', { id });
  const p = d.person;
  view.innerHTML = `
    <section class="card person-card">
      <a class="link" href="#/people">‹ אנשים</a>
      <div class="person-card__head">
        <span class="avatar avatar--big" aria-hidden="true">${esc(p.display_name.charAt(0))}</span>
        <div>
          <h2>${esc(p.display_name)}</h2>
          <span class="muted small" dir="ltr">@${esc(p.username)}</span>${p.follows_me ? ' <span class="badge badge--shared">עוקב/ת אחריך</span>' : ''}
        </div>
      </div>
      <div class="person-card__stats">
        <span><strong>${p.public_n}</strong> מתכונים ציבוריים</span>
        <span><strong>${p.followers_n}</strong> עוקבים</span>
        <span><strong>${p.following_n}</strong> נעקבים</span>
      </div>
      <div id="follow-slot" data-pid="${p.id}">${p.is_me ? '<span class="muted">זה הדף שלך.</span>'
        : p.blocked_by_me ? `<span class="muted">חסמת את ${esc(p.display_name)}.</span> <button class="btn btn--small" type="button" data-act="unblock">ביטול חסימה</button>`
        : `${followBtn(p)} <span class="person-card__more"><button class="link link--muted" type="button" data-act="report-user">דווח</button>
           <button class="link link--danger" type="button" data-act="block">חסום</button></span>`}</div>
      <div id="person-report"></div>
    </section>
    ${d.collections && d.collections.length ? `<h3 class="list__head">📁 אוספים</h3><div class="coll-grid">${d.collections.map(collectionCard).join('')}</div>` : ''}
    <section class="list">${d.recipes.length ? d.recipes.map(recipeItem).join('') : '<p class="muted">אין מתכונים שגלויים לך.</p>'}</section>`;
  $('#follow-slot').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    if (b.dataset.act === 'report-user') { reportBox($('#person-report'), 'user', p.id, p.display_name); return; }
    if (b.dataset.act === 'block' && !confirm(`לחסום את ${p.display_name}?\n\nלא תראו זה את זה בחיפוש ובמתכונים, לא תוכלו לשתף, להמליץ או לעקוב, והמעקבים והשיתופים ביניכם יימחקו.`)) return;
    b.disabled = true;
    try { await api(b.dataset.act, { id: p.id }); renderPerson(id); } catch (err) { alert(err.message); b.disabled = false; }
  });
}

/** אנשים: חיפוש, אחרי מי אני עוקב, ומי עוקב אחריי. */
async function renderPeople() {
  view.innerHTML = `
    <section class="card settings settings--wide people-page">
      <a class="link" href="#/">‹ לרשימה</a>
      <h2>👥 אנשים</h2>
      <p class="muted">עוקבים אחרי מי שמפרסם מתכונים — והמתכונים הציבוריים שלהם מופיעים בלשונית "נעקבים".
        <a class="link link--inline" href="#/circles">👪 המעגלים שלי ›</a></p>
      <div id="people-pick"></div>
      <nav class="subnav" aria-label="אנשים">
        <a href="#/people" class="is-on" data-tab="following">אני עוקב</a>
        <a href="#/people" data-tab="followers">עוקבים אחריי</a>
      </nav>
      <div id="people-list" class="people-list"><p class="muted">טוען…</p></div>
    </section>`;
  let tab = 'following';
  let net = null;
  const draw = () => {
    const list = net[tab];
    $('#people-list').innerHTML = list.length ? list.map((p) => personRow(p, followBtn(p))).join('')
      : `<p class="muted">${tab === 'following' ? 'עוד לא עוקב אחרי אף אחד — חפש למעלה.' : 'עוד אף אחד לא עוקב אחריך.'}</p>`;
  };
  const load = async () => { net = await api('network'); draw(); };
  const act = async (pid, what) => { await api(what, { id: pid }); await load(); };
  peoplePicker($('#people-pick'), { placeholder: 'חיפוש אנשים לפי שם…', actionFor: followBtn, onAction: act });
  $('#people-list').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    b.disabled = true;
    try { await act(+b.closest('[data-pid]').dataset.pid, b.dataset.act); } catch (err) { alert(err.message); b.disabled = false; }
  });
  $$('[data-tab]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault(); tab = a.dataset.tab;
    $$('[data-tab]').forEach((x) => x.classList.toggle('is-on', x === a)); draw();
  }));
  await load();
}

// ───────────────────────── סריקת מתכון מתמונה ─────────────────────────

/** שליחת סריקה: כמה תמונות בבקשה אחת, עם התקדמות. */
function uploadScan(files, fields, onProgress) {
  return new Promise((resolve, reject) => {
    const fd = new FormData();
    files.forEach((f, i) => fd.append('files[]', f, `scan-${i + 1}.jpg`));
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    const xhr = new XMLHttpRequest();
    xhr.open('POST', './scan.php');
    xhr.withCredentials = true;
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
    xhr.onload = () => {
      let data;
      try { data = JSON.parse(xhr.responseText); } catch { return reject(new Error('השרת החזיר תשובה שאינה תקינה')); }
      data.success ? resolve(data) : reject(new Error(data.error || 'השליחה נכשלה'));
    };
    xhr.onerror = () => reject(new Error('השליחה נכשלה — אין חיבור'));
    xhr.send(fd);
  });
}

const scanImg = (id, i) => `./scan.php?id=${id}&i=${i}`;
const SCAN_STATUS = { pending: '⏳ ממתינה לאישור', done: '✅ המתכון נוצר', rejected: '✖ לא נוצר מתכון' };

function scanCard(s, { dev = false } = {}) {
  const recipes = (s.recipe_ids || []).map((id, k) => `<a class="btn btn--small" href="#/r/${id}">פתח מתכון${s.recipe_ids.length > 1 ? ' ' + (k + 1) : ''} ›</a>`).join('');
  return `
    <article class="scan-card scan-card--${s.status}" data-id="${s.id}">
      <div class="scan-card__head">
        <strong>${SCAN_STATUS[s.status] || s.status}</strong>
        <span class="muted small">${dev && s.user ? esc(s.user.display_name || s.user.username) + ' · ' : ''}${s.visibility === 'public' ? 'ציבורי' : 'פרטי'} · ${s.images} תמונות · ${fmtWhen(s.created_at)}</span>
      </div>
      ${s.note ? `<p class="small">📝 ${esc(s.note)}</p>` : ''}
      ${s.status === 'pending' ? `<div class="scan-card__thumbs">${Array.from({ length: s.images }, (_, i) =>
        `<a href="${scanImg(s.id, i)}" target="_blank" rel="noopener"><img src="${scanImg(s.id, i)}" alt="תמונה ${i + 1}" loading="lazy"></a>`).join('')}</div>` : ''}
      ${s.status === 'rejected' && s.message ? `<p class="note note--warn small">${esc(s.message)}</p>` : ''}
      <div class="actions actions--wrap">
        ${recipes}
        ${s.status === 'pending' && !dev ? '<button class="btn btn--small btn--ghost" type="button" data-cancel>ביטול</button>' : ''}
        ${s.status === 'pending' && dev ? `<button class="btn btn--small btn--primary" type="button" data-ai>🤖 סרוק בבינה</button>
          <button class="btn btn--small btn--danger" type="button" data-reject>דחה</button>` : ''}
      </div>
    </article>`;
}

async function renderScan() {
  const picked = [];   // { file, url }
  view.innerHTML = `
    <section class="card settings settings--wide scan-page">
      <a class="link" href="#/">‹ לרשימה</a>
      <h2>📷 סריקת מתכון מתמונה</h2>
      <p class="muted">צלמו פתק בכתב יד, דף מודפס או גזיר — והמתכון ייבנה מהתמונה: שם, רכיבים ושלבים.
        <strong>כל התמונות בשליחה אחת הן מתכון אחד</strong> (למשל פתק עם המשך מאחור). למתכון אחר — שליחה נפרדת.</p>
      <p class="note small">💳 טעינת קרדיטים לסריקה בבינה — בפיתוח. בינתיים כל סריקה ממתינה לאישור מנהל האפליקציה, והמתכון יופיע אצלך כשתאושר.</p>
      <form id="scan-form" class="form">
        <div class="scan-pick">
          <label class="btn btn--primary">📷 צילום<input type="file" accept="image/*" capture="environment" hidden data-pick></label>
          <label class="btn">🖼 בחירת תמונות<input type="file" accept="image/*" multiple hidden data-pick></label>
        </div>
        <div id="scan-thumbs" class="scan-thumbs"></div>
        <label>הערה (לא חובה)
          <textarea name="note" rows="2" maxlength="1000" placeholder="למשל: העוגה של סבתא. התמונה השנייה היא ההמשך"></textarea></label>
        <fieldset class="scan-vis">
          <label class="check"><input type="radio" name="visibility" value="private" checked> פרטי — רק אני</label>
          <label class="check"><input type="radio" name="visibility" value="public"> ציבורי — לכולם</label>
        </fieldset>
        <label class="check"><input type="checkbox" name="attach" checked> לצרף את התמונות למתכון (נספר במקום האחסון שלך)</label>
        <button class="btn btn--primary" type="submit" id="scan-send" disabled>שליחה לסריקה</button>
        <p class="note" id="scan-msg" hidden></p>
      </form>
      <h3>הסריקות שלי</h3>
      <div id="scan-list" class="scan-list"><p class="muted">טוען…</p></div>
    </section>`;
  const msg = $('#scan-msg');
  const note = (t, k) => { msg.textContent = t; msg.className = 'note' + (k ? ' note--' + k : ''); msg.hidden = !t; };
  let maxImages = 6;
  const drawPicked = () => {
    $('#scan-thumbs').innerHTML = picked.map((p, i) => `
      <figure class="scan-thumb"><img src="${p.url}" alt=""><figcaption>${i + 1}</figcaption>
        <button type="button" class="scan-thumb__x" data-remove="${i}" aria-label="הסרה">×</button></figure>`).join('');
    $('#scan-send').disabled = !picked.length;
  };
  $$('[data-pick]').forEach((inp) => inp.addEventListener('change', () => {
    for (const file of inp.files) {
      if (picked.length >= maxImages) { note(`עד ${maxImages} תמונות למתכון אחד.`, 'warn'); break; }
      picked.push({ file, url: URL.createObjectURL(file) });
    }
    inp.value = '';
    drawPicked();
  }));
  $('#scan-thumbs').addEventListener('click', (e) => {
    const i = e.target.dataset.remove;
    if (i === undefined) return;
    URL.revokeObjectURL(picked[+i].url);
    picked.splice(+i, 1);
    drawPicked();
  });
  const list = $('#scan-list');
  const load = async () => {
    try {
      const d = await api('scans-mine');
      maxImages = d.max_images;
      list.innerHTML = d.scans.length ? d.scans.map((s) => scanCard(s)).join('') : '<p class="muted">עוד לא נשלחו סריקות.</p>';
    } catch (err) { list.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; }
  };
  list.addEventListener('click', async (e) => {
    if (!e.target.matches('[data-cancel]')) return;
    if (!confirm('לבטל את הסריקה? התמונות יימחקו.')) return;
    try { await api('scan-cancel', { id: +e.target.closest('[data-id]').dataset.id }); load(); }
    catch (err) { alert(err.message); }
  });
  $('#scan-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const btn = $('#scan-send');
    btn.disabled = true;
    try {
      note('מכין את התמונות…');
      // כתב יד צריך פרטים — צלע ארוכה 2200px (ולא 1600 כמו בתמונות מתכון)
      const files = await Promise.all(picked.map((p) => shrinkImage(p.file, 2200)));
      await uploadScan(files, { note: f.note.value, visibility: f.visibility.value, attach: f.attach.checked ? '1' : '0' },
        (p) => note(`שולח… ${Math.round(p * 100)}%`));
      picked.splice(0).forEach((p) => URL.revokeObjectURL(p.url));
      f.reset(); drawPicked();
      note('נשלח ✓ הסריקה ממתינה לאישור. המתכון יופיע ברשימה שלך כשתאושר.', 'ok');
      load();
    } catch (err) { note(err.message, 'err'); btn.disabled = !picked.length; }
  });
  await load();
}

/** המפתח: תור הדיווחים — דחייה, הסתרה (פרטי), מחיקה, חסימת המשתמש באתר. */
async function renderReports() {
  if (!state.user.is_developer) { go('#/settings'); return; }
  view.innerHTML = `
    <section class="card settings settings--wide">
      ${settingsNav()}
      <h2>⚑ דיווחים</h2>
      <nav class="subnav"><a href="#/reports" class="is-on" data-st="open">פתוחים</a><a href="#/reports" data-st="closed">טופלו</a></nav>
      <div id="rep-list" class="scan-list"><p class="muted">טוען…</p></div>
    </section>`;
  let st = 'open';
  const KIND = { recipe: 'מתכון', comment: 'תגובה', user: 'משתמש' };
  const link = (r) => r.kind === 'recipe' ? `#/r/${r.target_id}` : r.kind === 'user' ? `#/u/${r.target_id}` : null;
  const draw = (list) => {
    $('#rep-list').innerHTML = list.length ? list.map((r) => `
      <article class="scan-card ${r.status === 'open' ? 'scan-card--pending' : ''}" data-id="${r.id}">
        <div class="scan-card__head"><strong>${KIND[r.kind] || r.kind} · ${esc(r.reason_label)}</strong>
          <span class="muted small">מאת ${esc(r.reporter || 'משתמש שנמחק')} · ${fmtWhen(r.created_at)}</span></div>
        <p>${link(r) ? `<a href="${link(r)}">${esc(r.snapshot || '')}</a>` : `„${esc(r.snapshot || '')}”`}</p>
        <p class="muted small">של ${esc(r.owner.display_name || r.owner.username || '?')}${r.owner.blocked ? ' (חסום באתר)' : ''}${r.note ? ` · 💬 ${esc(r.note)}` : ''}</p>
        ${r.status === 'open' ? `<div class="actions actions--wrap">
          <button class="btn btn--small" type="button" data-a="dismiss">אין הפרה</button>
          ${r.kind === 'recipe' ? '<button class="btn btn--small" type="button" data-a="hide">הסתר (פרטי)</button>' : ''}
          ${r.kind !== 'user' ? '<button class="btn btn--small btn--danger" type="button" data-a="delete">מחק</button>' : ''}
          <button class="btn btn--small btn--danger" type="button" data-a="block">חסום משתמש באתר</button>
        </div>` : `<p class="small">✓ ${esc(r.resolution || '')} · ${fmtWhen(r.resolved_at)}</p>`}
      </article>`).join('') : `<p class="muted">${st === 'open' ? 'אין דיווחים פתוחים. ✓' : 'עוד לא טופלו דיווחים.'}</p>`;
  };
  const load = async () => draw((await api('reports', { status: st })).reports);
  $('#rep-list').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-a]');
    if (!b) return;
    if (b.dataset.a !== 'dismiss' && !confirm('לבצע? ' + b.textContent.trim())) return;
    try { draw((await api('report-resolve', { id: +b.closest('[data-id]').dataset.id, action: b.dataset.a })).reports); } catch (err) { alert(err.message); }
  });
  $$('[data-st]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault(); st = a.dataset.st; $$('[data-st]').forEach((x) => x.classList.toggle('is-on', x === a)); load();
  }));
  await load();
}

/** המפתח: הסריקות שממתינות — בבינה, ידנית (קובץ לקלוד וחזרה), או דחייה. */
async function renderScansDev() {
  if (!state.user.is_developer) { go('#/scan'); return; }
  view.innerHTML = `
    <section class="card settings settings--wide scan-page">
      ${settingsNav()}
      <h2>📷 סריקות ממתינות</h2>
      <p class="muted">סריקות שמשתמשים שלחו. "סרוק בבינה" — כשיש קרדיט. בלי קרדיט: מורידים קובץ (התמונות בתוכו), שולחים לקלוד בצ'אט,
        ומעלים את הקובץ שחזר. המתכון נוצר אצל מי שסרק, בפרטיות שבחר.</p>
      <div id="sd-pending" class="scan-list"><p class="muted">טוען…</p></div>
      <h3>ידנית — דרך קלוד</h3>
      <div class="actions actions--wrap"><button class="btn btn--primary" type="button" id="sd-download">⬇️ הורד קובץ סריקות</button></div>
      <p class="muted small">לקלוד אפשר גם בלי קובץ: הטוקן של היומן עם <code dir="ltr">&amp;view=scans</code>.</p>
      <form id="sd-form" class="form">
        <label>קובץ מתכונים שחזר <input type="file" name="file" accept=".json,application/json,text/plain"></label>
        <label>או הדבקה <textarea name="text" rows="5" dir="ltr" placeholder='{"format":"recipes-scan-1","items":[…]}'></textarea></label>
        <button class="btn btn--primary" type="submit">צור את המתכונים</button>
        <p class="note" id="sd-msg" hidden></p>
      </form>
      <ul class="site-results" id="sd-results" hidden></ul>
      <h3>טופלו לאחרונה</h3>
      <div id="sd-recent" class="scan-list"></div>
    </section>`;
  const msg = $('#sd-msg');
  const note = (t, k) => { msg.textContent = t; msg.className = 'note' + (k ? ' note--' + k : ''); msg.hidden = !t; };
  const load = async () => {
    const d = await api('scans-pending');
    $('#sd-pending').innerHTML = d.pending.length ? d.pending.map((s) => scanCard(s, { dev: true })).join('') : '<p class="muted">אין סריקות שממתינות. ✓</p>';
    if (!d.ai_available) $$('#sd-pending [data-ai]').forEach((b) => { b.disabled = true; b.title = 'אין מפתח API לבינה'; });
    $('#sd-recent').innerHTML = d.recent.map((s) => scanCard(s, { dev: true })).join('') || '<p class="muted">—</p>';
  };
  $('#sd-pending').addEventListener('click', async (e) => {
    const card = e.target.closest('[data-id]');
    if (!card) return;
    const id = +card.dataset.id;
    try {
      if (e.target.matches('[data-ai]')) {
        e.target.disabled = true; e.target.textContent = 'קורא…';
        const r = await api('scan-ai', { id });
        note(r.status === 'done' ? 'המתכון נוצר ✓' : 'הבינה לא מצאה מתכון — הסריקה נדחתה', r.status === 'done' ? 'ok' : 'warn');
      } else if (e.target.matches('[data-reject]')) {
        const reason = prompt('סיבת הדחייה (תוצג למשתמש):', 'לא הצלחנו לקרוא את הכתב בתמונה — כדאי לצלם שוב, קרוב ובאור טוב');
        if (reason === null) return;
        await api('scan-reject', { id, reason });
      } else return;
    } catch (err) { note(err.message, 'err'); }
    load();
  });
  $('#sd-download').addEventListener('click', async () => {
    try {
      const d = await api('scan-export');
      delete d.success;
      if (!d.items.length) { note('אין סריקות שממתינות.', 'ok'); return; }
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([JSON.stringify(d)], { type: 'application/json' }));
      a.download = `scans-${d.exported_at.slice(0, 10)}.json`;
      document.body.append(a); a.click(); a.remove();
      note(`הורדו ${d.items.length} סריקות${d.total_waiting > d.items.length ? ` (מתוך ${d.total_waiting} — השאר בפעם הבאה)` : ''}. לשלוח את הקובץ לקלוד בצ'אט.`, 'ok');
    } catch (err) { note(err.message, 'err'); }
  });
  $('#sd-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const text = f.file.files[0] ? await f.file.files[0].text() : f.text.value.trim();
    if (!text) { note('בחר קובץ או הדבק את התוכן.', 'warn'); return; }
    try {
      const d = await api('scan-apply', { text });
      const icon = { applied: '✅', rejected: '✖', error: '❌', missing: '❌' };
      const ul = $('#sd-results');
      ul.innerHTML = d.results.map((r) => `<li class="${r.status === 'error' || r.status === 'missing' ? 'is-err' : ''}">${icon[r.status] || '•'} ${esc(r.id)} ${esc(r.title || '')}
        <span class="muted small">${esc(r.message)}</span>${(r.recipe_ids || []).map((id) => ` <a href="#/r/${id}">›</a>`).join('')}</li>`).join('');
      ul.hidden = false;
      note(`נוצרו מתכונים ל-${d.applied} סריקות מתוך ${d.results.length}. נשארו ${d.waiting} ממתינות.`, d.applied ? 'ok' : 'warn');
      f.reset();
      load();
    } catch (err) { note(err.message, 'err'); }
  });
  await load();
}

// ───────────────────────── אינדקס החיפוש (מפתח) ─────────────────────────

const fmtDuration = (sec) => sec < 3600 ? `${Math.max(1, Math.round(sec / 60))} דק׳` : sec < 86400 ? `${Math.round(sec / 360) / 10} שעות` : `${Math.round(sec / 8640) / 10} ימים`;
// בזמן עדכון — כל 5 שניות; כשרק שמות ממתינים (שעות) — כל 30
const s_poll = (d) => (d.sites.some((s) => s.crawling || (s.enabled && s.next_in === 0)) ? 5000 : 30000);

async function renderIndexSites() {
  if (!state.user.is_developer) { go('#/settings'); return; }
  let timer = null;
  view.innerHTML = `
    <section class="card settings settings--wide indexsites">
      ${settingsNav()}
      <h2>🗂 אינדקס החיפוש</h2>
      <p class="muted">המשתמשים מחפשים רק באתרים שכאן. לכל אתר נקראת רשימת המתכונים שהוא מפרסם (sitemap) — שם וקישור לכל מתכון.
        העדכון רץ ברקע, פעם בשבוע, ומביא רק חלקים שהשתנו. מתכון שנעלם מהאתר — יוצא מהאינדקס.</p>
      <form id="site-form" class="form">
        <label>אתרים להוספה — אתר בכל שורה: כתובת, ואם רוצים שם בעברית
          <textarea name="text" rows="5" dir="auto" required autocomplete="off" placeholder="www.10dakot.co.il 10 דקות
www.carine.co.il קרין גורן
www.kipa.co.il כיפה — אוכל"></textarea>
        </label>
        <p class="muted small">אפשר גם את כתובת ה-sitemap עצמה. אתר שכבר באינדקס ונכתב לו שם — השם מתעדכן.</p>
        <button class="btn btn--primary" type="submit">הוסף לאינדקס</button>
        <p class="note" id="site-msg" hidden></p>
        <ul class="site-results" id="site-results" hidden></ul>
      </form>
      <p class="muted" id="index-total"></p>
      <div id="site-list" class="scout__list"></div>
    </section>`;
  const msg = $('#site-msg');
  const note = (t, k) => { msg.textContent = t; msg.className = 'note' + (k ? ' note--' + k : ''); msg.hidden = !t; };
  const ago = (iso) => iso ? fmtWhen(iso) : 'עוד לא';
  const draw = (d) => {
    $('#index-total').textContent = d.sites.length ? `${d.total.toLocaleString('he-IL')} מתכונים באינדקס, מ-${d.sites.filter((s) => s.enabled).length} אתרים פעילים.` : '';
    $('#site-list').innerHTML = d.sites.length ? d.sites.map((s) => `
      <article class="scout-item ${s.enabled ? '' : 'scout-item--skipped'}" data-id="${s.id}">
        <div class="scout-item__main">
          <strong>${esc(s.name)}</strong>
          <span class="muted small" dir="ltr">${esc(s.host)}</span>
          <span class="small">${s.entries_n.toLocaleString('he-IL')} מתכונים בחיפוש (🖼 ${s.with_image.toLocaleString('he-IL')} עם תמונה) · ${s.crawling
            ? `<strong>מתעדכן…</strong> ${s.progress.total ? `(${s.progress.done}/${s.progress.total} חלקים)` : '(מחפש את ה-sitemap)'}`
            : `עודכן: ${ago(s.last_crawl_at)}${s.enabled ? ` · הבא בעוד ${s.next_in > 86400 ? Math.round(s.next_in / 86400) + ' ימים' : s.next_in > 3600 ? Math.round(s.next_in / 3600) + ' שעות' : s.next_in > 0 ? Math.round(s.next_in / 60) + ' דק׳' : 'עכשיו'}` : ''}`}</span>
          ${s.titles_pending ? `<span class="small">🏷 ${s.titles_pending.toLocaleString('he-IL')} ממתינים לשם — בכתובת שלהם אין שם בעברית, אז השם מובא מהדף עצמו, ברקע, דף כל ${d.title_gap}–${d.title_gap + 10} שניות
            (עוד ${fmtDuration(s.titles_pending * (d.title_gap + 5))}).</span>` : ''}
          ${s.images_pending ? `<span class="small">🖼 ${s.images_pending.toLocaleString('he-IL')} ממתינים לתמונה — ב-sitemap שלהם אין תמונה, אז היא מובאת מהדף, ברקע, אחרי השמות
            (עוד ${fmtDuration((s.titles_pending + s.images_pending) * (d.title_gap + 5))}).</span>` : ''}
          ${s.not_recipes ? `<span class="muted small">${s.not_recipes.toLocaleString('he-IL')} דפים נבדקו ואינם מתכונים — לא בחיפוש.</span>` : ''}
          ${s.last_error ? `<span class="note note--warn small">${esc(s.last_error)}</span>` : ''}
          <div class="actions actions--wrap">
            <button class="btn btn--ghost" type="button" data-refresh="${s.id}">רענן עכשיו</button>
            <button class="btn btn--ghost btn--danger" type="button" data-remove="${s.id}">הסר</button>
          </div>
        </div>
        <div class="scout-item__actions">
          <label class="switch"><input type="checkbox" data-toggle="${s.id}" ${s.enabled ? 'checked' : ''}><span class="switch__track"></span>
            <span class="switch__label">${s.enabled ? 'בחיפוש' : 'מושבת'}</span></label>
        </div>
      </article>`).join('') : '<p class="muted">עוד אין אתרים. מוסיפים כתובת של אתר מתכונים — למשל www.10dakot.co.il.</p>';
    $$('[data-refresh]').forEach((b) => b.addEventListener('click', () => act('index-site-refresh', { id: +b.dataset.refresh })));
    $$('[data-remove]').forEach((b) => b.addEventListener('click', () => {
      if (confirm('להסיר את האתר מהאינדקס? המתכונים שלו ייצאו מהחיפוש. מתכונים שמשתמשים כבר שמרו — נשארים אצלם.')) act('index-site-remove', { id: +b.dataset.remove });
    }));
    $$('[data-toggle]').forEach((c) => c.addEventListener('change', () => act('index-site-toggle', { id: +c.dataset.toggle, on: c.checked })));
    clearTimeout(timer);
    if (d.sites.some((s) => s.crawling || (s.enabled && s.next_in === 0) || s.titles_pending || s.images_pending)) timer = setTimeout(load, s_poll(d));   // מתעדכן — מרעננים
  };
  const act = async (action, payload) => { try { draw(await api(action, payload)); } catch (err) { note(err.message, 'err'); } };
  const load = async () => { if (location.hash !== '#/index') return; try { draw(await api('index-sites')); } catch (err) { note(err.message, 'err'); } };
  $('#site-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target; const b = f.querySelector('button'); b.disabled = true;
    try {
      const d = await api('index-sites-add', { text: f.text.value });
      draw(d);
      const icon = { added: '✅', renamed: '✏️', exists: '•', error: '❌' };
      const ul = $('#site-results');
      ul.innerHTML = d.results.map((r) => `<li class="${r.status === 'error' ? 'is-err' : ''}">${icon[r.status]} <span dir="ltr">${esc(r.host || r.line)}</span>${r.name ? ` — ${esc(r.name)}` : ''} <span class="muted small">${esc(r.message)}</span></li>`).join('');
      ul.hidden = false;
      const n = d.results.filter((r) => r.status === 'added').length;
      const bad = d.results.filter((r) => r.status === 'error');
      if (!bad.length) f.reset(); else f.text.value = bad.map((r) => r.line).join('\n');   // נשארות רק השורות שנכשלו
      note(n ? `נוספו ${n}. העדכון הראשון רץ ברקע — כמה דקות לכל אתר.` : bad.length ? 'לא נוסף דבר — ראה למטה.' : 'עודכן.', n ? 'ok' : bad.length ? 'err' : 'ok');
    } catch (err) { note(err.message, 'err'); }
    b.disabled = false;
  });
  await load();
}

// ───────────────────────── סורק אתרים (מפתח) ─────────────────────────

// כתובת לקריאה: בלי https://, ועם העברית מפוענחת במקום %d7%a2…
const prettyUrl = (u) => { try { return decodeURIComponent(u).replace(/^https?:\/\//, '').slice(0, 90); } catch { return u.slice(0, 90); } };

const SCOUT_LABEL = { new: 'חדש', wanted: 'לייבוא', skipped: 'דילוג', imported: 'יובא', error: 'שגיאה' };

async function renderScout() {
  if (!state.user.is_developer) { go('#/settings'); return; }
  const filters = { status: 'new', site: '', q: '' };
  let data = { items: [], counts: {}, sites: [] };
  let job = null;
  let jobTimer = null;

  view.innerHTML = `
    <section class="card settings settings--wide scout">
      ${settingsNav()}
      <h2>🔎 סורק אתרים <span class="muted">— כלי פרטי</span></h2>
      <p class="muted">מדביקים דף קטגוריה, דף בית, או sitemap של אתר מתכונים. הסורק מציע מועמדים; אתה מסמן מה כן ומה לא;
        המסומנים מיובאים אחד-אחד עם מרווח בין הבאות, בכבוד ל-robots.txt, פרטיים ועם קרדיט. לא סריקה המונית — בכוונה.</p>
      <form id="scout-form" class="form">
        <label>דף רשימה או sitemap
          <input name="url" type="url" inputmode="url" dir="ltr" placeholder="https://www.example.co.il/recipes/  או  …/recipe-sitemap.xml" required autocomplete="off">
        </label>
        <button class="btn btn--primary" type="submit">מצא מתכונים</button>
        <p class="note" id="scout-msg" hidden></p>
      </form>

      <div class="scout__bar">
        <nav class="subnav" id="scout-tabs" aria-label="מצב"></nav>
        <div class="logfilter">
          <select name="site" aria-label="אתר"><option value="">כל האתרים</option></select>
          <input type="search" name="q" placeholder="חיפוש בשם" autocomplete="off">
        </div>
      </div>
      <div class="scout__bulk" id="scout-bulk">
        <span class="muted small scout__bulk-label">על כל מה שברשימה:</span>
        <button class="btn" type="button" data-bulk="wanted">הכול לייבוא</button>
        <button class="btn btn--ghost" type="button" data-bulk="skipped">דלג על הכול</button>
        <button class="btn btn--ghost btn--danger" type="button" data-bulk="remove">מחק הכול</button>
      </div>
      <div id="scout-list" class="scout__list"></div>

      <div class="scout__run" id="scout-run">
        <h3>ייבוא המסומנים — ברקע</h3>
        <p class="muted small">הייבוא רץ בשרת: אפשר לסגור את הדף, את הדפדפן, או לכבות את הטלפון — הוא ממשיך עד שהרשימה נגמרת או שעוצרים.
          אם האתר חוסם, הוא ממתין לסוף ההמתנה, מאט, וממשיך לבד.</p>
        <div class="row">
          <label>מרווח בין פריטים (שניות) <input type="number" name="gap" min="10" max="180" value="25" inputmode="numeric"></label>
          <label class="check check--big"><input type="checkbox" name="rewrite" id="scout-rewrite"> לנסח מחדש בבינה</label>
        </div>
        <div class="actions scout__go">
          <button class="btn btn--primary" type="button" id="scout-go">התחל ייבוא ברקע</button>
          <button class="btn btn--danger" type="button" id="scout-stop" hidden>⏹ עצור</button>
        </div>
        <div id="scout-job" class="scout__job" hidden></div>
        <p class="muted small">כל הבאה נרשמת ב<a href="#/logs/import">יומן הייבוא</a> — מה היה בדף ומה חולץ, עם קישור למקור.</p>
        <details class="scout__cron" id="scout-cron" hidden>
          <summary class="link">לאמינות מלאה: cron ב-cPanel (לא חובה)</summary>
          <p class="muted small">הייבוא ממשיך לבד גם בלי זה. ה-cron רק מבטיח שאם השרת עצר את העובד באמצע, הוא יחזור תוך 5 דקות גם כשאף אחד לא נכנס לאפליקציה.
            ב-cPanel → Cron Jobs → כל 5 דקות (<code dir="ltr">*/5 * * * *</code>), עם הפקודה:</p>
          <input class="mono" dir="ltr" readonly id="cron-cmd">
          <button class="btn btn--ghost" type="button" id="cron-copy">העתק פקודה</button>
          <p class="muted small">הכתובת מכילה מפתח — לא לשתף.</p>
        </details>
      </div>
    </section>`;

  const listEl = $('#scout-list');
  const msgEl = $('#scout-msg');
  const note = (t, k) => { msgEl.textContent = t; msgEl.className = 'note' + (k ? ' note--' + k : ''); msgEl.hidden = !t; };
  api('ai-status').then(({ available }) => { const cb = $('#scout-rewrite'); cb.checked = available; cb.disabled = !available; if (!available) cb.parentElement.append(' (אין מפתח API)'); }).catch(() => {});

  const drawTabs = () => {
    $('#scout-tabs').innerHTML = ['new', 'wanted', 'skipped', 'imported', 'error'].map((s) =>
      `<a href="#/scout" data-status="${s}" class="${filters.status === s ? 'is-on' : ''}">${SCOUT_LABEL[s]} (${data.counts[s] || 0})</a>`).join('');
    $$('[data-status]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); filters.status = a.dataset.status; load(); }));
    const n = data.counts.wanted || 0;
    if (!job || job.state !== 'running') $('#scout-go').textContent = n ? `התחל ייבוא ברקע (${n})` : 'התחל ייבוא ברקע';
    $('#scout-bulk').hidden = !data.items.some((it) => it.status !== 'imported');
    const sel = $('select[name="site"]'); const cur = sel.value;
    sel.innerHTML = '<option value="">כל האתרים</option>' + data.sites.map((s) => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
    sel.value = cur;
  };
  const drawList = () => {
    listEl.innerHTML = data.items.length ? data.items.map((it) => `
      <article class="scout-item scout-item--${it.status}" data-id="${it.id}">
        <div class="scout-item__main">
          <strong>${esc(it.title || '(בלי שם)')}</strong>
          <a class="muted small scout-item__url" dir="auto" href="${esc(it.url)}" target="_blank" rel="noopener nofollow">${esc(prettyUrl(it.url))}</a>
          ${it.error ? `<span class="note note--${it.status === 'error' ? 'err' : 'warn'} small">${esc(it.error)}</span>` : ''}
          ${it.recipe_id ? `<a class="link" href="#/r/${it.recipe_id}">למתכון שיובא ›</a>`
            : `<button class="link" type="button" data-expand="${it.id}">▾ הצג מה יחולץ</button>`}
          <div class="scout-item__preview" data-preview="${it.id}" hidden></div>
        </div>
        <div class="scout-item__actions">
          ${it.status !== 'imported' ? `
            <label class="switch" title="${it.status === 'wanted' ? 'מסומן לייבוא' : 'לא לייבוא'}">
              <input type="checkbox" data-want ${it.status === 'wanted' ? 'checked' : ''}>
              <span class="switch__track"></span>
              <span class="switch__label">${it.status === 'wanted' ? 'לייבוא' : it.status === 'skipped' ? 'דילוג' : 'לא נבחר'}</span>
            </label>` : '<span class="badge badge--public">יובא</span>'}
        </div>
      </article>`).join('') : '<p class="muted">אין פריטים במצב הזה.</p>';
    // מתג אחד: דולק = לייבוא, כבוי = דילוג. הפריט נשאר במקומו ברשימה אחרי
    // הסימון — אחרת, בלשונית "חדש", הוא נעלם ברגע שסימנו אותו ונראה כאילו
    // הבחירה התבטלה. הרשימה מסתננת מחדש רק כשעוברים לשונית.
    $$('[data-want]', listEl).forEach((c) => c.addEventListener('change', async () => {
      const art = c.closest('.scout-item'); const id = +art.dataset.id;
      const status = c.checked ? 'wanted' : 'skipped';
      try {
        const res = await api('scout-mark', { ids: [id], status });
        const it = data.items.find((x) => x.id === id);
        if (it) it.status = status;
        data.counts = res.counts;
        art.className = `scout-item scout-item--${status}`;
        art.querySelector('.switch__label').textContent = status === 'wanted' ? 'לייבוא' : 'דילוג';
        drawTabs();
      } catch (err) { c.checked = !c.checked; note(err.message, 'err'); }
    }));
    // הרחבה: מה בדיוק יחולץ מהדף — להשוואה מול המקור, ולתיקון בעורך לפני שמירה
    $$('[data-expand]', listEl).forEach((b) => b.addEventListener('click', async () => {
      const id = +b.dataset.expand;
      const box = $(`[data-preview="${id}"]`, listEl);
      const it = data.items.find((x) => x.id === id);
      if (!box.hidden) { box.hidden = true; b.textContent = '▾ הצג מה יחולץ'; return; }
      box.hidden = false; b.textContent = '▴ הסתר';
      if (!it._draft) {
        box.innerHTML = '<p class="muted">מביא את הדף ומחלץ…</p>';
        try { const res = await api('scout-preview', { id }); it._draft = res.draft; it._ai = res.ai_available; if (res.draft.title) it.title = res.draft.title; }
        catch (err) { box.innerHTML = `<p class="note note--err">${esc(err.message)}</p>`; return; }
      }
      const d = it._draft;
      const sec = d.sections || [];
      box.innerHTML = `
        <div class="preview">
          <p><strong>${esc(d.title)}</strong> <span class="muted small">· ${esc(d.extracted_by)}${d.servings ? ` · ${d.servings} מנות` : d.yield_text ? ` · ${esc(d.yield_text)}` : ''}${d.work_minutes ? ` · עבודה ${d.work_minutes} דק׳` : ''}${d.wait_minutes ? ` · המתנה ${d.wait_minutes} דק׳` : ''}</span></p>
          ${d.warnings?.length ? `<p class="note note--warn small">${d.warnings.map(esc).join(' · ')}</p>` : ''}
          ${d.pending_media?.images?.length ? `<div class="gallery__thumbs preview__imgs">${d.pending_media.images.slice(0, 3).map((u) => `<img src="${esc(u)}" alt="" loading="lazy" referrerpolicy="no-referrer">`).join('')}</div>` : ''}
          ${sec.map((s) => `
            ${s.name ? `<h4>${esc(s.name)}</h4>` : ''}
            ${s.ingredients?.length ? `<h5>רכיבים (${s.ingredients.length})</h5><ul class="ings ings--compact">${s.ingredients.map((i) => `<li>${esc(i.free_text)}${i.product ? ` <span class="muted small">→ ${esc(i.product)}${i.amount_min != null ? ` ${fmtAmount(i.amount_min)} ${UNITS[i.unit] || ''}` : ''}</span>` : ''}</li>`).join('')}</ul>` : ''}
            ${s.steps?.length ? `<h5>שלבים (${s.steps.length})</h5><ol class="steps steps--compact">${s.steps.map((st) => `<li>${esc(st.text)}</li>`).join('')}</ol>` : '<p class="muted small">לא חולצו שלבים</p>'}`).join('')}
          <div class="actions actions--wrap">
            <a class="btn btn--ghost" href="${esc(it.url)}" target="_blank" rel="noopener nofollow">↗ המקור להשוואה</a>
            <button class="btn btn--primary" type="button" data-edit="${id}">✏️ פתח בעורך לתיקון</button>
            <button class="btn" type="button" data-import-one="${id}">ייבא כמו שזה</button>
          </div>
        </div>`;
      $('[data-edit]', box).addEventListener('click', async () => {
        d.ai_available = it._ai;
        await renderEditor(null, d);   // השמירה בעורך מסמנת את הפריט "יובא" לפי source_url
        window.scrollTo(0, 0);
      });
      $('[data-import-one]', box).addEventListener('click', async (e) => {
        e.target.disabled = true;
        try {
          const r = await api('scout-import', { id, rewrite: $('#scout-rewrite').checked });
          it.status = 'imported'; it.recipe_id = r.recipe_id; drawList(); await load();
        } catch (err) { box.insertAdjacentHTML('beforeend', `<p class="note note--err">${esc(err.message)}</p>`); }
      });
    }));
  };
  const load = async () => {
    try { data = await api('scout-list', filters); drawTabs(); drawList(); }
    catch (err) { note(err.message, 'err'); }
  };

  $('#scout-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button'); btn.disabled = true;
    note('מביא את הדף… (robots.txt + מרווח)');
    try {
      const res = await api('scout-page', { url: e.target.url.value.trim() });
      note(`נמצאו ${res.found} מועמדים ב-${res.site}, ${res.new} חדשים.${res.is_sitemap_index ? ' זה אינדקס של sitemaps — להדביק אחד מהם.' : ''}`, 'ok');
      filters.status = 'new'; filters.site = ''; await load();
    } catch (err) { note(err.message, 'err'); }
    btn.disabled = false;
  });
  $('select[name="site"]').addEventListener('change', (e) => { filters.site = e.target.value; load(); });
  $('input[name="q"]').addEventListener('input', (e) => { filters.q = e.target.value.trim(); clearTimeout(load._t); load._t = setTimeout(load, 300); });
  $$('[data-bulk]').forEach((b) => b.addEventListener('click', async () => {
    const ids = data.items.filter((it) => it.status !== 'imported').map((it) => it.id);
    if (!ids.length) return;
    if (b.dataset.bulk === 'remove') {
      if (!confirm(`למחוק ${ids.length} פריטים מהרשימה? (המתכונים שיובאו נשארים)`)) return;
      try { await api('scout-remove', { ids }); await load(); } catch (err) { note(err.message, 'err'); }
      return;
    }
    // אחרי סימון כולם — עוברים ללשונית של המצב החדש, שם הם עכשיו
    try { await api('scout-mark', { ids, status: b.dataset.bulk }); filters.status = b.dataset.bulk; await load(); } catch (err) { note(err.message, 'err'); }
  }));

  // ── הייבוא ברקע: השרת הוא המתזמן (lib/scout_job.php). הדף רק מפעיל,
  //    עוצר ומציג. כשהדף פתוח — רענון מצב כל 5 שניות; כשנסגר — העבודה ממשיכה. ──
  const fmtWait = (s) => s >= 120 ? `${Math.round(s / 60)} דק׳` : `${s} שנ׳`;
  const drawJob = (j) => {
    job = j;
    const box = $('#scout-job');
    const run = j.state === 'running';
    $('#scout-go').hidden = run;
    $('#scout-stop').hidden = !run;
    if (!run) $('#scout-go').textContent = j.left ? `התחל ייבוא ברקע (${j.left})` : 'התחל ייבוא ברקע';
    if (j.cron_url) { $('#scout-cron').hidden = false; $('#cron-cmd').value = `curl -s "${j.cron_url}" >/dev/null 2>&1`; }
    if (j.state === 'idle') { box.hidden = true; return; }
    box.hidden = false;
    const head = run
      ? `<strong>▶ רץ ברקע</strong> · יובאו ${j.done_n}${j.fail_n ? ` · נכשלו ${j.fail_n}` : ''}${j.blocks_n ? ` · חסימות ${j.blocks_n}` : ''} · נשארו ${j.left}
         <br><span class="muted small">מרווח ${j.gap_sec} שנ׳ · ${j.next_in > 0 ? `הפריט הבא בעוד ${fmtWait(j.next_in)}` : 'מייבא עכשיו…'}${!j.alive ? ' · ⚠️ העובד שתק — מעיר אותו' : ''}</span>`
      : `<strong>${j.state === 'done' ? '✅ הסתיים' : '⏹ נעצר'}</strong> · יובאו ${j.done_n}${j.fail_n ? ` · נכשלו ${j.fail_n}` : ''}${j.left ? ` · נשארו ${j.left} לייבוא` : ''}`;
    box.className = `scout__job note ${run ? '' : j.state === 'done' ? 'note--ok' : 'note--warn'}`;
    box.innerHTML = `
      <p>${head}</p>
      ${j.message ? `<p class="small">${esc(j.message)}</p>` : ''}
      ${j.recent.length ? `<ol class="scout__progress">${j.recent.map((r) => `<li>${r.ok
        ? `<a href="#/r/${r.recipe_id}">${esc(r.title)}</a> ✅${r.rewritten ? ' <span class="muted small">נוסח מחדש</span>' : ''}${r.note ? ` <span class="muted small">${esc(r.note)}</span>` : ''}`
        : `${esc(r.title)} ${r.blocked ? '⏸' : '❌'} <span class="muted small">${esc(r.note || '')}</span>`}</li>`).join('')}</ol>` : ''}`;
  };
  const pollJob = async () => {
    if (location.hash !== '#/scout') { clearInterval(jobTimer); return; }
    try {
      const was = job?.done_n;
      const { job: j } = await api('scout-job');
      drawJob(j);
      if (was != null && j.done_n !== was) load();   // יובא משהו — לרענן את הלשוניות
    } catch {}
  };
  $('#scout-go').addEventListener('click', async () => {
    const b = $('#scout-go'); b.disabled = true;
    try {
      const { job: j } = await api('scout-job-start', { gap: Math.max(10, +$('input[name="gap"]').value || 25), rewrite: $('#scout-rewrite').checked });
      drawJob(j); note('');
    } catch (err) { note(err.message, 'warn'); }
    b.disabled = false;
  });
  $('#scout-stop').addEventListener('click', async () => {
    if (!confirm('לעצור את הייבוא ברקע? מה שלא יובא נשאר מסומן "לייבוא".')) return;
    try { drawJob((await api('scout-job-stop')).job); await load(); } catch (err) { note(err.message, 'err'); }
  });
  $('#cron-copy').addEventListener('click', async () => {
    const inp = $('#cron-cmd'); inp.select();
    try { await navigator.clipboard.writeText(inp.value); } catch { document.execCommand('copy'); }
    $('#cron-copy').textContent = 'הועתק ✓';
  });
  api('scout-job').then(({ job: j }) => { drawJob(j); $('input[name="gap"]').value = j.gap_sec; }).catch(() => {});
  jobTimer = setInterval(pollJob, 5000);

  await load();
}

async function renderDiag() {
  const { diag: d } = await api('diag');
  const yes = (b) => (b === true ? '✅' : b === false ? '❌' : '—');
  const rows = (obj, fmt = (v) => esc(v)) =>
    Object.entries(obj).map(([k, v]) => `<tr><th>${esc(k)}</th><td>${fmt(v)}</td></tr>`).join('');
  const boolRows = (obj) => rows(obj, (v) => (typeof v === 'boolean' ? yes(v) : esc(v)));

  view.innerHTML = `
    <section class="card settings settings--wide diag">
      ${settingsNav()}
      <h2>אזור פיתוח — מה השרת אומר על עצמו</h2>

      <h3>מגבלות העלאה ${d.limits.video_capped_by_server ? '<span class="badge badge--warn">השרת מגביל מתחת לאפיון</span>' : ''}</h3>
      <p class="muted">האפיון קבע 20MB לסרטון. התקרה בפועל היא המינימום בין זה לבין מה ש-PHP מרשה.</p>
      <table>${rows({
        'תקרה לסרטון לפי האפיון': d.limits.video_spec,
        'תקרה בפועל': d.limits.video_effective,
        'upload_max_filesize': d.php.upload_max_filesize,
        'post_max_size': d.php.post_max_size,
        'תקרה לתמונה': d.limits.image_max,
        'מקצב לחשבון': d.limits.quota_per_user,
      })}</table>

      <h3>דיסק</h3>
      <table>${rows({
        'פנוי': d.disk.free, 'סה"כ': d.disk.total, 'מדיה שהועלתה': d.disk.media_used,
        'חשבונות מלאים (200MB) שהדיסק מחזיק': d.disk.full_accounts ?? '—',
      })}</table>

      <h3>דוא"ל</h3>
      <table>${boolRows({ 'mail() קיימת': d.mail.function_exists, 'sendmail_path': d.mail.sendmail_path || '(ריק)' })}</table>
      <p class="muted">אם mail() קיימת אך דוא"ל לא מגיע — הבעיה ב-MTA של השרת, לא בקוד. הרשמת המנהל שלחה דוא"ל בדיקה.</p>

      <h3>PHP והרחבות</h3>
      <table>${rows({ 'גרסה': d.php.version, 'memory_limit': d.php.memory_limit, 'max_file_uploads': d.php.max_file_uploads })}
             ${boolRows(d.extensions)}</table>

      <h3>אחסון</h3>
      <table>${boolRows(d.storage)}</table>

      <h3>ספירות</h3>
      <table>${rows(d.counts)}</table>
    </section>`;
}

// ───────────────────────── טעינה ─────────────────────────

api('me')
  .then(({ user, assets_version }) => { setUser(user); checkVersion(assets_version); if (user) route(); })
  .catch(() => { setUser(null); say('לא הצלחתי להגיע לשרת. יש לרענן את הדף.', 'err'); });

// התקנה כאפליקציה + שלד בלי רשת (service-worker.js). כשל ברישום אינו מפריע לאפליקציה.
if ('serviceWorker' in navigator) navigator.serviceWorker.register('./service-worker.js').catch(() => {});
