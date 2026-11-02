# מעבר ללוח הגרסאות והשלבים של ui-core (ממ1) — כל האפליקציות

**מאת:** הקוד המשותף · **תאריך:** 2026-11-02
**המודול:** `creative-suite/ui-core/src/js/versions.js` + `ui.css` (`.versions-*`). **המקור:** "🕘 היסטוריית גרסאות" של
העימוד (`readHistory` ב-`mbe-io.js`, הרשימה ב-`editor.js`) ו"📜 שלבי הקובץ" של סטודיו העיצוב (`journalSection` ב-`main.js`).
**תצוגה:** `creative-suite/ui-core/demo/` — "גרסאות ושלבים". **שורה ב-`integration.md`:** 25.
**מתי:** בתור של האפליקציה (שלב ב, `docs/spec/work-order.md` §3) — לא עכשיו.

## מה יש

| פונקציה | מה |
| --- | --- |
| `journalSteps(f)` → `[{ id, at, kind, kindLabel, app, appLabel, who, note, sha, current, available }]` | השלבים של קובץ ‎.mbe פתוח, **מהחדש לישן**. `current` — השלב האחרון (המסמך כמו שהוא); `available` — העותק של הגרסה עוד בקובץ (לא נגזם) |
| `versionsList(el, opts)` → `{ refresh, steps }` | הרשימה בתוך אלמנט קיים: "הנוכחית" / "שחזור" / "נגזם", "כאן" ליד שלבים של האפליקציה, אישור לפני שחזור |
| `openVersions(opts)` | אותה רשימה בגיליון "🕘 גרסאות ושלבים" (בטלפון — מלמטה); נסגר אחרי שחזור |
| `KINDS`, `APPS`, `appLabel(app)`, `whenLabel(at)` | התוויות בעברית — אותן בכל האפליקציות |

`opts`: `load` (חובה) — `async () => f` (הקובץ ‎.mbe פתוח, או `null` — "עוד אין היסטוריה"); `restore(step, f)` — טוען את
`f.version(step.sha)` לעורך (בלי `restore` — בלי כפתור); `app` — המזהה של האפליקציה (ל"כאן"); `empty` — טקסט כשאין;
`confirmRestore` (ברירת מחדל `true`); `onRestored(step)`.

**הליבה לא מיובאת** — כמו `stamp` ב-image-io: לכל אפליקציה עותק של `mbe-core` במקום אחר, והיא מעבירה את `f` הפתוח.

## מה נשאר באפליקציה

- **מאיפה בא הקובץ** — השרת (`api.php?mbe_source=` בעימוד), המכשיר (`assets.get(mbeBase)` בסטודיו), הענן.
- **איך גרסה נכנסת לעורך** — `changed(…)` בעימוד, `restoreFromJournal` בסטודיו. השחזור צריך להיות צעד שאפשר לבטל ב-↶ (`history.js`).
- **רישום השלב `restore`** — `f.restore(step.id)` לפני השמירה, או `f.stage({ kind: 'restore', note })` בשמירה הבאה.

## דוגמה — העימוד

```js
import { openVersions } from './ui-core/versions.js';
import { open } from './mbe/mbe.js';

$('#hist-btn').onclick = () => openVersions({
    app: 'layout-studio',
    load: async () => {
        const res = await fetch('api.php?mbe_source=' + id, { credentials: 'same-origin' });
        if (res.status === 404) return null;
        if (!res.ok) throw new Error('השרת לא ענה');
        return open(new Uint8Array(await res.arrayBuffer()));
    },
    restore: async (st, f) => {
        changed(ensureMasters(f.version(st.sha)), 'שחזור גרסה');
        pendingStages.push({ kind: 'restore', note: 'שוחזר מ-' + st.id });
        toast('הגרסה נטענה');
    },
});
```

**מוחקים:** `readHistory` / `historyVersion` ב-`mbe-io.js`, `#hist-dlg` ו-`.hist-list` (CSS). בסטודיו — `journalSection`,
`STAGE_LABEL` / `APP_NAMES` (התוויות עכשיו ב-`KINDS` / `APPS`), ו-`mbeJournal`.

## רסטר וּוקטורי

עוד אין לוח. כפתור "🕘 גרסאות" בתפריט, `load` — הקובץ ‎.mbe האחרון שנשמר (השרת או המכשיר), `restore` — טעינת הגרסה כמסמך.
אם הקובץ עוד לא נשמר כ-‎.mbe — `load` מחזיר `null`, והחלון מסביר מתי ההיסטוריה נרשמת.

## בדיקה

`node creative-suite/ui-core/tests/browser.cjs` — 8 בדיקות `versions:` (סדר, available, תוויות, שגיאה, שורות, שחזור, ריק,
גיליון), בטלפון ובמחשב. מול הליבה האמיתית: `journalSteps(await open(bytes))` אחרי שתי שמירות → `[עריכה · רסטר (הנוכחית),
נוצר · … (שחזור)]`.
