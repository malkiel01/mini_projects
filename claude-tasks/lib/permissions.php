<?php
/**
 * 🔐 אישור הרשאות מהלוח — docs/spec.md §5.12 (שלב 13).
 *
 * צ'אט רשום שעומד להציג חלון "Allow?" באפליקציה: ה-hook ‏PermissionRequest (bridge/hook-permission.sh)
 * מפרסם כאן בקשה (kind permission, status open) ומחכה לתשובה (permission-status) — עד 10 דקות.
 * מודל המרוץ (§5.12.2a): החלון באפליקציה פתוח כל הזמן; הראשון שעונה קובע. מה שהוכרע במקום אחר
 * נסגר בלוח לשורה אפורה (permission-close).
 *
 * מצבים: open → answered (בלוח) · resolved (הוכרע באפליקציה / אוטומטית) · moved_to_app (10 דקות בלי תשובה).
 * רק בעל הלוח עונה. "תמיד בצ'אט הזה" — כלל session שהשרת בונה בעצמו, ורק אחרי רשימת האיסור (§5.12.1).
 */
declare(strict_types=1);

const PERMISSION_MODES = ['board', 'app'];
const PERMISSION_WAIT = 600;          // ‏10 דקות — אחר כך ה-hook מפסיק לחכות
const PERMISSION_POLL_EVERY = 8;      // ‏permission-status: בקשה אחת לכל 8 ש' לאסימון (ה-hook שואל כל 10)
const PERMISSION_DECISIONS = ['once', 'always', 'deny', 'project-always', 'project-never'];

/** פקודות שלעולם לא מקבלות "תמיד" (§5.12.1) — רק "פעם אחת". */
const PERMISSION_DENY_PATTERNS = [
    'rm -rf'             => '/(^|[\s;&|(])rm\s+(-[a-zA-Z]*r[a-zA-Z]*f|-[a-zA-Z]*f[a-zA-Z]*r|-r\s+-f|-f\s+-r|--recursive)/',
    'git push'           => '/\bgit\b[^;&|]*\bpush\b/',
    'git reset --hard'   => '/\bgit\b[^;&|]*\breset\b[^;&|]*--hard/',
    'gh'                 => '/(^|[\s;&|(])gh(\s|$)/',
    'curl'               => '/(^|[\s;&|(\/])curl(\s|$)/',
    'wget'               => '/(^|[\s;&|(\/])wget(\s|$)/',
    'ssh'                => '/(^|[\s;&|(\/])ssh(\s|$)/',
    'rsync'              => '/(^|[\s;&|(\/])rsync(\s|$)/',
    'scp'                => '/(^|[\s;&|(\/])scp(\s|$)/',
    'chmod'              => '/(^|[\s;&|(\/])chmod(\s|$)/',
    'chown'              => '/(^|[\s;&|(\/])chown(\s|$)/',
    'sudo'               => '/(^|[\s;&|(\/])sudo(\s|$)/',
    '.github/workflows/' => '#\.github/workflows#',
    '.claude/settings'   => '#\.claude/settings#',
];
/** פקודה שמריצה פקודה אחרת — קידומת עליה פותחת הכול. */
const PERMISSION_WRAPPERS = ['sh', 'bash', 'zsh', 'dash', 'env', 'xargs', 'eval', 'exec', 'nohup', 'time', 'timeout',
                             'nice', 'command', 'builtin', 'source', '.', 'find', 'parallel', 'watch'];
/** מפרשים: הקידומת כוללת את הקובץ שהם מריצים, אחרת "php:*" = כל קוד. */
const PERMISSION_INTERPRETERS = ['php', 'node', 'python', 'python3', 'ruby', 'perl', 'deno', 'bun', 'npx', 'bunx'];

function migratePermissions(PDO $pdo): void {
    addColumn($pdo, 'channels', 'permissions', "TEXT NOT NULL DEFAULT 'board'");
}

function channelPermissionsMode(array $channel): string {
    $m = (string) ($channel['permissions'] ?? 'board');
    return in_array($m, PERMISSION_MODES, true) ? $m : 'board';
}

