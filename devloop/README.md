# DevLoop — מטלות, אימות וראיות, כמודול משותף

המנגנון שבו המפתח ו-Claude עובדים על אפליקציה: כל מטלה מקבלת קוד (`PM-017`), שיח עם צילומים, "איך בודקים",
ראיות (PR, פריסה, commit), ו**סגירה רק אחרי שהמפתח אימת** (🧪 ממתין לאימות שלך → ✅ / ↩️).
שגיאות מהיומן נפתחות לבד (⚠️ לבירור), ו-🐞 בכותרת מאפשר לדווח מכל מסך עם ההקשר הטכני.

נבנה במנהל הפרויקטים (שלבים 1–2, `project-manager/README.md` — "DevLoop"), והוצא לכאן כדי שכל אפליקציה תשתמש
באותו קוד.

## איך זה בנוי

| מה | איפה |
| --- | --- |
| המקור — **עורכים רק כאן** | `devloop/src/devloop.php` · `devloop.js` · `devloop.css` |
| מי משתמש, ולאן מועתק | `devloop/apps.json` |
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

## מי משתמש

| אפליקציה | מצב |
| --- | --- |
| `project-manager` | על המודול (גרסה 0.71) |
| `document-templates` | עדיין על העותק הישן שלו (`lib/devtasks.php`, DT-…) — המעבר בידי הסשן של הכלי; ראו `.claude/COORDINATION.md` |
