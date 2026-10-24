<?php
/**
 * המסד של תוסף התשלומים — SQLite אחד ב-data/ (מוחרג בפריסה: data/*.sqlite*, *.key, config.json, backups/…).
 * אין כאן require לקובץ מחוץ לתיקייה — התוסף עצמאי.
 *
 * כסף: מחירים באגורות (amount) או במיליוניות ש"ח (amount_micros) לשימוש מדוד; הארנק — במיליוניות ש"ח
 * (micros: ‏1 ₪ = 1,000,000), כדי שטוקן בודד יוכל לעלות שבר אגורה בלי עיגול בכל אירוע.
 * שום רשומה כספית לא נמחקת — רק מצב או תנועה הפוכה.
 */

declare(strict_types=1);

const PAY_SCHEMA_VERSION = 6;

function pay_data_dir(): string
{
    $dir = getenv('PAY_DATA_DIR') ?: dirname(__DIR__) . '/data';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

/** אחרי fetch() של שורה אחת — closeCursor(), אחרת כתיבה באותה בקשה עלולה להיכשל ב-"database is locked". */
function db(): PDO
{
    static $pdo = null;
    static $path = null;
    $want = pay_data_dir() . '/payments.sqlite';
    if ($pdo && $path === $want) return $pdo;
    $path = $want;
    $pdo = new PDO('sqlite:' . $want, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON');
    pay_migrate($pdo);
    return $pdo;
}

/** טרנזקציה שנועלת כתיבה מההתחלה — אין מירוץ בין שני חיובים מאותו ארנק. */
function db_tx(callable $fn): mixed
{
    $pdo = db();
    if (db_in_tx()) return $fn();
    $pdo->exec('BEGIN IMMEDIATE');
    $GLOBALS['PAY_TX'] = true;
    try {
        $r = $fn();
        $pdo->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    } finally {
        $GLOBALS['PAY_TX'] = false;
    }
}

/** PDO::inTransaction לא רואה BEGIN שנשלח ב-exec — לכן דגל משלנו. */
function db_in_tx(): bool
{
    return !empty($GLOBALS['PAY_TX']);
}

function db_one(string $sql, array $args = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    $st->closeCursor();
    return $row ?: null;
}

function db_all(string $sql, array $args = []): array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function db_run(string $sql, array $args = []): int
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

function pay_migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= PAY_SCHEMA_VERSION) return;
    if ($v < 1) pay_migrate_v1($pdo);
    if ($v < 2) pay_migrate_v2($pdo);
    if ($v < 3) pay_migrate_v3($pdo);
    if ($v < 4) pay_migrate_v4($pdo);
    if ($v < 5) pay_migrate_v5($pdo);
    if ($v < 6) pay_migrate_v6($pdo);
    $pdo->exec('PRAGMA user_version = ' . PAY_SCHEMA_VERSION);
}

