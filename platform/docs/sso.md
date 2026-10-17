# כניסה מאוחדת — המדריך לאפליקציה

"כניסה עם חשבון כלים דיגיטליים": אפליקציה (בריפו או בדומיין אחר) מזהה משתמש דרך הפלטפורמה,
בלי לשתף איתה משתמשים, מסד או עוגייה.

- **חשבון הפלטפורמה הוא זהות.** הפלטפורמה מוסרת רק מזהה (`sub`) ושם תצוגה (`name`) — לא סיסמה,
  לא הרשאות, לא רשימת אפליקציות.
- **לאפליקציה יש חשבונות משלה.** היא מחזיקה טבלת קישורים `platform_user_id ↔ חשבון פנימי`, ומחליטה
  לבד מי מקשר ומה קורה עם זהות שאינה מקושרת. הכניסה בסיסמה של האפליקציה נשארת תמיד.
- הדרישות המקוריות: `guarded-browser/docs/sso-request.md`. הקוד: `platform/sso.php`, `platform/lib/sso.php`.

## 1. רישום (המנהל, פעם אחת)

⚙️ ניהול ← **🔗 חיבורים** ← "+ אפליקציה חדשה":

| שדה | למשל |
| --- | --- |
| מזהה (`client`) | `migdalor` |
| שם (מופיע במסך האישור) | מגדלור |
| כתובות חזרה — https מלאה, התאמה מדויקת | `https://migdalor.mbe-plus.com/sso/callback` |

אחרי היצירה מוצג **הסוד** (`mps_…`) — פעם אחת. שומרים אותו בשרת של האפליקציה (קובץ הגדרות מוחרג
מהפריסה, או סוד GitHub שהפריסה כותבת), לא בקוד ולא בדפדפן. אבד? "🔑 סוד חדש" באותו מסך.

## 2. הזרימה

```
אפליקציה                                        פלטפורמה (https://mbe-plus.com/mini_projects/)
  "כניסה עם חשבון כלים דיגיטליים"
   │ ── redirect ──▶ platform/sso.php?client=<id>&state=<אקראי>&return=<כתובת חזרה>
   │                   לא מחובר → מסך הכניסה הרגיל, ואז חזרה לכאן
   │                   פעם ראשונה → "X מבקשת לזהות אותך — המשך / ביטול"
   │ ◀── redirect ── <return>?code=<…>&state=<…>        (או ?error=access_denied&state=…)
   │ ── POST platform/sso.php?action=token ──▶  client, client_secret, code, return
   │ ◀── {"sub":"17","name":"דנה לוי","iat":…,"exp":…}
```

- **`state`** — 8–200 תווים אקראיים, נשמרים ב-session של האפליקציה ונבדקים בחזרה (CSRF). חובה.
- **`code`** — חד-פעמי, תקף 60 שניות, קשור ל-`client` ול-`return`.
- **`return`** — חייב להיות בדיוק אחת מהכתובות הרשומות, גם בהחלפה. כתובת אחרת = דף שגיאה, לא הפניה.
- **החלפת הקוד** — בקשת שרת-לשרת בלבד (הסוד לעולם לא בדפדפן). גוף כטופס או JSON.
  שגיאה: קוד 4xx ו-`{"error": "…"}`. חמישה כשלים מאותה כתובת IP — נעילה לרבע שעה.
- **ביטול** — המשתמש (תפריט אישי ← "🔗 חיבורים לאפליקציות") או המנהל (גיליון המשתמש / גיליון האפליקציה)
  מנתקים. אחרי ניתוק, הכניסה הבאה מבקשת אישור שוב, וקוד שכבר ניתן לא יוחלף.

## 3. דוגמה ב-PHP

```php
const PLATFORM = 'https://mbe-plus.com/mini_projects/platform/sso.php';
const CLIENT   = 'migdalor';
const RETURN_URL = 'https://migdalor.mbe-plus.com/sso/callback';

// הכפתור: /sso/start
session_start();
$_SESSION['sso_state'] = bin2hex(random_bytes(16));
header('Location: ' . PLATFORM . '?' . http_build_query([
    'client' => CLIENT, 'state' => $_SESSION['sso_state'], 'return' => RETURN_URL,
]));

// החזרה: /sso/callback
session_start();
if (!hash_equals($_SESSION['sso_state'] ?? '', $_GET['state'] ?? '')) exit('state לא תואם');
unset($_SESSION['sso_state']);
if (isset($_GET['error'])) exit('הכניסה בוטלה');

$ch = curl_init(PLATFORM . '?action=token');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
    CURLOPT_POSTFIELDS => http_build_query([
        'client' => CLIENT, 'client_secret' => SSO_SECRET,   // מקובץ ההגדרות, לא מהקוד
        'code' => $_GET['code'] ?? '', 'return' => RETURN_URL,
    ])]);
$id = json_decode((string)curl_exec($ch), true);
if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200 || empty($id['sub'])) exit('הזיהוי נכשל');

// מכאן — של האפליקציה: לחפש את הקישור ולהכניס
$account = find_account_by_platform_user($id['sub']);
if (!$account) exit('אין חשבון מקושר — פנו למנהל המערכת');
login_as($account);
```
