<?php
/**
 * ספריית הלקוח של תוסף התשלומים — קובץ אחד, ‏PHP 8, בלי תלויות (curl + sodium).
 * כל אפליקציה / אתר **מעתיקים** את הקובץ אליהם (לא require לתיקיית התוסף). המדריך: payments/docs/connect.md
 *
 *   $pay = new PaymentsClient([
 *       'base_url' => 'https://mbe-plus.com/mini_projects/payments/',
 *       'key'      => 'sk_test_….…',            // קוד סודי מממשק הניהול — רק בשרת!
 *       // או, במקום key — Ed25519 (כמו platform/docs/embed.md): 'client' => 'guarded-browser', 'env' => 'test',
 *       //   'ed_kid' => '2026-10', 'ed_secret' => <64 בתים בינאריים>
 *       'webhook_secret' => 'whsec_….…',        // לאימות הודעות (מי שלא ב-Ed25519)
 *       'cache'    => $pdo,                      // אופציונלי: מטמון זכאות (טבלה payments_cache נוצרת לבד)
 *       'fail'     => 'open',                    // התוסף לא עונה: open — לאפשר (ברירת מחדל), closed — לחסום
 *   ]);
 *   $pay->customer('family:12', ['name' => 'משפחת כהן', 'email' => 'a@b.co']);
 *   $c = $pay->checkout('family:12', 'subscription', [['product' => 'apps']], $returnUrl, $cancelUrl);  // → $c['url']
 *   $e = $pay->entitlement('family:12');         // עם מטמון
 *   $r = $pay->reserve('user:7', 'ai_tokens', 8000); … $pay->commit($r['reservation'], 6320, 'ai:log:99812');
 *   $event = $pay->webhook();                    // בכתובת ה-webhook של האפליקציה: מאמת, או זורק
 */

declare(strict_types=1);

final class PaymentsError extends RuntimeException
{
    public function __construct(string $message, public readonly string $codeName = 'error', public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}

final class PaymentsClient
{
    public const VERSION = '1.1.0';

    private string $base;
    private array $o;

    public function __construct(array $options)
    {
        $this->o = $options + ['fail' => 'open', 'timeout' => 10];
        $this->base = rtrim((string)($options['base_url'] ?? ''), '/') . '/';
        if (empty($options['key']) && empty($options['ed_secret'])) throw new InvalidArgumentException('צריך key (קוד סודי) או ed_secret');
    }

    // ---------- הפעולות ----------

    public function ping(): array { return $this->call('ping', []); }
    public function catalog(): array { return $this->call('catalog', []); }

    public function customer(string $externalId, array $fields = []): array
    {
        return $this->call('customer_upsert', ['external_id' => $externalId] + $fields);
    }

    /** ‏$mode: payment | subscription | topup | invoice | setup. מחזיר ‏{checkout, url, expires_at, amount}. */
    public function checkout(string $externalId, string $mode, array $items, string $returnUrl, string $cancelUrl, array $extra = []): array
    {
        return $this->call('checkout_create', ['external_id' => $externalId, 'mode' => $mode, 'items' => $items, 'return_url' => $returnUrl, 'cancel_url' => $cancelUrl] + $extra);
    }

    /** אחרי החזרה מדף התשלום — לא סומכים על הכתובת, שואלים. */
    public function checkoutStatus(string $checkoutId): array { return $this->call('checkout_get', ['checkout' => $checkoutId]); }

    /** קנייה לפי יחידה בלחיצה מכרטיס שמור. בלי כרטיס → ‏status=requires_action + url. */
    public function charge(string $externalId, array $items, string $idempotencyKey, ?string $returnUrl = null, ?string $cancelUrl = null): array
    {
        return $this->call('charge', array_filter(['external_id' => $externalId, 'items' => $items, 'idempotency_key' => $idempotencyKey,
            'return_url' => $returnUrl, 'cancel_url' => $cancelUrl], fn($v) => $v !== null));
    }

    /** מה מותר ללקוח עכשיו. במטמון לפי ttl; כשהתוסף לא עונה — הערך האחרון (‏stale) או לפי fail. */
    public function entitlement(string $externalId, bool $fresh = false): array
    {
        if (!$fresh && ($c = $this->cacheGet($externalId, false))) return $c;
        try {
            $e = $this->call('entitlement', ['external_id' => $externalId]);
            $this->cachePut($externalId, $e);
            return $e;
        } catch (PaymentsError $err) {
            if ($err->status && $err->status < 500 && $err->codeName !== 'network') throw $err;
            if ($c = $this->cacheGet($externalId, true)) return $c + ['stale' => true];
            $open = $this->o['fail'] !== 'closed';
            return ['active' => $open, 'state' => $open ? 'unknown' : 'none', 'stale' => true, 'plans' => [], 'features' => [], 'limits' => [], 'balance' => 0, 'can_spend' => $open];
        }
    }

