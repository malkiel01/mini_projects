# ענן MBE (`mbe-cloud/`)

הענן של כל האפליקציות, הקיימות והעתידיות. חשבון אחד, ובו קבצים, ספריות מערכת (כמו גופנים) ונתוני אפליקציה. חבילת
היצירה היא לקוח אחד שלו. זו אפליקציה עצמאית, עם חשבונות, מסד ונתונים משלה. האפליקציות מתחברות אליה ב-HTTPS בלבד,
ובלעדיה עובדות כמו היום.

> **עד 2026-11 נקרא "ענן החבילה", בתיקייה `suite-cloud/`.** זו נשארה כתיקיית הפניה בלבד (308, [`../suite-cloud/README.md`](../suite-cloud/README.md)).
> הנתונים בשרת הועברו בשלב חד-פעמי בפריסה.

- **החזון והמפה (אושר 2026-11-07):** [`docs/spec-0-vision.md`](docs/spec-0-vision.md)
- **האפיון הכללי (מימי החבילה):** `creative-suite/docs/spec/shared.md` · **ההכרעה:** `creative-suite/docs/requests/spec-shared.md`
- **שלב 1, השלד:** [`docs/spec-1-skeleton.md`](docs/spec-1-skeleton.md)
- **ההתקדמות עד היציאה מהחבילה:** `creative-suite/docs/projects/suite-cloud.md`

## מה יש

| חלק | מה |
| --- | --- |
| חשבון | הזהות מהשער (`PLATFORM_USER`). אותו מייל = אותו אדם. מי שמגיע עם `platform_admin` הוא מנהל הענן |
| כונן פנימי | העץ ב-`data/cloud.sqlite`, התוכן ב-`data/files/ab/cd/<sha256>`. מכסה לכל חשבון (ברירת מחדל 0 עד שהמנהל מאשר), תקרה לקובץ (500MB), העלאה בחלקים של 8MB, וסל של 30 יום |
| API | `api.php?action=…`: `list`, `stat`, `read`, `mkdir`, `write`, `upload_start/chunk/finish`, `rename`, `move`, `trash`, `restore`, `trash_list`, `purge`, `trash_empty`, ופעולות ניהול. הפירוט באפיון, סעיף 6 |
| חיבור אפליקציה | `connect.php` (הפניה אחת, בלי מסך אישור — `drives.ensure()`) → קוד → `connect_exchange` (חתום) → חיבור. לדפדפן — `access_token` של 15 דקות, ו-CORS רק ל-origins של הלקוח. **אילו שכבות דלוקות — המנהל קובע, לכל המשתמשים** (⚙️ ניהול ← אפליקציות; `docs/spec-6-admin-layers.md`), ונבדק בכל קריאה. למשתמש אין מתגים ואין ניתוק. הקוד: `lib/clients.php`, `lib/policy.php` |
| שני סוגי משתמשים | **עם כונן** — הרשאה ל-`mbe-cloud` בפלטפורמה; **בלי כונן** — מחובר בלי ההרשאה: רק 🗂️ (ו-📚 לקריאה). הסוג מ-`PLATFORM_USER.granted` (עוגייה) או מ-`grant_status` בפלטפורמה (חתום, מטמון 60 שניות; לא עונה — סגור). `connect.php`, `me.php` ו-`me-api.php` ב-`signed_in`. המפתח שהפלטפורמה מאמתת: `"grant_status": {"key_url": …platform_key}` ב-`app.json` — רק `grant_status`, לא `provision` (הזמנת משתמשים). הקוד: `lib/tier.php`; `docs/spec-5-account-tiers.md` |
| בינה מלאכותית | `ai_capabilities`, `ai_run` — חמש משימות (הסרת רקע, פירוק לעצמים, יצירת תמונה, מילוי, טקסט/JSON) דרך OpenAI, Google, Replicate ו-Anthropic (Claude, לטקסט בלבד). המפתחות מוצפנים (`data/secret.key`), ההרשאה והמכסה החודשית לכל משתמש, ויומן שימוש. כל קוד הספקים — `lib/ai/providers.php` בלבד. האפיון: `docs/spec-3-ai.md` |
| ספריית הגופנים | `fonts_list`, `font_add`, `font_file`, `font_update`, `font_delete` — פריט = קובץ (משפחה, משקל, סגנון), `rev` = sha256. הפרטים מטבלאות name / OS/2 / fvar (ttf, otf, woff), או משם הקובץ (woff2). נספר במכסה. ועוד 10 גופנים מובנים (OFL) ב-`fonts/builtin/` (`?builtin=1`, `font_license`). הקוד: `lib/fonts.php`. האפיון: `docs/spec-2-fonts.md` |
| מודול הכוננים | `drives/` — JS + PHP, עותק מסונכרן בכל אפליקציה שמצטרפת: חיבור לענן, הקבצים בענן, `fonts`, `ai`, ו-Dropbox ישירות מהאפליקציה. ההצטרפות: `drives/README.md` |
| Dropbox | App key אחד לכל החבילה (⚙️ ניהול ← 🗂️ כוננים חיצוניים), PKCE בלי secret, ו-redirect אחד: `oauth/dropbox.php`, שמעביר את החזרה לאפליקציה לפי ה-state |
| 👥 שיתוף ואסימון אישי | שיתוף תיקייה או קובץ לחשבון אחר — **קריאה בלבד**, ביטול מיידי (`share_create` / `share_list` / `share_revoke`, 👥 בדף). **חשבון שירות** (`accounts.kind = 'service'`, בלי כניסה ובלי כונן) ו**אסימון אישי** `mbe_pat_<id>_<secret>` שמנהל הענן יוצר (⚙️ ניהול ← 🔑 חשבונות שירות; הסוד מוצג פעם אחת, במסד רק sha256). עם האסימון — רק `shared_roots`, `list`, `stat`, `read` על מה ששותף, לפי `id`; כל פעולה אחרת 403; פריט לא נגיש — תמיד 404. 429 אחרי 10 כישלונות מכתובת ב-10 דקות; יומן לכל אסימון (`token_log`). הקוד: `lib/share.php`. האפיון: `docs/spec-2-share-token.md` |
| 👤 תפריט המשתמש | `accountMenu` של `ui-core` בסרגל; `account_*` ב-`api.php` דרך `lib/ui-core/account` (עותק מסונכרן): הגדרות אישיות במסד, ייצוא, ומחיקת חשבון מלאה (`lib/me.php`) |
| הדף | `index.html`, בעיצוב `phone-first-design` (דרך `ui-core`), עם הלשוניות קבצים, גופנים, סל, חיבורים וניהול |

## מה עוד לא

- צבעים, סגנונות, נכסים; Google Drive, OneDrive, SFTP / WebDAV; צוותים, גרסאות, חיפוש
- שיתוף לעריכה, אסימון אישי לאדם, ו"שותף איתי" דרך אסימון של אפליקציה מחוברת (שלב 3)

## בשרת

`data/` אינה בגיט, חוץ מ-`.htaccess` שחוסם גישה ישירה. שם גם `sign.key` (מפתח החתימה של הענן) ו-`secret.key` (מפתח ההצפנה של מפתחות הספקים), שנוצרים בשרת. `deploy.yml` מחריג את כל מה שבתוכה, ולכן פריסה לא נוגעת
בקבצים של המשתמשים.

## בדיקות

```
php mbe-cloud/tests/run.php
php mbe-cloud/tools/fonts-catalog.php --check
php mbe-cloud/drives/tests/run.php
node mbe-cloud/drives/tests/js.mjs
```
