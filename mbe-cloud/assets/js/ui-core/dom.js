/**
 * ui-core/dom.js — בניית אלמנטים בלי innerHTML (טקסט של משתמש לעולם לא הופך לקוד), ושאילתות.
 * מקור: h של עורך התמלילים (בורר 'tag.cls') ושל סטודיו העיצוב (מאפיינים ומאזינים) — מאוחדים.
 * לא לערוך עותק בתוך אפליקציה — המקור: creative-suite/ui-core/src/js/.
 */

/**
 * h('button.btn.primary', { onclick, 'aria-label': 'שמירה' }, 'שמירה', child…) → Element
 *
 * - הבורר: 'tag', 'tag.cls.cls', 'tag#id.cls'. בלי tag — div.
 * - on<event> עם פונקציה → מאזין. class / className → className. style: אובייקט או מחרוזת.
 * - aria-*, data-*, role, for, tabindex, ושם שיש בו מקף → setAttribute. true → '' ; false / null / undefined → מדלגים.
 * - השאר → property (value, checked, disabled, hidden, type…).
 * - הארגומנט השני יכול להיות ילד (מחרוזת / אלמנט / מערך) — אז אין מאפיינים.
 * - ילדים: מחרוזות נכנסות כטקסט, מערכים משוטחים, null / false / undefined נזרקים.
 */
export function h(sel, attrs, ...kids) {
    const m = /^([a-z][\w-]*)?((?:[.#][\w-]+)*)$/i.exec(sel || '');
    if (!m) throw new Error(`h: בורר לא חוקי "${sel}"`);
    const el = document.createElement(m[1] || 'div');
    for (const part of m[2].match(/[.#][\w-]+/g) || []) {
        if (part[0] === '#') el.id = part.slice(1);
        else el.classList.add(part.slice(1));
    }
    if (attrs !== null && attrs !== undefined && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) {
        kids.unshift(attrs);
        attrs = null;
    }
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v === undefined || v === null || v === false) continue;
        if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
        else if (k === 'class' || k === 'className') el.className = [el.className, v].filter(Boolean).join(' ');
        else if (k === 'style') typeof v === 'string' ? (el.style.cssText = v) : Object.assign(el.style, v);
        else if (k.includes('-') || k === 'role' || k === 'for' || k === 'tabindex') {
            if (k.startsWith('--')) el.style.setProperty(k, v);
            else el.setAttribute(k, v === true ? '' : String(v));
        }
        else el[k] = v;
    }
    append(el, kids);
    return el;
}

/** מוסיף ילדים לאלמנט באותם כללים של h. */
export function append(el, ...kids) {
    for (const k of kids.flat(Infinity)) if (k !== null && k !== undefined && k !== false) el.append(k instanceof Node ? k : String(k));
    return el;
}

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

/**
 * ltr('210×297') — עוטף טקסט משמאל לימין (מידות, מספרים, נתיבים) בתוך משפט עברי: LRI…PDI.
 * לטקסט בתוך אלמנט אפשר גם <bdi dir="ltr">.
 */
export const ltr = text => '⁦' + text + '⁩';

/** מגביל קריאות: הפונקציה רצה רק אחרי ms בלי קריאה חדשה. flush() — מיד, cancel() — מבטל. */
export function debounce(fn, ms) {
    let t = 0, args = null;
    const run = () => { t = 0; const a = args; args = null; fn(...a); };
    const d = (...a) => { args = a; clearTimeout(t); t = setTimeout(run, ms); };
    d.flush = () => { if (t) { clearTimeout(t); run(); } };
    d.cancel = () => { clearTimeout(t); t = 0; args = null; };
    return d;
}
