# ענן MBE — שלב 2: שיתוף לקריאה, חשבון שירות ואסימון אישי (אפיון)

**בעלים:** "ענן MBE - אפיון". **הקוד:** "ענן MBE - קוד". **מקור:** `spec-0-vision.md` סעיפים 3.2, 6.4 ו-7, והכרעה 7.
**הלקוח הראשון:** "אסטרטגיות למסחר". Claude שלהם קורא תיקייה ששותפה איתו, בלי דפדפן ובלי עוגייה. הדרישות שלהם
(ד1–ד7) נמצאות בסעיף 8, וכל אחת מהן נבדקת שם.

> סטטוס (2026-11-08): **טיוטה לבדיקה** של הבקרה של הענן ושל הבקרה של "אסטרטגיות למסחר". שתי ההחלטות הטכניות
> (400 ב-JSON, והתחילית `mbe_pat_`) אושרו בבקרה. אין כאן הכרעה שדורשת את בעל המערכת.

---

## 1. מה נכנס ומה לא

> **מפתח הקריאה הוא החריג היחיד, והוא לצ'אט של Claude בלי מסך בלבד** (הכרעת בעל המערכת, 2026-11-08). הוא **אינו**
> דרך חיבור של אפליקציה. אפליקציה מתחברת רק דרך מודול הכוננים ו-`connect.php` (`api-guide.md` סעיף 1). לכן:
> אסימון נוצר רק לחשבון שירות, ההיקף שלו הוא רק `shared.read`, ואין `client` שמקבל אותו בחיבור.


| נכנס | לא נכנס (מתי) |
| --- | --- |
| שיתוף תיקייה או קובץ לחשבון אחר, **קריאה בלבד**, וביטול מיידי | תפקידים `edit` ו-`comment`, צוותים, וקישור לאורח (ע5) |
| חשבון שירות (`kind = 'service'`) שמנהל הענן יוצר | כניסה לממשק של חשבון שירות (לעולם לא) |
| אסימון אישי (PAT) **לחשבון שירות**, בהיקף `shared.read` | אסימון לאדם. אותו קוד, אבל אין לו עדיין לקוח, ולכן הוא נדחה כדי לצמצם את משטח התקיפה |
| `shared_roots`, ו-`list` / `stat` / `read` לפי `id` על מה ששותף | "שותף איתי" דרך אסימון של אפליקציה מחוברת (היקף `files`). זה שלב 3 |
| 429 אחרי כישלונות חוזרים, ו-`token_log` | השורה של `trading-strategies` ב-`drives/apps.json`. מתאמים איתם את הנתיבים כשמגיעים לשם |
| 400 ב-JSON לבקשה עם אסימון לכתובת הישנה `suite-cloud/` | |

## 2. המסד

שלוש טבלאות חדשות ועמודה אחת. ההגירה כמו בכל הענן: `CREATE TABLE IF NOT EXISTS`. את העמודה מוסיפים רק אם היא
לא קיימת (`PRAGMA table_info`), ולכן פריסה חוזרת לא נכשלת.

```sql
ALTER TABLE accounts ADD COLUMN kind  TEXT NOT NULL DEFAULT 'person';   -- 'person' | 'service'
ALTER TABLE accounts ADD COLUMN owner TEXT;                             -- לחשבון שירות: האדם שאחראי עליו

CREATE TABLE IF NOT EXISTS shares (
    id          TEXT PRIMARY KEY,                -- 'sh_' + 16
    node        TEXT NOT NULL REFERENCES nodes(id),
    owner       TEXT NOT NULL REFERENCES accounts(id),
    grantee     TEXT NOT NULL REFERENCES accounts(id),
    role        TEXT NOT NULL CHECK (role IN ('read')),
    created_at  TEXT NOT NULL,
    created_by  TEXT NOT NULL,
    revoked_at  TEXT
);
CREATE INDEX IF NOT EXISTS shares_grantee ON shares(grantee, revoked_at);
CREATE INDEX IF NOT EXISTS shares_node    ON shares(node, revoked_at);

CREATE TABLE IF NOT EXISTS tokens (
    id          TEXT PRIMARY KEY,                -- 8 תווים [a-z0-9], החלק הגלוי של האסימון
    account     TEXT NOT NULL REFERENCES accounts(id),
    name        TEXT NOT NULL,                   -- "Claude — אסטרטגיות למסחר"
    hash        TEXT NOT NULL,                   -- sha256 (hex) של הסוד. הסוד עצמו לא נשמר בשום מקום
    scope       TEXT NOT NULL,                   -- '["shared.read"]'
    created_at  TEXT NOT NULL,
    created_by  TEXT NOT NULL,
    expires_at  TEXT,                            -- null = ללא תפוגה (חשבון שירות בלבד)
    used_at     TEXT,
    revoked_at  TEXT
);

CREATE TABLE IF NOT EXISTS token_log (
    id      INTEGER PRIMARY KEY,
    token   TEXT,                                -- tokens.id, או null כשהאסימון לא זוהה
    action  TEXT NOT NULL,
    node    TEXT,
    ip      TEXT NOT NULL,
    status  INTEGER NOT NULL,                    -- קוד ה-HTTP שהוחזר
    at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS token_log_tok ON token_log(token, at);
CREATE INDEX IF NOT EXISTS token_log_ip  ON token_log(ip, status, at);
```

