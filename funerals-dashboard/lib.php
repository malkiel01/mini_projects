<?php
declare(strict_types=1);

/**
 * ניהול לוויות חברה קדישא — קוד משותף ל-api.php (דפדפן, מאחורי השער) ול-ingest.php (מערכות חיצוניות, Bearer בלבד).
 * המסמכים עצמם נשארים ב-Dropbox; במסד נשמרים ממצאים וקישורים בלבד. אין לחשוף את data/ לדפדפן.
 * הקובץ אינו נקודת קצה: גישה ישירה אליו מחזירה 404.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
ini_set('display_errors', '0');

/** גרסת האפליקציה — מוצגת בתפריט המשתמש ובמסך המפתח. */
const VERSION = '1.0.0';

require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/events.php';
require_once __DIR__ . '/lib/secrets.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/form.php';   // פרוסה 02 — הטופס החכם (מקור לבדיקה, finalAllowed, יומן התיק)

function dataDir(): string { return __DIR__ . '/data'; }

/** תשובה שנלכדה (במקום echo + exit) — כשפעולה רצה מתוך פעולה אחרת, למשל כלי של הצ'אט (ai/lib.php, aiExt). */
final class FdReply extends Exception {
    public function __construct(public readonly int $status, public readonly array $data) { parent::__construct((string)($data['error'] ?? ''), $status); }
}
function reply(int $status, array $data): never {
    if (!empty($GLOBALS['fd_reply_capture'])) throw new FdReply($status, $data);
    if ($status >= 400) $GLOBALS['fd_err'] = (string)($data['error'] ?? '');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
/**
 * ההגדרות מ-data/config.php. כשהקובץ חסר — נוצר עכשיו עם ברירות מחדל (אין יותר setup.php).
 * מפתחות: api_token_sha256 (ריק = אין אסימון), dropbox_app_key. מפתחות ישנים מהכניסה בסיסמה שהוסרה — מתעלמים מהם.
 */
function configPath(): string { return __DIR__ . '/data/config.php'; }
function config(): array {
    $defaults = ['api_token_sha256' => '', 'dropbox_app_key' => ''];
    $path = configPath();
    if (!is_file($path)) {
        writeConfig($defaults);
        return $defaults;
    }
    $c = require $path;
    if (!is_array($c)) reply(503, ['error' => 'קובץ ההגדרות אינו תקין']);
    return $c + $defaults;
}
/** כתיבה אטומית של data/config.php בהרשאות 0600 (קובץ זמני + rename). */
function writeConfig(array $cfg): void {
    $path = configPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) reply(503, ['error' => 'לא ניתן ליצור את תיקיית הנתונים']);
    $old = umask(0077);
    try {
        $tmp = @tempnam($dir, '.config-');
        if ($tmp === false || @file_put_contents($tmp, "<?php\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX) === false) {
            if (is_string($tmp)) @unlink($tmp);
            reply(503, ['error' => 'לא ניתן לשמור את קובץ ההגדרות. בדוק הרשאות כתיבה ל-data/']);
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) { @unlink($tmp); reply(503, ['error' => 'לא ניתן לשמור את קובץ ההגדרות']); }
    } finally {
        umask($old);
    }
    @chmod($path, 0600);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);   // שאסימון שבוטל לא ימשיך לעבוד מהמטמון
}
/**
 * הזהות מהשער של הפלטפורמה — המקור היחיד לזהות המשתמש באפליקציה.
 * $_SERVER['PLATFORM_USER'] (JSON) נכתב ע"י השער; המפתח אינו HTTP_*, ולכן אי אפשר לזייף אותו מהבקשה.
 * מחזיר null אם אין זהות, אם היא לא תקינה, או אם granted אינו true בדיוק.
 */
