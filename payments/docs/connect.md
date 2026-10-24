# חיבור אפליקציה או אתר לתוסף התשלומים — המדריך

> למי: הצ'אט של אפליקציה בכלים הדיגיטליים, או מפתח של אתר/מערכת חיצונית שהמנהל אישר.
> **לא נוגעים בקוד של התוסף.** מעתיקים את ספריית הלקוח אליכם, ומדברים עם התוסף ב-HTTP חתום — כמו עם שירות חיצוני.

## 0. מה בעל המערכת עושה (פעם אחת, בממשק הניהול של התוסף)

1. **עוסק** (הגדרות ← "+ עוסק") — מי מוציא את הקבלה; ואם יש — חיבור מורנינג (`docs/morning-setup.md`).
2. **מערכת** (מערכות ← "+ מערכת") — המזהה שלכם (למשל `guarded-browser`) ושיוך לעוסק.
3. **קוד סודי לבדיקות** (בכרטיס המערכת ← "+ סודי · בדיקות") — מוצג פעם אחת; מעבירים אליכם בערוץ מאובטח.
4. **מוצרים ומחירים** — בכרטיס המערכת. **המחירים חיים רק בתוסף**; אתם מתייחסים למוצר לפי הקוד (`apps`, `ai_tokens`).
5. **כתובת הודעות** (webhook) של המערכת שלכם + "+ סוד webhook".
6. כשהכול נבדק — "מאושרת לכסף אמיתי", וקוד סודי לחי.

## 1. להעתיק את הספרייה

| שפה | קובץ במקור | לאן אצלכם |
| --- | --- | --- |
| PHP 8 | `payments/sdk/php/PaymentsClient.php` | למשל `lib/PaymentsClient.php` |
| Node 18+ | `payments/sdk/js/payments-client.mjs` | למשל `lib/payments-client.mjs` |

קובץ אחד, בלי תלויות. מספר הגרסה בראש הקובץ (`VERSION`). **הקוד הסודי רק בשרת** — בהגדרות/סודות של האפליקציה, לא
בגיט ולא בדפדפן.

```php
require_once __DIR__ . '/PaymentsClient.php';
$pay = new PaymentsClient([
    'base_url' => 'https://mbe-plus.com/mini_projects/payments/',
    'key' => $secrets['payments_key'],               // sk_test_….…  (בחי: sk_live_….…)
    'webhook_secret' => $secrets['payments_whsec'],  // whsec_….…
    'app' => 'guarded-browser',                      // המזהה שלכם — הודעה של מערכת אחרת נדחית
    'cache' => my_db(),                              // PDO — מטמון זכאות (טבלה payments_cache נוצרת לבד)
]);
```

אפליקציה שכבר חותמת ב-Ed25519 (כמו מול עורך התמלילים) — במקום `key`: ‏`client`, `env`, `ed_kid`, `ed_secret`, ובמסך
המערכת "כתובת מפתח Ed25519".

## 2. מי משלם — `external_id`

אתם בוחרים מזהה יציב לישות המשלמת: `user:17`, `family:12`, `company:4`, `org:9`. התוסף לא יודע מה זה — רק שזה אותו
לקוח בכל פעם. פותחים/מעדכנים פעם אחת (ובכל שינוי שם/מייל):

```php
$pay->customer('family:12', ['name' => 'משפחת כהן', 'email' => 'a@b.co', 'customer_type' => 'private']);
```

לעסק: `customer_type => 'business'`, `tax_id`, `billing_name` — כדי שהחשבונית תצא על שם העסק.

## 3. לגבות

```php
// מנוי
$c = $pay->checkout('family:12', 'subscription', [['product' => 'apps'], ['product' => 'extra_device', 'quantity' => 2]],
    'https://…/billing/return', 'https://…/billing');
header('Location: ' . $c['url']);  // דף התשלום של התוסף — ממותג בשם המערכת שלכם

// חזרה (return_url?checkout=chk_…) — לא סומכים על הכתובת, שואלים:
$st = $pay->checkoutStatus($_GET['checkout']);   // status: completed / open / expired

// קנייה לפי יחידה בלחיצה (כרטיס שמור); בלי כרטיס — מקבלים url לדף תשלום
$r = $pay->charge('org:9', [['product' => 'budget_year']], 'budget:org9:2027', $returnUrl, $cancelUrl);

// טעינת קרדיט
$c = $pay->checkout('user:7', 'topup', [], $returnUrl, $cancelUrl, ['amount' => 5000]);   // אגורות
```

הסכום תמיד מחושב בתוסף מהמחירים — אי אפשר לשלוח סכום משלכם (חוץ מטעינה, בטווח ₪5–₪5,000).

## 4. מה מותר עכשיו — זכאות

```php
$e = $pay->entitlement('family:12');
// active, state (trialing/active/past_due/…), plans, features, limits (devices: 5), balance (אגורות),
// can_spend, metered (used/included), purchased (קניות לפי יחידה), ttl
if (!$e['active']) { /* מסך "לשדרוג" */ }
if (count($devices) >= ($e['limits']['devices'] ?? 0)) { /* "הגעת למגבלה" */ }
```

