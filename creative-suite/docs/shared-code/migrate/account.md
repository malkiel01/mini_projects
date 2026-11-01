# 👤 תפריט המשתמש, הגדרות אישיות ו-📁 "המסמכים שלי" — `ui-core` (שורות 19–20 ב-`integration.md`)

**מאת:** הקוד המשותף · **תאריך:** 2026-10-31 · **ההכרעה:** `creative-suite/docs/requests/account-menu.md`
**המודול:** `creative-suite/ui-core/src/js/account.js` (התיעוד המלא בראש הקובץ). **תצוגה:** `creative-suite/ui-core/demo/` — העיגול בסרגל.

## מה המשתמש מקבל — אותו דבר בכל אפליקציה

עיגול עם התמונה או ראשי התיבות, בפינה של הסרגל העליון. לחיצה — במחשב תפריט קופץ, בטלפון גיליון מלמטה. הפריטים, תמיד
באותו סדר: ראש (שם, אימייל, תג "מחובר ל…") · 📁 המסמכים שלי · ☁️ עננים מחוברים · ⚙️ הגדרות אישיות · 🔑 החשבון · 🛠 ניהול
(רק למנהל) · ❓ עזרה ומה חדש · 🚪 יציאה. **פריט שהאפליקציה לא נתנה לו פעולה — לא מוצג.**

**הגדרות אישיות:** כלליות (ערכת צבעים, גודל טקסט, יחידות, שמירה אוטומטית, גופן ברירת מחדל, שמירה בענן), קטע של האפליקציה,
ורשימת קיצורי המקלדת (אם מעבירים את `shortcuts()` מ-`keys.js`).

## מה צריך

- **CSS ו-JS של ui-core:** `account.js` משתמש ב-`ui.css` (העיגול, הערכה הכהה, `--ui-scale`) ובחלונות ובתפריט. **אפליקציה
  שעוד לא עברה לשלבים 1–2 — קודם אותם** (`migrate/css-dialogs.md`).
- **צד השרת — גם הוא פעם אחת** (עדכון בעל המערכת): להוסיף `"php": "lib/ui-core"` לשורה שלך ב-`apps.json` ולהריץ sync. נוצר
  `lib/ui-core/account/account.php`. ההגדרות נשמרות **במסד שלך** (טבלה `uic_prefs`, נוצרת לבד); שום דבר לא יוצא מהאפליקציה.

## צד השרת — החיבור הקטן (ב-`api.php` שלך, אחרי בדיקת ה-CSRF)

```php
require_once __DIR__ . '/lib/ui-core/account/account.php';
$hooks = [
    'app'    => 'raster-studio',
    'pdo'    => fn() => rs_db(),                                  // המסד שלך
    'user'   => fn() => currentAccountId(),                        // המשתמש המחובר, או null
    'export' => fn($uid) => ['docs' => myDocsOf($uid)],            // רשות — מה שלך נכנס ל"ייצוא כל הנתונים שלי"
    'delete' => fn($uid) => deleteAccountAndDocs($uid),            // רשות — בלי: "מחיקת החשבון" מחזירה 501
];
if (str_starts_with($action, 'account_')) {
    try { reply(uic_account_api($action, $input, $hooks)); }
    catch (UicAccountError $e) { reply(['error' => $e->getMessage()], $e->status); }
}
```
הפעולות: `account_prefs`, `account_prefs_set`, `account_export`, `account_delete` (התיעוד בראש הקובץ).
**`--exclude` ב-`deploy.yml` לא צריך** — הטבלה בתוך המסד הקיים שלך, שכבר מוחרג.

## החיבור — דוגמה

```js
import { accountMenu, accountServer } from './ui-core/account.js';
const acc = accountMenu(document.querySelector('header.top'), {
    user: () => me && { name: me.name, email: me.email, avatar: me.avatar, admin: me.role === 'admin' },
    server: await accountServer((action, body) => api(action, body)),   // צד השרת המשותף: הגדרות, ייצוא, מחיקה
    docs: () => openLibrary(),             // היום — הספרייה שלך; בהמשך — files-window.js (שורה 20)
    sections: [{ id: 'app', title: 'עיצוב רסטר', render: body => body.append(/* ההגדרות שלך */) }],
    shortcuts: keys,                       // אם עברת ל-shortcuts()
    admin: 'admin/', help: () => …,
    logout: () => api('logout').then(() => location.reload()),
    onPrefs: p => { units = p.units; autosave = p.autosave; },
});
acc.refresh();   // אחרי התחברות / יציאה
```

