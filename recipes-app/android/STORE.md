# אפליקציית המתכונים בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`).
**כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים
משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.recipes` |
| כתובת | `https://recipes.mbe-plus.com/` — סאב-דומיין שתיקיית השורש שלו היא `public_html/mini_projects/recipes-app` (התיקייה שכבר נפרסת) |
| חשבון מפתחים | פרטי — לפני פרסום: בדיקה סגורה עם **12 בודקים, 14 יום ברצף** |
| בנייה | `.github/workflows/recipes-twa.yml` — AAB לחנות ו-APK לבדיקה |
| אישור האתר | `recipes-app/.well-known/assetlinks.json` — נכתב בבנייה |

---

## א. הקמה חד-פעמית — מה שרק אתה יכול לעשות

### 1. הסאב-דומיין

1. GitHub → **Actions** → בצד: **Provision server target** → **Run workflow**.
   הערכים כבר ממולאים (`public_html/mini_projects/recipes-app`, `recipes`, `mbe-plus.com`) → **Run workflow**.
2. בלוג, בשלב "Create the subdomain": אם כתוב שנוצר — מצוין. אם `uapi` אינה זמינה — ליצור ידנית:
   cPanel → **Domains** → **Create A New Domain** → `recipes.mbe-plus.com`, ולבטל את הסימון
   "Share document root", ובתיקייה: `public_html/mini_projects/recipes-app`.
3. **תעודת SSL:** cPanel → **SSL/TLS Status** → לסמן את `recipes.mbe-plus.com` → **Run AutoSSL**
   (בדרך כלל קורה לבד תוך שעה).
4. בדיקה: `https://recipes.mbe-plus.com/` נפתח עם האפליקציה, ו-
   `https://recipes.mbe-plus.com/.well-known/assetlinks.json` מחזיר JSON.

> משתמשים קיימים ימשיכו לעבוד גם בכתובת הישנה. בכתובת החדשה צריך להתחבר פעם אחת מחדש
> (החיבור שמור לכל כתובת בנפרד).

### 2. הסוד של מפתח החתימה

GitHub → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**:

| Name | Secret |
| --- | --- |
| `RECIPES_KEYSTORE_PASSWORD` | סיסמה ארוכה — **לפחות 16 תווים**, אקראית |

**לשמור את הסיסמה גם אצלך** (מנהל סיסמאות). בבנייה הראשונה נוצר מפתח ההעלאה ונשמר בריפו
**מוצפן בסיסמה הזאת** (`keystore/upload.jks.enc`). מוצפן הוא חסר ערך בלי הסיסמה. גוגל מחזיקה
מפתח חתימה משלה (Play App Signing), כך שגם אם מפתח ההעלאה אבד — מחליפים אותו דרך התמיכה
שלהם, והאפליקציה לא אובדת.

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

### 3. בנייה

GitHub → **Actions** → **Build Recipes app (Google Play)** → **Run workflow**.
(רץ גם לבד בכל שינוי בתיקייה `android/`.) בסוף ההרצה, למטה ב-**Artifacts**: `recipes-play-<מספר>`
— בתוכו `recipes-<מספר>.aab` (לחנות) ו-`recipes-<מספר>.apk` (להתקנה ידנית בטלפון לבדיקה).

---

## ב. Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `מתכונים — ספר המתכונים שלי` · שפת ברירת מחדל: **עברית** · App · **Free**
→ לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
מתכונים — ספר המתכונים שלי
```

**תיאור קצר (עד 80):**
```
המתכונים שלך במקום אחד: לשמור, לחפש, לבשל צעד-צעד, לקנות ולשתף.
```

**תיאור מלא:**
```
כל המתכונים שלך — במקום אחד, מסודרים, ותמיד בהישג יד במטבח.

📖 המתכונים שלך
• מתכון עם חלקים (בצק, מלית, ציפוי), רכיבים, שלבים, זמנים, תמונות וסרטונים
• מספר מנות שמשתנה — והכמויות מחושבות מחדש
• ייבוא מתכון מקישור לאתר, עם קרדיט ומקור
• סריקת מתכון מתמונה של פתק בכתב יד

🍳 במטבח
• מצב בישול: שלב אחרי שלב, באותיות גדולות, והמסך לא נכבה
• רשימת קניות ממתכון אחד או מכמה — עם איחוד כמויות
• "מה יש לי בבית?" — אילו מתכונים אפשר להכין ממה שיש

