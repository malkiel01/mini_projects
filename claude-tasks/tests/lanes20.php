<?php
/**
 * שלב 20 (docs/spec.md §16) — מודל ורמת חשיבה: חיבור › צ'אט › הודעה, מעבר רק בתוך אותו ספק, ה-422,
 * בחירה שמורה שכבר לא תקפה, effortFor לכל ספק, כרטיס המכסה לפני הקריאה, מעבר אוטומטי במחיר המודל של הצ'אט,
 * מעקב שמתעלם מבחירות, ותשובה לשאלה שממשיכה במודל ששאל. ספק מדומה — בלי מפתחות אמיתיים.
 *
 *   php claude-tasks/tests/lanes20.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ctl20-' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
define('DB_FILE', $tmp . '/t.sqlite');
define('CONFIG_FILE', $tmp . '/c.json');
define('SECRET_KEY_FILE', $tmp . '/secret.key');
define('BELL_DIR', $tmp . '/bell');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/channels.php';
require_once __DIR__ . '/../lib/answers.php';
require_once __DIR__ . '/../lib/choices.php';

$pass = 0; $fail = 0;
function check(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE)
       . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function throws(string $name, callable $fn, $has = null) {
    try { $fn(); check($name, 'לא נזרקה חריגה', 'חריגה'); }
    catch (Throwable $e) {
        $ok = $has === null || (is_int($has) ? (($e->status ?? $e->getCode()) === $has) : str_contains($e->getMessage(), $has));
        check($name . ' — ' . $e->getMessage(), $ok, true);
    }
}
/** ספק מדומה: רושם את הבקשות (ספק, גוף, מפתח). ‏$fail — מפתחות שנכשלים ב-529 (עומס, עוברים לבא). */
function fake(string $text, ?array &$seen = null, array $fail = []): callable {
    return function ($prov, $url, $json, $key) use ($text, &$seen, $fail) {
        $seen[] = ['provider' => $prov, 'body' => json_decode($json, true), 'key' => $key];
        if (in_array($key, $fail, true)) return ['status' => 529, 'body' => json_encode(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']])];
        return ['status' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => $text]],
                                                        'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]])];
    };
}
$GLOBALS['CT_FAKE_NOW'] = 1_800_000_000;
$col = fn (string $sql) => db()->query($sql)->fetchColumn();

createUser('boss', 'pass-12345', 'בעל הלוח');
$boss = ['id' => 1, 'role' => 'admin', 'username' => 'boss', 'display_name' => 'בעל הלוח'];
$root = createProject($boss, 'mini_projects', 'malkiel01', 'mini_projects');
$k1 = 'sk-ant-firstfirstfirstfirst1';
$k2 = 'sk-ant-secondsecondsecond22';
$c1 = addConnection($boss, 'anthropic', $k1, 'אישי', 'claude-sonnet-5-5');
$c2 = addConnection($boss, 'anthropic', $k2, 'גיבוי', 'claude-haiku-5-5');
setQuota($boss, 1, 25);
db()->exec('UPDATE users SET auto_ceiling_usd = 10 WHERE id = 1');
$regToken = bin2hex(random_bytes(16));
db()->prepare('UPDATE projects SET agent_token = ? WHERE id = ?')->execute([encryptSecret($regToken), $root]);
$_SERVER['HTTP_X_WORKER_TOKEN'] = $regToken;
$cid = (int) registerChannel(workerScope(), $root, 'מודלים', 'קוד', 'session_L20_1', '')['channel_id'];
db()->prepare("UPDATE projects SET route = 'platform' WHERE id = ?")->execute([$root]);

echo "\n— effortFor: כל ספק לפי התיעוד —\n";
check('Anthropic, מודל חדש: חמש רמות', effortLevels('anthropic', 'claude-opus-5-5'), EFFORT_LEVELS);
check('Anthropic "גבוהה" → output_config.effort high', effortFor('anthropic', 'claude-opus-5-5', 'high'), [['output_config' => ['effort' => 'high']], 8192]);
check('Anthropic "אוטומטי" → low (כמו היום)', effortFor('anthropic', 'claude-sonnet-5-5', 'auto')[0], ['output_config' => ['effort' => 'low']]);
check('Opus 4.5 "מרבית" → high (אין לו max)', effortFor('anthropic', 'claude-opus-4-5-20251101', 'max')[0], ['output_config' => ['effort' => 'high']]);
check('Haiku 4.5 — רק "אוטומטי"', effortLevels('anthropic', 'claude-haiku-4-5-20251001'), ['auto']);
check('Haiku 4.5 "גבוהה" לא נשלחת — כמו אוטומטי', effortFor('anthropic', 'claude-haiku-4-5-20251001', 'high'), [[], 2048]);
check('OpenAI gpt-5 "בינונית" → reasoning_effort', effortFor('openai', 'gpt-5', 'medium')[0], ['reasoning_effort' => 'medium']);
check('OpenAI gpt-4o — רק "אוטומטי"', effortLevels('openai', 'gpt-4o'), ['auto']);
check('Gemini 2.5 "נמוכה" → thinkingBudget', effortFor('google', 'gemini-2.5-flash', 'low')[0]['generationConfig']['thinkingConfig'], ['thinkingBudget' => 1024]);
[, , $gb] = providerRequest('google', 'gemini-2.5-pro', 's', 'p', 400, 'high');
check('Gemini — הגוף כולל thinkingConfig ו-maxOutputTokens', [isset($gb['generationConfig']['thinkingConfig']), $gb['generationConfig']['maxOutputTokens'] >= 16384], [true, true]);
[, , $ab] = providerRequest('anthropic', 'claude-opus-5-5', 's', 'p', 400, 'max');
check('max_tokens גדל עם הרמה', [$ab['max_tokens'], $ab['output_config']['effort']], [16000, 'max']);

echo "\n— ההכרעה: הודעה › צ'אט › חיבור —\n";
$ch = getChannel($cid);
$r = resolveAi($boss, $ch);
check('בלי בחירה — החיבור הראשון, במודל וברמה שלו', [$r['model'], $r['effort'], $r['source'], count($r['chain'])], ['claude-sonnet-5-5', 'auto', 'connection', 2]);
setConnectionEffort($boss, $c1, 'medium');
check('רמת ברירת מחדל לחיבור', [resolveAi($boss, $ch)['effort'], userConnections(1)[0]['effort_label'] ?? null], ['medium', 'בינונית']);
throws('רמה שהמודל לא תומך בה — 422', fn () => setConnectionEffort($boss, $c2, 'zzz'), 422);
setChannelAiPref($boss, $cid, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'high']);
$ch = getChannel($cid);
$r = resolveAi($boss, $ch);
check('בחירה לצ\'אט גוברת על החיבור', [$r['model'], $r['effort'], $r['source']], ['claude-opus-5-5', 'high', 'channel']);
check('השרשרת — שני החיבורים, שניהם במודל שנבחר', array_map(fn ($c) => [$c['model'], $c['effort']], $r['chain']), [['claude-opus-5-5', 'high'], ['claude-opus-5-5', 'high']]);
$msg = ['id' => 0, 'kind' => 'text', 'ai_pref' => json_encode(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'effort' => 'low'])];
check('בחירה להודעה גוברת על הצ\'אט', array_intersect_key(resolveAi($boss, $ch, $msg), array_flip(['model', 'effort', 'source'])),
      ['model' => 'claude-haiku-5-5', 'effort' => 'low', 'source' => 'message']);

