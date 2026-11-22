<?php
// פרוסה 05 — שיתוף: שמאי ממשרד אחר, גישת תמיכה וביטול (docs/spec/05-sharing.md §9): 05-A1 … 05-C3, מקרי הקצה של §8, רשימת נקודות
// הגישה של §6, והמיגרציה. כל בדיקת 🤖 כאן נשענת על תנאי אחד בקוד — tests/mutations-05.php שובר כל אחד מהם ומוודא שהבדיקה נופלת.
$dir = sys_get_temp_dir() . '/apr-test-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
define('APR_DATA_DIR', $dir);
define('APR_DB_PATH', $dir . '/app.sqlite');
require __DIR__ . '/lib.php';

$warnings = [];
$GLOBALS['apr_upload_rate'] = [100000, 600];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });
$T0 = 1794400000;
$GLOBALS['apr_time'] = $T0;             // שעון קפוא — מזיזים אותו במפורש כשבודקים תפוגה

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);   // מנהל המערכת — אינו חבר בשום משרד
$avi = ident('2', 'אבי', 'avi@example.com');        // משרד א: מנהל, שמאי, האחראי על התיקים
$dana = ident('3', 'דנה', 'dana@example.com');      // משרד א: שמאית, שותפה בתיק
$tal = ident('5', 'טל', 'tal@example.com');         // משרד א: עובדת משרד
$boaz = ident('4', 'בועז <img src=x onerror=alert(1)>', 'boaz@example.com');      // משרד ב: השותף החיצוני. השם שלו מגיע למסך של משרד א
$bella = ident('6', 'בלה', 'bella@example.com');    // משרד ב: חברה אחרת — לא הוזמנה לשום דבר
$zvi = ident('9', 'צבי', 'zvi@example.com');        // יש חשבון, אין משרד
call('GET', 'me', [], $owner);
$OA = office_for($avi, 'משרד א');
$OB = office_for($boaz, 'משרד ב <b>מודגש</b>');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$talPid = join_office($avi, $tal, 'tal@example.com', 'staff');
join_office($boaz, $bella, 'bella@example.com', 'appraiser');
call('GET', 'me', [], $zvi);
$uid = fn(string $email) => (int)apr_val('SELECT id FROM users WHERE email = ?', [$email]);
$aviPid = (string)apr_val('SELECT pid FROM users WHERE email = ?', ['avi@example.com']);

