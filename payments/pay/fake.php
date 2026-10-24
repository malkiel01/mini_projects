<?php
/**
 * הסולק המדומה — "הדף של חברת הסליקה" (פרק 5.4). רק בסביבת בדיקות, ורק כרטיסי בדיקה.
 * במציאות הדף הזה נמצא אצל הסולק; כאן הוא מאפשר לבנות ולבדוק את כל הזרימה בלי הסכם סליקה.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/pages.php';

$ref = (string)($_GET['s'] ?? '');
$s = preg_match('/^fks_[a-z0-9]{20}$/', $ref) ? db_one('SELECT * FROM fake_session WHERE ref = ?', [$ref]) : null;
$c = $s ? db_one('SELECT * FROM checkout WHERE id = ?', [$s['checkout']]) : null;
if (!$s || !$c || $c['env'] !== 'test') page_error('סשן בדיקה לא נמצא.', 'סולק מדומה');
$methods = json_decode($s['methods'], true) ?: [];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $s['status'] === 'open') {
    form_check($ref);
    try {
        $txn = (new FakeProvider())->payFromPage($ref, (string)($_POST['method'] ?? 'card'), (string)($_POST['card'] ?? ($_POST['bit'] ?? '')));
        if ($txn->status === 'succeeded') {
            header('Location: return.php?c=' . rawurlencode($c['id']), true, 303);
            exit;
        }
        $err = $txn->failureMessage;
    } catch (ApiError $e) {
        $err = $e->getMessage();
    }
}
if ($s['status'] !== 'open') {
    header('Location: return.php?c=' . rawurlencode($c['id']), true, 303);
    exit;
}

page_open('סולק מדומה — בדיקות', '🧪 סולק מדומה');
echo '<div class="summary"><div class="line"><span class="muted">סכום</span> <b>' . money_html((int)$s['amount']) . '</b>'
    . ($s['save_token'] ? '<span class="chip">הכרטיס יישמר לחיובים הבאים</span>' : '') . '</div></div>';
if ($err !== '') echo '<p class="error" role="alert">' . h($err) . '</p>';
$t = h(form_token($ref));
if (in_array('card', $methods, true)) {
    echo '<form method="post" style="display:grid;gap:12px"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="method" value="card">'
        . '<label class="f"><span>מספר כרטיס בדיקה</span><input name="card" inputmode="numeric" dir="ltr" autocomplete="off" value="4242 4242 4242 4242"></label>'
        . '<button class="btn primary" type="submit">' . ((int)$s['amount'] ? 'תשלום ' . money_html((int)$s['amount']) : 'שמירת הכרטיס') . '</button></form>';
    echo '<h2 class="sec-title">כרטיסי בדיקה</h2><ul class="list">';
    foreach (FakeProvider::CARDS as $num => [, $brand, $desc]) echo '<li class="card"><div class="body"><div class="l1"><span dir="ltr">' . h(trim(chunk_split((string)$num, 4, ' '))) . '</span></div><div class="l2">' . h($desc) . '</div></div></li>';
    echo '</ul>';
}
if (in_array('bit', $methods, true)) {
    echo '<form method="post" class="row-btns" style="margin-top:12px"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="method" value="bit">'
        . '<button class="btn primary" name="bit" value="ok" type="submit">ביט — אישור</button><button class="btn ghost" name="bit" value="decline" type="submit">ביט — סירוב</button></form>';
}
page_close('דף זה מדמה את חברת הסליקה. כרטיס אמיתי לא יתקבל כאן.');
