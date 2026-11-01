/**
 * ui-core/layers.js — לוח שכבות. אחד לכל האפליקציות. המקור: layers-panel.js של הרסטר — הרשימה, השורה, שינוי השם במקום
 * והגרירה (כולל לתוך קבוצה). מה שתלוי במודל (אטימות, מיזוג, מסכות, היסטוריה) — נשאר באפליקציה, דרך המתאם.
 *
 *   const panel = layersPanel(ul, {
 *       items:    () => doc.layers,                 // מערך במבנה הערימה: [0] = התחתונה. הלוח מציג מלמעלה (העליונה ראשונה)
 *       active:   () => doc.activeId,               // id, או Set / מערך של ids (בחירה מרובה)
 *       select:   (id, e) => …,                     // לחיצה על שורה (e.shiftKey / e.ctrlKey — לבחירה מרובה של האפליקציה)
 *       toggle:   (id, key) => …,                   // key: 'visible' | 'locked'
 *       rename:   (id, name) => …,
 *       move:     (id, parentId, index) => …,       // parentId: null = שורש; index — במערך של ההורה, אחרי שהפריט הוצא ממקומו
 *       remove:   id => …,                          // רשות — Delete במקלדת
 *       menu:     id => [{ label, run }, …],        // רשות — תפריט לחיצה ימנית / לחיצה ארוכה (menu.js)
 *       thumb:    (node, canvas) => …,              // רשות — ציור התמונה הממוזערת; בלי — node.icon / '▭'
 *   });
 *   panel.render()                                  // אחרי כל שינוי במסמך
 *
 * צומת: { id, name, visible = true, locked = false, children?: [...] (קבוצה), collapsed?, icon?, badges?: [Node], title? }
 * המראה — ui.css (.layer-row, .drag, .thumb, .layer-name, .eye, .lock, .fold — שמות הרסטר). שורה נעולה — השם לא נערך.
 * מקלדת: ↑ / ↓ — השכבה הבאה, F2 — שינוי שם, Delete — מחיקה (אם יש remove), Space — הסתרה / הצגה.
 */
import { h } from './dom.js';
import { menu as openMenu, contextMenu } from './menu.js';

