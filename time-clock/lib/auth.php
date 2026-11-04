<?php
/**
 * מי המבקש.
 *
 * צוות הניהול (בעלים, מנהל, מנהל סניף, מנהלת שכר) נכנס דרך הפלטפורמה: השער כותב את הזהות
 * ל-$_SERVER['PLATFORM_USER'] (platform/docs/sso.md סעיף 0), ואת זה אי אפשר לזייף מהדפדפן.
 * האדם באפליקציה מקושר ל-sub; אין אדם — נפתח מיד (ההרשאה בפלטפורמה היא האישור).
 *
 * עובדים נכנסים בקישור אישי ובקוד (lib/session.php). כניסה עצמאית כשהאפליקציה מנותקת מהפלטפורמה — טרם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

/**
 * התפקידים, מהגבוה לנמוך. הרשימה גם ב-CHECK של members.role (lib/db.php) — בדיקה ב-tests/run.php מוודאת שהן זהות.
 * שאר הקבוצות הן תתי-קבוצות שלה: מי רואה, מי עורך, ומי חבר צוות שהבעלים מוסיף.
 */
const ROLES = ['owner', 'manager', 'branch_manager', 'payroll', 'employee'];
const MANAGE_ROLES = ['owner', 'manager', 'branch_manager', 'payroll'];   // צוות הניהול
const EDIT_ROLES = ['owner', 'manager', 'branch_manager'];                // עורכים: עובדים, תיקונים, היעדרויות, עמדות
const TEAM_ROLES = ['manager', 'branch_manager', 'payroll'];              // מה שהבעלים מוסיף לצוות
const EMP_VIEW = MANAGE_ROLES;
const EMP_EDIT = EDIT_ROLES;
const CORR_EDIT = EDIT_ROLES;

function platformIdentity(): ?array {
    $id = json_decode((string) ($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_string($id['sub'] ?? null) || $id['sub'] === '') return null;
    return $id;
}

/** האדם המחובר — או 401. */
function currentPerson(): array {
    $id = platformIdentity();
    if (!$id) throw new AppError('נכנסים דרך הכלים הדיגיטליים', 401);
    return personForIdentity($id);
}

/** מנהל סניף מוגבל לסניף שלו: מחזיר את מספר הסניף. לתפקיד אחר — null (כל החברה). */
function branchSite(array $me): ?int {
    return $me['role'] === 'branch_manager' ? (int) $me['site_id'] : null;
}

/**
 * האדם של משתמש הפלטפורמה: מקושר לפי sub; אחרת — אדם שהבעלים הוסיף לצוות באותו מייל (רק כשהמייל
 * מאומת, אחרת אפשר היה להשתלט על מקום בצוות של חברה אחרת); אחרת — אדם חדש.
 */
function personForIdentity(array $id): array {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM people WHERE platform_sub = ?');
    $st->execute([$id['sub']]);
    if ($p = $st->fetch()) return $p;

    $email = is_string($id['email'] ?? null) ? mb_strtolower(trim($id['email'])) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    if ($email !== '' && !empty($id['email_verified'])) {
        $st = $pdo->prepare('SELECT * FROM people WHERE email = ? COLLATE NOCASE AND platform_sub IS NULL ORDER BY id LIMIT 1');
        $st->execute([$email]);
        if ($p = $st->fetch()) {
            $pdo->prepare('UPDATE people SET platform_sub = ? WHERE id = ?')->execute([$id['sub'], $p['id']]);
            audit(null, (int) $p['id'], 'person.link', 'people:' . $p['id'], null, ['by' => 'email']);
            return getPerson((int) $p['id']);
        }
    }

    $name = trim((string) ($id['name'] ?? ''));
    $name = mb_substr($name !== '' ? $name : ($email !== '' ? explode('@', $email)[0] : 'משתמש'), 0, 80);
    $pdo->prepare('INSERT INTO people (name, email, platform_sub, created_at) VALUES (?,?,?,?)')
        ->execute([$name, $email !== '' ? $email : null, $id['sub'], nowIso()]);
    return getPerson((int) $pdo->lastInsertId());
}

function getPerson(int $id): array {
    $st = db()->prepare('SELECT * FROM people WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) throw new AppError('לא נמצא', 404);
    return $p;
}

/**
 * החברוּת של האדם בחברה, בתפקיד מתוך הרשימה — או 403. כל פעולה על חברה עוברת כאן: אין API
 * שמקבל company_id מהדפדפן בלי לבדוק חברוּת (האפיון, פרק 7).
 */
function requireMember(array $person, int $companyId, array $roles): array {
    $st = db()->prepare("SELECT * FROM members WHERE company_id = ? AND person_id = ? AND status = 'active' ORDER BY id");
    $st->execute([$companyId, $person['id']]);
    foreach ($st->fetchAll() as $m) {
        if (in_array($m['role'], $roles, true)) return $m;
    }
    throw new AppError('אין לך הרשאה לפעולה הזו בחברה הזו', 403);
}
