<?php
/**
 * בדיקות השרת: php word-processor/tests/run.php
 * חתימה, טוקן וסשן, הפרדה בין לקוחות, נעילה וגרסאות, ייצוא וייבוא. מסד זמני — לא נוגע ב-data/.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/wp-tests-' . getmypid();
@mkdir($tmp, 0775, true);
putenv("WP_DATA_DIR=$tmp");
putenv('WP_BASE_URL=https://mbe-plus.com/mini_projects/word-processor/');
putenv('WP_DETACHED=1');

require_once __DIR__ . '/../lib/embed.php';
require_once __DIR__ . '/../lib/export.php';
require_once __DIR__ . '/../lib/import.php';
require_once __DIR__ . '/../lib/pdf.php';

$fail = 0;
$pass = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail, $pass;
    if ($ok) { $pass++; echo "  ✓ $name\n"; }
    else { $fail++; echo "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function throws(callable $fn, string $code): bool
{
    try { $fn(); } catch (ApiError $e) { return $e->codeName === $code; }
    return false;
}
function section(string $s): void { echo "\n$s\n"; }
/** כל זרמי התוכן של PDF, פרושים (לבדיקה). */
function gzuncompress_pages(string $pdf): string
{
    $out = '';
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
    foreach ($m[1] as $z) { $u = @gzuncompress($z); if ($u !== false && str_contains($u, ' Tf ') || ($u !== false && str_contains($u, ' re '))) $out .= $u; }
    return $out;
}

// מפתחות "מפורסמים" של לקוחות — במקום HTTP
$published = [];
$GLOBALS['WP_KEY_FETCHER'] = function (string $url) use (&$published) {
    return $published[$url] ?? null;
};
function keypair(): array
{
    $kp = sodium_crypto_sign_keypair();
    return ['secret' => sodium_crypto_sign_secretkey($kp), 'public' => sodium_crypto_sign_publickey($kp)];
}
function publish(array &$published, string $url, string $client, array $keys): void
{
    $published[$url] = json_encode(['client' => $client, 'keys' => array_map(fn($kid, $k) => ['kid' => $kid, 'alg' => 'Ed25519', 'key' => base64_encode($k['public'])], array_keys($keys), $keys)]);
}

$A = 'org-budget-proposals';
$urlA = 'https://mbe-plus.com/mini_projects/org-budget-proposals/api.php?action=embed_key';
$kA = keypair();
publish($published, $urlA, $A, ['2026-10' => $kA]);

// ======================================================================
section('רישום');
$r = client_register(['client' => $A, 'name' => 'הצעות תקציב', 'key_url' => $urlA, 'origins' => ['https://mbe-plus.com']]);
check('אותו מקור ובתיקייה של הלקוח → active', $r['status'] === 'active', json_encode($r));
$r = client_register(['client' => $A, 'name' => 'הצעות תקציב', 'key_url' => $urlA, 'origins' => ['https://mbe-plus.com']]);
check('רישום חוזר זהה — בלי שינוי', $r['status'] === 'active');
check('key_url שאינו תחת origins → נדחה', throws(fn() => client_register(['client' => 'x1', 'key_url' => 'https://evil.example/k', 'origins' => ['https://mbe-plus.com']]), 'bad_key_url'));
check('origin עם נתיב → נדחה', throws(fn() => client_register(['client' => 'x1', 'key_url' => $urlA, 'origins' => ['https://mbe-plus.com/x']]), 'bad_origins'));
check('http (לא https) → נדחה', throws(fn() => client_register(['client' => 'x1', 'key_url' => 'http://mbe-plus.com/a', 'origins' => ['http://mbe-plus.com']]), 'bad_origins'));
check('מפתח שלא ניתן להוריד → נדחה', throws(fn() => client_register(['client' => 'x1', 'key_url' => 'https://mbe-plus.com/mini_projects/x1/k', 'origins' => ['https://mbe-plus.com']]), 'key_fetch_failed'));

// אפליקציה אחרת באותו דומיין שמנסה להירשם בשם אחר — המפתח לא בתיקייה של הלקוח → pending
$urlEvil = 'https://mbe-plus.com/mini_projects/other-app/api.php?action=embed_key';
$kEvil = keypair();
publish($published, $urlEvil, 'victim', ['k' => $kEvil]);
$r = client_register(['client' => 'victim', 'key_url' => $urlEvil, 'origins' => ['https://mbe-plus.com']]);
check('מפתח בתיקייה של אפליקציה אחרת → pending', $r['status'] === 'pending');

$B = 'outside-app';
$urlB = 'https://outside.example/embed-key';
$kB = keypair();
publish($published, $urlB, $B, ['b1' => $kB]);
$r = client_register(['client' => $B, 'name' => 'חיצונית', 'key_url' => $urlB, 'origins' => ['https://outside.example']]);
check('דומיין אחר → pending', $r['status'] === 'pending');

// ======================================================================
section('חתימה');
function signed_req(string $client, string $kid, string $secret, string $action, array $payload, ?int $time = null, ?string $nonce = null): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    return [$body, sign_headers($client, $kid, $secret, $action, $body, $time, $nonce)];
}
[$body, $h] = signed_req($A, '2026-10', $kA['secret'], 'doc_list', []);
check('חתימה תקינה מתקבלת', verify_signed('doc_list', $body, $h)['id'] === $A);
check('אותה בקשה שוב → replay', throws(fn() => verify_signed('doc_list', $body, $h), 'replay'));
[$body, $h] = signed_req($A, '2026-10', $kA['secret'], 'doc_list', ['after' => '']);
check('גוף ששונה → bad_signature', throws(fn() => verify_signed('doc_list', $body . ' ', $h), 'bad_signature'));
check('פעולה אחרת מזו שנחתמה → bad_signature', throws(fn() => verify_signed('doc_delete', $body, $h), 'bad_signature'));
[$body, $h] = signed_req($A, '2026-10', $kA['secret'], 'doc_list', [], time() - 400);
check('זמן ישן (400 ש\') → stale', throws(fn() => verify_signed('doc_list', $body, $h), 'stale'));
[$body, $h] = signed_req($A, '2026-10', $kA['secret'], 'doc_list', [], time() + 400);
check('זמן עתידי → stale', throws(fn() => verify_signed('doc_list', $body, $h), 'stale'));
[$body, $h] = signed_req('nobody', 'k', $kA['secret'], 'doc_list', []);
check('לקוח לא רשום → unknown_client', throws(fn() => verify_signed('doc_list', $body, $h), 'unknown_client'));
[$body, $h] = signed_req($B, 'b1', $kB['secret'], 'doc_list', []);
check('לקוח ממתין → pending', throws(fn() => verify_signed('doc_list', $body, $h), 'pending'));
[$body, $h] = signed_req($A, '2026-10', keypair()['secret'], 'doc_list', []);
check('מפתח זר באותו kid → bad_signature', throws(fn() => verify_signed('doc_list', $body, $h), 'bad_signature'));
$h['X-Embed-Signature'] = '2026-10.' . base64_encode('short');
check('חתימה קצרה → bad_signature', throws(fn() => verify_signed('doc_list', $body, $h), 'bad_signature'));

// החלפת מפתח: המארחת מפרסמת מפתח חדש — חתימה שנכשלת מורידה מחדש פעם אחת
$kA2 = keypair();
publish($published, $urlA, $A, ['2026-10' => $kA, '2026-11' => $kA2]);
db()->prepare('UPDATE clients SET refetch_at = 0 WHERE id = ?')->execute([$A]);
[$body, $h] = signed_req($A, '2026-11', $kA2['secret'], 'doc_list', []);
check('מפתח חדש (kid חדש) — הורדה מחדש ומתקבל', verify_signed('doc_list', $body, $h)['id'] === $A);
[$body, $h] = signed_req($A, '2026-12', keypair()['secret'], 'doc_list', []);
check('kid לא מוכר אחרי הורדה → bad_signature', throws(fn() => verify_signed('doc_list', $body, $h), 'bad_signature'));

// לקוח קיים שנרשם עם origins אחרים → חוזר ל-pending (אף פעם לא דריסה שקטה)
publish($published, 'https://outside.example/k2', $A, ['2026-10' => $kA]);
$r = client_register(['client' => $A, 'key_url' => 'https://outside.example/k2', 'origins' => ['https://outside.example', 'https://mbe-plus.com']]);
check('שינוי key_url/origins ללקוח קיים → pending', $r['status'] === 'pending');
[$body, $h] = signed_req($A, '2026-10', $kA['secret'], 'doc_list', []);
check('…ואז הבקשות שלו נדחות', throws(fn() => verify_signed('doc_list', $body, $h), 'pending'));
$r = client_register(['client' => $A, 'name' => 'הצעות תקציב', 'key_url' => $urlA, 'origins' => ['https://mbe-plus.com']]);
check('חזרה לכתובת בתיקייה שלו → active', $r['status'] === 'active');
// B מאושר ידנית (כמו המתג במסך המנהל)
db()->prepare("UPDATE clients SET status = 'active' WHERE id = ?")->execute([$B]);

// ======================================================================
section('טוקן וסשן');
$cA = client_get($A);
$cB = client_get($B);
check('return_origin שאינו רשום → נדחה', throws(fn() => embed_session($cA, ['doc' => '', 'user' => ['id' => 'u1', 'name' => 'דנה'], 'return_origin' => 'https://evil.example']), 'bad_origin'));
check('בלי user.id → נדחה', throws(fn() => embed_session($cA, ['doc' => '', 'user' => ['name' => 'דנה'], 'return_origin' => 'https://mbe-plus.com']), 'bad_user'));
$s = embed_session($cA, ['doc' => '', 'user' => ['id' => 'u1', 'name' => 'דנה'], 'can' => 'edit', 'mode' => 'section',
    'page' => ['size' => 'Letter', 'margins' => ['top' => 30]], 'return_origin' => 'https://mbe-plus.com']);
