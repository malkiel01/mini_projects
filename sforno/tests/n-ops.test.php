<?php
/**
 * פרוסה 10 — ניהול תפעולי (docs/spec/10-operations.md §5, "N<מספר> …"), ומה שהצטבר לפרוסה בביקורות:
 *   ניקוי חשבונות שנמחקו אחרי 30 יום (05), הגיבוי כולל secret.key / vapid.json / woo.json ולא נגיש ולא בגיט (05, 08, 09),
 *   והקשחות 09: הצמדת מארח החנות לכתובת ציבורית, הגבלת קצב ל-webhook לפני הגוף, store-out בודק purchasable.
 *   👁 N5 (שחזור ידני לפי dev/restore.md) — אצל הבעלים (SF-010).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$ok = function (array $r, string $name): array {
    eq($r[0], 200, $name . ($r[0] !== 200 ? ' — ' . json_encode($r[1]['error'] ?? $GLOBALS['t_last_500'] ?? null, JSON_UNESCAPED_UNICODE) : ''));
    return $r[1];
};
$GLOBALS['sf_time'] = strtotime('2026-11-22T12:00:00Z');
$GLOBALS['sf_mail_out'] = [];
$later = function (int $sec) { $GLOBALS['sf_time'] += $sec; };

$DEV = ident('801', 'מפתח', 'dev@example.test', ['platform_admin' => true]);
$STAFF = ident('802', 'מנהלת', 'staff@example.test');
call('GET', 'me', [], $DEV);
call('GET', 'me', [], $STAFF);
$sample = $ok(call('POST', 'content-sample', [], $DEV), 'content-sample');
$ED = $sample['siddur'];
$rv = $ok(call('GET', 'read-revision', [], null, ['edition' => $ED]), 'read-revision');
$REV = $rv['revision']['pid'];
$SID = $rv['toc'][1]['sid'];
$TOP = $rv['toc'][0]['sid'];

// ── הרשאות ─────────────────────────────────────────────────
foreach (['ops-dashboard', 'ops-metrics', 'ops-reader', 'reports-list', 'report-get'] as $a) {
    eq(code(call('GET', $a, [], null)), 'login_required', "$a — רק מנהל");
}
eq(code(call('GET', 'dev-backups', [], $STAFF)), 'forbidden', 'dev-backups — רק מפתח');
foreach (['dev-backup-download', 'dev-backup-now', 'dev-backup-verify', 'dev-ops-set'] as $a) eq(code(call('POST', $a, [], $STAFF)), 'forbidden', "$a — רק מפתח");
check('פעולות התפעול לא ב-public של app.json', !preg_match('/report-create|metrics-open|ops-|backup|read-support/', implode(' ', sf_app_json()['public'])));

// ── N1: דיווח מתוך קטע — pid, revision, sid; בלי הערה, שם לתפילה או מייל ──
$GLOBALS['sf_mail_out'] = [];
rcall('POST', 'account-register', ['email' => 'rina@example.com', 'password' => 'rina-rina-1'], 'R');
$NOTE = 'הערה-סודית-' . bin2hex(random_bytes(3));
$PRAYER = 'שם-לתפילה-' . bin2hex(random_bytes(3));
$ok(rcall('POST', 'sync-push', ['items' => [
    ['pid' => 'n1', 'kind' => 'note', 'ref' => $ED, 'data' => ['note' => $NOTE, 'sid' => $SID]],
    ['pid' => 'pn1', 'kind' => 'prayer_name', 'data' => ['name' => $PRAYER]],
    ['pid' => 'b1', 'kind' => 'bookmark', 'ref' => $ED, 'data' => ['sid' => $SID]],
]], 'R'), 'sync-push');
// קורא מחובר שולח דיווח — ובכוונה מצרף שדות אסורים: הם לא נשמרים
$rep = $ok(rcall('POST', 'report-create', ['kind' => 'content', 'category' => 'typo', 'edition' => $ED, 'revision' => $REV, 'sid' => $SID,
    'text' => 'חסר ניקוד', 'note' => $NOTE, 'prayer_name' => $PRAYER, 'email' => 'rina@example.com', 'reader' => 'x'], 'R'), 'N1 report-create');
$row = sf_row('SELECT * FROM reports WHERE pid = ?', [$rep['report']]);
eq([$row['edition_pid'], $row['revision_pid'], $row['sid'], $row['category'], $row['body']], [$ED, $REV, $SID, 'typo', 'חסר ניקוד'], 'N1 הדיווח מכיל edition, revision ו-sid');
$all = json_encode($row, JSON_UNESCAPED_UNICODE);
check('N1 …ולא הערה, שם לתפילה או מייל (גם כשנשלחו, וגם כשהקורא מחובר)', !str_contains($all, $NOTE) && !str_contains($all, $PRAYER) && !str_contains($all, 'rina@'));
eq(array_keys($row), ['id', 'pid', 'kind', 'category', 'edition_pid', 'revision_pid', 'sid', 'body', 'contact', 'status', 'staff_note', 'created_at', 'updated_at', 'closed_at', 'notified_at'],
    'N1 אין בטבלה עמודה לקורא, ל-IP או למייל');
// "לחזרה אליי" — רק מה שהמשתמש כתב, ומוצפן
$rep2 = $ok(rcall('POST', 'report-create', ['kind' => 'content', 'category' => 'wrong', 'edition' => $ED, 'sid' => $SID, 'contact' => 'call-me-0501234567'], 'G1'), 'N1 אורח עם "לחזרה אליי"');
$c = (string)sf_val('SELECT contact FROM reports WHERE pid = ?', [$rep2['report']]);
check('N1 "לחזרה אליי" מוצפן במסד', str_starts_with($c, 's1:') && !str_contains($c, '0501234567'));
eq($ok(call('GET', 'report-get', [], $STAFF, ['report' => $rep2['report']]), 'report-get')['report']['contact'], 'call-me-0501234567', 'N1 המנהל רואה את "לחזרה אליי"');
// הצד של הלקוח: הקריאה ל-report-create שולחת רק את השדות שברשימה
$js = (string)file_get_contents("$root/assets/js/screens/ops.js");
preg_match_all("/SF\\.api\\('report-create', \\{ body: \\{([^}]*)\\}/", $js, $m);
$keys = array_unique(array_merge(...array_map(fn($b) => array_map(fn($p) => trim(explode(':', $p)[0]), explode(',', $b)), $m[1])));
sort($keys);
eq($keys, ['category', 'contact', 'edition', 'kind', 'revision', 'sid', 'text'], 'N1 ops.js שולח בדיווח רק kind/category/edition/revision/sid/text/contact');
check('N1 הקורא מעביר לדיווח רק מזהים ומיקום', (bool)preg_match("/SF\\.reportSheet\\(\\{ edition, revision: shown, sid: here, where: [^}]*\\}\\)/", (string)file_get_contents("$root/assets/js/screens/reader.js")));
// קלט לא תקין
eq(code(rcall('POST', 'report-create', ['kind' => 'content', 'category' => 'typo', 'edition' => $ED, 'sid' => '../x'], 'G1')), 'bad_input', 'N1 sid לא תקין — נדחה');
eq(code(rcall('POST', 'report-create', ['kind' => 'content', 'category' => 'typo', 'edition' => $ED, 'revision' => $REV, 'sid' => 'zzzzzzzz'], 'G1')), 'not_found', 'N1 sid שלא בגרסה — נדחה');
eq(code(rcall('POST', 'report-create', ['kind' => 'content', 'category' => 'hack', 'edition' => $ED, 'sid' => $SID], 'G1')), 'bad_input', 'N1 קטגוריה לא מוכרת');
eq(code(rcall('POST', 'report-create', ['kind' => 'contact', 'category' => 'question', 'text' => '  '], 'G1')), 'bad_input', 'N1 פנייה ריקה');
eq(code(rcall('POST', 'report-create', ['kind' => 'contact', 'category' => 'question', 'text' => 'שלום'], 'G1', [], ['sec_fetch_site' => 'cross-site'])), 'csrf', 'N1 מאתר אחר — נחסם (sf_same_site)');
eq(code(rcall('POST', 'report-create', ['kind' => 'contact', 'category' => 'question', 'text' => 'שלום'], 'G1', [], ['content_type' => 'text/plain'])), 'content_type', 'N1 בלי application/json — נחסם');
$n = 0;
for ($i = 0; $i < 12; $i++) if (code(rcall('POST', 'report-create', ['kind' => 'contact', 'category' => 'idea', 'text' => "הצעה $i"], 'G2', [], ['ip' => '198.51.100.9'])) === 'rate') $n++;
check('N1 עד 10 דיווחים בשעה לכתובת IP', $n === 2, $n);

// התראה אחת למייל התמיכה, בלי מה שנכתב
eq(code(call('POST', 'dev-ops-set', ['email' => 'not-an-email'], $DEV)), 'bad_input', 'מייל תמיכה לא תקין');
$ok(call('POST', 'dev-ops-set', ['email' => 'support@example.test', 'whatsapp' => '+972 50-123-4567'], $DEV), 'dev-ops-set');
eq($ok(call('GET', 'read-support'), 'read-support')['whatsapp'], '972501234567', 'read-support — וואטסאפ בספרות');
$GLOBALS['sf_mail_out'] = [];
$rep3 = $ok(rcall('POST', 'report-create', ['kind' => 'contact', 'category' => 'tech', 'text' => 'טקסט-פרטי-של-הפונה', 'contact' => 'phone-0509999999'], 'G3'), 'פנייה');
$later(60);
sf_jobs_run(10);
sf_jobs_run(10);
$mails = array_values(array_filter($GLOBALS['sf_mail_out'], fn($x) => $x['to'] === 'support@example.test' && str_contains($x['body'], $rep3['report'])));
eq(count($mails), 1, 'N התראה אחת למייל התמיכה — גם אחרי שני סבבים');
check('N …בלי מה שנכתב ובלי "לחזרה אליי", עם קישור', isset($mails[0]) && !str_contains($mails[0]['body'], 'טקסט-פרטי') && !str_contains($mails[0]['body'], '0509999999') && str_contains($mails[0]['body'], $rep3['report']));

// הניהול: רשימה, מצב, קפיצה לעורך
$l = $ok(call('GET', 'reports-list', [], $STAFF), 'reports-list');
check('רשימת הדיווחים — פתוחים', count($l['reports']) >= 3 && !array_key_exists('contact', $l['reports'][0]));
$g = $ok(call('GET', 'report-get', [], $STAFF, ['report' => $rep['report']]), 'report-get')['report'];
eq([$g['where']['editor_href'], $g['where']['reader_href']], ["#/admin/content/e/$ED", "#/r/$ED/$SID"], 'קפיצה לקטע בעורך ובקורא');
$s = $ok(call('POST', 'report-set', ['report' => $rep['report'], 'status' => 'working', 'note' => 'בודקים'], $STAFF), 'report-set working')['report'];
eq([$s['status'], $s['staff_note']], ['working', 'בודקים'], 'מצב "בטיפול" והערה פנימית');
$s = $ok(call('POST', 'report-set', ['report' => $rep['report'], 'status' => 'closed'], $STAFF), 'report-set closed')['report'];
check('נסגר — עם תאריך', $s['status'] === 'closed' && $s['closed_at'] !== null);
eq(code(call('POST', 'report-set', ['report' => $rep['report'], 'status' => 'gone'], $STAFF)), 'bad_input', 'מצב לא מוכר');
check('שינוי מצב — ביומן הפעולות', audit_count('report.status') === 2);

// ── N7: המדדים — בלי טקסט חופשי ─────────────────────────────
$ok(rcall('POST', 'metrics-open', ['edition' => $ED, 'sec' => $TOP, 'anon' => str_repeat('a1', 10)], 'G4'), 'metrics-open אורח');
rcall('POST', 'metrics-open', ['edition' => $ED, 'sec' => $TOP, 'anon' => str_repeat('a1', 10)], 'G4');
$ok(rcall('POST', 'metrics-open', ['edition' => $ED, 'anon' => str_repeat('b2', 10)], 'G5'), 'metrics-open אורח אחר');
$ok(rcall('POST', 'metrics-open', ['edition' => $ED, 'sec' => $TOP], 'R'), 'metrics-open קורא');
eq(code(rcall('POST', 'metrics-open', ['edition' => $ED, 'sec' => 'שלום עולם'], 'G4')), 'bad_input', 'N7 sec שאינו sid — נדחה');
eq(code(rcall('POST', 'metrics-open', ['edition' => $ED, 'anon' => 'טקסט חופשי'], 'G4')), 'bad_input', 'N7 anon שאינו מזהה — נדחה');
rcall('POST', 'metrics-open', ['edition' => $ED, 'sec' => 'zz9zz9zz'], 'G4');   // sid תקין שלא קיים — לא נספר כמקטע
$today = gmdate('Y-m-d', sf_time());
$mv = fn(string $kind, string $key = '') => (int)sf_val('SELECT n FROM metrics_daily WHERE day = ? AND kind = ? AND mkey = ?', [$today, $kind, $key]);
eq([$mv('open', $ED), $mv('open_sec', "$ED:$TOP"), $mv('guest')], [5, 3, 2], 'N7 פתיחות לפי כותר ומקטע עליון; אורח נספר פעם ביום לכל מזהה');
check('N7 קורא שנכנס/סנכרן היום — נספר פעם אחת', $mv('reader') === 1 && $mv('login') >= 1);
$bad = [];
foreach (sf_rows('SELECT kind, mkey FROM metrics_daily') as $r) if (!in_array($r['kind'], SF_METRIC_KINDS, true) || !preg_match(SF_METRIC_KEY, $r['mkey'])) $bad[] = $r;
eq($bad, [], 'N7 כל מפתח במדדים הוא מזהה (pid / sid) — בלי טקסט');
$threw = false;
try { sf_metric_add('open', 'טקסט חופשי'); } catch (LogicException) { $threw = true; }
check('N7 sf_metric_add דוחה טקסט', $threw);
$m = $ok(call('GET', 'ops-metrics', [], $STAFF, ['days' => '7']), 'ops-metrics');
$mj = json_encode($m, JSON_UNESCAPED_UNICODE);
check('N7 מסך המדדים — בלי הערות, שמות, מיילים או טקסט של דיווח', !str_contains($mj, $NOTE) && !str_contains($mj, $PRAYER) && !str_contains($mj, 'rina@') && !str_contains($mj, 'חסר ניקוד'));
eq([$m['titles'][0]['edition'] ?? null, $m['sections'][0]['sid'] ?? null], [$ED, $TOP], 'N7 הכותר והמקטע העליון המובילים');

// ── N2: מסך התמיכה למשתמש — בלי הערות ושמות לתפילה ──────────
$r = $ok(call('GET', 'ops-reader', [], $STAFF, ['q' => 'RINA@example.com']), 'N2 ops-reader');
$rj = json_encode($r, JSON_UNESCAPED_UNICODE);
check('N2 לא מציג הערות ושמות לתפילה', !str_contains($rj, $NOTE) && !str_contains($rj, $PRAYER));
check('N2 …אבל כן כניסות, מכשירים, מצב סנכרון', count($r['devices']) === 1 && ($r['sync']['kinds']->note ?? $r['sync']['kinds']['note'] ?? 0) === 1 && ($r['activity'][0]['login'] ?? 0) >= 1 && $r['sync']['rev'] > 0);
eq(code(call('GET', 'ops-reader', [], $STAFF, ['q' => 'nobody@example.com'])), 'not_found', 'N2 קורא שלא קיים');
check('N2 הצפייה ביומן הפעולות', audit_count('reader.support_view') === 1);
// שגיאת סנכרון נספרת לתמיכה
rcall('POST', 'sync-push', ['items' => [['pid' => 'x', 'kind' => 'nope']]], 'R');
eq((int)sf_val("SELECT n FROM reader_activity WHERE kind = 'sync_err'"), 1, 'N2 שגיאת סנכרון — בפעילות של הקורא');

// ── N6: כשל בחנות, במייל ובתזכורות — בלוח הבקרה ─────────────
$d = $ok(call('GET', 'ops-dashboard', [], $STAFF), 'ops-dashboard');
$areas = array_column($d['alerts'], 'area');
check('N6 לפני כשל — אין התראה על חנות/מייל/תזכורות', !array_intersect($areas, ['store', 'mail', 'reminders']), $areas);
sf_insert('store_sync_runs', ['pid' => sf_pid(), 'kind' => 'delta', 'source' => 'woo', 'status' => 'failed', 'stage' => 'products', 'error' => 'החנות דחתה את המפתח (401/403)',
    'started_at' => sf_now(), 'updated_at' => sf_now(), 'finished_at' => sf_now()]);
$GLOBALS['sf_mail_fail'] = true;
sf_reader_mail('x@example.com', 'בדיקה', 'גוף');
unset($GLOBALS['sf_mail_fail']);
sf_insert('reminder_occurrences', ['owner' => 'r:1', 'reminder' => 'r1', 'fire_at' => sf_now(), 'channel' => 'push', 'dedupe_key' => 'k1', 'status' => 'failed', 'created_at' => sf_now(), 'updated_at' => sf_now()]);
sf_rem_log((int)sf_val("SELECT id FROM reminder_occurrences WHERE dedupe_key = 'k1'"), 'push', 'failed', 500);
$d = $ok(call('GET', 'ops-dashboard', [], $STAFF), 'ops-dashboard אחרי כשלים');
$areas = array_column($d['alerts'], 'area');
check('N6 כשל בסנכרון החנות — בלוח הבקרה', in_array('store', $areas, true), $areas);
check('N6 כשל במייל — בלוח הבקרה', in_array('mail', $areas, true), $areas);
check('N6 כשל בתזכורות — בלוח הבקרה', in_array('reminders', $areas, true), $areas);
check('לוח הבקרה: מה פורסם, דיווחים פתוחים, גיבוי, בדיקות', count($d['published']) >= 2 && isset($d['reports']['counts']) && array_key_exists('backup', $d) && isset($d['health']['state']));
$later(86400);
sf_ops_rollup(gmdate('Y-m-d', sf_time() - 86400));
eq([(int)sf_val("SELECT n FROM metrics_daily WHERE kind = 'rem_fail'"), (int)sf_val("SELECT n FROM metrics_daily WHERE kind = 'store_err'")], [1, 1], 'סיכום לילי: תזכורות שנכשלו, שגיאות חנות');

// ── N3: גיבוי יומי נוצר; מעל 30 יום נמחק ────────────────────
foreach (['vapid.json' => '{"k":1}', 'woo.json' => '{"url":"https://shop.test"}'] as $f => $v) file_put_contents(SF_DATA_DIR . "/$f", $v);
@mkdir(SF_DATA_DIR . '/sources/abc', 0700, true);
file_put_contents(SF_DATA_DIR . '/sources/abc/original.docx', 'DOCX');
sf_jobs_run(20);
$b1 = sf_row("SELECT * FROM backups WHERE kind = 'daily' ORDER BY id DESC LIMIT 1");
check('N3 הגיבוי היומי נוצר במשימת הרקע', $b1 !== null && is_file(SF_DATA_DIR . '/backups/' . $b1['file']));
check('N3 שם הקובץ והרשאות 0600', (bool)preg_match(SF_BACKUP_NAME, (string)$b1['file']) && (fileperms(SF_DATA_DIR . '/backups/' . $b1['file']) & 0777) === 0600);
$zip = new ZipArchive();
$zip->open(SF_DATA_DIR . '/backups/' . $b1['file']);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
sort($names);
foreach (['sforno.sqlite', 'secret.key', 'vapid.json', 'woo.json', 'manifest.json', 'sources/abc/original.docx'] as $want) check("N3 בגיבוי: $want", in_array($want, $names, true), $names);
check('N3 secret.key בגיבוי — זהה למפתח', $zip->getFromName('secret.key') === file_get_contents(SF_DATA_DIR . '/secret.key'));
$zip->close();
check('N3 data/backups/ חסומה מהדפדפן (data/.htaccess, וגם משלה)', str_contains((string)file_get_contents("$root/data/.htaccess"), 'denied') && str_contains((string)file_get_contents(SF_DATA_DIR . '/backups/.htaccess'), 'denied'));
$gi = (string)file_get_contents("$root/.gitignore");
check('N3 הגיבויים והמפתחות לא בגיט (sforno/.gitignore: data/*)', str_contains($gi, "data/*\n") && str_contains($gi, '!data/.htaccess'));
$dy = (string)file_get_contents(dirname($root) . '/.github/workflows/deploy.yml');
check('N3 data/ מוחרגת מהפריסה (rsync לא נוגע בגיבויים)', str_contains($dy, "--exclude='/sforno/data/*'"));
// היום הבא — גיבוי נוסף (dedupe לפי יום); ישן מ-30 יום — נמחק; האחרון — תמיד נשאר
$nb = fn() => (int)sf_val("SELECT COUNT(*) FROM backups WHERE kind = 'daily' AND deleted_at IS NULL");
$before = $nb();
$later(600);
sf_jobs_run(20);
eq($nb(), $before, 'N3 גיבוי אחד ליום — סבב נוסף באותו יום לא יוצר עוד');
$later(86400);
sf_jobs_run(20);
eq($nb(), $before + 1, 'N3 …ולמחרת — עוד אחד');
$old = $b1['file'];
sf_exec('UPDATE backups SET created_at = ? WHERE id = ?', [sf_iso(sf_time() - 31 * 86400), $b1['id']]);
eq(sf_backup_prune(), 1, 'N3 גיבוי מעל 30 יום נמחק');
sf_exec('UPDATE backups SET deleted_at = ? WHERE deleted_at IS NULL AND id < (SELECT MAX(id) FROM backups)', [sf_now()]);
check('N3 …הקובץ נמחק מהדיסק', !is_file(SF_DATA_DIR . "/backups/$old") && sf_val('SELECT deleted_at FROM backups WHERE id = ?', [$b1['id']]) !== null);
sf_exec('UPDATE backups SET created_at = ? WHERE deleted_at IS NULL', [sf_iso(sf_time() - 60 * 86400)]);
eq(sf_backup_prune(), 0, 'N3 הגיבוי האחרון לא נמחק לעולם, גם אם ישן');

// ── N4: בדיקת השחזור — למסד זמני, והספירות זהות ──────────────
$last = sf_row('SELECT * FROM backups WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
$v = $ok(call('POST', 'dev-backup-verify', [], $DEV), 'N4 dev-backup-verify');
check('N4 בדיקת השחזור עוברת', $v['passed'] === true, $v['checks']);
eq($v['counts'], json_decode((string)$last['counts'], true), 'N4 הספירות במסד המשוחזר זהות לגיבוי');
check('N4 …כולל המידע האישי והדיווחים', ($v['counts']['personal_items'] ?? 0) >= 3 && ($v['counts']['reports'] ?? 0) >= 3);
check('N4 secret.key שבגיבוי מפענח פריט מוצפן', (bool)array_filter($v['checks'], fn($c) => str_contains($c['text'], 'מפענח')));
check('N4 המסד הזמני נמחק אחרי הבדיקה', !glob(SF_DATA_DIR . '/backups/.verify-*'));
check('N4 התוצאה נרשמה', (int)sf_val('SELECT verify_ok FROM backups WHERE id = ?', [$last['id']]) === 1);
// גיבוי שהשתנה — הבדיקה נכשלת, ולוח הבקרה מתריע
$f = SF_DATA_DIR . '/backups/' . $last['file'];
$keep = file_get_contents($f);
file_put_contents($f, $keep . 'x');
$v = $ok(call('POST', 'dev-backup-verify', [], $DEV), 'N4 גיבוי פגום');
check('N4 קובץ ששונה — הבדיקה נכשלת', $v['passed'] === false);
check('N4 …ולוח הבקרה מתריע', in_array('backup', array_column($ok(call('GET', 'ops-dashboard', [], $STAFF), 'dash')['alerts'], 'area'), true));
file_put_contents($f, $keep);
// הבדיקה החודשית — רצה לבד
sf_exec("DELETE FROM jobs WHERE kind = 'ops.restore_check'");
sf_exec('UPDATE backups SET verified_at = NULL, verify_ok = NULL');
$later(60);
sf_jobs_run(20);
check('N4 בדיקת השחזור החודשית רצה במשימת הרקע', (int)sf_val('SELECT verify_ok FROM backups WHERE id = ?', [$last['id']]) === 1);
// CLI
// (בלי פקודה — עזרה בלבד: הסקריפט עובד על data/ האמיתי, לא נוגעים בו מהבדיקות)
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tools/backup.php") . ' 2>&1', $o, $codeCli);
eq($codeCli, 0, 'tools/backup.php רץ (CLI)');
check('…ולא פתח את המסד האמיתי', !is_file("$root/data/sforno.sqlite") || filemtime("$root/data/sforno.sqlite") < time() - 5);
check('tools/ חסומה מהדפדפן', str_contains((string)file_get_contents("$root/tools/.htaccess"), 'denied'));
check('dev/restore.md קיים — עם השלבים', str_contains((string)file_get_contents("$root/dev/restore.md"), 'apply'));
// ── 🟡2: ההורדה — רק מוצפנת בסיסמה (Argon2id + secretstream); הסיסמה לא נשמרת ──
eq(code(call('GET', 'dev-backup-download', [], $DEV)), 'method', '🟡2 הורדה ב-GET (קישור) — אין');
$GLOBALS['sf_send_out'] = [];
eq(code(call('POST', 'dev-backup-download', ['password' => 'short'], $DEV)), 'bad_input', '🟡2 בלי סיסמה של 12 תווים — אין הורדה');
$PW = 'סיסמה-ארוכה-לגיבוי-42';
$dl = $ok(call('POST', 'dev-backup-download', ['password' => $PW], $DEV), 'dev-backup-download');
check('🟡2 ההורדה — הגיבוי האחרון (.sfbk), ונרשמת ביומן', $dl['name'] === preg_replace('/\.zip$/', '.sfbk', $last['file']) && audit_count('backup.download') === 1);
$enc = (string)file_get_contents($dl['file']);
$key = (string)file_get_contents(SF_DATA_DIR . '/secret.key');
check('🟡2 הקובץ מוצפן: לא zip, בלי המפתח ובלי המסד בגלוי', str_starts_with($enc, SF_BACKUP_ENC_MAGIC) && !str_contains($enc, 'PK' . "\x03\x04") && !str_contains($enc, $key) && !str_contains($enc, 'SQLite format'));
$audit = (string)json_encode(sf_rows('SELECT meta FROM audit_log'), JSON_UNESCAPED_UNICODE) . (string)json_encode(sf_rows('SELECT k, v FROM meta'), JSON_UNESCAPED_UNICODE);
check('🟡2 הסיסמה לא נשמרת (לא במסד, לא ביומן)', !str_contains($audit, $PW));
$plain = SF_DATA_DIR . '/dec-' . bin2hex(random_bytes(3)) . '.zip';
$err = null;
try { sf_backup_decrypt($dl['file'], $plain, 'סיסמה-שגויה-לגמרי'); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check('🟡2 סיסמה שגויה — לא מפוענח, ולא נשאר קובץ', $err !== null && !is_file($plain), $err);
sf_backup_decrypt($dl['file'], $plain, $PW);
eq(hash_file('sha256', $plain), $last['sha256'], '🟡2 בסיסמה הנכונה — בדיוק הגיבוי שבשרת');
$cut = $dl['file'] . '.cut';
file_put_contents($cut, substr($enc, 0, -10));
$err = null;
try { sf_backup_decrypt($cut, $plain . '2', $PW); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check('🟡2 קובץ קטוע — נדחה', $err !== null && !is_file($plain . '2'));
$imp = sf_backup_import($dl['file'], $PW);
check('🟡2 import — נרשם כגיבוי ועובר בדיקת שחזור', $imp['kind'] === 'import' && sf_backup_verify($imp['pid'])['passed'] === true);
unset($GLOBALS['sf_send_out']);
sf_exec("UPDATE backups SET deleted_at = ? WHERE kind = 'import'", [sf_now()]);
check('🟡2 ה-CLI: import ו-decrypt', str_contains((string)file_get_contents("$root/tools/backup.php"), "case 'import'") && str_contains((string)file_get_contents("$root/tools/backup.php"), "case 'decrypt'"));

// ── 🟡3: קישור במייל שנשלח מ-cron / CLI — הכתובת שנשמרה, לא localhost ──
$known = sf_known_url();
eq([$known['origin'] ?? null, $known['base'] ?? null], [SF_ORIGIN, SF_BASE_PATH], '🟡3 בקשה של מנהל שומרת את הכתובת הציבורית');
eq(sf_origin_of([]), SF_ORIGIN, '🟡3 בלי SERVER_NAME (cron) — הכתובת שנשמרה, לא localhost');
eq(sf_origin_of(['SERVER_NAME' => 'localhost']), SF_ORIGIN, '🟡3 SERVER_NAME=localhost — גם');

// ── 🟡1: שחזור — הכול או כלום, ומצב תחזוקה ──
$GLOBALS['sf_apply_wait'] = 0;
sf_db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$snap = fn() => [hash_file('sha256', SF_DB_PATH) . '/' . (is_file(SF_DB_PATH . '-wal') ? hash_file('sha256', SF_DB_PATH . '-wal') : '-'),
    hash_file('sha256', SF_DATA_DIR . '/secret.key'), hash_file('sha256', SF_DATA_DIR . '/vapid.json'), is_dir(SF_DATA_DIR . '/sources/abc')];
$before = null;
$nRen = 0;
$GLOBALS['sf_rename'] = function (string $a, string $b) use (&$nRen, &$before, $snap) {
    if ($nRen === 0) $before = $snap();          // המצב החי ברגע שההחלפה מתחילה
    if (++$nRen === 4) return false;            // הזזה רביעית נכשלת — באמצע ההחלפה
    if ($nRen === 2) check('🟡1 בזמן ההחלפה — מצב תחזוקה', sf_in_maintenance());
    return rename($a, $b);
};
$err = null;
try { sf_backup_apply(); } catch (RuntimeException $e) { $err = $e->getMessage(); }
unset($GLOBALS['sf_rename']);
check('🟡1 כשל באמצע — זריקה (לא "✓ שוחזר")', $err !== null && str_contains($err, 'הוחזר המצב הקודם'), $err);
check('🟡1 ההחלפה התחילה (יש מה להחזיר)', $nRen >= 4);
eq($snap(), $before, '🟡1 …והכול חזר למקומו: המסד, secret.key, vapid.json, sources/');
check('🟡1 …ומצב התחזוקה הוסר', !sf_in_maintenance());
check('🟡1 …בלי תיקיות זמניות', !glob(SF_DATA_DIR . '/.restore-*'));
$GLOBALS['sf_pdo'] = null;
unset($GLOBALS['sf_pdo']);
eq($ok(call('GET', 'dev-backups', [], $DEV), 'אחרי כשל — האפליקציה עובדת')['keep_days'], 30, '🟡1 האפליקציה עונה אחרי הכשל');
file_put_contents(sf_maint_file(), '{}');
eq(call('GET', 'me', [], $DEV)[0], 503, '🟡1 במצב תחזוקה — api עונה 503');
eq(code(rcall('POST', 'report-create', ['kind' => 'contact', 'category' => 'idea', 'text' => 'x'], 'M1')), 'upgrading', '🟡1 …גם לקוראים (בלי כתיבה למסד)');
eq(sf_cron_handle(sf_cron_token())[0], 503, '🟡1 …ו-cron לא מריץ משימות');
@unlink(sf_maint_file());
eq(call('GET', 'me', [], $DEV)[0], 200, '🟡1 אחרי — חזרה לרגיל');

$list = $ok(call('GET', 'dev-backups', [], $DEV), 'dev-backups');
check('dev-backups — בלי נתיב מלא בשרת', !str_contains(json_encode($list), SF_DATA_DIR));

// ── מחיקה מלאה אחרי 30 יום (05) ─────────────────────────────
$GLOBALS['sf_mail_out'] = [];
rcall('POST', 'account-register', ['email' => 'gone@example.com', 'password' => 'gone-gone-11'], 'Z');
$zid = (int)sf_val("SELECT id FROM readers WHERE email = 'gone@example.com'");
rcall('POST', 'sync-push', ['items' => [['pid' => 'zn', 'kind' => 'note', 'ref' => $ED, 'data' => ['note' => 'x']], ['pid' => 'zf', 'kind' => 'store_favorite', 'ref' => '12', 'data' => ['id' => 12]]]], 'Z');
sf_insert('push_devices', ['pid' => sf_pid(), 'reader_id' => $zid, 'device_id' => null, 'guest_hash' => null, 'endpoint_hash' => 'eh-z', 'sub' => 's1:x', 'label' => 'x',
    'created_at' => sf_now(), 'last_seen_at' => sf_now()]);
sf_insert('reminder_occurrences', ['owner' => "r:$zid", 'reminder' => 'r1', 'fire_at' => sf_now(), 'channel' => 'push', 'dedupe_key' => 'kz', 'status' => 'sent', 'created_at' => sf_now(), 'updated_at' => sf_now()]);
sf_rem_log((int)sf_val("SELECT id FROM reminder_occurrences WHERE dedupe_key = 'kz'"), 'push', 'sent', 201);
$ok(rcall('POST', 'account-delete', ['confirm' => true], 'Z'), 'account-delete');
$count = fn() => array_sum(array_map(fn($t) => (int)sf_val("SELECT COUNT(*) FROM $t WHERE reader_id = ?", [$zid]), ['personal_items', 'devices', 'reader_sessions', 'reader_tokens', 'push_devices', 'reader_activity']))
    + (int)sf_val('SELECT COUNT(*) FROM reminder_occurrences WHERE owner = ?', ["r:$zid"]);
check('לפני 30 יום — המידע עדיין קיים (מחיקה רכה)', $count() > 0);
$later(29 * 86400);
sf_ops_purge();
check('29 יום — עוד לא נמחק', sf_val('SELECT status FROM readers WHERE id = ?', [$zid]) === 'deleted' && $count() > 0);
$later(2 * 86400);
$p = sf_ops_purge();
eq($p['readers'], 1, 'אחרי 30 יום — מחיקה מלאה');
eq($count(), 0, 'personal_items, devices, sessions, tokens, push_devices, פעילות ומופעים — נמחקו');
eq((int)sf_val("SELECT COUNT(*) FROM reminder_send_log l JOIN reminder_occurrences o ON o.id = l.occurrence_id WHERE o.owner = ?", ["r:$zid"]), 0, '…וגם לוג השליחה שלו');
$z = sf_row('SELECT * FROM readers WHERE id = ?', [$zid]);
check('השורה נשארת בלי מייל, שם, סיסמה', $z['status'] === 'purged' && !str_contains($z['email'], 'gone@') && $z['name'] === '' && $z['password_hash'] === null);
$ok(rcall('POST', 'account-register', ['email' => 'gone@example.com', 'password' => 'back-back-11'], 'Z2'), 'אפשר להירשם שוב באותו מייל');
check('הניקוי — ביומן הפעולות', audit_count('reader.purge') === 1);
check('קורא פעיל לא נפגע', (int)sf_val("SELECT COUNT(*) FROM personal_items WHERE reader_id = (SELECT id FROM readers WHERE email = 'rina@example.com')") >= 3);
// "לחזרה אליי" — נמחק 90 יום אחרי הסגירה; פעילות — 90 יום
$ok(call('POST', 'report-set', ['report' => $rep2['report'], 'status' => 'closed'], $STAFF), 'סגירה');
$later(91 * 86400);
$p = sf_ops_purge();
check('"לחזרה אליי" נמחק 90 יום אחרי הסגירה; פעילות ישנה נמחקת', sf_val('SELECT contact FROM reports WHERE pid = ?', [$rep2['report']]) === null && $p['activity'] > 0);

// ── 09 ⚪: הצמדת מארח החנות, webhook לפני הגוף, store-out ─────
foreach (['10.0.0.5', '192.168.1.2', '127.0.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1',
    '198.18.0.1', '198.19.255.255', '224.0.0.1', '239.1.2.3', '255.255.255.255', '64:ff9b::a00:1', '64:ff9b:1::1', '2002:a00:1::', '2001:0:4136::1',
    'fec0::1', 'ff02::1', '2001:db8::1', '::'] as $ip) check("SSRF: $ip — לא ציבורי", !sf_net_ip_public($ip));
check('SSRF: כתובת ציבורית', sf_net_ip_public('93.184.216.34') && sf_net_ip_public('2606:4700::6810:84e5') && sf_net_ip_public('198.20.0.1') && sf_net_ip_public('2a00:1450::1'));
$GLOBALS['sf_dns'] = fn($h) => ['shop.test' => ['93.184.216.34'], 'mixed.test' => ['93.184.216.34', '10.0.0.7'], 'inner.test' => ['192.168.0.10']][$h] ?? [];
eq([sf_net_public_ip('shop.test'), sf_net_public_ip('mixed.test'), sf_net_public_ip('inner.test'), sf_net_public_ip('none.test')], ['93.184.216.34', null, null, null], 'SSRF: מארח שמצביע (גם) לפנימית — נחסם כולו');
unset($GLOBALS['sf_woo_http']);
eq(sf_woo_http('GET', 'https://inner.test/wp-json/wc/v3/products', []), [0, '', []], 'SSRF: sf_woo_http לא מתחבר למארח פנימי');
check('SSRF: curl מוצמד לכתובת שנבדקה (CURLOPT_RESOLVE)', str_contains((string)file_get_contents("$root/lib/store.php"), 'CURLOPT_RESOLVE'));
unset($GLOBALS['sf_dns']);
sf_woo_write(['url' => 'https://shop.test', 'key' => 'ck_x', 'secret' => 'cs_x', 'webhook_secret' => 'whsec-1']);
$hook = fn(string $ip) => call('POST', 'store-webhook', [], null, [], ['content_type' => 'application/json']);
$n = 0;
for ($i = 0; $i < SF_STORE_HOOK_PER_MIN + 3; $i++) {
    try { sf_store_webhook(['ip' => '203.0.113.50', 'raw_body' => '{"id":1}', 'wc_signature' => 'bad']); } catch (SfError $e) { if ($e->errorCode === 'rate') $n++; }
}
eq($n, 3, 'webhook: הגבלה לכתובת IP — לפני קריאת הגוף וה-HMAC');
$threw = null;
try { sf_store_webhook(['ip' => '203.0.113.51', 'raw_body' => '{"id":1}', 'wc_signature' => 'bad']); } catch (SfError $e) { $threw = $e->errorCode; }
eq($threw, 'forbidden', 'webhook: כתובת אחרת — לא נחסמה (רק החתימה נדחית)');
sf_insert('store_products', ['woo_id' => 77, 'source' => 'woo', 'name' => 'לא לרכישה', 'permalink' => 'https://shop.test/p/77', 'purchasable' => 0, 'raw' => '{}', 'synced_at' => sf_now()]);
sf_insert('store_products', ['woo_id' => 78, 'source' => 'woo', 'name' => 'ממארח ישן', 'permalink' => 'https://old-shop.test/p/78', 'purchasable' => 1, 'raw' => '{}', 'synced_at' => sf_now()]);
sf_insert('store_products', ['woo_id' => 79, 'source' => 'woo', 'name' => 'תקין', 'permalink' => 'https://shop.test/p/79', 'purchasable' => 1, 'raw' => '{}', 'synced_at' => sf_now()]);
eq(code(rcall('POST', 'store-out', ['id' => 77], 'S1')), 'state', 'store-out: מוצר שלא ניתן לרכישה — אין מעבר');
eq(code(rcall('POST', 'store-out', ['id' => 78], 'S1')), 'not_found', 'store-out: קישור ממארח קודם של החנות — לא יוצא');
eq($ok(rcall('POST', 'store-out', ['id' => 79], 'S1'), 'store-out')['url'], 'https://shop.test/p/79', 'store-out: מוצר תקין');

// ── N4/N5: ה-CLI על תיקיית הנתונים של הבדיקה — verify, restore-to, ו-apply (השחזור של dev/restore.md) ──
$cli = fn(string $args) => [exec('SF_DATA_DIR=' . escapeshellarg(SF_DATA_DIR) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tools/backup.php") . " $args 2>&1", $out, $rc), $rc, implode("\n", $out)];
sf_db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
[, $rc, $o] = $cli('verify');
check('CLI verify — עובר', $rc === 0 && str_contains($o, 'הספירות זהות'), $o);
$to = SF_DATA_DIR . '/restore-test';
[, $rc, $o] = $cli('restore-to ' . escapeshellarg($to));
check('CLI restore-to — המסד והמפתח בתיקייה', $rc === 0 && is_file("$to/sforno.sqlite") && is_file("$to/secret.key"), $o);
[, $rc] = $cli('apply');
eq($rc, 1, 'CLI apply בלי --yes — לא עושה כלום');
$want = json_decode((string)sf_val('SELECT counts FROM backups WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1'), true);
$GLOBALS['sf_pdo'] = null;
unset($GLOBALS['sf_pdo']);
[, $rc, $o] = $cli('apply --yes');
check('CLI apply --yes — שוחזר', $rc === 0 && str_contains($o, 'replaced'), $o);
$rdb = sf_backup_open(SF_DB_PATH);
$got = sf_backup_counts($rdb);
eq(array_diff_key($got, ['backups' => 0, 'audit_log' => 0, 'meta' => 0, 'jobs' => 0]), array_diff_key($want, ['backups' => 0, 'audit_log' => 0, 'meta' => 0, 'jobs' => 0]), 'N5 אחרי apply — המסד החי זהה לגיבוי');
check('N5 מה שהוחלף נשמר ב-data/.replaced-*', (bool)glob(SF_DATA_DIR . '/.replaced-*/sforno.sqlite'));

done();
