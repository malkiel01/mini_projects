// לשונית "ניתוח", הפרופיל וניהול השיטות — spec-stage-a פרקים 5–6.
// בלי polling (✅13): פנייה לשרת רק מפעולה של המשתמש — פתיחת המסך ולחיצה על "נתח". הבדיקה לאחור רצה בשרת
// לפי דרישה, באותה בקשה. הניסוח — 6.6: "לפי כללי השיטה", "ציון כיוון"; לא הוראה ולא הבטחה. כל טקסט — textContent.
import * as D from './decimal.js';

const TF_LABEL = { m1: 'דקה', m5: '5 ד׳', m15: '15 ד׳', m30: '30 ד׳', h1: 'שעה', h4: '4 ש׳', d1: 'יום', w1: 'שבוע', mn1: 'חודש' };
const SIDE = { long: 'לונג', short: 'שורט', none: 'אין כיוון' };
const RISK = { low: 'נמוכה', medium: 'בינונית', high: 'גבוהה' };
const HORIZON = [['short', 'קצר (דקות–שעות)'], ['medium', 'בינוני (ימים–שבועות)'], ['long', 'ארוך (חודשים+)']];
const RISK_LEVELS = [['low', 'נמוכה'], ['medium', 'בינונית'], ['high', 'גבוהה']];
const MARKET_LABEL = { stocks: 'מניות', crypto: 'קריפטו', forex: 'מט"ח', futures: 'חוזים', options: 'אופציות', indices: 'מדדים', commodities: 'סחורות' };

