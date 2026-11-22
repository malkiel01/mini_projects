<?php
/**
 * פרוסה 05 — הסנכרון (docs/spec/05-readers-sync.md §4–§5, §7): קריטריונים 4–9 בשרת (lib/sync.php, על המסד), וגם
 * הלוגיקה במכשיר (assets/js/sync-core.js) ב-node — tests/sync-core.mjs. ב-K8 הפריטים שמכשיר אורח שולח נבנים ב-node
 * ונשלחים כאן לשרת האמיתי.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$GLOBALS['sf_mail_out'] = [];
rcall('POST', 'account-register', ['email' => 'rina@example.com', 'password' => 'rina-rina-1'], 'A');
rcall('POST', 'account-login', ['email' => 'rina@example.com', 'password' => 'rina-rina-1'], 'B');
rcall('POST', 'account-register', ['email' => 'other@example.com', 'password' => 'other-other-1'], 'X');
$ED = 'abcdefghijklmnop';
$push = fn(string $dev, array $items) => rcall('POST', 'sync-push', ['items' => $items], $dev);
$pull = fn(string $dev, int $since = 0) => rcall('GET', 'sync-pull', [], $dev, ['since' => (string)$since])[1];
$byPid = fn(array $pulled) => array_column($pulled['items'] ?? [], null, 'pid');
$st = fn(array $res) => array_column($res[1]['results'] ?? [], 'status');

eq(code(rcall('GET', 'sync-pull', [], 'nobody')), 'reader_login', 'בלי חשבון — אין סנכרון');

// ── K4: סימניה במכשיר א' ← במכשיר ב'; עריכה במכשיר ב' (בלי רשת) נשלחת בחזרה ──
$bm = ['pid' => 'm:a1', 'kind' => 'bookmark', 'ref' => $ED, 'data' => ['id' => 'a1', 'edition' => $ED, 'sid' => 's1', 'type' => 'bookmark']];
eq($st($push('A', [$bm])), ['new'], 'K4 מכשיר א\' שולח סימניה');
$b = $byPid($pull('B'));
eq($b['m:a1']['data']['sid'] ?? null, 's1', 'K4 מכשיר ב\' מקבל אותה');
$rev1 = (int)$b['m:a1']['rev'];
$cursorB = (int)$pull('B')['cursor'];
// ב' "בלי רשת": השינוי מחכה בתור (ב-node), ונשלח בחזרת חיבור עם ה-rev שהכיר
$edit = ['base' => $rev1, 'data' => ['note' => 'נכתב בלי רשת'] + $bm['data']] + $bm;
eq($st($push('B', [$edit])), ['saved'], 'K4 בחזרת חיבור — העריכה של ב\' נשמרת');
$a = $byPid($pull('A', $rev1));
eq($a['m:a1']['data']['note'] ?? null, 'נכתב בלי רשת', 'K4 …ומגיעה למכשיר א\' (רק מה שהשתנה מאז)');
check('K4 pull מה-cursor מחזיר רק את החדש', count($pull('A', (int)$a['m:a1']['rev'])['items']) === 0);
// סימניות שונות משני מכשירים מתמזגות
$push('A', [['pid' => 'm:x9', 'kind' => 'bookmark', 'ref' => $ED, 'data' => ['id' => 'x9']]]);
$push('B', [['pid' => 'm:y8', 'kind' => 'bookmark', 'ref' => $ED, 'data' => ['id' => 'y8']]]);
$all = array_keys($byPid($pull('A')));
sort($all);
eq($all, ['m:a1', 'm:x9', 'm:y8'], 'K4 שתי סימניות שונות משני מכשירים — שתיהן נשמרות');
$p = rcall('GET', 'sync-pull', [], 'A', ['since' => 'x'])[1];
eq(count($p['items']), 3, 'since לא מספר — מההתחלה');

// ── K5: מחיקה = tombstone; מכשיר שהיה מנותק לא מחזיר ───────
$cur = (int)$byPid($pull('A'))['m:x9']['rev'];
eq($st($push('A', [['pid' => 'm:x9', 'kind' => 'bookmark', 'ref' => $ED, 'deleted' => true, 'base' => $cur]])), ['saved'], 'K5 מחיקה במכשיר א\'');
$t = $byPid($pull('B', $cursorB))['m:x9'] ?? [];
eq([$t['deleted'] ?? null, array_key_exists('data', $t) ? $t['data'] : 'x'], [true, null], 'K5 מכשיר ב\' מקבל tombstone (בלי התוכן)');
check('K5 במסד: השורה נשארת עם deleted_at (לא DELETE)', sf_val("SELECT deleted_at FROM personal_items WHERE pid = 'm:x9'") !== null);
eq($st($push('B', [['pid' => 'm:x9', 'kind' => 'bookmark', 'ref' => $ED, 'data' => ['id' => 'x9', 'color' => 'ישן'], 'base' => $cur]])), ['gone'], 'K5 עריכה של ב\' שמבוססת על לפני המחיקה — נדחית');
eq($st($push('B', [['pid' => 'm:x9', 'kind' => 'bookmark', 'ref' => $ED, 'data' => ['id' => 'x9']]])), ['gone'], 'K5 …גם בלי base (מכשיר שמעולם לא ראה את ה-rev)');
eq($byPid($pull('A'))['m:x9']['deleted'] ?? null, true, 'K5 הפריט לא חזר');
eq($st($push('A', [['pid' => 'm:x9', 'kind' => 'bookmark', 'ref' => $ED, 'deleted' => true]])), ['same'], 'K5 מחיקה חוזרת — אידמפוטנטית');

// ── K6: אותו שינוי פעמיים ← פריט אחד ────────────────────────
$fav = ['pid' => 'f:z1', 'kind' => 'favorite', 'ref' => $ED, 'data' => ['id' => 'z1', 'name' => 'עלינו']];
eq($st($push('A', [$fav])), ['new'], 'K6 פעם ראשונה — חדש');
$r1 = (int)$byPid($pull('A'))['f:z1']['rev'];
eq($st($push('A', [$fav])), ['same'], 'K6 פעם שנייה — "same"');
eq($st($push('B', [$fav, $fav])), ['same', 'same'], 'K6 …גם ממכשיר אחר, גם פעמיים באותה מנה');
eq((int)sf_val("SELECT COUNT(*) FROM personal_items WHERE pid = 'f:z1'"), 1, 'K6 שורה אחת במסד');
eq((int)$byPid($pull('A'))['f:z1']['rev'], $r1, 'K6 ה-rev לא עלה (אין "שינוי" למכשירים האחרים)');

// ── K7: שעון מכשיר מקודם בשנה לא גובר על שינוי חדש יותר בשרת ──
$GLOBALS['sf_time'] = time();
$push('A', [['pid' => 'pref:settings', 'kind' => 'pref', 'data' => ['theme' => 'dark', 'updated_at' => gmdate('c', time() + 365 * 86400)], 'base' => 99999]]);
$GLOBALS['sf_time'] = time() + 60;
$push('B', [['pid' => 'pref:settings', 'kind' => 'pref', 'data' => ['theme' => 'sepia', 'updated_at' => gmdate('c')]]]);
unset($GLOBALS['sf_time']);
$s = $byPid($pull('A'))['pref:settings'];
eq($s['data']['theme'] ?? null, 'sepia', 'K7 השינוי האחרון שהגיע לשרת קובע — לא התאריך "מהעתיד" של המכשיר');
check('K7 updated_at — שעת השרת, לא של המכשיר', $s['updated_at'] <= sf_iso(time() + 120));
check('K7 base מנופח לא נותן עדיפות', (int)$s['rev'] < 99999);

// ── K9: קורא א' לא מקבל, לא משנה ולא מוחק פריט של קורא ב' ──
$xPull = $pull('X');
eq($xPull['items'] ?? null, [], 'K9 קורא אחר — לא מקבל אף פריט');
eq($st($push('X', [['pid' => 'm:a1', 'kind' => 'bookmark', 'ref' => $ED, 'data' => ['id' => 'a1', 'sid' => 'hacked']]])), ['new'], 'K9 אותו pid אצל קורא אחר — פריט חדש שלו');
eq($st($push('X', [['pid' => 'f:z1', 'kind' => 'favorite', 'ref' => $ED, 'deleted' => true, 'base' => 1000]])), ['new'], 'K9 "מחיקה" של pid של אחר — tombstone אצלו בלבד');
$a = $byPid($pull('A'));
eq([$a['m:a1']['data']['sid'] ?? null, $a['f:z1']['deleted'] ?? null], ['s1', false], 'K9 הפריטים של רינה לא השתנו ולא נמחקו');
eq(array_keys($byPid($pull('X'))), ['m:a1', 'f:z1'], 'K9 קורא א\' רואה רק את שלו');
eq((int)sf_val("SELECT COUNT(DISTINCT reader_id) FROM personal_items WHERE pid = 'm:a1'"), 2, 'K9 שתי שורות נפרדות במסד');
check('K9 כל שאילתה ב-sync.php מסוננת לפי reader_id', (function () {
    $src = (string)file_get_contents(dirname(__DIR__) . '/lib/sync.php');
    preg_match_all("/'(SELECT|UPDATE|DELETE)[^']*personal_items[^']*'/", $src, $m);
    return $m[0] && count(array_filter($m[0], fn($q) => !str_contains($q, 'reader_id = ?') && !str_contains($q, 'WHERE id = ?'))) === 0;
})());

// ── בדיקת קלט ─────────────────────────────────────────────
eq($st($push('A', [['pid' => '../etc', 'kind' => 'bookmark', 'data' => []], ['pid' => 'ok1', 'kind' => 'virus', 'data' => []],
    ['pid' => 'ok2', 'kind' => 'note', 'data' => str_repeat('x', SF_SYNC_ITEM_MAX + 1)], ['pid' => 'ok3', 'kind' => 'note']])), ['bad', 'bad', 'bad', 'bad'],
    'פריט פגום (pid, kind, גודל, בלי data) — "bad", לא מפיל את המנה');
eq($st($push('A', [['pid' => 'pref:settings', 'kind' => 'note', 'data' => ['x' => 1]]])), ['bad'], 'שינוי סוג של פריט קיים — נדחה');
eq(code($push('A', array_fill(0, SF_SYNC_BATCH + 1, $fav))), 'too_large', 'יותר מ-500 במנה — 413');
eq(code(rcall('POST', 'sync-push', ['items' => 'x'], 'A')), 'bad_input', 'items שאינו רשימה — 400');
eq(code(rcall('POST', 'sync-push', ['items' => []], 'A', [], ['csrf' => ''])), 'csrf', 'בלי X-Reader-CSRF — 403');

// ── K8: מיזוג אורח — הפריטים נבנים ב-node (sync-core.js) ונשלחים לשרת האמיתי ──
exec('command -v node 2>/dev/null', $nodeOut, $nodeCode);
check('node מותקן (נדרש לבדיקת sync-core.js; בשרת עצמו אין צורך)', $nodeCode === 0);
if ($nodeCode === 0) {
    exec('node ' . escapeshellarg(__DIR__ . '/sync-core.mjs') . ' 2>&1', $out, $code);
    $res = json_decode(implode("\n", $out), true);
    eq($code, 0, 'sync-core.mjs רץ' . ($code ? ': ' . implode(' | ', array_slice($out, -5)) : ''));
    foreach (($res['fails'] ?? ['לא רץ']) as $f) check("sync-core: $f", false);
    echo '  (sync-core.mjs: ' . ($res['n'] ?? 0) . " בדיקות)\n";

    $tmp = SF_DATA_DIR . '/guest.json';
    file_put_contents($tmp, json_encode([
        'settings' => ['theme' => 'light', 'font' => 1.4],
        'marks' => [['id' => 'g1', 'edition' => $ED, 'sid' => 's5', 'type' => 'note', 'note' => 'הערה של אורח', 'created_at' => '2026-11-20T10:00:00Z']],
        'favorites' => [['id' => 'g2', 'edition' => $ED, 'sid' => 's6', 'name' => 'קדיש', 'created_at' => '2026-11-20T10:00:00Z']],
        'tehillim' => ['done' => [], 'group' => [], 'names' => [['id' => 'g3', 'name' => 'פלונית בת פלונית', 'note' => 'לרפואת', 'created_at' => '2026-11-20T10:00:00Z']]],
        'seen:local' => true,
    ], JSON_UNESCAPED_UNICODE));
    $items = json_decode((string)shell_exec('node ' . escapeshellarg(__DIR__ . '/sync-core.mjs') . ' payload ' . escapeshellarg($tmp)), true) ?: [];
    eq(count($items), 5, 'K8 "למזג" — 5 פריטים (הגדרות, הערה, מועדף, רשימת תהילים, שם) — בלי seen:local');
    check('K8 כולם create_only', $items && !array_filter($items, fn($i) => empty($i['create_only'])));
    rcall('POST', 'account-login', ['email' => 'rina@example.com', 'password' => 'rina-rina-1'], 'G');
    $before = (int)sf_val("SELECT COUNT(*) FROM personal_items p JOIN readers r ON r.id = p.reader_id WHERE r.email = 'rina@example.com'");
    $r = $push('G', $items);
    $c = array_count_values($st($r));
    eq([$c['new'] ?? 0, $c['kept'] ?? 0], [4, 1], 'K8 ארבעה חדשים; ההגדרות כבר היו בחשבון — "kept"');
    eq($byPid($pull('G'))['pref:settings']['data']['theme'] ?? null, 'sepia', 'K8 ההגדרה של החשבון קובעת');
    $push('G', $items);
    eq((int)sf_val("SELECT COUNT(*) FROM personal_items p JOIN readers r ON r.id = p.reader_id WHERE r.email = 'rina@example.com'"), $before + 4, 'K8 מיזוג חוזר — בלי כפילויות');
    eq($byPid($pull('G'))['m:g1']['data']['note'] ?? null, 'הערה של אורח', 'K8 הערת האורח בחשבון');
    check('K8 …מוצפנת במסד', str_starts_with((string)sf_val("SELECT data FROM personal_items WHERE pid = 'm:g1'"), 's1:'));
    // "להשאיר רק במכשיר" — sync.js לא שולח דבר: אין קריאה ל-sync-push בנתיב הזה
    $js = (string)file_get_contents(dirname(__DIR__) . '/assets/js/sync.js');
    check('K8 "להשאיר" — הנתיב לא בונה תור (outbox ריק, המידע נשמר ב-guest:stash)', preg_match("/choice === 'keep'\\) \\{\\s*await SF\\.store\\.setRaw\\('guest:stash', kv\\);\\s*await wipeLocal\\(\\);/", $js) === 1
        && preg_match("/outbox = \\{\\};\\s*if \\(choice === 'merge'\\) outbox = C\\.guestOutbox\\(kv\\);/", $js) === 1);
}

// ── מכשיר שבוטל — הסנכרון אליו נעצר ────────────────────────
$devs = rcall('GET', 'account-devices', [], 'A')[1]['devices'];
$bDev = array_values(array_filter($devs, fn($d) => !$d['current']));
foreach ($bDev as $d) rcall('POST', 'account-device-revoke', ['device' => $d['pid']], 'A');
eq(code(rcall('GET', 'sync-pull', [], 'B')), 'reader_login', 'מכשיר שבוטל — הסנכרון אליו נעצר (401)');
eq(code($push('B', [$fav])), 'reader_login', '…וגם השליחה');

done();
