<?php
/**
 * תשתית המפתח (פרוסה F1): מי המפתח, החיבור ל-DevLoop, והנתונים של מסך המפתח — יומן, יומן פעולות,
 * בדיקות תקינות, שירותי רקע ומצב הנתונים.
 *
 * lib/devloop.php הוא עותק מסונכרן של המודול המשותף — לא עורכים אותו; כאן רק מחברים אליו.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/devlog.php';
require_once __DIR__ . '/devloop.php';
require_once __DIR__ . '/forms.php';

/** גרסת התוסף לוורדפרס שהריפו מצפה לה באתר. התוסף אינו נפרס לשרת הזה, ולכן המספר כתוב כאן; בדיקה אוטומטית משווה אותו לקובץ התוסף. */
const KOL_PLUGIN_EXPECTED = '2.1.0';
const DEV_ATTACH_WINDOW = 900;      // חבר שאינו מפתח מצרף צילום רק למטלה שהוא עצמו פתח ב-15 הדקות האחרונות

/**
 * מי המפתח. **המקום היחיד שמכריע** — היום: הבעלים של האפליקציה (audit.md 4.4). כשמערכת המשתמשים תוחלף, משנים כאן.
 */
function is_developer(?array $user): bool
{
    return ($user['role'] ?? null) === 'owner';
}

function require_developer(?array $user): array
{
    if (!$user) throw new ApiError('נדרשת כניסה דרך הכלים הדיגיטליים', 401);
    if (!is_developer($user)) throw new ApiError('המסך הזה פתוח למפתח בלבד', 403);
    return $user;
}

/**
 * DevLoop מזהה משתמש במספר, והזהות כאן היא sub (מחרוזת). dev_users נותן לכל משתמש מספר קבוע, וזוכר אם היה מפתח
 * בבקשה האחרונה שלו — כך אסימון של Claude חי רק כל עוד מי שיצר אותו עדיין מפתח.
 * מחזיר את המשתמש בצורה ש-DevLoop מצפה לה: id, username, name (וגם sub).
 */
function dev_user(array $user): array
{
    static $cache = [];
    $sub = (string)$user['sub'];
    if (isset($cache[$sub])) return $cache[$sub];
    $pdo = db();
    $dev = is_developer($user) ? 1 : 0;
    $name = (string)($user['name'] ?? '');
    $st = $pdo->prepare('SELECT * FROM dev_users WHERE sub = ?');
    $st->execute([$sub]);
    $row = $st->fetch();
    if (!$row) {
        $pdo->prepare('INSERT OR IGNORE INTO dev_users (sub, name, developer, seen_at) VALUES (?, ?, ?, ?)')->execute([$sub, $name, $dev, now_iso()]);
        $st->execute([$sub]);
        $row = $st->fetch();
    } elseif ($row['name'] !== $name || (int)$row['developer'] !== $dev) {
        $pdo->prepare('UPDATE dev_users SET name = ?, developer = ?, seen_at = ? WHERE id = ?')->execute([$name, $dev, now_iso(), $row['id']]);
    }
    return $cache[$sub] = ['id' => (int)$row['id'], 'username' => $name, 'name' => $name, 'sub' => $sub];
}

