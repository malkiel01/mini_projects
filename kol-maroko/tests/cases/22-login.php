<?php
// P1 — lib/login.php: העוגייה kolm_sess, ה-session, החלפת sub, יציאה, granted:false, CSRF של ה-session, ונקודות בלי העוגייה.

declare(strict_types=1);

const P1_A = ['sub' => '7001', 'name' => 'אלף', 'email' => 'alef@example.test', 'email_verified' => true];
const P1_B = ['sub' => '7002', 'name' => 'בית', 'email' => 'bet@example.test', 'email_verified' => true];

/** בקשה "גולמית" — הכותרות בידי הבדיקה (בלי ה-jar של run.php). */
function p1_raw(string $method, string $url, ?array $who, string $cookie = '', array $more = [], ?array $json = null): array
{
    $h = $more;
    if ($who) $h[] = 'X-Test-User: ' . json_encode($who, JSON_UNESCAPED_UNICODE);
    if ($cookie !== '') $h[] = "Cookie: kolm_sess=$cookie";
    return http($method, $url, ['headers' => $h] + ($json !== null ? ['json' => $json] : []));
}

function p1_cookie(array $r): ?array
{
    if (!preg_match('/^kolm_sess=([^;]*)(.*)$/i', $r['headers']['set-cookie'] ?? '', $m)) return null;
    return ['value' => $m[1], 'attrs' => strtolower($m[2])];
}

test('P1 כניסה: בקשה ראשונה — המשתמש נפתח מיד (active, בלי תפקיד), ועוגייה HttpOnly · SameSite=Lax · Path של האפליקציה', function () {
    $r = p1_raw('GET', '/api.php?action=me', P1_A);
    eq(200, $r['status']);
    $c = p1_cookie($r);
    ok($c && preg_match('/^[a-f0-9]{64}$/', $c['value']), 'מזהה אקראי של 32 בתים');
    ok(str_contains($c['attrs'], 'httponly') && str_contains($c['attrs'], 'samesite=lax') && str_contains($c['attrs'], 'path=/;'), 'מאפייני העוגייה: ' . $c['attrs']);
    $u = user_by_sub('7001');
    eq(['active', null, 'alef@example.test'], [$u['status'], $u['role'], $u['email']]);
    // במסד — רק הגיבוב, לא המזהה
    eq(0, (int)db()->query("SELECT COUNT(*) FROM sessions WHERE id = " . db()->quote($c['value']))->fetchColumn(), 'המזהה הגולמי אינו במסד');
    eq(1, (int)db()->query("SELECT COUNT(*) FROM sessions WHERE user_id = {$u['id']}")->fetchColumn());
    // אותה עוגייה — אותו session, בלי עוגייה חדשה
    $r2 = p1_raw('GET', '/api.php?action=me', P1_A, $c['value']);
    eq(null, p1_cookie($r2), 'בלי Set-Cookie כשה-session תקף');
    eq($r['json']['csrf'], $r2['json']['csrf'], 'אותו אסימון CSRF');
});

test('P1 כניסה: ה-CSRF הוא של session חי של המשתמש — אסימון של משתמש אחר נדחה; בלי X-Kol נדחה; session שנמחק — נדחה', function () {
    db()->prepare("UPDATE users SET role = 'editor' WHERE sub = '7001'")->execute();
    $a1 = p1_raw('GET', '/api.php?action=me', P1_A);
    $a2 = p1_raw('GET', '/api.php?action=me', P1_A);                 // session שני, מדפדפן אחר
    $c1 = p1_cookie($a1)['value'];
    ok($a1['json']['csrf'] !== $a2['json']['csrf'], 'לכל session אסימון משלו');
    $body = ['op' => 'release'];
    eq(200, p1_raw('POST', '/api.php?action=lock', P1_A, $c1, ['X-Kol: 1', 'X-CSRF: ' . $a1['json']['csrf']], $body)['status'], 'האסימון של ה-session');
    // טעינה ראשונה: בקשות מקבילות בלי עוגייה — האסימון של session חי אחר של אותו משתמש מתקבל
    eq(200, p1_raw('POST', '/api.php?action=lock', P1_A, $c1, ['X-Kol: 1', 'X-CSRF: ' . $a2['json']['csrf']], $body)['status'], 'אסימון של session חי של אותו משתמש');
    $b = p1_raw('GET', '/api.php?action=me', P1_B);
    db()->prepare("UPDATE users SET role = 'editor' WHERE sub = '7002'")->execute();
    eq(403, p1_raw('POST', '/api.php?action=lock', P1_A, $c1, ['X-Kol: 1', 'X-CSRF: ' . $b['json']['csrf']], $body)['status'], 'אסימון של משתמש אחר');
    eq(403, p1_raw('POST', '/api.php?action=lock', P1_A, $c1, ['X-CSRF: ' . $a1['json']['csrf']], $body)['status'], 'בלי X-Kol');
    db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([login_sid_hash(p1_cookie($a2)['value'])]);
    eq(403, p1_raw('POST', '/api.php?action=lock', P1_A, $c1, ['X-Kol: 1', 'X-CSRF: ' . $a2['json']['csrf']], $body)['status'], 'session שנמחק — האסימון שלו לא תקף');
    db()->prepare("UPDATE users SET role = NULL WHERE sub = '7002'")->execute();
});

