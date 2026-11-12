<?php
/**
 * M7 — הצד של הכלי: התור היוצא (lib/outbox.php) ונקודת המשיכה (pull.php). H24–H26 של ערכת ההתקפה, ובדיקת קצה לקצה מול
 * התוסף האמיתי (tests/fake-wp.php): פרסום בכלי ⇒ משיכה ⇒ applied ⇒ "✓ הוחל".
 * (H18–H23, H27, H28 — בצד התוסף: tests/plugin/70-pull_test.php.)
 */

declare(strict_types=1);

require_once KOL_ROOT . '/lib/build.php';

const U_SITE = U_OWNER;          // בעל האתר (platform_admin)

/** בקשה ל-pull.php. $token — null: בלי אסימון; $hdr — Authorization (ברירת מחדל) או X-Kol-Pull-Token. */
function m7_pull(string $q, ?string $token, string $method = 'GET', ?array $json = null, array $headers = [], string $hdr = 'auth'): array
{
    if ($token !== null && $hdr !== 'none') $headers[] = $hdr === 'auth' ? "Authorization: Bearer $token" : "X-Kol-Pull-Token: $token";
    return http($method, "/pull.php?$q", ['headers' => $headers] + ($json !== null ? ['json' => $json] : []));
}

/** ערוץ משיכה, אתר מדומה, אסימון חדש. מחזיר את האסימון. */
function m7_ready(): string
{
    wp_site_ready();
    setting_set('pub_channel', 'pull');
    setting_set('pull_url', $GLOBALS['BASE'] . '/pull.php');
    db()->exec("DELETE FROM settings WHERE k IN ('pull_rate', 'site_heartbeat')");
    $r = m1_post('pull_line', [], U_SITE);
    if ($r['status'] !== 200) throw new TestFail('pull_line: ' . $r['body']);
    return substr(strrchr($r['json']['line'], ':'), 1);
}

function m7_done(): void
{
    setting_set('pub_channel', 'push');
    db()->exec("UPDATE site_outbox SET state = 'superseded' WHERE state IN ('queued','delivered')");
}

function m7_row(int $seq): array
{
    $st = db()->prepare('SELECT * FROM site_outbox WHERE seq = ?');
    $st->execute([$seq]);
    return $st->fetch() ?: [];
}

test('M7 הערוץ: ברירת המחדל — pull ל-maghreb.co.il, push לכל אתר אחר; הגדרה — בעל האתר בלבד, וביומן', function () {
    $was = setting('site_url');
    db()->exec("DELETE FROM settings WHERE k = 'pub_channel'");
    setting_set('site_url', 'https://maghreb.co.il');
    eq('pull', pub_channel());
    setting_set('site_url', $GLOBALS['WP']);
    eq('push', pub_channel());
    eq(403, m1_post('pub_channel_set', ['channel' => 'pull'], U_PUBLISHER)['status'], 'מפרסם');
    eq(403, m1_post('pull_line', [], U_PAGE)['status'], 'בעלת העמוד — אינה יוצרת אסימון');
    eq(422, m1_post('pub_channel_set', ['channel' => 'carrier-pigeon'], U_SITE)['status']);
    $r = m1_post('pub_channel_set', ['channel' => 'pull'], U_SITE);
    eq([200, 'pull'], [$r['status'], $r['json']['channel']]);
    eq('pull', pub_channel());
    ok(str_contains((string)db()->query("SELECT detail FROM audit WHERE action = 'pub_channel' ORDER BY id DESC")->fetchColumn(), 'משיכה'), 'ביומן');
    setting_set('pub_channel', 'push');
    $was === null ? db()->exec("DELETE FROM settings WHERE k = 'site_url'") : setting_set('site_url', $was);
});

test('M7 שורת החיבור: kol-maroko:2:<kid>:<מפתח>:<כתובת>:<אסימון>; האסימון נשמר רק כ-sha256; מוצג פעם אחת', function () {
    $tok = m7_ready();
    $r = m1_post('pull_line', [], U_SITE);
    $line = $r['json']['line'];
    $k = pub_key();
    ok(str_starts_with($line, 'kol-maroko:2:' . $k['kid'] . ':' . $k['public'] . ':' . $GLOBALS['BASE'] . '/pull.php:'), $line);
    $tok2 = substr(strrchr($line, ':'), 1);
    ok(preg_match('/^[A-Za-z0-9_-]{43}$/', $tok2) === 1, '32 בייט, base64url');
    ok($tok2 !== $tok, 'אסימון חדש בכל יצירה');
    eq(hash('sha256', $tok2), setting('pull_token_hash'));
    $all = json_encode(db()->query('SELECT * FROM settings')->fetchAll()) . json_encode(db()->query('SELECT * FROM audit')->fetchAll());
    ok(!str_contains($all, $tok2), 'האסימון עצמו אינו במסד');
    $s = m1_get('publish_settings', '', U_SITE)['json'];
    ok(!str_contains(json_encode($s), $tok2), 'ולא בהגדרות');
    eq(['pull', true], [$s['channel'], $s['token_at'] !== null]);
    m7_done();
});

