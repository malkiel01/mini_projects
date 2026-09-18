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
    const { user } = await api('login', { username: f.username.value, password: f.password.value });
    f.reset();
    setUser(user);
    go('#/');
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
  $('#nav-diag').hidden = !(on && user.role === 'admin');
  if (on) $('#who').textContent = user.display_name || user.username;
}

// ───────────────────────── ניתוב ─────────────────────────

const view = $('#view');
const go = (hash) => { location.hash = hash; };

async function route() {
  if (!state.user) return;
  const h = location.hash || '#/';
  let m;
  try {
    if (h === '#/' || h === '') await renderList();
    else if ((m = h.match(/^#\/r\/(\d+)$/))) await renderRecipe(+m[1]);
    else if (h === '#/new') await renderEditor(null);
    else if ((m = h.match(/^#\/edit\/(\d+)$/))) await renderEditor(+m[1]);
    else if (h === '#/diag') await renderDiag();
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
  const { recipe: r } = await api('recipe', { id });
  let servings = r.servings;

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
          </div>` : ''}
        </header>

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
      </article>`;

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
  };
  draw();
}

// ───────────────────────── עורך ─────────────────────────

const emptyIng = () => ({ free_text: '', amount_min: '', amount_max: '', unit: '', product: '', optional: false });
const emptySection = () => ({ name: '', ingredients: [emptyIng()], steps: [''] });

async function renderEditor(id) {
  const tags = await ensureTags();
  let r = id ? (await api('recipe', { id })).recipe : {
    title: '', visibility: 'private', servings: '', difficulty: '', work_minutes: '', wait_minutes: '',
    tips: '', tags: [], sections: [emptySection()],
  };
  // הטופס עובד על עותק שאפשר לשנות בלי לגעת במה שהגיע מהשרת.
  const model = {
    title: r.title, visibility: r.visibility, servings: r.servings ?? '', difficulty: r.difficulty ?? '',
    work_minutes: r.work_minutes ?? '', wait_minutes: r.wait_minutes ?? '', tips: r.tips ?? '',
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
                <button class="btn btn--ghost btn--danger" type="button" data-del-section="${si}" title="הסר חלק">✕</button>
              </div>` : ''}

              <h4>רכיבים</h4>
              ${s.ingredients.map((ing, ii) => `
                <div class="ing-row" data-si="${si}" data-ii="${ii}">
                  <input class="ing-free" placeholder="כפי שקוראים: 2 כוסות קמח" value="${esc(ing.free_text)}" data-f="free_text">
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
                  <span class="step-no">${ki + 1}</span>
                  <textarea rows="2" placeholder="מה עושים בשלב הזה" data-f="step">${esc(st)}</textarea>
                  <button class="btn btn--ghost btn--danger" type="button" data-del-step="${si}:${ki}" title="הסר">✕</button>
                </div>`).join('')}
              <button class="btn btn--ghost" type="button" data-add-step="${si}">+ שלב</button>
            </div>`).join('')}
          <button class="btn" type="button" id="add-section">+ חלק נוסף (בצק / מלית / זיגוג)</button>
        </fieldset>
        <datalist id="products">${state.products.map((p) => `<option value="${esc(p)}">`).join('')}</datalist>

        <label>טיפים והערות <textarea name="tips" rows="3">${esc(model.tips)}</textarea></label>

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
          tips: model.tips, tag_ids: [...model.tag_ids],
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

// ───────────────────────── אבחון ─────────────────────────

async function renderDiag() {
  const { diag: d } = await api('diag');
  const yes = (b) => (b === true ? '✅' : b === false ? '❌' : '—');
  const rows = (obj, fmt = (v) => esc(v)) =>
    Object.entries(obj).map(([k, v]) => `<tr><th>${esc(k)}</th><td>${fmt(v)}</td></tr>`).join('');
  const boolRows = (obj) => rows(obj, (v) => (typeof v === 'boolean' ? yes(v) : esc(v)));

  view.innerHTML = `
    <section class="card diag">
      <a class="link" href="#/">‹ לרשימה</a>
      <h2>אבחון — מה השרת אומר על עצמו</h2>

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
  .then(({ user }) => { setUser(user); if (user) route(); })
  .catch(() => { setUser(null); say('לא הצלחתי להגיע לשרת. יש לרענן את הדף.', 'err'); });