check('embed_session על doc ריק → מסמך חדש', preg_match('/^[a-z0-9]{20}$/', $s['doc']) === 1);
check('embed_url עם טוקן, 60 שניות', str_starts_with($s['embed_url'], 'https://mbe-plus.com/mini_projects/word-processor/embed.php?t=') && $s['expires_in'] === 60);
$docA = $s['doc'];
check('page של מצב קטע נשמר במסמך', doc_page(doc_find($docA, "c:$A"))['size'] === 'Letter' && doc_page(doc_find($docA, "c:$A"))['margins']['top'] == 30);
$t = substr($s['embed_url'], strpos($s['embed_url'], '?t=') + 3);
check('token_peek מוצא את הלקוח (ל-frame-ancestors)', (token_peek($t)['client'] ?? '') === $A);
$open = token_consume($t);
check('embed_open → סשן', strlen($open['session']) === 64 && $open['doc'] === $docA && $open['can'] === 'edit');
check('טוקן חד-פעמי: פתיחה שנייה → expired', throws(fn() => token_consume($t), 'expired'));
check('token_peek אחרי שימוש → null', token_peek($t) === null);
$s2 = embed_session($cA, ['doc' => $docA, 'user' => ['id' => 'u1', 'name' => 'דנה'], 'return_origin' => 'https://mbe-plus.com']);
$t2 = substr($s2['embed_url'], strpos($s2['embed_url'], '?t=') + 3);
db()->prepare('UPDATE embed_tokens SET expires = ? WHERE hash = ?')->execute([time() - 1, hash('sha256', $t2)]);
check('טוקן שפג (60 שניות) → expired', throws(fn() => token_consume($t2), 'expired'));
check('טוקן מזויף → expired', throws(fn() => token_consume(str_repeat('a', 48)), 'expired'));

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $open['session'];
check('Bearer → הסשן', embed_auth()['doc'] === $docA);
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['HTTP_X_EMBED_SESSION'] = $open['session'];
check('X-Embed-Session (כשהשרת מוריד Authorization) → הסשן', embed_auth()['doc'] === $docA);
$_SERVER['HTTP_X_EMBED_SESSION'] = str_repeat('0', 64);
check('סשן לא קיים → expired', throws(fn() => embed_auth(), 'expired'));
db()->prepare('UPDATE embed_sessions SET expires = ? WHERE hash = ?')->execute([time() - 1, hash('sha256', $open['session'])]);
$_SERVER['HTTP_X_EMBED_SESSION'] = $open['session'];
check('סשן שפג (8 שעות) → expired', throws(fn() => embed_auth(), 'expired'));
unset($_SERVER['HTTP_X_EMBED_SESSION']);

// ======================================================================
section('הפרדה בין לקוחות');
$docB = doc_new("c:$B", 'של B', null, null, 'client:b', 'B');
check('לקוח A לא רואה מסמך של B → 404', throws(fn() => doc_find($docB, "c:$A"), 'not_found'));
check('לקוח B לא רואה מסמך של A → 404', throws(fn() => doc_find($docA, "c:$B"), 'not_found'));
check('embed_session של B על מסמך של A → 404', throws(fn() => embed_session($cB, ['doc' => $docA, 'user' => ['id' => 'x'], 'return_origin' => 'https://outside.example']), 'not_found'));
check('משתמש אפליקציה לא רואה מסמך של לקוח → 404', throws(fn() => doc_find($docA, 'a:1'), 'not_found'));
$ids = array_column(db()->query("SELECT id FROM docs WHERE space = 'c:$A'")->fetchAll(), 'id');
check('doc_list של A — רק שלו', in_array($docA, $ids, true) && !in_array($docB, $ids, true));
check('מזהה לא תקין → 404 (לא שגיאת SQL)', throws(fn() => doc_find("x' OR 1=1 --", "c:$A"), 'not_found'));

// ======================================================================
section('נעילה וגרסאות');
check('משתמש 1 לוקח נעילה', lock_take($docA, 'u1', 'דנה', 'h1')['ok']);
$r = lock_take($docA, 'u2', 'יוסי', 'h2');
check('משתמש 2 — תפוס, "פתוח לעריכה אצל דנה"', !$r['ok'] && $r['by'] === 'דנה');
check('אותו משתמש בלשונית אחרת — מותר', lock_take($docA, 'u1', 'דנה', 'h3')['ok']);
db()->prepare('UPDATE locks SET beat = ? WHERE doc = ?')->execute([time() - 121, $docA]);
check('בלי דופק 2 דקות — הנעילה פגה', lock_take($docA, 'u2', 'יוסי', 'h2')['ok']);
lock_release($docA, 'u2');
check('שחרור', lock_get($docA) === null);

$row = doc_find($docA, "c:$A");
$v0 = (int)$row['version'];
$p = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'גרסה']]]]];
$row = doc_save($row, $p, null, 'u1', 'דנה', $v0);
$row = doc_save($row, $p, null, 'u1', 'דנה', $v0 + 1);
check('שתי שמירות של אותו משתמש תוך 10 דקות — גרסה אחת', count(versions_list($row)) === 1, (string)count(versions_list($row)));
$row = doc_save($row, $p, null, 'u2', 'יוסי', $v0 + 2);
check('משתמש אחר — גרסה חדשה', count(versions_list($row)) === 2);
check('שמירה על גרסה ישנה → 409 conflict', throws(fn() => doc_save($row, $p, null, 'u2', 'יוסי', $v0), 'conflict'));
check('JSON לא תקין → bad_json', throws(fn() => doc_save($row, ['type' => 'x'], null, 'u2', 'יוסי'), 'bad_json'));
db()->prepare('UPDATE versions SET started_at = started_at - 700 WHERE doc = ?')->execute([$docA]);
for ($i = 0; $i < 105; $i++) {
    $row = doc_save($row, $p, null, 'u' . ($i % 2 + 3), 'x', null);
}
check('עד 100 גרסאות למסמך', count(versions_list($row)) === 100, (string)count(versions_list($row)));

// ======================================================================
section('ייצוא');
$gd = imagecreatetruecolor(2, 2);
imagefill($gd, 0, 0, imagecolorallocate($gd, 200, 30, 30));
ob_start(); imagepng($gd); $png = ob_get_clean();   // PNG תקין (2×2)
$media = media_store($docA, $png);
check('העלאת תמונה (PNG אמיתי)', strlen($media['id']) === 24 && $media['width'] === 2);
check('קובץ שאינו תמונה → נדחה', throws(fn() => media_store($docA, '<?php echo 1;'), 'bad_type'));
check('כתובת תמונה חתומה', hash_equals(media_sig($media['id']), explode('&s=', media_url($media['id']))[1]));

$json = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'attrs' => ['pstyle' => 'title'], 'content' => [['type' => 'text', 'text' => 'תכנית עבודה לשנת 2026']]],
    ['type' => 'heading', 'attrs' => ['level' => 1, 'textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'מבוא']]],
    ['type' => 'paragraph', 'attrs' => ['textAlign' => 'justify', 'lineHeight' => '1.5', 'spaceAfter' => 8, 'indent' => 1], 'content' => [
        ['type' => 'text', 'text' => 'טקסט '], ['type' => 'text', 'text' => 'מודגש', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' ותאריך 2026/01-04 ', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#c00000', 'fontSize' => '14pt', 'fontFamily' => 'Frank Ruehl']]]],
        ['type' => 'text', 'text' => 'H', 'marks' => [['type' => 'italic']]], ['type' => 'text', 'text' => '2', 'marks' => [['type' => 'subscript']]],
        ['type' => 'text', 'text' => ' <script>', 'marks' => [['type' => 'highlight', 'attrs' => ['color' => '#ffff00']]]],
    ]],
    ['type' => 'orderedList', 'attrs' => ['start' => 1, 'listType' => 'hebrew'], 'content' => [
        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ראשון']]],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'תת-סעיף']]]]]]]]],
        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'שני']]]]],
    ]],
    ['type' => 'table', 'content' => [
        ['type' => 'tableRow', 'content' => [['type' => 'tableHeader', 'attrs' => ['colspan' => 2], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'כותרת']]]]]]],
        ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['rowspan' => 2], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ממוזג']]]]],
            ['type' => 'tableCell', 'attrs' => ['background' => '#ddeeff'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'א']]]]]]],
        ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ב']]]]]]],
    ]],
    ['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['media' => $media['id'], 'width' => 120, 'height' => 120, 'wrap' => 'square', 'align' => 'left']],
        ['type' => 'text', 'text' => 'טקסט שגולש סביב התמונה'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'שורה שנייה']]],
    ['type' => 'pageBreak'],
    ['type' => 'paragraph', 'attrs' => ['dir' => 'ltr', 'textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => 'English paragraph']]],
]];
$row = doc_save(doc_find($docA, "c:$A"), $json, 'תכנית', 'u1', 'דנה');
check('המקור נשמר בלי כתובת התמונה (רק המזהה)', !str_contains($row['json'], 'api.php'));
$page = page_normalize(['numbering' => ['format' => 'n_of_m', 'position' => 'bottom', 'align' => 'left', 'first_page' => false, 'start' => 3], 'header' => 'כותרת עליונה', 'footer' => 'תחתונה']);

$html = export_html(doc_json($row), $page);
check('html: כותרת מסמך, h1 ממורכז', str_contains($html['html'], 'class="wp-title"') && str_contains($html['html'], '<h1 style="text-align:center;">מבוא</h1>'));
check('html: מודגש, צבע, גודל, גופן', str_contains($html['html'], '<strong>מודגש</strong>') && str_contains($html['html'], 'color:#C00000') && str_contains($html['html'], 'font-size:14pt'));
check('html: רשימה עברית מקוננת', str_contains($html['html'], '<ol class="hebrew" style="counter-reset:wpl 0">') && str_contains($html['html'], '<ul><li><p>תת-סעיף'));
check('html: טבלה עם colspan/rowspan', str_contains($html['html'], '<th colspan="2">') && str_contains($html['html'], '<td rowspan="2">'));
check('html: תמונה בכתובת חתומה מלאה, גלישה ריבוע', str_contains($html['html'], 'src="https://mbe-plus.com/mini_projects/word-processor/api.php?action=media&amp;id=' . $media['id']) && str_contains($html['html'], 'wrap-square align-left'));
check('html: תוכן משתמש מוברח (<script>)', !str_contains($html['html'], '<script>') && str_contains($html['html'], '&lt;script&gt;'));
check('html: css עם הגופן וסגנונות הכותרות', str_contains($html['css'], "'David'") && str_contains($html['css'], '.wp-doc h1{'));
check('html: פסקה משמאל לימין', str_contains($html['html'], '<p dir="ltr" style="text-align:left;">English paragraph</p>'));

