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
  if (!data.success) throw new Error(data.error || 'שגיאה לא מזוהה');
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
    else if ((m = h.match(/^#\/edit\/(\d+)$/))) await renderEditor(+m[1]);
    else if (h === '#/favorites') await renderFavorites();
    else if (h === '#/diag') await renderDiag();
    else if (h === '#/logs') await renderLogs();
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
      <a class="btn btn--primary btn--wide" href="#/new">+ מתכון חדש</a>
    </section>
    <section id="results" class="list"></section>`;

  const results = $('#results');
  const run = async (q) => {
    results.innerHTML = '<p class="muted">טוען…</p>';
    const { recipes } = await api('search', { q });
    if (!recipes.length) {
      results.innerHTML = `<p class="muted">${q ? 'לא נמצא כלום.' : 'עדיין אין מתכונים. זה הרגע.'}</p>`;
      return;
    }
    results.innerHTML = recipes.map((r) => `
      <a class="item" href="#/r/${r.id}">
        ${r.thumb ? `<img class="item__thumb" src="${esc(r.thumb)}" alt="" loading="lazy">` : '<span class="item__thumb item__thumb--empty">🍲</span>'}
        <div class="item__main">
          <strong>${esc(r.title)}</strong>
          <span class="muted">${esc(r.owner_name)}${r.difficulty ? ' · ' + DIFFICULTY[r.difficulty] : ''}${
            r.work_minutes || r.wait_minutes ? ' · ' + minutes((r.work_minutes || 0) + (r.wait_minutes || 0)) : ''}</span>
        </div>
        <span class="badge ${r.is_mine ? 'badge--mine' : 'badge--public'}">${r.is_mine ? 'שלי' : 'ציבורי'}</span>
      </a>`).join('');
  };

  $('#search').addEventListener('submit', (e) => { e.preventDefault(); run(e.target.q.value.trim()); });
  await run('');
}

// ───────────────────────── תצוגת מתכון ─────────────────────────

async function renderRecipe(id) {
  const loaded = await api('recipe', { id });
  const r = loaded.recipe;
  let servings = r.servings;
  const social = { comments: loaded.comments, note: loaded.note, isFavorite: loaded.is_favorite };

  const draw = () => {
    const factor = r.servings && servings ? servings / r.servings : 1;
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
            </div>` : ''}
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

        <section class="part note-box" id="note-box"></section>
        ${r.visibility === 'public' ? '<section class="part comments" id="comments"></section>' : ''}
      </article>`;

    drawNote();
    if (r.visibility === 'public') drawComments();

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
    $('#fav')?.addEventListener('click', async () => {
      const b = $('#fav'); b.disabled = true;
      try { social.isFavorite = (await api('favorite-toggle', { recipe_id: r.id })).is_favorite; draw(); }
      catch (err) { alert(err.message); b.disabled = false; }
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

async function renderEditor(id) {
  const tags = await ensureTags();
  let r = id ? (await api('recipe', { id })).recipe : {
    title: '', visibility: 'private', servings: '', difficulty: '', work_minutes: '', wait_minutes: '',
    tips: '', tags: [], sections: [emptySection()], comments_open: true,
  };
  // הטופס עובד על עותק שאפשר לשנות בלי לגעת במה שהגיע מהשרת.
  const model = {
    title: r.title, visibility: r.visibility, servings: r.servings ?? '', difficulty: r.difficulty ?? '',
    work_minutes: r.work_minutes ?? '', wait_minutes: r.wait_minutes ?? '', tips: r.tips ?? '',
    comments_open: r.comments_open !== false,
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
        <h2>${id ? 'עריכת מתכון' : 'מתכון חדש'}</h2>

        <label>שם המתכון <input name="title" value="${esc(model.title)}" required maxlength="120"></label>

        <div class="row">
          <label>מנות <input name="servings" type="number" min="1" inputmode="numeric" value="${esc(model.servings)}"></label>
          <label>קושי <select name="difficulty">
            <option value="">—</option>
            ${Object.entries(DIFFICULTY).map(([k, v]) => `<option value="${k}" ${model.difficulty === k ? 'selected' : ''}>${v}</option>`).join('')}
          </select></label>
        </div>
        <div class="row">
          <label>זמן עבודה (דק׳) <input name="work_minutes" type="number" min="0" inputmode="numeric" value="${esc(model.work_minutes)}"></label>
          <label>זמן המתנה (דק׳) <input name="wait_minutes" type="number" min="0" inputmode="numeric" value="${esc(model.wait_minutes)}"></label>
        </div>

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
    model.servings = f.servings.value;
    model.difficulty = f.difficulty.value;
    model.work_minutes = f.work_minutes.value;
    model.wait_minutes = f.wait_minutes.value;
    model.tips = f.tips.value;
    model.visibility = f.public.checked ? 'public' : 'private';
    model.comments_open = f.comments_open.checked;
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
          servings: model.servings || null, difficulty: model.difficulty || null,
          work_minutes: model.work_minutes || null, wait_minutes: model.wait_minutes || null,
          tips: model.tips, tag_ids: [...model.tag_ids], comments_open: model.comments_open,
          sections: model.sections.map((s) => ({
            name: s.name,
            ingredients: s.ingredients.filter((i) => i.free_text.trim()),
            steps: s.steps.filter((t) => t.trim()).map((t) => ({ text: t })),
          })),
        };
        const { id: savedId } = await api('recipe-save', payload);
        go(`#/r/${savedId}`);
      } catch (err) {
        out.textContent = err.message; out.className = 'note note--err'; out.hidden = false;
        btn.disabled = false;
      }
    });
  };

  draw();
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
    </section>`;
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
    </section>`;
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
          <p class="muted small">לקלוד: גם <code dir="ltr">&amp;format=text</code> — טקסט להדבקה בצ'אט.</p>
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