function req(string $key, array $more = []): array
{
    return $more + ['key' => $key, 'group' => 'legal', 'doc_type' => 'rights', 'title' => "דרישה $key", 'priority' => 'normal', 'mandatory' => false, 'party_kind' => 'client',
        'due' => ['mode' => 'relative', 'days' => 3], 'needs_review' => true, 'when' => null, 'sort' => 1, 'explain_internal' => 'הערה-פנימית-סודית'];
}
function make_profile(string $as, string $name): string
{
    $pid = call('POST', 'profile-create', ['scope' => 'office', 'name' => $name], $as)[1]['profile'];
    $content = ['flags' => [], 'requirements' => ['groups' => [['key' => 'legal', 'title' => 'קבוצה-מהפרופיל']], 'items' => [req('rights', ['title' => 'נסח טאבו']), req('contract', ['title' => 'הסכם מכר', 'sort' => 2])]],
        'tasks' => ['items' => []], 'rules' => []];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $pid], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $pid, 'content' => $content, 'revision' => $d['draft']['revision']], $as);
    $r = call('POST', 'profile-publish', ['profile' => $pid, 'note' => '', 'revision' => $s[1]['revision'] ?? ''], $as);
    if ($r[0] !== 200) throw new RuntimeException('publish: ' . json_encode([$s[1], $r[1]], JSON_UNESCAPED_UNICODE));
    return $pid;
}
function make_case(string $as, string $profile, string $title, string $client = 'לקוח-סודי'): string
{
    $c = call('POST', 'case-create', ['profile' => $profile], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => $title, 'purpose' => 'purchase', 'property_type' => 'apartment'], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '6158', 'helka' => '12'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => $client, 'email' => $client === 'לקוח-סודי' ? 'client@secret.example' : 'b@client.example', 'kind' => 'client', 'is_orderer' => true], $as);
    $s = call('POST', 'case-start', ['case' => $c], $as);
    if ($s[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($s[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
function pdf(string $mark): string
{
    return "%PDF-1.4\n%$mark\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}
function jpg(string $mark): string
{
    $im = imagecreatetruecolor(40, 30);
    imagefill($im, 0, 0, imagecolorallocate($im, crc32($mark) & 255, 90, 160));
    ob_start(); imagejpeg($im); return (string)ob_get_clean();
}
function up(string $as, string $case, string $name, string $bytes, array $q = []): array
{
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, $bytes);
    $r = call('POST', 'file-upload', $q + ['case' => $case, 'name' => $name], $as, [], ['env' => ['upload' => $tmp]]);
    if ($r[0] !== 200) throw new RuntimeException('upload: ' . json_encode($r[1], JSON_UNESCAPED_UNICODE));
    return $r;
}
$caseId = fn(string $pid) => (int)apr_val('SELECT id FROM cases WHERE pid = ?', [$pid]);
$raises = function (string $sql, array $args = []): bool { try { apr_exec($sql, $args); return false; } catch (PDOException) { return true; } };
$json = fn(array $res) => (string)json_encode($res[1], JSON_UNESCAPED_UNICODE);
$sc = fn(array $res) => [$res[0], code($res)];

// ── התיקים של משרד א ──
$PA = make_profile($avi, 'פרופיל-סודי');
$PB = make_profile($boaz, 'פרופיל ב');
$C = make_case($avi, $PA, 'דירה ברחוב הרצל');          // התיק שישותף
$C2 = make_case($avi, $PA, 'וילה-סודית');              // תיק אחר של משרד א — לא משותף לעולם
$CB = make_case($boaz, $PB, 'תיק של משרד ב', 'לקוח של ב');
call('POST', 'case-member-add', ['case' => $C, 'user' => $danaPid], $avi);
call('POST', 'case-member-add', ['case' => $C, 'user' => $talPid], $avi);
call('POST', 'contact-save', ['name' => 'איש-קשר-סודי', 'phone' => '050-0000000'], $avi);
$docs = call('GET', 'case-docs', [], $avi, ['case' => $C])[1];
$R1 = array_values(array_filter($docs['requirements'], fn($r) => $r['title'] === 'נסח טאבו'))[0]['pid'];
$R2 = array_values(array_filter($docs['requirements'], fn($r) => $r['title'] === 'הסכם מכר'))[0]['pid'];
$u1 = up($avi, $C, 'גלוי.pdf', pdf('one'), ['requirement' => $R1]);
$F1 = $u1[1]['file']['pid'] ?? $u1[1]['file'];
$u2 = up($avi, $C, 'נסתר.pdf', pdf('two'), ['requirement' => $R2]);
$F2 = $u2[1]['file']['pid'] ?? $u2[1]['file'];
$u3 = up($avi, $C, 'תמונה.jpg', jpg('three'));
$F3 = $u3[1]['file']['pid'] ?? $u3[1]['file'];
$u4 = up($avi, $C2, 'של-תיק-אחר.pdf', pdf('four'));
$G1 = $u4[1]['file']['pid'] ?? $u4[1]['file'];
apr_exec("INSERT INTO file_texts (file_id, page, text, method, created_at) SELECT id, 1, ?, 'pdf_text', 'x' FROM files WHERE pid = ?", ['מילת-חיפוש-ייחודית בעמוד הראשון של הקובץ הנסתר', $F2]);
apr_exec("INSERT INTO file_texts (file_id, page, text, method, created_at) SELECT id, 1, ?, 'pdf_text', 'x' FROM files WHERE pid = ?", ['מילת-חיפוש-ייחודית בקובץ הגלוי', $F1]);
$FA = call('POST', 'fact-add', ['case' => $C, 'attribute' => 'area', 'value' => '85.5', 'unit' => 'sqm', 'area_kind' => 'registered', 'source' => ['kind' => 'user', 'note' => 'מהנסח']], $avi)[1]['fact']['pid'];
$K1 = call('POST', 'comp-add', ['case' => $C, 'values' => ['price' => 2000000], 'note' => 'הערת-עסקה-סודית'], $avi);
$K1x = $K1; $K1 = $K1[1]['comp'] ?? null;
$vp = call('POST', 'visit-plan', ['case' => $C, 'visitor' => $aviPid], $avi);
$V1 = $vp[1]['visit']['pid'] ?? null;
$V2 = call('POST', 'visit-plan', ['case' => $C, 'visitor' => $aviPid], $avi)[1]['visit']['pid'] ?? null;
$st = call('POST', 'visit-start', ['visit' => $V1, 'rev' => (int)apr_val('SELECT rev FROM visits WHERE pid = ?', [$V1])], $avi);
$photo = function (string $visit, string $mark) use ($avi): array {
    $tmp = tempnam(apr_upload_tmp_dir(), 'up');
    file_put_contents($tmp, jpg($mark));
    return call('POST', 'visit-photo-upload', ['visit' => $visit, 'name' => "$mark.jpg", 'key' => 'k-' . bin2hex(random_bytes(6))], $avi, [], ['env' => ['upload' => $tmp]]);
};
$p1 = $photo($V1, 'p-one');
$p2 = $photo($V1, 'p-two');
$P1 = $p1[1]['photo']['pid'] ?? null;      // יסומן "לתיק"
$P2 = $p2[1]['photo']['pid'] ?? null;      // נשאר private
$P1f = $p1[1]['photo']['file'] ?? null;
$P2f = $p2[1]['photo']['file'] ?? null;
call('POST', 'visit-photo-update', ['photo' => $P1, 'rev' => 1, 'usage' => 'case'], $avi);
$identPid = (string)apr_val('SELECT pid FROM case_identifiers WHERE case_id = ?', [$caseId($C)]);
$rk = call('POST', 'source-check-record', ['case' => $C, 'source' => 'xplan', 'status' => 'found', 'identifiers' => [$identPid], 'query_text' => 'שאילתה', 'coverage_note' => 'כיסוי', 'result_summary' => 'תוצאה-של-בדיקה'], $avi);
$CK = $rk[1]['check']['pid'] ?? null;
$rk2 = call('POST', 'source-check-record', ['case' => $C, 'source' => 'tabu_extract', 'status' => 'found', 'identifiers' => [$identPid], 'query_text' => 'שאילתה', 'coverage_note' => 'כיסוי', 'result_summary' => 'בדיקה-שלא-שותפה'], $avi);
$CK2 = $rk2[1]['check']['pid'] ?? null;
check('ההכנה: קבצים, נתון, עסקה, ביקורים, תמונות ובדיקות מקור נוצרו', !in_array(null, [$F1, $F2, $F3, $G1, $FA, $K1, $V1, $V2, $P1, $P2, $CK, $CK2], true)
    && apr_val('SELECT usage FROM visit_photos WHERE pid = ?', [$P1]) === 'case', [$K1, $vp[1], $rk[1], $rk2[1], $p1[1]]);

// ── כלים ──
/** תצוגה מקדימה ואז אישור — כמו המסך. מחזיר את תשובת האישור (או את תשובת התצוגה המקדימה, אם נדחתה). */
$share = function (string $as, string $case, array $p) {
    $pv = call('POST', 'share-preview', ['case' => $case] + $p, $as);
    if ($pv[0] !== 200) return $pv;
    return call('POST', 'share-create', ['case' => $case, 'preview' => $pv[1]['preview']] + $p + (($p['kind'] ?? '') === 'support' ? ['confirm' => 'אישור'] : []), $as);
};
$toBoaz = fn(array $more = []) => $more + ['kind' => 'cross_office', 'email' => 'boaz@example.com', 'scope' => 'all', 'perms' => [], 'days' => 0];
$toSupport = fn(array $more = []) => $more + ['kind' => 'support', 'scope' => 'items', 'items' => [['type' => 'file', 'pid' => $GLOBALS['F1']]], 'perms' => [], 'days' => 7, 'reason' => 'תקלה בתצוגת הקובץ'];
$GLOBALS['F1'] = $F1;
$srow = fn(string $pid) => apr_row('SELECT * FROM case_shares WHERE pid = ?', [$pid]);
$accept = fn(string $as, string $sharePid, bool $ok = true) => call('POST', 'share-respond', ['share' => $sharePid, 'accept' => $ok], $as);
$revoke = fn(string $as, string $sharePid) => call('POST', 'share-revoke', ['share' => $sharePid], $as);
$log = fn(string $case, string $action) => apr_rows('SELECT * FROM audit_log WHERE case_id = ? AND action = ? ORDER BY id', [$caseId($case), $action]);
$g = fn(string $as, string $action, array $q) => call('GET', $action, [], $as, $q);

/** כל נקודת גישה של §6 לתיק המשותף ($C), כפי שמשתמש אחד רואה אותה: [status, code] לכל אחת. */
$lst = fn(array $r, string $key) => $r[0] === 200 ? [200, count($r[1][$key]) ?: null] : [$r[0], code($r)];      // רשימה: 200 וכמה שורות (null = ריקה)
$points = function (string $as) use ($g, $sc, $lst, $C, $F1, $F2, $F3, $FA, $K1, $V1, $P1, $P2, $CK, $R1) {
    return [
        'case' => $sc($g($as, 'case', ['case' => $C])),
        'case-docs' => $sc($g($as, 'case-docs', ['case' => $C])),
        'file (תצוגה)' => $sc($g($as, 'file', ['file' => $F1, 'inline' => '1'])),
        'file (הורדה)' => $sc($g($as, 'file', ['file' => $F1])),
        'file-thumb' => $sc($g($as, 'file-thumb', ['file' => $F3])),
        'file-facts' => $sc($g($as, 'file-facts', ['file' => $F1])),
        'file-ai' => $sc($g($as, 'file-ai', ['file' => $F1])),
        'case-facts' => $sc($g($as, 'case-facts', ['case' => $C])),
        'fact' => $sc($g($as, 'fact', ['fact' => $FA])),
        'case-conflicts' => $sc($g($as, 'case-conflicts', ['case' => $C])),
        'case-visits' => $sc($g($as, 'case-visits', ['case' => $C])),
        'visit' => $sc($g($as, 'visit', ['visit' => $V1])),
        'visit-photo' => $sc($g($as, 'visit-photo', ['photo' => $P1])),
        'visit-photo (ממוזערת)' => $sc($g($as, 'visit-photo', ['photo' => $P1, 'thumb' => '1'])),
        'visit-photo (פרטית)' => $sc($g($as, 'visit-photo', ['photo' => $P2])),
        'case-sources' => $sc($g($as, 'case-sources', ['case' => $C])),
        'case-source' => $sc($g($as, 'case-source', ['case' => $C, 'source' => 'xplan'])),
        'source-check' => $sc($g($as, 'source-check', ['check' => $CK])),
        'case-planning' => $sc($g($as, 'case-planning', ['case' => $C])),
        'case-comps' => $sc($g($as, 'case-comps', ['case' => $C])),
        'comp' => $sc($g($as, 'comp', ['comp' => $K1])),
        'case-activity' => $sc($g($as, 'case-activity', ['case' => $C])),
        'case-requests' => $sc($g($as, 'case-requests', ['case' => $C])),
        'case-plan' => $sc($g($as, 'case-plan', ['case' => $C])),
        'case-ai' => $sc($g($as, 'case-ai', ['case' => $C])),
        'case-profile-diff' => $sc($g($as, 'case-profile-diff', ['case' => $C])),
        'case-shares' => $sc($g($as, 'case-shares', ['case' => $C])),
        'docs-search (shared)' => $lst($g($as, 'docs-search', ['q' => 'מילת-חיפוש', 'shared' => '1']), 'results'),
        'cases (shared)' => $lst($g($as, 'cases', ['tab' => 'shared']), 'shared'),
    ];
};
$closed = fn(array $pts) => array_values(array_unique(array_map(fn($x) => $x[0] . ' ' . ($x[1] ?? '-'), $pts)));
$GONE = ['404 not_found', '200 -'];      // כל נקודה: 404 "לא נמצא"; ושתי הרשימות — ריקות
/** הנקודות שנענו 200 עם תוכן (רשימה ריקה אינה "נפתחה"). */
$open = function (array $pts): array {
    $out = [];
    foreach ($pts as $k => $x) {
        if ($x[0] !== 200) continue;
        if (in_array($k, ['docs-search (shared)', 'cases (shared)'], true) && $x[1] === null) continue;
        $out[] = $k;
    }
    return $out;
};
/** מצב כל הנתונים של משרד א — כדי לוודא ששום דבר לא נכתב בידי מי שאינו רשאי. */
$stateA = fn() => hash('sha256', json_encode(array_map(fn($t) => apr_rows("SELECT * FROM $t ORDER BY 1"),
    ['cases', 'case_members', 'case_shares', 'case_share_items', 'requirements', 'requirement_files', 'files', 'facts', 'comps', 'visits', 'visit_photos', 'source_checks', 'tasks', 'contacts', 'case_parties', 'profiles', 'memberships'])));
$SECRETS = ['וילה-סודית', 'לקוח-סודי', 'client@secret.example', 'איש-קשר-סודי', 'פרופיל-סודי', 'קבוצה-מהפרופיל', 'הערה-פנימית-סודית', 'של-תיק-אחר', 'דנה', 'טל', 'dana@example.com', 'הערת-עסקה-סודית'];
$leaks = fn(string $text, array $needles) => array_values(array_filter($needles, fn($n) => str_contains($text, $n)));

// ══ 05-A2: עד שהשותף מאשר — אין גישה ══════════════════════════════
eq($closed($points($boaz)), $GONE, 'לפני כל שיתוף: לבועז (משרד ב) אין שום נקודת גישה לתיק של משרד א');
$pv = call('POST', 'share-preview', ['case' => $C] + $toBoaz(), $avi);
$sum = $pv[1]['summary'] ?? [];
eq([$pv[0], $sum['recipient']['email'] ?? null, $sum['recipient']['office'] ?? null, $sum['scope'] ?? null, $sum['perms'] ?? null, array_key_exists('expires_at', $sum) ? $sum['expires_at'] : 'x', $sum['needs_accept'] ?? null,
    array_values(array_intersect(['other_cases', 'profiles', 'team', 'contacts', 'internal', 'draft', 'download'], $sum['hidden'] ?? []))],
    [200, 'boaz@example.com', 'משרד ב <b>מודגש</b>', 'all', ['view'], null, true, ['other_cases', 'profiles', 'team', 'contacts', 'internal', 'draft', 'download']],
    '05-D1 (שרת) התצוגה המקדימה: הנמען והמשרד שלו, ההיקף, הפעולות (view בלבד כברירת מחדל), התוקף, ומה לא ייחשף');
eq([code(call('POST', 'share-create', ['case' => $C] + $toBoaz(), $avi)), code(call('POST', 'share-create', ['case' => $C, 'preview' => 'aaaaaaaaaaaaaaaa'] + $toBoaz(), $avi)),
    code(call('POST', 'share-create', ['case' => $C, 'preview' => $pv[1]['preview']] + $toBoaz(['perms' => ['download' => true]]), $avi)),
    code(call('POST', 'share-create', ['case' => $C, 'preview' => $pv[1]['preview']] + $toBoaz(), $dana)), (int)apr_val('SELECT COUNT(*) FROM case_shares')],
    ['preview_required', 'preview_required', 'preview_required', 'forbidden', 0],
    'אין שיתוף בלי תצוגה מקדימה: בלי מזהה, עם מזהה שאינו קיים, עם תוכן שונה ממה שהוצג, ובידי משתמש אחר (שגם אין לו members) — נדחה, ושום שורה לא נוצרה');
$GLOBALS['apr_time'] = $T0 + 901;
eq(code(call('POST', 'share-create', ['case' => $C, 'preview' => $pv[1]['preview']] + $toBoaz(), $avi)), 'preview_expired', 'תצוגה מקדימה בת יותר מ-15 דקות — פגה');
$GLOBALS['apr_time'] = $T0;
$mk = call('POST', 'share-create', ['case' => $C, 'preview' => $pv[1]['preview']] + $toBoaz(), $avi);
$S1 = $mk[1]['share'] ?? '';
eq([$mk[0], $mk[1]['status'] ?? null, code(call('POST', 'share-create', ['case' => $C, 'preview' => $pv[1]['preview']] + $toBoaz(), $avi)), $srow($S1)['invite_status'] ?? null, count($log($C, 'share.invite'))],
    [200, 'pending', 'preview_required', 'pending', 1], 'אישור התצוגה ← הזמנה ממתינה (pending), נרשמת ביומן; אותה תצוגה אינה מאשרת פעמיים');
eq($closed($points($boaz)), $GONE, '05-A2 הזמנה שעוד לא אושרה: שום נקודת גישה אינה נפתחת');
$mine = $g($boaz, 'my-shares', [])[1];
eq([count($mine['invites']), $mine['invites'][0]['office'] ?? null, array_keys($mine['invites'][0] ?? []), $mine['shared'], $leaks($json([200, $mine]), [...$SECRETS, 'דירה ברחוב הרצל'])],
    [1, 'משרד א', ['share', 'number', 'office', 'scope', 'perms', 'expires_at'], [], []], '§3.5 השותף רואה "הוזמנת לתיק <מספר> ממשרד <שם>" — מספר ושם משרד בלבד, בלי כותרת התיק');
eq([$sc($accept($bella, $S1)), $sc($accept($owner, $S1)), $sc($accept($avi, $S1)), $sc($accept($boaz, 'aaaaaaaaaaaaaaaa')), $srow($S1)['invite_status']],
    [[404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], 'pending'], 'רק המוזמן עצמו מאשר: חברה אחרת במשרד שלו, מנהל המערכת, המשתף ומזהה שלא קיים — אותו 404');
eq([$accept($boaz, $S1)[0], $srow($S1)['invite_status'], count($log($C, 'share.accept')), $sc($accept($boaz, $S1))], [200, 'active', 1, [404, 'not_found']], 'השותף מאשר ← פעיל, ונרשם ביומן של משרד א; אישור שני — 404');

// ══ 05-A1: שותף עם view על תיק אחד ═══════════════════════════════
$pts = $points($boaz);
eq($open($pts), ['case', 'case-docs', 'file (תצוגה)', 'file-thumb', 'case-visits', 'visit', 'visit-photo', 'visit-photo (ממוזערת)', 'case-sources', 'case-source', 'source-check', 'docs-search (shared)', 'cases (shared)'],
    '05-A1 אחרי אישור, עם view בלבד על כל התיק: נפתחים כרטיס התיק, דרישות וקבצים, תצוגת קובץ, ביקורים (בלי תמונה פרטית), בדיקות מקור, והחיפוש והרשימה של "שותפו איתי" — ותו לא');
eq([$pts['file (הורדה)'], $pts['visit-photo (פרטית)'], $pts['case-facts'], $pts['case-comps'], $pts['comp'], $pts['case-activity'], $pts['case-requests'], $pts['case-ai'], $pts['file-ai'], $pts['case-shares'], $pts['case-planning'], $pts['case-plan'], $pts['case-profile-diff']],
    [[403, 'forbidden'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found']],
    '05-A4 בלי download ← ההורדה נדחית; תמונת ביקור פרטית, נתונים, עסקאות, פעילות, בקשות, בינה, הצעות חילוץ, ניהול השיתופים, תכנון, תוכנית העבודה והפרופיל — 404');
$cv = $g($boaz, 'case', ['case' => $C]);
eq([array_keys($cv[1]), $cv[1]['me']['role'], array_keys(array_filter($cv[1]['me']['perms'])), $cv[1]['shared']['office'], $cv[1]['shared']['scope'], $leaks($json($cv), $SECRETS)],
    [['ok', 'case', 'me', 'shared', 'identifiers', 'parties'], 'external', ['view'], 'משרד א', 'all', ['לקוח-סודי']],
    'כרטיס התיק לשותף: כותרת, של מי התיק, ההיקף, מזהי הנכס ושמות הגורמים — בלי צוות, פרופיל, תוכנית עבודה, משימות, מייל או טלפון של גורם');
$dv = $g($boaz, 'case-docs', ['case' => $C]);
eq([count($dv[1]['requirements']), $dv[1]['groups'], $leaks($json($dv), $SECRETS), array_unique(array_column($dv[1]['requirements'], 'explain_internal')), $dv[1]['perms']],
    [2, [], [], [''], ['documents' => false, 'review_docs' => false]], '05-A4 בלי see_internal: ההערות הפנימיות של הדרישות, שמות הבודקים וקבוצות הפרופיל אינם בתשובה; אין שום פעולה');

// כל פעולה אחרת של משרד א — 404 או ריק, ושום דבר לא נכתב (05-A1)
$ids = ['case' => $C, 'file' => $F1, 'requirement' => $R1, 'visit' => $V1, 'photo' => $P1, 'comp' => $K1, 'fact' => $FA, 'check' => $CK, 'source' => 'xplan', 'user' => $danaPid, 'profile' => $PA, 'share' => $S1,
    'title' => 'x', 'name' => 'x', 'status' => 'review', 'reason' => 'x', 'rev' => 1, 'q' => 'סודי', 'attribute' => 'area', 'value' => '1', 'values' => ['price' => 1], 'key' => 'k-12345678', 'op' => 'op-12345678'];
$before = $stateA();
$bad = [];
foreach (APR_ACCESS as $action => $rule) {
    if (!in_array($rule, ['member', 'admin', 'case'], true) || in_array($action, ['office-leave', 'office-rename', 'case-create', 'contact-save', 'office-create'], true) || str_starts_with($action, 'office-') || str_starts_with($action, 'invite-') || str_starts_with($action, 'member-')) continue;
    foreach ([$ids, ['case' => $C2, 'file' => $G1] + $ids] as $n => $in) {
        $read = in_array($action, APR_READS, true);
        $r = $read ? call('GET', $action, [], $boaz, $in) : call('POST', $action, $in, $boaz);
        $body = $json($r);
        $allowed = $n === 0 && $rule === 'case';      // הקריאה שנפתחה לו בתיק המשותף — נבדקת בנפרד, למעלה ולמטה
        if ($l = $leaks($body, array_diff($SECRETS, $allowed ? ['לקוח-סודי'] : []))) $bad[] = [$action, $n, 'leak', $l];
        if (!$read && $r[0] === 200 && !in_array($action, ['share-respond'], true)) $bad[] = [$action, $n, 'wrote'];
        if ($n === 1 && $r[0] === 200 && str_contains($body, $C2)) $bad[] = [$action, $n, 'other case'];
    }
}
eq([$bad, $stateA() === $before], [[], true], '05-A1 כל פעולת member / admin / case בידי השותף, על התיק המשותף ועל תיק אחר של משרד א: שום סוד של משרד א בתשובה, שום פעולה משנה אינה מתקבלת, ושום שורה לא השתנתה');
$lists = ['cases' => $g($boaz, 'cases', []), 'my-tasks' => $g($boaz, 'my-tasks', []), 'docs-pending' => $g($boaz, 'docs-pending', []), 'my-visits' => $g($boaz, 'my-visits', []), 'contacts' => $g($boaz, 'contacts', []),
    'profiles' => $g($boaz, 'profiles', []), 'office' => $g($boaz, 'office', []), 'case-options' => $g($boaz, 'case-options', []), 'docs-search' => $g($boaz, 'docs-search', ['q' => 'גלוי']), 'inbox' => $g($boaz, 'inbox', [])];
eq([array_filter(array_map(fn($r) => $leaks($json($r), [...$SECRETS, 'דירה ברחוב הרצל', 'גלוי.pdf', $C, 'אבי'])  , $lists)), array_column($lists['cases'][1]['cases'], 'pid'), $lists['docs-search'][1]['results'],
    $sc($g($boaz, 'profile', ['profile' => $PA])), $sc($g($boaz, 'office', ['office' => $OA]))],
    [[], [$CB], [], [404, 'not_found'], [404, 'not_found']],
    '05-A1 רשימת התיקים, "המשימות שלי", הלוח הראשי, אנשי הקשר, הפרופילים, המשרד והחיפוש של השותף — רק של משרד ב; התיק המשותף אינו מתערבב בהם; פרופיל ומשרד של א — 404');
$sh = $g($boaz, 'cases', ['tab' => 'shared'])[1];
eq([$sh['cases'], count($sh['shared']), $sh['shared'][0]['case']['pid'] ?? null, $sh['shared'][0]['office'] ?? null, $g($bella, 'cases', ['tab' => 'shared'])[1]['shared'], $closed($points($bella))],
    [[], 1, $C, 'משרד א', [], $GONE], '"תיקים ששותפו איתי": לשונית נפרדת, מסומנת בשם המשרד הבעלים; חברה אחרת במשרד של השותף אינה רואה דבר — השיתוף הוא לאדם, לא למשרד');

// ══ 05-A4: download, see_internal, see_draft ══════════════════════
$up = $share($avi, $C, $toBoaz(['perms' => ['download' => true, 'see_internal' => true]]));
eq([$up[0], $up[1]['updated'] ?? null, $up[1]['status'] ?? null, (int)apr_val("SELECT COUNT(*) FROM case_shares WHERE case_id = ? AND revoked_at IS NULL", [$caseId($C)]), count($log($C, 'share.update'))],
    [200, true, 'active', 1, 1], '§8.7 אותו אדם מוזמן שוב: השורה הפעילה מתעדכנת (עם תצוגה מקדימה), לא נוצרת שנייה, והאישור שכבר ניתן נשאר');
$pts = $points($boaz);
eq([$pts['file (הורדה)'], $pts['case-facts'], $pts['fact'], $pts['file-facts'], $pts['case-comps'], $pts['comp'], $pts['visit-photo (פרטית)'], $pts['case-activity'], $pts['case-ai'], $pts['file-ai'], $pts['case-shares']],
    [[200, null], [200, null], [200, null], [200, null], [200, null], [200, null], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found']],
    '05-A4 עם download ← הורדה; עם see_internal ← נתונים ועסקאות נפתחים. תמונה פרטית, פעילות, בינה וניהול — נשארים 404');
$dv = $g($boaz, 'case-docs', ['case' => $C]);
eq([array_values(array_unique(array_column($dv[1]['requirements'], 'explain_internal'))), $g($boaz, 'case', ['case' => $C])[1]['me']['perms']['see_draft']], [['הערה-פנימית-סודית'], false],
    '05-A4 עם see_internal ההערות הפנימיות בתשובה; see_draft נשאר כבוי (טיוטת הדוח — פרוסה 17; apr_can(report) כבר סגור)');
$cAll = ['perms' => ['view' => true] + array_fill_keys(APR_CASE_PERMS, false), 'my_role' => 'external', 'share' => ['scope' => 'all', 'items' => []]];
eq([apr_can($cAll, 'report'), apr_can(['perms' => ['see_draft' => true] + $cAll['perms']] + $cAll, 'report'), apr_can($cAll, 'download'), apr_can($cAll, 'internal'), apr_can($cAll, 'comps'), apr_can($cAll, 'facts'), apr_can($cAll, 'team'), apr_can($cAll, 'no_such_area'),
    apr_can(['perms' => ['view' => false]] + $cAll, 'case')],
    [false, true, false, false, false, false, false, false, false], '05-A4 apr_can: טיוטת הדוח רק עם see_draft; הורדה, פנימי, עסקאות ונתונים — כבויים בלי ההרשאה; צוות — אף פעם; אזור לא מוכר — סגור; בלי view — כלום');

// ══ 05-A5: מה שאינו ניתן להענקה ══════════════════════════════════
$deny = [];
foreach (['members', 'approve_report', 'export', 'edit', 'tasks', 'documents', 'review_docs', 'sources', 'visit', 'contact'] as $perm) $deny[$perm] = code(call('POST', 'share-preview', ['case' => $C] + $toBoaz(['perms' => [$perm => true]]), $avi));
eq([array_values(array_unique($deny)), $raises("UPDATE case_shares SET perms = '{\"view\":true,\"members\":true}' WHERE pid = ?", [$S1]), $raises("UPDATE case_shares SET perms = '{\"view\":true,\"export\":1}' WHERE pid = ?", [$S1]),
    $raises("INSERT INTO case_shares (pid, case_id, office_id, kind, user_id, scope, perms, invite_status, added_by, created_at, updated_at) SELECT 'bbbbbbbbbbbbbbbb', case_id, office_id, 'cross_office', ?, 'all', '{\"view\":true,\"approve_report\":true}', 'active', added_by, 'x', 'x' FROM case_shares WHERE pid = ?", [$uid('bella@example.com'), $S1]),
    $raises('DELETE FROM case_shares WHERE pid = ?', [$S1])],
    [['share_perm'], true, true, true, true], '05-A5 members, approve_report, export — וכל פעולת כתיבה — אינם ניתנים להענקה לשותף ממשרד אחר: נדחה ב-API, וגם ב-SQL ישיר (טריגר); שורת שיתוף אינה נמחקת');
// גם אם שורה כזו הייתה נשמרת — ההכרעה מתעלמת ממה שאינו ברשימה
apr_db()->exec('DROP TRIGGER case_shares_perms_upd');
apr_exec("UPDATE case_shares SET perms = '{\"view\":true,\"members\":true,\"edit\":true,\"export\":true,\"documents\":true,\"review_docs\":true}' WHERE pid = ?", [$S1]);
$me = $g($boaz, 'case', ['case' => $C])[1]['me']['perms'];
$GLOBALS['apr_action'] = 'case';
$inner = apr_case_get(apr_ctx(['platform_user' => $boaz, 'cookie' => jar()[$boaz] ?? '', 'ua' => 'test']), $C)['perms'];
unset($GLOBALS['apr_action']);
eq([array_keys(array_filter($inner)), $g($boaz, 'case-docs', ['case' => $C])[1]['perms']], [['view'], ['documents' => false, 'review_docs' => false]],
    '05-A5 הרשאה אסורה שנשמרה במסד אינה מגיעה ל-perms של הבקשה — גם לא כדגל "מותר לך" בתשובה של מסך');
eq([array_keys(array_filter($me)), code(call('POST', 'case-update', ['case' => $C, 'title' => 'נפרץ'], $boaz)), code(call('POST', 'case-member-add', ['case' => $C, 'user' => $danaPid], $boaz)), apr_val('SELECT title FROM cases WHERE pid = ?', [$C])],
    [['view'], 'not_found', 'not_found', 'דירה ברחוב הרצל'], '05-A5 גם כשהרשאה אסורה נשמרה במסד (בלי הטריגר) — apr_share_for אינו מעביר אותה, ושום פעולת כתיבה אינה נפתחת');
apr_exec("UPDATE case_shares SET perms = '{\"view\":true,\"download\":true,\"see_internal\":true}' WHERE pid = ?", [$S1]);
apr_db()->exec((string)preg_replace('/^.*?(CREATE TRIGGER IF NOT EXISTS case_shares_perms_upd.*?END;).*$/s', '$1', (string)file_get_contents(__DIR__ . '/../lib/migrations/015_sharing.sql')));

// ══ 05-B4: המשתף אינו מעניק מה שאין לו ════════════════════════════
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['members' => true, 'download' => false, 'see_internal' => false]], $avi);
$pvAvi = call('POST', 'share-preview', ['case' => $C] + $toBoaz(), $avi)[1]['preview'];
eq([code(call('POST', 'share-create', ['case' => $C, 'preview' => $pvAvi] + $toBoaz(), $dana)), call('POST', 'share-create', ['case' => $C, 'preview' => $pvAvi] + $toBoaz(), $avi)[0]], ['preview_required', 200],
    'תצוגה מקדימה שהוצגה למשתמש אחד אינה מאשרת שיתוף של משתמש אחר — גם כשלשניהם יש members; למי שראה אותה — כן');
eq([code(call('POST', 'share-preview', ['case' => $C] + $toBoaz(['perms' => ['download' => true]]), $dana)), code(call('POST', 'share-preview', ['case' => $C] + $toBoaz(['perms' => ['see_internal' => true]]), $dana)),
    call('POST', 'share-preview', ['case' => $C] + $toBoaz(['perms' => ['see_draft' => true]]), $dana)[0], $g($dana, 'case-shares', ['case' => $C])[1]['grantable'],
    $sc($g($dana, 'file', ['file' => $F1])), $g($dana, 'file', ['file' => $F1, 'inline' => '1'])[0], $sc($g($dana, 'case-comps', ['case' => $C])), array_values(array_unique(array_column($g($dana, 'case-docs', ['case' => $C])[1]['requirements'], 'explain_internal')))],
    ['share_perm_exceeds', 'share_perm_exceeds', 200, ['see_draft'], [403, 'forbidden'], 200, [404, 'not_found'], ['']],
    '05-B4 משתתפת עם members ובלי download / see_internal אינה יכולה להעניק אותן (מה שיש לה — כן); וההרשאות האלה נאכפות גם עליה: אין הורדה, אין עסקאות, אין הערות פנימיות');
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['members' => false, 'download' => true, 'see_internal' => true]], $avi);
eq([code(call('POST', 'share-preview', ['case' => $C] + $toBoaz(), $dana)), code(call('POST', 'share-preview', ['case' => $C] + $toBoaz(), $tal)), code($revoke($tal, $S1)), $srow($S1)['revoked_at']],
    ['forbidden', 'forbidden', 'forbidden', null], 'בלי members בתיק — אין שיתוף ואין ביטול (403 למשתתף בתיק)');

// ══ קלט: נמען, היקף, תוקף ════════════════════════════════════════
$pvc = fn(array $p, string $as = '') => code(call('POST', 'share-preview', ['case' => $C] + $p, $as ?: $GLOBALS['t_avi']));
$GLOBALS['t_avi'] = $avi;
eq([$pvc($toBoaz(['email' => 'nobody@example.com'])), APR_ERRORS['share_user_not_found'][1], $pvc($toBoaz(['email' => 'avi@example.com'])), $pvc($toBoaz(['email' => 'dana@example.com'])), $pvc($toBoaz(['email' => 'לא-מייל'])),
    $pvc($toBoaz(['days' => 45])), $pvc($toBoaz(['days' => '30'])), $pvc(array_diff_key($toBoaz(), ['days' => 0])), $pvc($toBoaz(['scope' => 'everything'])), $pvc($toBoaz(['scope' => 'items', 'items' => []])),
    $pvc($toBoaz(['scope' => 'items', 'items' => [['type' => 'file', 'pid' => $G1]]])), $pvc($toBoaz(['scope' => 'items', 'items' => [['type' => 'secret', 'pid' => null]]])),
    $pvc($toBoaz(['scope' => 'items', 'items' => [['type' => 'comps', 'pid' => $K1]]])), $pvc($toBoaz(['scope' => 'items', 'items' => [['type' => 'file', 'pid' => $P1f]]])), $pvc($toBoaz(['kind' => 'client'])),
    $pvc($toBoaz(['perms' => ['download' => 'yes']])), call('POST', 'share-preview', ['case' => $C2] + $toBoaz(), $dana)[0], call('POST', 'share-preview', ['case' => $CB] + $toBoaz(), $avi)[0]],
    ['share_user_not_found', 'לא נמצא משתמש בכתובת הזו. הוא צריך להירשם למערכת קודם.', 'share_self', 'share_same_office', 'email_invalid', 'share_expiry', 'share_expiry', 'share_expiry', 'share_invalid', 'share_items_required',
        'share_invalid', 'share_invalid', 'share_invalid', 'share_invalid', 'share_invalid', 'share_invalid', 404, 404],
    '§3 הקלט: מייל בלי חשבון (בנוסח האפיון), עצמי, חבר המשרד, תוקף שאינו מהרשימה, היקף לא מוכר, פריטים ריקים, פריט של תיק אחר, סוג לא מוכר, עסקה בודדת, תמונת ביקור כ"קובץ" — נדחים; תיק שאינני משתתף בו ותיק של משרד אחר — 404');
call('POST', 'sys-user-status', ['user' => apr_val('SELECT pid FROM users WHERE email = ?', ['zvi@example.com']), 'status' => 'suspended', 'reason' => 'בדיקה'], $owner);
eq($pvc($toBoaz(['email' => 'zvi@example.com'])), 'share_user_not_found', 'משתמש מושעה — כמו כתובת בלי חשבון');
call('POST', 'sys-user-status', ['user' => apr_val('SELECT pid FROM users WHERE email = ?', ['zvi@example.com']), 'status' => 'active', 'reason' => 'בדיקה'], $owner);
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'share-lookup:%'");
$codes = [];
for ($i = 0; $i < 32; $i++) $codes[] = $pvc($toBoaz(['email' => "probe$i@example.com"]));
eq([array_count_values($codes)['share_user_not_found'] ?? 0, $codes[31]], [30, 'share_rate'], 'חיפוש נמען לפי מייל הוא שאלה על קיום חשבון — מוגבל ל-30 בשעה למשתמש');
apr_exec("DELETE FROM login_attempts WHERE key LIKE 'share-lookup:%'");

// ══ 05-A3, 05-C3: שיתוף פריטים ═══════════════════════════════════
$it = $share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'file', 'pid' => $F1]], 'perms' => ['download' => true, 'see_internal' => true]]));
eq([$it[0], apr_rows('SELECT item_type, item_pid FROM case_share_items WHERE share_id = ?', [$srow($S1)['id']])], [200, [['item_type' => 'file', 'item_pid' => $F1]]], 'השיתוף עודכן לקובץ אחד');
eq([$g($boaz, 'file', ['file' => $F1])[0], $g($boaz, 'file', ['file' => $F1, 'inline' => '1'])[0], $sc($g($boaz, 'file', ['file' => $F2])), $sc($g($boaz, 'file', ['file' => $F2, 'inline' => '1'])), $sc($g($boaz, 'file-thumb', ['file' => $F3])),
    $sc($g($boaz, 'file', ['file' => $F3])), $sc($g($boaz, 'file', ['file' => $P1f])), $sc($g($boaz, 'visit-photo', ['photo' => $P1])), $sc($g($boaz, 'file', ['file' => $G1])), $sc($g($boaz, 'file-facts', ['file' => $F1]))],
    [200, 200, [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found']],
    '05-A3 שיתוף של קובץ אחד: הקובץ נגיש (תצוגה והורדה); קובץ אחר באותו תיק, תמונה ממוזערת שלו, תמונת ביקור וקובץ של תיק אחר — 404, גם בהורדה ישירה לפי pid');
$dv = $g($boaz, 'case-docs', ['case' => $C]);
$cv = $g($boaz, 'case', ['case' => $C]);
$pts = $points($boaz);
eq([$dv[1]['requirements'], array_column($dv[1]['unassigned'], 'name'), $leaks($json($dv), ['נסתר', 'תמונה.jpg', 'נסח טאבו', 'הסכם מכר', ...$SECRETS]), array_keys($cv[1]['case']), $cv[1]['identifiers'], $cv[1]['parties'], $leaks($json($cv), $SECRETS),
    array_keys(array_filter($cv[1]['shared']['areas'])), $open($pts)],
    [[], ['גלוי.pdf'], [], ['pid', 'number', 'status', 'title', 'updated_at'], [], [], [], ['file', 'download', 'internal'], ['case', 'case-docs', 'file (תצוגה)', 'file (הורדה)', 'docs-search (shared)', 'cases (shared)']],
    '05-A3 שיתוף פריטים: ברשימה רק הקובץ ששותף (בלי הדרישה שלו); כרטיס התיק — כותרת בלבד, בלי מזהי נכס וגורמים; ביקורים, מקורות, נתונים (גם עם see_internal) ועסקאות — 404; מוצגת רק לשונית שיש בה פריט');
$res = $g($boaz, 'docs-search', ['q' => 'מילת-חיפוש', 'shared' => '1'])[1]['results'];
$res2 = $g($boaz, 'docs-search', ['q' => 'נסתר', 'shared' => '1'])[1]['results'];
$res3 = $g($boaz, 'docs-search', ['q' => 'הסכם', 'shared' => '1'])[1]['results'];
$res4 = $g($boaz, 'docs-search', ['q' => 'pdf', 'shared' => '1', 'case' => $C2])[1]['results'];
eq([array_values(array_unique(array_column($res, 'file'))), array_column($res, 'type'), str_contains(json_encode($res, JSON_UNESCAPED_UNICODE), 'הנסתר'), $res2, $res3, $res4, array_values(array_unique(array_column($g($boaz, 'docs-search', ['q' => 'גלוי', 'shared' => '1'])[1]['results'], 'file'))),
    count($g($avi, 'docs-search', ['q' => 'מילת-חיפוש'])[1]['results'])],
    [[$F1], ['text'], false, [], [], [], [$F1], 2],
    '05-C3 החיפוש של שותף פריטים: התאמה בטקסט של הקובץ ששותף — כן; התאמה בטקסט, בשם או בדרישה של פריט שלא שותף — לא, גם לא בתיק אחר; משתתף פנימי רואה את שתיהן');
// סוג שלם: קבצים שיתווספו אחר כך — נכללים (§8.6); פריט שנמחק — השורה נשארת ואינה מחזירה דבר (§8.5)
$pvT = call('POST', 'share-preview', ['case' => $C] + $toBoaz(['scope' => 'items', 'items' => [['type' => 'file', 'pid' => null], ['type' => 'file', 'pid' => $F1], ['type' => 'visit', 'pid' => $V1], ['type' => 'requirement', 'pid' => $R1]]]), $avi);
eq([$pvT[1]['summary']['future_items'] ?? null, array_map(fn($i) => [$i['type'], $i['pid'] === null ? null : 'x', $i['label']], $pvT[1]['summary']['items'] ?? [])],
    [true, [['requirement', 'x', 'נסח טאבו'], ['file', null, null], ['visit', 'x', '1']]], '§8.6 סוג שלם בולע את הפריטים הבודדים שלו, והתצוגה המקדימה אומרת שגם קבצים שיתווספו ייכללו');
call('POST', 'share-create', ['case' => $C, 'preview' => $pvT[1]['preview']] + $toBoaz(['scope' => 'items', 'items' => [['type' => 'file', 'pid' => null], ['type' => 'file', 'pid' => $F1], ['type' => 'visit', 'pid' => $V1], ['type' => 'requirement', 'pid' => $R1]]]), $avi);
$u5 = up($avi, $C, 'חדש-אחרי-השיתוף.pdf', pdf('five'));
$F5 = $u5[1]['file']['pid'] ?? $u5[1]['file'];
$dv = $g($boaz, 'case-docs', ['case' => $C]);
$vv = $g($boaz, 'case-visits', ['case' => $C]);
$v1 = $g($boaz, 'visit', ['visit' => $V1]);
eq([$g($boaz, 'file', ['file' => $F5, 'inline' => '1'])[0], array_column($dv[1]['requirements'], 'title'), count($dv[1]['requirements'][0]['files'] ?? []), $sc($g($boaz, 'file', ['file' => $F2])), $g($boaz, 'file', ['file' => $F2, 'inline' => '1'])[0], array_column($vv[1]['visits'], 'pid'), $vv[1]['visitors'], $vv[1]['contacts'], $vv[1]['summary']['open'] + $vv[1]['summary']['completed'],
    $v1[0], array_column($v1[1]['photos'], 'pid'), $v1[1]['others'], $v1[1]['prepared']['identifiers'], $v1[1]['prepared']['facts'], $sc($g($boaz, 'visit', ['visit' => $V2])), $sc($g($boaz, 'visit-photo', ['photo' => $P1]))[0], $sc($g($boaz, 'visit-photo', ['photo' => $P2])),
    $sc($g($boaz, 'file', ['file' => $P2f, 'inline' => '1'])), $sc($g($boaz, 'file-thumb', ['file' => $P2f])), $sc($g($boaz, 'file', ['file' => $P1f])), $leaks($json($v1) . $json($vv), $SECRETS)],
    [200, ['נסח טאבו'], 1, [403, 'forbidden'], 200, [$V1], [], [], 1, 200, [$P1], [], [], [], [404, 'not_found'], 200, [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [403, 'forbidden'], []],
    '§8.6 + ביקור: קובץ שנוסף אחרי שיתוף של "כל הקבצים" — נגיש; רק הדרישה ששותפה; רק הביקור ששותף (והסיכום סופר רק אותו — יש בתיק שניים), בלי מבקרי המשרד, אנשי הקשר והנתונים; תמונה פרטית — 404 בכל דרך (photo, file, thumb); תמונה גלויה בלי download — תצוגה בלבד');
call('POST', 'file-delete', ['file' => $F5, 'reason' => 'טעות'], $avi);
eq([$sc($g($boaz, 'file', ['file' => $F5, 'inline' => '1'])), in_array('חדש-אחרי-השיתוף.pdf', array_column($g($boaz, 'case-docs', ['case' => $C])[1]['unassigned'], 'name'), true)], [[404, 'not_found'], false], '§8.5 פריט שנמחק אחרי השיתוף — אינו מוחזר');
// בדיקת מקור כפריט
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'source_check', 'pid' => $CK]]]));
$cs = $g($boaz, 'case-sources', ['case' => $C]);
eq([array_column($cs[1]['sources'], 'key'), $cs[1]['identifiers'], $cs[1]['planning'], $g($boaz, 'source-check', ['check' => $CK])[0], $sc($g($boaz, 'source-check', ['check' => $CK2])), $sc($g($boaz, 'case-source', ['case' => $C, 'source' => 'tabu_extract'])),
    $leaks($json($cs), ['בדיקה-שלא-שותפה', ...$SECRETS]), $sc($g($boaz, 'case-docs', ['case' => $C])), $sc($g($boaz, 'case-visits', ['case' => $C]))],
    [['xplan'], [], null, 200, [404, 'not_found'], [404, 'not_found'], [], [404, 'not_found'], [404, 'not_found']],
    'שיתוף של בדיקת מקור אחת: רק המקור והבדיקה ששותפו; בדיקה אחרת ומקור אחר — 404; בלי מזהי הנכס ובלי התכנון; אין לשונית קבצים ואין לשונית ביקור');
