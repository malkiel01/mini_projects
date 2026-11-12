/**
 * העורך החזותי — מסגרת, פינות, צל, אפקטים, מסיכה ומפריד צורה (פרוסה V10, 03 §4.1–§4.4; 01-gap 3.6–3.8, 3.10).
 *
 * שני חלקים: חלק טהור (KolFx.* — פירוק וחיבור של ערכים, הצורות, בדיקת הזרקה; נבדק ב-tests/editor/effects.mjs), וממשק
 * בדפדפן (KolFx.Fx — הקבוצות בלשונית "🎨 עיצוב", וידיות הרדיוס והצל על הקנבס).
 * כל ערך שנכתב לקובץ עובר checkFx (בלי < > { } ; ובלי javascript: / expression( / הערות) ואחריו checkValue של style.js
 * (CSS.supports). url() — רק דרך urlCss של background.js. כל ערך מהעמוד — כטקסט; אין innerHTML.
 */
(function (g) {
  'use strict';
  const K = () => g.KolSrc, ST = () => g.KolStyle;
  const fail = msg => { const E = K() && K().SrcError; if (E) throw new E(msg, 'invalid'); const e = new Error(msg); e.code = 'invalid'; throw e; };

  // ── בדיקת ערך לפני כתיבה ─────────────────────────────────
  /** ערך CSS שמותר לכתוב: בלי < > { } ; (סוגרים הצהרה / כלל / תגית), בלי הערות, javascript:, expression(; סוגריים ומרכאות
   *  מאוזנים. url( — רק כשמותר במפורש (opt.url) ורק בכתובת שעברה checkUrl. מחזיר את הערך המנוקה. */
  function checkFx(v, opt = {}) {
    v = String(v == null ? '' : v).trim();
    if (!v) fail('ערך ריק');
    if (v.length > 2000 || /[<>{};\\]|\/\*|javascript:|expression\(|@import/i.test(v)) fail('ערך לא חוקי — בלי < > { } ; \\ ובלי הערות: ' + v.slice(0, 60));
    if (/url\(/i.test(v)) {
      if (!opt.url) fail('url() נכתב רק מבחירה בספרייה');
      for (const m of v.matchAll(/url\(\s*(['"]?)([^'")]*)\1\s*\)/gi)) g.KolBg.checkUrl(decodeURIComponent(m[2]));
    }
    let depth = 0, q = '';
    for (const ch of v) { if (q) { if (ch === q) q = ''; continue; } if (ch === '"' || ch === "'") q = ch; else if (ch === '(') depth++; else if (ch === ')' && --depth < 0) fail('סוגריים לא מאוזנים'); }
    if (depth || q) fail('סוגריים או מרכאות לא סגורים');
    return v;
  }
  /** checkFx + checkValue (CSS.supports בדפדפן) — לכל [מאפיין, ערך] שנכתב מכאן. */
  function checkSets(sets, opt) { for (const [p, v] of sets) if (v != null) { checkFx(v, opt); if (ST() && ST().checkValue) ST().checkValue(p, v); } return sets; }

  const splitTop = v => (ST() ? ST().splitTop(v) : String(v).trim().split(/\s+/));
  /** פסיקים ברמה העליונה (מחוץ לסוגריים). */
  function commas(v) { const out = []; let cur = '', d = 0; for (const ch of String(v || '')) { if (ch === '(') d++; else if (ch === ')') d--; if (ch === ',' && !d) { out.push(cur.trim()); cur = ''; } else cur += ch; } if (cur.trim()) out.push(cur.trim()); return out; }
  const LEN = /^-?(\d+\.?\d*|\.\d+)([a-z%]*)$/i;
  const isLen = x => LEN.test(x) || /^(calc|min|max|clamp)\(/i.test(x);

  // ── צל ─────────────────────────────────────────────────────
  /** box-shadow / text-shadow ← שכבות {x, y, blur, spread, color, inset}; null — לא מפורק (var() במקום אורך, כמה צבעים).
   *  "none" ← []. text — צל טקסט (בלי פריסה ופנימי). */
  function parseShadows(v, text) {
    v = String(v == null ? '' : v).trim(); if (!v || /^none$/i.test(v)) return [];
    const out = [];
    for (const layer of commas(v)) {
      const t = splitTop(layer); let inset = false; const lens = [], other = [];
      for (const x of t) { if (/^inset$/i.test(x)) inset = true; else if (isLen(x)) lens.push(x); else other.push(x); }
      if (lens.length < 2 || lens.length > (text ? 3 : 4) || other.length > 1 || (text && inset)) return null;
      if (other.some(x => /^var\(/i.test(x)) && t.filter(x => /^var\(/i.test(x)).length > 1) return null;
      out.push({ x: lens[0], y: lens[1], blur: lens[2] || '0px', spread: text ? null : lens[3] || '0px', color: other[0] || 'currentcolor', inset });
    }
    return out;
  }
  const z = x => (/^-?0+(\.0+)?[a-z%]*$/i.test(String(x)) ? '0' : String(x));
  function joinShadows(list, text) {
    if (!list.length) return 'none';
    return list.map(s => [s.inset && !text ? 'inset' : '', z(s.x), z(s.y), z(s.blur), text || s.spread == null || z(s.spread) === '0' ? '' : z(s.spread), s.color].filter(Boolean).join(' ')).join(', ');
  }

  // ── פילטרים ────────────────────────────────────────────────
  /** [שם, תווית, יחידה, מינימום, מקסימום, ערך ניטרלי] — כל אחד מחוון עם איפוס. */
  const FILTERS = [['blur', 'טשטוש', 'px', 0, 40, 0], ['brightness', 'בהירות', '%', 0, 300, 100], ['contrast', 'ניגודיות', '%', 0, 300, 100], ['grayscale', 'גווני אפור', '%', 0, 100, 0],
    ['hue-rotate', 'גוון', 'deg', 0, 360, 0], ['saturate', 'רוויה', '%', 0, 300, 100], ['sepia', 'ספיה', '%', 0, 100, 0], ['invert', 'היפוך', '%', 0, 100, 0], ['opacity', 'שקיפות (מסנן)', '%', 0, 100, 100]];
  /** filter ← [{fn, arg}] בסדר שבקובץ; null — url() / var() שלם / משהו שאינו פונקציה. */
  function parseFilter(v) {
    v = String(v == null ? '' : v).trim(); if (!v || /^none$/i.test(v)) return [];
    const out = [];
    for (const x of splitTop(v)) { const m = /^([a-z-]+)\((.*)\)$/i.exec(x); if (!m || /^url$/i.test(m[1])) return null; out.push({ fn: m[1].toLowerCase(), arg: m[2].trim() }); }
    return out;
  }
  const joinFilter = list => (list.length ? list.map(f => f.fn + '(' + f.arg + ')').join(' ') : 'none');
  /** קובע פונקציה אחת (או מסיר — arg null), ושומר על השאר ועל הסדר. */
  function setFilter(list, fn, arg) { const out = list.filter(f => f.fn !== fn || arg != null).map(f => (f.fn === fn ? { fn, arg } : f)); if (arg != null && !out.some(f => f.fn === fn)) out.push({ fn, arg }); return out; }

  // ── transform ──────────────────────────────────────────────
  /** transform ← {tx, ty, rot, sx, sy, kx, ky}; null — matrix / var() / פונקציה אחרת / סדר שאי אפשר לשמור בלי לשנות את המראה. */
  function parseTransform(v) {
    v = String(v == null ? '' : v).trim(); const t = { tx: '0px', ty: '0px', rot: '0deg', sx: '1', sy: '1', kx: '0deg', ky: '0deg' };
    if (!v || /^none$/i.test(v)) return t;
    const order = ['translate', 'rotate', 'scale', 'skew']; let at = 0;
    for (const x of splitTop(v)) {
      const m = /^([a-z]+)(X|Y|Z)?\((.*)\)$/i.exec(x); if (!m || /var\(/i.test(m[3])) return null;
      const fn = m[1].toLowerCase(), ax = (m[2] || '').toUpperCase(), a = commas(m[3]).length > 1 ? commas(m[3]) : [m[3].trim()];
      const k = order.indexOf(fn); if (k < 0 || k < at || ax === 'Z') return null; at = k;
      if (fn === 'translate') { if (ax === 'X') t.tx = a[0]; else if (ax === 'Y') t.ty = a[0]; else { t.tx = a[0]; t.ty = a[1] || '0px'; } }
      else if (fn === 'rotate') { if (ax) return null; t.rot = a[0]; }
      else if (fn === 'scale') { if (ax === 'X') t.sx = a[0]; else if (ax === 'Y') t.sy = a[0]; else { t.sx = a[0]; t.sy = a[1] == null ? a[0] : a[1]; } }
      else if (fn === 'skew') { if (ax === 'X') t.kx = a[0]; else if (ax === 'Y') t.ky = a[0]; else { t.kx = a[0]; t.ky = a[1] || '0deg'; } }
    }
    return t;
  }
  const zero = x => /^-?0*\.?0+(px|deg|%|turn|rad|em|rem)?$/i.test(String(x).trim());
  /** הסדר הקבוע: translate rotate scale skew (סיבוב סביב נקודת הציר, אחרי ההזזה). */
  function joinTransform(t) {
    const out = [];
    if (!zero(t.tx) || !zero(t.ty)) out.push('translate(' + z(t.tx) + ', ' + z(t.ty) + ')');
    if (!zero(t.rot)) out.push('rotate(' + t.rot + ')');
    if (String(t.sx) !== '1' || String(t.sy) !== '1') out.push(String(t.sx) === String(t.sy) ? 'scale(' + t.sx + ')' : 'scale(' + t.sx + ', ' + t.sy + ')');
    if (!zero(t.kx) || !zero(t.ky)) out.push(zero(t.ky) ? 'skewX(' + t.kx + ')' : 'skew(' + z(t.kx) + ', ' + z(t.ky) + ')');
    return out.length ? out.join(' ') : 'none';
  }
  /** זווית (deg / turn / rad / grad) ← מעלות. */
  function deg(a) { const m = /^(-?[\d.]+)(deg|turn|rad|grad)?$/i.exec(String(a).trim()); if (!m) return 0; const x = +m[1], u = (m[2] || 'deg').toLowerCase(); return u === 'turn' ? x * 360 : u === 'rad' ? x * 180 / Math.PI : u === 'grad' ? x * 0.9 : x; }

  // ── עקומות ─────────────────────────────────────────────────
  const NAMED_EASE = { ease: [0.25, 0.1, 0.25, 1], linear: [0, 0, 1, 1], 'ease-in': [0.42, 0, 1, 1], 'ease-out': [0, 0, 0.58, 1], 'ease-in-out': [0.42, 0, 0.58, 1] };
  function bezierOf(v) { v = String(v || '').trim().toLowerCase(); if (NAMED_EASE[v]) return NAMED_EASE[v].slice(); const m = /^cubic-bezier\(([^)]*)\)$/.exec(v); if (!m) return null; const p = m[1].split(',').map(x => +x.trim()); return p.length === 4 && p.every(x => isFinite(x)) && p[0] >= 0 && p[0] <= 1 && p[2] >= 0 && p[2] <= 1 ? p : null; }
  const r2 = x => Math.round(x * 100) / 100;
  function bezierCss(p) { const q = p.map(r2); for (const [k, v] of Object.entries(NAMED_EASE)) if (v.every((x, i) => x === q[i])) return k; return 'cubic-bezier(' + q.join(', ') + ')'; }

  // ── אנימציות כניסה (css/motion.css) ───────────────────────
  /** [מזהה, תווית, keyframes (from)]. נכתב: מחלקה kol-in kol-in-<מזהה> + --kol-in-dur / --kol-in-delay / --kol-in-ease. */
  const ENTRANCES = [
    ['fade', 'הופעה', 'opacity: 0;'], ['fade-up', 'החלקה מלמטה', 'opacity: 0; transform: translateY(40px);'], ['fade-down', 'החלקה מלמעלה', 'opacity: 0; transform: translateY(-40px);'],
    ['fade-right', 'החלקה מימין', 'opacity: 0; transform: translateX(40px);'], ['fade-left', 'החלקה משמאל', 'opacity: 0; transform: translateX(-40px);'],
    ['zoom-in', 'הגדלה', 'opacity: 0; transform: scale(.8);'], ['zoom-out', 'הקטנה', 'opacity: 0; transform: scale(1.2);'], ['rotate', 'סיבוב', 'opacity: 0; transform: rotate(-12deg) scale(.9);'],
    ['flip-x', 'היפוך אופקי', 'opacity: 0; transform: perspective(600px) rotateY(80deg);'], ['flip-y', 'היפוך אנכי', 'opacity: 0; transform: perspective(600px) rotateX(80deg);'],
    ['bounce', 'קפיצה', 'opacity: 0; transform: translateY(40px);'], ['blur', 'מטושטש לחד', 'opacity: 0; filter: blur(12px);'], ['slide-up', 'עלייה (בלי דהייה)', 'transform: translateY(100%);'], ['pop', 'הקפצה', 'opacity: 0; transform: scale(.5);']];
  const BOUNCE = '  60% { opacity: 1; transform: translateY(-10px); }\n  80% { transform: translateY(4px); }\n';
  const POP = '  70% { opacity: 1; transform: scale(1.08); }\n';
  const MOTION_HEAD = '/* תנועה — נוצר בידי העורך החזותי (V10). קובץ רגיל של הפרויקט: מותר לערוך ולמחוק.\n' +
    ' * .kol-in — אנימציית כניסה: בלי js/kol-motion.js היא רצה פעם אחת בטעינה; עם הקובץ — כשהאלמנט נראה במסך (is-in).\n' +
    ' * .kol-sc — אפקט גלילה (CSS בלבד, animation-timeline: view()); דפדפן בלי תמיכה — האלמנט סטטי.\n' +
    ' * הכול בתוך prefers-reduced-motion: no-preference — מי שביקש פחות תנועה רואה את המצב הסופי. */\n';
  const MOTION_BASE = '@media (prefers-reduced-motion: no-preference) {\n' +
    '  .kol-in { animation-name: var(--kol-in-name, none); animation-duration: var(--kol-in-dur, .8s); animation-timing-function: var(--kol-in-ease, ease); animation-delay: var(--kol-in-delay, 0s); animation-fill-mode: both; }\n' +
    '  .kol-motion-on .kol-in:not(.is-in) { animation-play-state: paused; }\n}\n';
  const SCROLL_BASE = '@supports (animation-timeline: view()) {\n  @media (prefers-reduced-motion: no-preference) {\n' +
    '    .kol-sc { animation: kol-sc linear both; animation-timeline: view(); animation-range: var(--kol-sc-from, cover 0%) var(--kol-sc-to, cover 100%); }\n  }\n}\n' +
    '@keyframes kol-sc {\n' +
    '  from { opacity: var(--kol-sc-o0, 1); filter: blur(var(--kol-sc-b0, 0px)); transform: translate(var(--kol-sc-x0, 0px), var(--kol-sc-y0, 0px)) rotate(var(--kol-sc-r0, 0deg)) scale(var(--kol-sc-s0, 1)); }\n' +
    '  to { opacity: var(--kol-sc-o1, 1); filter: blur(var(--kol-sc-b1, 0px)); transform: translate(var(--kol-sc-x1, 0px), var(--kol-sc-y1, 0px)) rotate(var(--kol-sc-r1, 0deg)) scale(var(--kol-sc-s1, 1)); }\n}\n';
  function entranceCss(id) {
    const e = ENTRANCES.find(x => x[0] === id); if (!e) fail('אנימציה לא מוכרת: ' + id);
    return '/* כניסה: ' + e[1] + ' */\n.kol-in-' + id + ' { --kol-in-name: kol-in-' + id + '; }\n@keyframes kol-in-' + id + ' {\n  from { ' + e[2] + ' }\n' + (id === 'bounce' ? BOUNCE : id === 'pop' ? POP : '') + '}\n';
  }
  /** מצגת רקע של n תמונות: keyframes לפי n, ומחלקה .bg-slides-<n>. mode: fade | slide; kb — "קן ברנס" (הגדלה איטית). */
  function slidesCss(n, mode, kb) {
    if (!(n >= 2 && n <= 10)) fail('מצגת: 2–10 תמונות');
    const name = 'kol-slides-' + mode + (kb ? '-kb' : '') + '-' + n, step = 100 / n, f = Math.min(step / 4, 6), p = x => r2(x) + '%';
    const hid = mode === 'slide' ? 'transform: translateX(100%)' : 'opacity: 0', out = mode === 'slide' ? 'transform: translateX(-100%)' : 'opacity: 0', on = mode === 'slide' ? 'transform: translateX(0)' : 'opacity: 1';
    const kbs = kb && mode !== 'slide' ? [' transform: scale(1);', ' transform: scale(1.15);'] : ['', ''];
    let css = '/* מצגת רקע — ' + n + ' תמונות, ' + (mode === 'slide' ? 'החלקה' : 'דהייה') + (kb ? ', קן ברנס' : '') + '. משך לכל תמונה: --kol-slide-d */\n';
    css += '.bg-slides { position: absolute; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }\n.bg-slide { position: absolute; inset: 0; background-size: cover; background-position: center; }\n';
    css += '.bg-slides-' + n + ' > .bg-slide { opacity: 0; }\n.bg-slides-' + n + ' > .bg-slide:first-child { opacity: 1; }\n';
    css += '@media (prefers-reduced-motion: no-preference) {\n  .bg-slides-' + n + '.' + name + ' > .bg-slide { opacity: 1; animation: ' + name + ' calc(var(--kol-slide-d, 5s) * ' + n + ') linear infinite both; }\n';
    for (let i = 2; i <= n; i++) css += '  .bg-slides-' + n + '.' + name + ' > .bg-slide:nth-child(' + i + ') { animation-delay: calc(var(--kol-slide-d, 5s) * ' + (i - 1) + '); }\n';
    css += '}\n@keyframes ' + name + ' {\n  0% { ' + hid + ';' + kbs[0] + ' }\n  ' + p(f) + ' { ' + on + ';' + ' }\n  ' + p(step) + ' { ' + on + ';' + kbs[1] + ' }\n  ' + p(step + f) + ' { ' + out + '; }\n  100% { ' + out + '; }\n}\n';
    return { name, css };
  }
  /** תיקונים ל-motion.css: מוסיף בסוף הקובץ רק מה שחסר (לפי @keyframes / בורר קיים) — לא נוגע במה שכבר כתוב. */
  function planMotion(text, need) {
    const m = K().parseCss(text), all = K().cssAll(m), has = re => all.some(n => (n.type === 'rule' && re.test(n.sel)) || (n.type === 'at' && re.test(n.name + ' ' + (n.prelude || '')))) || re.test(text);
    let add = '';
    if (!text.trim()) add += MOTION_HEAD;
    for (const k of need) {
      if (k === 'base' && !/\.kol-in\s*\{/.test(text)) add += MOTION_BASE;
      else if (k === 'scroll' && !/@keyframes\s+kol-sc\b/.test(text)) add += SCROLL_BASE;
      else if (/^in:/.test(k)) { const id = k.slice(3); if (!new RegExp('@keyframes\\s+kol-in-' + id + '\\b').test(text)) add += entranceCss(id); }
      else if (/^slides:/.test(k)) { const [, n, mode, kb] = k.split(':'), s = slidesCss(+n, mode, kb === '1'); if (!new RegExp('@keyframes\\s+' + s.name + '\\b').test(text)) add += s.css; }
    }
    void has;
    if (!add) return [];
    const sep = text && !text.endsWith('\n') ? '\n' : '', gap = text.trim() ? '\n' : '';
    return [{ from: text.length, to: text.length, text: sep + gap + add }];
  }

  // ── מפריד צורה ─────────────────────────────────────────────
  const SHAPES = [['wave', 'גל'], ['waves', 'גלים'], ['triangle', 'משולש'], ['triangle-asym', 'משולש לא סימטרי'], ['curve', 'קשת'], ['tilt', 'הטיה'], ['arrow', 'חץ'], ['zigzag', 'זיגזג'], ['mountains', 'הרים'], ['clouds', 'עננים'], ['book', 'ספר'], ['split', 'פיצול']];
  /** SVG של צורה מוכנה (מקובץ של הכלי) — רק svg עם path/polygon, בלי סקריפט, בלי תכונת on…, בלי href / url(). */
  function checkSvg(svg) {
    svg = String(svg || '').trim();
    if (!/^<svg\b[^>]*>[\s\S]*<\/svg>$/.test(svg) || /<(script|foreignObject|image|use|a|style|iframe)\b|\son[a-z]+\s*=|href\s*=|url\(|javascript:/i.test(svg)) fail('קובץ הצורה אינו SVG פשוט — לא נוסף');
    return svg;
  }
  function shapeHtml(svg, pos, id) { return '<div class="shape shape-' + (pos === 'top' ? 'top' : 'bottom') + '" data-shape="' + String(id).replace(/[^a-z-]/g, '') + '" aria-hidden="true">' + checkSvg(svg) + '</div>'; }
  const SHAPE_CSS = '/* מפריד צורה (V10): אלמנט רגיל בתוך הרובריקה; הצבע — color (fill: currentColor) */\n' +
    '.shape { position: absolute; inset-inline: 0; line-height: 0; overflow: hidden; pointer-events: none; z-index: 0; color: #ffffff; }\n' +
    '.shape svg { display: block; width: 100%; height: 100%; }\n.shape-top { top: 0; height: 60px; transform: scaleY(-1); }\n.shape-bottom { bottom: 0; height: 60px; }\n';
  const VIDEO_CSS = '/* וידאו ברקע (V10): האלמנט הראשון ברובריקה, מאחורי התוכן */\n.bg-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: -1; pointer-events: none; }\n';
  /** מסיכות מוכנות: מעבר צבע / צורה (radial / conic / polygon כ-SVG אינו נכתב — רק גרדיאנטים, בלי url). */
  const MASKS = [['fade-b', 'דהייה למטה', 'linear-gradient(to bottom, #000 60%, transparent)'], ['fade-t', 'דהייה למעלה', 'linear-gradient(to top, #000 60%, transparent)'],
    ['fade-x', 'דהייה בצדדים', 'linear-gradient(to right, transparent, #000 20%, #000 80%, transparent)'], ['circle', 'עיגול', 'radial-gradient(circle closest-side, #000 98%, transparent)'],
    ['ellipse', 'אליפסה', 'radial-gradient(ellipse closest-side, #000 98%, transparent)'], ['vignette', 'וינייטה', 'radial-gradient(ellipse, #000 55%, transparent 75%)'],
    ['diagonal', 'אלכסון', 'linear-gradient(135deg, transparent 15%, #000 15% 85%, transparent 85%)'], ['stripes', 'פסים', 'repeating-linear-gradient(90deg, #000 0 20px, transparent 20px 30px)']];


  // ════════════════════════ הממשק בדפדפן ════════════════════════
  function makeUi() {
    const hh = (...a) => g.h(...a), FX = g.KolFx;
    const SIDES = [['top', 'למעלה'], ['right', 'ימין'], ['bottom', 'למטה'], ['left', 'שמאל']];
    const CORNERS = [['top-right', 'ימין למעלה'], ['top-left', 'שמאל למעלה'], ['bottom-right', 'ימין למטה'], ['bottom-left', 'שמאל למטה']];
    const LSTYLES = [['solid', 'רציף (solid)'], ['dashed', 'מקווקו (dashed)'], ['dotted', 'נקודות (dotted)'], ['double', 'כפול (double)'], ['groove', 'groove'], ['ridge', 'ridge'], ['inset', 'inset'], ['outset', 'outset'], ['none', 'בלי (none)']];
    const BLENDS = ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'hard-light', 'soft-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'];
    class Fx {
      /** o: { design (לשונית "עיצוב"), adv, write, writeOps, joinTx, note, undo, phone, layer(), busy(), on(), html(), docOf(path), pagePath(), pick(kind, title), shapeUrl(id), reorder(ev, box, i, list, save) } */
      constructor(o) { Object.assign(this, o); this.lockB = 'all'; this.lockR = 'all'; this.side = 'top'; this.open = new Set(['border']); this.sh = { box: 0, text: 0 }; this.shKind = 'box'; }
      get D() { return this.design; } get c() { return this.D.canvas; }
      win() { return this.c.frame.contentWindow; }
      cs(n) { const e = this.c.elOf(n); return e ? this.win().getComputedStyle(e) : null; }
      decl(n, phys) { const s = this.D.styler.source(n, phys, this.D.info); return s.winner ? String(s.declared) : ''; }
      put(sets, label, opt) { try { FX.checkSets(sets, opt); } catch (e) { this.note(e.message, true); return null; } return this.D.put(sets, label + ' · ' + this.c.label(this.D.n), opt); }
      /** קבוצה מתקפלת; הפתוחות נזכרות. */
      group(id, title, body) {
        const d = hh('details', { class: 'sg-sec sg-fx', 'data-sec': id, open: this.open.has(id) || null }, hh('summary', { class: 'dt-h' }, title));
        d.addEventListener('toggle', () => { if (d.open) this.open.add(id); else this.open.delete(id); this.drawHandles(); });
        if (d.open || this.open.has(id)) d.append(...body().filter(Boolean)); else d.addEventListener('toggle', () => { if (d.open && d.children.length === 1) d.append(...body().filter(Boolean)); }, { once: true });
        return d;
      }
      sections(n) {
        const e = this.c.elOf(n); if (!e || /^(html|head)$/.test(n.tag)) return [];
        const out = [this.group('border', 'מסגרת ופינות', () => this.border(n)), this.group('shadow', 'צל וקו מתאר', () => this.shadow(n)), this.group('effects', 'שקיפות, מסננים ומיזוג', () => this.effects(n)), this.group('mask', 'מסיכה', () => this.mask(n))];
        if (this.isShape(n)) out.unshift(this.group('shape', 'מפריד צורה (נבחר)', () => this.shapeOwn(n)));
        else if (n.tag !== 'body' && e.children.length && !/^(p|h[1-6]|a|span|button|li|img|video|svg)$/.test(n.tag)) out.push(this.group('shapes', 'מפריד צורה', () => this.shapeAdd(n)));
        return out;
      }
      seg(label, cur, opts, fn, attr) { return hh('div', { class: 'sg-field' }, hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, label), hh('span', { class: 'sg-seg', role: 'group', 'aria-label': label }, opts.map(([v, t]) => hh('button', { type: 'button', 'aria-pressed': String(v === cur), [attr]: v, onclick: () => fn(v) }, t))))); }

      // ── מסגרת ופינות ──
      border(n) {
        const D = this.D, s = this.cs(n), out = [];
        out.push(this.seg('צדדים', this.lockB, [['all', '▢ כולם'], ['sides', '◧ לכל צד']], v => { this.lockB = v; D.draw(); }, 'data-border-lock'));
        if (this.lockB === 'all') {
          const cur = FX && g.KolStyle.lineParse(this.decl(n, 'border')) || [s.borderTopWidth, s.borderTopStyle, s.borderTopColor];
          const st = hh('select', { 'aria-label': 'סוג המסגרת', 'data-border-all': 'style', disabled: !D.canWrite() || null }, LSTYLES.map(([v, t]) => hh('option', { value: v, selected: v === cur[1] || null }, t)));
          const wd = hh('input', { type: 'text', dir: 'ltr', value: cur[0], 'aria-label': 'עובי המסגרת', 'data-border-all': 'width', disabled: !D.canWrite() || null, spellcheck: 'false' });
          const co = hh('input', { type: 'text', dir: 'ltr', value: cur[2], 'aria-label': 'צבע המסגרת', 'data-border-all': 'color', disabled: !D.canWrite() || null, spellcheck: 'false' });
          const sw = hh('button', { type: 'button', class: 'sg-swatch', 'aria-label': 'בוחר צבע — מסגרת', disabled: !D.canWrite() || null }); sw.style.setProperty('--c', s.borderTopColor);
          const go = () => this.borderAll(n, wd.value.trim(), st.value, co.value.trim());
          st.addEventListener('change', go); wd.addEventListener('change', go); co.addEventListener('change', go);
          const row = hh('div', { class: 'sg-field', 'data-field': 'border' }, hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'מסגרת'), hh('span', { class: 'sg-ctl wide' }, st)),
            hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'עובי · צבע'), hh('span', { class: 'sg-ctl wide' }, wd, sw, co), D.resetBtn(n, 'border', 'מסגרת')), D.srcLine(n, 'border').el);
          sw.addEventListener('click', () => D.picker(row, 'border-top-color', 'צבע המסגרת', s.borderTopColor, v => { co.value = v; go(); }));
          out.push(row);
        } else {
          out.push(this.seg('צד', this.side, SIDES, v => { this.side = v; D.draw(); }, 'data-border-side'));
          const sd = this.side, nm = SIDES.find(x => x[0] === sd)[1];
          out.push(D.choice('border-' + sd + '-style', 'סוג · ' + nm, LSTYLES), D.num('border-' + sd + '-width', 'עובי · ' + nm, { group: 'מסגרת' }), D.colorField(n, 'border-' + sd + '-color', 'צבע · ' + nm));
        }
        out.push(this.seg('פינות', this.lockR, [['all', '◻ כולן'], ['corners', '◳ לכל פינה']], v => { this.lockR = v; D.draw(); }, 'data-radius-lock'));
        if (this.lockR === 'all') out.push(D.num('border-radius', 'רדיוס', { group: 'פינות', units: ['%'] }));
        else for (const [k, l] of CORNERS) out.push(D.num('border-' + k + '-radius', l, { group: 'פינות', units: ['%'] }));
        out.push(hh('p', { class: 'hint' }, 'רדיוס אליפטי — בהקלדה: 40px / 20px. על הקנבס: נקודה בכל פינה — גרירה ← כל הפינות; Alt ← פינה אחת. "לכל צד" נכתב במאפיינים לוגיים (border-inline-start…).'));
        return out;
      }
      /** "כל הצדדים": border: <עובי> <סוג> <צבע> אחד, וההצהרות של הצדדים ביעד — נמחקות (אחרת הן דורסות). */
      borderAll(n, w, st, co) {
        const v = [w || 'medium', st || 'solid', co || 'currentcolor'].join(' '), sets = [['border', v]], D = this.D;
        for (const [sd] of SIDES) for (const k of ['width', 'style', 'color']) { const p = 'border-' + sd + '-' + k; const d = D.styler.ownDecl(n, D.effTarget(n, p), p, D.info); if (d && d.prop.trim().toLowerCase() !== 'border') sets.push([p, null]); }
        this.put(sets, 'מסגרת · ' + v);
      }

      // ── צל וקו מתאר ──
      shadowList(n, phys) { const d = this.decl(n, phys), text = phys === 'text-shadow'; return { d, list: FX.parseShadows(d || (this.cs(n) || {})[phys === 'box-shadow' ? 'boxShadow' : 'textShadow'] || 'none', text) }; }
      shadow(n) {
        const out = [];
        for (const [phys, title, key] of [['box-shadow', 'צל קופסה', 'box'], ['text-shadow', 'צל טקסט', 'text']]) out.push(this.shadowBox(n, phys, title, key));
        const D = this.D;
        out.push(hh('h4', { class: 'sg-sub' }, 'קו מתאר (outline)'), D.choice('outline-style', 'סוג', LSTYLES), D.num('outline-width', 'עובי'), D.colorField(n, 'outline-color', 'צבע'), D.num('outline-offset', 'היסט'));
        return out;
      }
      shadowBox(n, phys, title, key) {
        const D = this.D, text = key === 'text', { d, list } = this.shadowList(n, phys), w = D.canWrite();
        const box = hh('div', { class: 'sg-field fx-sh', 'data-field': phys }, hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, title), D.resetBtn(n, phys, title)), D.srcLine(n, phys).el);
        if (!list) { box.append(hh('p', { class: 'hint', 'data-sh-raw': phys }, 'הערך מורכב (' + d + ') — נערך ב"מאפיינים נוספים" למטה ובקוד; הפאנל אינו דורס אותו.')); return box; }
        const save = (nl, what) => this.put([[phys, nl.length ? FX.joinShadows(nl, text) : null]], title + ' · ' + what);
        const ol = hh('ol', { class: 'gt-list fx-layers', 'aria-label': title + ' — שכבות' });
        list.forEach((L, i) => {
          const f = (k, lab) => { const inp = hh('input', { type: 'text', dir: 'ltr', value: L[k], 'aria-label': lab + ' · שכבה ' + (i + 1), 'data-sh': phys + ':' + i + ':' + k, disabled: !w || null, spellcheck: 'false' }); inp.addEventListener('change', () => { const nl = list.map(x => ({ ...x })); nl[i][k] = inp.value.trim() || '0'; save(nl, 'שכבה ' + (i + 1) + ' · ' + lab + ' ← ' + inp.value.trim()); }); return inp; };
          const sw = hh('button', { type: 'button', class: 'sg-swatch', 'aria-label': 'צבע · שכבה ' + (i + 1), disabled: !w || null }); sw.style.setProperty('--c', /var\(/.test(L.color) ? 'transparent' : L.color);
          const grip = hh('span', { class: 'gt-grip', title: 'גרירה לשינוי הסדר', 'aria-hidden': 'true' }, '⠿');
          grip.addEventListener('pointerdown', ev => this.reorder(ev, ol, i, list, (nl, what) => save(nl, what)));
          const li = hh('li', { class: 'gt-row fx-layer' + (this.sh[key] === i ? ' on' : ''), 'data-sh-layer': phys + ':' + i, onclick: ev => { if (ev.target.closest('input,button')) return; this.sh[key] = i; this.shKind = key; for (const x of ol.children) x.classList.toggle('on', x === li); this.drawHandles(); } }, grip,
            f('x', 'X'), f('y', 'Y'), f('blur', 'טשטוש'), text ? null : f('spread', 'פריסה'), sw, f('color', 'צבע'),
            text ? null : hh('label', { class: 'fx-in', title: 'צל פנימי (inset)' }, hh('input', { type: 'checkbox', checked: L.inset || null, disabled: !w || null, 'data-sh-inset': phys + ':' + i, onchange: ev => { const nl = list.map(x => ({ ...x })); nl[i].inset = ev.target.checked; save(nl, 'שכבה ' + (i + 1) + ' · ' + (ev.target.checked ? 'פנימי' : 'חיצוני')); } }), 'פנימי'),
            hh('button', { type: 'button', class: 'btn sm', 'data-sh-del': phys + ':' + i, 'aria-label': 'מחיקת שכבה ' + (i + 1), disabled: !w || null, onclick: () => save(list.filter((_, j) => j !== i), 'מחיקת שכבה ' + (i + 1)) }, '✕'));
          sw.addEventListener('click', () => D.picker(box, phys, 'צבע הצל', L.color, v => { const nl = list.map(x => ({ ...x })); nl[i].color = v; save(nl, 'שכבה ' + (i + 1) + ' · צבע ← ' + v); }));
          ol.append(li);
        });
        ol.append(hh('li', { class: 'gt-row add' }, hh('button', { type: 'button', class: 'btn sm', 'data-sh-add': phys, disabled: !w || null, onclick: () => { this.sh[key] = list.length; this.shKind = key; save([...list, text ? { x: '1px', y: '2px', blur: '4px', color: 'rgba(0, 0, 0, 0.35)' } : { x: '0px', y: '8px', blur: '24px', spread: '0px', color: 'rgba(0, 0, 0, 0.18)', inset: false }], 'שכבה חדשה'); } }, '+ שכבה')));
        box.append(ol);
        if (list.length) box.append(hh('p', { class: 'hint' }, 'השכבה המסומנת — "☀" על הקנבס: גרירה מזיזה X/Y, גלגלת — טשטוש. ' + (text ? '' : 'X Y טשטוש פריסה צבע.')));
        return box;
      }

      // ── שקיפות, מסננים, מיזוג ──
      effects(n) {
        const D = this.D, out = [D.num('opacity', 'שקיפות', { unitless: true, units: ['%'], step: 0.05 })];
        for (const [phys, title] of [['filter', 'מסננים (filter)'], ['backdrop-filter', 'מסנן רקע (backdrop-filter)']]) out.push(this.filterBox(n, phys, title));
        out.push(D.choice('mix-blend-mode', 'מיזוג עם מה שמתחת', BLENDS.map(b => [b, b])));
        out.push(D.choice('isolation', 'בידוד (isolation)', [['auto', 'auto'], ['isolate', 'isolate']]));
        return out;
      }
      filterBox(n, phys, title) {
        const D = this.D, d = this.decl(n, phys), cur = d || (this.cs(n) || {})[phys === 'filter' ? 'filter' : 'backdropFilter'] || 'none', list = FX.parseFilter(cur), w = D.canWrite();
        const box = hh('div', { class: 'sg-field fx-filters', 'data-field': phys }, hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, title), D.resetBtn(n, phys, title)), D.srcLine(n, phys).el);
        if (!list) { box.append(hh('p', { class: 'hint', 'data-filter-raw': phys }, 'הערך מורכב (' + d + ') — נערך ב"מאפיינים נוספים" ובקוד.')); return box; }
        for (const [fn, lab, unit, min, max, neu] of FX.FILTERS) {
          if (phys === 'backdrop-filter' && fn === 'opacity') continue;
          const has = list.find(f => f.fn === fn), num = has ? parseFloat(has.arg) * (unit === '%' && !/%/.test(has.arg) ? 100 : 1) : neu;
          const rg = hh('input', { type: 'range', min: String(min), max: String(max), step: unit === 'px' ? '0.5' : '1', value: String(isFinite(num) ? num : neu), 'aria-label': lab, 'data-filter': phys + ':' + fn, disabled: !w || null });
          const val = hh('span', { class: 'fx-val', dir: 'ltr' }, has ? has.arg : '—');
          const v2 = () => rg.value + unit;
          rg.addEventListener('input', () => { val.textContent = v2(); this.D.preview(this.D.nodes(), [[phys, FX.joinFilter(FX.setFilter(list, fn, v2()))]]); });
          rg.addEventListener('change', () => this.put([[phys, FX.joinFilter(FX.setFilter(list, fn, v2()))]], title + ' · ' + lab + ' ← ' + v2()));
          box.append(hh('div', { class: 'sg-line fx-frow' }, hh('span', { class: 'sg-label' }, lab), hh('span', { class: 'sg-ctl wide' }, rg, val),
            hh('button', { type: 'button', class: 'sg-reset', 'data-filter-reset': phys + ':' + fn, 'aria-label': 'איפוס — ' + lab, disabled: !has || !w || null, onclick: () => { const nl = FX.setFilter(list, fn, null); this.put([[phys, nl.length ? FX.joinFilter(nl) : null]], title + ' · ' + lab + ' · איפוס'); } }, '⟲')));
        }
        if (phys === 'filter') {
          const ds = list.find(f => f.fn === 'drop-shadow');
          const inp = hh('input', { type: 'text', dir: 'ltr', value: ds ? ds.arg : '', placeholder: '0 4px 8px rgba(0,0,0,.3)', 'aria-label': 'צל-טיפה (drop-shadow)', 'data-filter': 'filter:drop-shadow', disabled: !w || null, spellcheck: 'false' });
          inp.addEventListener('change', () => { const v = inp.value.trim(); const nl = FX.setFilter(list, 'drop-shadow', v || null); this.put([[phys, nl.length ? FX.joinFilter(nl) : null]], title + ' · צל-טיפה ← ' + (v || 'איפוס')); });
          box.append(hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'צל-טיפה'), hh('span', { class: 'sg-ctl wide' }, inp)));
        }
        return box;
      }

      // ── מסיכה ──
      mask(n) {
        const D = this.D, w = D.canWrite(), cur = this.decl(n, 'mask-image') || this.decl(n, '-webkit-mask-image');
        const pre = FX.MASKS.find(m => m[2] === cur);
        const sel = hh('select', { 'aria-label': 'מסיכה', 'data-mask': true, disabled: !w || null }, hh('option', { value: '' }, cur ? (pre ? pre[1] : 'מותאם: ' + cur.slice(0, 40)) : 'בלי מסיכה'), FX.MASKS.map(([id, l]) => hh('option', { value: id, selected: pre && pre[0] === id || null }, l)), hh('option', { value: '-' }, 'בלי מסיכה (מחיקה)'));
        sel.addEventListener('change', () => { if (!sel.value) return; if (sel.value === '-') { this.maskSet(n, [['image', null], ['size', null], ['position', null], ['repeat', null]], 'מסיכה · הסרה'); return; } const m = FX.MASKS.find(x => x[0] === sel.value); this.maskSet(n, [['image', m[2]]], 'מסיכה · ' + m[1]); });
        const lib = hh('button', { type: 'button', class: 'btn sm', 'data-mask-lib': true, disabled: !w || null, onclick: async () => { const p = await this.pick('image', 'תמונת מסיכה (PNG / SVG שקוף)'); if (!p) return; const t = D.effTarget(n, 'mask-image'), base = t.kind === 'inline' ? this.filePath() : t.path; this.maskSet(n, [['image', g.KolBg.urlCss(g.KolBg.relFrom(base, p), "'")], ['size', 'contain'], ['repeat', 'no-repeat'], ['position', 'center']], 'מסיכה · ' + p, { url: true }); } }, '🖼 מהספרייה');
        const row = hh('div', { class: 'sg-field', 'data-field': 'mask-image' }, hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'מסיכה'), hh('span', { class: 'sg-ctl wide' }, sel, lib)), D.srcLine(n, 'mask-image').el);
        const out = [row];
        if (cur) {
          const ch = (k, lab, opts) => { const v = this.decl(n, 'mask-' + k); const s = hh('select', { 'aria-label': lab, ['data-mask-' + k]: true, disabled: !w || null }, (v && !opts.some(o => o[0] === v) ? [[v, v]] : []).concat(opts).map(([x, t]) => hh('option', { value: x, selected: x === v || null }, t))); s.addEventListener('change', () => this.maskSet(n, [[k, s.value]], 'מסיכה · ' + lab + ' ← ' + s.value)); return hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, lab), hh('span', { class: 'sg-ctl wide' }, s)); };
          out.push(ch('size', 'גודל', [['auto', 'מקורי'], ['contain', 'בתוך (contain)'], ['cover', 'מכסה (cover)'], ['100% 100%', 'מתיחה']]), ch('position', 'מיקום', [['center', 'מרכז'], ['top', 'למעלה'], ['bottom', 'למטה'], ['left', 'שמאל'], ['right', 'ימין']]), ch('repeat', 'חזרה', [['no-repeat', 'בלי'], ['repeat', 'חזרה'], ['repeat-x', 'אופקית'], ['repeat-y', 'אנכית']]));
        }
        out.push(hh('p', { class: 'hint' }, 'נכתב בזוג: mask-* ו--webkit-mask-* (Safari), ומוצג כאחד.'));
        return out;
      }
      maskSet(n, kv, label, opt) { const sets = []; for (const [k, v] of kv) sets.push(['mask-' + k, v], ['-webkit-mask-' + k, v]); this.put(sets, label, opt); }

      // ── מפריד צורה ──
      isShape(n) { return /(^|\s)shape(\s|$)/.test((g.KolSrc.attrOf(n, 'class') || {}).value || ''); }
      shapeAdd(n) {
        const w = this.D.canWrite(), sel = hh('select', { 'aria-label': 'צורה', 'data-shape-pick': true }, FX.SHAPES.map(([id, l]) => hh('option', { value: id }, l)));
        return [hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'צורה'), hh('span', { class: 'sg-ctl wide' }, sel)),
          hh('div', { class: 'sg-bgacts' }, hh('button', { type: 'button', class: 'btn sm', 'data-shape-add': 'top', disabled: !w || null, onclick: () => this.addShape(n, sel.value, 'top') }, '＋ בראש'), hh('button', { type: 'button', class: 'btn sm', 'data-shape-add': 'bottom', disabled: !w || null, onclick: () => this.addShape(n, sel.value, 'bottom') }, '＋ בתחתית')),
          hh('p', { class: 'hint' }, 'נכתב כאלמנט רגיל (<div class="shape">, SVG גלוי) בתוך הרובריקה — נבחר, נערך ונמחק כמו כל אלמנט.')];
      }
      async svgOf(id) { const r = await fetch(this.shapeUrl(id), { credentials: 'same-origin' }); if (!r.ok) throw new Error('הצורה ' + id + ' לא נטענה'); return FX.checkSvg((await r.text()).trim()); }
      /** HTML (המפריד) + CSS (.shape בקובץ העמוד, אם חסר) + position:relative לרובריקה — עסקה אחת. */
      async addShape(n, id, pos) {
        let svg; try { svg = await this.svgOf(id); } catch (e) { this.note(e.message, true); return; }
        const K2 = g.KolSrc, path = K2.pathOf(n), html = this.html(), cssPath = this.pagePath(), cssDoc = cssPath && this.docOf(cssPath);
        const ops = [() => { const m = this.c.m, at = K2.byPath(m, path); return [{ doc: html, patches: K2.html.insert(m, at, pos === 'top' ? 0 : at.kids.length, FX.shapeHtml(svg, pos, id)).patches }]; }];
        if (cssDoc && !/\.shape\s*\{/.test(cssDoc.text())) ops.push(() => { const t = cssDoc.text(); return [{ doc: cssDoc, patches: [{ from: t.length, to: t.length, text: (t.endsWith('\n') || !t ? '' : '\n') + '\n' + FX.SHAPE_CSS }] }]; });
        const k = this.relative(n, ops);
        const r = this.writeOps(ops, 'מפריד צורה · ' + FX.SHAPES.find(x => x[0] === id)[1] + ' · ' + (pos === 'top' ? 'בראש' : 'בתחתית') + ' · ' + this.c.label(n));
        if (!r) return; void k;
        const sec = K2.byPath(this.c.m, path), sh = sec && sec.kids.filter(x => x.tag === 'div' && this.isShape(x))[pos === 'top' ? 0 : sec.kids.filter(x => x.tag === 'div' && this.isShape(x)).length - 1];
        if (sh) this.c.select(sh);
      }
      /** רובריקה שהמפריד / הווידאו / המצגת יושבים בה — position: relative + isolation (כשאין מיקום משלה). כאן: תיקון בתוך ops. */
      relative(n, ops) {
        const s = this.cs(n); if (s.position !== 'static' && s.isolation === 'isolate') return 0;
        const K2 = g.KolSrc, path = K2.pathOf(n), St = this.D.styler;
        ops.push(() => { const at = K2.byPath(this.c.m, path), t = St.targets(at)[0], sets = []; if (s.position === 'static') sets.push(['position', 'relative']); if (s.isolation !== 'isolate') sets.push(['isolation', 'isolate']); return St.plan(at, St.dev(t), sets).filter(o => o.patches.length); });
        return 1;
      }
      shapeOwn(n) {
        const D = this.D, e = this.c.elOf(n), s = this.cs(n), w = D.canWrite(), top = /shape-top/.test(e.className), flip = /scaleX\(-1\)/.test(this.decl(n, 'transform'));
        const sel = hh('select', { 'aria-label': 'החלפת צורה', 'data-shape-swap': true, disabled: !w || null }, hh('option', { value: '' }, 'החלפת צורה…'), FX.SHAPES.map(([id, l]) => hh('option', { value: id, selected: e.dataset.shape === id || null }, l)));
        sel.addEventListener('change', async () => { if (!sel.value) return; let svg; try { svg = await this.svgOf(sel.value); } catch (er) { this.note(er.message, true); return; } const K2 = g.KolSrc, path = K2.pathOf(n), html = this.html(); this.writeOps([() => { const m = this.c.m, at = K2.byPath(m, path), old = at.kids.find(x => x.tag === 'svg'); const ps = old ? K2.html.remove(m, old).patches : []; return [{ doc: html, patches: ps }]; }, () => { const m = this.c.m, at = K2.byPath(m, path); return [{ doc: html, patches: K2.html.insert(m, at, 0, svg).patches }]; }, () => { const m = this.c.m, at = K2.byPath(m, path); return [{ doc: html, patches: K2.html.setAttr(m, at, 'data-shape', sel.value).patches }]; }], 'מפריד צורה · החלפה ← ' + sel.value); });
        return [hh('div', { class: 'sg-line' }, hh('span', { class: 'sg-label' }, 'צורה'), hh('span', { class: 'sg-ctl wide' }, sel)), D.colorField(n, 'color', 'צבע (fill)'), D.num('height', 'גובה', { group: 'מפריד' }), D.num('width', 'רוחב', { group: 'מפריד', units: ['%'] }),
          hh('div', { class: 'sg-bgacts' },
            hh('button', { type: 'button', class: 'btn sm', 'aria-pressed': String(flip), 'data-shape-flip': true, disabled: !w || null, onclick: () => this.put([['transform', flip ? (top ? 'scaleY(-1)' : null) : (top ? 'scale(-1, -1)' : 'scaleX(-1)')]], 'מפריד · היפוך אופקי') }, '⇋ היפוך'),
            hh('button', { type: 'button', class: 'btn sm', 'aria-pressed': String(+s.zIndex > 0), 'data-shape-front': true, disabled: !w || null, onclick: () => this.put([['z-index', +s.zIndex > 0 ? null : '2']], 'מפריד · ' + (+s.zIndex > 0 ? 'מאחורי התוכן' : 'מעל התוכן')) }, '⬆ מעל התוכן')),
          hh('p', { class: 'hint' }, 'ה-SVG — קוד גלוי ("פתח בקוד"). מחיקה — 🗑 כמו כל אלמנט.')];
      }

      // ── ידיות על הקנבס: רדיוס בכל פינה, ו"שמש" לצל ──
      drawHandles() {
        const c = this.c, n = c.selected, L = this.layer();
        if (!this.rh) {
          this.rh = {}; this.tip = hh('span', { class: 'fx-tip', hidden: true, dir: 'auto' });
          for (const [k] of CORNERS) { const b = hh('div', { class: 'fx-rad', hidden: true, 'data-radius-h': k, title: 'רדיוס · גרירה ← כל הפינות; Alt ← הפינה הזו' }); b.addEventListener('pointerdown', ev => this.radDown(ev, k)); this.rh[k] = b; L.append(b); }
          this.sun = hh('div', { class: 'fx-sun', hidden: true, 'data-shadow-h': true, title: 'צל · גרירה ← X/Y; גלגלת ← טשטוש' }, '☀');
          this.sun.addEventListener('pointerdown', ev => this.sunDown(ev)); this.sun.addEventListener('wheel', ev => this.sunWheel(ev), { passive: false });
          L.append(this.sun, this.tip);
        }
        const on = this.on() && n && !this.dragging && this.D.canWrite() && c.elOf(n) && !/^(html|body|head)$/.test(n.tag) && !(this.busy && this.busy());
        const r = on ? c.rectOf(n) : null, big = this.phone() ? 36 : 12, s = c.scale, cs = r && this.cs(n);
        const radOn = r && !r.zero && this.open.has('border');
        for (const [k, b] of Object.entries(this.rh)) {
          b.hidden = !radOn; if (b.hidden) continue;
          const [v, hz] = k.split('-'), rad = parseFloat(cs['border' + v[0].toUpperCase() + v.slice(1) + hz[0].toUpperCase() + hz.slice(1) + 'Radius']) || 0, d = Math.max(10, Math.min(rad * s, r.width / 2, r.height / 2) * 0.7071 + 8);
          const x = hz === 'left' ? r.left + d : r.left + r.width - d, y = v === 'top' ? r.top + d : r.top + r.height - d;
          b.style.cssText = 'left:' + (x - big / 2) + 'px;top:' + (y - big / 2) + 'px;width:' + big + 'px;height:' + big + 'px';
        }
        const shOn = r && !r.zero && this.open.has('shadow'); this.sun.hidden = !shOn;
        if (shOn) {
          const key = this.shKind, phys = key === 'text' ? 'text-shadow' : 'box-shadow', { list } = this.shadowList(n, phys), L0 = list && list[this.sh[key]];
          const px = v => parseFloat(v) || 0, cx = r.left + r.width / 2 + (L0 ? px(L0.x) * s : 0), cy = r.top + r.height / 2 + (L0 ? px(L0.y) * s : 0), sz = this.phone() ? 44 : 22;
          this.sun.style.cssText = 'left:' + (cx - sz / 2) + 'px;top:' + (cy - sz / 2) + 'px;width:' + sz + 'px;height:' + sz + 'px';
          this.sun.classList.toggle('ro', !list); this.sun.dataset.shadowH = phys;
        }
      }
      ready(n) { const D = this.D; D.info = D.styler.info(n); D.list = D.styler.targets(n, D.info); D.t = D.styler.st(D.styler.dev(D.target(n, D.list)), D.stt); }
      radDown(ev, k) {
        const c = this.c, n = c.selected, e = c.elOf(n); if (!e || ev.button > 0) return;
        ev.preventDefault(); const t0 = ev.currentTarget; try { t0.setPointerCapture(ev.pointerId); } catch { /* */ }
        this.ready(n);
        const cs = this.cs(n), [v, hz] = k.split('-'), key = 'border' + v[0].toUpperCase() + v.slice(1) + hz[0].toUpperCase() + hz.slice(1) + 'Radius', r0 = parseFloat(cs[key]) || 0, x0 = ev.clientX, y0 = ev.clientY, s = c.scale, rect = e.getBoundingClientRect();
        const maxR = Math.min(rect.width, rect.height) / 2; let rad = r0, moved = false, one = ev.altKey; this.dragging = true;
        const phys = () => (one ? 'border-' + k + '-radius' : 'border-radius');
        const mv = e2 => { one = one || e2.altKey; const dx = (e2.clientX - x0) / s * (hz === 'left' ? 1 : -1), dy = (e2.clientY - y0) / s * (v === 'top' ? 1 : -1); rad = Math.round(Math.max(0, Math.min(maxR, r0 + (dx + dy) / 2 * 1.4142))); moved = moved || Math.hypot(dx, dy) > 1; this.D.preview([n], [[phys(), rad + 'px']]); this.tip.hidden = false; this.tip.textContent = phys() + ': ' + rad + 'px'; const LR = this.layer().getBoundingClientRect(); this.tip.style.cssText = 'left:' + (e2.clientX - LR.left) + 'px;top:' + Math.max(0, e2.clientY - LR.top - 28) + 'px'; };
        const up = e2 => { t0.removeEventListener('pointermove', mv); t0.removeEventListener('pointerup', up); t0.removeEventListener('pointercancel', up); this.dragging = false; this.tip.hidden = true; if (e2.type !== 'pointerup' || !moved) { this.D.unpreview(); this.drawHandles(); return; } this.put([[phys(), rad + 'px']], 'פינות · ' + (one ? 'פינה ' + CORNERS.find(x => x[0] === k)[1] : 'כל הפינות') + ' · ' + rad + 'px'); this.drawHandles(); };
        t0.addEventListener('pointermove', mv); t0.addEventListener('pointerup', up); t0.addEventListener('pointercancel', up);
      }
      sunState(n) {
        const key = this.shKind, phys = key === 'text' ? 'text-shadow' : 'box-shadow', text = key === 'text', { list } = this.shadowList(n, phys);
        if (!list) { this.note('הצל כתוב בצורה שהפאנל אינו מפרק — נערך ב"מאפיינים נוספים"', true); return null; }
        const nl = list.map(x => ({ ...x })); let i = this.sh[key]; if (!nl[i]) { nl.push(text ? { x: '0px', y: '0px', blur: '4px', color: 'rgba(0, 0, 0, 0.35)' } : { x: '0px', y: '0px', blur: '24px', spread: '0px', color: 'rgba(0, 0, 0, 0.2)', inset: false }); i = nl.length - 1; this.sh[key] = i; }
        return { phys, text, nl, i };
      }
      sunDown(ev) {
        const c = this.c, n = c.selected; if (!n || ev.button > 0) return; const st = this.sunState(n); if (!st) return;
        ev.preventDefault(); const t0 = ev.currentTarget; try { t0.setPointerCapture(ev.pointerId); } catch { /* */ }
        this.ready(n);
        const { phys, text, nl, i } = st, x0 = ev.clientX, y0 = ev.clientY, s = c.scale, bx = parseFloat(nl[i].x) || 0, by = parseFloat(nl[i].y) || 0; let moved = false; this.dragging = true;
        const mv = e2 => { nl[i].x = Math.round(bx + (e2.clientX - x0) / s) + 'px'; nl[i].y = Math.round(by + (e2.clientY - y0) / s) + 'px'; moved = moved || Math.hypot(e2.clientX - x0, e2.clientY - y0) > 1; this.D.preview([n], [[phys, FX.joinShadows(nl, text)]]); this.tip.hidden = false; this.tip.textContent = 'X ' + nl[i].x + ' · Y ' + nl[i].y; const LR = this.layer().getBoundingClientRect(); this.tip.style.cssText = 'left:' + (e2.clientX - LR.left) + 'px;top:' + Math.max(0, e2.clientY - LR.top - 28) + 'px'; t0.style.left = (e2.clientX - LR.left - t0.offsetWidth / 2) + 'px'; t0.style.top = (e2.clientY - LR.top - t0.offsetHeight / 2) + 'px'; };
        const up = e2 => { t0.removeEventListener('pointermove', mv); t0.removeEventListener('pointerup', up); t0.removeEventListener('pointercancel', up); this.dragging = false; this.tip.hidden = true; if (e2.type !== 'pointerup' || !moved) { this.D.unpreview(); this.drawHandles(); return; } this.put([[phys, FX.joinShadows(nl, text)]], (text ? 'צל טקסט' : 'צל') + ' · שכבה ' + (i + 1) + ' · X ' + nl[i].x + ' Y ' + nl[i].y); this.drawHandles(); };
        t0.addEventListener('pointermove', mv); t0.addEventListener('pointerup', up); t0.addEventListener('pointercancel', up);
      }
      sunWheel(ev) {
        const n = this.c.selected; if (!n) return; ev.preventDefault(); const st = this.wpend && this.wpend.n === n ? this.wpend : this.sunState(n); if (!st) return; st.n = n; this.wpend = st;   // גלגלת רצופה — על הערך שעוד לא נכתב
        const { phys, text, nl, i } = st, b = Math.max(0, Math.round((parseFloat(nl[i].blur) || 0) + (ev.deltaY < 0 ? 2 : -2))); nl[i].blur = b + 'px';
        this.ready(n); this.D.preview([n], [[phys, FX.joinShadows(nl, text)]]);
        clearTimeout(this.wt); this.wt = setTimeout(() => (this.wpend = null, this.put([[phys, FX.joinShadows(nl, text)]], (text ? 'צל טקסט' : 'צל') + ' · טשטוש ' + b + 'px')), 350);
      }
    }
    return Fx;
  }

  g.KolFx = { checkFx, checkSets, commas, parseShadows, joinShadows, FILTERS, parseFilter, joinFilter, setFilter, parseTransform, joinTransform, deg, bezierOf, bezierCss, NAMED_EASE,
    ENTRANCES, entranceCss, slidesCss, planMotion, MOTION_BASE, SCROLL_BASE, SHAPES, checkSvg, shapeHtml, SHAPE_CSS, VIDEO_CSS, MASKS };
  if (typeof window !== 'undefined' && window.document) g.KolFx.Fx = makeUi();
})(typeof window !== 'undefined' ? window : globalThis);
