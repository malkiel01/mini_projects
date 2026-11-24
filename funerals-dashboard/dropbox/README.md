# Dropbox לארגון (פרוסה 03)

האפיון: `docs/spec/03-dropbox.md`. קובץ שמועלה באפליקציה נמצא בתיקיית התיק ב-Dropbox, וקובץ שנוסף לתיקייה ב-Dropbox
מופיע בתיק — עם תצוגה מקדימה דרך השרת.

| קובץ | תפקיד |
| --- | --- |
| `dropbox/lib.php` | טבלאות, לקוח HTTP (רענון אסימון, RPC, content, upload בחלקים), הגבלה לראשית, סנכרון ב-cursor, תיקייה לתיק, סריקת התאמה |
| `dropbox/actions.php` | פעולות `api.php` (`dropbox_*`, `case_files`, `file_view`) — החיבור היחיד ב-`api.php` הוא שתי שורות |
| `dropbox/compat.php` | נקודת החיבור היחידה ל-01: `secretPut/Get` (+ מחיקה בניתוק), `recordEvent`, `ctx`/`can`/`scope`. הרשאות: `dropbox.manage` (מנהל), `files.upload` (משרד, מנהל), `case.view`, `dev` |
| `dropbox-webhook.php` | ציבורי (`"public"`). GET challenge; POST חתום ב-`X-Dropbox-Signature` → 200 מיד, ואז סנכרון (עד 8 שניות לארגון) |
| `bin/dropbox-sync.php` | גיבוי ל-webhook, כל 5 דקות, CLI בלבד (HTTP → 404) |
| `assets/js/dropbox.js`, `assets/css/dropbox.css` | קבצי התיק, ולשונית "Dropbox" ב-⚙️ הגדרות (מנהל; App key/secret — למפתח) |
| `tests/dropbox-test.php`, `tests/dropbox-mock.php` | C1–C11, C13 מול Dropbox מדומה, בלי רשת (רץ מ-`tests/run.sh`) |

## נתונים (כולם ב-`data/funerals.sqlite`; הטבלאות — `migrateV2()` ב-`lib/schema.php`, `SCHEMA_VERSION = 2`)

- `dbx_orgs` — לכל ארגון: מצב (`connected` / `reauth` / `disconnected`), `account_id`, הראשית (`root_id` + נתיב), `cursor`,
  נעילה (`lock_until`), הסנכרון / webhook / שגיאה האחרונים. **בלי סודות.**
- `dbx_folders` — התיקיות שהסנכרון ראה בתוך הראשית (לפי `id`); בסיס לסריקת ההתאמה ולבדיקת "בתוך הראשית" בלי קריאה ל-Dropbox.
- `case_folders` — תיק ↔ תיקייה (`dbx_folder_id`, `dbx_path`). בטבלה נפרדת ולא בגוף התיק, כדי ש-`normalize()` לא ימחק את
  הקישור בשמירה הבאה. שינוי שם ב-Dropbox מעדכן את הנתיב; הקישור לפי `id`.
- `files` — לפי האפיון 3.5.1, `dbx_id` ייחודי; `deleted=1` במקום מחיקה; `upload_id` — מזהה חד-פעמי של העלאה (חוזרת → אותה שורה).
- `secrets` — `dropbox_app_key` / `dropbox_app_secret` (ארגון 0 = כללי), `dropbox_refresh` לכל ארגון. מוצפנים.
- `data/cache/thumbs/` — מטמון תמונות ממוזערות, עד 200MB.

## החלטות טכניות

- **נעילה:** `BEGIN IMMEDIATE` קצר שתופס חכירה בשורת הארגון (`lock_until`), ולא טרנזקציה פתוחה לאורך הקריאות ל-Dropbox —
  כך שאר האפליקציה לא נחסמת לכתיבה בזמן סנכרון. כל עמוד נכתב יחד עם ה-`cursor` שלו בטרנזקציה אחת.
- **"בתוך הראשית" בלי קריאה ל-Dropbox:** נתיב מבקשה נבדק בקוד (`..`, `.`, תווי בקרה, `id:`/`ns:`, קידומת הראשית).
  מזהה (`id:`) מתקבל רק אם הוא כבר ב-`files` / `dbx_folders` של הארגון. `file_view` מקבל מספר שורה שלנו.
- **כל הקבצים בראשית נשמרים** ב-`files` (גם בלי תיק), כדי ששיוך תיקייה לתיק יביא מיד את הקבצים שכבר בה.
- **תצוגה:** תמונה → `get_thumbnail_v2` (w1024h768); PDF → `download`; Word/PowerPoint → `get_preview` (PDF). גיליונות
  (`get_preview` מחזיר HTML) — בלי תצוגה, רק "פתיחה ב-Dropbox".
- **`DBX_API_BASE`** מתקבל רק כ-`http://127.0.0.1:PORT` — גם אם יוגדר בשרת בטעות, אסימון לא יישלח החוצה.

## מה הבעלים עושה פעם אחת

האפיון §2: אפליקציה ב-App Console (Full Dropbox; `files.metadata.read`, `files.content.read`, `files.content.write`,
`account_info.read`), Redirect URI ו-Webhook URI (מוצגים למפתח ב-⚙️ הגדרות ← Dropbox, להעתקה), ו-App key / App secret באותו מקום.

## ממשק לפרוסות אחרות (ב-`dropbox/actions.php`; ההרשאה — באחריות הקורא)

| פונקציה | מה |
| --- | --- |
| `dropboxUploadToCase(PDO, int $orgId, array $case, string $tmp, string $name, array $actor, string $via, ?string $uploadId)` | העלאה לתיקיית התיק (נפתחת אם אין) ורישום ב-`files`; `$via` = `ui` / `field` / `api`; `$uploadId` חוזר → אותה שורה בלי העלאה שנייה. מחזיר שורת `files` |
| `dropboxCaseFiles(PDO, array $case)` | קבצי התיק (`$case` עם `id`, `org_id`), בצורה של `case_files` |
| `dropboxFileContent(PDO, int $orgId, int $fileId, int $maxBytes = 10MB)` | `name`, `mime`, `size`, `bytes`; ארגון אחר / נמחק → `null` |
| `dropboxFileText(PDO, int $orgId, int $fileId)` | טקסט עד 200KB — txt/csv/md/json בלבד; אחר → `DbxError('not_text')` |

שגיאות: `DbxError` עם `tag` (`not_connected`, `reauth`, `no_root`, `outside_root`, `too_large`, `not_text`, או `error_summary` של Dropbox).

## מגבלת ההעלאה

**לא ב-`.user.ini` של האפליקציה:** `platform/build-gate.php` כותב שם את ה-`auto_prepend_file` של השער, ו**עוצר את הפריסה**
כשהקובץ כבר קיים. וגם לא `php_value` ב-`.htaccess` (500 לכל האפליקציה בשרת שאינו LSAPI). הערכים בפועל מוצגים בשורת Dropbox
בבדיקות התקינות (⛔ אם פחות מ-50M); העלאת הגבול — בקשה לפלטפורמה (שורה נוספת ב-`.user.ini` שהשער בונה) או בהגדרות ה-PHP
של השרת. עד אז קובץ גדול מהגבול → 413 עם הודעה.

## נשאר ל-C12 (ידני בשרת)
- השורה ב-`CRONS` של `deploy.yml` — של הבקרה.
