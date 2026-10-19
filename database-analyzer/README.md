# אנליסט מסד נתונים

כלי לניתוח וניהול מסד MySQL: מבנה הטבלאות, התוכן, הקשרים (רשימה ותרשים), הוספה ומחיקה של שורות
ועמודות, סקריפטי SQL, ייצוא, ריקון ושכפול מסד. פרטי החיבור נשמרים בזיכרון הדף בלבד ועוברים בכל
בקשה ל-`api.php`, שמתחבר למסד ומחזיר JSON.

| קובץ | מה |
| --- | --- |
| `index.html` | כל הממשק |
| `api.php` | הפעולות מול המסד (PDO) |
| `manifest.webmanifest`, `service-worker.js`, `assets/` | התקנה כאפליקציה (PWA) |
| `android/` | אפליקציית אנדרואיד (TWA) ותיקיית החנות — `android/STORE.md` |

## מאחורי השער של הפלטפורמה

`"gate": true`. ציבוריים (`"public"` ב-`app.json`) — רק מה שהדפדפן מוריד בלי עוגיות כדי להתקין:
המניפסט, ה-service worker והסמלים. `api.php` והדף עצמו — רק עם הרשאה בפלטפורמה.

## שלוש דרכי התקנה (rollout, צעד 6)

- **PWA** — `manifest.webmanifest` ו-`service-worker.js` (רשת קודם; `api.php` לעולם לא נשמר במטמון).
- **אנדרואיד (TWA)** — `com.mbeplus.dbanalyzer`, עטיפה סביב `https://mbe-plus.com/mini_projects/database-analyzer/`.
  נבנית ב-`.github/workflows/database-analyzer-twa.yml` בכל שינוי ב-`android/`.
- **תיקיית החנות** — Release `database-analyzer-store`: `database-analyzer.apk`, `database-analyzer.aab`,
  `store-listing.zip`, `assetlinks.json`, `build.json`.

## מה טרם הוכרע

- **`android/` עולה לשרת בפריסה** — `deploy.yml` אינו מחריג אותה (כמו `recipes-app/android/`), כי הצ'אט הזה
  נוגע רק בתיקייה שלו. עד שתתווסף `--exclude='database-analyzer/android/'` — `android/.htaccess` חוסם גלישה אליה.
- **מסך הטלפון** — נוספה פריסה בסיסית לרוחב צר (הרשימה מעל התוכן). הממשק עדיין בנוי בעיקר למחשב.
