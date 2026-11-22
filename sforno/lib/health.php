<?php
/**
 * בדיקות תקינות ויכולות השרת (אפיון 01 §8.4). רישום אחד — sf_health_register() — כדי שכל פרוסה תוסיף את שלה.
 * כל בדיקה מחזירה [state, detail]: ok · warn · bad, ומשפט שאומר מה נמצא ומה לעשות. בדיקה שזורקת לא מפילה אחרות.
 * השורות שמסומנות §24 הן התשובה לשורת "תשתית" של האפיון — מועתקות ל-dev/server-report.md אחרי הפריסה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/jobs.php';

function sf_app_json(): array
{
    $app = json_decode((string)@file_get_contents(sf_root() . '/app.json'), true);
    return is_array($app) ? $app : [];
}

function sf_version(): string
{
    return (string)(sf_app_json()['version'] ?? '');
}

function sf_health_register(string $id, string $label, callable $check, bool $s24 = false): void
{
    $GLOBALS['sf_health'][$id] = ['label' => $label, 'check' => $check, 's24' => $s24];
}

function sf_health(): array
{
    $checks = [];
    foreach ($GLOBALS['sf_health'] ?? [] as $id => $h) {
        try {
            [$state, $detail] = ($h['check'])();
        } catch (Throwable $e) {
            [$state, $detail] = ['bad', 'הבדיקה עצמה נכשלה: ' . get_class($e)];
        }
        $checks[] = ['id' => $id, 'label' => $h['label'], 's24' => $h['s24'], 'state' => $state, 'detail' => $detail];
    }
    $rank = ['ok' => 0, 'warn' => 1, 'bad' => 2];
    $worst = array_reduce($checks, fn($w, $c) => $rank[$c['state']] > $rank[$w] ? $c['state'] : $w, 'ok');
    return ['state' => $worst, 'at' => sf_now(), 'version' => sf_version(), 'checks' => $checks];
}

/** health בלי משתמש: רק ok ו-version (§9). */
function sf_health_public(): array
{
    try {
        $ok = sf_schema_version(sf_db()) === sf_schema_expected();
    } catch (Throwable) {
        $ok = false;
    }
    return ['ok' => $ok, 'version' => sf_version()];
}

/** שליחת מייל ניסיון — רק לכתובת של המפתח שלחץ. */
function sf_mail_test(array $staff): array
{
    if (!function_exists('mail')) sf_fail('bad_input', 'הפונקציה mail אינה זמינה בשרת');
    $to = (string)($staff['email'] ?? '');
    if ($to === '' || !(int)$staff['email_verified']) sf_fail('bad_input', 'אין לחשבון שלך מייל מאומת לשליחת הניסיון');
    $sent = isset($GLOBALS['sf_mail']) ? ($GLOBALS['sf_mail'])($to) : @mail($to, '=?UTF-8?B?' . base64_encode('ספורנו — מייל ניסיון') . '?=',
        "זהו מייל ניסיון ממסך הבדיקות של ספורנו.\n" . sf_now(), "Content-Type: text/plain; charset=UTF-8\r\n");
    sf_meta_set('mail_test_at', sf_now());
    sf_meta_set('mail_test_ok', $sent ? '1' : '0');
    return ['sent' => (bool)$sent, 'to' => $to];
}

function sf_ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

// ── הבדיקות של הפרוסה ────────────────────────────────────────

