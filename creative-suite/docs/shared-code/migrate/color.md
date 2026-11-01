# מעבר לבורר הצבעים של ui-core (שלב 7) — כל החבילה

**מאת:** הקוד המשותף · **תאריך:** 2026-11-01
**המודול:** `creative-suite/ui-core/src/js/color.js` + `ui.css` (`.cp-*`). **המקור:** `colorpicker.js` של סטודיו העיצוב (#913).
**תצוגה:** `creative-suite/ui-core/demo/` — "בורר צבעים". **שורה ב-`integration.md`:** 13.

## מה יש

| | |
| --- | --- |
| `colorPicker(label, value, onInput, onCommit, { alpha, docColors, presets, sample, onOpen })` | שדה צבע: כפתור עם הצבע והקוד, ומתחתיו הבורר — ריבוע רוויה/בהירות עם זכוכית מגדלת, פס גוון, פס שקיפות, קוד להקלדה ולהעתקה, R/G/B, ⭐ מועדפים, ושורות: 🕘 אחרונים · ⭐ מועדפים · 🎨 בעיצוב · מוכנים. בורר אחד פתוח בכל רגע |
| `setColorContext({ prefix, docColors, presets, sample, onOpen })` | פעם אחת לאפליקציה; `opts` של בורר גובר |
| `parseHex`, `toHex`, `rgbToHsv`, `hsvToRgb`, `toCss` | המרות טהורות |
| `luminance`, `contrast(a, b)`, `readableOn(bg)` | ניגוד WCAG — טקסט קריא על צבע, אזהרת נגישות |
| `recentColors()`, `favoriteColors()` | לשורת צבעים משלך (סרגל, לוח) |
| `field.setValue(hex)` | ערך מבחוץ (בחירה אחרת, ביטול) — בלי `onInput` / `onCommit` (לקח מהעימוד) |
| `setColorContext({ storage: { get, set } })` | מתאם לאחסון האחרונים והמועדפים במקום `localStorage` (לקח מהסטודיו) |
| קוד צבע | `#abc`, `rrggbb` בלי #, `#rrggbbaa`, `rgb(…)`, `rgba(… / 50%)` |

**`onInput`** — בכל תזוזה (פעם בפריים) → תצוגה חיה על הקנבס. **`onCommit`** — בשחרור / Enter / דוגמית → צעד ביטול
אחד (עם `history.js`: `u.commit('צבע')`).

**האחרונים והמועדפים — במכשיר**, לפי `prefix` (ברירת מחדל `uic-colors`). אחרי שלב 8–9 של ענן החבילה (צבעים בספרייה)
— אותם צבעים בכל האפליקציות.

## סטודיו העיצוב — המקור

`colorpicker.js` → שורה אחת: `export * from './ui-core/color.js';` ו-`setColorContext({ prefix: 'gc-colors', … })` (כך
האחרונים והמועדפים שכבר נשמרו נשארים). `log('color-open')` → `onOpen: label => log('color-open', { label })`.
מ-`editor.css` — למחוק את הבלוק `.cp-*` (עבר ל-`ui.css`). העטיפה היא `.f.cp-field` (היה `.field.cp-field`) — לבדוק בעין.

## וקטורי, רסטר, עימוד

`<input type="color">` / בורר משלך → `colorPicker(...)`. `alpha: true` כשיש שקיפות (מילוי, מתאר). `docColors` — הצבעים
שבמסמך (וקטורי: מהסגנונות; רסטר: מהלוחית; עימוד: מצבעי המסמך). `sample` — טפטפת על הקנבס, אם יש: `async () => hex | null`. **האפליקציה** מסתירה את החלון בזמן הדגימה (`dialog.close()` /
`show()` או class) — הבורר לא נוגע ב-DOM שלה.

## עורך התמלילים

צבע טקסט / הדגשה — `colorPicker` בלוח העיצוב במקום `<input type="color">`. `alpha: false`.

## אחרי המעבר

- `php creative-suite/ui-core/tools/sync.php --check`
- לסמן את התא שלך בשורה 13 של `creative-suite/docs/integration.md` באותו PR.
- באג או חסר — הודעה אליי, לא תיקון בעותק.
