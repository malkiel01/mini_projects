<?php
/**
 * שרת הסנכרון של "השלמות לשיפוץ" — אופציונלי.
 *
 * האפליקציה עובדת במלואה בלי הקובץ הזה; מי שמעלה אותו לשרת מקבל רשימה
 * משותפת בין כל מי שמחזיק באותו אסימון. האחסון מבוסס קבצים (JSON + מדיה
 * בתיקיית data/) ולא דורש מסד נתונים.
 *
 * הפעלה: להעלות את התיקייה לשרת PHP ולוודא ש-data/ ניתנת לכתיבה.
 * האסימון נוצר אוטומטית בבקשה הראשונה ונשמר ב-data/config.json.
 */

declare(strict_types=1);

const API_VERSION   = 1;
const DATA_DIR      = __DIR__ . '/data';
const STORE_FILE    = DATA_DIR . '/store.json';
const CONFIG_FILE   = DATA_DIR . '/config.json';
const MEDIA_DIR     = DATA_DIR . '/media';
const MAX_UPLOAD    = 268435456;   // 256MB — סרטון מלא מהטלפון נכנס בנוחות
const MAX_PUSH_ROWS = 2000;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// האפליקציה עשויה להתארח בדומיין אחר מהשרת; האסימון הוא מנגנון האימות.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ── עזרים ──────────────────────────────────────────────────────── */

function fail(string $message, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $payload = []) {
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** חותמת זמן במילישניות — אותה יחידה שבה משתמש הדפדפן. */
function nowMs(): int {
    return (int) round(microtime(true) * 1000);
}

function ensureDirs(): void {
    foreach ([DATA_DIR, MEDIA_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            fail('תיקיית data/ אינה ניתנת ליצירה — בדקו הרשאות בשרת', 500);
        }
    }
}

/**
 * טוען את ההגדרות, ובבקשה הראשונה יוצר אסימון אקראי.
 * כך אין צורך בשלב התקנה ידני, והאסימון לעולם אינו ברירת מחדל ידועה.
 */
function loadConfig(): array {
    if (is_file(CONFIG_FILE)) {
        $config = json_decode((string) file_get_contents(CONFIG_FILE), true);
        if (is_array($config) && !empty($config['token'])) {
            return $config;
        }
    }

    $config = ['token' => bin2hex(random_bytes(16)), 'created_at' => date('c')];
    file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(CONFIG_FILE, 0600);
    return $config;
}

function requireToken(?string $given): void {
    $config = loadConfig();
    if (!is_string($given) || $given === '' || !hash_equals($config['token'], $given)) {
        fail('אסימון שגוי. האסימון של השרת נמצא בקובץ data/config.json', 401);
    }
}

function loadStore(): array {
    if (!is_file(STORE_FILE)) {
        return ['tasks' => [], 'media' => []];
    }
    $store = json_decode((string) file_get_contents(STORE_FILE), true);
    if (!is_array($store)) {
        return ['tasks' => [], 'media' => []];
    }
    return ['tasks' => $store['tasks'] ?? [], 'media' => $store['media'] ?? []];
}

/** כתיבה אטומית — קריסה באמצע לא משאירה קובץ store חצי-כתוב. */
function saveStore(array $store): void {
    $tmp = STORE_FILE . '.tmp';
    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
        fail('שמירת הנתונים בשרת נכשלה', 500);
    }
    rename($tmp, STORE_FILE);
}

/** מזהים מגיעים מהלקוח ומשמשים כשמות קבצים — ולכן נבדקים בקפדנות. */
function validId(mixed $id): bool {
    return is_string($id) && preg_match('/^[a-z]_[A-Za-z0-9]{1,32}$/', $id) === 1;
}

function text(array $row, string $key, int $max): string {
    $value = $row[$key] ?? '';
    if (!is_string($value)) return '';
    return mb_substr(trim($value), 0, $max);
}

function pick(array $row, string $key, array $allowed, string $default): string {
    $value = $row[$key] ?? '';
    return in_array($value, $allowed, true) ? $value : $default;
}

function ts(array $row, string $key): int {
    $value = $row[$key] ?? 0;
    return is_numeric($value) ? (int) $value : 0;
}

/**
 * מקבל רק שדות מוכרים. הלקוח יכול לשלוח כל JSON, ובלי סינון הוא היה
 * יכול לנפח את הקובץ בשדות שרירותיים.
 */
function cleanTask(array $row): ?array {
    if (!validId($row['id'] ?? null)) return null;
    $due = text($row, 'due', 10);

    return [
        'id'        => $row['id'],
        'title'     => text($row, 'title', 200),
        'details'   => text($row, 'details', 5000),
        'assignee'  => text($row, 'assignee', 80),
        'location'  => text($row, 'location', 80),
        'status'    => pick($row, 'status', ['open', 'progress', 'done'], 'open'),
        'priority'  => pick($row, 'priority', ['normal', 'high'], 'normal'),
        'due'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : '',
        'createdAt' => ts($row, 'createdAt') ?: nowMs(),
        'updatedAt' => ts($row, 'updatedAt') ?: nowMs(),
        'doneAt'    => ts($row, 'doneAt') ?: null,
        'deleted'   => !empty($row['deleted']),
    ];
}

