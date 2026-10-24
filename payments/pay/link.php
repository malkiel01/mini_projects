<?php
/**
 * קישור תשלום (פרק 12.6) — ציבורי. ‏?k=<קוד>: כותרת, מחיר (או סכום לבחירה בטווח), טופס קצר, ואז דף התשלום.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/pages.php';

$code = (string)($_GET['k'] ?? '');
try {
    [$l, $client] = link_open($code);
} catch (ApiError $e) {
    page_error($e->getMessage(), 'תשלום');
}
[, $pr] = current_price($client['id'], (string)$l['product']);
$fields = json_decode($l['fields'], true) ?: [];
$err = '';
$form = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    form_check('link:' . $l['code']);
    $form = $_POST;
    if (($form['website'] ?? '') !== '') page_error('הבקשה נחסמה.', $client['name']); // מלכודת לרובוטים
    try {
        header('Location: ' . link_checkout($l['code'], $form), true, 303);
        exit;
    } catch (ApiError $e) {
        $err = $e->getMessage();
    }
}

page_open($l['title'], $client['name']);
if (isset($_GET['done'])) echo '<p class="summary" role="status">✅ תודה! התשלום התקבל, והקבלה תישלח אליך.</p>';
if ($l['env'] === 'test') echo '<p class="hint">🧪 סביבת בדיקות — לא יחויב כסף אמיתי.</p>';
echo '<div class="summary"><div class="line"><b>' . h($l['title']) . '</b>'
    . ($l['amount_mode'] === 'fixed' ? '<span class="pct">' . money_html((int)$pr['amount'] * (int)$l['quantity']) . '</span>' : '') . '</div>'
    . ($l['description'] !== '' ? '<div class="hint" style="white-space:pre-line">' . h($l['description']) . '</div>' : '') . '</div>';
if ($err !== '') echo '<p class="error" role="alert">' . h($err) . '</p>';
echo '<form method="post" style="display:grid;gap:12px"><input type="hidden" name="t" value="' . h(form_token('link:' . $l['code'])) . '">'
    . '<div aria-hidden="true" style="position:absolute;inset-inline-start:0;width:1px;height:1px;overflow:hidden;opacity:0"><input name="website" tabindex="-1" autocomplete="off"></div>';
if ($l['amount_mode'] === 'custom') {
    echo '<label class="f"><span>סכום (₪) — בין ' . money_html((int)$l['min_amount']) . ' ל-' . money_html((int)$l['max_amount']) . '</span>'
        . '<input name="amount" inputmode="decimal" dir="ltr" required value="' . h($form['amount'] ?? '') . '"></label>';
}
$TYPES = ['email' => 'email', 'phone' => 'tel'];
foreach ($fields as $f) {
    $tag = $f === 'note' ? '<textarea name="note" rows="2">' . h($form['note'] ?? '') . '</textarea>'
        : '<input name="' . h($f) . '" type="' . ($TYPES[$f] ?? 'text') . '"' . (in_array($f, ['email', 'phone', 'tax_id'], true) ? ' dir="ltr"' : '')
          . ($f !== 'tax_id' ? ' required' : '') . ' value="' . h($form[$f] ?? '') . '">';
    echo '<label class="f"><span>' . h(LINK_FIELDS[$f]) . ($f === 'tax_id' ? ' (לחשבונית לעסק)' : '') . '</span>' . $tag . '</label>';
}
echo '<button class="btn primary" type="submit">המשך לתשלום</button></form>';
page_close('🔒 פרטי הכרטיס מוקלדים רק בדף של חברת הסליקה.');