$text = export_text(doc_json($row));
$lines = explode("\n", $text);
check('text: כותרות בלי סימון', $lines[0] === 'תכנית עבודה לשנת 2026' && $lines[1] === 'מבוא');
check('text: ממוספר עברי ותבליט מקונן', in_array('א. ראשון', $lines, true) && in_array('  - תת-סעיף', $lines, true) && in_array('ב. שני', $lines, true));
check('text: טבלה בטאבים', in_array("ממוזג\tא", $lines, true));

$ox = export_ooxml(doc_json($row), $page);
$wrap = fn($inner) => '<w:document ' . W_NS . '><w:body>' . $inner . '</w:body></w:document>';
$dom = new DOMDocument();
check('ooxml: הגוף הוא XML תקין', @$dom->loadXML($wrap($ox['body'])));
check('ooxml: סגנונות בקידומת wp (בלי התנגשות במארחת)', str_contains($ox['body'], 'w:pStyle w:val="wpHeading1"') && str_contains($ox['styles'], 'w:styleId="wpTitle"'));
check('ooxml: מספור עברי ומזהים מ-9001', str_contains($ox['numbering'], 'hebrew1') && str_contains($ox['numbering'], 'w:numId="9001"'));
check('ooxml: תמונה + rels + media', count($ox['media']) === 1 && str_contains($ox['rels'], 'Id="wpImg1"') && str_contains($ox['body'], 'r:embed="wpImg1"') && str_contains($ox['body'], '<wp:wrapSquare'));
check('ooxml: rtl רק על עברית, לא על תאריך', str_contains($ox['body'], '<w:t xml:space="preserve">2026/01-04</w:t>') && !preg_match('/<w:rtl\/>[^<]*<\/w:rPr><w:t xml:space="preserve">2026\/01-04/', $ox['body']));
check('ooxml: יישור לוגי (justify→both, ltr+left→ללא)', str_contains($ox['body'], '<w:jc w:val="both"/>'));
check('ooxml: ריווח 1.5 ו-8pt אחרי', str_contains($ox['body'], 'w:after="160" w:line="360" w:lineRule="auto"'));
check('ooxml: מיזוג אנכי וגריד', str_contains($ox['body'], '<w:vMerge w:val="restart"/>') && str_contains($ox['body'], '<w:vMerge/>') && str_contains($ox['body'], '<w:gridSpan w:val="2"/>'));

$docx = export_docx(doc_json($row), $page, 'תכנית');
$f = "$tmp/t.docx";
file_put_contents($f, $docx);
$z = new ZipArchive();
check('docx: ZIP תקין', $z->open($f) === true);
$allXml = true;
for ($i = 0; $i < $z->numFiles; $i++) {
    $n = $z->getNameIndex($i);
    if (preg_match('/\.(xml|rels)$/', $n)) {
        $d = new DOMDocument();
        if (!@$d->loadXML($z->getFromName($n))) { $allXml = false; echo "    XML פגום: $n\n"; }
    }
}
check('docx: כל חלקי ה-XML תקינים', $allXml);
$docXml = $z->getFromName('word/document.xml');
check('docx: גודל A4 ושוליים', str_contains($docXml, '<w:pgSz w:w="11906" w:h="16838"/>') && str_contains($docXml, 'w:top="1417"'));
check('docx: מספור מ-3, עמוד ראשון בלי מספר (titlePg)', str_contains($docXml, 'w:start="3"') && str_contains($docXml, '<w:titlePg/>'));
$footer = $z->getFromName('word/footer1.xml');
check('docx: "N מתוך M" בכותרת התחתונה, משמאל', str_contains($footer, ' PAGE ') && str_contains($footer, ' NUMPAGES ') && str_contains($footer, 'מתוך') && str_contains($footer, '<w:jc w:val="end"/>'));
check('docx: עמוד ראשון — הטקסט בלי מספר', !str_contains((string)$z->getFromName('word/footer1f.xml'), 'PAGE') && str_contains((string)$z->getFromName('word/footer1f.xml'), 'תחתונה'));
check('docx: כותרת עליונה', str_contains($z->getFromName('word/header1.xml'), 'כותרת עליונה'));
check('docx: סגנונות מובנים (Heading1, Title) וגופן ברירת מחדל', str_contains($z->getFromName('word/styles.xml'), 'w:styleId="Heading1"') && str_contains($z->getFromName('word/styles.xml'), 'w:ascii="David"'));
check('docx: התמונה בתוך החבילה', $z->getFromName('word/media/image_1.png') === $png);
check('docx: שבירת עמוד', str_contains($docXml, '<w:br w:type="page"/>'));
$z->close();
$hebrewPage = page_normalize(['numbering' => ['format' => 'hebrew']]);
$z2 = export_docx(doc_json($row), $hebrewPage);
file_put_contents($f, $z2);
$z->open($f);
check('docx: מספור עמודים באותיות (hebrew1)', str_contains($z->getFromName('word/document.xml'), 'w:fmt="hebrew1"'));
$z->close();
check('page_label: כל התבניות', page_label(3, 12, 'n') === '3' && page_label(3, 12, 'page_n') === "עמ' 3" && page_label(3, 12, 'n_of_m') === '3 מתוך 12'
    && page_label(3, 12, 'dash_n') === '- 3 -' && page_label(15, 20, 'hebrew') === 'טו' && page_label(11, 20, 'hebrew') === 'יא');

// ======================================================================
section('ייבוא');
$imp = import_lines("# פרק ראשון\nפסקה רגילה\n- תבליט א\n- תבליט ב\n\n## כותרת משנה\nעוד פסקה");
$c = $imp['json']['content'];
check('lines: "# " → כותרת 1', $c[0]['type'] === 'heading' && $c[0]['attrs']['level'] === 1 && $c[0]['content'][0]['text'] === 'פרק ראשון');
check('lines: שורה → פסקה', $c[1]['type'] === 'paragraph');
check('lines: "- " רצופים → רשימה אחת', $c[2]['type'] === 'bulletList' && count($c[2]['content']) === 2);
check('lines: "## " → כותרת 2', $c[3]['type'] === 'heading' && $c[3]['attrs']['level'] === 2);
check('lines → text חוזר לאותו פורמט', export_text($imp['json']) === "פרק ראשון\nפסקה רגילה\n- תבליט א\n- תבליט ב\nכותרת משנה\nעוד פסקה");

$docC = doc_new("c:$A", 'ייבוא', null, null, 'u1', 'דנה');
$imp = import_docx($docx, $docC);
$types = array_column($imp['json']['content'], 'type');
check('docx → JSON: כותרת מסמך וכותרת 1', ($imp['json']['content'][0]['attrs']['pstyle'] ?? '') === 'title' && ($imp['json']['content'][1]['attrs']['level'] ?? 0) === 1);
check('docx → JSON: רשימה ממוספרת עברית עם תת-רשימה', in_array('orderedList', $types, true)
    && ($imp['json']['content'][array_search('orderedList', $types)]['attrs']['listType'] ?? '') === 'hebrew'
    && ($imp['json']['content'][array_search('orderedList', $types)]['content'][0]['content'][1]['type'] ?? '') === 'bulletList');
check('docx → JSON: טבלה עם מיזוגים', in_array('table', $types, true)
    && ($imp['json']['content'][array_search('table', $types)]['content'][1]['content'][0]['attrs']['rowspan'] ?? 0) === 2);
$flat = json_encode($imp['json'], JSON_UNESCAPED_UNICODE);
check('docx → JSON: מודגש, צבע, גודל', str_contains($flat, '"type":"bold"') && str_contains($flat, '#c00000') && str_contains($flat, '"fontSize":"14pt"'));
check('docx → JSON: תמונה נשמרה, גלישה ריבוע', str_contains($flat, '"type":"image"') && str_contains($flat, '"wrap":"square"'));
check('docx → JSON: שבירת עמוד', in_array('pageBreak', $types, true));
check('docx → JSON: הגדרות עמוד', ($imp['page']['size'] ?? '') === 'A4' && abs(($imp['page']['margins']['top'] ?? 0) - 25) < 0.2);
check('docx → JSON: כותרת עליונה / תחתונה ומספור חוזרים', ($imp['page']['header'] ?? '') === 'כותרת עליונה' && ($imp['page']['footer'] ?? '') === 'תחתונה'
    && ($imp['page']['numbering'] ?? null) == ['show' => true, 'position' => 'bottom', 'align' => 'left', 'format' => 'n_of_m', 'start' => 3, 'first_page' => false], json_encode($imp['page']['numbering'] ?? null, JSON_UNESCAPED_UNICODE));
check('דוח ייבוא: מסמך שיצא מכאן חוזר בלי הערות', $imp['report'] === [], json_encode($imp['report'], JSON_UNESCAPED_UNICODE));

// docx "של Word" עם מה שלא נתמך — כל דבר מגיע לדוח, והטקסט שמסביב נשמר
$mkDocx = function (array $parts): string {
    global $tmp;
    $f = "$tmp/r.docx";
    @unlink($f);
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE);
    foreach ($parts as $name => $xml) $z->addFromString($name, $xml);
    $z->close();
    return (string)file_get_contents($f);
};
$WN = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"';
$run = fn($t, $font = '') => '<w:r>' . ($font ? '<w:rPr><w:rFonts w:ascii="' . $font . '" w:cs="' . $font . '"/></w:rPr>' : '') . '<w:t xml:space="preserve">' . $t . '</w:t></w:r>';
$body = '<w:p><w:pPr><w:pStyle w:val="Quote"/><w:bidi/><w:ind w:right="1440" w:firstLine="360"/></w:pPr>' . $run('מוזח', 'Segoe UI') . '</w:p>'
    . '<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="4"/></w:pBdr></w:pPr>' . $run('לפני ') . '<w:r><w:drawing><wp:inline><a:graphic><a:graphicData uri="x"/></a:graphic></wp:inline></w:drawing></w:r>' . $run(' אחרי') . '</w:p>'
    . '<w:p><m:oMathPara><m:oMath><m:r><m:t>x</m:t></m:r></m:oMath></m:oMathPara>' . $run('נוסחה') . '</w:p>'
    . '<w:p><w:fldSimple w:instr=" DATE "><w:r><w:t>22/10/2026</w:t></w:r></w:fldSimple><w:ins w:id="1">' . $run(' נוסף') . '</w:ins><w:del w:id="2"><w:r><w:delText>נמחק</w:delText></w:r></w:del></w:p>'
    . '<w:p>' . $run('סיום') . '<w:r><w:rPr><w:rStyle w:val="Emph"/></w:rPr><w:t>!</w:t></w:r><w:r><w:endnoteReference w:id="1"/></w:r><w:commentRangeStart w:id="0"/></w:p>'
    . '<w:tbl><w:tr><w:tc><w:tbl><w:tr><w:tc><w:p>' . $run('פנימי') . '</w:p></w:tc></w:tr></w:tbl><w:p>' . $run('תא') . '</w:p></w:tc></w:tr></w:tbl>'
    . '<w:sectPr><w:headerReference w:type="default" r:id="rH"/><w:headerReference w:type="even" r:id="rE"/><w:footerReference w:type="default" r:id="rF"/>'
    . '<w:pgSz w:w="11906" w:h="16838"/><w:cols w:num="2"/></w:sectPr>';