- **חשבון שירות ב-`accounts`:** ב-`email` (`NOT NULL UNIQUE`) נכתבת כתובת שלא קיימת,
  `svc_<id>@service.invalid`. אין לו `platform_sub`, ולכן השער לא יכול להיכנס אליו. `quota = 0`: אין לו כונן משלו.
- **`token_log` עם `ip` ו-`status`** משמש גם למונה הכישלונות (סעיף 5), ולכן אין צורך בטבלה נפרדת.
- **ניקוי:** שורות ב-`token_log` מעל 90 יום נמחקות בניקוי העצל, שרץ פעם בשעה כמו הסל.

## 3. האסימון

**הצורה:** `mbe_pat_<id>_<secret>`

| חלק | מה |
| --- | --- |
| `mbe_pat_` | התחילית. כך `actor()` מבדיל אותו מאסימון אפליקציה **לפני** ניסיון פענוח, וסורקי סודות מזהים אותו בדליפה |
| `<id>` | 8 תווים `[a-z0-9]`, מ-`random_bytes`. נשמר גלוי, ובאמצעותו מוצאים את השורה בלי לסרוק את הטבלה |
| `<secret>` | 32 בתים מ-`random_bytes`, ב-base64url בלי ריפוד, כלומר 43 תווים |

**הבדיקה, בסדר הזה:**

1. **IP חסום (סעיף 5)** → 429. זה קורה עוד לפני שבודקים את האסימון.
2. **הצורה לא תואמת** → 401 `unauthorized`.
3. **אין שורה עם `id`** → מחשבים בכל זאת sha256 ו-`hash_equals` מול ערך קבוע, ואז 401. כך הזמן לא מגלה אם ה-`id` קיים.
4. **`hash_equals(row.hash, sha256(secret))` נכשל** → 401.
5. **`revoked_at` או `expires_at` עבר** → 401. ההודעה זהה בכל המקרים: "האסימון לא תקף".
6. **החשבון חסום או נמחק** → 401.
7. **אחרת:** עדכון `used_at` (לכל היותר פעם ב-10 דקות, כמו `connections`), ומחזירים
   `['account' => …, 'app' => 'pat:<id>', 'pat' => row, 'scope' => ['shared.read']]`.

**איפה האסימון:** `Authorization: Bearer …`. הענן קורא גם `REDIRECT_HTTP_AUTHORIZATION`, כי בשרת הזה (cPanel/CGI) הכותרת
לא תמיד מגיעה כ-`HTTP_AUTHORIZATION`, וגם `X-Cloud-Token`, כמו לאסימון של אפליקציה. **אסימון בכתובת (`?token=`,
`?access_token=`) נדחה ב-400**, ולא מתעלמים ממנו. כך מי שטעה ושלח אותו בכתובת שומע על זה מיד.

**תוקף (הכרעה 7):** ברירת המחדל 90 יום. לחשבון שירות מותר גם "ללא תפוגה" (`expires_at = null`). אין חידוש של אסימון:
יוצרים חדש ומבטלים את הישן.

**הסוד מוצג פעם אחת:** בתשובה של `token_create` בלבד. אחר כך הדף מציג רק `mbe_pat_<id>_…`, השם, מתי נוצר, מתי נעשה בו
שימוש ומתי הוא פג.

## 4. הרשאה בכל קריאה

