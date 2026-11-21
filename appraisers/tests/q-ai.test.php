<?php
// פרוסה 11 — בינה: ספקים, שני מסלולים, מכסות ושימוש (docs/spec/11-ai-core.md §13): 11-A1 … 11-E3, מקרי הקצה של §12, ההרשאות, הבידוד, הכספת והמיגרציה.
// **שום בדיקה אינה יוצאת לרשת:** מתאם מדומה מוזרק ב-$GLOBALS['apr_ai_adapters']; המפתחות כאן הם מחרוזות בדיקה, לא מפתחות אמיתיים.
$child = in_array($argv[1] ?? '', ['racer', 'dier'], true);
// התיקייה אינה מתחילה ב-apr-test- — כך הניקוי האוטומטי של תהליך-בן אינו מוחק את המסד של ההורה; ההורה מנקה בסוף
$dir = $child ? $argv[2] : sys_get_temp_dir() . '/apr-ai-' . bin2hex(random_bytes(5));
if (!$child) mkdir($dir, 0700, true);
define('APR_DATA_DIR', $dir);
define('APR_DB_PATH', $dir . '/app.sqlite');
require __DIR__ . '/lib.php';

const K_MANAGED = 'sk-test-MANAGED-key-0000-1111-aaaa-Zq7M';
const K_PERSONAL = 'sk-test-PERSONAL-key-2222-3333-bbbb-Xw9P';
const K_REPLACED = 'sk-test-REPLACED-key-4444-5555-cccc-Yt3R';
const SECRET_TEXT = 'תוכן-סודי-של-תיק-7f3a91';        // מה שנשלח למודל — אסור שיישמר בשום מקום
const SECRET_REPLY = 'תשובת-המודל-הסודית-c44e02';    // מה שהמודל החזיר — כנ"ל

/** המתאם המדומה: רושם כל קריאה (ובאיזה מפתח), ומחזיר מה שנקבע לו. */
final class FakeAi implements AprAiAdapter
{
    public array $calls = [];
    public array $health = [];
    public mixed $next = null;          // fn(string $key, array $request): array — או זורק
    public mixed $healthNext = null;
    public ?string $log = null;         // קובץ שנכתבת אליו שורה לכל קריאה (למרוץ בין תהליכים)

    public function complete(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $request): array
    {
        $this->calls[] = ['key' => $key, 'request' => $request];
        if ($this->log !== null) file_put_contents($this->log, "x\n", FILE_APPEND | LOCK_EX);
        return $this->next ? ($this->next)($key, $request) : ['text' => SECRET_REPLY, 'in_tokens' => 120, 'out_tokens' => 30, 'stop_reason' => 'end_turn'];
    }

    public function count_tokens(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $request): int
    {
        return 1;
    }

    public function health(#[\SensitiveParameter] string $key): string
    {
        $this->health[] = $key;
        return $this->healthNext ? ($this->healthNext)($key) : 'ok';
    }

    public function capabilities(string $model): array
    {
        return ['text' => true, 'vision' => true, 'pdf' => true];
    }
}
$fake = new FakeAi();
$GLOBALS['apr_ai_adapters'] = ['anthropic' => $fake];

function ctx_of(string $as): array
{
    call('GET', 'me', [], $as);
    return apr_ctx(['platform_user' => $as, 'cookie' => jar()[$as] ?? '', 'ua' => 'test']);
}
/** ai_run בשם משתמש, עם ברירות מחדל; כל קריאה במזהה פעולה חדש אלא אם נקבע. */
function run(string $as, array $over = []): array
{
    static $n = 0;
    return ai_run($over + ['ctx' => ctx_of($as), 'purpose' => 'test.run', 'content' => [['type' => 'text', 'text' => SECRET_TEXT]], 'max_output' => 100, 'op' => 'op-' . getmypid() . '-' . (++$n)]);
}

if ($child) {
    $as = ident($argv[3], 'בן', 'u' . $argv[3] . '@example.com');
    if ($argv[1] === 'racer') {
        $fake->log = $dir . '/adapter-calls.log';
        $ctx = ctx_of($as);
        while (microtime(true) < (float)$argv[4]) usleep(200);
        $r = ai_run(['ctx' => $ctx, 'purpose' => 'test.race', 'content' => [['type' => 'text', 'text' => SECRET_TEXT]], 'max_output' => 100, 'op' => 'race-' . getmypid() . '-x']);
        echo $r['status'] . ':' . ($r['block_reason'] ?? '');
    } else {
        // תהליך שמת באמצע הקריאה לספק — השריון נשאר פתוח
        $fake->next = function () { echo 'died'; exit(0); };
        run($as);
    }
    exit(0);
}

$warnings = [];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings) { if (!(error_reporting() & $no)) return true; $warnings[] = "$msg @" . basename($file) . ":$line"; return true; });
$GLOBALS['apr_time'] = 1794400000;      // שעון קפוא: 2026-11-11

$seen = [];                              // כל תשובת API שהתקבלה לאורך הבדיקות — נסרקת בסוף אחר המפתחות (11-C1)
function api(string $method, string $action, array $in = [], ?string $as = null, array $get = []): array
{
    $r = call($method, $action, $in + ($method === 'POST' ? ['confirm' => 'אישור'] : []), $as, $get);
    $GLOBALS['seen'][] = json_encode($r, JSON_UNESCAPED_UNICODE);
    return $r;
}
$db = fn(string $sql, array $a = []) => apr_val($sql, $a);
$calls = fn() => count($fake->calls);
$keysUsed = fn() => array_values(array_unique(array_column($fake->calls, 'key')));

$owner = ident('1', 'הבעלים', 'owner@example.com', ['platform_admin' => true]);
$avi = ident('2', 'אבי', 'avi@example.com');       // מנהל משרד א, שמאי — האחראי על התיק
$dana = ident('3', 'דנה', 'dana@example.com');     // שמאית במשרד א, לא מנהלת
$boaz = ident('4', 'בועז', 'boaz@example.com');    // מנהל משרד ב
call('GET', 'me', [], $owner);
$A = office_for($avi, 'משרד א');
$B = office_for($boaz, 'משרד ב');
$danaPid = join_office($avi, $dana, 'dana@example.com', 'appraiser');
$aviPid = call('GET', 'me', [], $avi)[1]['user']['pid'];
$boazPid = call('GET', 'me', [], $boaz)[1]['user']['pid'];

function make_case(string $as): string
{
    $p = call('POST', 'profile-create', ['scope' => 'office', 'name' => 'פרופיל'], $as)[1]['profile'];
    [, $d] = call('POST', 'profile-draft-open', ['profile' => $p], $as);
    $s = call('POST', 'profile-draft-save', ['profile' => $p, 'content' => ['flags' => [], 'requirements' => ['groups' => [], 'items' => []], 'tasks' => ['items' => []], 'rules' => []], 'revision' => $d['draft']['revision']], $as);
    call('POST', 'profile-publish', ['profile' => $p, 'note' => '', 'revision' => $s[1]['revision']], $as);
    $c = call('POST', 'case-create', ['profile' => $p], $as)[1]['case'];
    call('POST', 'case-update', ['case' => $c, 'title' => 'דירה', 'purpose' => 'purchase'], $as);
    call('POST', 'case-identifier-add', ['case' => $c, 'kind' => 'parcel', 'gush' => '1', 'helka' => '2'], $as);
    call('POST', 'case-party-add', ['case' => $c, 'name' => 'לקוח', 'kind' => 'client', 'is_orderer' => true], $as);
    $r = call('POST', 'case-start', ['case' => $c], $as);
    if ($r[0] !== 200) throw new RuntimeException('case-start: ' . json_encode($r[1], JSON_UNESCAPED_UNICODE));
    return $c;
}
$C = make_case($avi);
$CB = make_case($boaz);

// ═══ הרשאות, CSRF ואישור חוזר ══════════════════════════════════
$sysPosts = ['sys-ai-provider-save', 'sys-ai-model-save', 'sys-ai-fx-set', 'sys-ai-connection-save', 'sys-ai-connection-test', 'sys-ai-connection-revoke', 'sys-ai-assign-save', 'sys-ai-assign-delete', 'sys-ai-quota-save', 'sys-ai-quota-delete'];
$officePosts = ['office-ai-policy', 'office-ai-connection-save', 'office-ai-connection-test', 'office-ai-connection-revoke', 'office-ai-quota-save', 'office-ai-quota-delete'];
eq(array_values(array_unique(array_merge([code(api('GET', 'sys-ai', [], $avi))], array_map(fn($a) => code(api('POST', $a, [], $avi)), $sysPosts)))), ['not_found'],
    'מי שאינו מנהל המערכת: כל פעולת sys-ai-… עונה 404 — כמו פעולה שלא קיימת');
eq(array_values(array_unique(array_merge([code(api('GET', 'office-ai', [], $dana))], array_map(fn($a) => code(api('POST', $a, [], $dana)), $officePosts)))), ['forbidden'],
    'חבר משרד שאינו מנהל: כל פעולת office-ai-… נדחית (403)');
eq([code(call('POST', 'sys-ai-fx-set', ['rate' => '3.7'], $owner, [], ['csrf' => 'x'])), code(call('POST', 'office-ai-policy', ['enabled' => false], $avi, [], ['csrf' => ''])), code(call('POST', 'case-ai-set', ['case' => $C, 'no_ai' => true], $avi, [], ['csrf' => 'zz']))],
    ['csrf', 'csrf', 'csrf'], 'בלי X-CSRF נכון — 403, גם למנהל המערכת');
