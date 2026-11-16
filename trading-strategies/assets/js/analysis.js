// הפרופיל וניהול השיטות — spec-stage-a פרקים 5–6. כרטיס הניתוח עבר לפס שמתחת לגרף (card.js, forecast.js — S4, 18.4);
// מסך "🧭 ניתוח" הנפרד הוסר (2.15). כל טקסט — textContent.
import { TF_LABEL } from './card.js';
import { assetField } from './combobox.js';

const HORIZON = [['short', 'קצר (דקות–שעות)'], ['medium', 'בינוני (ימים–שבועות)'], ['long', 'ארוך (חודשים+)']];
const RISK_LEVELS = [['low', 'נמוכה'], ['medium', 'בינונית'], ['high', 'גבוהה']];
const MARKET_LABEL = { stocks: 'מניות', crypto: 'קריפטו', forex: 'מט"ח', futures: 'חוזים', options: 'אופציות', indices: 'מדדים', commodities: 'סחורות' };

export function mountAnalysis(ctx) {
  const { h, api, sheet, toast, setHead, render, field, numInput, seg, segVal, select, sep } = ctx;
  const ME = () => ctx.me();

  // ── פרופיל (5) + ההגדרות של שלב 1 — מסך אחד ─────────────
  async function viewProfile() {
    setHead('פרופיל והגדרות', '', { back: true });
    const [{ profile: p }, { methods }] = await Promise.all([api('profile'), api('methods')]);
    const u = ME().user;
    const f = {
      base_currency: h('input', { name: 'base_currency', value: u.base_currency, maxlength: 6, dir: 'ltr' }),
      timezone: select('timezone', (() => { try { return Intl.supportedValuesOf('timeZone'); } catch { return ['Asia/Jerusalem', 'UTC']; } })().map(z => [z, z]), u.timezone),
      account_size: numInput('account_size', u.account_size, 'לחישוב גודל פוזיציה'),
      risk_pct: numInput('risk_pct', p.risk_pct),
      min_rr: numInput('min_rr', p.min_rr),
      max_alerts_per_day: h('input', { name: 'max_alerts_per_day', type: 'number', min: 1, max: 100, value: p.max_alerts_per_day, inputmode: 'numeric', dir: 'ltr' }),
      quiet_from: h('input', { type: 'time', value: p.quiet_hours?.from || '22:00', 'aria-label': 'משעה' }),
      quiet_to: h('input', { type: 'time', value: p.quiet_hours?.to || '07:00', 'aria-label': 'עד שעה' }),
    };
    if (![...f.timezone.options].some(o => o.value === u.timezone)) f.timezone.prepend(h('option', { value: u.timezone, selected: true }, u.timezone));
    const cert = h('input', { type: 'range', min: 50, max: 80, step: 1, value: p.min_certainty, 'aria-label': 'ודאות מינימלית' });
    const certOut = h('output', { class: 'num' }, p.min_certainty + '%');
    cert.addEventListener('input', () => { certOut.textContent = cert.value + '%'; });
    const quiet = h('input', { type: 'checkbox' }); quiet.checked = !!p.quiet_hours;
    const push = h('input', { type: 'checkbox' }); push.checked = p.alert_channels.includes('push');
    const levelSeg = seg('risk_level', RISK_LEVELS, p.risk_level, { label: 'רמת סיכון', onChange: v => {
      const d = ME().risk_defaults[v]; f.risk_pct.value = d[0]; f.min_rr.value = d[1];
    } });
    // שיטות מועדפות — רשימה מסודרת: הראשונה מקבלת משקל 1.0, אחריה 0.8, 0.6…
    let order = [...p.preferred_methods.filter(id => methods.some(m => m.id === id)), ...methods.map(m => m.id).filter(id => !p.preferred_methods.includes(id))];
    const picked = new Set(p.preferred_methods);
    const prefList = h('ul', { class: 'list' });
    const drawPref = () => prefList.replaceChildren(...(order.length ? order.map((id, i) => {
      const m = methods.find(x => x.id === id);
      const c = h('input', { type: 'checkbox', 'aria-label': 'מועדפת: ' + m.name }); c.checked = picked.has(id);
      c.addEventListener('change', () => { if (c.checked) picked.add(id); else picked.delete(id); });
      const mv = d => { const [x] = order.splice(i, 1); order.splice(i + d, 0, x); drawPref(); };
      return h('li', { class: 'card', style: 'cursor:default' }, h('label', { class: 'switch', style: 'flex:1' }, c, h('span', {}, (m.sample ? '🧪 ' : '') + m.name)),
        h('span', { class: 'row-btns' }, h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'למעלה', disabled: i === 0, on: { click: () => mv(-1) } }, '▲'),
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'למטה', disabled: i === order.length - 1, on: { click: () => mv(1) } }, '▼')));
    }) : [h('li', { class: 'empty' }, 'עוד אין שיטות פעילות.')]));
    drawPref();

    const err = h('p', { class: 'error', role: 'alert', hidden: true });
    const submit = async e => {
      e.preventDefault();
      err.hidden = true;
      try {
        await api('settings', { base_currency: f.base_currency.value.trim(), timezone: f.timezone.value, account_size: f.account_size.value.trim(), default_risk_pct: f.risk_pct.value.trim() });
        await api('profile_save', {
          horizon: segVal(form, 'horizon'), risk_level: segVal(form, 'risk_level'), risk_pct: f.risk_pct.value.trim(), min_rr: f.min_rr.value.trim(),
          min_certainty: +cert.value, preferred_methods: order.filter(id => picked.has(id)), alert_channels: push.checked ? ['inbox', 'push'] : ['inbox'],
          quiet_hours: quiet.checked ? { from: f.quiet_from.value, to: f.quiet_to.value } : null, max_alerts_per_day: +f.max_alerts_per_day.value,
        });
        ctx.setMe(await api('me'));
        toast('הפרופיל נשמר');
      } catch (ex) { err.textContent = ex.message; err.hidden = false; }
    };
    const form = h('form', { class: 'stack', on: { submit } },
      h('h2', { class: 'sec-title' }, '🎯 פרופיל'),
      h('div', { class: 'block stack' },
        h('div', { class: 'f' }, h('span', {}, 'טווח השקעה — אילו מסגרות זמן נבדקות בניתוח'), seg('horizon', HORIZON, p.horizon, { grid: 3, label: 'טווח השקעה' })),
        h('div', { class: 'f' }, h('span', {}, 'רמת סיכון — ממלאת ברירות מחדל'), levelSeg),
        h('div', { class: 'fields two' }, field('סיכון לעסקה (%)', f.risk_pct), field('יחס סיכוי/סיכון מינימלי', f.min_rr)),
        h('label', { class: 'f' }, h('span', {}, 'ודאות מינימלית — מתחתיה הניתוח מסומן, והתראה לא נשלחת'), h('div', { class: 'toolbar', style: 'margin:0' }, cert, certOut))),
      h('h2', { class: 'sec-title' }, '⭐ שיטות מועדפות'),
      h('p', { class: 'hint' }, 'הסדר קובע את המשקל בשקלול: הראשונה 1.0, אחריה 0.8, 0.6… שיטה שלא סומנה — 0.5.'),
      prefList,
      h('h2', { class: 'sec-title' }, '🔔 התראות'),
      h('div', { class: 'block stack' },
        h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: true, disabled: true }), h('span', {}, 'בתוך האפליקציה — תמיד')),
        h('label', { class: 'switch' }, push, h('span', {}, 'פוש לטלפון')),
        h('label', { class: 'switch' }, quiet, h('span', {}, 'שעות שקטות')),
        h('div', { class: 'fields two' }, field('משעה', f.quiet_from), field('עד שעה', f.quiet_to)),
        field('מקסימום התראות ליום', f.max_alerts_per_day)),
      h('h2', { class: 'sec-title' }, '⚙️ הגדרות'),
      h('div', { class: 'block stack' },
        h('div', { class: 'fields two' }, field('מטבע בסיס להצגה', f.base_currency), field('גודל חשבון', f.account_size), field('אזור זמן', f.timezone, true))),
      err, h('div', {}, h('button', { class: 'btn primary', type: 'submit' }, 'שמירה')));
    render(form, passwordBlock());
  }

  // "סיסמה לאפליקציה" (spec-stage-a 20.1 ד'): לכניסה כשהאפליקציה מנותקת מהכלים הדיגיטליים, ושם רק אם עורך הפעיל
  // כניסה בסיסמה. נקבעת כשמחוברים; לא נמחקת כשהאפליקציה מתחברת שוב.
  function passwordBlock() {
    const has = !!ME().has_password;
    const open = () => {
      const pw = h('input', { type: 'password', autocomplete: 'new-password', dir: 'ltr', minlength: 10, maxlength: 200 });
      const s = sheet('סיסמה לאפליקציה', { onSave: async () => {
        await api('password_set', { password: pw.value });
        ctx.setMe(await api('me'));
        toast('הסיסמה נשמרה');
        render(document.querySelector('main form'), passwordBlock());
      } });
      s.body.append(field('סיסמה חדשה — לפחות 10 תווים', pw, true),
        h('p', { class: 'hint' }, 'נכנסים בה עם המייל ' + (ME().user.email || '') + '.'));
    };
    return h('section', { class: 'pw-block' },
      h('h2', { class: 'sec-title' }, '🔑 סיסמה לאפליקציה'),
      h('div', { class: 'block stack' },
        h('p', { class: 'hint' }, has ? 'יש לך סיסמה.' : 'אין לך סיסמה.',
          ' היא משמשת רק כשהאפליקציה מנותקת מהכלים הדיגיטליים ועורך ראשי פתח כניסה בסיסמה. אחרת — נכנסים דרך הכלים הדיגיטליים.'),
        ME().user.email ? h('div', {}, h('button', { class: 'btn ghost', type: 'button', on: { click: open } }, has ? 'החלפת סיסמה' : 'קביעת סיסמה'))
          : h('p', { class: 'hint' }, 'אין מייל בחשבון, ולכן אין כניסה בסיסמה.')));
  }

  // ── ניהול שיטות (6.7) + עמלות — השרת בודק עורך בכל פעולה ──
  function adminMethods() {
    const box = h('div', { class: 'stack' }, h('p', { class: 'loading' }, 'טוען…'));
    (async () => {
      let S, T;
      try { [S, T] = await Promise.all([api('admin_methods'), api('admin_tune_list')]); } catch (e) { box.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
      const primary = T.can_apply === true;
      const card = m => {
        const on = h('input', { type: 'checkbox' }); on.checked = m.enabled;
        const chipsOf = (all, chosen, lbl) => h('div', { class: 'chips', role: 'group', 'aria-label': lbl }, all.map(v => {
          const c = h('input', { type: 'checkbox', value: v }); c.checked = chosen.includes(v);
          return h('label', {}, c, h('span', {}, lbl === 'שווקים' ? (MARKET_LABEL[v] || v) : TF_LABEL[v]));
        }));
        const mk = chipsOf(m.all_markets, m.markets, 'שווקים');
        const tf = chipsOf(m.all_timeframes, m.timeframes, 'מסגרות זמן');
        const inputs = Object.fromEntries(Object.entries(m.param_defs).map(([k, d]) => [k, numInput(k, m.params[k] ?? '', String(d.default))]));
        // שכלול (18.7, ✅24): שינוי פרמטר משנה את התוצאה — רק עורך ראשי; עורך משני — דרך הצעה
        if (!primary) for (const el of Object.values(inputs)) el.disabled = true;
        const note = h('textarea', { rows: 2, maxlength: 500 }, m.note);
        return h('li', { class: 'card', style: 'cursor:default;display:block' },
          h('div', { class: 'l1' }, h('span', {}, m.name), h('span', { class: 't' }, 'גרסה ' + m.version)),
          m.label ? h('p', { class: 'chip warn', style: 'margin:4px 0' }, m.label) : null,
          h('p', { class: 'hint', style: 'margin:4px 0' }, m.source, sep(), `אופק ${m.horizon_bars} נרות`, sep(), m.alerts ? 'נכנסת להתראות' : 'לא בהתראות',
            sep(), `${m.backtests} בדיקות לאחור שמורות`),
          m.sample ? h('p', { class: 'hint' }, 'שיטת דוגמה: גם כשהיא מופעלת — רק עורכים רואים אותה, ולא נכנסת להתראות.') : null,
          h('label', { class: 'switch' }, on, h('span', {}, 'מופעלת')),
          h('div', { class: 'f' }, h('span', {}, 'שווקים'), mk), h('div', { class: 'f' }, h('span', {}, 'מסגרות זמן'), tf),
          h('div', { class: 'fields two' }, Object.entries(m.param_defs).map(([k, d]) => field(`${d.label} (${d.min}–${d.max})` + (d.ours === true ? ' · הגדרה שלנו' : d.ours === false ? ' · לפי הספר' : ''), inputs[k]))),
          field('הערה', note, true),
          h('p', { class: 'hint' }, primary ? 'שינוי פרמטר מעלה את גרסת השיטה, והבדיקות לאחור שלה מחושבות מחדש בפתיחה הבאה.'
            : 'שינוי פרמטר — רק עורך ראשי. אפשר להציע ערך ב"הצעת שינוי פרמטר" למטה.'),
          h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => {
            try {
              await api('admin_method_save', { id: m.id, enabled: on.checked, note: note.value,
                markets: [...mk.querySelectorAll('input:checked')].map(i => i.value), timeframes: [...tf.querySelectorAll('input:checked')].map(i => i.value),
                params: Object.fromEntries(Object.entries(inputs).map(([k, el]) => [k, el.value.trim()])) });
              toast('נשמר'); ctx.reload();
            } catch (e) { toast(e.message, true); }
          } } }, 'שמירה'),
          tunePanel(m, T.proposals.filter(x => x.method === m.id), primary),
          learnPanel(m));
      };
      const costIn = Object.fromEntries(Object.entries(S.costs).map(([mk, c]) => [mk, [numInput('fee', c.fee), numInput('slip', c.slip)]]));
      box.replaceChildren(
        h('p', { class: 'hint' }, 'השיטות שבקוד. שיטה חדשה נכתבת כקוד, ומופיעה כבויה עד שמפעילים אותה. שינוי בקוד מעלה את הגרסה, והבדיקות לאחור שלה מחושבות מחדש.'),
        h('ul', { class: 'list' }, S.methods.map(card)),
        h('h2', { class: 'sec-title' }, 'עמלה והחלקה לבדיקה לאחור (% לכל צד)'),
        h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, h('th', {}, 'שוק'), h('th', {}, 'עמלה'), h('th', {}, 'החלקה'))),
          h('tbody', {}, Object.entries(costIn).map(([mk, [a, b]]) => h('tr', {}, h('td', {}, MARKET_LABEL[mk] || mk), h('td', {}, a), h('td', {}, b))))),
        h('div', {}, h('button', { class: 'btn ghost', type: 'button', on: { click: async () => {
          try {
            await api('admin_costs_save', { costs: Object.fromEntries(Object.entries(costIn).map(([mk, [a, b]]) => [mk, { fee: a.value.trim(), slip: b.value.trim() }])) });
            toast('נשמר');
          } catch (e) { toast(e.message, true); }
        } } }, 'שמירת העמלות')));
    })();
    return box;
  }

  // ── שכלול (18.7, S7): הצעת שינוי פרמטר — ערך מועמד אחד, ביצועים בתוך הדגימה ומחוצה לה. בלי חיפוש אוטומטי ──
  const pct = v => (v === null || v === undefined ? '—' : Math.round(v * 1000) / 10 + '%');
  const r2 = v => (v === null || v === undefined ? '—' : (Math.round(v * 100) / 100).toString());
  const fmtVal = v => (typeof v === 'number' ? String(v) : '—');
  /** טבלה: לכל חלק (בתוך / מחוץ לדגימה) — כותרת ושורה לכל מדד; עמודה לכל ערך (נוכחי / מועמד). שורות קצרות — נוח בטלפון */
  function tuneTable(res) {
    const num = v => h('td', { class: 'num', dir: 'ltr' }, v);
    const part = (k, lbl) => h('tbody', { 'data-part': k },
      h('tr', { class: 'tune-part' }, h('th', { colspan: 3, scope: 'colgroup' }, lbl)),
      [['עסקאות', x => String(x.n)], ['הצלחה', x => pct(x.win_rate)], ['תוחלת', x => r2(x.expectancy_r) + 'R'], ['ירידה מרבית', x => r2(x.max_dd) + 'R']]
        .map(([l, f]) => h('tr', {}, h('th', { scope: 'row' }, l), num(f(res.current[k])), num(f(res.candidate[k])))));
    return h('table', { class: 'tbl tune-tbl' },
      h('thead', {}, h('tr', {}, h('th', {}, ''), h('th', { scope: 'col' }, 'נוכחי'), h('th', { scope: 'col' }, 'מועמד'))),
      part('in', 'בתוך הדגימה (70%)'), part('out', 'מחוץ לדגימה (30%)'));
  }
  const STATUS = { open: ['פתוחה', ''], applied: ['הוחלה', 'ok'], stale: ['ישנה', 'warn'] };
  function proposalCard(m, x, primary) {
    const d = m.param_defs[x.param] || { label: x.param };
    const [st, cls] = STATUS[x.status] || [x.status, ''];
    const apply = h('button', { class: 'btn sm primary', type: 'button', disabled: !primary || x.status !== 'open',
      on: { click: () => ctx.confirmSheet('החלת הצעה', `${m.name}: "${d.label}" ${fmtVal(x.current)} → ${fmtVal(x.candidate)}. הגרסה של השיטה עולה מ-${m.version} ל-${m.version + 1}, והבדיקות לאחור מחושבות מחדש.`, 'החל', async () => {
        await api('admin_tune_apply', { id: x.id }); toast('הוחל — גרסה חדשה'); ctx.reload();
      }) } }, 'החל');
    return h('li', { class: 'tune-prop', 'data-id': String(x.id), 'data-status': x.status },
      h('div', { class: 'l1' }, h('span', {}, `${d.label}: ${fmtVal(x.current)} → ${fmtVal(x.candidate)}`), h('span', { class: 'chip ' + cls }, st)),
      h('p', { class: 'hint' }, x.asset.replace(/^[^:]+:/, ''), sep(), TF_LABEL[x.tf] || x.tf, sep(), `גרסה ${x.method_version}`,
        x.created_by ? [sep(), x.created_by] : null, x.new_version ? [sep(), `→ גרסה ${x.new_version}`] : null),
      x.note ? h('p', { class: 'hint' }, x.note) : null,
      tuneTable(x.results),
      x.status === 'open' ? h('div', { class: 'row-btns wrap' }, apply,
        primary ? null : h('small', { class: 'muted tune-only' }, 'רק עורך ראשי מחיל')) : null);
  }
  function tunePanel(m, props, primary) {
    const keys = Object.keys(m.param_defs);
    if (!keys.length) return null;
    const param = select('tune-param', keys.map(k => [k, m.param_defs[k].label]), keys[0]);
    const value = numInput('tune-value', '', '');
    value.dir = 'rtl';   // ה-placeholder בעברית ("עכשיו 20 · 2–200"); מספר נקרא נכון גם כך
    const cur = k => m.params[k] ?? m.param_defs[k].default;
    const hintCur = () => { const d = m.param_defs[param.value]; value.placeholder = `עכשיו ${cur(param.value)} · ${d.min}–${d.max}`; };
    param.addEventListener('change', hintCur);
    hintCur();
    const asset = assetField(ctx, { value: 'binance:BTCUSDT', labels: { 'binance:BTCUSDT': { label: 'BTC ⇄ USDT' } }, label: 'נכס לבדיקה' });
    const tf = select('tune-tf', m.timeframes.map(t => [t, TF_LABEL[t] || t]), m.timeframes.includes('h4') ? 'h4' : m.timeframes[0]);
    const note = h('textarea', { rows: 2, maxlength: 500, 'aria-label': 'נימוק' });
    const out = h('div', { 'aria-live': 'polite' });
    const go = h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => {
      go.disabled = true; out.replaceChildren(h('p', { class: 'loading' }, 'מחשב בתוך הדגימה ומחוצה לה…'));
      try {
        await api('admin_tune_propose', { method: m.id, param: param.value, value: value.value.trim(), asset: asset.value, tf: tf.value, note: note.value });
        toast('ההצעה נשמרה'); ctx.reload();
      } catch (e) { out.replaceChildren(h('p', { class: 'error' }, e.message)); go.disabled = false; }
    } } }, 'חשב והצע');
    const det = h('details', { class: 'tune' + (props.length ? ' has' : '') },
      h('summary', {}, '🧪 הצעת שינוי פרמטר', props.length ? h('span', { class: 'chip' }, String(props.length)) : null),
      h('p', { class: 'hint' }, 'מזינים ערך אחד. המערכת מריצה את השיטה על אותם נרות בערך הנוכחי ובמועמד — 70% הראשונים "בתוך הדגימה", 30% האחרונים "מחוץ לדגימה", בלי חפיפה. בלי עסקאות מחוץ לדגימה — אין הצעה. אין חיפוש אוטומטי של ערכים.'),
      h('div', { class: 'fields two' }, field('פרמטר', param), field('ערך מועמד', value), h('div', { class: 'f wide' }, h('span', {}, 'נכס'), asset), field('מסגרת זמן', tf), field('נימוק', note, true)),
      h('div', {}, go), out,
      props.length ? h('ul', { class: 'list tune-list' }, props.map(x => proposalCard(m, x, primary))) : null);
    return det;
  }

  // ── למידה (פרק 23, L3): N ותוצאות לפי פלח, עם מינימום N (23.6). לכל העורכים; נטען רק כשפותחים ──
  const LEARN_DIM = { market: 'שוק', tf: 'מסגרת', market_tf: 'שוק × מסגרת', month: 'חודש', quarter: 'רבעון', weekday: 'יום', hour: 'שעה (UTC)', holidays: 'חגים' };
  const LEARN_SRC = [['backtest', 'בדיקה לאחור'], ['live', 'בפועל מאז'], ['trade', 'עסקאות']];
  const MONTHS = ['ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר'];
  const DAYS = ['שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת', 'ראשון'];
  const REGION = { US: 'ארה"ב', EU: 'אירופה', UK: 'בריטניה', JP: 'יפן', CN: 'סין', IL: 'ישראל', XMAS: 'חג המולד – ראש השנה', none: 'בלי חג' };
  function learnValue(dim, v) {
    if (dim === 'market') return MARKET_LABEL[v] || v;
    if (dim === 'tf') return TF_LABEL[v] || v;
    if (dim === 'market_tf') { const [a, b] = v.split('|'); return `${MARKET_LABEL[a] || a} · ${TF_LABEL[b] || b}`; }
    if (dim === 'month') return MONTHS[+v - 1] || v;
    if (dim === 'quarter') return 'רבעון ' + v;
    if (dim === 'weekday') return DAYS[+v - 1] || v;
    if (dim === 'hour') return String(v).padStart(2, '0') + ':00';
    if (dim === 'holidays') return REGION[v] || v;
    return v;
  }
  function learnPanel(m) {
    const out = h('div', { 'aria-live': 'polite' });
    const src = select('learn-source', LEARN_SRC, 'backtest');
    let ver = null;
    let loaded = false;
    const load = async () => {
      out.replaceChildren(h('p', { class: 'loading' }, 'טוען…'));
      let L;
      try { L = await api('admin_learn', null, { method: m.id, source: src.value, ...(ver ? { version: ver } : {}) }); }
      catch (e) { out.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
      const versions = [...new Set([m.version, ...L.counts.map(c => c.version)])].sort((a, b) => b - a);
      const verSel = select('learn-version', versions.map(v => [String(v), 'גרסה ' + v + (v === m.version ? ' (נוכחית)' : '')]), String(L.version));
      verSel.addEventListener('change', () => { ver = +verSel.value; load(); });
      const q = new URLSearchParams({ action: 'admin_learn_csv', method: m.id, version: String(L.version), source: L.source });
      const cell = c => (c.enough
        ? [h('td', { class: 'num', dir: 'ltr' }, String(c.n)), h('td', { class: 'num', dir: 'ltr' }, c.win_pct + '%'),
          h('td', { class: 'num', dir: 'ltr' }, (Math.round(c.expectancy_r * 100) / 100) + 'R'),
          h('td', { class: 'num', dir: 'ltr' }, c.certainty === null ? '—' : c.certainty + '%')]
        : [h('td', { class: 'num', dir: 'ltr' }, String(c.n)), h('td', { colspan: 3, class: 'muted' }, `אין מספיק נתונים (${c.n})`)]);
      const row = (label, c) => h('tr', { class: c.enough ? '' : 'learn-few', 'data-enough': String(c.enough) }, h('th', { scope: 'row' }, label), ...cell(c));
      const byDim = {};
      for (const sg of L.segments) (byDim[sg.dim] ??= []).push(sg);
      out.replaceChildren(
        h('p', { class: 'hint' }, `${L.rows.toLocaleString('he-IL')} שורות במסד לשיטה הזו · ≈ ${Math.max(1, Math.round(L.size / 1024)).toLocaleString('he-IL')} KB`),
        L.counts.length ? h('table', { class: 'tbl learn-counts' },
          h('thead', {}, h('tr', {}, h('th', {}, 'גרסה'), h('th', {}, 'מקור'), h('th', {}, 'N'))),
          h('tbody', {}, L.counts.map(c => h('tr', {}, h('td', {}, String(c.version)), h('td', {}, (LEARN_SRC.find(s => s[0] === c.source) || [, c.source])[1]),
            h('td', { class: 'num', dir: 'ltr' }, String(c.n))))))
          : h('p', { class: 'hint' }, 'עוד אין עסקאות במסד. הן נכתבות כשבדיקה לאחור מחושבת.'),
        h('div', { class: 'fields two' }, field('גרסה', verSel), field('מקור', src)),
        h('p', { class: 'hint' }, `פלח עם פחות מ-${L.min_n} עסקאות — "אין מספיק נתונים", בלי אחוז ובלי תוחלת.`,
          L.gaps ? ` ${L.gaps} אותות לא נפתחו (פער מעבר לרמה) — לא בסטטיסטיקה.` : ''),
        h('table', { class: 'tbl learn-tbl' },
          h('thead', {}, h('tr', {}, h('th', {}, 'פלח'), h('th', {}, 'N'), h('th', {}, 'הצלחה'), h('th', {}, 'תוחלת'), h('th', {}, 'ודאות'))),
          h('tbody', { 'data-dim': 'total' }, row('הכול', L.total)),
          Object.keys(LEARN_DIM).filter(d => byDim[d]).map(d => h('tbody', { 'data-dim': d },
            h('tr', { class: 'tune-part' }, h('th', { colspan: 5, scope: 'colgroup' }, LEARN_DIM[d])),
            byDim[d].map(sg => row(learnValue(d, sg.value), sg))))),
        h('div', {}, h('a', { class: 'btn ghost sm', href: 'api.php?' + q, download: '' }, '⬇️ ייצוא CSV')));
    };
    src.addEventListener('change', load);
    const det = h('details', { class: 'learn' }, h('summary', {}, '📚 למידה'), out);
    det.addEventListener('toggle', () => { if (det.open && !loaded) { loaded = true; load(); } });
    return det;
  }

  return { viewProfile, adminMethods };
}