// עסקאות כפריט: רק עם see_internal (15-C3)
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'comps', 'pid' => null]]]));
$a = $sc($g($boaz, 'case-comps', ['case' => $C]));
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'comps', 'pid' => null]], 'perms' => ['see_internal' => true]]));
$cc = $g($boaz, 'case-comps', ['case' => $C]);
eq([$a, $cc[0], $cc[1]['subject_areas'], $cc[1]['perms'], $sc($g($boaz, 'case-facts', ['case' => $C]))], [[404, 'not_found'], 200, [], ['write' => false, 'judge' => false], [404, 'not_found']],
    '15-C3 עסקאות וחישובים כפריט משותף: בלי see_internal — 404; איתה — נפתחות, בלי נתוני התיק ובלי שום פעולה');

// ══ 05-C2: התראה על קובץ חדש אינה מגיעה לשותף ════════════════════
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'file', 'pid' => $F1]]]));
$mailsBefore = count($GLOBALS['t_mail']);
$tasksBefore = (int)apr_val('SELECT COUNT(*) FROM tasks WHERE assignee_user_id = ?', [$uid('boaz@example.com')]);
up($avi, $C, 'קובץ-חדש-שלא-שותף.pdf', pdf('six'), ['requirement' => $R2]);
apr_jobs_run(5.0, 'test');
$toHim = array_filter(array_slice($GLOBALS['t_mail'], $mailsBefore), fn($m) => $m['to'] === 'boaz@example.com');
eq([count($toHim), (int)apr_val('SELECT COUNT(*) FROM tasks WHERE assignee_user_id = ?', [$uid('boaz@example.com')]) - $tasksBefore, (int)apr_val('SELECT COUNT(*) FROM case_members cm JOIN cases c ON c.id = cm.case_id WHERE cm.user_id = ? AND c.office_id <> (SELECT id FROM offices WHERE pid = ?)', [$uid('boaz@example.com'), $OB]),
    $leaks(json_encode(array_merge($g($boaz, 'my-shares', [])[1], $g($boaz, 'my-tasks', [])[1], $g($boaz, 'docs-pending', [])[1]), JSON_UNESCAPED_UNICODE), ['קובץ-חדש', 'נסתר'])],
    [0, 0, 0, []], '05-C2 קובץ חדש מחוץ להיקף של השותף: שום מייל, שום משימה ושום שורה בלוח שלו — שותף אינו שורה ב-case_members, ולכן אינו נמען של שום התראה בתיק');