eq([code(call('POST', 'sys-ai-connection-save', ['provider' => 'anthropic', 'label' => 'x', 'key' => K_MANAGED], $owner)), code(call('POST', 'office-ai-connection-save', ['provider' => 'anthropic', 'label' => 'x', 'key' => K_PERSONAL], $boaz)),
    code(call('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 1]], $avi)), (int)$db('SELECT COUNT(*) FROM ai_connections'), (int)$db('SELECT COUNT(*) FROM ai_policies')],
    ['confirm_required', 'confirm_required', 'confirm_required', 0, 0], '§7, §8 שמירת מפתח והדלקת הבינה הן פעולות רגישות: בלי אישור חוזר — 403, ושום דבר לא נשמר');
eq([in_array('sys-ai', APR_READS, true), array_values(array_filter(array_keys(APR_ACCESS), fn($a) => str_contains($a, 'ai') && in_array(APR_ACCESS[$a], ['public', 'token'], true) && preg_match('/(^|-)ai(-|$)/', $a)))], [true, []],
    'אין פעולת בינה ציבורית');

// ═══ הקטלוג, המחירון והשער ════════════════════════════════════
[, $sys] = api('GET', 'sys-ai', [], $owner);
eq([count($sys['providers']), array_column($sys['models'], 'priced'), array_column($sys['models'], 'price_in'), $sys['fx'], $sys['connections']], [1, [false, false, false], [null, null, null], null, []],
    '§9 הקטלוג נזרע עם ספק אחד ושלושה מודלים, **בלי מחירים**; אין שער ואין חיבורים');
$model = fn(array $more = []) => $more + ['provider' => 'anthropic', 'model_id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5', 'capabilities' => ['vision' => true, 'pdf' => true, 'json_output' => true],
    'context_tokens' => 1000000, 'max_output_tokens' => 128000, 'price_in' => '4', 'price_out' => '20', 'price_checked_at' => '2026-11-10'];
eq([code(api('POST', 'sys-ai-model-save', $model(['price_out' => null]), $owner)), code(api('POST', 'sys-ai-model-save', $model(['price_checked_at' => null]), $owner)), code(api('POST', 'sys-ai-model-save', $model(['price_in' => '4.0000001']), $owner)),
    code(api('POST', 'sys-ai-model-save', $model(['price_in' => 4.5]), $owner)), code(api('POST', 'sys-ai-model-save', $model(['price_in' => '-1']), $owner)), code(api('POST', 'sys-ai-model-save', $model(['provider' => 'openai']), $owner)),
    code(api('POST', 'sys-ai-fx-set', ['rate' => '0'], $owner)), code(api('POST', 'sys-ai-fx-set', ['rate' => 'abc'], $owner)), code(api('POST', 'sys-ai-fx-set', ['rate' => 3.7], $owner))],
    ['ai_price', 'ai_price', 'ai_number', 'ai_number', 'ai_number', 'not_found', 'ai_number', 'ai_number', 'ai_number'],
    'מחירון: מחיר קלט בלי מחיר פלט או בלי תאריך בדיקה נדחה; מספר עשרוני (float), שלילי או מדויק מדי נדחה; ספק שאינו בקטלוג — 404; שער 0 או לא מספר נדחה');
eq([apr_ai_dec('4', 6, 3), apr_ai_dec('0.1', 6, 3), apr_ai_dec_out(4000000, 6), apr_ai_dec_out(100000, 6), apr_ai_dec_out(3700000, 6), apr_ai_cost(1000000, 0, 4000000, 20000000, 3700000), apr_ai_cost(0, 1000000, 4000000, 20000000, 3700000),
    apr_ai_cost(1, 0, 4000000, 20000000, 3700000), apr_ai_cost(120, 30, 4000000, 20000000, 3700000), apr_ai_cost(5, 5, null, 20000000, 3700000), apr_ai_cost(5, 5, 1, 1, null), apr_ai_cost(4000000, 200000, 1000000000, 1000000000, 1000000000) > 0],
    [4000000, 100000, '4', '0.1', '3.7', 1480, 7400, 1, 1, null, null, true],
    'כסף בלי נקודה צפה: 1M טוקני קלט ב-4$ ובשער 3.7 = 14.80 ₪ (1480 אג׳); פלט ב-20$ = 74 ₪; כל שבר מעוגל כלפי מעלה; בלי מחיר או בלי שער — null; הקצה העליון אינו גולש');

// ═══ הכספת (§7) ═══════════════════════════════════════════════
eq([code(api('POST', 'sys-ai-connection-save', ['provider' => 'anthropic', 'label' => 'ראשי'], $owner)), code(api('POST', 'sys-ai-connection-save', ['provider' => 'anthropic', 'label' => 'ראשי', 'key' => 'short'], $owner)),
    code(api('POST', 'sys-ai-connection-save', ['provider' => 'anthropic', 'label' => 'ראשי', 'key' => "sk-test with space and more chars"], $owner)), code(api('POST', 'sys-ai-connection-save', ['provider' => 'nope', 'label' => 'x', 'key' => K_MANAGED], $owner))],
    ['ai_key_invalid', 'ai_key_invalid', 'ai_key_invalid', 'not_found'], 'חיבור חדש בלי מפתח, עם מפתח קצר או עם רווח — נדחה; ספק לא מוכר — 404');
[$st, $saved] = api('POST', 'sys-ai-connection-save', ['provider' => 'anthropic', 'label' => 'ראשי', 'key' => K_MANAGED], $owner);
$MC = $saved['connection']['pid'];
eq([$st, $saved['connection']['key_hint'], $saved['connection']['status'], $saved['connection']['track'], array_key_exists('key_enc', $saved['connection']), array_key_exists('key', $saved['connection'])],
    [200, substr(K_MANAGED, -4), 'active', 'managed', false, false], '11-F2 (חלק השרת) אחרי שמירה חוזרים רק 4 התווים האחרונים — אין שדה מפתח בתשובה');
$row = apr_row('SELECT * FROM ai_connections WHERE pid = ?', [$MC]);
$realSecret = apr_secret();
$GLOBALS['apr_secret'] = random_bytes(32);
$wrong = apr_ai_key_open($row);
$GLOBALS['apr_secret'] = $realSecret;
$other = ['pid' => 'aaaaaaaaaaaaaaaa'] + $row;
eq([str_contains((string)$row['key_enc'], K_MANAGED), str_contains((string)$row['key_enc'], substr(K_MANAGED, 8, 12)), str_contains(base64_decode(base64_encode((string)$row['key_enc'])), 'sk-test'), apr_ai_key_open($row), $wrong, apr_ai_key_open($other),
    apr_ai_key_open(['key_enc' => substr((string)$row['key_enc'], 0, -1) . 'X'] + $row), strlen((string)$row['key_enc']) > strlen(K_MANAGED) + 24],
    [false, false, false, K_MANAGED, null, null, null, true],
    '11-C2 המפתח במסד אינו בטקסט גלוי; נפתח רק עם secret.key הנכון; תיבה של חיבור אחד אינה נפתחת כחיבור אחר; תיבה שנפגמה אינה נפתחת');
eq([code(api('POST', 'sys-ai-connection-save', ['connection' => $MC, 'label' => 'ראשי'], $avi)), code(api('POST', 'office-ai-connection-save', ['connection' => $MC, 'label' => 'שלי', 'key' => K_REPLACED], $avi)),
    code(api('POST', 'office-ai-connection-test', ['connection' => $MC], $avi)), code(api('POST', 'office-ai-connection-revoke', ['connection' => $MC], $avi)), apr_ai_key_open(apr_row('SELECT * FROM ai_connections WHERE pid = ?', [$MC]))],
    ['not_found', 'not_found', 'not_found', 'not_found', K_MANAGED], 'מנהל משרד אינו נוגע בחיבור של המערכת — 404 בכל פעולה, והמפתח לא הוחלף');

// ═══ השער: מדיניות, מסלול, מודל ומכסה — לפי הסדר (§5) ═════════════
$r = run($avi, ['case' => $C]);
eq([$r['status'], $r['block_reason'], $r['text'], $calls(), (int)$db("SELECT COUNT(*) FROM ai_runs WHERE status = 'blocked' AND idempotency_key IS NULL")], ['blocked', 'policy', null, 0, 1],
    '11-D1 משרד חדש: ai_run ← blocked: policy, בלי קריאה למתאם; החסימה נרשמת');
[, $oa] = api('GET', 'office-ai', [], $avi);
eq([$oa['policy']['enabled'], $oa['policy']['on'], $oa['policy_text']['version'], in_array('no_redaction', $oa['policy_text']['statements'], true), $oa['policy_text']['providers'][0]['key'], $oa['connection'], $oa['preview']['reason']],
    [false, false, APR_AI_POLICY_VERSION, true, 'anthropic', null, 'policy'], '11-F4 (חלק השרת) מסך ההדלקה מקבל את נוסח המדיניות — כולל המשפט על אי-הסרת פרטים מזהים — ואת הספק');
eq([code(api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 99, 'providers' => ['anthropic' => 1]], $avi)), code(api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => []], $avi)),
    code(api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 2]], $avi)), (int)$db('SELECT COUNT(*) FROM ai_policies WHERE enabled = 1')],
    ['ai_policy_changed', 'ai_policy_changed', 'ai_policy_changed', 0], '§8 אישור של נוסח או של ספק שאינם אלה שמוצגים עכשיו — 409, והבינה נשארת כבויה');
eq([api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 1]], $avi)[0], (int)$db("SELECT COUNT(*) FROM audit_log WHERE action = 'ai.policy.approve'"),
    (int)$db("SELECT json_extract(meta, '$.text_version') FROM audit_log WHERE action = 'ai.policy.approve'"), (int)$db("SELECT COUNT(*) FROM audit_log WHERE action = 'confirm.ok' AND entity_pid = 'office-ai-policy'") >= 1], [200, 1, 1, true],
    '§8 האישור נרשם ביומן עם גרסת הנוסח, לצד האישור החוזר');
$r = run($avi, ['case' => $C]);
eq([$r['status'], $r['block_reason'], $r['track'], $calls()], ['blocked', 'no_connection', null, 0], '§4.3 אין חיבור אישי ואין הקצאה ← blocked: no_connection');

eq([code(api('POST', 'sys-ai-assign-save', ['connection' => $MC, 'scope' => 'office', 'target' => $A, 'models' => ['gpt-x']], $owner)), code(api('POST', 'sys-ai-assign-save', ['connection' => $MC, 'scope' => 'office', 'target' => 'aaaaaaaaaaaaaaaa'], $owner)),
    code(api('POST', 'sys-ai-assign-save', ['connection' => $MC, 'scope' => 'office', 'target' => $A, 'models' => ['claude-opus-5-5'], 'default_model' => 'claude-haiku-5-5'], $owner)),
    api('POST', 'sys-ai-assign-save', ['connection' => $MC, 'scope' => 'office', 'target' => $A, 'models' => []], $owner)[0]], ['ai_model_unknown', 'not_found', 'ai_model_unknown', 200],
    'הקצאה: מודל שאינו בקטלוג, משרד שאינו קיים, או ברירת מחדל מחוץ לרשימה — נדחים');
