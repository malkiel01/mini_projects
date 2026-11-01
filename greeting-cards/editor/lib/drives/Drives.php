<?php
/**
 * מודול הכוננים — צד השרת של האפליקציה (PHP). עותק מסונכרן: לא לערוך כאן. המקור: suite-cloud/drives/src/php/.
 *
 * מה הוא עושה בשביל האפליקציה (suite-cloud/docs/spec-1-skeleton.md סעיף 7, ו-requests/account-menu.md):
 *   - זוג מפתחות Ed25519 של האפליקציה — נוצר בפעם הראשונה ונשמר ב-<dataDir>/drives.sqlite (לא בגיט, ומוחרג כמו כל *.sqlite).
 *   - רישום אוטומטי בענן החבילה (client_register), חיבור משתמש (connect → callback → connect_exchange),
 *     אסימון קצר לדפדפן (access_token), ניתוק. כל קריאה לענן — חתומה.
 *   - הדפדפן מדבר עם הענן ישירות, עם האסימון. המפתח הפרטי לא יוצא מהשרת.
 *
 * באפליקציה — שלוש שורות ב-api.php (לפני הבדיקה הרגילה של המשתמש):
 *
 *   require_once __DIR__ . '/lib/drives/Drives.php';
 *   if (str_starts_with($action, 'drives_')) (new \SuiteCloud\Drives([
 *       'client'   => 'vector-studio', 'name' => 'וקטורי',
 *       'dataDir'  => __DIR__ . '/data',
 *       'endpoint' => 'https://mbe-plus.com/mini_projects/vector-studio/api.php',
 *       'user'     => fn() => currentUserId(),                  // מזהה המשתמש אצל האפליקציה, או null
 *       'back'     => 'https://mbe-plus.com/mini_projects/vector-studio/',   // לאן חוזרים אחרי החיבור
 *   ]))->serve($action);
 *
 * וב-app.json של האפליקציה: "api.php?action=drives_key" ב-public (הענן מוריד משם את המפתח הציבורי).
 * הפעולות: drives_key (ציבורי), drives_status, drives_connect, drives_callback, drives_token, drives_disconnect.
 */

declare(strict_types=1);

namespace SuiteCloud;

final class DrivesError extends \RuntimeException {
    public function __construct(string $message, public readonly int $status = 400, public readonly string $codeName = 'bad_request') {
        parent::__construct($message);
    }
}

final class Drives {
    public const CLOUD = 'https://mbe-plus.com/mini_projects/suite-cloud/';
    private const STATE_TTL = 600;

    private array $cfg;
    private ?\PDO $pdo = null;

    /**
     * client, name, dataDir, endpoint (כתובת https מלאה של api.php של האפליקציה), user (callable → ?string),
     * back (לאן חוזרים אחרי חיבור), cloud? (כתובת הענן), scope? (['files','libs','ai'] ברירת מחדל), http? (callable — לבדיקות).
     */
    public function __construct(array $cfg) {
        foreach (['client', 'name', 'dataDir', 'endpoint', 'user', 'back'] as $k) {
            if (empty($cfg[$k])) throw new \InvalidArgumentException("Drives: חסר $k");
        }
        $this->cfg = $cfg + ['cloud' => self::CLOUD, 'scope' => ['files', 'libs', 'ai'], 'http' => null];
        $this->cfg['cloud'] = rtrim($this->cfg['cloud'], '/') . '/';
    }

    // ─── הפעולות ─────────────────────────────────────────────────────────

    /** מטפל בפעולה ומסיים את הבקשה (JSON או הפניה). */
    public function serve(string $action): never {
        try {
            $r = $this->handle($action, $_GET + ($this->jsonBody() ?? []));
            if (isset($r['redirect'])) {
                header('Location: ' . $r['redirect'], true, 303);
                exit;
            }
            $this->json($r);
        } catch (DrivesError $e) {
            $this->json(['error' => $e->getMessage(), 'code' => $e->codeName], $e->status);
        } catch (\Throwable $e) {
            error_log('drives: ' . $e);
            $this->json(['error' => 'תקלה בחיבור לענן', 'code' => 'server'], 500);
        }
    }

    public function handle(string $action, array $in): array {
        if ($action === 'drives_key') return $this->keyJson();
        $user = ($this->cfg['user'])();
        if (!is_string($user) || $user === '') throw new DrivesError('נדרשת כניסה', 401, 'unauthorized');
        return match ($action) {
            'drives_status'     => $this->status($user),
            'drives_connect'    => ['url' => $this->connectUrl($user, $in['scope'] ?? null)],
            'drives_callback'   => ['redirect' => $this->callback($user, $in)],
            'drives_token'      => $this->token($user),
            'drives_disconnect' => $this->disconnect($user),
            default             => throw new DrivesError('פעולה לא מוכרת', 404, 'unknown_action'),
        };
    }

