// בורר צבע משלנו — במקום הבורר של אנדרואיד (שלושה סרגלים, בלי קוד ובלי זיכרון).
// נפתח בתוך הגיליון, מתחת לשדה, והשינוי נראה חי על הקנבס: ריבוע בהירות/רוויה, פס גוון, שקיפות (כשיש), קוד צבע
// להקלדה ולהעתקה, ושורות של צבעים: אחרונים, מועדפים, צבעי העיצוב ומוכנים. דגימה מהעיצוב — דרך ההקשר (main.js).

import { h } from './ui.js';
import { log } from './log.js';

const RECENT_KEY = 'gc-colors-recent', FAV_KEY = 'gc-colors-fav', RECENT_MAX = 12, FAV_MAX = 24;

/** מה שהבורר מקבל מהעורך: צבעי העיצוב, צבעים מוכנים, ודגימה מהקנבס. */
let ctx = { docColors: () => [], presets: [], sample: null };
export function setColorContext(c) { ctx = { ...ctx, ...c }; }

// ─── המרות ────────────────────────────────────────────────────────────────

const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
export function parseHex(v) {
    let s = String(v || '').trim().replace(/^#?/, '#').toLowerCase();
    if (/^#[0-9a-f]{3}$/.test(s)) s = '#' + [...s.slice(1)].map(c => c + c).join('');
    if (!/^#[0-9a-f]{6}([0-9a-f]{2})?$/.test(s)) return null;
    const n = parseInt(s.slice(1, 7), 16);
    return { r: n >> 16, g: (n >> 8) & 255, b: n & 255, a: s.length === 9 ? parseInt(s.slice(7), 16) / 255 : 1 };
}
const hex2 = n => Math.round(clamp(n, 0, 255)).toString(16).padStart(2, '0');
export const toHex = ({ r, g, b, a = 1 }, withAlpha = false) => '#' + hex2(r) + hex2(g) + hex2(b) + (withAlpha && a < 0.999 ? hex2(a * 255) : '');

function rgbToHsv({ r, g, b }) {
    r /= 255; g /= 255; b /= 255;
    const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
    let hh = 0;
    if (d) hh = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
    return { h: (hh * 60 + 360) % 360, s: mx ? d / mx : 0, v: mx };
}
function hsvToRgb({ h: hh, s, v }) {
    const c = v * s, x = c * (1 - Math.abs(((hh / 60) % 2) - 1)), m = v - c;
    const [r, g, b] = hh < 60 ? [c, x, 0] : hh < 120 ? [x, c, 0] : hh < 180 ? [0, c, x] : hh < 240 ? [0, x, c] : hh < 300 ? [x, 0, c] : [c, 0, x];
    return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
}

// ─── זיכרון במכשיר ────────────────────────────────────────────────────────

const load = k => { try { return JSON.parse(localStorage.getItem(k)) || []; } catch { return []; } };
const store = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* אין אחסון — לשיחה הזו */ } };
function remember(color) {
    const c = color.slice(0, 7);
    store(RECENT_KEY, [c, ...load(RECENT_KEY).filter(x => x !== c)].slice(0, RECENT_MAX));
}

// ─── הבורר ────────────────────────────────────────────────────────────────

let openOne = null;   // בורר אחד פתוח בכל רגע

/**
 * שדה צבע: כפתור עם הצבע והקוד, ומתחתיו הבורר (נפתח בלחיצה).
 * @param {string} value '#rrggbb' (או '#rrggbbaa' כש-alpha)
 * @param {{alpha?: boolean}} opts alpha — פס שקיפות, והערך כולל אותה
 */
export function colorPicker(label, value, onInput, onCommit, { alpha = false } = {}) {
    let c = parseHex(value) || { r: 0, g: 0, b: 0, a: 1 };
    let hsv = rgbToHsv(c);
    const id = 'cp' + Math.random().toString(36).slice(2, 8);
    const chip = h('span', { class: 'cp-chip' });
    const code = h('span', { class: 'cp-code', dir: 'ltr' });
    const trigger = h('button', { type: 'button', class: 'cp-trigger', id, 'aria-expanded': 'false', 'aria-controls': id + '-p' }, chip, code);
    const panel = h('div', { class: 'cp-panel', id: id + '-p', hidden: true });
    const out = () => toHex(c, alpha);
    const paintTrigger = () => {
        chip.style.background = out();
        code.textContent = out().toUpperCase();
        trigger.setAttribute('aria-label', `${label}: ${out()}`);
    };
    paintTrigger();

    let raf = 0;
    const emit = () => {
        paintTrigger();
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(() => onInput(out()));
    };
    const commit = () => { cancelAnimationFrame(raf); onInput(out()); remember(out()); onCommit?.(); refreshRows(); };
    const setRgb = (rgb, keepHue = false) => {
        c = { ...rgb, a: rgb.a ?? c.a };
        const n = rgbToHsv(c);
        hsv = keepHue && n.s === 0 ? { ...n, h: hsv.h } : n;
        sync();
    };

    // ריבוע בהירות/רוויה, פס גוון, פס שקיפות
    const sv = h('div', { class: 'cp-sv', role: 'slider', tabindex: 0, 'aria-label': 'רוויה ובהירות' }, h('span', { class: 'cp-thumb' }));
    const hue = h('div', { class: 'cp-hue', role: 'slider', tabindex: 0, 'aria-label': 'גוון', 'aria-valuemin': 0, 'aria-valuemax': 360 }, h('span', { class: 'cp-thumb' }));
    const al = alpha ? h('div', { class: 'cp-alpha', role: 'slider', tabindex: 0, 'aria-label': 'שקיפות', 'aria-valuemin': 0, 'aria-valuemax': 100 }, h('span', { class: 'cp-fill' }), h('span', { class: 'cp-thumb' })) : null;
    const loupe = h('span', { class: 'cp-loupe', hidden: true });
    sv.append(loupe);
    const hexIn = h('input', { type: 'text', class: 'cp-hex', dir: 'ltr', inputMode: 'text', autocomplete: 'off', spellcheck: false, maxLength: 9, 'aria-label': 'קוד צבע' });
    const rgbIns = ['R', 'G', 'B'].map(k => h('input', { type: 'number', class: 'cp-num', min: 0, max: 255, inputMode: 'numeric', 'aria-label': k }));
    const favBtn = h('button', { type: 'button', class: 'icon-btn cp-fav', onclick: () => {
        const cur = out().slice(0, 7), favs = load(FAV_KEY);
        store(FAV_KEY, favs.includes(cur) ? favs.filter(x => x !== cur) : [cur, ...favs].slice(0, FAV_MAX));
        refreshRows();
    } });
    const copyBtn = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'העתקת קוד הצבע', title: 'העתקה', onclick: async () => {
        try { await navigator.clipboard.writeText(out()); copyBtn.textContent = '✓'; setTimeout(() => { copyBtn.textContent = '⧉'; }, 1200); } catch { hexIn.select(); }
    } }, '⧉');
    const sampleBtn = ctx.sample ? h('button', { type: 'button', class: 'btn ghost sm', onclick: async () => {
        const got = await ctx.sample();
        if (!got) return;
        setRgb(parseHex(got));
        commit();
    } }, '💧 דגימה מהעיצוב') : null;

    const sync = () => {
        sv.style.setProperty('--hue', `hsl(${hsv.h} 100% 50%)`);
        sv.querySelector('.cp-thumb').style.cssText = `left:${hsv.s * 100}%;top:${(1 - hsv.v) * 100}%;background:${toHex(c)}`;
        hue.querySelector('.cp-thumb').style.left = `${(hsv.h / 360) * 100}%`;
        hue.setAttribute('aria-valuenow', Math.round(hsv.h));
        sv.setAttribute('aria-valuetext', `רוויה ${Math.round(hsv.s * 100)}%, בהירות ${Math.round(hsv.v * 100)}%`);
        if (al) {
            al.querySelector('.cp-fill').style.background = `linear-gradient(to right, transparent, ${toHex(c)})`;
            al.querySelector('.cp-thumb').style.left = `${c.a * 100}%`;
            al.setAttribute('aria-valuenow', Math.round(c.a * 100));
        }
        if (document.activeElement !== hexIn) hexIn.value = out().toUpperCase();
        rgbIns.forEach((inp, i) => { if (document.activeElement !== inp) inp.value = Math.round([c.r, c.g, c.b][i]); });
        const isFav = load(FAV_KEY).includes(out().slice(0, 7));
        favBtn.textContent = isFav ? '★' : '☆';
        favBtn.setAttribute('aria-label', isFav ? 'הסרה מהמועדפים' : 'הוספה למועדפים');
        favBtn.setAttribute('aria-pressed', String(isFav));
        emit();
    };

    // גרירה: האצבע ממשיכה לשלוט גם כשהיא יוצאת מהמשטח (pointer capture); השחרור — צעד אחד בהיסטוריה
    const drag = (el, fn, { withLoupe = false } = {}) => {
        const at = e => { const r = el.getBoundingClientRect(); return [clamp((e.clientX - r.left) / r.width, 0, 1), clamp((e.clientY - r.top) / r.height, 0, 1)]; };
        el.addEventListener('pointerdown', e => {
            e.preventDefault();
            el.setPointerCapture(e.pointerId);
            fn(...at(e));
            if (withLoupe) loupe.hidden = false;
            const move = ev => { fn(...at(ev)); if (withLoupe) loupe.style.background = toHex(c); };
            const up = () => { el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', up); loupe.hidden = true; commit(); };
            el.addEventListener('pointermove', move);
            el.addEventListener('pointerup', up);
            el.addEventListener('pointercancel', up);
        });
    };
    drag(sv, (x, y) => { hsv.s = x; hsv.v = 1 - y; c = { ...hsvToRgb(hsv), a: c.a }; sync(); loupe.style.left = `${x * 100}%`; loupe.style.top = `${y * 100}%`; }, { withLoupe: true });
    // בממשק מימין לשמאל הפסים עדיין משמאל לימין (כמו בכל עורך צבע) — לכן dir=ltr ב-CSS
    drag(hue, x => { hsv.h = x * 360; c = { ...hsvToRgb(hsv), a: c.a }; sync(); });
    if (al) drag(al, x => { c.a = Math.round(x * 100) / 100; sync(); });

    // מקלדת: חיצים — צעד קטן (דיוק), Shift — צעד גדול
    const keys = (el, fn) => el.addEventListener('keydown', e => {
        const step = e.shiftKey ? 0.1 : 0.01;
        const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
        if (!d) return;
        e.preventDefault();
        fn(...d);
        commit();
    });
    keys(sv, (dx, dy) => { hsv.s = clamp(hsv.s + dx, 0, 1); hsv.v = clamp(hsv.v - dy, 0, 1); c = { ...hsvToRgb(hsv), a: c.a }; sync(); });
    keys(hue, dx => { hsv.h = clamp(hsv.h + dx * 360, 0, 360); c = { ...hsvToRgb(hsv), a: c.a }; sync(); });
    if (al) keys(al, dx => { c.a = clamp(Math.round((c.a + dx) * 100) / 100, 0, 1); sync(); });

    hexIn.addEventListener('input', () => { const p = parseHex(hexIn.value); if (p) setRgb(alpha ? p : { ...p, a: c.a }, true); });
    hexIn.addEventListener('change', () => { hexIn.value = out().toUpperCase(); commit(); });
    hexIn.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); hexIn.blur(); } });
    rgbIns.forEach((inp, i) => {
        inp.addEventListener('input', () => {
            const v = clamp(Math.round(+inp.value || 0), 0, 255);
            setRgb({ r: i === 0 ? v : c.r, g: i === 1 ? v : c.g, b: i === 2 ? v : c.b }, true);
        });
        inp.addEventListener('change', commit);
    });

    // שורות הצבעים
    const rows = h('div', { class: 'cp-rows' });
    const row = (title, list, hint) => (list.length ? h('div', { class: 'cp-row' }, h('div', { class: 'cp-row-title' }, title),
        h('div', { class: 'cp-swatches' }, list.map(col => h('button', { type: 'button', class: 'cp-sw' + (col.slice(0, 7) === out().slice(0, 7) ? ' on' : ''),
            style: { background: col }, 'aria-label': col, title: col,
            onclick: () => { setRgb({ ...parseHex(col), a: c.a }); commit(); } })))) : hint ? h('p', { class: 'hint cp-empty' }, hint) : null);
    const refreshRows = () => {
        const docCols = [...new Set((ctx.docColors() || []).map(x => String(x).slice(0, 7).toLowerCase()).filter(x => parseHex(x)))].slice(0, 16);
        rows.replaceChildren(...[
            row('🕘 אחרונים', load(RECENT_KEY)),
            row('⭐ מועדפים', load(FAV_KEY), '⭐ — לחיצה על הכוכב שומרת את הצבע כאן'),
            row('🎨 בעיצוב', docCols),
            row('צבעים מוכנים', ctx.presets || []),
        ].filter(Boolean));
        sync();
    };

    panel.append(sv, hue, al || '', h('div', { class: 'cp-codes' }, hexIn, copyBtn, favBtn),
        h('div', { class: 'cp-rgb' }, rgbIns.map((inp, i) => h('label', {}, h('span', {}, 'RGB'[i]), inp))), sampleBtn || '', rows);

    const setOpen = open => {
        if (open && openOne && openOne !== setOpen) openOne(false);
        panel.hidden = !open;
        trigger.setAttribute('aria-expanded', String(open));
        trigger.classList.toggle('open', open);
        openOne = open ? setOpen : openOne === setOpen ? null : openOne;
        if (open) { refreshRows(); log('color-open', { label: String(label).slice(0, 20) }); }
    };
    trigger.addEventListener('click', () => setOpen(panel.hidden));
    return h('div', { class: 'field cp-field' }, h('label', { for: id }, label), trigger, panel);
}
