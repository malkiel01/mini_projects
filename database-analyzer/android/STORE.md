# אנליסט מסד נתונים בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`).
**כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים
משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.dbanalyzer` |
| כתובת | `https://mbe-plus.com/mini_projects/database-analyzer/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים |
| תיקיית החנות | Release קבוע `database-analyzer-store`: `database-analyzer.apk`, `database-analyzer.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/database-analyzer-store` |
| חשבון מפתחים | פרטי — לפני פרסום: בדיקה סגורה עם **12 בודקים, 14 יום ברצף** |
| בנייה | `.github/workflows/database-analyzer-twa.yml` — AAB לחנות ו-APK לבדיקה |
| אישור האתר | `assetlinks.json` מתיקיית החנות — הפריסה מאחדת אותו לשורש `https://mbe-plus.com/.well-known/assetlinks.json` (קובץ משותף לכל האפליקציות על הדומיין) |

---

## א. הקמה חד-פעמית

### 1. הסוד של מפתח החתימה

הסוד המשותף `ANDROID_KEYSTORE_PASSWORD` (Settings → Secrets → Actions) — **כבר מוגדר**, משותף לכל
האפליקציות (`platform/docs/rollout.md`, צעד 5). לא ליצור חדש.

בבנייה הראשונה נוצר מפתח ההעלאה ונשמר בריפו **מוצפן בסיסמה הזאת** (`keystore/upload.jks.enc`).
גוגל מחזיקה מפתח חתימה משלה (Play App Signing), כך שגם אם מפתח ההעלאה אבד — מחליפים אותו דרך
התמיכה שלהם, והאפליקציה לא אובדת.

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

### 2. בנייה

GitHub → **Actions** → **Build Database Analyzer app (Google Play)** → **Run workflow**.
(רץ גם לבד בכל שינוי בתיקייה `android/`.) התוצרים — בתיקיית החנות (Release) וב-**Artifacts**.

---

## ב. Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `אנליסט מסד נתונים` · שפת ברירת מחדל: **עברית** · App · **Free**
→ לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
אנליסט מסד נתונים — MySQL
```

**תיאור קצר (עד 80):**
```
מבנה, תוכן וקשרים של מסד MySQL — לצפות, לערוך ולייצא, גם מהטלפון.
```

**תיאור מלא:**
```
כלי עבודה למפתחים ולמנהלי מערכות: מתחברים למסד MySQL ורואים הכול במקום אחד.

🗄️ סקירה
• דשבורד: טבלאות, תצוגות, קשרי מפתח זר, טריגרים, מספר שורות וגודל
• תרשים קשרים (ERD) של כל המסד

📋 טבלה
• מבנה: עמודות, סוגים, אינדקסים, טריגרים ומפתחות זרים
• נתונים: דפדוף, הוספת שורה ומחיקת שורה
• קשרים יוצאים ונכנסים — ברשימה ובתרשים
• סקריפטי SQL: CREATE, INSERT, ועוד

📤 ייצוא וניהול
• ייצוא מבנה, נתונים או שניהם ל-JSON
• הוספה ומחיקה של עמודות
• שכפול מסד וריקון מסד — עם גיבוי ואישור כפול

🔒 פרטיות
• פרטי החיבור נשמרים רק בזיכרון הדף ונמחקים בהתנתקות — לא נשמרים בשרת ולא במכשיר

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-connect.png` … `5-relations.png` |

הצילומים — מהאפליקציה עצמה ברוחב טלפון (390px), עם מסד הדגמה (`shop_demo`, נתונים בדויים).

**קטגוריה:** Tools · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/database-analyzer/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | **חסר** — צריך דף מדיניות פרטיות לפני הגשה (ראה ד.) |
| **App access** | כל האפליקציה דורשת התחברות לפלטפורמה → לתת משתמש בדיקה עם הרשאה לאפליקציה, ופרטי מסד הדגמה |
| **Ads** | No |
| **Content rating** | שאלון IARC: כלי עבודה, אין תוכן משתמשים ציבורי, אין אלימות/מין/הימורים |
| **Target audience** | **18 ומעלה** |
| **News app** | No |
| **Government app** | No |
| **Financial features** | None |
| **Health** | No |
| **Data safety** | ראה הטבלה הבאה |
| **Account deletion** | החשבון הוא חשבון הפלטפורמה — המחיקה דרך מנהל הפלטפורמה |

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Email address** (חשבון הפלטפורמה) | כן | לא | Account management | חובה |
| App activity → **Other user-generated content** (פרטי חיבור ותוכן המסד — עוברים דרך השרת, לא נשמרים) | כן (מעובד בלבד) | לא | App functionality | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes.
- פרטי החיבור (שרת, משתמש, סיסמה) עוברים בכל בקשה לשרת שמתחבר למסד, ואינם נשמרים — לא בשרת ולא במכשיר.

### 4. בדיקה סגורה (חובה בחשבון פרטי)

1. **Test and release → Testing → Closed testing** → **Create track**.
2. **Testers:** רשימת אימיילים (Gmail) של **12 אנשים לפחות** — משפחה וחברים עם אנדרואיד.
3. **Create release** → להעלות את ה-`.aab` → שם גרסה → **Save** → **Review** → **Start rollout**.
4. **Countries:** Israel.
5. לשלוח לבודקים את **קישור ההצטרפות** (Join on the web) — כל אחד מאשר ומתקין מהחנות.
6. **14 יום ברצף** עם 12 בודקים לפחות שנשארים בתוכנית. אחרי זה: **Apply for production**
   (שאלון קצר על הבדיקה), ואז פרסום.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל), ולשלוח לי — אוסיף ל-`android/play-signing-sha256.txt`,
והבנייה הבאה תעדכן את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## ג. גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** (עיצוב, תכונה, תיקון) — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים, הרשאות) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
  `versionCode` עולה לבד בכל בנייה.

## ד. מה עוד לא כאן

- **מדיניות פרטיות** — Play Console דורש כתובת. אין עדיין דף כזה באפליקציה.
- **הממשק בטלפון** — יש פריסה בסיסית לרוחב צר (הרשימה מעל התוכן), אבל הכלי נבנה למחשב.