- **`user` מחזיר `null`** — העיגול לא מוצג (משתמש לא מחובר / אורח).
- **`server`** — ההגדרות במסד שלך, ו-🔑 "החשבון" נותן "ייצוא כל הנתונים שלי" ו"מחיקת החשבון" (אישור כפול). `ns` = `'general'`
  להגדרות הכלליות; לקטע שלך — `server.prefs.get('<app>')` / `set`. בלי `server` — ההגדרות במכשיר בלבד.
- **`theme` ו-`scale` מוחלים לבד** (`data-theme` על `<html>`, `--ui-scale`). **אם ב-`app.css` יש לך משתנים משלך עם
  `@media (prefers-color-scheme: dark)`** (למשל משתני הבמה בעימוד) — להוסיף את אותם ערכים גם תחת
  `:root[data-theme="dark"]`, ולעטוף את ה-media ב-`:root:not([data-theme="light"])`; אחרת ערכה ידנית לא תגיע אליהם.
- **`drives`** — כשהמודול של הכוננים (ענן החבילה, שורה 21) יהיה מוכן. עד אז — בלי, והפריט לא מוצג.

## 📁 "המסמכים שלי" — `files-window.js` (שורה 20)

חלון אחד לניהול, לפתיחה ולשמירה. לשונית לכל מקור: "באפליקציה" (הספרייה שלך, דרך מתאם קטן) + ענן החבילה (מודול הכוננים).
```js
import { openDocuments, pickFile, saveFile, suiteSource } from './ui-core/files-window.js';
const sources = [
    { id: 'app', title: 'באפליקציה', icon: '📱',
      list: async folder => ({ items: (await api('docs_list', { folder })).docs.map(d => ({ id: d.id, name: d.title + '.mbe', kind: 'file', size: d.size, updated: d.updated_at })) }),
      read: id => fetch(`api.php?action=doc_read&id=${id}`).then(r => r.blob()),
      write: (name, blob, { parent, replace }) => uploadDoc(name, blob, parent, replace),
      rename: (id, name) => api('doc_rename', { id, name }),
      remove: id => api('doc_trash', { id }),
      versions: id => api('doc_versions', { id }).then(r => r.versions),   // רשות
      restore: (id, v) => api('doc_restore', { id, v }) },                   // רשות
    drives && suiteSource(drives),                                         // אם הצטרפת למודול הכוננים (#960)
];
accountMenu(bar, { …, docs: () => openDocuments(sources, { onOpen: item => openDoc(item.id) }) });
const got = await pickFile(sources, { accept: ['.mbe'] });                 // במקום חלון הפתיחה שלך
const at  = await saveFile(sources, { name: title, ext: 'mbe', getBlob: buildMbe });   // "שמירה בשם"
```
המתאם — **כל פעולה רשות מלבד `list`**; פעולה שאין לה — הכפתור לא מוצג. התיעוד המלא בראש `files-window.js`.
**חלון הפתיחה והשמירה הקיים שלך — מוחלף בזה** (הכרעה: "אותו חלון משמש גם לפתיחה ולשמירה").

## מה מחליפים היום

| אפליקציה | היום | במקום |
| --- | --- | --- |
| וקטורי | עיגול שם (`#me`) בלי תפריט | `accountMenu` — ולמחוק את `#me` |
| סטודיו העיצוב | "התנתק" בתוך מסך אחר | `logout` בתפריט |
| ענן החבילה, עימוד, רסטר, עורך התמלילים | אין | `accountMenu` בסרגל העליון |

## בדיקה

- בטלפון: העיגול בסרגל, גיליון, "הגדרות אישיות" — ערכה כהה ידנית משנה את **כל** המסך (גם החלקים שלך).
- במחשב: תפריט קופץ ליד העיגול, Esc סוגר.
- סימון ✅ בשורה 19 ב-`creative-suite/docs/integration.md`, באותו PR.
