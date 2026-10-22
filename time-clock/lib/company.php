<?php
/**
 * חברות, סניפים וצוות הניהול (האפיון: M1 ו-M7).
 *
 * כל אחד שמחובר לפלטפורמה ויש לו הרשאה לשעון הנוכחות יכול לפתוח חברה, והוא נהיה הבעלים שלה.
 * הבעלים מוסיף לצוות לפי מייל. האדם מקושר כשהוא נכנס דרך הפלטפורמה עם אותו מייל (מאומת), וכדי
 * להיכנס הוא צריך הרשאה לשעון הנוכחות בכלים הדיגיטליים.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const TEAM_ROLES = ['manager', 'branch_manager', 'payroll'];

function str(array $in, string $key, int $max = 120): string {
    $v = $in[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function intIn(array $in, string $key): int {
    $v = $in[$key] ?? 0;
    return is_numeric($v) ? (int) $v : 0;
}

/** החברות שבהן האדם בצוות הניהול, עם התפקיד (הגבוה, אם יש כמה). */
function myCompanies(array $person): array {
    $st = db()->prepare("SELECT c.id, c.name, m.role, m.site_id, s.name AS site_name FROM members m
        JOIN companies c ON c.id = m.company_id LEFT JOIN sites s ON s.id = m.site_id
        WHERE m.person_id = ? AND m.status = 'active' AND m.role <> 'employee' ORDER BY c.name, m.id");
    $st->execute([$person['id']]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int) $r['id'];
        if (isset($out[$id]) && rank($out[$id]['role']) <= rank($r['role'])) continue;
        $out[$id] = ['id' => $id, 'name' => $r['name'], 'role' => $r['role'],
                     'site_id' => $r['site_id'] === null ? null : (int) $r['site_id'], 'site_name' => $r['site_name']];
    }
    return array_values($out);
}

function rank(string $role): int {
    return array_search($role, ['owner', 'manager', 'branch_manager', 'payroll', 'employee'], true);
}

/** שעות תקן: מספר ימים (5/6) ודקות ליום (1–12 שעות). */
function standardFrom(array $in): array {
    $days = intIn($in, 'days_per_week');
    if (!in_array($days, [5, 6], true)) throw new AppError('ימי עבודה בשבוע: 5 או 6');
    $minutes = intIn($in, 'daily_minutes');
    if ($minutes < 60 || $minutes > 720) throw new AppError('שעות ליום: בין 1 ל-12');
    return [$days, $minutes];
}

function companyCreate(array $person, array $in): array {
    $name = str($in, 'name', 80);
    if ($name === '') throw new AppError('חסר שם לחברה');
    [$days, $minutes] = standardFrom($in);
    $taxId = str($in, 'tax_id', 20);
    if ($taxId !== '' && !preg_match('/^\d{8,9}$/', $taxId)) throw new AppError('ח.פ. — 8 או 9 ספרות');

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO companies (name, tax_id, days_per_week, daily_minutes, created_by, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$name, $taxId !== '' ? $taxId : null, $days, $minutes, $person['id'], nowIso()]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO members (company_id, person_id, role, created_at) VALUES (?,?,'owner',?)")
        ->execute([$id, $person['id'], nowIso()]);
    audit($id, (int) $person['id'], 'company.create', 'companies:' . $id, null, ['name' => $name]);
    $pdo->commit();
    return companyGet($person, $id);
}

