<?php
/**
 * שכבת הספקים.
 *
 * המערכת אינה קשורה לספק אחד. משתמש מחבר לחשבונו את הספקים שיש לו,
 * ובוחר לכל מטלה מי יבצע אותה — קלוד למטלה אחת, GPT לאחרת, ג'מיני
 * לשלישית.
 *
 * ‏שלושת ה-API שונים זה מזה בכל פרט: מבנה הבקשה, מקום הוראת המערכת,
 * צורת התשובה, ואפילו איפה יושב המפתח (כותרת ייעודית, Bearer, או
 * פרמטר בכתובת). הקובץ הזה בולע את ההבדלים, ושאר המערכת רואה פונקציה
 * אחת: שלח הוראה, קבל טקסט.
 */

declare(strict_types=1);

require_once __DIR__ . '/errors.php';

/**
 * הספקים הנתמכים.
 *
 * ‏prefix הוא הצורה הידועה של מפתח אצל אותו ספק — נועד לתפוס הדבקה של
 * מפתח לתיבה הלא נכונה, לא להיות בדיקת אמיתות. הדבר היחיד שבאמת מוכיח
 * שמפתח תקין הוא פנייה מוצלחת, ולכן יש כפתור בדיקה.
 */
const PROVIDERS = [
    'anthropic' => [
        'label'   => 'Anthropic — Claude',
        'prefix'  => 'sk-ant-',
        'default' => 'claude-haiku-4-5-20251001',
        'hint'    => 'console.anthropic.com ← API keys',
    ],
    'openai' => [
        'label'   => 'OpenAI — GPT',
        'prefix'  => 'sk-',
        'default' => '',
        'hint'    => 'platform.openai.com ← API keys',
    ],
    'google' => [
        'label'   => 'Google — Gemini',
        'prefix'  => 'AIza',
        'default' => '',
        'hint'    => 'aistudio.google.com ← Get API key',
    ],
];

function providerExists(string $id): bool { return isset(PROVIDERS[$id]); }

/**
 * בודק צורת מפתח. שגיאה כאן חוסכת פנייה שתיכשל ממילא, אבל היא רק
 * רמז — מפתח שעובר את הבדיקה עדיין יכול להיות שגוי או פג.
 */
function checkProviderKey(string $provider, string $key) {
    if (!providerExists($provider)) throw new InvalidArgumentException('ספק לא מוכר');
    $prefix = PROVIDERS[$provider]['prefix'];

    if (strlen($key) < 16 || !preg_match('/^[A-Za-z0-9_\-]{16,300}$/', $key)) {
        throw new InvalidArgumentException('המפתח קצר מדי או מכיל תווים שאינם שייכים למפתח API');
    }
    if ($prefix !== '' && !str_starts_with($key, $prefix)) {
        throw new InvalidArgumentException(
            "מפתח של " . PROVIDERS[$provider]['label'] . " אמור להתחיל ב-\"$prefix\" — ודא שלא הודבק מפתח של ספק אחר"
        );
    }

    /*
     * ‏"sk-ant-" מתחיל ב-"sk-", ולכן מפתח של Anthropic עובר בקלות את
     * הבדיקה של OpenAI. תחילית ארוכה יותר של ספק אחר גוברת: היא מזהה
     * במדויק, ובלי זה השגיאה הייתה מתגלה רק בפנייה הראשונה בתשלום.
     */
    foreach (PROVIDERS as $other => $meta) {
        if ($other === $provider || $meta['prefix'] === '') continue;
        if (strlen($meta['prefix']) > strlen($prefix) && str_starts_with($key, $meta['prefix'])) {
            throw new InvalidArgumentException(
                'זה נראה כמו מפתח של ' . $meta['label'] . ', ולא של ' . PROVIDERS[$provider]['label']
            );
        }
    }
}

/* ── בנייה ופענוח לפי ספק ──────────────────────────────────────── */

/**
 * מודלים שחושבים לפני שהם עונים (Claude 5, Opus 4.6+, GPT-5 / o, Gemini 2.5):
 * החשיבה נספרת מתוך תקרת הטוקנים. בתקרה של 16 (בדיקת חיבור) או 400 (קטלוג)
 * החשיבה בולעת הכול והתשובה חוזרת ריקה. לכן התקרה לעולם לא יורדת מכאן —
 * ומשלמים רק על מה שנוצר בפועל, לא על התקרה.
 */