/** הפקודה כפי שתוצג — המלאה, לא תקציר (§5.12.4). */
function permissionCommand(string $tool, array $input): string {
    if ($tool === 'Bash') return (string) ($input['command'] ?? '');
    if (in_array($tool, ['Write', 'Edit', 'MultiEdit', 'NotebookEdit', 'Read'], true)) {
        $path = (string) ($input['file_path'] ?? $input['notebook_path'] ?? '');
        if ($tool === 'Edit') return "$path\n— " . mb_substr((string) ($input['old_string'] ?? ''), 0, 4000)
                                   . "\n+ " . mb_substr((string) ($input['new_string'] ?? ''), 0, 4000);
        if ($tool === 'Write') return "$path\n" . mb_substr((string) ($input['content'] ?? ''), 0, 8000);
        return $path;
    }
    if ($tool === 'WebFetch') return (string) ($input['url'] ?? '');
    return json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
}

/**
 * "תמיד בצ'אט הזה" — מותר? ואיזה כלל. מחזיר ['ok' => bool, 'rule' => ?array, 'label' => string, 'reason' => string].
 * ‏rule הוא מה שה-hook מוסיף ל-session: ‏{toolName, ruleContent?}.
 */
function permissionAlways(string $tool, array $input): array {
    $no = fn (string $why) => ['ok' => false, 'rule' => null, 'label' => '', 'reason' => $why];
    if (str_starts_with($tool, 'mcp__')) {
        return ['ok' => true, 'rule' => ['toolName' => $tool], 'label' => $tool, 'reason' => ''];
    }
    if ($tool !== 'Bash') return $no('לכלי הזה — רק "פעם אחת"');
    $cmd = trim((string) ($input['command'] ?? ''));
    if ($cmd === '') return $no('פקודה ריקה');
    foreach (PERMISSION_DENY_PATTERNS as $name => $re) {
        if (preg_match($re, $cmd)) return $no("‏$name — רק \"פעם אחת\"");
    }
    // כמה פקודות בשורה אחת — קידומת של הראשונה הייתה מאשרת גם את השאר
    if (preg_match('/[;&|`<>\n]|\$\(/', $cmd)) return $no('כמה פקודות בשורה אחת — רק "פעם אחת"');
    $tokens = preg_split('/\s+/', $cmd);
    $first = $tokens[0];
    if (str_contains($first, '=')) return $no('משתני סביבה לפני הפקודה — רק "פעם אחת"');
    if (in_array(basename($first), PERMISSION_WRAPPERS, true)) return $no("‏$first מריץ פקודות אחרות — רק \"פעם אחת\"");
    $second = $tokens[1] ?? '';
    if (in_array(basename($first), PERMISSION_INTERPRETERS, true)) {
        if ($second === '' || str_starts_with($second, '-')) return $no("‏$first בלי קובץ — רק \"פעם אחת\"");
        $prefix = "$first $second";
    } elseif ($second !== '' && preg_match('/^[a-z][a-z0-9_.:-]*$/', $second)) {
        $prefix = "$first $second";                 // git status, npm test, php -l… — פקודה + תת-פקודה
    } else {
        $prefix = $first;
    }
    if (mb_strlen($prefix) > 200) return $no('קידומת ארוכה מדי');
    return ['ok' => true, 'rule' => ['toolName' => 'Bash', 'ruleContent' => "$prefix:*"], 'label' => "$prefix …", 'reason' => ''];
}

/** כלי MCP בשם מחולל (mcp__<uuid>__…) — לא נבדק אם allow מכובד (§5.12.2a, 4). */
function permissionAliasTool(string $tool): bool {
    return (bool) preg_match('/^mcp__[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}__/i', $tool);
}

/**
 * מה-hook: בקשה חדשה. מחזיר {id, mode}; ‏mode app או paused — לא נוצרה בקשה, וה-hook יוצא בלי החלטה.
 */
