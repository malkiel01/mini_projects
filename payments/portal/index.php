<?php
/**
 * "החשבון שלי" (פרק 8.3) — ציבורי, בסשן שנפתח מקישור חד-פעמי (portal_session). מנויים (ביטול, חידוש,
 * אישור חידוש, ביטול תוך 14 יום), יתרה וטעינה, אמצעי תשלום, חשבוניות. ביטול — פשוט כמו ההרשמה (§14ט).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/pages.php';

$cus = portal_customer();
if (!$cus) page_error('הקישור לחשבון פג. חזור לאפליקציה ופתח את "החשבון שלי" שוב.', 'החשבון שלי');
$client = db_one('SELECT * FROM client WHERE id = ?', [$cus['client']]);
$acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
$self = base_url() . 'portal/';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_check($cus['_sid']);
    $do = (string)($_POST['do'] ?? '');
    $sub = (string)($_POST['sub'] ?? '');
    try {
        switch ($do) {
            case 'cancel': subscription_cancel($cus, $sub, 'period_end', 'portal:' . $cus['id']); $msg = 'המנוי בוטל. לא תחויב שוב.'; break;
            case 'resume': subscription_resume($cus, $sub); $msg = 'המנוי ימשיך כרגיל.'; break;
            case 'consent': subscription_consent($cus, $sub); $msg = 'אישרת חידוש לתקופה נוספת. תודה!'; break;
            case 'cooling_off':
                $q = subscription_cooling_off($cus, $sub);
                $msg = 'העסקה בוטלה. יוחזרו ' . money_text($q['refund']) . ' (בניכוי ' . money_text($q['used']) . ' על השימוש ו-' . money_text($q['fee']) . ' דמי ביטול).';
                break;
            case 'topup':
                $ag = (int)round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')) * 100);
                $c = checkout_create($client, $cus['env'], ['external_id' => $cus['external_id'], 'mode' => 'topup', 'amount' => $ag, 'return_url' => $self, 'cancel_url' => $self]);
                header('Location: ' . $c['url'], true, 303);
                exit;
            case 'pay_invoice':
                $c = checkout_create($client, $cus['env'], ['external_id' => $cus['external_id'], 'mode' => 'invoice', 'invoice' => (string)($_POST['inv'] ?? ''), 'return_url' => $self, 'cancel_url' => $self]);
                header('Location: ' . $c['url'], true, 303);
                exit;
            case 'method':
                $c = checkout_create($client, $cus['env'], ['external_id' => $cus['external_id'], 'mode' => 'setup', 'return_url' => $self, 'cancel_url' => $self]);
                header('Location: ' . $c['url'], true, 303);
                exit;
            case 'remove_method':
                db_run("UPDATE payment_method SET status = 'removed' WHERE id = ? AND account = ?", [(string)($_POST['pm'] ?? ''), $acc['id']]);
                db_run('UPDATE account SET default_method = NULL WHERE id = ? AND default_method = ?', [$acc['id'], (string)($_POST['pm'] ?? '')]);
                $msg = 'אמצעי התשלום הוסר.';
                break;
            default: throw new ApiError('פעולה לא מוכרת', 'bad_action');
        }
    } catch (ApiError $e) {
        $err = $e->getMessage();
    }
    $acc = db_one('SELECT * FROM account WHERE id = ?', [$cus['account']]);
}

$t = h(form_token($cus['_sid']));
$btn = fn(string $do, string $label, string $cls = 'ghost', string $sub = '', string $confirm = '') =>
    '<form method="post" style="display:inline"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="do" value="' . h($do) . '">'
    . ($sub !== '' ? '<input type="hidden" name="sub" value="' . h($sub) . '">' : '')
    . '<button class="btn sm ' . $cls . '" type="submit"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . h($label) . '</button></form>';
$STATE = ['active' => ['פעיל', 'ok'], 'trialing' => ['ניסיון', 'ok'], 'past_due' => ['החיוב לא עבר', 'bad'], 'paused' => ['מושהה', 'warn'], 'canceled' => ['הסתיים', '']];

page_open('החשבון שלי', $client['name']);
if ($cus['env'] === 'test') echo '<p class="hint">🧪 סביבת בדיקות</p>';
if ($msg !== '') echo '<p class="summary" role="status">✅ ' . h($msg) . '</p>';
if ($err !== '') echo '<p class="error" role="alert">' . h($err) . '</p>';

// מנויים
echo '<h2 class="sec-title">המנויים שלי</h2><ul class="list">';
$subs = db_all("SELECT * FROM subscription WHERE customer = ? AND (status <> 'canceled' OR canceled_at > ?) ORDER BY created_at DESC", [$cus['id'], now() - 90 * 86400]);
if (!$subs) echo '<li><p class="empty">אין מנויים.</p></li>';
foreach ($subs as $s) {
    $items = sub_items($s['id']);
    $names = implode(' + ', array_map(fn($i) => $i['name'] . ((int)$i['quantity'] > 1 ? ' × ' . $i['quantity'] : ''), $items));
    $amount = array_sum(array_map(fn($i) => item_amount($i, (int)$i['quantity'], (int)$s['period_index'] + ($s['status'] === 'trialing' ? 0 : 1)), $items));
    [$st, $cls] = $STATE[$s['status']] ?? [$s['status'], ''];
    $line = match (true) {
        $s['status'] === 'canceled' => 'הסתיים ב-' . date_text((int)$s['canceled_at']),
        (bool)$s['cancel_at_period_end'] => 'בוטל — פעיל עד ' . date_text((int)$s['current_period_end']) . ', בלי חיוב נוסף',
        $s['status'] === 'trialing' => 'ניסיון עד ' . date_text((int)$s['current_period_end']) . ', ואז ' . money_text($amount),
        default => 'החיוב הבא: ' . money_text($amount) . ' ב-' . date_text((int)$s['current_period_end']),
    };
    echo '<li class="card" style="flex-wrap:wrap"><div class="body"><div class="l1"><span>' . h($names) . '</span></div><div class="l2" style="white-space:normal">' . h($line) . '</div></div>'
        . '<span class="chip ' . $cls . '">' . h($st) . '</span>';
    if ($s['status'] !== 'canceled') {
        echo '<div class="row-btns wrap" style="flex-basis:100%;justify-content:flex-start">';
        $openInv = db_one("SELECT id, total FROM invoice WHERE subscription = ? AND status = 'open' ORDER BY created_at DESC LIMIT 1", [$s['id']]);
        if ($openInv) {
            echo '<form method="post" style="display:inline"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="do" value="pay_invoice">'
                . '<input type="hidden" name="inv" value="' . h($openInv['id']) . '"><button class="btn sm primary" type="submit">תשלום עכשיו ' . money_html((int)$openInv['total']) . '</button></form>';
        }
        if ($s['cancel_at_period_end']) echo $btn('resume', 'המשך המנוי', 'primary', $s['id']);
        else echo $btn('cancel', 'ביטול המנוי', 'ghost', $s['id'], 'לבטל? השירות ימשיך עד סוף התקופה ששולמה.');
        if (sub_needs_consent($s) && (int)($s['renew_consent_until'] ?? 0) < (int)$s['current_period_end'] && !$s['cancel_at_period_end']) {
            echo $btn('consent', 'אישור חידוש לתקופה נוספת', 'primary', $s['id']);
        }
        $q = cooling_off_quote($s);
        if ($q) echo $btn('cooling_off', 'ביטול עסקה (14 יום) — החזר ' . money_text($q['refund']), 'ghost', $s['id'], 'לבטל את העסקה? המנוי ייעצר מיד ויוחזרו ' . money_text($q['refund']) . '.');
        echo '</div>';
    }
    echo '</li>';
}
echo '</ul>';

// יתרה
$bal = wallet_balance($acc['id']);
echo '<h2 class="sec-title">יתרה</h2><div class="summary"><div class="line"><span class="muted">יתרה</span> <b>' . money_html(micros_to_agorot($bal)) . '</b></div>'
    . '<form method="post" class="toolbar" style="margin:0"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="do" value="topup">'
    . '<input name="amount" inputmode="decimal" dir="ltr" placeholder="סכום בשקלים" aria-label="סכום לטעינה" required>'
    . '<button class="btn primary" type="submit">טעינה</button></form></div>';

// אמצעי תשלום
echo '<h2 class="sec-title">אמצעי תשלום</h2><ul class="list">';
$pms = db_all("SELECT * FROM payment_method WHERE account = ? AND status = 'active' ORDER BY created_at DESC", [$acc['id']]);
if (!$pms) echo '<li><p class="empty">אין אמצעי תשלום שמור.</p></li>';
foreach ($pms as $pm) {
    echo '<li class="card"><span class="use-ic" aria-hidden="true">💳</span><div class="body"><div class="l1"><span dir="ltr">•••• ' . h($pm['last4']) . '</span></div>'
        . '<div class="l2">' . h($pm['brand']) . ($pm['exp'] ? ' · תוקף ' . h($pm['exp']) : '') . ($pm['id'] === $acc['default_method'] ? ' · ברירת מחדל' : '') . '</div></div>'
        . '<form method="post"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="do" value="remove_method"><input type="hidden" name="pm" value="' . h($pm['id']) . '">'
        . '<button class="icon-btn" type="submit" aria-label="הסרה" data-confirm="להסיר את הכרטיס?">🗑️</button></form></li>';
}
$merchant = db_one('SELECT * FROM merchant WHERE id = ?', [$acc['merchant']]);
$canSetup = provider_for($merchant, $cus['env'])->capabilities()['zero_amount'];
echo '</ul>' . ($canSetup ? '<div class="row-btns" style="margin-top:8px">' . $btn('method', $pms ? 'החלפת כרטיס' : 'הוספת כרטיס') . '</div>'
    : '<p class="hint">כרטיס חדש נשמר בתשלום הבא שתבצע בו.</p>');

// חשבוניות
echo '<h2 class="sec-title">תשלומים</h2><ul class="list">';
$invs = db_all("SELECT * FROM invoice WHERE account = ? AND status IN ('paid','open','uncollectible') ORDER BY created_at DESC LIMIT 50", [$acc['id']]);
if (!$invs) echo '<li><p class="empty">עוד אין תשלומים.</p></li>';
$IST = ['paid' => ['שולם', 'ok'], 'open' => ['ממתין', 'warn'], 'uncollectible' => ['לא נגבה', 'bad']];
foreach ($invs as $i) {
    $desc = db_one('SELECT description FROM invoice_line WHERE invoice = ? LIMIT 1', [$i['id']])['description'] ?? '';
    $doc = db_one('SELECT type, number FROM tax_document WHERE invoice = ? LIMIT 1', [$i['id']]);
    [$st, $cls] = $IST[$i['status']];
    echo '<li class="card"><div class="body"><div class="l1"><span>' . h($desc) . '</span><span class="t">' . h(date_text((int)$i['created_at'])) . '</span></div>'
        . '<div class="l2">' . money_html((int)$i['total']) . ((int)$i['vat'] ? ' · מע"מ ' . money_html((int)$i['vat']) : '')
        . ($doc ? ' · ' . ((int)$doc['type'] === 400 ? 'קבלה' : 'חשבונית מס/קבלה') . ' ' . h($doc['number']) : '') . '</div></div><span class="chip ' . $cls . '">' . h($st) . '</span></li>';
}
echo '</ul><p class="hint">הקבלות הרשמיות נשלחות במייל.</p>';
echo '<div class="row-btns" style="margin-top:12px"><a class="btn ghost" href="' . h($cus['_return']) . '">חזרה ל' . h($client['name']) . '</a></div>';
page_close(h(cancel_line($acc)));
tick_after_response('portal');
