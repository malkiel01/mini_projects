# בקשה לצ'אט הפלטפורמה — העברת קבצי הריצה של "קניות משותפות"

**מאת:** הצ'אט של קניות משותפות (`family-shopping/`) · **תאריך:** 2026-10-26.
**הכרעת בעל המערכת (2026-10-26):** האפליקציה עוברת מ-`public_html/form/family` ל-`mini_projects/family-shopping/`,
ו-MySQL נשאר. הקוד כבר בתיקייה החדשה (#867). כל הפרטים: `family-shopping/docs/migration.md`.
**לי אין גישה לשרת** — ולכן הבקשה אליכם, דרך GitHub Actions.

שני חלקים. **חלק א' — עכשיו.** **חלק ב' — רק כשבעל המערכת אומר "מעבר"** (אחרי שלב 4, החיבור לשער).
עד חלק ב' **האתר הישן ממשיך לעבוד כמו היום** — לכן בחלק א' רק **מעתיקים**, לא מזיזים ולא מוחקים.

המקור: `~/public_html/form/family` (בשרת: `/home2/mbeplusc/public_html/form/family`).
היעד: `<DEPLOY_PATH>/family-shopping` (`public_html/mini_projects/family-shopping`).

## חלק א' — עכשיו

1. **`.env`** — העתקה כמו שהוא, עם אותן הרשאות (`cp -p`). לא לערוך: `config.php` מתעלם מ-`APP_BASE_PATH` ו-`BASE_URL`
   שבו.
   - **מפתחות ה-VAPID** חייבים להישאר אותם מפתחות, כדי ש-14 מנויי ה-Push ימשיכו לקבל.
2. **`uploads/`** — העתקה של כל הקבצים (25 קבלות). **לא לדרוס** את `uploads/.htaccess` שבתיקייה החדשה — הוא כבר
   הגיע בפריסה. למשל: `rsync -a --ignore-existing <מקור>/uploads/ <יעד>/uploads/`.
3. **בדיקה לקריאה בלבד במסד** — עם הפרטים מה-`.env`, בלי לשנות כלום. להחזיר את התוצאות כמו שהן (מספרים בלבד,
   **בלי כתובות מייל** ביומן של Actions):

   ```sql
   SELECT COUNT(*) AS users_total,
          SUM(email IS NULL OR email = '') AS users_without_email,
          SUM(is_active = 0) AS users_inactive
   FROM users;

   SELECT COUNT(*) AS duplicate_email_groups FROM (
     SELECT LOWER(TRIM(email)) e FROM users
     WHERE email IS NOT NULL AND email <> ''
     GROUP BY e HAVING COUNT(*) > 1
   ) d;

   SELECT auth_type, COUNT(*) FROM users GROUP BY auth_type;

   SELECT COUNT(*) AS pending_invitations FROM group_invitations WHERE status = 'pending';

   SHOW COLUMNS FROM users;
   ```

4. **לוודא:** `php <יעד>/cron/process-notifications.php` רץ מהטרמינל ומדפיס הודעה (ולא `.env file not found`). זה
   אידמפוטנטי — מעבד את התור כמו ה-cron הקיים. ואם לא רוצים לגעת בתור: `php -r 'require "<יעד>/config.php";
   getDBConnection(); echo "ok\n";'`.

## חלק ב' — רק כשבעל המערכת אומר "מעבר"

1. **סנכרון אחרון של `uploads/`** — אותה פקודה (`--ignore-existing`), לקבלות שעלו באתר הישן בינתיים.
2. **שורת ה-cron** — להחליף (לא להוסיף שורה):
   ```
   */24 * * * * /usr/local/bin/php /home2/mbeplusc/public_html/form/family/cron/process-notifications.php
   ```
   ב:
   ```
   */24 * * * * /usr/local/bin/php /home2/mbeplusc/public_html/mini_projects/family-shopping/cron/process-notifications.php
   ```
   (שתיהן משתמשות באותו קובץ נעילה ובאותו תור — חפיפה של דקות לא תשלח פעמיים.)
3. **הפניה מהכתובת הישנה.** לגבות את `form/family/.htaccess` (`.htaccess.before-move`), ולהחליף את התוכן ב:
   ```apache
   RewriteEngine On
   RewriteRule ^(.*)$ https://mbe-plus.com/mini_projects/family-shopping/$1 [R=301,L]
   ```
   התיקייה משרתת שתי כתובות — `mbe-plus.com/form/family/…` ו-`form.mbe-plus.com/family/…` (הסאב-דומיין שהשורש שלו
   `public_html/form`) — והכלל הזה מכסה את שתיהן. השאילתה (`join.php?token=…`) עוברת כמו שהיא.
4. **לא למחוק** את `form/family` (קוד, `.env`, `uploads/`) — הוא נשאר כגיבוי עד שבעל המערכת יחליט אחרת.

## מה להחזיר

- חלק א': אישור על 1, 2 ו-4, ותוצאות הבדיקה (3).
- חלק ב': אישור על כל שלב, וקוד ה-HTTP של `https://mbe-plus.com/form/family/join.php?token=x` ושל
  `https://form.mbe-plus.com/family/` (מצופה 301 לכתובת החדשה).
