# git-deploy

ממשק לפריסת ריפוזיטוריז מ-GitHub לשרת: בוחרים ריפו, branch ותיקיית יעד, והממשק מבצע clone/pull.
שומר את הניתובים לכל ריפו ב-`data/config.json` (מוחרג מהפריסה ב-`deploy.yml`).

## כניסה

מאחורי השער של הפלטפורמה (`"gate": true`). משתמש פלטפורמה עם הרשאה מדלג על `login.php`, וחסימת ה-IP
לא חלה עליו. אפליקציה מנותקת — הסיסמה וחסימת ה-IP חוזרות לעבוד (`lib/auth.php`).

## התקנה (צעד 6 ב-`platform/docs/rollout.md`)

- **PWA** — `manifest.webmanifest`, `service-worker.js` (רק קבצים סטטיים; דפי PHP וה-API תמיד מהשרת),
  התגים ב-`<head>` מ-`lib/pwa.php`. המניפסט, ה-service worker והאייקונים ב-`"public"`.
- **אנדרואיד (TWA)** — `android/`, חבילה `com.mbeplus.gitdeploy`, בנייה ב-`.github/workflows/git-deploy-twa.yml`.
- **תיקיית חנות** — Release `git-deploy-store` (APK, AAB, `store-listing.zip`, `assetlinks.json`, `build.json`).
  הטקסטים וההוראות: `android/STORE.md`.

האייקונים נוצרים מ-`assets/icon.svg`.

## מה טרם הוכרע

- דף מדיניות פרטיות — נדרש רק אם האפליקציה תפורסם ב-Play.