function permissionPost(array $channel, array $in): array {
    if (!empty(config()['bridge_paused'])) return ['id' => 0, 'mode' => 'paused'];
    $mode = channelPermissionsMode($channel);
    if ($mode === 'app') return ['id' => 0, 'mode' => 'app'];
    $tool = mb_substr(trim((string) ($in['tool_name'] ?? '')), 0, 120);
    if ($tool === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $tool)) throw new InvalidArgumentException('tool_name לא תקין');
    $input = is_array($in['tool_input'] ?? null) ? $in['tool_input'] : [];
    $json = json_encode($input, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 60000) throw new InvalidArgumentException('tool_input גדול מדי');
    $command = permissionCommand($tool, $input);
    $description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 500);
    $always = permissionAlways($tool, $input);
    // שלב 14: הסוג מהקטלוג (אם הפעולה ממופה) — לכרטיס, ול"תמיד / אף פעם בפרויקט הזה"
    $gate = is_array($in['gate'] ?? null) ? $in['gate'] : [];
    $gtype = (string) ($gate['type'] ?? '') ?: gateTypeFor($tool, $input, (int) $channel['project_id'] ?: null, $in);
    if ($gtype !== null && $gtype !== '' && isset(APPROVAL_TYPES[$gtype])) {
        $gate = ['type' => $gtype, 'label' => APPROVAL_TYPES[$gtype]['label'], 'always' => APPROVAL_TYPES[$gtype]['always'],
                 'kind' => (string) ($gate['kind'] ?? 'chat')];
        // השער (PreToolUse) שואל בכל פעם — כלל session לא יעזור. במקומו: "תמיד בפרויקט הזה"
        $always = ['ok' => false, 'rule' => null, 'label' => '', 'reason' => 'פעולה מסוג "' . $gate['label'] . '" — ההחלטה הקבועה היא לפרויקט'];
    } else {
        $gate = null;
    }
    $now = nowTs();
    $meta = ['tool_name' => $tool, 'tool_input' => $input, 'command' => $command, 'description' => $description,
             'always' => $always, 'alias' => permissionAliasTool($tool), 'deadline' => $now + PERMISSION_WAIT,
             'key' => mb_substr((string) ($in['key'] ?? ''), 0, 64), 'gate' => $gate];
    $body = "🔐 בקשת הרשאה: $tool" . ($description !== '' ? " — $description" : '') . "\n" . mb_substr($command, 0, 2000);
    db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, answered_by, meta, status, created_at)
                   VALUES (?, 'out', 'chat', 'permission', ?, '', ?, 'open', ?)")
        ->execute([$channel['id'], cleanBody($body), json_encode($meta, JSON_UNESCAPED_UNICODE), $now]);
    $id = (int) db()->lastInsertId();
    touchChannel((int) $channel['id']);
    return ['id' => $id, 'mode' => 'board', 'deadline' => $meta['deadline'], 'always' => $always['ok']];
}

function getPermission(int $messageId): array {
    $st = db()->prepare("SELECT * FROM messages WHERE id = ? AND kind = 'permission'");
    $st->execute([$messageId]);
    $m = $st->fetch();
    if (!$m) throw new AppError('הבקשה לא נמצאה', 404);
    $m['meta'] = json_decode((string) $m['meta'], true) ?: [];
    return $m;
}

/** ה-hook שואל: מה הוחלט. רק על בקשה של הערוץ שלו. */
function permissionStatus(array $channel, int $messageId): array {
    $m = getPermission($messageId);
    if ((int) $m['channel_id'] !== (int) $channel['id']) throw new AppError('הבקשה לא שייכת לערוץ הזה', 403);
    $d = $m['meta']['decision'] ?? null;
    return ['status' => (string) $m['status'], 'decision' => $d,
            'reason' => (string) ($m['meta']['reason'] ?? ''),
            'rule' => $d === 'always' ? ($m['meta']['always']['rule'] ?? null) : null,
            'by' => (string) ($m['meta']['decided_by_name'] ?? '')];
}

