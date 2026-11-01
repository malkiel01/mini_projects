# מעבר לשמירה האוטומטית של ui-core (שלב 10) — כל החבילה

**מאת:** הקוד המשותף · **תאריך:** 2026-11-01
**המודול:** `creative-suite/ui-core/src/js/autosave.js`. **המקור:** `saver.js` של עורך התמלילים (ביקורת 03) + `draft.js` של הרסטר.
**שורה ב-`integration.md`:** 16.

## מה יש

| | |
| --- | --- |
| `autosave({ snapshot, send, version, draftKey, draftData, store, onState })` | שינוי → **טיוטה במכשיר** (IndexedDB) אחרי 250 מ"ש שקט (לכל המאוחר שנייה) → **שמירה לשרת** אחרי 1.5 שנייה. הטיוטה נמחקת רק כשהשרת אישר את אותו תוכן |
| ניתוק | מצב `offline`, ניסיון חוזר (2, 5, 10, 30, 60 שניות); הטיוטה נשמרת |
| התנגשות / נעילה | `conflict` / `locked` — השמירה **נעצרת**, לא דורסים; `keepConflictCopy()` שומר את המקומי בנפרד |
| `flush`, `flushUntilClean`, `flushDraft` | שמירה עכשיו / עד שנקי / טיוטה עכשיו — מחזירים תוצאה, לא זורקים |
| `findDraft(store, key, serverStr)` | בפתיחה: טיוטה שונה ממה שבשרת → להציע שחזור (`confirm` של dialog.js), ואז `markDirty(serverVersion)` |
| `saveOnExit(s, { warn })` | טיוטה מיד כשהדף מוסתר / נסגר (טלפון ברקע) |
| בלי `send` | רק טיוטה במכשיר (אפליקציה בלי שרת / לפני השמירה הראשונה); `markSaved()` אחרי שמירה ידנית |
| `draftData: async () => …` | טיוטה "כבדה" (פיקסלים) — נבנית רק כשצריך לכתוב; ההשוואה עדיין לפי `snapshot()` הזול |
| `STATE_TEXT` | '✓ נשמר', 'שומר…', '⚠ מנותק'… לכותרת |

`send(snap, version)` מחזיר `{ version }`; שגיאה עם `code`: `conflict` (409), `locked` / `readonly` (423), `offline`, `expired`,
`schema_newer`. `fetch` שנכשל (TypeError) נחשב ניתוק.

## עורך התמלילים — המקור

`saver.js` → `export { autosave as createSaver, findDraft, STATE_TEXT } from './ui-core/autosave.js'` + מתאם קטן: הטיוטה אצלך
`{ json, base_version, at }` → אצלנו `{ data, base_version, at }`; `drafts` (DB `word-processor`) → `store: kv('word-processor', 'drafts')`
כדי שהטיוטות הקיימות יישארו (ובקריאה הראשונה — `d.data ?? d.json`). הבדיקות של ביקורת 03 צריכות לעבור כמו שהן.

## רסטר

`draft.js` נשאר (PNG לכל שכבה) — אבל התזמון והמחזור עוברים: `autosave({ snapshot: () => doc.revKey(), draftData: () => buildDraft(),
draftKey: draftKey(doc), store: kv('raster-studio'), send: serverSave })`, ו-`saveOnExit(s)` במקום מאזין ה-visibilitychange שלך.

## וקטורי, עימוד, סטודיו העיצוב

`persist()` / שמירה בטיימר משלך → `autosave({ snapshot: () => S.doc, send: api.save, version, draftKey })` + `s.changed()` בכל
שינוי (אותו מקום שבו `history.commit` נקרא). כותרת המצב — `onState: st => status.textContent = STATE_TEXT[st]`.

## אחרי המעבר

- `php creative-suite/ui-core/tools/sync.php --check`
- לסמן את התא שלך בשורה 16 של `creative-suite/docs/integration.md` באותו PR.
