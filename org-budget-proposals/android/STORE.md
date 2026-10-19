# הצעות תקציב לארגונים בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`), מועתקים
מהמתכונים (`recipes-app/android/`). **כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה
בחנות. גרסה חדשה לחנות — רק כשמשנים משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.orgbudget` |
| כתובת | `https://mbe-plus.com/mini_projects/org-budget-proposals/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים |
| הכניסה | דרך השער, כמו בדפדפן (Chrome משתף את העוגייה) |
| תיקיית החנות | Release קבוע `org-budget-proposals-store`: `org-budget-proposals.apk`, `org-budget-proposals.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/org-budget-proposals-store` |
| בנייה | `.github/workflows/org-budget-proposals-twa.yml` — AAB לחנות ו-APK לבדיקה. רצה לבד בכל שינוי ב-`android/` |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD` (`platform/docs/rollout.md`, צעד 5). מפתח ההעלאה נוצר בבנייה הראשונה ונשמר מוצפן: `keystore/upload.jks.enc` |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לשורש הדומיין (`tools/assetlinks.php`), רשומה לכל חבילה |
| חשבון מפתחים | פרטי — לפני פרסום: בדיקה סגורה עם **12 בודקים, 14 יום ברצף** |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

---

## א. Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `הצעות תקציב לארגונים` · שפת ברירת מחדל: **עברית** · App · **Free**
→ לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
הצעות תקציב לארגונים
```

**תיאור קצר (עד 80):**
```
הצעת תקציב שנתית לארגון: קופות, הקדשות, ריכוז ונספח — ל-Word, PDF ואקסל.
```

**תיאור מלא:**
```
בונים את הצעת התקציב השנתית של הארגון — על בסיס השנה הקודמת, ובמבנה המסמך שאתם מגישים היום.

🏛️ ארגון וקופות
• כמה קופות לארגון: שוטף, הקדשות, קרנות ייעודיות — כל קופה מאוזנת בפני עצמה
• תת-ארגון עם קופות ומסמך הצעה משלו
• ספקים ולקוחות, קטלוג סעיפים, והיסטוריה של כל שינוי

📊 ההצעה
• שלוש עמודות: תקציב שנה קודמת · ניצול עד תאריך · תקציב השנה
• הצעה חדשה מהקודמת בלחיצה — הסכומים עוברים לעמודת "שנה קודמת"
• שורה חודשית ("13,500 לחודש, מחודש 7 +5%") מחושבת לבד
• העברות בין קופות — שני הצדדים תמיד שווים
• דף ריכוז שמסתכם לבד, ונספח שכירויות מתוך שורות ההכנסה

📄 הפלט
• Word לעריכה, PDF להגשה, ואקסל עם הנוסחאות
• שער, תכנית עבודה, דף לכל קופה ומספרי עמודים — מוכן להגשה

👥 עבודה משותפת
• בעלים, עורכים וצופים — וכל אחד יכול להעיר על שורה
• טיוטה ← הוגשה ← אושרה (נעולה)

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-budget.png` … `5-document.png` |

הצילומים — מהאפליקציה עצמה ברוחב 390px, עם **נתוני הדגמה** (ארגון בדוי, "עמותת קהילה לדוגמה").

**קטגוריה:** Business · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/org-budget-proposals/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | ⚠️ **עוד אין** — ראה "ג. מה חסר לפני פרסום" |
| **App access** | כל האפליקציה דורשת התחברות → לתת משתמש בדיקה (ראה למטה) |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; **שיתוף בין משתמשים** — Yes (חברים בארגון, הערות) |
| **Target audience** | **18 ומעלה** |
| **News app** | No |
| **Government app** | No |
| **Financial features** | None (תכנון תקציב, בלי תשלומים ובלי חשבון בנק מקוון) |
| **Health** | No |
| **Data safety** | ראה הטבלה הבאה |
| **Account deletion** | ⚠️ **עוד אין** — ראה "ג" |

**משתמש בדיקה לגוגל (App access):** משתמש בפלטפורמה עם הרשאה לאפליקציה (היא `restricted` — אישור ישיר
בממשק המנהל), וארגון הדגמה בתוכו. לכתוב בשדה ההוראות: "התחברות עם המייל והסיסמה. ארגון הדגמה
פתוח במסך הראשון."

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Email address** | כן | לא | Account management | חובה |
| Personal info → **Name** (שם תצוגה) | כן | לא | App functionality, Account management | חובה |
| Photos and videos → **Photos** (לוגו הארגון) | כן | לא | App functionality | רשות |
| App activity → **Other user-generated content** (הצעות, שורות, הערות) | כן | לא | App functionality | חובה |
| App info and performance → **Diagnostics** (יומן בקשות, שגיאות) | כן | לא | App functionality, Analytics | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes — לפני הפרסום צריך דרך לבקש (ראה "ג").

### 4. בדיקה סגורה (חובה בחשבון פרטי)

1. **Test and release → Testing → Closed testing** → **Create track**.
2. **Testers:** רשימת אימיילים (Gmail) של **12 אנשים לפחות**.
3. **Create release** → להעלות את ה-`.aab` מתיקיית החנות → **Save** → **Review** → **Start rollout**.
4. **Countries:** Israel.
5. לשלוח לבודקים את **קישור ההצטרפות** (Join on the web).
6. **14 יום ברצף** עם 12 בודקים לפחות. אחרי זה: **Apply for production**, ואז פרסום.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל) ל-`android/play-signing-sha256.txt`. הבנייה הבאה תעדכן
את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## ב. גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** (עיצוב, תכונה, תיקון) — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
  `versionCode` עולה לבד בכל בנייה.

## ג. מה חסר לפני פרסום בחנות

התקנה ישירה (APK) ובדיקה סגורה — אפשר כבר עכשיו. לפרסום ציבורי גוגל דורשת עוד:

- **מדיניות פרטיות** — דף ציבורי (ב-`"public"` של app.json), למשל `privacy.html`.
- **מחיקת חשבון** — מתוך האפליקציה, וגם דף ציבורי שמסביר איך לבקש.
