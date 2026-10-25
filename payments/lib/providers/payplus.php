<?php
/**
 * פייפלוס — סליקה, טוקן, החזר, ומסמך (אם לחשבון מחובר ‏Invoice+). לפי התיעוד הרשמי (‏docs.payplus.co.il, ‏REST v1.0),
 * ושמות השדות בתשובות — לפי התוסף הרשמי שלהם לוורדפרס, כי התיעוד לא מפרט את תשובת ה-IPN.
 *
 *  - כניסה: כותרות ‏api-key ו-secret-key (וגם ‏Authorization בצורת JSON — כמו בתוסף שלהם).
 *  - שרתים: בדיקות ‏restapidev.payplus.co.il, חי ‏restapi.payplus.co.il.
 *  - דף תשלום: ‏POST PaymentPages/generateLink → ‏{page_request_uid, payment_page_link}. ‏charge_method 1 — חיוב (J4),
 *    ‏5 — שמירת כרטיס בלי חיוב (J2); ‏create_token — שמירה עם החיוב.
 *  - אישור: לא סומכים על החזרה ולא על ה-callback — שואלים ‏POST PaymentPages/ipn לפי ‏payment_request_uid, ובודקים
 *    ‏status=approved, ‏status_code=000 והסכום.
 *  - חיוב יזום: ‏generateLink עם ‏token (כמו בתוסף שלהם) — מחזיר מיד את תוצאת העסקה. אין מפתח ייחודיות: לפני כל ניסיון
 *    שאינו הראשון שואלים ‏ipn לפי ‏more_info (= המפתח שלנו) — אם כבר עבר, לא מחייבים שוב.
 *  - החזר: ‏POST Transactions/RefundByTransactionUID.
 *  - ה-callback חתום: ‏hash = base64(HMAC-SHA256(גוף, secret-key)), ‏user-agent: PayPlus.
 */

declare(strict_types=1);

final class PayplusProvider implements Provider
{
    private const HOST = ['test' => 'https://restapidev.payplus.co.il/api/v1.0/', 'live' => 'https://restapi.payplus.co.il/api/v1.0/'];
    private const METHOD = ['card' => 'credit-card', 'bit' => 'bit', 'apple' => 'apple-pay', 'google' => 'google-pay'];

    public function __construct(private array $pa, private array $cred) {}

    public function id(): string { return $this->pa['id']; }

    public function capabilities(): array
    {
        return ['zero_amount' => true, 'refund_api' => true, 'documents' => (json_obj($this->pa['settings'])['documents'] ?? 'payplus') === 'payplus'];
    }

    public function methods(): array
    {
        $on = json_obj($this->pa['settings'])['methods'] ?? ['card', 'bit'];
        $all = ['card' => ['payment', 'subscription', 'topup', 'setup', 'invoice'], 'bit' => ['payment', 'topup', 'invoice'],
            'apple' => ['payment', 'topup', 'invoice'], 'google' => ['payment', 'topup', 'invoice']];
        return array_intersect_key($all, array_flip($on));
    }

    // ---------- תקשורת ----------

