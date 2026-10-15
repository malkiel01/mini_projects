<?php
/**
 * שלב 12 — סיווג חכם. קובץ ששותף (או שנבחר להעלאה) נשלח ל-Claude: מה זה (חשבונית, קבלה, הצעת מחיר, חוזה,
 * תוכנית, תמונה מהשטח…), מאיזה ספק, על כמה, מאיזה תאריך — ולאיזה פרויקט הוא שייך. מזה נבנית הצעה: "וילה כהן /
 * אינסטלטור כהן" — לחיצה אחת שומרת לשם, עם סוג הקובץ.
 *
 * מה נשלח: התמונה (מוקטנת בטלפון) או העמוד הראשון / השני של PDF כתמונה (pdf.js בטלפון) — לא הקובץ המקורי.
 * ועוד רשימת הפרויקטים הפתוחים שלך (שם, לקוח, כתובת) והספקים (שם, ח.פ.) — כדי ש-Claude יבחר מתוכם, במזהה.
 * המזהים נבדקים כאן מול ההרשאות; מה שלא שלך — נזרק.
 *
 * המפתח: הגדרות ← כללי (מפתח בלבד), נשמר בשרת ולא חוזר לדפדפן; או ANTHROPIC_API_KEY בסביבה.
 * HTTP ישיר (curl) — האפליקציה בלי composer ובלי תלויות (שרת אחסון משותף), ולכן לא ה-SDK.
 */

require_once __DIR__ . '/files.php';
require_once __DIR__ . '/projects.php';

const PM_AI_MODEL = 'claude-opus-5-5';
const PM_AI_URL = 'https://api.anthropic.com/v1/messages';
const PM_AI_DOC_TYPES = ['invoice', 'receipt', 'quote', 'contract', 'plan', 'site_photo', 'other'];
// סוג המסמך → סוג הקובץ באפליקציה (PM_FILE_TYPES)
const PM_AI_FILE_TYPE = ['invoice' => 'invoice', 'receipt' => 'proof', 'quote' => 'quote', 'contract' => 'contract',
    'plan' => 'plan', 'site_photo' => 'photo', 'other' => 'other'];

function pm_ai_key(): string
{
    return (string)(getenv('ANTHROPIC_API_KEY') ?: pm_setting('ai_key'));
}

function pm_ai_on(): bool
{
    return pm_ai_key() !== '' && pm_setting('ai_on', '1') === '1';
}

/** מה המסך יודע על ההגדרה — בלי המפתח עצמו. */
function pm_ai_status(): array
{
    $k = pm_ai_key();
    return ['on' => pm_ai_on(), 'has_key' => $k !== '', 'key_hint' => $k !== '' ? '…' . substr($k, -4) : '',
            'from_env' => (bool)getenv('ANTHROPIC_API_KEY'), 'daily_max' => (int)pm_setting('ai_daily_max', '300'),
            'today' => (int)pm_setting('ai_count_' . pm_today(), '0')];
}

/** הגדרות (מפתח בלבד): המפתח (ריק = בלי שינוי, "-" = מחיקה), הפעלה, ומכסה יומית. */
function pm_ai_settings_save(array $user, array $in): array
{
    $key = trim((string)($in['key'] ?? ''));
    if ($key === '-') pm_setting_set('ai_key', '');
    elseif ($key !== '') {
        if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $key)) throw new PmError('המפתח לא נראה כמו מפתח של Anthropic (sk-ant-…)', 422);
        pm_setting_set('ai_key', $key);
    }
    if (isset($in['on'])) pm_setting_set('ai_on', !empty($in['on']) ? '1' : '0');
    if (isset($in['daily_max'])) pm_setting_set('ai_daily_max', (string)max(0, min(5000, (int)$in['daily_max'])));
    pm_audit("user:{$user['id']}", 'setting.ai', 'setting', null, ['key_changed' => $key !== '', 'on' => pm_ai_on()]); // המפתח עצמו לא ביומן
    return ['ai' => pm_ai_status()];
}

/** קריאה ל-Messages API. מחזיר את התשובה המפוענחת; שגיאה — PmError בעברית. */
function pm_ai_call(array $body): array
{
    $key = pm_ai_key();
    if ($key === '') throw new PmError('הסיווג החכם לא מוגדר — חסר מפתח (הגדרות ← כללי)', 409);
    $ch = curl_init(getenv('PM_AI_URL') ?: PM_AI_URL); // PM_AI_URL בסביבה — לבדיקות מול שרת מדומה
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01',
            // סירוב של מסווגי הבטיחות — הבקשה רצה שוב על מודל חלופי באותה קריאה
            'anthropic-beta: server-side-fallback-2026-07-01'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) { pm_log('warn', 'ai', 'ai.network', ['message' => $err]); throw new PmError('אין חיבור לשירות הסיווג — נסו שוב', 502); }
    $res = json_decode((string)$raw, true) ?: [];
    if ($code >= 400) {
        $msg = (string)($res['error']['message'] ?? '');
        pm_log('warn', 'ai', 'ai.error', ['message' => $msg, 'data' => ['status' => $code, 'type' => $res['error']['type'] ?? '']]);
        throw match (true) {
            $code === 401 || $code === 403 => new PmError('מפתח ה-API נדחה — בודקים אותו בהגדרות ← כללי', 502),
            $code === 429 || $code === 529 => new PmError('השירות עמוס כרגע — נסו שוב בעוד רגע', 503),
            $code === 400 && str_contains($msg, 'credit') => new PmError('נגמר הקרדיט בחשבון Anthropic', 502),
            default => new PmError('הסיווג נכשל ({code})', 502, ['code' => $code]),
        };
    }
    return $res;
}

