<?php
declare(strict_types=1);

/**
 * פרוסה 06 — AI מובנה: הגדרות (ספק, מפתח מוצפן, מודל, תקרה), הצ'אט מול הספק עם כלים, וכרטיסי הצעה.
 * האפיון: docs/spec/06-ai.md. נטען מ-ai/actions.php (שנטען מ-api.php). לא נקודת קצה: גישה ישירה → 404.
 *
 * הכללים שהקובץ אוכף:
 * - **הכלים = הפעולות של ext.php (05)**, דרך extDo() — אותה לוגיקה ואותן הרשאות, בהקשר של המשתמש המחובר. אין כאן
 *   שאילתה על תיקים ואין כתיבה ישירה. אין extDo → הכלים לא מוצעים לספק, והצ'אט אומר זאת.
 * - **שום כתיבה בלי ✓:** כלי כתיבה רץ ב-dry (05 עושה הכול ו-ROLLBACK) ומחזיר כרטיס. ✓ מריץ אותו באמת, via: ai
 *   (formSaveCase של 02 — "מאומת" נשמר "לאימות"). "אישור אוטומטי" (הגדרת ארגון, כבויה) מדלג על הכרטיס, לא על הכלל.
 * - **המפתח** — רק ב-secrets (מוצפן). לא חוזר בתשובה, לא ביומן הבקשות, לא ב-events; מוצגים רק 4 התווים האחרונים.
 * - **תקרה חודשית** — נבדקת לפני כל קריאה לספק; מעבר → הודעה, בלי קריאה.
 *
 * הספק — Anthropic Messages API ב-HTTP ישיר (curl): בשרת אין composer, ואין SDK רשמי שאפשר לפרוס בלי התקנה.
 * הטבלאות — מיגרציה v4 ב-lib/schema.php.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
// הפעולות של ext.php (05) — מקור הכלים. אין את הקובץ (05 עוד לא מוזגה) → אין כלים, והצ'אט אומר זאת.
if (is_file(__DIR__ . '/../ext-lib.php')) require_once __DIR__ . '/../ext-lib.php';

/** הספקים והמודלים. המבנה מאפשר להוסיף ספק; היום — Claude בלבד (ש4). הראשון ברשימה = ברירת המחדל. */
const AI_PROVIDERS = [
    'anthropic' => ['name' => 'Claude (Anthropic)', 'base' => 'https://api.anthropic.com', 'models' => [
        'claude-opus-5-5' => 'Claude Opus 5.5',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'claude-haiku-5-5' => 'Claude Haiku 5.5',
        'claude-fable-5-2' => 'Claude Fable 5.2',
    ]],
];
/** מודלים שמקבלים fallbacks בצד השרת (כשהספק דוחה בקשה מסיבות בטיחות — מודל חלופי עונה). */
const AI_FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-2'];
const AI_DEFAULT_CAP = 500;
/** תקרת טוקנים חודשית לארגון (קלט + פלט, כולל מטמון) — מעבר → עצירה לפני הקריאה הבאה לספק. */
const AI_DEFAULT_TOKEN_CAP = 10000000;
/** במקום קובץ שנשלח לספק — הטקסט שנשמר בשיחה (התוכן עצמו לא נשמר; הקבצים נשארים ב-Dropbox). */
const AI_FILE_MARK = '[קובץ נקרא בסבב קודם — התוכן לא נשמר בשיחה. לקריאה חוזרת: file_view]';
/** ההצעה "בטיפול" יותר מזה (נפילה באמצע ✓) — חוזרת להמתנה. */
const AI_DECIDING_STALE_SEC = 120;
const AI_THREAD_DAYS = 30;
const AI_MAX_TOOL_ROUNDS = 8;
const AI_MAX_MESSAGE = 4000;
/** כלי כתיבה — לא כותבים, מחזירים הצעה. */
const AI_WRITE_TOOLS = ['case_update', 'case_create'];
/** פעולות של ext.php שאינן כלי לצ'אט (הפרומפט ל-GPT — לא רלוונטי כאן). */
const AI_SKIP_TOOLS = ['prompt'];

/** קובץ לספק (06 §2.2.2): תמונה או PDF (גם סרוק), עד 5MB לקובץ, 10 קבצים לשיחה — ועד 20MB לשיחה, כדי שהבקשה לספק לא תעבור 32MB. */
const AI_FILE_MAX = 5 * 1024 * 1024;
const AI_FILES_PER_THREAD = 10;
const AI_FILES_BYTES_PER_THREAD = 20 * 1024 * 1024;
const AI_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
/** סוגי מסמך לשיוך קובץ (06 §2.3) — הבדיקות של 05 שהן מסמך, ועוד "אחר". השם = השם של הבדיקה. */
const AI_DOC_CHECKS = ['license_document', 'identity_document', 'payment_evidence', 'hall_service', 'grave_purchase', 'partner_identity', 'national_insurance'];
/** קובץ חדש = נוסף ב-7 הימים האחרונים. הצעה אוטומטית — לכל היותר 2 קבצים בכל פתיחת תיק. */
const AI_KIND_NEW_DAYS = 7;
const AI_KIND_PER_OPEN = 2;

final class AiError extends RuntimeException {}

