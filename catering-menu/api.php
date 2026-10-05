<?php
/**
 * השרת של "תפריט קייטרינג ניחוחות".
 *
 * פעולות ציבוריות: order (לקוח שולח הזמנה) ו-config (מגבלות הבחירה לתפריט).
 * כל השאר דורש התחברות מנהל.
 * האחסון: קובץ JSON לכל הזמנה ב-data/orders/, כך ששתי הזמנות שנשלחות באותו
 * רגע לא דורסות זו את זו. data/ מוחרגת מהפריסה ב-deploy.yml.
 *
 * הסיסמה נקבעת בכניסה הראשונה לממשק המנהל ונשמרת כגיבוב ב-data/config.json.
 * לאיפוס: למחוק את הקובץ מהשרת (מנהל הקבצים של cPanel) ולקבוע סיסמה מחדש.
 *
 * הגדרות המנהל (מיקום לזמני היום, מגבלות בחירה, מיילים) — data/settings.json.
 * מיילים יוצאים דרך mail() של PHP, שעובד בשרתי cPanel בלי הגדרה נוספת.
 */

declare(strict_types=1);

const DATA_DIR    = __DIR__ . '/data';
const ORDERS_DIR  = DATA_DIR . '/orders';
const CONFIG_FILE = DATA_DIR . '/config.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
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
function menu(): array {
    static $menu = null;
    $menu ??= readJson(MENU_FILE);
    if (!$menu) fail('קובץ התפריט חסר בשרת', 500);
    return $menu;
}

function menuIndex(): array {
    $index = [];
    foreach (menu()['categories'] as $cat) {
        foreach ($cat['items'] as $item) {
            $index[$item['id']] = [
                'itemId'   => $item['id'],
                'name'     => $item['name'],
                'category' => $cat['name'],
                'catId'    => $cat['id'],
                'extra'    => !empty($item['extra']),
                'options'  => $item['options'] ?? [],
            ];
        }
    }
    return $index;
}

/* ── הגדרות המנהל ─────────────────────────────────────────────────── */

function defaultSettings(): array {
    return [
        // ברירת מחדל: ירושלים — הדלקה 40 דקות לפני השקיעה, צאת ב-8.5° מתחת לאופק
        'location' => [
            'id' => 'jerusalem', 'name' => 'ירושלים', 'lat' => 31.778, 'lon' => 35.235,
            'candleOffset' => 40, 'havdalahMode' => 'tzeit', 'havdalahMinutes' => 40,
        ],
        'limits' => [],          // מזהה קטגוריה → כמה מותר לבחור (אין מפתח = ללא הגבלה)
        'email'  => ['from' => '', 'replyTo' => '', 'adminNotify' => ''],
    ];
}

function loadSettings(): array {
    $saved = readJson(SETTINGS_FILE) ?? [];
    $def = defaultSettings();
    return [
        'location' => ($saved['location'] ?? []) + $def['location'],
        'limits'   => $saved['limits'] ?? [],
        'email'    => ($saved['email'] ?? []) + $def['email'],
    ];
}

function cleanEmail(mixed $v): string {
    $v = is_string($v) ? trim($v) : '';
    return ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) ? mb_substr($v, 0, 120) : '';
}

function cleanSettings(array $in): array {
    $loc = is_array($in['location'] ?? null) ? $in['location'] : [];
    $num = fn($v, $min, $max, $def) => is_numeric($v) ? max($min, min($max, (float) $v)) : $def;
    $def = defaultSettings()['location'];

    $catIds = array_column(menu()['categories'], 'id');
    $limits = [];
    foreach ((array) ($in['limits'] ?? []) as $cat => $n) {
        if (in_array($cat, $catIds, true) && is_numeric($n) && (int) $n > 0) $limits[$cat] = min(99, (int) $n);
    }
    $email = is_array($in['email'] ?? null) ? $in['email'] : [];

    return [
        'location' => [
            'id'              => text($loc, 'id', 40),
            'name'            => text($loc, 'name', 60) ?: $def['name'],
            'lat'             => round($num($loc['lat'] ?? null, -66, 66, $def['lat']), 4),
            'lon'             => round($num($loc['lon'] ?? null, -180, 180, $def['lon']), 4),
            'candleOffset'    => (int) $num($loc['candleOffset'] ?? null, 0, 90, 20),
            'havdalahMode'    => ($loc['havdalahMode'] ?? '') === 'minutes' ? 'minutes' : 'tzeit',
            'havdalahMinutes' => (int) $num($loc['havdalahMinutes'] ?? null, 10, 120, 40),
        ],
        'limits' => $limits,
        'email'  => [
            'from'        => cleanEmail($email['from'] ?? ''),
            'replyTo'     => cleanEmail($email['replyTo'] ?? ''),
            'adminNotify' => cleanEmail($email['adminNotify'] ?? ''),
        ],
    ];
}

