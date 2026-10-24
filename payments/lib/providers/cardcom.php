<?php
/**
 * קארדקום — סליקה, טוקן, החזר, ומסמך חשבונאי (אם במסוף יש מודול מסמכים). לפי התיעוד הרשמי:
 * ‏secure.cardcom.solutions/swagger/v11/swagger.json (‏CardCom API 11.0).
 *
 *  - כניסה: מספר מסוף + ‏ApiName בכל בקשה; ‏ApiPassword — רק להחזר (‏RefundByTransactionId).
 *  - דף תשלום: ‏POST LowProfile/Create → ‏{LowProfileId, Url, UrlToBit}. ‏ChargeAndCreateToken (תשלום + שמירת כרטיס),
 *    ‏ChargeOnly (חד-פעמי), ‏CreateTokenOnly (שמירת כרטיס בלי חיוב — "עדכון אמצעי" וניסיון חינם).
 *  - אישור: לא סומכים על החזרה ולא על ה-WebHook (לא חתום) — שואלים: ‏POST LowProfile/GetLpResult, ובודקים סכום.
 *  - חיוב יזום: ‏POST Transactions/Transaction עם ‏Token ותוקף. ‏ExternalUniqTranId = מפתח הניסיון, ו-
 *    ‏ExternalUniqUniqTranIdResponse — אותו מפתח פעמיים מחזיר את העסקה הראשונה במקום לחייב שוב.
 *  - החזר: ‏POST Transactions/RefundByTransactionId (‏PartialSum, ‏AllowMultipleRefunds).
 *  - הסכומים בשקלים עשרוניים; אצלנו — אגורות.
 */

declare(strict_types=1);

final class CardcomProvider implements Provider
{
    private const API = 'https://secure.cardcom.solutions/api/v11/';
    private const BRAND = ['Visa' => 'visa', 'MasterCard' => 'mastercard', 'Isracard' => 'isracard', 'AmericanExpress' => 'amex', 'Diners' => 'diners',
        'Maestro' => 'maestro', 'Discover' => 'discover', 'JBC' => 'jcb', 'PrivateCard' => 'private'];
    /** קודי "עוד לא שולם" של GetLpResult (הדף פתוח / ניסיון שנכשל והלקוח עוד יכול לנסות). */
    private const PENDING = [5117, 5118, 5119];

    public function __construct(private array $pa, private array $cred) {}

    public function id(): string { return $this->pa['id']; }

    /** מסמך — רק כשהוגדר שקארדקום מפיקה (במסוף יש מודול מסמכים); אחרת המסמך יוצא דרך מורנינג, אם מחוברת. */
    public function capabilities(): array
    {
        return ['zero_amount' => true, 'refund_api' => ($this->cred['api_password'] ?? '') !== '', 'documents' => $this->documents()];
    }

    private function documents(): bool
    {
        return (json_obj($this->pa['settings'])['documents'] ?? 'cardcom') === 'cardcom';
    }

    public function methods(): array
    {
        $on = json_obj($this->pa['settings'])['methods'] ?? ['card', 'bit'];
        // כרטיס: כל שימוש (כולל שמירה בלי חיוב). ביט, אפל וגוגל — בדף של קארדקום, חד-פעמי
        $all = ['card' => ['payment', 'subscription', 'topup', 'setup', 'invoice'], 'bit' => ['payment', 'topup', 'invoice'],
            'apple' => ['payment', 'topup', 'invoice'], 'google' => ['payment', 'topup', 'invoice']];
        return array_intersect_key($all, array_flip($on));
    }

    // ---------- תקשורת ----------

