// שפות — עברית, אנגלית, צרפתית, רוסית וערבית (lib/i18n.php בשרת).
// נטען ראשון, ב-<head>: קובע lang ו-dir לפני שהדף מצויר, ומושך את המילון.
//
// העברית היא שפת המקור — הטקסט העברי בקוד הוא המפתח, והמילון (assets/lang/<שפה>.json)
// ממפה אותו לתרגום. אין תרגום? מוצגת העברית.
//   t('נשמר')                          טקסט קבוע
//   t('נשלח מייל ל{name}', { name })   עם משתנים. ערך שהוא טקסט מתורגם גם הוא, אם יש לו
//                                      תרגום (שם שלב שנזרע) — ומה שהמשתמש כתב נשאר כמו שהוא
//   t('{n} קבצים', { n })              רבים: בתרגום {"one": …, "few": …, "other": …}
// ה-HTML הקבוע (טקסט, placeholder, title, aria-label, alt) מתורגם לבד — I18n.apply.
'use strict';

const I18n = (() => {
    const LANGS = {
        he: { name: 'עברית', dir: 'rtl', locale: 'he-IL' }, // i18n-none
        en: { name: 'English', dir: 'ltr', locale: 'en-GB' },
        fr: { name: 'Français', dir: 'ltr', locale: 'fr-FR' },
        ru: { name: 'Русский', dir: 'ltr', locale: 'ru-RU' },
        ar: { name: 'العربية', dir: 'rtl', locale: 'ar-u-nu-latn' },
    };
    const KEY = 'pm.lang';
    const VERSION = 67; // לשנות כשהמילונים משתנים — עוקף את המטמון של הדפדפן
    const base = (document.currentScript?.src || '').replace(/js\/i18n\.js.*$/, 'lang/');
    const ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
    let dict = {};

    function stored() { try { return localStorage.getItem(KEY) || ''; } catch { return ''; } }
    // ברירת המחדל — עברית, גם כשהטלפון באנגלית: מי שכבר עובד באפליקציה לא מופתע.
    // שפה אחרת — רק כשנבחרה (בבורר, בחשבון, או השפה של איש הקשר בפורטל).
    function detect() {
        const s = stored();
        return LANGS[s] ? s : 'he';
    }

    const lang = detect();
    const html = document.documentElement;
    html.lang = lang;
    html.dir = LANGS[lang].dir;

    // עד שהמילון נטען — הדף מוסתר (בלי הבהוב של עברית). לכל היותר 4 שניות.
    let style = null;
    if (lang !== 'he') {
        style = document.createElement('style');
        style.textContent = 'body{visibility:hidden}';
        document.head.appendChild(style);
    }
    const ready = (lang === 'he' ? Promise.resolve()
        : Promise.race([
            fetch(`${base}${lang}.json?v=${VERSION}`).then(r => (r.ok ? r.json() : {})).then(d => { dict = d || {}; }),
            new Promise(r => setTimeout(r, 4000)),
        ]).catch(() => {}))
        .then(() => new Promise(r => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', r, { once: true }) : r())))
        .then(() => { apply(document); style?.remove(); });

    const plural = new Intl.PluralRules(LANGS[lang].locale);

    function t(s, vars) {
        s = String(s ?? '');
        let out = s;
        if (lang !== 'he') {
            let tr = dict[s];
            if (tr && typeof tr === 'object') tr = tr[plural.select(Number(vars?.n) || 0)] ?? tr.other;
            if (typeof tr === 'string' && tr) out = tr;
        }
        if (!vars) return out;
        return out.replace(/\{([^{}]+)\}/g, (m, k) => {
            if (!(k in vars)) return m;
            const v = vars[k];
            return typeof v === 'string' && lang !== 'he' && v ? t(v) : String(v ?? '');
        });
    }

    const norm = s => s.replace(/\s+/g, ' ').trim();

    /** תרגום ה-HTML הקבוע: צמתי טקסט ותכונות. מה שמסומן data-no-i18n — לא. */
    function apply(root) {
        if (lang === 'he' || !root) return;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: n => (n.parentElement?.closest('script, style, textarea, [data-no-i18n]') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
        });
        for (let n = walker.nextNode(); n; n = walker.nextNode()) {
            const k = norm(n.nodeValue);
            if (!k || !(k in dict)) continue;
            const lead = n.nodeValue.match(/^\s*/)[0], tail = n.nodeValue.match(/\s*$/)[0];
            n.nodeValue = lead + t(k) + tail;
        }
        const els = root.querySelectorAll ? root.querySelectorAll(ATTRS.map(a => `[${a}]`).join(',')) : [];
        for (const el of els) {
            if (el.closest('[data-no-i18n]')) continue;
            for (const a of ATTRS) {
                const v = el.getAttribute(a);
                if (v && norm(v) in dict) el.setAttribute(a, t(norm(v)));
            }
        }
        if (root === document) document.title = t(norm(document.title));
    }

    /** החלפת שפה: נשמרת במכשיר, והדף נטען מחדש. */
    function set(l) {
        if (!LANGS[l]) return;
        try { localStorage.setItem(KEY, l); } catch { /* מצב פרטי */ }
        if (l !== lang) location.reload();
    }

    /** <select> לבחירת שפה — לכניסה, לכותרת ולפורטל. short — קוד קצר (EN), לכותרת הצרה בטלפון. */
    function picker(onChange = set, short = false) {
        const sel = document.createElement('select');
        sel.className = 'lang-pick';
        sel.setAttribute('aria-label', 'Language · שפה'); // i18n-none
        sel.setAttribute('data-no-i18n', '');
        sel.innerHTML = Object.entries(LANGS).map(([k, v]) => `<option value="${k}"${k === lang ? ' selected' : ''}>${short ? k.toUpperCase() : v.name}</option>`).join('');
        if (short) sel.title = LANGS[lang].name;
        sel.addEventListener('change', () => onChange(sel.value));
        return sel;
    }

    return {
        t, apply, set, picker, ready, LANGS,
        lang: () => lang, chosen: () => !!LANGS[stored()],
        locale: () => LANGS[lang].locale, dir: () => LANGS[lang].dir,
    };
})();

const t = I18n.t;
