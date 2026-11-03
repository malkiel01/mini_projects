<?php
/**
 * שלב 5 (docs/spec.md §6) — אישורים ומדיניות.
 *
 * צ'אט שצריך רשות לפעולה רגישה שואל קודם את המדיניות (policy). אם אין
 * "תמיד" — הוא מבקש אישור (ask-approval), והמטלה משתחררת ומחכה. אדם מחליט
 * (decide): פעם אחת / תמיד / לא / אף פעם. כל החלטה מחזירה את המטלה לתור
 * (answered) — גם "לא", כדי שהצ'אט יקבל את הדחייה ויבחר דרך אחרת.
 *
 * הקטלוג קבוע בקוד. סוג חדש נכנס ב-PR, לא מהממשק.
 */
declare(strict_types=1);

/**
 * ‏always: yes — מותר "תמיד"; warn — "תמיד" דורש להקליד את שם הסוג;
 * no — "תמיד" אסור (כן מותר "אף פעם").
 * מקור: creative-suite/docs/spec/command-center.md §1.3.
 */
const APPROVAL_TYPES = [
    'merge-own-pr'          => ['label' => 'מיזוג PR שפתחתי לענף שלי ל-main', 'always' => 'yes',  'settings_hint' => 'mcp__github__merge_pull_request'],
    'push-own-branch'       => ['label' => 'push לענף שלי',                    'always' => 'yes',  'settings_hint' => 'Bash(git push -u origin:*)'],
    'send-team-message'     => ['label' => 'שליחת הודעה לצ\'אט אחר בצוות',      'always' => 'yes',  'settings_hint' => 'mcp__claude-code-remote__create_trigger'],
    'format-change-request' => ['label' => 'בקשת שינוי בפורמט / בסכמה',         'always' => 'yes',  'settings_hint' => ''],
    'add-vendor-lib'        => ['label' => 'הוספת ספרייה ל-vendor/ (MIT/BSD/Apache)', 'always' => 'yes', 'settings_hint' => ''],
    'add-exclude'           => ['label' => 'הוספת --exclude ב-deploy.yml לתיקיית נתונים', 'always' => 'warn', 'settings_hint' => 'Edit(.github/workflows/deploy.yml) — פעם אחת בלבד, לא allow'],
    'change-deploy'         => ['label' => 'כל שינוי אחר ב-deploy.yml',          'always' => 'no',   'settings_hint' => 'deny Edit(.github/workflows/deploy.yml)'],
    'delete-files'          => ['label' => 'מחיקת קבצים בריפו (לא בתיקייה שלי)', 'always' => 'no',   'settings_hint' => 'deny'],
    'delete-user-data'      => ['label' => 'מחיקת נתוני משתמשים / מסד בשרת',     'always' => 'no',   'settings_hint' => 'deny'],
    'secrets'               => ['label' => 'קריאה / כתיבה של סודות',              'always' => 'no',   'settings_hint' => ''],
    'touch-other-project'   => ['label' => 'נגיעה בתיקייה של פרויקט אחר',        'always' => 'no',   'settings_hint' => 'deny'],
    // שלב 14 (§5.13.2) — בלי "תמיד" בכלל
    'push-protected'        => ['label' => 'push בכוח, או push ל-main',           'always' => 'no',   'settings_hint' => 'deny'],
    'change-gate'           => ['label' => 'שינוי השער עצמו — ההגדרות וקובצי הגשר', 'always' => 'no', 'settings_hint' => 'deny'],
    'run-remote-script'     => ['label' => 'הרצת סקריפט שהורד מהרשת',              'always' => 'no',   'settings_hint' => 'deny'],
    // שלב 18 (§15.7): מטלת מנוע שנוצרה בזמן מעבר ל-☁️ — כל אחת מאושרת בנפרד
    'engine-failover'       => ['label' => 'הרצת המנוע למטלה מתוך מעבר ל-☁️',     'always' => 'no',   'settings_hint' => ''],
    // בקשה בלי סוג מתאים — תמיד פעם אחת: אין לה מדיניות
    'other'                 => ['label' => 'אחר',                                 'always' => 'no',   'settings_hint' => ''],
];

const DECISIONS = ['once', 'always', 'deny', 'never'];
const DECISION_LABEL = ['once' => 'אושר פעם אחת', 'always' => 'אושר תמיד', 'deny' => 'נדחה', 'never' => 'נדחה תמיד'];