function dev_setup(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    devloop_setup([
        'db' => fn() => db(),
        'items_file' => dirname(__DIR__) . '/dev/open-items.json',
        'data_dir' => KOL_DATA_DIR . '/files/_dev',              // צילומים — תחת data/files/, שמוחרג בפריסה
        'prefix' => 'KM-',
        'token_prefix' => 'kmd_',
        'error' => fn(string $msg, int $status, array $vars) => new ApiError(
            strtr($msg, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', $vars)) ?: []), $status ?: 400),
        'user_names' => fn() => array_column(db()->query('SELECT id, name FROM dev_users')->fetchAll(), 'name', 'id'),
        'is_developer' => function (int $id): bool {
            $st = db()->prepare('SELECT developer FROM dev_users WHERE id = ?');
            $st->execute([$id]);
            return (int)$st->fetchColumn() === 1;
        },
        'log' => [
            'rows' => fn(array $q) => devlog_rows($q),
            'max_id' => fn() => devlog_max_id(),
            'write' => fn(string $level, string $cat, string $event, array $extra = []) => devlog_write($level, $cat, $event, $extra),
        ],
        // DevLoop מדווח: ("user:<id>", פעולה, סוג, מזהה, נתונים) — נכנס ליומן הפעולות של האפליקציה
        'audit' => function (string $who, string $action, string $type = '', mixed $id = null, array $data = []): void {
            $st = db()->prepare('SELECT sub, name FROM dev_users WHERE id = ?');
            $st->execute([(int)substr($who, 5)]);
            $u = $st->fetch() ?: ['sub' => $who, 'name' => ''];
            audit_as($u, str_replace('devtasks.', 'dev_', $action), trim(($data['label'] ?? '') . ' #' . $id));
        },
        'as_claude' => fn(int $tokenId) => devlog_user(['sub' => 'claude', 'name' => '🤖 Claude']),
    ]);
}

/** כניסה ליומן הפעולות — פעם בחצי שעה לכל משתמש, כדי שכל טעינה של הדף לא תציף. */
function audit_login(array $user): void
{
    try {
        $st = db()->prepare("SELECT 1 FROM audit WHERE sub = ? AND action = 'login' AND at > ? LIMIT 1");
        $st->execute([$user['sub'], gmdate('Y-m-d\TH:i:s\Z', time() - 1800)]);
        if (!$st->fetchColumn()) audit_as($user, 'login', mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120));
    } catch (Throwable $e) {
        devlog_write('warn', 'data', 'audit.login', ['message' => $e->getMessage()]);
    }
}

/** חבר שאינו מפתח מצרף צילום רק לדיווח שלו עצמו, מיד אחרי שנפתח. */
function dev_attach_own(array $du, string $code): void
{
    devloop_migrate();
    $st = db()->prepare('SELECT created_by, created_at FROM dev_tasks WHERE code = ?');
    $st->execute([$code]);
    $t = $st->fetch();
    if (!$t || (int)$t['created_by'] !== $du['id'] || strtotime((string)$t['created_at']) < time() - DEV_ATTACH_WINDOW) {
        throw new ApiError('אפשר לצרף רק לדיווח שפתחת עכשיו', 403);
    }
}

/** הדיווחים של המשתמש עצמו (🐞, ו"לא הצלחתי לשנות" מהעורך) — קוד, כותרת, תווית, מצב. קריאה בלבד מטבלת המטלות של DevLoop. */
function dev_my_reports(array $du): array
{
    devloop_migrate();
    $st = db()->prepare('SELECT code, title, area, status, created_at FROM dev_tasks WHERE created_by = ? ORDER BY id DESC LIMIT 50');
    $st->execute([$du['id']]);
    return ['items' => $st->fetchAll()];
}

/**
 * הפעולות של המפתח ושל DevLoop. מחזיר null כשהפעולה אינה שלו. שמות הפעולות — כמו בכל אפליקציה עם DevLoop
 * (dev-…, devapi-…), כדי ש-Claude והסקיל devloop יעבדו בלי התאמה.
 */
function dev_route(string $action, string $method, ?array $user, array $in): ?array
{
    if (!str_starts_with($action, 'dev')) return null;
    dev_setup();
    $member = function () use ($user): array {                    // כל חבר עם תפקיד — רואה 🐞 ומדווח
        return dev_user(require_role($user, 'editor'));
    };
    $dev = function () use ($user): array {
        return dev_user(require_developer($user));
    };
    return match ("$method $action") {
        // בלי עוגייה, באסימון (X-Dev-Token) — Claude
        'GET devapi-feed' => devloop_api_feed(devloop_api_auth(), $_GET),
        'POST devapi-comment' => devloop_api_comment(devloop_api_auth(), $in),
        'GET devapi-file' => (devloop_api_auth() ? devloop_file_out((int)($_GET['id'] ?? 0)) : []),
        'POST devapi-attach' => (devloop_api_auth() ? devloop_attach('claude', $_GET) : []),
        // כל משתמש מזוהה: שגיאה מהדפדפן
        'POST dev-client-error' => devlog_client($user ?? throw new ApiError('נדרשת כניסה', 401), $in),
        // כל חבר: דיווח 🐞 וצילום לדיווח שלו. הרשימה — רק למפתח (לאחרים ריקה, כדי שהכפתור לא יציג שגיאה)
        'POST dev-report' => devloop_report($member(), $in),
        'POST dev-attach' => (function () use ($member, $user) {
            $du = $member();
            if (!is_developer($user)) dev_attach_own($du, (string)($_GET['code'] ?? ''));
            return devloop_attach('user:' . $du['id'], $_GET);
        })(),
        // כל חבר: הדיווחים שהוא עצמו פתח, ומצבם (מי שאינו המפתח אינו רואה את שאר המטלות)
        'GET dev-my-reports' => dev_my_reports($member()),
        'GET dev-items' => ($member() && is_developer($user) ? devloop_task_list() : ['updated' => null, 'note' => null, 'items' => []]),
        // המפתח בלבד
        'POST dev-task-create' => devloop_task_create($dev(), $in),
        'POST dev-task-set' => devloop_task_set($dev(), $in),
        'POST dev-comment' => devloop_comment_add((string)($in['code'] ?? ''), 'user', $dev()['id'], (string)($in['body'] ?? '')),
        'POST dev-comment-delete' => devloop_comment_delete($dev(), $in),
        'POST dev-verify' => devloop_verify($dev(), $in),
        'GET dev-file' => ($dev() ? devloop_file_out((int)($_GET['id'] ?? 0)) : []),
        'GET dev-tokens' => ($dev() ? devloop_token_list() : []),
        'POST dev-token-create' => devloop_token_create($dev(), $in),
        'POST dev-token-revoke' => devloop_token_revoke($dev(), $in),
        'GET dev-item-log' => ($dev() ? devloop_item_log((string)($_GET['code'] ?? '')) : []),
        'POST dev-log-links' => ($dev() ? devloop_log_links((array)($in['ids'] ?? []), (array)($in['reqs'] ?? [])) : []),
        'POST dev-task-from-log' => devloop_task_from_log($dev(), $in),
        // מסך המפתח
        'GET dev-log' => ($dev() ? dev_log_view($_GET) : []),
        'GET dev-audit' => ($dev() ? dev_audit_view($_GET) : []),
        'GET dev-health' => ($dev() ? dev_health() : []),
        'POST dev-attack-save' => pv_attack_save($dev(), $in),       // ערכת ההתקפה מול הסטודיו (F4, lib/preview.php)
        'GET dev-state' => ($dev() ? ['services' => dev_services(), 'stats' => dev_stats()] : []),
        'GET dev-forms' => ($dev() ? forms_dev_state() : []),             // M5: שורת 📬 טפסים (lib/forms.php)
        // ספייק מנוע העורך (V0): קבצי הבדיקה מוגשים כנתונים — dev/ חסומה מהדפדפן
        'GET dev-spike-fixture' => ($dev() ? dev_spike_fixture((string)($_GET['name'] ?? '')) : []),
        default => throw new ApiError('פעולה לא מוכרת', in_array($action, DEV_ACTIONS, true) ? 405 : 404),
    };
}

const DEV_ACTIONS = ['devapi-feed', 'devapi-comment', 'devapi-file', 'devapi-attach', 'dev-client-error', 'dev-report', 'dev-attach', 'dev-my-reports', 'dev-items',
    'dev-task-create', 'dev-task-set', 'dev-comment', 'dev-comment-delete', 'dev-verify', 'dev-file', 'dev-tokens', 'dev-token-create',
    'dev-token-revoke', 'dev-item-log', 'dev-log-links', 'dev-task-from-log', 'dev-log', 'dev-audit', 'dev-health', 'dev-state', 'dev-spike-fixture', 'dev-attack-save', 'dev-forms'];
/** פעולות שנכנסים אליהן באסימון, בלי משתמש מהשער. חייבות להופיע גם ב-"public" של app.json. */
const DEV_TOKEN_ACTIONS = ['devapi-feed', 'devapi-comment', 'devapi-file', 'devapi-attach'];
/** פעולות שהגוף שלהן הוא קובץ, לא JSON. */
const DEV_RAW_BODY = ['dev-attach', 'devapi-attach'];

// ── ספייק מנוע העורך (V0) ─────────────────────────────────

const DEV_SPIKE_FIXTURES = ['lexicon.html', 'lexicon.css', 'handwritten.html', 'handwritten.css', 'broken.html', 'broken.css'];

/** קובץ בדיקה של הספייק, ב-base64 — כדי שהבייטים (BOM, CRLF) יגיעו לדפדפן כמו שהם. רשימה סגורה; קריאה בלבד. */
function dev_spike_fixture(string $name): array
{
    if (!in_array($name, DEV_SPIKE_FIXTURES, true)) throw new ApiError('אין קובץ בדיקה כזה', 404);
    $raw = @file_get_contents(__DIR__ . '/../dev/spike/fixtures/' . $name);
    if ($raw === false) throw new ApiError('קובץ הבדיקה חסר בשרת', 404);
    return ['name' => $name, 'bytes' => strlen($raw), 'b64' => base64_encode($raw)];
}

// ── יומן הבקשות והשגיאות ─────────────────────────────────

function dev_log_view(array $q): array
{
    // act — הפעולה שמסננים לפיה ("action" בכתובת הוא שם הפעולה של הבקשה הזו עצמה)
    $f = array_intersect_key($q, array_flip(['before', 'level', 'q', 'from', 'to', 'category', 'user', 'req']));
    $rows = devlog_rows($f + ['action' => $q['act'] ?? '', 'limit' => 200]);
    $pdo = db();
    return [
        'rows' => $rows,
        'more' => count($rows) === 200,
        'total' => devlog_max_id() ? (int)$pdo->query('SELECT COUNT(*) FROM dev_log')->fetchColumn() : 0,
        'keep' => ['rows' => DEVLOG_MAX_ROWS, 'days' => DEVLOG_DAYS],
        // לתפריטי הסינון: מה שבאמת קיים ביומן
        'facets' => [
            'category' => $pdo->query('SELECT DISTINCT category FROM dev_log ORDER BY category')->fetchAll(PDO::FETCH_COLUMN),
            'action' => $pdo->query("SELECT DISTINCT action FROM dev_log WHERE action <> '' ORDER BY action")->fetchAll(PDO::FETCH_COLUMN),
            'user' => $pdo->query("SELECT sub, MAX(username) AS name FROM dev_log WHERE sub <> '' GROUP BY sub ORDER BY name")->fetchAll(),
        ],
    ];
}

// ── יומן הפעולות ─────────────────────────────────────────

/**
 * מי עשה מה ומתי. טבלת audit, ואיתה שמירות הטיוטה (מטבלת revisions — שם הן כבר רשומות; לא משכפלים אותן ל-audit
 * כדי ששמירה אוטומטית לא תציף אותו). סינון: act (הפעולה), user (sub), q, from, to.
 */
function dev_audit_view(array $q): array
{
    $w = ['1 = 1'];
    $a = [];
    foreach (['act' => 'action = ?', 'user' => 'sub = ?', 'from' => 'at >= ?', 'to' => 'at <= ?'] as $k => $sql) {
        if (is_scalar($q[$k] ?? null) && (string)$q[$k] !== '') { $w[] = $sql; $a[] = (string)$q[$k]; }
    }
    if (is_scalar($q['q'] ?? null) && (string)$q['q'] !== '') {
        $w[] = '(detail LIKE ? OR name LIKE ? OR action LIKE ?)';
        $l = '%' . strtr((string)$q['q'], ['%' => '', '_' => '']) . '%';
        array_push($a, $l, $l, $l);
    }
    $src = "SELECT at, sub, name, action, detail FROM audit
            UNION ALL SELECT at, sub, name, 'draft_save' AS action, ('גרסת טיוטה ' || rev || CASE WHEN note <> '' THEN ' · ' || note ELSE '' END) AS detail FROM revisions";
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM ($src) WHERE " . implode(' AND ', $w) . ' ORDER BY at DESC LIMIT 300');
    $st->execute($a);
    return [
        'rows' => $st->fetchAll(),
        'facets' => [
            'action' => $pdo->query("SELECT DISTINCT action FROM ($src) ORDER BY action")->fetchAll(PDO::FETCH_COLUMN),
            'user' => $pdo->query("SELECT sub, MAX(name) AS name FROM ($src) GROUP BY sub ORDER BY name")->fetchAll(),
        ],
    ];
}

// ── בדיקות תקינות ────────────────────────────────────────

function dev_app_version(): string
{
    $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/app.json'), true);
    return is_array($j) ? (string)($j['version'] ?? '') : '';
}

/** מה שהפריסה כתבה (dev/build.json: commit, at) — אם כתבה. */
function dev_build(): ?array
{
    $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/dev/build.json'), true);
    return is_array($j) && !empty($j['commit']) ? ['commit' => substr(preg_replace('/[^0-9a-f]/', '', (string)$j['commit']), 0, 40), 'at' => (string)($j['at'] ?? '')] : null;
}

function dev_bytes(float|int $n): string
{
    foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $u => $d) if ($n >= $d) return round($n / $d, $n >= 10 * $d ? 0 : 1) . $u;
    return (int)$n . 'B';
}

