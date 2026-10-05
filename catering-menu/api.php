<?php
/**
 * השרת של "תפריט קייטרינג ניחוחות".
 *
 * פעולה ציבורית אחת — order (לקוח שולח הזמנה). כל השאר דורש התחברות מנהל.
 * האחסון: קובץ JSON לכל הזמנה ב-data/orders/, כך ששתי הזמנות שנשלחות באותו
 * רגע לא דורסות זו את זו. data/ מוחרגת מהפריסה ב-deploy.yml.
 *
 * הסיסמה נקבעת בכניסה הראשונה לממשק המנהל ונשמרת כגיבוב ב-data/config.json.
 * לאיפוס: למחוק את הקובץ מהשרת (מנהל הקבצים של cPanel) ולקבוע סיסמה מחדש.
 */

declare(strict_types=1);

const DATA_DIR    = __DIR__ . '/data';
const ORDERS_DIR  = DATA_DIR . '/orders';
const CONFIG_FILE = DATA_DIR . '/config.json';
const MENU_FILE   = __DIR__ . '/assets/menu.json';
const MAX_ITEMS   = 200;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

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

function ensureDirs(): void {
    foreach ([DATA_DIR, ORDERS_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            fail('תיקיית data/ אינה ניתנת ליצירה — בדקו הרשאות בשרת', 500);
        }
    }
}

function body(): array {
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function text(array $row, string $key, int $max): string {
    $value = $row[$key] ?? '';
    if (!is_string($value)) return '';
    return mb_substr(trim($value), 0, $max);
}

/** מזהה הזמנה משמש כשם קובץ — ולכן נבדק בקפדנות. */
function validOrderId(mixed $id): bool {
    return is_string($id) && preg_match('/^\d{8}-[a-f0-9]{6}$/', $id) === 1;
}

function orderPath(string $id): string {
    return ORDERS_DIR . '/' . $id . '.json';
}

/** כתיבה אטומית — קריסה באמצע לא משאירה קובץ חצי-כתוב. */
function writeJson(string $path, array $data): void {
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
        fail('שמירת הנתונים בשרת נכשלה', 500);
    }
    rename($tmp, $path);
}

function readJson(string $path): ?array {
    if (!is_file($path)) return null;
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** מפת מזהה → מנה, מתוך התפריט שבריפו. ההזמנה נבדקת מולה. */
function menuIndex(): array {
    $menu = readJson(MENU_FILE);
    if (!$menu) fail('קובץ התפריט חסר בשרת', 500);
    $index = [];
    foreach ($menu['categories'] as $cat) {
        foreach ($cat['items'] as $item) {
            $index[$item['id']] = [
                'itemId'   => $item['id'],
                'name'     => $item['name'],
                'category' => $cat['name'],
                'extra'    => !empty($item['extra']),
            ];
        }
    }
    return $index;
}

/** המצב נגזר מהפריטים: אף אחד לא מוכן, חלק, או כולם. */
function derivedStatus(array $items): string {
    $ready = count(array_filter($items, fn($i) => !empty($i['ready'])));
    if ($ready === 0) return 'pending';
    return $ready === count($items) ? 'done' : 'in_progress';
}

/* ── התחברות ─────────────────────────────────────────────────────── */

function startSession(): void {
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
    session_name('catering_admin');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => $path,
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    session_start();
}

function config(): ?array {
    $config = readJson(CONFIG_FILE);
    return ($config && !empty($config['passwordHash'])) ? $config : null;
}

function requireAdmin(): void {
    startSession();
    if (empty($_SESSION['admin'])) fail('נדרשת התחברות', 401);
    session_write_close();
}

/* ── ניתוב ───────────────────────────────────────────────────────── */

ensureDirs();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    // טופס HTML מאתר זר לא יכול לשלוח JSON — זו ההגנה מפני CSRF.
    fail('בקשה לא תקינה');
}

