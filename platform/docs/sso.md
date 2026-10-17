# כניסה מאוחדת — המדריך לאפליקציה

"כניסה עם חשבון כלים דיגיטליים": אפליקציה (בריפו או בדומיין אחר) מזהה משתמש דרך הפלטפורמה,
בלי לשתף איתה משתמשים, מסד או עוגייה. **בלי קודים ובלי סודות להעתיק:** האפליקציה מצהירה על עצמה
ב-app.json, והפלטפורמה מחזירה זהות **חתומה** שהאפליקציה בודקת במפתח ציבורי.

- **חשבון הפלטפורמה הוא זהות.** נמסרים רק מזהה (`sub`), שם (`name`) ומייל (`email`) — לא סיסמה,
  לא הרשאות, לא רשימת אפליקציות.
- **לאפליקציה יש חשבונות משלה**, מקושרים לזהות. הכניסה בסיסמה של האפליקציה נשארת תמיד.
- **המייל קובע** (החלטת בעל המערכת): אותו מייל = אותו אדם. סעיף 4.
- הקוד: `platform/sso.php`, `platform/lib/sso.php`.

## 1. ההצהרה — שורה אחת ב-app.json

```json
"sso": { "return": ["https://migdalor.mbe-plus.com/sso/callback"] }
```

כתובות החזרה המותרות — https מלאה, התאמה מדויקת. אחרי הפריסה האפליקציה מופיעה אצל המנהל
(⚙️ ← 🔗 חיבורים) **דלוקה**; המנהל יכול לכבות במתג. אפליקציה חיצונית (בדומיין אחר) — אותה שורה ב-
`app.json` של תיקיית הרישום שלה כאן (למשל `kadisha/app.json`).

## 2. הזרימה

```
אפליקציה                                       פלטפורמה (https://mbe-plus.com/mini_projects/)
  "כניסה עם חשבון כלים דיגיטליים"
   │ ── redirect ──▶ platform/sso.php?client=<id>&state=<אקראי>&return=<כתובת חזרה>
   │                   לא מחובר → מסך הכניסה הרגיל, וחזרה לכאן
   │                   פעם ראשונה → "X מבקשת לזהות אותך — המשך / ביטול"
   │ ◀── redirect ── <return>?token=<זהות חתומה>&state=<…>   (או ?error=access_denied&state=…)
   האפליקציה בודקת את החתימה במפתח הציבורי — בלי בקשה נוספת לפלטפורמה
```

- **`client`** — ה-`id` מה-app.json.
- **`state`** — 8–200 תווים אקראיים, נשמרים ב-session של האפליקציה. חובה, ונבדק בחזרה.
- **`token`** — `<payload>.<signature>`, שניהם base64url. החתימה (Ed25519) על מחרוזת ה-payload כמו
  שהיא. ה-payload הוא JSON:

```json
{"iss":"https://mbe-plus.com/mini_projects/","aud":"migdalor","sub":"17","name":"דנה לוי",
 "email":"dana@example.com","email_verified":false,"state":"…","return":"https://…/sso/callback",
 "iat":1792268603,"exp":1792268723,"jti":"…"}
```

- **המפתח הציבורי** — `https://mbe-plus.com/mini_projects/platform/sso.php?action=key` →
  `{"alg":"Ed25519","key":"<base64url>","issuer":"…"}`. אפשר לשמור אותו בהגדרות האפליקציה, או לקרוא
  ולשמור במטמון. זה מפתח **ציבורי** — אין בו סוד.

### מה האפליקציה חייבת לבדוק (כולם!)

1. החתימה תקינה במפתח הציבורי.
2. `aud` = ה-id שלה. (זהות שנחתמה לאפליקציה אחרת — נדחית.)
3. `state` = מה שנשמר ב-session, ואז למחוק אותו (שימוש חד-פעמי).
4. `return` = כתובת החזרה שלה, ו-`exp` עוד לא עבר.

## 3. דוגמה ב-PHP (`sodium`)

```php
const PLATFORM = 'https://mbe-plus.com/mini_projects/platform/sso.php';
const CLIENT   = 'migdalor';
const RETURN_URL = 'https://migdalor.mbe-plus.com/sso/callback';
const PLATFORM_KEY = '<מ-sso.php?action=key>';   // ציבורי — מותר בקוד

// הכפתור: /sso/start
session_start();
$_SESSION['sso_state'] = bin2hex(random_bytes(16));
header('Location: ' . PLATFORM . '?' . http_build_query([
    'client' => CLIENT, 'state' => $_SESSION['sso_state'], 'return' => RETURN_URL,
]));

// החזרה: /sso/callback
session_start();
$state = $_SESSION['sso_state'] ?? '';
unset($_SESSION['sso_state']);
if ($state === '' || !hash_equals($state, $_GET['state'] ?? '')) exit('state לא תואם');
if (isset($_GET['error'])) exit('הכניסה בוטלה');

$b64 = fn($s) => base64_decode(strtr($s, '-_', '+/'));
[$payload, $sig] = array_pad(explode('.', $_GET['token'] ?? '', 2), 2, '');
if (!sodium_crypto_sign_verify_detached($b64($sig), $payload, $b64(PLATFORM_KEY))) exit('חתימה לא תקינה');
$id = json_decode($b64($payload), true);
if (($id['aud'] ?? '') !== CLIENT || ($id['state'] ?? '') !== $state
    || ($id['return'] ?? '') !== RETURN_URL || ($id['exp'] ?? 0) < time()) exit('זהות לא תקפה');

// מכאן — של האפליקציה: קישור קיים, ואם אין — איחוד לפי מייל (סעיף 4)
$account = find_account_by_platform_user($id['sub']);
if (!$account && !empty($id['email'])) {
    $account = find_account_by_email($id['email']);
    if ($account) link_platform_user($account, $id['sub']);   // + רישום ביומן
}
if (!$account) exit('אין חשבון מקושר — פנו למנהל המערכת');
login_as($account);
```

## 4. איחוד לפי מייל

החלטת בעל המערכת: **המייל קובע.** משתמש שנכנס דרך הפלטפורמה, ובאפליקציה כבר יש חשבון באותו מייל —
זה אותו אדם, והחשבונות מתאחדים:

1. יש קישור `sub ↔ חשבון` → נכנסים לחשבון המקושר.
2. אין קישור, ו-`email` לא `null` → מחפשים חשבון באותו מייל (בלי תלות באותיות גדולות/קטנות).
   נמצא → **מקשרים אוטומטית**, רושמים ביומן של האפליקציה, ונכנסים.
3. אין קישור ואין חשבון באותו מייל → מה שהאפליקציה מחליטה: חשבון חדש באותו מייל, או "פנו למנהל".

- `email` הוא שם המשתמש בפלטפורמה כשהוא כתובת מייל; אחרת `null`, ואז רק קישור ידני.
- `email_verified: false` — הפלטפורמה לא שולחת מייל אימות. ההגנה: כל הרשמה לפלטפורמה ממתינה לאישור
  המנהל, וזהות נחתמת רק למשתמש פעיל.

## 5. ניתוק

המשתמש (תפריט אישי ← "🔗 חיבורים לאפליקציות") או המנהל (גיליון המשתמש / האפליקציה ב-🔗 חיבורים)
מנתקים — והכניסה הבאה מבקשת אישור מחדש. המנהל יכול גם לכבות את הכניסה המאוחדת לאפליקציה כולה.
