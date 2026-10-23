<?php
/**
 * אישורים להיעדרות — אישור מחלה ודומיו (גרסה 3, שלב 3; האפיון: docs/v3-absences.md פרק 5).
 *
 * - מידע רפואי. הקובץ נשמר ב-data/certs/ (חסום לגישה ישירה; deploy.yml מחריג את התיקייה) בשם אקראי, ומוגש
 *   רק דרך api.php אחרי בדיקת הרשאה.
 * - רואים את הקובץ: העובד עצמו, הבעלים ומנהלת השכר. מנהלים ומנהלי סניף רואים רק שיש אישור (מספר).
 * - JPG, PNG או PDF, עד 5MB — לפי התוכן (finfo), לא לפי השם. עד 5 קבצים להיעדרות.
 * - משך השמירה — הבעלים קובע (cert_years, ברירת מחדל 7 שנים מסוף ההיעדרות). אחריו הקובץ נמחק, הרשומה נשארת
 *   ("הוצג אישור"), והמחיקה נרשמת ביומן.
 */

declare(strict_types=1);

require_once __DIR__ . '/absences.php';

const CERT_VIEW = ['owner', 'payroll'];
const CERT_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
const CERT_MAX_BYTES = 5 * 1024 * 1024;
const CERT_MAX_PER_ABSENCE = 5;

function certDir(): string {
    $dir = dirname(DB_FILE) . '/certs';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir;
}

/** מחיקה בתום משך השמירה, לחברה. נקרא בכל גישה לאישורים ובהגדרות — אין משימה מתוזמנת בשרת. */
function certPurge(int $companyId): int {
    $years = (int) absSettings($companyId)['cert_years'];
    $cutoff = (new DateTimeImmutable(todayIn(companyRow($companyId)['tz'])))->modify("-$years years")->format('Y-m-d');
    $st = db()->prepare("SELECT c.* FROM absence_certs c JOIN absences a ON a.id = c.absence_id JOIN members m ON m.id = a.member_id
        WHERE m.company_id = ? AND c.deleted_at IS NULL AND a.to_date < ?");
    $st->execute([$companyId, $cutoff]);
    $n = 0;
    foreach ($st->fetchAll() as $c) {
        certErase($c, 'retention');
        audit($companyId, null, 'cert.expire', 'absence_certs:' . $c['id'], ['absence_id' => (int) $c['absence_id']], ['years' => $years]);
        $n++;
    }
    return $n;
}

function certErase(array $c, string $reason): void {
    @unlink(certDir() . '/' . basename($c['file']));
    db()->prepare('UPDATE absence_certs SET deleted_at = ?, delete_reason = ? WHERE id = ?')->execute([nowIso(), $reason, $c['id']]);
}

/** שמירת הקובץ שהועלה. $file — כמו ב-$_FILES. מחוץ ל-CLI רק קובץ שהגיע בהעלאה אמיתית. */
function certStore(array $absence, int $companyId, array $file, ?int $byMember, ?int $byPerson): array {
    if (in_array($absence['status'], ['rejected', 'cancelled'], true)) throw new AppError('ההיעדרות בוטלה או נדחתה');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new AppError(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'הקובץ גדול מדי — עד 5MB' : 'הקובץ לא הגיע');
    }
    $tmp = (string) $file['tmp_name'];
    if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) throw new AppError('הקובץ לא הגיע');
    $size = (int) filesize($tmp);
    if ($size <= 0) throw new AppError('הקובץ ריק');
    if ($size > CERT_MAX_BYTES) throw new AppError('הקובץ גדול מדי — עד 5MB');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset(CERT_TYPES[$mime])) throw new AppError('רק תמונה (JPG, PNG) או PDF');
    $st = db()->prepare('SELECT COUNT(*) FROM absence_certs WHERE absence_id = ? AND deleted_at IS NULL');
    $st->execute([$absence['id']]);
    if ((int) $st->fetchColumn() >= CERT_MAX_PER_ABSENCE) throw new AppError('עד ' . CERT_MAX_PER_ABSENCE . ' קבצים להיעדרות');
    $name = bin2hex(random_bytes(16)) . '.' . CERT_TYPES[$mime];
    $dest = certDir() . '/' . $name;
    $ok = PHP_SAPI === 'cli' && !is_uploaded_file($tmp) ? copy($tmp, $dest) : move_uploaded_file($tmp, $dest);
    if (!$ok) throw new AppError('שמירת הקובץ נכשלה', 500);
    chmod($dest, 0600);
    db()->prepare('INSERT INTO absence_certs (absence_id, file, mime, size, by_member, by_person, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$absence['id'], $name, $mime, $size, $byMember, $byPerson, nowIso()]);
    $id = (int) db()->lastInsertId();
    // ביומן — בלי שם הקובץ ובלי תוכן: רק שהועלה, מה גודלו ומאיזה סוג
    audit($companyId, $byPerson, 'cert.upload', 'absence_certs:' . $id, null, ['absence_id' => (int) $absence['id'], 'mime' => $mime, 'size' => $size]);
    return certsOf((int) $absence['id']);
}

