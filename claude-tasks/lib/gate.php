<?php
/**
 * שער מדיניות אחד — docs/spec.md §5.13 (שלב 14). ‏PreToolUse בכל כלי, בצ'אט, ב-Routine וב-Platform.
 *
 * ה-hook (bridge/hook-gate.sh) שולח את הפעולה; כאן היא מסווגת שוב (bridge/classify.php — אותו קוד שה-hook
 * מריץ), ומוכרעת לפי המדיניות (policyVerdict, עם ירושה — §6.1):
 *   allow  → הכלי רץ.          deny → נחסם, עם הסיבה.
 *   ask    → צ'אט אינטראקטיבי: החלון באפליקציה (permissionDecision ask) — ושלב 13 מעלה אותו ללוח, עם הסוג.
 *            Routine (ללא השגחה): כרטיס בלוח, וה-hook מחכה עד 10 דק'. אין תשובה → נדחה ("לא אושר בזמן").
 *            Platform: ask-approval על המטלה (שלב 5), וה-hook דוחה "נשלח לאישור — המטלה ממתינה".
 * כל הכרעה נרשמת ב-gate_log ("מה הוחלט" בגיליון 🔐).
 */
declare(strict_types=1);

require_once __DIR__ . '/../bridge/classify.php';

const GATE_KINDS = ['chat' => '💬 צ\'אט', 'routine' => '🔁 Routine', 'platform' => '☁️ Platform'];
/** מצבי הרשאה של Claude Code בלי אדם מול המסך (§5.13.1). */
const GATE_UNATTENDED_MODES = ['auto', 'bypassPermissions', 'dontAsk'];

function migrateGate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gate_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id  INTEGER,
            channel_id  INTEGER,
            task_id     INTEGER,
            kind        TEXT NOT NULL,
            type        TEXT NOT NULL,
            tool        TEXT NOT NULL,
            command     TEXT NOT NULL DEFAULT '',
            verdict     TEXT NOT NULL,
            how         TEXT NOT NULL,
            message_id  INTEGER,
            by_name     TEXT NOT NULL DEFAULT '',
            at          INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_gate_log_project ON gate_log(project_id, id);
    ");
}

function gateUnattended(string $mode): bool {
    return in_array($mode, GATE_UNATTENDED_MODES, true);
}

/** ‏how: policy | asked | answered | timeout | late. ‏verdict: allow | deny | ask. */
function gateLog(?int $projectId, ?int $channelId, ?int $taskId, string $kind, string $type, string $tool, string $command,
                 string $verdict, string $how, ?int $messageId = null, string $by = ''): void {
    db()->prepare('INSERT INTO gate_log (project_id, channel_id, task_id, kind, type, tool, command, verdict, how, message_id, by_name, at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$projectId, $channelId, $taskId, $kind, $type, mb_substr($tool, 0, 120), mb_substr($command, 0, 2000),
                   $verdict, $how, $messageId, mb_substr($by, 0, 120), nowTs()]);
}

