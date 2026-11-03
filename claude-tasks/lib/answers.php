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
            . PLATFORM_LIMITS
            . (($ctx['open_asks'] ?? 0) >= PLATFORM_MAX_OPEN_ASKS
                ? 'כבר שאלת ' . PLATFORM_MAX_OPEN_ASKS . ' שאלות שבעל הלוח עוד לא ענה עליהן — אסור לשאול עוד ("ask": null). ענה עם מה שיש, או הצע מטלה. '
                : '')
            . 'ענה JSON בלבד: {"reply": "התשובה (או ריק כששואלים בלבד)", "task": null או {"title": "כותרת קצרה", "body": "מה בדיוק לעשות"}, '
            . '"ask": null או {"questions": [{"header": "עד 12 תווים", "question": "השאלה", "multiSelect": false, '
            . '"options": [{"label": "אפשרות", "description": "מה היא אומרת"}]}]}, "needs_chat": null או "מה הצ\'אט צריך לעשות"}';
    $prompt = "## README של התיקייה\n" . ($ctx['readme'] !== '' ? $ctx['readme'] : '(אין)')
            . "\n\n## מטלות פתוחות בפרויקט\n" . ($tasks ? implode("\n", $tasks) : '(אין)')
            . "\n\n## השיחה עד עכשיו (מהישן לחדש)\n" . implode("\n", $lines)
            . "\n\n## ההודעה שעליה עונים\n" . $question['body'];
    return [$system, $prompt];
}

/**
 * מפענח את תשובת המודל (§9.1): reply · task · ask (שאלות בצורה של AskUserQuestion) · needs_chat.
 * JSON שבור — הטקסט כולו הוא התשובה. שאלה בצורה לא תקינה — נזרקת (התשובה נשארת).
 */
function parsePlatformReply(string $text): array {
    $out = ['reply' => trim($text), 'task' => null, 'ask' => null, 'needs_chat' => null];
    try {
        $d = extractJson($text);
    } catch (Throwable) { return $out; }
    $out['reply'] = trim((string) ($d['reply'] ?? $d['answer'] ?? ''));
    $task = $d['task'] ?? null;
    $out['task'] = is_array($task) && trim((string) ($task['title'] ?? '')) !== ''
        ? ['title' => mb_substr(trim((string) $task['title']), 0, 200), 'body' => mb_substr((string) ($task['body'] ?? ''), 0, 5000)]
        : null;
    if (is_array($d['ask'] ?? null)) {
        try { $out['ask'] = normalizeChoiceQuestions($d['ask']['questions'] ?? $d['ask']); } catch (Throwable) { $out['ask'] = null; }
    }
    $need = trim((string) ($d['needs_chat'] ?? ''));
    $out['needs_chat'] = $need !== '' ? mb_substr($need, 0, 1000) : null;
    if ($out['reply'] === '' && !$out['ask'] && !$out['needs_chat']) $out['reply'] = trim($text);
    return $out;
}

/** "שלחתי", "העברתי ל", "הערתי"… בלי needs_chat — Platform תיאר פעולה שלא קרתה (אין לו כלים). מסומן בכרטיס. */
function platformClaimsAction(string $reply): bool {
    return (bool) preg_match('/שלחתי|העברתי\s+ל|הערתי|הפעלתי|הרצתי|\bsent (it )?to\b|\bran\s|\bforwarded\b|\bwoke\b/ui', $reply);
}

