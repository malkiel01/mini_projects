/*
 * שלב 7 (docs/spec.md §8) — המכסה והשימוש, בעיני המשתמש.
 *
 * כרטיס: "$X מתוך $Y · נשארו $Z" + פס, ומתחתיו עמודות יומיות מוערמות לפי סוג
 * (פיתוח / תשובות / מעקב) ושורה לפרויקט. SVG בקוד, לפי הסקיל dataviz:
 * שלושת הצבעים הראשונים של הפלטה המאומתת (עוברים גם בעיוורון צבעים, בשני
 * המצבים), פס דק, רווח של 2px בין מקטעים, ומקרא תמיד. ליד כל עמודה — tooltip,
 * ומתחת — טבלה לקורא מסך.
 */
import { el, api } from './core.js';

export const KIND_LABEL = { develop: 'פיתוח', answer: 'תשובות', monitor: 'מעקב' };
const KINDS = ['develop', 'answer', 'monitor'];
const SVG = 'http://www.w3.org/2000/svg';
const usd = (n) => `$${Number(n || 0).toFixed(Number(n) >= 100 ? 0 : 2)}`;

function svg(tag, attrs = {}, children = []) {
  const n = document.createElementNS(SVG, tag);
  for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined) n.setAttribute(k, String(v));
  for (const c of [].concat(children)) if (c) n.append(c);
  return n;
}

export async function loadUsage(userId = null) {
  return api('usage-summary', userId ? { user_id: userId } : {});
}

/** הפס: ok — צבע המותג; warn (80%) — צהוב; over — אדום. */
export function quotaBar(s, { compact = false } = {}) {
  const pct = Math.max(0, Math.min(100, Number(s.pct) || 0));
  const wrap = el('div', { class: `qbar qbar--${s.state}${compact ? ' qbar--compact' : ''}`, role: 'meter',
    'aria-valuemin': '0', 'aria-valuemax': String(s.quota), 'aria-valuenow': String(s.used),
    'aria-label': s.state === 'off' ? 'אין מכסה' : `${usd(s.used)} מתוך ${usd(s.quota)}` });
  const fill = el('span', { class: 'qbar-fill' });
  fill.style.inlineSize = `${pct}%`;
  wrap.append(fill);
  return wrap;
}

function headline(s) {
  if (s.state === 'off') return '☁️ כבוי — לא הוגדרה מכסה חודשית';
  return `${usd(s.used)} מתוך ${usd(s.quota)} · נשארו ${usd(s.left)}`;
}

function stateLine(s) {
  if (s.state === 'off') return 'מנהל הלוח מגדיר מכסה ב: ניהול ← משתמשים ← "מכסה חודשית ($)". עד אז Platform לא עובד בשבילך — הצ\'אטים כן.';
  if (s.state === 'over') return 'המכסה נגמרה. עד 1 לחודש: הרצות במנוע נחסמות, והצ\'אטים עונים במקום ☁️ Platform.';
  if (s.state === 'warn') return `נוצלו ${Math.round(s.pct)}% מהמכסה החודשית.`;
  return `מעקב (קטלוג, סידור): ${usd(s.monitor)} מתוך ${usd(s.monitor_cap)} (עד 20% מהמכסה).`;
}