$r = run($avi, ['case' => $C]);
eq([$r['status'], $r['block_reason'], $r['track'], $calls()], ['blocked', 'model_not_allowed', 'managed', 0], '11-E3 מסלול מנוהל, ולאף מודל אין מחיר ← אינו ניתן לשימוש');
eq([api('POST', 'sys-ai-model-save', $model(), $owner)[0], run($avi, ['case' => $C, 'model' => 'claude-sonnet-5-5'])['block_reason'], run($avi, ['case' => $C, 'model' => 'no-such-model'])['block_reason']], [200, 'model_not_allowed', 'model_not_allowed'],
    '11-E3 אחרי שהוזן מחיר למודל אחד: מודל אחר, שעדיין בלי מחיר, נשאר חסום במנוהל; וכך גם מודל שאינו בקטלוג');
$r = run($avi, ['case' => $C]);
eq([$r['status'], $r['block_reason'], $r['model'], $calls()], ['blocked', 'quota', 'claude-opus-5-5', 0], '11-A2 מסלול מנוהל בלי מכסה מוגדרת ← blocked: quota');

eq([code(api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'office', 'target' => $A, 'period' => 'month', 'limit_money' => '50'], $owner)), code(api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'office', 'target' => $A, 'period' => 'month'], $owner)),
    code(api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'case', 'target' => $C, 'period' => 'month', 'limit_tokens' => 5], $owner)), code(api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'office', 'target' => $A, 'period' => 'year', 'limit_tokens' => 5], $owner)),
    code(api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'office', 'target' => $A, 'period' => 'month', 'limit_tokens' => -1], $owner))],
    ['ai_no_fx', 'ai_quota_empty', 'ai_request', 'ai_request', 'ai_number'],
    '§12.10 בלי שער המרה אי אפשר להגדיר מכסה בכסף; מכסה בלי תקרה נדחית; מנהל המערכת אינו קובע מכסה לתיק (אינו רואה תיקים)');
// מכסה רכה בלבד אינה "מכסה" למסלול המנוהל
[, $soft] = api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'office', 'target' => $A, 'period' => 'day', 'limit_tokens' => 1, 'hard' => false], $owner);
eq([run($avi)['block_reason'], api('POST', 'sys-ai-quota-delete', ['quota' => $soft['quota']['pid']], $owner)[0]], ['quota', 200], '§4 במנוהל מכסה רכה (אזהרה בלבד) אינה מספיקה — נדרשת מכסה קשיחה');
api('POST', 'sys-ai-fx-set', ['rate' => '3.7'], $owner);
[, $q] = api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'office', 'target' => $A, 'period' => 'month', 'limit_tokens' => 100000, 'limit_money' => '50'], $owner);
$QA = $q['quota']['pid'];
$qid = fn(string $pid) => (int)$db('SELECT id FROM ai_quotas WHERE pid = ?', [$pid]);
$cnt = function (string $pid, ?string $key = null) use ($qid) {
    $r = apr_row('SELECT used_money, used_tokens, reserved_money, reserved_tokens FROM ai_usage_counters WHERE quota_id = ?' . ($key ? ' AND period_key = ?' : ' ORDER BY period_key DESC LIMIT 1'), $key ? [$qid($pid), $key] : [$qid($pid)]);
    return $r ? array_map('intval', array_values($r)) : null;
};
[$estIn] = apr_ai_estimate([['type' => 'text', 'text' => SECRET_TEXT]], null);
$est = $estIn + 100;
$estCost = apr_ai_cost($estIn, 100, 4000000, 20000000, 3700000);

// ── בקשה שעוברת: המתאם נקרא פעם אחת, עם המפתח המפוענח, והיישוב לפי דיווח הספק ──
$r = run($avi, ['case' => $C, 'op' => 'first-run-001']);
eq([$r['status'], $r['text'], $r['track'], $r['model'], $r['cost']['basis'], $r['cost']['estimated'], $r['cost']['reported'], $r['usage']['in_tokens'], $r['usage']['out_tokens'], $r['usage']['est_in_tokens'], $r['replayed'], $calls(), $fake->calls[0]['key'],
    $fake->calls[0]['request']['model'], $fake->calls[0]['request']['parts'][0]['text'], array_keys($fake->calls[0]['request'])],
    ['done', SECRET_REPLY, 'managed', 'claude-opus-5-5', 'reported', $estCost, apr_ai_cost(120, 30, 4000000, 20000000, 3700000), 120, 30, $estIn, false, 1, K_MANAGED, 'claude-opus-5-5', SECRET_TEXT, ['model', 'max_tokens', 'system', 'parts']],
    '11-B3 הספק דיווח טוקנים ← cost_basis = reported; האומדן והעלות המדווחת חוזרים בנפרד; המתאם קיבל את המפתח, את המודל ואת התוכן — ושום דבר אחר');
eq([$cnt($QA), (int)$db("SELECT COUNT(*) FROM ai_run_reservations WHERE settled = 0")], [[apr_ai_cost(120, 30, 4000000, 20000000, 3700000), 150, 0, 0], 0], '§6.2 אחרי היישוב: השריון שוחרר, והשימוש — לפי הטוקנים שדווחו');
// 11-D4: אותו מזהה פעולה — אין קריאה שנייה; תוכן אחר באותו מזהה — 409
$again = run($avi, ['case' => $C, 'op' => 'first-run-001']);
$conflict = null;
try { run($avi, ['case' => $C, 'op' => 'first-run-001', 'content' => [['type' => 'text', 'text' => 'אחר']]]); } catch (AprError $e) { $conflict = $e->errorCode; }
eq([$again['status'], $again['replayed'], $again['text'], $again['run'] === $r['run'], $calls(), $conflict, (int)$db("SELECT COUNT(*) FROM ai_runs WHERE idempotency_key LIKE '%first-run-001'"), run($dana, ['op' => 'first-run-001'])['run'] !== $r['run']],
    ['done', true, null, true, 1, 'ai_op_conflict', 1, true], '11-D4, §12.9 אותו מזהה פעולה פעמיים ← קריאה אחת למתאם והתוצאה הקודמת (בלי הטקסט — הוא אינו נשמר); תוכן אחר באותו מזהה ← 409; מזהה של משתמש אחר אינו מתנגש');
// הספק לא דיווח טוקנים
$fake->next = fn() => ['text' => SECRET_REPLY, 'in_tokens' => null, 'out_tokens' => null, 'stop_reason' => 'end_turn'];
$before = $cnt($QA);
$r = run($avi);
eq([$r['status'], $r['cost']['basis'], $r['cost']['reported'], $r['cost']['estimated'], $r['usage']['in_tokens'], [$cnt($QA)[0] - $before[0], $cnt($QA)[1] - $before[1], $cnt($QA)[2], $cnt($QA)[3]]], ['done', 'estimated', null, $estCost, null, [$estCost, $est, 0, 0]],
    '11-B3 הספק לא דיווח טוקנים ← cost_basis = estimated, והאומדן הופך לשימוש');

// 11-B4: unknown — השריון נרשם כשימוש משוער; אין ניסיון חוזר
$fake->next = fn() => throw new AprAiError('unknown', 'timeout');
$before = $cnt($QA);
$n0 = $calls();
$r = run($avi);
eq([$r['status'], $r['error_code'], $r['cost']['basis'], $r['text'], $calls() - $n0, [$cnt($QA)[1] - $before[1], $cnt($QA)[3]]], ['unknown', 'timeout', 'estimated', null, 1, [$est, 0]], '11-B4 פסק זמן ← unknown: השריון נרשם כשימוש משוער, ואין ניסיון חוזר אוטומטי');
// כשל ברור — השריון משתחרר
$fake->next = fn() => throw new AprAiError('rejected', 'http_400');
$before = $cnt($QA);
$n0 = $calls();
$r = run($avi);
eq([$r['status'], $r['error_code'], $r['cost']['basis'], $calls() - $n0, $cnt($QA) === $before], ['failed', 'http_400', null, 1, true], '§6.2.5 הספק דחה בבירור ← failed: השריון משתחרר, השימוש לא משתנה, ואין ניסיון נוסף');
// חריגה לא צפויה מהמתאם, שההודעה שלה נושאת תוכן ומפתח — נרשם רק סוג החריגה
$fake->next = fn(string $key) => throw new LogicException('boom ' . $key . ' ' . SECRET_TEXT);
$r = run($avi);
eq([$r['status'], $r['error_code'], str_contains(json_encode($r, JSON_UNESCAPED_UNICODE), 'boom')], ['unknown', 'exception', false], 'חריגה לא צפויה מהמתאם ← unknown; ההודעה שלה אינה מגיעה לתוצאה');
$seen[] = json_encode($r, JSON_UNESCAPED_UNICODE);
$fake->next = null;

// 11-A4: מכסת משתמש מאפשרת, מכסת משרד לא ← נחסם; ולהפך
[, $qu] = api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'user', 'target' => $aviPid, 'period' => 'day', 'limit_tokens' => 1000000], $owner);
api('POST', 'sys-ai-quota-save', ['quota' => $QA, 'limit_tokens' => $cnt($QA)[1] + $est - 1, 'limit_money' => '50'], $owner);
$n0 = $calls();
$r = run($avi);
eq([$r['status'], $r['block_reason'], $r['quota']['scope'], $r['quota']['pid'], $r['quota']['tokens']['left'], is_string($r['quota']['resets_at']), $calls() - $n0, $cnt($qu['quota']['pid'])], ['blocked', 'quota', 'office', $QA, $est - 1, true, 0, null],
    '11-A4, §6.4 מכסת המשתמש מאפשרת ומכסת המשרד לא ← נחסם; התוצאה אומרת איזו מכסה, כמה נשאר ומתי מתאפסת; שום דבר לא שוריין במכסה האחרת');