$hdr = fn($tag, $inner) => '<w:' . $tag . ' ' . $WN . '>' . $inner . '</w:' . $tag . '>';
$wordDocx = $mkDocx([
    '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
    'word/document.xml' => '<w:document ' . $WN . '><w:body>' . $body . '</w:body></w:document>',
    'word/styles.xml' => '<w:styles ' . $WN . '><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:sz w:val="24"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="ציטוט"/><w:pPr><w:jc w:val="center"/><w:spacing w:after="240"/></w:pPr><w:rPr><w:i/><w:color w:val="595959"/></w:rPr></w:style>'
        . '<w:style w:type="character" w:styleId="Emph"><w:name w:val="Emph"/><w:rPr><w:b/></w:rPr></w:style></w:styles>',
    'word/comments.xml' => '<w:comments ' . $WN . '><w:comment w:id="0"><w:p>' . $run('הערה') . '</w:p></w:comment></w:comments>',
    'word/_rels/document.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rH" Target="header1.xml"/><Relationship Id="rE" Target="header2.xml"/><Relationship Id="rF" Target="footer1.xml"/></Relationships>',
    'word/header1.xml' => $hdr('hdr', '<w:p><w:pPr><w:bidi/></w:pPr>' . $run('חברה בע"מ') . '</w:p>'),
    'word/header2.xml' => $hdr('hdr', '<w:p>' . $run('זוגי') . '</w:p>'),
    'word/footer1.xml' => $hdr('ftr', '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/></w:pPr><w:r><w:t xml:space="preserve">- </w:t></w:r><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r><w:r><w:t xml:space="preserve"> -</w:t></w:r></w:p>'),
]);
$w = import_docx($wordDocx, $docC);
$rep = array_column($w['report'], 'count', 'key');
check('דוח ייבוא: כל מה שלא נתמך מופיע', $rep == ['shapes' => 1, 'math' => 1, 'comments' => 1, 'endnotes' => 1, 'nested' => 1, 'tracked' => 2, 'fields' => 1,
    'columns' => 1, 'hf_extra' => 1, 'styles' => 1, 'pborder' => 1, 'fonts' => 1], json_encode($rep));
check('ייבוא: סגנון פסקה מותאם → סגנון שלנו (שם ועיצוב), והפסקה בו', ($w['page']['styles']['u1'] ?? null) == ['name' => 'ציטוט', 'italic' => true, 'color' => '#595959', 'align' => 'center', 'after' => 12.0]
    && ($w['json']['content'][0]['attrs']['pstyle'] ?? '') === 'u1', json_encode($w['page']['styles'] ?? null, JSON_UNESCAPED_UNICODE));
$fontRow = array_values(array_filter($w['report'], fn($r) => $r['key'] === 'fonts'))[0] ?? [];
check('דוח ייבוא: שם הגופן החסר ומה מוצג במקומו', str_contains($fontRow['detail'] ?? '', 'Segoe UI') && str_contains($fontRow['detail'] ?? '', 'Heebo'));
$wt = export_text($w['json']);
check('דוח ייבוא: הטקסט שמסביב נשמר; שדה — תוצאתו; נמחק — לא', str_contains($wt, 'לפני  אחרי') && str_contains($wt, '22/10/2026 נוסף') && !str_contains($wt, 'נמחק') && str_contains($wt, 'תא'), $wt);
check('ייבוא: הזחה מימין (bidi) → indent, ושורה ראשונה → firstLine', ($w['json']['content'][0]['attrs']['indent'] ?? 0) === 2 && ($w['json']['content'][0]['attrs']['firstLine'] ?? 0) == 18);
check('ייבוא: כותרת עליונה, ומספר "- N -" במרכז הכותרת התחתונה', ($w['page']['header'] ?? '') === 'חברה בע"מ' && !isset($w['page']['footer'])
    && ($w['page']['numbering'] ?? null) == ['show' => true, 'position' => 'bottom', 'align' => 'center', 'format' => 'dash_n', 'first_page' => true], json_encode($w['page'], JSON_UNESCAPED_UNICODE));
$noHf = $mkDocx(['word/document.xml' => '<w:document ' . $WN . '><w:body><w:p>' . $run('א') . '</w:p><w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>']);
check('ייבוא: בלי מספר עמוד במקור — בלי מספור', (import_docx($noHf, $docC)['page']['numbering'] ?? null) == ['show' => false]);

// קישורים וסימניות: Word הלוך וחזור, HTML, ושדה HYPERLINK של Word
$lk = fn($t, $href, $more = []) => ['type' => 'text', 'text' => $t, 'marks' => array_merge($more, [['type' => 'link', 'attrs' => ['href' => $href]]])];
$jl = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ראו '], $lk('האתר', 'https://example.com/a?b=1&c=2'), ['type' => 'text', 'text' => ' או '], $lk('הפרק', '#פרק_א', [['type' => 'bold']])]],
    ['type' => 'paragraph', 'content' => [['type' => 'bookmark', 'attrs' => ['name' => 'פרק_א']], ['type' => 'text', 'text' => 'פרק א']]],
    ['type' => 'paragraph', 'content' => [$lk('רע', 'javascript:alert(1)')]],
]];
$oxl = export_ooxml($jl, page_normalize([]));
check('Word: קישור חיצוני = w:hyperlink + קשר External, עם &amp;', str_contains($oxl['body'], '<w:hyperlink r:id="wpLink1"') && str_contains($oxl['rels'], 'Target="https://example.com/a?b=1&amp;c=2" TargetMode="External"'));
check('Word: קישור לסימניה = w:anchor; סימניה = bookmarkStart/End', str_contains($oxl['body'], 'w:anchor="פרק_א"') && preg_match('/<w:bookmarkStart w:id="\d+" w:name="פרק_א"\/><w:bookmarkEnd/u', $oxl['body']) === 1);
check('Word: קישור בסגנון Hyperlink (מוגדר בסגנונות)', str_contains($oxl['body'], '<w:rStyle w:val="wpHyperlink"/>') && str_contains($oxl['styles'], 'w:styleId="wpHyperlink"'));
check('Word: javascript: לא נכנס כקישור', substr_count($oxl['body'], '<w:hyperlink') === 2);
$hl = export_html($jl, page_normalize([]))['html'];
check('HTML: <a href> ו-<a id>, בלי javascript:', str_contains($hl, '<a href="https://example.com/a?b=1&amp;c=2">האתר</a>') && str_contains($hl, '<a id="פרק_א"></a>') && !str_contains($hl, 'javascript'));
$docL = doc_new("c:$A", 'קישורים', null, null, 'u1', 'דנה');
$backL = import_docx(export_docx($jl, page_normalize([])), $docL)['json'];
$fl = json_encode($backL, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('Word → חזרה: שני הקישורים, הסימניה, והמודגש — בלי צבע וקו תחתון נוספים', str_contains($fl, '"href":"https://example.com/a?b=1&c=2"') && str_contains($fl, '"href":"#פרק_א"')
    && str_contains($fl, '{"type":"bookmark","attrs":{"name":"פרק_א"}}') && str_contains($fl, '"type":"bold"') && !str_contains($fl, '0563c1') && !str_contains($fl, 'underline'), $fl);
$fieldDocx = $mkDocx(['word/document.xml' => '<w:document ' . $WN . '><w:body><w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> HYPERLINK "https://gov.il" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>'
    . $run('אתר הממשלה') . '<w:r><w:fldChar w:fldCharType="end"/></w:r>' . $run(' סוף') . '<w:bookmarkStart w:id="0" w:name="_GoBack"/><w:bookmarkEnd w:id="0"/></w:p></w:body></w:document>']);
$fi = import_docx($fieldDocx, $docL);
$ff = json_encode($fi['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('ייבוא: שדה HYPERLINK → קישור, רק על התוצאה; _GoBack — לא', str_contains($ff, '{"type":"text","text":"אתר הממשלה","marks":[{"type":"link","attrs":{"href":"https://gov.il"}}]}') && str_contains($ff, '{"type":"text","text":" סוף"}') && !str_contains($ff, 'bookmark'), $ff);
$anchorDocx = $mkDocx(['word/document.xml' => '<w:document ' . $WN . '><w:body><w:p><w:fldSimple w:instr=" HYPERLINK \\l &quot;פרק_ג&quot; ">' . $run('לפרק') . '</w:fldSimple></w:p></w:body></w:document>']);
check('ייבוא: HYPERLINK \\l "סימניה" → קישור פנימי', str_contains(json_encode(import_docx($anchorDocx, $docL)['json'], JSON_UNESCAPED_UNICODE), '"href":"#פרק_ג"'));
check('ייבוא: שדה HYPERLINK לא נספר בדוח כ"שדה"', !in_array('fields', array_column($fi['report'], 'key'), true));
check('safe_href: משלים ודוחה', safe_href('www.a.co.il') === 'https://www.a.co.il' && safe_href('a@b.co') === 'mailto:a@b.co' && safe_href('javascript:x') === null && safe_href('#') === null);

// טיפוגרפיה: סוגי קו תחתון, שורה ראשונה / תלויה, טאב — Word הלוך וחזור ו-HTML
$jt = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'attrs' => ['firstLine' => 18], 'content' => [['type' => 'text', 'text' => 'גלי', 'marks' => [['type' => 'underline', 'attrs' => ['ul' => 'wavy']]]], ['type' => 'text', 'text' => "\tאחרי טאב"]]],
    ['type' => 'paragraph', 'attrs' => ['firstLine' => -24, 'indent' => 1], 'content' => [['type' => 'text', 'text' => 'כפול', 'marks' => [['type' => 'underline', 'attrs' => ['ul' => 'double']]]]]],
]];
$oxT = export_ooxml($jt, page_normalize([]));
check('Word: קו גלי / כפול, firstLine ו-hanging (start כולל את התלויה), טאב', str_contains($oxT['body'], '<w:u w:val="wave"/>') && str_contains($oxT['body'], '<w:u w:val="double"/>')
    && str_contains($oxT['body'], '<w:ind w:start="0" w:firstLine="360"/>') && str_contains($oxT['body'], '<w:ind w:start="1200" w:hanging="480"/>') && str_contains($oxT['body'], '<w:tab/>'));
