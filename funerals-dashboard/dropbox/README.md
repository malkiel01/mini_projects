# Dropbox לארגון (פרוסה 03)

האפיון: `docs/spec/03-dropbox.md`. קובץ שמועלה באפליקציה נמצא בתיקיית התיק ב-Dropbox, וקובץ שנוסף לתיקייה ב-Dropbox
מופיע בתיק — עם תצוגה מקדימה דרך השרת.

| קובץ | תפקיד |
| --- | --- |
| `dropbox/lib.php` | טבלאות, לקוח HTTP (רענון אסימון, RPC, content, upload בחלקים), הגבלה לראשית, סנכרון ב-cursor, תיקייה לתיק, סריקת התאמה |
| `dropbox/actions.php` | פעולות `api.php` (`dropbox_*`, `case_files`, `file_view`) — החיבור היחיד ב-`api.php` הוא שתי שורות |
| `dropbox/compat.php` | שכבה דקה מול 01: `dbxSecretPut/Get/Delete`, `dbxEvent`, `dbxWho`, `dbxCan`, `dbxCase` — קוראת ל-`secretPut`/`recordEvent`/`can` כשהן קיימות |
| `dropbox-webhook.php` | ציבורי (`"public"`). GET challenge; POST חתום ב-`X-Dropbox-Signature` → 200 מיד, ואז סנכרון (עד 8 שניות לארגון) |
| `bin/dropbox-sync.php` | גיבוי ל-webhook, כל 5 דקות, CLI בלבד (HTTP → 404) |
| `assets/js/dropbox.js`, `assets/css/dropbox.css` | קבצי התיק ומסך ההגדרות (☁️ בכותרת) |
| `tests/dropbox-test.php`, `tests/dropbox-mock.php` | C1–C11, C13 מול Dropbox מדומה, בלי רשת (רץ מ-`tests/run.sh`) |

## נתונים (כולם ב-`data/funerals.sqlite`, מוסיפים בלבד)

- `dbx_orgs` — לכל ארגון: מצב (`connected` / `reauth` / `disconnected`), `account_id`, הראשית (`root_id` + נתיב), `cursor`,
  נעילה (`lock_until`), הסנכרון / webhook / שגיאה האחרונים. **בלי סודות.**
- `dbx_folders` — התיקיות שהסנכרון ראה בתוך הראשית (לפי `id`); בסיס לסריקת ההתאמה ולבדיקת "בתוך הראשית" בלי קריאה ל-Dropbox.
- `case_folders` — תיק ↔ תיקייה (`dbx_folder_id`, `dbx_path`). בטבלה נפרדת ולא בגוף התיק, כדי ש-`normalize()` לא ימחק את
  הקישור בשמירה הבאה. שינוי שם ב-Dropbox מעדכן את הנתיב; הקישור לפי `id`.
- `files` — לפי האפיון 3.5.1, `dbx_id` ייחודי; `deleted=1` במקום מחיקה.
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
`account_info.read`), Redirect URI ו-Webhook URI (מוצגים במסך ☁️ למפתח, להעתקה), ו-App key / App secret במסך ☁️.

## מה טרם הוכרע / נשאר ל-C12 (ידני בשרת)

- `upload_max_filesize` / `post_max_size` בשרת — צריכים להיות ≥ 50MB כדי שהגבול של האפיון יעבוד; פחות מזה → 413 עם הודעה.
- השורה ב-`CRONS` של `deploy.yml` — של הבקרה.
