<?php
/**
 * ה-API של תיבת-מייל. כל תשובה JSON, חוץ מצרופה (קובץ).
 *
 * GET  ?action=me                      המשתמש, התיבות שלו, ייבוא חד-פעמי למנהל
 * GET  ?action=list&box=ID             50 ההודעות האחרונות
 * GET  ?action=unseen&box=ID           כמה לא נקראו (לרשימת התיבות)
 * GET  ?action=message&box=ID&uid=N    הודעה (HTML מסונן) — ומסמן אותה כנקראה
 * GET  ?action=attachment&box=&uid=&part=[&inline=1]
 * POST action=box_add | box_update | box_delete | share_add | share_remove   (JSON, כותרת X-Mailbox)
 * GET  ?action=shares&box=ID           מי משותף (רק הבעלים)
 * GET  ?action=address_info            כתובות חדשות אצלנו: זמין? כמה נוצרו מתוך כמה
 * POST action=address_create           פותח local@mbe-plus.com בשרת (lib/cpanel.php)
 * POST action=cpanel_token             מנהל בלבד: אסימון API של cPanel, נבדק ונשמר מוצפן
 *
 * הכניסה רק דרך הפלטפורמה (lib/auth.php). בקשה משנה חייבת את הכותרת X-Mailbox: אתר זר לא יכול
 * לשלוח כותרת כזו בלי preflight, וכך אין CSRF בלי אסימון.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/imap.php';
require_once __DIR__ . '/lib/sanitize.php';
require_once __DIR__ . '/lib/cpanel.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

function input(): array
{
    static $in = null;
    if ($in === null) {
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $in = [];
    }
    return $in;
}

function box_or_fail(array $me, bool $own = false): array
{
    $id = (int)($_GET['box'] ?? input()['box'] ?? 0);
    $box = $id > 0 ? mb_box($me, $id, $own) : null;
    if (!$box) fail($own ? 'רק הבעלים של התיבה יכולים לעשות את זה' : 'התיבה לא נמצאה', 404);
    return $box;
}

/** כתובות חדשות: זמין? כמה פתח המשתמש, ומה המכסה (null — בלי הגבלה, למנהל). */
function address_info(array $me): array
{
    $st = mb_db()->prepare('SELECT COUNT(*) FROM mailboxes WHERE owner_sub = ? AND created = 1');
    $st->execute([$me['sub']]);
    return ['enabled' => mb_cpanel_mode() !== null, 'domain' => MB_DOMAIN, 'used' => (int)$st->fetchColumn(),
        'limit' => $me['admin'] ? null : MB_ADDRESS_LIMIT];
}

$me = mb_identity();
if (!$me) fail('הכניסה לתיבת-מייל היא דרך הכלים הדיגיטליים בלבד', 401);