echo "\n— ה-422 —\n";
throws('ספק שאין לו חיבור — 422', fn () => setChannelAiPref($boss, $cid, ['provider' => 'openai', 'model' => 'gpt-5', 'effort' => 'auto']), 422);
throws('מודל שלא ברשימה — 422', fn () => setChannelAiPref($boss, $cid, ['provider' => 'anthropic', 'model' => 'claude-nope-9', 'effort' => 'auto']), 422);
throws('רמה שהמודל לא תומך בה — 422', fn () => setChannelAiPref($boss, $cid, ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001', 'effort' => 'high']), 422);
throws('בחירה להודעה, לא תקפה — 422 לפני שנשמרת', fn () => channelSend($boss, $cid, 'x', null, null, ['provider' => 'anthropic', 'model' => 'gpt-5']), 422);
$member = ['id' => 2, 'role' => 'user', 'username' => 'm', 'display_name' => 'חבר'];
throws('מודל לשיחה — בעל הלוח בלבד', fn () => setChannelAiPref($member, $cid, null), 403);

echo "\n— בחירה שמורה שכבר לא תקפה —\n";
db()->prepare('UPDATE channels SET ai_pref = ? WHERE id = ?')->execute([json_encode(['provider' => 'anthropic', 'model' => 'claude-retired-1', 'effort' => 'auto']), $cid]);
$info = channelAiInfo(getChannel($cid));
check('יורדת לברירת המחדל, עם הודעה', [$info['model'], $info['source'], $info['notice']], ['claude-sonnet-5-5', 'connection', 'המודל שנבחר כבר לא זמין — חזר לברירת המחדל']);
setChannelAiPref($boss, $cid, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'high']);

echo "\n— platform-reply: ai_used, הודעה אחת, מעבר בתוך הספק —\n";
$seen = [];
$m1 = channelSend($boss, $cid, 'שאלה קשה', null, null, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'max']);
$r1 = platformReply($boss, $m1, fake('{"reply":"תשובה עמוקה"}', $seen));
check('הקריאה — במודל וברמה של ההודעה', [$seen[0]['body']['model'], $seen[0]['body']['output_config']['effort'], $seen[0]['body']['max_tokens']], ['claude-opus-5-5', 'max', 16000]);
$u = $r1['message']['ai_used'];
check('ai_used על התשובה', [$u['model'], $u['effort'], $u['source'], $u['tokens_in'], $u['tokens_out'], $u['cost_usd'] > 0, $u['connection_id']],
      ['claude-opus-5-5', 'max', 'message', 1000, 200, true, $c1]);