$backT = import_docx(export_docx($jt, page_normalize([])), $docL)['json']['content'];
$ft = json_encode($backT, JSON_UNESCAPED_UNICODE);
check('Word → חזרה: סוגי הקו, השורה הראשונה, התלויה וההזחה, הטאב', ($backT[0]['attrs']['firstLine'] ?? 0) == 18 && ($backT[1]['attrs']['firstLine'] ?? 0) == -24 && ($backT[1]['attrs']['indent'] ?? 0) === 1
    && str_contains($ft, '"ul":"wavy"') && str_contains($ft, '"ul":"double"') && str_contains($ft, '\t'), $ft);
$hT = export_html($jt, page_normalize([]))['html'];
check('HTML: text-indent / padding לתלויה, וסוג הקו', str_contains($hT, 'text-indent:18pt') && str_contains($hT, 'text-indent:-24pt;padding-inline-start:24pt') && str_contains($hT, 'text-decoration-style:wavy'));

// סגנונות: כותרות 1–9 וסגנון של המשתמש — Word הלוך וחזור, HTML, נרמול
$pgS = page_normalize(['styles' => ['u1' => ['name' => 'הדגשה <b>', 'size' => 14, 'bold' => true, 'color' => '#C00000'], 'u2' => ['size' => 10], 'x9' => ['name' => 'רע']]]);
check('נרמול: סגנון משתמש עם שם נשמר (שם מנוקה), בלי שם / מפתח לא תקין — לא', ($pgS['styles']['u1'] ?? null) == ['name' => 'הדגשה b', 'size' => 14.0, 'bold' => true, 'color' => '#c00000']
    && !isset($pgS['styles']['u2']) && !isset($pgS['styles']['x9']) && isset($pgS['styles']['h9']), json_encode($pgS['styles']['u1'] ?? null, JSON_UNESCAPED_UNICODE));
check('נרמול: null מוחק סגנון משתמש', !isset(page_normalize(['styles' => ['u1' => null]], $pgS)['styles']['u1']));
$js = ['type' => 'doc', 'content' => [
    ['type' => 'heading', 'attrs' => ['level' => 5], 'content' => [['type' => 'text', 'text' => 'כותרת חמש']]],
    ['type' => 'heading', 'attrs' => ['level' => 8], 'content' => [['type' => 'text', 'text' => 'כותרת שמונה']]],
    ['type' => 'paragraph', 'attrs' => ['pstyle' => 'u1'], 'content' => [['type' => 'text', 'text' => 'פסקה מודגשת']]],
]];
$oxS = export_ooxml($js, $pgS);
check('Word: Heading5 / Heading8 וסגנון User1 בשם שלו', str_contains($oxS['body'], 'w:val="wpHeading5"') && str_contains($oxS['body'], 'w:val="wpUser1"')
    && str_contains($oxS['styles'], 'w:styleId="wpHeading8"') && str_contains($oxS['styles'], '<w:name w:val="wp הדגשה b"/>'));
$docSt = doc_new("c:$A", 'סגנונות', null, null, 'u1', 'דנה');
$backS = import_docx(export_docx($js, $pgS), $docSt);
check('Word → חזרה: כותרות 5 ו-8, הסגנון והפסקה שבו', ($backS['json']['content'][0]['attrs']['level'] ?? 0) === 5 && ($backS['json']['content'][1]['attrs']['level'] ?? 0) === 8
    && ($backS['json']['content'][2]['attrs']['pstyle'] ?? '') === 'u1' && ($backS['page']['styles']['u1']['name'] ?? '') === 'הדגשה b'
    && ($backS['page']['styles']['u1']['size'] ?? 0) == 14 && !empty($backS['page']['styles']['u1']['bold']), json_encode($backS['page']['styles'] ?? null, JSON_UNESCAPED_UNICODE));
$hS = export_html($js, $pgS)['html'];
check('HTML: h5, כותרת 8 כפסקה עם תפקיד, ומחלקת הסגנון עם CSS', str_contains($hS, '<h5') && str_contains($hS, 'role="heading" aria-level="8"') && str_contains($hS, 'class="wp-s-u1"')
    && str_contains(export_css($pgS), '.wp-doc .wp-s-u1{') && str_contains(export_css($pgS), '.wp-doc .wp-h8{'));

$imp = import_html('<h1>כותרת</h1><p style="text-align:center">שלום <b>עולם</b> <span style="color:#ff0000">אדום</span></p><ul><li>א</li><li>ב</li></ul><table><tr><td>1</td><td>2</td></tr></table>', $docC);
$c = $imp['json']['content'];
check('html → JSON', $c[0]['type'] === 'heading' && ($c[1]['attrs']['textAlign'] ?? '') === 'center' && $c[2]['type'] === 'bulletList' && $c[3]['type'] === 'table'
    && str_contains(json_encode($c[1], JSON_UNESCAPED_UNICODE), '"color":"#ff0000"'));

// ======================================================================
section('העתקה ומחיקה');
$copy = doc_copy(doc_find($docA, "c:$A"), "c:$A", 'תכנית 2027', [['2026', '2027'], ['תשפ"ו', 'תשפ"ז']], 'u1', 'דנה');
$cj = json_encode(doc_json(doc_find($copy, "c:$A")), JSON_UNESCAPED_UNICODE);
check('doc_copy: החלפת שנה בטקסט', str_contains($cj, 'תכנית עבודה לשנת 2027') && !str_contains($cj, '2026'));
$cm = json_decode($cj, true);
$imgId = null;
walk_nodes($cm, function ($n) use (&$imgId) { if (($n['type'] ?? '') === 'image') $imgId = $n['attrs']['media']; return $n; });
check('doc_copy: תמונה הועתקה (מזהה חדש, שייך להעתק)', $imgId && $imgId !== $media['id'] && media_of_doc($imgId, $copy) !== null);
doc_delete(doc_find($docA, "c:$A"));
check('doc_delete: המסמך והתמונות שלו נמחקו', throws(fn() => doc_find($docA, "c:$A"), 'not_found') && media_row($media['id']) === null && !is_file(media_path($media['id'], 'image/png')));
check('העותק לא נפגע', media_of_doc($imgId, $copy) !== null);

// ======================================================================
section('שלב 2: גלישת תמונות, חיתוך, סיבוב, כיתוב, ריווח תווים');
$docI = doc_new("c:$B", 'תמונות', null, null, 'u1', 'דנה');
$mi = media_store($docI, $png);
$img = fn(array $a) => ['type' => 'image', 'attrs' => ['media' => $mi['id'], 'width' => 200, 'height' => 100] + $a];
$jsonI = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'content' => [$img(['wrap' => 'tight', 'align' => 'left', 'dist' => 12]), ['type' => 'text', 'text' => 'צמוד']]],
    ['type' => 'paragraph', 'content' => [$img(['wrap' => 'topBottom', 'align' => 'center']), ['type' => 'text', 'text' => 'מעל ומתחת']]],
    ['type' => 'paragraph', 'content' => [$img(['wrap' => 'behind', 'x' => 40, 'y' => 10]), ['type' => 'text', 'text' => 'מאחורי']]],
    ['type' => 'paragraph', 'content' => [$img(['wrap' => 'front', 'x' => -5, 'y' => 0]), ['type' => 'text', 'text' => 'לפני']]],
    ['type' => 'paragraph', 'content' => [$img(['wrap' => 'inline', 'crop' => ['t' => 10, 'r' => 0, 'b' => 10, 'l' => 25], 'rotate' => 90, 'caption' => 'איור 1: מבנה הארגון'])]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'מרווח', 'marks' => [['type' => 'textStyle', 'attrs' => ['letterSpacing' => '2pt']]]],
        ['type' => 'text', 'text' => 'צפוף', 'marks' => [['type' => 'textStyle', 'attrs' => ['letterSpacing' => '-1pt']]]]]],
]];
$rowI = doc_save(doc_find($docI, "c:$B"), $jsonI, null, 'u1', 'דנה');
$ox = export_ooxml(doc_json($rowI), page_normalize([]));
$d = new DOMDocument();
check('ooxml (שלב 2): XML תקין', @$d->loadXML($wrap($ox['body'])));
check('צמוד → wrapTight עם מצולע, מרחק 12 נק׳', str_contains($ox['body'], '<wp:wrapTight wrapText="bothSides"><wp:wrapPolygon') && str_contains($ox['body'], 'distL="152400"') && str_contains($ox['body'], '<wp:align>left</wp:align>'));
check('מעל ומתחת → wrapTopAndBottom, מרחק למעלה ולמטה', str_contains($ox['body'], '<wp:wrapTopAndBottom/>') && str_contains($ox['body'], 'distT="114300" distB="114300"'));
check('מאחורי הטקסט → wrapNone + behindDoc=1 + מיקום', str_contains($ox['body'], 'behindDoc="1"') && str_contains($ox['body'], '<wp:posOffset>' . (40 * 9525) . '</wp:posOffset>'));
check('לפני הטקסט → wrapNone + behindDoc=0', substr_count($ox['body'], '<wp:wrapNone/>') === 2 && str_contains($ox['body'], '<wp:posOffset>' . (-5 * 9525) . '</wp:posOffset>'));
check('חיתוך → srcRect (אלפיות האחוז), החלק הנראה ב-extent', str_contains($ox['body'], '<a:srcRect l="25000" t="10000" r="0" b="10000"/>') && str_contains($ox['body'], '<wp:extent cx="' . (150 * 9525) . '" cy="' . (80 * 9525) . '"/>'));
check('סיבוב 90° → rot=5400000 ו-effectExtent', str_contains($ox['body'], 'rot="5400000"') && preg_match('/<wp:effectExtent l="0" t="([1-9]\d*)" r="0" b="\1"\/>/', $ox['body']) === 1);
check('כיתוב → פסקה ממורכזת אחרי התמונה', str_contains($ox['body'], ': מבנה הארגון') && str_contains($ox['body'], '</w:p><w:p><w:pPr><w:bidi/><w:spacing w:before="40" w:after="120"/><w:jc w:val="center"/>'));
check('ריווח תווים → w:spacing (גם שלילי)', str_contains($ox['body'], '<w:spacing w:val="40"/>') && str_contains($ox['body'], '<w:spacing w:val="-20"/>'));
$hI = export_html(doc_json($rowI), page_normalize([]))['html'];
check('html: כל סוגי הגלישה, חיתוך, סיבוב, כיתוב, ריווח', str_contains($hI, 'wrap-tight align-left') && str_contains($hI, 'wrap-topBottom') && str_contains($hI, 'wrap-behind')
    && str_contains($hI, 'left:40px;top:10px') && str_contains($hI, 'rotate(90deg)') && str_contains($hI, 'margin-left:-50px') && str_contains($hI, 'wp-img-cap">איור 1')
    && str_contains($hI, 'letter-spacing:2pt') && str_contains($hI, 'letter-spacing:-1pt'));
