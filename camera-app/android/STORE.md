# אפליקציית המצלמות בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`). התבנית —
`recipes-app/android/`, מועתקת ולא משותפת. **כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה
בחנות. גרסה חדשה לחנות — רק כשמשנים משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.camera` |
| כתובת | `https://mbe-plus.com/mini_projects/camera-app/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים |
| תיקיית החנות | Release קבוע `camera-app-store`: `camera-app.apk`, `camera-app.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/camera-app-store` |
| בנייה | `.github/workflows/camera-app-twa.yml` — רצה לבד בכל שינוי ב-`camera-app/android/` |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`. המפתח נוצר בבנייה הראשונה ונשמר מוצפן: `keystore/upload.jks.enc` |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לבד לשורש הדומיין (`platform/tools/assetlinks.php`) |
| תמונות | `node camera-app/tools/store-assets.mjs` — סמלים, באנר וצילומי מסך עם נתוני הדגמה |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `מצלמות — המצלמות שלי בטלפון` · שפת ברירת מחדל: **עברית** · App · **Free**.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
מצלמות — המצלמות שלי בטלפון
```

**תיאור קצר (עד 80):**
```
שידור חי, הקלטות וציר זמן יומי מכל מצלמות ה-IP שלך — במקום אחד.
```

**תיאור מלא:**
```
כל מצלמות ה-IP של הבית או העסק — במקום אחד, בטלפון.

📷 המצלמות
• כל המצלמות במסך אחד, עם הצילום האחרון של כל אחת
• שידור חי ושליטה בכיוון (PTZ) — דרך גשר קטן ברשת הביתית
• הקלטה ידנית, רציפה, לפי לוח זמנים או רק כשמזוהה אדם

⏱️ ציר זמן
• כל יום על ציר אחד: מתי הייתה תנועה, מתי אדם, מתי רכב
• לחיצה על נקודה בציר — והקטע מתנגן
• נגן עם מהירויות, מעבר פריים-פריים, זום, צילום מסך וסימניות

📁 סדר
• גלריה עם סינון לפי מצלמה, סוג, נושא ותאריך
• כותרת, תיאור ותגיות לכל הקלטה
• תיקיות נושא — "שיפוץ", "משלוחים" — והקלטה יכולה להיות בכמה
• כוכב ונעילה: מה שחשוב לא נמחק במדיניות השמירה

🔒 שלכם
• המצלמה מעלה לשרת שלכם, בלי ענן של יצרן
• משתמשים בשלושה תפקידים: מנהל, מפעיל וצופה
• בלי פרסומות
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-cameras.png` … `5-events.png` |

"הצילומים" שבתוך צילומי המסך הם איורים, לא צילומי מצלמה אמיתיים. מומלץ להחליף בצילומי מסך
אמיתיים מהטלפון — בלי פנים של אנשים ובלי מספרי רכב.

**קטגוריה:** Tools · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/camera-app/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/camera-app/privacy.html` |
| **App access** | כל האפליקציה דורשת התחברות → לתת חשבון בדיקה ב"כלים הדיגיטליים" עם הרשאה לאפליקציה, ומצלמת הדגמה |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; אין תוכן ציבורי ואין תקשורת בין משתמשים |
| **Target audience** | **18 ומעלה** |
| **News app / Government app** | No |
| **Financial features** | None |
| **Health** | No |
| **Data safety** | ראה הטבלה הבאה |
| **Account deletion** | במייל ל-`support@mbe-plus.com` — מפורט ב-`privacy.html` |

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Email address** | כן | לא | Account management | חובה |
| Personal info → **Name** (שם תצוגה) | כן | לא | App functionality, Account management | חובה |
| Photos and videos → **Photos**, **Videos** (מהמצלמות שלכם) | כן | לא | App functionality | חובה — זו האפליקציה |
| App activity → **Other user-generated content** (כותרות, תגיות, נושאים) | כן | לא | App functionality | רשות |
| App info and performance → **Diagnostics** (יומן אירועים) | כן | לא | App functionality | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes.

### 4. בדיקה סגורה (חובה בחשבון פרטי)

כמו במתכונים (`recipes-app/android/STORE.md`, ב.4): **Closed testing**, 12 בודקים לפחות, 14 יום
ברצף, ואז **Apply for production**.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → את **SHA-256 certificate fingerprint** של
**App signing key certificate** (של גוגל) להוסיף ל-`android/play-signing-sha256.txt`. הבנייה הבאה
מעדכנת את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** (עיצוב, תכונה, תיקון) — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים, הרשאות) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
