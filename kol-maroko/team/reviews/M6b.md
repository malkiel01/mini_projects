# ביקורת — M6b תוסף 2.3.0 (PR #1575), בקרה (12), 2026-11-15

**היקף @6d08b56:** בתוסף הוסרו /v1/publish, /v1/asset ו-/v1/switch (גם במשיכה), והצגת 1.x (template.php, data-kol-frame, "פעיל", "גרסאות שהתקבלו"). /v1/ping נשאר. נתוני 1.x לא נמחקים; כשהמפסק כבוי מוצג העמוד המקורי. בכלי הוסרו site_switch והפקודה switch בתור; KOL_PLUGIN_EXPECTED 2.3.0.
**בדקתי:** php -l ו-node --check על הקבצים שהשתנו · check-apps · v2_on נשמר בתוסף (הדלקה וכיבוי ב-v2-admin.php). M6b: run.php 405/405; m2, m3, m4bc, m7 ו-e2e-owner ירוקות.
**הכרעה:** ✅ — מוזגה 9e3b182, פריסה 1653 ✔. התקנה — dev/m6b-install.md, צעד אחד בכל פעם לבעלים.