/**
 * הבדיקות. כל אחת מחזירה [ok, text, fix] — מה נמצא במילים, ומה לעשות כשאדום. בדיקה שזורקת מוצגת כאדומה ואינה
 * מפילה את האחרות. 'probe' — בדיקה שהדפדפן משלים בבקשה אמיתית (חסימת data/).
 */
function dev_health_checks(): array
{
    return [
        'db' => ['המסד נפתח ונכתב, וגרסת הסכמה', function (): array {
            $pdo = db();
            $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
            setting_set('health_probe', (string)time());
            if ((int)setting('health_probe') < time() - 5) return [false, 'הכתיבה למסד לא נשמרה', 'לבדוק הרשאות כתיבה ל-data/kol.sqlite ומקום פנוי בדיסק'];
            $ok = $pdo->query('PRAGMA quick_check')->fetchColumn() === 'ok';
            if (!$ok) return [false, 'בדיקת השלמות של המסד נכשלה', 'להוריד גיבוי מלא (⚙️) מיד, ולדווח'];
            if ($v !== KOL_SCHEMA_VERSION) return [false, "גרסת הסכמה $v, והקוד מצפה ל-" . KOL_SCHEMA_VERSION, 'מיגרציה לא רצה עד הסוף — לבדוק ביומן שגיאת SQL'];
            return [true, "נפתח, נכתב ונקרא · גרסת סכמה $v", ''];
        }],
        'writable' => ['הרשאות כתיבה ל-data/ ול-data/files/', function (): array {
            $bad = [];
            foreach ([KOL_DATA_DIR, KOL_DATA_DIR . '/files', KOL_DATA_DIR . '/files/_dev'] as $d) {
                if (!is_dir($d)) @mkdir($d, 0775, true);
                $f = $d . '/.probe_' . bin2hex(random_bytes(4));
                if (@file_put_contents($f, 'x') !== 1) $bad[] = str_replace(dirname(KOL_DATA_DIR) . '/', '', $d);
                @unlink($f);
            }
            return $bad ? [false, 'אי אפשר לכתוב אל: ' . implode(', ', $bad), 'בשרת: הרשאות 775 לתיקייה, בבעלות המשתמש שמריץ את PHP'] : [true, 'נכתב ונמחק קובץ בדיקה בשלוש התיקיות', ''];
        }],
        'disk' => ['מקום פנוי מול תקרת הדיסק', function (): array {
            require_once __DIR__ . '/files.php';
            $used = disk_used();
            $cap = disk_cap();
            $free = @disk_free_space(KOL_DATA_DIR);
            $txt = 'קבצים: ' . dev_bytes($used) . ' מתוך תקרה של ' . dev_bytes($cap) . ($free !== false ? ' · פנוי בשרת: ' . dev_bytes($free) : '');
            if ($used > 0.9 * $cap) return [false, $txt, 'מעל 90% מהתקרה — לרוקן את הסל (↩️ שחזור) או להגדיל את התקרה (🗂️ קבצים)'];
            if ($free !== false && $free < 300 * (1 << 20)) return [false, $txt, 'פחות מ-300MB פנויים בשרת — לפנות מקום בחשבון האחסון'];
            return [true, $txt, ''];
        }],
        'php' => ['הרחבות PHP נדרשות', function (): array {
            $miss = array_values(array_filter(['sodium', 'zip', 'pdo_sqlite', 'curl', 'mbstring', 'dom', 'fileinfo'], fn($e) => !extension_loaded($e)));
            $img = extension_loaded('gd') ? 'gd' : (extension_loaded('imagick') ? 'imagick' : '');
            if ($img === '') $miss[] = 'gd / imagick';
            return $miss ? [false, 'חסרות: ' . implode(', ', $miss) . ' · PHP ' . PHP_VERSION, 'להפעיל ב-cPanel ← Select PHP Version ← Extensions']
                : [true, "sodium, zip, pdo_sqlite, curl, mbstring, dom, fileinfo, $img · PHP " . PHP_VERSION, ''];
        }],
        'version' => ['גרסת האפליקציה והקומיט', function (): array {
            $v = dev_app_version();
            $b = dev_build();
            if ($v === '') return [false, 'app.json לא נקרא', 'הקובץ חסר או פגום בשרת — פריסה מחדש'];
            if (!$b) return [false, "גרסה $v · הקומיט לא ידוע (הקבצים עודכנו " . gmdate('Y-m-d H:i', (int)@filemtime(dirname(__DIR__) . '/api.php')) . ' UTC)',
                'הפריסה אינה כותבת את dev/build.json — שלב ב-deploy.yml (בקשה לבקרה)'];
            return [true, "גרסה $v · קומיט " . substr($b['commit'], 0, 7) . ($b['at'] !== '' ? ' · נפרס ' . $b['at'] : ''), ''];
        }],
        'htaccess' => ['data/ חסומה לגישה מהדפדפן', function (): array {
            $f = KOL_DATA_DIR . '/.htaccess';
            if (!is_file($f) || !str_contains((string)file_get_contents($f), 'denied')) return [false, 'data/.htaccess חסר או שאינו חוסם', 'פריסה מחדש — הקובץ בריפו'];
            return [true, 'הקובץ קיים וחוסם', ''];
        }, 'data/.htaccess'],
        'site' => ['החיבור לאתר, וגרסת התוסף שמותקנת בו', function (): array {
            require_once __DIR__ . '/publish.php';
            try {
                $r = site_call('ping', []);
            } catch (ApiError $e) {
                return [false, 'ה-ping החתום נכשל: ' . $e->getMessage(), 'ב-🚀 פרסום ← "בדיקת חיבור" מופיע ההסבר המלא; לוודא שהתוסף מותקן ופעיל באתר ושהמפתח הציבורי הודבק בו (docs/install.md)'];
            }
            $p = (string)($r['plugin'] ?? '');
            $state = (!empty($r['enabled']) ? 'האתר מציג את הגרסה מהסטודיו' : 'האתר מציג את העמוד המקורי') . ' · גרסה חיה ' . (int)($r['live'] ?? 0);
            if ($p === '') return [false, "ה-ping החתום עבר, אבל התוסף לא דיווח גרסה · $state", 'התוסף באתר ישן מאוד — להתקין את ' . KOL_PLUGIN_EXPECTED . ' (מטלה לבעלים)'];
            if (version_compare($p, KOL_PLUGIN_EXPECTED, '<')) return [false, "ה-ping החתום עבר · באתר מותקן תוסף $p, ובריפו " . KOL_PLUGIN_EXPECTED . " · $state",
                'להתקין באתר את התוסף המעודכן מ-wordpress-plugin/ (מטלה לבעלים; docs/install.md)'];
            return [true, "ה-ping החתום עבר · תוסף $p · $state", ''];
        }],
        'preview' => ['התצוגה החיה: מפתח החתימה וכותרות הבידוד', function (): array {
            require_once __DIR__ . '/preview.php';
            if (!pv_token_ok(pv_token_make())) return [false, 'אסימון שנחתם עכשיו אינו מתקבל', 'לבדוק ש-data/secrets.json נכתב ונקרא (הרשאות), ושהשעון בשרת תקין'];
            if (pv_token_ok(pv_token_make(time() - 5))) return [false, 'אסימון שפג תוקפו מתקבל', 'תקלה בקוד — lib/preview.php'];
            return [true, 'האסימון נחתם ונבדק; אסימון שפג נדחה', ''];
        }, 'preview'],
        'editor' => ['עורך התמלילים זמין', function (): array {
            require_once __DIR__ . '/longtext.php';
            $ch = curl_init(WordProcessorClient::EDITOR . 'api.php?action=embed_key');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['Cookie: humans_21909=1']]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($res === false) return [false, 'העורך לא ענה: ' . $err, 'לבדוק שהעורך (word-processor) באוויר; טקסט ארוך לא ייפתח עד אז'];
            if ($status >= 500) return [false, "העורך ענה $status", 'תקלה בצד העורך — לדווח בצ\'אט שלו דרך הבעלים'];
            return [true, "העורך ענה ($status)", ''];
        }],
    ];
}