/**
 * מסווג קובץ. $pages — עד 2 תמונות JPEG (base64, בלי "data:"), כבר מוקטנות בטלפון. $name — שם הקובץ (עוזר: "חשבונית_123.pdf").
 * מחזיר {ai: {...מה זוהה}, suggest: {project_id, project, folder_id, path, reason} | null}.
 */
function pm_ai_classify(array $user, array $in): array
{
    if (!pm_ai_on()) throw new PmError('הסיווג החכם כבוי', 409);
    $pages = array_values(array_filter(array_slice((array)($in['pages'] ?? []), 0, 2), 'is_string'));
    if (!$pages) throw new PmError('אין מה לסווג', 422);
    $total = 0;
    foreach ($pages as $p) {
        $len = strlen($p);
        if ($len < 100 || !preg_match('/^[A-Za-z0-9+\/=]+$/', $p)) throw new PmError('תמונה לא תקינה', 422);
        $total += $len;
    }
    if ($total > 6 * 1024 * 1024) throw new PmError('התמונה גדולה מדי לסיווג', 422);
    // מכסה יומית — שלא ייצא חשבון לא צפוי
    $countKey = 'ai_count_' . pm_today();
    $count = (int)pm_setting($countKey, '0');
    if ($count >= (int)pm_setting('ai_daily_max', '300')) throw new PmError('הגעתם למכסת הסיווגים היומית', 429);
    pm_setting_set($countKey, (string)($count + 1));

    [$projects, $suppliers] = pm_ai_context($user);
    // i18n-none-start — ההנחיה ל-Claude, לא טקסט למשתמש
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $content = [];
    foreach ($pages as $p) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $p]];
    $content[] = ['type' => 'text', 'text' => "שם הקובץ: " . ($name !== '' ? $name : '(אין)') . "\n\n"
        . "הפרויקטים הפתוחים (מזהה | שם | לקוח | כתובת):\n" . implode("\n", array_map(fn($p) => "{$p['id']} | {$p['name']} | {$p['client']} | {$p['address']}", $projects)) . "\n\n"
        . "הספקים (מזהה | שם | ח.פ./ע.מ.):\n" . implode("\n", array_map(fn($s) => "{$s['id']} | {$s['name']} | {$s['tax_id']}", $suppliers))];

    $nullable = fn(string $type) => ['anyOf' => [['type' => $type], ['type' => 'null']]];
    $schema = ['type' => 'object', 'additionalProperties' => false,
        'required' => ['doc_type', 'title', 'supplier_id', 'supplier_name', 'tax_id', 'amount', 'date', 'doc_number', 'project_id', 'project_reason'],
        'properties' => [
            'doc_type' => ['type' => 'string', 'enum' => PM_AI_DOC_TYPES],
            'title' => ['type' => 'string', 'description' => 'תיאור קצר בעברית, עד 8 מילים'],
            'supplier_id' => $nullable('integer'), 'supplier_name' => ['type' => 'string'], 'tax_id' => ['type' => 'string'],
            'amount' => $nullable('number'), 'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD או ריק'],
            'doc_number' => ['type' => 'string'], 'project_id' => $nullable('integer'), 'project_reason' => ['type' => 'string'],
        ]];
    $t0 = microtime(true);
    $res = pm_ai_call([
        'model' => PM_AI_MODEL,
        'max_tokens' => 4000,
        'fallbacks' => 'default',
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        'system' => "You classify files that a renovation/construction project manager in Israel shares into a project-management app. "
            . "Documents are usually in Hebrew. Identify what the file is: invoice (חשבונית / חשבונית מס), receipt (קבלה, אישור העברה, אסמכתת תשלום), "
            . "quote (הצעת מחיר), contract (חוזה / הסכם), plan (תוכנית, שרטוט, היתר), site_photo (a photo taken at the site, not a document), or other. "
            . "supplier = the business that issued the document. Pick supplier_id and project_id only from the lists given, when the document clearly matches "
            . "(name, tax id, address, client name); otherwise null — a wrong guess is worse than none. amount = the total to pay including VAT, in shekels; null if none. "
            . "title and project_reason in short Hebrew. Use empty strings for unknown text fields.",
        'messages' => [['role' => 'user', 'content' => $content]],
    ]);
    // i18n-none-end
    if (($res['stop_reason'] ?? '') === 'refusal') throw new PmError('השירות סירב לסווג את הקובץ', 422);
    $text = implode('', array_map(fn($b) => $b['text'] ?? '', array_filter($res['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text')));
    $ai = json_decode($text, true);
    if (!is_array($ai)) { pm_log('warn', 'ai', 'ai.parse', ['data' => ['stop' => $res['stop_reason'] ?? '']]); throw new PmError('לא התקבלה תשובה מהסיווג', 502); }
    pm_log('info', 'ai', 'ai.classify', ['data' => ['type' => $ai['doc_type'] ?? '', 'ms' => (int)((microtime(true) - $t0) * 1000),
        'in' => $res['usage']['input_tokens'] ?? 0, 'out' => $res['usage']['output_tokens'] ?? 0, 'model' => $res['model'] ?? '']]);

    // מה ש-Claude בחר — רק מתוך מה שמותר למשתמש
    $byP = array_column($projects, null, 'id');
    $byS = array_column($suppliers, null, 'id');
    $pid = isset($ai['project_id'], $byP[(int)$ai['project_id']]) ? (int)$ai['project_id'] : null;
    $sid = isset($ai['supplier_id'], $byS[(int)$ai['supplier_id']]) ? (int)$ai['supplier_id'] : null;
    $type = in_array($ai['doc_type'] ?? '', PM_AI_DOC_TYPES, true) ? $ai['doc_type'] : 'other';
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($ai['date'] ?? '')) ? $ai['date'] : '';
    $out = ['doc_type' => $type, 'file_type' => PM_AI_FILE_TYPE[$type], 'title' => mb_substr((string)($ai['title'] ?? ''), 0, 80),
        'supplier_id' => $sid, 'supplier' => $sid ? $byS[$sid]['name'] : mb_substr((string)($ai['supplier_name'] ?? ''), 0, 80),
        'amount' => is_numeric($ai['amount'] ?? null) ? round((float)$ai['amount'], 2) : null, 'date' => $date,
        'doc_number' => mb_substr((string)($ai['doc_number'] ?? ''), 0, 40), 'project_reason' => mb_substr((string)($ai['project_reason'] ?? ''), 0, 160)];
    return ['ai' => $out, 'suggest' => $pid ? pm_ai_folder($user, $pid, $type, $sid) + ['reason' => $out['project_reason']] : null];
}

