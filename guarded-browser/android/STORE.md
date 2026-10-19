# מגדלור בחנות של גוגל

אפליקציית הילד (`android/`) — נייטיב, לא WebView על דף. כל שינוי בה דורש גרסה חדשה.

## תיקיית החנות

Release קבוע ב-GitHub: **`guarded-browser-store`**. ה-app.json מצביע עליו (`"install": {"release": "guarded-browser-store"}`),
והכרטיס של הכלי בפלטפורמה מציג ממנו את ההורדות. הבנייה (`.github/workflows/android.yml`) מעדכנת אותו בכל שינוי
ב-`android/` שמגיע ל-main:

| קובץ | מה | למי |
| --- | --- | --- |
| `guarded-browser.apk` | התקנה ידנית בטלפון | כולם |
| `guarded-browser.aab` | העלאה ל-Play Console | מנהל |
| `store-listing.zip` | התוכן של `store/`: טקסטים, תמונות, תשובות למדיניות ו-Data safety | מנהל |
| `build.json` | גרסה, טביעת המפתח, commit | — |

אין `assetlinks.json`: האפליקציה לא פותחת קישורים של הדומיין (זה נדרש רק ל-TWA).

**ה-Release הישן `guarded-browser-apk` נשאר, ומתעדכן כמו קודם** (`migdalor.apk` + `latest.json`): ממנו השרת יודע מה
הגרסה האחרונה (`lib/appversion.php`), ממנו `download.php` מוריד את ה-APK לאפליקציית ההורים, ועליו נשען ה-QR של
"בעל מכשיר". אסור לשנות לו שם.

## חתימה

**מפתח אחד** לכל הגרסאות — זה שכבר מותקן במכשירים (alias `mg`). עדכון מותקן מעל רק בחתימה זהה, וה-QR של
"בעל מכשיר" בודק את טביעת המפתח.

הסוד המשותף **`ANDROID_KEYSTORE_PASSWORD`** (GitHub → Settings → Secrets and variables → Actions), לפחות 16
תווים, אותה סיסמה לכל האפליקציות (`platform/docs/rollout.md`, צעד 5). המפתח בריפו, **מוצפן** בה:
`keystore/signing.jks.enc`.

**המעבר (פעם אחת, בבנייה הראשונה מ-main):** עד עכשיו המפתח היה מוצפן בסיסמה שנגזרה מ-`DEPLOY_SSH_KEY`
(`keystore/release.jks.enc`). הבנייה פותחת אותו בסיסמה הישנה, מעבירה אותו לסיסמה החדשה, **בודקת שטביעת המפתח
זהה**, שומרת את `signing.jks.enc` ומוחקת את הישן — באותו commit. אם הטביעה שונה — עוצרת בלי לגעת בכלום.

⚠️ **לא למחוק את `keystore/signing.jks.enc` ולא לשנות את הסיסמה.** מפתח חדש = כל מכשיר צריך להסיר את
האפליקציה ולהתקין מחדש, ומכשיר "בעל מכשיר" — איפוס להגדרות יצרן.

## לפני ההעלאה הראשונה — מה עוד חסר

התיקייה מוכנה, אבל גרסת החנות דורשת החלטות שלא נעשו כאן — כי הן משנות את ההתנהגות של האפליקציה במכשיר של
הילד, ואי אפשר לבדוק אותן בלי מכשיר:

1. **`targetSdk` 34 → 36.** Google Play מקבל היום רק 35 ומעלה (בעדכונים — 36 מ-31.8.2026). המעבר כופה
   edge-to-edge (תוכן מתחת לשורת המצב) ו-predictive back (`onBackPressed` לא נקרא באנדרואיד 16 —
   `BlockedActivity` עדיין משתמש בו). לבדוק במכשיר את כל המסכים, ובמיוחד שאי אפשר לצאת מהדף החסום.
2. **עדכון עצמי (`REQUEST_INSTALL_PACKAGES`, `Updater.kt`)** — אסור באפליקציה מהחנות. בגרסת החנות: בלי
   ההרשאה, והעדכון דרך החנות. כנראה flavor נפרד (`store` / `direct`), כדי שה-APK הישיר ימשיך להתעדכן לבד.
3. **AccessibilityService** — הצהרה וסרטון (נוסח מוכן ב-`store/listing.md`).
4. **צילומי מסך** — ממכשיר אמיתי, ל-`store/screenshots/`.

## Play Console

הכול ב-`store/listing.md` (גם בתוך `store-listing.zip`).

1. **Create app** → עברית · App · Free.
2. **App signing:** לבחור **"Use a different key" → להעלות את המפתח הקיים** (PEPK), לא מפתח שגוגל יוצרת. כך
   האפליקציה מהחנות והאפליקציה מה-APK הישיר חתומות באותו מפתח — מתעדכנות זו מעל זו, וה-QR של "בעל מכשיר"
   ממשיך לעבוד.
3. **Main store listing** — הטקסטים והתמונות מ-`store-listing.zip`.
4. **App content** ו-**Data safety** — לפי הטבלאות ב-`listing.md`.
5. **Testing → Closed testing** — חשבון מפתחים פרטי: 12 בודקים, 14 יום ברצף, ואז Apply for production.