function cleanMedia(array $row): ?array {
    if (!validId($row['id'] ?? null) || !validId($row['taskId'] ?? null)) return null;

    return [
        'id'        => $row['id'],
        'taskId'    => $row['taskId'],
        'kind'      => pick($row, 'kind', ['image', 'video'], 'image'),
        'mime'      => text($row, 'mime', 100),
        'name'      => text($row, 'name', 200),
        'size'      => max(0, ts($row, 'size')),
        'width'     => max(0, ts($row, 'width')),
        'height'    => max(0, ts($row, 'height')),
        'createdAt' => ts($row, 'createdAt') ?: nowMs(),
        'updatedAt' => ts($row, 'updatedAt') ?: nowMs(),
        'deleted'   => !empty($row['deleted']),
    ];
}

function mediaPath(string $id, string $which): string {
    return MEDIA_DIR . '/' . $id . ($which === 'thumb' ? '.thumb.jpg' : '.bin');
}

/* ── ניתוב ──────────────────────────────────────────────────────── */

ensureDirs();

$action = $_GET['action'] ?? '';
$input  = [];

if ($action === '') {
    $raw = file_get_contents('php://input');
    $input = json_decode((string) $raw, true);
    if (!is_array($input)) {
        fail('בקשה לא תקינה');
    }
    $action = is_string($input['action'] ?? null) ? $input['action'] : '';
}

switch ($action) {

    /* בדיקת חיבור והצגת גודל הרשימה בשרת */
    case 'ping': {
        requireToken($input['token'] ?? null);
        $store = loadStore();
        ok([
            'version' => API_VERSION,
            'tasks'   => count(array_filter($store['tasks'], fn($t) => empty($t['deleted']))),
            'media'   => count(array_filter($store['media'], fn($m) => empty($m['deleted']))),
            'now'     => nowMs(),
        ]);
    }

    /* משיכת כל מה שהשתנה מאז חותמת הזמן שהלקוח מחזיק */
    case 'pull': {
        requireToken($input['token'] ?? null);
        $since = ts($input, 'since');
        $store = loadStore();

        $newer = fn(array $rows) => array_values(array_filter(
            $rows,
            fn($row) => ($row['updatedAt'] ?? 0) > $since
        ));

        ok([
            'tasks' => $newer($store['tasks']),
            'media' => $newer($store['media']),
            'now'   => nowMs(),
        ]);
    }

    /* דחיפת שינויים מקומיים. המאוחר ביותר מנצח. */
    case 'push': {
        requireToken($input['token'] ?? null);
        $tasks = is_array($input['tasks'] ?? null) ? $input['tasks'] : [];
        $media = is_array($input['media'] ?? null) ? $input['media'] : [];

        if (count($tasks) + count($media) > MAX_PUSH_ROWS) {
            fail('נשלחו יותר מדי רשומות בבת אחת');
        }

        $store = loadStore();
        $applied = 0;

        foreach ([['tasks', $tasks, 'cleanTask'], ['media', $media, 'cleanMedia']] as [$bucket, $rows, $sanitize]) {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $clean = $sanitize($row);
                if ($clean === null) continue;

                $existing = $store[$bucket][$clean['id']] ?? null;
                if ($existing !== null && ($existing['updatedAt'] ?? 0) >= $clean['updatedAt']) {
                    continue;
                }
                $store[$bucket][$clean['id']] = $clean;
                $applied++;

                // מחיקה משחררת גם את הקבצים; בלי זה הדיסק מתמלא ב-tombstones.
                if ($bucket === 'media' && $clean['deleted']) {
                    @unlink(mediaPath($clean['id'], 'file'));
                    @unlink(mediaPath($clean['id'], 'thumb'));
                }
            }
        }

        saveStore($store);
        ok(['applied' => $applied, 'now' => nowMs()]);
    }

    /* העלאת קובץ מדיה (multipart) */
    case 'media-upload': {
        requireToken($_POST['token'] ?? null);
        $id     = $_POST['id'] ?? '';
        $taskId = $_POST['taskId'] ?? '';
        if (!validId($id) || !validId($taskId)) {
            fail('מזהה קובץ לא תקין');
        }

        $saved = 0;
        foreach (['file', 'thumb'] as $field) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) continue;
            if ($_FILES[$field]['size'] > MAX_UPLOAD) {
                fail('הקובץ גדול מדי');
            }
            $target = mediaPath($id, $field === 'thumb' ? 'thumb' : 'file');
            if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
                fail('שמירת הקובץ בשרת נכשלה', 500);
            }
            @chmod($target, 0644);
            $saved++;
        }

        if ($saved === 0) {
            fail('לא התקבל קובץ');
        }
        ok(['saved' => $saved]);
    }

    /* הורדת קובץ מדיה */
    case 'media-file': {
        requireToken($_GET['token'] ?? null);
        $id    = $_GET['id'] ?? '';
        $which = ($_GET['which'] ?? 'file') === 'thumb' ? 'thumb' : 'file';
        if (!validId($id)) {
            fail('מזהה קובץ לא תקין');
        }

        $path = mediaPath($id, $which);
        if (!is_file($path)) {
            fail('הקובץ אינו קיים בשרת', 404);
        }

        $store = loadStore();
        $mime = $which === 'thumb'
            ? 'image/jpeg'
            : ($store['media'][$id]['mime'] ?? 'application/octet-stream');

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($path);
        exit;
    }

    default:
        fail('פעולה לא מוכרת');
}
