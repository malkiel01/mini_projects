/**
 * כלי ממשק קטנים — בלי מצב של עורך: בניית אלמנטים (h), הודעה (toast), גיליון / חלון (sheet), שדות, ותאריך.
 * הוצאו מ-editor.js (F20 — הפרדת מודולים); editor.js מייצא את h / toast / sheet / field / seg / when גם הלאה.
 * h / toast / sheet — מתאם דק ל-ui-core (assets/js/ui-core, עותק של creative-suite/ui-core/src/js — לא עורכים שם).
 */
import { FONTS, cssFont } from './extensions.js';
import { h } from './ui-core/dom.js';
import { sheet as uiSheet, initDialogs } from './ui-core/dialog.js';
import { toast as uiToast } from './ui-core/toast.js';
import { colorPicker, setColorContext } from './ui-core/color.js';

// h, הגיליון וההודעה — מ-ui-core (creative-suite/ui-core; הם נולדו כאן). כאן רק מתאם לחתימות הישנות, כך שכל הקוראים
// לא משתנים. initDialogs: [data-close], סגירה בלחיצה ברקע, --vvh / --kb
export { h, initDialogs, setColorContext };

/** הודעה קצרה בתחתית המסך. action: {label, run} — כפתור בהודעה (למשל "ביטול"); ההודעה נשארת יותר זמן. */
export const toast = (msg, err = false, action = null) => uiToast(msg, { kind: err ? 'error' : '', action });

/** גיליון עריכה (dialog). fill(body, dlg) ממלא; onSave(form, dlg) → false נשאר פתוח, זריקה — הודעה בחלון. מחזיר את ה-<dialog>. */
export const sheet = (title, fill, onSave, saveLabel = 'אישור') =>
    uiSheet({ title, body: fill, onSubmit: onSave || null, submit: onSave ? saveLabel : null }).el;

export function field(label, input, cls = '') {
    return h('label.f' + (cls ? '.' + cls : ''), h('span', label), input);
}

/**
 * שדה צבע — הבורר של ui-core (color.js, integration.md שורה 13): גוון, רוויה, קוד, אחרונים, מועדפים, צבעי המסמך.
 * קלט נסתר בשם name נושא את הערך, כך ש-FormData של החלון קורא אותו כמו קודם.
 */
export function colorField(label, name, value) {
    const hidden = h('input', { type: 'hidden', name, value });
    const el = colorPicker(label, value, hex => { hidden.value = hex; }, null);
    el.append(hidden);
    return el;
}

/** חלון צבע לכפתור בסרגל: הבורר פתוח, "החלה" — onPick(hex). */
export function colorSheet(title, value, onPick) {
    let cur = value;
    return sheet(title, body => {
        const el = colorPicker(title, value, hex => { cur = hex; }, null);
        body.append(el);
        el.querySelector('.cp-trigger').click();
    }, () => { onPick(cur); }, 'החלה');
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