test('M7 H24: pull.php בלי אסימון / שגוי / ישן אחרי "החלף אסימון" — 401, נספר במסך המפתח; עם אסימון — בלי עוגייה, no-store; 60 לדקה', function () {
    $old = m7_ready();
    $bad0 = (int)(json_decode((string)setting('pull_bad'), true)['count'] ?? 0);
    eq(401, m7_pull('after=0', null)['status'], 'בלי אסימון');
    eq(401, m7_pull('after=0', ($old[0] === 'A' ? 'B' : 'A') . substr($old, 1))['status'], 'שגוי');
    eq(401, m7_pull('after=0', $old, 'GET', null, ["Cookie: kolm_sess=abc"], 'none')['status'], 'עוגייה אינה זהות');
    $new = substr(strrchr(m1_post('pull_line', [], U_SITE)['json']['line'], ':'), 1);
    eq(401, m7_pull('after=0', $old)['status'], 'הישן אחרי החלפה');
    eq($bad0 + 4, (int)json_decode((string)setting('pull_bad'), true)['count'], 'נספר');
    eq($bad0 + 4, (int)m1_get('publish_settings', '', U_SITE)['json']['bad']['count'], 'במסך');
    $r = m7_pull('after=0', $new);
    eq(200, $r['status'], $r['body']);
    eq(200, m7_pull('after=0', $new, 'GET', null, [], 'x')['status'], 'X-Kol-Pull-Token (כשהשרת מסיר Authorization)');
    eq('no-store', $r['headers']['cache-control'] ?? null);
    ok(!isset($r['headers']['set-cookie']), 'בלי עוגייה');
    eq([true, []], [$r['json']['ok'], $r['json']['items']]);
    eq(400, m7_pull('after=-1', $new)['status'], 'after לא תקין');
    eq(405, m7_pull('after=0', $new, 'DELETE')['status']);
    // הקצב
    setting_set('pull_rate', json_encode(['m' => intdiv(time(), 60), 'c' => 59]));
    eq(200, m7_pull('ping', $new)['status'], 'ה-60');
    eq([429, 'rate'], [($x = m7_pull('ping', $new))['status'], $x['json']['code'] ?? null], 'ה-61');
    db()->exec("DELETE FROM settings WHERE k = 'pull_rate'");
    // ביומן הבקשות של מסך המפתח — עם ה-IP, בלי האסימון
    $log = json_encode(db()->query("SELECT * FROM dev_log WHERE category = 'pull' ORDER BY id DESC LIMIT 20")->fetchAll(), JSON_UNESCAPED_UNICODE);
    ok(str_contains($log, '127.0.0.1') && str_contains($log, 'pull.items'), 'ביומן: ' . mb_substr($log, 0, 200));
    ok(!str_contains($log, $new) && !str_contains($log, $old), 'האסימון אינו ביומן');
    m7_done();
});

