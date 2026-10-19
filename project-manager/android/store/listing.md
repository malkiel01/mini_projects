# ניהול פרויקטים — דף החנות ותשובות ל-Play Console

הקובץ הזה, עם התמונות שלידו, הוא `store-listing.zip` שבתיקיית החנות (Release `project-manager-store`).

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.projectmanager` |
| שפת ברירת מחדל | עברית |
| סוג | App · Free |
| קטגוריה | Business |
| אימייל | `support@mbe-plus.com` |
| אתר | `https://mbe-plus.com/mini_projects/project-manager/` |
| מדיניות פרטיות | `https://mbe-plus.com/mini_projects/project-manager/privacy.html` |

## Main store listing

**שם (עד 30):**
```
ניהול פרויקטים — שיפוץ ובנייה
```

**תיאור קצר (עד 80):**
```
שלבים, ספקים, הצעות מחיר, תשלומים ותיעוד מהשטח — כל הפרויקט במקום אחד.
```

**תיאור מלא:**
```
ניהול פרויקטים של שיפוץ ובנייה — למנהלי פרויקטים, קבלנים ומפקחים.

🧱 שלבים
• כל שלב עם הספק שלו, התקדמות, תאריכים ומה עוד חסר
• תבניות לשיפוץ, לתוספת בנייה ולמשרדים

💰 כספים
• הצעות מחיר ואישור הלקוח, אבני דרך לתשלום ועיכבון
• מה שולם, מה הגיע מועדו, והתראה כשמשלמים מעל הביצוע
• תקציב לפי שלבים, לפני מע"מ או כולל מע"מ

📎 קבצים ותיעוד
• תמונות מהשטח, חוזים, חשבוניות ואסמכתאות — בתיקיות של הפרויקט
• שיתוף ישיר מכל אפליקציה לתיקיות האחרונות

👥 צוות ולקוחות
• קישור אישי ללקוח, לעורך הדין ולספק — רואים את מה שנוגע להם ומאשרים בלחיצה
• יומן עם תאריכים עבריים, תזכורות ומייל יומי

נדרש חשבון ב"כלים הדיגיטליים" (mbe-plus.com) עם הרשאה לאפליקציה. בלי פרסומות.
```

**גרפיקה:**

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `icon-512.png` |
| Feature graphic (1024×500) | `feature-1024x500.png` |
| Phone screenshots (1080×1920) | `screenshots/1-projects.png` … `5-calendar.png` — נתוני הדגמה, שמות בדויים |

## App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/project-manager/privacy.html` |
| **App access** | דורש חשבון "כלים דיגיטליים" עם הרשאה לאפליקציה → לתת לגוגל משתמש בדיקה (ראה למטה) |
| **Ads** | No |
| **Content rating** | שאלון IARC: כלי עבודה עסקי. אין אלימות, מין, הימורים או תוכן של משתמשים אחרים לציבור |
| **Target audience** | 18 ומעלה |
| **News / Government / Health** | No |
| **Financial features** | None — רישום תשלומים בין לקוח לספק, בלי העברת כסף באפליקציה |
| **Data safety** | הטבלה הבאה |
| **Account deletion** | `https://mbe-plus.com/mini_projects/project-manager/privacy.html` (סעיף "מחיקה") |

**משתמש בדיקה לגוגל (App access):** חשבון בפלטפורמה, למשל `google.review`, עם הרשאה לניהול הפרויקטים בלבד,
ופרויקט הדגמה בחשבון. בשדה ההוראות: "Sign in with the provided Digital Tools account; the app opens on the projects list."

### Data safety

| סוג מידע | נאסף | שותף | למה | חובה? |
| --- | --- | --- | --- | --- |
| Personal info → **Name**, **Email address**, **Phone number** (המשתמש) | כן | לא | Account management | חובה |
| Personal info → **Name**, **Email**, **Phone**, **Address** (אנשי קשר שהמשתמש מזין: לקוחות, ספקים) | כן | לא | App functionality | רשות |
| Financial info → **Other financial info** (סכומי הצעות ותשלומים, פרטי בנק של ספקים) | כן | לא | App functionality | רשות |
| Photos and videos / Files and docs (תמונות מהשטח, חוזים, אסמכתאות) | כן | כן — לספק ה-AI (Anthropic), רק כשהסיווג החכם מופעל, לזיהוי סוג המסמך | App functionality | רשות |
| App info and performance → **Crash logs**, **Diagnostics** (יומן שגיאות) | כן | לא | App functionality | חובה |

- **Encrypted in transit:** Yes (HTTPS).
- **Users can request deletion:** Yes — `support@mbe-plus.com`.
- המצלמה — רק דרך בחירת קובץ (אין הרשאת מצלמה באפליקציה). אין מיקום, אין אנשי קשר מהטלפון בלי בחירה מפורשת.

### אחרי ההעלאה הראשונה

Play Console → App integrity → App signing → את ה-SHA-256 של **App signing key certificate** להוסיף ל-
`project-manager/android/play-signing-sha256.txt`. הבנייה הבאה מוסיפה אותו ל-`assetlinks.json`.
