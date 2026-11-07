// לשונית "שוק", ספקי נתונים וממשק המנהל — spec-stage-a פרקים 1.1, 2–4.
// בלי polling (✅13): פנייה לשרת רק מפעולה של המשתמש (פתיחת מסך, חיפוש, "רענן", שמירה). זמן אמת — רק WebSocket
// ציבורי של Binance, ישירות מהדפדפן לספק; נסגר כשהלשונית מוסתרת או כשעוזבים את הגרף, ולא מתחבר מחדש לבד בלולאה.
import * as D from './decimal.js';

// האינדיקטורים בגרף — float, לתצוגה בלבד (עיקרון 6); שום ערך מהם לא נוגע בכסף.

const TF_LABEL = { m1: 'דקה', m5: '5 ד׳', m15: '15 ד׳', m30: '30 ד׳', h1: 'שעה', h4: '4 ש׳', d1: 'יום', w1: 'שבוע', mn1: 'חודש' };
const TF_SEC = { m1: 60, m5: 300, m15: 900, m30: 1800, h1: 3600, h4: 14400, d1: 86400, w1: 604800, mn1: 2678400 };
const RANGES = [['d', 'יום', 86400], ['w', 'שבוע', 7 * 86400], ['m', 'חודש', 30 * 86400], ['q', '3 חודשים', 90 * 86400],
  ['y', 'שנה', 365 * 86400], ['y5', '5 שנים', 5 * 365 * 86400], ['all', 'הכול', null], ['custom', 'מותאם…', null]];
const DEFAULT_RANGE = { m1: 'd', m5: 'w', m15: 'w', m30: 'm', h1: 'q', h4: 'y', d1: 'y5', w1: 'all', mn1: 'all' };
const MARKET_LABEL = { stocks: 'מניות', crypto: 'קריפטו', forex: 'מט"ח', futures: 'חוזים', options: 'אופציות', indices: 'מדדים', commodities: 'סחורות' };
const DELAY_LABEL = { realtime: 'זמן אמת', '15min': 'בעיכוב 15 דק׳', eod: 'סוף יום' };
const IND = [['sma20', 'SMA 20'], ['sma50', 'SMA 50'], ['ema20', 'EMA 20'], ['vol', 'נפח'], ['rsi', 'RSI 14'], ['atr', 'ATR 14']];

// ── אינדיקטורים (float, לתצוגה) ──────────────────────────
export function sma(v, n) {
  const out = []; let s = 0;
  for (let i = 0; i < v.length; i++) { s += v[i]; if (i >= n) s -= v[i - n]; out.push(i >= n - 1 ? s / n : null); }
  return out;
}
export function ema(v, n) {
  const out = []; const k = 2 / (n + 1); let e = null;
  for (let i = 0; i < v.length; i++) {
    if (i < n - 1) { out.push(null); continue; }
    e = e === null ? v.slice(0, n).reduce((a, b) => a + b, 0) / n : v[i] * k + e * (1 - k);
    out.push(e);
  }
  return out;
}
/** RSI של Wilder */
export function rsi(v, n = 14) {
  const out = new Array(v.length).fill(null);
  if (v.length <= n) return out;
  let g = 0, l = 0;
  for (let i = 1; i <= n; i++) { const d = v[i] - v[i - 1]; if (d > 0) g += d; else l -= d; }
  g /= n; l /= n;
  out[n] = l === 0 ? 100 : 100 - 100 / (1 + g / l);
  for (let i = n + 1; i < v.length; i++) {
    const d = v[i] - v[i - 1];
    g = (g * (n - 1) + Math.max(d, 0)) / n;
    l = (l * (n - 1) + Math.max(-d, 0)) / n;
    out[i] = l === 0 ? 100 : 100 - 100 / (1 + g / l);
  }
  return out;
}
/** ATR של Wilder */
export function atr(hh, ll, cc, n = 14) {
  const out = new Array(cc.length).fill(null);
  const tr = cc.map((c, i) => (i === 0 ? hh[0] - ll[0] : Math.max(hh[i] - ll[i], Math.abs(hh[i] - cc[i - 1]), Math.abs(ll[i] - cc[i - 1]))));
  if (cc.length < n) return out;
  let a = tr.slice(0, n).reduce((x, y) => x + y, 0) / n;
  out[n - 1] = a;
  for (let i = n; i < cc.length; i++) { a = (a * (n - 1) + tr[i]) / n; out[i] = a; }
  return out;
}

