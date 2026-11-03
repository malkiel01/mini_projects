<?php
/**
 * שאלות בחירה מרובה (AskUserQuestion) בלוח — docs/spec.md §5.10 (שלב 11b).
 *
 * צ'אט רשום ששואל בכלי השאלות: ה-hook (bridge/hook-choice.sh) מפרסם את השאלות
 * כאן (kind choice), ולפי channels.questions:
 *   board (ברירת מחדל) — הכלי נדחה, הצ'אט מדפיס את השאלות ממוספרות ומסיים את התור.
 *                         עונים בלוח (choice-answer) או בהקלדה בצ'אט (prompt עם reply_to).
 *   app                 — החלון באפליקציה רץ כרגיל; הלוח רק משקף, והתשובה מגיעה
 *                         מ-PostToolUse (choice_answer עם reply_to).
 * הראשון שעונה קובע: עדכון אטומי על status = 'open'; השני מקבל 409.
 */
declare(strict_types=1);

const CHOICE_MODES = ['board', 'app'];
const CHOICE_MAX_QUESTIONS = 4;
const CHOICE_MAX_OPTIONS = 8;

function migrateChoices(PDO $pdo): void {
    // ‏'' לכל הודעה רגילה; בשאלת בחירה: open / answered
    addColumn($pdo, 'messages', 'status', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'channels', 'questions', "TEXT NOT NULL DEFAULT 'board'");
}

/** הקלט של הכלי, מנוקה: עד 4 שאלות, 2–8 אפשרויות, אורכים חסומים. */
function normalizeChoiceQuestions($raw): array {
    if (!is_array($raw) || !$raw || !array_is_list($raw)) throw new InvalidArgumentException('questions: רשימה של שאלות');
    if (count($raw) > CHOICE_MAX_QUESTIONS) throw new InvalidArgumentException('עד ' . CHOICE_MAX_QUESTIONS . ' שאלות');
    $out = []; $seen = [];
    foreach ($raw as $q) {
        $text = trim((string) ($q['question'] ?? ''));
        if ($text === '') throw new InvalidArgumentException('שאלה בלי טקסט');
        $text = mb_substr($text, 0, 1000);
        if (isset($seen[$text])) throw new InvalidArgumentException('אותה שאלה פעמיים');
        $seen[$text] = true;
        $opts = [];
        foreach ((array) ($q['options'] ?? []) as $o) {
            $label = mb_substr(trim((string) (is_array($o) ? ($o['label'] ?? '') : $o)), 0, 200);
            if ($label === '' || in_array($label, array_column($opts, 'label'), true)) continue;
            $opts[] = ['label' => $label, 'description' => mb_substr(trim((string) (is_array($o) ? ($o['description'] ?? '') : '')), 0, 500)];
        }
        if (count($opts) < 1) throw new InvalidArgumentException('שאלה בלי אפשרויות');
        $out[] = ['question' => $text, 'header' => mb_substr(trim((string) ($q['header'] ?? '')), 0, 40),
                  'multiSelect' => !empty($q['multiSelect']), 'options' => array_slice($opts, 0, CHOICE_MAX_OPTIONS)];
    }
    return $out;
}

/** גוף טקסט לשאלה — לחיפוש, לייצוא, ולמי שקורא בלי הבועה. */
function choiceBody(array $questions): string {
    $parts = [];
    foreach ($questions as $q) {
        $lines = ['❓ ' . ($q['header'] !== '' ? "{$q['header']}: " : '') . $q['question'] . ($q['multiSelect'] ? ' (אפשר כמה)' : '')];
        foreach ($q['options'] as $i => $o) {
            $lines[] = ($i + 1) . '. ' . $o['label'] . ($o['description'] !== '' ? " — {$o['description']}" : '');
        }
        $parts[] = implode("\n", $lines);
    }
    return implode("\n\n", $parts);
}

function channelQuestionsMode(array $channel): string {
    $m = (string) ($channel['questions'] ?? 'board');
    return in_array($m, CHOICE_MODES, true) ? $m : 'board';
}

/** מה-hook: השאלה נשמרת (out, chat, choice, open). מחזיר את המזהה ואת המצב — שה-hook יידע אם לדחות את הכלי. */
function choicePost(array $channel, $questions): array {
    $qs = normalizeChoiceQuestions($questions);
    $mode = channelQuestionsMode($channel);
    db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, answered_by, meta, status, created_at)
                   VALUES (?, 'out', 'chat', 'choice', ?, '', ?, 'open', ?)")
        ->execute([$channel['id'], cleanBody(choiceBody($qs)),
                   json_encode(['questions' => $qs, 'mode' => $mode], JSON_UNESCAPED_UNICODE), nowTs()]);
    $id = (int) db()->lastInsertId();
    touchChannel((int) $channel['id']);
    return ['id' => $id, 'mode' => $mode];
}

