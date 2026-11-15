// בחירת נכס מרשימה — combobox (spec-stage-a 18.1.3, S1.2). רכיב אחד לשוק, להתראה חדשה וליומן.
// הטקסט שמוקלד רק מסנן: אין כפתור "חיפוש", ו-Enter בלי פריט מסומן לא עושה כלום. רק בחירה של פריט פותחת משהו.
// סדר הסינון: קודם הקטלוג (מקומי, בלי בקשה), ורק כשאין בו התאמה — הספק (market_search), מ-2 תווים, אחרי 300ms
// מההקשה האחרונה; תשובה שהגיעה אחרי שהטקסט השתנה — נזרקת.

const COMMON = ['BTC/USDT', 'ETH/USDT', 'SOL/USDT', 'XRP/USDT', 'USD/ILS', 'EUR/USD', 'EUR/ILS', 'GBP/USD'];   // [שלנו] כשהשדה ריק
const DEBOUNCE = 300;
const MIN_REMOTE = 2;
const LIMIT = 40;
let CAT = null;   // הקטלוג — פעם אחת לטעינת דף (נמחק כשמחברים או מנתקים ספק: invalidateCatalog)
let seqId = 0;

export const invalidateCatalog = () => { CAT = null; };

/** הקטלוג (api pairs) כרשימה שטוחה: לכל זוג — הנכס, התווית ומילות חיפוש (קוד, עברית, אנגלית) */
function catalog(api) {
  CAT ??= api('pairs').then(r => r.bases.flatMap(b => b.quotes.map(q => ({
    asset: q.asset, pair: q.pair, label: q.label, name: q.name, base: q.base, quote: q.quote, computed: q.computed,
    market: b.kind === 'crypto' ? 'crypto' : 'forex',
    words: { base: words(b.code, b.name, b.en), quote: words(q.code, q.name, q.en) },
  })))).catch(e => { CAT = null; throw e; });
  return CAT;
}
const norm = s => String(s ?? '').toLowerCase().replace(/[׳']/g, "'").trim();
const words = (...xs) => [...new Set(xs.flatMap(x => [norm(x), ...norm(x).split(/[\s()]+/)]).filter(Boolean))];

/**
 * כל מילה שהוקלדה היא התחלה של מילה באחד הצדדים. "ביט" → ביטקוין; "btc usdt", "דולר שקל" — שני הצדדים.
 * מחזיר דירוג (0 — הבסיס התאים, 1 — רק הצד השני) או -1.
 */
function score(item, tokens) {
  const all = [...item.words.base, ...item.words.quote];
  if (!tokens.every(t => all.some(w => w.startsWith(t)))) return -1;
  return tokens.some(t => item.words.base.some(w => w.startsWith(t))) ? 0 : 1;
}

/**
 * mountCombo(ctx, opts) → { el, input, clear() }.
 * opts.onPick(item) — פריט נבחר ({asset, label, base, quote, ...}); opts.recent() — המעקב (מוצג כשהשדה ריק);
 * opts.label — שם הרכיב לקורא מסך; opts.keep — אחרי בחירה הטקסט נשאר (תווית הפריט), אחרת מתרוקן.
 */
export function mountCombo(ctx, { onPick, recent = () => [], label = 'בחירת נכס', placeholder = 'ביטקוין, דולר, אפל…', keep = false, name = 'asset-q' } = {}) {
  const { h, api, pairLabel } = ctx;
  const id = 'cb' + (++seqId);
  const input = h('input', { type: 'text', name, role: 'combobox', 'aria-label': label, 'aria-expanded': 'false', 'aria-controls': id + '-list',
    'aria-autocomplete': 'list', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', enterkeyhint: 'search', maxlength: 40, placeholder });
  const list = h('ul', { class: 'combo-list', role: 'listbox', id: id + '-list', 'aria-label': label });
  const msg = h('p', { class: 'combo-msg', role: 'status', 'aria-live': 'polite' });
  const pop = h('div', { class: 'combo-pop', hidden: true }, list, msg);
  const el = h('div', { class: 'combo' }, input, pop);
  let items = [];
  let active = -1;
  let committed = '';   // הטקסט לפני הפתיחה — Esc מחזיר אותו
  let seq = 0, timer = null;

  const isOpen = () => !pop.hidden;
  function open() {
    if (isOpen()) return;
    committed = input.value;
    pop.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }
  function close() {
    pop.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    active = -1;
    clearTimeout(timer);
    seq++;   // תשובה שבדרך — נזרקת
  }
  function setActive(i) {
    active = i;
    [...list.children].forEach((li, k) => li.setAttribute('aria-selected', String(k === i)));
    if (i < 0) { input.removeAttribute('aria-activedescendant'); return; }
    const li = list.children[i];
    input.setAttribute('aria-activedescendant', li.id);
    li.scrollIntoView({ block: 'nearest' });
  }
  function show(xs, text = '') {
    items = xs.slice(0, LIMIT);
    list.replaceChildren(...items.map((it, i) => h('li', { role: 'option', id: `${id}-o${i}`, 'aria-selected': 'false', class: 'combo-opt',
      on: { mousedown: e => e.preventDefault(), click: () => pick(i) } },
      pairLabel(it), it.computed ? h('span', { class: 'chip' }, 'מחושב') : null,
      it.provName ? h('small', { class: 't' }, it.provName) : null)));
    list.hidden = !items.length;
    msg.textContent = text;
    msg.hidden = !text;
    active = -1;
    input.removeAttribute('aria-activedescendant');
  }
  function pick(i) {
    const it = items[i];
    if (!it) return;
    input.value = keep ? it.label.replace(/\s*⇄\s*/, ' ⇄ ') : '';
    committed = input.value;
    close();
    onPick?.(it);
  }

  async function update() {
    const text = input.value;
    const t = norm(text);
    const my = ++seq;
    clearTimeout(timer);
    let cat = [];
    try { cat = await catalog(api); } catch (e) { if (my === seq) show([], e.message); return; }
    if (my !== seq) return;
    if (!t) {
      // שדה ריק: המעקב, ואחריו הנפוצים מהקטלוג
      const rec = (recent() || []).filter(w => w.asset).map(w => ({ ...w }));
      const have = new Set(rec.map(x => x.asset));
      show([...rec, ...COMMON.map(k => cat.find(x => x.pair === k)).filter(x => x && !have.has(x.asset))]);
      return;
    }
    const tokens = t.split(/[\s/⇄]+/).filter(Boolean);
    const local = cat.map((x, i) => [score(x, tokens), i, x]).filter(([s]) => s >= 0).sort((a, b) => a[0] - b[0] || a[1] - b[1]).map(x => x[2]);
    if (local.length) { show(local); return; }   // הקטלוג ענה — בלי בקשה לספק
    if (t.length < MIN_REMOTE) { show([], 'לא נמצא'); return; }
    show([], 'מחפש…');
    timer = setTimeout(async () => {
      try {
        const r = await api('market_search', null, { q: text.trim() });
        if (my !== seq || input.value !== text) return;   // הטקסט השתנה בינתיים
        const xs = r.results.map(x => ({ ...x, provName: x.exchange || '' }));
        show(xs, xs.length ? '' : (r.errors?.[0] || 'לא נמצא'));
      } catch (e) {
        if (my !== seq) return;
        show([], e.status === 429 ? 'יותר מדי חיפושים בדקה — נסו שוב עוד רגע' : 'לא נמצא');
      }
    }, DEBOUNCE);
  }

  input.addEventListener('focus', () => {
    open(); update();
    // בטלפון: השדה עולה לראש המסך, כדי שהמקלדת לא תכסה את הפריט הראשון
    if (window.matchMedia('(max-width: 700px)').matches) setTimeout(() => el.scrollIntoView({ block: 'start', behavior: 'smooth' }), 250);
  });
  input.addEventListener('input', () => { open(); update(); });
  input.addEventListener('blur', () => close());
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!isOpen()) { open(); update(); return; }
      if (items.length) setActive(Math.min(active + 1, items.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (items.length && active > 0) setActive(active - 1);
    } else if (e.key === 'Enter') {
      e.preventDefault();   // אין שליחה של טקסט חופשי — רק פריט מסומן
      if (isOpen() && active >= 0) pick(active);
    } else if (e.key === 'Escape') {
      if (!isOpen()) return;
      e.preventDefault();
      e.stopPropagation();   // בתוך חלון — סוגר את הרשימה, לא את החלון
      input.value = committed;
      close();
    }
  });
  return { el, input, clear: () => { input.value = ''; committed = ''; } };
}

/**
 * שדה נכס בטופס (התראה חדשה): הנכס שנבחר — בתווית של S1.1, ומתחתיו ה-combobox להחלפה. .value — הנכס; change — כשהוחלף.
 * labels[value] — התווית של הנכס ההתחלתי (pair_labels).
 */
export function assetField(ctx, { value = null, labels = {}, recent = () => [], label = 'נכס' } = {}) {
  const { h, pairLabel } = ctx;
  let cur = value;
  const shown = h('div', { class: 'asset-chosen', 'aria-live': 'polite' });
  const draw = lab => shown.replaceChildren(cur ? pairLabel(lab || { label: 'נכס' }) : h('span', { class: 'muted' }, 'עוד לא נבחר נכס'));
  const box = h('div', { class: 'asset-field', role: 'group', 'aria-label': label }, shown);
  const combo = mountCombo(ctx, { label: cur ? 'החלפת נכס' : 'בחירת נכס', placeholder: cur ? 'החלפה: ביטקוין, דולר, אפל…' : 'ביטקוין, דולר, אפל…', recent,
    onPick: it => { cur = it.asset; draw(it); box.dispatchEvent(new Event('change')); } });
  box.append(combo.el);
  draw(labels[value]);
  Object.defineProperty(box, 'value', { get: () => cur });
  return box;
}