function certsOf(int $absenceId): array {
    $st = db()->prepare('SELECT c.*, p.name AS by_name FROM absence_certs c LEFT JOIN people p ON p.id = c.by_person WHERE c.absence_id = ? ORDER BY c.id');
    $st->execute([$absenceId]);
    return array_map(fn($c) => ['id' => (int) $c['id'], 'mime' => $c['mime'], 'size' => (int) $c['size'], 'created_at' => $c['created_at'],
                                'by' => $c['by_member'] !== null ? 'employee' : $c['by_name'], 'deleted_at' => $c['deleted_at'],
                                'delete_reason' => $c['delete_reason']], $st->fetchAll());
}

function certRow(int $absenceId, int $certId): array {
    $st = db()->prepare('SELECT * FROM absence_certs WHERE id = ? AND absence_id = ?');
    $st->execute([$certId, $absenceId]);
    $c = $st->fetch();
    if (!$c || $c['deleted_at'] !== null) throw new AppError('הקובץ לא נמצא', 404);
    return $c;
}

/** הקובץ עצמו — מחזירים את הנתיב והסוג; api.php שולח אותו. */
function certFile(array $c): array {
    $path = certDir() . '/' . basename($c['file']);
    if (!is_file($path)) throw new AppError('הקובץ לא נמצא', 404);
    return ['path' => $path, 'mime' => $c['mime'], 'name' => 'cert-' . $c['id'] . '.' . (CERT_TYPES[$c['mime']] ?? 'bin')];
}

/* ===== העובד ===== */

/** ההיעדרות של העובד מהמכשיר הזה. */
function empAbsence(array $in): array {
    $m = sessionMember(intIn($in, 'member_id'));
    $a = absencesOf('a.id = ? AND a.member_id = ?', [intIn($in, 'absence_id'), $m['id']])[0] ?? null;
    if (!$a) throw new AppError('ההיעדרות לא נמצאה', 404);
    certPurge((int) $m['company_id']);
    return [$m, $a];
}

function empCertUpload(array $in, array $file): array {
    [$m, $a] = empAbsence($in);
    return ['certs' => certStore($a, (int) $m['company_id'], $file, (int) $m['id'], null)];
}

function empCertList(array $in): array {
    [, $a] = empAbsence($in);
    return ['certs' => certsOf($a['id']), 'can_delete' => $a['status'] === 'pending'];
}

function empCertFile(array $in): array {
    [, $a] = empAbsence($in);
    return certFile(certRow($a['id'], intIn($in, 'id')));
}

/** העובד מוחק קובץ שהעלה — רק כל עוד ההיעדרות ממתינה (אחרי ההחלטה האישור הוא חלק מהתיק). */
function empCertDelete(array $in): array {
    [$m, $a] = empAbsence($in);
    $c = certRow($a['id'], intIn($in, 'id'));
    if ($a['status'] !== 'pending' || (int) $c['by_member'] !== (int) $m['id']) throw new AppError('אחרי ההחלטה — רק הבעלים או מנהלת השכר מוחקים', 403);
    certErase($c, 'employee');
    audit((int) $m['company_id'], null, 'cert.delete', 'absence_certs:' . $c['id'], null, ['by' => 'employee']);
    return ['certs' => certsOf($a['id'])];
}

/* ===== הבעלים ומנהלת השכר ===== */

function mgrAbsence(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    [, $site] = empScope($person, $companyId, CERT_VIEW);
    certPurge($companyId);
    return [$companyId, absOne($companyId, intIn($in, 'absence_id'), $site)];
}

function absCertUpload(array $person, array $in, array $file): array {
    [$companyId, $a] = mgrAbsence($person, $in);
    return ['certs' => certStore($a, $companyId, $file, null, (int) $person['id'])];
}

function absCertList(array $person, array $in): array {
    [, $a] = mgrAbsence($person, $in);
    return ['certs' => certsOf($a['id']), 'can_delete' => true];
}

function absCertFile(array $person, array $in): array {
    [$companyId, $a] = mgrAbsence($person, $in);
    $c = certRow($a['id'], intIn($in, 'id'));
    audit($companyId, (int) $person['id'], 'cert.view', 'absence_certs:' . $c['id'], null, null);   // מי צפה במידע רפואי
    return certFile($c);
}

function absCertDelete(array $person, array $in): array {
    [$companyId, $a] = mgrAbsence($person, $in);
    $c = certRow($a['id'], intIn($in, 'id'));
    $reason = str($in, 'reason', 200);
    if ($reason === '') throw new AppError('חסרה סיבה');
    certErase($c, 'manual');
    audit($companyId, (int) $person['id'], 'cert.delete', 'absence_certs:' . $c['id'], null, ['reason' => $reason]);
    return ['certs' => certsOf($a['id']), 'can_delete' => true];
}
