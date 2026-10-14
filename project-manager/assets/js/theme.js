/**
 * ערכת נושא — בהירה, כהה או לפי המכשיר (הגדרות ← תצוגה). נטען ראשון ב-<head>, לפני ה-CSS, כדי שהדף לא יהבהב.
 * הבחירה נשמרת למשתמש בשרת (users.theme — עוברת איתו בין מכשירים) ובמכשיר (localStorage — לטעינה הבאה).
 * בלי בחירה (system) — אין data-theme, וה-CSS הולך לפי prefers-color-scheme.
 */
const Theme = (() => {
    const KEY = 'pm-theme', ALL = ['system', 'light', 'dark'];
    function read() { try { return localStorage.getItem(KEY) || 'system'; } catch { return 'system'; } }
    function apply(v) {
        v = ALL.includes(v) ? v : 'system';
        if (v === 'system') document.documentElement.removeAttribute('data-theme');
        else document.documentElement.dataset.theme = v;
        try { if (v === 'system') localStorage.removeItem(KEY); else localStorage.setItem(KEY, v); } catch { /* מצב פרטי */ }
        return v;
    }
    apply(read());
    return { get: read, apply, ALL };
})();