**העיקרון:** אין מטמון של הרשאה. כל `list` / `stat` / `read` עם אסימון מחשב מחדש אם הפריט נגיש.

```
sharedNode(grantee, id):
  n = nodes[id]                                   — לא קיים → 404
  לכל צומת בשרשרת מ-n ועד השורש (n, n.parent, …):
      בסל (trashed_at) → 404
      יש לו share פעיל (revoked_at IS NULL) ל-grantee → נגיש. root = הצומת הזה
  לא נמצא share → 404
  הבעלים חסום → 404
```

- **תמיד 404, ואף פעם 403** (ד5). זה חל על קובץ שלא שותף, שיתוף שבוטל, קובץ שהועבר אל מחוץ לתיקייה המשותפת, קובץ
  בסל וקובץ של בעלים חסום. מי שמבחוץ לא יכול להבחין בין "לא קיים" ל"קיים ואסור".
- **עומק השרשרת קטן**, ולכן הבדיקה היא כמה שאילתות לפי מפתח ראשי. בלי אינדקס נוסף.
- **שבירת השרשרת כלפי מעלה:** בתשובה לבעל `shared.read`, השדה `parent` של שורש השיתוף הוא `null`, ו-`path` של `list`
  מתחיל בשורש השיתוף. **שמות התיקיות של הבעלים מעל השיתוף לא נחשפים לעולם.**
- **`updated_by`** בתשובה מכיל רק את `app`, בלי שם הבעלים. גם זה מידע שאינו חלק מהשיתוף.

## 5. 429 — כישלונות חוזרים

- **הספירה:** שורות ב-`token_log` עם `status = 401`, מאותו `ip`, ב-10 הדקות האחרונות.
- **הסף:** 10 כישלונות → כל בקשה עם אסימון מאותה כתובת מקבלת 429 (`code: "rate_limited"`, שונה מ-`quota` של ה-AI) עם `Retry-After`, עד 15 דקות מהכישלון העשירי.
  החסימה חלה גם על אסימון תקף, כדי שתוקף לא ינצל אסימון תקף אחד כדי לבדוק אחרים.
- **הכתובת:** `REMOTE_ADDR` בלבד. `X-Forwarded-For` לא נחשב, כי אין פרוקסי לפני השרת, ומי שמבחוץ היה מזייף אותו.
- **החסימה חלה רק על אסימון אישי.** הדף של הענן ואסימוני אפליקציה לא נחסמים בגלל זה.

## 6. ה-API

**לבעל האסימון** (`public` ב-`app.json`, בלי עוגייה):

| פעולה | גוף | תשובה |
| --- | --- | --- |
| `shared_roots` | — | `{items:[{id, name, kind, size, mime, updated_at, share:{id, owner_name, created_at}}]}`. רק שיתופים פעילים |
| `list` | `{folder}`, חובה | כמו היום: `{folder, path, items}`, עם `path` שמתחיל בשורש השיתוף. בלי `folder` → 400 |
| `stat` | `{id}` | `node`: `{id, kind, name, parent, size, sha256, mime, rev, created_at, updated_at}` |
| `read` | `GET ?action=read&id=…` | `sendFile` הקיים: `Content-Type`, `Content-Length`, `ETag` לפי sha256, `Range` (טווח אחד, 206 או 416) ו-`HEAD` |

**כל פעולה אחרת עם אסימון אישי → 403 `forbidden`** (ד6). זה כולל `write`, `mkdir`, `upload_*`, `rename`, `move`,
`trash`, `purge`, `share_*`, `token_*`, `ai_*` ו-`font_*`. הבדיקה נעשית ב-`actor()`, לפי רשימה סגורה
`PAT_ACTIONS = ['shared_roots','list','stat','read']`. רשימה פתוחה, שבה שוכחים לחסום פעולה חדשה, היא בדיוק
הטעות שאנחנו רוצים למנוע.

**לבעלים, בדף של הענן** (עוגייה בלבד, **לא** ב-`public`):

| פעולה | גוף | מה |
| --- | --- | --- |
| `share_create` | `{node, grantee}` | `grantee` הוא מייל של חשבון קיים, או `id` של חשבון שירות. לחשבון שירות משתף רק הבעלים שלו או מנהל הענן. שיתוף כפול לאותו `grantee` ואותו `node` מחזיר את הקיים |
| `share_list` | `{node?}` | השיתופים שיצרתי. עם `node`, רק של הצומת הזה |
| `share_revoke` | `{id}` | `revoked_at = now`. **מיידי**, כי הבדיקה נעשית בכל קריאה |