function migrateApprovals(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS policy_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            type        TEXT NOT NULL,
            decision    TEXT NOT NULL,
            by_user     INTEGER,
            at          INTEGER NOT NULL,
            prev        TEXT NOT NULL DEFAULT '',
            source_task INTEGER
        );
    ");
    addColumn($pdo, 'policy_log', 'project_id', 'INTEGER');

    /*
     * שלב 7b (§6.1): כלל לכל פרויקט. המפתח (project_id, type), ו-NULL = כל הלוח.
     * SQLite לא משנה מפתח ראשי — בונים טבלה חדשה ומעתיקים: הכללים הקיימים נשארים
     * כלליים לכל הלוח, כך ששום התנהגות לא משתנה בפריסה.
     */
    $has = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'policy'")->fetchColumn();
    $cols = $has ? array_column($pdo->query('PRAGMA table_info(policy)')->fetchAll(), 'name') : [];
    if ($has && !in_array('project_id', $cols, true)) {
        $pdo->beginTransaction();
        try {
            $pdo->exec('ALTER TABLE policy RENAME TO policy_v1');
            policyTable($pdo);
            $pdo->exec('INSERT INTO policy (project_id, type, decision, by_user, at, prev, source_task)
                        SELECT NULL, type, decision, by_user, at, prev, source_task FROM policy_v1');
            $pdo->exec('DROP TABLE policy_v1');
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    } elseif (!$has) {
        policyTable($pdo);
    }
}

function policyTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE policy (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id  INTEGER,
            type        TEXT NOT NULL,
            decision    TEXT NOT NULL,
            by_user     INTEGER,
            at          INTEGER NOT NULL,
            prev        TEXT NOT NULL DEFAULT '',
            source_task INTEGER
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_policy_key ON policy(IFNULL(project_id, 0), type);
    ");
}

function approvalType(string $type): string {
    return isset(APPROVAL_TYPES[$type]) ? $type : 'other';
}

function approvalTypes(): array {
    $out = [];
    foreach (APPROVAL_TYPES as $t => $m) $out[] = ['type' => $t] + $m;
    return $out;
}

/** הכלל שנקבע במקום אחד בדיוק (פרויקט, או null = כל הלוח). */
function localPolicy(string $type, ?int $projectId): ?array {
    $st = $projectId === null
        ? db()->prepare('SELECT * FROM policy WHERE project_id IS NULL AND type = ?')
        : db()->prepare('SELECT * FROM policy WHERE project_id = ? AND type = ?');
    $st->execute($projectId === null ? [$type] : [$projectId, $type]);
    return $st->fetch() ?: null;
}

/** הפרויקט ואבותיו, מהקרוב לרחוק: [[id, name], …]. */
function projectChain(?int $projectId): array {
    $out = [];
    $st = db()->prepare('SELECT id, name, parent_id FROM projects WHERE id = ?');
    for ($id = $projectId, $i = 0; $id && $i < 16; $i++) {
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) break;
        $out[] = [(int) $r['id'], (string) $r['name']];
        $id = (int) ($r['parent_id'] ?? 0);
    }
    return $out;
}

/**
 * פתרון (§6.1): הפרויקט ← כל אב עד השורש ← כל הלוח ← ask. הקרוב גובר.
 * ‏source: {project_id, name} | 'board' | null.
 */
function policyResolve(string $type, ?int $projectId = null): array {
    $type = approvalType($type);
    foreach (projectChain($projectId) as [$pid, $name]) {
        if ($r = localPolicy($type, $pid)) return ['verdict' => $r['decision'], 'source' => ['project_id' => $pid, 'name' => $name]];
    }
    if ($r = localPolicy($type, null)) return ['verdict' => $r['decision'], 'source' => 'board'];
    return ['verdict' => 'ask', 'source' => null];
}

/** allow | deny | ask */
function policyVerdict(string $type, ?int $projectId = null): string {
    return policyResolve($type, $projectId)['verdict'];
}

