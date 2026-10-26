<?php
/**
 * מי המשתמש, ולאילו תיבות הוא רשאי.
 *
 * הכניסה רק דרך הפלטפורמה (platform/docs/sso.md, סעיף 0): השער כותב את $_SERVER['PLATFORM_USER'] —
 * {sub, name, email, email_verified, platform_admin} — רק למשתמש עם הרשאה לאפליקציה. אין לה סיסמה
 * משלה; בלי PLATFORM_USER (אפליקציה מנותקת) — אין כניסה.
 *
 * תיבה נגישה לבעלים שלה, ולמי ששותף איתו במייל. השיתוף מתקשר ל-sub בכניסה הראשונה של המשתמש,
 * ורק כשהפלטפורמה אימתה את המייל שלו — אחרת כל אחד היה יכול "להיות" כתובת שלא שלו.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function mb_identity(): ?array
{
    $id = json_decode((string)($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || (string)$id['sub'] === '') return null;
    $email = is_string($id['email'] ?? null) && filter_var($id['email'], FILTER_VALIDATE_EMAIL)
        ? strtolower($id['email']) : null;
    return [
        'sub' => (string)$id['sub'],
        'name' => trim((string)($id['name'] ?? '')),
        'email' => $email,
        'email_verified' => ($id['email_verified'] ?? false) === true,
        'admin' => ($id['platform_admin'] ?? false) === true,
    ];
}

/** רושם את הכניסה ומקשר שיתופים שחיכו למייל שלו. */
function mb_touch_user(array $me): void
{
    $db = mb_db();
    $db->prepare('INSERT INTO users (sub, name, email, last_seen) VALUES (?, ?, ?, ?)
                  ON CONFLICT(sub) DO UPDATE SET name = excluded.name, email = excluded.email, last_seen = excluded.last_seen')
        ->execute([$me['sub'], $me['name'], $me['email_verified'] ? $me['email'] : null, time()]);   // רק מייל מאומת — הוא מה שמקשר שיתוף
    if ($me['email'] && $me['email_verified']) {
        $db->prepare('UPDATE mailbox_shares SET sub = ? WHERE email = ? AND sub IS NULL')->execute([$me['sub'], $me['email']]);
    }
}

/** התיבות שהמשתמש רואה — שלו, ומשותפות איתו. בלי הסיסמה. */
function mb_boxes_for(array $me): array
{
    $st = mb_db()->prepare(<<<'SQL'
        SELECT m.id, m.email, m.label, m.imap_host, m.imap_port, m.owner_sub,
               CASE WHEN m.owner_sub = :sub THEN 'owner' ELSE 'read' END AS role,
               u.name AS owner_name
        FROM mailboxes m
        LEFT JOIN users u ON u.sub = m.owner_sub
        WHERE m.owner_sub = :sub
           OR m.id IN (SELECT mailbox_id FROM mailbox_shares WHERE sub = :sub)
        ORDER BY role = 'owner' DESC, m.id
        SQL);
    $st->execute([':sub' => $me['sub']]);
    $out = [];
    foreach ($st->fetchAll() as $b) {
        $out[] = [
            'id' => (int)$b['id'],
            'email' => $b['email'],
            'label' => $b['label'] !== '' ? $b['label'] : $b['email'],
            'host' => $b['imap_host'],
            'port' => (int)$b['imap_port'],
            'role' => $b['role'],
            'owner' => $b['role'] === 'owner' ? null : ($b['owner_name'] ?: ''),
        ];
    }
    return $out;
}

/** התיבה המלאה (כולל הסיסמה המוצפנת) — רק אם מותר. $own: רק הבעלים. */
function mb_box(array $me, int $id, bool $own = false): ?array
{
    $shared = $own ? '' : ' OR id IN (SELECT mailbox_id FROM mailbox_shares WHERE sub = :sub)';
    $st = mb_db()->prepare("SELECT * FROM mailboxes WHERE id = :id AND (owner_sub = :sub$shared)");
    $st->execute([':id' => $id, ':sub' => $me['sub']]);
    return $st->fetch() ?: null;
}

// ── ייבוא חד-פעמי מ-~/.mailbox/*.json ─────────────────────

function mb_home_dir(): ?string
{
    if (defined('MB_IMPORT_DIR')) return MB_IMPORT_DIR;
    $home = getenv('HOME') ?: '';
    if ($home === '' && function_exists('posix_getpwuid')) $home = (string)(posix_getpwuid(posix_geteuid())['dir'] ?? '');
    return $home !== '' ? rtrim($home, '/') . '/.mailbox' : null;
}

/**
 * בכניסה של מנהל הפלטפורמה: כל קובץ ב-~/.mailbox נכנס כתיבה שלו (מוצפנת במסד), ונמחק.
 * הסיסמה לא נכתבת ללוג ולא חוזרת לדפדפן. מחזיר כמה תיבות יובאו.
 */
function mb_import_files(array $me): int
{
    if (!$me['admin']) return 0;
    $dir = mb_home_dir();
    if (!$dir || !is_dir($dir)) return 0;
    $n = 0;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $c = json_decode((string)@file_get_contents($file), true);
        $email = is_array($c) ? strtolower(trim((string)($c['email'] ?? ''))) : '';
        $host = is_array($c) ? trim((string)($c['imap_host'] ?? '')) : '';
        $pass = is_array($c) ? (string)($c['password'] ?? '') : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !mb_valid_host($host) || $pass === '') {
            error_log('mailbox import: קובץ לא תקין — ' . basename($file));
            continue;
        }
        $port = (int)($c['imap_port'] ?? 993) ?: 993;
        try {
            mb_db()->prepare('INSERT INTO mailboxes (owner_sub, email, label, imap_host, imap_port, username, secret, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                              ON CONFLICT(owner_sub, email) DO UPDATE SET imap_host = excluded.imap_host,
                                  imap_port = excluded.imap_port, secret = excluded.secret')
                ->execute([$me['sub'], $email, (string)($c['label'] ?? ''), $host, $port, (string)($c['username'] ?? $email),
                    mb_encrypt($pass), time()]);
        } catch (Throwable $e) {
            error_log('mailbox import: ' . $e->getMessage());
            continue;
        }
        // נשמר במסד — הקובץ הגלוי נמחק. נכשל? לפחות לא נשאר קריא לאחרים.
        if (!@unlink($file)) @chmod($file, 0600);
        $n++;
    }
    return $n;
}

function mb_valid_host(string $host): bool
{
    return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)
        || (defined('MB_ALLOW_LOCALHOST') && $host === 'localhost');
}