export function layersPanel(list, a) {
    list.classList.add('layer-list');
    list.setAttribute('role', 'tree');
    const index = new Map();       // id → { node, parent, siblings }
    const isActive = id => { const x = a.active(); return x instanceof Set ? x.has(id) : Array.isArray(x) ? x.includes(id) : x === id; };

    function render() {
        index.clear();
        const focusId = document.activeElement?.closest?.('.layer-row')?.dataset.id;
        const rows = [];
        const walk = (arr, parent, depth) => {
            for (let i = arr.length - 1; i >= 0; i--) {
                const node = arr[i];
                index.set(node.id, { node, parent, siblings: arr });
                rows.push(row(node, depth));
                if (node.children && !node.collapsed) walk(node.children, node, depth + 1);
            }
        };
        walk(a.items(), null, 0);
        list.replaceChildren(...rows);
        if (focusId) list.querySelector(`[data-id="${CSS.escape(focusId)}"]`)?.focus();
    }

    function row(node, depth) {
        const visible = node.visible !== false, locked = !!node.locked, group = Array.isArray(node.children);
        const li = h('li.layer-row', { role: 'treeitem', tabindex: isActive(node.id) ? '0' : '-1', 'data-id': node.id,
            'aria-selected': String(isActive(node.id)), 'aria-level': String(depth + 1), title: node.title || false,
            ...(group ? { 'aria-expanded': String(!node.collapsed) } : {}), style: { paddingInlineStart: `calc(var(--s2) + ${depth * 16}px)` } });
        li.classList.toggle('group-row', group);
        li.classList.toggle('hidden-layer', !visible);
        const drag = h('span.drag', { 'aria-hidden': 'true', title: 'גרירה לשינוי הסדר' }, '⋮⋮');
        const fold = group ? h('button.icon-btn.fold', { type: 'button', 'aria-label': (node.collapsed ? 'פתיחת ' : 'קיפול ') + node.name,
            onclick: e => { e.stopPropagation(); node.collapsed = !node.collapsed; a.collapse?.(node.id, node.collapsed); render(); } }, node.collapsed ? '‹' : '⌄') : null;
        let thumb;
        if (a.thumb && !group) { thumb = h('canvas.thumb', { 'aria-hidden': 'true', width: 64, height: 64 }); a.thumb(node, thumb); }
        else thumb = h('span.thumb.folder', { 'aria-hidden': 'true' }, node.icon || (group ? '📁' : '▭'));
        const name = h('button.layer-name', { type: 'button' }, node.name);
        name.addEventListener('click', e => {
            e.stopPropagation();
            if (isActive(node.id) && !locked && a.rename && !e.shiftKey && !e.ctrlKey && !e.metaKey) rename(node, name);
            else a.select(node.id, e);
        });
        const eye = h('button.icon-btn.eye', { type: 'button', 'aria-label': (visible ? 'הסתרת ' : 'הצגת ') + node.name, 'aria-pressed': String(!visible),
            onclick: e => { e.stopPropagation(); a.toggle(node.id, 'visible'); } }, visible ? '👁️' : '🚫');
        const lock = h('button.icon-btn.lock', { type: 'button', 'aria-label': (locked ? 'שחרור ' : 'נעילת ') + node.name, 'aria-pressed': String(locked),
            onclick: e => { e.stopPropagation(); a.toggle(node.id, 'locked'); } }, locked ? '🔒' : '🔓');
        lock.classList.toggle('off', !locked);
        li.append(drag, ...(fold ? [fold] : []), thumb, ...(node.badges || []), name, eye, lock);
        li.addEventListener('click', e => { if (!e.target.closest('button, input')) a.select(node.id, e); });
        li.addEventListener('keydown', e => key(e, node, name));
        drag.addEventListener('pointerdown', e => startDrag(e, node, li));
        if (a.menu) contextMenu(li, () => { a.select(node.id, {}); return a.menu(node.id); });
        return li;
    }

    function key(e, node, nameBtn) {
        if (e.target.tagName === 'INPUT') return;
        const rows = [...list.querySelectorAll('.layer-row')], i = rows.indexOf(e.currentTarget);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const next = rows[i + (e.key === 'ArrowDown' ? 1 : -1)];
            if (next) { a.select(next.dataset.id, e); render(); list.querySelector(`[data-id="${CSS.escape(next.dataset.id)}"]`)?.focus(); }
        } else if (e.key === 'F2' && a.rename && !node.locked) { e.preventDefault(); rename(node, e.currentTarget.querySelector('.layer-name')); }
        else if ((e.key === 'Delete' || e.key === 'Backspace') && a.remove) { e.preventDefault(); a.remove(node.id); }
        else if (e.key === ' ') { e.preventDefault(); a.toggle(node.id, 'visible'); }
        else if (e.key === 'ContextMenu' && a.menu) { e.preventDefault(); openMenu(e.currentTarget, a.menu(node.id)); }
    }

    /** שינוי שם במקום: Enter / יציאה — שומר; Esc — מבטל. */
    function rename(node, btn) {
        const input = h('input.layer-name-input', { value: node.name, maxLength: 80, 'aria-label': 'שם השכבה' });
        btn.replaceWith(input);
        input.focus();
        input.select();
        let done = false;
        const finish = save => {
            if (done) return;
            done = true;
            const v = input.value.trim();
            if (save && v && v !== node.name) a.rename(node.id, v);
            render();
        };
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        input.addEventListener('blur', () => finish(true));
        input.addEventListener('click', e => e.stopPropagation());
    }

    /** גרירה בידית ⋮⋮: לפני / אחרי שורה, או (שליש אמצעי של קבוצה) לתוכה. קבוצה לא נגררת לתוך עצמה. */
    function startDrag(e, node, li) {
        e.preventDefault();
        const handle = e.currentTarget;
        handle.setPointerCapture?.(e.pointerId);
        li.classList.add('dragging');
        let target = null, after = false, into = false;
        const clear = () => list.querySelectorAll('.drop-before, .drop-after, .drop-into').forEach(r => r.classList.remove('drop-before', 'drop-after', 'drop-into'));
        const onMove = ev => {
            clear();
            target = null;
            for (const r of list.children) {
                const b = r.getBoundingClientRect();
                if (ev.clientY < b.top || ev.clientY >= b.bottom) continue;
                if (r === li) break;
                target = r;
                const rel = (ev.clientY - b.top) / b.height;
                into = r.classList.contains('group-row') && rel > 1 / 3 && rel < 2 / 3;
                after = rel > 0.5;
                r.classList.add(into ? 'drop-into' : after ? 'drop-after' : 'drop-before');
                break;
            }
        };
        const up = () => {
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', up);
            handle.removeEventListener('pointercancel', up);
            li.classList.remove('dragging');
            clear();
            if (!target) return;
            const t = index.get(target.dataset.id), me = index.get(node.id);
            if (!t || !me || contains(node, t.node)) return;
            // התצוגה הפוכה לערימה: "לפני" שורה בתצוגה = מעליה בערימה = אינדקס גבוה ממנה
            const parent = into ? t.node : t.parent;
            const siblings = into ? t.node.children : t.siblings;
            let to = into ? siblings.length : siblings.indexOf(t.node) + (after ? 0 : 1);
            if (siblings === me.siblings && me.siblings.indexOf(node) < to) to--;
            if (siblings === me.siblings && me.siblings.indexOf(node) === to) return;
            if (into) t.node.collapsed = false;
            a.move(node.id, parent ? parent.id : null, to);
        };
        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', up);
        handle.addEventListener('pointercancel', up);
    }

    const contains = (outer, inner) => outer === inner || (outer.children || []).some(c => contains(c, inner));

    render();
    return { render, el: list };
}

/**
 * עזר למודל פשוט (מערך של צמתים עם children): מוציא את הצומת ומכניס אותו להורה / לשורש באינדקס — לשימוש ב-move.
 *   move: (id, parentId, i) => { moveNode(doc.layers, id, parentId, i); commit('סדר השכבות'); panel.render(); }
 */
export function moveNode(root, id, parentId, index) {
    let found = null;
    const take = arr => { const i = arr.findIndex(n => n.id === id); if (i >= 0) { found = arr.splice(i, 1)[0]; return true; } return arr.some(n => n.children && take(n.children)); };
    const find = arr => { for (const n of arr) { if (n.id === parentId) return n; const r = n.children && find(n.children); if (r) return r; } return null; };
    if (!take(root)) return false;
    const dest = parentId == null ? root : find(root)?.children;
    if (!dest) { root.push(found); return false; }
    dest.splice(Math.max(0, Math.min(index, dest.length)), 0, found);
    return true;
}
