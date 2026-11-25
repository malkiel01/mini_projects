# מטלות לקלוד — סקר מול הכללים (פרוסה 00)

**נכתב:** צ'אט הקוד, 2026-11-24. **ענף:** `claude/claude-tasks-audit`. **לא שונה שום קוד מוצר.**
**נמדד מול:** `.claude/skills/app-team/references/app-rules.md` (12 כללים), `CLAUDE.md`, `docs/owner-brief.md`, `docs/spec/00-overview.md` §7.
**סיווג:** 🔴 חובה לתקן · 🟡 מומלץ · ⚪ רקע (מידע, לא דורש פעולה).

מצב בדיקות קיים: כל 38 קובצי `tests/*.php` — `0 נכשלו` (הורצו בסקר). `php platform/check-apps.php` — `39 אפליקציות רשומות`.

---

## 1. טבלת הכללים (app-rules.md)

| # | הכלל | מצב | עיקר |
| --- | --- | --- | --- |
| 1 | רישום ב-`app.json`, `gate`, `restricted` | **חלקי** | יש `gate:true`; **אין `"restricted": true`** (ממצא A1) |
| 2 | התחברות דרך הפלטפורמה + ניתוק | ✅ | זהות מהשער + חזרה לסיסמה כשמנותקת (A2, רקע) |
| 3 | עצמאית | **חלקי** | אין `require` החוצה בקוד המוצר; יש תלויות בבדיקות ובסקילים (A3, A4) |
| 4 | DevLoop | ❌ | לא רשומה ב-`devloop/apps.json`, אין `.devloop.json` (A5) |
| 5 | יומן ובדיקות | **חלקי** | יש `events` ו-`logEvent`; אין מסך מפתח אחיד (A6) |
| 6 | משתמשים והרשאות | ✅ | `users`, `project_members`, `authorRole` |
| 7 | `dev/open-items.json` | ❌ | לא קיים (A7) |
| 8 | ענן MBE ותוספים | ✅ | "לא צריך" — האפיון החדש לא דורש ענן |
| 9 | נתונים מוגנים | **חלקי** | `data/*.sqlite*` מוחרג, אבל `data/studio-cron.log` ו-`data/**` אחר לא (A8) |
| 10 | בדיקות שעוצרות פריסה | ❌ | אין צעד של `claude-tasks/tests` ב-`deploy.yml` (A9) |
| 11 | כתובות ציבוריות | **חלקי** | נכון להיום; חסרות כתובות לצ'אט והגשר (B1) |
| 12 | עיצוב עברית/RTL/טלפון | לא נבדק בסקר זה | נבדק בפרוסת המסך |

---

## 2. ממצאים מול הכללים

### 🔴 חובה לתקן

