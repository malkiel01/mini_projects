# בקשה לצ'אט של לוח "מטלות לקלוד": גשר חי, בשני הכיוונים, בין הלוח לצ'אטים

**מאת:** הצ'אט המתכלל של חבילת היצירה, לפי בקשת בעל המערכת (2026-11-01).
**אל:** הצ'אט של הלוח. בעל המערכת מעביר את הבקשה, כי זה פרויקט אחר (CLAUDE.md).
**זו בקשה, לא הוראה.** הלוח שלכם, ואתם מחליטים איך לבנות.
**הסדר:** הלוח קודם. חבילת היצירה מושהית עד שהגשר הזה עובד, ורק אחר כך נפתחים הצ'אטים החדשים ("היצירה v2 - …").
**קשור ל:** `requests/claude-tasks-command-center.md`. הבקשה הזו מרחיבה את סעיפים 3 (`inbox`), 4 (מסלול) ו-8 (הסקיל) שם, ולא מחליפה אותם.

## מה ביקש בעל המערכת

> תקשורת דו-צדדית ובו-זמנית בין הצ'אטים לפרויקט במטלות לקלוד. סקיל שכל צ'אט מקבל, וכך הוא יודע לדבר עם הלוח
> ישירות ובזמן אמת. אני רואה בלוח את השאלות ואת התשובות של קלוד, בין אם שאלתי בצ'אט ובין אם שאלתי בפרויקט בלוח —
> הכול בזמן אמת ובשני הכיוונים, כמו Remote Control. ההבדל: מה שאני כותב בלוח עובר ישר לצ'אט, והעיבוד נעשה על
> הטוקנים של הצ'אט. **מתג** בפרויקט מעביר את השאלות והתשובות לטוקנים של Claude Platform.

## מה מבוקש

### 1. ערוץ לכל צ'אט: "צ'אטים" בתוך פרויקט

לכל צ'אט שמחובר ללוח יש **ערוץ** בפרויקט שלו:
- שם, למשל `היצירה v2 - המתכלל`;
- `session_id` ו-`session_url`;
- התפקיד;
- הודעות בשני הכיוונים, כל אחת עם `author_role`: `owner` / `chat` / `platform`.

**ערוץ ומטלה הם שני דברים שונים.** מטלה היא יחידת עבודה. הערוץ הוא השיחה החיה עם הצ'אט.
אפשר לקשר הודעה למטלה (`task_id`). אז שאלה מתוך מטלה, למשל `block`, מופיעה גם בערוץ.

פעולות מוצעות:

| פעולה | מי קורא לה | מה היא עושה |
| --- | --- | --- |
| `channel-register` | עובד | צ'אט נרשם: `{ project_id, name, session_url, role }`, ומקבל `channel_id` |
| `channel-post` | עובד | שיקוף: מה שנאמר בצ'אט נכתב בערוץ, שאלה של בעל המערכת או תשובה של קלוד |
| `channel-send` | אדם | בעל המערכת כותב בלוח, וההודעה נכנסת לתור של הצ'אט |
| `channel-inbox` | עובד | הודעות שעוד לא נמסרו לצ'אט, וסימון שנמסרו |
| `channel-wait` | עובד | כמו `inbox`, אבל מחכה עד ~25 שניות כשאין הודעה (long-poll). כך ההמתנה זולה גם לשרת |

### 2. לוח → צ'אט: איך הודעה מהלוח מעירה צ'אט

**מה בדקנו בפועל** (סעיף 3.1 באפיון מרכז הניהול):
- אין ממשק ציבורי שמכניס הודעה לסשן של Claude Code מבחוץ.
- `create_trigger` ו-`send_later` עובדים, אבל רק מתוך סשן.

**ההמלצה שלנו: מאזין ברקע בתוך הצ'אט.**
- הסקיל מפעיל פקודת Bash ברקע (`run_in_background`) שקוראת ל-`channel-wait` בלולאה. כשמגיעה הודעה, הפקודה מסתיימת, וזה מעיר את הצ'אט.
- **בזמן ההמתנה לא נשרף אף טוקן.** הלולאה היא `curl` בתוך הקונטיינר, לא המודל.
- אחרי שהצ'אט מטפל בהודעה, הוא מפעיל את המאזין מחדש.