check('הבחירה של ההודעה — לא נשמרה לצ\'אט', channelAiInfo(getChannel($cid))['effort'], 'high');
$seen = [];
$m2 = channelSend($boss, $cid, 'עוד אחת');
$r2 = platformReply($boss, $m2, fake('{"reply":"כן"}', $seen, [$k1]));
check('החיבור הראשון עמוס — עובר לשני, באותו ספק ובאותו מודל', [count($seen), $seen[1]['key'], $seen[1]['body']['model'], $seen[1]['provider']], [2, $k2, 'claude-opus-5-5', 'anthropic']);
check('ai_used — מהחיבור שענה, עם "עבר מ…"', [$r2['message']['ai_used']['connection_id'], str_contains((string) $r2['message']['ai_used']['fell_back_from'], 'אישי')], [$c2, true]);
check('השימוש נרשם לפי מודל', array_column(usageSummary(1)['by_model'], 'model'), ['claude-opus-5-5']);

echo "\n— אין חיבור לאותו ספק — מסירה עם סיבה, לא מעבר שקט —\n";
addConnection($boss, 'openai', 'sk-openaiopenaiopenaiopenai1', 'OpenAI', 'gpt-5');
setChannelAiPref($boss, $cid, ['provider' => 'openai', 'model' => 'gpt-5', 'effort' => 'medium']);
db()->exec("UPDATE connections SET secret = '' WHERE kind = 'openai'");
$m3 = channelSend($boss, $cid, 'שאלה ל-GPT');
$seen = [];
$r3 = platformReply($boss, $m3, fake('x', $seen));
check('לא נקרא אף ספק אחר', count($seen), 0);
check('נמסרה לצ\'אט, והסיבה בשם המודל', [$r3['answered'], str_contains((string) ($r3['reason'] ?? ''), 'gpt-5')], [false, true]);
setChannelAiPref($boss, $cid, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'high']);

echo "\n— 8b: תשובה לשאלה של Platform ממשיכה במודל ששאל —\n";
$m4 = channelSend($boss, $cid, 'תשאל אותי', null, null, ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'effort' => 'low']);
$r4 = platformReply($boss, $m4, fake('{"reply":"","ask":{"questions":[{"header":"צבע","question":"איזה צבע?","multiSelect":false,"options":[{"label":"כחול","description":""},{"label":"אדום","description":""}]}]}}'));
$choice = (int) $col("SELECT id FROM messages WHERE kind = 'choice' AND reply_to = $m4");
check('השאלה נשמרה עם ai_used', json_decode((string) $col("SELECT ai_used FROM messages WHERE id = $choice"), true)['model'] ?? null, 'claude-haiku-5-5');
$ans = ['id' => 0, 'kind' => 'choice_answer', 'reply_to' => $choice, 'ai_pref' => null];
check('choice_answer — באותו מודל וברמה', array_intersect_key(resolveAi($boss, getChannel($cid), $ans), array_flip(['model', 'effort', 'source'])),
      ['model' => 'claude-haiku-5-5', 'effort' => 'low', 'source' => 'choice']);

echo "\n— מעקב: תמיד ברירת המחדל של החיבור —\n";
$mon = userConn($boss);
check('userConn (מעקב/קטלוג) — המודל של חיבור (לא הבחירה של הצ\'אט), בלי רמה', [in_array($mon['model'], ['claude-sonnet-5-5', 'claude-haiku-5-5'], true), $mon['effort'] ?? 'auto'], [true, 'auto']);