**למנהל הענן** (עוגייה ו-`admin`):

| פעולה | גוף | מה |
| --- | --- | --- |
| `service_create` | `{name, owner}` | `owner` הוא מייל של אדם. מחזיר חשבון שירות חדש |
| `service_list` | — | חשבונות השירות, עם הבעלים, האסימונים והשיתופים איתם |
| `service_delete` | `{id}` | מבטל את כל האסימונים ואת כל השיתופים איתו, ואז מוחק |
| `token_create` | `{account, name, expires_days: 90 \| null}` | **התשובה היחידה שבה מופיע הסוד:** `{token:"mbe_pat_…", id, expires_at}`. `null` מותר רק לחשבון שירות |
| `token_list` | `{account?}` | בלי הסוד ובלי `hash` |
| `token_revoke` | `{id}` | מיידי |
| `token_log` | `{token, cursor?}` | 100 שורות אחרונות: פעולה, צומת (שם ו-`id`), כתובת, קוד ומתי |

**401 בלי אסימון ובלי עוגייה → JSON** (ד2): `{"error":"נדרשת התחברות","code":"unauthorized"}`, ולא דף התחברות.
השער מעביר בקשה לפעולה שב-`public` בלי זהות, ו-`actor()` הוא שמחזיר את ה-401.

**`app.json`:** ל-`public` נוסף רק `shared_roots`. `list`, `stat` ו-`read` כבר שם.

## 7. הכתובת הישנה — 400 ב-JSON (ד1)

בקשה ל-`suite-cloud/` **עם** `Authorization` או `X-Cloud-Token` לא מופנית. היא מקבלת 400, כי לקוח שעוקב אחרי הפניות
היה מעביר את האסימון הלאה בלי לדעת. בלי אסימון, ה-308 נשאר כמו שהוא, בשביל סימניות ועותקים ישנים.

```apache
# suite-cloud/.htaccess — לפני ה-308
ErrorDocument 400 /mini_projects/mbe-cloud/errors/moved.json
RewriteCond %{HTTP:Authorization} . [OR]
RewriteCond %{HTTP:X-Cloud-Token} .
RewriteRule ^ - [R=400,L]
```

```json
// mbe-cloud/errors/moved.json — קובץ סטטי, Content-Type: application/json לפי הסיומת
{"error":"הענן עבר לכתובת חדשה. שלחו את הבקשה ישירות אליה","code":"moved","use":"https://mbe-plus.com/mini_projects/mbe-cloud/api.php"}
```

- **`mbe-cloud/errors/` נפרס** ואינו תחת `data/`.
- **בדיקה בפריסה:** `curl -s -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer x' …/suite-cloud/api.php?action=list`
  מחזיר `400`. אותה כתובת בלי הכותרת מחזירה `308`.
- **`…/mbe-cloud/api.php` עונה ישירות, בלי שום הפניה** (ד1). ה-Network secret של "אסטרטגיות למסחר" קשור לנתיב הזה
  בדיוק. אסור להוסיף לו הפניה בעתיד, למשל לסלאש בסוף או ל-`www`.

## 8. הבדיקות — `mbe-cloud/tests/run.php`

כל בדיקה רצה מול מסד זמני, עם שני חשבונות אנשים (A, הבעלים, ו-B), חשבון שירות S, ועץ:
`A:/ראשי/שיטות/ספר.pdf`, `A:/ראשי/פרטי.txt`.

