<?php
/**
 * בדיקות תקינות (אפיון 01 §9.4) — למסך המפתח. הבדיקות נרשמות ברישום אחד, apr_health_register(), כדי שכל פרוסה
 * תוסיף את שלה (ספק בינה, ערוץ הודעות, מקור חיצוני). כל בדיקה מחזירה [state, detail]: ok · warn · bad, ומשפט שאומר
 * מה נמצא ומה לעשות. בדיקה שזורקת לא מפילה את האחרות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/jobs.php';

function apr_app_json(): array
{
    $app = json_decode((string)@file_get_contents(dirname(__DIR__) . '/app.json'), true);
    return is_array($app) ? $app : [];
}

function apr_app_version(): string
{
    return (string)(apr_app_json()['version'] ?? '');
}

function apr_health_register(string $id, string $label, callable $check): void
{
    $GLOBALS['apr_health'][$id] = ['label' => $label, 'check' => $check];
}

/** הכתובת של קובץ באפליקציה, כפי שהדפדפן רואה אותה (לבדיקה עצמית). */
function apr_self_url(string $path): string
{
    return apr_app_url($path);   // הכתובת הקבועה מ-app.json — לא מהבקשה
}

function apr_health(): array
{
    $checks = [];
    foreach ($GLOBALS['apr_health'] ?? [] as $id => $h) {
        try {
            [$state, $detail] = ($h['check'])();
        } catch (Throwable $e) {
            [$state, $detail] = ['bad', 'הבדיקה עצמה נכשלה: ' . get_class($e)];
        }
        $checks[] = ['id' => $id, 'label' => $h['label'], 'state' => $state, 'detail' => $detail];
    }
    $worst = array_reduce($checks, fn($w, $c) => $c['state'] === 'bad' ? 'bad' : ($c['state'] === 'warn' && $w !== 'bad' ? 'warn' : $w), 'ok');
    return ['state' => $worst, 'at' => apr_now(), 'checks' => $checks];
}

/** בדיקת החיים הציבורית — בלי פירוט: המסד נפתח והסכימה מעודכנת. */
function apr_health_public(): array
{
    try {
        $ok = apr_schema_version(apr_db()) === apr_schema_expected();
    } catch (Throwable) {
        $ok = false;
    }
    return ['ok' => $ok, 'version' => apr_app_version()];
}

// ── הבדיקות של הפרוסה הזו ────────────────────────────────────

apr_health_register('db', 'המסד נפתח', function () {
    $db = apr_db();
    $r = (string)$db->query('PRAGMA quick_check')->fetchColumn();
    if ($r !== 'ok') return ['bad', 'בדיקת השלמות נכשלה. לשחזר מגיבוי.'];
    if (!(int)$db->query('PRAGMA foreign_keys')->fetchColumn()) return ['bad', 'מפתחות זרים כבויים'];
    $mode = (string)$db->query('PRAGMA journal_mode')->fetchColumn();
    $path = apr_db_path();
    if ($path !== ':memory:' && $mode !== 'wal') return ['warn', "המסד שלם, אבל במצב $mode ולא WAL"];
    $size = $path !== ':memory:' && is_file($path) ? round(filesize($path) / 1048576, 2) . 'MB' : 'בזיכרון';
    return ['ok', "שלם · $size · SQLite " . $db->query('SELECT sqlite_version()')->fetchColumn()];
});

apr_health_register('schema', 'גרסת הסכימה', function () {
    $have = apr_schema_version(apr_db());
    $want = apr_schema_expected();
    return $have === $want ? ['ok', "מיגרציה $have מתוך $want"] : ['bad', "המסד במיגרציה $have, והקוד מצפה ל-$want. לבדוק ביומן למה המיגרציה נכשלה."];
});

