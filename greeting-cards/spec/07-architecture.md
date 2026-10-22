# 07 — ארכיטקטורה וטכנולוגיה

## האילוצים

- **השרת:** cPanel, LiteSpeed, PHP 8.3. אין Node בשרת. פריסה ב-rsync מ-GitHub Actions, **בלי `--delete`**.
- **בנייה מותרת ב-CI** — GitHub Actions יכול להריץ `npm run build` ולפרוס רק את התוצר.
- **אפליקציית אנדרואיד** — WebView קיים, עם גשר לוואטסאפ ולאנשי קשר. נשמר.
- **עצמאות** — בלי תלות בקוד הפלטפורמה.

## צד לקוח

| נושא | בחירה | למה |
| --- | --- | --- |
| שפה ובנייה | **ES modules של הדפדפן, בלי שלב בנייה** — ספריות מקומיות ב-`editor/vendor/` | **הוכרע בשלב 1 (2026-10-22):** כמו שאר הריפו (למשל carpentry-3d). אין בנייה ב-CI ואין קובץ פריסה משותף לשנות. ספריות כבדות (pdf-lib, JSZip) נטענות רק כשצריך |
| ממשק | **DOM ישיר** עם עוזר `h()` (`editor/js/ui.js`) — בלי framework | הממשק סביב הקנבס הוא טפסים ורשימות. בלי `innerHTML` עם נתוני משתמש |
| מנוע הקנבס | **Konva.js** | שכבות, Transformer (ידיות), Pointer Events, ביצועים טובים בטלפון, ייצוא לפי pixelRatio. **חלופה:** Fabric.js v6. **בדיקה מוקדמת (spike):** טקסט עברי רב-שורתי ב-Konva |
| טקסט | **מנוע פריסה משלנו** מעל Canvas (`direction='rtl'`, `Intl.Segmenter` לשבירת שורות) + עריכה ב-`contenteditable` מעל הקנבס | טקסט עברי ומעורב (עברית + מספרים + אנגלית) חייב להיות זהה בעריכה, בתצוגה ובייצוא |
| מצב | Store אחד של המסמך (Immer או Zustand-vanilla) + היסטוריית פקודות | ביטול/חזרה, שמירה אוטומטית ושיתוף עתידי — מאותו מקור |
| אחסון מקומי | **IndexedDB** — עיצובים, תמונות כ-Blob (לא base64), פונטים | עבודה בלי רשת; לא מאבדים כלום ברענון |
| PDF | **pdf-lib + fontkit** — טקסט וקטורי, הפונט מוטמע (subset), תמונות כ-JPEG/PNG | עברית תקינה, קובץ קטן, הדפסה חדה |
| ZIP / אקסל | JSZip, ו-SheetJS **בגרסת mini** | נטענים רק כשצריך |
| עיבוד כבד | **Web Worker + OffscreenCanvas** — ייצוא בכמויות, פילטרים, הסרת רקע | המסך לא קופא |
| הסרת רקע | `@imgly/background-removal` (WASM, בדפדפן), נטען רק בלחיצה | חינם, בלי שרת |
| PWA | Service Worker (Workbox) עם precache של התוצר | פתיחה מיידית, עבודה בלי רשת |

## צד שרת

- **PHP 8.3**, נקודת כניסה אחת `api/index.php` עם ניתוב REST (`GET /api/designs`, `POST /api/designs/{id}`…).
  שכבות: Router → Middleware (הרשאה, קצב, CSRF) → Controller → Repository.
- **מסד:** MySQL בשרת (טבלאות `gc_*`). בפיתוח — SQLite. הגישה דרך PDO עם שאילתות מוכנות בלבד.
- **קבצים:** `data/` מחוץ לגישה ישירה (`.htaccess` deny), ומוגשים דרך `api/files/{id}` עם בדיקת בעלות. שמות
  קבצים = UUID, ולא שם שהמשתמש שלח. **`data/` חייבת `--exclude` ב-`deploy.yml`** — אחרת הפריסה דורסת נתוני
  משתמשים (כלל היסוד).
- **עיבוד תמונה בהעלאה:** GD/Imagick — פענוח מחדש, הסרת EXIF, הקטנה לגודל מרבי, יצירת תמונה ממוזערת (WebP).
- **AI:** `api/ai/*` → `ProviderAdapter`. משימות ארוכות — רשומת job, והלקוח שואל על המצב (או SSE).

### טבלאות עיקריות