$allMail = json_encode($GLOBALS['t_mail'], JSON_UNESCAPED_UNICODE);
$allAudit = json_encode(apr_rows("SELECT meta, reason FROM audit_log WHERE action LIKE 'share.%' OR action LIKE 'support.%'"), JSON_UNESCAPED_UNICODE);
eq([$leaks($allMail, ['גלוי.pdf', 'נסתר.pdf']), $leaks($allAudit, ['גלוי.pdf', 'נסתר.pdf', 'לקוח-סודי', 'boaz@example.com', 'בועז'])], [[], []], '§6 שום מייל ושום שורת יומן של שיתוף אינם נושאים שם קובץ, שם גורם או שם / מייל של הנמען (רק מזהים)');

// ══ 05-C1: ביטול — מיידי, בכל נקודה, ונרשם ═══════════════════════
$share($avi, $C, $toBoaz(['perms' => ['download' => true, 'see_internal' => true]]));
check('לפני הביטול: השותף רואה את התיק', count($open($points($boaz))) > 10);
$rv = $revoke($avi, $S1);
$lg = $log($C, 'share.revoke');
eq([$rv[0], $closed($points($boaz)), count($lg), $lg[0]['entity_pid'] ?? null, (int)($lg[0]['actor_user_id'] ?? 0) === $uid('avi@example.com'), array_diff_key($g($boaz, 'my-shares', [])[1], ['ok' => 1]), $sc($revoke($avi, $S1)), $sc($accept($boaz, $S1))],
    [200, $GONE, 1, $S1, true, ['invites' => [], 'shared' => []], [404, 'not_found'], [404, 'not_found']],
    '05-C1 אחרי ביטול: כל נקודה מסעיף 6 — 404, התיק נעלם מהרשימה ומהחיפוש של השותף, והביטול ביומן עם מי ביטל; ביטול שני ואישור מחדש — 404');
// הזמנה חדשה אחרי ביטול — שורה חדשה, ושוב ממתינה לאישור
$n2 = $share($avi, $C, $toBoaz(['days' => 30]));
$S2 = $n2[1]['share'];
eq([$n2[1]['status'], $S2 !== $S1, $closed($points($boaz)), $srow($S2)['expires_at']], ['pending', true, $GONE, apr_iso($T0 + 30 * 86400)], 'הזמנה חדשה אחרי ביטול: שורה חדשה, ממתינה לאישור — הביטול אינו "מתבטל" בעדכון');
eq([$accept($boaz, $S2, false)[0], $srow($S2)['invite_status'], $srow($S2)['revoked_at'] !== null, count($log($C, 'share.decline')), $closed($points($boaz))], [200, 'declined', true, 1, $GONE], '§3.5 השותף דוחה ← אין גישה, ונרשם');