const MIN_OUTPUT_TOKENS = 2048;

/**
 * ‏Claude שבו אי אפשר לכבות חשיבה (או שהיא פועלת כברירת מחדל): משאלה קצרה כמו
 * שלנו מבקשים מאמץ נמוך. במודלים ישנים יותר (Haiku 4.5 ומטה) השדה לא נתמך — לא שולחים.
 */
function anthropicTakesEffort(string $model): bool {
    return (bool) preg_match('/^claude-(opus|sonnet|haiku|fable|mythos)-(5|4-[678])/', $model);
}

/** מחזיר [url, headers, body] לפנייה. */
function providerRequest(string $provider, string $model, string $system, string $prompt, int $maxTokens): array {
    $maxTokens = max($maxTokens, MIN_OUTPUT_TOKENS);
    switch ($provider) {
        case 'anthropic':
            $body = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
                     'messages' => [['role' => 'user', 'content' => $prompt]]];
            if (anthropicTakesEffort($model)) $body['output_config'] = ['effort' => 'low'];
            return [
                'https://api.anthropic.com/v1/messages',
                ['Content-Type: application/json', 'anthropic-version: 2023-06-01'],
                $body,
            ];

        case 'openai':
            // הוראת המערכת היא הודעה ברשימה, ולא שדה נפרד.
            return [
                'https://api.openai.com/v1/chat/completions',
                ['Content-Type: application/json'],
                ['model' => $model, 'max_completion_tokens' => $maxTokens,
                 'messages' => [['role' => 'system', 'content' => $system],
                                ['role' => 'user',   'content' => $prompt]]],
            ];

        case 'google':
            // המפתח נוסף לכתובת בשלב השליחה, לא כאן.
            return [
                'https://generativelanguage.googleapis.com/v1beta/models/'
                    . rawurlencode($model) . ':generateContent',
                ['Content-Type: application/json'],
                ['system_instruction' => ['parts' => [['text' => $system]]],
                 'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                 'generationConfig' => ['maxOutputTokens' => $maxTokens]],
            ];
    }
    throw new InvalidArgumentException('ספק לא מוכר: ' . $provider);
}

