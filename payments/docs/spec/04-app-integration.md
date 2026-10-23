# פרק 4 — ההתממשקות עם האפליקציות

**העיקרון:** כל אפליקציה מדברת עם התוסף כמו עם Stripe — שירות חיצוני, HTTP חתום, בלי `require` ובלי מסד
משותף. כך אפליקציה יכולה לצאת לדומיין משלה ולהמשיך לגבות, והתוסף יכול לצאת לדומיין משלו (`pay.mbe-plus.com`)
בלי שינוי באפליקציות — רק `base_url` ב-SDK.

```
┌────────────── אפליקציה ──────────────┐            ┌──────────── תוסף התשלומים ────────────┐
│  קוד האפליקציה                        │  API חתום  │  api.php      (Ed25519, idempotency)   │
│    └─ payments-client.php (SDK) ─────┼───────────▶│  pay/         דף תשלום (ציבורי)        │
│  api.php?action=payments_webhook ◀───┼────────────│  portal/      "החשבון שלי" (ציבורי)    │
│  api.php?action=embed_key  (מפתח)  ◀─┼── מושך ────│  hooks/<סולק> webhooks נכנסים          │
└──────────────────────────────────────┘  webhook   │  index.html   ממשק המנהל (שער)         │
         ▲  return_url                     חתום     └────────────────────────────────────────┘
         │                                                      │
   דפדפן הלקוח ──── checkout url ────▶ pay/?c=… ──▶ דף/iframe של הסולק
```

## 4.1 רישום אפליקציה

זהה לעורך התמלילים (`platform/docs/embed.md` §1), כי שלוש אפליקציות כבר מכירות אותו:

1. האפליקציה יוצרת זוג Ed25519 **במסד שלה** (פעם אחת). מי שכבר יש לה (הצעות תקציב, מגדלור) — משתמשת באותו
   זוג.
2. מפרסמת את המפתח הציבורי ב-`<app>/api.php?action=embed_key` (ב-`"public"` שלה).
3. קוראת `app_register` (לא חתום — המפתח עוד לא ידוע):

```
POST payments/api.php?action=app_register
{"app":"guarded-browser","name":"מגדלור",
 "key_url":"https://mbe-plus.com/mini_projects/guarded-browser/api.php?action=embed_key",
 "webhook_url":"https://mbe-plus.com/mini_projects/guarded-browser/api.php?action=payments_webhook",
 "origins":["https://mbe-plus.com","https://migdalor.mbe-plus.com"]}
→ {"status":"pending","payments_key_url":"https://mbe-plus.com/mini_projects/payments/api.php?action=key"}
```

- **תמיד `pending`** עד שהמנהל מאשר (לשונית "אפליקציות"). שונה מהעורך — כי כאן זה כסף.
- התוסף מושך את המפתח מ-`key_url` בעצמו; `key_url` ו-`webhook_url` חייבים https תחת `origins`.
- רישום חוזר זהה — בלי שינוי. שינוי ב-`key_url`/`origins` → חוזר ל-`pending`.
- `payments_key_url` — המפתח הציבורי של התוסף, לאימות webhooks (4.5).

## 4.2 חתימה

בדיוק `embed.md` §2, כדי שאותו קוד ישרת את שני השימושים:

```
X-Embed-Client:    guarded-browser
X-Embed-Time:      1761220000
X-Embed-Nonce:     <32 hex>
X-Embed-Signature: <kid>.<base64(Ed25519(action \n time \n nonce \n sha256hex(body)))>
Idempotency-Key:   <עד 64 תווים>      ← בפעולות יוצרות
```

דחייה: 401 `{"code":"bad_signature|stale|replay|unknown_client|pending|disabled"}`. ±300 שניות; nonce נשמר 10
דקות; חתימה שנכשלת → משיכה אחת מחדש של המפתח (החלפת מפתח) ואז דחייה.

**הרשאה:** אפליקציה רואה **רק** לקוחות, מוצרים ומנויים שלה. מזהה של אפליקציה אחרת → 404 (לא 403 — לא מגלים
שקיים).

## 4.3 ה-API

`POST payments/api.php?action=<פעולה>`, גוף JSON, תשובה JSON. שגיאה: `{"error":"<עברית>","code":"..."}`.
סכומים באגורות. זמנים — שניות UTC. כל רשימה — `{data:[…], next?}` (cursor).

### לקוחות

| פעולה | גוף | תשובה |
| --- | --- | --- |
| `customer_upsert` | `{external_id, name, email, email_verified?, customer_type?, tax_id?, billing_name?}` | `{customer, account}` |
| `customer_get` | `{external_id}` | `{customer, account:{…, balance, methods:[{id,kind,brand,last4,exp}]}}` |
| `customer_delete` | `{external_id}` | `{ok}` — אנונימיזציה; מסמכים חשבונאיים נשמרים |