$docxI = export_docx(doc_json($rowI), page_normalize([]));
$back = import_docx($docxI, $docI);
$imgs = [];
walk_nodes($back['json'], function ($n) use (&$imgs) { if (($n['type'] ?? '') === 'image') $imgs[] = $n['attrs']; return $n; });
check('docx → JSON: כל סוגי הגלישה חוזרים', array_column($imgs, 'wrap') === ['tight', 'topBottom', 'behind', 'front', 'inline'], json_encode(array_column($imgs, 'wrap')));
check('docx → JSON: חיתוך, סיבוב, מיקום ומרחק חוזרים', ($imgs[4]['crop'] ?? null) == ['t' => 10, 'r' => 0, 'b' => 10, 'l' => 25] && ($imgs[4]['rotate'] ?? 0) === 90
    && abs($imgs[4]['width'] - 200) <= 1 && ($imgs[2]['x'] ?? null) === 40 && ($imgs[3]['x'] ?? null) === -5 && ($imgs[0]['dist'] ?? null) == 12);
check('docx → JSON: ריווח תווים חוזר', str_contains(json_encode($back['json']), '"letterSpacing":"2pt"') && str_contains(json_encode($back['json']), '"letterSpacing":"-1pt"'));

// ======================================================================
section('PDF מהשרת');
$vis = function (string $s, int $p = 1): string {
    $cps = array_map('mb_ord', mb_str_split($s));
    $lv = bidi_levels($cps, $p);
    $out = '';
    foreach (bidi_visual($lv, $cps, $p) as $i) { $ch = mb_chr($cps[$i]); $out .= $lv[$i] % 2 && isset(BIDI_MIRROR[$ch]) ? BIDI_MIRROR[$ch] : $ch; }
    return $out;
};
check('bidi: תאריך ומספר נשארים שלמים בתוך עברית', $vis('שלום 2026/01-04 עולם') === 'םלוע 2026/01-04 םולש' && $vis('סכום 250,000 ₪.') === '.₪ 250,000 םוכס');
check('bidi: סוגריים מתהפכים, אנגלית נשארת משמאל לימין', $vis('פרויקט ABC def (שלב 2)') === '(2 בלש) ABC def טקיורפ');
check('bidi: פסקה משמאל לימין עם עברית', $vis('Hello שלום world', 0) === 'Hello םולש world');
$f = FontSet::pick(FontSet::stack('David', false), 0x5D0);
check('גופן: WOFF נקרא, עברית ורוחבים', $f->has(0x5D0) && $f->unitsPerEm > 0 && $f->advance(0x5D0, 12) > 3 && substr($f->sfnt, 0, 4) === "\0\1\0\0");

$pdfOf = fn(array $j, array $p = []) => export_pdf($j, page_normalize($p), 'בדיקה');
$structOk = function (string $pdf): bool {
    if (!str_starts_with($pdf, '%PDF-1.7') || !str_ends_with(rtrim($pdf), '%%EOF')) return false;
    if (!preg_match('/startxref\n(\d+)\n/', $pdf, $m) || substr($pdf, (int)$m[1], 4) !== 'xref') return false;
    preg_match_all('/^(\d+) 0 obj$/m', $pdf, $objs, PREG_OFFSET_CAPTURE);
    preg_match('/xref\n0 (\d+)\n0000000000 65535 f \n((?:\d{10} 00000 n \n)+)/', $pdf, $x);
    $offs = array_map(fn($l) => (int)substr($l, 0, 10), array_filter(explode("\n", $x[2] ?? '')));
    foreach ($objs[1] as $k => [$num, $at]) if (($offs[(int)$num - 1] ?? -1) !== $objs[0][$k][1]) return false;
    return count($offs) === (int)$x[1] - 1;
};
$long = ['type' => 'doc', 'content' => []];
for ($i = 1; $i <= 40; $i++) $long['content'][] = ['type' => 'paragraph', 'attrs' => ['textAlign' => 'justify'], 'content' => [['type' => 'text', 'text' => str_repeat("פסקה $i עם טקסט שחוזר על עצמו כדי למלא שורות. ", 6)]]];
$long['content'][] = ['type' => 'pageBreak'];
$long['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'אחרי מעבר עמוד']]];
$pdf = $pdfOf($long, ['numbering' => ['format' => 'hebrew'], 'header' => 'כותרת עליונה']);
$pages = preg_match_all('#/Type /Page /#', $pdf);
check('PDF: מבנה תקין (xref מדויק, EOF)', $structOk($pdf));
check('PDF: מסמך ארוך → כמה עמודים, ומעבר עמוד ידני פותח עמוד', $pages >= 4, (string)$pages);
check('PDF: גופן מוטמע (Type0 + FontFile2) עם ToUnicode', str_contains($pdf, '/Subtype /Type0') && str_contains($pdf, '/FontFile2') && str_contains($pdf, '/ToUnicode'));
check('PDF: כיוון קריאה מימין לשמאל ושפה עברית', str_contains($pdf, '/Direction /R2L') && str_contains($pdf, '/Lang (he-IL)'));
if (trim((string)shell_exec('command -v pdftotext'))) {
    $tmpPdf = "$tmp/t.pdf";
    file_put_contents($tmpPdf, $pdf);
    // pdftotext מוסיף סימני כיוון (U+202A–202C) בין עברית לספרות — מסירים
    $clean = fn($t) => preg_replace('/[\x{202A}-\x{202E}]/u', '', (string)$t);
    $p1 = $clean(shell_exec('pdftotext -f 1 -l 1 ' . escapeshellarg($tmpPdf) . ' - 2>&1'));
    $pl = $clean(shell_exec('pdftotext -f ' . $pages . ' -l ' . $pages . ' ' . escapeshellarg($tmpPdf) . ' - 2>&1'));
    check('PDF (pdftotext): העברית ניתנת לחילוץ, מספור עמודים באותיות', preg_match('/פסקה\s+1/u', $p1) === 1 && str_contains($p1, 'כותרת עליונה') && preg_match('/^\s*א\s*$/mu', $p1) === 1);
    check('PDF (pdftotext): העמוד האחרון — אחרי מעבר העמוד בלבד', str_contains($pl, 'אחרי מעבר עמוד') && !str_contains($pl, 'פסקה 40'));
} else {
    echo "  · pdftotext לא מותקן — דילוג על חילוץ הטקסט\n";
}

// שבירת עמודים: שמירה עם הבא / שורות יחד / מעבר עמוד לפני — Word הלוך וחזור, HTML, PDF
$pk = fn($t, $a = []) => ['type' => 'paragraph', 'attrs' => $a, 'content' => [['type' => 'text', 'text' => $t]]];
$jk = ['type' => 'doc', 'content' => [$pk('ראשונה', ['keepNext' => true, 'keepLines' => true]), $pk('שנייה', ['breakBefore' => true])]];
$oxK = export_ooxml($jk, page_normalize([]));
check('Word: keepNext, keepLines, pageBreakBefore — לפני bidi', str_contains($oxK['body'], '<w:pPr><w:keepNext/><w:keepLines/><w:bidi/>') && str_contains($oxK['body'], '<w:pPr><w:pageBreakBefore/><w:bidi/>'));
$backK = import_docx(export_docx($jk, page_normalize([])), $docL)['json']['content'];
check('Word → חזרה: שלוש ההגדרות', !empty($backK[0]['attrs']['keepNext']) && !empty($backK[0]['attrs']['keepLines']) && empty($backK[0]['attrs']['breakBefore'])
    && !empty($backK[1]['attrs']['breakBefore']) && empty($backK[1]['attrs']['keepNext']), json_encode($backK, JSON_UNESCAPED_UNICODE));
$hK = export_html($jk, page_normalize([]))['html'];
check('HTML: break-after / break-inside / break-before', str_contains($hK, 'break-after:avoid') && str_contains($hK, 'break-inside:avoid') && str_contains($hK, 'break-before:page'));
check('PDF: מעבר עמוד לפני — שני עמודים; בלי — אחד', preg_match_all('#/Type /Page /#', $pdfOf($jk)) === 2
    && preg_match_all('#/Type /Page /#', $pdfOf(['type' => 'doc', 'content' => [$pk('ראשונה'), $pk('שנייה')]])) === 1);

// עיצוב טבלה: גבולות וצבע, רוחב ויישור, יישור אנכי, גובה שורה — Word הלוך וחזור, HTML, PDF
$tc = fn($t, $a = []) => ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1] + $a, 'content' => [$pk($t)]];
$jt2 = ['type' => 'doc', 'content' => [['type' => 'table', 'attrs' => ['borders' => 'outer', 'borderColor' => '#c00000', 'width' => 60, 'align' => 'center'], 'content' => [
    ['type' => 'tableRow', 'content' => [$tc('א'), $tc('ב')]],
    ['type' => 'tableRow', 'attrs' => ['height' => 40], 'content' => [$tc('ג', ['valign' => 'middle']), $tc('ד', ['valign' => 'bottom'])]],
]]]];
$oxB = export_ooxml($jt2, page_normalize([]));
check('Word: מסגרת בצבע (פנימיים nil), jc מרכז, trHeight, vAlign', str_contains($oxB['body'], '<w:top w:val="single" w:sz="4" w:space="0" w:color="C00000"/>') && str_contains($oxB['body'], '<w:insideH w:val="nil"/>')
    && str_contains($oxB['body'], '<w:jc w:val="center"/>') && str_contains($oxB['body'], '<w:trHeight w:val="800" w:hRule="atLeast"/>') && str_contains($oxB['body'], '<w:vAlign w:val="center"/>'));
