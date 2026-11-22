# 01 — תשתית: מסד, כניסת מנהלים, יומן פעולות, מסך המפתח ובדיקת השרת

**הפרוסה הראשונה לקוד.** בסופה אין עדיין תפילה אחת — אבל יש כל מה שכל פרוסה אחרת עומדת עליו: מסד עם מיגרציות,
מנהלים מזוהים דרך הפלטפורמה, כל אחד בחשבון אישי (§3), יומן פעולות (§3, §16), מעטפת ממשק, מפתח שרואה מה קורה
במערכת — ו**תשובה מוכחת מהשרת** לשאלות התשתית של האפיון (§18, §22.1, §24 "תשתית"): גרסת PHP, הרחבות, cron, אימייל,
גישה החוצה ונפח.

**קריאת חובה לפני הקוד:** `00-overview.md` (סעיפים 3–4), `platform/docs/auth.md`, `platform/docs/sso.md` §0,
`devloop/README.md`, `.claude/skills/devloop/references/install.md` ו-`log.md`, הסקיל `phone-first-design`.
דוגמאות קיימות לזיהוי דרך המעטפת: `document-templates/lib/platform.php`, `appraisers/lib/` — **לקרוא, לא לעשות להם
`require`.**

## 0. תנאי מוקדם — בקרה

לפני ה-PR של הקוד (`PROTOCOL.md` §8 — רק הבקרה נוגעת מחוץ ל-`sforno/`), ויחד איתו:

1. רישום `sforno` ב-`devloop/apps.json` וב-`.devloop.json` (קידומת `SF-`, אסימון `sfd_`).
2. צעד ב-`deploy.yml` שמריץ `php sforno/tests/run.php` ועוצר את הפריסה בכישלון.
3. `data/` כבר מוחרג (`--exclude='/sforno/data/*'`) — אין נתיב נתונים חדש בפרוסה הזו.

