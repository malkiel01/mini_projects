<?php
/**
 * שלב 20 (docs/spec.md §16) — מודל ורמת חשיבה: לחיבור, לצ'אט, להודעה.
 *
 * הקרוב גובר: הודעה › צ'אט (channels.ai_pref) › חיבור (connections.model + effort). פונקציה אחת מכריעה — resolveAi;
 * הממשק לעולם לא מכריע. חל רק על מה שהלוח משלם עליו (☁️ Platform, 🤖 המנוע) — צ'אט Claude Code רץ במודל של הסשן שלו.
 *
 * בחירה (הודעה / צ'אט) = ספק + מודל: הגיבוי (§10.1) נשאר, אבל רק בין חיבורים של אותו ספק. אין — המסירה של שלב 15
 * עם הסיבה, אף פעם לא מעבר שקט למודל של ספק אחר. מעקב, קטלוג וסיכומים — תמיד ברירת המחדל של החיבור.
 */
declare(strict_types=1);

const AI_MODELS_CACHE_SECONDS = 86400;
/** הערכה לפני קריאה (§16.4): טוקנים לתו, בקירוב שמרני (עברית ≈ 2.5 תווים לטוקן). */
const AI_CHARS_PER_TOKEN = 2.5;

function migrateAiPref(PDO $pdo): void {
    addColumn($pdo, 'connections', 'effort', 'TEXT');
    addColumn($pdo, 'connections', 'models_cache', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'channels', 'ai_pref', 'TEXT');
    addColumn($pdo, 'messages', 'ai_pref', 'TEXT');
    addColumn($pdo, 'messages', 'ai_used', 'TEXT');
    addColumn($pdo, 'tasks', 'model', 'TEXT');
    addColumn($pdo, 'tasks', 'effort', 'TEXT');
}

/* ── האפשרויות של המשלם ─────────────────────────────────────── */

/** החיבורים של המשלם (בינה), כל אחד עם רשימת המודלים שלו (מטמון יומי, או הרשימה הבסיסית) והרמות של כל מודל. */
function aiOptions(int $payer, bool $refresh = false, ?callable $transport = null): array {
    $st = db()->prepare("SELECT * FROM connections WHERE user_id = ? AND kind <> 'github' ORDER BY priority, id");
    $st->execute([$payer]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $cache = json_decode((string) $r['models_cache'], true) ?: [];
        $models = $cache['models'] ?? null;
        if ($refresh && (!$models || (int) ($cache['at'] ?? 0) < nowTs() - AI_MODELS_CACHE_SECONDS)) {
            $live = providerModels($r['kind'], decryptSecret((string) $r['secret']), $transport);
            if (($live['source'] ?? '') === 'live') {
                $models = $live['models'];
                db()->prepare('UPDATE connections SET models_cache = ? WHERE id = ?')
                    ->execute([json_encode(['at' => nowTs(), 'models' => $models], JSON_UNESCAPED_UNICODE), $r['id']]);
            }
        }
        $models = $models ?: (MODELS_FALLBACK[$r['kind']] ?? []);
        if (!in_array((string) $r['model'], array_column($models, 'id'), true)) array_unshift($models, ['id' => (string) $r['model'], 'label' => (string) $r['model']]);
        [, $baseOut] = modelPrice($r['kind'], (string) $r['model']);
        $out[] = ['connection_id' => (int) $r['id'], 'provider' => $r['kind'], 'label' => connLabel($r),
                  'model' => (string) $r['model'], 'effort' => $r['effort'] ?: 'auto',
                  'models' => array_map(function ($m) use ($r, $baseOut) {
                      [, $o] = modelPrice($r['kind'], (string) $m['id']);
                      return $m + ['levels' => effortLevels($r['kind'], (string) $m['id']),
                                   'cost_x' => $baseOut > 0 ? round($o / $baseOut, $o / $baseOut < 1 ? 2 : 0) : null];
                  }, $models)];
    }
    return $out;
}

/** האם המודל מותר למשלם: ברשימה של חיבור שלו מאותו ספק (או ברירת המחדל של חיבור). */
function aiModelAllowed(int $payer, string $provider, string $model): bool {
    foreach (aiOptions($payer) as $c) {
        if ($c['provider'] !== $provider) continue;
        if ($c['model'] === $model || in_array($model, array_column($c['models'], 'id'), true)) return true;
    }
    return false;
}

