# פרוסה 01 — אב-טיפוס מקצה לקצה

**המטרה:** המבחן של `owner-brief.md` §3, בגרסה הכי קטנה: הבעלים כותב בפלטפורמה, ותוך שניות מקבל תשובה מ-Claude
Code שרץ על המחשב שלו, וההודעה הבאה ממשיכה את אותה שיחה. שיחה אחת, מחשב אחד. ארכיטקטורה: `00-overview.md`.

## מה בונים

### שרת (PHP, תחת `claude-tasks/`)
1. `lib/chat/db.php` — `data/chat.sqlite`, טבלאות `bridge`, `conversation`, `message` (§3 באפיון), מיגרציה בדפוס של
   `lib/db.php`. בפתיחה הראשונה — שיחה אחת.
2. `chat-api.php` — פעולות. כל בקשה קצרה (בלי המתנה), JSON.
   - **בעלים** (עוגיית האפליקציה + Origin): `pair-code` (קוד 8 ספרות, 10 דק'), `send {body}`, `messages {since_seq}`,
     `status` (מחשב מחובר? last_seen, הודעה בטיפול).
   - **גשר** (`Authorization: Bearer br_…`): `bridge-pair {code, name}` → `{token, ntfy_topic}` (פעם אחת);
     `bridge-next` → הודעה אחת שנתפסה או `null`; `bridge-done {id, claim_id, body, session_id, cost_usd, duration_ms}`;
     `bridge-fail {id, claim_id, error}`; `bridge-hello {version, cwd_label, holding:[ids]}` — בעלייה, ודופק פעם ב-5 דק'.
3. צלצול: אחרי `send` ואחרי `bridge-done` — POST אחד ל-`https://ntfy.sh/<topic>` עם `{"t":"msg"}` / `{"t":"reply"}`,
   timeout 3 שניות; כשל לא מכשיל את הבקשה (נרשם ביומן השגיאות).
4. `chat-api.php` נכנס ל-`"public"` ב-`app.json` (הגשר פונה בלי עוגיית שער). הפעולות של הבעלים בודקות כניסה בעצמן.

### דף (`chat.html`, `assets/js/chat.js`, `assets/css/chat.css`)
5. מסך אחד, RTL, phone-first: היסטוריה, תיבת כתיבה, שורת מצב ("🟢 המחשב מחובר" / "🔌 לא מחובר" / "⏳ Claude עובד…").
   כפתור "חיבור מחשב" מציג קוד צימוד והוראה של שורה אחת.
6. עדכון: אחרי שליחה — `messages` כל 3 שניות **רק כל עוד יש הודעה שלא נענתה**, עד 10 דק', ואז רק ב-focus.
   (צלצול בדפדפן — פרוסה 02.) בלי בדיקה מתוזמנת כשאין הודעה ממתינה.
7. תשובה מוצגת כ-Markdown בטוח (בלי HTML גולמי). עלות ומשך — שורה קטנה מתחת לתשובה.

### גשר (`desk-bridge/`, Node 20+, בלי תלויות)
8. `desk-bridge/bridge.mjs pair <code> --url <base> --cwd <תיקייה>` → שומר `~/.claude-tasks-desk/config.json`
   (אסימון, נושא, cwd, `permissions` (ברירת מחדל `full`), `auth` (ברירת מחדל `api`), `max_budget_usd`, `anthropic_api_key`),
   הרשאות קובץ 600. `bridge.mjs config [key value]` מציג ומשנה. `auth` ו-`permissions` נקראים מחדש בכל הודעה.
9. `bridge.mjs run`: `bridge-hello` → `bridge-next` עד שאין → מחובר לזרם `https://ntfy.sh/<topic>/json`; על כל צלצול
   `msg` — `bridge-next`. זרם נפל → חיבור מחדש עם נסיגה (5, 30, 120 שניות); כל עוד הזרם למטה — `bridge-next` פעם בדקה.
10. ביצוע: `claude -p --output-format stream-json --verbose [--resume <cc_session_id>]` + דגלי ההרשאה מהקובץ
    (`00-overview.md` §5), cwd מהקובץ, הטקסט ב-stdin, `CT_BRIDGE_RUN=1`. ה-`session_id` וה-`result`/`total_cost_usd`
    נלקחים מהודעת `result` שבזרם. יציאה לא-0 או בלי `result` → `bridge-fail` עם 500 התווים האחרונים של stderr.
11. בלמים: 429/503/5xx מהשרת → נסיגה (דקה, 5, 15) ולא דפיקה חוזרת; Ctrl+C → SIGINT לריצה הפעילה, `bridge-fail "נעצר"`.
12. כל בקשה שולחת את עוגיית הגנת הבוטים. יומן מקומי `~/.claude-tasks-desk/bridge.log` (בלי טקסט ההודעות).

## קריטריוני קבלה
1. מחשב חדש: `node bridge.mjs pair <code> …` ואז `run` — המסך מראה "🟢 מחובר" תוך 5 שניות.
2. הודעה "מה יש בתיקייה הזו?" → תשובה שמפרטת קבצים אמיתיים מה-cwd, **תוך 15 שניות** מהשליחה (מעבר לזמן של Claude עצמו —
   העיכוב שלנו, מהשליחה עד תחילת הריצה, ≤ 3 שניות כשהצלצול פעיל).
3. הודעה שנייה "מה שאלתי קודם?" → עונה נכון (אותו `cc_session_id`, `--resume`).
4. שני גשרים מקבילים עם אותו אסימון (בדיקה): הודעה רצה **פעם אחת בדיוק**. בדיקה אוטומטית ב-PHP לתפיסה הכפולה.
5. הגשר נהרג באמצע ריצה → אחרי 30 דק' (או בעלייה הבאה) ההודעה `interrupted`, ובמסך "⚠ נקטע — לשלוח שוב?". לא רצה שוב לבד.
6. פרופיל `repo`: "תעשה git push" → לא מתבצע, התשובה מסבירה שנחסם (`permission_denials`). מעבר ל-`full` ב-`bridge.mjs config`
   → ההודעה הבאה כבר רצה עם push מותר, בלי הפעלה מחדש של הגשר. אותו דבר ל-`auth api|subscription`.
7. כשאין הודעה ממתינה והדף פתוח 10 דק' — **אפס** בקשות מהדף; הגשר — רק דופק (≤ 2 בקשות ב-10 דק').
8. ntfy חסום (בדיקה: נושא שגוי) → הודעה עדיין נענית, תוך ≤ 70 שניות.
9. אסימון גשר על פעולת בעלים (`send`) → 403. בלי כניסה → 401. Origin זר → 403.
10. `php -l`, `node --check desk-bridge/bridge.mjs`, `php platform/check-apps.php`, והבדיקות של האפליקציה — ירוקים.

## לבדוק תוך כדי (ולרשום ביומן הקוד)
- מגבלות ntfy.sh לפרסום אנונימי מ-IP של אחסון משותף (מכסה יומית לכל IP). אם צפוי לחסום — לדווח לאפיון; החלופה: נושא עם
  חשבון ntfy, או שרת ntfy אחר.
- ש-`--permission-prompts none` קיים בגרסת `claude` שבמחשב (v2.1.259+); אחרת — הגשר מסרב לעלות ומדפיס "עדכן את Claude Code".

## לא בפרוסה הזו
רשימת שיחות, צלצול בדפדפן, הודעה ישנה (ש5) — 02. טרמינל — 03. התקנה והפעלה עם המחשב — 04. כרטיס הרשאות — 05.