api('POST', 'sys-ai-quota-save', ['quota' => $QA, 'limit_tokens' => 10000000, 'limit_money' => '50'], $owner);
api('POST', 'sys-ai-quota-save', ['quota' => $qu['quota']['pid'], 'limit_tokens' => $est - 1], $owner);
$r = run($avi);
eq([$r['block_reason'], $r['quota']['scope'], run($dana)['status']], ['quota', 'user', 'done'], '11-A4 מכסת המשרד מאפשרת ומכסת המשתמש לא ← נחסם; משתמש אחר במשרד, שאין לו מכסה משלו — עובר');
// מכסה בכסף: נשאר פחות מהאומדן
api('POST', 'sys-ai-quota-delete', ['quota' => $qu['quota']['pid']], $owner);
$moneyUsed = $cnt($QA)[0];
api('POST', 'sys-ai-quota-save', ['quota' => $QA, 'limit_tokens' => 10000000, 'limit_money' => apr_ai_dec_out($moneyUsed + $estCost - 1, 2)], $owner);
$r = run($avi);
eq([$r['block_reason'], $r['quota']['money']['limit'], $r['quota']['money']['left']], ['quota', $moneyUsed + $estCost - 1, $estCost - 1], '§6.2 מכסה בכסף נאכפת לפי האומדן: נשארה אגורה פחות מהאומדן ← נחסם');
api('POST', 'sys-ai-quota-save', ['quota' => $QA, 'limit_tokens' => 10000000, 'limit_money' => apr_ai_dec_out($moneyUsed + $estCost, 2)], $owner);
eq(run($avi)['status'], 'done', '§6.2 ובדיוק האומדן — עובר');
api('POST', 'sys-ai-quota-save', ['quota' => $QA, 'limit_tokens' => 10000000], $owner);

// §12.4: הספק דיווח יותר מהאומדן — השימוש לפי הדיווח, גם מעבר לתקרה; הבאה נחסמת
api('POST', 'sys-ai-quota-save', ['quota' => $QA, 'limit_tokens' => $cnt($QA)[1] + $est], $owner);
$fake->next = fn() => ['text' => 'x', 'in_tokens' => 5000, 'out_tokens' => 5000, 'stop_reason' => 'max_tokens'];
$before = $cnt($QA);
$r = run($avi);
$fake->next = null;
[, $sys] = api('GET', 'sys-ai', [], $owner);
eq([$r['status'], $r['stop_reason'], $cnt($QA)[1] - $before[1], run($avi)['block_reason'], count($sys['alerts']['over']), $sys['alerts']['over'][0]['pid'] ?? null], ['done', 'max_tokens', 10000, 'quota', 1, $QA],
    '§12.4 הספק דיווח יותר מהאומדן ← השימוש לפי הדיווח, גם מעבר לתקרה; הבקשה הבאה נחסמת, והחריגה מוצגת למנהל המערכת');
api('POST', 'sys-ai-quota-save', ['quota' => $QA, 'limit_tokens' => 100000000], $owner);

// §12.5: מחיר שעודכן חל על בקשות חדשות; הישנות שומרות את העלות שחושבה אז
$old = apr_row("SELECT pid, reported_cost, est_cost, price_in FROM ai_runs WHERE idempotency_key LIKE '%first-run-001'");
api('POST', 'sys-ai-model-save', $model(['price_in' => '8', 'price_out' => '40']), $owner);
$r = run($avi);
$after = apr_row("SELECT reported_cost, est_cost, price_in FROM ai_runs WHERE pid = ?", [$old['pid']]);
eq([$after === ['reported_cost' => $old['reported_cost'], 'est_cost' => $old['est_cost'], 'price_in' => $old['price_in']], $r['cost']['estimated'], $r['cost']['reported']], [true, apr_ai_cost($estIn, 100, 8000000, 40000000, 3700000), apr_ai_cost(120, 30, 8000000, 40000000, 3700000)],
    '§12.5 עדכון מחיר: בקשה חדשה לפי המחיר החדש; בקשה ישנה שומרת את העלות והמחיר שהיו אז');
api('POST', 'sys-ai-model-save', $model(), $owner);

// §12.2: המכסה התאפסה באמצע הבקשה — נספרת בתקופה שבה שוריינה
$t0 = $GLOBALS['apr_time'];
$fake->next = function () { $GLOBALS['apr_time'] += 40 * 86400; return ['text' => 'x', 'in_tokens' => 7, 'out_tokens' => 3, 'stop_reason' => 'end_turn']; };
$before = $cnt($QA, '2026-11');
$r = run($avi);
$fake->next = null;
eq([$r['status'], $cnt($QA, '2026-11')[1] - $before[1], $cnt($QA, '2026-11')[3], $cnt($QA, apr_ai_period_key('month')), apr_ai_period_key('month')], ['done', 10, 0, null, '2026-12'],
    '§12.2 התקופה התחלפה באמצע הבקשה ← השימוש נרשם בתקופה שבה שוריין; בתקופה החדשה לא נרשם דבר');
$GLOBALS['apr_time'] = $t0;
eq([apr_ai_period_key('month'), apr_ai_period_key('day'), apr_ai_period_key('total'), apr_ai_period_reset('total'), apr_ai_period_reset('month'), apr_ai_period_reset('day')],
    ['2026-11', '2026-11-11', 'total', null, '2026-11-30T22:00:00Z', '2026-11-11T22:00:00Z'], 'תקופות לפי שעון ישראל: חודש, יום, כוללת; האיפוס בחצות ישראל');