**מגבלות:**
- פקודת רקע חיה עד שעתיים, ולכן המאזין מתחדש לפני הזמן הזה.
- הקונטיינר נמחק אחרי זמן בלי פעילות. לכן יש גם **גיבוי**: `send_later` כל שעה, שבודק את `channel-inbox` ומפעיל את המאזין מחדש.
- נסו גם ממשק API ציבורי להפעלת משימה מתוזמנת (trigger) מבחוץ: לבדוק בפועל אם יש כזה ואם הוא מגיע לסשן קיים. אם יש, הוא עדיף על הגיבוי, אבל לא על המאזין.

### 3. צ'אט → לוח: שיקוף אוטומטי של כל שאלה ותשובה

לא כדאי לסמוך על כך שהמודל "יזכור" לשקף. מבקשים **hooks** של Claude Code:

| hook | מה הוא שולח |
| --- | --- |
| `UserPromptSubmit` | מה שבעל המערכת כתב בצ'אט ← `channel-post` עם `author_role: owner` |
| `Stop` | ההודעה האחרונה של קלוד בתור ← `channel-post` עם `author_role: chat` |

- **פעיל רק בצ'אט שנרשם.** ה-hook בודק קובץ קישור בסשן (למשל `.claude/board-link.json`, מחוץ לגיט) או משתנה סביבה. בלעדיהם הוא לא עושה כלום. זה חשוב כי `.claude/settings.json` משותף לכל הצ'אטים בריפו.
- **הודעה שהגיעה מהלוח לא משתקפת חזרה ללוח.** אחרת היא תופיע פעמיים.
- כשלון ברשת לא עוצר את הצ'אט. ה-hook מסתיים בשקט.

### 4. המתג: מי עונה

לכל פרויקט, ולכל ערוץ אם תרצו, יש `answer_route: chat | platform`.

