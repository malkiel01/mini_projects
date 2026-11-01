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
    // בקשה בלי סוג מתאים — תמיד פעם אחת: אין לה מדיניות
    'other'                 => ['label' => 'אחר',                                 'always' => 'no',   'settings_hint' => ''],
];

const DECISIONS = ['once', 'always', 'deny', 'never'];
const DECISION_LABEL = ['once' => 'אושר פעם אחת', 'always' => 'אושר תמיד', 'deny' => 'נדחה', 'never' => 'נדחה תמיד'];

function migrateApprovals(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS policy (
            type        TEXT PRIMARY KEY,
            decision    TEXT NOT NULL,
            by_user     INTEGER,
            at          INTEGER NOT NULL,
            prev        TEXT NOT NULL DEFAULT '',
            source_task INTEGER
        );
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
}

function approvalType(string $type): string {
    return isset(APPROVAL_TYPES[$type]) ? $type : 'other';
}

function approvalTypes(): array {
    $out = [];
    foreach (APPROVAL_TYPES as $t => $m) $out[] = ['type' => $t] + $m;
    return $out;
}

/** allow | deny | ask */
function policyVerdict(string $type): string {
    $st = db()->prepare('SELECT decision FROM policy WHERE type = ?');
    $st->execute([approvalType($type)]);
    return (string) ($st->fetchColumn() ?: 'ask');
}

/** ‏$decision: allow | deny | '' (ביטול). כל שינוי נרשם ביומן. */
function setPolicy(string $type, string $decision, ?array $user, ?int $taskId): void {
    $prev = policyVerdict($type);
    $by = $user ? (int) $user['id'] : null;
    if ($decision === '') db()->prepare('DELETE FROM policy WHERE type = ?')->execute([$type]);
    else db()->prepare('INSERT INTO policy (type, decision, by_user, at, prev, source_task) VALUES (?,?,?,?,?,?)
                        ON CONFLICT(type) DO UPDATE SET decision = excluded.decision, by_user = excluded.by_user,
                                                        at = excluded.at, prev = excluded.prev, source_task = excluded.source_task')
        ->execute([$type, $decision, $by, nowTs(), $prev, $taskId]);
    db()->prepare('INSERT INTO policy_log (type, decision, by_user, at, prev, source_task) VALUES (?,?,?,?,?,?)')
        ->execute([$type, $decision === '' ? 'ask' : $decision, $by, nowTs(), $prev, $taskId]);
}

function policyList(): array {
    $cast = function (array $r): array {
        foreach (['by_user', 'source_task'] as $k) $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        $r['at'] = (int) $r['at'];
        if (isset($r['id'])) $r['id'] = (int) $r['id'];
        return $r;
    };
    $q = fn ($sql) => array_map($cast, db()->query($sql)->fetchAll());
    return [
        'policy' => $q('SELECT p.*, u.display_name by_name FROM policy p LEFT JOIN users u ON u.id = p.by_user ORDER BY p.type'),
        'log'    => $q('SELECT l.*, u.display_name by_name FROM policy_log l LEFT JOIN users u ON u.id = l.by_user ORDER BY l.id DESC LIMIT 200'),
        'types'  => approvalTypes(),
    ];
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
    return ['type' => $type, 'verdict' => policyVerdict($type)];
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
    if ($forever) setPolicy($type, $decision === 'always' ? 'allow' : 'deny', $user, (int) $task['id']);
    logEvent($user, 'approval.decide', "task:{$task['id']}", true, "$type → $decision");

    // הבקשה הגיעה מערוץ — ההחלטה חוזרת אליו כהודעה נכנסת
    $msg = null;
    $cid = $task['asked_channel_id'] ?? null;
    if ($cid !== null && ($ch = getChannel((int) $cid)) && $ch['status'] === 'active') {
        $text = '🔐 ' . DECISION_LABEL[$decision] . ": {$a['summary']}" . ($note !== '' ? "\n$note" : '');
        $msg = channelInsertIn((int) $cid, authorRole($user), (int) $user['id'], $text, 'decision', (int) $task['id']);
    }
    return ['decision' => $decision, 'type' => $type, 'verdict' => policyVerdict($type), 'channel_message_id' => $msg];
}

function revokePolicy(array $user, string $type): void {
    if (authorRole($user) !== 'owner') throw new AppError('ביטול מדיניות — בעל הלוח בלבד', 403);
    if (!isset(APPROVAL_TYPES[$type])) throw new InvalidArgumentException('סוג לא מוכר');
    if (policyVerdict($type) === 'ask') return;
    setPolicy($type, '', $user, null);
    logEvent($user, 'policy.revoke', $type);
}