export function mountAnalysis(ctx) {
  const { h, api, toast, setHead, render, field, numInput, seg, segVal, select, num, sep } = ctx;
  const ME = () => ctx.me();
  const years = (a, b) => {
    const y = t => new Date(t * 1000).getUTCFullYear();
    return a && b ? (y(a) === y(b) ? String(y(a)) : `${y(a)}–${y(b)}`) : '';
  };
  const when = t => { try { return new Date(t * 1000).toLocaleString('he-IL', { timeZone: ME().user.timezone, day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } };
  const disclaimer = () => h('p', { class: 'disclaimer' }, '⚖️ ' + ME().disclaimer);

  // ── ניתוח ─────────────────────────────────────────────
  async function viewAnalysis(assetArg) {
    setHead('ניתוח', 'לפי כללי שיטות מסחר — לא ייעוץ');
    const [{ methods }, { watchlist }, { providers }] = await Promise.all([api('methods'), api('watchlist'), api('market_providers')]);
    const key = 'ts-analysis';
    let st = {};
    try { st = JSON.parse(sessionStorage.getItem(key) || '{}'); } catch { /* ריק */ }
    if (assetArg) st.asset = assetArg;
    const save = () => { try { sessionStorage.setItem(key, JSON.stringify(st)); } catch { /* ריק */ } };

    if (!methods.length) {
      render(h('p', { class: 'empty' }, 'עוד אין שיטות פעילות. השיטה הראשונה מהספר תיכנס בקרוב.'),
        ME().is_admin ? h('p', { class: 'hint' }, 'מנהל: שיטות מפעילים ב"עוד ← ניהול ← 🧪 שיטות".') : null, disclaimer());
      return;
    }
    const assets = [...new Set([...(st.asset ? [st.asset] : []), ...watchlist.map(w => w.asset)])];
    if (!assets.length) {
      render(h('p', { class: 'empty' }, 'בוחרים נכס ב"שוק" ולוחצים 🧭, או מוסיפים נכסים למעקב.'), h('a', { class: 'btn ghost', href: '#/market' }, '📈 לשוק'), disclaimer());
      return;
    }
    st.asset = assets.includes(st.asset) ? st.asset : assets[0];
    const prov = id => providers.find(p => p.id === id);
    const assetSel = select('asset', assets.map(a => [a, '\u2066' + a.slice(a.indexOf(':') + 1) + '\u2069 · ' + (prov(a.split(':')[0])?.name || a.split(':')[0])]), st.asset, { 'aria-label': 'נכס' });
    const tfBox = h('div');
    const methodBox = h('div', { class: 'chips', role: 'group', 'aria-label': 'שיטות' });
    const out = h('div', { class: 'stack', 'aria-live': 'polite' });
    const go = h('button', { class: 'btn primary', type: 'button' }, '🧭 נתח');

    const pref = ME().profile.preferred_methods;
    st.methods = (st.methods || []).filter(id => methods.some(m => m.id === id));
    if (!st.methods.length) st.methods = (pref.filter(id => methods.some(m => m.id === id)).length ? pref.filter(id => methods.some(m => m.id === id)) : methods.map(m => m.id));
    methods.forEach(m => {
      const c = h('input', { type: 'checkbox', value: m.id });
      c.checked = st.methods.includes(m.id);
      c.addEventListener('change', () => { st.methods = [...methodBox.querySelectorAll('input:checked')].map(i => i.value); save(); drawTfs(); });
      methodBox.append(h('label', {}, c, h('span', {}, (m.sample ? '🧪 ' : '') + m.name)));
    });

    function tfOptions() {
      const p = prov(st.asset.split(':')[0]);
      const chosen = methods.filter(m => st.methods.includes(m.id));
      const ok = t => (p?.tfs || []).includes(t) && chosen.some(m => m.timeframes.includes(t));
      const byProfile = (ME().horizon_tfs[ME().profile.horizon] || []).filter(ok);
      return byProfile.length ? byProfile : Object.keys(TF_LABEL).filter(ok);
    }
    function drawTfs() {
      const tfs = tfOptions();
      if (!tfs.includes(st.tf)) st.tf = tfs[0];
      tfBox.replaceChildren(tfs.length ? seg('atf', tfs.map(t => [t, TF_LABEL[t]]), st.tf, { label: 'מסגרת זמן', onChange: v => { st.tf = v; save(); } })
        : h('p', { class: 'hint' }, 'אין מסגרת זמן משותפת לשיטות שנבחרו ולספק של הנכס.'));
      go.disabled = !tfs.length || !st.methods.length;
    }
    assetSel.addEventListener('change', () => { st.asset = assetSel.value; save(); drawTfs(); out.replaceChildren(); });
    drawTfs();

    go.addEventListener('click', async () => {
      if (!st.tf || !st.methods.length) return;
      go.disabled = true;
      out.replaceChildren(h('p', { class: 'loading' }, 'מנתח… בפעם הראשונה הבדיקה לאחור יכולה לקחת כמה שניות'));
      try {
        const r = await api('analysis', null, { asset: st.asset, tf: st.tf, methods: st.methods.join(',') });
        const alertable = id => methods.find(m => m.id === id)?.alerts;
        out.replaceChildren(...(r.cards.length > 1 ? [blendCard(r, r.cards.every(c => alertable(c.method.id)))] : []), ...r.cards.map(c => methodCard(c, r, alertable(c.method.id))));
      } catch (e) {
        out.replaceChildren(h('p', { class: 'error', role: 'alert' }, e.message));
      } finally { go.disabled = false; }
    });

    render(
      h('div', { class: 'block stack' },
        h('div', { class: 'toolbar pick' }, assetSel, h('a', { class: 'btn ghost sm', href: '#/chart/' + encodeURIComponent(st.asset) }, '📈 גרף')),
        tfBox, h('p', { class: 'hint' }, 'מסגרות הזמן — לפי טווח ההשקעה בפרופיל. ', h('a', { href: '#/profile' }, 'פרופיל')),
        methodBox, h('div', {}, go)),
      out, disclaimer());
  }

  function certaintyBlock(c, r) {
    const bt = c.backtest || {};
    const sym = r.symbol + ' ' + TF_LABEL[c.tf];
    if (bt.status === 'ok') {
      return h('div', { class: 'summary' },
        h('div', { class: 'line' }, h('span', { class: 'muted' }, 'ודאות'), h('b', { class: 'num' }, bt.certainty + '%'),
          c.below_threshold ? h('span', { class: 'chip warn' }, `מתחת לסף שלך (${r.profile.min_certainty}%)`) : null),
        h('div', { class: 'line muted' }, `${bt.win_pct}% הצלחה ב-${bt.n.toLocaleString('he-IL')} אותות`, sep(), years(bt.from_t, bt.to_t), sep(), h('span', { class: 'num' }, sym)),
        h('div', { class: 'line muted' }, `תוחלת ${(bt.expectancy_r >= 0 ? '+' : '') + bt.expectancy_r.toFixed(2)}R`, sep(), `ירידה מרבית ${bt.max_dd.toFixed(1)}R`, sep(), 'חושב ' + when(bt.computed_at)),
        h('p', { class: 'hint', style: 'margin:0' }, 'הוודאות היא של השיטה על הנכס הזה בעבר — לא של העסקה הבאה. הגבול התחתון של רווח סמך 95% (Wilson).'));
    }
    if (bt.status === 'insufficient') {
      return h('div', { class: 'summary' }, h('div', { class: 'line' }, h('b', {}, `אין מספיק נתונים (${bt.n} אותות)`)),
        h('div', { class: 'line muted' }, 'ודאות מוצגת רק מ-30 אותות בבדיקה לאחור', sep(), years(bt.from_t, bt.to_t), sep(), h('span', { class: 'num' }, sym)));
    }
    return h('div', { class: 'summary' }, h('div', { class: 'line' }, h('b', {}, bt.message || 'הבדיקה לאחור לא זמינה')), h('div', { class: 'line muted' }, 'בלי אחוז ודאות'));
  }

  /** "פתח עסקה מזה" (פרק 11): הנכס ביומן לפי סימול מנורמל; הרמות — המחרוזות המעוגלות מהשרת; וגודל הפוזיציה מהפרופיל */
  async function openTrade(c, r) {
    try {
      const { prefill: p } = await api('trade_prefill', null, { asset: r.asset });
      const snapshot = { asset: r.asset, tf: c.tf, method_id: c.method.id, method_version: c.method.version, bias: c.bias,
        levels: c.levels, certainty: c.certainty, n: c.backtest?.n ?? null, win_pct: c.backtest?.win_pct ?? null, as_of: c.as_of };
      await ctx.openTrade({
        trade: { symbol: p.symbol, market: p.market, currency: p.currency, multiplier: p.multiplier ?? '', option: p.option, direction: c.bias,
          entry_price: c.levels.entry, planned_stop: c.levels.stop, planned_target: c.levels.target, quantity: c.risk?.position_qty || '' },
        method_id: c.method.id, method_name: c.method.name, snapshot, existing: p.existing,
      });
    } catch (e) { toast(e.message, true); }
  }

  const alertBtn = pre => h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => ctx.newAlert(pre) } }, '🔔 התראה מזה');

  function methodCard(c, r, alertable = false) {
    const head = h('div', { class: 'card-head' }, h('b', {}, c.method.name), h('small', { class: 'muted' }, 'גרסה ' + c.method.version));
    const label = c.method.label ? h('p', { class: 'chip warn', style: 'margin:4px 0' }, c.method.label) : null;
    const box = h('section', { class: 'block stack analysis-card', 'aria-label': c.method.name }, head, label);
    if (c.status !== 'ok') {
      box.append(h('p', { class: 'hint' }, c.message), h('p', { class: 'muted' }, c.horizon_text));
      return box;
    }
    const met = side => c.conditions.filter(x => x.side === side);
    const count = side => `${met(side).filter(x => x.met).length} מתוך ${met(side).length}`;
    const verdict = c.bias === 'none'
      ? `לפי ${c.method.name} — אין כרגע כיוון לפי כללי השיטה (לונג ${count('long')}, שורט ${count('short')})`
      : `לפי ${c.method.name} — תנאי ה${SIDE[c.bias]} מתקיימים (${count(c.bias)})` + (c.signal_ago != null ? ` · האות לפני ${c.signal_ago} נרות` : '');
    box.append(
      h('p', { class: 'verdict ' + c.bias }, h('span', { class: 'dir-ic ' + c.bias, 'aria-hidden': 'true' }, c.bias === 'long' ? '↗' : c.bias === 'short' ? '↘' : '–'), h('b', {}, verdict)),
      h('ul', { class: 'conds' }, c.conditions.map(x => h('li', { class: x.met ? 'met' : '' }, h('span', { 'aria-hidden': 'true' }, x.met ? '✓' : '○'),
        h('span', {}, x.text), h('small', { class: 'muted' }, SIDE[x.side]), h('span', { class: 'sr-only' }, x.met ? 'מתקיים' : 'לא מתקיים')))));
    if (c.levels) {
      box.append(h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'כניסה (לפי השיטה)'), h('dd', { class: 'num' }, D.fmt(c.levels.entry, 10))),
        h('div', {}, h('dt', {}, 'סטופ'), h('dd', { class: 'num' }, D.fmt(c.levels.stop, 10))),
        c.levels.target ? h('div', {}, h('dt', {}, 'יעד'), h('dd', { class: 'num' }, D.fmt(c.levels.target, 10))) : null),
        h('p', { class: 'hint', style: 'margin:0' }, 'רמות לפי כללי השיטה, מעוגלות ל-tick של הנכס.'));
    }
    if (c.risk) {
      const rk = c.risk;
      box.append(h('div', { class: 'line-wrap' },
        rk.level ? h('span', { class: 'chip ' + { low: 'ok', medium: 'warn', high: 'bad' }[rk.level] }, `סיכון בעסקה: ${RISK[rk.level]} (סטופ ${rk.stop_atr} ATR)`) : null,
        rk.rr ? h('span', { class: 'chip' + (rk.rr_below_min ? ' warn' : '') }, `יחס סיכוי/סיכון ${D.fmt(rk.rr, 2)}` + (rk.rr_below_min ? ` — מתחת למינימום שלך (${D.fmt(r.profile.min_rr, 2)})` : '')) : null),
        rk.position_qty ? h('p', { class: 'hint', style: 'margin:0' }, 'גודל פוזיציה לפי הסיכון שבפרופיל (', num(D.fmt(rk.risk_pct, 4) + '%'), '): ', num(D.fmt(rk.position_qty, 8)), ' יחידות')
          : h('p', { class: 'hint', style: 'margin:0' }, 'לגודל פוזיציה — גודל חשבון ב', h('a', { href: '#/profile' }, 'פרופיל'), '.'));
    }
    box.append(certaintyBlock(c, r),
      h('p', { class: 'muted', style: 'margin:0' }, 'אופק: ' + c.horizon_text.replace(/^עד /, 'עד '),
        c.max_horizon && c.max_horizon.tf !== c.tf ? [sep(), 'הארוך ביותר שנבדק: ' + c.max_horizon.text] : null),
      h('p', { class: 'hint', style: 'margin:0' }, 'נכון לנר שנסגר ב-' + when(c.as_of)),
      h('div', { class: 'row-btns wrap' },
        c.levels && c.bias !== 'none' ? h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => openTrade(c, r) } }, '📓 פתח עסקה מזה') : null,
        alertable ? alertBtn({ asset: r.asset, tf: c.tf, kind: 'method', method_id: c.method.id, side: c.bias === 'none' ? 'both' : c.bias }) : null));
    return box;
  }

  function blendCard(r, alertable = false) {
    const b = r.blend;
    const name = id => r.cards.find(c => c.method.id === id)?.method.name || id;
    const box = h('section', { class: 'block stack', 'aria-label': 'שקלול' }, h('div', { class: 'card-head' }, h('b', {}, '⚖️ שקלול השיטות')));
    if (b.score === null) {
      box.append(h('p', { class: 'hint' }, 'אין שקלול: לאף שיטה שנבחרה אין עדיין ודאות (30 אותות לפחות).'));
      return box;
    }
    const lean = b.lean === 'long' ? 'נוטה ללונג' : b.lean === 'short' ? 'נוטה לשורט' : 'בלי נטייה';
    const pos = (b.score + 1) / 2 * 100;   // −1 … +1 → 0 … 100
    box.append(
      h('div', { class: 'line' }, h('span', {}, 'ציון כיוון: ', h('b', {}, lean), ', ', num((b.score >= 0 ? '+' : '') + b.score.toFixed(2)))),
      h('div', { class: 'score-bar', role: 'img', 'aria-label': `ציון כיוון ${b.score.toFixed(2)} מתוך טווח מינוס 1 עד פלוס 1` },
        h('span', { class: 'mid' }), h('span', { class: 'dot', style: `inset-inline-start:calc(${100 - pos}% - 7px)` })),
      h('div', { class: 'score-ends muted' }, h('span', {}, 'לונג +1'), h('span', {}, 'שורט −1')),
      h('p', { style: 'margin:0' }, `${b.counts.long} מ-${b.total} שיטות לונג`, sep(), `${b.counts.short} שורט`, sep(), `${b.counts.none} בלי כיוון`),
      b.strong_disagreement ? h('p', { class: 'chip bad' }, '⚠️ מחלוקת חזקה בין השיטות — גם הלונג וגם השורט מחזיקים 30% מהמשקל או יותר') : null,
      b.excluded.length ? h('p', { class: 'hint', style: 'margin:0' }, 'לא בשקלול (פחות מ-30 אותות): ' + b.excluded.map(name).join(', ')) : null,
      h('p', { class: 'hint', style: 'margin:0' }, 'משקל = ודאות × העדפה (הסדר ב"שיטות מועדפות" בפרופיל).'),
      alertable ? h('div', {}, alertBtn({ asset: r.asset, tf: r.tf, kind: 'blend', methods: r.cards.map(c => c.method.id) })) : null);
    return box;
  }

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
          h('label', { class: 'switch' }, on, h('span', {}, 'מופעלת')),
          h('div', { class: 'f' }, h('span', {}, 'שווקים'), mk), h('div', { class: 'f' }, h('span', {}, 'מסגרות זמן'), tf),
          h('div', { class: 'fields two' }, Object.entries(m.param_defs).map(([k, d]) => field(`${d.label} (${d.min}–${d.max})`, inputs[k]))),
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
        h('p', { class: 'hint' }, 'השיטות שבקוד. שיטה חדשה מופיעה כבויה עד שמפעילים אותה. שינוי בקוד של שיטה מעלה את הגרסה, והבדיקות לאחור שלה מחושבות מחדש.'),
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

  return { viewAnalysis, viewProfile, adminMethods };
}