apr_health_register('write', 'הרשאות כתיבה', function () {
    $dir = apr_data_dir();
    foreach (['', '/files', '/_dev'] as $sub) {
        $d = $dir . $sub;
        if (!is_dir($d) && !@mkdir($d, 0700, true)) return ['bad', "אי אפשר ליצור את data$sub/. לתת לשרת הרשאת כתיבה לתיקיית data."];
        $probe = $d . '/.probe-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'x') === false) return ['bad', "אין הרשאת כתיבה ל-data$sub/"];
        @unlink($probe);
    }
    return ['ok', 'data/, data/files/ ו-data/_dev/ ניתנות לכתיבה'];
});

apr_health_register('protect', 'data/ חסומה מהדפדפן', function () {
    $ht = apr_data_dir() . '/.htaccess';
    if (!is_file($ht) || !str_contains((string)file_get_contents($ht), 'denied')) return ['bad', 'חסר data/.htaccess — המסד עלול להיות נגיש מהדפדפן'];
    $res = apr_http_get(apr_self_url('data/app.sqlite'));
    if ($res === null) return ['warn', 'data/.htaccess קיים; הבדיקה מבחוץ לא הצליחה להתחבר'];
    return $res[0] === 200 ? ['bad', 'המסד נגיש מהדפדפן! לבדוק את data/.htaccess מיד'] : ['ok', "data/.htaccess קיים, ובקשה מבחוץ למסד נדחית ({$res[0]})"];
});

apr_health_register('secret', 'מפתח ההצפנה', function () {
    $f = apr_data_dir() . '/secret.key';
    apr_secret();
    if (!is_file($f) || filesize($f) !== 32) return ['bad', 'data/secret.key חסר או באורך שגוי'];
    $res = apr_http_get(apr_self_url('data/secret.key'));
    return $res !== null && $res[0] === 200 ? ['bad', 'המפתח נגיש מהדפדפן!'] : ['ok', 'קיים, באורך הנכון, ולא נגיש מהדפדפן'];
});

apr_health_register('php', 'PHP והרחבות', function () {
    if (version_compare(PHP_VERSION, '8.3', '<')) return ['bad', 'PHP ' . PHP_VERSION . ' — נדרשת 8.3 ומעלה'];
    $need = ['pdo_sqlite' => 'התשתית', 'mbstring' => 'התשתית', 'fileinfo' => 'התשתית', 'sodium' => 'מפתחות שירות (11)',
        'curl' => 'בדיקות וחיבורים (09, 11)', 'zip' => 'יצוא (18, 21)', 'bcmath' => 'חישובים (15)'];
    $missing = [];
    foreach ($need as $ext => $for) if (!extension_loaded($ext)) $missing[] = "$ext — $for";
    $hard = array_filter($missing, fn($m) => str_contains($m, 'התשתית'));
    return $missing ? [$hard ? 'bad' : 'warn', 'חסר: ' . implode('; ', $missing)] : ['ok', 'PHP ' . PHP_VERSION . ' · ' . implode(', ', array_keys($need))];
});

apr_health_register('version', 'גרסה', function () {
    $v = apr_app_version();
    $m = @filemtime(dirname(__DIR__) . '/api.php');
    return [$v === '' ? 'bad' : 'ok', 'גרסה ' . ($v ?: '?') . ($m ? ' · הקוד עודכן ' . apr_iso($m) : '')];
});

apr_health_register('platform', 'המעטפת', function () {
    if ((apr_app_json()['gate'] ?? false) !== true) return ['bad', 'app.json: "gate" אינו true — האפליקציה חייבת להיות מחוברת'];
    $cfg = apr_platform_config(true);
    if ($cfg === null) return ['warn', 'המעטפת לא ענתה לשאלה אם האפליקציה מחוברת (sso.php?action=config)'];
    return $cfg['connected'] ? ['ok', 'המעטפת עונה, והאפליקציה מחוברת'] : ['bad', 'האפליקציה מנותקת במעטפת — הכניסה חסומה עד שתחובר'];
});

