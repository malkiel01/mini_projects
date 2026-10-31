# ענן החבילה — שלב 1: השלד (אפיון מפורט)

**בעלים:** הצ'אט של ענן החבילה. **מקור:** `creative-suite/docs/spec/shared.md` (סעיפים 2, 3, 8) וההכרעה ב-
`creative-suite/docs/requests/spec-shared.md`. **סטטוס (2026-10-30):** האפיון אושר — כל ארבע ההכרעות התקבלו (סעיף 11). הבא: PR השלד.

---

## 1. מה נכנס בשלב הזה, ומה לא

| נכנס | לא נכנס (השלב שבסוגריים) |
| --- | --- |
| `app.json`, דף אחד בעיצוב `phone-first-design` | ספריות: גופנים (2), צבעים וסגנונות (3), נכסים ותבניות (3) |
| חשבון חבילה — הזהות מהשער | כניסה בסיסמה משלו כשהענן מנותק מהפלטפורמה (5) |
| כונן פנימי עם מכסה שהמנהל קובע | כוננים חיצוניים: Dropbox, Drive, SFTP / WebDAV, תיקייה במחשב (4) |
| API קבצים: רשימה, קריאה, כתיבה, תיקייה, העברה, שינוי שם, סל | גרסאות, חיפוש, "אחרונים", התראות, צוותים (5) |
| חיבור אפליקציה: `connect`, אסימון חתום ומוגבל, ניתוק | הצפנת אסימונים של ספקים — אין עדיין ספקים |
| לקוח הענן (JS + PHP) — מודול מסונכרן עם שער בפריסה | |

## 2. מבנה התיקייה

```
suite-cloud/
  app.json            הרישום בפלטפורמה
  index.html          הדף: כוננים, קבצים, חיבורים, ניהול (lang="he" dir="rtl")
  api.php             כל הפעולות — ?action=<פעולה>
  connect.php         מסך "X מבקשת גישה לענן שלך"
  lib/                db.php, account.php, drive.php, clients.php, tokens.php — הכול בתוך התיקייה
  assets/css/app.css  assets/js/app.js  assets/icon.svg
  client/             לקוח הענן — המקור (סעיף 8)
    src/js/  src/php/  apps.json  tools/sync.php
  tests/run.php       בדיקות CLI (מכסה, חתימות, אסימונים, שמות)
  data/               ← בשרת בלבד, לא בגיט (חוץ מ-.htaccess)
    .htaccess         Require all denied
    cloud.sqlite      חשבונות, עץ הקבצים, לקוחות, חיבורים, הגדרות
    sign.key          מפתח החתימה של הענן (Ed25519, לאסימונים) — נוצר בשרת בהפעלה הראשונה, 0600
    secret.key        מפתח ההצפנה של מפתחות הספקים (spec-3-ai.md) — כמו sign.key
    files/ab/cd/<sha256>   תוכן הקבצים
    uploads/<id>/     העלאות בחלקים שטרם הושלמו
```

**הפריסה** — שתי שורות ב-`.github/workflows/deploy.yml`, **ב-PR של השלד, לפני כל כתיבה לשרת** (אותה תבנית כמו
`recipes-app/data/media/`):

```
--include='suite-cloud/data/.htaccess' \
--exclude='suite-cloud/data/*' \
```

ובנוסף `--exclude='/suite-cloud/client/tools/' --exclude='/suite-cloud/tests/'` — כלים של הריפו, לא של השרת.

## 3. `app.json`

```json
{
  "id": "suite-cloud",
  "name": "ענן החבילה",
  "icon": "☁️",
  "description": "הענן של חבילת היצירה — חשבון אחד, כוננים זה לצד זה וספריות משותפות לכל האפליקציות.",
  "category": "documents",
  "entry": "index.html",
  "gate": true,
  "restricted": true,
  "public": ["api.php?action=cloud_key|client_register|connect_exchange|access_token|disconnect|quota|list|stat|read|mkdir|write|upload_start|upload_chunk|upload_finish|rename|move|trash|restore|trash_list|purge|trash_empty"]
}
```

