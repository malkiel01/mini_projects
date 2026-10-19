# ניחוחות (תפריט הקייטרינג) בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`), שהועתקו
מאפליקציית המתכונים (`recipes-app/android/`). **כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה
חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.catering` |
| כתובת | `https://mbe-plus.com/mini_projects/catering-menu/` — התפריט הציבורי. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים. ממשק המנהל (`admin/`) נפתח באותה אפליקציה |
| תיקיית החנות | Release קבוע `catering-menu-store`: `catering-menu.apk`, `catering-menu.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/catering-menu-store` |
| בנייה | `.github/workflows/catering-menu-twa.yml` — AAB לחנות ו-APK לבדיקה. רצה לבד בכל שינוי ב-`android/` שמתמזג ל-`main` |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`. מפתח ההעלאה נוצר בבנייה הראשונה ונשמר מוצפן ב-`keystore/upload.jks.enc` |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לבד לשורש הדומיין (`platform/tools/assetlinks.php`) |
| חשבון מפתחים | פרטי — לפני פרסום: בדיקה סגורה עם **12 בודקים, 14 יום ברצף** |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `ניחוחות — קייטרינג בטעם של בית` · שפת ברירת מחדל: **עברית** · App · **Free**
→ לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
ניחוחות — קייטרינג בטעם של בית
```

**תיאור קצר (עד 80):**
```
בוחרים מנות מהתפריט בטלפון ושולחים הזמנת קייטרינג — בלי טלפונים ובלי פתקים.
```

**תיאור מלא:**
```
ניחוחות — קייטרינג בטעם של בית. כל התפריט בטלפון, וההזמנה יוצאת בכמה הקשות.

🍽️ התפריט
• סלטים, מנה ראשונה, עיקריות, תוספות וקינוחים — מסודרים לפי קטגוריות
• מסמנים מנה בהקשה, ורואים כמה נבחרו בכל קטגוריה
• מנות בתוספת תשלום מסומנות בבירור
• הבחירה נשמרת בטלפון עד השליחה — גם אם סגרתם את האפליקציה

📝 ההזמנה
• סיכום ברור של כל המנות שנבחרו, עם אפשרות להסיר
• תאריך ושעת האירוע, מספר סועדים והערות (אלרגיות, כתובת למשלוח)
• אישור במייל כשההזמנה מאושרת
• אפשר לשלוח את ההזמנה גם בוואטסאפ

👨‍🍳 לבעל העסק
• כל ההזמנות במקום אחד, לפי לקוח — עם אישור או סירוב
• יומן אירועים עם תאריכים עבריים, חגים וזמני שבת
• מעקב "מוכן / לא מוכן" לכל מנה, והתראה לטלפון על הזמנה חדשה

בלי פרסומות. בלי הרשמה ללקוחות.
```

**גרפיקה** (בתיקייה `store/`, ובתוך `store-listing.zip`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-menu.png` … `5-calendar.png` |

הצילומים — מהאפליקציה עצמה ברוחב טלפון (390px), עם התפריט האמיתי והזמנות הדגמה (שמות
וטלפונים בדויים). 1–3: הלקוח (תפריט, בחירה, סיכום הזמנה); 4–5: ממשק המנהל (הזמנות, יומן).

**קטגוריה:** Food & Drink · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/catering-menu/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/catering-menu/privacy.html` |
| **App access** | התפריט וההזמנה — בלי התחברות. ממשק המנהל (`admin/`) — בסיסמה, לבעל העסק בלבד: "All functionality is available without special access" עם הערה שממשק הניהול אינו חלק מהאפליקציה ללקוחות |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; אין תוכן שמשתמשים משתפים זה עם זה; אין רכישות |
| **Target audience** | **18 ומעלה** (פשוט מכולם: בלי דרישות של מדיניות משפחות) |
| **News / Government / Financial / Health** | No / No / None / No |
| **Data safety** | ראה הטבלה הבאה |
| **Account deletion** | אין חשבונות ללקוחות. מחיקת הזמנה — בפנייה בטלפון או במייל (כתוב ב-privacy.html) |

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Name** | כן | לא | App functionality (ההזמנה) | חובה |
| Personal info → **Phone number** | כן | לא | App functionality (חזרה ללקוח) | חובה |
| Personal info → **Email address** | כן | לא | App functionality (אישור ההזמנה) | חובה |
| App activity → **Other user-generated content** (המנות, ההערות) | כן | לא | App functionality | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes — בפנייה לבעל העסק.

### 4. בדיקה סגורה (חובה בחשבון פרטי)

1. **Test and release → Testing → Closed testing** → **Create track**.
2. **Testers:** רשימת אימיילים (Gmail) של **12 אנשים לפחות**.
3. **Create release** → להעלות את `catering-menu.aab` מתיקיית החנות → **Save** → **Review** → **Start rollout**.
4. **Countries:** Israel. לשלוח לבודקים את **קישור ההצטרפות**.
5. **14 יום ברצף** — ואז **Apply for production**.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל), ולהוסיף ל-`android/play-signing-sha256.txt`. הבנייה
הבאה תעדכן את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** (תפריט, עיצוב, תיקון) — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
  `versionCode` עולה לבד בכל בנייה.
