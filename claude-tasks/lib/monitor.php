<?php
/**
 * שלב 8 (docs/spec.md §9; מרכז הבקרה §2) — מה Platform עושה במעקב.
 *
 *   - סיכום של 3 שורות לכל פרויקט: מה זז, מה תקוע, מה ממתין לך — במודל.
 *   - ❓ / 🔐 ממוינים לפי דחיפות (כמה זמן ממתין) — בלי מודל.
 *   - "נשאל כבר": שאלה דומה לשאלה שכבר נענתה — מציע את התשובה. בלי מודל.
 *   - התאמת סוג: בקשת אישור בלי סוג (other) — סוג מוצע מהקטלוג. בלי מודל.
 *
 * בלי cron: "בעצלות" — כשבעל הלוח פותח את הלוח ועברה שעה (monitor-lazy), ואחרי
 * block / ask-approval — לכל היותר פעם ב-10 דקות לפרויקט. ‏monitor-tick נשאר לקוראים
 * מבחוץ. אין מפתח / אין מכסה / 100% — מדלגים בשקט: monitor: "off".
 */
declare(strict_types=1);

const MONITOR_MIN_GAP  = 600;    // אחרי block / ask-approval — לא יותר מפעם ב-10 דקות
const MONITOR_STALE    = 3600;   // פתיחת הלוח — רק אם עברה שעה
const ALREADY_ASKED_MIN = 0.55;  // דמיון מילים (Jaccard) מעליו — "נשאל כבר"

function migrateMonitor(PDO $pdo): void {
    addColumn($pdo, 'projects', 'monitor_summary', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'projects', 'monitor_at', 'INTEGER NOT NULL DEFAULT 0');
}

/** מילים משמעותיות — לדמיון בין שאלות. */
function questionWords(string $s): array {
    $s = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? '');
    $w = array_filter(preg_split('/\s+/u', $s) ?: [], fn ($x) => mb_strlen($x) >= 3);
    return array_values(array_unique($w));
}

function wordSimilarity(string $a, string $b): float {
    $x = questionWords($a); $y = questionWords($b);
    if (!$x || !$y) return 0.0;
    $inter = count(array_intersect($x, $y));
    return $inter / count(array_unique(array_merge($x, $y)));
}

/** סוג מוצע לבקשת אישור בלי סוג — לפי מילים בסיכום. null — אין התאמה. */
function suggestApprovalType(string $text): ?string {
    $t = mb_strtolower($text);
    $map = [
        'merge-own-pr' => ['merge', 'מיזוג', 'למזג', 'pull request', ' pr '],
        'push-own-branch' => ['push', 'לדחוף', 'דחיפה'],
        'add-exclude' => ['--exclude', 'exclude'],
        'change-deploy' => ['deploy.yml', 'פריסה', 'deploy'],
        'delete-user-data' => ['נתוני משתמש', 'מסד', 'database', 'drop table'],
        'delete-files' => ['מחיקת קבצים', 'למחוק קבצ', 'delete file', 'rm -'],
        'secrets' => ['secret', 'סוד', 'token', 'אסימון', 'מפתח'],
        'add-vendor-lib' => ['vendor', 'ספרייה', 'library'],
        'send-team-message' => ['הודעה לצ\'אט', 'create_trigger', 'לצוות'],
        'format-change-request' => ['פורמט', 'סכמה', 'schema', 'format'],
        'touch-other-project' => ['פרויקט אחר', 'תיקייה של'],
    ];
    foreach ($map as $type => $words) foreach ($words as $w) if (str_contains(" $t ", $w)) return $type;
    return null;
}

/**
 * מה ממתין לאדם בענף — ממוין לפי דחיפות (הוותיק קודם), עם "נשאל כבר" ו"סוג מוצע".
 * בלי מודל, ולכן גם כשהמעקב כבוי.
 */
