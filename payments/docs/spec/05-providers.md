# פרק 5 — חברות הסליקה: מחברים (connectors)

> מקורות: מחקר מ-2026-10-23 (רשימה בסוף הפרק). מקרא אמינות: ✅ נקרא בתיעוד/מחירון רשמי · 🟡 עמוד רשמי אבל
> שיווקי/חלקי · 🔴 משווק משנה, בלוג, או לא אומת. **כל מחיר — לאמת בהצעה בכתב לפני חתימה.** המחירים לפני מע"מ.

## 5.1 העיקרון: סולק = מחבר, והליבה שלנו

**הליבה שלנו מנהלת את החיוב** — מנויים, מדידה, ארנק, חשבוניות, ניסיונות חוזרים. **הסולק משמש רק
כ"כספת טוקנים + ביצוע חיוב"**: הלקוח מקליד כרטיס בדף/iframe של הסולק, אנחנו מקבלים טוקן, ומחייבים ממנו
כשצריך (MIT — merchant-initiated, עם דגל "הוראת קבע").

**למה לא מנוע המנויים של הסולק:** הוא יודע לגבות **סכום קבוע** בלבד (Hyp אומרים זאת במפורש ✅). שימוש מדוד,
יחסי, ארנק, חבילה בין אפליקציות — לא נתמכים, וכל סולק עושה את זה אחרת. ככה גם החלפת סולק לא משנה את
המנויים — רק את המחבר.

```
                          ┌──────────── Provider (ממשק) ─────────────┐
 ליבת החיוב ───────────▶ │ createSession · verifyReturn · webhook     │
                          │ query · chargeToken · refund · void       │
                          │ tokenize · capabilities · reconcile       │
                          └───┬────────┬────────┬────────┬─────────┬──┘
                           PayPlus  Cardcom  Grow/Morning  Tranzila  Fake …
```

## 5.2 הממשק

```php
interface Provider {
  // מה הוא יודע: אמצעים, מטבעות, תשלומים, טוקן, החזר, בנק, חתימת webhook
  public function capabilities(): Capabilities;

  // דף תשלום מתארח / iframe. mode: payment | setup (רק טוקן) | payment+token
  public function createSession(Checkout $c, array $methods): Session;   // → url, ref, iframe?

  // הלקוח חזר (redirect) — לא סומכים; מחזיר רק ref לשאילתה
  public function parseReturn(array $query): ?string;

  // webhook נכנס — אימות (חתימה / סוד / IP) + חילוץ ref ומזהה אירוע
  public function parseWebhook(Request $r): ProviderEvent;

  // מקור האמת: שאילתה יזומה לסולק על עסקה
  public function query(string $ref): Txn;                // status, amount, currency, token?, card meta

  // חיוב יזום מטוקן (חידוש, יחידה, טעינה אוטומטית) — עם idempotency
  public function chargeToken(Token $t, Money $m, string $idemKey, array $meta): Txn;

  public function refund(Txn $t, Money $m, string $idemKey): Refund;
  public function void(Txn $t): void;                     // ביטול באותו יום, לפני שידור

  // רשימת עסקאות לטווח — להתאמה (פרק 9.5). null = לא נתמך
  public function listTransactions(Range $r): ?iterable;
}
```

**כללים לכל מחבר:**

1. **לעולם לא סומכים על callback בלבד.** אם יש חתימה — מאמתים (PayPlus, Allpay, Morning). תמיד — `query()`
   מהשרת לפני שינוי מצב (Cardcom `GetLpResult`, Grow `approveTransaction`, Hyp `VERIFY`, iCredit `Verify`).
2. **הסכום והמטבע נבדקים מול ה-`checkout` שלנו.** Tranzila Handshake / Grow — נעילת סכום מראש בצד הסולק.
3. **idempotency** — מפתח ייחודי לכל ניסיון חיוב; webhook כפול (Grow מנסה שוב אחרי 10/20/30 דקות) לא מחייב פעמיים.
4. **מיפוי קודי סירוב** לקטגוריות שלנו: `soft` (לנסות שוב), `hard` (לבקש אמצעי אחר), `fraud`, `expired`,
   `insufficient`, `3ds_required`. הודעה ללקוח בעברית.