// הטבלאות — מיגרציה v4 ב-lib/schema.php (רצה ב-db()).

// ───────────────────────── הגדרות ─────────────────────────

function aiSettings(PDO $pdo, int $orgId): array {
    $st = $pdo->prepare('SELECT * FROM ai_settings WHERE org_id = ?');
    $st->execute([$orgId]);
    $r = $st->fetch() ?: [];
    $provider = isset(AI_PROVIDERS[$r['provider'] ?? '']) ? $r['provider'] : array_key_first(AI_PROVIDERS);
    $models = AI_PROVIDERS[$provider]['models'];
    return ['provider' => $provider, 'model' => isset($models[$r['model'] ?? '']) ? $r['model'] : array_key_first($models),
        'monthly_cap' => (int)($r['monthly_cap'] ?? AI_DEFAULT_CAP), 'auto_approve' => (int)($r['auto_approve'] ?? 0) === 1,
        'token_cap' => (int)($r['token_cap'] ?? AI_DEFAULT_TOKEN_CAP),
        'key_hint' => (string)($r['key_hint'] ?? ''), 'key_set_at' => $r['key_set_at'] ?? null,
        'key_check' => (string)($r['key_check'] ?? ''), 'key_check_at' => $r['key_check_at'] ?? null];
}
function aiSettingsSave(PDO $pdo, int $orgId, array $s): void {
    $pdo->prepare('INSERT INTO ai_settings(org_id, provider, model, monthly_cap, auto_approve, key_hint, key_set_at, key_check, key_check_at, updated_at, token_cap)
        VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(org_id) DO UPDATE SET provider=excluded.provider, model=excluded.model,
        monthly_cap=excluded.monthly_cap, auto_approve=excluded.auto_approve, key_hint=excluded.key_hint, key_set_at=excluded.key_set_at,
        key_check=excluded.key_check, key_check_at=excluded.key_check_at, updated_at=excluded.updated_at, token_cap=excluded.token_cap')
        ->execute([$orgId, $s['provider'], $s['model'], $s['monthly_cap'], $s['auto_approve'] ? 1 : 0, $s['key_hint'], $s['key_set_at'],
            $s['key_check'], $s['key_check_at'], gmdate('c'), $s['token_cap']]);
}
/** המפתח האפקטיבי (א11): של הארגון אם הגדיר, אחרת ברירת המחדל של הבעלים (org 0). */
function aiKey(int $orgId): ?string {
    $k = secretGet('ai_key', $orgId);
    if ($k !== null && $k !== '') return $k;
    $k = $orgId === 0 ? null : secretGet('ai_key', 0);
    return $k === null || $k === '' ? null : $k;
}
/** מאיפה המפתח: org (של הארגון) / owner (ברירת המחדל של הבעלים) / null. */
function aiKeySource(int $orgId): ?string {
    $k = secretGet('ai_key', $orgId);
    if ($k !== null && $k !== '') return 'org';
    return $orgId !== 0 && aiKey($orgId) !== null ? 'owner' : null;
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
        'token_cap' => $s['token_cap'], 'tokens' => aiTokensUsed($pdo, $orgId),
        'has_key' => aiKey($orgId) !== null, 'key_source' => aiKeySource($orgId), 'key_hint' => $s['key_hint'], 'key_set_at' => $s['key_set_at'],
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

function aiTokensUsed(PDO $pdo, int $orgId): int {
    $st = $pdo->prepare('SELECT tokens FROM ai_usage WHERE org_id = ? AND month = ?');
    $st->execute([$orgId, gmdate('Y-m')]);
    return (int)$st->fetchColumn();
}
/** צבירת הטוקנים של תשובה (usage) לחודש ולארגון. */
function aiAddTokens(PDO $pdo, int $orgId, ?object $usage): void {
    if ($usage === null) return;
    $n = (int)($usage->input_tokens ?? 0) + (int)($usage->output_tokens ?? 0)
        + (int)($usage->cache_creation_input_tokens ?? 0) + (int)($usage->cache_read_input_tokens ?? 0);
    $month = gmdate('Y-m');
    $pdo->prepare('INSERT OR IGNORE INTO ai_usage(org_id, month, chats) VALUES (?,?,0)')->execute([$orgId, $month]);
    $pdo->prepare('UPDATE ai_usage SET tokens = tokens + ? WHERE org_id = ? AND month = ?')->execute([$n, $orgId, $month]);
}
function aiTokenCapMessage(int $cap): string {
    return 'הארגון הגיע לתקרת השימוש החודשית (' . number_format($cap) . ' טוקנים). מנהל הארגון יכול להגדיל אותה ב-⚙️ הגדרות ← בינה מלאכותית.';
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
function aiHttp(string $provider, #[\SensitiveParameter] string $key, array $payload, int $timeout = 120): array {
    $headers = ['content-type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . $key];
    $betas = [];
    if (isset($payload['fallbacks'])) $betas[] = 'server-side-fallback-2026-07-01';
    if (isset($payload['thinking']['block_binding'])) $betas[] = 'thinking-binding-controls-2026-08-01';
    if ($betas) $headers[] = 'anthropic-beta: ' . implode(',', $betas);
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
function aiProviderError(int $status, ?object $j, #[\SensitiveParameter] string $key): string {
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
function aiKeyTest(string $provider, string $model, #[\SensitiveParameter] string $key): array {
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
function aiToolsReady(): bool { return function_exists('extActions') && function_exists('extDo'); }

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
    if (aiFilesReady()) $tools[] = ['name' => 'file_view',
        'description' => 'הקובץ עצמו (תמונה, או PDF — גם סרוק) כדי לקרוא אותו. מזהה הקובץ — מ-files או case. עד 5MB, ועד '
            . AI_FILES_PER_THREAD . ' קבצים בשיחה / The file itself (image, or PDF — also scanned) to read it. File id from files or case.',
        'input_schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'integer', 'description' => 'מזהה הקובץ / File id']], 'required' => ['file']]];
    return $tools;
}

/**
 * מריץ פעולה של ext.php (extDo של 05) בהקשר של המשתמש המחובר, via: ai (05 כותב "<שם> (AI)" ביומן). מחזיר [status, data].
 * שגיאת פעולה — ExtError; פונקציות הליבה (normalize, existing, need) עונות ב-reply() — כאן במצב לכידה (FdReply), כדי שהשגיאה
 * תחזור לספק כ-tool_result ולא תסיים את בקשת הצ'אט. טרנזקציה שנשארה פתוחה (שגיאה באמצע) — מתבטלת.
 */
function aiExt(PDO $pdo, string $action, array $input, bool $dry, ?callable $guard = null): array {
    $GLOBALS['fd_reply_capture'] = true;
    try {
        return [200, extDo($pdo, $action, $input, 'ai', $dry, $guard)];
    } catch (ExtError $e) {
        return [$e->getCode() ?: 400, ['error' => $e->getMessage()]];
    } catch (FdReply $r) {
        return [$r->status, $r->data];
    } catch (Throwable $e) {
        error_log('funerals-dashboard ai tool ' . $action . ': ' . $e->getMessage());
        return [500, ['error' => 'הכלי נכשל']];
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        unset($GLOBALS['fd_reply_capture'], $GLOBALS['fd_err']);
    }
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
    if ($name === 'file_view' && aiFilesReady()) return aiFileTool($pdo, $thread, $input);
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
    $card = aiCard($pdo, $name, $input, $data, $orgId) + ['from_file' => !empty($settings['from_file'])];
    // הכרעת הבקרה (ביקורת 06 ⚪7): בסבב שבו נקרא קובץ — כתיבה תמיד ככרטיס, גם באישור אוטומטי (קובץ יכול להכיל הוראות)
    if ($settings['auto_approve'] && empty($settings['from_file'])) {
        [$st2, $data2] = aiExt($pdo, $name, $clean, false);
        $pid = aiProposalAdd($pdo, $thread, $name, $clean, $card, $st2 < 400 ? 'applied' : 'failed', $data2);
        return [aiToolJson(['status' => $st2 < 400 ? 'saved' : 'failed', 'auto_approved' => true] + $data2), $st2 >= 400,
            aiProposalGet($pdo, $pid, $orgId)];
    }
    $pid = aiProposalAdd($pdo, $thread, $name, $clean, $card, 'pending', null, aiCaseBase($pdo, $name, $clean, $orgId));
    return [aiToolJson(['status' => 'proposed', 'proposal_id' => $pid, 'saved' => false,
        'message' => 'ההצעה הוצגה למשתמש עם ✓ / ✗. שום דבר לא נשמר עד שיאשר. לא לומר שנשמר.',
        'would_save' => $data['saved'] ?? null, 'converted' => $data['converted'] ?? []]), false, aiProposalGet($pdo, $pid, $orgId)];
}
function aiToolJson(array $data): string {
    return mb_strcut(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 100000);
}

// ───────────────────────── קבצים (03) ─────────────────────────

/** הקבצים מגיעים מ-03 (dropbox/actions.php). אין את הפונקציה — אין כלי קובץ ואין הצעת סוג. */
function aiFilesReady(): bool { return function_exists('dropboxFileContent') && function_exists('dropboxCaseFiles'); }

/** הודעת שגיאה של 03 (DbxError->tag) בעברית — בלי פרטים פנימיים. */
function aiDbxMessage(Throwable $e): string {
    $tag = $e instanceof DbxError ? $e->tag : '';
    return match ($tag) {
        'too_large' => 'הקובץ גדול מ-5MB — אי אפשר לשלוח אותו לקריאה.',
        'not_connected', 'no_root' => 'Dropbox לא מחובר לארגון.',
        'reauth' => 'צריך לחבר מחדש את Dropbox (⚙️ הגדרות ← Dropbox).',
        default => 'לא הצלחתי לקרוא את הקובץ מ-Dropbox.',
    };
}

/** תוכן קובץ של הארגון, כבלוק לספק (image / document). [block|null, שם, שגיאה|null]. */
function aiFileBlock(PDO $pdo, int $orgId, int $fileId): array {
    try {
        $f = dropboxFileContent($pdo, $orgId, $fileId, AI_FILE_MAX);
    } catch (DbxError $e) {
        return [null, '', aiDbxMessage($e)];
    } catch (FdReply $r) {
        return [null, '', (string)($r->data['error'] ?? 'לא נמצא')];
    }
    if ($f === null) return [null, '', 'הקובץ לא נמצא'];
    $mime = (string)$f['mime'];
    $data = base64_encode((string)$f['bytes']);
    if (in_array($mime, AI_IMAGE_MIMES, true)) return [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]], (string)$f['name'], null];
    if ($mime === 'application/pdf') return [['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data],
        'title' => mb_substr((string)$f['name'], 0, 200)], (string)$f['name'], null];
    return [null, (string)$f['name'], 'סוג הקובץ לא נתמך לקריאה (' . $mime . ') — רק תמונה או PDF.'];
}

/** כמה קבצים (ובתים) כבר נשלחו לספק בשיחה הזו — לפי ההיסטוריה עצמה. */
function aiFilesUsed(array $thread): array {
    $n = 0; $bytes = 0;
    foreach (json_decode(json_encode($thread['messages'])) ?: [] as $m) {   // אחידות: אובייקטים, גם מה שנוסף בבקשה הזו
        if ($m->role !== 'user' || !is_array($m->content)) continue;
        foreach ($m->content as $b) {
            if (($b->type ?? '') !== 'tool_result' || !is_array($b->content ?? null)) continue;
            foreach ($b->content as $c) {
                if (in_array($c->type ?? '', ['image', 'document'], true)) { $n++; $bytes += (int)(strlen((string)($c->source->data ?? '')) * 3 / 4); }
                elseif (($c->type ?? '') === 'text' && ($c->text ?? '') === AI_FILE_MARK) $n++;   // נשלח בבקשה קודמת (התוכן לא נשמר)
            }
        }
    }
    return [$n, $bytes];
}

/** הכלי file_view: הקובץ עצמו לספק, בהרשאה של המשתמש (case.view, קובץ של הארגון הפעיל). */
function aiFileTool(PDO $pdo, array $thread, array $input): array {
    static $round = [0, 0];   // קבצים שנשלחו בבקשה הזו ועוד לא נכנסו להיסטוריה
    $err = fn(string $m) => [json_encode(['error' => $m], JSON_UNESCAPED_UNICODE), true, null];
    if (!can('case.view')) return $err('אין לך הרשאה לקבצים');
    $id = (int)($input['file'] ?? 0);
    if ($id <= 0) return $err('נדרש מזהה קובץ (מספר, מ-files)');
    [$n, $bytes] = aiFilesUsed($thread);
    if ($n + $round[0] >= AI_FILES_PER_THREAD) return $err('נשלחו כבר ' . AI_FILES_PER_THREAD . ' קבצים בשיחה הזו. לפתוח שיחה חדשה.');
    [$block, $name, $e] = aiFileBlock($pdo, (int)$thread['org_id'], $id);
    if ($e !== null) return $err($e);
    $size = (int)(strlen($block['source']['data']) * 3 / 4);
    if ($bytes + $round[1] + $size > AI_FILES_BYTES_PER_THREAD) return $err('הקבצים בשיחה הזו כבר גדולים מדי. לפתוח שיחה חדשה.');
    $round[0]++; $round[1] += $size;
    return [[['type' => 'text', 'text' => 'הקובץ "' . $name . '" (מזהה ' . $id . ')'], $block], false, null];
}

/** סוג המסמך → שם (השם של הבדיקה ב-05). */
function aiKindLabel(string $kind): string {
    return $kind === 'other' ? 'אחר' : (defined('EXT_CHECKS') ? (EXT_CHECKS[$kind] ?? $kind) : $kind);
}

/**
 * הצעת סוג לקובץ (06 §2.3): הקובץ לספק, ותשובה במבנה קבוע (structured output). תופס יחידה מהתקרה.
 * מחזיר את ההצעה, או null כשאין מפתח / התקרה מוצתה / הקובץ לא נקרא — בלי שגיאה למשתמש: זו הצעה, לא פעולה שביקש.
 */
function aiSuggestKind(PDO $pdo, int $orgId, array $file): ?array {
    $key = aiKey($orgId);
    if ($key === null) return null;
    $s = aiSettings($pdo, $orgId);
    if (aiTokensUsed($pdo, $orgId) >= $s['token_cap']) return null;
    // תופסים את הקובץ לפני הקריאה לספק — שתי פתיחות מקבילות לא שולחות אותו פעמיים (ביקורת 06 ⚪12)
    $claim = $pdo->prepare("INSERT OR IGNORE INTO ai_file_kinds(org_id, file_id, kind, reason, status, created_at) VALUES (?,?,'','','running',?)");
    $claim->execute([$orgId, (int)$file['id'], gmdate('c')]);
    if ($claim->rowCount() !== 1) return null;
    $fail = function () use ($pdo, $orgId, $file): null {
        $pdo->prepare("UPDATE ai_file_kinds SET status = 'failed' WHERE org_id = ? AND file_id = ? AND status = 'running'")->execute([$orgId, (int)$file['id']]);
        return null;
    };
    [$block, , $e] = aiFileBlock($pdo, $orgId, (int)$file['id']);
    if ($block === null || $e !== null) return $fail();
    if (!aiTakeQuota($pdo, $orgId, $s['monthly_cap'])) {
        $pdo->prepare("DELETE FROM ai_file_kinds WHERE org_id = ? AND file_id = ? AND status = 'running'")->execute([$orgId, (int)$file['id']]);   // יוצע בחודש הבא
        return null;
    }
    $kinds = array_merge(AI_DOC_CHECKS, ['other']);
    $list = implode("\n", array_map(fn($k) => "- $k: " . aiKindLabel($k), $kinds));
    $payload = ['model' => $s['model'], 'max_tokens' => 2000, 'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['kind', 'reason'],
            'properties' => ['kind' => ['type' => 'string', 'enum' => $kinds], 'reason' => ['type' => 'string']]]]],
        'messages' => [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' =>
            "זה קובץ מתיק לוויה (\"{$file['name']}\"). איזה מסמך זה? הסוגים:\n$list\n"
            . "אם לא ברור — other. reason: משפט קצר בעברית, מה בקובץ מראה את זה."]]]]];
    if (in_array($s['model'], AI_FALLBACK_MODELS, true)) $payload['fallbacks'] = 'default';
    try {
        [$status, $j] = aiHttp($s['provider'], $key, $payload, 60);
    } catch (AiError $e) {
        return $fail();
    }
    if ($j !== null) aiAddTokens($pdo, $orgId, $j->usage ?? null);
    if ($status !== 200 || $j === null || ($j->stop_reason ?? '') === 'refusal') return $fail();
    $text = '';
    foreach (($j->content ?? []) as $b) if (($b->type ?? '') === 'text') $text .= $b->text;
    $out = json_decode($text, true);
    if (!is_array($out) || !in_array($out['kind'] ?? '', $kinds, true)) return $fail();
    $row = ['kind' => $out['kind'], 'reason' => mb_substr(cleanText($out['reason'] ?? '', 300), 0, 300)];
    $pdo->prepare("UPDATE ai_file_kinds SET kind = ?, reason = ?, status = 'pending' WHERE org_id = ? AND file_id = ? AND status = 'running'")
        ->execute([$row['kind'], $row['reason'], $orgId, (int)$file['id']]);
    return $row;
}

