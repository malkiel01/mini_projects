# איחוד PDF ותמונות

כלי לאיחוד קבצי PDF ותמונות (JPG, PNG, WEBP) לקובץ PDF אחד.
הכול רץ בדפדפן — הקבצים לא עולים לשרת.

## יכולות

- גרירת קבצים או בחירה מהמחשב
- תמיכה ב-PDF (כולל מרובה דפים), JPG, PNG, WEBP
- שינוי סדר בגרירה
- תצוגה מקדימה של דפים
- סיבוב דפים בודדים
- הגדרות: גודל דף, כיוון, איכות תמונות
- פיצול PDF לדפים בודדים
- מונה דפים כולל

## טכנולוגיות

- pdf-lib — יצירה ואיחוד של PDF
- pdf.js — רנדור תצוגה מקדימה

שתי הספריות (ו-`pdf.worker.min.js`) יושבות ב-`assets/vendor/`, ולא נטענות מ-CDN — כדי שהכלי יעבוד גם בלי
רשת. הקבצים זהים לגרסאות ב-npm: pdf-lib 1.17.1, pdfjs-dist 3.11.174.

## התקנה (rollout צעד 6)

שלוש דרכים, כמו המתכונים:

- **PWA** — `manifest.webmanifest` ו-`service-worker.js`. ה-service worker שומר מראש את כל מה שצריך לעבודה
  בלי רשת (הדף, העיצוב, הקוד והספריות); רשת קודם, ותשובה שהופנתה (השער שולח לכניסה) לא נשמרת. אחרי שינוי
  ברשימה — להעלות את `CACHE_VERSION`.
- **אנדרואיד (TWA)** — `android/`, הועתק מ-`recipes-app/android/`. חבילה `com.mbeplus.pdfmerger`, בנייה ב-
  `.github/workflows/pdf-merger-twa.yml`.
- **תיקיית חנות** — Release `pdf-merger-store`: APK, AAB, `store-listing.zip`, `assetlinks.json`, `build.json`.
  הטקסטים לחנות ותשובות המדיניות: `android/STORE.md`. מדיניות פרטיות: `privacy.html`.

כתובות ציבוריות (`public` ב-app.json): המניפסט, ה-service worker, האייקונים ו-`privacy.html`. השאר — מאחורי השער.

## מה טרם הוכרע

- הוספת header/footer לכל דף
- סידור כמה דפים על דף אחד (2-up, 4-up)
- הגנה בסיסמה
- מספור דפים אוטומטי