export function mountMarket(ctx) {
  const { h, api, sheet, confirmSheet, toast, setHead, render, field, numInput, seg, segVal, select, num, sep } = ctx;
  const ME = () => ctx.me();
  let teardownFns = [];
  const onTeardown = fn => teardownFns.push(fn);
  function teardown() { teardownFns.forEach(fn => { try { fn(); } catch { /* ריק */ } }); teardownFns = []; }

  let PROVIDERS = null;
  async function providers(force = false) {
    if (!PROVIDERS || force) PROVIDERS = (await api('market_providers')).providers;
    return PROVIDERS;
  }
  const provName = id => PROVIDERS?.find(p => p.id === id)?.name || id;
  const assetUrl = a => '#/chart/' + encodeURIComponent(a);
  const splitAsset = a => { const i = a.indexOf(':'); return [a.slice(0, i), a.slice(i + 1)]; };

  // ── שוק: חיפוש + המעקב שלי ─────────────────────────────
  async function viewMarket() {
    setHead('שוק', 'גרפים מספקי הנתונים שלך');
    const [provs, { watchlist }] = await Promise.all([providers(), api('watchlist')]);
    const available = provs.filter(p => p.available);
    const results = h('div', { class: 'stack', 'aria-live': 'polite' });
    const q = h('input', { type: 'search', name: 'q', placeholder: 'סימול או שם (BTC, AAPL)', 'aria-label': 'חיפוש נכס', maxlength: 40, autocomplete: 'off' });
    const go = async e => {
      e?.preventDefault();
      const v = q.value.trim();
      if (!v) return;
      results.replaceChildren(h('p', { class: 'loading' }, 'מחפש…'));
      try {
        const r = await api('market_search', null, { q: v });
        results.replaceChildren(
          ...r.errors.map(m => h('p', { class: 'error' }, m)),
          r.results.length ? h('ul', { class: 'list' }, r.results.map(x => resultRow(x, watchlist))) : h('p', { class: 'empty' }, 'לא נמצא. נסו סימול אחר, או חברו עוד ספק ב"עוד ← ספקי נתונים".'));
      } catch (ex) { results.replaceChildren(h('p', { class: 'error', role: 'alert' }, ex.message)); }
    };
    const wl = h('ul', { class: 'list' });
    let editing = false;
    const drawWl = list => {
      wl.replaceChildren(...(list.length ? list.map((w, i) => watchRow(w, i, list)) : [h('li', { class: 'empty' }, 'עוד אין נכסים במעקב. מחפשים, ומסמנים ☆.')]));
    };
    const watchRow = (w, i, list) => {
      const name = w.meta?.name || w.symbol;
      const row = h('li', { class: 'card', tabindex: '0', role: 'button', on: { click: e => { if (!e.target.closest('button')) location.hash = assetUrl(w.asset); }, keydown: e => { if (e.key === 'Enter') location.hash = assetUrl(w.asset); } } },
        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, { crypto: '🪙', forex: '💱', stocks: '🏢', indices: '📊' }[w.meta?.market] || '📈'),
        h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', { class: 'num' }, w.symbol)),
          h('div', { class: 'l2' }, name !== w.symbol ? [name, sep()] : null, provName(w.provider))),
        editing ? h('span', { class: 'row-btns' },
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'למעלה', disabled: i === 0, on: { click: () => move(list, i, -1) } }, '▲'),
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'למטה', disabled: i === list.length - 1, on: { click: () => move(list, i, 1) } }, '▼'),
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הסרה מהמעקב', on: { click: async () => { const r = await api('watchlist_remove', { asset: w.asset }); watchlist.splice(0, Infinity, ...r.watchlist); drawWl(watchlist); } } }, '✕'))
          : h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'));
      return row;
    };
    const move = async (list, i, d) => {
      const [x] = list.splice(i, 1); list.splice(i + d, 0, x);
      drawWl(list);
      await api('watchlist_order', { assets: list.map(w => w.asset) });
    };
    const editBtn = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'סידור המעקב', on: { click: () => { editing = !editing; drawWl(watchlist); } } }, '✏️');
    drawWl(watchlist);
    const connected = available.map(p => p.name).join(' · ');
    render(
      h('form', { class: 'toolbar', on: { submit: go } }, q, h('button', { class: 'btn primary', type: 'submit' }, '🔎 חיפוש')),
      h('p', { class: 'hint' }, 'מחפשים ב: ', connected || 'אין ספקים', '. ', h('a', { href: '#/providers' }, 'חיבור ספקים')),
      results,
      h('div', { class: 'toolbar', style: 'justify-content:space-between;margin-top:12px' }, h('h2', { class: 'sec-title' }, '⭐ המעקב שלי'), watchlist.length ? editBtn : null),
      wl);
  }

  function resultRow(x, watchlist) {
    const inWl = () => watchlist.some(w => w.asset === x.asset);
    const star = h('button', { class: 'icon-btn', type: 'button', 'aria-pressed': String(inWl()), 'aria-label': inWl() ? 'הסרה מהמעקב' : 'הוספה למעקב',
      on: { click: async () => {
        try {
          const r = await api(inWl() ? 'watchlist_remove' : 'watchlist_add', { asset: x.asset });
          watchlist.splice(0, Infinity, ...r.watchlist);
          star.textContent = inWl() ? '⭐' : '☆';
          star.setAttribute('aria-pressed', String(inWl()));
          toast(inWl() ? 'נוסף למעקב' : 'הוסר מהמעקב');
        } catch (e) { toast(e.message, true); }
      } } }, inWl() ? '⭐' : '☆');
    const alt = x.alternatives.length ? h('button', { type: 'button', class: 'chip', on: { click: () => chooseProvider(x) } }, `+${x.alternatives.length} ספקים`) : null;
    return h('li', { class: 'card', style: 'cursor:default' },
      h('a', { class: 'body', href: assetUrl(x.asset), style: 'text-decoration:none;color:inherit' },
        h('div', { class: 'l1' }, h('span', { class: 'num' }, x.symbol), h('span', { class: 't' }, MARKET_LABEL[x.market] || x.market)),
        h('div', { class: 'l2' }, x.name, sep(), provName(x.provider), x.exchange && x.exchange !== provName(x.provider) ? [sep(), x.exchange] : null)),
      alt, star);
  }
  let route = null;

  function chooseProvider(x) {
    const s = sheet(`${x.symbol} — מאיזה ספק?`);
    const all = [{ asset: x.asset, provider: x.provider, name: x.name }, ...x.alternatives];
    s.body.append(h('p', { class: 'hint' }, 'אותו סימול אצל כמה ספקים. הבחירה נשמרת — בחיפוש הבא יוצג הספק הזה.'),
      h('ul', { class: 'list' }, all.map(a => h('li', {}, h('button', { class: 'use-row', type: 'button', on: { click: async () => {
        await api('symbol_pref', { asset: a.asset }); s.close(); location.hash = assetUrl(a.asset);
      } } }, h('span', { class: 'use-tx' }, h('b', {}, provName(a.provider)), h('small', {}, a.name)), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))))));
  }

  // ── גרף ─────────────────────────────────────────────────
  async function viewChart(asset) {
    const [provider, symbol] = splitAsset(asset);
    const provs = await providers();
    const p = provs.find(x => x.id === provider);
    setHead(symbol, p ? p.name : provider, { back: true });
    if (!p?.available) {
      render(h('p', { class: 'error' }, 'אין לכם חיבור לספק הזה. '), h('a', { class: 'btn ghost', href: '#/providers' }, 'ספקי נתונים'));
      return;
    }
    const key = 'ts-chart:' + asset;
    let st = {};
    try { st = JSON.parse(sessionStorage.getItem(key) || '{}'); } catch { /* ריק */ }
    const tfs = p.tfs;
    st.tf = tfs.includes(st.tf) ? st.tf : (tfs.includes('h1') ? 'h1' : tfs.includes('d1') ? 'd1' : tfs[0]);
    st.range ??= DEFAULT_RANGE[st.tf];
    st.ind ??= ['sma20', 'vol'];
    const save = () => { try { sessionStorage.setItem(key, JSON.stringify(st)); } catch { /* ריק */ } };

    const { watchlist } = await api('watchlist');
    let inWl = watchlist.some(w => w.asset === asset);
    const star = h('button', { class: 'icon-btn', type: 'button', 'aria-label': inWl ? 'הסרה מהמעקב' : 'הוספה למעקב', 'aria-pressed': String(inWl), on: { click: async () => {
      try {
        await api(inWl ? 'watchlist_remove' : 'watchlist_add', { asset });
        inWl = !inWl; star.textContent = inWl ? '⭐' : '☆'; star.setAttribute('aria-pressed', String(inWl));
        toast(inWl ? 'נוסף למעקב' : 'הוסר מהמעקב');
      } catch (e) { toast(e.message, true); }
    } } }, inWl ? '⭐' : '☆');
    const refresh = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'רענן', title: 'רענן', on: { click: () => load(true) } }, '↻');
    setHead(symbol, p.name, { back: true, actions: [refresh, star] });

    const tfSeg = seg('tf', tfs.map(t => [t, TF_LABEL[t]]), st.tf, { label: 'מסגרת זמן', onChange: v => { st.tf = v; st.range = DEFAULT_RANGE[v]; rangeSel.value = st.range; save(); load(); } });
    const rangeSel = select('range', RANGES.map(([k, l]) => [k, l]), st.range, { 'aria-label': 'טווח' });
    const from = h('input', { type: 'date', 'aria-label': 'מתאריך', value: st.from || '' });
    const to = h('input', { type: 'date', 'aria-label': 'עד תאריך', value: st.to || '' });
    const custom = h('div', { class: 'fields two', hidden: st.range !== 'custom' }, field('מתאריך', from), field('עד תאריך', to));
    rangeSel.addEventListener('change', () => { st.range = rangeSel.value; custom.hidden = st.range !== 'custom'; save(); if (st.range !== 'custom') load(); });
    [from, to].forEach(el => el.addEventListener('change', () => { st.from = from.value; st.to = to.value; save(); if (from.value) load(); }));
    const inds = h('div', { class: 'chips', role: 'group', 'aria-label': 'אינדיקטורים' }, IND.map(([k, l]) => {
      const c = h('input', { type: 'checkbox', value: k });
      c.checked = st.ind.includes(k);
      c.addEventListener('change', () => { st.ind = [...inds.querySelectorAll('input:checked')].map(i => i.value); save(); drawIndicators(); });
      return h('label', {}, c, h('span', {}, l));
    }));

    const info = h('p', { class: 'hint', 'aria-live': 'polite' });
    const live = h('span', { class: 'chip', hidden: true });
    const box = h('div', { class: 'chart-box' });
    const oscBox = h('div', { class: 'chart-box osc', hidden: true });
    const head = h('div', { class: 'quote-head' });
    render(head, h('div', { class: 'chart-tools' }, tfSeg, h('div', { class: 'toolbar' }, rangeSel, live)), custom,
      h('div', { class: 'chart-wrap' }, box, oscBox), inds, info,
      h('p', { class: 'disclaimer' }, '⚖️ נתוני שוק להצגה בלבד. אינו ייעוץ השקעות. ', h('span', {}, p.terms)));

    const css = getComputedStyle(document.documentElement);
    const col = n => css.getPropertyValue(n).trim();
    const tz = ME().user.timezone;
    const fmtT = (t, opts) => { try { return new Date(t * 1000).toLocaleString('he-IL', { timeZone: tz, ...opts }); } catch { return ''; } };
    const LW = window.LightweightCharts;
    const base = {
      autoSize: true,
      layout: { background: { color: col('--surface') }, textColor: col('--muted'), fontFamily: 'system-ui, sans-serif', attributionLogo: true },
      grid: { vertLines: { color: col('--line') }, horzLines: { color: col('--line') } },
      rightPriceScale: { borderColor: col('--line') },
      // minBarSpacing נמוך — כדי ש"5 שנים" (1,825 נרות) ייכנסו כולם גם ברוחב של טלפון
      timeScale: { borderColor: col('--line'), timeVisible: TF_SEC[st.tf] < 86400, secondsVisible: false, minBarSpacing: 0.05,
        // סוג הסימון מהספרייה: 0 שנה · 1 חודש · 2 יום · 3 שעה — כך רואים תאריך במעבר יום, ושעה בתוכו
        tickMarkFormatter: (t, type) => fmtT(t, type === 0 ? { year: 'numeric' } : type === 1 ? { month: 'short' }
          : type === 2 ? { day: 'numeric', month: 'numeric' } : { hour: '2-digit', minute: '2-digit' }) },
      localization: { timeFormatter: t => fmtT(t, { day: 'numeric', month: 'numeric', year: '2-digit', ...(TF_SEC[st.tf] < 86400 ? { hour: '2-digit', minute: '2-digit' } : {}) }) },
      crosshair: { mode: 0 },
    };
    const chart = LW.createChart(box, base);
    const up = col('--ok'), down = col('--danger');
    const candle = chart.addCandlestickSeries({ upColor: up, downColor: down, wickUpColor: up, wickDownColor: down, borderVisible: false });
    const volume = chart.addHistogramSeries({ priceScaleId: 'vol', priceFormat: { type: 'volume' }, lastValueVisible: false, priceLineVisible: false });
    chart.priceScale('vol').applyOptions({ scaleMargins: { top: 0.82, bottom: 0 } });
    const lines = {
      sma20: chart.addLineSeries({ color: col('--brand'), lineWidth: 2, priceLineVisible: false, lastValueVisible: false }),
      sma50: chart.addLineSeries({ color: col('--star'), lineWidth: 2, priceLineVisible: false, lastValueVisible: false }),
      ema20: chart.addLineSeries({ color: col('--muted'), lineWidth: 2, lineStyle: 2, priceLineVisible: false, lastValueVisible: false }),
    };
    const osc = LW.createChart(oscBox, { ...base, timeScale: { ...base.timeScale, visible: false } });
    const oscSeries = osc.addLineSeries({ color: col('--brand'), lineWidth: 2, priceLineVisible: false });
    chart.timeScale().subscribeVisibleLogicalRangeChange(r => { if (r && !oscBox.hidden) osc.timeScale().setVisibleLogicalRange(r); });
    onTeardown(() => { chart.remove(); osc.remove(); });

    let data = [];
    let ws = null;
    function drawIndicators() {
      const c = data.map(x => x.close), hh = data.map(x => x.high), ll = data.map(x => x.low);
      const pts = arr => arr.map((v, i) => (v === null ? null : { time: data[i].time, value: v })).filter(Boolean);
      for (const k of ['sma20', 'sma50', 'ema20']) {
        const on = st.ind.includes(k);
        lines[k].setData(on ? pts(k === 'ema20' ? ema(c, 20) : sma(c, k === 'sma50' ? 50 : 20)) : []);
      }
      volume.setData(st.ind.includes('vol') ? data.map(x => ({ time: x.time, value: x.volume, color: x.close >= x.open ? up + '66' : down + '66' })) : []);
      const which = st.ind.includes('rsi') ? 'rsi' : st.ind.includes('atr') ? 'atr' : null;
      oscBox.hidden = !which;
      if (which) {
        oscSeries.setData(pts(which === 'rsi' ? rsi(c) : atr(hh, ll, c)));
        osc.timeScale().setVisibleLogicalRange(chart.timeScale().getVisibleLogicalRange() || { from: 0, to: data.length });
      }
    }
    function priceFormat(rows) {
      let p = 2;
      for (const r of rows.slice(-50)) { const d = (r.c.split('.')[1] || '').replace(/0+$/, '').length; p = Math.max(p, Math.min(d, 8)); }
      return { type: 'price', precision: p, minMove: 1 / 10 ** p };
    }
    function rangeQuery() {
      const now = Math.floor(Date.now() / 1000);
      if (st.range === 'custom' && st.from) {
        const f = Math.floor(new Date(st.from + 'T00:00:00Z').getTime() / 1000);
        const t = st.to ? Math.floor(new Date(st.to + 'T23:59:59Z').getTime() / 1000) : now;
        return { from: f, to: t };
      }
      if (st.range === 'all') return { from: 0, to: now };
      const span = RANGES.find(r => r[0] === st.range)?.[2] || 30 * 86400;
      return { from: now - span, to: now };
    }
    function setHeadQuote() {
      const last = data.at(-1), prev = data.at(-2);
      if (!last) { head.replaceChildren(); return; }
      const ch = prev ? (last.close - prev.close) / prev.close * 100 : null;   // תצוגה בלבד
      head.replaceChildren(h('b', { class: 'num price' }, D.fmt(last.closeStr, 8)),
        ch !== null ? h('span', { class: 'num ' + (ch >= 0 ? 'pos' : 'neg') }, (ch >= 0 ? '+' : '') + ch.toFixed(2) + '%') : null,
        h('span', { class: 'muted' }, fmtT(last.time, { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' })));
    }
    let loading = false;
    async function load(isRefresh = false) {
      if (loading) return;
      loading = true;
      refresh.disabled = true;
      closeLive();
      info.textContent = 'טוען…';
      try {
        chart.applyOptions({ timeScale: { timeVisible: TF_SEC[st.tf] < 86400 } });
        const r = await api('market_candles', null, { asset, tf: st.tf, ...rangeQuery() });
        data = r.candles.map(x => ({ time: x.t, open: +x.o, high: +x.h, low: +x.l, close: +x.c, volume: +x.v, closeStr: x.c }));
        candle.applyOptions({ priceFormat: priceFormat(r.candles) });
        candle.setData(data.map(({ time, open, high, low, close }) => ({ time, open, high, low, close })));
        drawIndicators();
        chart.timeScale().fitContent();
        setHeadQuote();
        const n = data.length;
        info.replaceChildren(`${n.toLocaleString('he-IL')} נרות · ${TF_LABEL[st.tf]} · ${DELAY_LABEL[r.delay] || r.delay}`,
          r.capped ? ' · מוצגים 5,000 הנרות האחרונים — לטווח ארוך בחרו מסגרת גדולה יותר' : '',
          r.stream ? '' : ` · "↻" מושך עדכון (תוקף ${r.tail_ttl < 120 ? 'דקה' : Math.round(r.tail_ttl / 60) + ' דק׳'})`,
          n ? '' : ' · אין נתונים בטווח הזה');
        if (isRefresh) toast(r.provider_calls ? 'עודכן מהספק' : 'עודכן מהמאגר');
        if (r.stream && n) openLive(r.stream, r.stream_interval);
      } catch (e) {
        info.textContent = '';
        info.append(h('span', { class: 'error' }, e.message));
      } finally {
        loading = false;
        refresh.disabled = false;
      }
    }
    // WebSocket ציבורי של Binance — ישירות לספק. אין התחברות חוזרת אוטומטית בלולאה: נותק → הודעה, ו"↻" מחבר.
    let streamSpec = null;
    function openLive(spec, interval) {
      streamSpec = [spec, interval];
      if (document.hidden || spec.kind !== 'binance-kline') return;
      const url = spec.url.replace('{symbol}', symbol.toLowerCase()).replace('{interval}', interval);
      ws = new WebSocket(url);
      live.hidden = false; live.className = 'chip warn'; live.textContent = '… מתחבר';
      ws.onopen = () => { live.className = 'chip ok'; live.textContent = '● חי'; };
      ws.onmessage = ev => {
        let k; try { k = JSON.parse(ev.data).k; } catch { return; }
        if (!k) return;
        const bar = { time: Math.floor(k.t / 1000), open: +k.o, high: +k.h, low: +k.l, close: +k.c, volume: +k.v, closeStr: k.c };
        const last = data.at(-1);
        if (last && bar.time < last.time) return;
        if (last && bar.time === last.time) data[data.length - 1] = bar; else data.push(bar);
        candle.update({ time: bar.time, open: bar.open, high: bar.high, low: bar.low, close: bar.close });
        if (st.ind.includes('vol')) volume.update({ time: bar.time, value: bar.volume, color: bar.close >= bar.open ? up + '66' : down + '66' });
        setHeadQuote();
      };
      ws.onclose = () => { if (ws) { ws = null; live.className = 'chip bad'; live.textContent = 'נותק — ↻'; } };
    }
    function closeLive() { if (ws) { const w = ws; ws = null; w.close(); } live.hidden = true; }
    const vis = () => { if (document.hidden) { closeLive(); live.hidden = false; live.className = 'chip'; live.textContent = 'מושהה'; } else if (streamSpec && !ws) openLive(...streamSpec); };
    document.addEventListener('visibilitychange', vis);
    onTeardown(() => { document.removeEventListener('visibilitychange', vis); streamSpec = null; closeLive(); });
    await load();
  }

  // ── ספקי נתונים (המשתמש) ───────────────────────────────
  async function viewProviders() {
    setHead('ספקי נתונים', 'מאיפה מגיעים הגרפים', { back: true });
    const provs = await providers(true);
    const fmt = t => (t ? new Date(t * 1000).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
    const row = p => {
      let status, actions = null;
      if (!p.enabled) status = h('span', { class: 'chip' }, 'כבוי על ידי המנהל');
      else if (!p.needs_key) status = h('span', { class: 'chip ok' }, '✓ פתוח לכולם, בלי מפתח');
      else if (p.own) {
        status = h('span', { class: 'chip ' + (p.own.last_test_ok ? 'ok' : 'bad') }, `${p.own.last_test_ok ? 'מחובר' : 'החיבור נכשל'} · נבדק ${fmt(p.own.last_test_at)} · ••••${p.own.last4}`);
        actions = h('div', { class: 'row-btns wrap' },
          h('button', { class: 'btn ghost sm', type: 'button', on: { click: async e => { e.target.disabled = true; try { await api('cred_test', { provider: p.id }); toast('החיבור תקין'); } catch (ex) { toast(ex.message, true); } PROVIDERS = null; route(); } } }, 'בדוק חיבור'),
          h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => connect(p) } }, 'החלפת מפתח'),
          h('button', { class: 'btn ghost sm danger', type: 'button', on: { click: () => confirmSheet('ניתוק ספק', `המפתח של ${p.name} יימחק, וגם הנתונים שנמשכו בו.`, 'ניתוק', async () => { await api('cred_delete', { provider: p.id }); PROVIDERS = null; toast('נותק'); route(); }) } }, 'ניתוק'));
      } else if (p.source === 'shared') status = h('span', { class: 'chip ok' }, '✓ מהמנהל');
      else {
        status = h('span', { class: 'chip warn' }, 'לא מחובר');
        actions = h('button', { class: 'btn primary sm', type: 'button', on: { click: () => connect(p) } }, 'חיבור');
      }
      return h('li', { class: 'card', style: 'cursor:default;flex-wrap:wrap' },
        h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, p.name), h('span', { class: 't' }, DELAY_LABEL[p.delay] || '')),
          h('div', { class: 'l2' }, p.markets.map(m => MARKET_LABEL[m] || m).join(', '), sep(), p.tfs.map(t => TF_LABEL[t]).join(' · ')),
          h('div', { style: 'margin-top:6px' }, status)),
        actions);
    };
    render(h('p', { class: 'hint' }, 'Binance ו-ECB פתוחים לכולם. לספק עם מפתח — מחברים מפתח משלכם: הוא נשמר מוצפן בשרת, משמש רק לבקשות שלכם, ולא מוצג שוב (רק ארבעת התווים האחרונים).'),
      h('ul', { class: 'list' }, provs.map(row)));
  }

  function connect(p) {
    const secret = h('input', { type: 'password', name: 'secret', autocomplete: 'off', spellcheck: 'false', dir: 'ltr', maxlength: 200, required: true });
    const label = h('input', { name: 'label', maxlength: 60, placeholder: 'רשות — למשל "החשבון החינמי"' });
    const s = sheet(`חיבור ${p.name}`, { saveLabel: 'בדיקה ושמירה', onSave: async () => {
      await api('cred_save', { provider: p.id, secret: secret.value, label: label.value });
      secret.value = '';
      PROVIDERS = null;
      toast(`${p.name} מחובר`);
      route();
    } });
    s.body.append(
      p.key_help?.note ? h('p', { class: 'hint' }, p.key_help.note) : null,
      p.key_help?.url ? h('p', {}, h('a', { href: p.key_help.url, target: '_blank', rel: 'noopener noreferrer' }, 'לקבלת מפתח ↗')) : null,
      h('div', { class: 'fields' }, field(p.key_help?.label || 'מפתח', secret, true), field('תווית', label, true)),
      h('p', { class: 'hint' }, 'בשמירה המפתח נבדק מול הספק. הוא נשמר מוצפן, ולא יוצג שוב.'));
  }

  // ── ממשק המנהל (1.1) — השרת בודק בכל פעולה; כאן רק התצוגה ──────
  async function viewAdmin() {
    setHead('ניהול', 'ספקים, מנהלים ומאגר', { back: true });
    const S = await api('admin_state');
    let tab = sessionStorage.getItem('ts-admin-tab') || 'providers';
    const tabs = [['providers', '🔌 ספקים'], ['shared', '🤝 לחלוקה'], ['admins', '👤 מנהלים'], ['cache', '🗄️ מאגר'], ['log', '📜 יומן']];
    const bar = h('div', { class: 'seg-tabs', role: 'tablist' }, tabs.map(([k, l]) => h('button', { role: 'tab', type: 'button', 'aria-selected': String(k === tab), on: { click: () => { sessionStorage.setItem('ts-admin-tab', k); route(); } } }, l)));
    const body = { providers: adminProviders, shared: adminShared, admins: adminAdmins, cache: adminCache, log: adminLog }[tab](S);
    render(bar, body);
  }
  const reload = () => route();

  function adminProviders(S) {
    return h('ul', { class: 'list' }, S.providers.map(p => {
      const on = h('input', { type: 'checkbox' }); on.checked = p.enabled;
      const day = numInput('per_day', p.overrides.per_day ?? '', String(p.default_limits.per_day));
      const min = numInput('per_min', p.overrides.per_min ?? '', String(p.default_limits.per_min));
      return h('li', { class: 'card', style: 'cursor:default;display:block' },
        h('div', { class: 'l1' }, h('span', {}, p.name), h('span', { class: 't' }, p.needs_key ? 'עם מפתח' : 'בלי מפתח')),
        h('p', { class: 'hint', style: 'margin:4px 0' }, p.terms),
        h('label', { class: 'switch' }, on, h('span', {}, 'מופעל')),
        h('div', { class: 'fields two' }, field('תקרה ליום (ריק = ברירת מחדל)', day), field('תקרה לדקה', min)),
        h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => {
          try { await api('admin_provider_save', { provider: p.id, enabled: on.checked, per_day: day.value.trim(), per_min: min.value.trim() }); toast('נשמר'); reload(); }
          catch (e) { toast(e.message, true); }
        } } }, 'שמירה'));
    }));
  }

  function adminShared(S) {
    const sw = h('input', { type: 'checkbox', disabled: !S.is_primary_admin }); sw.checked = S.shared_enabled;
    sw.addEventListener('change', () => confirmSheet(sw.checked ? 'הפעלת ספקים לחלוקה' : 'כיבוי ספקים לחלוקה',
      sw.checked ? 'להפעיל רק אחרי שנבחרה תוכנית מסחרית שמתירה הצגה לצד שלישי (הכרעה ✅2). הספקים סופרים מי רואה את הנתונים, לא רק מי שמושך.' : 'משתמשים שקיבלו ספק מהמנהל יאבדו אליו גישה מיד.',
      sw.checked ? 'הפעלה' : 'כיבוי', async () => { await api('admin_shared_switch', { on: sw.checked }); reload(); }));
    const userOpts = S.users.map(u => [u.id, u.display_name || u.email || ('#' + u.id)]);
    const credRow = c => {
      const user = select('user', userOpts, '', { 'aria-label': 'משתמש' });
      const lim = numInput('daily_limit', '100');
      return h('li', { class: 'card', style: 'cursor:default;display:block' },
        h('div', { class: 'l1' }, h('span', {}, provName(c.provider) + (c.label ? ' · ' + c.label : '')), h('span', { class: 't num' }, '••••' + c.last4)),
        h('ul', { class: 'stack', style: 'list-style:none;margin:8px 0' }, c.grants.length ? c.grants.map(g => h('li', { class: 'toolbar' },
          h('span', { style: 'flex:1' }, g.name || g.email, ' · ', num(String(g.daily_limit)), ' ביום'),
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'ביטול שיוך', on: { click: async () => { await api('admin_grant_delete', { cred_id: c.id, user_id: g.user_id }); reload(); } } }, '✕'))) : h('li', { class: 'muted' }, 'לא שויך לאף משתמש')),
        h('div', { class: 'toolbar' }, user, lim, h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => {
          try { await api('admin_grant_save', { cred_id: c.id, user_id: +user.value, daily_limit: lim.value.trim() }); toast('שויך'); reload(); } catch (e) { toast(e.message, true); }
        } } }, 'שיוך')),
        h('div', { class: 'row-btns' },
          h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => { try { await api('admin_cred_test', { id: c.id }); toast('החיבור תקין'); } catch (e) { toast(e.message, true); } reload(); } } }, 'בדוק חיבור'),
          h('button', { class: 'btn ghost sm danger', type: 'button', on: { click: () => confirmSheet('מחיקת מפתח', 'המפתח והשיוכים שלו יימחקו.', 'מחיקה', async () => { await api('admin_cred_delete', { id: c.id }); reload(); }) } }, 'מחיקה')));
    };
    const add = () => {
      const prov = select('provider', S.providers.filter(p => p.needs_key).map(p => [p.id, p.name]), '');
      const secret = h('input', { type: 'password', autocomplete: 'off', dir: 'ltr', maxlength: 200 });
      const label = h('input', { maxlength: 60 });
      const s = sheet('מפתח לחלוקה', { saveLabel: 'בדיקה ושמירה', onSave: async () => { await api('admin_cred_save', { provider: prov.value, secret: secret.value, label: label.value }); reload(); } });
      s.body.append(h('div', { class: 'fields' }, field('ספק', prov, true), field('מפתח', secret, true), field('תווית', label, true)));
    };
    return h('div', { class: 'stack' },
      h('div', { class: 'block stack' },
        h('label', { class: 'switch' }, sw, h('span', {}, h('b', {}, 'המתג הראשי — ספקים מהמנהל'), S.shared_enabled ? ' · דלוק' : ' · כבוי')),
        h('p', { class: 'hint' }, 'כבוי בשלב א\' (הכרעה ✅2) עד שתיבחר תוכנית מסחרית שמתירה הצגה לצד שלישי. כשכבוי — מפתח ושיוכים נשמרים, אבל אף משתמש לא מקבל דרכם נתונים.',
          S.is_primary_admin ? '' : ' רק המנהל הראשי משנה את המתג.')),
      h('div', { class: 'toolbar' }, h('button', { class: 'btn primary', type: 'button', on: { click: add } }, '+ מפתח לחלוקה')),
      S.creds.length ? h('ul', { class: 'list' }, S.creds.map(credRow)) : h('p', { class: 'empty' }, 'אין מפתחות לחלוקה.'));
  }

  function adminAdmins(S) {
    const user = select('user', S.users.filter(u => !S.admins.some(a => a.user_id === u.id)).map(u => [u.id, u.display_name || u.email]), '', { 'aria-label': 'משתמש' });
    return h('div', { class: 'stack' },
      h('p', { class: 'hint' }, 'המנהל הראשי הוא מנהל הפלטפורמה (מהשער). מנהלים נוספים — רק הוא ממנה ומבטל, והביטול חל מיד.'),
      S.admins.length ? h('ul', { class: 'list' }, S.admins.map(a => h('li', { class: 'card', style: 'cursor:default' },
        h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, a.display_name || a.email)), h('div', { class: 'l2' }, a.email || '')),
        S.is_primary_admin ? h('button', { class: 'btn ghost sm danger', type: 'button', on: { click: async () => { await api('admin_role_remove', { user_id: a.user_id }); reload(); } } }, 'ביטול') : null)))
        : h('p', { class: 'empty' }, 'אין מנהלים נוספים.'),
      S.is_primary_admin ? h('div', { class: 'toolbar' }, user, h('button', { class: 'btn ghost', type: 'button', on: { click: async () => { if (!user.value) return; await api('admin_role_add', { user_id: +user.value }); toast('מונה'); reload(); } } }, 'מינוי')) : null);
  }

  function adminCache(S) {
    const kb = Math.round(S.cache.bytes / 1024);
    return h('div', { class: 'stack' },
      h('div', { class: 'summary' }, h('div', { class: 'line' }, h('span', { class: 'muted' }, 'גודל המאגר'), h('b', { class: 'num' }, kb.toLocaleString('he-IL') + ' KB'),
        h('span', { class: 'muted' }, ' · ניקוי אחרון ' + (S.cache.cleanup_at ? new Date(S.cache.cleanup_at * 1000).toLocaleString('he-IL') : '—')))),
      h('p', { class: 'hint' }, 'המאגר שומר 60 יום אחורה ומתנקה פעם ביום. נתונים שנמשכו במפתח אישי — רק של אותו משתמש.'),
      S.cache.by.length ? h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, h('th', {}, 'ספק'), h('th', {}, 'סוג'), h('th', { class: 'n' }, 'נרות'), h('th', { class: 'n' }, 'הוותיק'))),
        h('tbody', {}, S.cache.by.map(r => h('tr', {}, h('td', {}, provName(r.provider)), h('td', {}, { shared: 'משותף', personal: 'אישי', granted: 'מהמנהל' }[r.kind]),
          h('td', { class: 'n num' }, r.candles.toLocaleString('he-IL')), h('td', { class: 'n num' }, new Date(r.oldest * 1000).toLocaleDateString('he-IL')))))) : h('p', { class: 'empty' }, 'המאגר ריק.'),
      h('div', { class: 'row-btns wrap' },
        h('button', { class: 'btn ghost', type: 'button', on: { click: async () => { const r = await api('admin_cache_cleanup', {}); toast(`נמחקו ${r.deleted} נרות ישנים`); reload(); } } }, '🧹 ניקוי ישנים מ-60 יום'),
        h('button', { class: 'btn ghost danger', type: 'button', on: { click: () => confirmSheet('ריקון המאגר', 'כל הנרות יימחקו. הגרפים יימשכו שוב מהספקים.', 'ריקון', async () => { await api('admin_cache_clear', {}); reload(); }) } }, 'ריקון הכול')));
  }

  function adminLog(S) {
    return S.log.length ? h('ul', { class: 'list' }, S.log.map(l => h('li', { class: 'card', style: 'cursor:default' },
      h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, l.action), h('span', { class: 't' }, new Date(l.at * 1000).toLocaleString('he-IL'))),
        h('div', { class: 'l2' }, (l.display_name || '') + (l.target ? ' · ' + l.target : ''))))))
      : h('p', { class: 'empty' }, 'אין עדיין פעולות מנהל.');
  }

  return {
    viewMarket, viewChart, viewProviders, viewAdmin, teardown,
    setRoute: fn => { route = fn; },
  };
}