echo "\n— כרטיס המכסה לפני הקריאה —\n";
setQuota($boss, 1, (float) usageSummary(1)['used'] + 0.05);
$seen = [];
$m5 = channelSend($boss, $cid, 'שאלה יקרה', null, null, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'max']);
$r5 = platformReply($boss, $m5, fake('x', $seen));
check('ההערכה עוברת את מה שנשאר — לא נקרא הספק', [count($seen), $r5['preflight'] ?? false], [0, true]);
$card = db()->query("SELECT * FROM messages WHERE id = " . (int) $r5['card_id'])->fetch();
$cm = json_decode((string) $card['meta'], true);
check('הכרטיס: ai_card, עם המודל, ההערכה ומה שנשאר', [$card['kind'], $card['status'], $cm['model'], $cm['estimate'] > $cm['left']], ['ai_card', 'open', 'claude-opus-5-5', true]);
check('ההודעה ממתינה להחלטה — לא בתור של Platform ולא אצל הצ\'אט', [$col("SELECT answered_by FROM messages WHERE id = $m5"), count(array_filter(channelInbox(getChannel($cid)), fn ($x) => (int) $x['id'] === $m5))], ['platform-hold', 0]);
$view = channelMessages($boss, $cid);
$pending = array_values(array_filter($view['messages'], fn ($x) => (int) $x['id'] === $m5))[0];
check('בלוח — לא "ממתינה ל-Platform"', $pending['platform_pending'], false);
check('קריאה חוזרת — אותו כרטיס, בלי ספק', [platformReply($boss, $m5, fake('x', $seen))['card_id'], count($seen)], [(int) $r5['card_id'], 0]);
throws('כרטיס — בעל הלוח בלבד', fn () => aiCardAnswer($member, (int) $r5['card_id'], 'send'), 403);
$ans = aiCardAnswer($boss, (int) $r5['card_id'], 'cancel');
check('בטל — ההודעה לא נשלחת, הכרטיס נסגר', [$ans['run'], $col('SELECT status FROM messages WHERE id = ' . (int) $r5['card_id']), $col("SELECT delivered_at IS NOT NULL FROM messages WHERE id = $m5")], [[], 'answered', 1]);
throws('כרטיס שנסגר — 409', fn () => aiCardAnswer($boss, (int) $r5['card_id'], 'send'), 409);
// שלח במודל של הצ'אט: הצ'אט על Haiku (זול) — ההודעה ביקשה Opus max
setChannelAiPref($boss, $cid, ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'effort' => 'auto']);
$m6 = channelSend($boss, $cid, 'שוב יקרה', null, null, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'max']);
$r6 = platformReply($boss, $m6, fake('x', $seen));
$cm6 = json_decode((string) $col('SELECT meta FROM messages WHERE id = ' . (int) $r6['card_id']), true);
check('הכרטיס מציע את המודל של הצ\'אט', $cm6['alt']['model'] ?? null, 'claude-haiku-5-5');
$ans = aiCardAnswer($boss, (int) $r6['card_id'], 'send');
check('שלח — ההודעה חוזרת לתור, במודל של הצ\'אט', [$ans['run'], $col("SELECT answered_by FROM messages WHERE id = $m6"), json_decode((string) $col("SELECT ai_pref FROM messages WHERE id = $m6"), true)['model']],
      [[$m6], 'platform', 'claude-haiku-5-5']);
$seen = [];
setQuota($boss, 1, 25);
$r6b = platformReply($boss, $m6, fake('{"reply":"זול"}', $seen));
check('…ונענית ב-Haiku', [$seen[0]['body']['model'] ?? null, $r6b['message']['ai_used']['model']], ['claude-haiku-5-5', 'claude-haiku-5-5']);

echo "\n— מעבר אוטומטי במחיר המודל של הצ'אט —\n";
$cheap = platformCanTakeOver(1, $root, true, getChannel($cid));
setChannelAiPref($boss, $cid, ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'max']);
db()->exec('UPDATE users SET auto_ceiling_usd = 0.06 WHERE id = 1');
db()->exec("DELETE FROM usage WHERE kind <> 'answer' OR 1");
$exp = platformCanTakeOver(1, $root, true, getChannel($cid));
$noCh = platformCanTakeOver(1, $root, true);
check('תקרה 0.06: הצ\'אט על Opus max — כרטיס; בלי מודל לצ\'אט — עובר', [$exp['ok'], !empty($exp['ceiling']), $noCh['ok']], [false, true, true]);
check('עם Haiku — עובר', $cheap['ok'], true);

echo "\n— מטלה מתוך ערוץ — המודל של הצ'אט —\n";
check('ai_pref של הצ\'אט נשמר כ-JSON', json_decode((string) $col("SELECT ai_pref FROM channels WHERE id = $cid"), true)['model'], 'claude-opus-5-5');

echo "\n— הבועה: ai_used ו-ai_pref יוצאים מפוענחים —\n";
$v = channelMessages($boss, $cid);
$withPref = array_values(array_filter($v['messages'], fn ($x) => (int) $x['id'] === $m1))[0];
check('ai_pref על ההודעה של בעל הלוח', $withPref['ai_pref']['effort'] ?? null, 'max');
check('הערוץ מחזיר ai (שבב)', [$v['channel']['ai']['model'] ?? null, $v['channel']['ai']['source'] ?? null], ['claude-opus-5-5', 'channel']);
check('modelShortName', [modelShortName('claude-opus-5-5'), modelShortName('claude-haiku-4-5-20251001'), modelShortName('gpt-5')], ['Opus 5.5', 'Haiku 4.5', 'gpt-5']);

echo "\n  ════ עברו: $pass · נכשלו: $fail ════\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