$backB = import_docx(export_docx($jt2, page_normalize([])), $docL)['json']['content'][0];
check('Word → חזרה: גבולות, צבע, 60%, מרכז, גובה, יישור אנכי', ($backB['attrs'] ?? []) == ['borders' => 'outer', 'borderColor' => '#c00000', 'width' => 60, 'align' => 'center']
    && ($backB['content'][1]['attrs']['height'] ?? 0) == 40 && ($backB['content'][1]['content'][0]['attrs']['valign'] ?? '') === 'middle' && ($backB['content'][1]['content'][1]['attrs']['valign'] ?? '') === 'bottom',
    json_encode([$backB['attrs'] ?? null, $backB['content'][1]['attrs'] ?? null], JSON_UNESCAPED_UNICODE));
$backPlain = import_docx(export_docx(['type' => 'doc', 'content' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [$tc('א')]]]]]], page_normalize([])), $docL)['json']['content'][0];
check('Word → חזרה: טבלה רגילה — בלי תכונות', empty($backPlain['attrs']), json_encode($backPlain['attrs'] ?? null));
$hB = export_html($jt2, page_normalize([]))['html'];
check('HTML: data-borders, צבע, רוחב, גובה, יישור אנכי', str_contains($hB, 'data-borders="outer"') && stripos($hB, '--wpt-bc:#c00000') !== false && str_contains($hB, 'width:60%')
    && str_contains($hB, 'height:40pt') && str_contains($hB, 'vertical-align:middle'));
$tall = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => array_map(fn($i) => ['type' => 'tableRow', 'attrs' => ['height' => 400], 'content' => [$tc("שורה $i")]], [1, 2, 3])]]];
check('PDF: גובה שורה — שלוש שורות של 400 נק\' (עמוד ~700) בשלושה עמודים', preg_match_all('#/Type /Page /#', $pdfOf($tall)) === 3);

// מספור: רב-רמתי 1.1.1, המשך מספור, מספור כותרות — PDF, Word הלוך וחזור, HTML
$li = fn($t, $sub = null) => ['type' => 'listItem', 'content' => array_merge([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t]]]], $sub ? [$sub] : [])];
$jn = ['type' => 'doc', 'content' => [
    ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'מבוא']]],
    ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'רקע']]],
    ['type' => 'orderedList', 'attrs' => ['listType' => 'multi', 'start' => 1], 'content' => [
        $li('ראשון', ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [$li('תת א'), $li('תת ב')]]), $li('שני')]],
    ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'פירוט']]],
    ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [$li('א1'), $li('א2')]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'באמצע']]],
    ['type' => 'orderedList', 'attrs' => ['start' => 3, 'cont' => true], 'content' => [$li('א3')]],
    ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'סיכום']]],
]];
$pgN = page_normalize(['heading_numbers' => true]);
check('מספור כותרות: 1. / 1.1 / 1.1.1 / 2.', array_column(array_column(array_filter(heading_numbers($jn)['content'], fn($b) => $b['type'] === 'heading'), 'attrs'), '_hnum') === ['1. ', '1.1 ', '1.1.1 ', '2. ']);
if (trim((string)shell_exec('command -v pdftotext'))) {
    file_put_contents("$tmp/n.pdf", export_pdf($jn, $pgN, 'מספור'));
    $tn = $clean(shell_exec('pdftotext -layout ' . escapeshellarg("$tmp/n.pdf") . ' - 2>&1'));
    // pdftotext מציג מספר בשורה מימין לשמאל עם הנקודה משמאל (".1") — כך הוא נראה בעמוד
    $tn1 = preg_replace('/\s+/u', '', $tn);
    check('PDF: מספרי הכותרות לפני הטקסט, 1.1 / 1.2 ברשימה הרב-רמתית, והמשך מספור (3.)', str_contains($tn1, '.1מבוא') && str_contains($tn1, '1.1רקע') && str_contains($tn1, '1.1.1פירוט')
        && str_contains($tn1, '.1.1תתא') && str_contains($tn1, '.1.2תתב') && str_contains($tn1, '.2שני') && str_contains($tn1, '.3א3') && str_contains($tn1, '.2סיכום'), $tn);
}
$oxN = export_ooxml($jn, $pgN);
check('Word: רב-רמתי — %1.%2. ורשימה פנימית באותו numId ברמה 1', str_contains($oxN['numbering'], 'w:val="%1.%2."')
    && preg_match('/<w:ilvl w:val="1"\/><w:numId w:val="(\d+)"\/>.*?/s', $oxN['body'], $m1) === 1 && substr_count($oxN['body'], '<w:numId w:val="' . $m1[1] . '"/>') === 4);
preg_match_all('/<w:numId w:val="(\d+)"\/>/', $oxN['body'], $all);
check('Word: המשך מספור — אותו numId כמו הרשימה הקודמת', count(array_unique(array_slice($all[1], -3))) === 1, implode(',', $all[1]));
check('Word: מספור כותרות — הסגנונות מקושרים, %1. ו-%1.%2 עם רווח', str_contains($oxN['styles'], '<w:numPr><w:ilvl w:val="0"/>') && str_contains($oxN['numbering'], '<w:pStyle w:val="wpHeading2"/><w:suff w:val="space"/><w:lvlText w:val="%1.%2"/>'));
$backN = import_docx(export_docx($jn, $pgN), $docSt);
$ols = array_values(array_filter($backN['json']['content'], fn($b) => $b['type'] === 'orderedList'));
check('Word → חזרה: רב-רמתי, המשך מספור מ-3, ומספור כותרות', ($ols[0]['attrs']['listType'] ?? '') === 'multi' && !empty($ols[2]['attrs']['cont']) && ($ols[2]['attrs']['start'] ?? 0) === 3
    && !empty($backN['page']['heading_numbers']), json_encode(array_column($ols, 'attrs')) . ' ' . json_encode($backN['page']['heading_numbers'] ?? null));
check('HTML: מוני כותרות ו-1.1.1', str_contains(export_css($pgN), '.wp-doc h2::before{content:counter(wh1) "." counter(wh2) " "}') && str_contains(export_css($pgN), 'counters(wpl,".")'));
$pdfImg = $pdfOf(doc_json($rowI));
$ops = gzuncompress_pages($pdfImg);
check('PDF: תמונות (XObject), חיתוך כ-clip, סיבוב 90° עם כיוון השעון', $structOk($pdfImg) && str_contains($pdfImg, '/Subtype /Image') && str_contains($ops, ' re W n')
    && preg_match('/\n0 -1 1 0 [\d.]+ [\d.]+ cm/', $ops) === 1, substr($ops, 0, 0));
$tbl = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => array_merge(
    [['type' => 'tableRow', 'content' => [['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'כותרת הטבלה']]]]]]]],
    array_map(fn($i) => ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "שורה $i"]]]]]]], range(1, 80)))]]];
$pdfT = $pdfOf($tbl);
$pt = preg_match_all('#/Type /Page /#', $pdfT);
check('PDF: טבלה ארוכה נשברת בין שורות, ושורת הכותרת חוזרת בכל עמוד', $pt >= 2 && substr_count(gzuncompress_pages($pdfT), '0.95 0.95 0.95 rg') >= $pt, "$pt עמודים");

// ======================================================================
section('הערת שוליים עם עיצוב (attrs.content)');
$fnDoc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
    ['type' => 'text', 'text' => 'טקסט'],
    ['type' => 'footnote', 'attrs' => ['text' => 'רגיל מודגש נטוי', 'content' => [
        ['type' => 'text', 'text' => 'רגיל '], ['type' => 'text', 'text' => 'מודגש', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' '], ['type' => 'text', 'text' => 'נטוי', 'marks' => [['type' => 'italic'], ['type' => 'link', 'attrs' => ['href' => 'x']]]],
        ['type' => 'image', 'attrs' => []]]]],
]]]];
check('fn_inline: רק צמתי text ורק עיצוב מותר', fn_inline($fnDoc['content'][0]['content'][1]['attrs']) === [
    ['type' => 'text', 'text' => 'רגיל '], ['type' => 'text', 'text' => 'מודגש', 'marks' => [['type' => 'bold']]],
    ['type' => 'text', 'text' => ' '], ['type' => 'text', 'text' => 'נטוי', 'marks' => [['type' => 'italic']]]]);
check('fn_inline: בלי content — הטקסט הפשוט', fn_inline(['text' => ' רק טקסט ']) === [['type' => 'text', 'text' => 'רק טקסט']]);
$fnDocx = export_docx($fnDoc, page_normalize([]), 'הערה');
$zip = new ZipArchive();
file_put_contents("$tmp/fn.docx", $fnDocx);
$zip->open("$tmp/fn.docx");
$fnXml = $zip->getFromName('word/footnotes.xml');
$zip->close();
check('Word: ההערה עם מודגש ונטוי (w:b, w:i)', str_contains($fnXml, '<w:b/>') && str_contains($fnXml, '<w:i/>') && str_contains($fnXml, 'מודגש'));
$fnBack = import_docx($fnDocx, 'fn');
$fnAttrs = null;
$walkFn = function ($n) use (&$walkFn, &$fnAttrs) { if (($n['type'] ?? '') === 'footnote') $fnAttrs = $n['attrs']; foreach ($n['content'] ?? [] as $c) $walkFn($c); };
$walkFn($fnBack['json']);
check('Word הלוך וחזור: הטקסט והעיצוב של ההערה חוזרים', ($fnAttrs['text'] ?? '') === 'רגיל מודגש נטוי'
    && fn_inline($fnAttrs) == [['type' => 'text', 'text' => 'רגיל '], ['type' => 'text', 'text' => 'מודגש', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' '], ['type' => 'text', 'text' => 'נטוי', 'marks' => [['type' => 'italic']]]], json_encode($fnAttrs, JSON_UNESCAPED_UNICODE));