/** מוציא את הטקסט מהתשובה, לפי המבנה של אותו ספק. */
function providerText(string $provider, array $data): string {
    $out = '';
    switch ($provider) {
        case 'anthropic':
            foreach ($data['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $out .= $b['text'];
            break;
        case 'openai':
            $out = (string) ($data['choices'][0]['message']['content'] ?? '');
            break;
        case 'google':
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $p) $out .= $p['text'] ?? '';
            break;
    }
    return $out;
}

/** הודעת השגיאה של הספק, אם יש כזו במקום שבו הוא נוהג לשים אותה. */
function providerError(string $provider, array $data): string {
    return (string) ($data['error']['message'] ?? $data['message'] ?? '');
}

/* ── הפנייה ────────────────────────────────────────────────────── */

/**
 * שולח הוראה לספק ומחזיר טקסט.
 *
 * ‏$conn = ['provider' => …, 'key' => …, 'model' => …]
 * ‏$transport מוזרק בבדיקות, כדי שאפשר יהיה לבדוק בנייה ופענוח בלי
 * מפתחות אמיתיים ובלי לשלם על כל הרצה.
 */
function aiComplete(array $conn, string $system, string $prompt,
                    int $maxTokens = 400, ?callable $transport = null): string {
    // שימוש ומכסה (lib/usage.php): ‏usage — kind, project_id, task_id, channel_id, enforce
    $ctx = $conn['usage'] ?? [];
    // שרשרת חיבורים (lib/connections.php): נכשל אחד — הבא בתור
    if (!empty($conn['chain']) && count($conn['chain']) > 1) {
        // המכסה נבדקת פעם אחת, לפני הראשון: מכסה שנגמרה לא "נעקפת" במעבר לחיבור הבא
        if (!empty($ctx['enforce']) && !empty($conn['chain'][0]['user_id'])) {
            requireQuota((int) $conn['chain'][0]['user_id'], (string) ($ctx['kind'] ?? 'monitor'));
        }
        $chain = array_map(fn ($c) => $c + ['usage' => ['enforce' => false] + $ctx], $conn['chain']);
        return aiCompleteChain($chain, $system, $prompt, $maxTokens, $transport,
                               $conn['user'] ?? null, $conn['task_id'] ?? null)[0];
    }
    if (!empty($ctx['enforce']) && !empty($conn['user_id'])) {
        requireQuota((int) $conn['user_id'], (string) ($ctx['kind'] ?? 'monitor'));
    }
    $provider = (string) ($conn['provider'] ?? '');
    $key      = (string) ($conn['key'] ?? '');
    $model    = trim((string) ($conn['model'] ?? '')) ?: (PROVIDERS[$provider]['default'] ?? '');

    if (!providerExists($provider)) throw new InvalidArgumentException('ספק לא מוכר: ' . $provider);
    if ($key === '')   throw new AppError('לא הוגדר מפתח עבור ' . PROVIDERS[$provider]['label']);
    if ($model === '') throw new AppError('לא הוגדר מודל עבור ' . PROVIDERS[$provider]['label']);

    [$url, $headers, $body] = providerRequest($provider, $model, $system, $prompt, $maxTokens);

    // כל ספק ממקם את המפתח במקום אחר.
    if ($provider === 'anthropic')   $headers[] = 'x-api-key: ' . $key;
    elseif ($provider === 'openai')  $headers[] = 'Authorization: Bearer ' . $key;
    elseif ($provider === 'google')  $headers[] = 'x-goog-api-key: ' . $key;

    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $res  = $transport
        ? $transport($provider, $url, $json, $key)
        : aiHttp($url, $headers, (string) $json);

    $data = json_decode((string) $res['body'], true);
    $data = is_array($data) ? $data : [];

    if (($res['status'] ?? 0) !== 200) {
        $msg = providerError($provider, $data);
        $err = new AppError(match ((int) ($res['status'] ?? 0)) {
            401, 403 => 'המפתח של ' . PROVIDERS[$provider]['label'] . ' נדחה',
            429      => PROVIDERS[$provider]['label'] . ': המכסה נגמרה או שהשירות עמוס',
            404      => "המודל \"$model\" אינו קיים אצל " . PROVIDERS[$provider]['label'],
            default  => PROVIDERS[$provider]['label'] . " החזיר שגיאה ({$res['status']}) $msg",
        });
        $err->aiStatus = (int) ($res['status'] ?? 0);
        throw $err;
    }

    // נרשם גם כשהתשובה ריקה — הטוקנים נצרכו
    if (!empty($conn['user_id'])) {
        [$tin, $tout] = usageFromResponse($provider, $data);
        recordUsage(['user_id' => (int) $conn['user_id'], 'connection_id' => $conn['connection_id'] ?? null] + $ctx,
                    $provider, $model, $tin, $tout);
    }

    $text = providerText($provider, $data);
    if (trim($text) === '') {
        // הסיבה השכיחה: המודל חשב והגיע לתקרה לפני שכתב. אומרים את זה, ולא "ריקה" סתם.
        $stop = (string) ($data['stop_reason'] ?? $data['choices'][0]['finish_reason'] ?? $data['candidates'][0]['finishReason'] ?? '');
        if (in_array($stop, ['max_tokens', 'length', 'MAX_TOKENS'], true)) {
            throw new AppError(PROVIDERS[$provider]['label'] . ": המודל \"$model\" חשב עד התקרה ולא הספיק לענות");
        }
        if ($stop === 'refusal') throw new AppError(PROVIDERS[$provider]['label'] . ': המודל סירב לבקשה');
        throw new AppError(PROVIDERS[$provider]['label'] . ' החזיר תשובה ריקה' . ($stop !== '' ? " ($stop)" : ''));
    }
    return $text;
}

function aiHttp(string $url, array $headers, string $json): array {
    if (!function_exists('curl_init')) throw new AppError('cURL אינו זמין בשרת');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new AppError('הפנייה לספק נכשלה: ' . $err);
    return ['status' => $code, 'body' => (string) $body];
}

/** מודלים נוטים לעטוף JSON בטקסט. לוקחים את הבלוק ולא את העטיפה. */
function extractJson(string $text): array {
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end < $start) {
        throw new AppError('לא נמצא JSON בתשובת המודל');
    }
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($data)) throw new AppError('ה-JSON בתשובת המודל אינו תקין');
    return $data;
}