    /** ‏idempotency_key ייחודי לכל אירוע — אותו מפתח פעמיים נספר פעם אחת. */
    public function usage(string $externalId, string $product, float $quantity, string $idempotencyKey, array $meta = []): array
    {
        return $this->call('usage_record', ['external_id' => $externalId, 'product' => $product, 'quantity' => $quantity, 'idempotency_key' => $idempotencyKey, 'meta' => (object)$meta]);
    }

    public function reserve(string $externalId, string $product, float $estimate, int $ttl = 900): array
    {
        return $this->call('usage_reserve', ['external_id' => $externalId, 'product' => $product, 'estimate' => $estimate, 'ttl' => $ttl]);
    }

    public function commit(string $reservation, float $quantity, string $idempotencyKey): array
    {
        return $this->call('usage_commit', ['reservation' => $reservation, 'quantity' => $quantity, 'idempotency_key' => $idempotencyKey]);
    }

    public function release(string $reservation): array { return $this->call('usage_release', ['reservation' => $reservation]); }

    public function subscriptions(string $externalId): array { return $this->call('subscription_list', ['external_id' => $externalId])['data'] ?? []; }

    public function changeSubscription(string $externalId, string $subscription, array $items, ?string $proration = null): array
    {
        return $this->call('subscription_update', array_filter(['external_id' => $externalId, 'subscription' => $subscription, 'items' => $items, 'proration' => $proration], fn($v) => $v !== null));
    }

    public function cancelSubscription(string $externalId, string $subscription, bool $now = false): array
    {
        return $this->call('subscription_cancel', ['external_id' => $externalId, 'subscription' => $subscription, 'at' => $now ? 'now' : 'period_end']);
    }

    /** קישור חד-פעמי ל"החשבון שלי" — הפניה מיד. */
    public function portalUrl(string $externalId, string $returnUrl): string
    {
        return (string)$this->call('portal_session', ['external_id' => $externalId, 'return_url' => $returnUrl])['url'];
    }

    public function invoices(string $externalId): array { return $this->call('invoice_list', ['external_id' => $externalId])['data'] ?? []; }

    // ---------- הודעות מהתוסף (webhook) ----------

    /**
     * בכתובת ה-webhook של האפליקציה: מאמת חתימה, זמן (±5 דקות) ומחזיר את האירוע. זורק PaymentsError אם לא תקין.
     * החתימה: ‏"webhook\n<time>\n<evt>\n<sha256(body)>" — ב-Ed25519 של התוסף ("<kid>.<base64>") או ב-HMAC ("hmac.<hex>").
     * חשוב: האירוע הוא רמז; לפני החלטה — entitlement($id, true). ‏id חוזר — להתעלם (הסדר לא מובטח).
     */
    public function webhook(?string $raw = null, ?array $headers = null): array
    {
        $raw ??= (string)file_get_contents('php://input');
        $h = $headers ?? self::requestHeaders();
        $evt = (string)($h['x-payments-event'] ?? '');
        $time = (string)($h['x-payments-time'] ?? '');
        $sig = (string)($h['x-payments-signature'] ?? '');
        if ($evt === '' || !ctype_digit($time) || abs(time() - (int)$time) > 300) throw new PaymentsError('הודעה ישנה או חסרה', 'stale', 400);
        $msg = "webhook\n$time\n$evt\n" . hash('sha256', $raw);
        if (str_starts_with($sig, 'hmac.')) {
            $secret = $this->secretPart((string)($this->o['webhook_secret'] ?? ''));
            if ($secret === '' || !hash_equals(hash_hmac('sha256', $msg, $secret), substr($sig, 5))) throw new PaymentsError('חתימה לא תקינה', 'bad_signature', 401);
        } else {
            [$kid, $b64] = array_pad(explode('.', $sig, 2), 2, '');
            $bin = base64_decode($b64, true);
            $ok = false;
            foreach ([false, true] as $refresh) {
                $pk = $this->pluginKeys($refresh)[$kid] ?? null;
                if ($pk && $bin && strlen($bin) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($bin, $msg, $pk)) { $ok = true; break; }
            }
            if (!$ok) throw new PaymentsError('חתימה לא תקינה', 'bad_signature', 401);
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || ($j['id'] ?? '') !== $evt) throw new PaymentsError('גוף לא תקין', 'bad_body', 400);
        // אותו מפתח חותם לכל המערכות ולשתי הסביבות — הודעה של מערכת אחרת, או מבדיקות, לא מתקבלת כאן
        $key = (string)($this->o['key'] ?? '');
        $env = $this->o['env'] ?? (str_starts_with($key, 'sk_live_') ? 'live' : (str_starts_with($key, 'sk_test_') ? 'test' : (empty($this->o['client']) ? null : 'live')));
        $app = $this->o['app'] ?? $this->o['client'] ?? null;
        if ($env !== null && ($j['env'] ?? '') !== $env) throw new PaymentsError('הודעה מסביבה אחרת', 'wrong_env', 400);
        if ($app !== null && ($j['app'] ?? '') !== $app) throw new PaymentsError('הודעה של מערכת אחרת', 'wrong_app', 400);
        if (!empty($j['external_id'])) $this->cacheDrop((string)$j['external_id']); // משהו השתנה — הזכאות תיקרא מחדש
        return $j;
    }

