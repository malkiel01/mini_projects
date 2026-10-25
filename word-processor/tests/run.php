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

// שחזור גרסה (ביקורת 03 / 04, F06 / R07): עריכה, שמירה ושחזור בתוך 10 דקות — גם המצב שלפני וגם המשוחזר נשארים
$docR = doc_new("c:$A", 'שחזור', null, null, 'u1', 'דנה');
$rowR = doc_find($docR, "c:$A");
$txt = fn($t) => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t]]]]];
$rowR = doc_save($rowR, $txt('ראשון'), null, 'u1', 'דנה', (int)$rowR['version']);
db()->prepare('UPDATE versions SET started_at = started_at - 700 WHERE doc = ?')->execute([$docR]);   // "ראשון" — גרסה סגורה
$rowR = doc_save($rowR, $txt('שני'), null, 'u1', 'דנה', (int)$rowR['version']);
$rowR = doc_save($rowR, $txt('שלישי'), null, 'u1', 'דנה', (int)$rowR['version']);   // מתאחד עם "שני" (אותו משתמש, 10 דקות)
$vsR = versions_list($rowR);
$first = end($vsR)['id'];
check('לפני השחזור: "ראשון" ו"שלישי" (שני ושלישי מאוחדים)', count($vsR) === 2);
check('שחזור על בסיס ישן → התנגשות', throws(fn() => doc_restore_version($rowR, $first, 'u1', 'דנה', (int)$rowR['version'] - 1), 'conflict'));
$rowR = doc_restore_version($rowR, $first, 'u1', 'דנה', (int)$rowR['version']);
$texts = fn() => array_map(fn($v) => export_text(json_decode(version_get($rowR, $v['id'])['json'], true)), versions_list($rowR));
check('שחזור: התוכן הוא "ראשון", והמצב שלפני ("שלישי") נשמר כגרסה נפרדת', export_text(doc_json($rowR)) === 'ראשון' && $texts() === ['ראשון', 'שלישי', 'ראשון'], json_encode($texts(), JSON_UNESCAPED_UNICODE));
$rowR = doc_save($rowR, $txt('אחרי השחזור'), null, 'u1', 'דנה', (int)$rowR['version']);
check('שמירה מיד אחרי השחזור — גרסה חדשה, לא דורסת את המשוחזר', $texts() === ['אחרי השחזור', 'ראשון', 'שלישי', 'ראשון'], json_encode($texts(), JSON_UNESCAPED_UNICODE));
$rowR = doc_save($rowR, $txt('אחרי השחזור, עוד'), null, 'u1', 'דנה', (int)$rowR['version']);
check('ושמירה נוספת — מתאחדת כרגיל (רק לא לתוך השחזור / המצב שלפניו)', count(versions_list($rowR)) === 4 && $texts()[0] === 'אחרי השחזור, עוד');

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
    . '<w:sectPr><w:headerReference w:type="default" r:id="rH"/><w:headerReference w:type="first" r:id="rE"/><w:footerReference w:type="default" r:id="rF"/>'
    . '<w:pgSz w:w="11906" w:h="16838"/><w:cols w:num="2"/></w:sectPr>';
$hdr = fn($tag, $inner) => '<w:' . $tag . ' ' . $WN . '>' . $inner . '</w:' . $tag . '>';
$wordDocx = $mkDocx([
    '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
    'word/document.xml' => '<w:document ' . $WN . '><w:body>' . $body . '</w:body></w:document>',
    'word/styles.xml' => '<w:styles ' . $WN . '><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:sz w:val="24"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="ציטוט"/><w:pPr><w:jc w:val="center"/><w:spacing w:after="240"/></w:pPr><w:rPr><w:i/><w:color w:val="595959"/></w:rPr></w:style>'
        . '<w:style w:type="character" w:styleId="Emph"><w:name w:val="Emph"/><w:rPr><w:b/></w:rPr></w:style></w:styles>',
    'word/endnotes.xml' => '<w:endnotes ' . $WN . '><w:endnote w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:endnote>'
        . '<w:endnote w:id="1"><w:p><w:r><w:endnoteRef/></w:r><w:r><w:t xml:space="preserve"> מקור </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>חשוב</w:t></w:r></w:p></w:endnote></w:endnotes>',
    'word/comments.xml' => '<w:comments ' . $WN . '><w:comment w:id="0"><w:p>' . $run('הערה') . '</w:p></w:comment></w:comments>',
    'word/_rels/document.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rH" Target="header1.xml"/><Relationship Id="rE" Target="header2.xml"/><Relationship Id="rF" Target="footer1.xml"/></Relationships>',
    'word/header1.xml' => $hdr('hdr', '<w:p><w:pPr><w:bidi/></w:pPr>' . $run('חברה בע"מ') . '</w:p>'),
    'word/header2.xml' => $hdr('hdr', '<w:p>' . $run('ראשון שונה') . '</w:p>'),   // לעמוד הראשון, בלי titlePg — Word לא מציג אותה, ולא נכנסת (וגם לא לדוח)
    'word/footer1.xml' => $hdr('ftr', '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/></w:pPr><w:r><w:t xml:space="preserve">- </w:t></w:r><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r><w:r><w:t xml:space="preserve"> -</w:t></w:r></w:p>'),
]);
$w = import_docx($wordDocx, $docC);
$rep = array_column($w['report'], 'count', 'key');
check('דוח ייבוא: כל מה שלא נתמך מופיע (וטורים — כבר נתמכים, לא בדוח)', $rep == ['shapes' => 1, 'math' => 1, 'nested' => 1, 'fields' => 1,
    'styles' => 1, 'pborder' => 1, 'fonts' => 1], json_encode($rep));
check('ייבוא: סגנון פסקה מותאם → סגנון שלנו (שם ועיצוב), והפסקה בו', ($w['page']['styles']['u1'] ?? null) == ['name' => 'ציטוט', 'italic' => true, 'color' => '#595959', 'align' => 'center', 'after' => 12.0]
    && ($w['json']['content'][0]['attrs']['pstyle'] ?? '') === 'u1', json_encode($w['page']['styles'] ?? null, JSON_UNESCAPED_UNICODE));
$fontRow = array_values(array_filter($w['report'], fn($r) => $r['key'] === 'fonts'))[0] ?? [];
check('דוח ייבוא: שם הגופן החסר ומה מוצג במקומו', str_contains($fontRow['detail'] ?? '', 'Segoe UI') && str_contains($fontRow['detail'] ?? '', 'Heebo'));
$wt = export_text($w['json']);
check('ייבוא: הטקסט שמסביב נשמר; שדה — תוצאתו; תוספת ומחיקה במעקב — בטקסט הסופי נמחק לא מופיע', str_contains($wt, 'לפני  אחרי') && str_contains($wt, '22/10/2026 נוסף') && !str_contains($wt, 'נמחק') && str_contains($wt, 'תא'), $wt);
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
// רשימת תבליטים ומיד אחריה רשימה ממוספרת (ובתוך פריט — תת-רשימה ממוספרת ואחריה תבליטים) — כל אחת רשימה משלה
$li = fn(string $t, array $sub = []) => ['type' => 'listItem', 'content' => array_merge([para([text_node($t)])], $sub)];
$jm = ['type' => 'doc', 'content' => [
    ['type' => 'bulletList', 'content' => [$li('תבליט א'), $li('תבליט ב', [['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [$li('פנימי 1')]], ['type' => 'bulletList', 'content' => [$li('פנימי •')]]])]],
    ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [$li('מספר 1'), $li('מספר 2')]],
]];
$backM = import_docx(export_docx($jm, page_normalize([])), $docL)['json']['content'];
$inner = array_map(fn($b) => $b['type'], array_slice($backM[0]['content'][1]['content'] ?? [], 1));
check('Word → חזרה: תבליטים ואחריהם רשימה ממוספרת — שתי רשימות (לא מתאחדות); גם בתוך פריט', array_column($backM, 'type') === ['bulletList', 'orderedList']
    && count($backM[0]['content']) === 2 && count($backM[1]['content']) === 2 && $inner === ['orderedList', 'bulletList'],
    json_encode([array_column($backM, 'type'), $inner], JSON_UNESCAPED_UNICODE));
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

// רקע הדף: צבע עמוד וסימן מים — נרמול, Word הלוך וחזור, PDF
$pgW = page_normalize(['page_color' => '#FDF6E3', 'watermark' => "  טיוטה\n "]);
check('נרמול: צבע עמוד (אותיות קטנות), סימן מים מנוקה; לבן / לא תקין — בלי', $pgW['page_color'] === '#fdf6e3' && $pgW['watermark'] === 'טיוטה'
    && page_normalize(['page_color' => '#ffffff'])['page_color'] === '' && page_normalize(['page_color' => 'red'])['page_color'] === '');
check('גודל סימן המים: קצר — 120, ארוך — קטן יותר, לא פחות מ-24', watermark_size('טיוטה', 595, 842) == 120 && watermark_size(str_repeat('א', 30), 595, 842) < 60 && watermark_size(str_repeat('א', 40), 300, 300) == 24);
$dxW = export_docx(['type' => 'doc', 'content' => [$pk('טקסט')]], $pgW);
$zW = new ZipArchive(); $tW = tempnam(sys_get_temp_dir(), 'wm'); file_put_contents($tW, $dxW); $zW->open($tW);
$hdrW = $zW->getFromName('word/header1.xml');
check('Word: w:background, displayBackgroundShape, סימן מים (textpath) בכותרת — XML תקין',
    str_contains($zW->getFromName('word/document.xml'), '<w:background w:color="FDF6E3"/>') && str_contains($zW->getFromName('word/settings.xml'), '<w:displayBackgroundShape/>')
    && str_contains($hdrW, 'string="טיוטה"') && str_contains($hdrW, 'rotation:315') && (new DOMDocument())->loadXML($hdrW));
$zW->close(); unlink($tW);
$backW = import_docx($dxW, $docL);
check('Word → חזרה: צבע העמוד וסימן המים; הכותרת לא "מורכבת"', ($backW['page']['page_color'] ?? '') === '#fdf6e3' && ($backW['page']['watermark'] ?? '') === 'טיוטה'
    && !in_array('hf_complex', array_column($backW['report'], 'key'), true), json_encode($backW['page'], JSON_UNESCAPED_UNICODE));
$pdfW = gzuncompress_pages($pdfOf(['type' => 'doc', 'content' => [$pk('טקסט')]], ['page_color' => '#fdf6e3', 'watermark' => 'טיוטה']));
check('PDF: מלבן הרקע בצבע העמוד, והסימן מסובב 45°', str_contains($pdfW, '0.99 0.96 0.89 rg 0 0 595.28 841.89 re f') && str_contains($pdfW, '0.71 0.71 -0.71 0.71'));

// שכבות של תמונות (מאחורי / לפני הטקסט): PDF לפי z, Word (relativeHeight) הלוך וחזור
$pngOf = function (array $c): string { $g = imagecreatetruecolor(2, 2); imagefill($g, 0, 0, imagecolorallocate($g, ...$c)); ob_start(); imagepng($g); return ob_get_clean(); };
$docZ = doc_new("c:$A", 'שכבות', null, null, 'u1', 'דנה');
$mR = media_store($docZ, $pngOf([255, 0, 0]))['id']; $mB = media_store($docZ, $pngOf([0, 0, 255]))['id'];
$imgL = fn($z, $c) => ['type' => 'image', 'attrs' => ['media' => $c === [255, 0, 0] ? $mR : $mB, 'width' => 100, 'height' => 100, 'wrap' => 'front', 'x' => 0, 'y' => 0, 'z' => $z]];
$jL = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$imgL(1, [255, 0, 0]), $imgL(0, [0, 0, 255]), ['type' => 'text', 'text' => 'שכבות']]]]];
$pdfL = gzuncompress_pages($pdfOf($jL));
preg_match_all('#(/Im\d+) Do#', $pdfL, $mL);
// הכחולה (z 0, שנייה במסמך) נרשמת ראשונה ב-PDF — Im2 לפני Im1; האדומה (z 1) — אחריה, כלומר מעליה
check('PDF: התמונה עם z גבוה מצוירת אחרונה (מעל), גם כשהיא ראשונה במסמך', $mL[1] === ['/Im2', '/Im1'], implode(',', $mL[1]));
$backL = import_docx(export_docx($jL, page_normalize([])), $docL)['json']['content'][0]['content'];
check('Word → חזרה: סדר השכבות נשמר (הראשונה במסמך — מעל)', ($backL[0]['attrs']['z'] ?? 0) > ($backL[1]['attrs']['z'] ?? 0) && !isset($backL[0]['attrs']['_rh']), json_encode(array_map(fn($n) => $n['attrs']['z'] ?? null, $backL)));
$backL2 = import_docx(export_docx(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$imgL(0, [255, 0, 0]), $imgL(0, [0, 0, 255]), ['type' => 'text', 'text' => 'שכבות']]]]], page_normalize([])), $docL)['json']['content'][0]['content'];
check('Word → חזרה: בסדר הרגיל — בלי z', empty($backL2[0]['attrs']['z']) && empty($backL2[1]['attrs']['z']));

// תמונה מעוגנת לעמוד (anchor: page): x, y מפינת העמוד — ב-PDF, ב-Word (relativeFrom="page") הלוך וחזור, וגרסת מבנה 9
$imgP = fn(?string $anchor) => ['type' => 'image', 'attrs' => ['media' => $mR, 'width' => 100, 'height' => 100, 'wrap' => 'behind', 'x' => 40, 'y' => 60] + ($anchor ? ['anchor' => $anchor] : [])];
$jP = fn(?string $anchor) => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ראשונה']]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'שנייה']]], ['type' => 'paragraph', 'content' => [$imgP($anchor), ['type' => 'text', 'text' => 'עם תמונה']]]]];
// מרכז התמונה: x 40px + חצי רוחב 50px = 67.5 נק' משמאל; y 60px + 50px = 82.5 נק' מלמעלה → 841.89 − 82.5 = 759.39
$pdfP = gzuncompress_pages($pdfOf($jP('page')));
$pdfQ = gzuncompress_pages($pdfOf($jP(null)));
check('PDF: תמונה מעוגנת לעמוד — במקום מפינת העמוד (לא מהפסקה); בלי עיגון — יחסית לפסקה', str_contains($pdfP, ' 67.5 759.39 cm') && !str_contains($pdfQ, ' 67.5 759.39 cm'),
    implode(' | ', array_slice(preg_grep('/ cm$/', explode("\n", $pdfP)), 0, 3)));
$oxP = export_ooxml($jP('page'), page_normalize([]))['body'];
check('Word: מעוגנת לעמוד → positionH ו-positionV יחסית לעמוד', str_contains($oxP, '<wp:positionH relativeFrom="page"><wp:posOffset>' . (40 * 9525)) && str_contains($oxP, '<wp:positionV relativeFrom="page"><wp:posOffset>' . (60 * 9525))
    && !str_contains(export_ooxml($jP(null), page_normalize([]))['body'], 'relativeFrom="page"'));
$backP = null;
walk_nodes(import_docx(export_docx($jP('page'), page_normalize([])), $docZ)['json'], function ($n) use (&$backP) { if (($n['type'] ?? '') === 'image') $backP = $n['attrs']; return $n; });
$backQ = null;
walk_nodes(import_docx(export_docx($jP(null), page_normalize([])), $docZ)['json'], function ($n) use (&$backQ) { if (($n['type'] ?? '') === 'image') $backQ = $n['attrs']; return $n; });
check('Word → חזרה: העיגון לעמוד והמיקום חוזרים; בלי עיגון — בלי', ($backP['anchor'] ?? null) === 'page' && ($backP['x'] ?? null) === 40 && ($backP['y'] ?? null) === 60 && empty($backQ['anchor']),
    json_encode([$backP, $backQ]));
check('גרסת מבנה: תמונה מעוגנת לעמוד צריכה 9 (גם עם כותרת עשירה); בלי עיגון — 0', doc_schema_need($jP('page')) === 9 && doc_schema_need($jP(null)) === 0
    && doc_schema_need($jP('page'), page_normalize(['header' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'כ', 'marks' => [['type' => 'bold']]]]]]])) === 9);

// תוכן עניינים: Word (שדה TOC בתוך sdt) הלוך וחזור, HTML, PDF
$toc = ['type' => 'toc', 'attrs' => ['levels' => 2, 'entries' => [['t' => 'מבוא', 'l' => 1, 'p' => '1', 'i' => 0], ['t' => 'רקע & הקשר', 'l' => 2, 'p' => '2', 'i' => 1]]]];
$jT = ['type' => 'doc', 'content' => [$toc, ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'מבוא']]]]];
$oxToc = export_ooxml($jT, page_normalize([]));
check('Word: sdt "Table of Contents" עם שדה TOC \\o "1-2", שורות עם טאב ונקודות', str_contains($oxToc['body'], 'w:docPartGallery w:val="Table of Contents"') && str_contains($oxToc['body'], 'TOC \\o "1-2"')
    && str_contains($oxToc['body'], 'w:leader="dot"') && str_contains($oxToc['body'], 'רקע &amp; הקשר') && substr_count($oxToc['body'], 'fldCharType="end"') === 1);
$backToc = import_docx(export_docx($jT, page_normalize([])), $docL)['json']['content'];
check('Word → חזרה: צומת toc עם אותן שורות (טקסט, רמה, עמוד), והכותרת אחריו', ($backToc[0]['type'] ?? '') === 'toc'
    && array_map(fn($e) => [$e['t'], $e['l'], $e['p']], $backToc[0]['attrs']['entries']) == [['מבוא', 1, '1'], ['רקע & הקשר', 2, '2']] && ($backToc[1]['type'] ?? '') === 'heading',
    json_encode($backToc[0] ?? null, JSON_UNESCAPED_UNICODE));
$hToc = export_html($jT, page_normalize([]))['html'];
check('HTML: רשימת תוכן העניינים', str_contains($hToc, 'class="toc"') && str_contains($hToc, 'רקע &amp; הקשר'));
$pdfToc = $pdfOf($jT);
check('PDF: תוכן העניינים מצויר (עמוד אחד, מבנה תקין)', $structOk($pdfToc) && preg_match_all('#/Type /Page /#', $pdfToc) === 1);

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
section('הערות סיום (שלב 4)');
$enDoc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
    ['type' => 'text', 'text' => 'גוף'],
    ['type' => 'footnote', 'attrs' => ['text' => 'שוליים אחת']],
    ['type' => 'text', 'text' => ' עוד'],
    ['type' => 'footnote', 'attrs' => ['text' => 'סיום ראשונה', 'end' => true]],
    ['type' => 'footnote', 'attrs' => ['text' => 'סיום שנייה', 'content' => [['type' => 'text', 'text' => 'סיום '], ['type' => 'text', 'text' => 'שנייה', 'marks' => [['type' => 'bold']]]], 'end' => true]],
]]]];
$enDocx = export_docx($enDoc, page_normalize([]), 'סיום');
file_put_contents("$tmp/en.docx", $enDocx);
$zip->open("$tmp/en.docx");
$enXml = (string)$zip->getFromName('word/endnotes.xml');
$enFn = (string)$zip->getFromName('word/footnotes.xml');
$enMain = (string)$zip->getFromName('word/document.xml');
$enCt = (string)$zip->getFromName('[Content_Types].xml');
$enRels = (string)$zip->getFromName('word/_rels/document.xml.rels');
$enSet = (string)$zip->getFromName('word/settings.xml');
$zip->close();
check('Word: endnotes.xml עם המפרידים ושתי ההערות; הערת השוליים — ב-footnotes.xml', substr_count($enXml, '<w:endnoteRef/>') === 2
    && str_contains($enXml, 'w:type="separator"') && str_contains($enXml, 'סיום ראשונה') && !str_contains($enXml, 'שוליים אחת')
    && str_contains($enFn, 'שוליים אחת') && !str_contains($enFn, 'סיום'));
check('Word: הפניות במסמך, סוג תוכן, קשר וספרות רומיות', substr_count($enMain, '<w:endnoteReference') === 2 && substr_count($enMain, '<w:footnoteReference') === 1
    && str_contains($enCt, 'wordprocessingml.endnotes+xml') && str_contains($enRels, 'Target="endnotes.xml"') && str_contains($enSet, '<w:numFmt w:val="lowerRoman"/>'));
$enBack = import_docx($enDocx, 'en');
$notes = [];
$walkEn = function ($n) use (&$walkEn, &$notes) { if (($n['type'] ?? '') === 'footnote') $notes[] = $n['attrs']; foreach ($n['content'] ?? [] as $c) $walkEn($c); };
$walkEn($enBack['json']);
check('Word הלוך וחזור: שוליים נשארת שוליים, סיום — סיום, עם העיצוב', count($notes) === 3 && empty($notes[0]['end']) && ($notes[1]['end'] ?? false) === true
    && $notes[1]['text'] === 'סיום ראשונה' && ($notes[2]['content'][1]['marks'][0]['type'] ?? '') === 'bold'
    && !array_filter($enBack['report'], fn($r) => $r['key'] === 'endnotes'), json_encode($notes, JSON_UNESCAPED_UNICODE));
check('ייבוא מ-Word: הפניית סיום → הערת סיום עם הטקסט והעיצוב', (function () use ($w) {
    $f = null;
    $walk = function ($n) use (&$walk, &$f) { if (($n['type'] ?? '') === 'footnote') $f = $n['attrs']; foreach ($n['content'] ?? [] as $c) $walk($c); };
    $walk($w['json']);
    return ($f['end'] ?? false) === true && $f['text'] === 'מקור חשוב' && ($f['content'][1]['marks'][0]['type'] ?? '') === 'bold';
})());
check('PDF: הערות הסיום בסוף המסמך עם ספרות רומיות, השוליים — עם מספר', preg_match_all('#/Type /Page /#', $pdfOf($enDoc)) === 1 && roman_lower(2) === 'ii' && roman_lower(14) === 'xiv');

// ======================================================================
// ======================================================================
section('R08/F03 — הערות ב-HTML ובטקסט');
$fnH = export_html(['type' => 'doc', 'content' => [
    $enDoc['content'][0],
    ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'תא'], ['type' => 'footnote', 'attrs' => ['text' => 'שוליים בתא']]]]]]]]]],
]], page_normalize([]))['html'];
check('HTML: כל ההערות מופיעות — שוליים 1,2 (גם מתא), סיום i,ii, עם העיצוב',
    str_contains($fnH, 'שוליים אחת') && str_contains($fnH, 'שוליים בתא') && str_contains($fnH, 'סיום ראשונה') && str_contains($fnH, 'סיום <strong>שנייה</strong>')
    && str_contains($fnH, 'href="#wp-fn-f2"') && str_contains($fnH, 'id="wp-fn-f2"') && str_contains($fnH, 'href="#wp-fn-eii"') && str_contains($fnH, 'id="wp-fn-eii"')
    && strpos($fnH, 'class="wp-footnotes"') < strpos($fnH, 'class="wp-endnotes"'), $fnH);
$fnT = export_text($enDoc);
check('טקסט: סימן [1] / [i] בגוף, וההערות בסוף', str_contains($fnT, 'גוף[1] עוד[i][ii]') && str_contains($fnT, "[1] שוליים אחת\n") && str_ends_with($fnT, "[i] סיום ראשונה\n[ii] סיום שנייה"), $fnT);
check('בלי הערות — HTML וטקסט בלי מקטע הערות', !str_contains(export_html(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'א']]]]], page_normalize([]))['html'], 'wp-footnotes')
    && export_text(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'א']]]]]) === 'א');

section('R08/F04 — הערת שוליים בתא טבלה ב-PDF');
if (trim((string)shell_exec('command -v pdftotext'))) {
    $cellNotes = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'גוף'], ['type' => 'footnote', 'attrs' => ['text' => 'הערה מהגוף']]]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'תא'], ['type' => 'footnote', 'attrs' => ['text' => 'הערה מהתא']]]]]],
            ['type' => 'tableCell', 'content' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'פנימי'], ['type' => 'footnote', 'attrs' => ['text' => 'הערה מטבלה פנימית']]]]]]]]]]]],
        ]]]],
    ]];
    file_put_contents("$tmp/fn-cell.pdf", $pdfOf($cellNotes));
    $fc = preg_replace('/[\x{202A}-\x{202E}]/u', '', (string)shell_exec('pdftotext ' . escapeshellarg("$tmp/fn-cell.pdf") . ' - 2>&1'));
    check('PDF: הערות מתא ומטבלה מקוננת מופיעות בתחתית העמוד, לפי הסדר', str_contains($fc, 'הערה מהגוף') && str_contains($fc, 'הערה מהתא') && str_contains($fc, 'הערה מטבלה פנימית')
        && strpos($fc, 'הערה מהגוף') < strpos($fc, 'הערה מהתא') && strpos($fc, 'הערה מהתא') < strpos($fc, 'הערה מטבלה פנימית'), $fc);
} else {
    echo "  · pdftotext לא מותקן — דילוג\n";
}