| # | דרישה | הבדיקה |
| --- | --- | --- |
| ד1 | נתיב סופי בלי הפניה | בקשה ל-`mbe-cloud/api.php` עם Bearer → 200 ולא 3xx. בדיקה בפריסה (סעיף 7): `suite-cloud/` עם Bearer → 400 JSON עם `code: "moved"`, ובלי Bearer → 308 |
| ד2 | שער ו-401 JSON | `list` בלי Bearer ובלי עוגייה → 401, `Content-Type: application/json`, `code: "unauthorized"`. `shared_roots` עם PAT תקף ובלי עוגייה → 200 |
| ד3 | "שותף איתי" | שיתוף של `שיטות` עם S → `shared_roots` מחזיר רק אותו. `list {folder: שיטות}` מחזיר את `ספר.pdf`. ב-`path` אין `ראשי`. `parent` של `שיטות` הוא `null` |
| ד4 | קבצים גדולים | קובץ של 40MB: `stat` מחזיר `size`, `sha256` ו-`updated_at`. `read` מחזיר `Content-Length` נכון. `Range: bytes=0-1023` → 206, `Content-Range` ו-1024 בתים. `Range: bytes=-100` → 100 הבתים האחרונים. טווח מעבר לסוף → 416 |
| ד5 | 404 ולא 403 | (א) `stat` על `פרטי.txt` → 404. (ב) `share_revoke`, ואחריו מיד `stat` על `ספר.pdf` → 404. (ג) `move` של `ספר.pdf` ל-`ראשי` → 404. ובנוסף: `ספר.pdf` בסל → 404, `id` שלא קיים → 404, והגוף **זהה** בכל המקרים |
| ד6 | בלי כתיבה | עם PAT: `write`, `mkdir`, `upload_start`, `rename`, `move`, `trash`, `share_create`, `token_create`, `ai_run`, `font_add` → כולן 403 `forbidden`, ושום דבר במסד לא השתנה |
| ד7 | הסוד לא נרשם | יוצרים אסימון ומבצעים 20 קריאות, תקינות ושגויות. הסוד (43 התווים) **לא מופיע** בקובץ המסד, ב-`token_log`, בפלט `error_log` של הריצה, ובאף גוף תשובה חוץ מ-`token_create`. `token_list` לא מחזיר `hash` |
| ת1 | תוקף | `expires_at` בעבר → 401. `revoked_at` → 401. ההודעה זהה ל"אסימון שגוי" |
| ת2 | 429 | 10 אסימונים שגויים מאותה כתובת → האחד-עשר, **גם תקף**, מקבל 429 עם `Retry-After`. כתובת אחרת לא מושפעת |
| ת3 | אסימון בכתובת | `?token=mbe_pat_…` → 400 |
| ת4 | רק לבעלים | B מנסה `share_create` ל-S → 403. אסימון אפליקציה (`files`) של B לא רואה את `שיטות` |
| ת5 | מחיקת חשבון שירות | `service_delete` → האסימון מחזיר 401, והשיתוף לא מופיע ב-`share_list` של A |
| ת6 | אסימון אפליקציה לא נשבר | אסימון קיים של 15 דקות (בלי `mbe_pat_`) עובד בדיוק כמו קודם בכל הפעולות |

## 9. הממשק (`phone-first-design`, דרך `ui-core`)

| איפה | מה |
| --- | --- |
| **תפריט השורה בקבצים** | "👥 שיתוף…" → גיליון: מי רואה (שורה לכל שיתוף, עם ✕ לביטול) והוספה לפי מייל או חשבון שירות. רק "קריאה" |
| **סימון בעץ** | 👥 קטן ליד תיקייה או קובץ ששותפו |
| **⚙️ ניהול ← 🤖 חשבונות שירות** (מנהל בלבד) | שורה לכל חשבון שירות: בעלים, אסימונים, שיתופים איתו. "＋ אסימון" פותח גיליון: שם ותוקף (90 יום או ללא תפוגה). אחר כך מוצג הסוד **פעם אחת**, עם 📋 העתקה ועם הכיתוב "לא יוצג שוב". לכל אסימון: מתי נעשה בו שימוש, 📜 היומן, ו"בטל" |

## 10. הסדר בתוך ה-PR

1. מסד והגירה (סעיף 2).
2. `actor()`: פיצול לפי `mbe_pat_`, `PAT_ACTIONS` ו-429.
3. `sharedNode()` ושלוש פעולות הקריאה, ואחריהם `shared_roots`.
4. פעולות הבעלים והמנהל.
5. הממשק.
6. `suite-cloud/.htaccess` ו-`mbe-cloud/errors/moved.json`.
7. הבדיקות (סעיף 8), ובדיקה בפריסה ל-ד1.

**לפני push:** `php platform/check-apps.php` ו-`php mbe-cloud/tests/run.php`. **אחרי המיזוג:** `conclusion: success`,
ואז מנהל הענן יוצר את חשבון השירות ואת האסימון, משתף את התיקייה, ומעביר את האסימון לבעל המערכת. **האסימון לא עובר
בצ'אט.** בעל המערכת מכניס אותו כ-Network secret בסביבה של "אסטרטגיות למסחר".