// ══ 05-B6: תפוגה נאכפת בכל בקשה, בלי שירות רקע ═══════════════════
$S3 = $share($avi, $C, $toBoaz(['days' => 30, 'perms' => ['download' => true]]))[1]['share'];
$accept($boaz, $S3);
$GLOBALS['apr_time'] = $T0 + 30 * 86400 - 1;
$a = count($open($points($boaz)));
$GLOBALS['apr_time'] = $T0 + 30 * 86400;
eq([$a > 5, $closed($points($boaz)), $srow($S3)['revoked_at'], $g($boaz, 'my-shares', [])[1]['shared'], $g($avi, 'case-shares', ['case' => $C])[1]['shares'][0]['status'] ?? null],
    [true, $GONE, null, [], 'expired'], '05-B6 שנייה לפני expires_at — גישה; ב-expires_at — 404 בכל נקודה, **בלי** שרץ שירות רקע (השורה עוד לא סומנה); המשתף רואה "פג"');
$GLOBALS['apr_time'] = $T0 + 27 * 86400 + 60;
$tb = (int)apr_val("SELECT COUNT(*) FROM tasks WHERE link_type = 'share'");
$tick1 = apr_shares_tick();
$tick2 = apr_shares_tick();
$wt = apr_row("SELECT * FROM tasks WHERE link_type = 'share' AND link_pid = ?", [$S3]);
eq([(int)apr_val("SELECT COUNT(*) FROM tasks WHERE link_type = 'share'") - $tb, (int)$wt['assignee_user_id'] === $uid('avi@example.com'), $leaks((string)$wt['title'], ['בועז', 'boaz', 'משרד ב', 'גלוי']), $srow($S3)['revoked_at'], count($open($points($boaz))) > 5],
    [1, true, [], null, true], '§5 שלושה ימים לפני התפוגה: משימה אחת למשתף (גם בסבב שני — לא נוספת), בנוסח בלי שם של אדם, משרד או קובץ; הגישה עצמה עוד פעילה');
call('POST', 'share-preview', ['case' => $C] + $toBoaz(['days' => 90]), $avi);      // תצוגה מקדימה שלא תאושר
$GLOBALS['apr_time'] = $T0 + 31 * 86400;
$pvOpen = (int)apr_val('SELECT COUNT(*) FROM share_previews WHERE confirmed_at IS NULL');
$pvDone = (int)apr_val('SELECT COUNT(*) FROM share_previews WHERE confirmed_at IS NOT NULL');
apr_shares_tick();
eq([$srow($S3)['revoked_at'] !== null, count($log($C, 'share.expire')), $log($C, 'share.expire')[0]['actor_kind'] ?? null, $g($avi, 'case-shares', ['case' => $C])[1]['shares'],
    $pvOpen > 0, (int)apr_val('SELECT COUNT(*) FROM share_previews WHERE confirmed_at IS NULL'), (int)apr_val('SELECT COUNT(*) FROM share_previews WHERE confirmed_at IS NOT NULL') === $pvDone],
    [true, 1, 'system', [], true, 0, true], '§5 שירות הרקע רק מסמן ורושם (share.expire, בידי המערכת) — אחרי שהגישה כבר נחסמה; תצוגות מקדימות שלא אושרו מנוקות, ומה שאושר נשאר כתיעוד');
$GLOBALS['apr_time'] = $T0;

// ══ 05-B: גישת תמיכה ═════════════════════════════════════════════
$isCase = fn(string $k) => !in_array($k, ['docs-search (shared)', 'cases (shared)'], true);
$ownerPts = fn() => array_filter($points($owner), fn($k) => $isCase($k), ARRAY_FILTER_USE_KEY);
$b1 = $ownerPts();
$caseRule = array_filter($b1, fn($k) => (APR_ACCESS[explode(' ', $k)[0]] ?? '') === 'case', ARRAY_FILTER_USE_KEY);
eq([array_values(array_unique(array_map(fn($x) => $x[0] . ' ' . $x[1], $caseRule))), count($caseRule) >= 18, array_values(array_unique(array_column(array_diff_key($b1, $caseRule), 0))), $sc($g($owner, 'file', ['file' => $G1])), $sc($g($owner, 'case', ['case' => $C2])),
    $sc($g($owner, 'case', ['case' => 'aaaaaaaaaaaaaaaa'])), $g($owner, 'sys-support', [])[1]['shares'], $g($owner, 'docs-search', ['q' => 'גלוי', 'shared' => '1'])[1]['results'], $g($owner, 'cases', ['tab' => 'shared'])[1]['shared']],
    [['404 not_found'], true, [403], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [], [], []],
    '05-B1 מנהל המערכת בלי שיתוף: על כל תיק ועל כל קובץ — אותו 404 של זר ושל מזהה שלא קיים; שאר הפעולות — אינו חבר משרד (403); רשימת התמיכה והחיפוש — ריקים');
$sysTries = [];
foreach (['share-preview' => ['case' => $C] + $toSupport(), 'share-create' => ['case' => $C, 'preview' => 'aaaaaaaaaaaaaaaa', 'confirm' => 'אישור'] + $toSupport(), 'share-revoke' => ['share' => $S1], 'share-respond' => ['share' => $S1, 'accept' => true],
    'case-shares' => ['case' => $C], 'case-member-add' => ['case' => $C, 'user' => apr_val('SELECT pid FROM users WHERE email = ?', ['owner@example.com'])]] as $action => $in) {
    $sysTries[$action] = in_array($action, APR_READS, true) ? $g($owner, $action, $in)[0] : call('POST', $action, $in, $owner)[0];
}
$sysActions = array_keys(array_filter(APR_ACCESS, fn($r) => $r === 'sysadmin'));
eq([array_filter($sysTries, fn($s) => $s === 200), array_values(array_filter($sysActions, fn($a) => str_contains($a, 'share') || str_contains($a, 'support'))), in_array('sys-support', APR_READS, true), (int)apr_val("SELECT COUNT(*) FROM case_shares WHERE kind = 'support'")],
    [[], ['sys-support'], true, 0], '05-B2 אין פעולת API שבה מנהל המערכת יוצר, מאשר או מרחיב שיתוף: כל פעולות השיתוף נדחות לו, והפעולה היחידה שלו בתחום — sys-support — קריאה בלבד');
// מנהל מערכת שהוא גם משתתף בתיק (חבר במשרד א): עדיין אינו פותח גישת תמיכה
call('POST', 'sys-user-flags', ['user' => $danaPid, 'is_sysadmin' => true, 'confirm' => 'אישור'], $owner);
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['members' => true]], $avi);
eq([code(call('POST', 'share-preview', ['case' => $C] + $toSupport(), $dana)), $g($dana, 'case-shares', ['case' => $C])[1]['can_support'], call('POST', 'share-preview', ['case' => $C] + $toBoaz(), $dana)[0]], ['share_sysadmin', false, 200],
    '05-B2 גם מנהלת מערכת שהיא משתתפת עם members בתיק אינה פותחת גישת תמיכה (שיתוף רגיל עם שמאי — כן)');
call('POST', 'sys-user-flags', ['user' => $danaPid, 'is_sysadmin' => false], $owner);
call('POST', 'case-member-update', ['case' => $C, 'user' => $danaPid, 'perms' => ['members' => false]], $avi);

eq([$pvc(array_diff_key($toSupport(), ['days' => 0])), $pvc($toSupport(['days' => 0])), $pvc($toSupport(['days' => 90])), $pvc($toSupport(['items' => []])), $pvc($toSupport(['scope' => 'all'])), $pvc(array_diff_key($toSupport(), ['items' => 0])),
    $pvc($toSupport(['perms' => ['edit' => true]])), $pvc($toSupport(['perms' => ['see_internal' => true]])), $pvc($toSupport(['perms' => ['see_draft' => true]])), $pvc($toSupport(['reason' => ''])), $pvc(array_diff_key($toSupport(), ['reason' => 0])), $pvc($toSupport(['reason' => '   '])),
    $raises("INSERT INTO case_shares (pid, case_id, office_id, kind, scope, perms, invite_status, added_by, created_at, updated_at, reason) SELECT 'cccccccccccccccc', case_id, office_id, 'support', 'items', '{\"view\":true}', 'active', added_by, 'x', 'x', 'r' FROM case_shares WHERE pid = ?", [$S1]),
    $raises("INSERT INTO case_shares (pid, case_id, office_id, kind, scope, perms, invite_status, added_by, created_at, updated_at, expires_at) SELECT 'cccccccccccccccc', case_id, office_id, 'support', 'items', '{\"view\":true}', 'active', added_by, 'x', 'x', 'x' FROM case_shares WHERE pid = ?", [$S1]),
    $raises("INSERT INTO case_shares (pid, case_id, office_id, kind, scope, perms, invite_status, added_by, created_at, updated_at, expires_at, reason) SELECT 'cccccccccccccccc', case_id, office_id, 'support', 'all', '{\"view\":true}', 'active', added_by, 'x', 'x', 'x', 'r' FROM case_shares WHERE pid = ?", [$S1]),
    $raises("INSERT INTO case_shares (pid, case_id, office_id, kind, scope, perms, invite_status, added_by, created_at, updated_at, expires_at, reason) SELECT 'cccccccccccccccc', case_id, office_id, 'support', 'items', '{\"view\":true,\"see_internal\":true}', 'active', added_by, 'x', 'x', 'x', 'r' FROM case_shares WHERE pid = ?", [$S1])],
    ['share_expiry', 'share_expiry', 'share_expiry', 'share_items_required', 'share_items_required', 'share_items_required', 'share_perm', 'share_perm', 'share_perm', 'reason_required', 'reason_required', 'reason_required', true, true, true, true],
    '05-B3 שיתוף תמיכה: בלי תוקף / "ללא הגבלה" / תוקף שאינו מהרשימה ← נדחה; בלי פריטים / כל התיק ← נדחה; עם edit, see_internal או see_draft ← נדחה; בלי נימוק ← נדחה — וגם ב-SQL ישיר (CHECK וטריגר)');
$pvS = call('POST', 'share-preview', ['case' => $C] + $toSupport(), $avi);
eq([$pvS[0], code(call('POST', 'share-create', ['case' => $C, 'preview' => $pvS[1]['preview']] + $toSupport(), $avi)), (int)apr_val("SELECT COUNT(*) FROM case_shares WHERE kind = 'support'")], [200, 'confirm_required', 0],
    '§4.3 שיתוף עם התמיכה הוא פעולה רגישה: בלי אישור חוזר — נדחה');
$mkS = call('POST', 'share-create', ['case' => $C, 'preview' => $pvS[1]['preview'], 'confirm' => 'אישור'] + $toSupport(), $avi);
$SS = $mkS[1]['share'] ?? '';
eq([$mkS[0], $mkS[1]['status'] ?? null, $srow($SS)['user_id'], $srow($SS)['expires_at'], count($log($C, 'support.share')), $log($C, 'support.share')[0]['reason'] ?? null], [200, 'active', null, apr_iso($T0 + 7 * 86400), 1, 'תקלה בתצוגת הקובץ'],
    '§4 הלקוח פותח גישת תמיכה לקובץ אחד, ל-7 ימים, עם נימוק — פעילה מיד, ונרשמת ביומן של המשרד');