test('M7 התור: seq ו-expires בתוך החתימה; מניפסט — seq ו-expires גם בתוכו; "פרסם" ⇒ ממתין, לא "באתר"; אין asset_has / asset_put', function () {
    $tok = m7_ready();
    m1_wp_opt('kol_v2_mock', null);
    m1_wp_opt('kol_wp_plugin_v1', null);
    $b = m1_build(U_PUBLISHER);
    $r = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER);
    eq(200, $r['status'], $r['body']);
    eq([true, 'sending'], [$r['json']['queued'], $r['json']['build']['status']], 'בתור — לא "באתר"');
    $row = m7_row($r['json']['seq']);
    eq(['manifest', 'queued', (string)$b['id']], [$row['action'], $row['state'], $row['ref']]);
    $env = json_decode($row['body'], true);
    eq([$row['seq'], $row['expires']], [$env['seq'], $env['expires']], 'בתוך החתימה');
    ok(abs($env['expires'] - (time() + 7 * 86400)) < 5, 'manifest — 7 ימים');
    ok(sodium_crypto_sign_verify_detached(base64_decode($row['sig']), $row['body'], base64_decode(pub_key()['public'])), 'החתימה על הבייטים שנשמרו');
    $man = json_decode($env['manifest'], true);
    eq([(int)$row['seq'], (int)$row['expires']], [$man['seq'], $man['expires']], 'המניפסט נושא את ה-seq שלו');
    ok(sodium_crypto_sign_verify_detached(base64_decode($env['manifest_sig']), $env['manifest'], base64_decode(pub_key()['public'])), 'חתימה נפרדת על המניפסט');
    eq('sending', build_row($b['id'])['status']);
    eq(0, count(array_filter(db()->query("SELECT detail FROM audit WHERE action = 'pkg_publish' ORDER BY id DESC LIMIT 1")->fetchAll(), fn($a) => str_contains($a['detail'], (string)$man['version']))), 'לא נרשם "פורסם"');
    // הדפיקה נשלחה (במאמץ בלבד) לכתובת של האתר
    $w = json_decode((string)setting('pull_wake'), true);
    ok(($w['at'] ?? 0) >= time() - 10, 'דפיקה: ' . json_encode($w));
    // במשיכה: הפריט, ה-delivered, ו-after שמסנן
    $p = m7_pull('after=0', $tok)['json'];
    $mine = array_values(array_filter($p['items'], fn($i) => $i['seq'] === (int)$row['seq']));
    eq(1, count($mine));
    eq($row['body'], base64_decode($mine[0]['body']), 'הבייטים בדיוק');
    eq('delivered', m7_row($row['seq'])['state']);
    eq([], array_values(array_filter(m7_pull('after=' . $row['seq'], $tok)['json']['items'], fn($i) => $i['seq'] <= $row['seq'])), 'after');
    // אין "שליחת קבצים" מצד הכלי בערוץ הזה
    throws(fn() => site_call('asset_has', ['sha256' => []]));
    m7_done();
});

test('M7 H25: blob של sha256 שאינו במניפסט שבתור — 404; שבתור — הבייטים, עם Range (206) עד 8MB; אחרי שהוחל — 404', function () {
    $tok = m7_ready();
    $b = m1_build(U_PUBLISHER);
    $r = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER);
    $files = json_decode(build_row($b['id'])['files'], true);
    $f = array_values(array_filter($files, fn($x) => $x['size'] > 20))[0];
    $bytes = (string)file_get_contents(build_blob_path($f));
    eq(404, m7_pull('blob=' . str_repeat('a', 64), $tok)['status'], 'sha שאינו במניפסט');
    eq(404, m7_pull('blob=../../data/kol.sqlite', $tok)['status'], 'נתיב');
    eq(401, m7_pull('blob=' . $f['sha'], null)['status'], 'בלי אסימון');
    $all = m7_pull('blob=' . $f['sha'], $tok);
    eq([200, $bytes], [$all['status'], $all['body']]);
    eq('application/octet-stream', $all['headers']['content-type'] ?? null);
    $part = m7_pull('blob=' . $f['sha'], $tok, 'GET', null, ['Range: bytes=5-9']);
    eq([206, substr($bytes, 5, 5), 'bytes 5-9/' . strlen($bytes)], [$part['status'], $part['body'], $part['headers']['content-range'] ?? null]);
    eq(416, m7_pull('blob=' . $f['sha'], $tok, 'GET', null, ['Range: bytes=999999-'])['status']);
    eq([0, PULL_CHUNK - 1], pull_range(null, 20 * 1024 * 1024), 'בלי Range — עד 8MB');
    eq([100, 100 + PULL_CHUNK - 1], pull_range('bytes=100-', 20 * 1024 * 1024), 'Range פתוח — עד 8MB');
    // הוחל — כבר לא מוגש
    m7_pull('report', $tok, 'POST', ['results' => [['seq' => $r['json']['seq'], 'state' => 'applied']], 'state' => []]);
    eq(404, m7_pull('blob=' . $f['sha'], $tok)['status'], 'אחרי applied');
    m7_done();
});

