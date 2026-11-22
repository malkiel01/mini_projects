# ביקורת — פרוסה 11 (PR #1805, + #1806 cron)

בקרה (3), 22.11 ~22:30 UTC, עם סוקר עומק (כניסה, חתימות, הרשאות). סבב 1 — **לתיקון (🟡), בלי 🔴**.

🤖 run.php 15/15 (o-launch 82), check-apps, sync --check, php -l, node --check — ירוקים; רק `sforno/`, `app.json` לא נגע. בלי `data/launch.json`
— שלוש הפעולות `unknown_action` לפני כל דבר, `sf_authenticate` כמו 01, לא נוצר מפתח. נמצא תקין: Ed25519 על ה-payload, מפתח מוצמד ב-launch.json
(בלי fetch), aud/iss/return/exp/sub, state ב-`hash_equals` ונמחק תמיד; אין open redirect; session חדש בכניסה, עוגיות Secure/HttpOnly/Lax;
json מדויק + same_site + קצב; grant_status טרי בכניסה, 5 דק' בבקשות, נכשל סגור (503), curl https בלי הפניות; `launch` לא מרחיב פעולה קיימת.

## 🟡 לתיקון
1. **שינוי באתר החי בצד הדפדפן** (`assets/js/app.js:58-67`): כתובת עם `?state=&token=` / `&error=` שולחת `sso-finish` → 404 → מסך שגיאה,
   ו-`replaceState` מוחק פרמטרים אחרים. תיקון: `unknown_action` (או `sf_launch` כבוי) ← טעינה רגילה, בלי שגיאה ובלי מחיקת פרמטרים.
2. **אין דרך להסיר "מפתח" במצב המנותק** (`lib/auth.php:65`, `lib/sso.php:194-197`): `is_developer` קפוא מההעתקה. תיקון בפרוסה הזו: נוהל
   ב-`dev/launch-check.md` + handoff (ביטול הרשאה / `UPDATE staff SET is_developer=0` דרך CLI); מתג במסך המנהלים — לאפיון, אחרי "עוברים".

## ⚪ באותו תיקון (קטנים)
3. state לא אטומי — `DELETE ... WHERE k=? AND v>=?` + `rowCount()===1`.
4. יצירת זוג המפתחות לא אטומית — INSERT שנכשל בהתנגשות ← קריאה חוזרת.
5. `iss` מדויק — בהוראות handoff: "`platform` = `issuer` מ-`sso.php?action=key`".
6. הטוקן ב-query → ביומן הגישה — חריג מתועד ב-`launch-check.md` (חד-פעמי, 2 דק', קשור לעוגייה).
7. `sessionStorage.setItem` ב-`router.js:76` — לעטוף ב-try.