- **`public` לפעולות ה-API** — כי אפליקציה מתקשרת שרת-לשרת, בלי עוגיית הפלטפורמה. הכתובת ציבורית אבל לא פרוצה:
  כל פעולה דורשת חתימה של לקוח רשום או אסימון של הענן (סעיף 7). בלי אחד מהם — 401.
- **`connect.php` אינו ציבורי** (שונה במימוש, 2026-10-31): השער עצמו מפנה מי שלא מחובר לכניסה (`?next=`) ומחזיר
  לכאן, ובודק שיש לו הרשאה לענן. כך אין צורך בבדיקה כפולה. אסימון הטופס — ב-session בעוגייה משלנו (`SUITECLOUD`).
- **בטוח ש-`public` לא פותח את הדף לכולם:** השער כותב `PLATFORM_USER` רק למי שיש לו הרשאה לענן (`gate-prepend.php`).
  בכתובת ציבורית בלי הרשאה — אין זהות, ולכן נדרשת חתימה או אסימון.
- **`restricted`** — עד שהשלד נבדק (הכרעה 3).

## 4. החשבון

| שדה | מה |
| --- | --- |
| `id` | מזהה אקראי של החשבון בענן (לא ה-`sub`) |
| `sub`, `email`, `name` | מ-`$_SERVER['PLATFORM_USER']` — מתעדכנים בכל כניסה. **אותו מייל = אותו אדם** (`sso.md` סעיף 4) |
| `role` | `admin` למי שמגיע עם `platform_admin: true`; השאר `user`. התפקיד — של הענן, לא של הפלטפורמה |
| `quota`, `used` | בבתים. `quota` — מה שהמנהל קבע (ברירת מחדל — 0, הכרעה 1) |
| `cloud_id` | **אחד לכל ענן** (לא לכל חשבון): מזהה אקראי שנכנס ל-`lib.cloud` בקבצים (`shared.md` סעיף 5) — לא כתובת ולא מייל |

- **אין חשבון → נפתח מיד, פעיל** (ההרשאה בפלטפורמה היא האישור — `auth.md` סעיף 3), במכסת ברירת המחדל.
- **בלי `PLATFORM_USER`** (רק בכתובת ציבורית) → אין משתמש ממשק. ה-API עובד רק עם חתימה / אסימון.
- **עצמאות:** הטבלה מחזיקה מייל ושם משלה — כשהענן יצא לדומיין משלו או יתנתק, נוסיף כניסה משלו (שלב 5) בלי הגירה.

## 5. הכונן הפנימי

**העץ במסד, התוכן בדיסק.** טבלת `nodes`:

| שדה | מה |
| --- | --- |
| `id` | `n_` + 16 תווים אקראיים — יציב גם אחרי שינוי שם והעברה (האפליקציות שומרות אותו) |
| `account`, `parent` | `parent = null` — שורש הכונן |
| `kind` | `folder` / `file` |
| `name` | עד 200 תווים; בלי `/ \ : * ? " < > |` ובלי תווי בקרה; לא `.` / `..`; NFC |
| `size`, `sha256`, `mime` | לקובץ |
| `rev` | מונה שעולה בכל כתיבה — לבדיקת התנגשות (`if_rev`) |
| `created_at`, `updated_at`, `updated_by` | `updated_by` — החשבון, ואיזה לקוח (אפליקציה) כתב |
| `trashed_at` | `null` — חי. אחרת — בסל |

- **התוכן** — `data/files/ab/cd/<sha256>`. אותו תוכן פעמיים — קובץ אחד בדיסק, אבל **המכסה סופרת כל קובץ בנפרד**
  (מה שהמשתמש רואה = מה שנספר). קובץ בדיסק נמחק כשאף `node` כבר לא מפנה אליו.
