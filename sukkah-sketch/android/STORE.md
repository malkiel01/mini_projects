# השרטוט לסוכה בחנות של גוגל

האפליקציה היא **TWA** (Trusted Web Activity), מועתקת מ-`recipes-app/android/`: היא פותחת את
הכלי ב-Chrome, במסך מלא, בלי שורת כתובת. אין בה קוד משלנו — רק הגדרות, סמלים וחתימה
(`app/build.gradle.kts`). **כל עדכון בכלי מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה
לחנות — רק כשמשנים משהו כאן, בתיקייה `android/`.

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.sukkah` |
| כתובת | `https://mbe-plus.com/mini_projects/sukkah-sketch/` — מאחורי השער של הפלטפורמה. האפליקציה תופסת קישורים **רק בתיקייה הזאת** (`pathPrefix`) |
| בלי רשת | ה-service worker שומר את כל הכלי (הדפים, three.js, קובצי ה-PDF) — אחרי פתיחה אחת עם רשת הוא עובד גם בלי |
| תיקיית החנות | Release קבוע `sukkah-sketch-store`: `sukkah-sketch.apk`, `sukkah-sketch.aab`, `store-listing.zip`, `assetlinks.json`, `build.json` — `https://github.com/malkiel01/mini_projects/releases/tag/sukkah-sketch-store` |
| בנייה | `.github/workflows/sukkah-sketch-twa.yml` — רצה לבד בכל שינוי ב-`android/` |
| חתימה | הסוד המשותף `ANDROID_KEYSTORE_PASSWORD`; מפתח ההעלאה נוצר בבנייה הראשונה ונשמר מוצפן ב-`keystore/upload.jks.enc`. ⚠️ לא למחוק אותו ולא לשנות את הסיסמה אחרי ההעלאה הראשונה |
| אישור האתר | `assetlinks.json` — הפריסה מאחדת אותו לשורש הדומיין (`platform/tools/assetlinks.php`) |

---

## Play Console

**Create app** → שם: `שרטוט לסוכה` · שפת ברירת מחדל: **עברית** · App · **Free**.

### דף החנות

**שם (עד 30):**
```
שרטוט לסוכה — שלד 1:1
```

**תיאור קצר (עד 80):**
```
סוכה למרפסת בתלת־ממד: כל קורה עם אורך, מחברים וזוויות חיתוך.
```

**תיאור מלא:**
```
בונים סוכה למרפסת? כל השלד — בתלת־ממד, בקנה מידה 1:1, עם כל מספר שצריך בנגרייה.

📐 השרטוט
• תלת־ממד, מבט־על, מדרום וממזרח
• הקשה על קורה או עמוד — אורך חוץ ופנים, זוויות החיתוך בשני הקצוות והפרופיל
• מחברי שרוול־ושן בכל קצה, ו"פירוק" שמרים את הקורות ומראה איך הן יורדות לשרוולים
• שכבות להדלקה וכיבוי: מפלס 70, הקיר, המחברים והמידות

🪚 מצגת החלקים
• שקופית לכל קורה, עמוד ומחבר — מידות, חיתוכים ומיקומי השיניים
• תוכניות, חתך גבהים ורשימת חיתוך מלאה
• הורדה כ-PDF להדפסה

📏 הזוויות
• כל חיתוך משופע: כמה מעלות, איזה צד נסוג ובכמה — כדי לסמן על הפרופיל בלי מד־זווית

עובד גם בלי רשת. בלי פרסומות ובלי איסוף מידע.
```

**גרפיקה** (בתיקייה `store/`):

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `store/icon-512.png` |
| Feature graphic (1024×500) | `store/feature-1024x500.png` |
| Phone screenshots (1080×1920) | `store/screenshots/1-3d.png` … `5-angles.png` — צילומים של הכלי עצמו (Playwright, רוחב 390) |

**קטגוריה:** House & Home · **אימייל:** `support@mbe-plus.com` · **אתר:** `https://mbe-plus.com/mini_projects/sukkah-sketch/`

### App content

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/sukkah-sketch/privacy.html` (ציבורי, מחוץ לשער) |
| **App access** | הכניסה דרך "הכלים הטכנולוגיים" → לתת לגוגל משתמש בדיקה בפלטפורמה עם הרשאה לכלי הזה |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות/מין/הימורים, אין תוכן של משתמשים ואין שיתוף בין משתמשים |
| **Target audience** | 18 ומעלה |
| **News / Government / Financial / Health** | No / No / None / No |
| **Data safety** | **No data collected, no data shared.** האפליקציה עצמה לא אוספת כלום; ההתחברות היא של הפלטפורמה. Encrypted in transit: Yes (HTTPS) |
| **Account deletion** | אין לאפליקציה חשבונות משלה — החשבון בפלטפורמה |

### בדיקה סגורה ואחרי ההעלאה הראשונה

כמו במתכונים (`recipes-app/android/STORE.md`, ב.4–ב.5): 12 בודקים, 14 יום ברצף; ואחרי ההעלאה
הראשונה — את ה-SHA-256 של **App signing key** להוסיף ל-`android/play-signing-sha256.txt`, והבנייה
הבאה תעדכן את `assetlinks.json`. בלי זה, מה שמותקן מהחנות נפתח **עם שורת כתובת** למעלה.

## צילומי המסך מחדש

אחרי שינוי שנראה בכלי: שרת מקומי בשורש הריפו (`python3 -m http.server 8765`), ו-Playwright ברוחב 390
(`deviceScaleFactor` 1080/390, גובה 693) על `index.html` (תלת־ממד, הקשה על ע2, מבט־על), `parts.html`
(שקופית 7) ו-`angles.html`. את הנתונים אין צורך להכין — המודל מגיע מהקוד.
