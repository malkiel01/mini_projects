<?php
/**
 * ניסוח מחדש של שלבי ההכנה בבינה — דרך Claude API (Anthropic).
 *
 * למה: זכות יוצרים מגנה על הניסוח ולא על השיטה. רכיבים, כמויות, טמפרטורות,
 * זמנים וסדר הפעולות הם עובדות ונשארים; הניסוח של השלבים נכתב מחדש
 * במבנה ובמילים אחרות — ואז המתכון הוא ביטוי חדש, "מבוסס על" המקור.
 *
 * איך: בקשת HTTP ישירה ל-/v1/messages (cURL של PHP). לא ה-SDK הרשמי —
 * כי האפליקציה נפרסת ב-rsync לשרת משותף בלי Composer ובלי שלב בנייה, ואין
 * כאן תיקיית vendor. הבקשה היא אחת, לא זורמת, עם פלט מובנה (JSON לפי
 * סכימה) כדי שהתשובה תתפרס בלי ניחושים.
 *
 * אחרי התשובה נמדד דמיון למקור: שיעור השלבים שזהים למשפט במקור אחרי
 * נרמול. גבוה מדי — ניסיון שני עם הוראה חזקה יותר; ואם עדיין — התוצאה
 * חוזרת עם אזהרה, והמשתמש מחליט.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/log.php';

const AI_MODEL           = 'claude-opus-5-5';
const AI_MAX_TOKENS      = 8000;
const AI_TIMEOUT         = 90;
const AI_SIMILARITY_MAX  = 0.3;   // מעל 30% שלבים זהים → מנסחים שוב
const AI_MAX_STEPS       = 60;

function aiEndpoint(): string {
    return defined('AI_ENDPOINT') ? AI_ENDPOINT : 'https://api.anthropic.com/v1/messages';
}

function aiAvailable(): bool {
    return secretGet('anthropic_api_key') !== null && function_exists('curl_init');
}

const AI_SYSTEM_PROMPT = <<<'TXT'
אתה עורך מתכונים בעברית. מקבלים שלבי הכנה של מתכון כפי שנכתבו במקור, ומחזירים אותם כתובים מחדש — באותו תוכן, בניסוח חדש לגמרי.

חובה לשמור בדיוק: כל כמות, כל טמפרטורה, כל זמן, כל כלי, וסדר הפעולות. אסור להוסיף פעולות, רכיבים או עצות שלא היו במקור, ואסור להשמיט.

חובה לשנות: המילים, מבנה המשפטים, וגם חלוקת השלבים — לאחד שלבים קצרים או לפצל שלב ארוך, כך שהתוצאה לא תהיה פסקה-מול-פסקה של המקור. אל תחזור על משפט מהמקור כלשונו.

סגנון: עברית פשוטה ונקייה, גוף רבים בהווה ("מערבבים", "אופים"), משפטים קצרים, בלי סימני קריאה, בלי פנייה לקורא, בלי הקדמות וסיכומים. שמות החלקים (בצק, מלית) נשארים.
TXT;

function aiRequestBody(array $sections, string $title, bool $stronger = false): array {
    $input = array_map(fn($s) => [
        'name'  => (string) ($s['name'] ?? ''),
        'steps' => array_values(array_map('strval', $s['steps'] ?? [])),
    ], $sections);
    $user = "המתכון: " . $title . "\n\nהשלבים כפי שנכתבו במקור (JSON):\n"
          . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
          . ($stronger ? "\n\nהניסיון הקודם היה קרוב מדי למקור. הפעם שנה את מבנה השלבים (אחד/פצל) ואת סדר המילים בכל משפט, בלי לשנות שום עובדה." : '');
    return [
        'model'      => AI_MODEL,
        'max_tokens' => AI_MAX_TOKENS,
        'system'     => AI_SYSTEM_PROMPT,
        'fallbacks'  => 'default',
        'output_config' => [
            'effort' => 'medium',
            'format' => [
                'type'   => 'json_schema',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sections' => [
                            'type'  => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name'  => ['type' => 'string'],
                                    'steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'required' => ['name', 'steps'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['sections'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'messages' => [['role' => 'user', 'content' => $user]],
    ];
}

/** קריאה אחת ל-API. מחזיר את גוף התשובה המפוענח או זורק AppError במילים של המשתמש. */
function aiCall(array $body, string $apiKey): array {
    $ch = curl_init(aiEndpoint());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AI_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_NOPROXY        => '127.0.0.1,localhost',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01',
        ],
    ]);
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno  = curl_errno($ch);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        logEvent('error', 'ai', 'cURL: ' . $err, ['errno' => $errno]);
        throw new AppError('לא הצלחתי להגיע לשירות הבינה (' . ($errno === CURLE_OPERATION_TIMEDOUT ? 'פג הזמן' : 'שגיאת רשת') . ')', 502);
    }
    $data = json_decode((string) $raw, true);
    if ($status === 401 || $status === 403) throw new AppError('מפתח ה-API נדחה — לבדוק אותו בהגדרות הציבוריות', 502);
    if ($status === 429) throw new AppError('שירות הבינה עמוס או שהמכסה נגמרה. לנסות שוב בעוד דקה', 503);
    if ($status === 529 || $status >= 500) throw new AppError('שירות הבינה לא זמין כרגע (' . $status . '). לנסות שוב מאוחר יותר', 503);
    if ($status !== 200 || !is_array($data)) {
        $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        logEvent('error', 'ai', 'HTTP ' . $status . ' ' . $msg, ['status' => $status]);
        throw new AppError('שירות הבינה החזיר שגיאה (' . $status . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 160) : ''), 502);
    }
    return $data;
}

