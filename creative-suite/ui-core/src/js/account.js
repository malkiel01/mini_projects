/**
 * ui-core/account.js — 👤 העיגול של המשתמש בסרגל, התפריט שלו, וההגדרות האישיות. אחד לכל האפליקציות.
 * ההכרעה: creative-suite/docs/requests/account-menu.md (בעל המערכת + הצ'אט המתכלל, 2026-10-31).
 *
 *   const acc = accountMenu(document.querySelector('.top'), {
 *       user:   () => ({ name, email, avatar, admin }),          // מהאפליקציה; null — לא מחובר (העיגול לא מוצג)
 *       server: await accountServer(api),                        // רשות — צד השרת המשותף (php/account): הגדרות במסד של
 *                                                                // האפליקציה, ייצוא "כל הנתונים שלי" ומחיקת חשבון
 *       prefs:  { get: ns => obj | null, set: (ns, obj) => … },  // רשות — מתאם משלך; בלי server ובלי prefs — localStorage
 *       docs:   () => …,                                         // 📁 "המסמכים שלי" (בהמשך: files-window.js)
 *       drives: { list: () => [{ id, name, icon, connected }], manage: () => … },   // ☁️ — מהמודול של הכוננים
 *       sections: [{ id, title, render: body => … }],            // הגדרות של האפליקציה עצמה
 *       shortcuts: keys,                                         // רשות — shortcuts() מ-keys.js: הרשימה בהגדרות
 *       fonts: ['Heebo', 'Rubik', …],                            // רשות — גופן ברירת המחדל (עד שלב 8)
 *       account: () => … | url,                                  // רשות — במקום חלון החשבון של server
 *       admin: () => … | url, help: () => … | url,
 *       logout: () => …,
 *       onPrefs: prefs => …,                                     // כשההגדרות הכלליות משתנות (יחידות, שמירה אוטומטית…)
 *   });
 *   acc.prefs            // ההגדרות הכלליות הנוכחיות
 *   acc.refresh()        // אחרי שהמשתמש / החיבורים השתנו
 *   acc.open()           // פתיחת התפריט מקוד
 *
 * בטלפון (עד 599px) התפריט הוא גיליון שעולה מלמטה; במחשב — תפריט קופץ ליד העיגול. הפריטים — באותו סדר בכל אפליקציה,
 * ופריט שהאפליקציה לא נתנה לו פעולה — לא מוצג.
 *
 * הגדרות כלליות (זהות בכל האפליקציות, ns = 'general'): theme ('system' | 'light' | 'dark'), scale (0.9 | 1 | 1.15 | 1.3),
 * units ('pt' | 'mm' | 'px'), autosave (bool), font (שם), cloudSync (bool). theme ו-scale מוחלים כאן (data-theme, --ui-scale);
 * השאר — של האפליקציה, דרך onPrefs.
 */
import { h } from './dom.js';
import { menu, closeMenu } from './menu.js';
import { sheet, confirm } from './dialog.js';
import { toast } from './toast.js';
import { download, fileName } from './files.js';
import { pref } from './store.js';
import { label as keyLabel } from './keys.js';

export const DEFAULT_PREFS = Object.freeze({ theme: 'system', scale: 1, units: 'pt', autosave: true, font: '', cloudSync: false });

export const initials = name => String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';

/** גוון קבוע לשם (לרקע של ראשי התיבות) — כמו .avatar --h בסקיל */
export const hue = s => [...String(s || '')].reduce((a, c) => (a * 31 + c.codePointAt(0)) % 360, 7);

/** מחיל ערכת צבעים וגודל טקסט על הדף. */
export function applyPrefs(p) {
    const root = document.documentElement;
    if (p.theme === 'light' || p.theme === 'dark') root.dataset.theme = p.theme;
    else delete root.dataset.theme;
    root.style.setProperty('--ui-scale', String(p.scale || 1));
}

const act = v => typeof v === 'string' ? () => { location.href = v; } : v;

/**
 * צד השרת המשותף (ui-core/php/account): call(action, body) → Promise של ה-JSON — העטיפה של ה-API של האפליקציה
 * (עם ה-CSRF שלה). טוען את ההגדרות פעם אחת; כל שינוי נשמר בשרת.
 *   const server = await accountServer((action, body) => api(action, body));
 */
