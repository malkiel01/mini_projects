# הטמעת נתונים בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`).
**כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים
משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.dataembed` |
| כתובת | `https://mbe-plus.com/mini_projects/data-embed-for-clients/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים |
| תיקיית החנות | Release קבוע `data-embed-for-clients-store`: `data-embed-for-clients.apk`, `data-embed-for-clients.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` |
| בנייה | `.github/workflows/data-embed-for-clients-twa.yml` — AAB לחנות ו-APK לבדיקה |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`; המפתח מוצפן בריפו — `keystore/upload.jks.enc` |
| אישור האתר | `assetlinks.json` מתיקיית החנות — הפריסה מאחדת אותו לשורש הדומיין (`platform/tools/assetlinks.php`) |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `הטמעת נתונים — PDF ללקוחות` · שפת ברירת מחדל: **עברית** · App · **Free**.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
הטמעת נתונים — PDF ללקוחות
```

**תיאור קצר (עד 80):**
```
מסמך אחד, PDF מותאם אישית לכל לקוח: שם, מספר תיק, תמונה וחתימה.
```

**תיאור מלא:**
```
מכתב, אישור או טופס — מכינים פעם אחת, ומפיקים קובץ PDF אישי לכל לקוח.

📄 התבנית
• רקע מתמונה או מקובץ PDF (גם כמה עמודים)
• שדות טקסט בגרירה: מיקום, גודל, צבע, סיבוב ופונט משלך
• שדה תמונה (לוגו, חותמת — גם PNG שקוף), ציור חופשי ושדה חתימה

👥 הלקוחות
• רשימת לקוחות — ערך שונה לכל אחד בכל שדה, או ערך קבוע לכולם
• הורדת כל הקבצים יחד ב-ZIP

✍️ חתימה מרחוק
• שולחים ללקוח קישור — הוא חותם מהטלפון, והמסמך החתום חוזר אליך
• מעקב אחרי מה נחתם

🔗 API
• שמירת תבנית והפקת PDF ממערכת אחרת

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1919) | `store/screenshots/1-settings.png` … `4-api.png` |

הצילומים — מהאפליקציה עצמה ברוחב 390px, עם נתוני הדגמה (מכתב של משרד בדוי ושמות בדויים).

**קטגוריה:** Business · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/data-embed-for-clients/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | **חסר** — לאפליקציה אין עדיין דף מדיניות פרטיות. חובה לפני הגשה לחנות |
| **App access** | הכניסה דרך הכלים הדיגיטליים → לתת לגוגל משתמש בדיקה עם הרשאה לאפליקציה |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; אין תקשורת בין משתמשים |
| **Target audience** | **18 ומעלה** |
| **News / Government / Financial / Health** | No |

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Name** (שמות לקוחות שמוזנים לתבנית) | כן | לא | App functionality | רשות |
| Files and docs (תבניות, פונטים, מסמכים חתומים) | כן | לא | App functionality | רשות |
| Photos (רקע, לוגו, חתימה) | כן | לא | App functionality | רשות |

- **Encrypted in transit:** Yes (HTTPS).

### 4. בדיקה סגורה (חובה בחשבון פרטי)

כמו במתכונים (`recipes-app/android/STORE.md`, סעיף 4): Closed testing, **12 בודקים, 14 יום ברצף**,
ואז Apply for production.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → את **SHA-256** של **App signing key certificate**
להוסיף ל-`android/play-signing-sha256.txt`. הבנייה הבאה מעדכנת את `assetlinks.json`. בלי זה, מה
שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