    /** @return array{0:int,1:array} — קוד HTTP, והתשובה */
    private function call(string $path, array $body, bool $withPassword = false): array
    {
        $auth = $withPassword
            ? ['ApiName' => (string)($this->cred['api_name'] ?? ''), 'ApiPassword' => (string)($this->cred['api_password'] ?? '')]
            : ['TerminalNumber' => (int)($this->cred['terminal'] ?? 0), 'ApiName' => (string)($this->cred['api_name'] ?? '')];
        $res = http_request(self::API . $path, json_encode($auth + $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json', 'Accept: application/json'], 30);
        $j = json_decode($res['body'], true);
        return [$res['status'], is_array($j) ? $j : []];
    }

    private static function err(array $j, string $fallback): string
    {
        $d = trim((string)($j['Description'] ?? ''));
        return $d !== '' ? $d . (isset($j['ResponseCode']) ? ' (' . (int)$j['ResponseCode'] . ')' : '') : $fallback;
    }

    private static function nis(int $agorot): float { return round($agorot / 100, 2); }
    private static function agorot(mixed $nis): int { return (int)round((float)$nis * 100); }

    /** בדיקת חיבור: שאלה על דף שלא קיים. שם משתמש שגוי → 603; כל תשובה אחרת — הקודים התקבלו. */
    public function check(): void
    {
        [$st, $j] = $this->call('LowProfile/GetLpResult', ['LowProfileId' => '00000000-0000-0000-0000-000000000000']);
        if ($st === 0 || $st >= 500 || !isset($j['ResponseCode'])) throw new ApiError('קארדקום לא ענתה (קוד ' . $st . ')', 'provider_error', 502);
        if (in_array((int)$j['ResponseCode'], [601, 602, 603, 604], true)) {
            throw new ApiError('קארדקום דחתה את הקודים: ' . self::err($j, '') . ' — בדוק מספר מסוף ושם משתמש לממשק', 'provider_auth', 502);
        }
    }

    // ---------- המסמך ----------

    /** מסמך לדף / לחיוב: מי, מייל, ושורות. סכום השורות = סכום העסקה (המחירים כוללים מע"מ, כמו אצלנו). */
    private function document(array $acc, array $lines, int $amount): array
    {
        $products = [];
        $sum = 0;
        foreach ($lines as $l) {
            $qty = max(1, (float)$l['quantity']);
            $whole = abs($qty - round($qty)) < 1e-9 && $qty <= 10_000;
            $total = (int)$l['amount'];
            $products[] = ['Description' => mb_substr((string)$l['description'], 0, 200), 'Quantity' => $whole ? (int)$qty : 1,
                'UnitCost' => round($total / 100 / ($whole ? (int)$qty : 1), 2), 'TotalLineCost' => self::nis($total)];
            $sum += $total;
        }
        // מחיר "לא כולל מע"מ" — הסכום אצלנו כבר כולל; שורה אחת בסכום המלא, כדי שהמסמך יתאים לחיוב
        if ($sum !== $amount) $products = [['Description' => mb_substr((string)($lines[0]['description'] ?? 'תשלום'), 0, 200), 'Quantity' => 1,
            'UnitCost' => self::nis($amount), 'TotalLineCost' => self::nis($amount)]];
        $m = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
        return array_filter([
            'Name' => $acc['name'] !== '' ? mb_substr($acc['name'], 0, 120) : ($acc['email'] !== '' ? $acc['email'] : 'לקוח'),
            'TaxId' => $acc['tax_id'] ?: null, 'Email' => $acc['email'] ?: null, 'IsSendByEmail' => $acc['email'] !== '',
            'Mobile' => $acc['phone'] ?: null, 'Language' => 'he', 'IsVatFree' => $m['tax_status'] === 'exempt' ? true : null,
            'Products' => $products,
            'AdvancedDefinition' => ['SiteUniqueId' => $acc['id']],
        ], fn($v) => $v !== null);
    }

    private function docFrom(array $info, string $lpOrTxn): ?array
    {
        $num = (int)($info['DocumentNumber'] ?? 0);
        if (!$num) return null;
        $type = (string)($info['DocumentType'] ?? '');
        // הסוגים אצלנו כמו במורנינג: 320 חשבונית מס/קבלה, 400 קבלה
        $code = str_starts_with($type, 'Receipt') ? 400 : 320;
        return ['id' => 'cc:' . $type . ':' . $num, 'type' => $code, 'number' => (string)$num, 'url' => (string)($info['DocumentUrl'] ?? ''), 'allocation' => ''];
    }

    // ---------- הממשק ----------

    public function createSession(array $checkout, int $amount, bool $saveToken, array $methods, string $returnUrl): array
    {
        $acc = db_one('SELECT * FROM account WHERE id = ?', [$checkout['account']]);
        $client = db_one('SELECT name FROM client WHERE id = ?', [$checkout['client']])['name'];
        $op = $amount === 0 ? 'CreateTokenOnly' : ($saveToken ? 'ChargeAndCreateToken' : 'ChargeOnly');
        $body = [
            'Operation' => $op,
            'ReturnValue' => $checkout['id'],
            'Amount' => $amount === 0 ? 1 : self::nis($amount), // ‏CreateTokenOnly: בדיקת כרטיס (J2) בלי חיוב
            'SuccessRedirectUrl' => $returnUrl,
            'FailedRedirectUrl' => base_url() . 'pay/?c=' . $checkout['id'] . '&failed=1',
            'CancelRedirectUrl' => (string)$checkout['cancel_url'],
            'WebHookUrl' => base_url() . 'hooks/cardcom.php?pa=' . $this->pa['id'],
            'ProductName' => mb_substr($client, 0, 100),
            'Language' => 'he',
            'ISOCoinId' => 1,
            'UIDefinition' => array_filter(['CardOwnerNameValue' => $acc['name'] ?: null, 'CardOwnerEmailValue' => $acc['email'] ?: null,
                'CardOwnerPhoneValue' => $acc['phone'] ?: null, 'CardOwnerIdValue' => $acc['tax_id'] ?: null]),
            'AdvancedDefinition' => $amount === 0 ? ['JValidateType' => 2] : ['MaxNumOfPayments' => 1],
        ];
        if ($amount > 0 && $this->documents()) $body['Document'] = $this->document($acc, checkout_lines($checkout), $amount);
        if (!$body['UIDefinition']) unset($body['UIDefinition']);
        [$st, $j] = $this->call('LowProfile/Create', $body);
        if ((int)($j['ResponseCode'] ?? -1) !== 0 || empty($j['LowProfileId'])) {
            throw new ApiError('יצירת דף התשלום בקארדקום נכשלה: ' . self::err($j, "קוד $st"), 'provider_error', 502);
        }
        $url = ($methods[0] ?? 'card') === 'bit' && !empty($j['UrlToBit']) ? (string)$j['UrlToBit'] : (string)$j['Url'];
        return [$url, 'lp:' . $j['LowProfileId']];
    }

    public function query(string $ref): ?Txn
    {
        if (!str_starts_with($ref, 'lp:')) return null;
        [, $j] = $this->call('LowProfile/GetLpResult', ['LowProfileId' => substr($ref, 3)]);
        $code = (int)($j['ResponseCode'] ?? -1);
        if ($code !== 0) {
            if (in_array($code, self::PENDING, true)) return null;
            if ($code === -1) return null; // לא ענו — ננסה שוב
            return new Txn($ref, 'failed', 0, 'ILS', null, 'card', '', '', '', 'provider_' . $code, self::err($j, 'התשלום לא עבר'));
        }
        $tok = $j['TokenInfo'] ?? null;
        $t = $j['TranzactionInfo'] ?? null;
        if ($t && (int)($t['ResponseCode'] ?? -1) !== 0) {
            return new Txn('txn:' . (int)($t['TranzactionId'] ?? 0), 'failed', self::agorot($t['Amount'] ?? 0), 'ILS', null, 'card', '', '', '',
                'card_declined', self::err($t, 'הכרטיס נדחה'));
        }
        if (!$t && !$tok) return null;
        $month = (int)($t['CardMonth'] ?? $tok['CardMonth'] ?? 0);
        $year = (int)($t['CardYear'] ?? $tok['CardYear'] ?? 0);
        $exp = $month && $year ? sprintf('%02d/%02d', $month, $year % 100) : '';
        $amount = $t ? self::agorot($t['Amount'] ?? 0) : 0; // ‏CreateTokenOnly — רק שמירה
        $method = str_contains(strtolower((string)($j['ExternalPaymentVector'] ?? '')), 'bit') ? 'bit' : 'card';
        $doc = $t ? $this->docFrom($t, $ref) : null;
        if (!$doc && !empty($j['DocumentInfo']) && (int)($j['DocumentInfo']['ResponseCode'] ?? -1) === 0) $doc = $this->docFrom($j['DocumentInfo'], $ref);
        // הטוקן לשימוש חוזר: "טוקן ותוקף" — קארדקום דורשת את התוקף בכל חיוב
        $token = $tok && !empty($tok['Token']) ? json_encode(['t' => (string)$tok['Token'], 'e' => $month && $year ? sprintf('%02d%02d', $month, $year % 100) : '']) : null;
        return new Txn($t ? 'txn:' . (int)$t['TranzactionId'] : $ref, 'succeeded', $amount, 'ILS', $token, $method,
            self::BRAND[(string)($t['Brand'] ?? '')] ?? '', (string)($t['Last4CardDigitsString'] ?? ''), $exp, '', '', $doc);
    }

    public function chargeToken(string $token, int $amount, string $idem, array $ctx = []): Txn
    {
        $tk = json_decode($token, true);
        if (!is_array($tk) || empty($tk['t'])) return new Txn('', 'failed', $amount, 'ILS', null, 'card', '', '', '', 'bad_token', 'אמצעי התשלום השמור לא תקין');
        $body = [
            'Amount' => self::nis($amount), 'Token' => (string)$tk['t'], 'CardExpirationMMYY' => (string)($tk['e'] ?? ''),
            'ExternalUniqTranId' => mb_substr($idem, 0, 50), 'ExternalUniqUniqTranIdResponse' => true, 'NumOfPayments' => 1, 'ISOCoinId' => 1,
        ];
        $inv = isset($ctx['invoice']) ? db_one('SELECT * FROM invoice WHERE id = ?', [$ctx['invoice']]) : null;
        if ($inv && $this->documents()) {
            $acc = db_one('SELECT * FROM account WHERE id = ?', [$inv['account']]);
            $body['Document'] = $this->document($acc, invoice_lines_for_provider($inv['id']), $amount);
        }
        [$st, $j] = $this->call('Transactions/Transaction', $body);
        $code = (int)($j['ResponseCode'] ?? -1);
        if ($code !== 0) {
            return new Txn((string)($j['TranzactionId'] ?? ''), 'failed', $amount, 'ILS', null, 'card', '', '', '',
                $code === -1 ? 'provider_unavailable' : 'card_declined', self::err($j, "החיוב לא עבר (קוד $st)"));
        }
        if (self::agorot($j['Amount'] ?? 0) !== $amount) {
            // ‏ExternalUniqTranId שכבר שימש לסכום אחר — לא נסגור על סכום שלא ביקשנו
            return new Txn('txn:' . (int)$j['TranzactionId'], 'failed', $amount, 'ILS', null, 'card', '', '', '', 'amount_mismatch', 'הסכום בקארדקום לא תואם');
        }
        return new Txn('txn:' . (int)$j['TranzactionId'], 'succeeded', $amount, 'ILS', null, 'card',
            self::BRAND[(string)($j['Brand'] ?? '')] ?? '', (string)($j['Last4CardDigitsString'] ?? ''), '', '', '', $this->docFrom($j, ''));
    }

    public function refund(string $txnRef, int $amount, string $idem): string
    {
        if (($this->cred['api_password'] ?? '') === '') {
            throw new ApiError('להחזר דרך קארדקום צריך את סיסמת הממשק בחיבור. אפשר גם להחזיר בממשק קארדקום ולרשום כאן.', 'manual_refund', 409);
        }
        if (!str_starts_with($txnRef, 'txn:')) throw new ApiError('אין מזהה עסקה של קארדקום לתשלום הזה', 'not_refundable', 409);
        [$st, $j] = $this->call('Transactions/RefundByTransactionId', [
            'TransactionId' => (int)substr($txnRef, 4), 'PartialSum' => self::nis($amount), 'AllowMultipleRefunds' => true,
            'ExternalRefundDealId' => mb_substr($idem, 0, 50),
        ], true);
        if ((int)($j['ResponseCode'] ?? -1) !== 0) throw new ApiError('ההחזר בקארדקום נכשל: ' . self::err($j, "קוד $st"), 'provider_error', 502);
        return 'txn:' . (int)($j['NewTranzactionId'] ?? 0);
    }

    /** ‏LowProfileId מההודעה הנכנסת (לא חתומה — רק כדי לדעת על איזו קנייה לשאול). */
    public static function hookLowProfileId(string $raw, array $get): string
    {
        $j = json_decode($raw, true);
        $id = is_array($j) ? (string)($j['LowProfileId'] ?? '') : '';
        if ($id === '') { parse_str($raw, $form); $id = (string)($form['LowProfileId'] ?? $form['lowprofilecode'] ?? $get['lowprofilecode'] ?? ''); }
        return preg_match('/^[0-9a-fA-F-]{36}$/', $id) ? strtolower($id) : '';
    }
}
