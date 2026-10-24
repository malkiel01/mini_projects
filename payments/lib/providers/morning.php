<?php
/**
 * מורנינג (חשבונית ירוקה) — סליקה ומסמך חשבונאי בקריאה אחת (פרק 5, ההכרעה מ-2026-10-24).
 * לפי התיעוד הרשמי: developers.morning.co (openapi.bundled.json, גרסה 2.0.0).
 *
 *  - כניסה: ‏OAuth2 ‏client_credentials → ‏accessToken לשעה (נשמר מוצפן ומתחדש לבד).
 *  - דף תשלום: ‏POST /payments/form → כתובת. מורנינג מפיקה את המסמך (320 חשבונית מס/קבלה, או 400 קבלה לעוסק פטור).
 *  - אישור: לא סומכים על החזרה ולא על notifyUrl (לא חתום) — שואלים: ‏POST /documents/search לפי האסמכתא שבתיאור.
 *    ההודעות החתומות (webhooks, ‏x-webhook-signature) — רק מזרזות את השאלה.
 *  - טוקן: מורנינג שומרת את הכרטיס אצלה, קשור ל"לקוח" שלה (externalKey). ‏POST /payments/tokens/search.
 *  - חיוב יזום: ‏POST /payments/tokens/{id}/charge → המסמך שנוצר.
 *  - אין שמירת כרטיס בלי חיוב, ואין החזר דרך הממשק — החזר נעשה בממשק מורנינג ונרשם אצלנו ידנית.
 */

declare(strict_types=1);

final class MorningProvider implements Provider
{
    private const HOSTS = [
        'test' => ['idp' => 'https://api.sandbox.morning.dev', 'api' => 'https://sandbox.d.greeninvoice.co.il/api/v1'],
        'live' => ['idp' => 'https://api.morning.co', 'api' => 'https://api.greeninvoice.co.il/api/v1'],
    ];
    private const GROUP = ['card' => 100, 'paypal' => 110, 'bit' => 120, 'google' => 150, 'apple' => 160];
    private const BRAND = [1 => 'isracard', 2 => 'visa', 3 => 'mastercard', 4 => 'amex', 5 => 'diners'];

    public function __construct(private array $pa, private array $cred) {}

    public function id(): string { return $this->pa['id']; }

    public function capabilities(): array { return ['zero_amount' => false, 'refund_api' => false, 'documents' => true]; }

    /** כרטיס — לכל דבר שיש בו סכום (חיוב חוזר מהטוקן); ביט ו-Apple/Google — חד-פעמי. */
    public function methods(): array
    {
        $on = json_obj($this->pa['settings'])['methods'] ?? ['card', 'bit'];
        $all = ['card' => ['payment', 'subscription', 'topup', 'invoice'], 'bit' => ['payment', 'topup', 'invoice'],
            'apple' => ['payment', 'topup', 'invoice'], 'google' => ['payment', 'topup', 'invoice']];
        return array_intersect_key($all, array_flip($on));
    }

    // ---------- תקשורת ----------

    private function host(string $kind): string
    {
        return self::HOSTS[$this->pa['env'] === 'live' ? 'live' : 'test'][$kind];
    }

    /** ‏accessToken בתוקף (שעה), במטמון מוצפן. */
    private function token(bool $fresh = false): string
    {
        $key = 'morning_token:' . $this->pa['id'];
        $c = setting($key);
        if (!$fresh && $c) {
            $j = json_decode(unseal($c), true);
            if (is_array($j) && (int)$j['exp'] - 120 > now()) return (string)$j['token'];
        }
        $res = http_request($this->host('idp') . '/idp/v1/oauth/token', json_encode([
            'grant_type' => 'client_credentials', 'client_id' => (string)($this->cred['client_id'] ?? ''), 'client_secret' => (string)($this->cred['client_secret'] ?? ''),
        ]), ['Content-Type: application/json'], 15);
        $j = json_decode($res['body'], true);
        if ($res['status'] !== 200 || empty($j['accessToken'])) {
            $why = is_array($j) ? (string)($j['error_description'] ?? $j['error'] ?? '') : '';
            throw new ApiError('מורנינג דחתה את הקודים' . ($why !== '' ? " ($why)" : '') . ' — בדוק מזהה וסוד, ושיש מנוי עם גישה לממשק', 'provider_auth', 502);
        }
        set_setting($key, seal(json_encode(['token' => $j['accessToken'], 'exp' => (int)($j['expiresAt'] ?? now() + 3000)])));
        return (string)$j['accessToken'];
    }

