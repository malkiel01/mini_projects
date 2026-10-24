<?php
/**
 * סולקים — מחברים מאחורי ממשק אחד (פרק 5). הליבה מנהלת את החיוב; הסולק רק: דף מתארח, טוקן, חיוב, החזר.
 * כללים לכל מחבר: לא סומכים על חזרה מהדפדפן או על הודעה מהסולק — תמיד query() מהשרת, ובודקים סכום.
 *
 * כאן: הממשק, והסולק המדומה (fake) — לבנייה ולבדיקה בלי הסכם סליקה. פועל רק בסביבת בדיקות,
 * ומקבל רק כרטיסי בדיקה; שומר רק 4 ספרות. סולקים אמיתיים — שלב 4 ואילך, כל אחד בקובץ משלו.
 */

declare(strict_types=1);

require_once __DIR__ . '/util.php';

/** תוצאת עסקה אצל הסולק, אחרי query או חיוב. */
final class Txn
{
    public function __construct(
        public readonly string $ref,           // מזהה העסקה אצל הסולק
        public readonly string $status,        // succeeded | failed | pending
        public readonly int $amount,           // אגורות
        public readonly string $currency = 'ILS',
        public readonly ?string $token = null, // לחיוב יזום בהמשך
        public readonly string $method = 'card',
        public readonly string $brand = '',
        public readonly string $last4 = '',
        public readonly string $exp = '',
        public readonly string $failureCode = '',
        public readonly string $failureMessage = '',
    ) {}
}

interface Provider
{
    public function id(): string;

    /** אילו אמצעים הוא יודע ולאיזה שימוש (פרק 6.3). */
    public function methods(): array;

    /** דף תשלום מתארח. מחזיר [url, ref]. ‏$amount = 0 → רק שמירת אמצעי (טוקן). */
    public function createSession(array $checkout, int $amount, bool $saveToken, array $methods, string $returnUrl): array;

    /** מקור האמת: מה מצב הסשן/העסקה. null = עוד לא שולם. */
    public function query(string $ref): ?Txn;

    /** חיוב יזום מטוקן (חידוש, יחידה, טעינה אוטומטית). ‏$idem — אותו מפתח לא מחייב פעמיים. */
    public function chargeToken(string $token, int $amount, string $idem): Txn;

    /** החזר מלא או חלקי. מחזיר מזהה החזר אצל הסולק. */
    public function refund(string $txnRef, int $amount, string $idem): string;
}

function provider(string $id): Provider
{
    return match ($id) {
        'fake' => new FakeProvider(),
        default => throw new ApiError("סולק לא מוכר: $id", 'unknown_provider', 500),
    };
}

/** הסולק לסביבה: בבדיקות — המדומה. בחי — מה שהוגדר לעוסק (שלב 4); עד אז אין סליקה חיה. */
function provider_for(array $merchant, string $env): Provider
{
    if ($env === 'test') return provider('fake');
    throw new ApiError('לעוסק עוד לא חוברה חברת סליקה (שלב 4)', 'no_provider', 409);
}

// ---------------------------------------------------------------

final class FakeProvider implements Provider
{
    /** כרטיסי בדיקה (פרק 5.4). כל מספר אחר נדחה — כדי שאף כרטיס אמיתי לא יוקלד כאן. */
    public const CARDS = [
        '4242424242424242' => ['ok', 'visa', 'עובר תמיד'],
        '4000000000000002' => ['declined', 'visa', 'נדחה'],
        '4000000000000341' => ['decline_later', 'visa', 'עובר עכשיו, נדחה בחיוב הבא'],
        '5555555555554444' => ['ok', 'mastercard', 'עובר תמיד'],
    ];

    public function id(): string { return 'fake'; }

    public function methods(): array
    {
        return ['card' => ['payment', 'subscription', 'topup', 'setup'], 'bit' => ['payment', 'topup']];
    }