sf_health_register('db', 'המסד', function () {
    $db = sf_db();
    if (sf_driver($db) !== 'sqlite') return ['ok', 'מנוע ' . sf_driver($db) . ' — נפתח'];
    if ((string)$db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') return ['bad', 'בדיקת השלמות נכשלה. לשחזר מגיבוי.'];
    if (!(int)$db->query('PRAGMA foreign_keys')->fetchColumn()) return ['bad', 'מפתחות זרים כבויים'];
    $mode = (string)$db->query('PRAGMA journal_mode')->fetchColumn();
    if (sf_db_path() !== ':memory:' && $mode !== 'wal') return ['warn', "המסד שלם, אבל במצב $mode ולא WAL"];
    $size = sf_db_path() !== ':memory:' && is_file(sf_db_path()) ? round(filesize(sf_db_path()) / 1048576, 2) . 'MB' : 'בזיכרון';
    return ['ok', "quick_check תקין · WAL · מפתחות זרים · $size · SQLite " . $db->query('SELECT sqlite_version()')->fetchColumn()];
});

sf_health_register('schema', 'גרסת הסכימה', function () {
    $have = sf_schema_version(sf_db());
    $want = sf_schema_expected();
    return $have === $want ? ['ok', "מיגרציה $have מתוך $want"] : ['bad', "המסד במיגרציה $have והקוד מצפה ל-$want. לבדוק ביומן למה המיגרציה נכשלה."];
});

sf_health_register('write', 'כתיבה', function () {
    foreach (['', '/_dev'] as $sub) {
        $d = sf_data_dir() . $sub;
        if (!is_dir($d) && !@mkdir($d, 0700, true)) return ['bad', "אי אפשר ליצור את data$sub/. לתת לשרת הרשאת כתיבה."];
        $probe = $d . '/.probe-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'x') === false) return ['bad', "אין הרשאת כתיבה ל-data$sub/"];
        @unlink($probe);
    }
    return ['ok', 'data/ ו-data/_dev/ ניתנות לכתיבה'];
});

sf_health_register('protect', 'data/ חסומה מהדפדפן', function () {
    $ht = sf_data_dir() . '/.htaccess';
    if (!is_file($ht) || !str_contains((string)file_get_contents($ht), 'denied')) return ['bad', 'חסר data/.htaccess — המסד עלול להיות נגיש מהדפדפן'];
    $res = sf_http_get(sf_self_url('data/sforno.sqlite'), 5);
    if ($res === null) return ['warn', 'data/.htaccess קיים; הבקשה העצמית לא הצליחה להתחבר'];
    return $res[0] === 200 ? ['bad', 'המסד נגיש מהדפדפן! לבדוק את data/.htaccess מיד'] : ['ok', "data/.htaccess קיים, ובקשה עצמית ל-data/sforno.sqlite נדחית ({$res[0]})"];
});

sf_health_register('php', 'PHP', function () {
    $need = ['pdo_sqlite', 'sodium', 'curl', 'mbstring', 'json'];
    $missing = array_values(array_filter($need, fn($e) => !extension_loaded($e)));
    if (version_compare(PHP_VERSION, '8.3', '<')) return ['bad', 'PHP ' . PHP_VERSION . ' — נדרשת 8.3 ומעלה'];
    return $missing ? ['bad', 'PHP ' . PHP_VERSION . ' · חסר: ' . implode(', ', $missing)] : ['ok', 'PHP ' . PHP_VERSION . ' · ' . implode(', ', $need) . ' · ' . PHP_SAPI];
}, true);

sf_health_register('ext', 'הרחבות לפרוסות הבאות', function () {
    $want = ['intl' => 'לוח עברי (07)', 'dom' => 'ניקוי HTML (02)', 'libxml' => 'ניקוי HTML (02)', 'zip' => 'Word / EPUB (03)',
        'gd|imagick' => 'תמונות (03)', 'pdo_mysql' => 'אם ש2 תשתנה', 'openssl' => 'Web Push (08)'];
    $have = $miss = [];
    foreach ($want as $ext => $for) {
        $any = array_filter(explode('|', $ext), 'extension_loaded');
        $any ? $have[] = implode('/', $any) : $miss[] = str_replace('|', ' או ', $ext) . " — $for";
    }
    return $miss ? ['warn', 'חסר: ' . implode('; ', $miss) . ($have ? ' · יש: ' . implode(', ', $have) : '')] : ['ok', 'יש: ' . implode(', ', $have)];
}, true);

sf_health_register('limits', 'מגבלות', function () {
    $keys = ['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time'];
    $txt = implode(' · ', array_map(fn($k) => "$k = " . ini_get($k), $keys));
    return [sf_ini_bytes((string)ini_get('upload_max_filesize')) < (8 << 20) ? 'warn' : 'ok', $txt];
}, true);

sf_health_register('outbound', 'גישה החוצה', function () {
    $res = sf_http_get('https://www.google.com/generate_204', 5);
    if ($res === null) return ['bad', 'בקשת HTTPS החוצה נכשלה (google.com/generate_204) — Google בכניסה ו-WooCommerce לא יעבדו'];
    $ms = (int)($res[2] ?? 0);
    return $res[0] === 204 && $ms <= 5000 ? ['ok', "HTTPS החוצה עובד · 204 בתוך {$ms}ms"] : ['warn', "HTTPS החוצה החזיר {$res[0]} בתוך {$ms}ms"];
}, true);

sf_health_register('mail', 'אימייל', function () {
    if (!function_exists('mail')) return ['bad', 'הפונקציה mail אינה זמינה — תזכורות ואיפוס סיסמה ידרשו ספק'];
    $at = sf_meta('mail_test_at');
    $tail = $at ? ' · ניסיון אחרון ' . $at . (sf_meta('mail_test_ok') === '1' ? ' — mail() החזירה הצלחה (לבדוק שהגיע)' : ' — mail() נכשלה') : ' · לא נשלח בבדיקה; "שליחת ניסיון" שולחת לכתובת שלך';
    return [$at && sf_meta('mail_test_ok') !== '1' ? 'warn' : 'ok', 'mail() זמינה · sendmail_path = ' . (ini_get('sendmail_path') ?: '—') . $tail];
}, true);

sf_health_register('disk', 'נפח', function () {
    $dir = sf_data_dir();
    $free = @disk_free_space(is_dir($dir) ? $dir : sf_root());
    if ($free === false) return ['warn', 'לא ניתן לקרוא את המקום הפנוי'];
    $mb = (int)round($free / 1048576);
    return [$mb < 500 ? 'warn' : 'ok', number_format($mb) . 'MB פנויים' . ($mb < 500 ? ' — פחות מ-500MB' : '')];
}, true);

sf_health_register('cron', 'המתזמן', function () {
    $ok = sf_meta('cron_last_ok_at');
    $how = 'להגדיר ב-cPanel → Cron Jobs, כל 5 דקות, את הפקודה שבלשונית "⚙️ שירותי רקע".';
    if ($ok === null) return ['warn', 'עוד לא רץ. ' . $how];
    $age = sf_time() - (int)strtotime($ok);
    return $age > 900 ? ['warn', 'הצליח לאחרונה לפני ' . intdiv($age, 60) . ' דקות. ' . $how] : ['ok', 'הצליח לפני ' . max(0, intdiv($age, 60)) . ' דקות'];
}, true);

sf_health_register('queue', 'תור הרקע', function () {
    $failed = (int)sf_val("SELECT COUNT(*) FROM jobs WHERE status = 'failed'");
    $stuck = (int)sf_val("SELECT COUNT(*) FROM jobs WHERE status = 'running' AND started_at < ?", [sf_iso(sf_time() - SF_JOB_STUCK)]);
    if ($failed || $stuck) return ['bad', trim(($failed ? "$failed משימות נכשלו. " : '') . ($stuck ? "$stuck תקועות מעל 10 דקות. " : '')) . ' בלשונית "⚙️ שירותי רקע".'];
    return ['ok', (int)sf_val("SELECT COUNT(*) FROM jobs WHERE status = 'pending'") . ' ממתינות, אין כשלים'];
});

sf_health_register('version', 'גרסה', function () {
    $v = sf_version();
    return [$v === '' ? 'bad' : 'ok', 'גרסה ' . ($v ?: '?') . ' (app.json)'];
});

sf_health_register('platform', 'המעטפת', function () {
    $cfg = sf_platform_config(true);
    if ($cfg === null) return ['bad', 'sso.php?action=config לא עונה (' . sf_platform_base() . ') — הכניסה תחזיר 503'];
    return $cfg['connected'] ? ['ok', 'sso.php?action=config עונה · connected = true'] : ['bad', 'ספורנו מנותקת במעטפת — הכניסה חסומה עד שתחובר (פרוסה 11)'];
});

sf_health_register('devloop', 'DevLoop', function () {
    $missing = array_values(array_filter(['lib/devloop.php', 'assets/js/devloop.js', 'assets/css/devloop.css'], fn($f) => !is_file(sf_root() . "/$f")));
    if ($missing) return ['bad', 'חסר: ' . implode(', ', $missing)];
    $j = json_decode((string)@file_get_contents(sf_root() . '/dev/open-items.json'), true);
    return is_array($j['items'] ?? null) ? ['ok', 'שלושת העותקים קיימים · ' . count($j['items']) . ' מטלות ב-dev/open-items.json'] : ['bad', 'dev/open-items.json חסר או פגום'];
});

sf_health_register('standalone', 'עצמאות', function () {
    $bad = sf_standalone_scan();
    return $bad ? ['bad', 'קריאה לקובץ מחוץ לתיקייה: ' . implode(', ', array_slice($bad, 0, 5))] : ['ok', 'אין require / include מחוץ לתיקייה (lib/, api.php, cron.php)'];
});

/** קריאה לקובץ שמחוץ לתיקייה (נתיב שעולה למעלה) (קריטריון 15). מחזיר רשימת "קובץ:שורה". */
function sf_standalone_scan(): array
{
    $files = array_merge(glob(sf_root() . '/lib/*.php') ?: [], glob(sf_root() . '/lib/migrations/*.php') ?: [], [sf_root() . '/api.php', sf_root() . '/cron.php']);
    $bad = [];
    foreach ($files as $f) {
        foreach (file($f) ?: [] as $n => $line) {
            if (preg_match('/(require|include)[^;]*\.\.\//', $line)) $bad[] = basename($f) . ':' . ($n + 1);
        }
    }
    return $bad;
}
