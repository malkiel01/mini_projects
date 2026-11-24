<?php
declare(strict_types=1);

/**
 * פרוסה 06 — AI מובנה: הגדרות (ספק, מפתח מוצפן, מודל, תקרה), הצ'אט מול הספק עם כלים, וכרטיסי הצעה.
 * האפיון: docs/spec/06-ai.md. נטען מ-ai/actions.php (שנטען מ-api.php). לא נקודת קצה: גישה ישירה → 404.
 *
 * הכללים שהקובץ אוכף:
 * - **הכלים = הפעולות של ext.php (05)**, דרך extRun() — אותה לוגיקה ואותן הרשאות, בהקשר של המשתמש המחובר. אין כאן
 *   שאילתה על תיקים ואין כתיבה ישירה. אין extRun (05 עוד לא מוזגה) → הכלים לא מוצעים לספק, והצ'אט אומר זאת.
 * - **שום כתיבה בלי ✓:** כלי כתיבה רץ ב-dry (05 עושה הכול ו-ROLLBACK) ומחזיר כרטיס. ✓ מריץ אותו באמת, via: ai
 *   (formSaveCase של 02 — "מאומת" נשמר "לאימות"). "אישור אוטומטי" (הגדרת ארגון, כבויה) מדלג על הכרטיס, לא על הכלל.
 * - **המפתח** — רק ב-secrets (מוצפן). לא חוזר בתשובה, לא ביומן הבקשות, לא ב-events; מוצגים רק 4 התווים האחרונים.
 * - **תקרה חודשית** — נבדקת לפני כל קריאה לספק; מעבר → הודעה, בלי קריאה.
 *
 * הספק — Anthropic Messages API ב-HTTP ישיר (curl): בשרת אין composer, ואין SDK רשמי שאפשר לפרוס בלי התקנה.
 * הטבלאות — CREATE IF NOT EXISTS, בלי מספר גרסה (כמו 05), כדי לא להתנגש במיגרציות של פרוסות אחרות.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
// הפעולות של ext.php (05) — מקור הכלים. אין את הקובץ (05 עוד לא מוזגה) → אין כלים, והצ'אט אומר זאת.
if (is_file(__DIR__ . '/../ext-lib.php')) require_once __DIR__ . '/../ext-lib.php';

/** הספקים והמודלים. המבנה מאפשר להוסיף ספק; היום — Claude בלבד (ש4). הראשון ברשימה = ברירת המחדל. */
const AI_PROVIDERS = [
    'anthropic' => ['name' => 'Claude (Anthropic)', 'base' => 'https://api.anthropic.com', 'models' => [
        'claude-opus-5-5' => 'Claude Opus 5.5 — מומלץ',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — מהיר וזול יותר',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 — הכי זול',
        'claude-fable-5-2' => 'Claude Fable 5.2 — החזק ביותר, יקר',
    ]],
];
/** מודלים שמקבלים fallbacks בצד השרת (כשהספק דוחה בקשה מסיבות בטיחות — מודל חלופי עונה). */
const AI_FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-2'];
const AI_DEFAULT_CAP = 500;
const AI_THREAD_DAYS = 30;
const AI_MAX_TOOL_ROUNDS = 8;
const AI_MAX_MESSAGE = 4000;
/** כלי כתיבה — לא כותבים, מחזירים הצעה. */
const AI_WRITE_TOOLS = ['case_update', 'case_create'];
/** פעולות של ext.php שאינן כלי לצ'אט (הפרומפט ל-GPT — לא רלוונטי כאן). */
const AI_SKIP_TOOLS = ['prompt'];

final class AiError extends RuntimeException {}