/** הפרויקטים הפתוחים (גם משותפים) והספקים של בעלי הפרויקטים האלה — מה ש-Claude בוחר מתוכו. */
function pm_ai_context(array $user): array
{
    [$where, $args] = pm_project_clause($user, 'p');
    $st = pm_db()->prepare("SELECT p.id, p.name, p.address, p.owner_id, COALESCE(c.name, '') AS client FROM projects p LEFT JOIN contacts c ON c.id = p.client_id
                             WHERE $where AND p.status IN ('active','paused') ORDER BY p.last_activity_at DESC LIMIT 200");
    $st->execute($args);
    $projects = $st->fetchAll();
    $owners = array_unique(array_merge([(int)$user['id']], array_map('intval', array_column($projects, 'owner_id'))));
    $in = implode(',', $owners);
    $suppliers = pm_db()->query("SELECT id, name, tax_id FROM contacts WHERE kind = 'supplier' AND owner_id IN ($in) ORDER BY name LIMIT 500")->fetchAll();
    return [array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'client' => $p['client'], 'address' => $p['address']], $projects),
            array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'tax_id' => $s['tax_id']], $suppliers)];
}

/** לאיזו תיקייה בפרויקט: מסמך של ספק — לתיקייה שלו; תוכנית — "תוכניות והיתרים"; תמונה מהשטח — "תמונות מהשטח"; אחרת — "ממתין למיון". */
function pm_ai_folder(array $user, int $pid, string $type, ?int $sid): array
{
    $F = pm_folders($user, $pid);
    $byId = array_column($F, null, 'id');
    $pick = null;
    if ($sid && in_array($type, ['invoice', 'receipt', 'quote', 'contract'], true)) {
        foreach ($F as $f) if ($f['contact_id'] === $sid && in_array($f['kind'], ['ssup', 'contact'], true)) { $pick = $f; break; }
    }
    $kind = ['plan' => 'plans', 'site_photo' => 'photos'][$type] ?? 'inbox';
    if (!$pick) foreach ($F as $f) if ($f['kind'] === $kind && $f['parent_id'] === null) { $pick = $f; break; }
    if (!$pick) foreach ($F as $f) if ($f['kind'] === 'inbox') { $pick = $f; break; }
    $path = [];
    for ($x = $pick; $x; $x = $x['parent_id'] ? ($byId[$x['parent_id']] ?? null) : null) array_unshift($path, $x['system'] ? pm_t($x['name']) : $x['name']);
    $p = pm_project_row($user, $pid);
    return ['project_id' => $pid, 'project' => $p['name'], 'folder_id' => $pick ? (int)$pick['id'] : 0, 'icon' => $pick['icon'] ?? '📥', 'path' => implode(' / ', $path)];
}
