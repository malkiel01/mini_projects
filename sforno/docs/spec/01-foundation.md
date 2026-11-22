# 01 — תשתית: מסד, התחברות צוות, צוות ותפקידים, ומסך המפתח

**הפרוסה הראשונה לקוד.** בסופה אין עדיין ספר אחד — אבל יש כל מה שכל פרוסה אחרת עומדת עליו: מסד עם מיגרציות,
איש צוות מזוהה דרך הפלטפורמה, תפקידים, יומן פעולות, מעטפת ממשק, ומפתח שרואה מה קורה במערכת.

**קריאת חובה לפני הקוד:** `00-overview.md` (סעיף 3 כולו), `platform/docs/auth.md`, `platform/docs/sso.md` §0,
`devloop/README.md`, `.claude/skills/devloop/references/install.md` ו-`log.md`, הסקיל `phone-first-design`.
דוגמאות קיימות לזיהוי דרך המעטפת: `document-templates/lib/platform.php`, `appraisers/lib/` —
**לקרוא, לא לעשות להם `require`.**

## 1. מה בונים

1. מסד SQLite עם מיגרציות שרצות לבד.
2. זיהוי איש צוות דרך הפלטפורמה (מצב מחוברת), חשבון משלה מקושר ל-`sub`, ו-session משלה.
3. תפקידים: מפתח, מנהל, עורך תוכן — וניהול צוות (⏳ ש14).
4. יומן פעולות (audit).
5. מסך המפתח: DevLoop, יומן בקשות ושגיאות, יומן פעולות, בדיקות תקינות.
6. מעטפת הממשק: כותרת, ניתוב, מסך בית עם ששת הכפתורים **כמקום שמור** ("בקרוב"), אזור ניהול ריק, הודעות שגיאה.
7. בדיקות אוטומטיות שרצות ב-CLI.

## 2. מה לא בפרוסה הזו

| מה | איפה |
| --- | --- |
| ספרים, עורך, תגיות | 02 |
| הקורא | 03 |
| לוח עברי, תכנים יומיים | 04 |
| זמנים | 05 |
| PWA, service worker | 06 |
| חשבונות קוראים, כל כתובת לציבור | 07, 09 |
| מצב מנותק ו-`sso.php` | 10 |
| שירותי רקע / cron | אין צורך עדיין. ייכנס בפרוסה הראשונה שצריכה (⚙️) |

## 3. מבנה הקבצים ⚙️

המלצה, לא חוזה — הכללים שמתחת לעץ הם החוזה.

```
sforno/
  app.json                 + "public" ו-"version" (סעיף 9)
  index.html               המעטפת — lang="he" dir="rtl"
  api.php                  הנתב היחיד
  lib/                     .htaccess חוסם
    db.php  migrations/NNN-*.php
    http.php               JSON, שגיאות, CSRF, מזהה בקשה, בסיס הכתובת
    platform.php           הזהות מהמעטפת — הנקודה היחידה שקוראת PLATFORM_USER
    auth.php               חשבונות, session, המשתמש הנוכחי
    access.php             ההרשאות — מקום אחד
    audit.php              יומן הפעולות
    health.php             רישום והרצה של בדיקות תקינות
    devtasks.php           החיבור ל-DevLoop
    devloop.php            ← עותק מסונכרן. לא עורכים
  assets/js/   app.js  api.js  router.js  screens/*.js  devloop.js ← עותק
  assets/css/  app.css  devloop.css ← עותק
  assets/fonts/ DavidLibre-Regular.ttf  DavidLibre-Bold.ttf  OFL-davidlibre.txt   (⏳ ש11)
  dev/open-items.json      מפת הדרכים; dev/.htaccess חוסם הכול (הפלטפורמה קוראת מהדיסק)
  tests/run.php            .htaccess חוסם
  data/                    sforno.sqlite, _dev/, log.sqlite — לא בגיט, מוחרג בפריסה
```

