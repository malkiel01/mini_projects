# fixtures של חוזה v2 — לבדיקות בלבד

החוזה: `dashboard/data/schema/v2/academy-with-lessons-proposal.schema.json` במאגר האקדמיה (`schemaVersion` "2.0.0",
**הצעה בלבד**). כללי ההפניות והסטטוס: `dashboard/data/schema/v2/migration-and-validation-proposal.md`. התוכן הועבר לצוות על
ידי הבקרה; הקבצים עצמם לא הועתקו לריפו.

- `excerpt-49f4b2d.json` — **קטע מקוצר וסינתטי**, לפי התיאור של `dashboard/data/templates/v2-proposed-curriculum-fixture.json`
  בקומיט **`49f4b2d`**: curriculum proposed, stage אחד, track `01` (proposed), module `01.01`, ושלושה שיעורים
  (theory / analysis / lab עם `authorizedLabRequired`). הכותרות סינתטיות (`fixture · …`). נבדק מול ה-schema ב-jsonschema
  4.26 (Draft 2020-12): **0 שגיאות**.
- `synthetic-full.json` — סינתטי, שלנו: כל הישויות (כולל competency `mastered` עם ראיה והערכה מאושרת, `dependsOn`,
  ראיה, אירועים). curriculum `approved`, ושני מסלולים מאושרים מתוך שלושה. jsonschema: **0 שגיאות**.
- `broken/schema--*.json` — שבורים ברמת ה-schema. כל אחד נכשל גם ב-jsonschema וגם ב-`academy_validate_v2`.
- `broken/rule--*.json` — עוברים את ה-schema, ונכשלים בכללי מסמך המעבר (הפניות, מחזורים, סטטוס, `mastered`, הפניה לא
  בטוחה) — רק ב-`academy_validate_v2`. `rule--event-bad-date` נמצא כאן כי jsonschema בלי חבילת `rfc3339-validator` לא
  בודק `format: date-time`, ואנחנו כן בודקים.
