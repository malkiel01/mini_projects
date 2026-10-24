/**
 * כלי ממשק קטנים — בלי מצב של עורך: בניית אלמנטים (h), הודעה (toast), גיליון / חלון (sheet), שדות, ותאריך.
 * הוצאו מ-editor.js (F20 — הפרדת מודולים); editor.js מייצא את h / toast / sheet / field / seg / when גם הלאה.
 */
import { FONTS, cssFont } from './extensions.js';

/** h('button.btn.primary', {onclick}, 'טקסט', child…) */
export function h(sel, attrs, ...kids) {
    const [tag, ...cls] = sel.split('.');
    const el = document.createElement(tag || 'div');
    if (cls.length) el.className = cls.join(' ');
    if (attrs && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) { kids.unshift(attrs); attrs = null; }
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
        else if (k in el && !k.startsWith('aria') && !k.startsWith('data')) el[k] = v;
        else el.setAttribute(k, v === true ? '' : v);
    }
    for (const k of kids.flat()) if (k !== null && k !== undefined && k !== false) el.append(k instanceof Node ? k : String(k));
    return el;
}

/** הודעה קצרה בתחתית המסך. action: {label, run} — כפתור בהודעה (למשל "ביטול"); ההודעה נשארת יותר זמן. */
export function toast(msg, err = false, action = null) {
    const el = document.querySelector('.toast');
    if (!el) return;
    el.textContent = msg;
    if (action) el.append(h('button.toast-act', { type: 'button', onclick: () => { el.hidden = true; action.run(); } }, action.label));
    el.classList.toggle('err', err);
    el.classList.toggle('has-act', !!action);
    el.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.hidden = true; }, action ? 8000 : 2800);
}

/** גיליון עריכה (dialog) — חלון במחשב, מסך מלא בטלפון. fields(body) ממלא; onSave(form) → true לסגירה. */
export function sheet(title, fill, onSave, saveLabel = 'אישור') {
    const body = h('div.sheet-body');
    const err = h('p.error', { role: 'alert', hidden: true });
    const dlg = h('dialog', { 'aria-label': title },
        h('form', { method: 'dialog', novalidate: true },
            h('header.sheet-head',
                h('button.icon-btn', { type: 'button', 'aria-label': 'סגירה', onclick: () => dlg.close() }, '✕'),
                h('h2', title),
                onSave ? h('button.btn.primary', { type: 'submit' }, saveLabel) : null),
            body));
    fill(body, dlg);
    body.append(err);
    dlg.querySelector('form').addEventListener('submit', async e => {
        e.preventDefault();
        if (!onSave) return dlg.close();
        err.hidden = true;
        try {
            if ((await onSave(dlg.querySelector('form'), dlg)) !== false) dlg.close();
        } catch (ex) {
            err.textContent = ex.message || String(ex);
            err.hidden = false;
        }
    });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
    return dlg;
}

export function field(label, input, cls = '') {
    return h('label.f' + (cls ? '.' + cls : ''), h('span', label), input);
}

export function seg(name, options, value, label) {
    return h('fieldset.seg', { 'aria-label': label },
        Object.entries(options).map(([v, t]) => h('label', h('input', { type: 'radio', name, value: v, checked: String(value) === v }), h('span', t))));
}

export function sw(name, label, checked) {
    return h('label.switch', h('input', { type: 'checkbox', name, checked: !!checked }), h('span', label));
}

export function numInput(name, value, attrs = {}) {
    return h('input', { name, value: value ?? '', inputmode: 'decimal', dir: 'ltr', type: 'number', step: 'any', ...attrs });
}

export function fontSelect(name, value, allowInherit = false) {
    return h('select', { name },
        allowInherit ? h('option', { value: '' }, '— כמו בגופן המסמך —') : null,
        FONTS.map(f => h('option', { value: f, selected: f === value, style: { fontFamily: cssFont(f) } }, f)));
}

export function when(t) {
    if (!t) return '';
    const d = new Date(t * 1000);
    const today = new Date();
    const time = d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === today.toDateString()) return `היום ${time}`;
    return d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: d.getFullYear() === today.getFullYear() ? undefined : '2-digit' }) + ' ' + time;
}