/** בדיקה ושמירה של בחירה ({provider, model, effort}). 422 בעברית. */
function aiPrefValidate(int $payer, ?array $pref): ?array {
    if ($pref === null || ($pref['model'] ?? '') === '') return null;
    $provider = (string) ($pref['provider'] ?? '');
    $model = mb_substr(trim((string) $pref['model']), 0, 80);
    $effort = (string) ($pref['effort'] ?? 'auto');
    if (!aiModelAllowed($payer, $provider, $model)) throw new AppError("המודל \"$model\" לא זמין בחיבורים של המשלם", 422);
    if (!in_array($effort, effortLevels($provider, $model), true)) {
        throw new AppError('רמת החשיבה "' . (EFFORT_LABEL[$effort] ?? $effort) . "\" לא נתמכת ב-$model", 422);
    }
    return ['provider' => $provider, 'model' => $model, 'effort' => $effort];
}

/* ── ההכרעה ────────────────────────────────────────────────── */

/**
 * מי עונה: [provider, model, effort, source (message|choice|channel|connection), notice, chain]. ‏chain — השרשרת לקריאה
 * (בבחירה — רק חיבורים של אותו ספק, עם המודל שנבחר; בלי בחירה — כמו היום, כל חיבור במודל וברמה שלו).
 * בחירה שמורה שכבר לא תקפה — יורדים לרמה הבאה, עם notice.
 */
function resolveAi(array $payerUser, ?array $channel, ?array $message = null, ?array $task = null): array {
    $payer = (int) $payerUser['id'];
    $notice = '';
    $pick = null; $source = 'connection';
    $try = function (?string $json, string $src) use (&$pick, &$source, &$notice, $payer) {
        if ($pick || !$json) return;
        $p = json_decode($json, true);
        if (!is_array($p) || ($p['model'] ?? '') === '') return;
        try { $pick = aiPrefValidate($payer, $p); $source = $src; }
        catch (AppError $e) { $notice = 'המודל שנבחר כבר לא זמין — חזר לברירת המחדל'; }
    };
    if ($task && ($task['model'] ?? '') !== '' && $task['model'] !== null) {
        $c = aiOptions($payer);
        $prov = '';
        foreach ($c as $o) if (in_array($task['model'], array_column($o['models'], 'id'), true) || $o['model'] === $task['model']) { $prov = $o['provider']; break; }
        if ($prov !== '') $try(json_encode(['provider' => $prov, 'model' => $task['model'], 'effort' => $task['effort'] ?: 'auto']), 'task');
    }
    if ($message) {
        // 8b: תשובה לשאלה של Platform — ממשיכים במודל ששאל
        if (($message['kind'] ?? '') === 'choice_answer' && !empty($message['reply_to'])) {
            $q = db()->prepare("SELECT ai_used FROM messages WHERE id = ? AND author_role = 'platform'");
            $q->execute([(int) $message['reply_to']]);
            $used = json_decode((string) ($q->fetchColumn() ?: ''), true);
            if (is_array($used) && ($used['model'] ?? '') !== '') $try(json_encode(['provider' => $used['provider'], 'model' => $used['model'], 'effort' => $used['effort'] ?? 'auto']), 'choice');
        }
        $try($message['ai_pref'] ?? null, 'message');
    }
    if ($channel) $try($channel['ai_pref'] ?? null, 'channel');
    if ($pick) {
        $chain = array_map(fn ($c) => ['model' => $pick['model'], 'effort' => $pick['effort']] + $c, aiChain($payerUser, null, $pick['provider']));
        return $pick + ['source' => $source, 'notice' => $notice, 'chain' => $chain];
    }
    // ברירת המחדל: כל חיבור — המודל וברמת החשיבה שלו
    $eff = [];
    foreach (aiOptions($payer) as $o) $eff[$o['connection_id']] = $o['effort'];
    $chain = array_map(fn ($c) => $c + ['effort' => $eff[$c['connection_id']] ?? 'auto'], aiChain($payerUser));
    $first = $chain[0] ?? ['provider' => '', 'model' => '', 'effort' => 'auto'];
    return ['provider' => $first['provider'], 'model' => $first['model'], 'effort' => $first['effort'], 'source' => 'connection',
            'notice' => $notice, 'chain' => $chain];
}

/** מה הצ'אט בוחר (לשבב ולגיליון): השם, הרמה, ואם השמור כבר לא תקף — notice. */
function channelAiInfo(array $channel): array {
    $payerId = projectPayer((int) $channel['project_id']);
    $payer = $payerId ? getUserById($payerId) : null;
    if (!$payer) return ['provider' => '', 'model' => '', 'effort' => 'auto', 'source' => 'connection', 'notice' => ''];
    $r = resolveAi($payer, $channel);
    unset($r['chain']);
    return $r + ['own' => json_decode((string) ($channel['ai_pref'] ?? ''), true) ?: null];
}

