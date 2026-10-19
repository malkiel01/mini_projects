# תבניות מסמכים בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`).
**כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים
משהו כאן, בתיקייה `android/`. הועתק מ-`recipes-app/android/` (התבנית של הפלטפורמה).

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.doctemplates` |
| כתובת | `https://mbe-plus.com/mini_projects/document-templates/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`) — גם קישורי המילוי (`fill.html`) שנשלחים למשתתפים |
| תיקיית החנות | Release קבוע `document-templates-store`: `document-templates.apk`, `document-templates.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/document-templates-store` |
| בנייה | `.github/workflows/document-templates-twa.yml` — AAB לחנות ו-APK לבדיקה |
| חתימה | מפתח העלאה משלה, מוצפן ב-`keystore/upload.jks.enc` בסיסמה המשותפת `ANDROID_KEYSTORE_PASSWORD` |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לבד לקובץ המשותף `https://mbe-plus.com/.well-known/assetlinks.json` (`platform/README.md`, "התקנה והורדה") |
| חשבון מפתחים | פרטי — לפני פרסום: בדיקה סגורה עם **12 בודקים, 14 יום ברצף** |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

---

## א. לפני הגשה לחנות — מה עוד חסר באפליקציה

גוגל דורשת את שני אלה לאפליקציה שיוצרים בה חשבון. הבנייה, ה-APK והתיקייה עובדים גם בלעדיהם —
רק ההגשה לחנות מחכה להם:

1. **מדיניות פרטיות** — דף ציבורי (למשל `privacy.html`, ולהוסיף ל-`public` ב-`app.json`).
2. **מחיקת חשבון** — מתוך האפליקציה, וגם דף באתר שמסביר איך מבקשים מחיקה.

## ב. Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `תבניות מסמכים` · שפת ברירת מחדל: **עברית** · App · **Free** → לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
תבניות מסמכים — מילוי וחתימה
```

**תיאור קצר (עד 80):**
```
תבנית אחת — מסמכים בלי סוף: ממלאים, שולחים לחתימה, ושומרים הכול במקום אחד.
```

**תיאור מלא:**
```
יוצרים תבנית פעם אחת — הסכם, הצעת מחיר, טופס הרשמה — ומנפיקים ממנה מסמכים כמה שרוצים.

📝 תבניות
• מעלים PDF או Word, או כותבים את המסמך ישירות באפליקציה
• שדות: טקסט, תאריך, מספר, תיבת סימון, רשימה, חתימה, טבלה עם שורות
• חישובים אוטומטיים: סכומים, מע"מ, תאריכי תשלום
• תנאים: שדה או פסקה שמופיעים רק כשצריך
• גרסאות — מסמך שכבר הונפק נשאר על הגרסה שלו

🗂️ מסמכים
• ממלאים לבד, או שולחים קישור למילוי ולחתימה — לאדם אחד או לכמה, כל אחד בתורו
• הממלא לא צריך חשבון: קישור, קוד אימות, מילוי וחתימה
• מספור אוטומטי, תגיות, חיפוש וארכיון
• הנפקה מאקסל — עשרות מסמכים בבת אחת
• הורדה כ-PDF או Word, והדפסה

🔒 פרטיות
• רק אתם רואים את התבניות והמסמכים שלכם — אלא אם שיתפתם
• יומן מלא לכל מסמך: מי מילא, מתי ומה
• שום דבר שהשתמשו בו לא נמחק — רק מועבר לארכיון

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-templates.png` … `5-lease.png` |

הצילומים — של האפליקציה עצמה, ברוחב 390px, עם נתוני הדגמה (משתמשת "דנה לוי", שלוש תבניות וחמישה מסמכים).

**קטגוריה:** Business · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/document-templates/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | הדף מסעיף א (עדיין חסר) |
| **App access** | כל האפליקציה דורשת התחברות → לתת משתמש בדיקה (ראה למטה) |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; **שיתוף בין משתמשים** — Yes (שיתוף תבניות ומסמכים, קישורי מילוי) |
| **Target audience** | **18 ומעלה** |
| **News app** | No |
| **Government app** | No |
| **Financial features** | None |
| **Health** | No |
| **Data safety** | ראה הטבלה הבאה |
| **Account deletion** | סעיף א (עדיין חסר) |

**משתמש בדיקה לגוגל (App access):** ליצור חשבון, למשל `google.review`, ולאשר אותו מלשונית "פיתוח".
לכתוב בשדה ההוראות: "התחברות במייל ובסיסמה. '+ תבנית חדשה' → לכתוב את המסמך, ואז '+ מסמך'."

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Email address** | כן | לא | Account management | חובה |
| Personal info → **Name** (שם תצוגה) | כן | לא | App functionality, Account management | חובה |
| Personal info → **Phone number** (אנשי קשר, קוד אימות לממלא) | כן | לא | App functionality | רשות |
| Files and docs → **Files and docs** (תבניות, מסמכים, קבצים מצורפים) | כן | לא | App functionality | חובה |
| App activity → **Other user-generated content** (ערכים, חתימות) | כן | לא | App functionality | חובה |
| App info and performance → **Diagnostics** (יומן בקשות) | כן | לא | App functionality | חובה |

- **Encrypted in transit:** Yes (HTTPS). התוכן נשמר גם מוצפן בשרת.
- **Users can request deletion:** Yes — אחרי שמחיקת החשבון תיבנה (סעיף א).

### 4. בדיקה סגורה (חובה בחשבון פרטי)

1. **Test and release → Testing → Closed testing** → **Create track**.
2. **Testers:** רשימת אימיילים (Gmail) של **12 אנשים לפחות**.
3. **Create release** → להעלות את `document-templates.aab` → **Save** → **Review** → **Start rollout**.
4. **Countries:** Israel.
5. לשלוח לבודקים את **קישור ההצטרפות** (Join on the web).
6. **14 יום ברצף** עם 12 בודקים לפחות. אחרי זה: **Apply for production**, ואז פרסום.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל), ולהוסיף ל-`android/play-signing-sha256.txt`.
הבנייה הבאה מעדכנת את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## ג. גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
  `versionCode` עולה לבד בכל בנייה.