- **בודקים בנקודות שבהן זה משנה** (פתיחת מסך בתשלום, הוספת מכשיר, שליחה ל-AI) — לא בכל בקשה. יש מטמון (`ttl`, 5 דקות).
- **התוסף לא עונה?** מקבלים את הערך האחרון (`stale: true`), ובלי ערך — `active: true` (ברירת מחדל `fail => 'open'`: לא
  חוסמים לקוח משלם בגלל תקלה אצלנו). לחסום במקום — `'fail' => 'closed'`.

## 5. שימוש מדוד (AI, SMS, …)

```php
// לפני הפעולה — שריון (בקרדיט מראש). אין יתרה → allowed:false → "נגמר הקרדיט" + כפתור טעינה
$r = $pay->reserve('user:7', 'ai_tokens', 8000);
if (!$r['allowed']) { /* … */ }
$used = call_claude(...);                          // בפועל 6,320
$pay->commit($r['reservation'], $used, "ai:log:$logId");

// או בלי שריון — אחרי שהשימוש קרה (נרשם תמיד; allowed:false — לעצור מכאן)
$pay->usage('company:4', 'active_employees', 23, 'company:4:2026-10');
```

**`idempotency_key` ייחודי לכל אירוע** (מזהה השורה ביומן שלכם) — שליחה כפולה לא תספור פעמיים. צ'אט ארוך: שריון גדול
אחד לשיחה ו-`commit` אחד בסוף.

## 6. "החשבון שלי" וביטול — חובה

```php
header('Location: ' . $pay->portalUrl('family:12', 'https://…/settings'));
```

**חוק הגנת הצרכן (§14ט):** אפליקציה שגובה מנוי חייבת **קישור בולט "ביטול מנוי" בדף הבית שלה** — שמוביל ל"החשבון שלי".
שם הלקוח מבטל בשני קליקים, מאשר חידוש שנתי, משלם חשבונית פתוחה ומחליף כרטיס.

## 7. הודעות מהתוסף (webhook)

```php
// api.php?action=payments_webhook — ב-"public" של app.json שלכם
try {
    $event = $pay->webhook();          // מאמת חתימה וזמן, או זורק
} catch (PaymentsError $e) { http_response_code(401); exit; }
if (already_seen($event['id'])) { http_response_code(200); exit; }   // id חוזר — להתעלם
switch ($event['type']) {
    case 'entitlement.changed':        // הכי חשוב: משהו בזכאות השתנה
        $e = $pay->entitlement($event['external_id'], true);
        apply_plan($event['external_id'], $e);     // למשל: accountExtend / level_max במגדלור
        break;
}
http_response_code(200);
```

אירועים: `checkout.completed` · `payment.succeeded|failed|refunded` · `subscription.created|updated|renewed|past_due|canceled|trial_ending`
· `invoice.paid` · `wallet.topped_up|low|empty` · `entitlement.changed` · `ping`.
**ההודעה היא רמז** — הסדר לא מובטח, ולפני החלטה קוראים `entitlement(..., true)`. עונים 2xx תוך 10 שניות; אחרת ניסיון
חוזר (דקה, 5, 30, שעתיים…). אפליקציה בלי כתובת ציבורית — רק `entitlement`.

## 8. בדיקות

- בסביבת בדיקות (`sk_test_…`) — הסולק המדומה (או סביבת הניסוי של מורנינג, אם חוברה). כרטיסי בדיקה: `4242 4242 4242 4242`
  עובר, `4000 0000 0000 0002` נדחה, `4000 0000 0000 0341` עובר ונדחה בחיוב הבא (לבדיקת חידוש שנכשל).
- בממשק הניהול, בכרטיס המערכת: יומן קריאות, הודעות שנשלחו, "שליחת בדיקה".
- `php payments/tests/sign.php <base> <sk> <action> '<json>'` — בקשה חתומה ידנית.

## 9. בלי קוד בכלל — קישור או כפתור

לאתר תדמית / דף נחיתה / וויקס: בכרטיס המערכת ← "+ קישור" (מוצר לפי יחידה, או סכום לבחירה בטווח). מקבלים קישור,
קוד לסריקה, וקוד כפתור להדבקה:

```html
<script src="https://mbe-plus.com/mini_projects/payments/assets/embed.js" async></script>
<a class="mbe-pay" href="https://mbe-plus.com/mini_projects/payments/pay/link.php?k=XXXXXXXXXX">לתשלום</a>
```

## 10. לא לעשות

- לא לשמור מחירים בקוד שלכם — `catalog()` אם צריך להציג מחירון.
- לא לסמוך על החזרה מדף התשלום או על משהו שהדפדפן אמר — רק `checkoutStatus` / `entitlement` / הודעה חתומה.
- לא לחסום משתמש כי webhook לא הגיע — `entitlement`.
- לא להכניס את הקוד הסודי לדפדפן, לאפליקציית טלפון או לגיט.
- לא לפתוח "משתמשים" אצלנו: הלקוח המשלם הוא הלקוח שלכם; התוסף מכיר אותו רק דרך `external_id`.
