// הפרופיל וניהול השיטות — spec-stage-a פרקים 5–6. כרטיס הניתוח עבר לפס שמתחת לגרף (card.js, forecast.js — S4, 18.4);
// מסך "🧭 ניתוח" הנפרד הוסר (2.15). כל טקסט — textContent.
import { openBuilder, openVersions } from './builder.js';
import { TF_LABEL } from './card.js';

const HORIZON = [['short', 'קצר (דקות–שעות)'], ['medium', 'בינוני (ימים–שבועות)'], ['long', 'ארוך (חודשים+)']];
const RISK_LEVELS = [['low', 'נמוכה'], ['medium', 'בינונית'], ['high', 'גבוהה']];
const MARKET_LABEL = { stocks: 'מניות', crypto: 'קריפטו', forex: 'מט"ח', futures: 'חוזים', options: 'אופציות', indices: 'מדדים', commodities: 'סחורות' };

export function mountAnalysis(ctx) {
  const { h, api, toast, setHead, render, field, numInput, seg, segVal, select, sep } = ctx;
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
    render(form);
  }

  // ── ניהול שיטות (6.7) + עמלות — השרת בודק מנהל בכל פעולה ──
  function adminMethods() {
    const box = h('div', { class: 'stack' }, h('p', { class: 'loading' }, 'טוען…'));
    (async () => {
      let S;
      try { S = await api('admin_methods'); } catch (e) { box.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
      const card = m => {
        const on = h('input', { type: 'checkbox' }); on.checked = m.enabled;
        const chipsOf = (all, chosen, lbl) => h('div', { class: 'chips', role: 'group', 'aria-label': lbl }, all.map(v => {
          const c = h('input', { type: 'checkbox', value: v }); c.checked = chosen.includes(v);
          return h('label', {}, c, h('span', {}, lbl === 'שווקים' ? (MARKET_LABEL[v] || v) : TF_LABEL[v]));
        }));
        const mk = chipsOf(m.all_markets, m.markets, 'שווקים');
        const tf = chipsOf(m.all_timeframes, m.timeframes, 'מסגרות זמן');
        const inputs = Object.fromEntries(Object.entries(m.param_defs).map(([k, d]) => [k, numInput(k, m.params[k] ?? '', String(d.default))]));
        const note = h('textarea', { rows: 2, maxlength: 500 }, m.note);
        return h('li', { class: 'card', style: 'cursor:default;display:block' },
          h('div', { class: 'l1' }, h('span', {}, m.name), h('span', { class: 't' }, 'גרסה ' + m.version)),
          m.label ? h('p', { class: 'chip warn', style: 'margin:4px 0' }, m.label) : null,
          h('p', { class: 'hint', style: 'margin:4px 0' }, m.source, sep(), `אופק ${m.horizon_bars} נרות`, sep(), m.alerts ? 'נכנסת להתראות' : 'לא בהתראות',
            sep(), `${m.backtests} בדיקות לאחור שמורות`),
          m.sample ? h('p', { class: 'hint' }, 'שיטת דוגמה: גם כשהיא מופעלת — רק מנהלים רואים אותה, ולא נכנסת להתראות.') : null,
          m.kind === 'defined' ? h('div', { class: 'row-btns wrap' }, h('span', { class: 'chip' }, '🧩 מוגדרת'),
            h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => openBuilder(ctx, m.definition, () => ctx.reload()) } }, '✏️ עריכת ההגדרה'),
            h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => openVersions(ctx, m.id, () => ctx.reload()) } }, '🕘 גרסאות')) : null,
          h('label', { class: 'switch' }, on, h('span', {}, 'מופעלת')),
          h('div', { class: 'f' }, h('span', {}, 'שווקים'), mk), h('div', { class: 'f' }, h('span', {}, 'מסגרות זמן'), tf),
          h('div', { class: 'fields two' }, Object.entries(m.param_defs).map(([k, d]) => field(`${d.label} (${d.min}–${d.max})` + (d.ours === true ? ' · הגדרה שלנו' : d.ours === false ? ' · לפי הספר' : ''), inputs[k]))),
          field('הערה', note, true),
          h('p', { class: 'hint' }, 'שינוי פרמטרים — הבדיקות לאחור של השיטה מחושבות מחדש בפתיחה הבאה.'),
          h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => {
            try {
              await api('admin_method_save', { id: m.id, enabled: on.checked, note: note.value,
                markets: [...mk.querySelectorAll('input:checked')].map(i => i.value), timeframes: [...tf.querySelectorAll('input:checked')].map(i => i.value),
                params: Object.fromEntries(Object.entries(inputs).map(([k, el]) => [k, el.value.trim()])) });
              toast('נשמר'); ctx.reload();
            } catch (e) { toast(e.message, true); }
          } } }, 'שמירה'));
      };
      const costIn = Object.fromEntries(Object.entries(S.costs).map(([mk, c]) => [mk, [numInput('fee', c.fee), numInput('slip', c.slip)]]));
      box.replaceChildren(
        h('p', { class: 'hint' }, 'השיטות שבקוד, והשיטות המוגדרות (🧩). שיטה חדשה מופיעה כבויה עד שמפעילים אותה. שינוי בקוד או בהגדרה מעלה את הגרסה, והבדיקות לאחור שלה מחושבות מחדש.'),
        h('div', {}, h('button', { class: 'btn ghost', type: 'button', on: { click: () => openBuilder(ctx, null, () => ctx.reload()) } }, '➕ שיטה מוגדרת')),
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

  return { viewProfile, adminMethods };
}
