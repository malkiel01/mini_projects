/**
 * ui-core/dialog.js — חלונות: גיליון עריכה, אישור, שאלה עם תשובה, ובחירה בין אפשרויות.
 *
 * המראה — הגיליון של סטודיו העיצוב (הכרעת בעל המערכת 2026-10-31): עולה מלמטה בטלפון, במרכז במחשב, נסגר בלחיצה
 * מחוצה לו. הבסיס — <dialog> של הדפדפן: שכבה עליונה, לכידת פוקוס, Esc והחזרת הפוקוס בסגירה — בלי קוד שלנו.
 * מקור: sheet של עורך התמלילים (onSave שמחזיר false / זורק) + confirmDialog של סטודיו העיצוב (Promise).
 *
 *   initDialogs()   — פעם אחת בטעינה: [data-close], סגירה ברקע, --vvh / --kb (המקלדת בטלפון).
 *                     מתאים גם ל-<dialog> שכבר כתובים ב-HTML של האפליקציה.
 *
 *   const s = sheet({ title: 'ייצוא', body: el | (body, dlg) => void, submit: 'שמירה',
 *                     onSubmit: async form => false | void, onClose, wide, full });
 *   await confirm('למחוק את השכבה?', { ok: 'מחיקה', danger: true })     → true / false
 *   await prompt('שם חדש', { value: 'כרטיס.png', select: 'name' })        → מחרוזת / null
 *   await choose('שינויים שלא נשמרו', 'מה לעשות?', [['save', 'לשמור'], ['drop', 'לוותר', 'danger']]) → מפתח / null
 *
 * <dialog data-static> — לא נסגר בלחיצה ברקע (טופס ארוך שקל לאבד).
 */
import { h } from './dom.js';

let ready = false;

export function initDialogs() {
    if (ready) return;
    ready = true;
    document.addEventListener('click', e => {
        const closer = e.target.closest?.('[data-close]');
        if (closer) { closer.closest('dialog')?.close(); return; }
        const d = e.target;
        if (d instanceof HTMLDialogElement && d.open && !d.hasAttribute('data-static') && outside(d, e)) d.close();
    });
    const vv = window.visualViewport;
    const fit = () => {
        const s = document.documentElement.style;
        s.setProperty('--vvh', Math.round(vv ? vv.height : innerHeight) + 'px');
        s.setProperty('--kb', (vv ? Math.max(0, Math.round(innerHeight - vv.height - vv.offsetTop)) : 0) + 'px');
    };
    vv?.addEventListener('resize', fit);
    vv?.addEventListener('scroll', fit);
    addEventListener('resize', fit);
    fit();
}

// לחיצה על הרקע מגיעה ל-<dialog> עצמו; גם לחיצה על ריפוד שלו — לכן בודקים שהיא מחוץ למלבן.
function outside(d, e) {
    if (e.clientX === 0 && e.clientY === 0 && e.detail === 0) return false;   // Enter במקלדת, לא לחיצה
    const r = d.getBoundingClientRect();
    return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
}

/**
 * גיליון עריכה. נבנה, נפתח, ונמחק מה-DOM כשנסגר.
 * onSubmit(form, dlg): מחזיר false → נשאר פתוח; זורק → ההודעה מוצגת בתוך החלון; אחרת → נסגר.
 * בלי submit — אין כפתור שמירה (חלון מידע / תפריט).
 */
export function sheet({ title, body, submit = null, onSubmit = null, onClose = null, wide = false, full = false, label } = {}) {
    initDialogs();
    const err = h('p.error', { role: 'alert', hidden: true });
    const bodyEl = h('div.sheet-body');
    const form = h('form', { method: 'dialog', novalidate: true },
        h('header.sheet-head',
            h('h2', title),
            submit ? h('button.btn.primary', { type: 'submit', value: 'ok' }, submit) : null,
            h('button.icon-btn', { type: 'button', 'data-close': true, 'aria-label': 'סגירה' }, '✕')),
        bodyEl);
    const dlg = h('dialog', { class: [wide && 'wide', full && 'full'].filter(Boolean).join(' '), 'aria-label': label || title }, form);
    if (typeof body === 'function') body(bodyEl, dlg);
    else if (body) bodyEl.append(...[body].flat().filter(Boolean));
    bodyEl.append(err);

    let busy = false;
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (busy) return;
        if (!onSubmit) { dlg.close('ok'); return; }
        busy = true;
        err.hidden = true;
        form.querySelectorAll('[type="submit"]').forEach(b => b.disabled = true);
        try {
            if (await onSubmit(form, dlg) !== false) dlg.close('ok');
        } catch (x) {
            err.textContent = x?.message || String(x);
            err.hidden = false;
        } finally {
            busy = false;
            form.querySelectorAll('[type="submit"]').forEach(b => b.disabled = false);
        }
    });
    dlg.addEventListener('close', () => { onClose?.(dlg.returnValue, dlg); dlg.remove(); });
    document.body.append(dlg);
    dlg.showModal();
    return { el: dlg, form, body: bodyEl, close: v => dlg.close(v), error: m => { err.textContent = m; err.hidden = !m; } };
}

/** בחירה בין אפשרויות. options: [[key, label, kind?]] (null / false — מדלגים, לאפשרות מותנית) — kind: 'primary' (ברירת מחדל לראשון) / 'danger' / 'ghost'. */
export function choose(title, text, options) {
    return new Promise(resolve => {
        let result = null;
        const s = sheet({
            title,
            body: [
                text ? h('p', text) : null,
                h('div.row-btns.wrap', options.filter(Boolean).map(([key, label, kind], i) =>
                    h('button.btn', {
                        type: 'button',
                        class: kind === 'danger' ? 'danger ghost' : (kind || (i === 0 ? 'primary' : 'ghost')),
                        autofocus: i === 0 && kind !== 'danger',
                        onclick: () => { result = key; s.close(); },
                    }, label))),
            ],
            onClose: () => resolve(result),
        });
    });
}

/** אישור לפעולה. Esc, ✕ או לחיצה ברקע → false. */
export async function confirm(text, { ok = 'אישור', cancel = 'ביטול', danger = false, title = 'אישור' } = {}) {
    return await choose(title, text, [['ok', ok, danger ? 'danger' : 'primary'], ['cancel', cancel, 'ghost']]) === 'ok';
}

/**
 * שאלה עם תשובה בשורה אחת. select: 'all' (ברירת מחדל) / 'name' — רק עד הנקודה האחרונה (שם קובץ בלי הסיומת).
 * required: תשובה ריקה לא נשלחת.
 */
export function prompt(label, { title = label, value = '', ok = 'אישור', select = 'all', required = true, inputmode, dir } = {}) {
    return new Promise(resolve => {
        let result = null;
        const input = h('input', { name: 'v', value, autocomplete: 'off', inputmode, dir, required });
        sheet({
            title,
            submit: ok,
            body: h('label.f', title === label ? h('span.sr', label) : h('span', label), input),
            onSubmit: () => {
                const v = input.value.trim();
                if (required && !v) throw new Error('חסרה תשובה');
                result = v;
            },
            onClose: () => resolve(result),
        });
        requestAnimationFrame(() => {
            input.focus();
            const dot = select === 'name' ? input.value.lastIndexOf('.') : -1;
            input.setSelectionRange(0, dot > 0 ? dot : input.value.length);
        });
    });
}
