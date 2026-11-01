# ענן החבילה (`suite-cloud/`)

ה-Creative Cloud של חבילת היצירה: חשבון אחד, כוננים זה לצד זה, וספריות משותפות לכל האפליקציות. זו אפליקציה
עצמאית, עם חשבונות, מסד ונתונים משלה. האפליקציות מתחברות אליה ב-HTTPS בלבד, ובלעדיה עובדות כמו היום.

- **האפיון הכללי:** `creative-suite/docs/spec/shared.md` · **ההכרעה:** `creative-suite/docs/requests/spec-shared.md`
- **שלב 1, השלד:** [`docs/spec-1-skeleton.md`](docs/spec-1-skeleton.md)
- **ההתקדמות:** `creative-suite/docs/projects/suite-cloud.md`

## מה יש

| חלק | מה |
| --- | --- |
| חשבון | הזהות מהשער (`PLATFORM_USER`). אותו מייל = אותו אדם. מי שמגיע עם `platform_admin` הוא מנהל הענן |
| כונן פנימי | העץ ב-`data/cloud.sqlite`, התוכן ב-`data/files/ab/cd/<sha256>`. מכסה לכל חשבון (ברירת מחדל 0 עד שהמנהל מאשר), תקרה לקובץ (500MB), העלאה בחלקים של 8MB, וסל של 30 יום |
| API | `api.php?action=…`: `list`, `stat`, `read`, `mkdir`, `write`, `upload_start/chunk/finish`, `rename`, `move`, `trash`, `restore`, `trash_list`, `purge`, `trash_empty`, ופעולות ניהול. הפירוט באפיון, סעיף 6 |
| חיבור אפליקציה | `connect.php` (אישור פעם אחת) → קוד → `connect_exchange` (חתום) → חיבור. לדפדפן — `access_token` של 15 דקות, ו-CORS רק ל-origins של הלקוח. ניתוק — מהענן ("🔗 חיבורים") או מהאפליקציה (`disconnect`), ונבדק בכל קריאה. הקוד: `lib/clients.php` |
| בינה מלאכותית | `ai_capabilities`, `ai_run` — חמש משימות (הסרת רקע, פירוק לעצמים, יצירת תמונה, מילוי, טקסט/JSON) דרך OpenAI, Google, Replicate ו-Anthropic (Claude, לטקסט בלבד). המפתחות מוצפנים (`data/secret.key`), ההרשאה והמכסה החודשית לכל משתמש, ויומן שימוש. כל קוד הספקים — `lib/ai/providers.php` בלבד. האפיון: `docs/spec-3-ai.md` |
| ספריית הגופנים | `fonts_list`, `font_add`, `font_file`, `font_update`, `font_delete` — פריט = קובץ (משפחה, משקל, סגנון), `rev` = sha256. הפרטים מטבלאות name / OS/2 / fvar (ttf, otf, woff), או משם הקובץ (woff2). נספר במכסה. ועוד 10 גופנים מובנים (OFL) ב-`fonts/builtin/` (`?builtin=1`, `font_license`). הקוד: `lib/fonts.php`. האפיון: `docs/spec-2-fonts.md` |
| מודול הכוננים | `drives/` — JS + PHP, עותק מסונכרן בכל אפליקציה שמצטרפת: חיבור לענן, הקבצים בענן, `fonts`, `ai`, ו-Dropbox ישירות מהאפליקציה. ההצטרפות: `drives/README.md` |
| Dropbox | App key אחד לכל החבילה (⚙️ ניהול ← 🗂️ כוננים חיצוניים), PKCE בלי secret, ו-redirect אחד: `oauth/dropbox.php`, שמעביר את החזרה לאפליקציה לפי ה-state |
| הדף | `index.html`, בעיצוב `phone-first-design` (דרך `ui-core`), עם הלשוניות קבצים, גופנים, סל, חיבורים וניהול |

## מה עוד לא

- צבעים, סגנונות, נכסים; Google Drive, OneDrive, SFTP / WebDAV; צוותים, גרסאות, חיפוש

## בשרת

`data/` אינה בגיט, חוץ מ-`.htaccess` שחוסם גישה ישירה. שם גם `sign.key` (מפתח החתימה של הענן) ו-`secret.key` (מפתח ההצפנה של מפתחות הספקים), שנוצרים בשרת. `deploy.yml` מחריג את כל מה שבתוכה, ולכן פריסה לא נוגעת
בקבצים של המשתמשים.

## בדיקות

```
php suite-cloud/tests/run.php
php suite-cloud/tools/fonts-catalog.php --check
php suite-cloud/drives/tests/run.php
node suite-cloud/drives/tests/js.mjs
```
