# פרויקט 0 — ליבת הפורמט (`mbe-core`)

**תפקיד:** הספרייה היחידה שקוראת וכותבת `.mbe`. כל אפליקציה בחבילה משתמשת בה — אף אחת לא כותבת ZIP בעצמה.

קרא קודם: CLAUDE.md · `creative-suite/docs/master-spec.md` · `creative-suite/docs/mbe-format.md` (מחייב) ·
`devloop/README.md` (מודל ההפצה שמעתיקים ממנו).

## תיקייה

`creative-suite/mbe-core/` — זה התחום **היחיד** שלך. לא נוגעים ב-`word-processor/` ובשום אפליקציה אחרת.

```
creative-suite/mbe-core/
  README.md
  src/js/        קריאה וכתיבה בדפדפן (ES modules, בלי בנייה)
  src/php/       קריאה וכתיבה בשרת (ZipArchive)
  schema/        document-1.json, manifest-1.json (JSON Schema)
  tools/sync.php העתקה לאפליקציות שב-apps.json, ובדיקת עותקים ששונו (כמו devloop)
  apps.json      ריק בהתחלה; אפליקציה נכנסת רק בהסכמת הצ'אט שלה
  tests/         קבצי דוגמה + בדיקות (כולל קבצים עוינים)
```

## מה נדרש (גרסה 1)

1. **קריאה** — `open(bytes) → { manifest, document, media(), renditions(), apps(id) }`, ב-JS וב-PHP.
2. **כתיבה** — `save(model, { renditions }) → bytes`: ‏`mimetype` ראשון ולא דחוס, גיבובים, `media/` לפי sha256,
   **שמירה בית-לבית** של כל קובץ ושדה לא מוכר (סעיף 4 בפורמט).
3. **אימות** — מול ה-Schema + כל כללי סעיף 10 (zip-slip, zip-bomb, SVG, הפניות חיצוניות). מחזיר רשימת בעיות, לא זורק.
4. **ייצוגים** — מנגנון בלבד: האפליקציה מספקת `bytes + gaps`, הליבה רושמת `sourceSha256` ומסמנת מיושנים.
   `preview.png` — חובה בכל שמירה.
5. **גרסאות** — סירוב מנומס ל-major גבוה; ממיר ריק מוכן ל-1→2.
6. **סנכרון** — `php tools/sync.php` + שער בפריסה, כמו DevLoop (תיאום עם הצ'אט המתכלל לפני שנוגעים ב-`deploy.yml`).
7. **דף בדיקה** קטן: גוררים `.mbe` → רואים manifest, עץ שכבות, ייצוגים ובעיות. עוזר לכל הצ'אטים.

## ספריות

רישיון MIT/BSD/Apache בלבד, מועתקות ל-`vendor/` (לא CDN) — כמו בעורך התמלילים. ZIP בדפדפן: `fflate` (MIT) או קוד משלנו.

## לא בתחום

ציור, עימוד, המרת docx — שייכים לאפליקציות. הליבה לא יודעת לצייר PDF; היא יודעת לשמור אותו.

## סיום

קובץ דוגמה מכל `kind`, שנפתח ונשמר ב-JS וב-PHP **זהה בית-לבית במודל**; בדיקות עוינות נדחות; README; PR ממוזג
ופריסה `conclusion: success`.

## יומן

- 2026-10-22 — נוצר המסמך (הצ'אט המתכלל).