    /** @return array{0:int,1:array} */
    private function call(string $path, ?array $body = null, string $method = 'POST'): array
    {
        $send = function (string $tok) use ($path, $body, $method) {
            $hdr = ['Content-Type: application/json', 'Accept: application/json', "Authorization: Bearer $tok"];
            $payload = $method === 'GET' ? null : json_encode($body ?? (object)[], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return http_request($this->host('api') . $path, $payload, $hdr, 30);
        };
        $res = $send($this->token());
        if ($res['status'] === 401) $res = $send($this->token(true)); // הטוקן פג באמצע
        $j = json_decode($res['body'], true);
        return [$res['status'], is_array($j) ? $j : []];
    }

    private static function err(array $j, string $fallback): string
    {
        foreach (['errorDescription', 'errorMessage', 'message', 'error'] as $k) if (!empty($j[$k]) && is_string($j[$k])) return $j[$k];
        return $fallback;
    }

    /** בדיקת חיבור (מסך ההגדרות): כניסה + קריאה קלה. */
    public function check(): void
    {
        $this->token(true);
        [$st, $j] = $this->call('/payments/tokens/search', ['page' => 1, 'pageSize' => 1]);
        if ($st >= 400) throw new ApiError('החיבור נכשל: ' . self::err($j, "קוד $st"), 'provider_error', 502);
    }

    // ---------- פרטי המסמך ----------

    private function docType(array $merchant): int
    {
        return $merchant['tax_status'] === 'exempt' ? 400 : 320;
    }

    /** הלקוח שלנו אצל מורנינג — נוצר בפעם הראשונה, ואז נשמר (הטוקנים והמסמכים קשורים אליו). */
    private function clientRef(array $acc): string
    {
        $row = db_one('SELECT ref FROM provider_customer WHERE account = ? AND provider_account = ?', [$acc['id'], $this->pa['id']]);
        if ($row) return $row['ref'];
        $body = array_filter([
            'name' => $acc['name'] !== '' ? $acc['name'] : ($acc['email'] !== '' ? $acc['email'] : 'לקוח ' . substr($acc['id'], -6)),
            'taxId' => $acc['tax_id'] ?: null, 'emails' => $acc['email'] !== '' ? [$acc['email']] : null,
            'phone' => $acc['phone'] ?: null, 'remarks' => 'נוצר מתוסף התשלומים · ' . $acc['id'],
        ], fn($v) => $v !== null);
        [$st, $j] = $this->call('/clients', $body);
        if ($st >= 300 || empty($j['id'])) throw new ApiError('יצירת הלקוח במורנינג נכשלה: ' . self::err($j, "קוד $st"), 'provider_error', 502);
        db_run('INSERT OR IGNORE INTO provider_customer (account, provider_account, ref, created_at) VALUES (?, ?, ?, ?)', [$acc['id'], $this->pa['id'], $j['id'], now()]);
        return (string)$j['id'];
    }

    /** שורות המסמך מהשורות שלנו (אגורות → ₪). מחיר כולל מע"מ → vatType 1; לא כולל → 0. */
    private static function income(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $qty = max(1, (float)$l['quantity']);
            $whole = abs($qty - round($qty)) < 1e-9 && $qty <= 10_000;
            $out[] = [
                'description' => mb_substr((string)$l['description'], 0, 200),
                'quantity' => $whole ? (int)$qty : 1,
                'price' => round(((int)$l['amount'] / 100) / ($whole ? (int)$qty : 1), 2),
                'currency' => 'ILS',
                'vatType' => ($l['tax_behavior'] ?? 'inclusive') === 'exclusive' ? 0 : 1,
            ];
        }
        return $out;
    }

    /** אסמכתא קצרה בתיאור המסמך — כך מוצאים את המסמך אחר כך (חיפוש לפי תיאור). */
    private static function ref(string $id): string
    {
        return 'אסמכתא ' . substr($id, -10);
    }

    // ---------- הממשק ----------