apr_health_register('devloop', 'העותק של DevLoop', function () {
    $root = dirname(__DIR__);
    $missing = array_filter(['lib/devloop.php', 'assets/js/devloop.js', 'assets/css/devloop.css'], fn($f) => !is_file("$root/$f"));
    return $missing ? ['bad', 'חסר: ' . implode(', ', $missing)] : ['ok', 'שלושת הקבצים קיימים'];
});

apr_health_register('roadmap', 'מפת הדרכים', function () {
    $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/dev/open-items.json'), true);
    return is_array($j['items'] ?? null) ? ['ok', count($j['items']) . ' מטלות ב-dev/open-items.json'] : ['bad', 'dev/open-items.json חסר או אינו JSON תקין'];
});

apr_health_register('disk', 'מקום בדיסק', function () {
    $dir = apr_data_dir();
    $free = @disk_free_space(is_dir($dir) ? $dir : dirname($dir));
    if ($free === false) return ['warn', 'לא ניתן לקרוא את המקום הפנוי'];
    $mb = (int)round($free / 1048576);
    return [$mb < 200 ? 'warn' : 'ok', number_format($mb) . 'MB פנויים' . ($mb < 200 ? ' — פחות מ-200MB' : '')];
});

apr_health_register('cron', 'המתזמן', function () {
    $ok = apr_meta('cron_last_ok_at');
    if ($ok === null) return ['warn', 'עוד לא רץ. להגדיר מתזמן ב-cPanel לפי הכתובת שבלשונית "שירותי רקע"; עד אז רץ סבב גיבוי מבקשות רגילות.'];
    $age = apr_time() - strtotime($ok);
    $src = apr_meta('cron_last_source') === 'cron' ? '' : ' (סבב גיבוי — המתזמן עצמו עוד לא הוגדר)';
    return $age > 900 ? ['warn', 'הצליח לאחרונה לפני ' . intdiv($age, 60) . ' דקות. לבדוק את המתזמן ב-cPanel.'] : ['ok', 'הצליח לפני ' . max(0, intdiv($age, 60)) . ' דקות' . $src];
});

apr_health_register('queue', 'תור הרקע', function () {
    $bad = (int)apr_val("SELECT COUNT(*) FROM jobs WHERE status IN ('failed', 'needs_action')");
    $stuck = (int)apr_val("SELECT COUNT(*) FROM jobs WHERE status = 'running' AND started_at < ?", [apr_iso(apr_time() - APR_JOB_STUCK)]);
    if ($bad || $stuck) return ['bad', ($bad ? "$bad משימות נכשלו או דורשות טיפול" : '') . ($bad && $stuck ? '; ' : '') . ($stuck ? "$stuck משימות תקועות מעל 10 דקות" : '') . '. בלשונית "שירותי רקע".'];
    return ['ok', (int)apr_val("SELECT COUNT(*) FROM jobs WHERE status = 'pending'") . ' ממתינות, אין כשלים'];
});

apr_health_register('platform_key', 'מפתח החתימה מול המעטפת', function () {
    $k = apr_pkey();
    return ['ok', 'מפתח פעיל ' . $k['kid'] . ' (הפרטי מוצפן במסד)'];
});

apr_health_register('sso_clock', 'שעון השרת (כניסה מאוחדת)', function () {
    $at = (int)apr_meta('sso_expired_at', '0');
    return $at > apr_time() - 86400
        ? ['warn', 'זהות חתומה נדחתה כי פג תוקפה (' . apr_iso($at) . ') — אם זה חוזר, שעון השרת סוטה']
        : ['ok', 'לא נדחתה זהות בגלל תוקף ב-24 השעות האחרונות'];
});

apr_health_register('mail', 'שליחת מייל', fn() => function_exists('mail') ? ['ok', 'הפונקציה mail זמינה (לא נשלח מייל בבדיקה)'] : ['warn', 'הפונקציה mail אינה זמינה — תידרש בפרוסה 07']);