/* ── רשימת המודלים של ספק ─────────────────────────────────────────
 *
 * הבורר בהגדרות מציג את המודלים שהספק באמת מציע לאותו מפתח — לא רשימה
 * שמוקלדת ביד ומתיישנת. בלי מפתח, או כשהפנייה נכשלה, יש גיבוי קצר בקוד
 * (רק לאנתרופיק, שהמזהים שלו ידועים), ותמיד אפשר "אחר…" ידני.
 */

/** גיבוי כשאין רשימה חיה. מזהים בלבד — בלי להמציא מזהים לספקים אחרים. */
const MODELS_FALLBACK = [
    'anthropic' => [
        ['id' => 'claude-opus-5-5',           'label' => 'Claude Opus 5.5'],
        ['id' => 'claude-sonnet-5-5',         'label' => 'Claude Sonnet 5.5'],
        ['id' => 'claude-haiku-5-5',          'label' => 'Claude Haiku 5.5'],
        ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5'],
    ],
    'openai' => [],
    'google' => [],
];

/**
 * המודלים של ספק, לפי המפתח. מחזיר ['models' => [[id, label]], 'source' => live|fallback, 'error' => ?].
 * ‏$transport — כמו ב-aiComplete, לבדיקות: fn(provider, url, key) → [status, body].
 */
function providerModels(string $provider, string $key, ?callable $transport = null): array {
    if (!providerExists($provider)) throw new InvalidArgumentException('ספק לא מוכר');
    $fallback = ['models' => MODELS_FALLBACK[$provider] ?? [], 'source' => 'fallback'];
    if ($key === '') return $fallback;

    [$url, $headers] = match ($provider) {
        'anthropic' => ['https://api.anthropic.com/v1/models?limit=100',
                        ['anthropic-version: 2023-06-01', 'x-api-key: ' . $key]],
        'openai'    => ['https://api.openai.com/v1/models', ['Authorization: Bearer ' . $key]],
        'google'    => ['https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', ['x-goog-api-key: ' . $key]],
    };
    try {
        $res = $transport ? $transport($provider, $url, $key) : aiHttpGet($url, $headers);
    } catch (Throwable $e) {
        return $fallback + ['error' => $e->getMessage()];
    }
    $data = json_decode((string) ($res['body'] ?? ''), true);
    if (($res['status'] ?? 0) !== 200 || !is_array($data)) {
        $code = (int) ($res['status'] ?? 0);
        return $fallback + ['error' => in_array($code, [401, 403], true)
            ? 'המפתח נדחה' : "הספק החזיר $code"];
    }

    $out = [];
    if ($provider === 'anthropic') {
        foreach ($data['data'] ?? [] as $m) {
            if (!empty($m['id'])) $out[] = ['id' => $m['id'], 'label' => $m['display_name'] ?? $m['id']];
        }
    } elseif ($provider === 'openai') {
        // הרשימה כוללת גם הטמעה, קול ותמונה — משאירים רק מודלים לשיחה
        foreach ($data['data'] ?? [] as $m) {
            $id = (string) ($m['id'] ?? '');
            if (!preg_match('/^(gpt-|o\d|chatgpt-)/', $id)) continue;
            if (preg_match('/(audio|realtime|tts|transcribe|search|image|embedding|moderation|instruct)/', $id)) continue;
            $out[] = ['id' => $id, 'label' => $id];
        }
        usort($out, fn ($a, $b) => strcmp($b['id'], $a['id']));
    } else {
        foreach ($data['models'] ?? [] as $m) {
            if (!in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) continue;
            $id = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            if ($id === '' || !str_starts_with($id, 'gemini')) continue;
            $out[] = ['id' => $id, 'label' => $m['displayName'] ?? $id];
        }
    }
    return $out ? ['models' => $out, 'source' => 'live'] : $fallback;
}

function aiHttpGet(string $url, array $headers): array {
    if (!function_exists('curl_init')) throw new AppError('cURL אינו זמין בשרת');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                            CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $headers]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new AppError('הפנייה לספק נכשלה: ' . $err);
    return ['status' => $code, 'body' => (string) $body];
}
