<?php
/**
 * סקילים מותאמים.
 *
 * אופרציה שהמפתח חוזר עליה — סדר פעולות, מוסכמה בפרויקט, דרך מסוימת
 * לכתוב בדיקה — נשמרת פעם אחת ומוזרקת לכל מטלה רלוונטית, במקום להיכתב
 * שוב בכל בקשה.
 *
 * ‏המבנה מחקה את הדרך שבה סקילים באמת עובדים: לדגם נמסר רק **מדד** —
 * שם ומתי להשתמש — והוא מבקש את הגוף המלא רק כשהוא צריך אותו. סקיל
 * ארוך שנדחף לכל פרומפט מבזבז הקשר ומטשטש את המטלה עצמה.
 *
 * סקיל של פרויקט שייך לו; סקיל בלי פרויקט זמין בכולם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';

/** שם קצר ויציב — הוא המזהה שהדגם מבקש לפיו. */
function checkSkillName(string $name): string {
    $name = trim($name);
    if (!preg_match('/^[A-Za-z0-9\x{0590}-\x{05FF} ._-]{2,60}$/u', $name)) {
        throw new AppError('שם סקיל: 2-60 תווים, אותיות, ספרות, רווח, נקודה, מקף או קו תחתון');
    }
    return $name;
}

/**
 * הסקילים שחלים על פרויקט: שלו, ואלה שאינם שייכים לאף פרויקט.
 *
 * ‏$withBody נשאר כבוי כברירת מחדל — הקוראים הרגילים רוצים מדד, לא את
 * הטקסט המלא.
 */
function skillsFor(?int $projectId, bool $withBody = false): array {
    $cols = $withBody ? '*' : 'id, project_id, name, description, always, updated_at';
    $st = db()->prepare(
        "SELECT $cols FROM skills WHERE project_id IS NULL OR project_id = ? ORDER BY always DESC, name"
    );
    $st->execute([$projectId]);

    return array_map(fn($r) => $r + ['scope' => $r['project_id'] === null ? 'global' : 'project'],
                     $st->fetchAll());
}

/** סקיל יחיד לפי שם, בתחום שמותר לפרויקט הזה לראות. */
function skillByName(?int $projectId, string $name): ?array {
    // ORDER BY project_id DESC: סקיל של הפרויקט גובר על גלובלי באותו שם.
    $st = db()->prepare(
        'SELECT * FROM skills WHERE name = ? AND (project_id IS NULL OR project_id = ?)
          ORDER BY project_id DESC LIMIT 1'
    );
    $st->execute([trim($name), $projectId]);
    return $st->fetch() ?: null;
}

function saveSkill(array $user, ?int $projectId, string $name, string $description,
                   string $body, bool $always, ?int $id = null): int {
    $name = checkSkillName($name);
    if (trim($body) === '') throw new AppError('סקיל בלי תוכן אינו מלמד כלום');

    $args = [$projectId, $name, mb_substr(trim($description), 0, 300),
             mb_substr($body, 0, 20000), $always ? 1 : 0, nowTs()];

    if ($id !== null) {
        db()->prepare('UPDATE skills SET project_id=?, name=?, description=?, body=?, always=?, updated_at=?
                        WHERE id=?')->execute([...$args, $id]);
        logEvent($user, 'skill.update', $name);
        return $id;
    }

    try {
        db()->prepare('INSERT INTO skills (project_id, name, description, body, always, updated_at,
                                           created_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([...$args, $user['id'], nowTs()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new AppError("כבר קיים סקיל בשם \"$name\" בתחום הזה");
        }
        throw $e;
    }

    $newId = (int) db()->lastInsertId();
    logEvent($user, 'skill.create', $name, true, $projectId === null ? 'גלובלי' : "project:$projectId");
    return $newId;
}

function deleteSkill(array $user, int $id): void {
    $st = db()->prepare('SELECT name FROM skills WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new AppError('הסקיל לא נמצא', 404);

    db()->prepare('DELETE FROM skills WHERE id = ?')->execute([$id]);
    logEvent($user, 'skill.delete', (string) $row['name']);
}

/**
 * מה שנמסר לרץ בתחילת מטלה.
 *
 * סקיל שסומן "תמיד" נשלח עם גופו המלא — סימנו אותו ככזה בדיוק כדי
 * שיחול בלי שהדגם יצטרך לזכור לבקש. השאר מגיעים כמדד.
 */
function skillsPayload(?int $projectId): array {
    $out = [];
    foreach (skillsFor($projectId, true) as $s) {
        $out[] = [
            'name'        => $s['name'],
            'description' => $s['description'],
            'always'      => (bool) $s['always'],
            'body'        => $s['always'] ? $s['body'] : null,
        ];
    }
    return $out;
}