/** תשובה מהלוח — בעל הלוח בלבד. אטומי: רק בקשה פתוחה שלא עבר זמנה. */
function permissionAnswer(array $user, int $messageId, string $decision, string $reason = ''): array {
    if (authorRole($user) !== 'owner') throw new AppError('אישור הרשאות — בעל הלוח בלבד', 403);
    if (!in_array($decision, PERMISSION_DECISIONS, true)) throw new InvalidArgumentException('decision: once, always או deny');
    $m = getPermission($messageId);
    $channel = getChannel((int) $m['channel_id']);
    requireProject((int) $channel['project_id'], $user, 'read');
    $project = str_starts_with($decision, 'project-');
    if ($project && $m['status'] !== 'open' || $project && nowTs() > (int) ($m['meta']['deadline'] ?? 0)) {
        // מאוחר — הכלי כבר נדחה, אבל ההחלטה נרשמת למדיניות (חלה בהרצה הבאה)
        $r = gateProjectDecision($user, $m, $channel, $decision);
        $meta = $m['meta'];
        $meta['late_decision'] = $decision; $meta['late_by_name'] = (string) ($user['display_name'] ?? ''); $meta['late_at'] = nowTs();
        db()->prepare('UPDATE messages SET meta = ? WHERE id = ?')->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $messageId]);
        gateLog((int) $channel['project_id'] ?: null, (int) $channel['id'], null, (string) ($meta['gate']['kind'] ?? 'chat'), $r['type'],
                (string) $meta['tool_name'], (string) $meta['command'], $r['policy'], 'late', $messageId, (string) ($user['display_name'] ?? ''));
        return ['status' => (string) $m['status'], 'decision' => $decision, 'late' => true];
    }
    if ($m['status'] !== 'open') throw new AppError(PERMISSION_CLOSED_LABEL[$m['status']] ?? 'הבקשה כבר נסגרה', 409);
    if (nowTs() > (int) ($m['meta']['deadline'] ?? 0)) throw new AppError('עבר הזמן — הבקשה עברה לאפליקציה', 409);
    // השרת בודק שוב — לא רק הממשק (§5.12.1)
    if ($decision === 'always') {
        $always = permissionAlways((string) $m['meta']['tool_name'], (array) ($m['meta']['tool_input'] ?? []));
        if (!$always['ok']) throw new AppError('"תמיד" לא מותר לפקודה הזו: ' . $always['reason'], 422);
        $m['meta']['always'] = $always;
    }
    if ($project) {
        gateProjectDecision($user, $m, $channel, $decision);
        $meta = $m['meta'];
        $meta['project_decision'] = $decision;
        $m['meta'] = $meta;
        $decision = $decision === 'project-always' ? 'once' : 'deny';
    }
    $reason = mb_substr(trim($reason), 0, 1000);
    $meta = $m['meta'] + [];
    $meta['decision'] = $decision;
    $meta['reason'] = $decision === 'deny' ? $reason : '';
    $meta['decided_by'] = (int) $user['id'];
    $meta['decided_by_name'] = (string) ($user['display_name'] ?? $user['username'] ?? '');
    $meta['decided_at'] = nowTs();
    $st = db()->prepare("UPDATE messages SET status = 'answered', meta = ? WHERE id = ? AND status = 'open'");
    $st->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $messageId]);
    if ($st->rowCount() !== 1) throw new AppError('הבקשה כבר נסגרה', 409);
    ringBell((int) $channel['id']);
    if (!empty($meta['gate']['type'])) {
        gateLog((int) $channel['project_id'] ?: null, (int) $channel['id'], null, (string) ($meta['gate']['kind'] ?? 'chat'),
                (string) $meta['gate']['type'], (string) $meta['tool_name'], (string) $meta['command'],
                $decision === 'deny' ? 'deny' : 'allow', 'answered', $messageId, (string) $meta['decided_by_name']);
    }
    logEvent($user, 'permission.' . $decision, "channel:{$channel['id']}", true,
             mb_substr((string) $m['meta']['tool_name'] . ': ' . (string) $m['meta']['command'], 0, 900));
    return ['status' => 'answered', 'decision' => $decision];
}

const PERMISSION_CLOSED_LABEL = ['answered' => 'כבר נענתה בלוח', 'resolved' => 'הוכרעה באפליקציה',
                                 'moved_to_app' => 'עברה לאפליקציה'];
const PERMISSION_CLOSE_HOW = ['resolved' => 'resolved', 'stop' => 'resolved', 'timeout' => 'moved_to_app'];

/** ה-hook / ‏Stop: הבקשה הוכרעה במקום אחר, או שעבר הזמן. רק בקשה פתוחה. */
function permissionClose(array $channel, int $messageId, string $how): bool {
    if (!isset(PERMISSION_CLOSE_HOW[$how])) throw new InvalidArgumentException('how: resolved, stop או timeout');
    $m = getPermission($messageId);
    if ((int) $m['channel_id'] !== (int) $channel['id']) throw new AppError('הבקשה לא שייכת לערוץ הזה', 403);
    $meta = $m['meta'];
    $meta['closed_how'] = $how;
    $meta['closed_at'] = nowTs();
    $st = db()->prepare("UPDATE messages SET status = ?, meta = ? WHERE id = ? AND status = 'open'");
    $st->execute([PERMISSION_CLOSE_HOW[$how], json_encode($meta, JSON_UNESCAPED_UNICODE), $messageId]);
    $closed = $st->rowCount() === 1;
    // Routine שלא נענה בזמן — נדחה (אין אדם באפליקציה), ונרשם
    if ($closed && $how === 'timeout' && ($meta['gate']['kind'] ?? '') === 'routine') {
        gateLog((int) $channel['project_id'] ?: null, (int) $channel['id'], null, 'routine', (string) $meta['gate']['type'],
                (string) ($meta['tool_name'] ?? ''), (string) ($meta['command'] ?? ''), 'deny', 'timeout', $messageId);
    }
    return $closed;
}