section('R08/F05 — הערה ארוכה מעמוד ב-PDF');
if (trim((string)shell_exec('command -v pdftotext'))) {
    // כל מילה בתוך אזור התוכן של העמוד (מספר העמוד בשוליים — מחוץ לבדיקה), וכל מילות ההערה — במקומן
    $pdfWords = function (array $doc, string $name) use ($tmp, $pdfOf): array {
        file_put_contents("$tmp/$name.pdf", $pdfOf($doc));
        $x = (string)shell_exec('pdftotext -bbox ' . escapeshellarg("$tmp/$name.pdf") . ' - 2>&1');
        $out = ['pages' => 0, 'outside' => [], 'text' => ''];
        preg_match_all('#<page width="([\d.]+)" height="([\d.]+)">(.*?)</page>#s', $x, $pg, PREG_SET_ORDER);
        foreach ($pg as $k => [, $w, $h, $body]) {
            $out['pages']++;
            preg_match_all('#xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<#', $body, $ws, PREG_SET_ORDER);
            foreach ($ws as [, $x0, $y0, $x1, $y1, $t]) {
                $out['text'] .= $t . ' ';
                if (ctype_digit($t) && $y0 > $h - 60) continue;
                if ($y1 > $h - 72 + 3 || $y0 < 72 - 5 || $x0 < 72 - 3 || $x1 > $w - 72 + 3) $out['outside'][] = ($k + 1) . ":$t";
            }
        }
        return $out;
    };
    $longNote = fn(string $end) => ['type' => 'footnote', 'attrs' => ['text' => implode(' ', array_map(fn($i) => "מילה$i", range(1, 2600))) . " $end"]];
    $pp = fn(string $t, ...$more) => ['type' => 'paragraph', 'content' => array_merge([['type' => 'text', 'text' => $t]], $more)];
    $fe = $pdfWords(['type' => 'doc', 'content' => [$pp('גוף', $longNote('סוףבסוף'))]], 'fn-end');
    check('PDF: הערה של כמה עמודים בסוף המסמך — עמודים של הערות בלבד, הכול בתוך הדף', $fe['pages'] >= 4 && !$fe['outside'], $fe['pages'] . ' ' . implode(',', array_slice($fe['outside'], 0, 5)));
    check('PDF: הערה בסוף המסמך — כל המילים, עד האחרונה', substr_count($fe['text'], 'מילה') + substr_count($fe['text'], 'הלימ') === 2600 && preg_match('/סוףבסוף|ףוסבףוס/u', $fe['text']) === 1);
    $body = array_fill(0, 12, $pp(str_repeat('טקסט רגיל בגוף המסמך. ', 30)));
    $fm = $pdfWords(['type' => 'doc', 'content' => array_merge($body, [$pp('סימן', $longNote('סוףבאמצע'), ['type' => 'text', 'text' => ' ועוד'], ['type' => 'footnote', 'attrs' => ['text' => 'הערה שנייה קצרה']])], $body, [['type' => 'pageBreak']], $body)], 'fn-mid');
    check('PDF: שתי הערות (אחת ארוכה מעמודים) באמצע — הכול בתוך הדף, ושתיהן שלמות', !$fm['outside'] && preg_match('/סוףבאמצע|עצמאבףוס/u', $fm['text']) === 1
        && preg_match('/שנייה|היינש/u', $fm['text']) === 1, implode(',', array_slice($fm['outside'], 0, 5)) . ' ' . mb_substr($fm['text'], -200));
} else {
    echo "  · pdftotext לא מותקן — דילוג\n";
}

section('R10 — גודל גופן בחצאי נקודה');
$hs = json_encode(import_html('<p><span style="font-size:13.5pt">חצי</span> <span style="font-size:10.4pt">עיגול</span></p>', 'r10')['json'], JSON_UNESCAPED_UNICODE);
check('ייבוא HTML: 13.5 נשאר 13.5 (לא 14), 10.4 → 10.5', str_contains($hs, '"fontSize":"13.5pt"') && str_contains($hs, '"fontSize":"10.5pt"'), $hs);
$hx = export_ooxml(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'א', 'marks' => [['type' => 'textStyle', 'attrs' => ['fontSize' => '13.5pt']]]]]]]], page_normalize([]));
check('Word: 13.5 → w:sz 27', str_contains(json_encode($hx, JSON_UNESCAPED_UNICODE), 'w:sz w:val=\\"27\\"'));
section('R10 ג — קישורים לחיצים ב-PDF');
$lk = fn(string $t, string $href) => ['type' => 'text', 'text' => $t, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]];
$lpdf = $pdfOf(['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ראו '], $lk('האתר', 'https://example.com/דף (1)'), ['type' => 'text', 'text' => ' ו-'], $lk('פרק ב', '#פרק_ב'),
        ['type' => 'text', 'text' => ' ו-'], $lk('חסר', '#אין_כזה'), ['type' => 'text', 'text' => ' ו-'], $lk('רע', 'javascript:alert(1)')]],
    ['type' => 'pageBreak'],
    ['type' => 'paragraph', 'content' => [['type' => 'bookmark', 'attrs' => ['name' => 'פרק_ב']], ['type' => 'text', 'text' => 'פרק ב מתחיל כאן']]],
]]);
preg_match_all('/<< \/Type \/Annot \/Subtype \/Link \/Rect \[([\d. ]+)\] \/Border \[0 0 0\] (.*?) >>\n/', $lpdf, $an, PREG_SET_ORDER);
preg_match_all('/^(\d+) 0 obj\n<< \/Type \/Page \//m', $lpdf, $pg);
$uri = array_values(array_filter($an, fn($m) => str_contains($m[2], '/URI')));
$dst = array_values(array_filter($an, fn($m) => str_contains($m[2], '/Dest')));
check('PDF: קישור לאתר — אזור לחיץ עם הכתובת (עברית ורווח בקידוד אחוזים, סוגריים בבריחה)', count($uri) === 1 && str_contains($uri[0][2], '(https://example.com/%D7%93%D7%A3%20\(1\))'), json_encode(array_column($an, 2)));
check('PDF: קישור לסימניה — יעד בעמוד 2; לסימניה שלא קיימת ול-javascript: — בלי אזור לחיץ', count($an) === 2 && count($dst) === 1
    && preg_match('/\/Dest \[(\d+) 0 R \/XYZ null [\d.]+ null\]/', $dst[0][2], $dm) && (int)$dm[1] === (int)($pg[1][1] ?? -1), json_encode(array_column($an, 2)) . ' ' . json_encode($pg[1]));
check('PDF: האזור הלחיץ — על הטקסט (בתוך העמוד, בגובה שורה)', (function () use ($uri) {
    [$x1, $y1, $x2, $y2] = array_map('floatval', explode(' ', $uri[0][1]));
    return $x1 > 0 && $x2 < 600 && $x2 - $x1 > 10 && $y2 - $y1 > 8 && $y2 - $y1 < 30 && $y2 > 700;
})(), $uri[0][1] ?? '');
check('PDF עם קישורים — מבנה תקין', $structOk($lpdf));
// תוכן עניינים ורשימת איורים: כל שורה לחיצה, אל הכותרת / הכיתוב שלה (לפי i, כמו בעורך)
$hd = fn(string $t, int $l = 1) => ['type' => 'heading', 'attrs' => ['level' => $l], 'content' => [['type' => 'text', 'text' => $t]]];
$tpdf = $pdfOf(['type' => 'doc', 'content' => [
    ['type' => 'toc', 'attrs' => ['entries' => [['t' => 'א', 'l' => 1, 'p' => '2', 'i' => 0], ['t' => 'ב', 'l' => 2, 'p' => '2', 'i' => 1], ['t' => 'ג', 'l' => 1, 'p' => '3', 'i' => 2]]]],
    ['type' => 'toc', 'attrs' => ['seq' => 'איור', 'entries' => [['t' => 'איור 1', 'l' => 0, 'p' => '3', 'i' => 0]]]],
    ['type' => 'pageBreak'], $hd('א'), ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [$hd('ב בטבלה', 2)]]]]]],
    ['type' => 'pageBreak'], $hd('ג'), ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'איור '], ['type' => 'seq', 'attrs' => ['label' => 'איור', 'id' => 'x1']], ['type' => 'text', 'text' => ': תמונה']]],
]]);
preg_match_all('/\/Dest \[(\d+) 0 R/', $tpdf, $td);
preg_match_all('/^(\d+) 0 obj\n<< \/Type \/Page \//m', $tpdf, $tp);
$pageOfObj = array_flip(array_map('intval', $tp[1]));
check('PDF: שורות תוכן העניינים ורשימת האיורים לחיצות — אל העמוד של הכותרת / הכיתוב (גם כותרת בתוך טבלה)', array_map(fn($o) => $pageOfObj[(int)$o] ?? -1, $td[1]) === [1, 1, 2, 2], json_encode($td[1]) . ' ' . json_encode($tp[1]));

section('טורים — נשמרים ב-Word הלוך וחזור');
$colDoc = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'בשני טורים']]],
    ['type' => 'sectionBreak', 'attrs' => ['page' => ['columns' => ['n' => 1]]]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'בטור אחד']]],
]];
$colPage = page_normalize(['columns' => ['n' => 2, 'gap' => 10, 'line' => true]]);
check('page_normalize: טורים 1–4, רווח, קו; ערך לא תקין — לא נכנס', $colPage['columns'] === ['n' => 2, 'gap' => 10.0, 'line' => true]
    && page_normalize(['columns' => ['n' => 9]])['columns']['n'] === 4 && page_normalize([])['columns']['n'] === 1);
$colDocx = export_docx($colDoc, $colPage, 'טורים');
file_put_contents("$tmp/cols.docx", $colDocx);
$zip->open("$tmp/cols.docx");
$colMain = (string)$zip->getFromName('word/document.xml');
$zip->close();
check('Word: w:cols במקטע הראשון (2, רווח, קו), ובמקטע השני — בלי (טור אחד)', substr_count($colMain, '<w:cols ') === 1
    && str_contains($colMain, '<w:cols w:num="2" w:space="567" w:sep="1"/>'), substr_count($colMain, '<w:cols '));
$colBack = import_docx($colDocx, 'cols');
$sb = array_values(array_filter($colBack['json']['content'], fn($n) => ($n['type'] ?? '') === 'sectionBreak'));
check('Word הלוך וחזור: המקטע הראשון בשני טורים, השני בטור אחד; ובדוח הייבוא — לא כאובדן (מוצגים בטורים)',
    ($colBack['page']['columns']['n'] ?? 0) === 2 && ($colBack['page']['columns']['line'] ?? false) === true && abs(($colBack['page']['columns']['gap'] ?? 0) - 10) < 0.2
    && ($sb[0]['attrs']['page']['columns']['n'] ?? 0) === 1 && !array_filter($colBack['report'], fn($r) => $r['key'] === 'columns'),
    json_encode([$colBack['page']['columns'] ?? null, $sb[0]['attrs']['page']['columns'] ?? null], JSON_UNESCAPED_UNICODE));

section('טורים — PDF בשני טורים');
if (trim((string)shell_exec('command -v pdftotext'))) {
    $colText = fn(int $n) => array_map(fn($i) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "פסקה$i " . str_repeat('מילים רבות בטור צר כדי למלא שורות. ', 6)]]], range(1, $n));
    $colPdf = function (array $doc, array $page) use ($tmp): array {
        file_put_contents("$tmp/colpdf.pdf", export_pdf($doc, page_normalize($page), 'טורים'));
        $x = (string)shell_exec('pdftotext -bbox ' . escapeshellarg("$tmp/colpdf.pdf") . ' - 2>&1');
        preg_match_all('#<page width="([\d.]+)" height="([\d.]+)">(.*?)</page>#s', $x, $pg, PREG_SET_ORDER);
        return array_map(function ($p) {
            preg_match_all('#xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<#', $p[3], $ws, PREG_SET_ORDER);
            return ['w' => (float)$p[1], 'h' => (float)$p[2], 'words' => array_map(fn($m) => ['x0' => (float)$m[1], 'y0' => (float)$m[2], 'x1' => (float)$m[3], 'y1' => (float)$m[4], 't' => $m[5]], $ws)];
        }, $pg);
    };
    $one = $colPdf(['type' => 'doc', 'content' => $colText(30)], []);
    $two = $colPdf(['type' => 'doc', 'content' => $colText(30)], ['columns' => ['n' => 2, 'gap' => 10, 'line' => true]]);
    // A4, שוליים 25 מ"מ: אזור התוכן 70.87–524.41; שני טורים עם רווח 10 מ"מ — הימני 311.81–524.41, השמאלי 70.87–283.46
    $L = 25 * PT_PER_MM; $R = 595.28 - 25 * PT_PER_MM; $gapL = ($L + $R) / 2 - 5 * PT_PER_MM; $gapR = ($L + $R) / 2 + 5 * PT_PER_MM;
    $body = fn($pg) => array_filter($pg['words'], fn($w) => !(ctype_digit($w['t']) && $w['y0'] > $pg['h'] - 60));
    $inGap = []; $right = 0; $left = 0;
    foreach ($two as $k => $pg) foreach ($body($pg) as $w) {
        if ($w['x1'] > $gapL + 1 && $w['x0'] < $gapR - 1) $inGap[] = ($k + 1) . ':' . $w['t'];
        if ($w['x0'] >= $gapR - 1) $right++; elseif ($w['x1'] <= $gapL + 1) $left++;
    }
    check('PDF בשני טורים: שום מילה ברווח בין הטורים, ושני הטורים מלאים', !$inGap && $right > 50 && $left > 50, implode(',', array_slice($inGap, 0, 5)) . " ימין $right שמאל $left");
    check('PDF בשני טורים: הטור הראשון — מימין (פסקה1 בטור הימני בעמוד 1)', (bool)array_filter($body($two[0]), fn($w) => str_contains(implode('', array_reverse(mb_str_split($w['t']))), 'פסקה1') && $w['x0'] >= $gapR - 1)
        || (bool)array_filter($body($two[0]), fn($w) => str_contains($w['t'], 'פסקה1') && $w['x0'] >= $gapR - 1));
    check('PDF בשני טורים: כל המילים, ובערך באותו מספר עמודים כמו טור אחד (הטקסט צר — שורות רבות יותר)',
        array_sum(array_map(fn($p) => count($body($p)), $two)) >= array_sum(array_map(fn($p) => count($body($p)), $one)) - 2 && count($two) >= count($one) - 1 && count($two) <= count($one) + 1, count($one) . ' / ' . count($two));
    $brk = $colPdf(['type' => 'doc', 'content' => [$colText(1)[0], ['type' => 'pageBreak'], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'אחרימעבר']]]]], ['columns' => ['n' => 2]]);
    check('PDF בטורים: מעבר עמוד ידני — עמוד חדש (לא הטור הבא)', count($brk) === 2 && (bool)array_filter($brk[1]['words'], fn($w) => in_array($w['t'], ['אחרימעבר', 'רבעמירחא'], true)), (string)count($brk));
    $fnc = $colPdf(['type' => 'doc', 'content' => array_merge($colText(14), [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'סימן'], ['type' => 'footnote', 'attrs' => ['text' => 'הערהבטורהשמאלי']]]]])], ['columns' => ['n' => 2, 'gap' => 10]]);
    $fw = null;
    foreach ($fnc as $pg) foreach ($pg['words'] as $w) if (in_array($w['t'], ['הערהבטורהשמאלי', 'ילאמשהרוטבהרעה'], true)) $fw = $w;
    check('PDF בטורים: הערת שוליים — ברוחב הטור שלה ובתוכו', $fw && ($fw['x1'] <= $gapL + 1 || $fw['x0'] >= $gapR - 1), json_encode($fw, JSON_UNESCAPED_UNICODE));
    // מעבר טור ידני: בשני טורים — הטור השמאלי של אותו עמוד; בטור אחד — העמוד הבא (כמו ב-Word)
    $cb = ['type' => 'doc', 'content' => [$colText(1)[0], ['type' => 'columnBreak'], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'אחרימעבר']]]]];
    $find = fn($pages) => array_values(array_filter(array_merge(...array_map(fn($p, $k) => array_map(fn($w) => $w + ['pg' => $k], $p['words']), $pages, array_keys($pages))),
        fn($w) => in_array($w['t'], ['אחרימעבר', 'רבעמירחא'], true)))[0] ?? null;
    $cb2 = $colPdf($cb, ['columns' => ['n' => 2, 'gap' => 10]]);
    $aw = $find($cb2);
    check('PDF: מעבר טור בשני טורים — בראש הטור השמאלי, באותו עמוד', count($cb2) === 1 && $aw && $aw['x1'] <= $gapL + 1 && $aw['y0'] < 25 * PT_PER_MM + 20, json_encode($aw, JSON_UNESCAPED_UNICODE) . ' / ' . count($cb2));
    $cb1 = $colPdf($cb, []);
    check('PDF: מעבר טור בטור אחד — העמוד הבא', count($cb1) === 2 && ($find($cb1)['pg'] ?? -1) === 1, (string)count($cb1));
    $cb3 = $colPdf(['type' => 'doc', 'content' => [$colText(1)[0], ['type' => 'columnBreak'], $colText(1)[0], ['type' => 'columnBreak'], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'אחרימעבר']]]]], ['columns' => ['n' => 2]]);
    check('PDF: מעבר טור בטור האחרון — העמוד הבא, בטור הראשון (מימין)', count($cb3) === 2 && ($w3 = $find($cb3)) && $w3['pg'] === 1 && $w3['x0'] >= $gapR - 1, (string)count($cb3));
} else echo "  · pdftotext לא מותקן — דילוג\n";

section('מעבר טור — Word, HTML, טקסט וגרסת המבנה');
$cbDoc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'לפני']]], ['type' => 'columnBreak'],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'אחרי']]]]];
file_put_contents("$tmp/cb.docx", export_docx($cbDoc, page_normalize(['columns' => ['n' => 2]]), 'מעבר טור'));
$zip->open("$tmp/cb.docx");
$cbMain = (string)$zip->getFromName('word/document.xml');
$zip->close();
check('Word: מעבר טור — w:br w:type="column"', str_contains($cbMain, '<w:br w:type="column"/>') && !str_contains($cbMain, '<w:br w:type="page"/>'));
$cbBack = import_docx(file_get_contents("$tmp/cb.docx"), 'cb');
check('Word הלוך וחזור: מעבר הטור חוזר כמעבר טור (לא מעבר עמוד)', array_column($cbBack['json']['content'], 'type') === ['paragraph', 'columnBreak', 'paragraph'], json_encode(array_column($cbBack['json']['content'], 'type')));
check('HTML: מעבר טור עם break-before: column; טקסט — שורה ריקה', str_contains(export_html($cbDoc, page_normalize([]))[0] ?? json_encode(export_html($cbDoc, page_normalize([]))), 'break-before:column')
    && str_contains(export_text($cbDoc), "לפני\n\nאחרי"), substr(export_text($cbDoc), 0, 40));
check('גרסת מבנה: מסמך עם מעבר טור צריך 5', doc_schema_need($cbDoc) === 5 && doc_schema_need(['type' => 'doc', 'attrs' => ['track' => true], 'content' => $cbDoc['content']]) === 5
    && doc_schema_need(['type' => 'doc', 'attrs' => ['track' => true], 'content' => []]) === 4);
$r5 = doc_find(doc_new('a:99', 'גרסה 5 — מעבר טור', $cbDoc, null, 'u1', 'דנה'), 'a:99');
check('גרסת מבנה 5: כותב בגרסה 4 על מסמך עם מעבר טור → schema_newer; בגרסה 5 — נשמר',
    throws(fn() => doc_save($r5, ['type' => 'doc', 'attrs' => ['schema' => 4], 'content' => [$cbDoc['content'][0]]], null, 'u2', 'ישן'), 'schema_newer')
    && (int)doc_save($r5, $cbDoc + ['attrs' => ['schema' => 5]], null, 'u1', 'דנה')['version'] === (int)$r5['version'] + 1);

section('עצירות טאב ומובילים (R10 ב)');
check('tabs_val: ממוין, בלי כפולות ובלי ערכים לא תקינים; יישור / מוביל לא מוכרים — ברירת מחדל',
    tabs_val([['pos' => 300, 'align' => 'end', 'leader' => 'dot'], ['pos' => 100, 'align' => 'x', 'leader' => 'y'], ['pos' => 100], ['pos' => -5], ['pos' => 'a'], 'x'])
    === [['pos' => 100.0, 'align' => 'start', 'leader' => 'none'], ['pos' => 300.0, 'align' => 'end', 'leader' => 'dot']]);
$tabDoc = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'attrs' => ['tabs' => [['pos' => 100, 'align' => 'start'], ['pos' => 300, 'align' => 'end', 'leader' => 'dot']]], 'content' => [['type' => 'text', 'text' => "פרק\tראשון\t12"]]],
    ['type' => 'paragraph', 'attrs' => ['dir' => 'ltr', 'tabs' => [['pos' => 72, 'align' => 'start'], ['pos' => 144, 'align' => 'end', 'leader' => 'hyphen'], ['pos' => 200, 'align' => 'decimal', 'leader' => 'underscore']]],
        'content' => [['type' => 'text', 'text' => "a\tb\tc\t3.5"]]],
]];
file_put_contents("$tmp/tabs.docx", export_docx($tabDoc, page_normalize([]), 'טאבים'));
$zip->open("$tmp/tabs.docx");
$tabMain = (string)$zip->getFromName('word/document.xml');
$zip->close();
check('Word: עצירות בפסקה — מימין לשמאל left / right הפוכים (לתחילה = right), משמאל לימין כרגיל; לפני w:bidi',
    str_contains($tabMain, '<w:tabs><w:tab w:val="right" w:pos="2000"/><w:tab w:val="left" w:leader="dot" w:pos="6000"/></w:tabs><w:bidi/>')
    && str_contains($tabMain, '<w:tabs><w:tab w:val="left" w:pos="1440"/><w:tab w:val="right" w:leader="hyphen" w:pos="2880"/><w:tab w:val="decimal" w:leader="underscore" w:pos="4000"/></w:tabs>'),
    implode(' ', array_slice(explode('<w:tabs>', $tabMain), 1)));
$tabBack = import_docx(file_get_contents("$tmp/tabs.docx"), 'tabs');
check('Word הלוך וחזור: אותן עצירות, יישור ומובילים (בשני הכיוונים)', array_map(fn($p) => $p['attrs']['tabs'] ?? null, $tabBack['json']['content'])
    === array_map(fn($p) => tabs_val($p['attrs']['tabs']), $tabDoc['content']), json_encode(array_map(fn($p) => $p['attrs']['tabs'] ?? null, $tabBack['json']['content'])));