### קטלוג (קריאה; העריכה — במסך המנהל)

| פעולה | גוף | תשובה |
| --- | --- | --- |
| `catalog` | `{}` | `{products:[{code,name,type,prices:[…]}]}` — כדי להציג מחירון באפליקציה בלי לשכפל מחירים |

### תשלום ומנוי

| פעולה | גוף | תשובה |
| --- | --- | --- |
| `checkout_create` | `{external_id, mode:"payment"\|"subscription"\|"setup"\|"topup", items?:[{product,price?,qty}], amount?, coupon?, return_url, cancel_url, methods?:["card","bit",…], installments_max?, meta?}` | `{checkout, url, expires_at}` |
| `checkout_get` | `{checkout}` | `{status, payment?, subscription?}` |
| `subscription_list` | `{external_id}` | `{data:[{id,status,items,period_end,cancel_at}]}` |
| `subscription_update` | `{subscription, items:[{product,qty}], proration:"now"\|"period_end"\|"none"}` | `{subscription, invoice?}` |
| `subscription_cancel` | `{subscription, at:"period_end"\|"now", reason?}` | `{subscription}` |
| `subscription_resume` | `{subscription}` | `{subscription}` |
| `charge` | `{external_id, items:[{product,qty}], description, confirm:"saved_method"}` | `{payment, status}` — חיוב מיידי מאמצעי שמור (off-session). נכשל → `requires_action` + `url` |

### שימוש וזכאות

| פעולה | גוף | תשובה |
| --- | --- | --- |
| `usage_record` | `{external_id, product, quantity, idempotency_key, occurred_at?, meta?}` | `{event, allowed, balance?, included_left?}` |
| `usage_batch` | `{events:[…]}` עד 500 | `{accepted, duplicates, rejected:[{i,code}]}` |
| `usage_reserve` | `{external_id, product, estimate, ttl?}` | `{reservation, allowed, balance}` |
| `usage_commit` | `{reservation, quantity, idempotency_key}` | `{event, balance}` — שחרור ההפרש |
| `usage_release` | `{reservation}` | `{ok}` |
| `usage_summary` | `{external_id, product?, period?}` | `{data:[{product, quantity, included, billable, amount}]}` |
| `entitlement` | `{external_id}` | ראו 4.4 |

### חשבון הלקוח

| פעולה | גוף | תשובה |
| --- | --- | --- |
| `portal_session` | `{external_id, return_url, section?}` | `{url}` — חד-פעמי, 15 דקות |
| `invoice_list` | `{external_id, after?}` | `{data:[{id,number,total,status,doc_url}]}` |
| `wallet_get` | `{external_id}` | `{balance, auto_topup, recent:[…]}` |

### קודי שגיאה עיקריים

`not_found` · `invalid` · `pending` · `insufficient_balance` · `no_payment_method` · `card_declined` ·
`requires_action` · `idempotency_mismatch` · `rate_limited` · `provider_down`

## 4.4 זכאות — מה מותר עכשיו

```json
POST ?action=entitlement  {"external_id":"family:12"}
→ {
  "active": true,
  "state": "active",                    // trialing|active|past_due|paused|canceled|none
  "grace_until": null,
  "plans": ["migdalor_apps"],
  "features": {"migdalor_apps": true, "extra_device": 2},
  "limits":   {"devices": 5, "children": 3, "storage_bytes": 2147483648},
  "balance":  1840,                     // אגורות, אם יש ארנק
  "metered":  {"ai_tokens": {"used": 412000, "included": 200000}},
  "until": 1764547200,
  "ttl": 300                            // כמה שניות מותר לשמור במטמון
}
```

- `features` ו-`limits` מוגדרים **במוצר** (במסך המנהל): כל מוצר יכול לשאת `grants: {"limits":{"devices":3}}`.
  הזכאות היא איחוד כל המוצרים הפעילים. כך האפליקציה לא מתחזקת טבלת "מה כל תכנית נותנת".
- **מטמון באפליקציה:** ה-SDK שומר את התשובה ל-`ttl` שניות (טבלה קטנה אצל האפליקציה), ומנקה אותה בכל webhook.
- **התוסף לא עונה:** ה-SDK מחזיר את הערך האחרון במטמון גם אם פג (עד 24 שעות), עם `stale:true`. אין ערך בכלל
  → ❓ `fail_open` (ברירת מחדל — לא חוסמים לקוח משלם בגלל תקלה אצלנו) או `fail_closed`, לפי הגדרה באפליקציה.

