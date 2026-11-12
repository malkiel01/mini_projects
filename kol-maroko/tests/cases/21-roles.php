<?php
// P1 — מטריצת ההרשאות: כל פעולה ב-ACTION_ROLES × חמשת התפקידים + "בלי תפקיד" + "בלי משתמש", דרך api.php האמיתי.
// הפעולה עצמה אינה רצה (X-Test-Authorize-Only: api.php עוצר אחרי authorize), כך שגם פעולות הרסניות נבדקות בלי נזק.

declare(strict_types=1);

const P1_USERS = ['content_editor' => U_CONTENT, 'editor' => U_EDITOR, 'publisher' => U_PUBLISHER, 'page_owner' => U_PAGE, 'site_owner' => U_OWNER];
const P1_GET_DEV = ['dev-items', 'dev-my-reports', 'dev-file', 'dev-tokens', 'dev-item-log', 'dev-log', 'dev-audit', 'dev-health', 'dev-state', 'dev-spike-fixture', 'dev-forms'];

function p1_api_actions(): array
{
    preg_match_all("/^        '([a-z_-]+)' => /m", (string)file_get_contents(KOL_ROOT . '/api.php'), $m);
    return $m[1];
}

function p1_method(string $action): string
{
    preg_match('/const POST_ACTIONS = \[(.*?)\];/s', (string)file_get_contents(KOL_ROOT . '/api.php'), $m);
    preg_match_all("/'([a-z_]+)'/", $m[1], $mm);
    if (str_starts_with($action, 'dev')) return in_array($action, P1_GET_DEV, true) ? 'GET' : 'POST';
    if ($action === 'forms_settings') return 'GET';
    return in_array($action, $mm[1], true) ? 'POST' : 'GET';
}

function p1_auth(string $action, ?array $user): int
{
    $m = p1_method($action);
    $o = ['headers' => ['X-Test-Authorize-Only: 1']] + ($user ? ['user' => $user] : []) + ($m === 'POST' ? ['json' => []] : []);
    return http($m, "/api.php?action=$action", $o)['status'];
}

test('P1 הרשאות: כל פעולה ב-api.php וב-dev_route מסווגת ב-ACTION_ROLES, ואין שורה בטבלה בלי פעולה', function () {
    $api = p1_api_actions();
    ok(count($api) > 100, 'הביטוי מצא את הפעולות');
    $all = array_unique(array_merge($api, DEV_ACTIONS));
    eq([], array_values(array_diff($all, array_keys(ACTION_ROLES))), 'פעולות בלי סיווג');
    eq([], array_values(array_diff(array_keys(ACTION_ROLES), $all, ['devapi-feed', 'devapi-comment', 'devapi-file', 'devapi-attach'])), 'שורות בלי פעולה');
    // ההרשאה — במקום אחד: אין require_role מפוזר בתוך ה-match
    $src = (string)file_get_contents(KOL_ROOT . '/api.php');
    eq(0, preg_match_all('/require_role\(|require_developer\(/', $src), 'require_role מפוזר ב-api.php');
    foreach (ACTION_ROLES as $a => $r) ok($r === 'public' || $r === 'any' || isset(ROLE_RANK[$r]), "$a: תפקיד לא מוכר $r");
    // agent.php — כל פעולה שלו ממופה לפעולה שבטבלה
    foreach (AGENT_ACTIONS as $a => $to) ok(isset(ACTION_ROLES[$to]), "agent $a ← $to");
    // הציבוריות — גם ב-"public" של app.json
    $pub = json_decode((string)file_get_contents(KOL_ROOT . '/app.json'), true)['public'][0];
    foreach (array_keys(array_filter(ACTION_ROLES, fn($r) => $r === 'public')) as $a) ok(str_contains($pub, $a), "$a ב-public של app.json");
});

test('P1 הרשאות: המטריצה — כל פעולה × 5 תפקידים + בלי תפקיד + בלי משתמש ← 200 / 403 / 401 בדיוק לפי הטבלה', function () {
    $bad = [];
    $n = 0;
    foreach (ACTION_ROLES as $a => $min) {
        if ($min === 'public' || str_starts_with($a, 'devapi')) continue;
        $exp = ['none' => 401, 'stranger' => $min === 'any' ? 200 : 403];
        foreach (P1_USERS as $role => $u) $exp[$role] = $min === 'any' || ROLE_RANK[$role] >= ROLE_RANK[$min] ? 200 : 403;
        foreach ($exp as $who => $want) {
            $got = p1_auth($a, $who === 'none' ? null : ($who === 'stranger' ? U_STRANGER : P1_USERS[$who]));
            $n++;
            if ($got !== $want) $bad[] = "$a · $who: $got (ציפיתי $want)";
        }
    }
    eq([], array_slice($bad, 0, 20), "מתוך $n בדיקות");
    ok($n > 1000, "נבדקו $n צירופים");
});

test('P1 הרשאות: עורך תוכן — כותב מילים, ונדחה (403, עם ההסבר) בכל כתיבה לקבצי הפרויקט, לקוד ולטיוטה', function () {
    foreach (['pf_write', 'pf_create', 'pf_delete', 'code_save', 'draft_save', 'pf_zip_import', 'list_place', 'build_start', 'publish', 'build_send'] as $a) {
        $r = http('POST', "/api.php?action=$a", ['user' => U_CONTENT, 'json' => ['path' => 'x.html', 'content' => 'x']]);
        eq(403, $r['status'], $a);
        ok(str_contains((string)$r['json']['error'], 'עורך תוכן') || str_contains((string)$r['json']['error'], 'מפרסם'), "$a: ההסבר — " . $r['json']['error']);
    }
    $w = http('POST', '/api.php?action=word_save', ['user' => U_CONTENT, 'json' => ['word' => 'עורך-תוכן-P1', 'meaning' => 'בדיקה']]);
    eq(200, $w['status'], $w['body']);
    eq(200, http('GET', '/api.php?action=pf_tree', ['user' => U_CONTENT])['status'], 'קריאה של קבצי הפרויקט');
    eq(200, http('GET', '/api.php?action=code', ['user' => U_CONTENT])['status'], 'קריאה של הקוד');
});

test('P1 הרשאות: אדם בלי תפקיד — 403 לכל פעולה, נרשם ב"ביקשו גישה", ובעל העמוד מקבל הודעה', function () {
    $me = http('GET', '/api.php?action=me', ['user' => U_STRANGER])['json'];
    eq(null, $me['user']['role'], 'לזר אין תפקיד');
    eq(403, http('GET', '/api.php?action=words', ['user' => U_STRANGER])['status']);
    $p = http('GET', '/api.php?action=people', ['user' => U_PAGE])['json'];
    ok(in_array('stranger@example.test', array_column($p['requests'], 'email'), true), 'ב"ביקשו גישה"');
    $n = http('GET', '/api.php?action=notices', ['user' => U_PAGE])['json']['notices'];
    ok((bool)array_filter($n, fn($x) => $x['kind'] === 'access' && str_contains($x['text'], 'זר')), 'הודעה לבעל העמוד');
});
