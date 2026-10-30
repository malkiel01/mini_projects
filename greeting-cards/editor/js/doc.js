// מודל המסמך: עיצוב = עמוד, נכסים ושכבות (spec/03-editor.md, schema/design.schema.json).
// כל העורך עובד על אובייקט JSON אחד. הקנבס רק מצייר אותו, וההיסטוריה שומרת צילומים שלו.

import { FONTS, DEFAULT_FONT } from './fonts.js';
import { ELEMENTS } from './elements.js';

export const SIZES = [
    { id: 'post', label: 'פוסט', w: 1080, h: 1350 },
    { id: 'square', label: 'ריבוע', w: 1080, h: 1080 },
    { id: 'story', label: 'סטורי', w: 1080, h: 1920 },
    { id: 'a4', label: 'A4', w: 2480, h: 3508 },
    { id: 'a5', label: 'A5', w: 1748, h: 2480 },
    { id: 'card', label: 'כרטיס', w: 1500, h: 1050 },
    { id: 'banner', label: 'באנר', w: 1920, h: 1080 },
];

export const ROLES = ['background', 'title', 'subtitle', 'body', 'cta', 'logo', 'photo', 'decoration', 'other'];

const MAX_SIDE = 10000;
const MAX_LAYERS = 500;

export function uid(prefix = 'l') {
    return prefix + Math.random().toString(36).slice(2, 9);
}

export function newDoc(w = 1080, h = 1350, title = 'עיצוב חדש') {
    return {
        version: 1,
        title,
        source: { app: 'editor' },
        page: { width: w, height: h, unit: 'px', background: '#ffffff' },
        assets: {},
        layers: [],
    };
}

/** טקסט חדש, במרכז העמוד ובגודל יחסי לעמוד. */
export function textLayer(doc, preset = 'body') {
    const { width: W, height: H } = doc.page;
    const base = Math.min(W, H);
    const p = {
        title: { text: 'כותרת', size: base * 0.11, weight: 800, role: 'title' },
        subtitle: { text: 'כותרת משנה', size: base * 0.065, weight: 700, role: 'subtitle' },
        body: { text: 'טקסט חופשי', size: base * 0.045, weight: 400, role: 'body' },
    }[preset];
    const w = W * 0.8;
    return {
        id: uid(), type: 'text', role: p.role, text: p.text,
        x: (W - w) / 2, y: H / 2 - p.size, w, h: p.size * 1.3, rotation: 0, opacity: 1,
        style: { font: DEFAULT_FONT, size: Math.round(p.size), weight: p.weight, color: '#1b1e26', align: 'center', dir: 'rtl', lineHeight: 1.2 },
    };
}

export function shapeLayer(doc, shape = 'rect') {
    const { width: W, height: H } = doc.page;
    const s = Math.min(W, H) * 0.35;
    return {
        id: uid(), type: 'shape', role: 'decoration', shape,
        x: (W - s) / 2, y: (H - s) / 2, w: s, h: shape === 'line' ? Math.max(4, s * 0.02) : s,
        rotation: 0, opacity: 1, fill: '#2d4a8a', radius: 0, points: 5,
    };
}

export function iconLayer(doc, name) {
    const { width: W, height: H } = doc.page;
    const s = Math.round(Math.min(W, H) * 0.2);
    return { id: uid(), type: 'icon', role: 'decoration', icon: name, x: (W - s) / 2, y: (H - s) / 2, w: s, h: s, rotation: 0, opacity: 1, color: '#2d4a8a', strokeWidth: 2 };
}

/** אלמנט מעוצב (elements.js): בגודל היחסי של הציור שלו, במרכז הדף. */
export function elementLayer(doc, name) {
    const { width: W, height: H } = doc.page;
    const el = ELEMENTS[name];
    const k = (Math.min(W, H) * 0.45) / Math.max(el.vb[0], el.vb[1]);
    const w = el.vb[0] * k, h = el.vb[1] * k;
    const base = { id: uid(), type: 'shape', role: 'decoration', shape: 'element', element: name, x: (W - w) / 2, y: (H - h) / 2, w, h, rotation: 0, opacity: 1, radius: 0, points: 5 };
    return el.stroke
        ? { ...base, fill: '#00000000', stroke: { width: Math.max(3, Math.min(W, H) * 0.008), color: '#b8924a' } }
        : { ...base, fill: '#e0457b' };
}