/* ── מיילים ───────────────────────────────────────────────────────── */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** שולח מייל HTML בעברית. מחזיר true אם mail() קיבל אותו. */
function sendMail(string $to, string $subject, string $bodyHtml): bool {
    if ($to === '') return false;
    $settings = loadSettings();
    $business = menu()['business']['name'] ?? 'קייטרינג';
    $host = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
    $from = $settings['email']['from'] ?: 'noreply@' . $host;

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . mb_encode_mimeheader($business, 'UTF-8') . " <$from>",
    ];
    if ($settings['email']['replyTo']) $headers[] = 'Reply-To: ' . $settings['email']['replyTo'];

    $html = '<!DOCTYPE html><html lang="he" dir="rtl"><body style="margin:0;background:#faf7f1;'
          . 'font-family:Arial,sans-serif;color:#232326"><div style="max-width:560px;margin:0 auto;'
          . 'background:#fff;padding:24px;direction:rtl;text-align:right">'
          . '<div style="background:#27282a;color:#e9b45a;font-size:26px;font-weight:bold;padding:14px;'
          . 'text-align:center;border-radius:10px">' . h($business) . '</div>'
          . $bodyHtml . '</div></body></html>';

    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $html, implode("\r\n", $headers), '-f' . $from);
}

function orderItemsHtml(array $order): string {
    $byCat = [];
    foreach ($order['items'] as $i) $byCat[$i['category']][] = $i['name'] . (!empty($i['extra']) ? ' *' : '');
    $out = '';
    foreach ($byCat as $cat => $names) {
        $out .= '<p style="margin:12px 0 4px;color:#c98f2e;font-weight:bold">' . h($cat) . '</p><ul style="margin:0;padding-right:18px">';
        foreach ($names as $n) $out .= '<li>' . h($n) . '</li>';
        $out .= '</ul>';
    }
    return $out;
}

function businessFooter(): string {
    $b = menu()['business'] ?? [];
    $phones = implode(' / ', $b['phones'] ?? []);
    return '<p style="margin-top:20px;color:#77736b;font-size:14px">' . h(($b['owner'] ?? '') . ' · ' . $phones) . '</p>';
}

/** מייל ללקוח על החלטת המנהל. מחזיר 'sent' / 'failed' / 'none' (אין מייל). */
function mailDecision(array $order): string {
    $to = $order['customer']['email'] ?? '';
    if ($to === '') return 'none';
    $c = $order['customer'];
    $when = $c['deliveryAt'] ? ' לתאריך ' . $c['deliveryAt'] : '';

    if ($order['approval'] === 'approved') {
        $subject = 'הזמנתך אושרה';
        $body = '<h2>שלום ' . h($c['name']) . ',</h2><p>שמחים לעדכן: <b>ההזמנה שלך' . h($when) . ' אושרה</b>.</p>'
              . ($c['guests'] ? '<p>מספר סועדים: ' . (int) $c['guests'] . '</p>' : '')
              . orderItemsHtml($order) . businessFooter();
    } else {
        $subject = 'עדכון לגבי הזמנתך';
        $body = '<h2>שלום ' . h($c['name']) . ',</h2><p>לצערנו לא נוכל לקבל את ההזמנה' . h($when) . '.</p>'
              . (!empty($order['rejectReason']) ? '<p>' . nl2br(h($order['rejectReason'])) . '</p>' : '')
              . '<p>נשמח לעזור בתאריך אחר — אפשר ליצור איתנו קשר.</p>' . businessFooter();
    }
    return sendMail($to, $subject, $body) ? 'sent' : 'failed';
}

/** תאריך YYYY-MM-DD ושעה HH:MM — או מחרוזת ריקה, אם הערך לא תקין. */
function cleanDate(mixed $v): string {
    return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : '';
}
function cleanTime(mixed $v): string {
    return (is_string($v) && preg_match('/^\d{2}:\d{2}$/', $v)) ? $v : '';
}