function aiSchema(PDO $pdo): void {
    static $ok = false;
    if ($ok) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_settings (org_id INTEGER PRIMARY KEY, provider TEXT NOT NULL, model TEXT NOT NULL,
        monthly_cap INTEGER NOT NULL, auto_approve INTEGER NOT NULL DEFAULT 0, key_hint TEXT NOT NULL DEFAULT \'\', key_set_at TEXT,
        key_check TEXT NOT NULL DEFAULT \'\', key_check_at TEXT, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_usage (org_id INTEGER NOT NULL, month TEXT NOT NULL, chats INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (org_id, month))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_threads (id TEXT PRIMARY KEY, org_id INTEGER NOT NULL, user_id INTEGER NOT NULL, case_id TEXT,
        title TEXT NOT NULL DEFAULT \'\', messages TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_threads_case ON ai_threads(org_id, case_id, updated_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id TEXT NOT NULL, org_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL, case_id TEXT, tool TEXT NOT NULL, input TEXT NOT NULL, card TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'pending\',
        result TEXT, created_at TEXT NOT NULL, decided_at TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_proposals_thread ON ai_proposals(thread_id)');
    $ok = true;
}

// ───────────────────────── הגדרות ─────────────────────────

function aiSettings(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare('SELECT * FROM ai_settings WHERE org_id = ?');
    $st->execute([$orgId]);
    $r = $st->fetch() ?: [];
    $provider = isset(AI_PROVIDERS[$r['provider'] ?? '']) ? $r['provider'] : array_key_first(AI_PROVIDERS);
    $models = AI_PROVIDERS[$provider]['models'];
    return ['provider' => $provider, 'model' => isset($models[$r['model'] ?? '']) ? $r['model'] : array_key_first($models),
        'monthly_cap' => (int)($r['monthly_cap'] ?? AI_DEFAULT_CAP), 'auto_approve' => (int)($r['auto_approve'] ?? 0) === 1,
        'key_hint' => (string)($r['key_hint'] ?? ''), 'key_set_at' => $r['key_set_at'] ?? null,
        'key_check' => (string)($r['key_check'] ?? ''), 'key_check_at' => $r['key_check_at'] ?? null];
}
function aiSettingsSave(PDO $pdo, int $orgId, array $s): void {
    $pdo->prepare('INSERT INTO ai_settings(org_id, provider, model, monthly_cap, auto_approve, key_hint, key_set_at, key_check, key_check_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(org_id) DO UPDATE SET provider=excluded.provider, model=excluded.model,
        monthly_cap=excluded.monthly_cap, auto_approve=excluded.auto_approve, key_hint=excluded.key_hint, key_set_at=excluded.key_set_at,
        key_check=excluded.key_check, key_check_at=excluded.key_check_at, updated_at=excluded.updated_at')
        ->execute([$orgId, $s['provider'], $s['model'], $s['monthly_cap'], $s['auto_approve'] ? 1 : 0, $s['key_hint'], $s['key_set_at'],
            $s['key_check'], $s['key_check_at'], gmdate('c')]);
}
function aiKey(int $orgId): ?string {
    $k = secretGet('ai_key', $orgId);
    return $k === null || $k === '' ? null : $k;
}
/** "מוגדר, מסתיים ב-…" — 4 התווים האחרונים בלבד. */
function aiKeyHint(string $key): string { return mb_substr($key, -4); }

/** ההגדרות כפי שהן מוצגות — בלי המפתח עצמו. */
function aiPublicSettings(PDO $pdo, int $orgId): array {
    $s = aiSettings($pdo, $orgId);
    $providers = [];
    foreach (AI_PROVIDERS as $id => $p) $providers[] = ['id' => $id, 'name' => $p['name'],
        'models' => array_map(fn($m, $n) => ['id' => $m, 'name' => $n], array_keys($p['models']), $p['models'])];
    return ['provider' => $s['provider'], 'model' => $s['model'], 'monthly_cap' => $s['monthly_cap'], 'auto_approve' => $s['auto_approve'],
        'has_key' => aiKey($orgId) !== null, 'key_hint' => $s['key_hint'], 'key_set_at' => $s['key_set_at'],
        'key_check' => $s['key_check'], 'key_check_at' => $s['key_check_at'],
        'used' => aiUsed($pdo, $orgId), 'month' => gmdate('Y-m'), 'providers' => $providers, 'tools_ready' => aiToolsReady()];
}

// ───────────────────────── תקרה ─────────────────────────

function aiUsed(PDO $pdo, int $orgId): int {
    $st = $pdo->prepare('SELECT chats FROM ai_usage WHERE org_id = ? AND month = ?');
    $st->execute([$orgId, gmdate('Y-m')]);
    return (int)$st->fetchColumn();
}
/** תופס יחידה מהתקרה (אטומית). false = התקרה מוצתה — אין קריאה לספק. */
function aiTakeQuota(PDO $pdo, int $orgId, int $cap): bool {
    $month = gmdate('Y-m');
    $pdo->prepare('INSERT OR IGNORE INTO ai_usage(org_id, month, chats) VALUES (?,?,0)')->execute([$orgId, $month]);
    $st = $pdo->prepare('UPDATE ai_usage SET chats = chats + 1 WHERE org_id = ? AND month = ? AND chats < ?');
    $st->execute([$orgId, $month, $cap]);
    return $st->rowCount() === 1;
}

// ───────────────────────── הספק ─────────────────────────

/** כתובת הספק. AI_API_BASE — בסביבת הבדיקה בלבד, ורק 127.0.0.1: גם אם יוגדר בשרת בטעות, המפתח לא יוצא החוצה. */
function aiBase(string $provider): string {
    $env = getenv('AI_API_BASE');
    if (is_string($env) && preg_match('~^http://127\.0\.0\.1:\d{2,5}$~D', $env)) return $env;
    return AI_PROVIDERS[$provider]['base'];
}

/**
 * קריאה אחת ל-Messages API. מחזיר [status, json-object|null]. אין מפתח בשום הודעת שגיאה (מוחלף ב-***).
 * התוכן נשמר כאובייקטים (stdClass) — כך בלוקים שחוזרים לספק (thinking, tool_use עם input ריק) נשלחים בדיוק כמו שהגיעו.
 */
function aiHttp(string $provider, string $key, array $payload, int $timeout = 120): array {
    $headers = ['content-type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . $key];
    if (isset($payload['fallbacks'])) $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    $ch = curl_init(aiBase($provider) . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new AiError('אין חיבור לספק: ' . str_replace($key, '***', $err));
    $json = json_decode((string)$raw, false);
    return [$status, is_object($json) ? $json : null];
}
/** הודעת שגיאה של הספק, בעברית, בלי המפתח. */
function aiProviderError(int $status, ?object $j, string $key): string {
    $msg = str_replace($key, '***', (string)($j->error->message ?? ''));
    $type = (string)($j->error->type ?? '');
    if ($status === 401 || $type === 'authentication_error') return 'המפתח לא תקין. מנהל הארגון מחליף אותו ב-⚙️ הגדרות ← בינה מלאכותית.';
    if ($status === 403 || $type === 'permission_error') return 'למפתח אין הרשאה למודל הזה.';
    if (aiNoCredit($status, $msg)) return 'אין יתרה בחשבון הספק. מנהל הארגון מטעין קרדיט בחשבון.';
    if ($status === 429) return 'הספק עמוס כרגע (מגבלת קצב). לנסות שוב בעוד דקה.';
    if ($status >= 500) return 'הספק לא זמין כרגע. לנסות שוב מאוחר יותר.';
    return 'הספק החזיר שגיאה (' . $status . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '');
}
function aiNoCredit(int $status, string $msg): bool {
    return ($status === 400 || $status === 402) && preg_match('/credit balance|billing|insufficient/i', $msg) === 1;
}

/** "בדיקת מפתח": קריאה קטנה. תשובה: ok / invalid / no_credit / error. */
function aiKeyTest(string $provider, string $model, string $key): array {
    try {
        [$status, $j] = aiHttp($provider, $key, ['model' => $model, 'max_tokens' => 32,
            'output_config' => ['effort' => 'low'], 'messages' => [['role' => 'user', 'content' => 'ping']]], 30);
    } catch (AiError $e) {
        return ['result' => 'error', 'message' => $e->getMessage()];
    }
    if ($status === 200) return ['result' => 'ok', 'message' => 'המפתח תקין'];
    $msg = (string)($j->error->message ?? '');
    if ($status === 401 || $status === 403) return ['result' => 'invalid', 'message' => 'המפתח לא תקין'];
    if (aiNoCredit($status, $msg)) return ['result' => 'no_credit', 'message' => 'המפתח תקין, אבל אין יתרה בחשבון'];
    return ['result' => 'error', 'message' => aiProviderError($status, $j, $key)];
}

// ───────────────────────── הכלים = הפעולות של ext.php ─────────────────────────

/** הכלים קיימים רק כשהשכבה המשותפת של 05 קיימת. */
function aiToolsReady(): bool { return function_exists('extActions') && function_exists('extRun'); }

/** הגדרות הכלים לספק — נוצרות מטבלת הפעולות של 05 (extActions), לא נכתבות כאן. */
function aiToolDefs(): array {
    if (!aiToolsReady()) return [];
    $tools = [];
    foreach (extActions() as $name => $a) {
        if (in_array($name, AI_SKIP_TOOLS, true)) continue;
        if ($a['method'] === 'POST') {
            $schema = $a['body'];
        } else {
            $schema = ['type' => 'object', 'properties' => [], 'required' => []];
            foreach ($a['query'] as $q) {
                $schema['properties'][$q['name']] = ['type' => 'string', 'description' => $q['description'] ?? ''] + (isset($q['enum']) ? ['enum' => $q['enum']] : []);
                if (!empty($q['required'])) $schema['required'][] = $q['name'];
            }
        }
        $desc = $a['he'] . ' / ' . $a['en'];
        if (in_array($name, AI_WRITE_TOOLS, true)) {
            // מאיפה הערך — כדי שהכרטיס יראה "מאיזה קובץ". לא עובר ל-ext.
            $schema['properties']['source'] = ['type' => 'string', 'description' => 'מקור הערכים: שם הקובץ, או "דברי המשתמש" / Source of the values: file name, or "user said"'];
            $desc .= '. לא נשמר מיד: מוצג למשתמש כהצעה לאישור / Not saved immediately: shown to the user as a proposal to approve';
        }
        if ($schema['properties'] === []) $schema['properties'] = new stdClass();
        if (($schema['required'] ?? null) === []) unset($schema['required']);
        $tools[] = ['name' => $name, 'description' => $desc, 'input_schema' => $schema];
    }
    return $tools;
}

/** ה"חבר" ש-extRun מקבל — מההקשר של המשתמש המחובר (01), לא מאסימון. */
function aiMember(): array {
    $c = ctx();
    return ['user_id' => $c['user']['id'], 'name' => $c['user']['name'], 'email' => $c['user']['email'],
        'org_id' => (int)$c['org']['id'], 'org_name' => (string)$c['org']['name'], 'role' => $c['role']];
}

/**
 * מריץ פעולה של ext.php בהקשר של המשתמש המחובר, ולוכד את התשובה במקום לצאת (reply() במצב לכידה).
 * מחזיר [status, data]. ה-actor ב-events: שם המשתמש + " (AI)". טרנזקציה שנשארה פתוחה (שגיאה באמצע) — מתבטלת.
 */
function aiExt(PDO $pdo, string $action, array $input, bool $dry): array {
    $def = extActions()[$action] ?? null;
    if ($def === null) return [404, ['error' => 'כלי לא מוכר: ' . $action]];
    $query = $body = [];
    if ($def['method'] === 'POST') $body = $input; else foreach ($input as $k => $v) if (is_scalar($v)) $query[$k] = (string)$v;
    $saveActor = $GLOBALS['fd_ctx']['actor'];
    $GLOBALS['fd_ctx']['actor'] = ['id' => $saveActor['id'], 'actor' => aiActorName()];
    $GLOBALS['fd_reply_capture'] = true;
    try {
        extRun($pdo, aiMember(), $action, $query, $body, 'ai', $dry);
        return [500, ['error' => 'הפעולה לא החזירה תשובה']];
    } catch (FdReply $r) {
        return [$r->status, $r->data];
    } catch (Throwable $e) {
        error_log('funerals-dashboard ai tool ' . $action . ': ' . $e->getMessage());
        return [500, ['error' => 'הכלי נכשל']];
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        unset($GLOBALS['fd_reply_capture']);
        $GLOBALS['fd_ctx']['actor'] = $saveActor;
        unset($GLOBALS['fd_err']);
    }
}
function aiActorName(): string {
    $u = ctx()['user'];
    return ($u['name'] !== '' ? $u['name'] : ($u['email'] !== '' ? $u['email'] : 'משתמש ' . $u['id'])) . ' (AI)';
}

/** שורות "לפני ← אחרי" לכרטיס: מה ביקשו מול התיק כפי שהוא, ומה יישמר בפועל (מה-dry של 05). */
function aiCard(PDO $pdo, string $tool, array $input, array $dryResult, int $orgId): array {
    $rows = [];
    $before = null;
    if ($tool === 'case_update') {
        $id = (string)($input['id'] ?? '');
        $before = preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id) ? existing($pdo, $id, $orgId) : null;
        $labels = function_exists('extActions') && defined('EXT_CHECKS') ? EXT_CHECKS : [];
        $statusNames = defined('EXT_STATUSES') ? EXT_STATUSES : FORM_STATUS_LABELS;
        foreach (($dryResult['saved']['fields'] ?? []) as $f => $v) {
            $rows[] = ['label' => aiFieldLabel($f), 'before' => aiShow($before[$f] ?? ''), 'after' => aiShow($v)];
        }
        if (($dryResult['saved']['note'] ?? null) !== null) $rows[] = ['label' => 'הערה חדשה', 'before' => '', 'after' => (string)$dryResult['saved']['note']];
        foreach (($dryResult['saved']['checks'] ?? []) as $k => $c) {
            $b = $before['checks'][$k] ?? ['status' => 'unknown', 'value' => ''];
            $fmt = fn($s, $v) => ($statusNames[$s] ?? $s) . ((string)$v !== '' ? ' — ' . $v : '');
            $rows[] = ['label' => $labels[$k] ?? ($c['name'] ?? $k), 'before' => $fmt($b['status'] ?? 'unknown', $b['value'] ?? ''),
                'after' => $fmt($c['status'] ?? '', $c['value'] ?? '')];
        }
    } else {
        foreach (['name', 'date', 'time', 'origin', 'team', 'grave', 'note'] as $f) {
            if ((string)($input[$f] ?? '') !== '') $rows[] = ['label' => aiFieldLabel($f), 'before' => '', 'after' => aiShow($input[$f])];
        }
    }
    return ['title' => $tool === 'case_create' ? 'פתיחת תיק חדש' : 'עדכון התיק ' . (string)($before['name'] ?? ''),
        'case_id' => $tool === 'case_update' ? (string)($input['id'] ?? '') : null,
        'source' => mb_substr(cleanText($input['source'] ?? '', 300), 0, 300), 'rows' => $rows,
        'converted' => array_map(fn($c) => ['name' => $c['name'] ?? '', 'reason' => $c['reason'] ?? ''], $dryResult['converted'] ?? [])];
}
function aiFieldLabel(string $f): string {
    return ['name' => 'שם הנפטר', 'date' => 'תאריך', 'time' => 'שעה', 'origin' => 'מקום', 'team' => 'צוות', 'grave' => 'מיקום קבר',
        'notes' => 'הערות', 'folder_url' => 'תיקיית Dropbox', 'payments' => 'תשלומים', 'note' => 'הערה'][$f] ?? $f;
}
function aiShow(mixed $v): string {
    if (is_array($v)) return implode(' · ', array_map(fn($p) => is_array($p) ? trim(($p['amount'] ?? '') . ' ' . ($p['purpose'] ?? '') . ' ' . ($p['reference'] ?? '')) : (string)$p, $v));
    return (string)$v;
}

/**
 * כלי אחד שהספק ביקש. קריאה → ext כרגיל. כתיבה → dry (ולידציה והרשאה כמו ב-API), ואז הצעה (או, באישור אוטומטי, כתיבה).
 * מחזיר [tool_result content (string), is_error, proposal|null].
 */
function aiRunTool(PDO $pdo, array $thread, array $settings, string $name, array $input): array {
    if (!aiToolsReady() || in_array($name, AI_SKIP_TOOLS, true) || !isset(extActions()[$name])) {
        return [json_encode(['error' => 'כלי לא מוכר: ' . $name], JSON_UNESCAPED_UNICODE), true, null];
    }
    $orgId = (int)$thread['org_id'];
    $clean = $input;
    unset($clean['source']);
    if (!in_array($name, AI_WRITE_TOOLS, true)) {
        [$st, $data] = aiExt($pdo, $name, $clean, false);
        return [aiToolJson($data), $st >= 400, null];
    }
    [$st, $data] = aiExt($pdo, $name, $clean, true);
    if ($st >= 400) return [aiToolJson($data + ['status' => $st, 'saved' => false]), true, null];
    $card = aiCard($pdo, $name, $input, $data, $orgId);
    if ($settings['auto_approve']) {
        [$st2, $data2] = aiExt($pdo, $name, $clean, false);
        $pid = aiProposalAdd($pdo, $thread, $name, $clean, $card, $st2 < 400 ? 'applied' : 'failed', $data2);
        return [aiToolJson(['status' => $st2 < 400 ? 'saved' : 'failed', 'auto_approved' => true] + $data2), $st2 >= 400,
            aiProposalGet($pdo, $pid, $orgId)];
    }
    $pid = aiProposalAdd($pdo, $thread, $name, $clean, $card, 'pending', null);
    return [aiToolJson(['status' => 'proposed', 'proposal_id' => $pid, 'saved' => false,
        'message' => 'ההצעה הוצגה למשתמש עם ✓ / ✗. שום דבר לא נשמר עד שיאשר. לא לומר שנשמר.',
        'would_save' => $data['saved'] ?? null, 'converted' => $data['converted'] ?? []]), false, aiProposalGet($pdo, $pid, $orgId)];
}
function aiToolJson(array $data): string {
    return mb_strcut(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 100000);
}

// ───────────────────────── הצעות ─────────────────────────

function aiProposalAdd(PDO $pdo, array $thread, string $tool, array $input, array $card, string $status, ?array $result): int {
    $pdo->prepare('INSERT INTO ai_proposals(thread_id, org_id, user_id, case_id, tool, input, card, status, result, created_at, decided_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$thread['id'], $thread['org_id'], $thread['user_id'], $card['case_id'], $tool,
        json_encode($input, JSON_UNESCAPED_UNICODE), json_encode($card, JSON_UNESCAPED_UNICODE), $status,
        $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE), gmdate('c'), $status === 'pending' ? null : gmdate('c')]);
    return (int)$pdo->lastInsertId();
}
function aiProposalGet(PDO $pdo, int $id, int $orgId): ?array {
    $st = $pdo->prepare('SELECT * FROM ai_proposals WHERE id = ? AND org_id = ?');
    $st->execute([$id, $orgId]);
    $r = $st->fetch();
    if (!$r) return null;
    return ['id' => (int)$r['id'], 'thread_id' => $r['thread_id'], 'user_id' => (int)$r['user_id'], 'tool' => $r['tool'],
        'case_id' => $r['case_id'], 'status' => $r['status'], 'card' => json_decode((string)$r['card'], true),
        'input' => json_decode((string)$r['input'], true), 'created_at' => $r['created_at'], 'decided_at' => $r['decided_at'],
        'message' => $r['result'] ? (string)(json_decode((string)$r['result'], true)['message'] ?? '') : ''];
}
/** ✓ או ✗ — רק מי שקיבל את ההצעה, ורק פעם אחת. ✓ מריץ את הכלי באמת (via: ai), באותן הרשאות. */
function aiDecide(PDO $pdo, int $id, bool $approve): array {
    $c = ctx();
    $orgId = (int)$c['org']['id'];
    $p = aiProposalGet($pdo, $id, $orgId);
    if ($p === null || $p['user_id'] !== (int)$c['user']['id']) throw new AiError('ההצעה לא נמצאה', 404);
    if ($p['status'] !== 'pending') throw new AiError('ההצעה כבר טופלה', 409);
    // תופסים את ההצעה לפני הכתיבה — שתי לחיצות לא כותבות פעמיים
    $st = $pdo->prepare("UPDATE ai_proposals SET status = 'deciding' WHERE id = ? AND status = 'pending'");
    $st->execute([$id]);
    if ($st->rowCount() !== 1) throw new AiError('ההצעה כבר טופלה', 409);
    if (!$approve) {
        $pdo->prepare("UPDATE ai_proposals SET status = 'rejected', decided_at = ? WHERE id = ?")->execute([gmdate('c'), $id]);
        return ['proposal' => aiProposalGet($pdo, $id, $orgId)];
    }
    if (!aiToolsReady()) {
        $pdo->prepare("UPDATE ai_proposals SET status = 'pending' WHERE id = ?")->execute([$id]);
        throw new AiError('ה-API החיצוני (פרוסה 05) עוד לא מותקן', 501);
    }
    [$status, $data] = aiExt($pdo, $p['tool'], $p['input'], false);
    $pdo->prepare('UPDATE ai_proposals SET status = ?, result = ?, decided_at = ? WHERE id = ?')
        ->execute([$status < 400 ? 'applied' : 'failed', json_encode($data, JSON_UNESCAPED_UNICODE), gmdate('c'), $id]);
    return ['proposal' => aiProposalGet($pdo, $id, $orgId), 'status' => $status, 'result' => $data];
}

