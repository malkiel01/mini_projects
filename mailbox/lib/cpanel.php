<?php
/**
 * כתובות חדשות אצלנו (@mbe-plus.com) — האפליקציה פותחת ומוחקת תיבות בשרת בעצמה, דרך UAPI של cPanel.
 * אף משתמש לא נכנס ל-cPanel; זה אזור של המפתח בלבד (הכרעת בעל המערכת, 2026-10-26).
 *
 * שתי דרכים, לפי הסדר:
 *   1. אסימון API — ~/.mailbox/cpanel.json: {"token": "…", "user": "mbeplusc", "host": "box2054.bluehost.com"}.
 *      בכניסה הראשונה של מנהל הפלטפורמה הקובץ נקרא, נשמר מוצפן במסד (settings), ונמחק — כמו demo.json.
 *   2. בלי אסימון — הפקודה uapi של cPanel, אם PHP רשאי להריץ אותה (אותו משתמש cPanel, בלי סיסמה).
 * אף אחת לא זמינה — "יצירת כתובות לא הופעלה", והשאר עובד כרגיל.
 *
 * הסיסמה של תיבה שנוצרה כאן — אקראית, מוצפנת במסד; המשתמש לא צריך אותה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const MB_DOMAIN = 'mbe-plus.com';
const MB_IMAP_HOST = 'box2054.bluehost.com';   // לא mail.mbe-plus.com — שם התעודה לא מתאימה
const MB_ADDRESS_LIMIT = 3;                     // למשתמש; למנהל הפלטפורמה — בלי הגבלה
const MB_QUOTA_MB = 1024;
const MB_RESERVED = ['admin', 'administrator', 'root', 'postmaster', 'hostmaster', 'webmaster', 'abuse', 'security',
    'mailer-daemon', 'noreply', 'no-reply', 'support', 'info', 'office', 'billing', 'cpanel', 'demo', 'test', 'mail',
    'www', 'ftp', 'dmarc', 'bounce', 'bounces', 'sales', 'contact', 'help'];

final class MbCpanelError extends RuntimeException {}

/** ההגדרות (מוצפנות במסד), או null. */
function mb_cpanel_config(): ?array
{
    $row = mb_db()->query("SELECT v FROM settings WHERE k = 'cpanel'")->fetchColumn();
    if (!$row) return null;
    $c = json_decode(mb_decrypt((string)$row), true);
    return is_array($c) && !empty($c['token']) ? $c : null;
}

/** ייבוא חד-פעמי של ~/.mailbox/cpanel.json (רק מנהל, נקרא מ-mb_import_files). */
function mb_cpanel_import(string $file): bool
{
    $c = json_decode((string)@file_get_contents($file), true);
    $token = is_array($c) ? trim((string)($c['token'] ?? '')) : '';
    if (!preg_match('/^[A-Z0-9]{20,64}$/i', $token)) {
        error_log('mailbox: cpanel.json — אסימון לא תקין');
        return false;
    }
    $cfg = ['token' => $token];
    foreach (['user', 'host'] as $k) {
        if (isset($c[$k]) && preg_match('/^[a-z0-9._-]{1,64}$/i', (string)$c[$k])) $cfg[$k] = (string)$c[$k];
    }
    mb_db()->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES ('cpanel', ?)")
        ->execute([mb_encrypt(json_encode($cfg))]);
    if (!@unlink($file)) @chmod($file, 0600);
    return true;
}

function mb_cpanel_user(?array $cfg): string
{
    if (!empty($cfg['user'])) return $cfg['user'];
    if (function_exists('posix_getpwuid')) return (string)(posix_getpwuid(posix_geteuid())['name'] ?? '');
    return get_current_user();
}

function mb_uapi_bin(): ?string
{
    if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        return null;
    }
    foreach (['/usr/local/cpanel/bin/uapi', '/usr/bin/uapi'] as $bin) {
        if (@is_executable($bin)) return $bin;
    }
    return null;
}

/** האם אפשר לפתוח כתובות: 'token' / 'uapi' / null. */
function mb_cpanel_mode(): ?string
{
    if (mb_cpanel_config()) return 'token';
    return mb_uapi_bin() ? 'uapi' : null;
}

/** קריאה ל-UAPI. מחזיר את data, או זורק MbCpanelError עם השגיאה של cPanel. */
function mb_uapi(string $module, string $func, array $params): mixed
{
    $mode = mb_cpanel_mode();
    if ($mode === 'token') {
        $cfg = mb_cpanel_config() ?? [];
        $base = defined('MB_CPANEL_ENDPOINT') ? MB_CPANEL_ENDPOINT : 'https://' . ($cfg['host'] ?? MB_IMAP_HOST) . ':2083';
        $ch = curl_init($base . '/execute/' . $module . '/' . $func);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . mb_cpanel_user($cfg) . ':' . ($cfg['token'] ?? 'dev')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new MbCpanelError('השרת לא ענה (' . $err . ')');
        if ($code === 401 || $code === 403) throw new MbCpanelError('האסימון של cPanel לא תקף');
    } elseif ($mode === 'uapi') {
        $args = [mb_uapi_bin(), '--output=json', $module, $func];
        foreach ($params as $k => $v) $args[] = $k . '=' . $v;
        $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) throw new MbCpanelError('לא ניתן להריץ את uapi');
        $raw = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
    } else {
        throw new MbCpanelError('יצירת כתובות עוד לא הופעלה בשרת');
    }
    $res = json_decode((string)$raw, true);
    $res = $res['result'] ?? $res;   // CLI עוטף ב-result
    if (!is_array($res) || !array_key_exists('status', $res)) throw new MbCpanelError('תשובה לא מובנת מהשרת');
    if (!$res['status']) {
        $msg = implode(' ', array_map('strval', (array)($res['errors'] ?? [])));
        throw new MbCpanelError($msg !== '' ? $msg : 'הפעולה נכשלה בשרת');
    }
    return $res['data'] ?? null;
}

/** בדיקת שם — מחזיר הודעת שגיאה, או null כשתקין. */
function mb_local_part_error(string $local): ?string
{
    if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,28}[a-z0-9])?$/', $local)) {
        return 'השם: 1–30 אותיות באנגלית, ספרות, נקודה, מקף או קו תחתון — בלי רווחים, ולא בהתחלה או בסוף';
    }
    if (preg_match('/[._-]{2}/', $local)) return 'אי אפשר שני סימנים ברצף (.. / -- / __)';
    if (in_array($local, MB_RESERVED, true)) return 'השם הזה שמור למערכת. נסו שם אחר';
    return null;
}

/** פותח תיבה בשרת. מחזיר את הסיסמה האקראית שנקבעה לה. */
function mb_cpanel_create(string $local): string
{
    $pass = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Kq'), '=') . '#7a';
    try {
        mb_uapi('Email', 'add_pop', ['email' => $local, 'domain' => MB_DOMAIN, 'password' => $pass,
            'quota' => MB_QUOTA_MB, 'skip_update_db' => 0]);
    } catch (MbCpanelError $e) {
        if (stripos($e->getMessage(), 'exists') !== false) throw new MbCpanelError('הכתובת ' . $local . '@' . MB_DOMAIN . ' כבר תפוסה');
        throw $e;
    }
    return $pass;
}

function mb_cpanel_delete(string $email): void
{
    [$local, $domain] = explode('@', $email, 2);
    mb_uapi('Email', 'delete_pop', ['email' => $local, 'domain' => $domain]);
}
