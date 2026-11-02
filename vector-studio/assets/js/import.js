// ייבוא SVG: צורות ונתיבים נכנסים כפריטים שלנו (עם `d` מנורמל), עם סגנון ותורשה מ-<g>, וטרנספורמציות נאפות.
// הקובץ לא נכנס ל-DOM (DOMParser לא מריץ סקריפטים), ומה שלא נתמך — נספר בדוח הפערים, לא נבלע בשקט.

import { normalize, parse, bbox } from './geometry/path.js';
import { multiply } from './geometry/matrix.js';
import { parseColor, parseTransform, parseLength, viewBoxMatrix, elementToD } from './geometry/svg.js';
import { applyMatrix, setShape } from './model.js';

// צבע, transform, אורך ו-viewBox — מ-geometry-core (svg.js). parseColor / parseTransform מיוצאים גם מכאן, כמו קודם.
export { parseColor, parseTransform };

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
const styleProp = (el, k) => ownStyleAny(el)[k];
function ownStyleAny(el) {
  const s = {};
  for (const decl of (el.getAttribute('style') || '').split(';')) {
    const [k, v] = decl.split(':').map(x => x && x.trim());
    if (k && v) s[k] = v;
  }
  return s;
}
let gradientFor = () => null;

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
  const { matrix: base, size } = viewBoxMatrix(root.getAttribute('viewBox'), root.getAttribute('width'),
    root.getAttribute('height'), root.getAttribute('preserveAspectRatio') || '');

  const walk = (el, m, style) => {
    const tag = el.nodeName.replace(/^svg:/, '').toLowerCase();
    if (['defs', 'title', 'desc', 'metadata', 'style', 'script'].includes(tag)) {
      if (tag === 'style') gap('CSS (<style>) — לא נקרא');
      if (tag === 'script') gap('סקריפט — הושמט');
      return [];
    }
    const own = ownStyle(el);
    const st = { ...style, ...own };
    // opacity מצטברת (קבוצה חצי שקופה ובתוכה צורה חצי שקופה — רבע), לא מחליפה
    if (own.opacity != null) st.opacity = num(style.opacity, 1) * num(own.opacity, 1);
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
      case 'polyline': case 'polygon': d = elementToD(tag, (k) => el.getAttribute(k)); break;
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
    const url = /^url\(\s*['"]?#([^'")\s]+)/.exec(String(st.fill || ''));
    const grad = url && tag !== 'line' ? gradientFor(url[1], item) : null;
    if (fillC === undefined && !grad) gap(url ? 'מעבר צבע או דוגמה שלא נמצאו → שחור' : 'צבע לא מוכר → שחור');
    const fc = fillC === undefined ? '#000000' : fillC;
    item.fill = grad ? { gradient: grad } : fc && tag !== 'line' ? { color: fc } : null; // לקו אין מילוי; לקו שבור — יש, כמו ב-SVG
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
    // קנה מידה בקובץ — עובי המתאר ורדיוס הפינות גדלים איתו (הטרנספורמציה נאפתה ל-d, אז מתקנים אותם). סיבוב או
    // היפוך נשמרים ב-transform, ושם הקנבס כבר מגדיל את המתאר — אז לא פעמיים.
    const k = Math.sqrt(Math.abs(mm[0] * mm[3] - mm[1] * mm[2])) || 1;
    if (Math.abs(k - 1) > 1e-6 && !item.transform) {
      const r3 = (v) => Math.round(v * 1000) / 1000;
      if (item.stroke) { item.stroke.width = r3(item.stroke.width * k); item.stroke.dash = item.stroke.dash.map(v => r3(v * k)); }
      if (item.shape?.type === 'rect' && item.shape.radius) setShape(item, { ...item.shape, radius: item.shape.radius * k });
    }
    return [item];
  };

  // מעברי צבע (ישר ועגול) מ-<defs>, גם עם href לשרשרת stops
  const grads = new Map();
  const stopsOf = (el, seen = new Set()) => {
    const own = [...el.children].filter(c => c.nodeName.replace(/^svg:/, '').toLowerCase() === 'stop');
    if (own.length) return own.map(st => {
      const at = String(st.getAttribute('offset') || '0');
      return { at: Math.max(0, Math.min(1, at.endsWith('%') ? parseFloat(at) / 100 : num(at))),
        color: parseColor(st.getAttribute('stop-color') || styleProp(st, 'stop-color') || '#000000') || '#000000',
        ...(num(st.getAttribute('stop-opacity') ?? styleProp(st, 'stop-opacity'), 1) < 1 ? { opacity: num(st.getAttribute('stop-opacity') ?? styleProp(st, 'stop-opacity'), 1) } : {}) };
    });
    const href = (el.getAttribute('href') || el.getAttribute('xlink:href') || '').replace(/^#/, '');
    const ref = href && !seen.has(href) ? dom.getElementById(href) : null;
    return ref ? stopsOf(ref, new Set([...seen, href])) : [];
  };
  for (const tag of ['linearGradient', 'radialGradient']) for (const el of dom.getElementsByTagName(tag)) {
    if (el.id) grads.set(el.id, el);
  }
  gradientFor = (ref, item) => {
    const el = grads.get(ref);
    if (!el) return null;
    const stops = stopsOf(el);
    if (!stops.length) return null;
    if (el.hasAttribute('gradientTransform')) gap('טרנספורמציה של מעבר צבע — הושמטה');
    const user = el.getAttribute('gradientUnits') === 'userSpaceOnUse';
    const pc = (k, d) => { const v = el.getAttribute(k); if (v == null) return d; return v.endsWith('%') ? parseFloat(v) / 100 : num(v); };
    if (el.localName === 'radialGradient') {
      let cx = pc('cx', 0.5), cy = pc('cy', 0.5), rr = pc('r', 0.5);
      if ((el.hasAttribute('fx') && pc('fx', cx) !== cx) || (el.hasAttribute('fy') && pc('fy', cy) !== cy)) gap('מוקד של מעבר עגול — במרכז');
      if (!user) {
        const b = bbox(parse(item.d)) || [0, 0, 1, 1];
        if (Math.abs(b[2] - b[3]) > 1e-6) gap('מעבר עגול בתיבה לא ריבועית — עיגול');
        [cx, cy, rr] = [b[0] + cx * b[2], b[1] + cy * b[3], rr * Math.sqrt((b[2] * b[2] + b[3] * b[3]) / 2)];
      }
      return { type: 'radial', center: [cx, cy], radius: rr, stops };
    }
    let x1 = pc('x1', 0), y1 = pc('y1', 0), x2 = pc('x2', user ? 0 : 1), y2 = pc('y2', 0);
    if (!user) {
      const b = bbox(parse(item.d)) || [0, 0, 1, 1];
      [x1, x2] = [b[0] + x1 * b[2], b[0] + x2 * b[2]];
      [y1, y2] = [b[1] + y1 * b[3], b[1] + y2 * b[3]];
    }
    return { type: 'linear', from: [x1, y1], to: [x2, y2], stops };
  };

  const items = walk(root, base, {});
  return { items, gaps: [...gaps].map(([k, n]) => (n > 1 ? `${k} (×${n})` : k)), size };
}
