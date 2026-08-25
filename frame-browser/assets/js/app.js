/**
 * דפדפן משובץ.
 *
 * מדביקים כתובת, והיא נפתחת בתוך הדף. אפשר כמה במקביל.
 *
 * הדבר שמפריד את זה מ-iframe שכתבתם בעצמכם: לפני ההטמעה השרת שואל את
 * האתר אם הוא בכלל מרשה. ‏iframe שנחסם אינו מדווח דבר — הדפדפן פשוט מסרב
 * לצייר, ונשאר מלבן ריק שלא אומר אם האתר איטי, הכתובת שגויה, או שהאתר
 * חוסם. כאן התשובה מגיעה לפני שהמסגרת בכלל נוצרת.
 */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

const STORE = { saved: 'fb.saved', history: 'fb.history', panes: 'fb.panes', cols: 'fb.cols' };
const MAX_HISTORY = 20;
const LOAD_TIMEOUT = 15000;

const state = { panes: [], cols: 1 };
let nextId = 1;

/* ── עזרים ─────────────────────────────────────────────────────── */

function el(tag, props = {}, children = []) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'text') n.textContent = v;
    else if (k === 'class') n.className = v;
    else if (k === 'dataset') Object.assign(n.dataset, v);
    else if (k.startsWith('on')) n.addEventListener(k.slice(2).toLowerCase(), v);
    else n.setAttribute(k, v === true ? '' : v);
  }
  for (const c of [].concat(children)) if (c) n.append(c);
  return n;
}

function toast(msg, kind = '') {
  const host = $('#toasts');
  while (host.children.length >= 3) host.firstElementChild.remove();
  const n = el('div', { class: `toast${kind ? ` toast--${kind}` : ''}`, text: msg });
  host.append(n);
  setTimeout(() => n.remove(), kind === 'error' ? 5000 : 2600);
}

/** אחסון מקומי נופל בגלישה פרטית ובחסימת אתרים — לא סיבה להפיל את הדף. */
function load(key, fallback) {
  try { return JSON.parse(localStorage.getItem(key)) ?? fallback; }
  catch { return fallback; }
}
function save(key, value) {
  try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* אין מקום, ניחא */ }
}

/** משלים סכימה ומנקה. מה שהמשתמש מדביק לא תמיד כתובת מלאה. */
function normalize(raw) {
  const url = raw.trim();
  if (!url) return '';
  return /^https?:\/\//i.test(url) ? url : `https://${url}`;
}

function hostOf(url) {
  try { return new URL(url).host; } catch { return url; }
}

/* ── מסגרות ────────────────────────────────────────────────────── */

function addPane(url = '') {
  const pane = { id: nextId++, url, status: url ? 'checking' : 'empty', title: '', reason: '' };
  state.panes.push(pane);
  render();
  if (url) inspect(pane);
  return pane;
}

/**
 * משלים מסגרות ריקות עד למספר שהפריסה דורשת.
 *
 * פריסה של ארבע עם שלוש מסגרות נראית שבורה, וזה קורה גם בהחלפת פריסה
 * וגם בטעינה מחדש — מסגרת ריקה אינה נשמרת, ולכן אין מה לשחזר.
 */
function ensurePanes() {
  while (state.panes.length < state.cols) {
    state.panes.push({ id: nextId++, url: '', status: 'empty', title: '', reason: '' });
  }
}

function closePane(id) {
  state.panes = state.panes.filter((p) => p.id !== id);
  if (!state.panes.length) addPane();
  render();
  persist();
}

function setPane(id, patch) {
  const pane = state.panes.find((p) => p.id === id);
  if (!pane) return;
  Object.assign(pane, patch);
  render();
}

/** שואל את השרת אם הכתובת ניתנת להטמעה, ורק אז יוצר את המסגרת. */
async function inspect(pane) {
  setPane(pane.id, { status: 'checking', reason: '', title: '' });

  try {
    const res = await fetch(`./api.php?url=${encodeURIComponent(pane.url)}`);
    const data = await res.json();

    if (!data.success) return setPane(pane.id, { status: 'error', reason: data.error });

    // הפניה מחליפה את הכתובת: עדיף שהשורה תראה לאן באמת הגענו.
    const patch = { title: data.title || '', url: data.url || pane.url };

    if (data.framable) setPane(pane.id, { ...patch, status: 'ok' });
    else setPane(pane.id, { ...patch, status: 'blocked', reason: data.reason });

    remember(pane.url, data.title);
  } catch (ex) {
    setPane(pane.id, { status: 'error', reason: `הבדיקה נכשלה: ${ex.message}` });
  }
}

/* ── תצוגה ─────────────────────────────────────────────────────── */

