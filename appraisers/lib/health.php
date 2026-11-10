<?php
/**
 * בדיקות תקינות — למסך המפתח. כל בדיקה מחזירה: id, label, state (ok | warn | bad) ו-detail — משפט שאומר מה נמצא.
 * בדיקה שנכשלת לא מפילה את האחרות: חריגה בבדיקה הופכת ל-bad עם ההודעה.
 *
 * חיבורים חיצוניים ושירותי רקע נרשמים כאן כשהם נבנים: חיבור — ב-apr_connections(); שירות — קורא ל-apr_service_beat()
 * בכל ריצה, ומופיע לבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function apr_app_version(): string
{
    $app = json_decode((string)@file_get_contents(dirname(__DIR__) . '/app.json'), true);
    return is_array($app) ? (string)($app['version'] ?? '') : '';
}

/** שירות רקע מדווח שרץ. $error ריק = הצליח. $everySec — כל כמה זמן הוא אמור לרוץ (0 = לפי דרישה). */
function apr_service_beat(string $name, string $label, int $everySec, string $error = ''): void
{
    $now = apr_now();
    apr_exec('INSERT INTO service_state (name, label, every_sec, last_run_at, last_ok_at, last_error) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(name) DO UPDATE SET label = excluded.label, every_sec = excluded.every_sec, last_run_at = excluded.last_run_at,
            last_ok_at = COALESCE(excluded.last_ok_at, service_state.last_ok_at), last_error = excluded.last_error',
        [$name, $label, $everySec, $now, $error === '' ? $now : null, mb_substr($error, 0, 500)]);
}

/**
 * החיבורים החיצוניים של האפליקציה: id => ['label' => …, 'check' => fn(): array{state, detail}].
 * עוד אין — כל חיבור שנבנה (מייל, רשויות, בינה) מוסיף את עצמו כאן, עם בדיקה שלא שולחת מידע של תיק.
 */
function apr_connections(): array
{
    return $GLOBALS['apr_connections'] ?? [];
}

