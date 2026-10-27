<?php
/**
 * יחסיות לקנייה בודדת
 * includes/purchase_shares.php
 *
 * ברירת המחדל: כל קנייה מתחלקת לפי מה שהוגדר לכל שותף באירוע
 * (אחוזים, נפשות או סכום קבוע), פחות מי שהוחרג ממנה.
 *
 * כשקנייה מסוימת מתחלקת אחרת - "את היין שילמו רק המבוגרים, חצי-חצי" -
 * נקבעים לה משקלים משלה: מספר לכל שותף, והסכום מתחלק לפי היחס
 * ביניהם. המשקלים אינם חייבים להסתכם ב-100: 1 ו-1 זה חצי-חצי, 60 ו-20
 * זה שלושה רבעים ורבע. משקל 0 - לא משתתף בקנייה.
 *
 * קנייה בלי שורות בטבלה מתחלקת כרגיל. החישוב עצמו -
 * calculateGroupBalance ב-group_calculations.php.
 */

/** האם טבלת היחסיות קיימת (מיגרציה 018) */
function purchaseSharesReady(PDO $pdo) {
    // לכל חיבור בנפרד, והחיבור נשמר לצד התשובה - ראו currencyColumnsReady
    static $ready = [];
    $key = spl_object_id($pdo);

    if (isset($ready[$key]) && $ready[$key][0] === $pdo) {
        return $ready[$key][1];
    }

    try {
        $pdo->query("SELECT purchase_id, member_id, weight FROM purchase_shares WHERE 1 = 0");
        $ok = true;
    } catch (Exception $e) {
        $ok = false;
    }

    $ready[$key] = [$pdo, $ok];

    return $ok;
}

/**
 * היחסיות של כל הקניות באירוע.
 *
 * @return array purchase_id => [member_id => משקל]
 */
function loadPurchaseShares(PDO $pdo, $groupId) {
    if (!purchaseSharesReady($pdo)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT ps.purchase_id, ps.member_id, ps.weight
        FROM purchase_shares ps
        JOIN group_purchases gp ON gp.id = ps.purchase_id
        WHERE gp.group_id = ?
    ");
    $stmt->execute([(int)$groupId]);

    $shares = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $shares[(int)$row['purchase_id']][(int)$row['member_id']] = (float)$row['weight'];
    }

    return $shares;
}

/** מצמיד לכל קנייה את היחסיות שלה (shares), אם נקבעה */
function attachPurchaseShares(array $purchases, array $shares) {
    foreach ($purchases as &$purchase) {
        $purchase['shares'] = $shares[(int)$purchase['id']] ?? [];
    }
    unset($purchase);

    return $purchases;
}

/**
 * היחסיות מהטופס.
 *
 * מגיעה כ-JSON: {"member_id": משקל, ...}. ריק או חסר - ברירת המחדל.
 * רק שותפים פעילים באירוע; משקל שלילי או לא מספרי נדחה; לפחות
 * שותף אחד חייב להשתתף.
 *
 * @param int[] $activeIds השותפים הפעילים באירוע
 * @return array ['ok' => true, 'weights' => [member_id => משקל] | null]
 *               או ['ok' => false, 'message']
 */
function parseSharesInput($raw, array $activeIds) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['ok' => true, 'weights' => null];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'היחסיות שנשלחה אינה תקינה'];
    }

    $active  = array_flip(array_map('intval', $activeIds));
    $weights = [];
    foreach ($decoded as $memberId => $weight) {
        if (!is_numeric($weight) || !is_finite((float)$weight) || (float)$weight < 0 || (float)$weight > 1000000) {
            return ['ok' => false, 'message' => 'כל חלק חייב להיות מספר, אפס ומעלה'];
        }
        if (!isset($active[(int)$memberId])) {
            continue;
        }
        if ((float)$weight > 0) {
            $weights[(int)$memberId] = round((float)$weight, 4);
        }
    }

    if (count($weights) === 0) {
        return ['ok' => false, 'message' => 'לפחות שותף אחד צריך להשתתף בקנייה'];
    }

    return ['ok' => true, 'weights' => $weights];
}

/**
 * שומר את היחסיות של קנייה. null - חזרה לברירת המחדל.
 */
function savePurchaseShares(PDO $pdo, $purchaseId, $weights) {
    if (!purchaseSharesReady($pdo)) {
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM purchase_shares WHERE purchase_id = ?");
    $stmt->execute([(int)$purchaseId]);

    if (!$weights) {
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO purchase_shares (purchase_id, member_id, weight) VALUES (?, ?, ?)");
    foreach ($weights as $memberId => $weight) {
        $stmt->execute([(int)$purchaseId, (int)$memberId, $weight]);
    }
}

/**
 * תיאור קצר של החלוקה, לשורת הקנייה: "דנה 60% · יוסי 40%".
 *
 * @param array $split   member_id => סכום (מ-purchaseSplits)
 * @param array $names   member_id => כינוי
 */
function purchaseSplitLabel(array $split, array $names, $amount) {
    $amount = (float)$amount;
    if ($amount <= 0 || count($split) === 0) {
        return '';
    }

    arsort($split);
    $parts = [];
    foreach ($split as $memberId => $value) {
        $percent = round($value / $amount * 100, 1);
        $parts[] = ($names[$memberId] ?? '?') . ' ' . rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.') . '%';
    }

    return implode(' · ', $parts);
}

/** החלוקה של קנייה באחוזים מסכומה - למילוי טופס העריכה */
function splitPercents(array $split, $amount) {
    $amount = (float)$amount;
    $out    = [];
    foreach ($split as $memberId => $value) {
        $out[(int)$memberId] = $amount > 0 ? round($value / $amount * 100, 2) : 0.0;
    }

    return $out;
}