/** מהתשובה — הטקסט הראשון, מפוענח כ-JSON לפי הסכימה. */
function aiParseSections(array $response): array {
    $stop = (string) ($response['stop_reason'] ?? '');
    if ($stop === 'refusal') {
        $cat = (string) ($response['stop_details']['category'] ?? '');
        throw new AppError('שירות הבינה סירב לנסח את זה' . ($cat !== '' ? " ($cat)" : '') . '. אפשר לנסח ידנית', 422);
    }
    if ($stop === 'max_tokens') throw new AppError('התשובה נקטעה — המתכון ארוך מדי לניסוח בבת אחת', 422);
    $text = '';
    foreach ((array) ($response['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') { $text = (string) ($block['text'] ?? ''); break; }
    }
    $parsed = json_decode($text, true);
    $sections = $parsed['sections'] ?? null;
    if (!is_array($sections)) throw new AppError('התשובה מהבינה לא בצורה הצפויה', 502);
    $out = [];
    foreach ($sections as $s) {
        if (!is_array($s)) continue;
        $steps = array_values(array_filter(array_map(fn($x) => trim((string) $x), (array) ($s['steps'] ?? [])), fn($x) => $x !== ''));
        if ($steps) $out[] = ['name' => trim((string) ($s['name'] ?? '')), 'steps' => $steps];
    }
    if (!$out) throw new AppError('הבינה החזירה שלבים ריקים', 502);
    return $out;
}

/** נרמול להשוואה: אותיות ומספרים בלבד, רווח אחד. */
function aiNormalize(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * דמיון למקור: שיעור השלבים החדשים שהם משפט מהמקור כלשונו (אחרי נרמול),
 * או שמכילים משפט מקורי שלם באורך של 8 מילים ומעלה. 0 = הכול חדש, 1 = הכול זהה.
 */
function aiSimilarity(array $original, array $rewritten): float {
    $sentences = [];
    foreach ($original as $s) foreach ($s['steps'] ?? [] as $st) {
        foreach (preg_split('/(?<=[.!?:;])\s+|\n+/u', (string) $st) ?: [] as $sent) {
            $n = aiNormalize($sent);
            if ($n !== '') $sentences[] = $n;
        }
    }
    $new = [];
    foreach ($rewritten as $s) foreach ($s['steps'] ?? [] as $st) {
        $n = aiNormalize((string) $st);
        if ($n !== '') $new[] = $n;
    }
    if (!$new || !$sentences) return 0.0;
    $hits = 0;
    foreach ($new as $n) {
        foreach ($sentences as $o) {
            if ($n === $o || (str_word_count_u($o) >= 8 && str_contains($n, $o))) { $hits++; break; }
        }
    }
    return round($hits / count($new), 3);
}

function str_word_count_u(string $s): int {
    return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

/**
 * השירות המלא: שלבים במבנה [{name, steps[]}] → אותם, מנוסחים מחדש, עם מדד
 * דמיון ושם המודל. $user ליומן בלבד.
 */
function aiRewriteSteps(array $sections, string $title, ?array $user = null): array {
    $apiKey = secretGet('anthropic_api_key');
    if ($apiKey === null) throw new AppError('ניסוח מחדש בבינה אינו מופעל — המפתח צריך להזין מפתח API בהגדרות הציבוריות', 409);
    if (!function_exists('curl_init')) throw new AppError('בשרת אין cURL', 500);

    $count = 0;
    $clean = [];
    foreach ($sections as $s) {
        $steps = array_values(array_filter(array_map(fn($x) => trim((string) (is_array($x) ? ($x['text'] ?? '') : $x)), (array) ($s['steps'] ?? [])), fn($x) => $x !== ''));
        $count += count($steps);
        if ($steps) $clean[] = ['name' => trim((string) ($s['name'] ?? '')), 'steps' => $steps];
    }
    if ($count === 0) throw new AppError('אין שלבים לנסח');
    if ($count > AI_MAX_STEPS) throw new AppError('יותר מדי שלבים לניסוח בבת אחת (עד ' . AI_MAX_STEPS . ')');

    $t0 = microtime(true);
    $response = aiCall(aiRequestBody($clean, $title), $apiKey);
    $result = aiParseSections($response);
    $sim = aiSimilarity($clean, $result);
    $attempts = 1;
    if ($sim > AI_SIMILARITY_MAX) {
        $response = aiCall(aiRequestBody($clean, $title, true), $apiKey);
        $result = aiParseSections($response);
        $sim = aiSimilarity($clean, $result);
        $attempts = 2;
    }
    $usage = $response['usage'] ?? [];
    logEvent('info', 'ai-rewrite', 'נוסח מחדש', [
        'steps' => $count, 'attempts' => $attempts, 'similarity' => $sim,
        'model' => (string) ($response['model'] ?? AI_MODEL),
        'in_tokens' => (int) ($usage['input_tokens'] ?? 0), 'out_tokens' => (int) ($usage['output_tokens'] ?? 0),
    ], $user, (int) round((microtime(true) - $t0) * 1000));

    return [
        'sections'   => $result,
        'similarity' => $sim,
        'too_close'  => $sim > AI_SIMILARITY_MAX,
        'model'      => (string) ($response['model'] ?? AI_MODEL),
        'attempts'   => $attempts,
    ];
}
