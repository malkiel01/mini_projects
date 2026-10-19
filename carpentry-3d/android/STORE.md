# נגרות בתלת מימד בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity) — העתק של התבנית של המתכונים (`recipes-app/android/`).
היא פותחת את האתר ב-Chrome, במסך מלא, בלי שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים
וחתימה (`app/build.gradle.kts`). **כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות.
גרסה חדשה לחנות — רק כשמשנים משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.carpentry` |
| כתובת | `https://mbe-plus.com/mini_projects/carpentry-3d/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים |
| תיקיית החנות | Release קבוע `carpentry-3d-store`: `carpentry-3d.apk`, `carpentry-3d.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/carpentry-3d-store` |
| בנייה | `.github/workflows/carpentry-3d-twa.yml` — רצה לבד בכל שינוי ב-`android/` שמתמזג ל-main |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`. מפתח ההעלאה נוצר בבנייה הראשונה ונשמר מוצפן: `keystore/upload.jks.enc` |
| אישור האתר | `assetlinks.json` — הבנייה כותבת `carpentry-3d/.well-known/assetlinks.json`, והפריסה מאחדת אותו לקובץ המשותף `https://mbe-plus.com/.well-known/assetlinks.json` |
| חשבון מפתחים | פרטי — לפני פרסום: בדיקה סגורה עם **12 בודקים, 14 יום ברצף** |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `נגרות בתלת מימד` · שפת ברירת מחדל: **עברית** · App · **Free** → לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
נגרות בתלת מימד
```

**תיאור קצר (עד 80):**
```
מידות וחומרים נכנסים — מודל תלת־ממדי, רשימת חיתוך, שרטוטים ומחיר יוצאים.
```

**תיאור מלא:**
```
אפליקציה לנגרים: מתכננים רהיט, רואים אותו בתלת מימד, ומקבלים כל מה שצריך כדי לבנות ולתמחר.

🪚 שבעה סוגי מוצרים
• ספרייה, ארון בגדים, שידת מגירות, מטבח (קו ישר או בצורת ר׳), שולחן, מיטה וחיפוי קיר
• ממלאים מידות, חלוקה, חומרים ופרזול — המודל מתעדכן מיד

🧊 תלת מימד
• סיבוב, זום ומבטים קבועים, וקוביית ניווט
• דלתות ומגירות שנפתחות, חזיתות שקופות, ומיקוד ברכיב אחד
• הדמיית חומרים: גוני עץ, צבע בתנור, זכוכית — או תמונה אמיתית של החומר

📋 פלט ייצור
• רשימת חיתוך עם קנטים, זכוכית ופרזול — וייצוא ל-CSV
• שרטוטים: חזית, צד ומבט־על עם מידות
• הערכת מחיר לפי החומרים והתעריפים שלך
• סידור חיתוך על הלוחות ואחוז פחת
• הדפסה ו-PDF

👥 לעבודה
• פרויקטים ולקוחות שנשמרים בשרת — מכל מכשיר
• קישור צפייה ללקוח: רק הדגם, בלי מחירים
• כמה נגרים במערכת אחת, עם תפקידים

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-home.png` … `7-price.png` |

הצילומים — מהאפליקציה עצמה (Playwright, רוחב 390, נתוני הדגמה: "יוסי הנגר", ספרייה, מטבח).

**קטגוריה:** Productivity · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/carpentry-3d/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/carpentry-3d/privacy.html` |
| **App access** | כל האפליקציה דורשת התחברות → לתת משתמש בדיקה (ראה למטה) |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; אין תוכן שמשתמשים משתפים עם הציבור |
| **Target audience** | **18 ומעלה** — כלי מקצועי |
| **News / Government / Financial / Health** | No / No / None / No |
| **Data safety** | ראה הטבלה הבאה |
| **Account deletion** | בפנייה למנהל המערכת או ב-`support@mbe-plus.com` — כתוב ב-`privacy.html` |

**משתמש בדיקה לגוגל (App access):** לפתוח לו חשבון בפלטפורמה עם הרשאה לאפליקציה, ולכתוב בשדה
ההוראות: "התחברות עם המייל והסיסמה. בוחרים סוג מוצר (למשל ספרייה) → יצירת הפרויקט → תלת מימד."

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Email address** | כן | לא | Account management | חובה |
| Personal info → **Name** | כן | לא | App functionality, Account management | חובה |
| Personal info → **Phone number**, **Address** (של לקוחות, מה שהנגר מזין) | כן | לא | App functionality | רשות |
| Photos and videos → **Photos** (תמונות חומרים) | כן | לא | App functionality | רשות |
| App activity → **Other user-generated content** (פרויקטים) | כן | לא | App functionality | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes.

### 4. בדיקה סגורה (חובה בחשבון פרטי)

1. **Test and release → Testing → Closed testing** → **Create track**.
2. **Testers:** רשימת אימיילים של **12 אנשים לפחות** עם אנדרואיד.
3. **Create release** → להעלות את `carpentry-3d.aab` מתיקיית החנות → **Save** → **Review** → **Start rollout**.
4. **14 יום ברצף** עם 12 בודקים לפחות. אחרי זה: **Apply for production**, ואז פרסום.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל) ולהוסיף ל-`android/play-signing-sha256.txt`.
הבנייה הבאה תעדכן את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
  `versionCode` עולה לבד בכל בנייה.