export function imageLayer(doc, assetId, natW, natH, role = 'photo') {
    const { width: W, height: H } = doc.page;
    const k = Math.min((W * 0.7) / natW, (H * 0.7) / natH, 1);
    const w = natW * k, h = natH * k;
    return { id: uid(), type: 'image', role, asset: assetId, x: (W - w) / 2, y: (H - h) / 2, w, h, rotation: 0, opacity: 1 };
}

/** תמונה כרקע: מכסה את כל העמוד (cover), נעולה ובתחתית. */
export function backgroundLayer(doc, assetId, natW, natH) {
    const { width: W, height: H } = doc.page;
    const k = Math.max(W / natW, H / natH);
    const w = natW * k, h = natH * k;
    return { id: uid(), type: 'image', role: 'background', name: 'רקע', asset: assetId, x: (W - w) / 2, y: (H - h) / 2, w, h, rotation: 0, opacity: 1, locked: true };
}

export function findLayer(doc, id) {
    return doc.layers.find(l => l.id === id) || null;
}

export function layerLabel(l) {
    if (l.name) return l.name;
    if (l.type === 'text') return (l.text || '').split('\n')[0].slice(0, 40) || 'טקסט';
    if (l.type === 'image') return l.role === 'background' ? 'רקע' : 'תמונה';
    if (l.type === 'icon') return 'אייקון';
    if (l.type === 'shape' && l.shape === 'element') return ELEMENTS[l.element]?.label || 'אלמנט';
    if (l.type === 'shape' && l.shape === 'path') return 'צורה';
    if (l.type === 'shape') return { rect: 'מלבן', ellipse: 'עיגול', line: 'קו', star: 'כוכב', polygon: 'מצולע', arrow: 'חץ' }[l.shape] || 'צורה';
    return 'שכבה';
}

// ─── קלט חיצוני (צ'אט, קובץ): בדיקה ותיקון ────────────────────────────────

const isColor = c => typeof c === 'string' && /^#([0-9a-f]{6}|[0-9a-f]{8})$/i.test(c);
const num = (v, d) => (typeof v === 'number' && Number.isFinite(v) ? v : d);
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

/** מדרג: ‏{type: linear|radial, angle, colors: [2–5 צבעים]}. כל דבר אחר — אין מדרג. */
function gradient(g) {
    if (!g || !Array.isArray(g.colors)) return null;
    const colors = g.colors.filter(isColor).slice(0, 5);
    if (colors.length < 2) return null;
    return { type: g.type === 'radial' ? 'radial' : 'linear', angle: ((Math.round(num(g.angle, 180)) % 360) + 360) % 360, colors };
}

/** מוצא את בלוק ה-JSON בתוך טקסט שהודבק מהצ'אט (גם עם ```json וגם בלי). */
export function extractJson(text) {
    const fenced = /```(?:json)?\s*([\s\S]*?)```/i.exec(text);
    const body = (fenced ? fenced[1] : text).trim();
    const start = body.indexOf('{'), end = body.lastIndexOf('}');
    if (start < 0 || end <= start) throw new Error('לא נמצא JSON בטקסט');
    return JSON.parse(body.slice(start, end + 1));
}

/**
 * מקבל design.json מכל מקור ומחזיר מסמך תקין + רשימת תיקונים בעברית.
 * לא זורק על שגיאה קטנה: מתקן כשאפשר (פונט לא קיים ← הקרוב, מיקום מחוץ לדף ← פנימה) ומדווח.
 */
