# איחוד PDF ותמונות בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את הכלי ב-Chrome, במסך מלא, בלי שורת
כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`), שהועתקו מהתבנית
`recipes-app/android/`. **כל עדכון בכלי מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה לחנות —
רק כשמשנים משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.pdfmerger` |
| כתובת | `https://mbe-plus.com/mini_projects/pdf-merger/` — מאחורי השער של הפלטפורמה. הכניסה בתוך האפליקציה — דרך השער, כמו בדפדפן (Chrome משתף את העוגייה). האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`) |
| בלי רשת | עובדת: ה-service worker (`../service-worker.js`) שומר את הדף, הקוד והספריות. הקבצים של המשתמש לא יוצאים מהמכשיר |
| תיקיית החנות | Release קבוע `pdf-merger-store`: `pdf-merger.apk`, `pdf-merger.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/pdf-merger-store` |
| בנייה | `.github/workflows/pdf-merger-twa.yml` — AAB לחנות ו-APK לבדיקה. רצה לבד בכל שינוי ב-`android/` |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`. מפתח ההעלאה נוצר בבנייה הראשונה ונשמר מוצפן: `keystore/upload.jks.enc` — **לא למחוק** |
| אישור האתר | `assetlinks.json` מתיקיית החנות — הפריסה מאחדת אותו לבד ל-`https://mbe-plus.com/.well-known/assetlinks.json` (`platform/tools/assetlinks.php`) |

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `איחוד PDF ותמונות` · שפת ברירת מחדל: **עברית** · App · **Free** → לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
איחוד PDF ותמונות
```

**תיאור קצר (עד 80):**
```
כמה קבצי PDF ותמונות — קובץ PDF אחד. בטלפון, בלי להעלות לשום שרת.
```

**תיאור מלא:**
```
מאחדים קבצי PDF ותמונות לקובץ PDF אחד — מהר, בטלפון, ובלי שהקבצים יוצאים מהמכשיר.

📑 איחוד
• קבצי PDF (גם של כמה עמודים) ותמונות JPG, PNG ו-WEBP — באותו קובץ
• סדר הקבצים בגרירה
• תצוגה מקדימה של כל הדפים לפני האיחוד
• סיבוב דף בודד

⚙️ הגדרות
• גודל דף: לפי המקור, A4 או Letter
• כיוון: אוטומטי, לאורך או לרוחב
• איכות התמונות — לקובץ קטן יותר כשצריך לשלוח במייל
• פיצול PDF לדפים בודדים

🔒 פרטיות
• הכול רץ במכשיר: הקבצים לא נשלחים לשום שרת
• עובד גם בלי חיבור לאינטרנט

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-start.png` … `5-done.png` |

הצילומים — מהכלי עצמו ברוחב 390px, עם מסמכי הדגמה (חוזה, קבלות) שנוצרו לצורך הצילום.

**קטגוריה:** Productivity · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/pdf-merger/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/pdf-merger/privacy.html` |
| **App access** | כל האפליקציה דורשת כניסה (חשבון בפלטפורמה) → לתת לגוגל משתמש בדיקה עם הרשאה לכלי |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים, אין תוכן משתמשים משותף, אין תקשורת בין משתמשים |
| **Target audience** | **18 ומעלה** |
| **News / Government / Financial / Health** | No |
| **Data safety** | ראה הטבלה הבאה |
| **Account deletion** | בבקשה ל-`support@mbe-plus.com` (החשבון הוא של הפלטפורמה) |

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Email address** | כן | לא | Account management (הכניסה לפלטפורמה) | חובה |
| Personal info → **Name** | כן | לא | Account management | חובה |
| Files and docs | **לא** — הקבצים מעובדים במכשיר ולא נשלחים | — | — | — |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes.

### 4. בדיקה סגורה (חובה בחשבון פרטי)

כמו במתכונים (`recipes-app/android/STORE.md`, ב.4): Closed testing, **12 בודקים, 14 יום ברצף**, ואז
**Apply for production**.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל) ל-`android/play-signing-sha256.txt`. הבנייה הבאה מוסיפה
אותו ל-`assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.