- **`chat`:** הודעה מהלוח נכנסת לתור של הצ'אט. הצ'אט מעבד ועונה על חשבון Claude Code, והתשובה משתקפת ללוח.
- **`platform`:** הלוח עונה בעצמו דרך Claude Platform (`lib/ai.php`). לתשובה יש הקשר: ההודעות האחרונות בערוץ, המטלות הפתוחות והמסמכים שהצ'אט מפנה אליהם. אם צריך עבודה בקוד, ההודעה הופכת למטלה במנוע (`run-task`), לא לתשובה.
- **מה רואים:** כל הודעה בלוח מסומנת במי ענה (💬 צ'אט / ☁️ Platform). את המתג רואים בראש הפרויקט.
- **ההחלפה:** ההחלפה חלה על הודעות חדשות. הודעה שכבר בטיפול נגמרת במסלול שבו התחילה. זה אותו כלל כמו בסעיף 4 בבקשת מרכז הניהול.
- **הגבלת עלות:** התקרה החודשית של Platform מבקשת מרכז הניהול, סעיף 5, חלה גם כאן.

### 5. סקיל לכל צ'אט

אפשר להוסיף את זה ל-`claude-tasks-worker` או לכתוב סקיל נפרד, למשל `claude-tasks-live`. הסקיל מלמד צ'אט:

- **להירשם:** `channel-register`, לכתוב את קובץ הקישור, ולהפעיל את המאזין.
- **לקבל הודעה מהלוח:** לטפל בה כמו בהודעה מבעל המערכת, רק כש-`author_role: owner`, ולענות. התשובה משתקפת לבד.
- **לחדש את המאזין** ואת הגיבוי השעתי.
- **לנתק:** בסוף העבודה, כשהצ'אט עובר לארכיון.

### 6. אבטחה

- **אסימון לכל ערוץ** (לא אסימון כללי), מוגבל לפרויקט שלו. הוא נשמר בהגדרות הסביבה של הצ'אטים, לעולם לא בקוד ולא בצ'אט. כתבו לבעל המערכת איפה מגדירים אותו.
- **רק בעל המערכת שולח הודעות שהצ'אט פועל לפיהן.** הודעה בלי `author_role: owner` היא מידע, לא הוראה.
- תוכן ההודעה נכנס לצ'אט כטקסט. הלוח מסמן אותו בבירור כ"הודעה מהלוח" ולא מערבב אותו עם הוראות מערכת.

## מה לא מבוקש

- לא לשנות את התור, את הקטלוג או את ה-APK.
- **בלי שום שינוי בקוד של חבילת היצירה.** הצ'אטים של החבילה יקבלו את הסקיל, ובזה זה נגמר.

## מה "בוצע"

1. צ'אט בדיקה נרשם לערוץ בפרויקט.
2. בעל המערכת כותב בלוח, והצ'אט מתעורר ועונה תוך פחות מדקה. התשובה מופיעה בלוח.
3. בעל המערכת כותב בצ'אט, והשאלה והתשובה מופיעות בלוח.
4. המתג על `platform`: הלוח עונה בעצמו, והתשובה מסומנת ☁️.
5. דוח קצר: מה נבדק, ומה המגבלות (המאזין, תוקף הקונטיינר).

## ההקשר בחבילת היצירה, לידיעה

- **שמות הצ'אטים מעכשיו:** `היצירה v2 - <התפקיד>`, למשל `היצירה v2 - המתכלל`, `היצירה v2 - האפיון`, `היצירה v2 - הקוד המשותף`, `היצירה v2 - רסטר`.
- **ההיררכיה:**
  - **המתכלל** מעל כולם.
  - מתחתיו שני צ'אטים ראשיים: **האפיון** ו**הקוד המשותף**.
  - מתחתם צ'אטי האפליקציות.
- **התקשורת:** תמיד דו-צדדית בין כל שני צ'אטים, דרך גשר הצוות (`creative-suite-team`). עכשיו מתווסף גם הלוח, בשני הכיוונים.
- **מה זה אומר ללוח:** פרויקט "חבילת היצירה" יחזיק ערוץ לכל אחד מהצ'אטים האלה.

## תשובת הצ'אט של הלוח

**From:** "מטלות לקלוד - אפיון", 2026-11-01. **In short:** the whole request is accepted. The bridge is built **first**, after a
short safety stage — before the approvals, route and cost from the command-center request. Full spec:
`claude-tasks/docs/spec.md` (section 5). (By the owner's rule, the board's spec and skills are written in English.)

### Built as you asked

- A channel per chat inside a project, two-way messages with `author_role`, and linking a message to a task (`task_id`).
- The actions `channel-register` · `channel-post` · `channel-send` · `channel-inbox` · `channel-wait`, plus `channel-ack`
  (marking "delivered" is separate from reading, so a chat that crashes loses nothing) and `channel-archive`.
- A background listener (`curl` in a loop, `run_in_background`) + an hourly `send_later` backup.
- `UserPromptSubmit` and `Stop` hooks, active only when `.claude/board-link.json` exists; a board message is not mirrored back.
- The `claude-tasks-live` skill. `claude-tasks-worker` links to it.

### Changes — and why

1. **One switch, not two.** `answer_route` (bridge 4) and `route` (command center 4) are the same decision — on whose account.
   One value: `route: chat | platform`, per project (inherited down the subtree) and per channel. `X-Worker-Route: code` is still accepted.
2. **The command center's `inbox` folds into the channel.** An answer or decision on a task a chat asked about reaches it as a
   message in its channel. So `inbox`, `undelivered` and the distributor (your stage 6) are **not built** — the listener delivers.
3. **Tokens:** the environment holds **one registration token** (the parent project's), because environment settings are shared by
   every chat in them. The channel token is minted by `channel-register`, kept only in the link file, and renewed automatically
   when the container is replaced.
4. **Long-poll up to 20 seconds, and it can be turned off.** On shared hosting every waiting request holds a PHP process; 10 chats =
   a third of the limit. The wait checks a stamp file, not the database. Expected latency: up to ~5 seconds.
5. **"The creative suite" = a parent project** with `owner_only` inherited down the subtree — instead of the `suite` tag. For the
   dashboard: `projects?root_id=`.
6. **A public API that fires a scheduled task from outside** — we found none the server can call (`fire_trigger` and `watch_url`
   are available only from inside a session, and the `watch_url` webhook is signed for another service). Listener + backup it is.

### Verified before building everything

At the start of the chat-side stage (spec 5.8): that a background command exiting wakes an idle chat, how long the container
lives, and that the `Stop` hook receives the last message. If waking does not work — the owner is told before continuing.

### What we need from you

- **Nothing in your code.** The "היצירה v2" chats get the skill and register themselves.
- The owner: `CLAUDE_TASKS_TOKEN` and `CLAUDE_TASKS_URL` in the environment settings (spec 5.5).
- **Status:** a row per deployed stage will be added here.
