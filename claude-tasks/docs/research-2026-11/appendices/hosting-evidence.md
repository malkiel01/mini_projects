# ראיות אירוח (2026-11-08)

כל הנתונים מקוד/תיעוד בריפו. לא נגענו בייצור — כל ערך ייצור הוא [לא ידוע] אלא אם צוין.

## מה שאפשר לקבוע
| נושא | ממצא | תיוג | מקור |
|---|---|---|---|
| סוג אירוח | אחסון משותף (Bluehost/cPanel): נתיב `/home2/<user>/public_html/...`, "ב-cPanel: Select PHP Version", "cPanel → Terminal", spec: "Bluehost limits concurrent processes (typically 20–30)" | [מאומת] שהצוות מתייחס אליו כמשותף; המספר 20–30 הוא ציטוט כללי של כותב ה-spec, **לא נמדד** [לא ידוע בפועל] | deploy.yml:15; health.php; README:293; spec.md:227 |
| SSH ≠ VPS | יש SSH ו-rsync (deploy.yml), אך גם `jailshell` (בסקיל deploy-to-cpanel) — מעטפת מוגבלת של cPanel. אין להסיק VPS | [מאומת] אין ראיה ל-root/תהליכי רקע | deploy.yml; skill deploy-to-cpanel |
| PHP | 8.3 בשרת ("matches the server"); `health.php` דורש ≥8.0, כתוב בתחביר PHP5 כדי לרוץ גם כשהשאר נופל; בודק pdo_sqlite, mbstring, curl, sodium, openssl | [מאומת] | deploy.yml `setup-php 8.3`; health.php:15-57 |
| מסד | **SQLite** בקובץ `data/tasks.sqlite`, `journal_mode=WAL`, `busy_timeout=5000`, `foreign_keys=ON`. אין MySQL בשימוש בלוח | [מאומת] | lib/db.php:28-37 |
| נעילת כתיבה | WAL: קוראים לא חסומים, **כותב אחד בכל רגע**; כותב נוסף ממתין עד 5 ש' ואז SQLITE_BUSY. `migrate()` נקרא בכל `db()` (כלומר כל בקשה מריצה סדרת CREATE IF NOT EXISTS) | [מאומת] הקוד; העלות בפועל [לא נמדדה] | db.php:38 |
| WAL על NFS/ אחסון משותף | WAL דורש shared memory באותו host; אם home מותקן ב-NFS בין שרתים — בעיה ידועה. אין ראיה לכך כאן, ו-WAL פועל (בדיקות חיות עברו) | [השערה] סיכון נמוך, [לא ידוע] | — |
| תהליכי PHP | long-poll "תופס worker"; לכן `long_poll_max=0` | [מאומת] | channels.php:325-341 |
| משך בקשה | `set_time_limit($wait+15)` רק ב-long-poll; קריאות ל-AI/GitHub: cURL timeout 25 ש' (חיבור 8); זיקוק/סיכום ארוך — עד 150 ש' + `set_time_limit` (#1221, אחרי כשל חי "Operation timed out… 0 bytes"). `session_write_close` לפני קריאות AI | [מאומת] | ai.php:336-338; github.php:35; silence.php:535; api.php:1424; commit 634b270 |
| `max_execution_time` בשרת | [לא ידוע] (אין ערך בריפו; `set_time_limit` עשוי להיות מוגבל ע"י האחסון; ב-FPM גם request_terminate_timeout) | [לא ידוע] | — |
| יציאה לרשת | PHP יוצא ל-GitHub ול-Anthropic/OpenAI/Gemini ב-curl, ופעל בשימוש חי (#1221 תיעד שימוש ראשון באתר) | [מאומת] יציאה מותרת; [לא ידוע] allow-list/ rate | github.php, ai.php |
| תהליכים מתמשכים | אין daemon/worker בצד השרת; כל "מאזין" רץ בצ'אט (לקוח) לא בשרת. אין ראיה שניתן להריץ תהליך רקע בשרת | [הסקה] | spec §5.6 |
| cron | הלוח תוכנן במפורש "בלי cron" (lazy monitoring). אין ראיה אם cPanel Cron זמין — בדרך כלל זמין בחבילות cPanel | [לא ידוע] זמינות; [מאומת] העדר שימוש | spec:60, 974, 1153 |
| הגנת בוטים | בקשה בלי דפדפן מקבלת **409** + JS שקובע עוגייה `humans_21909=1`; עם `-b humans_21909=1` עוברת. השם עלול להשתנות; `lib.sh` מחלץ מ-409 ומנסה שוב; מנוע ה-Actions נחסם אף הוא ותוקן בשלב 2 | [מאומת] | spec:285-287, 358-361 |
| proxy/CDN | אין בריפו ראיה ל-Cloudflare/Varnish/Reverse proxy. הבלם בצד האחסון החזיר 429 לדומיין שלם, ו-508 מוזכר (קוד "Resource Limit Is Reached" אופייני לאחסוני cPanel) | [לא ידוע]; 508 [הסקה] | spec:241 |
| פריסה | rsync -rlptz **בלי --delete** (קבצים שרק בשרת נשארים); מחריג data/*.sqlite*, config.json, secret.key, `data/bell/`, `data/throttle/`, `models/`. SSH אחד בכל רגע; ה-workflow ממתין שאין deploy פעיל. יש retry על Connection closed | [מאומת] | deploy.yml:9,294-336; spec:826 |
| `data/bell` | קובצי stamp (mtime) להתעוררות long-poll; `channel-send` נוגע בהם. בלי long-poll — לא נחוצים (נשארו) | [מאומת] | spec:229-231 |
| "ניהול סביבה" במסך מנהל | שימו לב: "סביבה" בלוח = **סביבת Claude Code** (שם הסביבה שבה שמור `CLAUDE_TASKS_TOKEN`; api.php:161, 1547) — לא סביבת שרת. אבחון שרת = `health.php` (קישור "בדיקת הסביבה בשרת" ב-app.js:1553) | [מאומת] | api.php; app.js |
| מדדי עומס קיימים | `meter-*` ב-`data/throttle`: בקשות/דקה, 429/503 בשעה, מאזינים עכשיו (שלב 24א) — "המד הזה *הוא* מדידת §5.4" | [מאומת] קיים בקוד; לא הורץ | spec:1954, 1227 |

## למה תכנון התקשורת הקודם עמס
1. **Long-poll = worker תפוס:** כל בקשת `channel-wait` החזיקה תהליך PHP עד 20 ש'. מאזין שחזר אחרי 3 ש' ⇒ ~87% מהזמן תהליך תפוס *לצ'אט*. [חישוב; ראו load-model].
2. **קצב בקשות גבוה** גם בלי המתנה: ~2.6 בקשות/דקה/צ'אט, ועוד permission-status כל 10 ש' בזמן אישור, gate-check לכל כלי, שיקוף prompt/stop/activity. כל אלה עוברים PHP+SQLite.
3. **כתיבה בכל poll:** `touchChannel` מעדכן `listening_until` — כל poll הוא כתיבה לטבלה (SQLite כותב יחיד). [הסקה מ-channels.php:331 — לא נבדקה העלות].
4. **אין משוב נסיגה:** באג `$( )` — המאזין לא ראה 429/Retry-After והמשיך (נסיגה ≤ דקה) ⇒ עומס מצטבר על דומיין שכבר חסום. [מאומת, a6a788c]
5. **הבלם נמצא ברמת הדומיין:** 429 משותף לכל האפליקציות באותו חשבון (mbe-plus.com) — "blast radius" גדול מהלוח.
6. תקציב השרת (1/10 ש') נבדק עם קובץ+flock — אך רץ *בתוך PHP*, כלומר הבקשה כבר עלתה worker; הוא מקטין DB, לא תהליכים.