## 4.5 webhooks מהתוסף לאפליקציה

```
POST <webhook_url>
X-Payments-Event:     evt_…
X-Payments-Time:      1761220000
X-Payments-Signature: <kid>.<base64(Ed25519("webhook\n" time "\n" evt_id "\n" sha256hex(body)))>

{"id":"evt_…","type":"subscription.updated","created":1761220000,"app":"guarded-browser",
 "external_id":"family:12","data":{…}}
```

| אירוע | מתי |
| --- | --- |
| `checkout.completed` / `checkout.expired` | הלקוח סיים / נטש |
| `payment.succeeded` / `payment.failed` / `payment.refunded` | |
| `subscription.created` / `.updated` / `.renewed` / `.past_due` / `.canceled` / `.trial_ending` (3 ימים לפני) | |
| `invoice.issued` / `invoice.paid` / `invoice.uncollectible` | |
| `wallet.topped_up` / `wallet.low` / `wallet.empty` | |
| `entitlement.changed` | **האירוע שכל אפליקציה צריכה** — משהו בזכאות השתנה; לנקות מטמון ולקרוא שוב |

- האפליקציה מאמתת חתימה (המפתח מ-`payments_key_url`, במטמון), ±300 שניות, ו-`id` שלא נראה.
- מחזירה 2xx תוך 10 שניות. אחרת: ניסיונות אחרי 1, 5, 30 דקות, 2, 6, 12, 24 שעות; אחר כך — `failed` והתראה למנהל.
- **הסדר לא מובטח, והאירוע הוא רמז.** החלטה חשובה → `entitlement` טרי.
- אפליקציה בלי כתובת ציבורית (אופליין) — רק משיכה (`entitlement`), בלי webhooks.

## 4.6 זרימות טיפוסיות

### א. מנוי ראשון (מגדלור)

```
הורה ──"לשדרוג"──▶ מגדלור: customer_upsert(family:12) → checkout_create(mode:subscription, items:[migdalor_apps])
                        ◀─ url
הורה ──redirect──▶ payments/pay/?c=chk_…  (סיכום, פרטי חשבונית, בחירת אמצעי)
                        └─▶ דף הסולק (iframe/redirect) — הקלדת כרטיס, 3DS
הסולק ──webhook──▶ payments/hooks/<סולק>.php  → אימות + שאילתה לסולק → payment succeeded,
                        token נשמר, subscription active, מסמך חשבונאי, evt checkout.completed
הורה ◀──redirect── return_url?checkout=chk_…  → מגדלור: checkout_get (לא סומכת על ה-redirect) → "תודה"
מגדלור ◀──webhook── entitlement.changed → accountExtend / level_max
```

### ב. AI בקרדיט (מתכונים)

```
משתמש "שכתב מתכון" ─▶ מתכונים: usage_reserve(user:7, ai_tokens, estimate:8000)
                         ◀─ allowed:true, reservation
                      קריאה ל-Claude … בפועל 6,320 טוקנים
                      usage_commit(reservation, 6320, idempotency_key:"ai:log:99812")
                         ◀─ balance:1702
allowed:false → המסך מציג "נגמר הקרדיט" + כפתור טעינה (checkout_create mode:topup)
```

כדי לא לקרוא לתוסף בכל הודעה בצ'אט ארוך — אפשר **שריון גדול** (למשל 50,000) לכל השיחה ו-commit אחד בסוף.

### ג. שימוש בדיעבד (שעון נוכחות)

```
1 בחודש 02:00 — cron באפליקציה: לכל חברה usage_record(company:4, active_employees, 23,
                 idempotency_key:"company:4:2026-10", aggregate max)
1 בחודש 04:00 — cron בתוסף: חשבונית לכל מנוי שהמחזור שלו נגמר → חיוב מטוקן → מסמך → webhook
```

### ד. קנייה לפי יחידה (הצעות תקציב)

`checkout_create(mode:payment, items:[{product:"budget_year", qty:1}], meta:{year:2027})` → אחרי תשלום,
`entitlement.features.budget_year` מכיל את 2027. ללקוח עם כרטיס שמור — `charge` בלחיצה אחת, בלי דף.

## 4.7 דפים מתארחים — ציבוריים

