<?php
/**
 * מתאם לכל ספק — כאן, ורק כאן, יש פרטים של ספק (docs/spec-3-ai.md, סעיפים 1 ו-3).
 * ספק חדש = רשומה ב-AI_PROVIDERS + פונקציית run אחת. אף אפליקציה לא משתנה.
 *
 * כל run מקבל ($task, $in, $key, $model) ומחזיר אחד מאלה:
 *   ['png' => bytes, 'transparent' => bool]   — remove-background / generate-image / inpaint (בגודל שהספק החזיר)
 *   ['masks' => [bytes, …]]                    — segment (המסכות כמו שהספק החזיר; ההתאמה — ב-ai.php)
 *   ['json' => mixed] | ['text' => string]     — complete
 * $in: image?, mask? (bytes), prompt?, system?, schema? (array), images? ([bytes]), size? ('1024x1536'), transparent?
 */

declare(strict_types=1);

require_once __DIR__ . '/image.php';
require_once __DIR__ . '/anthropic.php';

const AI_PROVIDERS = [
    'openai' => [
        'name' => 'OpenAI',
        'tasks' => ['generate-image', 'inpaint', 'complete'],
        'models' => ['generate-image' => 'gpt-image-1', 'inpaint' => 'gpt-image-1', 'complete' => 'gpt-4.1-mini'],
        'cost' => ['generate-image' => 0.07, 'inpaint' => 0.07, 'complete' => 0.005],
        'sizes' => ['1024x1024', '1024x1536', '1536x1024'],
    ],
    'google' => [
        'name' => 'Google',
        'tasks' => ['generate-image', 'inpaint', 'complete'],
        'models' => ['generate-image' => 'gemini-2.5-flash-image', 'inpaint' => 'gemini-2.5-flash-image', 'complete' => 'gemini-2.5-flash'],
        'cost' => ['generate-image' => 0.04, 'inpaint' => 0.04, 'complete' => 0.002],
        'ratios' => ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
    ],
    'anthropic' => [
        'name' => 'Anthropic (Claude)',
        'tasks' => ['complete'],   // טקסט / JSON, גם עם תמונות לתיאור — לא מייצר תמונות (lib/ai/anthropic.php)
        'models' => ['complete' => 'claude-opus-5-5'],
        'cost' => ['complete' => 0.03],   // הערכה לקריאה: $4 / $20 למיליון אסימונים
    ],
    'replicate' => [
        'name' => 'Replicate',
        'tasks' => ['remove-background', 'segment'],
        // "בעלים/שם" למודל רשמי, או "בעלים/שם:גרסה" — המנהל יכול להחליף בממשק
        'models' => ['remove-background' => '851-labs/background-remover', 'segment' => 'meta/sam-2'],
        'cost' => ['remove-background' => 0.005, 'segment' => 0.02],
    ],
];

const AI_TASKS = ['remove-background', 'segment', 'generate-image', 'inpaint', 'complete'];

// ─── HTTP ─────────────────────────────────────────────────────────────────

/**
 * בקשה לספק: [status, body]. בבדיקות — $GLOBALS['AI_HTTP'] (אותה חתימה), כדי שלא תצא קריאה אמיתית.
 * $body: מחרוזת (JSON) או מערך (multipart; ערך CURLFile / CURLStringFile לקובץ).
 */
function aiHttp(string $method, string $url, array $headers, string|array|null $body = null, int $timeout = 120): array {
    $fake = $GLOBALS['AI_HTTP'] ?? null;
    if (is_callable($fake)) return $fake($method, $url, $headers, $body);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) throw new AppError('הספק לא ענה' . ($err !== '' && str_contains($err, 'timed out') ? ' בזמן' : ''), 504, 'provider', ['detail' => $err]);
    return [$status, (string) $out];
}

