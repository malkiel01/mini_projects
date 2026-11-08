# `suite-cloud/` — הפניה בלבד

ענן החבילה נקרא עכשיו **ענן MBE**, והוא נמצא ב-[`mbe-cloud/`](../mbe-cloud/README.md). ההכרעה: בעל המערכת, 2026-11-07.
האפיון: `mbe-cloud/docs/spec-0-vision.md` §8.

- **`.htaccess`** מחזיר 308 לכל בקשה, אל אותו נתיב תחת `/mini_projects/mbe-cloud/`. ‏308 שומר `POST` וגוף.
- **בקשה עם אסימון** (`Authorization` / `X-Cloud-Token`) לא מופנית: **400** עם `errors/moved.json` (`code: moved`), כדי שלקוח
  שעוקב אחרי הפניות לא יעביר את האסימון הלאה בלי לדעת (`mbe-cloud/docs/spec-2-share-token.md` סעיף 7).
  הקובץ כאן ולא ב-`mbe-cloud/`, כי שם הוא היה עובר דרך השער, שמחזיר 404 ב-`ErrorDocument`.
- **`data/`** חסומה (403). הנתונים עברו ל-`mbe-cloud/data/` בשלב חד-פעמי בפריסה (`deploy.yml`, "ענן MBE — העברת הנתונים").
- **אין כאן `app.json` ואין קוד** (רק `errors/moved.json`, סטטי). בשרת נשארים הקבצים הישנים, כי `rsync` רץ בלי `--delete`. ההפניה מונעת מהם לרוץ,
  ושלב הפריסה משנה את השם של `app.json` הישן, כדי שהכלי לא יופיע פעמיים.
- **לא מוחקים את התיקייה הזו**, וגם לא דרך `platform/retired.json`. `retire-apps.php` מוחק תיקייה, וההפניה נחוצה כל
  עוד יש עותקים ישנים או סימניות.