5. **אין מספרי כרטיס, CVV או ת"ז מלאה** בשום לוג, גם לא בדיבאג. רק 4 ספרות ותוקף.
6. כל מחבר בקובץ אחד: `payments/lib/providers/<name>.php` + בדיקות מול sandbox.

## 5.3 כמה סולקים במקביל

- `provider_account` — כמה חיבורים פעילים יחד, עם **עדיפות לכל אמצעי** (למשל: כרטיס → PayPlus; bit → Grow;
  PayPal → PayPal).
- **טוקן שייך לסולק שיצר אותו.** חיוב חוזר תמיד דרך אותו `provider_account`. מעבר סולק = הלקוח מעדכן אמצעי
  (או ייבוא טוקנים — רק אם הסולקים תומכים בהעברה; ❓ לשאול כל סולק).
- **גיבוי (failover):** רק לתשלום חדש (דף תשלום) — אם הסולק הראשי לא עונה, מציגים את השני. לא לחיוב חוזר.
- **סולק לכל אפליקציה (אופציה):** `app.provider_account` — אפליקציה שתצא לעצמאות יכולה לקבל מסוף משלה מראש,
  כך שהטוקנים וההיסטוריה שלה כבר שם (פרק 10.7).

## 5.4 מחבר `fake` — לפיתוח

סולק מדומה מלא בתוך התוסף: דף "כרטיס" עם כרטיסי בדיקה (`4242…` מצליח, `4000…0002` נדחה, `…3220` 3DS),
טוקנים, webhooks, החזרים, השהיה. **כל האפליקציות נבנות ונבדקות מולו** לפני שיש הסכם עם סולק אמיתי. פעיל
רק ב-`test`.

## 5.5 המפה — כל הסולקים

### א. סולקים ישראליים עם API משלהם (שער — לרוב עם הסכם סליקה נפרד מול חברות האשראי)

