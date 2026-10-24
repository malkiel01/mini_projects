# פרק 3 — מודל הנתונים

## 3.1 עקרונות

- **SQLite** ב-`payments/data/payments.sqlite`, מצב WAL, `foreign_keys=ON`. כמו שאר הכלים; מספיק בהרבה לנפח
  הצפוי (אלפי לקוחות). מעבר ל-MySQL אפשרי בהמשך — כל הגישה דרך שכבה אחת (`lib/db.php`).
- **כסף באגורות שלמות** (`INTEGER`). שבר אגורה ליחידה — `amount_micros` (מיליוניות ₪).
- **מזהים חיצוניים עם קידומת** — `cus_`, `acc_`, `sub_`, `inv_`, `pay_`, `chk_`, `evt_` + 20 תווים אקראיים
  (base32). לא מספרים רציפים החוצה — אי אפשר לנחש.
- **שום רשומה כספית לא נמחקת** — רק מצב (`void`, `canceled`). ביטול = רשומה הפוכה.
- **ביקורת** — כל שינוי במצב כספי נרשם ב-`audit` (רק הוספה).
- **`merchant` (עוסק) מהיום הראשון** — הוכרע: "כל סוגי העוסקים". כל `app` משויכת לעוסק; לכל עוסק `tax_status`,
  מע"מ, `provider_account`ים וחשבון Morning משלו. `account` שייך לעוסק אחד — **חשבונית, ארנק וקישור חשבונות רק
  בתוך אותו עוסק**. לקוח של שני עוסקים = שני חשבונות (אפשר לקשר ביניהם לתצוגה בפורטל בלבד).

## 3.2 תרשים

```
merchant ──< provider_account (סולק+מפתחות)        merchant ──< invoice_provider_account
   │
   └──< app ──< app_key                    app ──< webhook_endpoint ──< webhook_delivery
         │
         ├──< product ──< price
         │
         └──< customer (app, external_id) >── account ──< account_link (אימות מייל לקישור)
                                                 │
       ┌──────────────┬───────────────┬─────────┼──────────────┬──────────────┬─────────────┐
 payment_method   subscription      wallet_tx  usage_event   checkout      invoice      coupon_redemption
  (token)          ──< sub_item                 ──< reservation           ──< invoice_line
                                                                          ──< payment ──< refund
                                                                          ──< tax_document
 provider_event (webhook נכנס, גולמי)     idempotency      nonce      audit      job (תור)
```

## 3.3 הטבלאות

### מבנה ובעלות

| טבלה | עמודות עיקריות |
| --- | --- |
| `merchant` | `id`, `name`, `tax_id`, `tax_status` (`exempt`/`licensed`/`company`), `vat_rate`, `currency`, `address`, `email` |
| `provider_account` | `id`, `merchant`, `provider` (`cardcom`/`payplus`/…), `mode` (`test`/`live`), `credentials_enc`, `methods` (JSON), `priority`, `active` |
| `invoice_provider_account` | `id`, `merchant`, `provider` (`morning`/`icount`/`provider`/`none`), `credentials_enc`, `active` |
| `app` | `id` (שם התיקייה/מזהה), `name`, `merchant`, `key_url`, `origins` (JSON), `status` (`pending`/`active`/`disabled`), `created_at` |
| `app_key` | `app`, `kid`, `public_key`, `fetched_at` — מטמון המפתחות מ-`key_url` |
| `webhook_endpoint` | `id`, `app`, `url`, `events` (JSON), `active` |

### קטלוג

| טבלה | עמודות עיקריות |
| --- | --- |
| `product` | `id`, `app`, `code` (ייחודי לאפליקציה), `name`, `type` (פרק 2.2), `unit_label` ("טוקנים"), `active` |
| `price` | `id`, `product`, `version`, `amount`, `amount_micros`, `currency`, `tax_behavior`, `interval`, `interval_count`, `billing_scheme`, `tiers` (JSON), `tiers_mode`, `package_size`, `included`, `aggregate`, `prepaid` (0/1), `trial_days`, `grace_days`, `min_charge`, `cap`, `active`, `created_at` |
| `coupon` | `id`, `code`, `percent_off`/`amount_off`, `duration` (`once`/`repeating`/`forever`), `months`, `max_redemptions`, `expires_at`, `apps` (JSON) |

### לקוחות

| טבלה | עמודות עיקריות |
| --- | --- |
| `account` | `id`, `merchant`, `name` (לחשבונית), `tax_id`, `customer_type` (`private`/`business`), `email_billing`, `email_verified_at`, `address`, `phone`, `default_method`, `credit_limit`, `auto_topup` (JSON: סף, סכום), `status`, `created_at` |
| `customer` | `id`, `app`, `external_id`, `account`, `name`, `email`, `created_at` — **ייחודי (`app`, `external_id`)** |
| `account_link` | `id`, `from_account`, `to_account`, `email`, `token_hash`, `status`, `confirmed_at` — קישור חשבונות בין אפליקציות (❓) |
| `payment_method` | `id`, `account`, `provider_account`, `kind` (`card`/`bit`/`direct_debit`/…), `token_enc`, `brand`, `last4`, `exp_month`, `exp_year`, `holder_id_last4`, `status`, `created_at` — **אין מספר כרטיס, אין CVV** |