test('P1 כניסה: ה-session שייך ל-sub אחר ← מוחלף; אין PLATFORM_USER ← נמחק והעוגייה מתבטלת', function () {
    $a = p1_raw('GET', '/api.php?action=me', P1_A);
    $ca = p1_cookie($a)['value'];
    $b = p1_raw('GET', '/api.php?action=me', P1_B, $ca);              // אותו דפדפן, משתמש אחר בפלטפורמה
    $cb = p1_cookie($b);
    ok($cb && $cb['value'] !== $ca, 'עוגייה חדשה');
    eq('בית', $b['json']['user']['name']);
    eq(0, (int)db()->query("SELECT COUNT(*) FROM sessions WHERE id = " . db()->quote(login_sid_hash($ca)))->fetchColumn(), 'ה-session של אלף נמחק');
    // יציאה מהפלטפורמה
    $out = p1_raw('GET', '/api.php?action=me', null, $cb['value']);
    eq(401, $out['status']);
    $gone = p1_cookie($out);
    ok($gone && $gone['value'] === '' || str_contains((string)($out['headers']['set-cookie'] ?? ''), 'deleted'), 'העוגייה מתבטלת: ' . ($out['headers']['set-cookie'] ?? ''));
    eq(0, (int)db()->query("SELECT COUNT(*) FROM sessions WHERE id = " . db()->quote(login_sid_hash($cb['value'])))->fetchColumn(), 'ה-session נמחק');
});

test('P1 כניסה: granted:false (מחובר בלי הרשאה) ← אין משתמש; זהות לא תקינה ← אין משתמש', function () {
    eq(401, p1_raw('GET', '/api.php?action=me', ['sub' => '7003', 'name' => 'ג', 'granted' => false])['status']);
    eq(null, user_by_sub('7003'), 'לא נפתח משתמש');
    eq(401, p1_raw('GET', '/api.php?action=me', ['name' => 'בלי sub'])['status']);
});

test('P1 כניסה: הוזמן לפי מייל ← מתקשר ל-sub רק כשהמייל מאומת; בעל האתר — לפי platform_admin', function () {
    db()->prepare("INSERT INTO users (name, email, role, status, created_at) VALUES ('מוזמן', 'invited-p1@example.test', 'publisher', 'active', ?)")->execute([now_iso()]);
    p1_raw('GET', '/api.php?action=me', ['sub' => '7004', 'name' => 'מוזמן', 'email' => 'invited-p1@example.test', 'email_verified' => false]);
    eq(null, user_by_sub('7004')['role'], 'מייל לא מאומת — חשבון חדש, בלי תפקיד');
    $r = p1_raw('GET', '/api.php?action=me', ['sub' => '7005', 'name' => 'מוזמן', 'email' => 'invited-p1@example.test', 'email_verified' => true]);
    eq('publisher', $r['json']['user']['role'], 'מאומת — מתקשר לשורה שהוזמנה');
    $o = p1_raw('GET', '/api.php?action=me', ['sub' => '7006', 'name' => 'מנהל', 'email' => 'adm@example.test', 'email_verified' => true, 'platform_admin' => true]);
    eq(['site_owner', true], [$o['json']['user']['role'], $o['json']['user']['developer']]);
    // שורה שנשארה site_owner בלי platform_admin — בלי תפקיד
    eq(null, p1_raw('GET', '/api.php?action=me', ['sub' => '7006', 'name' => 'מנהל', 'email' => 'adm@example.test', 'email_verified' => true])['json']['user']['role']);
});

test('P1 כניסה: התפקיד נקרא מ-users בכל בקשה — שינוי ו-removed חלים מיד על session פתוח', function () {
    $a = p1_raw('GET', '/api.php?action=me', P1_A);
    $c = p1_cookie($a)['value'];
    db()->prepare("UPDATE users SET role = 'editor', status = 'active' WHERE sub = '7001'")->execute();
    eq(200, p1_raw('GET', '/api.php?action=pf_tree', P1_A, $c)['status']);
    db()->prepare("UPDATE users SET role = 'content_editor' WHERE sub = '7001'")->execute();
    eq('content_editor', p1_raw('GET', '/api.php?action=me', P1_A, $c)['json']['user']['role']);
    db()->prepare("UPDATE users SET status = 'removed' WHERE sub = '7001'")->execute();
    $me = p1_raw('GET', '/api.php?action=me', P1_A, $c)['json']['user'];
    eq([null, true], [$me['role'], $me['removed']], 'הוסר — בלי תפקיד, ו"הוסרת" בממשק');
    eq(403, p1_raw('GET', '/api.php?action=words', P1_A, $c)['status']);
});

test('P1 כניסה: form.php, preview/ ו-agent.php — בלי העוגייה kolm_sess (לא קוראים ולא כותבים אותה)', function () {
    $c = p1_cookie(p1_raw('GET', '/api.php?action=me', P1_B))['value'];
    foreach (['/form.php', '/preview/index.php', '/agent.php?action=help'] as $u) {
        $r = p1_raw('GET', $u, P1_B, $c);
        ok(!isset($r['headers']['set-cookie']) || !str_contains($r['headers']['set-cookie'], 'kolm_sess'), "$u: בלי Set-Cookie של kolm_sess");
    }
    foreach (['form.php', 'preview/index.php', 'agent.php', 'lib/forms.php', 'lib/preview.php'] as $f) {
        ok(!preg_match('/current_user\(|kolm_sess|LOGIN_COOKIE/', (string)file_get_contents(KOL_ROOT . "/$f")), "$f אינו נוגע ב-session של הסטודיו");
    }
});

test('P1 כניסה: PLATFORM_USER נקרא רק ב-lib/login.php', function () {
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KOL_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = substr((string)$f, strlen(KOL_ROOT) + 1);
        if (!str_ends_with($p, '.php') || preg_match('~^(tests|wordpress-plugin|data|dev)/~', $p) || $p === 'lib/login.php') continue;
        if (preg_match('/PLATFORM_USER|\bidentity\(\)/', (string)file_get_contents((string)$f))) $hits[] = $p;
    }
    eq([], $hits);
});