function paneToolbar(pane) {
  const addr = el('input', {
    type: 'text', inputmode: 'url', class: 'pane__addr', dir: 'ltr',
    value: pane.url, spellcheck: 'false',
    placeholder: 'כתובת…',
    onkeydown: (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const url = normalize(e.target.value);
      if (url) { pane.url = url; inspect(pane); persist(); }
    },
  });

  const tools = [
    el('button', { type: 'button', class: 'icon-btn', title: 'טעינה מחדש',
      onclick: () => pane.url && inspect(pane) }, ['⟳']),
    el('a', { class: 'icon-btn', href: pane.url || '#', target: '_blank', rel: 'noopener noreferrer',
      title: 'פתיחה בלשונית חדשה' }, ['↗']),
    el('button', { type: 'button', class: 'icon-btn', title: 'שמירה',
      onclick: () => { if (pane.url) { addSaved(pane.url, pane.title); toast('נשמר', 'ok'); } } }, ['★']),
    el('button', { type: 'button', class: 'icon-btn', title: 'סגירה',
      onclick: () => closePane(pane.id) }, ['✕']),
  ];

  return el('div', { class: 'pane__bar' }, [addr, el('div', { class: 'pane__tools' }, tools)]);
}

/**
 * מה שמוצג בתוך המסגרת בכל מצב.
 *
 * מצב חסום אינו הודעת שגיאה אלא מסך עם מוצא: הסיבה, ולחצן שפותח את
 * הדף בלשונית — שם הוא כן ייפתח.
 */
function paneBody(pane) {
  if (pane.status === 'empty') {
    return el('div', { class: 'pane__note' }, [
      el('p', { text: 'הדביקו כתובת בשורה שמעל, או בחרו קישור שמור.' }),
    ]);
  }

  if (pane.status === 'checking') {
    return el('div', { class: 'pane__note' }, [
      el('div', { class: 'spinner' }),
      el('p', { text: `בודק אם ${hostOf(pane.url)} מרשה הטמעה…` }),
    ]);
  }

  if (pane.status === 'blocked' || pane.status === 'error') {
    const isBlocked = pane.status === 'blocked';
    return el('div', { class: 'pane__note pane__note--stop' }, [
      el('div', { class: 'pane__icon', text: isBlocked ? '⛔' : '⚠' }),
      el('h3', { text: isBlocked ? `${hostOf(pane.url)} אינו מרשה הטמעה` : 'לא הצלחתי לבדוק' }),
      el('p', { text: pane.reason }),
      el('a', { class: 'btn btn--primary', href: pane.url, target: '_blank', rel: 'noopener noreferrer',
        text: 'פתיחה בלשונית חדשה' }),
      isBlocked ? el('p', { class: 'hint', text: 'זו החלטה של האתר, ולא משהו שאפשר לעקוף מכאן.' }) : null,
    ]);
  }

  /*
   * ‏sandbox מגביל את מה שהדף המוטמע רשאי לעשות. allow-same-origin נשאר
   * כי בלעדיו אתרים רבים לא מתפקדים (עוגיות ואחסון), והוא בטוח כאן:
   * הסכנה שבצירוף allow-scripts היא רק כשמטמיעים תוכן מהאתר שלנו עצמו.
   * allow-top-navigation לא ניתן בכוונה — דף מוטמע לא יחטוף את הלשונית.
   */
  const frame = el('iframe', {
    class: 'pane__frame',
    src: pane.url,
    referrerpolicy: 'no-referrer-when-downgrade',
    sandbox: 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox',
    allow: 'fullscreen; clipboard-write',
    loading: 'lazy',
  });

  // האתר אישר הטמעה ובכל זאת לא נטען — קורה כשיש חסימה בשכבת ה-JS.
  const timer = setTimeout(() => {
    if (!frame.dataset.loaded) {
      setPane(pane.id, { status: 'error', reason: 'האתר אישר הטמעה אך הדף לא נטען בזמן סביר' });
    }
  }, LOAD_TIMEOUT);
  frame.addEventListener('load', () => { frame.dataset.loaded = '1'; clearTimeout(timer); });

  return frame;
}

function render() {
  $('#grid').dataset.cols = String(state.cols);
  $('#grid').replaceChildren(...state.panes.map((pane) => {
    const title = pane.title && pane.status === 'ok' ? pane.title : '';
    return el('section', { class: `pane pane--${pane.status}` }, [
      paneToolbar(pane),
      title ? el('div', { class: 'pane__title', text: title }) : null,
      paneBody(pane),
    ]);
  }));
  renderQuick();
}

/* ── שמורים והיסטוריה ──────────────────────────────────────────── */

function addSaved(url, name = '') {
  const saved = load(STORE.saved, []);
  if (saved.some((s) => s.url === url)) return;
  saved.unshift({ url, name: name || hostOf(url) });
  save(STORE.saved, saved.slice(0, 60));
  renderQuick();
  renderSaved();
}