check('Word: start / end / clear / bar — start ו-end לפי הכיוון הלוגי, clear ו-bar לא נכנסים', (function () {
    $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:pPr><w:tabs><w:tab w:val="clear" w:pos="500"/><w:tab w:val="bar" w:pos="700"/>'
        . '<w:tab w:val="end" w:leader="middleDot" w:pos="2000"/><w:tab w:val="start" w:pos="1000"/></w:tabs><w:bidi/></w:pPr><w:r><w:t>א</w:t></w:r></w:p></w:body></w:document>';
    $z = new ZipArchive(); $f = sys_get_temp_dir() . '/wp-tabs-' . getmypid() . '.docx';
    copy($GLOBALS['tmp'] . '/tabs.docx', $f); $z->open($f); $z->addFromString('word/document.xml', $xml); $z->close();
    $t = import_docx(file_get_contents($f), 'x')['json']['content'][0]['attrs']['tabs'] ?? null;
    unlink($f);
    return $t === [['pos' => 50.0, 'align' => 'start', 'leader' => 'none'], ['pos' => 100.0, 'align' => 'end', 'leader' => 'dot']];
})());
check('גרסת מבנה: פסקה עם עצירות טאב צריכה 6', doc_schema_need($tabDoc) === 6 && doc_schema_need(['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['tabs' => null]]]]) === 0);
$r6 = doc_find(doc_new('a:99', 'גרסה 6 — טאבים', $tabDoc, null, 'u1', 'דנה'), 'a:99');
check('גרסת מבנה 6: כותב בגרסה 5 על מסמך עם עצירות → schema_newer', throws(fn() => doc_save($r6, ['type' => 'doc', 'attrs' => ['schema' => 5], 'content' => []], null, 'u2', 'ישן'), 'schema_newer'));
if (trim((string)shell_exec('command -v pdftotext'))) {
    file_put_contents("$tmp/tabs.pdf", export_pdf($tabDoc, page_normalize([]), 'טאבים'));
    $x = (string)shell_exec('pdftotext -bbox ' . escapeshellarg("$tmp/tabs.pdf") . ' - 2>&1');
    preg_match_all('#xMin="([\d.]+)" yMin="[\d.]+" xMax="([\d.]+)" yMax="[\d.]+">([^<]*)<#', $x, $tw, PREG_SET_ORDER);
    $box = []; foreach ($tw as $m) $box[$m[3]] ??= [(float)$m[1], (float)$m[2]];
    $Rm = 595.28 - 25 * PT_PER_MM; $Lm = 25 * PT_PER_MM;
    $first = $box['ןושאר'] ?? $box['ראשון'] ?? null;
    check('PDF: לתחילה ב-100 מימין, לסוף ב-300 (המספר מסתיים בעצירה), משמאל לימין — מהשוליים השמאליים',
        $first && abs($first[1] - ($Rm - 100)) < 1 && isset($box['12']) && abs($box['12'][0] - ($Rm - 300)) < 1
        && isset($box['b']) && abs($box['b'][0] - ($Lm + 72)) < 1 && isset($box['c']) && abs($box['c'][1] - ($Lm + 144)) < 1 && isset($box['3.5']) && $box['3.5'][0] < $Lm + 200 && $box['3.5'][1] > $Lm + 200,
        json_encode(array_intersect_key($box, array_flip(['ןושאר', 'ראשון', '12', 'b', '3.5', 'c']))));
}

section('השוואת גרסאות (שלב 4)');
require_once __DIR__ . '/../lib/compare.php';
$cp = fn(...$t) => ['type' => 'paragraph', 'content' => array_map(fn($x) => is_array($x) ? $x : ['type' => 'text', 'text' => $x], $t)];
$cd = fn(...$b) => ['type' => 'doc', 'content' => $b];
/** הסימונים של התוצאה בצורה קריאה: +[נוסף] -[נמחק] ~[עוצב] */
$show = function (array $doc) {
    return implode(' / ', array_map(function ($b) {
        $s = '';
        $walk = function ($n) use (&$walk, &$s) {
            if (isset($n['text'])) {
                $t = array_column($n['marks'] ?? [], 'type');
                $txt = $n['text'];
                $s .= in_array('ins', $t, true) ? "+[$txt]" : (in_array('del', $t, true) ? "-[$txt]" : (in_array('fmt', $t, true) ? "~[$txt]" : $txt));
            }
            foreach ($n['content'] ?? [] as $c) $walk($c);
        };
        $walk($b);
        return ($b['type'] ?? '') . ':' . $s;
    }, $doc['content']));
};
$d0 = $cd($cp('אחת שתיים שלוש'), $cp('פסקה שנייה'), $cp('שלישית'));
check('השוואה: מסמך זהה — בלי סימונים', $show(track_compare($d0, $d0)) === 'paragraph:אחת שתיים שלוש / paragraph:פסקה שנייה / paragraph:שלישית', $show(track_compare($d0, $d0)));
$d1 = $cd($cp('אחת ארבע שלוש'), $cp('שלישית'), $cp('חדשה בסוף'));
$r1 = $show(track_compare($d0, $d1));
check('השוואה: מילה שהוחלפה — נמחקה ונוספה; פסקה שנמחקה ופסקה שנוספה', $r1 === 'paragraph:אחת -[שתיים]+[ארבע] שלוש / paragraph:-[פסקה שנייה] / paragraph:שלישית / paragraph:+[חדשה בסוף]', $r1);
$r2 = track_compare($cd($cp('מילה רגילה')), $cd($cp(['type' => 'text', 'text' => 'מילה', 'marks' => [['type' => 'bold']]], ' רגילה')));
check('השוואה: רק העיצוב השתנה — שינוי עיצוב (fmt) עם העיצוב הקודם, לא מחיקה ותוספת', $show($r2) === 'paragraph:~[מילה] רגילה'
    && ($r2['content'][0]['content'][0]['marks'][1]['attrs']['old'] ?? null) === [] && $r2['content'][0]['content'][0]['marks'][0] === ['type' => 'bold'], $show($r2));
$r3 = track_compare($cd($cp('ישן', ['type' => 'text', 'text' => ' כבר', 'marks' => [['type' => 'ins', 'attrs' => ['author' => 'דנה']]]])), $cd($cp('ישן כבר')));
check('השוואה: סימוני מעקב קודמים לא נחשבים הבדל', $show($r3) === 'paragraph:ישן כבר', $show($r3));
$tbl = fn($t) => ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [$cp($t)]]]]]];
$r4 = $show(track_compare($cd($cp('לפני'), $tbl('תא ישן')), $cd($cp('לפני'), $tbl('תא חדש'))));
check('השוואה: טבלה שהשתנתה — הישנה כמחיקה והחדשה כתוספת', $r4 === 'paragraph:לפני / table:-[תא ישן] / table:+[תא חדש]', $r4);
$r5 = track_compare($d0, $d1, '2026-10-22T10:00:00Z');
$m5 = $r5['content'][0]['content'][1]['marks'][0] ?? [];
check('השוואה: המחבר "השוואה" והתאריך; המעקב לא פועל בתוצאה', $m5 === ['type' => 'del', 'attrs' => ['author' => 'השוואה', 'date' => '2026-10-22T10:00:00Z']] && empty($r5['attrs']['track']), json_encode($m5, JSON_UNESCAPED_UNICODE));
$big = $cd(...array_map(fn($i) => $cp("פסקה $i"), range(1, 900)));
$big2 = $big; $big2['content'][450] = $cp('פסקה שונתה');
$t0 = microtime(true);
$r6 = $show(track_compare($big, $big2));
check('השוואה: 900 פסקאות עם שינוי באמצע — מהר, ורק הפסקה ההיא מסומנת', microtime(true) - $t0 < 2 && substr_count($r6, '-[') === 1 && substr_count($r6, '+[') === 1, round(microtime(true) - $t0, 2) . 's');
// מעקב שינויים וגרסאות לא דורסים זה את זה (תנאי הקבלה של שלב 4): גרסאות עם שינויים שעוד לא אושרו — מושוות כאילו
// הכול אושר (כמו ב-Word). קודם: מחיקה במעקב חזרה כטקסט רגיל בפסקה שהשתנתה, ובפסקה שלא השתנתה נשארו הסימונים הישנים
$dn = fn($t) => ['type' => 'text', 'text' => $t, 'marks' => [['type' => 'del', 'attrs' => ['author' => 'דנה', 'date' => '2026-10-20T10:00:00Z']]]];
$in = fn($t) => ['type' => 'text', 'text' => $t, 'marks' => [['type' => 'ins', 'attrs' => ['author' => 'דנה', 'date' => '2026-10-20T10:00:00Z']]]];
$r7 = $show(track_compare($cd($cp('אחת ', $dn('שתיים '), 'שלוש')), $cd($cp('אחת ', $dn('שתיים '), 'חמש'))));
check('השוואה עם מעקב: מחיקה שעוד לא אושרה — לא חוזרת כטקסט רגיל; ההבדל — רק מה שהשתנה', $r7 === 'paragraph:אחת -[שלוש]+[חמש]', $r7);
$r8 = track_compare($cd($cp('ישן', $in(' כבר')), $cp('משתנה')), $cd($cp('ישן', $in(' כבר')), $cp('השתנה')));
check('השוואה עם מעקב: פסקה שלא השתנתה — בלי הסימונים הישנים (כל סימון בתוצאה — הבדל בין הגרסאות)',
    $show($r8) === 'paragraph:ישן כבר / paragraph:-[משתנה]+[השתנה]' && !str_contains(json_encode($r8, JSON_UNESCAPED_UNICODE), 'דנה'), $show($r8));
$fm = ['type' => 'text', 'text' => 'מודגש', 'marks' => [['type' => 'bold'], ['type' => 'fmt', 'attrs' => ['author' => 'דנה', 'old' => []]]]];
$pc = ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center', 'pchg' => ['author' => 'דנה', 'old' => ['type' => 'paragraph', 'attrs' => []]]], 'content' => [$fm]];
$r9 = track_compare($cd($pc), $cd($pc));
check('השוואה עם מעקב: שינויי עיצוב (תווים ופסקה) שעוד לא אושרו — העיצוב הנוכחי, בלי הסימון',
    $show($r9) === 'paragraph:מודגש' && ($r9['content'][0]['content'][0]['marks'] ?? null) === [['type' => 'bold']] && !isset($r9['content'][0]['attrs']['pchg'])
    && ($r9['content'][0]['attrs']['textAlign'] ?? '') === 'center', json_encode($r9['content'][0], JSON_UNESCAPED_UNICODE));
check('השוואה עם מעקב: track_pending — מזהה גרסה עם שינויים שלא אושרו', track_pending($cd($cp('אחת ', $dn('שתיים')))) && track_pending($cd($pc)) && !track_pending($d0));
section('כותרות זוגי / אי-זוגי והתחלת מספור במקטע (שלב 3)');
$eoPage = page_normalize(['even_odd' => true, 'header' => 'אי-זוגי', 'header_even' => 'זוגי', 'numbering' => ['position' => 'bottom']]);
$eoPara = fn($i) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "פסקה$i " . str_repeat('מילים רבות למלא עמוד. ', 30)]]];
$eoDoc = ['type' => 'doc', 'content' => array_merge(array_map($eoPara, range(1, 14)),
    [['type' => 'sectionBreak', 'attrs' => ['page' => ['num_restart' => true, 'num_start' => 1]]]], array_map($eoPara, range(15, 22)),
    [['type' => 'sectionBreak', 'attrs' => ['page' => ['orientation' => 'landscape']]]], [$eoPara(23)])];
check('page_numbers: מקטע עם num_restart מתחיל מחדש; המקטע שאחריו ממשיך (לא יורש את ההתחלה מחדש)',
    page_numbers([0, 0, 0, 1, 1, 2], [page_normalize([]), page_section(['num_restart' => true, 'num_start' => 5], page_normalize([])),
        page_section(['orientation' => 'landscape'], page_section(['num_restart' => true, 'num_start' => 5], page_normalize([])))]) === [1, 2, 3, 5, 6, 7]);
if (trim((string)shell_exec('command -v pdftotext'))) {
    file_put_contents("$tmp/eo.pdf", export_pdf($eoDoc, $eoPage, 'זוגי'));
    $eoPages = explode("\f", preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', (string)shell_exec('pdftotext -layout ' . escapeshellarg("$tmp/eo.pdf") . ' - 2>&1')));
    array_pop($eoPages);
    $hdrOf = fn($t) => str_contains($t, 'זוגי-אי') || str_contains($t, 'אי-זוגי') ? 'odd' : (str_contains($t, 'זוגי') || str_contains($t, 'יגוז') ? 'even' : '?');
    $numOf = function ($t) { preg_match_all('/^\s*(\d+)\s*$/m', $t, $m); return (int)end($m[1]); };
    $seq = array_map(fn($t) => $numOf($t) . $hdrOf($t), $eoPages);
    // המקטע השני מתחיל ב-1 — ושוב אי-זוגי; הכותרת לפי מספר העמוד
    $nums = array_map($numOf, $eoPages);
    $restarts = array_keys(array_filter($nums, fn($v, $i) => $i > 0 && $v !== $nums[$i - 1] + 1, ARRAY_FILTER_USE_BOTH));
    $first2 = $restarts[0] ?? null;
    // התחלה מחדש אחת בלבד (במקטע השני; השלישי ממשיך), ל-1
    $ok = count($restarts) === 1 && $first2 > 1 && $nums[$first2] === 1 && $nums[0] === 1;
    foreach ($seq as $x) if (!preg_match('/^(\d+)(odd|even)$/', $x, $m) || ((int)$m[1] % 2 === 0) !== ($m[2] === 'even')) $ok = false;
    check('PDF: המספור מתחיל מחדש במקטע השני, וכותרת זוגית בכל עמוד שמספרו זוגי', $ok, implode(' ', $seq) . " / מקטע 2 בעמוד $first2");
}
file_put_contents("$tmp/eo.docx", export_docx($eoDoc, $eoPage, 'זוגי'));
$zip->open("$tmp/eo.docx");
$eoSet = (string)$zip->getFromName('word/settings.xml');
$eoMain = (string)$zip->getFromName('word/document.xml');
$eoEven = (string)$zip->getFromName('word/header1e.xml');
$zip->close();
preg_match_all('#<w:pgNumType[^>]*/>#', $eoMain, $pg);
check('Word: evenAndOddHeaders, כותרת לעמוד זוגי, והתחלת מספור במקטע השני בלבד', str_contains($eoSet, '<w:evenAndOddHeaders/>') && str_contains($eoMain, 'w:type="even"')
    && str_contains($eoEven, 'זוגי') && str_contains($pg[0][1] ?? '', 'w:start="1"') && !str_contains($pg[0][2] ?? '', 'w:start'), implode(' ', $pg[0]));
$eoBack = import_docx(file_get_contents("$tmp/eo.docx"), 'eo');
$eoSb = array_values(array_filter($eoBack['json']['content'], fn($n) => ($n['type'] ?? '') === 'sectionBreak'));
check('Word הלוך וחזור: זוגי / אי-זוגי, הכותרת הזוגית, והתחלת המספור במקטע', !empty($eoBack['page']['even_odd']) && ($eoBack['page']['header_even'] ?? '') === 'זוגי'
    && !empty($eoSb[0]['attrs']['page']['num_restart']) && ($eoSb[0]['attrs']['page']['num_start'] ?? 0) === 1 && empty($eoSb[1]['attrs']['page']['num_restart'])
    && !array_filter($eoBack['report'], fn($r) => $r['key'] === 'hf_extra'), json_encode([$eoBack['page']['even_odd'] ?? null, $eoSb[0]['attrs']['page']['num_restart'] ?? null, $eoSb[1]['attrs']['page']['num_restart'] ?? null], JSON_UNESCAPED_UNICODE));

section('עמוד ראשון שונה לכל מקטע (שלב 3)');
// שלושה מקטעים: הראשון — עמוד שער (COVER, בלי תחתונה); השני — בלי עמוד ראשון שונה; השלישי — OPENING בעמוד הראשון שלו
$fdPage = page_normalize(['first_diff' => true, 'header' => 'REGULAR', 'header_first' => 'COVER', 'footer' => 'FOOT', 'footer_first' => '']);
$fdDoc = ['type' => 'doc', 'content' => array_merge(array_map($eoPara, range(1, 6)),
    [['type' => 'sectionBreak', 'attrs' => ['page' => ['first_diff' => false]]]], array_map($eoPara, range(7, 12)),
    [['type' => 'sectionBreak', 'attrs' => ['page' => ['first_diff' => true, 'header_first' => 'OPENING', 'footer_first' => 'FOOT']]]], array_map($eoPara, range(13, 18)))];
check('page_normalize: first_diff, header_first / footer_first; מקטע יורש ומבטל', $fdPage['first_diff'] === true && $fdPage['header_first'] === 'COVER'
    && page_section(['first_diff' => false], $fdPage)['first_diff'] === false && page_section([], $fdPage)['header_first'] === 'COVER');
if (trim((string)shell_exec('command -v pdftotext'))) {
    file_put_contents("$tmp/fd.pdf", export_pdf($fdDoc, $fdPage, 'ראשון'));
    $fdPages = explode("\f", (string)shell_exec('pdftotext -layout ' . escapeshellarg("$tmp/fd.pdf") . ' - 2>&1'));
    array_pop($fdPages);
    $fdSeq = array_map(fn($t) => (preg_match('/\b(COVER|REGULAR|OPENING)\b/', $t, $m) ? $m[1] : '-') . (str_contains($t, 'FOOT') ? '+F' : ''), $fdPages);
    // הראשון: COVER בלי תחתונה; אחר כך REGULAR; השני (בלי עמוד ראשון שונה) — REGULAR מההתחלה; השלישי — OPENING ואז REGULAR
    $fdOk = count($fdPages) >= 5 && $fdSeq[0] === 'COVER' && $fdSeq[1] === 'REGULAR+F' && count(array_keys($fdSeq, 'COVER')) === 1
        && count(array_keys($fdSeq, 'OPENING+F')) === 1 && array_search('OPENING+F', $fdSeq) > 2 && ($fdSeq[array_search('OPENING+F', $fdSeq) + 1] ?? '') === 'REGULAR+F';
    check('PDF: הכותרת לעמוד הראשון — רק בעמוד הראשון של מקטע עם first_diff', $fdOk, implode(' ', $fdSeq));
}
file_put_contents("$tmp/fd.docx", export_docx($fdDoc, $fdPage, 'ראשון'));
$zip->open("$tmp/fd.docx");
$fdMain = (string)$zip->getFromName('word/document.xml');
$fdFiles = [];
for ($i = 0; $i < $zip->numFiles; $i++) $fdFiles[$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
$zip->close();
preg_match_all('#<w:sectPr>.*?</w:sectPr>#s', $fdMain, $fdSect);
$fdFirstHdr = array_filter($fdFiles, fn($x, $k) => preg_match('#^word/header\d+f\.xml$#', $k) === 1, ARRAY_FILTER_USE_BOTH);
check('Word: titlePg במקטעים הראשון והשלישי בלבד, עם כותרות לעמוד ראשון (COVER, OPENING)', count($fdSect[0]) === 3
    && str_contains($fdSect[0][0], '<w:titlePg/>') && !str_contains($fdSect[0][1], '<w:titlePg/>') && str_contains($fdSect[0][2], '<w:titlePg/>')
    && count(array_filter($fdFirstHdr, fn($x) => str_contains($x, 'COVER'))) === 1 && count(array_filter($fdFirstHdr, fn($x) => str_contains($x, 'OPENING'))) === 1,
    implode(' | ', array_map(fn($x) => (int)str_contains($x, 'titlePg'), $fdSect[0])));
$fdBack = import_docx(file_get_contents("$tmp/fd.docx"), 'fd');
$fdSb = array_values(array_filter($fdBack['json']['content'], fn($n) => ($n['type'] ?? '') === 'sectionBreak'));
check('Word הלוך וחזור: עמוד ראשון שונה לכל מקטע, עם הכותרות שלו; בלי "כותרת שלא יובאה" בדוח', !empty($fdBack['page']['first_diff']) && ($fdBack['page']['header_first'] ?? '') === 'COVER'
    && ($fdBack['page']['footer_first'] ?? null) === '' && ($fdBack['page']['footer'] ?? '') === 'FOOT'
    && ($fdSb[0]['attrs']['page']['first_diff'] ?? null) === false && !empty($fdSb[1]['attrs']['page']['first_diff']) && ($fdSb[1]['attrs']['page']['header_first'] ?? '') === 'OPENING'
    && !array_filter($fdBack['report'], fn($r) => $r['key'] === 'hf_extra'),
    json_encode([$fdBack['page']['first_diff'] ?? null, $fdBack['page']['header_first'] ?? null, $fdSb[0]['attrs']['page']['first_diff'] ?? null, $fdSb[1]['attrs']['page']['header_first'] ?? null], JSON_UNESCAPED_UNICODE));
$fdNum = import_docx(export_docx($eoDoc, page_normalize(['numbering' => ['first_page' => false]]), 'x'), 'fd2')['page'];
check('Word: "בלי מספר בעמוד הראשון" (בלי first_diff) — עדיין first_page:false, לא עמוד ראשון שונה', empty($fdNum['first_diff']) && ($fdNum['numbering']['first_page'] ?? true) === false,
    json_encode([$fdNum['first_diff'] ?? null, $fdNum['numbering'] ?? null]));

section('כותרת עליונה / תחתונה עשירה (שלב 3)');
$rhDocId = doc_new('a:99', 'בסיס כותרת', null, null, 'u1', 'דנה');
$rhImg = media_store($rhDocId, $png);
$rhHdr = [['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [
        ['type' => 'image', 'attrs' => ['media' => $rhImg['id'], 'width' => 40, 'height' => 20]],
        ['type' => 'text', 'text' => ' חברה בע"מ', 'marks' => [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['fontSize' => '14pt', 'onclick' => 'x']], ['type' => 'link', 'attrs' => ['href' => 'x']]]]]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'שורה שנייה']]], ['type' => 'table'], 'x'];
$rhPage = page_normalize(['header' => $rhHdr, 'footer' => 'פשוטה', 'numbering' => ['position' => 'bottom']]);
$rh = $rhPage['header'];
check('hf_rich_val: פסקאות עם יישור, עיצוב מותר בלבד (בלי קישור ומאפיינים זרים), תמונה; בלי טבלה', count($rh) === 2 && $rh[0]['attrs'] === ['textAlign' => 'left']
    && $rh[0]['content'][0]['type'] === 'image' && $rh[0]['content'][1]['marks'] === [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['fontSize' => '14pt']]]
    && hf_text($rh) === " חברה בע\"מ\nשורה שנייה" && $rhPage['footer'] === 'פשוטה' && page_normalize(['header' => [['type' => 'paragraph']]])['header'] === '', json_encode($rh, JSON_UNESCAPED_UNICODE));
$rhDoc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'גוף']]]]];
if (trim((string)shell_exec('command -v pdftotext'))) {
    file_put_contents("$tmp/rh.pdf", export_pdf($rhDoc, $rhPage, 'כותרת'));
    $x = (string)shell_exec('pdftotext -bbox ' . escapeshellarg("$tmp/rh.pdf") . ' - 2>&1');
    preg_match_all('#xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<#', $x, $ws, PREG_SET_ORDER);
    $w = []; foreach ($ws as $m) $w[$m[5]] ??= [(float)$m[1], (float)$m[2]];
    $co = $w['הרבח'] ?? $w['חברה'] ?? null;
    check('PDF: כותרת עשירה — במרחק הכותרת מראש הדף, מיושרת לשמאל (אחרי הסמליל), והשורה השנייה מימין', $co && $co[1] < 25 * PT_PER_MM && $co[0] < 150
        && isset($w['הרוש']) && $w['הרוש'][0] > 300, json_encode($w, JSON_UNESCAPED_UNICODE));
}
file_put_contents("$tmp/rh.docx", export_docx($rhDoc, $rhPage, 'כותרת'));
$zip->open("$tmp/rh.docx");
$rhX = (string)$zip->getFromName('word/header1.xml');
$rhR = (string)$zip->getFromName('word/_rels/header1.xml.rels');

$zip->close();
check('Word: הכותרת העשירה כפסקאות (מודגש, 14, יישור), והתמונה עם קובץ קשרים של חלק הכותרת', str_contains($rhX, '<w:b/>') && str_contains($rhX, 'w:val="28"')
    && str_contains($rhX, '<w:drawing>') && preg_match('/r:embed="([^"]+)"/', $rhX, $em) && str_contains($rhR, 'Id="' . $em[1] . '"') && str_contains($rhR, 'media/'), $rhR);
$rhBack = import_docx(file_get_contents("$tmp/rh.docx"), $rhDocId);
$bh = $rhBack['page']['header'] ?? null;
check('Word הלוך וחזור: הכותרת חוזרת עשירה — התמונה, המודגש והיישור; התחתונה הפשוטה — טקסט', is_array($bh) && ($bh[0]['content'][0]['type'] ?? '') === 'image'
    && in_array(['type' => 'bold'], $bh[0]['content'][1]['marks'] ?? [], true) && ($bh[0]['attrs']['textAlign'] ?? '') === 'left' && ($rhBack['page']['footer'] ?? '') === 'פשוטה',
    json_encode([$bh, $rhBack['page']['footer'] ?? null], JSON_UNESCAPED_UNICODE));
$rhRow = doc_find(doc_new('a:99', 'כותרת עשירה', $rhDoc, $rhPage, 'u1', 'דנה'), 'a:99');
$rhCopy = doc_page(doc_find(doc_copy($rhRow, 'a:99', 'עותק', [], 'u1', 'דנה'), 'a:99'));
$cm = $rhCopy['header'][0]['content'][0]['attrs']['media'] ?? '';
check('גרסת מבנה: כותרת עשירה (במסמך או במעבר מקטע) צריכה 8', doc_schema_need($rhDoc, $rhPage) === 8 && doc_schema_need($rhDoc, page_normalize(['header' => 'פשוטה'])) === 0
    && doc_schema_need(['type' => 'doc', 'content' => [['type' => 'sectionBreak', 'attrs' => ['page' => ['footer' => $rhHdr]]]]]) === 8);
check('שכפול מסמך: גם התמונה שבכותרת מועתקת למסמך החדש', $cm !== '' && $cm !== $rhImg['id'] && media_row($cm), $cm);