function dev_health(): array
{
    $out = [];
    $fails = [];
    foreach (dev_health_checks() as $id => $c) {
        $t0 = microtime(true);
        try {
            [$ok, $text, $fix] = ($c[1])();
        } catch (Throwable $e) {
            [$ok, $text, $fix] = [false, (string)devlog_scrub($e->getMessage()), 'הבדיקה עצמה נפלה — הפרטים ביומן, לפי מזהה הבקשה ' . devlog_req()];
            devlog_write('warn', 'health', 'health.' . $id, ['message' => $e->getMessage()]);
        }
        if (!$ok) $fails[] = $id;
        $out[] = ['id' => $id, 'name' => $c[0], 'ok' => (bool)$ok, 'text' => $text, 'fix' => $fix, 'ms' => (int)round((microtime(true) - $t0) * 1000)]
            + (isset($c[2]) ? ['probe' => $c[2]] : []);
    }
    dev_run_note('health', !$fails, $fails ? 'נכשלו: ' . implode(', ', $fails) : 'הכול ירוק (' . count($out) . ')');
    require_once __DIR__ . '/preview.php';
    return ['attack' => pv_attack_last(), 'checks' => $out, 'at' => now_iso(), 'version' => dev_app_version(), 'build' => dev_build(), 'req' => devlog_req()];
}

