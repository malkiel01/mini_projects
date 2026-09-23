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
        // רשימה מובנית לבחירה מיידית, בלי צורך בקריאת רשת. כפתור
        // "טען מודלים" ב-UI מרחיב אותה מ-API החי של הספק.
        'models'  => [
            ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Haiku 4.5 — מהיר וזול'],
            ['id' => 'claude-sonnet-5',           'label' => 'Sonnet 5 — חכם ומאוזן'],
            ['id' => 'claude-opus-5',             'label' => 'Opus 5 — החכם ביותר (יקר)'],
        ],
    ],
    'openai' => [
        'label'   => 'OpenAI — GPT',
        'prefix'  => 'sk-',
        'default' => '',
        'hint'    => 'platform.openai.com ← API keys',
        // ‏OpenAI מחליפה שמות מודלים תכופות — עדיף לטעון מהספק
        // בכפתור "טען מודלים" מאשר לקבע רשימה שתתיישן.
        'models'  => [],
    ],
    'google' => [
        'label'   => 'Google — Gemini',
        'prefix'  => 'AIza',
        'default' => '',
        'hint'    => 'aistudio.google.com ← Get API key',
        'models'  => [],
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

/** מחזיר [url, headers, body] לפנייה. */
function providerRequest(string $provider, string $model, string $system, string $prompt, int $maxTokens): array {
    switch ($provider) {
        case 'anthropic':
            return [
                'https://api.anthropic.com/v1/messages',
                ['Content-Type: application/json', 'anthropic-version: 2023-06-01'],
                ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
                 'messages' => [['role' => 'user', 'content' => $prompt]]],
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
        throw new AppError(match ((int) ($res['status'] ?? 0)) {
            401, 403 => 'המפתח של ' . PROVIDERS[$provider]['label'] . ' נדחה',
            429      => PROVIDERS[$provider]['label'] . ': המכסה נגמרה או שהשירות עמוס',
            404      => "המודל \"$model\" אינו קיים אצל " . PROVIDERS[$provider]['label'],
            default  => PROVIDERS[$provider]['label'] . " החזיר שגיאה ({$res['status']}) $msg",
        });
    }

    $text = providerText($provider, $data);
    if (trim($text) === '') throw new AppError(PROVIDERS[$provider]['label'] . ' החזיר תשובה ריקה');
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

function aiHttpGet(string $url, array $headers): array {
    if (!function_exists('curl_init')) throw new AppError('cURL אינו זמין בשרת');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
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

/* ── רשימת מודלים חיה מהספק ─────────────────────────────────────── */

/** [url, headers] לשליפת רשימת המודלים אצל הספק. */
function providerModelsRequest(string $provider, string $key): array {
    switch ($provider) {
        case 'anthropic':
            return ['https://api.anthropic.com/v1/models?limit=100',
                    ['anthropic-version: 2023-06-01', 'x-api-key: ' . $key]];
        case 'openai':
            return ['https://api.openai.com/v1/models',
                    ['Authorization: Bearer ' . $key]];
        case 'google':
            // המפתח בכתובת, כמו בפניית ההשלמה.
            return ['https://generativelanguage.googleapis.com/v1beta/models?pageSize=200&key=' . rawurlencode($key),
                    []];
    }
    throw new InvalidArgumentException('ספק לא מוכר: ' . $provider);
}

/** מוציא [id, label] מכל מודל, ומסנן מה שאינו רלוונטי לצ'אט. */
function providerModelsParse(string $provider, array $data): array {
    $out = [];
    switch ($provider) {
        case 'anthropic':
            foreach ($data['data'] ?? [] as $m) {
                if (!empty($m['id'])) $out[] = ['id' => $m['id'], 'label' => $m['display_name'] ?? $m['id']];
            }
            break;
        case 'openai':
            // רק מודלים שיודעים לשוחח — לא embeddings/tts/whisper/image.
            foreach ($data['data'] ?? [] as $m) {
                $id = (string) ($m['id'] ?? '');
                if ($id !== '' && preg_match('/^(gpt|o[1-9]|chatgpt)/', $id)) {
                    $out[] = ['id' => $id, 'label' => $id];
                }
            }
            break;
        case 'google':
            foreach ($data['models'] ?? [] as $m) {
                $name    = (string) ($m['name'] ?? '');
                $methods = $m['supportedGenerationMethods'] ?? [];
                if ($name !== '' && in_array('generateContent', $methods, true)) {
                    $id = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
                    $out[] = ['id' => $id, 'label' => $m['displayName'] ?? $id];
                }
            }
            break;
    }
    // ייחוד לפי id, סדר יציב.
    $seen = [];
    $uniq = [];
    foreach ($out as $m) {
        if (isset($seen[$m['id']])) continue;
        $seen[$m['id']] = true;
        $uniq[] = $m;
    }
    return $uniq;
}

/**
 * שולף את רשימת המודלים מהספק. $transport מוזרק בבדיקות.
 */
function listProviderModels(string $provider, string $key, ?callable $transport = null): array {
    if (!providerExists($provider)) throw new InvalidArgumentException('ספק לא מוכר');
    if ($key === '') throw new AppError('צריך מפתח API כדי לטעון מודלים');

    [$url, $headers] = providerModelsRequest($provider, $key);
    $res = $transport ? $transport($provider, $url, $key) : aiHttpGet($url, $headers);

    $data = json_decode((string) $res['body'], true);
    $data = is_array($data) ? $data : [];

    if (($res['status'] ?? 0) !== 200) {
        throw new AppError(match ((int) ($res['status'] ?? 0)) {
            401, 403 => 'המפתח של ' . PROVIDERS[$provider]['label'] . ' נדחה בעת טעינת המודלים',
            429      => PROVIDERS[$provider]['label'] . ': המכסה נגמרה או שהשירות עמוס',
            default  => 'טעינת רשימת המודלים נכשלה (' . ($res['status'] ?? 0) . ')',
        });
    }

    $models = providerModelsParse($provider, $data);
    if (!$models) throw new AppError('הספק לא החזיר מודלים מתאימים');
    return $models;
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