ומטלה לבעלים (דרך צ'אט המטלות), אחרי הפריסה: להגדיר ב-cPanel משימת cron שקוראת ל-`cron.php` (הכתובת — במסך המפתח).

## 1. מה בונים

1. מסד SQLite עם מיגרציות שרצות לבד (🟢 ש2).
2. כניסת מנהלים דרך הפלטפורמה (מצב מחוברת), חשבון אישי לכל מנהל מקושר ל-`sub`, ו-session משלה.
3. ניהול המנהלים: רשימה, השעיה, ביטול השעיה.
4. יומן פעולות (audit).
5. מסך המפתח: DevLoop, יומן בקשות ושגיאות, יומן פעולות, בדיקות תקינות ויכולות השרת, ושירותי רקע.
6. שלד שירותי הרקע: `cron.php`, טבלת `jobs`, ומשימת ניקוי אחת — כדי להוכיח שהמתזמן רץ בשרת הזה.
7. מעטפת הממשק: כותרת, ניתוב, מסך בית עם הכפתורים המרכזיים **כמקום שמור** ("בקרוב"), אזור ניהול ריק, שגיאות.
8. בדיקות אוטומטיות שרצות ב-CLI.

## 2. מה לא בפרוסה הזו

| מה | איפה |
| --- | --- |
| תוכן, עורך, מהדורות | 02 |
| ייבוא | 03 |
| סידור, תהילים, קורא | 04 |
| משתמשים רשומים (סיסמה, Google), אורח, סנכרון | 05 |
| הורדות, PWA ו-service worker | 06 |
| זמנים, הלכה יומית | 07 |
| תזכורות | 08 |
| WooCommerce | 09 |
| דיווחים, מדדים, גיבויים | 10 |
| מצב מנותק ו-`sso.php`, כל כתובת לציבור | 11 |

## 3. מבנה הקבצים ⚙️

המלצה, לא חוזה — הכללים שמתחת לעץ הם החוזה.

```
sforno/
  app.json                 + "public" ו-"version" (סעיף 9)
  index.html               המעטפת — lang="he" dir="rtl"
  api.php                  הנתב היחיד
  cron.php                 שירותי רקע — באסימון (סעיף 8.5)
  lib/                     .htaccess חוסם
    db.php  migrations/NNN-*.php
    http.php               JSON, שגיאות, CSRF, מזהה בקשה, בסיס הכתובת
    platform.php           הזהות מהמעטפת — הנקודה היחידה שקוראת PLATFORM_USER
    auth.php               מנהלים, session, המשתמש הנוכחי
    access.php             ההרשאות — מקום אחד
    audit.php              יומן הפעולות
    jobs.php               תור שירותי הרקע
    health.php             רישום והרצה של בדיקות תקינות
    devtasks.php           החיבור ל-DevLoop
    devloop.php            ← עותק מסונכרן. לא עורכים
  assets/js/   app.js  api.js  router.js  screens/*.js  devloop.js ← עותק
  assets/css/  app.css  devloop.css ← עותק
  assets/fonts/ DavidLibre-Regular.ttf  DavidLibre-Bold.ttf  OFL-davidlibre.txt   (גופן חלופי, §17)
  dev/open-items.json      מפת הדרכים; dev/.htaccess חוסם הכול (הפלטפורמה קוראת מהדיסק)
  tests/run.php            .htaccess חוסם
  data/                    sforno.sqlite, _dev/, cron.token — לא בגיט, מוחרג בפריסה
```

- `lib/`, `tests/`, `dev/`, `data/` — חסומים מהדפדפן ב-`.htaccess` משלהם. `data/.htaccess` כבר קיים ונשאר.
- **אין `require` לשום קובץ מחוץ ל-`sforno/`.** הגופן — **עותק** מ-`mbe-cloud/fonts/builtin/`, עם הרישיון.
- **אין נתיב קבוע:** בסיס הכתובת ונתיב העוגייה נגזרים מתיקיית `api.php` בזמן ריצה (`00` §3.3).
- אין קישור "חזרה לכלים", ואין `../` בשום HTML או JS.

## 4. מודל הנתונים

מוסכמות לכל הטבלאות, גם בפרוסות הבאות:

- `id` — מספר רץ פנימי, **לא יוצא מהשרת.** `pid` — מזהה ציבורי אקראי (16 תווים, `[a-z0-9]`), ייחודי; רק הוא
  בכתובות וב-API. מזהה יציב (§6).
- `created_at`, `updated_at` — UTC, ‏ISO-8601.
- SQLite במצב WAL, מפתחות זרים דלוקים. שאילתות ב-SQL שעובד גם ב-MySQL (`00` §3.1).

```
staff                                     מנהלים בלבד. משתמשים רשומים — טבלה נפרדת (readers) בפרוסה 05
  id, pid
  platform_sub        TEXT UNIQUE NULL    ה-sub מהמעטפת (מחרוזת ספרות)
  email               TEXT                באותיות קטנות; ייחודי כשאינו NULL
  email_verified      INT
  name                TEXT
  is_developer        INT                 = platform_admin של הזהות, מתעדכן בכל כניסה
  status              active | suspended
  last_seen_at, created_at, updated_at

staff_sessions
  id, staff_id
  token_hash          TEXT UNIQUE         רק ה-hash של האסימון שבעוגייה
  csrf                TEXT
  platform_sub        TEXT                למי ה-session שייך
  ua, created_at, last_used_at, expires_at

audit_log                                 רק מוסיפים; אין עדכון ואין מחיקה
  id, at
  actor_staff_id      INT NULL            NULL = המערכת
  actor_kind          staff | system | job
  action              TEXT                קוד: staff.create, staff.suspend …
  entity_type, entity_pid
  meta                JSON                מזהים ומצבים בלבד — לא תוכן, לא אסימונים (§19)
  req                 TEXT                מזהה הבקשה — מקשר ליומן הטכני

jobs
  id, pid
  kind                TEXT                maintenance.cleanup …
  payload             JSON
  dedupe_key          TEXT UNIQUE NULL    אותה משימה לא נכנסת פעמיים (§12.4 — אותו עיקרון לתזכורות)
  status              pending | running | done | failed
  attempts, max_attempts, run_after, started_at, finished_at
  last_error          TEXT NULL           הודעה טכנית, בלי תוכן
  created_at

meta                                      key / value
  schema_version, platform_base, platform_config_cache, cron_last_run_at, cron_last_ok_at …
```

> **כל המנהלים בהרשאות מלאות (§3)** — אין תפקידים בתוך הניהול. ההבדל היחיד הוא `is_developer`, שמגיע מהפלטפורמה
> ופותח את מסך המפתח. **למה `staff` ולא `users`:** משתמשים רשומים (§3) הם עולם אחר — טבלה, עוגייה והרשאות. שם נפרד
> מונע ערבוב מראש.

## 5. כניסת מנהלים (מצב מחוברת)

### 5.1 המצב

`platform_mode()` ב-`lib/platform.php` מחזיר `connected` · `detached` · `unavailable`:

- יש `$_SERVER['PLATFORM_USER']` תקין ← `connected`.
- אחרת — `platform/sso.php?action=config&client=sforno`, בכתובת שנבנית מ-`SERVER_NAME` ומ-`meta.platform_base`
  (ברירת מחדל: `/mini_projects/platform/`); תשובה נשמרת 60 שניות, כישלון — 15.
- ב-CLI ובבדיקות בלבד (`PHP_SAPI === 'cli'`): `SF_PLATFORM` קובע את המצב, `SF_TEST_IDENTITY` מזריק זהות.

בפרוסה הזו `detached` ו-`unavailable` ← כל פעולה שאינה ציבורית מחזירה 503 "הכניסה לא זמינה כרגע". **נכשל סגור.**
המצב המנותק (סאב-דומיין) — פרוסה 11; הקריאה מבודדת ב-`platform.php` כדי שיתווסף בלי לגעת בשאר.

### 5.2 בכל בקשה

1. הזהות: `{sub, name, email, email_verified, platform_admin, granted}`.
2. `granted` אינו `true` ← אין משתמש (401). לא סומכים על כך שהשער כבר חסם.
3. מחפשים `staff` לפי `platform_sub`.
4. לא נמצא, ויש `email` מאומת ← `staff` באותו מייל בלי `platform_sub` ← מקשרים (`staff.link`).
5. עדיין לא נמצא ← **פותחים חשבון מנהל מיד, פעיל** (`staff.create`). ההרשאה ל-`sforno` בפלטפורמה היא האישור; אין
   אישור כפול (`auth.md` §3). כך מוסיפים מנהל: מנהל הפלטפורמה נותן לו הרשאה, והוא נכנס.
6. `is_developer` ← `platform_admin` שבזהות, לשני הכיוונים (`staff.developer_on` / `_off`, המבצע: המערכת).
7. שם ומייל מתעדכנים מהזהות כשהשתנו.
8. `suspended` ← 403 "החשבון הושעה".
9. העוגייה `sf_staff`: אסימון אקראי, `HttpOnly`, `Secure`, `SameSite=Lax`, נתיב = בסיס הכתובת. אין session, או
   שהוא של `sub` אחר ← חדש, והישן נמחק. תוקף: 30 יום מהשימוש האחרון. **לא session של PHP.**
10. בקשה בלי `PLATFORM_USER` ← אין מנהל, וה-session נמחק (יציאה מהפלטפורמה = יציאה מכאן).

> **אין "מסך חיבור חד-פעמי"** (`auth.md` §4): אין מנהלים ישנים בסיסמה — ספורנו נולדת מחוברת.

### 5.3 הגנות (§19)

- כל פעולה משנה: `POST`, גוף JSON, כותרת `X-CSRF` (מגיע מ-`me`). חסר או שגוי ← 403.
- כל תשובה: `X-Request-Id`, ‏`Cache-Control: no-store`.
- שגיאה: `{ "ok": false, "error": { "code": "…", "message": "…בעברית…" } }` — בלי SQL, נתיב קובץ או trace (§18
  "שגיאות אחידות").

## 6. ניהול המנהלים

| פעולה | מי |
| --- | --- |
| `me`, יציאה | כל מנהל פעיל |
| רשימת המנהלים (שם, מייל, מצב, נראה לאחרונה); השעיה וביטול השעיה | כל מנהל פעיל (§3 — הרשאות מלאות) |
| מסך המפתח, DevLoop, בדיקות, שירותי רקע | `is_developer` |
| `health` הקצר, `cron.php`, `devapi-*` | בלי משתמש — סעיף 9 |

- ההכרעה במקום אחד: `lib/access.php`. הנתב קורא לו לפני כל פעולה; המסכים רק מסתירים כפתורים.
- **תמיד נשאר מנהל פעיל אחד לפחות**, ומנהל לא משעה את עצמו.
- השעיה חלה **בבקשה הבאה** של אותו אדם.
- השעיה כאן אינה מבטלת את ההרשאה בפלטפורמה — ומי שהושעה נשאר חסום גם כשהוא נכנס שוב.

## 7. יומן הפעולות (§3, §16)

פונקציה אחת: `audit(action, entity, meta)`, **באותה טרנזקציה** של השינוי — יומן שנכשל מפיל את הפעולה.

- ב-`meta`: מזהים, מצב קודם וחדש. **לעולם לא:** אסימון, סיסמה, תוכן, שמות לתפילה, הערות (§19).
- הפעולות בפרוסה: `staff.create`, `staff.link`, `staff.suspend`, `staff.unsuspend`, `staff.developer_on`,
  `staff.developer_off`, `devtoken.create`, `devtoken.revoke`, `cron.token_rotate`, `job.retry`, `job.cancel`.
- כל פרוסה הבאה רושמת כאן את פעולות המנהלים שלה (פרסום, שחזור, שינוי שיטה…).

## 8. מסך המפתח (`#/dev`) — `PROTOCOL.md` §7

רק ל-`is_developer`. חמש לשוניות:

### 8.1 📋 מטלות — DevLoop

מהמודול המשותף בלבד: `lib/devtasks.php` → `devloop_setup` עם `db`, `items_file` = `dev/open-items.json`, `data_dir` =
`data/_dev`, `prefix` = `SF-`, `token_prefix` = `sfd_`, `error`, `user_names`, `is_developer`, `audit`. היומן המובנה
(בלי `log`): `devloop_log_begin` בראש `api.php`, `devloop_log_error` ב-catch. פעולות `dev-*` באותם שמות כמו ב-
`install.md`. בדפדפן `DevLoop.setup({…, builtinLog: true, tasksHref: '#/dev/tasks'})` ו-`DevLoop.mount()` — 🐞 בכותרת,
**רק למפתח**. `dev/open-items.json` נפתח עם מטלה לכל פרוסה שב-`00` §5 (`SF-001` והלאה).

### 8.2 📜 יומן בקשות ושגיאות

`DevLoop.showLog`: כל בקשה (פעולה, מצב, משך, מי), שגיאות PHP וחריגות, שגיאות JavaScript מהדפדפן. **לא נרשמים** גוף
הבקשה, פרמטרים שאינם מזהים, או טקסט חופשי (§19).

### 8.3 🧾 יומן פעולות

`audit_log` מהחדש לישן, סינון לפי פעולה, מבצע ותאריכים; מזהה הבקשה פותח את היומן הטכני.

### 8.4 🩺 בדיקות תקינות ויכולות השרת

כפתור "הרצה", שורה לכל בדיקה (✅ / ⚠️ / ❌ + מה לעשות), ברישום אחד (`health_register`) כדי שפרוסות הבאות יוסיפו.
**השורות המסומנות "§24" הן התשובה לשורת "תשתית" של האפיון** — הצ'אט מעתיק את התוצאות מהשרת ל-`dev/server-report.md`
אחרי הפריסה הראשונה.

| בדיקה | תקין כש- |
| --- | --- |
| המסד | `PRAGMA quick_check` = ok; WAL; מפתחות זרים דלוקים |
| גרסת הסכימה | שווה למיגרציה האחרונה שבקוד |
| כתיבה | `data/`, `data/_dev/` ניתנות לכתיבה |
| `data/` חסומה | `data/.htaccess` קיים, ובקשה עצמית ל-`data/sforno.sqlite` אינה מחזירה 200 |
| PHP §24 | 8.3+; `pdo_sqlite`, `sodium`, `curl`, `mbstring`, `json` (חסרה ← ❌) |
| הרחבות לפרוסות הבאות §24 | `intl` (לוח עברי, 07), `dom` + `libxml` (ניקוי HTML, 02), `zip` (Word/EPUB, 03), `gd` או `imagick` (תמונות, 03), `pdo_mysql` (אם ש2 תשתנה), `openssl` (Web Push, 08). חסרה ← ⚠️ עם שם הפרוסה |
| מגבלות §24 | `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time` — מוצגים כמו שהם (לייבוא, 03) |
| גישה החוצה §24 | בקשת HTTPS ל-`https://www.google.com/generate_204` מחזירה 204 בתוך 5 שניות (Google בכניסה, WooCommerce) |
| אימייל §24 | `mail` זמינה. **לא שולחים** בבדיקה; שליחת ניסיון — כפתור נפרד, לכתובת המפתח בלבד |
| נפח §24 | מקום פנוי בדיסק (⚠️ מתחת ל-500MB) |
| המתזמן §24 | `cron_last_ok_at` בתוך 15 הדקות האחרונות (⚠️ עם ההוראה מסעיף 8.5) |
| תור הרקע | אין `failed`; אין `running` תקועה מעל 10 דקות |
| גרסה | `version` מ-`app.json` |
| המעטפת | `sso.php?action=config` עונה, ו-`connected` = `true` |
| DevLoop | שלושת העותקים קיימים; `dev/open-items.json` תקין |
| עצמאות | אין `require`/`include` לנתיב מחוץ לתיקייה (סריקה של `lib/`, `api.php`, `cron.php`) |

### 8.5 ⚙️ שירותי רקע

- **`cron.php?token=<אסימון>`** — האסימון נוצר בהרצה הראשונה (`data/cron.token`); המסך מציג את הכתובת המלאה להעתקה,
  ופקודת cPanel מוכנה (`curl -s …` **כל דקה**, `* * * * *` — ההכרעה של 08). אסימון שגוי ← 404. "אסימון חדש" ← הישן מפסיק לעבוד.
- **כל הרצה:** נועלת (לא שתיים במקביל), מריצה משימות `pending` שמועדן הגיע עד 25 שניות, מעדכנת זמנים, מחזירה סיכום
  JSON קצר. משימה שנכשלה: ניסיון חוזר בהשהיה גדלה (דקה, 5, 30) עד `max_attempts`, ואז `failed`.
- **המסך:** מתי רץ לאחרונה ומתי הצליח; ספירה לפי מצב; המשימות האחרונות; "ניסיון חוזר" ו"ביטול" למשימה שנכשלה.
- **משימה אחת בפרוסה:** `maintenance.cleanup` — מוחקת sessions שפגו ומשימות `done` מעל 30 יום. רצה פעם ביום
  (`dedupe_key` לפי תאריך).
- **בלי גיבוי של "רענון עמודים"** (§18: "שליחת תזכורות לא תסתמך על רענון עמודים") — אם cron לא רץ, הבדיקה מתריעה,
  והמטלה לבעלים היא להגדיר אותו.

## 9. `app.json`

```json
{
  "id": "sforno", "name": "ספורנו", "icon": "📚", "category": "daily", "entry": "index.html",
  "version": "0.1", "gate": true, "restricted": true,
  "public": ["cron.php", "api.php?action=health|devapi-feed|devapi-comment|devapi-file|devapi-attach"]
}
```

- `health` בלי משתמש מחזיר רק `{ "ok": true|false, "version": "…" }`.
- `cron.php` — ציבורי, ומוגן באסימון (סעיף 8.5).
- `devapi-*` — בלי עוגייה, אחרי `devloop_api_auth()` (אסימון `sfd_`).
- **שום מסך ושום פעולה אחרת לא ציבוריים** בפרוסה הזו. `restricted` נשאר.

## 10. המעטפת בממשק

- כותרת: "ספורנו", שם המנהל, 🐞 (למפתח), תפריט: ניהול, מנהלים, פיתוח (למפתח), יציאה.
- `#/` — מסך הבית (§4): הכפתורים המרכזיים — סידור · תהילים · הלכה יומית · זמני היום · חנות — כל אחד למסך "בקרוב"
  עם שם הרכיב. (הקיצורים האישיים, המידע היומי והמשך הקריאה — בפרוסות שלהם.)
- `#/admin` — מסך ריק מסודר ("כאן יופיעו התוכן, הייבוא וההלכה היומית").
- `#/admin/staff` — רשימת המנהלים ופעולות סעיף 6.
- שגיאת רשת / 401 / 403 / 503 — הודעה אחת אחידה בעברית, עם "נסו שוב".
- עיצוב: `phone-first-design`; צבע וגופן כמשתנים במקום אחד (📦 §17 יחליפו אותם); David Libre כגופן חלופי.
- נגישות (§17): ניווט במקלדת, סדר מיקוד, תוויות לכל כפתור, `prefers-reduced-motion`.

## 11. בדיקות

`php sforno/tests/run.php` — רץ ב-CLI, יוצא בקוד שונה מ-0 בכישלון, בלי רשת ובלי שרת, על מסד זמני.

## 12. קריטריוני קבלה

🤖 — בדיקה אוטומטית ב-`tests/run.php`. 👁 — בדיקה ידנית אחרי פריסה.

| # | קריטריון | איך |
| --- | --- | --- |
| 1 | בקשה ראשונה על מסד ריק יוצרת את כל הטבלאות; בקשה שנייה לא משנה דבר; `schema_version` = האחרונה | 🤖 |
| 2 | זהות עם `granted: true` ואין חשבון ← נפתח `staff` פעיל; עם `platform_admin` ← גם `is_developer` | 🤖 |
| 3 | `granted: false` או חסר ← 401, ואין חשבון חדש | 🤖 |
| 4 | חשבון בלי `sub` ובאותו מייל מאומת ← מתקשר ולא נפתח שני; מייל לא מאומת ← לא מתקשר | 🤖 |
| 5 | `platform_admin` עבר מ-`true` ל-`false` ← `is_developer` כבה בבקשה הבאה, ונרשם `staff.developer_off` | 🤖 |
| 6 | session של `sub` אחר ← מוחלף; בקשה בלי `PLATFORM_USER` ← ה-session נמחק | 🤖 |
| 7 | `POST` בלי `X-CSRF` תקין ← 403; `GET` לפעולה משנה ← 405 | 🤖 |
| 8 | מנהל שאינו מפתח שמבקש `dev-*` או את מסך שירותי הרקע ← 403 | 🤖 |
| 9 | אי אפשר להשעות את המנהל הפעיל האחרון, ומנהל לא משעה את עצמו; מנהל מושעה ← 403 בבקשה הבאה | 🤖 |
| 10 | כל שינוי בסעיף 6 כותב שורה ב-`audit_log` באותה טרנזקציה; יומן שנכשל מפיל את השינוי | 🤖 |
| 11 | שגיאה בשרת מחזירה JSON בעברית בלי trace, ונרשמת ביומן הטכני עם מזהה הבקשה | 🤖 |
| 12 | `health` בלי משתמש מחזיר רק `ok` ו-`version` | 🤖 |
| 13 | `cron.php` בלי אסימון או עם אסימון שגוי ← 404; עם אסימון תקין ← מריץ, מעדכן `cron_last_ok_at`; שתי הרצות במקביל ← השנייה יוצאת בלי לעבוד | 🤖 |
| 14 | משימה שנכשלת ← `attempts` עולה ו-`run_after` נדחה; אחרי `max_attempts` ← `failed`; `dedupe_key` כפול ← לא נכנסת שנייה | 🤖 |
| 15 | `grep -rnE "(require|include)[^;]*\.\./" sforno/lib sforno/api.php sforno/cron.php` ריק; `grep -rn "mini_projects" sforno --include=*.php --include=*.js` ריק; אין `../` ב-HTML/JS | 🤖 |
| 16 | `php platform/check-apps.php` ו-`php devloop/tools/sync.php --check` עוברים | 🤖 |
| 17 | בדפדפן `data/sforno.sqlite`, `data/cron.token`, `lib/db.php`, `tests/run.php`, `dev/open-items.json` ← לא 200 | 👁 |
| 18 | המפתח נכנס מהפלטפורמה בלי מסך נוסף, רואה את מסך הבית, 🐞, ואת חמש הלשוניות של `#/dev` | 👁 |
| 19 | מסך הבדיקות רץ בשרת, וכל שורות §24 מלאות; התוצאות ב-`dev/server-report.md` | 👁 |
| 20 | אחרי שהבעלים הגדיר cron — בדיקת המתזמן ✅ ו-`maintenance.cleanup` רצה | 👁 |
| 21 | 🐞 פותח מטלה עם ההקשר, והיא מופיעה בדשבורד הפיתוח (`platform/devboard.php?app=sforno`) | 👁 |
| 22 | משתמש פלטפורמה בלי הרשאה ל-`sforno` נחסם בשער | 👁 |
| 23 | בטלפון (רוחב 360) ובמקלדת בלבד: הכותרת, מסך הבית ורשימת המנהלים שמישים, בלי גלילה אופקית, RTL תקין | 👁 |

## 13. מה הקוד מגיש עם ה-PR

- רשימת "נבנה" / "לא נבנה" מול סעיף 1, וכל סטייה מ-⚙️ — ב-`team/decisions.md`.
- פלט `tests/run.php`, `check-apps.php`, `sync.php --check`.
- `dev/open-items.json` עם המטלות, ו-`README.md` מעודכן (מה בנוי, מה טרם הוכרע).
- אחרי הפריסה: `dev/server-report.md` (קריטריון 19) — הבסיס להחלטות על ייבוא, תזכורות ו-WooCommerce.