function remember(url, title) {
  const history = load(STORE.history, []).filter((h) => h.url !== url);
  history.unshift({ url, name: title || hostOf(url), at: Date.now() });
  save(STORE.history, history.slice(0, MAX_HISTORY));
}

function open(url) {
  // המסגרת הריקה הראשונה, ואם אין — חדשה.
  const target = state.panes.find((p) => p.status === 'empty');
  if (target) { target.url = url; inspect(target); }
  else addPane(url);
  persist();
}

function renderQuick() {
  const saved = load(STORE.saved, []);
  const recent = load(STORE.history, []).filter((h) => !saved.some((s) => s.url === h.url));

  $('#quick').replaceChildren(
    ...saved.slice(0, 8).map((s) => el('button', {
      type: 'button', class: 'chip chip--saved', title: s.url,
      text: `★ ${s.name}`, onclick: () => open(s.url),
    })),
    ...recent.slice(0, 6).map((h) => el('button', {
      type: 'button', class: 'chip', title: h.url,
      text: h.name, onclick: () => open(h.url),
    })),
  );
}

function renderSaved() {
  const saved = load(STORE.saved, []);
  $('#savedList').replaceChildren(...(saved.length ? saved.map((s, i) => el('div', { class: 'saverow' }, [
    el('button', { type: 'button', class: 'saverow__open', onclick: () => { open(s.url); $('#saved').close(); } }, [
      el('b', { text: s.name }),
      el('span', { class: 'hint', dir: 'ltr', text: s.url }),
    ]),
    el('button', { type: 'button', class: 'icon-btn', title: 'מחיקה', onclick: () => {
      const list = load(STORE.saved, []);
      list.splice(i, 1);
      save(STORE.saved, list);
      renderSaved(); renderQuick();
    } }, ['✕']),
  ])) : [el('p', { class: 'hint', text: 'אין עדיין קישורים שמורים.' })]));

  const history = load(STORE.history, []);
  $('#historyList').replaceChildren(...(history.length ? history.map((h) => el('div', { class: 'saverow' }, [
    el('button', { type: 'button', class: 'saverow__open', onclick: () => { open(h.url); $('#saved').close(); } }, [
      el('b', { text: h.name }),
      el('span', { class: 'hint', dir: 'ltr', text: h.url }),
    ]),
    el('button', { type: 'button', class: 'icon-btn', title: 'שמירה',
      onclick: () => { addSaved(h.url, h.name); toast('נשמר', 'ok'); } }, ['★']),
  ])) : [el('p', { class: 'hint', text: 'עוד לא נפתחו כתובות.' })]));
}

/* ── שמירת מצב ─────────────────────────────────────────────────── */

function persist() {
  save(STORE.panes, state.panes.map((p) => p.url).filter(Boolean));
  save(STORE.cols, state.cols);
}

/* ── אירועים ───────────────────────────────────────────────────── */

$('#omniForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const url = normalize($('#omni').value);
  if (!url) return;
  open(url);
  $('#omni').value = '';
});

$('#layoutSeg').addEventListener('click', (e) => {
  const btn = e.target.closest('.seg__btn');
  if (!btn) return;
  $$('.seg__btn').forEach((b) => b.classList.toggle('is-active', b === btn));
  state.cols = Number(btn.dataset.cols);

  ensurePanes();
  render();
  persist();
});

$('#savedBtn').addEventListener('click', () => { renderSaved(); $('#saved').showModal(); });
$('#helpBtn').addEventListener('click', () => $('#help').showModal());
$$('[data-close]').forEach((b) => b.addEventListener('click', () => $(`#${b.dataset.close}`).close()));

$('#saveForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const url = normalize($('#sUrl').value);
  if (!url) return;
  addSaved(url, $('#sName').value.trim());
  $('#sUrl').value = ''; $('#sName').value = '';
  toast('נשמר', 'ok');
});

$('#clearHistory').addEventListener('click', () => {
  save(STORE.history, []);
  renderSaved(); renderQuick();
  toast('ההיסטוריה נוקתה', 'ok');
});

/* ── פתיחה ─────────────────────────────────────────────────────── */

state.cols = load(STORE.cols, 1);
$$('.seg__btn').forEach((b) => b.classList.toggle('is-active', Number(b.dataset.cols) === state.cols));

const restored = load(STORE.panes, []);
restored.forEach((url) => addPane(url));
ensurePanes();
if (!state.panes.length) addPane();

// כתובת בשורת הכתובת של הדף עצמו: ?url=... פותחת אותה מיד.
const wanted = new URLSearchParams(location.search).get('url');
if (wanted) open(normalize(wanted));

render();
