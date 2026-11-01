# מודול הכוננים — חיבור אפליקציה לענן החבילה (ובהמשך לכל ענן)

**הבעלים:** ענן החבילה. **מקור אחד, ועותק מסונכרן בכל אפליקציה** — כמו `mbe-core` ו-`ui-core`.
**הרקע:** `creative-suite/docs/requests/account-menu.md` (החוזה עם `ui-core`), `suite-cloud/docs/spec-1-skeleton.md` סעיף 7
(הפרוטוקול), `suite-cloud/docs/spec-3-ai.md` (הבינה המלאכותית).

## מה יש עכשיו

| | מה |
| --- | --- |
| ☁️ **ענן החבילה** | חיבור משתמש (אישור פעם אחת), ניתוק, ובמקום הכונן: רשימה, קריאה, כתיבה, העלאה בחלקים, תיקייה, שינוי שם, העברה, סל |
| 🔤 **גופנים** | `drives.fonts.list()`, `load(item)` (FontFace + הבתים להטמעה), `add(file, …)` — לבורר הגופנים: "שלי". לפני `add` — האפליקציה מציגה את אזהרת הרישיון |
| 🤖 **בינה מלאכותית** | `drives.ai.capabilities()` ו-`drives.ai.run(task, …)` — כל קוד הספקים בענן. בלי חיבור: הכול `false`, והאפליקציה מציגה "דרך הצ'אט (סקיל)" |
| 📦 **Dropbox** | `drives.link('dropbox')` / `unlink`, ו-`drives.dropbox` באותה צורה כמו `drives.suite` (list, stat, read, mkdir, rename, move, trash, write, upload, quota). **הדפדפן מדבר עם Dropbox ישירות** — הקבצים לא עוברים בשרת. בתיקיית האפליקציה (App folder) בלבד |
| 🗂️ **כל הכוננים** | `drives.list()` → `[{ id, name, connected, account? }]` — רק כוננים שמוגדרים. בורר "שמור ב…" / "פתח מ…" נבנה מזה |
| בהמשך | Google Drive, OneDrive, SFTP / WebDAV, תיקייה במחשב — באותו ממשק (`drives.<כונן>`) |

### Dropbox — איך זה עובד

- **App key אחד לכל החבילה**, שבעל המערכת שם ב"ניהול" של הענן (🗂️ כוננים חיצוניים). אין App secret: OAuth עם PKCE.
  המודול מקבל אותו מהענן (`drives_config`, חתום) ושומר במטמון. בלי App key — Dropbox פשוט לא מופיע ב-`status` / `list`.
- **redirect URI אחד** בקונסולה של Dropbox: `https://mbe-plus.com/mini_projects/suite-cloud/oauth/dropbox.php`. הדף מעביר את
  החזרה ל-`api.php` של האפליקציה לפי ה-state (`<client>.<אקראי>`), ושם המודול מחליף את הקוד עם ה-verifier שרק הוא מחזיק.
- **אסימון הרענון נשמר רק אצל האפליקציה** (`drives.sqlite`), והדפדפן מקבל אסימון גישה של כמה שעות (`drives_ptoken`).
  הרשאה שבוטלה אצל Dropbox → `not-connected`.
- **אפליקציה בדומיין משלה:** `'dropbox' => ['app_key' => '…']` ב-cfg של `Drives`, וב-Dropbox redirect URI
  `<endpoint>?action=drives_oauth_callback&provider=dropbox`.
- **אחרי `link`** חוזרים ל-`back` עם `?drive=connected` או `?drive=denied` (ובשגיאה — דף עם `?cloud=error`).

## איך אפליקציה מצטרפת — PR אחד, בתיקייה שלה

1. **הסכמה:** הצ'אט של האפליקציה מוסיף שורה ל-`suite-cloud/drives/apps.json` (שורה לכל אפליקציה, לפי א"ב; למזג main לפני
   ה-PR):
   ```json
   "vector-studio": { "js": "assets/js/drives", "php": "lib/drives" }
   ```
   ואז `php suite-cloud/drives/tools/sync.php`. התיקיות האלה **כולן של המודול** — לא עורכים בהן (שער בפריסה).

2. **השרת — שלוש שורות ב-`api.php`**, לפני הבדיקה הרגילה של הפעולות:
   ```php
   require_once __DIR__ . '/lib/drives/Drives.php';
   if (str_starts_with($action, 'drives_')) (new \SuiteCloud\Drives([
       'client'   => 'vector-studio', 'name' => 'וקטורי',
       'dataDir'  => __DIR__ . '/data',                               // שם נוצר drives.sqlite
       'endpoint' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php',
       'user'     => fn() => ($u = currentUser()) ? (string) $u['id'] : null,
       'back'     => 'https://mbe-plus.com/mini_projects/vector-studio/',
   ]))->serve($action);
   ```

3. **`app.json`:** להוסיף ל-`public` את `api.php?action=drives_key` (הענן מוריד משם את המפתח הציבורי — שרת-לשרת, בלי עוגייה).

4. **`deploy.yml`:** `<app>/data/drives.sqlite*` חייב להיות מוחרג — בו המפתח הפרטי של האפליקציה והחיבורים. אצל מי שכבר
   מחריג `data/*.sqlite*` — אין מה להוסיף. אחרת — שורה אחת.

5. **הדפדפן:**
   ```js
   import { createDrives } from './drives/drives.js';
   const drives = createDrives({ endpoint: 'api.php' });

   const { suite } = await drives.status();            // suite.connected, suite.name
   await drives.connect();                              // מסך האישור בענן; חוזרים ל-back עם ?cloud=connected | denied
   const { items } = await drives.suite.list();
   const node = await drives.suite.upload(file, { parent, ifRev, onProgress });
   const blob = await drives.suite.read(node.id);
   const caps = await drives.ai.capabilities();
   const { png } = await drives.ai.run('remove-background', { image: blob });
   ```
   הממשק המלא — בראש `src/js/drives.js`.

**מי יכול להתחבר:** משתמש שיש לו הרשאה לענן החבילה בפלטפורמה. כל עוד הענן `restricted` — רק מי שאושר לו ישירות.

## הקבצים

| | |
| --- | --- |
| `src/php/Drives.php` | צד השרת: מפתחות האפליקציה, רישום בענן, חיבור, אסימון לדפדפן, ניתוק — כל קריאה לענן חתומה |
| `src/js/drives.js` | צד הדפדפן: `status`, `connect`, `disconnect`, `suite.*`, `ai.*` — האסימון בזיכרון בלבד, מתחדש לבד |
| `apps.json` | האפליקציות שהצטרפו — כל אחת בהסכמת הצ'אט שלה |
| `tools/sync.php` | הסנכרון; `--check` — שער בפריסה |
| `tests/run.php`, `tests/js.mjs` | המודול מול קוד הענן עצמו (PHP), וצד הדפדפן עם `fetch` מדומה (Node) |
