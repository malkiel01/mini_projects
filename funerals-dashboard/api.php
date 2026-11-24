<?php
declare(strict_types=1);

/**
 * API לניהול תיקי לוויות. המסמכים עצמם נשארים ב-Dropbox;
 * במסד נשמרים ממצאים וקישורים בלבד. אין לחשוף את data/ לדפדפן.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
ini_set('display_errors', '0');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('fd_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/mini_projects/funerals-dashboard/',
    'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'
]);
session_start();

function reply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function config(): array {
    $path = __DIR__ . '/data/config.php';
    if (!is_file($path)) reply(503, ['error' => 'המערכת טרם הוגדרה. יש להריץ את setup.php דרך שורת הפקודה.']);
    $c = require $path;
    if (!is_array($c) || empty($c['password_hash']) || empty($c['api_token_sha256'])) {
        reply(503, ['error' => 'קובץ ההגדרות אינו תקין']);
    }
    return $c;
}
function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO('sqlite:' . __DIR__ . '/data/funerals.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS funerals (id TEXT PRIMARY KEY, month TEXT NOT NULL, name TEXT NOT NULL, body TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_funerals_month ON funerals(month)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, funeral_id TEXT NOT NULL, at TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL)');
        return $pdo;
    } catch (Throwable $e) {
        reply(503, ['error' => 'מסד הנתונים אינו זמין. בדוק הרשאות כתיבה ותמיכה ב-SQLite.']);
    }
}
function body(): array {
    if (strlen((string)($_SERVER['CONTENT_LENGTH'] ?? '')) > 0 && (int)$_SERVER['CONTENT_LENGTH'] > 1200000) {
        reply(413, ['error' => 'הבקשה גדולה מדי']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 1200001);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) reply(400, ['error' => 'נדרש JSON תקין']);
    return $data;
}
function validMonth(string $month): string {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) reply(400, ['error' => 'חודש לא תקין']);
    return $month;
}
function cleanText(mixed $value, int $max = 250): string {
    $v = trim(is_scalar($value) ? (string)$value : '');
    if (mb_strlen($v) > $max) reply(400, ['error' => 'ערך ארוך מהמותר']);
    return $v;
}
function dropboxUrl(mixed $value): string {
    $url = cleanText($value, 1500);
    if ($url === '') return '';
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || !in_array(strtolower($p['host'] ?? ''), ['dropbox.com','www.dropbox.com'], true)
        || isset($p['user']) || isset($p['pass'])) reply(400, ['error' => 'מותר לשמור קישורי HTTPS של Dropbox בלבד']);
    return $url;
}
function normalize(array $v): array {
    $month = validMonth(cleanText($v['month'] ?? '', 7));
    $name = cleanText($v['name'] ?? '', 160);
    if ($name === '') reply(400, ['error' => 'נדרש שם נפטר']);
    $id = cleanText($v['id'] ?? '', 100);
    if ($id !== '' && !preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id)) reply(400, ['error' => 'מזהה תיק אינו תקין']);
    if ($id === '') $id = bin2hex(random_bytes(12));
    $date = cleanText($v['date'] ?? '', 10);
    if ($date !== '' && (!preg_match('/^\d{4}-\d\d-\d\d$/D', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)))) reply(400, ['error' => 'תאריך לא תקין']);
    $time = cleanText($v['time'] ?? '', 5);
    if ($time !== '' && !preg_match('/^(0\d|1\d|2[0-3]):[0-5]\d$/D', $time)) reply(400, ['error' => 'שעה לא תקינה']);
    $allowed = ['verified','document_present','unreadable','missing','unknown','reported','not_applicable'];
    $checks = [];
    $rawChecks = $v['checks'] ?? [];
    if (!is_array($rawChecks) || count($rawChecks) > 60) reply(400, ['error' => 'בדיקות לא תקינות']);
    foreach ($rawChecks as $key => $value) {
        if (!is_string($key) || !preg_match('/^[a-z_]{2,50}$/D', $key) || !is_array($value)) reply(400, ['error' => 'שדה בדיקה אינו תקין']);
        $status = cleanText($value['status'] ?? 'unknown', 30);
        if (!in_array($status, $allowed, true)) reply(400, ['error' => 'סטטוס בדיקה אינו תקין']);
        $checks[$key] = ['status'=>$status, 'value'=>cleanText($value['value'] ?? '', 500),
            'source'=>cleanText($value['source'] ?? '', 250), 'note'=>cleanText($value['note'] ?? '', 800)];
    }
    $docs = [];
    $rawDocs = $v['documents'] ?? [];
    if (!is_array($rawDocs) || count($rawDocs) > 100) reply(400, ['error' => 'רשימת מסמכים לא תקינה']);
    foreach ($rawDocs as $doc) {
        if (!is_array($doc)) reply(400, ['error' => 'פרטי מסמך לא תקינים']);
        $docs[] = ['label'=>cleanText($doc['label'] ?? '', 150), 'url'=>dropboxUrl($doc['url'] ?? ''),
            'kind'=>cleanText($doc['kind'] ?? '', 80)];
    }
    $payments = [];
    $rawPayments = $v['payments'] ?? [];
    if (!is_array($rawPayments) || count($rawPayments) > 40) reply(400, ['error' => 'תשלומים לא תקינים']);
    foreach ($rawPayments as $payment) {
        if (!is_array($payment)) reply(400, ['error' => 'תשלום אינו תקין']);
        $amount = $payment['amount'] ?? null;
        if ($amount !== null && (!is_numeric($amount) || (float)$amount < 0 || (float)$amount > 99999999)) reply(400, ['error' => 'סכום לא תקין']);
        $payments[] = ['amount'=>$amount === null ? null : round((float)$amount, 2),
            'purpose'=>cleanText($payment['purpose'] ?? '', 250), 'reference'=>cleanText($payment['reference'] ?? '', 120),
            'source'=>cleanText($payment['source'] ?? '', 250)];
    }
    $manual = [];
    foreach (($v['manual_fields'] ?? []) as $field) {
        if (is_string($field) && in_array($field, ['name','date','time','origin','team','grave','notes','folder_url','payments'], true)) $manual[] = $field;
    }
    return ['id'=>$id, 'month'=>$month, 'name'=>$name, 'date'=>$date, 'time'=>$time,
        'origin'=>cleanText($v['origin'] ?? '', 180), 'team'=>cleanText($v['team'] ?? '', 180),
        'grave'=>cleanText($v['grave'] ?? '', 180), 'notes'=>cleanText($v['notes'] ?? '', 1500),
        'folder_url'=>dropboxUrl($v['folder_url'] ?? ''), 'documents'=>$docs, 'checks'=>$checks,
        'payments'=>$payments, 'manual_fields'=>array_values(array_unique($manual))];
}
function existing(PDO $pdo, string $id): ?array {
    $st = $pdo->prepare('SELECT body FROM funerals WHERE id=?');
    $st->execute([$id]);
    $json = $st->fetchColumn();
    return $json === false ? null : json_decode((string)$json, true);
}
function store(PDO $pdo, array $v, string $actor, string $action): void {
    $st = $pdo->prepare('INSERT INTO funerals(id,month,name,body,updated_at) VALUES(?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET month=excluded.month,name=excluded.name,body=excluded.body,updated_at=excluded.updated_at');
    $st->execute([$v['id'], $v['month'], $v['name'], json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gmdate('c')]);
    $st = $pdo->prepare('INSERT INTO audit(funeral_id,at,actor,action) VALUES(?,?,?,?)');
    $st->execute([$v['id'], gmdate('c'), $actor, $action]);
}

$cfg = config();
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$bearer = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($bearer === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $bearer = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
$tokenAuth = str_starts_with($bearer, 'Bearer ') &&
    hash_equals((string)$cfg['api_token_sha256'], hash('sha256', substr($bearer, 7)));
$sessionAuth = !empty($_SESSION['fd_auth']);
if ($action === 'login') {
    if ($method !== 'POST') reply(405, ['error'=>'שיטה לא מורשית']);
    $payload = body();
    $user = cleanText($payload['username'] ?? '', 100);
    $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
    if (strlen($password) > 200) reply(400, ['error'=>'סיסמה ארוכה מדי']);
    if ($user !== ($cfg['username'] ?? 'admin') || !password_verify($password, (string)$cfg['password_hash'])) {
        usleep(350000);
        reply(401, ['error'=>'שם משתמש או סיסמה אינם נכונים']);
    }
    session_regenerate_id(true);
    $_SESSION['fd_auth'] = true;
    $_SESSION['fd_csrf'] = bin2hex(random_bytes(24));
    reply(200, ['ok'=>true,'csrf'=>$_SESSION['fd_csrf']]);
}
if (!$tokenAuth && !$sessionAuth) reply(401, ['error'=>'יש להתחבר למערכת']);
if ($action === 'me') {
    reply(200, ['ok'=>true,'csrf'=>$sessionAuth ? ($_SESSION['fd_csrf'] ?? '') : '',
        'embed_key'=>cleanText($cfg['dropbox_app_key'] ?? '', 100)]);
}
if ($action === 'logout') {
    if (!$sessionAuth || $method !== 'POST') reply(403, ['error'=>'אין הרשאה']);
    if (!hash_equals((string)($_SESSION['fd_csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) reply(403, ['error'=>'אימות בקשה נכשל']);
    $_SESSION = [];
    session_destroy();
    reply(200, ['ok'=>true]);
}
if ($method === 'POST' && !$tokenAuth &&
    (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || !hash_equals((string)($_SESSION['fd_csrf'] ?? ''), (string)$_SERVER['HTTP_X_CSRF_TOKEN']))) {
    reply(403, ['error'=>'אימות בקשה נכשל']);
}
$pdo = db();
if ($action === 'list' || $action === 'export') {
    if ($method !== 'GET') reply(405, ['error'=>'שיטה לא מורשית']);
    $month = validMonth((string)($_GET['month'] ?? ''));
    $st = $pdo->prepare('SELECT body FROM funerals WHERE month=? ORDER BY name COLLATE NOCASE');
    $st->execute([$month]);
    $items = array_map(static fn($row) => json_decode($row['body'], true), $st->fetchAll());
    if ($action === 'export') {
        header('Content-Disposition: attachment; filename="funerals-' . $month . '.json"');
        reply(200, ['schema_version'=>1, 'month'=>$month, 'records'=>$items]);
    }
    reply(200, ['month'=>$month, 'records'=>$items]);
}
if ($action === 'save') {
    if ($method !== 'POST' || !$sessionAuth) reply(403, ['error'=>'שינוי ידני דורש התחברות']);
    $payload = body();
    $item = normalize($payload);
    $old = existing($pdo, $item['id']);
    if ($old !== null) {
        foreach (['name','date','time','origin','team','grave','notes','folder_url','payments'] as $field) {
            if (($item[$field] ?? null) !== ($old[$field] ?? null)) $item['manual_fields'][] = $field;
        }
        $item['manual_fields'] = array_values(array_unique(array_merge($old['manual_fields'] ?? [], $item['manual_fields'])));
    } else {
        $item['manual_fields'] = ['name','date','time','origin','team','grave','notes','folder_url','payments'];
    }
    foreach ($item['checks'] as &$check) {
        if ($check['status'] !== 'unknown') {
            $check['source'] = 'עדכון ידני';
            $check['status'] = 'reported';
        }
    }
    unset($check);
    store($pdo,$item,'dashboard','save');
    reply(200, ['ok'=>true,'record'=>$item]);
}
if ($action === 'import') {
    if ($method !== 'POST') reply(405, ['error'=>'שיטה לא מורשית']);
    $payload = body();
    $records = $payload['records'] ?? null;
    if (!is_array($records) || count($records) > 60) reply(400, ['error'=>'נדרשת רשימת רשומות (עד 60)']);
    $pdo->beginTransaction();
    try {
        $count = 0;
        foreach ($records as $raw) {
            if (!is_array($raw)) reply(400, ['error'=>'רשומה לא תקינה']);
            $item = normalize($raw);
            $old = existing($pdo,$item['id']);
            if ($old !== null) {
                $item['manual_fields'] = $old['manual_fields'] ?? [];
                foreach ($item['manual_fields'] as $field) if (array_key_exists($field,$old)) $item[$field]=$old[$field];
                foreach (($old['checks'] ?? []) as $key => $c) {
                    if (($c['status'] ?? '') === 'reported') $item['checks'][$key]=$c;
                }
            }
            store($pdo,$item,$tokenAuth ? 'api' : 'dashboard','import');
            $count++;
        }
        $pdo->commit();
        reply(200, ['ok'=>true, 'imported'=>$count]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        reply(500, ['error'=>'שמירת הנתונים נכשלה']);
    }
}
reply(404, ['error'=>'פעולה לא מוכרת']);