function pay_migrate_v1(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        -- מנוי מאושר (פרק 12): משתמש פלטפורמה שהמנהל אישר. המנהל עצמו — המנוי הראשון (role=owner)
        CREATE TABLE IF NOT EXISTS tenant (
            id TEXT PRIMARY KEY,
            platform_sub TEXT UNIQUE,
            name TEXT NOT NULL DEFAULT '',
            email TEXT,
            role TEXT NOT NULL DEFAULT 'tenant',      -- owner | tenant
            status TEXT NOT NULL DEFAULT 'pending',   -- pending | active | suspended | closed
            limits TEXT NOT NULL DEFAULT '{}',
            features TEXT NOT NULL DEFAULT '{}',
            created_at INTEGER NOT NULL,
            last_seen INTEGER NOT NULL DEFAULT 0
        );

        -- עוסק: פטור / מורשה / חברה, עם מע"מ, סולקים ו-Morning משלו (פרק 3)
        CREATE TABLE IF NOT EXISTS merchant (
            id TEXT PRIMARY KEY,
            tenant TEXT NOT NULL REFERENCES tenant(id),
            name TEXT NOT NULL,
            tax_id TEXT NOT NULL DEFAULT '',
            tax_status TEXT NOT NULL DEFAULT 'exempt', -- exempt | licensed | company
            vat_rate INTEGER NOT NULL DEFAULT 1800,     -- בבסיס 10,000: ‏1800 = 18%
            currency TEXT NOT NULL DEFAULT 'ILS',
            email TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        );

        -- מערכת מחוברת: אפליקציה, אתר, מערכת (פרק 12.2)
        CREATE TABLE IF NOT EXISTS client (
            id TEXT PRIMARY KEY,                       -- ‏slug: ‏guarded-browser
            tenant TEXT NOT NULL REFERENCES tenant(id),
            merchant TEXT REFERENCES merchant(id),
            name TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT 'server',       -- internal | server | static | mobile
            status TEXT NOT NULL DEFAULT 'active',     -- pending | active | disabled
            live_ok INTEGER NOT NULL DEFAULT 0,        -- ‏live רק באישור המנהל
            key_url TEXT NOT NULL DEFAULT '',          -- ‏Ed25519: כתובת המפתח הציבורי של המערכת
            ed_keys TEXT,                              -- ‏{kid: base64}
            ed_keys_at INTEGER NOT NULL DEFAULT 0,
            origins TEXT NOT NULL DEFAULT '[]',
            webhook_url TEXT NOT NULL DEFAULT '',
            brand TEXT NOT NULL DEFAULT '{}',
            created_at INTEGER NOT NULL
        );

        -- קודי גישה (פרק 12.3). הסוד מוצפן — צריך אותו כדי לאמת HMAC
        CREATE TABLE IF NOT EXISTS api_key (
            id TEXT PRIMARY KEY,                       -- ‏key_id — החלק הגלוי
            client TEXT NOT NULL REFERENCES client(id),
            env TEXT NOT NULL,                         -- test | live
            kind TEXT NOT NULL,                        -- sk | pk | whsec
            secret_enc TEXT NOT NULL,
            last4 TEXT NOT NULL,
            ips TEXT NOT NULL DEFAULT '[]',
            status TEXT NOT NULL DEFAULT 'active',     -- active | revoked
            expires_at INTEGER,                        -- חפיפה בהחלפה
            last_used INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS api_key_client ON api_key(client, env, kind);

        CREATE TABLE IF NOT EXISTS product (
            id TEXT PRIMARY KEY,
            client TEXT NOT NULL REFERENCES client(id),
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            type TEXT NOT NULL,                        -- plan | seat | unit | metered | credit_pack | addon
            unit_label TEXT NOT NULL DEFAULT '',
            grants TEXT NOT NULL DEFAULT '{}',         -- ‏{features:{…}, limits:{…}} — מה המוצר נותן (פרק 4.4)
            active INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            UNIQUE (client, code)
        );

        -- מחיר לא נערך — רק גרסה חדשה (פרק 2.3)
        CREATE TABLE IF NOT EXISTS price (
            id TEXT PRIMARY KEY,
            product TEXT NOT NULL REFERENCES product(id),
            version INTEGER NOT NULL,
            amount INTEGER NOT NULL DEFAULT 0,         -- אגורות
            amount_micros INTEGER NOT NULL DEFAULT 0,  -- מיליוניות ₪ לכל package_size (מדוד)
            currency TEXT NOT NULL DEFAULT 'ILS',
            tax_behavior TEXT NOT NULL DEFAULT 'inclusive',
            interval TEXT NOT NULL DEFAULT 'none',     -- month | year | week | none
            interval_count INTEGER NOT NULL DEFAULT 1,
            package_size INTEGER NOT NULL DEFAULT 1,
            included INTEGER NOT NULL DEFAULT 0,
            aggregate TEXT NOT NULL DEFAULT 'sum',     -- sum | max | last | unique
            prepaid INTEGER NOT NULL DEFAULT 1,        -- מדוד: 1 = יורד מהארנק, 0 = בדיעבד
            tiers TEXT,
            trial_days INTEGER NOT NULL DEFAULT 0,
            grace_days INTEGER NOT NULL DEFAULT 7,
            active INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            UNIQUE (product, version)
        );

        -- חשבון חיוב: שייך לעוסק אחד; כמה לקוחות (מכמה מערכות) יכולים להצביע עליו (קישור לפי מייל)
        CREATE TABLE IF NOT EXISTS account (
            id TEXT PRIMARY KEY,
            merchant TEXT NOT NULL REFERENCES merchant(id),
            env TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            tax_id TEXT NOT NULL DEFAULT '',
            customer_type TEXT NOT NULL DEFAULT 'private',
            email TEXT NOT NULL DEFAULT '',
            email_verified_at INTEGER,
            credit_limit INTEGER NOT NULL DEFAULT 0,   -- מיליוניות ₪; ‏0 = אסור לרדת מתחת לאפס
            status TEXT NOT NULL DEFAULT 'active',
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS customer (
            id TEXT PRIMARY KEY,
            client TEXT NOT NULL REFERENCES client(id),
            env TEXT NOT NULL,
            external_id TEXT NOT NULL,
            account TEXT NOT NULL REFERENCES account(id),
            name TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            UNIQUE (client, env, external_id)
        );
        CREATE INDEX IF NOT EXISTS customer_account ON customer(account);

        -- מנוי. בשלב 2 — רק ידני (המנהל מעניק תכנית); חידוש וחיוב — שלב 3
        CREATE TABLE IF NOT EXISTS subscription (
            id TEXT PRIMARY KEY,
            account TEXT NOT NULL REFERENCES account(id),
            customer TEXT NOT NULL REFERENCES customer(id),
            client TEXT NOT NULL REFERENCES client(id),
            status TEXT NOT NULL,                      -- trialing | active | past_due | paused | canceled
            source TEXT NOT NULL DEFAULT 'manual',
            current_period_start INTEGER NOT NULL,
            current_period_end INTEGER,
            cancel_at INTEGER,
            canceled_at INTEGER,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS subscription_item (
            id TEXT PRIMARY KEY,
            subscription TEXT NOT NULL REFERENCES subscription(id),
            price TEXT NOT NULL REFERENCES price(id),
            quantity INTEGER NOT NULL DEFAULT 1
        );

        -- הארנק: יתרה = סכום התנועות (פרק 2.5). ‏balance_cache מתעדכן באותה טרנזקציה ונבדק מול הסכום
        CREATE TABLE IF NOT EXISTS wallet_tx (
            id TEXT PRIMARY KEY,
            account TEXT NOT NULL REFERENCES account(id),
            amount INTEGER NOT NULL,                   -- מיליוניות ₪, ±
            kind TEXT NOT NULL,                        -- topup | grant | usage | reserve | release | expire | refund | adjust
            ref_type TEXT NOT NULL DEFAULT '',
            ref_id TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            actor TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS wallet_tx_account ON wallet_tx(account, created_at);
        CREATE TABLE IF NOT EXISTS wallet_balance (
            account TEXT PRIMARY KEY REFERENCES account(id),
            micros INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS usage_event (
            id TEXT PRIMARY KEY,
            client TEXT NOT NULL REFERENCES client(id),
            env TEXT NOT NULL,
            customer TEXT NOT NULL REFERENCES customer(id),
            product TEXT NOT NULL REFERENCES product(id),
            price TEXT REFERENCES price(id),
            quantity REAL NOT NULL,
            cost_micros INTEGER NOT NULL DEFAULT 0,
            idempotency_key TEXT NOT NULL,
            occurred_at INTEGER NOT NULL,
            meta TEXT NOT NULL DEFAULT '{}',
            created_at INTEGER NOT NULL,
            UNIQUE (client, env, idempotency_key)
        );
        CREATE INDEX IF NOT EXISTS usage_customer ON usage_event(customer, product, occurred_at);

        CREATE TABLE IF NOT EXISTS reservation (
            id TEXT PRIMARY KEY,
            account TEXT NOT NULL REFERENCES account(id),
            customer TEXT NOT NULL REFERENCES customer(id),
            product TEXT NOT NULL REFERENCES product(id),
            price TEXT NOT NULL REFERENCES price(id),
            estimate REAL NOT NULL,
            micros INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'held',       -- held | committed | released | expired
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        );

        -- webhooks יוצאים: תור עם ניסיונות חוזרים (פרק 4.5)
        CREATE TABLE IF NOT EXISTS webhook_delivery (
            id TEXT PRIMARY KEY,                       -- ‏evt_…
            client TEXT NOT NULL REFERENCES client(id),
            env TEXT NOT NULL,
            type TEXT NOT NULL,
            body TEXT NOT NULL,
            attempt INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'pending',    -- pending | delivered | failed
            response_code INTEGER NOT NULL DEFAULT 0,
            next_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS webhook_due ON webhook_delivery(status, next_at);

        CREATE TABLE IF NOT EXISTS idempotency (
            client TEXT NOT NULL,
            env TEXT NOT NULL,
            key TEXT NOT NULL,
            action TEXT NOT NULL,
            request_hash TEXT NOT NULL,
            response TEXT NOT NULL,
            status_code INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (client, env, key)
        );
        CREATE TABLE IF NOT EXISTS nonce (
            client TEXT NOT NULL,
            nonce TEXT NOT NULL,
            t INTEGER NOT NULL,
            PRIMARY KEY (client, nonce)
        );

        -- יומן קריאות לכל מערכת (פרק 12.7) — בלי סודות ובלי גוף מלא
        CREATE TABLE IF NOT EXISTS api_log (
            id INTEGER PRIMARY KEY,
            client TEXT,
            env TEXT,
            action TEXT NOT NULL,
            status INTEGER NOT NULL,
            code TEXT NOT NULL DEFAULT '',
            ms INTEGER NOT NULL DEFAULT 0,
            ip TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS api_log_client ON api_log(client, created_at);

        -- ביקורת: רק הוספה
        CREATE TABLE IF NOT EXISTS audit (
            id INTEGER PRIMARY KEY,
            tenant TEXT,
            actor_type TEXT NOT NULL,                  -- admin | client | system | customer
            actor_id TEXT NOT NULL DEFAULT '',
            action TEXT NOT NULL,
            object_type TEXT NOT NULL DEFAULT '',
            object_id TEXT NOT NULL DEFAULT '',
            detail TEXT NOT NULL DEFAULT '{}',
            ip TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        );
        CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON audit BEGIN SELECT RAISE(ABORT, 'audit is append-only'); END;
        CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON audit BEGIN SELECT RAISE(ABORT, 'audit is append-only'); END;
        CREATE TRIGGER IF NOT EXISTS wallet_no_update BEFORE UPDATE ON wallet_tx BEGIN SELECT RAISE(ABORT, 'wallet_tx is append-only'); END;
        CREATE TRIGGER IF NOT EXISTS wallet_no_delete BEFORE DELETE ON wallet_tx BEGIN SELECT RAISE(ABORT, 'wallet_tx is append-only'); END;

        CREATE TABLE IF NOT EXISTS setting (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    SQL);
}

/** שלב 3: תשלום, אמצעי תשלום (טוקן), חשבוניות פנימיות, החזרים, מנויים מלאים, הודעות ללקוח, הסולק המדומה. */
function pay_migrate_v2(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        -- מבצע: ‏intro_amount ל-intro_periods המחזורים הראשונים. ‏renewal: ‏auto (חודשי ללא הגבלה) | consent (שנתי — חידוש רק בהסכמה, §13א)
        ALTER TABLE price ADD COLUMN intro_amount INTEGER;
        ALTER TABLE price ADD COLUMN intro_periods INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE price ADD COLUMN renewal TEXT NOT NULL DEFAULT 'auto';

        ALTER TABLE subscription ADD COLUMN trial_end INTEGER;
        ALTER TABLE subscription ADD COLUMN period_index INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE subscription ADD COLUMN cancel_at_period_end INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE subscription ADD COLUMN renew_consent_until INTEGER;
        ALTER TABLE subscription ADD COLUMN cancel_reason TEXT NOT NULL DEFAULT '';
        ALTER TABLE subscription ADD COLUMN first_paid_at INTEGER;
        ALTER TABLE subscription ADD COLUMN past_due_since INTEGER;
        ALTER TABLE subscription ADD COLUMN pending_items TEXT;          -- שנמוך שנכנס בסוף המחזור

        ALTER TABLE account ADD COLUMN default_method TEXT;
        ALTER TABLE account ADD COLUMN phone TEXT NOT NULL DEFAULT '';

        -- אמצעי תשלום: רק טוקן של הסולק + 4 ספרות. אין מספר כרטיס, אין CVV
        CREATE TABLE IF NOT EXISTS payment_method (
            id TEXT PRIMARY KEY,
            account TEXT NOT NULL REFERENCES account(id),
            provider TEXT NOT NULL,
            kind TEXT NOT NULL,                        -- card | bit | …
            token_enc TEXT NOT NULL,
            brand TEXT NOT NULL DEFAULT '',
            last4 TEXT NOT NULL DEFAULT '',
            exp TEXT NOT NULL DEFAULT '',              -- MM/YY
            status TEXT NOT NULL DEFAULT 'active',
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS checkout (
            id TEXT PRIMARY KEY,
            client TEXT NOT NULL REFERENCES client(id),
            env TEXT NOT NULL,
            customer TEXT NOT NULL REFERENCES customer(id),
            account TEXT NOT NULL REFERENCES account(id),
            mode TEXT NOT NULL,                        -- payment | subscription | topup | setup
            items TEXT NOT NULL DEFAULT '[]',
            amount INTEGER NOT NULL,                   -- אגורות לחיוב עכשיו (0 בניסיון/החלפת כרטיס)
            currency TEXT NOT NULL DEFAULT 'ILS',
            status TEXT NOT NULL DEFAULT 'open',       -- open | completed | expired | canceled
            return_url TEXT NOT NULL,
            cancel_url TEXT NOT NULL,
            provider TEXT NOT NULL DEFAULT '',
            provider_ref TEXT NOT NULL DEFAULT '',
            consent_at INTEGER,
            result TEXT NOT NULL DEFAULT '{}',
            meta TEXT NOT NULL DEFAULT '{}',
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS checkout_open ON checkout(status, created_at);

        -- חשבונית פנימית (המסמך החשבונאי — שלב 5, דרך Morning)
        CREATE TABLE IF NOT EXISTS invoice (
            id TEXT PRIMARY KEY,
            account TEXT NOT NULL REFERENCES account(id),
            merchant TEXT NOT NULL REFERENCES merchant(id),
            env TEXT NOT NULL,
            subscription TEXT,
            checkout TEXT,
            period_start INTEGER,
            period_end INTEGER,
            subtotal INTEGER NOT NULL,                 -- לפני מע"מ
            vat_rate INTEGER NOT NULL,
            vat INTEGER NOT NULL,
            total INTEGER NOT NULL,
            status TEXT NOT NULL,                      -- draft | open | paid | void | uncollectible
            attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at INTEGER,
            paid_at INTEGER,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS invoice_due ON invoice(status, next_attempt_at);
        CREATE TABLE IF NOT EXISTS invoice_line (
            id TEXT PRIMARY KEY,
            invoice TEXT NOT NULL REFERENCES invoice(id),
            client TEXT NOT NULL,
            product TEXT,
            price TEXT,
            description TEXT NOT NULL,
            quantity REAL NOT NULL,
            amount INTEGER NOT NULL                    -- אגורות, כפי שהוצג (כולל/לא כולל לפי המחיר)
        );

        CREATE TABLE IF NOT EXISTS payment (
            id TEXT PRIMARY KEY,
            account TEXT NOT NULL REFERENCES account(id),
            env TEXT NOT NULL,
            invoice TEXT,
            checkout TEXT,
            method TEXT,
            provider TEXT NOT NULL,
            amount INTEGER NOT NULL,
            refunded INTEGER NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT 'ILS',
            status TEXT NOT NULL,                      -- succeeded | failed | refunded | partially_refunded
            provider_txn TEXT NOT NULL DEFAULT '',
            failure_code TEXT NOT NULL DEFAULT '',
            failure_message TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS payment_txn ON payment(provider, provider_txn) WHERE provider_txn <> '';

        CREATE TABLE IF NOT EXISTS refund (
            id TEXT PRIMARY KEY,
            payment TEXT NOT NULL REFERENCES payment(id),
            amount INTEGER NOT NULL,
            reason TEXT NOT NULL,
            provider_ref TEXT NOT NULL DEFAULT '',
            actor TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );

        -- הודעות ללקוח (מייל / מסרון). ‏dedupe מונע שליחה כפולה של אותה הודעה חוקית
        CREATE TABLE IF NOT EXISTS notification (
            id TEXT PRIMARY KEY,
            account TEXT NOT NULL,
            channel TEXT NOT NULL,                     -- email | sms
            template TEXT NOT NULL,
            dedupe TEXT NOT NULL UNIQUE,
            to_addr TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',    -- pending | sent | failed | no_provider
            created_at INTEGER NOT NULL,
            sent_at INTEGER
        );

        -- סשן "החשבון שלי" (חד-פעמי, קצר)
        CREATE TABLE IF NOT EXISTS portal_session (
            hash TEXT PRIMARY KEY,
            customer TEXT NOT NULL REFERENCES customer(id),
            return_url TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        );

        -- הסולק המדומה (פרק 5.4) — מתנהג כמו סולק חיצוני; רק בסביבת בדיקות
        CREATE TABLE IF NOT EXISTS fake_session (
            ref TEXT PRIMARY KEY,
            checkout TEXT NOT NULL,
            amount INTEGER NOT NULL,
            save_token INTEGER NOT NULL,
            methods TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',       -- open | paid | declined
            txn TEXT,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS fake_txn (
            id TEXT PRIMARY KEY,
            kind TEXT NOT NULL,                        -- charge | refund
            amount INTEGER NOT NULL,
            status TEXT NOT NULL,
            token TEXT,
            card_last4 TEXT NOT NULL DEFAULT '',
            method TEXT NOT NULL DEFAULT 'card',
            idem TEXT UNIQUE,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS fake_token (
            token TEXT PRIMARY KEY,
            last4 TEXT NOT NULL,
            behavior TEXT NOT NULL DEFAULT 'ok'        -- ok | decline (כרטיס בדיקה שנדחה בחיוב הבא)
        );
    SQL);
}

/** שלב 4: סולקים אמיתיים לכל עוסק, הלקוח אצל הסולק, אירועים נכנסים, ומסמכים חשבונאיים שהסולק הפיק. */
function pay_migrate_v3(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        -- חיבור לחברת סליקה, לכל עוסק ולכל סביבה. הקודים מוצפנים
        CREATE TABLE IF NOT EXISTS provider_account (
            id TEXT PRIMARY KEY,
            merchant TEXT NOT NULL REFERENCES merchant(id),
            provider TEXT NOT NULL,                    -- morning | …
            env TEXT NOT NULL,                         -- test (סביבת הניסוי של הסולק) | live
            credentials_enc TEXT NOT NULL,
            settings TEXT NOT NULL DEFAULT '{}',       -- ‏{methods:[…], plugin_id, …} — לא סודי
            status TEXT NOT NULL DEFAULT 'active',
            checked_at INTEGER,
            check_error TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            UNIQUE (merchant, provider, env)
        );
        -- הלקוח שלנו אצל הסולק (במורנינג: "לקוח" שהטוקנים והמסמכים שלו קשורים אליו)
        CREATE TABLE IF NOT EXISTS provider_customer (
            account TEXT NOT NULL REFERENCES account(id),
            provider_account TEXT NOT NULL REFERENCES provider_account(id),
            ref TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (account, provider_account)
        );
        -- אירוע נכנס מסולק — גולמי, פעם אחת (פרק 9.4)
        CREATE TABLE IF NOT EXISTS provider_event (
            id TEXT PRIMARY KEY,
            provider_account TEXT NOT NULL,
            delivery_id TEXT NOT NULL,
            topic TEXT NOT NULL DEFAULT '',
            payload TEXT NOT NULL,
            verified INTEGER NOT NULL,
            processed_at INTEGER,
            error TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            UNIQUE (provider_account, delivery_id)
        );
        -- מסמך חשבונאי (קבלה / חשבונית מס-קבלה / זיכוי) שהופק אצל הסולק או ספק החשבוניות
        CREATE TABLE IF NOT EXISTS tax_document (
            id TEXT PRIMARY KEY,
            payment TEXT,
            invoice TEXT,
            provider TEXT NOT NULL,
            external_id TEXT NOT NULL,
            type INTEGER NOT NULL,                     -- ‏320 חשבונית מס/קבלה · 400 קבלה · 330 זיכוי
            number TEXT NOT NULL DEFAULT '',
            allocation_number TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            UNIQUE (provider, external_id)
        );
        ALTER TABLE payment ADD COLUMN provider_account TEXT;
        ALTER TABLE payment_method ADD COLUMN provider_account TEXT;
        ALTER TABLE checkout ADD COLUMN provider_account TEXT;
        ALTER TABLE checkout ADD COLUMN invoice TEXT;                     -- ‏mode=invoice: תשלום חשבונית פתוחה
        ALTER TABLE refund ADD COLUMN manual INTEGER NOT NULL DEFAULT 0;  -- בוצע בממשק הסולק, נרשם כאן
    SQL);
}

/** שלב 5: מסמכים חשבונאיים לתשלומים שלא דרך מורנינג (ידני, סולקים אחרים), וזיכויים. */
function pay_migrate_v4(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        ALTER TABLE tax_document ADD COLUMN refund TEXT;
        ALTER TABLE payment ADD COLUMN pay_method TEXT NOT NULL DEFAULT '';      -- ‏card | bit | cash | transfer | cheque | other
        ALTER TABLE payment ADD COLUMN reference TEXT NOT NULL DEFAULT '';       -- אסמכתא (העברה, צ'ק)
        ALTER TABLE payment ADD COLUMN doc_attempts INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE payment ADD COLUMN doc_error TEXT NOT NULL DEFAULT '';
        ALTER TABLE refund ADD COLUMN doc_attempts INTEGER NOT NULL DEFAULT 0;
        ALTER TABLE refund ADD COLUMN doc_error TEXT NOT NULL DEFAULT '';
    SQL);
}

/** שלב 6: קישורי תשלום (בלי קוד) וכללים. */
function pay_migrate_v5(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS payment_link (
            id TEXT PRIMARY KEY,
            code TEXT NOT NULL UNIQUE,                 -- בכתובת: pay/link.php?k=<code>
            client TEXT NOT NULL REFERENCES client(id),
            env TEXT NOT NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            product TEXT,                              -- קוד מוצר (יחידה / חבילה) — או ריק לסכום לבחירה
            quantity INTEGER NOT NULL DEFAULT 1,
            amount_mode TEXT NOT NULL DEFAULT 'fixed', -- fixed | custom
            min_amount INTEGER NOT NULL DEFAULT 0,
            max_amount INTEGER NOT NULL DEFAULT 0,
            fields TEXT NOT NULL DEFAULT '["name","email"]',
            success_url TEXT NOT NULL DEFAULT '',
            max_uses INTEGER NOT NULL DEFAULT 0,       -- 0 = ללא הגבלה
            uses INTEGER NOT NULL DEFAULT 0,
            expires_at INTEGER,
            active INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        );
        -- כללים וקריטריונים (פרק 12.4): "כש… אז…". ‏scope_client ריק = לכל המערכות של המנוי
        CREATE TABLE IF NOT EXISTS rule (
            id TEXT PRIMARY KEY,
            tenant TEXT NOT NULL REFERENCES tenant(id),
            name TEXT NOT NULL,
            scope_client TEXT,
            priority INTEGER NOT NULL DEFAULT 100,
            cond TEXT NOT NULL DEFAULT '{}',
            act TEXT NOT NULL DEFAULT '{}',
            active INTEGER NOT NULL DEFAULT 1,
            version INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
    SQL);
}

/** גרסה 6: פרטי המוכר ותנאי הרכישה — חובת גילוי בעסקה מרחוק (§14ג), תנאי לאישור "כסף אמיתי". */
function pay_migrate_v6(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        ALTER TABLE merchant ADD COLUMN phone TEXT NOT NULL DEFAULT '';
        ALTER TABLE client ADD COLUMN terms_url TEXT NOT NULL DEFAULT '';
        ALTER TABLE client ADD COLUMN privacy_url TEXT NOT NULL DEFAULT '';
    SQL);
}