| כתובת | מה | אבטחה |
| --- | --- | --- |
| `pay/?c=<checkout>` | דף התשלום (פרק 8.2) | `checkout` אקראי, חד-פעמי, פג תוך שעה. אין בו פרטים מעבר לשם וסכום |
| `pay/return.php` | נחיתה מהסולק → מאמתת מול הסולק → מפנה ל-`return_url` | לא משנה מצב בלי אימות מהסולק |
| `portal/?s=<session>` | "החשבון שלי" (פרק 8.3) | סשן חד-פעמי מ-`portal_session`, עוגייה משלנו (`payments_portal`), 30 דקות |
| `hooks/<provider>.php` | webhooks של הסולקים | אימות לפי הסולק + שאילתה חוזרת (פרק 5) |
| `api.php` | ה-API | חתימה |
| `api.php?action=key` | המפתח הציבורי של התוסף | פתוח |
| `doc/?d=<token>` | הורדת מסמך חשבונאי ללקוח (קישור במייל) | טוקן חתום, ללא תפוגה (מסמך חשבונאי) ❓ |

כל אלה ב-`"public"` של `payments/app.json`. כל השאר מאחורי השער — רק המנהל.

**מיתוג:** דף התשלום מציג את שם האפליקציה והלוגו שלה (`app.brand`: שם, צבע, לוגו) — ללקוח זה נראה כחלק
מהאפליקציה, לא כ"כלים הדיגיטליים". זה חשוב לעיקרון "אפליקציה עצמאית".

## 4.8 ספריית הלקוח (SDK)

`payments/sdk/PaymentsClient.php` — קובץ אחד, PHP 8, בלי תלויות (sodium + curl בלבד):

```php
$pay = new PaymentsClient([
  'base_url' => 'https://mbe-plus.com/mini_projects/payments/',
  'app'      => 'guarded-browser',
  'keys'     => fn() => my_load_keypair(),      // מהמסד של האפליקציה
  'cache'    => fn() => my_db(),                 // PDO — טבלת payments_cache נוצרת לבד
  'fail'     => 'open',
]);

$pay->customer('family:12', ['name'=>'משפחת כהן','email'=>'a@b.co']);
$url = $pay->checkout('family:12', ['mode'=>'subscription','items'=>[['product'=>'migdalor_apps']],
                                    'return_url'=>…, 'cancel_url'=>…]);
$e   = $pay->entitlement('family:12');           // עם מטמון
$pay->usage('user:7', 'ai_tokens', 6320, "ai:log:99812");
$r   = $pay->reserve('user:7', 'ai_tokens', 8000);  $r->commit(6320);

// ב-webhook של האפליקציה:
$event = $pay->webhook();                        // מאמת חתימה, זמן, כפילות — או זורק
```

- **כלי JS קטן** (`payments/sdk/payments.js`) — רק UI: כפתור "לתשלום" שקורא לשרת של האפליקציה ומפנה; רכיב
  "יתרה" ו"שדרוג". **הדפדפן לעולם לא מדבר עם ה-API החתום.**
- **הפצה:** כמו DevLoop — מקור אחד ב-`payments/sdk/`, עותק בכל אפליקציה (`lib/PaymentsClient.php`). ❓ לצרף ל-
  `devloop/tools/sync.php`-סגנון עם שער בפריסה — דורש בקשה לצ'אט הפלטפורמה (`payments/docs/requests/`).
  בינתיים: מספר גרסה בראש הקובץ, ו-`api.php?action=sdk_version` מחזיר את העדכנית — האפליקציה מתריעה ביומן.
- **אפליקציית APK:** שום דבר לא משתנה — התשלום הוא דף ווב (הסולק). ❗ **Google Play** דורש Google Play Billing
  לתוכן דיגיטלי שנמכר בתוך אפליקציה מהחנות; האפליקציות שלנו מופצות כ-APK ישיר/TWA — ❓ לבדוק לכל אפליקציה
  שתעלה לחנות (פרק 10.6).

## 4.9 `connect.md` — המדריך לצ'אט של אפליקציה

ייכתב בשלב 5 (פרק 11). התוכן: (1) רישום + מפתחות, (2) העתקת ה-SDK, (3) `external_id` — מי הישות המשלמת,
(4) הגדרת מוצרים במסך המנהל (בעל המערכת), (5) כפתור תשלום, (6) webhook, (7) זכאות ובדיקתה בנקודות הנכונות,
(8) מדידת שימוש, (9) בדיקה בסביבת test, (10) רשימת "לא לעשות" (לא לשמור מחירים בקוד, לא לסמוך על
redirect, לא לחסום על webhook שלא הגיע).

## 4.10 סביבות

- כל אפליקציה רשומה **פעמיים**: `test` ו-`live` (מצב לפי מפתח: `X-Embed-Client: guarded-browser` / `guarded-browser~test`).
  ב-test — סולק בסביבת בדיקות, מסמכים חשבונאיים "טיוטה", ואין חיוב אמיתי.
- ספק `fake` (פרק 5.4) — מאפשר לבנות ולבדוק אפליקציה בלי שום סולק.
