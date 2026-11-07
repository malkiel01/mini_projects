<?php
/**
 * חשבון החבילה.
 *
 * לענן משתמשים משלו (CLAUDE.md, "אפליקציה פנימית עצמאית"), אבל בשלב הזה הוא **מחובר** לפלטפורמה: הכניסה רק דרך
 * השער, אוטומטית (platform/docs/auth.md). מי שהשער העביר — החשבון המקושר ל-sub שלו; אין כזה — חשבון באותו מייל,
 * ומקשרים; אין — חשבון חדש, פעיל, במכסת ברירת המחדל. מי שמגיע עם platform_admin — מנהל הענן.
 *
 * אין סיסמה: כשהענן יתנתק או יצא לדומיין משלו — כניסה משלו (שלב 5), על אותה טבלה, בלי הגירה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** הזהות שהשער כתב: {sub, name, email, email_verified, platform_admin}. מפתח שאינו HTTP_* — לא ניתן לזייף בבקשה. */
function platformIdentity(): ?array {
    $id = json_decode((string) ($_SERVER['PLATFORM_USER'] ?? ''), true);
    if (!is_array($id) || !is_string($id['sub'] ?? null) || $id['sub'] === '') return null;
    return $id;
}

function getAccount(string $id): array {
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) throw new AppError('חשבון לא נמצא', 404, 'not_found');
    return $a;
}

/** החשבון בענן של משתמש הפלטפורמה — מקושר, לפי מייל, או חדש. השם, המייל והתפקיד מתעדכנים בכל כניסה. */
function platformAccount(array $id): array {
    $pdo = db();
    $sub = $id['sub'];
    $email = is_string($id['email'] ?? null) ? mb_strtolower(trim($id['email'])) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    $name = mb_substr(trim((string) ($id['name'] ?? '')), 0, 60);
    $admin = ($id['platform_admin'] ?? false) === true;

    $st = $pdo->prepare('SELECT * FROM accounts WHERE platform_sub = ?');
    $st->execute([$sub]);
    $a = $st->fetch();

    if (!$a && $email !== '') {
        $st = $pdo->prepare('SELECT * FROM accounts WHERE email = ?');
        $st->execute([$email]);
        if ($a = $st->fetch()) {
            if ($a['platform_sub'] !== null) throw new AppError('כתובת הדוא"ל הזו כבר מקושרת לחשבון אחר — פנו למנהל', 403, 'forbidden');
            $pdo->prepare('UPDATE accounts SET platform_sub = ? WHERE id = ?')->execute([$sub, $a['id']]);
        }
    }

    if (!$a) {
        $aid = randomId('a_');
        $pdo->prepare('INSERT INTO accounts (id, platform_sub, email, name, role, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$aid, $sub,
                       $email !== '' ? $email : 'platform-' . substr(hash('sha256', $sub), 0, 16) . '@platform.invalid',
                       $name !== '' ? $name : ($email !== '' ? explode('@', $email)[0] : 'משתמש'),
                       $admin ? 'admin' : 'user', nowIso()]);
        return getAccount($aid);
    }

    // מתעדכנים מהפלטפורמה: שם, מייל (אם השתנה ואינו תפוס), ומנהל — רק קידום. הורדה מניהול — בממשק הניהול.
    $set = [];
    $args = [];
    if ($name !== '' && $name !== $a['name']) { $set[] = 'name = ?'; $args[] = $name; }
    if ($email !== '' && $email !== mb_strtolower($a['email'])) {
        $st = $pdo->prepare('SELECT 1 FROM accounts WHERE email = ? AND id <> ?');
        $st->execute([$email, $a['id']]);
        if (!$st->fetchColumn()) { $set[] = 'email = ?'; $args[] = $email; }
    }
    if ($admin && $a['role'] !== 'admin') { $set[] = "role = 'admin'"; }
    if ($set) {
        $args[] = $a['id'];
        $pdo->prepare('UPDATE accounts SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        $a = getAccount($a['id']);
    }
    return $a;
}

/** החשבון של מי שבדפדפן — לפי השער בלבד. אין זהות — אין משתמש (יציאה מהפלטפורמה = יציאה מכאן). */
function currentAccount(): ?array {
    $pid = platformIdentity();
    if (!$pid) return null;
    $a = platformAccount($pid);
    if ((int) $a['blocked'] === 1) throw new AppError('החשבון חסום — פנו למנהל', 403, 'forbidden');
    if ($a['last_login_at'] === null || $a['last_login_at'] < nowIso(time() - 3600)) {
        db()->prepare('UPDATE accounts SET last_login_at = ? WHERE id = ?')->execute([nowIso(), $a['id']]);
    }
    return $a;
}

function requireAccount(): array {
    $a = currentAccount();
    if (!$a) throw new AppError('נדרשת כניסה דרך הכלים הדיגיטליים', 401, 'unauthorized');
    return $a;
}

function requireAdmin(): array {
    $a = requireAccount();
    if ($a['role'] !== 'admin') throw new AppError('רק מנהל הענן', 403, 'forbidden');
    return $a;
}

/** המכסה בפועל: של החשבון אם המנהל קבע, אחרת ברירת המחדל. */
function accountQuota(array $a): int {
    return $a['quota'] !== null ? (int) $a['quota'] : (int) setting('default_quota');
}

function publicAccount(array $a): array {
    return ['id' => $a['id'], 'name' => $a['name'], 'email' => $a['email'], 'role' => $a['role']];
}