test('M7 H26: "דולק ← כבוי ← דולק" בזמן שהאתר לא משך — רק האחרונה מוגשת; שתי הקודמות superseded', function () {
    $tok = m7_ready();
    $seqs = [];
    foreach ([true, false, true] as $on) {
        $r = m1_post('site_switch', ['enabled' => $on], U_PUBLISHER);
        eq(200, $r['status'], $r['body']);
        $seqs[] = $r['json']['site']['seq'];
    }
    eq(['superseded', 'superseded', 'queued'], array_map(fn($s) => m7_row($s)['state'], $seqs));
    eq(86400, (int)m7_row($seqs[2])['expires'] - strtotime(m7_row($seqs[2])['created_at']), 'switch — 24 שעות');
    $items = array_values(array_filter(m7_pull('after=0', $tok)['json']['items'], fn($i) => $i['action'] === 'switch'));
    eq([$seqs[2]], array_column($items, 'seq'));
    eq(true, json_decode(base64_decode($items[0]['body']), true)['enabled']);
    ok(str_contains((string)db()->query("SELECT detail FROM audit WHERE action = 'site_switch' ORDER BY id DESC")->fetchColumn(), 'בתור'), 'ביומן: בתור');
    m7_done();
});

test('M7 התוקף: פקודה שפג תוקפה — expired, הבנייה מקבלת שגיאה; "שלח שוב" — seq חדש; דיווח expired מהאתר', function () {
    $tok = m7_ready();
    $b = m1_build(U_PUBLISHER);
    $seq = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER)['json']['seq'];
    db()->prepare('UPDATE site_outbox SET expires = ? WHERE seq = ?')->execute([time() - 1, $seq]);
    eq([], array_values(array_filter(m7_pull('after=0', $tok)['json']['items'], fn($i) => $i['seq'] === $seq)), 'לא מוגשת');
    eq('expired', m7_row($seq)['state']);
    $err = json_decode((string)build_row($b['id'])['error'], true);
    eq('expired', $err['code'] ?? null);
    $o = m1_get('outbox', '', U_PUBLISHER)['json'];
    $mine = array_values(array_filter($o['outbox'], fn($x) => $x['seq'] === $seq))[0];
    ok(str_contains((string)$mine['reason'], 'שלח שוב'), (string)$mine['reason']);
    eq(403, m1_get('outbox', '', U_EDITOR)['status'], 'עורך אינו רואה את התור');
    $r = m1_post('outbox_resend', ['seq' => $seq], U_PUBLISHER);
    eq(200, $r['status'], $r['body']);
    $new = (int)$r['json']['outbox'][0]['seq'];
    ok($new > $seq, 'seq חדש');
    eq(['manifest', 'queued'], [$r['json']['outbox'][0]['action'], $r['json']['outbox'][0]['state']]);
    eq(409, m1_post('outbox_resend', ['seq' => $new], U_PUBLISHER)['status'], 'רק שנכשלה / פג תוקפה');
    // האתר מדווח expired (השעון שלו קדימה) — אותו מצב
    m7_pull('report', $tok, 'POST', ['results' => [['seq' => $new, 'state' => 'failed', 'code' => 'expired']], 'state' => []]);
    eq('expired', m7_row($new)['state']);
    m7_done();
});

