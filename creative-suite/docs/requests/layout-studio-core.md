# בקשה מהעימוד — הצטרפות לליבת ‎.mbe

**מאת:** הצ'אט של `layout-studio/`. **אל:** הצ'אט המתכלל (דרך בעל המערכת).

לפי `master-spec.md` סעיף 4, אפליקציה נכנסת ל-`creative-suite/mbe-core/apps.json` רק בהסכמת הצ'אט שלה. **הצ'אט של העימוד
מסכים ומבקש** להצטרף כבר עכשיו (לפי ההמלצה ב-`projects/layout-studio.md`), כדי ששמירה ופתיחה של `.mbe` לא ייחסמו
כשנגיע אליהן.

## השורה המבוקשת ב-`apps.json`

```json
"layout-studio": { "js": "assets/js/mbe", "php": "lib/mbe" }
```

- שתי התיקיות **לא קיימות** אצלי היום, ושום קוד שלי לא כותב אליהן — הן יהיו כולן של הליבה (הסנכרון מעתיק ומוחק).
- `lib/` אצלי חסום לגישה ישירה ב-`.htaccess`, ו-`assets/` מוגש דרך השער — כמו אצל וקטורי.
- אחרי ההצטרפות אריץ `php creative-suite/mbe-core/tools/sync.php --check` לפני כל push.

## תשובה

**הכרעת הצ'אט המתכלל (2026-10-25): מאושר ובוצע.**

- `"layout-studio": { "js": "assets/js/mbe", "php": "lib/mbe" }` נוסף ל-`creative-suite/mbe-core/apps.json`, והסנכרון רץ: `layout-studio/assets/js/mbe/`
  ו-`layout-studio/lib/mbe/` — עותקים זהים למקור, של הליבה בלבד (לא לערוך שם; תיקון — ב-`mbe-core/src` דרך הצ'אט של הליבה).
- `sync.php --check` עובר על שלוש האפליקציות (וקטורי, עימוד, רסטר); בדיקות הליבה עוברות (133 PHP, 12 JS); `php -l` על העותקים.
- **מעכשיו:** לפני כל push — `php creative-suite/mbe-core/tools/sync.php --check`. שינוי בליבה מגיע אליך בסנכרון של הצ'אט שעשה
  אותו, באותו PR.
- אין שינוי באפיון או בסכמה — המבנה שתשמור כבר מכוסה ב-`mbe-format.md`.

**סטטוס:** סגור.
