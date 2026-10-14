# DevLoop — מטלות, אימות וראיות, כמודול משותף

המנגנון שבו המפתח ו-Claude עובדים על אפליקציה: כל מטלה מקבלת קוד (`PM-017`), שיח עם צילומים, "איך בודקים",
ראיות (PR, פריסה, commit), ו**סגירה רק אחרי שהמפתח אימת** (🧪 ממתין לאימות שלך → ✅ / ↩️).
שגיאות מהיומן נפתחות לבד (⚠️ לבירור), ו-🐞 בכותרת מאפשר לדווח מכל מסך עם ההקשר הטכני.

נבנה במנהל הפרויקטים (שלבים 1–2, `project-manager/README.md` — "DevLoop"), והוצא לכאן כדי שכל אפליקציה תשתמש
באותו קוד.

**איך Claude עובד עם זה** — הסקיל `.claude/skills/devloop`. הוא **נייד**: יש בו עותק של המודול (`assets/`), דוגמה
שעובדת במערכת ריקה (`assets/example/`), CI גנרי, והחוזה ב-HTTP — כדי להתקין DevLoop גם במערכות שאין להן קשר לריפו
הזה. מה שייחודי לריפו (אילו אפליקציות, כתובות, סוד) — ב-`.devloop.json` בשורש, והסקיל קורא משם.

**היומן הוא חלק מחייב:** בלי `log` ב-`devloop_setup` — היומן המובנה (`data_dir/log.sqlite`, 30 יום); לאפליקציה עם
יומן משלה (מנהל הפרויקטים) — `log` עם `rows` / `max_id` / `write`. ממנו: ⚠️ לבירור, העקבות של 🐞, "📜 יומן" בכל מטלה,
ו-🔗 / "+ מטלה" בכל שורה ביומן. הפירוט: `.claude/skills/devloop/references/log.md`.

## איך זה בנוי

| מה | איפה |
| --- | --- |
| המקור — **עורכים רק כאן** | `devloop/src/devloop.php` · `devloop.js` · `devloop.css` |
| מי משתמש, ולאן מועתק | `devloop/apps.json` — האפליקציות, וגם `assets/` של הסקיל |
| סנכרון | `php devloop/tools/sync.php` (מעתיק) · `--check` (רק בודק) |
| שער בפריסה | `DevLoop gate` ב-`.github/workflows/deploy.yml` — עותק ששונה בתוך אפליקציה עוצר את הפריסה |

`devloop/` עצמה לא עולה לשרת (`--exclude='/devloop/'`); עולים העותקים שבתוך האפליקציות. העותקים יושבים בנתיבים
שהאפליקציה כבר מגישה (`lib/`, `assets/js/`, `assets/css/`), כדי שלא צריך לשנות את השער או את `public`.

**שינוי במודול:** עורכים ב-`src`, מריצים `sync.php`, בודקים בכל אפליקציה שב-`apps.json`, ומעלים את המקור ואת
כל העותקים באותו commit.

## חיבור לאפליקציה

**שרת** — קובץ חיבור קטן (במנהל הפרויקטים: `lib/devtasks.php`):

```php
require_once __DIR__ . '/devloop.php';
devloop_setup([
    'db'           => fn() => my_db(),                 // PDO, ברירת מחדל FETCH_ASSOC
    'items_file'   => __DIR__ . '/../dev/open-items.json',
    'data_dir'     => __DIR__ . '/../data/files/_dev',  // צילומים — חייב להיות מוחרג מ-rsync
    'prefix'       => 'XX-',  'token_prefix' => 'xxd_',
    'error'        => fn($msg, $status, $vars) => new MyError($msg, $status),
    'user_names'   => fn() => [/* id => שם */],
    'is_developer' => fn(int $id) => true /* מפתח פעיל */,
    // רשות: now, audit, log, as_claude, errors + errors_max (בלעדיהם אין "⚠️ לבירור")
]);
```

ובנתב של ה-API: `devloop_task_list`, `devloop_task_create`, `devloop_task_set`, `devloop_comment_add`,
`devloop_comment_delete`, `devloop_verify`, `devloop_report`, `devloop_attach`, `devloop_file_out`,
`devloop_token_list/create/revoke` — למפתח; ובלי עוגייה, באסימון: `devloop_api_feed`, `devloop_api_comment`,
`devloop_file_out`, `devloop_attach` (אחרי `devloop_api_auth()`). השמות של הפעולות — כמו במנהל הפרויקטים
(`dev-items`, `devapi-feed`, …), כדי ש-Claude והסקיל `devloop` יעבדו אותו דבר בכל אפליקציה.

**דפדפן:**

```js
DevLoop.setup({ api, toast, isDev, appName: '…', tasksHref: '#/settings/tasks', mountBefore: '#me-btn',
    headers: () => ({ 'X-CSRF': … }), version: () => '…', trace: () => ({ requests, errors }) });
DevLoop.mount();            // 🐞 בכותרת
DevLoop.show(element);      // מסך המטלות
```

`trace` — הבקשות והשגיאות האחרונות שהאפליקציה אוספת (במנהל הפרויקטים: `PMTrace` ב-`core.js`), להקשר של 🐞.
העיצוב משתמש במשתנים של `phone-first-design`.

## התראות ולוח מרכזי (שלב 4)

- **מייל למפתח** — דרך `notify` של האפליקציה (ו-`item_url` לקישור ישר): מטלה עברה ל-🧪 אימות או ל-❓ שאלה, Claude ענה
  (`devapi-comment`), ושגיאות חדשות ⚠️ לבירור. התראה אחת לבקשה; התראה שנכשלה לא מפילה את הפעולה.
- **מונה** — 🐞 בכותרת של האפליקציה: מה שממתין לך בה. קישור ישר למטלה — `#/settings/tasks/<קוד>`.

## מי משתמש

| אפליקציה | מצב |
| --- | --- |
| `project-manager` | על המודול (מגרסה 0.71); התראות במייל (0.72) |
| `document-templates` | עדיין על העותק הישן שלו (`lib/devtasks.php`, DT-…) — המעבר בידי הסשן של הכלי; ראו `.claude/COORDINATION.md` |
