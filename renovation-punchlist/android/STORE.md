# השלמות לשיפוץ בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity), מועתקת מהמתכונים (`recipes-app/android/`): היא פותחת
את האתר ב-Chrome, במסך מלא, בלי שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה
(`app/build.gradle.kts`). **כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.punchlist` |
| כתובת | `https://mbe-plus.com/mini_projects/renovation-punchlist/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`) |
| תיקיית החנות | Release קבוע `renovation-punchlist-store`: `renovation-punchlist.apk`, `renovation-punchlist.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/renovation-punchlist-store` |
| בנייה | `.github/workflows/renovation-punchlist-twa.yml` — רצה לבד בכל שינוי ב-`android/` מ-main |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`. מפתח ההעלאה נוצר בבנייה הראשונה ונשמר מוצפן ב-`keystore/upload.jks.enc` — **לא למחוק אותו ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות |
| אישור האתר | הפריסה מאחדת את `assetlinks.json` מתיקיית החנות לשורש הדומיין (`platform/tools/assetlinks.php`) |

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `השלמות לשיפוץ` · שפת ברירת מחדל: **עברית** · App · **Free**.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
השלמות לשיפוץ — פינישים
```

**תיאור קצר (עד 80):**
```
כל מה שנשאר לסיים בשיפוץ: צילום, תיאור, אחראי ומעקב — גם בלי אינטרנט.
```

**תיאור מלא:**
```
סוף שיפוץ זה רשימה ארוכה של "עוד דבר קטן". השלמות לשיפוץ מרכזת את כולם במקום אחד —
ומראה מה כבר נסגר.

📷 תיעוד בסיור
• צילום ישיר מהמצלמה, סרטון או בחירה מהגלריה — כמה שצריך לכל ליקוי
• תיאור קצר, ותיאור מפורט: חומר, מידה, הערות
• תמונות גדולות מכווצות לבד, כדי לא למלא את הטלפון

👤 מי ומתי
• על מי המטלה מוטלת — אלומיניום, ריצוף, חשמל, צבע
• מיקום בדירה, עדיפות ותאריך יעד — מה שעבר זמנו מסומן באדום

✓ מעקב
• פתוח / בתהליך / בוצע — סימון בלחיצה אחת
• פס התקדמות: כמה נשאר עד הסוף
• סינון לפי אחראי, מיקום וסטטוס, וחיפוש חופשי — להראות לכל בעל מקצוע רק את שלו

📶 עובד גם בלי אינטרנט
• הכול נשמר בטלפון. סנכרון בין כמה אנשים — רק אם רוצים
• גיבוי לקובץ אחד והעברה למכשיר אחר

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`, נוצרה מהאפליקציה עם נתוני הדגמה — רוחב 390px):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-list.png` … `5-settings.png` |

התמונות בתוך הצילומים הן איורים (לא צילומים של אחרים). מומלץ להחליף בצילומי מסך אמיתיים מאתר.

**קטגוריה:** House & Home · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/renovation-punchlist/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/renovation-punchlist/privacy.html` |
| **App access** | הכניסה דרך הפלטפורמה (הכלים הטכנולוגיים) → לתת משתמש בדיקה עם הרשאה לאפליקציה |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; אין תוכן ציבורי בין משתמשים — רק רשימה פרטית, ושיתוף אופציונלי באסימון |
| **Target audience** | **18 ומעלה** |
| **News / Government / Financial / Health** | No / No / None / No |
| **Account deletion** | אין חשבון באפליקציה עצמה — החשבון הוא של הפלטפורמה. הנתונים בטלפון: הגדרות → "מחיקת כל הנתונים במכשיר" |

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Photos and videos → **Photos**, **Videos** | רק בסנכרון | לא | App functionality | רשות — בלי סנכרון נשאר בטלפון בלבד |
| App activity → **Other user-generated content** (השלמות, תיאורים, שמות אחראים) | רק בסנכרון | לא | App functionality | רשות |

- נתונים שנשמרים רק בטלפון אינם "נאספים" לפי הגדרות גוגל.
- **Encrypted in transit:** Yes (HTTPS). **Users can request deletion:** Yes.

### 4. בדיקה סגורה (חובה בחשבון פרטי)

כמו במתכונים (`recipes-app/android/STORE.md`, ב.4): **12 בודקים, 14 יום ברצף**, ואז Apply for production.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → את **SHA-256** של **App signing key certificate**
להוסיף ל-`android/play-signing-sha256.txt`. הבנייה הבאה מעדכנת את `assetlinks.json`. בלי זה, מה
שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש. `versionCode` עולה לבד.