### מנויים ושימוש

| טבלה | עמודות עיקריות |
| --- | --- |
| `subscription` | `id`, `account`, `customer`, `app`, `status`, `current_period_start`, `current_period_end`, `anchor`, `trial_end`, `cancel_at`, `canceled_at`, `pause` (JSON), `coupon`, `created_at` |
| `subscription_item` | `id`, `subscription`, `price`, `quantity` |
| `usage_event` | `id`, `customer`, `app`, `product`, `quantity` (REAL), `idempotency_key`, `occurred_at`, `period`, `meta` (JSON), `wallet_tx` — **ייחודי (`app`, `idempotency_key`)** |
| `reservation` | `id`, `account`, `product`, `estimate`, `amount`, `status` (`held`/`committed`/`released`/`expired`), `expires_at` |
| `wallet_tx` | `id`, `account`, `scope_app` (NULL = כללי), `amount` (±), `kind` (פרק 2.5), `ref_type`, `ref_id`, `expires_at`, `note`, `created_at` |

### מסמכים ותשלומים

| טבלה | עמודות עיקריות |
| --- | --- |
| `checkout` | `id`, `account`, `customer`, `app`, `mode` (`payment`/`subscription`/`setup`/`topup`), `items` (JSON), `amount`, `currency`, `status` (`open`/`completed`/`expired`/`canceled`), `return_url`, `cancel_url`, `provider_account`, `provider_ref`, `expires_at` |
| `invoice` | `id`, `account`, `number` (פנימי), `period_start`, `period_end`, `subtotal`, `discount`, `vat_rate`, `vat`, `total`, `status` (`draft`/`open`/`paid`/`void`/`uncollectible`), `due_at`, `paid_at`, `attempts`, `next_attempt_at` |
| `invoice_line` | `id`, `invoice`, `app`, `product`, `price`, `description`, `quantity`, `unit_amount`, `amount`, `period_start`, `period_end`, `proration` (0/1) |
| `payment` | `id`, `invoice`, `checkout`, `account`, `method`, `provider_account`, `amount`, `currency`, `installments`, `status` (`pending`/`succeeded`/`failed`/`refunded`/`partially_refunded`), `provider_txn`, `approval_code`, `failure_code`, `failure_message`, `created_at` |
| `refund` | `id`, `payment`, `amount`, `reason`, `status`, `provider_ref`, `created_by` |
| `tax_document` | `id`, `invoice`/`payment`/`refund`, `provider`, `type` (`receipt`/`tax_invoice`/`tax_invoice_receipt`/`credit_note`), `number`, `allocation_number`, `url`, `pdf_path`, `status`, `created_at` |

### תשתית

| טבלה | עמודות עיקריות |
| --- | --- |
| `provider_event` | `id`, `provider`, `provider_event_id`, `payload` (גולמי), `verified`, `processed_at`, `error` — **ייחודי (`provider`, `provider_event_id`)** |
| `webhook_delivery` | `id`, `endpoint`, `event_id`, `type`, `body`, `attempt`, `status`, `response_code`, `next_at` |
| `idempotency` | `app`, `key`, `request_hash`, `response`, `status_code`, `created_at` — נשמר 24 שעות |
| `nonce` | `app`, `nonce`, `seen_at` — נשמר 10 דקות |
| `audit` | `id`, `actor_type` (`admin`/`app`/`provider`/`system`/`customer`), `actor_id`, `action`, `object_type`, `object_id`, `before`, `after`, `ip`, `created_at` |
| `job` | `id`, `type`, `payload`, `run_at`, `attempts`, `locked_until`, `last_error` — תור עבודות (cron) |
| `setting` | `key`, `value` |

## 3.4 שלמות

- כל שינוי ביתרה, בחשבונית או בתשלום — **בתוך `BEGIN IMMEDIATE`** (SQLite נועל כתיבה; אין מירוץ בין שני
  `usage_record` שמורידים מאותו ארנק).
- יתרה מחושבת: `SELECT SUM(amount) FROM wallet_tx WHERE account=?` — עם אינדקס, ומטמון ב-`account.balance_cache`
  שמתעדכן באותה טרנזקציה ונבדק מול הסכום בבדיקה הלילית.
- `invoice.total = Σ invoice_line.amount − discount + vat` — נבדק לפני כל הוצאת מסמך.
- **בדיקת שלמות לילית** — סכומים, יתרות, תשלומים מול חשבוניות; פער → התראה למנהל (פרק 9).

## 3.5 קבצים בשרת

```
payments/data/                ← מוחרג בפריסה (--exclude='payments/data/')
  payments.sqlite (+ -wal, -shm)
  secret.key                  ← מפתח הצפנה לטוקנים ולסודות הסולקים (sodium secretbox)
  signing.key                 ← זוג Ed25519 של התוסף (חתימת webhooks)
  backups/                    ← גיבוי יומי, 30 יום, מוצפן
  docs/                       ← PDF של מסמכים חשבונאיים (מטמון)
  logs/
```

`payments/data/.htaccess` — `Require all denied`.
