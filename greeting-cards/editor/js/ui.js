// רכיבי ממשק משותפים: יצירת אלמנטים בלי innerHTML (אין הזרקת קוד מנתוני משתמש), גיליון תחתון
// נגיש, הודעות, ואישור.

import { log } from './log.js';
import { colorPicker } from './colorpicker.js';

/**
 * h('button', {class:'btn', onclick}, 'טקסט', child...) — יוצר אלמנט. מחרוזות נכנסות כ-textContent.
 * מאפיין שמתחיל ב-on הוא מאזין; aria-* ו-data-* — מאפיינים; השאר — property.
 */
export function h(tag, props, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(props || {})) {
        if (v === undefined || v === null || v === false) continue;
        if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
        else if (k === 'class') el.className = v;
        else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
        else if (k.startsWith('aria-') || k.startsWith('data-') || k === 'role' || k === 'for' || k === 'tabindex') el.setAttribute(k, v === true ? '' : v);
        else el[k] = v;
    }
    for (const c of children.flat()) {
        if (c === null || c === undefined || c === false) continue;
        el.append(c instanceof Node ? c : String(c));
    }
    return el;
}

export const $ = (sel, root = document) => root.querySelector(sel);

// ─── הודעות ────────────────────────────────────────────────────────────────

let toastTimer;
export function toast(msg, kind = '') {
    const el = $('#toast');
    el.textContent = msg;
    el.className = 'toast show ' + kind;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = 'toast'; }, kind === 'error' ? 6000 : 3000);
}

// ─── גיליון תחתון ──────────────────────────────────────────────────────────
// בטלפון — גיליון מלמטה, והקנבס גלוי מעליו. במחשב — חלון צד. לכידת פוקוס, Esc סוגר, והפוקוס חוזר
// לכפתור שפתח אותו.

let openSheet = null;

