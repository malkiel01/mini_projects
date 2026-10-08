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
async function shrinkImage(file) {
  if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024) return file;
  const bitmap = await createImageBitmap(file).catch(() => null);
  if (!bitmap) return file;
  const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
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
  const m = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|shorts\/|embed\/))([\w-]{11})/);
  return m ? `https://www.youtube-nocookie.com/embed/${m[1]}` : null;
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
      ${videos.map((v) => {
        if (v.source === 'upload') return `<video class="gallery__video" controls preload="metadata" src="${esc(v.url)}"></video>`;
        const emb = embedUrl(v.url);
        return emb
          ? `<iframe class="gallery__video" src="${esc(emb)}" allowfullscreen loading="lazy" referrerpolicy="no-referrer" title="סרטון"></iframe>`
          : `<a class="btn" href="${esc(v.url)}" target="_blank" rel="noopener">▶ סרטון (קישור חיצוני)</a>`;
      }).join('')}
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
  $$('[data-dev]').forEach((a) => { a.hidden = !(on && user.is_developer); });
  if (on) $('#who').textContent = user.display_name || user.username;
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
    if (h === '#/' || h === '') await renderList();
    else if ((m = h.match(/^#\/r\/(\d+)$/))) await renderRecipe(+m[1]);
    else if (h === '#/new') await renderEditor(null);
    else if (h === '#/import' || h.startsWith('#/import?')) await renderImport(new URLSearchParams(h.split('?')[1] || '').get('url') || '');
    else if ((m = h.match(/^#\/edit\/(\d+)$/))) await renderEditor(+m[1]);
    else if (h === '#/favorites') await renderFavorites();
    else if (h === '#/pantry') await renderPantry();
    else if (h === '#/shopping') await renderShoppingLists();
    else if ((m = h.match(/^#\/shopping\/(\d+)$/))) await renderShoppingList(+m[1]);
    else if ((m = h.match(/^#\/cook\/(\d+)$/))) await renderCook(+m[1]);
    else if (h === '#/diag') await renderDiag();
    else if (h === '#/logs') await renderLogs();
    else if (h === '#/logs/import') await renderImportLog();
    else if (h === '#/scout') await renderScout();
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

async function ensureTags() {
  if (!state.tags) state.tags = (await api('tags')).tags;
  return state.tags;
}

// ───────────────────────── רשימה וחיפוש ─────────────────────────

async function renderList() {
  view.innerHTML = `
    <section class="toolbar">
      <form id="search" class="search" role="search">
        <input name="q" type="search" placeholder="חיפוש בשם או ברכיב…" autocomplete="off">
        <button class="btn btn--primary" type="submit">חפש</button>
      </form>
      <div class="toolbar__row">
        <a class="btn btn--primary" href="#/new">+ מתכון חדש</a>
        <a class="btn" href="#/import">🌐 ייבוא מהרשת</a>
      </div>
      <a class="btn btn--wide" href="#/pantry">🥕 מה אפשר להכין ממה שיש לי בבית?</a>
      <nav class="subnav subnav--list" aria-label="סינון">
        <a href="#/" class="is-on" data-scope="all">הכול</a>
        <a href="#/" data-scope="web">מהרשת</a>
      </nav>
    </section>
    <section id="results" class="list"></section>`;

  const results = $('#results');
  let scope = 'all';
  const run = async (q) => {
    results.innerHTML = '<p class="muted">טוען…</p>';
    const { recipes } = await api('search', { q, from_web: scope === 'web' });
    if (!recipes.length) {
      results.innerHTML = `<p class="muted">${q ? 'לא נמצא כלום.' : scope === 'web'
        ? 'עדיין לא יובא כלום מהרשת. "🌐 ייבוא מהרשת" — מדביקים קישור למתכון.' : 'עדיין אין מתכונים. זה הרגע.'}</p>`;
      return;
    }
    results.innerHTML = recipes.map((r) => `
      <a class="item" href="#/r/${r.id}">
        ${r.thumb ? `<img class="item__thumb" src="${esc(r.thumb)}" alt="" loading="lazy">` : '<span class="item__thumb item__thumb--empty">🍲</span>'}
        <div class="item__main">
          <strong>${esc(r.title)}</strong>
          <span class="muted">${esc(r.owner_name)}${r.source_name ? ` · 🌐 ${esc(r.source_name)}` : ''}${r.difficulty ? ' · ' + DIFFICULTY[r.difficulty] : ''}${
            r.work_minutes || r.wait_minutes ? ' · ' + minutes((r.work_minutes || 0) + (r.wait_minutes || 0)) : ''}</span>
        </div>
        <span class="badge ${r.is_mine ? 'badge--mine' : 'badge--public'}">${r.is_mine ? 'שלי' : 'ציבורי'}</span>
      </a>`).join('');
  };

  $$('[data-scope]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    scope = a.dataset.scope;
    $$('[data-scope]').forEach((x) => x.classList.toggle('is-on', x === a));
    run($('#search').q.value.trim());
  }));
  $('#search').addEventListener('submit', (e) => { e.preventDefault(); run(e.target.q.value.trim()); });
  await run('');
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
          <a class="link" href="#/">‹ לרשימה</a>
          <h2>${esc(r.title)}</h2>
          <p class="muted">${esc(r.owner_name)} ·
            <span class="badge ${r.is_mine ? 'badge--mine' : 'badge--public'}">${r.visibility === 'public' ? 'ציבורי' : 'פרטי'}</span>
            ${r.updated_at !== r.created_at ? ` · עודכן ${esc(r.updated_at.slice(0, 10))}` : ''}
          </p>
          ${r.source ? `
          <p class="credit">
            🌐 <strong>${r.source.rewritten ? 'מבוסס על המתכון של' : 'מקור:'}</strong> <a href="${esc(r.source.url)}" target="_blank" rel="noopener nofollow">${esc(r.source.name || r.source.url)}</a>${
              r.source.author ? ` · מאת ${esc(r.source.author)}` : ''}${
              r.source.imported_at ? ` · יובא ${esc(r.source.imported_at.slice(0, 10))}` : ''}
            <span class="muted small">— המתכון, התמונות והסרטונים שייכים למקור. הגרסה המקורית והמלאה שם.</span>
          </p>` : ''}
          <div class="meta">
            ${r.difficulty ? `<span>קושי: ${DIFFICULTY[r.difficulty]}</span>` : ''}
            ${r.work_minutes ? `<span>עבודה: ${minutes(r.work_minutes)}</span>` : ''}
            ${r.wait_minutes ? `<span>המתנה: ${minutes(r.wait_minutes)}</span>` : ''}
          </div>
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
          <div class="actions actions--wrap">
            <a class="btn btn--primary" href="#/cook/${r.id}">🍳 מצב בישול</a>
            <button class="btn" id="to-shopping" type="button">🛒 לרשימת קניות</button>
            <button class="btn btn--ghost" id="print" type="button">🖨 הדפסה</button>
          </div>
          <div id="shopping-pick" hidden></div>
          ${r.is_mine ? `<div class="actions">
            <a class="btn" href="#/edit/${r.id}">עריכה</a>
            <button class="btn btn--danger" id="del" type="button">מחיקה</button>
          </div>` : `<div class="actions">
            <button class="btn ${social.isFavorite ? 'btn--primary' : ''}" id="fav" type="button" aria-pressed="${social.isFavorite}">
              ${social.isFavorite ? '♥ שמור אצלי' : '♡ שמור אצלי'}
            </button>
          </div>`}
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
    $('#del')?.addEventListener('click', async () => {
      if (!confirm(`למחוק את "${r.title}"? הפעולה אינה הפיכה.`)) return;
      const { deleted_comments } = await api('recipe-delete', { id: r.id });
      if (deleted_comments) alert(`נמחק, יחד עם ${deleted_comments} תגובות.`);
      go('#/');
    });
    $('#print').addEventListener('click', () => window.print());
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
    $('#toggle-comments')?.addEventListener('click', async () => {
      try { r.comments_open = (await api('recipe-comments-open', { recipe_id: r.id, open: !r.comments_open })).comments_open; drawComments(); }
      catch (err) { fail(err.message); }
    });
  };

  draw();
}

// ───────────────────────── מועדפים ─────────────────────────

async function renderFavorites() {
  const { favorites } = await api('favorites');
  const draw = (items) => {
    view.innerHTML = `
      <section class="toolbar"><a class="link" href="#/">‹ לרשימה</a><h2>המועדפים שלי</h2></section>
      <section class="list">
        ${items.length ? items.map((f) => f.status === 'ok' ? `
          <a class="item" href="#/r/${f.id}">
            ${f.thumb ? `<img class="item__thumb" src="${esc(f.thumb)}" alt="" loading="lazy">` : '<span class="item__thumb item__thumb--empty">🍲</span>'}
            <div class="item__main">
              <strong>${esc(f.title)}</strong>
              <span class="muted">${esc(f.owner_name)}${f.difficulty ? ' · ' + DIFFICULTY[f.difficulty] : ''}${
                f.work_minutes || f.wait_minutes ? ' · ' + minutes((f.work_minutes || 0) + (f.wait_minutes || 0)) : ''}</span>
            </div>
            <span class="badge badge--public">♥</span>
          </a>` : `
          <div class="item item--gone">
            <span class="item__thumb item__thumb--empty">${f.status === 'gone' ? '🗑' : '🔒'}</span>
            <div class="item__main">
              <strong>${esc(f.title)}</strong>
              <span class="muted">${f.status === 'gone' ? 'הוסר על ידי הכותב' : 'המתכון אינו זמין כרגע — הכותב הפך אותו לפרטי'}</span>
            </div>
            <button class="btn btn--ghost btn--danger" type="button" data-unfav="${f.fav_id}" aria-label="הסר מהמועדפים">✕</button>
          </div>`).join('')
        : '<p class="muted">עדיין לא שמרת כלום. במתכון של מישהו אחר יש כפתור ♡ "שמור אצלי".</p>'}
      </section>`;
    $$('[data-unfav]').forEach((b) => b.addEventListener('click', async () => {
      try { draw((await api('favorite-remove', { fav_id: +b.dataset.unfav })).favorites); }
      catch (err) { alert(err.message); }
    }));
  };
  draw(favorites);
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
    source: draft ? { url: draft.source_url, name: draft.source_name, author: draft.source_author, rewritten: false } : (r.source || null),
    snapshot: draft ? draft.snapshot : null,
    import_log_id: draft ? draft.import_log_id || null : null,   // השורה ביומן הייבוא — תקושר למתכון בשמירה
    ai_available: draft ? !!draft.ai_available : null,
    pending_media: draft ? draft.pending_media : null,
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
             : `<p class="muted">תמונות וסרטונים אפשר להוסיף אחרי השמירה הראשונה.</p>`}

        <fieldset class="tags">
          ${Object.entries(AXES).map(([axis, label]) => tags[axis] ? `
            <div class="tags__axis"><span class="muted">${label}</span>
              ${tags[axis].map((t) => `<label class="chip chip--pick">
                <input type="checkbox" data-tag="${t.id}" ${model.tag_ids.has(t.id) ? 'checked' : ''}> ${esc(t.name)}</label>`).join('')}
            </div>` : '').join('')}
        </fieldset>

        <label class="check check--big">
          <input type="checkbox" name="public" ${model.visibility === 'public' ? 'checked' : ''}>
          מתכון ציבורי — כולם רואים, ואפשר להגיב
        </label>
        ${model.source ? '<p class="muted small">מתכון שיובא מהרשת: הקרדיט והקישור למקור מוצגים תמיד. פרסום ציבורי של תוכן שאינו שלך — על אחריותך.</p>' : ''}
        <label class="check">
          <input type="checkbox" name="comments_open" ${model.comments_open ? 'checked' : ''}>
          לאפשר תגובות (במתכון ציבורי)
        </label>

        <button class="btn btn--primary btn--wide" type="submit">${id ? 'שמור שינויים' : 'צור מתכון'}</button>
        <p id="edit-msg" class="note" hidden></p>
      </form>`;

    bindEditor(id);
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
    model.visibility = f.public.checked ? 'public' : 'private';
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

    if (editId) renderMediaEdit(editId);

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
      <div class="media-row">
        <span>${v.source === 'link' ? '🔗 ' + esc(v.url) : '🎬 קובץ · ' + humanBytes(v.bytes)}</span>
        <button class="btn btn--ghost btn--danger" type="button" data-del-media="${v.id}">✕</button>
      </div>`).join('')}
    ${videos.length < limits.max_videos ? `
      <div class="media-add">
        <!-- לא <form>: אזור המדיה יושב בתוך טופס העורך, ודפדפן זורק תג form
             מקונן — כך שדה הקישור הפך לשדה חובה של "שמור שינויים" וחסם אותו. -->
        <div class="media-link" id="media-link">
          <input name="video_url" type="text" inputmode="url" placeholder="קישור ליוטיוב (מומלץ לסרטון ארוך)"
                 autocomplete="off" aria-label="קישור לסרטון">
          <button class="btn" type="button" id="media-link-add">הוסף</button>
        </div>
        <label class="upload">
          <input type="file" accept="video/mp4" hidden data-up="video">
          <span class="btn">+ קובץ mp4</span>
          <small class="muted">עד ${humanBytes(limits.video_max)} — כ-10–15 שניות מהטלפון</small>
        </label>
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
      <h3>📲 אפליקציה בטלפון</h3>
      <div id="install-box" class="install"></div>
    </section>`;
  drawInstall($('#install-box'));
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

const IMPORT_KIND_HE = { preview: 'ייבוא מהעורך', 'scout-preview': 'תצוגה מקדימה בסורק', 'scout-import': 'ייבוא בסורק', refresh: 'משיכה חוזרת', 'editor-save': 'שמירה מהעורך' };

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
      ${r.ok && r.kind !== 'editor-save' ? `<p class="muted small">חולץ ב-${esc(r.method || '?')} · ${r.ingredients_n} רכיבים · ${r.steps_n} שלבים ב-${r.sections_n} חלקים · ${r.images_n} תמונות${r.videos_n ? ` · ${r.videos_n} סרטונים` : ''}</p>` : ''}
      ${r.kind === 'editor-save' ? `<p class="muted small">נשמר אחרי תיקון: ${r.ingredients_n} רכיבים · ${r.steps_n} שלבים ב-${r.sections_n} חלקים</p>` : ''}
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