/** ‏$decision: allow | deny | '' (מחיקת הכלל המקומי). כל שינוי נרשם ביומן, עם מה שהיה כאן. */
function setPolicy(string $type, string $decision, ?array $user, ?int $taskId, ?int $projectId = null): void {
    $prev = (string) (localPolicy($type, $projectId)['decision'] ?? 'ask');
    $by = $user ? (int) $user['id'] : null;
    // מחיקה והכנסה (ולא upsert על אינדקס של ביטוי) — עובד בכל גרסת SQLite שבשרת
    $projectId === null
        ? db()->prepare('DELETE FROM policy WHERE project_id IS NULL AND type = ?')->execute([$type])
        : db()->prepare('DELETE FROM policy WHERE project_id = ? AND type = ?')->execute([$projectId, $type]);
    if ($decision !== '') {
        db()->prepare('INSERT INTO policy (project_id, type, decision, by_user, at, prev, source_task) VALUES (?,?,?,?,?,?,?)')
            ->execute([$projectId, $type, $decision, $by, nowTs(), $prev, $taskId]);
    }
    db()->prepare('INSERT INTO policy_log (project_id, type, decision, by_user, at, prev, source_task) VALUES (?,?,?,?,?,?,?)')
        ->execute([$projectId, $type, $decision === '' ? 'ask' : $decision, $by, nowTs(), $prev, $taskId]);
}

/** מי רשאי לקבוע כלל: בפרויקט — מי שרשאי להחליט בו (canAnswer); לכל הלוח — בעל הלוח. */
function requirePolicyWriter(array $user, ?int $projectId): void {
    if ($projectId === null) {
        if (authorRole($user) !== 'owner') throw new AppError('אישורים כלליים לכל הלוח — בעל הלוח בלבד', 403);
        return;
    }
    requireProject($projectId, $user, 'read');
    if (!canAnswer($user, ['project_id' => $projectId])) {
        throw new AppError(projectOwnerOnly($projectId) ? 'בענף הזה רק בעל הלוח מחליט' : 'נדרשת הרשאת כתיבה בפרויקט', 403);
    }
}

/**
 * ‏policy-set: ask | allow | deny. ‏ask מוחק רק את הכלל של המקום הזה (והירושה חוזרת).
 * אותן מגבלות כמו ב-decide: no — בלי allow; warn + allow — להקליד את שם הסוג; other — אין כללים.
 */
function policySet(array $user, ?int $projectId, string $type, string $verdict, string $confirmType = ''): array {
    if (!isset(APPROVAL_TYPES[$type])) throw new InvalidArgumentException('סוג לא מוכר');
    if (!in_array($verdict, ['ask', 'allow', 'deny'], true)) throw new InvalidArgumentException('verdict: ask, allow או deny');
    requirePolicyWriter($user, $projectId);
    $m = APPROVAL_TYPES[$type];
    if ($type === 'other' && $verdict !== 'ask') throw new AppError('לבקשה בלי סוג מהקטלוג אין כללים — תמיד שואל', 422);
    if ($verdict === 'allow' && $m['always'] === 'no') throw new AppError("לסוג \"{$m['label']}\" אין \"תמיד מותר\"", 422);
    if ($verdict === 'allow' && $m['always'] === 'warn' && trim($confirmType) !== $type) {
        throw new AppError("\"תמיד מותר\" לסוג הזה דורש להקליד את שמו: $type", 422);
    }
    $now = (string) (localPolicy($type, $projectId)['decision'] ?? 'ask');
    if ($now !== $verdict) {
        setPolicy($type, $verdict === 'ask' ? '' : $verdict, $user, null, $projectId);
        logEvent($user, 'policy.set', ($projectId === null ? 'board' : "project:$projectId") . ":$type", true, "$now → $verdict");
    }
    return policyResolve($type, $projectId) + ['local' => $verdict === 'ask' ? null : $verdict];
}

/**
 * ‏policy-list: לכל סוג בקטלוג — הערך האפקטיבי, מאיפה הוא, והכלל המקומי; ויומן של
 * המקום הזה (100 אחרונים), כולל ההחלטות החד-פעמיות (once / deny) על מטלות הפרויקט.
 */