    // ---------- תקשורת ----------

    private function call(string $action, array $payload): array
    {
        $body = json_encode($payload === [] ? (object)[] : $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $msg = "$action\n$time\n$nonce\n" . hash('sha256', $body);
        $hdr = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: payments-client-php/' . self::VERSION];
        if (!empty($this->o['key'])) {
            [$keyId, $secret] = array_pad(explode('.', (string)$this->o['key'], 2), 2, '');
            array_push($hdr, "X-Pay-Key: $keyId", "X-Pay-Time: $time", "X-Pay-Nonce: $nonce", 'X-Pay-Signature: ' . hash_hmac('sha256', $msg, $secret));
        } else {
            $client = (string)$this->o['client'] . (($this->o['env'] ?? 'live') === 'test' ? '~test' : '');
            $sig = sodium_crypto_sign_detached($msg, (string)$this->o['ed_secret']);
            array_push($hdr, "X-Embed-Client: $client", "X-Embed-Time: $time", "X-Embed-Nonce: $nonce", 'X-Embed-Signature: ' . $this->o['ed_kid'] . '.' . base64_encode($sig));
        }
        $ch = curl_init($this->base . 'api.php?action=' . rawurlencode($action));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_TIMEOUT => (int)$this->o['timeout'], CURLOPT_CONNECTTIMEOUT => 4]);
        $out = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) throw new PaymentsError('התוסף לא זמין: ' . $err, 'network', 0);
        $j = json_decode((string)$out, true);
        if ($status >= 400 || !is_array($j)) throw new PaymentsError((string)($j['error'] ?? "שגיאה $status"), (string)($j['code'] ?? 'error'), $status);
        return $j;
    }

    private function secretPart(string $full): string
    {
        return str_contains($full, '.') ? explode('.', $full, 2)[1] : $full;
    }

    /** המפתח הציבורי של התוסף — במטמון שעה (בקובץ זמני). */
    private function pluginKeys(bool $refresh): array
    {
        $file = sys_get_temp_dir() . '/payments-key-' . md5($this->base) . '.json';
        if (!$refresh && is_file($file) && filemtime($file) > time() - 3600) {
            $j = json_decode((string)file_get_contents($file), true);
        } else {
            $ch = curl_init($this->base . 'api.php?action=key');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $j = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            if (is_array($j)) @file_put_contents($file, json_encode($j));
        }
        $out = [];
        foreach ((array)($j['keys'] ?? []) as $k) if (($k['alg'] ?? '') === 'Ed25519') $out[(string)$k['kid']] = base64_decode((string)$k['key']);
        return $out;
    }

    private static function requestHeaders(): array
    {
        $h = [];
        foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $h[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
        return $h;
    }

    // ---------- מטמון זכאות ----------

    private function db(): ?PDO
    {
        $c = $this->o['cache'] ?? null;
        if (!$c instanceof PDO) return null;
        static $made = [];
        $id = spl_object_id($c);
        if (!isset($made[$id])) {
            $c->exec('CREATE TABLE IF NOT EXISTS payments_cache (external_id TEXT PRIMARY KEY, body TEXT NOT NULL, fresh_until INTEGER NOT NULL, saved_at INTEGER NOT NULL)');
            $made[$id] = true;
        }
        return $c;
    }

    private function cacheGet(string $ext, bool $stale): ?array
    {
        $db = $this->db();
        if (!$db) return null;
        $st = $db->prepare('SELECT body, fresh_until, saved_at FROM payments_cache WHERE external_id = ?');
        $st->execute([$ext]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if (!$row) return null;
        if (!$stale && (int)$row['fresh_until'] < time()) return null;
        if ($stale && (int)$row['saved_at'] < time() - 86400) return null; // ערך ישן מיום — לא סומכים
        return json_decode($row['body'], true) ?: null;
    }

    private function cachePut(string $ext, array $e): void
    {
        $db = $this->db();
        if (!$db) return;
        $db->prepare('REPLACE INTO payments_cache (external_id, body, fresh_until, saved_at) VALUES (?, ?, ?, ?)')
            ->execute([$ext, json_encode($e, JSON_UNESCAPED_UNICODE), time() + (int)($e['ttl'] ?? 300), time()]);
    }

    private function cacheDrop(string $ext): void
    {
        $db = $this->db();
        if ($db) $db->prepare('DELETE FROM payments_cache WHERE external_id = ?')->execute([$ext]);
    }
}