- **שם תפוס באותה תיקייה** → `ספר (2).mbe`, אלא אם `on_conflict: "replace"` (כתיבה מחדש לאותו קובץ) או `"fail"`.
- **מכסה** — נבדקת **לפני** העלאה (`upload_start` עם הגודל), ושוב בסיום. חריגה → `quota_exceeded`, בלי כתיבה.
  קובץ בסל נספר במכסה עד שהוא נמחק לצמיתות.
- **תקרה לקובץ** — הגדרת מנהל, ברירת מחדל 500MB (הכרעה 2). **העלאה בחלקים** של 8MB: עוקף את `upload_max_filesize` של השרת, וממשיך
  אחרי ניתוק. העלאה שלא הושלמה — נמחקת אחרי 24 שעות.
- **סל** — `trash` מסמן; `restore` מחזיר (לתיקייה המקורית, ואם היא נמחקה — לשורש). אחרי **30 יום** — מחיקה לצמיתות
  (ניקוי עצל: בכל קריאה, לכל היותר פעם בשעה). "רוקן סל" — מהממשק.

## 6. API הקבצים

`api.php?action=<פעולה>`. `POST` עם JSON (חוץ מ-`read` ו-`upload_chunk`). תשובה: JSON. שגיאה:
`{"error":"<עברית>","code":"<קוד>"}`.

| פעולה | גוף | תשובה |
| --- | --- | --- |
| `quota` | — | `{quota, used, max_file}` |
| `list` | `{folder?, trashed?:false}` | `{folder, path:[{id,name}], items:[node]}` |
| `stat` | `{id}` | `node` |
| `read` | `GET ?action=read&id=…` | התוכן (`Content-Type`, `ETag` = `sha256`, `Range`) |
| `mkdir` | `{parent?, name, on_conflict?}` | `node` |
| `write` | `{parent?, name, content_b64, mime?, if_rev?, on_conflict?}` — קובץ עד 4MB בקריאה אחת | `node` |
| `upload_start` | `{parent?, name, size, sha256, mime?, if_rev?, on_conflict?}` | `{upload, chunk:8388608}` |
| `upload_chunk` | `PUT ?action=upload_chunk&upload=…&index=N` — גוף בינארי | `{received}` |
| `upload_finish` | `{upload}` — בודק גודל ו-`sha256` | `node` |
| `rename` | `{id, name, on_conflict?}` | `node` |
| `move` | `{id, parent}` — לא לתוך עצמו | `node` |
| `trash` / `restore` | `{id}` | `node` |
| `trash_list` | — | `{items:[node]}` |

**`node`** = `{id, kind, name, parent, size, sha256, mime, rev, created_at, updated_at, updated_by:{name, app}}`.

**קודי שגיאה:** `unauthorized` (401), `forbidden` (403 — אין `files` בהרשאות), `not_found` (404 — גם קובץ של חשבון
אחר), `conflict` (409 — `if_rev` לא תואם; מחזיר את ה-`rev` הנוכחי), `name_taken`, `bad_name`, `quota_exceeded`,
`too_large`, `bad_checksum`, `stale` / `replay` / `bad_signature` / `pending` / `unknown_client` (סעיף 7).

**`if_rev`** — "שמור רק אם לא השתנה מאז שקראתי". כך שתי אפליקציות שכותבות לאותו `.mbe` לא דורסות זו את זו בשקט:
השנייה מקבלת `conflict` ומציעה "שמור כעותק".

## 7. חיבור אפליקציה

**העיקרון: אין סוד שעובר ביד או נשמר בדפדפן לאורך זמן.** כמו `embed.md`: לאפליקציה זוג מפתחות Ed25519 משלה (במסד
שלה), והענן מוריד את הציבורי מהכתובת שלה. כל קריאה שרת-לשרת חתומה. לדפדפן — רק אסימון קצר שהענן חתם.