/**
 * ההצעות לקבצי התיק: מה שכבר הוצע, ולקבצים חדשים בלי סוג — הצעה חדשה (עד AI_KIND_PER_OPEN בפתיחה).
 * לא רץ בלי מפתח (aiSuggestKind בודק) ולא מעבר לתקרה. מחזיר {file_id: {kind, label, check, reason, status}}.
 */
function aiCaseKinds(PDO $pdo, array $case): array {
    $orgId = (int)$case['org_id'];
    try { $files = dropboxCaseFiles($pdo, $case); } catch (Throwable $e) { return []; }
    $ids = array_map(fn($f) => (int)$f['id'], $files);
    $have = [];
    if ($ids) {
        $st = $pdo->prepare('SELECT * FROM ai_file_kinds WHERE org_id = ? AND file_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute(array_merge([$orgId], $ids));
        foreach ($st->fetchAll() as $r) $have[(int)$r['file_id']] = $r;
    }
    $left = AI_KIND_PER_OPEN;
    $since = gmdate('c', time() - AI_KIND_NEW_DAYS * 86400);
    $st = $pdo->prepare('SELECT id, name, kind, created_at FROM files WHERE id = ? AND org_id = ?');
    $out = [];
    foreach ($files as $f) {
        $id = (int)$f['id'];
        if (!isset($have[$id]) && (string)$f['kind'] === '' && $left > 0 && in_array($f['preview'] ?? '', ['image', 'pdf'], true)) {
            $st->execute([$id, $orgId]);
            $row = $st->fetch();
            $st->closeCursor();
            if ($row && (string)$row['created_at'] >= $since) {
                $left--;
                $sug = aiSuggestKind($pdo, $orgId, $row);
                if ($sug !== null) $have[$id] = $sug + ['status' => 'pending'];
            }
        }
        if (isset($have[$id]) && $have[$id]['status'] === 'pending') {
            $k = (string)$have[$id]['kind'];
            $out[$id] = ['kind' => $k, 'label' => aiKindLabel($k), 'check' => in_array($k, AI_DOC_CHECKS, true) ? $k : null,
                'reason' => (string)$have[$id]['reason'], 'status' => (string)$have[$id]['status']];
        }
    }
    return $out;
}

/**
 * ✓ / ✗ על הצעת סוג. ✓: files.kind = שם הסוג (רק אם עדיין ריק), והבדיקה המתאימה → "לאימות" דרך extDo (via: ai) —
 * רק אם היא עוד "טרם נבדק" או "חסר". ההרשאה: case.track (כמו סימון בדיקה).
 */
function aiDecideKind(PDO $pdo, int $fileId, bool $approve): array {
    $c = ctx();
    $orgId = (int)$c['org']['id'];
    need('case.track');
    $st = $pdo->prepare("SELECT k.*, f.name, f.case_id, f.kind AS file_kind FROM ai_file_kinds k JOIN files f ON f.id = k.file_id AND f.org_id = k.org_id
        WHERE k.org_id = ? AND k.file_id = ? AND f.deleted = 0");
    $st->execute([$orgId, $fileId]);
    $k = $st->fetch();
    $st->closeCursor();
    if (!$k) throw new AiError('ההצעה לא נמצאה', 404);
    if ($k['status'] !== 'pending') throw new AiError('ההצעה כבר טופלה', 409);
    $upd = $pdo->prepare("UPDATE ai_file_kinds SET status = ?, decided_at = ?, decided_by = ? WHERE org_id = ? AND file_id = ? AND status = 'pending'");
    $upd->execute([$approve ? 'applied' : 'rejected', gmdate('c'), $c['user']['id'], $orgId, $fileId]);
    if ($upd->rowCount() !== 1) throw new AiError('ההצעה כבר טופלה', 409);
    if (!$approve) return ['status' => 'rejected'];
    $label = aiKindLabel((string)$k['kind']);
    $pdo->prepare("UPDATE files SET kind = ?, updated_at = ? WHERE id = ? AND org_id = ? AND kind = ''")->execute([$label, gmdate('c'), $fileId, $orgId]);
    recordEvent($orgId, $k['case_id'], $c['actor'], 'ui', 'file_kind', ['file.' . $fileId . '.kind' => [(string)$k['file_kind'], $label]]);
    $check = null;
    if (in_array($k['kind'], AI_DOC_CHECKS, true) && $k['case_id'] !== null && aiToolsReady()) {
        $case = existing($pdo, (string)$k['case_id'], $orgId);
        $cur = (string)($case['checks'][$k['kind']]['status'] ?? 'unknown');
        if (in_array($cur, ['unknown', 'missing'], true)) {
            [$status, $data] = aiExt($pdo, 'case_update', ['id' => $k['case_id'],
                'checks' => [$k['kind'] => ['status' => 'document_present', 'note' => 'קובץ: ' . $k['name']]]], false);
            $check = ['key' => $k['kind'], 'label' => $label, 'ok' => $status < 400, 'error' => $status < 400 ? null : ($data['error'] ?? '')];
        }
    }
    return ['status' => 'applied', 'kind' => $label, 'check' => $check];
}

// ───────────────────────── הצעות ─────────────────────────

function aiProposalAdd(PDO $pdo, array $thread, string $tool, array $input, array $card, string $status, ?array $result, ?string $base = null): int {
    $pdo->prepare('INSERT INTO ai_proposals(thread_id, org_id, user_id, case_id, tool, input, card, status, result, created_at, decided_at, base)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$thread['id'], $thread['org_id'], $thread['user_id'], $card['case_id'], $tool,
        json_encode($input, JSON_UNESCAPED_UNICODE), json_encode($card, JSON_UNESCAPED_UNICODE), $status,
        $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE), gmdate('c'), $status === 'pending' ? null : gmdate('c'), $base]);
    return (int)$pdo->lastInsertId();
}
/**
 * "גרסת" התיק מול ההצעה (ביקורת 06 🔴1): hash של השדות והבדיקות שההצעה נוגעת בהם, כפי שהם בתיק עכשיו.
 * ✓ על הצעה שהשדות שלה השתנו מאז — נדחה (stale), כדי לא לדרוס עריכה של אדם. לתיק חדש (case_create) — אין.
 */