    /** @return array{0:int,1:array} */
    private function call(string $path, array $body): array
    {
        $key = (string)($this->cred['api_key'] ?? '');
        $secret = (string)($this->cred['secret_key'] ?? '');
        $res = http_request(self::HOST[$this->pa['env'] === 'live' ? 'live' : 'test'] . $path, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
            'Content-Type: application/json', 'Accept: application/json', "api-key: $key", "secret-key: $secret",
            'Authorization: ' . json_encode(['api_key' => $key, 'secret_key' => $secret]),
        ], 30);
        $j = json_decode($res['body'], true);
        return [$res['status'], is_array($j) ? $j : []];
    }

    private static function err(array $j, string $fallback): string
    {
        $d = $j['results']['description'] ?? $j['data']['status_description'] ?? $j['message'] ?? '';
        return is_string($d) && trim($d) !== '' ? trim($d) : $fallback;
    }

    private static function ok(array $j): bool { return ($j['results']['status'] ?? '') === 'success'; }
    private static function nis(int $agorot): float { return round($agorot / 100, 2); }
    private static function agorot(mixed $nis): int { return (int)round((float)$nis * 100); }

    /** בדיקת חיבור: ‏PaymentPages/list — קודים שגויים נדחים, ודף התשלום שהוזן חייב להופיע. */
    public function check(): void
    {
        $res = http_request(self::HOST[$this->pa['env'] === 'live' ? 'live' : 'test'] . 'PaymentPages/list', null, [
            'Accept: application/json', 'api-key: ' . ($this->cred['api_key'] ?? ''), 'secret-key: ' . ($this->cred['secret_key'] ?? ''),
            'Authorization: ' . json_encode(['api_key' => $this->cred['api_key'] ?? '', 'secret_key' => $this->cred['secret_key'] ?? '']),
        ], 20);
        $j = json_decode($res['body'], true) ?: [];
        if ($res['status'] === 401 || $res['status'] === 403 || (isset($j['results']) && !self::ok($j))) {
            throw new ApiError('פייפלוס דחתה את הקודים: ' . self::err($j, 'קוד ' . $res['status']) . ' — בדוק מפתח וסוד', 'provider_auth', 502);
        }
        if ($res['status'] >= 300 || $res['status'] === 0) throw new ApiError('פייפלוס לא ענתה (קוד ' . $res['status'] . ')', 'provider_error', 502);
        $uids = array_map(fn($p) => (string)($p['uid'] ?? $p['payment_page_uid'] ?? ''), is_array($j['data'] ?? null) ? $j['data'] : []);
        if ($uids && !in_array((string)$this->cred['payment_page_uid'], $uids, true)) throw new ApiError('דף התשלום שהוזן לא נמצא בחשבון', 'provider_error', 502);
    }

    // ---------- פרטי הקנייה ----------

    private function customer(array $acc): array
    {
        return array_filter([
            'customer_name' => $acc['name'] !== '' ? mb_substr($acc['name'], 0, 120) : ($acc['email'] !== '' ? $acc['email'] : 'לקוח'),
            'email' => $acc['email'] ?: null, 'vat_number' => $acc['tax_id'] ?: null, 'phone' => $acc['phone'] ?: null,
            'customer_external_number' => $acc['id'],
        ]);
    }

    /** שורות — סכומים כוללים מע"מ (‏vat_type 0), לפטור ‏2. סכום השורות = הסכום. */
    private function items(array $acc, array $lines, int $amount): array
    {
        $m = db_one('SELECT tax_status FROM merchant WHERE id = ?', [$acc['merchant']]);
        $vat = $m['tax_status'] === 'exempt' ? 2 : 0;
        $out = [];
        $sum = 0;
        foreach ($lines as $l) {
            $qty = max(1, (float)$l['quantity']);
            $whole = abs($qty - round($qty)) < 1e-9 && $qty <= 10_000;
            $out[] = ['name' => mb_substr((string)$l['description'], 0, 200), 'quantity' => $whole ? (int)$qty : 1,
                'price' => round((int)$l['amount'] / 100 / ($whole ? (int)$qty : 1), 2), 'vat_type' => $vat];
            $sum += (int)$l['amount'];
        }
        if ($sum !== $amount) $out = [['name' => mb_substr((string)($lines[0]['description'] ?? 'תשלום'), 0, 200), 'quantity' => 1, 'price' => self::nis($amount), 'vat_type' => $vat]];
        return $out;
    }

    // ---------- הממשק ----------

    public function createSession(array $checkout, int $amount, bool $saveToken, array $methods, string $returnUrl): array
    {
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$checkout['account']]);
        $method = $methods[0] ?? 'card';
        $zero = $amount === 0;
        $body = [
            'payment_page_uid' => (string)$this->cred['payment_page_uid'],
            'charge_method' => $zero ? 5 : 1,
            'charge_default' => self::METHOD[$method] ?? 'credit-card',
            'hide_other_charge_methods' => true,
            'language_code' => 'he',
            'amount' => $zero ? 1 : self::nis($amount), // ‏J2 — בדיקת כרטיס בלי חיוב
            'currency_code' => 'ILS',
            'sendEmailApproval' => false, 'sendEmailFailure' => false, // ההודעות — מהתוסף
            'expiry_datetime' => '60',
            'refURL_success' => $returnUrl,
            'refURL_failure' => base_url() . 'pay/?c=' . $checkout['id'] . '&failed=1',
            'refURL_cancel' => (string)$checkout['cancel_url'],
            'refURL_callback' => base_url() . 'hooks/payplus.php?pa=' . $this->pa['id'],
            'create_token' => $zero || ($saveToken && $method === 'card'),
            'payments' => 1,
            'more_info' => $checkout['id'],
            'customer' => $this->customer($acc),
            'initial_invoice' => !$zero && $this->capabilities()['documents'],
        ];
        if (!$zero) $body['items'] = $this->items($acc, checkout_lines($checkout), $amount);
        [$st, $j] = $this->call('PaymentPages/generateLink', $body);
        if (!self::ok($j) || empty($j['data']['payment_page_link']) || empty($j['data']['page_request_uid'])) {
            throw new ApiError('יצירת דף התשלום בפייפלוס נכשלה: ' . self::err($j, "קוד $st"), 'provider_error', 502);
        }
        return [(string)$j['data']['payment_page_link'], ($zero ? 'ppz:' : 'pp:') . $j['data']['page_request_uid']];
    }

    /** תוצאת IPN → ‏Txn. ‏$zero — שמירת כרטיס בלי חיוב (סכום 0 אצלנו). */
    private function txnFrom(array $d, bool $zero): Txn
    {
        $exp = ($d['expiry_month'] ?? '') !== '' ? sprintf('%02d/%02d', (int)$d['expiry_month'], (int)$d['expiry_year'] % 100) : '';
        $method = ($d['method'] ?? 'credit-card') === 'bit' ? 'bit' : 'card';
        $token = !empty($d['token_uid']) ? (string)$d['token_uid'] : null;
        $doc = null;
        $inv = $d['invoice'] ?? null;
        if (is_array($inv) && !empty($inv['docu_number'])) {
            $doc = ['id' => 'pp:' . ($inv['uuid'] ?? $inv['docu_number']), 'type' => 320, 'number' => (string)$inv['docu_number'], 'url' => (string)($inv['original_url'] ?? ''), 'allocation' => ''];
        }
        return new Txn('txn:' . $d['transaction_uid'], 'succeeded', $zero ? 0 : self::agorot($d['amount'] ?? 0), (string)($d['currency'] ?? 'ILS'), $token, $method,
            strtolower((string)($d['brand_name'] ?? '')), (string)($d['four_digits'] ?? ''), $exp, '', '', $doc);
    }

    private static function approved(array $d): bool
    {
        return ($d['status'] ?? '') === 'approved' && ltrim((string)($d['status_code'] ?? ''), '0') === '' && (string)($d['status_code'] ?? '') !== '' && !empty($d['transaction_uid']);
    }

    public function query(string $ref): ?Txn
    {
        if (!preg_match('/^(ppz?):(.+)$/', $ref, $m)) return null;
        [, $j] = $this->call('PaymentPages/ipn', ['payment_request_uid' => $m[2]]);
        $d = $j['data'] ?? null;
        if (!is_array($d) || !self::approved($d)) return null; // עוד לא שולם, או נדחה — הלקוח יכול לנסות שוב בדף
        return $this->txnFrom($d, $m[1] === 'ppz');
    }

    public function chargeToken(string $token, int $amount, string $idem, array $ctx = []): Txn
    {
        // אין מפתח ייחודיות בפייפלוס: אם ניסיון קודם עם אותו מפתח כבר עבר (ניתוק באמצע) — לא מחייבים שוב
        [, $prev] = $this->call('PaymentPages/ipn', ['more_info' => $idem]);
        $p = $prev['data'] ?? null;
        if (is_array($p) && self::approved($p) && ($p['more_info'] ?? $idem) === $idem) {
            return self::agorot($p['amount'] ?? 0) === $amount ? $this->txnFrom($p, false)
                : new Txn('txn:' . $p['transaction_uid'], 'failed', $amount, 'ILS', null, 'card', '', '', '', 'amount_mismatch', 'הסכום בפייפלוס לא תואם');
        }
        $inv = isset($ctx['invoice']) ? db_one('SELECT * FROM invoice WHERE id = ?', [$ctx['invoice']]) : null;
        $acc = $inv ? db_one('SELECT * FROM account WHERE id = ?', [$inv['account']]) : null;
        $body = [
            'payment_page_uid' => (string)$this->cred['payment_page_uid'], 'charge_method' => 1, 'amount' => self::nis($amount), 'currency_code' => 'ILS',
            'token' => $token, 'secure3d' => ['activate' => false], 'sendEmailApproval' => false, 'sendEmailFailure' => false,
            'more_info' => $idem, 'payments' => 1, 'initial_invoice' => $inv !== null && $this->capabilities()['documents'],
        ];
        if ($acc) {
            $body['customer'] = $this->customer($acc);
            $body['items'] = $this->items($acc, invoice_lines_for_provider($inv['id']), $amount);
        }
        [$st, $j] = $this->call('PaymentPages/generateLink', $body);
        $d = $j['data'] ?? null;
        if (!is_array($d) || !self::approved($d)) {
            return new Txn((string)($d['transaction_uid'] ?? ''), 'failed', $amount, 'ILS', null, 'card', '', '', '',
                $st === 0 ? 'provider_unavailable' : 'card_declined', self::err($j, 'החיוב לא עבר'));
        }
        if (self::agorot($d['amount'] ?? 0) !== $amount) {
            return new Txn('txn:' . $d['transaction_uid'], 'failed', $amount, 'ILS', null, 'card', '', '', '', 'amount_mismatch', 'הסכום בפייפלוס לא תואם');
        }
        return $this->txnFrom($d, false);
    }

    public function refund(string $txnRef, int $amount, string $idem): string
    {
        if (!str_starts_with($txnRef, 'txn:')) throw new ApiError('אין מזהה עסקה של פייפלוס לתשלום הזה', 'not_refundable', 409);
        [$st, $j] = $this->call('Transactions/RefundByTransactionUID', [
            'transaction_uid' => substr($txnRef, 4), 'amount' => self::nis($amount), 'more_info' => mb_substr('החזר ' . $idem, 0, 100),
            'initial_invoice' => $this->capabilities()['documents'],
        ]);
        $t = $j['data']['transaction'] ?? [];
        if (!self::ok($j) || ltrim((string)($t['status_code'] ?? '1'), '0') !== '') throw new ApiError('ההחזר בפייפלוס נכשל: ' . self::err($j, "קוד $st"), 'provider_error', 502);
        return 'txn:' . (string)($t['uid'] ?? '');
    }

    /** אימות ה-callback: ‏base64(HMAC-SHA256(הגוף הגולמי, secret-key)) בכותרת ‏hash. */
    public function verifyCallback(string $raw, string $hash, string $userAgent): bool
    {
        $secret = (string)($this->cred['secret_key'] ?? '');
        return $secret !== '' && $hash !== '' && $userAgent === 'PayPlus' && hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $hash);
    }
}
