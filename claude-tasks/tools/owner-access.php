<?php
/**
 * גישה חוזרת לחשבון — מסוף השרת בלבד (cPanel → Terminal). לא נגיש מהרשת.
 *
 * הלוח מקשר חשבון ישן לכלים הדיגיטליים לבד, לפי מייל (lib/platform.php,
 * platformFindAccount): חשבון שעוד לא מקושר ושהמייל שלו זהה למייל בפלטפורמה —
 * מתקשר בכניסה הבאה. לחשבון שנפתח בסיסמה אין מייל, ולכן הוא לא נמצא, ומופיע
 * המסך החד-פעמי. הכלי הזה כותב את המייל לחשבון — ותו לא.
 *
 *   php tools/owner-access.php                       רשימת המשתמשים. לא משנה כלום
 *   php tools/owner-access.php link <id> <email>     גיבוי, ואז מייל לחשבון <id>
 *   php tools/owner-access.php password <id>         גיבוי, ואז סיסמה חדשה (מוקלדת, לא מוצגת)
 *   php tools/owner-access.php tasks                 כמה מטלות לכל created_by — גם מזהים בלי משתמש. לא משנה כלום
 *
 * לפני כל שינוי — עותק של המסד ב-data/tasks.sqlite.bak-<תאריך>. הפריסה לא
 * דורסת אותו (--exclude של data/*.sqlite*), ו-data/.htaccess חוסם אותו מהרשת.
 * לא נוצר חשבון ולא נמחק כלום.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/db.php';

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function bye(string $s): void { fwrite(STDERR, "✗ $s\n"); exit(1); }

function backup(): string {
    if (!is_file(DB_FILE)) bye('אין מסד ב-' . DB_FILE);
    $to = DB_FILE . '.bak-' . date('Ymd-His');
    for ($i = 2; file_exists($to); $i++) $to = DB_FILE . '.bak-' . date('Ymd-His') . "-$i";   // לא לדרוס גיבוי קודם
    // VACUUM INTO — עותק עקבי גם אם מישהו כותב באותו רגע (SQLite 3.27+)
    try {
        db()->exec('VACUUM INTO ' . db()->quote($to));
    } catch (Throwable $e) {
        if (!copy(DB_FILE, $to)) bye('הגיבוי נכשל — לא שיניתי כלום');
    }
    if (!is_file($to) || filesize($to) === 0) bye('הגיבוי נכשל — לא שיניתי כלום');
    out("✓ גיבוי: $to (" . filesize($to) . ' בתים)');
    return $to;
}

function userRow(int $id): array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) bye("אין משתמש עם id $id");
    return $u;
}

$cmd = $argv[1] ?? 'list';

if ($cmd === 'list') {
    out('id | שם משתמש | שם תצוגה | תפקיד | מייל | מקושר לפלטפורמה | סיסמה | מטלות שיצר | נוצר');
    $rows = db()->query("SELECT u.*, (SELECT COUNT(*) FROM tasks t WHERE t.created_by = u.id) AS n
                           FROM users u ORDER BY u.id")->fetchAll();
    foreach ($rows as $u) {
        out(implode(' | ', [
            $u['id'], $u['username'], $u['display_name'], $u['role'],
            $u['email'] !== '' ? $u['email'] : '—',
            $u['platform_sub'] !== '' ? 'כן' : 'לא',
            $u['password_hash'] !== '' ? 'יש' : 'אין',
            $u['n'], date('Y-m-d', (int) $u['created_at']),
        ]));
    }
    out();
    out('לקישור: php tools/owner-access.php link <id> <המייל בכלים הדיגיטליים>');
    exit(0);
}

/*
 * מי פתח את המטלות. עונה על "מטלות שיצר: 0" — מטלות שנפתחו בחשבון אחר
 * (למשל "אין לי חשבון"), או בחשבון שכבר לא קיים. ‏created_by ריק — מטלה בלי
 * יוצר (נפתחה לפני שנשמר). קריאה בלבד.
 */