function aiCaseBase(PDO $pdo, string $tool, array $input, int $orgId): ?string {
    if ($tool !== 'case_update') return null;
    $id = (string)($input['id'] ?? '');
    $case = preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $id) ? existing($pdo, $id, $orgId) : null;
    if ($case === null) return hash('sha256', 'missing');
    $parts = [];
    foreach (array_keys(is_array($input['fields'] ?? null) ? $input['fields'] : []) as $f) $parts['f.' . $f] = $case[$f] ?? null;
    foreach (array_keys(is_array($input['checks'] ?? null) ? $input['checks'] : []) as $k) {
        $c = $case['checks'][$k] ?? [];
        $parts['c.' . $k] = [(string)($c['status'] ?? 'unknown'), (string)($c['value'] ?? ''), (string)($c['note'] ?? '')];
    }
    ksort($parts);
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
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
    // תופסים את ההצעה לפני הכתיבה — שתי לחיצות לא כותבות פעמיים. "נכשלה" — אפשר לנסות שוב; "בטיפול" תקוע (נפילה באמצע) — חוזר.
    $st = $pdo->prepare("UPDATE ai_proposals SET status = 'deciding', decided_at = ? WHERE id = ?
        AND (status IN ('pending', 'failed') OR (status = 'deciding' AND decided_at < ?))");
    $st->execute([gmdate('c'), $id, gmdate('c', time() - AI_DECIDING_STALE_SEC)]);
    if ($st->rowCount() !== 1) throw new AiError('ההצעה כבר טופלה', 409);
    if (!$approve) {
        $pdo->prepare("UPDATE ai_proposals SET status = 'rejected', decided_at = ? WHERE id = ?")->execute([gmdate('c'), $id]);
        return ['proposal' => aiProposalGet($pdo, $id, $orgId)];
    }
    if (!aiToolsReady()) {
        $pdo->prepare("UPDATE ai_proposals SET status = 'pending' WHERE id = ?")->execute([$id]);
        throw new AiError('ה-API החיצוני (פרוסה 05) עוד לא מותקן', 501);
    }
    // התיק השתנה מאז ההצעה (בשדות שהיא נוגעת בהם) — לא דורסים: ההצעה ישנה, ומבקשים חדשה בצ'אט
    $st = $pdo->prepare('SELECT base FROM ai_proposals WHERE id = ?');
    $st->execute([$id]);
    $base = $st->fetchColumn();
    $st->closeCursor();   // סמן פתוח מחזיק נעילת קריאה ומפיל כתיבה מקבילה ("database is locked", #1855)
    $isStale = fn() => is_string($base) && $base !== '' && $base !== aiCaseBase($pdo, $p['tool'], $p['input'], $orgId);
    $markStale = function () use ($pdo, $id, $orgId): array {
        $pdo->prepare("UPDATE ai_proposals SET status = 'stale', result = ?, decided_at = ? WHERE id = ?")
            ->execute([json_encode(['message' => 'התיק השתנה מאז ההצעה'], JSON_UNESCAPED_UNICODE), gmdate('c'), $id]);
        return ['stale' => true, 'proposal' => aiProposalGet($pdo, $id, $orgId)];
    };
    if ($isStale()) return $markStale();
    // ושוב בתוך הטרנזקציה של הכתיבה (extDo), צמוד לשמירה — בלי חלון בין הבדיקה לכתיבה
    $hit = false;
    $guard = function () use ($isStale, &$hit): void {
        if ($isStale()) { $hit = true; throw new ExtError('התיק השתנה מאז ההצעה', 409); }
    };
    [$status, $data] = aiExt($pdo, $p['tool'], $p['input'], false, $guard);
    if ($hit) return $markStale();
    $pdo->prepare('UPDATE ai_proposals SET status = ?, result = ?, decided_at = ? WHERE id = ?')
        ->execute([$status < 400 ? 'applied' : 'failed', json_encode($data, JSON_UNESCAPED_UNICODE), gmdate('c'), $id]);
    return ['proposal' => aiProposalGet($pdo, $id, $orgId), 'status' => $status, 'result' => $data];
}

