# לחבר אפליקציה ל-DevLoop

1. **שורה ב-`devloop/apps.json`** — לאן מועתקים שלושת הקבצים, בנתיבים שהאפליקציה כבר מגישה (בדרך כלל
   `lib/devloop.php`, `assets/js/devloop.js`, `assets/css/devloop.css` — כדי שלא צריך לגעת בשער או ב-`public`).
   ואז `php devloop/tools/sync.php`.
2. **קובץ חיבור בשרת** (כמו `project-manager/lib/devtasks.php`): `require_once 'devloop.php'` ו-`devloop_setup([...])` —
   `db`, `items_file`, `data_dir`, `prefix` (קידומת משלה: `DT-`, `RC-`…), `token_prefix` (`dtd_`…), `error`,
   `user_names`, `is_developer`; ורשות: `now`, `audit`, `log`, `as_claude`, `errors` + `errors_max` (יומן שגיאות →
   ⚠️ לבירור), `notify` + `item_url` (מייל).
3. **בנתב של ה-API** — אותם שמות פעולות כמו במנהל הפרויקטים: למפתח בעוגייה `dev-items`, `dev-task-create`,
   `dev-task-set`, `dev-comment`, `dev-comment-delete`, `dev-verify`, `dev-report`, `dev-attach`, `dev-file`,
   `dev-tokens`, `dev-token-create`, `dev-token-revoke`; ובלי עוגייה, באסימון: `devapi-feed`, `devapi-comment`,
   `devapi-file`, `devapi-attach` (כל אחד אחרי `devloop_api_auth()`). העתק מ-`project-manager/api.php`.
4. **בדפדפן**: `<link devloop.css>`, `<script devloop.js>`, ואחרי הכניסה:
   `DevLoop.setup({ api, toast, isDev, appName, tasksHref, mountBefore, headers, lang, version, trace })`,
   `DevLoop.mount()` (🐞 בכותרת), ומסך שמריץ `DevLoop.show(el, code)` — בכתובת `#/settings/tasks/<קוד>`, כדי
   שהקישורים מהמייל ומהלוח המרכזי יעבדו.
5. **`deploy.yml`**: `--exclude` לתיקיית הצילומים (`data_dir`) אם היא לא כבר בתוך תיקיית נתונים מוחרגת; ובשלב
   "DevLoop — ראיות פריסה" — הקידומת החדשה ב-grep, וכתובת + סוד לאפליקציה.
6. **`dev/open-items.json`** + `dev/.htaccess` (deny all), ו-README של האפליקציה.
7. בדיקה: 🐞 מכל מסך, מטלה חדשה, שיח עם צילום, אסימון → `devapi-feed`, ✅ / ↩️.
