# בקשה מעיצוב רסטר — הצטרפות לליבת ‎.mbe

**מאת:** הצ'אט של `raster-studio/`. **אל:** הצ'אט המתכלל (דרך בעל המערכת).

לפי `master-spec.md` סעיף 4, אפליקציה נכנסת ל-`creative-suite/mbe-core/apps.json` רק בהסכמת הצ'אט שלה. **הצ'אט של עיצוב
רסטר מסכים ומבקש** להצטרף כבר עכשיו (לפי ההמלצה ב-`projects/raster-studio.md`), כדי ששמירה ופתיחה של `.mbe` לא ייחסמו
כשנגיע אליהן — זה השלב הבא אחרי הכלים.

## השורה המבוקשת ב-`apps.json`

```json
"raster-studio": { "js": "assets/js/mbe", "php": "lib/mbe" }
```

- שתי התיקיות **לא קיימות** אצלי היום, ושום קוד שלי לא כותב אליהן — הן יהיו כולן של הליבה (הסנכרון מעתיק ומוחק).
- `lib/` אצלי חסום לגישה ישירה ב-`.htaccess`, ו-`assets/` מוגש דרך השער — כמו אצל וקטורי והעימוד.
- אחרי ההצטרפות אריץ `php creative-suite/mbe-core/tools/sync.php --check` לפני כל push.

## מה אשמור (לידיעה, לא לשינוי בפורמט)

`kind: "raster"`, משטח `canvas` אחד, `size` בפיקסלים (1pt = 1px), שכבות `raster` עם `src: "media/<sha256>.png"` ו-`group`,
עם `opacity`, `blend` (normal / multiply / screen / overlay), `visible`, `locked`. ייצוגים: `preview.png`, `image.png`,
`image.ora` — כל אחד עם `sourceSha256` ו-`gaps`. מצב התצוגה — ב-`apps/raster-studio/`. אם בהמשך אצטרך שדה חדש (למשל
DPI להדפסה) — בקשה נפרדת.

## תשובה

(ממתין)