- `lib/`, `tests/`, `data/` — חסומים מהדפדפן ב-`.htaccess` משלהם. `data/.htaccess` כבר קיים בריפו ונשאר.
- **אין `require` לשום קובץ מחוץ ל-`sforno/`.** הגופן — **עותק** מ-`mbe-cloud/fonts/builtin/`, עם הרישיון.
- **אין נתיב קבוע:** בסיס הכתובת ונתיב העוגייה נגזרים מתיקיית `api.php` בזמן ריצה (`00` §3.3).
- אין קישור "חזרה לכלים", ואין `../` בשום HTML או JS.

## 4. מודל הנתונים

מוסכמות לכל הטבלאות, גם בפרוסות הבאות:

- `id` — מספר רץ פנימי, **לא יוצא מהשרת.** `pid` — מזהה ציבורי אקראי (16 תווים, `[a-z0-9]`), ייחודי; רק הוא
  בכתובות וב-API.
- `created_at`, `updated_at` — UTC, ‏ISO-8601.
- SQLite במצב WAL, מפתחות זרים דלוקים, `utf8` (ברירת המחדל של SQLite — מתאים לעברית).

```
staff                                     אנשי צוות בלבד. קוראים — טבלה נפרדת בפרוסה 07, לא כאן
  id, pid
  platform_sub        TEXT UNIQUE NULL    ה-sub מהמעטפת (מחרוזת ספרות)
  email               TEXT                באותיות קטנות; ייחודי כשאינו NULL
  email_verified      INT
  name                TEXT
  role                admin | editor      (⏳ ש14)
  is_developer        INT                 = platform_admin של הזהות, מתעדכן בכל כניסה
  status              active | suspended
  last_seen_at, created_at, updated_at

sessions
  id, staff_id
  token_hash          TEXT UNIQUE         רק ה-hash של האסימון שבעוגייה
  csrf                TEXT
  platform_sub        TEXT                למי ה-session שייך
  ua, created_at, last_used_at, expires_at

audit_log                                 רק מוסיפים; אין עדכון ואין מחיקה
  id, at
  actor_staff_id      INT NULL            NULL = המערכת
  actor_kind          staff | system
  action              TEXT                קוד: staff.create, staff.role …
  entity_type, entity_pid
  meta                JSON                מזהים ומצבים בלבד — לא תוכן
  req                 TEXT                מזהה הבקשה — מקשר ליומן הטכני

meta                                      key / value
  schema_version, platform_base, platform_config_cache …
```

> **למה `staff` ולא `users`:** כדי שקוראי הציבור (⏳ ש2) לא יתערבבו עם הצוות — לא בטבלה, לא ב-session ולא
> בהרשאות. שם נפרד מונע את הטעות מראש.

## 5. התחברות (מצב מחוברת)

### 5.1 המצב

`platform_mode()` ב-`lib/platform.php` מחזיר `connected` · `detached` · `unavailable`:

- יש `$_SERVER['PLATFORM_USER']` תקין ← `connected`.
- אחרת — `platform/sso.php?action=config&client=sforno`, בכתובת שנבנית מ-`SERVER_NAME` ומ-`meta.platform_base`
  (ברירת מחדל: `/mini_projects/platform/`), תשובה נשמרת 60 שניות, כישלון — 15.
- ב-CLI ובבדיקות בלבד (`PHP_SAPI === 'cli'`): `SF_PLATFORM` קובע את המצב, `SF_TEST_IDENTITY` מזריק זהות.

בפרוסה הזו `detached` ו-`unavailable` ← כל פעולה שאינה ציבורית מחזירה 503 "הכניסה לא זמינה כרגע". **נכשל סגור.**

### 5.2 בכל בקשה

1. הזהות: `{sub, name, email, email_verified, platform_admin, granted}`.
2. `granted` אינו `true` ← אין משתמש (401). לא סומכים על כך שהשער כבר חסם.
3. מחפשים `staff` לפי `platform_sub`.
4. לא נמצא, ויש `email` מאומת ← `staff` באותו מייל בלי `platform_sub` ← מקשרים (`staff.link`).
5. עדיין לא נמצא ← **פותחים חשבון מיד, פעיל** (`staff.create`), בתפקיד `editor`; מי ש-`platform_admin` — `admin`.
   ההרשאה בפלטפורמה היא האישור; אין אישור כפול.
