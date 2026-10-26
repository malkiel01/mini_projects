// SVG מהצ'אט → שכבות וקטור עריכות: כל צורה (path, rect, circle, ellipse, line, polyline, polygon) — שכבת shape:'path'
// משלה, עם צבע, קו ומיקום. כך קישוט שהצ'אט כתב כקוד (ולא כתמונה) נשאר עריך: צבע, גודל, הזזה, מחיקה.
// ה-SVG רק נקרא (DOMParser) — לא נכנס לדף, ולכן סקריפטים, קישורים וגופנים שבו לא רצים ולא נטענים.

import { toMbePath, absolutize, mul } from './pathnorm.js';
import { uid } from './doc.js';

const MAX_SHAPES = 300;
const NUMS = s => (String(s || '').match(/-?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?/gi) || []).map(Number);

/** transform="translate(..) scale(..) rotate(..) matrix(..)" → מטריצה. */
function parseTransform(t) {
    let m = [1, 0, 0, 1, 0, 0];
    const re = /(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/g;
    let x;
    while ((x = re.exec(t || ''))) {
        const a = NUMS(x[2]);
        let n;
        switch (x[1]) {
            case 'matrix': n = a.length === 6 ? a : null; break;
            case 'translate': n = [1, 0, 0, 1, a[0] || 0, a[1] || 0]; break;
            case 'scale': n = [a[0] ?? 1, 0, 0, a[1] ?? a[0] ?? 1, 0, 0]; break;
            case 'rotate': {
                const r = ((a[0] || 0) * Math.PI) / 180, c = Math.cos(r), s = Math.sin(r);
                n = [c, s, -s, c, 0, 0];
                if (a.length === 3) n = mul(mul([1, 0, 0, 1, a[1], a[2]], n), [1, 0, 0, 1, -a[1], -a[2]]);
                break;
            }
            case 'skewX': n = [1, 0, Math.tan(((a[0] || 0) * Math.PI) / 180), 1, 0, 0]; break;
            case 'skewY': n = [1, Math.tan(((a[0] || 0) * Math.PI) / 180), 0, 1, 0, 0]; break;
        }
        if (n) m = mul(m, n);
    }
    return m;
}

/** צבע SVG → #rrggbb(aa), או null ל-none. צבעים בשם — דרך הדפדפן. */
function color(v, opacity = 1) {
    if (!v || v === 'none' || v === 'transparent') return null;
    if (/^url\(/.test(v)) return null;   // מדרג / תבנית — לא נתמך כאן; מקבל את צבע ברירת המחדל של המעטפת
    const ctx = color.ctx ||= document.createElement('canvas').getContext('2d');
    ctx.fillStyle = '#000000';
    ctx.fillStyle = v;
    let hex = ctx.fillStyle;   // #rrggbb או rgba(...)
    let a = opacity;
    const m = /^rgba?\(([^)]+)\)/.exec(hex);
    if (m) {
        const p = m[1].split(',').map(Number);
        hex = '#' + p.slice(0, 3).map(n => n.toString(16).padStart(2, '0')).join('');
        if (p.length === 4) a *= p[3];
    }
    return a >= 0.999 ? hex : hex + Math.round(Math.max(0, Math.min(1, a)) * 255).toString(16).padStart(2, '0');
}

/** סגנון מורש: fill / stroke / stroke-width / opacity — מהמאפיינים ומ-style="". */
function styleOf(el, parent) {
    const st = { ...parent };
    const read = (name, v) => { if (v != null && v !== '' && v !== 'inherit') st[name] = v.trim(); };
    for (const k of ['fill', 'stroke', 'stroke-width', 'opacity', 'fill-opacity', 'stroke-opacity', 'fill-rule']) read(k, el.getAttribute(k));
    for (const part of (el.getAttribute('style') || '').split(';')) {
        const [k, v] = part.split(':');
        if (k && v) read(k.trim(), v);
    }
    if (el.getAttribute('opacity')) st.groupOpacity = (parent.groupOpacity ?? 1) * Number(el.getAttribute('opacity'));
    return st;
}

const num = (el, a, d = 0) => { const v = parseFloat(el.getAttribute(a)); return Number.isFinite(v) ? v : d; };

function shapeD(el) {
    switch (el.localName) {
        case 'path': return el.getAttribute('d') || '';
        case 'rect': {
            const x = num(el, 'x'), y = num(el, 'y'), w = num(el, 'width'), h = num(el, 'height');
            let rx = num(el, 'rx', NaN), ry = num(el, 'ry', NaN);
            if (Number.isNaN(rx)) rx = Number.isNaN(ry) ? 0 : ry;
            if (Number.isNaN(ry)) ry = rx;
            rx = Math.min(rx, w / 2); ry = Math.min(ry, h / 2);
            if (!rx && !ry) return `M${x} ${y} H${x + w} V${y + h} H${x} Z`;
            return `M${x + rx} ${y} H${x + w - rx} A${rx} ${ry} 0 0 1 ${x + w} ${y + ry} V${y + h - ry} A${rx} ${ry} 0 0 1 ${x + w - rx} ${y + h} H${x + rx} A${rx} ${ry} 0 0 1 ${x} ${y + h - ry} V${y + ry} A${rx} ${ry} 0 0 1 ${x + rx} ${y} Z`;
        }
        case 'circle': case 'ellipse': {
            const cx = num(el, 'cx'), cy = num(el, 'cy');
            const rx = el.localName === 'circle' ? num(el, 'r') : num(el, 'rx'), ry = el.localName === 'circle' ? rx : num(el, 'ry');
            return `M${cx - rx} ${cy} A${rx} ${ry} 0 1 0 ${cx + rx} ${cy} A${rx} ${ry} 0 1 0 ${cx - rx} ${cy} Z`;
        }
        case 'line': return `M${num(el, 'x1')} ${num(el, 'y1')} L${num(el, 'x2')} ${num(el, 'y2')}`;
        case 'polyline': case 'polygon': {
            const p = NUMS(el.getAttribute('points'));
            if (p.length < 4) return '';
            let d = `M${p[0]} ${p[1]}`;
            for (let i = 2; i + 1 < p.length; i += 2) d += ` L${p[i]} ${p[i + 1]}`;
            return el.localName === 'polygon' ? d + ' Z' : d;
        }
    }
    return '';
}

/**
 * ‏SVG (טקסט) → שכבות shape:'path' בעמוד. box — [x, y, w, h] בפיקסלי הדף שבו ה-SVG נמתח (שומר יחס, במרכז).
 * @returns {{layers: object[], skipped: number}}
 */
export function svgToLayers(text, box, name = 'קישוט') {
    const doc = new DOMParser().parseFromString(String(text), 'image/svg+xml');
    const svg = doc.documentElement;
    if (!svg || svg.localName !== 'svg' || doc.querySelector('parsererror')) throw new Error('ה-SVG לא תקין');
    const vb = NUMS(svg.getAttribute('viewBox'));
    const vw = vb.length === 4 ? vb[2] : num(svg, 'width', 100), vh = vb.length === 4 ? vb[3] : num(svg, 'height', 100);
    const vx = vb.length === 4 ? vb[0] : 0, vy = vb.length === 4 ? vb[1] : 0;
    const [bx, by, bw, bh] = box;
    const k = Math.min(bw / vw, bh / vh);
    const root = [k, 0, 0, k, bx + (bw - vw * k) / 2 - vx * k, by + (bh - vh * k) / 2 - vy * k];

    const layers = [];
    let skipped = 0;
    const walk = (el, m, st) => {
        for (const ch of el.children) {
            if (layers.length >= MAX_SHAPES) { skipped++; continue; }
            const tag = ch.localName;
            if (['defs', 'style', 'script', 'title', 'desc', 'metadata', 'clipPath', 'mask', 'linearGradient', 'radialGradient', 'pattern', 'symbol'].includes(tag)) continue;
            const cm = mul(m, parseTransform(ch.getAttribute('transform')));
            const cs = styleOf(ch, st);
            if (tag === 'g' || tag === 'svg') { walk(ch, cm, cs); continue; }
            const d = shapeD(ch);
            if (!d) { skipped++; continue; }   // טקסט, תמונה וכו' — לא נכנסים מ-SVG
            const op = (cs.groupOpacity ?? 1);
            const fill = tag === 'line' || (tag === 'polyline' && cs.fill == null) ? null : color(cs.fill ?? '#000000', Number(cs['fill-opacity'] ?? 1));
            const stroke = color(cs.stroke, Number(cs['stroke-opacity'] ?? 1));
            if (!fill && !stroke) continue;
            // הנתיב בעמוד, ואז יחסית לתיבה שלו — כמו שכבת path שקוראים מ-‎.mbe
            const pageD = toMbePath(d, cm);
            const pts = absolutize(pageD).flatMap(([, ...n]) => n);
            const xs = pts.filter((_, i) => i % 2 === 0), ys = pts.filter((_, i) => i % 2 === 1);
            if (!xs.length) { skipped++; continue; }
            const x0 = Math.min(...xs), y0 = Math.min(...ys);
            const w = Math.max(0.5, Math.max(...xs) - x0), h = Math.max(0.5, Math.max(...ys) - y0);
            const sw = parseFloat(cs['stroke-width'] ?? 1) * Math.hypot(cm[0], cm[1]);
            layers.push({
                id: uid(), type: 'shape', shape: 'path', role: 'decoration', name,
                path: toMbePath(pageD, [1, 0, 0, 1, -x0, -y0]), vb: [w, h],
                x: x0, y: y0, w, h, rotation: 0, opacity: Math.max(0, Math.min(1, op)), radius: 0, points: 5,
                fill: fill || '#00000000',
                ...(stroke ? { stroke: { color: stroke, width: Math.max(0.5, sw || 1) } } : {}),
            });
        }
    };
    walk(svg, root, {});
    return { layers, skipped };
}
