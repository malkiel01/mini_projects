# כרטיסי ברכה בחנות של גוגל

האפליקציה היא WebView על דף הכלי, עם גשר ל-WhatsApp ולאנשי הקשר (`MainActivity.kt`, `WhatsAppBridge.kt`).
**כל שינוי בדף מגיע לאפליקציה מיד**, בלי גרסה חדשה בחנות. גרסה חדשה — רק כשמשנים משהו בתיקייה `android/`.

## תיקיית החנות

Release קבוע ב-GitHub: **`greeting-cards-store`**. ה-app.json מצביע עליו (`"install": {"release": "greeting-cards-store"}`),
והכרטיס של הכלי בפלטפורמה מציג ממנו את ההורדות. הבנייה (`.github/workflows/greeting-cards-apk.yml`) מעדכנת אותו
בכל שינוי ב-`android/` שמגיע ל-main:

| קובץ | מה | למי |
| --- | --- | --- |
| `greeting-cards.apk` | התקנה ידנית בטלפון | כולם |
| `greeting-cards.aab` | העלאה ל-Play Console (רק כשהבנייה חתומה) | מנהל |
| `store-listing.zip` | התוכן של `store/`: טקסטים, תמונות, תשובות למדיניות ו-Data safety | מנהל |
| `assetlinks.json` | טביעות המפתח — הפריסה מאחדת אותו לשורש הדומיין | — |
| `build.json` | גרסה, חתימה, commit | — |

## חתימה

הסוד המשותף **`ANDROID_KEYSTORE_PASSWORD`** (GitHub → Settings → Secrets and variables → Actions), לפחות 16
תווים, אותה סיסמה לכל האפליקציות (`platform/docs/rollout.md`, צעד 5). בבנייה הראשונה מ-main עם הסוד נוצר מפתח
ההעלאה ונשמר **מוצפן** בריפו: `keystore/upload.jks.enc`. בלי הסוד — APK בחתימת debug בלבד, בלי AAB.

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

> מי שהתקין APK ישן (חתימת debug) צריך להסיר אותו ולהתקין את החדש: אנדרואיד לא מעדכן אפליקציה שהחתימה שלה השתנתה.

## Play Console

הכול ב-`store/listing.md` (גם בתוך `store-listing.zip`): שם, תיאורים, גרפיקה, App content ו-Data safety.

1. **Create app** → עברית · App · Free.
2. **Main store listing** — הטקסטים והתמונות מ-`store-listing.zip`.
3. **App content** — לפי הטבלה ב-`listing.md`. ההרשאה לאנשי קשר רגישה — יש שם נוסח מוכן להצהרה.
4. **App access** — משתמש בדיקה בפלטפורמה עם הרשאה לכרטיסי ברכה.
5. **Testing → Closed testing** — חשבון מפתחים פרטי: 12 בודקים, 14 יום ברצף, ואז Apply for production.
6. **אחרי ההעלאה הראשונה:** App integrity → App signing → את ה-SHA-256 של **App signing key certificate**
   להוסיף ל-`play-signing-sha256.txt`.

## מתי צריך גרסה חדשה

- **שינוי בדף** (עיצוב, תכונה, תיקון) — לא צריך.
- **שינוי ב-`android/`** (סמל, שם, הרשאות, הגשר) — הבנייה רצה לבד; מעלים את ה-AAB החדש. `versionCode` עולה לבד.