function apr_health(): array
{
    $checks = [];
    $add = function (string $id, string $label, callable $fn) use (&$checks) {
        try {
            [$state, $detail] = $fn();
        } catch (Throwable $e) {
            [$state, $detail] = ['bad', $e->getMessage()];
        }
        $checks[] = ['id' => $id, 'label' => $label, 'state' => $state, 'detail' => $detail];
    };
    $dir = apr_data_dir();

    $add('db', 'מסד הנתונים', function () {
        $r = (string)apr_db()->query('PRAGMA quick_check')->fetchColumn();
        if ($r !== 'ok') return ['bad', 'בדיקת שלמות נכשלה: ' . mb_substr($r, 0, 200)];
        $path = apr_db_path();
        $size = $path !== ':memory:' && is_file($path) ? round(filesize($path) / 1048576, 2) . 'MB' : 'בזיכרון';
        return ['ok', "שלם · $size · SQLite " . apr_db()->query('SELECT sqlite_version()')->fetchColumn()];
    });
    $add('schema', 'מבנה המסד', function () {
        $have = apr_schema_version(apr_db());
        $want = apr_schema_expected();
        return $have === $want ? ['ok', "מיגרציה $have מתוך $want"] : ['bad', "המסד במיגרציה $have, הקוד מצפה ל-$want"];
    });
    $add('write', 'הרשאות כתיבה', function () use ($dir) {
        foreach ([$dir, $dir . '/devloop'] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0700, true)) return ['bad', 'אי אפשר ליצור את ' . basename($d) . '/'];
            $probe = $d . '/.probe-' . bin2hex(random_bytes(4));
            if (@file_put_contents($probe, 'x') === false) return ['bad', 'אין הרשאת כתיבה ל-' . basename($d) . '/'];
            @unlink($probe);
        }
        return ['ok', 'data/ ו-data/devloop/ ניתנות לכתיבה'];
    });
    $add('protect', 'הנתונים חסומים מהדפדפן', function () use ($dir) {
        return is_file($dir . '/.htaccess') && str_contains((string)file_get_contents($dir . '/.htaccess'), 'denied')
            ? ['ok', 'data/.htaccess חוסם גישה ישירה'] : ['bad', 'חסר data/.htaccess — המסד עלול להיות נגיש מהדפדפן'];
    });
    $add('disk', 'מקום בדיסק', function () use ($dir) {
        $free = @disk_free_space(is_dir($dir) ? $dir : dirname($dir));
        if ($free === false) return ['warn', 'לא ניתן לקרוא את המקום הפנוי'];
        $mb = (int)round($free / 1048576);
        return [$mb < 200 ? 'bad' : ($mb < 1024 ? 'warn' : 'ok'), number_format($mb) . 'MB פנויים'];
    });
    $add('version', 'גרסה', function () {
        $v = apr_app_version();
        $php = PHP_VERSION;
        return [$v === '' || version_compare($php, '8.1', '<') ? 'bad' : 'ok', 'האפליקציה ' . ($v ?: '?') . " · PHP $php"];
    });
    $add('ext', 'הרחבות PHP', function () {
        $need = ['pdo_sqlite', 'mbstring', 'json', 'fileinfo'];
        $later = ['sodium', 'curl'];   // לחיבורים שייבנו בהמשך (חתימות, קריאה לשירותים)
        $missing = array_values(array_filter($need, fn($e) => !extension_loaded($e)));
        if ($missing) return ['bad', 'חסר: ' . implode(', ', $missing)];
        $soft = array_values(array_filter($later, fn($e) => !extension_loaded($e)));
        return $soft ? ['warn', 'חסר (יידרש בהמשך): ' . implode(', ', $soft)] : ['ok', implode(', ', [...$need, ...$later])];
    });
    $add('gate', 'השער של הפלטפורמה', function () {
        $app = json_decode((string)@file_get_contents(dirname(__DIR__) . '/app.json'), true) ?: [];
        if (($app['gate'] ?? false) !== true) return ['bad', 'app.json: "gate" אינו true — האפליקציה חייבת להיות מחוברת'];
        // .user.ini נוצר בפריסה (build-gate.php). בלעדיו קובצי ה-PHP לא עוברים בשער.
        return is_file(dirname(__DIR__) . '/.user.ini') ? ['ok', 'השער מותקן, והזהות מגיעה ממנו']
            : ['warn', 'אין .user.ini בתיקייה — תקין רק בסביבת פיתוח'];
    });
    $add('log', 'יומן הבקשות והשגיאות', function () {
        if (!function_exists('devloop_log_db')) return ['bad', 'DevLoop לא נטען'];
        $db = devloop_log_db();
        if (!$db) return ['bad', 'אי אפשר לפתוח את היומן (data/devloop/log.sqlite)'];
        $since = gmdate('Y-m-d\\TH:i:s\\Z', apr_time() - 86400);
        $st = $db->prepare("SELECT COUNT(*) AS n, SUM(level = 'error') AS e FROM log WHERE at >= ?");
        $st->execute([$since]);
        $r = $st->fetch();
        $e = (int)($r['e'] ?? 0);
        return [$e ? 'warn' : 'ok', (int)$r['n'] . ' שורות ב-24 שעות' . ($e ? " · $e שגיאות" : ' · בלי שגיאות')];
    });

    $conns = apr_connections();
    if (!$conns) $checks[] = ['id' => 'connections', 'label' => 'חיבורים חיצוניים', 'state' => 'ok', 'detail' => 'עוד לא הוגדרו חיבורים חיצוניים'];
    foreach ($conns as $id => $c) $add('conn:' . $id, 'חיבור: ' . $c['label'], $c['check']);

    $services = apr_rows('SELECT * FROM service_state ORDER BY name');
    if (!$services) $checks[] = ['id' => 'services', 'label' => 'שירותי רקע', 'state' => 'ok', 'detail' => 'עוד אין שירותי רקע'];
    foreach ($services as $s) {
        $add('svc:' . $s['name'], 'שירות: ' . ($s['label'] ?: $s['name']), function () use ($s) {
            $age = $s['last_ok_at'] ? apr_time() - strtotime($s['last_ok_at']) : null;
            $when = $s['last_ok_at'] ? 'הצליח לאחרונה ' . $s['last_ok_at'] : 'עוד לא הצליח אף פעם';
            if ($s['last_error'] !== '') return ['bad', 'נכשל: ' . $s['last_error'] . " · $when"];
            if ((int)$s['every_sec'] > 0 && ($age === null || $age > 3 * (int)$s['every_sec'])) return ['bad', "לא רץ בזמן · $when"];
            return ['ok', $when];
        });
    }

    $worst = array_reduce($checks, fn($w, $c) => $c['state'] === 'bad' ? 'bad' : ($c['state'] === 'warn' && $w !== 'bad' ? 'warn' : $w), 'ok');
    return ['state' => $worst, 'at' => apr_now(), 'checks' => $checks];
}
