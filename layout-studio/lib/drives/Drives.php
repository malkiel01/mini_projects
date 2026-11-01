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
 *
 * כוננים חיצוניים (requests/account-menu.md סעיף 2 — חיבור ישיר מהאפליקציה):
 *   drives_link?provider=dropbox → { url } (OAuth עם PKCE) · drives_oauth_callback (החזרה) · drives_ptoken?provider=dropbox
 *   → { token, expires_in } לדפדפן, שמדבר עם Dropbox ישירות · drives_unlink?provider=dropbox.
 *   ה-App key של Dropbox מגיע מענן החבילה (drives_config, שבעל המערכת ממלא בניהול), או מ-cfg['dropbox'] ⇒ ['app_key', 'redirect']
 *   לאפליקציה בדומיין משלה. בלי App key — Dropbox פשוט לא מופיע. אסימון הרענון נשמר רק ב-drives.sqlite של האפליקציה.
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
            // החזרה מהענן היא ניווט של הדפדפן — דף ברור עם דרך חזרה, לא JSON
            if (in_array($action, ['drives_callback', 'drives_oauth_callback'], true)) $this->page($e->getMessage(), $e->codeName, $e->status);
            $this->json(['error' => $e->getMessage(), 'code' => $e->codeName], $e->status);
        } catch (\Throwable $e) {
            error_log('drives: ' . $e);
            if (in_array($action, ['drives_callback', 'drives_oauth_callback'], true)) $this->page('תקלה בחיבור לענן', 'server', 500);
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
            'drives_link'       => ['url' => $this->linkUrl($user, (string) ($in['provider'] ?? ''))],
            'drives_oauth_callback' => ['redirect' => $this->oauthCallback($user, $in)],
            'drives_ptoken'     => $this->providerToken($user, (string) ($in['provider'] ?? '')),
            'drives_unlink'     => $this->unlink($user, (string) ($in['provider'] ?? '')),
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
        $out = ['suite' => $c ? ['connected' => true, 'name' => $c['name'], 'cloud_id' => $c['cloud_id'], 'scope' => json_decode($c['scope'], true),
                                 'cloud' => $this->cfg['cloud']] : ['connected' => false, 'cloud' => $this->cfg['cloud']]];
        foreach (array_keys(self::PROVIDERS) as $p) {
            if (!$this->providerConfig($p)) continue;   // בלי App key — הכונן לא מופיע
            $l = $this->link($user, $p);
            $out[$p] = $l ? ['connected' => true, 'name' => $l['name']] : ['connected' => false];
        }
        return $out;
    }

    // ─── כוננים חיצוניים ─────────────────────────────────────────────────

    private const PROVIDERS = [
        'dropbox' => [
            'authorize' => 'https://www.dropbox.com/oauth2/authorize',
            'token' => 'https://api.dropboxapi.com/oauth2/token',
            'revoke' => 'https://api.dropboxapi.com/2/auth/token/revoke',
            'account' => 'https://api.dropboxapi.com/2/users/get_current_account',
        ],
    ];

    /** {app_key, redirect} — מהגדרות האפליקציה, או מענן החבילה (במטמון שעה). null — הספק לא מוגדר. */
    private function providerConfig(string $p): ?array {
        if (!isset(self::PROVIDERS[$p])) return null;
        if (isset($this->cfg[$p]['app_key'])) return $this->cfg[$p] + ['redirect' => $this->endpointOnly() . '?action=drives_oauth_callback&provider=' . $p];
        $cached = json_decode((string) $this->meta('drives_config'), true);
        if (!is_array($cached) || ($cached['at'] ?? 0) < time() - 3600) {
            try {
                $this->register();
                $conf = $this->call('drives_config', []);
                $cached = ['at' => $conf ? time() : time() - 3300, 'config' => $conf];   // עוד לא הוגדר כלום — בודקים שוב בעוד 5 דקות
            } catch (\Throwable $e) {
                error_log('drives config: ' . $e->getMessage());
                $cached = ['at' => time() - 3300, 'config' => $cached['config'] ?? []];   // ננסה שוב בעוד 5 דקות
            }
            $this->setMeta('drives_config', json_encode($cached));
        }
        $c = $cached['config'][$p] ?? null;
        return is_array($c) && !empty($c['app_key']) && !empty($c['redirect']) ? $c : null;
    }

    private function needProvider(string $p): array {
        $c = $this->providerConfig($p);
        if (!$c) throw new DrivesError('הכונן הזה לא מוגדר', 404, 'no-provider');
        return $c;
    }

    /** כתובת ההרשאה אצל הספק. PKCE: ה-verifier נשמר כאן בלבד, וה-state מתחיל במזהה האפליקציה (לדף ההעברה בענן). */
    public function linkUrl(string $user, string $p): string {
        $c = $this->needProvider($p);
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $state = $this->cfg['client'] . '.' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $db = $this->db();
        $db->prepare('DELETE FROM pstates WHERE expires < ?')->execute([time()]);
        $db->prepare('INSERT INTO pstates (state, user, provider, verifier, expires) VALUES (?,?,?,?,?)')
            ->execute([$state, $user, $p, $verifier, time() + self::STATE_TTL]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return self::PROVIDERS[$p]['authorize'] . '?' . http_build_query([
            'client_id' => $c['app_key'], 'response_type' => 'code', 'redirect_uri' => $c['redirect'], 'state' => $state,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'token_access_type' => 'offline',
        ]);
    }

    /** החזרה מהספק: state של המשתמש הזה → החלפת הקוד (עם ה-verifier) באסימון רענון → שמירה. */
    public function oauthCallback(string $user, array $in): string {
        $state = (string) ($in['state'] ?? '');
        $db = $this->db();
        $st = $db->prepare('SELECT * FROM pstates WHERE state = ?');
        $st->execute([$state]);
        $row = $st->fetch();
        $db->prepare('DELETE FROM pstates WHERE state = ?')->execute([$state]);
        $back = $this->cfg['back'] . (str_contains($this->cfg['back'], '?') ? '&' : '?');
        if (!$row || $row['user'] !== $user || (int) $row['expires'] < time()) {
            $why = !$row ? 'missing' : ($row['user'] !== $user ? 'user' : 'expired');
            if (!$row && $this->link($user, (string) ($in['provider'] ?? ''))) return $back . 'drive=connected';   // חזרה כפולה
            error_log("drives oauth ({$this->cfg['client']}): state $why");
            throw new DrivesError('החיבור לכונן פג או נפתח בחשבון אחר — נסו שוב מהאפליקציה', 400, "bad_state:$why");
        }
        $p = $row['provider'];
        if (isset($in['error'])) return $back . 'drive=denied';
        $c = $this->needProvider($p);
        $t = $this->form(self::PROVIDERS[$p]['token'], ['grant_type' => 'authorization_code', 'code' => (string) ($in['code'] ?? ''),
            'client_id' => $c['app_key'], 'code_verifier' => $row['verifier'], 'redirect_uri' => $c['redirect']]);
        if (empty($t['refresh_token']) || empty($t['access_token'])) throw new DrivesError('הספק לא החזיר הרשאה', 502, 'provider');
        $name = '';
        try {
            $acc = $this->rpc(self::PROVIDERS[$p]['account'], $t['access_token']);
            $name = (string) ($acc['name']['display_name'] ?? $acc['email'] ?? '');
        } catch (DrivesError) { /* השם רק לתצוגה */ }
        $db->prepare('INSERT INTO links (user, provider, refresh, name, access, access_until, created_at) VALUES (?,?,?,?,?,?,?)
                      ON CONFLICT(user, provider) DO UPDATE SET refresh = excluded.refresh, name = excluded.name, access = excluded.access,
                      access_until = excluded.access_until, created_at = excluded.created_at')
            ->execute([$user, $p, $t['refresh_token'], $name, $t['access_token'], time() + (int) ($t['expires_in'] ?? 14400) - 120, gmdate('c')]);
        return $back . 'drive=connected';
    }

    /** אסימון גישה קצר לדפדפן (מתחדש מאסימון הרענון). הרשאה שבוטלה אצל הספק → מוחקים ומחזירים connected:false. */
    public function providerToken(string $user, string $p): array {
        $l = $this->link($user, $p);
        if (!$l) return ['connected' => false];
        if ((int) $l['access_until'] > time() + 60) return ['connected' => true, 'token' => $l['access'], 'expires_in' => (int) $l['access_until'] - time()];
        $c = $this->needProvider($p);
        try {
            $t = $this->form(self::PROVIDERS[$p]['token'], ['grant_type' => 'refresh_token', 'refresh_token' => $l['refresh'], 'client_id' => $c['app_key']]);
        } catch (DrivesError $e) {
            if ($e->status === 400 || $e->status === 401) { $this->forgetLink($user, $p); return ['connected' => false]; }
            throw $e;
        }
        $until = time() + (int) ($t['expires_in'] ?? 14400) - 120;
        $this->db()->prepare('UPDATE links SET access = ?, access_until = ? WHERE user = ? AND provider = ?')->execute([$t['access_token'], $until, $user, $p]);
        return ['connected' => true, 'token' => $t['access_token'], 'expires_in' => $until - time()];
    }

    public function unlink(string $user, string $p): array {
        $l = $this->link($user, $p);
        if ($l && isset(self::PROVIDERS[$p])) {
            try {
                $tok = $this->providerToken($user, $p);
                if (!empty($tok['token'])) $this->rpc(self::PROVIDERS[$p]['revoke'], $tok['token']);
            } catch (\Throwable) { /* מוחקים כאן בכל מקרה */ }
            $this->forgetLink($user, $p);
        }
        return ['ok' => true];
    }

    private function link(string $user, string $p): ?array {
        $st = $this->db()->prepare('SELECT * FROM links WHERE user = ? AND provider = ?');
        $st->execute([$user, $p]);
        return $st->fetch() ?: null;
    }

    private function forgetLink(string $user, string $p): void {
        $this->db()->prepare('DELETE FROM links WHERE user = ? AND provider = ?')->execute([$user, $p]);
    }

    /** POST טופס לספק (OAuth). */
    private function form(string $url, array $fields): array {
        return $this->send($url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /** קריאת RPC של Dropbox (גוף JSON ריק). */
    private function rpc(string $url, string $token): array {
        return $this->send($url, ["Authorization: Bearer $token", 'Content-Type: application/json'], 'null');
    }

    private function send(string $url, array $headers, string $body): array {
        if (is_callable($this->cfg['http'])) {
            [$status, $out] = ($this->cfg['http'])($url, $headers, $body);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $out = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($out === false) throw new DrivesError('הספק לא ענה', 502, 'provider');
        }
        $j = json_decode((string) $out, true);
        if ($status >= 200 && $status < 300) return is_array($j) ? $j : [];
        error_log("drives provider $status: " . mb_substr((string) $out, 0, 300));
        throw new DrivesError('הספק דחה את הבקשה', $status >= 400 && $status < 500 ? $status : 502, 'provider');
    }

    private function endpointOnly(): string { return strtok($this->cfg['endpoint'], '?'); }

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
        $back = $this->cfg['back'];
        $db = $this->db();
        $st = $db->prepare('SELECT * FROM states WHERE state = ?');
        $st->execute([$state]);
        $row = $st->fetch();
        $why = match (true) {
            !$row => 'missing',
            $row['user'] !== $user => 'user',
            (int) $row['expires'] < time() => 'expired',
            default => null,
        };
        if ($why !== null) {
            error_log("drives callback ({$this->cfg['client']}): state $why");
            throw new DrivesError(['missing' => 'בקשת החיבור לא נמצאה', 'user' => 'בקשת החיבור נפתחה בחשבון אחר באפליקציה',
                                   'expired' => 'בקשת החיבור פגה (10 דקות)'][$why] . ' — נסו שוב מהאפליקציה', 400, "bad_state:$why");
        }
        // חזרה כפולה (רענון, או דפדפן ששולח את הבקשה פעמיים): הראשונה כבר חיברה — לא שגיאה, פשוט חוזרים
        $mark = $db->prepare('UPDATE states SET used = 1 WHERE state = ? AND used = 0');
        $mark->execute([$state]);
        if ($mark->rowCount() === 0) {
            if ($this->conn($user)) return $back . (str_contains($back, '?') ? '&' : '?') . 'cloud=connected';
            throw new DrivesError('בקשת החיבור כבר נוצלה — נסו שוב מהאפליקציה', 400, 'bad_state:used');
        }
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
            CREATE TABLE IF NOT EXISTS states (state TEXT PRIMARY KEY, user TEXT NOT NULL, expires INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS pstates (state TEXT PRIMARY KEY, user TEXT NOT NULL, provider TEXT NOT NULL, verifier TEXT NOT NULL, expires INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS links (user TEXT NOT NULL, provider TEXT NOT NULL, refresh TEXT NOT NULL, name TEXT NOT NULL,
                                              access TEXT, access_until INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL,
                                              PRIMARY KEY (user, provider));
        ");
        // מסד מגרסה קודמת — בלי העמודה used
        $cols = array_column($this->pdo->query('PRAGMA table_info(states)')->fetchAll(), 'name');
        if (!in_array('used', $cols, true)) $this->pdo->exec('ALTER TABLE states ADD COLUMN used INTEGER NOT NULL DEFAULT 0');
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

    /** דף שגיאה קצר בעברית, עם כפתור חזרה לאפליקציה — לחזרה מהענן. */
    private function page(string $msg, string $code, int $status): never {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $m = htmlspecialchars($msg);
        $c = htmlspecialchars($code);
        $b = htmlspecialchars($this->cfg['back'] . (str_contains($this->cfg['back'], '?') ? '&' : '?') . 'cloud=error');
        echo <<<HTML
<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>חיבור לענן החבילה</title>
<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:24px 16px;background:#f4f5f7;color:#16192b}
.box{max-width:420px;margin:auto;background:#fff;border:1px solid #e3e6eb;border-radius:10px;padding:16px;display:grid;gap:12px}
a{display:block;text-align:center;background:#2d4a8a;color:#fff;border-radius:10px;padding:10px;text-decoration:none}
small{color:#5d6475}@media(prefers-color-scheme:dark){body{background:#111318;color:#e8eaf0}.box{background:#1b1e26;border-color:#2c303b}}</style>
</head><body><div class="box"><b>☁️ החיבור לענן לא הושלם</b><div>$m</div><a href="$b">חזרה לאפליקציה</a><small>קוד: $c</small></div></body></html>
HTML;
        exit;
    }

    private function json(array $payload, int $code = 200): never {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
