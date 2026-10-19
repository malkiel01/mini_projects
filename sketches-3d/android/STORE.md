# שרטוטים בתלת מימד בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`).
**כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים
משהו כאן, בתיקייה `android/`. הועתקה מ-`recipes-app/android/` (platform/docs/rollout.md, צעדים 5–6).

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.sketches3d` |
| כתובת | `https://mbe-plus.com/mini_projects/sketches-3d/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים |
| תיקיית החנות | Release קבוע `sketches-3d-store`: `sketches-3d.apk`, `sketches-3d.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/sketches-3d-store` |
| בנייה | `.github/workflows/sketches-3d-twa.yml` — רצה לבד בכל שינוי ב-`android/`. AAB לחנות ו-APK לבדיקה |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`; מפתח ההעלאה מוצפן ב-`keystore/upload.jks.enc` (נוצר בבנייה הראשונה). ⚠️ לא למחוק אותו ולא לשנות את הסיסמה אחרי ההעלאה הראשונה לחנות |
| אישור האתר | `assetlinks.json` מתיקיית החנות — הפריסה (`deploy.yml`) מאחדת אותו לשורש הדומיין, `https://mbe-plus.com/.well-known/assetlinks.json` |

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `שרטוטים 3D` · שפת ברירת מחדל: **עברית** · App · **Free** → לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
שרטוטים 3D — מודלים בתלת מימד
```

**תיאור קצר (עד 80):**
```
לשרטט ולצפות במודלים תלת־ממדיים — בטלפון, וגם בלי רשת.
```

**תיאור מלא:**
```
שרטוטים בתלת מימד — כלי לשרטוט ולצפייה במודלים תלת־ממדיים, מתוך "הכלים הדיגיטליים".

📐 מה יש היום
• תצוגה תלת־ממדית חיה של מודל, בפרספקטיבה
• עובד במלואו גם בלי רשת — הכול נשמר במכשיר בפתיחה הראשונה
• עברית מלאה, מימין לשמאל

🛠️ בדרך
• שרטוט מודלים בתוך הכלי
• ייבוא קבצים (STL / OBJ / GLTF)

🔒 פרטיות
• הכלי רץ במכשיר שלך — שרטוטים וקבצים לא נשלחים לשרת
• בלי פרסומות ובלי כלי מעקב
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-home.png`, `2-angle.png`, `3-side.png` |

הצילומים — מהאפליקציה עצמה (Playwright, רוחב 390px, נתוני הדגמה: זווית קבועה של המודל). כשהכלי
יקבל תכן לפי האפיון — לצלם מחדש.

**קטגוריה:** Art & Design · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/sketches-3d/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/sketches-3d/privacy.html` (ציבורי, בלי התחברות) |
| **App access** | כל האפליקציה דורשת כניסה דרך "הכלים הדיגיטליים" → לתת לגוגל משתמש בדיקה בפלטפורמה, עם הרשאה לכלי |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים, אין תוכן משתמשים משותף |
| **Target audience** | **18 ומעלה** |
| **News / Government / Financial / Health** | No / No / None / No |
| **Data safety** | הכלי לא אוסף מידע. הכניסה (שם ודוא"ל) — של הפלטפורמה: Personal info → Email address, Name — נאסף, לא משותף, Account management, חובה. Encrypted in transit: Yes |
| **Account deletion** | בקשה ל-`support@mbe-plus.com` (החשבון הוא של הפלטפורמה) |

### 4. בדיקה סגורה (חובה בחשבון פרטי)

כמו במתכונים (`recipes-app/android/STORE.md`, פרק 4): Closed testing, **12 בודקים, 14 יום ברצף**, ואז
Apply for production.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל), ולהוסיף ל-`android/play-signing-sha256.txt`. הבנייה הבאה
מעדכנת את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.
