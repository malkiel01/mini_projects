/**
 * ui-core/tabs.js — לשוניות נגישות. מקור: tabs() של סטודיו העיצוב והסרט של עורך התמלילים.
 *
 *   <div class="seg-tabs" role="tablist">
 *     <button role="tab" data-tab="props" aria-controls="p-props">מאפיינים</button>
 *     <button role="tab" data-tab="layers" aria-controls="p-layers">שכבות</button>
 *   </div>
 *   <section id="p-props" role="tabpanel">…</section>
 *
 *   const t = tabs(root, { onChange: key => …, remember: 'vs-side-tab' });
 *   t.select('layers');  t.value
 *
 * חצים — הפוכים בעברית (חץ שמאלה = הבאה), Home / End. tabindex מתגלגל: Tab נכנס ללשונית הנבחרת בלבד.
 * remember — מפתח ב-localStorage; הלשונית האחרונה חוזרת בטעינה הבאה.
 */
export function tabs(root, { onChange = null, remember = null, initial = null } = {}) {
    const list = () => [...root.querySelectorAll('[role="tab"]')];
    const keyOf = t => t.dataset.tab || t.id;
    let value = null;

    function select(key, { focus = false, user = false } = {}) {
        const all = list();
        const tab = all.find(t => keyOf(t) === key) || all[0];
        if (!tab) return;
        for (const t of all) {
            const on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            const panel = t.getAttribute('aria-controls') && document.getElementById(t.getAttribute('aria-controls'));
            if (panel) panel.hidden = !on;
        }
        if (focus) tab.focus();
        const changed = keyOf(tab) !== value;
        value = keyOf(tab);
        if (remember && user) { try { localStorage.setItem(remember, value); } catch { /* חסום — לא נורא */ } }
        if (changed) onChange?.(value, { user });
    }

    root.addEventListener('click', e => {
        const t = e.target.closest('[role="tab"]');
        if (t && root.contains(t)) select(keyOf(t), { user: true });
    });
    root.addEventListener('keydown', e => {
        const all = list(), i = all.indexOf(document.activeElement);
        if (i < 0) return;
        const rtl = getComputedStyle(root).direction === 'rtl';
        const step = { ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1 }[e.key];
        let n = null;
        if (step) n = (i + step + all.length) % all.length;
        else if (e.key === 'Home') n = 0;
        else if (e.key === 'End') n = all.length - 1;
        if (n === null) return;
        e.preventDefault();
        select(keyOf(all[n]), { focus: true, user: true });
    });

    let saved = null;
    if (remember) { try { saved = localStorage.getItem(remember); } catch { /* */ } }
    const pre = list().find(t => t.getAttribute('aria-selected') === 'true');
    select(saved || initial || (pre && keyOf(pre)));

    return { select: key => select(key, { user: true }), get value() { return value; } };
}