export async function accountServer(call) {
    const { prefs: all } = await call('account_prefs', {});
    const cache = { ...(all && !Array.isArray(all) ? all : {}) };
    return {
        prefs: {
            get: ns => cache[ns] ?? null,
            set: (ns, value) => { cache[ns] = value; return call('account_prefs_set', { ns, value }); },
        },
        async exportData(name = 'הנתונים שלי') {
            const data = await call('account_export', {});
            await download(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }), fileName(name, 'json'));
        },
        deleteAccount: () => call('account_delete', { confirm: 'DELETE' }),
    };
}

export function accountMenu(bar, opts = {}) {
    const local = pref('ui-core-account');
    const store = opts.server?.prefs || opts.prefs || { get: ns => local.get(ns), set: (ns, v) => local.set(ns, v) };
    let prefs = { ...DEFAULT_PREFS, ...(store.get('general') || {}) };
    applyPrefs(prefs);

    const btn = h('button.account-btn', { type: 'button', 'aria-haspopup': 'menu', 'aria-expanded': 'false', onclick: () => open() });
    bar.append(btn);

    function face(el) {
        const u = opts.user?.();
        el.replaceChildren(u?.avatar ? h('img', { src: u.avatar, alt: '' }) : initials(u?.name || u?.email));
        el.style.setProperty('--h', hue(u?.email || u?.name));
        return u;
    }

    function refresh() {
        const u = face(btn);
        btn.hidden = !u;
        btn.setAttribute('aria-label', u ? `החשבון של ${u.name || u.email}` : 'חשבון');
        btn.title = u ? (u.name || u.email) : '';
    }

    function entries() {
        const u = opts.user?.() || {};
        const connected = (opts.drives?.list?.() || []).filter(d => d.connected);
        return [
            opts.docs && { icon: '📁', label: 'המסמכים שלי', run: opts.docs },
            opts.drives && { icon: '☁️', label: 'עננים מחוברים', sub: connected.length ? connected.map(d => d.name).join(' · ') : 'לא מחובר', run: opts.drives.manage },
            { icon: '⚙️', label: 'הגדרות אישיות', run: settings },
            (opts.account || opts.server) && { icon: '🔑', label: 'החשבון', run: opts.account ? act(opts.account) : accountSheet },
            u.admin && opts.admin && { icon: '🛠', label: 'ניהול', run: act(opts.admin) },
            opts.help && { icon: '❓', label: 'עזרה ומה חדש', run: act(opts.help) },
            opts.logout && '-',
            opts.logout && { icon: '🚪', label: 'יציאה', run: opts.logout, danger: true },
        ].filter(Boolean);
    }

    function head() {
        const u = opts.user?.() || {};
        const av = h('span.avatar');
        face(av);
        const connected = (opts.drives?.list?.() || []).filter(d => d.connected);
        return h('div.account-head', av, h('div.who',
            h('b', u.name || u.email || ''),
            u.email && u.name ? h('small', { dir: 'ltr' }, u.email) : null,
            connected.length ? h('div.chips', connected.map(d => h('span.chip', `${d.icon || '☁️'} ${d.name}`))) : null));
    }

    function open() {
        if (matchMedia('(max-width: 599px)').matches) {
            const s = sheet({
                title: 'החשבון',
                body: [head(), h('ul.account-list', entries().map(it => it === '-' ? null : h('li', { class: it.danger ? 'danger' : '' },
                    h('button.use-row', { type: 'button', onclick: () => { s.close(); it.run?.(); } },
                        h('span.use-ic', { 'aria-hidden': 'true' }, it.icon),
                        h('span.use-tx', h('span', it.label), it.sub ? h('small', it.sub) : null)))))],
            });
            return s;
        }
        const m = menu(btn, entries(), { align: 'end' });
        m.el.prepend(head());
        return m;
    }

    function save(next) {
        prefs = { ...prefs, ...next };
        Promise.resolve(store.set('general', prefs)).catch(e => toast('ההגדרות לא נשמרו: ' + (e?.message || e), { kind: 'error' }));
        applyPrefs(prefs);
        opts.onPrefs?.(prefs);
    }

    function radios(name, value, items, onChange) {
        return h('div.seg', { role: 'radiogroup' }, items.map(([v, text]) => h('label',
            h('input', { type: 'radio', name, value: String(v), checked: String(v) === String(value), onchange: () => onChange(v) }),
            h('span', text))));
    }

    function settings() {
        closeMenu();
        const general = h('section.group',
            h('h3', 'כללי ', h('small', 'אותן הגדרות בכל האפליקציות')),
            h('div.f', h('span', 'ערכת צבעים'), radios('theme', prefs.theme, [['system', 'כמו המכשיר'], ['light', 'בהירה'], ['dark', 'כהה']], v => save({ theme: v }))),
            h('div.f', h('span', 'גודל טקסט'), radios('scale', prefs.scale, [[0.9, 'קטן'], [1, 'רגיל'], [1.15, 'גדול'], [1.3, 'גדול מאוד']], v => save({ scale: +v }))),
            h('div.f', h('span', 'יחידות'), radios('units', prefs.units, [['pt', "נק'"], ['mm', 'מ"מ'], ['px', 'פיקסלים']], v => save({ units: v }))),
            h('label.switch', h('input', { type: 'checkbox', checked: prefs.autosave, onchange: e => save({ autosave: e.target.checked }) }), 'שמירה אוטומטית'),
            opts.fonts?.length ? h('label.f', h('span', 'גופן ברירת מחדל'),
                h('select', { onchange: e => save({ font: e.target.value }) },
                    h('option', { value: '' }, 'של האפליקציה'),
                    opts.fonts.map(f => h('option', { value: f, selected: f === prefs.font, style: { fontFamily: `"${f}"` } }, f)))) : null,
            opts.drives ? h('label.switch', h('input', { type: 'checkbox', checked: prefs.cloudSync, onchange: e => save({ cloudSync: e.target.checked }) }),
                'לשמור את ההגדרות בענן (אותן הגדרות בכל המכשירים)') : null);

        const list = opts.shortcuts?.list?.().filter(k => k.label) || [];
        const keys = list.length ? h('section.group', h('h3', 'קיצורי מקלדת'),
            h('ul.list', list.map(k => h('li.card', { style: 'min-height:40px;cursor:default' },
                h('span.body', k.label), h('kbd', { dir: 'ltr' }, keyLabel(k.combo)))))) : null;

        const app = (opts.sections || []).map(s => {
            const body = h('section.group', h('h3', s.title));
            s.render(body);
            return body;
        });

        sheet({ title: 'הגדרות אישיות', body: [general, ...app, keys], full: true });
    }

    /** 🔑 החשבון — כש-server נתון: ייצוא כל הנתונים, ומחיקת החשבון (אישור כפול). */
    function accountSheet() {
        closeMenu();
        const s = sheet({
            title: 'החשבון',
            body: [
                head(),
                h('div.tiles',
                    h('button.tile', { type: 'button', onclick: () => opts.server.exportData().then(() => toast('הקובץ ירד')).catch(e => toast(e?.message || String(e), true)) },
                        h('span.tile-ic', '⬇️'), 'ייצוא כל הנתונים שלי'),
                    h('button.tile', { type: 'button', onclick: async () => {
                        if (!await confirm('למחוק את החשבון ואת כל הנתונים שלך באפליקציה הזו? אי אפשר לבטל.', { ok: 'המשך', danger: true })) return;
                        if (!await confirm('בטוח? זה הצעד האחרון.', { ok: 'מחיקת החשבון', danger: true, title: 'מחיקת החשבון' })) return;
                        try { await opts.server.deleteAccount(); s.close(); toast('החשבון נמחק'); opts.onDeleted ? opts.onDeleted() : opts.logout?.(); }
                        catch (e) { toast(e?.message || String(e), true); }
                    } }, h('span.tile-ic', '🗑'), h('span', { style: 'color:var(--danger)' }, 'מחיקת החשבון'))),
            ],
        });
    }

    refresh();
    return { get prefs() { return { ...prefs }; }, refresh, open, settings, el: btn, setPrefs: save };
}
