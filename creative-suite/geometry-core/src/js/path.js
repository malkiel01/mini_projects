// נתיבים: פענוח `d` של SVG, נרמול לפקודות מוחלטות M L C Q Z, כתיבה, טרנספורמציה ותיבה חוסמת.
//
// מקטע = { c: 'M'|'L'|'C'|'Q'|'Z', p: [...] } — p בנקודות מוחלטות:
//   M/L [x y] · C [x1 y1 x2 y2 x y] · Q [x1 y1 x y] · Z [].
// כך כל קורא של ‎.mbe מצייר את `d` עם ארבע פקודות בלבד (בקשה: creative-suite/docs/requests/vector-studio-paths.md).

import { apply } from './matrix.js';

const ARGS = { M: 2, L: 2, H: 1, V: 1, C: 6, S: 4, Q: 4, T: 2, A: 7, Z: 0 };
const NUM = /^[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/;

/** סורק תווים: מספרים, פקודות, ודגלי קשת — שיכולים להיות צמודים ("a1 1 0 011 1"). */
function scanner(d) {
  let i = 0;
  const skip = () => { while (i < d.length && /[\s,]/.test(d[i])) i++; };
  return {
    done() { skip(); return i >= d.length; },
    cmd() { skip(); return /[MmLlHhVvCcSsQqTtAaZz]/.test(d[i] || '') ? d[i++] : null; },
    number() {
      skip();
      const m = NUM.exec(d.slice(i));
      if (!m) return null;
      i += m[0].length;
      return Number(m[0]);
    },
    flag() { skip(); const c = d[i]; if (c !== '0' && c !== '1') return null; i++; return Number(c); },
  };
}

/** פענוח `d` לרשימת מקטעים מוחלטים M L C Q Z. זורק על תחביר שגוי. */
export function parse(d) {
  const sc = scanner(String(d || ''));
  const segs = [];
  let cmd = null, x = 0, y = 0, sx = 0, sy = 0;
  let lastC = null, lastQ = null; // נקודת בקרה אחרונה, לשיקוף ב-S / T

  while (!sc.done()) {
    const c = sc.cmd();
    if (c) cmd = c;
    else if (cmd === null) throw new Error('נתיב חייב להתחיל בפקודה');
    const up = cmd.toUpperCase();
    const rel = cmd !== up;
    if (up === 'Z') {
      segs.push({ c: 'Z', p: [] });
      x = sx; y = sy; lastC = lastQ = null;
      cmd = null;
      continue;
    }
    const a = [];
    for (let k = 0; k < ARGS[up]; k++) {
      const v = up === 'A' && (k === 3 || k === 4) ? sc.flag() : sc.number();
      if (v === null || !Number.isFinite(v)) throw new Error('נתיב לא תקין');
      a.push(v);
    }
    const ox = rel ? x : 0, oy = rel ? y : 0;

    switch (up) {
      case 'M':
        x = a[0] + ox; y = a[1] + oy; sx = x; sy = y;
        segs.push({ c: 'M', p: [x, y] });
        cmd = rel ? 'l' : 'L'; // זוגות נוספים אחרי M הם L
        lastC = lastQ = null;
        break;
      case 'L': x = a[0] + ox; y = a[1] + oy; segs.push({ c: 'L', p: [x, y] }); lastC = lastQ = null; break;
      case 'H': x = a[0] + ox; segs.push({ c: 'L', p: [x, y] }); lastC = lastQ = null; break;
      case 'V': y = a[0] + (rel ? y : 0); segs.push({ c: 'L', p: [x, y] }); lastC = lastQ = null; break;
      case 'C': {
        const p = [a[0] + ox, a[1] + oy, a[2] + ox, a[3] + oy, a[4] + ox, a[5] + oy];
        segs.push({ c: 'C', p }); lastC = [p[2], p[3]]; lastQ = null; x = p[4]; y = p[5];
        break;
      }
      case 'S': {
        const c1 = lastC ? [2 * x - lastC[0], 2 * y - lastC[1]] : [x, y];
        const p = [c1[0], c1[1], a[0] + ox, a[1] + oy, a[2] + ox, a[3] + oy];
        segs.push({ c: 'C', p }); lastC = [p[2], p[3]]; lastQ = null; x = p[4]; y = p[5];
        break;
      }
      case 'Q': {
        const p = [a[0] + ox, a[1] + oy, a[2] + ox, a[3] + oy];
        segs.push({ c: 'Q', p }); lastQ = [p[0], p[1]]; lastC = null; x = p[2]; y = p[3];
        break;
      }
      case 'T': {
        const c1 = lastQ ? [2 * x - lastQ[0], 2 * y - lastQ[1]] : [x, y];
        const p = [c1[0], c1[1], a[0] + ox, a[1] + oy];
        segs.push({ c: 'Q', p }); lastQ = c1; lastC = null; x = p[2]; y = p[3];
        break;
      }
      case 'A': {
        const ex = a[5] + ox, ey = a[6] + oy;
        for (const p of arcToCubics(x, y, a[0], a[1], a[2], a[3], a[4], ex, ey)) segs.push({ c: 'C', p });
        x = ex; y = ey; lastC = lastQ = null;
        break;
      }
    }
  }
  if (segs.length && segs[0].c !== 'M') throw new Error('נתיב חייב להתחיל ב-M');
  return segs;
}

/** קשת אליפטית → עקומות בזייה מעוקבות (SVG 1.1, נספח F.6). */
function arcToCubics(x1, y1, rx, ry, phi, fa, fs, x2, y2) {
  if (x1 === x2 && y1 === y2) return [];
  rx = Math.abs(rx); ry = Math.abs(ry);
  if (!rx || !ry) return [[x1, y1, x2, y2, x2, y2]];
  const rad = phi * Math.PI / 180, cos = Math.cos(rad), sin = Math.sin(rad);
  const dx = (x1 - x2) / 2, dy = (y1 - y2) / 2;
  const x1p = cos * dx + sin * dy, y1p = -sin * dx + cos * dy;
  const lam = (x1p * x1p) / (rx * rx) + (y1p * y1p) / (ry * ry);
  if (lam > 1) { rx *= Math.sqrt(lam); ry *= Math.sqrt(lam); }
  const num = rx * rx * ry * ry - rx * rx * y1p * y1p - ry * ry * x1p * x1p;
  const den = rx * rx * y1p * y1p + ry * ry * x1p * x1p;
  let co = Math.sqrt(Math.max(0, num / den));
  if (!!fa === !!fs) co = -co;
  const cxp = co * rx * y1p / ry, cyp = -co * ry * x1p / rx;
  const cx = cos * cxp - sin * cyp + (x1 + x2) / 2, cy = sin * cxp + cos * cyp + (y1 + y2) / 2;
  const ang = (ux, uy, vx, vy) => {
    const a = Math.atan2(ux * vy - uy * vx, ux * vx + uy * vy);
    return a;
  };
  const t1 = ang(1, 0, (x1p - cxp) / rx, (y1p - cyp) / ry);
  let dt = ang((x1p - cxp) / rx, (y1p - cyp) / ry, (-x1p - cxp) / rx, (-y1p - cyp) / ry);
  if (!fs && dt > 0) dt -= 2 * Math.PI;
  if (fs && dt < 0) dt += 2 * Math.PI;
  const n = Math.ceil(Math.abs(dt) / (Math.PI / 2) - 1e-9);
  const step = dt / n, k = 4 / 3 * Math.tan(step / 4);
  const pt = (t) => {
    const ex = rx * Math.cos(t), ey = ry * Math.sin(t);
    return [cos * ex - sin * ey + cx, sin * ex + cos * ey + cy];
  };
  const der = (t) => {
    const ex = -rx * Math.sin(t), ey = ry * Math.cos(t);
    return [cos * ex - sin * ey, sin * ex + cos * ey];
  };
  const out = [];
  for (let j = 0; j < n; j++) {
    const a1 = t1 + j * step, a2 = a1 + step;
    const p1 = pt(a1), p2 = pt(a2), d1 = der(a1), d2 = der(a2);
    out.push([p1[0] + k * d1[0], p1[1] + k * d1[1], p2[0] - k * d2[0], p2[1] - k * d2[1], p2[0], p2[1]]);
  }
  const last = out[out.length - 1];
  last[4] = x2; last[5] = y2; // בלי סטייה של עיגול בנקודת הסיום
  return out;
}

/** מספר קצר: עד 3 ספרות אחרי הנקודה, בלי אפסים מיותרים ובלי "‎-0". */
export function num(v) {
  const r = Math.round(v * 1000) / 1000;
  return Object.is(r, -0) ? '0' : String(r);
}

export function serialize(segs) {
  return segs.map(s => s.c === 'Z' ? 'Z' : s.c + s.p.map(num).join(' ')).join(' ');
}

/** נרמול: פענוח וכתיבה מחדש — `d` תקני בפקודות M L C Q Z בלבד. */
export const normalize = (d) => serialize(parse(d));

export function transform(segs, m) {
  return segs.map(s => {
    const p = [];
    for (let i = 0; i < s.p.length; i += 2) p.push(...apply(m, s.p[i], s.p[i + 1]));
    return { c: s.c, p };
  });
}

/** קיצון של עקומה בציר אחד: נקודות t שבהן הנגזרת מתאפסת, בתוך (0,1). */
function cubicRoots(p0, p1, p2, p3) {
  const a = -p0 + 3 * p1 - 3 * p2 + p3, b = 2 * (p0 - 2 * p1 + p2), c = p1 - p0;
  const ts = [];
  if (Math.abs(a) < 1e-12) { if (Math.abs(b) > 1e-12) ts.push(-c / b); }
  else {
    const disc = b * b - 4 * a * c;
    if (disc >= 0) { const s = Math.sqrt(disc); ts.push((-b + s) / (2 * a), (-b - s) / (2 * a)); }
  }
  return ts.filter(t => t > 0 && t < 1);
}
const cubicAt = (p0, p1, p2, p3, t) => {
  const u = 1 - t;
  return u * u * u * p0 + 3 * u * u * t * p1 + 3 * u * t * t * p2 + t * t * t * p3;
};

/** תיבה חוסמת מדויקת (כולל קיצוני העקומות): [x, y, w, h], או null לנתיב ריק. */
export function bbox(segs) {
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
  const add = (x, y) => { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; };
  let cx = 0, cy = 0, sx = 0, sy = 0;
  for (const s of segs) {
    const p = s.p;
    if (s.c === 'M') { cx = sx = p[0]; cy = sy = p[1]; add(cx, cy); }
    else if (s.c === 'L') { cx = p[0]; cy = p[1]; add(cx, cy); }
    else if (s.c === 'C' || s.c === 'Q') {
      // ריבועית → מעוקבת שקולה, ואז אותו חישוב
      const c = s.c === 'Q'
        ? [cx + 2 / 3 * (p[0] - cx), cy + 2 / 3 * (p[1] - cy), p[2] + 2 / 3 * (p[0] - p[2]), p[3] + 2 / 3 * (p[1] - p[3]), p[2], p[3]]
        : p;
      add(c[4], c[5]);
      for (const t of cubicRoots(cx, c[0], c[2], c[4])) add(cubicAt(cx, c[0], c[2], c[4], t), cubicAt(cy, c[1], c[3], c[5], t));
      for (const t of cubicRoots(cy, c[1], c[3], c[5])) add(cubicAt(cx, c[0], c[2], c[4], t), cubicAt(cy, c[1], c[3], c[5], t));
      cx = c[4]; cy = c[5];
    } else if (s.c === 'Z') { cx = sx; cy = sy; }
  }
  return x0 === Infinity ? null : [x0, y0, x1 - x0, y1 - y0];
}

/** איחוד תיבות [x y w h]; null מתעלמים. */
export function unionBox(boxes) {
  let r = null;
  for (const b of boxes) {
    if (!b) continue;
    if (!r) { r = b.slice(); continue; }
    const x0 = Math.min(r[0], b[0]), y0 = Math.min(r[1], b[1]);
    const x1 = Math.max(r[0] + r[2], b[0] + b[2]), y1 = Math.max(r[1] + r[3], b[1] + b[3]);
    r = [x0, y0, x1 - x0, y1 - y0];
  }
  return r;
}
