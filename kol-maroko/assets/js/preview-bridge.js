/*
 * הגשר של התצוגה החיה (פרוסה F4). מוזרק על ידי lib/preview.php לכל מסמך HTML שמוגש בתצוגה, ורץ **בתוך המסגרת
 * המבודדת** — במקור אטום, לצד הקוד של בעל העמוד. לכן אין לו שום סמכות: הוא רק מדווח להורה (שורת מסוף, כותרת,
 * "נלחץ אלמנט"), וההורה מתייחס לכל הודעה כקלט לא מהימן (S5; assets/js/live.js).
 * כשהעמוד נפתח ישירות בלשונית (בלי הורה) — הגשר אינו עושה דבר.
 */
(() => {
  'use strict';
  if (window.parent === window || window.__kolBridge) return;
  window.__kolBridge = true;
  const me = document.currentScript, ROOT = me ? me.src.replace(/__kol\/bridge\.js.*$/, '') : '';
  const rel = u => { try { u = String(u); return decodeURIComponent((u.startsWith(ROOT) ? u.slice(ROOT.length) : u).split(/[?#]/)[0]); } catch { return String(u); } };
  const here = () => rel(location.href) || 'index.html';
  const post = (t, o) => { try { parent.postMessage(Object.assign({ kol: 'pv', t }, o), '*'); } catch { /* ההורה איננו */ } };
  const cut = (s, n) => { s = String(s); return s.length > n ? s.slice(0, n) + '…' : s; };

  // ── מסוף ────────────────────────────────────────────────
  const show = v => {
    if (typeof v === 'string') return v;
    if (v instanceof Error) return v.stack || (v.name + ': ' + v.message);
    if (v instanceof Element) return '<' + v.tagName.toLowerCase() + (v.id ? '#' + v.id : '') + '>';
    if (typeof v === 'function') return 'ƒ ' + (v.name || '');
    try { const j = JSON.stringify(v); return j === undefined ? String(v) : j; } catch { return String(v); }
  };
  /** השורה בקוד של העמוד שקראה לנו: המסגרת הראשונה במחסנית שאינה הגשר. */
  const caller = () => {
    const lines = String(new Error().stack || '').split('\n');
    for (const l of lines) {
      const m = l.match(/(https?:\/\/[^\s)]+?):(\d+):\d+\)?\s*$/);
      if (m && !m[1].includes('__kol/bridge.js')) return { file: rel(m[1]), line: +m[2] };
    }
    return {};
  };
  const line = (level, text, at, limit) => post('console', { level, text: cut(text, 4000), file: cut(at.file || '', 400), line: at.line | 0, limit: limit || '' });
  for (const level of ['log', 'info', 'warn', 'error', 'debug']) {
    const orig = console[level];
    console[level] = function (...a) {
      try { line(level, a.map(show).join(' '), caller()); } catch { /* המסוף של העמוד לעולם אינו נשבר בגללנו */ }
      return orig.apply(this, a);
    };
  }
  addEventListener('error', e => {
    const t = e.target;
    if (t && t !== window && t.nodeType === 1) {            // משאב שלא נטען: תמונה, סקריפט, סגנון, שמע
      const u = t.currentSrc || t.src || t.href || '';
      line('error', 'המשאב לא נטען: ' + rel(u) + '  (<' + t.tagName.toLowerCase() + '>)', { file: here() });
    } else {
      line('error', e.message || 'שגיאה', { file: rel(e.filename || ''), line: e.lineno });
    }
  }, true);
  addEventListener('unhandledrejection', e => line('error', 'Promise נדחה בלי טיפול: ' + show(e.reason), {}));

  // ── מה לא יעבוד בארגז החול — מזוהה מהגישה בפועל, לא מניחוש ──
  const said = new Set();
  const limit = (kind, text) => { if (said.has(kind)) return; said.add(kind); line('warn', text, caller(), kind); };
  const trap = (obj, proto, name, kind, text) => {
    try {
      const d = Object.getOwnPropertyDescriptor(proto, name);
      if (!d || !d.get) return;
      Object.defineProperty(obj, name, {
        configurable: true,
        get() { limit(kind, text); return d.get.call(this); },
        set: d.set ? function (v) { limit(kind, text); return d.set.call(this, v); } : undefined,
      });
    } catch { /* דפדפן שאינו מרשה — מוותרים על הזיהוי, לא על העמוד */ }
  };
  const STORE = 'העמוד ניגש לאחסון בדפדפן (%s). בארגז החול אין אחסון — הקריאה זורקת חריגה.';
  trap(window, Window.prototype, 'localStorage', 'storage', STORE.replace('%s', 'localStorage'));
  trap(window, Window.prototype, 'sessionStorage', 'storage', STORE.replace('%s', 'sessionStorage'));
  trap(window, Window.prototype, 'indexedDB', 'storage', STORE.replace('%s', 'indexedDB'));
  trap(document, Document.prototype, 'cookie', 'storage', STORE.replace('%s', 'document.cookie'));
  trap(navigator, Navigator.prototype, 'serviceWorker', 'sw', 'העמוד ניגש ל-Service Worker. בארגז החול אי אפשר לרשום אותו.');
  const embeds = () => {
    for (const f of document.querySelectorAll('iframe[src]')) {
      if (/(^|\.)(youtube(-nocookie)?\.com|youtu\.be|vimeo\.com)$/.test((() => { try { return new URL(f.src).hostname; } catch { return ''; } })())) {
        limit('embed', 'בעמוד יש נגן מוטמע (' + new URL(f.src).hostname + '). נגן מוטמע יורש את ארגז החול ואינו מתנגן בו.');
      }
    }
  };

  // ── כותרת, מוכנות, מיקום הגלילה ──────────────────────────
  let title = null;
  const tell = () => { if (document.title !== title) { title = document.title; post('title', { title: cut(title, 300) }); } };
  // מיקום הגלילה נשמר ב-window.name (אין אחסון במקור אטום), ורק כשהעמוד עצמו אינו משתמש בו
  const NAME = 'kolpv:';
  const mine = () => window.name === '' || window.name.startsWith(NAME);
  const keep = () => { if (mine()) window.name = NAME + JSON.stringify({ p: here(), x: scrollX | 0, y: scrollY | 0 }); };
  let saved = null;
  try { if (window.name.startsWith(NAME)) saved = JSON.parse(window.name.slice(NAME.length)); } catch { /* שם של מישהו אחר */ }
  addEventListener('pagehide', keep);
  let st = 0;
  addEventListener('scroll', () => { clearTimeout(st); st = setTimeout(keep, 150); }, { passive: true });
  addEventListener('DOMContentLoaded', () => { post('ready', { path: cut(here(), 400) }); tell(); embeds(); });
  addEventListener('load', () => {
    if (saved && saved.p === here()) scrollTo(saved.x | 0, saved.y | 0);
    tell(); embeds();
    setInterval(tell, 1000);
  });

  // ── כלי הבדיקה ───────────────────────────────────────────
  let srcP = null, box = null, picking = false;
  /** הקובץ והשורה שבהם האלמנט כתוב — או null כשהוא נוצר בזמן ריצה. משווים למסמך כפי שהוא בקובץ, בלי להריץ אותו. */
  async function locate(el) {
    try {
      const src = await (srcP = srcP || fetch(location.href.split('#')[0]).then(r => r.text()));
      const pure = new DOMParser().parseFromString(src, 'text/html');
      const chain = [];
      for (let n = el; n && n !== document.documentElement; n = n.parentElement) chain.unshift([...n.parentElement.children].indexOf(n));
      let twin = pure.documentElement;
      for (const i of chain) twin = twin && twin.children[i];
      if (!twin || twin.tagName !== el.tagName || (twin.id || '') !== (el.id || '')) twin = el.id ? pure.getElementById(el.id) : null;
      if (!twin || twin.tagName !== el.tagName) return null;
      const tag = el.tagName.toLowerCase(), all = [...pure.getElementsByTagName(tag)], k = all.indexOf(twin);
      const blank = s => s.replace(/[^\n]/g, ' ');
      const masked = src.replace(/<!--[\s\S]*?-->|<(script|style|textarea|title)\b[^>]*>[\s\S]*?<\/\1\s*>/gi,
        (m, t) => t ? m.replace(/^(<[^>]*>)([\s\S]*)$/, (a, open, rest) => open + blank(rest)) : blank(m));
      const hits = [...masked.matchAll(new RegExp('<' + tag.replace(/[^a-z0-9-]/g, '') + '(?=[\\s/>])', 'gi'))];
      if (hits.length !== all.length || k < 0) return { file: here(), line: 0 };      // תגית שהדפדפן השלים לבד — קובץ בלי שורה
      return { file: here(), line: masked.slice(0, hits[k].index).split('\n').length };
    } catch { return null; }
  }
  const frame = el => {
    if (!box) {
      box = document.createElement('div');
      box.style.cssText = 'position:fixed;z-index:2147483647;pointer-events:none;border:2px solid #2563eb;background:rgba(37,99,235,.12);box-sizing:border-box;margin:0;padding:0';
    }
    if (!el) { box.remove(); return; }
    const r = el.getBoundingClientRect();
    Object.assign(box.style, { left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' });
    if (!box.isConnected) document.documentElement.append(box);
  };
  const over = e => { if (picking && e.target.nodeType === 1 && e.target !== box) frame(e.target); };
  const click = async e => {
    if (!picking || e.target.nodeType !== 1) return;
    e.preventDefault(); e.stopImmediatePropagation();
    const el = e.target, r = el.getBoundingClientRect(), at = await locate(el);
    frame(el);
    post('pick', {
      tag: el.tagName.toLowerCase(), id: cut(el.id || '', 200), classes: cut(el.getAttribute('class') || '', 600),
      file: at ? cut(at.file, 400) : '', line: at ? at.line | 0 : 0, made: !at, w: Math.round(r.width), h: Math.round(r.height),
    });
  };
  addEventListener('mouseover', over, true);
  addEventListener('click', click, true);

  // ── פקודות מהסטודיו (רק מההורה). אין בהן דבר שהעמוד עצמו אינו יכול לעשות ──
  addEventListener('message', e => {
    const d = e.data;
    if (e.source !== parent || !d || d.kol !== 'pv') return;
    if (d.cmd === 'inspect') { picking = !!d.on; if (!picking) frame(null); document.documentElement.style.cursor = picking ? 'crosshair' : ''; }
    if (d.cmd === 'css') {                                // סגנון שנשמר: מחליפים רק אותו, בלי לטעון את העמוד מחדש
      let hit = false;
      for (const l of document.querySelectorAll('link[rel~="stylesheet"][href]')) {
        if (rel(l.href) === d.path) { l.href = ROOT + encodeURI(d.path) + '?kolv=' + Date.now(); hit = true; }
      }
      if (!hit) { keep(); location.reload(); }            // נטען ב-@import או מ-JS — טוענים מחדש, עם מיקום הגלילה
    }
  });
})();
