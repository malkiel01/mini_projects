<?php
/**
 * שלב 8 (docs/spec.md §9) — Platform עונה בערוץ.
 *
 * בערוץ במסלול ☁️: ‏channel-send שומר את ההודעה בלי להעיר את הצ'אט, והממשק
 * קורא מיד ל-platform-reply, שעונה בתוך אותה בקשה (ל-PHP אין תהליך רקע).
 * נסגר המסך באמצע — ההודעה נשארת "ממתינה ל-Platform", והפתיחה הבאה משלימה.
 *
 * ‏answered_by על הודעה נכנסת: '' — לצ'אט; 'platform' — ממתינה ל-Platform;
 * ‏'platform-busy:<ts>' — Platform עונה עכשיו (נתפס, כדי ששני מסכים לא יענו פעמיים).
 * אחרי התשובה ההודעה המקורית מסומנת כנמסרה, והצ'אט מקבל הודעת status — בלי להתעורר.
 */
declare(strict_types=1);

const PLATFORM_CONTEXT_MESSAGES = 30;
const PLATFORM_BUSY_SECONDS = 180;

function migrateAnswers(PDO $pdo): void {
    addColumn($pdo, 'messages', 'meta', "TEXT NOT NULL DEFAULT ''");
}

/** המשלם על ענף (בעל השורש), כשורת משתמש. */
function payerUser(int $projectId): ?array {
    $uid = projectPayer($projectId);
    if (!$uid) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

/** ההקשר: 30 ההודעות האחרונות, המטלות הפתוחות בענף, וה-README של התיקייה. */
function platformContext(array $channel, array $project, ?array $payer): array {
    $from = db()->prepare('SELECT id FROM messages WHERE channel_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . (PLATFORM_CONTEXT_MESSAGES - 1));
    $from->execute([$channel['id']]);
    $msgs = messageRows('m.channel_id = ? AND m.id >= ?', [$channel['id'], (int) ($from->fetchColumn() ?: 0)]);
    $ids = projectSubtree((int) $project['id']);
    $st = db()->prepare('SELECT id, title, status FROM tasks WHERE status NOT IN (\'done\',\'cancelled\') AND project_id IN ('
                        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY updated_at DESC LIMIT 30');
    $st->execute($ids);
    $readme = '';
    if ($payer && ($project['repo_name'] ?? '') !== '') {
        try {
            $path = trim((string) ($project['path'] ?? ''), '/');
            $f = ghContents(githubTokenFor($payer, $project), (string) $project['repo_owner'], (string) $project['repo_name'],
                            ($path !== '' ? "$path/" : '') . 'README.md', (string) ($project['default_branch'] ?? ''));
            if (($f['kind'] ?? '') === 'file') $readme = mb_substr((string) $f['content'], 0, 6000);
        } catch (Throwable) { /* בלי README — עונים בלי */ }
    }
    return ['messages' => $msgs, 'tasks' => $st->fetchAll(), 'readme' => $readme];
}

function platformPrompt(array $channel, array $project, array $ctx, array $question): array {
    $who = ['owner' => 'בעל הלוח', 'member' => 'חבר', 'chat' => 'הצ\'אט', 'system' => 'מערכת', 'platform' => 'Platform'];
    $lines = [];
    foreach ($ctx['messages'] as $m) {
        $lines[] = '[' . ($who[$m['author_role']] ?? $m['author_role']) . ($m['direction'] === 'out' ? ' · בצ\'אט' : ' · בלוח') . '] '
                 . mb_substr((string) $m['body'], 0, 1500);
    }
    $tasks = array_map(fn ($t) => "#{$t['id']} [{$t['status']}] {$t['title']}", $ctx['tasks']);
    $system = "אתה Platform — עונה בלוח המטלות בשם הפרויקט \"{$project['name']}\", בערוץ \"{$channel['name']}\" "
            . "(שם הצ'אט: {$channel['role']}). ענה בעברית, קצר וענייני, למי שקורא בטלפון. "
            . "אינך כותב קוד ואינך משנה קבצים. אם התשובה דורשת עבודת קוד — הסבר בקצרה מה צריך, והצע מטלה. "
            . 'ענה JSON בלבד: {"reply": "התשובה", "task": null או {"title": "כותרת קצרה", "body": "מה בדיוק לעשות"}}';
    $prompt = "## README של התיקייה\n" . ($ctx['readme'] !== '' ? $ctx['readme'] : '(אין)')
            . "\n\n## מטלות פתוחות בפרויקט\n" . ($tasks ? implode("\n", $tasks) : '(אין)')
            . "\n\n## השיחה עד עכשיו (מהישן לחדש)\n" . implode("\n", $lines)
            . "\n\n## ההודעה שעליה עונים\n" . $question['body'];
    return [$system, $prompt];
}

/** מפענח את תשובת המודל. JSON שבור — הטקסט כולו הוא התשובה, בלי הצעת מטלה. */
function parsePlatformReply(string $text): array {
    try {
        $d = extractJson($text);
        $reply = trim((string) ($d['reply'] ?? ''));
        $task = $d['task'] ?? null;
        $task = is_array($task) && trim((string) ($task['title'] ?? '')) !== ''
            ? ['title' => mb_substr(trim((string) $task['title']), 0, 200), 'body' => mb_substr((string) ($task['body'] ?? ''), 0, 5000)]
            : null;
        if ($reply !== '') return [$reply, $task];
    } catch (Throwable) { /* לא JSON */ }
    return [trim($text), null];
}

/** מחזיר הודעה לצ'אט: Platform לא יכול לענות (אין מכסה / אין חיבור). מצלצל. */
function handToChat(int $messageId, int $channelId, string $why): array {
    db()->prepare("UPDATE messages SET answered_by = '' WHERE id = ?")->execute([$messageId]);
    ringBell($channelId);
    return ['answered' => false, 'handed_to_chat' => true, 'reason' => $why];
}

/**
 * ‏platform-reply: עונה על הודעה נכנסת בערוץ ☁️. ‏$transport — לבדיקות.
 * מחזיר את הודעת התשובה (out, author_role platform, kind answer, reply_to).
 */
/**
 * העתק לצ'אט של מה ש-Platform ענה בשמו (status, בלי פעמון). בלוח הוא מקופל לשורה אחת — התשובה עצמה
 * כבר בבועה של Platform. ("שאלה: … תשובה: …" הישן נראה כאילו בעל הלוח ענה על שאלה.)
 */
const PLATFORM_RELAY_PREFIX = '📨 לצ\'אט: Platform ענה בשמך בלוח.';

function platformReply(array $user, int $messageId, ?callable $transport = null): array {
    $st = db()->prepare('SELECT * FROM messages WHERE id = ?');
    $st->execute([$messageId]);
    $m = $st->fetch();
    if (!$m || $m['direction'] !== 'in') throw new AppError('ההודעה לא נמצאה', 404);
    $channel = getChannel((int) $m['channel_id']);
    $project = requireProject((int) $channel['project_id'], $user, 'read');

    // כבר נענתה — מחזירים את התשובה הקיימת (מסך שני, או רענון)
    $done = db()->prepare("SELECT id FROM messages WHERE reply_to = ? AND author_role = 'platform' ORDER BY id LIMIT 1");
    $done->execute([$messageId]);
    if ($id = $done->fetchColumn()) return ['answered' => true, 'message' => messageRows('m.id = ?', [(int) $id])[0]];

    if (channelRoute($channel) !== 'platform') {
        if (str_starts_with((string) $m['answered_by'], 'platform')) return handToChat($messageId, (int) $channel['id'], 'הערוץ חזר למסלול הצ\'אטים');
        throw new AppError('הערוץ במסלול הצ\'אטים — הצ\'אט עונה', 409);
    }
    // תפיסה: רק מי שהצליח לסמן busy עונה. busy ישן (בקשה שמתה באמצע) — אפשר לתפוס מחדש
    $claim = db()->prepare("UPDATE messages SET answered_by = ? WHERE id = ? AND delivered_at IS NULL
                              AND (answered_by = 'platform' OR (answered_by LIKE 'platform-busy:%'
                                   AND CAST(substr(answered_by, 15) AS INTEGER) < ?))");
    $claim->execute(['platform-busy:' . nowTs(), $messageId, nowTs() - PLATFORM_BUSY_SECONDS]);
    if ($claim->rowCount() === 0) throw new AppError('Platform כבר עונה על ההודעה הזאת', 409);

    $payer = payerUser((int) $project['id']);
    if (!$payer) return handToChat($messageId, (int) $channel['id'], 'אין משלם לענף');
    try { requireQuota((int) $payer['id'], 'answer'); }
    catch (AppError $e) { return handToChat($messageId, (int) $channel['id'], $e->getMessage()); }
    $chain = aiChain($payer);
    if (!$chain) return handToChat($messageId, (int) $channel['id'], 'למשלם אין חיבור לספק בינה');

    try {
        [$system, $prompt] = platformPrompt($channel, $project, platformContext($channel, $project, $payer), $m);
        $ctx = ['kind' => 'answer', 'project_id' => (int) $project['id'], 'channel_id' => (int) $channel['id'], 'enforce' => false];
        $conn = $chain[0] + ['chain' => $chain, 'user' => $payer, 'usage' => $ctx];
        [$reply, $task] = parsePlatformReply(aiComplete($conn, $system, $prompt, 1500, $transport));
    } catch (Throwable $e) {
        // נכשל — חוזר להמתין ל-Platform (הפתיחה הבאה תנסה שוב), ולא נבלע
        db()->prepare("UPDATE messages SET answered_by = 'platform' WHERE id = ?")->execute([$messageId]);
        throw $e;
    }

    db()->prepare("INSERT INTO messages (channel_id, task_id, direction, author_role, kind, body, reply_to, answered_by, meta, created_at)
                   VALUES (?,?, 'out', 'platform', 'answer', ?,?, '', ?,?)")
        ->execute([$channel['id'], $m['task_id'], cleanBody($reply), $messageId,
                   $task ? json_encode(['task' => $task], JSON_UNESCAPED_UNICODE) : '', nowTs()]);
    $answerId = (int) db()->lastInsertId();
    db()->prepare("UPDATE messages SET answered_by = 'platform', delivered_at = ? WHERE id = ?")->execute([nowTs(), $messageId]);

    // הצ'אט נשאר מעודכן — status בתיבה, בלי פעמון ובלי להעיר את המאזין
    channelInsertIn((int) $channel['id'], 'system', null,
                    PLATFORM_RELAY_PREFIX . "\n💬 הודעה מבעל הלוח:\n" . mb_substr((string) $m['body'], 0, 2000) . "\n\n☁️ Platform ענה:\n" . mb_substr($reply, 0, 4000),
                    'status', $m['task_id'] === null ? null : (int) $m['task_id'], $messageId, false);
    return ['answered' => true, 'message' => messageRows('m.id = ?', [$answerId])[0]];
}
