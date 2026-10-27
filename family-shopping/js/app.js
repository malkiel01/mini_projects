// js/app.js — מה שכל מסך צריך (שפת העיצוב של הכלים הדיגיטליים)

// --vvh: הגובה הנראה בפועל, בלי המקלדת. החלון (modal) בטלפון הוא גיליון
// מלא, והוא מתקצר כשהמקלדת נפתחת - כך שדה וכפתור השמירה לא נעלמים מתחתיה.
(() => {
    const vv = window.visualViewport;
    if (!vv) return;
    const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
    vv.addEventListener('resize', set);
    set();
})();

// תפריט "עוד" בכותרת: נפתח בלחיצה, נסגר בלחיצה בחוץ, ב-Escape ואחרי בחירה
function toggleTopMenu(button) {
    const menu = button.nextElementSibling;
    const open = menu.hidden;
    closeTopMenus();
    if (open) {
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        menu.querySelector('[role="menuitem"]')?.focus();
    }
}

function closeTopMenus() {
    document.querySelectorAll('.top-menu').forEach(menu => {
        menu.hidden = true;
        menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
    });
}

document.addEventListener('click', event => {
    if (!event.target.closest('.menu-wrap') || event.target.closest('[role="menuitem"]')) closeTopMenus();
});
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeTopMenus(); });