/** בחירה לצ'אט (בעל הלוח). null — חזרה לברירת המחדל של החיבור. */
function setChannelAiPref(array $user, int $channelId, ?array $pref): array {
    if (authorRole($user) !== 'owner') throw new AppError('מודל לשיחה — בעל הלוח בלבד', 403);
    $c = getChannel($channelId);
    if (!$c) throw new AppError('הערוץ לא נמצא', 404);
    requireProject((int) $c['project_id'], $user, 'read');
    $payer = (int) projectPayer((int) $c['project_id']);
    $v = aiPrefValidate($payer, $pref);
    db()->prepare('UPDATE channels SET ai_pref = ? WHERE id = ?')->execute([$v ? json_encode($v) : null, $channelId]);
    logEvent($user, 'ai.channel_pref', "channel:$channelId", true, $v ? "{$v['model']} · {$v['effort']}" : 'default');
    return channelAiInfo(getChannel($channelId));
}

/** רמת חשיבה כברירת מחדל לחיבור (בעל החיבור). */
function setConnectionEffort(array $user, int $connectionId, string $effort): void {
    $r = ownConnection($user, $connectionId);
    if ($r['kind'] === 'github') throw new AppError('חיבור גיטהאב — אין חשיבה', 422);
    if (!in_array($effort, effortLevels($r['kind'], (string) $r['model']), true)) {
        throw new AppError('רמת החשיבה "' . (EFFORT_LABEL[$effort] ?? $effort) . "\" לא נתמכת ב-{$r['model']}", 422);
    }
    db()->prepare('UPDATE connections SET effort = ? WHERE id = ?')->execute([$effort === 'auto' ? null : $effort, $connectionId]);
}

/* ── הערכה לפני קריאה (§16.4) ───────────────────────────────── */

/** כמה הקריאה עלולה לעלות לכל היותר: הקשר × מחיר קלט + max_tokens × מחיר פלט. */
function aiEstimate(string $provider, string $model, string $effort, int $promptChars, int $maxTokens): float {
    [$pi, $po] = modelPrice($provider, $model);
    [, $effMax] = effortFor($provider, $model, $effort);
    $out = max($maxTokens, MIN_OUTPUT_TOKENS, $effMax);
    return round((($promptChars / AI_CHARS_PER_TOKEN) * $pi + $out * $po) / 1_000_000, 4);
}

function modelShortName(string $model): string {
    if (preg_match('/^claude-(opus|sonnet|haiku|fable|mythos)-(\d+)(?:-(\d+))?/', $model, $m)) {
        return ucfirst($m[1]) . ' ' . $m[2] . (isset($m[3]) && strlen($m[3]) <= 2 ? '.' . $m[3] : '');
    }
    return $model;
}

/**
 * לפני platform-reply: ההערכה גבוהה ממה שנשאר במכסה — לא קוראים לספק. כרטיס אחד (kind ai_card) ליד ההודעה:
 * "שלח ב-<מודל הצ'אט>" / "בטל". אף פעם לא הורדת מודל בשקט. null — אפשר לקרוא.
 */