// ── שירותי רקע ───────────────────────────────────────────

function dev_last_run(string $name): ?array
{
    $st = db()->prepare('SELECT at, ok, note FROM dev_runs WHERE name = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$name]);
    $r = $st->fetch();
    return $r ? ['at' => $r['at'], 'ok' => (bool)$r['ok'], 'note' => $r['note']] : null;
}

/** לכל שירות: מתי רץ לאחרונה, התוצאה, ומתי הבא. אין cron — השירותים רצים מתוך בקשות. */
function dev_services(): array
{
    $pdo = db();
    $today = gmdate('Y-m-d');
    $snap = $pdo->query("SELECT id, at FROM snapshots WHERE kind = 'daily' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    $marked = setting('snap_daily');
    // snapshot_daily מסמן את היום לפני שהוא יוצר את הנקודה; סימון של היום בלי נקודה מהיום = הנקודה נכשלה
    $snapOk = $snap && ($marked !== $today || str_starts_with((string)$snap['at'], $today));
    $trashN = (int)$pdo->query('SELECT (SELECT COUNT(*) FROM files WHERE deleted_at IS NOT NULL) + (SELECT COUNT(*) FROM words_trash) + (SELECT COUNT(*) FROM topic_trash)')->fetchColumn();
    $purge = $pdo->query("SELECT at, name, detail FROM audit WHERE action = 'trash_purge' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    $clean = dev_last_run('log_clean');
    $cleanAt = (int)(setting('devlog_clean_at') ?? 0);
    $health = dev_last_run('health');
    return [
        ['id' => 'snapshot', 'name' => '🛟 נקודת השחזור היומית', 'how' => 'בבקשה הראשונה של כל יום (UTC), של חבר עם תפקיד',
            'last' => $snap['at'] ?? null, 'ok' => $snap ? $snapOk : null,
            'result' => !$snap ? 'עוד לא נוצרה נקודה יומית' : ($snapOk ? 'נקודה #' . $snap['id'] : 'היום סומן, אבל נקודה של היום לא נוצרה — לבדוק ביומן'),
            'next' => $marked === $today ? gmdate('Y-m-d\T00:00:00\Z', time() + 86400) : null, 'next_text' => $marked === $today ? 'בבקשה הראשונה אחרי' : 'בבקשה הבאה'],
        ['id' => 'trash', 'name' => '🗑️ ניקוי הסל', 'how' => 'ידני בלבד: הסל אינו מתרוקן לבד (הכרעת הבעלים — שום דבר לא נמחק לצמיתות בלי אישור)',
            'last' => $purge['at'] ?? null, 'ok' => null,
            'result' => ($purge ? 'מחיקה סופית אחרונה: ' . $purge['name'] . ' · ' . $purge['detail'] : 'עוד לא נמחק דבר לצמיתות') . " · בסל עכשיו: $trashN",
            'next' => null, 'next_text' => 'אין ריצה מתוזמנת'],
        ['id' => 'log_clean', 'name' => '📜 ניקוי היומן', 'how' => 'פעם בשעה לכל היותר, מתוך בקשה: מה שישן מ-' . DEVLOG_DAYS . ' יום, ועד ' . number_format(DEVLOG_MAX_ROWS) . ' שורות',
            'last' => $clean['at'] ?? null, 'ok' => $clean['ok'] ?? null, 'result' => $clean['note'] ?? 'עוד לא רץ',
            'next' => $cleanAt ? gmdate('Y-m-d\TH:i:s\Z', $cleanAt + DEVLOG_CLEAN_EVERY) : null, 'next_text' => $cleanAt ? 'בבקשה הראשונה אחרי' : 'בבקשה הבאה שנרשמת'],
        ['id' => 'health', 'name' => '🩺 בדיקות התקינות', 'how' => 'כשפותחים את המסך או לוחצים "הרצת כל הבדיקות"',
            'last' => $health['at'] ?? null, 'ok' => $health['ok'] ?? null, 'result' => $health['note'] ?? 'עוד לא רצו', 'next' => null, 'next_text' => 'בלחיצה'],
        forms_dev_service(),                                                // M5: המייל על פניות חדשות
    ];
}

// ── מצב הנתונים ──────────────────────────────────────────

/** מספרים בלבד — בלי תוכן. עונה מהמסך על audit.md §7 (שורות 2, 3, 5, 8). */
function dev_stats(): array
{
    $pdo = db();
    $n = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
    $size = 0;
    foreach (['', '-wal', '-shm'] as $suf) $size += (int)@filesize(KOL_DATA_DIR . '/kol.sqlite' . $suf);
    $imp = json_decode((string)setting('imported_from', 'null'), true);
    return [
        ['id' => 'words', 'label' => 'מילים', 'value' => $n('SELECT COUNT(*) FROM words')],
        ['id' => 'words_audio', 'label' => 'מילים עם הקלטה', 'value' => $n("SELECT COUNT(*) FROM words WHERE audio <> ''")],
        ['id' => 'audio_site', 'label' => 'הקלטות "באתר" (הפניה)', 'value' => $n("SELECT COUNT(*) FROM words WHERE audio LIKE 'https://%'")],
        ['id' => 'audio_tool', 'label' => 'הקלטות "בכלי" (קובץ)', 'value' => $n("SELECT COUNT(*) FROM words WHERE audio <> '' AND audio NOT LIKE 'https://%'")],
        ['id' => 'topics', 'label' => 'נושאים', 'value' => $n('SELECT COUNT(*) FROM topics')],
        ['id' => 'files', 'label' => 'קבצים', 'value' => $n('SELECT COUNT(*) FROM files WHERE deleted_at IS NULL')],
        ['id' => 'files_trash', 'label' => 'קבצים בסל', 'value' => $n('SELECT COUNT(*) FROM files WHERE deleted_at IS NOT NULL')],
        ['id' => 'files_size', 'label' => 'גודל הקבצים', 'value' => dev_bytes($n('SELECT COALESCE(SUM(size), 0) FROM files'))],
        ['id' => 'code_files', 'label' => 'קבצי קוד שנוצרו', 'value' => $n('SELECT COUNT(*) FROM code_files')],
        ['id' => 'versions', 'label' => 'גרסאות שפורסמו', 'value' => $n('SELECT COUNT(*) FROM versions')],
        ['id' => 'revisions', 'label' => 'שמירות טיוטה בהיסטוריה', 'value' => $n('SELECT COUNT(*) FROM revisions')],
        ['id' => 'snapshots', 'label' => 'נקודות שחזור', 'value' => $n('SELECT COUNT(*) FROM snapshots')],
        ['id' => 'members', 'label' => 'חברים', 'value' => $n('SELECT COUNT(*) FROM members')],
        ['id' => 'requests', 'label' => 'ביקשו גישה', 'value' => $n('SELECT COUNT(*) FROM access_requests')],
        ['id' => 'log', 'label' => 'שורות ביומן', 'value' => $n('SELECT COUNT(*) FROM dev_log')],
        ['id' => 'db_size', 'label' => 'גודל המסד', 'value' => dev_bytes($size)],
        dev_x1_row(),
        ['id' => 'imported', 'label' => 'ייבוא העמוד מהאתר', 'value' => is_array($imp) ? (string)($imp['at'] ?? 'בוצע') : 'לא בוצע'],
    ];
}

/** X1: ✨ / 🤖 / 📝 החודש — שימוש בבינה (קיים), עריכות שהוחלו / נדחו, בקשות מהצ'אט לפי כניסה, סנכרונים של טקסט ארוך והתנגשויות. */
function dev_x1_row(): array
{
    $st = db()->prepare("SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens + output_tokens), 0) AS tokens FROM ai_usage WHERE at >= ? AND status <> 'error'");
    $st->execute([gmdate('Y-m-01\\T00:00:00\\Z')]);
    $u = $st->fetch() ?: ['calls' => 0, 'tokens' => 0];
    $s = function_exists('x1_stats') ? x1_stats() : [];
    $agents = [];
    foreach ($s as $k => $v) if (str_starts_with((string)$k, 'agent:')) $agents[] = substr((string)$k, 6) . ': ' . $v;
    $value = sprintf('✨ %d בקשות · %s טוקנים · הוחלו %d · נדחו %d  |  🤖 %s  |  📝 סנכרונים %d · התנגשויות %d',
        (int)$u['calls'], number_format((int)$u['tokens']), (int)($s['applied'] ?? 0), (int)($s['rejected'] ?? 0),
        $agents ? implode(', ', $agents) : 'אין בקשות', (int)($s['lt_sync'] ?? 0), (int)($s['lt_conflict'] ?? 0));
    return ['id' => 'x1', 'label' => 'בינה, צ\'אט וטקסט ארוך — החודש', 'value' => $value];
}
