# מגדלור — דף החנות ותשובות ל-Play Console

הקובץ הזה, עם התמונות שלידו, הוא `store-listing.zip` שבתיקיית החנות (Release `guarded-browser-store`).

| מה | ערך |
| --- | --- |
| חבילה | `com.mbeplus.guardedbrowser` |
| שפת ברירת מחדל | עברית |
| סוג | App · Free |
| קטגוריה | Parenting |
| אימייל | `support@mbe-plus.com` |
| אתר | `https://mbe-plus.com/mini_projects/guarded-browser/parent/` |
| מדיניות פרטיות | `https://mbe-plus.com/mini_projects/guarded-browser/privacy.html` |

## Main store listing

**שם (עד 30):**
```
מגדלור — גלישה בטוחה לילדים
```

**תיאור קצר (עד 80):**
```
אפליקציה נעולה במכשיר של הילד — ההורה קובע מה מותר לפתוח, ומתי.
```

**תיאור מלא:**
```
מגדלור הופך את המכשיר של הילד למכשיר בטוח: רק מה שההורה התיר נפתח.

🌐 גלישה
• לפי אתר, קטגוריה (20 קטגוריות) וסוג תוכן
• מצב סגור (רק מה שאושר) או פתוח (הכול חוץ ממה שנחסם)
• חיפוש בטוח, וחסימת פרסומות

▶️ יוטיוב לפי ערוצים
• רק הערוצים שאישרתם — בלי הצעות מערוצים זרים
• Shorts — לפי בחירתכם

📱 אפליקציות ושעות
• אילו אפליקציות מותרות במכשיר
• ימים, חלון שעות, מכסת זמן יומית ומשך ישיבה

🙋 בקשות
• הילד מבקש לפתוח אתר, וההורה מאשר מהטלפון — לשעה או לתמיד

📊 פעילות — כמה שתבחרו
• היסטוריה מלאה, סיכום לפי אתר, או כלום

ההורה מנהל הכול מאפליקציית ההורים (בדפדפן, אפשר להתקין לטלפון).
בלי פרסומות.
```

**גרפיקה:**

| מה | קובץ |
| --- | --- |
| App icon (512×512) | `icon-512.png` |
| Feature graphic (1024×500) | `feature-1024x500.png` |
| Phone screenshots (לפחות 2, 1080×1920) | **חסרים** — לצלם ממכשיר אמיתי: מסך הבית של הילד, דף חסום, בקשת גישה, וגיליון הילד באפליקציית ההורה. לשמור ב-`screenshots/` |

## App content (Policy → App content)

| שאלה | תשובה |
| --- | --- |
| **Privacy policy** | `https://mbe-plus.com/mini_projects/guarded-browser/privacy.html` |
| **App access** | האפליקציה עובדת רק אחרי צימוד לחשבון משפחה → לתת לגוגל חשבון בדיקה (ראה למטה) |
| **Ads** | No |
| **Content rating** | שאלון IARC: אין אלימות, מין, הימורים או תוכן בין משתמשים. דפדפן מסונן — הגלישה מוגבלת למה שההורה התיר |
| **Target audience** | 18 ומעלה (ההורה מתקין ומגדיר). ⚠️ לא לסמן ילדים: אפליקציה שמיועדת לילדים נכנסת ל-Families Policy |
| **News app** | No |
| **Government app** | No |
| **Financial features** | None |
| **Health** | No |
| **Data safety** | הטבלה הבאה |
| **Account deletion** | `https://mbe-plus.com/mini_projects/guarded-browser/privacy.html` (סעיף "מחיקה") |

**חשבון בדיקה לגוגל (App access):** בקונסולת המפתח (`dev/`) לפתוח חשבון משפחה `google.review`, עם הורה וילד
אחד. בשדה ההוראות: "באפליקציית ההורים (הכתובת למעלה) — כניסה בשם ובסיסמה, הוספת מכשיר, וסריקת הקוד מהאפליקציה."

### הרשאות רגישות — הצהרות

| הרשאה | ההצהרה |
| --- | --- |
| **AccessibilityService** (`AppGuardService`) | "פיקוח הורים: זיהוי האפליקציה שבחזית וסגירת אפליקציה שההורה לא התיר. השירות קורא רק את שם החבילה שבחזית — לא תוכן מהמסך ולא הקשות." נדרש סרטון קצר שמראה את ההסבר במסך ההקמה ואת החסימה |
| **Device admin** (`MigdalorAdmin`) | "מניעת הסרה של אפליקציית הפיקוח בלי ההורה" |
| **REQUEST_INSTALL_PACKAGES** | ⚠️ **אסור בגרסת החנות** — עדכון עצמי מחוץ לחנות. ראו `STORE.md`, "לפני ההעלאה הראשונה" |

## Data safety

| סוג מידע | נאסף | משותף | חובה | למה |
| --- | --- | --- | --- | --- |
| Web browsing history | ✅ | ❌ | ✅ (חסימות) · לפי ההורה (היסטוריה) | App functionality |
| Installed apps | ✅ | ❌ | ✅ | App functionality |
| Device or other IDs (ANDROID_ID) | ✅ | ❌ | ✅ | App functionality, Fraud prevention & security |
| Name, Email (ההורה) | ✅ | ❌ | ✅ | Account management |
| App interactions (זמן שימוש לפי אתר) | ✅ | ❌ | לפי ההורה | App functionality |
| Crash logs / Diagnostics (רישומי אבחון) | ✅ | ❌ | ❌ (ידני) | App functionality |

- Data encrypted in transit: **Yes** (HTTPS).
- Users can request data deletion: **Yes** — מחיקת ילד מגיליון ההורה, או במייל.
