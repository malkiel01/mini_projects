# תוסף הוורדפרס של קול מרוקו

קוד **לאתר מגרב** (maghreb.co.il), לא לפלטפורמה — התיקייה מוחרגת מהפריסה. בעל המערכת מתקין אותו באתר (מטלה).

## 2.0 (פרוסה M2) — מה השתנה

| קובץ | מה |
| --- | --- |
| `kol-maroko/kol-maroko.php` | 1.x כמו שהיה (ping, asset, publish, switch, התבנית, הייצוא), ועוד: `ping` מחזיר `protocol: 2` ומצב 2.0; הניתוב לפעולות החדשות; ההגשה נקראת בטעינת הקובץ |
| `kol-maroko/v2-store.php` | `asset_has`, `asset_put`, `manifest`, `activate` — הבדיקות, המכסות, החלפת המצביע האטומית (`rename` של `live.json`), הניקוי |
| `kol-maroko/v2-serve.php` | אופן M-A: `/lexicon/**` לפני וורדפרס, רק מה שבמניפסט; הכותרות I1–I7 על כל תשובה (כולל 304/404/416/HEAD), ETag, Range |
| `kol-maroko/v2-admin.php` | בעמוד התוסף: המפסק "העמוד החדש", הגרסות ו"החזר לגרסה זו", נפח, מכסות, הגשה ישירה של מדיה (כבויה), מחיקה מפורשת, ובדיקה עצמית |

- **האחסון:** `uploads/kol-maroko/v2/` — `blobs/<sha256>` (טקסט, בלי סיומת), `blobs/<sha256>.<ext>` (מדיה מרשימה סגורה),
  `manifest-<n>.json`, `live.json`. הכול חסום מגישה ישירה; מוגש רק דרך התוסף.
- **הכותרות** (מעבר ל-§2 של M2, באישור הבקרה): `Cross-Origin-Opener-Policy: same-origin` — עמוד שנפתח מעמוד של האתר לא יכול לנווט
  את הלשונית של האתר (נמצא בערכת ההתקפה); HTML ונתיב "יפה" — `max-age=0, must-revalidate` + ETag, כדי שכיבוי המפסק יחזיר את
  העמוד המקורי מיד (H14).
- **אין שינוי לגולשים** עד פרסום ראשון בפורמט החדש **וגם** הדלקת "העמוד החדש" (M3).

## התקנה (מטלה לבעלים)

1. גיבוי של `wp-content/uploads/kol-maroko/`.
2. העלאת התיקייה `kol-maroko/` במקום הקיימת (תוספים ← הוספה ← העלאה, או FTP). ההגדרות נשמרות.
3. הגדרות ← קול מרוקו ← "🔎 בדיקה עצמית" (אחרי פרסום ראשון של 2.0) — כל שורה ✓. העמוד של היום — ללא שינוי.

## בדיקות

- `php tests/run.php` — `tests/plugin/*_test.php` מול האתר המדומה `tests/plugin/wp.php` (טוען את הקובץ האמיתי).
- `KOL_V2_REAL=1 php tests/run.php` ו-`KOL_V2_REAL=1 node tests/browser/m1.cjs` — הבדיקות של M1 מול התוסף האמיתי.
- `node tests/browser/m2.cjs` — ערכת ההתקפה (`tests/attack-kit/`) בדפדפן.

האפיון: `../docs/spec-v2/07-publish-and-mount.md` §5–6, `../docs/spec-v2/slices/M2-wordpress-plugin-v2.md`.
