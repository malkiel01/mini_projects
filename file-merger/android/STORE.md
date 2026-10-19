# איחוד ופיצול קבצים בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`), מועתקים
מהתבנית של המתכונים (`recipes-app/android/`). **כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה
חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.filemerger` |
| כתובת | `https://mbe-plus.com/mini_projects/file-merger/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`) |
| תיקיית החנות | Release קבוע `file-merger-store`: `file-merger.apk`, `file-merger.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/file-merger-store` |
| בנייה | `.github/workflows/file-merger-twa.yml` — AAB לחנות ו-APK לבדיקה |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`; מפתח ההעלאה נוצר בבנייה הראשונה ונשמר מוצפן ב-`keystore/upload.jks.enc` |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לקובץ המשותף `https://mbe-plus.com/.well-known/assetlinks.json` |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `איחוד ופיצול קבצים — PDF` · שפת ברירת מחדל: **עברית** · App · **Free**.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
איחוד ופיצול קבצים — PDF
```

**תיאור קצר (עד 80):**
```
תמונות וקבצי PDF לקובץ PDF אחד, ו-PDF לתמונות. הכול במכשיר, בלי העלאה.
```

**תיאור מלא:**
```
מאחדים תמונות וקבצי PDF לקובץ PDF אחד — ומפצלים PDF לתמונות — בלי שהקבצים יוצאים מהטלפון.

📎 איחוד ל-PDF
• תמונות (JPG, PNG, WEBP ועוד) וקבצי PDF באותה רשימה
• סדר חופשי: גרירה, חצים או מיון לפי שם
• עמודים מקבצי PDF מועתקים כמו שהם
• לתמונות: גודל עמוד (לפי התמונה, A4 ועוד), כיוון ושוליים
• שם לקובץ שנוצר

🧩 פיצול ל-ZIP
• כל עמוד ב-PDF הופך לתמונה — PNG, JPG או WEBP
• רזולוציה לבחירה, איכות דחיסה וטווח עמודים ("1-3, 7, 10-")
• כמה קבצים בבת אחת, ו-ZIP אחד בסוף

🔒 פרטיות
• כל העיבוד במכשיר — שום קובץ לא נשלח לשרת
• עובד גם בלי אינטרנט אחרי הפתיחה הראשונה
• בלי פרסומות, בלי מעקב
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-home.png` … `5-split.png` |

הצילומים — מהאפליקציה עצמה ברוחב 390px, עם קבצי הדגמה (תמונות ו-PDF שנוצרו לצילום, בלי תוכן אמיתי).

**קטגוריה:** Productivity · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/file-merger/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/file-merger/privacy.html` |
| **App access** | הכניסה דרך פלטפורמת הכלים → לתת לגוגל משתמש בדיקה בפלטפורמה עם הרשאה לאפליקציה |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים, אין תוכן משתמשים ואין שיתוף בין משתמשים |
| **Target audience** | **18 ומעלה** |
| **News / Government / Financial / Health** | No / No / None / No |
| **Data safety** | **No data collected, no data shared** — הקבצים מעובדים במכשיר ולא נשלחים. Encrypted in transit: Yes (HTTPS) |
| **Account deletion** | אין חשבון באפליקציה עצמה (הכניסה — של הפלטפורמה) |

### 4. בדיקה סגורה (חובה בחשבון פרטי)

כמו במתכונים (`recipes-app/android/STORE.md`, ב.4): Closed testing, **12 בודקים, 14 יום ברצף**, ואז
**Apply for production**.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → את **SHA-256** של **App signing key certificate**
להוסיף ל-`android/play-signing-sha256.txt`. הבנייה הבאה מעדכנת את `assetlinks.json`. בלי זה, מה
שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
  `versionCode` עולה לבד בכל בנייה.
