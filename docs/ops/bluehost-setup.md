# הגדרת השרת: נתיבי סודות ותיקיית התלמידים (Bluehost / cPanel)

מסמך הפעלה למנהל בשרת. **בלי ערכי סודות ובלי ערכים אמיתיים כאן.** כל ערך שמופיע בסוגריים מרובעים — לקבוע בעצמך ולהזין בלי להדביק בצ'אט.

- **מה זה מכסה:** תיקיית נתוני ההתקדמות (`progress_dir`), רשימת המנהלים (`progress.managers`), וקובץ אסימון GitHub (`github.token_file`).
- **מה זה לא מכסה:** פריסת הקוד. הפריסה נעשית מ-GitHub Actions ואינה נוגעת בתיקיות האלה (ראה "למה הפריסה לא תמחק").
- **לפני שמתחילים:** ביקורת PR #1811 מצאה ממצאים שצריך לתקן לפני רישום לומדים אמיתיים — ראה `docs/audit/progress-audit-2026-11-23.md`, "המלצות לפני מיזוג".

---

## 1. לאמת את נתיב הבית (חובה לפני הכול)

בתיעוד החשבון מופיעים גם `/home` וגם `/home2`. **אל תניח — אמת.**

- **cPanel → File Manager:** בתחתית העץ של הפאנל מופיע הנתיב המוחלט של תיקיית הבית, או בעמוד הראשי של cPanel: שורת *Home Directory*.
- **SSH:** אחרי ההתחברות, `pwd` מיד אחרי הכניסה, או `echo $HOME`.

התוצאה אמורה להיות `/home2/mbeplusc` (כך נפרס `mini_projects` בפועל, ראה `.claude/skills/deploy-to-cpanel/references/server-facts.md`). אם התוצאה שונה — להשתמש בה במקום בכל הנתיבים כאן.

נתיב האתר בשרת: `/home2/mbeplusc/public_html/mini_projects`.

## 2. תיקיית התלמידים — `progress_dir`

**הכלל:** מחוץ ל-`public_html`, לא בתוך העץ של האתר, ולא פתוחה לאחרים.

| פרמטר | ערך |
| --- | --- |
| נתיב מוחלט | `/home2/mbeplusc/academy-progress` |
| הרשאות | `700` |
| בעלים | החשבון שלך (`mbeplusc`) |

**יצירה ב-SSH:**

```bash
mkdir -p /home2/mbeplusc/academy-progress
chmod 700 /home2/mbeplusc/academy-progress
```

**יצירה ב-cPanel:** File Manager → Home (`/home2/mbeplusc`) → New Folder → `academy-progress` → לחיצה ימנית → Change Permissions → `0700` (ללא Read/Write/Execute לקבוצה ולכולם).

**מה הקוד בודק** (`cyber-academy/lib/progress.php:42-53`): אם התיקייה לא תקינה, השרת מחזיר `503 progress_unavailable` עם `reason` ובלי נתיב. הקודים:

| `reason` | המשמעות | מה לעשות |
| --- | --- | --- |
| `progress_dir_missing` | ריק, לא קיים, או לא תיקייה | לבדוק שהנתיב נכון, שהתיקייה נוצרה |
| `progress_dir_not_absolute` | נתיב יחסי | להזין נתיב שמתחיל ב-`/` |
| `progress_dir_unsafe` | בתוך העץ, `..`, או קישור סימבולי לתוך העץ | להעביר את התיקייה מחוץ ל-`public_html` |
| `progress_dir_insecure` | קריאה או כתיבה לכולם | `chmod 700` |

**לא לשים** את התיקייה ב-`public_html`, ב-`www`, ב-`htdocs`, ולא כקישור סימבולי לשם. הקוד בודק גם את `realpath`.

## 3. קובץ אסימון GitHub — `github.token_file`

**הכלל:** האסימון **לא** נכנס לקונפיג בתור ערך. הקונפיג מכיל רק את הנתיב לקובץ, והקובץ מחוץ לעץ האתר ומוגבל ל-`600`. הקוד דוחה מפתח `token` בקונפיג (`academy-source.php:142`).

| פרמטר | ערך |
| --- | --- |
| תיקייה | `/home2/mbeplusc/academy-secrets` |
| קובץ | `/home2/mbeplusc/academy-secrets/github-token` |
| הרשאות תיקייה | `700` |
| הרשאות קובץ | `600` |

**יצירה ב-SSH — בלי שהאסימון יופיע בהיסטוריה או במסך:**

```bash
mkdir -p /home2/mbeplusc/academy-secrets
chmod 700 /home2/mbeplusc/academy-secrets
umask 077
read -r -s -p "הדבק אסימון ולחץ Enter: " TOKEN; echo
printf '%s' "$TOKEN" > /home2/mbeplusc/academy-secrets/github-token
unset TOKEN
chmod 600 /home2/mbeplusc/academy-secrets/github-token
```

- `read -s` לא מציג את הטקסט על המסך.
- **אל תכתוב את הערך בשורת פקודה** (`printf 'ghp_...'`) — זה נשמר בהיסטוריה.
- אם יש ספק שהאסימון דלף: לבטל אותו ב-GitHub וליצור חדש. לא להשאיר ערך ישן.

