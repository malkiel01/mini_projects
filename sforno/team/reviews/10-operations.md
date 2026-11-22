# ביקורת — פרוסה 10 (PR #1801)

בקרה (2), 22.11 ~21:55 UTC, עם סוקר עומק (קלט מבחוץ, מחיקה ושחזור, סודות בגיבוי). סבב 1 — **לתיקון (🟡), בלי 🔴**.

🤖 run.php 14/14, check-apps, sync --check, php -l, node --check — ירוקים; אין שינוי מחוץ ל-`sforno/`. `data/backups/` בתוך
`/sforno/data/*` המוחרג; `sforno/.gitignore` חדש — בגיט רק `data/.htaccess`. נמצא תקין: הורדה — dev בלבד, שם לפי תבנית, no-store;
שחזור — CLI בלבד, שמות מה-manifest, `..` נדחה, `secret.key` הנוכחי נשמר ב-`.replaced-*`; ניקוי — `status='deleted' AND deleted_at < now-30d`
בטרנזקציה לכל קורא, כל הטבלאות; `report-create` — json מדויק + same_site, 8000, שדות מותרים, contact מוצפן, תצוגה דרך `SF.h`;
CURLOPT_RESOLVE — נפתר פעם אחת ומוצמד; הגבלת webhook לפני הגוף; טסטים בלי רשת (`sf_dns` מדומה).

## 🟡 לתיקון
1. **שחזור לא אטומי** (`lib/backup.php:279-287`): `mkdir`/`rename` בלי בדיקת תוצאה — כשל באמצע משאיר תיקייה חצי ישנה וחצי משוחזרת,
   וה-CLI מדפיס "✓ שוחזר". ובזמן השחזור בקשות רשת ממשיכות לכתוב למסד הישן. תיקון: לבדוק כל rename, rollback של מה שב-`$moved` וזריקה;
   קובץ "תחזוקה" ש-`api.php` עונה עליו 503 בזמן ה-apply. טסט לכשל באמצע.
2. **הגיבוי שיורד הוא zip פתוח עם `secret.key` ליד המסד** (`backup.php:27,96,315-327`) — מי שיש לו את הקובץ (מחשב המפתח, ענן) קורא
   הערות ושמות תפילה, ומקבל את מפתח החנות ו-VAPID. תיקון: ההורדה מוצפנת בסיסמה (sodium secretstream + Argon2id; הסיסמה לא נשמרת בשרת),
   והשחזור מבקש אותה. הגיבוי שנשאר בשרת (`data/backups/`) — יכול להישאר כמו שהוא.
3. **קישור במייל הדיווח** (`http.php:29-31`) — מ-cron אין `SERVER_NAME` → `https://localhost/...`. תיקון: מקור קבוע (meta / הגדרה במסך
   המפתח, או ה-origin האחרון שנראה בבקשת רשת).

## ⚪ הערות
- `sf_net_ip_public` מפספס NAT64 (`64:ff9b::/96`), 6to4 (`2002::/16`), `fec0::/10`, multicast (`ff00::/8`, `224.0.0.0/4`), `198.18.0.0/15` — להוסיף (אותו תיקון).
- הגבלות קצב לפי `REMOTE_ADDR` — נכון כל עוד אין CDN/proxy מלפנים.
- הגנת `data/backups/` נבדקת על הדיסק בלבד — בדיקה ידנית אחרי הפריסה: בקשה ל-`sforno/data/backups/<name>.zip` ← 403 (מטלה).

## סבב 2 — ✅ (בקרה 3, 22.11 ~22:00 UTC, בלי סוקר)
#1802 (`claude/sforno-10-fix`): 🟡1 — `maintenance.json` חוסם api/cron, כל rename נבדק, ביטול בסדר הפוך, `finally`, טסט כשל בהזזה הרביעית.
🟡2 — הורדה רק מוצפנת (Argon2id + secretstream, POST, סיסמה לא נשמרת). 🟡3 — `meta.public_url` לקישורי cron/CLI. ⚪ טווחי IP — נוספו.
🤖 run.php 14/14 (n-ops 186), check-apps, sync --check, php -l, node --check — ירוקים; מחוץ ל-`sforno/` רק שורת ספורנו ב-`.claude/teams/STATE.md`. למיזוג.
