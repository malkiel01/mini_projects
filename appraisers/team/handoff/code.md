# העברה — צ'אט הקוד

נכתב בידי קוד (2) ב-2026-11-10 16:55 UTC, לבקשת הבקרה, לקראת קוד (3). קוד (2) (`session_018g4NYFs9fDdKejES8Dp6rw`)
מפסיק לפעול אחרי סבב 2 של #1379.

## איפה עומדים

| פרוסה | מצב |
| --- | --- |
| 01, 02 | ב-main (0.3) |
| 03 פרופילים | PR #1379, סבב 2 נדחף — ממתין לבקרה. גרסה 0.4 |
| 04 תיקים | **בעבודה, בענף `wip-04`** — שרת ובדיקות כתובים, **לא הורצו** |

## פרוסה 04 — מה יש ב-`wip-04`

הענף יוצא מ-`claude/appraisers-code` **לפני** סבב 2 של 03. קודם כול: `git merge origin/main` (אחרי ש-#1379 מוזג) —
צפויות התנגשויות קלות ב-`lib/access.php`, `lib/app.php`, `lib/errors.php`, `lib/offices.php` (שני הצדדים הוסיפו שורות).

- `lib/migrations/005_cases.sql` — כל הטבלאות של 04 §3, ועוד `requirements.party_kind`, `case_identifiers.match_key`,
  `source_note`, `removed_at`, ו-`audit_log.case_id`.
- `lib/cases.php` — `apr_case_get()` (ההרשאה לתיק, במקום אחד), מספר תיק, פתיחה (`case-create`), `case-update`, `case-plan`,
  `case-start` (יוצר דרישות ומשימות מהפרופיל), `case-status`, `case-delete`, משתתפים והרשאות, שורות הניהול והעברת אחריות
  למנהל משרד, והחלת גרסת פרופיל (`case-profile-diff`, `case-profile-apply`). `apr_cases_on_member_out()` נקרא מ-`offices.php`.
- `lib/case_parts.php` — מזהים (כולל "מזהה דומה"), ספר כתובות, גורמים, משימות, דרישות, כרטיס התיק, רשימה וחיפוש, פעילות.
- `lib/access.php`, `lib/app.php`, `lib/errors.php`, `lib/audit.php` (`case_id` דרך `$GLOBALS['apr_audit_case']`) — מחוברים.
- `tests/i-cases.test.php` — בדיקה לכל 🤖 של 04 ולמקרי הקצה, כולל 100 פתיחות מקבילות ב-10 תהליכים על קובץ מסד.
  **נכתב בלי להריץ** — צפויות שגיאות גם בבדיקה וגם בקוד. `php -l` עובר על הכול; 10 הקבצים הקיימים ירוקים עם הקוד החדש.

## מה נשאר ב-04

1. להריץ `php appraisers/tests/i-cases.test.php`, לתקן עד ירוק, ואז מוטציות לכל בדיקת הרשאה.
2. **להעתיק מ-03 (הכרעות הביקורת):** פריט שכלל מוסיף אינו נושא `when`; `origin_key` ייחודי גם בין פריטי כללים — `case-start`
   ו-`case-profile-diff` מניחים את זה. אסימון הטיוטה בפרופיל הוא `revision` — הבדיקה ב-`i-cases` (`publish()`) עוד שולחת
   `updated_at` וקוראת ל-`profile-publish` בלי `revision`: **לעדכן** (ראו `rev()` ב-`h-profiles.test.php`).
3. `d-audit.test.php` — רשימת קודי הפעולה המותרים: להוסיף את `case.*`, `task.*`, `requirement.*`, `contact.*`.
4. המסכים (04 §9): לוח ראשי ב-`#/`, `#/cases`, אשף `#/cases/new` ו-`#/cases/<pid>/setup`, כרטיס תיק עם לשוניות (סקירה ·
   גורמים והרשאות · דרישות · פעילות — לשונית של פרוסה שלא נבנתה אינה מוצגת), `#/tasks`, `#/contacts`, `#/office/cases`,
   תצוגת הבדלים בהחלת גרסה. טקסטים — ב-`strings.js` (`T.c`, כמו `T.p`).
5. גרסה 0.5 (`app.json`, `?v=`), README (טבלת הקבצים, כללים, סטיות), `dev/open-items.json` AP-004, יומן.
6. לא נבנה ולא יוצהר אחרת: הגדרת המשרד "מנהלים משתתפים בכל תיק" (04 §6.1, כבויה כברירת מחדל) — לרשום ב-README כסטייה,
   או לבנות. מסנני הרשימה קיימים בשרת (`cases`: `status`, `responsible`, `property_type`, `orderer`, `due_before`,
   `open_mandatory`, `closed`).

## הכרעות שכבר התקבלו (לא לפתוח מחדש)

- רשימות מטרת שומה וסוג נכס: `APR_PURPOSES`, `APR_PROPERTY_TYPES` ב-`lib/profile_content.php` (03 §4.4). "אחר" מחייב הערה.
- שיתוף פרופיל עם משרד אחר — פרוסה 05, לפי מייל של מנהל פעיל.
- שאלות אפיון — **לבקרה בלבד**; צ'אט האפיון רדום.

## מלכודות

- **`PDO::inTransaction()` לא רואה `BEGIN` שנשלח ב-`exec`.** `apr_tx()` מחזיק דגל משלו; טרנזקציה מקוננת מצטרפת לחיצונית.
- **regex על קלט: `\z` (או `D`), לא `$`** — `$` מתאים גם לפני ירידת שורה אחרונה.
- **פרמטר GET בשם `action` תפוס** בידי הנתב.
- **שום כתובת מהבקשה** — רק `apr_app_url()` (מ-`app.json`); בבדיקות `APR_APP_URL`.
- **בדיקות:** `'1' = 1` ב-SQLite הוא שקר כשהפרמטר נקשר כמחרוזת — לא להשוות פרמטר למספר ב-SQL. `$GLOBALS['apr_time']` מזיז
  את השעון; מונים של `apr_limit` נשארים בין קטעי בדיקה (לנקות `login_attempts` לפי קידומת).
- **כלים:** פקודת Bash עם heredoc שמכיל גם backticks וגם `$$` נחסמת כ"rm מסוכן" — קבצים גדולים כותבים ב-Write. אחרי `sed`
  / סקריפט ששינה קובץ — אין צורך לקרוא אותו שוב כדי להריץ, רק כדי לערוך ב-Edit.
- **גרירה במסך:** מאזינים ל-`pointermove` / `pointerup` על `document` — הזזת האלמנט ב-DOM מבטלת `setPointerCapture`.
- **בדיקת דפדפן:** `php -S 127.0.0.1:PORT -d auto_prepend_file=appraisers/tests/dev-prepend.php -t appraisers` עם `APR_DATA_DIR`
  זמני, ו-Playwright (מותקן; `chromium.launch()` בלי אפשרויות). העוגייה `dev_as`: `owner` / `none` / `detached` / `sub:שם:מייל`.
- **לפני כל push:** `git fetch origin main`, ולבדוק שה-PR של הענף עדיין פתוח. PR שמוזג → `git merge origin/main` ו-PR חדש.
  הודעות מהבקרה מגיעות רק בסוף תור — תורות קצרים, ו-`ReadNotifications` בתחילת כל שלב.
- **הענף הקבוע `claude/appraisers-code` = PR אחד.** עבודה על הפרוסה הבאה לפני מיזוג — בענף מקומי, ולדחוף אותו (`wip-NN`)
  כגיבוי רק באישור הבקרה.