// טבלה בכותרת: סמליל בצד אחד, פרטים בצד השני — ניקוי, PDF, Word הלוך וחזור
$thCell = fn(array $content, array $attrs = []) => ['type' => 'tableCell', 'attrs' => $attrs + ['colspan' => 1, 'rowspan' => 1, 'onclick' => 'x'], 'content' => $content];
$thHdr = [['type' => 'table', 'attrs' => ['borders' => 'none', 'evil' => 1], 'content' => [['type' => 'tableRow', 'content' => [
    $thCell([['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['media' => $rhImg['id'], 'width' => 40, 'height' => 20]]]]]),
    $thCell([['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => 'LEFTCELL', 'marks' => [['type' => 'bold']]]]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'SECONDLINE']]], ['type' => 'table']], ['valign' => 'middle', 'background' => '#EEEEEE']),
]]]]];
$th = page_normalize(['header' => $thHdr])['header'];
$thC = $th[0]['content'][0]['content'] ?? [];
check('hf_rich_val: טבלה בכותרת — גבולות, תאים עם פסקאות ותמונה, בלי מאפיינים זרים ובלי טבלה בתוך תא', ($th[0]['type'] ?? '') === 'table' && ($th[0]['attrs'] ?? null) === ['borders' => 'none']
    && count($thC) === 2 && ($thC[0]['content'][0]['content'][0]['type'] ?? '') === 'image' && count($thC[1]['content']) === 2 && !isset($thC[1]['attrs']['onclick'])
    && ($thC[1]['attrs']['valign'] ?? '') === 'middle' && ($thC[1]['attrs']['background'] ?? '') === '#eeeeee' && hf_text($th) === "\tLEFTCELL SECONDLINE",
    json_encode($th, JSON_UNESCAPED_UNICODE));
if (trim((string)shell_exec('command -v pdftotext'))) {
    file_put_contents("$tmp/th.pdf", export_pdf($rhDoc, page_normalize(['header' => $thHdr]), 'טבלה'));
    $thTxt = (string)shell_exec('pdftotext -bbox ' . escapeshellarg("$tmp/th.pdf") . ' - 2>&1');
    $thY = fn($w) => preg_match('/yMin="([\d.]+)"[^>]*>' . $w . '</', $thTxt, $m) ? (float)$m[1] : null;
    $thX = fn($w) => preg_match('/xMin="([\d.]+)"[^>]*>' . $w . '</', $thTxt, $m) ? (float)$m[1] : null;
    // בראש הדף (בשוליים העליונים), בתא השמאלי (שני — בטבלה מימין לשמאל), השורה השנייה מתחת לראשונה
    check('PDF: טבלה בכותרת — הטקסט בשוליים העליונים, בתא השמאלי, שתי שורות; התמונה מצוירת', $thY('LEFTCELL') !== null && $thY('LEFTCELL') < 70 && $thX('LEFTCELL') < 297
        && $thY('SECONDLINE') > $thY('LEFTCELL') && str_contains(gzuncompress_pages(file_get_contents("$tmp/th.pdf")), ' Do'),
        json_encode([$thY('LEFTCELL'), $thX('LEFTCELL'), $thY('SECONDLINE')]));
}
$thDocx = export_docx($rhDoc, page_normalize(['header' => $thHdr]), 'טבלה');
file_put_contents("$tmp/th.docx", $thDocx);
$zip->open("$tmp/th.docx");
$thX1 = (string)$zip->getFromName('word/header1.xml');
$zip->close();
check('Word: טבלה בחלק הכותרת (w:tbl עם התמונה והטקסט)', str_contains($thX1, '<w:tbl>') && str_contains($thX1, 'LEFTCELL') && str_contains($thX1, '<w:drawing>'), substr($thX1, 0, 300));
$thBack = import_docx($thDocx, $rhDocId);
$tb = $thBack['page']['header'][0] ?? null;
check('Word הלוך וחזור: הטבלה בכותרת חוזרת — שני תאים, התמונה והטקסט המודגש; בלי "כותרת מורכבת" בדוח', ($tb['type'] ?? '') === 'table'
    && count($tb['content'][0]['content'] ?? []) === 2 && ($tb['content'][0]['content'][0]['content'][0]['content'][0]['type'] ?? '') === 'image'
    && str_contains(json_encode($tb, JSON_UNESCAPED_UNICODE), 'LEFTCELL') && !in_array('hf_complex', array_column($thBack['report'], 'key'), true),
    json_encode([$tb, array_column($thBack['report'], 'key')], JSON_UNESCAPED_UNICODE));

section('כיתובים והפניות צולבות (שלב 4)');
$cap = fn($label, $id, $text) => ['type' => 'paragraph', 'attrs' => ['pstyle' => 'caption'], 'content' => [
    ['type' => 'text', 'text' => "$label "], ['type' => 'seq', 'attrs' => ['label' => $label, 'id' => $id]], ['type' => 'text', 'text' => ": $text"]]];
$capDoc = ['type' => 'doc', 'content' => [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ראו '], ['type' => 'xref', 'attrs' => ['target' => 'b2', 'show' => 'full']],
        ['type' => 'text', 'text' => ' ו-'], ['type' => 'xref', 'attrs' => ['target' => 't1', 'show' => 'num']], ['type' => 'text', 'text' => ' ו-'], ['type' => 'xref', 'attrs' => ['target' => 'zz']]]],
    $cap('איור', 'a1', 'ראשון'), $cap('טבלה', 't1', 'נתונים'), $cap('איור', 'b2', 'שני'),
]];
check('seq_numbers: מספור נפרד לכל תווית, לפי הסדר', seq_numbers($capDoc) === ['a1' => ['label' => 'איור', 'n' => 1], 't1' => ['label' => 'טבלה', 'n' => 1], 'b2' => ['label' => 'איור', 'n' => 2]]);
$capText = export_text($capDoc);
check('טקסט: ההפניות והמספרים; הפניה שבורה — הודעת שגיאה', str_contains($capText, 'ראו איור 2 ו-1 ו-' . XREF_MISSING) && str_contains($capText, 'איור 2: שני') && str_contains($capText, 'טבלה 1: נתונים'), $capText);
check('HTML: סגנון כיתוב ומספרים', str_contains(export_html($capDoc, page_normalize([]))['html'], '<p class="wp-caption"') && str_contains(export_html($capDoc, page_normalize([]))['css'], '.wp-doc .wp-caption{'));
$capDocx = export_docx($capDoc, page_normalize([]), 'כיתובים');
file_put_contents("$tmp/cap.docx", $capDocx);
$zip->open("$tmp/cap.docx");
$capMain = (string)$zip->getFromName('word/document.xml');
$capStyles = (string)$zip->getFromName('word/styles.xml');
$zip->close();
check('Word: שדות SEQ בתוך סימניות _Ref, הפניות REF, סגנון Caption', substr_count($capMain, ' SEQ איור \\* ARABIC ') === 2 && str_contains($capMain, 'w:name="_Refb2"')
    && str_contains($capMain, ' REF _Refb2 \\h ') && str_contains($capMain, ' REF _Reft1 \\h ') && str_contains($capMain, '<w:pStyle w:val="Caption"/>')
    && str_contains($capStyles, 'w:styleId="Caption"') && str_contains($capMain, XREF_MISSING));
$capBack = import_docx($capDocx, 'cap');
$nodes = [];
$walkC = function ($n) use (&$walkC, &$nodes) { if (in_array($n['type'] ?? '', ['seq', 'xref'], true)) $nodes[] = $n['type'] . ':' . json_encode($n['attrs'], JSON_UNESCAPED_UNICODE); foreach ($n['content'] ?? [] as $c) $walkC($c); };
$walkC($capBack['json']);
check('Word הלוך וחזור: כיתובים, הפניות (מספר בלבד אחרי התווית) וסגנון', $nodes === ['xref:{"target":"b2","show":"num"}', 'xref:{"target":"t1","show":"num"}',
    'seq:{"label":"איור","id":"a1"}', 'seq:{"label":"טבלה","id":"t1"}', 'seq:{"label":"איור","id":"b2"}']
    && ($capBack['json']['content'][1]['attrs']['pstyle'] ?? '') === 'caption' && export_text($capBack['json']) === $capText
    && !array_filter($capBack['report'], fn($r) => $r['key'] === 'fields'), json_encode($nodes, JSON_UNESCAPED_UNICODE) . "\n" . export_text($capBack['json']));
// כמו ש-Word עצמו שומר: הסימניה עוטפת "Figure 1", ותוצאת ההפניה "Figure 1" → הפניה מלאה
$fld = fn($instr, $res) => '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> ' . $instr . ' </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>' . $res . '</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>';
$wCap = $mkDocx([
    '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
    'word/document.xml' => '<w:document ' . $WN . '><w:body><w:p><w:pPr><w:pStyle w:val="Caption"/></w:pPr><w:bookmarkStart w:id="1" w:name="_Ref123456"/><w:r><w:t xml:space="preserve">Figure </w:t></w:r>'
        . $fld('SEQ Figure \\* ARABIC', '1') . '<w:bookmarkEnd w:id="1"/><w:r><w:t>: Map</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t xml:space="preserve">See </w:t></w:r>' . $fld('REF _Ref123456 \\h', 'Figure 1') . '</w:p></w:body></w:document>',
    'word/styles.xml' => '<w:styles ' . $WN . '><w:style w:type="paragraph" w:styleId="Caption"><w:name w:val="caption"/></w:style></w:styles>',
]);
$wc = import_docx($wCap, 'wcap');
check('ייבוא מ-Word: SEQ → כיתוב, REF → הפניה מלאה, בלי סימניה נראית', ($wc['json']['content'][0]['attrs']['pstyle'] ?? '') === 'caption'
    && export_text($wc['json']) === "Figure 1: Map\nSee Figure 1" && !str_contains(json_encode($wc['json']), '"bookmark"')
    && ($wc['json']['content'][1]['content'][1]['attrs'] ?? null) === ['target' => '123456', 'show' => 'full'], export_text($wc['json']) . json_encode($wc['json']));

section('רשימת איורים (toc עם seq)');
$tofDoc = ['type' => 'doc', 'content' => [
    ['type' => 'toc', 'attrs' => ['seq' => 'איור', 'levels' => 3, 'entries' => [['t' => 'איור 1: ראשון', 'l' => 0, 'p' => '2', 'i' => 0], ['t' => 'איור 2: שני', 'l' => 0, 'p' => '3', 'i' => 1]]]],
    ['type' => 'toc', 'attrs' => ['seq' => 'טבלה', 'levels' => 3, 'entries' => []]],
    $cap('איור', 'a1', 'ראשון'), $cap('איור', 'b2', 'שני'),
]];
check('הכותרות: רשימת איורים / טבלאות', toc_title($tofDoc['content'][0]['attrs']) === 'רשימת איורים' && toc_title($tofDoc['content'][1]['attrs']) === 'רשימת טבלאות'
    && toc_title(['levels' => 3]) === 'תוכן עניינים' && toc_empty($tofDoc['content'][1]['attrs']) === 'אין כיתובי "טבלה" במסמך');
$tofDocx = export_docx($tofDoc, page_normalize([]), 'רשימה');
file_put_contents("$tmp/tof.docx", $tofDocx);
$zip->open("$tmp/tof.docx");
$tofMain = (string)$zip->getFromName('word/document.xml');
$zip->close();
check('Word: שדה TOC \\c "איור", הכותרת, בלי הדגשה בשורות', str_contains($tofMain, 'TOC \\h \\z \\c "איור"') && str_contains($tofMain, 'רשימת איורים')
    && str_contains($tofMain, 'TOC \\h \\z \\c "טבלה"') && !preg_match('#<w:b/><w:bCs/><w:rtl/></w:rPr><w:t xml:space="preserve">איור 1#u', $tofMain));
$tofBack = import_docx($tofDocx, 'tof');
check('Word הלוך וחזור: רשימת איורים חוזרת עם התווית והשורות', ($tofBack['json']['content'][0]['attrs'] ?? null) === $tofDoc['content'][0]['attrs']
    && ($tofBack['json']['content'][1]['attrs']['seq'] ?? null) === 'טבלה', json_encode($tofBack['json']['content'][0] ?? null, JSON_UNESCAPED_UNICODE));
check('HTML: הכותרת של הרשימה', str_contains(export_html($tofDoc, page_normalize([]))['html'], '<b>רשימת איורים</b>'));

// ======================================================================
section('הערות על טווח טקסט (שלב 4)');
$cm = fn($id) => [['type' => 'comment', 'attrs' => ['id' => $id]]];
$cmDoc = ['type' => 'doc', 'attrs' => ['comments' => [
    'k1' => ['author' => 'דנה לוי', 'date' => '2026-10-22T10:00:00Z', 'text' => "לנסח מחדש\nבבקשה", 'done' => false, 'replies' => [['author' => 'יוסי', 'date' => '2026-10-22T11:00:00Z', 'text' => 'מסכים']]],
    'k2' => ['author' => 'יוסי', 'date' => '2026-10-22T12:00:00Z', 'text' => 'נפתר כבר', 'done' => true, 'replies' => []],
    'gone' => ['author' => 'x', 'date' => '2026-10-22T12:00:00Z', 'text' => 'בלי טווח', 'done' => false, 'replies' => []],
]], 'content' => [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'לפני '], ['type' => 'text', 'text' => 'מסומן', 'marks' => $cm('k1')],
        ['type' => 'text', 'text' => ' וגם', 'marks' => array_merge($cm('k1'), [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]])]]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'המשך', 'marks' => $cm('k1')], ['type' => 'text', 'text' => ' ושני', 'marks' => $cm('k2')], ['type' => 'text', 'text' => ' סוף']]],
]];
$cmDocx = export_docx($cmDoc, page_normalize([]), 'הערות');
file_put_contents("$tmp/cm.docx", $cmDocx);
$zip->open("$tmp/cm.docx");
$cmMain = (string)$zip->getFromName('word/document.xml');
$cmXml = (string)$zip->getFromName('word/comments.xml');
$cmEx = (string)$zip->getFromName('word/commentsExtended.xml');
$cmCt = (string)$zip->getFromName('[Content_Types].xml');
$cmRels = (string)$zip->getFromName('word/_rels/document.xml.rels');
$zip->close();
check('Word: טווחים — התחלה בפסקה אחת וסוף בפסקה הבאה, ההערה והתשובה; בלי הערה שאין לה טווח', substr_count($cmMain, '<w:commentRangeStart') === 3 && substr_count($cmMain, '<w:commentReference') === 3
    && strpos($cmMain, 'w:commentRangeStart w:id="0"') < strpos($cmMain, 'מסומן') && strpos($cmMain, 'w:commentRangeEnd w:id="0"') > strpos($cmMain, 'המשך')
    && strpos($cmMain, 'w:commentRangeEnd w:id="0"') < strpos($cmMain, ' ושני') && !str_contains($cmXml, 'בלי טווח'));
check('Word: comments.xml (מחבר, תאריך, שתי שורות) ו-commentsExtended (תשובה, נפתר), סוג תוכן וקשרים', str_contains($cmXml, 'w:author="דנה לוי"') && str_contains($cmXml, 'w:date="2026-10-22T10:00:00Z"')
    && str_contains($cmXml, '>לנסח מחדש<') && str_contains($cmXml, '>בבקשה<') && str_contains($cmEx, 'w15:paraIdParent="10000000"') && str_contains($cmEx, 'w15:paraId="10000002" w15:done="1"')
    && str_contains($cmCt, 'wordprocessingml.comments+xml') && str_contains($cmCt, 'wordprocessingml.commentsExtended+xml') && str_contains($cmRels, 'Target="commentsExtended.xml"'));
$cmBack = import_docx($cmDocx, 'cm');
$cmAttrs = $cmBack['json']['attrs']['comments'] ?? [];
$cmText = [];
$walkCm = function ($n) use (&$walkCm, &$cmText) { foreach ($n['marks'] ?? [] as $m) if (($m['type'] ?? '') === 'comment') $cmText[$m['attrs']['id']] = ($cmText[$m['attrs']['id']] ?? '') . $n['text']; foreach ($n['content'] ?? [] as $c) $walkCm($c); };
$walkCm($cmBack['json']);
$v = array_values($cmAttrs);
check('Word הלוך וחזור: שני שרשורים, התשובה, נפתר והטווחים', count($v) === 2 && $v[0]['text'] === "לנסח מחדש\nבבקשה" && $v[0]['author'] === 'דנה לוי' && $v[0]['date'] === '2026-10-22T10:00:00Z'
    && ($v[0]['replies'][0]['text'] ?? '') === 'מסכים' && $v[0]['done'] === false && $v[1]['done'] === true
    && array_values($cmText) === ['מסומן וגםהמשך', ' ושני'] && !array_filter($cmBack['report'], fn($r) => $r['key'] === 'comments'), json_encode([$cmAttrs, $cmText], JSON_UNESCAPED_UNICODE));
check('PDF וטקסט: ההערות לא מופיעות ולא מפריעות', export_text($cmDoc) === "לפני מסומן וגם\nהמשך ושני סוף" && str_starts_with($pdfOf($cmDoc), '%PDF'));

// ======================================================================
section('מעקב שינויים (שלב 4)');
$ins = fn($a = 'דנה לוי') => [['type' => 'ins', 'attrs' => ['author' => $a, 'date' => '2026-10-22T10:00:00Z']]];
$del = fn($a = 'יוסי') => [['type' => 'del', 'attrs' => ['author' => $a, 'date' => '2026-10-22T11:00:00Z']]];
$trDoc = ['type' => 'doc', 'attrs' => ['track' => true], 'content' => [['type' => 'paragraph', 'content' => [
    ['type' => 'text', 'text' => 'לפני '], ['type' => 'text', 'text' => 'חדש', 'marks' => array_merge($ins(), [['type' => 'bold']])],
    ['type' => 'text', 'text' => ' ישן', 'marks' => $del()], ['type' => 'text', 'text' => ' אחרי'],
]]]];
check('צבע לפי מחבר — קבוע, מהרשימה', track_color('דנה לוי') === track_color('דנה לוי') && in_array(track_color('יוסי'), TRACK_COLORS, true));
check('טקסט: אחרי קבלת השינויים', export_text($trDoc) === 'לפני חדש אחרי', export_text($trDoc));
$tv = track_visual($trDoc)['content'][0]['content'];
check('PDF / HTML: תוספת — קו תחתון וצבע (המודגש נשאר), מחיקה — קו חוצה וצבע', in_array(['type' => 'underline'], $tv[1]['marks']) && in_array(['type' => 'bold'], $tv[1]['marks'])
    && in_array(['type' => 'textStyle', 'attrs' => ['color' => track_color('דנה לוי')]], $tv[1]['marks']) && in_array(['type' => 'strike'], $tv[2]['marks']));
// תצוגת השינויים (חלק ב): סופי / מקור / מחבר מוסתר — לפי הטקסט שנשאר ואילו סימונים
$tvText = fn($j) => implode('', array_map(fn($x) => $x['text'], $j['content'][0]['content']));
$tvf = track_view($trDoc, 'final');
check('track_view סופי: המחיקה יוצאת, התוספת — בלי קו ובלי צבע (המודגש נשאר)', $tvText($tvf) === 'לפני חדש אחרי'
    && $tvf['content'][0]['content'][1]['marks'] === [['type' => 'bold']], json_encode($tvf['content'][0]['content'], JSON_UNESCAPED_UNICODE));
check('track_view מקור: התוספת יוצאת, המחיקה — טקסט רגיל', $tvText(track_view($trDoc, 'original')) === 'לפני  ישן אחרי'
    && !isset(track_view($trDoc, 'original')['content'][0]['content'][1]['marks'][1]));
$tvh = track_view($trDoc, 'all', ['יוסי']);
check('track_view עם מחבר מוסתר: השינוי שלו כמו בסופי, של האחר — מסומן', $tvText($tvh) === 'לפני חדש אחרי' && in_array(['type' => 'underline'], $tvh['content'][0]['content'][1]['marks']));
check('track_view: תצוגה לא מוכרת — סימון מלא', track_view($trDoc, 'xx') === track_visual($trDoc));
// שינוי עיצוב במעקב (fmt): מודגש ואדום, לפני כן נטוי — Word: rPrChange הלוך וחזור; PDF במקור — העיצוב הקודם
$fmDoc = ['type' => 'doc', 'attrs' => ['track' => true], 'content' => [['type' => 'paragraph', 'content' => [
    ['type' => 'text', 'text' => 'עוצב', 'marks' => [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['color' => '#ff0000']],
        ['type' => 'fmt', 'attrs' => ['author' => 'דנה', 'date' => '2026-10-22T10:00:00Z', 'old' => [['type' => 'italic']]]]]],
    ['type' => 'text', 'text' => ' רגיל'],
]]]];
$fmo = track_view($fmDoc, 'original')['content'][0]['content'][0]['marks'];
check('track_view מקור: שינוי עיצוב — העיצוב הקודם במקום הנוכחי', $fmo === [['type' => 'italic']], json_encode($fmo));
check('track_view סופי / מלא: העיצוב הנוכחי, בלי הסימון', array_column(track_view($fmDoc, 'final')['content'][0]['content'][0]['marks'], 'type') === ['bold', 'textStyle']
    && array_column(track_view($fmDoc)['content'][0]['content'][0]['marks'], 'type') === ['bold', 'textStyle']);
file_put_contents("$tmp/fm.docx", export_docx($fmDoc, page_normalize([]), 'עיצוב'));
$zip->open("$tmp/fm.docx");
$fmMain = (string)$zip->getFromName('word/document.xml');
$zip->close();
check('Word: w:rPrChange — האחרון ב-rPr, עם מחבר, תאריך והעיצוב הקודם', (bool)preg_match('#<w:rPr><w:b/><w:bCs/><w:color w:val="FF0000"/><w:rtl/><w:rPrChange w:id="\d+" w:author="דנה" w:date="2026-10-22T10:00:00Z"><w:rPr><w:i/><w:iCs/></w:rPr></w:rPrChange></w:rPr>#u', $fmMain),
    substr($fmMain, strpos($fmMain, '<w:body>'), 400));
$fmBack = import_docx(file_get_contents("$tmp/fm.docx"), 'fm')['json']['content'][0]['content'][0];
$fmMark = array_values(array_filter($fmBack['marks'], fn($m) => $m['type'] === 'fmt'))[0] ?? null;
check('Word הלוך וחזור: שינוי העיצוב חוזר — מחבר, תאריך והעיצוב הקודם', $fmMark && $fmMark['attrs']['author'] === 'דנה' && $fmMark['attrs']['date'] === '2026-10-22T10:00:00Z'
    && $fmMark['attrs']['old'] === [['type' => 'italic']] && in_array(['type' => 'bold'], $fmBack['marks']), json_encode($fmBack, JSON_UNESCAPED_UNICODE));
check('גרסת מבנה: שינוי עיצוב במעקב צריך 7 (גם בפסקה עם עצירות טאב)', doc_schema_need($fmDoc) === 7
    && doc_schema_need(['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['tabs' => [['pos' => 50]]], 'content' => $fmDoc['content'][0]['content']]]]) === 7);
check('טקסט: שינוי עיצוב לא משנה את הטקסט', export_text($fmDoc) === 'עוצב רגיל');
// שינוי עיצוב פסקה במעקב (pchg): עכשיו כותרת 2 ממורכזת, לפני כן פסקה רגילה עם הזחה — Word: pPrChange הלוך וחזור
$pcDoc = ['type' => 'doc', 'attrs' => ['track' => true], 'content' => [
    ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center', 'pchg' => ['author' => 'דנה', 'date' => '2026-10-22T10:00:00Z',
        'old' => ['type' => 'paragraph', 'attrs' => ['indent' => 1, 'spaceAfter' => 12]]]], 'content' => [['type' => 'text', 'text' => 'כותרת חדשה']]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'רגילה']]]]];
$pco = track_view($pcDoc, 'original')['content'][0];
$pcf = track_view($pcDoc, 'final')['content'][0];
check('track_view מקור: הפסקה כמו שהייתה (סוג ומאפיינים); סופי — הנוכחית; בשניהם בלי pchg', $pco['type'] === 'paragraph' && $pco['attrs'] === ['indent' => 1, 'spaceAfter' => 12]
    && $pcf['type'] === 'heading' && !isset($pcf['attrs']['pchg']) && !isset(track_final($pcDoc)['content'][0]['attrs']['pchg']), json_encode([$pco, $pcf], JSON_UNESCAPED_UNICODE));
file_put_contents("$tmp/pc.docx", export_docx($pcDoc, page_normalize([]), 'פסקה'));
$zip->open("$tmp/pc.docx");
$pcMain = (string)$zip->getFromName('word/document.xml');
$zip->close();
check('Word: w:pPrChange — האחרון ב-pPr, עם מחבר, תאריך והפסקה הקודמת (בלי סגנון הכותרת, עם ההזחה)', (bool)preg_match('#<w:pPr><w:pStyle w:val="Heading2"/>.*?<w:jc w:val="center"/><w:pPrChange w:id="\d+" w:author="דנה" w:date="2026-10-22T10:00:00Z"><w:pPr>(?:(?!pStyle).)*?<w:ind w:start="720"/>(?:(?!pStyle).)*?</w:pPr></w:pPrChange></w:pPr>#u', $pcMain),
    substr($pcMain, strpos($pcMain, '<w:body>'), 500));
$pcBack = import_docx(file_get_contents("$tmp/pc.docx"), 'pc');
$pb = $pcBack['json']['content'][0];
check('Word הלוך וחזור: שינוי עיצוב הפסקה חוזר — מחבר, תאריך, הפסקה הקודמת; בלי שורה בדוח', $pb['type'] === 'heading' && ($pb['attrs']['pchg']['author'] ?? '') === 'דנה'
    && ($pb['attrs']['pchg']['date'] ?? '') === '2026-10-22T10:00:00Z' && ($pb['attrs']['pchg']['old']['type'] ?? '') === 'paragraph' && ($pb['attrs']['pchg']['old']['attrs']['indent'] ?? 0) === 1
    && ($pb['attrs']['pchg']['old']['attrs']['spaceAfter'] ?? null) == 12 && empty($pcBack['json']['content'][1]['attrs']['pchg']) && !$pcBack['report'],
    json_encode([$pb['attrs'], $pcBack['report']], JSON_UNESCAPED_UNICODE));
check('גרסת מבנה: שינוי עיצוב פסקה צריך 10; גוף לשילוב — בלי pPrChange', doc_schema_need($pcDoc) === 10 && !str_contains(export_ooxml($pcDoc, page_normalize([]))['body'], 'pPrChange'));
// מזהה פסקה (חבילת היצירה, mbe-format.md סעיפים 3 ו-7): attrs.id ↔ סימנייה נסתרת _mbe_p<id> ב-Word
$pidDoc = ['type' => 'doc', 'content' => [
    ['type' => 'heading', 'attrs' => ['level' => 1, 'id' => 'pAbc123XYZ0'], 'content' => [['type' => 'text', 'text' => 'כותרת']]],
    ['type' => 'paragraph', 'attrs' => ['id' => 'p_2'], 'content' => [['type' => 'text', 'text' => 'פסקה']]],
    ['type' => 'paragraph', 'attrs' => ['id' => 'p_2'], 'content' => [['type' => 'text', 'text' => 'כפולה']]],
    ['type' => 'paragraph', 'attrs' => ['id' => 'bad-id'], 'content' => [['type' => 'text', 'text' => 'פסולה']]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'בלי']]],
]];
$pidX = export_docx($pidDoc, page_normalize([]), 'x');
file_put_contents("$tmp/pid.docx", $pidX);
$pidZ = new ZipArchive();
$pidZ->open("$tmp/pid.docx");
preg_match_all('/w:name="(_mbe_p[^"]*)"/', (string)$pidZ->getFromName('word/document.xml'), $pidM);
check('מזהה פסקה → Word: סימנייה נסתרת _mbe_p<id> לכל פסקה עם מזהה תקין, פעם אחת לכל מזהה', $pidM[1] === ['_mbe_ppAbc123XYZ0', '_mbe_pp_2'], json_encode($pidM[1]));
$pidB = array_map(fn($b) => [$b['type'], $b['attrs']['id'] ?? null], import_docx($pidX, 'x')['json']['content']);
check('Word → מזהה פסקה: הסימנייה חוזרת ל-attrs.id (גם בכותרת), ולא כסימנייה רגילה', $pidB === [['heading', 'pAbc123XYZ0'], ['paragraph', 'p_2'], ['paragraph', null], ['paragraph', null], ['paragraph', null]]
    && !str_contains(json_encode(import_docx($pidX, 'x')['json']), '"bookmark"'), json_encode($pidB));
