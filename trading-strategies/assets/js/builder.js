// בונה השיטות — מנהל בלבד (spec-stage-a 17.2, ✅19; spec-method-builder.md 3–4). עורך ההגדרה (JSON), "✔ בדיקה"
// (תחביר והפניות — בשרת), "🧪 נסה" (על הנרות האחרונים של נכס — לא נשמר), שמירה (חדשה = כבויה; שינוי = גרסה חדשה),
// וגרסאות. בלי polling: כל פנייה לשרת — מלחיצה. כל טקסט — textContent.
//
// ההגדרה נכתבת כ-JSON. בונה חזותי של תנאים (spec-method-builder.md 4) — עוד לא; ראו README "4ב/3".

const TF_LABEL = { m1: 'דקה', m5: '5 ד׳', m15: '15 ד׳', m30: '30 ד׳', h1: 'שעה', h4: '4 ש׳', d1: 'יום', w1: 'שבוע' };

/** תבנית להתחלה — RSI עם רמות; עובדת כמו שהיא */
export const TEMPLATE = {
  id: 'rsi-levels', name: 'RSI עם רמות', source: 'הגדרה של המנהל',
  markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'],
  params: {
    len: { label: 'אורך RSI', default: 14, min: 2, max: 50, int: true },
    lo: { label: 'רמה תחתונה', default: 30, min: 5, max: 50 },
    hi: { label: 'רמה עליונה', default: 70, min: 50, max: 95 },
  },
  indicators: [
    { id: 'rsi', type: 'rsi', source: 'close', length: '$len' },
    { id: 'atr', type: 'atr', length: 14 },
  ],
  rules: { long: { all: ['rsi crosses_above $lo'] }, short: { all: ['rsi crosses_below $hi'] } },
  levels: { stop: 'entry - dir * 2 * atr', target: 'entry + dir * 3 * atr' },
  horizon_bars: 30,
  validate: [{ expr: '$lo < $hi', message: 'הרמה התחתונה חייבת להיות מתחת לעליונה' }],
};

