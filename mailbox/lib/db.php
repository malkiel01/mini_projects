<?php
/**
 * המסד (SQLite) והצפנת הסיסמאות של התיבות.
 *
 * mailboxes       תיבה = מייל + שרת + סיסמה מוצפנת. הבעלים — sub של משתמש הפלטפורמה.
 * mailbox_shares  שיתוף: מייל של משתמש פלטפורמה (sub נקבע כשהוא נכנס בפעם הראשונה) + הרשאה.
 * users           מי נכנס — כדי שהבעלים יבחר ממי לשתף, ושהשיתוף יתקשר ל-sub.
 * msg_cache       כותרות ותקציר לכל הודעה (box, uidvalidity, uid) — הודעה ב-IMAP לא משתנה.
 *
 * הסיסמה מוצפנת ב-sodium crypto_secretbox; המפתח ב-data/secret.key, נוצר בשרת בפעם הראשונה.
 * לא בגיט ולא נדרס בפריסה (deploy.yml).
 */

declare(strict_types=1);

if (!defined('MB_DATA_DIR')) define('MB_DATA_DIR', dirname(__DIR__) . '/data');

function mb_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    mb_ensure_data_dir();
    $pdo = new PDO('sqlite:' . MB_DATA_DIR . '/mailbox.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 4000;');
    mb_migrate($pdo);
    return $pdo;
}

function mb_ensure_data_dir(): void
{
    if (!is_dir(MB_DATA_DIR)) mkdir(MB_DATA_DIR, 0700, true);
    $ht = MB_DATA_DIR . '/.htaccess';
    if (!is_file($ht)) file_put_contents($ht, "Require all denied\n");
}

function mb_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS mailboxes (
            id          INTEGER PRIMARY KEY,
            owner_sub   TEXT NOT NULL,
            email       TEXT NOT NULL,
            label       TEXT NOT NULL DEFAULT '',
            imap_host   TEXT NOT NULL,
            imap_port   INTEGER NOT NULL DEFAULT 993,
            username    TEXT NOT NULL,
            secret      TEXT NOT NULL,
            created_at  INTEGER NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_mailboxes_owner_email ON mailboxes(owner_sub, email);
        CREATE TABLE IF NOT EXISTS mailbox_shares (
            id          INTEGER PRIMARY KEY,
            mailbox_id  INTEGER NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
            email       TEXT NOT NULL,
            sub         TEXT,
            perm        TEXT NOT NULL DEFAULT 'read' CHECK (perm IN ('read')),
            created_at  INTEGER NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_shares_box_email ON mailbox_shares(mailbox_id, email);
        CREATE TABLE IF NOT EXISTS users (
            sub         TEXT PRIMARY KEY,
            name        TEXT NOT NULL DEFAULT '',
            email       TEXT,
            last_seen   INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS msg_cache (
            mailbox_id  INTEGER NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
            uidvalidity INTEGER NOT NULL,
            uid         INTEGER NOT NULL,
            data        TEXT NOT NULL,
            at          INTEGER NOT NULL,
            PRIMARY KEY (mailbox_id, uidvalidity, uid)
        );
        CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        );
        SQL);
    // created = 1 — כתובת שהאפליקציה פתחה בשרת (lib/cpanel.php): נספרת במכסה, ונמחקת גם מהשרת
    $cols = array_column($pdo->query('PRAGMA table_info(mailboxes)')->fetchAll(), 'name');
    if (!in_array('created', $cols, true)) $pdo->exec('ALTER TABLE mailboxes ADD COLUMN created INTEGER NOT NULL DEFAULT 0');
}

// ── הצפנה ────────────────────────────────────────────

function mb_key(): string
{
    static $key = null;
    if ($key !== null) return $key;
    mb_ensure_data_dir();
    $file = MB_DATA_DIR . '/secret.key';
    if (!is_file($file)) {
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, base64_encode(sodium_crypto_secretbox_keygen()));
        chmod($tmp, 0600);
        // שני תהליכים במקביל — הראשון זוכה, השני קורא את מה שנכתב
        if (!@link($tmp, $file)) { /* כבר קיים */ }
        @unlink($tmp);
    }
    $raw = base64_decode(trim((string)file_get_contents($file)), true);
    if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('מפתח ההצפנה פגום');
    }
    return $key = $raw;
}

function mb_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, mb_key()));
}

function mb_decrypt(string $stored): string
{
    $raw = base64_decode($stored, true);
    $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if ($raw === false || strlen($raw) <= $n) throw new RuntimeException('סיסמה מוצפנת פגומה');
    $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), mb_key());
    if ($plain === false) throw new RuntimeException('לא ניתן לפענח את סיסמת התיבה');
    return $plain;
}