function waitingItems(int $projectId): array {
    $ids = projectSubtree($projectId);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, title, status, updated_at FROM tasks WHERE status IN ('blocked','awaiting_approval')
                          AND project_id IN ($in) ORDER BY updated_at ASC LIMIT 30");
    $st->execute($ids);
    $q = db()->prepare("SELECT body FROM notes WHERE task_id = ? AND kind IN ('question','approval') ORDER BY id DESC LIMIT 1");
    // שאלות שכבר נענו בענף: השאלה, והתשובה שאחריה
    $past = db()->prepare("SELECT q.task_id, q.body question,
                                  (SELECT a.body FROM notes a WHERE a.task_id = q.task_id AND a.kind = 'answer' AND a.id > q.id ORDER BY a.id LIMIT 1) answer
                             FROM notes q JOIN tasks t ON t.id = q.task_id
                            WHERE q.kind = 'question' AND t.project_id IN ($in) ORDER BY q.id DESC LIMIT 200");
    $past->execute($ids);
    $answered = array_values(array_filter($past->fetchAll(), fn ($r) => $r['answer'] !== null));

    $out = [];
    foreach ($st->fetchAll() as $t) {
        $q->execute([$t['id']]);
        $body = (string) ($q->fetchColumn() ?: '');
        $item = ['task_id' => (int) $t['id'], 'title' => $t['title'], 'status' => $t['status'],
                 'waiting_since' => (int) $t['updated_at'], 'already_asked' => null, 'suggested_type' => null];
        if ($t['status'] === 'blocked' && $body !== '') {
            $best = null; $score = 0.0;
            foreach ($answered as $a) {
                if ((int) $a['task_id'] === (int) $t['id']) continue;
                $s = wordSimilarity($body, (string) $a['question']);
                if ($s > $score) { $score = $s; $best = $a; }
            }
            if ($best && $score >= ALREADY_ASKED_MIN) {
                $item['already_asked'] = ['task_id' => (int) $best['task_id'], 'answer' => mb_substr((string) $best['answer'], 0, 500),
                                          'score' => round($score, 2)];
            }
        }
        if ($t['status'] === 'awaiting_approval') {
            $a = json_decode($body, true) ?: [];
            if (approvalType((string) ($a['type'] ?? '')) === 'other') {
                $item['suggested_type'] = suggestApprovalType(($a['summary'] ?? '') . ' ' . ($a['detail'] ?? '') . ' ' . ($a['requested_type'] ?? ''));
            }
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * מריץ מעקב לפרויקט. ‏$minGap — כמה זמן חייב לעבור מהריצה הקודמת (0 = עכשיו).
 * מחזיר {monitor: on|off|skipped, summary?}.
 */
function monitorTick(int $projectId, int $minGap = MONITOR_MIN_GAP, ?callable $transport = null): array {
    $st = db()->prepare('SELECT * FROM projects WHERE id = ? AND archived = 0');
    $st->execute([$projectId]);
    $p = $st->fetch();
    if (!$p) return ['monitor' => 'off', 'reason' => 'אין פרויקט'];
    if ($minGap > 0 && nowTs() - (int) $p['monitor_at'] < $minGap) return ['monitor' => 'skipped'];

    $payer = payerUser($projectId);
    $chain = $payer ? aiChain($payer) : [];
    if (!$payer || !$chain) return ['monitor' => 'off', 'reason' => 'אין מפתח'];
    try { requireQuota((int) $payer['id'], 'monitor'); }
    catch (AppError $e) { return ['monitor' => 'off', 'reason' => $e->getMessage()]; }

    $ids = projectSubtree($projectId);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $tasks = db()->prepare("SELECT id, title, status, updated_at FROM tasks WHERE project_id IN ($in)
                             AND (status NOT IN ('done','cancelled') OR updated_at > ?) ORDER BY updated_at DESC LIMIT 60");
    $tasks->execute(array_merge($ids, [nowTs() - 86400]));
    $lines = array_map(fn ($t) => "#{$t['id']} [{$t['status']}] {$t['title']}", $tasks->fetchAll());
    $waiting = waitingItems($projectId);
    $system = 'אתה עוקב אחרי פרויקט בלוח מטלות. ענה JSON בלבד: {"lines": ["מה זז", "מה תקוע", "מה ממתין לבעל הלוח"]} — '
            . 'שלוש שורות קצרות בעברית, כל אחת עד 15 מילים, בלי מספרי מטלות אם אפשר.';
    $prompt = "פרויקט: {$p['name']}\nמטלות (פתוחות, ומה שנסגר ביממה האחרונה):\n" . ($lines ? implode("\n", $lines) : '(אין)')
            . "\n\nממתינות לאדם: " . count($waiting);
    try {
        $conn = $chain[0] + ['chain' => $chain, 'user' => $payer,
                             'usage' => ['kind' => 'monitor', 'project_id' => $projectId, 'enforce' => false]];
        $d = extractJson(aiComplete($conn, $system, $prompt, 400, $transport));
        $out = array_values(array_filter(array_map(fn ($l) => mb_substr(trim((string) $l), 0, 160), (array) ($d['lines'] ?? [])), 'strlen'));
    } catch (Throwable $e) {
        logEvent(null, 'monitor.fail', "project:$projectId", false, $e->getMessage());
        return ['monitor' => 'off', 'reason' => $e->getMessage()];
    }
    $summary = ['lines' => array_slice($out, 0, 3), 'at' => nowTs()];
    db()->prepare('UPDATE projects SET monitor_summary = ?, monitor_at = ? WHERE id = ?')
        ->execute([json_encode($summary, JSON_UNESCAPED_UNICODE), nowTs(), $projectId]);
    return ['monitor' => 'on', 'summary' => $summary];
}

/** אחרי block / ask-approval — לכל היותר פעם ב-10 דקות לפרויקט. שגיאה כאן לעולם לא מפילה את הקורא. */
function monitorAfterEvent(?int $projectId): void {
    if (!$projectId) return;
    try { monitorTick($projectId, MONITOR_MIN_GAP); } catch (Throwable) { /* מעקב לא חוסם עבודה */ }
}

/** פתיחת הלוח: עד $max פרויקטים שעברה שעה מהמעקב האחרון — קודם אלה שממתין בהם משהו. */
function monitorLazy(array $user, int $max = 2, ?callable $transport = null): array {
    $done = [];
    $rows = db()->query("SELECT p.id, (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id
                                         AND t.status IN ('blocked','awaiting_approval')) waiting
                           FROM projects p WHERE p.archived = 0 AND p.monitor_at < " . (nowTs() - MONITOR_STALE) . "
                            AND EXISTS (SELECT 1 FROM tasks t WHERE t.project_id = p.id AND t.status NOT IN ('done','cancelled'))
                          ORDER BY waiting DESC, p.monitor_at ASC LIMIT 20")->fetchAll();
    foreach ($rows as $r) {
        if (count($done) >= $max) break;
        if (projectLevel((int) $r['id'], $user) === null && ($user['role'] ?? '') !== 'admin') continue;
        $done[(int) $r['id']] = monitorTick((int) $r['id'], MONITOR_STALE, $transport)['monitor'];
    }
    return $done;
}

/** לתצוגה: הסיכום האחרון והממתינים. */
function projectMonitor(int $projectId): array {
    $st = db()->prepare('SELECT monitor_summary FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    $s = json_decode((string) ($st->fetchColumn() ?: ''), true);
    $payer = projectPayer($projectId);
    return ['summary' => is_array($s) ? $s : null, 'waiting' => waitingItems($projectId),
            // שאלות בחירה פתוחות בצ'אטים של הענף (§5.10) — גם הן ממתינות לך
            'choices' => openChoices(projectSubtree($projectId)),
            'monitor' => $payer && platformAvailable($payer) ? 'on' : 'off'];
}