function platformUser(): ?array {
    $raw = $_SERVER['PLATFORM_USER'] ?? null;
    if (!is_string($raw) || $raw === '') return null;
    $id = json_decode($raw, true);
    if (!is_array($id) || !is_scalar($id['sub'] ?? null) || !preg_match('/^[0-9]{1,12}$/D', (string)$id['sub'])) return null;
    if (($id['granted'] ?? null) !== true) return null;
    $email = is_string($id['email'] ?? null) && mb_strlen($id['email']) <= 200 && filter_var($id['email'], FILTER_VALIDATE_EMAIL)
        ? mb_strtolower($id['email']) : '';
    $name = mb_substr(trim((string)preg_replace('/\s+/u', ' ', is_string($id['name'] ?? null) ? $id['name'] : '')), 0, 120);
    // email_verified — רק מייל מאומת מקשר חשבון קיים או מממש הזמנה (השער שולח שם משתמש כ-email כשלא אומת)
    return ['sub' => (string)$id['sub'], 'name' => $name, 'email' => $email, 'email_verified' => ($id['email_verified'] ?? null) === true,
        'platform_admin' => ($id['platform_admin'] ?? null) === true,
        'actor' => $email !== '' ? $email : ($name !== '' ? $name : 'platform:' . $id['sub'])];
}
function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    try {
        if (!is_dir(dataDir()) && !@mkdir(dataDir(), 0700, true)) throw new RuntimeException('no data dir');
        $pdo = new PDO('sqlite:' . dataDir() . '/funerals.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS funerals (id TEXT PRIMARY KEY, month TEXT NOT NULL, name TEXT NOT NULL, body TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_funerals_month ON funerals(month)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, funeral_id TEXT NOT NULL, at TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL)');
    } catch (Throwable $e) {
        $pdo = null;
        reply(503, ['error' => 'מסד הנתונים אינו זמין. בדוק הרשאות כתיבה ותמיכה ב-SQLite.']);
    }
    migrate($pdo);
    return $pdo;   // secret.key נוצר בשימוש הראשון (secretKey) — כך ingest.php ושאר האפליקציה לא תלויים ב-sodium
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
/** $orgId — לארגון 1 המזהה המחושב נשאר כמו קודם (ייבוא חוזר מזהה אותו תיק); בארגון אחר הארגון נכנס לגיבוב. */
function normalize(array $v, int $orgId = 1): array {
    $month = validMonth(cleanText($v['month'] ?? '', 7));
    $name = cleanText($v['name'] ?? '', 160);
    if ($name === '') reply(400, ['error' => 'נדרש שם נפטר']);
    $id = cleanText($v['id'] ?? '', 100);
    if ($id !== '' && !preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id)) reply(400, ['error' => 'מזהה תיק אינו תקין']);
    if ($id === '') $id = 'fd_' . substr(hash('sha256', ($orgId === 1 ? '' : 'org' . $orgId . '|') . $month . '|' . $name . '|' . ($v['date'] ?? '')), 0, 24);
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
/**
 * התיק בארגון $orgId. תיק שקיים בארגון אחר → 404 (לא מגלים שהוא קיים, הכרעה ה5).
 */