6. `is_developer` ← `platform_admin` שבזהות, לשני הכיוונים (`staff.developer_on` / `_off`, המבצע: המערכת).
7. שם ומייל מתעדכנים מהזהות כשהשתנו.
8. `suspended` ← 403 "החשבון הושעה — פנו למנהל".
9. העוגייה `sf_session`: אסימון אקראי, `HttpOnly`, `Secure`, `SameSite=Lax`, נתיב = בסיס הכתובת. אין session, או
   שהוא של `sub` אחר ← חדש, והישן נמחק. תוקף: 30 יום מהשימוש האחרון. **לא session של PHP.**
10. בקשה בלי `PLATFORM_USER` ← אין משתמש, ומוחקים את ה-session (יציאה מהפלטפורמה = יציאה מכאן).

> **אין "מסך חיבור חד-פעמי"** (`auth.md` §4): אין חשבונות ישנים בסיסמה — ספורנו נולדת מחוברת.

### 5.3 הגנות

- כל פעולה משנה: `POST`, גוף JSON, כותרת `X-CSRF` (מגיע מ-`me`). חסר או שגוי ← 403.
- כל תשובה: `X-Request-Id`, ‏`Cache-Control: no-store`.
- שגיאה: `{ "ok": false, "error": { "code": "…", "message": "…בעברית…" } }` — בלי SQL, נתיב קובץ או trace.

## 6. צוות ותפקידים (⏳ ש14)

| פעולה | מי |
| --- | --- |
| `me`, יציאה | כל איש צוות פעיל |
| אזור הניהול של התוכן (מפרוסה 02) | `admin`, `editor` |
| רשימת הצוות; שינוי תפקיד; השעיה וביטול השעיה | `admin` |
| מסך המפתח, DevLoop, בדיקות תקינות | `is_developer` |
| `health` הקצר, `devapi-*` | בלי משתמש — סעיף 9 |

- ההכרעה במקום אחד: `lib/access.php`. הנתב קורא לו לפני כל פעולה; המסכים רק מסתירים כפתורים.
- **תמיד נשאר `admin` פעיל אחד לפחות.** אי אפשר להשעות או להוריד את האחרון; מנהל לא משעה את עצמו.
- השעיה ושינוי תפקיד חלים **בבקשה הבאה** של אותו אדם.
- **הוספת איש צוות** — אין הזמנה בפרוסה הזו: מנהל הפלטפורמה נותן לו הרשאה ל-`sforno`, הוא נכנס, ונפתח לו חשבון
  `editor`. המנהל באפליקציה רואה אותו ברשימה ומשנה תפקיד.

## 7. יומן הפעולות

פונקציה אחת: `audit(action, entity, meta)`, **באותה טרנזקציה** של השינוי — יומן שנכשל מפיל את הפעולה.

- ב-`meta`: מזהים, מצב קודם וחדש, תפקיד. **לעולם לא:** אסימון, סיסמה, תוכן.
- הפעולות בפרוסה: `staff.create`, `staff.link`, `staff.role`, `staff.suspend`, `staff.unsuspend`,
  `staff.developer_on`, `staff.developer_off`, `devtoken.create`, `devtoken.revoke`.

## 8. מסך המפתח (`#/dev`) — `PROTOCOL.md` §7

רק ל-`is_developer`. ארבע לשוניות:

1. **📋 מטלות — DevLoop.** מהמודול המשותף בלבד: `lib/devtasks.php` → `devloop_setup` עם `db`, `items_file` =
   `dev/open-items.json`, `data_dir` = `data/_dev`, `prefix` = `SF-`, `token_prefix` = `sfd_`, `error`,
   `user_names`, `is_developer`, `audit`. היומן המובנה (בלי `log`): `devloop_log_begin` בראש `api.php`,
   `devloop_log_error` ב-catch. כל פעולות `dev-*` באותם שמות כמו ב-`install.md`. בדפדפן `DevLoop.setup({…,
   builtinLog: true, tasksHref: '#/dev/tasks'})` ו-`DevLoop.mount()` — 🐞 בכותרת, **רק למפתח**.
   `dev/open-items.json` נפתח עם מטלה לכל פרוסה שב-`00` §5 (`SF-001` והלאה).