$fnBody = export_ooxml($fnDoc, page_normalize([]))['body'];
check('גוף לשילוב: ההערה בסוגריים, עילית, עם המודגש', str_contains($fnBody, 'מודגש') && preg_match('#<w:b/>.*?<w:vertAlign w:val="superscript"/>.*?מודגש#s', $fnBody) === 1);
$fnPdf = $pdfOf($fnDoc);
check('PDF: ההערה עם גופן מודגש ונטוי', str_contains($fnPdf, 'BaseFont') && preg_match('#/BaseFont /\w+\+[\w-]*(?:700|Bold)#', $fnPdf) === 1, '');

// ======================================================================
section('הגדרות עמוד');
$p = page_normalize(['size' => 'Legal', 'orientation' => 'landscape', 'margins' => ['top' => 500, 'left' => 'x'], 'font' => ['family' => 'David<script>', 'size' => 200],
    'styles' => ['h1' => ['size' => 18, 'color' => 'red', 'underline' => true]], 'numbering' => ['format' => 'bad', 'align' => 'left']]);
check('ערכים לא תקינים נדחים / נחתכים', $p['size'] === 'A4' && $p['orientation'] === 'landscape' && $p['margins']['top'] == 80 && $p['margins']['left'] == 25
    && $p['font']['family'] === 'Davidscript' && $p['font']['size'] == 72 && $p['styles']['h1']['size'] == 18 && !isset($p['styles']['h1']['color'])
    && $p['styles']['h1']['underline'] === true && $p['numbering']['format'] === 'n' && $p['numbering']['align'] === 'left');
check('גודל לרוחב', page_mm($p) === [297, 210]);

// ======================================================================
section('סל המחזור (ליקוי ה\')');
$sp = 'a:trash-test';
$tid = doc_new($sp, 'לסל', null, null, 'u1', 'דנה');
doc_trash(doc_find($tid, $sp));
$gone = false;
try { doc_find($tid, $sp); } catch (ApiError $e) { $gone = $e->getMessage() === 'המסמך לא נמצא'; }
check('מסמך בסל — לא נפתח, לא נשמר ולא מיוצא (לא נמצא)', $gone);
check('מסמך בסל — נמצא כשמחפשים בסל', doc_find($tid, $sp, true)['id'] === $tid);
doc_restore(doc_find($tid, $sp, true));
check('שחזור — המסמך חוזר', doc_find($tid, $sp)['title'] === 'לסל');
doc_trash(doc_find($tid, $sp));
db()->prepare('UPDATE docs SET deleted_at = ? WHERE id = ?')->execute([time() - (TRASH_DAYS + 1) * 86400, $tid]);
$keep = doc_new($sp, 'נשאר בסל', null, null, 'u1', 'דנה');
doc_trash(doc_find($keep, $sp));
trash_purge($sp);
$cnt = fn($id) => (int)db()->query("SELECT COUNT(*) FROM docs WHERE id = " . db()->quote($id))->fetchColumn();
check('אחרי ' . TRASH_DAYS . ' יום — נמחק לצמיתות; מסמך שנמחק היום — נשאר בסל', $cnt($tid) === 0 && $cnt($keep) === 1);
doc_delete(doc_find($keep, $sp, true));
check('מחיקה לצמיתות מהסל', $cnt($keep) === 0);
// מסד ישן (גרסה 1, בלי העמודה) — ההסבה מוסיפה אותה, והמסמכים נשארים
$old = new PDO('sqlite::memory:');
$old->exec("CREATE TABLE docs (id TEXT PRIMARY KEY, space TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', json TEXT NOT NULL, page TEXT NOT NULL DEFAULT '{}',
    version INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, updated_by TEXT NOT NULL DEFAULT '', updated_by_id TEXT NOT NULL DEFAULT '');
    INSERT INTO docs (id, space, json, created_at, updated_at) VALUES ('old1', 'a:1', '{}', 1, 1); PRAGMA user_version = 1;");
wp_migrate($old);
$cols = array_column($old->query('PRAGMA table_info(docs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check('הסבה של מסד ישן: עמודת deleted_at נוספה, המסמך נשאר', in_array('deleted_at', $cols, true)
    && $old->query("SELECT COUNT(*) FROM docs WHERE id = 'old1' AND deleted_at IS NULL")->fetchColumn() == 1);

// ======================================================================
section('שמירה אטומית וגרסת מבנה');
$docS = doc_new('a:99', 'אטומי', null, null, 'u1', 'דנה');
$rowS = doc_find($docS, 'a:99');
check('מסמך חדש נשמר עם attrs.schema', (doc_json($rowS)['attrs']['schema'] ?? 0) === SCHEMA_VERSION);
$j = ['type' => 'doc', 'attrs' => ['schema' => 1, 'page' => ['size' => 'Letter', 'orientation' => 'landscape']], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'שלום']]]]];
$v0 = (int)$rowS['version'];
$rowS = doc_save($rowS, $j, null, 'u1', 'דנה', $v0);
check('הגדרות העמוד שבתוך המסמך נשמרות באותה גרסה', (int)$rowS['version'] === $v0 + 1 && doc_page($rowS)['size'] === 'Letter' && doc_page($rowS)['orientation'] === 'landscape');
check('…ולא נשארות כפולות בתוך ה-JSON השמור', !isset(doc_json($rowS)['attrs']['page']));
$vs = versions_list($rowS);
$vrow = version_get($rowS, $vs[0]['id']);
check('הגרסה השמורה כוללת את הגדרות העמוד', json_decode($vrow['page'], true)['size'] === 'Letter');
$ej = editor_json(doc_json($rowS), doc_page($rowS));
check('לעורך: הגדרות העמוד מוחזרות בתוך המסמך', ($ej['attrs']['page']['size'] ?? '') === 'Letter' && $ej['attrs']['schema'] === SCHEMA_VERSION);
$old = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
check('מסמך ישן (בלי attrs) מומר', doc_migrate($old)['attrs']['schema'] === SCHEMA_VERSION);
$rowS = doc_save($rowS, $old, null, 'u1', 'דנה');
check('שמירה מלשונית ישנה (בלי הגדרות עמוד בפנים) משאירה את ההגדרות', doc_page($rowS)['size'] === 'Letter');
check('מבנה חדש יותר מהשרת → schema_newer', throws(fn() => doc_save($rowS, ['type' => 'doc', 'attrs' => ['schema' => SCHEMA_VERSION + 1], 'content' => []], null, 'u1', 'דנה'), 'schema_newer'));
check('ייצוא Word ממסמך עם attrs', strlen(export_docx(doc_json($rowS), doc_page($rowS))) > 1000);

// ======================================================================
section('אוסף המסמכים (tests/corpus) — Word הלוך וחזור');
$words = fn(string $s) => preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s)), -1, PREG_SPLIT_NO_EMPTY);
foreach (glob(__DIR__ . '/corpus/*.json') as $f) {
    $c = json_decode(file_get_contents($f), true);
    $page = page_normalize($c['page'] ?? []);
    $docx = export_docx($c['json'], $page, $c['title']);
    $zip = new ZipArchive();
    $tmpDocx = "$tmp/corpus.docx";
    file_put_contents($tmpDocx, $docx);
    $bad = [];
    if ($zip->open($tmpDocx) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!preg_match('/\.(xml|rels)$/', $name)) continue;
            $x = new DOMDocument();
            if (!@$x->loadXML($zip->getFromIndex($i))) $bad[] = $name;
        }
        $zip->close();
    } else $bad[] = 'zip';
    $back = import_docx($docx, 'corpus');
    $a = $words(export_text($c['json']));
    $b = $words(export_text($back['json']));
    $bp = page_normalize($back['page'] ?? []);
    // מעברי מקטע (עם הכיוון) והערות שוליים (עם הטקסט) — חוזרים כמו שהיו
    $marks = function (array $json) {
        $out = [];
        $walk = function ($n) use (&$walk, &$out) {
            if (($n['type'] ?? '') === 'sectionBreak') $out[] = 'S:' . ($n['attrs']['page']['orientation'] ?? '');
            if (($n['type'] ?? '') === 'footnote') $out[] = 'F:' . trim($n['attrs']['text'] ?? '');
            foreach ($n['content'] ?? [] as $ch) $walk($ch);
        };
        $walk($json);
        return $out;
    };
    $m1 = $marks($c['json']);
    $m2 = $marks($back['json']);
    if ($m1) check("{$c['title']}: מעברי מקטע והערות שוליים חוזרים מ-Word", $m1 === $m2, count($m1) . ' מול ' . count($m2) . ($m1 !== $m2 ? ' — ' . json_encode(array_slice(array_values(array_diff_assoc($m1, $m2)), 0, 2), JSON_UNESCAPED_UNICODE) : ''));
    check("{$c['title']}: Word תקין, וחוזר עם אותו טקסט ואותן הגדרות עמוד",
        !$bad && $a === $b && $bp['size'] === $page['size'] && $bp['orientation'] === $page['orientation'] && $bp['margins'] == $page['margins'],
        $bad ? 'XML שבור: ' . implode(', ', $bad) : (count($a) . ' מילים מול ' . count($b) . ($a !== $b ? ' — מילה ראשונה שונה: ' . json_encode(array_values(array_diff_assoc($a, $b))[0] ?? '', JSON_UNESCAPED_UNICODE) : '')));
}

// ניקוי
array_map('unlink', glob("$tmp/media/*") ?: []);
@rmdir("$tmp/media");
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);

echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
