# ענן החבילה (`suite-cloud/`)

ה-Creative Cloud של חבילת היצירה: חשבון אחד, כוננים זה לצד זה, וספריות משותפות לכל האפליקציות. זו אפליקציה
עצמאית, עם חשבונות, מסד ונתונים משלה. האפליקציות מתחברות אליה ב-HTTPS בלבד, ובלעדיה עובדות כמו היום.

- **האפיון הכללי:** `creative-suite/docs/spec/shared.md` · **ההכרעה:** `creative-suite/docs/requests/spec-shared.md`
- **שלב 1, השלד:** [`docs/spec-1-skeleton.md`](docs/spec-1-skeleton.md)
- **ההתקדמות:** `creative-suite/docs/projects/suite-cloud.md`

## מה יש

| חלק | מה |
| --- | --- |
| חשבון | הזהות מהשער (`PLATFORM_USER`). אותו מייל = אותו אדם. מי שמגיע עם `platform_admin` הוא מנהל הענן |
| כונן פנימי | העץ ב-`data/cloud.sqlite`, התוכן ב-`data/files/ab/cd/<sha256>`. מכסה לכל חשבון (ברירת מחדל 0 עד שהמנהל מאשר), תקרה לקובץ (500MB), העלאה בחלקים של 8MB, וסל של 30 יום |
| API | `api.php?action=…`: `list`, `stat`, `read`, `mkdir`, `write`, `upload_start/chunk/finish`, `rename`, `move`, `trash`, `restore`, `trash_list`, `purge`, `trash_empty`, ופעולות ניהול. הפירוט באפיון, סעיף 6 |
| הדף | `index.html`, בעיצוב `phone-first-design`, עם הלשוניות קבצים, סל וניהול |

## מה עוד לא

- **חיבור אפליקציות:** `connect`, אסימון חתום, ניתוק. ה-API בינתיים פתוח רק לדף של הענן, מאחורי השער
- **לקוח הענן:** מודול JS + PHP מסונכרן לכל אפליקציה
- ספריות (גופנים, צבעים, סגנונות, נכסים), כוננים חיצוניים, צוותים, גרסאות, חיפוש

## בשרת

`data/` אינה בגיט, חוץ מ-`.htaccess` שחוסם גישה ישירה. `deploy.yml` מחריג את כל מה שבתוכה, ולכן פריסה לא נוגעת
בקבצים של המשתמשים.

## בדיקות

```
php suite-cloud/tests/run.php
```