function existing(PDO $pdo, string $id, int $orgId): ?array {
    $st = $pdo->prepare('SELECT body, org_id FROM funerals WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row === false) return null;
    if ((int)$row['org_id'] !== $orgId) reply(404, ['error' => 'התיק לא נמצא']);
    return json_decode((string)$row['body'], true);
}
/** שומר תיק בארגון ורושם ב-events את מה שהשתנה. $old — התיק לפני השינוי (null = חדש). */
function store(PDO $pdo, array $v, ?array $old, int $orgId, array $actor, string $source, string $action): void {
    $st = $pdo->prepare('INSERT INTO funerals(id,month,name,body,updated_at,org_id) VALUES(?,?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET month=excluded.month,name=excluded.name,body=excluded.body,updated_at=excluded.updated_at WHERE funerals.org_id=excluded.org_id');
    $st->execute([$v['id'], $v['month'], $v['name'], json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gmdate('c'), $orgId]);
    if ($st->rowCount() !== 1) reply(404, ['error' => 'התיק לא נמצא']);
    recordEvent($orgId, $v['id'], $actor, $source, $old === null ? 'create' : $action, caseDiff($old, $v));
}

/** אסימון ה-API מהכותרת Authorization: Bearer — נבדק מול ה-hash שב-config. אין hash (טרם נוצר) → תמיד false. */
function bearerOk(array $cfg): bool {
    $bearer = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($bearer === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $bearer = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    $hash = (string)($cfg['api_token_sha256'] ?? '');
    return $hash !== '' && str_starts_with($bearer, 'Bearer ') && strlen($bearer) > 7
        && hash_equals($hash, hash('sha256', substr($bearer, 7)));
}
function listMonth(PDO $pdo, string $action, string $method, int $orgId): never {
    if ($method !== 'GET') reply(405, ['error'=>'שיטה לא מורשית']);
    $month = validMonth((string)($_GET['month'] ?? ''));
    $st = $pdo->prepare('SELECT body FROM funerals WHERE org_id=? AND month=? ORDER BY name COLLATE NOCASE');
    $st->execute([$orgId, $month]);
    $items = array_map(static fn($row) => json_decode($row['body'], true), $st->fetchAll());
    if ($action === 'export') {
        header('Content-Disposition: attachment; filename="funerals-' . $month . '.json"');
        reply(200, ['schema_version'=>1, 'month'=>$month, 'records'=>$items]);
    }
    reply(200, ['month'=>$month, 'records'=>$items]);
}
/** $source — 'import' (ingest.php) או 'ui' (ייבוא קובץ מהמסך). */
function importRecords(PDO $pdo, string $method, int $orgId, array $actor, string $source): never {
    if ($method !== 'POST') reply(405, ['error'=>'שיטה לא מורשית']);
    $payload = body();
    $records = $payload['records'] ?? null;
    if (!is_array($records) || count($records) > 60) reply(400, ['error'=>'נדרשת רשימת רשומות (עד 60)']);
    $pdo->beginTransaction();
    // reply() בתוך הלולאה (רשומה לא תקינה, תיק של ארגון אחר) יוצא מהתהליך — והטרנזקציה שלא נסגרה מתבטלת לבד
    try {
        $count = 0;
        foreach ($records as $raw) {
            if (!is_array($raw)) reply(400, ['error'=>'רשומה לא תקינה']);
            $item = normalize($raw, $orgId);
            $old = existing($pdo, $item['id'], $orgId);
            if ($old !== null) {
                $item['manual_fields'] = $old['manual_fields'] ?? [];
                foreach (['name','date','time','origin','team','grave','notes','folder_url','payments'] as $field) {
                    if (in_array($field, $item['manual_fields'], true)
                        || (($item[$field] ?? null) === '' && !empty($old[$field]))
                        || ($field === 'payments' && empty($item['payments']) && !empty($old['payments']))) {
                        if (array_key_exists($field, $old)) $item[$field] = $old[$field];
                    }
                }
                if (!$item['documents']) $item['documents'] = $old['documents'] ?? [];
                $item['checks'] = array_replace(($old['checks'] ?? []), $item['checks']);
                foreach (($old['checks'] ?? []) as $key => $c) {
                    if (($c['source'] ?? '') === 'עדכון ידני' || ($c['status'] ?? '') === 'reported') $item['checks'][$key]=$c;
                }
            }
            $item['checks'] = formApplyChecks($item['checks'], $old['checks'] ?? null, 'import', (string)($actor['actor'] ?? ''));   // פרוסה 02: מקור לבדיקה
            store($pdo, $item, $old, $orgId, $actor, $source, 'import');
            $count++;
        }
        $pdo->commit();
        reply(200, ['ok'=>true, 'imported'=>$count]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        reply(500, ['error'=>'שמירת הנתונים נכשלה']);
    }
}