2. **📜 יומן בקשות ושגיאות** — `DevLoop.showLog`: כל בקשה (פעולה, מצב, משך, מי), שגיאות PHP וחריגות, שגיאות
   JavaScript מהדפדפן. שגיאות דפדפן נרשמות לכל איש צוות מחובר. **לא נרשמים** גוף הבקשה או טקסט חופשי.
3. **🧾 יומן פעולות** — `audit_log` מהחדש לישן, סינון לפי פעולה, מבצע ותאריכים; מזהה הבקשה פותח את היומן הטכני.
4. **🩺 בדיקות תקינות** — כפתור "הרצה", שורה לכל בדיקה (✅ / ⚠️ / ❌ + מה לעשות), ברישום אחד (`health_register`)
   כדי שפרוסות הבאות יוסיפו:

| בדיקה | תקין כש- |
| --- | --- |
| המסד | `PRAGMA quick_check` = ok; WAL; מפתחות זרים דלוקים |
| גרסת הסכימה | שווה למיגרציה האחרונה שבקוד |
| כתיבה | `data/`, `data/_dev/` ניתנות לכתיבה |
| `data/` חסומה | `data/.htaccess` קיים, ובקשה עצמית ל-`data/sforno.sqlite` אינה מחזירה 200 |
| PHP | 8.3+; `pdo_sqlite`, `sodium`, `curl`, `mbstring`, `intl` (חסרה ← ⚠️: נדרשת ללוח העברי בפרוסה 04) |
| גרסה | `version` מ-`app.json` |
| המעטפת | `sso.php?action=config` עונה, ו-`connected` = `true` |
| DevLoop | שלושת העותקים קיימים; `dev/open-items.json` תקין |
| עצמאות | אין `require`/`include` לנתיב מחוץ לתיקייה (סריקה של `lib/` ו-`api.php`) |
| מקום בדיסק | מעל 200MB פנויים |

## 9. `app.json`

```json
{
  "id": "sforno", "name": "ספורנו", "icon": "📚", "category": "daily", "entry": "index.html",
  "version": "0.1", "gate": true, "restricted": true,
  "public": ["api.php?action=health|devapi-feed|devapi-comment|devapi-file|devapi-attach"]
}
```

- `health` בלי משתמש מחזיר רק `{ "ok": true|false, "version": "…" }`.
- `devapi-*` — בלי עוגייה, אחרי `devloop_api_auth()` (אסימון `sfd_`).
- **שום מסך ושום פעולה אחרת לא ציבוריים** בפרוסה הזו. `restricted` נשאר.

## 10. המעטפת בממשק

- כותרת: "ספורנו", שם איש הצוות, 🐞 (למפתח), תפריט: ניהול (למנהל ולעורך), צוות (למנהל), פיתוח (למפתח), יציאה.
- `#/` — מסך הבית: שישה כפתורים במרכז בסדר שב-`00` §1, כל אחד מוביל למסך "בקרוב" עם שם הרכיב.
- `#/admin` — מסך ריק מסודר ("כאן יופיעו הספרים והתכנים היומיים").
- `#/admin/staff` — רשימת הצוות (שם, מייל, תפקיד, מצב, נראה לאחרונה), ופעולות לפי סעיף 6.
- שגיאת רשת / 401 / 403 / 503 — הודעה אחת אחידה בעברית, עם "נסו שוב".
- עיצוב: `phone-first-design`, צבעי המותג מ-`00` §3.2, הגופן David Libre לכותרות ולטקסט.

## 11. בדיקות

`php sforno/tests/run.php` — רץ ב-CLI, יוצא בקוד שונה מ-0 בכישלון, בלי רשת ובלי שרת. משתמש במסד זמני.