function policyList(?int $projectId = null): array {
    $rows = [];
    foreach (APPROVAL_TYPES as $type => $m) {
        if ($type === 'other') continue;
        $local = localPolicy($type, $projectId);
        $rows[] = ['type' => $type, 'label' => $m['label'], 'always' => $m['always'], 'covers' => GATE_COVERS[$type] ?? '',
                   'local' => $local['decision'] ?? null, 'local_at' => $local ? (int) $local['at'] : null]
                  + policyResolve($type, $projectId);
    }
    $log = db()->prepare('SELECT l.*, u.display_name by_name, t.title task_title FROM policy_log l
                            LEFT JOIN users u ON u.id = l.by_user LEFT JOIN tasks t ON t.id = l.source_task
                           WHERE ' . ($projectId === null ? 'l.project_id IS NULL' : 'l.project_id = ?') . '
                           ORDER BY l.id DESC LIMIT 100');
    $log->execute($projectId === null ? [] : [$projectId]);
    $history = array_map(fn ($r) => ['at' => (int) $r['at'], 'kind' => 'rule', 'type' => $r['type'],
        'label' => APPROVAL_TYPES[$r['type']]['label'] ?? $r['type'], 'prev' => $r['prev'], 'decision' => $r['decision'],
        'by_name' => $r['by_name'], 'task_id' => $r['source_task'] === null ? null : (int) $r['source_task'],
        'task_title' => $r['task_title']], $log->fetchAll());
    // החלטות של פעם אחת על מטלות של הפרויקט — כדי שיראו כל מה שאושר ונדחה, לא רק כללים
    if ($projectId !== null) {
        $n = db()->prepare("SELECT n.body, n.created_at, n.author, n.task_id, t.title FROM notes n JOIN tasks t ON t.id = n.task_id
                             WHERE n.kind = 'decision' AND t.project_id = ? ORDER BY n.id DESC LIMIT 100");
        $n->execute([$projectId]);
        foreach ($n->fetchAll() as $r) {
            $d = json_decode((string) $r['body'], true) ?: [];
            if (!in_array($d['decision'] ?? '', ['once', 'deny'], true)) continue;   // always/never — כבר ביומן הכללים
            $t = approvalType((string) ($d['type'] ?? ''));
            $history[] = ['at' => (int) $r['created_at'], 'kind' => 'once', 'type' => $t, 'label' => APPROVAL_TYPES[$t]['label'],
                          'prev' => '', 'decision' => $d['decision'], 'by_name' => $r['author'], 'note' => (string) ($d['note'] ?? ''),
                          'task_id' => (int) $r['task_id'], 'task_title' => $r['title']];
        }
    }
    usort($history, fn ($a, $b) => $b['at'] <=> $a['at']);
    return ['project_id' => $projectId, 'types' => $rows, 'history' => array_slice($history, 0, 100), 'gate' => gateHistory($projectId),
            'counts' => ['allow' => count(array_filter($rows, fn ($r) => $r['local'] === 'allow')),
                         'deny'  => count(array_filter($rows, fn ($r) => $r['local'] === 'deny'))]];
}

/** הבקשה האחרונה על המטלה (הערת approval), מפוענחת. */
function lastApproval(int $taskId): ?array {
    $st = db()->prepare("SELECT body, created_at FROM notes WHERE task_id = ? AND kind = 'approval' ORDER BY id DESC LIMIT 1");
    $st->execute([$taskId]);
    $row = $st->fetch();
    if (!$row) return null;
    $a = json_decode((string) $row['body'], true);
    if (!is_array($a)) return null;
    $type = approvalType((string) ($a['type'] ?? ''));
    return ['type' => $type, 'label' => APPROVAL_TYPES[$type]['label'], 'always' => APPROVAL_TYPES[$type]['always'],
            'summary' => (string) ($a['summary'] ?? ''), 'detail' => (string) ($a['detail'] ?? ''),
            'target' => (string) ($a['target'] ?? ''), 'requested_type' => (string) ($a['requested_type'] ?? $type),
            'at' => (int) $row['created_at']];
}

/**
 * עובד מבקש אישור. נשמר כהערת approval (JSON), והמטלה משתחררת כמו ב-block.
 * מערוץ — גם בועת 🔐 בערוץ.
 */
function askApproval(array $task, string $worker, array $in, ?array $channel = null): array {
    $summary = trim((string) ($in['summary'] ?? ''));
    if ($summary === '') throw new InvalidArgumentException('חסר summary — משפט אחד: מה מבקשים לעשות');
    $requested = mb_substr(trim((string) ($in['type'] ?? '')), 0, 60);
    $type = approvalType($requested);
    $data = ['type' => $type, 'requested_type' => $requested, 'summary' => mb_substr($summary, 0, 500),
             'detail' => mb_substr((string) ($in['detail'] ?? ''), 0, 20000), 'target' => mb_substr((string) ($in['target'] ?? ''), 0, 500)];
    $id = (int) $task['id'];
    addNote(db(), $id, $worker, 'approval', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    db()->prepare("UPDATE tasks SET status = 'awaiting_approval', claimed_by = '', claim_until = 0, updated_at = ? WHERE id = ?")
        ->execute([nowTs(), $id]);
    if ($channel) {
        db()->prepare('UPDATE tasks SET asked_channel_id = ? WHERE id = ?')->execute([$channel['id'], $id]);
        channelPost($channel, 'chat', "🔐 {$data['summary']}", null, $id, 'approval');
    }
    return ['type' => $type, 'verdict' => policyVerdict($type, $task['project_id'] === null ? null : (int) $task['project_id'])];
}

/**
 * אדם מחליט. רשאי — מי שרשאי לענות (canAnswer): בעל הלוח תמיד; בענף
 * owner_only — רק הוא.
 */
function decide(array $user, array $task, string $decision, string $note = '', string $confirmType = ''): array {
    if (!in_array($decision, DECISIONS, true)) throw new InvalidArgumentException('decision: once, always, deny או never');
    if (!canAnswer($user, $task)) {
        throw new AppError(projectOwnerOnly($task['project_id'] === null ? null : (int) $task['project_id'])
            ? 'בענף הזה רק בעל הלוח מחליט' : 'נדרשת הרשאת כתיבה בפרויקט', 403);
    }
    if ($task['status'] !== 'awaiting_approval') throw new AppError('המטלה לא ממתינה לאישור', 409);
    $a = lastApproval((int) $task['id']);
    if (!$a) throw new AppError('לא נמצאה בקשת האישור', 409);
    $type = $a['type'];
    $forever = $decision === 'always' || $decision === 'never';
    if ($forever && $type === 'other') throw new AppError('לבקשה בלי סוג מהקטלוג — רק פעם אחת', 422);
    if ($decision === 'always' && $a['always'] === 'no') throw new AppError("לסוג \"{$a['label']}\" אין \"תמיד\" — רק פעם אחת", 422);
    if ($decision === 'always' && $a['always'] === 'warn' && trim($confirmType) !== $type) {
        throw new AppError("\"תמיד\" לסוג הזה דורש להקליד את שמו: $type", 422);
    }

    $note = mb_substr(trim($note), 0, 5000);
    $body = json_encode(['decision' => $decision, 'type' => $type, 'note' => $note], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    addNote(db(), (int) $task['id'], (string) $user['display_name'], 'decision', $body, authorRole($user), (int) $user['id']);
    db()->prepare("UPDATE tasks SET status = 'answered', updated_at = ? WHERE id = ?")->execute([nowTs(), $task['id']]);
    // ‏"תמיד" / "אף פעם" — כלל על הפרויקט של המטלה (§6.1). מטלה בלי פרויקט — לכל הלוח
    $pid = $task['project_id'] === null ? null : (int) $task['project_id'];
    if ($forever) setPolicy($type, $decision === 'always' ? 'allow' : 'deny', $user, (int) $task['id'], $pid);
    logEvent($user, 'approval.decide', "task:{$task['id']}", true, "$type → $decision");

    // הבקשה הגיעה מערוץ — ההחלטה חוזרת אליו כהודעה נכנסת
    $msg = null;
    $cid = $task['asked_channel_id'] ?? null;
    if ($cid !== null && ($ch = getChannel((int) $cid)) && $ch['status'] === 'active') {
        $text = '🔐 ' . DECISION_LABEL[$decision] . ": {$a['summary']}" . ($note !== '' ? "\n$note" : '');
        $msg = channelInsertIn((int) $cid, authorRole($user), (int) $user['id'], $text, 'decision', (int) $task['id']);
    }
    return ['decision' => $decision, 'type' => $type, 'verdict' => policyVerdict($type, $pid), 'channel_message_id' => $msg];
}

/** הקיצור הישן: חזרה ל-ask במקום אחד (ברירת מחדל — כל הלוח). */
function revokePolicy(array $user, string $type, ?int $projectId = null): void {
    policySet($user, $projectId, $type, 'ask');
}