/** העורך. def — הגדרה קיימת (עריכה) או null (חדשה); onSaved — אחרי שמירה */
export function openBuilder(ctx, def, onSaved) {
  const { h, api, sheet, toast } = ctx;
  const editing = !!def;
  const text = h('textarea', { class: 'code-edit', rows: 18, dir: 'ltr', spellcheck: 'false', autocapitalize: 'off', 'aria-label': 'ההגדרה (JSON)' });
  text.value = JSON.stringify(def ?? TEMPLATE, null, 2);
  const out = h('div', { class: 'builder-out', 'aria-live': 'polite' });
  text.addEventListener('input', () => out.replaceChildren());   // תוצאה ישנה לא נשארת ליד טקסט שהשתנה
  const asset = h('input', { type: 'text', value: 'binance:BTCUSDT', dir: 'ltr', 'aria-label': 'נכס לניסיון', autocapitalize: 'off' });
  const tf = h('select', { 'aria-label': 'מסגרת זמן לניסיון' }, Object.entries(TF_LABEL).map(([k, l]) => h('option', { value: k, selected: k === 'h1' }, l)));

  const read = () => {
    try { return JSON.parse(text.value); } catch (e) { throw new Error('JSON לא תקין: ' + e.message); }
  };
  const busy = async (btn, fn) => {
    btn.disabled = true;
    try { await fn(); } catch (e) { out.replaceChildren(h('p', { class: 'error', role: 'alert' }, e.message)); } finally { btn.disabled = false; }
  };
  const check = h('button', { class: 'btn ghost sm', type: 'button', on: { click: e => busy(e.target, async () => {
    const r = await api('admin_method_validate', { definition: read() });
    out.replaceChildren(h('p', { class: 'ok' }, `✔ תקין — ${r.method.name} (${r.method.id}) · ${Object.keys(r.method.params).length} פרמטרים · אופק ${r.method.horizon_bars} נרות`));
  }) } }, '✔ בדיקה');
  const tryIt = h('button', { class: 'btn ghost sm', type: 'button', on: { click: e => busy(e.target, async () => {
    const r = await api('admin_method_dry_run', { definition: read(), asset: asset.value.trim(), tf: tf.value });
    const side = { long: 'לונג', short: 'שורט', none: 'אין כיוון' };
    out.replaceChildren(
      h('p', {}, h('b', {}, `🧪 ${r.signals_total} אותות ב-${r.candles.toLocaleString('he-IL')} נרות`), ` · עכשיו: ${side[r.bias]}`),
      h('p', { class: 'hint' }, r.note),
      h('ul', { class: 'conds' }, r.conditions.map(c => h('li', { class: c.met ? 'met' : '' }, h('span', { 'aria-hidden': 'true' }, c.met ? '✓' : '○'),
        h('span', { dir: 'ltr' }, c.text), h('small', { class: 'muted' }, side[c.side])))),
      r.signals.length ? h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, ['זמן', 'צד', 'כניסה', 'סטופ', 'יציאה'].map(x => h('th', {}, x)))),
        h('tbody', {}, r.signals.slice().reverse().map(s => h('tr', {}, h('td', {}, new Date(s.t * 1000).toISOString().slice(0, 16).replace('T', ' ')),
          h('td', {}, side[s.side]), h('td', { class: 'num' }, s.entry), h('td', { class: 'num' }, s.stop), h('td', { class: 'num' }, s.target ?? '—'))))) : null);
  }) } }, '🧪 נסה');

  const s = sheet(editing ? '🧩 עריכת שיטה מוגדרת' : '🧩 שיטה מוגדרת חדשה', { saveLabel: 'שמירה', onSave: async () => {
    const r = await api('admin_method_save', { definition: read() });
    const v = r.saved;
    toast(v.created ? `נוצרה — גרסה 1, כבויה עד הפעלה` : v.changed ? `נשמר — גרסה ${v.version}` : 'אין שינוי בהגדרה');
    onSaved?.();
  } });
  s.body.append(
    h('p', { class: 'hint' }, 'ביטויים בשפה סגורה: מספרים, אינדיקטורים (sma, ema, rsi, atr), מחירים (open, high, low, close), $פרמטר, + − × ÷, השוואות, crosses_above / crosses_below, and / or. ברמות — גם entry ו-dir (+1 לונג, −1 שורט). בלי קוד חופשי.'),
    h('p', { class: 'hint' }, 'שיטה חדשה — כבויה עד שמפעילים אותה, ומסומנת "מוגדרת ידנית" עד שיש לה בדיקה לאחור עם 30 אותות לפחות. שמירה ששינתה את ההגדרה — גרסה חדשה, והבדיקות לאחור מחושבות מחדש. נפח — עוד לא נתמך.'),
    text,
    h('div', { class: 'toolbar wrap' }, check, asset, tf, tryIt),
    out);
  return s;
}

/** היסטוריית הגרסאות; "לעורך" — פותח את הגרסה בעורך (שמירה שלה = גרסה חדשה) */
export async function openVersions(ctx, id, onSaved) {
  const { h, api, sheet, toast } = ctx;
  let versions;
  try { ({ versions } = await api('admin_method_versions', null, { id })); } catch (e) { toast(e.message, true); return; }
  const s = sheet('🕘 גרסאות — ' + id);
  s.body.append(h('ul', { class: 'list' }, versions.map(v => h('li', { class: 'card', style: 'cursor:default' },
    h('div', { class: 'l1' }, h('span', {}, 'גרסה ' + v.version), h('span', { class: 't' }, new Date(v.saved_at * 1000).toLocaleString('he-IL'))),
    h('p', { class: 'hint', style: 'margin:0' }, v.saved_by ? 'נשמרה על ידי ' + v.saved_by : ''),
    h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => { s.close(); openBuilder(ctx, v.definition, onSaved); } } }, 'לעורך')))));
}