### 7.1 רישום לקוח — פעם אחת, אוטומטית

```
POST suite-cloud/api.php?action=client_register
{"client":"vector-studio","name":"וקטורי",
 "key_url":"https://mbe-plus.com/mini_projects/vector-studio/api.php?action=cloud_key",
 "origins":["https://mbe-plus.com"],
 "return":["https://mbe-plus.com/mini_projects/vector-studio/api.php?action=cloud_callback"]}
→ {"status":"active"}   // או "pending" — ממתין למתג אצל מנהל הענן
```

בדיוק כללי `embed.md` סעיף 1: המפתח לא נשלח — הענן מוריד אותו; אותו origin → `active`, אחר → `pending`; שינוי
`key_url` / `origins` / `return` → חזרה ל-`pending`, אף פעם לא דריסה שקטה.

### 7.2 החיבור — המשתמש מאשר פעם אחת

```
אפליקציה                                      ענן החבילה
 "☁️ חיבור לענן החבילה"
  │ ── redirect ──▶ suite-cloud/connect.php?client=<id>&scope=files&state=<אקראי>&return=<כתובת>
  │                  לא מחובר → כניסה (השער) וחזרה
  │                  "וקטורי מבקשת גישה לקבצים שלך בענן — אשר / בטל"
  │ ◀── redirect ── <return>?code=<חד-פעמי, 2 דקות>&state=…       (או ?error=access_denied)
  │ ── POST (חתום) connect_exchange {code} ──▶
  │ ◀── {connection, account:{name}, scope:["files"], cloud_id}
```

- **`scope`** — בשלב הזה `files` בלבד; אחר כך `libs`, `prefs`. מבקשים רק מה שצריך; המסך מפרט כל אחד.
- אישור קיים לאותו לקוח ואותן הרשאות → בלי מסך, ישר חזרה (כמו `sso.md`).
- **`connection`** — מזהה, **לא סוד**: לבדו הוא לא פותח כלום — רק עם חתימת הלקוח שקיבל אותו.

### 7.3 קריאות שרת-לשרת (PHP של האפליקציה)

אותה חתימה כמו `embed.md` סעיף 2, בשמות משלנו: `X-Cloud-Client`, `X-Cloud-Time`, `X-Cloud-Nonce`,
`X-Cloud-Signature`, ועוד `X-Cloud-Connection`. המחרוזת: `<action>\n<time>\n<nonce>\n<sha256 של הגוף>`.
±300 שניות, nonce לא חוזר בתוך 10 דקות.

### 7.4 קריאות מהדפדפן (JS של האפליקציה)

העלאה ישירה מהדפדפן חוסכת לעבור דרך השרת של האפליקציה. לכן:

```
POST (חתום) access_token {connection}  →  {token, expires_in:900}
```

- `token` = `<payload>.<signature>` ב-base64url, חתום במפתח של הענן (`cloud_key` — ציבורי). ה-payload:
  `{iss, aud:<client>, conn, acc, scope, iat, exp, jti}`.
- הדפדפן שולח `Authorization: Bearer <token>`. **CORS** — רק ל-`origins` של הלקוח.
- 15 דקות; הלקוח (סעיף 8) מבקש חדש לבד. נשמר בזיכרון בלבד — לא `localStorage`.

### 7.5 ניתוק

- מהענן ("🔗 חיבורים" בדף) או מהאפליקציה (`disconnect`, חתום). **נבדק בכל קריאה** — גם אסימון שעוד לא פג נדחה מיד.
- מנהל הענן יכול לכבות לקוח שלם — כל החיבורים שלו נעצרים.
- ניתוק לא מוחק קבצים. האפליקציה שומרת עותק מקומי (`shared.md` סעיף 2: "עובדת בלי הענן").

### 7.6 מה אפליקציה מחוברת רואה

**כל הכונן של המשתמש** (הכרעה 4) — אחרת "פתח ב…" בין אפליקציות לא עובד. כל קובץ רושם איזו אפליקציה כתבה אותו.