if ($cmd === 'tasks') {
    out('created_by | שם משתמש | שם תצוגה | מטלות | פתוחות | בלי פרויקט');
    $rows = db()->query("SELECT t.created_by, u.username, u.display_name, COUNT(*) n,
                                SUM(t.status NOT IN ('done','cancelled')) open_n,
                                SUM(t.project_id IS NULL) orphan_n
                           FROM tasks t LEFT JOIN users u ON u.id = t.created_by
                          GROUP BY t.created_by ORDER BY t.created_by")->fetchAll();
    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['n'];
        out(implode(' | ', [
            $r['created_by'] ?? '—',
            $r['username'] ?? ($r['created_by'] === null ? '(ריק)' : '(אין משתמש כזה)'),
            $r['display_name'] ?? '—',
            $r['n'], (int) $r['open_n'], (int) $r['orphan_n'],
        ]));
    }
    out();
    out("סך הכול: $total מטלות");
    exit(0);
}

if ($cmd === 'link') {
    $id = (int) ($argv[2] ?? 0);
    $email = strtolower(trim((string) ($argv[3] ?? '')));
    if ($id <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) bye('שימוש: link <id> <email>');
    $u = userRow($id);
    if ($u['platform_sub'] !== '') bye("החשבון {$u['username']} כבר מקושר לפלטפורמה — אין מה לשנות");

    $st = db()->prepare("SELECT id, username, platform_sub FROM users WHERE lower(email) = ? AND id <> ?");
    $st->execute([$email, $id]);
    foreach ($st->fetchAll() as $o) {
        // חשבון אחר עם אותו מייל ייבחר לפניו (ORDER BY id), או שכבר נכנסים אליו לפי sub
        if ($o['platform_sub'] !== '' || (int) $o['id'] < $id) {
            bye("המייל כבר שייך לחשבון {$o['username']} (id {$o['id']}). לא שיניתי כלום — שלחו לי את הפלט");
        }
    }

    backup();
    db()->prepare("UPDATE users SET email = ? WHERE id = ? AND platform_sub = ''")->execute([$email, $id]);
    db()->prepare('INSERT INTO events (user_id, actor, action, target, ok, detail, ip, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, 'console', 'owner-access-link', $u['username'], 1, 'מייל לקישור אוטומטי', '', time()]);
    out("✓ החשבון {$u['username']} (id $id, {$u['role']}) — מייל: $email");
    out('  בכניסה הבאה דרך הכלים הדיגיטליים הוא יקושר לבד. שום דבר אחר לא השתנה.');
    exit(0);
}

if ($cmd === 'password') {
    $id = (int) ($argv[2] ?? 0);
    if ($id <= 0) bye('שימוש: password <id>');
    $u = userRow($id);
    $ask = function (string $prompt): string {
        fwrite(STDOUT, $prompt);
        @shell_exec('stty -echo 2>/dev/null');
        $v = rtrim((string) fgets(STDIN), "\r\n");
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
        return $v;
    };
    $p1 = $ask("סיסמה חדשה ל-{$u['username']} (לא תוצג): ");
    if (mb_strlen($p1) < 8) bye('לפחות 8 תווים — לא שיניתי כלום');
    if ($ask('שוב: ') !== $p1) bye('לא תואם — לא שיניתי כלום');

    backup();
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($p1, PASSWORD_DEFAULT), $id]);
    db()->prepare("DELETE FROM login_locks WHERE lock_key LIKE 'link:%'")->execute();
    db()->prepare('INSERT INTO events (user_id, actor, action, target, ok, detail, ip, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, 'console', 'owner-access-password', $u['username'], 1, 'סיסמה נקבעה מהמסוף', '', time()]);
    out("✓ סיסמה חדשה ל-{$u['username']}. במסך החד-פעמי: \"יש לי כבר חשבון\" — {$u['username']} והסיסמה.");
    exit(0);
}

bye("פקודה לא מוכרת: $cmd (list | link | password)");