/** JSON מהספק, או שגיאה בעברית עם הפרטים ביומן. */
function aiJson(array $res, string $who): array {
    [$status, $body] = $res;
    $j = json_decode($body, true);
    if ($status >= 200 && $status < 300 && is_array($j)) return $j;
    $msg = is_array($j) ? (string) ($j['error']['message'] ?? $j['detail'] ?? $j['error'] ?? '') : '';
    error_log("suite-cloud ai $who $status: " . mb_substr($msg !== '' ? $msg : $body, 0, 500));
    if ($status === 401 || $status === 403) throw new AppError("$who: המפתח נדחה — בדקו אותו בניהול", 502, 'provider');
    if ($status === 429) throw new AppError("$who: עומס או מכסה אצל הספק — נסו שוב בעוד רגע", 502, 'provider');
    if ($status === 400 && $msg !== '') throw new AppError("$who דחה את הבקשה: " . mb_substr($msg, 0, 200), 502, 'provider');
    throw new AppError("$who החזיר שגיאה ($status)", 502, 'provider');
}

function aiDataUri(string $bytes): string {
    $mime = (@getimagesizefromstring($bytes))['mime'] ?? 'image/png';
    return "data:$mime;base64," . base64_encode($bytes);
}

/** JSON מתשובת טקסט — גם כשהמודל עטף ב-```json. */
function aiParseJsonText(string $text): mixed {
    $t = trim($text);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $t, $m)) $t = $m[1];
    $j = json_decode($t, true);
    if ($j === null && strtolower($t) !== 'null') throw new AppError('המודל לא החזיר JSON תקין', 502, 'provider');
    return $j;
}

// ─── OpenAI ───────────────────────────────────────────────────────────────

function aiRunOpenai(string $task, array $in, string $key, string $model): array {
    $auth = ["Authorization: Bearer $key"];
    if ($task === 'generate-image') {
        $size = aiNearest(aiParseSize($in['size'] ?? '1024x1024') ?? [1024, 1024], AI_PROVIDERS['openai']['sizes']);
        $j = aiJson(aiHttp('POST', 'https://api.openai.com/v1/images/generations', [...$auth, 'Content-Type: application/json'],
            json_encode(['model' => $model, 'prompt' => (string) $in['prompt'], 'size' => $size, 'n' => 1, 'output_format' => 'png',
                         'background' => !empty($in['transparent']) ? 'transparent' : 'auto'])), 'OpenAI');
        $png = base64_decode((string) ($j['data'][0]['b64_json'] ?? ''), true);
        if (!$png) throw new AppError('OpenAI לא החזיר תמונה', 502, 'provider');
        return ['png' => $png, 'transparent' => !empty($in['transparent']) && aiHasAlpha($png)];
    }
    if ($task === 'inpaint') {
        [$w, $h] = aiSize($in['image']);
        $size = aiNearest([$w, $h], AI_PROVIDERS['openai']['sizes']);
        [$sw, $sh] = aiParseSize($size);
        // OpenAI: תמונה ומסכה באותו גודל; במסכה — שקוף איפה שממלאים
        $j = aiJson(aiHttp('POST', 'https://api.openai.com/v1/images/edits', $auth, [
            'model' => $model, 'size' => $size, 'n' => '1',
            'prompt' => (string) (($in['prompt'] ?? '') !== '' ? $in['prompt'] : 'Fill the masked area naturally, matching the surrounding image.'),
            'image' => new CURLStringFile(aiFit($in['image'], $sw, $sh), 'image.png', 'image/png'),
            'mask' => new CURLStringFile(aiMaskToAlpha($in['mask'], $sw, $sh), 'mask.png', 'image/png'),
        ]), 'OpenAI');
        $png = base64_decode((string) ($j['data'][0]['b64_json'] ?? ''), true);
        if (!$png) throw new AppError('OpenAI לא החזיר תמונה', 502, 'provider');
        return ['png' => $png, 'transparent' => false];
    }
    // complete
    $content = [['type' => 'text', 'text' => (string) $in['prompt']]];
    foreach ($in['images'] ?? [] as $img) $content[] = ['type' => 'image_url', 'image_url' => ['url' => aiDataUri($img)]];
    $messages = [];
    if (($in['system'] ?? '') !== '') $messages[] = ['role' => 'system', 'content' => (string) $in['system']];
    $messages[] = ['role' => 'user', 'content' => $content];
    $body = ['model' => $model, 'messages' => $messages];
    if (isset($in['schema'])) {
        $body['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'result', 'schema' => $in['schema'], 'strict' => false]];
    }
    $j = aiJson(aiHttp('POST', 'https://api.openai.com/v1/chat/completions', [...$auth, 'Content-Type: application/json'], json_encode($body)), 'OpenAI');
    $text = (string) ($j['choices'][0]['message']['content'] ?? '');
    return isset($in['schema']) ? ['json' => aiParseJsonText($text)] : ['text' => $text];
}

// ─── Google (Gemini) ──────────────────────────────────────────────────────

function aiGemini(string $model, string $key, array $body): array {
    return aiJson(aiHttp('POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
        ['Content-Type: application/json', "x-goog-api-key: $key"], json_encode($body)), 'Google');
}

/** התמונה הראשונה בתשובה של Gemini. */
function aiGeminiImage(array $j): string {
    foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) {
        $d = $p['inlineData']['data'] ?? $p['inline_data']['data'] ?? null;
        if (is_string($d) && ($png = base64_decode($d, true))) return $png;
    }
    throw new AppError('Google לא החזיר תמונה' . (($j['candidates'][0]['finishReason'] ?? '') === 'SAFETY' ? ' (נחסם בסינון התוכן)' : ''), 502, 'provider');
}