// §12.8: מודל שהוסתר — בקשה אליו נחסמת, וברירת המחדל עוברת למותר הבא
api('POST', 'sys-ai-model-save', $model(['model_id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'price_in' => '0.1', 'price_out' => '0.5']), $owner);
api('POST', 'sys-ai-assign-save', ['connection' => $MC, 'scope' => 'office', 'target' => $A, 'models' => [], 'default_model' => 'claude-opus-5-5'], $owner);
$m1 = api('GET', 'my-ai', [], $avi)[1]['ai']['model']['model_id'];
api('POST', 'sys-ai-model-save', $model(['status' => 'hidden']), $owner);
[, $my] = api('GET', 'my-ai', [], $avi);
eq([$m1, run($avi, ['model' => 'claude-opus-5-5'])['block_reason'], $my['ai']['model']['model_id'], $my['ai']['available'], run($avi)['model'], array_column($my['ai']['models'], 'model_id')],
    ['claude-opus-5-5', 'model_not_allowed', 'claude-haiku-5-5', true, 'claude-haiku-5-5', ['claude-haiku-5-5']],
    '§12.8 מודל שהוסתר: בקשה אליו נחסמת; ברירת המחדל עוברת למודל המותר הבא, ו"הבינה שלי" מציג איזה מודל ישמש');
api('POST', 'sys-ai-model-save', $model(), $owner);

// 11-F3 (חלק השרת): "הבינה שלי"
[, $my] = api('GET', 'my-ai', [], $avi);
eq([$my['ai']['track'], $my['ai']['provider'], $my['ai']['model']['model_id'], count($my['quotas']), $my['quotas'][0]['scope'], isset($my['quotas'][0]['tokens']['left']), $my['usage']['runs'] > 5, $my['usage']['cost_reported'] > 0, $my['usage']['cost_estimated'] > 0,
    count($my['blocks']) > 3, array_keys($my['blocks'][0])], ['managed', 'Anthropic (Claude)', 'claude-opus-5-5', 1, 'office', true, true, true, true, true, ['at', 'reason', 'purpose', 'model']],
    '11-F3 (חלק השרת) "הבינה שלי": המסלול, הספק והמודל, המכסה והיתרה, השימוש (מדווח ומשוער בנפרד), ומה נחסם לאחרונה');

// ═══ תיק: "בלי בינה" והרשאות על התוכן ═══════════════════════════
call('POST', 'case-member-add', ['case' => $C, 'user' => $danaPid, 'role' => 'partner'], $avi);
eq([code(api('POST', 'case-ai-set', ['case' => $C, 'no_ai' => true], $dana)), code(api('POST', 'case-ai-set', ['case' => $C, 'no_ai' => true], $boaz)), code(api('POST', 'case-ai-set', ['case' => $C, 'no_ai' => 'yes'], $avi)),
    api('GET', 'case-ai', [], $dana, ['case' => $C])[1]['can_set'], api('GET', 'case-ai', [], $dana, ['case' => $C])[1]['usage'], code(api('GET', 'case-ai', [], $boaz, ['case' => $C])), api('GET', 'case-ai', [], $avi, ['case' => $C])[1]['can_set']],
    ['forbidden', 'not_found', 'ai_request', false, null, 'not_found', true], '§11 "בלי בינה" — השמאי האחראי בלבד: שותף בתיק מקבל 403 ואינו רואה את השימוש; משרד אחר — 404');
$n0 = $calls();
eq([api('POST', 'case-ai-set', ['case' => $C, 'no_ai' => true], $avi)[1]['no_ai'], run($avi, ['case' => $C])['block_reason'], run($dana, ['case' => $C])['block_reason'], run($avi)['status'], $calls() - $n0,
    (int)$db("SELECT COUNT(*) FROM audit_log WHERE action = 'case.ai' AND case_id IS NOT NULL"), api('GET', 'case-ai', [], $dana, ['case' => $C])[1]['no_ai']], [true, 'case_no_ai', 'case_no_ai', 'done', 1, 1, true],
    '11-D2 תיק "בלי בינה" ← blocked: case_no_ai לכל משתתף; בקשה שאינה על התיק אינה נחסמת; השינוי נרשם בפעילות התיק');
api('POST', 'case-ai-set', ['case' => $C, 'no_ai' => false], $avi);

// 11-D3: קובץ שאין למשתמש view עליו, ותיק שאינו שלו ← 404, ואין שורה ב-ai_runs
$tmp = tempnam(apr_upload_tmp_dir(), 'up');
file_put_contents($tmp, "%PDF-1.4\n%x\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
$F = call('POST', 'file-upload', ['case' => $C, 'name' => 'נסח.pdf'], $avi, [], ['env' => ['upload' => $tmp]])[1]['file'] ?? null;
$tmp = tempnam(apr_upload_tmp_dir(), 'up');
file_put_contents($tmp, "%PDF-1.4\n%y\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
$FB = call('POST', 'file-upload', ['case' => $CB, 'name' => 'ב.pdf'], $boaz, [], ['env' => ['upload' => $tmp]])[1]['file'] ?? null;
check('קובצי הבדיקה הועלו', apr_is_pid($F) && apr_is_pid($FB), [$F, $FB]);
$try = function (string $as, array $over) { try { return run($as, $over)['status']; } catch (AprError $e) { return $e->errorCode; } };
$rows0 = (int)$db('SELECT COUNT(*) FROM ai_runs');
$n0 = $calls();
$GLOBALS['apr_file_visible'] = fn($ctx, $c, $f) => (int)$ctx['user']['id'] !== (int)$db('SELECT id FROM users WHERE pid = ?', [$danaPid]);
$denied = [$try($dana, ['case' => $C, 'content' => [['type' => 'text', 'text' => 'סכם'], ['type' => 'file', 'file' => $F]]]), $try($avi, ['case' => $CB]), $try($avi, ['case' => $C, 'content' => [['type' => 'file', 'file' => $FB]]]),
    $try($avi, ['content' => [['type' => 'file', 'file' => $F]]]), $try($avi, ['case' => 'aaaaaaaaaaaaaaaa']), $try($avi, ['case' => $C, 'content' => [['type' => 'file', 'file' => 'bbbbbbbbbbbbbbbb']]])];
eq([$denied, (int)$db('SELECT COUNT(*) FROM ai_runs') - $rows0, $calls() - $n0], [array_fill(0, 6, 'not_found'), 0, 0],
    '11-D3 קובץ שאין למשתמש view עליו · תיק של משרד אחר · קובץ של תיק אחר · קובץ בלי תיק · תיק או קובץ שלא קיימים ← 404, **אין שורה ב-ai_runs** ואין קריאה למתאם');
$r = run($avi, ['case' => $C, 'content' => [['type' => 'text', 'text' => 'סכם'], ['type' => 'file', 'file' => $F]]]);
$sent = $fake->calls[count($fake->calls) - 1]['request']['parts'];
eq([$r['status'], $r['usage']['pages'], array_column($sent, 'type'), str_starts_with($sent[1]['data'], '%PDF'), $sent[1]['mime']], ['done', 1, ['text', 'pdf'], true, 'application/pdf'], '§9 קובץ PDF מהתיק נשלח כקלט, רק אחרי בדיקת ההרשאה; העמודים נספרים');
unset($GLOBALS['apr_file_visible']);
eq([$try($avi, ['content' => []]), $try($avi, ['content' => [['type' => 'url', 'url' => 'x']]]), $try($avi, ['purpose' => 'Bad Purpose']), $try($avi, ['op' => 'x']), $try($avi, ['max_output' => 0]), $try($avi, ['max_output' => 128001]),
    $try($avi, ['content' => [['type' => 'text', 'text' => str_repeat('א', 1000001)]]])], ['ai_content', 'ai_content', 'ai_request', 'ai_request', 'ai_request', 'ai_too_large', 'ai_too_large'],
    'בקשה לא תקינה נדחית לפני כל דבר: תוכן ריק או מסוג לא מוכר, מטרה או מזהה פעולה לא תקינים, פלט מעבר למרבי של המודל, טקסט גדול מדי');
// יכולות: מודל בלי pdf
api('POST', 'sys-ai-model-save', $model(['capabilities' => ['vision' => true, 'pdf' => false]]), $owner);
eq(run($avi, ['case' => $C, 'model' => 'claude-opus-5-5', 'content' => [['type' => 'file', 'file' => $F]]])['block_reason'], 'policy', '§5.7 המודל אינו מקבל PDF ← blocked: policy');
api('POST', 'sys-ai-model-save', $model(), $owner);

// ═══ המסלול האישי, ואין נפילה למנוהל (§4) ═══════════════════════
api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 1]], $boaz);
[$st, $pc] = api('POST', 'office-ai-connection-save', ['provider' => 'anthropic', 'label' => 'החשבון שלנו', 'key' => K_PERSONAL], $boaz);
$PC = $pc['connection']['pid'];
eq([$st, $pc['connection']['track'], $pc['connection']['key_hint'], code(api('POST', 'office-ai-connection-save', ['provider' => 'anthropic', 'label' => 'עוד אחד', 'key' => K_REPLACED], $boaz)),
    code(api('POST', 'office-ai-connection-test', ['connection' => $PC], $avi)), code(api('POST', 'office-ai-connection-revoke', ['connection' => $PC], $avi)), code(api('POST', 'office-ai-connection-save', ['connection' => $PC, 'label' => 'x', 'key' => K_REPLACED], $avi)),
    code(api('POST', 'sys-ai-connection-revoke', ['connection' => $PC], $owner)), code(api('POST', 'sys-ai-connection-test', ['connection' => $PC], $owner)), apr_ai_key_open(apr_row('SELECT * FROM ai_connections WHERE pid = ?', [$PC]))],
    [200, 'personal', substr(K_PERSONAL, -4), 'ai_conn_exists', 'not_found', 'not_found', 'not_found', 'not_found', 'not_found', K_PERSONAL],
    'חיבור אישי: אחד למשרד; מנהל של משרד אחר — 404 בבדיקה, בביטול ובהחלפת מפתח; גם מנהל המערכת אינו נוגע בחיבור אישי של משרד');
$n0 = $calls();
$r = run($boaz);
eq([$r['status'], $r['track'], $fake->calls[count($fake->calls) - 1]['key'], (int)$db("SELECT COUNT(*) FROM ai_quotas WHERE track = 'personal'")], ['done', 'personal', K_PERSONAL, 0], '11-A3 מסלול אישי בלי מכסה ← עובר, במפתח של המשרד');
// גם כשלמשרד ב ולמשתמש יש הקצאה מנוהלת ומכסה מנוהלת — המסלול נשאר אישי
api('POST', 'sys-ai-assign-save', ['connection' => $MC, 'scope' => 'office', 'target' => $B, 'models' => []], $owner);
api('POST', 'sys-ai-assign-save', ['connection' => $MC, 'scope' => 'user', 'target' => $boazPid, 'models' => []], $owner);
api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'office', 'target' => $B, 'period' => 'month', 'limit_tokens' => 1000000], $owner);
$mark = $calls();
$managedSince = fn() => count(array_filter(array_slice($fake->calls, $mark), fn($c) => $c['key'] === K_MANAGED));
// 11-B1: שגיאת אימות בחיבור האישי
$fake->next = fn() => throw new AprAiError('auth', 'http_401');
$r1 = run($boaz);
$r2 = run($boaz);
$st2 = $db('SELECT status FROM ai_connections WHERE pid = ?', [$PC]);
$r3 = run($boaz);
$st3 = $db('SELECT status FROM ai_connections WHERE pid = ?', [$PC]);
$fake->next = null;
$r4 = run($boaz);
eq([[$r1['status'], $r1['track'], $r1['error_code']], $r2['status'], $st2, $r3['status'], $st3, [$r4['status'], $r4['block_reason'], $r4['track']], $managedSince(), $calls() - $mark,
    (int)$db("SELECT COUNT(*) FROM audit_log WHERE action = 'ai.connection.invalid'"), api('GET', 'my-ai', [], $boaz)[1]['ai']['reason']],
    [['failed', 'personal', 'http_401'], 'failed', 'active', 'failed', 'invalid', ['blocked', 'no_connection', 'personal'], 0, 3, 1, 'no_connection'],
    '11-B1, §12.1 חיבור אישי שמחזיר שגיאת אימות ← failed; אחרי 3 כשלים רצופים — "לא תקין" (עם רשומה למנהל); הבקשה הבאה נחסמת **במסלול האישי** — אפס קריאות במפתח המנוהל, אף שיש הקצאה ומכסה מנוהלות');
// החלפת מפתח מחזירה את החיבור לפעיל
eq([api('POST', 'office-ai-connection-save', ['connection' => $PC, 'label' => 'החשבון שלנו', 'key' => K_REPLACED], $boaz)[1]['connection']['status'], run($boaz)['status'], $fake->calls[count($fake->calls) - 1]['key'],
    (int)$db("SELECT COUNT(*) FROM audit_log WHERE action = 'ai.connection.key'")], ['active', 'done', K_REPLACED, 1], '§7 החלפה = הזנת מפתח חדש: החיבור חוזר לפעיל, והבקשה הבאה במפתח החדש; ההחלפה נרשמת ביומן');
// 11-A3 + 11-B2: מכסה אישית שהופעלה נאכפת, ואינה מעבירה למנוהל
eq([code(api('POST', 'office-ai-quota-save', ['scope' => 'user', 'target' => $aviPid, 'period' => 'month', 'limit_tokens' => 5], $boaz)), code(api('POST', 'office-ai-quota-save', ['scope' => 'case', 'target' => $C, 'period' => 'month', 'limit_tokens' => 5], $boaz)),
    code(api('POST', 'office-ai-quota-save', ['scope' => 'office', 'period' => 'month', 'limit_tokens' => 5, 'track' => 'managed'], $dana))], ['not_found', 'not_found', 'forbidden'], 'מכסת רשות: מנהל משרד אינו קובע מכסה למשתמש או לתיק של משרד אחר');
[, $pq] = api('POST', 'office-ai-quota-save', ['scope' => 'office', 'period' => 'month', 'limit_tokens' => $est - 1, 'track' => 'managed'], $boaz);
$mark = $calls();
$r = run($boaz);
eq([$pq['quota']['track'], $pq['quota']['owner'], $r['status'], $r['block_reason'], $r['track'], $r['quota']['owner'], $calls() - $mark, $managedSince()], ['personal', 'office', 'blocked', 'quota', 'personal', 'office', 0, 0],
    '11-A3, 11-B2 מכסה אישית שהופעלה נאכפת (והמסלול שבבקשה של מנהל המשרד אינו נלקח מהדפדפן); כשנחסמה — אין קריאה במסלול המנוהל');
eq([code(api('POST', 'office-ai-quota-delete', ['quota' => $pq['quota']['pid']], $avi)), code(api('POST', 'office-ai-quota-save', ['quota' => $pq['quota']['pid'], 'limit_tokens' => 9999999], $avi)), code(api('POST', 'office-ai-quota-delete', ['quota' => $QA], $avi)),
    code(api('POST', 'office-ai-quota-save', ['quota' => $QA, 'limit_tokens' => 999999999], $avi)), code(api('POST', 'sys-ai-quota-delete', ['quota' => $pq['quota']['pid']], $owner)), (int)$db('SELECT limit_tokens FROM ai_quotas WHERE pid = ?', [$pq['quota']['pid']])],
    ['not_found', 'not_found', 'not_found', 'not_found', 'not_found', $est - 1], 'מכסה: מנהל משרד אינו משנה מכסה של משרד אחר, ולא מכסה שקבע מנהל המערכת; מנהל המערכת אינו מוחק מכסת רשות של משרד');
