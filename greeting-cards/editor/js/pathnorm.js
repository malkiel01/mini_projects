// נרמול נתיבי SVG לפורמט ‎.mbe: רק פקודות מוחלטות M L C Q Z (mbe-format.md, "תוכן שכבה vector").
// הנתיבים שלנו (אייקוני Tabler, האלמנטים) משתמשים גם ב-H V S T A ובפקודות יחסיות — כאן הם הופכים לקווים ולעקומות,
// ואז עוברים מטריצה (מיקום, קנה מידה, סיבוב) — כך הנתיב יושב בדיוק במקום שלו בעמוד.

const NUM = /-?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?/gi;

/** מפרק נתיב לרשימת [פקודה, מספרים], עם חזרה מרומזת של פקודות (M אחרי M = L). */
function tokens(d) {
    const out = [];
    const re = /([MLHVCSQTAZmlhvcsqtaz])([^MLHVCSQTAZmlhvcsqtaz]*)/g;
    const arity = { M: 2, L: 2, H: 1, V: 1, C: 6, S: 4, Q: 4, T: 2, A: 7, Z: 0 };
    let m;
    while ((m = re.exec(d))) {
        let cmd = m[1];
        const n = (m[2].match(NUM) || []).map(Number);
        const k = arity[cmd.toUpperCase()];
        if (!k) { out.push([cmd, []]); continue; }
        for (let i = 0; i < n.length; i += k) {
            out.push([cmd, n.slice(i, i + k)]);
            if (cmd === 'M') cmd = 'L';
            else if (cmd === 'm') cmd = 'l';
        }
    }
    return out;
}

/** קשת (A) → עקומות C. לפי SVG 1.1, נספח F.6. */
function arcToCubics(x1, y1, rx, ry, phi, fa, fs, x2, y2) {
    if (rx === 0 || ry === 0) return [[x2, y2]];
    const rad = (phi * Math.PI) / 180, cos = Math.cos(rad), sin = Math.sin(rad);
    const dx = (x1 - x2) / 2, dy = (y1 - y2) / 2;
    const x1p = cos * dx + sin * dy, y1p = -sin * dx + cos * dy;
    rx = Math.abs(rx); ry = Math.abs(ry);
    const lam = (x1p * x1p) / (rx * rx) + (y1p * y1p) / (ry * ry);
    if (lam > 1) { rx *= Math.sqrt(lam); ry *= Math.sqrt(lam); }
    const sign = fa === fs ? -1 : 1;
    const num = rx * rx * ry * ry - rx * rx * y1p * y1p - ry * ry * x1p * x1p;
    const co = sign * Math.sqrt(Math.max(0, num / (rx * rx * y1p * y1p + ry * ry * x1p * x1p)));
    const cxp = (co * rx * y1p) / ry, cyp = (-co * ry * x1p) / rx;
    const cx = cos * cxp - sin * cyp + (x1 + x2) / 2, cy = sin * cxp + cos * cyp + (y1 + y2) / 2;
    const ang = (ux, uy, vx, vy) => { const a = Math.atan2(ux * vy - uy * vx, ux * vx + uy * vy); return a; };
    let t1 = ang(1, 0, (x1p - cxp) / rx, (y1p - cyp) / ry);
    let dt = ang((x1p - cxp) / rx, (y1p - cyp) / ry, (-x1p - cxp) / rx, (-y1p - cyp) / ry);
    if (!fs && dt > 0) dt -= 2 * Math.PI;
    if (fs && dt < 0) dt += 2 * Math.PI;
    const segs = Math.ceil(Math.abs(dt) / (Math.PI / 2));
    const delta = dt / segs, k = (4 / 3) * Math.tan(delta / 4);
    const pt = t => [cx + rx * Math.cos(t) * cos - ry * Math.sin(t) * sin, cy + rx * Math.cos(t) * sin + ry * Math.sin(t) * cos];
    const der = t => [-rx * Math.sin(t) * cos - ry * Math.cos(t) * sin, -rx * Math.sin(t) * sin + ry * Math.cos(t) * cos];
    const out = [];
    for (let i = 0; i < segs; i++) {
        const a = t1 + i * delta, b = a + delta;
        const [ax, ay] = pt(a), [bx, by] = pt(b), [dax, day] = der(a), [dbx, dby] = der(b);
        out.push([ax + k * dax, ay + k * day, bx - k * dbx, by - k * dby, bx, by]);
    }
    return out;
}