function gateProjectPath(?int $projectId): string {
    if ($projectId === null) return '';
    $st = db()->prepare('SELECT path FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    return trim((string) $st->fetchColumn(), '/');
}

/** הסוג של פעולה — השרת מסווג בעצמו (לא סומך על ה-hook). */
function gateTypeFor(string $tool, array $input, ?int $projectId, array $in): ?string {
    return gateStrictest(gateClassify($tool, $input, [
        'project_path' => gateProjectPath($projectId),
        'root'         => mb_substr((string) ($in['root'] ?? ''), 0, 500),
        'cwd'          => mb_substr((string) ($in['cwd'] ?? ''), 0, 500),
        'branch'       => mb_substr((string) ($in['branch'] ?? ''), 0, 200),
        'session_id'   => mb_substr((string) ($in['session_id'] ?? ''), 0, 100),
    ]));
}

/** מה שה-hook מדפיס כשנחסם — המודל קורא את זה. */
function gateDenyText(string $type, string $why): string {
    $label = APPROVAL_TYPES[$type]['label'] ?? $type;
    return "🔐 השער של הלוח חסם: $label. $why אל תנסה דרך עוקפת; אם צריך — דווח בלוח מה נחסם ולמה.";
}

/**
 * ‏gate-check. ‏$channel — ערוץ (צ'אט / Routine), או null ו-$task — Platform.
 * קלט: tool_name, tool_input, permission_mode, root, cwd, branch, session_id.
 * מחזיר {verdict: none|allow|deny|ask, type, label, reason?, message_id?, deadline?, cache}.
 */
function gateCheck(?array $channel, ?array $task, array $in, string $worker = 'claude-code'): array {
    $tool = mb_substr(trim((string) ($in['tool_name'] ?? '')), 0, 120);
    if ($tool === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $tool)) throw new InvalidArgumentException('tool_name לא תקין');
    $input = is_array($in['tool_input'] ?? null) ? $in['tool_input'] : [];
    $json = json_encode($input, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 60000) throw new InvalidArgumentException('tool_input גדול מדי');
    $mode = (string) ($in['permission_mode'] ?? '');

    $kind = $channel ? (gateUnattended($mode) ? 'routine' : 'chat') : 'platform';
    $pid = $channel ? ((int) $channel['project_id'] ?: null)
                    : ($task && $task['project_id'] !== null ? (int) $task['project_id'] : null);
    $type = gateTypeFor($tool, $input, $pid, $in);
    if ($type === null) return ['verdict' => 'none', 'type' => null, 'kind' => $kind];

    $label = APPROVAL_TYPES[$type]['label'] ?? $type;
    $command = permissionCommand($tool, $input);
    $cid = $channel ? (int) $channel['id'] : null;
    $tid = $task ? (int) $task['id'] : null;
    $res = policyResolve($type, $pid);
    $base = ['type' => $type, 'label' => $label, 'kind' => $kind];

    if ($res['verdict'] === 'allow' && APPROVAL_TYPES[$type]['always'] !== 'no') {
        gateLog($pid, $cid, $tid, $kind, $type, $tool, $command, 'allow', 'policy');
        return $base + ['verdict' => 'allow', 'cache' => true];
    }
    if ($res['verdict'] === 'deny') {
        gateLog($pid, $cid, $tid, $kind, $type, $tool, $command, 'deny', 'policy');
        $where = is_array($res['source']) ? "בפרויקט {$res['source']['name']}" : 'בכל הלוח';
        return $base + ['verdict' => 'deny', 'cache' => true, 'reason' => gateDenyText($type, "המדיניות $where: אף פעם.")];
    }

    // ask
    if ($kind === 'platform') {
        if (!$task) throw new InvalidArgumentException('חסר task_id');
        askApproval($task, $worker, ['type' => $type, 'summary' => "$label — $tool", 'detail' => mb_substr($command, 0, 20000),
                                     'target' => $tool]);
        gateLog($pid, null, $tid, $kind, $type, $tool, $command, 'ask', 'asked');
        return $base + ['verdict' => 'deny', 'cache' => false, 'asked' => true,
                        'reason' => "🔐 נשלח לאישור בלוח (מטלה #$tid): $label. המטלה ממתינה — עצור כאן ואל תנסה דרך אחרת; "
                                  . 'כשבעל הלוח יחליט, המטלה תחזור לתור.'];
    }
    if ($kind === 'chat') {
        // יש אדם מול המסך: החלון באפליקציה, ו-PermissionRequest (שלב 13) מעלה אותו ללוח עם הסוג
        gateLog($pid, $cid, null, $kind, $type, $tool, $command, 'ask', 'asked');
        return $base + ['verdict' => 'ask', 'cache' => false,
                        'reason' => "🔐 לפי מדיניות הלוח: $label — דורש אישור."];
    }
    // Routine: כרטיס בלוח, וה-hook מחכה
    $p = permissionPost($channel, ['tool_name' => $tool, 'tool_input' => $input, 'key' => (string) ($in['key'] ?? ''),
                                   'gate' => ['type' => $type, 'kind' => $kind]]);
    if (!$p['id']) {
        // ‏⏸ או מצב app — אין מי שיענה בלוח, ואין אדם באפליקציה
        gateLog($pid, $cid, null, $kind, $type, $tool, $command, 'deny', 'policy');
        return $base + ['verdict' => 'deny', 'cache' => false,
                        'reason' => gateDenyText($type, $p['mode'] === 'paused' ? 'הלוח מושהה — אין מי שיאשר.' : 'האישורים בערוץ הזה באפליקציה, ואין שם אף אחד.')];
    }
    gateLog($pid, $cid, null, $kind, $type, $tool, $command, 'ask', 'asked', $p['id']);
    return $base + ['verdict' => 'ask', 'cache' => false, 'message_id' => $p['id'], 'deadline' => $p['deadline']];
}

/** ‏"תמיד / אף פעם בפרויקט הזה" על כרטיס של השער — אפשר גם אחרי שעבר הזמן ("תמיד" חל בהרצה הבאה). */
function gateProjectDecision(array $user, array $m, array $channel, string $decision): array {
    $gate = $m['meta']['gate'] ?? null;
    if (!is_array($gate) || !isset(APPROVAL_TYPES[$gate['type'] ?? ''])) throw new AppError('לבקשה הזו אין סוג מדיניות', 422);
    $type = (string) $gate['type'];
    $pid = (int) $channel['project_id'] ?: null;
    if ($decision === 'project-always' && APPROVAL_TYPES[$type]['always'] !== 'yes') {
        throw new AppError('לסוג "' . APPROVAL_TYPES[$type]['label'] . '" אין "תמיד"', 422);
    }
    requirePolicyWriter($user, $pid);
    setPolicy($type, $decision === 'project-always' ? 'allow' : 'deny', $user, null, $pid);
    logEvent($user, 'policy.set', ($pid === null ? 'board' : "project:$pid") . ":$type", true, "gate → $decision");
    return ['type' => $type, 'policy' => $decision === 'project-always' ? 'allow' : 'deny'];
}

/** ‏"מה הוחלט" — 100 ההכרעות האחרונות של השער בפרויקט (או בכל הלוח). */
function gateHistory(?int $projectId): array {
    $st = db()->prepare('SELECT g.*, c.name channel_name FROM gate_log g LEFT JOIN channels c ON c.id = g.channel_id WHERE '
                        . ($projectId === null ? '1' : 'g.project_id = ?') . ' ORDER BY g.id DESC LIMIT 100');
    $st->execute($projectId === null ? [] : [$projectId]);
    return array_map(fn ($r) => ['at' => (int) $r['at'], 'kind' => $r['kind'], 'kind_label' => GATE_KINDS[$r['kind']] ?? $r['kind'],
        'type' => $r['type'], 'label' => APPROVAL_TYPES[$r['type']]['label'] ?? $r['type'], 'tool' => $r['tool'],
        'command' => mb_substr((string) $r['command'], 0, 300), 'verdict' => $r['verdict'], 'how' => $r['how'],
        'channel_name' => $r['channel_name'], 'task_id' => $r['task_id'] === null ? null : (int) $r['task_id'],
        'by_name' => $r['by_name']], $st->fetchAll());
}
