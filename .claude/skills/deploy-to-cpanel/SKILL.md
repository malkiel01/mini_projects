---
name: deploy-to-cpanel
description: Set up automatic deployment of a project to the user's cPanel/Bluehost server via GitHub Actions rsync-over-SSH. Handles the target domain or subdomain (including creating it), the server folder, an existing or brand-new GitHub repo, and the four deploy secrets. Use this whenever the user wants a new project to go live on their server, mentions putting a site or tool on a domain or subdomain, asks why a repo is not updating on the server, or asks to set up, fix, or debug automatic deployment, GitHub Actions deploy, rsync to server, or DEPLOY_SSH_KEY. Also use it when a deploy workflow fails with Permission denied, "not a key file", or jailshell errors — the troubleshooting reference maps each symptom to its cause.
---

# הקמת פריסה אוטומטית לשרת cPanel

מקים פרויקט חדש שנפרס לבד: push לענף → גיטהאב מתחבר ב‑SSH לשרת ומעלה ב‑rsync.

התבנית כאן **אומתה בייצור** — היא זהה לזו שמריצה את `kadisha-vaad` (מאות פריסות) ואת `mini_projects`. אל תמציא מחדש; העתק והתאם.

## מה לאסוף מהמשתמש לפני שמתחילים

שאל רק את מה שחסר — הרבה מזה כבר ידוע מ‑`references/server-facts.md`:

1. **דומיין או סאב־דומיין יעד** — למשל `tool.mbe-plus.com`, או נתיב תחת דומיין קיים כמו `mbe-plus.com/mini_projects/x`.
2. **האם היעד כבר קיים.** בדוק ב‑DNS לפני ששואל: `getent hosts <domain>`. תשובה ריקה = צריך ליצור.
3. **תיקיית היעד בשרת** — אם לא צוינה, גזור אותה לפי הכללים ב‑`references/server-facts.md`.
4. **ריפו** — קיים (שם) או חדש (ליצור).
5. **ענף הפריסה** — הענף שממנו האתר מוגש. אל תניח `main`; ברירות מחדל לא‑שגרתיות קיימות (ראה server-facts).

## הסדר שחוסך זמן

הסדר הזה לא שרירותי: כל שלב מאמת את הקודם, וכשל מתגלה מוקדם וזול.

### 1. הכן את הריפו והקוד

ריפו קיים — קלון ועבוד עליו. ריפו חדש — צור אותו, ואז את הקוד.

### 2. הוסף את שני ה‑workflows

העתק מ‑`assets/`:

| קובץ | תפקיד |
| --- | --- |
| `assets/deploy.yml` | הפריסה עצמה. **התאם את `branches:` ואת רשימת ההחרגות** |
| `assets/provision.yml` | הקמה חד־פעמית: יוצר תיקייה, ואם צריך גם סאב־דומיין |

**החרגות — זו הנקודה שהכי קל לטעות בה.** `--exclude='vendor/'` נכון כשזו תיקיית composer שמותקנת בשרת, ו**הרסני** כשזה קוד שנמצא בריפו. עבור על התיקיות בפועל והחלט לכל אחת. החרג תמיד קבצי ריצה שנוצרים בשרת (הגדרות, אסימונים, העלאות משתמשים) — הם לא בריפו, ו‑rsync רץ בלי `--delete` כדי לא למחוק אותם.

### 3. הרץ את ההקמה

`provision.yml` מופעל ידנית מ‑Actions → Run workflow, עם היעד כקלט. הוא מתחבר לשרת עם אותו מפתח פריסה, יוצר את התיקייה, ומדווח מה קיים.

⚠️ **יצירת סאב־דומיין דרך `uapi`** — הפקודה `uapi SubDomain addsubdomain` היא הדרך של cPanel לעשות זאת מתוך SSH, אבל **זמינותה בחשבון הזה לא אומתה**. ה‑workflow בודק זאת ומדווח; אם היא חסומה, צור את הסאב־דומיין ידנית ב‑cPanel → Domains → Create A New Domain, והמשך. אל תתאר את המסלול האוטומטי כעובד לפני שראית אותו מצליח בלוג.

### 4. הזן את הסודות — זה החלק שנכשל הכי הרבה

ארבעה סודות ב‑Settings → Secrets and variables → **Actions** (לא Environment):

| Secret | ערך |
| --- | --- |
| `DEPLOY_HOST` | כתובת השרת |
| `DEPLOY_USER` | שם המשתמש בשרת |
| `DEPLOY_PATH` | הנתיב המלא לתיקייה, **בלי `/` בסוף** |
| `DEPLOY_SSH_KEY` | המפתח הפרטי, **בלי סיסמה** |

אתה לא יכול לכתוב סודות — אין כלי לכך, וזו הפרדה מכוונת של גיטהאב. תן למשתמש קישור ישיר ל‑`https://github.com/<owner>/<repo>/settings/secrets/actions` ורשימה מדויקת.

**קרא את `references/troubleshooting.md` לפני שאתה מנחה בהזנת המפתח.** שם מתועדות התקלות שעלו בפועל, וכל אחת מהן עלתה הרצות מיותרות.

### 5. הרץ ואמת

מזג לענף הפריסה → הרצה מתחילה לבד. קרא את הלוג. `Deploy complete` = הצליח.

**אמת מול המציאות ולא מול ההיגיון שלך.** אל תכריז על הצלחה כי ההרצה "עוד רצה" או כי התיקון "אמור לעבוד" — רק אחרי `conclusion: success` בלוג.

## כשמשהו נכשל

`references/troubleshooting.md` ממפה כל סימפטום לסיבה, עם החתימה המדויקת בלוג. **גש לשם לפני שאתה מנסח תיאוריה משלך.**

שני כללים שנקנו ביוקר:

**השווה לפרויקט שכבר עובד.** אם יש ריפו אחר שנפרס לאותו שרת בהצלחה — זו נקודת הייחוס. workflow זהה + תוצאה שונה ⟵ ההבדל בקלט (בסודות), לא בקוד. השוואה כזו חוסכת שעות של תיאוריות על ה‑workflow.

**אל תוסיף מנגנונים לפני שאבחנת.** קל להוסיף דגלי ssh, ssh-agent ושלבי עזר כדי "לתקן" — וכולם מיותרים אם הבעיה בתוכן של סוד. תוספת שלא נובעת מאבחון היא רעש שמסתיר את הסיבה.

## מגבלות שכדאי לדעת מראש

- **אין גישה ישירה לשרת** מהסביבה הזו — מדיניות הרשת חוסמת אותו. כל פעולה על השרת עוברת דרך GitHub Actions, שכן מורשה. זו הסיבה ש‑`provision.yml` קיים.
- **אין הרשאת `workflow_dispatch`** — הפעלת workflow ידנית מחזירה 403. המשתמש מפעיל מה‑UI; מזיגה לענף מפעילה את הפריסה.
- **אי אפשר לקרוא או לכתוב סודות.**

## קבצי עזר

| קובץ | מתי לקרוא |
| --- | --- |
| `references/troubleshooting.md` | לפני הנחיה על המפתח, ובכל כשל |
| `references/server-facts.md` | לגזירת נתיבים, שם משתמש, ענפי פריסה |
| `assets/deploy.yml` | תבנית הפריסה |
| `assets/provision.yml` | תבנית ההקמה |
