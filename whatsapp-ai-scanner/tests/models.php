<?php
/**
 * בדיקת שליפת רשימת המודלים — פענוח וסינון לכל ספק, בלי רשת אמיתית.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/ai.php';

$failed = 0;
function check(string $name, bool $cond): void {
    global $failed;
    echo ($cond ? "  ok  " : "  FAIL") . "  $name\n";
    if (!$cond) $failed++;
}
function ids(array $models): array { return array_map(fn($m) => $m['id'], $models); }

// Anthropic — נלקח כמו שהוא
$anthropic = function () {
    return ['status' => 200, 'body' => json_encode(['data' => [
        ['id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
        ['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5'],
    ]])];
};
$m = listProviderModels('anthropic', 'sk-ant-x', $anthropic);
check('Anthropic: שני מודלים', count($m) === 2);
check('Anthropic: label מה-display_name', $m[0]['label'] === 'Claude Opus 5');

// OpenAI — מסנן מה שאינו צ'אט
$openai = function () {
    return ['status' => 200, 'body' => json_encode(['data' => [
        ['id' => 'gpt-5'],
        ['id' => 'text-embedding-3-large'],
        ['id' => 'o3-mini'],
        ['id' => 'whisper-1'],
        ['id' => 'dall-e-3'],
    ]])];
};
$m = ids(listProviderModels('openai', 'sk-x', $openai));
check('OpenAI: שומר gpt ו-o3', in_array('gpt-5', $m) && in_array('o3-mini', $m));
check('OpenAI: מסנן embedding/whisper/dalle',
    !in_array('text-embedding-3-large', $m) && !in_array('whisper-1', $m) && !in_array('dall-e-3', $m));

// Google — רק מודלים שתומכים ב-generateContent, בלי הקידומת models/
$google = function () {
    return ['status' => 200, 'body' => json_encode(['models' => [
        ['name' => 'models/gemini-2.5-pro', 'displayName' => 'Gemini 2.5 Pro',
         'supportedGenerationMethods' => ['generateContent', 'countTokens']],
        ['name' => 'models/text-embedding-004',
         'supportedGenerationMethods' => ['embedContent']],
    ]])];
};
$m = listProviderModels('google', 'AIzaX', $google);
check('Google: רק המודל התומך ב-generateContent', count($m) === 1);
check('Google: הקידומת models/ הוסרה', $m[0]['id'] === 'gemini-2.5-pro');

// מפתח דחוי → הודעה ברורה
try {
    listProviderModels('anthropic', 'sk-ant-x', fn() => ['status' => 401, 'body' => '{}']);
    check('מפתח דחוי זורק', false);
} catch (AppError $e) {
    check('מפתח דחוי זורק הודעה מובנת', str_contains($e->getMessage(), 'נדחה'));
}

echo $failed === 0 ? "\nהכול עבר\n" : "\n$failed נכשלו\n";
exit($failed === 0 ? 0 : 1);