check('גרסת מבנה: מזהה פסקה צריך 11; כותב בגרסה 10 על מסמך עם מזהה → schema_newer', doc_schema_need($pidDoc) === 11
    && doc_schema_need(['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => null, 'pchg' => ['author' => 'א', 'old' => ['type' => 'paragraph', 'attrs' => []]]]]]]) === 10);
check('מזהה פסקה: לא בגוף לשילוב במארחת (export_ooxml)', !str_contains(export_ooxml($pidDoc, page_normalize([]))['body'], '_mbe_p'));
$trDocx = export_docx($trDoc, page_normalize([]), 'מעקב');
file_put_contents("$tmp/tr.docx", $trDocx);
$zip->open("$tmp/tr.docx");
$trMain = (string)$zip->getFromName('word/document.xml');
$trSet = (string)$zip->getFromName('word/settings.xml');
$zip->close();
check('Word: w:ins ו-w:del (delText) עם מחבר ותאריך; trackRevisions', preg_match('#<w:ins w:id="\d+" w:author="דנה לוי" w:date="2026-10-22T10:00:00Z"><w:r>.*?חדש.*?</w:ins>#u', $trMain) === 1
    && preg_match('#<w:del w:id="\d+" w:author="יוסי"[^>]*><w:r>.*?<w:delText xml:space="preserve"> ישן</w:delText>.*?</w:del>#u', $trMain) === 1 && str_contains($trSet, '<w:trackRevisions/>'));
$trBack = import_docx($trDocx, 'tr');
$bk = $trBack['json']['content'][0]['content'];
check('Word הלוך וחזור: התוספת והמחיקה עם המחבר והתאריך, והמעקב פועל', ($trBack['json']['attrs']['track'] ?? false) === true
    && in_array($ins()[0], $bk[1]['marks'] ?? []) && in_array(['type' => 'bold'], $bk[1]['marks'] ?? []) && in_array($del()[0], $bk[2]['marks'] ?? []) && $bk[2]['text'] === ' ישן'
    && !array_filter($trBack['report'], fn($r) => $r['key'] === 'tracked'), json_encode($bk, JSON_UNESCAPED_UNICODE));
$trBody = export_ooxml($trDoc, page_normalize([]))['body'];
check('גוף לשילוב: הטקסט אחרי קבלת השינויים — בלי סימונים ובלי הטקסט המחוק', !str_contains($trBody, '<w:ins') && !str_contains($trBody, 'ישן') && str_contains($trBody, 'חדש'));

// ======================================================================
section('מעבר מקטע בתוך רשימה (ביקורת 04, R01)');
$nsDoc = ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'לפני']]],
    ['type' => 'sectionBreak', 'attrs' => ['page' => ['orientation' => 'landscape']]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'אחרי']]]]]]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'סוף']]]]];
$nsTop = sections_top($nsDoc);
check('sections_top: המעבר יוצא מהרשימה, מיד אחריה; שום טקסט לא נעלם', array_column($nsTop['content'], 'type') === ['bulletList', 'sectionBreak', 'paragraph']
    && preg_split('/\s+/u', trim(export_text($nsTop))) === preg_split('/\s+/u', trim(export_text($nsDoc))) && !str_contains(json_encode($nsTop['content'][0]), 'sectionBreak'), json_encode(array_column($nsTop['content'], 'type')));
$nsPdf = $pdfOf($nsDoc);
check('PDF: מעבר מקטע מתוך רשימה — עמוד שני לרוחב', preg_match_all('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $nsPdf, $mb) === 2 && (float)$mb[1][1] > (float)$mb[2][1], json_encode($mb[1] ?? []));
$nsDocx = export_docx($nsDoc, page_normalize([]), 'מקטע');
file_put_contents("$tmp/ns.docx", $nsDocx);
$zip->open("$tmp/ns.docx");
$nsMain = (string)$zip->getFromName('word/document.xml');
$zip->close();
check('Word: הגדרות המקטע בפסקה מחוץ לרשימה (בלי numPr)', preg_match('#<w:p><w:pPr><w:bidi/><w:sectPr#', $nsMain) === 1 && substr_count($nsMain, '<w:sectPr') === 2, substr_count($nsMain, '<w:sectPr'));

// ======================================================================
section('הגדרות עמוד');
$p = page_normalize(['size' => 'Legal', 'orientation' => 'landscape', 'margins' => ['top' => 500, 'left' => 'x'], 'font' => ['family' => 'David<script>', 'size' => 200],
    'styles' => ['h1' => ['size' => 18, 'color' => 'red', 'underline' => true]], 'numbering' => ['format' => 'bad', 'align' => 'left']]);
check('ערכים לא תקינים נדחים / נחתכים', $p['size'] === 'A4' && $p['orientation'] === 'landscape' && $p['margins']['top'] == 80 && $p['margins']['left'] == 25
    && $p['font']['family'] === 'Davidscript' && $p['font']['size'] == 72 && $p['styles']['h1']['size'] == 18 && !isset($p['styles']['h1']['color'])
    && $p['styles']['h1']['underline'] === true && $p['numbering']['format'] === 'n' && $p['numbering']['align'] === 'left');
check('גודל לרוחב', page_mm($p) === [297, 210]);

// ======================================================================
section('שיתוף והרשאות (שלב 5)');
require_once __DIR__ . '/../lib/users.php';
require_once __DIR__ . '/../lib/share.php';
$owner = account_for_identity(['sub' => 'sh-1', 'email' => 'owner@example.com', 'name' => 'בעלת']);
$shDoc = doc_new('a:' . $owner['id'], 'לשיתוף', ['type' => 'doc', 'content' => [para([text_node('טקסט לשיתוף')])]], null, 'a:' . $owner['id'], 'בעלת');
$shRow = doc_find($shDoc, 'a:' . $owner['id']);
share_set($shRow, $owner, ' Guest@Example.com ', 'comment');
// האורח נכנס בפעם הראשונה אחרי השיתוף — החשבון נוצר עם אותו מייל, והשיתוף כבר פעיל
$guest = account_for_identity(['sub' => 'sh-2', 'email' => 'guest@example.com', 'name' => 'אורח']);
$stranger = account_for_identity(['sub' => 'sh-3', 'email' => 'other@example.com', 'name' => 'זר']);
check('שיתוף לפי מייל לפני שהחשבון קיים — פעיל כשנכנסים (בלי תלות באותיות)', share_role($shRow, $guest) === 'comment' && share_role($shRow, $owner) === 'owner' && share_role($shRow, $stranger) === null);
check('doc_access: מעיר — פתיחה ושמירת הערות כן, עריכה / בעלות לא; זר — "לא נמצא"', doc_access($shDoc, $guest, 'comment')[1] === 'comment'
    && throws(fn() => doc_access($shDoc, $guest, 'edit'), 'forbidden') && throws(fn() => doc_access($shDoc, $guest, 'owner'), 'forbidden') && throws(fn() => doc_access($shDoc, $stranger), 'not_found'));
$sw = shared_with($guest);
check('shared_with: המסמך ברשימה של האורח, עם התפקיד והבעלים; לא ברשימה של הבעלים', count($sw) === 1 && $sw[0]['doc'] === $shDoc && $sw[0]['role'] === 'comment' && $sw[0]['owner'] === 'בעלת' && !shared_with($owner), json_encode($sw, JSON_UNESCAPED_UNICODE));
check('shares_of: המייל (באותיות קטנות), התפקיד, השם — כשכבר נכנס', shares_of($shDoc) === [['email' => 'guest@example.com', 'role' => 'comment', 'name' => 'אורח', 'joined' => true]], json_encode(shares_of($shDoc), JSON_UNESCAPED_UNICODE));
check('share_set: מייל לא תקין, המייל של הבעלים, תפקיד לא קיים — שגיאה', throws(fn() => share_set($shRow, $owner, 'לא-מייל', 'view'), 'bad_email')
    && throws(fn() => share_set($shRow, $owner, 'OWNER@example.com', 'view'), 'bad_email') && throws(fn() => share_set($shRow, $owner, 'x@example.com', 'admin'), 'bad_role'));
// מעיר: רק הערות — גם כשההערה מפצלת את הטקסט, ועם ערכי ברירת מחדל שהעורך מוסיף
$base = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'שלום עולם יפה']]]]];
$withC = ['type' => 'doc', 'attrs' => ['schema' => 10, 'comments' => ['c1' => ['author' => 'אורח', 'text' => 'הערה']], 'track' => null], 'content' => [['type' => 'paragraph', 'attrs' => ['indent' => 0, 'textAlign' => null],
    'content' => [['type' => 'text', 'text' => 'שלום '], ['type' => 'text', 'text' => 'עולם', 'marks' => [['type' => 'comment', 'attrs' => ['id' => 'c1']]]], ['type' => 'text', 'text' => ' יפה']]]]];
$changed = $withC; $changed['content'][0]['content'][2]['text'] = ' מכוער';
$bolded = $withC; $bolded['content'][0]['content'][0]['marks'] = [['type' => 'bold']];
check('only_comments_changed: הערה (גם מפצלת טקסט, עם ברירות מחדל) — כן; שינוי טקסט או עיצוב — לא', only_comments_changed($base, $withC) && !only_comments_changed($base, $changed) && !only_comments_changed($base, $bolded));
share_set($shRow, $owner, 'guest@example.com', '');
check('ביטול שיתוף — האורח כבר לא רואה', share_role($shRow, $guest) === null && !shared_with($guest));
share_set($shRow, $owner, 'guest@example.com', 'view');
doc_delete($shRow);
$cnt = db()->prepare('SELECT COUNT(*) FROM shares WHERE doc = ?'); $cnt->execute([$shDoc]);
check('מחיקה לצמיתות — גם השיתופים', (int)$cnt->fetchColumn() === 0);

section('קישור עריכה לזר (שלב 5)');
require_once __DIR__ . '/../lib/links.php';
$lnOwner = ['id' => 501, 'role' => 'user'];
$lnAdmin = ['id' => 502, 'role' => 'admin'];
$lnDoc = doc_new('a:501', 'לזר', ['type' => 'doc', 'content' => [para([text_node('טקסט')])]], null, 'a:501', 'בעלים');
$lnRow = doc_find($lnDoc, 'a:501');
$l = link_request($lnRow, $lnOwner);
$tok = link_row($lnDoc)['token'];
check('בעלים שאינו מנהל — הקישור ממתין, בלי כתובת; הזר מקבל "ממתין לאישור"', $l['status'] === 'pending' && $l['url'] === null && throws(fn() => guest_doc($tok), 'link_pending'));
link_decide($lnDoc, true, $lnAdmin);
$l = link_public(link_row($lnDoc));
check('אחרי אישור המנהל — פעיל ל-' . LINK_DAYS . ' יום, הכתובת עם הטוקן אחרי #', $l['status'] === 'active' && str_ends_with($l['url'], 'guest.html#k=' . $tok)
    && abs($l['expires_at'] - time() - LINK_DAYS * 86400) < 5 && guest_doc($tok)['id'] === $lnDoc, json_encode($l));
[$gu1] = guest_identity($tok, 'device0001', 'רות');
[$gu2, $gn2] = guest_identity($tok, 'device0002', '  רות   כהן ');
check('זהות הזר: לפי המכשיר (שני מכשירים — שני מזהים), השם מנוקה ו"(אורח)"', $gu1 !== $gu2 && str_starts_with($gu1, 'g:') && $gn2 === 'רות כהן (אורח)'
    && throws(fn() => guest_identity($tok, 'device0001', ' '), 'bad_name') && throws(fn() => guest_identity($tok, 'x', 'רות'), 'bad_guest'));
check('טוקן שגוי / לא בפורמט — "לא בתוקף"', throws(fn() => guest_doc(str_repeat('a', 32)), 'link_invalid') && throws(fn() => guest_doc('../x'), 'link_invalid') && throws(fn() => guest_doc(null), 'link_invalid'));
link_request($lnRow, $lnAdmin);
$tok2 = link_row($lnDoc)['token'];
check('קישור חדש (מנהל — פעיל מיד) מחליף את הקודם: הישן מפסיק לעבוד', $tok2 !== $tok && link_public(link_row($lnDoc))['status'] === 'active'
    && throws(fn() => guest_doc($tok), 'link_invalid') && guest_doc($tok2)['id'] === $lnDoc);
db()->prepare('UPDATE links SET expires_at = ? WHERE doc = ?')->execute([time() - 1, $lnDoc]);
check('פג תוקף — "לא בתוקף", ולבעלים: expired', throws(fn() => guest_doc($tok2), 'link_invalid') && link_public(link_row($lnDoc))['status'] === 'expired');
link_request($lnRow, $lnAdmin);
$tok3 = link_row($lnDoc)['token'];
$adm = links_all();
check('מסך המנהל: הקישור, שם המסמך והסטטוס', count(array_filter($adm, fn($x) => $x['doc'] === $lnDoc && $x['title'] === 'לזר' && $x['status'] === 'active')) === 1, json_encode($adm, JSON_UNESCAPED_UNICODE));
doc_trash($lnRow);
check('מסמך בסל — הקישור לא עובד', throws(fn() => guest_doc($tok3), 'link_invalid'));
doc_restore(doc_find($lnDoc, 'a:501', true));
link_decide($lnDoc, false, $lnAdmin);
check('דחייה / ביטול — הקישור נמחק', link_row($lnDoc) === null && throws(fn() => guest_doc($tok3), 'link_invalid'));
link_request($lnRow, $lnAdmin);
doc_delete(doc_find($lnDoc, 'a:501'));
check('מחיקה לצמיתות — גם הקישור', link_row($lnDoc) === null);

section('עריכה משותפת (שלב 5)');
require_once __DIR__ . '/../lib/collab.php';
$coDoc = doc_new('a:601', 'משותף', ['type' => 'doc', 'content' => [para([text_node('אבג')])]], null, 'a:601', 'דנה');
$coRow = doc_find($coDoc, 'a:601');
$c0 = collab_state($coRow);
$ins = fn(int $pos, string $t) => ['stepType' => 'replace', 'from' => $pos, 'to' => $pos, 'slice' => ['content' => [['type' => 'text', 'text' => $t]]]];
$jsonWith = fn(string $t) => ['type' => 'doc', 'attrs' => ['schema' => SCHEMA_VERSION], 'content' => [para([text_node($t)])]];
$r1 = collab_push($coRow, 'owner', ['epoch' => $c0['epoch'], 'v' => 0, 'client' => 'clienta1', 'steps' => [$ins(4, 'ד')], 'json' => $jsonWith('אבגד')], 'a:601', 'דנה');
$r2 = collab_push($coRow, 'edit', ['epoch' => $c0['epoch'], 'v' => 0, 'client' => 'clientb1', 'steps' => [$ins(1, 'ז')], 'json' => $jsonWith('זאבג')], 'a:602', 'יוסי');
check('שליחה: מעודכן — מתקבל (v=1) והמסמך נשמר; מאחור — נדחה עם הגרסה הנוכחית', $r1['ok'] && $r1['v'] === 1 && !$r2['ok'] && $r2['v'] === 1
    && doc_json(doc_find($coDoc, 'a:601'))['content'][0]['content'][0]['text'] === 'אבגד', json_encode([$r1, $r2]));
$p = collab_pull(doc_find($coDoc, 'a:601'), 'edit', ['epoch' => $c0['epoch'], 'since' => 0, 'client' => 'clientb1', 'sel' => [2, 2, 0]], 'a:602', 'יוסי');
check('משיכה: הצעד של דנה, עם הלקוח שלה; ודנה לא "אחרים" של עצמה', $p['v'] === 1 && count($p['steps']) === 1 && $p['steps'][0][1] === 'clienta1' && $p['steps'][0][0]['slice']['content'][0]['text'] === 'ד', json_encode($p, JSON_UNESCAPED_UNICODE));
$r3 = collab_push(doc_find($coDoc, 'a:601'), 'edit', ['epoch' => $c0['epoch'], 'v' => 1, 'client' => 'clientb1', 'steps' => [$ins(1, 'ז')], 'json' => $jsonWith('זאבגד')], 'a:602', 'יוסי');
$pa = collab_pull(doc_find($coDoc, 'a:601'), 'owner', ['epoch' => $c0['epoch'], 'since' => 1, 'client' => 'clienta1'], 'a:601', 'דנה');
check('אחרי משיכה — יוסי שולח שוב ומתקבל; דנה מושכת את שלו, ורואה שיוסי במסמך', $r3['ok'] && $r3['v'] === 2 && count($pa['steps']) === 1
    && $pa['others'][0]['name'] === 'יוסי' && $pa['others'][0]['sel'] === [2, 2, 0], json_encode($pa, JSON_UNESCAPED_UNICODE));
$vs = versions_list(doc_find($coDoc, 'a:601'));
check('גרסאות: השמירות של שניהם — גרסה אחת עם שני השמות', $vs[0]['by'] === 'דנה, יוסי', json_encode(array_column($vs, 'by'), JSON_UNESCAPED_UNICODE));
check('נעילה ישנה (הטמעה / קישור לזר) לא נכנסת כשעורכים במסמך', lock_take($coDoc, 'g:zzz', 'אורח', 'h')['ok'] === false);
check('מעיר: צעד שאינו הערה — 403', throws(fn() => collab_push(doc_find($coDoc, 'a:601'), 'comment', ['epoch' => $c0['epoch'], 'v' => 2, 'client' => 'clientc1',
    'steps' => [$ins(1, 'x')], 'json' => $jsonWith('xזאבגד')], 'a:603', 'מעיר'), 'forbidden'));
$cmt = ['stepType' => 'addMark', 'from' => 1, 'to' => 3, 'mark' => ['type' => 'comment', 'attrs' => ['id' => 'c1']]];
$cj = ['type' => 'doc', 'attrs' => ['schema' => SCHEMA_VERSION], 'content' => [para([['type' => 'text', 'text' => 'זא', 'marks' => [['type' => 'comment', 'attrs' => ['id' => 'c1']]]], text_node('בגד')])]];
$rc = collab_push(doc_find($coDoc, 'a:601'), 'comment', ['epoch' => $c0['epoch'], 'v' => 2, 'client' => 'clientc1', 'steps' => [$cmt], 'json' => $cj], 'a:603', 'מעיר');
check('מעיר: צעד הערה — מתקבל', $rc['ok'] && $rc['v'] === 3);
// שמירה מבחוץ (שחזור / הטמעה) — עידן חדש: כולם טוענים מחדש
doc_save(doc_find($coDoc, 'a:601'), $jsonWith('חדש'), null, 'x', 'מבחוץ');
$pr = collab_pull(doc_find($coDoc, 'a:601'), 'owner', ['epoch' => $c0['epoch'], 'since' => 3, 'client' => 'clienta1'], 'a:601', 'דנה');
$c1 = collab_state(doc_find($coDoc, 'a:601'));
check('שמירה מבחוץ — עידן חדש: משיכה מחזירה reload, והצעדים הישנים נמחקו', ($pr['reload'] ?? false) && $c1['epoch'] !== $c0['epoch'] && (int)$c1['v'] === 0
    && (int)db()->query("SELECT COUNT(*) FROM collab_steps WHERE doc = " . db()->quote($coDoc))->fetchColumn() === 0);
check('שליחה בעידן ישן — reload', (collab_push(doc_find($coDoc, 'a:601'), 'owner', ['epoch' => $c0['epoch'], 'v' => 3, 'client' => 'clienta1', 'steps' => [$ins(1, 'x')], 'json' => $jsonWith('x')], 'a:601', 'דנה')['reload'] ?? false) === true);
check('בקשה פגומה — שגיאה', throws(fn() => collab_push(doc_find($coDoc, 'a:601'), 'owner', ['epoch' => $c1['epoch'], 'v' => 0, 'client' => 'x', 'steps' => [], 'json' => []], 'a:601', 'דנה'), 'bad_request'));

section('תיקיות ומועדפים (שלב 5)');
require_once __DIR__ . '/../lib/folders.php';
$fs = 'a:701';
$fA = folder_create($fs, ' פרויקטים ', null);
$fB = folder_create($fs, 'לקוחות', $fA);
$fC = folder_create($fs, 'דנה', $fB);
$names = array_column(folders_of($fs), 'name', 'id');
check('תיקיות בתוך תיקיות: שלוש רמות, השם מנוקה', $names[$fA] === 'פרויקטים' && folder_ancestors($fs, $fC) === [$fC, $fB, $fA]);
check('תיקייה לתוך עצמה / לתוך תיקייה שבתוכה — שגיאה', throws(fn() => folder_move($fs, $fA, $fC), 'cycle') && throws(fn() => folder_move($fs, $fA, $fA), 'cycle'));
check('תיקייה של חשבון אחר — "לא נמצאה"; שם ריק — שגיאה', throws(fn() => folder_get('a:702', $fA), 'not_found') && throws(fn() => folder_create($fs, '  ', null), 'bad_name'));
$fd = doc_new($fs, 'בתיקייה', null, null, 'a:701', 'דנה');
doc_move(doc_find($fd, $fs), $fC);
$fold = fn() => db()->query('SELECT folder FROM docs WHERE id = ' . db()->quote($fd))->fetchColumn();
check('מסמך לתיקייה; לתיקייה של חשבון אחר — שגיאה', $fold() === $fC && throws(fn() => doc_move(doc_find($fd, $fs), folder_create('a:702', 'זרה', null)), 'not_found'));
folder_delete($fs, $fC);
check('מחיקת תיקייה: המסמך שבתוכה עובר לתיקייה שמעליה, לא נמחק', $fold() === $fB && doc_find($fd, $fs)['title'] === 'בתיקייה');
folder_move($fs, $fB, null);
folder_delete($fs, $fA);
check('העברת תיקייה לראש, ומחיקת התיקייה שהייתה מעליה — התת-תיקייה נשארת', array_column(folders_of($fs), 'name') === ['לקוחות']);
$deep = null; for ($i = 0; $i < FOLDER_DEPTH; $i++) $deep = folder_create($fs, "רמה $i", $deep);
check('עד ' . FOLDER_DEPTH . ' רמות', throws(fn() => folder_create($fs, 'עמוק מדי', $deep), 'too_deep'));
$favAcc = ['id' => 701];
fav_set($favAcc, $fd, true); fav_set($favAcc, $fd, true);
check('מועדפים: כוכב (פעמיים — פעם אחת), והסרה', favs_of($favAcc) === [$fd] && (fav_set($favAcc, $fd, false) || true) && favs_of($favAcc) === []);
fav_set($favAcc, $fd, true);
doc_delete(doc_find($fd, $fs));
check('מחיקה לצמיתות — גם מהמועדפים', favs_of($favAcc) === []);

section('יומן פעילות (שלב 5)');
$acD = doc_new('a:801', 'יומן', null, null, 'a:801', 'דנה');
$acR = doc_find($acD, 'a:801');
activity($acD, 'a:801', 'דנה', 'open'); activity($acD, 'a:801', 'דנה', 'open');
$acR = doc_save($acR, ['type' => 'doc', 'content' => [para([text_node('א')])]], null, 'a:801', 'דנה');
$acR = doc_save($acR, ['type' => 'doc', 'content' => [para([text_node('אב')])]], null, 'a:801', 'דנה');
$acR = doc_save($acR, ['type' => 'doc', 'content' => [para([text_node('אבג')])]], null, 'a:802', 'יוסי');
$log = activity_of($acD);
check('יצירה, פתיחה ועריכה — פתיחות ועריכות רצופות של אותו אדם מתאחדות; עריכה של אחר — שורה משלה',
    array_map(fn($a) => $a['what'] . ':' . $a['who'], $log) === ['edit:יוסי', 'edit:דנה', 'open:דנה', 'create:דנה'] && $log[0]['text'] === 'עריכה', json_encode($log, JSON_UNESCAPED_UNICODE));
activity($acD, 'a:801', 'דנה', 'open');
check('פתיחה אחרי עריכה של אחרים — אותה שורה (בתוך החלון), לא שורה חדשה', count(array_filter(activity_of($acD), fn($a) => $a['what'] === 'open')) === 1);
$vsA = versions_list($acR);
doc_restore_version($acR, end($vsA)['id'], 'a:801', 'דנה', null);
check('שחזור גרסה — נרשם, עם מספר הגרסה', activity_of($acD)[0]['what'] === 'restore' && str_starts_with(activity_of($acD)[0]['detail'], 'גרסה '));
// הערות: הערה חדשה, תשובה, "נפתר" ופתיחה מחדש, מחיקה — שורה לכל אחת (עם תחילת הטקסט); שמירה שרק הערות השתנו בה — לא "עריכה"
$cmJ = fn(array $comments, string $t = 'אבג') => ['type' => 'doc', 'attrs' => ['comments' => $comments],
    'content' => [para([text_node($t, $comments ? [['type' => 'comment', 'attrs' => ['id' => 'c1']]] : [])])]];
$long = str_repeat('מילה ', 20);
$acR = doc_save(doc_find($acD, 'a:801'), $cmJ(['c1' => ['author' => 'יוסי', 'text' => $long, 'replies' => []]]), null, 'a:802', 'יוסי');
$acR = doc_save($acR, $cmJ(['c1' => ['author' => 'יוסי', 'text' => $long, 'replies' => [['author' => 'דנה', 'text' => 'מסכימה']]]]), null, 'a:801', 'דנה');
$acR = doc_save($acR, $cmJ(['c1' => ['author' => 'יוסי', 'text' => $long, 'done' => true, 'replies' => [['author' => 'דנה', 'text' => 'מסכימה']]]]), null, 'a:801', 'דנה');
$acR = doc_save($acR, $cmJ(['c1' => ['author' => 'יוסי', 'text' => $long, 'done' => false, 'replies' => [['author' => 'דנה', 'text' => 'מסכימה']]]]), null, 'a:802', 'יוסי');
$acR = doc_save($acR, $cmJ([]), null, 'a:801', 'דנה');
$cl = array_slice(activity_of($acD), 0, 6);
check('יומן: הערה, תשובה, "נפתרה", פתיחה מחדש ומחיקה — על שם מי ששמר, עם תחילת הטקסט; בלי "עריכה" כשרק הערות השתנו',
    array_map(fn($a) => $a['what'] . ':' . $a['who'], $cl) === ['uncomment:דנה', 'reopen:יוסי', 'resolve:דנה', 'reply:דנה', 'comment:יוסי', 'restore:דנה']
    && $cl[3]['detail'] === 'מסכימה' && mb_strlen($cl[4]['detail']) === 60 && str_ends_with($cl[4]['detail'], '…') && $cl[4]['text'] === 'הערה',
    json_encode($cl, JSON_UNESCAPED_UNICODE));