| | **PayPlus** | **Cardcom** | **Tranzila** | **Hyp** (קרדיטגארד/יעד שריג) | **Pelecard** | **iCredit** (ריווחית) | **Z-Credit** | **EasyCard** |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| הסכם סליקה | הסכם משלך או אגרגטור 🟡 | **"הסכם אחד" — הכול כלול**, רישיון "קארדקום סליקה" 🟡 | לרוב הסכם משלך 🔴 | **חובה הסכם נפרד** + מספר ספק אינטרנטי ✅ | הסכם משלך 🔴 | 🔴 | 🔴 | 🔴 |
| עלות (סדר גודל) | לא מפורסם; משווקים: ~195 ₪ הקמה, 59 ₪/ח', ~1.2% 🔴 | לא מפורסם; משווקים: 49 ₪/ח', ~0.9–1.2% 🔴 | מ-29 ₪/ח' 🟡 + מודולים (טוקן, My Billing) 🔴 | לפי הצעה | 🔴 | 🔴 | 🔴 | 🔴 |
| דף מתארח | Payment Pages `generateLink` (iframe/redirect) + Hosted Fields ✅ | **LowProfile** (iframe/redirect, API v11) ✅ | **DirectNG iframe** + Hosted Fields, Handshake נועל סכום ✅ | Hyp Pay ✅ | PaymentGW iframe 🔴 | `GetUrl` ✅ | WebCheckout 🔴 | iframe 🔴 |
| טוקן + חיוב יזום | ✅ | `ChargeAndCreateToken` → `Transaction` ✅ | מודול טוקן (בתשלום) ✅ | ✅ | 🔴 | `SaleChargeToken` ✅ | 🔴 | 🔴 |
| מנוע מנויים משלו | RecurringPayments ✅ | הוראות קבע ✅ | My Billing (גם הצמדה למדד) ✅ | קבוע בלבד ✅ | 🔴 | ✅ | 🔴 | 🔴 |
| הוראת קבע בנקאית (מס"ב) | ✅ | 🟡 | **MASAV API** ✅ | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |
| bit / Apple / Google Pay | ✅/✅/✅ 🟡 | ✅/✅/✅ 🟡 | ✅/✅/✅ + PayPal ✅ | ✅/✅/✅ ✅ | + בנקאות פתוחה 🟡 | Apple/Google ✅ | bit 🟡 | bit 🔴 |
| **אימות webhook** | **HMAC-SHA256 על הגוף** (כותרת `hash`) ✅ | **בלי חתימה** → שאילתת `GetLpResult` 🟡 | בלי חתימה ב-notify (V2 — HMAC) → Handshake + שאילתה ✅ | `Sign` + קריאת `VERIFY` ✅ | `GetTransaction` 🔴 | IPN + `Verify` + IP ✅ | 🔴 | 🔴 |
| החזר ב-API | ✅ | ✅ | 🟡 | ✅ | 🔴 | ✅ | 🔴 | 🔴 |
| מטבעות | ₪ + מט"ח 🔴 | ₪, $, ISO ✅ | ₪ $ € ✅ | 🟡 | | | | 🔴 |
| מסמכים חשבונאיים | **Invoice+**, מספר הקצאה 🟡 | מודול מסמכים מלא ✅ | מסמכים 🟡 | **Hyp Invoice** (לשעבר EZcount) ✅ | 🔴 | ריווחית — כולל מספר הקצאה ✅ | 🔴 | חיבור לתוכנות 🔴 |
| תיעוד + sandbox | docs.payplus.co.il, staging, כרטיסי בדיקה ✅ — **טוב** | Swagger v11 ✅ — טוב | docs.tranzila.com (OpenAPI) ✅ | developers.hyp.co.il ✅ — טוב | בעיקר צד ג' 🔴 | rivhit-api.readme.io ✅ | 🔴 | 🔴 |

### ב. "הכול כלול" — בלי הסכם סליקה נפרד (אגרגטורים / חברות תשלום)

| | **Grow** (משולם) | **Morning — סליקה** (על Grow) | **Allpay** | **Upay** (דרך SUMIT) | **PayMe** | **SUMIT** |
| --- | --- | --- | --- | --- | --- | --- |
| פטור/מורשה | שניהם ✅ | שניהם ✅ | 🔴 | **פטור מתקבל** ✅ | 🔴 | שניהם ✅ |
| עלות | **0.75% + 59 ₪/ח'** (שנתי) / 69 ₪; כולל 100 עסקאות ✅. **Light: 1% + 29 ₪** עד 20 עסקאות ✅. bit/PayBox בלי תוספת ✅ | **1.4% + 1.2 ₪ לעסקה** (Best) עד 0.95% בנפח; bit +0.3%; תייר 3.5% ✅ + מנוי Morning | **50 ₪/ח', 1.4%→1.2% + 1 ₪** ✅ | **1.1%**, bit 1.6%, בלי הקמה ✅ | 🔴 | חינם עד 10 פעולות 🟡 |
| דף מתארח | `createPaymentProcess` ✅ | `POST /payments/form` ✅ | ✅ | ✅ | ✅ | `beginredirect` ✅ |
| טוקן + חיוב יזום | ✅ + recurring premium ✅ | `tokens/{id}/charge` (Best+) ✅ | ✅ | | `buyer_key` ✅ | ✅ |
| bit / Apple / Google / PayBox / העברה בנקאית | **כולם** ✅ | bit, Apple, Google ✅ | ✅ | bit ✅ | Apple ✅ + רבים | 🔴 |
| הוראת קבע בנקאית | 🔴 | 🔴 | 🔴 | | | **מס"ב** ✅ |
| **אימות webhook** | **בלי חתימה** → חובה `approveTransaction` + IP + שאילתה ✅ | מסמכים: HMAC ✅; `notifyUrl` של תשלום — לא מתועד → שאילתה ✅ | **SHA256 על פרמטרים ממוינים** ✅ | | 🔴 | 🔴 |
| החזר ב-API | ✅ (כולל ביטול bit) | **אין** (רק בממשק) ✅ | 🟡 | | 🟡 | 🟡 |
| מטבעות | 🔴 | **₪ בלבד** ✅ | ₪ $ ✅ | ₪ $ ✅ | 120+ 🟡 | |
| מסמכים | מודול 🟡 | **אוטומטי לכל תשלום**, מספר הקצאה ✅ | דרך EasyCount ✅ | SUMIT | 🔴 | הנה"ח מלאה 🔴 |

### ג. Merchant of Record — הם המוכרים, אנחנו הספק שלהם

| | **Paddle** | **Lemon Squeezy** | **FastSpring** | **2Checkout/Verifone** |
| --- | --- | --- | --- | --- |
| מוכר ישראלי | ישראל לא ברשימת החסומים 🟡; לא אושר במפורש 🔴 | תשלום לבנק ישראלי ✅; עוברים ל-Stripe Managed Payments (ישראל לא שם) — **סיכון** ✅ | 🔴 | 🔴 (חיבור Invoice4U ✅) |
| עלות | **5% + 50¢** ✅ | 5% + 50¢ + תוספות 🔴 | הצעה 🔴 | 🔴 |
| מע"מ וחשבונית ישראלית | **ישראל לא ברשימת המדינות שהם גובים בהן מע"מ** ✅ → **אין חשבונית מס ללקוח ישראלי** | כנ"ל 🔴 | 🔴 | 🟡 |

**שורה תחתונה:** MoR — רק ללקוחות **בחו"ל**, ורק אחרי רו"ח. לא פתרון ללקוח ישראלי.

### ד. בינלאומיים

| | זמין לעוסק ישראלי? | הערות |
| --- | --- | --- |
| **Stripe** | **לא.** ישראל לא ברשימת 51 המדינות ✅; גלובס 20.10.2026: "עדיין לא זמין", מגייסים מנכ"ל לישראל ✅ | לעקוף דרך LLC בארה"ב — לא רשמי, סיכון הקפאה. **לעקוב** — אם ייפתח, מחבר אחד נוסף |
| **PayPal** | כן ✅ | **3.4% + 1.2 ₪**, +1–2% בינ"ל, **+18% מע"מ על העמלה** ✅. מנויים, החזרים, webhooks מאומתים ✅. החשבונית — עלינו |
| **BlueSnap** | **סליקה מקומית בישראל מ-2021** ✅ | ממוקד בינוניים; קליטת קטנים 🔴 |
| **Rapyd** | מחובר לשב"א ✅ | ארגוני 🔴 |
| Adyen · Checkout.com · Braintree · Payoneer Checkout | אין עדות לקליטת עוסק ישראלי 🔴 | לא מעשי |
| **Wise** | העברות בנקאיות בלבד ✅ | ללקוחות B2B בחו"ל (חשבונית גדולה) |

### ה. עוד שמות שעלו (לא נבדקו לעומק 🔴)

Kesher (גבייה לקהילות/עמותות) · Takbull · **ישראכרט "תשלום רשת"** (1.2%, 19.90 ₪/ח' לקישורי תשלום — קטע
חיפוש בלבד) · Max / כאל — לא נמצא מוצר דף תשלום אונליין רשמי · Pepper Pay — האפליקציה נסגרה.

## 5.6 כרטיס מחבר — מה כל אחד דורש מאיתנו

| מחבר | דף | אישור תשלום | חיוב חוזר | החזר | התאמה | מורכבות |
| --- | --- | --- | --- | --- | --- | --- |
| **PayPlus** | `generateLink` (iframe) | HMAC על ה-callback + `query` | token charge | `RefundByTransactionUID` | API | נמוכה |
| **Morning/Grow** | `payments/form` | `query` (ה-notify לא חתום) | `tokens/{id}/charge` | **ידני בממשק Morning** | דוח/API | נמוכה |
| **Grow ישיר** | `createPaymentProcess` | **`approveTransaction`** + IP + query | token / recurring premium | API (+ ביטול bit) | API | בינונית |
| **Cardcom** | LowProfile | `GetLpResult` | `Transaction` עם טוקן | `RefundByTransactionId` | API | בינונית |
| **Tranzila** | DirectNG + Handshake | שאילתה | מודול טוקן | 🟡 | 🟡 | בינונית |
| **Hyp** | Hyp Pay | `VERIFY` | טוקן | `refundDeal` | ✅ | בינונית |
| **iCredit** | `GetUrl` | `Verify` + IP | `SaleChargeToken` | ✅ | ✅ | בינונית |
| **Allpay** | API | חתימת SHA256 | טוקן | 🟡 | 🟡 | נמוכה |
| **PayPal** | Orders / Subscriptions | verify-signature API | vault / subscriptions | ✅ | ✅ | בינונית |

## 5.7 ההמלצה

| מצב | המלצה | למה |
| --- | --- | --- |
| **עוסק פטור / התחלה פשוטה** | **Morning (Best, 45 ₪/ח') + הסליקה המובנית (Grow), 1.4% + 1.2 ₪** | בלי הסכם סליקה ובלי דמי מסוף; טוקן וחיוב יזום ב-API; **כל תשלום מפיק לבד את המסמך הנכון** (קבלה לפטור, 320 למורשה), מספר הקצאה; webhooks של מסמכים חתומים. חסרונות: ₪ בלבד, **אין החזר ב-API**, ה-notify לא חתום (→ שאילתה) |
| **עוסק מורשה / חברה, נפח עולה** | **PayPlus** | ה-webhook **חתום ב-HMAC**, sandbox טוב, טוקן, מנויים, החזר ב-API, מס"ב, Invoice+ עם מספר הקצאה, bit/Apple/Google. חסרון: מחיר לא מפורסם — הצעה בכתב |
| **נפח גבוה, מחיר** | **Grow ישיר** (0.75% + 59 ₪) | הזול ביותר שנמצא; callback לא חתום → `approveTransaction` + שאילתה |
| **גם אלה טובים** | Cardcom (הכול כלול, מסמכים מובנים), Hyp (תיעוד מצוין, דורש הסכם), iCount (אם רוצים שהנה"ח תנהל גם גבייה) | |
| **לקוחות בחו"ל** | PayPal (ובהמשך Paddle אחרי רו"ח) | |

**ובכל מקרה:** המחבר `fake` נכתב ראשון, ואחריו **שני מחברים** אמיתיים מאותו ממשק (ההמלצה: Morning + PayPlus) —
כדי לוודא שהממשק באמת כללי ולא תפור לסולק אחד.

## 5.8 שאלות לשאול כל סולק (בכתב)

1. מחיר אמיתי לעוסק פטור/מורשה בנפח נמוך; מינימום חודשי; התחייבות ודמי יציאה.
2. **ייצוא/העברת טוקנים** לסולק אחר (ניידות — פרק 10.7).
3. האם הכרטיס שמאחורי Apple Pay / Google Pay נשמר לחיוב יזום.
4. חתימה על ה-callbacks; רשימת IP.
5. bit: תקרה לעסקה; החזר ב-API.
6. דגל "הוראת קבע" בחיוב טוקן (חוק כרטיסי חיוב §10ב — פרק 10).
7. דוח הפקדות (settlement) ב-API — להתאמה.

## מקורות

PayPlus: docs.payplus.co.il (llms.txt, validate-requests) · Cardcom: secure.cardcom.solutions/swagger/v11 ·
Tranzila: docs.tranzila.com (sto-api-v2, handshake-v2) · Hyp: developers.hyp.co.il · iCredit: rivhit-api.readme.io ·
Grow: developers.grow.business, grow.business/price-offer · Morning: developers.morning.co, greeninvoice.co.il/pricing ·
Allpay: allpay.co.il/en/pricing, /help/webhooks · Upay/SUMIT: intercom.help/sumit/he/articles/5832995, api.sumit.co.il ·
PayMe: docs.payme.io · Pelecard: pelecard.com/בנקאות-פתוחה · Stripe: stripe.com/global, en.globes.co.il (1001560134) ·
PayPal: paypal.com/il/business/paypal-business-fees · Paddle: developer.paddle.com (supported-countries), paddle.com/help (VAT) ·
Lemon Squeezy: docs.lemonsqueezy.com, lemonsqueezy.com/blog/2026-update · BlueSnap: bluesnap.com/press-media ·
Rapyd: docs.rapyd.net/en/integration_with_shva.html · משווקים/בלוגים 🔴: invoice4u.co.il/pricelist-credit,
autoflowr.co.il, adircpa.com, lastartup.co.il, shopmaster.co.il.