/** כמה שאלות של Platform בערוץ עוד פתוחות. */
function platformOpenAsks(int $channelId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM messages WHERE channel_id = ? AND kind = 'choice' AND author_role = 'platform' AND status = 'open'");
    $st->execute([$channelId]);
    return (int) $st->fetchColumn();
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
/**
 * מה ש-Platform לא יכול לעשות — קריאה אחת למודל, בלי כלים (תקלות 4 ו-5, 2026-11-03). בשיחה יש הודעות של הצ'אט
 * האמיתי ("שלחתי לרסטר…"), והמודל חיקה אותן וכתב שעשה פעולה שלא קרתה.
 */
const PLATFORM_LIMITS = "אין לך שום כלי: אינך יכול לשלוח הודעה לצ'אט אחר או להעיר אותו, לפתוח צ'אט, להריץ פקודה, "
    . "לשנות קבצים או את הלוח, או לחכות — התשובה הזאת היא כל מה שתעשה. לעולם אל תכתוב שעשית, שלחת, העברת "
    . "או הפעלת משהו, וגם לא \"אעשה\" / \"אשלח\". ההודעות של הצ'אט בשיחה נכתבו על ידי הצ'אט עצמו, שיש לו כלים — לא על ידך. "
    . "כשצריך החלטה של בעל הלוח (בחירה בין אפשרויות, מידע שחסר, משהו שאי אפשר לבטל) — שאל אותו בשדה ask; לעולם אל תניח "
    . "שענה. כשצריך פעולה שרק הצ'אט יכול לעשות (להעיר או לשלוח לצ'אט אחר, להריץ, לשנות קוד) — כתוב מה צריך בשדה needs_chat: "
    . "ההודעה תועבר לצ'אט האמיתי של הערוץ, ובתשובה כתוב רק שהעברת את הבקשה אליו. ";

/** §9.1: לכל היותר 3 שאלות פתוחות ברצף — אחר כך Platform עונה עם מה שיש, או מציע מטלה. */
const PLATFORM_MAX_OPEN_ASKS = 3;

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
    // ‏§15.3: המפסק פתוח — לא קוראים לספק. מעבירים לצ'אט, או ממתינים (לפי ההגדרה של המשלם)
    if (breakerBlocks((int) $payer['id'])) {
        $b = platformBreaker((int) $payer['id']);
        $why = '☁️ Platform נעצר — ' . breakerShort($b['state'], $b['reason']);
        if ($b['handover']) return handToChat($messageId, (int) $channel['id'], $why);
        db()->prepare("UPDATE messages SET answered_by = 'platform' WHERE id = ?")->execute([$messageId]);
        return ['answered' => false, 'waiting' => true, 'reason' => "$why — ההודעה ממתינה עד שיחזור"];
    }

    try {
        $openAsks = platformOpenAsks((int) $channel['id']);
        [$system, $prompt] = platformPrompt($channel, $project, platformContext($channel, $project, $payer) + ['open_asks' => $openAsks], $m);
        $ctx = ['kind' => 'answer', 'project_id' => (int) $project['id'], 'channel_id' => (int) $channel['id'], 'enforce' => false,
                // שלב 16: תשובה במקום צ'אט שלא עונה, אחרי מעבר אוטומטי — נספרת מול התקרה
                'auto' => (int) ($channel['unresponsive_since'] ?? 0) > 0 && lanePrefs((int) $payer['id'])['on_stop'] === 'auto'];
        $conn = $chain[0] + ['chain' => $chain, 'user' => $payer, 'usage' => $ctx];
        $out = parsePlatformReply(aiComplete($conn, $system, $prompt, 1500, $transport));
    } catch (Throwable $e) {
        // ‏§15.3: לא נשאר תלוי. הספק נעצר (קרדיט / מפתח / עומס / תקלה) — המפסק נפתח: הודעה אחת לכל ערוץ,
        // וכל מה שממתין עובר לצ'אט במעבר אחד (או ממתין, לפי ההגדרה). כשל של ההודעה הזו בלבד — לצ'אט, עם הסיבה
        $class = $e instanceof AppError ? $e->aiClass : '';
        $pid = (int) $payer['id'];
        if ($class !== '' && $class !== 'bad_request') {
            breakerTrip($pid, $class, $e->getMessage(), (string) ($chain[0]['provider'] ?? ''));
            $why = '☁️ Platform נעצר — ' . breakerShort($class, $e->getMessage());
            if (platformBreaker($pid)['handover']) return handToChat($messageId, (int) $channel['id'], $why);
            db()->prepare("UPDATE messages SET answered_by = 'platform' WHERE id = ?")->execute([$messageId]);
            return ['answered' => false, 'waiting' => true, 'reason' => "$why — ההודעה ממתינה עד שיחזור"];
        }
        channelInsertIn((int) $channel['id'], 'system', null, '☁️ Platform לא הצליח לענות על ההודעה הזו — '
                        . mb_substr($e->getMessage(), 0, 300) . '. היא עברה לצ\'אט.', 'status',
                        $m['task_id'] === null ? null : (int) $m['task_id'], $messageId, false);
        return handToChat($messageId, (int) $channel['id'], $e->getMessage());
    }
    // הצליח — ואם זו הייתה בדיקה של מפסק פתוח, הוא נסגר (הודעה אחת: חזר)
    if (platformBreaker((int) $payer['id'])['open']) breakerReset((int) $payer['id']);

    ['reply' => $reply, 'task' => $task, 'ask' => $ask, 'needs_chat' => $need] = $out;
    $cid = (int) $channel['id'];
    $tid = $m['task_id'] === null ? null : (int) $m['task_id'];
    // §9.1: לא יותר מ-3 שאלות פתוחות ברצף — גם אם המודל ניסה
    if ($ask && $openAsks >= PLATFORM_MAX_OPEN_ASKS) {
        $ask = null;
        if ($reply === '') $reply = 'יש כבר ' . PLATFORM_MAX_OPEN_ASKS . ' שאלות שמחכות לתשובה שלך — עונים עליהן קודם, או: 📋 להפוך למטלה.';
    }
    $listening = ($channel['status'] ?? '') === 'active' && (int) ($channel['listening_until'] ?? 0) > nowTs();
    $meta = array_filter([
        'task' => $task,
        // ‏needs_chat: מה צריך מהצ'אט, ולמי הועבר — הכרטיס אומר את זה, וגם אם הצ'אט לא מאזין
        'needs_chat' => $need ? ['what' => $need, 'to' => (string) $channel['name'], 'listening' => $listening] : null,
        // שמירה בקוד, לא רק בפרומפט: תיאור של פעולה שלא קרתה
        'claimed_action' => !$need && $reply !== '' && platformClaimsAction($reply) ? true : null,
    ], fn ($v) => $v !== null);
    $answerId = null;
    if ($reply !== '' || !$ask) {
        db()->prepare("INSERT INTO messages (channel_id, task_id, direction, author_role, kind, body, reply_to, answered_by, meta, created_at)
                       VALUES (?,?, 'out', 'platform', 'answer', ?,?, '', ?,?)")
            ->execute([$cid, $tid, cleanBody($reply !== '' ? $reply : '…'), $messageId,
                       $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : '', nowTs()]);
        $answerId = (int) db()->lastInsertId();
    }
    $askId = null;
    if ($ask) {
        // אותה בועת בחירה כמו של הצ'אטים (§5.10); התשובה מפעילה את Platform שוב, עם כל השרשור
        db()->prepare("INSERT INTO messages (channel_id, task_id, direction, author_role, kind, body, reply_to, answered_by, meta, status, created_at)
                       VALUES (?,?, 'out', 'platform', 'choice', ?,?, '', ?, 'open', ?)")
            ->execute([$cid, $tid, cleanBody(choiceBody($ask)), $messageId,
                       json_encode(['questions' => $ask, 'mode' => 'board', 'platform' => true], JSON_UNESCAPED_UNICODE), nowTs()]);
        $askId = (int) db()->lastInsertId();
    }
    db()->prepare("UPDATE messages SET answered_by = 'platform', handled_by = 'platform', lease_until = 0, delivered_at = ? WHERE id = ?")->execute([nowTs(), $messageId]);
    channelStateRefresh((int) $channel['id']);
    touchChannel($cid);

    if ($need) {
        // ההודעה עוברת לצ'אט האמיתי של הערוץ — עם מה שצריך. מצלצל (כמו handToChat)
        channelInsertIn($cid, 'system', null, "☁️ Platform לא יכול לבצע את זה בעצמו — צריך ממך: $need\n\n💬 ההודעה של בעל הלוח:\n"
                        . mb_substr((string) $m['body'], 0, 3000), 'text', $tid, $messageId, true, '');
    } else {
        // הצ'אט נשאר מעודכן — status בתיבה, בלי פעמון ובלי להעיר את המאזין
        $said = ($reply !== '' ? "\n\n☁️ Platform ענה:\n" . mb_substr($reply, 0, 4000) : '')
              . ($ask ? "\n\n☁️ Platform שאל:\n" . mb_substr(choiceBody($ask), 0, 2000) : '');
        channelInsertIn($cid, 'system', null, PLATFORM_RELAY_PREFIX . "\n💬 הודעה מבעל הלוח:\n" . mb_substr((string) $m['body'], 0, 2000) . $said,
                        'status', $tid, $messageId, false);
    }
    $rows = messageRows('m.id IN (' . implode(',', array_filter([$answerId, $askId])) . ')', []);
    return ['answered' => true, 'message' => $rows[0], 'messages' => $rows, 'asked' => $askId !== null, 'handed_to_chat' => $need !== null,
            'listening' => $listening];
}