$nAudit = fn() => count($log($C, 'support.view'));
$n0 = $nAudit();
$sp = $ownerPts();
eq([$open($sp), $sp['file (הורדה)'], $sp['file-thumb'], $sc($g($owner, 'file', ['file' => $F2, 'inline' => '1'])), $sc($g($owner, 'case', ['case' => $C2])), $sc($g($owner, 'file', ['file' => $G1]))],
    [['case', 'case-docs', 'file (תצוגה)'], [403, 'forbidden'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found'], [404, 'not_found']],
    '§4 התמיכה רואה רק את מה ששותף: כותרת התיק, רשימה עם הקובץ האחד ותצוגה שלו; בלי download — אין הורדה; קובץ אחר, תיק אחר וכל שאר הנקודות — 404');
$views = array_slice($log($C, 'support.view'), $n0);
eq([count($views), array_values(array_unique(array_map(fn($v) => (int)$v['actor_user_id'], $views))) === [$uid('owner@example.com')], array_values(array_unique(array_map(fn($v) => (int)$v['office_id'], $views))) === [(int)apr_val('SELECT id FROM offices WHERE pid = ?', [$OA])],
    array_map(fn($v) => [$v['entity_type'], $v['entity_pid'], json_decode($v['meta'], true)['action']], $views)],
    [4, true, true, [['case', $C, 'case'], ['case', $C, 'case-docs'], ['file', $F1, 'file'], ['case', $C, 'docs-search']]],
    '05-B5 כל צפייה של התמיכה שנענתה נרשמת ביומן של המשרד הבעלים (support.view): מי, באיזה פריט, ובאיזו פעולה; בקשה שנדחתה (404 / 403) אינה נרשמת כצפייה');
$sv = $g($avi, 'case-shares', ['case' => $C])[1];
$act = $g($avi, 'case-activity', ['case' => $C])[1]['rows'];
eq([count($sv['views']), $sv['views'][0]['by'] ?? null, array_values(array_filter(array_column($sv['views'], 'label'))), count(array_filter($act, fn($r) => $r['action'] === 'support.view')), $sv['can_support']], [4, 'הבעלים', ['גלוי.pdf'], 4, true],
    '05-D3 (שרת) הלקוח רואה מי מהתמיכה צפה, במה ומתי — ב"גורמים והרשאות" ובלשונית הפעילות');
$ss = $g($owner, 'sys-support', [])[1]['shares'];
eq([count($ss), $ss[0]['case']['pid'] ?? null, $ss[0]['office'] ?? null, $ss[0]['items'] ?? null, $ss[0]['reason'] ?? null, $ss[0]['expires_at'] ?? null, $sc($g($avi, 'sys-support', []))],
    [1, $C, 'משרד א', 1, 'תקלה בתצוגת הקובץ', apr_iso($T0 + 7 * 86400), [404, 'not_found']], '§4.4 מנהל המערכת רואה ב-sys-support את התיקים ששותפו איתו, עם ההיקף, הנימוק והתוקף; למי שאינו מנהל מערכת הפעולה אינה קיימת');
eq([$sc($g($boaz, 'case', ['case' => $C])), $sc($g($bella, 'file', ['file' => $F1, 'inline' => '1']))], [[404, 'not_found'], [404, 'not_found']], 'שורת התמיכה פותחת רק למנהל מערכת — לא לשום משתמש אחר');
// §8.10: ניסיון להעניק edit לתמיכה דרך ה-API; והרחבה — רק בפעולה חדשה של הלקוח
$ex = $share($avi, $C, $toSupport(['perms' => ['download' => true], 'days' => 30, 'items' => [['type' => 'file', 'pid' => $F1], ['type' => 'file', 'pid' => $F2]]]));
eq([$ex[0], $ex[1]['updated'] ?? null, (int)apr_val("SELECT COUNT(*) FROM case_shares WHERE kind = 'support' AND revoked_at IS NULL"), $g($owner, 'file', ['file' => $F2])[0], count($log($C, 'support.update'))], [200, true, 1, 200, 1],
    '§4 הארכה והרחבה — בפעולה חדשה של הלקוח, עם תצוגה מקדימה ואישור חוזר: שורת התמיכה מתעדכנת (אחת לתיק)');
// תפוגה וביטול של תמיכה
$GLOBALS['apr_time'] = $T0 + 30 * 86400;
$exp = $ownerPts();
$GLOBALS['apr_time'] = $T0;
$rvS = $revoke($avi, $SS);
eq([array_values(array_unique(array_map(fn($x) => $x[0], array_intersect_key($exp, $caseRule)))), $rvS[0], array_values(array_unique(array_map(fn($x) => $x[0] . ' ' . $x[1], array_intersect_key($ownerPts(), $caseRule)))), count($log($C, 'support.revoke')), $g($owner, 'sys-support', [])[1]['shares']],
    [[404], 200, ['404 not_found'], 1, []], '05-B6 + 05-C1 גישת תמיכה: אחרי expires_at — 404 בלי שירות רקע; אחרי ביטול — 404 בכל נקודה, והביטול ביומן');
// §8.8: מנהל מערכת שהוזמן כשותף — שותף רגיל, וכללי התמיכה אינם חלים
$share($avi, $C, $toSupport());
$so = $share($avi, $C, ['kind' => 'cross_office', 'email' => 'owner@example.com', 'scope' => 'all', 'perms' => ['download' => true], 'days' => 0]);
$accept($owner, $so[1]['share']);
$n0 = $nAudit();
$po = $ownerPts();
eq([$po['file (הורדה)'][0], $g($owner, 'file', ['file' => $F2, 'inline' => '1'])[0], $po['case-visits'][0], $nAudit() - $n0, $g($owner, 'case', ['case' => $C])[1]['shared']['kind'], (int)apr_val('SELECT COUNT(*) FROM case_shares WHERE case_id = ? AND revoked_at IS NULL', [$caseId($C)])],
    [200, 200, 200, 0, 'cross_office', 2], '§8.8 מנהל מערכת שהוזמן כשותף ממשרד אחר: השורה שלו גוברת על שורת התמיכה — הוא שותף רגיל (לפי perms שלו), וצפיותיו אינן "צפיות תמיכה"');
$revoke($avi, $so[1]['share']);
$revoke($avi, (string)apr_val("SELECT pid FROM case_shares WHERE kind = 'support' AND revoked_at IS NULL"));

// ══ בינה: שותף אינו מפעיל ואינו רואה שימוש (11 §12.7) ════════════
$S7 = $share($avi, $C, $toBoaz(['perms' => ['download' => true, 'see_internal' => true, 'see_draft' => true]]))[1]['share'];
$accept($boaz, $S7);
$officeAId = (int)apr_val('SELECT id FROM offices WHERE pid = ?', [$OA]);
$rate0 = (int)apr_val("SELECT COALESCE(SUM(count), 0) FROM login_attempts WHERE key LIKE 'ai-extract:%'");
$runs0 = (int)apr_val('SELECT COUNT(*) FROM ai_runs WHERE office_id = ? OR case_id IN (SELECT id FROM cases WHERE office_id = ?)', [$officeAId, $officeAId]);
$aiTries = [$sc(call('POST', 'file-ai-run', ['file' => $F1, 'what' => 'summary', 'key' => 'k-12345678'], $boaz)), $sc($g($boaz, 'file-ai', ['file' => $F1])), $sc($g($boaz, 'case-ai', ['case' => $C])),
    $sc(call('POST', 'case-ai-set', ['case' => $C, 'no_ai' => true], $boaz)), $sc(call('POST', 'case-ai-auto-set', ['case' => $C, 'auto_extract' => false], $boaz))];
$GLOBALS['apr_action'] = 'case';       // גם אם קוד עתידי יקרא ל-ai_run מתוך פעולה שפתוחה לשיתוף
$ctxB = apr_ctx(['platform_user' => $boaz, 'cookie' => jar()[$boaz] ?? '', 'ua' => 'test']);
try { $direct = ai_run(['ctx' => $ctxB, 'case' => $C, 'purpose' => 'extract.fields', 'content' => [['type' => 'text', 'text' => 'x']], 'max_output' => 100, 'op' => 'op-share-1']); $direct = [$direct['status'], $direct['block_reason']]; }
catch (AprError $e) { $direct = [$e->status, $e->errorCode]; }
unset($GLOBALS['apr_action']);
eq([array_values(array_unique(array_map(fn($x) => $x[0] . ' ' . $x[1], $aiTries))), $direct,
    (int)apr_val('SELECT COUNT(*) FROM ai_runs WHERE office_id = ? OR case_id IN (SELECT id FROM cases WHERE office_id = ?)', [$officeAId, $officeAId]) - $runs0, (int)apr_val("SELECT COUNT(*) FROM ai_runs WHERE status <> 'blocked'"),
    (int)apr_val("SELECT COALESCE(SUM(count), 0) FROM login_attempts WHERE key LIKE 'ai-extract:%'") - $rate0],
    [['404 not_found'], ['blocked', 'no_assignment'], 0, 0, 0],
    '11 §12.7 שותף חיצוני (גם עם כל ההרשאות): הפעלת בינה, הצעות, שימוש ומכסה של התיק — 404; וקריאה ישירה ל-ai_run נחסמת (no_assignment) בלי שום הפעלה על חשבון המשרד הבעלים, ובלי לצרוך מתקרת הקצב שלו (החסימה לפני הספירה)');
$revoke($avi, $S7);

// ══ §8: מקרי קצה ═════════════════════════════════════════════════
$S4 = $share($avi, $C, $toBoaz(['perms' => ['download' => true]]))[1]['share'];
$accept($boaz, $S4);
// 8.2: השותף הושעה במערכת
$boazPid = (string)apr_val('SELECT pid FROM users WHERE email = ?', ['boaz@example.com']);
call('POST', 'sys-user-status', ['user' => $boazPid, 'status' => 'suspended', 'reason' => 'בדיקה'], $owner);
$a = $sc($g($boaz, 'case', ['case' => $C]));
call('POST', 'sys-user-status', ['user' => $boazPid, 'status' => 'active', 'reason' => 'בדיקה'], $owner);
eq([$a, $g($boaz, 'case', ['case' => $C])[0]], [[403, 'account_suspended'], 200], '§8.2 השותף הושעה במערכת ← אין גישה (ההשעיה גוברת); אחרי שחזור — השיתוף עדיין שם');
// 8.3: המשרד הבעלים הושעה
call('POST', 'sys-office-status', ['confirm' => 'אישור', 'office' => $OA, 'status' => 'suspended', 'reason' => 'בדיקה'], $owner);
$a = [$closed($points($boaz)), $g($boaz, 'my-shares', [])[1]['shared'], $sc($g($avi, 'case', ['case' => $C]))];
call('POST', 'sys-office-status', ['office' => $OA, 'status' => 'active', 'reason' => 'בדיקה'], $owner);
eq([$a, $g($boaz, 'case', ['case' => $C])[0]], [[$GONE, [], [403, 'office_suspended']], 200], '§8.3 המשרד הבעלים הושעה ← אין גישה לאף אחד, כולל השותף (404, והתיק נעלם מהרשימה שלו)');
// 8.1: השותף עזב את המשרד שלו (או שהמשרד שלו הושעה) — השיתוף הוא לאדם
call('POST', 'sys-office-status', ['confirm' => 'אישור', 'office' => $OB, 'status' => 'suspended', 'reason' => 'בדיקה'], $owner);
$a = [$g($boaz, 'case', ['case' => $C])[0], $g($boaz, 'file', ['file' => $F1])[0], $sc($g($boaz, 'case', ['case' => $CB])), $sc($g($bella, 'case', ['case' => $C]))];
call('POST', 'sys-office-status', ['office' => $OB, 'status' => 'active', 'reason' => 'בדיקה'], $owner);
call('POST', 'member-update', ['user' => $boazPid, 'is_admin' => true], $boaz);
$bellaPid = (string)apr_val('SELECT pid FROM users WHERE email = ?', ['bella@example.com']);
call('POST', 'member-update', ['user' => $bellaPid, 'is_admin' => true], $boaz);
$leave = call('POST', 'office-leave', [], $boaz);
$sh8 = $g($avi, 'case-shares', ['case' => $C])[1]['shares'][0] ?? [];
eq([$a, $leave[0], $g($boaz, 'me', [])[1]['office'], $g($boaz, 'case', ['case' => $C])[0], $g($boaz, 'cases', ['tab' => 'shared'])[1]['shared'][0]['case']['pid'] ?? null, $sh8['left_office'] ?? null, $sh8['office'] ?? null, $sc($g($boaz, 'cases', []))],
    [[200, 200, [403, 'office_suspended'], [403, 'office_suspended']], 200, null, 200, $C, true, 'משרד ב <b>מודגש</b>', [403, 'no_office']],
    '§8.1 המשרד של השותף הושעה, ואחר כך השותף עזב אותו: השיתוף נשאר (הוא לאדם) ופועל גם בלי משרד פעיל; המשתף רואה "אינו עוד במשרד <שם>"; רשימת תיקי המשרד שלו — אין');
// שותף בלי משרד אינו מקבל דבר מעבר לתיק המשותף
eq([$closed($points($zvi)), array_diff_key($g($zvi, 'my-shares', [])[1], ['ok' => 1]), $sc($g($boaz, 'case', ['case' => $C2])), $sc($g($boaz, 'file', ['file' => $G1]))],
    [['403 no_office'], ['invites' => [], 'shared' => []], [404, 'not_found'], [404, 'not_found']], 'משתמש בלי משרד ובלי שיתוף — שום נקודה אינה נפתחת; שותף בלי משרד — תיק אחר של משרד א עדיין 404');
// 8.4: המשתף איבד את members — השיתופים שיצר נשארים, והאחראי החדש רואה ומבטל אותם
$S5 = $share($avi, $C2, $toBoaz())[1]['share'];
call('POST', 'case-member-add', ['case' => $C2, 'user' => $danaPid], $avi);
call('POST', 'case-member-update', ['case' => $C2, 'user' => $danaPid, 'role' => 'responsible', 'reason' => 'העברה'], $avi);
eq([code(call('POST', 'share-preview', ['case' => $C2] + $toBoaz(), $avi)), count($g($dana, 'case-shares', ['case' => $C2])[1]['shares']), $g($avi, 'case-shares', ['case' => $C2])[1]['can_manage'], $revoke($dana, $S5)[0]], ['forbidden', 1, false, 200],
    '§8.4 המשתף איבד את members: השיתוף שיצר נשאר; האחראית החדשה רואה אותו ומבטלת');
// 8.9: תפוגה באמצע עבודה — הבקשה הבאה 404
$S6 = $share($avi, $C, $toBoaz(['days' => 30]))[1]['share'];
eq($S6 === $S4, true, 'עדכון התוקף של שיתוף פעיל — אותה שורה');
$GLOBALS['apr_time'] = $T0 + 30 * 86400 + 1;
eq([$sc($g($boaz, 'case-docs', ['case' => $C])), $sc($g($boaz, 'file', ['file' => $F1, 'inline' => '1']))], [[404, 'not_found'], [404, 'not_found']], '§8.9 תפוגה באמצע עבודה: הבקשה הבאה — 404');
$GLOBALS['apr_time'] = $T0;
$revoke($avi, $S4);

// שיתוף אינו נפתח בתיק בטיוטה, ותיק סגור נשאר קריא לשותף
$draft = call('POST', 'case-create', ['profile' => $PA], $avi)[1]['case'];
eq(code(call('POST', 'share-preview', ['case' => $draft] + $toBoaz(), $avi)), 'case_state', 'תיק בטיוטה אינו משותף');

// ══ ביקורת סבב 1: דליפות בתוך התיק המשותף, ותנאים שלא נבדקו ══════
$GLOBALS['t_share_rate'] = fn() => apr_exec("DELETE FROM login_attempts WHERE key LIKE 'share-lookup:%'");      // הגבלת הקצב של חיפוש נמען נבדקה למעלה
($GLOBALS['t_share_rate'])();
$contactPid = (string)apr_val('SELECT ct.pid FROM case_parties cp JOIN contacts ct ON ct.id = cp.contact_id WHERE cp.case_id = ?', [$caseId($C)]);
apr_exec("UPDATE contacts SET phone = '050-7654321' WHERE pid = ?", [$contactPid]);
$vu = call('POST', 'visit-update', ['visit' => $V1, 'rev' => (int)apr_val('SELECT rev FROM visits WHERE pid = ?', [$V1]), 'access_contact' => $contactPid, 'access_note' => 'הערת-גישה-סודית'], $avi);
apr_exec("UPDATE visits SET attendees = 'נוכח-סודי', status_note = 'הערת-מצב-סודית', rev = rev + 1 WHERE pid = ?", [$V1]);
call('POST', 'visit-start', ['visit' => $V2, 'rev' => (int)apr_val('SELECT rev FROM visits WHERE pid = ?', [$V2])], $avi);
$VSEC = ['הערת-גישה-סודית', 'נוכח-סודי', 'הערת-מצב-סודית', '050-7654321', $contactPid, $aviPid];
// B1 — ביקור ששותף כפריט, view בלבד
$SB = $share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'visit', 'pid' => $V1]]]))[1]['share'];
$accept($boaz, $SB);
$lv = $g($boaz, 'case-visits', ['case' => $C]);
$vw = $g($boaz, 'visit', ['visit' => $V1]);
$inside = $g($avi, 'visit', ['visit' => $V1])[1];
eq([$vu[0], $lv[0], $vw[0], $leaks($json($lv) . $json($vw), $VSEC), $vw[1]['visit']['access_contact'], $vw[1]['visit']['visitor'], $lv[1]['visits'][0]['access_contact'], $vw[1]['others'],
    $inside['visit']['access_contact']['phone'] ?? null, $inside['visit']['access_note'], count($inside['others'])],
    [200, 200, 200, [], null, ['pid' => null, 'name' => 'אבי'], null, [], '050-7654321', 'הערת-גישה-סודית', 1],
    '🔴 B1 ביקור משותף: איש הקשר לגישה (שם, טלפון, מזהה), הערת הגישה, הנוכחים, הערת המצב ומזהה המבקר אינם בתשובה — לא ברשימה ולא במסך; ביקור אחר שלא שותף אינו ב-others. משתתף פנימי רואה הכול');
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'visit', 'pid' => $V1]], 'perms' => ['see_internal' => true]]));
$vw = $g($boaz, 'visit', ['visit' => $V1]);
eq([$vw[1]['visit']['access_note'], $vw[1]['visit']['attendees'], $vw[1]['visit']['access_contact'], $leaks($json($vw), ['050-7654321', $contactPid, $aviPid])], ['הערת-גישה-סודית', 'נוכח-סודי', null, []],
    '🔴 B1 עם see_internal: הערת הגישה והנוכחים — כן; איש הקשר, הטלפון והמזהים — לעולם לא');