```
gc_users        id, email, name, role(owner|user), status, created_at, last_login_at
gc_sessions     id, user_id, token_hash, expires_at, user_agent
gc_designs      id, user_id, title, doc_json, thumb_file_id, width, height, updated_at, deleted_at
gc_design_versions id, design_id, doc_json, created_at, reason(auto|manual|export)
gc_files        id, user_id, kind(image|font|thumb|gcard), mime, bytes, sha256, path, created_at
gc_fonts        id, user_id(null=מערכת), family, weight, style, file_id
gc_templates    id, owner_id(null=מערכת), category, doc_json, thumb_file_id, published
gc_recipients   id, user_id, list_name, rows_json        (או טבלת שורות, אם הרשימות גדולות)
gc_contacts     id, user_id, name, phone_e164, source
gc_chat_imports id, code, user_id(null עד שיוך), files_json, design_json, created_at, expires_at
gc_ai_quota     user_id, monthly_credits, daily_cap, enabled, providers_json
gc_ai_ledger    id, user_id, delta, reason, action, provider, cost_usd, request_id, created_at
gc_ai_jobs      id, user_id, action, status, prompt, result_file_ids, error, created_at
gc_settings     key, value_encrypted
gc_audit        id, actor_id, action, target, meta_json, created_at
```

## אבטחה

- **עוגיית סשן** `gc_session`: `HttpOnly`, `Secure`, `SameSite=Lax`. באסימון נשמר רק hash. **אין** אסימון בכתובת.
- CSRF: כותרת `X-CSRF` לכל בקשה שכותבת. CORS — רק המקור של האפליקציה.
- כל מזהה = UUID שנבדק בתבנית. בעלות נבדקת בכל גישה לנתונים.
- העלאות: בדיקת סוג אמיתית (magic bytes), פענוח מחדש, SVG מנוקה (או נאסר), גודל מרבי, מכסת אחסון למשתמש.
- מפתחות API של ספקי AI — מוצפנים (libsodium) במסד, והמפתח להצפנה בקובץ מחוץ לתיקיית האתר.
- בצד הלקוח: **אין `innerHTML` עם נתוני משתמש** — רק רכיבי Preact או `textContent`. CSP מחמיר.

## תקציבי ביצועים (נבדקים ב-CI עם Lighthouse)

| מדד | יעד |
| --- | --- |
| JS ראשוני (gzip) | ≤ 150KB, כולל Konva (בפועל ~75KB: Konva ~55KB + קוד העורך) |
| מסך ראשון (LCP, 4G, טלפון בינוני) | ≤ 2 שניות |
| זמן עד שאפשר לגעת (INP) | ≤ 200ms |
| גרירה וזום | 60fps בטלפון בינוני (בערך Galaxy A54) |
| ייצוא PNG בודד 1080×1350 | ≤ 1 שנייה |
| 200 כרטיסי PDF | ≤ 60 שניות, בלי קפיאה, עם התקדמות |
| זיכרון בזמן ייצוא בכמויות | קבוע — כל קובץ נכתב ל-ZIP בסטרימינג ומשתחרר |

## אנדרואיד

- **נשאר WebView**, בגלל הגשר לוואטסאפ ולאנשי קשר. מתוקן:
  - הורדות: הייצוא נשמר דרך הגשר (`saveFile(name, mime, bytes)` ל-MediaStore), ולא דרך `blob:`.
  - `ACTION_SEND` ← "ייבוא מהצ'אט" (שיתוף תמונה או טקסט מ-ChatGPT/Gemini ישירות לאפליקציה).
  - זום: `setSupportZoom(false)` — הזום בתוך הקנבס, כך שאין התנגשות.
  - הגשר לוואטסאפ: מזהה איש קשר (ולא שם תצוגה), וקבצים דרך FileProvider (ולא base64 ענק בגשר).
- **iOS:** PWA בשלב הראשון. אפליקציה — רק אם יידרש.

## מבנה התיקייה

```
greeting-cards/
  app.json, index.html (המעטפת), manifest.webmanifest, sw.js
  editor/            ← התוצר של Vite (נבנה ב-CI; המקור ב-editor-src/)
  editor-src/        TypeScript: core/ (מודל, פקודות, היסטוריה) · canvas/ · ui/ · export/ · workers/
  api/               PHP: index.php, src/, migrations/
  data/              נתוני משתמשים — לא בגיט, ‎--exclude‎ בפריסה
  skill/             הסקיל לצ'אטים (נבנה מ-spec/skill לפי משתמש)
  android/           כמו היום
  legacy/            הגרסה הקיימת, עד שהחדשה מחליפה אותה
  spec/              האפיון הזה
```

## בדיקות

- יחידה (Vitest): מודל המסמך, פקודות, ביטול/חזרה, פריסת טקסט עברי, אימות `design.json`.
- **ויזואליות (Playwright):** כל תבנית מרונדרת ומושווית לצילום ייחוס, ב-390px וב-1280px. ה-PDF מומר לתמונה
  ומושווה.
- מחוות: Playwright עם מגע (צביטה, גרירה, גלילה מחוץ לקנבס).
- נגישות: axe-core בכל מסך, ובדיקה ידנית עם TalkBack.
- שרת: PHPUnit להרשאות — **כל endpoint נבדק גם עם משתמש אחר** (שאין לו גישה).
