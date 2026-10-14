# היומן — חלק מחייב של DevLoop

בלי יומן אין "⚠️ לבירור", אין עקבות לדיווח 🐞, ואין דרך לדעת מה קרה. לכן `devloop_setup` תמיד מחבר יומן:
**המובנה** (בלי `log` — טבלה ב-`data_dir/log.sqlite`, 30 יום), או **היומן שכבר יש לאפליקציה** (`log` עם
`rows` / `max_id` / `write`).

## מה נרשם

| מה | איך |
| --- | --- |
| כל בקשה — פעולה, תוצאה, משך, מי | `devloop_log_begin($user)` בראש ה-API (המובנה) — וגם כותרת `X-Request-Id` |
| שגיאת PHP, גם קטלנית | אותו `devloop_log_begin` |
| חריגה בבקשה, עם 8 שורות מה-trace | `devloop_log_error($e)` ב-catch של ה-API |
| שגיאת JavaScript מהדפדפן | `DevLoop.setup({ builtinLog: true })` → `devloop-client-error` |
| כל דבר אחר | `devloop_log_write($level, $category, $event, ['message' => …, 'data' => […]])` |

השורה: `id`, `at`, `level` (info · warn · error), `category` (api · php · client · auth · data …), `event`, `message`,
`username`, `method`, `action`, `status`, `ms`, `req`, `ua`, `data`.

## הקישור בשני הכיוונים

- **ממטלה ליומן** — "📜 יומן" בכל מטלה (`dev-item-log`): המופעים של השגיאה (עד 50 לחתימה), והבקשות מההקשר של 🐞.
  לחיצה על מזהה בקשה — היומן מסונן לאותה בקשה (`logHref`).
- **מהיומן למטלה** — בכל שורה ביומן `🔗 PM-024` כשיש לה מטלה, ובשגיאה בלי מטלה "+ מטלה" (`dev-log-links`,
  `dev-task-from-log`). יומן של האפליקציה: `DevLoop.decorateLog(list)` על אלמנטים עם `data-log-id`, `data-log-req`,
  `data-log-level` ו-`.dl-slot`. היומן המובנה: `DevLoop.showLog(el, req)`.
- **Claude** — `devapi-feed&code=…` מחזיר גם `log[]`. קודם קוראים אותו, אחר כך מתקנים.

## איך קוראים שורה

- `client.error` עם `source:line` ו-`page` — שגיאה בדפדפן; `stack` ב-`data`.
- `exception` / `php.error` / `php.fatal` — `file:line` ו-`trace` ב-`data`.
- `request` (או `api.<פעולה>` ביומן של האפליקציה) עם `status` 4xx/5xx — הבקשה שנכשלה, וההודעה שהמשתמש ראה.
- אותו `req` בכמה שורות = אותה בקשה: מה קרה, באיזה סדר.
