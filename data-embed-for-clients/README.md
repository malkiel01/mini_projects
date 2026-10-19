# הטמעת נתונים לריכוז לקוחות

תבנית אחת (תמונה או PDF) עם שדות טקסט, תמונה וחתימה — והפקת PDF אישי לכל לקוח, בהורדה אחת כ-ZIP.
אפשר גם לשמור תבנית ולהפיק ממנה דרך API, ולשלוח ללקוח קישור לחתימה (`sign.html`, מעקב ב-`track.html`).

## התקנה (צעד 6 בפלטפורמה)

שלוש דרכים, כמו המתכונים:

| דרך | איפה |
| --- | --- |
| **PWA** | `manifest.webmanifest` + `service-worker.js` (רשת קודם; `api.php` ותיקיות הנתונים — תמיד מהשרת) |
| **APK** | Release `data-embed-for-clients-store` → `data-embed-for-clients.apk` |
| **תיקיית חנות** | אותו Release: AAB, `store-listing.zip`, `assetlinks.json`, `build.json` |

האנדרואיד הוא TWA (`android/`, חבילה `com.mbeplus.dataembed`) שנבנה ב-
`.github/workflows/data-embed-for-clients-twa.yml`. הפירוט ל-Play Console: `android/STORE.md`.

## נתונים בשרת

`fonts/`, `templates/`, `signed/` — נוצרים בשרת, אינם בריפו.

## מה טרם הוכרע

- דף מדיניות פרטיות — חובה לפני הגשה לחנות.