function aiInline(string $bytes): array {
    return ['inlineData' => ['mimeType' => (@getimagesizefromstring($bytes))['mime'] ?? 'image/png', 'data' => base64_encode($bytes)]];
}

function aiRunGoogle(string $task, array $in, string $key, string $model): array {
    if ($task === 'generate-image') {
        $want = aiParseSize($in['size'] ?? '1024x1024') ?? [1024, 1024];
        // Gemini מקבל יחס ("4:5"), לא גודל: '4:5' → '400x500' כדי לבחור את הקרוב באותה פונקציה
        $bySize = [];
        foreach (AI_PROVIDERS['google']['ratios'] as $r) {
            [$a, $b] = explode(':', $r);
            $bySize[($a * 100) . 'x' . ($b * 100)] = $r;
        }
        $ratio = $bySize[aiNearest($want, array_keys($bySize))];
        $j = aiGemini($model, $key, ['contents' => [['parts' => [['text' => (string) $in['prompt']]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => $ratio]]]);
        return ['png' => aiGeminiImage($j), 'transparent' => false];
    }
    if ($task === 'inpaint') {
        $text = 'Edit the first image. Change ONLY the area that is white in the second image (the mask); keep everything else identical. '
              . (($in['prompt'] ?? '') !== '' ? 'In that area: ' . $in['prompt'] : 'Fill it naturally to match the surroundings.');
        $j = aiGemini($model, $key, ['contents' => [['parts' => [aiInline($in['image']), aiInline($in['mask']), ['text' => $text]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE']]]);
        return ['png' => aiGeminiImage($j), 'transparent' => false];
    }
    // complete — JSON במצב JSON; את הסכמה מוסיפים להוראות (לא כל סכמת JSON נתמכת ב-responseSchema)
    $parts = [['text' => (string) $in['prompt']]];
    foreach ($in['images'] ?? [] as $img) $parts[] = aiInline($img);
    $system = (string) ($in['system'] ?? '');
    if (isset($in['schema'])) $system .= "\n\nAnswer with JSON only, matching this JSON Schema:\n" . json_encode($in['schema'], JSON_UNESCAPED_UNICODE);
    $body = ['contents' => [['role' => 'user', 'parts' => $parts]]];
    if (trim($system) !== '') $body['system_instruction'] = ['parts' => [['text' => trim($system)]]];
    if (isset($in['schema'])) $body['generationConfig'] = ['responseMimeType' => 'application/json'];
    $j = aiGemini($model, $key, $body);
    $text = '';
    foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) $text .= $p['text'] ?? '';
    return isset($in['schema']) ? ['json' => aiParseJsonText($text)] : ['text' => $text];
}

// ─── Replicate ────────────────────────────────────────────────────────────

/** הרצת מודל ב-Replicate עד שיש תוצאה (Prefer: wait, ואם צריך — המתנה נוספת עד ~110 שניות). מחזיר את output. */
function aiReplicate(string $model, string $key, array $input): mixed {
    $h = ["Authorization: Bearer $key", 'Content-Type: application/json', 'Prefer: wait=60'];
    if (str_contains($model, ':')) {
        [, $version] = explode(':', $model, 2);
        $res = aiHttp('POST', 'https://api.replicate.com/v1/predictions', $h, json_encode(['version' => $version, 'input' => $input]));
    } else {
        $res = aiHttp('POST', 'https://api.replicate.com/v1/models/' . $model . '/predictions', $h, json_encode(['input' => $input]));
    }
    $p = aiJson($res, 'Replicate');
    $until = time() + 50;
    while (in_array($p['status'] ?? '', ['starting', 'processing'], true) && time() < $until && !empty($p['urls']['get'])) {
        if (!isset($GLOBALS['AI_HTTP'])) sleep(2);
        $p = aiJson(aiHttp('GET', (string) $p['urls']['get'], ["Authorization: Bearer $key"]), 'Replicate');
    }
    if (($p['status'] ?? '') !== 'succeeded') {
        if (in_array($p['status'] ?? '', ['starting', 'processing'], true)) throw new AppError('Replicate לא סיים בזמן — נסו שוב', 504, 'provider');
        error_log('suite-cloud ai Replicate: ' . json_encode($p['error'] ?? $p['status'] ?? ''));
        throw new AppError('Replicate נכשל בהרצת המודל', 502, 'provider');
    }
    return $p['output'] ?? null;
}

/** כל הכתובות שבפלט (מחרוזת, מערך או אובייקט), לפי הסדר. */
function aiUrls(mixed $out, ?string $prefer = null): array {
    if (is_string($out)) return preg_match('#^https://#', $out) ? [$out] : [];
    if (!is_array($out)) return [];
    if ($prefer !== null && isset($out[$prefer])) return aiUrls($out[$prefer]);
    $urls = [];
    foreach ($out as $v) $urls = [...$urls, ...aiUrls($v)];
    return $urls;
}

function aiDownload(string $url): string {
    [$status, $body] = aiHttp('GET', $url, []);
    if ($status !== 200 || $body === '') throw new AppError('לא הצלחנו להוריד את התוצאה מהספק', 502, 'provider');
    return $body;
}

function aiRunReplicate(string $task, array $in, string $key, string $model): array {
    $out = aiReplicate($model, $key, ['image' => aiDataUri($in['image'])]);
    if ($task === 'remove-background') {
        $urls = aiUrls($out);
        if (!$urls) throw new AppError('Replicate לא החזיר תמונה', 502, 'provider');
        return ['png' => aiDownload($urls[0]), 'transparent' => true];
    }
    // segment — מסכה לכל עצם (SAM: individual_masks; מודלים אחרים — רשימת כתובות)
    $urls = aiUrls($out, 'individual_masks');
    if (!$urls) throw new AppError('Replicate לא החזיר מסכות', 502, 'provider');
    return ['masks' => array_map('aiDownload', array_slice($urls, 0, 32))];
}

// ─── בדיקת מפתח (בלי עלות) ────────────────────────────────────────────────

function aiTestKey(string $provider, string $key): void {
    [$url, $h] = match ($provider) {
        'openai' => ['https://api.openai.com/v1/models', ["Authorization: Bearer $key"]],
        'google' => ['https://generativelanguage.googleapis.com/v1beta/models?pageSize=1', ["x-goog-api-key: $key"]],
        'replicate' => ['https://api.replicate.com/v1/account', ["Authorization: Bearer $key"]],
        'anthropic' => [ANTHROPIC_API . 'models?limit=1', ["x-api-key: $key", 'anthropic-version: ' . ANTHROPIC_VERSION]],
    };
    aiJson(aiHttp('GET', $url, $h, null, 15), AI_PROVIDERS[$provider]['name']);
}

function aiProviderRun(string $provider, string $task, array $in, string $key, string $model): array {
    return match ($provider) {
        'openai' => aiRunOpenai($task, $in, $key, $model),
        'google' => aiRunGoogle($task, $in, $key, $model),
        'replicate' => aiRunReplicate($task, $in, $key, $model),
        'anthropic' => aiRunAnthropic($task, $in, $key, $model),
    };
}
