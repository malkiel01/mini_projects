// ייבוא SVG: צורות ונתיבים נכנסים כפריטים שלנו (עם `d` מנורמל), עם סגנון ותורשה מ-<g>, וטרנספורמציות נאפות.
// הקובץ לא נכנס ל-DOM (DOMParser לא מריץ סקריפטים), ומה שלא נתמך — נספר בדוח הפערים, לא נבלע בשקט.

import { normalize } from './geometry/path.js';
import { multiply, translate, IDENTITY } from './geometry/matrix.js';
import { applyMatrix, setShape } from './model.js';

const NAMED = {
  black: '#000000', white: '#ffffff', red: '#ff0000', green: '#008000', blue: '#0000ff', yellow: '#ffff00', orange: '#ffa500',
  gray: '#808080', grey: '#808080', purple: '#800080', pink: '#ffc0cb', brown: '#a52a2a', navy: '#000080', teal: '#008080',
  lime: '#00ff00', cyan: '#00ffff', magenta: '#ff00ff', silver: '#c0c0c0', maroon: '#800000', olive: '#808000',
};

/** צבע SVG → #rrggbb, null ל-none, undefined אם לא מובן (ואז נרשם פער). */
export function parseColor(v) {
  if (v == null) return undefined;
  v = String(v).trim().toLowerCase();
  if (v === 'none' || v === 'transparent') return null;
  if (v === 'currentcolor') return '#000000';
  if (NAMED[v]) return NAMED[v];
  let m = /^#([0-9a-f]{3})$/.exec(v);
  if (m) return '#' + [...m[1]].map(c => c + c).join('');
  m = /^#([0-9a-f]{6})$/.exec(v);
  if (m) return v;
  m = /^rgba?\(\s*([\d.]+%?)\s*[, ]\s*([\d.]+%?)\s*[, ]\s*([\d.]+%?)/.exec(v);
  if (m) {
    return '#' + m.slice(1, 4).map(x => {
      const n = x.endsWith('%') ? parseFloat(x) * 2.55 : parseFloat(x);
      return Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
    }).join('');
  }
  return undefined;
}

/** transform="…" → מטריצה. */
export function parseTransform(str) {
  let m = IDENTITY.slice();
  const re = /(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/g;
  let t;
  while ((t = re.exec(str || ''))) {
    const a = t[2].split(/[\s,]+/).filter(Boolean).map(Number);
    let k;
    switch (t[1]) {
      case 'matrix': k = a.length === 6 ? a : IDENTITY; break;
      case 'translate': k = translate(a[0] || 0, a[1] || 0); break;
      case 'scale': k = [a[0] ?? 1, 0, 0, a[1] ?? a[0] ?? 1, 0, 0]; break;
      case 'rotate': {
        const r = (a[0] || 0) * Math.PI / 180, c = Math.cos(r), s = Math.sin(r);
        k = [c, s, -s, c, 0, 0];
        if (a.length >= 3) k = multiply(multiply(translate(a[1], a[2]), k), translate(-a[1], -a[2]));
        break;
      }
      case 'skewX': k = [1, 0, Math.tan((a[0] || 0) * Math.PI / 180), 1, 0, 0]; break;
      case 'skewY': k = [1, Math.tan((a[0] || 0) * Math.PI / 180), 0, 1, 0, 0]; break;
    }
    m = multiply(m, k);
  }
  return m;
}

const STYLE_KEYS = ['fill', 'stroke', 'stroke-width', 'opacity', 'fill-opacity', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'fill-rule', 'display', 'visibility'];

function ownStyle(el) {
  const s = {};
  for (const k of STYLE_KEYS) if (el.hasAttribute(k)) s[k] = el.getAttribute(k);
  for (const decl of (el.getAttribute('style') || '').split(';')) {
    const [k, v] = decl.split(':').map(x => x && x.trim());
    if (k && v && STYLE_KEYS.includes(k)) s[k] = v;
  }
  return s;
}

const num = (v, d = 0) => { const n = parseFloat(v); return Number.isFinite(n) ? n : d; };

/**
 * text — תוכן קובץ SVG. מחזיר { items, gaps, size: [w, h] | null }. מזהים — מ-next().
 */
export function importSVG(text, next) {
  const dom = new DOMParser().parseFromString(text, 'image/svg+xml');
  const root = dom.documentElement;
  if (!root || root.nodeName.toLowerCase() !== 'svg' || dom.querySelector('parsererror')) throw new Error('זה לא קובץ SVG תקין');
  const gaps = new Map();
  const gap = (k) => gaps.set(k, (gaps.get(k) || 0) + 1);

  // viewBox → גודל: יחידה אחת של SVG = 1pt (כמו ב-Illustrator)
  const vb = (root.getAttribute('viewBox') || '').split(/[\s,]+/).map(Number);
  let base = IDENTITY, size = null;
  if (vb.length === 4 && vb.every(Number.isFinite) && vb[2] > 0 && vb[3] > 0) {
    const w = num(root.getAttribute('width'), vb[2]), h = num(root.getAttribute('height'), vb[3]);
    base = multiply([w / vb[2], 0, 0, h / vb[3], 0, 0], translate(-vb[0], -vb[1]));
    size = [w, h];
  } else if (root.hasAttribute('width') && root.hasAttribute('height')) size = [num(root.getAttribute('width')), num(root.getAttribute('height'))];

  const walk = (el, m, style) => {
    const tag = el.nodeName.replace(/^svg:/, '').toLowerCase();
    if (['defs', 'title', 'desc', 'metadata', 'style', 'script'].includes(tag)) {
      if (tag === 'style') gap('CSS (<style>) — לא נקרא');
      if (tag === 'script') gap('סקריפט — הושמט');
      return [];
    }
    const st = { ...style, ...ownStyle(el) };
    if (st.display === 'none' || st.visibility === 'hidden') return [];
    const mm = el.hasAttribute('transform') ? multiply(m, parseTransform(el.getAttribute('transform'))) : m;
    if (tag === 'g' || tag === 'svg' || tag === 'a') {
      if (el.hasAttribute('clip-path') || el.hasAttribute('mask') || el.hasAttribute('filter')) gap('חיתוך / מסכה / פילטר — הושמט');
      const kids = [...el.children].flatMap(c => walk(c, mm, st));
      if (tag === 'g' && kids.length > 1) return [{ id: next(), type: 'group', name: el.getAttribute('id') || 'קבוצה', children: kids }];
      return kids;
    }
    let shape = null, d = null;
    const A = (k, def = 0) => num(el.getAttribute(k), def);
    switch (tag) {
      case 'path': d = el.getAttribute('d'); break;
      case 'rect': {
        const rx = el.hasAttribute('rx') ? A('rx') : A('ry');
        shape = { type: 'rect', box: [A('x'), A('y'), A('width'), A('height')], radius: rx };
        if (el.hasAttribute('ry') && A('ry') !== rx) gap('מלבן עם פינות לא שוות — פינות שוות');
        break;
      }
      case 'circle': { const r = A('r'); shape = { type: 'ellipse', box: [A('cx') - r, A('cy') - r, 2 * r, 2 * r] }; break; }
      case 'ellipse': shape = { type: 'ellipse', box: [A('cx') - A('rx'), A('cy') - A('ry'), 2 * A('rx'), 2 * A('ry')] }; break;
      case 'line': shape = { type: 'line', from: [A('x1'), A('y1')], to: [A('x2'), A('y2')] }; break;
      case 'polyline': case 'polygon': {
        const p = (el.getAttribute('points') || '').trim().split(/[\s,]+/).map(Number);
        if (p.length >= 4) d = 'M' + p.join(' ').replace(/^(\S+ \S+)/, '$1 L') + (tag === 'polygon' ? ' Z' : '');
        break;
      }
      default:
        gap(`<${tag}> — לא נתמך`);
        return [];
    }
    if (shape && ((shape.box && (shape.box[2] <= 0 || shape.box[3] <= 0)))) return [];
    const item = { id: next(), d: '' };
    try {
      if (shape) setShape(item, shape); else item.d = normalize(d || '');
    } catch { gap('נתיב פגום — הושמט'); return []; }
    if (!item.d) return [];

    const op = num(st.opacity, 1);
    const fillC = parseColor(st.fill ?? '#000000');
    if (fillC === undefined) gap(String(st.fill).startsWith('url(') ? 'מעבר צבע / דוגמה במילוי → שחור' : 'צבע לא מוכר → שחור');
    const fc = fillC === undefined ? '#000000' : fillC;
    item.fill = fc && tag !== 'line' ? { color: fc } : null; // לקו אין מילוי; לקו שבור — יש, כמו ב-SVG
    if (item.fill && num(st['fill-opacity'], 1) * op < 1) item.fill.opacity = num(st['fill-opacity'], 1) * op;
    const sc = parseColor(st.stroke ?? 'none');
    if (sc === undefined) gap(String(st.stroke).startsWith('url(') ? 'מעבר צבע במתאר → שחור' : 'צבע לא מוכר → שחור');
    const strokeC = sc === undefined ? '#000000' : sc;
    item.stroke = strokeC ? {
      color: strokeC, width: num(st['stroke-width'], 1), cap: st['stroke-linecap'] || 'butt', join: st['stroke-linejoin'] || 'miter',
      dash: st['stroke-dasharray'] && st['stroke-dasharray'] !== 'none' ? st['stroke-dasharray'].split(/[\s,]+/).map(Number).filter(Number.isFinite) : [],
    } : null;
    if (item.stroke && num(st['stroke-opacity'], 1) * op < 1) item.stroke.opacity = num(st['stroke-opacity'], 1) * op;
    if (st['fill-rule'] === 'evenodd') item.fillRule = 'evenodd';
    applyMatrix(item, mm);
    // קנה מידה בקובץ — עובי המתאר ורדיוס הפינות גדלים איתו (הטרנספורמציה נאפתה ל-d, אז מתקנים אותם)
    const k = Math.sqrt(Math.abs(mm[0] * mm[3] - mm[1] * mm[2])) || 1;
    if (Math.abs(k - 1) > 1e-6) {
      const r3 = (v) => Math.round(v * 1000) / 1000;
      if (item.stroke) { item.stroke.width = r3(item.stroke.width * k); item.stroke.dash = item.stroke.dash.map(v => r3(v * k)); }
      if (item.shape?.type === 'rect' && item.shape.radius) setShape(item, { ...item.shape, radius: item.shape.radius * k });
    }
    return [item];
  };

  const items = walk(root, base, {});
  return { items, gaps: [...gaps].map(([k, n]) => (n > 1 ? `${k} (×${n})` : k)), size };
}