export function sheet(title, body, { actions, onClose, wide } = {}) {
    log('sheet', { title: String(title).slice(0, 30) });
    closeSheet();
    const back = document.activeElement;
    const titleId = 'sheet-t-' + Date.now();
    const closeBtn = h('button', { class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => closeSheet() }, '✕');
    const panel = h('div', { class: 'sheet' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId },
        h('div', { class: 'sheet-head' }, h('h2', { id: titleId }, title), actions || null, closeBtn),
        h('div', { class: 'sheet-body' }, body));
    const scrim = h('div', { class: 'scrim', onclick: () => closeSheet() });
    const root = h('div', { class: 'sheet-root' }, scrim, panel);
    root.addEventListener('keydown', e => {
        if (e.key === 'Escape') { e.stopPropagation(); closeSheet(); }
        if (e.key === 'Tab') {
            const f = [...panel.querySelectorAll('button,input,select,textarea,a[href],[tabindex]:not([tabindex="-1"])')].filter(x => !x.disabled && x.offsetParent);
            if (!f.length) return;
            const first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });
    document.body.append(root);
    openSheet = { root, back, onClose };
    requestAnimationFrame(() => {
        root.classList.add('open');
        (panel.querySelector('[autofocus]') || closeBtn).focus({ preventScroll: true });
    });
    return panel;
}

export function closeSheet() {
    if (!openSheet) return;
    const { root, back, onClose } = openSheet;
    openSheet = null;
    root.remove();
    onClose?.();
    if (back && document.contains(back)) back.focus({ preventScroll: true });
}

export function isSheetOpen() { return !!openSheet; }

// גם כשהפוקוס נפל ל-body (למשל אחרי שרשימה בגיליון צוירה מחדש) — Esc סוגר.
document.addEventListener('keydown', e => { if (e.key === 'Escape' && openSheet) closeSheet(); });

/** אישור לפעולה מסוכנת. מחזיר הבטחה — כל חלון חדש מבטל את הקודם ומחזיר בו false. */
let pendingConfirm = null;
export function confirmDialog(msg, okLabel = 'אישור', danger = false) {
    pendingConfirm?.(false);
    return new Promise(resolve => {
        pendingConfirm = resolve;
        const done = v => { if (pendingConfirm === resolve) pendingConfirm = null; resolve(v); closeSheet(); };
        sheet('אישור', h('div', {},
            h('p', { class: 'lead' }, msg),
            h('div', { class: 'row-actions' },
                h('button', { class: 'btn ' + (danger ? 'danger' : 'primary'), onclick: () => done(true), autofocus: true }, okLabel),
                h('button', { class: 'btn ghost', onclick: () => done(false) }, 'ביטול'))),
        { onClose: () => { if (pendingConfirm === resolve) { pendingConfirm = null; resolve(false); } } });
    });
}

// ─── שדות ──────────────────────────────────────────────────────────────────

let fieldSeq = 0;

/** שדה עם תווית. */
export function field(label, input, hint) {
    const id = input.id || (input.id = 'f' + (++fieldSeq));
    return h('div', { class: 'field' }, h('label', { for: id }, label), input, hint ? h('div', { class: 'hint' }, hint) : null);
}

/** סרגל טווח + שדה מספר, מסונכרנים. */
/**
 * מחוון עם שדה מספר. ‏log — המחוון לוגריתמי ויש −/+ (צעד של ~5%): בטווח רחב (גודל טקסט 6–1169 בדף A4) מחוון
 * לינארי זז בקפיצות של פיקסלים רבים לכל פיקסל אצבע, וגודל קטן כמעט בלתי אפשרי לכוון (D7 ב-spec/11-qa.md).
 */
export function rangeField(label, { min, max, step = 1, value, onInput, onCommit, unit = '', log = false }) {
    const id = 'f' + (++fieldSeq);
    const STEPS = 1000;
    const toPos = v => (log ? Math.round((Math.log(v / min) / Math.log(max / min)) * STEPS) : v);
    const fromPos = p => (log ? Math.round(min * Math.pow(max / min, p / STEPS) * 10) / 10 : +p);
    const range = h('input', { type: 'range', id, min: log ? 0 : min, max: log ? STEPS : max, step: log ? 1 : step, value: toPos(value) });
    const numIn = h('input', { type: 'number', min, max, step, value: Math.round(value * 100) / 100, class: 'num', 'aria-label': label });
    const set = (v, fromRange) => {
        v = Math.min(max, Math.max(min, +v));
        if (!fromRange) range.value = toPos(v);
        numIn.value = Math.round(v * 100) / 100;
        onInput(v);
    };
    range.addEventListener('input', () => set(fromPos(range.value), true));
    numIn.addEventListener('input', () => { if (numIn.value !== '') set(numIn.value); });
    range.addEventListener('change', () => onCommit?.());
    numIn.addEventListener('change', () => onCommit?.());
    const nudge = dir => { const v = +numIn.value; set(dir > 0 ? Math.max(v + 1, Math.round(v * 1.05)) : Math.min(v - 1, Math.round(v / 1.05))); onCommit?.(); };
    const steppers = log ? [
        h('button', { type: 'button', class: 'icon-btn step', 'aria-label': `הקטנת ${label}`, onclick: () => nudge(-1) }, '−'),
        h('button', { type: 'button', class: 'icon-btn step', 'aria-label': `הגדלת ${label}`, onclick: () => nudge(1) }, '+'),
    ] : [];
    return h('div', { class: 'field' }, h('label', { for: id }, label, unit ? h('span', { class: 'muted' }, ' ' + unit) : null), h('div', { class: 'range-row' }, range, ...steppers, numIn));
}

/** בורר מקטעים בשורה אחת (radio נגיש). */
export function seg(label, options, value, onChange) {
    const name = 's' + (++fieldSeq);
    return h('fieldset', { class: 'seg-field' },
        h('legend', {}, label),
        h('div', { class: 'seg' }, options.map(o =>
            h('label', { class: 'seg-opt', title: o.title || o.label },
                h('input', { type: 'radio', name, value: o.value, checked: o.value === value, onchange: () => onChange(o.value) }),
                h('span', { 'aria-hidden': o.icon ? 'true' : undefined }, o.icon || o.label),
                o.icon ? h('span', { class: 'sr' }, o.label) : null))));
}

/** שדה צבע — הבורר שלנו (colorpicker.js), לא של המערכת: מדויק, עם קוד, אחרונים ומועדפים. */
export function colorField(label, value, onInput, onCommit, opts) {
    return colorPicker(label, value, onInput, onCommit, opts);
}

export function toggle(label, checked, onChange) {
    const input = h('input', { type: 'checkbox', checked, onchange: () => onChange(input.checked) });
    return h('label', { class: 'toggle' }, input, h('span', {}, label));
}

/** בחירת קובץ בלחיצה. */
export function pickFiles(accept, multiple = false) {
    return new Promise(resolve => {
        const input = h('input', { type: 'file', accept, multiple, style: { display: 'none' } });
        input.addEventListener('change', () => { resolve([...input.files]); input.remove(); });
        document.body.append(input);
        input.click();
    });
}

export async function download(blob, name) {
    // באפליקציית האנדרואיד (WebView) קישור blob: לא יורד — הקובץ עובר דרך הגשר לתיקיית ההורדות.
    const bridge = window.GreetingCardsAndroid;
    if (bridge?.saveFile) {
        const b64 = await new Promise((res, rej) => {
            const r = new FileReader();
            r.onload = () => res(String(r.result).split(',')[1] || '');
            r.onerror = () => rej(r.error);
            r.readAsDataURL(blob);
        });
        const err = bridge.saveFile(b64, name, blob.type || 'application/octet-stream');
        if (err) throw new Error(err);
        toast(`נשמר בהורדות: ${name}`);
        return;
    }
    const a = h('a', { href: URL.createObjectURL(blob), download: name });
    document.body.append(a);
    a.click();
    a.remove();
    // שחרור מאוחר: דפדפנים מסוימים מתחילים את ההורדה רק אחרי שהלחיצה מסתיימת.
    setTimeout(() => URL.revokeObjectURL(a.href), 60000);
}

export function timeAgo(t) {
    const s = (Date.now() - t) / 1000;
    if (s < 60) return 'עכשיו';
    if (s < 3600) return `לפני ${Math.round(s / 60)} דק'`;
    if (s < 86400) return `לפני ${Math.round(s / 3600)} שע'`;
    return new Date(t).toLocaleDateString('he-IL');
}