/** העמודות היומיות. בעברית הזמן זורם מימין לשמאל: 1 לחודש — מימין. */
function dailyChart(s) {
  const days = s.daily;
  const W = 340, H = 120, top = 8, bottom = 18, left = 4, right = 34;   // ימין — תוויות הציר
  const plotW = W - left - right, plotH = H - top - bottom;
  const slot = plotW / days.length;
  const barW = Math.max(3, Math.min(24, slot - 2));
  const totals = days.map((d) => KINDS.reduce((a, k) => a + (Number(d[k]) || 0), 0));
  const max = Math.max(...totals, 0);
  const niceMax = max <= 0 ? 1 : (() => { const p = 10 ** Math.floor(Math.log10(max)); return Math.ceil(max / p) * p; })();
  const y = (v) => top + plotH - (v / niceMax) * plotH;
  const xOf = (i) => left + plotW - (i + 1) * slot + (slot - barW) / 2;   // יום 1 מימין

  // ltr: הגיאומטריה מחושבת בקואורדינטות; בלי זה תוויות הציר "מתהפכות" ונחתכות בדף RTL
  const root = svg('svg', { viewBox: `0 0 ${W} ${H}`, class: 'uchart', role: 'img', direction: 'ltr',
    'aria-label': `שימוש יומי החודש, עד ${usd(niceMax)} ליום` });
  // רשת שקטה: אפס וחצי ומקסימום
  for (const v of [0, niceMax / 2, niceMax]) {
    root.append(svg('line', { x1: left, x2: left + plotW, y1: y(v), y2: y(v), class: v === 0 ? 'u-base' : 'u-grid' }));
    root.append(svg('text', { x: W - 2, y: y(v) + 3, class: 'u-tick', 'text-anchor': 'end' }, [document.createTextNode(usd(v))]));
  }
  days.forEach((d, i) => {
    const x = xOf(i);
    const g = svg('g', { class: 'u-day', tabindex: '-1' });
    let acc = 0;
    const segs = KINDS.filter((k) => Number(d[k]) > 0);
    segs.forEach((k, j) => {
      const v = Number(d[k]);
      const y0 = y(acc), y1 = y(acc + v);
      const h = Math.max(1, y0 - y1 - (j < segs.length - 1 ? 0 : 0));
      const last = j === segs.length - 1;
      const gap = j > 0 ? 2 : 0;   // רווח של 2px בצבע המשטח בין מקטעים
      const r = last ? Math.min(2, barW / 2, h) : 0;
      const yy = y1, hh = Math.max(0.5, h - gap);
      // הקצה העליון מעוגל, הבסיס ישר
      const path = r > 0
        ? `M${x},${yy + hh} V${yy + r} Q${x},${yy} ${x + r},${yy} H${x + barW - r} Q${x + barW},${yy} ${x + barW},${yy + r} V${yy + hh} Z`
        : `M${x},${yy + hh} V${yy} H${x + barW} V${yy + hh} Z`;
      g.append(svg('path', { d: path, class: `u-seg u-${k}` }));
      acc += v;
    });
    // אזור מגע רחב מהעמודה, עם tooltip
    const t = totals[i];
    g.append(svg('rect', { x: left + plotW - (i + 1) * slot, y: top, width: slot, height: plotH, class: 'u-hit' }, [
      svg('title', {}, [document.createTextNode(`${d.day} לחודש · ${usd(t)}${t ? ' — ' + KINDS.filter((k) => d[k] > 0).map((k) => `${KIND_LABEL[k]} ${usd(d[k])}`).join(', ') : ''}`)]),
    ]));
    if (d.day === s.today) g.classList.add('is-today');
    root.append(g);
  });
  // תוויות ימים: 1, אמצע, אחרון
  for (const i of [0, Math.floor(days.length / 2), days.length - 1]) {
    root.append(svg('text', { x: xOf(i) + barW / 2, y: H - 4, class: 'u-tick', 'text-anchor': 'middle' }, [document.createTextNode(String(days[i].day))]));
  }
  return root;
}

function legend() {
  return el('ul', { class: 'ulegend', 'aria-label': 'מקרא' }, KINDS.map((k) => el('li', {}, [
    el('span', { class: `sw u-${k}`, 'aria-hidden': 'true' }), el('span', { text: KIND_LABEL[k] }),
  ])));
}

function table(s) {
  const rows = s.daily.filter((d) => KINDS.some((k) => d[k] > 0));
  if (!rows.length) return el('p', { class: 'hint', text: 'עוד אין שימוש החודש.' });
  return el('table', { class: 'utable' }, [
    el('thead', {}, [el('tr', {}, [el('th', { text: 'יום' }), ...KINDS.map((k) => el('th', { text: KIND_LABEL[k] }))])]),
    el('tbody', {}, rows.map((d) => el('tr', {}, [el('td', { text: String(d.day) }), ...KINDS.map((k) => el('td', { text: usd(d[k]) }))]))),
  ]);
}

/** הכרטיס המלא — בהגדרות ← שימוש. */
export function usageCard(s) {
  const projects = s.projects || [];
  return el('div', { class: `ucard ucard--${s.state}` }, [
    el('div', { class: 'u-head' }, [el('b', { text: headline(s) }), s.state !== 'off' ? el('small', { text: `${Math.round(s.pct)}%` }) : null]),
    quotaBar(s),
    el('p', { class: `hint u-state${s.state === 'warn' || s.state === 'over' ? ' is-alert' : ''}`, text: stateLine(s) }),
    el('h3', { text: `שימוש יומי · ${s.month}` }),
    legend(),
    dailyChart(s),
    el('details', { class: 'what' }, [el('summary', { text: 'כטבלה' }), table(s)]),
    el('h3', { text: 'לפי פרויקט' }),
    projects.length ? el('ul', { class: 'list' }, projects.map((p) => el('li', { class: 'urow' }, [
      el('span', { class: 'ic', 'aria-hidden': 'true', text: p.icon || '📁' }),
      el('span', { class: 't', text: p.name }),
      el('small', { text: `${Math.round(p.tokens / 1000).toLocaleString('he-IL')}K טוקנים` }),
      el('b', { text: usd(p.cost) }),
    ]))) : el('p', { class: 'hint', text: 'עוד אין שימוש החודש.' }),
  ]);
}

/** הגרסה המוקטנת — ליד ☁️ במסך הפרויקט: פס ושורה אחת. */
export function usageMini(s, payerName = '') {
  return el('div', { class: `umini umini--${s.state}` }, [
    quotaBar(s, { compact: true }),
    el('small', { text: (payerName ? `${payerName}: ` : '') + headline(s) }),
  ]);
}
