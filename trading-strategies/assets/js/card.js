// כרטיס הניתוח של שיטה — בפס שמתחת לגרף (spec-stage-a 18.4, S4). היה מסך "🧭 ניתוח" נפרד; המסך הוסר (2.15),
// והחלקים כאן משמשים את הפס (forecast.js): ממצא (כיוון, תנאים, רמות, סיכון), הסתברות (הוודאות של 6.3, ✅26),
// שקלול (6.5), "🔔 התראה מזה" ו"📓 פתח עסקה מזה". הניסוח — 6.6: "לפי כללי השיטה", לא הוראה ולא הבטחה. כל טקסט — textContent.
import * as D from './decimal.js';

export const TF_LABEL = { m1: 'דקה', m5: '5 ד׳', m15: '15 ד׳', m30: '30 ד׳', h1: 'שעה', h4: '4 ש׳', d1: 'יום', w1: 'שבוע', mn1: 'חודש' };
export const SIDE = { long: 'לונג', short: 'שורט', none: 'אין כיוון' };
const RISK = { low: 'נמוכה', medium: 'בינונית', high: 'גבוהה' };

export function cardParts(ctx) {
  const { h, api, toast, num, sep, pairMain } = ctx;
  const ME = () => ctx.me();
  const years = (a, b) => {
    const y = t => new Date(t * 1000).getUTCFullYear();
    return a && b ? (y(a) === y(b) ? String(y(a)) : `${y(a)}–${y(b)}`) : '';
  };
  const when = t => { try { return new Date(t * 1000).toLocaleString('he-IL', { timeZone: ME().user.timezone, day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } };

  /** הסתברות (✅26): אחוז ההצלחה של השיטה בבדיקה לאחור על הנכס, עם המקור. בלי בדיקה, או N < 30 — "לא נבדק לאחור", בלי אחוז */
  function probability(c, r, running = false) {
    const bt = c.backtest || {};
    const sym = [pairMain(r.pair), ' ', TF_LABEL[c.tf]];   // BTC ⇄ USDT שעה (18.1.1)
    if (bt.status === 'ok') {
      return h('div', { class: 'summary' },
        h('div', { class: 'line' }, h('span', { class: 'muted' }, 'ודאות'), h('b', { class: 'num' }, bt.certainty + '%'),
          c.below_threshold ? h('span', { class: 'chip warn' }, `מתחת לסף שלך (${r.profile.min_certainty}%)`) : null),
        h('div', { class: 'line muted' }, `${bt.win_pct}% הצלחה ב-${bt.n.toLocaleString('he-IL')} אותות`, sep(), years(bt.from_t, bt.to_t), sep(), h('span', { class: 'num' }, sym)),
        h('div', { class: 'line muted' }, `תוחלת ${(bt.expectancy_r >= 0 ? '+' : '') + bt.expectancy_r.toFixed(2)}R`, sep(), `ירידה מרבית ${bt.max_dd.toFixed(1)}R`, sep(), 'חושב ' + when(bt.computed_at)),
        bt.stale ? h('p', { class: 'hint', style: 'margin:0' }, 'הבדיקה לאחור מלפני יותר מיום — תחושב מחדש ב"↻ רענן".') : null,
        h('p', { class: 'hint', style: 'margin:0' }, 'הוודאות היא של השיטה על הנכס הזה בעבר — לא של העסקה הבאה, ולא של התרחיש. הגבול התחתון של רווח סמך 95% (Wilson).'));
    }
    const why = running ? 'מריץ בדיקה לאחור… בפעם הראשונה זה יכול לקחת כמה שניות'
      : bt.status === 'insufficient' ? `${bt.n} אותות בבדיקה לאחור — ודאות מוצגת רק מ-30`
        : bt.status === 'missing' || !bt.status ? 'עוד אין בדיקה לאחור שמורה לנכס ולמסגרת האלה'
          : bt.message || 'הבדיקה לאחור לא זמינה';
    return h('div', { class: 'summary' }, h('div', { class: 'line' }, h('b', { class: 'bt-none' }, 'לא נבדק לאחור')),
      h('div', { class: 'line muted' }, why, bt.status === 'insufficient' ? [sep(), years(bt.from_t, bt.to_t), sep(), h('span', { class: 'num' }, sym)] : null));
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

  /** הכיוון במילים — לשורת הסיכום של שיטה מקופלת */
  const biasWord = c => (c?.status === 'ok' ? SIDE[c.bias] : '');

  /** ממצא: מה שכבר קרה על הנרות הסגורים — הכיוון לפי כללי השיטה, התנאים, הרמות והסיכון */
  function finding(c, r) {
    if (c.status !== 'ok') return [h('p', { class: 'hint' }, c.message)];
    // תנאי עם met: null — "בדוק בעצמך" (17.1.9 ז): המחשב לא בודק אותו, ולכן הוא לא נספר
    const met = side => c.conditions.filter(x => x.side === side && x.met !== null);
    const count = side => `${met(side).filter(x => x.met).length} מתוך ${met(side).length}`;
    const manual = c.conditions.filter(x => x.met === null);
    const auto = c.conditions.filter(x => x.met !== null);
    const verdict = c.bias === 'none'
      ? `לפי ${c.method.name} — אין כרגע כיוון לפי כללי השיטה (לונג ${count('long')}, שורט ${count('short')})`
      : `לפי ${c.method.name} — תנאי ה${SIDE[c.bias]} מתקיימים (${count(c.bias)})` + (c.signal_ago != null ? ` · האות לפני ${c.signal_ago} נרות` : '');
    const out = [
      h('p', { class: 'verdict ' + c.bias }, h('span', { class: 'dir-ic ' + c.bias, 'aria-hidden': 'true' }, c.bias === 'long' ? '↗' : c.bias === 'short' ? '↘' : '–'), h('b', {}, verdict)),
      h('ul', { class: 'conds' }, auto.map(x => h('li', { class: x.met ? 'met' : '' }, h('span', { 'aria-hidden': 'true' }, x.met ? '✓' : '○'),
        h('span', {}, x.text), h('small', { class: 'muted' }, SIDE[x.side]), h('span', { class: 'sr-only' }, x.met ? 'מתקיים' : 'לא מתקיים')))),
      manual.length ? h('div', { class: 'manual-conds' }, h('b', {}, '🔎 בדוק בעצמך — מה שהמחשב כאן לא בודק'),
        h('ul', { class: 'conds' }, manual.map(x => h('li', { class: 'manual' }, h('span', { 'aria-hidden': 'true' }, '?'), h('span', {}, x.text),
          h('small', { class: 'muted' }, 'לא נבדק במחשב'))))) : null,
      c.basis === 'warmup' ? h('p', { class: 'hint warn' }, 'חושב בלי בדיקה לאחור — מחלון הנרות האחרון, ולא ממצב המנוע. האותות כאן עלולים להיות שונים מאלה שבבדיקה לאחור.') : null,
      c.param_notes ? h('details', { class: 'param-notes' }, h('summary', {}, 'הפרמטרים — מה מהספר ומה הגדרה שלנו'),
        h('ul', {}, Object.values(c.param_notes).map(x => h('li', {}, x.label, ' — ', h('b', {}, x.note))))) : null,
    ];
    if (c.levels) {
      out.push(h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'כניסה (לפי השיטה)'), h('dd', { class: 'num' }, D.fmt(c.levels.entry, 10))),
        h('div', {}, h('dt', {}, 'סטופ'), h('dd', { class: 'num' }, D.fmt(c.levels.stop, 10))),
        c.levels.target ? h('div', {}, h('dt', {}, 'רמת יציאה ברווח'), h('dd', { class: 'num' }, D.fmt(c.levels.target, 10))) : null),
        h('p', { class: 'hint', style: 'margin:0' }, 'רמות לפי כללי השיטה, מעוגלות ל-tick של הנכס.'));
    }
    if (c.risk) {
      const rk = c.risk;
      out.push(h('div', { class: 'line-wrap' },
        rk.level ? h('span', { class: 'chip ' + { low: 'ok', medium: 'warn', high: 'bad' }[rk.level] }, `סיכון בעסקה: ${RISK[rk.level]} (סטופ ${rk.stop_atr} ATR)`) : null,
        rk.rr ? h('span', { class: 'chip' + (rk.rr_below_min ? ' warn' : '') }, `יחס סיכוי/סיכון ${D.fmt(rk.rr, 2)}` + (rk.rr_below_min ? ` — מתחת למינימום שלך (${D.fmt(r.profile.min_rr, 2)})` : '')) : null),
        rk.position_qty ? h('p', { class: 'hint', style: 'margin:0' }, 'גודל פוזיציה לפי הסיכון שבפרופיל (', num(D.fmt(rk.risk_pct, 4) + '%'), '): ', num(D.fmt(rk.position_qty, 8)), ' יחידות')
          : h('p', { class: 'hint', style: 'margin:0' }, 'לגודל פוזיציה — גודל חשבון ב', h('a', { href: '#/profile' }, 'פרופיל'), '.'));
    }
    out.push(h('p', { class: 'hint', style: 'margin:0' }, 'נכון לנר שנסגר ב-' + when(c.as_of)));
    return out;
  }

  /** האופק של השיטה (6.4) — שייך לתרחיש: עד מתי ההמשך האפשרי */
  const horizon = c => h('p', { class: 'muted', style: 'margin:0' }, 'אופק: ' + c.horizon_text,
    c.max_horizon && c.max_horizon.tf !== c.tf ? [sep(), 'הארוך ביותר שנבדק: ' + c.max_horizon.text] : null);

  /** "📓 פתח עסקה מזה" ו"🔔 התראה מזה" — מהממצא בלבד, לא מהתרחיש (קריטריון 5) */
  function actions(c, r, alertable) {
    if (c.status !== 'ok') return [];
    return [
      c.levels && c.bias !== 'none' ? h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => openTrade(c, r) } }, '📓 פתח עסקה מזה') : null,
      alertable ? alertBtn({ asset: r.asset, tf: c.tf, kind: 'method', method_id: c.method.id, side: c.bias === 'none' ? 'both' : c.bias }) : null,
    ].filter(Boolean);
  }

  /** שקלול (6.5) — רק מהממצא והוודאות של כל שיטה. התרחיש לא נכנס */
  function blendCard(r, alertable = false) {
    const b = r.blend;
    const name = id => r.cards.find(c => c.method.id === id)?.method.name || id;
    const box = h('section', { class: 'block stack blend-card', 'aria-label': 'שקלול' }, h('div', { class: 'card-head' }, h('b', {}, '⚖️ שקלול השיטות')));
    if (b.score === null) {
      box.append(h('p', { class: 'hint' }, 'אין שקלול: לאף שיטה שנבחרה אין עדיין ודאות (30 אותות לפחות).'));
      return box;
    }
    const lean = b.lean === 'long' ? 'נוטה ללונג' : b.lean === 'short' ? 'נוטה לשורט' : 'בלי נטייה';
    const pos = (b.score + 1) / 2 * 100;   // −1 … +1 → 0 … 100
    box.append(...[
      h('div', { class: 'line' }, h('span', {}, 'ציון כיוון: ', h('b', {}, lean), ', ', num((b.score >= 0 ? '+' : '') + b.score.toFixed(2)))),
      h('div', { class: 'score-bar', role: 'img', 'aria-label': `ציון כיוון ${b.score.toFixed(2)} מתוך טווח מינוס 1 עד פלוס 1` },
        h('span', { class: 'mid' }), h('span', { class: 'dot', style: `inset-inline-start:calc(${100 - pos}% - 7px)` })),
      h('div', { class: 'score-ends muted' }, h('span', {}, 'לונג +1'), h('span', {}, 'שורט −1')),
      h('p', { style: 'margin:0' }, `${b.counts.long} מ-${b.total} שיטות לונג`, sep(), `${b.counts.short} שורט`, sep(), `${b.counts.none} בלי כיוון`),
      b.strong_disagreement ? h('p', { class: 'chip bad' }, '⚠️ מחלוקת חזקה בין השיטות — גם הלונג וגם השורט מחזיקים 30% מהמשקל או יותר') : null,
      b.excluded.length ? h('p', { class: 'hint', style: 'margin:0' }, 'לא בשקלול (פחות מ-30 אותות): ' + b.excluded.map(name).join(', ')) : null,
      h('p', { class: 'hint', style: 'margin:0' }, 'משקל = ודאות × העדפה (הסדר ב"שיטות מועדפות" בפרופיל). התרחישים לא נכנסים לשקלול.'),
      alertable ? h('div', {}, alertBtn({ asset: r.asset, tf: r.tf, kind: 'blend', methods: r.cards.map(c => c.method.id) })) : null]
      .filter(Boolean));   // Element.append כותב null כטקסט "null"
    return box;
  }

  return { finding, probability, horizon, actions, blendCard, biasWord };
}