    /** {"client","keys":[{kid,alg,key}]} — מה שהענן מוריד מ-key_url. */
    public function keyJson(): array {
        $k = $this->keys();
        return ['client' => $this->cfg['client'], 'keys' => [['kid' => $k['kid'], 'alg' => 'Ed25519', 'key' => base64_encode($k['pk'])]]];
    }

    public function status(string $user): array {
        $c = $this->conn($user);
        return ['suite' => $c ? ['connected' => true, 'name' => $c['name'], 'cloud_id' => $c['cloud_id'], 'scope' => json_decode($c['scope'], true),
                                'cloud' => $this->cfg['cloud']] : ['connected' => false, 'cloud' => $this->cfg['cloud']]];
    }

    /** הכתובת של מסך האישור בענן. state — חד-פעמי, של המשתמש הזה, 10 דקות. */
    public function connectUrl(string $user, mixed $scope = null): string {
        $this->register();
        $scope = is_string($scope) && $scope !== '' ? $scope : implode(',', $this->cfg['scope']);
        $state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $db = $this->db();
        $db->prepare('DELETE FROM states WHERE expires < ?')->execute([time()]);
        $db->prepare('INSERT INTO states (state, user, expires) VALUES (?,?,?)')->execute([$state, $user, time() + self::STATE_TTL]);
        return $this->cfg['cloud'] . 'connect.php?' . http_build_query([
            'client' => $this->cfg['client'], 'scope' => $scope, 'state' => $state, 'return' => $this->returnUrl(),
        ]);
    }

    /** החזרה מהענן: בודק state, מחליף קוד בחיבור, שומר. מחזיר לאן להפנות (back, עם ?cloud=connected|denied). */
    public function callback(string $user, array $in): string {
        $state = (string) ($in['state'] ?? '');
        $db = $this->db();
        $st = $db->prepare('SELECT * FROM states WHERE state = ?');
        $st->execute([$state]);
        $row = $st->fetch();
        $db->prepare('DELETE FROM states WHERE state = ?')->execute([$state]);
        if (!$row || $row['user'] !== $user || (int) $row['expires'] < time()) throw new DrivesError('החיבור פג או לא שלך — נסו שוב', 400, 'bad_state');
        $back = $this->cfg['back'];
        if (isset($in['error'])) return $back . (str_contains($back, '?') ? '&' : '?') . 'cloud=denied';
        $r = $this->call('connect_exchange', ['code' => (string) ($in['code'] ?? '')]);
        $db->prepare('INSERT INTO conns (user, connection, name, cloud_id, scope, created_at) VALUES (?,?,?,?,?,?)
                      ON CONFLICT(user) DO UPDATE SET connection = excluded.connection, name = excluded.name, cloud_id = excluded.cloud_id,
                      scope = excluded.scope, created_at = excluded.created_at')
            ->execute([$user, $r['connection'], (string) ($r['account']['name'] ?? ''), (string) ($r['cloud_id'] ?? ''), json_encode($r['scope'] ?? []), gmdate('c')]);
        return $back . (str_contains($back, '?') ? '&' : '?') . 'cloud=connected';
    }

    /** אסימון לדפדפן (15 דקות) + כתובת ה-API של הענן. נותק בענן → מוחקים כאן ומחזירים connected:false. */
    public function token(string $user): array {
        $c = $this->conn($user);
        if (!$c) return ['connected' => false];
        try {
            $r = $this->call('access_token', ['connection' => $c['connection']]);
        } catch (DrivesError $e) {
            if ($e->codeName === 'disconnected') { $this->forget($user); return ['connected' => false]; }
            throw $e;
        }
        return ['connected' => true, 'token' => $r['token'], 'expires_in' => $r['expires_in'], 'api' => $this->cfg['cloud'] . 'api.php',
                'cloud_id' => $c['cloud_id'], 'scope' => json_decode($c['scope'], true)];
    }

    public function disconnect(string $user): array {
        $c = $this->conn($user);
        if ($c) {
            try { $this->call('disconnect', ['connection' => $c['connection']]); } catch (DrivesError) { /* כבר נותק בענן — מוחקים כאן בכל מקרה */ }
            $this->forget($user);
        }
        return ['ok' => true];
    }

    // ─── הענן ────────────────────────────────────────────────────────────

    /** רישום בענן — פעם אחת, ושוב רק כשהפרטים משתנים. */
    private function register(): void {
        $payload = ['client' => $this->cfg['client'], 'name' => $this->cfg['name'], 'key_url' => $this->cfg['endpoint'] . '?action=drives_key',
                    'origins' => [$this->origin($this->cfg['endpoint'])], 'return' => [$this->returnUrl()]];
        $sig = hash('sha256', json_encode($payload));
        if ($this->meta('registered') === $sig) return;
        $r = $this->post('client_register', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), []);
        if (!in_array($r['status'] ?? '', ['active', 'pending'], true)) throw new DrivesError('הרישום בענן נכשל', 502, 'cloud');
        $this->setMeta('registered', $sig);
    }