switch ($action) {

    case 'order': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        $in = body();
        if (!empty($in['website'])) ok(['id' => '']);   // מלכודת לבוטים

        $name  = text($in, 'name', 80);
        $phone = text($in, 'phone', 30);
        if ($name === '')  fail('נא למלא שם');
        if (!preg_match('/^[0-9+\-\s()]{9,}$/', $phone)) fail('נא למלא מספר טלפון תקין');

        $ids = $in['items'] ?? [];
        if (!is_array($ids) || !$ids) fail('לא נבחרו מנות');
        if (count($ids) > MAX_ITEMS) fail('יותר מדי פריטים');

        $menu = menuIndex();
        $items = [];
        foreach (array_unique(array_filter($ids, 'is_string')) as $id) {
            if (isset($menu[$id])) $items[] = $menu[$id] + ['ready' => false];
        }
        if (!$items) fail('לא נבחרו מנות');

        $guests = $in['guests'] ?? 0;
        $order = [
            'id'        => date('Ymd') . '-' . bin2hex(random_bytes(3)),
            'createdAt' => date('c'),
            'customer'  => [
                'name'       => $name,
                'phone'      => $phone,
                'deliveryAt' => text($in, 'deliveryAt', 40),
                'guests'     => is_numeric($guests) ? max(0, min(100000, (int) $guests)) : 0,
                'notes'      => text($in, 'notes', 2000),
            ],
            'items'     => $items,
            'status'    => 'pending',
            'updatedAt' => date('c'),
        ];
        writeJson(orderPath($order['id']), $order);
        ok(['id' => $order['id']]);
    }

    case 'state': {
        startSession();
        ok(['configured' => config() !== null, 'loggedIn' => !empty($_SESSION['admin'])]);
    }

    case 'setup': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        if (config() !== null) fail('הסיסמה כבר נקבעה', 409);
        $password = (string) (body()['password'] ?? '');
        if (mb_strlen($password) < 6) fail('הסיסמה צריכה לפחות 6 תווים');
        writeJson(CONFIG_FILE, ['passwordHash' => password_hash($password, PASSWORD_DEFAULT), 'createdAt' => date('c')]);
        @chmod(CONFIG_FILE, 0600);
        startSession();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        ok();
    }

    case 'login': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        $config = config();
        if (!$config) fail('עדיין לא נקבעה סיסמה', 409);
        $password = (string) (body()['password'] ?? '');
        if (!password_verify($password, $config['passwordHash'])) {
            sleep(1);   // מאט ניחוש סיסמאות
            fail('סיסמה שגויה', 401);
        }
        startSession();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        ok();
    }

    case 'logout': {
        startSession();
        $_SESSION = [];
        session_destroy();
        ok();
    }

    case 'orders': {
        requireAdmin();
        $orders = [];
        foreach (glob(ORDERS_DIR . '/*.json') ?: [] as $file) {
            $order = readJson($file);
            if ($order) $orders[] = $order;
        }
        usort($orders, fn($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
        ok(['orders' => $orders]);
    }

    case 'update': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $in = body();
        $id = $in['id'] ?? null;
        if (!validOrderId($id)) fail('מזהה הזמנה שגוי');

        $path = orderPath($id);
        $lock = fopen($path . '.lock', 'c');
        flock($lock, LOCK_EX);
        $order = readJson($path);
        if (!$order) fail('ההזמנה לא נמצאה', 404);

        // סימון פריטים: { itemId: true/false } — המצב נגזר מחדש.
        if (isset($in['ready']) && is_array($in['ready'])) {
            foreach ($order['items'] as &$item) {
                if (array_key_exists($item['itemId'], $in['ready'])) {
                    $item['ready'] = (bool) $in['ready'][$item['itemId']];
                }
            }
            unset($item);
            $order['status'] = derivedStatus($order['items']);
        }
        // שינוי מצב ידני גובר, עד הסימון הבא.
        if (isset($in['status']) && in_array($in['status'], ['pending', 'in_progress', 'done'], true)) {
            $order['status'] = $in['status'];
        }
        if (isset($in['adminNotes'])) {
            $order['adminNotes'] = text($in, 'adminNotes', 2000);
        }
        $order['updatedAt'] = date('c');
        writeJson($path, $order);
        flock($lock, LOCK_UN);
        fclose($lock);
        ok(['order' => $order]);
    }

    case 'delete': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $id = body()['id'] ?? null;
        if (!validOrderId($id)) fail('מזהה הזמנה שגוי');
        @unlink(orderPath($id));
        @unlink(orderPath($id) . '.lock');
        ok();
    }

    default:
        fail('פעולה לא מוכרת', 404);
}