**A1. אין `"restricted": true` בזמן פיתוח** — `claude-tasks/app.json:1-21` (אין את המפתח). הכלל (#1) דורש `restricted` בפיתוח.
בלעדיו, הרשאה לקטגוריית `dev` פותחת את הלוח לכל מי שיש לו אותה. מה לתקן: להוסיף `"restricted": true`.

**A5. DevLoop לא מחובר** — `devloop/apps.json` לא מכיל `claude-tasks`; אין `claude-tasks/.devloop.json`; אין 🐞 בכותרת
(`grep -rli devloop claude-tasks` → רק `team/PROTOCOL.md`). דורש: רשומה, `.devloop.json`, `php devloop/tools/sync.php` — **ורק בצ'אט הקוד, בפרוסה נפרדת** (נוגע בקבצים משותפים מחוץ לתיקייה: `devloop/apps.json`).
זה עניין של הבקרה לאשר, כי `devloop/` אינו של האפליקציה.

**A7. אין `dev/open-items.json`** — התיקייה `claude-tasks/dev/` לא הייתה קיימת לפני הסקר. הדשבורד (`platform/devboard.php?app=claude-tasks`) לא יקרא כלום.

**A9. בדיקות לא עוצרות פריסה** — `.github/workflows/deploy.yml:140-191` מריץ בדיקות של אפליקציות אחרות (`family-shopping`, `grave-prices`, `sforno`, `cyber-academy`…),
**ואין צעד ל-`claude-tasks/tests/*.php`**. ל-38 בדיקות עוברות; אף אחת לא חוסמת. הקובץ משותף — שינוי בו דרך הבקרה (לפי `PROTOCOL.md`: "הבקרה, ורק השורות של…").
הערה: בדיקות `lanes25.php:136`, `audit-s1s2.php:46,51`, `templates.php:229` קוראות `../../.claude/…` — מחוץ לתיקייה; הן ייכשלו אם הסקילים יימחקו (ראו A4). לפני חיבור לפריסה יש להחליט אם להשאיר אותן.

### 🟡 מומלץ

**A3. תלות בבדיקות מחוץ לתיקייה** — `tests/lanes25.php:136`, `tests/audit-s1s2.php:46,51`, `tests/templates.php:229` קוראות `../../.claude/skills/claude-tasks-live/SKILL.md` ו-`../../.claude/settings.json`.
קוד המוצר נקי (`grep require.*\.\./\.\.` — אין). **מומלץ:** בפרוסה שתנקה את הקוד הישן, למחוק או להעביר את הבדיקות האלה.

**A4. סקילים מחוץ לתיקייה מקושרים לקוד המוצר** — `lib/bridgever.php:22,26` שולח הוראות ללקוח הכוללות `.claude/settings.json`, `.claude/skills/claude-tasks-live/SKILL.md`, `claude-tasks/bridge/update.sh`
(וגם `lib/launcher.php:27`, `lib/permissions.php:35`). זה מודל הצ'אט-ענן שהוחלף (§3). מומלץ: לא להשתמש בהם בצ'אט החדש.

**A6. אין מסך מפתח אחיד** — יש `logEvent` (`api.php:118`) ו-`events`, `health.php`, אבל אין מסך "יומן בקשות ושגיאות / בדיקות תקינות" לפי כלל #5. לצ'אט החדש: לרשום ב-`events` כל `bridge-*` וכל כשל.

**A8. `data/` מוגן חלקית** — `deploy.yml:379` (`data/*.sqlite*`), `:390-393` (`config.json`, `secret.key`, `bell/`, `throttle/`).
**לא מוחרגים:** `data/studio-cron.log` (נכתב על ידי `deploy.yml:513`) ושאר קבצי ריצה עתידיים. `data/.htaccess` חוסם גישה מהדפדפן (✅), אבל rsync **כן** ידרוס קובץ ב-`data/` שגם קיים בריפו.
כיום בריפו רק `data/.gitignore` ו-`data/.htaccess`, ולכן אין סיכון בפועל. `chat.sqlite` מכוסה על ידי `data/*.sqlite*` (✅ — הנחת האפיון נכונה).
**כלל להמשך:** כל קובץ ריצה חדש ב-`data/` שאינו `.sqlite` — `--exclude` לפני הקוד.

**A10. אין `--exclude` ל-`claude-tasks/dev/`** — `deploy.yml` מוציא `team/` (שורה 355) אך לא `docs/`, `dev/`, `tests/`, `lab/`; הם יעלו לשרת. מומלץ: להוסיף `--exclude='/claude-tasks/dev/'` ו-`/claude-tasks/tests/` (בדומה ל-`funerals-dashboard/tests/`, שורה 361). דרך הבקרה.

### ⚪ רקע

**A2. התחברות** — `lib/platform.php:33-41` קורא `PLATFORM_USER` מהשער; `:70-72` שואל `sso.php?action=config&client=claude-tasks`; `api.php:109-124`
מנתק כשהפלטפורמה מנותקת. אין `"sso"` ב-`app.json` — לא נדרש כל עוד הלוח לא ממשק OAuth עצמאי (בדוגמאות `grave-prices/app.json:15` יש; כאן המנגנון ישן ועובד). לא לגעת.

**A11. אין קישור "חזרה לכלים"** — `grep '\.\./index.html'` על הקוד, ה-JS ו-HTML: אין. `assets/js/translate.js:19` ו-`translate-worker.js:13` משתמשים ב-`../../models/` — בתוך האפליקציה (✅). `android/…/PlatformLauncherActivity.java` לא פותח כתובות חיצוניות (אין `shouldOverrideUrlLoading`) — ✅.

**A12. עוגייה ומסד עצמאיים** — עוגיית `CLAUDETASKS` (`lib/auth.php:24`), מסד `data/tasks.sqlite` (`lib/db.php:17`). ✅.

**A13. `studio-cron.php` רץ כל דקה** — `deploy.yml:513`. נוגד את "אין שעונים" של `audit-2026-11.md §1` ואת ה-`brief` (פניות לפי אירועים). לא קשור לצ'אט; לדווח לבעלים/בקרה, לא לתקן כאן.

---

## 3. מה מתנגש עם האפיון החדש (צ'אט ↔ Claude Code דרך גשר)

| # | מה בקוד הקיים | קובץ:שורה | התנגשות |
| --- | --- | --- | --- |
| C1 | **מודל "צ'אט ענן מאזין"**: ערוצים, `messages`, `channels` — בנוי על צ'אט Claude בענן שמושך מטלות | `lib/channels.php`, `lib/db.php` (טבלאות `channels`, `messages`), `lib/listen.php`, `lib/dispatch.php` | האפיון החדש מפעיל Claude Code **מקומי** מהגשר. שמות הטבלאות `messages` ו-`channels` תפוסים — הצ'אט החדש חייב מסד נפרד `data/chat.sqlite` עם `message`/`conversation` (כבר כך בסעיף 3 באפיון) כדי שלא יתבלבלו |
| C2 | **המאזין ו-hooks**: `bridge/listen.sh` (long-poll עם נסיגה), `bridge/hook*.sh` | `bridge/listen.sh:9,105`, `bridge/lib.sh:64-78` | אילוץ "אין long-poll על PHP משותף" — כבר מכובד בקוד הישן ("יוצא בכל 429"). החדש לא ממחזר את הקוד, רק את הכללים |
| C3 | **`agent/run.mjs`** קורא ל-Messages API (לא `claude -p`) ופותח PR | `agent/run.mjs:108` (`ask`), `:207` (`openPr`) | מנוע של "סוכן שפותח PR", לא שיחה. אין `--resume`, אין זיכרון שיחה. לא מתאים לצ'אט |
| C4 | **`ANTHROPIC_API_KEY` כסוד GitHub** — המפתח נשלח ל-GitHub Actions של הריפו | `lib/engine.php:150`, `agent/claude-code.yml:19,94` | האפיון קובע: המפתח **נשאר במחשב** בקובץ הגשר ולא עובר לשרת (§5). הקוד הישן עושה הפוך. אסור לערבב |
| C5 | **`api.php` אחד ענק** — 1978 שורות, `switch` על `$action` | `api.php:161-162` (רשימת `$public`), `:117` | צ'אט חדש = **`chat-api.php` נפרד** (כך האפיון, §1). אל תוסיפו `case`ים ל-`api.php` |
| C6 | **תפקיד "owner" = `users.role='admin'`** | `lib/projects.php:153-156` | האפיון: "רק בעל האפליקציה". הערך קיים וניתן לשימוש: `authorRole($user) === 'owner'` |
| C7 | **שער `public`** | `app.json:9-17` | `chat-api.php` שהגשר פונה אליו בלי עוגיית משתמש חייב להיכנס ל-`"public"` (הגשר אינו דפדפן), אחרת השער יחסום אותו (כלל #11). **דרישה חדשה לפרוסה 01** |
| C8 | **הרשאת הגשר** | `lib/throttle.php:59` (`bridgeGuard`) | `bridgeGuard` נקרא `bridge*` כבר — **שם זהה** לגשר החדש (`bridge-next`, `bridge-done`) והוא שייך לגשר הישן. מומלץ לקרוא לפעולות החדשות בשם אחר או לתחום בקובץ נפרד, למניעת בלבול |
| C9 | **סשן בשני תהליכים** | — | הקוד הישן לא מכיר `--resume` ולא נעילת סשן. הכול חדש (פרוסה 03) |

---

## 4. מה ניתן למחזר (לפי §7 באפיון, אומת בקוד)

| מה | איפה | מצב | הערה |
| --- | --- | --- | --- |
| כניסה ומשתמש | `lib/auth.php:20-35,173-193` (`sessionStart`, `currentUser`, `sameOriginRequest`) | ✅ אומת | `sameOriginRequest` מחזיר true ל-GET, וממלא את Origin/Referer. תקין לשימוש בכל `POST` של הצ'אט |
| בעלות | `lib/projects.php:153` `authorRole` | ✅ | |
| מסד SQLite | `lib/db.php:22-30` (`db()`), `:305` (`addColumn`), `:335` (`nowTs`) | ✅ **חלקי** | `db()` קבוע על `DB_FILE`=`tasks.sqlite` (שורה 17). לפתיחת `chat.sqlite` צריך **פונקציה חדשה** באותו דפוס (`chatDb()`), לא שימוש ב-`db()` |
| הצפנה | `lib/crypto.php:45-113` (`secretKey`, `encryptSecret`, `secretTail`) | ✅ | לגיבוב אסימון הגשר — `hash()` רגיל מספיק (אסימון אקראי, לא סיסמה); `crypto.php` לא נדרש |
| בלם קצב | `lib/throttle.php:25-59` (`bridgeVerdict`, `bridgeBudget`, `bridgeGuard`) | **חלקי** | מותאם לגשר הישן (תקציב לפי אסימון עובד). ניתן להסתמך על הרעיון, לא על הפונקציות |
| נסיגה על 429/5xx | `bridge/lib.sh:64-78`, `bridge/listen.sh:91-105` | רקע | bash. הגשר החדש ב-Node; להעתיק את הכללים, לא את הקוד |
| עוגיית הגנת הבוטים | `humans_21909=1` — `agent/run.mjs:51`, `bridge/lib.sh:60`, `README.md:148` | ✅ | חובה בכל בקשה של הגשר לשרת |
| מבנה Node בלי תלויות | `agent/run.mjs`, `agent/finish.mjs` | רקע | דוגמה ל-`fetch` + `env`, בלי חבילות |
| עיצוב | `assets/css/app.css`, הסקיל `phone-first-design` | לא נבדק | |

**לא למחזר** (לפי §7): `lib/channels.php`, `bridge/*.sh`, `agent/run.mjs`, dispatcher (`lib/dispatch.php`, `lib/listen.php`). הסקר מאשר: כולם בנויים על המודל הישן, ואין בהם `--resume` או שיחה שנשמרת.

---

## 5. ממצאים שהאפיון צריך לדעת (B)

**B1. כתובות ציבוריות לצ'אט** — `app.json:9-17` כולל `api.php` ב-`public`. הגשר יפנה ל-`chat-api.php` בלי עוגייה; כדי שהשער לא יחסום, צריך להוסיף אותו שם. הבדיקה של אסימון הגשר (`br_…`) חייבת להיות **בקוד** ולא להסתמך על השער. (כבר ב-§5 של האפיון; כאן — תזכורת מימוש.)

**B2. `data/chat.sqlite` מכוסה** — `deploy.yml:379` מוציא `claude-tasks/data/*.sqlite*`. אין נתיב חדש. ✅ אין צורך בשינוי.

**B3. אין `cURL` מובטח** — `lib/platform.php:75-85` משתמש ב-`curl_init` עם גיבוי `file_get_contents`. שליחת צלצול ל-ntfy מהשרת צריכה אותו דפוס (timeout קצר, בלי כשל שעוצר את התשובה).

**B4. `claude-tasks/data/` לא מכיל בריפו נתוני ריצה** — רק `.gitignore`, `.htaccess`. מסד חדש נוצר בריצה הראשונה (`ensureDataDir`, `lib/crypto.php:36`).

**B5. השם `bridge` תפוס** — תיקיית `claude-tasks/bridge/` היא של הגשר הישן (bash). הגשר החדש (Node) — לא באותה תיקייה. הצעה: `claude-tasks/chat-bridge/`. (החלטה לאפיון/בקרה.)

**B6. שער הבדיקות ב-`app.json`** — `"install": {"release": "claude-tasks-store"}` (שורה 18-20): האפליקציה מפורסמת בחנות. שינוי ב-`app.json` צריך לא לשבור זאת.

---

## 6. סיכום עדיפויות (להצעת הבקרה)

1. **לפני פרוסה 01:** A1 (`restricted`), C7/B1 (`public` לצ'אט) — שורה אחת ב-`app.json` כל אחד.
2. **פרוסה 01 עצמה:** `chat-api.php` נפרד (C5), `chatDb()` נפרד (C1), שם לגשר ולפעולותיו שאינו מתנגש (C8, B5).
3. **דרך הבקרה (קבצים משותפים):** A9 (בדיקות בפריסה), A10 (`--exclude` ל-`dev/` ו-`tests/`), A5 (DevLoop).
4. **לדווח לבעלים:** A13 (cron כל דקה).
5. **רקע:** A2, A11, A12, B2, B4.