$acR = doc_save($acR, $cmJ(['c2' => ['author' => 'דנה', 'text' => 'עוד']], 'שונה'), null, 'a:801', 'דנה');
// (העריכה מתאחדת עם שורת העריכה הקודמת של אותו אדם — היא באותה שנייה, לא בהכרח ראשונה)
$now2 = array_values(array_filter(activity_of($acD), fn($a) => $a['t'] === activity_of($acD)[0]['t']));
check('הערה יחד עם שינוי בטקסט — גם "הערה" וגם "עריכה"', in_array('comment', array_column($now2, 'what'), true) && in_array('edit', array_column($now2, 'what'), true), json_encode($now2, JSON_UNESCAPED_UNICODE));
doc_delete(doc_find($acD, 'a:801'));
check('מחיקה לצמיתות — גם היומן', activity_of($acD) === []);

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
// R09 / F02: כותב ישן (גרסה 2) על מסמך עם מבנה של גרסה 3 — היה מוחק אותו בשקט (צמתים לא מוכרים נזרקים בטעינה)
$p3 = fn(array ...$inl) => ['type' => 'paragraph', 'content' => $inl];
foreach ([
    'כיתוב' => ['type' => 'doc', 'content' => [$p3(['type' => 'text', 'text' => 'איור '], ['type' => 'seq', 'attrs' => ['label' => 'איור', 'id' => 'a1']])]],
    'תוכן עניינים' => ['type' => 'doc', 'content' => [['type' => 'toc'], $p3(['type' => 'text', 'text' => 'א'])]],
    'הערה על טקסט' => ['type' => 'doc', 'attrs' => ['comments' => ['c1' => ['text' => 'הערה']]], 'content' => [$p3(['type' => 'text', 'text' => 'א', 'marks' => [['type' => 'comment', 'attrs' => ['id' => 'c1']]]])]],
    'הערת סיום' => ['type' => 'doc', 'content' => [$p3(['type' => 'text', 'text' => 'א'], ['type' => 'footnote', 'attrs' => ['text' => 'ס', 'end' => true]])]],
] as $what => $j3) {
    // נשמר בעבר עם המספר 2 (לפני שעלה) — הדחייה לפי התוכן, לא לפי המספר
    $j3['attrs'] = ($j3['attrs'] ?? []) + ['schema' => 2];
    $r3 = doc_find(doc_new('a:99', "גרסה 3 — $what", $j3, null, 'u1', 'דנה'), 'a:99');
    check("גרסת מבנה: כותב בגרסה 2 על מסמך עם $what → schema_newer, והמסמך לא השתנה", throws(fn() => doc_save($r3, ['type' => 'doc', 'attrs' => ['schema' => 2], 'content' => [$p3(['type' => 'text', 'text' => 'ישן'])]], null, 'u2', 'ישן'), 'schema_newer')
        && doc_find($r3['id'], 'a:99')['version'] === $r3['version']);
    check("גרסת מבנה: כותב בגרסה 3 על מסמך עם $what — נשמר", (int)doc_save($r3, array_replace_recursive($j3, ['attrs' => ['schema' => 3]]), null, 'u1', 'דנה')['version'] === (int)$r3['version'] + 1);
}
$r2 = doc_find(doc_new('a:99', 'גרסה 2 בלבד', ['type' => 'doc', 'content' => [$p3(['type' => 'text', 'text' => 'רגיל'])]], null, 'u1', 'דנה'), 'a:99');
$r2 = doc_save($r2, ['type' => 'doc', 'attrs' => ['schema' => 2], 'content' => [$p3(['type' => 'text', 'text' => 'ישן'])]], null, 'u2', 'ישן');
check('גרסת מבנה: כותב בגרסה 2 על מסמך בלי מבנה חדש — עדיין נשמר (גם פעם שנייה)', doc_save($r2, ['type' => 'doc', 'attrs' => ['schema' => 2], 'content' => [$p3(['type' => 'text', 'text' => 'שוב'])]], null, 'u2', 'ישן')['version'] > $r2['version']);
// מעקב שינויים (גרסה 4): כותב בגרסה 3 (לפני המעקב) לא שומר על מסמך עם ins / del או עם המעקב פועל
foreach (['סימון תוספת' => ['type' => 'doc', 'attrs' => ['schema' => 3], 'content' => [$p3(['type' => 'text', 'text' => 'חדש', 'marks' => [['type' => 'ins', 'attrs' => ['author' => 'א']]]])]],
          'מעקב פועל' => ['type' => 'doc', 'attrs' => ['schema' => 3, 'track' => true], 'content' => [$p3(['type' => 'text', 'text' => 'רגיל'])]]] as $what => $j4) {
    $r4 = doc_find(doc_new('a:99', "גרסה 4 — $what", $j4, null, 'u1', 'דנה'), 'a:99');
    check("גרסת מבנה 4: כותב בגרסה 3 על מסמך עם $what → schema_newer; בגרסה 4 — נשמר",
        throws(fn() => doc_save($r4, ['type' => 'doc', 'attrs' => ['schema' => 3], 'content' => [$p3(['type' => 'text', 'text' => 'ישן'])]], null, 'u2', 'ישן'), 'schema_newer')
        && (int)doc_save($r4, array_replace_recursive($j4, ['attrs' => ['schema' => 4]]), null, 'u1', 'דנה')['version'] === (int)$r4['version'] + 1);
}

// ======================================================================
section('ייבוא ODT, HTML וטקסט (import_file — לפי התוכן)');
// tests/fixtures/libreoffice.odt — נוצר ב-LibreOffice (מ-docx שלנו): כותרות, עיצוב, קישור, הערה, רשימות, טבלה עם מיזוג,
// תמונה, הזחה, פסקה באנגלית, דף לרוחב עם שוליים וכותרת עליונה
$odDoc = doc_new("c:$A", 'ODT', null, null, 'u1', 'דנה');
$od = import_file(file_get_contents(__DIR__ . '/fixtures/libreoffice.odt'), 'מסמך.odt', $odDoc);
$oc = $od['json']['content'];
$types = array_column($oc, 'type');
$p1 = $oc[1]['content'] ?? [];
$mk = fn($t) => array_column(array_values(array_filter($p1, fn($n) => ($n['text'] ?? '') === $t))[0]['marks'] ?? [], 'type');
check('ODT: כותרות 1 ו-2, פסקאות, תבליטים, רשימה ממוספרת וטבלה — בסדר', array_slice($types, 0, 7) === ['heading', 'paragraph', 'paragraph', 'heading', 'bulletList', 'orderedList', 'table']
    && $oc[0]['attrs'] === ['level' => 1] && $oc[3]['attrs'] === ['level' => 2], json_encode($types));
check('ODT: מודגש, נטוי + צבע וגודל, קישור, הערת שוליים', $mk('מודגש') === ['bold'] && $mk('נטוי אדום') === ['italic', 'textStyle']
    && in_array('link', $mk(' וקישור'), true) && in_array(['type' => 'footnote', 'attrs' => ['text' => 'הערת שוליים כאן', 'content' => null]], $p1, true), json_encode($p1, JSON_UNESCAPED_UNICODE));
check('ODT: יישור למרכז ורווח אחרי; פסקה מימין לשמאל בלי יישור מפורש; הזחה ושורה ראשונה; אנגלית משמאל לימין',
    ($oc[2]['attrs'] ?? null) === ['textAlign' => 'center', 'spaceAfter' => 12.0] && !isset($oc[1]['attrs'])
    && ($oc[9]['attrs'] ?? null) == ['indent' => 1, 'firstLine' => 18] && ($oc[10]['attrs'] ?? null) === ['dir' => 'ltr'], json_encode(array_column($oc, 'attrs'), JSON_UNESCAPED_UNICODE));
$tb = $oc[6]['content'];
check('ODT: טבלה — שתי שורות, התא הממוזג (colspan 2)', count($tb) === 2 && count($tb[0]['content']) === 2 && ($tb[1]['content'][0]['attrs']['colspan'] ?? 0) === 2);
$oimg = array_values(array_filter($oc[8]['content'] ?? [], fn($n) => ($n['type'] ?? '') === 'image'))[0]['attrs'] ?? null;
check('ODT: התמונה נשמרה כתמונה של המסמך, בגודל שלה (80×40)', $oimg && media_of_doc($oimg['media'], $odDoc) !== null && $oimg['width'] === 80 && $oimg['height'] === 40, json_encode($oimg));
check('ODT: הגדרות הדף — לרוחב, שוליים (גם עם הכותרת), כותרת עליונה ומספור למטה במרכז; דוח ריק',
    ($od['page']['orientation'] ?? '') === 'landscape' && ($od['page']['margins'] ?? null) == ['top' => 20, 'bottom' => 20, 'right' => 30, 'left' => 15]
    && ($od['page']['header'] ?? '') === 'כותרת עליונה' && ($od['page']['numbering'] ?? null) === ['show' => true, 'position' => 'bottom', 'align' => 'center'] && $od['report'] === [],
    json_encode([$od['page'], $od['report']], JSON_UNESCAPED_UNICODE));
check('ODT: נכנס כמו שהוא לשמירה (doc_save) — בלי שגיאה', (bool)doc_save(doc_find($odDoc, "c:$A"), $od['json'], null, 'u1', 'דנה'));
// לפי התוכן: docx, HTML (גם מסמך שלם), טקסט (UTF-8 / Windows-1255), doc ישן — שגיאה מובנת
check('import_file: docx לפי התוכן (גם בלי סיומת)', import_file(export_docx(['type' => 'doc', 'content' => [para([text_node('מוורד')])]], page_normalize([])), '', $odDoc)['json']['content'][0]['content'][0]['text'] === 'מוורד');
$ih = import_file("<!DOCTYPE html><html><head><title>x</title></head><body><h1>כותרת</h1><p>פסקה <b>מודגשת</b></p></body></html>", 'a.html', $odDoc)['json']['content'];
check('import_file: מסמך HTML שלם — רק הגוף, כותרת ופסקה עם מודגש', ($ih[0]['type'] ?? '') === 'heading' && str_contains(json_encode($ih, JSON_UNESCAPED_UNICODE), '"bold"') && !str_contains(json_encode($ih, JSON_UNESCAPED_UNICODE), '"x"'), json_encode($ih, JSON_UNESCAPED_UNICODE));
$it = import_file("# פרק\nשורה ראשונה\n- תבליט", 'a.txt', $odDoc)['json']['content'];
$i1255 = import_file(iconv('UTF-8', 'WINDOWS-1255', "שלום עולם"), 'old.txt', $odDoc)['json']['content'][0]['content'][0]['text'] ?? '';
check('import_file: טקסט — שורות, # כותרת, - תבליט; וגם Windows-1255', array_column($it, 'type') === ['heading', 'paragraph', 'bulletList'] && $i1255 === 'שלום עולם', $i1255);
check('import_file: קובץ OLE פגום (‎.doc חתוך) — שגיאה עם הסבר, לא שגיאת שרת', throws(fn() => import_file("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 50), 'a.doc', $odDoc), 'bad_file'));

// ‎.doc (Word 97–2003, lib/import_doc.php) — tests/fixtures/libreoffice*.doc: נוצרו ב-LibreOffice מ-docx שלנו
$dc = import_file(file_get_contents(__DIR__ . '/fixtures/libreoffice.doc'), 'ישן.doc', $odDoc);
$dcB = $dc['json']['content'];
$dcT = fn($b) => implode('', array_map(fn($n) => $n['text'] ?? '', $b['content'] ?? []));
$dcM = fn($b, $t) => array_values(array_filter($b['content'] ?? [], fn($n) => ($n['text'] ?? '') === $t))[0]['marks'] ?? [];
check('‎.doc: כותרות 1 ו-2 (לפי הסגנון), והדוח ריק', ($dcB[0]['type'] ?? '') === 'heading' && $dcB[0]['attrs']['level'] === 1 && $dcT($dcB[0]) === 'כותרת ראשית'
    && ($dcB[3]['attrs']['level'] ?? 0) === 2 && $dc['report'] === [], json_encode($dc['report'], JSON_UNESCAPED_UNICODE));
check('‎.doc: מודגש, נטוי, קו תחתון, וצבע וגודל', $dcM($dcB[1], 'מודגש') == [['type' => 'bold']] && $dcM($dcB[1], 'נטוי') == [['type' => 'italic']]
    && $dcM($dcB[1], 'קו תחתון') == [['type' => 'underline']] && $dcM($dcB[1], 'אדום גדול') == [['type' => 'textStyle', 'attrs' => ['fontSize' => '16pt', 'color' => '#ff0000']]],
    json_encode($dcB[1], JSON_UNESCAPED_UNICODE));
check('‎.doc: יישור למרכז, ופסקה באנגלית — משמאל לימין', ($dcB[2]['attrs']['textAlign'] ?? '') === 'center'
    && (array_values(array_filter($dcB, fn($b) => str_starts_with($dcT($b), 'English')))[0]['attrs']['dir'] ?? '') === 'ltr');
check('‎.doc: רשימת תבליטים ורשימה ממוספרת — נפרדות', ($dcB[4]['type'] ?? '') === 'bulletList' && count($dcB[4]['content']) === 2 && ($dcB[5]['type'] ?? '') === 'orderedList'
    && str_contains(json_encode($dcB[5], JSON_UNESCAPED_UNICODE), 'צעד שניים'));
$dcTbl = array_values(array_filter($dcB, fn($b) => $b['type'] === 'table'))[0] ?? [];
check('‎.doc: טבלה 2×2, כל תא במקומו', count($dcTbl['content'] ?? []) === 2 && count($dcTbl['content'][0]['content']) === 2
    && $dcT($dcTbl['content'][1]['content'][1]['content'][0]) === 'תא ב2', json_encode($dcTbl, JSON_UNESCAPED_UNICODE));
$dcLink = array_values(array_filter($dcB, fn($b) => str_contains($dcT($b), 'קישור')))[0] ?? [];
check('‎.doc: קישור (שדה HYPERLINK) ושבירת שורה', ($dcM($dcLink, 'קישור')[0]['attrs']['href'] ?? '') === 'https://example.com/'
    && in_array('hardBreak', array_column($dcLink['content'] ?? [], 'type'), true));
$dcImg = array_values(array_filter($dcB, fn($b) => ($b['content'][0]['type'] ?? '') === 'image'))[0]['content'][0] ?? [];
check('‎.doc: תמונה (PNG מזרם Data) — באחסון שלנו ובגודל המקורי', media_of_doc($dcImg['attrs']['media'] ?? '', $odDoc) && ($dcImg['attrs']['width'] ?? 0) === 60 && ($dcImg['attrs']['height'] ?? 0) === 30);
$dc2 = import_file(file_get_contents(__DIR__ . '/fixtures/libreoffice-notes.doc'), 'x.bin', $odDoc);
$dc2B = $dc2['json']['content'];
check('‎.doc: הערת שוליים במקומה, עם הטקסט שלה', ($dc2B[0]['content'][1]['type'] ?? '') === 'footnote' && ($dc2B[0]['content'][1]['attrs']['text'] ?? '') === 'זו ההערה');
check('‎.doc: רשימה מקוננת (רמה 2 בתוך רמה 1)', ($dc2B[1]['content'][0]['content'][1]['type'] ?? '') === 'bulletList' && count($dc2B[1]['content']) === 2,
    json_encode($dc2B[1], JSON_UNESCAPED_UNICODE));
check('‎.doc: מעבר עמוד → breakBefore, ותמונת JPEG בתוך תא', ($dc2B[2]['attrs']['breakBefore'] ?? false) === true
    && ($dc2B[3]['content'][0]['content'][0]['content'][0]['content'][0]['type'] ?? '') === 'image' && $dc2['report'] === []);
// libreoffice-hf.doc: Letter, שוליים 20/22/30/15, כותרת עליונה עשירה (מודגש, לימין), תחתונה פשוטה + מספר עמוד במרכז, ועמוד ראשון שונה
$dc3 = import_file(file_get_contents(__DIR__ . '/fixtures/libreoffice-hf.doc'), 'מכתב.doc', $odDoc);
$dc3P = page_normalize($dc3['page'] ?? []);
check('‎.doc: גודל העמוד והשוליים מהמקטע (SEPX)', $dc3P['size'] === 'Letter' && $dc3P['margins'] == ['top' => 20, 'bottom' => 22, 'right' => 30, 'left' => 15], json_encode($dc3P['margins']));
check('‎.doc: כותרת עליונה עשירה (מודגש ביחד עם טקסט רגיל), ותחתונה כטקסט', ($dc3P['header'][0]['content'][0]['marks'][0]['type'] ?? '') === 'bold'
    && ($dc3P['header'][0]['content'][0]['text'] ?? '') === 'חברה בע"מ' && $dc3P['footer'] === 'רחוב הרצל 1, תל אביב', json_encode($dc3P['header'], JSON_UNESCAPED_UNICODE));
check('‎.doc: מספר העמוד (שדה PAGE) → מספור למטה במרכז, ולא כטקסט בכותרת', $dc3P['numbering']['show'] && $dc3P['numbering']['position'] === 'bottom'
    && $dc3P['numbering']['align'] === 'center' && !str_contains(json_encode($dc3P['footer'], JSON_UNESCAPED_UNICODE), 'PAGE'), json_encode($dc3P['numbering']));
check('‎.doc: עמוד ראשון שונה (titlePg) — header_first, והדוח ריק', $dc3P['first_diff'] && $dc3P['header_first'] === 'עמוד השער' && $dc3['report'] === [],
    json_encode([$dc3P['header_first'], $dc3['report']], JSON_UNESCAPED_UNICODE));
check('‎.doc בלי כותרות — אין עמוד ראשון שונה ואין מספור', !(page_normalize($dc['page'] ?? [])['first_diff']) && empty(($dc['page'] ?? [])['header'] ?? null));
$od3 = import_file(file_get_contents(__DIR__ . '/fixtures/libreoffice-hf.odt'), 'מכתב.odt', $odDoc);
$od3P = page_normalize($od3['page'] ?? []);
check('ODT: אותו מכתב — כותרת עליונה עשירה (מודגש), תחתונה כטקסט, מספור במרכז, ועמוד ראשון שונה (header-first)',
    ($od3P['header'][0]['content'][0]['marks'][0]['type'] ?? '') === 'bold' && $od3P['footer'] === 'רחוב הרצל 1, תל אביב' && $od3P['numbering']['align'] === 'center'
    && $od3P['first_diff'] && $od3P['header_first'] === 'עמוד השער' && $od3['report'] === [], json_encode([$od3P['header'], $od3P['header_first'], $od3['report']], JSON_UNESCAPED_UNICODE));
check('‎.doc → Word: נשמר כ-docx (אין כתיבה של ‎.doc) ונפתח בחזרה עם אותו טקסט', str_contains(export_text(import_docx(export_docx($dc['json'], page_normalize([]), 'x'), $odDoc)['json']), 'תא ב2'));
check('קובץ Office שאינו Word (OLE בלי WordDocument) — הודעה ברורה', throws(fn() => import_doc(substr(file_get_contents(__DIR__ . '/fixtures/libreoffice.doc'), 0, 1536), $odDoc), 'bad_file'));

section('ייבוא RTF (lib/import_rtf.php)');
// כמו ש-Word בעברית שומר: ansicpg1255, גופן עברי ב-\af, עברית ב-\'hh וב-\uN, סגנון "כותרת 1" (שם ב-\'hh), כותרת עם
// מספר עמוד, הערת שוליים, רשימות (\listtext), טבלה, קישור, צבע וסימון, מעקב שינויים, תמונה. § = לוכסן הפוך.
$R = fn(string $s) => str_replace('§', chr(92), $s);
$rpng = bin2hex(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$rtf = $R('{§rtf1§ansi§ansicpg1255§deff0§uc1{§fonttbl{§f0§froman§fcharset0 Times New Roman;}{§f1§fbidi§fswiss§fcharset177 Arial;}'
    . '{§f3§froman§fcharset2 Symbol;}}{§colortbl;§red255§green0§blue0;§red255§green255§blue0;}'
    . '{§stylesheet{§qr§rtlpar§sa160§fs22§lang1037 Normal;}{§s1§qr§rtlpar§sb240§sa120§b§fs32§ab§afs32 §\'eb§\'e5§\'fa§\'f8§\'fa 1;}{§*§cs10 Default Paragraph Font;}}'
    . '{§*§revtbl {Unknown;}{§\'e9§\'e5§\'f1§\'e9;}}'
    . '§paperw11906§paperh16838§margl1134§margr1701§margt1418§margb1418'
    . '{§header §pard§qc§rtlpar {§rtlch§af1 §\'e3§\'e5"§\'e7 §\'f9§\'f0§\'fa§\'e9}§par}'
    . '{§footer §pard§qc {§field{§*§fldinst { PAGE }}{§fldrslt {7}}}§par}'
    . '§pard§plain§s1§qr§rtlpar§sb240§sa120§b§fs32 {§rtlch§af1§ab§afs32 §\'f4§\'f8§\'f7 §\'f8§\'e0§\'f9§\'e5§\'ef}§par'
    . '§pard§plain§qr§rtlpar§sa160§fs22 {§rtlch§af1§afs22 §\'f9§\'ec§\'e5§\'ed }{§rtlch§af1§ab§afs22 §u1506?§u1493?§u1500?§u1501?}'
    . '{§rtlch§af1§afs22 §\'ee§\'f4§\'e5§\'e8}{§super§chftn}{§*§footnote §pard§plain§fs20 {§super§chftn} §\'e4§\'f2§\'f8§\'e4 §\'eb§\'e0§\'ef}'
    . '{§rtlch§af1§afs22 . }{§cf1 §\'e0§\'e3§\'e5§\'ed} {§highlight2 §\'ee§\'f1§\'e5§\'ee§\'ef} {§ltrch§fs28 Big}§par'
    . '§pard§ltrpar§qj§li720§fi-360§sa160§fs22 English {§i italic} {§field{§*§fldinst {HYPERLINK "https://example.com/"}}{§fldrslt {§ul§cf2 link}}}§line'
    . ' next {§uldb double} {§revised§revauth1§revdttm132825664 added}{§deleted§revauthdel1 gone}§par'
    . '{§listtext§pard§plain§f3§fs22 §\'b7§tab}§pard§ls1§ilvl0§fi-360§li720§rtlpar§fs22 {§rtlch§af1 §\'e0§\'e7§\'fa}§par'
    . '{§listtext§pard§plain§f0§fs22 1.§tab}§pard§ls2§ilvl1§fi-360§li1440§rtlpar§fs22 {§rtlch§af1 §\'f9§\'fa§\'e9§\'ed}§par'
    . '{§listtext§pard§plain§f0§fs22 2.§tab}§pard§ls2§ilvl1§fi-360§li1440§rtlpar§fs22 {§rtlch§af1 §\'f9§\'ec§\'e5§\'f9}§par'
    . '§trowd§rtlrow§cellx3000§cellx6000§pard§intbl§rtlpar§fs22 {§rtlch§af1 §\'e01}§cell {§rtlch§af1 §\'e02}§cell§row'
    . '§trowd§rtlrow§cellx3000§cellx6000§pard§intbl§rtlpar§fs22 {§rtlch§af1 §\'e11}§cell §pard§intbl {§b B2}§cell§row'
    . '§pard§rtlpar§fs22 {§*§shppict{§pict§pngblip§picw1§pich1§picwgoal1500§pichgoal750 ' . $rpng . '}}{§nonshppict{§pict§wmetafile8 0100}}'
    . ' {§pict§emfblip 0100}§page §pard§rtlpar§fs22 {§headerf ignored}{§field{§*§fldinst { TOC §§o "1-3" }}{§fldrslt toc}}§par}');
$rt = import_file($rtf, 'מסמך.rtf', $odDoc);
$rc = $rt['json']['content'];
$tx = fn(array $n) => implode('', array_map(fn($c) => $c['text'] ?? '', $n['content'] ?? []));
$mk = fn(array $p, string $t) => array_column(array_values(array_filter($p['content'], fn($c) => ($c['text'] ?? '') === $t))[0]['marks'] ?? [], 'type');
check('RTF: לפי התוכן (גם בלי סיומת .rtf) — הסוגים בסדר', array_column(import_file($rtf, 'x', $odDoc)['json']['content'], 'type')
    === ['heading', 'paragraph', 'paragraph', 'bulletList', 'table', 'paragraph', 'pageBreak', 'paragraph'], json_encode(array_column($rc, 'type')));
check('RTF: סגנון "כותרת 1" (שם בעברית) — כותרת רמה 1, בלי מודגש / גודל ישירים מהסגנון',
    $rc[0]['attrs'] === ['level' => 1] && $tx($rc[0]) === 'פרק ראשון' && !isset($rc[0]['content'][0]['marks']), json_encode($rc[0], JSON_UNESCAPED_UNICODE));
$p1 = $rc[1];
check('RTF: עברית ב-\\\'hh (ansicpg1255 + \\af) וב-\\uN; מודגש מ-\\ab; צבע; סימון; גודל של טקסט אנגלי (\\fs)',
    str_starts_with($tx($p1), 'שלום עולםמפוט') && $mk($p1, 'עולם') === ['bold'] && $mk($p1, 'אדום') === ['textStyle']
    && $mk($p1, 'מסומן') === ['highlight'] && $mk($p1, 'Big') === ['textStyle'], json_encode($p1, JSON_UNESCAPED_UNICODE));
check('RTF: הערת שוליים — במקומה, כטקסט; גודל Normal (\\fs22) — גודל המסמך, לא עיצוב ישיר',
    in_array(['type' => 'footnote', 'attrs' => ['text' => 'הערה כאן', 'content' => null]], $p1['content'], true) && ($rt['page']['font']['size'] ?? 0) == 11
    && !isset($p1['attrs']), json_encode([$p1['attrs'] ?? null, $rt['page']['font'] ?? null], JSON_UNESCAPED_UNICODE));
$p2 = $rc[2];
$link = array_values(array_filter($p2['content'], fn($c) => ($c['text'] ?? '') === 'link'))[0]['marks'] ?? [];
$ins = array_values(array_filter($p2['content'], fn($c) => ($c['text'] ?? '') === 'added'))[0]['marks'][0] ?? null;
check('RTF: שמאל לימין, מיושר לשני הצדדים, הזחה ושורה ראשונה תלויה; נטוי; קישור; מעבר שורה; קו כפול',
    $p2['attrs'] == ['textAlign' => 'justify', 'dir' => 'ltr', 'indent' => 1, 'firstLine' => -18] && $mk($p2, 'italic') === ['italic']
    && in_array(['type' => 'link', 'attrs' => ['href' => 'https://example.com/']], $link, true) && in_array('hardBreak', array_column($p2['content'], 'type'), true)
    && in_array(['type' => 'underline', 'attrs' => ['ul' => 'double']], array_values(array_filter($p2['content'], fn($c) => ($c['text'] ?? '') === 'double'))[0]['marks'] ?? [], true),
    json_encode($p2, JSON_UNESCAPED_UNICODE));
check('RTF: מעקב שינויים — הוספה עם השם מ-\\revtbl והתאריך (\\revdttm), מחיקה',
    $ins === ['type' => 'ins', 'attrs' => ['author' => 'יוסי', 'date' => '2026-10-24T09:00:00Z']] && $mk($p2, 'gone') === ['del'], json_encode($ins, JSON_UNESCAPED_UNICODE));
$bl = $rc[3];
check('RTF: רשימה — תבליט (Symbol \\\'b7), ובתוכה רשימה ממוספרת ברמה 2 (\\ilvl1)',
    $bl['type'] === 'bulletList' && $tx($bl['content'][0]['content'][0]) === 'אחת' && ($bl['content'][0]['content'][1]['type'] ?? '') === 'orderedList'
    && count($bl['content'][0]['content'][1]['content']) === 2 && !isset($bl['content'][0]['content'][0]['attrs']['indent']), json_encode($bl, JSON_UNESCAPED_UNICODE));
$tb = $rc[4]['content'];
check('RTF: טבלה 2×2 עם הטקסט ועיצוב בתא', count($tb) === 2 && count($tb[1]['content']) === 2 && $tx($tb[0]['content'][1]['content'][0]) === 'א2'
    && $mk($tb[1]['content'][1]['content'][0], 'B2') === ['bold'], json_encode($tb, JSON_UNESCAPED_UNICODE));
$rimg = array_values(array_filter($rc[5]['content'] ?? [], fn($n) => ($n['type'] ?? '') === 'image'));
check('RTF: תמונת PNG (\\shppict) — אחת, בגודל התצוגה (100×50), ושל המסמך; ה-WMF החלופי לא נכנס',
    count($rimg) === 1 && $rimg[0]['attrs']['width'] === 100 && $rimg[0]['attrs']['height'] === 50 && media_of_doc($rimg[0]['attrs']['media'], $odDoc) !== null, json_encode($rc[5], JSON_UNESCAPED_UNICODE));
$rp = $rt['page'];
check('RTF: הדף — A4, שוליים במ"מ, כותרת עליונה (הטקסט), מספור עמודים למטה (שדה PAGE — בלי המספר עצמו)',
    $rp['size'] === 'A4' && $rp['margins'] == ['left' => 20, 'right' => 30, 'top' => 25, 'bottom' => 25] && $rp['header'] === 'דו"ח שנתי'
    && $rp['numbering'] === ['show' => true, 'position' => 'bottom'] && !isset($rp['footer']), json_encode($rp, JSON_UNESCAPED_UNICODE));
check('RTF: הדוח — תמונה בפורמט אחר (EMF), כותרת לעמוד הראשון, שדה TOC (כטקסט)', array_column($rt['report'], 'key') === ['images', 'fields', 'hf_extra']
    && $tx($rc[7]) === 'toc', json_encode([$rt['report'], $rc[7]], JSON_UNESCAPED_UNICODE));
check('RTF: נכנס כמו שהוא לשמירה (doc_save) — בלי שגיאה', (bool)doc_save(doc_find($odDoc, "c:$A"), $rt['json'], null, 'u1', 'דנה'));
$re = import_file($R('{§rtf1§ansi{§stylesheet{§fs22 Normal;}{§s1§fs32{§*§keycode §shift}heading 1;}{§*§cs10 Default;}}§pard§s1 A§par§pard B §u-10179?§u-8704? §u x§par}'), 'a.rtf', $odDoc);
check('RTF: סגנון עם קבוצה בתוכו וסגנון תו (\\*\\cs) אחריו — לא נפגעים; אימוג\'י (זוג surrogate); \\u בלי מספר',
    $re['json']['content'][0]['attrs'] === ['dir' => 'ltr', 'level' => 1] && ($re['page']['font']['size'] ?? 0) == 11
    && $re['json']['content'][1]['content'][0]['text'] === 'B 😀 x', json_encode($re, JSON_UNESCAPED_UNICODE));
check('RTF: קובץ פגום / קטוע — לא נופל', is_array(import_file($R('{§rtf1§ansi {§b bold'), 'a.rtf', $odDoc)['json']) && is_array(import_file($R('{§rtf1 }}}§par{{'), 'a.rtf', $odDoc)['json']));
// קבצים אמיתיים מ-LibreOffice (נשמרו כ-RTF מה-docx של האוסף): כל המילים, הכותרות, הרשימות, הערות השוליים ומעקב השינויים
$count = function (array $n, array &$acc) use (&$count) {
    $t = $n['type'] ?? '';
    if ($t === 'heading') $acc['h' . $n['attrs']['level']] = ($acc['h' . $n['attrs']['level']] ?? 0) + 1;
    if (in_array($t, ['footnote', 'listItem', 'orderedList', 'bulletList', 'table'], true)) $acc[$t] = ($acc[$t] ?? 0) + 1;
    foreach ($n['marks'] ?? [] as $m) if (in_array($m['type'], ['ins', 'del'], true)) $acc[$m['type'] . ':' . $m['attrs']['author']] = ($acc[$m['type'] . ':' . $m['attrs']['author']] ?? 0) + 1;
    foreach ($n['content'] ?? [] as $c) $count($c, $acc);
};
$rw = fn(string $s) => preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s)), -1, PREG_SPLIT_NO_EMPTY);
$alltext = function (array $n) use (&$alltext): string { $o = $n['text'] ?? ($n['attrs']['text'] ?? ''); foreach ($n['content'] ?? [] as $c) $o .= ' ' . $alltext($c); return $o; };
foreach (['numbering', 'track'] as $f) {
    $src = json_decode(file_get_contents(__DIR__ . "/corpus/$f.json"), true)['json'];
    $got = import_file(file_get_contents(__DIR__ . "/fixtures/libreoffice-$f.rtf"), "$f.rtf", $odDoc)['json'];
    $a = []; $b = [];
    $count($src, $a);
    $count($got, $b);
    ksort($a);
    ksort($b);
    $wa = $rw($alltext($src));
    $wb = $rw($alltext($got));
    check("RTF מ-LibreOffice ($f): כל המילים, וכותרות / רשימות / הערות / שינויים — כמו במקור", $wa === $wb && $a === $b,
        json_encode([count($wa), count($wb), array_slice(array_diff_assoc($wa, $wb), 0, 5), $a, $b], JSON_UNESCAPED_UNICODE));
}

section('ייצוא ל-ODT (lib/export_odt.php)');
// מסמך עם כל מה שהייצוא יודע: כותרת, עיצוב תווים, קישור, הערות שוליים וסיום, רשימה מקוננת (מ-3), טבלה עם מיזוג ושורת
// כותרת, תמונה, מעבר עמוד, רווחים כפולים וטאב, סימניה, הערה, מעקב שינויים, תוכן עניינים, מקטע לרוחב, כותרת עליונה ומספור
$oDoc = doc_new("c:$A", 'ODT', null, null, 'u1', 'דנה');
$om = media_store($oDoc, $png);
$T = fn(string $t, array $m = []) => ['type' => 'text', 'text' => $t] + ($m ? ['marks' => $m] : []);
$Pp = fn(array $c, array $a = []) => ['type' => 'paragraph', 'attrs' => $a, 'content' => $c];
$LI = fn(string $t, array $sub = []) => ['type' => 'listItem', 'content' => array_merge([$Pp([$T($t)])], $sub)];
$Cell = fn(string $t, string $type = 'tableCell', array $a = []) => ['type' => $type, 'attrs' => $a, 'content' => [$Pp([$T($t)])]];
$oj = ['type' => 'doc', 'attrs' => ['comments' => ['k1' => ['author' => 'דנה לוי', 'date' => '2026-10-22T10:00:00Z', 'text' => 'לבדוק', 'replies' => [['author' => 'יוסי', 'text' => 'בסדר']]]]], 'content' => [
    ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [$T('פרק ראשון')]],
    ['type' => 'toc', 'attrs' => ['levels' => 2, 'entries' => [['l' => 2, 't' => 'פרק ראשון', 'p' => '1']]]],
    $Pp([$T('מודגש', [['type' => 'bold']]), $T('  רווחים'), $T("\tטאב"), $T(' גלי', [['type' => 'underline', 'attrs' => ['ul' => 'wavy']]]),
        $T(' אדום', [['type' => 'textStyle', 'attrs' => ['color' => '#c00000', 'fontSize' => '14pt']]]), $T(' קישור', [['type' => 'link', 'attrs' => ['href' => 'https://example.com/']]]),
        ['type' => 'footnote', 'attrs' => ['text' => 'הערת שוליים']], ['type' => 'hardBreak'], $T('שורה שנייה', [['type' => 'comment', 'attrs' => ['id' => 'k1']]]),
        ['type' => 'bookmark', 'attrs' => ['name' => 'סימניה']], ['type' => 'footnote', 'attrs' => ['text' => 'הערת סיום', 'end' => true]],
        $T(' נוסף', [['type' => 'ins', 'attrs' => ['author' => 'יוסי', 'date' => '2026-10-22T10:00:00Z']]]), $T(' נמחק', [['type' => 'del', 'attrs' => ['author' => 'רונית', 'date' => '2026-10-22T11:00:00Z']]])],
        ['textAlign' => 'center', 'spaceAfter' => 12]),
    ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [$LI('שלוש', [['type' => 'bulletList', 'content' => [$LI('תבליט')]]]), $LI('ארבע')]],
    ['type' => 'pageBreak'],
    ['type' => 'table', 'content' => [
        ['type' => 'tableRow', 'content' => [$Cell('א', 'tableHeader'), $Cell('ב', 'tableHeader'), $Cell('ג', 'tableHeader')]],
        ['type' => 'tableRow', 'content' => [$Cell('ממוזג', 'tableCell', ['colspan' => 2]), $Cell('גבוה', 'tableCell', ['rowspan' => 2])]],
        ['type' => 'tableRow', 'content' => [$Cell('1'), $Cell('2')]],
    ]],
    $Pp([['type' => 'image', 'attrs' => ['media' => $om['id'], 'src' => $om['url'], 'width' => 80, 'height' => 40]]]),
    ['type' => 'sectionBreak', 'attrs' => ['page' => ['orientation' => 'landscape']]],
    $Pp([$T('English paragraph')], ['dir' => 'ltr', 'indent' => 1]),
]];
$opage = page_normalize(['header' => 'כותרת עליונה', 'numbering' => ['show' => true, 'position' => 'bottom', 'format' => 'n_of_m'], 'margins' => ['top' => 30, 'bottom' => 20, 'right' => 25, 'left' => 20]]);
$odt = export_odt($oj, $opage, 'ייצוא');
file_put_contents("$tmp/x.odt", $odt);
$oz = new ZipArchive();
$oz->open("$tmp/x.odt");
$okXml = true;
foreach (['content.xml', 'styles.xml', 'meta.xml', 'META-INF/manifest.xml'] as $x) { $dx = new DOMDocument(); $okXml = $okXml && @$dx->loadXML((string)$oz->getFromName($x)); }
$ost = $oz->statIndex(0);
$ocontent = (string)$oz->getFromName('content.xml');
$ostyles = (string)$oz->getFromName('styles.xml');
check('ODT: mimetype ראשון ובלי דחיסה, XML תקין, התמונה בפנים (Pictures/)', $ost['name'] === 'mimetype' && $ost['comp_method'] === 0 && $okXml
    && $oz->locateName('Pictures/' . $om['id'] . '.png') !== false);