test('M7 הדיווח: applied ⇒ הבנייה "באתר" רק עכשיו, ההודעות, הדופק; failed עם קוד ⇒ שגיאה בעברית; שדות זרים נזרקים', function () {
    $tok = m7_ready();
    $b = m1_build(U_PUBLISHER);
    $seq = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER)['json']['seq'];
    eq('sending', build_row($b['id'])['status']);
    $r = m7_pull('report', $tok, 'POST', ['results' => [['seq' => $seq, 'state' => 'applied'], ['seq' => 999999, 'state' => 'applied'], ['seq' => 'x', 'state' => 'applied']],
        'state' => ['plugin' => '2.2.0<script>', 'v2_live' => 42, 'v2_on' => true, 'v2_mount' => 'bar', 'evil' => 'x', 'v2_stats' => ['requests' => 3], 'cursor' => $seq]]);
    eq([200, [$seq]], [$r['status'], $r['json']['recorded']], $r['body']);
    eq('live', build_row($b['id'])['status'], 'באתר — אחרי applied');
    eq('applied', m7_row($seq)['state']);
    ok(m7_row($seq)['applied_at'] !== null);
    ok(str_contains((string)db()->query("SELECT detail FROM audit WHERE action = 'pkg_publish' ORDER BY id DESC")->fetchColumn(), "#$seq"), 'ביומן');
    $h = pull_heartbeat();
    eq(['2.2.0script', 42, true, 'bar', ['requests' => 3]], [$h['state']['plugin'], $h['state']['v2_live'], $h['state']['v2_on'], $h['state']['v2_mount'], $h['state']['v2_stats']], 'המצב — מסונן');
    ok(!isset($h['state']['evil']), 'שדה זר נזרק');
    $ping = site_call('ping', []);
    eq([true, 'pull', 42, true], [$ping['ok'], $ping['channel'], $ping['v2_live'], $ping['v2_on']], 'ping בערוץ המשיכה = הדופק');
    // דיווח חוזר על מה שכבר הוחל — אינו משנה דבר
    m7_pull('report', $tok, 'POST', ['results' => [['seq' => $seq, 'state' => 'failed', 'code' => 'boom']], 'state' => []]);
    eq('applied', m7_row($seq)['state']);
    // failed
    $b2 = m1_build(U_PUBLISHER);
    $s2 = m1_post('build_send', ['id' => $b2['id']], U_PUBLISHER)['json']['seq'];
    m7_pull('report', $tok, 'POST', ['results' => [['seq' => $s2, 'state' => 'failed', 'code' => 'blob_mismatch', 'message' => "x\x00y"]], 'state' => []]);
    eq('failed', m7_row($s2)['state']);
    $err = json_decode((string)build_row($b2['id'])['error'], true);
    eq('blob_mismatch', $err['code']);
    ok(str_contains($err['msg'], 'שלוש פעמים'), $err['msg']);
    eq(400, http('POST', '/pull.php?report', ['headers' => ["Authorization: Bearer $tok"], 'raw' => 'not json'])['status']);
    eq(413, http('POST', '/pull.php?report', ['headers' => ["Authorization: Bearer $tok"], 'raw' => str_repeat('x', PULL_REPORT_MAX + 10)])['status']);
    m7_done();
});

test('M7 הדופק: לא משך מעולם — אדום; לפני 3 דקות — ירוק; מעל 30 דקות — צהוב; מעל 24 שעות — אדום, ובבדיקות התקינות', function () {
    db()->exec("DELETE FROM settings WHERE k = 'site_heartbeat'");
    eq(['bad', true], [pull_pulse()['level'], pull_pulse()['never']]);
    $set = fn(int $ago) => setting_set('site_heartbeat', json_encode(['at' => time() - $ago, 'state' => ['plugin' => '2.2.0', 'v2_live' => 42, 'v2_on' => true]]));
    $set(180);
    $p = pull_pulse();
    eq('ok', $p['level']);
    eq('האתר משך לאחרונה לפני 3 דקות · תוסף 2.2.0 · באוויר: גרסה 42 · המפסק: דולק', $p['text']);
    $set(31 * 60);
    eq('warn', pull_pulse()['level']);
    $set(25 * 3600);
    eq('bad', pull_pulse()['level']);
    setting_set('pub_channel', 'pull');
    $h = array_values(array_filter(dev_health()['checks'] ?? dev_health(), fn($c) => ($c['id'] ?? '') === 'site'))[0] ?? null;
    ok($h && ($h['ok'] ?? true) === false, 'בדיקת התקינות: ' . json_encode($h, JSON_UNESCAPED_UNICODE));
    ok(str_contains(json_encode($h, JSON_UNESCAPED_UNICODE), 'האתר משך לאחרונה'), 'במילים');
    setting_set('pub_channel', 'push');
    db()->exec("DELETE FROM settings WHERE k = 'site_heartbeat'");
});

test('M7 גיבוי ששוחזר: האתר כבר החיל seq גבוה משלנו — המונה קופץ מעליו, ומה שממתין נחתם מחדש במספר חדש', function () {
    $tok = m7_ready();
    $r = m1_post('site_switch', ['enabled' => false], U_PUBLISHER);
    $seq = $r['json']['site']['seq'];
    $after = $seq + 1000;
    $items = m7_pull("after=$after", $tok)['json']['items'];
    eq(1, count($items), json_encode($items));
    ok($items[0]['seq'] > $after, 'מספר חדש מעל מה שהאתר החיל');
    eq('superseded', m7_row($seq)['state']);
    eq(false, json_decode(base64_decode($items[0]['body']), true)['enabled'], 'אותה פקודה');
    m7_done();
});

