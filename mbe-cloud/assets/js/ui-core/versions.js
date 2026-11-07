/**
 * ui-core/versions.js — לוח גרסאות ושלבים (ממ1). אחד לכל האפליקציות. המקור: "🕘 היסטוריית גרסאות" של העימוד
 * (readHistory ב-mbe-io.js, הרשימה ב-editor.js) ו"📜 שלבי הקובץ" של סטודיו העיצוב (journalSection ב-main.js).
 *
 * המודול לא מייבא את ליבת הפורמט (לכל אפליקציה עותק משלה במקום אחר) — הוא מקבל קובץ ‎.mbe פתוח, `f`, מהאפליקציה:
 *
 *   import { open } from './mbe/mbe.js';                       // העותק של הליבה באפליקציה
 *   import { openVersions } from './ui-core/versions.js';
 *
 *   openVersions({
 *       load:    async () => open(bytes),            // הקובץ ‎.mbe כמו שנשמר; null — "עוד אין היסטוריה"
 *       restore: async (step, f) => { … },           // טוען את f.version(step.sha) לעורך (האפליקציה), ומחזירה
 *       app:     'layout-studio',                    // רשות — מסמן "כאן" ליד שלבים של האפליקציה הזו
 *   });
 *
 * או בתוך חלון / לוח קיים: `versionsList(el, { load, restore })` → { refresh() }.
 * החשבון לבד: `journalSteps(f)` — השלבים מהחדש לישן, עם תוויות בעברית.
 *
 * מה שנשאר באפליקציה: מאיפה מגיע הקובץ (שרת, מכשיר, ענן), איך גרסה נטענת לעורך, ורישום השלב `restore`
 * (`f.restore(step.id)` לפני שמירה, או `f.stage({ kind: 'restore' })` בשמירה הבאה — מה שהאפליקציה כבר עושה).
 */
import { h } from './dom.js';
import { sheet, confirm } from './dialog.js';
import { toast } from './toast.js';

/** סוגי השלבים ביומן (mbe-format.md, history/journal.json). */
export const KINDS = { create: 'נוצר', edit: 'עריכה', export: 'יצא', return: 'חזר', merge: 'מוזג', restore: 'שוחזר' };

/** שמות האפליקציות בחבילה. "external:<שם>" — כלי חיצוני (Word, פוטושופ…). */
export const APPS = {
    'layout-studio': 'עימוד', 'word-processor': 'עורך התמלילים', 'raster-studio': 'רסטר', 'vector-studio': 'וקטורי',
    'greeting-cards': 'סטודיו העיצוב', 'suite-cloud': 'ענן החבילה',
};

export const appLabel = app => {
    const a = String(app || '');
    return APPS[a] || (a.startsWith('external:') ? a.slice(9) : a);
};

/** תאריך ושעה קצרים, בעברית. */
export function whenLabel(at) {
    if (!at) return '';
    const d = new Date(at);
    if (isNaN(d)) return String(at);
    return d.toLocaleString('he-IL', { day: 'numeric', month: 'numeric', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}

/**
 * השלבים של קובץ ‎.mbe פתוח, מהחדש לישן:
 * [{ id, at, kind, kindLabel, app, appLabel, who, note, sha, current, available }]
 *   current   — השלב האחרון = המסמך כמו שהוא עכשיו (אין מה לשחזר)
 *   available — העותק של הגרסה עדיין בקובץ (לא נגזם ב-f.prune)
 */
export function journalSteps(f) {
    if (!f || typeof f.journal !== 'function') throw new Error('versions: חסר קובץ ‎.mbe פתוח (f.journal)');
    const list = (f.journal() || []).slice().reverse();
    return list.map((s, i) => {
        const sha = s.result || null;
        let available = false;
        if (sha && !s.pruned && i > 0) {
            try { available = !!f.version(sha); } catch { available = false; }
        }
        return {
            id: s.id || '', at: s.at || '', kind: s.kind || '', kindLabel: KINDS[s.kind] || s.kind || '',
            app: String(s.app || ''), appLabel: appLabel(s.app), who: s.who || '', note: s.note || '',
            sha, current: i === 0, available,
        };
    });
}

const EMPTY = 'עוד אין היסטוריה. היא נרשמת בכל שמירה של המסמך כקובץ ‎.mbe, וגם כשאפליקציה אחרת עורכת אותו.';

/**
 * הרשימה בתוך אלמנט קיים. opts: { load, restore, app?, empty?, confirmRestore = true, onRestored? }
 * מחזיר { refresh, steps() }.
 */
export function versionsList(el, opts) {
    const { load, restore, app = '', empty = EMPTY, confirmRestore = true, onRestored } = opts || {};
    if (typeof load !== 'function') throw new Error('versions: חסרה הפונקציה load');
    el.classList.add('versions-box');   // לא '.versions' — שם תפוס ברסטר (app.css)
    const sum = h('p.hint.versions-sum', { 'aria-live': 'polite' });
    const ul = h('ul.list.versions-list');
    el.replaceChildren(sum, ul);
    let steps = [];
    let file = null;

    function row(st) {
        const txt = h('div.versions-txt',
            h('div.versions-head', `${st.kindLabel} · ${st.appLabel || '—'}`, app && st.app === app ? h('span.versions-here', 'כאן') : null),
            h('div.versions-meta', h('bdi', whenLabel(st.at)), (st.who || st.note) ? ' · ' + [st.who, st.note].filter(Boolean).join(' — ') : ''));
        let end;
        if (st.current) end = h('span.versions-end', 'הנוכחית');
        else if (!st.available) end = h('span.versions-end', { title: 'העותק של הגרסה הזו כבר לא שמור בקובץ' }, 'נגזם');
        else if (typeof restore === 'function') end = h('button.btn.sm.ghost', { type: 'button', onclick: () => doRestore(st, end) }, 'שחזור');
        return h('li.versions-row', { 'data-id': st.id }, txt, end);
    }

    async function doRestore(st, btn) {
        if (confirmRestore && !await confirm(`לטעון את הגרסה מ-${whenLabel(st.at)}? המסמך הנוכחי יוחלף בה (אפשר לבטל ב-↶).`,
            { ok: 'שחזור', title: 'שחזור גרסה' })) return;
        btn.disabled = true;
        try {
            await restore(st, file);
            onRestored?.(st);
        } catch (e) {
            toast('השחזור נכשל: ' + (e?.message || e), { kind: 'error' });
        } finally {
            btn.disabled = false;
        }
    }

    async function refresh() {
        sum.textContent = 'טוען…';
        ul.replaceChildren();
        try {
            file = await load();
            steps = file ? journalSteps(file) : [];
        } catch (e) {
            steps = [];
            sum.textContent = 'ההיסטוריה לא נטענה: ' + (e?.message || e);
            return;
        }
        sum.textContent = steps.length ? 'מהחדש לישן. שחזור טוען את הגרסה לעורך, ונרשם ביומן בשמירה הבאה.' : empty;
        ul.append(...steps.map(row));
    }

    refresh();
    return { refresh, steps: () => steps };
}

/** חלון "🕘 גרסאות ושלבים" (גיליון; בטלפון — מלמטה). אותן אפשרויות כמו versionsList; נסגר אחרי שחזור. */
export function openVersions(opts = {}) {
    let s;
    s = sheet({
        title: opts.title || '🕘 גרסאות ושלבים',
        body: body => versionsList(body, { ...opts, onRestored: st => { s?.close('restored'); opts.onRestored?.(st); } }),
    });
    return s;
}
