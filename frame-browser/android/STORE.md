# הדפדפן המשובץ בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity): היא פותחת את האתר ב-Chrome, במסך מלא, בלי
שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה (`app/build.gradle.kts`).
**כל עדכון באתר מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה לחנות — רק כשמשנים
משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.framebrowser` |
| כתובת | `https://mbe-plus.com/mini_projects/frame-browser/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`): על הדומיין יש עוד כלים |
| תיקיית החנות | Release קבוע `frame-browser-store`: `frame-browser.apk`, `frame-browser.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/frame-browser-store` |
| בנייה | `.github/workflows/frame-browser-twa.yml` — AAB לחנות ו-APK לבדיקה |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`; המפתח מוצפן ב-`keystore/upload.jks.enc` (נוצר בבנייה הראשונה) |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לשורש הדומיין (`platform/tools/assetlinks.php`) |

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

---

## Play Console

### 1. יצירת האפליקציה

**Create app** → שם: `דפדפן משובץ` · שפת ברירת מחדל: **עברית** · App · **Free** → לאשר את ההצהרות.

### 2. דף החנות (Main store listing)

**שם (עד 30):**
```
דפדפן משובץ — כמה אתרים במסך
```

**תיאור קצר (עד 80):**
```
כמה אתרים במסך אחד, ותשובה ברורה כשאתר לא מרשה להציג אותו בתוך דף.
```

**תיאור מלא:**
```
מדביקים כתובת — והדף נפתח כאן בפנים. אחד, שניים זה לצד זה, או ארבעה במקביל.

🪟 כמה אתרים במסך אחד
• פריסה של מסגרת אחת, שתיים או ארבע — מתחלפת בלחיצה
• כל מסגרת עם שורת כתובת, רענון, פתיחה בלשונית ושמירה
• המסגרות הפתוחות נשמרות — וחוזרות בפתיחה הבאה

⛔ תשובה במקום מלבן ריק
• לפני שהמסגרת נוצרת, בודקים אם האתר מרשה להציג אותו בתוך דף אחר
• אתר שחוסם — הסיבה המדויקת (X-Frame-Options / CSP), וכפתור לפתיחה בלשונית
• "לא הצלחתי לבדוק" אינו "האתר חוסם": כששירות הגנה עוצר את הבדיקה, המסגרת נטענת בכל זאת
• 🔍 אבחון מעמיק: מה נוסה, ומה חזר מכל ניסיון

★ קיצורים
• קישורים שמורים והיסטוריה — בשורת קיצורים מעל המסגרות
• הכול נשמר במכשיר שלך בלבד

בלי פרסומות.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-open.png` … `5-saved.png` |

הצילומים — מהאפליקציה עצמה (390px), עם אתרי הדגמה בדומיין `.example` (שמור, לא אתרים של אחרים).

**קטגוריה:** Tools · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/frame-browser/`

### 3. App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | נדרש קישור — אין עדיין דף פרטיות לכלי (ראה "מה עוד לא כאן") |
| **App access** | הכניסה דרך הכלים הדיגיטליים (השער) → לתת לגוגל משתמש בדיקה בפלטפורמה עם הרשאה לכלי |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים. **הכלי מציג אתרים שהמשתמש בוחר** — "Users can access unrestricted web content": Yes |
| **Target audience** | **18 ומעלה** |
| **News / Government / Financial / Health** | No |
| **Data safety** | ראה הטבלה הבאה |

**Data safety:**

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Web browsing → **Web browsing history** (הכתובת שנבדקת עוברת לשרת לבדיקת ההטמעה; לא נשמרת) | כן — ephemeral | לא | App functionality | חובה |
| Personal info → **Email address** (חשבון הפלטפורמה) | כן | לא | Account management | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- השמורים וההיסטוריה — רק במכשיר (`localStorage`), לא בשרת.

### 4. בדיקה סגורה (חובה בחשבון פרטי)

כמו במתכונים (`recipes-app/android/STORE.md`, פרק ב.4): מסלול Closed testing, **12 בודקים, 14 יום ברצף**,
ואז Apply for production.

### 5. אחרי ההעלאה הראשונה — חשוב

**Test and release → App integrity → App signing** → להעתיק את **SHA-256 certificate fingerprint**
של **App signing key certificate** (של גוגל) ל-`android/play-signing-sha256.txt`, והבנייה הבאה תעדכן את
`assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

---

## גרסה חדשה לחנות — מתי צריך

- **שינוי באתר** — **לא צריך**. האפליקציה מציגה את האתר החי.
- **שינוי ב-`android/`** (סמל, שם, צבעים) — בנייה חדשה (רצה לבד), ומעלים את ה-AAB החדש. `versionCode` עולה לבד.

## מה עוד לא כאן

- **דף פרטיות** — Play Console דורש קישור. להחליט אם דף לכלי (`privacy.html`, ציבורי) או דף אחד לפלטפורמה.
