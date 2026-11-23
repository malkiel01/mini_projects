# fixtures — לבדיקות בלבד

שייכים לחוזה `dashboard/data/schema/v1/academy.schema.json` במאגר האקדמיה, **קומיט `cc85ac1`**
(`schemaVersion: "1.0.0"`). שינוי בחוזה = לעדכן כאן, ב-`lib/validate.php` (ACADEMY_SCHEMA_COMMIT) ובבדיקות.

(ההערה כאן ולא בתוך ה-JSON — `additionalProperties: false` בחוזה אוסר שדה הערה.)

- `valid-v1.json` — תואם: כל 7 סטטוסי הכשירות, 4 סטטוסי משימה, 3 מצבי `mode`, שדות אופציונליים חסרים / ריקים / null.
- `empty-template.json` — זהה בתוכנו לתבנית `dashboard/data/templates/empty-snapshot.json`. חייב לעבור.
- `broken/*.json` — כל אחד שבור בדרך אחת, וחייב להיכשל. השם אומר מה שבור.
