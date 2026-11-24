# ביקורת 04 — עובד שטח (PR #1853)

## סבב 1 — 2026-11-24 (סוקר עומק, על 7326fd3)

**בדיקות:** `tests/run.sh` על עותק הענף — EXIT 0 (A, B, W1/W5/W7, 05, 03, field.test.php, field-dropbox.test.php). אין התנגשות מול main.
**הבקרה אימתה בקוד את שני ה-🔴** (`lib/field.php:204/248-252`; `docs/spec/04-field.md:21` מול README "לא נבנה").

**תקין:** org_id בכל שאילתה (חוץ מ-9), 404 לארגון אחר; CSRF בכל POST; `field_save` רק `TRACK_FIELDS` (W5); `need()` —
`case.view`/`case.track`; העלאה: `is_uploaded_file`, 50MB, `finfo`, בלי SVG/HTML, `basename`+`dbxSafeName`; מזהה חד-פעמי
בשתי שכבות; `textContent` בממשק; הקטנה 2560/JPEG 85, GPS נשמר ושאר EXIF נמחק, `imageOrientation: from-image`; Dropbox מנותק → 409 + `blocked`.

| # | חומרה | ממצא | התיקון |
| --- | --- | --- | --- |
| 1 | 🔴 | **העלאה דורסת את התיק בגרסה ישנה.** `$case` נקרא ב-`lib/field.php:204` לפני ההעלאה (שניות–עשרות שניות); ב-`:248-252` `$raw = $case` → `formSaveCase` → `store()` כותב את כל הגוף. עריכה של העובד/המשרד בזמן ההעלאה (קבר, הערה, **שם, תשלומים**) נמחקת בשקט, ונרשמת ב-events כשינוי של `field` | לקרוא מחדש **אחרי** ה-uploader, בתוך `BEGIN IMMEDIATE`: `existing()` טרי, הסטטוס נבדק עליו, משנים רק `checks[$check]`. בדיקה: uploader מדומה שמשנה את התיק באמצע — השינוי שורד |
| 2 | 🔴 | **חובה שהוצהרה "לא נבנתה":** אפיון §2.4 "נשלח כשחוזרת רשת — גם אחרי סגירת המסך"; אין service worker, התור נשלח רק כשהאפליקציה פתוחה | **הכרעת הבקרה:** service worker מינימלי עם Background Sync (`sync` tag) שקורא מאותו `fd_field` ב-IndexedDB ושולח באותו `upload_id` — אנדרואיד/Chrome; בלי תמיכה (iOS) — השליחה בפתיחה הקיימת, ושורה ב-README. ה-SW בתוך `funerals-dashboard/` בלבד, ולא מאחסן שום דבר מחוץ לתור |
| 3 | 🟡 | ביטול בזמן `flush` לא מבטל — `field-core.js:179` עותק בזיכרון; `save()` (`:184`) מחזיר את הפריט שבוטל | לקרוא כל פריט מחדש מהמחסן לפני שליחה; לא לשמור פריט שבוטל |
| 4 | 🟡 | `enqueue` (`field.js:235-245`) קורא `current` אחרי `await prepareImage` — דיאלוג סגור → TypeError ותמונה אובדת; תיק אחר נפתח → הצילום לתיק האחר | לשמור `{id,name,kinds}` לפני ה-await; try + toast |
| 5 | 🟡 | העלאה מעל 120 שניות (`lib/field.php:219`) → בקשה שנייה מעלה במקביל; קובץ כפול ב-Dropbox ו-500 על `idx_files_upload` | heartbeat ל-`updated_at` בין חלקים, או חלון לפי גודל (≥10 דק') |
| 6 | 🟡 | פריט בתור בלי `org_id` (`field-core.js:145`) — החלפת ארגון → 404 לצמיתות; פריטים של משתמש אחר נשארים במכשיר משותף | `org` בפריט, שליחה רק בארגון התואם; ניקוי פריטים של משתמש אחר ביציאה / אחרי N ימים |
| 7 | 🟡 | `fieldAllowedFile` (`:289-298`) בודק MIME בלבד — JPEG בשם `x.html`, `octet-stream`+`.doc` לכל בינארי; `grave_photo` מקבל PDF ונשמר `.jpg` | `dbxAllowedUpload()` של 03; `grave_photo` → `image/*` בלבד |
| 8 | 🟡 | `field_uploads` נוצרת עצלה ב-`fieldSchema()` (`:38-48`), בלי גרסה וגיבוי (כמו #3 ב-03) | מיגרציה ממוספרת ב-`lib/schema.php` (v3 או הבאה הפנויה — לתאם עם 05/06) |
| 9 | ⚪ | `:284` `UPDATE files … WHERE id = ?` בלי `org_id` | `AND org_id = ?` |
| 10 | ⚪ | ניסיון חוזר רושם `field_upload` שוב (`:258`); `kind` חופשי (`:202`); `ON CONFLICT` לא מעדכן `user_id` | אירוע רק לקובץ חדש; `kind` מ-`fieldKinds($case)` |
| 11 | ⚪ | שם צילום הקבר לפי שעת ההגעה לשרת (`:182`) | `taken_at` מהדפדפן, בגבולות סבירים |
| 12 | ⚪ | PNG/WebP/HEIC קטנים נשלחים עם כל המטא-נתונים (`field-core.js:113-119`) | לתעד ב-README |
| 13 | ⚪ | `fieldSearch` (`:111`) טוען את כל התיקים | מספיק לעכשיו |
| 14 | ⚪ | שני טאבים — `flush` מקביל (`:181`) | `navigator.locks` |
| 15 | ⚪ | HTML של השער עם 200 → "נכשל (200)" ידני | 200 בלי JSON → later, "צריך להתחבר מחדש" |

**קריטריונים:** W1, W2, W6, W7 ✅; W3 ✅ (⚠5); W4, W5 ✅ (⚠1); W8 ידני (תלוי ב-2).

**החלטה:** חוזר לקוד — 1–8 בסבב הזה (⚪ לפי שיקול, 9 ו-15 מומלצים). סבב 2 — הבקרה.

## סבב 2 — 2026-11-24 (בקרה, על 214f74b9)

- 🔴1 ✅ `lib/field.php`: אחרי ה-uploader — `BEGIN IMMEDIATE`, `existing()` טרי, משנים רק `checks[$check]`; האירוע ו-done באותה
  טרנזקציה. `store()` לא פותח טרנזקציה משלו (רק `importRecords`), כך שאין קינון. בדיקה: uploader מדומה ששומר שם/תשלום/הערה — שורדים.
- 🔴2 ✅ `sw.js`: `sync` בלבד, בלי fetch handler ובלי מטמון; אותו `fd_field` ואותו `upload_id`; נעילה משותפת (`navigator.locks`)
  עם הדף. ב-`public` — קובץ סטטי בלי סודות, מותר. iOS — בפתיחה, ב-README. נבדק ב-Playwright (dispatchSyncEvent).
- 🟡3–8 ✅ לפי הדיווח והבדיקות ב-run.sh (415 ל-JPEG בשם .html ול-PDF כצילום קבר; v3 ב-`lib/schema.php`).
- ⚪ 9–12, 14, 15 תוקנו; 13 נשאר.
- **לתיאום:** 04 לוקחת v3. 06 לא משנה סכמה — אם תוסיף, v4. 04 ו-06 מתנגשות ב-merge-tree — מי שממוזג שני מיישר.

**החלטה:** ✅ למזג כשה-CI ירוק.