    /** קריאה חתומה לענן. */
    private function call(string $action, array $body): array {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $k = $this->keys();
        $time = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sig = sodium_crypto_sign_detached("$action\n$time\n$nonce\n" . hash('sha256', $json), $k['sk']);
        try {
            return $this->post($action, $json, ["X-Cloud-Client: {$this->cfg['client']}", "X-Cloud-Time: $time", "X-Cloud-Nonce: $nonce",
                                                "X-Cloud-Signature: {$k['kid']}." . base64_encode($sig)]);
        } catch (DrivesError $e) {
            if ($e->codeName !== 'unknown_client') throw $e;
            $this->setMeta('registered', '');   // הענן לא מכיר אותנו (מסד חדש?) — נרשמים שוב ומנסים פעם אחת
            $this->register();
            return $this->call($action, $body);
        }
    }

    private function post(string $action, string $json, array $headers): array {
        $url = $this->cfg['cloud'] . 'api.php?action=' . $action;
        $headers[] = 'Content-Type: application/json';
        if (is_callable($this->cfg['http'])) {
            [$status, $body] = ($this->cfg['http'])($url, $headers, $json);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body === false) throw new DrivesError('ענן החבילה לא ענה', 502, 'cloud');
        }
        $j = json_decode((string) $body, true);
        if ($status >= 200 && $status < 300 && is_array($j)) return $j;
        throw new DrivesError(is_array($j) && isset($j['error']) ? (string) $j['error'] : "ענן החבילה החזיר שגיאה ($status)",
                              $status >= 400 && $status < 500 ? $status : 502, is_array($j) ? (string) ($j['code'] ?? 'cloud') : 'cloud');
    }

    // ─── אחסון ───────────────────────────────────────────────────────────

    private function db(): \PDO {
        if ($this->pdo) return $this->pdo;
        $dir = $this->cfg['dataDir'];
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $this->pdo = new \PDO('sqlite:' . rtrim($dir, '/') . '/drives.sqlite', null, null,
                              [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS conns (user TEXT PRIMARY KEY, connection TEXT NOT NULL, name TEXT NOT NULL,
                                              cloud_id TEXT NOT NULL, scope TEXT NOT NULL, created_at TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS states (state TEXT PRIMARY KEY, user TEXT NOT NULL, expires INTEGER NOT NULL);
        ");
        return $this->pdo;
    }

    private function meta(string $k): ?string {
        $st = $this->db()->prepare('SELECT v FROM meta WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function setMeta(string $k, string $v): void {
        $this->db()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
    }

    /** {kid, sk, pk} — המפתח של האפליקציה. נוצר פעם אחת; לא יוצא מהשרת. */
    private function keys(): array {
        $j = json_decode((string) $this->meta('key'), true);
        if (!is_array($j)) {
            $pair = sodium_crypto_sign_keypair();
            $j = ['kid' => gmdate('Y-m'), 'sk' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'pk' => base64_encode(sodium_crypto_sign_publickey($pair))];
            $this->db()->prepare('INSERT OR IGNORE INTO meta (k, v) VALUES (?, ?)')->execute(['key', json_encode($j)]);
            $j = json_decode((string) $this->meta('key'), true);   // מי שכתב ראשון — זה המפתח
        }
        return ['kid' => $j['kid'], 'sk' => base64_decode($j['sk']), 'pk' => base64_decode($j['pk'])];
    }

    private function conn(string $user): ?array {
        $st = $this->db()->prepare('SELECT * FROM conns WHERE user = ?');
        $st->execute([$user]);
        return $st->fetch() ?: null;
    }

    private function forget(string $user): void {
        $this->db()->prepare('DELETE FROM conns WHERE user = ?')->execute([$user]);
    }

    // ─── עזרים ───────────────────────────────────────────────────────────

    private function returnUrl(): string { return $this->cfg['endpoint'] . '?action=drives_callback'; }

    private function origin(string $url): string {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . strtolower($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private function jsonBody(): ?array {
        if (!str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) return null;
        $j = json_decode((string) file_get_contents('php://input'), true);
        return is_array($j) ? $j : null;
    }

    private function json(array $payload, int $code = 200): never {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
