// קריאת SVG בלי DOM: צבע, transform, אורך, צורות בסיס → `d`, ונתיב → `d` של ‎.mbe (M L C Q Z) אחרי מטריצה.
// הפונקציות לא נוגעות ב-document — מקבלות מחרוזות או פונקציה שקוראת מאפיין (el.getAttribute.bind(el)),
// כך שהן רצות גם ב-Node ובעובד רקע. הכפילות שהן מחליפות: ייבוא ה-SVG בווקטורי, ו-pathnorm.js / svg-import.js בסטודיו.

import { IDENTITY, multiply, translate } from './matrix.js';
import { parse, serialize, transform } from './path.js';

const NAMED = {
  black: '#000000', white: '#ffffff', red: '#ff0000', green: '#008000', blue: '#0000ff', yellow: '#ffff00', orange: '#ffa500',
  gray: '#808080', grey: '#808080', purple: '#800080', pink: '#ffc0cb', brown: '#a52a2a', navy: '#000080', teal: '#008080',
  lime: '#00ff00', cyan: '#00ffff', magenta: '#ff00ff', silver: '#c0c0c0', maroon: '#800000', olive: '#808000',
};

const NUMS = (s) => (String(s || '').match(/-?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?/gi) || []).map(Number);

/** צבע SVG → '#rrggbb'; null ל-none / transparent; undefined אם לא מובן (הקורא מחליט: שחור, פער בדוח…). */
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
      const n = x.endsWith('%') ? parseFloat(x) * 255 / 100 : parseFloat(x);
      return Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
    }).join('');
  }
  return undefined;
}

/** transform="translate(..) scale(..) rotate(a [cx cy]) skewX(..) skewY(..) matrix(..)" → מטריצה [a b c d e f]. */
export function parseTransform(str) {
  let m = IDENTITY.slice();
  const re = /(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/g;
  let t;
  while ((t = re.exec(str || ''))) {
    const a = NUMS(t[2]);
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

/** אורך SVG (width / height) → pt (יחידת SVG = 1pt, כמו ב-Illustrator). אחוזים, אפס או ערך לא תקין — null. */
export function parseLength(v) {
  const m = /^\s*([\d.]+(?:e[-+]?\d+)?)\s*(px|pt|mm|cm|in|pc)?\s*$/i.exec(v || '');
  if (!m || !(parseFloat(m[1]) > 0)) return null;
  return parseFloat(m[1]) * ({ mm: 72 / 25.4, cm: 72 / 2.54, in: 72, pc: 12 }[(m[2] || '').toLowerCase()] || 1);
}

/**
 * viewBox + width / height + preserveAspectRatio → { matrix, size: [w, h] | null }.
 * גודל חסר — לפי ה-viewBox ויחס הצלעות שלו; ברירת המחדל של SVG: קנה מידה אחיד, במרכז (xMidYMid meet); 'none' — מתיחה.
 */
export function viewBoxMatrix(viewBox, width, height, preserveAspectRatio = '') {
  const vb = String(viewBox || '').split(/[\s,]+/).filter(Boolean).map(Number);
  let w = parseLength(width), h = parseLength(height);
  if (vb.length === 4 && vb.every(Number.isFinite) && vb[2] > 0 && vb[3] > 0) {
    if (w == null && h == null) [w, h] = [vb[2], vb[3]];
    else if (w == null) w = h * vb[2] / vb[3];
    else if (h == null) h = w * vb[3] / vb[2];
    if (String(preserveAspectRatio).trim() === 'none') {
      return { matrix: multiply([w / vb[2], 0, 0, h / vb[3], 0, 0], translate(-vb[0], -vb[1])), size: [w, h] };
    }
    const k = Math.min(w / vb[2], h / vb[3]);
    return { matrix: multiply([k, 0, 0, k, (w - vb[2] * k) / 2, (h - vb[3] * k) / 2], translate(-vb[0], -vb[1])), size: [w, h] };
  }
  return { matrix: IDENTITY.slice(), size: w != null && h != null ? [w, h] : null };
}

/**
 * צורת בסיס של SVG → `d` (גולמי; parse / normalize ינרמלו אותו). tag — path / rect / circle / ellipse / line /
 * polyline / polygon; attr(name) → ערך המאפיין או null. תג אחר, או צורה בלי שטח — ''.
 */
export function elementToD(tag, attr) {
  const n = (k, d = 0) => { const v = parseFloat(attr(k)); return Number.isFinite(v) ? v : d; };
  switch (String(tag).replace(/^svg:/, '').toLowerCase()) {
    case 'path': return attr('d') || '';
    case 'rect': {
      const x = n('x'), y = n('y'), w = n('width'), h = n('height');
      if (!(w > 0 && h > 0)) return '';
      let rx = n('rx', NaN), ry = n('ry', NaN);
      if (Number.isNaN(rx)) rx = Number.isNaN(ry) ? 0 : ry;
      if (Number.isNaN(ry)) ry = rx;
      rx = Math.min(Math.max(rx, 0), w / 2); ry = Math.min(Math.max(ry, 0), h / 2);
      if (!rx || !ry) return `M${x} ${y} H${x + w} V${y + h} H${x} Z`;
      return `M${x + rx} ${y} H${x + w - rx} A${rx} ${ry} 0 0 1 ${x + w} ${y + ry} V${y + h - ry} A${rx} ${ry} 0 0 1 ${x + w - rx} ${y + h} ` +
        `H${x + rx} A${rx} ${ry} 0 0 1 ${x} ${y + h - ry} V${y + ry} A${rx} ${ry} 0 0 1 ${x + rx} ${y} Z`;
    }
    case 'circle': case 'ellipse': {
      const circle = String(tag).toLowerCase().endsWith('circle');
      const cx = n('cx'), cy = n('cy'), rx = circle ? n('r') : n('rx'), ry = circle ? rx : n('ry');
      if (!(rx > 0 && ry > 0)) return '';
      return `M${cx - rx} ${cy} A${rx} ${ry} 0 1 0 ${cx + rx} ${cy} A${rx} ${ry} 0 1 0 ${cx - rx} ${cy} Z`;
    }
    case 'line': return `M${n('x1')} ${n('y1')} L${n('x2')} ${n('y2')}`;
    case 'polyline': case 'polygon': {
      const p = NUMS(attr('points'));
      if (p.length < 4) return '';
      let d = `M${p[0]} ${p[1]}`;
      for (let i = 2; i + 1 < p.length; i += 2) d += ` L${p[i]} ${p[i + 1]}`;
      return /polygon$/i.test(tag) ? d + ' Z' : d;
    }
  }
  return '';
}

/** `d` כלשהו של SVG → `d` של ‎.mbe (M L C Q Z מוחלטים) אחרי מטריצה. זורק על נתיב פגום. */
export function toMbePath(d, m = IDENTITY) {
  return serialize(transform(parse(d), m));
}