/** "20/10/2026 19:00" — התצוגה של מועד האירוע, נגזרת מהתאריך והשעה. */
function deliveryLabel(string $date, string $time): string {
    if ($date === '') return $time;
    [$y, $m, $d] = explode('-', $date);
    return trim("$d/$m/$y $time");
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

        $email = cleanEmail($in['email'] ?? '');
        if (text($in, 'email', 120) !== '' && $email === '') fail('כתובת המייל אינה תקינה');

        $menu = menuIndex();
        $chosen = is_array($in['options'] ?? null) ? $in['options'] : [];
        $items = [];
        $perCat = [];
        foreach (array_unique(array_filter($ids, 'is_string')) as $id) {
            if (!isset($menu[$id])) continue;
            $perCat[$menu[$id]['catId']] = ($perCat[$menu[$id]['catId']] ?? 0) + 1;
            $item = $menu[$id];
            // מנה עם אפשרויות (למשל סוג הדג) — הבחירה חובה, ונכנסת לשם המנה
            if ($item['options']) {
                $option = $chosen[$id] ?? '';
                if (!in_array($option, $item['options'], true)) fail('נא לבחור ' . $item['name'] . ': ' . implode(' / ', $item['options']));
                $item['option'] = $option;
                $item['name'] .= ' — ' . $option;
            }
            unset($item['catId'], $item['options']);
            $items[] = $item + ['ready' => false];
        }
        if (!$items) fail('לא נבחרו מנות');

        // מגבלות הבחירה נבדקות גם כאן — התפריט בדפדפן הוא נוחות, לא אכיפה
        $limits = loadSettings()['limits'];
        foreach (menu()['categories'] as $cat) {
            $max = $limits[$cat['id']] ?? 0;
            if ($max && ($perCat[$cat['id']] ?? 0) > $max) fail("ב{$cat['name']} אפשר לבחור עד $max");
        }

        $guests = $in['guests'] ?? 0;
        $order = [
            'id'        => date('Ymd') . '-' . bin2hex(random_bytes(3)),
            'createdAt' => date('c'),
            'customer'  => [
                'name'       => $name,
                'phone'      => $phone,
                'email'      => $email,
                'deliveryDate' => cleanDate($in['deliveryDate'] ?? ''),
                'deliveryTime' => cleanTime($in['deliveryTime'] ?? ''),
                'deliveryAt' => deliveryLabel(cleanDate($in['deliveryDate'] ?? ''), cleanTime($in['deliveryTime'] ?? '')),
                'guests'     => is_numeric($guests) ? max(0, min(100000, (int) $guests)) : 0,
                'notes'      => text($in, 'notes', 2000),
            ],
            'items'     => $items,
            'status'    => 'pending',
            'approval'  => 'new',      // new → approved / rejected (החלטת המנהל)
            'read'      => false,      // האם המנהל כבר פתח את ההזמנה
            'updatedAt' => date('c'),
        ];
        writeJson(orderPath($order['id']), $order);

        $notify = loadSettings()['email']['adminNotify'];
        if ($notify) {
            sendMail($notify, 'הזמנה חדשה מ' . $name,
                '<h2>הזמנה חדשה</h2><p><b>' . h($name) . '</b> · ' . h($phone)
                . ($order['customer']['deliveryAt'] ? ' · ' . h($order['customer']['deliveryAt']) : '') . '</p>'
                . orderItemsHtml($order));
        }
        ok(['id' => $order['id']]);
    }

    case 'config': {
        // ציבורי: רק מה שהתפריט צריך כדי לאכוף את מגבלות הבחירה
        ok(['limits' => (object) loadSettings()['limits']]);
    }

    case 'settings': {
        requireAdmin();
        ok(['settings' => loadSettings()]);
    }

    case 'saveSettings': {
        if ($method !== 'POST') fail('שיטה לא נתמכת', 405);
        requireAdmin();
        $settings = cleanSettings(body());
        writeJson(SETTINGS_FILE, $settings);
        ok(['settings' => $settings]);
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
        // המנהל יכול לקבוע או לתקן את מועד האירוע — זה מה שממקם אותו ביומן.
        if (array_key_exists('deliveryDate', $in) || array_key_exists('deliveryTime', $in)) {
            $c = &$order['customer'];
            if (array_key_exists('deliveryDate', $in)) $c['deliveryDate'] = cleanDate($in['deliveryDate']);
            if (array_key_exists('deliveryTime', $in)) $c['deliveryTime'] = cleanTime($in['deliveryTime']);
            $c['deliveryAt'] = deliveryLabel($c['deliveryDate'] ?? '', $c['deliveryTime'] ?? '');
            unset($c);
        }
        if (isset($in['read'])) {
            $order['read'] = (bool) $in['read'];
        }
        // אישור או סירוב — ומייל ללקוח, אם השאיר כתובת
        $mail = null;
        if (isset($in['approval']) && in_array($in['approval'], ['new', 'approved', 'rejected'], true)) {
            $order['approval'] = $in['approval'];
            $order['rejectReason'] = $in['approval'] === 'rejected' ? text($in, 'reason', 1000) : '';
            $order['decidedAt'] = date('c');
            $order['read'] = true;
            if ($in['approval'] !== 'new' && !empty($in['notify'])) {
                $mail = mailDecision($order);
                $order['mailLog'][] = ['at' => date('c'), 'approval' => $in['approval'], 'result' => $mail];
            }
        }
        if (isset($in['adminNotes'])) {
            $order['adminNotes'] = text($in, 'adminNotes', 2000);
        }
        $order['updatedAt'] = date('c');
        writeJson($path, $order);
        flock($lock, LOCK_UN);
        fclose($lock);
        ok(['order' => $order, 'mail' => $mail]);
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