function companyRow(int $id): array {
    $st = db()->prepare('SELECT id, name, tax_id, tz, days_per_week, daily_minutes, created_at FROM companies WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) throw new AppError('החברה לא נמצאה', 404);
    foreach (['id', 'days_per_week', 'daily_minutes'] as $k) $c[$k] = (int) $c[$k];
    return $c;
}

/** פרטי החברה, הסניפים והצוות. כל מי שבצוות הניהול רואה; רק הבעלים משנה. */
function companyGet(array $person, int $id): array {
    $me = requireMember($person, $id, MANAGE_ROLES);
    $sites = db()->prepare("SELECT s.id, s.name, (SELECT COUNT(*) FROM members m WHERE m.site_id = s.id AND m.status = 'active') AS members
        FROM sites s WHERE s.company_id = ? ORDER BY s.name");
    $sites->execute([$id]);
    $team = db()->prepare("SELECT m.id, m.role, m.site_id, p.name, p.email, p.platform_sub IS NOT NULL AS linked, p.id = ? AS me
        FROM members m JOIN people p ON p.id = m.person_id
        WHERE m.company_id = ? AND m.status = 'active' AND m.role <> 'employee' ORDER BY m.role = 'owner' DESC, p.name");
    $team->execute([$person['id'], $id]);
    return [
        'company' => companyRow($id),
        'my_role' => $me['role'],
        'sites'   => array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'members' => (int) $s['members']], $sites->fetchAll()),
        'team'    => array_map(fn($t) => ['id' => (int) $t['id'], 'role' => $t['role'], 'site_id' => $t['site_id'] === null ? null : (int) $t['site_id'],
                                          'name' => $t['name'], 'email' => $t['email'], 'linked' => (bool) $t['linked'], 'me' => (bool) $t['me']],
                               $team->fetchAll()),
    ];
}

function companyUpdate(array $person, array $in): array {
    $id = intIn($in, 'company_id');
    requireMember($person, $id, ['owner']);
    $before = companyRow($id);
    $name = str($in, 'name', 80);
    if ($name === '') throw new AppError('חסר שם לחברה');
    [$days, $minutes] = standardFrom($in);
    $taxId = str($in, 'tax_id', 20);
    if ($taxId !== '' && !preg_match('/^\d{8,9}$/', $taxId)) throw new AppError('ח.פ. — 8 או 9 ספרות');
    db()->prepare('UPDATE companies SET name = ?, tax_id = ?, days_per_week = ?, daily_minutes = ? WHERE id = ?')
        ->execute([$name, $taxId !== '' ? $taxId : null, $days, $minutes, $id]);
    audit($id, (int) $person['id'], 'company.update', 'companies:' . $id, $before, companyRow($id));
    return companyGet($person, $id);
}

function siteOf(int $companyId, int $siteId): array {
    $st = db()->prepare('SELECT * FROM sites WHERE id = ? AND company_id = ?');
    $st->execute([$siteId, $companyId]);
    $s = $st->fetch();
    if (!$s) throw new AppError('הסניף לא נמצא', 404);
    return $s;
}

function siteSave(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner', 'manager']);
    $name = str($in, 'name', 60);
    if ($name === '') throw new AppError('חסר שם לסניף');
    $siteId = intIn($in, 'id');
    if ($siteId) {
        $before = siteOf($companyId, $siteId);
        db()->prepare('UPDATE sites SET name = ? WHERE id = ?')->execute([$name, $siteId]);
        audit($companyId, (int) $person['id'], 'site.update', 'sites:' . $siteId, ['name' => $before['name']], ['name' => $name]);
    } else {
        db()->prepare('INSERT INTO sites (company_id, name, created_at) VALUES (?,?,?)')->execute([$companyId, $name, nowIso()]);
        $siteId = (int) db()->lastInsertId();
        audit($companyId, (int) $person['id'], 'site.create', 'sites:' . $siteId, null, ['name' => $name]);
    }
    return companyGet($person, $companyId);
}

/** סניף שיש בו אנשים לא נמחק — קודם מעבירים אותם. */
function siteDelete(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $site = siteOf($companyId, intIn($in, 'id'));
    $st = db()->prepare("SELECT COUNT(*) FROM members WHERE site_id = ? AND status = 'active'");
    $st->execute([$site['id']]);
    if ((int) $st->fetchColumn() > 0) throw new AppError('יש אנשים בסניף הזה. קודם מעבירים אותם לסניף אחר.');
    db()->prepare('UPDATE members SET site_id = NULL WHERE site_id = ?')->execute([$site['id']]);
    db()->prepare('DELETE FROM sites WHERE id = ?')->execute([$site['id']]);
    audit($companyId, (int) $person['id'], 'site.delete', 'sites:' . $site['id'], ['name' => $site['name']], null);
    return companyGet($person, $companyId);
}

/** תפקיד ושיוך לסניף לצוות: מנהל סניף חייב סניף; אחרים — בלי. */
function roleAndSite(int $companyId, array $in): array {
    $role = str($in, 'role', 20);
    if (!in_array($role, TEAM_ROLES, true)) throw new AppError('תפקיד לא מוכר');
    $siteId = null;
    if ($role === 'branch_manager') {
        $siteId = (int) siteOf($companyId, intIn($in, 'site_id'))['id'];
    }
    return [$role, $siteId];
}

function teamAdd(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $name = str($in, 'name', 80);
    if ($name === '') throw new AppError('חסר שם');
    $email = mb_strtolower(str($in, 'email', 120));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('כתובת מייל לא תקינה');
    [$role, $siteId] = roleAndSite($companyId, $in);

    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM people WHERE email = ? COLLATE NOCASE ORDER BY platform_sub IS NULL, id LIMIT 1');
    $st->execute([$email]);
    $p = $st->fetch();
    if (!$p) {
        $pdo->prepare('INSERT INTO people (name, email, created_at) VALUES (?,?,?)')->execute([$name, $email, nowIso()]);
        $p = getPerson((int) $pdo->lastInsertId());
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM members WHERE company_id = ? AND person_id = ? AND status = 'active' AND role <> 'employee'");
    $st->execute([$companyId, $p['id']]);
    if ((int) $st->fetchColumn() > 0) throw new AppError('האדם הזה כבר בצוות');

    $pdo->prepare('INSERT INTO members (company_id, person_id, role, site_id, created_at) VALUES (?,?,?,?,?)')
        ->execute([$companyId, $p['id'], $role, $siteId, nowIso()]);
    $mid = (int) $pdo->lastInsertId();
    audit($companyId, (int) $person['id'], 'team.add', 'members:' . $mid, null, ['person' => (int) $p['id'], 'role' => $role, 'site_id' => $siteId]);
    return companyGet($person, $companyId);
}

function teamMember(int $companyId, int $memberId): array {
    $st = db()->prepare("SELECT * FROM members WHERE id = ? AND company_id = ? AND status = 'active' AND role <> 'employee'");
    $st->execute([$memberId, $companyId]);
    $m = $st->fetch();
    if (!$m) throw new AppError('לא נמצא בצוות', 404);
    if ($m['role'] === 'owner') throw new AppError('את הבעלים לא משנים מכאן');
    return $m;
}

function teamUpdate(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $m = teamMember($companyId, intIn($in, 'id'));
    [$role, $siteId] = roleAndSite($companyId, $in);
    db()->prepare('UPDATE members SET role = ?, site_id = ? WHERE id = ?')->execute([$role, $siteId, $m['id']]);
    audit($companyId, (int) $person['id'], 'team.update', 'members:' . $m['id'],
          ['role' => $m['role'], 'site_id' => $m['site_id']], ['role' => $role, 'site_id' => $siteId]);
    return companyGet($person, $companyId);
}

/** הסרה מהצוות — סימון "עזב", לא מחיקה: היומן והאישורים שלו נשארים. */
function teamRemove(array $person, array $in): array {
    $companyId = intIn($in, 'company_id');
    requireMember($person, $companyId, ['owner']);
    $m = teamMember($companyId, intIn($in, 'id'));
    db()->prepare("UPDATE members SET status = 'left', end_date = ? WHERE id = ?")->execute([gmdate('Y-m-d'), $m['id']]);
    audit($companyId, (int) $person['id'], 'team.remove', 'members:' . $m['id'], ['role' => $m['role']], null);
    return companyGet($person, $companyId);
}
