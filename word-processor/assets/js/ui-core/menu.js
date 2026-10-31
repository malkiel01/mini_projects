/**
 * ui-core/menu.js — תפריט קופץ ותפריט הקשר.
 * מקור: popMenu של עורך התמלילים — ומה שחסר בו ובפופאובר של הווקטורי: Esc, חצים, מיקום לפי כיוון הכתיבה
 * שנשאר בתוך המסך, פוקוס שחוזר לכפתור, ולחיצה ארוכה במגע.
 *
 *   menu(button, [
 *       { icon: '📄', label: 'שכפול', run: duplicate },
 *       { icon: '✏️', label: 'שינוי שם', run: rename, keys: 'F2' },
 *       '-',
 *       { icon: '🗑', label: 'מחיקה', run: remove, danger: true, disabled: locked },
 *   ]);
 *   menu({ x, y }, items)                          // בנקודה — לתפריט הקשר
 *   contextMenu(canvas, e => items | null)         // לחיצה ימנית, ולחיצה ארוכה במגע (גם ב-iOS)
 *
 * run שזורק או מחזיר Promise שנדחה — ההודעה בטוסט.
 */
import { h } from './dom.js';
import { toast } from './toast.js';

let current = null;

export function menu(anchor, items, { align = 'start' } = {}) {
    closeMenu();
    const isPoint = !(anchor instanceof Element);
    const host = (!isPoint && anchor.closest('dialog[open]')) || [...document.querySelectorAll('dialog[open]')].pop() || document.body;
    const rtl = getComputedStyle(isPoint ? document.documentElement : anchor).direction === 'rtl';

    const el = h('div.menu', { role: 'menu', dir: rtl ? 'rtl' : 'ltr' });
    for (const it of items) {
        if (!it) continue;
        if (it === '-') { el.append(h('div', { role: 'separator' })); continue; }
        el.append(h('button', {
            type: 'button', role: 'menuitem', tabindex: '-1',
            class: it.danger ? 'danger' : '',
            'aria-disabled': it.disabled ? 'true' : null,
            onclick: () => {
                if (it.disabled) return;
                closeMenu();
                try {
                    const r = it.run?.();
                    if (r?.catch) r.catch(e => toast(e?.message || String(e), { kind: 'error' }));
                } catch (e) { toast(e?.message || String(e), { kind: 'error' }); }
            },
        }, it.icon ? h('span', { 'aria-hidden': 'true' }, it.icon) : null, h('span', { style: 'flex:1' }, it.label),
           it.keys ? h('small.muted', { dir: 'ltr' }, it.keys) : null));
    }
    host.append(el);
    place(el, isPoint ? { left: anchor.x, right: anchor.x, top: anchor.y, bottom: anchor.y } : anchor.getBoundingClientRect(), rtl, align);

    const buttons = () => [...el.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])')];
    const focusAt = i => { const b = buttons(); if (b.length) b[(i + b.length) % b.length].focus(); };
    el.addEventListener('keydown', e => {
        const b = buttons(), i = b.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); focusAt(i + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); focusAt(i - 1); }
        else if (e.key === 'Home') { e.preventDefault(); focusAt(0); }
        else if (e.key === 'End') { e.preventDefault(); focusAt(-1); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(true); }   // לא סוגר את ה-<dialog> שמתחת
        else if (e.key === 'Tab') closeMenu();
    });
    const away = e => { if (current?.el === el && !el.contains(e.target) && e.target !== anchor) closeMenu(); };
    // רק אחרי הלחיצה שפתחה — ורק אם התפריט עוד פתוח (Esc מהיר היה משאיר מאזין יתום שסוגר את התפריט הבא).
    setTimeout(() => { if (current?.el === el) document.addEventListener('pointerdown', away, true); });
    addEventListener('resize', closeMenuQuiet);
    if (!isPoint) anchor.setAttribute('aria-expanded', 'true');
    current = { el, anchor: isPoint ? null : anchor, away };
    focusAt(0);
    return { el, close: () => closeMenu() };
}

function closeMenuQuiet() { closeMenu(false); }

/** סוגר את התפריט הפתוח. back — להחזיר את הפוקוס לכפתור שפתח. */
export function closeMenu(back = false) {
    if (!current) return;
    const { el, anchor, away } = current;
    current = null;
    document.removeEventListener('pointerdown', away, true);
    removeEventListener('resize', closeMenuQuiet);
    el.remove();
    if (anchor) {
        anchor.setAttribute('aria-expanded', 'false');
        if (back) anchor.focus();
    }
}

// מתחת לכפתור (או מעליו כשאין מקום), מיושר לתחילת השורה לפי כיוון הכתיבה, ותמיד בתוך המסך.
function place(el, r, rtl, align) {
    const pad = 8, vw = innerWidth, vh = innerHeight;
    const w = el.offsetWidth, hgt = el.offsetHeight;
    const startEdge = (align === 'start') === rtl ? r.right - w : r.left;   // RTL+start → מיושר לימין הכפתור
    const left = Math.min(Math.max(pad, startEdge), vw - w - pad);
    const below = r.bottom + 4;
    const top = below + hgt <= vh - pad ? below : Math.max(pad, r.top - hgt - 4);
    el.style.left = left + 'px';
    el.style.top = top + 'px';
}

/**
 * תפריט הקשר על אלמנט. getItems(event) מחזיר פריטים, או null — אין תפריט כאן.
 * לחיצה ימנית בעכבר; בטלפון — לחיצה ארוכה (500ms בלי תזוזה). אנדרואיד שולח contextmenu בעצמו; iOS לא —
 * לכן גם טיימר, ומניעת כפילות ביניהם. מחזיר פונקציה שמנתקת.
 */
export function contextMenu(target, getItems, { delay = 500 } = {}) {
    let timer = 0, start = null, openedAt = 0;
    const open = (x, y, e) => {
        if (Date.now() - openedAt < 700) return;
        const items = getItems(e);
        if (!items) return;
        openedAt = Date.now();
        menu({ x, y }, items);
    };
    const onContext = e => {
        const items = getItems(e);
        if (!items) return;
        e.preventDefault();
        if (Date.now() - openedAt < 700) return;
        openedAt = Date.now();
        menu({ x: e.clientX, y: e.clientY }, items);
    };
    const cancel = () => { clearTimeout(timer); timer = 0; start = null; };
    const onDown = e => {
        if (e.pointerType !== 'touch') return;
        start = { x: e.clientX, y: e.clientY };
        timer = setTimeout(() => { timer = 0; open(start.x, start.y, e); }, delay);
    };
    const onMove = e => { if (start && Math.hypot(e.clientX - start.x, e.clientY - start.y) > 8) cancel(); };
    const onClick = e => { if (Date.now() - openedAt < 700) { e.preventDefault(); e.stopPropagation(); } };
    target.addEventListener('contextmenu', onContext);
    target.addEventListener('pointerdown', onDown);
    target.addEventListener('pointermove', onMove);
    target.addEventListener('pointerup', cancel);
    target.addEventListener('pointercancel', cancel);
    target.addEventListener('click', onClick, true);
    return () => {
        cancel();
        target.removeEventListener('contextmenu', onContext);
        target.removeEventListener('pointerdown', onDown);
        target.removeEventListener('pointermove', onMove);
        target.removeEventListener('pointerup', cancel);
        target.removeEventListener('pointercancel', cancel);
        target.removeEventListener('click', onClick, true);
    };
}
