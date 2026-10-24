<?php
/**
 * דף התשלום (פרק 8.2) — ציבורי. ‏?c=<checkout>: סיכום, הגילוי שהחוק מחייב, בחירת אמצעי, אישור תנאים,
 * ואז הפניה לדף של הסולק. שדות הכרטיס אף פעם לא כאן.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/pages.php';

$id = (string)($_GET['c'] ?? '');
$c = preg_match('/^chk_[a-z0-9]{20}$/', $id) ? checkout_load($id) : null;
if (!$c) page_error('הקישור לתשלום לא תקין או שפג.');
$v = checkout_view($c);
$METHOD = ['card' => '💳 כרטיס אשראי', 'bit' => 'ביט', 'paypal' => 'פייפאל', 'apple' => 'תשלום אפל', 'google' => 'תשלום גוגל', 'none' => '🎁 התחלת הניסיון — בלי כרטיס עכשיו'];
$err = isset($_GET['failed']) ? 'התשלום לא הושלם. אפשר לנסות שוב.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_check($c['id']);
    try {
        if (empty($_POST['agree'])) throw new ApiError('צריך לאשר את התנאים', 'agree');
        $url = checkout_start($c, (string)($_POST['method'] ?? ''));
        header('Location: ' . $url, true, 303);
        exit;
    } catch (ApiError $e) {
        $err = $e->getMessage();
    }
}

if ($c['status'] === 'completed') {
    $back = $c['return_url'] . (str_contains($c['return_url'], '?') ? '&' : '?') . 'checkout=' . rawurlencode($c['id']);
    header('Refresh: 3; url=' . $back);
    page_open('התשלום התקבל', $v['client']);
    echo '<div class="summary"><div class="line"><b>✅ ' . ($c['mode'] === 'setup' ? 'אמצעי התשלום עודכן' : 'שולם') . '</b><span class="pct">' . money_html($v['amount']) . '</span></div>'
        . '<p class="hint">חוזרים ל' . h($v['client']) . '…</p></div>'
        . '<div class="row-btns"><a class="btn primary" href="' . h($back) . '">חזרה ל' . h($v['client']) . '</a></div>';
    page_close();
    exit;
}
if ($c['status'] !== 'open') {
    page_open('הקישור פג', $v['client']);
    echo '<p class="empty">הקישור לתשלום פג. חזור ל' . h($v['client']) . ' ונסה שוב.</p><div class="row-btns"><a class="btn ghost" href="' . h($c['cancel_url']) . '">חזרה</a></div>';
    page_close();
    exit;
}

page_open($c['mode'] === 'setup' ? 'עדכון אמצעי תשלום' : 'תשלום', $v['client']);
if ($v['env'] === 'test') echo '<p class="hint" style="background:var(--brand-soft);padding:8px;border-radius:10px">🧪 סביבת בדיקות — לא יחויב כסף אמיתי.</p>';
echo '<ul class="list">';
foreach ($v['lines'] as $l) {
    echo '<li class="card"><div class="body"><div class="l1"><span>' . h($l['name']) . '</span></div></div><b>' . money_html($l['amount']) . '</b></li>';
}
echo '</ul>';
echo '<div class="summary" style="margin-top:12px"><div class="line"><span class="muted">לתשלום עכשיו</span> <b>' . money_html($v['amount']) . '</b></div>'
    . '<div class="hint">' . h($v['vat_note']) . ' · ' . h($v['merchant']) . '</div></div>';
if ($v['disclose']) {
    echo '<h2 class="sec-title">חשוב לדעת</h2><ul class="list">';
    foreach ($v['disclose'] as $d) echo '<li class="card"><div class="body" style="white-space:normal">' . h($d) . '</div></li>';
    echo '</ul>';
}
if ($err !== '') echo '<p class="error" role="alert" style="margin-top:12px">' . h($err) . '</p>';
echo '<form method="post" style="display:grid;gap:12px;margin-top:12px">'
    . '<input type="hidden" name="t" value="' . h(form_token($c['id'])) . '">'
    . '<fieldset class="seg" aria-label="אמצעי תשלום">';
foreach ($v['methods'] as $i => $m) {
    echo '<label><input type="radio" name="method" value="' . h($m) . '"' . ($i === 0 ? ' checked' : '') . '><span>' . h($METHOD[$m] ?? $m) . '</span></label>';
}
echo '</fieldset>'
    . '<label class="switch"><input type="checkbox" name="agree" value="1" required><span>קראתי ואני מסכים לתנאים' . ($c['mode'] === 'subscription' ? ' ולחיוב החוזר' : '') . '</span></label>'
    . '<button class="btn primary" type="submit">' . ($c['mode'] === 'setup' ? 'המשך לעדכון' : ($v['amount'] ? 'המשך לתשלום ' . money_html($v['amount']) : 'התחלת הניסיון')) . '</button>'
    . '<a class="btn ghost" href="' . h($c['cancel_url']) . '">ביטול וחזרה</a>'
    . '</form>';
page_close('🔒 פרטי הכרטיס מוקלדים רק בדף של חברת הסליקה. אנחנו לא רואים ולא שומרים אותם.');