test('M7 קצה לקצה מול התוסף האמיתי: פרסום ⇒ משיכה ⇒ applied ⇒ העמוד מוגש מהגרסה החדשה ⇒ "✓ הוחל"; אופן ההצבה דרך התור', function () {
    $tok = m7_ready();
    m1_wp_opt('kol_v2_mock', null);
    m1_wp_opt('kol_wp_plugin_v1', null);
    foreach (['kol_maroko_v2_last', 'kol_maroko_v2_live', 'kol_maroko_last', 'kol_maroko_pull_cursor', 'kol_maroko_pull_unreported'] as $k) m1_wp_opt($k, null);
    m1_wp_opt('kol_maroko_pull_url', $GLOBALS['BASE'] . '/pull.php');
    m1_wp_opt('kol_maroko_pull_token', $tok);
    // התוסף מתחיל מאחרי מה שכבר בתור (בדיקות קודמות) — כמו תוסף שמותקן עכשיו ומקבל רק את מה שחדש
    $cur = (int)db()->query('SELECT COALESCE(MAX(seq), 0) FROM site_outbox')->fetchColumn();
    m1_wp_opt('kol_maroko_pull_cursor', $cur);
    $b = m1_build(U_PUBLISHER);
    $r = m1_post('build_send', ['id' => $b['id']], U_PUBLISHER);
    eq(200, $r['status'], $r['body']);
    $seq = $r['json']['seq'];
    $n = (int)build_row($b['id'])['version'];
    $save = $GLOBALS['BASE'];
    $GLOBALS['BASE'] = $GLOBALS['WP'];
    try { $run = http('GET', '/__pull'); } finally { $GLOBALS['BASE'] = $save; }
    $j = $run['json'];
    eq([true, 1, 0], [$j['ok'] ?? null, $j['applied'] ?? null, $j['failed'] ?? null], $run['body']);
    eq('applied', m7_row($seq)['state']);
    eq('live', build_row($b['id'])['status'], '"✓ הוחל" — הבנייה באתר');
    eq($n, (int)m1_wp_get('kol_maroko_v2_live'), 'התוסף האמיתי: באוויר');
    eq($seq, (int)m1_wp_get('kol_maroko_pull_cursor'));
    $man = json_decode((string)file_get_contents($GLOBALS['WP_DIR'] . '/uploads/kol-maroko/v2/live.json'), true);
    eq([$n, $seq], [$man['version'], $man['seq']], 'live.json — המניפסט שנמשך');
    foreach (json_decode(build_row($b['id'])['files'], true) as $p => $f) ok(kol_test_wp_blob($f['sha']), "הגוש של $p באתר");
    $o = m1_get('outbox', '', U_PUBLISHER)['json'];
    eq('applied', $o['outbox'][0]['state']);
    eq('ok', $o['pulse']['level'], 'הדופק');
    ok(str_contains($o['pulse']['text'], 'תוסף ' . KOL_PLUGIN_EXPECTED) && str_contains($o['pulse']['text'], "באוויר: גרסה $n"), $o['pulse']['text']);
    // אופן ההצבה — בתור, ונשמר בכלי רק אחרי applied
    setting_set('site_mount', 'full');
    $m = m1_post('mount_set', ['mount' => 'framed'], U_SITE);
    eq([200, true, 'full'], [$m['status'], $m['json']['queued'] ?? null, $m['json']['mount'] ?? null], $m['body']);
    $GLOBALS['BASE'] = $GLOBALS['WP'];
    try { $run = http('GET', '/__pull'); } finally { $GLOBALS['BASE'] = $save; }
    eq(1, $run['json']['applied'] ?? null, $run['body']);
    eq('framed', setting('site_mount'), 'נשמר אחרי applied');
    eq('framed', m1_wp_get('kol_maroko_v2_mount'), 'באתר');
    // ריצה נוספת — אין חדש, ושום דבר לא מוחל שוב
    $GLOBALS['BASE'] = $GLOBALS['WP'];
    try { $run = http('GET', '/__pull'); } finally { $GLOBALS['BASE'] = $save; }
    eq([0, 0], [$run['json']['applied'], $run['json']['failed']]);
    m1_wp_opt('kol_maroko_pull_token', null);
    m1_wp_opt('kol_maroko_pull_url', null);
    m7_done();
});

/** הגוש בתוסף האמיתי (בשני השמות האפשריים). */
function kol_test_wp_blob(string $sha): bool
{
    return (bool)glob($GLOBALS['WP_DIR'] . "/uploads/kol-maroko/v2/blobs/$sha*");
}