$action = (string)($_GET['action'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) {
    $action = (string)(input()['action'] ?? $action);
    if (($_SERVER['HTTP_X_MAILBOX'] ?? '') !== '1') fail('בקשה לא מורשית', 403);
}

try {
    mb_touch_user($me);

    switch ($post ? 'post:' . $action : $action) {
        case 'me':
            $imported = mb_import_files($me);
            out(['ok' => true, 'me' => ['name' => $me['name'], 'email' => $me['email'], 'admin' => $me['admin']],
                'boxes' => mb_boxes_for($me), 'imported' => $imported]);

        case 'list':
            $box = box_or_fail($me);
            $t = microtime(true);
            $res = mb_list($box);
            out(['ok' => true, 'ms' => (int)round((microtime(true) - $t) * 1000)] + $res);

        case 'unseen':
            out(['ok' => true, 'unseen' => mb_unseen(box_or_fail($me))]);

        case 'message':
            $box = box_or_fail($me);
            $m = mb_message($box, (int)($_GET['uid'] ?? 0));
            $m['html'] = $m['html'] !== '' ? mb_sanitize_html($m['html'], $m['cids']) : '';
            unset($m['cids']);
            out(['ok' => true, 'message' => $m]);

        case 'attachment':
            $box = box_or_fail($me);
            $part = (string)($_GET['part'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,9}$/', $part)) fail('חלק לא תקין');
            $a = mb_attachment($box, (int)($_GET['uid'] ?? 0), $part);
            $inline = !empty($_GET['inline']) && $a['type'] === 'application/pdf';
            $type = $inline ? 'application/pdf' : (preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', $a['type']) ? $a['type'] : 'application/octet-stream');
            // HTML / SVG לעולם לא מוצגים באתר — תמיד הורדה
            if (preg_match('#(html|svg|xml|javascript)#i', $type)) $type = 'application/octet-stream';
            header('Content-Type: ' . $type);
            header('Content-Length: ' . strlen($a['data']));
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="'
                . preg_replace('/[^\x20-\x7e]|"/', '_', $a['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($a['name']));
            echo $a['data'];
            exit;

        case 'shares':
            $box = box_or_fail($me, true);
            $st = mb_db()->prepare('SELECT s.email, s.sub IS NOT NULL AS joined, u.name FROM mailbox_shares s
                                    LEFT JOIN users u ON u.sub = s.sub WHERE s.mailbox_id = ? ORDER BY s.id');
            $st->execute([$box['id']]);
            $known = mb_db()->prepare('SELECT name, email FROM users WHERE email IS NOT NULL AND sub != ? ORDER BY last_seen DESC LIMIT 50');
            $known->execute([$me['sub']]);
            out(['ok' => true, 'shares' => array_map(fn($r) => ['email' => $r['email'], 'name' => (string)$r['name'],
                'joined' => (bool)$r['joined']], $st->fetchAll()), 'known' => $known->fetchAll()]);

        case 'post:box_add':
            $in = input();
            $email = strtolower(trim((string)($in['email'] ?? '')));
            $host = strtolower(trim((string)($in['host'] ?? '')));
            $port = (int)($in['port'] ?? 993);
            $user = trim((string)($in['username'] ?? '')) ?: $email;
            $pass = (string)($in['password'] ?? '');
            $label = mb_substr_safe(trim((string)($in['label'] ?? '')), 60);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('כתובת המייל לא תקינה');
            if (!mb_valid_host($host)) fail('שם השרת לא תקין (למשל box2054.bluehost.com)');
            if (!in_array($port, [993, 143], true)) fail('הפורט חייב להיות 993 (SSL) או 143 (STARTTLS)');
            if ($pass === '' || strlen($pass) > 500) fail('חסרה סיסמה');
            // בדיקה אמיתית לפני שמירה — תיבה שלא נפתחת לא נכנסת לרשימה
            $mb = mb_imap_open(['imap_host' => $host, 'imap_port' => $port, 'username' => $user, 'password' => $pass], OP_HALFOPEN);
            mb_imap_close($mb);
            $db = mb_db();
            $exists = $db->prepare('SELECT id FROM mailboxes WHERE owner_sub = ? AND email = ?');
            $exists->execute([$me['sub'], $email]);
            if ($id = $exists->fetchColumn()) {
                $db->prepare('UPDATE mailboxes SET imap_host = ?, imap_port = ?, username = ?, secret = ?, label = ? WHERE id = ?')
                    ->execute([$host, $port, $user, mb_encrypt($pass), $label, $id]);
            } else {
                $db->prepare('INSERT INTO mailboxes (owner_sub, email, label, imap_host, imap_port, username, secret, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$me['sub'], $email, $label, $host, $port, $user, mb_encrypt($pass), time()]);
                $id = (int)$db->lastInsertId();
            }
            out(['ok' => true, 'id' => (int)$id, 'boxes' => mb_boxes_for($me)]);

        case 'post:box_update':
            $box = box_or_fail($me, true);
            $label = mb_substr_safe(trim((string)(input()['label'] ?? '')), 60);
            mb_db()->prepare('UPDATE mailboxes SET label = ? WHERE id = ?')->execute([$label, $box['id']]);
            out(['ok' => true, 'boxes' => mb_boxes_for($me)]);

        case 'post:box_delete':
            $box = box_or_fail($me, true);
            // כתובת שהאפליקציה פתחה — נמחקת גם מהשרת, עם הדואר שבה (המשתמש אישר במפורש)
            if ($box['created']) mb_cpanel_delete($box['email']);
            mb_db()->prepare('DELETE FROM mailboxes WHERE id = ?')->execute([$box['id']]);
            out(['ok' => true, 'boxes' => mb_boxes_for($me)]);

        case 'post:cpanel_token':
            // רק מנהל הפלטפורמה (המפתח): אסימון API של cPanel, נבדק בשרת ונשמר מוצפן
            if (!$me['admin']) fail('רק מנהל המערכת', 403);
            $token = trim((string)(input()['token'] ?? ''));
            if (!preg_match('/^[A-Z0-9]{20,64}$/i', $token)) fail('האסימון לא תקין — 32 אותיות וספרות מ-cPanel');
            $db = mb_db();
            $db->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES ('cpanel', ?)")->execute([mb_encrypt(json_encode(['token' => $token]))]);
            try {
                mb_uapi('Email', 'list_pops', []);
            } catch (MbCpanelError $e) {
                $db->exec("DELETE FROM settings WHERE k = 'cpanel'");
                fail('האסימון נבדק ונכשל: ' . $e->getMessage());
            }
            out(['ok' => true] + address_info($me));

        case 'address_info':
            out(['ok' => true] + address_info($me));

        case 'post:address_create':
            $info = address_info($me);
            if (!$info['enabled']) fail($me['admin'] ? 'יצירת כתובות עוד לא הופעלה: צריך אסימון API ב-~/.mailbox/cpanel.json (README)' : 'יצירת כתובות לא זמינה כרגע');
            if ($info['limit'] !== null && $info['used'] >= $info['limit']) fail('אפשר לפתוח עד ' . $info['limit'] . ' כתובות');
            $local = strtolower(trim((string)(input()['local'] ?? '')));
            if ($err = mb_local_part_error($local)) fail($err);
            $email = $local . '@' . MB_DOMAIN;
            $taken = mb_db()->prepare('SELECT 1 FROM mailboxes WHERE email = ? LIMIT 1');
            $taken->execute([$email]);
            if ($taken->fetchColumn()) fail('הכתובת ' . $email . ' כבר תפוסה');
            $label = mb_substr_safe(trim((string)(input()['label'] ?? '')), 60);
            $pass = mb_cpanel_create($local);
            $db = mb_db();
            $db->prepare('INSERT INTO mailboxes (owner_sub, email, label, imap_host, imap_port, username, secret, created_at, created)
                          VALUES (?, ?, ?, ?, 993, ?, ?, ?, 1)')
                ->execute([$me['sub'], $email, $label, defined('MB_DEV_IMAP_HOST') ? MB_DEV_IMAP_HOST : MB_IMAP_HOST, $email, mb_encrypt($pass), time()]);
            out(['ok' => true, 'id' => (int)$db->lastInsertId(), 'email' => $email, 'boxes' => mb_boxes_for($me)]);

        case 'post:share_add':
            $box = box_or_fail($me, true);
            $email = strtolower(trim((string)(input()['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('כתובת המייל לא תקינה');
            if ($email === $me['email']) fail('זו הכתובת שלך — התיבה כבר שלך');
            $known = mb_db()->prepare('SELECT sub FROM users WHERE email = ? LIMIT 1');
            $known->execute([$email]);
            mb_db()->prepare('INSERT OR IGNORE INTO mailbox_shares (mailbox_id, email, sub, perm, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$box['id'], $email, $known->fetchColumn() ?: null, 'read', time()]);
            out(['ok' => true]);

        case 'post:share_remove':
            $box = box_or_fail($me, true);
            mb_db()->prepare('DELETE FROM mailbox_shares WHERE mailbox_id = ? AND email = ?')
                ->execute([$box['id'], strtolower(trim((string)(input()['email'] ?? '')))]);
            out(['ok' => true]);

        default:
            fail('פעולה לא מוכרת', 404);
    }
} catch (MbCpanelError $e) {
    fail($e->getMessage(), 502);
} catch (MbImapError $e) {
    fail($e->getMessage(), 502);
} catch (Throwable $e) {
    error_log('mailbox: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('שגיאה בשרת', 500);
}