// B2 — עסקאות כפריט + see_internal: נתוני הנכס אינם יוצאים דרך הקבוצה או דרך "דומה / שונה"
call('POST', 'fact-add', ['case' => $C, 'attribute' => 'floor', 'value' => '4', 'source' => ['kind' => 'user', 'note' => 'קומה-מהנסח-סודי']], $avi);
call('POST', 'comp-correct', ['comp' => $K1, 'rev' => (int)apr_val('SELECT rev FROM comps WHERE pid = ?', [$K1]), 'changes' => ['floor' => '2'], 'reason' => 'תיקון'], $avi);
$gs = call('POST', 'comp-set-save', ['case' => $C, 'unit_basis' => 'per_sqm', 'area_kind' => 'registered', 'method' => 'mean', 'rounding' => 'none', 'subject_area_fact' => $FA], $avi);
$GS = $gs[1]['set']['pid'] ?? ($gs[1]['set'] ?? null);
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'comps', 'pid' => null]], 'perms' => ['see_internal' => true]]));
$bs = $g($boaz, 'comp-set', ['set' => $GS]);
$bk = $g($boaz, 'comp', ['comp' => $K1]);
$bl = $g($boaz, 'case-comps', ['case' => $C]);
$as = $g($avi, 'comp-set', ['set' => $GS]);
$ak = $g($avi, 'comp', ['comp' => $K1]);
eq([$gs[0], $bs[0], $bk[0], $bs[1]['subject']['fact'], $bs[1]['subject_areas'], $bk[1]['similar'], array_key_exists('fact', $bl[1]['sets'][0]['subject'] ?? []) ? $bl[1]['sets'][0]['subject']['fact'] : 'x', $leaks($json($bs) . $json($bk) . $json($bl), ['מהנסח', 'קומה-מהנסח-סודי', $FA]),
    ($as[1]['subject']['fact']['pid'] ?? null) === $FA, count($as[1]['subject_areas']) > 0, count($ak[1]['similar']['rows']) + count($ak[1]['similar']['unknown']) > 0],
    [200, 200, 200, null, [], ['rows' => [], 'unknown' => []], null, [], true, true, true],
    '🔴 B2 עסקאות כפריט (עם see_internal, בלי נתוני התיק): רשומת הנתון של השטח הנישום, רשימת נתוני השטח ו"דומה / שונה" מול הנכס אינם בתשובה — ב-comp-set, ב-comp וב-case-comps. משתתף פנימי רואה אותם');

// B3 — מי העלה, הערת המקור והערת הבדיקה: גם בקובץ שמשויך לדרישה, וגם לתמיכה
$linkF1 = (string)apr_val('SELECT rf.pid FROM requirement_files rf JOIN files f ON f.id = rf.file_id WHERE f.pid = ?', [$F1]);
call('POST', 'file-update', ['file' => $F1, 'source_note' => 'הערת-מקור-סודית'], $avi);
call('POST', 'file-update', ['file' => $F3, 'source_note' => 'הערת-מקור-סודית'], $avi);
$rvw = call('POST', 'file-review', ['link' => $linkF1, 'do' => 'approve', 'chk_readable' => true, 'chk_complete' => true, 'chk_matches' => true, 'note' => 'הערת-בדיקה-סודית'], $avi);
$share($avi, $C, $toBoaz());
$bd = $g($boaz, 'case-docs', ['case' => $C]);
$ad = $g($avi, 'case-docs', ['case' => $C]);
$share($avi, $C, $toSupport(['items' => [['type' => 'file', 'pid' => $F1], ['type' => 'requirement', 'pid' => $R1], ['type' => 'file', 'pid' => $F3]]]));
$od = $g($owner, 'case-docs', ['case' => $C]);
$req1 = fn(array $d) => array_values(array_filter($d[1]['requirements'], fn($r) => $r['title'] === 'נסח טאבו'))[0];
$ISEC = ['הערת-מקור-סודית', 'הערת-בדיקה-סודית', 'אבי'];
eq([$rvw[0], $leaks($json($bd), $ISEC), $leaks($json($od), $ISEC), $req1($bd)['files'][0]['file']['uploader'], $req1($bd)['files'][0]['reviewed_by'], $req1($bd)['party'], $req1($od)['files'][0]['file']['source_note'], count($od[1]['unassigned']),
    $leaks($json($ad), $ISEC), $req1($ad)['party'] !== null],
    [200, [], [], null, null, null, null, 1, $ISEC, true],
    '🔴 B3 בלי see_internal: מי העלה, הערת המקור, הערת הבדיקה, שם הבודק והגורם של הדרישה אינם בתשובה — בקובץ שמשויך לדרישה ובקובץ שאינו משויך, לשותף ולתמיכה. משתתף פנימי רואה אותם');
$share($avi, $C, $toBoaz(['perms' => ['see_internal' => true]]));
eq($leaks($json($g($boaz, 'case-docs', ['case' => $C])), $ISEC), $ISEC, 'עם see_internal — ההערות הפנימיות של הקבצים בתשובה');
// דרישה ששותפה בלי הקובץ שלה: הקישור לקובץ אינו בתשובה
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'requirement', 'pid' => $R2]]]));
$bd = $g($boaz, 'case-docs', ['case' => $C]);
eq([array_column($bd[1]['requirements'], 'title'), $bd[1]['requirements'][0]['files'] ?? 'x', $bd[1]['unassigned'], $leaks($json($bd), ['נסתר', $F2])], [['הסכם מכר'], [], [], []],
    'דרישה ששותפה בלי הקובץ שלה: הדרישה בתשובה, הקישורים לקבצים שלא שותפו — לא (גם לא שם או מזהה)');

// 🟡4 — סתירות והיסטוריה של נתון אינן לשותף
call('POST', 'fact-add', ['case' => $C, 'attribute' => 'area', 'value' => '99', 'unit' => 'sqm', 'area_kind' => 'registered', 'source' => ['kind' => 'user', 'note' => 'סותר']], $avi);
$share($avi, $C, $toBoaz(['perms' => ['see_internal' => true]]));
$bf = $g($boaz, 'case-facts', ['case' => $C]);
$b1 = $g($boaz, 'fact', ['fact' => $FA]);
$af = $g($avi, 'case-facts', ['case' => $C]);
eq([$bf[0], $bf[1]['conflicts_open'], array_sum(array_map(fn($a) => count($a['conflicts']), $bf[1]['attributes'])), $b1[1]['history'], $b1[1]['conflicts'], $af[1]['conflicts_open'] > 0, count($g($avi, 'fact', ['fact' => $FA])[1]['history']) > 0],
    [200, 0, 0, [], [], true, true], '🟡4 נתונים לשותף (כל התיק + see_internal): בלי ספירת סתירות, בלי מזהי סתירות ובלי היסטוריית הנתון; משתתף פנימי רואה אותן');

// בדיקת מקור ששותפה: בלי הנתונים, פריטי התכנון והמשימות שנגזרו ממנה
apr_insert('tasks', ['pid' => apr_pid(), 'office_id' => $officeAId, 'case_id' => $caseId($C), 'kind' => 'system', 'title' => 'משימת-בדיקה-סודית', 'link_type' => 'source_check', 'origin_key' => 'source-x:xplan', 'created_at' => 'x', 'updated_at' => 'x']);
$pl = call('POST', 'planning-add', ['case' => $C, 'kind' => 'plan', 'title' => 'תוכנית-סודית', 'check' => $CK], $avi);
$fk = call('POST', 'fact-add', ['case' => $C, 'attribute' => 'floor', 'value' => '7', 'source' => ['kind' => 'source_check', 'ref' => $CK]], $avi);
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'source_check', 'pid' => $CK]], 'perms' => ['see_internal' => true]]));
$bc = $g($boaz, 'source-check', ['check' => $CK]);
$ac = $g($avi, 'source-check', ['check' => $CK]);
eq([$bc[0], $bc[1]['facts'], $bc[1]['items'], $bc[1]['tasks'], $leaks($json($bc), ['משימת-בדיקה-סודית', 'תוכנית-סודית']), count($ac[1]['tasks']), count($ac[1]['items']) === ($pl[0] === 200 ? 1 : 0), count($ac[1]['facts']) === ($fk[0] === 200 ? 1 : 0)],
    [200, [], [], [], [], 1, true, true], 'בדיקת מקור ששותפה כפריט: הנתונים, פריטי התכנון והמשימות שנגזרו ממנה אינם בתשובה; משתתף פנימי רואה אותם');
check('ההכנה של בדיקת המקור: לפחות אחד מפריט התכנון והנתון נוצר', $pl[0] === 200 || $fk[0] === 200, [$pl[1], $fk[1]]);

// 🟡1 — חיפוש של התמיכה שנוגע בשני תיקים: שורה לכל תיק
($GLOBALS['t_share_rate'])();
apr_exec("INSERT INTO file_texts (file_id, page, text, method, created_at) SELECT id, 1, 'מילת-חיפוש-ייחודית בתיק השני', 'pdf_text', 'x' FROM files WHERE pid = ?", [$G1]);
$share($dana, $C2, ['kind' => 'support', 'scope' => 'items', 'items' => [['type' => 'file', 'pid' => $G1]], 'perms' => [], 'days' => 7, 'reason' => 'גם התיק השני']);
$n1 = count($log($C, 'support.view'));
$n2 = count($log($C2, 'support.view'));
$hits = $g($owner, 'docs-search', ['q' => 'מילת-חיפוש', 'shared' => '1'])[1]['results'];
$sup = $g($owner, 'sys-support', [])[1]['shares'];
eq([array_values(array_unique(array_column(array_column($hits, 'case'), 'pid'))) == [$C2, $C] || array_values(array_unique(array_column(array_column($hits, 'case'), 'pid'))) == [$C, $C2], count($log($C, 'support.view')) - $n1, count($log($C2, 'support.view')) - $n2,
    count($sup), (int)apr_val("SELECT COUNT(*) FROM case_shares WHERE revoked_at IS NULL AND kind = 'cross_office' AND invite_status = 'active'") >= 1],
    [true, 1, 1, 2, true], '🟡1 חיפוש של התמיכה שהחזיר תוצאות משני תיקים רושם support.view בכל אחד מהם (ביומן של המשרד שלו); sys-support מציג רק שורות תמיכה — לא שיתופים של שמאים');
$revoke($avi, (string)apr_val("SELECT pid FROM case_shares WHERE kind = 'support' AND revoked_at IS NULL AND case_id = ?", [$caseId($C)]));
$revoke($dana, (string)apr_val("SELECT pid FROM case_shares WHERE kind = 'support' AND revoked_at IS NULL AND case_id = ?", [$caseId($C2)]));