/**
 * נתיב SVG כלשהו → מערך פקודות מוחלטות [['M',x,y], ['L',x,y], ['C',...6], ['Q',...4], ['Z']].
 */
export function absolutize(d) {
    const out = [];
    let x = 0, y = 0, sx = 0, sy = 0, lastC = null, lastQ = null;
    for (const [cmd, a] of tokens(d)) {
        const rel = cmd === cmd.toLowerCase() && cmd !== 'z';
        const C = cmd.toUpperCase();
        const ox = rel ? x : 0, oy = rel ? y : 0;
        let nc = null, nq = null;
        switch (C) {
            case 'M': x = a[0] + ox; y = a[1] + oy; sx = x; sy = y; out.push(['M', x, y]); break;
            case 'L': x = a[0] + ox; y = a[1] + oy; out.push(['L', x, y]); break;
            case 'H': x = a[0] + (rel ? x : 0); out.push(['L', x, y]); break;
            case 'V': y = a[0] + (rel ? y : 0); out.push(['L', x, y]); break;
            case 'C': {
                const c = [a[0] + ox, a[1] + oy, a[2] + ox, a[3] + oy, a[4] + ox, a[5] + oy];
                out.push(['C', ...c]); nc = [c[2], c[3]]; x = c[4]; y = c[5]; break;
            }
            case 'S': {
                const r = lastC ? [2 * x - lastC[0], 2 * y - lastC[1]] : [x, y];
                const c = [r[0], r[1], a[0] + ox, a[1] + oy, a[2] + ox, a[3] + oy];
                out.push(['C', ...c]); nc = [c[2], c[3]]; x = c[4]; y = c[5]; break;
            }
            case 'Q': {
                const c = [a[0] + ox, a[1] + oy, a[2] + ox, a[3] + oy];
                out.push(['Q', ...c]); nq = [c[0], c[1]]; x = c[2]; y = c[3]; break;
            }
            case 'T': {
                const r = lastQ ? [2 * x - lastQ[0], 2 * y - lastQ[1]] : [x, y];
                const c = [r[0], r[1], a[0] + ox, a[1] + oy];
                out.push(['Q', ...c]); nq = r; x = c[2]; y = c[3]; break;
            }
            case 'A': {
                const ex = a[5] + ox, ey = a[6] + oy;
                for (const seg of arcToCubics(x, y, a[0], a[1], a[2], !!a[3], !!a[4], ex, ey)) {
                    out.push(seg.length === 2 ? ['L', ...seg] : ['C', ...seg]);
                }
                x = ex; y = ey; break;
            }
            case 'Z': out.push(['Z']); x = sx; y = sy; break;
        }
        lastC = nc; lastQ = nq;
    }
    return out;
}

/** מטריצה [a b c d e f] על כל הנקודות, וכתיבה כמחרוזת M L C Q Z. */
export function toMbePath(d, m = [1, 0, 0, 1, 0, 0]) {
    const f = v => +v.toFixed(2);
    const T = (px, py) => [f(m[0] * px + m[2] * py + m[4]), f(m[1] * px + m[3] * py + m[5])];
    return absolutize(d).map(([c, ...n]) => {
        if (c === 'Z') return 'Z';
        const p = [];
        for (let i = 0; i < n.length; i += 2) p.push(...T(n[i], n[i + 1]));
        return c + p.join(' ');
    }).join(' ');
}

/** מטריצה: קנה מידה, ואז סיבוב סביב ראשית השכבה, ואז הזזה — כמו ש-Konva מצייר קבוצה. */
export function layerMatrix(x, y, rotationDeg, sx = 1, sy = 1) {
    const r = (rotationDeg * Math.PI) / 180, c = Math.cos(r), s = Math.sin(r);
    return [c * sx, s * sx, -s * sy, c * sy, x, y];
}

/** מכפלת מטריצות: קודם b, אחר כך a. */
export function mul(a, b) {
    return [a[0] * b[0] + a[2] * b[1], a[1] * b[0] + a[3] * b[1], a[0] * b[2] + a[2] * b[3], a[1] * b[2] + a[3] * b[3],
        a[0] * b[4] + a[2] * b[5] + a[4], a[1] * b[4] + a[3] * b[5] + a[5]];
}