// מנהל המערכת קובע מכסה במסלול האישי של משרד (האפיון 14.1)
[, $sq] = api('POST', 'sys-ai-quota-save', ['track' => 'personal', 'scope' => 'office', 'target' => $B, 'period' => 'total', 'limit_tokens' => 3], $owner);
api('POST', 'office-ai-quota-delete', ['quota' => $pq['quota']['pid']], $boaz);
$r = run($boaz);
[, $ob] = api('GET', 'office-ai', [], $boaz);
eq([$r['block_reason'], $r['quota']['owner'], array_column($ob['quotas'], 'editable', 'pid')[$sq['quota']['pid']] ?? null, code(api('POST', 'sys-ai-quota-save', ['track' => 'personal', 'scope' => 'user', 'target' => $boazPid, 'period' => 'month', 'limit_tokens' => 3], $owner))],
    ['quota', 'system', false, 'ai_request'], '§11 מנהל המערכת קובע מכסה גם במסלול האישי; מנהל המשרד רואה אותה ואינו עורך; מכסת משתמש במסלול האישי דורשת משרד');
api('POST', 'sys-ai-quota-delete', ['quota' => $sq['quota']['pid']], $owner);
// "בקשת הגדלה"
eq([api('POST', 'ai-quota-request', ['quota' => $QA], $dana)[1]['to'], code(api('POST', 'ai-quota-request', ['quota' => $QA], $dana)), code(api('POST', 'ai-quota-request', ['quota' => $QA], $boaz)), count(api('GET', 'sys-ai', [], $owner)[1]['alerts']['requests'])],
    ['sysadmin', 'too_soon', 'not_found', 1], '§6.4 "בקשת הגדלה" מגיעה למי שרשאי להגדיל; פעם ביום; מכסה שאינה חלה עליי — 404');
// בחירה מפורשת של המשרד במסלול המנוהל — הגדרה, לא נפילה
api('POST', 'office-ai-policy', ['prefer_managed' => true], $boaz);
$r = run($boaz);
eq([$r['status'], $r['track'], $fake->calls[count($fake->calls) - 1]['key'], (int)$db("SELECT COUNT(*) FROM audit_log WHERE action = 'ai.policy.track'")], ['done', 'managed', K_MANAGED, 1], '§4 "להשתמש במסלול המנוהל גם כשיש חיבור אישי" — הגדרת משרד מפורשת, שנרשמת ביומן');
api('POST', 'sys-ai-assign-delete', ['scope' => 'office', 'target' => $B], $owner);
api('POST', 'sys-ai-assign-delete', ['scope' => 'user', 'target' => $boazPid], $owner);
$mark = $calls();
$r = run($boaz);
eq([$r['block_reason'], $r['track'], $calls() - $mark], ['no_connection', null, 0], '§4 בחר במנוהל ואין הקצאה ← blocked: no_connection — אין נפילה לחיבור האישי');
api('POST', 'office-ai-policy', ['prefer_managed' => false], $boaz);

// §8: שינוי מהותי בתיאור הפרטיות של הספק ← נדרש אישור מחדש
eq([code(api('POST', 'sys-ai-provider-save', ['provider' => 'anthropic', 'privacy_url' => 'javascript:alert(1)'], $owner)), code(api('POST', 'sys-ai-provider-save', ['provider' => 'anthropic', 'privacy_checked_at' => '2026-13-40'], $owner))], ['url_invalid', 'ai_number'], 'קטלוג הספקים: קישור שאינו http(s) ותאריך לא קיים נדחים');
api('POST', 'sys-ai-provider-save', ['provider' => 'anthropic', 'privacy_note' => 'מעובד בארה"ב; נשמר 30 יום; אינו משמש לאימון', 'privacy_checked_at' => '2026-11-10'], $owner);
$stillOn = run($boaz)['status'];
api('POST', 'sys-ai-provider-save', ['provider' => 'anthropic', 'privacy_note' => 'מעובד באירופה', 'privacy_checked_at' => '2026-11-11', 'material' => true], $owner);
[, $ob] = api('GET', 'office-ai', [], $boaz);
eq([$stillOn, run($boaz)['block_reason'], $ob['policy']['needs_reapproval'], $ob['policy_text']['providers'][0]['rev'], code(api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 1]], $boaz)),
    api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 2]], $boaz)[0], run($boaz)['status']], ['done', 'policy', true, 2, 'ai_policy_changed', 200, 'done'],
    '§8 עדכון רגיל של תיאור הספק אינו עוצר; שינוי מהותי ← כל משרד נחסם (policy) עד אישור מחדש של הנוסח המעודכן');
api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 2]], $avi);
eq([api('POST', 'office-ai-policy', ['enabled' => false], $boaz)[0], run($boaz)['block_reason'], run($avi)['status']], [200, 'policy', 'done'], 'כיבוי הבינה במשרד אחד חוסם אותו בלבד');
api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 2]], $boaz);

// ═══ בדיקת חיבור (11-F1, עם המתאם המדומה) ═══════════════════════
$fake->health = [];
$t1 = api('POST', 'sys-ai-connection-test', ['connection' => $MC], $owner)[1];
$fake->healthNext = fn() => throw new AprAiError('unreachable', 'curl_7');
$t2 = api('POST', 'sys-ai-connection-test', ['connection' => $MC], $owner)[1];
$fake->healthNext = fn() => throw new AprAiError('auth', 'http_401');
$t3 = api('POST', 'sys-ai-connection-test', ['connection' => $MC], $owner)[1];
$blockedNow = run($avi)['block_reason'];
$fake->healthNext = null;
$t4 = api('POST', 'sys-ai-connection-test', ['connection' => $MC], $owner)[1];
eq([[$t1['result'], $t1['connection']['status']], [$t2['result'], $t2['connection']['status'], $t2['connection']['last_error']], [$t3['result'], $t3['connection']['status']], $blockedNow, [$t4['result'], $t4['connection']['status'], $t4['connection']['last_error']], array_unique($fake->health)],
    [['ok', 'active'], ['unreachable', 'active', 'curl_7'], ['invalid', 'invalid'], 'no_connection', ['ok', 'active', null], [K_MANAGED]],
    '11-F1 (עם מתאם מדומה) בדיקת חיבור: עברה ← פעיל; הספק לא זמין ← המצב לא משתנה; האימות נדחה ← "לא תקין" מיד, והבקשות נחסמות; בדיקה שעברה מחזירה לפעיל');

// ═══ מה כל אחד רואה בשימוש (§10) ═══════════════════════════════
$caseNumber = (string)$db('SELECT number FROM cases WHERE pid = ?', [$C]);
[, $sys] = api('GET', 'sys-ai', [], $owner);
$sysJson = json_encode($sys, JSON_UNESCAPED_UNICODE);
$keysDeep = function ($v) use (&$keysDeep) { $k = []; if (is_array($v)) foreach ($v as $key => $x) { if (is_string($key)) $k[$key] = 1; $k += $keysDeep($x); } return $k; };
eq([count($sys['usage']) > 1, array_keys($sys['usage'][0]), str_contains($sysJson, $C), str_contains($sysJson, $CB), str_contains($sysJson, $caseNumber), array_intersect(['case', 'case_id', 'case_pid', 'number', 'purpose'], array_keys($keysDeep($sys))),
    str_contains($sysJson, 'test.run'), count(array_unique(array_column($sys['usage'], 'office'))), count($sys['blocks']) > 2],
    [true, ['day', 'office', 'office_name', 'user', 'user_name', 'model', 'track', 'runs', 'blocked', 'tokens', 'cost_reported', 'cost_estimated'], false, false, false, [], false, 2, true],
    '11-E1 השימוש למנהל המערכת מצרפי בלבד — משרד, משתמש, מודל, מסלול ויום: אין מזהה תיק, אין מספר תיק, אין מטרה');
[, $oaA] = api('GET', 'office-ai', [], $avi);
[, $oaB] = api('GET', 'office-ai', [], $boaz);
$jsonB = json_encode($oaB, JSON_UNESCAPED_UNICODE);
eq([array_column($oaA['usage']['by_case'], 'case'), array_column($oaB['usage']['by_case'], 'case'), str_contains($jsonB, $C), str_contains($jsonB, $aviPid), str_contains($jsonB, $QA), array_values(array_unique(array_column($oaB['usage']['by_user'], 'user'))),
    count($oaA['usage']['by_user']), array_sum(array_column($oaA['usage']['by_user'], 'runs')) === (int)$db('SELECT COUNT(*) FROM ai_runs r JOIN offices o ON o.id = r.office_id WHERE o.pid = ? AND r.created_at >= ?', [$A, apr_ai_month_start()])],
    [[$C], [], false, false, false, [$boazPid], 2, true], '11-E2 מנהל משרד רואה שימוש לפי משתמש ותיק — במשרדו בלבד: משרד ב אינו מקבל תיק, משתמש או מכסה של משרד א');
[, $myD] = api('GET', 'my-ai', [], $dana);
eq([$myD['usage']['runs'] === (int)$db('SELECT COUNT(*) FROM ai_runs r JOIN users u ON u.id = r.user_id WHERE u.pid = ? AND r.created_at >= ?', [$danaPid, apr_ai_month_start()]), $myD['usage']['runs'] < array_sum(array_column($oaA['usage']['by_user'], 'runs')),
    str_contains(json_encode($myD), $aviPid)], [true, true, false], '§10 משתמש רואה רק את השימוש של עצמו');

// ═══ 11-A5: שריון שנשאר פתוח — תהליך שמת באמצע ═══════════════════
$GLOBALS['apr_time'] = time();          // התהליך-הבן רץ בשעון אמיתי
$eve = ident('20', 'בן', 'u20@example.com');
$E = office_for($eve, 'משרד המרוץ');
api('POST', 'office-ai-policy', ['enabled' => true, 'text_version' => 1, 'providers' => ['anthropic' => 2]], $eve);
api('POST', 'sys-ai-assign-save', ['connection' => $MC, 'scope' => 'office', 'target' => $E, 'models' => []], $owner);
[, $qe] = api('POST', 'sys-ai-quota-save', ['track' => 'managed', 'scope' => 'office', 'target' => $E, 'period' => 'total', 'limit_tokens' => 10 * $est], $owner);
$QE = $qe['quota']['pid'];
$died = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' dier ' . escapeshellarg($dir) . ' 20 0'));
$open = apr_row("SELECT * FROM ai_runs WHERE status IN ('reserved', 'running') ORDER BY id DESC LIMIT 1");
$early = apr_ai_sweep();
$GLOBALS['apr_time'] = time() + APR_AI_STALE - 5;
$stillEarly = apr_ai_sweep();
$GLOBALS['apr_time'] = time() + APR_AI_STALE + 5;
$during = $cnt($QE);
$swept = apr_ai_sweep();
$closed = apr_row('SELECT status, cost_basis, error_code, in_tokens FROM ai_runs WHERE id = ?', [$open['id'] ?? 0]);
eq([$died, $open['status'] ?? null, $during, [$early, $stillEarly, $swept], $closed, $cnt($QE), apr_ai_sweep(), isset(apr_job_kinds()['ai.reservations']), apr_job_kinds()['ai.reservations']['every'] ?? 0],
    ['died', 'running', [0, 0, $estCost, $est], [0, 0, 1], ['status' => 'unknown', 'cost_basis' => 'estimated', 'error_code' => 'stale', 'in_tokens' => null], [$estCost, $est, 0, 0], 0, true, 300],
    '11-A5 תהליך שמת באמצע הקריאה: השריון נשאר; לפני 15 דקות שירות הרקע אינו נוגע בו; אחריהן — מיושב כשימוש משוער (unknown), פעם אחת; השירות רשום ורץ כל 5 דקות');
