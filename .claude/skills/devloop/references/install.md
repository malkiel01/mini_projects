# להוסיף DevLoop לאפליקציה

הקוד בסקיל: `assets/devloop.php` (שרת, PHP 8.1+, PDO SQLite), `assets/devloop.js` (דפדפן, בלי ספריות),
`assets/devloop.css`. דוגמה שעובדת בלי שום דבר אחר: `assets/example/` (`api.php` + `index.html`) — הכי מהיר להתחיל
ממנה. אפליקציה שאינה PHP — `protocol.md`.

> בפרויקט שיש לו מנגנון משלו למודול (למשל מקור אחד שמסונכרן לכמה אפליקציות) — לפי ההוראות שלו, לא העתקה ידנית.
> `.devloop.json` / README של הפרויקט אומרים.

## 1. השרת

1. העתק את `devloop.php` לאפליקציה (למשל `lib/`), ובקובץ חיבור קטן — `devloop_setup([...])`:
   - **חובה:** `db` (fn(): PDO, ברירת מחדל `FETCH_ASSOC`), `items_file` (קובץ המטלות בקוד), `data_dir` (צילומים
     ויומן — **להחריג מהפריסה**, כדי שלא יידרס), `prefix` (`PM-`), `token_prefix` (`pmd_`), `error` (fn → Throwable
     שה-API שלך מחזיר כשגיאה), `user_names` (fn(): [id => שם]), `is_developer` (fn(int): bool).
   - **היומן — אחד משניים:** בלי `log` → היומן המובנה; או `log => ['rows' => …, 'max_id' => …, 'write' => …]` ליומן
     שכבר יש לאפליקציה (`rows` מקבל after, before, level, ids, reqs, q, limit — ראה `devloop_log_rows`).
   - **רשות:** `now`, `audit`, `as_claude` (סימון 🤖 ביומן), `notify` (מייל למפתח), `item_url` (קישור למטלה במייל).
2. בראש ה-API: `devloop_log_begin($user)` (רק ביומן המובנה), וב-catch: `devloop_log_error($e)`.
3. בנתב — **אותם שמות פעולות** (כך Claude עובד אותו דבר בכל אפליקציה); העתק מ-`assets/example/api.php`:
   - למפתח (בהרשאה שלך): `dev-items`, `dev-task-create`, `dev-task-set`, `dev-comment`, `dev-comment-delete`,
     `dev-verify`, `dev-report`, `dev-attach`, `dev-file`, `dev-tokens`, `dev-token-create`, `dev-token-revoke`,
     `dev-item-log`, `dev-log-links`, `dev-task-from-log`, ו-`dev-log` (רק ביומן המובנה).
   - בלי עוגייה, באסימון: `devapi-feed`, `devapi-comment`, `devapi-file`, `devapi-attach` — כל אחד אחרי
     `devloop_api_auth()`. ו-`devloop-client-error` (שגיאות דפדפן ליומן המובנה). אם יש שער / רשימת כתובות ציבוריות —
     הפעולות האלה צריכות לעבור בלי כניסה.

## 2. הדפדפן

```js
DevLoop.setup({
  api,                // api(action, { body, params }) → JSON, או זורק Error עם ההודעה
  toast, isDev,       // הודעה קצרה; האם המשתמש מפתח
  appName: 'שם האפליקציה', tasksHref: '#/settings/tasks', mountBefore: '#me-btn',
  headers: () => ({ 'X-CSRF': … }),         // מה שה-API שלך דורש בבקשת POST
  version: () => '1.4', lang: () => 'he',
  trace: () => ({ requests, errors }),       // אם יש לאפליקציה; אחרת DevLoop.noteRequest({action, method, status, id}) מ-api
  logHref: r => '#/settings/log/' + r.req,   // יומן של האפליקציה; בלי — DevLoop.showLog
  builtinLog: true,                          // היומן המובנה: שגיאות JS נשלחות לשרת
});
DevLoop.mount();                              // 🐞 בכותרת, עם מונה "ממתינות לך"
DevLoop.show(el, code);                       // מסך המטלות — בכתובת `${tasksHref}/<קוד>` (מייל וקישורים מגיעים לשם)
DevLoop.showLog(el, req);                     // היומן המובנה; ביומן של האפליקציה — DevLoop.decorateLog(list)
```

העיצוב (`devloop.css`) משתמש במשתנים: `--s2 --s3 --r --line --surface --bg --muted --brand --brand-ink --brand-soft
--danger --ok --star` — הגדר אותם (או מפה לשלך), ורכיבים בסיסיים: `.btn`, `.chip`, `.icon-btn`, `.hint`, `.f`.

## 3. בפרויקט

- `dev/open-items.json` (`items-file.md`), ו-`.htaccess` / הגנה שלא יוגש לדפדפן.
- **`.devloop.json`** בשורש הריפו — כדי שהסקיל ימצא את האפליקציה:
  ```json
  { "apps": [{ "name": "…", "prefix": "PM-", "dir": "app", "api": "https://…/api.php",
               "tasks_url": "https://…/#/settings/tasks", "log_url": "https://…/#/settings/log",
               "items_file": "app/dev/open-items.json", "token_env": "PM_DEVLOOP_TOKEN" }] }
  ```
- **CI** — `assets/ci/github-actions.yml` כשלב אחרון בפריסה, וסוד עם אסימון (המפתח יוצר אותו: "🔑 גישה ל-Claude";
  ב-GitHub: Settings → Secrets and variables → Actions). אין GitHub Actions? אותו curl בכל CI.

## 4. בדיקה

🐞 מכל מסך (עם צילום) → מטלה עם ההקשר · שגיאת JS מכוונת → ⚠️ לבירור · "📜 יומן" במטלה · 🔗 ביומן · "+ מטלה" משגיאה ·
אסימון → `devapi-feed&code=…` עם `log` · ✅ / ↩️.