    public function createSession(array $checkout, int $amount, bool $saveToken, array $methods, string $returnUrl): array
    {
        if ($amount <= 0) throw new ApiError('מורנינג לא שומרת כרטיס בלי חיוב', 'zero_amount', 409);
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$checkout['account']]);
        $m = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
        $client = db_one('SELECT name FROM client WHERE id = ?', [$checkout['client']])['name'];
        $lines = checkout_lines($checkout);
        $method = $methods[0] ?? 'card';
        $body = [
            'description' => mb_substr($client . ' · ' . self::ref($checkout['id']), 0, 200),
            'type' => $this->docType($m),
            'amount' => round($amount / 100, 2),
            'currency' => 'ILS',
            'vatType' => 0,
            'lang' => 'he',
            'maxPayments' => 1,
            'group' => self::GROUP[$method] ?? 100,
            'client' => ['id' => $this->clientRef($acc)],
            'income' => self::income($lines),
            'successUrl' => $returnUrl,
            'failureUrl' => base_url() . 'pay/?c=' . $checkout['id'] . '&failed=1',
            'notifyUrl' => base_url() . 'hooks/morning.php?pa=' . $this->pa['id'] . '&c=' . $checkout['id'],
            'custom' => $checkout['id'],
        ];
        $plugin = json_obj($this->pa['settings'])['plugin_id'] ?? '';
        if ($plugin !== '') $body['pluginId'] = $plugin;
        [$st, $j] = $this->call('/payments/form', $body);
        if ($st >= 300 || empty($j['url']) || (isset($j['success']) && !$j['success'])) {
            throw new ApiError('יצירת דף התשלום במורנינג נכשלה: ' . self::err($j, "קוד $st"), 'provider_error', 502);
        }
        return [(string)$j['url'], 'mf:' . $checkout['id']];
    }

    /** מחפש את המסמך שמורנינג הפיקה לאסמכתא, בסכום המדויק. */
    private function findDoc(string $id, int $amount, int $since): ?array
    {
        [$st, $j] = $this->call('/documents/search', [
            'page' => 1, 'pageSize' => 20, 'description' => self::ref($id), 'type' => [320, 400],
            'fromDate' => gmdate('Y-m-d', $since - 86400),
        ]);
        if ($st >= 300) return null;
        foreach ($j['items'] ?? [] as $d) {
            if (!str_contains((string)($d['description'] ?? ''), self::ref($id))) continue;
            if ((int)round((float)$d['amount'] * 100) !== $amount) continue;
            return $d;
        }
        return null;
    }

    /** הכרטיס שנשמר אצל מורנינג ללקוח — האחרון התקף שנוצר מאז הקנייה. */
    private function latestToken(array $acc, int $since): ?array
    {
        $row = db_one('SELECT ref FROM provider_customer WHERE account = ? AND provider_account = ?', [$acc['id'], $this->pa['id']]);
        if (!$row) return null;
        [$st, $j] = $this->call('/payments/tokens/search', ['page' => 1, 'pageSize' => 25, 'externalKey' => $row['ref']]);
        if ($st >= 300) return null;
        $best = null;
        foreach ($j['items'] ?? [] as $t) {
            if (!in_array((int)($t['status'] ?? 0), [1, 2], true) || (int)($t['creationDate'] ?? 0) < $since - 600) continue;
            if (!$best || (int)$t['creationDate'] > (int)$best['creationDate']) $best = $t;
        }
        return $best;
    }

    private function docTxn(array $d, int $amount, ?array $tok, string $method): Txn
    {
        $exp = (string)($tok['expires'] ?? '');
        return new Txn('doc:' . $d['id'], 'succeeded', $amount, 'ILS', $tok['id'] ?? null, $tok ? 'card' : $method,
            self::BRAND[(int)($tok['cardType'] ?? 0)] ?? '', (string)($tok['number'] ?? ''), strlen($exp) === 4 ? substr($exp, 0, 2) . '/' . substr($exp, 2) : '',
            '', '', ['id' => (string)$d['id'], 'type' => (int)$d['type'], 'number' => (string)$d['number'], 'url' => '', 'allocation' => (string)($d['allocationNumber'] ?? '')]);
    }

    public function query(string $ref): ?Txn
    {
        if (!str_starts_with($ref, 'mf:')) return null;
        $c = db_one('SELECT * FROM checkout WHERE id = ?', [substr($ref, 3)]);
        if (!$c) return null;
        $d = $this->findDoc($c['id'], (int)$c['amount'], (int)$c['created_at']);
        if (!$d) return null;
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$c['account']]);
        $tok = $this->latestToken($acc, (int)$c['created_at']); // ביט / Apple Pay — אין טוקן, ‏null
        return $this->docTxn($d, (int)$c['amount'], $tok, 'card');
    }

    public function chargeToken(string $token, int $amount, string $idem, array $ctx = []): Txn
    {
        $inv = isset($ctx['invoice']) ? db_one('SELECT * FROM invoice WHERE id = ?', [$ctx['invoice']]) : null;
        if (!$inv) throw new ApiError('חיוב במורנינג — חסרה חשבונית', 'bad_ctx', 500);
        // אין מפתח ייחודיות במורנינג: לפני כל ניסיון שאינו הראשון — אולי ניסיון קודם כבר עבר (ניתוק באמצע)
        $prev = $this->findDoc($inv['id'], $amount, (int)$inv['created_at']);
        if ($prev) return $this->docTxn($prev, $amount, null, 'card');
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$inv['account']]);
        $m = db_one('SELECT * FROM merchant WHERE id = ?', [$inv['merchant']]);
        $client = db_one('SELECT c.name FROM invoice_line l JOIN client c ON c.id = l.client WHERE l.invoice = ? LIMIT 1', [$inv['id']])['name'] ?? '';
        $lines = invoice_lines_for_provider($inv['id']);
        [$st, $j] = $this->call('/payments/tokens/' . rawurlencode($token) . '/charge', [
            'description' => mb_substr($client . ' · ' . self::ref($inv['id']), 0, 200),
            'type' => $this->docType($m), 'amount' => round($amount / 100, 2), 'currency' => 'ILS', 'vatType' => 0, 'lang' => 'he',
            'maxPayments' => 1, 'income' => self::income($lines),
        ]);
        if ($st >= 300 || empty($j['id'])) {
            return new Txn('', 'failed', $amount, 'ILS', null, 'card', '', '', '', 'card_declined', self::err($j, 'החיוב לא עבר'));
        }
        return new Txn('doc:' . $j['id'], 'succeeded', $amount, 'ILS', null, 'card', '', '', '', '', '',
            ['id' => (string)$j['id'], 'type' => (int)$j['type'], 'number' => (string)$j['number'], 'url' => (string)($j['url']['he'] ?? $j['url']['origin'] ?? ''), 'allocation' => '']);
    }

    public function refund(string $txnRef, int $amount, string $idem): string
    {
        throw new ApiError('במורנינג ההחזר נעשה בממשק שלהם (שם גם נוצרת חשבונית הזיכוי). אחרי שביצעת — "רישום החזר שבוצע ידנית".', 'manual_refund', 409);
    }

    // ---------- מסמכים (שלב 5) — לתשלומים שלא עברו בסליקה של מורנינג ----------

    /**
     * מסמך חשבונאי: ‏POST /documents. ‏$payments — שורות תשלום ‏{type, price, date, …} (קודי מורנינג).
     * ‏$linked — מסמך מקורי (לזיכוי: ‏linkType=cancel). מחזיר ‏{id, type, number, url}.
     */
    public function issueDocument(array $acc, int $type, array $lines, array $payments, array $linked = [], string $remarks = ''): array
    {
        $body = [
            'description' => mb_substr((string)($lines[0]['description'] ?? 'תשלום'), 0, 200), 'type' => $type, 'lang' => 'he', 'currency' => 'ILS', 'vatType' => 0,
            'client' => ['id' => $this->clientRef($acc)], 'income' => self::income($lines), 'signed' => true, 'attachment' => true,
        ];
        if ($payments) $body['payment'] = $payments;
        if ($linked) { $body['linkedDocumentIds'] = array_values($linked); $body['linkType'] = 'cancel'; }
        if ($remarks !== '') $body['remarks'] = mb_substr($remarks, 0, 500);
        [$st, $j] = $this->call('/documents', $body);
        if ($st >= 300 || empty($j['id'])) throw new ApiError('הפקת המסמך במורנינג נכשלה: ' . self::err($j, "קוד $st"), 'provider_error', 502);
        return ['id' => (string)$j['id'], 'type' => (int)($j['type'] ?? $type), 'number' => (string)($j['number'] ?? ''), 'url' => (string)($j['url']['he'] ?? $j['url']['origin'] ?? '')];
    }

    // ---------- הודעות נכנסות (webhooks) ----------

    /** אימות הודעה חתומה: ‏HMAC-SHA256 על הגוף הגולמי בסוד שהוגדר במורנינג, ‏hex. */
    public function verifyWebhook(string $raw, string $signature): bool
    {
        $secret = (string)($this->cred['webhook_secret'] ?? '');
        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($signature));
    }
}

