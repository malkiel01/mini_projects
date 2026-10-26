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