    public function createSession(array $checkout, int $amount, bool $saveToken, array $methods, string $returnUrl): array
    {
        $ref = 'fks_' . substr(new_id('x'), 2);
        db_run('INSERT INTO fake_session (ref, checkout, amount, save_token, methods, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$ref, $checkout['id'], $amount, (int)$saveToken, json_encode(array_values($methods)), now()]);
        return [base_url() . 'pay/fake.php?s=' . $ref, $ref];
    }

    public function query(string $ref): ?Txn
    {
        $s = db_one('SELECT * FROM fake_session WHERE ref = ?', [$ref]);
        if (!$s || $s['status'] === 'open' || !$s['txn']) return null;
        return $this->txn((string)$s['txn']);
    }

    private function txn(string $id): Txn
    {
        $t = db_one('SELECT * FROM fake_txn WHERE id = ?', [$id]);
        return new Txn($t['id'], $t['status'], (int)$t['amount'], 'ILS', $t['token'], $t['method'],
            $t['method'] === 'card' ? 'visa' : '', $t['card_last4'], $t['method'] === 'card' ? '12/30' : '',
            $t['status'] === 'failed' ? 'card_declined' : '', $t['status'] === 'failed' ? 'הכרטיס נדחה (בדיקה)' : '');
    }

    /** "הלקוח שילם בדף של הסולק" — נקרא מ-pay/fake.php. */
    public function payFromPage(string $ref, string $method, string $card): Txn
    {
        $s = db_one("SELECT * FROM fake_session WHERE ref = ? AND status = 'open'", [$ref]);
        if (!$s) throw new ApiError('הסשן לא נמצא או נסגר', 'not_found', 404);
        if (!in_array($method, json_decode($s['methods'], true) ?: [], true)) throw new ApiError('אמצעי לא זמין', 'bad_method');
        $behavior = 'ok';
        $last4 = '';
        if ($method === 'card') {
            $card = preg_replace('/\D/', '', $card);
            if (!isset(self::CARDS[$card])) throw new ApiError('בסביבת בדיקות — רק כרטיסי בדיקה', 'not_test_card');
            $behavior = self::CARDS[$card][0];
            $last4 = substr($card, -4);
        } elseif ($card === 'decline') {
            $behavior = 'declined';
        }
        $ok = $behavior !== 'declined';
        $token = null;
        if ($ok && $method === 'card' && $s['save_token']) {
            $token = 'fktok_' . substr(new_id('x'), 2);
            db_run('INSERT INTO fake_token (token, last4, behavior) VALUES (?, ?, ?)', [$token, $last4, $behavior === 'decline_later' ? 'decline' : 'ok']);
        }
        $id = 'fktx_' . substr(new_id('x'), 2);
        db_run('INSERT INTO fake_txn (id, kind, amount, status, token, card_last4, method, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, 'charge', (int)$s['amount'], $ok ? 'succeeded' : 'failed', $token, $last4, $method, now()]);
        // עסקה שנדחתה — הסשן נשאר פתוח לניסיון נוסף (כמו בסולק אמיתי)
        if ($ok) db_run("UPDATE fake_session SET status = 'paid', txn = ? WHERE ref = ?", [$id, $ref]);
        return $this->txn($id);
    }

    public function chargeToken(string $token, int $amount, string $idem): Txn
    {
        $dup = db_one('SELECT id FROM fake_txn WHERE idem = ?', [$idem]);
        if ($dup) return $this->txn($dup['id']);
        $tok = db_one('SELECT * FROM fake_token WHERE token = ?', [$token]);
        $ok = $tok && $tok['behavior'] === 'ok';
        $id = 'fktx_' . substr(new_id('x'), 2);
        db_run('INSERT INTO fake_txn (id, kind, amount, status, token, card_last4, method, idem, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, 'charge', $amount, $ok ? 'succeeded' : 'failed', $token, $tok['last4'] ?? '', 'card', $idem, now()]);
        return $this->txn($id);
    }

    public function refund(string $txnRef, int $amount, string $idem): string
    {
        $dup = db_one('SELECT id FROM fake_txn WHERE idem = ?', [$idem]);
        if ($dup) return $dup['id'];
        $orig = db_one("SELECT * FROM fake_txn WHERE id = ? AND kind = 'charge' AND status = 'succeeded'", [$txnRef]);
        if (!$orig) throw new ApiError('העסקה לא נמצאה אצל הסולק', 'provider_not_found', 409);
        $done = (int)(db_one("SELECT COALESCE(SUM(amount),0) AS s FROM fake_txn WHERE kind = 'refund' AND token = ?", [$txnRef])['s']);
        if ($done + $amount > (int)$orig['amount']) throw new ApiError('ההחזר גדול מהעסקה', 'refund_too_large', 409);
        $id = 'fkrf_' . substr(new_id('x'), 2);
        // ‏token משמש כאן כהפניה לעסקה המקורית
        db_run('INSERT INTO fake_txn (id, kind, amount, status, token, method, idem, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, 'refund', $amount, 'succeeded', $txnRef, $orig['method'], $idem, now()]);
        return $id;
    }
}