**מחוץ ל-`sforno/` — לבקרה בלבד (`PROTOCOL.md` §8):** הרישום ב-`devloop/apps.json` וב-`.devloop.json`, וצעד
ב-`deploy.yml` שמריץ את `tests/run.php` ועוצר את הפריסה. צ'אט הקוד מבקש, הבקרה עושה.

## 12. קריטריוני קבלה

כל אחד נבדק בבדיקה אוטומטית (🤖) או ידנית אחרי פריסה (👁).

| # | קריטריון | איך |
| --- | --- | --- |
| 1 | בקשה ראשונה על מסד ריק יוצרת את כל הטבלאות; בקשה שנייה לא משנה דבר; `schema_version` = האחרונה | 🤖 |
| 2 | זהות עם `granted: true` ואין חשבון ← נפתח `staff` פעיל, `editor`; עם `platform_admin` ← `admin` ו-`is_developer` | 🤖 |
| 3 | `granted: false` או חסר ← 401, ואין חשבון חדש | 🤖 |
| 4 | חשבון בלי `sub` ובאותו מייל מאומת ← מתקשר ולא נפתח שני; מייל לא מאומת ← לא מתקשר | 🤖 |
| 5 | `platform_admin` עבר מ-`true` ל-`false` ← `is_developer` כבה בבקשה הבאה, ונרשם `staff.developer_off` | 🤖 |
| 6 | session של `sub` אחר ← מוחלף; בקשה בלי `PLATFORM_USER` ← ה-session נמחק | 🤖 |
| 7 | `POST` בלי `X-CSRF` תקין ← 403; `GET` לפעולה משנה ← 405 | 🤖 |
| 8 | `editor` שמבקש רשימת צוות או שינוי תפקיד ← 403; לא-מפתח שמבקש `dev-*` ← 403 | 🤖 |
| 9 | אי אפשר להשעות או להוריד את ה-`admin` הפעיל האחרון, ומנהל לא משעה את עצמו | 🤖 |
| 10 | כל שינוי בסעיף 6 כותב שורה ב-`audit_log` באותה טרנזקציה; יומן שנכשל מפיל את השינוי | 🤖 |
| 11 | שגיאה בשרת מחזירה JSON בעברית בלי trace, ונרשמת ביומן הטכני עם מזהה הבקשה | 🤖 |
| 12 | `health` בלי משתמש מחזיר רק `ok` ו-`version` | 🤖 |
| 13 | `grep -rnE "(require|include)[^;]*\.\./" sforno/lib sforno/api.php` ריק; `grep -rn "mini_projects" sforno --include=*.php --include=*.js` ריק; אין `../` ב-HTML/JS | 🤖 |
| 14 | `php platform/check-apps.php` ו-`php devloop/tools/sync.php --check` עוברים | 🤖 |
| 15 | בדפדפן `data/sforno.sqlite`, `lib/db.php`, `tests/run.php` ← לא 200 | 👁 |
| 16 | המפתח נכנס מהפלטפורמה בלי מסך נוסף, רואה את ששת הכפתורים, 🐞, ואת ארבע הלשוניות של `#/dev`; הבדיקות התקינות ✅ (או ⚠️ מוסבר) | 👁 |
| 17 | 🐞 פותח מטלה עם ההקשר, והיא מופיעה בדשבורד הפיתוח של הפלטפורמה (`platform/devboard.php?app=sforno`) | 👁 |
| 18 | משתמש פלטפורמה בלי הרשאה ל-`sforno` נחסם בשער (האפליקציה `restricted`) | 👁 |
| 19 | בטלפון (רוחב 360): הכותרת, מסך הבית ורשימת הצוות בלי גלילה אופקית; RTL תקין | 👁 |

## 13. מה הקוד מגיש עם ה-PR

- רשימת "נבנה" / "לא נבנה" מול סעיף 1, וכל סטייה מ-⚙️ — ב-`team/decisions.md`.
- פלט `tests/run.php`, `check-apps.php`, `sync.php --check`.
- `dev/open-items.json` עם המטלות, ו-`README.md` מעודכן (מה בנוי, מה טרם הוכרע).