function aiPreflight(array $payerUser, array $channel, array $message, array $ai, int $promptChars, int $maxTokens): ?array {
    $q = quotaState((int) $payerUser['id']);
    if ($q['state'] === 'off') return null;   // requireQuota כבר מטפל
    $est = aiEstimate($ai['provider'], $ai['model'], $ai['effort'], $promptChars, $maxTokens);
    if ($est <= (float) $q['left']) return null;
    $mid = (int) $message['id'];
    $open = db()->prepare("SELECT id FROM messages WHERE kind = 'ai_card' AND reply_to = ? AND status = 'open'");
    $open->execute([$mid]);
    if ($id = $open->fetchColumn()) return ['card_id' => (int) $id, 'estimate' => $est, 'left' => (float) $q['left']];
    $chatAi = channelAiInfo($channel);
    $alt = ($ai['source'] === 'message' || $ai['source'] === 'choice') && $chatAi['model'] !== $ai['model'] ? $chatAi : null;
    $meta = ['model' => $ai['model'], 'effort' => $ai['effort'], 'estimate' => $est, 'left' => (float) $q['left'],
             'alt' => $alt ? ['provider' => $alt['provider'], 'model' => $alt['model'], 'effort' => $alt['effort'], 'name' => modelShortName($alt['model'])] : null];
    db()->prepare("INSERT INTO messages (channel_id, direction, author_role, kind, body, reply_to, answered_by, meta, status, created_at)
                   VALUES (?, 'out', 'system', 'ai_card', ?, ?, '', ?, 'open', ?)")
        ->execute([(int) $channel['id'], sprintf('השאלה הזו ב-%s עלולה לעלות עד $%.2f, ונותרו $%.2f במכסה.', modelShortName($ai['model']), $est, $q['left']),
                   $mid, json_encode($meta, JSON_UNESCAPED_UNICODE), nowTs()]);
    $cardId = (int) db()->lastInsertId();
    // ההודעה ממתינה לבעל הלוח — לא ל-Platform ולא לצ'אט
    db()->prepare("UPDATE messages SET answered_by = 'platform-hold' WHERE id = ?")->execute([$mid]);
    channelStateRefresh((int) $channel['id']);
    return ['card_id' => $cardId, 'estimate' => $est, 'left' => (float) $q['left']];
}

/** התשובה לכרטיס: send — במודל של הצ'אט (ההודעה מאבדת את הבחירה שלה); cancel — ההודעה לא נשלחת ל-Platform. */
function aiCardAnswer(array $user, int $cardId, string $choice): array {
    if (authorRole($user) !== 'owner') throw new AppError('בעל הלוח בלבד', 403);
    if (!in_array($choice, ['send', 'cancel'], true)) throw new InvalidArgumentException('choice: send או cancel');
    $st = db()->prepare("SELECT * FROM messages WHERE id = ? AND kind = 'ai_card'");
    $st->execute([$cardId]);
    $card = $st->fetch();
    if (!$card) throw new AppError('הכרטיס לא נמצא', 404);
    if ($card['status'] !== 'open') throw new AppError('הכרטיס כבר נסגר', 409);
    $c = getChannel((int) $card['channel_id']);
    requireProject((int) $c['project_id'], $user, 'read');
    $meta = json_decode((string) $card['meta'], true) ?: [];
    if ($choice === 'send' && empty($meta['alt'])) throw new AppError('גם במודל של הצ\'אט זה עובר את המכסה — אפשר רק לבטל, או להגדיל מכסה', 409);
    $mid = (int) $card['reply_to'];
    return inTx(function () use ($card, $meta, $choice, $mid, $c) {
        $meta['decision'] = $choice;
        db()->prepare("UPDATE messages SET status = 'answered', meta = ? WHERE id = ?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $card['id']]);
        if ($choice === 'send') {
            db()->prepare("UPDATE messages SET ai_pref = ?, answered_by = 'platform' WHERE id = ?")
                ->execute([json_encode($meta['alt']), $mid]);
        } else {
            db()->prepare("UPDATE messages SET answered_by = 'platform', delivered_at = ? WHERE id = ?")->execute([nowTs(), $mid]);
            channelInsertIn((int) $c['id'], 'system', null, 'בוטל — ההודעה לא נשלחה ל-Platform (המכסה).', 'status', null, $mid, false);
        }
        channelStateRefresh((int) $c['id']);
        return ['decision' => $choice, 'run' => $choice === 'send' ? [$mid] : []];
    });
}

/** ‏ai_used לבועה, מתוך הקריאה האחרונה (CT_AI_LAST) והשרשרת. */
function aiUsedRecord(array $ai, array $fallbacks): ?string {
    $last = $GLOBALS['CT_AI_LAST'] ?? null;
    if (!$last) return null;
    return json_encode($last + ['source' => $ai['source'], 'fell_back_from' => $fallbacks ? implode(' · ', $fallbacks) : null], JSON_UNESCAPED_UNICODE);
}

/** פירוט השימוש לפי מודל לחודש — לגרף (§16.4). */
function usageByModel(int $userId): array {
    $st = db()->prepare('SELECT provider, model, ROUND(SUM(cost_usd), 4) cost, COUNT(*) n FROM usage WHERE user_id = ? AND at >= ?
                          GROUP BY provider, model ORDER BY cost DESC');
    $st->execute([$userId, monthStart()]);
    return array_map(fn ($r) => ['provider' => $r['provider'], 'model' => $r['model'], 'name' => modelShortName((string) $r['model']),
                                 'cost' => (float) $r['cost'], 'calls' => (int) $r['n']], $st->fetchAll());
}
