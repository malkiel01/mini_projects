# עובדות השרת

מה שנצפה בפועל. ערך שלא אומת מסומן ככזה — אל תציג אותו כוודאי.

## החשבון

| | ערך | מקור |
| --- | --- | --- |
| ספק | Bluehost, cPanel | תיעוד הפרויקטים |
| משתמש | `mbeplusc` | נתיבים בתיעוד ובפריסות |
| דומיין ראשי | `mbe-plus.com` → `67.222.39.68` | DNS, ולוגי פריסה מוצלחת |
| SSH | פורט 22 סטנדרטי | פריסה מוצלחת |
| shell | `jailshell` (cPanel מוגבל) | הודעות שגיאה מהשרת |
| SSH בשרת | OpenSSH 7.4 | banner בלוג |
| PHP | 8.3 | תואם ל‑`setup-php` שבשימוש |

**השרת תומך בחתימות `rsa-sha2-256` ו‑`rsa-sha2-512`** (`server-sig-algs` בלוג). אין צורך בדגלי `ssh-rsa` — הוספתם היא תיקון לבעיה שלא קיימת.

## נתיבים

⚠️ **`/home` ו‑`/home2` שניהם מופיעים בתיעוד של החשבון.** אל תניח — אמת מול המשתמש (cPanel → File Manager, או שורת Home Directory בעמוד הראשי).

| פרויקט | נתיב | אומת |
| --- | --- | --- |
| `mini_projects` | `/home2/mbeplusc/public_html/mini_projects` | ✅ פריסה עברה |
| `kadisha-vaad` | `/home/mbeplusc/public_html/kadisha-vaad` | מהתיעוד, לא אומת ישירות |

**גזירת נתיב:**

- תת־תיקייה תחת הדומיין הראשי → `<home>/public_html/<folder>`, והכתובת `https://mbe-plus.com/<folder>/`
- סאב־דומיין → docroot נפרד שנקבע ביצירתו. `dir` שנמסר ל‑`uapi` הוא **יחסי ל‑home**, לא מוחלט.

**`DEPLOY_PATH` תמיד מוחלט ובלי `/` בסוף** — ה‑workflow מוסיף אותו.

## ענפי פריסה

**אל תניח `main`.** בחשבון הזה זה לא תמיד נכון:

| ריפו | ענף שממנו האתר מוגש |
| --- | --- |
| `kadisha-vaad` | `main` |
| `mini_projects` | `claude/landing-page-coordinates-0yx2p` |

בדוק מה ה‑HEAD branch בפועל לפני שכותבים `branches:` ב‑workflow. ענף שגוי = מיזוגים שלא מפרסים כלום, בלי שום הודעת שגיאה.

## מפתחות SSH בשרת

ב‑cPanel → SSH Access → Manage SSH Keys:

- מפתח ציבורי במצב `authorized` = משהו משתמש בו. **אל תמחק** גם אם אין לו מפתח פרטי מקומי — החצי הפרטי יושב במקום אחר (למשל בסוד בגיטהאב), ומחיקה תנתק אותו.
- אותו זוג מפתחות יכול להתקיים בשתי צורות: נעול ב‑cPanel, פתוח בגיטהאב. **המפתח הציבורי זהה בשתיהן**, ולכן הסרת סיסמה מעותק אינה דורשת אישור מחדש ואינה משפיעה על שימושים אחרים.
- `id_levayot` משמש את הפריסות. נעול בסיסמה בעותק שב‑cPanel.

## מה שלא אומת

- **`uapi` בתוך `jailshell`** — הדרך של cPanel ליצור סאב־דומיין מ‑SSH היא `uapi SubDomain addsubdomain domain=<sub> rootdomain=<root> dir=<dir-relative-to-home>`. זמינותה בחשבון הזה **לא נבדקה**. `provision.yml` בודק ומדווח לפני שהוא מנסה.
- אם היא חסומה — יצירה ידנית ב‑cPanel → Domains → Create A New Domain, וההמשך זהה.
