<?php
declare(strict_types=1);
/**
 * פרוסה 10 — זיהוי וקישור (link/). רץ מ-tests/run.sh על data/ נקייה (run.sh מנקה לפני ואחרי). לא לגעת ב-data/ של שרת אמיתי.
 * בלי רשת: Dropbox — שורות ב-dbx_folders / files (כמו שהסנכרון של 03 כותב); הבינה — $GLOBALS['fd_link_reader'].
 * הקבלה (team/slices.md, 10): מגוון שמות תיקיות → קישור או שאלה, אף פעם לא כפילות; שינוי שם לא שובר קישור;
 * כלל שנלמד חל בתיקייה הבאה.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
require __DIR__ . '/../link/lib.php';

$fails = 0;
$finished = false;
register_shutdown_function(function () use (&$finished) { if (!$finished) { fwrite(STDERR, "\nFAIL: link.test.php נעצר באמצע (reply/exit)\n"); exit(1); } });
function ok(bool $cond, string $what): void {
    global $fails;
    if (!$cond) { $fails++; fwrite(STDERR, "FAIL: $what\n"); }
}

// ───── חלק א׳: פענוח והחלטה (טהור) ─────
$now = new DateTimeImmutable('2026-11-25');
foreach (['2026-10' => '2026-10', '10.2026' => '2026-10', '10-2026' => '2026-10', 'אוקטובר 2026' => '2026-10', 'October 2026' => '2026-10',
    'Oct-26' => '2026-10', '2026_10' => '2026-10', '202610' => '2026-10', 'נובמבר 26' => '2026-11'] as $in => $want) {
    ok((linkParseMonth((string)$in, null, $now)['month'] ?? null) === $want, "חודש: \"$in\" → $want");
}
ok((linkParseMonth('אוקטובר', 2026, $now)['month'] ?? null) === '2026-10', 'חודש בתוך תיקיית שנה');
ok((linkParseMonth('חשון', null, $now)['ask'] ?? '') === 'hebrew', 'חודש עברי — שאלה (או כלל), לא ניחוש');
ok(in_array('2026-11', linkParseMonth('חשון', null, $now)['options'] ?? [], true), 'חשון 2026 — בין האפשרויות 11/2026');
ok(linkParseMonth('כהן אברהם 2026', null, $now) === null && linkParseMonth('2026-10-03 כהן', null, $now) === null, 'שם עם תאריך אינו חודש');

$p = linkParseName("כליפה א' 14.10", '2026-10');
ok($p['words'] === ['כליפה', 'א'] && $p['dates'] === ['2026-10-14'], 'שם ותאריך חלקי בתוך תיקיית חודש');
$p = linkParseName('012345674 לוי שרה ז"ל', null);
ok($p['tz'] === ['012345674'] && $p['words'] === ['לוי', 'שרה'], 'ת"ז תקינה + מילות רעש');
ok(linkParseName('לוי 123456789', null)['tz'] === [], 'מספר שאינו ת"ז תקינה — לא ת"ז');
ok(linkParseName('20.10.26 - כהן', null)['dates'] === ['2026-10-20'] && linkParseName('כהן 2026_10_20', null)['dates'] === ['2026-10-20'], 'תאריכים בכתיבים שונים');

$cases = [
    ['id' => 'c1', 'name' => 'כליפה אטון', 'date' => '2026-10-14', 'month' => '2026-10', 'linked' => false, 'tz' => []],
    ['id' => 'c2', 'name' => 'כהן אברהם', 'date' => '2026-10-03', 'month' => '2026-10', 'linked' => false, 'tz' => []],
    ['id' => 'c3', 'name' => 'כהן משה', 'date' => '2026-10-09', 'month' => '2026-10', 'linked' => false, 'tz' => ['012345674']],
    ['id' => 'c4', 'name' => 'לוי שרה', 'date' => '2026-10-20', 'month' => '2026-10', 'linked' => true, 'tz' => []],
];
$dec = fn(string $name, ?string $m = '2026-10') => linkDecide(linkParseName($name, $m), $cases, $m);
ok($dec('2026-10-03 כהן אברהם')['decision'] === 'link' && $dec('2026-10-03 כהן אברהם')['case_id'] === 'c2', 'שם מלא + תאריך → קישור');
ok(($dec('אברהם כהן 3.10')['case_id'] ?? '') === 'c2', 'סדר הפוך ותאריך חלקי → קישור');
ok($dec("כליפה א' 14.10")['decision'] === 'question' && $dec("כליפה א' 14.10")['candidates'][0]['id'] === 'c1', 'שם חלקי → שאלה עם המועמד');
ok($dec('כהן 3.10')['decision'] === 'question', 'שם משפחה בלבד → שאלה');
ok(($dec('משה 012345674')['case_id'] ?? '') === 'c3', 'ת"ז → קישור');
ok($dec('כהן אברהם 012345674')['decision'] !== 'link' || $dec('כהן אברהם 012345674')['case_id'] !== 'c2', 'ת"ז של אחר שוללת');
ok($dec('לוי שרה 20.10')['decision'] === 'question', 'התיק כבר מקושר לתיקייה אחרת → שאלה, לא קישור שני');
ok($dec('Cohen Avraham 03-10-2026')['decision'] === 'question', 'שם באנגלית + תאריך זהה → שאלה (לא תיק חדש)');
ok($dec('גולן רחל 22.10')['decision'] === 'new', 'אין אף מועמד → תיק חדש');
ok($dec('סריקות')['decision'] === 'question', 'שם בלי תאריך → שאלה, לא תיק');
ok($dec('כהן אברהם 9.10')['decision'] === 'question', 'שם מלא ותאריך שונה → שאלה');
ok(linkFilePattern('IMG_0012.jpg') === '' && linkFilePattern('Adobe Scan רישיון 2026-10-14.pdf') === 'adobe scan רישיון', 'תבנית כלל לקובץ');
ok(linkPatternHits('adobe scan רישיון', 'Adobe Scan 3 רישיון קבורה.pdf') && !linkPatternHits('adobe scan רישיון', 'Adobe Scan.pdf'), 'כלל קובץ חל');

// ───── חלק ב׳: הרצה על מסד ─────
$pdo = db();
linkEnsureSchema($pdo);
linkEnsureSchema($pdo);   // אידמפוטנטית
$sys = ['id' => null, 'actor' => 'test'];
$mk = function (string $name, string $date, array $extra = []) use ($pdo, $sys): array {
    $item = normalize(['month' => substr($date, 0, 7), 'name' => $name, 'date' => $date] + $extra, 1);
    store($pdo, $item, null, 1, $sys, 'ui', 'save');
    return $item;
};
$pdo->exec('DELETE FROM funerals');   // רק התיקים של הבדיקה
$kh = $mk('כליפה אטון', '2026-10-14');
$co = $mk('כהן אברהם', '2026-10-03');
$lv = $mk('לוי שרה', '2026-10-20', ['checks' => ['license_document' => ['status' => 'verified', 'value' => 'נבדק', 'source' => 'אדם', 'note' => '']]]);
$mz = $mk('מזרחי יוסף', '2026-10-07');
$pz = $mk('פרץ דוד', '2026-10-11');
$now = gmdate('c');
$pdo->prepare("INSERT OR REPLACE INTO dbx_orgs(org_id, status, account_id, account_name, root_id, root_path, root_lower, connected_at)
    VALUES (1, 'connected', 'dbid:T', 'בדיקה', 'id:root', '/Funerals', '/funerals', ?)")->execute([$now]);
$fseq = 0;
$folder = function (string $path) use ($pdo, &$fseq): string {
    $id = 'id:f' . (++$fseq);
    $pdo->prepare('INSERT INTO dbx_folders(org_id, dbx_id, path, path_lower) VALUES (1,?,?,?)')->execute([$id, $path, mb_strtolower($path)]);
    return $id;
};
$file = function (string $path) use ($pdo, &$fseq, $now): int {
    $lower = mb_strtolower($path);
    $pdo->prepare("INSERT INTO files(org_id, case_id, dbx_id, path, path_lower, name, size, rev, server_modified, deleted, added_via, kind, created_at, updated_at)
        VALUES (1, ?, ?, ?, ?, ?, 10, 'r', ?, 0, 'dropbox', '', ?, ?)")
        ->execute([dbxCaseForPath($pdo, 1, $lower), 'id:x' . (++$fseq), $path, $lower, basename($path), $now, $now, $now]);
    return (int)$pdo->lastInsertId();
};
$reads = [];
$answers = [];   // שם קובץ → מה "הבינה" קוראת בו
$GLOBALS['fd_link_reader'] = function (array $f) use (&$reads, &$answers) { $reads[] = $f['name']; return $answers[$f['name']] ?? null; };
$caseOf = function (string $folderId) use ($pdo): ?string {
    $st = $pdo->prepare('SELECT case_id FROM case_folders WHERE org_id = 1 AND dbx_folder_id = ?');
    $st->execute([$folderId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
};
$q = function (string $kind, string $subject) use ($pdo): ?array {
    $st = $pdo->prepare("SELECT * FROM ai_questions WHERE org_id = 1 AND kind = ? AND subject = ?");
    $st->execute([$kind, $subject]);
    return dbxRow($st);
};
$count = fn() => (int)$pdo->query('SELECT COUNT(*) FROM funerals WHERE org_id = 1')->fetchColumn();
$org = dbxOrg($pdo, 1);
$actor = ['id' => 5, 'actor' => 'office@example.com'];

$m10 = $folder('/Funerals/2026-10');
$fCo = $folder('/Funerals/2026-10/2026-10-03 כהן אברהם');
$fKh = $folder("/Funerals/2026-10/כליפה א' 14.10");
$fGo = $folder('/Funerals/2026-10/גולן רחל 22.10');
$fSc = $folder('/Funerals/2026-10/סריקות');
$mOct = $folder('/Funerals/Oct-2026');
$fMz = $folder('/Funerals/Oct-2026/Mizrahi Yosef 07-10-2026');
$mHe = $folder('/Funerals/אוקטובר 2026');
$fLv = $folder('/Funerals/אוקטובר 2026/לוי שרה 20.10');
$fNoName = $folder('/Funerals/2026-10/Scan 0012');
$fSub = $folder('/Funerals/2026-10/2026-10-03 כהן אברהם/מסמכים');
$mHs = $folder('/Funerals/חשון');
$fBr = $folder('/Funerals/חשון/ברק נועה 5');
$lic = $file('/Funerals/2026-10/2026-10-03 כהן אברהם/Adobe Scan רישיון.pdf');
$img = $file('/Funerals/2026-10/2026-10-03 כהן אברהם/IMG_0012.jpg');
$lvLic = $file('/Funerals/אוקטובר 2026/לוי שרה 20.10/רישיון.pdf');
$scanPdf = $file('/Funerals/2026-10/Scan 0012/doc.pdf');
$loose = $file('/Funerals/2026-10/loose.pdf');
$answers = [
    'Adobe Scan רישיון.pdf' => ['kind' => 'license_document', 'confidence' => 'high', 'license_number' => 'RK-4471', 'name' => 'כהן אברהם', 'tz' => '', 'death_date' => '2026-10-02'],
    'IMG_0012.jpg' => ['kind' => 'identity_document', 'confidence' => 'low', 'name' => '', 'tz' => '', 'death_date' => '', 'license_number' => ''],
    'רישיון.pdf' => ['kind' => 'license_document', 'confidence' => 'high', 'license_number' => '999', 'name' => '', 'tz' => '', 'death_date' => ''],
    'doc.pdf' => ['kind' => 'identity_document', 'confidence' => 'high', 'name' => 'פרץ דוד', 'tz' => '012345674', 'death_date' => '2026-10-11', 'license_number' => ''],
];
$before = $count();
$r1 = linkRun($pdo, 1, ['read' => true]);
if (getenv('LINK_DEBUG')) { echo json_encode($r1), "\n"; foreach ($pdo->query('SELECT f.path, lf.role, lf.month, lf.why FROM dbx_folders f LEFT JOIN link_folders lf USING(org_id, dbx_id)') as $r) echo implode(' | ', $r), "\n";
    foreach ($pdo->query('SELECT kind, subject, text, options FROM ai_questions') as $r) echo implode(' | ', $r), "\n"; }

ok($caseOf($fCo) === $co['id'], 'L1: "2026-10-03 כהן אברהם" → קושר לתיק (לפי מזהה תיקייה)');
ok($caseOf($fLv) === $lv['id'], 'L1: "אוקטובר 2026 / לוי שרה 20.10" → קושר');
ok($caseOf($fKh) === null && ($q('folder', $fKh)['status'] ?? '') === 'open', 'L2: "כליפה א\' 14.10" → שאלה, לא קישור');
ok(str_contains((string)($q('folder', $fKh)['options'] ?? ''), 'case:' . $kh['id']), 'L2: בשאלה — התיק כליפה אטון כתשובה לבחירה');
ok($caseOf($fMz) === null && $q('folder', $fMz) !== null, 'L2: שם באנגלית → שאלה (לא תיק חדש)');
ok($caseOf($fNoName) === $pz['id'], 'L3: תיקייה בלי שם ("Scan 0012") — מקושרת לפי התוכן (שם + תאריך מהמסמך)');
$go = $caseOf($fGo);
ok($go !== null && ($g = existing($pdo, $go, 1)) !== null && $g['name'] === 'גולן רחל' && $g['date'] === '2026-10-22' && $g['month'] === '2026-10', 'L4: תיקייה חדשה → תיק חדש (א2)');
ok($q('folder', $fSc) !== null && $caseOf($fSc) === null, 'L5: "סריקות" (בלי תאריך) → שאלה, לא תיק');
ok($q('month', $mHs) !== null && $q('folder', $fBr) === null, 'L6: "חשון" → שאלה על החודש; התיקייה שבתוכו ממתינה');
ok($caseOf($fSub) === null && $q('folder', $fSub) === null, 'L7: תת-תיקייה של תיק — לא תיק ולא שאלה');
ok($count() === $before + 1, 'L8: נפתח תיק אחד בלבד (גולן רחל)');

// קבצים: סוג, בדיקה וערך
$c = existing($pdo, $co['id'], 1);
ok(($c['checks']['license_document']['status'] ?? '') === 'document_present', 'F1: רישיון → "לאימות" (לא "מאומת")');
ok(($c['checks']['license_number']['value'] ?? '') === 'RK-4471', 'F1: מספר הרישיון נשלף');
ok(($c['checks']['license_number']['via'] ?? '') === 'ai', 'F1: מקור — בינה');
ok($pdo->query("SELECT kind FROM files WHERE id = $lic")->fetchColumn() === 'רישיון קבורה', 'F1: סוג בעברית לקובץ');
ok(($q('kind', (string)$img)['status'] ?? '') === 'open', 'F2: ביטחון נמוך → שאלה על סוג');
ok((existing($pdo, $lv['id'], 1)['checks']['license_document']['status'] ?? '') === 'verified', 'F3: בדיקה שאדם סגר — לא נדרסת');
ok(($q('file', (string)$loose)['status'] ?? '') === 'open', 'F4: קובץ מחוץ לכל תיק → שאלה');
$ev = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE action = 'case_from_folder'")->fetchColumn();
ok($ev === 1, 'F5: תיק מתיקייה — ביומן');

// אידמפוטנטיות
$readsBefore = count($reads);
$r2 = linkRun($pdo, 1, ['read' => true]);
ok($count() === $before + 1 && $r2['linked'] === 0 && $r2['created'] === 0, 'L9: הרצה שנייה — אין קישור ואין תיק נוסף');
ok(count($reads) === $readsBefore, 'L9: קובץ לא נקרא פעמיים בספק');

// שינוי שם לא שובר קישור
$pdo->prepare('UPDATE dbx_folders SET path = ?, path_lower = ? WHERE dbx_id = ?')->execute(['/Funerals/2026-10/כהן אברהם ז"ל', mb_strtolower('/Funerals/2026-10/כהן אברהם ז"ל'), $fCo]);
$pdo->prepare('UPDATE case_folders SET dbx_path = ? WHERE dbx_folder_id = ?')->execute(['/Funerals/2026-10/כהן אברהם ז"ל', $fCo]);
// כמו בסנכרון של 03: Dropbox שולח את הקבצים שבתוך התיקייה בנתיב החדש (אותו id)
$oldL = mb_strtolower('/Funerals/2026-10/2026-10-03 כהן אברהם/'); $newL = mb_strtolower('/Funerals/2026-10/כהן אברהם ז"ל/');
foreach ($pdo->query("SELECT id, path_lower FROM files WHERE org_id = 1")->fetchAll() as $fr) {
    if (str_starts_with($fr['path_lower'], $oldL)) $pdo->prepare('UPDATE files SET path_lower = ? WHERE id = ?')->execute([$newL . substr($fr['path_lower'], strlen($oldL)), $fr['id']]);
}
linkRun($pdo, 1, ['read' => true]);
ok($caseOf($fCo) === $co['id'] && $q('folder', $fCo) === null && $count() === $before + 1, 'L10: שינוי שם תיקייה — הקישור נשאר, בלי שאלה ובלי תיק');
ok(dbxCaseForPath($pdo, 1, mb_strtolower('/Funerals/2026-10/כהן אברהם ז"ל/x.pdf')) === $co['id'], 'L10: קבצים בשם החדש — של אותו תיק');

// תשובות → כללים
$x = linkAnswer($pdo, $org, (int)$q('folder', $fKh)['id'], 'case:' . $kh['id'], $actor);
ok($x['ok'] && $caseOf($fKh) === $kh['id'], 'Q1: תשובה "כליפה אטון" → קישור');
ok((int)$pdo->query("SELECT COUNT(*) FROM ai_rules WHERE org_id = 1 AND kind = 'alias'")->fetchColumn() === 1, 'Q1: התשובה נעשתה כלל');
$x = linkAnswer($pdo, $org, (int)$q('folder', $fKh)['id'], 'case:' . $kh['id'], $actor);
ok(!$x['ok'] && $x['status'] === 409, 'Q1: שאלה שנענתה — לא נענית פעמיים');
ok(!linkAnswer($pdo, $org, (int)$q('folder', $fMz)['id'], 'case:fd_לא_ברשימה', $actor)['ok'], 'Q1: תשובה שלא מהרשימה → נדחית');

$x = linkAnswer($pdo, $org, (int)$q('folder', $fSc)['id'], 'not_case', $actor);
ok($x['ok'] && $x['rule'] > 0, 'Q2: "לא תיקיית נפטר" → כלל');
$m11 = $folder('/Funerals/2026-11');
$fSc2 = $folder('/Funerals/2026-11/סריקות');
linkRun($pdo, 1, ['read' => false]);
ok($q('folder', $fSc2) === null && $caseOf($fSc2) === null, 'Q2: הכלל חל בתיקייה הבאה — בלי שאלה');
ok((int)$pdo->query("SELECT uses FROM ai_rules WHERE kind = 'not_case'")->fetchColumn() === 1, 'Q2: מונה שימוש לכלל');

$x = linkAnswer($pdo, $org, (int)$q('month', $mHs)['id'], 'month:2026-11', $actor);
ok($x['ok'], 'Q3: "חשון" → 11/2026');
$before2 = $count();
linkRun($pdo, 1, ['read' => false]);
$br = $caseOf($fBr);
ok($br !== null && (existing($pdo, $br, 1)['date'] ?? '') === '2026-11-05', 'Q3: התיקייה שחיכתה בתוך "חשון" — נפתח לה תיק עם תאריך 5.11');
$y26 = $folder('/Funerals/2026');
$mHs2 = $folder('/Funerals/2026/חשון');
linkRun($pdo, 1, ['read' => false]);
ok($q('month', $mHs2) === null && $pdo->query("SELECT month FROM link_folders WHERE dbx_id = '$mHs2'")->fetchColumn() === '2026-11', 'Q3: כלל החודש חל בתיקייה הבאה');

$x = linkAnswer($pdo, $org, (int)$q('kind', (string)$img)['id'], 'kind:identity_document', $actor);
ok($x['ok'] && $x['rule'] === null, 'Q4: "IMG_0012" — סוג נקבע, בלי כלל (שם בלי תוכן)');
ok((existing($pdo, $co['id'], 1)['checks']['identity_document']['status'] ?? '') === 'document_present', 'Q4: הבדיקה עודכנה');
$cert = $file('/Funerals/2026-10/כהן אברהם ז"ל/Adobe Scan תעודת פטירה.pdf');
$answers['Adobe Scan תעודת פטירה.pdf'] = ['kind' => 'identity_document', 'confidence' => 'low', 'name' => '', 'tz' => '', 'death_date' => '', 'license_number' => ''];
linkRun($pdo, 1, ['read' => true]);
$x = linkAnswer($pdo, $org, (int)$q('kind', (string)$cert)['id'], 'kind:identity_document', $actor);
ok($x['ok'] && $x['rule'] > 0, 'Q5: סוג לקובץ עם שם של תוכן → כלל');
$cert2 = $file('/Funerals/2026-10/גולן רחל 22.10/Adobe Scan תעודת פטירה 2.pdf');
$readsBefore = count($reads);
linkRun($pdo, 1, ['read' => true]);
ok(count($reads) === $readsBefore && (existing($pdo, $go, 1)['checks']['identity_document']['status'] ?? '') === 'document_present', 'Q5: הכלל חל בקובץ הבא — בלי קריאה לספק');

// "מאשר לבד" — רק כשאדם הגדיר
linkRulePut($pdo, 1, 'file_kind', 'אישור תשלום', 'payment_evidence', $actor, true, 'manual');
$pay = $file('/Funerals/2026-10/גולן רחל 22.10/אישור תשלום.pdf');
linkRun($pdo, 1, ['read' => false]);
$gp = existing($pdo, $go, 1)['checks']['payment_evidence'] ?? [];
ok(($gp['status'] ?? '') === 'verified' && str_contains((string)($gp['by'] ?? ''), 'office@example.com'), 'A1: כלל "מאשר לבד" שאדם הגדיר → "מאומת", בשמו');
ok((existing($pdo, $go, 1)['checks']['identity_document']['status'] ?? '') === 'document_present', 'A1: בלי "מאשר לבד" — "לאימות" בלבד');

// שכבות: משותף מול ארגון
linkRulePut($pdo, 0, 'not_case', 'ארכיון', '1', ['id' => null, 'actor' => 'owner']);
ok(isset(linkRules($pdo, 1)['not_case']['ארכיון']) && isset(linkRules($pdo, 2)['not_case']['ארכיון']), 'R1: כלל משותף חל על כל הארגונים');
linkRulePut($pdo, 1, 'not_case', 'ארכיון', '', $actor);
ok(!isset(linkRules($pdo, 1)['not_case']['ארכיון']) && isset(linkRules($pdo, 2)['not_case']['ארכיון']), 'R1: שינוי של ארגון גובר אצלו בלבד');
linkRulePut($pdo, 0, 'month', 'חשון', '2026-10', ['id' => null, 'actor' => 'owner']);
ok(linkRules($pdo, 1)['month']['חשון']['value'] === '2026-11', 'R1: כלל של הארגון גובר על המשותף');

// קובץ יתום — "לא שייך"
$x = linkAnswer($pdo, $org, (int)$q('file', (string)$loose)['id'], 'none', $actor);
ok($x['ok'] && $pdo->query("SELECT state FROM link_files WHERE file_id = $loose")->fetchColumn() === 'ignored', 'F4: "לא שייך" — לא נשאל שוב');

// אף פעם לא כפילות: אותו נפטר בשתי תיקיות
$fGo2 = $folder('/Funerals/2026-10/רחל גולן 22-10-2026');
$before3 = $count();
linkRun($pdo, 1, ['read' => true]);
ok($count() === $before3 && $caseOf($fGo2) === null && $q('folder', $fGo2) !== null, 'D1: תיקייה שנייה לאותו נפטר → שאלה, לא תיק שני');
$x = linkAnswer($pdo, $org, (int)$q('folder', $fGo2)['id'], 'case:' . $go, $actor);
ok(!$x['ok'] && $x['status'] === 409, 'D1: אי אפשר לקשר תיק לשתי תיקיות');
$dups = $pdo->query('SELECT name, COUNT(*) n FROM funerals WHERE org_id = 1 GROUP BY name HAVING n > 1')->fetchAll();
ok($dups === [], 'D2: אין שני תיקים באותו שם');
// תיקייה עם שם ותאריך של תיק קיים שעוד לא קושר, תחת חודש אחר בכתיב שונה — קישור, לא תיק
$fMz2 = $folder('/Funerals/10.2026/7.10 מזרחי יוסף');   // הילד נרשם לפני ההורה — linkRun ממיין לפי עומק
$folder('/Funerals/10.2026');
linkRun($pdo, 1, ['read' => false]);
ok($caseOf($fMz2) === $mz['id'], 'D3: "10.2026 / 7.10 מזרחי יוסף" → קושר לתיק הקיים');
ok(($q('folder', $fMz)['status'] ?? '') === 'open', 'D3: השאלה על התיקייה באנגלית נשארת (התיק כבר מקושר)');

$st = linkStats($pdo, 1);
ok($st['open'] >= 1 && $st['week'] >= $st['open'] && $st['rules'] >= 3, 'S1: מונה שאלות השבוע וכללים');
ok(str_contains(linkRuleText(['kind' => 'month', 'pattern' => 'חשון', 'value' => '2026-11']), '11/2026'), 'S2: כלל בעברית פשוטה');

$finished = true;
if ($fails) { fwrite(STDERR, "link.test.php: $fails כשלים\n"); exit(1); }
echo "link.test.php: הכול עבר\n";