$raises = function (string $sql): bool { try { apr_exec($sql); return false; } catch (PDOException) { return true; } };
eq([$raises('DELETE FROM ai_runs WHERE id = ' . (int)$open['id']), $raises("UPDATE ai_runs SET status = 'done' WHERE id = " . (int)$open['id']), $raises("UPDATE ai_runs SET in_tokens = 0 WHERE status = 'blocked'"), $raises('DELETE FROM ai_connections')], [true, true, true, true],
    'המסד: רישום שימוש אינו נמחק, בקשה שהסתיימה אינה משתנה, וחיבור אינו נמחק (טריגרים)');

// ═══ 11-A1: עשר בקשות מקבילות, מקום לאחת — תהליכים אמיתיים ═══════
$GLOBALS['apr_time'] = time();
api('POST', 'sys-ai-quota-save', ['quota' => $QE, 'limit_tokens' => $cnt($QE)[1] + $est], $owner);
@unlink($dir . '/adapter-calls.log');
$procs = $pipes = [];
$go = (string)(microtime(true) + 2.0);
for ($i = 0; $i < 10; $i++) $procs[] = proc_open([PHP_BINARY, __FILE__, 'racer', $dir, '20', $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
$out = [];
foreach ($procs as $i => $proc) { $out[] = trim((string)stream_get_contents($pipes[$i][1])); proc_close($proc); }
sort($out);
$after = $cnt($QE);
eq([$out, count(file($dir . '/adapter-calls.log') ?: []), $after[3], $after[1] <= (int)$db('SELECT limit_tokens FROM ai_quotas WHERE pid = ?', [$QE]), (int)$db("SELECT COUNT(*) FROM ai_runs WHERE purpose = 'test.race' AND status = 'blocked' AND block_reason = 'quota'"),
    (int)$db("SELECT COUNT(*) FROM ai_runs WHERE purpose = 'test.race' AND status = 'done'")], [array_merge(array_fill(0, 9, 'blocked:quota'), ['done:']), 1, 0, true, 9, 1],
    '11-A1 מכסה קשיחה עם מקום לבקשה אחת, 10 בקשות מקבילות בתהליכים נפרדים ← **בדיוק אחת** מגיעה למתאם; 9 נחסמות (quota); התקרה לא נעקפה');
$GLOBALS['apr_time'] = 1794400000;

// ═══ ביטול (11-C3) ═════════════════════════════════════════════
api('POST', 'office-ai-connection-revoke', ['connection' => $PC], $boaz);
$rev = apr_row('SELECT * FROM ai_connections WHERE pid = ?', [$PC]);
eq([$rev['status'], $rev['key_enc'], $rev['key_hint'], $rev['revoked_at'] !== null, apr_ai_key_open($rev), $raises("UPDATE ai_connections SET status = 'active', revoked_at = NULL, key_enc = x'00' WHERE id = " . (int)$rev['id']),
    $raises("UPDATE ai_connections SET key_enc = x'0011' WHERE id = " . (int)$rev['id']), code(api('POST', 'office-ai-connection-save', ['connection' => $PC, 'label' => 'x', 'key' => K_PERSONAL], $boaz)), code(api('POST', 'office-ai-connection-test', ['connection' => $PC], $boaz)),
    api('GET', 'office-ai', [], $boaz)[1]['connection']['key_hint'], run($boaz)['block_reason'], api('POST', 'office-ai-connection-save', ['provider' => 'anthropic', 'label' => 'חדש', 'key' => K_PERSONAL], $boaz)[0]],
    ['revoked', null, null, true, null, true, true, 'ai_conn_state', 'ai_conn_state', null, 'no_connection', 200],
    '11-C3 אחרי ביטול: אין מפתח במסד (גם לא 4 התווים); חיבור שבוטל אינו חוזר ואינו מקבל מפתח — גם לא ב-SQL ישיר; הבקשות נחסמות; אפשר לחבר חיבור חדש');
eq((int)$db("SELECT COUNT(*) FROM ai_connections WHERE status <> 'revoked' AND key_enc IS NULL"), 0, 'חיבור שאינו מבוטל — תמיד עם מפתח מוצפן');

// ═══ 11-C4: ב-ai_runs אין תוכן ═════════════════════════════════
$cols = array_column(apr_rows('PRAGMA table_info(ai_runs)'), 'name');
eq($cols, ['id', 'pid', 'office_id', 'case_id', 'user_id', 'quota_user_id', 'track', 'connection_id', 'provider_key', 'model_id', 'purpose', 'status', 'block_reason', 'in_tokens', 'out_tokens', 'est_in_tokens', 'est_out_tokens', 'est_cost',
    'reported_cost', 'cost_basis', 'price_in', 'price_out', 'fx_rate', 'images', 'pages', 'idempotency_key', 'request_hash', 'stop_reason', 'error_code', 'created_at', 'started_at', 'finished_at'],
    '11-C4 העמודות של ai_runs — רשימה סגורה: אין עמודה לבקשה, לתשובה או להודעת שגיאה (עמודה חדשה מחייבת לעדכן את הבדיקה)');
eq(array_values(array_unique(array_filter(array_column(apr_rows('SELECT error_code FROM ai_runs WHERE error_code IS NOT NULL'), 'error_code'), fn($c) => preg_match('/^[a-z0-9_]{1,40}\z/', $c) !== 1))), [], 'error_code הוא קוד טכני קצר בלבד');

// ═══ 11-C1: המפתח והתוכן אינם בשום מקום ═════════════════════════
apr_db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$needles = ['המפתח המנוהל' => K_MANAGED, 'המפתח האישי' => K_PERSONAL, 'המפתח שהוחלף' => K_REPLACED, 'חלק מהמפתח' => substr(K_MANAGED, 8, 16), 'המפתח ב-base64' => base64_encode(K_MANAGED), 'התוכן שנשלח' => SECRET_TEXT, 'תשובת המודל' => SECRET_REPLY];
$scan = function (string $root) use (&$scan): string { $all = ''; foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $f) $all .= is_dir("$root/$f") ? $scan("$root/$f") : "\n" . (string)file_get_contents("$root/$f"); return $all; };
$disk = $scan($dir);
$tables = '';
foreach (array_column(apr_rows("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name') as $t) $tables .= json_encode(apr_rows("SELECT * FROM \"$t\""), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
$log = implode('', array_map('file_get_contents', glob("$dir/_dev/log.sqlite*") ?: []));
$apiText = implode("\n", $seen);
foreach ($needles as $name => $needle) {
    $isKey = str_contains($name, 'מפתח');
    check("11-C1 $name: לא בקובצי הנתונים (app.sqlite, היומן הטכני _dev/log.sqlite, וכל קובץ אחר ב-data/)", !str_contains($disk, $needle));
    check("11-C1 $name: לא בשום טבלה (כולל audit_log ו-ai_runs)", !str_contains($tables, $needle));
    if ($isKey) check("11-C1 $name: לא בשום תשובת API", !str_contains($apiText, $needle));
}
check('11-C1 הסריקה אכן רואה את המסד, את היומן הטכני ואת התשובות', strlen($disk) > 100000 && str_contains($log, 'ai.adapter') && str_contains($log, 'LogicException') && count($seen) > 150 && str_contains($tables, 'ai.connection.create') && str_contains($apiText, substr(K_MANAGED, -4)), [strlen($disk), strlen($log), count($seen)]);
file_put_contents("$dir/leak.txt", 'x' . K_MANAGED);
check('11-C1 הסריקה תופסת מפתח גלוי כשיש', str_contains($scan($dir), K_MANAGED));
unlink("$dir/leak.txt");
eq((int)$db("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'ai.%' AND (meta LIKE '%sk-%' OR meta LIKE '%key_hint%' OR meta LIKE '%Zq7M%')"), 0, '§7 יומן הפעולות של הבינה אינו נושא מפתח ולא חלק ממנו');
check('§7 שמירה והחלפה של מפתח נרשמות ביומן הפעולות', (int)$db("SELECT COUNT(*) FROM audit_log WHERE action IN ('ai.connection.create', 'ai.connection.key')") >= 4);

// ═══ המתאם של Anthropic — בלי רשת (11-D5) ═══════════════════════
$body = AprAnthropicAdapter::body(['model' => 'm', 'max_tokens' => 50, 'system' => 'הנחיה', 'parts' => [['type' => 'text', 'text' => 'שאלה'], ['type' => 'pdf', 'mime' => 'application/pdf', 'data' => '%PDF-x'], ['type' => 'image', 'mime' => 'image/png', 'data' => 'PNG']]]);
eq([array_keys($body), array_column($body['messages'][0]['content'], 'type'), $body['messages'][0]['content'][0]['source'], count($body['messages']), $body['messages'][0]['role'],
    array_keys(AprAnthropicAdapter::body(['model' => 'm', 'max_tokens' => 5, 'system' => null, 'parts' => [['type' => 'text', 'text' => 'x']]])), array_keys(AprAnthropicAdapter::body(['model' => 'm', 'max_tokens' => 5, 'parts' => [['type' => 'text', 'text' => 'x']]], true))],
    [['model', 'messages', 'max_tokens', 'system'], ['document', 'image', 'text'], ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-x')], 1, 'user', ['model', 'messages', 'max_tokens'], ['model', 'messages']],
    '11-D5 גוף הבקשה לספק: model, messages, max_tokens, system — **בלי tools**, בלי tool_choice ובלי temperature; מסמך ותמונה לפני הטקסט');
$adapterSrc = file_get_contents(__DIR__ . '/../lib/ai_adapter.php');
$adapterCode = implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all($adapterSrc)));
eq([preg_match('/[\'"]tools?[\'"]|tool_choice|mcp_servers|temperature/', $adapterCode), substr_count($adapterCode, 'curl_init('), str_contains($adapterCode, 'curl_init(self::BASE . $path)'), preg_match_all('#https?://[^\'"]+#', $adapterCode, $urls), $urls[0],
    str_contains($adapterCode, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($adapterCode, 'CURLOPT_SSL_VERIFYHOST => 2') && str_contains($adapterCode, 'CURLOPT_FOLLOWLOCATION => false') && str_contains($adapterCode, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS'),
    preg_match('/VERIFYPEER\s*=>\s*(false|0)|VERIFYHOST\s*=>\s*(false|0)/', $adapterCode)],
    [0, 1, true, 1, ['https://api.anthropic.com'], true, 0], '11-D5 + רשת: המתאם אינו מזכיר tools; קריאת cURL אחת, לכתובת קבועה בקוד (לא מהמסד ולא מהמשתמש); TLS מאומת, בלי הפניות, HTTPS בלבד');
$kind = function (string|bool $raw, int $errno, int $status) { try { AprAnthropicAdapter::interpret($raw, $errno, $status); return 'ok'; } catch (AprAiError $e) { return $e->kind . ':' . $e->tech; } };
$leak = '{"type":"error","error":{"type":"invalid_request_error","message":"' . K_MANAGED . ' ' . SECRET_TEXT . '"}}';
$msg = function () use ($leak) { try { AprAnthropicAdapter::interpret($leak, 0, 400); } catch (AprAiError $e) { return $e->getMessage(); } };
eq([$kind('{"type":"message"}', 0, 200), $kind('<html>', 0, 200), $kind($leak, 0, 401), $kind($leak, 0, 403), $kind($leak, 0, 400), $kind($leak, 0, 429), $kind($leak, 0, 529), $kind('Bad Gateway', 0, 502), $kind(false, CURLE_OPERATION_TIMEDOUT, 0),
    $kind(false, CURLE_COULDNT_CONNECT, 0), $kind(false, CURLE_COULDNT_RESOLVE_HOST, 0), $kind(false, 60, 0), $kind(false, CURLE_RECV_ERROR, 0), $msg()],
    ['ok', 'unknown:bad_body', 'auth:http_401', 'auth:http_403', 'rejected:http_400', 'rejected:http_429', 'rejected:http_529', 'unknown:http_502', 'unknown:timeout', 'unreachable:curl_7', 'unreachable:curl_6', 'unreachable:curl_60', 'unknown:curl_56', 'ai: rejected (http_400)'],
    '§5 שגיאת ספק אינה דולפת גולמית: כל כשל הופך לקוד טכני (אימות / דחייה / לא נשלחה / לא ידוע); גוף התשובה — עם מפתח ותוכן בתוכו — אינו נכנס לחריגה');
check('המתאם האמיתי רשום, ואינו נטען בבדיקות', APR_AI_ADAPTERS === ['anthropic' => 'AprAnthropicAdapter'] && apr_ai_adapter('anthropic') === $fake);

// ═══ בדיקה סטטית: רק lib/ai.php מדבר עם מתאם ═══════════════════
$libs = [];
foreach (glob(__DIR__ . '/../lib/*.php') as $f) $libs[basename($f)] = implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true) ? '' : $t[1]) : $t, token_get_all(file_get_contents($f))));
foreach (['api.php', 'cron.php'] as $f) $libs[$f] = (string)file_get_contents(__DIR__ . '/../' . $f);
$who = fn(string $re) => array_keys(array_filter($libs, fn($src, $name) => $name !== 'devloop.php' && preg_match($re, $src) === 1, ARRAY_FILTER_USE_BOTH));
eq([$who('/apr_ai_adapter\s*\(/'), $who('/->\s*complete\s*\(/'), $who('/->\s*health\s*\(\s*\$key/'), $who('/apr_ai_key_open\s*\(/'), $who('/api\.anthropic\.com|AprAnthropicAdapter/'), $who('/\bai_run\s*\(/'), substr_count($libs['ai.php'], '->complete('), substr_count($libs['ai.php'], '->health(')],
    [['ai.php', 'ai_adapter.php'], ['ai.php'], ['ai.php'], ['ai.php'], ['ai_adapter.php'], ['ai.php'], 1, 1],
    '§5 השער: רק lib/ai.php קורא למתאם ופותח מפתח — complete() פעם אחת (בתוך ai_run) ו-health() פעם אחת (בדיקת חיבור); אין קובץ אחר שמגיע לספק');
