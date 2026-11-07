/**
 * ui-core/toast.js — הודעה קצרה בתחתית המסך. אחת בכל רגע; חדשה מחליפה את הקודמת.
 * מקור: toast של עורך התמלילים (כפתור פעולה) ושל סטודיו העיצוב (זמן ארוך לשגיאה).
 *
 *   toast('נשמר')
 *   toast('השמירה נכשלה', { kind: 'error' })            // או toast(msg, true) — כמו בקוד הישן
 *   toast('השכבה נמחקה', { action: { label: 'ביטול', run: undo } })
 *
 * זמנים: 3 שניות; שגיאה — 6; עם כפתור — 8. ms דורס.
 * האלמנט: .toast[role=status] — נוצר לבד אם אין. בתוך <dialog> פתוח הוא עובר לשם, אחרת היה מתחת לשכבה העליונה.
 */
import { h } from './dom.js';

let timer = 0;

export function toast(msg, opts = {}) {
    if (opts === true) opts = { kind: 'error' };
    const { kind = '', action = null } = opts;
    const error = kind === 'error' || kind === 'err';
    const el = element();
    el.replaceChildren(String(msg));
    if (action) {
        el.append(h('button.toast-act', { type: 'button', onclick: () => { hide(); action.run(); } }, action.label));
    }
    el.classList.toggle('err', error);
    el.classList.toggle('has-act', !!action);
    el.hidden = false;
    clearTimeout(timer);
    timer = setTimeout(hide, opts.ms ?? (action ? 8000 : error ? 6000 : 3000));
}

export function hide() {
    clearTimeout(timer);
    const el = document.querySelector('.toast');
    if (el) el.hidden = true;
}

function element() {
    let el = document.querySelector('.toast');
    if (!el) el = h('div.toast', { role: 'status', 'aria-live': 'polite', hidden: true });
    const host = [...document.querySelectorAll('dialog[open]')].pop() || document.body;
    if (el.parentNode !== host) {
        host.append(el);
        // החלון נסגר (ואולי נמחק) — ההודעה חוזרת לדף ונשארת גלויה עד שהזמן שלה נגמר.
        if (host !== document.body) host.addEventListener('close', () => document.body.append(el), { once: true });
    }
    return el;
}