check('ODT: הערה עם תשובה (office:annotation + loext:parent-name), תוכן עניינים שאפשר לעדכן, מעקב שינויים, הערת סיום',
    substr_count($ocontent, '<office:annotation ') === 2 && str_contains($ocontent, 'loext:parent-name="cm0"') && str_contains($ocontent, '<text:table-of-content ')
    && str_contains($ocontent, '<text:tracked-changes') && str_contains($ocontent, 'text:note-class="endnote"'));
check('ODT: עמוד-אב למקטע לרוחב; כותרת עליונה ומספור "N מתוך M" למטה; שוליים: הכותרות בתוכם (חצי מהקצה)',
    str_contains($ostyles, 'style:print-orientation="landscape"') && str_contains($ocontent, 'style:master-page-name="MP1"') && str_contains($ostyles, '<text:page-count>')
    && str_contains($ostyles, 'כותרת עליונה') && str_contains($ostyles, 'fo:margin-top="12.5mm" fo:margin-bottom="10mm"'), substr($ostyles, strpos($ostyles, '<style:page-layout '), 400));
$ob = import_file($odt, 'x.odt', $oDoc);
$oc = $ob['json']['content'];
$ofind = function (array $nodes, string $type) use (&$ofind): array { $o = []; foreach ($nodes as $n) { if (($n['type'] ?? '') === $type) $o[] = $n; array_push($o, ...$ofind($n['content'] ?? [], $type)); } return $o; };
$op = array_values(array_filter($ofind($oc, 'paragraph'), fn($p) => str_starts_with($p['content'][0]['text'] ?? '', 'מודגש')))[0] ?? [];
$otext = implode('', array_map(fn($n) => $n['text'] ?? '', $op['content'] ?? []));
$omk = fn(string $t) => array_column(array_values(array_filter($op['content'] ?? [], fn($n) => ($n['text'] ?? '') === $t))[0]['marks'] ?? [], 'type');
check('ODT הלוך וחזור: כותרת 2, פסקה ממורכזת עם רווח אחרי, רווחים כפולים וטאב, מודגש / קו גלי / צבע וגודל / קישור, שבירת שורה',
    ($oc[0]['type'] ?? '') === 'heading' && ($oc[0]['attrs']['level'] ?? 0) === 2 && ($op['attrs']['textAlign'] ?? '') === 'center' && ($op['attrs']['spaceAfter'] ?? 0) == 12
    && str_contains($otext, "מודגש  רווחים\tטאב") && $omk('מודגש') === ['bold'] && $omk(' גלי') === ['underline'] && $omk(' אדום') === ['textStyle'] && in_array('link', $omk(' קישור'), true)
    && in_array('hardBreak', array_column($op['content'], 'type'), true), json_encode($op, JSON_UNESCAPED_UNICODE));
$ofn = $ofind($oc, 'footnote');
check('ODT הלוך וחזור: הערת שוליים והערת סיום (עם הטקסט), סימניה, הוספה ומחיקה במעקב (עם השמות)',
    count($ofn) === 2 && $ofn[0]['attrs']['text'] === 'הערת שוליים' && !empty($ofn[1]['attrs']['end']) && count($ofind($oc, 'bookmark')) === 1
    && $omk(' נוסף') === ['ins'] && $omk(' נמחק') === ['del'] && array_values(array_filter($op['content'], fn($n) => ($n['text'] ?? '') === ' נמחק'))[0]['marks'][0]['attrs']['author'] === 'רונית',
    json_encode($op['content'], JSON_UNESCAPED_UNICODE));
$ol = $ofind($oc, 'orderedList')[0] ?? null;
check('ODT הלוך וחזור: רשימה ממוספרת מ-3 ובתוכה תבליט', $ol && ($ol['attrs']['start'] ?? 0) === 3 && count($ol['content']) === 2 && ($ol['content'][0]['content'][1]['type'] ?? '') === 'bulletList', json_encode($ol, JSON_UNESCAPED_UNICODE));
$otb = $ofind($oc, 'table')[0]['content'] ?? [];
check('ODT הלוך וחזור: מעבר עמוד, טבלה 3 שורות עם מיזוג (colspan 2, rowspan 2) ושורת כותרת, תמונה 80×40, פסקה באנגלית מוזחת',
    count($ofind($oc, 'pageBreak')) === 1 && count($otb) === 3 && ($otb[1]['content'][0]['attrs']['colspan'] ?? 0) === 2 && ($otb[1]['content'][1]['attrs']['rowspan'] ?? 0) === 2
    && ($otb[0]['content'][0]['type'] ?? '') === 'tableHeader' && (($ofind($oc, 'image')[0]['attrs']['width'] ?? 0) === 80) && ((end($oc)['attrs'] ?? []) == ['dir' => 'ltr', 'indent' => 1]),
    json_encode([count($otb), $otb[1] ?? null, end($oc)], JSON_UNESCAPED_UNICODE));
check('ODT הלוך וחזור: הגדרות הדף — שוליים, כותרת עליונה, מספור למטה', ($ob['page']['margins'] ?? null) == ['top' => 30, 'bottom' => 20, 'right' => 25, 'left' => 20]
    && ($ob['page']['header'] ?? '') === 'כותרת עליונה' && ($ob['page']['numbering']['position'] ?? '') === 'bottom', json_encode($ob['page'], JSON_UNESCAPED_UNICODE));

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
    // ODT הלוך וחזור: אותן מילים (גם בהערות), כותרות, רשימות, טבלאות, הערות שוליים, שינויים במעקב והגדרות הדף
    $oback = import_file(export_odt($c['json'], $page, $c['title']), 'c.odt', $oDoc);
    $shape = function (array $json) {
        $acc = [];
        $walk = function ($n) use (&$walk, &$acc) {
            $t = $n['type'] ?? '';
            $k = $t === 'heading' ? 'h' . ($n['attrs']['level'] ?? 1) : (in_array($t, ['listItem', 'table', 'tableRow', 'footnote', 'image'], true) ? $t : null);
            if ($k) $acc[$k] = ($acc[$k] ?? 0) + 1;
            foreach ($n['marks'] ?? [] as $m) if (in_array($m['type'] ?? '', ['ins', 'del'], true)) $acc[$m['type']] = ($acc[$m['type']] ?? 0) + 1;
            foreach ($n['content'] ?? [] as $ch) if (is_array($ch)) $walk($ch);
        };
        $walk($json);
        ksort($acc);
        return $acc;
    };
    // הטקסט (כמו בבדיקת Word — כיתובים, הפניות ותוכן עניינים כפי שהם מוצגים) ועוד הטקסט של הערות השוליים
    $fnt = function (array $n) use (&$fnt): string { $o = ($n['type'] ?? '') === 'footnote' ? ($n['attrs']['text'] ?? '') : ''; foreach ($n['content'] ?? [] as $ch) if (is_array($ch)) $o .= ' ' . $fnt($ch); return $o; };
    $all = fn(array $json) => export_text($json) . ' ' . $fnt($json);
    $op2 = page_normalize($oback['page'] ?? []);
    $wa = $words($all($c['json']));
    $wb = $words($all($oback['json']));
    check("{$c['title']}: ODT חוזר עם אותן מילים, אותו מבנה ואותן הגדרות עמוד", $wa === $wb && $shape($c['json']) === $shape($oback['json'])
        && $op2['size'] === $page['size'] && $op2['orientation'] === $page['orientation'] && $op2['margins'] == $page['margins'],
        json_encode([count($wa), count($wb), $shape($c['json']), $shape($oback['json']), $op2['margins']], JSON_UNESCAPED_UNICODE));
}

