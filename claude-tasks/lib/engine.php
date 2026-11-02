<?php
/**
 * מנוע ההרצה.
 *
 * הלוח אינו מריץ את המטלה בעצמו — אחסון PHP משותף לא יכול להחזיק תהליך
 * שמשכפל ריפו, מתקין תלויות ומריץ בדיקות. במקום זה הוא מתקין workflow
 * בריפו של המשתמש ומפעיל אותו. הריצה של גיטהאב עושה את העבודה בסביבה
 * שכבר יש לה גישה לקוד, וכותבת את התוצאה חזרה ללוח.
 *
 * שני קבצים מותקנים בריפו:
 *   .github/workflows/claude-agent.yml   ההפעלה
 *   .github/claude-agent/run.mjs         הלולאה עצמה
 *
 * ושני סודות:
 *   AGENT_BOARD_TOKEN  אסימון של הפרויקט הזה בלבד
 *   AGENT_AI_KEY       מפתח הספק של מי שהתקין
 */

declare(strict_types=1);

require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/github.php';

const ENGINE_WORKFLOW = 'claude-agent.yml';
const ENGINE_PATHS = [
    '.github/workflows/claude-agent.yml' => __DIR__ . '/../agent/workflow.yml',
    '.github/claude-agent/run.mjs'       => __DIR__ . '/../agent/run.mjs',
];

/*
 * שלב 10 (docs/spec.md §11): מנוע שני — Claude Code עצמו ב-Actions, לצד run.mjs ולא
 * במקומו. ‏projects.engine: loop | claude-code | '' (יורש מהאב; בשורש — loop).
 */
const ENGINES = ['loop', 'claude-code'];
const CC_WORKFLOW = 'claude-code-agent.yml';
const CC_PATHS = [
    '.github/workflows/claude-code-agent.yml' => __DIR__ . '/../agent/claude-code.yml',
    '.github/claude-agent/finish.mjs'         => __DIR__ . '/../agent/finish.mjs',
];

function migrateEngine(PDO $pdo): void {
    addColumn($pdo, 'projects', 'engine', "TEXT NOT NULL DEFAULT ''");
}

/** המנוע האפקטיבי: של הפרויקט, או של האב הקרוב שבחר; בשורש — loop. */
function projectEngine(array $project): string {
    $st = db()->prepare('SELECT engine, parent_id FROM projects WHERE id = ?');
    for ($id = (int) $project['id'], $i = 0; $id && $i < 16; $i++) {
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) break;
        if (in_array($r['engine'], ENGINES, true)) return $r['engine'];
        $id = (int) ($r['parent_id'] ?? 0);
    }
    return 'loop';
}

/** ‏update-project {engine}: מנהל הפרויקט. החלפה — המנוע צריך התקנה מחדש (קבצים אחרים בריפו). */
function setProjectEngine(array $user, array $project, string $engine): string {
    $engine = trim($engine);
    if ($engine !== '' && !in_array($engine, ENGINES, true)) throw new InvalidArgumentException('engine: loop או claude-code');
    $before = projectEngine($project);
    db()->prepare('UPDATE projects SET engine = ? WHERE id = ?')->execute([$engine, $project['id']]);
    $after = projectEngine($project);
    if ($after !== $before) db()->prepare('UPDATE projects SET engine_at = 0 WHERE id = ?')->execute([$project['id']]);
    logEvent($user, 'project.engine', 'project:' . $project['id'], true, "$before → $after");
    return $after;
}

/**
 * הכתובת שאליה הריצה תדווח בחזרה.
 *
 * נגזרת מהבקשה הנוכחית, כי זו הכתובת שהמשתמש באמת משתמש בה. אפשר
 * לדרוס בהגדרות כשהלוח יושב מאחורי פרוקסי ששם ההוסט שלו שונה.
 */
