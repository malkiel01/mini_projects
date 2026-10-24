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

const PAY_SCHEMA_VERSION = 1;

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
    $pdo->exec('PRAGMA user_version = ' . PAY_SCHEMA_VERSION);
}