/** בקשה פתוחה שעבר זמנה ולא נסגרה (ה-hook מת) — בכל קריאה נסגרת כ"עברה לאפליקציה". */
function permissionsExpire(): void {
    $st = db()->prepare("SELECT id, meta FROM messages WHERE kind = 'permission' AND status = 'open' AND created_at < ?");
    $st->execute([nowTs() - PERMISSION_WAIT - 30]);
    foreach ($st->fetchAll() as $r) {
        $meta = (json_decode((string) $r['meta'], true) ?: []) + ['closed_how' => 'expired', 'closed_at' => nowTs()];
        db()->prepare("UPDATE messages SET status = 'moved_to_app', meta = ? WHERE id = ? AND status = 'open'")
            ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $r['id']]);
    }
}

/** הכרטיס בראש המסך (§5.12.3): כל הבקשות הפתוחות שבעל הלוח רואה. */
function openPermissions(array $user): array {
    if (authorRole($user) !== 'owner') return [];
    permissionsExpire();
    $st = db()->query("SELECT m.id, m.channel_id, m.meta, m.created_at, c.name channel_name, c.icon channel_icon, c.project_id
                         FROM messages m JOIN channels c ON c.id = m.channel_id
                        WHERE m.kind = 'permission' AND m.status = 'open' AND c.status = 'active'
                        ORDER BY m.id LIMIT 20");
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (projectLevel((int) $r['project_id'], $user) === null && ($user['role'] ?? '') !== 'admin') continue;
        $meta = json_decode((string) $r['meta'], true) ?: [];
        $out[] = ['message_id' => (int) $r['id'], 'channel_id' => (int) $r['channel_id'], 'channel_name' => $r['channel_name'],
                  'channel_icon' => (string) $r['channel_icon'], 'created_at' => (int) $r['created_at']]
                 + permissionPublicMeta($meta);
    }
    return $out;
}

/** מה שהממשק צריך מתוך meta — בלי tool_input הגולמי. */
function permissionPublicMeta(array $meta): array {
    return ['tool_name' => (string) ($meta['tool_name'] ?? ''), 'command' => (string) ($meta['command'] ?? ''),
            'description' => (string) ($meta['description'] ?? ''), 'deadline' => (int) ($meta['deadline'] ?? 0),
            'always_ok' => !empty($meta['always']['ok']), 'always_label' => (string) ($meta['always']['label'] ?? ''),
            'always_reason' => (string) ($meta['always']['reason'] ?? ''), 'alias' => !empty($meta['alias']),
            'decision' => $meta['decision'] ?? null, 'reason' => (string) ($meta['reason'] ?? ''),
            'decided_by_name' => (string) ($meta['decided_by_name'] ?? ''), 'decided_at' => (int) ($meta['decided_at'] ?? 0),
            'gate' => is_array($meta['gate'] ?? null) ? $meta['gate'] + ['kind_label' => GATE_KINDS[$meta['gate']['kind'] ?? ''] ?? ''] : null,
            'project_decision' => (string) ($meta['project_decision'] ?? ''), 'late_decision' => (string) ($meta['late_decision'] ?? ''),
            'closed_how' => (string) ($meta['closed_how'] ?? ''), 'closed_at' => (int) ($meta['closed_at'] ?? 0)];
}

/** ⚙ מצב הערוץ: board (ברירת מחדל) / app — בעל הלוח. */
function setChannelPermissions(array $user, int $channelId, string $mode): void {
    if (authorRole($user) !== 'owner') throw new AppError('אישורי הרשאות — בעל הלוח בלבד', 403);
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $channel['project_id'], $user, 'read');
    if (!in_array($mode, PERMISSION_MODES, true)) throw new InvalidArgumentException('permissions: board או app');
    db()->prepare('UPDATE channels SET permissions = ? WHERE id = ?')->execute([$mode, $channelId]);
    logEvent($user, 'permission.mode', "channel:$channelId", true, $mode);
}
