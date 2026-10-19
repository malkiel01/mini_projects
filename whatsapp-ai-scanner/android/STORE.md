# גשר הסורק בחנות של גוגל

הגשר הוא אפליקציה **נייטיב** (שירות נגישות שקורא את מסך הוואטסאפ), לא WebView. כל שינוי בגשר דורש גרסה חדשה;
שינוי בשרת או באתר — לא.

## תיקיית החנות

Release קבוע ב-GitHub: **`whatsapp-ai-scanner-store`**. ה-app.json מצביע עליו
(`"install": {"release": "whatsapp-ai-scanner-store"}`), והכרטיס של הכלי בפלטפורמה מציג ממנו את ההורדות.
הבנייה (`.github/workflows/wascanner-apk.yml`) מעדכנת אותו בכל שינוי ב-`android/` שמגיע ל-main:

| קובץ | מה | למי |
| --- | --- | --- |
| `whatsapp-ai-scanner.apk` | התקנה ידנית בטלפון | כולם |
| `whatsapp-ai-scanner.aab` | העלאה ל-Play Console (רק כשהבנייה חתומה) | מנהל |
| `store-listing.zip` | התוכן של `store/`: טקסטים, תמונות, תשובות למדיניות ו-Data safety | מנהל |
| `assetlinks.json` | טביעות המפתח — הפריסה מאחדת אותו לשורש הדומיין | — |
| `build.json` | גרסה, חתימה, commit | — |

ה-Release הישן `wascanner-apk` (APK בחתימת debug) כבר לא מתעדכן.

## חתימה

הסוד המשותף **`ANDROID_KEYSTORE_PASSWORD`** (GitHub → Settings → Secrets and variables → Actions), לפחות 16
תווים, אותה סיסמה לכל האפליקציות (`platform/docs/rollout.md`, צעד 5). בבנייה הראשונה מ-main עם הסוד נוצר מפתח
ההעלאה ונשמר **מוצפן** בריפו: `keystore/upload.jks.enc`. בלי הסוד — APK בחתימת debug בלבד, בלי AAB.

⚠️ **לא למחוק את `keystore/upload.jks.enc` ולא לשנות את הסיסמה** אחרי ההעלאה הראשונה לחנות.

> מי שהתקין את ה-APK הישן (חתימת debug) צריך להסיר אותו ולהתקין את החדש: אנדרואיד לא מעדכן אפליקציה שהחתימה
> שלה השתנתה. אחרי ההתקנה — להדביק שוב את אסימון הצימוד מהגדרות האתר ולהפעיל מחדש את שירות הנגישות.

## Play Console

הכול ב-`store/listing.md` (גם בתוך `store-listing.zip`). **קודם "סיכוני מדיניות" שם** — שירות הנגישות והגישה לכל
הקבצים נבדקים ידנית, וחסר עדיין מסך גילוי והסכמה בתוך האפליקציה.

1. **Create app** → עברית · App · Free.
2. **Main store listing** — מ-`store-listing.zip`. צילומי מסך — לצלם מהטלפון.
3. **App content** — לפי הטבלה ב-`listing.md`, כולל הצהרת Accessibility API.
4. **App access** — משתמש בדיקה בפלטפורמה עם הרשאה לסורק.
5. **Testing → Closed testing** — חשבון מפתחים פרטי: 12 בודקים, 14 יום ברצף, ואז Apply for production.
6. **אחרי ההעלאה הראשונה:** App integrity → App signing → את ה-SHA-256 של **App signing key certificate**
   להוסיף ל-`play-signing-sha256.txt`.
