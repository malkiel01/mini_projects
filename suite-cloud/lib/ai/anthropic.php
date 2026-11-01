<?php
/**
 * מתאם Anthropic (Claude) — למשימה complete בלבד: טקסט או JSON, עם images? לתיאור תמונה (docs/spec-3-ai.md, סעיף 3).
 * Claude לא מייצר תמונות ולא מסכות, ולכן אינו בארבע משימות התמונה.
 *
 * Messages API ב-HTTP ישיר, כמו שאר המתאמים (אין composer בפרויקט; כל קוד הספקים — כאן, דרך aiHttp).
 * - המודל: claude-opus-5-5 (העדכני), effort medium; המנהל יכול להחליף מודל בממשק.
 * - JSON: output_config.format = json_schema. סכמה שה-API לא מקבל (למשל בלי additionalProperties:false) → נסיון אחד
 *   בלי format, עם הסכמה בהנחיה; את התשובה מפענח aiParseJsonText.
 * - סירוב (stop_reason: refusal): fallbacks "default" בצד השרת, ובסירוב שנשאר — שגיאה בעברית.
 */

declare(strict_types=1);

const ANTHROPIC_API = 'https://api.anthropic.com/v1/';
const ANTHROPIC_VERSION = '2026-10-21';
/** מודלים שמקבלים fallbacks: "default" (server-side-fallback-2026-07-01). */
const ANTHROPIC_FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1'];
const ANTHROPIC_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

function anthropicHeaders(string $key, array $betas = []): array {
    $h = ["x-api-key: $key", 'anthropic-version: ' . ANTHROPIC_VERSION, 'Content-Type: application/json'];
    if ($betas) $h[] = 'anthropic-beta: ' . implode(',', $betas);
    return $h;
}

function aiRunAnthropic(string $task, array $in, string $key, string $model): array {
    if ($task !== 'complete') throw new AppError('Claude יודע רק טקסט ותוכנית', 400, 'bad_request');
    $content = [];
    foreach ($in['images'] ?? [] as $img) {
        $mime = (@getimagesizefromstring($img))['mime'] ?? '';
        if (!in_array($mime, ANTHROPIC_IMAGE_TYPES, true)) throw new AppError('Claude מקבל תמונות PNG, JPEG, GIF או WebP', 400, 'bad_request');
        $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($img)]];
    }
    $content[] = ['type' => 'text', 'text' => (string) ($in['prompt'] ?? '')];
    $body = ['model' => $model, 'max_tokens' => 16000, 'messages' => [['role' => 'user', 'content' => $content]],
             'output_config' => ['effort' => 'medium']];
    if (($in['system'] ?? '') !== '') $body['system'] = (string) $in['system'];
    $betas = [];
    if (in_array($model, ANTHROPIC_FALLBACK_MODELS, true)) {
        $body['fallbacks'] = 'default';
        $betas[] = 'server-side-fallback-2026-07-01';
    }
    $schema = $in['schema'] ?? null;
    if ($schema !== null) $body['output_config']['format'] = ['type' => 'json_schema', 'schema' => $schema];

    $res = aiHttp('POST', ANTHROPIC_API . 'messages', anthropicHeaders($key, $betas), json_encode($body, JSON_UNESCAPED_UNICODE), 240);
    if ($schema !== null && $res[0] === 400 && str_contains(strtolower($res[1]), 'schema')) {
        // הסכמה של האפליקציה לא עומדת בכללי structured outputs — אותה בקשה, והסכמה כהנחיה
        unset($body['output_config']['format']);
        $body['system'] = trim(($body['system'] ?? '') . "\n\nענה ב-JSON בלבד, בלי טקסט מסביב, לפי הסכמה הזו:\n"
            . json_encode($schema, JSON_UNESCAPED_UNICODE));
        $res = aiHttp('POST', ANTHROPIC_API . 'messages', anthropicHeaders($key, $betas), json_encode($body, JSON_UNESCAPED_UNICODE), 240);
    }
    $j = aiJson($res, 'Claude');
    if (($j['stop_reason'] ?? '') === 'refusal') {
        error_log('suite-cloud ai Claude refusal: ' . json_encode($j['stop_details'] ?? null));
        throw new AppError('Claude סירב לבקשה הזו', 502, 'provider');
    }
    $text = implode('', array_map(fn($b) => (string) $b['text'], array_filter($j['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text')));
    if (($j['stop_reason'] ?? '') === 'max_tokens' && $schema !== null) throw new AppError('התשובה של Claude נקטעה', 502, 'provider');
    return $schema !== null ? ['json' => aiParseJsonText($text)] : ['text' => $text];
}
