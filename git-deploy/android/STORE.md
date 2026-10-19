# git-deploy בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity), מועתקת מ-`recipes-app/android/`: היא פותחת את האתר ב-Chrome,
במסך מלא, בלי שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`).
**כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים משהו כאן.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.gitdeploy` |
| כתובת | `https://mbe-plus.com/mini_projects/git-deploy/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`) |
| תיקיית החנות | Release קבוע `git-deploy-store`: `git-deploy.apk`, `git-deploy.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/git-deploy-store` |
| בנייה | `.github/workflows/git-deploy-twa.yml` — רצה לבד בכל שינוי ב-`git-deploy/android/` |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`; המפתח נוצר בבנייה הראשונה ונשמר מוצפן ב-`keystore/upload.jks.enc` |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לבד ל-`https://mbe-plus.com/.well-known/assetlinks.json` (`platform/tools/assetlinks.php`) |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

> git-deploy היא כלי ניהול אישי (מי שיש לו הרשאה בפלטפורמה). ה-APK מתיקיית החנות הוא הדרך העיקרית
> להתקין אותה. פרסום ציבורי ב-Play — רק אם יוחלט; הטקסטים כאן מוכנים לזה.

---

## Play Console

**Create app** → שם: `git-deploy — פריסה לשרת` · שפת ברירת מחדל: **עברית** · App · **Free**.

**שם (עד 30):**
```
git-deploy — פריסה לשרת
```

**תיאור קצר (עד 80):**
```
פריסת ריפוזיטוריז מ-GitHub לשרת שלך: ריפו, branch ותיקייה — ו-Pull בלחיצה.
```

**תיאור מלא:**
```
git-deploy מעדכן אתרים בשרת ישירות מ-GitHub — גם מהטלפון.

🚀 פריסה
• רשימת הריפוזיטוריז שלך מ-GitHub, כולל פרטיים
• לכל ריפו — ניתובים: branch ותיקיית יעד בשרת
• Pull בלחיצה, עם הפלט המלא של git מול העיניים
• תיקייה חדשה? clone אוטומטי. תיקייה קיימת? משיכה

📋 סדר
• כל ניתוב זוכר את ה-commit האחרון, ההודעה שלו ומתי נמשך
• כמה ניתובים לאותו ריפו — אתר חי ואתר בדיקות

🔐 אבטחה
• כניסה רק דרך חשבון הכלים הדיגיטליים, עם הרשאה מהמנהל
• אסימון GitHub נשמר בשרת שלך בלבד

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-repos.png` … `4-settings.png` — נתוני הדגמה (`demo-studio`), לא ריפוז אמיתיים |

**קטגוריה:** Tools · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/`

### App content

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | עדיין אין דף לאפליקציה הזו — נדרש לפני פרסום ציבורי |
| **App access** | כולה דורשת התחברות → משתמש בדיקה בפלטפורמה עם הרשאה ל-git-deploy |
| **Ads** | No |
| **Content rating** | אין תוכן משתמשים ציבורי, אין אלימות/מין/הימורים |
| **Target audience** | 18 ומעלה |
| **Data safety** | Email/Name (חשבון הפלטפורמה) — Account management, חובה. אין שיתוף. Encrypted in transit: Yes |

### אחרי ההעלאה הראשונה

**App integrity → App signing** → SHA-256 של **App signing key certificate** → להוסיף ל-
`android/play-signing-sha256.txt`. הבנייה הבאה מעדכנת את `assetlinks.json`. בלי זה מה שמותקן מהחנות נפתח
עם שורת כתובת (ה-APK מתיקיית החנות — לא מושפע: הוא חתום במפתח ההעלאה).
