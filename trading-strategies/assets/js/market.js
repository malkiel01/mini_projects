// לשונית "שוק", ספקי נתונים וממשק העורכים — spec-stage-a פרקים 2–4, 24.
// פנייה לשרת מפעולה של המשתמש (פתיחת מסך, חיפוש, "רענן", שמירה) — ובגרף גם **רענון אוטומטי כשהניתוח פעיל** (18.3, ✅23):
// הלשונית גלויה ונבחרה שיטה → בקשה אחת (chart_refresh) כל 60 שניות, או 30 לבחירת המשתמש — נרות ותוצאת כל השיטות יחד.
// לשונית מוסתרת — עוצר, וחוזר מיד כשהיא גלויה. 429 — מדלגים עד הפעם הבאה, בלי הודעה קופצת ובלי ניסיון חוזר.
// זמן אמת למחיר — רק WebSocket ציבורי של Binance, ישירות מהדפדפן לספק; נסגר כשהלשונית מוסתרת או כשעוזבים את הגרף,
// ולא מתחבר מחדש לבד בלולאה.
import * as D from './decimal.js';
import { mountDrawings } from './drawings.js';
import { mountForecast } from './forecast.js';
import { mountChartTip } from './charttip.js';
import { mountAxis, labelBoard, tzOffset } from './chartaxis.js';
import { setTurned, screenRect } from './rotate.js';
import { mountGestures } from './gestures.js';
import { mountCombo, invalidateCatalog } from './combobox.js';

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
  const { h, api, sheet, confirmSheet, toast, setHead, render, field, numInput, seg, segVal, select, num, sep, pairLabel, pairMain, pairSub } = ctx;
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

  // ── שוק: בחירה מרשימה (18.1.3) ולשוניות (18.1.4) ─────────
  // מלמעלה למטה: ה-combobox, שורת לשוניות (נגללת לצדדים), והרשימה של הלשונית הפתוחה. הראשונה — "⭐ מועדפים" (המעקב).
  // פתיחת לשונית — market_tab: מחיר ושינוי יומי מהמאגר בלבד (ש6), בלי פנייה לספק. הסימול לא מוצג — רק התווית (18.1.1).
  async function viewMarket() {
    setHead('שוק', 'בוחרים מהרשימה');
    const [provs, T, wl0] = await Promise.all([providers(), api('market_tabs'), api('watchlist')]);
    const watchlist = wl0.watchlist;
    let tabs = T.tabs, removed = T.removed;
    const MAX = T.max, MAX_ASSETS = T.max_assets;
    let cur = sessionStorage.getItem('ts-market-tab');
    if (!tabs.some(t => t.id === cur)) cur = 'favorites';
    const combo = mountCombo(ctx, { label: 'בחירת נכס', recent: () => watchlist, onPick: it => {
      if (it.alternatives?.length) chooseProvider(it); else location.hash = assetUrl(it.asset);
    } });
    const strip = h('div', { class: 'mtabs', role: 'tablist', 'aria-label': 'לשוניות' });
    const panel = h('div', { class: 'mtab-panel', role: 'tabpanel', id: 'mtab-panel' });
    const manage = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'סידור לשוניות', title: 'סידור לשוניות', on: { click: () => manageTabs() } }, '⚙️');

    const saveTabs = async next => {
      const r = await api('market_tabs_save', { tabs: next });
      tabs = r.tabs; removed = r.removed;
      if (!tabs.some(t => t.id === cur)) cur = 'favorites';
      drawStrip();
    };
    function openTab(id) {
      cur = id;
      try { sessionStorage.setItem('ts-market-tab', id); } catch { /* ריק */ }
      strip.querySelectorAll('[role="tab"]').forEach(b => { b.setAttribute('aria-selected', String(b.dataset.id === id)); b.tabIndex = b.dataset.id === id ? 0 : -1; });
      strip.querySelector(`[data-id="${CSS.escape(id)}"]`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      drawTab();
    }
    function drawStrip() {
      strip.replaceChildren(...tabs.map(t => h('button', { role: 'tab', type: 'button', class: 'mtab', id: 'mtab-' + t.id, 'data-id': t.id,
        'aria-controls': 'mtab-panel', 'aria-selected': String(t.id === cur), tabindex: t.id === cur ? '0' : '-1',
        'data-fixed': t.kind === 'favorites' ? '1' : null,
        on: { click: e => { if (strip.dataset.dragged) { e.preventDefault(); return; } openTab(t.id); } } }, t.name)),
      h('button', { class: 'mtab mtab-add', type: 'button', on: { click: () => addTab() } }, '➕ לשונית'));
    }
    // מקלדת בשורת הלשוניות: חצים, Home/End (ARIA tabs). RTL — ימינה זה "הקודם"
    strip.addEventListener('keydown', e => {
      const ids = tabs.map(t => t.id);
      const i = ids.indexOf(cur);
      const rtl = getComputedStyle(strip).direction === 'rtl';
      const d = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[e.key];
      let j = d ? (i + d + ids.length) % ids.length : e.key === 'Home' ? 0 : e.key === 'End' ? ids.length - 1 : -1;
      if (j < 0 || !e.target.closest('[role="tab"]')) return;
      e.preventDefault();
      openTab(ids[j]);
      strip.querySelector(`[data-id="${CSS.escape(ids[j])}"]`).focus();
    });
    dragTabs(strip, async order => {
      const byId = Object.fromEntries(tabs.map(t => [t.id, t]));
      // עד שהסדר נשמר והשורה נבנתה מחדש — הכפתורים עוד יוחלפו (קורא מסך, וגם הבדיקה מחכה לזה)
      strip.setAttribute('aria-busy', 'true');
      try {
        await saveTabs(order.map(id => byId[id]).filter(Boolean)).catch(e => { toast(e.message, true); drawStrip(); });
      } finally {
        strip.removeAttribute('aria-busy');
      }
    });

    // ── הרשימה של לשונית ──
    let editing = false;
    async function drawTab() {
      const tab = tabs.find(t => t.id === cur);
      const my = cur;
      panel.setAttribute('aria-labelledby', 'mtab-' + cur);
      panel.replaceChildren(h('p', { class: 'loading' }, 'טוען…'));
      let r;
      try { r = await api('market_tab', null, { id: cur }); } catch (e) { if (my === cur) panel.replaceChildren(h('p', { class: 'error', role: 'alert' }, e.message)); return; }
      if (my !== cur) return;
      const rows = r.rows;
      const head = [];
      if (r.needs) head.push(h('p', { class: 'needs', role: 'note' }, `הנכסים בלשונית הזו מגיעים מ-${r.needs.name}, שצריך בו מפתח אישי. `, h('a', { href: '#/providers' }, 'חיבור ספק')));
      if (tab.kind === 'favorites' && rows.length) {
        head.push(h('div', { class: 'toolbar mtab-tools' }, h('span', { class: 'muted' }, `${rows.length} במעקב`),
          h('button', { class: 'btn ghost sm', type: 'button', 'aria-pressed': String(editing), on: { click: () => { editing = !editing; drawTab(); } } }, editing ? 'סיום' : '✏️ סידור')));
      }
      if (tab.kind === 'custom') {
        const add = mountCombo(ctx, { label: 'הוספת נכס ללשונית', placeholder: 'הוספת נכס ללשונית…', name: 'tab-add', onPick: async it => {
          if (tab.assets.includes(it.asset)) { toast('כבר בלשונית'); return; }
          if (tab.assets.length >= MAX_ASSETS) { toast(`עד ${MAX_ASSETS} נכסים בלשונית`, true); return; }
          try { await saveTabs(tabs.map(t => (t.id === tab.id ? { ...t, assets: [...t.assets, it.asset] } : t))); drawTab(); } catch (e) { toast(e.message, true); }
        } });
        head.push(add.el);
      }
      const empty = tab.kind === 'favorites' ? 'סמנו ⭐ ליד נכס כדי להוסיף' : tab.kind === 'custom' ? 'בוחרים נכסים בשדה שלמעלה.' : 'אין נכסים בלשונית.';
      panel.replaceChildren(...head, rows.length
        ? h('ul', { class: 'list mrows' }, rows.map((x, i) => marketRow(x, { tab, i, rows, needs: r.needs })))
        : h('p', { class: 'empty' }, empty));
    }

    function marketRow(x, { tab, i, rows, needs }) {
      const inWl = () => watchlist.some(w => w.asset === x.asset);
      const star = x.asset ? h('button', { class: 'icon-btn star', type: 'button', 'aria-pressed': String(inWl()), 'aria-label': inWl() ? 'הסרה מהמועדפים' : 'הוספה למועדפים',
        on: { click: async () => {
          try {
            const r = await api(inWl() ? 'watchlist_remove' : 'watchlist_add', { asset: x.asset });
            watchlist.splice(0, Infinity, ...r.watchlist);
            star.textContent = inWl() ? '⭐' : '☆';
            star.setAttribute('aria-pressed', String(inWl()));
            star.setAttribute('aria-label', inWl() ? 'הסרה מהמועדפים' : 'הוספה למועדפים');
            toast(inWl() ? 'נוסף למועדפים' : 'הוסר מהמועדפים');
            if (tab.kind === 'favorites') drawTab();
          } catch (e) { toast(e.message, true); }
        } } }, inWl() ? '⭐' : '☆') : null;
      const quote = h('span', { class: 'mq', dir: 'ltr' },
        h('span', { class: 'num p' }, x.price != null ? fmtQuote(x.price) : '—'),
        x.change_pct != null ? h('small', { class: 'num ' + (x.change_pct.startsWith('-') ? 'neg' : x.change_pct === '0.00' ? '' : 'pos') },
          (x.change_pct.startsWith('-') || x.change_pct === '0.00' ? '' : '+') + x.change_pct + '%') : null,
        // המילוי ברקע (21.2): נתון ישן מיום — בקטן
        x.price != null && x.age_days ? h('small', { class: 'muted mq-age', dir: 'rtl' }, x.age_days === 1 ? 'לפני יום' : `לפני ${x.age_days} ימים`) : null);
      const body = [h('div', { class: 'l1' }, pairLabel(x), x.computed ? h('span', { class: 'chip' }, 'מחושב') : null)];
      const go = () => (x.locked ? lockedSheet(x, needs) : (location.hash = assetUrl(x.asset)));
      let btns = null;
      if (tab.kind === 'favorites' && editing) {
        btns = h('span', { class: 'row-btns' },
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'למעלה', disabled: i === 0, on: { click: () => moveFav(rows, i, -1) } }, '▲'),
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'למטה', disabled: i === rows.length - 1, on: { click: () => moveFav(rows, i, 1) } }, '▼'));
      } else if (tab.kind === 'custom') {
        btns = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הסרה מהלשונית', on: { click: async () => {
          try { await saveTabs(tabs.map(t => (t.id === tab.id ? { ...t, assets: t.assets.filter(a => a !== x.asset) } : t))); drawTab(); } catch (e) { toast(e.message, true); }
        } } }, '✕');
      }
      return h('li', { class: 'card mrow' + (x.locked ? ' locked' : '') },
        h('button', { class: 'body', type: 'button', title: x.locked ? 'צריך לחבר ספק' : null, on: { click: go } }, body), quote, star, btns);
    }
    const moveFav = async (rows, i, d) => {
      const list = rows.map(r => r.asset);
      const [x] = list.splice(i, 1); list.splice(i + d, 0, x);
      await api('watchlist_order', { assets: list });
      const w = await api('watchlist'); watchlist.splice(0, Infinity, ...w.watchlist);
      drawTab();
    };
    // ש7: נכס בלי ספק — הסבר וקישור, לא גרף
    function lockedSheet(x, needs) {
      const s = sheet('צריך לחבר ספק');
      s.body.append(h('p', {}, pairLabel(x)),
        h('p', {}, needs ? `הנתונים של הנכס הזה מגיעים מ-${needs.name}, ושם צריך מפתח אישי (יש גם חינמי).` : 'אין לכם חיבור לספק של הנכס הזה.'),
        h('a', { class: 'btn primary', href: '#/providers', on: { click: () => s.close() } }, '🔌 חיבור ספק'));
    }

    // ── "➕ לשונית": מובנית שהוסרה, או לשונית משלי ──
    function addTab() {
      if (tabs.length >= MAX) { toast(`עד ${MAX} לשוניות. מסירים אחת ב-⚙️, ואז מוסיפים.`, true); return; }
      const s = sheet('➕ לשונית');
      const name = h('input', { name: 'tab-name', maxlength: 30, placeholder: 'למשל: הארוך שלי', autocomplete: 'off' });
      const chosen = [];
      const chosenList = h('ul', { class: 'list' });
      const drawChosen = () => chosenList.replaceChildren(...(chosen.length ? chosen.map((it, k) => h('li', { class: 'card', style: 'cursor:default' },
        h('div', { class: 'body' }, pairLabel(it)),
        h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'הסרה', on: { click: () => { chosen.splice(k, 1); drawChosen(); } } }, '✕')))
        : [h('li', { class: 'empty' }, 'עוד לא נבחרו נכסים')]));
      const pickAsset = mountCombo(ctx, { label: 'נכס ללשונית', placeholder: 'נכס ללשונית…', name: 'new-tab-asset', onPick: it => {
        if (!chosen.some(c => c.asset === it.asset) && chosen.length < MAX_ASSETS) chosen.push(it);
        drawChosen();
      } });
      drawChosen();
      const create = h('button', { class: 'btn primary', type: 'button', on: { click: async () => {
        const n = name.value.trim();
        if (!n) { toast('חסר שם ללשונית', true); name.focus(); return; }
        const id = 'c' + Date.now().toString(36) + Math.floor(Math.random() * 1296).toString(36);
        try { await saveTabs([...tabs, { id, kind: 'custom', name: n, assets: chosen.map(c => c.asset) }]); s.close(); openTab(id); } catch (e) { toast(e.message, true); }
      } } }, 'יצירת הלשונית');
      s.body.append(
        removed.length ? h('div', { class: 'group' }, h('h3', {}, 'לשוניות מובנות שהוסרו'),
          h('div', { class: 'chips' }, removed.map(b => h('button', { class: 'chip-btn', type: 'button', on: { click: async () => {
            try { await saveTabs([...tabs, { id: b.id, kind: 'builtin' }]); s.close(); openTab(b.id); } catch (e) { toast(e.message, true); }
          } } }, b.name)))) : null,
        h('div', { class: 'group' }, h('h3', {}, 'לשונית משלי'), field('שם', name, true), h('div', { class: 'f wide' }, h('span', {}, 'נכסים'), pickAsset.el), chosenList, create));
    }

    // ── ⚙️ סידור והסרה (גם בלי גרירה — מקלדת וקורא מסך) ──
    function manageTabs() {
      const s = sheet('סידור לשוניות');
      const box = h('ul', { class: 'list' });
      const draw = () => box.replaceChildren(...tabs.map((t, i) => h('li', { class: 'card', style: 'cursor:default' },
        h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, t.name)), t.kind === 'favorites' ? h('div', { class: 'l2' }, 'תמיד ראשונה') : t.kind === 'custom' ? h('div', { class: 'l2' }, `משלי · ${t.assets.length} נכסים`) : null),
        t.kind === 'favorites' ? null : h('span', { class: 'row-btns' },
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': `${t.name} — למעלה`, disabled: i <= 1, on: { click: () => mv(i, -1) } }, '▲'),
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': `${t.name} — למטה`, disabled: i === tabs.length - 1, on: { click: () => mv(i, 1) } }, '▼'),
          h('button', { class: 'icon-btn', type: 'button', 'aria-label': `הסרה של ${t.name}`, on: { click: () => rm(t) } }, '✕')))));
      const mv = async (i, d) => {
        const next = [...tabs]; const [x] = next.splice(i, 1); next.splice(i + d, 0, x);
        try { await saveTabs(next); draw(); } catch (e) { toast(e.message, true); }
      };
      const rm = t => {
        const go = async () => { try { await saveTabs(tabs.filter(x => x.id !== t.id)); draw(); drawTab(); } catch (e) { toast(e.message, true); } };
        if (t.kind === 'custom') confirmSheet('מחיקת לשונית', `הלשונית "${t.name}" והרשימה שבה יימחקו.`, 'מחיקה', go); else go();
      };
      draw();
      s.body.append(h('p', { class: 'hint' }, 'אפשר גם לגרור לשונית בשורה (בטלפון — לחיצה ארוכה ואז גרירה). מובנית שהוסרה חוזרת דרך "➕ לשונית".'), box);
    }

    drawStrip();
    const connected = provs.filter(p => p.available).map(p => p.name).join(' · ');
    render(combo.el, h('div', { class: 'mtabs-bar' }, strip, manage), panel,
      h('p', { class: 'hint', style: 'margin-top:12px' }, 'ספקים: ', connected || 'אין ספקים', '. ', h('a', { href: '#/providers' }, 'חיבור ספקים'), ' — מניות, מדדים ומט"ח תוך-יומי צריכים ספק עם מפתח.'));
    openTab(cur);
  }

  // מחיר בשורה: מפריד אלפים; מתחת ל-1 — עד 8 ספרות (שיבה אינו), מעל 1,000 — 2
  function fmtQuote(v) {
    const a = Math.abs(parseFloat(v));
    return D.fmt(v, a >= 1000 ? 2 : a >= 1 ? 4 : 8);
  }

  /**
   * גרירה לסידור הלשוניות (18.1.4 קריטריון 4). עכבר — גוררים; מגע — לחיצה ארוכה (450ms) ואז גרירה, כדי שגלילה לצדדים
   * תישאר גלילה. "⭐ מועדפים" (data-fixed) לא נגררת, ושום לשונית לא עוברת לפניה. onDrop(ids) — הסדר החדש.
   */
  function dragTabs(strip, onDrop) {
    let st = null;
    const tabOf = el => el?.closest?.('.mtab[role="tab"]');
    const end = () => {
      if (!st) return;
      clearTimeout(st.timer);
      const was = st.on;
      st.el.classList.remove('dragging');
      strip.classList.remove('dragging');
      st = null;
      if (was) {
        strip.dataset.dragged = '1';
        setTimeout(() => { delete strip.dataset.dragged; }, 0);   // הלחיצה שאחרי השחרור — לא בוחרת לשונית
        onDrop([...strip.querySelectorAll('.mtab[role="tab"]')].map(b => b.dataset.id));
      }
    };
    const begin = () => { st.on = true; st.el.classList.add('dragging'); strip.classList.add('dragging'); navigator.vibrate?.(15); };
    strip.addEventListener('pointerdown', e => {
      const el = tabOf(e.target);
      if (!el || el.dataset.fixed || e.button > 0) return;
      st = { el, x: e.clientX, y: e.clientY, on: false, touch: e.pointerType !== 'mouse', id: e.pointerId };
      if (st.touch) st.timer = setTimeout(() => { if (st) begin(); }, 450);
    });
    strip.addEventListener('pointermove', e => {
      if (!st || e.pointerId !== st.id) return;
      const dist = Math.hypot(e.clientX - st.x, e.clientY - st.y);
      if (!st.on) {
        if (st.touch) { if (dist > 8) { clearTimeout(st.timer); st = null; } return; }   // גלילה, לא גרירה
        if (dist < 6) return;
        begin();
        try { strip.setPointerCapture(e.pointerId); } catch { /* ריק */ }
      }
      // מחליפים מקום עם הלשונית שמתחת לאצבע — בלי לעבור את המועדפים
      const over = [...strip.querySelectorAll('.mtab[role="tab"]:not([data-fixed])')].find(b => {
        const r = b.getBoundingClientRect();
        return b !== st.el && e.clientX >= r.left && e.clientX <= r.right;
      });
      if (!over) return;
      const all = [...strip.children];
      if (all.indexOf(st.el) < all.indexOf(over)) over.after(st.el); else over.before(st.el);
    });
    // בזמן גרירה במגע — הדף לא נגלל
    strip.addEventListener('touchmove', e => { if (st?.on) e.preventDefault(); }, { passive: false });
    strip.addEventListener('pointerup', end);
    strip.addEventListener('pointercancel', () => { if (st && !st.on) { clearTimeout(st.timer); st = null; } else end(); });
    strip.addEventListener('contextmenu', e => { if (st) e.preventDefault(); });
  }

  let route = null;

  function chooseProvider(x) {
    const s = sheet('מאיזה ספק?');
    const all = [{ asset: x.asset, provider: x.provider, name: x.name }, ...x.alternatives];
    s.body.append(h('p', {}, pairLabel(x)), h('p', { class: 'hint' }, 'אותו זוג אצל כמה ספקים. הבחירה נשמרת — בפעם הבאה יוצג הספק הזה.'),
      h('ul', { class: 'list' }, all.map(a => h('li', {}, h('button', { class: 'use-row', type: 'button', on: { click: async () => {
        await api('symbol_pref', { asset: a.asset }); s.close(); location.hash = assetUrl(a.asset);
      } } }, h('span', { class: 'use-tx' }, h('b', {}, provName(a.provider)), h('small', {}, a.name)), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))))));
  }

  // ── גרף ─────────────────────────────────────────────────
  async function viewChart(asset) {
    const [provider, symbol] = splitAsset(asset);
    // הכותרת — שם הזוג במילים (18.1); הסימול נשאר בפנים
    const [provs, { labels }] = await Promise.all([providers(), api('pair_labels', null, { assets: asset })]);
    // הכותרת: BTC ⇄ USDT; מתחת — השמות בעברית והספק (18.1.1)
    const L = labels[asset] || { label: 'גרף' };
    const label = () => pairMain(L);
    // השמות בעברית — מתקצרים כשאין מקום; הספק — ב-sub-tail, תמיד שלם (🟡 F1: "Binance" נחתך ל-"…ce")
    const sub = pp => { const s = pairSub(L); return [s, h('span', { class: 'sub-tail' }, (s ? '· ' : '') + (pp ? pp.name : provider) + (L.computed ? ' · מחושב (1/x)' : ''))]; };
    const p = provs.find(x => x.id === provider);
    setHead(label(), sub(p), { back: true, docTitle: L.label });
    // pool (21.3): ספק משותף בלי דרך אליו — מה שבמאגר מוצג, בלי השלמה
    if (!p?.available && !p?.pool) {
      render(h('p', { class: 'error' }, 'אין לכם חיבור לספק הזה. '), h('a', { class: 'btn ghost', href: '#/providers' }, 'ספקי נתונים'));
      return;
    }
    const key = 'ts-chart:' + asset;
    let st = {};
    try { st = JSON.parse(sessionStorage.getItem(key) || '{}'); } catch { /* ריק */ }
    // זוג מהקטלוג — גם המסגרות שספק אחר נותן לו (21.3, 2.26: מט"ח תוך-יומי)
    const tfs = L.tfs?.length ? L.tfs : p.tfs;
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
    const refresh = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'רענן', title: 'רענן', on: { click: () => load('manual') } }, '↻');
    const drawBtn = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'ציור על הגרף', title: 'ציור על הגרף', 'aria-pressed': 'false',
      on: { click: () => { draw.toggle(); drawBtn.setAttribute('aria-pressed', String(draw.isActive())); } } }, '✏️');
    // "🔮 שיטות על הגרף" (17.2.2, 18.1.2) — הכפתור כאן, התפריט וההתנהגות ב-forecast.js (נבנה אחרי הגרף)
    const fcBtn = () => h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'תחזית שיטה', title: 'שיטות על הגרף', 'aria-pressed': 'false', 'aria-expanded': 'false' }, '🔮');
    const forecastBtn = fcBtn();
    // ⛶ מסך מלא (18.1.2 סעיף 3) — בשורת הכפתורים של הגרף (בכותרת אין מקום בטלפון)
    // L1 (18.1.5): ⛶ פותח תפריט קטן — ⛶ לגובה / ⟷ לרוחב — רק כשהמסך לאורך. מסך רחב (מחשב, טלפון שכבר לרוחב) — ישר פנימה
    const fsBtn = h('button', { class: 'icon-btn fs-open', type: 'button', 'aria-label': 'מסך מלא', title: 'מסך מלא', 'aria-haspopup': 'menu', 'aria-expanded': 'false',
      on: { click: () => (tall() ? fsMenuToggle() : fsEnter('portrait')) } }, '⛶');
    const fsItem = (mode, icon, text) => h('button', { class: 'fs-item', type: 'button', role: 'menuitem', 'data-mode': mode,
      on: { click: () => { fsMenuClose(); fsEnter(mode); } } }, h('span', { class: 'fs-ico', 'aria-hidden': 'true' }, icon), text);
    const fsMenu = h('div', { class: 'fs-menu', role: 'menu', 'aria-label': 'מסך מלא', hidden: true },
      fsItem('portrait', '⛶', 'לגובה'), fsItem('landscape', '⟷', 'לרוחב'));
    const fsPick = h('span', { class: 'fs-pick' }, fsBtn, fsMenu);
    setHead(label(), sub(p), { back: true, docTitle: L.label, actions: [forecastBtn, drawBtn, refresh, star] });

    const tfSeg = seg('tf', tfs.map(t => [t, TF_LABEL[t]]), st.tf, { label: 'מסגרת זמן', onChange: v => { st.tf = v; st.range = DEFAULT_RANGE[v]; rangeSel.value = st.range; save(); forecast.retf(); load('change'); } });
    const rangeSel = select('range', RANGES.map(([k, l]) => [k, l]), st.range, { 'aria-label': 'טווח' });
    const from = h('input', { type: 'date', 'aria-label': 'מתאריך', value: st.from || '' });
    const to = h('input', { type: 'date', 'aria-label': 'עד תאריך', value: st.to || '' });
    const custom = h('div', { class: 'fields two', hidden: st.range !== 'custom' }, field('מתאריך', from), field('עד תאריך', to));
    rangeSel.addEventListener('change', () => { st.range = rangeSel.value; custom.hidden = st.range !== 'custom'; save(); if (st.range !== 'custom') load('change'); });
    [from, to].forEach(el => el.addEventListener('change', () => { st.from = from.value; st.to = to.value; save(); if (from.value) load('change'); }));
    const inds = h('div', { class: 'chips', role: 'group', 'aria-label': 'אינדיקטורים' }, IND.map(([k, l]) => {
      const c = h('input', { type: 'checkbox', value: k });
      c.checked = st.ind.includes(k);
      c.addEventListener('change', () => { st.ind = [...inds.querySelectorAll('input:checked')].map(i => i.value); save(); drawIndicators(); });
      return h('label', {}, c, h('span', {}, l));
    }));

    const info = h('p', { class: 'hint', 'aria-live': 'polite' });
    const live = h('span', { class: 'chip', hidden: true });
    // רענון אוטומטי (18.3): הקצב לבחירת המשתמש — 60 (ברירת מחדל) או 30 שניות; "עודכן לפני X".
    // שניהם בשורת הכותרת של הפס שמתחת לגרף (S4) — לא בשורת הכפתורים, כדי שהגרף לא יירד בשורה כשהניתוח נדלק בטלפון
    const EVERY = [60, 30];
    let every = 60;
    try { const v = +localStorage.getItem('ts-refresh-every'); if (EVERY.includes(v)) every = v; } catch { /* ריק */ }
    const everySel = select('every', [['60', '⟳ כל דקה'], ['30', '⟳ כל 30 ש׳']], String(every), { 'aria-label': 'קצב הרענון האוטומטי', hidden: true });
    everySel.addEventListener('change', () => {
      every = +everySel.value;
      try { localStorage.setItem('ts-refresh-every', String(every)); } catch { /* ריק */ }
      schedule();
    });
    const upd = h('span', { class: 'chip refresh-age', hidden: true });
    const box = h('div', { class: 'chart-box' });
    const oscBox = h('div', { class: 'chart-box osc', hidden: true });
    const head = h('div', { class: 'quote-head' });
    const drawSlot = h('div', { class: 'draw-slot' });
    const forecastSlot = h('div', {});
    // במסך מלא: שורה צפה מעל הגרף — תווית הזוג, 🔮 ו-✕ (18.1.2 סעיף 3)
    const forecastBtnFs = fcBtn();
    const fsExit = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'יציאה ממסך מלא', title: 'יציאה ממסך מלא', on: { click: () => fsLeave() } }, '✕');
    // ⟳ החלפה בין לגובה ללרוחב בלי לצאת (L1 סעיף 3) — ליד ✕. רק כשנכנסו מטלפון לאורך (כמו התפריט)
    const fsRot = h('button', { class: 'icon-btn fs-rot', type: 'button', hidden: true, on: { click: () => fsSetMode(fsMode === 'landscape' ? 'portrait' : 'landscape') } }, '⟳');
    const fsBar = h('div', { class: 'fs-bar' }, h('span', { class: 'fs-pair' }, pairMain(L)), forecastBtnFs, fsRot, fsExit);
    const wrap = h('div', { class: 'chart-wrap' }, fsBar, box, oscBox);
    render(head, h('div', { class: 'chart-tools' }, tfSeg, h('div', { class: 'toolbar' }, rangeSel, live, fsPick)), custom,
      wrap, drawSlot, forecastSlot, inds, info,
      h('p', { class: 'disclaimer' }, '⚖️ נתוני שוק להצגה בלבד. אינו ייעוץ השקעות. ', h('span', {}, p.terms)));

    const css = getComputedStyle(document.documentElement);
    const col = n => css.getPropertyValue(n).trim();
    const tz = ME().user.timezone;
    const fmtT = (t, opts) => { try { return new Date(t * 1000).toLocaleString('he-IL', { timeZone: tz, ...opts }); } catch { return ''; } };
    const LW = window.LightweightCharts;
    const base = {
      autoSize: true,
      layout: { background: { color: col('--surface') }, textColor: col('--muted'), fontFamily: 'system-ui, sans-serif', attributionLogo: true },
      // הצירים — בתוך שטח הגרף (chartaxis.js), בלי עמודת מחירים ובלי פס זמן: הנרות מגיעים עד הקצה (18.1.2 סעיף 4).
      // גם קווי הרשת — שם, לפי אותם סימונים
      grid: { vertLines: { visible: false }, horzLines: { visible: false } },
      rightPriceScale: { visible: false },
      leftPriceScale: { visible: false },
      // minBarSpacing נמוך — כדי ש"5 שנים" (1,825 נרות) ייכנסו כולם גם ברוחב של טלפון
      timeScale: { visible: false, borderColor: col('--line'), timeVisible: TF_SEC[st.tf] < 86400, secondsVisible: false, minBarSpacing: 0.05, rightOffset: 0,
        // סיבוב (מסך מלא לרוחב, 2.30) — אותו טווח זמן נמתח על כל הרוחב, ולא חצי גרף ריק
        lockVisibleTimeRangeOnResize: true,
        // סוג הסימון מהספרייה: 0 שנה · 1 חודש · 2 יום · 3 שעה — כך רואים תאריך במעבר יום, ושעה בתוכו
        tickMarkFormatter: (t, type) => fmtT(t, type === 0 ? { year: 'numeric' } : type === 1 ? { month: 'short' }
          : type === 2 ? { day: 'numeric', month: 'numeric' } : { hour: '2-digit', minute: '2-digit' }) },
      localization: { timeFormatter: t => fmtT(t, { day: 'numeric', month: 'numeric', year: '2-digit', ...(TF_SEC[st.tf] < 86400 ? { hour: '2-digit', minute: '2-digit' } : {}) }) },
      crosshair: { mode: 0, vertLine: { labelVisible: false }, horzLine: { labelVisible: false } },
    };
    const chart = LW.createChart(box, base);
    const up = col('--ok'), down = col('--danger');
    const candle = chart.addCandlestickSeries({ upColor: up, downColor: down, wickUpColor: up, wickDownColor: down, borderVisible: false });
    const volume = chart.addHistogramSeries({ priceScaleId: 'vol', priceFormat: { type: 'volume' }, lastValueVisible: false, priceLineVisible: false });
    // הנפח — מעל פס דק בתחתית, שבו מספרי ציר הזמן (בתוך הגרף, 18.1.2 סעיף 4)
    chart.priceScale('vol').applyOptions({ scaleMargins: { top: 0.82, bottom: 0.05 } });
    const lines = {
      sma20: chart.addLineSeries({ color: col('--brand'), lineWidth: 2, priceLineVisible: false, lastValueVisible: false }),
      sma50: chart.addLineSeries({ color: col('--star'), lineWidth: 2, priceLineVisible: false, lastValueVisible: false }),
      ema20: chart.addLineSeries({ color: col('--muted'), lineWidth: 2, lineStyle: 2, priceLineVisible: false, lastValueVisible: false }),
    };
    const osc = LW.createChart(oscBox, { ...base, timeScale: { ...base.timeScale, visible: false } });
    const oscSeries = osc.addLineSeries({ color: col('--brand'), lineWidth: 2, priceLineVisible: false, lastValueVisible: false });
    // ציר הזמן: תאריך במעבר יום/חודש/שנה, ושעה בתוך היום; הסמן — תאריך ושעה
    const fmtTick = (t, kind) => fmtT(t, kind === 'year' ? { year: 'numeric' } : kind === 'month' ? { month: 'short', year: '2-digit' }
      : kind === 'day' ? { day: 'numeric', month: 'numeric' } : kind === 'time' ? { hour: '2-digit', minute: '2-digit' }
        // הנר האחרון בקצה הימני: שעה במסגרת שבתוך היום, תאריך במסגרת של יום ומעלה
        : kind === 'last' ? (TF_SEC[st.tf] < 86400 ? { hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'numeric' })
          : { day: 'numeric', month: 'numeric', ...(TF_SEC[st.tf] < 86400 ? { hour: '2-digit', minute: '2-digit' } : { year: '2-digit' }) });
    let dec = 2;   // הספרות של הנכס (priceFormat) — לסמן ולציר
    const axis = mountAxis({ chart, series: candle, col, getDec: () => dec, getTimes: () => data.map(x => x.time), fmtTime: fmtTick,
      offset: tzOffset(tz) });   // גבולות הסימונים לפי שעון המשתמש — כמו הטקסט (fmtT)
    const oscAxis = mountAxis({ chart: osc, series: oscSeries, col, getDec: () => 2, time: false });
    // לוח התוויות — משותף לשיטות ולשרטוטים, כדי שתוויות לא יחפפו (18.1.2 סעיף 5)
    const board = labelBoard();
    box.tsLabels = () => board.rects();   // לבדיקות: המלבנים של התוויות שצוירו בפעם האחרונה
    box.tsAxis = () => axis.shown();      // לבדיקות: המספרים שעל הצירים
    box.tsAxisW = () => axis.priceWidth(); // לבדיקות: רוחב עמודת המספרים
    box.tsRange = () => chart.timeScale().getVisibleLogicalRange(); // לבדיקות: הטווח הנראה (L1)
    box.tsPriceAt = y => candle.coordinateToPrice(y);                 // לבדיקות: המחיר בגובה y בגרף (L1, ציור בשכבה המסובבת)
    box.tsScreen = () => { const r = screenRect(box); return [r.left, r.top, r.right, r.bottom]; }; // לבדיקות: המלבן האמיתי על המסך
    let cross = null;
    chart.subscribeCrosshairMove(e => { cross = e.point ? { x: e.point.x, y: e.point.y } : null; });
    box.tsCross = () => cross;                                         // לבדיקות: איפה הסמן (L1 קריטריון 5)
    // תנועות מגע (gestures.js): אצבע אחת — לרוחב בזמן, לגובה גוללת את הדף; שתי אצבעות — צביטה (זמן / גובה) או גרירה (זמן / מחיר)
    const gest = mountGestures({ chart, series: candle, shifted: [candle, ...Object.values(lines)], box, blocked: () => draw.isCapturing() });
    // לבדיקות: הגובה (px) של הנרות הנראים ושל עמודות הנפח, ומקדם הכיווץ
    box.tsHeights = () => {
      const r = chart.timeScale().getVisibleLogicalRange();
      const vis = r ? data.slice(Math.max(0, Math.ceil(r.from)), Math.floor(r.to) + 1) : data;
      if (!vis.length) return null;
      const hi = Math.max(...vis.map(x => x.high)), lo = Math.min(...vis.map(x => x.low));
      const m = chart.priceScale('vol').options().scaleMargins;
      return { price: Math.round(candle.priceToCoordinate(lo) - candle.priceToCoordinate(hi)), vol: Math.round(box.offsetHeight * (1 - m.top - m.bottom)), k: gest.vScale(),
        // האינדיקטורים על אותו ציר כמו הנרות — מתכווצים איתם
        lines: Object.values(lines).filter(l => l.data().length).map(l => Math.abs(l.priceToCoordinate(hi) - candle.priceToCoordinate(hi)) < 0.5) };
    };
    chart.timeScale().subscribeVisibleLogicalRangeChange(r => { if (r && !oscBox.hidden) osc.timeScale().setVisibleLogicalRange(r); });
    // ציור על הגרף (פרק 7) — השרטוטים נטענים פעם אחת לכל פתיחת גרף; נשמרים רק בסוף שינוי
    // השיטות, והפס שמתחת לגרף — ממצא · תרחיש · הסתברות (S4, 18.4)
    const forecast = mountForecast({ ctx, asset, pair: L, chart, series: candle, h, host: wrap, isAdmin: () => !!ME().is_admin, api, toast, col, board, axisWidth: () => axis.priceWidth(),
      buttons: [forecastBtn, forecastBtnFs], tools: [everySel, upd], tfs: () => tfs,
      setTf: v => { const i = tfSeg.querySelector(`input[value="${v}"]`); if (i && !i.checked) { i.checked = true; i.dispatchEvent(new Event('change')); } },
      onChange: () => (forecast.methods().length ? load('pick') : schedule()),
      getTf: () => st.tf, getSec: () => TF_SEC[st.tf], getTimes: () => data.map(x => x.time) });
    forecastSlot.append(forecast.panel);
    box.tsForecast = () => forecast.state();   // לבדיקות: השיטות, והפריטים של הממצא ושל התרחיש
    // השרטוטים — אחרי השיטות: הם מצוירים מעליהן, אבל בחפיפת תוויות התווית של השיטה המובילה גוברת (18.1.2 סעיף 5)
    const draw = mountDrawings({ chart, series: candle, box, asset, api, toast, h, sheet, board,
      getTf: () => st.tf, getSec: () => TF_SEC[st.tf], getTimes: () => data.map(x => x.time), fmtPrice: v => D.fmt(String(+(+v).toPrecision(7)), 8) });
    drawSlot.append(draw.bar);
    // חלונית הסבר על נקודת תחזית (T1, 18.1.6): לא כשכלי ציור פעיל, ולא בלחיצה שסוגרת את התפריט 🔮
    const tip = mountChartTip({ h, box, wrap, chart, forecast, legend: () => wrap.querySelector('.fc-legend'),
      fmtTime: t => fmtT(t, { day: 'numeric', month: 'numeric', year: '2-digit', ...(TF_SEC[st.tf] < 86400 ? { hour: '2-digit', minute: '2-digit' } : {}) }),
      canTip: () => !draw.isActive() && !forecast.popOpen(),
      // "פירוט השיטה ›" (סעיף 2): תפריט השיטות עם השיטה מסומנת, והתנאים שלה פתוחים בפס שמתחת לגרף. במסך מלא — יוצאים קודם
      onDetails: id => {
        if (fsOn) fsLeave();
        const sec = forecastSlot.querySelector(`.forecast-panel[data-method="${CSS.escape(id)}"]`);
        sec?.querySelector('.an-fold[aria-expanded="false"]')?.click();
        wrap.scrollIntoView({ block: 'start' });
        if (!forecast.popOpen()) forecastBtn.click();
      } });
    box.tsTip = () => tip.state();   // לבדיקות: החלונית הפתוחה
    box.tsPoints = kind => forecast.points(kind);   // לבדיקות: הנקודות של השיטות על הגרף
    onTeardown(() => { fsLeave(true); gest.destroy(); tip.destroy(); forecast.destroy(); draw.destroy(); axis.destroy(); oscAxis.destroy(); chart.remove(); osc.remove(); });

    // ── מסך מלא (18.1.2 סעיף 3; L1 — 18.1.5): רק הגרף. Fullscreen API על כל הדף (documentElement) כשיש, והגרף — שכבה שמכסה
    // את המסך (`.chart-wrap.fs`). כל הדף ולא הגרף: על אלמנט ב-Fullscreen הדפדפן כופה `transform: none`, והשכבה המסובבת צריכה transform.
    // יציאה: ✕, Esc, או "חזור" — רשומה בהיסטוריה (אותה כתובת, כך שהניתוב לא מתחלף), ו"חזור" רק מוציא ממסך מלא.
    //
    // "לרוחב" (L1 סעיף 2): קודם `screen.orientation.lock('landscape')` (רק בתוך Fullscreen). אין נעילה, או שנדחתה —
    // שכבה מסובבת ב-CSS (`.fs-turn`), והמגע מומר ב-rotate.js. הטלפון הסתובב לבד לרוחב — אין צורך בסיבוב, והוא יורד.
    const root = document.documentElement;
    const fsApi = () => !!(root.requestFullscreen && document.fullscreenEnabled);
    const ori = () => window.screen?.orientation;
    const tall = () => window.innerHeight > window.innerWidth;
    let fsOn = false, fsMode = 'portrait', fromTall = false, locked = false, lockTried = false, lockPending = false;
    function fsMenuToggle() {
      const open = fsMenu.hidden;
      fsMenu.hidden = !open;
      fsBtn.setAttribute('aria-expanded', String(open));
      if (open) fsMenu.querySelector('.fs-item').focus();
    }
    function fsMenuClose(focus = false) {
      if (fsMenu.hidden) return;
      fsMenu.hidden = true;
      fsBtn.setAttribute('aria-expanded', 'false');
      if (focus) fsBtn.focus();
    }
    // המצב על המסך: הכפתור ⟳, והאם השכבה מסובבת
    function fsApply() {
      if (!fsOn) return;
      const land = fsMode === 'landscape';
      fsRot.hidden = !fromTall;
      fsRot.setAttribute('aria-label', land ? 'מסך מלא לגובה' : 'מסך מלא לרוחב');
      fsRot.title = land ? 'לגובה' : 'לרוחב';
      wrap.dataset.fsMode = fsMode;
      const turn = land && !locked && !lockPending && tall();
      if (turn) {
        wrap.style.setProperty('--fs-w', window.innerHeight + 'px');
        wrap.style.setProperty('--fs-h', window.innerWidth + 'px');
      }
      if (turn !== wrap.classList.contains('fs-turn')) {
        wrap.classList.toggle('fs-turn', turn);
        setTurned(turn ? wrap : null);
      }
    }
    // נעילה: לרוחב — רק בתוך Fullscreen; לגובה — רק כשכבר נעלנו לרוחב (מ-⟳). נדחתה — השכבה המסובבת
    function fsLock() {
      const want = fsMode;
      const can = document.fullscreenElement === root && typeof ori()?.lock === 'function';
      if (!fsOn || !can || (want === 'portrait' && !locked)) { fsApply(); return; }
      lockTried = true;
      lockPending = true;
      fsApply();
      ori().lock(want).then(() => { locked = true; }, () => { if (want === 'portrait') locked = false; })
        .finally(() => { if (fsMode === want) lockPending = false; fsApply(); });
    }
    function fsSetMode(mode) {
      if (!fsOn || mode === fsMode) return;
      fsMode = mode;
      fsLock();
    }
    function fsEnter(mode = 'portrait') {
      if (fsOn) return;
      fsOn = true;
      fromTall = tall();
      fsMode = mode;
      locked = lockTried = lockPending = false;
      wrap.classList.add('fs');
      document.body.classList.add('chart-fs');
      try { history.pushState({ tsFs: true }, ''); } catch { /* ריק */ }
      if (fsApi()) {
        lockPending = mode === 'landscape';   // עד שה-Fullscreen והנעילה הסתיימו — בלי סיבוב, כדי שלא יהבהב
        root.requestFullscreen({ navigationUI: 'hide' }).then(() => {
          // הבקשה אסינכרונית: יצאו לפני שהסתיימה (Esc או "חזור" מהר) — יוצאים גם מה-Fullscreen API
          if (!fsOn) { if (document.fullscreenElement) document.exitFullscreen().catch(() => {}); return; }
          lockPending = false;
          fsLock();
        }, () => { lockPending = false; fsApply(); });
      }
      fsApply();
      fsExit.focus();
    }
    /** quiet — בלי לגעת בהיסטוריה (חזור כבר קרה, או שעוזבים את המסך) */
    function fsLeave(quiet = false) {
      if (!fsOn) return;
      fsOn = false;
      if (lockTried) { try { ori()?.unlock?.(); } catch { /* ריק */ } }
      locked = lockTried = lockPending = false;
      fsMode = 'portrait';
      setTurned(null);
      wrap.classList.remove('fs', 'fs-turn');
      wrap.style.removeProperty('--fs-w');
      wrap.style.removeProperty('--fs-h');
      delete wrap.dataset.fsMode;
      document.body.classList.remove('chart-fs');
      if (document.fullscreenElement === root) document.exitFullscreen().catch(() => {});
      if (!quiet && history.state?.tsFs) history.back();
      if (!quiet) fsBtn.focus();
    }
    let fsWas = false;   // היה Fullscreen — כדי שיציאה ממנו מבחוץ (מחווה של המערכת) תוציא גם מהשכבה
    const fsPop = () => { if (fsOn && !history.state?.tsFs) fsLeave(true); };
    const fsChange = () => {
      if (document.fullscreenElement) { fsWas = true; return; }
      if (fsWas && fsOn) fsLeave();
      fsWas = false;
    };
    const fsKey = e => {
      if (e.key !== 'Escape') return;
      if (!fsMenu.hidden) fsMenuClose(true);
      else if (fsOn && !forecast.popOpen()) fsLeave();
    };
    const fsOutside = e => { if (!fsMenu.hidden && !fsPick.contains(e.target)) fsMenuClose(); };
    const fsResize = () => fsApply();
    window.addEventListener('popstate', fsPop);
    window.addEventListener('resize', fsResize);
    document.addEventListener('fullscreenchange', fsChange);
    document.addEventListener('keydown', fsKey);
    document.addEventListener('pointerdown', fsOutside);
    onTeardown(() => { window.removeEventListener('popstate', fsPop); window.removeEventListener('resize', fsResize); document.removeEventListener('fullscreenchange', fsChange);
      document.removeEventListener('keydown', fsKey); document.removeEventListener('pointerdown', fsOutside); });

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
    // ── הטעינה: בקשה אחת לנרות ולכל השיטות (18.3) ──
    // mode: open — פתיחת הגרף · change — טווח או מסגרת · manual — "↻" · pick — בחירת שיטה · auto — הרענון האוטומטי
    let loading = false;
    let pending = null;      // פעולה של המשתמש בזמן שבקשה אחרת רצה — נטענת מיד אחריה
    let timer = null;
    let lastOk = 0;          // מתי הגיעה התשובה האחרונה (שעון הדפדפן)
    const analysisOn = () => forecast.methods().length > 0;
    const active = () => document.visibilityState === 'visible' && analysisOn();
    function ago(ms) {
      const s = Math.max(0, Math.round(ms / 1000));
      return s < 60 ? `${s} ש׳` : `${Math.round(s / 60)} דק׳`;
    }
    function showAge() {
      everySel.hidden = !analysisOn();
      upd.hidden = !analysisOn() || !lastOk;
      if (!upd.hidden) upd.textContent = 'עודכן לפני ' + ago(Date.now() - lastOk);
    }
    const ageTick = setInterval(showAge, 5000);
    /** הטיימר הבא — רק כשהניתוח פעיל. אחרת אין טיימר בכלל (אין שיטה, או לשונית מוסתרת) */
    function schedule() {
      clearTimeout(timer);
      timer = null;
      if (active()) timer = setTimeout(() => load('auto'), every * 1000);
      showAge();
    }
    async function load(mode = 'change') {
      if (loading) { if (mode !== 'auto') pending = mode; return; }
      const auto = mode === 'auto';
      if (auto && !active()) { schedule(); return; }
      loading = true;
      clearTimeout(timer);
      timer = null;
      refresh.disabled = true;
      if (!auto && mode !== 'pick') { closeLive(); info.textContent = 'טוען…'; }
      try {
        chart.applyOptions({ timeScale: { timeVisible: TF_SEC[st.tf] < 86400 } });
        const r = await api('chart_refresh', null, { asset, tf: st.tf, ...rangeQuery(), methods: forecast.methods().join(',') });
        lastOk = Date.now();
        let rows = r.candles;
        const capped = r.capped || rows.length > 5000;
        if (rows.length > 5000) rows = rows.slice(-5000);
        data = rows.map(x => ({ time: x.t, open: +x.o, high: +x.h, low: +x.l, close: +x.c, volume: +x.v, closeStr: x.c }));
        const pf = priceFormat(rows);
        dec = pf.precision;
        candle.applyOptions({ priceFormat: pf });
        candle.setData(data.map(({ time, open, high, low, close }) => ({ time, open, high, low, close })));
        drawIndicators();
        // רענון אוטומטי ובחירת שיטה — לא מזיזים את התצוגה של המשתמש
        // נתונים חדשים (פתיחה, מסגרת, טווח, ↻): גם ציר המחיר חוזר ל-autoScale — טווח מוקפא של הנתונים הקודמים לא מתאים להם
        if (mode === 'open' || mode === 'change' || mode === 'manual') { chart.timeScale().fitContent(); gest.reset(); }
        draw.redraw();
        forecast.onResult(r);
        // הבדיקה לאחור — רק בפעולה של המשתמש, לא ברענון האוטומטי (18.3): אין שמורה, או שעבר יום
        if (!auto && forecast.needsBacktest()) runBacktest(mode === 'manual');
        setHeadQuote();
        const n = data.length;
        info.replaceChildren(`${n.toLocaleString('he-IL')} נרות · ${TF_LABEL[st.tf]} · ${DELAY_LABEL[r.delay] || r.delay}`,
          capped ? ' · מוצגים 5,000 הנרות האחרונים — לטווח ארוך בחרו מסגרת גדולה יותר' : '',
          r.stream || analysisOn() ? '' : ` · "↻" מושך עדכון (תוקף ${r.tail_ttl < 120 ? 'דקה' : Math.round(r.tail_ttl / 60) + ' דק׳'})`,
          n ? '' : ' · אין נתונים בטווח הזה',
          // 21.3: אין דרך להשלים (תקרה, או אין מפתח זמין) — מוצג מה שבמאגר
          r.notice ? h('span', { class: 'chart-notice', role: 'status' }, ' · ' + r.notice) : '');
        if (mode === 'manual') toast(r.provider_calls ? 'עודכן מהספק' : 'עודכן מהמאגר');
        if (r.stream && n && !ws) openLive(r.stream, r.stream_interval);
      } catch (e) {
        // רענון אוטומטי שנכשל (גם 429) — בשקט: הנתונים הקודמים נשארים, ו"עודכן לפני X" מראה כמה הם ישנים.
        // אין ניסיון חוזר מיידי — הפעם הבאה בקצב הרגיל (18.3 קריטריון 5)
        if (!auto) {
          info.textContent = '';
          info.append(h('span', { class: 'error' }, e.message));
          forecast.onError(e);
        }
      } finally {
        loading = false;
        refresh.disabled = false;
        if (pending) { const m = pending; pending = null; load(m); } else schedule();
      }
    }
    // הבדיקה לאחור (6.2) דרך analysis — פעם אחת לכל מסגרת ושילוב שיטות בפתיחה; "↻ רענן" מריץ שוב. התשובה מחליפה את הכרטיסים
    const btTried = new Set();
    async function runBacktest(force) {
      const ids = forecast.methods();
      const key = st.tf + '|' + ids.join(',');
      if (!ids.length || (!force && btTried.has(key))) return;
      btTried.add(key);
      const tf = st.tf;
      forecast.backtesting(true);
      try {
        const a = await api('analysis', null, { asset, tf, methods: ids.join(',') });
        if (tf === st.tf && forecast.methods().join(',') === ids.join(',')) forecast.onAnalysis(a); else forecast.backtesting(false);
      } catch (e) {
        forecast.backtesting(false);
        if (e.status !== 429) toast(e.message, true);
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
    // לשונית מוסתרת — הרענון עוצר (אין טיימר); גלויה שוב — בקשה אחת מיד, ואז בקצב הרגיל (18.3 קריטריון 2)
    const vis = () => {
      if (document.visibilityState !== 'visible') {
        clearTimeout(timer); timer = null;
        closeLive(); live.hidden = false; live.className = 'chip'; live.textContent = 'מושהה';
        return;
      }
      if (streamSpec && !ws) openLive(...streamSpec);
      if (analysisOn()) load('auto');
    };
    document.addEventListener('visibilitychange', vis);
    onTeardown(() => { document.removeEventListener('visibilitychange', vis); clearTimeout(timer); clearInterval(ageTick); pending = null; streamSpec = null; closeLive(); });
    await Promise.all([load('open'), draw.load()]);
  }

  // ── ספקי נתונים (המשתמש) ───────────────────────────────
  async function viewProviders() {
    setHead('ספקי נתונים', 'מאיפה מגיעים הגרפים', { back: true });
    const provs = await providers(true);
    const fmt = t => (t ? new Date(t * 1000).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
    const row = p => {
      let status, actions = null;
      if (!p.enabled) status = h('span', { class: 'chip' }, 'כבוי על ידי העורך');
      else if (!p.needs_key) status = h('span', { class: 'chip ok' }, '✓ פתוח לכולם, בלי מפתח');
      else if (p.own) {
        status = h('span', { class: 'chip ' + (p.own.last_test_ok ? 'ok' : 'bad') }, `${p.own.last_test_ok ? 'מחובר' : 'החיבור נכשל'} · נבדק ${fmt(p.own.last_test_at)} · ••••${p.own.last4}`);
        actions = h('div', { class: 'row-btns wrap' },
          h('button', { class: 'btn ghost sm', type: 'button', on: { click: async e => { e.target.disabled = true; try { await api('cred_test', { provider: p.id }); toast('החיבור תקין'); } catch (ex) { toast(ex.message, true); } PROVIDERS = null; invalidateCatalog(); route(); } } }, 'בדוק חיבור'),
          h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => connect(p) } }, 'החלפת מפתח'),
          h('button', { class: 'btn ghost sm danger', type: 'button', on: { click: () => confirmSheet('ניתוק ספק',
            p.share ? `המפתח של ${p.name} יימחק. מה שנמשך בו נשאר במאגר המשותף.` : `המפתח של ${p.name} יימחק, וגם הנתונים שנמשכו בו.`, 'ניתוק', async () => { await api('cred_delete', { provider: p.id }); PROVIDERS = null; invalidateCatalog(); toast('נותק'); route(); }) } }, 'ניתוק'));
      } else if (p.source === 'app') {
        // 21.3: מפתח האפליקציה — לכולם. מפתח אישי אפשר להוסיף כגיבוי
        status = h('span', { class: 'chip ok' }, '✓ מפתח האפליקציה — פתוח לכולם');
        actions = h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => connect(p) } }, 'מפתח משלי (גיבוי)');
      } else if (p.source === 'shared') status = h('span', { class: 'chip ok' }, '✓ מהעורך');
      else {
        status = h('span', { class: 'chip warn' }, 'לא מחובר');
        actions = h('button', { class: 'btn primary sm', type: 'button', on: { click: () => connect(p) } }, 'חיבור');
      }
      return h('li', { class: 'card', style: 'cursor:default;flex-wrap:wrap' },
        h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, p.name), h('span', { class: 't' }, DELAY_LABEL[p.delay] || '')),
          h('div', { class: 'l2' }, p.markets.map(m => MARKET_LABEL[m] || m).join(', '), sep(), p.tfs.map(t => TF_LABEL[t]).join(' · ')),
          h('div', { style: 'margin-top:6px' }, status),
          // 21.3 (2): המפתח האישי בספק משותף — גיבוי, ומה שנמשך בו לכולם
          p.needs_key && p.share && p.own ? h('p', { class: 'hint pool-note' }, 'משמש כשאין מפתח אפליקציה זמין. מה שנמשך בו נשמר במאגר המשותף.') : null),
        actions);
    };
    render(h('p', { class: 'hint' }, 'Binance ו-ECB פתוחים לכולם. לספק עם מפתח — מחברים מפתח משלכם: הוא נשמר מוצפן בשרת, ולא מוצג שוב (רק ארבעת התווים האחרונים).'),
      h('ul', { class: 'list' }, provs.map(row)));
  }

  function connect(p) {
    const secret = h('input', { type: 'password', name: 'secret', autocomplete: 'off', spellcheck: 'false', dir: 'ltr', maxlength: 200, required: true });
    const label = h('input', { name: 'label', maxlength: 60, placeholder: 'רשות — למשל "החשבון החינמי"' });
    const s = sheet(`חיבור ${p.name}`, { saveLabel: 'בדיקה ושמירה', onSave: async () => {
      await api('cred_save', { provider: p.id, secret: secret.value, label: label.value });
      secret.value = '';
      PROVIDERS = null; invalidateCatalog();
      toast(`${p.name} מחובר`);
      route();
    } });
    s.body.append(
      p.key_help?.note ? h('p', { class: 'hint' }, p.key_help.note) : null,
      p.key_help?.url ? h('p', {}, h('a', { href: p.key_help.url, target: '_blank', rel: 'noopener noreferrer' }, 'לקבלת מפתח ↗')) : null,
      h('div', { class: 'fields' }, field(p.key_help?.label || 'מפתח', secret, true), field('תווית', label, true)),
      p.share ? h('p', { class: 'hint pool-note' }, 'משמש כשאין מפתח אפליקציה זמין. מה שנמשך בו נשמר במאגר המשותף.') : null,
      h('p', { class: 'hint' }, 'בשמירה המפתח נבדק מול הספק. הוא נשמר מוצפן, ולא יוצג שוב.'));
  }

  // ── ממשק העורכים (פרק 24) — השרת בודק בכל פעולה; כאן רק התצוגה ──────
  async function viewAdmin() {
    setHead('ניהול', 'ספקים, שיטות, עורכים ומאגר', { back: true });
    const S = await api('admin_state');
    let tab = sessionStorage.getItem('ts-admin-tab') || 'providers';
    if (tab === 'admins') tab = 'editors';   // השם הקודם של הלשונית (עד פרק 24)
    const tabs = [['providers', '🔌 ספקים'], ['methods', '🧪 שיטות'], ['shared', '🤝 לחלוקה'], ['editors', '👤 עורכים'], ['cache', '🗄️ מאגר'], ['journal', '📓 יומן השיטה'], ['log', '📜 יומן']];
    const bar = h('div', { class: 'seg-tabs', role: 'tablist' }, tabs.map(([k, l]) => h('button', { role: 'tab', type: 'button', 'aria-selected': String(k === tab), on: { click: () => { sessionStorage.setItem('ts-admin-tab', k); route(); } } }, l)));
    const body = { providers: adminProviders, methods: () => ctx.adminMethods(), shared: adminShared, editors: adminEditors, cache: adminCache, journal: () => ctx.journalView(h('div', { class: 'stack method-journal' })), log: adminLog }[tab](S);
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
    const sw = h('input', { type: 'checkbox', disabled: !S.is_chief }); sw.checked = S.shared_enabled;
    sw.addEventListener('change', () => confirmSheet(sw.checked ? 'הפעלת ספקים לחלוקה' : 'כיבוי ספקים לחלוקה',
      sw.checked ? 'להפעיל רק אחרי שנבחרה תוכנית מסחרית שמתירה הצגה לצד שלישי (הכרעה ✅2). הספקים סופרים מי רואה את הנתונים, לא רק מי שמושך.' : 'משתמשים שקיבלו ספק מהעורך יאבדו אליו גישה מיד.',
      sw.checked ? 'הפעלה' : 'כיבוי', async () => { await api('admin_shared_switch', { on: sw.checked }); reload(); }));
    const userOpts = S.users.map(u => [u.id, u.display_name || u.email || ('#' + u.id)]);
    const credRow = c => {
      const user = select('user', userOpts, '', { 'aria-label': 'משתמש' });
      const lim = numInput('daily_limit', '100');
      return h('li', { class: 'card', style: 'cursor:default;display:block' },
        h('div', { class: 'l1' }, h('span', {}, provName(c.provider) + (c.label ? ' · ' + c.label : '')), h('span', { class: 't num' }, '••••' + c.last4)),
        appKeyRow(c),
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
    // מפתח האפליקציה (21.3): מפתח אחד לכל ספק משותף, שהאפליקציה פונה בו בשביל כולם. רק עורך ראשי מסמן
    const appKeyRow = c => {
      const ak = (S.app_keys || {})[c.provider];
      if (!ak) return null;
      const box = h('input', { type: 'checkbox', 'aria-label': 'מפתח האפליקציה', disabled: !S.is_chief }); box.checked = !!c.app_key;
      box.addEventListener('change', async () => {
        try { await api('admin_app_key', { provider: c.provider, cred_id: box.checked ? c.id : null }); toast(box.checked ? 'מפתח האפליקציה סומן' : 'הסימון בוטל'); }
        catch (e) { toast(e.message, true); }
        reload();
      });
      const lim = numInput('app_user_limit', String(ak.user_limit));
      return h('div', { class: 'stack app-key' },
        h('label', { class: 'switch' }, box, h('span', {}, h('b', {}, '⭐ מפתח האפליקציה'), c.app_key ? ' · פעיל — לכל המשתמשים' : '')),
        h('p', { class: 'hint' }, 'פונים בו בשביל כולם, ומה שנמשך נשמר במאגר המשותף. לכל ספק — מפתח אחד.', S.is_chief ? '' : ' רק עורך ראשי מסמן.'),
        c.app_key && S.is_chief ? h('div', { class: 'toolbar' }, field('פניות ליום למשתמש', lim), h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => {
          try { await api('admin_app_key', { provider: c.provider, cred_id: c.id, user_limit: lim.value.trim() }); toast('נשמר'); reload(); } catch (e) { toast(e.message, true); }
        } } }, 'שמירה')) : null);
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
        h('label', { class: 'switch' }, sw, h('span', {}, h('b', {}, 'המתג הראשי — ספקים מהעורך'), S.shared_enabled ? ' · דלוק' : ' · כבוי')),
        h('p', { class: 'hint' }, 'כבוי בשלב א\' (הכרעה ✅2) עד שתיבחר תוכנית מסחרית שמתירה הצגה לצד שלישי. כשכבוי — מפתח ושיוכים נשמרים, אבל אף משתמש לא מקבל דרכם נתונים. מפתח האפליקציה (⭐) — לא תלוי במתג.',
          S.is_chief ? '' : ' רק עורך ראשי משנה את המתג.')),
      h('div', { class: 'toolbar' }, h('button', { class: 'btn primary', type: 'button', on: { click: add } }, '+ מפתח לחלוקה')),
      S.creds.length ? h('ul', { class: 'list' }, S.creds.map(credRow)) : h('p', { class: 'empty' }, 'אין מפתחות לחלוקה.'));
  }

  // ── עורכים (פרק 24.5): עורך ראשי ממנה, משנה ומבטל; עורך משני רואה את הרשימה בלבד. השרת אוכף (ts_role_grant / ts_role_revoke)
  const ROLE_NAME = { chief: 'עורך ראשי', editor: 'עורך משני' };
  function adminEditors(S) {
    const chief = !!S.is_chief;
    const when = t => new Date(t * 1000).toLocaleDateString('he-IL');
    const act = async (action, body, ok) => { try { await api(action, body); toast(ok); reload(); } catch (e) { toast(e.message, true); } };
    const row = a => {
      const self = a.user_id === S.me_id;
      const other = a.role === 'chief' ? 'editor' : 'chief';
      const tag = a.gate ? 'עורך ראשי · מהשער' : ROLE_NAME[a.role];
      const btns = chief && !a.gate && !self ? h('div', { class: 'row-btns' },
        h('button', { class: 'btn ghost sm', type: 'button', 'data-role-to': other, on: { click: () => act('admin_role_add', { user_id: a.user_id, role: other }, 'התפקיד שונה') } },
          other === 'chief' ? 'לעורך ראשי' : 'לעורך משני'),
        h('button', { class: 'btn ghost sm danger', type: 'button', 'data-revoke': '', on: { click: () => confirmSheet('ביטול עורך', `${a.display_name || a.email} יחזור להיות משתמש, מהבקשה הבאה.`, 'ביטול',
          () => act('admin_role_remove', { user_id: a.user_id }, 'בוטל')) } }, 'בטל')) : null;
      return h('li', { class: 'card editor-row', style: 'cursor:default', 'data-user': a.user_id },
        h('div', { class: 'body' },
          h('div', { class: 'l1' }, h('span', {}, a.display_name || a.email || '—'), h('span', { class: 'chip' + (a.role === 'chief' ? ' ok' : '') }, tag)),
          h('div', { class: 'l2' }, [a.email || '', self ? 'אתם' : ''].filter(Boolean).join(' · ')),
          a.granted_by_name ? h('div', { class: 'l2 granted' }, `מינה: ${a.granted_by_name} · ${when(a.granted_at)}`) : null),
        btns);
    };
    const appoint = () => {
      const free = S.users.filter(u => !S.editors.some(a => a.user_id === u.id));
      if (!free.length) { toast('כל המשתמשים כבר עורכים'); return; }
      const user = select('user', free.map(u => [u.id, u.display_name || u.email]), '', { 'aria-label': 'משתמש' });
      const sh = sheet('מינוי עורך', { saveLabel: 'מינוי', onSave: async () => {
        if (!user.value) throw new Error('בחרו משתמש');
        await api('admin_role_add', { user_id: +user.value, role: segVal(sh.body, 'role') });
        toast('מונה'); reload();
      } });
      sh.body.append(field('משתמש', user),
        h('div', { class: 'f' }, h('span', {}, 'תפקיד'), seg('role', [['editor', 'עורך משני'], ['chief', 'עורך ראשי']], 'editor', { label: 'תפקיד' })),
        h('p', { class: 'hint' }, 'עורך ראשי — גם פרמטרים, "החל", מפתח האפליקציה, המתג, תפקידים וסיסמאות. עורך משני — כל השאר.'));
    };
    return h('div', { class: 'stack' },
      h('p', { class: 'hint' }, chief
        ? 'מי שמגיע מהכלים הדיגיטליים — תמיד עורך ראשי. עורך ראשי ממנה ומבטל עורכים; הביטול חל מיד. את עצמכם — עורך ראשי אחר מבטל.'
        : 'רק עורך ראשי ממנה ומבטל עורכים.'),
      chief ? h('div', { class: 'toolbar' }, h('button', { class: 'btn primary', type: 'button', on: { click: appoint } }, '+ מינוי עורך')) : null,
      S.editors.length ? h('ul', { class: 'list editors' }, S.editors.map(row)) : h('p', { class: 'empty' }, 'אין עורכים.'),
      chief ? adminPasswords(S) : null);
  }

  // איפוס "סיסמה לאפליקציה" (spec-stage-a 20.1 ד', ש9) — עורך ראשי בלבד. הסיסמה נמחקת; המשתמש קובע חדשה כשהוא מחובר
  function adminPasswords(S) {
    const withPw = S.users.filter(u => +u.has_password);
    return h('div', { class: 'stack' },
      h('h3', { class: 'sec-title' }, '🔑 סיסמאות לאפליקציה'),
      h('p', { class: 'hint' }, 'לכניסה כשהאפליקציה מנותקת מהכלים הדיגיטליים. משתמש ששכח — מאפסים כאן, והוא קובע חדשה בפרופיל אחרי שנכנס דרך הכלים הדיגיטליים.'),
      withPw.length ? h('ul', { class: 'list' }, withPw.map(u => h('li', { class: 'card', style: 'cursor:default' },
        h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, u.display_name || u.email)), h('div', { class: 'l2' }, u.email || '')),
        h('button', { class: 'btn ghost sm danger', type: 'button', on: { click: () => confirmSheet('איפוס סיסמה', `הסיסמה של ${u.display_name || u.email} תימחק, וכניסות בסיסמה שלו ייסגרו.`, 'איפוס',
          async () => { await api('admin_password_reset', { user_id: u.id }); toast('הסיסמה אופסה'); reload(); }) } }, 'איפוס'))))
        : h('p', { class: 'empty' }, 'לאף משתמש אין סיסמה.'));
  }

  function adminCache(S) {
    const kb = Math.round(S.cache.bytes / 1024);
    return h('div', { class: 'stack' },
      h('div', { class: 'summary' }, h('div', { class: 'line' }, h('span', { class: 'muted' }, 'גודל המאגר'), h('b', { class: 'num' }, kb.toLocaleString('he-IL') + ' KB'),
        h('span', { class: 'muted' }, ' · ניקוי אחרון ' + (S.cache.cleanup_at ? new Date(S.cache.cleanup_at * 1000).toLocaleString('he-IL') : '—')))),
      h('p', { class: 'hint' }, 'המאגר שומר 60 יום אחורה ומתנקה פעם ביום. Binance, ECB ו-Twelve Data — מאגר משותף, בכל מפתח. Finnhub במפתח אישי — רק של אותו משתמש.'),
      S.cache.by.length ? h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, h('th', {}, 'ספק'), h('th', {}, 'סוג'), h('th', { class: 'n' }, 'נרות'), h('th', { class: 'n' }, 'הוותיק'))),
        h('tbody', {}, S.cache.by.map(r => h('tr', {}, h('td', {}, provName(r.provider)), h('td', {}, { shared: 'משותף', personal: 'אישי', granted: 'מהעורך' }[r.kind]),
          h('td', { class: 'n num' }, r.candles.toLocaleString('he-IL')), h('td', { class: 'n num' }, new Date(r.oldest * 1000).toLocaleDateString('he-IL')))))) : h('p', { class: 'empty' }, 'המאגר ריק.'),
      adminFetchLog(S.fetch_log || []),
      adminMethodRuns(S.method_runs || []),
      adminPairs(S.pairs || []),
      h('div', { class: 'row-btns wrap' },
        h('button', { class: 'btn ghost', type: 'button', on: { click: async () => { const r = await api('admin_cache_cleanup', {}); toast(`נמחקו ${r.deleted} נרות ישנים`); reload(); } } }, '🧹 ניקוי ישנים מ-60 יום'),
        h('button', { class: 'btn ghost danger', type: 'button', on: { click: () => confirmSheet('ריקון המאגר', 'כל הנרות יימחקו. הגרפים יימשכו שוב מהספקים.', 'ריקון', async () => { await api('admin_cache_clear', {}); reload(); }) } }, 'ריקון הכול')));
  }

  // יומן בקשות הנרות (18.2, S2): כמה נרות נמשכו מהספק בכל בקשה, מול כמה הוגשו — 0 = הכול מהמאגר
  function adminFetchLog(rows) {
    const kind = { shared: 'משותף', personal: 'אישי', granted: 'מהעורך' };
    const time = t => new Date(t * 1000).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    return h('section', { class: 'block' },
      h('h3', {}, 'יומן בקשות נרות'),
      h('p', { class: 'hint' }, '"מהספק" — כמה נרות נמשכו מהספק בבקשה. 0 — הכול הוגש מהמאגר. מאגר משותף נמשך פעם אחת לכולם; אישי — כל משתמש לעצמו.'),
      rows.length ? h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
        h('thead', {}, h('tr', {}, h('th', {}, 'מתי'), h('th', {}, 'מי'), h('th', {}, 'נכס'), h('th', {}, 'מאגר'), h('th', { class: 'n' }, 'מהספק'), h('th', { class: 'n' }, 'הוגשו'))),
        h('tbody', {}, rows.map(r => h('tr', {},
          h('td', { class: 'num' }, time(r.at)), h('td', {}, r.user || '—'),
          h('td', {}, h('span', { dir: 'ltr' }, r.asset + ' · ' + r.tf)), h('td', {}, kind[r.kind] || r.kind),
          h('td', { class: 'n num' }, r.provider_candles.toLocaleString('he-IL') + (r.provider_calls ? ` (${r.provider_calls})` : '')),
          h('td', { class: 'n num' }, r.served.toLocaleString('he-IL')))))))
        : h('p', { class: 'empty' }, 'אין עדיין בקשות.'));
  }

  // מונה ריצות השיטות (18.3, S3): "רצה" — חישוב אמיתי; "מהשמור" — אותו נר סגור, התוצאה הקודמת חזרה בלי להריץ
  function adminMethodRuns(rows) {
    const time = t => (t ? new Date(t * 1000).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
    return h('section', { class: 'block method-runs' },
      h('h3', {}, 'ריצות שיטה ברענון'),
      h('p', { class: 'hint' }, '"רצה" עולה רק כשנסגר נר חדש (או השתנו הפרמטרים). רענון בלי נר חדש — "מהשמור", והשיטה לא רצה.'),
      rows.length ? h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
        h('thead', {}, h('tr', {}, h('th', {}, 'שיטה'), h('th', { class: 'n' }, 'רצה'), h('th', { class: 'n' }, 'מהשמור'), h('th', {}, 'ריצה אחרונה'))),
        h('tbody', {}, rows.map(r => h('tr', { 'data-method': r.method_id },
          h('td', {}, h('span', { dir: 'ltr' }, r.method_id)),
          h('td', { class: 'n num runs' }, r.runs.toLocaleString('he-IL')),
          h('td', { class: 'n num reused' }, r.reused.toLocaleString('he-IL')),
          h('td', { class: 'num' }, time(r.last_run_at)))))))
        : h('p', { class: 'empty' }, 'אף שיטה עוד לא רצה ברענון.'));
  }

  // קטלוג הזוגות (18.1 קריטריון 4): כאן — ורק כאן — הסימולים גלויים, לבדיקה
  function adminPairs(rows) {
    return h('details', { class: 'block' },
      h('summary', {}, `🔤 קטלוג הזוגות (${rows.length}) — שם ← סימול`),
      h('p', { class: 'hint' }, 'למשתמש מוצג רק השם. הנכס הראשון שיש לו ספק — הוא שנפתח. "1/" — זוג מחושב (ההופכי).'),
      h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
        h('thead', {}, h('tr', {}, h('th', {}, 'זוג'), h('th', {}, 'נכסים (לפי סדר)'))),
        h('tbody', {}, rows.map(r => h('tr', {}, h('td', {}, pairLabel(r), r.computed ? ' · מחושב' : ''),
          h('td', {}, h('span', { dir: 'ltr' }, r.assets.join(' · ')))))))));
  }

  function adminLog(S) {
    return S.log.length ? h('ul', { class: 'list' }, S.log.map(l => h('li', { class: 'card', style: 'cursor:default' },
      h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, l.action), h('span', { class: 't' }, new Date(l.at * 1000).toLocaleString('he-IL'))),
        h('div', { class: 'l2' }, (l.display_name || '') + (l.target ? ' · ' + l.target : ''))))))
      : h('p', { class: 'empty' }, 'אין עדיין פעולות של עורכים.');
  }

  return {
    viewMarket, viewChart, viewProviders, viewAdmin, teardown,
    setRoute: fn => { route = fn; },
  };
}
