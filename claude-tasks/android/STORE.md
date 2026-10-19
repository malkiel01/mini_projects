# מטלות לקלוד בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`), בהעתקה
מהמתכונים (`recipes-app/android/`). **כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות.
גרסה חדשה לחנות — רק כשמשנים משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.claudetasks` |
| כתובת | `https://mbe-plus.com/mini_projects/claude-tasks/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים |
| תיקיית החנות | Release קבוע `claude-tasks-store`: `claude-tasks.apk`, `claude-tasks.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/claude-tasks-store` |
| בנייה | `.github/workflows/claude-tasks-twa.yml` — AAB לחנות ו-APK לבדיקה, בכל שינוי ב-`android/` |
| חתימה | מפתח העלאה משלה, מוצפן ב-`keystore/upload.jks.enc` בסיסמה המשותפת `ANDROID_KEYSTORE_PASSWORD` |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לשורש `https://mbe-plus.com/.well-known/assetlinks.json` (`platform/README.md`) |

⚠️ **לא למחוק את `keystore/upload.jks.enc`** אחרי ההעלאה הראשונה לחנות.

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `מטלות לקלוד` · שפת ברירת מחדל: **עברית** · App · **Free** → לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
מטלות לקלוד
```

**תיאור קצר (עד 80):**
```
זורקים מטלה — Claude עובד עליה לבד. שאלה פתוחה לא עוצרת את השאר.
```

**תיאור מלא:**
```
לוח מטלות ש-Claude עובד עליו לבד, מטלה אחר מטלה.

כשהכול יושב בצ'אט אחד ארוך, אי אפשר לדעת מה ביקשת, מה נענה ומה נשאר תלוי. כאן כל בקשה היא רשומה עם מצב משלה.

🗂️ קטלוג אוטומטי
• כותבים שורה — המערכת משייכת אותה לנושא הנכון
• מילות מפתח בחינם, ומודל בינה מלאכותית כשרוצים
• כשהמערכת לא בטוחה — המטלה נשארת בלי נושא, ולא בנושא הלא נכון

🔁 לולאה, לא רשימה
• שאלה של Claude לא עוצרת את העבודה: הוא רושם אותה וממשיך למטלה הבאה
• ברגע שעניתם — המטלה חוזרת לראש התור
• סדר עבודה חכם: תשתית לפני לוגיקה, דחוף לפני רגיל, ותלויות בין מטלות

👩‍💻 סביבת פיתוח
• פרויקטים שקשורים לריפו בגיטהאב, עם הרשאות לכל חבר
• הרצת מטלות קוד בגיטהאב — כותב, מריץ בדיקות, ופותח PR
• ספקי בינה מלאכותית לבחירה: Anthropic, OpenAI, Gemini — על החשבון שלכם

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-board.png` … `4-queue.png` |

הצילומים — מהאפליקציה עצמה ברוחב 390px, עם נתוני הדגמה (לא מטלות אמיתיות).

**קטגוריה:** Productivity · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/claude-tasks/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/claude-tasks/privacy.html` |
| **App access** | כל האפליקציה דורשת התחברות דרך הכלים הדיגיטליים → לתת משתמש בדיקה בפלטפורמה, עם הרשאה לאפליקציה |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; אין שיתוף פומבי — תוכן רק בין חברי אותו פרויקט |
| **Target audience** | **18 ומעלה** |
| **News app / Government app** | No |
| **Financial features / Health** | None / No |
| **Account deletion** | פנייה ל-`support@mbe-plus.com` — מנהל המערכת מוחק את החשבון ואת המטלות |

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Email address** | כן | לא | Account management | חובה |
| Personal info → **Name** (שם תצוגה) | כן | לא | App functionality, Account management | חובה |
| App activity → **Other user-generated content** (מטלות, הערות) | כן | לא | App functionality | חובה |
| App info and performance → **Diagnostics** (יומן אירועים) | כן | לא | App functionality | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes.
- מפתחות API ואסימוני גיטהאב שהמשתמש מזין — נשמרים מוצפנים, ולא חוזרים לעולם מהשרת.
- הטקסט של מטלה עובר לספק הבינה המלאכותית **שהמשתמש עצמו חיבר**, בחשבון שלו — כספק שירות.

### 4. בדיקה סגורה (חובה בחשבון פרטי)

כמו במתכונים (`recipes-app/android/STORE.md`, פרק ב.4): **12 בודקים, 14 יום ברצף**, ואז
**Apply for production**.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate**, ולהוסיף ל-`android/play-signing-sha256.txt` — הבנייה הבאה
מעדכנת את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.