## 8. לקוח הענן — מודול מסונכרן

כמו `creative-suite/mbe-core`: מקור אחד ב-`suite-cloud/client/src/{js,php}`, `client/apps.json`
(`{"<app>":{"js":"…","php":"…"}}`), `client/tools/sync.php` (בונה ומעתיק; `--check` — שער בפריסה, צעד חדש ב-
`deploy.yml` ליד השערים של הליבה). **`apps.json` מתחיל ריק** — אפליקציה נכנסת רק בהסכמת הצ'אט שלה.

| צד | מה הוא נותן |
| --- | --- |
| **PHP** | יצירת מפתחות ושמירתם במסד של האפליקציה, `cloud_key` (התשובה לכתובת המפתח), `register()`, `connectUrl()`, `exchange()`, `call()` חתום, `accessToken()` |
| **JS** | `cloud.connected()`, `cloud.list/stat/read/write/upload(file, onProgress)/rename/move/trash`, חידוש אסימון לבד, `conflict` כשגיאה מוגדרת |

**בלי ענן — הכול עובד כמו היום:** הלקוח מחזיר `connected() === false` והאפליקציה לא מציגה את הכונן. הלקוח לא
זורק שגיאה רק משום שאין ענן.

## 9. הממשק (דף אחד, `phone-first-design`)

| מסך | מה |
| --- | --- |
| **כוננים** | שורה לכל כונן — היום רק "☁️ הענן שלי" עם פס מכסה ("1.2 מתוך 5 ג"ב") |
| **קבצים** | פירורי לחם, רשימה (שם, גודל, מתי, איזו אפליקציה), העלאה בגרירה / בכפתור, תפריט שורה: שינוי שם, העברה, הורדה, לסל |
| **סל** | שחזור, מחיקה לצמיתות, רוקן |
| **🔗 חיבורים** | האפליקציות שאישרת והרשאותיהן — ניתוק |
| **⚙️ ניהול** (רק `admin`) | מכסת ברירת מחדל, תקרה לקובץ, מכסה לכל חשבון, לקוחות (מתג ל-`pending`), שימוש כולל בדיסק |

## 10. הסדר — ארבעה PR-ים

1. **האפיון הזה.**
2. **השלד:** `app.json`, ה-`--include`/`--exclude` ב-`deploy.yml`, חשבון, כונן פנימי, API קבצים, הממשק, בדיקות.
3. **חיבור אפליקציה:** `client_register`, `connect.php`, `connect_exchange`, חתימות, `access_token`, CORS, ניתוק.
4. **לקוח הענן:** `client/` + `sync.php` + שער בפריסה. אחר כך — הזמנה לכל צ'אט להצטרף (בלי לגעת בתיקייה שלו).

כל PR — `php platform/check-apps.php` ו-`php suite-cloud/tests/run.php` לפני push; `[x]` במסמך הפרויקט אחרי
`conclusion: success`.

## 11. הכרעות בעל המערכת (2026-10-30)

| # | שאלה | הכרעה |
| --- | --- | --- |
| 1 | מכסת ברירת מחדל לחשבון חדש | **0 עד שהמנהל מאשר** (התקבלה ההמלצה) — כמו הכרעה 2 ("בנפח שמנהל אישר"); המנהל מגדיל בשורה אחת |
| 2 | תקרה לקובץ בודד | **500MB** (התקבלה ההמלצה) — מספיק לספר עם תמונות, ושומר על דיסק השרת |
| 3 | מי רואה את הענן בדף הכלים | **`restricted`** (התקבלה ההמלצה) עד שהשלד נבדק — רק מי שאושר ישירות |
| 4 | מה אפליקציה מחוברת רואה | **כל הכונן** (התקבלה ההמלצה) — זה מה שמאפשר "פתח ב…"; האישור הוא פעם אחת לכל אפליקציה |