function getChoice(int $messageId): array {
    $st = db()->prepare("SELECT * FROM messages WHERE id = ? AND kind = 'choice'");
    $st->execute([$messageId]);
    $m = $st->fetch();
    if (!$m) throw new AppError('השאלה לא נמצאה', 404);
    $m['meta'] = json_decode((string) $m['meta'], true) ?: [];
    return $m;
}

/**
 * התשובות מהלוח, מאומתות מול השאלה. לכל שאלה: תווית אחת, רשימה (multiSelect),
 * או {other: "טקסט"}. כל שאלה חייבת תשובה.
 */
function validateChoiceAnswers(array $questions, $answers): array {
    if (!is_array($answers)) throw new InvalidArgumentException('answers: אובייקט לפי שאלה');
    $out = [];
    foreach ($questions as $q) {
        $a = $answers[$q['question']] ?? null;
        $labels = array_column($q['options'], 'label');
        if (is_array($a) && array_key_exists('other', $a)) {
            $t = trim((string) $a['other']);
            if ($t === '') throw new InvalidArgumentException("חסרה תשובה: {$q['question']}");
            $out[$q['question']] = ['other' => mb_substr($t, 0, 2000)];
        } elseif (is_array($a)) {
            if (!$q['multiSelect']) throw new InvalidArgumentException("רק תשובה אחת: {$q['question']}");
            $pick = array_values(array_unique(array_map('strval', $a)));
            if (!$pick) throw new InvalidArgumentException("חסרה תשובה: {$q['question']}");
            foreach ($pick as $p) if (!in_array($p, $labels, true)) throw new InvalidArgumentException("אפשרות לא קיימת: $p");
            $out[$q['question']] = $pick;
        } elseif (is_string($a) && in_array($a, $labels, true)) {
            $out[$q['question']] = $a;
        } else {
            throw new InvalidArgumentException("חסרה תשובה: {$q['question']}");
        }
    }
    return $out;
}

/** תשובה בשורה אחת: "א, ב" או הטקסט החופשי. */
function choiceAnswerText($a): string {
    if (is_array($a) && array_key_exists('other', $a)) return (string) $a['other'];
    return is_array($a) ? implode(', ', $a) : (string) $a;
}

/** הגוף שהצ'אט מקבל: "תשובה לשאלה #id:" ושורה לכל שאלה. */
function choiceAnswerBody(int $choiceId, array $questions, array $answers): string {
    $lines = ["תשובה לשאלה #$choiceId:"];
    foreach ($questions as $q) {
        if (!array_key_exists($q['question'], $answers)) continue;
        $lines[] = '• ' . ($q['header'] !== '' ? $q['header'] : $q['question']) . ': ' . choiceAnswerText($answers[$q['question']]);
    }
    return implode("\n", $lines);
}

/**
 * מסמן "נענתה" — רק אם עוד פתוחה (אטומי). מחזיר false כשמישהו הקדים.
 * ‏$via: board | chat | app.
 */
function markChoiceAnswered(array $choice, array $answers, string $via, ?int $userId): bool {
    $meta = $choice['meta'] + ['answers' => $answers, 'answered_via' => $via, 'answered_by_user' => $userId, 'answered_at' => nowTs()];
    $st = db()->prepare("UPDATE messages SET status = 'answered', meta = ? WHERE id = ? AND status = 'open'");
    $st->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $choice['id']]);
    return $st->rowCount() === 1;
}

const CHOICE_VIA_LABEL = ['board' => 'בלוח', 'chat' => 'בצ\'אט', 'app' => 'באפליקציה'];

/** תשובה מהלוח: נגיעה בבועה. ‏in, kind choice_answer, reply_to — ומעיר את הצ'אט. */
function choiceAnswer(array $user, int $messageId, $answers): array {
    $choice = getChoice($messageId);
    $channel = getChannel((int) $choice['channel_id']);
    requireProject((int) $channel['project_id'], $user, 'read');
    if (!canSendToChannel($user, $channel)) throw new AppError('אין לך הרשאה לענות לצ\'אט הזה', 403);
    if ($choice['status'] !== 'open') {
        throw new AppError('כבר נענתה ' . (CHOICE_VIA_LABEL[$choice['meta']['answered_via'] ?? ''] ?? ''), 409);
    }
    if (($choice['meta']['mode'] ?? 'board') === 'app') throw new AppError('השאלה פתוחה בחלון באפליקציה — עונים שם', 409);
    if ($channel['status'] !== 'active') throw new AppError('הערוץ מנותק', 409);
    $questions = $choice['meta']['questions'] ?? [];
    $clean = validateChoiceAnswers($questions, $answers);
    if (!markChoiceAnswered($choice, $clean, 'board', (int) $user['id'])) throw new AppError('כבר נענתה', 409);
    // שאלה של Platform (§9.1): התשובה מפעילה את Platform שוב — לא מעירה את הצ'אט (אם הערוץ עדיין ב-☁️)
    $toPlatform = ($choice['author_role'] ?? '') === 'platform' && channelRoute($channel) === 'platform';
    $id = channelInsertIn((int) $channel['id'], authorRole($user), (int) $user['id'],
                          choiceAnswerBody($messageId, $questions, $clean), 'choice_answer', null, $messageId,
                          !$toPlatform, $toPlatform ? 'platform' : '');
    return ['id' => $id, 'answers' => $clean, 'platform' => $toPlatform];
}