section('‎.mbe — הורדה (חבילת היצירה, mbe-core)');
require_once __DIR__ . '/../lib/mbe_export.php';
$mbDocId = doc_new('a:99', 'בסיס mbe', null, null, 'u1', 'דנה');
$mbImg = media_store($mbDocId, $png)['id'];
$mbHdrImg = media_store($mbDocId, $pngOf([0, 128, 0]))['id'];
$mbJson = ['type' => 'doc', 'content' => [
    ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'כותרת המסמך']]],
    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'שלום עולם — ו-English באמצע.'], ['type' => 'image', 'attrs' => ['media' => $mbImg, 'width' => 40, 'height' => 40]]]],
]];
$mbPage = page_normalize(['header' => [['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['media' => $mbHdrImg, 'width' => 20, 'height' => 20]], ['type' => 'text', 'text' => ' לוגו']]]],
    'margins' => ['top' => 20, 'bottom' => 25, 'right' => 30, 'left' => 15], 'numbering' => ['format' => 'hebrew', 'start' => 3]]);
$mbRow = doc_find($mbDocId, 'a:99');
$mbRow = doc_save(doc_set_page($mbRow, $mbPage, 'u1', 'דנה'), $mbJson, 'מסמך לחבילה', 'u1', 'דנה');
$mbBytes = export_mbe($mbRow);
$mbV = Mbe::validate($mbBytes);
$mbErr = array_values(array_filter($mbV, fn($i) => $i['level'] === 'error'));
check('‎.mbe: עובר את הבודק של הליבה (Mbe::validate) בלי שגיאות, והחתימה בהיסט 30', !$mbErr && substr($mbBytes, 30, 31) === 'mimetypeapplication/vnd.mbe+zip', json_encode($mbV, JSON_UNESCAPED_UNICODE));
$mbF = Mbe::open($mbBytes);
$mbDoc = $mbF->document;
$mbFlow = $mbDoc['flows']['f1']['doc'] ?? [];
$mbSrcs = [];
array_walk_recursive($mbFlow, function ($v, $k) use (&$mbSrcs) { if ($k === 'src' || $k === 'media') $mbSrcs[] = "$k=$v"; });
check('‎.mbe: kind text — משטח עמוד בגודל A4 (pt), מסגרת בשוליים שמפנה לשטף f1, layout מהשוליים והמספור', $mbF->kind() === 'text'
    && $mbDoc['surfaces'][0]['size'] == [round(210 * PT_PER_MM, 2), round(297 * PT_PER_MM, 2)] && $mbDoc['surfaces'][0]['layers'][0]['flow'] === 'f1'
    && $mbDoc['surfaces'][0]['layers'][0]['box'][0] == round(15 * PT_PER_MM, 2) && $mbDoc['layout']['margins']['inside'] == round(30 * PT_PER_MM, 2)
    && $mbDoc['layout']['numbering'] == ['start' => 3, 'style' => 'hebrew'], json_encode([$mbDoc['surfaces'], $mbDoc['layout']], JSON_UNESCAPED_UNICODE));
check('‎.mbe: השטף — ה-JSON של המסמך כמו שהוא, עם הגדרות העמוד (attrs.page)', ($mbFlow['content'][0]['content'][0]['text'] ?? '') === 'כותרת המסמך'
    && ($mbFlow['content'][1]['content'][0]['text'] ?? '') === 'שלום עולם — ו-English באמצע.' && ($mbFlow['attrs']['page']['numbering']['format'] ?? '') === 'hebrew');
check('‎.mbe: כל התמונה — גם בכותרת העליונה — media/<sha256>.png שקיים בקובץ, בלי מזהה השרת', count($mbSrcs) === 2
    && !array_filter($mbSrcs, fn($s) => !preg_match('~^src=media/[0-9a-f]{64}\.png$~', $s) || $mbF->getMedia(substr($s, 4)) === null), json_encode($mbSrcs));
$mbR = [];
foreach ($mbF->renditions() as $r) $mbR[$r['path']] = $r;
$mbPrev = @imagecreatefromstring($mbR['renditions/preview.png']['data'] ?? '');
check('‎.mbe: ייצוגים — preview.png (העמוד הראשון, עד 1024px), document.pdf, document.docx; עדכניים, מאת word-processor', $mbPrev && max(imagesx($mbPrev), imagesy($mbPrev)) <= 1024
    && str_starts_with($mbR['renditions/document.pdf']['data'] ?? '', '%PDF') && str_starts_with($mbR['renditions/document.docx']['data'] ?? '', 'PK')
    && !array_filter($mbR, fn($r) => $r['stale'] || ($r['by'] ?? '') !== 'word-processor'), json_encode(array_map(fn($r) => [$r['stale'], $r['by'] ?? null], $mbR)));
// התמונה הממוזערת מציירת את העמוד: הכותרת (טקסט כהה) למעלה, הסמליל הירוק בכותרת העליונה
$mbDark = 0; $mbGreen = 0;
for ($y = 0; $y < imagesy($mbPrev) / 3; $y++) for ($x = 0; $x < imagesx($mbPrev); $x++) {
    $c = imagecolorat($mbPrev, $x, $y); [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    if ($r + $g + $b < 300) $mbDark++;
    if ($g > 100 && $r < 60 && $b < 60) $mbGreen++;
}
check('‎.mbe: preview.png — העמוד עצמו: טקסט בשליש העליון, והתמונה מהכותרת העליונה', $mbDark > 30 && $mbGreen > 4, "dark $mbDark, green $mbGreen");
check('‎.mbe: apps/word-processor/meta.json — שם המסמך', (json_decode($mbF->apps('word-processor')['meta.json'] ?? '', true)['title'] ?? '') === 'מסמך לחבילה');
section('‎.mbe — פתיחה ושמירה בית-לבית');
// הלוך וחזור: הקובץ שלנו נפתח כמסמך זהה — אותו תוכן, אותן הגדרות עמוד, והתמונות (גם בכותרת) באחסון שלנו
$mbInId = doc_new('a:99', 'מ-mbe', null, null, 'u1', 'דנה');
$mbIn = import_file($mbBytes, 'x.bin', $mbInId);
// מזהי הפסקה — הליבה משלימה בשמירה (פורמט 1.1); נבדקים בנפרד, למטה
$mbNorm = function ($v) use (&$mbNorm) { if (!is_array($v)) return $v; unset($v['media'], $v['src']); if (is_array($v['attrs'] ?? null)) { unset($v['attrs']['id']); if (!$v['attrs']) unset($v['attrs']); } return array_map($mbNorm, $v); };
$mbIds = function ($v) use (&$mbIds): array { if (!is_array($v)) return []; $o = in_array($v['type'] ?? '', ['paragraph', 'heading'], true) && isset($v['attrs']['id']) ? [$v['attrs']['id']] : [];
    foreach ($v as $c) if (is_array($c)) array_push($o, ...$mbIds($c)); return $o; };
$mbPics = [];
array_walk_recursive($mbIn, function ($v, $k) use (&$mbPics, $mbInId) { if ($k === 'media') $mbPics[] = media_of_doc($v, $mbInId) ? 'ok' : 'bad'; });
check('‎.mbe → מסמך: מזוהה לפי החתימה (גם בלי סיומת), אותו תוכן ואותן הגדרות עמוד, בלי דוח', $mbNorm($mbIn['json']) == $mbNorm(doc_migrate($mbJson))
    && $mbIn['page']['margins'] == $mbPage['margins'] && $mbIn['page']['numbering']['format'] === 'hebrew' && $mbIn['report'] === [],
    json_encode([$mbIn['json'], $mbIn['report']], JSON_UNESCAPED_UNICODE));
check('‎.mbe → מסמך: מזהי הפסקה של הקובץ נשמרים כמו שהם (אותם מזהים, באותו סדר)', $mbIds($mbIn['json']) === $mbIds(Mbe::open($mbBytes)->document['flows']['f1']['doc'] ?? []),
    json_encode([$mbIds($mbIn['json']), $mbIds(Mbe::open($mbBytes)->document['flows']['f1']['doc'] ?? [])]));
check('‎.mbe → מסמך: התמונות — בשטף ובכותרת העליונה — באחסון של המסמך החדש', $mbPics === ['ok', 'ok'], json_encode($mbPics));
// קובץ של מישהו אחר (הדוגמה של הליבה — text.mbe): שדות, חלקים ו-apps/ שלא שלנו נשמרים בית-לבית; רק השטף מתחלף
$mbFix = __DIR__ . '/../../creative-suite/mbe-core/tests/fixtures/valid/';
$mbOrig = Mbe::open(file_get_contents($mbFix . 'text.mbe'));
$mbTId = doc_new('a:99', 'זר', null, null, 'u1', 'דנה');
$mbT = import_file(file_get_contents($mbFix . 'text.mbe'), 'text.mbe', $mbTId);
$mbTJson = $mbT['json'];
$mbTJson['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'נוסף בעורך']]];
$mbTRow = doc_save(doc_find($mbTId, 'a:99'), $mbTJson, null, 'u1', 'דנה');
$mbBack = Mbe::open(export_mbe($mbTRow));
$mbOD = $mbOrig->document; $mbBD = $mbBack->document;
unset($mbOD['flows']['f1']['doc'], $mbBD['flows']['f1']['doc']);
check('‎.mbe זר (text.mbe): בלי דוח — משטח אחד, מסגרת אחת, שטף אחד', $mbT['report'] === [] && str_contains(json_encode($mbT['json'], JSON_UNESCAPED_UNICODE), 'שלום עולם'));
check('‎.mbe זר, הורדה אחרי עריכה: כל המסמך מלבד השטף — זהה בבתים (גם x-future, {} ו-1e+21)', MbeCanonical::encode($mbOD) === MbeCanonical::encode($mbBD),
    MbeCanonical::encode($mbBD));
check('‎.mbe זר: החלקים שלא שלנו — apps/word-processor/view.json הישן נשאר לצד meta.json, extensions/future.bin — בית-לבית', ($mbBack->files['extensions/future.bin'] ?? null) === $mbOrig->files['extensions/future.bin']
    && ($mbBack->apps('word-processor')['view.json'] ?? null) === $mbOrig->apps('word-processor')['view.json'] && isset($mbBack->apps('word-processor')['meta.json']));
check('‎.mbe זר: בשטף — מה שנוסף בעורך; הייצוגים עדכניים', str_contains(json_encode($mbBack->document['flows']['f1']['doc'], JSON_UNESCAPED_UNICODE), 'נוסף בעורך')
    && !array_filter($mbBack->renditions(), fn($r) => $r['stale']) && !array_filter(Mbe::validate(export_mbe($mbTRow)), fn($i) => $i['level'] === 'error'));
// עימוד (layout.mbe): שני עמודים, רקע רסטר, קבוצה וקטורית — מוצג כתמונה בראש המסמך, ונשמר כמו שהוא
$mbLId = doc_new('a:99', 'עימוד', null, null, 'u1', 'דנה');
$mbL = import_file(file_get_contents($mbFix . 'layout.mbe'), 'layout.mbe', $mbLId);
$mbLFirst = $mbL['json']['content'][0]['content'][0] ?? [];
check('‎.mbe מהעימוד: הטקסט של השטף, ובראש — התמונה הממוזערת של הקובץ, עם דוח "תוכן מאפליקציה אחרת"', ($mbLFirst['type'] ?? '') === 'image'
    && media_of_doc($mbLFirst['attrs']['media'] ?? '', $mbLId) && str_contains(json_encode($mbL['json'], JSON_UNESCAPED_UNICODE), 'שטף אחד שעובר')
    && ($mbL['report'][0]['key'] ?? '') === 'mbe_foreign', json_encode($mbL['report'], JSON_UNESCAPED_UNICODE));
$mbLRow = doc_save(doc_find($mbLId, 'a:99'), $mbL['json'], null, 'u1', 'דנה');
$mbLB = Mbe::open(export_mbe($mbLRow));
$mbLO = Mbe::open(file_get_contents($mbFix . 'layout.mbe'));
$mbLFlow = json_encode($mbLB->document['flows']['story']['doc'] ?? null, JSON_UNESCAPED_UNICODE);
check('‎.mbe מהעימוד, הורדה: המשטחים, השכבות והרקע — זהים בבתים; השטף story בלי התמונה שהוספנו', MbeCanonical::encode($mbLB->document['surfaces']) === MbeCanonical::encode($mbLO->document['surfaces'])
    && ($mbLB->files[$mbLO->document['surfaces'][0]['layers'][0]['src']] ?? null) === $mbLO->files[$mbLO->document['surfaces'][0]['layers'][0]['src']]
    && str_contains($mbLFlow, 'שטף אחד שעובר') && !str_contains($mbLFlow, '"image"') && isset($mbLB->apps('layout-studio')['view.json']), $mbLFlow);
$mbSt = $mbDoc['styles'] ?? [];
check('‎.mbe: סגנונות פסקה במבנה המשותף — body מגופן העמוד ומ-normal, h1–h3 מהכותרות (pt, weight, leading)', array_keys($mbSt) === ['body', 'h1', 'h2', 'h3']
    && $mbSt['body'] == ['name' => 'גוף', 'font' => 'David', 'size' => 12, 'leading' => 13.8, 'align' => 'right', 'weight' => 400, 'spaceBefore' => 0, 'spaceAfter' => 6, 'indent' => 0]
    && $mbSt['h1']['size'] == 16 && $mbSt['h1']['weight'] === 700 && $mbSt['h1']['spaceBefore'] == 12 && $mbSt['h2']['size'] == 14 && !isset($mbSt['normal']),
    json_encode($mbSt, JSON_UNESCAPED_UNICODE));
$mbSP = mbe_page_of(['size' => [595.3, 841.9]], [], ['body' => ['font' => 'Noto Serif Hebrew', 'size' => 11, 'leading' => 15, 'align' => 'justify', 'weight' => 400, 'spaceAfter' => 4],
    'h1' => ['size' => 20, 'weight' => 700, 'spaceBefore' => 18], 'h2' => ['size' => 'x', 'align' => 'nope']]);
check('‎.mbe מאפליקציה אחרת (בלי attrs.page): הסגנונות המשותפים → גופן העמוד, normal ו-h1; ערך לא תקין — ברירת המחדל', $mbSP['font'] == ['family' => 'Noto Serif Hebrew', 'size' => 11.0]
    && $mbSP['styles']['normal']['line'] == 1.36 && $mbSP['styles']['normal']['align'] === 'justify' && $mbSP['styles']['normal']['after'] == 4
    && $mbSP['styles']['h1']['size'] == 20 && $mbSP['styles']['h1']['before'] == 18 && $mbSP['styles']['h1']['color'] === '#1f3864'
    && $mbSP['styles']['h2'] == page_defaults()['styles']['h2'], json_encode([$mbSP['font'], $mbSP['styles']['normal'], $mbSP['styles']['h1'], $mbSP['styles']['h2']], JSON_UNESCAPED_UNICODE));
// layout בשורש גובר על attrs.page (העימוד שינה שוליים ומספור — מגיע לעורך); מה שאין במעטפת — מ-attrs.page
$mbLy = Mbe::open($mbBytes);
$mbLy->document['layout']['margins']['top'] = round(40 * PT_PER_MM, 2);
$mbLy->document['layout']['numbering'] = ['start' => 7, 'style' => 'arabic'];
$mbLyId = doc_new('a:99', 'layout גובר', null, null, 'u1', 'דנה');
$mbLyP = import_mbe(Mbe::save($mbLy, ['app' => 'layout-studio', 'appVersion' => '1']), $mbLyId)['page'];
check('‎.mbe: layout בשורש גובר על attrs.page — שוליים ומספור מהמעטפת, הכותרת העליונה מ-attrs.page', $mbLyP['margins'] == ['top' => 40.0, 'bottom' => 25.0, 'right' => 30.0, 'left' => 15.0]
    && $mbLyP['numbering']['start'] === 7 && $mbLyP['numbering']['format'] === 'n' && str_contains(json_encode($mbLyP['header'], JSON_UNESCAPED_UNICODE), 'לוגו'),
    json_encode([$mbLyP['margins'], $mbLyP['numbering']], JSON_UNESCAPED_UNICODE));
check('‎.mbe: קובץ פגום — הודעה ברורה', throws(fn() => import_mbe(substr($mbBytes, 0, 200), $mbLId), 'bad_file'));

section('מסמך חדש שלא נכתב בו — לא נשאר (my_discard)');
$enA = doc_new('a:99', 'מסמך חדש', null, null, 'u1', 'דנה');
check('מסמך חדש, בלי שמירה — "לא נכתב בו"', doc_untouched(doc_find($enA, 'a:99')));
$enB = doc_new('a:99', 'מסמך חדש', null, null, 'u1', 'דנה');
doc_save(doc_find($enB, 'a:99'), ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'א']]]]], null, 'u1', 'דנה');
$enC = doc_new('a:99', 'מסמך חדש', null, null, 'u1', 'דנה');
doc_rename(doc_find($enC, 'a:99'), 'שם שבחרתי');
$enD = doc_new('a:99', 'מסמך חדש', null, null, 'u1', 'דנה');
$enIm = imagecreatetruecolor(2, 2);
ob_start(); imagepng($enIm); media_store($enD, (string)ob_get_clean());
check('…אבל לא: אחרי שמירה, אחרי שינוי שם, או עם תמונה', !doc_untouched(doc_find($enB, 'a:99')) && !doc_untouched(doc_find($enC, 'a:99')) && !doc_untouched(doc_find($enD, 'a:99')));

section('F07 — הגדרות עמוד (my_page / embed_page) בהיסטוריית הגרסאות');
$f7Id = doc_new('a:99', 'עמוד', null, null, 'u1', 'דנה');
$f7 = doc_save(doc_find($f7Id, 'a:99'), ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'תוכן']]]]], null, 'u1', 'דנה');
$f7v = (int)$f7['version'];
db()->prepare("UPDATE versions SET started_at = started_at - 3600 WHERE doc = ?")->execute([$f7Id]);   // מחוץ לחלון האיחוד
$f7 = doc_set_page($f7, ['size' => 'Letter', 'orientation' => 'landscape'], 'u2', 'רון');
$f7List = versions_list($f7);
check('F07: שינוי הגדרות עמוד — גרסה חדשה (מספר עולה), ובהיסטוריה — על שם מי ששינה', (int)$f7['version'] === $f7v + 1 && doc_page($f7)['size'] === 'Letter'
    && count($f7List) === 2 && $f7List[0]['by'] === 'רון' && $f7List[0]['version'] === $f7v + 1, json_encode($f7List, JSON_UNESCAPED_UNICODE));
check('F07: התוכן לא משתנה, ובסיס ישן — התנגשות (כמו בשמירה)', json_encode(doc_json($f7)) === json_encode(doc_json(doc_find($f7Id, 'a:99')))
    && str_contains(json_encode(doc_json($f7), JSON_UNESCAPED_UNICODE), 'תוכן') && throws(fn() => doc_set_page($f7, ['size' => 'A4'], 'u2', 'רון', $f7v), 'conflict'));
$f7 = doc_restore_version($f7, $f7List[1]['id'], 'u1', 'דנה', $f7['version']);
check('F07: שחזור הגרסה שלפני — מחזיר גם את הגדרות העמוד', doc_page($f7)['size'] === 'A4' && doc_page($f7)['orientation'] === 'portrait', json_encode(doc_page($f7)));

section('EMF / WMF — נייר מכתבים מ-Word (lib/metafile.php)');
// DIB של 2×2, 24 ביט (שורה — 8 בתים עם ריפוד), מלמטה למעלה: שורה תחתונה כחולה, עליונה אדומה
$mfDib = pack('VVVvvVVVVVV', 40, 2, 2, 1, 24, 0, 16, 0, 0, 0, 0);
$mfBits = "\xFF\x00\x00\xFF\x00\x00\0\0" . "\x00\x00\xFF\x00\x00\xFF\0\0";
$mfHead = pack('VV', 1, 88) . str_repeat("\0", 32) . ' EMF' . str_repeat("\0", 44);
$mfStretch = fn() => pack('VV', 81, 80 + 40 + 16) . str_repeat("\0", 16) . pack('V6', 0, 0, 0, 0, 2, 2) . pack('V4', 80, 40, 120, 16) . pack('V4', 0, 0xCC0020, 2, 2) . $mfDib . $mfBits;
$mfEof = pack('VVVVV', 14, 20, 0, 16, 20);
$mfEmf = $mfHead . $mfStretch() . $mfEof;
$mfPng = metafile_to_png($mfEmf);
$mfIm = $mfPng ? imagecreatefrompng('data://image/png;base64,' . base64_encode($mfPng)) : null;
check('EMF שהוא תמונה אחת → PNG באותו גודל ובאותם צבעים (למעלה אדום, למטה כחול)', $mfIm && imagesx($mfIm) === 2 && imagesy($mfIm) === 2
    && (imagecolorat($mfIm, 0, 0) & 0xFFFFFF) === 0xFF0000 && (imagecolorat($mfIm, 1, 1) & 0xFFFFFF) === 0x0000FF);
$mfWmf = pack('vvvVvVv', 1, 9, 0x300, 0, 1, 0, 0)   // META_HEADER (9 מילים)
    . pack('Vv', (6 + 22 + 56) / 2, 0x0F43) . pack('Vv', 0xCC0020, 0) . pack('v8', 2, 2, 0, 0, 2, 2, 0, 0) . $mfDib . $mfBits . pack('Vv', 3, 0);
check('WMF שהוא תמונה אחת (META_STRETCHDIB) → PNG', ($w2 = metafile_to_png($mfWmf)) !== null && getimagesizefromstring($w2)[0] === 2);
check('EMF עם ציור נוסף (קו) — לא מומר (נספר בדוח), וקובץ שאינו EMF — כמו שהוא', metafile_to_png($mfHead . pack('VVVV', 54, 16, 1, 1) . $mfStretch() . $mfEof) === null
    && metafile_to_png($mfHead . $mfStretch() . $mfStretch() . $mfEof) === null && metafile_to_png('GIF89a') === null);
$mfDoc = doc_new('a:99', 'נייר מכתבים', null, null, 'u1', 'דנה');
check('media_store: EMF נשמר כ-PNG', (media_store($mfDoc, $mfEmf)['width'] ?? 0) === 2 && media_of_doc(media_store($mfDoc, $mfEmf)['id'], $mfDoc));
// docx עם נייר מכתבים: תמונה צפה (wp:anchor) בכותרת העליונה, בפורמט EMF
$mfAnchor = '<w:p><w:r><w:drawing><wp:anchor behindDoc="0" relativeHeight="1"><wp:positionH relativeFrom="column"><wp:posOffset>0</wp:posOffset></wp:positionH>'
    . '<wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV><wp:extent cx="5618480" cy="889635"/><wp:wrapNone/><a:graphic><a:graphicData><a:blip r:embed="rI"/></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r></w:p>';
$mfDocx = $mkDocx([
    'word/document.xml' => '<w:document ' . $WN . '><w:body><w:p>' . $run('גוף המכתב') . '</w:p><w:sectPr><w:headerReference w:type="default" r:id="rH"/><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>',
    'word/_rels/document.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rH" Target="header1.xml"/></Relationships>',
    'word/header1.xml' => $hdr('hdr', $mfAnchor),
    'word/_rels/header1.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rI" Target="media/image1.emf"/></Relationships>',
    'word/media/image1.emf' => $mfEmf,
]);
$mfImp = import_docx($mfDocx, $mfDoc);
$mfHdr = $mfImp['page']['header'][0]['content'][0] ?? [];
check('docx עם נייר מכתבים (EMF צף בכותרת): התמונה בכותרת העליונה, והדוח ריק', ($mfHdr['type'] ?? '') === 'image' && media_of_doc($mfHdr['attrs']['media'] ?? '', $mfDoc)
    && ($mfHdr['attrs']['width'] ?? 0) === 590 && $mfImp['report'] === [], json_encode([$mfImp['page']['header'] ?? null, $mfImp['report']], JSON_UNESCAPED_UNICODE));

section('רשימת ציור → PDF (pdf-core: lib/pdf-core/draw.php — לעימוד)');
require_once __DIR__ . '/../lib/pdf-core/draw.php';
$dwFont = file_get_contents(__DIR__ . '/../vendor/fonts/pdf/frank-ruhl-libre-hebrew-400-normal.woff');
$dwF = TtfFont::fromBytes($dwFont, 'frank');
$dwIm = imagecreatetruecolor(40, 40);
imagefilledrectangle($dwIm, 0, 0, 40, 40, imagecolorallocate($dwIm, 30, 120, 220));
ob_start(); imagepng($dwIm); $dwPng = ob_get_clean();
// "שלום" בסדר חזותי (ם ו ל ש), ו-שׁ (U+FB2A — גליף מורכב: ש + נקודת שין) בעמוד השני
$dwRun = fn(string $s, float $size) => array_map(fn($ch) => [$dwF->gid(mb_ord($ch)), 0, 0, $dwF->advance(mb_ord($ch), $size)], array_reverse(mb_str_split($s)));
$dwList = ['title' => 'ציור', 'pages' => [
    ['size' => [300, 200], 'bleed' => 9, 'ops' => [
        ['op' => 'rect', 'box' => [-9, -9, 318, 40], 'fill' => '#ffeecc'],
        ['op' => 'glyphs', 'font' => 'media/frank.woff', 'size' => 24, 'color' => '#202020', 'x' => 40, 'y' => 80, 'glyphs' => $dwRun('שלום', 24), 'text' => 'שלום'],
        ['op' => 'image', 'src' => 'media/a.png', 'box' => [200, 100, 80, 80], 'clip' => [210, 110, 40, 40]],
        ['op' => 'line', 'from' => [20, 150], 'to' => [180, 150], 'color' => '#cc0000', 'width' => 2],
        ['op' => 'rect', 'box' => [20, 160, 100, 20], 'stroke' => '#0000ff'],
    ]],
    ['size' => [100, 100], 'ops' => [['op' => 'glyphs', 'font' => 'media/frank.woff', 'size' => 60, 'x' => 20, 'y' => 70, 'glyphs' => [[$dwF->gid(0xFB2A), 0, 0, 40]], 'text' => 'שׁ']]],
]];
$dwRes = fn($k) => ['media/frank.woff' => $dwFont, 'media/a.png' => $dwPng][$k] ?? null;
$dwPdf = pdf_draw($dwList, $dwRes);
$dwRaw = preg_replace_callback('/stream\n(.*?)\nendstream/s', fn($m) => 'stream ' . (@gzuncompress($m[1]) ?: '') . ' endstream', $dwPdf);
preg_match('/\/Length1 (\d+)/', $dwPdf, $dwLen);
check('ציור: עמוד בגודל חופשי עם גלישה — MediaBox ו-BleedBox מורחבים ב-9, TrimBox הוא העמוד', str_contains($dwPdf, '/MediaBox [0 0 318 218] /BleedBox [0 0 318 218] /TrimBox [9 9 309 209]')
    && str_contains($dwPdf, '/MediaBox [0 0 100 100] /Resources'));
check('ציור: הגופן מוטמע רק עם הגליפים שבשימוש (subset) — ומזהי הגליפים נשמרים', (int)($dwLen[1] ?? 0) > 0 && (int)$dwLen[1] < strlen($dwF->sfnt) / 3
    && TtfFont::fromBytes(TtfSubset::make($dwF->sfnt, [$dwF->gid(0x5E9)]), 's')->numGlyphs() === $dwF->numGlyphs(), ($dwLen[1] ?? '?') . ' / ' . strlen($dwF->sfnt));
check('ציור: רצף הגליפים — כל גליף במקומו (Tm), עם ActualText לחיפוש והעתקה', str_contains($dwRaw, '/Span << /ActualText ' . PdfWriter::text('שלום') . ' >> BDC')
    && str_contains($dwRaw, '1 0 0 1 49 129 Tm <' . sprintf('%04X', $dwF->gid(0x5DD)) . '> Tj'), substr($dwRaw, strpos($dwRaw, 'BDC') ?: 0, 200));
check('ציור: תמונה עם חיתוך, מלבן מלא, מסגרת וקו — בקואורדינטות של PDF (y למעלה)', str_contains($dwRaw, '219 59 40 40 re W n') && str_contains($dwRaw, '80 0 0 80 209 29 cm /Im1 Do')
    && str_contains($dwRaw, '29 29 100 20 re S') && str_contains($dwRaw, '29 59 m 189 59 l S') && str_contains($dwRaw, '0 178 318 40 re f'), substr($dwRaw, strpos($dwRaw, ' re f') ?: 0, 300));
if (trim((string)shell_exec('command -v pdftoppm'))) {
    file_put_contents("$tmp/draw.pdf", $dwPdf);
    shell_exec('pdftoppm -r 72 -png ' . escapeshellarg("$tmp/draw.pdf") . ' ' . escapeshellarg("$tmp/draw") . ' 2>&1');
    $dwInk = function (string $png, array $r): int {
        $im = @imagecreatefrompng($png); $n = 0;
        if (!$im) return -1;
        for ($y = $r[1]; $y < $r[3]; $y++) for ($x = $r[0]; $x < $r[2]; $x++) { $c = imagecolorat($im, $x, $y); if ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255) < 200) $n++; }
        return $n;
    };
    $dwP1 = glob("$tmp/draw-*1.png")[0] ?? '';
    $dwP2 = glob("$tmp/draw-*2.png")[0] ?? '';
    check('ציור, כפי שמוצג (pdftoppm): הטקסט מצויר — והתמונה רק בתוך מלבן החיתוך', $dwInk($dwP1, [45, 65, 140, 95]) > 50
        && (imagecolorat(imagecreatefrompng($dwP1), 235, 135) & 0xFF) > 200 && (imagecolorat(imagecreatefrompng($dwP1), 275, 175) & 0xFFFFFF) === 0xFFFFFF, (string)$dwInk($dwP1, [45, 65, 140, 95]));
    check('ציור: גליף מורכב (שׁ — ש + נקודה) — גם הרכיבים שלו נכנסים ל-subset ומצוירים', $dwInk($dwP2, [15, 10, 70, 80]) > 200, (string)$dwInk($dwP2, [15, 10, 70, 80]));
    array_map('unlink', glob("$tmp/draw*") ?: []);
}
$dwErr = function (array $list) use ($dwRes): string {
    try { pdf_draw($list, $dwRes); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
};
check('ציור: רשימה פגומה — הודעה ברורה עם מקום הבעיה', str_contains($dwErr(['pages' => [['size' => [10, 10], 'ops' => [['op' => 'circle']]]]]), 'עמוד 1, פעולה 1')
    && str_contains($dwErr(['pages' => [['size' => [10, 10], 'ops' => [['op' => 'glyphs', 'font' => 'media/x.ttf', 'size' => 9, 'x' => 0, 'y' => 0, 'glyphs' => []]]]]]), 'גופן חסר')
    && str_contains($dwErr(['pages' => [['size' => [10, 10], 'ops' => [['op' => 'glyphs', 'font' => 'media/frank.woff', 'size' => 9, 'x' => 0, 'y' => 0, 'glyphs' => [[999999, 0, 0, 1]]]]]]]), 'גליף')
    && $dwErr(['pages' => []]) === 'אין עמודים');
array_map('unlink', glob("$tmp/media/mbe/*") ?: []);
@rmdir("$tmp/media/mbe");

// ניקוי
array_map('unlink', glob("$tmp/media/*") ?: []);
@rmdir("$tmp/media");
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);

echo "\n" . ($fail ? "✗ $fail נכשלו, $pass עברו\n" : "✓ כל $pass הבדיקות עברו\n");
exit($fail ? 1 : 0);