function boardUrl(): string {
    $override = trim((string) (config()['board_url'] ?? ''));
    if ($override !== '') return rtrim($override, '/');

    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path   = (string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php');

    return "$scheme://$host$path";
}

/** אסימון העובד של הפרויקט. נוצר בהתקנה הראשונה. */
function projectAgentToken(int $projectId, bool $regenerate = false): string {
    $st = db()->prepare('SELECT agent_token FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    $row = $st->fetch();

    $existing = $row ? decryptSecret((string) $row['agent_token']) : '';
    if ($existing !== '' && !$regenerate) return $existing;

    $token = bin2hex(random_bytes(24));
    db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')
        ->execute([encryptSecret($token), $projectId]);
    return $token;
}

/**
 * מתקין את המנוע בריפו.
 *
 * כתיבת קובץ קיימת מחייבת את ה-sha הנוכחי שלו, ולכן קוראים לפני שכותבים.
 * קובץ שאינו קיים מחזיר 404 — וזה המצב הרגיל בהתקנה ראשונה.
 */
function installEngine(array $user, array $project, array $conn, ?callable $transport = null): array {
    $token = requireGithubToken($user, $project);
    $owner = (string) $project['repo_owner'];
    $repo  = (string) $project['repo_name'];
    if ($repo === '') throw new AppError('לפרויקט לא מקושר ריפו');
    $cc = projectEngine($project) === 'claude-code';
    if ($cc && ($conn['provider'] ?? '') !== 'anthropic') {
        throw new AppError('Claude Code צריך חיבור Anthropic — הגדרות ← חיבורים ← "+ חיבור" ← Anthropic');
    }

    $branch  = (string) $project['default_branch'];
    $written = [];

    foreach ($cc ? CC_PATHS : ENGINE_PATHS as $target => $source) {
        $content = (string) file_get_contents($source);

        $sha = '';
        try {
            $existing = ghContents($token, $owner, $repo, $target, $branch, $transport);
            if (($existing['kind'] ?? '') === 'file') {
                if ($existing['content'] === $content) { $written[$target] = 'ללא שינוי'; continue; }
                $sha = (string) $existing['sha'];
            }
        } catch (Throwable $e) {
            // 404 = הקובץ טרם קיים. כל שגיאה אחרת תצוץ שוב בכתיבה עצמה.
        }

        ghPutFile($token, $owner, $repo, $target, $content,
                  $sha === '' ? 'התקנת מנוע ההרצה של לוח המטלות' : 'עדכון מנוע ההרצה',
                  $branch, $sha, $transport);
        $written[$target] = $sha === '' ? 'נוצר' : 'עודכן';
    }

    // הסודות נכתבים אחרי הקבצים: אם הכתיבה נכשלה, אין טעם שהמפתח יישב
    // בריפו שאין בו מנוע.
    ghSetSecret($token, $owner, $repo, 'AGENT_BOARD_TOKEN', projectAgentToken((int) $project['id']), $transport);
    // Claude Code קורא את המפתח בשם הסטנדרטי שלו
    ghSetSecret($token, $owner, $repo, $cc ? 'ANTHROPIC_API_KEY' : 'AGENT_AI_KEY', (string) $conn['key'], $transport);

    db()->prepare('UPDATE projects SET engine_at = ? WHERE id = ?')->execute([nowTs(), $project['id']]);
    logEvent($user, 'engine.install', "$owner/$repo", true, implode(', ', array_keys($written)));

    return ['files' => $written, 'board_url' => boardUrl()];
}

/**
 * שולח מטלה להרצה.
 *
 * הריצה מדווחת חזרה דרך הרשת, ולכן כתובת שאינה נגישה מבחוץ תיצור ריצה
 * שתעבוד ותיעלם בלי שאיש יידע. עדיף לעצור כאן עם הסבר.
 */
function dispatchTask(array $user, array $project, array $task, array $conn, ?callable $transport = null): array {
    $token = requireGithubToken($user, $project);
    $board = boardUrl();

    if (!str_starts_with($board, 'https://')) {
        throw new AppError(
            "כתובת הלוח ($board) אינה https ולכן ההרצה בגיטהאב לא תוכל לדווח בחזרה. " .
            'הריצו את הלוח מכתובת ציבורית, או הגדירו board_url בהגדרות.'
        );
    }
    if ($conn['key'] === '') {
        throw new AppError('לא מחובר ספק שיבצע את המטלה. הגדרות ← ספקי בינה מלאכותית');
    }

    $cc = projectEngine($project) === 'claude-code';
    if ($cc && ($conn['provider'] ?? '') !== 'anthropic') {
        throw new AppError('הפרויקט רץ ב-Claude Code, וזה דורש חיבור Anthropic');
    }
    $inputs = $cc ? [
        'task_id'     => (string) $task['id'],
        'board_url'   => $board,
        'model'       => (string) $conn['model'],
        'base_branch' => (string) $project['default_branch'],
    ] : [
        'task_id'       => (string) $task['id'],
        'board_url'     => $board,
        'provider'      => (string) $conn['provider'],
        'model'         => (string) $conn['model'],
        'base_branch'   => (string) $project['default_branch'],
        'check_command' => (string) $project['check_command'],
    ];

    try {
        ghDispatchWorkflow($token, (string) $project['repo_owner'], (string) $project['repo_name'],
                           $cc ? CC_WORKFLOW : ENGINE_WORKFLOW, (string) $project['default_branch'], $inputs, $transport);
    } catch (Throwable $e) {
        logEvent($user, 'engine.dispatch', 'task:' . $task['id'], false, $e->getMessage());
        // ‏404 כאן פירושו בדרך כלל שהקובץ נכתב לפני רגע וגיטהאב טרם קלט אותו.
        if (str_contains($e->getMessage(), 'לא נמצא')) {
            throw new AppError('המנוע לא נמצא בריפו. התקינו אותו, והמתינו דקה אם ההתקנה זה עתה הסתיימה.');
        }
        throw $e;
    }

    db()->prepare("UPDATE tasks SET status = 'open', updated_at = ? WHERE id = ? AND status = 'blocked'")
        ->execute([nowTs(), $task['id']]);

    logEvent($user, 'engine.dispatch', 'task:' . $task['id'], true, $conn['provider'] . ' · ' . $conn['model']);
    return ['dispatched' => true, 'provider' => $conn['provider'], 'model' => $conn['model'], 'engine' => $cc ? 'claude-code' : 'loop'];
}

/** מצב המנוע בפרויקט, לתצוגה. */
function engineStatus(array $project): array {
    return [
        'engine'        => projectEngine($project),
        'engine_own'    => (string) ($project['engine'] ?? ''),
        'installed'     => ((int) $project['engine_at']) > 0,
        'installed_at'  => (int) $project['engine_at'],
        'check_command' => (string) $project['check_command'],
        'board_url'     => boardUrl(),
        'board_public'  => str_starts_with(boardUrl(), 'https://'),
    ];
}