/**
 * מהצ'אט: הודעה שעונה על שאלה פתוחה. נקרא מ-channelPost.
 *   owner + reply_to       — בעל הלוח הקליד תשובה בצ'אט (מספר או טקסט) → via chat
 *   choice_answer + reply_to — PostToolUse במצב app → via app
 * שאלה שכבר נענתה — לא משנים כלום (ההודעה עצמה נשמרת כרגיל).
 */
function choiceRepliedFromChat(int $choiceId, int $channelId, string $role, string $kind, string $body, $answers = null): void {
    $st = db()->prepare("SELECT * FROM messages WHERE id = ? AND channel_id = ? AND kind = 'choice' AND status = 'open'");
    $st->execute([$choiceId, $channelId]);
    $m = $st->fetch();
    if (!$m) return;
    $m['meta'] = json_decode((string) $m['meta'], true) ?: [];
    if ($kind === 'choice_answer') {
        $parsed = is_array($answers) ? array_map(fn ($a) => is_array($a) || is_string($a) ? $a : (string) $a, $answers) : ['' => ['other' => $body]];
        markChoiceAnswered($m, $parsed, 'app', null);
    } elseif ($role === 'owner') {
        markChoiceAnswered($m, ['' => ['other' => mb_substr($body, 0, 2000)]], 'chat', null);
    }
}

function setChannelQuestions(array $user, int $channelId, string $mode): void {
    $channel = getChannel($channelId);
    if (!$channel) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $channel['project_id'], $user, 'read');
    if (!canSendToChannel($user, $channel)) throw new AppError('אין לך הרשאה לשנות את הצ\'אט הזה', 403);
    if (!in_array($mode, CHOICE_MODES, true)) throw new InvalidArgumentException('questions: board או app');
    db()->prepare('UPDATE channels SET questions = ? WHERE id = ?')->execute([$mode, $channelId]);
}

/** שאלות פתוחות בענף — ל"ממתין לך". */
function openChoices(array $projectIds, ?int $userId = null): array {
    if (!$projectIds) return [];
    $muted = $userId ? mutedChannelIds($userId) : [];
    $in = implode(',', array_fill(0, count($projectIds), '?'));
    $st = db()->prepare("SELECT m.id, m.channel_id, m.meta, m.created_at, m.author_role, c.name channel_name FROM messages m
                           JOIN channels c ON c.id = m.channel_id
                          WHERE m.kind = 'choice' AND m.status = 'open' AND c.status = 'active' AND c.project_id IN ($in)
                          ORDER BY m.id LIMIT 30");
    $st->execute($projectIds);
    $rows = array_values(array_filter($st->fetchAll(), fn ($r) => !in_array((int) $r['channel_id'], $muted, true)));
    return array_map(function ($r) {
        $q = (json_decode((string) $r['meta'], true) ?: [])['questions'][0] ?? [];
        return ['message_id' => (int) $r['id'], 'channel_id' => (int) $r['channel_id'], 'channel_name' => $r['channel_name'],
                'title' => (string) ($q['question'] ?? 'שאלה'), 'waiting_since' => (int) $r['created_at'],
                'lane' => $r['author_role'] === 'platform' ? 'platform' : 'chat'];
    }, $rows);
}

/** כרטיסי "הצ'אט לא עונה" פתוחים בענף (שלב 16) — ל"ממתין לך". */
function openLaneCards(array $projectIds, ?int $userId = null): array {
    if (!$projectIds) return [];
    $muted = $userId ? mutedChannelIds($userId) : [];
    $in = implode(',', array_fill(0, count($projectIds), '?'));
    $st = db()->prepare("SELECT m.id, m.channel_id, m.created_at, c.name channel_name FROM messages m JOIN channels c ON c.id = m.channel_id
                          WHERE m.kind = 'lane_card' AND m.status = 'open' AND c.status = 'active' AND c.project_id IN ($in) ORDER BY m.id LIMIT 30");
    $st->execute($projectIds);
    return array_values(array_map(fn ($r) => ['message_id' => (int) $r['id'], 'channel_id' => (int) $r['channel_id'], 'channel_name' => $r['channel_name'],
        'title' => "הצ'אט לא עונה — {$r['channel_name']}", 'waiting_since' => (int) $r['created_at'], 'lane' => 'chat'],
        array_filter($st->fetchAll(), fn ($r) => !in_array((int) $r['channel_id'], $muted, true))));
}