/** שורות הקנייה (לפני שיש חשבונית) — כמו שיופיעו בחשבונית. */
function checkout_lines(array $c): array
{
    if (in_array($c['mode'], ['topup'], true)) return [['description' => 'טעינת יתרה', 'quantity' => 1, 'amount' => (int)$c['amount'], 'tax_behavior' => 'inclusive']];
    if ($c['mode'] === 'invoice') {
        return invoice_lines_for_provider((string)$c['invoice']);
    }
    $out = [];
    foreach (json_decode($c['items'], true) ?: [] as $it) {
        $pr = db_one('SELECT pr.*, p.name FROM price pr JOIN product p ON p.id = pr.product WHERE pr.id = ?', [$it['price']]);
        $qty = (int)$it['quantity'];
        $amount = $c['mode'] === 'subscription' ? item_amount($pr, $qty, 0) : (int)$pr['amount'] * $qty;
        $out[] = ['description' => $pr['name'], 'quantity' => $qty, 'amount' => $amount, 'tax_behavior' => $pr['tax_behavior']];
    }
    return $out;
}

function invoice_lines_for_provider(string $invoiceId): array
{
    return db_all("SELECT l.description, l.quantity, l.amount, COALESCE(pr.tax_behavior, 'inclusive') AS tax_behavior
        FROM invoice_line l LEFT JOIN price pr ON pr.id = l.price WHERE l.invoice = ?", [$invoiceId]);
}