// ───────────────────────── שיחות ─────────────────────────

function aiThreadNew(PDO $pdo, int $orgId, int $userId, ?string $caseId): array {
    $t = ['id' => 'ait_' . bin2hex(random_bytes(12)), 'org_id' => $orgId, 'user_id' => $userId, 'case_id' => $caseId, 'title' => '',
        'messages' => [], 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')];
    $pdo->prepare('INSERT INTO ai_threads(id, org_id, user_id, case_id, title, messages, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$t['id'], $orgId, $userId, $caseId, '', '[]', $t['created_at'], $t['updated_at']]);
    return $t;
}
/** שיחה של המשתמש בארגון הזה בלבד (של אחר — כאילו לא קיימת). messages — אובייקטים, כמו שהספק החזיר. */
function aiThreadGet(PDO $pdo, string $id, int $orgId, int $userId): ?array {
    $st = $pdo->prepare('SELECT * FROM ai_threads WHERE id = ? AND org_id = ? AND user_id = ?');
    $st->execute([$id, $orgId, $userId]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['messages'] = json_decode((string)$r['messages'], false) ?: [];
    $r['org_id'] = (int)$r['org_id'];
    $r['user_id'] = (int)$r['user_id'];
    return $r;
}
function aiThreadSave(PDO $pdo, array $t): void {
    $pdo->prepare('UPDATE ai_threads SET title = ?, messages = ?, updated_at = ? WHERE id = ?')
        ->execute([$t['title'], json_encode($t['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), gmdate('c'), $t['id']]);
}
/** שיחות ישנות מ-30 יום — נמחקות (האפיון 06 §2.2.4). אלה שיחות צ'אט, לא נתוני תיקים. */
function aiPurge(PDO $pdo): void {
    $cut = gmdate('c', time() - AI_THREAD_DAYS * 86400);
    $pdo->prepare('DELETE FROM ai_proposals WHERE thread_id IN (SELECT id FROM ai_threads WHERE updated_at < ?)')->execute([$cut]);
    $pdo->prepare('DELETE FROM ai_threads WHERE updated_at < ?')->execute([$cut]);
}
/** השיחה לתצוגה: רק טקסט של המשתמש ושל העוזר, וכרטיסי ההצעה במקומם. */
function aiThreadView(PDO $pdo, array $t): array {
    $out = [];
    foreach ($t['messages'] as $m) {
        if (is_string($m->content)) { $out[] = ['role' => $m->role, 'text' => aiStripNotice($m->content)]; continue; }
        $text = '';
        foreach ($m->content as $b) if (($b->type ?? '') === 'text') $text .= ($text === '' ? '' : "\n") . $b->text;
        if ($m->role === 'user' && $text !== '') $out[] = ['role' => 'user', 'text' => aiStripNotice($text)];
        if ($m->role === 'assistant' && $text !== '') $out[] = ['role' => 'assistant', 'text' => $text];
    }
    $st = $pdo->prepare('SELECT id FROM ai_proposals WHERE thread_id = ? ORDER BY id');
    $st->execute([$t['id']]);
    $proposals = array_map(fn($id) => aiProposalGet($pdo, (int)$id, $t['org_id']), $st->fetchAll(PDO::FETCH_COLUMN));
    return ['id' => $t['id'], 'case_id' => $t['case_id'], 'title' => $t['title'], 'messages' => $out, 'proposals' => $proposals];
}
/** הודעת מצב שהשרת מצרף להודעת המשתמש (הכרעות על הצעות) — לא מוצגת לו. */
function aiStripNotice(string $s): string { return (string)preg_replace('/^\[מערכת:[^\]]*\]\n/u', '', $s); }

/** הודעת המערכת — בקוד (האפיון 06 §2.2.5). */
function aiSystemPrompt(array $thread, ?array $case): string {
    $c = ctx();
    $role = $c['role'] ? ROLES[$c['role']] : ($c['dev'] ? 'מפתח' : '');
    $p = "אתה עוזר בתוך האפליקציה \"ניהול לוויות חברה קדישא\". אתה עונה לעובד של הארגון \"{$c['org']['name']}\" "
        . "({$c['user']['name']}, תפקיד: {$role}). המטרה: לעזור לסגור תיקי לוויות — מה חסר, מה לאימות, ולמלא ממסמכים.\n\n"
        . "כללים:\n"
        . "1. עונים בעברית, קצר וברור, כמו לאדם שעובד מהטלפון.\n"
        . "2. לא מנחשים. ערך שלא מופיע במסמך, בתיק או בדברי המשתמש — לא כותבים. כשיש ספק — שואלים.\n"
        . "3. לכל ערך מציינים מאיפה הוא (שם הקובץ, או \"לפי דבריך\"), ובכלי כתיבה — בשדה source.\n"
        . "4. לפני עדכון תיק לפי שם — find. יותר מתוצאה אחת — שואלים איזה (לפי תאריך ומקום).\n"
        . "5. כלי כתיבה (case_update, case_create) לא שומר: הוא מציג למשתמש כרטיס הצעה עם ✓ / ✗. אחרי הכלי אומרים \"הצעתי — "
        . "אשר בכרטיס\", ולעולם לא \"נשמר\".\n"
        . "6. \"מאומת\" נקבע רק בידי אדם. הצעה שלך לסטטוס סופי נשמרת \"לאימות\" — וזה בסדר; אומרים זאת למשתמש.\n"
        . "7. כלי שהחזיר שגיאה (אין הרשאה, לא נמצא) — אומרים למשתמש בפשטות, ולא מנסים לעקוף.\n"
        . "8. אין מחיקה. אין גישה לשום דבר שאין למשתמש.\n";
    if ($case !== null) {
        $p .= "\nההקשר: התיק \"{$case['name']}\" (id: {$case['id']}, תאריך: " . ($case['date'] ?: '—') . ", חודש: {$case['month']}). "
            . "השאלות הן על התיק הזה, אלא אם המשתמש אומר אחרת. לפרטים המלאים — הכלי case עם ה-id הזה.";
    } else {
        $p .= "\nההקשר: הארגון כולו, החודש " . gmdate('Y-m') . ". רשימת התיקים — הכלי cases.";
    }
    return $p;
}

/**
 * הודעה אחת של המשתמש → תשובה. תופס יחידה מהתקרה (לפני כל קריאה לספק), מריץ את לולאת הכלים עד AI_MAX_TOOL_ROUNDS,
 * ושומר את השיחה. מחזיר ['reply' => טקסט, 'proposals' => [...], 'thread' => id].
 */
function aiChat(PDO $pdo, array $thread, string $text): array {
    $orgId = (int)$thread['org_id'];
    $s = aiSettings($pdo, $orgId);
    $key = aiKey($orgId);
    if ($key === null) return ['ok' => false, 'code' => 'no_key', 'message' => 'לא הוגדר מפתח — פנה למנהל'];
    if (!aiTakeQuota($pdo, $orgId, $s['monthly_cap'])) {
        return ['ok' => false, 'code' => 'cap', 'message' => 'הגעתם לתקרה החודשית של הארגון (' . $s['monthly_cap']
            . ' שיחות). מנהל הארגון יכול להגדיל אותה ב-⚙️ הגדרות ← בינה מלאכותית.'];
    }
    $case = null;
    if ($thread['case_id'] !== null) {
        $case = existing($pdo, (string)$thread['case_id'], $orgId);
        if ($case === null) throw new AiError('התיק לא נמצא', 404);
    }
    // הכרעות על הצעות מאז ההודעה הקודמת — כדי שהעוזר ידע מה נשמר ומה נדחה
    $st = $pdo->prepare("SELECT id, status FROM ai_proposals WHERE thread_id = ? AND status IN ('applied','rejected','failed') AND decided_at >= ?");
    $st->execute([$thread['id'], $thread['updated_at']]);
    $notes = array_map(fn($r) => '#' . $r['id'] . ' ' . ['applied' => 'אושרה ונשמרה', 'rejected' => 'נדחתה', 'failed' => 'נכשלה בשמירה'][$r['status']], $st->fetchAll());
    $content = ($notes ? '[מערכת: הכרעות על הצעות — ' . implode(', ', $notes) . "]\n" : '') . $text;
    $thread['messages'][] = (object)['role' => 'user', 'content' => $content];
    if ($thread['title'] === '') $thread['title'] = mb_substr($text, 0, 80);

    $payload = ['model' => $s['model'], 'max_tokens' => 16000, 'system' => aiSystemPrompt($thread, $case),
        'output_config' => ['effort' => 'medium']];
    $tools = aiToolDefs();
    if ($tools) $payload['tools'] = $tools;
    if (in_array($s['model'], AI_FALLBACK_MODELS, true)) $payload['fallbacks'] = 'default';

    $proposals = [];
    $reply = '';
    for ($round = 0; ; $round++) {
        $payload['messages'] = $thread['messages'];
        [$status, $j] = aiHttp($s['provider'], $key, $payload);
        if ($status !== 200 || $j === null) {
            aiThreadSave($pdo, $thread);
            throw new AiError(aiProviderError($status, $j, $key), 502);
        }
        $thread['messages'][] = (object)['role' => 'assistant', 'content' => $j->content ?? []];
        $stop = (string)($j->stop_reason ?? '');
        foreach (($j->content ?? []) as $b) if (($b->type ?? '') === 'text') $reply .= ($reply === '' ? '' : "\n") . $b->text;
        if ($stop === 'refusal') { $reply = $reply !== '' ? $reply : 'הספק סירב לענות על הבקשה הזו. לנסח אחרת.'; break; }
        if ($stop !== 'tool_use') break;
        if ($round >= AI_MAX_TOOL_ROUNDS) { $reply .= ($reply === '' ? '' : "\n") . '(עצרתי — יותר מדי צעדים בבקשה אחת. לנסות בקשה מצומצמת יותר.)'; break; }
        $results = [];
        foreach ($j->content as $b) {
            if (($b->type ?? '') !== 'tool_use') continue;
            $input = json_decode(json_encode($b->input ?? new stdClass()), true) ?: [];
            [$res, $isErr, $prop] = aiRunTool($pdo, $thread, $s, (string)$b->name, $input);
            if ($prop !== null) $proposals[] = $prop;
            $r = ['type' => 'tool_result', 'tool_use_id' => $b->id, 'content' => $res];
            if ($isErr) $r['is_error'] = true;
            $results[] = (object)$r;
        }
        $thread['messages'][] = (object)['role' => 'user', 'content' => $results];
    }
    aiThreadSave($pdo, $thread);
    return ['ok' => true, 'thread' => $thread['id'], 'reply' => $reply, 'proposals' => $proposals, 'used' => aiUsed($pdo, $orgId),
        'tools_ready' => aiToolsReady()];
}