export function normalizeDoc(input) {
    const fixes = [];
    if (!input || typeof input !== 'object') throw new Error('הקובץ אינו עיצוב');
    const pw = clamp(Math.round(num(input.page?.width, 1080)), 16, MAX_SIDE);
    const ph = clamp(Math.round(num(input.page?.height, 1350)), 16, MAX_SIDE);
    if (!input.page) fixes.push('לא צוין גודל — נקבע פוסט 1080×1350');
    const doc = newDoc(pw, ph, String(input.title || 'עיצוב מהצ\'אט').slice(0, 120));
    doc.source = { app: ['chat', 'ai', 'template', 'editor'].includes(input.source?.app) ? input.source.app : 'chat' };
    if (isColor(input.page?.background)) doc.page.background = input.page.background;
    const pg = gradient(input.page?.gradient);
    if (pg) doc.page.gradient = pg;

    for (const [id, a] of Object.entries(input.assets || {})) {
        if (!/^[A-Za-z0-9_-]{1,64}$/.test(id) || !a || a.type !== 'image') continue;
        doc.assets[id] = { type: 'image', file: String(a.file || '').slice(0, 100), src: a.src && String(a.src).startsWith('idb:') ? a.src : undefined };
    }

    // רשימת נמענים (מקובץ עיצוב שיוצא מכאן) — נשמרת כמו שהיא, רק טקסט.
    const m = input.merge;
    if (m && Array.isArray(m.columns) && Array.isArray(m.rows)) {
        const columns = m.columns.map(c => String(c).slice(0, 60)).slice(0, 50);
        doc.merge = {
            columns,
            rows: m.rows.slice(0, 5000).filter(r => r && typeof r === 'object').map(r => Object.fromEntries(columns.map(c => [c, String(r[c] ?? '').slice(0, 500)]))),
        };
        for (const k of ['nameColumn', 'phoneColumn', 'fileColumn']) doc.merge[k] = columns.includes(m[k]) ? m[k] : null;
    }

    const fontNames = FONTS.map(f => f.family);
    const seen = new Set();
    for (const raw of (Array.isArray(input.layers) ? input.layers : []).slice(0, MAX_LAYERS)) {
        if (!raw || typeof raw !== 'object') continue;
        let id = /^[A-Za-z0-9_-]{1,64}$/.test(raw.id) && !seen.has(raw.id) ? raw.id : uid();
        seen.add(id);
        const l = {
            id, type: raw.type,
            role: ROLES.includes(raw.role) ? raw.role : 'other',
            x: num(raw.x, 0), y: num(raw.y, 0),
            w: Math.max(1, num(raw.w, pw / 2)), h: Math.max(1, num(raw.h, ph / 4)),
            rotation: num(raw.rotation, 0), opacity: clamp(num(raw.opacity, 1), 0, 1),
        };
        if (raw.name) l.name = String(raw.name).slice(0, 80);
        if (raw.locked) l.locked = true;
        if (raw.hidden) l.hidden = true;
        if (raw.flipX) l.flipX = true;
        if (raw.flipY) l.flipY = true;
        if (raw.shadow && isColor(raw.shadow.color)) l.shadow = { x: num(raw.shadow.x, 0), y: num(raw.shadow.y, 4), blur: Math.max(0, num(raw.shadow.blur, 8)), color: raw.shadow.color };

        if (raw.type === 'text') {
            const s = raw.style || {};
            let font = s.font;
            if (!fontNames.includes(font)) {
                const near = fontNames.find(f => f.toLowerCase() === String(font || '').toLowerCase());
                if (font) fixes.push(`הפונט "${String(font).slice(0, 40)}" הוחלף ב-${near || DEFAULT_FONT}`);
                font = near || DEFAULT_FONT;
            }
            l.text = String(raw.text ?? '').slice(0, 5000);
            l.style = {
                font, size: clamp(num(s.size, 48), 1, 2000), weight: clamp(Math.round(num(s.weight, 400) / 100) * 100, 100, 900),
                color: isColor(s.color) ? s.color : '#000000',
                align: ['right', 'center', 'left', 'justify'].includes(s.align) ? s.align : 'center',
                dir: ['rtl', 'ltr', 'auto'].includes(s.dir) ? s.dir : 'rtl',
                lineHeight: clamp(num(s.lineHeight, 1.2), 0.6, 4),
            };
            if (s.italic) l.style.italic = true;
            if (s.stroke && isColor(s.stroke.color)) l.style.stroke = { width: clamp(num(s.stroke.width, 2), 0, 100), color: s.stroke.color };
            if (s.shadow && isColor(s.shadow.color)) l.style.shadow = { x: num(s.shadow.x, 0), y: num(s.shadow.y, 4), blur: Math.max(0, num(s.shadow.blur, 8)), color: s.shadow.color };
            // מילוי האות במעבר צבעים, והילה (זוהר סביב האותיות) — `color` נשאר צבע הגיבוי והצבע הראשון
            const tg = gradient(s.gradient);
            if (tg) l.style.gradient = tg;
            if (s.glow && isColor(s.glow.color)) l.style.glow = { color: s.glow.color, blur: clamp(num(s.glow.blur, 20), 1, 500), strength: clamp(Math.round(num(s.glow.strength, 1)), 1, 4) };
            if (s.background && isColor(s.background.color)) {
                const b = s.background;
                l.style.background = { color: b.color, radius: Math.max(0, num(b.radius, 0)), padding: Math.max(0, num(b.padding, 16)) };
                if (num(b.opacity, 1) < 1) l.style.background.opacity = clamp(num(b.opacity, 1), 0, 1);
                if (b.border && isColor(b.border.color) && num(b.border.width, 0) > 0) l.style.background.border = { color: b.border.color, width: clamp(num(b.border.width, 2), 0, 200) };
            }
        } else if (raw.type === 'image') {
            if (!doc.assets[raw.asset]) { fixes.push(`שכבה "${id}" מפנה לתמונה שלא קיימת — דולגה`); continue; }
            l.asset = raw.asset;
            if (raw.filters && typeof raw.filters === 'object') {
                const f = raw.filters;
                l.filters = { brightness: clamp(num(f.brightness, 0), -1, 1), contrast: clamp(num(f.contrast, 0), -100, 100), saturation: clamp(num(f.saturation, 0), -2, 10), blur: clamp(num(f.blur, 0), 0, 40) };
            }
            if (['circle', 'rounded'].includes(raw.mask)) l.mask = raw.mask;
            const c = raw.crop;
            if (c && [c.x, c.y, c.w, c.h].every(v => typeof v === 'number' && v >= 0 && v <= 1) && c.w > 0 && c.h > 0 && c.x + c.w <= 1.0001 && c.y + c.h <= 1.0001) {
                l.crop = { x: c.x, y: c.y, w: c.w, h: c.h };
            }
        } else if (raw.type === 'icon') {
            l.icon = /^[a-z-]{1,40}$/.test(raw.icon) ? raw.icon : 'star';
            l.color = isColor(raw.color) ? raw.color : '#2d4a8a';
            l.strokeWidth = clamp(num(raw.strokeWidth, 2), 0.5, 4);
            if (raw.filled) l.filled = true;
        } else if (raw.type === 'shape') {
            l.shape = ['rect', 'ellipse', 'line', 'star', 'polygon', 'arrow', 'element', 'path'].includes(raw.shape) ? raw.shape : 'rect';
            // נתיב חופשי (מקובץ ‎.mbe של אפליקציה אחרת): רק פקודות ומספרים, ו-vb — הגודל שבו הנתיב צויר
            if (l.shape === 'path') {
                const okPath = typeof raw.path === 'string' && raw.path.length < 200000 && /^[MLCQZ0-9eE.,+\s-]*$/.test(raw.path);
                const okVb = Array.isArray(raw.vb) && raw.vb.length === 2 && raw.vb.every(v => typeof v === 'number' && v > 0);
                if (okPath && okVb) { l.path = raw.path; l.vb = raw.vb; } else { fixes.push('נתיב לא תקין — הוחלף במלבן'); l.shape = 'rect'; }
            }
            if (l.shape === 'element') {
                if (ELEMENTS[raw.element]) l.element = raw.element;
                else { fixes.push(`אלמנט לא מוכר (${String(raw.element).slice(0, 30)}) — הוחלף במלבן`); l.shape = 'rect'; }
            }
            const sg = gradient(raw.gradient);
            if (sg) l.gradient = sg;
            l.fill = isColor(raw.fill) ? raw.fill : raw.fill === 'none' ? '#00000000' : '#2d4a8a';
            l.radius = Math.max(0, num(raw.radius, 0));
            l.points = clamp(Math.round(num(raw.points, 5)), 3, 24);
            if (raw.stroke && isColor(raw.stroke.color)) l.stroke = { width: clamp(num(raw.stroke.width, 2), 0, 200), color: raw.stroke.color };
        } else {
            fixes.push(`סוג שכבה לא נתמך (${String(raw.type).slice(0, 20)}) — דולג`);
            continue;
        }

        // מיקום שיצא לגמרי מהדף — מוזז פנימה.
        if (l.x > pw || l.y > ph || l.x + l.w < 0 || l.y + l.h < 0) {
            l.x = clamp(l.x, 0, Math.max(0, pw - l.w));
            l.y = clamp(l.y, 0, Math.max(0, ph - l.h));
            fixes.push(`"${layerLabel(l)}" היה מחוץ לדף — הוזז פנימה`);
        }
        doc.layers.push(l);
    }
    return { doc, fixes };
}

/** עותק עמוק — המסמך תמיד JSON טהור. */
export function clone(doc) {
    return JSON.parse(JSON.stringify(doc));
}