eq(preg_match('/function ai_run\(.*?\n}\n/s', $libs['ai.php'], $gate), 1, 'ai_run נמצאה לבדיקה הסטטית');
$pos = fn(string $s) => strpos($gate[0], $s);
check('§5 הסדר ב-ai_run: מזהה פעולה ← מדיניות ← "בלי בינה" ← מסלול ← מודל ← יכולות ← שריון ← המתאם ← יישוב', $pos('idempotency_key = ?') < $pos('apr_ai_policy_on') && $pos('apr_ai_policy_on') < $pos('case_ai') && $pos('case_ai') < $pos('apr_ai_route(')
    && $pos('apr_ai_route(') < $pos('apr_ai_model_pick') && $pos('apr_ai_model_pick') < $pos("'vision'") && $pos("'vision'") < $pos('apr_ai_reserve_check') && $pos('apr_ai_reserve_check') < $pos('->complete(') && $pos('->complete(') < $pos("apr_ai_settle(\$runId, 'done'"));
check('§6.2 הקריאה לספק מחוץ לטרנזקציה: apr_tx נסגר לפני complete()', substr_count(substr($gate[0], 0, $pos('->complete(')), 'apr_tx(') === 1 && $pos("return ['run' => \$id") < $pos('->complete('));
$front = '';
foreach (array_merge(glob(__DIR__ . '/../assets/js/*.js'), glob(__DIR__ . '/../assets/js/screens/*.js'), [__DIR__ . '/../index.html']) as $f) $front .= file_get_contents($f);
check('הדפדפן אינו פונה לספק ואינו מחזיק מפתח', !preg_match('/anthropic\.com|x-api-key|sk-ant/i', $front));

// ═══ בריאות ומסך המפתח ═════════════════════════════════════════
[, $h] = call('GET', 'dev-health', [], $owner);
$ai = array_values(array_filter($h['checks'] ?? [], fn($c) => ($c['id'] ?? '') === 'ai'))[0] ?? null;
check('בדיקת התקינות "בינה" רשומה במסך המפתח', $ai !== null && in_array($ai['state'] ?? '', ['ok', 'warn'], true), $ai);

// ═══ מיגרציה 013 על מסד v12 מאוכלס ═════════════════════════════
apr_db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$m12 = sys_get_temp_dir() . '/apr-m12-' . bin2hex(random_bytes(5));
mkdir($m12, 0700, true);
foreach (glob(__DIR__ . '/../lib/migrations/*.sql') as $mf) if ((int)basename($mf) <= 12) copy($mf, $m12 . '/' . basename($mf));
$pdoOpt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$v12 = new PDO("sqlite:$dir/v12.sqlite", null, null, $pdoOpt);
apr_migrate($v12, $m12);
$v12->exec('PRAGMA foreign_keys = OFF');
$v12->exec('ATTACH ' . $v12->quote("$dir/app.sqlite") . ' AS live');
$old = array_column($v12->query("SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchAll(), 'name');
foreach ($old as $t) {
    try { $v12->exec("DELETE FROM main.\"$t\""); } catch (PDOException) { continue; }
    try { $v12->exec("INSERT INTO main.\"$t\" SELECT * FROM live.\"$t\""); } catch (PDOException) { continue; }
}
$v12->exec('DETACH live');
$v12->exec('PRAGMA foreign_keys = ON');
$dump = fn(PDO $d) => array_map(fn($t) => [$t, hash('sha256', json_encode($d->query("SELECT * FROM \"$t\" ORDER BY 1")->fetchAll()))], $old);
$d12 = $dump($v12);
$had = [apr_schema_version($v12), in_array('ai_runs', $old, true), (int)$v12->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() > 50, (int)$v12->query('SELECT COUNT(*) FROM cases')->fetchColumn()];
apr_migrate($v12);
$fresh = new PDO('sqlite::memory:', null, null, $pdoOpt);
apr_migrate($fresh);
$shape = fn(PDO $d) => json_encode($d->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll());
$new = array_column($v12->query("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE 'ai_%' OR name IN ('fx_rates', 'case_ai')) ORDER BY name")->fetchAll(), 'name');
eq([apr_schema_version($v12) >= 13, $had, $dump($v12) === $d12, $shape($v12) === $shape($fresh), $new, (int)$v12->query('SELECT COUNT(*) FROM ai_models WHERE price_in IS NOT NULL OR price_out IS NOT NULL')->fetchColumn(),
    (int)$v12->query('SELECT COUNT(*) FROM ai_connections')->fetchColumn() + (int)$v12->query('SELECT COUNT(*) FROM ai_policies')->fetchColumn(), count($v12->query('PRAGMA foreign_key_check')->fetchAll()),
    trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' diff --stat origin/main -- lib/migrations/00[1-9]_*.sql lib/migrations/01[0-2]_*.sql 2>/dev/null'))],
    [true, [12, false, true, 2], true, true, ['ai_assignments', 'ai_connections', 'ai_models', 'ai_policies', 'ai_providers', 'ai_quotas', 'ai_run_reservations', 'ai_runs', 'ai_usage_counters', 'case_ai', 'fx_rates'], 0, 0, 0, ''],
    'מיגרציה 013 על מסד v12 מאוכלס: כל שורה בכל טבלה קיימת נשמרת, המבנה זהה למסד חדש; המחירים ריקים, אין חיבורים ואין משרד שהבינה דלוקה בו; 001–012 לא נגעו');
$v12 = $fresh = null;

eq($warnings, [], 'אין אזהרות PHP לאורך כל הבדיקות');

// ניקוי: התיקייה אינה נמחקת לבד (ראו למעלה)
$rm = function (string $d) use (&$rm) { foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f"); @rmdir($d); };
$GLOBALS['apr_pdo'] = null;
register_shutdown_function(function () use ($rm, $dir, $m12) { $rm($dir); $rm($m12); });
done();