// ───────────────────────── שיחות ─────────────────────────

function aiThreadNew(PDO $pdo, int $orgId, int $userId, ?string $caseId): array {
    $t = ['id' => 'ait_' . bin2hex(random_bytes(12)), 'org_id' => $orgId, 'user_id' => $userId, 'case_id' => $caseId, 'title' => '',
        'messages' => [], 'system' => '', 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')];
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
    $r['system'] = (string)($r['system'] ?? '');
    $r['org_id'] = (int)$r['org_id'];
    $r['user_id'] = (int)$r['user_id'];
    return $r;
}
function aiThreadSave(PDO $pdo, array $t): void {
    $pdo->prepare('UPDATE ai_threads SET title = ?, messages = ?, system = ?, updated_at = ? WHERE id = ?')
        ->execute([$t['title'], json_encode(aiStripFiles($t['messages']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $t['system'] ?? '', gmdate('c'), $t['id']]);
}
/**
 * הקבצים שנשלחו לספק (image / document בתוך tool_result) לא נשמרים בשיחה — במקומם AI_FILE_MARK (ביקורת 06 🟡3).
 * זו עריכה של היסטוריה, ולכן כל בקשה לספק שולחת prefix_mismatch_behavior: drop_block — בלוקי thinking שאחריה נזרקים
 * (בלי חיוב) במקום שגיאה 400 בחשבונות שנאכפים.
 */
function aiStripFiles(array $messages): array {
    $out = json_decode(json_encode($messages)) ?: [];
    foreach ($out as $m) {
        if (($m->role ?? '') !== 'user' || !is_array($m->content ?? null)) continue;
        foreach ($m->content as $b) {
            if (($b->type ?? '') !== 'tool_result' || !is_array($b->content ?? null)) continue;
            foreach ($b->content as $i => $c) {
                if (in_array($c->type ?? '', ['image', 'document'], true)) $b->content[$i] = (object)['type' => 'text', 'text' => AI_FILE_MARK];
            }
        }
    }
    return $out;
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
        . "8. אין מחיקה. אין גישה לשום דבר שאין למשתמש.\n"
        . "9. לקרוא מסמך (תמונה, PDF סרוק): files לרשימה, ואז file_view עם מזהה הקובץ. לא לקרוא קבצים שלא צריך.\n";
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
    if (aiTokensUsed($pdo, $orgId) >= $s['token_cap']) return ['ok' => false, 'code' => 'cap', 'message' => aiTokenCapMessage($s['token_cap'])];
    if (!aiTakeQuota($pdo, $orgId, $s['monthly_cap'])) {
        return ['ok' => false, 'code' => 'cap', 'message' => 'הגעתם לתקרה החודשית של הארגון (' . $s['monthly_cap']
            . ' שיחות). מנהל הארגון יכול להגדיל אותה ב-⚙️ הגדרות ← בינה מלאכותית.'];
    }
    $case = null;
    if ($thread['case_id'] !== null) {
        $case = existing($pdo, (string)$thread['case_id'], $orgId);
        if ($case === null) throw new AiError('התיק לא נמצא', 404);
    }
    // הודעת המערכת נקבעת פעם אחת לשיחה ונשמרת: שינוי שלה באמצע שובר את המטמון ואת בלוקי ה-thinking של ההמשך
    if (($thread['system'] ?? '') === '') $thread['system'] = aiSystemPrompt($thread, $case);
    // הכרעות על הצעות מאז ההודעה הקודמת — כדי שהעוזר ידע מה נשמר ומה נדחה
    $st = $pdo->prepare("SELECT id, status FROM ai_proposals WHERE thread_id = ? AND status IN ('applied','rejected','failed','stale') AND decided_at >= ?");
    $st->execute([$thread['id'], $thread['updated_at']]);
    $names = ['applied' => 'אושרה ונשמרה', 'rejected' => 'נדחתה', 'failed' => 'נכשלה בשמירה', 'stale' => 'לא נשמרה — התיק השתנה מאז'];
    $notes = array_map(fn($r) => '#' . $r['id'] . ' ' . $names[$r['status']], $st->fetchAll());
    $content = ($notes ? '[מערכת: הכרעות על הצעות — ' . implode(', ', $notes) . "]\n" : '') . $text;
    $thread['messages'][] = (object)['role' => 'user', 'content' => $content];
    if ($thread['title'] === '') $thread['title'] = mb_substr($text, 0, 80);

    $payload = ['model' => $s['model'], 'max_tokens' => 16000, 'system' => $thread['system'],
        // drop_block: אחרי שקובץ הוחלף בטקסט בהיסטוריה (aiStripFiles), בלוקי thinking שאחריו נזרקים במקום 400
        'thinking' => ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']],
        'output_config' => ['effort' => 'medium'], 'cache_control' => ['type' => 'ephemeral']];
    $tools = aiToolDefs();
    if ($tools) $payload['tools'] = $tools;
    if (in_array($s['model'], AI_FALLBACK_MODELS, true)) $payload['fallbacks'] = 'default';

    $proposals = [];
    $reply = '';
    $fileRead = false;
    for ($round = 0; ; $round++) {
        if ($round > 0 && aiTokensUsed($pdo, $orgId) >= $s['token_cap']) { $reply = aiTokenCapMessage($s['token_cap']); break; }
        $payload['messages'] = $thread['messages'];
        [$status, $j] = aiHttp($s['provider'], $key, $payload);
        if ($status !== 200 || $j === null) {
            aiThreadSave($pdo, $thread);
            throw new AiError(aiProviderError($status, $j, $key), 502);
        }
        aiAddTokens($pdo, $orgId, $j->usage ?? null);
        $thread['messages'][] = (object)['role' => 'assistant', 'content' => $j->content ?? []];
        $stop = (string)($j->stop_reason ?? '');
        $reply = '';   // התשובה — הטקסט של הסבב האחרון בלבד
        foreach (($j->content ?? []) as $b) if (($b->type ?? '') === 'text') $reply .= ($reply === '' ? '' : "\n") . $b->text;
        if ($stop === 'refusal') { $reply = $reply !== '' ? $reply : 'הספק סירב לענות על הבקשה הזו. לנסח אחרת.'; break; }
        if ($stop !== 'tool_use') break;
        if ($round >= AI_MAX_TOOL_ROUNDS) { $reply .= ($reply === '' ? '' : "\n") . '(עצרתי — יותר מדי צעדים בבקשה אחת. לנסות בקשה מצומצמת יותר.)'; break; }
        $results = [];
        foreach ($j->content as $b) {
            if (($b->type ?? '') !== 'tool_use') continue;
            $input = json_decode(json_encode($b->input ?? new stdClass()), true) ?: [];
            $name = (string)$b->name;
            [$res, $isErr, $prop] = aiRunTool($pdo, $thread, $s + ['from_file' => $fileRead], $name, $input);
            if (in_array($name, ['file_view', 'file_text'], true) && !$isErr) $fileRead = true;
            if ($prop !== null) $proposals[] = $prop;
            $r = ['type' => 'tool_result', 'tool_use_id' => $b->id, 'content' => $res];
            if ($isErr) $r['is_error'] = true;
            $results[] = (object)$r;
        }
        $thread['messages'][] = (object)['role' => 'user', 'content' => $results];
    }
    // tool_use שנשאר בלי tool_result (עצירה אחרי AI_MAX_TOOL_ROUNDS, max_tokens, תקרה) — סוגרים בשגיאה, אחרת השיחה שבורה
    $last = end($thread['messages']);
    if ($last && $last->role === 'assistant' && is_array($last->content)) {
        $open = [];
        foreach ($last->content as $b) if (($b->type ?? '') === 'tool_use') {
            $open[] = (object)['type' => 'tool_result', 'tool_use_id' => $b->id, 'is_error' => true, 'content' => 'לא הורץ — העוזר עצר לפני הכלי.'];
        }
        if ($open) $thread['messages'][] = (object)['role' => 'user', 'content' => $open];
    }
    aiThreadSave($pdo, $thread);
    return ['ok' => true, 'thread' => $thread['id'], 'reply' => $reply, 'proposals' => $proposals, 'used' => aiUsed($pdo, $orgId),
        'tools_ready' => aiToolsReady()];
}