📁 סדר
• אוספים: "ארוחות שבת", "לחגים", "מה שאמא אוהבת" — גם ממתכונים מהרשת, בלי לייבא
• קטגוריות וסינון: עוגות, מרקים, סלטים… כשרות, טבעוני, ללא גלוטן, זמן הכנה
• "בשבילך" — מתכונים שמתאימים למה שאתה אוהב, לעונה ולחגים

👥 עם אחרים
• שיתוף מתכון או אוסף עם אנשים מסוימים — או עם מעגל ("משפחה")
• מעקב אחרי מי שמפרסם מתכונים, תגובות והמלצות
• פרטיות מלאה: מתכון פרטי נשאר שלך, ושליטה מלאה במי משתף איתך

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-home.png` … `5-foryou.png` |

התמונות בצילומים הן איורים (לא תמונות של אתרים אחרים — זכויות יוצרים). מומלץ להחליף בצילומי
מסך אמיתיים מהטלפון, עם מתכונים ותמונות שלך.

**קטגוריה:** Food & Drink · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://recipes.mbe-plus.com/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://recipes.mbe-plus.com/privacy.html` |
| **App access** | חלק מהאפליקציה דורש התחברות → לתת משתמש בדיקה (ראה למטה) |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים; **יש תוכן שמשתמשים יוצרים ושיתוף בין משתמשים** (מתכונים, תגובות) — Yes; יש דיווח (⚑) וחסימה |
| **Target audience** | **18 ומעלה** (פשוט מכולם: בלי דרישות של מדיניות משפחות) |
| **News app** | No |
| **Government app** | No |
| **Financial features** | None |
| **Health** | No |
| **Data safety** | ראה הטבלה הבאה |
| **Account deletion** | בתוך האפליקציה: הגדרות → מחיקת החשבון. ובאתר: `https://recipes.mbe-plus.com/delete-account.html` |

**משתמש בדיקה לגוגל (App access):** ליצור חשבון, למשל `google.review`, ולאמת אותו מ-☰ → ניהול
משתמשים (בלי לחכות למייל). לכתוב בשדה ההוראות: "התחברות עם שם המשתמש והסיסמה. אפשר ליצור
מתכון ב-'+ מתכון חדש'."

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Email address** | כן | לא | Account management | חובה |
| Personal info → **Name** (שם תצוגה) | כן | לא | App functionality, Account management | חובה |
| Photos and videos → **Photos** | כן | לא | App functionality (תמונות מתכונים, סריקת פתק) | רשות |
| App activity → **Other user-generated content** (מתכונים, תגובות) | כן | לא | App functionality | רשות |
| App activity → **App interactions** (פתיחה, בישול — ל"בשבילך") | כן | לא | Personalization | רשות — מתג "התאמה אישית" בהגדרות |
| App info and performance → **Diagnostics** (יומן בקשות) | כן | לא | App functionality, Analytics | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes.
- סריקה וניסוח מחדש בבינה עוברים ל-Anthropic כ**ספק שירות** שמעבד בשמנו — לפי הגדרות גוגל זה אינו "שיתוף".

### 4. בדיקה סגורה (חובה בחשבון פרטי)

1. **Test and release → Testing → Closed testing** → **Create track**.
2. **Testers:** רשימת אימיילים (Gmail) של **12 אנשים לפחות** — משפחה וחברים עם אנדרואיד.
3. **Create release** → להעלות את ה-`.aab` → שם גרסה → **Save** → **Review** → **Start rollout**.
4. **Countries:** Israel.
5. לשלוח לבודקים את **קישור ההצטרפות** (Join on the web) — כל אחד מאשר ומתקין מהחנות.
6. **14 יום ברצף** עם 12 בודקים לפחות שנשארים בתוכנית. אחרי זה: **Apply for production**
   (שאלון קצר על הבדיקה), ואז פרסום.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל), ולשלוח לי — אוסיף ל-`android/play-signing-sha256.txt`,
והבנייה הבאה תעדכן את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## ג. גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** (עיצוב, תכונה, תיקון) — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים, הרשאות) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש.
  `versionCode` עולה לבד בכל בנייה.

## ד. מה עוד לא כאן

- **קנייה בתוך האפליקציה** (קרדיטים לסריקה): באפליקציה מהחנות חובה דרך Google Play Billing
  (Digital Goods API ב-TWA). כרגע אין קנייה — הסריקה באישור המפתח — ולכן אין צורך.
- **התראות לטלפון** — אפשר ב-TWA (Web Push); נדחה (SPEC פרק 12).