// 🟡2 — הרעבת חיפוש: מאות התאמות בקובץ נסתר וחדש יותר אינן דוחקות את ההתאמה הגלויה
apr_exec("INSERT INTO file_texts (file_id, page, text, method, created_at) WITH RECURSIVE n(i) AS (SELECT 2 UNION ALL SELECT i + 1 FROM n WHERE i < 305) SELECT f.id, n.i, 'מילת-חיפוש-ייחודית בעמוד ' || n.i, 'pdf_text', 'x' FROM files f, n WHERE f.pid = ?", [$F2]);
for ($i = 0; $i < 210; $i++) apr_insert('requirements', ['pid' => apr_pid(), 'office_id' => $officeAId, 'case_id' => $caseId($C), 'origin' => 'manual', 'doc_type' => 'other', 'title' => "דרישת-הצפה $i", 'created_at' => 'x', 'updated_at' => 'x']);
call('POST', 'requirement-update', ['requirement' => $R1, 'title' => 'דרישת-הצפה ישנה וגלויה'], $avi);
$share($avi, $C, $toBoaz(['scope' => 'items', 'items' => [['type' => 'file', 'pid' => $F1], ['type' => 'requirement', 'pid' => $R1]]]));
$st = $g($boaz, 'docs-search', ['q' => 'מילת-חיפוש', 'shared' => '1'])[1]['results'];
$sr = $g($boaz, 'docs-search', ['q' => 'דרישת-הצפה', 'shared' => '1'])[1]['results'];
eq([(int)apr_val('SELECT COUNT(*) FROM file_texts t JOIN files f ON f.id = t.file_id WHERE f.pid = ?', [$F2]), array_values(array_unique(array_column($st, 'file'))), array_column($sr, 'requirement')], [305, [$F1], [$R1]],
    '🟡2 הרעבת חיפוש: 305 התאמות בטקסט של קובץ נסתר וחדש יותר, ו-210 דרישות נסתרות וחדשות יותר — ההתאמה הגלויה (הישנה) עדיין חוזרת, והנסתרות לא');

// תנאים נוספים ב-apr_share_for וב-share-respond
($GLOBALS['t_share_rate'])();
apr_db()->exec('DROP TRIGGER case_shares_perms_upd');
$sid = (string)apr_val("SELECT pid FROM case_shares WHERE kind = 'cross_office' AND revoked_at IS NULL AND case_id = ?", [$caseId($C)]);
apr_exec("UPDATE case_shares SET perms = '{\"view\":false,\"download\":true}' WHERE pid = ?", [$sid]);
$noView = [$sc($g($boaz, 'case', ['case' => $C])), $sc($g($boaz, 'file', ['file' => $F1]))];
apr_exec("UPDATE case_shares SET perms = '{\"view\":true}' WHERE pid = ?", [$sid]);
apr_db()->exec((string)preg_replace('/^.*?(CREATE TRIGGER IF NOT EXISTS case_shares_perms_upd.*?END;).*$/s', '$1', (string)file_get_contents(__DIR__ . '/../lib/migrations/015_sharing.sql')));
$revoke($avi, $sid);
$SX = $share($avi, $C, $toBoaz(['days' => 30]))[1]['share'];
$GLOBALS['apr_time'] = $T0 + 30 * 86400;
$late = [$sc($accept($boaz, $SX)), $g($boaz, 'my-shares', [])[1]['invites']];
$GLOBALS['apr_time'] = $T0;
eq([$noView, $late, $accept($boaz, $SX)[0]], [[[404, 'not_found'], [404, 'not_found']], [[404, 'not_found'], []], 200],
    'שיתוף בלי view — אין גישה לכלום (גם עם download); הזמנה שתוקפה פג לפני שאושרה — אינה מוצגת ואינה מתאשרת');
$revoke($avi, $SX);
$cShare = ['id' => 1, 'pid' => 'x', 'perms' => ['view' => true] + array_fill_keys(APR_CASE_PERMS, false), 'my_role' => 'external', 'responsible_user_id' => 0, 'share' => ['kind' => 'cross_office', 'scope' => 'all', 'items' => []]];
eq([apr_file_visible(['user' => ['id' => 0]], $cShare, ['id' => 0]), apr_file_visible(['user' => ['id' => 0]], $cShare, ['id' => 0, 'pid' => 'ffffffffffffffff'])], [false, true],
    'apr_file_visible: קובץ בלי מזהה אינו נראה לשותף (אי אפשר להכריע לפריט); עם מזהה, בשיתוף של כל התיק — נראה');

// משתתף פנימי: שורות שלפני 05 (בלי שלוש ההרשאות) — דלוקות; ו-see_internal כבוי מסתיר הערות פנימיות ושורות comp.* ביומן
$talRow = (int)apr_val('SELECT cm.id FROM case_members cm WHERE cm.case_id = ? AND cm.user_id = ?', [$caseId($C), $uid('tal@example.com')]);
apr_exec("UPDATE case_members SET perms = '{\"view\":true,\"tasks\":true,\"documents\":true,\"sources\":true,\"contact\":true}' WHERE id = ?", [$talRow]);
$tp = $g($tal, 'case', ['case' => $C])[1]['me']['perms'];
$old = [$tp['download'], $tp['see_internal'], $tp['see_draft'], $tp['edit'], $g($tal, 'file', ['file' => $F1])[0], $g($tal, 'case-comps', ['case' => $C])[0]];
call('POST', 'case-member-update', ['case' => $C, 'user' => $talPid, 'perms' => ['edit' => true]], $avi);
$kept = json_decode((string)apr_val('SELECT perms FROM case_members WHERE id = ?', [$talRow]), true);
call('POST', 'case-member-update', ['case' => $C, 'user' => $talPid, 'perms' => ['see_internal' => false]], $avi);
$tv = $g($tal, 'case', ['case' => $C])[1];
$ta = array_column($g($tal, 'case-activity', ['case' => $C])[1]['rows'], 'action');
$aa = array_column($g($avi, 'case-activity', ['case' => $C])[1]['rows'], 'action');
eq([$old, [$kept['download'] ?? null, $kept['see_internal'] ?? null, $kept['see_draft'] ?? null, $kept['edit'] ?? null], array_values(array_unique(array_column($tv['requirements'], 'explain_internal'))), $tv['comps'],
    count(array_filter($ta, fn($a) => str_starts_with($a, 'comp.'))), count(array_filter($aa, fn($a) => str_starts_with($a, 'comp.'))) > 0, in_array('הערה-פנימית-סודית', array_column($g($avi, 'case', ['case' => $C])[1]['requirements'], 'explain_internal'), true)],
    [[true, true, true, false, 200, 200], [true, true, true, true], [''], null, 0, true, true],
    'משתתף פנימי שנשמר לפני 05 (בלי שלוש ההרשאות): הורדה, פנימי וטיוטה דלוקות, ועדכון של הרשאה אחרת אינו מכבה אותן; עם see_internal כבוי — בלי הערות פנימיות, בלי עסקאות, ובלי שורות comp.* ביומן הפעילות');

// ══ מבנה: נקודת כניסה אחת, וברירת מחדל סגורה ═════════════════════
$lib = fn(string $f) => (string)file_get_contents(__DIR__ . '/../lib/' . $f);
$caseRules = array_keys(array_filter(APR_ACCESS, fn($r) => $r === 'case'));
sort($caseRules);
$expected = ['case', 'case-comps', 'case-docs', 'case-facts', 'case-sources', 'case-source', 'case-visits', 'cases', 'comp', 'comp-set', 'docs-search', 'fact', 'file', 'file-facts', 'file-thumb', 'source-check', 'visit', 'visit-photo'];
sort($expected);
$sharesSql = [];
foreach (glob(__DIR__ . '/../lib/*.php') as $f) if (preg_match('/\b(FROM|JOIN|INTO|UPDATE)\s+case_shares\b/', (string)file_get_contents($f))) $sharesSql[] = basename($f);
sort($sharesSql);
eq([$caseRules, array_values(array_diff($caseRules, APR_READS)), preg_match_all('/apr_share_for\(/', implode('', array_map($lib, array_map('basename', glob(__DIR__ . '/../lib/*.php'))))), $sharesSql,
    preg_match_all('/\bcase_members\b/', $lib('shares.php')) <= 3, apr_share_for(['user' => ['id' => $uid('boaz@example.com'), 'is_sysadmin' => 1]], ['id' => $caseId($C)])],
    [$expected, [], 3, ['access.php', 'documents.php', 'shares.php'], true, null],
    'המבנה: רק 18 פעולות קריאה פתוחות לשיתוף (הכלל case), כולן GET; apr_share_for נקרא ממקום אחד (apr_case_get); case_shares נקראת רק ב-shares.php, בכלל הגישה ובחיפוש; ובלי פעולה של הנתב — אין שיתוף');
// שותף פעיל, וקריאה ל-apr_case_get מפעולה שאינה פתוחה לשיתוף / בלי נתב / עם perm אחר — 404
$S8 = $share($avi, $C, $toBoaz())[1]['share'];
$accept($boaz, $S8);
$ctxB = apr_ctx(['platform_user' => $boaz, 'cookie' => jar()[$boaz] ?? '', 'ua' => 'test']);
$try = function (?string $action, string $perm = 'view') use ($ctxB, $C) {
    if ($action === null) unset($GLOBALS['apr_action']); else $GLOBALS['apr_action'] = $action;
    try { return apr_case_get($ctxB, $C, $perm)['my_role']; } catch (AprError $e) { return $e->errorCode; } finally { unset($GLOBALS['apr_action']); }
};
eq([$try('case'), $try(null), $try('case-update'), $try('case-activity'), $try('no-such-action'), $try('case', 'edit'), $try('case', 'documents'), $try('case', 'members')],
    ['external', 'not_found', 'not_found', 'not_found', 'not_found', 'not_found', 'not_found', 'not_found'],
    'ברירת המחדל סגורה: apr_case_get מכיר שיתוף רק בפעולה שהכלל שלה case, ורק ל-view — כל נקודת גישה חדשה סגורה לשותף עד שתיפתח במודע');
$revoke($avi, $S8);

// ══ המסך: כל ערך שמגיע מהמשרד האחר עובר esc() ═══════════════════
$js = (string)file_get_contents(__DIR__ . '/../assets/js/screens/shares.js');
preg_match_all('/\$\{([^}]*)\}/', $js, $tpl);
// ביטוי בתבנית שנוגע בשדה של משתמש חייב לעבור esc() (או פונקציית עזר sh…() שמחזירה HTML שכבר עבר). קבוע של מחרוזות (SH.x / C.x / T.x) אינו קלט
$unsafe = array_values(array_filter($tpl[1], fn($e) => preg_match('/\.(name|office|email|reason|label|title|number|by|recipient|added_by)\b/', $e)
    && !preg_match('/\besc\(|\bfmt[A-Z]\w*\(|\bsh[A-Z]\w*\(/', $e) && !preg_match('/^\s*(SH|C|T|P)(\.\w+)+\s*$/', $e)));
eq([$unsafe, str_contains($js, 'innerHTML') && !str_contains($js, 'esc('), strlen($js) > 2000], [[], false, true], 'XSS: במסך השיתוף אין ערך של שם, משרד, מייל, נימוק או תווית שנכנס לתבנית בלי esc()');

// ══ מיגרציה 015: על מסד v14 מאוכלס, ואידמפוטנטית ═════════════════
$m14 = sys_get_temp_dir() . '/apr-m14-' . bin2hex(random_bytes(5));
mkdir($m14, 0700, true);
foreach (glob(__DIR__ . '/../lib/migrations/*.sql') as $mf) if ((int)basename($mf) <= 14) copy($mf, $m14 . '/' . basename($mf));
$pdoOpt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$v14 = new PDO("sqlite:$dir/v14.sqlite", null, null, $pdoOpt);
apr_migrate($v14, $m14);
$v14->exec('PRAGMA foreign_keys = OFF');
$v14->exec('ATTACH ' . $v14->quote("$dir/app.sqlite") . ' AS live');
$tables = array_column($v14->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchAll(), 'name');
foreach ($tables as $t) {
    try { $v14->exec("DELETE FROM main.\"$t\""); } catch (PDOException) { continue; }
    try { $v14->exec("INSERT INTO main.\"$t\" (" . t_cols($v14, $t) . ") SELECT " . t_cols($v14, $t) . " FROM live.\"$t\""); } catch (PDOException) { continue; }
}
$v14->exec('DETACH live');
$v14->exec('PRAGMA foreign_keys = ON');
$dump = fn(PDO $d) => array_map(fn($t) => [$t, hash('sha256', json_encode($d->query('SELECT ' . t_cols($d, $t) . " FROM \"$t\" ORDER BY 1")->fetchAll()))], $tables);
$d14 = $dump($v14);
$had = [apr_schema_version($v14), in_array('case_shares', $tables, true), (int)$v14->query('SELECT COUNT(*) FROM case_members')->fetchColumn() >= 4, (int)$v14->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() > 50];
apr_migrate($v14);
$fresh = new PDO('sqlite::memory:', null, null, $pdoOpt);
apr_migrate($fresh);
$shape = fn(PDO $d) => json_encode($d->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll());
$s15 = $shape($v14);
$v14->exec((string)file_get_contents(__DIR__ . '/../lib/migrations/015_sharing.sql'));      // הרצה שנייה של אותו קובץ
$v14->exec((string)file_get_contents(__DIR__ . '/../lib/migrations/015_sharing.sql'));
eq([apr_schema_version($v14), $had, $dump($v14) === $d14, $s15 === $shape($fresh), $shape($v14) === $s15, array_column($v14->query("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE '%share%') ORDER BY name")->fetchAll(), 'name'),
    (int)$v14->query('SELECT COUNT(*) FROM case_shares')->fetchColumn(), count($v14->query('PRAGMA foreign_key_check')->fetchAll()),
    trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --stat origin/main -- lib/migrations/00[1-9]_*.sql lib/migrations/01[0-4]_*.sql 2>/dev/null'))],
    [15, [14, false, true, true], true, true, true, ['case_share_items', 'case_shares', 'profile_shares', 'share_previews'], 0, 0, ''],
    'מיגרציה 015 על מסד v14 מאוכלס: כל שורה בכל טבלה קיימת נשמרת כפי שהיא, המבנה זהה למסד חדש, הרצה חוזרת של הקובץ אינה משנה דבר (אידמפוטנטית), ו-001–014 לא נגעו');

eq($warnings, [], 'אין אזהרות PHP לאורך כל הבדיקות');
done();
