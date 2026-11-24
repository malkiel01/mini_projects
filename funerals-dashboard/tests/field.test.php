<?php
declare(strict_types=1);
/**
 * פרוסה 04 — בדיקות ל-lib/field.php (W1–W4, W7 בצד השרת), עם העלאה מדומה במקום Dropbox.
 * רץ מ-tests/run.sh אחרי שהשרת נעצר ו-data/ נוקתה (run.sh מנקה אותה גם בסוף). לא לגעת ב-data/ של שרת אמיתי.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
require __DIR__ . '/../lib/field.php';

$fails = 0;
$finished = false;
// reply() של האפליקציה יוצא ב-exit — יציאה באמצע הבדיקות היא כשל, לא הצלחה
register_shutdown_function(function () use (&$finished) { if (!$finished) { fwrite(STDERR, "\nFAIL: field.test.php נעצר באמצע (reply/exit)\n"); exit(1); } });
function ok(bool $cond, string $what): void {
    global $fails;
    if (!$cond) { $fails++; fwrite(STDERR, "FAIL: $what\n"); }
}
$pdo = db();
$actor = ['id' => 7, 'actor' => 'field@example.com'];
$now = new DateTimeImmutable('2026-11-24T08:30:00Z');   // 10:30 בישראל
$mk = function (string $name, string $date, string $time = '', array $extra = []) use ($pdo): array {
    $item = normalize(['month' => substr($date, 0, 7), 'name' => $name, 'date' => $date, 'time' => $time] + $extra, 1);
    store($pdo, $item, null, 1, ['id' => null, 'actor' => 'test'], 'ui', 'save');
    return $item;
};
$t1 = $mk('כהן אברהם', '2026-11-24', '14:00');
$t0 = $mk('לוי שרה', '2026-11-24', '09:00', ['grave' => 'גוש 1']);
$tm = $mk('מזרחי יוסף', '2026-11-25');
$mk('ישן משה', '2026-10-03', '11:00');
$mk('עתידי דוד', '2026-11-28', '11:00');
// תיק של ארגון אחר באותו יום — לא מופיע
$pdo->exec("INSERT OR IGNORE INTO orgs(id, name, created_at, settings) VALUES (2, 'ב', '2026-01-01', '{}')");
$o2 = normalize(['month' => '2026-11', 'name' => 'ארגון ב', 'date' => '2026-11-24'], 2);
store($pdo, $o2, null, 2, ['id' => null, 'actor' => 'test'], 'ui', 'save');

// ---- W1: היום ומחר בלבד, לפי שעה; חיפוש מוצא תיק מחודש אחר
$d = fieldToday($pdo, 1, $now);
ok([$d['today'], $d['tomorrow']] === ['2026-11-24', '2026-11-25'], 'W1: היום ומחר בשעון ישראל');
ok(array_column($d['cases'], 'name') === ['לוי שרה', 'כהן אברהם', 'מזרחי יוסף'], 'W1: היום ומחר בלבד, לפי שעה: ' . json_encode(array_column($d['cases'], 'name'), JSON_UNESCAPED_UNICODE));
ok($d['cases'][0]['needs'] === ['grave_photo'] && $d['cases'][1]['needs'] === ['grave_photo', 'grave'], 'W1: מה חסר מהשטח');
ok(!isset($d['cases'][0]['payments']), 'W1: בלי תשלומים במסך השטח');
ok(fieldDays(new DateTimeImmutable('2026-11-24T22:30:00Z')) === ['2026-11-25', '2026-11-26'], 'W1: אחרי חצות בישראל — כבר מחר');
$s = fieldSearch($pdo, 1, 'ישן');
ok(count($s) === 1 && $s[0]['date'] === '2026-10-03', 'W1: חיפוש בשם מוצא תיק מחודש אחר');
ok(fieldSearch($pdo, 1, 'ארגון ב') === [], 'W1: חיפוש לא חוצה ארגון');
ok(fieldSearch($pdo, 1, 'א') === [], 'W1: חיפוש של תו אחד — ריק');

// ---- העלאה מדומה
$calls = [];
$fake = function (PDO $p, int $org, array $case, string $tmp, string $name, string $kind, array $act) use (&$calls): array {
    $calls[] = compact('org', 'name', 'kind') + ['case' => $case['id'], 'by' => $act['actor']];
    return ['file_id' => count($calls), 'name' => $name, 'link' => 'api.php?action=file_view&id=' . count($calls)];
};
$tmp = tempnam(sys_get_temp_dir(), 'fdw');
file_put_contents($tmp, "\xFF\xD8\xFF\xE0fake");
$uid = fn(string $s) => 'up_' . str_pad($s, 20, '0');

// ---- W2: צילום קבר → שם צפוי, kind grave_photo, ושורה ב-events (via field)
[$st, $b] = fieldUpload($pdo, 1, $t1['id'], $uid('a'), 'grave_photo', $tmp, 'IMG_1.jpg', $actor, $fake, $now);
ok($st === 200 && $b['file']['name'] === 'קבר כהן אברהם 2026-11-24 1030.jpg', 'W2: שם הקובץ: ' . ($b['file']['name'] ?? $st));
ok(($calls[0]['kind'] ?? '') === 'grave_photo' && $calls[0]['case'] === $t1['id'], 'W2: kind grave_photo לתיקיית התיק');
$ev = $pdo->query("SELECT * FROM events WHERE action='field_upload' ORDER BY id DESC LIMIT 1")->fetch();
ok($ev && $ev['case_id'] === $t1['id'] && (int)$ev['actor_user_id'] === 7 && str_contains($ev['diff'], '"via":[null,"field"]'), 'W2: שורה ב-events עם via field');
ok($b['check'] === null, 'W2: צילום קבר לא נוגע בבדיקות');

// ---- W3: אותו מזהה פעמיים → קובץ אחד, אותה תשובה
[$st2, $b2] = fieldUpload($pdo, 1, $t1['id'], $uid('a'), 'grave_photo', $tmp, 'IMG_1.jpg', $actor, $fake, $now);
ok($st2 === 200 && count($calls) === 1 && $b2['duplicate'] === true && $b2['file'] === $b['file'], 'W3: שליחה כפולה → קובץ אחד');
[$st3] = fieldUpload($pdo, 1, $t0['id'], $uid('a'), 'grave_photo', $tmp, 'IMG_1.jpg', $actor, $fake, $now);
ok($st3 === 409 && count($calls) === 1, 'W3: אותו מזהה לתיק אחר → 409');
ok((int)$pdo->query("SELECT COUNT(*) FROM events WHERE action='field_upload'")->fetchColumn() === 1, 'W3: אירוע אחד');
ok(fieldUpload($pdo, 1, $t1['id'], 'short', 'other', $tmp, 'a.jpg', $actor, $fake)[0] === 400, 'W3: מזהה העלאה לא תקין → 400');

// ---- W4: רישיון קבורה → license_document "לאימות", via field; בדיקה שאדם סגר — לא יורדת
[$st, $b] = fieldUpload($pdo, 1, $t1['id'], $uid('b'), 'license', $tmp, 'רישיון.pdf', $actor, $fake, $now);
$c = existing($pdo, $t1['id'], 1)['checks']['license_document'] ?? [];
ok($st === 200 && ($c['status'] ?? '') === 'document_present' && ($c['via'] ?? '') === 'field' && ($c['by'] ?? '') === 'field@example.com', 'W4: document_present, via field');
ok(($b['check'] ?? null) === ['key' => 'license_document', 'status' => 'document_present'], 'W4: התשובה מחזירה את הבדיקה');
ok(($calls[1]['name'] ?? '') === 'רישיון.pdf', 'W4: קובץ שאינו צילום — בשם המקורי');
$v = existing($pdo, $tm['id'], 1);
$v['checks']['license_document'] = ['status' => 'verified', 'value' => '77', 'note' => ''];
formSaveCase($pdo, $v, 'ui', ['id' => 1, 'actor' => 'office'], 1);
fieldUpload($pdo, 1, $tm['id'], $uid('c'), 'license', $tmp, 'r.pdf', $actor, $fake, $now);
ok((existing($pdo, $tm['id'], 1)['checks']['license_document']['status'] ?? '') === 'verified', 'W4: בדיקה מאומתת לא יורדת ל"לאימות"');
ok(array_column(fieldKinds(['documents' => [['kind' => 'license'], ['kind' => 'צוואה']]]), 'kind') === array_merge(array_slice(array_keys(FIELD_KINDS), 0, -1), ['צוואה', 'other']),
    'W4: סוגי הקבצים — הקבועים, הסוגים שבתיק, ו"אחר" בסוף');

// ---- ביקורת 1: עריכה בזמן ההעלאה (שם, תשלום, הערה) שורדת — משנים רק את הבדיקה, על התיק הטרי
$ed = $mk('עריכה באמצע', '2026-11-24', '12:00');
$midEdit = function (PDO $p, int $org, array $case, string $tmp, string $name, string $kind, array $act) use ($fake): array {
    $c = existing($p, $case['id'], $org);
    $c['name'] = 'עריכה באמצע (תוקן)'; $c['notes'] = 'נכתב בזמן ההעלאה'; $c['payments'] = [['amount' => 120, 'purpose' => 'קבר']];
    formSaveCase($p, $c, 'ui', ['id' => 2, 'actor' => 'office@example.com'], $org);
    return $fake($p, $org, $case, $tmp, $name, $kind, $act);
};
[$st] = fieldUpload($pdo, 1, $ed['id'], $uid('e'), 'license', $tmp, 'r.pdf', $actor, $midEdit, $now);
$after = existing($pdo, $ed['id'], 1);
ok($st === 200 && $after['name'] === 'עריכה באמצע (תוקן)' && $after['notes'] === 'נכתב בזמן ההעלאה' && (float)($after['payments'][0]['amount'] ?? 0) === 120.0,
    'ביקורת 1: עריכה בזמן ההעלאה שורדת: ' . json_encode([$after['name'], $after['notes'], $after['payments']], JSON_UNESCAPED_UNICODE));
ok(($after['checks']['license_document']['status'] ?? '') === 'document_present', 'ביקורת 1: והבדיקה עדיין לאימות');
$lastSave = $pdo->query("SELECT diff FROM events WHERE case_id = " . $pdo->quote($ed['id']) . " AND action = 'save' ORDER BY id DESC LIMIT 1")->fetchColumn();
// (payments מופיע לפעמים ב-diff כש-120 מול 120.0 — רעש ידוע של caseDiff ב-01, לא שינוי; לכן בודקים שהערך זהה משני הצדדים)
$d = json_decode((string)$lastSave, true);
ok(!isset($d['name']) && (!isset($d['payments']) || $d['payments'][0] == $d['payments'][1]), 'ביקורת 1: השמירה של השטח לא משנה שם/תשלום: ' . $lastSave);

// ---- ביקורת 10: ניסיון חוזר אחרי בקשה שנקטעה — אירוע אחד; סוג לא מוכר → "אחר"
$pdo->exec("UPDATE field_uploads SET status = 'failed' WHERE upload_id = '" . $uid('e') . "'");
fieldUpload($pdo, 1, $ed['id'], $uid('e'), 'license', $tmp, 'r.pdf', $actor, $fake, $now);
ok((int)$pdo->query("SELECT COUNT(*) FROM events WHERE action = 'field_upload' AND case_id = " . $pdo->quote($ed['id']))->fetchColumn() === 1, 'ביקורת 10: אירוע אחד למזהה');
$n = count($calls);
fieldUpload($pdo, 1, $ed['id'], $uid('f'), '<script>', $tmp, 'z.pdf', $actor, $fake, $now);
ok(($calls[$n]['kind'] ?? '') === 'other', 'ביקורת 10: סוג לא מוכר → other');
// ביקורת 5: "בשליחה" — חלון של 15 דקות
$pdo->exec("UPDATE field_uploads SET status = 'pending', updated_at = '" . gmdate('c', time() - 600) . "' WHERE upload_id = '" . $uid('f') . "'");
ok(fieldUpload($pdo, 1, $ed['id'], $uid('f'), 'other', $tmp, 'z.pdf', $actor, $fake, $now)[0] === 409, 'ביקורת 5: בשליחה לפני 10 דקות → 409 in_progress');
// ביקורת 11: שעת הצילום מהדפדפן, בגבולות
ok(fieldTakenAt((time() - 3600) * 1000)?->getTimestamp() === time() - 3600, 'ביקורת 11: taken_at');
ok(fieldTakenAt((time() - 30 * 86400) * 1000) === null && fieldTakenAt('x') === null && fieldTakenAt((time() + 3600) * 1000) === null, 'ביקורת 11: מחוץ לגבולות → null');

// ---- W7: Dropbox מנותק → 409 בהודעה ברורה, ואותו מזהה מצליח כשחוזר
$down = fn() => throw new FieldUploadError(409, 'dropbox_not_connected', FIELD_DBX_DOWN);
[$st, $b] = fieldUpload($pdo, 1, $t0['id'], $uid('d'), 'grave_photo', $tmp, 'x.jpg', $actor, $down, $now);
ok($st === 409 && $b['error'] === 'Dropbox של הארגון לא מחובר — פנה למנהל' && $b['code'] === 'dropbox_not_connected', 'W7: הודעה ברורה');
ok($pdo->query("SELECT status FROM field_uploads WHERE upload_id='" . $uid('d') . "'")->fetchColumn() === 'failed', 'W7: נרשם כנכשל (לא שקט)');
[$st] = fieldUpload($pdo, 1, $t0['id'], $uid('d'), 'grave_photo', $tmp, 'x.jpg', $actor, $fake, $now);
ok($st === 200, 'W7: ניסיון חוזר עם אותו מזהה — מצליח');
// בלי הקוד של 03 — fieldDropboxUploader עונה "לא מחובר"
if (!function_exists('dropboxUploadToCase')) {
    try { fieldDropboxUploader($pdo, 1, $t0, $tmp, 'x.jpg', 'other', $actor); ok(false, 'W7: בלי 03 — חריגה'); }
    catch (FieldUploadError $e) { ok($e->status === 409 && $e->getMessage() === FIELD_DBX_DOWN, 'W7: בלי 03 — 409 "לא מחובר"'); }
}

// ---- שם צילום עם תווים אסורים
ok(fieldFileName('grave_photo', ['name' => 'א/ב:ג'], 'x', $now) === 'קבר א_ב_ג 2026-11-24 1030.jpg', 'שם צילום — תווים אסורים מוחלפים');
ok(fieldFileName('other', [], '../../etc/passwd') === 'passwd', 'שם קובץ — בלי נתיב');

@unlink($tmp);
$finished = true;
if ($fails) { fwrite(STDERR, "field.test.php: $fails כשלים\n"); exit(1); }
echo "  ✓ פרוסה 04 — W1–W4, W7 (שרת, העלאה מדומה)\n";