**מה הקוד בודק** (`academy-source.php:141-170`): אורך לפחות 20 תווים, בלי רווח באמצע, קריאה לכולם נדחית (`secret_insecure_perms`), ושגיאות מחזירות קוד בלי ערך.

## 4. קונפיג השרת — `data/config.json`

הקובץ **לא** נמצא בריפו ולא נדחף בפריסה (`deploy.yml:347`: `--exclude='/cyber-academy/data/*'`). צריך ליצור אותו ידנית בשרת:

`/home2/mbeplusc/public_html/mini_projects/cyber-academy/data/config.json`

התוכן המינימלי הרלוונטי למה שבמסמך הזה:

```json
{
  "progress_dir": "/home2/mbeplusc/academy-progress",
  "progress": {
    "managers": ["[sub של המנהל בפלטפורמה]"]
  },
  "github": {
    "token_file": "/home2/mbeplusc/academy-secrets/github-token"
  }
}
```

- `progress_dir` — חובה לפני כל קריאה או כתיבה של נתוני לומד. בלעדיו: 503.
- `progress.managers` — רשימת `sub` של מנהלים. ברירת מחדל ריקה = **אין מנהל**, וכל כתיבה נחסמת (`progress-api.php:56`). **לא** להעתיק מזהה ממסמך, מדוגמה או מצ'אט.
- `github.token_file` — נדרש רק כשמפעילים את מקור ה-GitHub. יש שדות נוספים בקונפיג (`source`, `github_enabled`, `contract` ועוד) עם ברירות מחדל ב-`academy-source.php:49-74`; לא לשנות אותם בלי צורך.
- **JSON תקין חשוב:** קובץ שאינו JSON נטען כברירת מחדל בשקט (`academy-source.php:85`). לבדוק לפני השמירה, למשל: `php -r 'var_dump(is_array(json_decode(file_get_contents("/home2/mbeplusc/public_html/mini_projects/cyber-academy/data/config.json"), true)));'` — צריך להדפיס `bool(true)`.

**מזהה `sub` של המנהל:** הקוד לא מתעד בשום מקום איך מקבלים אותו (לא נבדק — ראה "מה לא נבדק" בביקורת). לקבל אותו מהפלטפורמה עצמה (מסך ניהול החשבון / `platform/docs/auth.md`), ולא להזין ערך מוכן.

## 5. בדיקה אחרי ההגדרה

1. **הרשאות:** ב-SSH:
   ```bash
   ls -ld /home2/mbeplusc/academy-progress        # צריך להיות: drwx------
   ls -l  /home2/mbeplusc/academy-secrets/github-token   # צריך להיות: -rw-------
   ```
2. **נתיבים מחוץ לאתר:**
   ```bash
   readlink -f /home2/mbeplusc/academy-progress   # לא אמור להתחיל ב-/home2/mbeplusc/public_html
   ```
3. **הנקודה בדפדפן:** פתיחת `…/mini_projects/cyber-academy/api/progress.php` בלי להתחבר → 403 (השער). מחובר כמנהל → `{"ok":true,"learnerId":null,"progress":null}` (אין עדיין רשומות). אם מוחזר 503 — לקרוא את `reason` בטבלה שבסעיף 2.
4. **הקובץ `data/config.json` לא נגיש מהדפדפן:** `…/cyber-academy/data/config.json` צריך להחזיר 403. הקובץ `data/.htaccess` חוסם (`Require all denied`); לוודא שהשרת (LiteSpeed) אכן מכבד אותו — זה **לא נבדק** בביקורת.

## 6. גיבוי

- גבה את `academy-progress/` כולה, **כולל `audit.log`** (יומן הביקורת). היא לא בתוך `public_html`, לכן לא מגיעה לגיבוי של האתר — צריך גיבוי נפרד.
- גבה את `academy-secrets/` בנפרד, במקום מאובטח, ולא בתוך הריפו.

## למה הפריסה לא תמחק

- התיקיות האלה **מחוץ** לעץ הריפו — `rsync` לא נוגע בהן.
- `data/*` מוחרג מה-rsync (`deploy.yml:347`), כך ש-`config.json` ו-`data/` לא מועתקים ולא נמחקים.
- **אם מוסיפים כלי חדש שכותב נתונים לשרת**, הוא חייב `--exclude` משלו ב-`deploy.yml` (ראה `CLAUDE.md`).

## מה לא להדביק בצ'אט

- ערך של אסימון GitHub.
- `sub` של משתמש.
- סיסמאות cPanel או מפתחות SSH.
- תוכן של `academy-progress/` או `audit.log`.

> **סיכום:** מסמך הגדרה לשרת: תיקיית התלמידים `/home2/mbeplusc/academy-progress` (700, מחוץ ל-public_html), קובץ האסימון ב-`academy-secrets` (600), ו-`data/config.json` שנוצר ידנית עם `progress_dir` ו-`progress.managers`. בלי ערכי סודות.
> הרחבה למעלה ב"1. לאמת את נתיב הבית" וב"5. בדיקה אחרי ההגדרה".
